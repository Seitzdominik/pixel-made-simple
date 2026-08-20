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
	 * Free-Limit für die GESAMTZAHL an Custom Events (Tab "URL-Events"), nicht
	 * nur die aktiven. Ab diesem Stand ist "Event hinzufügen" in der Free-
	 * Version gesperrt -- anders als bis v0.6.1, wo beliebig viele Events
	 * anlegbar waren und nur die Aktivierung gedeckelt war (siehe Changelog
	 * 0.6.2). In der Pro-Version gibt es kein Limit (siehe is_pro()).
	 */
	const FREE_EVENT_LIMIT = 2;

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
			// GA4 (Pro-only, seit v0.6.8). Eigenständig von google_tag_id/
			// google_enabled -- ein Kunde kann GA4 ohne Google Ads betreiben
			// (oder umgekehrt). Teilt sich denselben gtag.js-Loader wie Google
			// Ads (siehe PMS_Frontend::print_scripts()/build_google_js()),
			// deshalb bewusst KEIN eigenes ga4_enabled-Bool-Toggle -- die reine
			// Anwesenheit einer Measurement-ID entscheidet, ob gtag('config', ...)
			// dafür ausgegeben wird (dasselbe Muster wie z. B.
			// wc_google_conversion_label, das ebenfalls ohne eigenes Toggle
			// allein über seine Nicht-Leere gated).
			'ga4_measurement_id'  => '',
			// TikTok. tiktok_capi_enabled/tiktok_access_token spiegeln
			// capi_enabled/capi_token für Meta (Events API, seit v0.6.6 nur
			// für WooCommerce-Purchase genutzt, siehe pro/class-pro-woo-purchase.php).
			'tiktok_enabled'      => 0,
			'tiktok_pixel_id'     => '',
			'tiktok_capi_enabled' => 0,
			'tiktok_access_token' => '',
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
			// WooCommerce-Tracking (Pro-only, siehe pro/class-pro-woo.php).
			// Nur wirksam, wenn WooCommerce selbst aktiv ist -- die Klasse
			// initialisiert sich sonst gar nicht erst (siehe dortiger
			// class_exists('WooCommerce')-Guard).
			'wc_tracking_enabled'  => 0,
			'wc_content_id_type'   => 'id',
			// Purchase-Tracking (Pro-only, siehe pro/class-pro-woo-purchase.php).
			// Privacy-by-Default: Advanced Matching aus Bestelldaten (Name/Adresse)
			// ist bei Neuinstallation deaktiviert, aus demselben Grund wie
			// form_tracking/utm_passthrough oben.
			'wc_purchase_value_type'        => 'gross',
			'wc_purchase_advanced_matching' => 0,
			// Google Ads Purchase-Conversion-Label (Tab "E-Commerce", seit
			// v0.6.6) -- eigenständig von den PER-EVENT google_label-Werten
			// der URL-Events-Tabelle (siehe sanitize_event()), da eine
			// Bestellung kein konfiguriertes Custom Event ist.
			'wc_google_conversion_label'    => '',
			// SureCart-Tracking (Pro-only, seit v0.6.7, siehe
			// pro/class-pro-surecart.php) -- zweite E-Commerce-Integration
			// neben WooCommerce, identisches Schlüssel-Schema (Content-ID-
			// Modus, Purchase-Value-Modus, Advanced Matching, eigenes
			// Google-Ads-Conversion-Label für Purchase). Nur wirksam, wenn
			// SureCart selbst aktiv ist -- die Klasse initialisiert sich
			// sonst gar nicht erst (siehe dortiger Guard).
			'sc_tracking_enabled'           => 0,
			'sc_content_id_type'            => 'id',
			'sc_purchase_value_type'        => 'gross',
			'sc_purchase_advanced_matching' => 0,
			'sc_google_conversion_label'    => '',
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

		// Dasselbe Erhalt-bei-fehlendem-Schlüssel-Muster für den TikTok-
		// Events-API-Token (nur auf Tab "Allgemein" ein echtes Feld, siehe
		// render_general_tab()).
		$old_tiktok_token  = is_array( $old ) ? (string) ( $old['tiktok_access_token'] ?? '' ) : '';
		$tiktok_access_token = array_key_exists( 'tiktok_access_token', $input )
			? trim( sanitize_textarea_field( (string) $input['tiktok_access_token'] ) )
			: $old_tiktok_token;

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
			// Format lt. Google: "G-" + alphanumerisch (z. B. G-ABC1234XYZ).
			// Groß/klein wird vereinheitlicht (Nutzer kopiert die ID oft in
			// Kleinschreibung aus irgendeiner Doku), Zeichen-Whitelist danach
			// dasselbe Muster wie bei google_tag_id/tiktok_pixel_id oben.
			'ga4_measurement_id'   => preg_replace( '/[^A-Z0-9\-]+/', '', strtoupper( trim( (string) ( $input['ga4_measurement_id'] ?? '' ) ) ) ),
			'tiktok_enabled'       => empty( $input['tiktok_enabled'] ) ? 0 : 1,
			'tiktok_pixel_id'      => preg_replace( '/[^A-Za-z0-9]+/', '', (string) ( $input['tiktok_pixel_id'] ?? '' ) ),
			'tiktok_capi_enabled'  => empty( $input['tiktok_capi_enabled'] ) ? 0 : 1,
			'tiktok_access_token'  => $tiktok_access_token,
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
			'wc_tracking_enabled'            => empty( $input['wc_tracking_enabled'] ) ? 0 : 1,
			'wc_content_id_type'             => 'sku' === (string) ( $input['wc_content_id_type'] ?? '' ) ? 'sku' : 'id',
			'wc_purchase_value_type'         => 'net' === (string) ( $input['wc_purchase_value_type'] ?? '' ) ? 'net' : 'gross',
			'wc_purchase_advanced_matching'  => empty( $input['wc_purchase_advanced_matching'] ) ? 0 : 1,
			// Dieselbe Zeichen-Whitelist wie beim per-Event google_label in
			// sanitize_event() -- Google-Conversion-Labels sind alphanumerisch
			// plus Unterstrich/Bindestrich.
			'wc_google_conversion_label'     => preg_replace( '/[^A-Za-z0-9_\-]+/', '', (string) ( $input['wc_google_conversion_label'] ?? '' ) ),
			// SureCart -- identisches Sanitizing-Muster wie die wc_*-Zeilen
			// direkt darüber, siehe get()-Doku oben.
			'sc_tracking_enabled'            => empty( $input['sc_tracking_enabled'] ) ? 0 : 1,
			'sc_content_id_type'             => 'sku' === (string) ( $input['sc_content_id_type'] ?? '' ) ? 'sku' : 'id',
			'sc_purchase_value_type'         => 'net' === (string) ( $input['sc_purchase_value_type'] ?? '' ) ? 'net' : 'gross',
			'sc_purchase_advanced_matching'  => empty( $input['sc_purchase_advanced_matching'] ) ? 0 : 1,
			'sc_google_conversion_label'     => preg_replace( '/[^A-Za-z0-9_\-]+/', '', (string) ( $input['sc_google_conversion_label'] ?? '' ) ),
		);
	}

	/**
	 * Konfigurierter WooCommerce content_id-Modus ("id" oder "sku") für
	 * PMS_Pro_Woo_Product_Data::get_product_data(). Fällt bei fehlender SKU
	 * pro Produkt trotzdem auf die ID zurück (siehe dortige Doku).
	 *
	 * @return string 'id' oder 'sku'.
	 */
	public static function wc_content_id_type() {
		$settings = self::get();
		return 'sku' === (string) ( $settings['wc_content_id_type'] ?? 'id' ) ? 'sku' : 'id';
	}

	/**
	 * Konfigurierter Modus für den Purchase-"value" (Tab "E-Commerce",
	 * PMS_Pro_Woo_Purchase): "gross" (Bestellsumme wie tatsächlich bezahlt,
	 * inkl. Steuer) oder "net" (abzüglich der Bestellsteuer).
	 *
	 * @return string 'gross' oder 'net'.
	 */
	public static function wc_purchase_value_type() {
		$settings = self::get();
		return 'net' === (string) ( $settings['wc_purchase_value_type'] ?? 'gross' ) ? 'net' : 'gross';
	}

	/**
	 * Konfigurierter SureCart content_id-Modus -- SureCart-Pendant zu
	 * wc_content_id_type() oben, für
	 * PMS_Pro_SureCart_Product_Data::resolve_content_id().
	 *
	 * @return string 'id' oder 'sku'.
	 */
	public static function sc_content_id_type() {
		$settings = self::get();
		return 'sku' === (string) ( $settings['sc_content_id_type'] ?? 'id' ) ? 'sku' : 'id';
	}

	/**
	 * Konfigurierter Modus für den SureCart-Purchase-"value" -- SureCart-
	 * Pendant zu wc_purchase_value_type() oben.
	 *
	 * @return string 'gross' oder 'net'.
	 */
	public static function sc_purchase_value_type() {
		$settings = self::get();
		return 'net' === (string) ( $settings['sc_purchase_value_type'] ?? 'gross' ) ? 'net' : 'gross';
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
	 * FREE_EVENT_LIMIT Events INSGESAMT – unabhängig davon, über welchen Weg
	 * gespeichert wird (Admin-UI-Handler in class-pms-admin.php ODER
	 * JSON-Import in PMS_Tools::import_from_json()). Diese Methode ist der
	 * einzige Ort, an dem Events tatsächlich persistiert werden, also der
	 * richtige Ort für die Whitelist/den Cap – dieselbe Rolle wie
	 * sanitize_settings() für die Einstellungen.
	 *
	 * Events über dem Limit werden abgeschnitten (erste FREE_EVENT_LIMIT in
	 * Array-Reihenfolge bleiben erhalten) – greift praktisch nur beim Import
	 * einer auf einer Pro-Site exportierten Konfiguration in eine Free-Site,
	 * da die Admin-UI das Anlegen eines weiteren Events bereits vorher sperrt
	 * (siehe PMS_Admin::render_event_form()/handle_save_event()).
	 *
	 * @param array $events Events, keyed by id.
	 * @return void
	 */
	public static function save_events( array $events ) {
		if ( ! self::is_pro() && count( $events ) > self::FREE_EVENT_LIMIT ) {
			$events = array_slice( $events, 0, self::FREE_EVENT_LIMIT, true );
		}

		update_option( self::OPTION_EVENTS, array_values( $events ), false );
	}

	/**
	 * Ist das Free-Limit für die Gesamtzahl an Events erreicht (bereits
	 * FREE_EVENT_LIMIT Events vorhanden, unabhängig vom Aktiv-Status)?
	 *
	 * In der Pro-Version immer false (kein Limit). Anders als bis v0.6.1
	 * gibt es keinen $exclude_id-Parameter mehr: Ein bestehendes Event zu
	 * bearbeiten oder zu (de)aktivieren ändert die Gesamtzahl nicht und ist
	 * deshalb nie durch dieses Limit blockiert – nur das ANLEGEN eines
	 * zusätzlichen Events ist betroffen.
	 *
	 * @return bool
	 */
	public static function free_event_limit_reached() {
		if ( self::is_pro() ) {
			return false;
		}

		return count( self::get_events() ) >= self::FREE_EVENT_LIMIT;
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
