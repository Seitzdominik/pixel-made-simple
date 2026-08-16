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
	 * Gematchte Events als einen gebündelten CAPI-Request senden.
	 *
	 * @param array[] $events     Events inkl. 'event_id' (identisch zum Browser-Pixel).
	 * @param array   $settings   Plugin-Einstellungen.
	 * @param string  $source_url Aktuelle Seiten-URL.
	 * @return void
	 */
	public static function send_events( array $events, array $settings, $source_url ) {
		$pixel_id = preg_replace( '/\D+/', '', (string) ( $settings['pixel_id'] ?? '' ) );
		$token    = (string) ( $settings['capi_token'] ?? '' );

		if ( '' === $pixel_id || '' === $token || empty( $events ) ) {
			return;
		}

		$user_data = self::build_user_data( $settings );
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

		if ( ! empty( $settings['test_event_code'] ) ) {
			$body['test_event_code'] = (string) $settings['test_event_code'];
		}

		/**
		 * Graph-API-Version überschreiben, falls Meta die gepinnte Version abkündigt.
		 *
		 * @param string $version Z. B. 'v23.0'.
		 */
		$version = apply_filters( 'lmpct_graph_api_version', self::GRAPH_API_VERSION );
		$version = preg_replace( '/[^v0-9.]/', '', (string) $version );

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/events', $version, rawurlencode( $pixel_id ) );

		/**
		 * Für Debugging auf blockierend schalten; Antwort landet dann bei aktivem
		 * WP_DEBUG_LOG im Debug-Log.
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

		if ( $blocking && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( is_wp_error( $response ) ) {
				error_log( '[LMPCT] CAPI-Fehler: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[LMPCT] CAPI-Antwort: ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * user_data für die CAPI zusammenstellen.
	 *
	 * IP und User-Agent werden laut Meta-Spezifikation unverschlüsselt gesendet,
	 * E-Mail (optional, nur eingeloggte Nutzer) ausschließlich als SHA-256-Hash.
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
		if ( ! $fbc && isset( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Zugriff auf einen Tracking-Parameter.
			$fbclid = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $_GET['fbclid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $fbclid ) {
				$fbc = 'fb.1.' . (string) round( microtime( true ) * 1000 ) . '.' . $fbclid;
			}
		}
		if ( $fbc ) {
			$user_data['fbc'] = $fbc;
		}

		if ( ! empty( $settings['hash_email'] ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				$user_data['em'] = array( hash( 'sha256', strtolower( trim( $user->user_email ) ) ) );
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
