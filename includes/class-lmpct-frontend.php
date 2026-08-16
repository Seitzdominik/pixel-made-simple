<?php
/**
 * Frontend: Basis-Skripte (Meta, Google Ads, TikTok), URL-Matching,
 * Consent-Script-Blocking und Auslösen der CAPI.
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

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'prepare' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_scripts' ), 4 );
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

		if ( empty( $meta_events ) ) {
			return;
		}

		// Im Script-Blocking-Modus gilt: Ohne _fbp-Cookie lief das Browser-Pixel
		// noch nie (kein Consent) – dann wird auch serverseitig nichts gesendet.
		$consent_ok = empty( self::$settings['consent_blocking'] ) || ! empty( $_COOKIE['_fbp'] );

		/**
		 * CAPI-Consent überschreiben, z. B. um das Consent-Cookie des eigenen
		 * Banners direkt auszuwerten.
		 *
		 * @param bool $consent_ok Ob die CAPI senden darf.
		 */
		if ( apply_filters( 'lmpct_capi_consent', $consent_ok ) ) {
			LMPCT_CAPI::send_events( $meta_events, self::$settings, self::current_source_url() );
		}
	}

	/**
	 * Darf auf diesem Request getrackt werden?
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( is_feed() || is_robots() || is_preview() || is_customize_preview() || is_404() ) {
			return false;
		}

		$settings = LMPCT_Settings::get();

		$any_platform =
			( ! empty( $settings['pixel_enabled'] ) && ! empty( $settings['pixel_id'] ) ) ||
			( ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ) ) ||
			( ! empty( $settings['tiktok_enabled'] ) && ! empty( $settings['tiktok_pixel_id'] ) );

		if ( ! $any_platform ) {
			return false;
		}

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			return false;
		}

		/**
		 * Tracking global unterbinden, z. B. durch ein Consent-Plugin.
		 *
		 * add_filter( 'lmpct_allow_tracking', fn( $allow ) => my_consent_given() );
		 *
		 * @param bool $allow Ob getrackt werden darf.
		 */
		return (bool) apply_filters( 'lmpct_allow_tracking', true );
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
	 * Attribute für Inline-Skripte: im Script-Blocking-Modus werden alle
	 * Tracking-Skripte als text/plain ausgegeben, bis das Consent-Banner
	 * sie freischaltet (Kategorie "marketing").
	 *
	 * @return array
	 */
	private static function script_attributes() {
		if ( empty( self::$settings['consent_blocking'] ) ) {
			return array();
		}

		return array(
			'type'                => 'text/plain',
			'data-cookiecategory' => 'marketing',
		);
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

		echo "\n<!-- Lightweight Meta Pixel & CAPI Tracker -->\n";

		if ( self::meta_active() ) {
			self::print_meta();
		}
		if ( self::google_active() ) {
			self::print_google();
		}
		if ( self::tiktok_active() ) {
			self::print_tiktok();
		}

		echo "<!-- / Lightweight Meta Pixel & CAPI Tracker -->\n";
	}

	/**
	 * Meta Pixel: Loader, PageView und gematchte Events (mit eventID zur
	 * Deduplizierung gegen die CAPI).
	 *
	 * @return void
	 */
	private static function print_meta() {
		$pixel_id = preg_replace( '/\D+/', '', (string) self::$settings['pixel_id'] );
		if ( '' === $pixel_id ) {
			return;
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

		wp_print_inline_script_tag( $js, self::script_attributes() );

		// Der noscript-Fallback feuert bedingungslos und entfällt daher im
		// Script-Blocking-Modus (kein Tracking ohne Consent).
		if ( empty( self::$settings['consent_blocking'] ) ) {
			$noscript_url = 'https://www.facebook.com/tr?id=' . rawurlencode( $pixel_id ) . '&ev=PageView&noscript=1';
			echo '<noscript><img height="1" width="1" style="display:none" alt="" src="' . esc_url( $noscript_url ) . '" /></noscript>' . "\n";
		}
	}

	/**
	 * Google Ads (gtag.js) inkl. Consent Mode v2 Defaults und Conversions.
	 *
	 * Das Inline-Skript steht bewusst VOR dem externen gtag.js, damit die
	 * Consent-Defaults sicher gesetzt sind, bevor der Tag verarbeitet wird.
	 *
	 * @return void
	 */
	private static function print_google() {
		$tag_id = preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) self::$settings['google_tag_id'] );
		if ( '' === $tag_id ) {
			return;
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

		wp_print_inline_script_tag( $js, self::script_attributes() );

		$src     = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $tag_id );
		$blocked = ! empty( self::$settings['consent_blocking'] )
			? ' type="text/plain" data-cookiecategory="marketing"'
			: '';
		echo '<script' . $blocked . ' async src="' . esc_url( $src ) . '"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Bewusst direkt im Head, wie von Google vorgesehen.
	}

	/**
	 * TikTok Pixel: offizieller Loader, PageView und gematchte Events.
	 *
	 * @return void
	 */
	private static function print_tiktok() {
		$pixel_id = preg_replace( '/[^A-Za-z0-9]+/', '', (string) self::$settings['tiktok_pixel_id'] );
		if ( '' === $pixel_id ) {
			return;
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

		wp_print_inline_script_tag( $js, self::script_attributes() );
	}
}
