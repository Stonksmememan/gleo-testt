<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scrapes live site pages for llms.txt context; merges with practice profile (profile overrides scrape).
 *
 * Prefers best-effort automatic llms.txt generation over manual Practice Profile entry.
 */
class Gleo_Llms_Scraper {

	const CACHE_KEY = 'gleo_llms_merged_profile';

	const SCRAPE_CACHE_KEY = 'gleo_llms_scraped_context';

	const CACHE_TTL = DAY_IN_SECONDS;

	const MAX_PAGES = 15;

	const MIN_MEANINGFUL_PAGES = 3;

	const BUSINESS_TYPES = array(
		'Dentist',
		'Physician',
		'MedicalClinic',
		'MedicalBusiness',
		'Hospital',
		'LocalBusiness',
		'HealthAndBeautyBusiness',
		'Organization',
	);

	const SERVICE_URL_PATTERNS = array(
		'/services/',
		'/treatments/',
		'/procedures/',
		'/care/',
		'/solutions/',
	);

	const ABOUT_SLUGS = array(
		'about',
		'about-us',
		'team',
		'our-team',
		'meet-the-team',
		'providers',
		'doctors',
		'our-doctors',
		'doctor',
		'provider',
		'staff',
		'practice',
		'company',
		'our-practice',
	);

	const CONTACT_SLUGS = array(
		'contact',
		'contact-us',
		'locations',
		'location',
		'office',
		'find-us',
	);

	const DEPRIORITIZE_PATTERNS = array(
		'privacy',
		'terms',
		'cookie',
		'archive',
		'search',
		'/page/',
		'/tag/',
		'/author/',
		'/category/',
		'wp-login',
		'cart',
		'checkout',
	);

	/**
	 * Missing section keys for a merged or scraped context array.
	 *
	 * @param array $context Profile-shaped context.
	 * @return string[]
	 */
	public static function compute_missing_sections( $context ) {
		return self::detect_missing_sections( is_array( $context ) ? $context : array() );
	}

	/**
	 * Merged profile for llms.txt (cached).
	 *
	 * @return array
	 */
	public static function get_merged_profile() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$scraped = self::get_scraped_context();
		$profile = class_exists( 'Gleo_Practice_Profile' ) ? Gleo_Practice_Profile::get() : array();
		$merged  = class_exists( 'Gleo_Practice_Profile' )
			? Gleo_Practice_Profile::merge_with_scraped( $scraped, $profile )
			: $scraped;

		set_transient( self::CACHE_KEY, $merged, self::CACHE_TTL );
		return $merged;
	}

	/**
	 * Cached raw scrape context (no saved profile merged in).
	 *
	 * @return array
	 */
	public static function get_scraped_context() {
		$cached = get_transient( self::SCRAPE_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$scraped = self::scrape_site_context();
		set_transient( self::SCRAPE_CACHE_KEY, $scraped, self::CACHE_TTL );
		return $scraped;
	}

	/**
	 * Scrape metadata for admin UI (confidence, missing sections).
	 *
	 * @return array
	 */
	public static function get_scrape_metadata() {
		$merged = self::get_merged_profile();
		return array(
			'confidence'        => $merged['confidence'] ?? self::default_confidence(),
			'missing_sections'  => $merged['missing_sections'] ?? array(),
			'can_generate_llms' => ! empty( $merged['can_generate_llms'] ),
			'generation_mode'   => $merged['generation_mode'] ?? 'manual',
			'pages_discovered'  => (int) ( $merged['pages_discovered'] ?? 0 ),
		);
	}

	/**
	 * Clear cached merged profile (call after profile save, publish, or scan).
	 */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::SCRAPE_CACHE_KEY );
	}

	/**
	 * Scrape homepage and discovered pages into a practice-profile-shaped array.
	 *
	 * @return array
	 */
	public static function scrape_site_context() {
		$context = self::empty_context();
		$pages   = self::discover_pages();

		if ( empty( $pages ) ) {
			self::finalize_context( $context, 0 );
			return $context;
		}

		$meaningful = 0;
		$all_html   = array();

		foreach ( $pages as $page_info ) {
			$url  = $page_info['url'];
			$body = self::fetch_url( $url );
			if ( '' === $body ) {
				continue;
			}

			$meaningful++;
			$all_html[] = $body;
			$type       = $page_info['type'];

			if ( 'homepage' === $type ) {
				self::parse_homepage_analysis( $body, $context, $page_info );
			}

			if ( in_array( $type, array( 'service', 'about', 'contact', 'location', 'provider' ), true ) ) {
				self::parse_typed_page( $body, $context, $page_info );
			}

			$summary = self::summarize_page( $body, $page_info['title'] );
			if ( '' !== $summary ) {
				$context['page_summaries'][] = array(
					'title'   => sanitize_text_field( $page_info['title'] ),
					'url'     => esc_url_raw( $url ),
					'summary' => $summary,
					'type'    => $type,
					'score'   => (int) $page_info['score'],
				);
			}
		}

		if ( ! empty( $all_html ) ) {
			$combined = implode( "\n<!-- gleo-page-break -->\n", $all_html );
			self::parse_json_ld( $combined, $context );
			self::parse_html_signals( $combined, $context );
		}

		self::group_services( $context );
		self::infer_missing_data( $context );
		self::finalize_context( $context, $meaningful );

		return $context;
	}

	/**
	 * @return array
	 */
	private static function empty_context() {
		return array(
			'business_name'        => '',
			'business_description' => '',
			'trust_indicators'     => array(),
			'practice_type'        => '',
			'specialty'            => '',
			'services'             => array(),
			'about'                => array(
				'mission'          => '',
				'expertise'        => '',
				'differentiators'  => array(),
			),
			'locations'            => array(),
			'providers'            => array(),
			'insurance_accepted'   => array(),
			'booking_url'          => '',
			'target_queries'     => array(),
			'page_summaries'       => array(),
			'confidence'           => self::default_confidence(),
			'missing_sections'     => array(),
			'can_generate_llms'    => false,
			'generation_mode'      => 'manual',
			'pages_discovered'     => 0,
		);
	}

	/**
	 * @return array{business_description: float, services: float, providers: float, contact: float}
	 */
	private static function default_confidence() {
		return array(
			'business_description' => 0.0,
			'services'             => 0.0,
			'providers'            => 0.0,
			'contact'              => 0.0,
		);
	}

	/**
	 * Discover and score pages for scraping.
	 *
	 * @return array<int, array{url: string, title: string, type: string, score: int}>
	 */
	private static function discover_pages() {
		$home_url = trailingslashit( home_url( '/' ) );
		$scored   = array();

		$scored[] = array(
			'url'   => $home_url,
			'title' => get_bloginfo( 'name' ),
			'type'  => 'homepage',
			'score' => 100,
		);

		$nav_labels = self::extract_nav_labels_from_homepage();

		$wp_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 80,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		foreach ( $wp_pages as $page ) {
			if ( ! class_exists( 'Gleo_Schema' ) || ! Gleo_Schema::is_post_indexable( $page ) ) {
				continue;
			}

			$link = get_permalink( $page );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}

			$key = trailingslashit( $link );
			if ( $key === $home_url ) {
				continue;
			}

			if ( self::is_deprioritized( $page->post_name, $link ) ) {
				continue;
			}

			$type  = self::classify_page( $page->post_name, $page->post_title, $link );
			$score = self::score_page( $page, $link, $type, $nav_labels );

			if ( $score < 1 ) {
				continue;
			}

			$scored[] = array(
				'url'   => $link,
				'title' => $page->post_title,
				'type'  => $type,
				'score' => $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		$seen = array();
		$out  = array();

		foreach ( $scored as $item ) {
			$key = trailingslashit( $item['url'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= self::MAX_PAGES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string $slug Page slug.
	 * @param string $url  Page URL.
	 * @return bool
	 */
	private static function is_deprioritized( $slug, $url ) {
		$haystack = strtolower( $slug . ' ' . $url );
		foreach ( self::DEPRIORITIZE_PATTERNS as $pattern ) {
			if ( false !== strpos( $haystack, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		if ( preg_match( '/\/page\/\d+/i', $url ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $slug  Page slug.
	 * @param string $title Page title.
	 * @param string $url   Page URL.
	 * @return string Page type slug.
	 */
	private static function classify_page( $slug, $title, $url ) {
		$haystack = strtolower( $slug . ' ' . $title . ' ' . $url );

		foreach ( self::SERVICE_URL_PATTERNS as $pattern ) {
			if ( false !== strpos( strtolower( $url ), $pattern ) ) {
				return 'service';
			}
		}
		if ( preg_match( '/\b(services?|treatments?|procedures?|solutions?|care)\b/i', $haystack ) ) {
			return 'service';
		}

		foreach ( self::CONTACT_SLUGS as $needle ) {
			if ( $slug === $needle || false !== strpos( $slug, $needle ) ) {
				if ( false !== strpos( $haystack, 'location' ) || false !== strpos( $haystack, 'office' ) ) {
					return 'location';
				}
				return 'contact';
			}
		}

		foreach ( self::ABOUT_SLUGS as $needle ) {
			if ( $slug === $needle || false !== strpos( $slug, $needle ) ) {
				if ( preg_match( '/\b(team|doctor|provider|staff)\b/i', $haystack ) ) {
					return 'provider';
				}
				return 'about';
			}
		}

		if ( preg_match( '/\b(contact|location|office|hours|find us)\b/i', $haystack ) ) {
			return 'contact';
		}

		return 'page';
	}

	/**
	 * @param WP_Post $page       Page object.
	 * @param string  $url        Permalink.
	 * @param string  $type       Classified type.
	 * @param array   $nav_labels Nav link text from homepage.
	 * @return int
	 */
	private static function score_page( $page, $url, $type, $nav_labels ) {
		$score = 5;
		$title = strtolower( $page->post_title );
		$slug  = strtolower( $page->post_name );

		switch ( $type ) {
			case 'service':
				$score += 35;
				break;
			case 'homepage':
				$score += 50;
				break;
			case 'about':
			case 'provider':
				$score += 28;
				break;
			case 'contact':
			case 'location':
				$score += 25;
				break;
			default:
				$score += 3;
		}

		foreach ( $nav_labels as $label ) {
			$label = strtolower( trim( $label ) );
			if ( '' === $label ) {
				continue;
			}
			if ( false !== strpos( $title, $label ) || false !== strpos( $slug, sanitize_title( $label ) ) ) {
				$score += 20;
				break;
			}
		}

		$word_count = str_word_count( wp_strip_all_tags( $page->post_content ) );
		if ( $word_count > 200 ) {
			$score += 10;
		} elseif ( $word_count > 80 ) {
			$score += 5;
		}

		if ( 0 === (int) $page->menu_order && 0 === (int) $page->post_parent ) {
			$score += 5;
		}

		return $score;
	}

	/**
	 * Extract navigation link labels from homepage HTML.
	 *
	 * @return string[]
	 */
	private static function extract_nav_labels_from_homepage() {
		$html = self::fetch_url( home_url( '/' ) );
		if ( '' === $html ) {
			return array();
		}

		$labels = array();
		if ( preg_match_all( '/<nav[^>]*>(.*?)<\/nav>/is', $html, $nav_blocks ) ) {
			foreach ( $nav_blocks[1] as $block ) {
				if ( preg_match_all( '/<a[^>]*>(.*?)<\/a>/is', $block, $links ) ) {
					foreach ( $links[1] as $text ) {
						$text = trim( wp_strip_all_tags( $text ) );
						if ( '' !== $text && strlen( $text ) < 60 ) {
							$labels[] = $text;
						}
					}
				}
			}
		}
		return array_values( array_unique( $labels ) );
	}

	/**
	 * @param string $url Page URL.
	 * @return string HTML body or empty string.
	 */
	private static function fetch_url( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $response );
		return is_string( $body ) ? $body : '';
	}

	/**
	 * Homepage-specific extraction: business name, description, services, trust signals.
	 *
	 * @param string $html      Homepage HTML.
	 * @param array  $context   Context (by reference).
	 * @param array  $page_info Page metadata.
	 */
	private static function parse_homepage_analysis( $html, &$context, $page_info ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return;
		}
		$xpath = new DOMXPath( $dom );

		$name = self::extract_business_name( $xpath, $html );
		if ( '' !== $name ) {
			$context['business_name'] = $name;
		}

		$desc = self::extract_site_description( $xpath, $html );
		if ( '' !== $desc ) {
			$context['business_description'] = $desc;
		}

		$services = self::extract_homepage_services( $xpath, $html );
		if ( ! empty( $services ) ) {
			$context['services'] = self::merge_service_lists( $services, $context['services'] );
		}

		$trust = self::extract_trust_indicators( $html );
		if ( ! empty( $trust ) ) {
			$context['trust_indicators'] = self::merge_string_lists( $trust, $context['trust_indicators'], 10 );
		}

		$hero = self::extract_hero_text( $xpath );
		if ( '' !== $hero && '' === $context['business_description'] ) {
			$context['business_description'] = self::truncate_sentences( $hero, 2 );
		}
	}

	/**
	 * Parse about, service, contact, and provider pages.
	 *
	 * @param string $html      Page HTML.
	 * @param array  $context   Context (by reference).
	 * @param array  $page_info Page metadata.
	 */
	private static function parse_typed_page( $html, &$context, $page_info ) {
		$type  = $page_info['type'];
		$title = $page_info['title'];
		$url   = $page_info['url'];

		if ( 'service' === $type ) {
			$summary = self::summarize_page( $html, $title );
			$context['services'] = self::merge_service_lists(
				array(
					array(
						'name'        => sanitize_text_field( $title ),
						'summary'     => $summary,
						'url'         => esc_url_raw( $url ),
						'group'       => self::service_group_from_url( $url ),
						'importance'  => (int) $page_info['score'],
					),
				),
				$context['services']
			);
		}

		if ( in_array( $type, array( 'about', 'provider' ), true ) ) {
			$dom = self::load_dom( $html );
			if ( $dom ) {
				$xpath = new DOMXPath( $dom );
				self::extract_providers_from_dom( $xpath, $context );
				$intro = self::extract_intro_content( $xpath );
				if ( '' !== $intro ) {
					if ( 'about' === $type && '' === $context['about']['mission'] ) {
						$context['about']['mission'] = self::truncate_sentences( $intro, 2 );
					}
					if ( '' === $context['about']['expertise'] ) {
						$context['about']['expertise'] = self::truncate_sentences( $intro, 2 );
					}
				}
			}
		}

		if ( in_array( $type, array( 'contact', 'location' ), true ) ) {
			$dom = self::load_dom( $html );
			if ( $dom ) {
				self::extract_contact_from_dom( new DOMXPath( $dom ), $context );
			}
		}
	}

	/**
	 * @param string $html  Page HTML.
	 * @param string $title Page title.
	 * @return string 1–2 sentence summary.
	 */
	private static function summarize_page( $html, $title ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return '';
		}
		$xpath = new DOMXPath( $dom );

		$meta_desc = '';
		$meta_nodes = $xpath->query( '//meta[@name="description"]/@content' );
		if ( $meta_nodes instanceof DOMNodeList && $meta_nodes->length > 0 ) {
			$meta_desc = trim( $meta_nodes->item( 0 )->nodeValue );
		}
		if ( '' === $meta_desc ) {
			$og_nodes = $xpath->query( '//meta[@property="og:description"]/@content' );
			if ( $og_nodes instanceof DOMNodeList && $og_nodes->length > 0 ) {
				$meta_desc = trim( $og_nodes->item( 0 )->nodeValue );
			}
		}

		$h1 = '';
		$h1_nodes = $xpath->query( '//h1' );
		if ( $h1_nodes instanceof DOMNodeList && $h1_nodes->length > 0 ) {
			$h1 = trim( wp_strip_all_tags( $h1_nodes->item( 0 )->textContent ) );
		}

		$intro = self::extract_intro_content( $xpath );

		$parts = array();
		if ( '' !== $meta_desc ) {
			$parts[] = $meta_desc;
		} elseif ( '' !== $intro ) {
			$parts[] = $intro;
		} elseif ( '' !== $h1 && strcasecmp( $h1, $title ) !== 0 ) {
			$parts[] = $h1;
		}

		if ( empty( $parts ) && '' !== $title ) {
			return sanitize_text_field( $title ) . '.';
		}

		return self::truncate_sentences( implode( ' ', $parts ), 2 );
	}

	/**
	 * @param string $text Raw text.
	 * @param int    $max  Max sentences.
	 * @return string
	 */
	private static function truncate_sentences( $text, $max = 2 ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match_all( '/[^.!?]+[.!?]+/', $text, $matches ) ) {
			$sentences = array_slice( array_map( 'trim', $matches[0] ), 0, $max );
			return sanitize_text_field( implode( ' ', $sentences ) );
		}
		if ( strlen( $text ) > 240 ) {
			return sanitize_text_field( substr( $text, 0, 237 ) . '…' );
		}
		return sanitize_text_field( $text );
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @return string
	 */
	private static function extract_intro_content( $xpath ) {
		$paragraphs = $xpath->query( '//main//p | //article//p | //div[contains(@class,"content")]//p | //body//p' );
		if ( ! ( $paragraphs instanceof DOMNodeList ) ) {
			return '';
		}
		foreach ( $paragraphs as $p ) {
			$text = trim( $p->textContent );
			if ( strlen( $text ) >= 40 && strlen( $text ) <= 600 ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @return string
	 */
	private static function extract_hero_text( $xpath ) {
		$selectors = array(
			'//*[contains(@class,"hero")]//p',
			'//*[contains(@class,"banner")]//p',
			'//header//p',
			'//main//p',
		);
		foreach ( $selectors as $sel ) {
			$nodes = $xpath->query( $sel );
			if ( ! ( $nodes instanceof DOMNodeList ) ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$text = trim( $node->textContent );
				if ( strlen( $text ) >= 30 ) {
					return $text;
				}
			}
		}
		return '';
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @param string   $html  Full HTML for regex fallbacks.
	 * @return string
	 */
	private static function extract_business_name( $xpath, $html ) {
		$candidates = array();

		$og = $xpath->query( '//meta[@property="og:site_name"]/@content' );
		if ( $og instanceof DOMNodeList && $og->length > 0 ) {
			$candidates[] = trim( $og->item( 0 )->nodeValue );
		}

		$title_nodes = $xpath->query( '//title' );
		if ( $title_nodes instanceof DOMNodeList && $title_nodes->length > 0 ) {
			$title = trim( $title_nodes->item( 0 )->textContent );
			$title = preg_replace( '/\s*[|\-–—]\s*.+$/u', '', $title );
			$candidates[] = trim( $title );
		}

		$h1_nodes = $xpath->query( '//h1' );
		if ( $h1_nodes instanceof DOMNodeList && $h1_nodes->length > 0 ) {
			$candidates[] = trim( wp_strip_all_tags( $h1_nodes->item( 0 )->textContent ) );
		}

		$logo_imgs = $xpath->query( '//img[contains(@class,"logo") or contains(@class,"custom-logo") or contains(@id,"logo")]' );
		if ( $logo_imgs instanceof DOMNodeList ) {
			foreach ( $logo_imgs as $img ) {
				$alt = trim( $img->getAttribute( 'alt' ) );
				if ( '' !== $alt && strlen( $alt ) >= 3 ) {
					$candidates[] = $alt;
				}
			}
		}

		foreach ( $candidates as $name ) {
			$name = sanitize_text_field( $name );
			if ( '' !== $name && strlen( $name ) >= 3 && strlen( $name ) <= 120 ) {
				return $name;
			}
		}

		$blog_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return sanitize_text_field( $blog_name );
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @param string   $html  HTML.
	 * @return string
	 */
	private static function extract_site_description( $xpath, $html ) {
		$meta_nodes = $xpath->query( '//meta[@name="description"]/@content' );
		if ( $meta_nodes instanceof DOMNodeList && $meta_nodes->length > 0 ) {
			$desc = trim( $meta_nodes->item( 0 )->nodeValue );
			if ( strlen( $desc ) >= 20 ) {
				return sanitize_text_field( $desc );
			}
		}

		$og_nodes = $xpath->query( '//meta[@property="og:description"]/@content' );
		if ( $og_nodes instanceof DOMNodeList && $og_nodes->length > 0 ) {
			$desc = trim( $og_nodes->item( 0 )->nodeValue );
			if ( strlen( $desc ) >= 20 ) {
				return sanitize_text_field( $desc );
			}
		}

		$tagline = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		if ( '' !== trim( $tagline ) ) {
			return sanitize_text_field( $tagline );
		}

		return '';
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @param string   $html  HTML.
	 * @return array<int, array{name: string, summary: string, url: string, group: string, importance: int}>
	 */
	private static function extract_homepage_services( $xpath, $html ) {
		$services = array();

		$sections = $xpath->query(
			'//*[contains(translate(@class,"SERVICE","service"),"service") or contains(translate(@id,"SERVICE","service"),"service")]//a[@href]'
		);
		if ( $sections instanceof DOMNodeList ) {
			foreach ( $sections as $link ) {
				$href = $link->getAttribute( 'href' );
				$text = trim( wp_strip_all_tags( $link->textContent ) );
				if ( '' === $text || strlen( $text ) < 3 || strlen( $text ) > 80 ) {
					continue;
				}
				$url = self::normalize_internal_url( $href );
				if ( '' === $url ) {
					continue;
				}
				$services[] = array(
					'name'       => sanitize_text_field( $text ),
					'summary'  => '',
					'url'      => $url,
					'group'    => 'General',
					'importance' => 15,
				);
			}
		}

		$cards = $xpath->query( '//*[contains(@class,"card") or contains(@class,"column") or contains(@class,"grid")]//h2 | //*[contains(@class,"card") or contains(@class,"column") or contains(@class,"grid")]//h3' );
		if ( $cards instanceof DOMNodeList && $cards->length > 0 && $cards->length <= 12 ) {
			foreach ( $cards as $heading ) {
				$text = trim( wp_strip_all_tags( $heading->textContent ) );
				if ( strlen( $text ) < 4 || strlen( $text ) > 80 ) {
					continue;
				}
				if ( preg_match( '/\b(welcome|about|contact|blog|news|read more)\b/i', $text ) ) {
					continue;
				}
				$services[] = array(
					'name'       => sanitize_text_field( $text ),
					'summary'  => '',
					'url'      => '',
					'group'    => 'Featured',
					'importance' => 12,
				);
			}
		}

		return $services;
	}

	/**
	 * @param string $html HTML content.
	 * @return string[]
	 */
	private static function extract_trust_indicators( $html ) {
		$text  = wp_strip_all_tags( $html );
		$found = array();

		$patterns = array(
			'/\b(\d{1,3}\+?\s*years?\s+(?:of\s+)?(?:experience|in\s+business|serving))\b/i',
			'/\b(board[- ]certified[^.]{0,60})\b/i',
			'/\b(fellow\s+of\s+[^.]{5,50})\b/i',
			'/\b(award[- ]winning[^.]{0,50})\b/i',
			'/\b(state[- ]of[- ]the[- ]art[^.]{0,40})\b/i',
			'/\b(patient[- ]centered[^.]{0,40})\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$found[] = sanitize_text_field( trim( $m[1] ) );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @param array    $context Context (by reference).
	 */
	private static function extract_providers_from_dom( $xpath, &$context ) {
		$headings = $xpath->query( '//*[self::h2 or self::h3 or self::h4][contains(@class,"team") or contains(@class,"doctor") or contains(@class,"provider") or contains(@class,"staff")]' );
		if ( ! ( $headings instanceof DOMNodeList ) || 0 === $headings->length ) {
			$headings = $xpath->query( '//*[contains(@class,"team-member") or contains(@class,"provider") or contains(@class,"doctor-card")]' );
		}

		$name_pattern = '/^(Dr\.?\s+)?[A-Z][a-z]+(?:\s+[A-Z][a-z\'-]+){1,3}(?:,?\s*(?:DDS|DMD|MD|DO|PhD|PA-C|NP|RN))?$/';

		if ( $headings instanceof DOMNodeList ) {
			foreach ( $headings as $node ) {
				$name = trim( wp_strip_all_tags( $node->textContent ) );
				if ( preg_match( $name_pattern, $name ) ) {
					$provider = array(
						'name'        => sanitize_text_field( $name ),
						'credentials' => '',
						'specialty'   => '',
					);
					$context['providers'] = self::merge_provider_lists( array( $provider ), $context['providers'] );
				}
			}
		}

		$cards = $xpath->query( '//*[contains(@class,"team") or contains(@class,"provider") or contains(@class,"doctor")]//h3 | //*[contains(@class,"team") or contains(@class,"provider") or contains(@class,"doctor")]//h4' );
		if ( $cards instanceof DOMNodeList ) {
			foreach ( $cards as $node ) {
				$name = trim( wp_strip_all_tags( $node->textContent ) );
				if ( strlen( $name ) >= 5 && strlen( $name ) <= 60 && preg_match( '/[A-Z]/', $name ) ) {
					$provider = array(
						'name'        => sanitize_text_field( $name ),
						'credentials' => '',
						'specialty'   => '',
					);
					$context['providers'] = self::merge_provider_lists( array( $provider ), $context['providers'] );
				}
			}
		}
	}

	/**
	 * @param DOMXPath $xpath XPath.
	 * @param array    $context Context (by reference).
	 */
	private static function extract_contact_from_dom( $xpath, &$context ) {
		$phones = $xpath->query( '//a[starts-with(@href, "tel:")]' );
		if ( $phones instanceof DOMNodeList ) {
			foreach ( $phones as $phone_link ) {
				$href  = $phone_link->getAttribute( 'href' );
				$phone = self::normalize_phone( preg_replace( '/^tel:/i', '', $href ) );
				if ( '' !== $phone ) {
					$loc = array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => $phone,
						'email'  => '',
						'hours'  => array(),
					);
					$context['locations'] = self::merge_location_lists( array( $loc ), $context['locations'] );
					break;
				}
			}
		}

		$emails = $xpath->query( '//a[starts-with(@href, "mailto:")]' );
		if ( $emails instanceof DOMNodeList && $emails->length > 0 ) {
			$href  = $emails->item( 0 )->getAttribute( 'href' );
			$email = sanitize_email( preg_replace( '/^mailto:/i', '', $href ) );
			if ( is_email( $email ) ) {
				if ( empty( $context['locations'] ) ) {
					$context['locations'][] = array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => '',
						'email'  => $email,
						'hours'  => array(),
					);
				} else {
					$context['locations'][0]['email'] = $email;
				}
			}
		}

		$address_nodes = $xpath->query( '//*[contains(@class,"address") or contains(@itemprop,"address")]' );
		if ( $address_nodes instanceof DOMNodeList && $address_nodes->length > 0 ) {
			$addr_text = trim( wp_strip_all_tags( $address_nodes->item( 0 )->textContent ) );
			$parsed    = self::parse_address_string( $addr_text );
			if ( ! empty( $parsed ) ) {
				$loc = array_merge(
					array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => '',
						'email'  => '',
						'hours'  => array(),
					),
					$parsed
				);
				$context['locations'] = self::merge_location_lists( array( $loc ), $context['locations'] );
			}
		}

		$footer = $xpath->query( '//footer' );
		if ( $footer instanceof DOMNodeList && $footer->length > 0 ) {
			self::extract_contact_from_footer( $footer->item( 0 )->textContent, $context );
		}
	}

	/**
	 * @param string $text    Footer text.
	 * @param array  $context Context (by reference).
	 */
	private static function extract_contact_from_footer( $text, &$context ) {
		if ( empty( $context['locations'] ) || '' === ( $context['locations'][0]['phone'] ?? '' ) ) {
			if ( preg_match( '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $text, $m ) ) {
				$phone = self::normalize_phone( $m[0] );
				if ( '' !== $phone ) {
					$loc = array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => $phone,
						'email'  => '',
						'hours'  => array(),
					);
					$context['locations'] = self::merge_location_lists( array( $loc ), $context['locations'] );
				}
			}
		}

		if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m ) ) {
			$email = sanitize_email( $m[0] );
			if ( is_email( $email ) ) {
				if ( empty( $context['locations'] ) ) {
					$context['locations'][] = array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => '',
						'email'  => $email,
						'hours'  => array(),
					);
				} elseif ( empty( $context['locations'][0]['email'] ) ) {
					$context['locations'][0]['email'] = $email;
				}
			}
		}
	}

	/**
	 * @param string $addr Address string.
	 * @return array
	 */
	private static function parse_address_string( $addr ) {
		$addr = trim( preg_replace( '/\s+/', ' ', $addr ) );
		if ( '' === $addr ) {
			return array();
		}
		if ( preg_match( '/^(.+?),\s*([^,]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i', $addr, $m ) ) {
			return array(
				'street' => sanitize_text_field( $m[1] ),
				'city'   => sanitize_text_field( $m[2] ),
				'state'  => strtoupper( sanitize_text_field( $m[3] ) ),
				'zip'    => sanitize_text_field( $m[4] ),
			);
		}
		return array( 'street' => sanitize_text_field( $addr ) );
	}

	/**
	 * @param string $phone Raw phone.
	 * @return string
	 */
	private static function normalize_phone( $phone ) {
		$phone = rawurldecode( (string) $phone );
		$digits = preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) === 11 && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) );
		}
		return sanitize_text_field( $phone );
	}

	/**
	 * @param string $href Raw href.
	 * @return string Absolute URL or empty.
	 */
	private static function normalize_internal_url( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}
		if ( 0 === strpos( $href, '/' ) ) {
			$href = home_url( $href );
		}
		$url = esc_url_raw( $href );
		if ( '' === $url || 0 !== strpos( $url, 'http' ) ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $host && $site && strtolower( $host ) !== strtolower( $site ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * @param string $url Service page URL.
	 * @return string
	 */
	private static function service_group_from_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( self::SERVICE_URL_PATTERNS as $pattern ) {
			$pattern = trim( $pattern, '/' );
			if ( preg_match( '#/' . preg_quote( $pattern, '#' ) . '/([^/]+)#i', $path, $m ) ) {
				return ucwords( str_replace( '-', ' ', $m[1] ) );
			}
		}
		return 'General';
	}

	/**
	 * Group and rank services by importance.
	 *
	 * @param array $context Context (by reference).
	 */
	private static function group_services( &$context ) {
		if ( empty( $context['services'] ) ) {
			return;
		}

		usort(
			$context['services'],
			static function ( $a, $b ) {
				return (int) ( $b['importance'] ?? 0 ) - (int) ( $a['importance'] ?? 0 );
			}
		);

		$context['services'] = array_slice( $context['services'], 0, 20 );
	}

	/**
	 * Infer missing fields from whatever was collected.
	 *
	 * @param array $context Context (by reference).
	 */
	private static function infer_missing_data( &$context ) {
		if ( '' === $context['business_name'] ) {
			$context['business_name'] = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}

		if ( '' === $context['business_description'] && ! empty( $context['about']['mission'] ) ) {
			$context['business_description'] = $context['about']['mission'];
		}

		if ( '' === $context['specialty'] && ! empty( $context['services'] ) ) {
			$names = array_map(
				static function ( $s ) {
					return $s['name'] ?? '';
				},
				array_slice( $context['services'], 0, 3 )
			);
			$names = array_filter( $names );
			if ( ! empty( $names ) ) {
				$context['specialty'] = sanitize_text_field( implode( ', ', $names ) );
			}
		}

		if ( empty( $context['services'] ) && ! empty( $context['page_summaries'] ) ) {
			foreach ( $context['page_summaries'] as $ps ) {
				if ( 'service' === ( $ps['type'] ?? '' ) ) {
					$context['services'][] = array(
						'name'       => $ps['title'],
						'summary'    => $ps['summary'],
						'url'        => $ps['url'],
						'group'      => self::service_group_from_url( $ps['url'] ),
						'importance' => (int) ( $ps['score'] ?? 10 ),
					);
				}
			}
		}

		if ( empty( $context['providers'] ) && ! empty( $context['trust_indicators'] ) ) {
			foreach ( $context['trust_indicators'] as $indicator ) {
				if ( preg_match( '/\b(dr\.?\s+[a-z]+)/i', $indicator, $m ) ) {
					$context['providers'] = self::merge_provider_lists(
						array(
							array(
								'name'        => sanitize_text_field( $m[1] ),
								'credentials' => '',
								'specialty'   => '',
							),
						),
						$context['providers']
					);
				}
			}
		}
	}

	/**
	 * Compute confidence scores and generation readiness.
	 *
	 * @param array $context         Context (by reference).
	 * @param int   $meaningful_pages Count of successfully fetched pages.
	 */
	private static function finalize_context( &$context, $meaningful_pages ) {
		$context['pages_discovered'] = $meaningful_pages;

		$confidence = self::default_confidence();

		if ( '' !== $context['business_description'] ) {
			$confidence['business_description'] = 0.85;
		} elseif ( '' !== $context['business_name'] ) {
			$confidence['business_description'] = 0.45;
		}

		if ( count( $context['services'] ) >= 3 ) {
			$confidence['services'] = 0.9;
		} elseif ( count( $context['services'] ) >= 1 ) {
			$confidence['services'] = 0.6;
		} elseif ( '' !== $context['specialty'] ) {
			$confidence['services'] = 0.35;
		}

		if ( count( $context['providers'] ) >= 2 ) {
			$confidence['providers'] = 0.85;
		} elseif ( count( $context['providers'] ) >= 1 ) {
			$confidence['providers'] = 0.55;
		}

		$has_contact = false;
		foreach ( $context['locations'] as $loc ) {
			if ( ! empty( $loc['phone'] ) || ! empty( $loc['street'] ) || ! empty( $loc['email'] ) ) {
				$has_contact = true;
				break;
			}
		}
		if ( $has_contact ) {
			$loc0 = $context['locations'][0] ?? array();
			$filled = 0;
			foreach ( array( 'phone', 'street', 'city', 'email' ) as $field ) {
				if ( ! empty( $loc0[ $field ] ) ) {
					$filled++;
				}
			}
			$confidence['contact'] = min( 0.95, 0.4 + ( $filled * 0.15 ) );
		}

		$context['confidence'] = $confidence;
		$context['missing_sections'] = self::detect_missing_sections( $context );

		$has_identity = self::has_business_identity( $context );
		$can_generate = $has_identity && $meaningful_pages >= self::MIN_MEANINGFUL_PAGES;

		$context['can_generate_llms'] = $can_generate;
		$context['generation_mode']   = $can_generate ? 'auto' : 'manual';
	}

	/**
	 * @param array $context Context array.
	 * @return bool
	 */
	public static function has_business_identity( $context ) {
		if ( ! empty( $context['business_name'] ) && strlen( $context['business_name'] ) >= 3 ) {
			return true;
		}
		if ( ! empty( $context['practice_type'] ) ) {
			return true;
		}
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '' !== trim( $blog );
	}

	/**
	 * @param array $context Context.
	 * @return string[] Section keys that are empty.
	 */
	private static function detect_missing_sections( $context ) {
		$missing = array();

		if ( '' === ( $context['business_description'] ?? '' ) ) {
			$missing[] = 'business_description';
		}
		if ( empty( $context['services'] ) ) {
			$missing[] = 'services';
		}
		if ( empty( $context['providers'] ) ) {
			$missing[] = 'providers';
		}

		$has_contact = false;
		foreach ( $context['locations'] ?? array() as $loc ) {
			if ( ! empty( $loc['phone'] ) || ! empty( $loc['street'] ) || ! empty( $loc['email'] ) ) {
				$has_contact = true;
				break;
			}
		}
		if ( ! $has_contact ) {
			$missing[] = 'contact';
		}

		if ( empty( $context['insurance_accepted'] ) ) {
			$missing[] = 'insurance';
		}
		if ( '' === ( $context['booking_url'] ?? '' ) ) {
			$missing[] = 'booking';
		}

		return $missing;
	}

	/**
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	private static function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( false );
		return $loaded ? $dom : null;
	}

	/**
	 * @param string $html Combined HTML.
	 * @param array  $context Context array (by reference).
	 */
	private static function parse_json_ld( $html, &$context ) {
		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $raw_json ) {
			$raw_json = trim( html_entity_decode( $raw_json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $raw_json ) {
				continue;
			}
			$data = json_decode( $raw_json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$nodes = self::flatten_json_ld( $data );
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				self::absorb_business_node( $node, $context );
				self::absorb_person_node( $node, $context );
				self::absorb_service_node( $node, $context );
				self::absorb_faq_node( $node, $context );
			}
		}
	}

	/**
	 * @param array $data JSON-LD root.
	 * @return array<int, array>
	 */
	private static function flatten_json_ld( $data ) {
		$nodes = array();
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
			return $nodes;
		}
		if ( isset( $data['@type'] ) ) {
			$nodes[] = $data;
		}
		return $nodes;
	}

	/**
	 * @param array $node Schema node.
	 * @return string[]
	 */
	private static function node_types( $node ) {
		$types = isset( $node['@type'] ) ? $node['@type'] : array();
		if ( is_string( $types ) ) {
			return array( $types );
		}
		if ( is_array( $types ) ) {
			return array_values( array_filter( array_map( 'strval', $types ) ) );
		}
		return array();
	}

	/**
	 * @param array $node Schema node.
	 * @param array $context Context (by reference).
	 */
	private static function absorb_business_node( $node, &$context ) {
		$types = self::node_types( $node );
		if ( empty( array_intersect( $types, self::BUSINESS_TYPES ) ) ) {
			return;
		}

		if ( ! empty( $node['name'] ) && '' === $context['business_name'] ) {
			$context['business_name'] = sanitize_text_field( (string) $node['name'] );
		}

		if ( ! empty( $node['description'] ) && '' === $context['business_description'] ) {
			$context['business_description'] = self::truncate_sentences( (string) $node['description'], 2 );
		}

		if ( '' === $context['practice_type'] ) {
			$context['practice_type'] = self::schema_type_to_practice_type( $types );
		}

		if ( '' === $context['specialty'] ) {
			$spec = $node['medicalSpecialty'] ?? ( $node['specialty'] ?? '' );
			if ( is_array( $spec ) ) {
				$spec = implode( ', ', array_filter( array_map( 'strval', $spec ) ) );
			}
			$context['specialty'] = sanitize_text_field( (string) $spec );
		}

		if ( '' === $context['booking_url'] ) {
			$context['booking_url'] = self::extract_booking_from_node( $node );
		}

		$loc = self::location_from_business_node( $node );
		if ( ! empty( $loc ) ) {
			$context['locations'] = self::merge_location_lists( array( $loc ), $context['locations'] );
		}

		$employees = array();
		foreach ( array( 'employee', 'member', 'founder', 'author' ) as $key ) {
			if ( empty( $node[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $node[ $key ] as $emp ) {
				if ( is_array( $emp ) ) {
					$employees[] = $emp;
				}
			}
		}
		foreach ( $employees as $emp ) {
			self::absorb_person_node( $emp, $context );
		}
	}

	/**
	 * @param array $node Service schema node.
	 * @param array $context Context (by reference).
	 */
	private static function absorb_service_node( $node, &$context ) {
		$types = self::node_types( $node );
		if ( ! in_array( 'Service', $types, true ) && ! in_array( 'MedicalProcedure', $types, true ) ) {
			return;
		}

		$name = trim( (string) ( $node['name'] ?? '' ) );
		if ( '' === $name ) {
			return;
		}

		$summary = '';
		if ( ! empty( $node['description'] ) ) {
			$summary = self::truncate_sentences( (string) $node['description'], 2 );
		}

		$url = self::extract_url_from_schema_value( $node['url'] ?? '' );

		$context['services'] = self::merge_service_lists(
			array(
				array(
					'name'       => sanitize_text_field( $name ),
					'summary'    => $summary,
					'url'        => $url ? esc_url_raw( $url ) : '',
					'group'      => 'Schema',
					'importance' => 25,
				),
			),
			$context['services']
		);
	}

	/**
	 * @param array $node Schema node.
	 * @param array $context Context (by reference).
	 */
	private static function absorb_person_node( $node, &$context ) {
		$types = self::node_types( $node );
		if ( empty( array_intersect( $types, array( 'Person', 'Physician', 'Dentist' ) ) ) ) {
			return;
		}
		$name = trim( (string) ( $node['name'] ?? '' ) );
		if ( '' === $name || strlen( $name ) < 3 ) {
			return;
		}
		$provider = array(
			'name'        => sanitize_text_field( $name ),
			'credentials' => sanitize_text_field( (string) ( $node['honorificSuffix'] ?? ( $node['jobTitle'] ?? '' ) ) ),
			'specialty'   => sanitize_text_field( (string) ( $node['medicalSpecialty'] ?? '' ) ),
		);
		$context['providers'] = self::merge_provider_lists( array( $provider ), $context['providers'] );
	}

	/**
	 * @param array $node FAQPage node.
	 * @param array $context Context (by reference).
	 */
	private static function absorb_faq_node( $node, &$context ) {
		$types = self::node_types( $node );
		if ( ! in_array( 'FAQPage', $types, true ) ) {
			return;
		}
		$entities = $node['mainEntity'] ?? array();
		if ( ! is_array( $entities ) ) {
			return;
		}
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$q = trim( (string) ( $entity['name'] ?? '' ) );
			if ( '' !== $q && strlen( $q ) >= 8 ) {
				$context['target_queries'] = self::merge_string_lists( array( $q ), $context['target_queries'], 15 );
			}
		}
	}

	/**
	 * @param string[] $types Schema @type values.
	 * @return string practice_type slug.
	 */
	private static function schema_type_to_practice_type( $types ) {
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'Dentist':
					return 'dentist';
				case 'Physician':
					return 'physician';
				case 'MedicalClinic':
				case 'MedicalBusiness':
				case 'Hospital':
					return 'medical_clinic';
				case 'LocalBusiness':
				case 'HealthAndBeautyBusiness':
					return 'other';
			}
		}
		return '';
	}

	/**
	 * @param array $node Business schema node.
	 * @return string Booking URL or empty.
	 */
	private static function extract_booking_from_node( $node ) {
		$actions = $node['potentialAction'] ?? array();
		if ( ! is_array( $actions ) ) {
			return '';
		}
		if ( isset( $actions['@type'] ) ) {
			$actions = array( $actions );
		}
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$action_types = self::node_types( $action );
			if ( ! empty( array_intersect( $action_types, array( 'ReserveAction', 'BookAction', 'ScheduleAction' ) ) ) ) {
				$url = self::extract_url_from_schema_value( $action['target'] ?? '' );
				if ( '' !== $url ) {
					return esc_url_raw( $url );
				}
			}
		}
		if ( ! empty( $node['url'] ) && preg_match( '/book|appointment|schedule|reserve/i', (string) $node['url'] ) ) {
			return esc_url_raw( (string) $node['url'] );
		}
		return '';
	}

	/**
	 * @param mixed $value Schema URL field (string or EntryPoint).
	 * @return string
	 */
	private static function extract_url_from_schema_value( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
				return $value['url'];
			}
			if ( ! empty( $value['@id'] ) && is_string( $value['@id'] ) && 0 === strpos( $value['@id'], 'http' ) ) {
				return $value['@id'];
			}
		}
		return '';
	}

	/**
	 * @param array $node Business schema node.
	 * @return array Location array or empty.
	 */
	private static function location_from_business_node( $node ) {
		$loc = array(
			'label'  => sanitize_text_field( (string) ( $node['name'] ?? 'Office' ) ),
			'street' => '',
			'city'   => '',
			'state'  => '',
			'zip'    => '',
			'phone'  => sanitize_text_field( (string) ( $node['telephone'] ?? '' ) ),
			'email'  => sanitize_email( (string) ( $node['email'] ?? '' ) ),
			'hours'  => array(),
		);

		if ( ! empty( $loc['phone'] ) ) {
			$loc['phone'] = self::normalize_phone( $loc['phone'] );
		}

		$addr = $node['address'] ?? null;
		if ( is_array( $addr ) ) {
			$loc['street'] = sanitize_text_field( (string) ( $addr['streetAddress'] ?? '' ) );
			$loc['city']   = sanitize_text_field( (string) ( $addr['addressLocality'] ?? '' ) );
			$loc['state']  = sanitize_text_field( (string) ( $addr['addressRegion'] ?? '' ) );
			$loc['zip']    = sanitize_text_field( (string) ( $addr['postalCode'] ?? '' ) );
		} elseif ( is_string( $addr ) && '' !== trim( $addr ) ) {
			$parsed = self::parse_address_string( $addr );
			$loc    = array_merge( $loc, $parsed );
		}

		if ( ! empty( $node['openingHoursSpecification'] ) ) {
			$loc['hours'] = self::hours_from_schema( $node['openingHoursSpecification'] );
		} elseif ( ! empty( $node['openingHours'] ) && is_array( $node['openingHours'] ) ) {
			$loc['hours'] = self::hours_from_opening_hours_strings( $node['openingHours'] );
		}

		if ( '' === $loc['phone'] && '' === $loc['street'] && '' === $loc['city'] && empty( $loc['hours'] ) && '' === $loc['email'] ) {
			return array();
		}
		return $loc;
	}

	/**
	 * @param mixed $specs OpeningHoursSpecification node(s).
	 * @return array<string, string>
	 */
	private static function hours_from_schema( $specs ) {
		$hours = array();
		if ( isset( $specs['@type'] ) ) {
			$specs = array( $specs );
		}
		if ( ! is_array( $specs ) ) {
			return $hours;
		}
		$day_map = array(
			'monday'    => 'monday',
			'tuesday'   => 'tuesday',
			'wednesday' => 'wednesday',
			'thursday'  => 'thursday',
			'friday'    => 'friday',
			'saturday'  => 'saturday',
			'sunday'    => 'sunday',
		);
		$by_day = array();
		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$opens  = (string) ( $spec['opens'] ?? '' );
			$closes = (string) ( $spec['closes'] ?? '' );
			if ( '' === $opens || '' === $closes ) {
				continue;
			}
			$range = self::format_hour_range( $opens, $closes );
			if ( '' === $range ) {
				continue;
			}
			$days = $spec['dayOfWeek'] ?? array();
			if ( is_string( $days ) ) {
				$days = array( $days );
			}
			foreach ( (array) $days as $day ) {
				$day_key = strtolower( preg_replace( '/^https?:\/\/schema\.org\//i', '', (string) $day ) );
				if ( isset( $day_map[ $day_key ] ) ) {
					$by_day[ $day_map[ $day_key ] ] = $range;
				}
			}
		}
		return $by_day;
	}

	/**
	 * @param array $strings openingHours strings e.g. "Mo 09:00-17:00".
	 * @return array<string, string>
	 */
	private static function hours_from_opening_hours_strings( $strings ) {
		$hours   = array();
		$day_map = array(
			'mo' => 'monday',
			'tu' => 'tuesday',
			'we' => 'wednesday',
			'th' => 'thursday',
			'fr' => 'friday',
			'sa' => 'saturday',
			'su' => 'sunday',
		);
		foreach ( $strings as $line ) {
			if ( ! is_string( $line ) || ! preg_match( '/^([A-Za-z]{2})\s+(\d{2}:\d{2})-(\d{2}:\d{2})$/', trim( $line ), $m ) ) {
				continue;
			}
			$key = strtolower( $m[1] );
			if ( ! isset( $day_map[ $key ] ) ) {
				continue;
			}
			$range = self::format_hour_range( $m[2], $m[3] );
			if ( '' !== $range ) {
				$hours[ $day_map[ $key ] ] = $range;
			}
		}
		return $hours;
	}

	/**
	 * @param string $opens 24h HH:MM.
	 * @param string $closes 24h HH:MM.
	 * @return string Human-readable range or empty.
	 */
	private static function format_hour_range( $opens, $closes ) {
		$open_dt  = DateTime::createFromFormat( 'H:i', $opens );
		$close_dt = DateTime::createFromFormat( 'H:i', $closes );
		if ( ! $open_dt || ! $close_dt ) {
			return '';
		}
		return $open_dt->format( 'g:i A' ) . ' - ' . $close_dt->format( 'g:i A' );
	}

	/**
	 * @param string $html Combined HTML.
	 * @param array  $context Context (by reference).
	 */
	private static function parse_html_signals( $html, &$context ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return;
		}

		$xpath = new DOMXPath( $dom );

		if ( empty( $context['locations'] ) || '' === ( $context['locations'][0]['phone'] ?? '' ) ) {
			$phones = $xpath->query( '//a[starts-with(@href, "tel:")]' );
			if ( $phones instanceof DOMNodeList && $phones->length > 0 ) {
				$href  = $phones->item( 0 )->getAttribute( 'href' );
				$phone = self::normalize_phone( preg_replace( '/^tel:/i', '', $href ) );
				if ( '' !== $phone ) {
					$loc = array(
						'label'  => 'Office',
						'street' => '',
						'city'   => '',
						'state'  => '',
						'zip'    => '',
						'phone'  => $phone,
						'email'  => '',
						'hours'  => array(),
					);
					$context['locations'] = self::merge_location_lists( array( $loc ), $context['locations'] );
				}
			}
		}

		if ( '' === $context['booking_url'] ) {
			$links = $xpath->query( '//a[@href]' );
			if ( $links instanceof DOMNodeList ) {
				foreach ( $links as $link ) {
					$href = $link->getAttribute( 'href' );
					$text = strtolower( trim( $link->textContent ) );
					if ( ! is_string( $href ) || '' === $href ) {
						continue;
					}
					if ( preg_match( '/book|appointment|schedule|reserve|zocdoc|demandforce|nexhealth|localmed/i', $href . ' ' . $text ) ) {
						$url = esc_url_raw( $href );
						if ( '' !== $url && 0 === strpos( $url, 'http' ) ) {
							$context['booking_url'] = $url;
							break;
						}
					}
				}
			}
		}

		$footer = $xpath->query( '//footer' );
		if ( $footer instanceof DOMNodeList && $footer->length > 0 ) {
			self::extract_contact_from_footer( $footer->item( 0 )->textContent, $context );
		}

		self::extract_faqs_from_dom( $xpath, $context );
		self::extract_insurance_from_dom( $xpath, $context );
	}

	/**
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $context Context (by reference).
	 */
	private static function extract_faqs_from_dom( $xpath, &$context ) {
		$questions = array();

		$details = $xpath->query( '//details/summary' );
		if ( $details instanceof DOMNodeList ) {
			foreach ( $details as $summary ) {
				$q = trim( $summary->textContent );
				if ( '' !== $q && ( false !== strpos( $q, '?' ) || strlen( $q ) >= 12 ) ) {
					$questions[] = $q;
				}
			}
		}

		$faq_headings = $xpath->query( '//*[self::h3 or self::h4][contains(., "?")]' );
		if ( $faq_headings instanceof DOMNodeList ) {
			foreach ( $faq_headings as $heading ) {
				$q = trim( $heading->textContent );
				if ( '' !== $q && false !== strpos( $q, '?' ) ) {
					$questions[] = $q;
				}
			}
		}

		if ( ! empty( $questions ) ) {
			$context['target_queries'] = self::merge_string_lists( $questions, $context['target_queries'], 15 );
		}
	}

	/**
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $context Context (by reference).
	 */
	private static function extract_insurance_from_dom( $xpath, &$context ) {
		$insurance = array();
		$sections  = $xpath->query( '//*[contains(translate(@class,"INSURANCE","insurance"),"insurance") or contains(translate(@id,"INSURANCE","insurance"),"insurance")]' );
		if ( ! ( $sections instanceof DOMNodeList ) || 0 === $sections->length ) {
			return;
		}
		foreach ( $sections as $section ) {
			$items = ( new DOMXPath( $section->ownerDocument ) )->query( './/li', $section );
			if ( ! ( $items instanceof DOMNodeList ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$text = trim( $item->textContent );
				if ( '' !== $text && strlen( $text ) < 80 ) {
					$insurance[] = sanitize_text_field( $text );
				}
			}
		}
		if ( ! empty( $insurance ) ) {
			$context['insurance_accepted'] = self::merge_string_lists( $insurance, $context['insurance_accepted'], 20 );
		}
	}

	/**
	 * @param array $primary Primary services.
	 * @param array $fallback Existing services.
	 * @return array
	 */
	public static function merge_service_lists( $primary, $fallback ) {
		$out  = array();
		$seen = array();

		foreach ( array_merge( $primary, $fallback ) as $svc ) {
			if ( ! is_array( $svc ) || empty( $svc['name'] ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $svc['name'] ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'name'       => sanitize_text_field( (string) $svc['name'] ),
				'summary'    => sanitize_text_field( (string) ( $svc['summary'] ?? '' ) ),
				'url'        => ! empty( $svc['url'] ) ? esc_url_raw( (string) $svc['url'] ) : '',
				'group'      => sanitize_text_field( (string) ( $svc['group'] ?? 'General' ) ),
				'importance' => (int) ( $svc['importance'] ?? 10 ),
			);
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array $primary Primary list.
	 * @param array $fallback Fallback list.
	 * @return array
	 */
	public static function merge_location_lists( $primary, $fallback ) {
		$out = array();
		$max = max( count( $primary ), count( $fallback ), 1 );
		for ( $i = 0; $i < $max && $i < 5; $i++ ) {
			$a = isset( $primary[ $i ] ) && is_array( $primary[ $i ] ) ? $primary[ $i ] : array();
			$b = isset( $fallback[ $i ] ) && is_array( $fallback[ $i ] ) ? $fallback[ $i ] : array();
			$merged = array(
				'label'  => self::pick_field( $a, $b, 'label', 'Office' ),
				'street' => self::pick_field( $a, $b, 'street' ),
				'city'   => self::pick_field( $a, $b, 'city' ),
				'state'  => self::pick_field( $a, $b, 'state' ),
				'zip'    => self::pick_field( $a, $b, 'zip' ),
				'phone'  => self::pick_field( $a, $b, 'phone' ),
				'email'  => self::pick_field( $a, $b, 'email' ),
				'hours'  => self::merge_hours( $a['hours'] ?? array(), $b['hours'] ?? array() ),
			);
			if ( ! empty( $merged['phone'] ) ) {
				$merged['phone'] = self::normalize_phone( $merged['phone'] );
			}
			if ( self::location_has_data( $merged ) ) {
				$out[] = $merged;
			}
		}
		return $out;
	}

	/**
	 * @param array $loc Location row.
	 * @return bool
	 */
	private static function location_has_data( $loc ) {
		return '' !== ( $loc['phone'] ?? '' )
			|| '' !== ( $loc['street'] ?? '' )
			|| '' !== ( $loc['city'] ?? '' )
			|| '' !== ( $loc['email'] ?? '' )
			|| ! empty( $loc['hours'] );
	}

	/**
	 * @param array $primary Scraped hours.
	 * @param array $fallback Profile hours.
	 * @return array
	 */
	private static function merge_hours( $primary, $fallback ) {
		$out  = is_array( $fallback ) ? $fallback : array();
		$prim = is_array( $primary ) ? $primary : array();
		foreach ( $prim as $day => $val ) {
			if ( is_string( $val ) && '' !== trim( $val ) ) {
				$out[ $day ] = $val;
			}
		}
		return $out;
	}

	/**
	 * @param array  $a Primary row.
	 * @param array  $b Fallback row.
	 * @param string $key Field key.
	 * @param string $default Default when both empty.
	 * @return string
	 */
	private static function pick_field( $a, $b, $key, $default = '' ) {
		$av = isset( $a[ $key ] ) ? trim( (string) $a[ $key ] ) : '';
		$bv = isset( $b[ $key ] ) ? trim( (string) $b[ $key ] ) : '';
		if ( '' !== $av ) {
			return 'email' === $key ? sanitize_email( $av ) : sanitize_text_field( $av );
		}
		if ( '' !== $bv ) {
			return 'email' === $key ? sanitize_email( $bv ) : sanitize_text_field( $bv );
		}
		return $default;
	}

	/**
	 * @param array $primary Scraped providers.
	 * @param array $fallback Profile providers.
	 * @return array
	 */
	public static function merge_provider_lists( $primary, $fallback ) {
		$out  = array();
		$seen = array();
		foreach ( array_merge( $primary, $fallback ) as $prov ) {
			if ( ! is_array( $prov ) || empty( $prov['name'] ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $prov['name'] ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'name'        => sanitize_text_field( (string) $prov['name'] ),
				'credentials' => sanitize_text_field( (string) ( $prov['credentials'] ?? '' ) ),
				'specialty'   => sanitize_text_field( (string) ( $prov['specialty'] ?? '' ) ),
			);
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array $primary Scraped strings.
	 * @param array $fallback Profile strings.
	 * @param int   $limit Max items.
	 * @return array
	 */
	public static function merge_string_lists( $primary, $fallback, $limit = 15 ) {
		$out  = array();
		$seen = array();
		foreach ( array_merge( $primary, $fallback ) as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$key = strtolower( $item );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
