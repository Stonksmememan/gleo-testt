<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Practice profile data model for dental/medical sites.
 *
 * Stores NAP, hours, providers, insurance, booking URL, and target AI queries
 * as a single JSON option (gleo_practice_profile). Provides helpers for
 * schema generation, llms.txt output, and completeness scoring.
 */
class Gleo_Practice_Profile {

	const VALID_TYPES = array( 'dentist', 'physician', 'medical_clinic', 'other' );

	const DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/**
	 * Retrieve the decoded practice profile with safe defaults.
	 *
	 * @return array{
	 *   practice_type: string,
	 *   specialty: string,
	 *   locations: array,
	 *   providers: array,
	 *   insurance_accepted: array,
	 *   booking_url: string,
	 *   target_queries: array
	 * }
	 */
	public static function get() {
		$defaults = self::default_fields();

		$raw = get_option( 'gleo_practice_profile', '' );
		if ( '' === $raw || ! is_string( $raw ) ) {
			return $defaults;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}
		return array_merge( $defaults, $decoded );
	}

	/**
	 * Default profile / scrape field shape.
	 *
	 * @return array
	 */
	public static function default_fields() {
		return array(
			'business_name'        => '',
			'business_description' => '',
			'trust_indicators'     => array(),
			'practice_type'        => '',
			'specialty'            => '',
			'services'             => array(),
			'about'                => array(
				'mission'         => '',
				'expertise'       => '',
				'differentiators' => array(),
			),
			'locations'            => array(),
			'providers'          => array(),
			'insurance_accepted' => array(),
			'booking_url'        => '',
			'target_queries'     => array(),
			'page_summaries'       => array(),
			'confidence'           => array(
				'business_description' => 0.0,
				'services'             => 0.0,
				'providers'            => 0.0,
				'contact'              => 0.0,
			),
			'missing_sections'     => array(),
			'can_generate_llms'    => false,
			'generation_mode'      => 'manual',
			'pages_discovered'     => 0,
		);
	}

	/**
	 * Whether the profile contains enough data to drive schema and llms.txt output.
	 *
	 * @param array|null $profile Pre-decoded profile; fetched from option if null.
	 * @return bool
	 */
	public static function is_set( $profile = null ) {
		$p = null !== $profile ? $profile : self::get();
		return ! empty( $p['practice_type'] ) && ! empty( $p['locations'] );
	}

	/**
	 * Whether merged scrape + profile data is enough to render llms.txt practice sections.
	 *
	 * Prefers best-effort generation whenever business identity is known.
	 *
	 * @param array|null $profile Merged or raw profile.
	 * @return bool
	 */
	public static function has_llms_context( $profile = null ) {
		$p = null !== $profile ? $profile : self::get_merged_for_llms();

		if ( ! empty( $p['can_generate_llms'] ) ) {
			return true;
		}

		$has_identity = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::has_business_identity( $p )
			: ( ! empty( $p['business_name'] ) || ! empty( $p['practice_type'] ) );

		if ( $has_identity ) {
			if ( ! empty( $p['business_description'] ) || ! empty( $p['services'] ) || ! empty( $p['providers'] )
				|| ! empty( $p['locations'] ) || ! empty( $p['target_queries'] ) || ! empty( $p['specialty'] ) ) {
				return true;
			}
		}

		if ( ! empty( $p['practice_type'] ) && ! empty( $p['locations'] ) ) {
			return true;
		}
		if ( ! empty( $p['providers'] ) || ! empty( $p['target_queries'] ) ) {
			return true;
		}
		if ( ! empty( $p['locations'] ) && is_array( $p['locations'][0] ) ) {
			$loc = $p['locations'][0];
			if ( ! empty( $loc['phone'] ) || ! empty( $loc['street'] ) || ! empty( $loc['city'] ) || ! empty( $loc['email'] ) ) {
				return true;
			}
		}
		return ! empty( $p['specialty'] ) || ! empty( $p['booking_url'] ) || ! empty( $p['services'] );
	}

	/**
	 * Merged profile for llms.txt (saved practice profile overrides scraped site data).
	 *
	 * @return array
	 */
	public static function get_merged_for_llms() {
		if ( class_exists( 'Gleo_Llms_Scraper' ) ) {
			return Gleo_Llms_Scraper::get_merged_profile();
		}
		return self::get();
	}

	/**
	 * Merge scraped site context with saved practice profile (profile wins; scrape fills gaps).
	 *
	 * @param array $scraped Scraped context from Gleo_Llms_Scraper.
	 * @param array $profile Saved practice profile.
	 * @return array
	 */
	public static function merge_with_scraped( $scraped, $profile ) {
		$defaults = self::default_fields();
		$profile  = array_merge( $defaults, is_array( $profile ) ? $profile : array() );
		$scraped  = array_merge( $defaults, is_array( $scraped ) ? $scraped : array() );

		$merged = $profile;

		foreach ( array( 'business_name', 'business_description', 'practice_type', 'specialty', 'booking_url' ) as $key ) {
			if ( empty( $merged[ $key ] ) && ! empty( $scraped[ $key ] ) ) {
				$merged[ $key ] = $scraped[ $key ];
			}
		}

		$merged['trust_indicators'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_string_lists( $profile['trust_indicators'], $scraped['trust_indicators'], 10 )
			: ( ! empty( $profile['trust_indicators'] ) ? $profile['trust_indicators'] : $scraped['trust_indicators'] );

		$merged['services'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_service_lists( $profile['services'], $scraped['services'] )
			: ( ! empty( $profile['services'] ) ? $profile['services'] : $scraped['services'] );

		$merged_about  = is_array( $profile['about'] ?? null ) ? $profile['about'] : array();
		$scraped_about = is_array( $scraped['about'] ?? null ) ? $scraped['about'] : array();
		$merged['about'] = array(
			'mission'         => ! empty( $merged_about['mission'] ) ? $merged_about['mission'] : ( $scraped_about['mission'] ?? '' ),
			'expertise'       => ! empty( $merged_about['expertise'] ) ? $merged_about['expertise'] : ( $scraped_about['expertise'] ?? '' ),
			'differentiators' => class_exists( 'Gleo_Llms_Scraper' )
				? Gleo_Llms_Scraper::merge_string_lists( $merged_about['differentiators'] ?? array(), $scraped_about['differentiators'] ?? array(), 8 )
				: ( $merged_about['differentiators'] ?? array() ),
		);

		$merged['locations'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_location_lists( $profile['locations'], $scraped['locations'] )
			: ( ! empty( $profile['locations'] ) ? $profile['locations'] : $scraped['locations'] );

		$merged['providers'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_provider_lists( $profile['providers'], $scraped['providers'] )
			: ( ! empty( $profile['providers'] ) ? $profile['providers'] : $scraped['providers'] );

		$merged['insurance_accepted'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_string_lists( $profile['insurance_accepted'], $scraped['insurance_accepted'], 20 )
			: ( ! empty( $profile['insurance_accepted'] ) ? $profile['insurance_accepted'] : $scraped['insurance_accepted'] );

		$merged['target_queries'] = class_exists( 'Gleo_Llms_Scraper' )
			? Gleo_Llms_Scraper::merge_string_lists( $profile['target_queries'], $scraped['target_queries'], 15 )
			: ( ! empty( $profile['target_queries'] ) ? $profile['target_queries'] : $scraped['target_queries'] );

		if ( ! empty( $scraped['page_summaries'] ) ) {
			$merged['page_summaries'] = $scraped['page_summaries'];
		}

		foreach ( array( 'confidence', 'can_generate_llms', 'generation_mode', 'pages_discovered' ) as $meta_key ) {
			if ( isset( $scraped[ $meta_key ] ) ) {
				$merged[ $meta_key ] = $scraped[ $meta_key ];
			}
		}

		if ( class_exists( 'Gleo_Llms_Scraper' ) ) {
			$merged['missing_sections'] = Gleo_Llms_Scraper::compute_missing_sections( $merged );
		} elseif ( isset( $scraped['missing_sections'] ) ) {
			$merged['missing_sections'] = $scraped['missing_sections'];
		}

		return $merged;
	}

	/**
	 * Empty location row for the Practice Profile editor.
	 *
	 * @return array
	 */
	public static function empty_location() {
		return array(
			'label'  => '',
			'street' => '',
			'city'   => '',
			'state'  => '',
			'zip'    => '',
			'phone'  => '',
			'email'  => '',
			'hours'  => array(),
		);
	}

	/**
	 * Restrict merged context to fields persisted in gleo_practice_profile.
	 *
	 * @param array $data Merged or draft profile data.
	 * @return array
	 */
	public static function to_saved_shape( $data ) {
		$out = array(
			'practice_type'        => sanitize_text_field( (string) ( $data['practice_type'] ?? '' ) ),
			'specialty'            => sanitize_text_field( (string) ( $data['specialty'] ?? '' ) ),
			'booking_url'          => ! empty( $data['booking_url'] ) ? esc_url_raw( (string) $data['booking_url'] ) : '',
			'locations'            => array(),
			'providers'            => array(),
			'insurance_accepted'   => array(),
			'target_queries'       => array(),
		);

		if ( ! empty( $data['locations'] ) && is_array( $data['locations'] ) ) {
			foreach ( array_slice( $data['locations'], 0, 5 ) as $loc ) {
				if ( ! is_array( $loc ) ) {
					continue;
				}
				$clean_loc = self::empty_location();
				foreach ( array( 'label', 'street', 'city', 'state', 'zip', 'phone', 'email' ) as $field ) {
					if ( 'email' === $field ) {
						$clean_loc[ $field ] = sanitize_email( (string) ( $loc[ $field ] ?? '' ) );
					} else {
						$clean_loc[ $field ] = sanitize_text_field( (string) ( $loc[ $field ] ?? '' ) );
					}
				}
				if ( ! empty( $loc['hours'] ) && is_array( $loc['hours'] ) ) {
					foreach ( self::DAYS as $day ) {
						if ( ! empty( $loc['hours'][ $day ] ) ) {
							$clean_loc['hours'][ $day ] = sanitize_text_field( (string) $loc['hours'][ $day ] );
						}
					}
				}
				if ( self::location_row_has_data( $clean_loc ) ) {
					$out['locations'][] = $clean_loc;
				}
			}
		}

		if ( empty( $out['locations'] ) ) {
			$out['locations'][] = self::empty_location();
		}

		if ( ! empty( $data['providers'] ) && is_array( $data['providers'] ) ) {
			foreach ( array_slice( $data['providers'], 0, 10 ) as $prov ) {
				if ( ! is_array( $prov ) || empty( $prov['name'] ) ) {
					continue;
				}
				$out['providers'][] = array(
					'name'        => sanitize_text_field( (string) $prov['name'] ),
					'credentials' => sanitize_text_field( (string) ( $prov['credentials'] ?? '' ) ),
					'specialty'   => sanitize_text_field( (string) ( $prov['specialty'] ?? '' ) ),
				);
			}
		}

		if ( ! empty( $data['insurance_accepted'] ) && is_array( $data['insurance_accepted'] ) ) {
			foreach ( array_slice( $data['insurance_accepted'], 0, 20 ) as $ins ) {
				$ins = sanitize_text_field( (string) $ins );
				if ( '' !== $ins ) {
					$out['insurance_accepted'][] = $ins;
				}
			}
		}

		if ( ! empty( $data['target_queries'] ) && is_array( $data['target_queries'] ) ) {
			foreach ( array_slice( $data['target_queries'], 0, 10 ) as $q ) {
				$q = sanitize_text_field( (string) $q );
				if ( '' !== $q ) {
					$out['target_queries'][] = $q;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array $loc Location row.
	 * @return bool
	 */
	private static function location_row_has_data( $loc ) {
		foreach ( array( 'label', 'street', 'city', 'state', 'zip', 'phone', 'email' ) as $field ) {
			if ( '' !== trim( (string) ( $loc[ $field ] ?? '' ) ) ) {
				return true;
			}
		}
		if ( ! empty( $loc['hours'] ) && is_array( $loc['hours'] ) ) {
			foreach ( $loc['hours'] as $hours ) {
				if ( '' !== trim( (string) $hours ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param mixed $value Profile field value.
	 * @return bool
	 */
	private static function is_empty_profile_value( $value ) {
		if ( is_array( $value ) ) {
			return empty( $value );
		}
		return '' === trim( (string) $value );
	}

	/**
	 * Field paths that were empty in the saved profile but filled from a site scrape.
	 *
	 * @param array $saved Saved practice profile.
	 * @param array $draft Profile with scrape-filled blanks.
	 * @return string[]
	 */
	public static function compute_filled_from_scrape( $saved, $draft ) {
		$paths = array();

		foreach ( array( 'practice_type', 'specialty', 'booking_url' ) as $key ) {
			if ( self::is_empty_profile_value( $saved[ $key ] ?? '' ) && ! self::is_empty_profile_value( $draft[ $key ] ?? '' ) ) {
				$paths[] = $key;
			}
		}

		$saved_locs = is_array( $saved['locations'] ?? null ) ? $saved['locations'] : array();
		$draft_locs = is_array( $draft['locations'] ?? null ) ? $draft['locations'] : array();
		$loc_count  = max( count( $saved_locs ), count( $draft_locs ) );
		for ( $i = 0; $i < $loc_count; $i++ ) {
			$saved_loc = isset( $saved_locs[ $i ] ) && is_array( $saved_locs[ $i ] ) ? $saved_locs[ $i ] : self::empty_location();
			$draft_loc = isset( $draft_locs[ $i ] ) && is_array( $draft_locs[ $i ] ) ? $draft_locs[ $i ] : self::empty_location();
			foreach ( array( 'label', 'street', 'city', 'state', 'zip', 'phone', 'email' ) as $field ) {
				if ( self::is_empty_profile_value( $saved_loc[ $field ] ?? '' ) && ! self::is_empty_profile_value( $draft_loc[ $field ] ?? '' ) ) {
					$paths[] = "locations.{$i}.{$field}";
				}
			}
			$saved_hours = is_array( $saved_loc['hours'] ?? null ) ? $saved_loc['hours'] : array();
			$draft_hours = is_array( $draft_loc['hours'] ?? null ) ? $draft_loc['hours'] : array();
			foreach ( self::DAYS as $day ) {
				if ( self::is_empty_profile_value( $saved_hours[ $day ] ?? '' ) && ! self::is_empty_profile_value( $draft_hours[ $day ] ?? '' ) ) {
					$paths[] = "locations.{$i}.hours.{$day}";
				}
			}
		}

		$saved_providers = is_array( $saved['providers'] ?? null ) ? $saved['providers'] : array();
		$draft_providers = is_array( $draft['providers'] ?? null ) ? $draft['providers'] : array();
		if ( empty( $saved_providers ) && ! empty( $draft_providers ) ) {
			$paths[] = 'providers';
		}

		if ( self::is_empty_profile_value( $saved['insurance_accepted'] ?? array() ) && ! self::is_empty_profile_value( $draft['insurance_accepted'] ?? array() ) ) {
			$paths[] = 'insurance_accepted';
		}

		if ( self::is_empty_profile_value( $saved['target_queries'] ?? array() ) && ! self::is_empty_profile_value( $draft['target_queries'] ?? array() ) ) {
			$paths[] = 'target_queries';
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Practice Profile editor payload: saved profile with scrape-filled blanks.
	 *
	 * @return array{profile: array, filled_from_scrape: string[], scrape_meta: array}
	 */
	public static function get_editor_payload() {
		$saved = self::to_saved_shape( self::get() );
		$draft = $saved;
		$full  = $saved;

		if ( class_exists( 'Gleo_Llms_Scraper' ) ) {
			$scraped = Gleo_Llms_Scraper::get_scraped_context();
			$full    = self::merge_with_scraped( $scraped, $saved );
			$draft   = self::to_saved_shape( $full );
		}

		$scrape_meta = array();
		if ( class_exists( 'Gleo_Llms_Scraper' ) ) {
			$scrape_meta = array(
				'confidence'        => $full['confidence'] ?? Gleo_Llms_Scraper::get_scrape_metadata()['confidence'] ?? array(),
				'missing_sections'  => Gleo_Llms_Scraper::compute_missing_sections( $full ),
				'can_generate_llms' => ! empty( $full['can_generate_llms'] ),
				'generation_mode'   => $full['generation_mode'] ?? 'manual',
				'pages_discovered'  => (int) ( $full['pages_discovered'] ?? 0 ),
			);
		}

		return array(
			'profile'            => $draft,
			'filled_from_scrape' => self::compute_filled_from_scrape( $saved, $draft ),
			'scrape_meta'        => $scrape_meta,
		);
	}

	/**
	 * Profile completeness as a percentage (0–100) for the admin progress meter.
	 *
	 * @param array|null $profile Pre-decoded profile; fetched from option if null.
	 * @return int
	 */
	public static function completeness( $profile = null ) {
		$p = null !== $profile ? $profile : self::get();
		$checks = array(
			! empty( $p['practice_type'] ),
			! empty( $p['specialty'] ),
			! empty( $p['locations'] ),
			isset( $p['locations'][0]['phone'] ) && '' !== trim( $p['locations'][0]['phone'] ),
			isset( $p['locations'][0]['street'] ) && '' !== trim( $p['locations'][0]['street'] ),
			isset( $p['locations'][0]['city'] ) && '' !== trim( $p['locations'][0]['city'] ),
			! empty( $p['providers'] ),
			! empty( $p['insurance_accepted'] ),
			! empty( $p['booking_url'] ),
			! empty( $p['target_queries'] ),
		);
		$done = count( array_filter( $checks ) );
		return (int) round( ( $done / count( $checks ) ) * 100 );
	}

	/**
	 * Map practice_type value to the appropriate schema.org @type.
	 *
	 * @param string $type Raw practice_type value.
	 * @return string schema.org type name.
	 */
	public static function get_schema_type( $type ) {
		switch ( $type ) {
			case 'dentist':
				return 'Dentist';
			case 'physician':
			case 'medical_clinic':
				return 'MedicalClinic';
			default:
				return 'LocalBusiness';
		}
	}

	/**
	 * Parse a day's hour string ("9:00 AM - 5:00 PM") into opens/closes strings.
	 * Returns null if unparseable or "Closed".
	 *
	 * @param string $hours_str
	 * @return array{opens: string, closes: string}|null
	 */
	public static function parse_hours( $hours_str ) {
		$s = trim( (string) $hours_str );
		if ( '' === $s || 0 === strcasecmp( $s, 'closed' ) ) {
			return null;
		}
		// Expect "HH:MM AM - HH:MM PM" or "9:00 AM - 5:00 PM"
		if ( preg_match( '/^(\d{1,2}:\d{2}\s*(?:AM|PM))\s*[-–]\s*(\d{1,2}:\d{2}\s*(?:AM|PM))$/i', $s, $m ) ) {
			$to24 = static function ( $t ) {
				$dt = DateTime::createFromFormat( 'g:i A', strtoupper( trim( $t ) ) );
				return $dt ? $dt->format( 'H:i' ) : null;
			};
			$opens  = $to24( $m[1] );
			$closes = $to24( $m[2] );
			if ( $opens && $closes ) {
				return array( 'opens' => $opens, 'closes' => $closes );
			}
		}
		return null;
	}

	/**
	 * Build openingHoursSpecification nodes from a location's hours array.
	 *
	 * @param array $hours Keyed by day (monday → "9:00 AM - 5:00 PM").
	 * @return array  Array of schema.org OpeningHoursSpecification nodes.
	 */
	public static function build_opening_hours_spec( $hours ) {
		if ( ! is_array( $hours ) || empty( $hours ) ) {
			return array();
		}
		$day_map = array(
			'monday'    => 'Monday',
			'tuesday'   => 'Tuesday',
			'wednesday' => 'Wednesday',
			'thursday'  => 'Thursday',
			'friday'    => 'Friday',
			'saturday'  => 'Saturday',
			'sunday'    => 'Sunday',
		);
		$specs = array();
		foreach ( self::DAYS as $day ) {
			if ( empty( $hours[ $day ] ) ) {
				continue;
			}
			$parsed = self::parse_hours( $hours[ $day ] );
			if ( $parsed ) {
				$specs[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => 'https://schema.org/' . $day_map[ $day ],
					'opens'     => $parsed['opens'],
					'closes'    => $parsed['closes'],
				);
			}
		}
		return $specs;
	}

	/**
	 * Format practice profile sections as plain-text blocks for /llms.txt.
	 *
	 * @param array|null $profile Pre-decoded profile; fetched from option if null.
	 * @return string Plain-text sections (empty string when profile is incomplete).
	 */
	public static function to_llms_sections( $profile = null ) {
		$p = null !== $profile ? $profile : self::get_merged_for_llms();
		if ( ! self::has_llms_context( $p ) ) {
			return '';
		}

		$plain = array( 'Gleo_Schema', 'plain_text' );
		$out   = '';

		// About / business description
		if ( ! empty( $p['business_description'] ) || ! empty( $p['about']['mission'] ) || ! empty( $p['trust_indicators'] ) ) {
			$out .= "## About\n\n";
			$desc = ! empty( $p['business_description'] ) ? $p['business_description'] : ( $p['about']['mission'] ?? '' );
			if ( '' !== $desc ) {
				$out .= call_user_func( $plain, $desc ) . "\n\n";
			}
			if ( ! empty( $p['about']['expertise'] ) && $p['about']['expertise'] !== $desc ) {
				$out .= call_user_func( $plain, $p['about']['expertise'] ) . "\n\n";
			}
			if ( ! empty( $p['trust_indicators'] ) ) {
				foreach ( $p['trust_indicators'] as $indicator ) {
					$indicator = call_user_func( $plain, (string) $indicator );
					if ( '' !== $indicator ) {
						$out .= "- {$indicator}\n";
					}
				}
				$out .= "\n";
			}
		}

		// Practice Information
		$type_labels = array(
			'dentist'        => 'Dental Practice',
			'physician'      => 'Physician Practice',
			'medical_clinic' => 'Medical Clinic',
			'other'          => 'Healthcare Practice',
		);
		if ( ! empty( $p['practice_type'] ) || ! empty( $p['specialty'] ) ) {
			$out .= "## Practice Information\n\n";
			if ( ! empty( $p['practice_type'] ) ) {
				$type_label = $type_labels[ $p['practice_type'] ] ?? ucwords( str_replace( '_', ' ', $p['practice_type'] ) );
				$out .= '- Type: ' . $type_label . "\n";
			}
			if ( '' !== ( $p['specialty'] ?? '' ) ) {
				$out .= '- Specialty: ' . call_user_func( $plain, $p['specialty'] ) . "\n";
			}
			$out .= "\n";
		}

		// Services
		if ( ! empty( $p['services'] ) ) {
			$out .= "## Services\n\n";
			$current_group = '';
			foreach ( $p['services'] as $svc ) {
				if ( ! is_array( $svc ) || empty( $svc['name'] ) ) {
					continue;
				}
				$group = $svc['group'] ?? 'General';
				if ( '' !== $group && $group !== $current_group && 'General' !== $group ) {
					$out .= '### ' . call_user_func( $plain, $group ) . "\n";
					$current_group = $group;
				}
				$name = call_user_func( $plain, $svc['name'] );
				$line = "- {$name}";
				if ( ! empty( $svc['summary'] ) ) {
					$line .= ': ' . call_user_func( $plain, $svc['summary'] );
				}
				if ( ! empty( $svc['url'] ) ) {
					$line .= ' (' . esc_url_raw( $svc['url'] ) . ')';
				}
				$out .= $line . "\n";
			}
			$out .= "\n";
		}

		// Locations
		if ( ! empty( $p['locations'] ) ) {
			$out .= "## Locations\n\n";
			foreach ( $p['locations'] as $loc ) {
				if ( ! is_array( $loc ) ) {
					continue;
				}
				$label = ! empty( $loc['label'] ) ? call_user_func( $plain, $loc['label'] ) : 'Office';
				$out  .= "### {$label}\n";

				$addr_parts = array_filter( array(
					! empty( $loc['street'] ) ? call_user_func( $plain, $loc['street'] ) : '',
					! empty( $loc['city'] )   ? call_user_func( $plain, $loc['city'] )   : '',
					! empty( $loc['state'] )  ? call_user_func( $plain, $loc['state'] )  : '',
					! empty( $loc['zip'] )    ? call_user_func( $plain, $loc['zip'] )    : '',
				) );
				if ( ! empty( $addr_parts ) ) {
					$out .= '- Address: ' . implode( ', ', $addr_parts ) . "\n";
				}
				if ( ! empty( $loc['phone'] ) ) {
					$out .= '- Phone: ' . call_user_func( $plain, $loc['phone'] ) . "\n";
				}
				if ( ! empty( $loc['email'] ) ) {
					$out .= '- Email: ' . call_user_func( $plain, $loc['email'] ) . "\n";
				}
				if ( ! empty( $loc['hours'] ) && is_array( $loc['hours'] ) ) {
					$out .= "- Hours:\n";
					foreach ( self::DAYS as $day ) {
						if ( ! empty( $loc['hours'][ $day ] ) ) {
							$out .= '  - ' . ucfirst( $day ) . ': ' . call_user_func( $plain, $loc['hours'][ $day ] ) . "\n";
						}
					}
				}
				$out .= "\n";
			}
		}

		// Providers
		if ( ! empty( $p['providers'] ) ) {
			$out .= "## Providers\n\n";
			foreach ( $p['providers'] as $provider ) {
				if ( ! is_array( $provider ) || empty( $provider['name'] ) ) {
					continue;
				}
				$name  = call_user_func( $plain, $provider['name'] );
				$creds = ! empty( $provider['credentials'] ) ? ', ' . call_user_func( $plain, $provider['credentials'] ) : '';
				$spec  = ! empty( $provider['specialty'] )   ? ' — ' . call_user_func( $plain, $provider['specialty'] )  : '';
				$out  .= "- {$name}{$creds}{$spec}\n";
			}
			$out .= "\n";
		}

		// Insurance
		if ( ! empty( $p['insurance_accepted'] ) ) {
			$out .= "## Insurance Accepted\n\n";
			foreach ( $p['insurance_accepted'] as $ins ) {
				$ins = call_user_func( $plain, (string) $ins );
				if ( '' !== $ins ) {
					$out .= "- {$ins}\n";
				}
			}
			$out .= "\n";
		}

		// Booking
		if ( '' !== ( $p['booking_url'] ?? '' ) ) {
			$out .= "## Booking\n\n";
			$out .= '- Book an appointment: ' . esc_url_raw( $p['booking_url'] ) . "\n\n";
		}

		// Target queries (seeds Phase 3 SOV)
		if ( ! empty( $p['target_queries'] ) ) {
			$out .= "## Patient Questions We Help Answer\n\n";
			foreach ( $p['target_queries'] as $q ) {
				$q = call_user_func( $plain, (string) $q );
				if ( '' !== $q ) {
					$out .= "- {$q}\n";
				}
			}
			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Human-readable labels for missing section keys.
	 *
	 * @return array<string, string>
	 */
	public static function missing_section_labels() {
		return array(
			'business_description' => 'Business description',
			'services'             => 'Services',
			'providers'            => 'Provider information',
			'contact'              => 'Contact information',
			'insurance'            => 'Insurance accepted',
			'booking'              => 'Booking URL',
		);
	}
}
