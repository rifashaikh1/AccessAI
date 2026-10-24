<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: Trigger a contrast scan of a specific published page.
 * Returns a signed scan token and the page URL.
 * The dashboard JS opens the URL in a hidden iframe; the token is
 * injected into the page via wp_head and consumed by contrast-scanner.js.
 */
add_action( 'wp_ajax_accessai_trigger_contrast_scan', 'accessai_trigger_contrast_scan' );

function accessai_trigger_contrast_scan() {
	check_ajax_referer( 'accessai_contrast_trigger', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;

	if ( ! $page_id ) {
		wp_send_json_error( array( 'message' => 'Please select a published page or post.' ), 400 );
	}

	$post = get_post( $page_id );

	if (
		! $post ||
		$post->post_status !== 'publish' ||
		! in_array( $post->post_type, array( 'page', 'post' ), true )
	) {
		wp_send_json_error(
			array( 'message' => 'The selected content is not a published page or post.' ),
			400
		);
	}

	$page_url = get_permalink( $page_id );

	if ( ! $page_url ) {
		wp_send_json_error( array( 'message' => 'Could not resolve the URL of the selected page.' ), 400 );
	}

	// Generate a single-use scan token tied to this page URL.
	$token = wp_generate_password( 32, false, false );

	// The result-save nonce is generated now and stored in the transient
	// so contrast-scanner.js can retrieve it without logging in.
	$result_nonce = wp_create_nonce( 'accessai_contrast_scan' );

	set_transient(
		'accessai_scan_token_' . $token,
		array(
			'page_id'      => $page_id,
			'page_url'     => $page_url,
			'result_nonce' => $result_nonce,
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'created'      => time(),
		),
		5 * MINUTE_IN_SECONDS
	);

	wp_send_json_success(
		array(
			'token'    => $token,
			'page_url' => $page_url,
		)
	);
}

/**
 * AJAX (public): Verify a scan token from inside the scan iframe.
 * Returns the ajax URL and result-save nonce so the scanner can post results.
 * Note: This endpoint deliberately accepts unauthenticated requests because
 * the token IS the authentication. Tokens are single-use and expire in 5 minutes.
 */
add_action( 'wp_ajax_nopriv_accessai_verify_scan_token', 'accessai_verify_scan_token' );
add_action( 'wp_ajax_accessai_verify_scan_token', 'accessai_verify_scan_token' );

function accessai_verify_scan_token() {
	$token = isset( $_POST['token'] )
		? sanitize_text_field( wp_unslash( $_POST['token'] ) )
		: '';

	if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
		wp_send_json_error( array( 'message' => 'Invalid token format.' ), 400 );
	}

	$transient_key = 'accessai_scan_token_' . $token;
	$data          = get_transient( $transient_key );

	if ( ! is_array( $data ) ) {
		wp_send_json_error( array( 'message' => 'Token not found or expired.' ), 403 );
	}

	// Single-use: delete immediately after verification.
	delete_transient( $transient_key );

	wp_send_json_success(
		array(
			'ajaxUrl'      => $data['ajax_url'],
			'resultNonce'  => $data['result_nonce'],
		)
	);
}

/**
 * Frontend wp_head hook: inject a scan token meta tag when a pending
 * scan token matches the current page URL.
 * Runs only in the frontend (not admin) so no credentials are exposed.
 */
add_action( 'wp_head', 'accessai_maybe_inject_scan_token' );

function accessai_maybe_inject_scan_token() {
	// Only inject for logged-in admins (the iframe opens in their session).
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Check all pending scan tokens for this user.
	// We store multiple tokens in a separate index so we don't have to
	// search all transients. Use a lightweight user-specific index.
	$user_id   = get_current_user_id();
	$token_idx = get_user_meta( $user_id, '_accessai_pending_scan_token', true );

	if ( ! $token_idx ) {
		return;
	}

	$transient_key = 'accessai_scan_token_' . $token_idx;
	$data          = get_transient( $transient_key );

	if ( ! is_array( $data ) ) {
		delete_user_meta( $user_id, '_accessai_pending_scan_token' );
		return;
	}

	// Normalise both URLs for comparison (strip trailing slash, scheme).
	$current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	$stored_url  = $data['page_url'];

	$current_norm = rtrim( strtolower( $current_url ), '/' );
	$stored_norm  = rtrim( strtolower( $stored_url ), '/' );

	if ( $current_norm !== $stored_norm ) {
		return;
	}

	// Output the token for contrast-scanner.js to pick up.
	printf(
		'<meta name="accessai-scan-token" content="%s">%s',
		esc_attr( $token_idx ),
		"\n"
	);

	// Output the AJAX URL (public info, same origin).
	printf(
		'<meta name="accessai-ajax-url" content="%s">%s',
		esc_attr( admin_url( 'admin-ajax.php' ) ),
		"\n"
	);

	// Clear the pending token (scanner.js will claim the real credentials via verify).
	delete_user_meta( $user_id, '_accessai_pending_scan_token' );
}

/**
 * AJAX: Store the pending scan token in user meta so wp_head can inject it.
 * Called right after trigger_contrast_scan succeeds in the dashboard JS.
 */
add_action( 'wp_ajax_accessai_store_scan_token', 'accessai_store_scan_token' );

function accessai_store_scan_token() {
	check_ajax_referer( 'accessai_contrast_trigger', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	$token = isset( $_POST['token'] )
		? sanitize_text_field( wp_unslash( $_POST['token'] ) )
		: '';

	if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
		wp_send_json_error( array( 'message' => 'Invalid token.' ), 400 );
	}

	update_user_meta( get_current_user_id(), '_accessai_pending_scan_token', $token );

	wp_send_json_success( array( 'message' => 'Token stored.' ) );
}
