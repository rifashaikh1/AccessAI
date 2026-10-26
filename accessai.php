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

	$score_label = '';
	$score_class = 'accessai-score-pass';
	if ( $score !== null ) {
		if ( $score >= 90 ) {
			$score_label = 'Excellent';
			$score_class = 'accessai-score-pass';
		} elseif ( $score >= 70 ) {
			$score_label = 'Good';
			$score_class = 'accessai-score-good';
		} elseif ( $score >= 50 ) {
			$score_label = 'Needs Review';
			$score_class = 'accessai-score-warn';
		} else {
			$score_label = 'Action Required';
			$score_class = 'accessai-score-crit';
		}
	}

	$has_api_key = defined( 'ACCESSAI_GEMINI_API_KEY' ) && ACCESSAI_GEMINI_API_KEY;

	// Build fingerprinted issue lists for auto-explain.
	$alt_issues_json      = accessai_build_issue_data( $issues, 'alt_text' );
	$heading_issues_json  = accessai_build_issue_data( $heading_issues, 'heading_structure' );
	$form_issues_json     = accessai_build_issue_data( $form_issues, 'form_label' );
	$contrast_issues_json = accessai_build_issue_data( $contrast_results, 'color_contrast' );

	?>
	<div class="wrap accessai-wrap">

		<!-- ============================================================
		     HERO HEADER
		     ============================================================ -->
		<div class="accessai-hero">
			<div class="accessai-hero-brand">
				<div class="accessai-brand-icon">
					<span class="dashicons dashicons-universal-access-alt"></span>
				</div>
				<div class="accessai-brand-text">
					<div class="accessai-brand-title-wrap">
						<h1>AccessAI</h1>
						<span class="accessai-version-pill">v1.2</span>
					</div>
					<p class="accessai-subtitle">AI-assisted WCAG 2.1 accessibility auditing and automated remediation</p>
				</div>
			</div>
			<div class="accessai-hero-status">
				<?php if ( ! $has_api_key ) : ?>
					<div class="accessai-api-pill accessai-api-missing">
						<span class="accessai-status-dot"></span>
						<span>Gemini API Key Needed</span>
					</div>
				<?php else : ?>
					<div class="accessai-api-pill accessai-api-connected">
						<span class="accessai-status-dot"></span>
						<span>Gemini 3.8 Flash Active</span>
						<button id="accessai-test-btn" type="button" class="accessai-pill-btn">Test</button>
					</div>
					<span id="accessai-test-result" class="accessai-test-result"></span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! $has_api_key ) : ?>
			<div class="notice notice-warning accessai-notice">
				<p>
					<strong>Gemini API key not configured.</strong>
					AI explanations will not be generated.
					Add <code>define( 'ACCESSAI_GEMINI_API_KEY', 'your-key' );</code> to <code>wp-config.php</code>.
				</p>
			</div>
		<?php endif; ?>

		<!-- ============================================================
		     PAGE AUDIT COMMAND BAR
		     ============================================================ -->
		<div class="accessai-section accessai-audit-bar-section">
			<div class="accessai-audit-header">
				<div class="accessai-audit-header-icon">
					<span class="dashicons dashicons-search"></span>
				</div>
				<div class="accessai-audit-header-content">
					<h2>Target Page Audit</h2>
					<p>Select any published page or post to audit all 4 accessibility checkpoints (Alt Text, Headings, Forms, and Colour Contrast) and compute its compliance score.</p>
				</div>
			</div>

			<?php if ( empty( $published_pages ) ) : ?>
				<div class="accessai-empty-state accessai-empty-neutral">
					<div class="accessai-empty-icon"><span class="dashicons dashicons-info-outline"></span></div>
					<div class="accessai-empty-text">
						<h4>No published pages found</h4>
						<p>Create and publish at least one page or post to begin auditing.</p>
					</div>
				</div>
			<?php else : ?>
				<div class="accessai-scan-bar">
					<div class="accessai-select-wrapper">
						<span class="dashicons dashicons-admin-page accessai-select-icon"></span>
						<label for="accessai-page-select" class="screen-reader-text">Select page to audit</label>
						<select id="accessai-page-select" class="accessai-page-select">
							<?php foreach ( $published_pages as $page ) : ?>
								<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $active_page_id, $page->ID ); ?>>
									<?php echo esc_html( $page->post_title ); ?> (<?php echo esc_html( ucfirst( $page->post_type ) ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<button id="accessai-scan-btn" type="button" class="accessai-scan-btn">
						<span class="dashicons dashicons-update"></span>
						<span>Scan Page</span>
					</button>
					<?php if ( $active_page_id ) : ?>
						<button id="accessai-clear-contrast-btn" type="button" class="accessai-clear-btn">
							<span class="dashicons dashicons-trash"></span>
							<span>Clear Results</span>
						</button>
					<?php endif; ?>
				</div>

				<div id="accessai-scan-status" class="accessai-scan-status" role="status" aria-live="polite"></div>
				<div id="accessai-iframe-container"></div>
			<?php endif; ?>
		</div>

		<!-- ============================================================
		     METRIC KPI CARDS
		     ============================================================ -->
		<div class="accessai-overview">
			<div class="accessai-score-card accessai-card-score">
				<div class="accessai-card-top">
					<span class="accessai-card-label">Accessibility Score</span>
					<?php if ( $score !== null ) : ?>
						<span class="accessai-badge-rating <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score_label ); ?></span>
					<?php endif; ?>
				</div>
				<div class="accessai-score-value <?php echo esc_attr( $score_class ); ?>">
					<?php echo $score !== null ? esc_html( $score ) . '<span class="accessai-score-max">/100</span>' : '—'; ?>
				</div>
				<?php if ( $score !== null ) : ?>
					<div class="accessai-progress-track">
						<div class="accessai-progress-fill <?php echo esc_attr( $score_class ); ?>" style="width: <?php echo esc_attr( $score ); ?>%;"></div>
					</div>
				<?php endif; ?>
				<p class="accessai-card-sub">Weighted page compliance</p>
			</div>

			<div class="accessai-score-card accessai-card-issues">
				<div class="accessai-card-top">
					<span class="accessai-card-label">Total Findings</span>
					<span class="accessai-card-icon-chip"><span class="dashicons dashicons-warning"></span></span>
				</div>
				<div class="accessai-score-value accessai-score-warn">
					<?php echo $score !== null ? esc_html( $total_issues ) : '—'; ?>
				</div>
				<p class="accessai-card-sub">Issues across 4 scanner modules</p>
			</div>

			<div class="accessai-score-card accessai-card-target">
				<div class="accessai-card-top">
					<span class="accessai-card-label">Audited Target</span>
					<span class="accessai-card-icon-chip"><span class="dashicons dashicons-admin-post"></span></span>
				</div>
				<div class="accessai-score-value accessai-target-title">
					<?php echo $scanned_post ? esc_html( wp_trim_words( $scanned_post->post_title, 4 ) ) : 'None Selected'; ?>
				</div>
				<p class="accessai-card-sub">
					<?php echo $scanned_post ? '<span class="accessai-type-pill">' . esc_html( ucfirst( $scanned_post->post_type ) ) . '</span>' : 'Select page above'; ?>
				</p>
			</div>

			<div class="accessai-score-card accessai-card-passed">
				<div class="accessai-card-top">
					<span class="accessai-card-label">Checks Evaluated</span>
					<span class="accessai-card-icon-chip"><span class="dashicons dashicons-yes-alt"></span></span>
				</div>
				<div class="accessai-score-value accessai-score-pass">
					<?php echo $score !== null ? esc_html( $passed_checks . '/' . $checks_estimated ) : '—'; ?>
				</div>
				<p class="accessai-card-sub">Criteria passed without defect</p>
			</div>
		</div>

		<?php if ( ! $active_page_id ) : ?>
			<div class="accessai-section accessai-welcome-box">
				<div class="accessai-welcome-icon">
					<span class="dashicons dashicons-dashboard"></span>
				</div>
				<div class="accessai-welcome-body">
					<h3>Ready to audit accessibility</h3>
					<p>Select a published page or post from the dropdown above and click <strong>Scan Page</strong> to run a complete evaluation across Alt Text, Headings, Form Labels, and Colour Contrast.</p>
				</div>
			</div>
		<?php else : ?>

			<!-- ============================================================
			     ALT TEXT SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-alt">
							<span class="dashicons dashicons-format-image"></span>
						</div>
						<div>
							<h2>Missing &amp; Empty Alt Text</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 1.1.1 Non-text Content (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ All Clear</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $issues ) ); ?> Needs Review</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>No missing alt text issues found</h4>
							<p>All images evaluated in this page's content have proper descriptive alternative text.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-alt-table"
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
										<td><strong><?php echo esc_html( $issue['type'] ); ?></strong></td>
										<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $issue['severity'] ); ?></span></td>
										<td><?php echo esc_html( $issue['page_title'] ); ?></td>
										<td class="accessai-truncate" title="<?php echo esc_attr( $issue['image_src'] ?? '' ); ?>">
											<code><?php echo $issue['image_src'] ? esc_html( $issue['image_src'] ) : 'N/A'; ?></code>
										</td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
										<td>
											<a class="button button-secondary accessai-action-btn"
											   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
											   target="_blank">
												<span class="dashicons dashicons-edit"></span> Edit Page
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     HEADING STRUCTURE SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-heading">
							<span class="dashicons dashicons-editor-ol"></span>
						</div>
						<div>
							<h2>Heading Structure &amp; Hierarchy</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 1.3.1 Info and Relationships (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $heading_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ Proper Hierarchy</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $heading_issues ) ); ?> Discontinuity Found</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $heading_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>Proper heading hierarchy detected</h4>
							<p>Headings follow a logical sequential order with no skipped levels.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-heading-table"
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
										<td><strong><?php echo esc_html( $issue['type'] ); ?></strong></td>
										<td><?php echo esc_html( $issue['page_title'] ); ?></td>
										<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $issue['previous'] . ' → ' . $issue['current'] ); ?></span></td>
										<td><em><?php echo esc_html( $issue['heading_text'] ?: '(Empty heading)' ); ?></em></td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
										<td>
											<a class="button button-secondary accessai-action-btn"
											   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
											   target="_blank">
												<span class="dashicons dashicons-edit"></span> Edit Page
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     FORM LABEL SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-forms">
							<span class="dashicons dashicons-feedback"></span>
						</div>
						<div>
							<h2>Form Controls &amp; Input Labels</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 3.3.2 Labels or Instructions (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $form_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ All Controls Labeled</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $form_issues ) ); ?> Missing Label</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $form_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>All form controls properly labeled</h4>
							<p>Form inputs, selects, and textareas have associated labels or accessible names.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-form-table"
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
										<td><strong><?php echo esc_html( $issue['type'] ); ?></strong></td>
										<td><?php echo esc_html( $issue['page_title'] ); ?></td>
										<td>
											<span class="accessai-tag"><?php echo esc_html( $issue['control'] ); ?></span>
											<?php if ( $issue['field_type'] !== '' ) : ?>
												<small>(<?php echo esc_html( $issue['field_type'] ); ?>)</small>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( $issue['field_name'] ?: 'Not specified' ); ?></code></td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
										<td>
											<a class="button button-secondary accessai-action-btn"
											   href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
											   target="_blank">
												<span class="dashicons dashicons-edit"></span> Edit Page
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     COLOUR CONTRAST SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-contrast">
							<span class="dashicons dashicons-art"></span>
						</div>
						<div>
							<h2>Computed Colour Contrast Ratio</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 1.4.3 Contrast Minimum (Level AA)</p>
						</div>
					</div>
					<div>
						<?php if ( ! $contrast_scan_completed ) : ?>
							<span class="accessai-chip-pill accessai-chip-neutral">Pending Scan</span>
						<?php elseif ( empty( $contrast_results ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ AA Compliant</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $contrast_results ) ); ?> Low Contrast</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! $contrast_scan_completed ) : ?>
					<div class="accessai-empty-state accessai-empty-neutral" id="accessai-no-contrast-notice">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-info-outline"></span></div>
						<div class="accessai-empty-text">
							<h4>Contrast evaluation pending</h4>
							<p>Click <strong>Scan Page</strong> above to run live computed contrast analysis on this page.</p>
						</div>
					</div>
				<?php elseif ( empty( $contrast_results ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>No contrast failures detected</h4>
							<p>All tested text elements meet or exceed WCAG 2.1 Level AA minimum contrast ratios.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-contrast-table"
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
										<td><code><?php echo esc_html( $result['element'] ?? '' ); ?></code></td>
										<td><span class="accessai-text-sample"><?php echo esc_html( $result['text'] ?? '' ); ?></span></td>
										<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $result['ratio'] ?? '' ); ?></span></td>
										<td><span class="accessai-badge accessai-badge-neutral"><?php echo esc_html( $result['required'] ?? '' ); ?></span></td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
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
