
<?php
/**
 * Plugin Name: AccessAI
 * Description: AI-assisted accessibility auditing for WordPress websites.
 * Version: 1.0.0
 * Author: AccessAI Team
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once plugin_dir_path( __FILE__ )
    . 'scanner/alt-text.php';

require_once plugin_dir_path( __FILE__ )
    . 'scanner/heading-structure.php';

require_once plugin_dir_path( __FILE__ )
    . 'scanner/form-labels.php';

require_once plugin_dir_path( __FILE__ )
    . 'scanner/ai-assistant.php';

// Add AccessAI to the WordPress admin sidebar.
add_action( 'admin_menu', 'accessai_register_menu' );

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

// Display the dashboard.
function accessai_render_dashboard() {
    $issues = accessai_scan_missing_alt_text();
    $heading_issues = accessai_scan_heading_structure();
    $form_issues = accessai_scan_form_labels();
   $user_id = get_current_user_id();

$contrast_results = get_transient(
    'accessai_contrast_results_' . $user_id
);

$contrast_scan_completed = get_transient(
    'accessai_contrast_scan_completed_' . $user_id
);

if ( ! is_array( $contrast_results ) ) {
    $contrast_results = array();
}


$total_issues = count( $issues )
    + count( $heading_issues )
    + count( $form_issues )
    + count( $contrast_results );

$warnings = $total_issues;

// A preliminary project metric, not a WCAG compliance score.
// This uses the number of findings as a simple penalty.
$total_content_items = get_posts(
    array(
        'post_type'      => array( 'page', 'post' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);

$items_scanned = count( $total_content_items );

$checks_estimated = max( 1, $items_scanned * 3 );

$passed_checks = max(
    0,
    $checks_estimated - $total_issues
);

$accessibility_score = round(
    ( $passed_checks / $checks_estimated ) * 100
);

$accessibility_score = min(
    100,
    max( 0, $accessibility_score )
);

    ?>


    <div class="wrap">
        <h1>AccessAI Dashboard</h1>

        <p>
            Welcome to AccessAI — your WordPress accessibility assistant.
        </p>
         
       
<h2>Alt Text Scan Results</h2>

<?php if ( empty( $issues ) ) : ?>

    <div class="notice notice-success inline">
        <p>No missing or empty alt text issues were found in the scanned content.</p>
    </div>

<?php else : ?>

    <p>
        Found <strong><?php echo esc_html( count( $issues ) ); ?></strong>
        image(s) that need alt text review.
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Issue</th>
                <th>Severity</th>
                <th>Page</th>
                <th>Image Source</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ( $issues as $issue ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( $issue['type'] ); ?>
                    </td>

                    <td>
                        <span style="color:#b45309;font-weight:600;">
                            <?php echo esc_html( $issue['severity'] ); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo esc_html( $issue['page_title'] ); ?>
                    </td>

                    <td>
                        <?php if ( ! empty( $issue['image_src'] ) ) : ?>
                            <?php echo esc_html( $issue['image_src'] ); ?>
                        <?php else : ?>
                            Image source unavailable
                        <?php endif; ?>
                    </td>

                    <td>
                        <a
                            class="button button-secondary"
                            href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
                        >
                            Edit Page
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>


<hr>

<h2>Heading Structure Scan Results</h2>

<?php if ( empty( $heading_issues ) ) : ?>

    <div class="notice notice-success inline">
        <p>No skipped heading levels were detected in the scanned content.</p>
    </div>

<?php else : ?>

    <p>
        Found <strong><?php echo esc_html( count( $heading_issues ) ); ?></strong>
        possible heading hierarchy issue(s).
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Issue</th>
                <th>Page</th>
                <th>Heading Transition</th>
                <th>Heading Text</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ( $heading_issues as $issue ) : ?>
                <tr>
                    <td><?php echo esc_html( $issue['type'] ); ?></td>

                    <td><?php echo esc_html( $issue['page_title'] ); ?></td>

                    <td>
                        <?php
                        echo esc_html(
                            $issue['previous'] . ' → ' . $issue['current']
                        );
                        ?>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $issue['heading_text'] ?: '(Empty heading)'
                        );
                        ?>
                    </td>

                    <td>
                        <a
                            class="button button-secondary"
                            href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
                        >
                            Edit Page
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>


<hr>

<h2>Form Label Scan Results</h2>

<?php if ( empty( $form_issues ) ) : ?>

    <div class="notice notice-success inline">
        <p>No potentially unlabeled form controls were found in the scanned content.</p>
    </div>

<?php else : ?>

    <p>
        Found <strong><?php echo esc_html( count( $form_issues ) ); ?></strong>
        form control(s) that need review.
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Issue</th>
                <th>Page</th>
                <th>Control</th>
                <th>Field Name</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ( $form_issues as $issue ) : ?>
                <tr>
                    <td><?php echo esc_html( $issue['type'] ); ?></td>
                    <td><?php echo esc_html( $issue['page_title'] ); ?></td>
                    <td>
                        <?php echo esc_html( $issue['control'] ); ?>
                        <?php if ( $issue['field_type'] !== '' ) : ?>
                            (<?php echo esc_html( $issue['field_type'] ); ?>)
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html( $issue['field_name'] ?: 'Not specified' ); ?>
                    </td>
                    <td>
                        <a
                            class="button button-secondary"
                            href="<?php echo esc_url( get_edit_post_link( $issue['page_id'] ) ); ?>"
                        >
                            Edit Page
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>


<hr>

<h2>Color Contrast Scan Results</h2>

<?php if ( ! $contrast_scan_completed ) : ?>

    <div class="notice notice-info inline">
        <p>
            No contrast scan has been completed yet.
            Open your published test page and run the scan first.
        </p>
    </div>

<?php elseif ( empty( $contrast_results ) ) : ?>

    <div class="notice notice-success inline">
        <p>
            Scan completed. No potential contrast failures were detected
            among the text elements checked.
        </p>
    </div>

<?php else : ?>

    <p>
        Found
        <strong><?php echo esc_html( count( $contrast_results ) ); ?></strong>
        potential contrast issue(s).
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Element</th>
                <th>Text</th>
                <th>Contrast Ratio</th>
                <th>Required Ratio</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ( $contrast_results as $result ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( $result['element'] ); ?>
                    </td>
                    <td>
                        <?php echo esc_html( $result['text'] ); ?>
                    </td>
                    <td>
                        <?php echo esc_html( $result['ratio'] ); ?>
                    </td>
                    <td>
                        <?php echo esc_html( $result['required'] ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>


        <div style="
            background:#ffffff;
            padding:24px;
            margin-top:20px;
            border:1px solid #dcdcde;
            border-radius:10px;
            max-width:850px;
        ">
            <h2>Website Accessibility Overview</h2>

            
<div style="
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:16px;
    margin:20px 0;
">
    <div style="background:#ffffff;padding:20px;border:1px solid #dcdcde;border-radius:10px;">
        <p style="margin:0;color:#50575e;">Accessibility Score</p>
        <h2 style="font-size:32px;margin:8px 0;">
            <?php echo esc_html( $accessibility_score ); ?>/100
        </h2>
        <p style="margin:0;color:#646970;">Preliminary project metric</p>
    </div>

    <div style="background:#ffffff;padding:20px;border:1px solid #dcdcde;border-radius:10px;">
        <p style="margin:0;color:#50575e;">Total Findings</p>
        <h2 style="font-size:32px;margin:8px 0;">
            <?php echo esc_html( $total_issues ); ?>
        </h2>
        <p style="margin:0;color:#646970;">Across current scanners</p>
    </div>

    <div style="background:#ffffff;padding:20px;border:1px solid #dcdcde;border-radius:10px;">
        <p style="margin:0;color:#50575e;">Warnings</p>
        <h2 style="font-size:32px;margin:8px 0;">
            <?php echo esc_html( $warnings ); ?>
        </h2>
        <p style="margin:0;color:#646970;">Findings needing review</p>
    </div>

    <div style="background:#ffffff;padding:20px;border:1px solid #dcdcde;border-radius:10px;">
        <p style="margin:0;color:#50575e;">Estimated Passed Checks</p>
        <h2 style="font-size:32px;margin:8px 0;">
            <?php echo esc_html( $passed_checks ); ?>
        </h2>
        <p style="margin:0;color:#646970;">Estimated, not a full audit</p>
    </div>
</div>


            <p>
                Your accessibility scan results will appear here.
            </p>

            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="background:#fef2f2;padding:18px;border-radius:8px;min-width:140px;">
                    <strong>Critical Issues</strong>
                    <h2>0</h2>
                </div>

                <div style="background:#fff7ed;padding:18px;border-radius:8px;min-width:140px;">
                    <strong>Warnings</strong>
                    <h2>0</h2>
                </div>

                <div style="background:#f0fdf4;padding:18px;border-radius:8px;min-width:140px;">
                    <strong>Passed Checks</strong>
                    <h2>0</h2>
                </div>
            </div>

            <p style="margin-top:24px;">
                <button type="button" class="button button-primary button-hero" disabled>
                    Scanner Coming Next
                </button>
            </p>

            <p>
                Next: implement automatic accessibility issue detection.
            </p>
        </div>
    </div>
    <?php
}

/**
 * Load the contrast scanner on the public website for administrators.
 */
add_action( 'wp_enqueue_scripts', 'accessai_load_contrast_scanner' );


function accessai_load_contrast_scanner() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_script(
        'accessai-contrast-scanner',
        plugin_dir_url( __FILE__ ) . 'assets/js/contrast-scanner.js',
        array(),
        '1.0.2',
        true
    );

    wp_localize_script(
        'accessai-contrast-scanner',
        'AccessAIConfig',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'accessai_contrast_scan' ),
        )
    );
}


add_action(
    'wp_ajax_accessai_save_contrast_results',
    'accessai_save_contrast_results'
);

function accessai_save_contrast_results() {
    check_ajax_referer( 'accessai_contrast_scan', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            array( 'message' => 'Permission denied.' ),
            403
        );
    }

    $raw_results = isset( $_POST['results'] )
        ? wp_unslash( $_POST['results'] )
        : '';

    $results = json_decode( $raw_results, true );

    if ( ! is_array( $results ) ) {
        wp_send_json_error(
            array( 'message' => 'Invalid scan results.' ),
            400
        );
    }

    $safe_results = array();

    foreach ( array_slice( $results, 0, 500 ) as $result ) {
        if ( ! is_array( $result ) ) {
            continue;
        }

        $safe_results[] = array(
            'element' => sanitize_text_field(
                $result['element'] ?? ''
            ),
            'text' => sanitize_text_field(
                $result['text'] ?? ''
            ),
            'ratio' => sanitize_text_field(
                $result['ratio'] ?? ''
            ),
            'required' => sanitize_text_field(
                $result['required'] ?? ''
            ),
        );
    }

    $user_id = get_current_user_id();

set_transient(
    'accessai_contrast_results_' . $user_id,
    $safe_results,
    HOUR_IN_SECONDS
);

set_transient(
    'accessai_contrast_scan_completed_' . $user_id,
    true,
    HOUR_IN_SECONDS
);

    wp_send_json_success(
        array(
            'message' => 'Contrast results received.',
            'count'   => count( $safe_results ),
        )
    );
}

