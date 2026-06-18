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
define( 'GLEO_DB_VERSION', '1.1' );

// Activation hook
register_activation_hook( __FILE__, 'gleo_activate' );
function gleo_activate() {
	gleo_run_db_migrations();
}

/**
 * Run all database migrations idempotently via dbDelta.
 * Called on activation and on plugins_loaded when GLEO_DB_VERSION has changed.
 */
function gleo_run_db_migrations() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$wpdb->prefix}gleo_scans (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		scan_status varchar(50) NOT NULL,
		scan_result longtext,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$sql .= "CREATE TABLE {$wpdb->prefix}gleo_scan_history (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		geo_score int(3) DEFAULT 0,
		brand_inclusion_rate int(2) DEFAULT 0,
		scanned_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY post_id (post_id),
		KEY scanned_at (scanned_at)
	) $charset_collate;";

	// Phase 5: pre-fix snapshots for undo/rollback
	$sql .= "CREATE TABLE {$wpdb->prefix}gleo_fix_snapshots (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		fix_type varchar(50) NOT NULL,
		snapshot_json longtext NOT NULL,
		user_id bigint(20) DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY post_id (post_id),
		KEY created_at (created_at)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'gleo_db_version', GLEO_DB_VERSION );
}

/**
 * Upgrade database tables for existing installs when GLEO_DB_VERSION changes.
 */
add_action( 'plugins_loaded', 'gleo_maybe_upgrade_db' );
function gleo_maybe_upgrade_db() {
	if ( get_option( 'gleo_db_version' ) !== GLEO_DB_VERSION ) {
		gleo_run_db_migrations();
	}
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

	register_setting( 'gleo_settings', 'gleo_practice_profile', array(
		'type'              => 'string',
		'sanitize_callback' => 'gleo_sanitize_practice_profile_json',
		'show_in_rest'      => true,
		'default'           => '',
	) );

	register_setting( 'gleo_settings', 'gleo_faq_placement_default', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'show_in_rest'      => true,
		'default'           => 'append_end',
	) );

	register_setting( 'gleo_settings', 'gleo_design_profile', array(
		'type'              => 'string',
		'sanitize_callback' => 'gleo_sanitize_design_profile_json',
		'show_in_rest'      => true,
		'default'           => '',
	) );
}

/**
 * Sanitize design profile JSON for gleo_design_profile.
 *
 * @param mixed $value Raw option value.
 * @return string JSON-encoded sanitized profile.
 */
function gleo_sanitize_design_profile_json( $value ) {
	if ( is_string( $value ) && '' !== trim( $value ) ) {
		$decoded = json_decode( $value, true );
	} elseif ( is_array( $value ) ) {
		$decoded = $value;
	} else {
		$decoded = array();
	}
	if ( ! is_array( $decoded ) ) {
		return '';
	}

	$color_keys = array( 'accent', 'text', 'muted', 'card', 'surface', 'border' );
	$out = array(
		'enabled'       => ! empty( $decoded['enabled'] ),
		'page_wide'     => ! empty( $decoded['page_wide'] ),
		'source'        => sanitize_text_field( (string) ( $decoded['source'] ?? 'user' ) ),
		'updated_at'    => sanitize_text_field( (string) ( $decoded['updated_at'] ?? '' ) ),
		'layout_preset' => '',
	);

	$raw_preset = sanitize_text_field( (string) ( $decoded['layout_preset'] ?? '' ) );
	if ( in_array( $raw_preset, array( 'clean', 'editorial', 'bold' ), true ) ) {
		$out['layout_preset'] = $raw_preset;
	}

	foreach ( $color_keys as $key ) {
		$raw = sanitize_hex_color( (string) ( $decoded[ $key ] ?? '' ) );
		$out[ $key ] = $raw ?? '';
	}

	// Typography tokens (body_size, line_height, heading_weight)
	if ( isset( $decoded['typography'] ) && is_array( $decoded['typography'] ) ) {
		$safe_typo = array();
		foreach ( array( 'body_size', 'line_height', 'heading_weight' ) as $tk ) {
			$raw = sanitize_text_field( (string) ( $decoded['typography'][ $tk ] ?? '' ) );
			if ( '' !== $raw && preg_match( '/^[\d\w\s.%-]+$/', $raw ) ) {
				$safe_typo[ $tk ] = $raw;
			}
		}
		if ( ! empty( $safe_typo ) ) {
			$out['typography'] = $safe_typo;
		}
	}

	// Spacing tokens (content_max_width, section_gap, image_margin)
	if ( isset( $decoded['spacing'] ) && is_array( $decoded['spacing'] ) ) {
		$safe_spacing = array();
		foreach ( array( 'content_max_width', 'section_gap', 'image_margin' ) as $sk ) {
			$raw = sanitize_text_field( (string) ( $decoded['spacing'][ $sk ] ?? '' ) );
			if ( '' !== $raw && preg_match( '/^[\d\w\s.%-]+$/', $raw ) ) {
				$safe_spacing[ $sk ] = $raw;
			}
		}
		if ( ! empty( $safe_spacing ) ) {
			$out['spacing'] = $safe_spacing;
		}
	}

	// Image CSS tokens (border_radius, shadow)
	if ( isset( $decoded['images'] ) && is_array( $decoded['images'] ) ) {
		$safe_img = array();
		foreach ( array( 'border_radius', 'shadow' ) as $ik ) {
			$raw = sanitize_text_field( (string) ( $decoded['images'][ $ik ] ?? '' ) );
			if ( '' !== $raw && preg_match( '/^[\d\w\s.,()%-]+$/', $raw ) ) {
				$safe_img[ $ik ] = $raw;
			}
		}
		if ( ! empty( $safe_img ) ) {
			$out['images'] = $safe_img;
		}
	}

	return wp_json_encode( $out );
}

/**
 * Sanitize JSON array of social profile URLs for gleo_social_links.
 *
 * @param mixed $value Raw option value.
 * @return string JSON-encoded URL list.
 */
/**
 * Sanitize JSON practice profile for gleo_practice_profile option.
 *
 * @param mixed $value Raw option value.
 * @return string JSON-encoded sanitized profile.
 */
function gleo_sanitize_practice_profile_json( $value ) {
	if ( is_string( $value ) && '' !== trim( $value ) ) {
		$decoded = json_decode( $value, true );
	} elseif ( is_array( $value ) ) {
		$decoded = $value;
	} else {
		$decoded = array();
	}
	if ( ! is_array( $decoded ) ) {
		return '';
	}

	$valid_types = array( 'dentist', 'physician', 'medical_clinic', 'other' );
	$days        = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	$out = array();

	$raw_type = sanitize_text_field( (string) ( $decoded['practice_type'] ?? '' ) );
	$out['practice_type'] = in_array( $raw_type, $valid_types, true ) ? $raw_type : '';

	$out['specialty']    = sanitize_text_field( (string) ( $decoded['specialty'] ?? '' ) );
	$out['booking_url']  = isset( $decoded['booking_url'] ) ? esc_url_raw( (string) $decoded['booking_url'] ) : '';

	// Locations (cap at 5)
	$out['locations'] = array();
	if ( ! empty( $decoded['locations'] ) && is_array( $decoded['locations'] ) ) {
		foreach ( array_slice( $decoded['locations'], 0, 5 ) as $loc ) {
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$clean_loc = array(
				'label'  => sanitize_text_field( (string) ( $loc['label']  ?? '' ) ),
				'street' => sanitize_text_field( (string) ( $loc['street'] ?? '' ) ),
				'city'   => sanitize_text_field( (string) ( $loc['city']   ?? '' ) ),
				'state'  => sanitize_text_field( (string) ( $loc['state']  ?? '' ) ),
				'zip'    => sanitize_text_field( (string) ( $loc['zip']    ?? '' ) ),
				'phone'  => sanitize_text_field( (string) ( $loc['phone']  ?? '' ) ),
				'hours'  => array(),
			);
			if ( ! empty( $loc['hours'] ) && is_array( $loc['hours'] ) ) {
				foreach ( $days as $day ) {
					if ( isset( $loc['hours'][ $day ] ) ) {
						$clean_loc['hours'][ $day ] = sanitize_text_field( (string) $loc['hours'][ $day ] );
					}
				}
			}
			$out['locations'][] = $clean_loc;
		}
	}

	// Providers (cap at 10)
	$out['providers'] = array();
	if ( ! empty( $decoded['providers'] ) && is_array( $decoded['providers'] ) ) {
		foreach ( array_slice( $decoded['providers'], 0, 10 ) as $prov ) {
			if ( ! is_array( $prov ) ) {
				continue;
			}
			$out['providers'][] = array(
				'name'        => sanitize_text_field( (string) ( $prov['name']        ?? '' ) ),
				'credentials' => sanitize_text_field( (string) ( $prov['credentials'] ?? '' ) ),
				'specialty'   => sanitize_text_field( (string) ( $prov['specialty']   ?? '' ) ),
			);
		}
	}

	// Insurance (cap at 20)
	$out['insurance_accepted'] = array();
	if ( ! empty( $decoded['insurance_accepted'] ) && is_array( $decoded['insurance_accepted'] ) ) {
		foreach ( array_slice( $decoded['insurance_accepted'], 0, 20 ) as $ins ) {
			$ins = sanitize_text_field( (string) $ins );
			if ( '' !== $ins ) {
				$out['insurance_accepted'][] = $ins;
			}
		}
	}

	// Target queries (cap at 10)
	$out['target_queries'] = array();
	if ( ! empty( $decoded['target_queries'] ) && is_array( $decoded['target_queries'] ) ) {
		foreach ( array_slice( $decoded['target_queries'], 0, 10 ) as $q ) {
			$q = sanitize_text_field( (string) $q );
			if ( '' !== $q ) {
				$out['target_queries'][] = $q;
			}
		}
	}

	return wp_json_encode( $out );
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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gleo-practice-profile.php';
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
			'practiceProfile' => class_exists( 'Gleo_Practice_Profile' ) ? Gleo_Practice_Profile::get() : array(),
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
