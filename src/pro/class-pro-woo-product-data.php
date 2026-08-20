<?php
/**
 * Pro-Feature: WooCommerce-Datenextraktion für ViewContent/AddToCart/
 * InitiateCheckout (siehe class-pro-woo.php für die eigentlichen Hooks).
 *
 * Reine, zustandslose Extraktionsschicht: nimmt ein WC_Product entgegen und
 * liefert genau die Felder zurück, die PMS_CAPI/pms-woocommerce.js für
 * custom_data brauchen. Keine Hooks, kein Request-Zugriff -- dadurch isoliert
 * testbar (siehe dev-tools/test-suite.php).
 *
 * "value" ist bewusst der EINZELPREIS (nicht mit quantity multipliziert):
 * Aufrufer, die einen Gesamtwert für eine bestimmte Menge brauchen (z. B.
 * AddToCart), multiplizieren selbst. Für einen Warenkorb mit mehreren
 * Positionen (InitiateCheckout) wird sonst derselbe Einzelpreis doppelt
 * skaliert, sobald er zusätzlich in ein contents[].item_price einfließt.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_Woo_Product_Data {

	/**
	 * Produktdaten für ein einzelnes WC_Product (Simple, Variable oder
	 * Variation) extrahieren.
	 *
	 * @param WC_Product $product Produkt- oder Variations-Objekt.
	 * @param int        $qty     Menge (nur durchgereicht, siehe Klassen-Doku
	 *                            oben -- fließt NICHT in "value" ein).
	 * @return array{content_id:string,content_name:string,content_category:string,value:float,currency:string,quantity:int} Leeres Array bei ungültigem Produkt.
	 */
	public static function get_product_data( $product, $qty = 1 ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		return array(
			'content_id'       => self::resolve_content_id( $product ),
			'content_name'     => (string) $product->get_name(),
			'content_category' => self::resolve_category( $product ),
			'value'            => self::resolve_price( $product ),
			'currency'         => (string) get_woocommerce_currency(),
			'quantity'         => max( 1, absint( $qty ) ),
		);
	}

	/**
	 * content_id gemäß PMS_Settings::wc_content_id_type(): SKU, falls
	 * konfiguriert UND vorhanden, sonst immer die Produkt-/Variations-ID.
	 *
	 * WC_Product::get_sku() liefert bei Variationen ohne eigene SKU bereits
	 * nativ die geerbte Parent-SKU zurück -- keine eigene Fallback-Logik nötig.
	 *
	 * Public (seit Purchase-Tracking, class-pro-woo-purchase.php): Bestell-
	 * Positionen brauchen dieselbe ID-Auflösung, aber NICHT den aktuellen
	 * Katalogpreis (siehe dortige Doku, warum Bestellungen den historischen
	 * Line-Item-Preis statt eines get_product_data()-Aufrufs verwenden).
	 *
	 * @param WC_Product $product Produkt.
	 * @return string
	 */
	public static function resolve_content_id( WC_Product $product ) {
		if ( 'sku' === PMS_Settings::wc_content_id_type() ) {
			$sku = trim( (string) $product->get_sku() );
			if ( '' !== $sku ) {
				return $sku;
			}
		}

		return (string) $product->get_id();
	}

	/**
	 * Anzeigepreis inkl./exkl. Steuer je nach Shop-Einstellung (native
	 * WooCommerce-Logik statt eigener Steuerberechnung).
	 *
	 * @param WC_Product $product Produkt.
	 * @return float
	 */
	private static function resolve_price( WC_Product $product ) {
		return (float) wc_get_price_to_display( $product );
	}

	/**
	 * Erste zugewiesene Produktkategorie ("primäre" Kategorie im Sinne dieses
	 * Plugins -- WooCommerce-Core kennt selbst kein Primary-Category-Konzept,
	 * anders als z. B. Yoast SEO). Bei Variationen zählt die Kategorie des
	 * Elternprodukts, da Kategorien nur dort zugewiesen werden.
	 *
	 * Public aus demselben Grund wie resolve_content_id() oben.
	 *
	 * @param WC_Product $product Produkt oder Variation.
	 * @return string Leerer String, wenn keine Kategorie zugewiesen ist.
	 */
	public static function resolve_category( WC_Product $product ) {
		$parent_id = $product->get_parent_id();
		$id        = $parent_id ? $parent_id : $product->get_id();
		$terms     = get_the_terms( $id, 'product_cat' );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return isset( $first->name ) ? (string) $first->name : '';
	}
}
