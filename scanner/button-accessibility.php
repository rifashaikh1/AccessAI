<?php
/**
 * AccessAI — Button Accessibility Scanner
 * WCAG 2.1 Success Criterion 4.1.2 Name, Role, Value (Level A)
 *
 * Evaluates native <button>, <input type="button|submit|reset|image">, and custom [role="button"]
 * controls according to W3C Accessible Name and Description Computation (AccName 1.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract accessible text from child nodes, strictly ignoring content hidden via aria-hidden="true"
 * or display:none / visibility:hidden inline styles.
 *
 * @param DOMNode $node
 * @return string
 */
function accessai_button_extract_accessible_text( DOMNode $node ) {
	$text = '';
	if ( ! $node->hasChildNodes() ) {
		return '';
	}

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_TEXT_NODE ) {
			$text .= ' ' . $child->nodeValue;
		} elseif ( $child->nodeType === XML_ELEMENT_NODE ) {
			// WCAG rule: aria-hidden="true" content MUST NOT be counted towards accessible name
			if ( strtolower( trim( $child->getAttribute( 'aria-hidden' ) ) ) === 'true' ) {
				continue;
			}

			// Also ignore inline hidden styles
			$style = strtolower( $child->getAttribute( 'style' ) );
			if (
				strpos( $style, 'display:none' ) !== false ||
				strpos( $style, 'display: none' ) !== false ||
				strpos( $style, 'visibility:hidden' ) !== false ||
				strpos( $style, 'visibility: hidden' ) !== false
			) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( $tag === 'img' ) {
				$alt = trim( $child->getAttribute( 'alt' ) );
				if ( $alt !== '' ) {
					$text .= ' ' . $alt;
				}
			} elseif ( $tag === 'svg' ) {
				$svg_label = trim( $child->getAttribute( 'aria-label' ) );
				if ( $svg_label !== '' ) {
					$text .= ' ' . $svg_label;
				} else {
					$titles = $child->getElementsByTagName( 'title' );
					if ( $titles->length > 0 && trim( $titles->item( 0 )->textContent ) !== '' ) {
						$text .= ' ' . trim( $titles->item( 0 )->textContent );
					}
				}
			} else {
				$text .= ' ' . accessai_button_extract_accessible_text( $child );
			}
		}
	}

	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * Compute the accessible name for a button or interactive element according to AccName 1.2.
 *
 * @param DOMElement $el
 * @param DOMXPath   $xpath
 * @return array
 */
function accessai_compute_button_accessible_name( DOMElement $el, DOMXPath $xpath ) {
	// 1. Check aria-labelledby
	$aria_labelledby = trim( $el->getAttribute( 'aria-labelledby' ) );
	if ( $aria_labelledby !== '' ) {
		$parts   = preg_split( '/\s+/', $aria_labelledby );
		$by_text = array();
		foreach ( $parts as $id ) {
			$targets = $xpath->query( '//*[@id="' . esc_attr( $id ) . '"]' );
			if ( $targets && $targets->length > 0 ) {
				$by_text[] = trim( $targets->item( 0 )->textContent );
			}
		}
		$resolved = trim( implode( ' ', $by_text ) );
		if ( $resolved !== '' ) {
			return array(
				'name'   => $resolved,
				'source' => 'aria-labelledby',
				'empty'  => false,
			);
		}
	}

	// 2. Check aria-label
	if ( $el->hasAttribute( 'aria-label' ) ) {
		$aria_label = trim( $el->getAttribute( 'aria-label' ) );
		if ( $aria_label !== '' ) {
			return array(
				'name'   => $aria_label,
				'source' => 'aria-label',
				'empty'  => false,
			);
		}
		// When aria-label="" is explicitly empty, it provides NO accessible name.
		// Continue to inspect child text or flag if no child text remains.
	}

	// 3. Child subtree (excluding aria-hidden="true" nodes)
	$child_text = accessai_button_extract_accessible_text( $el );
	if ( $child_text !== '' ) {
		return array(
			'name'   => $child_text,
			'source' => 'child-text',
			'empty'  => false,
		);
	}

	// 4. Fallback: title attribute
	$title = trim( $el->getAttribute( 'title' ) );
	if ( $title !== '' ) {
		return array(
			'name'   => $title,
			'source' => 'title',
			'empty'  => false,
		);
	}

	return array(
		'name'   => '',
		'source' => 'none',
		'empty'  => true,
	);
}

/**
 * Generate a descriptive CSS selector for an element.
 *
 * @param DOMElement $el
 * @return string
 */
function accessai_get_button_selector( DOMElement $el ) {
	$tag = strtolower( $el->tagName );
	if ( $el->hasAttribute( 'id' ) && trim( $el->getAttribute( 'id' ) ) !== '' ) {
		return '#' . trim( $el->getAttribute( 'id' ) );
	}
	$classes = trim( $el->getAttribute( 'class' ) );
	if ( $classes !== '' ) {
		$first_class = preg_split( '/\s+/', $classes )[0];
		if ( $first_class ) {
			return $tag . '.' . $first_class;
		}
	}
	if ( $el->hasAttribute( 'aria-label' ) ) {
		$label = $el->getAttribute( 'aria-label' );
		return $tag . '[aria-label="' . esc_attr( substr( $label, 0, 30 ) ) . '"]';
	}
	if ( $el->hasAttribute( 'type' ) ) {
		return $tag . '[type="' . esc_attr( $el->getAttribute( 'type' ) ) . '"]';
	}
	return $tag;
}

/**
 * Scan published post/page content for button accessibility issues.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of button accessibility findings.
 */
function accessai_scan_button_accessibility( $post_id = 0 ) {
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

		// Fallback to filtered post content
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

		// Strictly exclude WordPress admin bar if present in the document
		$admin_bar = $xpath->query( '//*[@id="wpadminbar"]' );
		foreach ( $admin_bar as $ab ) {
			$ab->parentNode->removeChild( $ab );
		}

		// 1. Native <button> elements
		$buttons = $dom->getElementsByTagName( 'button' );
		foreach ( $buttons as $btn ) {
			$computed_name = accessai_compute_button_accessible_name( $btn, $xpath );
			$selector      = accessai_get_button_selector( $btn );
			$outer         = $dom->saveHTML( $btn );
			$outer_snip    = substr( $outer, 0, 140 );

			if ( $computed_name['empty'] || trim( $computed_name['name'] ) === '' ) {
				// Determine diagnostic reason
				$has_empty_aria_label = ( $btn->hasAttribute( 'aria-label' ) && trim( $btn->getAttribute( 'aria-label' ) ) === '' );
				$has_hidden_children  = false;
				foreach ( $btn->getElementsByTagName( '*' ) as $c ) {
					if ( strtolower( trim( $c->getAttribute( 'aria-hidden' ) ) ) === 'true' ) {
						$has_hidden_children = true;
						break;
					}
				}

				$reason = 'This button provides no accessible name for assistive technologies.';
				if ( $has_empty_aria_label && $has_hidden_children ) {
					$reason = 'Button has an empty aria-label="" and its visual contents are hidden with aria-hidden="true", leaving screen readers with no accessible name.';
				} elseif ( $has_empty_aria_label ) {
					$reason = 'Button has an empty aria-label="" attribute, overriding default text and leaving the button unlabeled.';
				} elseif ( $has_hidden_children ) {
					$reason = 'Button contents are marked with aria-hidden="true" but no replacement aria-label or accessible text was provided.';
				}

				$issues[] = array(
					'category'    => 'Button Accessibility',
					'type'        => 'Button Without Accessible Name',
					'wcag'        => '4.1.2 Name, Role, Value (Level A)',
					'severity'    => 'High',
					'status'      => 'Verified Failure',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>' . esc_html( $outer_snip ) . '</code>',
					'selector'    => $selector,
					'button_text' => '(empty accessible name)',
					'message'     => $reason,
					'remediation' => 'Add a descriptive aria-label (e.g. aria-label="Settings") or provide meaningful visible text that is not hidden by aria-hidden="true".',
				);
			}
		}

		// 2. <input type="button|submit|reset|image">
		$input_buttons = $xpath->query( '//input[@type="button" or @type="submit" or @type="reset" or @type="image"]' );
		foreach ( $input_buttons as $input ) {
			$type       = strtolower( $input->getAttribute( 'type' ) );
			$value      = trim( $input->getAttribute( 'value' ) );
			$aria_label = trim( $input->getAttribute( 'aria-label' ) );
			$title      = trim( $input->getAttribute( 'title' ) );
			$alt        = trim( $input->getAttribute( 'alt' ) );
			$selector   = accessai_get_button_selector( $input );
			$outer      = substr( $dom->saveHTML( $input ), 0, 140 );

			if ( $type === 'image' ) {
				if ( $alt === '' && $aria_label === '' && $title === '' ) {
					$issues[] = array(
						'category'    => 'Button Accessibility',
						'type'        => 'Image Submit Button Missing Alt Text',
						'wcag'        => '4.1.2 Name, Role, Value (Level A)',
						'severity'    => 'High',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>' . esc_html( $outer ) . '</code>',
						'selector'    => $selector,
						'button_text' => '(image input)',
						'message'     => '<input type="image"> requires a descriptive alt attribute to serve as its accessible name.',
						'remediation' => 'Add alt="Submit Search" or aria-label describing the button action.',
					);
				}
			} else {
				if ( $value === '' && $aria_label === '' && $title === '' ) {
					$issues[] = array(
						'category'    => 'Button Accessibility',
						'type'        => 'Input Button Without Accessible Name',
						'wcag'        => '4.1.2 Name, Role, Value (Level A)',
						'severity'    => 'High',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>' . esc_html( $outer ) . '</code>',
						'selector'    => $selector,
						'button_text' => '(empty value)',
						'message'     => 'Input button has no value, title, or aria-label attribute.',
						'remediation' => 'Add a descriptive value="Save" or aria-label attribute describing the action.',
					);
				}
			}
		}

		// 3. Custom elements using role="button"
		$custom_buttons = $xpath->query( '//*[@role="button" and not(self::button) and not(self::input)]' );
		foreach ( $custom_buttons as $cbtn ) {
			$tag           = strtolower( $cbtn->tagName );
			$selector      = accessai_get_button_selector( $cbtn );
			$computed_name = accessai_compute_button_accessible_name( $cbtn, $xpath );
			$tabindex      = $cbtn->getAttribute( 'tabindex' );
			$outer         = substr( $dom->saveHTML( $cbtn ), 0, 140 );

			if ( $computed_name['empty'] || trim( $computed_name['name'] ) === '' ) {
				// Empty accessible name on custom widget is a Verified Failure under WCAG 4.1.2
				$issues[] = array(
					'category'    => 'Button Accessibility',
					'type'        => 'Custom Button Missing Accessible Name',
					'wcag'        => '4.1.2 Name, Role, Value (Level A)',
					'severity'    => 'High',
					'status'      => 'Verified Failure',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>' . esc_html( $outer ) . '</code>',
					'selector'    => $selector,
					'button_text' => '(custom widget)',
					'message'     => 'Element has role="button" but provides no visible text or aria-label.',
					'remediation' => 'Add an aria-label or inner text describing the button purpose.',
				);
			} elseif ( $tabindex === '' || (int) $tabindex < 0 ) {
				// Lacks keyboard focusability: Potential Issue (do not flag verified failure solely for lacking native markup)
				$issues[] = array(
					'category'    => 'Button Accessibility',
					'type'        => 'Custom Button Lacks Keyboard Focusability',
					'wcag'        => '4.1.2 Name, Role, Value (Level A)',
					'severity'    => 'Medium',
					'status'      => 'Potential Issue',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>' . esc_html( $outer ) . '</code>',
					'selector'    => $selector,
					'button_text' => substr( $computed_name['name'], 0, 25 ) ?: '(labeled)',
					'message'     => 'Custom button with role="button" has an accessible name but cannot receive keyboard focus because it lacks tabindex="0".',
					'remediation' => 'Add tabindex="0" and keydown handlers (Enter and Space keys), or use a native <button>.',
				);
			}
		}

		libxml_clear_errors();
	}

	return $issues;
}
