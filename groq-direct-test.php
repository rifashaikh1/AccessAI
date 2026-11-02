<?php
/**
 * Standalone Groq API Diagnostic Test
 *
 * Requirements:
 * 1. Reads ACCESSAI_GROQ_API_KEY securely from wp-config.php without leaking it.
 * 2. Sends one minimal text request directly to Groq's chat completions endpoint.
 * 3. Uses a currently supported model (llama-3.3-70b-versatile / llama-3.1-8b-instant).
 * 4. Displays the HTTP status, latency, and sanitized response.
 * 5. Does not invoke AccessAI's cache or fallback.
 */

// Security: If accessed via web, require manage_options capability.
if ( php_sapi_name() !== 'cli' ) {
	$wp_load = dirname( dirname( dirname( __DIR__ ) ) ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
		if ( ! current_user_can( 'manage_options' ) ) {
			header( 'HTTP/1.1 403 Forbidden' );
			die( 'Access denied: Administrator privileges required.' );
		}
	}
}

// Step 1: Read key securely from wp-config.php.
$api_key = '';
if ( defined( 'ACCESSAI_GROQ_API_KEY' ) && ACCESSAI_GROQ_API_KEY ) {
	$api_key = trim( (string) ACCESSAI_GROQ_API_KEY );
} else {
	$wp_config_path = dirname( dirname( dirname( __DIR__ ) ) ) . '/wp-config.php';
	if ( file_exists( $wp_config_path ) ) {
		$config_content = file_get_contents( $wp_config_path );
		if ( preg_match( "/define\s*\(\s*['\"]ACCESSAI_GROQ_API_KEY['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $matches ) ) {
			$api_key = trim( $matches[1] );
		}
	}
}

if ( empty( $api_key ) ) {
	echo "[ERROR] ACCESSAI_GROQ_API_KEY could not be found in wp-config.php.\n";
	exit( 1 );
}

$masked_key = strlen( $api_key ) > 8
	? substr( $api_key, 0, 6 ) . '...' . substr( $api_key, -4 )
	: '***';

$endpoint = 'https://api.groq.com/openai/v1/chat/completions';
$model    = 'openai/gpt-oss-120b';

echo "========================================================\n";
echo "   AccessAI: Standalone Direct Groq API Test\n";
echo "========================================================\n";
echo "1. API Key Source:   wp-config.php (Found: {$masked_key})\n";
echo "2. Target Endpoint:  {$endpoint}\n";
echo "3. Target Model:     {$model}\n";
echo "4. Bypass Mode:      Direct HTTP (No cache, no transients, no fallback)\n";
echo "--------------------------------------------------------\n";
echo "Sending minimal JSON test payload to Groq...\n\n";

$payload = array(
	'model'           => $model,
	'messages'        => array(
		array(
			'role'    => 'system',
			'content' => 'You are AccessAI. Respond strictly in valid JSON with keys: status, message.',
		),
		array(
			'role'    => 'user',
			'content' => 'Test connection. Return status as "ok" and message confirming Groq API is working.',
		),
	),
	'response_format' => array( 'type' => 'json_object' ),
	'temperature'     => 0.2,
	'max_tokens'      => 150,
);

$json_payload = json_encode( $payload );

$ch = curl_init( $endpoint );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $json_payload,
		CURLOPT_HTTPHEADER     => array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
			'User-Agent: AccessAI-WordPress/1.3',
		),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_SSL_VERIFYPEER => true,
	)
);

$start_time   = microtime( true );
$response_raw = curl_exec( $ch );
$elapsed_ms   = round( ( microtime( true ) - $start_time ) * 1000 );
$http_status  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$curl_error   = curl_error( $ch );
curl_close( $ch );

echo "=== Direct HTTP Response ===\n";
echo "HTTP Status Code:    {$http_status}\n";
echo "Round-trip Latency:  {$elapsed_ms} ms\n\n";

if ( $curl_error ) {
	echo "[CURL Transport Error]: " . htmlspecialchars( $curl_error, ENT_QUOTES, 'UTF-8' ) . "\n";
	exit( 1 );
}

$decoded = json_decode( $response_raw, true );

if ( $http_status === 200 && is_array( $decoded ) ) {
	$reply = $decoded['choices'][0]['message']['content'] ?? '';
	echo "[SUCCESS] Groq API responded successfully!\n";
	echo "Generated Content:\n";
	echo trim( $reply ) . "\n\n";

	if ( isset( $decoded['usage'] ) ) {
		echo "Token Usage:\n";
		echo " - Prompt Tokens:     " . ( $decoded['usage']['prompt_tokens'] ?? 0 ) . "\n";
		echo " - Completion Tokens: " . ( $decoded['usage']['completion_tokens'] ?? 0 ) . "\n";
		echo " - Total Tokens:      " . ( $decoded['usage']['total_tokens'] ?? 0 ) . "\n";
	}

	if ( isset( $decoded['model'] ) ) {
		echo " - Active Model:      " . $decoded['model'] . "\n";
	}
} else {
	echo "[HTTP {$http_status} ERROR Response from Groq]\n";
	if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
		$err_type    = $decoded['error']['type'] ?? 'api_error';
		$err_code    = $decoded['error']['code'] ?? $http_status;
		$err_message = $decoded['error']['message'] ?? 'No message provided';

		echo "Error Type:    " . htmlspecialchars( (string) $err_type, ENT_QUOTES, 'UTF-8' ) . "\n";
		echo "Error Code:    " . htmlspecialchars( (string) $err_code, ENT_QUOTES, 'UTF-8' ) . "\n";
		echo "Message:       " . htmlspecialchars( (string) $err_message, ENT_QUOTES, 'UTF-8' ) . "\n";
	} else {
		$safe_raw = substr( strip_tags( (string) $response_raw ), 0, 500 );
		echo "Raw Response:  " . htmlspecialchars( $safe_raw, ENT_QUOTES, 'UTF-8' ) . "\n";
	}
}
echo "========================================================\n";
