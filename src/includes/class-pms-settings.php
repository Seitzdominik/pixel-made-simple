<?php
/**
 * Zentraler Zugriff auf Einstellungen und Events inkl. Sanitization.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Settings {

	const OPTION_SETTINGS       = 'pms_settings';
	const OPTION_EVENTS         = 'pms_events';
	const OPTION_EVENTS_ENABLED = 'pms_events_enabled';

	/**
	 * Free-Limit für gleichzeitig AKTIVE Custom Events (Tab "URL-Events").
	 * Beliebig viele Event-Regeln lassen sich weiterhin anlegen/speichern –
	 * nur die Anzahl der gleichzeitig aktiven ist begrenzt. In der Pro-
	 * Version gibt es kein Limit (siehe is_pro()).
	 */
	const FREE_ACTIVE_EVENT_LIMIT = 2;

	/**
	 * Event-Log-Aufbewahrung (Tab "Event Log", siehe PMS_Logger). Free ist
	 * fest auf FREE_LOG_RETENTION_DAYS gesetzt und nicht konfigurierbar; Pro
	 * wählt aus ALLOWED_LOG_RETENTION_DAYS. Bewusst hier statt in PMS_Logger
	 * definiert: PMS_Settings ist die "Schaltzentrale" für alles, was ein
	 * gültiger Einstellungswert ist -- PMS_Logger konsumiert diese Konstanten
	 * nur, damit die Abhängigkeitsrichtung dieselbe bleibt wie überall sonst.
	 */
	const FREE_LOG_RETENTION_DAYS    = 3;
	const DEFAULT_LOG_RETENTION_DAYS = 7;
	const ALLOWED_LOG_RETENTION_DAYS = array( 3, 7, 14, 30 );

	/**
	 * Läuft gerade die Pro-Version?
	 *
	 * Defensiv per defined()-Check statt eines nackten PMS_IS_PRO-Zugriffs:
	 * dev-tools/test-suite.php lädt diese Klasse ohne eines der beiden
	 * Bootstrap-Files (die die Konstante sonst immer vor dem ersten Aufruf
	 * setzen), daher muss "noch nicht definiert" sicher als "Free" gelten.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return defined( 'PMS_IS_PRO' ) && true === PMS_IS_PRO;
	}

	/**
	 * Von Meta unterstützte Standard-Events plus "CustomEvent".
	 *
	 * Bei "CustomEvent" wird der interne Event-Name via fbq('trackCustom', ...)
	 * bzw. als event_name an die CAPI gesendet.
	 *
	 * @return string[]
	 */
	public static function meta_event_types() {
		return array(
			'CustomEvent',
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		);
	}

	/**
	 * Offizielle TikTok-Web-Events plus "CustomEvent".
	 *
	 * @return string[]
	 */
	public static function tiktok_event_types() {
		return array(
			'CustomEvent',
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'ClickButton',
			'CompletePayment',
			'CompleteRegistration',
			'Contact',
			'Download',
			'InitiateCheckout',
			'PlaceAnOrder',
			'Search',
			'SubmitForm',
			'Subscribe',
			'ViewContent',
		);
	}

	/**
	 * Abwärtskompatibler Alias (v1.x).
	 *
	 * @return string[]
	 */
	public static function event_types() {
		return self::meta_event_types();
	}

	/**
	 * Einstellungen mit Defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = array(
			// Global. Consent-Erkennung ist bei Neuinstallation bewusst aktiv (DSGVO).
			'exclude_admins'        => 1,
			'consent_detection'     => 1,
			// Meta.
			'pixel_enabled'         => 0,
			'pixel_id'              => '',
			'capi_enabled'          => 0,
			'capi_token'            => '',
			'test_event_code'       => '',
			'test_code_created_at'  => 0,
			'hash_email'            => 0,
			// Google Ads.
			'google_enabled'      => 0,
			'google_tag_id'       => '',
			'google_consent_mode' => 1,
			// TikTok.
			'tiktok_enabled'      => 0,
			'tiktok_pixel_id'     => '',
			// Erweiterte Tracking-Features. Privacy-by-Default: Formular-Grabber
			// und UTM-Attribution sind bei Neuinstallation bewusst DEAKTIVIERT,
			// da sie zusätzliche personenbezogene Daten (Formularinhalte,
			// Kampagnen-Cookie) verarbeiten. Die Live-Debug-Leiste betrifft nur
			// eingeloggte Administratoren und bleibt daher aktiv.
			'form_tracking'        => 0,
			'form_event_type'      => 'Lead',
			'form_url_filter'      => '',
			'form_exclude_system'  => 1,
			'utm_passthrough'      => 0,
			'enable_utm_form_fill' => 0,
			'utm_form_fill_mode'   => 'all',
			'utm_form_fill_urls'   => '',
			'debug_bar'            => 1,
			// Event Log (Tab "Event Log"). Nur in Pro tatsächlich wählbar --
			// siehe PMS_Logger::retention_days() für den Free/Pro-Unterschied.
			'log_retention_days'   => 7,
		);

		$settings = get_option( self::OPTION_SETTINGS, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Sanitize-Callback für die Settings API (Tab "Allgemein").
	 *
	 * @param mixed $input Rohdaten aus dem Formular.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		$test_code = preg_replace( '/[^A-Za-z0-9]+/', '', (string) ( $input['test_event_code'] ?? '' ) );

		// Test-Code-Zeitstempel: bei neuem/geändertem Code neu setzen, bei
		// unverändertem Code beibehalten (Basis für das 12h-Auto-Expiry).
		$old        = get_option( self::OPTION_SETTINGS, array() );
		$old_code   = is_array( $old ) ? (string) ( $old['test_event_code'] ?? '' ) : '';
		$old_ts     = is_array( $old ) ? (int) ( $old['test_code_created_at'] ?? 0 ) : 0;
		$created_at = 0;

		if ( '' !== $test_code ) {
			$created_at = ( $test_code === $old_code && $old_ts > 0 ) ? $old_ts : time();
		}

		// Der CAPI-Token hat nur auf Tab "Allgemein" ein echtes Feld (siehe
		// PMS_Admin::render_advanced_tab(), die ihn bewusst NICHT als Hidden-
		// Feld mitschickt, um seine Sichtbarkeit im Seitenquelltext auf diesen
		// einen Tab zu beschränken). Fehlt der Schlüssel komplett im Input (weil
		// von einem anderen Tab gespeichert wurde), bleibt der bisherige Wert
		// erhalten, statt ihn stillschweigend zu leeren.
		$old_token  = is_array( $old ) ? (string) ( $old['capi_token'] ?? '' ) : '';
		$capi_token = array_key_exists( 'capi_token', $input )
			? trim( sanitize_textarea_field( (string) $input['capi_token'] ) )
			: $old_token;

		return array(
			'exclude_admins'       => empty( $input['exclude_admins'] ) ? 0 : 1,
			'consent_detection'    => empty( $input['consent_detection'] ) ? 0 : 1,
			'pixel_enabled'        => empty( $input['pixel_enabled'] ) ? 0 : 1,
			'pixel_id'             => preg_replace( '/\D+/', '', (string) ( $input['pixel_id'] ?? '' ) ),
			'capi_enabled'         => empty( $input['capi_enabled'] ) ? 0 : 1,
			'capi_token'           => $capi_token,
			'test_event_code'      => $test_code,
			'test_code_created_at' => $created_at,
			'hash_email'           => empty( $input['hash_email'] ) ? 0 : 1,
			'google_enabled'       => empty( $input['google_enabled'] ) ? 0 : 1,
			'google_tag_id'        => preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) ( $input['google_tag_id'] ?? '' ) ),
			'google_consent_mode'  => empty( $input['google_consent_mode'] ) ? 0 : 1,
			'tiktok_enabled'       => empty( $input['tiktok_enabled'] ) ? 0 : 1,
			'tiktok_pixel_id'      => preg_replace( '/[^A-Za-z0-9]+/', '', (string) ( $input['tiktok_pixel_id'] ?? '' ) ),
			'form_tracking'        => empty( $input['form_tracking'] ) ? 0 : 1,
			'form_event_type'      => in_array( (string) ( $input['form_event_type'] ?? '' ), self::form_event_types(), true )
				? (string) $input['form_event_type']
				: 'Lead',
			'form_url_filter'      => self::sanitize_url_filter( $input['form_url_filter'] ?? '' ),
			'form_exclude_system'  => empty( $input['form_exclude_system'] ) ? 0 : 1,
			'utm_passthrough'      => empty( $input['utm_passthrough'] ) ? 0 : 1,
			'enable_utm_form_fill' => empty( $input['enable_utm_form_fill'] ) ? 0 : 1,
			'utm_form_fill_mode'   => in_array( (string) ( $input['utm_form_fill_mode'] ?? '' ), array( 'all', 'include', 'exclude' ), true )
				? (string) $input['utm_form_fill_mode']
				: 'all',
			'utm_form_fill_urls'   => self::sanitize_url_patterns( $input['utm_form_fill_urls'] ?? '' ),
			'debug_bar'            => empty( $input['debug_bar'] ) ? 0 : 1,
			'log_retention_days'   => in_array( (int) ( $input['log_retention_days'] ?? 0 ), self::ALLOWED_LOG_RETENTION_DAYS, true )
				? (int) $input['log_retention_days']
				: self::DEFAULT_LOG_RETENTION_DAYS,
		);
	}

	/**
	 * Erlaubte Meta-Event-Typen für den Formular-Auto-Grabber.
	 *
	 * @return string[]
	 */
	public static function form_event_types() {
		return array( 'Lead', 'Contact' );
	}

	/**
	 * URL-Filter säubern: kommagetrennte Pfade, kleingeschrieben.
	 *
	 * @param mixed $value Rohwert aus dem Formular.
	 * @return string Normalisierte, kommagetrennte Liste.
	 */
	public static function sanitize_url_filter( $value ) {
		$value = sanitize_text_field( (string) $value );
		$parts = array();

		foreach ( explode( ',', $value ) as $part ) {
			$part = strtolower( trim( $part ) );
			$part = preg_replace( '#[^a-z0-9/_\-.%]#', '', $part );

			if ( '' !== $part ) {
				$parts[] = substr( $part, 0, 200 );
			}
		}

		return implode( ', ', array_slice( array_unique( $parts ), 0, 50 ) );
	}

	/**
	 * URL-Muster für den UTM-Form-Fill säubern: zeilenbasiert (ein Muster pro
	 * Zeile), kleingeschrieben. Anders als sanitize_url_filter() bleibt ein
	 * abschließendes "*" erhalten (einfacher Prefix-Platzhalter, z. B. "/lp/*").
	 *
	 * @param mixed $value Rohwert aus dem Formular.
	 * @return string Normalisierte, zeilengetrennte Liste.
	 */
	public static function sanitize_url_patterns( $value ) {
		$value = sanitize_textarea_field( (string) $value );
		$lines = preg_split( '/[\r\n]+/', $value );
		$parts = array();

		foreach ( (array) $lines as $line ) {
			$line = strtolower( trim( $line ) );
			$line = preg_replace( '#[^a-z0-9/_\-.%*]#', '', $line );

			if ( '' !== $line ) {
				$parts[] = substr( $line, 0, 200 );
			}
		}

		return implode( "\n", array_slice( array_unique( $parts ), 0, 50 ) );
	}

	/**
	 * UTM-Form-Fill-URL-Muster als Array.
	 *
	 * @return string[] Leeres Array = kein Muster hinterlegt.
	 */
	public static function utm_form_fill_url_patterns() {
		$settings = self::get();
		$value    = self::sanitize_url_patterns( $settings['utm_form_fill_urls'] ?? '' );

		if ( '' === $value ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $value ) ) ) );
	}

	/**
	 * Konfigurierter Meta-Event-Typ für Formular-Absendungen.
	 *
	 * @return string 'Lead' oder 'Contact'.
	 */
	public static function form_event_type() {
		$settings = self::get();
		$type     = (string) ( $settings['form_event_type'] ?? 'Lead' );

		return in_array( $type, self::form_event_types(), true ) ? $type : 'Lead';
	}

	/**
	 * URL-Filter als Array.
	 *
	 * @return string[] Leeres Array = auf der gesamten Website aktiv.
	 */
	public static function form_url_filters() {
		$settings = self::get();
		$filter   = self::sanitize_url_filter( $settings['form_url_filter'] ?? '' );

		if ( '' === $filter ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $filter ) ) ) );
	}

	/**
	 * Alle konfigurierten Events (id => Event-Array).
	 *
	 * @return array[]
	 */
	public static function get_events() {
		$events = get_option( self::OPTION_EVENTS, array() );

		if ( ! is_array( $events ) ) {
			return array();
		}

		$clean = array();
		foreach ( $events as $event ) {
			$event = self::sanitize_event( $event );
			if ( $event ) {
				$clean[ $event['id'] ] = $event;
			}
		}

		return $clean;
	}

	/**
	 * Events speichern.
	 *
	 * In der Free-Version zusätzlich hartes serverseitiges Limit von
	 * FREE_ACTIVE_EVENT_LIMIT gleichzeitig aktiven Events – unabhängig davon,
	 * über welchen Weg gespeichert wird (Admin-UI-Handler in class-pms-admin.php
	 * ODER JSON-Import in PMS_Tools::import_from_json()). Diese Methode ist der
	 * einzige Ort, an dem Events tatsächlich persistiert werden, also der
	 * richtige Ort für die Whitelist/den Cap – dieselbe Rolle wie
	 * sanitize_settings() für die Einstellungen.
	 *
	 * Events über dem Limit werden NICHT verworfen, nur auf inaktiv gesetzt
	 * (erste FREE_ACTIVE_EVENT_LIMIT aktive Events in Array-Reihenfolge bleiben
	 * aktiv) – die Konfiguration bleibt vollständig erhalten, falls später auf
	 * Pro aktualisiert wird.
	 *
	 * @param array $events Events, keyed by id.
	 * @return void
	 */
	public static function save_events( array $events ) {
		if ( ! self::is_pro() ) {
			$active_count = 0;
			foreach ( $events as $id => $event ) {
				if ( empty( $event['active'] ) ) {
					continue;
				}
				++$active_count;
				if ( $active_count > self::FREE_ACTIVE_EVENT_LIMIT ) {
					$events[ $id ]['active'] = 0;
				}
			}
		}

		update_option( self::OPTION_EVENTS, array_values( $events ), false );
	}

	/**
	 * Ist das Free-Limit für gleichzeitig aktive Events erreicht?
	 *
	 * In der Pro-Version immer false (kein Limit). $exclude_id lässt das
	 * gerade bearbeitete/umgeschaltete Event selbst außen vor, damit z. B.
	 * das Deaktivieren-und-wieder-Aktivieren desselben, bereits aktiven
	 * Events nicht fälschlich als "Limit erreicht" zählt.
	 *
	 * @param string $exclude_id Event-ID, die nicht mitgezählt werden soll.
	 * @return bool
	 */
	public static function free_event_limit_reached( $exclude_id = '' ) {
		if ( self::is_pro() ) {
			return false;
		}

		$active_count = 0;
		foreach ( self::get_events() as $id => $event ) {
			if ( $id === $exclude_id ) {
				continue;
			}
			if ( ! empty( $event['active'] ) ) {
				++$active_count;
			}
		}

		return $active_count >= self::FREE_ACTIVE_EVENT_LIMIT;
	}

	/**
	 * Sind benutzerdefinierte Events global aktiviert?
	 *
	 * @return bool
	 */
	public static function events_enabled() {
		return (bool) get_option( self::OPTION_EVENTS_ENABLED, 1 );
	}

	/**
	 * Einzelnes Event validieren und säubern.
	 *
	 * Events aus v1.x (ohne Plattform-Felder) gelten automatisch als Meta-Events.
	 *
	 * @param mixed $event Roh-Event.
	 * @return array|null Null, wenn das Event unbrauchbar ist.
	 */
	public static function sanitize_event( $event ) {
		if ( ! is_array( $event ) ) {
			return null;
		}

		$id          = sanitize_key( (string) ( $event['id'] ?? '' ) );
		$name        = sanitize_text_field( (string) ( $event['name'] ?? '' ) );
		$name        = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 50 ) : substr( $name, 0, 50 );
		$event_type  = (string) ( $event['event_type'] ?? '' );
		$match_type  = (string) ( $event['match_type'] ?? '' );
		$match_value = sanitize_text_field( (string) ( $event['match_value'] ?? '' ) );

		if ( '' === $id || '' === $name || '' === $match_value ) {
			return null;
		}

		if ( ! in_array( $event_type, self::meta_event_types(), true ) ) {
			return null;
		}

		if ( ! in_array( $match_type, array( 'exact', 'contains' ), true ) ) {
			$match_type = 'contains';
		}

		// Plattform-Zuweisung; fehlendes meta_enabled = Alt-Event aus v1.x.
		$meta_enabled   = array_key_exists( 'meta_enabled', $event ) ? ( empty( $event['meta_enabled'] ) ? 0 : 1 ) : 1;
		$google_enabled = empty( $event['google_enabled'] ) ? 0 : 1;
		$tiktok_enabled = empty( $event['tiktok_enabled'] ) ? 0 : 1;

		$google_label = preg_replace( '/[^A-Za-z0-9_\-]+/', '', (string) ( $event['google_label'] ?? '' ) );

		$tiktok_event = (string) ( $event['tiktok_event'] ?? '' );
		if ( '' === $tiktok_event ) {
			$tiktok_event = 'CompleteRegistration';
		}
		if ( ! in_array( $tiktok_event, self::tiktok_event_types(), true ) ) {
			return null;
		}

		// Mindestens eine Plattform; Google braucht zwingend ein Conversion Label.
		if ( ! $meta_enabled && ! $google_enabled && ! $tiktok_enabled ) {
			return null;
		}
		if ( $google_enabled && '' === $google_label ) {
			return null;
		}

		return array(
			'id'             => $id,
			'name'           => $name,
			'event_type'     => $event_type,
			'match_type'     => $match_type,
			'match_value'    => $match_value,
			'active'         => empty( $event['active'] ) ? 0 : 1,
			'meta_enabled'   => $meta_enabled,
			'google_enabled' => $google_enabled,
			'google_label'   => $google_label,
			'tiktok_enabled' => $tiktok_enabled,
			'tiktok_event'   => $tiktok_event,
		);
	}

	/**
	 * Der Meta-Event-Name, der tatsächlich gesendet wird.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function resolved_event_name( array $event ) {
		if ( 'CustomEvent' === $event['event_type'] ) {
			return $event['name'];
		}
		return $event['event_type'];
	}

	/**
	 * Der TikTok-Event-Name, der tatsächlich gesendet wird.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function resolved_tiktok_event_name( array $event ) {
		if ( 'CustomEvent' === ( $event['tiktok_event'] ?? '' ) ) {
			return $event['name'];
		}
		return (string) ( $event['tiktok_event'] ?? '' );
	}
}
