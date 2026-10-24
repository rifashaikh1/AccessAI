
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect form controls without an associated label.
 */
function accessai_scan_form_labels() {
    $issues = array();

    $items = get_posts(
        array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        )
    );

    foreach ( $items as $item ) {
        if ( empty( $item->post_content ) ) {
            continue;
        }

        libxml_use_internal_errors( true );

        $dom = new DOMDocument();

        $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $item->post_content
        );

        $xpath = new DOMXPath( $dom );

        $controls = $xpath->query(
            '//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="reset")] | //select | //textarea'
        );

        foreach ( $controls as $control ) {
            $has_label = false;
            $id = $control->getAttribute( 'id' );

            // Check for a label linked using the control's ID.
            if ( $id !== '' ) {
                $labels = $xpath->query(
                    '//label[@for="' . $id . '"]'
                );

                if ( $labels->length > 0 ) {
                    $has_label = true;
                }
            }

            // Check whether the control is inside a label.
            $parent = $control->parentNode;

            while ( $parent ) {
                if (
                    $parent instanceof DOMElement &&
                    strtolower( $parent->tagName ) === 'label'
                ) {
                    $has_label = true;
                    break;
                }

                $parent = $parent->parentNode;
            }

            // An aria-label or aria-labelledby may also name a control.
            if (
                $control->hasAttribute( 'aria-label' ) &&
                trim( $control->getAttribute( 'aria-label' ) ) !== ''
            ) {
                $has_label = true;
            }

            if (
                $control->hasAttribute( 'aria-labelledby' ) &&
                trim( $control->getAttribute( 'aria-labelledby' ) ) !== ''
            ) {
                $has_label = true;
            }

            if ( ! $has_label ) {
                $issues[] = array(
                    'type'       => 'Possible Missing Form Label',
                    'severity'   => 'Warning',
                    'page_title' => $item->post_title,
                    'page_id'    => $item->ID,
                    'control'    => strtolower( $control->tagName ),
                    'field_type' => $control->getAttribute( 'type' ),
                    'field_name' => $control->getAttribute( 'name' ),
                );
            }
        }

        libxml_clear_errors();
    }

    return $issues;
}
