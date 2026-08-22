<?php
/**
 * Pro-Feature: SureCart Purchase-Tracking (Meta CAPI, Google Ads Enhanced
 * Conversions, TikTok Events API). Zweites Purchase-Modul neben WooCommerce
 * (pro/class-pro-woo-purchase.php) -- gleiche Zielsetzung, aber strukturell
 * an SureCarts tatsächliche Architektur angepasst, siehe unten.
 *
 * ====================================================================
 * ARCHITEKTUR-ABWEICHUNG von PMS_Pro_Woo_Purchase -- bewusst, nicht bloß
 * übernommen, siehe Begründung:
 * ====================================================================
 *
 * WooCommerce liefert mit `woocommerce_thankyou` einen echten PHP-Template-
 * Hook, der im BODY der vom Kunden tatsächlich betrachteten Danke-Seite
 * feuert -- dort lässt sich ein <script>-Tag mit Browser-Pixel-Aufrufen
 * direkt einecho'en (siehe PMS_Pro_Woo_Purchase::print_pixel_scripts()).
 *
 * SureCart hat kein Äquivalent. Recherchiert gegen die offizielle
 * SureCart-Entwicklerdokumentation (developer.surecart.com, Stand dieser
 * Session):
 * - `surecart/checkout_confirmed` (Parameter: \SureCart\Models\Checkout
 *   $checkout, \WP_REST_Request $request) feuert zuverlässig einmal pro
 *   abgeschlossenem Checkout -- ABER innerhalb des REST-API-Requests, der
 *   den Checkout finalisiert, NICHT während eines Seitenaufrufs, den der
 *   Kunden-Browser gerade rendert. Ein hier ausgegebenes <script>-Tag
 *   würde im Response-Body des REST-Requests landen, nicht auf der
 *   sichtbaren Seite -- für Browser-Pixel-Zwecke wirkungslos.
 * - Die Bestätigung selbst zeigt SureCart standardmäßig als JS-gesteuertes
 *   Modal auf der Checkout-Seite (kein neuer Seitenaufruf) ODER, falls
 *   konfiguriert, auf einer eigenen "Thank You"-Seite mit dem
 *   `[sc_order_confirmation]`-Shortcode/-Block (surecart.com/docs/
 *   custom-thank-you-page) -- in KEINEM der beiden Fälle ist zum
 *   PHP-Rendering-Zeitpunkt bekannt, für welchen Checkout die Seite steht
 *   (der Shortcode nimmt keine Parameter entgegen).
 *
 * Konsequenz: Diese Klasse ist der server-seitige CAPI-/Events-API-
 * Dispatcher (Meta Conversions API + TikTok Events API) für BEIDE
 * Auslösewege -- sie gibt NIE HTML/JS aus. Das Feuern der Browser-Pixel
 * (fbq/gtag/ttq) für Purchase übernimmt STATTDESSEN assets/pms-surecart.js:
 * dasselbe Skript, das ohnehin schon jeden SureCart-REST-Traffic auf
 * Checkout-/Warenkorb-Seiten beobachtet (siehe dortige Doku zu
 * ViewContent/AddToCart/InitiateCheckout), erkennt eine ALS BEZAHLT
 * erkannte Checkout-Antwort und feuert dann selbst fbq('track','Purchase')/
 * gtag('event','conversion')/ttq.track('CompletePayment') -- mit
 * GENAU DERSELBEN deterministischen event_id wie diese Klasse serverseitig
 * verwendet (event_id(), siehe unten), damit Meta/TikTok weiterhin
 * Browser- und Server-Event deduplizieren, exakt wie überall sonst in
 * diesem Plugin. Das funktioniert unabhängig davon, ob die Bestätigung als
 * Modal oder als eigene Seite erscheint, weil pms-surecart.js in beiden
 * Fällen bereits läuft (siehe PMS_Pro_SureCart::page_has_surecart_markup()).
 *
 * Advanced Matching/Enhanced Conversions bleibt dabei plattformspezifisch
 * konsistent mit dem bestehenden WooCommerce-Verhalten aufgeteilt (siehe
 * PMS_Pro_Woo_Purchase::print_pixel_scripts()'s Aufteilung):
 * - Meta: gehashte em/ph/fn/ln/ct/st/zp/country AUSSCHLIESSLICH über die
 *   CAPI (dispatch_capi(), diese Klasse) -- der Browser-fbq-Aufruf bekommt
 *   (wie bei WooCommerce) niemals user_data.
 * - TikTok: dieselbe Regel, AUSSCHLIESSLICH über die Events API
 *   (dispatch_tiktok_capi()).
 * - Google: Enhanced Conversions ist bei Google grundsätzlich nur
 *   browserseitig via gtag.js möglich (kein Server-Pfad, siehe
 *   PMS_Pro_Woo_Purchase-Doku) -- die gehashten Felder müssen also zur
 *   BROWSER-Pixel-Zeit vorliegen. Da SureCarts Confirmation-Kontext (anders
 *   als WooCommerces Danke-Seite) keine serverseitig vorgerenderte Stelle
 *   zum Einbetten bietet, holt pms-surecart.js sie bei Bedarf per EIGENEM,
 *   separat genonce'tem AJAX-Request (handle_purchase_matching_ajax()
 *   unten) nach, BEVOR es den gtag-Conversion-Aufruf feuert. Das verletzt
 *   NICHT das "nie rohe PII an den Browser"-Prinzip dieses Plugins: der
 *   Request liefert ausschließlich bereits SHA-256-gehashte Werte zurück
 *   (identisch zu dem, was PMS_CAPI::hash_email()/hash_field() ohnehin
 *   erzeugen) -- genau das Format, das Googles eigene gtag-Enhanced-
 *   Conversions-Doku für eine Browser-seitige Integration vorsieht.
 *
 * Dedup-Flag `_pms_sc_purchase_tracked` als Checkout-Metadata (SureCarts
 * natives, dokumentiertes Metadata-Feld -- \SureCart\Models\Checkout::update(
 * ['id' => ..., 'metadata' => [...]] ), siehe already_tracked()/mark_tracked()
 * unten) -- dasselbe "ein Flag pro Bestellung, plattformübergreifend"-Prinzip
 * wie PMS_Pro_Woo_Purchase::TRACKED_META_KEY.
 *
 * Deterministische Event-ID `pms_sc_order_{checkout_id}` (SureCarts
 * Checkout-ID, nicht die dünnere Order-ID -- der Checkout trägt die
 * Geldbeträge/Line-Items und ist über BEIDE Auslösewege hinweg identisch
 * auflösbar, siehe track_confirmed()/maybe_track_fallback() unten) --
 * dieselbe "eine Bestellung = eine feste ID"-Logik wie bei WooCommerce.
 *
 * UNVERIFIZIERT gegen ein echtes SureCart-Backend (dieselbe Einschränkung
 * wie bei class-pro-surecart-product-data.php/class-pro-surecart.php):
 * insbesondere der genaue Checkout::$status-Wert für "bezahlt" (angenommen:
 * "paid", analog zum dokumentierten Order::$status-Beispiel) und die Struktur
 * von Checkout::$billing_address (angenommen: Stripe-Adress-Schema mit
 * line1/city/state/postal_code/country, da SureCart auf Stripe aufbaut).
 * Vor Live-Verlass gegen ein echtes SureCart-Backend + die Diagnose-Tools von
 * Meta/Google/TikTok prüfen -- dieselbe Vorsicht wie beim historischen
 * test_event_code-Fund (siehe CLAUDE.md, „Test Event Code bleibt CAPI-only").
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_SureCart_Purchase {

	const TRACKED_META_KEY      = '_pms_sc_purchase_tracked';
	const MATCHING_NONCE_ACTION = 'pms_surecart_purchase_matching';
	const MAX_LEN_ID            = 128;

	public static function init() {
		if ( ! class_exists( 'SureCart' ) && ! function_exists( 'surecart' ) ) {
			return;
		}

		add_action( 'surecart/checkout_confirmed', array( __CLASS__, 'track_confirmed' ), 10, 2 );

		// Fallback: manche Zahlungswege lassen den Checkout ohne (sofortigen)
		// surecart/checkout_confirmed-Request bezahlt werden (z. B. asynchrone
		// Zahlungsmethoden, ein Admin, der eine Bestellung manuell auf
		// "bezahlt" setzt). $order->status wird innerhalb des Handlers
		// geprüft (siehe maybe_track_fallback()) -- die _pms_sc_purchase_tracked-
		// Prüfung macht beide Wege gegenseitig idempotent, unabhängig davon,
		// welcher zuerst feuert.
		add_action( 'surecart/order_updated', array( __CLASS__, 'maybe_track_fallback' ), 10, 2 );

		add_action( 'wp_ajax_' . self::MATCHING_NONCE_ACTION, array( __CLASS__, 'handle_purchase_matching_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::MATCHING_NONCE_ACTION, array( __CLASS__, 'handle_purchase_matching_ajax' ) );
	}

	/**
	 * Ist SureCart-Tracking (und damit auch Purchase-Tracking, kein eigener
	 * Master-Toggle, dieselbe Regel wie bei WooCommerce) konfiguriert und
	 * einsatzbereit?
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! PMS_Settings::is_pro() || ( ! class_exists( 'SureCart' ) && ! function_exists( 'surecart' ) ) ) {
			return false;
		}

		$settings = PMS_Settings::get();

		return ! empty( $settings['sc_tracking_enabled'] );
	}

	/**
	 * Darf für DIESEN Aufruf überhaupt getrackt werden? Bewusst ohne
	 * PMS_Frontend::is_active() -- surecart/order_updated kann aus einem
	 * Zahlungs-Webhook ganz ohne Seitenaufruf laufen, dasselbe Argument wie
	 * PMS_Pro_Woo_Purchase::should_process().
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
	 * Weg 1 (primär, siehe Klassen-Doku oben): surecart/checkout_confirmed.
	 *
	 * @param object            $checkout \SureCart\Models\Checkout.
	 * @param \WP_REST_Request|null $request  REST-Request, der den Checkout
	 *                                          finalisiert hat.
	 * @return void
	 */
	public static function track_confirmed( $checkout, $request = null ) {
		if ( ! self::should_process() || ! is_object( $checkout ) || empty( $checkout->id ) ) {
			return;
		}

		self::track_checkout( $checkout, $request );
	}

	/**
	 * Weg 2 (Fallback, siehe Klassen-Doku oben): surecart/order_updated.
	 * Löst den vollständigen Checkout über die (dünnere) Order-Referenz auf.
	 *
	 * @param object $order \SureCart\Models\Order.
	 * @param object $data  Rohe Event-Daten (ungenutzt, Signatur-Parität mit
	 *                       dem dokumentierten Hook).
	 * @return void
	 */
	public static function maybe_track_fallback( $order, $data = null ) {
		if ( ! self::should_process() || ! is_object( $order ) ) {
			return;
		}

		$status = isset( $order->status ) ? (string) $order->status : '';
		if ( 'paid' !== $status ) {
			return;
		}

		if ( empty( $order->checkout ) ) {
			return;
		}

		$checkout = is_object( $order->checkout )
			? $order->checkout
			: ( class_exists( 'PMS_Pro_SureCart' ) ? PMS_Pro_SureCart::fetch_checkout( (string) $order->checkout ) : null );

		if ( ! is_object( $checkout ) || empty( $checkout->id ) ) {
			return;
		}

		self::track_checkout( $checkout, null );
	}

	/**
	 * Gemeinsamer Kern beider Auslösewege: Dedup-Prüfung, custom_data
	 * aufbauen, an Meta CAPI + TikTok Events API senden, als getrackt
	 * markieren.
	 *
	 * @param object                 $checkout SureCart-Checkout.
	 * @param \WP_REST_Request|null $request  Optional, für die Ermittlung der event_source_url.
	 * @return void
	 */
	private static function track_checkout( $checkout, $request ) {
		if ( self::already_tracked( $checkout ) || ! class_exists( 'PMS_Pro_SureCart' ) ) {
			return;
		}

		$custom_data = PMS_Pro_SureCart::build_checkout_custom_data( $checkout, PMS_Settings::sc_purchase_value_type() );

		if ( empty( $custom_data ) ) {
			// Keine auflösbaren Positionen -- nichts Sinnvolles zu senden.
			// KEIN mark_tracked(): ein späterer Fallback-Aufruf (z. B. nach
			// einer vorübergehenden API-Störung) soll es erneut versuchen
			// dürfen, dieselbe Haltung wie bei einem fehlgeschlagenen
			// build_order_custom_data() bei WooCommerce.
			return;
		}

		$source_url = self::resolve_event_source_url( $request );

		self::dispatch_capi( $checkout, $custom_data, $source_url );
		self::dispatch_tiktok_capi( $checkout, $custom_data, $source_url );

		self::mark_tracked( $checkout );
	}

	/**
	 * Deterministische Event-ID -- identisch für beide Auslösewege UND für
	 * den Browser-Pixel-Aufruf aus assets/pms-surecart.js (siehe Klassen-Doku
	 * oben). Public, damit handle_purchase_matching_ajax() unten dieselbe
	 * Formel nutzt, ohne sie zu duplizieren.
	 *
	 * @param string $checkout_id SureCart-Checkout-ID.
	 * @return string
	 */
	public static function event_id( $checkout_id ) {
		return 'pms_sc_order_' . preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $checkout_id );
	}

	/**
	 * Bereits getrackt? Liest die Checkout-Metadata (siehe Klassen-Doku).
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return bool
	 */
	private static function already_tracked( $checkout ) {
		$meta = self::metadata_array( $checkout );

		return ! empty( $meta[ self::TRACKED_META_KEY ] );
	}

	/**
	 * Als getrackt markieren. \SureCart\Models\Checkout::update() erwartet
	 * laut SureCart-Dokumentation ein Array inkl. 'id' -- in try/catch
	 * gekapselt wie jeder andere Model-Zugriff dieser Integration (ein
	 * Fehler hier darf den Rest des Requests nie stören, insbesondere
	 * nicht den REST-Request, der surecart/checkout_confirmed auslöst).
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return void
	 */
	private static function mark_tracked( $checkout ) {
		if ( ! class_exists( '\SureCart\Models\Checkout' ) ) {
			return;
		}

		$meta                            = self::metadata_array( $checkout );
		$meta[ self::TRACKED_META_KEY ] = 1;

		try {
			\SureCart\Models\Checkout::update(
				array(
					'id'       => $checkout->id,
					'metadata' => $meta,
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- absichtlich stumm, siehe Methoden-Doku.
			// Konnte nicht als getrackt markiert werden -- im schlimmsten
			// Fall verarbeitet ein späterer Aufruf (anderer Auslöseweg)
			// dieselbe Bestellung erneut; Metas/TikToks eigene
			// Deduplizierung über die identische event_id fängt das
			// zweite Fenster ab (dieselbe zweite Sicherheitsebene wie bei
			// PMS_Pro_Woo_Purchase, siehe dortige Doku).
		}
	}

	/**
	 * Checkout::$metadata als assoziatives Array lesen -- das Feld kann laut
	 * JSON-Schema sowohl als leeres Objekt ("{}", von SureCarts SDK ggf. als
	 * stdClass dekodiert) als auch als assoziatives Array vorliegen.
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return array
	 */
	private static function metadata_array( $checkout ) {
		if ( empty( $checkout->metadata ) ) {
			return array();
		}

		return is_array( $checkout->metadata ) ? $checkout->metadata : (array) $checkout->metadata;
	}

	/**
	 * event_source_url ermitteln: aus dem REST-Request-Referer (Weg 1, die
	 * Seite, von der aus der Checkout finalisiert wurde), sonst die
	 * Startseite -- SureCart hat kein WooCommerce-Äquivalent zu
	 * get_checkout_order_received_url(), da die Bestätigungs-URL vom
	 * jeweiligen Formular abhängt (siehe Klassen-Doku oben).
	 *
	 * @param \WP_REST_Request|null $request REST-Request, falls vorhanden.
	 * @return string
	 */
	private static function resolve_event_source_url( $request ) {
		if ( $request instanceof WP_REST_Request ) {
			$referer = $request->get_header( 'referer' );
			if ( ! empty( $referer ) ) {
				$referer = esc_url_raw( (string) $referer );
				$host    = wp_parse_url( $referer, PHP_URL_HOST );
				$home    = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( $host && $home && strtolower( $host ) === strtolower( $home ) ) {
					return $referer;
				}
			}
		}

		return home_url( '/' );
	}

	/**
	 * Meta-CAPI-Versand. Das custom_data landet über den bereits von
	 * PMS_Pro_SureCart registrierten pms_capi_event_data-Filter im Payload
	 * (derselbe Schlüssel "pms_surecart_custom_data" wie dort -- eine
	 * zweite, identische Filter-Registrierung hier ist unnötig, dasselbe
	 * Muster wie PMS_Pro_Woo_Purchase::dispatch_capi()).
	 *
	 * @param object $checkout   SureCart-Checkout.
	 * @param array  $custom_data Von PMS_Pro_SureCart::build_checkout_custom_data().
	 * @param string $source_url  event_source_url.
	 * @return array Status-Eintrag von PMS_CAPI::send_events().
	 */
	private static function dispatch_capi( $checkout, array $custom_data, $source_url ) {
		$settings = PMS_Settings::get();

		$event = array(
			'id'                       => 'sc-purchase',
			'name'                     => 'Purchase',
			'event_type'               => 'Purchase',
			'event_id'                 => self::event_id( $checkout->id ),
			'meta_enabled'             => 1,
			'pms_surecart_custom_data' => $custom_data,
		);

		return PMS_CAPI::send_events(
			array( $event ),
			$settings,
			$source_url,
			self::build_meta_user_data( $checkout )
		);
	}

	/**
	 * Gehashte Meta-Advanced-Matching-Felder aus den Checkout-eigenen
	 * Kundendaten (email/first_name/last_name/phone/billing_address) --
	 * dasselbe Feld-Set (em/ph/fn/ln/ct/st/zp/country) und dieselbe
	 * Hash-Logik wie PMS_Pro_Woo_Purchase::build_order_user_data(), nur mit
	 * SureCarts eigenen Property-Namen statt WC_Order-Gettern. Gated hinter
	 * sc_purchase_advanced_matching (dasselbe Setting wie
	 * wc_purchase_advanced_matching auf Tab "E-Commerce", eine gemeinsame
	 * "sende gehashte Rechnungsdaten"-Checkbox pro Plattform-Integration).
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return array
	 */
	private static function build_meta_user_data( $checkout ) {
		$settings = PMS_Settings::get();

		if ( empty( $settings['sc_purchase_advanced_matching'] ) ) {
			return array();
		}

		$user_data = array();

		$email = PMS_CAPI::hash_email( $checkout->email ?? '' );
		if ( '' !== $email ) {
			$user_data['em'] = array( $email );
		}

		$phone = PMS_CAPI::hash_phone( $checkout->phone ?? '' );
		if ( '' !== $phone ) {
			$user_data['ph'] = array( $phone );
		}

		$address = self::billing_address( $checkout );

		foreach (
			array(
				'fn'      => $checkout->first_name ?? '',
				'ln'      => $checkout->last_name ?? '',
				'st'      => $address['state'],
				'country' => $address['country'],
			) as $key => $raw
		) {
			$hash = PMS_CAPI::hash_field( $raw );
			if ( '' !== $hash ) {
				$user_data[ $key ] = array( $hash );
			}
		}

		foreach (
			array(
				'ct' => $address['city'],
				'zp' => $address['postal_code'],
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
	 * Checkout::$billing_address in ein flaches Array normalisieren.
	 * Angenommenes Schema (Stripe-Adress-Objekt: line1/line2/city/state/
	 * postal_code/country) -- SureCart baut auf Stripe auf, das Feld ist
	 * laut REST-Beispiel nullable, das Unterschema selbst wurde nicht gegen
	 * echte Testdaten verifiziert (siehe Klassen-Doku oben).
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return array{line1:string,city:string,state:string,postal_code:string,country:string}
	 */
	private static function billing_address( $checkout ) {
		$defaults = array(
			'line1'       => '',
			'city'        => '',
			'state'       => '',
			'postal_code' => '',
			'country'     => '',
		);

		if ( empty( $checkout->billing_address ) || ! is_object( $checkout->billing_address ) ) {
			return $defaults;
		}

		$address = $checkout->billing_address;

		foreach ( $defaults as $key => $value ) {
			if ( isset( $address->$key ) ) {
				$defaults[ $key ] = (string) $address->$key;
			}
		}

		return $defaults;
	}

	/**
	 * TikTok Events API -- dasselbe Vorgehen wie
	 * PMS_Pro_Woo_Purchase::dispatch_tiktok_capi() (gleicher Endpoint,
	 * gleiches Gating: Pixel aktiv + Events-API-Toggle + Access Token,
	 * gleicher Consent-Fail-closed-Grundsatz, gleicher
	 * pms_tiktok_capi_blocking-Debug-Filter), nur mit SureCarts eigenen
	 * Feldnamen für die Bestell-/Kundendaten. Bewusst OHNE Event-Log-Eintrag,
	 * identische Begründung wie beim WooCommerce-Pendant (siehe dortige
	 * Doku und „Bekannte Trade-offs" in CLAUDE.md).
	 *
	 * @param object $checkout    SureCart-Checkout.
	 * @param array  $custom_data Von PMS_Pro_SureCart::build_checkout_custom_data().
	 * @param string $source_url  Seiten-URL für page.url.
	 * @return void
	 */
	private static function dispatch_tiktok_capi( $checkout, array $custom_data, $source_url ) {
		$settings = PMS_Settings::get();

		if ( empty( $settings['tiktok_enabled'] ) || empty( $settings['tiktok_pixel_id'] )
			|| empty( $settings['tiktok_capi_enabled'] ) || empty( $settings['tiktok_access_token'] ) ) {
			return;
		}

		// Server-Gate wie beim Meta-Pendant, siehe
		// PMS_Consent::has_server_consent() (respektiert seit v0.6.10 den
		// Consent-Modus).
		if ( class_exists( 'PMS_Consent' ) && ! PMS_Consent::has_server_consent() ) {
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

		if ( ! empty( $settings['sc_purchase_advanced_matching'] ) ) {
			$email = PMS_CAPI::hash_email( $checkout->email ?? '' );
			if ( '' !== $email ) {
				$user['email'] = $email;
			}
			$phone = PMS_CAPI::hash_phone( $checkout->phone ?? '' );
			if ( '' !== $phone ) {
				$user['phone'] = $phone;
			}
		}

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
					'event_id'   => self::event_id( $checkout->id ),
					'user'       => $user,
					'properties' => $properties,
					'page'       => array( 'url' => $source_url ),
				),
			),
		);

		// TikTok Test Event Code (seit v0.6.10): Pendant zu Metas
		// test_event_code (siehe PMS_CAPI::send_events()). Top-Level-Feld des
		// Events-API-Requests, NICHT Teil von data[] -- solange gesetzt,
		// erscheinen die Events in TikToks "Test Events"-Ansicht statt im
		// regulären Stream. Inklusive desselben 12h-Auto-Expiry wie bei Meta:
		// ein vergessener Code darf echte Käufe nicht dauerhaft aus den
		// Live-Berichten heraushalten (siehe PMS_Settings::expire_test_code()).
		$tiktok_test_code = PMS_Settings::active_tiktok_test_event_code( $settings );
		if ( '' !== $tiktok_test_code ) {
			$body['test_event_code'] = $tiktok_test_code;
		}

		/** Dokumentiert in class-pro-woo-purchase.php */
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

	/**
	 * AJAX: gehashte Google-Enhanced-Conversions-Felder für einen BEREITS
	 * BEZAHLTEN Checkout nachreichen (siehe Klassen-Doku oben für die
	 * ausführliche Begründung, warum das nicht serverseitig vorgerendert
	 * werden kann). Liefert ausschließlich SHA-256-Hashes zurück, niemals
	 * rohe PII -- dasselbe Prinzip wie jede andere AJAX-Antwort dieses
	 * Plugins.
	 *
	 * Verifiziert den Bezahlt-Status server-seitig SELBST neu (liest den
	 * Checkout frisch über die ID, statt der Client-Behauptung zu
	 * vertrauen) -- ein Aufrufer könnte sonst beliebige Checkout-IDs
	 * durchprobieren, um herauszufinden, ob/wann eine fremde Bestellung
	 * bezahlt wurde (Status-Leck), auch wenn die eigentliche Antwort selbst
	 * nur Hashes enthält.
	 *
	 * @return void
	 */
	public static function handle_purchase_matching_ajax() {
		check_ajax_referer( self::MATCHING_NONCE_ACTION, 'nonce' );

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'reason' => 'disabled' ), 200 );
		}

		$settings = PMS_Settings::get();

		if ( empty( $settings['sc_purchase_advanced_matching'] ) ) {
			wp_send_json_error( array( 'reason' => 'matching_disabled' ), 200 );
		}

		if ( ! PMS_Consent::has_marketing_consent() ) {
			wp_send_json_error( array( 'reason' => 'no_consent' ), 200 );
		}

		$checkout_id = substr( (string) wp_unslash( $_POST['checkout_id'] ?? '' ), 0, self::MAX_LEN_ID );
		$checkout_id = preg_replace( '/[^A-Za-z0-9\-_]/', '', $checkout_id );

		if ( '' === $checkout_id || ! class_exists( 'PMS_Pro_SureCart' ) ) {
			wp_send_json_error( array( 'reason' => 'missing_checkout' ), 400 );
		}

		$checkout = PMS_Pro_SureCart::fetch_checkout( $checkout_id );

		if ( ! is_object( $checkout ) || 'paid' !== (string) ( $checkout->status ?? '' ) ) {
			wp_send_json_error( array( 'reason' => 'not_paid' ), 200 );
		}

		wp_send_json_success( array( 'user_data' => self::build_google_user_data( $checkout ) ) );
	}

	/**
	 * Gehashte/strukturierte Bestelldaten für Google Enhanced Conversions
	 * (gtag.js `user_data`-Objekt) -- dasselbe Format wie
	 * PMS_Pro_Woo_Purchase::build_google_user_data(): email/phone_number
	 * sowie address[0].first_name/last_name/street sind Hash-Felder,
	 * address[0].city/region/postal_code/country bleiben laut Google-Doku
	 * Klartext.
	 *
	 * @param object $checkout SureCart-Checkout.
	 * @return array
	 */
	private static function build_google_user_data( $checkout ) {
		$user_data = array();

		$email = PMS_CAPI::hash_email( $checkout->email ?? '' );
		if ( '' !== $email ) {
			$user_data['email'] = $email;
		}

		$phone = self::hash_google_phone( $checkout->phone ?? '' );
		if ( '' !== $phone ) {
			$user_data['phone_number'] = $phone;
		}

		$raw_address = self::billing_address( $checkout );
		$address     = array();

		$first_name = PMS_CAPI::hash_field( $checkout->first_name ?? '' );
		if ( '' !== $first_name ) {
			$address['first_name'] = $first_name;
		}

		$last_name = PMS_CAPI::hash_field( $checkout->last_name ?? '' );
		if ( '' !== $last_name ) {
			$address['last_name'] = $last_name;
		}

		$street = PMS_CAPI::hash_field( $raw_address['line1'] );
		if ( '' !== $street ) {
			$address['street'] = $street;
		}

		if ( '' !== $raw_address['city'] ) {
			$address['city'] = sanitize_text_field( $raw_address['city'] );
		}
		if ( '' !== $raw_address['state'] ) {
			$address['region'] = sanitize_text_field( $raw_address['state'] );
		}
		if ( '' !== $raw_address['postal_code'] ) {
			$address['postal_code'] = sanitize_text_field( $raw_address['postal_code'] );
		}
		if ( '' !== $raw_address['country'] ) {
			$address['country'] = sanitize_text_field( $raw_address['country'] );
		}

		if ( ! empty( $address ) ) {
			$user_data['address'] = array( $address );
		}

		return $user_data;
	}

	/**
	 * Telefonnummer für Google Enhanced Conversions hashen: E.164-Format,
	 * identisch zu PMS_Pro_Woo_Purchase::hash_google_phone() (bewusst
	 * dupliziert statt sichtbar gemacht -- kleine, in diesem Projekt
	 * etablierte Duplikation zwischen zwei unabhängigen Plattform-
	 * Integrationen, siehe class-pro-surecart.php-Doku für dasselbe Prinzip).
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
}
