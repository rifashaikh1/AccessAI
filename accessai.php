<?php
/**
 * Plugin Name: AccessAI
 * Description: AI-assisted accessibility auditing for WordPress websites across 10 WCAG 2.1 check categories.
 * Version: 1.3.0
 * Author: AccessAI Team
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load all 10 accessibility scanner modules.
require_once plugin_dir_path( __FILE__ ) . 'scanner/alt-text.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/heading-structure.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/form-labels.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/contrast-scan.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/link-accessibility.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/button-accessibility.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/table-accessibility.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/keyboard-accessibility.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/text-spacing-adaptation.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/focus-visibility.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/prioritization.php';
require_once plugin_dir_path( __FILE__ ) . 'scanner/ai-assistant.php';

// Register admin menu.
add_action( 'admin_menu', 'accessai_register_menu' );

/**
 * Load contrast-scanner.js on public frontend pages for logged-in admins.
 * Required for the scan iframe to run live computed browser evaluations.
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
		'1.3.0',
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
 * Render the full AccessAI admin dashboard covering all 10 check categories.
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

	// Initialize all 10 scan categories.
	$alt_issues              = array();
	$heading_issues          = array();
	$form_issues             = array();
	$contrast_results        = array();
	$keyboard_issues         = array();
	$link_issues             = array();
	$button_issues           = array();
	$text_spacing_results    = array();
	$focus_results           = array();
	$table_issues            = array();
	$contrast_scan_completed = false;

	if ( $active_page_id ) {
		// 1. Image Alt Text
		$alt_issues = accessai_scan_missing_alt_text( $active_page_id );

		// 2. Heading Structure
		$heading_issues = accessai_scan_heading_structure( $active_page_id );

		// 3. Form Labels
		$form_issues = accessai_scan_form_labels( $active_page_id );

		// 4. Link Accessibility
		$link_issues = accessai_scan_link_accessibility( $active_page_id );

		// 5. Button Accessibility
		$button_issues = accessai_scan_button_accessibility( $active_page_id );

		// 6. Table Accessibility
		$table_issues = accessai_scan_table_accessibility( $active_page_id );

		// 7. Keyboard Accessibility (Server-side static checks)
		$keyboard_static = accessai_scan_keyboard_accessibility( $active_page_id );

		// 8. Text Resizing & Spacing (Server-side static checks)
		$text_spacing_static = accessai_scan_text_spacing_adaptation( $active_page_id );

		// 9. Focus Visibility (Server-side static checks)
		$focus_static = accessai_scan_focus_visibility( $active_page_id );

		// Live browser-based scans (retrieved from user transients).
		$contrast_results        = get_transient( 'accessai_contrast_results_' . $user_id ) ?: array();
		$focus_browser           = get_transient( 'accessai_focus_results_' . $user_id ) ?: array();
		$text_spacing_browser    = get_transient( 'accessai_text_spacing_results_' . $user_id ) ?: array();
		$keyboard_browser        = get_transient( 'accessai_browser_keyboard_results_' . $user_id ) ?: array();
		$browser_buttons         = get_transient( 'accessai_browser_button_results_' . $user_id ) ?: array();
		$contrast_scan_completed = get_transient( 'accessai_contrast_scan_completed_' . $user_id );

		if ( ! is_array( $contrast_results ) ) { $contrast_results = array(); }
		if ( ! is_array( $focus_browser ) ) { $focus_browser = array(); }
		if ( ! is_array( $text_spacing_browser ) ) { $text_spacing_browser = array(); }
		if ( ! is_array( $keyboard_browser ) ) { $keyboard_browser = array(); }
		if ( ! is_array( $browser_buttons ) ) { $browser_buttons = array(); }

		// Merge static and live browser focus findings
		$focus_results = $focus_static;
		foreach ( $focus_browser as $f_b ) {
			$found = false;
			foreach ( $focus_static as $f_s ) {
				if (
					( ! empty( $f_s['element'] ) && $f_s['element'] === ( $f_b['element'] ?? '' ) ) ||
					( ! empty( $f_s['target_text'] ) && $f_s['target_text'] === ( $f_b['target_text'] ?? '' ) )
				) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$focus_results[] = $f_b;
			}
		}

		// Merge static and live browser keyboard findings
		$keyboard_issues = array_merge( $keyboard_static, $keyboard_browser );

		// Merge static and live browser button findings
		$button_issues_merged = $button_issues;
		foreach ( $browser_buttons as $b_btn ) {
			$found = false;
			foreach ( $button_issues as $existing_btn ) {
				if ( ( $existing_btn['selector'] ?? '' ) !== '' && ( $existing_btn['selector'] ?? '' ) === ( $b_btn['selector'] ?? '' ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$button_issues_merged[] = $b_btn;
			}
		}
		$button_issues = $button_issues_merged;

		// Merge static and live browser text spacing findings
		$text_spacing_results = $text_spacing_static;
		foreach ( $text_spacing_browser as $ts_b ) {
			$found = false;
			foreach ( $text_spacing_static as $ts_s ) {
				if (
					( ! empty( $ts_s['selector'] ) && $ts_s['selector'] === ( $ts_b['element'] ?? '' ) ) ||
					( ! empty( $ts_s['sample'] ) && $ts_s['sample'] === ( $ts_b['sample'] ?? '' ) )
				) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$text_spacing_results[] = $ts_b;
			}
		}
	}

	$total_issues = count( $alt_issues ) +
		count( $heading_issues ) +
		count( $form_issues ) +
		count( $contrast_results ) +
		count( $keyboard_issues ) +
		count( $link_issues ) +
		count( $button_issues ) +
		count( $text_spacing_results ) +
		count( $focus_results ) +
		count( $table_issues );

	// Calculate comprehensive compliance benchmark across 10 categories.
	if ( $scanned_post ) {
		$html = $scanned_post->post_content;
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . ( $html ?: '<div></div>' ) );

		$img_count     = $dom->getElementsByTagName( 'img' )->length;
		$link_count    = $dom->getElementsByTagName( 'a' )->length;
		$btn_count     = $dom->getElementsByTagName( 'button' )->length;
		$table_count   = $dom->getElementsByTagName( 'table' )->length;

		$heading_count = 0;
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			if ( preg_match( '/^h[1-6]$/i', $node->tagName ) ) {
				$heading_count++;
			}
		}

		$xpath      = new DOMXPath( $dom );
		$form_count = $xpath->query( '//input[not(@type="hidden")] | //select | //textarea' )->length;
		libxml_clear_errors();

		$checks_estimated = max(
			15,
			max( 2, $img_count ) +
			max( 2, $heading_count ) +
			max( 2, $form_count ) +
			max( 4, count( $contrast_results ) ) +
			max( 2, $link_count ) +
			max( 2, $btn_count ) +
			max( 1, $table_count ) +
			max( 2, count( $keyboard_issues ) ) +
			max( 2, count( $focus_results ) ) +
			max( 2, count( $text_spacing_results ) )
		);

		$passed_checks = max( 0, $checks_estimated - $total_issues );
		$score         = min( 100, max( 0, round( ( $passed_checks / $checks_estimated ) * 100 ) ) );
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

	// Build fingerprinted issue lists for auto-explain across all 10 categories.
	$alt_issues_json          = accessai_build_issue_data( $alt_issues, 'alt_text' );
	$heading_issues_json      = accessai_build_issue_data( $heading_issues, 'heading_structure' );
	$form_issues_json         = accessai_build_issue_data( $form_issues, 'form_label' );
	$contrast_issues_json     = accessai_build_issue_data( $contrast_results, 'color_contrast' );
	$keyboard_issues_json     = accessai_build_issue_data( $keyboard_issues, 'keyboard' );
	$link_issues_json         = accessai_build_issue_data( $link_issues, 'links' );
	$button_issues_json       = accessai_build_issue_data( $button_issues, 'buttons' );
	$text_spacing_issues_json = accessai_build_issue_data( $text_spacing_results, 'text_spacing' );
	$focus_issues_json        = accessai_build_issue_data( $focus_results, 'focus' );
	$table_issues_json        = accessai_build_issue_data( $table_issues, 'tables' );

	// Build unified normalized findings list across all 10 categories.
	$normalized_findings = array();
	$raw_category_map    = array(
		'alt_text'          => $alt_issues,
		'heading_structure' => $heading_issues,
		'form_label'        => $form_issues,
		'color_contrast'    => $contrast_results,
		'keyboard'          => $keyboard_issues,
		'links'             => $link_issues,
		'buttons'           => $button_issues,
		'text_spacing'      => $text_spacing_results,
		'focus'             => $focus_results,
		'tables'            => $table_issues,
	);

	foreach ( $raw_category_map as $cat_id => $raw_list ) {
		if ( ! empty( $raw_list ) && is_array( $raw_list ) ) {
			foreach ( $raw_list as $idx => $raw_item ) {
				$normalized_findings[] = accessai_normalize_finding( $raw_item, $cat_id, $scanned_post, $idx );
			}
		}
	}

	// Calculate counts for priorities and detection statuses.
	$priority_counts = array(
		'critical' => 0,
		'high'     => 0,
		'medium'   => 0,
		'low'      => 0,
	);
	$status_counts = array(
		'total'     => count( $normalized_findings ),
		'failure'   => 0,
		'potential' => 0,
		'manual'    => 0,
	);
	$active_statuses = array();

	foreach ( $normalized_findings as $finding ) {
		$p = $finding['priority'];
		if ( isset( $priority_counts[ $p ] ) ) {
			$priority_counts[ $p ]++;
		}
		$st = $finding['status'];
		if ( ! in_array( $st, $active_statuses, true ) ) {
			$active_statuses[] = $st;
		}
		if ( $st === 'Verified Failure' ) {
			$status_counts['failure']++;
		} elseif ( $st === 'Potential Issue' ) {
			$status_counts['potential']++;
		} elseif ( $st === 'Manual Review Required' ) {
			$status_counts['manual']++;
		}
	}

	// Sort findings by default: Highest Priority First.
	usort( $normalized_findings, function( $a, $b ) {
		if ( $a['priority_weight'] !== $b['priority_weight'] ) {
			return $b['priority_weight'] <=> $a['priority_weight'];
		}
		$cat_cmp = strcmp( $a['category_label'], $b['category_label'] );
		if ( $cat_cmp !== 0 ) {
			return $cat_cmp;
		}
		return strcmp( $a['title'], $b['title'] );
	} );

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
						<span class="accessai-version-pill">v1.3 &bull; 10 Checkpoints</span>
					</div>
					<p class="accessai-subtitle">Comprehensive WCAG 2.1 accessibility auditing and AI-assisted remediation across 10 core categories</p>
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
					Standard WCAG 2.1 authoritative guidance fallbacks will be used.
					Add <code>define( 'ACCESSAI_GEMINI_API_KEY', 'your-key' );</code> to <code>wp-config.php</code> to activate real-time Gemini recommendations.
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
					<h2>Target Page Audit (10 Checkpoint Suite)</h2>
					<p>Select any published page or post to audit all 10 WCAG checkpoints: Alt Text, Headings, Form Labels, Colour Contrast, Keyboard, Links, Buttons, Text Resizing &amp; Spacing, Focus Visibility, and Tables.</p>
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
						<span>Scan Page (10 Checkpoints)</span>
					</button>
					<?php if ( $active_page_id ) : ?>
						<button id="accessai-clear-contrast-btn" type="button" class="accessai-clear-btn">
							<span class="dashicons dashicons-trash"></span>
							<span>Clear Results</span>
						</button>
						<button id="accessai-download-pdf-btn" type="button" class="accessai-pdf-btn"
								data-page-id="<?php echo esc_attr( $active_page_id ); ?>"
								data-page-title="<?php echo esc_attr( $scanned_post ? $scanned_post->post_title : 'Page' ); ?>"
								data-page-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
								data-score="<?php echo esc_attr( $score !== null ? $score : '' ); ?>"
								data-score-label="<?php echo esc_attr( $score_label ); ?>"
								data-score-class="<?php echo esc_attr( $score_class ); ?>"
								data-total-issues="<?php echo esc_attr( $total_issues ); ?>"
								data-passed-checks="<?php echo esc_attr( $passed_checks ); ?>"
								data-total-checks="<?php echo esc_attr( $checks_estimated ); ?>"
								title="Download detailed, professional WCAG accessibility audit PDF">
							<span class="dashicons dashicons-pdf"></span>
							<span>Download 10-Check Audit Report</span>
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
				<p class="accessai-card-sub">Automated audit benchmark</p>
			</div>

			<div class="accessai-score-card accessai-card-issues">
				<div class="accessai-card-top">
					<span class="accessai-card-label">Total Findings</span>
					<span class="accessai-card-icon-chip"><span class="dashicons dashicons-warning"></span></span>
				</div>
				<div class="accessai-score-value accessai-score-warn">
					<?php echo $score !== null ? esc_html( $total_issues ) : '—'; ?>
				</div>
				<p class="accessai-card-sub">Issues across 10 scanner modules</p>
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

		<!-- ============================================================
		     WCAG COMPLIANCE BENCHMARK NOTICE (NON-COMPLIANCE DISCLAIMER)
		     ============================================================ -->
		<div class="accessai-disclaimer-card">
			<span class="dashicons dashicons-info accessai-disclaimer-icon"></span>
			<p>
				<strong>Accessibility Evaluation Notice:</strong>
				This compliance score is an automated programmatic benchmark across 10 measurable WCAG 2.1 AA criteria.
				Automated testing cannot detect 100% of accessibility barriers (such as subjective image context, cognitive clarity, or full screen reader pronunciation) and is not legal proof of complete WCAG compliance. Manual evaluation with assistive technologies is always recommended.
			</p>
		</div>

		<?php if ( ! $active_page_id ) : ?>
			<div class="accessai-section accessai-welcome-box">
				<div class="accessai-welcome-icon">
					<span class="dashicons dashicons-dashboard"></span>
				</div>
				<div class="accessai-welcome-body">
					<h3>Ready to audit accessibility</h3>
					<p>Select a published page or post from the dropdown above and click <strong>Scan Page (10 Checkpoints)</strong> to run a complete evaluation across all 10 WCAG categories.</p>
				</div>
			</div>
		<?php else : ?>

			<!-- ============================================================
			     SMART ISSUE PRIORITIZATION & SUMMARY DASHBOARD
			     ============================================================ -->
			<div class="accessai-priority-summary-section" id="accessai-priority-summary">
				<div class="accessai-summary-header">
					<div class="accessai-summary-title">
						<span class="dashicons dashicons-shield-alt accessai-summary-icon"></span>
						<div>
							<h3>Issue Prioritization &amp; Severity Breakdown</h3>
							<p class="accessai-summary-subtitle">Deterministic ranking based on barrier severity, disabled user impact, and detection confidence</p>
						</div>
					</div>
					<div class="accessai-summary-meta">
						<span class="accessai-summary-hint"><span class="dashicons dashicons-filter"></span> Click any priority card to filter findings</span>
					</div>
				</div>

				<div class="accessai-priority-cards-grid">
					<!-- Critical Priority Card -->
					<button type="button" class="accessai-priority-card is-critical" data-priority="critical" aria-pressed="false" title="Filter by Critical Priority">
						<div class="accessai-pcard-top">
							<span class="accessai-priority-badge is-critical">
								<span class="dashicons dashicons-dismiss"></span> Critical
							</span>
							<span class="accessai-pcard-count" id="accessai-count-critical"><?php echo esc_html( $priority_counts['critical'] ); ?></span>
						</div>
						<div class="accessai-pcard-label">Critical Priority</div>
						<p class="accessai-pcard-desc">Severe barrier blocking essential content or functionality</p>
					</button>

					<!-- High Priority Card -->
					<button type="button" class="accessai-priority-card is-high" data-priority="high" aria-pressed="false" title="Filter by High Priority">
						<div class="accessai-pcard-top">
							<span class="accessai-priority-badge is-high">
								<span class="dashicons dashicons-warning"></span> High
							</span>
							<span class="accessai-pcard-count" id="accessai-count-high"><?php echo esc_html( $priority_counts['high'] ); ?></span>
						</div>
						<div class="accessai-pcard-label">High Priority</div>
						<p class="accessai-pcard-desc">Significant accessibility barrier that should be addressed soon</p>
					</button>

					<!-- Medium Priority Card -->
					<button type="button" class="accessai-priority-card is-medium" data-priority="medium" aria-pressed="false" title="Filter by Medium Priority">
						<div class="accessai-pcard-top">
							<span class="accessai-priority-badge is-medium">
								<span class="dashicons dashicons-info"></span> Medium
							</span>
							<span class="accessai-pcard-count" id="accessai-count-medium"><?php echo esc_html( $priority_counts['medium'] ); ?></span>
						</div>
						<div class="accessai-pcard-label">Medium Priority</div>
						<p class="accessai-pcard-desc">Accessibility problem with more limited impact or provisional</p>
					</button>

					<!-- Low Priority Card -->
					<button type="button" class="accessai-priority-card is-low" data-priority="low" aria-pressed="false" title="Filter by Low Priority">
						<div class="accessai-pcard-top">
							<span class="accessai-priority-badge is-low">
								<span class="dashicons dashicons-lightbulb"></span> Low
							</span>
							<span class="accessai-pcard-count" id="accessai-count-low"><?php echo esc_html( $priority_counts['low'] ); ?></span>
						</div>
						<div class="accessai-pcard-label">Low Priority</div>
						<p class="accessai-pcard-desc">Lower-impact issue or improvement recommendation</p>
					</button>
				</div>

				<!-- Status Breakdown Summary Strip -->
				<div class="accessai-status-summary-bar">
					<div class="accessai-status-metric">
						<span class="accessai-sm-label">Total Findings:</span>
						<strong class="accessai-sm-value" id="accessai-summary-total"><?php echo esc_html( $status_counts['total'] ); ?></strong>
					</div>
					<div class="accessai-status-divider" aria-hidden="true">•</div>
					<div class="accessai-status-metric">
						<span class="accessai-status-dot is-failure"></span>
						<span class="accessai-sm-label">Verified Failures:</span>
						<strong class="accessai-sm-value" id="accessai-summary-failure"><?php echo esc_html( $status_counts['failure'] ); ?></strong>
					</div>
					<div class="accessai-status-divider" aria-hidden="true">•</div>
					<div class="accessai-status-metric">
						<span class="accessai-status-dot is-potential"></span>
						<span class="accessai-sm-label">Potential Issues:</span>
						<strong class="accessai-sm-value" id="accessai-summary-potential"><?php echo esc_html( $status_counts['potential'] ); ?></strong>
					</div>
					<div class="accessai-status-divider" aria-hidden="true">•</div>
					<div class="accessai-status-metric">
						<span class="accessai-status-dot is-manual"></span>
						<span class="accessai-sm-label">Manual Review Required:</span>
						<strong class="accessai-sm-value" id="accessai-summary-manual"><?php echo esc_html( $status_counts['manual'] ); ?></strong>
					</div>
				</div>
			</div>

			<!-- ============================================================
			     FILTER & SORT TOOLBAR
			     ============================================================ -->
			<div class="accessai-section accessai-filter-section" id="accessai-filter-panel">
				<div class="accessai-filter-toolbar">
					<div class="accessai-filter-grid">
						<!-- Search Field -->
						<div class="accessai-filter-col accessai-search-col">
							<label for="accessai-filter-search" class="accessai-filter-field-label">Search Findings</label>
							<div class="accessai-search-input-wrap">
								<span class="dashicons dashicons-search accessai-search-ico"></span>
								<input type="search"
									   id="accessai-filter-search"
									   class="accessai-filter-input"
									   placeholder="Search title, description, selector, URL, or WCAG (e.g. 1.4.3)..."
									   aria-label="Search accessibility issues"
									   autocomplete="off" />
								<button type="button" id="accessai-search-clear-btn" class="accessai-search-clear-btn" title="Clear search" style="display:none;">
									<span class="dashicons dashicons-no-alt"></span>
								</button>
							</div>
						</div>

						<!-- Priority Filter -->
						<div class="accessai-filter-col">
							<label for="accessai-filter-priority" class="accessai-filter-field-label">Priority</label>
							<select id="accessai-filter-priority" class="accessai-filter-select">
								<option value="all">All Priorities (<?php echo esc_html( $status_counts['total'] ); ?>)</option>
								<option value="critical">Critical (<?php echo esc_html( $priority_counts['critical'] ); ?>)</option>
								<option value="high">High (<?php echo esc_html( $priority_counts['high'] ); ?>)</option>
								<option value="medium">Medium (<?php echo esc_html( $priority_counts['medium'] ); ?>)</option>
								<option value="low">Low (<?php echo esc_html( $priority_counts['low'] ); ?>)</option>
							</select>
						</div>

						<!-- Issue Category Filter -->
						<div class="accessai-filter-col">
							<label for="accessai-filter-category" class="accessai-filter-field-label">Issue Category</label>
							<select id="accessai-filter-category" class="accessai-filter-select">
								<option value="all">All Categories</option>
								<?php
								$registered_scanners = accessai_get_registered_scanners();
								foreach ( $registered_scanners as $sc_id => $sc_info ) :
									$cat_count = 0;
									if ( isset( $raw_category_map[ $sc_id ] ) && is_array( $raw_category_map[ $sc_id ] ) ) {
										$cat_count = count( $raw_category_map[ $sc_id ] );
									}
								?>
									<option value="<?php echo esc_attr( $sc_id ); ?>">
										<?php echo esc_html( $sc_info['label'] ); ?> (<?php echo esc_html( $cat_count ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- Detection Status Filter (Only relevant statuses) -->
						<div class="accessai-filter-col">
							<label for="accessai-filter-status" class="accessai-filter-field-label">Detection Status</label>
							<select id="accessai-filter-status" class="accessai-filter-select">
								<option value="all">All Statuses</option>
								<?php foreach ( $active_statuses as $st_name ) : ?>
									<option value="<?php echo esc_attr( $st_name ); ?>">
										<?php echo esc_html( $st_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- Sorting Options -->
						<div class="accessai-filter-col">
							<label for="accessai-sort-select" class="accessai-filter-field-label">Sort By</label>
							<select id="accessai-sort-select" class="accessai-filter-select">
								<option value="highest" selected>Highest Priority First</option>
								<option value="lowest">Lowest Priority First</option>
								<option value="category">Issue Category (A–Z)</option>
								<option value="wcag">WCAG Criterion</option>
								<option value="url">Page URL</option>
							</select>
						</div>

						<!-- Actions -->
						<div class="accessai-filter-col accessai-filter-action-col">
							<span class="accessai-filter-field-label">&nbsp;</span>
							<button type="button" id="accessai-clear-filters-btn" class="button accessai-clear-btn" title="Reset all filters and sort">
								<span class="dashicons dashicons-undo"></span>
								<span>Clear Filters</span>
							</button>
						</div>
					</div>

					<!-- Counter & View Switcher Bar -->
					<div class="accessai-filter-footer-bar">
						<div class="accessai-filter-count-badge">
							<span id="accessai-filter-counter-text">
								Showing <strong id="accessai-visible-count"><?php echo esc_html( count( $normalized_findings ) ); ?></strong> of <strong><?php echo esc_html( count( $normalized_findings ) ); ?></strong> findings
							</span>
						</div>
						<div class="accessai-view-tabs" role="tablist" aria-label="Findings View Mode">
							<button type="button" class="accessai-view-tab is-active" id="accessai-tab-prioritized" data-target="prioritized" role="tab" aria-selected="true">
								<span class="dashicons dashicons-sort"></span>
								<span>Prioritized Stream</span>
							</button>
							<button type="button" class="accessai-view-tab" id="accessai-tab-category" data-target="category" role="tab" aria-selected="false">
								<span class="dashicons dashicons-category"></span>
								<span>Category Sections</span>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Empty State when Filter Matches 0 Findings -->
			<div id="accessai-no-filter-results" class="accessai-empty-state accessai-filter-empty" style="display: none;">
				<div class="accessai-empty-icon"><span class="dashicons dashicons-filter"></span></div>
				<div class="accessai-empty-text">
					<h4>No accessibility findings match your filter criteria</h4>
					<p>Try clearing your search query or selecting a different priority or category.</p>
					<button type="button" id="accessai-empty-reset-btn" class="button button-primary accessai-reset-filters-btn">
						<span class="dashicons dashicons-undo"></span> Reset All Filters
					</button>
				</div>
			</div>

			<!-- ============================================================
			     PRIORITIZED MASTER FINDINGS VIEW (DEFAULT VIEW)
			     ============================================================ -->
			<div id="accessai-prioritized-view" class="accessai-view-panel is-active">
				<?php if ( empty( $normalized_findings ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>No accessibility defects detected</h4>
							<p>This page passed all 10 automated WCAG compliance checkpoints without defects.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-findings-stream" id="accessai-findings-stream">
						<?php foreach ( $normalized_findings as $f_idx => $finding ) :
							$status_class = ( $finding['status'] === 'Verified Failure' )
								? 'accessai-status-failure'
								: ( ( $finding['status'] === 'Potential Issue' ) ? 'accessai-status-potential' : 'accessai-status-manual' );
						?>
							<article class="accessai-finding-card accessai-priority-<?php echo esc_attr( $finding['priority'] ); ?>"
									 id="<?php echo esc_attr( $finding['id'] ); ?>"
									 data-finding-id="<?php echo esc_attr( $finding['id'] ); ?>"
									 data-fingerprint="<?php echo esc_attr( $finding['fingerprint'] ); ?>"
									 data-priority="<?php echo esc_attr( $finding['priority'] ); ?>"
									 data-priority-weight="<?php echo esc_attr( $finding['priority_weight'] ); ?>"
									 data-category="<?php echo esc_attr( $finding['category_id'] ); ?>"
									 data-category-label="<?php echo esc_attr( $finding['category_label'] ); ?>"
									 data-status="<?php echo esc_attr( $finding['status'] ); ?>"
									 data-wcag="<?php echo esc_attr( $finding['wcag_criterion'] ); ?>"
									 data-url="<?php echo esc_attr( $finding['page_url'] ); ?>"
									 data-search-text="<?php echo esc_attr( $finding['search_text'] ); ?>"
									 data-original-order="<?php echo esc_attr( $f_idx ); ?>"
									 data-page-id="<?php echo esc_attr( $finding['page_id'] ); ?>"
									 data-image-src="<?php echo esc_attr( $finding['raw']['image_src'] ?? '' ); ?>">

								<!-- Finding Header -->
								<header class="accessai-card-header">
									<div class="accessai-card-badge-row">
										<!-- Priority Label Badge -->
										<span class="accessai-priority-badge is-<?php echo esc_attr( $finding['priority'] ); ?>">
											<span class="dashicons <?php echo $finding['priority'] === 'critical' ? 'dashicons-dismiss' : ( $finding['priority'] === 'high' ? 'dashicons-warning' : ( $finding['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
											<span><?php echo esc_html( $finding['priority_label'] ); ?></span>
										</span>

										<?php if ( $finding['is_provisional'] ) : ?>
											<span class="accessai-provisional-pill" title="Unverified finding requiring manual confirmation">Provisional</span>
										<?php endif; ?>

										<!-- Issue Category -->
										<span class="accessai-category-badge">
											<?php echo esc_html( $finding['category_label'] ); ?>
										</span>

										<!-- WCAG Reference -->
										<span class="accessai-wcag-badge" title="<?php echo esc_attr( $finding['wcag'] ); ?>">
											<?php echo esc_html( $finding['wcag_criterion'] . ' ' . $finding['wcag_level'] ); ?>
										</span>
									</div>

									<div class="accessai-card-status-wrap">
										<span class="accessai-status-badge <?php echo esc_attr( $status_class ); ?>">
											<?php echo esc_html( $finding['status_label'] ); ?>
										</span>
									</div>
								</header>

								<!-- Finding Body -->
								<div class="accessai-card-body">
									<h4 class="accessai-card-title"><?php echo esc_html( $finding['title'] ); ?></h4>
									<p class="accessai-card-desc"><?php echo esc_html( $finding['description'] ); ?></p>

									<!-- Affected Page or Element -->
									<div class="accessai-card-element-row">
										<span class="accessai-row-label">Affected Target:</span>
										<div class="accessai-card-element-content">
											<?php echo $finding['element_html']; ?>
										</div>
									</div>

									<!-- Recommended Remediation -->
									<?php if ( ! empty( $finding['remediation'] ) ) : ?>
										<div class="accessai-card-remediation-row">
											<span class="dashicons dashicons-lightbulb accessai-remediation-icon"></span>
											<div class="accessai-remediation-content">
												<strong>Recommended Remediation:</strong> <?php echo esc_html( $finding['remediation'] ); ?>
											</div>
										</div>
									<?php endif; ?>
								</div>

								<!-- AI Explanation & Actions -->
								<footer class="accessai-card-footer">
									<div class="accessai-ai-cell" data-fingerprint="<?php echo esc_attr( $finding['fingerprint'] ); ?>">
										<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
									</div>
									<div class="accessai-card-actions">
										<?php if ( $finding['page_id'] ) : ?>
											<a class="button button-secondary accessai-action-btn"
											   href="<?php echo esc_url( get_edit_post_link( $finding['page_id'] ) ); ?>"
											   target="_blank"
											   title="Edit page in WordPress editor">
												<span class="dashicons dashicons-edit"></span> Edit Page
											</a>
										<?php endif; ?>
									</div>
								</footer>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     DETAILED CATEGORY SECTIONS (10 CHECKPOINTS)
			     ============================================================ -->
			<div id="accessai-category-view" class="accessai-view-panel">

			<!-- ============================================================
			     1. ALT TEXT SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section" data-category="alt_text">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-alt">
							<span class="dashicons dashicons-format-image"></span>
						</div>
						<div>
							<h2>1. Missing &amp; Empty Alt Text</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 1.1.1 Non-text Content (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $alt_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ All Clear</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $alt_issues ) ); ?> Needs Review</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $alt_issues ) ) : ?>
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
									<th>Detection Status</th>
									<th>Image Source</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $alt_issues as $issue ) :
									$fp = md5( 'alt_text|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['image_src'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $issue, 'alt_text' );
									$search_text = strtolower( ( $issue['type'] ?? '' ) . ' ' . ( $issue['image_src'] ?? '' ) . ' 1.1.1 Image Alternative Text Verified Failure ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-page-id="<?php echo esc_attr( $issue['page_id'] ?? '' ); ?>"
										data-image-src="<?php echo esc_attr( $issue['image_src'] ?? '' ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="alt_text"
										data-status="Verified Failure"
										data-wcag="1.1.1"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $issue['type'] ); ?></strong>
										</td>
										<td><span class="accessai-status-badge accessai-status-failure">Verified Failure</span></td>
										<td class="accessai-wrap-col" title="<?php echo esc_attr( $issue['image_src'] ?? '' ); ?>">
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
			     2. HEADING STRUCTURE SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section" data-category="heading_structure">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-heading">
							<span class="dashicons dashicons-editor-ol"></span>
						</div>
						<div>
							<h2>2. Heading Structure &amp; Hierarchy</h2>
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
									<th>Detection Status</th>
									<th>Heading Transition</th>
									<th>Heading Text</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $heading_issues as $issue ) :
									$fp = md5( 'heading_structure|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['previous'] ?? '' ) . '|' . ( $issue['current'] ?? '' ) . '|' . ( $issue['heading_text'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $issue, 'heading_structure' );
									$search_text = strtolower( ( $issue['type'] ?? '' ) . ' ' . ( $issue['previous'] ?? '' ) . ' ' . ( $issue['current'] ?? '' ) . ' ' . ( $issue['heading_text'] ?? '' ) . ' 1.3.1 Heading Hierarchy Potential Issue ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="heading_structure"
										data-status="Potential Issue"
										data-wcag="1.3.1"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $issue['type'] ); ?></strong>
										</td>
										<td><span class="accessai-status-badge accessai-status-potential">Potential Issue</span></td>
										<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $issue['previous'] . ' → ' . $issue['current'] ); ?></span></td>
										<td class="accessai-wrap-col"><em><?php echo esc_html( $issue['heading_text'] ?: '(Empty heading)' ); ?></em></td>
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
			     3. FORM LABEL SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section" data-category="form_label">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-forms">
							<span class="dashicons dashicons-feedback"></span>
						</div>
						<div>
							<h2>3. Form Controls &amp; Input Labels</h2>
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
									<th>Detection Status</th>
									<th>Control</th>
									<th>Field Name</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $form_issues as $issue ) :
									$fp = md5( 'form_label|' . ( $issue['page_id'] ?? '' ) . '|' . ( $issue['control'] ?? '' ) . '|' . ( $issue['field_name'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $issue, 'form_label' );
									$search_text = strtolower( ( $issue['type'] ?? '' ) . ' ' . ( $issue['control'] ?? '' ) . ' ' . ( $issue['field_name'] ?? '' ) . ' 3.3.2 Form Labels Verified Failure ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="form_label"
										data-status="Verified Failure"
										data-wcag="3.3.2"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $issue['type'] ); ?></strong>
										</td>
										<td><span class="accessai-status-badge accessai-status-failure">Verified Failure</span></td>
										<td>
											<span class="accessai-tag"><?php echo esc_html( $issue['control'] ); ?></span>
											<?php if ( $issue['field_type'] !== '' ) : ?>
												<small>(<?php echo esc_html( $issue['field_type'] ); ?>)</small>
											<?php endif; ?>
										</td>
										<td class="accessai-wrap-col"><code><?php echo esc_html( $issue['field_name'] ?: 'Not specified' ); ?></code></td>
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
			     4. COLOUR CONTRAST SCAN RESULTS
			     ============================================================ -->
			<div class="accessai-section" data-category="color_contrast">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-contrast">
							<span class="dashicons dashicons-art"></span>
						</div>
						<div>
							<h2>4. Computed Colour Contrast Ratio</h2>
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
							<h4>Live contrast evaluation pending</h4>
							<p>Click <strong>Scan Page (10 Checkpoints)</strong> above to run live computed contrast analysis on this page.</p>
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
									<th>Detection Status</th>
									<th>Text Sample</th>
									<th>Contrast Ratio</th>
									<th>Required</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $contrast_results as $idx => $result ) :
									$fp = md5( 'color_contrast|' . ( $result['element'] ?? '' ) . '|' . ( $result['text'] ?? '' ) . '|' . ( $result['ratio'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $result, 'color_contrast' );
									$search_text = strtolower( ( $result['element'] ?? '' ) . ' ' . ( $result['text'] ?? '' ) . ' ' . ( $result['ratio'] ?? '' ) . ' 1.4.3 Colour Contrast Verified Failure ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="color_contrast"
										data-status="Verified Failure"
										data-wcag="1.4.3"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<code><?php echo esc_html( $result['element'] ?? '' ); ?></code>
										</td>
										<td><span class="accessai-status-badge accessai-status-failure">Verified Failure</span></td>
										<td class="accessai-wrap-col"><span class="accessai-text-sample"><?php echo esc_html( $result['text'] ?? '' ); ?></span></td>
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

			<!-- ============================================================
			     5. KEYBOARD ACCESSIBILITY & TRAPS
			     ============================================================ -->
			<div class="accessai-section" data-category="keyboard">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-keyboard">
							<span class="dashicons dashicons-keyboard"></span>
						</div>
						<div>
							<h2>5. Keyboard Accessibility &amp; Trap Prevention</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guidelines 2.1.1 Keyboard &amp; 2.1.2 No Keyboard Trap (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $keyboard_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ Keyboard Navigable</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $keyboard_issues ) ); ?> Findings</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $keyboard_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>No keyboard accessibility defects detected</h4>
							<p>Interactive controls are reachable via keyboard tab navigation without positive tabindex conflicts or traps.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-keyboard-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $keyboard_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Finding</th>
									<th>Detection Status</th>
									<th>Element / Target</th>
									<th>Description</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $keyboard_issues as $k_issue ) :
									$status_class = ( ( $k_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: ( ( $k_issue['status'] ?? '' ) === 'Potential Issue' ? 'accessai-status-potential' : 'accessai-status-manual' );
									$fp = md5( 'keyboard|' . ( $k_issue['type'] ?? '' ) . '|' . ( $k_issue['element'] ?? '' ) . '|' . ( $k_issue['context'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $k_issue, 'keyboard' );
									$search_text = strtolower( ( $k_issue['type'] ?? '' ) . ' ' . strip_tags( $k_issue['element'] ?? '' ) . ' ' . ( $k_issue['context'] ?? '' ) . ' ' . ( $k_issue['message'] ?? '' ) . ' 2.1.1 Keyboard ' . ( $k_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="keyboard"
										data-status="<?php echo esc_attr( $k_issue['status'] ?? 'Potential Issue' ); ?>"
										data-wcag="2.1.1"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $k_issue['type'] ?? 'Keyboard Finding' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $k_issue['status'] ?? 'Potential Issue' ); ?></span></td>
										<td class="accessai-wrap-col">
											<code><?php echo wp_kses_post( $k_issue['element'] ?? '' ); ?></code>
											<?php if ( ! empty( $k_issue['context'] ) ) : ?>
												<div class="accessai-subcontext"><?php echo esc_html( $k_issue['context'] ); ?></div>
											<?php endif; ?>
										</td>
										<td class="accessai-wrap-col"><?php echo esc_html( $k_issue['message'] ?? '' ); ?></td>
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

			<!-- ============================================================
			     6. LINK ACCESSIBILITY & CONTEXT
			     ============================================================ -->
			<div class="accessai-section" data-category="links">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-links">
							<span class="dashicons dashicons-admin-links"></span>
						</div>
						<div>
							<h2>6. Link Purpose &amp; Text Context</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 2.4.4 Link Purpose (In Context) (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $link_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ All Links Descriptive</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $link_issues ) ); ?> Link Defects</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $link_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>All evaluated links have clear purpose</h4>
							<p>Anchor elements possess descriptive text or accessible names without vague "click here" phrases.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-link-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $link_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Issue</th>
									<th>Detection Status</th>
									<th>Link Text</th>
									<th>Destination URL</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $link_issues as $l_issue ) :
									$l_status_class = ( ( $l_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: 'accessai-status-potential';
									$fp = md5( 'links|' . ( $l_issue['type'] ?? '' ) . '|' . ( $l_issue['link_url'] ?? '' ) . '|' . ( $l_issue['link_text'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $l_issue, 'links' );
									$search_text = strtolower( ( $l_issue['type'] ?? '' ) . ' ' . ( $l_issue['link_text'] ?? '' ) . ' ' . ( $l_issue['link_url'] ?? '' ) . ' 2.4.4 Link Accessibility ' . ( $l_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="links"
										data-status="<?php echo esc_attr( $l_issue['status'] ?? 'Potential Issue' ); ?>"
										data-wcag="2.4.4"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $l_issue['type'] ?? 'Link Finding' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $l_status_class ); ?>"><?php echo esc_html( $l_issue['status'] ?? 'Potential Issue' ); ?></span></td>
										<td class="accessai-wrap-col"><code><?php echo esc_html( $l_issue['link_text'] ?? '(empty)' ); ?></code></td>
										<td class="accessai-wrap-col"><span class="accessai-url-pill"><?php echo esc_html( $l_issue['link_url'] ?? '' ); ?></span></td>
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

			<!-- ============================================================
			     7. BUTTON ACCESSIBILITY
			     ============================================================ -->
			<div class="accessai-section" data-category="buttons">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-buttons">
							<span class="dashicons dashicons-marker"></span>
						</div>
						<div>
							<h2>7. Button Name, Role &amp; Value</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 4.1.2 Name, Role, Value (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $button_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ All Buttons Labeled</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $button_issues ) ); ?> Button Issues</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $button_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>All buttons have accessible names</h4>
							<p>Native buttons, input triggers, and custom widgets possess accessible labels, values, or ARIA names.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-button-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $button_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Issue</th>
									<th>Detection Status</th>
									<th>Button Element</th>
									<th>Description</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $button_issues as $b_issue ) :
									$b_status_class = ( ( $b_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: 'accessai-status-potential';
									$fp = md5( 'buttons|' . ( $b_issue['type'] ?? '' ) . '|' . ( $b_issue['element'] ?? '' ) . '|' . ( $b_issue['button_text'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $b_issue, 'buttons' );
									$search_text = strtolower( ( $b_issue['type'] ?? '' ) . ' ' . strip_tags( $b_issue['element'] ?? '' ) . ' ' . ( $b_issue['button_text'] ?? '' ) . ' ' . ( $b_issue['message'] ?? '' ) . ' 4.1.2 Buttons ' . ( $b_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="buttons"
										data-status="<?php echo esc_attr( $b_issue['status'] ?? 'Verified Failure' ); ?>"
										data-wcag="4.1.2"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $b_issue['type'] ?? 'Button Issue' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $b_status_class ); ?>"><?php echo esc_html( $b_issue['status'] ?? 'Verified Failure' ); ?></span></td>
										<td class="accessai-wrap-col"><?php echo wp_kses_post( $b_issue['element'] ?? '' ); ?></td>
										<td class="accessai-wrap-col"><?php echo esc_html( $b_issue['message'] ?? '' ); ?></td>
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

			<!-- ============================================================
			     8. TEXT RESIZING & SPACING
			     ============================================================ -->
			<div class="accessai-section" data-category="text_spacing">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-text">
							<span class="dashicons dashicons-editor-textcolor"></span>
						</div>
						<div>
							<h2>8. Text Resizing &amp; Spacing Adaptation</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guidelines 1.4.4 Resize Text &amp; 1.4.12 Text Spacing (Level AA)</p>
						</div>
					</div>
					<div>
						<?php if ( ! empty( $text_spacing_results ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $text_spacing_results ) ); ?> Clipping Issues</span>
						<?php elseif ( ! $contrast_scan_completed ) : ?>
							<span class="accessai-chip-pill accessai-chip-neutral">Pending Live Scan</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ Resilient Layout</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! empty( $text_spacing_results ) ) : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-text-spacing-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $text_spacing_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Issue</th>
									<th>Detection Status</th>
									<th>Element Container</th>
									<th>Content Sample</th>
									<th>Measurements</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $text_spacing_results as $ts_issue ) :
									$ts_status_class = ( ( $ts_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: 'accessai-status-potential';
									$fp = md5( 'text_spacing|' . ( $ts_issue['type'] ?? '' ) . '|' . ( $ts_issue['element'] ?? '' ) . '|' . ( $ts_issue['sample'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $ts_issue, 'text_spacing' );
									$search_text = strtolower( ( $ts_issue['type'] ?? '' ) . ' ' . ( $ts_issue['element'] ?? '' ) . ' ' . ( $ts_issue['sample'] ?? '' ) . ' 1.4.4 Text Resizing ' . ( $ts_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="text_spacing"
										data-status="<?php echo esc_attr( $ts_issue['status'] ?? 'Verified Failure' ); ?>"
										data-wcag="1.4.4"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $ts_issue['type'] ?? 'Text Spacing Finding' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $ts_status_class ); ?>"><?php echo esc_html( $ts_issue['status'] ?? 'Verified Failure' ); ?></span></td>
										<td><code><?php echo esc_html( $ts_issue['element'] ?? '' ); ?></code></td>
										<td class="accessai-wrap-col"><em><?php echo esc_html( $ts_issue['sample'] ?? '' ); ?></em></td>
										<td class="accessai-wrap-col"><small><?php echo esc_html( $ts_issue['metrics'] ?? '' ); ?></small></td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php elseif ( ! $contrast_scan_completed ) : ?>
					<div class="accessai-empty-state accessai-empty-neutral">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-info-outline"></span></div>
						<div class="accessai-empty-text">
							<h4>Live browser resizing &amp; spacing test pending</h4>
							<p>Click <strong>Scan Page (10 Checkpoints)</strong> to run safe, non-destructive browser testing for 200% zoom and WCAG text spacing tolerance.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>Text scales and spaces without content loss</h4>
							<p>Layouts tolerate 200% text magnification and WCAG 1.4.12 spacing without clipping or text obstruction.</p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     9. FOCUS VISIBILITY
			     ============================================================ -->
			<div class="accessai-section" data-category="focus">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-focus">
							<span class="dashicons dashicons-visibility"></span>
						</div>
						<div>
							<h2>9. Focus Visibility &amp; Outline Indicators</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 2.4.7 Focus Visible (Level AA)</p>
						</div>
					</div>
					<div>
						<?php if ( ! empty( $focus_results ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $focus_results ) ); ?> Focus Defects</span>
						<?php elseif ( ! $contrast_scan_completed ) : ?>
							<span class="accessai-chip-pill accessai-chip-neutral">Pending Live Scan</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ Focus Rings Present</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! empty( $focus_results ) ) : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-focus-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $focus_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Issue</th>
									<th>Detection Status</th>
									<th>Interactive Element</th>
									<th>Focus Indicator State</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $focus_results as $f_issue ) :
									$f_status_class = ( ( $f_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: 'accessai-status-potential';
									$fp = md5( 'focus|' . ( $f_issue['type'] ?? '' ) . '|' . ( $f_issue['element'] ?? '' ) . '|' . ( $f_issue['target_text'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $f_issue, 'focus' );
									$search_text = strtolower( ( $f_issue['type'] ?? '' ) . ' ' . ( $f_issue['element'] ?? '' ) . ' ' . ( $f_issue['target_text'] ?? '' ) . ' 2.4.7 Focus Visible ' . ( $f_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="focus"
										data-status="<?php echo esc_attr( $f_issue['status'] ?? 'Verified Failure' ); ?>"
										data-wcag="2.4.7"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $f_issue['type'] ?? 'Focus Finding' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $f_status_class ); ?>"><?php echo esc_html( $f_issue['status'] ?? 'Verified Failure' ); ?></span></td>
										<td class="accessai-wrap-col">
											<code><?php echo esc_html( $f_issue['element'] ?? '' ); ?></code>
											<div class="accessai-subcontext"><?php echo esc_html( $f_issue['target_text'] ?? '' ); ?></div>
										</td>
										<td><span class="accessai-badge accessai-badge-warn"><?php echo esc_html( $f_issue['focus_style'] ?? 'outline: none' ); ?></span></td>
										<td class="accessai-ai-cell">
											<span class="accessai-spinner" aria-label="Loading AI explanation"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php elseif ( ! $contrast_scan_completed ) : ?>
					<div class="accessai-empty-state accessai-empty-neutral">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-info-outline"></span></div>
						<div class="accessai-empty-text">
							<h4>Live computed focus inspection pending</h4>
							<p>Click <strong>Scan Page (10 Checkpoints)</strong> to evaluate computed focus styles and outline suppression across all interactive controls.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>Visible keyboard focus indicators verified</h4>
							<p>Interactive elements display clear, visible outlines or distinct focus rings when navigating by keyboard.</p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============================================================
			     10. TABLE ACCESSIBILITY
			     ============================================================ -->
			<div class="accessai-section" data-category="tables">
				<div class="accessai-section-header">
					<div class="accessai-section-title-wrap">
						<div class="accessai-section-icon-chip accessai-icon-tables">
							<span class="dashicons dashicons-editor-table"></span>
						</div>
						<div>
							<h2>10. Data Table Structure &amp; Relationships</h2>
							<p class="accessai-wcag-ref">WCAG 2.1 Guideline 1.3.1 Info and Relationships (Level A)</p>
						</div>
					</div>
					<div>
						<?php if ( empty( $table_issues ) ) : ?>
							<span class="accessai-chip-pill accessai-chip-pass">✓ Semantic Tables</span>
						<?php else : ?>
							<span class="accessai-chip-pill accessai-chip-warn"><?php echo esc_html( count( $table_issues ) ); ?> Table Defects</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $table_issues ) ) : ?>
					<div class="accessai-empty-state accessai-empty-pass">
						<div class="accessai-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="accessai-empty-text">
							<h4>All tables properly structured</h4>
							<p>Data tables feature appropriate header cells (<code>&lt;th&gt;</code>) and header scope associations.</p>
						</div>
					</div>
				<?php else : ?>
					<div class="accessai-table-card">
						<table class="accessai-table" id="accessai-table-table"
							   data-issues="<?php echo esc_attr( wp_json_encode( $table_issues_json ) ); ?>">
							<thead>
								<tr>
									<th>Issue</th>
									<th>Detection Status</th>
									<th>Table Element</th>
									<th>Description</th>
									<th class="accessai-ai-col">AI Explanation &amp; Fix</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $table_issues as $t_issue ) :
									$t_status_class = ( ( $t_issue['status'] ?? '' ) === 'Verified Failure' )
										? 'accessai-status-failure'
										: ( ( $t_issue['status'] ?? '' ) === 'Potential Issue' ? 'accessai-status-potential' : 'accessai-status-manual' );
									$fp = md5( 'tables|' . ( $t_issue['type'] ?? '' ) . '|' . ( $t_issue['element'] ?? '' ) . '|' . ( $t_issue['table_summary'] ?? '' ) );
									$p_calc = accessai_calculate_issue_priority( $t_issue, 'tables' );
									$search_text = strtolower( ( $t_issue['type'] ?? '' ) . ' ' . strip_tags( $t_issue['element'] ?? '' ) . ' ' . ( $t_issue['table_summary'] ?? '' ) . ' ' . ( $t_issue['message'] ?? '' ) . ' 1.3.1 Table Accessibility ' . ( $t_issue['status'] ?? '' ) . ' ' . $p_calc['priority_label'] );
								?>
									<tr data-fingerprint="<?php echo esc_attr( $fp ); ?>"
										data-priority="<?php echo esc_attr( $p_calc['priority'] ); ?>"
										data-priority-weight="<?php echo esc_attr( $p_calc['priority_weight'] ); ?>"
										data-category="tables"
										data-status="<?php echo esc_attr( $t_issue['status'] ?? 'Verified Failure' ); ?>"
										data-wcag="1.3.1"
										data-url="<?php echo esc_url( get_permalink( $active_page_id ) ); ?>"
										data-search-text="<?php echo esc_attr( $search_text ); ?>">
										<td>
											<span class="accessai-priority-badge is-<?php echo esc_attr( $p_calc['priority'] ); ?>">
												<span class="dashicons <?php echo $p_calc['priority'] === 'critical' ? 'dashicons-dismiss' : ( $p_calc['priority'] === 'high' ? 'dashicons-warning' : ( $p_calc['priority'] === 'medium' ? 'dashicons-info' : 'dashicons-lightbulb' ) ); ?>"></span>
												<span><?php echo esc_html( $p_calc['priority_label'] ); ?></span>
											</span>
											<strong><?php echo esc_html( $t_issue['type'] ?? 'Table Finding' ); ?></strong>
										</td>
										<td><span class="accessai-status-badge <?php echo esc_attr( $t_status_class ); ?>"><?php echo esc_html( $t_issue['status'] ?? 'Verified Failure' ); ?></span></td>
										<td class="accessai-wrap-col">
											<?php echo wp_kses_post( $t_issue['element'] ?? '' ); ?>
											<?php if ( ! empty( $t_issue['table_summary'] ) ) : ?>
												<div class="accessai-subcontext"><?php echo esc_html( $t_issue['table_summary'] ); ?></div>
											<?php endif; ?>
										</td>
										<td class="accessai-wrap-col"><?php echo esc_html( $t_issue['message'] ?? '' ); ?></td>
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

			</div><!-- #accessai-category-view -->

		<?php endif; ?>

	</div><!-- .accessai-wrap -->
	<?php
}

/**
 * Build issue data arrays suitable for auto-explain JS consumption across all 10 categories.
 * Each item receives a unique deterministic MD5 fingerprint and salient details string.
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

			case 'keyboard':
				$details = 'Keyboard Finding: ' . ( $issue['type'] ?? '' ) .
					'. Element: ' . strip_tags( $issue['element'] ?? '' ) .
					'. Context: ' . ( $issue['context'] ?? '' ) .
					'. Message: ' . ( $issue['message'] ?? '' );
				$fp_key  = 'keyboard|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['context'] ?? '' );
				break;

			case 'links':
				$details = 'Link Finding: ' . ( $issue['type'] ?? '' ) .
					'. Link text: ' . ( $issue['link_text'] ?? '' ) .
					'. Destination URL: ' . ( $issue['link_url'] ?? '' ) .
					'. Message: ' . ( $issue['message'] ?? '' );
				$fp_key  = 'links|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['link_url'] ?? '' ) . '|' . ( $issue['link_text'] ?? '' );
				break;

			case 'buttons':
				$details = 'Button Finding: ' . ( $issue['type'] ?? '' ) .
					'. Button: ' . strip_tags( $issue['element'] ?? '' ) .
					'. Text: ' . ( $issue['button_text'] ?? '' ) .
					'. Message: ' . ( $issue['message'] ?? '' );
				$fp_key  = 'buttons|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['button_text'] ?? '' );
				break;

			case 'text_spacing':
				$details = 'Text Spacing Finding: ' . ( $issue['type'] ?? '' ) .
					'. Element: ' . ( $issue['element'] ?? '' ) .
					'. Sample: ' . ( $issue['sample'] ?? '' ) .
					'. Metrics: ' . ( $issue['metrics'] ?? '' );
				$fp_key  = 'text_spacing|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['sample'] ?? '' );
				break;

			case 'focus':
				$details = 'Focus Visibility Finding: ' . ( $issue['type'] ?? '' ) .
					'. Element: ' . ( $issue['element'] ?? '' ) .
					'. Target: ' . ( $issue['target_text'] ?? '' ) .
					'. Style: ' . ( $issue['focus_style'] ?? '' );
				$fp_key  = 'focus|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['target_text'] ?? '' );
				break;

			case 'tables':
				$details = 'Table Finding: ' . ( $issue['type'] ?? '' ) .
					'. Element: ' . strip_tags( $issue['element'] ?? '' ) .
					'. Summary: ' . ( $issue['table_summary'] ?? '' ) .
					'. Message: ' . ( $issue['message'] ?? '' );
				$fp_key  = 'tables|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['table_summary'] ?? '' );
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
 * AJAX: Save browser audit scan results sent from the scan iframe across checkpoints.
 * Handles both legacy flat arrays and multi-module payload dictionaries.
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
		wp_send_json_error( array( 'message' => 'Invalid scan results payload.' ), 400 );
	}

	$user_id = get_current_user_id();

	// Check if this is the multi-module payload (contrast, focus, text_spacing, keyboard)
	if ( isset( $results['contrast'] ) || isset( $results['focus'] ) || isset( $results['text_spacing'] ) || isset( $results['keyboard'] ) ) {
		$safe_contrast = array();
		if ( ! empty( $results['contrast'] ) && is_array( $results['contrast'] ) ) {
			foreach ( array_slice( $results['contrast'], 0, 100 ) as $c ) {
				if ( is_array( $c ) ) {
					$safe_contrast[] = array(
						'element'  => sanitize_text_field( $c['element'] ?? '' ),
						'text'     => sanitize_text_field( $c['text'] ?? '' ),
						'ratio'    => sanitize_text_field( $c['ratio'] ?? '' ),
						'required' => sanitize_text_field( $c['required'] ?? '' ),
					);
				}
			}
		}

		$safe_focus = array();
		if ( ! empty( $results['focus'] ) && is_array( $results['focus'] ) ) {
			foreach ( array_slice( $results['focus'], 0, 50 ) as $f ) {
				if ( is_array( $f ) ) {
					$safe_focus[] = array(
						'type'        => sanitize_text_field( $f['type'] ?? 'Focus Finding' ),
						'status'      => sanitize_text_field( $f['status'] ?? 'Verified Failure' ),
						'severity'    => sanitize_text_field( $f['severity'] ?? 'High' ),
						'element'     => sanitize_text_field( $f['element'] ?? '' ),
						'target_text' => sanitize_text_field( $f['target_text'] ?? '' ),
						'focus_style' => sanitize_text_field( $f['focus_style'] ?? '' ),
						'message'     => sanitize_text_field( $f['message'] ?? '' ),
						'remediation' => sanitize_text_field( $f['remediation'] ?? '' ),
					);
				}
			}
		}

		$safe_text_spacing = array();
		if ( ! empty( $results['text_spacing'] ) && is_array( $results['text_spacing'] ) ) {
			foreach ( array_slice( $results['text_spacing'], 0, 50 ) as $ts ) {
				if ( is_array( $ts ) ) {
					$safe_text_spacing[] = array(
						'type'        => sanitize_text_field( $ts['type'] ?? 'Text Spacing Finding' ),
						'status'      => sanitize_text_field( $ts['status'] ?? 'Verified Failure' ),
						'severity'    => sanitize_text_field( $ts['severity'] ?? 'High' ),
						'element'     => sanitize_text_field( $ts['element'] ?? '' ),
						'sample'      => sanitize_text_field( $ts['sample'] ?? '' ),
						'metrics'     => sanitize_text_field( $ts['metrics'] ?? '' ),
						'message'     => sanitize_text_field( $ts['message'] ?? '' ),
						'remediation' => sanitize_text_field( $ts['remediation'] ?? '' ),
					);
				}
			}
		}

		$safe_keyboard = array();
		if ( ! empty( $results['keyboard'] ) && is_array( $results['keyboard'] ) ) {
			foreach ( array_slice( $results['keyboard'], 0, 50 ) as $kb ) {
				if ( is_array( $kb ) ) {
					$safe_keyboard[] = array(
						'type'        => sanitize_text_field( $kb['type'] ?? 'Keyboard Finding' ),
						'status'      => sanitize_text_field( $kb['status'] ?? 'Verified Failure' ),
						'severity'    => sanitize_text_field( $kb['severity'] ?? 'High' ),
						'element'     => sanitize_text_field( $kb['element'] ?? '' ),
						'context'     => sanitize_text_field( $kb['context'] ?? '' ),
						'message'     => sanitize_text_field( $kb['message'] ?? '' ),
						'remediation' => sanitize_text_field( $kb['remediation'] ?? '' ),
					);
				}
			}
		}

		$safe_buttons = array();
		if ( ! empty( $results['buttons'] ) && is_array( $results['buttons'] ) ) {
			foreach ( array_slice( $results['buttons'], 0, 50 ) as $b ) {
				if ( is_array( $b ) ) {
					$safe_buttons[] = array(
						'type'        => sanitize_text_field( $b['type'] ?? 'Button Without Accessible Name' ),
						'status'      => sanitize_text_field( $b['status'] ?? 'Verified Failure' ),
						'severity'    => sanitize_text_field( $b['severity'] ?? 'High' ),
						'element'     => sanitize_text_field( $b['element'] ?? '' ),
						'selector'    => sanitize_text_field( $b['selector'] ?? '' ),
						'button_text' => sanitize_text_field( $b['button_text'] ?? '(empty)' ),
						'message'     => sanitize_text_field( $b['message'] ?? '' ),
						'remediation' => sanitize_text_field( $b['remediation'] ?? '' ),
					);
				}
			}
		}

		set_transient( 'accessai_contrast_results_' . $user_id, $safe_contrast, HOUR_IN_SECONDS );
		set_transient( 'accessai_focus_results_' . $user_id, $safe_focus, HOUR_IN_SECONDS );
		set_transient( 'accessai_text_spacing_results_' . $user_id, $safe_text_spacing, HOUR_IN_SECONDS );
		set_transient( 'accessai_browser_keyboard_results_' . $user_id, $safe_keyboard, HOUR_IN_SECONDS );
		set_transient( 'accessai_browser_button_results_' . $user_id, $safe_buttons, HOUR_IN_SECONDS );
		set_transient( 'accessai_contrast_scan_completed_' . $user_id, true, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => 'All browser audit results received.',
				'counts'  => array(
					'contrast'     => count( $safe_contrast ),
					'focus'        => count( $safe_focus ),
					'text_spacing' => count( $safe_text_spacing ),
					'keyboard'     => count( $safe_keyboard ),
					'buttons'      => count( $safe_buttons ),
				),
			)
		);
	}

	// Legacy fallback: single list of contrast results
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
 * AJAX: Clear all stored audit scan results for the current user across checkpoints.
 */
add_action( 'wp_ajax_accessai_clear_contrast_results', 'accessai_clear_contrast_results' );

function accessai_clear_contrast_results() {
	check_ajax_referer( 'accessai_contrast_trigger', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	$user_id = get_current_user_id();

	delete_transient( 'accessai_contrast_results_' . $user_id );
	delete_transient( 'accessai_focus_results_' . $user_id );
	delete_transient( 'accessai_text_spacing_results_' . $user_id );
	delete_transient( 'accessai_browser_keyboard_results_' . $user_id );
	delete_transient( 'accessai_browser_button_results_' . $user_id );
	delete_transient( 'accessai_contrast_scan_completed_' . $user_id );
	delete_user_meta( $user_id, '_accessai_scanned_page_id' );

	wp_send_json_success( array( 'message' => 'All audit results cleared.' ) );
}
