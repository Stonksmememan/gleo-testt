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
