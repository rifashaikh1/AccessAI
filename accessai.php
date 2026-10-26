<?php
/**
 * Plugin Name: AccessAI
 * Description: AI-assisted accessibility auditing for WordPress websites.
 * Version: 1.1.0
 * Author: AccessAI Team
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'scanner/alt-text.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/heading-structure.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/form-labels.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/ai-assistant.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/contrast-scan.php';

// Register admin menu.
add_action( 'admin_menu', 'accessai_register_menu' );

/**
 * Load contrast-scanner.js on public frontend pages for logged-in admins.
 * This is required for the scan iframe to work — the script must be present
 * on the target page so it can read computed styles and send results back.
 */
add_action( 'wp_enqueue_scripts', 'accessai_load_contrast_scanner_frontend' );

function accessai_load_contrast_scanner_frontend() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_enqueue_script(
		'accessai-contrast-scanner',
		plugin_dir_url( __FILE__ ) . 'assets/js/contrast-scanner.js',
		array(),
		'1.1.0',
		true
	);
}

function accessai_register_menu() {
	add_menu_page(
		'AccessAI Dashboard',
		'AccessAI',
		'manage_options',
		'accessai',
		'accessai_render_dashboard',
		'dashicons-universal-access-alt',
		30
	);
}

/**
 * Render the full AccessAI admin dashboard.
 */
function accessai_render_dashboard() {
	$user_id = get_current_user_id();

	// Published pages/posts available for auditing.
	$published_pages = get_posts(
		array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	// Determine active audited page ID.
	$active_page_id = 0;
	if ( isset( $_GET['scanned_page'] ) && absint( $_GET['scanned_page'] ) ) {
		$active_page_id = absint( $_GET['scanned_page'] );
	} else {
		$stored_page_id = absint( get_user_meta( $user_id, '_accessai_scanned_page_id', true ) );
		if ( $stored_page_id ) {
			$active_page_id = $stored_page_id;
		}
	}

	$scanned_post = $active_page_id ? get_post( $active_page_id ) : null;
	if ( ! $scanned_post || $scanned_post->post_status !== 'publish' ) {
		$scanned_post   = null;
		$active_page_id = 0;
	}

	// Run content scanners strictly for this specific page.
	$issues                  = array();
	$heading_issues          = array();
	$form_issues             = array();
	$contrast_results        = array();
	$contrast_scan_completed = false;

	if ( $active_page_id ) {
		$issues         = accessai_scan_missing_alt_text( $active_page_id );
		$heading_issues = accessai_scan_heading_structure( $active_page_id );
		$form_issues    = accessai_scan_form_labels( $active_page_id );

		$contrast_results        = get_transient( 'accessai_contrast_results_' . $user_id );
		$contrast_scan_completed = get_transient( 'accessai_contrast_scan_completed_' . $user_id );

		if ( ! is_array( $contrast_results ) ) {
			$contrast_results = array();
		}
	}

	$total_issues = count( $issues ) + count( $heading_issues ) + count( $form_issues ) + count( $contrast_results );

	// Calculate accessibility score for this specific page.
	if ( $scanned_post ) {
		$html = $scanned_post->post_content;
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . ( $html ?: '<div></div>' ) );
		$img_count     = $dom->getElementsByTagName( 'img' )->length;
		$heading_count = 0;
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			if ( preg_match( '/^h[1-6]$/i', $node->tagName ) ) {
				$heading_count++;
			}
		}
		$xpath      = new DOMXPath( $dom );
		$form_count = $xpath->query( '//input[not(@type="hidden")] | //select | //textarea' )->length;
		libxml_clear_errors();

		$checks_estimated = max( 10, $img_count + max( 1, $heading_count ) + max( 1, $form_count ) + max( 5, count( $contrast_results ) ) );
		$passed_checks    = max( 0, $checks_estimated - $total_issues );
		$score            = min( 100, max( 0, round( ( $passed_checks / $checks_estimated ) * 100 ) ) );
	} else {
		$checks_estimated = 0;
		$passed_checks    = 0;
		$score            = null;
	}

	$has_api_key = defined( 'ACCESSAI_GEMINI_API_KEY' ) && ACCESSAI_GEMINI_API_KEY;

	// Build fingerprinted issue lists for auto-explain.
	$alt_issues_json      = accessai_build_issue_data( $issues, 'alt_text' );
	$heading_issues_json  = accessai_build_issue_data( $heading_issues, 'heading_structure' );
	$form_issues_json     = accessai_build_issue_data( $form_issues, 'form_label' );
	$contrast_issues_json = accessai_build_issue_data( $contrast_results, 'color_contrast' );

	?>
	<div class="wrap accessai-wrap">
		<div class="accessai-header">
			<h1><span class="dashicons dashicons-universal-access-alt"></span> AccessAI Dashboard</h1>
			<p class="accessai-subtitle">AI-assisted accessibility auditing for your WordPress site.</p>
		</div>

		<?php if ( ! $has_api_key ) : ?>
			<div class="notice notice-warning accessai-notice">
				<p>
					<strong>Gemini API key not configured.</strong>
					AI explanations will not be generated.
					Add <code>define( 'ACCESSAI_GEMINI_API_KEY', 'your-key' );</code> to <code>wp-config.php</code>.
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-info inline accessai-notice" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
				<p style="margin:0;">
					<strong>Gemini API key detected.</strong>
					Click <em>Test Connection</em> to verify it works.
				</p>
				<button id="accessai-test-btn" type="button" class="button button-secondary">
					Test Gemini Connection
				</button>
				<span id="accessai-test-result" style="font-size:0.88rem;"></span>
			</div>
		<?php endif; ?>

		<!-- ============================================================
		     PAGE AUDIT SELECTOR (TOP)
		     ============================================================ -->
		<div class="accessai-section accessai-audit-bar-section">
			<div class="accessai-section-header">
				<h2>Run Full Accessibility Audit</h2>
				<p>
					Select a published page or post to audit all accessibility checkpoints (Alt Text, Heading Structure, Form Labels, and Colour Contrast) and calculate its score.
				</p>
			</div>

			<?php if ( empty( $published_pages ) ) : ?>
				<div class="notice notice-info inline"><p>No published pages or posts found to scan.</p></div>
			<?php else : ?>
				<div class="accessai-scan-bar">
					<label for="accessai-page-select" class="screen-reader-text">Select page to audit</label>
					<select id="accessai-page-select" class="accessai-page-select">
						<?php foreach ( $published_pages as $page ) : ?>
							<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $active_page_id, $page->ID ); ?>>
								<?php echo esc_html( $page->post_title ); ?>
								(<?php echo esc_html( ucfirst( $page->post_type ) ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<button id="accessai-scan-btn" type="button" class="button button-primary">
						<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
						Scan Page
					</button>
					<?php if ( $active_page_id ) : ?>
						<button id="accessai-clear-contrast-btn" type="button" class="button button-secondary">
							Clear Results
						</button>
					<?php endif; ?>
				</div>

				<div id="accessai-scan-status" class="accessai-scan-status" role="status" aria-live="polite"></div>
				<div id="accessai-iframe-container"></div>
			<?php endif; ?>
		</div>

		<!-- Score overview -->
		<div class="accessai-overview">
			<div class="accessai-score-card accessai-score-main">
				<p class="accessai-card-label">Accessibility Score</p>
				<div class="accessai-score-value">
					<?php echo $score !== null ? esc_html( $score ) . '<span>/100</span>' : '—'; ?>
				</div>
				<p class="accessai-card-sub">Page-specific score</p>
			</div>
			<div class="accessai-score-card">
				<p class="accessai-card-label">Page Findings</p>
				<div class="accessai-score-value accessai-score-warn">
					<?php echo $score !== null ? esc_html( $total_issues ) : '—'; ?>
				</div>
				<p class="accessai-card-sub">Across all 4 scanners</p>
			</div>
			<div class="accessai-score-card">
				<p class="accessai-card-label">Audited Page</p>
				<div class="accessai-score-value" style="font-size:1.1rem;font-weight:600;line-height:1.3;padding-top:8px;">
					<?php echo $scanned_post ? esc_html( wp_trim_words( $scanned_post->post_title, 4 ) ) : 'None Selected'; ?>
				</div>
				<p class="accessai-card-sub"><?php echo $scanned_post ? esc_html( ucfirst( $scanned_post->post_type ) ) : 'Select above'; ?></p>
			</div>
			<div class="accessai-score-card">
				<p class="accessai-card-label">Checks Evaluated</p>
				<div class="accessai-score-value accessai-score-pass">
					<?php echo $score !== null ? esc_html( $passed_checks . '/' . $checks_estimated ) : '—'; ?>
				</div>
				<p class="accessai-card-sub">Estimated criteria passed</p>
			</div>
		</div>

		<?php if ( ! $active_page_id ) : ?>
			<div class="notice notice-info inline accessai-section" style="padding:24px;text-align:center;">
				<p style="font-size:1.05rem;margin:0 0 8px;font-weight:600;">No page audited yet</p>
				<p style="margin:0;color:#646970;">Select a published page or post from the dropdown above and click <strong>Scan Page</strong> to run a complete audit across Alt Text, Headings, Forms, and Contrast.</p>
			</div>
		<?php else : ?>

			<!-- ============================================================
			     ALT TEXT SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<h2>Alt Text Scan Results</h2>
				</div>

				<?php if ( empty( $issues ) ) : ?>
					<div class="notice notice-success inline">
						<p>No missing or empty alt text issues found on this page.</p>
					</div>
				<?php else : ?>
					<p>Found <strong><?php echo esc_html( count( $issues ) ); ?></strong> image(s) needing alt text review.</p>

					<table class="widefat striped accessai-table" id="accessai-alt-table"
						   data-issues="<?php echo esc_attr( wp_json_encode( $alt_issues_json ) ); ?>">
						<thead>
							<tr>
								<th>Issue</th>
								<th>Severity</th>
								<th>Page</th>
								<th>Image Source</th>
								<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $issues as $issue ) :
								$fp = md5( 'alt_text|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['image_src'] ?? '' ) );
							?>
								<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
									data-page-id="<?php echo esc_attr( $issue['page_id'] ?? '' ); ?>"
									data-image-src="<?php echo esc_attr( $issue['image_src'] ?? '' ); ?>">
									<td><?php echo esc_html( $issue['type'] ); ?></td>
									<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $issue['severity'] ); ?></span></td>
									<td><?php echo esc_html( $issue['page_title'] ); ?></td>
									<td class="accessai-truncate">
										<?php echo $issue['image_src'] ? esc_html( $issue['image_src'] ) : 'N/A'; ?>
									</td>
									<td class="accessai-ai-cell">
										<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
									</td>
									<td>
										<a class="button button-secondary"
										   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>">
											Edit Page
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     HEADING STRUCTURE SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<h2>Heading Structure Scan Results</h2>
				</div>

				<?php if ( empty( $heading_issues ) ) : ?>
					<div class="notice notice-success inline">
						<p>No skipped heading levels detected on this page.</p>
					</div>
				<?php else : ?>
					<p>Found <strong><?php echo esc_html( count( $heading_issues ) ); ?></strong> possible heading hierarchy issue(s).</p>

					<table class="widefat striped accessai-table" id="accessai-heading-table"
						   data-issues="<?php echo esc_attr( wp_json_encode( $heading_issues_json ) ); ?>">
						<thead>
							<tr>
								<th>Issue</th>
								<th>Page</th>
								<th>Heading Transition</th>
								<th>Heading Text</th>
								<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $heading_issues as $issue ) :
								$fp = md5( 'heading_structure|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['previous'] ?? '' ) . '|' . ( $issue['current'] ?? '' ) . '|' . ( $issue['heading_text'] ?? '' ) );
							?>
								<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>">
									<td><?php echo esc_html( $issue['type'] ); ?></td>
									<td><?php echo esc_html( $issue['page_title'] ); ?></td>
									<td><?php echo esc_html( $issue['previous'] . ' → ' . $issue['current'] ); ?></td>
									<td><?php echo esc_html( $issue['heading_text'] ?: '(Empty heading)' ); ?></td>
									<td class="accessai-ai-cell">
										<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
									</td>
									<td>
										<a class="button button-secondary"
										   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>">
											Edit Page
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     FORM LABEL SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<h2>Form Label Scan Results</h2>
				</div>

				<?php if ( empty( $form_issues ) ) : ?>
					<div class="notice notice-success inline">
						<p>No potentially unlabeled form controls found on this page.</p>
					</div>
				<?php else : ?>
					<p>Found <strong><?php echo esc_html( count( $form_issues ) ); ?></strong> form control(s) needing review.</p>

					<table class="widefat striped accessai-table" id="accessai-form-table"
						   data-issues="<?php echo esc_attr( wp_json_encode( $form_issues_json ) ); ?>">
						<thead>
							<tr>
								<th>Issue</th>
								<th>Page</th>
								<th>Control</th>
								<th>Field Name</th>
								<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $form_issues as $issue ) :
								$fp = md5( 'form_label|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['control'] ?? '' ) . '|' . ( $issue['field_name'] ?? '' ) );
							?>
								<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>">
									<td><?php echo esc_html( $issue['type'] ); ?></td>
									<td><?php echo esc_html( $issue['page_title'] ); ?></td>
									<td>
										<?php echo esc_html( $issue['control'] ); ?>
										<?php if ( $issue['field_type'] !== '' ) : ?>
											(<?php echo esc_html( $issue['field_type'] ); ?>)
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $issue['field_name'] ?: 'Not specified' ); ?></td>
									<td class="accessai-ai-cell">
										<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
									</td>
									<td>
										<a class="button button-secondary"
										   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>">
											Edit Page
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     COLOUR CONTRAST SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<h2>Colour Contrast Scan Results</h2>
				</div>

				<?php if ( ! $contrast_scan_completed ) : ?>
					<div class="notice notice-info inline" id="accessai-no-contrast-notice">
						<p>Contrast scan in progress or pending. Click <strong>Scan Page</strong> above to run contrast checks.</p>
					</div>
				<?php elseif ( empty( $contrast_results ) ) : ?>
					<div class="notice notice-success inline">
						<p>Scan complete — no contrast failures detected among the text elements checked on this page.</p>
					</div>
				<?php else : ?>
					<p>
						Found <strong><?php echo esc_html( count( $contrast_results ) ); ?></strong>
						potential contrast issue(s) on this page. AI explanations load automatically below.
					</p>

					<table class="widefat striped accessai-table" id="accessai-contrast-table"
						   data-issues="<?php echo esc_attr( wp_json_encode( $contrast_issues_json ) ); ?>">
						<thead>
							<tr>
								<th>Element</th>
								<th>Text Sample</th>
								<th>Contrast Ratio</th>
								<th>Required</th>
								<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $contrast_results as $idx => $result ) :
								$fp = md5( 'color_contrast|' . ( $result['element'] ?? '' ) . '|' . ( $result['text'] ?? '' ) . '|' . ( $result['ratio'] ?? '' ) );
							?>
								<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>">
									<td><?php echo esc_html( $result['element'] ?? '' ); ?></td>
									<td><?php echo esc_html( $result['text'] ?? '' ); ?></td>
									<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $result['ratio'] ?? '' ); ?></span></td>
									<td><?php echo esc_html( $result['required'] ?? '' ); ?></td>
									<td class="accessai-ai-cell">
										<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

		<?php endif; ?>

	</div><!-- .accessai-wrap -->
	<?php
}

/**
 * Build issue data arrays suitable for auto-explain JS consumption.
 * Each item gets a fingerprint, type, and salient details string.
 */
function accessai_build_issue_data( $issues, $type ) {
	$data = array();

	foreach ( $issues as $issue ) {
		switch ( $type ) {
			case 'alt_text':
				$details = 'Page: ' . ( $issue['page_title'] ?? '' ) .
					'. Image src: ' . ( $issue['image_src'] ?? 'unknown' ) .
					'. Issue: ' . ( $issue['message'] ?? 'Missing or empty alt text.' );
				$fp_key  = 'alt_text|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['image_src'] ?? '' );
				break;

			case 'heading_structure':
				$details = 'Page: ' . ( $issue['page_title'] ?? '' ) .
					'. Heading transition: ' . ( $issue['previous'] ?? '' ) . ' to ' . ( $issue['current'] ?? '' ) .
					'. Heading text: ' . ( $issue['heading_text'] ?? '(empty)' );
				$fp_key  = 'heading_structure|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['previous'] ?? '' ) . '|' . ( $issue['current'] ?? '' ) . '|' . ( $issue['heading_text'] ?? '' );
				break;

			case 'form_label':
				$details = 'Page: ' . ( $issue['page_title'] ?? '' ) .
					'. Control: ' . ( $issue['control'] ?? '' ) .
					' (type: ' . ( $issue['field_type'] ?? 'unspecified' ) . ')' .
					'. Field name: ' . ( $issue['field_name'] ?? 'not specified' );
				$fp_key  = 'form_label|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['control'] ?? '' ) . '|' . ( $issue['field_name'] ?? '' );
				break;

			case 'color_contrast':
				$details = 'Element: ' . ( $issue['element'] ?? '' ) .
					'. Text: ' . ( $issue['text'] ?? '' ) .
					'. Contrast ratio: ' . ( $issue['ratio'] ?? '' ) .
					'. Required ratio: ' . ( $issue['required'] ?? '' );
				$fp_key  = 'color_contrast|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['text'] ?? '' ) . '|' . ( $issue['ratio'] ?? '' );
				break;

			default:
				continue 2;
		}

		$fp     = md5( $fp_key );
		$data[] = array(
			'fingerprint' => $fp,
			'type'        => $type,
			'details'     => $details,
		);
	}

	return $data;
}

/**
 * AJAX: Save contrast scan results sent from the scan iframe.
 */
add_action( 'wp_ajax_accessai_save_contrast_results', 'accessai_save_contrast_results' );

function accessai_save_contrast_results() {
	check_ajax_referer( 'accessai_contrast_scan', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	$raw_results = isset( $_POST['results'] ) ? wp_unslash( $_POST['results'] ) : '';
	$results     = json_decode( $raw_results, true );

	if ( ! is_array( $results ) ) {
		wp_send_json_error( array( 'message' => 'Invalid scan results.' ), 400 );
	}

	$safe_results = array();

	foreach ( array_slice( $results, 0, 500 ) as $result ) {
		if ( ! is_array( $result ) ) {
			continue;
		}

		$safe_results[] = array(
			'element'  => sanitize_text_field( $result['element'] ?? '' ),
			'text'     => sanitize_text_field( $result['text'] ?? '' ),
			'ratio'    => sanitize_text_field( $result['ratio'] ?? '' ),
			'required' => sanitize_text_field( $result['required'] ?? '' ),
		);
	}

	$user_id = get_current_user_id();

	set_transient( 'accessai_contrast_results_' . $user_id, $safe_results, HOUR_IN_SECONDS );
	set_transient( 'accessai_contrast_scan_completed_' . $user_id, true, HOUR_IN_SECONDS );

	wp_send_json_success(
		array(
			'message' => 'Contrast results received.',
			'count'   => count( $safe_results ),
		)
	);
}

/**
 * AJAX: Clear stored contrast scan results for the current user.
 */
add_action( 'wp_ajax_accessai_clear_contrast_results', 'accessai_clear_contrast_results' );

function accessai_clear_contrast_results() {
	check_ajax_referer( 'accessai_contrast_trigger', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	$user_id = get_current_user_id();

	delete_transient( 'accessai_contrast_results_' . $user_id );
	delete_transient( 'accessai_contrast_scan_completed_' . $user_id );
	delete_user_meta( $user_id, '_accessai_scanned_page_id' );

	wp_send_json_success( array( 'message' => 'Audit results cleared.' ) );
}
