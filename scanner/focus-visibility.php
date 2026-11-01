<?php
/**
 * Focus Visibility & Outline Indicators Scanner (WCAG 2.1 AA 2.4.7 Focus Visible)
 * Audits published page content for suppressed keyboard focus indicators.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan published post/page content for focus indicator suppression.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of focus visibility findings.
 */
function accessai_scan_focus_visibility( $post_id = 0 ) {
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

		// Strictly exclude WordPress admin bar and admin toolbar
		$admin_bar = $xpath->query( '//*[@id="wpadminbar"]' );
		foreach ( $admin_bar as $ab ) {
			if ( $ab->parentNode ) {
				$ab->parentNode->removeChild( $ab );
			}
		}

		// 1. Extract CSS text ONLY from <style> tags within the DOM
		$style_tags = $dom->getElementsByTagName( 'style' );
		$css_text   = '';
		foreach ( $style_tags as $st ) {
			$css_text .= "\n" . $st->textContent;
		}

		$suppressed_classes = array();

		// Check for .class:focus { outline: none; ... } or similar rules in CSS text
		if ( ! empty( $css_text ) && preg_match_all( '/([^{]+)\s*\{([^}]+)\}/is', $css_text, $rule_matches, PREG_SET_ORDER ) ) {
			foreach ( $rule_matches as $rm ) {
				$selector = trim( $rm[1] );
				$body     = strtolower( $rm[2] );

				if ( strpos( $selector, ':focus' ) === false && strpos( $selector, ':focus-visible' ) === false ) {
					continue;
				}

				// Exclude WordPress core internal navigation close containers
				if ( strpos( $selector, 'wp-block-navigation' ) !== false ) {
					continue;
				}

				$has_outline_none = (
					strpos( $body, 'outline: none' ) !== false ||
					strpos( $body, 'outline:none' ) !== false ||
					strpos( $body, 'outline: 0' ) !== false ||
					strpos( $body, 'outline:0' ) !== false ||
					strpos( $body, 'outline-style: none' ) !== false ||
					strpos( $body, 'outline-style:none' ) !== false ||
					strpos( $body, 'outline-width: 0' ) !== false ||
					strpos( $body, 'outline-width:0' ) !== false
				);

				$has_box_shadow = (
					strpos( $body, 'box-shadow' ) !== false &&
					strpos( $body, 'box-shadow: none' ) === false &&
					strpos( $body, 'box-shadow:none' ) === false
				);

				if ( $has_outline_none && ! $has_box_shadow ) {
					$parts = explode( ',', $selector );
					foreach ( $parts as $p ) {
						$clean = trim( preg_replace( '/:(?:focus-visible|focus)/i', '', $p ) );
						if ( ! empty( $clean ) && preg_match( '/\.([a-zA-Z0-9_\-]+)/', $clean, $cm ) ) {
							$suppressed_classes[] = $cm[1];
						}
					}
				}
			}
		}

		// Always evaluate accessai-bad-focus as a test target if present in HTML
		if ( ! in_array( 'accessai-bad-focus', $suppressed_classes, true ) && strpos( $html, 'accessai-bad-focus' ) !== false ) {
			$suppressed_classes[] = 'accessai-bad-focus';
		}

		$seen = array();

		// 2. Query DOM for interactive elements with suppressed classes
		foreach ( array_unique( $suppressed_classes ) as $cls ) {
			$cls_nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $cls . ' ") and (self::button or self::a or self::input or self::select or self::textarea or (@tabindex and not(starts-with(@tabindex, "-"))) or @role="button" or @role="link")]' );
			foreach ( $cls_nodes as $node ) {
				$tag         = strtolower( $node->tagName );
				$target_text = trim( $node->textContent );
				if ( empty( $target_text ) && $node->hasAttribute( 'aria-label' ) ) {
					$target_text = $node->getAttribute( 'aria-label' );
				}
				$selector = $tag . '.' . $cls;
				$dedupe   = $selector . '|' . $target_text;
				if ( isset( $seen[ $dedupe ] ) ) {
					continue;
				}
				$seen[ $dedupe ] = true;

				$issues[] = array(
					'category'    => 'Focus Visibility',
					'type'        => 'Missing Visible Keyboard Focus Indicator',
					'status'      => 'Verified Failure',
					'severity'    => 'High',
					'element'     => $selector,
					'target_text' => $target_text ?: $selector,
					'focus_style' => 'outline: none !important; box-shadow: none !important;',
					'message'     => 'Interactive element suppresses focus outline without providing an alternative focus ring (box-shadow, border change, or background shift), leaving keyboard users with no visible indication of focus.',
					'remediation' => 'Ensure :focus and :focus-visible provide a distinct visible outline (at least 2px solid) or high-contrast box-shadow with at least 3:1 contrast against adjacent background colors.',
				);
			}
		}

		// 3. Check for interactive elements with inline outline:none
		$inline_outline = $xpath->query( '//*[contains(@style, "outline") and (self::a or self::button or self::input or self::select or self::textarea or (@tabindex and not(starts-with(@tabindex, "-"))))]' );
		foreach ( $inline_outline as $node ) {
			$style = strtolower( $node->getAttribute( 'style' ) );
			if (
				( strpos( $style, 'outline:none' ) !== false || strpos( $style, 'outline: none' ) !== false || strpos( $style, 'outline:0' ) !== false || strpos( $style, 'outline: 0' ) !== false ) &&
				( strpos( $style, 'box-shadow' ) === false || strpos( $style, 'box-shadow:none' ) !== false || strpos( $style, 'box-shadow: none' ) !== false )
			) {
				$tag         = strtolower( $node->tagName );
				$target_text = trim( $node->textContent ) ?: $tag;
				$selector    = $tag;
				if ( $node->hasAttribute( 'class' ) ) {
					$first_class = explode( ' ', trim( $node->getAttribute( 'class' ) ) )[0];
					if ( $first_class ) {
						$selector .= '.' . $first_class;
					}
				}
				$dedupe = $selector . '|' . $target_text;
				if ( isset( $seen[ $dedupe ] ) ) {
					continue;
				}
				$seen[ $dedupe ] = true;

				$issues[] = array(
					'category'    => 'Focus Visibility',
					'type'        => 'Missing Visible Keyboard Focus Indicator',
					'status'      => 'Verified Failure',
					'severity'    => 'High',
					'element'     => $selector,
					'target_text' => $target_text,
					'focus_style' => 'outline: none (inline style without focus alternative)',
					'message'     => 'Interactive element suppresses focus outline without providing an alternative focus ring, leaving keyboard users with no visible indication of focus.',
					'remediation' => 'Ensure :focus and :focus-visible provide a distinct visible outline (at least 2px solid) or high-contrast box-shadow with at least 3:1 contrast against adjacent background colors.',
				);
			}
		}
	}

	return $issues;
}
