<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click optimize helpers: vision critique proxy to Node API.
 */
class Gleo_Optimize {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'gleo/v1',
			'/optimize/critique',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_critique' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'gleo/v1',
			'/design/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_design' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Apply a design profile (palette) to a single post or site-wide.
	 *
	 * Accepts: { post_id?: int, site_wide?: bool, profile: { enabled, accent, text, muted, card, surface, border } }
	 */
	public function apply_design( $request ) {
		$params   = $request->get_json_params();
		$profile  = isset( $params['profile'] ) && is_array( $params['profile'] ) ? $params['profile'] : array();
		$post_id  = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$site_wide = ! empty( $params['site_wide'] );

		if ( empty( $profile ) ) {
			return new WP_Error( 'missing_profile', 'profile is required.', array( 'status' => 400 ) );
		}

		$color_keys = array( 'accent', 'text', 'muted', 'card', 'surface', 'border' );
		$sanitized  = array(
			'enabled'    => ! empty( $profile['enabled'] ),
			'page_wide'  => ! empty( $profile['page_wide'] ),
			'source'     => sanitize_text_field( (string) ( $profile['source'] ?? 'user' ) ),
			'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		foreach ( $color_keys as $key ) {
			$sanitized[ $key ] = sanitize_hex_color( (string) ( $profile[ $key ] ?? '' ) ) ?? '';
		}

		$json = wp_json_encode( $sanitized );

		// #region agent log
		$_gleo_log = wp_json_encode( array(
			'sessionId' => '8b85ea', 'hypothesisId' => 'B-C',
			'location'  => 'class-gleo-optimize.php:apply_design',
			'message'   => 'saving profile',
			'data'      => array(
				'received_page_wide'  => $profile['page_wide'] ?? 'NOT SET',
				'received_enabled'    => $profile['enabled'] ?? 'NOT SET',
				'sanitized_page_wide' => $sanitized['page_wide'],
				'sanitized_enabled'   => $sanitized['enabled'],
				'post_id'   => $post_id, 'site_wide' => $site_wide,
				'saved_option' => ( $site_wide || $post_id <= 0 ),
				'saved_meta'   => ( $post_id > 0 ),
			),
			'timestamp' => round( microtime( true ) * 1000 ),
		) );
		file_put_contents( '/tmp/gleo-debug-8b85ea.log', $_gleo_log . "\n", FILE_APPEND );
		// #endregion

		if ( $site_wide || $post_id <= 0 ) {
			update_option( 'gleo_design_profile', $json );
		}

		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_gleo_design_profile', $json );
		}

		return rest_ensure_response( array( 'success' => true, 'profile' => $sanitized ) );
	}

	public function run_critique( $request ) {
		$params  = $request->get_json_params();
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;

		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', 'post_id is required.', array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', 'Published post not found.', array( 'status' => 404 ) );
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return new WP_Error( 'no_url', 'Could not resolve post URL.', array( 'status' => 400 ) );
		}

		$page_url = add_query_arg( 'gleo_iframe', '1', $permalink );

		$api_client = new Gleo_API_Client();
		$response   = $api_client->send_request(
			'/v1/optimize/critique',
			array(
				'page_url'    => $page_url,
				'post_title'  => $post->post_title,
			),
			120
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = isset( $body['data'] ) ? $body['data'] : array();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}
}

new Gleo_Optimize();
