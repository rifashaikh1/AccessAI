
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Find images with missing or empty alt attributes
 * in published WordPress pages and posts.
 */
function accessai_scan_missing_alt_text() {
    $issues = array();

    $items = get_posts(
        array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        )
    );

    foreach ( $items as $item ) {
        $html = $item->post_content;

        if ( empty( $html ) ) {
            continue;
        }

        libxml_use_internal_errors( true );

        $dom = new DOMDocument();

        $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html
        );

        $images = $dom->getElementsByTagName( 'img' );

        foreach ( $images as $image ) {
            if (
                ! $image->hasAttribute( 'alt' ) ||
                trim( $image->getAttribute( 'alt' ) ) === ''
            ) {
                $issues[] = array(
                    'type'       => 'Missing Alt Text',
                    'severity'   => 'Warning',
                    'page_title' => $item->post_title,
                    'page_id'    => $item->ID,
                    'image_src'  => $image->getAttribute( 'src' ),
                    'message'    => 'This image has missing or empty alternative text.',
                );
            }
        }

        libxml_clear_errors();
    }

    return $issues;
}
