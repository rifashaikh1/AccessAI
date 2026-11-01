<?php
/**
 * AccessAI — Keyboard Accessibility Scanner (Server-side DOM inspection)
 * WCAG 2.1 Success Criteria:
 * - 2.1.1 Keyboard (Level A)
 * - 2.1.2 No Keyboard Trap (Level A)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan published post/page content for static keyboard accessibility issues.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of keyboard accessibility findings.
 */
function accessai_scan_keyboard_accessibility( $post_id = 0 ) {
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
		$html = $item->post_content;
		if ( empty( $html ) ) {
			continue;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		$xpath = new DOMXPath( $dom );

		// 1. Positive tabindex attributes (disrupts natural tab sequence)
		$positive_tabindexes = $xpath->query( '//*[@tabindex > 0]' );
		foreach ( $positive_tabindexes as $node ) {
			$val   = (int) $node->getAttribute( 'tabindex' );
			$tag   = strtolower( $node->tagName );
			$outer = $dom->saveHTML( $node );

			$issues[] = array(
				'category'    => 'Keyboard Accessibility',
				'type'        => 'Positive Tabindex Disrupts Navigation Order',
				'wcag'        => '2.1.1 Keyboard (Level A)',
				'severity'    => 'Medium',
				'status'      => 'Verified Failure',
				'page_id'     => $item->ID,
				'page_title'  => $item->post_title,
				'element'     => '<code>&lt;' . esc_html( $tag ) . ' tabindex="' . $val . '"&gt;</code>',
				'context'     => substr( trim( $node->textContent ), 0, 30 ) ?: '<' . $tag . '>',
				'message'     => 'Positive tabindex value (' . $val . ') alters the natural DOM tab sequence and creates an unpredictable navigation experience for keyboard users.',
				'remediation' => 'Remove the positive tabindex or replace with tabindex="0" to respect the natural source document order.',
			);
		}

		// 2. Click handlers on non-interactive elements without tabindex or keyboard role
		$clickables = $xpath->query( '//*[@onclick and not(self::a) and not(self::button) and not(self::input) and not(self::select) and not(self::textarea)]' );
		foreach ( $clickables as $node ) {
			$tag      = strtolower( $node->tagName );
			$tabindex = $node->getAttribute( 'tabindex' );
			$role     = strtolower( trim( $node->getAttribute( 'role' ) ) );

			if ( $tabindex === '' ) {
				$issues[] = array(
					'category'    => 'Keyboard Accessibility',
					'type'        => 'Interactive Element Inaccessible via Keyboard',
					'wcag'        => '2.1.1 Keyboard (Level A)',
					'severity'    => 'High',
					'status'      => 'Potential Issue',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>&lt;' . esc_html( $tag ) . ' onclick="..."&gt;</code>',
					'context'     => substr( trim( $node->textContent ), 0, 30 ) ?: '<' . $tag . '>',
					'message'     => 'Element <' . $tag . '> has an onclick handler but lacks tabindex="0" and keyboard event handlers, making it unreachable with the Tab key.',
					'remediation' => 'Use a native <button> element instead, or add tabindex="0", role="button", and onkeydown listener.',
				);
			}
		}

		// 3. Essential interactive elements disabled from keyboard via tabindex="-1"
		$tab_removed = $xpath->query( '//a[@href and @tabindex="-1"] | //button[@tabindex="-1"]' );
		foreach ( $tab_removed as $node ) {
			$tag = strtolower( $node->tagName );
			$issues[] = array(
				'category'    => 'Keyboard Accessibility',
				'type'        => 'Interactive Control Excluded From Tab Order',
				'wcag'        => '2.1.1 Keyboard (Level A)',
				'severity'    => 'Low',
				'status'      => 'Manual Review Required',
				'page_id'     => $item->ID,
				'page_title'  => $item->post_title,
				'element'     => '<code>&lt;' . esc_html( $tag ) . ' tabindex="-1"&gt;</code>',
				'context'     => substr( trim( $node->textContent ), 0, 30 ) ?: '<' . $tag . '>',
				'message'     => 'Interactive <' . $tag . '> has tabindex="-1", removing it from sequential keyboard navigation.',
				'remediation' => 'Verify whether this control should be reachable by keyboard users; if so, remove tabindex="-1".',
			);
		}

		libxml_clear_errors();
	}

	return $issues;
}
