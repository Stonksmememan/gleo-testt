<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gleo_API_Client {

	private $api_base_url;

	// #region agent log
	private function debug_log( $hypothesis_id, $message, $data = array() ) {
		$entry = array(
			'sessionId'    => '570b9f',
			'runId'        => 'pre-fix',
			'hypothesisId' => $hypothesis_id,
			'location'     => 'gleo-wp-plugin/includes/class-gleo-api-client.php',
			'message'      => $message,
			'data'         => $data,
			'timestamp'    => (int) round( microtime( true ) * 1000 ),
		);
		@file_put_contents( '/Users/varun/Desktop/gleo-testt-main/.cursor/debug-570b9f.log', wp_json_encode( $entry ) . PHP_EOL, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	// #endregion

	// #region agent log
	private function debug_probe_api_hosts( $endpoint ) {
		$hosts   = array(
			'configured'           => $this->api_base_url,
			'localhost'            => 'http://localhost:8765',
			'loopback'             => 'http://127.0.0.1:8765',
			'host_docker_internal' => 'http://host.docker.internal:8765',
		);
		$results = array();

		foreach ( $hosts as $label => $base_url ) {
			$health_url = untrailingslashit( $base_url ) . '/api/health';
			$started_at = microtime( true );
			$response   = wp_remote_get(
				$health_url,
				array(
					'timeout'     => 2,
					'redirection' => 0,
				)
			);

			$results[ $label ] = array(
				'host'       => wp_parse_url( $health_url, PHP_URL_HOST ),
				'port'       => wp_parse_url( $health_url, PHP_URL_PORT ),
				'elapsed_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			);

			if ( is_wp_error( $response ) ) {
				$results[ $label ]['error_code']    = $response->get_error_code();
				$results[ $label ]['error_message'] = $response->get_error_message();
			} else {
				$results[ $label ]['status']     = (int) wp_remote_retrieve_response_code( $response );
				$results[ $label ]['body_bytes'] = strlen( wp_remote_retrieve_body( $response ) );
			}
		}

		$this->debug_log(
			'E,F,G',
			'WordPress connectivity probe after Node API connection failure',
			array(
				'failed_endpoint' => $endpoint,
				'results'         => $results,
			)
		);
	}
	// #endregion

	public function __construct() {
		$base = defined( 'GLEO_NODE_API_URL' ) ? GLEO_NODE_API_URL : 'http://localhost:8765';
		$this->api_base_url = untrailingslashit( apply_filters( 'gleo_node_api_url', $base ) );
	}

	/**
	 * Strips Gutenberg block comments, shortcodes, and general metadata context
	 * to clean the post content before sending to the LLM backend.
	 */
	public function sanitize_content( $post_content ) {
		// Strip Gutenberg block comments: <!-- wp:... --> ... <!-- /wp:... -->
		$content = preg_replace( '/<!--(.|\s)*?-->/', '', $post_content );
		
		// Strip Shortcodes
		$content = strip_shortcodes( $content );
		
		return trim( $content );
	}

	/**
	 * Send a generic signed request to the Node API.
	 */
	public function send_request( $endpoint, $payload, $timeout = 30 ) {
		$client_id  = get_option( 'gleo_client_id' );
		$secret_key = get_option( 'gleo_secret_key' );

		if ( empty( $client_id ) || empty( $secret_key ) ) {
			return new WP_Error( 'missing_credentials', 'Gleo Client ID or Secret Key is not configured.' );
		}

		$payload_json = wp_json_encode( $payload );

		// Sign payload with HMAC-SHA256
		$signature = hash_hmac( 'sha256', $payload_json, $secret_key );

		$args = array(
			'body'    => $payload_json,
			'headers' => array(
				'Content-Type'       => 'application/json',
				'X-Gleo-Client-Id'   => $client_id,
				'X-Gleo-Signature'   => $signature,
			),
			'timeout' => (int) $timeout,
		);

		$endpoint = '/' . ltrim( (string) $endpoint, '/' );
		// #region agent log
		$this->debug_log(
			'A,B,C',
			'WordPress sending Node API request',
			array(
				'api_base_url'        => $this->api_base_url,
				'endpoint'            => $endpoint,
				'request_url'         => $this->api_base_url . $endpoint,
				'timeout'             => (int) $timeout,
				'payload_bytes'       => strlen( (string) $payload_json ),
				'has_credentials'     => ! empty( $client_id ) && ! empty( $secret_key ),
				'constant_overridden' => defined( 'GLEO_NODE_API_URL' ),
				'wp_site_host'        => wp_parse_url( get_site_url(), PHP_URL_HOST ),
			)
		);
		// #endregion
		$response = wp_remote_post( $this->api_base_url . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
			// #region agent log
			$this->debug_log(
				'A,B',
				'WordPress Node API request returned WP_Error',
				array(
					'request_url'   => $this->api_base_url . $endpoint,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			$this->debug_probe_api_hosts( $endpoint );
			// #endregion
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
		// #region agent log
		$this->debug_log(
			'D',
			'WordPress Node API request received HTTP response',
			array(
				'request_url' => $this->api_base_url . $endpoint,
				'status'      => (int) $status,
				'body_bytes'  => strlen( wp_remote_retrieve_body( $response ) ),
			)
		);
		// #endregion
        if ( $status >= 400 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_msg = isset($body['error']) ? $body['error'] : 'Unknown API error';
            return new WP_Error( 'api_error', 'Node API returned ' . $status . ': ' . $error_msg, array( 'status' => $status ) );
        }

        return $response;
	}
}
