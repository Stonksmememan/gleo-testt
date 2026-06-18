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

		register_rest_route(
			'gleo/v1',
			'/appearance/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_appearance_analyze' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'gleo/v1',
			'/appearance/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_appearance' ),
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
				'received_accent'     => $profile['accent'] ?? 'NOT SET',
				'sanitized_page_wide' => $sanitized['page_wide'],
				'sanitized_enabled'   => $sanitized['enabled'],
				'sanitized_accent'    => $sanitized['accent'],
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
			// Site-wide apply: remove all per-post overrides so the site option is the
			// single source of truth. Otherwise stale post meta silently wins.
			delete_metadata( 'post', 0, '_gleo_design_profile', '', true );
		}

		if ( $post_id > 0 && ! $site_wide ) {
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

	/**
	 * Proxy to Node appearance analysis endpoint.
	 * Accepts: { post_id }
	 * Stores the returned plan in post meta so the modal can display it immediately on re-open.
	 */
	public function run_appearance_analyze( $request ) {
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

		// Pull image_count from the latest scan result so the Node service can factor it in.
		$image_count = 0;
		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';
		$scan = $wpdb->get_row( $wpdb->prepare(
			"SELECT scan_result FROM {$table_name} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1",
			$post_id
		) );
		if ( $scan && $scan->scan_result ) {
			$result_data = json_decode( $scan->scan_result, true );
			$image_count = (int) ( $result_data['content_signals']['image_count'] ?? 0 );
		}

		$api_client = new Gleo_API_Client();
		$response   = $api_client->send_request(
			'/v1/optimize/appearance',
			array(
				'page_url'    => $page_url,
				'post_title'  => $post->post_title,
				'image_count' => $image_count,
			),
			120
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = isset( $body['data'] ) ? $body['data'] : array();

		// Cache the plan in post meta so the user doesn't need to wait for a re-analysis.
		if ( ! empty( $data ) ) {
			update_post_meta( $post_id, '_gleo_appearance_plan', wp_json_encode( $data ) );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	/**
	 * Apply user-selected appearance improvements.
	 *
	 * Accepts: {
	 *   post_id: int,
	 *   apply_images: bool,
	 *   apply_styles: bool,
	 *   page_wide: bool,
	 *   palette: { accent, text, muted, card, surface, border },
	 *   typography: { body_size, line_height, heading_weight },
	 *   spacing: { content_max_width, section_gap, image_margin },
	 *   images: { border_radius, shadow },
	 *   unsplash_photo: { url, alt, credit, photographer_name, photographer_url },
	 * }
	 */
	public function apply_appearance( $request ) {
		$params        = $request->get_json_params();
		$post_id       = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$apply_images  = ! empty( $params['apply_images'] );
		$page_wide     = ! empty( $params['page_wide'] );
		$palette       = isset( $params['palette'] ) && is_array( $params['palette'] ) ? $params['palette'] : array();
		$photo         = isset( $params['unsplash_photo'] ) && is_array( $params['unsplash_photo'] ) ? $params['unsplash_photo'] : null;
		$raw_preset    = sanitize_text_field( (string) ( $params['layout_preset'] ?? 'clean' ) );
		$layout_preset = in_array( $raw_preset, array( 'clean', 'editorial', 'bold' ), true ) ? $raw_preset : 'clean';

		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', 'post_id is required.', array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// ── 1. Save extended design profile ──────────────────────────────────
		// Always save when this endpoint is called — visual_enhancement source activates
		// the comprehensive CSS preset in inject_appearance_styles().
		{
			$color_keys = array( 'accent', 'text', 'muted', 'card', 'surface', 'border' );
			$sanitized  = array(
				'enabled'       => true,
				'page_wide'     => $page_wide,
				'source'        => 'visual_enhancement',
				'layout_preset' => $layout_preset,
				'updated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			);
			foreach ( $color_keys as $key ) {
				$sanitized[ $key ] = sanitize_hex_color( (string) ( $palette[ $key ] ?? '' ) ) ?? '';
			}

			update_post_meta( $post_id, '_gleo_design_profile', wp_json_encode( $sanitized ) );
		}

		// ── 2. Inject hero image from Unsplash ────────────────────────────────
		$image_injected = false;
		if ( $apply_images && ! empty( $photo['url'] ) ) {
			$image_injected = $this->inject_hero_image( $post, $photo );
		}

		return rest_ensure_response( array(
			'success'        => true,
			'image_injected' => $image_injected,
		) );
	}

	/**
	 * Download an Unsplash photo into the media library and prepend it as a hero block.
	 *
	 * @param WP_Post $post  The post to inject into.
	 * @param array   $photo { url, alt, credit, photographer_name, photographer_url }
	 * @return bool Whether the image was successfully injected.
	 */
	private function inject_hero_image( WP_Post $post, array $photo ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$image_url = esc_url_raw( $photo['url'] );
		if ( ! $image_url ) {
			return false;
		}

		// Sideload the image into the WP media library.
		$attachment_id = media_sideload_image( $image_url, $post->ID, sanitize_text_field( $photo['alt'] ?? '' ), 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		// Store attribution in attachment meta so the credit is preserved.
		if ( ! empty( $photo['credit'] ) ) {
			update_post_meta( $attachment_id, '_gleo_unsplash_credit', sanitize_text_field( $photo['credit'] ) );
		}

		$alt_text = sanitize_text_field( $photo['alt'] ?? '' );
		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		// Build a Gutenberg image block with the Unsplash credit as a caption.
		$caption   = ! empty( $photo['credit'] ) ? esc_html( $photo['credit'] ) : '';
		$img_src   = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! $img_src ) {
			return false;
		}

		$block_attrs = wp_json_encode( array(
			'id'              => $attachment_id,
			'sizeSlug'        => 'large',
			'linkDestination' => 'none',
			'className'       => 'gleo-hero-image',
			'alt'             => $alt_text,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$img_tag  = '<img src="' . esc_url( $img_src ) . '" alt="' . esc_attr( $alt_text ) . '" class="wp-image-' . (int) $attachment_id . '"/>';
		$fig_open = '<figure class="wp-block-image size-large gleo-hero-image">';
		$caption_html = $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';
		$hero_html = $fig_open . $img_tag . $caption_html . '</figure>';
		$hero_block = "<!-- wp:image {$block_attrs} -->\n{$hero_html}\n<!-- /wp:image -->";

		// Phase 5: snapshot original post_content before hero injection so it can be undone.
		global $wpdb;
		$snap_table = $wpdb->prefix . 'gleo_fix_snapshots';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $snap_table ) ) === $snap_table ) {
			$wpdb->insert(
				$snap_table,
				array(
					'post_id'       => $post->ID,
					'fix_type'      => 'appearance_hero',
					'snapshot_json' => wp_json_encode( array( 'post_content' => $post->post_content ) ),
					'user_id'       => get_current_user_id(),
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%d', '%s' )
			);
			// Prune snapshots older than the 5 most recent for this post.
			$old_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$snap_table} WHERE post_id = %d ORDER BY id DESC LIMIT 5, 999",
				$post->ID
			) );
			if ( ! empty( $old_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$snap_table} WHERE id IN ($placeholders)", ...$old_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}

		// Prepend above the first paragraph / heading.
		$updated_content = $hero_block . "\n\n" . $post->post_content;
		$updated = wp_update_post( array( 'ID' => $post->ID, 'post_content' => $updated_content ), true );

		return ! is_wp_error( $updated );
	}
}

new Gleo_Optimize();
