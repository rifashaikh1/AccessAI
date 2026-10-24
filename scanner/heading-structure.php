
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect skipped heading levels in published pages and posts.
 */
function accessai_scan_heading_structure() {
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

        $headings = $dom->getElementsByTagName( '*' );
        $previous_level = 0;

        foreach ( $headings as $heading ) {
            $tag = strtolower( $heading->tagName );

            if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
                continue;
            }

            $current_level = (int) substr( $tag, 1 );

            if (
                $previous_level > 0 &&
                $current_level > $previous_level + 1
            ) {
                $issues[] = array(
                    'type'          => 'Skipped Heading Level',
                    'severity'      => 'Warning',
                    'page_title'    => $item->post_title,
                    'page_id'       => $item->ID,
                    'previous'      => 'H' . $previous_level,
                    'current'       => strtoupper( $tag ),
                    'heading_text'  => trim( $heading->textContent ),
                );
            }

            $previous_level = $current_level;
        }

        libxml_clear_errors();
    }

    return $issues;
}
