<?php
/**
 * Pro-Feature: First-Touch- & UTM-Attribution + automatischer Formular-Fill.
 *
 * Speichert Kampagnen-Parameter beim Erstbesuch in einem First-Party-Cookie
 * (30 Tage, SameSite=Lax, für JS lesbar), stellt sie den serverseitigen
 * CAPI-Events als custom_data bzw. als fbc bereit, und liefert die
 * Einstellungen, die assets/frontend.js für den (in beiden Versionen
 * enthaltenen) UTM-Form-Fill-Code benötigt.
 *
 * Wird ausschließlich von pixel-made-simple-pro.php geladen – die Free-
 * Version bindet diese Datei nie ein. Andere geteilte Klassen (PMS_CAPI,
 * PMS_Debug, PMS_Frontend) prüfen deshalb konsequent
 * `class_exists( 'PMS_Pro_UTM' )`, bevor sie hierher aufrufen, und
 * degradieren in der Free-Version einfach auf "keine Attribution-Daten"
 * statt zu fataln.
 *
 * First-Touch-Semantik: Vorhandene UTM-Werte werden NICHT überschrieben.
 * Einzige Ausnahme sind fbclid und gclid – Klick-IDs müssen immer zum letzten
 * Anzeigenklick passen, sonst ordnen Meta/Google die Conversion der falschen
 * Kampagne zu.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_UTM {

	const COOKIE_NAME = 'pms_attribution';
	const LIFETIME    = 2592000; // 30 Tage.

	/**
	 * Obergrenze für das Attribution-Cookie vor dem json_decode(). Das eigene
	 * Cookie ist normalerweise <200 Bytes; alles jenseits von 8 KB kann kein
	 * gültiger Inhalt sein (und stammt z. B. von einem manipulierten Client).
	 */
	const MAX_COOKIE_LEN = 8192;

	/**
	 * Unterstützte Parameter.
	 *
	 * @var string[]
	 */
	private static $params = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_content',
		'utm_term',
		'fbclid',
		'gclid',
		'ttclid',
	);

	/**
	 * Klick-IDs: werden – anders als die UTM-Parameter – nicht First-Touch
	 * behandelt (siehe capture()) und nicht als custom_data an die Meta CAPI
	 * gesendet (siehe custom_data()), da sie plattformspezifische Identifier
	 * ohne Bedeutung für die jeweils andere Plattform sind.
	 *
	 * ttclid (seit v0.6.6) wird über ttclid() unten von PMS_Pro_Woo_Purchase
	 * für die TikTok Events API gelesen -- derselbe Fallback-Gedanke wie bei
	 * fbc() für Meta: WooCommerce-Server-Events (Purchase) haben keinen
	 * eigenen Zugriff auf den ursprünglichen Klick, nur auf dieses Cookie.
	 *
	 * @var string[]
	 */
	private static $click_ids = array( 'fbclid', 'gclid', 'ttclid' );

	/**
	 * Request-Cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture' ), 1 );
	}

	/**
	 * Ist die Attribution-Weitergabe aktiviert?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = PMS_Settings::get();
		return ! empty( $settings['utm_passthrough'] );
	}

	/**
	 * Ist der granulare UTM-/Attribution-Passthrough in Formularfelder aktiviert?
	 *
	 * Unabhängig von enabled() (utm_passthrough): Diese Funktion füllt lediglich
	 * Formularfelder im Browser, sendet aber selbst keine Daten an eine Plattform.
	 * Sie liest zusätzlich das pms_attribution-Cookie als Fallback, sofern
	 * enabled() es überhaupt anlegt – ist utm_passthrough aus, greift nur die
	 * URL-Parameter-Quelle.
	 *
	 * Die URL-Auswertung (all/include/exclude, Wildcards) passiert seit v0.5.7
	 * ausschließlich in assets/frontend.js (utmFormFillAllowed()) anhand der
	 * vom Browser aufgelösten URL -- PHP liefert nur noch Modus und Muster
	 * (PMS_Settings::utm_form_fill_url_patterns()) per wp_localize_script().
	 *
	 * @return bool
	 */
	public static function form_fill_enabled() {
		$settings = PMS_Settings::get();
		return ! empty( $settings['enable_utm_form_fill'] );
	}

	/**
	 * Kampagnen-Parameter der aktuellen URL erfassen und im Cookie ablegen.
	 *
	 * @return void
	 */
	public static function capture() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! self::enabled() ) {
			return;
		}

		$incoming = self::from_query();
		if ( empty( $incoming ) ) {
			return;
		}

		$stored = self::from_cookie();
		$merged = $stored;

		foreach ( $incoming as $key => $value ) {
			// First-Touch: bestehende UTM-Werte bleiben erhalten.
			// Klick-IDs (fbclid/gclid) werden immer auf den aktuellen Klick aktualisiert.
			if ( in_array( $key, self::$click_ids, true ) || ! isset( $merged[ $key ] ) || '' === $merged[ $key ] ) {
				$merged[ $key ] = $value;
			}
		}

		if ( empty( $merged['ts'] ) ) {
			$merged['ts'] = time();
		}

		if ( $merged === $stored ) {
			return;
		}

		self::$cache = $merged;

		if ( headers_sent() ) {
			return; // Cookie kann nicht mehr gesetzt werden; Werte gelten für diesen Request.
		}

		$value = wp_json_encode( $merged );
		if ( ! is_string( $value ) ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => time() + self::LIFETIME,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false, // Bewusst für JS lesbar.
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	/**
	 * Gespeicherte Attribution-Daten (Cookie + aktuelle URL-Parameter).
	 *
	 * @return array
	 */
	public static function get() {
		if ( ! self::enabled() ) {
			return array();
		}

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		// Aktuelle URL-Parameter haben Vorrang, damit der allererste Aufruf
		// (Cookie ist noch nicht zurückgesendet) bereits vollständig trackt.
		self::$cache = array_merge( self::from_cookie(), self::from_query() );

		return self::$cache;
	}

	/**
	 * custom_data-Anteil (UTM-Parameter) für CAPI-Events.
	 *
	 * @return array
	 */
	public static function custom_data() {
		$data   = self::get();
		$custom = array();

		foreach ( self::$params as $key ) {
			if ( in_array( $key, self::$click_ids, true ) ) {
				continue; // fbclid -> fbc() weiter unten; gclid ist Google-spezifisch und für die Meta CAPI irrelevant.
			}
			if ( ! empty( $data[ $key ] ) ) {
				$custom[ $key ] = $data[ $key ];
			}
		}

		return $custom;
	}

	/**
	 * fbc-Wert aus der gespeicherten fbclid im Meta-Format.
	 *
	 * @return string Leerer String, wenn keine fbclid vorliegt.
	 */
	public static function fbc() {
		$data = self::get();

		if ( empty( $data['fbclid'] ) ) {
			return '';
		}

		$timestamp = ! empty( $data['ts'] ) ? (int) $data['ts'] * 1000 : (int) round( microtime( true ) * 1000 );

		return 'fb.1.' . $timestamp . '.' . $data['fbclid'];
	}

	/**
	 * Gespeicherte ttclid für die TikTok Events API (PMS_Pro_Woo_Purchase).
	 * Anders als fbc() keine Formattransformation -- TikTok erwartet den
	 * Klick-Parameter unverändert.
	 *
	 * @return string Leerer String, wenn keine ttclid vorliegt.
	 */
	public static function ttclid() {
		$data = self::get();

		return ! empty( $data['ttclid'] ) ? (string) $data['ttclid'] : '';
	}

	/**
	 * Parameter aus der aktuellen URL.
	 *
	 * @return array
	 */
	private static function from_query() {
		$found = array();

		foreach ( self::$params as $key ) {
			if ( empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lesender Zugriff auf Kampagnen-Parameter.
				continue;
			}
			$value = self::clean( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $value ) {
				$found[ $key ] = $value;
			}
		}

		return $found;
	}

	/**
	 * Parameter aus dem gespeicherten Cookie.
	 *
	 * @return array
	 */
	private static function from_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird direkt darunter validiert.

		if ( strlen( $raw ) > self::MAX_COOKIE_LEN ) {
			return array();
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::$params as $key ) {
			if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$value = self::clean( (string) $data[ $key ] );
				if ( '' !== $value ) {
					$clean[ $key ] = $value;
				}
			}
		}

		if ( ! empty( $data['ts'] ) ) {
			$clean['ts'] = absint( $data['ts'] );
		}

		return $clean;
	}

	/**
	 * Parameterwert säubern und begrenzen.
	 *
	 * @param mixed $value Rohwert.
	 * @return string
	 */
	private static function clean( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( (string) $value );
		$value = preg_replace( '/[^\p{L}\p{N}_\-.:\/ +%|]/u', '', $value );

		return substr( trim( (string) $value ), 0, 200 );
	}
}
