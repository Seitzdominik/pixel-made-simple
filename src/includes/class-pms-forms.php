<?php
/**
 * Formular-Auto-Grabber: serverseitige Verarbeitung der Lead-Events.
 *
 * Das Frontend erkennt Formular-Absendungen, feuert das Browser-Event und
 * meldet Event-ID sowie Kontaktdaten hier an. Die Rohdaten werden ausschließlich
 * gehasht (SHA-256) an die Conversions API übergeben und niemals gespeichert.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Forms {

	const NONCE_ACTION = 'pms_form_lead';

	/**
	 * Harte Obergrenzen für POST-Parameter dieses (nopriv-)AJAX-Endpunkts.
	 * Schützt vor überdimensionierten Payloads durch nicht-authentifizierte
	 * Anfragen, bevor teurere Verarbeitung (Regex, Hashing, Graph-API-Call)
	 * überhaupt beginnt.
	 */
	const MAX_LEN_EVENT_ID = 64;
	const MAX_LEN_FIELD    = 255;
	const MAX_LEN_URL      = 2000;

	public static function init() {
		add_action( 'wp_ajax_pms_form_lead', array( __CLASS__, 'handle_lead' ) );
		add_action( 'wp_ajax_nopriv_pms_form_lead', array( __CLASS__, 'handle_lead' ) );
	}

	/**
	 * Ist das automatische Formular-Tracking aktiv?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = PMS_Settings::get();
		return ! empty( $settings['form_tracking'] );
	}

	/**
	 * Konfigurierter Meta-Event-Typ für Formular-Absendungen.
	 *
	 * @return string 'Lead' oder 'Contact'.
	 */
	public static function event_type() {
		return PMS_Settings::form_event_type();
	}

	/**
	 * Ist der Formular-Grabber auf diesem Pfad aktiv?
	 *
	 * Leerer Filter = gesamte Website. Andernfalls muss der Pfad einen der
	 * hinterlegten Teilstrings enthalten.
	 *
	 * @param string $path Pfad (z. B. "/kontakt/").
	 * @return bool
	 */
	public static function url_allowed( $path ) {
		$filters = PMS_Settings::form_url_filters();

		if ( empty( $filters ) ) {
			return true;
		}

		$path = strtolower( (string) $path );

		foreach ( $filters as $needle ) {
			if ( '' !== $needle && false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * AJAX: Lead-Event verarbeiten und serverseitig an die CAPI senden.
	 *
	 * @return void
	 */
	public static function handle_lead() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'reason' => 'disabled' ), 200 );
		}

		$settings = PMS_Settings::get();

		// Längenbegrenzung VOR jeder weiteren Verarbeitung: dieser Endpunkt ist
		// über wp_ajax_nopriv_ auch ohne Login erreichbar, daher werden alle
		// Eingaben hart gekappt, bevor Regex/Hashing/API-Call anfallen. Vorab
		// geparst (reine Eingabe-Normalisierung, kein Seiteneffekt), damit auch
		// die frühen Bail-outs unten (insbesondere capi_inactive) bei
		// bestätigtem Browser-Dispatch noch einen Event-Log-Eintrag schreiben
		// können, statt ein rein browserseitig getracktes Event stillschweigend
		// gar nicht zu protokollieren.
		$event_id = substr( (string) wp_unslash( $_POST['event_id'] ?? '' ), 0, self::MAX_LEN_EVENT_ID );
		$event_id = sanitize_text_field( $event_id );
		$event_id = preg_replace( '/[^A-Za-z0-9\-]/', '', $event_id );

		if ( '' === $event_id ) {
			wp_send_json_error( array( 'reason' => 'missing_event_id' ), 400 );
		}

		// Event-Name gegen die Whitelist prüfen; alles andere fällt auf die
		// konfigurierte Einstellung zurück (der Client bestimmt sie nicht frei).
		$event_name = substr( (string) wp_unslash( $_POST['event_name'] ?? '' ), 0, self::MAX_LEN_FIELD );
		$event_name = sanitize_text_field( $event_name );
		if ( ! in_array( $event_name, PMS_Settings::form_event_types(), true ) ) {
			$event_name = self::event_type();
		}

		// Einziges Signal, ob der Browser-Pixel tatsächlich gefeuert hat (siehe
		// frontend.js#fireLead) -- window.fbq kann z. B. fehlen, wenn die
		// Meta-Plattform selbst deaktiviert ist.
		$browser_fired = ! empty( $_POST['browser_fired'] );

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'reason' => 'admin_excluded' ), 200 );
		}

		/** Dokumentiert in class-pms-frontend.php */
		if ( ! apply_filters( 'pms_allow_tracking', true ) ) {
			wp_send_json_error( array( 'reason' => 'tracking_disabled' ), 200 );
		}

		// Server-Gate (siehe PMS_Consent::has_server_consent()): der
		// Browser-Pixel dieses Leads ist zu diesem Zeitpunkt bereits
		// clientseitig entschieden worden (frontend.js), hier geht es nur noch
		// um den CAPI-Request.
		if ( ! PMS_Consent::has_server_consent() ) {
			wp_send_json_error( array( 'reason' => 'no_consent' ), 200 );
		}

		if ( empty( $settings['capi_enabled'] ) || empty( $settings['pixel_id'] ) || empty( $settings['capi_token'] ) ) {
			// CAPI ist aus/nicht konfiguriert, der Browser-Pixel lief aber ganz
			// normal weiter (häufiges Setup, gerade bei kleineren Sites ohne
			// CAPI-Token) -- dafür trotzdem einen eigenständigen Event-Log-
			// Eintrag schreiben, statt das Event komplett unsichtbar zu machen.
			if ( $browser_fired && class_exists( 'PMS_Logger' ) ) {
				PMS_Logger::record( $event_name, $event_id, 'browser', 0, array() );
			}
			wp_send_json_error( array( 'reason' => 'capi_inactive' ), 200 );
		}

		$source_url = self::source_url();

		// URL-Filter auch serverseitig durchsetzen, damit der Endpunkt nicht
		// über manipulierte Requests umgangen werden kann.
		if ( ! self::url_allowed( (string) wp_parse_url( $source_url, PHP_URL_PATH ) ) ) {
			wp_send_json_error( array( 'reason' => 'url_filtered' ), 200 );
		}

		// Kontaktdaten ausschließlich gehasht weiterreichen. Rohwerte werden
		// hier NUR im RAM verarbeitet (gekappt -> gehasht) und nie geloggt,
		// gespeichert oder in der Response zurückgegeben.
		$user_data = array();

		$raw_email  = substr( (string) wp_unslash( $_POST['email'] ?? '' ), 0, self::MAX_LEN_FIELD );
		$email_hash = PMS_CAPI::hash_email( $raw_email );
		if ( '' !== $email_hash ) {
			$user_data['em'] = array( $email_hash );
		}
		unset( $raw_email );

		$raw_phone  = substr( (string) wp_unslash( $_POST['phone'] ?? '' ), 0, self::MAX_LEN_FIELD );
		$phone_hash = PMS_CAPI::hash_phone( $raw_phone );
		if ( '' !== $phone_hash ) {
			$user_data['ph'] = array( $phone_hash );
		}
		unset( $raw_phone );

		$event = array(
			'id'           => 'form-lead',
			'name'         => $event_name,
			'event_type'   => $event_name,
			'event_id'     => $event_id,
			'meta_enabled' => 1,
		);

		$status = PMS_CAPI::send_events(
			array( $event ),
			$settings,
			$source_url,
			$user_data,
			$browser_fired
		);

		wp_send_json_success(
			array(
				'event'      => $event_name,
				'event_id'   => $event_id,
				'status'     => $status['status'],
				'code'       => $status['code'],
				'message'    => $status['message'],
				'match_keys' => $status['match_keys'],
			)
		);
	}

	/**
	 * event_source_url für das Lead-Event ermitteln (nur eigene Domain).
	 *
	 * @return string
	 */
	private static function source_url() {
		$url = substr( (string) wp_unslash( $_POST['source_url'] ?? '' ), 0, self::MAX_LEN_URL );
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			$url = (string) wp_get_referer();
		}

		if ( '' === $url ) {
			return home_url( '/' );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $host && $home && strtolower( $host ) !== strtolower( $home ) ) {
			return home_url( '/' );
		}

		return $url;
	}
}
