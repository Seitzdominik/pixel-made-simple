<?php
/**
 * Meta Conversions API: serverseitiger, nicht-blockierender Event-Versand.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_CAPI {

	/**
	 * Graph-API-Version. Über den Filter 'lmpct_graph_api_version' anpassbar,
	 * damit das Plugin auch nach Meta-Deprecations ohne Update weiterläuft.
	 */
	const GRAPH_API_VERSION = 'v26.0';

	/**
	 * Protokoll der CAPI-Versuche dieses Requests (für die Live-Debug-Leiste).
	 *
	 * @var array[]
	 */
	private static $log = array();

	/**
	 * Protokoll auslesen.
	 *
	 * @return array[]
	 */
	public static function get_log() {
		return self::$log;
	}

	/**
	 * Gematchte Events als einen gebündelten CAPI-Request senden.
	 *
	 * @param array[] $events           Events inkl. 'event_id' (identisch zum Browser-Pixel).
	 * @param array   $settings         Plugin-Einstellungen.
	 * @param string  $source_url       Aktuelle Seiten-URL.
	 * @param array   $extra_user_data  Zusätzliche (bereits gehashte) user_data-Felder,
	 *                                  z. B. em/ph aus dem Formular-Auto-Grabber.
	 * @return array Status-Eintrag (auch im Log hinterlegt).
	 */
	public static function send_events( array $events, array $settings, $source_url, array $extra_user_data = array() ) {
		$names = array();
		foreach ( $events as $event ) {
			$names[] = LMPCT_Settings::resolved_event_name( $event );
		}

		// DSGVO: Ohne Marketing-Einwilligung wird kein Request abgesetzt.
		if ( class_exists( 'LMPCT_Consent' ) && ! LMPCT_Consent::has_marketing_consent() ) {
			return self::log( $names, 'consent_blocked', 0, __( 'No marketing consent', 'lightweight-meta-pixel-capi-tracker' ), array() );
		}

		$pixel_id = preg_replace( '/\D+/', '', (string) ( $settings['pixel_id'] ?? '' ) );
		$token    = (string) ( $settings['capi_token'] ?? '' );

		if ( '' === $pixel_id || '' === $token || empty( $events ) ) {
			return self::log( $names, 'skipped', 0, __( 'Pixel ID or access token missing', 'lightweight-meta-pixel-capi-tracker' ), array() );
		}

		$user_data = array_merge( self::build_user_data( $settings ), $extra_user_data );
		$custom    = class_exists( 'LMPCT_Attribution' ) ? LMPCT_Attribution::custom_data() : array();
		$now       = time();
		$data      = array();

		foreach ( $events as $event ) {
			$payload = array(
				'event_name'       => LMPCT_Settings::resolved_event_name( $event ),
				'event_time'       => $now,
				'event_id'         => (string) $event['event_id'],
				'event_source_url' => $source_url,
				'action_source'    => 'website',
				'user_data'        => $user_data,
			);

			if ( ! empty( $custom ) ) {
				$payload['custom_data'] = $custom;
			}

			/**
			 * Einzelnes CAPI-Event vor dem Versand anpassen (z. B. custom_data ergänzen).
			 *
			 * @param array $payload Event-Payload.
			 * @param array $event   Konfiguriertes Plugin-Event.
			 */
			$data[] = apply_filters( 'lmpct_capi_event_data', $payload, $event );
		}

		$body = array(
			'data'         => $data,
			'access_token' => $token,
		);

		$test_code = self::active_test_event_code( $settings );
		if ( '' !== $test_code ) {
			$body['test_event_code'] = $test_code;
		}

		/**
		 * Graph-API-Version überschreiben, falls Meta die gepinnte Version abkündigt.
		 *
		 * @param string $version Z. B. 'v26.0'.
		 */
		$version = apply_filters( 'lmpct_graph_api_version', self::GRAPH_API_VERSION );
		$version = preg_replace( '/[^v0-9.]/', '', (string) $version );

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/events', $version, rawurlencode( $pixel_id ) );

		/**
		 * Für Debugging auf blockierend schalten; die Antwort erscheint dann in der
		 * Live-Debug-Leiste bzw. bei aktivem WP_DEBUG_LOG im Debug-Log.
		 *
		 * @param bool $blocking Standard: false (fire-and-forget).
		 */
		$blocking = (bool) apply_filters( 'lmpct_capi_blocking', false );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'   => $blocking ? 5 : 2,
				'blocking'  => $blocking,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( $body ),
				'sslverify' => true,
			)
		);

		$keys = array_keys( $user_data );

		if ( ! $blocking ) {
			return self::log( $names, 'sent', 0, '', $keys );
		}

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[LMPCT] CAPI-Fehler: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return self::log( $names, 'error', 0, $message, $keys );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[LMPCT] CAPI-Antwort: ' . $raw ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( $code >= 200 && $code < 300 ) {
			return self::log( $names, 'ok', $code, '', $keys );
		}

		$message = $raw;
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
			$message = (string) $decoded['error']['message'];
		}

		return self::log( $names, 'error', $code, $message, $keys );
	}

	/**
	 * Status-Eintrag protokollieren.
	 *
	 * @param string[] $events    Event-Namen.
	 * @param string   $status    sent|ok|error|consent_blocked|skipped.
	 * @param int      $code      HTTP-Statuscode.
	 * @param string   $message   Fehlermeldung.
	 * @param string[] $match_keys Verwendete user_data-Schlüssel.
	 * @return array
	 */
	private static function log( array $events, $status, $code = 0, $message = '', array $match_keys = array() ) {
		$entry = array(
			'events'     => $events,
			'status'     => $status,
			'code'       => $code,
			'message'    => (string) substr( (string) $message, 0, 300 ),
			'match_keys' => $match_keys,
		);

		self::$log[] = $entry;

		return $entry;
	}

	/**
	 * Test Event Code mit 12h-Auto-Expiry: Abgelaufene Codes werden serverseitig
	 * ignoriert und direkt aus der Datenbank entfernt, damit kein versehentliches
	 * Test-Tracking im Live-Betrieb passiert.
	 *
	 * @param array $settings Plugin-Einstellungen.
	 * @return string Aktiver Test-Code oder leerer String.
	 */
	private static function active_test_event_code( array $settings ) {
		$code = (string) ( $settings['test_event_code'] ?? '' );
		if ( '' === $code ) {
			return '';
		}

		$created_at = (int) ( $settings['test_code_created_at'] ?? 0 );
		$max_age    = defined( 'HOUR_IN_SECONDS' ) ? 12 * HOUR_IN_SECONDS : 43200;

		if ( $created_at > 0 && ( time() - $created_at ) > $max_age ) {
			$settings['test_event_code']      = '';
			$settings['test_code_created_at'] = 0;
			update_option( LMPCT_Settings::OPTION_SETTINGS, $settings );
			return '';
		}

		return $code;
	}

	/**
	 * E-Mail-Adresse nach Meta-Vorgabe normalisieren und hashen.
	 *
	 * @param string $email Rohwert.
	 * @return string SHA-256-Hash oder leerer String.
	 */
	public static function hash_email( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		return hash( 'sha256', $email );
	}

	/**
	 * Telefonnummer nach Meta-Vorgabe normalisieren und hashen:
	 * nur Ziffern, ohne führende Nullen bzw. Verkehrsausscheidungsziffer.
	 *
	 * @param string $phone Rohwert.
	 * @return string SHA-256-Hash oder leerer String.
	 */
	public static function hash_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( '' === $digits ) {
			return '';
		}

		// Internationale Präfixe entfernen (0049… / 0176…).
		$digits = preg_replace( '/^0+/', '', $digits );

		/**
		 * Normalisierte Telefonnummer anpassen, z. B. um eine Landesvorwahl
		 * zu ergänzen, bevor gehasht wird.
		 *
		 * @param string $digits Nur-Ziffern-Nummer.
		 * @param string $phone  Ursprünglicher Rohwert.
		 */
		$digits = (string) apply_filters( 'lmpct_normalize_phone', $digits, $phone );

		if ( strlen( $digits ) < 6 ) {
			return '';
		}

		return hash( 'sha256', $digits );
	}

	/**
	 * user_data für die CAPI zusammenstellen.
	 *
	 * IP und User-Agent werden laut Meta-Spezifikation unverschlüsselt gesendet,
	 * personenbezogene Daten ausschließlich als SHA-256-Hash.
	 *
	 * @param array $settings Plugin-Einstellungen.
	 * @return array
	 */
	private static function build_user_data( array $settings ) {
		$user_data = array();

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validierung via filter_var.
			if ( $ip ) {
				$user_data['client_ip_address'] = $ip;
			}
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_data['client_user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$fbp = self::read_fb_cookie( '_fbp' );
		if ( $fbp ) {
			$user_data['fbp'] = $fbp;
		}

		$fbc = self::read_fb_cookie( '_fbc' );

		// Fallback: fbclid aus der aktuellen URL bzw. aus dem Attribution-Cookie
		// (überlebt so auch mehrere Seitenaufrufe nach dem Anzeigenklick).
		if ( ! $fbc && isset( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Zugriff auf einen Tracking-Parameter.
			$fbclid = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $_GET['fbclid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $fbclid ) {
				$fbc = 'fb.1.' . (string) round( microtime( true ) * 1000 ) . '.' . $fbclid;
			}
		}

		if ( ! $fbc && class_exists( 'LMPCT_Attribution' ) ) {
			$fbc = LMPCT_Attribution::fbc();
		}

		if ( $fbc ) {
			$user_data['fbc'] = $fbc;
		}

		if ( ! empty( $settings['hash_email'] ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				$hash = self::hash_email( $user->user_email );
				if ( '' !== $hash ) {
					$user_data['em'] = array( $hash );
				}
			}
		}

		/**
		 * user_data vor dem Versand anpassen.
		 *
		 * @param array $user_data user_data-Payload.
		 */
		return apply_filters( 'lmpct_capi_user_data', $user_data );
	}

	/**
	 * Meta-Cookie (_fbp/_fbc) sicher auslesen.
	 *
	 * @param string $name Cookie-Name.
	 * @return string Leerer String, wenn nicht vorhanden/ungültig.
	 */
	private static function read_fb_cookie( $name ) {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return '';
		}

		$value = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) wp_unslash( $_COOKIE[ $name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Cookie-Werte sind nach dem Filtern reines ASCII, substr ist hier sicher.
		return is_string( $value ) ? substr( $value, 0, 255 ) : '';
	}
}
