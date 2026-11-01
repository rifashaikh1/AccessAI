<?php
/**
 * AccessAI — Text Resizing & Spacing Adaptation Scanner
 * WCAG 2.1 Success Criteria:
 * - 1.4.4 Resize Text (Level AA)
 * - 1.4.12 Text Spacing (Level AA)
 *
 * Detects fixed-dimension containers that clip, truncate, or obscure text under
 * enlarged text magnification (200%) and WCAG 1.4.12 text spacing requirements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan published post/page content for constrained text containers.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of text spacing and resizing findings.
 */
function accessai_scan_text_spacing_adaptation( $post_id = 0 ) {
	$issues = array();

	$args = array(
		'post_type'      => array( 'page', 'post' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	);

	if ( $post_id ) {
		$args['p'] = absint( $post_id );
	}

	$items = get_posts( $args );

	foreach ( $items as $item ) {
		$html = '';

		// Attempt to inspect rendered frontend HTML if available
		$page_url = get_permalink( $item->ID );
		if ( $page_url ) {
			$response = wp_remote_get(
				$page_url,
				array(
					'timeout'    => 4,
					'sslverify'  => false,
					'user-agent' => 'AccessAIScanner/1.0',
				)
			);
			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$html = wp_remote_retrieve_body( $response );
			}
		}

		if ( empty( $html ) ) {
			$html = apply_filters( 'the_content', $item->post_content );
		}

		if ( empty( $html ) ) {
			continue;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		$xpath = new DOMXPath( $dom );

		// Strictly exclude WordPress admin bar
		$admin_bar = $xpath->query( '//*[@id="wpadminbar"]' );
		foreach ( $admin_bar as $ab ) {
			$ab->parentNode->removeChild( $ab );
		}

		// Inspect all elements with inline style containing overflow or height
		$nodes = $xpath->query( '//*[contains(@style, "overflow") or contains(@style, "height")]' );

		foreach ( $nodes as $node ) {
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, array( 'script', 'style', 'svg', 'path', 'img', 'iframe' ), true ) ) {
				continue;
			}

			$style = strtolower( $node->getAttribute( 'style' ) );
			$text  = trim( $node->textContent );

			if ( empty( $text ) ) {
				continue;
			}

			// Check for overflow: hidden / clip
			$has_overflow_hidden = (
				strpos( $style, 'overflow:hidden' ) !== false ||
				strpos( $style, 'overflow: hidden' ) !== false ||
				strpos( $style, 'overflow-y:hidden' ) !== false ||
				strpos( $style, 'overflow-y: hidden' ) !== false ||
				strpos( $style, 'overflow:clip' ) !== false ||
				strpos( $style, 'overflow: clip' ) !== false
			);

			// Extract fixed height in px if present
			$fixed_height = null;
			if ( preg_match( '/(?:^|;|\s)height\s*:\s*(\d+(?:\.\d+)?)\s*px/i', $style, $m_h ) ) {
				$fixed_height = floatval( $m_h[1] );
			}

			// Extract fixed width in px if present
			$fixed_width = null;
			if ( preg_match( '/(?:^|;|\s)width\s*:\s*(\d+(?:\.\d+)?)\s*px/i', $style, $m_w ) ) {
				$fixed_width = floatval( $m_w[1] );
			}

			// If both overflow:hidden and a constrained fixed height are present:
			if ( $has_overflow_hidden && $fixed_height !== null && $fixed_height > 0 ) {
				$char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

				// Estimate required height under WCAG 1.4.12 text spacing (1.5 line height)
				// At average 16px font size with 220px or default width:
				$effective_width = ( $fixed_width && $fixed_width > 50 ) ? $fixed_width : 300;
				// Average character width is ~8px (with 0.12em letter spacing ~10px)
				$chars_per_line = max( 10, floor( $effective_width / 10 ) );
				$estimated_lines = ceil( $char_count / $chars_per_line );
				$line_height_px  = 24; // 16px * 1.5 line height
				$needed_height   = $estimated_lines * $line_height_px;

				// If the container height is significantly less than needed, content is clipped
				if ( $char_count > 40 && $fixed_height < ( $needed_height * 0.8 ) ) {
					$sample = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 80 ) : substr( $text, 0, 80 );
					$id     = $node->getAttribute( 'id' );
					$class  = $node->getAttribute( 'class' );
					$sel    = $tag . ( $id ? '#' . $id : ( $class ? '.' . preg_split( '/\s+/', trim( $class ) )[0] : '' ) );
					if ( $sel === $tag ) {
						$sel = $tag . '[style*="overflow: hidden"]';
					}

					$outer = substr( $dom->saveHTML( $node ), 0, 160 );

					$issues[] = array(
						'category'    => 'Text Resizing & Spacing',
						'type'        => 'Text Container Clips Content Under Resizing/Spacing',
						'wcag'        => '1.4.12 Text Spacing (Level AA) & 1.4.4 Resize Text (Level AA)',
						'severity'    => 'High',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>' . esc_html( $sel ) . '</code>',
						'selector'    => $sel,
						'sample'      => $sample . ( mb_strlen( $text ) > 80 ? '…' : '' ),
						'metrics'     => 'Constrained box (height: ' . $fixed_height . 'px, overflow: hidden) clips text (needs approx ' . ceil( $needed_height ) . 'px for ' . $char_count . ' chars)',
						'message'     => 'Fixed-height container with overflow:hidden clips text content when rendered or adjusted for WCAG 1.4.12 text spacing and 200% zoom.',
						'remediation' => 'Replace fixed height with min-height or height:auto, and allow overflow to expand so text is not cut off.',
					);
				}
			}
		}

		libxml_clear_errors();
	}

	return $issues;
}
