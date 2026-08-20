<?php
/**
 * Pro-Feature: WooCommerce-Tracking (ViewContent, AddToCart, InitiateCheckout).
 *
 * Lade-Guard: init() wird nur von pixel-made-simple-pro.php aufgerufen (Free
 * kennt diese Datei nicht, siehe class-pro-utm.php-Doku für dasselbe Muster)
 * UND bricht zusätzlich selbst ab, wenn WooCommerce nicht aktiv ist -- eine
 * Pro-Lizenz ohne WooCommerce soll keine toten Hooks/Assets registrieren.
 *
 * Cache-Sicherheit (siehe assets/pms-woocommerce.js): ViewContent und
 * AddToCart bekommen NIE eine serverseitig generierte event_id -- auf einer
 * vollständig gecachten Produktseite läuft für einen Cache-HIT überhaupt kein
 * PHP mehr, ein dort eingebackenes UUID würde also für JEDEN Besucher
 * identisch sein und die Meta-Deduplizierung zerstören. Die event_id entsteht
 * deshalb ausschließlich im Browser (crypto.randomUUID()) und wird für Pixel
 * UND den asynchronen CAPI-Request (siehe handle_track()) gemeinsam genutzt.
 * InitiateCheckout ist davon ausgenommen: WooCommerce schließt Checkout-Seiten
 * bereits selbst von Full-Page-Caching aus (DONOTCACHEPAGE), daher darf die
 * klassische Checkout-Variante genau wie die bestehenden URL-Events (siehe
 * class-pms-frontend.php::match_events()) eine serverseitige UUID einbacken.
 *
 * Preise werden NIE aus dem Client übernommen (siehe handle_track()): Für
 * ViewContent/AddToCart löst der Server product_id/variation_id IMMER frisch
 * über wc_get_product() auf, für InitiateCheckout wird ausschließlich der
 * serverseitige WC()->cart-Sitzungsinhalt gelesen. Der Client teilt dem
 * Server nur mit, DASS/WELCHES Produkt hinzugefügt wurde, nie zu welchem Preis.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_WooCommerce {

	const AJAX_ACTION      = 'pms_woo_track';
	const NONCE_ACTION     = 'pms_woo_track';
	const MAX_LEN_EVENT_ID = 64;
	const MAX_LEN_URL      = 2000;
	const MAX_QTY          = 999;

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'woocommerce_after_single_product', array( __CLASS__, 'render_view_content_payload' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_initiate_checkout_payload' ) );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_track' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_track' ) );

		// Injiziert das WooCommerce-custom_data (content_ids/value/contents/…)
		// in den generischen CAPI-Payload, ohne class-pms-capi.php (geteilte
		// Free/Pro-Datei) anfassen zu müssen -- der Filter existiert dort
		// bereits genau für diesen Zweck.
		add_filter( 'pms_capi_event_data', array( __CLASS__, 'filter_capi_event_data' ), 10, 2 );
	}

	/**
	 * Ist WooCommerce-Tracking konfiguriert und einsatzbereit?
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! PMS_Settings::is_pro() || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		$settings = PMS_Settings::get();

		return ! empty( $settings['wc_tracking_enabled'] );
	}

	/**
	 * Soll auf diesem Request überhaupt etwas WooCommerce-Bezogenes laufen?
	 * Respektiert zusätzlich das globale Tracking-Gate (Admin-Ausschluss,
	 * pms_allow_tracking-Filter, Consent-Erkennung deaktiviert) über
	 * PMS_Frontend::is_active() -- dieselbe Bedingung, unter der auch der
	 * Formular-Grabber und der UTM-Form-Fill ihr Skript laden.
	 *
	 * @return bool
	 */
	private static function should_load() {
		if ( ! self::enabled() ) {
			return false;
		}

		if ( ! class_exists( 'PMS_Frontend' ) || ! PMS_Frontend::is_active() ) {
			return false;
		}

		return is_woocommerce() || is_cart() || is_checkout() || is_product();
	}

	/**
	 * pms-woocommerce.js nur auf WooCommerce-Seiten laden.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! self::should_load() ) {
			return;
		}

		wp_enqueue_script( 'pms-woocommerce', PMS_PLUGIN_URL . 'assets/pms-woocommerce.js', array(), PMS_VERSION, true );

		// Ab WP 6.3 per Loading-Strategy-API tatsächlich "defer" (WP core ignoriert
		// unbekannte Strategy-Metadaten auf älteren Cores folgenlos -- sicher trotz
		// "Requires at least: 6.0" im Plugin-Header).
		wp_script_add_data( 'pms-woocommerce', 'strategy', 'defer' );

		$settings = PMS_Settings::get();

		wp_localize_script(
			'pms-woocommerce',
			'pms_woo_settings',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				// Dieselben Banner-Events wie der Consent-Bootstrap in
				// class-pms-frontend.php -- damit die Consent-Queue in
				// pms-woocommerce.js weiß, wann sie es erneut versuchen soll.
				'consentEvents' => class_exists( 'PMS_Consent' ) ? array_values( PMS_Consent::consent_events() ) : array(),
				// Für den Block-Checkout (kein PHP-Hook verfügbar, siehe
				// render_initiate_checkout_payload()) liest das Skript den
				// aktuellen Warenkorb selbst über die WooCommerce Store API.
				'storeCartUrl'  => rest_url( 'wc/store/v1/cart' ),
				// Google Ads (gtag) und TikTok Pixel (ttq) sind seit v0.6.6
				// zusätzliche Ziele für ViewContent/AddToCart/InitiateCheckout.
				// pms-woocommerce.js prüft NICHT nur typeof window.gtag/ttq
				// (könnte durch ein fremdes GTM/Tool zufällig existieren),
				// sondern zusätzlich dieses Flag -- das eigene google_enabled/
				// tiktok_enabled-Toggle muss respektiert werden, unabhängig
				// davon, ob gtag/ttq aus anderen Gründen im Fenster existieren.
				// gtag/ttq selbst werden bereits von class-pms-frontend.php
				// geladen (dieselbe Bedingung), hier wird nichts neu enqueued.
				// Kein zusätzlicher is_pro()-Check nötig: should_load() oben
				// garantiert das bereits (Google/TikTok sind ohnehin Pro-only).
				'googleEnabled' => ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ),
				'tiktokEnabled' => ! empty( $settings['tiktok_enabled'] ) && ! empty( $settings['tiktok_pixel_id'] ),
			)
		);
	}

	/**
	 * ViewContent-Nutzlast auf der Produktseite ausgeben (Produktdaten dürfen
	 * gecacht werden, NUR die event_id nicht -- siehe Klassen-Doku oben).
	 *
	 * @return void
	 */
	public static function render_view_content_payload() {
		if ( ! self::should_load() || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$data = PMS_Pro_Woo_Product_Data::get_product_data( $product, 1 );

		if ( empty( $data ) ) {
			return;
		}

		// content_id kann je nach wc_content_id_type eine SKU (String) sein --
		// für den späteren AJAX-Roundtrip (handle_track()) wird zusätzlich
		// immer die echte numerische Produkt-ID mitgegeben, damit der Server
		// das Produkt zuverlässig per wc_get_product() erneut auflösen kann.
		$data['product_id'] = $product->get_id();

		self::print_json( 'pms-woo-view-content-data', $data );
	}

	/**
	 * InitiateCheckout-Nutzlast auf dem klassischen Checkout ausgeben. Läuft
	 * NICHT für den Block-Checkout (der rendert nicht über dieses Template-
	 * Hook-System) -- dafür liest pms-woocommerce.js die Store API direkt.
	 *
	 * @return void
	 */
	public static function render_initiate_checkout_payload() {
		if ( ! self::should_load() ) {
			return;
		}

		$custom = self::build_cart_custom_data();

		if ( null === $custom ) {
			return;
		}

		self::print_json( 'pms-woo-checkout-data', $custom );
	}

	/**
	 * JSON-Nutzlast als vom Frontend-Skript lesbares <script type="application/json">
	 * ausgeben. Dieselbe JSON_HEX_*-Absicherung wie die Live-Debug-Leiste
	 * (class-pms-debug.php): Produktname/-kategorie stammen aus Shop-Daten,
	 * die ein Store-Betreiber oder ein Import frei befüllen kann.
	 *
	 * @param string $id   Element-ID.
	 * @param array  $data Nutzlast.
	 * @return void
	 */
	private static function print_json( $id, array $data ) {
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( ! is_string( $json ) ) {
			return;
		}

		printf( '<script type="application/json" id="%s">%s</script>' . "\n", esc_attr( $id ), $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json ist JSON_HEX_*-kodiert, kein HTML-Kontext.
	}

	/**
	 * AJAX: ViewContent/AddToCart/InitiateCheckout serverseitig an die CAPI
	 * senden. Erreichbar auch ohne Login (nopriv), daher harte
	 * Längenbegrenzung/Whitelisting VOR jeder weiteren Verarbeitung, analog
	 * zu PMS_Forms::handle_lead().
	 *
	 * @return void
	 */
	public static function handle_track() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'reason' => 'disabled' ), 200 );
		}

		$settings = PMS_Settings::get();

		$event_id = substr( (string) wp_unslash( $_POST['event_id'] ?? '' ), 0, self::MAX_LEN_EVENT_ID );
		$event_id = sanitize_text_field( $event_id );
		$event_id = preg_replace( '/[^A-Za-z0-9\-]/', '', $event_id );

		if ( '' === $event_id ) {
			wp_send_json_error( array( 'reason' => 'missing_event_id' ), 400 );
		}

		$event_name = self::resolve_event_name( sanitize_key( wp_unslash( $_POST['event_name'] ?? '' ) ) );

		if ( '' === $event_name ) {
			wp_send_json_error( array( 'reason' => 'invalid_event' ), 400 );
		}

		// Einziges Signal, ob der Browser-Pixel tatsächlich gefeuert hat
		// (window.fbq kann fehlen, z. B. wenn Meta selbst deaktiviert ist).
		$browser_fired = ! empty( $_POST['browser_fired'] );

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'reason' => 'admin_excluded' ), 200 );
		}

		/** Dokumentiert in class-pms-frontend.php */
		if ( ! apply_filters( 'pms_allow_tracking', true ) ) {
			wp_send_json_error( array( 'reason' => 'tracking_disabled' ), 200 );
		}

		if ( ! PMS_Consent::has_marketing_consent() ) {
			wp_send_json_error( array( 'reason' => 'no_consent' ), 200 );
		}

		if ( empty( $settings['capi_enabled'] ) || empty( $settings['pixel_id'] ) || empty( $settings['capi_token'] ) ) {
			// CAPI ist aus/nicht konfiguriert, der Browser-Pixel lief aber ganz
			// normal weiter -- dafür trotzdem einen Event-Log-Eintrag schreiben
			// (siehe PMS_Forms::handle_lead() für dasselbe Muster).
			if ( $browser_fired && class_exists( 'PMS_Logger' ) ) {
				PMS_Logger::record( $event_name, $event_id, 'browser', 0, array() );
			}
			wp_send_json_error( array( 'reason' => 'capi_inactive' ), 200 );
		}

		$custom_data = self::resolve_custom_data( $event_name );

		if ( null === $custom_data ) {
			wp_send_json_error( array( 'reason' => 'no_product_data' ), 200 );
		}

		$event = array(
			'id'                  => 'woo-' . strtolower( $event_name ),
			'name'                => $event_name,
			'event_type'          => $event_name,
			'event_id'            => $event_id,
			'meta_enabled'        => 1,
			'pms_woo_custom_data' => $custom_data,
		);

		$status = PMS_CAPI::send_events( array( $event ), $settings, self::source_url(), array(), $browser_fired );

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
	 * pms-woocommerce.js sendet kurze, lesbare Event-Namen; hier gegen eine
	 * feste Whitelist aufgelöst -- der Client bestimmt den tatsächlich
	 * gesendeten Meta-Event-Namen nicht frei.
	 *
	 * @param string $key sanitize_key()'te Nutzereingabe.
	 * @return string Leerer String bei unbekanntem Event.
	 */
	private static function resolve_event_name( $key ) {
		$map = array(
			'viewcontent'      => 'ViewContent',
			'addtocart'        => 'AddToCart',
			'initiatecheckout' => 'InitiateCheckout',
		);

		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * custom_data für das jeweilige Event serverseitig auflösen. Nie aus dem
	 * Client übernommen (siehe Klassen-Doku oben).
	 *
	 * @param string $event_name 'ViewContent'|'AddToCart'|'InitiateCheckout'.
	 * @return array|null Null, wenn keine gültigen Produktdaten ermittelt werden konnten.
	 */
	private static function resolve_custom_data( $event_name ) {
		if ( 'InitiateCheckout' === $event_name ) {
			return self::build_cart_custom_data();
		}

		$product_id   = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
		$variation_id = absint( wp_unslash( $_POST['variation_id'] ?? 0 ) );
		$qty          = min( self::MAX_QTY, max( 1, absint( wp_unslash( $_POST['quantity'] ?? 1 ) ) ) );

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$data = PMS_Pro_Woo_Product_Data::get_product_data( $product, $qty );

		if ( empty( $data ) ) {
			return null;
		}

		return self::single_product_custom_data( $data, $qty );
	}

	/**
	 * custom_data-Payload für ein einzelnes Produkt (ViewContent/AddToCart).
	 *
	 * @param array $data Rückgabe von PMS_Pro_Woo_Product_Data::get_product_data().
	 * @param int   $qty  Menge.
	 * @return array
	 */
	private static function single_product_custom_data( array $data, $qty ) {
		$custom = array(
			'content_ids'  => array( $data['content_id'] ),
			'content_type' => 'product',
			'content_name' => $data['content_name'],
			'value'        => round( $data['value'] * $qty, 2 ),
			'currency'     => $data['currency'],
			'contents'     => array(
				array(
					'id'         => $data['content_id'],
					'quantity'   => $qty,
					'item_price' => $data['value'],
				),
			),
		);

		if ( '' !== $data['content_category'] ) {
			$custom['content_category'] = $data['content_category'];
		}

		return $custom;
	}

	/**
	 * custom_data-Payload für den kompletten Warenkorb (InitiateCheckout).
	 * Liest ausschließlich den serverseitigen Sitzungs-Warenkorb -- läuft
	 * identisch für Classic- und Block-Checkout (WooCommerce lädt WC()->cart
	 * aus der Session, unabhängig vom Checkout-Rendering).
	 *
	 * @return array|null Null bei leerem/fehlendem Warenkorb.
	 */
	private static function build_cart_custom_data() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}

		$content_ids = array();
		$contents    = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$qty  = max( 1, absint( $cart_item['quantity'] ) );
			$data = PMS_Pro_Woo_Product_Data::get_product_data( $cart_item['data'], $qty );

			if ( empty( $data ) ) {
				continue;
			}

			$content_ids[] = $data['content_id'];
			$contents[]    = array(
				'id'         => $data['content_id'],
				'quantity'   => $qty,
				'item_price' => $data['value'],
			);
		}

		if ( empty( $content_ids ) ) {
			return null;
		}

		return array(
			'content_ids'  => $content_ids,
			'content_type' => 'product',
			// Gesamtwert kommt bewusst vom Warenkorb selbst (Rabatte/Gebühren
			// eingerechnet), nicht aus der Summe der Einzelpreise.
			'value'        => (float) WC()->cart->get_total( 'edit' ),
			'currency'     => (string) get_woocommerce_currency(),
			'contents'     => $contents,
			'num_items'    => array_sum( array_column( $contents, 'quantity' ) ),
		);
	}

	/**
	 * event_source_url ermitteln (nur eigene Domain). Bewusst dupliziert statt
	 * PMS_Forms::source_url() sichtbar zu machen -- die Pro-Integration soll
	 * ohne Änderungen an geteilten Free/Pro-Dateien auskommen (siehe
	 * Klassen-Doku "Isolation" in CLAUDE.md).
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

	/**
	 * WooCommerce-custom_data in den generischen CAPI-Event-Payload einmischen
	 * (siehe pms_capi_event_data-Filter in PMS_CAPI::send_events()).
	 *
	 * @param array $payload Event-Payload.
	 * @param array $event   Event, inkl. des von handle_track() gesetzten
	 *                       Schlüssels pms_woo_custom_data.
	 * @return array
	 */
	public static function filter_capi_event_data( $payload, $event ) {
		if ( empty( $event['pms_woo_custom_data'] ) || ! is_array( $event['pms_woo_custom_data'] ) ) {
			return $payload;
		}

		$existing               = isset( $payload['custom_data'] ) && is_array( $payload['custom_data'] ) ? $payload['custom_data'] : array();
		$payload['custom_data'] = array_merge( $existing, $event['pms_woo_custom_data'] );

		return $payload;
	}
}
