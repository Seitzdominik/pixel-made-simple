<?php
/**
 * Pro-Feature: SureCart-Tracking (ViewContent, AddToCart, InitiateCheckout).
 * Zweite E-Commerce-Integration neben WooCommerce (pro/class-pro-woo.php),
 * demselben Architektur-Muster folgend: eine reine Datenextraktionsklasse
 * (class-pro-surecart-product-data.php) + diese Klasse für Hooks/AJAX/CAPI-
 * Filter + assets/pms-surecart.js fürs Frontend. Purchase-Tracking (mit
 * Server-Side-Dispatch) lebt separat in class-pro-surecart-purchase.php,
 * exakt wie bei WooCommerce.
 *
 * Lade-Guard: init() wird nur von pixel-made-simple-pro.php aufgerufen (Free
 * kennt diese Datei nicht) UND bricht zusätzlich selbst ab, wenn SureCart
 * nicht aktiv ist (siehe active()) -- eine Pro-Lizenz ohne SureCart soll
 * keine toten Hooks/Assets registrieren.
 *
 * WICHTIGSTER STRUKTURELLER UNTERSCHIED zu WooCommerce, der die gesamte
 * Frontend-Architektur prägt: SureCart hat -- anders als WooCommerce, das
 * jQuery-Events (added_to_cart) und klare Template-Hooks
 * (woocommerce_after_single_product, woocommerce_before_checkout_form)
 * bereitstellt -- KEIN dokumentiertes JavaScript-Event für "Produkt zum
 * Warenkorb hinzugefügt" oder "Checkout betreten" (recherchiert gegen
 * developer.surecart.com/documentation/add-to-cart sowie die Cart-/
 * Checkout-Actions-Referenz, Stand dieser Session; siehe die ausführliche
 * Einordnung in assets/pms-surecart.js für AddToCart/InitiateCheckout).
 * ViewContent ist davon NICHT betroffen (reine is_singular('sc_product')-
 * Serverseiten-Erkennung, derselbe Ansatz wie is_product() bei WooCommerce)
 * und entsprechend hoch zuverlässig.
 *
 * Produkt-IDs sind bei SureCart UUIDs (z. B.
 * "b5094a04-34f7-4ae9-a193-55a4b74cabb9"), NICHT die numerische
 * WordPress-Post-ID des "sc_product"-Custom-Post-Types -- siehe
 * class-pro-surecart-product-data.php-Doku für die Einzelheiten. Preise/
 * Namen werden -- exakt wie bei WooCommerce -- NIE aus dem Client
 * übernommen: der Server löst Produkt/Preis für die Meta-CAPI IMMER selbst
 * über die SureCart-PHP-Models neu auf (siehe resolve_custom_data()).
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_SureCart {

	const AJAX_ACTION      = 'pms_surecart_track';
	const NONCE_ACTION     = 'pms_surecart_track';
	const MAX_LEN_EVENT_ID = 64;
	const MAX_LEN_URL      = 2000;
	const MAX_LEN_ID       = 128;
	const MAX_QTY          = 999;

	public static function init() {
		if ( ! self::active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_view_content_payload' ) );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_track' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_track' ) );

		// Eigener custom_data-Schlüssel (pms_surecart_custom_data), getrennt
		// von PMS_Pro_WooCommerce's pms_woo_custom_data -- derselbe Filter
		// (pms_capi_event_data, siehe class-pms-capi.php) bedient beide
		// Integrationen nebeneinander, ohne dass eine der beiden die Nutzlast
		// der anderen überschreiben könnte, falls eine Installation
		// theoretisch beide Plattformen gleichzeitig einsetzt.
		add_filter( 'pms_capi_event_data', array( __CLASS__, 'filter_capi_event_data' ), 10, 2 );
	}

	/**
	 * Ist das SureCart-Plugin überhaupt aktiv? SureCarts Haupt-Bootstrap
	 * stellt sowohl eine Marker-Klasse "SureCart" als auch eine globale
	 * Helper-Funktion surecart() bereit (siehe SureCart-Kerndokumentation) --
	 * beide werden geprüft, falls eine künftige SureCart-Version eine der
	 * beiden umbenennt/entfernt.
	 *
	 * @return bool
	 */
	private static function active() {
		return class_exists( 'SureCart' ) || function_exists( 'surecart' );
	}

	/**
	 * Ist SureCart-Tracking konfiguriert und einsatzbereit?
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! PMS_Settings::is_pro() || ! self::active() ) {
			return false;
		}

		$settings = PMS_Settings::get();

		return ! empty( $settings['sc_tracking_enabled'] );
	}

	/**
	 * Soll auf diesem Request überhaupt etwas SureCart-Bezogenes laufen?
	 * Respektiert zusätzlich das globale Tracking-Gate über
	 * PMS_Frontend::is_active() -- dieselbe Bedingung, unter der auch der
	 * Formular-Grabber und die WooCommerce-Integration ihr Skript laden.
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

		return self::is_product_page() || self::page_has_surecart_markup();
	}

	/**
	 * Produktseite (Singular des SureCart-eigenen "sc_product"-Custom-Post-
	 * Types) -- das Pendant zu WooCommerces is_product().
	 *
	 * @return bool
	 */
	private static function is_product_page() {
		return is_singular( 'sc_product' );
	}

	/**
	 * Enthält die aktuelle Singular-Seite bekannte SureCart-Checkout-/
	 * Warenkorb-/Bestätigungs-Marker (Blöcke oder Shortcodes)? SureCart hat
	 * -- anders als WooCommerce mit is_cart()/is_checkout() -- keine eigenen
	 * globalen Query-Conditionals; die hier geprüften Block-/Shortcode-Namen
	 * (surecart/line-items, surecart/submit, surecart/totals, surecart/coupon,
	 * [sc_order_confirmation], [sc_buy_button], [sc_product_cart_button])
	 * sind öffentlich dokumentierte, stabile Bezeichner
	 * (developer.surecart.com), daher eine belastbarere Erkennung als ein
	 * Rätselraten anhand von Seiten-Slugs.
	 *
	 * @return bool
	 */
	private static function page_has_surecart_markup() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		foreach ( array( 'surecart/line-items', 'surecart/submit', 'surecart/totals', 'surecart/coupon' ) as $block ) {
			if ( has_block( $block, $post ) ) {
				return true;
			}
		}

		foreach ( array( 'sc_order_confirmation', 'sc_buy_button', 'sc_product_cart_button' ) as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * pms-surecart.js nur auf relevanten Seiten laden (siehe should_load()).
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! self::should_load() ) {
			return;
		}

		wp_enqueue_script( 'pms-surecart', PMS_PLUGIN_URL . 'assets/pms-surecart.js', array(), PMS_VERSION, true );
		wp_script_add_data( 'pms-surecart', 'strategy', 'defer' );

		$settings = PMS_Settings::get();

		wp_localize_script(
			'pms-surecart',
			'pms_surecart_settings',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'consentEvents' => class_exists( 'PMS_Consent' ) ? array_values( PMS_Consent::consent_events() ) : array(),
				// Consent-Modus (seit v0.6.10): steuert in diesem Skript, ob der
				// CAPI-Request bei fehlender Einwilligung mit in die Consent-Queue
				// wandert ('strict') oder sofort rausgeht, während nur der
				// Browser-Pixel wartet ('browser_only'). Serverseitige
				// Gegenstelle: PMS_Consent::has_server_consent().
				'consentMode'   => PMS_Settings::consent_mode(),
				// Google Ads (gtag) / TikTok Pixel (ttq) sind seit der
				// WooCommerce-Multi-Platform-Erweiterung (v0.6.6) dasselbe
				// Muster: eigenes *Enabled-Flag UND tatsächliche
				// Funktionsexistenz prüfen, siehe assets/pms-surecart.js.
				// gtag/ttq selbst lädt bereits class-pms-frontend.php, hier
				// wird nichts neu enqueued. Kein zusätzlicher is_pro()-Check
				// nötig: should_load() garantiert das bereits.
				//
				// Seit v0.6.8 zählt zusätzlich ga4_measurement_id für dieses
				// Flag -- ViewContent/AddToCart/InitiateCheckout feuern ohne
				// send_to (siehe pms-surecart.js) und erreichen damit jedes
				// per gtag('config', ...) registrierte Ziel automatisch,
				// GA4 UND Google Ads gleichermaßen. Ein Shop mit NUR GA4
				// (kein Google Ads) bekäme sonst fälschlich 'googleEnabled':
				// false, obwohl class-pms-frontend.php gtag.js in diesem Fall
				// längst lädt (siehe PMS_Frontend::build_google_js()).
				'googleEnabled' => ( ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ) )
					|| '' !== trim( (string) ( $settings['ga4_measurement_id'] ?? '' ) ),
				'tiktokEnabled' => ! empty( $settings['tiktok_enabled'] ) && ! empty( $settings['tiktok_pixel_id'] ),
				// GA4-Purchase (siehe firePurchase()/fireGA4Purchase() in
				// pms-surecart.js) ist unabhängig von Google Ads -- eigene,
				// simple Anwesenheitsprüfung statt googleEnabled, da SureCart
				// (anders als WooCommerce) den Purchase-Browser-Pixel-Call
				// selbst aus diesem Skript heraus feuert (kein PHP-Render-Hook,
				// siehe Klassen-Doku oben).
				'ga4MeasurementId' => ! empty( $settings['ga4_measurement_id'] ) ? preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) $settings['ga4_measurement_id'] ) : '',
				// Purchase-Tracking (PMS_Pro_SureCart_Purchase) feuert die
				// Browser-Pixel selbst aus diesem Skript heraus (siehe
				// dortige Klassen-Doku für die ausführliche Begründung,
				// warum SureCart -- anders als WooCommerce -- keinen
				// PHP-Seiten-Render-Hook für die Bestätigung bietet). Ein
				// eigener, separat genonce'ter Endpunkt liefert bei Bedarf
				// gehashte Google-Enhanced-Conversions-Felder nach.
				'purchaseNonce'            => wp_create_nonce( PMS_Pro_SureCart_Purchase::MATCHING_NONCE_ACTION ),
				'scGoogleAdvancedMatching' => ! empty( $settings['sc_purchase_advanced_matching'] )
					&& ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ),
				'googleTagId'              => ! empty( $settings['google_tag_id'] ) ? preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) $settings['google_tag_id'] ) : '',
				'scGoogleConversionLabel'  => (string) ( $settings['sc_google_conversion_label'] ?? '' ),
				// Dieselbe filterbare Minor-Unit-Basis wie serverseitig
				// (siehe currency_minor_unit() in dieser Klasse und in
				// class-pro-surecart-product-data.php) -- als fertiger
				// Divisor lokalisiert, damit pms-surecart.js Beträge aus
				// beobachteten SureCart-API-Antworten (Minor Units) exakt
				// so umrechnet wie der Server.
				'currencyMinorUnitDivisor' => self::currency_minor_unit(),
			)
		);
	}

	/**
	 * ViewContent-Nutzlast auf der Produktseite ausgeben. Läuft auf
	 * wp_footer (kein dokumentiertes SureCart-Produktseiten-Template-Hook
	 * verfügbar, siehe Klassen-Doku) statt eines plattformspezifischen
	 * Action-Hooks -- funktional gleichwertig zu
	 * PMS_Pro_WooCommerce::render_view_content_payload(), da beide nur
	 * sicherstellen müssen, dass das <script>-Tag irgendwo im Dokument vor
	 * dem Ausführen von pms-surecart.js im Markup steht.
	 *
	 * @return void
	 */
	public static function render_view_content_payload() {
		if ( ! self::should_load() || ! self::is_product_page() ) {
			return;
		}

		$post_id = get_queried_object_id();
		$product = self::fetch_current_product( $post_id );

		if ( null === $product ) {
			return;
		}

		$data = PMS_Pro_SureCart_Product_Data::get_product_data( $product, 1, $post_id );

		if ( empty( $data ) ) {
			return;
		}

		// Wie bei WooCommerce (siehe class-pro-woo.php): content_id kann je
		// nach sc_content_id_type eine SKU sein -- der spätere AJAX-
		// Roundtrip (handle_track()) braucht zusätzlich immer die echte
		// SureCart-Produkt-ID, damit der Server das Produkt zuverlässig neu
		// auflösen kann.
		$data['product_id'] = isset( $product->id ) ? (string) $product->id : '';

		self::print_json( 'pms-surecart-view-content-data', $data );
	}

	/**
	 * Aktuelles Produkt über den SureCart-Template-Helper sc_get_product()
	 * auflösen (Pendant zu wc_get_product()) -- fällt auf eine direkte
	 * Model-Abfrage zurück, falls der Helper aus irgendeinem Grund (noch)
	 * nicht verfügbar ist. Beide Wege sind defensiv in try/catch gekapselt:
	 * SureCarts PHP-Models sprechen laut eigener Dokumentation mit der
	 * SureCart-REST-API, ein Netzwerk-/Auth-Fehler soll das Frontend nie
	 * fatal beenden.
	 *
	 * @param int $post_id WordPress-Post-ID der "sc_product"-Seite.
	 * @return object|null
	 */
	private static function fetch_current_product( $post_id ) {
		if ( $post_id <= 0 ) {
			return null;
		}

		try {
			if ( function_exists( 'sc_get_product' ) ) {
				$product = sc_get_product( $post_id );
				if ( is_object( $product ) ) {
					return $product;
				}
			}

			if ( class_exists( '\SureCart\Models\Product' ) ) {
				$sc_id = get_post_meta( $post_id, 'sc_id', true );
				if ( '' !== $sc_id && is_string( $sc_id ) ) {
					$product = \SureCart\Models\Product::find( $sc_id );
					if ( is_object( $product ) ) {
						return $product;
					}
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- absichtlich stumm, siehe Methoden-Doku.
			// SureCart-API nicht erreichbar/Auth-Fehler -- kein Tracking für
			// diesen Aufruf, statt das Frontend fatal zu beenden.
		}

		return null;
	}

	/**
	 * Produkt über die SureCart-Produkt-ID (UUID) neu auflösen -- für den
	 * AJAX-Roundtrip, bei dem KEINE WordPress-Post-ID zur Verfügung steht
	 * (siehe handle_track()), daher hier ohne Kategorie-Auflösung
	 * (PMS_Pro_SureCart_Product_Data::resolve_category() braucht eine
	 * Post-ID, siehe dortige Doku).
	 *
	 * @param string $product_id SureCart-Produkt-ID (UUID).
	 * @return object|null
	 */
	private static function fetch_product_by_id( $product_id ) {
		if ( '' === $product_id || ! class_exists( '\SureCart\Models\Product' ) ) {
			return null;
		}

		try {
			$product = \SureCart\Models\Product::find( $product_id );
			return is_object( $product ) ? $product : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Produkt über eine Price-ID auflösen (zweiter Auflösungsweg für
	 * AddToCart, siehe resolve_custom_data()): erst die Price laden, dann
	 * deren Produkt-Referenz auflösen. Price::$product kann laut SureCarts
	 * REST-Schema entweder eine reine ID (String) oder -- falls die
	 * aufrufende Stelle "expand" genutzt hat -- ein bereits eingebettetes
	 * Produkt-Objekt sein; beide Formen werden unterstützt, da nicht
	 * gegen ein echtes Backend verifiziert werden konnte, welche davon
	 * Price::find() ohne Weiteres liefert (siehe Klassen-Doku in
	 * class-pro-surecart-product-data.php).
	 *
	 * @param string $price_id SureCart-Price-ID.
	 * @return object|null
	 */
	private static function fetch_product_by_price_id( $price_id ) {
		if ( '' === $price_id || ! class_exists( '\SureCart\Models\Price' ) ) {
			return null;
		}

		try {
			$price = \SureCart\Models\Price::find( $price_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $price ) ? self::resolve_product_from_price( $price ) : null;
	}

	/**
	 * Produkt-Referenz einer Price auflösen. Price::$product kann laut
	 * SureCarts REST-Schema entweder eine reine ID (String) oder -- bei
	 * genutztem "expand" -- ein bereits eingebettetes Produkt-Objekt sein;
	 * beide Formen werden unterstützt, siehe Klassen-Doku in
	 * class-pro-surecart-product-data.php für die fehlende Live-Verifikation
	 * dieses Details. Von zwei Aufrufern genutzt (fetch_product_by_price_id()
	 * für AddToCart, build_checkout_custom_data() für InitiateCheckout).
	 *
	 * @param object $price Price-Objekt.
	 * @return object|null
	 */
	private static function resolve_product_from_price( $price ) {
		if ( empty( $price->product ) ) {
			return null;
		}

		if ( is_object( $price->product ) ) {
			return $price->product;
		}

		return self::fetch_product_by_id( (string) $price->product );
	}

	/**
	 * JSON-Nutzlast als vom Frontend-Skript lesbares
	 * <script type="application/json"> ausgeben. Dieselbe JSON_HEX_*-
	 * Absicherung wie bei PMS_Pro_WooCommerce::print_json() -- Produktname/
	 * -kategorie stammen aus Shop-Daten, die ein Store-Betreiber oder ein
	 * Import frei befüllen kann.
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
	 * Längenbegrenzung/Whitelisting VOR jeder weiteren Verarbeitung --
	 * dasselbe Muster wie PMS_Pro_WooCommerce::handle_track().
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

		$browser_fired = ! empty( $_POST['browser_fired'] );

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'reason' => 'admin_excluded' ), 200 );
		}

		/** Dokumentiert in class-pms-frontend.php */
		if ( ! apply_filters( 'pms_allow_tracking', true ) ) {
			wp_send_json_error( array( 'reason' => 'tracking_disabled' ), 200 );
		}

		// Server-Gate (siehe PMS_Consent::has_server_consent()) -- dieser
		// Endpunkt sendet ausschließlich die Meta-CAPI; über den Browser-Pixel
		// hat pms-surecart.js bereits selbst entschieden.
		if ( ! PMS_Consent::has_server_consent() ) {
			wp_send_json_error( array( 'reason' => 'no_consent' ), 200 );
		}

		if ( empty( $settings['capi_enabled'] ) || empty( $settings['pixel_id'] ) || empty( $settings['capi_token'] ) ) {
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
			'id'                       => 'sc-' . strtolower( $event_name ),
			'name'                     => $event_name,
			'event_type'               => $event_name,
			'event_id'                 => $event_id,
			'meta_enabled'             => 1,
			'pms_surecart_custom_data' => $custom_data,
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
	 * pms-surecart.js sendet kurze, lesbare Event-Namen; hier gegen eine
	 * feste Whitelist aufgelöst.
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
	 * @return array|null Null, wenn keine gültigen Daten ermittelt werden konnten.
	 */
	private static function resolve_custom_data( $event_name ) {
		if ( 'InitiateCheckout' === $event_name ) {
			$checkout_id = self::clean_id( wp_unslash( $_POST['checkout_id'] ?? '' ) );
			$checkout    = self::fetch_checkout( $checkout_id );

			return null !== $checkout ? self::build_checkout_custom_data( $checkout ) : array();
		}

		$qty = min( self::MAX_QTY, max( 1, absint( wp_unslash( $_POST['quantity'] ?? 1 ) ) ) );

		$product_id = self::clean_id( wp_unslash( $_POST['product_id'] ?? '' ) );
		$price_id   = self::clean_id( wp_unslash( $_POST['price_id'] ?? '' ) );

		$product = '' !== $product_id ? self::fetch_product_by_id( $product_id ) : null;
		if ( null === $product && '' !== $price_id ) {
			$product = self::fetch_product_by_price_id( $price_id );
		}

		if ( null === $product ) {
			return null;
		}

		$data = PMS_Pro_SureCart_Product_Data::get_product_data( $product, $qty );

		if ( empty( $data ) ) {
			return null;
		}

		return self::single_product_custom_data( $data, $qty );
	}

	/**
	 * ID-artigen POST-Wert (SureCart-UUIDs) säubern: Länge kappen, auf
	 * UUID-typische Zeichen beschränken.
	 *
	 * @param mixed $value Roher POST-Wert.
	 * @return string
	 */
	private static function clean_id( $value ) {
		$value = substr( (string) $value, 0, self::MAX_LEN_ID );
		return preg_replace( '/[^A-Za-z0-9\-_]/', '', $value );
	}

	/**
	 * custom_data-Payload für ein einzelnes Produkt (ViewContent/AddToCart) --
	 * identisches Format zu PMS_Pro_WooCommerce::single_product_custom_data().
	 *
	 * @param array $data Rückgabe von PMS_Pro_SureCart_Product_Data::get_product_data().
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
	 * Checkout über seine ID neu auflösen -- die einzige Quelle, aus der
	 * build_checkout_custom_data() Geldbeträge liest, NIE aus dem Client
	 * (siehe Klassen-Doku oben). Auch von PMS_Pro_SureCart_Purchase genutzt
	 * (dort meist bereits als Objekt vorhanden, siehe dortige Doku).
	 *
	 * @param string $checkout_id SureCart-Checkout-ID.
	 * @return object|null
	 */
	public static function fetch_checkout( $checkout_id ) {
		if ( '' === $checkout_id || ! class_exists( '\SureCart\Models\Checkout' ) ) {
			return null;
		}

		try {
			$checkout = \SureCart\Models\Checkout::find( $checkout_id );
			return is_object( $checkout ) ? $checkout : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * custom_data-Payload für einen SureCart-Checkout. Dient sowohl
	 * InitiateCheckout (Checkout noch unbezahlt, $value_type bleibt "gross" --
	 * dieselbe Regel wie bei WooCommerces InitiateCheckout, das den
	 * Sitzungs-Warenkorb unabhängig von wc_purchase_value_type immer brutto
	 * liest) als auch PMS_Pro_SureCart_Purchase (Checkout bereits bezahlt,
	 * $value_type kommt von PMS_Settings::sc_purchase_value_type()) --
	 * public, damit beide Klassen dieselbe Logik wiederverwenden, exakt das
	 * Muster von PMS_Pro_Woo_Product_Data::resolve_content_id()/
	 * resolve_category(), die aus demselben Grund public wurden.
	 *
	 * Value/Steuer kommen bewusst aus den CHECKOUT-eigenen Feldern
	 * (total_amount/tax_amount, laut SureCarts REST-Schema real vorhanden),
	 * NICHT aus der Summe der Line-Item-Beträge -- Checkout-weite Rabatte/
	 * Gebühren fließen so korrekt ein, exakt dieselbe Überlegung wie bei
	 * WC_Order::get_total() in PMS_Pro_Woo_Purchase::build_order_custom_data().
	 * Line-Item-Preise (contents[].item_price) kommen dagegen aus dem
	 * jeweiligen LineItem::$total_amount/$quantity -- dem zum
	 * Checkout-Zeitpunkt TATSÄCHLICH berechneten Betrag dieser Position,
	 * NICHT aus einem erneuten Price::$amount-Lookup (könnte sich seither
	 * geändert haben) -- dasselbe "historischer Preis statt Live-
	 * Katalogpreis"-Prinzip wie bei WC_Order_Item_Product::get_total().
	 * Die Price wird trotzdem einmal geladen, aber ausschließlich für die
	 * Produkt-/content_id-Auflösung (siehe resolve_product_from_price()).
	 *
	 * @param object $checkout   SureCart-Checkout-Objekt (siehe fetch_checkout()).
	 * @param string $value_type 'gross' (Default) oder 'net' (abzüglich tax_amount).
	 * @return array Leeres Array, wenn keine gültigen Positionen ermittelt
	 *               werden konnten -- ein Event mit weniger Daten ist besser
	 *               als gar keins (siehe Aufrufer).
	 */
	public static function build_checkout_custom_data( $checkout, $value_type = 'gross' ) {
		if ( ! is_object( $checkout ) || empty( $checkout->id ) || ! class_exists( '\SureCart\Models\LineItem' ) ) {
			return array();
		}

		try {
			$line_items = \SureCart\Models\LineItem::where( array( 'checkout' => $checkout->id ) )->get();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_iterable( $line_items ) ) {
			return array();
		}

		$content_ids = array();
		$contents    = array();

		foreach ( $line_items as $item ) {
			if ( ! is_object( $item ) || empty( $item->price ) ) {
				continue;
			}

			$qty = isset( $item->quantity ) ? max( 1, absint( $item->quantity ) ) : 1;

			$price   = self::fetch_price( is_object( $item->price ) ? ( $item->price->id ?? '' ) : (string) $item->price, $item->price );
			$product = null !== $price ? self::resolve_product_from_price( $price ) : null;

			$content_id = ( null !== $product )
				? PMS_Pro_SureCart_Product_Data::resolve_content_id( $product )
				: (string) ( is_object( $item->price ) ? ( $item->price->id ?? '' ) : $item->price );

			$line_total = isset( $item->total_amount ) ? (float) $item->total_amount : 0.0;
			$item_price = round( $line_total / self::currency_minor_unit() / $qty, 2 );

			$content_ids[] = $content_id;
			$contents[]    = array(
				'id'         => $content_id,
				'quantity'   => $qty,
				'item_price' => $item_price,
			);
		}

		if ( empty( $content_ids ) ) {
			return array();
		}

		$currency = ! empty( $checkout->currency ) ? strtoupper( (string) $checkout->currency ) : '';
		$total    = isset( $checkout->total_amount ) ? ( (float) $checkout->total_amount ) / self::currency_minor_unit() : 0.0;
		$tax      = isset( $checkout->tax_amount ) ? ( (float) $checkout->tax_amount ) / self::currency_minor_unit() : 0.0;
		$value    = ( 'net' === $value_type ) ? round( $total - $tax, 2 ) : round( $total, 2 );

		return array(
			'content_ids'  => $content_ids,
			'content_type' => 'product',
			'contents'     => $contents,
			'value'        => $value,
			'currency'     => $currency,
			'num_items'    => array_sum( array_column( $contents, 'quantity' ) ),
			'tax'          => round( $tax, 2 ),
		);
	}

	/**
	 * Price-Objekt auflösen: nutzt ein bereits eingebettetes Objekt (falls
	 * die aufrufende Stelle es schon hat), sonst ein frischer
	 * Price::find()-Aufruf.
	 *
	 * @param string      $price_id       Price-ID.
	 * @param object|null $maybe_embedded Bereits vorhandenes Objekt, falls kein String.
	 * @return object|null
	 */
	private static function fetch_price( $price_id, $maybe_embedded ) {
		if ( is_object( $maybe_embedded ) ) {
			return $maybe_embedded;
		}

		if ( '' === $price_id || ! class_exists( '\SureCart\Models\Price' ) ) {
			return null;
		}

		try {
			$price = \SureCart\Models\Price::find( $price_id );
			return is_object( $price ) ? $price : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Dieselbe filterbare Minor-Unit-Basis wie
	 * PMS_Pro_SureCart_Product_Data::currency_minor_unit() -- absichtlich
	 * dupliziert statt sichtbar gemacht, um diese Klasse unabhängig von den
	 * internen Details der Produktdaten-Klasse zu halten (dieselbe kleine
	 * Duplikation, die dieses Projekt an mehreren Stellen bewusst in Kauf
	 * nimmt, siehe z. B. PMS_Pro_Woo_Purchase::should_process()).
	 *
	 * @return int
	 */
	private static function currency_minor_unit() {
		/** Dokumentiert in class-pro-surecart-product-data.php */
		$exponent = (int) apply_filters( 'pms_surecart_currency_minor_unit', 2 );
		$exponent = max( 0, min( 4, $exponent ) );

		return (int) pow( 10, $exponent );
	}

	/**
	 * event_source_url ermitteln (nur eigene Domain) -- identisch zu
	 * PMS_Pro_WooCommerce::source_url().
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
	 * SureCart-custom_data in den generischen CAPI-Event-Payload einmischen
	 * (siehe pms_capi_event_data-Filter in PMS_CAPI::send_events()).
	 *
	 * @param array $payload Event-Payload.
	 * @param array $event   Event, inkl. des von handle_track() gesetzten
	 *                       Schlüssels pms_surecart_custom_data.
	 * @return array
	 */
	public static function filter_capi_event_data( $payload, $event ) {
		if ( empty( $event['pms_surecart_custom_data'] ) || ! is_array( $event['pms_surecart_custom_data'] ) ) {
			return $payload;
		}

		$existing               = isset( $payload['custom_data'] ) && is_array( $payload['custom_data'] ) ? $payload['custom_data'] : array();
		$payload['custom_data'] = array_merge( $existing, $event['pms_surecart_custom_data'] );

		return $payload;
	}
}
