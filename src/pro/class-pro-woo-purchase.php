<?php
/**
 * Pro-Feature: WooCommerce Purchase-Tracking mit Server-Side-Fallback.
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
 * 1. **Danke-Seite** (`woocommerce_thankyou`): synchroner Pfad. Rendert den
 *    Browser-Pixel-Call inline (respektiert Consent genau wie die Basis-
 *    Skripte in class-pms-frontend.php) UND löst im selben Request den
 *    CAPI-Versand aus.
 * 2. **Server-Side-Fallback** (`woocommerce_payment_complete` sowie
 *    `woocommerce_order_status_completed`/`_processing`): reiner CAPI-Pfad
 *    ohne Browser-Komponente -- fängt Bestellungen ab, bei denen der Kunde
 *    nach der Zahlung nicht auf die Danke-Seite zurückkehrt (externe
 *    Payment-Gateways wie PayPal/Klarna, die teils direkt weiterleiten).
 *    Greift nur, wenn Weg 1 diese Bestellung noch nicht markiert hat.
 *
 * Dedup-Flag: `_pms_purchase_tracked` als Order-Meta über die WC_Order-
 * eigene CRUD-API (get_meta()/update_meta_data()+save()), NICHT über
 * update_post_meta()/get_post_meta() -- Bestellungen liegen seit WooCommerce
 * High-Performance Order Storage (HPOS) nicht mehr zwingend als wp_posts-
 * Zeile vor, raw post-meta-Funktionen würden dort auf die falsche/eine gar
 * nicht existierende ID zugreifen. Dieselbe "native WC-CRUD statt Rohzugriff"
 * -Regel wie bei den Produktdaten in class-pro-woo-product-data.php.
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

		self::print_pixel_script( self::event_id( $order->get_id() ), $custom_data );
		self::dispatch_capi( $order, $custom_data );
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
	 * Browser-Pixel-Aufruf auf der Danke-Seite ausgeben. Direktes Echo, KEIN
	 * add_action('wp_head', ...) -- woocommerce_thankyou feuert innerhalb des
	 * Seiten-BODY (WooCommerce-Template checkout/thankyou.php), also lange
	 * NACHDEM wp_head bereits vollständig durchlaufen ist; ein hier
	 * registrierter wp_head-Callback würde für diesen Request nie mehr
	 * aufgerufen. Ein <script>-Tag mitten im Body ist unproblematisch (Skripte
	 * müssen nicht im <head> stehen).
	 *
	 * Wartet -- statt Consent-Logik hier ein zweites Mal nachzubauen -- auf
	 * denselben globalen window.pmsInitialized-Guard, den
	 * class-pms-frontend.php für alle anderen Events bereits setzt (sofort
	 * true, wenn beim Rendern schon Consent vorlag; sonst erst, sobald deren
	 * bestehender Consent-Bootstrap ihn setzt). Da wp_head zu diesem
	 * Zeitpunkt bereits gerendert ist, existiert dieser Guard (falls er
	 * überhaupt gesetzt wird) längst, wenn dieses Skript läuft.
	 *
	 * @param string $event_id    Deterministische Event-ID.
	 * @param array  $custom_data Von build_order_custom_data().
	 * @return void
	 */
	private static function print_pixel_script( $event_id, array $custom_data ) {
		$settings = PMS_Settings::get();

		if ( empty( $settings['pixel_enabled'] ) || empty( $settings['pixel_id'] ) ) {
			return; // Kein Meta-Pixel aktiv -- nur CAPI trackt (siehe dispatch_capi()).
		}

		$payload = wp_json_encode( $custom_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $payload ) ) {
			return;
		}

		$fire = "if('function'===typeof window.fbq){window.fbq('track','Purchase'," . $payload . ",{eventID:'" . esc_js( $event_id ) . "'});}";

		wp_print_inline_script_tag(
			'(function(){function f(){if(window.pmsInitialized){' . $fire . 'return true;}return false;}'
			. 'if(!f()){var iv=setInterval(function(){if(f()){clearInterval(iv);}},150);'
			// Sicherheitsnetz: nach 30s aufgeben (z. B. dauerhaft
			// verweigerter Consent), statt endlos zu pollen.
			. 'setTimeout(function(){clearInterval(iv);},30000);}})();'
		);
	}
}
