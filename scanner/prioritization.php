<?php
/**
 * AccessAI — Smart Issue Prioritization & Normalization Engine
 *
 * Assigns deterministic priority levels (Critical, High, Medium, Low) based on:
 * 1. Severity of the accessibility barrier.
 * 2. User impact on individuals with disabilities.
 * 3. Whether essential content or functionality is blocked.
 * 4. Repeated components and scope.
 * 5. Detection confidence (uncertain/potential issues are never Critical).
 *
 * Deterministic and consistent — does NOT rely on external AI for priority.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return registry of all 10 core WCAG scanner modules.
 *
 * @return array
 */
function accessai_get_registered_scanners() {
	return array(
		'buttons'           => array(
			'id'          => 'buttons',
			'label'       => 'Button Accessibility',
			'wcag'        => 'WCAG 2.1 4.1.2 Name, Role, Value (Level A)',
			'wcag_num'    => '4.1.2',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-marker',
			'table_id'    => 'accessai-button-table',
		),
		'keyboard'          => array(
			'id'          => 'keyboard',
			'label'       => 'Keyboard Accessibility',
			'wcag'        => 'WCAG 2.1 2.1.1 Keyboard & 2.1.2 No Keyboard Trap (Level A)',
			'wcag_num'    => '2.1.1',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-keyboard',
			'table_id'    => 'accessai-keyboard-table',
		),
		'form_label'        => array(
			'id'          => 'form_label',
			'label'       => 'Form Labels',
			'wcag'        => 'WCAG 2.1 3.3.2 Labels or Instructions (Level A)',
			'wcag_num'    => '3.3.2',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-feedback',
			'table_id'    => 'accessai-form-table',
		),
		'color_contrast'    => array(
			'id'          => 'color_contrast',
			'label'       => 'Colour Contrast',
			'wcag'        => 'WCAG 2.1 1.4.3 Contrast Minimum (Level AA)',
			'wcag_num'    => '1.4.3',
			'wcag_level'  => 'Level AA',
			'icon'        => 'dashicons-art',
			'table_id'    => 'accessai-contrast-table',
		),
		'alt_text'          => array(
			'id'          => 'alt_text',
			'label'       => 'Image Alternative Text',
			'wcag'        => 'WCAG 2.1 1.1.1 Non-text Content (Level A)',
			'wcag_num'    => '1.1.1',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-format-image',
			'table_id'    => 'accessai-alt-table',
		),
		'links'             => array(
			'id'          => 'links',
			'label'       => 'Link Accessibility',
			'wcag'        => 'WCAG 2.1 2.4.4 Link Purpose (In Context) (Level A)',
			'wcag_num'    => '2.4.4',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-admin-links',
			'table_id'    => 'accessai-link-table',
		),
		'focus'             => array(
			'id'          => 'focus',
			'label'       => 'Focus Visibility',
			'wcag'        => 'WCAG 2.1 2.4.7 Focus Visible (Level AA)',
			'wcag_num'    => '2.4.7',
			'wcag_level'  => 'Level AA',
			'icon'        => 'dashicons-visibility',
			'table_id'    => 'accessai-focus-table',
		),
		'text_spacing'      => array(
			'id'          => 'text_spacing',
			'label'       => 'Text Resizing & Spacing',
			'wcag'        => 'WCAG 2.1 1.4.4 Resize Text & 1.4.12 Text Spacing (Level AA)',
			'wcag_num'    => '1.4.4',
			'wcag_level'  => 'Level AA',
			'icon'        => 'dashicons-editor-textcolor',
			'table_id'    => 'accessai-text-spacing-table',
		),
		'tables'            => array(
			'id'          => 'tables',
			'label'       => 'Table Accessibility',
			'wcag'        => 'WCAG 2.1 1.3.1 Info and Relationships (Level A)',
			'wcag_num'    => '1.3.1',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-editor-table',
			'table_id'    => 'accessai-table-table',
		),
		'heading_structure' => array(
			'id'          => 'heading_structure',
			'label'       => 'Heading Hierarchy',
			'wcag'        => 'WCAG 2.1 1.3.1 Info and Relationships (Level A)',
			'wcag_num'    => '1.3.1',
			'wcag_level'  => 'Level A',
			'icon'        => 'dashicons-editor-ol',
			'table_id'    => 'accessai-heading-table',
		),
	);
}

/**
 * Deterministically calculate issue priority.
 *
 * Priority Levels:
 * - Critical: Severe barrier blocking essential interaction/navigation (keyboard trap, essential unlabeled button, missing alt on linked navigation image). Must be a verified failure.
 * - High: Significant barrier that should be addressed soon (severe contrast < 3.0:1, missing form label, empty link, missing alt on content image, focus outline suppressed).
 * - Medium: Moderate barrier or improvement with more limited impact (contrast 3.0:1–4.49:1, skipped heading level, ambiguous link text, unverified potential issues).
 * - Low: Minor formatting or advisory recommendation (table missing summary, decorative image check, minor spacing warning).
 *
 * Confidence Rule: If detection status is 'Potential Issue' or 'Manual Review Required',
 * priority MUST NOT be 'Critical'; it is capped at 'Medium' or 'Low' and flagged as provisional.
 *
 * @param array  $issue       The raw issue data array.
 * @param string $category_id Scanner category key.
 * @return array Array with 'priority', 'priority_label', 'priority_weight', 'is_provisional'.
 */
function accessai_calculate_issue_priority( $issue, $category_id ) {
	// Normalize category ID aliases
	$cat_aliases = array(
		'button_accessibility' => 'buttons',
		'link_accessibility'   => 'links',
		'table_accessibility'  => 'tables',
		'focus_visible'        => 'focus',
		'heading_hierarchy'    => 'heading_structure',
	);
	if ( isset( $cat_aliases[ $category_id ] ) ) {
		$category_id = $cat_aliases[ $category_id ];
	}

	$status = isset( $issue['status'] ) ? trim( (string) $issue['status'] ) : '';

	// Default fallback status per category if not explicitly set
	if ( $status === '' ) {
		switch ( $category_id ) {
			case 'heading_structure':
				$status = 'Potential Issue';
				break;
			case 'alt_text':
			case 'form_label':
			case 'color_contrast':
				$status = 'Verified Failure';
				break;
			default:
				$status = 'Verified Failure';
				break;
		}
	}

	$is_uncertain = in_array(
		$status,
		array( 'Potential Issue', 'Manual Review Required', 'Not Tested', 'Uncertain' ),
		true
	);

	$type    = isset( $issue['type'] ) ? (string) $issue['type'] : '';
	$message = isset( $issue['message'] ) ? (string) $issue['message'] : '';
	$element = isset( $issue['element'] ) ? (string) $issue['element'] : '';

	$priority = 'Medium'; // Default baseline

	switch ( $category_id ) {
		/* --------------------------------------------------------------
		   1. Keyboard Accessibility
		   -------------------------------------------------------------- */
		case 'keyboard':
			if ( stripos( $type, 'Trap' ) !== false || stripos( $message, 'trap' ) !== false ) {
				// Keyboard traps completely strand keyboard-only users
				$priority = 'Critical';
			} elseif ( stripos( $type, 'Positive Tabindex' ) !== false || stripos( $element, 'tabindex' ) !== false ) {
				// Positive tabindex disrupts reading and tab sequence across entire page
				$priority = 'High';
			} elseif ( stripos( $type, 'Inaccessible via Keyboard' ) !== false || stripos( $message, 'onclick' ) !== false ) {
				// Click handlers on non-interactive elements without keyboard support
				$priority = 'Medium';
			} else {
				$priority = 'High';
			}
			break;

		/* --------------------------------------------------------------
		   2. Button Accessibility
		   -------------------------------------------------------------- */
		case 'buttons':
			if (
				stripos( $type, 'Without Accessible Name' ) !== false ||
				stripos( $type, 'Empty' ) !== false ||
				stripos( $type, 'Missing Name' ) !== false
			) {
				// Completely unlabeled interactive button with no accessible text blocks users
				$priority = 'Critical';
			} elseif ( stripos( $type, 'Suspicious' ) !== false || stripos( $type, 'Generic' ) !== false ) {
				$priority = 'Medium';
			} elseif ( stripos( $type, 'Nested' ) !== false ) {
				$priority = 'High';
			} else {
				$priority = 'Medium';
			}
			break;

		/* --------------------------------------------------------------
		   3. Form Labels
		   -------------------------------------------------------------- */
		case 'form_label':
			$control    = isset( $issue['control'] ) ? strtolower( (string) $issue['control'] ) : '';
			$field_type = isset( $issue['field_type'] ) ? strtolower( (string) $issue['field_type'] ) : '';

			// Essential interactive input fields missing labels create severe barrier
			if ( in_array( $field_type, array( 'text', 'email', 'password', 'tel', 'number', 'search' ), true ) ||
				 in_array( $control, array( 'select', 'textarea', 'input' ), true ) ||
				 stripos( $type, 'Missing' ) !== false ) {
				$priority = 'Critical';
			} else {
				$priority = 'High';
			}
			break;

		/* --------------------------------------------------------------
		   4. Colour Contrast
		   -------------------------------------------------------------- */
		case 'color_contrast':
			$ratio_val = 0.0;
			if ( isset( $issue['ratio'] ) ) {
				if ( is_numeric( $issue['ratio'] ) ) {
					$ratio_val = (float) $issue['ratio'];
				} elseif ( preg_match( '/^([\d.]+)/', (string) $issue['ratio'], $m ) ) {
					$ratio_val = (float) $m[1];
				}
			}

			// Severe contrast failure (< 3.0:1) significantly degrades readability
			if ( $ratio_val > 0.0 && $ratio_val < 3.0 ) {
				$priority = 'Critical';
			} elseif ( $ratio_val >= 3.0 && $ratio_val < 4.5 ) {
				$priority = 'High';
			} else {
				$priority = 'High';
			}
			break;

		/* --------------------------------------------------------------
		   5. Image Alternative Text
		   -------------------------------------------------------------- */
		case 'alt_text':
			$src = isset( $issue['image_src'] ) ? (string) $issue['image_src'] : '';
			$msg = isset( $issue['message'] ) ? (string) $issue['message'] : '';

			// Check if this image acts as a link or primary content
			if ( stripos( $msg, 'linked' ) !== false || stripos( $type, 'Linked' ) !== false ) {
				$priority = 'Critical'; // Functional link with no name
			} elseif ( stripos( $type, 'Missing Alt' ) !== false ) {
				$priority = 'High'; // Informative image missing alt attribute
			} elseif ( stripos( $type, 'Empty Alt' ) !== false ) {
				$priority = 'Medium'; // May be decorative or informative; requires verification
			} else {
				$priority = 'High';
			}
			break;

		/* --------------------------------------------------------------
		   6. Link Accessibility
		   -------------------------------------------------------------- */
		case 'links':
			if (
				stripos( $type, 'Without Accessible Name' ) !== false ||
				stripos( $type, 'Empty Link' ) !== false
			) {
				// Anchor element with no accessible name blocks navigation
				$priority = 'High';
			} elseif ( stripos( $type, 'Ambiguous' ) !== false || stripos( $type, 'Vague' ) !== false ) {
				// "click here", "read more"
				$priority = 'Medium';
			} elseif ( stripos( $type, 'Action' ) !== false || stripos( $type, 'href="#"' ) !== false ) {
				// Link used for button action
				$priority = 'Medium';
			} elseif ( stripos( $type, 'New Window' ) !== false ) {
				$priority = 'Low';
			} else {
				$priority = 'Medium';
			}
			break;

		/* --------------------------------------------------------------
		   7. Focus Visibility
		   -------------------------------------------------------------- */
		case 'focus':
			$style = isset( $issue['focus_style'] ) ? (string) $issue['focus_style'] : '';
			if (
				stripos( $style, 'none' ) !== false ||
				stripos( $style, 'outline: 0' ) !== false ||
				stripos( $type, 'Suppressed' ) !== false ||
				stripos( $type, 'Removed' ) !== false
			) {
				// Keyboard users lose all visual context on current location
				$priority = 'High';
			} else {
				$priority = 'Medium';
			}
			break;

		/* --------------------------------------------------------------
		   8. Text Resizing & Spacing
		   -------------------------------------------------------------- */
		case 'text_spacing':
			if ( stripos( $type, 'Clipping' ) !== false || stripos( $message, 'clipped' ) !== false || stripos( $message, 'overflow' ) !== false ) {
				// Content is cut off or unreadable on 200% zoom or WCAG spacing
				$priority = 'High';
			} elseif ( stripos( $type, 'Overlap' ) !== false ) {
				$priority = 'High';
			} else {
				$priority = 'Medium';
			}
			break;

		/* --------------------------------------------------------------
		   9. Table Accessibility
		   -------------------------------------------------------------- */
		case 'tables':
			if ( stripos( $type, 'Missing Header' ) !== false || stripos( $message, 'header' ) !== false ) {
				// Data tables without <th> headers prevent understanding cell relationships
				$priority = 'High';
			} elseif ( stripos( $type, 'Scope' ) !== false || stripos( $message, 'scope' ) !== false ) {
				$priority = 'Medium';
			} elseif ( stripos( $type, 'Caption' ) !== false ) {
				$priority = 'Low';
			} else {
				$priority = 'Medium';
			}
			break;

		/* --------------------------------------------------------------
		   10. Heading Structure
		   -------------------------------------------------------------- */
		case 'heading_structure':
			$prev = isset( $issue['previous'] ) ? (string) $issue['previous'] : '';
			$curr = isset( $issue['current'] ) ? (string) $issue['current'] : '';

			// Skipped headings (e.g. H1 -> H3) have moderate navigational impact (WCAG 1.3.1)
			if ( stripos( $type, 'Skipped' ) !== false || stripos( $type, 'Missing H1' ) !== false || stripos( $type, 'Multiple H1' ) !== false ) {
				$priority = 'Medium';
			} elseif ( $prev !== '' && $curr !== '' ) {
				$priority = 'Medium';
			} else {
				$priority = 'Low';
			}
			break;

		default:
			$priority = 'Medium';
			break;
	}

	// MANDATORY CONFIDENCE RULE:
	// An unverified potential issue or manual review item must NEVER automatically receive Critical priority.
	// Cap uncertain issues at Medium or Low, and mark as provisional.
	if ( $is_uncertain ) {
		if ( $priority === 'Critical' ) {
			$priority = 'Medium';
		}
	}

	$weights = array(
		'Critical' => 4,
		'High'     => 3,
		'Medium'   => 2,
		'Low'      => 1,
	);

	$priority_key = strtolower( $priority );

	return array(
		'priority'        => $priority_key,
		'priority_label'  => $priority,
		'priority_weight' => $weights[ $priority ] ?? 2,
		'is_provisional'  => $is_uncertain,
		'status'          => $status,
	);
}

/**
 * Normalize an issue into a unified data structure across all 10 checkpoints.
 *
 * @param array        $issue       Raw finding.
 * @param string       $category_id Scanner category key.
 * @param WP_Post|null $post        Audited post object.
 * @param int          $index       Zero-based index within category.
 * @return array Normalized finding structure.
 */
function accessai_normalize_finding( $issue, $category_id, $post = null, $index = 0 ) {
	$scanners = accessai_get_registered_scanners();
	$scanner  = $scanners[ $category_id ] ?? array(
		'label'      => ucfirst( str_replace( '_', ' ', $category_id ) ),
		'wcag'       => 'WCAG 2.1 Conformance',
		'wcag_num'   => '2.1',
		'wcag_level' => 'Level A',
	);

	$post_id    = $post ? $post->ID : ( $issue['page_id'] ?? 0 );
	$post_title = $post ? $post->post_title : ( $issue['page_title'] ?? 'Audited Page' );
	$post_url   = $post_id ? get_permalink( $post_id ) : home_url();

	// Title / Type
	$title = isset( $issue['type'] ) ? trim( (string) $issue['type'] ) : '';
	if ( $title === '' ) {
		switch ( $category_id ) {
			case 'color_contrast':
				$title = 'Low Text Contrast Ratio';
				break;
			case 'alt_text':
				$title = 'Missing Image Alternative Text';
				break;
			case 'form_label':
				$title = 'Missing Form Control Label';
				break;
			default:
				$title = $scanner['label'] . ' Finding';
				break;
		}
	}

	// Status determination
	$status = isset( $issue['status'] ) ? trim( (string) $issue['status'] ) : '';
	if ( $status === '' ) {
		if ( $category_id === 'heading_structure' ) {
			$status = 'Potential Issue';
		} elseif ( in_array( $category_id, array( 'alt_text', 'form_label', 'color_contrast' ), true ) ) {
			$status = 'Verified Failure';
		} else {
			$status = 'Verified Failure';
		}
	}

	// Calculate deterministic priority
	$p_info = accessai_calculate_issue_priority( $issue, $category_id );

	// Build deterministic fingerprint
	switch ( $category_id ) {
		case 'alt_text':
			$fp_key = 'alt_text|' . $post_id . '|' . ( $issue['image_src'] ?? '' );
			break;
		case 'heading_structure':
			$fp_key = 'heading_structure|' . $post_id . '|' . ( $issue['previous'] ?? '' ) . '|' . ( $issue['current'] ?? '' ) . '|' . ( $issue['heading_text'] ?? '' );
			break;
		case 'form_label':
			$fp_key = 'form_label|' . $post_id . '|' . ( $issue['control'] ?? '' ) . '|' . ( $issue['field_name'] ?? '' );
			break;
		case 'color_contrast':
			$fp_key = 'color_contrast|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['text'] ?? '' ) . '|' . ( $issue['ratio'] ?? '' );
			break;
		case 'keyboard':
			$fp_key = 'keyboard|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['context'] ?? '' );
			break;
		case 'links':
			$fp_key = 'links|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['link_url'] ?? '' ) . '|' . ( $issue['link_text'] ?? '' );
			break;
		case 'buttons':
			$fp_key = 'buttons|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['button_text'] ?? '' );
			break;
		case 'text_spacing':
			$fp_key = 'text_spacing|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['sample'] ?? '' );
			break;
		case 'focus':
			$fp_key = 'focus|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['target_text'] ?? '' );
			break;
		case 'tables':
			$fp_key = 'tables|' . ( $issue['type'] ?? '' ) . '|' . ( $issue['element'] ?? '' ) . '|' . ( $issue['table_summary'] ?? '' );
			break;
		default:
			$fp_key = $category_id . '|' . $post_id . '|' . $index;
			break;
	}
	$fingerprint = md5( $fp_key );

	// Element representation
	$element_html = '';
	$element_text = '';

	switch ( $category_id ) {
		case 'alt_text':
			$src          = $issue['image_src'] ?? 'N/A';
			$element_html = '<code>' . esc_html( $src ) . '</code>';
			$element_text = $src;
			break;

		case 'heading_structure':
			$transition   = ( $issue['previous'] ?? 'H0' ) . ' → ' . ( $issue['current'] ?? 'H?' );
			$h_text       = $issue['heading_text'] ?? '(empty)';
			$element_html = '<span class="accessai-badge accessai-badge-warn">' . esc_html( $transition ) . '</span> <em>' . esc_html( $h_text ) . '</em>';
			$element_text = $transition . ' ' . $h_text;
			break;

		case 'form_label':
			$ctrl         = $issue['control'] ?? 'input';
			$ft           = $issue['field_type'] ?? '';
			$fn           = $issue['field_name'] ?? 'unspecified';
			$element_html = '<span class="accessai-tag">' . esc_html( $ctrl ) . '</span>' . ( $ft ? ' <small>(' . esc_html( $ft ) . ')</small>' : '' ) . ' <code>' . esc_html( $fn ) . '</code>';
			$element_text = $ctrl . ' ' . $ft . ' ' . $fn;
			break;

		case 'color_contrast':
			$el           = $issue['element'] ?? '';
			$txt          = $issue['text'] ?? '';
			$element_html = '<code>' . esc_html( $el ) . '</code> <span class="accessai-text-sample">“' . esc_html( $txt ) . '”</span>';
			$element_text = $el . ' ' . $txt;
			break;

		case 'links':
			$txt          = $issue['link_text'] ?? '(empty)';
			$url          = $issue['link_url'] ?? '';
			$element_html = '<code>' . esc_html( $txt ) . '</code> <span class="accessai-url-pill">' . esc_html( $url ) . '</span>';
			$element_text = $txt . ' ' . $url;
			break;

		case 'buttons':
			$el           = $issue['element'] ?? '<button>';
			$btn_txt      = $issue['button_text'] ?? '';
			$element_html = wp_kses_post( $el ) . ( $btn_txt ? ' <small class="accessai-subcontext">' . esc_html( $btn_txt ) . '</small>' : '' );
			$element_text = strip_tags( $el ) . ' ' . $btn_txt;
			break;

		case 'keyboard':
			$el           = $issue['element'] ?? '';
			$ctx          = $issue['context'] ?? '';
			$element_html = wp_kses_post( $el ) . ( $ctx ? '<div class="accessai-subcontext">' . esc_html( $ctx ) . '</div>' : '' );
			$element_text = strip_tags( $el ) . ' ' . $ctx;
			break;

		case 'text_spacing':
			$el           = $issue['element'] ?? '';
			$smp          = $issue['sample'] ?? '';
			$element_html = '<code>' . esc_html( $el ) . '</code> <em>' . esc_html( $smp ) . '</em>';
			$element_text = $el . ' ' . $smp;
			break;

		case 'focus':
			$el           = $issue['element'] ?? '';
			$tgt          = $issue['target_text'] ?? '';
			$element_html = '<code>' . esc_html( $el ) . '</code>' . ( $tgt ? '<div class="accessai-subcontext">' . esc_html( $tgt ) . '</div>' : '' );
			$element_text = $el . ' ' . $tgt;
			break;

		case 'tables':
			$el           = $issue['element'] ?? '<table>';
			$sm           = $issue['table_summary'] ?? '';
			$element_html = wp_kses_post( $el ) . ( $sm ? '<div class="accessai-subcontext">' . esc_html( $sm ) . '</div>' : '' );
			$element_text = strip_tags( $el ) . ' ' . $sm;
			break;

		default:
			$element_html = esc_html( $issue['element'] ?? '' );
			$element_text = strip_tags( $issue['element'] ?? '' );
			break;
	}

	// Short description
	$description = '';
	if ( ! empty( $issue['message'] ) ) {
		$description = (string) $issue['message'];
	} else {
		switch ( $category_id ) {
			case 'alt_text':
				$description = 'Image lacks descriptive alternative text for assistive technology users.';
				break;
			case 'heading_structure':
				$description = 'Heading levels skip intermediate ranks, disrupting logical reading order.';
				break;
			case 'form_label':
				$description = 'Form control has no associated <label> or aria-label describing its purpose.';
				break;
			case 'color_contrast':
				$ratio = $issue['ratio'] ?? 'unknown';
				$req   = $issue['required'] ?? '4.5:1';
				$description = 'Text contrast ratio is ' . $ratio . ', which does not meet the minimum ' . $req . ' requirement.';
				break;
			default:
				$description = $title;
				break;
		}
	}

	// Recommended remediation
	$remediation = '';
	if ( ! empty( $issue['remediation'] ) ) {
		$remediation = (string) $issue['remediation'];
	} else {
		switch ( $category_id ) {
			case 'alt_text':
				$remediation = 'Add a descriptive alt attribute describing the image or mark alt="" if decorative.';
				break;
			case 'heading_structure':
				$remediation = 'Adjust heading tags to follow sequential descending order (e.g. H1 → H2 → H3).';
				break;
			case 'form_label':
				$remediation = 'Add an explicit <label for="id"> or provide an aria-label attribute on the input.';
				break;
			case 'color_contrast':
				$remediation = 'Increase luminance contrast between foreground text and background to at least 4.5:1.';
				break;
			case 'keyboard':
				$remediation = 'Ensure interactive controls can be activated with Tab, Enter, and Spacebar without positive tabindex.';
				break;
			case 'links':
				$remediation = 'Provide meaningful anchor text that indicates destination out of context, or add aria-label.';
				break;
			case 'buttons':
				$remediation = 'Provide an accessible name using visible text, aria-label, or descriptive alt on nested icons.';
				break;
			case 'text_spacing':
				$remediation = 'Use flexible container sizing (min-height, relative units) to prevent text truncation.';
				break;
			case 'focus':
				$remediation = 'Provide a visible focus indicator with at least 3:1 contrast ratio when tabbing.';
				break;
			case 'tables':
				$remediation = 'Designate header cells using <th> with scope="col" or scope="row" attributes.';
				break;
			default:
				$remediation = 'Review the affected element and apply WCAG 2.1 Level AA conformance practices.';
				break;
		}
	}

	// Search blob: lowercase aggregation of title, description, element, URL, wcag
	$search_text = strtolower(
		$title . ' ' .
		$scanner['label'] . ' ' .
		$scanner['wcag'] . ' ' .
		$scanner['wcag_num'] . ' ' .
		$description . ' ' .
		$remediation . ' ' .
		$element_text . ' ' .
		$post_title . ' ' .
		$post_url . ' ' .
		$status . ' ' .
		$p_info['priority_label']
	);

	return array(
		'id'              => 'accessai-finding-' . $category_id . '-' . $index,
		'fingerprint'     => $fingerprint,
		'category_id'     => $category_id,
		'category_label'  => $scanner['label'],
		'title'           => $title,
		'priority'        => $p_info['priority'],
		'priority_label'  => $p_info['priority_label'],
		'priority_weight' => $p_info['priority_weight'],
		'is_provisional'  => $p_info['is_provisional'],
		'status'          => $status,
		'status_label'    => $status,
		'wcag'            => $scanner['wcag'],
		'wcag_criterion'  => $scanner['wcag_num'],
		'wcag_level'      => $scanner['wcag_level'],
		'element_html'    => $element_html,
		'element_text'    => $element_text,
		'description'     => $description,
		'remediation'     => $remediation,
		'page_id'         => $post_id,
		'page_title'      => $post_title,
		'page_url'        => $post_url,
		'search_text'     => $search_text,
		'raw'             => $issue,
	);
}
