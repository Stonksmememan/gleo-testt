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
		$defaults = array(
			'practice_type'      => '',
			'specialty'          => '',
			'locations'          => array(),
			'providers'          => array(),
			'insurance_accepted' => array(),
			'booking_url'        => '',
			'target_queries'     => array(),
		);

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
		$p = null !== $profile ? $profile : self::get();
		if ( ! self::is_set( $p ) ) {
			return '';
		}

		$plain = array( 'Gleo_Schema', 'plain_text' );
		$out   = '';

		// Practice Information
		$type_labels = array(
			'dentist'        => 'Dental Practice',
			'physician'      => 'Physician Practice',
			'medical_clinic' => 'Medical Clinic',
			'other'          => 'Healthcare Practice',
		);
		$out .= "## Practice Information\n\n";
		$type_label = $type_labels[ $p['practice_type'] ] ?? ucwords( str_replace( '_', ' ', $p['practice_type'] ) );
		$out .= '- Type: ' . $type_label . "\n";
		if ( '' !== $p['specialty'] ) {
			$out .= '- Specialty: ' . call_user_func( $plain, $p['specialty'] ) . "\n";
		}
		$out .= "\n";

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
		if ( '' !== $p['booking_url'] ) {
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
}
