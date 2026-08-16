<?php
/**
 * Intelligente Cookie-Consent-Erkennung (DSGVO).
 *
 * Erkennt gängige Cookie-Banner-Plugins (DACH-Markt) serverseitig und
 * entscheidet, ob eine Marketing-Einwilligung vorliegt:
 *
 * - Erkennung deaktiviert            -> Consent gilt als erteilt.
 * - Kein bekanntes Banner erkannt    -> Consent gilt als erteilt (Fallback,
 *   damit Websites ohne Banner nicht ungewollt blockiert werden).
 * - Banner erkannt, keine/negative
 *   Marketing-Einwilligung           -> Consent verweigert (Browser-Skripte
 *   werden verzögert, CAPI bricht vor dem HTTP-Request ab).
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Consent {

	/**
	 * Request-Cache für das Auswertungsergebnis.
	 *
	 * @var bool|null
	 */
	private static $cache = null;

	/**
	 * Ist die automatische Cookie-Banner-Erkennung aktiviert?
	 *
	 * @return bool
	 */
	public static function detection_enabled() {
		$settings = LMPCT_Settings::get();
		return ! empty( $settings['consent_detection'] );
	}

	/**
	 * Liegt eine Marketing-Einwilligung vor?
	 *
	 * @return bool
	 */
	public static function has_marketing_consent() {
		if ( ! self::detection_enabled() ) {
			return true;
		}

		if ( null === self::$cache ) {
			self::$cache = self::evaluate();
		}

		/**
		 * Consent-Ergebnis überschreiben, z. B. für nicht unterstützte Banner.
		 *
		 * add_filter( 'lmpct_has_marketing_consent', fn( $consent ) => my_check() );
		 *
		 * @param bool $consent Ob eine Marketing-Einwilligung vorliegt.
		 */
		return (bool) apply_filters( 'lmpct_has_marketing_consent', self::$cache );
	}

	/**
	 * Consent-Status aus WP Consent API bzw. bekannten Banner-Cookies ermitteln.
	 *
	 * @return bool
	 */
	private static function evaluate() {
		// 1) WP Consent API (Standard-Schnittstelle, wird u. a. von Complianz,
		//    Real Cookie Banner und Borlabs bedient).
		if ( function_exists( 'wp_has_consent' ) ) {
			return (bool) wp_has_consent( 'marketing' );
		}

		// 2) Must Have Plugins Cookie Bar / GDPR Cookie Consent (Cookie Law Info).
		if ( isset( $_COOKIE['cookielawinfo-checkbox-advertisement'] ) ) {
			return 'yes' === self::cookie( 'cookielawinfo-checkbox-advertisement' );
		}

		// 3) CookieYes.
		if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
			$value = self::cookie( 'cookieyes-consent' );
			return false !== strpos( $value, 'advertisement:yes' ) || false !== strpos( $value, 'marketing:yes' );
		}

		// 4) Cookie Law Info (ältere Versionen ohne Kategorie-Cookies).
		if ( isset( $_COOKIE['viewed_cookie_policy'] ) ) {
			return 'yes' === self::cookie( 'viewed_cookie_policy' );
		}

		// 5) Borlabs Cookie (JSON: consents -> marketing).
		if ( isset( $_COOKIE['borlabs-cookie'] ) ) {
			$data = json_decode( urldecode( self::cookie( 'borlabs-cookie' ) ), true );
			return is_array( $data ) && ! empty( $data['consents']['marketing'] );
		}

		// 6) Complianz.
		if ( isset( $_COOKIE['cmplz_marketing'] ) ) {
			return 'allow' === self::cookie( 'cmplz_marketing' );
		}
		if ( isset( $_COOKIE['complianz_consent_status'] ) ) {
			return 'allow' === self::cookie( 'complianz_consent_status' );
		}

		// 7) Cookiebot.
		if ( isset( $_COOKIE['CookieConsent'] ) ) {
			return false !== strpos( urldecode( self::cookie( 'CookieConsent' ) ), 'marketing:true' );
		}

		// 8) SureCookies.
		if ( isset( $_COOKIE['surecookies_consent'] ) ) {
			return (bool) preg_match( '/marketing[^,;}]*?(true|yes|1)/i', urldecode( self::cookie( 'surecookies_consent' ) ) );
		}

		// 9) Real Cookie Banner (Cookie-Name trägt eine Blog-ID als Suffix).
		//    Vorhandenes Cookie = Besucher hat eine Entscheidung getroffen;
		//    die Kategorie-Auswertung übernimmt RCB über die WP Consent API (Fall 1).
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'real_cookie_banner' ) ) {
				return true;
			}
		}

		// 10) Kein Consent-Cookie vorhanden: Ist überhaupt ein bekanntes
		//     Banner-Plugin aktiv? Wenn ja, wurde noch nicht eingewilligt.
		if ( self::banner_plugin_active() ) {
			return false;
		}

		// Kein Banner erkannt -> nicht blockieren.
		return true;
	}

	/**
	 * Prüft anhand von Plugin-Signaturen (Konstanten/Klassen), ob ein
	 * bekanntes Cookie-Banner-Plugin aktiv ist.
	 *
	 * @return bool
	 */
	public static function banner_plugin_active() {
		$active =
			defined( 'CLI_VERSION' )                                    // Cookie Law Info / MHP Cookie Bar.
			|| defined( 'CKY_VERSION' ) || defined( 'CKY_APP_URL' )     // CookieYes.
			|| class_exists( '\CookieYes\Lite\CookieYes' )
			|| defined( 'BORLABS_COOKIE_VERSION' )                      // Borlabs Cookie.
			|| class_exists( '\Borlabs\Cookie\Container\ApplicationContainer' )
			|| defined( 'cmplz_version' )                               // Complianz.
			|| function_exists( 'cmplz_has_consent' )
			|| defined( 'RCB_FILE' )                                    // Real Cookie Banner.
			|| class_exists( '\DevOwl\RealCookieBanner\Core' )
			|| class_exists( 'Cookiebot_WP' )                           // Cookiebot.
			|| defined( 'CYBOT_COOKIEBOT_PLUGIN_VERSION' )
			|| defined( 'SURECOOKIES_VER' )                             // SureCookies.
			|| class_exists( '\SureCookies\Loader' )
			|| function_exists( 'wp_has_consent' );                     // WP Consent API.

		/**
		 * Banner-Erkennung überschreiben (z. B. eigenes Banner registrieren).
		 *
		 * @param bool $active Ob ein bekanntes Cookie-Banner aktiv ist.
		 */
		return (bool) apply_filters( 'lmpct_consent_banner_active', $active );
	}

	/**
	 * Client-seitige Consent-Prüfung als JavaScript-Funktionskörper.
	 * Spiegelt die PHP-Cookie-Muster, damit das Tracking nach dem Klick auf
	 * "Akzeptieren" ohne Seiten-Reload starten kann.
	 *
	 * @return string
	 */
	public static function consent_check_js() {
		return 'function lmpctHasConsent(){var c=document.cookie,m;'
			. "if(c.indexOf('cookielawinfo-checkbox-advertisement=yes')>-1)return true;"
			. "if(c.indexOf('cookielawinfo-checkbox-advertisement=no')>-1)return false;"
			. "m=c.match(/cookieyes-consent=([^;]*)/);if(m)return m[1].indexOf('advertisement:yes')>-1||m[1].indexOf('marketing:yes')>-1;"
			. "m=c.match(/borlabs-cookie=([^;]*)/);if(m){try{var b=JSON.parse(decodeURIComponent(m[1]));return !!(b&&b.consents&&b.consents.marketing&&b.consents.marketing.length!==0);}catch(e){return false;}}"
			. "m=c.match(/cmplz_marketing=([^;]*)/);if(m)return m[1]==='allow';"
			. "m=c.match(/complianz_consent_status=([^;]*)/);if(m)return m[1]==='allow';"
			. "m=c.match(/CookieConsent=([^;]*)/);if(m)return decodeURIComponent(m[1]).indexOf('marketing:true')>-1;"
			. "m=c.match(/surecookies_consent=([^;]*)/);if(m)return /marketing[^,;}]*?(true|yes|1)/i.test(decodeURIComponent(m[1]));"
			. "if(c.indexOf('real_cookie_banner')>-1)return true;"
			. "if(c.indexOf('viewed_cookie_policy=yes')>-1)return true;"
			. 'return false;}';
	}

	/**
	 * Banner-Events, auf die das verzögerte Tracking lauscht.
	 *
	 * @return string[]
	 */
	public static function consent_events() {
		$events = array(
			'CLI_Cookie_Accept',            // Must Have Plugins / Cookie Law Info.
			'CLI_Cookie_Accept_All',
			'cookieyes_consent_update',     // CookieYes.
			'borlabs-cookie-consent-saved', // Borlabs Cookie.
			'cmplz_fire_categories',        // Complianz.
			'CookiebotOnConsentReady',      // Cookiebot.
			'surecookies_consent_updated',  // SureCookies.
			'wp_listen_for_consent_change', // WP Consent API.
		);

		/**
		 * Banner-Events erweitern, z. B. für eigene Consent-Lösungen.
		 *
		 * @param string[] $events Event-Namen.
		 */
		return (array) apply_filters( 'lmpct_consent_events', $events );
	}

	/**
	 * Sanitize-sicheres Cookie-Auslesen.
	 *
	 * @param string $name Cookie-Name.
	 * @return string
	 */
	private static function cookie( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return trim( (string) wp_unslash( $_COOKIE[ $name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Werte werden nur verglichen, nie ausgegeben.
	}
}
