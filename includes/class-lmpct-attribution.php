<?php
/**
 * First-Touch- & UTM-Attribution.
 *
 * Speichert Kampagnen-Parameter beim Erstbesuch in einem First-Party-Cookie
 * (30 Tage, SameSite=Lax, für JS lesbar) und stellt sie den serverseitigen
 * CAPI-Events als custom_data bzw. als fbc bereit.
 *
 * First-Touch-Semantik: Vorhandene UTM-Werte werden NICHT überschrieben.
 * Einzige Ausnahme ist die fbclid – sie muss immer zum letzten Anzeigenklick
 * passen, sonst ordnet Meta die Conversion der falschen Kampagne zu.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Attribution {

	const COOKIE_NAME = 'lmpct_attribution';
	const LIFETIME    = 2592000; // 30 Tage.

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
	);

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
		$settings = LMPCT_Settings::get();
		return ! empty( $settings['utm_passthrough'] );
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
			// Die fbclid wird immer auf den aktuellen Klick aktualisiert.
			if ( 'fbclid' === $key || ! isset( $merged[ $key ] ) || '' === $merged[ $key ] ) {
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
			if ( 'fbclid' === $key ) {
				continue;
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

		$raw  = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird direkt darunter validiert.
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
