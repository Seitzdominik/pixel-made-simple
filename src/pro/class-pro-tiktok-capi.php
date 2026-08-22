<?php
/**
 * TikTok Events API: gemeinsamer Versand + Protokollierung.
 *
 * Bis v0.6.10 haben PMS_Pro_Woo_Purchase und PMS_Pro_SureCart_Purchase den
 * kompletten Sende-Block (Blocking-Filter, wp_remote_post(), Endpoint,
 * Header) jeweils als eigene, wortgleiche Kopie getragen -- und BEIDE haben
 * das Ergebnis verworfen, weshalb TikTok-Dispatches im Event Log gar nicht
 * auftauchten. Seit v0.6.11 läuft der Versand über diese eine Stelle, die
 * zusätzlich dasselbe leistet wie PMS_CAPI::log() für Meta:
 *
 * - request-lokales Log für die Live-Debug-Leiste (siehe get_log()),
 * - persistente Event-Log-Zeile über PMS_Logger mit HTTP-Status und den
 *   tatsächlich übergebenen Match Keys.
 *
 * TikTok-Besonderheit gegenüber Meta: Die Events API antwortet auch bei
 * FACHLICHEN Fehlern mit HTTP 200 und signalisiert den Fehler ausschließlich
 * über ein "code"-Feld im JSON-Body (0 = Erfolg). Ein reiner Statuscode-Check
 * -- wie er für Meta genügt -- würde einen abgelehnten Request deshalb als
 * Erfolg protokollieren. parse_response() wertet daher beides aus.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_TikTok_CAPI {

	const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

	/**
	 * Protokoll der Events-API-Versuche dieses Requests (Live-Debug-Leiste).
	 * Pendant zu PMS_CAPI::$log.
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
	 * Events-API-Request absetzen und das Ergebnis protokollieren.
	 *
	 * @param array  $body       Vollständiger Request-Body (inkl. data[] und
	 *                           ggf. test_event_code).
	 * @param string $token      Access Token.
	 * @param array  $context {
	 *     @type string   $event_name Event-Name fürs Log (z. B. "CompletePayment").
	 *     @type string   $event_id   Event-ID (Dedup-Abgleich mit dem Browser-Pixel).
	 *     @type string[] $match_keys Namen der übergebenen user-Felder (NIE die Werte).
	 *     @type string   $source     'capi' oder 'both' (Browser-Pixel bestätigt).
	 * }
	 * @return array Status-Eintrag (auch im Log hinterlegt).
	 */
	public static function send( array $body, $token, array $context = array() ) {
		$event_name = (string) ( $context['event_name'] ?? '' );
		$event_id   = (string) ( $context['event_id'] ?? '' );
		$match_keys = isset( $context['match_keys'] ) ? (array) $context['match_keys'] : array();
		$source     = (string) ( $context['source'] ?? 'capi' );

		/**
		 * Für Debugging auf blockierend schalten, analog zu pms_capi_blocking
		 * für Meta (class-pms-capi.php). Die Live-Debug-Leiste setzt diesen
		 * Filter seit v0.6.11 selbst auf true, damit Administratoren echte
		 * Statuscodes statt eines Fire-and-Forget-"gesendet" sehen.
		 *
		 * @param bool $blocking Standard: false (fire-and-forget).
		 */
		$blocking = (bool) apply_filters( 'pms_tiktok_capi_blocking', false );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'   => $blocking ? 5 : 2,
				'blocking'  => $blocking,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Access-Token' => (string) $token,
				),
				'body'      => wp_json_encode( $body ),
				'sslverify' => true,
			)
		);

		if ( ! $blocking ) {
			// Wie bei Meta: Die Antwort wird bewusst nicht abgewartet, der
			// Status bleibt deshalb unbekannt (http_status 0 OHNE Fehlertext
			// = "gesendet", siehe PMS_Logger::is_error_row()).
			return self::log( $event_name, $event_id, 'sent', 0, '', $match_keys, $source );
		}

		list( $status, $code, $message ) = self::parse_response( $response );

		return self::log( $event_name, $event_id, $status, $code, $message, $match_keys, $source );
	}

	/**
	 * Antwort der Events API auswerten.
	 *
	 * @param array|WP_Error $response Rückgabe von wp_remote_post().
	 * @return array{0:string,1:int,2:string} status, http_status, message.
	 */
	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'error', 0, $response->get_error_message() );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PMS] TikTok-Events-API-Antwort: ' . $raw ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$decoded = json_decode( $raw, true );

		// Fachlicher Fehler trotz HTTP 200 (siehe Klassen-Doku oben): TikTok
		// legt den eigentlichen Status in body.code, 0 bedeutet Erfolg.
		if ( is_array( $decoded ) && isset( $decoded['code'] ) && 0 !== (int) $decoded['code'] ) {
			$message = (string) ( $decoded['message'] ?? '' );
			if ( '' === $message ) {
				$message = 'TikTok error code ' . (int) $decoded['code'];
			}
			return array( 'error', $http, $message );
		}

		if ( $http >= 200 && $http < 300 ) {
			return array( 'ok', $http, '' );
		}

		$message = $raw;
		if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
			$message = (string) $decoded['message'];
		}

		return array( 'error', $http, $message );
	}

	/**
	 * Status-Eintrag protokollieren -- request-lokal für die Live-Debug-Leiste
	 * UND persistent im Event Log. Bewusst dieselbe Aufteilung und dieselben
	 * Statuswerte wie PMS_CAPI::log(), damit beide Plattformen im Event-Log-Tab
	 * identisch gelesen werden können.
	 *
	 * @param string   $event_name Event-Name.
	 * @param string   $event_id   Event-ID.
	 * @param string   $status     sent|ok|error.
	 * @param int      $code       HTTP-Statuscode (0 = unbekannt).
	 * @param string   $message    Fehlermeldung.
	 * @param string[] $match_keys Übergebene user-Feldnamen.
	 * @param string   $source     'capi' oder 'both'.
	 * @return array
	 */
	private static function log( $event_name, $event_id, $status, $code, $message, array $match_keys, $source ) {
		// $message stammt aus der rohen HTTP-Antwort einer externen API und
		// landet u. a. in der Live-Debug-Leiste -- Tags entfernen und kappen,
		// bevor er irgendwo weiterverarbeitet wird (identisch zu PMS_CAPI::log()).
		$message = wp_strip_all_tags( (string) $message );
		$message = substr( $message, 0, 300 );

		$entry = array(
			'events'     => array( $event_name ),
			'status'     => $status,
			'code'       => (int) $code,
			'message'    => $message,
			'match_keys' => $match_keys,
			'platform'   => PMS_Logger::PLATFORM_TIKTOK,
		);

		self::$log[] = $entry;

		if ( class_exists( 'PMS_Logger' ) ) {
			PMS_Logger::record(
				$event_name,
				$event_id,
				$source,
				(int) $code,
				$match_keys,
				$message,
				PMS_Logger::PLATFORM_TIKTOK
			);
		}

		return $entry;
	}
}
