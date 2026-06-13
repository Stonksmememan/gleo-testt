<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized site metadata and JSON-LD schema utilities.
 */
class Gleo_Schema {

	/**
	 * Single source of truth for site-level metadata used across llms.txt, sitemap, and schema.
	 *
	 * @return array{
	 *   name: string,
	 *   description: string,
	 *   url: string,
	 *   logo: string,
	 *   social_links: string[]
	 * }
	 */
	public static function get_site_metadata() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$desc = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		$url  = home_url( '/' );

		$logo = (string) get_option( 'gleo_org_logo_url', '' );
		if ( '' === $logo ) {
			$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id > 0 ) {
				$logo_src = wp_get_attachment_image_url( $custom_logo_id, 'full' );
				if ( is_string( $logo_src ) && '' !== $logo_src ) {
					$logo = $logo_src;
				}
			}
		}
		if ( '' === $logo ) {
			$site_icon = get_site_icon_url( 512 );
			if ( is_string( $site_icon ) && '' !== $site_icon ) {
				$logo = $site_icon;
			}
		}

		$social = get_option( 'gleo_social_links', '' );
		$social_links = array();
		if ( is_string( $social ) && '' !== trim( $social ) ) {
			$decoded = json_decode( $social, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $link ) {
					$link = esc_url_raw( (string) $link );
					if ( '' !== $link ) {
						$social_links[] = $link;
					}
				}
			}
		}
		$social_links = array_values( array_unique( $social_links ) );

		return array(
			'name'         => $name,
			'description'  => $desc,
			'url'          => $url,
			'logo'         => esc_url_raw( $logo ),
			'social_links' => $social_links,
		);
	}

	/**
	 * Stable @id for the site Organization node.
	 *
	 * @return string
	 */
	public static function get_organization_id() {
		return trailingslashit( home_url( '/' ) ) . '#gleo-organization';
	}

	/**
	 * Stable @id for the site WebSite node.
	 *
	 * @return string
	 */
	public static function get_website_id() {
		return trailingslashit( home_url( '/' ) ) . '#gleo-website';
	}

	/**
	 * Post types included in discovery surfaces (sitemap, llms.txt key pages).
	 *
	 * @return string[]
	 */
	public static function get_indexable_post_types() {
		$types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		return apply_filters( 'gleo_indexable_post_types', $types );
	}

	/**
	 * Whether a post should appear in sitemap / discovery (public, published, not noindex).
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	public static function is_post_indexable( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::get_indexable_post_types(), true ) ) {
			return false;
		}
		if ( self::post_has_noindex( (int) $post->ID ) ) {
			return false;
		}
		return (bool) apply_filters( 'gleo_is_post_indexable', true, $post );
	}

	/**
	 * Detect noindex from common SEO plugins or core robots meta.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_has_noindex( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return true;
		}

		if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
			return true;
		}
		if ( is_string( $rm_robots ) && false !== stripos( $rm_robots, 'noindex' ) ) {
			return true;
		}

		$rm_advanced = get_post_meta( $post_id, 'rank_math_advanced_robots', true );
		if ( is_array( $rm_advanced ) && ! empty( $rm_advanced['noindex'] ) ) {
			return true;
		}

		return (bool) apply_filters( 'gleo_post_has_noindex', false, $post_id );
	}

	/**
	 * Whether Gleo should output JSON-LD on the current request.
	 *
	 * @param int $post_id Optional post ID for per-post override checks.
	 * @return bool
	 */
	public static function should_inject_schema( $post_id = 0 ) {
		if ( is_admin() || is_feed() || is_preview() ) {
			return false;
		}

		$post_id = (int) $post_id;
		$global_override = (bool) get_option( 'gleo_override_schema', false );
		$post_override   = $post_id > 0 ? (bool) get_post_meta( $post_id, '_gleo_schema_override', true ) : false;
		$override        = $global_override || $post_override;

		if ( ! $override && self::seo_plugin_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * @return bool
	 */
	public static function seo_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'seo-by-rank-math/rank-math.php' );
	}

	/**
	 * Stable @id for the practice/business node (used when practice profile is configured).
	 *
	 * @return string
	 */
	public static function get_practice_id() {
		return trailingslashit( home_url( '/' ) ) . '#gleo-practice';
	}

	/**
	 * Build a healthcare-typed schema node (Dentist, MedicalClinic, LocalBusiness) from the
	 * practice profile. Returns null when the profile is incomplete.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function build_practice_schema() {
		if ( ! class_exists( 'Gleo_Practice_Profile' ) ) {
			return null;
		}
		$profile = Gleo_Practice_Profile::get();
		if ( ! Gleo_Practice_Profile::is_set( $profile ) ) {
			return null;
		}

		$meta       = self::get_site_metadata();
		$schema_type = Gleo_Practice_Profile::get_schema_type( $profile['practice_type'] );

		$practice = array(
			'@type' => $schema_type,
			'@id'   => self::get_practice_id(),
			'name'  => $meta['name'],
			'url'   => $meta['url'],
		);

		if ( '' !== $meta['description'] ) {
			$practice['description'] = $meta['description'];
		}
		if ( '' !== $meta['logo'] ) {
			$practice['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $meta['logo'],
			);
		}
		if ( ! empty( $meta['social_links'] ) ) {
			$practice['sameAs'] = $meta['social_links'];
		}
		if ( '' !== $profile['specialty'] ) {
			$practice['medicalSpecialty'] = $profile['specialty'];
		}
		if ( '' !== $profile['booking_url'] ) {
			$practice['potentialAction'] = array(
				'@type'  => 'ReserveAction',
				'name'   => 'Book Appointment',
				'target' => esc_url_raw( $profile['booking_url'] ),
			);
		}

		// Primary location → PostalAddress + telephone + openingHoursSpecification
		if ( ! empty( $profile['locations'] ) ) {
			$loc = $profile['locations'][0];
			if ( is_array( $loc ) ) {
				$addr = array( '@type' => 'PostalAddress' );
				if ( ! empty( $loc['street'] ) ) {
					$addr['streetAddress'] = $loc['street'];
				}
				if ( ! empty( $loc['city'] ) ) {
					$addr['addressLocality'] = $loc['city'];
				}
				if ( ! empty( $loc['state'] ) ) {
					$addr['addressRegion'] = $loc['state'];
				}
				if ( ! empty( $loc['zip'] ) ) {
					$addr['postalCode'] = $loc['zip'];
				}
				$addr['addressCountry'] = 'US';
				$practice['address']    = $addr;

				if ( ! empty( $loc['phone'] ) ) {
					$practice['telephone'] = $loc['phone'];
				}

				if ( ! empty( $loc['hours'] ) && is_array( $loc['hours'] ) ) {
					$specs = Gleo_Practice_Profile::build_opening_hours_spec( $loc['hours'] );
					if ( ! empty( $specs ) ) {
						$practice['openingHoursSpecification'] = $specs;
					}
				}
			}
		}

		// Providers → employee nodes
		if ( ! empty( $profile['providers'] ) ) {
			$employees = array();
			foreach ( $profile['providers'] as $prov ) {
				if ( ! is_array( $prov ) || empty( $prov['name'] ) ) {
					continue;
				}
				$employee = array(
					'@type' => 'Physician' === $schema_type ? 'Physician' : 'Person',
					'name'  => $prov['name'],
				);
				if ( ! empty( $prov['credentials'] ) ) {
					$employee['honorificSuffix'] = $prov['credentials'];
				}
				if ( ! empty( $prov['specialty'] ) ) {
					$employee['jobTitle'] = $prov['specialty'];
				}
				$employees[] = $employee;
			}
			if ( ! empty( $employees ) ) {
				$practice['employee'] = count( $employees ) === 1 ? $employees[0] : $employees;
			}
		}

		// Insurance
		if ( ! empty( $profile['insurance_accepted'] ) ) {
			$practice['paymentAccepted'] = implode( ', ', $profile['insurance_accepted'] );
		}

		return $practice;
	}

	/**
	 * Build Organization schema node.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_organization_schema() {
		$meta = self::get_site_metadata();
		$org  = array(
			'@type' => 'Organization',
			'@id'   => self::get_organization_id(),
			'name'  => $meta['name'],
			'url'   => $meta['url'],
		);
		if ( '' !== $meta['description'] ) {
			$org['description'] = $meta['description'];
		}
		if ( '' !== $meta['logo'] ) {
			$org['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $meta['logo'],
			);
		}
		if ( ! empty( $meta['social_links'] ) ) {
			$org['sameAs'] = $meta['social_links'];
		}
		return $org;
	}

	/**
	 * Build WebSite schema node.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_website_schema() {
		$meta = self::get_site_metadata();
		$site = array(
			'@type'     => 'WebSite',
			'@id'       => self::get_website_id(),
			'url'       => $meta['url'],
			'name'      => $meta['name'],
			'publisher' => array( '@id' => self::get_organization_id() ),
		);
		if ( '' !== $meta['description'] ) {
			$site['description'] = $meta['description'];
		}
		$site['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		);
		return $site;
	}

	/**
	 * Organization + WebSite graph for homepage / site layout.
	 *
	 * When a practice profile is configured, the Organization node is replaced with
	 * the appropriate healthcare type (Dentist, MedicalClinic, etc.) and the WebSite
	 * publisher pointer is updated to reference it.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_site_graph_schema() {
		$practice = self::build_practice_schema();

		if ( null !== $practice ) {
			$website = self::build_website_schema();
			// Redirect the publisher pointer to the practice node.
			$website['publisher'] = array( '@id' => self::get_practice_id() );

			return array(
				'@context' => 'https://schema.org',
				'@graph'   => array( $practice, $website ),
			);
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				self::build_organization_schema(),
				self::build_website_schema(),
			),
		);
	}

	/**
	 * Fetch stored scan JSON-LD for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_post_schema_from_scan( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gleo_scans';
		$scan  = $wpdb->get_row( $wpdb->prepare(
			"SELECT scan_result FROM {$table} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1",
			$post_id
		) );
		if ( ! $scan || ! $scan->scan_result ) {
			return null;
		}
		$result = json_decode( $scan->scan_result, true );
		if ( ! is_array( $result ) || empty( $result['json_ld_schema'] ) || ! is_array( $result['json_ld_schema'] ) ) {
			return null;
		}
		return $result['json_ld_schema'];
	}

	/**
	 * Fallback Article / BlogPosting schema when no scan payload exists.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	public static function build_article_schema( WP_Post $post ) {
		$meta = self::get_site_metadata();
		$desc = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' );
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BlogPosting',
			'headline'        => wp_strip_all_tags( $post->post_title ),
			'description'     => wp_strip_all_tags( $desc ),
			'datePublished'   => get_the_date( 'c', $post ),
			'dateModified'    => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
			'author'          => array(
				'@type' => 'Organization',
				'@id'   => self::get_organization_id(),
				'name'  => $meta['name'],
			),
			'publisher'       => array(
				'@type' => 'Organization',
				'@id'   => self::get_organization_id(),
				'name'  => $meta['name'],
			),
		);
		if ( '' !== $meta['logo'] ) {
			$schema['publisher']['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $meta['logo'],
			);
		}
		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		if ( is_string( $thumb ) && '' !== $thumb ) {
			$schema['image'] = esc_url_raw( $thumb );
		}
		return $schema;
	}

	/**
	 * WebPage schema for static pages.
	 *
	 * @param WP_Post $post Page post object.
	 * @return array<string, mixed>
	 */
	public static function build_webpage_schema( WP_Post $post ) {
		$meta = self::get_site_metadata();
		$desc = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' );
		return array(
			'@context'    => 'https://schema.org',
			'@type'       => 'WebPage',
			'@id'         => get_permalink( $post ),
			'url'         => get_permalink( $post ),
			'name'        => wp_strip_all_tags( $post->post_title ),
			'description' => wp_strip_all_tags( $desc ),
			'isPartOf'    => array( '@id' => self::get_website_id() ),
			'publisher'   => array( '@id' => self::get_organization_id() ),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'inLanguage'    => get_bloginfo( 'language' ),
		);
	}

	/**
	 * Product schema for WooCommerce products.
	 *
	 * @param WP_Post $post Product post object.
	 * @return array<string, mixed>|null
	 */
	public static function build_product_schema( WP_Post $post ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return null;
		}
		$meta = self::get_site_metadata();
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'@id'         => get_permalink( $post ) . '#product',
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'url'         => get_permalink( $post ),
			'sku'         => $product->get_sku() ?: null,
			'brand'       => array(
				'@type' => 'Organization',
				'name'  => $meta['name'],
			),
		);
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$img = wp_get_attachment_image_url( $image_id, 'full' );
			if ( is_string( $img ) && '' !== $img ) {
				$schema['image'] = esc_url_raw( $img );
			}
		}
		$price = $product->get_price();
		if ( '' !== $price && is_numeric( $price ) ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'url'           => get_permalink( $post ),
				'price'         => $price,
				'priceCurrency' => get_woocommerce_currency(),
				'availability'  => $product->is_in_stock()
					? 'https://schema.org/InStock'
					: 'https://schema.org/OutOfStock',
			);
		}
		return array_filter( $schema, static function ( $value ) {
			return null !== $value && '' !== $value;
		} );
	}

	/**
	 * Resolve the JSON-LD payload for the current singular view.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_singular_schema( WP_Post $post ) {
		if ( 'post' === $post->post_type ) {
			$scan_schema = self::get_post_schema_from_scan( $post->ID );
			return $scan_schema ?: self::build_article_schema( $post );
		}
		if ( 'page' === $post->post_type ) {
			return self::build_webpage_schema( $post );
		}
		if ( 'product' === $post->post_type ) {
			return self::build_product_schema( $post );
		}
		return null;
	}

	/**
	 * Output a JSON-LD script block.
	 *
	 * @param array<string, mixed> $schema Schema payload.
	 * @param string               $comment Optional HTML comment label.
	 * @return void
	 */
	public static function render_json_ld( $schema, $comment = 'Gleo GEO Schema' ) {
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}
		if ( '' !== $comment ) {
			echo "\n<!-- " . esc_html( $comment ) . " -->\n";
		}
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	/**
	 * Merge Organization + publisher wiring into stored scan JSON-LD.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function enrich_scan_json_ld( $post_id, WP_Post $post ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gleo_scans';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT scan_result FROM {$table} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1", $post_id ) );
		if ( ! $row || ! $row->scan_result ) {
			return;
		}
		$data = json_decode( $row->scan_result, true );
		if ( ! is_array( $data ) ) {
			return;
		}
		if ( empty( $data['json_ld_schema'] ) || ! is_array( $data['json_ld_schema'] ) ) {
			$data['json_ld_schema'] = array(
				'@context' => 'https://schema.org',
				'@type'    => 'Article',
				'headline' => wp_strip_all_tags( $post->post_title ),
			);
		}
		$schema = $data['json_ld_schema'];
		$graph  = array();
		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			$graph = $schema['@graph'];
		} elseif ( isset( $schema['@type'] ) ) {
			$graph[] = $schema;
		} else {
			return;
		}

		$org_id  = self::get_organization_id();
		$has_org = false;
		foreach ( $graph as $node ) {
			if ( empty( $node['@type'] ) ) {
				continue;
			}
			$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( in_array( 'Organization', $types, true ) ) {
				$has_org = true;
				break;
			}
		}
		if ( ! $has_org ) {
			$graph[] = self::build_organization_schema();
		}

		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle' );
		foreach ( $graph as &$node ) {
			if ( empty( $node['@type'] ) ) {
				continue;
			}
			$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			$is_article = (bool) array_intersect( $article_types, $types );
			if ( $is_article ) {
				$node['publisher'] = array( '@id' => $org_id );
				if ( empty( $node['mainEntityOfPage'] ) ) {
					$node['mainEntityOfPage'] = array(
						'@type' => 'WebPage',
						'@id'   => get_permalink( $post_id ),
					);
				}
			}
		}
		unset( $node );

		$data['json_ld_schema'] = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
		$wpdb->update(
			$table,
			array( 'scan_result' => wp_json_encode( $data ) ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Plain-text safe string for llms.txt output.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function plain_text( $value ) {
		return str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) );
	}
}
