<?php
/**
 * Namespaced SureCart-Model-Stubs für dev-tools/test-suite.php.
 *
 * Ausgelagert in eine eigene Datei, weil eine echte PHP-Namespace-
 * Deklaration ("namespace SureCart\Models;") nicht mitten in eine
 * bestehende, komplett namespace-lose Datei eingemischt werden kann: Nutzt
 * eine Datei irgendwo eine geklammerte namespace-Deklaration, verlangt PHP,
 * dass JEDER Top-Level-Code in DERSELBEN Datei ebenfalls in eine
 * namespace-Deklaration eingeschlossen ist -- bei test-suite.php (2000+
 * Zeilen, durchgängig namespace-los) ein unnötiges Risiko, nur um eine
 * Handvoll Model-Klassen zu stubben. Diese Datei nutzt stattdessen die
 * einfache (ungeklammerte) "namespace X;"-Form, die für die GESAMTE Datei
 * gilt -- PHP-Namespaces sind dateiweise, ein require aus einer
 * namespace-losen Datei heraus funktioniert problemlos.
 *
 * Dieselbe Philosophie wie jeder andere Stub in diesem Harness (siehe
 * WC_Product/WC_Order in test-suite.php): nur die Getter/Methoden, die
 * pro/class-pro-surecart*.php tatsächlich aufruft, kein SQL-/HTTP-Layer.
 * Query-Ergebnisse kommen aus $GLOBALS['stub']['sc_*']-Fixtures, die die
 * jeweiligen Testabschnitte selbst befüllen.
 */

namespace SureCart\Models;

class Product {
	public $id;
	public $name = '';
	public $sku  = '';
	public $price;
	public $prices;
	public $metrics;

	public function __construct( array $data = array() ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}

	public static function find( $id ) {
		return isset( $GLOBALS['stub']['sc_products'][ $id ] ) ? $GLOBALS['stub']['sc_products'][ $id ] : null;
	}
}

class Price {
	public $id;
	public $amount   = 0;
	public $currency = '';
	public $product;

	public function __construct( array $data = array() ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}

	public static function find( $id ) {
		return isset( $GLOBALS['stub']['sc_prices'][ $id ] ) ? $GLOBALS['stub']['sc_prices'][ $id ] : null;
	}
}

class LineItem {
	public $id;
	public $quantity     = 1;
	public $price;
	public $total_amount = 0;
	public $checkout;

	public function __construct( array $data = array() ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}

	/**
	 * Nur die eine Filterform, die class-pro-surecart.php tatsächlich nutzt:
	 * where(['checkout' => $id])->get(). Kein allgemeiner Query-Builder.
	 *
	 * @param array $args Erwartet den Schlüssel 'checkout'.
	 * @return \Test_SC_LineItem_Query
	 */
	public static function where( $args ) {
		$checkout_id = isset( $args['checkout'] ) ? $args['checkout'] : '';
		$items       = array();

		foreach ( $GLOBALS['stub']['sc_line_items'] as $item ) {
			$item_checkout = is_object( $item->checkout ) ? $item->checkout->id : $item->checkout;
			if ( $item_checkout === $checkout_id ) {
				$items[] = $item;
			}
		}

		return new \Test_SC_LineItem_Query( $items );
	}
}

class Checkout {
	public $id;
	public $status          = 'draft';
	public $total_amount    = 0;
	public $tax_amount      = 0;
	public $subtotal_amount = 0;
	public $currency        = 'eur';
	public $email           = '';
	public $first_name      = '';
	public $last_name       = '';
	public $phone           = '';
	public $billing_address;
	public $metadata = array();

	public function __construct( array $data = array() ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}

	public static function find( $id ) {
		return isset( $GLOBALS['stub']['sc_checkouts'][ $id ] ) ? $GLOBALS['stub']['sc_checkouts'][ $id ] : null;
	}

	/**
	 * Nur die eine Form, die PMS_Pro_SureCart_Purchase::mark_tracked()
	 * tatsächlich nutzt: update(['id' => ..., 'metadata' => [...]]).
	 * Erfasst jeden Aufruf zusätzlich in captured_sc_updates fürs Dedup-Assert.
	 *
	 * @param array $args Muss 'id' enthalten.
	 * @return Checkout|null
	 */
	public static function update( array $args ) {
		$GLOBALS['stub']['captured_sc_updates'][] = $args;

		$id = isset( $args['id'] ) ? $args['id'] : '';
		if ( isset( $GLOBALS['stub']['sc_checkouts'][ $id ] ) && array_key_exists( 'metadata', $args ) ) {
			$GLOBALS['stub']['sc_checkouts'][ $id ]->metadata = $args['metadata'];
		}

		return isset( $GLOBALS['stub']['sc_checkouts'][ $id ] ) ? $GLOBALS['stub']['sc_checkouts'][ $id ] : null;
	}
}

class Order {
	public $id;
	public $status = 'pending';
	public $checkout;

	public function __construct( array $data = array() ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}
}
