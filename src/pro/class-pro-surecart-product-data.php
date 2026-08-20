<?php
/**
 * Pro-Feature: SureCart-Datenextraktion für ViewContent/AddToCart/
 * InitiateCheckout (siehe class-pro-surecart.php für die eigentlichen Hooks).
 *
 * Reine, zustandslose Extraktionsschicht -- dasselbe Muster wie
 * PMS_Pro_Woo_Product_Data für WooCommerce. Nimmt ein SureCart-Produkt
 * (\SureCart\Models\Product, typischerweise über die Template-Funktion
 * sc_get_product() geholt) entgegen und liefert genau die Felder zurück, die
 * PMS_CAPI/pms-surecart.js für custom_data brauchen.
 *
 * WICHTIGER UNTERSCHIED zu WooCommerce: SureCarts Produkt-ID ist eine UUID
 * (z. B. "b5094a04-34f7-4ae9-a193-55a4b74cabb9"), NICHT identisch mit der
 * numerischen WordPress-Post-ID des zugehörigen "sc_product"-Custom-Post-Types
 * -- get_the_ID() auf einer Produktseite liefert die WP-Post-ID, niemals die
 * SureCart-eigene ID. sc_get_product( $post ) (SureCart-Template-Helper,
 * Pendant zu wc_get_product()) löst das korrekt auf und liefert ein Objekt mit
 * der echten SureCart-ID -- resolve_content_id() erwartet deshalb IMMER ein
 * bereits über sc_get_product()/Product::find() aufgelöstes Produkt-Objekt,
 * nie eine rohe Post-ID.
 *
 * Ebenfalls anders als WooCommerce: SureCarts REST-API liefert Geldbeträge in
 * Minor Units (Cent, z. B. total_amount=2900 für $29.00) -- resolve_price()
 * rechnet das per SC_CURRENCY_MINOR_UNIT (filterbar über
 * "pms_surecart_currency_minor_unit", Default 2) zurück in eine Dezimalzahl.
 * SureCarts REST-Referenz zeigt für Minor-Units keine explizite
 * Currency-Minor-Unit-Angabe wie WooCommerces Store API (siehe
 * class-pro-woo.php::build_cart_custom_data()) -- der Filter ist der
 * Sicherheitsnetz für Shops mit einer Zero-Decimal-Währung (z. B. JPY).
 *
 * UNVERIFIZIERT gegen ein echtes SureCart-Backend (siehe Klassen-Doku in
 * class-pro-surecart.php für die vollständige Einordnung): Die exakte Kette,
 * über die ein Product-Objekt seinen aktuellen Verkaufspreis liefert
 * (geprüft werden mehrere plausible Property-Pfade, siehe resolve_price()),
 * basiert auf offizieller SureCart-REST-API-Dokumentation
 * (developer.surecart.com), nicht auf einem Live-Test. Vor Produktiveinsatz
 * gegen eine echte SureCart-Produktseite prüfen.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_SureCart_Product_Data {

	/**
	 * Produktdaten für ein einzelnes SureCart-Produkt extrahieren.
	 *
	 * @param object $product SureCart-Produkt-Objekt (\SureCart\Models\Product,
	 *                         z. B. von sc_get_product()) oder ein bereits
	 *                         flaches stdClass/array-Objekt mit denselben
	 *                         Feldern (z. B. aus einer API-Antwort).
	 * @param int    $qty     Menge (nur durchgereicht, fließt NICHT in "value"
	 *                        ein -- dasselbe Prinzip wie
	 *                        PMS_Pro_Woo_Product_Data::get_product_data()).
	 * @param int    $post_id Optionale WP-Post-ID für die Kategorie-Taxonomie-
	 *                        Abfrage (siehe resolve_category()) -- nur auf der
	 *                        Produktseite selbst bekannt (get_the_ID()), im
	 *                        AJAX-Roundtrip (handle_track()) nicht verfügbar.
	 * @return array{content_id:string,content_name:string,content_category:string,value:float,currency:string,quantity:int} Leeres Array bei ungültigem Produkt.
	 */
	public static function get_product_data( $product, $qty = 1, $post_id = 0 ) {
		if ( ! is_object( $product ) || ! self::has_id( $product ) ) {
			return array();
		}

		return array(
			'content_id'       => self::resolve_content_id( $product ),
			'content_name'     => self::prop( $product, 'name', '' ),
			'content_category' => self::resolve_category( $product, $post_id ),
			'value'            => self::resolve_price( $product ),
			'currency'         => self::resolve_currency( $product ),
			'quantity'         => max( 1, absint( $qty ) ),
		);
	}

	/**
	 * content_id gemäß PMS_Settings::sc_content_id_type(): SKU, falls
	 * konfiguriert UND vorhanden (Product::$sku ist bei SureCart ein
	 * natives, optionales Feld -- anders als WooCommerce braucht es hier
	 * keinen Fallback auf eine geerbte Varianten-SKU, siehe Klassen-Doku),
	 * sonst immer die SureCart-Produkt-ID (UUID).
	 *
	 * Public (analog zu PMS_Pro_Woo_Product_Data::resolve_content_id()):
	 * class-pro-surecart-purchase.php braucht dieselbe Auflösung für
	 * Bestellpositionen.
	 *
	 * @param object $product SureCart-Produkt-Objekt.
	 * @return string
	 */
	public static function resolve_content_id( $product ) {
		if ( 'sku' === PMS_Settings::sc_content_id_type() ) {
			$sku = trim( (string) self::prop( $product, 'sku', '' ) );
			if ( '' !== $sku ) {
				return $sku;
			}
		}

		return (string) self::prop( $product, 'id', '' );
	}

	/**
	 * Existiert eine ID auf diesem Objekt?
	 *
	 * @param object $product Zu prüfendes Objekt.
	 * @return bool
	 */
	private static function has_id( $product ) {
		return '' !== trim( (string) self::prop( $product, 'id', '' ) );
	}

	/**
	 * Verkaufspreis in Dezimalform (nicht Minor Units). Prüft mehrere
	 * plausible Property-Pfade, da die exakte Struktur eines über
	 * sc_get_product() hydrierten Produkt-Objekts nicht gegen ein echtes
	 * Backend verifiziert werden konnte (siehe Klassen-Doku oben) --
	 * degradiert auf 0.0, wenn keiner davon einen Betrag liefert (dieselbe
	 * "lieber 0 als raten"-Haltung wie PMS_Pro_Woo_Product_Data bei
	 * fehlendem Preis).
	 *
	 * Geprüfte Pfade (in dieser Reihenfolge): $product->price (Objekt mit
	 * ->amount, falls die aufrufende Stelle die aktuell gewählte Price
	 * bereits mitgibt -- siehe PMS_Pro_SureCart::resolve_custom_data()),
	 * dann $product->prices->data[0]->amount bzw.
	 * $product->prices[0]->amount (Preis-Collection direkt am Produkt,
	 * SDK-Konvention laut PHP-Models-Doku), zuletzt
	 * $product->metrics->min_price_amount (Fallback -- laut REST-Schema
	 * immer vorhanden, aber ggf. 0/null bei einem Produkt ohne Preise).
	 *
	 * @param object $product SureCart-Produkt-Objekt.
	 * @return float
	 */
	private static function resolve_price( $product ) {
		$amount = self::first_price_amount( $product );

		if ( null === $amount ) {
			return 0.0;
		}

		return round( ( (float) $amount ) / self::currency_minor_unit(), 2 );
	}

	/**
	 * Ersten auffindbaren Minor-Unit-Preisbetrag über die in resolve_price()
	 * dokumentierten Pfade ermitteln.
	 *
	 * @param object $product SureCart-Produkt-Objekt.
	 * @return int|float|null Null, wenn kein Betrag gefunden wurde.
	 */
	private static function first_price_amount( $product ) {
		$price = self::prop( $product, 'price', null );
		if ( is_object( $price ) && null !== self::prop( $price, 'amount', null ) ) {
			return self::prop( $price, 'amount', null );
		}

		$prices = self::prop( $product, 'prices', null );
		$first  = self::first_of_collection( $prices );
		if ( is_object( $first ) && null !== self::prop( $first, 'amount', null ) ) {
			return self::prop( $first, 'amount', null );
		}

		$metrics = self::prop( $product, 'metrics', null );
		if ( is_object( $metrics ) && null !== self::prop( $metrics, 'min_price_amount', null ) ) {
			return self::prop( $metrics, 'min_price_amount', null );
		}

		return null;
	}

	/**
	 * Erstes Element einer möglichen "Collection" (Array, ->data-Array wie
	 * bei paginierten SureCart-API-Antworten, oder bereits ein einzelnes
	 * Objekt) ermitteln.
	 *
	 * @param mixed $value Zu prüfender Wert.
	 * @return object|null
	 */
	private static function first_of_collection( $value ) {
		if ( is_object( $value ) && isset( $value->data ) ) {
			$value = $value->data;
		}

		if ( is_array( $value ) ) {
			$first = reset( $value );
			return is_object( $first ) ? $first : null;
		}

		return is_object( $value ) ? $value : null;
	}

	/**
	 * Konfigurierbare Minor-Unit-Basis für die Preisumrechnung (siehe
	 * Klassen-Doku oben). Default 2 (Cent-Währungen, die überwältigende
	 * Mehrheit realer Shops) -- ein Zero-Decimal-Währungs-Shop (z. B. JPY)
	 * kann den Filter auf 0 setzen.
	 *
	 * @return int
	 */
	private static function currency_minor_unit() {
		/**
		 * Minor-Unit-Exponent für die SureCart-Preisumrechnung anpassen
		 * (10^n), z. B. auf 0 für eine Zero-Decimal-Währung wie JPY.
		 *
		 * @param int $exponent Default 2.
		 */
		$exponent = (int) apply_filters( 'pms_surecart_currency_minor_unit', 2 );
		$exponent = max( 0, min( 4, $exponent ) );

		return (int) pow( 10, $exponent );
	}

	/**
	 * Währungscode: über dieselbe Preis-Fallback-Kette wie
	 * first_price_amount() (embedded ->price, dann ->prices-Collection),
	 * sonst aus Product::$metrics->currency (laut REST-Schema immer
	 * vorhanden), sonst leer. SureCart liefert Währungscodes
	 * kleingeschrieben (z. B. "usd") -- Meta/Google/TikTok erwarten
	 * Großschreibung (ISO 4217), daher strtoupper().
	 *
	 * @param object $product SureCart-Produkt-Objekt.
	 * @return string
	 */
	private static function resolve_currency( $product ) {
		$price = self::prop( $product, 'price', null );
		if ( is_object( $price ) ) {
			$currency = self::prop( $price, 'currency', '' );
			if ( '' !== $currency ) {
				return strtoupper( (string) $currency );
			}
		}

		$first_from_collection = self::first_of_collection( self::prop( $product, 'prices', null ) );
		if ( is_object( $first_from_collection ) ) {
			$currency = self::prop( $first_from_collection, 'currency', '' );
			if ( '' !== $currency ) {
				return strtoupper( (string) $currency );
			}
		}

		$metrics = self::prop( $product, 'metrics', null );
		if ( is_object( $metrics ) ) {
			$currency = self::prop( $metrics, 'currency', '' );
			if ( '' !== $currency ) {
				return strtoupper( (string) $currency );
			}
		}

		return '';
	}

	/**
	 * Produktkategorie. SureCarts REST-Schema (siehe Klassen-Doku) kennt
	 * keine eingebettete Kategorie-Taxonomie am Product-Objekt selbst -- das
	 * "sc_product"-CPT unterstützt aber die reguläre WordPress-Taxonomie
	 * "sc_collection" (Produkt-Kollektionen), über get_the_terms() auf der
	 * WP-Post-ID auslesbar, sofern eine übergeben werden kann.
	 *
	 * Public aus demselben Grund wie resolve_content_id() oben.
	 *
	 * @param object $product SureCart-Produkt-Objekt.
	 * @param int    $post_id Optionale WP-Post-ID (get_the_ID()) für die
	 *                        Taxonomie-Abfrage -- das Product-Objekt selbst
	 *                        trägt keine zuverlässig nutzbare Post-ID-Referenz.
	 * @return string Leerer String, wenn keine Kategorie ermittelbar ist.
	 */
	public static function resolve_category( $product, $post_id = 0 ) {
		if ( $post_id <= 0 ) {
			return '';
		}

		$terms = get_the_terms( $post_id, 'sc_collection' );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return isset( $first->name ) ? (string) $first->name : '';
	}

	/**
	 * Property sowohl von stdClass-artigen Objekten als auch von Objekten
	 * mit magischen __get()-Zugriffen (SureCarts Models basieren laut
	 * PHP-Models-Doku auf einer Laravel-artigen Basisklasse mit __get())
	 * sicher lesen, ohne bei einem fehlenden Feld eine PHP-Notice
	 * auszulösen.
	 *
	 * @param object $object  Quellobjekt.
	 * @param string $key     Property-Name.
	 * @param mixed  $default Rückgabewert, falls nicht vorhanden/null.
	 * @return mixed
	 */
	private static function prop( $object, $key, $default ) {
		if ( ! is_object( $object ) ) {
			return $default;
		}

		if ( isset( $object->$key ) ) {
			return $object->$key;
		}

		return $default;
	}
}
