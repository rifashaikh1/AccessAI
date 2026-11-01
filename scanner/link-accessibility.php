<?php
/**
 * AccessAI — Link Accessibility Scanner
 * WCAG 2.1 Success Criterion 2.4.4 Link Purpose (In Context) (Level A)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan published post/page content for link accessibility issues.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of link accessibility findings.
 */
function accessai_scan_link_accessibility( $post_id = 0 ) {
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

	$ambiguous_phrases = array(
		'click here', 'click this', 'read more', 'learn more', 'more',
		'here', 'link', 'this link', 'go', 'details', 'info',
		'continue', 'continue reading', 'see more', 'find out more'
	);

	foreach ( $items as $item ) {
		$html = $item->post_content;
		if ( empty( $html ) ) {
			continue;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		$links = $dom->getElementsByTagName( 'a' );

		foreach ( $links as $link ) {
			$href       = trim( $link->getAttribute( 'href' ) );
			$aria_label = trim( $link->getAttribute( 'aria-label' ) );
			$aria_by    = trim( $link->getAttribute( 'aria-labelledby' ) );
			$title      = trim( $link->getAttribute( 'title' ) );
			$role       = trim( strtolower( $link->getAttribute( 'role' ) ) );
			$text       = trim( $link->textContent );

			// Check image alternatives inside link
			$img_alts = array();
			$imgs     = $link->getElementsByTagName( 'img' );
			$has_img  = ( $imgs->length > 0 );
			foreach ( $imgs as $img ) {
				if ( $img->hasAttribute( 'alt' ) ) {
					$alt = trim( $img->getAttribute( 'alt' ) );
					if ( $alt !== '' ) {
						$img_alts[] = $alt;
					}
				}
			}

			// Determine accessible name
			$acc_name = $aria_label;
			if ( $acc_name === '' && ! empty( $img_alts ) ) {
				$acc_name = implode( ' ', $img_alts );
			}
			if ( $acc_name === '' ) {
				$acc_name = $text;
			}
			if ( $acc_name === '' && $title !== '' ) {
				$acc_name = $title;
			}

			$clean_acc_name = strtolower( preg_replace( '/\s+/', ' ', $acc_name ) );

			// Case 1: Empty Link (no accessible name and no image alt)
			if ( $clean_acc_name === '' ) {
				$outer = $dom->saveHTML( $link );
				$issues[] = array(
					'category'    => 'Link Accessibility',
					'type'        => 'Link Without Accessible Name',
					'wcag'        => '2.4.4 Link Purpose (In Context) (Level A)',
					'severity'    => 'High',
					'status'      => 'Verified Failure',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => substr( $outer, 0, 140 ),
					'link_url'    => $href ?: '(no href)',
					'link_text'   => '(empty)',
					'message'     => 'The link provides no text, image alternative, or aria-label for assistive technologies.',
					'remediation' => 'Add meaningful visible anchor text or an aria-label describing the destination.',
				);
				continue;
			}

			// Case 2: Link contains image without alt text and no other link text
			if ( $has_img && empty( $img_alts ) && $text === '' && $aria_label === '' ) {
				$outer = $dom->saveHTML( $link );
				$issues[] = array(
					'category'    => 'Link Accessibility',
					'type'        => 'Image Link Missing Alternative',
					'wcag'        => '2.4.4 Link Purpose (In Context) (Level A)',
					'severity'    => 'High',
					'status'      => 'Verified Failure',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => substr( $outer, 0, 140 ),
					'link_url'    => $href ?: '(no href)',
					'link_text'   => '(image only)',
					'message'     => 'This link wraps an image that lacks alternative text, leaving screen reader users with only the raw URL.',
					'remediation' => 'Provide a descriptive alt attribute on the image or an aria-label on the link.',
				);
				continue;
			}

			// Case 3: Ambiguous link text ("Click here", "Read more", etc.)
			if ( in_array( $clean_acc_name, $ambiguous_phrases, true ) ) {
				// Inspect surrounding context (parent paragraph or parent list item)
				$parent_text = '';
				$parent      = $link->parentNode;
				if ( $parent instanceof DOMElement ) {
					$parent_text = trim( $parent->textContent );
				}

				// If parent text is identical to link text or very brief, context is insufficient
				$has_context = strlen( $parent_text ) > strlen( $text ) + 15;

				$issues[] = array(
					'category'    => 'Link Accessibility',
					'type'        => 'Ambiguous Link Text',
					'wcag'        => '2.4.4 Link Purpose (In Context) (Level A)',
					'severity'    => $has_context ? 'Low' : 'Medium',
					'status'      => $has_context ? 'Potential Issue' : 'Verified Failure',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>&lt;a href="' . esc_attr( substr( $href, 0, 40 ) ) . '"&gt;' . esc_html( $text ) . '&lt;/a&gt;</code>',
					'link_url'    => $href,
					'link_text'   => $text,
					'message'     => 'Link text "' . $text . '" is ambiguous out of context when navigated via screen reader link lists.',
					'remediation' => 'Rewrite link text to state the specific destination (e.g. "Read more about accessibility guidelines") or add aria-label.',
				);
			}

			// Case 4: Placeholder/broken link target (href="#" or href="javascript:void(0)")
			if ( $href === '#' || strpos( strtolower( $href ), 'javascript:' ) === 0 ) {
				if ( $role !== 'button' ) {
					$issues[] = array(
						'category'    => 'Link Accessibility',
						'type'        => 'Inappropriate Link Used for Action',
						'wcag'        => '2.4.4 Link Purpose (In Context) (Level A)',
						'severity'    => 'Medium',
						'status'      => 'Potential Issue',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>href="' . esc_attr( $href ) . '"</code> (' . esc_html( substr( $text, 0, 30 ) ) . ')',
						'link_url'    => $href,
						'link_text'   => $text,
						'message'     => 'Link has no valid destination and may be acting as an interactive script button without appropriate semantics.',
						'remediation' => 'Use a native <button> element instead of an <a> element for script actions, or supply a valid URL.',
					);
				}
			}
		}

		libxml_clear_errors();
	}

	return $issues;
}
