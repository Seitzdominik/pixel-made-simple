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
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Consent {

	/**
	 * Request-Cache für das Auswertungsergebnis.
	 *
	 * @var bool|null
	 */
	private static $cache = null;

	/**
	 * Obergrenze für Cookie-Werte, die per base64_decode()/json_decode()
	 * geparst werden. Browser begrenzen einzelne Cookies auf ~4 KB; ein
	 * nicht-Browser-Client kann aber beliebig lange Cookie-Header senden.
	 * Alles darüber ist offensichtlich kein echtes Consent-Cookie und wird
	 * ungeparst verworfen (fail-closed, siehe Aufrufer).
	 */
	const MAX_COOKIE_LEN = 8192;

	/**
	 * Ist die automatische Cookie-Banner-Erkennung aktiviert?
	 *
	 * @return bool
	 */
	public static function detection_enabled() {
		$settings = PMS_Settings::get();
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
		 * add_filter( 'pms_has_marketing_consent', fn( $consent ) => my_check() );
		 *
		 * @param bool $consent Ob eine Marketing-Einwilligung vorliegt.
		 */
		return (bool) apply_filters( 'pms_has_marketing_consent', self::$cache );
	}

	/**
	 * Darf ein SERVERSEITIGES Signal (Meta CAPI, TikTok Events API) raus?
	 *
	 * Seit v0.6.10 die Gegenstelle zu has_marketing_consent(), das weiterhin
	 * ausschließlich den Browser-Pixel bewacht. Welcher der beiden Gates
	 * gilt, entscheidet der Consent-Modus (Tab "Allgemein", siehe
	 * PMS_Settings::CONSENT_MODE_*):
	 *
	 * - 'strict' (Default, Verhalten aller Versionen vor v0.6.10): identisch
	 *   zu has_marketing_consent() -- ohne Einwilligung kein Request.
	 * - 'browser_only': serverseitige Signale laufen unabhängig vom
	 *   Banner-Status; nur der Browser-Pixel wartet weiterhin.
	 *
	 * Der Modus wird NUR ausgewertet, wenn die Erkennung überhaupt etwas
	 * blockieren würde -- liegt Einwilligung vor (oder ist die Erkennung aus),
	 * ist das Ergebnis in beiden Modi identisch true.
	 *
	 * WICHTIG: 'browser_only' umgeht bewusst auch den Filter
	 * pms_has_marketing_consent -- der ist die Korrektur für nicht erkannte
	 * BANNER, und im flexiblen Modus hat der Betreiber ausdrücklich erklärt,
	 * dass der Banner-Status für den Server-Pfad nicht maßgeblich ist. Wer
	 * den Server-Pfad trotzdem programmatisch stoppen möchte, filtert
	 * pms_has_server_consent (bzw. weiterhin global pms_allow_tracking).
	 *
	 * @return bool
	 */
	public static function has_server_consent() {
		$strict  = PMS_Settings::CONSENT_MODE_STRICT === PMS_Settings::consent_mode();
		$consent = $strict ? self::has_marketing_consent() : true;

		/**
		 * Serverseitiges Consent-Ergebnis überschreiben.
		 *
		 * @param bool $consent Ob serverseitige Signale gesendet werden dürfen.
		 * @param bool $strict  Ob der strikte Consent-Modus aktiv ist.
		 */
		return (bool) apply_filters( 'pms_has_server_consent', $consent, $strict );
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

		// 2) Must Have Plugins Cookie Bar: Base64-kodiertes JSON-Cookie "mhcookie".
		//    Reales Format (aus Live-Test verifiziert):
		//    {"groups":["all"],"services":{...},"iab_vendors":["all"],...}
		//    "groups" ist ein Array von Kategorie-Slugs, KEIN Key-Value-Objekt.
		//    "Alle akzeptieren" setzt groups:["all"]. Zustimmung liegt vor, wenn
		//    "all", "marketing" oder "advertisement" im groups-Array steht.
		if ( ! empty( $_COOKIE['mhcookie'] ) ) {
			$raw     = sanitize_text_field( wp_unslash( $_COOKIE['mhcookie'] ) );
			$decoded = strlen( $raw ) <= self::MAX_COOKIE_LEN ? base64_decode( $raw, true ) : false;
			if ( false !== $decoded && '' !== $decoded ) {
				$data = json_decode( $decoded, true );
				if ( is_array( $data ) && ! empty( $data['groups'] ) && is_array( $data['groups'] ) ) {
					return in_array( 'all', $data['groups'], true )
						|| in_array( 'marketing', $data['groups'], true )
						|| in_array( 'advertisement', $data['groups'], true );
				}
			}
			// mhcookie existiert, aber ohne zustimmende Gruppe (z. B. nur
			// "essential" oder leeres Array bei "Nur erforderliche") -> blockieren.
			return false;
		}

		// 3) GDPR Cookie Consent (Cookie Law Info).
		//    STRIKT: Nur die Advertisement-/Marketing-Kategorie zählt als
		//    Zustimmung. viewed_cookie_policy wird bei JEDER Interaktion gesetzt
		//    (auch bei "Nur erforderliche akzeptieren") und ist daher KEINE
		//    Marketing-Einwilligung.
		if ( isset( $_COOKIE['cookielawinfo-checkbox-advertisement'] ) ) {
			return 'yes' === self::cookie( 'cookielawinfo-checkbox-advertisement' );
		}
		if ( isset( $_COOKIE['cookielawinfo-checkbox-marketing'] ) ) {
			return 'yes' === self::cookie( 'cookielawinfo-checkbox-marketing' );
		}

		// 4) CookieYes.
		if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
			$value = self::cookie( 'cookieyes-consent' );
			return false !== strpos( $value, 'advertisement:yes' ) || false !== strpos( $value, 'marketing:yes' );
		}

		// 5) Cookie Law Info: Banner wurde bedient, aber es existiert keine
		//    erteilte Marketing-Kategorie (siehe 3) -> blockieren.
		if ( isset( $_COOKIE['viewed_cookie_policy'] ) ) {
			return false;
		}

		// 6) Borlabs Cookie (JSON: consents -> marketing).
		if ( isset( $_COOKIE['borlabs-cookie'] ) ) {
			$raw = self::cookie( 'borlabs-cookie' );
			if ( strlen( $raw ) > self::MAX_COOKIE_LEN ) {
				return false;
			}
			$data = json_decode( urldecode( $raw ), true );
			return is_array( $data ) && ! empty( $data['consents']['marketing'] );
		}

		// 7) Complianz.
		if ( isset( $_COOKIE['cmplz_marketing'] ) ) {
			return 'allow' === self::cookie( 'cmplz_marketing' );
		}
		if ( isset( $_COOKIE['complianz_consent_status'] ) ) {
			return 'allow' === self::cookie( 'complianz_consent_status' );
		}

		// 8) Cookiebot.
		if ( isset( $_COOKIE['CookieConsent'] ) ) {
			return false !== strpos( urldecode( self::cookie( 'CookieConsent' ) ), 'marketing:true' );
		}

		// 9) SureCookies.
		if ( isset( $_COOKIE['surecookies_consent'] ) ) {
			return (bool) preg_match( '/marketing[^,;}]*?(true|yes|1)/i', urldecode( self::cookie( 'surecookies_consent' ) ) );
		}

		// 10) Real Cookie Banner (Cookie-Name trägt eine Blog-ID als Suffix).
		//    Vorhandenes Cookie = Besucher hat eine Entscheidung getroffen;
		//    die Kategorie-Auswertung übernimmt RCB über die WP Consent API (Fall 1).
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'real_cookie_banner' ) ) {
				return true;
			}
		}

		// 11) Kein Consent-Cookie vorhanden: Ist überhaupt ein bekanntes
		//     Banner-Plugin aktiv? Wenn ja, wurde noch nicht eingewilligt.
		if ( self::banner_plugin_active() ) {
			return false;
		}

		// Kein Banner erkannt -> nicht blockieren.
		return true;
	}

	/**
	 * Name des erkannten Cookie-Banners (für die Live-Debug-Leiste).
	 *
	 * @return string Leerer String, wenn keines erkannt wurde.
	 */
	public static function detected_banner() {
		if ( isset( $_COOKIE['mhcookie'] ) || defined( 'CLI_VERSION' ) ) {
			return 'Must Have Plugins / Cookie Law Info';
		}
		if ( isset( $_COOKIE['borlabs-cookie'] ) || defined( 'BORLABS_COOKIE_VERSION' ) ) {
			return 'Borlabs Cookie';
		}
		if ( isset( $_COOKIE['cmplz_marketing'] ) || isset( $_COOKIE['complianz_consent_status'] ) || defined( 'cmplz_version' ) ) {
			return 'Complianz';
		}
		if ( isset( $_COOKIE['cookieyes-consent'] ) || defined( 'CKY_VERSION' ) ) {
			return 'CookieYes';
		}
		if ( isset( $_COOKIE['CookieConsent'] ) || class_exists( 'Cookiebot_WP' ) ) {
			return 'Cookiebot';
		}
		if ( isset( $_COOKIE['surecookies_consent'] ) || defined( 'SURECOOKIES_VER' ) ) {
			return 'SureCookies';
		}
		if ( defined( 'RCB_FILE' ) || class_exists( '\DevOwl\RealCookieBanner\Core' ) ) {
			return 'Real Cookie Banner';
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'real_cookie_banner' ) ) {
				return 'Real Cookie Banner';
			}
		}
		if ( function_exists( 'wp_has_consent' ) ) {
			return 'WP Consent API';
		}

		return '';
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
		return (bool) apply_filters( 'pms_consent_banner_active', $active );
	}

	/**
	 * Client-seitige Consent-Prüfung als JavaScript-Funktionskörper.
	 * Spiegelt die PHP-Cookie-Muster, damit das Tracking nach dem Klick auf
	 * "Akzeptieren" ohne Seiten-Reload starten kann.
	 *
	 * @return string
	 */
	public static function consent_check_js() {
		// STRIKT für CLI/Must Have Plugins: Nur advertisement/marketing=yes
		// zählt; ein vorhandenes Kategorie-Cookie mit anderem Wert oder ein
		// bloßes viewed_cookie_policy (= Banner bedient) blockiert.
		// 8192 = derselbe Grenzwert wie serverseitig (self::MAX_COOKIE_LEN);
		// verhindert unnötiges atob()/JSON.parse() auf überlangen Werten.
		return 'function pmsHasConsent(){var c=document.cookie,m,L=8192;'
			. 'm=c.match(/(?:^|;\\s*)mhcookie=([^;]*)/);'
			. 'if(m){if(m[1].length>L)return false;try{var mh=JSON.parse(atob(decodeURIComponent(m[1])));'
			. 'if(mh&&Array.isArray(mh.groups)){return mh.groups.indexOf("all")>-1||mh.groups.indexOf("marketing")>-1||mh.groups.indexOf("advertisement")>-1;}'
			. 'return false;}catch(e){return false;}}'
			. "if(c.indexOf('cookielawinfo-checkbox-advertisement=yes')>-1||c.indexOf('cookielawinfo-checkbox-marketing=yes')>-1)return true;"
			. "if(c.indexOf('cookielawinfo-checkbox-advertisement=')>-1||c.indexOf('cookielawinfo-checkbox-marketing=')>-1)return false;"
			. "m=c.match(/cookieyes-consent=([^;]*)/);if(m)return m[1].indexOf('advertisement:yes')>-1||m[1].indexOf('marketing:yes')>-1;"
			. "m=c.match(/borlabs-cookie=([^;]*)/);if(m){if(m[1].length>L)return false;try{var b=JSON.parse(decodeURIComponent(m[1]));return !!(b&&b.consents&&b.consents.marketing&&b.consents.marketing.length!==0);}catch(e){return false;}}"
			. "m=c.match(/cmplz_marketing=([^;]*)/);if(m)return m[1]==='allow';"
			. "m=c.match(/complianz_consent_status=([^;]*)/);if(m)return m[1]==='allow';"
			. "m=c.match(/CookieConsent=([^;]*)/);if(m)return decodeURIComponent(m[1]).indexOf('marketing:true')>-1;"
			. "m=c.match(/surecookies_consent=([^;]*)/);if(m)return /marketing[^,;}]*?(true|yes|1)/i.test(decodeURIComponent(m[1]));"
			. "if(c.indexOf('real_cookie_banner')>-1)return true;"
			. "if(c.indexOf('viewed_cookie_policy=')>-1)return false;"
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
		return (array) apply_filters( 'pms_consent_events', $events );
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
