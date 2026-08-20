<?php
/**
 * Pro-Feature: WooCommerce Purchase-Tracking mit Server-Side-Fallback.
 * Seit v0.6.6 Multi-Platform: Meta (Pixel + CAPI), Google Ads (gtag.js
 * Conversion + Enhanced Conversions) und TikTok (Pixel + Events API). Seit
 * v0.6.8 zusätzlich GA4 (gtag.js Standard-Event "purchase", siehe
 * ga4_purchase_js()) -- eigenständig von Google Ads, funktioniert auch ohne
 * konfigurierten Google-Ads-Tag.
 *
 * Zwei unabhängige Auslösewege für dasselbe Event, beide über eine
 * deterministische event_id (`pms_order_{$order_id}`, siehe event_id())
 * gegen Doppelzählung abgesichert -- anders als ViewContent/AddToCart/
 * InitiateCheckout (siehe class-pro-woo.php) bewusst OHNE clientseitig
 * generierte UUID: ein Purchase-Event ist untrennbar an genau eine
 * Bestellung gebunden, eine feste, aus der Order-ID abgeleitete ID ist
 * hier kein Cache-Risiko (die Danke-Seite ist ohnehin pro Bestellung
 * einmalig und wird von WooCommerce selbst nie gecacht).
 *
 * 1. **Danke-Seite** (`woocommerce_thankyou`): synchroner Pfad. Rendert die
 *    Browser-Pixel-Calls ALLER aktiven Plattformen inline (respektiert
 *    Consent genau wie die Basis-Skripte in class-pms-frontend.php, siehe
 *    print_pixel_scripts()) UND löst im selben Request den Meta-CAPI- sowie
 *    den TikTok-Events-API-Versand aus. Google Ads hat KEINEN Server-Pfad
 *    (Enhanced Conversions via gtag.js ist rein browserseitig, siehe
 *    google_conversion_js() -- ein "Server-Side-Fallback" ist für Google
 *    daher strukturell nicht möglich, nur für Meta/TikTok).
 * 2. **Server-Side-Fallback** (`woocommerce_payment_complete` sowie
 *    `woocommerce_order_status_completed`/`_processing`): reiner API-Pfad
 *    ohne Browser-Komponente (Meta-CAPI + TikTok-Events-API) -- fängt
 *    Bestellungen ab, bei denen der Kunde nach der Zahlung nicht auf die
 *    Danke-Seite zurückkehrt (externe Payment-Gateways wie PayPal/Klarna,
 *    die teils direkt weiterleiten). Greift nur, wenn Weg 1 diese Bestellung
 *    noch nicht markiert hat.
 *
 * Dedup-Flag: `_pms_purchase_tracked` als Order-Meta über die WC_Order-
 * eigene CRUD-API (get_meta()/update_meta_data()+save()), NICHT über
 * update_post_meta()/get_post_meta() -- Bestellungen liegen seit WooCommerce
 * High-Performance Order Storage (HPOS) nicht mehr zwingend als wp_posts-
 * Zeile vor, raw post-meta-Funktionen würden dort auf die falsche/eine gar
 * nicht existierende ID zugreifen. Dieselbe "native WC-CRUD statt Rohzugriff"
 * -Regel wie bei den Produktdaten in class-pro-woo-product-data.php. EIN
 * Flag für alle drei Plattformen zusammen (keine Pro-Plattform-Granularität)
 * -- dieselbe "eine Bestellung = ein Bearbeitungsversuch"-Vereinfachung wie
 * schon vor der Multi-Platform-Erweiterung.
 *
 * **Unverifiziert gegen echte Google-/TikTok-Testdaten:** Die genauen
 * Feld-/Hash-Anforderungen von Google Enhanced Conversions (welche
 * address-Unterfelder gehasht werden, E.164-Telefonformat) und der TikTok
 * Events API v1.3 basieren auf offizieller Dokumentation, wurden aber -- im
 * Unterschied zur Meta-Integration -- noch nie gegen echte Testdaten im
 * Google-Ads- bzw. TikTok-Events-Manager geprüft. Dieselbe Vorsicht gilt wie
 * beim `test_event_code`-Fund in der Vor-Rebrand-Ära (siehe „Test Event Code
 * bleibt CAPI-only" in CLAUDE.md): vor Live-Verlass auf die Diagnose-Tools
 * beider Plattformen gegenprüfen.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_Woo_Purchase {

	const TRACKED_META_KEY = '_pms_purchase_tracked';

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'track_thankyou' ) );

		// Alle drei Fallback-Hooks laufen auf denselben Handler -- die
		// _pms_purchase_tracked-Prüfung darin macht sie gegenseitig (und
		// gegen die Danke-Seite) idempotent, unabhängig davon, welcher
		// zuerst feuert oder ob mehrere für dieselbe Bestellung feuern.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_track_fallback' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_track_fallback' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_track_fallback' ), 10, 2 );
	}

	/**
	 * Ist WooCommerce-Tracking (und damit auch Purchase-Tracking, kein
	 * eigener Master-Toggle) konfiguriert und einsatzbereit?
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
	 * Darf für DIESEN Aufruf (unabhängig von einer konkreten Bestellung)
	 * überhaupt getrackt werden? Bewusst ohne PMS_Frontend::is_active() --
	 * die Fallback-Hooks laufen oft in einem Kontext ganz ohne Seitenaufruf
	 * (Zahlungs-Webhook), wo dieses request-lokale Flag nie gesetzt würde.
	 * exclude_admins/pms_allow_tracking sind dagegen unabhängig vom
	 * Seiten-Kontext sinnvoll auswertbar und werden deshalb hier repliziert
	 * (dieselbe kleine, bewusste Duplikation wie should_load() in
	 * class-pro-woo.php für Google/TikTok).
	 *
	 * @return bool
	 */
	private static function should_process() {
		if ( ! self::enabled() ) {
			return false;
		}

		$settings = PMS_Settings::get();

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			return false;
		}

		/** Dokumentiert in class-pms-frontend.php */
		return (bool) apply_filters( 'pms_allow_tracking', true );
	}

	/**
	 * Weg 1: Danke-Seite. Synchron im selben Request -- Browser-Pixel UND CAPI.
	 *
	 * @param int $order_id Bestell-ID.
	 * @return void
	 */
	public static function track_thankyou( $order_id ) {
		if ( ! self::should_process() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::already_tracked( $order ) ) {
			return;
		}

		$custom_data = self::build_order_custom_data( $order );
		if ( null === $custom_data ) {
			return;
		}

		self::print_pixel_scripts( $order, $custom_data );
		self::dispatch_capi( $order, $custom_data );
		self::dispatch_tiktok_capi( $order, $custom_data );
		self::mark_tracked( $order );
	}

	/**
	 * Weg 2: Server-Side-Fallback. Reiner CAPI-Pfad, kein Browser-Kontext.
	 *
	 * @param int           $order_id Bestell-ID.
	 * @param WC_Order|null $order    Bereits aufgelöste Bestellung, falls vom
	 *                                Hook mitgegeben (woocommerce_payment_complete
	 *                                liefert sie NICHT mit, die beiden
	 *                                order_status_*-Hooks schon).
	 * @return void
	 */
	public static function maybe_track_fallback( $order_id, $order = null ) {
		if ( ! self::should_process() ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::already_tracked( $order ) ) {
			return;
		}

		$custom_data = self::build_order_custom_data( $order );
		if ( null === $custom_data ) {
			return;
		}

		self::dispatch_capi( $order, $custom_data );
		self::dispatch_tiktok_capi( $order, $custom_data );
		self::mark_tracked( $order );
	}

	/**
	 * Deterministische Event-ID: identisch für Danke-Seite UND jeden
	 * Fallback-Versuch derselben Bestellung -- Meta dedupliziert automatisch,
	 * falls die _pms_purchase_tracked-Prüfung durch einen seltenen Zeitpunkt-
	 * Zufall (z. B. zwei Fallback-Hooks im selben Request) doch einmal
	 * zweimal auslöst.
	 *
	 * @param int $order_id Bestell-ID.
	 * @return string
	 */
	private static function event_id( $order_id ) {
		return 'pms_order_' . absint( $order_id );
	}

	/**
	 * Bereits getrackt? Liest über die WC_Order-eigene Meta-API (HPOS-sicher,
	 * siehe Klassen-Doku oben).
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	private static function already_tracked( WC_Order $order ) {
		return '1' === (string) $order->get_meta( self::TRACKED_META_KEY, true );
	}

	/**
	 * Als getrackt markieren (siehe Klassen-Doku oben für die HPOS-Begründung).
	 *
	 * @param WC_Order $order Bestellung.
	 * @return void
	 */
	private static function mark_tracked( WC_Order $order ) {
		$order->update_meta_data( self::TRACKED_META_KEY, 1 );
		$order->save();
	}

	/**
	 * custom_data für die Bestellung: Positionen, Gesamtwert (Netto/Brutto
	 * konfigurierbar), Steuer, Versand.
	 *
	 * Line-Item-Preise kommen bewusst NICHT aus PMS_Pro_Woo_Product_Data::
	 * get_product_data() (das liest den AKTUELLEN Katalogpreis) -- eine
	 * Bestellung muss den historisch tatsächlich gezahlten Betrag zeigen,
	 * der sich seit dem Kauf durch Rabattaktionen/Preisänderungen geändert
	 * haben kann. Content-ID/-Kategorie sind dagegen reine Produkt-Identität
	 * ohne Preisbezug und werden deshalb über die geteilten Resolver aus
	 * PMS_Pro_Woo_Product_Data wiederverwendet, wenn das Produkt noch existiert.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array|null Null, wenn die Bestellung keine gültigen Positionen hat.
	 */
	private static function build_order_custom_data( WC_Order $order ) {
		$content_ids = array();
		$contents    = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$qty = max( 1, (int) $item->get_quantity() );
			// get_total() ist bei WooCommerce-Order-Items immer NETTO
			// (Zeilensumme exkl. Steuer, nach Rabatten) -- konsistent mit dem
			// contents[].item_price-Format der anderen WooCommerce-Events.
			$item_price = round( ( (float) $item->get_total() ) / $qty, 2 );

			$product    = $item->get_product();
			$content_id = ( $product instanceof WC_Product )
				? PMS_Pro_Woo_Product_Data::resolve_content_id( $product )
				: (string) $item->get_product_id();

			$content_ids[] = $content_id;
			$contents[]    = array(
				'id'         => $content_id,
				'quantity'   => $qty,
				'item_price' => $item_price,
			);
		}

		if ( empty( $content_ids ) ) {
			return null;
		}

		$total = (float) $order->get_total();
		$tax   = (float) $order->get_total_tax();
		$value = ( 'net' === PMS_Settings::wc_purchase_value_type() ) ? round( $total - $tax, 2 ) : $total;

		return array(
			'content_ids'  => $content_ids,
			'content_type' => 'product',
			'contents'     => $contents,
			'value'        => $value,
			'currency'     => (string) $order->get_currency(),
			'num_items'    => array_sum( array_column( $contents, 'quantity' ) ),
			'tax'          => $tax,
			'shipping'     => (float) $order->get_shipping_total(),
		);
	}

	/**
	 * Gehashte Advanced-Matching-Felder aus der Rechnungsadresse der
	 * Bestellung. Gated hinter wc_purchase_advanced_matching (Privacy-by-
	 * Default aus, siehe PMS_Settings::get()) -- getrennt vom bestehenden
	 * hash_email-Setting, das ausschließlich die E-Mail eingeloggter Nutzer
	 * betrifft (class-pms-capi.php::build_user_data()) und eine deutlich
	 * kleinere Datenmenge (nur em) offenlegt.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array
	 */
	private static function build_order_user_data( WC_Order $order ) {
		$settings = PMS_Settings::get();

		if ( empty( $settings['wc_purchase_advanced_matching'] ) ) {
			return array();
		}

		$user_data = array();

		$email = PMS_CAPI::hash_email( $order->get_billing_email() );
		if ( '' !== $email ) {
			$user_data['em'] = array( $email );
		}

		$phone = PMS_CAPI::hash_phone( $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$user_data['ph'] = array( $phone );
		}

		// fn/ln/st/country: einfache Normalisierung (lowercase + trim).
		foreach (
			array(
				'fn'      => $order->get_billing_first_name(),
				'ln'      => $order->get_billing_last_name(),
				'st'      => $order->get_billing_state(),
				'country' => $order->get_billing_country(),
			) as $key => $raw
		) {
			$hash = PMS_CAPI::hash_field( $raw );
			if ( '' !== $hash ) {
				$user_data[ $key ] = array( $hash );
			}
		}

		// ct/zp: Meta verlangt zusätzlich das Entfernen von Leerzeichen.
		foreach (
			array(
				'ct' => $order->get_billing_city(),
				'zp' => $order->get_billing_postcode(),
			) as $key => $raw
		) {
			$hash = PMS_CAPI::hash_field( $raw, true );
			if ( '' !== $hash ) {
				$user_data[ $key ] = array( $hash );
			}
		}

		return $user_data;
	}

	/**
	 * CAPI-Versand für beide Auslösewege. Das custom_data landet über den
	 * bereits von PMS_Pro_WooCommerce (class-pro-woo.php) registrierten
	 * pms_capi_event_data-Filter im Payload -- der Schlüssel
	 * "pms_woo_custom_data" ist bewusst derselbe generische Name wie dort,
	 * eine zweite, identische Filter-Registrierung hier ist deshalb
	 * unnötig. Setzt voraus, dass PMS_Pro_WooCommerce::init() im selben
	 * Bootstrap gelaufen ist (siehe pixel-made-simple-pro.php) -- beide
	 * init()-Aufrufe registrieren nur Hooks/Filter und laufen lange vor
	 * jedem tatsächlichen WooCommerce-Event dieses Requests.
	 *
	 * @param WC_Order $order       Bestellung.
	 * @param array    $custom_data Von build_order_custom_data().
	 * @return array Status-Eintrag von PMS_CAPI::send_events().
	 */
	private static function dispatch_capi( WC_Order $order, array $custom_data ) {
		$settings = PMS_Settings::get();

		$event = array(
			'id'                  => 'woo-purchase',
			'name'                => 'Purchase',
			'event_type'          => 'Purchase',
			'event_id'            => self::event_id( $order->get_id() ),
			'meta_enabled'        => 1,
			'pms_woo_custom_data' => $custom_data,
		);

		return PMS_CAPI::send_events(
			array( $event ),
			$settings,
			$order->get_checkout_order_received_url(),
			self::build_order_user_data( $order )
		);
	}

	/**
	 * Browser-Pixel-Aufrufe ALLER aktiven Plattformen auf der Danke-Seite
	 * ausgeben (Meta fbq, Google gtag-Conversion inkl. Enhanced Conversions,
	 * TikTok ttq.track) -- ein einzelner kombinierter Block statt drei
	 * separater Skript-Tags, damit auch nur EIN window.pmsInitialized-Poll
	 * nötig ist (siehe unten).
	 *
	 * Direktes Echo, KEIN add_action('wp_head', ...) -- woocommerce_thankyou
	 * feuert innerhalb des Seiten-BODY (WooCommerce-Template
	 * checkout/thankyou.php), also lange NACHDEM wp_head bereits vollständig
	 * durchlaufen ist; ein hier registrierter wp_head-Callback würde für
	 * diesen Request nie mehr aufgerufen. Ein <script>-Tag mitten im Body ist
	 * unproblematisch (Skripte müssen nicht im <head> stehen).
	 *
	 * Wartet -- statt Consent-Logik hier ein zweites Mal nachzubauen -- auf
	 * denselben globalen window.pmsInitialized-Guard, den
	 * class-pms-frontend.php für alle anderen Events bereits setzt (sofort
	 * true, wenn beim Rendern schon Consent vorlag; sonst erst, sobald deren
	 * bestehender Consent-Bootstrap ihn setzt). Da wp_head zu diesem
	 * Zeitpunkt bereits gerendert ist, existiert dieser Guard (falls er
	 * überhaupt gesetzt wird) längst, wenn dieses Skript läuft.
	 *
	 * @param WC_Order $order       Bestellung.
	 * @param array    $custom_data Von build_order_custom_data().
	 * @return void
	 */
	private static function print_pixel_scripts( WC_Order $order, array $custom_data ) {
		$settings = PMS_Settings::get();
		$event_id = self::event_id( $order->get_id() );
		$fire     = '';

		if ( ! empty( $settings['pixel_enabled'] ) && ! empty( $settings['pixel_id'] ) ) {
			$payload = wp_json_encode( $custom_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( is_string( $payload ) ) {
				$fire .= "if('function'===typeof window.fbq){window.fbq('track','Purchase'," . $payload . ",{eventID:'" . esc_js( $event_id ) . "'});}";
			}
		}

		if ( ! empty( $settings['google_enabled'] ) && ! empty( $settings['google_tag_id'] ) ) {
			$fire .= self::google_conversion_js( $order, $custom_data, $event_id, $settings );
		}

		// GA4 (seit v0.6.8): eigenständig von der Google-Ads-Conversion oben --
		// die Ads-Conversion trägt ein explizites send_to (Conversion-Label),
		// erreicht also NIE ein GA4-Property (siehe PMS_Frontend::build_google_js()
		// für dieselbe Unterscheidung bei den URL-Events). Ein Shop kann GA4
		// ohne Google Ads betreiben, deshalb eigene, unabhängige Prüfung.
		if ( '' !== trim( (string) ( $settings['ga4_measurement_id'] ?? '' ) ) ) {
			$fire .= self::ga4_purchase_js( $custom_data, $event_id );
		}

		if ( ! empty( $settings['tiktok_enabled'] ) && ! empty( $settings['tiktok_pixel_id'] ) ) {
			$tiktok_params = array(
				'content_type' => 'product',
				'contents'     => array_map(
					static function ( $item ) {
						return array(
							'content_id' => $item['id'],
							'quantity'   => $item['quantity'],
							'price'      => $item['item_price'],
						);
					},
					$custom_data['contents']
				),
				'value'        => $custom_data['value'],
				'currency'     => $custom_data['currency'],
			);
			$payload = wp_json_encode( $tiktok_params, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( is_string( $payload ) ) {
				$fire .= "if('function'===typeof window.ttq){window.ttq.track('CompletePayment'," . $payload . ",{event_id:'" . esc_js( $event_id ) . "'});}";
			}
		}

		if ( '' === $fire ) {
			return;
		}

		wp_print_inline_script_tag(
			'(function(){function f(){if(window.pmsInitialized){' . $fire . 'return true;}return false;}'
			. 'if(!f()){var iv=setInterval(function(){if(f()){clearInterval(iv);}},150);'
			// Sicherheitsnetz: nach 30s aufgeben (z. B. dauerhaft
			// verweigerter Consent), statt endlos zu pollen.
			. 'setTimeout(function(){clearInterval(iv);},30000);}})();'
		);
	}

	/**
	 * Google Ads gtag-Conversion inkl. optionaler Enhanced Conversions.
	 * Rein browserseitig (siehe Klassen-Doku oben, warum Google keinen
	 * Server-Pfad hat) -- gtag() selbst existiert nur, wenn
	 * class-pms-frontend.php es auf dieser Seite bereits initialisiert hat
	 * (google_enabled, dieselbe Bedingung wie hier), das Purchase-Skript
	 * fügt nur den Conversion-Aufruf hinzu.
	 *
	 * Kein Label konfiguriert -> kein Aufruf, dieselbe Regel wie bei
	 * Google-Ads-URL-Events (PMS_Settings::sanitize_event(): "Google braucht
	 * zwingend ein Conversion Label").
	 *
	 * @param WC_Order $order       Bestellung.
	 * @param array    $custom_data Von build_order_custom_data().
	 * @param string   $event_id    Deterministische Event-ID (als transaction_id).
	 * @param array    $settings    Plugin-Einstellungen.
	 * @return string JS-Fragment oder leerer String.
	 */
	private static function google_conversion_js( WC_Order $order, array $custom_data, $event_id, array $settings ) {
		$label = trim( (string) ( $settings['wc_google_conversion_label'] ?? '' ) );
		if ( '' === $label ) {
			return '';
		}

		$tag_id = preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) $settings['google_tag_id'] );

		$params = array(
			'send_to'        => $tag_id . '/' . $label,
			'value'          => $custom_data['value'],
			'currency'       => $custom_data['currency'],
			'transaction_id' => $event_id,
		);

		if ( ! empty( $settings['wc_purchase_advanced_matching'] ) ) {
			$user_data = self::build_google_user_data( $order );
			if ( ! empty( $user_data ) ) {
				$params['user_data'] = $user_data;
			}
		}

		$payload = wp_json_encode( $params, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $payload ) ) {
			return '';
		}

		return "if('function'===typeof window.gtag){window.gtag('event','conversion'," . $payload . ");}";
	}

	/**
	 * GA4 Standard-Ecommerce-Event "purchase", seit v0.6.8. Rein browserseitig
	 * wie google_conversion_js() (dieselbe gtag()-Ladevoraussetzung), aber
	 * bewusst OHNE send_to -- ein GA4-Property kennt keine Conversion-Labels.
	 * Das Event geht stattdessen an jedes per gtag('config', ...) registrierte
	 * Ziel, das mit einem Standard-Event wie "purchase" etwas anfängt (also
	 * GA4-Properties; ein Google-Ads-Tag ignoriert es einfach).
	 * transaction_id = dieselbe deterministische Event-ID wie bei Meta/TikTok/
	 * Google Ads -- GA4 dedupliziert purchase-Events serverseitig anhand
	 * dieses Felds, exakt derselbe Zweck wie Metas eventID.
	 *
	 * items[] nutzt dasselbe {item_id,price,quantity}-Schema wie
	 * contentsToGoogleItems() in pms-woocommerce.js (view_item/add_to_cart/
	 * begin_checkout) -- hier serverseitig nachgebaut, weil Purchase (anders
	 * als die drei Browser-Events) aus $custom_data['contents'] kommt, nicht
	 * aus einem Client-Payload.
	 *
	 * @param array  $custom_data Von build_order_custom_data().
	 * @param string $event_id    Deterministische Event-ID (als transaction_id).
	 * @return string JS-Fragment oder leerer String.
	 */
	private static function ga4_purchase_js( array $custom_data, $event_id ) {
		$items = array_map(
			static function ( $item ) {
				return array(
					'item_id'  => (string) $item['id'],
					'price'    => (float) ( $item['item_price'] ?? 0 ),
					'quantity' => (int) ( $item['quantity'] ?? 1 ),
				);
			},
			$custom_data['contents']
		);

		$params = array(
			'transaction_id' => $event_id,
			'value'          => $custom_data['value'],
			'currency'       => $custom_data['currency'],
			'items'          => $items,
		);

		$payload = wp_json_encode( $params, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $payload ) ) {
			return '';
		}

		return "if('function'===typeof window.gtag){window.gtag('event','purchase'," . $payload . ");}";
	}

	/**
	 * Gehashte/strukturierte Bestelldaten für Google Enhanced Conversions
	 * (gtag.js `user_data`-Objekt). Getrennt von build_order_user_data()
	 * (Meta-Format em/ph/fn/…) -- Google verlangt eigene Schlüsselnamen und
	 * eine ANDERE Telefonnormalisierung (E.164 mit führendem "+" statt Metas
	 * führende-Null-Entfernung ohne "+", siehe hash_google_phone()), Metas
	 * bereits gehashte Werte sind hier deshalb NICHT wiederverwendbar.
	 *
	 * city/region/postal_code/country werden laut Google-Dokumentation
	 * UNGEHASHT übergeben (nur first_name/last_name/street sind Hash-Felder,
	 * genau wie email/phone_number) -- siehe Klassen-Doku oben zur
	 * fehlenden Live-Verifikation dieses Details.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array
	 */
	private static function build_google_user_data( WC_Order $order ) {
		$user_data = array();

		$email = PMS_CAPI::hash_email( $order->get_billing_email() );
		if ( '' !== $email ) {
			$user_data['email'] = $email;
		}

		$phone = self::hash_google_phone( $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$user_data['phone_number'] = $phone;
		}

		$address = array();

		$first_name = PMS_CAPI::hash_field( $order->get_billing_first_name() );
		if ( '' !== $first_name ) {
			$address['first_name'] = $first_name;
		}

		$last_name = PMS_CAPI::hash_field( $order->get_billing_last_name() );
		if ( '' !== $last_name ) {
			$address['last_name'] = $last_name;
		}

		$street = PMS_CAPI::hash_field( $order->get_billing_address_1() );
		if ( '' !== $street ) {
			$address['street'] = $street;
		}

		$city = sanitize_text_field( (string) $order->get_billing_city() );
		if ( '' !== $city ) {
			$address['city'] = $city;
		}

		$state = sanitize_text_field( (string) $order->get_billing_state() );
		if ( '' !== $state ) {
			$address['region'] = $state;
		}

		$postcode = sanitize_text_field( (string) $order->get_billing_postcode() );
		if ( '' !== $postcode ) {
			$address['postal_code'] = $postcode;
		}

		$country = sanitize_text_field( (string) $order->get_billing_country() );
		if ( '' !== $country ) {
			$address['country'] = $country;
		}

		if ( ! empty( $address ) ) {
			// Google erwartet ein Array von Adress-Objekten (mehrere Adressen
			// pro Conversion sind zulässig) -- hier immer genau eines.
			$user_data['address'] = array( $address );
		}

		return $user_data;
	}

	/**
	 * Telefonnummer für Google Enhanced Conversions hashen: E.164-Format
	 * (führendes "+", Ziffern) statt Metas führende-Null-Entfernung. Nutzt
	 * dieselbe pms_normalize_phone-Filterkette wie PMS_CAPI::hash_phone(),
	 * damit eine site-spezifische Landesvorwahl-Ergänzung (siehe dortige
	 * Doku) für beide Plattformen konsistent greift -- nur der letzte
	 * Formatierungsschritt (führendes "+") unterscheidet sich.
	 *
	 * @param string $raw Rohwert.
	 * @return string SHA-256-Hash oder leerer String.
	 */
	private static function hash_google_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', substr( (string) $raw, 0, 32 ) );
		if ( '' === $digits ) {
			return '';
		}

		$digits = preg_replace( '/^0+/', '', $digits );
		/** Dokumentiert in class-pms-capi.php::hash_phone() */
		$digits = (string) apply_filters( 'pms_normalize_phone', $digits, $raw );

		if ( strlen( $digits ) < 6 ) {
			return '';
		}

		return PMS_CAPI::hash_field( '+' . $digits );
	}

	/**
	 * TikTok Events API (server-seitig, analog zu PMS_CAPI::send_events()
	 * für Meta, aber TikTok-spezifisch -- siehe Klassen-Doku "Isolation":
	 * kein zweiter Aufrufer existiert aktuell, eine eigene Klasse wäre
	 * verfrühte Abstraktion). Respektiert denselben Consent-Gate wie Meta.
	 *
	 * Bewusst OHNE Event-Log-Eintrag (anders als PMS_CAPI::send_events()) --
	 * das Event-Log-Schema/UI ist auf den Meta-Sprachgebrauch zugeschnitten
	 * (source-Werte 'capi'/'both'/'browser'); eine vierte source würde auch
	 * PMS_Admin_Event_Log::render_row() anfassen müssen. Bewusster Scope-Cut
	 * dieser Session, siehe „Bekannte Trade-offs" in CLAUDE.md.
	 *
	 * @param WC_Order $order       Bestellung.
	 * @param array    $custom_data Von build_order_custom_data().
	 * @return void
	 */
	private static function dispatch_tiktok_capi( WC_Order $order, array $custom_data ) {
		$settings = PMS_Settings::get();

		if ( empty( $settings['tiktok_enabled'] ) || empty( $settings['tiktok_pixel_id'] )
			|| empty( $settings['tiktok_capi_enabled'] ) || empty( $settings['tiktok_access_token'] ) ) {
			return;
		}

		// DSGVO: dieselbe Marketing-Einwilligungsprüfung wie
		// PMS_CAPI::send_events() für Meta -- kein Sonderfall für
		// Server-zu-Server-Kontexte (siehe Klassen-Doku "Bekannte
		// Trade-offs" in CLAUDE.md für die ausführliche Begründung anhand
		// des Meta-Pendants).
		if ( class_exists( 'PMS_Consent' ) && ! PMS_Consent::has_marketing_consent() ) {
			return;
		}

		$user = array();

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validierung via filter_var.
			if ( $ip ) {
				$user['ip'] = $ip;
			}
		}
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		if ( ! empty( $settings['wc_purchase_advanced_matching'] ) ) {
			$email = PMS_CAPI::hash_email( $order->get_billing_email() );
			if ( '' !== $email ) {
				$user['email'] = $email;
			}
			$phone = PMS_CAPI::hash_phone( $order->get_billing_phone() );
			if ( '' !== $phone ) {
				$user['phone'] = $phone;
			}
		}

		// ttclid aus dem Attribution-Cookie (PMS_Pro_UTM, seit v0.6.6) --
		// nur verfügbar, wenn UTM-Passthrough aktiv ist UND der Besucher
		// tatsächlich über einen TikTok-Klick kam, siehe dortige Doku.
		if ( class_exists( 'PMS_Pro_UTM' ) ) {
			$ttclid = PMS_Pro_UTM::ttclid();
			if ( '' !== $ttclid ) {
				$user['ttclid'] = $ttclid;
			}
		}

		$properties = array(
			'content_type' => 'product',
			'contents'     => array_map(
				static function ( $item ) {
					return array(
						'content_id' => $item['id'],
						'quantity'   => $item['quantity'],
						'price'      => $item['item_price'],
					);
				},
				$custom_data['contents']
			),
			'value'        => $custom_data['value'],
			'currency'     => $custom_data['currency'],
		);

		$body = array(
			'event_source'    => 'web',
			'event_source_id' => preg_replace( '/[^A-Za-z0-9]+/', '', (string) $settings['tiktok_pixel_id'] ),
			'data'            => array(
				array(
					'event'      => 'CompletePayment',
					'event_time' => time(),
					'event_id'   => self::event_id( $order->get_id() ),
					'user'       => $user,
					'properties' => $properties,
					'page'       => array( 'url' => $order->get_checkout_order_received_url() ),
				),
			),
		);

		/**
		 * Für Debugging auf blockierend schalten, analog zu
		 * pms_capi_blocking für Meta (class-pms-capi.php).
		 *
		 * @param bool $blocking Standard: false (fire-and-forget).
		 */
		$blocking = (bool) apply_filters( 'pms_tiktok_capi_blocking', false );

		wp_remote_post(
			'https://business-api.tiktok.com/open_api/v1.3/event/track/',
			array(
				'timeout'   => $blocking ? 5 : 2,
				'blocking'  => $blocking,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Access-Token' => (string) $settings['tiktok_access_token'],
				),
				'body'      => wp_json_encode( $body ),
				'sslverify' => true,
			)
		);
	}
}
