<?php
/**
 * Frontend: Basis-Skripte (Meta, Google Ads, TikTok), URL-Matching,
 * Consent-gesteuerte Initialisierung und Auslösen der CAPI.
 *
 * Consent-Logik:
 * - Liegt eine Marketing-Einwilligung vor (oder ist die Erkennung aus bzw.
 *   kein Banner installiert), werden die Skripte direkt ausgegeben.
 * - Blockiert ein erkanntes Banner noch, werden die Skripte in einen
 *   Bootstrap gekapselt, der auf die Consent-Events der Banner lauscht und
 *   das Tracking ohne Seiten-Reload startet, sobald eingewilligt wurde.
 * - Google Ads mit aktivem Consent Mode v2 lädt immer sofort – genau dafür
 *   existiert der Consent Mode (Defaults stehen auf "denied").
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Frontend {

	/**
	 * Auf dieser Seite gematchte Events, jeweils inkl. generierter event_id.
	 *
	 * @var array[]
	 */
	private static $matched_events = array();

	/**
	 * Läuft Tracking auf diesem Request? Wird in prepare() gesetzt.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Gecachte Einstellungen für diesen Request.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Grund, warum auf diesem Request nicht getrackt wird (für die Debug-Leiste).
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'prepare' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_scripts' ), 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
	}

	/**
	 * Läuft auf diesem Request Tracking?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/**
	 * Auf dieser Seite gematchte Events inkl. Event-IDs.
	 *
	 * @return array[]
	 */
	public static function get_matched_events() {
		return self::$matched_events;
	}

	/**
	 * Grund für übersprungenes Tracking.
	 *
	 * @return string
	 */
	public static function get_skip_reason() {
		return self::$skip_reason;
	}

	/**
	 * Formular-Auto-Grabber im Footer laden (nur wenn aktiv und getrackt wird).
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( ! self::$active || ! class_exists( 'LMPCT_Forms' ) || ! LMPCT_Forms::enabled() ) {
			return;
		}

		// URL-Filter bereits serverseitig auswerten: Passt die Seite nicht,
		// wird das Skript gar nicht erst ausgeliefert (0 Byte statt Leerlauf).
		$path = (string) wp_parse_url( self::current_request_uri(), PHP_URL_PATH );

		if ( ! LMPCT_Forms::url_allowed( $path ) ) {
			return;
		}

		wp_enqueue_script( 'lmpct-frontend', LMPCT_PLUGIN_URL . 'assets/frontend.js', array(), LMPCT_VERSION, true );

		wp_localize_script(
			'lmpct-frontend',
			'lmpctFront',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( LMPCT_Forms::NONCE_ACTION ),
				'formTracking'  => true,
				'eventType'     => LMPCT_Forms::event_type(),
				'urlFilter'     => LMPCT_Settings::form_url_filters(),
				'excludeSystem' => ! empty( self::$settings['form_exclude_system'] ),
			)
		);
	}

	/**
	 * Läuft früh im Frontend-Request: Events matchen, IDs generieren, CAPI feuern.
	 *
	 * @return void
	 */
	public static function prepare() {
		if ( ! self::should_track() ) {
			return;
		}

		self::$settings = LMPCT_Settings::get();
		self::$active   = true;

		if ( LMPCT_Settings::events_enabled() ) {
			self::$matched_events = self::match_events( self::current_request_uri() );
		}

		if ( empty( self::$settings['capi_enabled'] ) || ! self::meta_active() ) {
			return;
		}

		$meta_events = array_values(
			array_filter(
				self::$matched_events,
				static function ( $event ) {
					return ! empty( $event['meta_enabled'] );
				}
			)
		);

		if ( ! empty( $meta_events ) ) {
			// Die Consent-Prüfung (DSGVO) übernimmt LMPCT_CAPI::send_events()
			// unmittelbar vor dem HTTP-Request.
			LMPCT_CAPI::send_events( $meta_events, self::$settings, self::current_source_url() );
		}
	}

	/**
	 * Darf auf diesem Request getrackt werden?
	 *
	 * @return bool
	 */
	public static function should_track() {
		self::$skip_reason = '';

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			self::$skip_reason = 'context';
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			self::$skip_reason = 'context';
			return false;
		}

		if ( is_feed() || is_robots() || is_preview() || is_customize_preview() || is_404() ) {
			self::$skip_reason = 'context';
			return false;
		}

		$settings = LMPCT_Settings::get();

		$any_platform =
			( ! empty( $settings['pixel_enabled'] ) && ! empty( $settings['pixel_id'] ) ) ||
			( ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ) ) ||
			( ! empty( $settings['tiktok_enabled'] ) && ! empty( $settings['tiktok_pixel_id'] ) );

		if ( ! $any_platform ) {
			self::$skip_reason = 'no_platform';
			return false;
		}

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			self::$skip_reason = 'admin_excluded';
			return false;
		}

		/**
		 * Tracking global unterbinden, z. B. durch eine eigene Consent-Logik.
		 *
		 * add_filter( 'lmpct_allow_tracking', fn( $allow ) => my_consent_given() );
		 *
		 * @param bool $allow Ob getrackt werden darf.
		 */
		$allowed = (bool) apply_filters( 'lmpct_allow_tracking', true );

		if ( ! $allowed ) {
			self::$skip_reason = 'filtered';
		}

		return $allowed;
	}

	private static function meta_active() {
		return ! empty( self::$settings['pixel_enabled'] ) && ! empty( self::$settings['pixel_id'] );
	}

	private static function google_active() {
		return ! empty( self::$settings['google_enabled'] ) && ! empty( self::$settings['google_tag_id'] );
	}

	private static function tiktok_active() {
		return ! empty( self::$settings['tiktok_enabled'] ) && ! empty( self::$settings['tiktok_pixel_id'] );
	}

	/**
	 * Aktuelle Request-URI (Pfad + Query), unslashed.
	 *
	 * @return string
	 */
	private static function current_request_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird nur für String-Vergleiche genutzt und vor Ausgabe escaped.
		return (string) $uri;
	}

	/**
	 * Vollständige aktuelle URL für event_source_url.
	 *
	 * @return string
	 */
	private static function current_source_url() {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . self::current_request_uri();

		return esc_url_raw( $url );
	}

	/**
	 * Aktive Events gegen die aktuelle URL matchen.
	 *
	 * @param string $request_uri Request-URI (Pfad + Query).
	 * @return array[] Gematchte Events inkl. 'event_id'.
	 */
	private static function match_events( $request_uri ) {
		$events = LMPCT_Settings::get_events();
		if ( empty( $events ) ) {
			return array();
		}

		$path    = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$path    = self::normalize_path( $path );
		$matched = array();

		foreach ( $events as $event ) {
			if ( empty( $event['active'] ) ) {
				continue;
			}

			if ( 'exact' === $event['match_type'] ) {
				$is_match = ( self::normalize_path( $event['match_value'] ) === $path );
			} else {
				$is_match = ( false !== stripos( $request_uri, $event['match_value'] ) );
			}

			if ( $is_match ) {
				$event['event_id'] = wp_generate_uuid4();
				$matched[]         = $event;
			}
		}

		return $matched;
	}

	/**
	 * Pfad normalisieren: kleingeschrieben, führender und schließender Slash.
	 * Volle URLs werden auf ihren Pfad reduziert.
	 *
	 * @param string $path Pfad oder URL.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$path = trim( (string) $path );

		if ( false !== strpos( $path, '://' ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}

		$path = strtolower( $path );

		if ( '' === $path ) {
			return '/';
		}

		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return trailingslashit( $path );
	}

	/**
	 * Basis-Skripte + Events aller aktiven Plattformen im <head> ausgeben.
	 *
	 * @return void
	 */
	public static function print_scripts() {
		if ( ! self::$active ) {
			return;
		}

		$consent_given = LMPCT_Consent::has_marketing_consent();
		$immediate_js  = ''; // Läuft sofort, geschützt durch den globalen Init-Guard.
		$deferred_js   = ''; // Läuft erst nach Einwilligung, gleicher Guard.
		$gtag_src      = '';

		echo "\n<!-- Lightweight Meta Pixel & CAPI Tracker -->\n";

		// Meta Pixel.
		if ( self::meta_active() ) {
			if ( $consent_given ) {
				$immediate_js .= self::build_meta_js();
			} else {
				$deferred_js .= self::build_meta_js();
			}
		}

		// Google Ads: Mit Consent Mode v2 immer sofort laden (Defaults = denied),
		// ohne Consent Mode wie die anderen Plattformen verzögern.
		if ( self::google_active() ) {
			$tag_id   = preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) self::$settings['google_tag_id'] );
			$gtag_src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $tag_id );

			if ( $consent_given ) {
				$immediate_js .= self::build_google_js();
			} elseif ( ! empty( self::$settings['google_consent_mode'] ) ) {
				// Eigener Guard: Läuft VOR der Marketing-Einwilligung (Consent Mode)
				// und darf die spätere Pixel-Initialisierung nicht blockieren.
				wp_print_inline_script_tag(
					'if(!window.lmpctGtagInit){window.lmpctGtagInit=true;' . "\n" . self::build_google_js() . '}'
				);
				echo '<script async src="' . esc_url( $gtag_src ) . '"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Bewusst direkt im Head, wie von Google vorgesehen.
				$gtag_src = '';
			} else {
				$deferred_js .= self::build_google_js()
					. "var lmpctGs=document.createElement('script');lmpctGs.async=true;lmpctGs.src='" . esc_url( $gtag_src ) . "';document.head.appendChild(lmpctGs);\n";
				$gtag_src = '';
			}
		}

		// TikTok Pixel.
		if ( self::tiktok_active() ) {
			if ( $consent_given ) {
				$immediate_js .= self::build_tiktok_js();
			} else {
				$deferred_js .= self::build_tiktok_js();
			}
		}

		// Sofortige Skripte: EIN gebündelter Block hinter dem globalen Guard,
		// damit Pixel-Init und PageView pro Seitenaufruf maximal einmal laufen
		// (auch wenn wp_head mehrfach rendert oder Banner-Events erneut feuern).
		if ( '' !== $immediate_js ) {
			wp_print_inline_script_tag(
				'window.lmpctInitialized=window.lmpctInitialized||false;'
				. 'if(!window.lmpctInitialized){window.lmpctInitialized=true;' . "\n"
				. $immediate_js
				. '}'
			);

			if ( '' !== $gtag_src ) {
				echo '<script async src="' . esc_url( $gtag_src ) . '"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			}

			if ( self::meta_active() ) {
				$pixel_id     = preg_replace( '/\D+/', '', (string) self::$settings['pixel_id'] );
				$noscript_url = 'https://www.facebook.com/tr?id=' . rawurlencode( $pixel_id ) . '&ev=PageView&noscript=1';
				echo '<noscript><img height="1" width="1" style="display:none" alt="" src="' . esc_url( $noscript_url ) . '" /></noscript>' . "\n";
			}
		}

		// Verzögerte Skripte: warten auf die Einwilligung im Cookie-Banner.
		if ( '' !== $deferred_js ) {
			wp_print_inline_script_tag( self::build_consent_bootstrap( $deferred_js ) );
		}

		echo "<!-- / Lightweight Meta Pixel & CAPI Tracker -->\n";
	}

	/**
	 * Meta Pixel: Loader, PageView und gematchte Events (mit eventID zur
	 * Deduplizierung gegen die CAPI).
	 *
	 * @return string
	 */
	private static function build_meta_js() {
		$pixel_id = preg_replace( '/\D+/', '', (string) self::$settings['pixel_id'] );
		if ( '' === $pixel_id ) {
			return '';
		}

		$js  = "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\n";
		$js .= "fbq('init','" . esc_js( $pixel_id ) . "');\n";
		$js .= "fbq('track','PageView');\n";

		foreach ( self::$matched_events as $event ) {
			if ( empty( $event['meta_enabled'] ) ) {
				continue;
			}
			$method = ( 'CustomEvent' === $event['event_type'] ) ? 'trackCustom' : 'track';
			$name   = LMPCT_Settings::resolved_event_name( $event );
			$js    .= "fbq('" . $method . "','" . esc_js( $name ) . "',{},{eventID:'" . esc_js( $event['event_id'] ) . "'});\n";
		}

		return $js;
	}

	/**
	 * Google Ads (gtag.js) inkl. Consent Mode v2 Defaults und Conversions.
	 * Die Consent-Defaults stehen bewusst VOR gtag('config').
	 *
	 * @return string
	 */
	private static function build_google_js() {
		$tag_id = preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) self::$settings['google_tag_id'] );
		if ( '' === $tag_id ) {
			return '';
		}

		$js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";

		if ( ! empty( self::$settings['google_consent_mode'] ) ) {
			$js .= "gtag('consent','default',{'ad_storage':'denied','ad_user_data':'denied','ad_personalization':'denied','analytics_storage':'denied','wait_for_update':500});\n";
		}

		$js .= "gtag('js',new Date());\n";
		$js .= "gtag('config','" . esc_js( $tag_id ) . "');\n";

		foreach ( self::$matched_events as $event ) {
			if ( empty( $event['google_enabled'] ) || '' === $event['google_label'] ) {
				continue;
			}
			$js .= "gtag('event','conversion',{'send_to':'" . esc_js( $tag_id . '/' . $event['google_label'] ) . "'});\n";
		}

		return $js;
	}

	/**
	 * TikTok Pixel: offizieller Loader, PageView und gematchte Events.
	 *
	 * @return string
	 */
	private static function build_tiktok_js() {
		$pixel_id = preg_replace( '/[^A-Za-z0-9]+/', '', (string) self::$settings['tiktok_pixel_id'] );
		if ( '' === $pixel_id ) {
			return '';
		}

		$js  = '!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};' . "\n";
		$js .= "ttq.load('" . esc_js( $pixel_id ) . "');\n";
		$js .= "ttq.page();\n";

		foreach ( self::$matched_events as $event ) {
			if ( empty( $event['tiktok_enabled'] ) ) {
				continue;
			}
			$js .= "ttq.track('" . esc_js( LMPCT_Settings::resolved_tiktok_event_name( $event ) ) . "');\n";
		}

		$js .= "}(window,document,'ttq');\n";

		return $js;
	}

	/**
	 * Consent-Bootstrap: prüft Client-seitig die Banner-Cookies und lauscht
	 * auf die Consent-Events der Banner, damit das Tracking ohne Seiten-Reload
	 * startet, sobald der Besucher einwilligt.
	 *
	 * @param string $deferred_js Skripte, die erst nach Einwilligung laufen dürfen.
	 * @return string
	 */
	private static function build_consent_bootstrap( $deferred_js ) {
		$events_json = wp_json_encode( array_values( LMPCT_Consent::consent_events() ) );

		// Globaler Init-Guard (window.lmpctInitialized): garantiert, dass Pixel-
		// Init und PageView pro Seitenaufruf maximal EINMAL laufen – auch wenn
		// der Bootstrap doppelt gerendert wird oder Banner-Events (z. B.
		// surecookies_consent_updated) kurz nach dem Laden erneut feuern.
		return '(function(){window.lmpctInitialized=window.lmpctInitialized||false;'
			. LMPCT_Consent::consent_check_js()
			// Für den Formular-Auto-Grabber global verfügbar machen.
			. 'window.lmpctHasConsent=lmpctHasConsent;'
			. 'function lmpctInitTracking(){if(window.lmpctInitialized){return;}window.lmpctInitialized=true;' . "\n" . $deferred_js . '}'
			. 'if(lmpctHasConsent()){lmpctInitTracking();}'
			. 'var lmpctEvts=' . $events_json . ';'
			. 'lmpctEvts.forEach(function(e){var f=function(){setTimeout(function(){if(lmpctHasConsent()){lmpctInitTracking();}},100);};document.addEventListener(e,f);window.addEventListener(e,f);});'
			. '})();';
	}
}
