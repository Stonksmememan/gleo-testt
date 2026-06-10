<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic XML sitemap served at /sitemap.xml.
 */
class Gleo_Sitemap {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'serve_sitemap_xml' ) );
		add_filter( 'robots_txt', array( $this, 'append_sitemap_directive' ), 10, 2 );
	}

	/**
	 * Serve /sitemap.xml for indexable public routes.
	 *
	 * @return void
	 */
	public function serve_sitemap_xml() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( wp_parse_url( $request_uri, PHP_URL_PATH ) !== '/sitemap.xml' ) {
			return;
		}
		if ( ! get_option( 'blog_public' ) ) {
			status_header( 404 );
			exit;
		}

		$urls = $this->collect_indexable_urls();

		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $entry ) {
			echo "  <url>\n";
			echo '    <loc>' . esc_url( $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				echo '    <lastmod>' . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
			}
			if ( ! empty( $entry['changefreq'] ) ) {
				echo '    <changefreq>' . esc_html( $entry['changefreq'] ) . "</changefreq>\n";
			}
			if ( isset( $entry['priority'] ) ) {
				echo '    <priority>' . esc_html( (string) $entry['priority'] ) . "</priority>\n";
			}
			echo "  </url>\n";
		}

		echo "</urlset>\n";
		exit;
	}

	/**
	 * Collect indexable URLs with optional lastmod metadata.
	 *
	 * @return array<int, array{loc:string,lastmod?:string,changefreq?:string,priority?:float}>
	 */
	private function collect_indexable_urls() {
		$urls   = array();
		$seen   = array();

		$homepage      = home_url( '/' );
		$last_modified = get_lastpostmodified( 'GMT', 'post' );
		$homepage_mod  = $last_modified ? gmdate( 'c', strtotime( $last_modified . ' GMT' ) ) : gmdate( 'c' );
		$urls[]        = array(
			'loc'        => $homepage,
			'lastmod'    => $homepage_mod,
			'changefreq' => 'daily',
			'priority'   => 1.0,
		);
		$seen[ trailingslashit( $homepage ) ] = true;

		$post_types = Gleo_Schema::get_indexable_post_types();
		$query      = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 2000,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || ! Gleo_Schema::is_post_indexable( $post ) ) {
				continue;
			}
			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}
			$key = trailingslashit( $permalink );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$priority = 0.8;
			if ( 'page' === $post->post_type ) {
				$priority = 0.7;
			} elseif ( 'product' === $post->post_type ) {
				$priority = 0.75;
			}

			$urls[] = array(
				'loc'        => $permalink,
				'lastmod'    => get_post_modified_time( 'c', true, $post ),
				'changefreq' => 'weekly',
				'priority'   => $priority,
			);
		}

		return apply_filters( 'gleo_sitemap_urls', $urls );
	}

	/**
	 * Add Sitemap directive to robots.txt when the site is public.
	 *
	 * @param string $output Robots.txt output.
	 * @param bool   $public Whether search engines are discouraged.
	 * @return string
	 */
	public function append_sitemap_directive( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$sitemap_url = home_url( '/sitemap.xml' );
		if ( false !== stripos( $output, 'Sitemap:' ) ) {
			return $output;
		}
		return rtrim( $output ) . "\n\nSitemap: {$sitemap_url}\n";
	}
}

new Gleo_Sitemap();
