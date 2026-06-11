<?php
/**
 * Plugin Name: Gleo
 * Plugin URI: https://example.com/gleo
 * Description: Generative Engine Optimization (GEO) WordPress plugin.
 * Version: 1.0.0
 * Author: Gleo Team
 * License: GPL-2.0+
 * Text Domain: gleo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'GLEO_VERSION', '1.0.0' );
define( 'GLEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Activation hook
register_activation_hook( __FILE__, 'gleo_activate' );
function gleo_activate() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name = $wpdb->prefix . 'gleo_scans';

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		scan_status varchar(50) NOT NULL,
		scan_result longtext,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// History table for analytics over time
	$history_table = $wpdb->prefix . 'gleo_scan_history';
	$sql .= "CREATE TABLE $history_table (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		geo_score int(3) DEFAULT 0,
		brand_inclusion_rate int(2) DEFAULT 0,
		scanned_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY post_id (post_id),
		KEY scanned_at (scanned_at)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Register settings
add_action( 'init', 'gleo_register_settings' );
function gleo_register_settings() {
	register_setting( 'gleo_settings', 'gleo_client_id', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'show_in_rest'      => true,
		'default'           => '',
	) );
	
	register_setting( 'gleo_settings', 'gleo_secret_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'show_in_rest'      => true,
		'default'           => '',
	) );

	register_setting( 'gleo_settings', 'gleo_override_schema', array(
		'type'              => 'boolean',
		'show_in_rest'      => true,
		'default'           => false,
	) );

	register_setting( 'gleo_settings', 'gleo_org_logo_url', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'show_in_rest'      => true,
		'default'           => '',
	) );

	register_setting( 'gleo_settings', 'gleo_social_links', array(
		'type'              => 'string',
		'sanitize_callback' => 'gleo_sanitize_social_links_json',
		'show_in_rest'      => true,
		'default'           => '',
	) );
}

/**
 * Sanitize JSON array of social profile URLs for gleo_social_links.
 *
 * @param mixed $value Raw option value.
 * @return string JSON-encoded URL list.
 */
function gleo_sanitize_social_links_json( $value ) {
	$links = array();
	if ( is_string( $value ) && '' !== trim( $value ) ) {
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $link ) {
				$link = esc_url_raw( (string) $link );
				if ( '' !== $link ) {
					$links[] = $link;
				}
			}
		}
	} elseif ( is_array( $value ) ) {
		foreach ( $value as $link ) {
			$link = esc_url_raw( (string) $link );
			if ( '' !== $link ) {
				$links[] = $link;
			}
		}
	}
	return wp_json_encode( array_values( array_unique( $links ) ) );
}

// Include API Client & Modules
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-sitemap.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-batch-scanner.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-optimize.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-frontend.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-analytics.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-tracking.php';

// Deactivation hook
register_deactivation_hook( __FILE__, 'gleo_deactivate' );
function gleo_deactivate() {
	// Deactivation logic goes here.
}

// Enqueue admin scripts
add_action( 'admin_enqueue_scripts', 'gleo_admin_scripts' );
function gleo_admin_scripts( $hook ) {
	// Only load on the Gleo top-level admin page; avoid loading the React bundle on every screen.
	if ( 'toplevel_page_gleo' !== $hook ) {
		return;
	}

	$asset_path = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	if ( file_exists( $asset_path ) ) {
		$asset_file = include( $asset_path );
		
		wp_enqueue_script(
			'gleo-admin-app',
			plugins_url( 'build/index.js', __FILE__ ),
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'gleo-admin-style',
			plugins_url( 'build/index.css', __FILE__ ),
			array( 'wp-components' ),
			$asset_file['version']
		);

		// Detect active SEO plugins
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		$seo_plugin_active = false;
		$seo_plugin_name = '';
		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			$seo_plugin_active = true;
			$seo_plugin_name = 'Yoast SEO';
		} elseif ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			$seo_plugin_active = true;
			$seo_plugin_name = 'RankMath';
		}

		$node_api_url = defined( 'GLEO_NODE_API_URL' ) ? GLEO_NODE_API_URL : 'http://localhost:8765';
		$node_api_url = apply_filters( 'gleo_node_api_url', $node_api_url );

		$top_posts  = get_posts( array( 'posts_per_page' => 20, 'post_status' => 'publish' ) );
		$posts_data = array();
		foreach ( $top_posts as $p ) {
			$posts_data[] = array(
				'ID'        => $p->ID,
				'title'     => $p->post_title,
				'post_type' => 'post',
			);
		}

		$top_pages  = get_pages( array( 'number' => 50, 'post_status' => 'publish', 'sort_column' => 'menu_order' ) );
		$pages_data = array();
		foreach ( $top_pages as $pg ) {
			$pages_data[] = array(
				'ID'        => $pg->ID,
				'title'     => $pg->post_title,
				'post_type' => 'page',
				'parent'    => (int) $pg->post_parent,
			);
		}

		$gleo_data = array(
			'seoPluginActive' => $seo_plugin_active,
			'seoPluginName'   => $seo_plugin_name,
			'siteUrl'         => get_site_url(),
			'posts'           => $posts_data,
			'pages'           => $pages_data,
			'nodeApiUrl'      => esc_url_raw( $node_api_url ),
		);

		wp_localize_script( 'gleo-admin-app', 'gleoData', $gleo_data );
	}
}

// Register admin menu
add_action( 'admin_menu', 'gleo_register_admin_menu' );
function gleo_register_admin_menu() {
	add_menu_page(
		__( 'Gleo', 'gleo' ),
		__( 'Gleo', 'gleo' ),
		'manage_options',
		'gleo',
		'gleo_admin_page_html',
		'dashicons-chart-area',
		30
	);
}

function gleo_admin_page_html() {
	echo '<div class="wrap"><div id="gleo-admin-app"></div></div>';
}
