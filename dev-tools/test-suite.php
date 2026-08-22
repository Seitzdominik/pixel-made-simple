<?php
/**
 * Funktionaler Test-Harness für Pixel Made Simple.
 * Stubbt die benötigten WordPress-Funktionen und testet die echte Plugin-Logik
 * per require – kein echtes WordPress nötig. Stand: v0.7.0, 648 Tests.
 *
 * Für die rein clientseitige Logik in assets/frontend.js (UTM-Form-Fill),
 * die hier nicht erreichbar ist, siehe das analoge Node-Pendant
 * dev-tools/test-frontend-js.js.
 *
 * Ausführen:  & "C:\php\php.exe" dev-tools\test-suite.php
 *
 * Hinweis zur Reihenfolge: PHP-Konstanten/Funktionen lassen sich nicht
 * "un-definieren". Tests ohne Banner-Plugin laufen daher zuerst, danach wird
 * CLI_VERSION definiert (Banner aktiv), ganz am Ende wp_has_consent().
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'PMS_VERSION', '0.5.6-test' );
define( 'PMS_PLUGIN_URL', 'https://example.com/wp-content/plugins/pixel-made-simple/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function check( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['t_fail']++;
		echo "FAIL  $label" . ( $detail !== '' ? "  ($detail)" : '' ) . "\n";
	}
}

/* ---------------------------------------------------------------------
 * WordPress-Stubs
 * ------------------------------------------------------------------- */

$GLOBALS['stub'] = array(
	'options'           => array(),
	'is_admin'          => false,
	'current_user_can'  => false,
	'is_user_logged_in' => false,
	'user_email'        => '',
	'is_ssl'            => true,
	'filters'           => array(),
	'captured_posts'    => array(),
	'wp_consent'        => true,
	'localized'         => array(),
	'enqueued_scripts'  => array(),
);

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['stub']['options'] ) ? $GLOBALS['stub']['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['stub']['options'][ $name ] = $value;
	return true;
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function trailingslashit( $str ) {
	return rtrim( (string) $str, '/\\' ) . '/';
}
function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
		mt_rand( 0, 0xffff ),
		mt_rand( 0, 0x0fff ) | 0x4000,
		mt_rand( 0, 0x3fff ) | 0x8000,
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}
// Signatur muss die echte apply_filters()-Signatur inkl. variadischer
// Zusatzargumente nachbilden (z. B. apply_filters('pms_capi_event_data',
// $payload, $event) in class-pms-capi.php) -- ein zu einfacher 2-Parameter-
// Stub hätte diesen Aufrufpfad nie über das echte Filter-System testbar
// gemacht (siehe dieselbe Lehre bei wp_json_encode() weiter oben).
function apply_filters( $tag, $value, ...$args ) {
	if ( isset( $GLOBALS['stub']['filters'][ $tag ] ) ) {
		return call_user_func( $GLOBALS['stub']['filters'][ $tag ], $value, ...$args );
	}
	return $value;
}
function add_action( $tag, $cb, $prio = 10 ) {}
// Erfasst jede Registrierung (statt nur die letzte zu behalten wie beim
// add_filter()-Stub oben) -- für Abschnitt 20 (Submenü-Registrierungen).
function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['stub']['registered_menu_pages'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return 'toplevel_page_' . $menu_slug;
}
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	$hook = $parent_slug . '_page_' . $menu_slug;
	$GLOBALS['stub']['registered_submenu_pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'hook' );
	return $hook;
}
function is_admin() { return $GLOBALS['stub']['is_admin']; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_preview() { return false; }
function is_customize_preview() { return false; }
function is_404() { return false; }
function current_user_can( $cap ) { return $GLOBALS['stub']['current_user_can']; }
function is_user_logged_in() { return $GLOBALS['stub']['is_user_logged_in']; }
function wp_get_current_user() {
	return (object) array( 'user_email' => $GLOBALS['stub']['user_email'] );
}
function is_ssl() { return $GLOBALS['stub']['is_ssl']; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function esc_url_raw( $url ) { return (string) $url; }
function esc_url( $url ) { return (string) $url; }
function esc_js( $text ) {
	return str_replace( array( '\\', "'", '"', "\n", "\r", '<', '>' ), array( '\\\\', "\\'", '\\"', '', '', '\\x3c', '\\x3e' ), (string) $text );
}
function wp_print_inline_script_tag( $js, $attrs = array() ) {
	$attr_str = '';
	foreach ( $attrs as $k => $v ) {
		$attr_str .= ' ' . $k . '="' . $v . '"';
	}
	echo '<script' . $attr_str . ">\n" . $js . "</script>\n";
}
// WICHTIG: Signatur muss die echte wp_json_encode()-Signatur inkl. $flags
// nachbilden. Ein zu einfacher Stub ohne $flags hätte den JSON_HEX_TAG-
// Security-Fix (v0.5.5) fälschlich als "getestet & bestanden" durchgewunken,
// weil die Flags einfach verschluckt wurden. Bei jedem neuen Stub prüfen,
// ob er auch wirklich alle Parameter durchreicht, die der Code nutzt.
function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
function is_wp_error( $thing ) { return false; }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }
// Seit v0.6.11 kann eine konkrete Antwort vorgegeben werden
// ($GLOBALS['stub']['http_response']) -- nötig für die TikTok-Events-API-
// Auswertung, die Erfolg/Fehler NICHT allein am HTTP-Status festmacht,
// sondern zusätzlich am "code"-Feld im JSON-Body (siehe Abschnitt 27b).
// Ohne Vorgabe unverändertes Verhalten: HTTP 200, leerer Body.
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['stub']['captured_posts'][] = array( 'url' => $url, 'args' => $args );

	if ( isset( $GLOBALS['stub']['http_response'] ) ) {
		return $GLOBALS['stub']['http_response'];
	}

	return array( 'response' => array( 'code' => 200 ) );
}
function is_email( $email ) { return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL ); }
function wp_strip_all_tags( $s, $breaks = false ) { return trim( strip_tags( (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 200; }
function wp_get_referer() { return 'https://example.com/referer/'; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['stub']['filters'][ $tag ] = $cb; }
function wp_enqueue_script( $handle = '', $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['stub']['enqueued_scripts'][] = $handle;
}
function wp_enqueue_style( $handle = '', $src = '', $deps = array(), $ver = false ) {}
function wp_script_add_data( $handle, $key, $value ) { return true; }
// Signatur muss wp_localize_script() real nachbilden (Handle, Objektname, Daten),
// sonst kann kein Test die lokalisierten JS-Settings (pms_settings) prüfen.
function wp_localize_script( $handle, $object_name, $data ) {
	$GLOBALS['stub']['localized'][ $handle ][ $object_name ] = $data;
}
function wp_create_nonce( $a = '' ) { return 'test-nonce'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function nocache_headers() {}
function esc_html__( $t, $d = null ) { return $t; }
function __( $t, $d = null ) { return $t; }
// Ab hier: Abschnitt 20 (register_menu()/render_page() erstmals über diesen
// Harness getestet statt nur über das separate dev-tools/preview-admin.php)
// -- dieselben Stub-Bodies wie dort (kein echtes Escaping/HTML nötig, die
// Tests hier prüfen nur String-Vorkommen, kein DOM).
function esc_html_e( $t, $d = null ) { echo $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_attr_e( $t, $d = null ) { echo $t; }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b || ( true === $b && ! empty( $a ) ) ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function selected( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function disabled( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b || ( true === $b && ! empty( $a ) ) ) ? ' disabled="disabled"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
	$f = '<input type="hidden" name="' . $n . '" value="test-nonce" />';
	if ( $e ) { echo $f; }
	return $f;
}
function submit_button( $text = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) {
	$btn = '<input type="submit" name="' . $name . '" class="button button-' . $type . '" value="' . $text . '" />';
	echo $wrap ? '<p class="submit">' . $btn . '</p>' : $btn;
}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '" />'; }
function esc_html( $t ) { return (string) $t; }
function esc_attr( $t ) { return (string) $t; }
function esc_textarea( $t ) { return (string) $t; }
function get_date_from_gmt( $gmt_date, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, strtotime( $gmt_date . ' UTC' ) ); }
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url );
		$url  = func_get_arg( 2 );
	}
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function current_time( $type, $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) {}
function wp_clear_scheduled_hook( $hook ) {}

/**
 * Minimaler $wpdb-Ersatz für PMS_Logger. Bewusst KEIN SQL-Parser: PMS_Logger
 * ist absichtlich so geschrieben, dass es bis auf TRUNCATE/DELETE ohne WHERE
 * ausschließlich strukturierte $wpdb-Methoden (insert/get_results/delete)
 * ohne dynamisches WHERE/JOIN nutzt (siehe dessen eigene Doku dazu) -- ein
 * Array pro "Tabelle" reicht deshalb aus, um sein Verhalten treu nachzubilden,
 * ohne echtes SQL zu interpretieren.
 */
class Test_PMS_Wpdb {
	public $prefix  = 'wp_';
	public $rows    = array();
	private $next_id = 1;

	public function get_charset_collate() {
		return '';
	}

	public function insert( $table, $data, $format = null ) {
		$data['id']            = $this->next_id++;
		$this->rows[ $table ][] = $data;
		return 1;
	}

	public function get_results( $query, $output = null ) {
		$table = $this->extract_table( $query );
		$rows  = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		return $rows;
	}

	public function delete( $table, $where, $format = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			return 0;
		}
		$before = count( $this->rows[ $table ] );
		$this->rows[ $table ] = array_values(
			array_filter(
				$this->rows[ $table ],
				static function ( $row ) use ( $where ) {
					foreach ( $where as $key => $value ) {
						if ( ( $row[ $key ] ?? null ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons -- bewusst locker, id kommt teils als string aus $_POST.
							return true; // behalten, WHERE trifft nicht zu.
						}
					}
					return false; // löschen, WHERE trifft zu.
				}
			)
		);
		return $before - count( $this->rows[ $table ] );
	}

	/**
	 * Letzte an query() übergebene SQL -- damit Tests prüfen können, dass ein
	 * Platzhalter tatsächlich durch prepare() ersetzt wurde (v0.7.0).
	 *
	 * @var string
	 */
	public $last_query = '';

	/**
	 * Minimaler prepare()-Ersatz: %s -> einfach gequoteter, escapter String,
	 * %d -> int. Genau das Verhalten, auf das sich
	 * PMS_Logger::cleanup_old_entries() seit v0.7.0 stützt -- mehr Syntax
	 * (Named-Placeholders, %f, %i) wird bewusst nicht nachgebaut.
	 */
	public function prepare( $query, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback(
			'/%[sd]/',
			static function ( $m ) use ( $args, &$i ) {
				$value = $args[ $i++ ] ?? '';
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$this->last_query = (string) $sql;

		$table = $this->extract_table( $sql );
		if ( null === $table ) {
			return false;
		}
		if ( false !== stripos( $sql, 'TRUNCATE' ) || ( false !== stripos( $sql, 'DELETE' ) && false === stripos( $sql, 'WHERE' ) ) ) {
			$this->rows[ $table ] = array();
			return true;
		}
		// Seit v0.7.0 die einzige dynamische WHERE-Form, die PMS_Logger
		// absetzt: DELETE ... WHERE created_at < '<GMT-Datum>' (siehe
		// cleanup_old_entries()). String-Vergleich reicht, weil created_at
		// durchgängig im sortierbaren Format Y-m-d H:i:s gespeichert wird.
		if ( preg_match( "/^DELETE FROM \\S+ WHERE created_at < '([^']+)'$/i", trim( $sql ), $m ) ) {
			$cutoff = $m[1];
			$before = count( $this->rows[ $table ] ?? array() );
			$this->rows[ $table ] = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $cutoff ) {
						return strcmp( (string) $row['created_at'], $cutoff ) >= 0;
					}
				)
			);
			return $before - count( $this->rows[ $table ] );
		}
		return false;
	}

	private function extract_table( $sql ) {
		if ( preg_match( '/\b(?:FROM|INTO|TABLE)\s+(\S+)/i', $sql, $m ) ) {
			return rtrim( $m[1], ';' );
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Test_PMS_Wpdb();

/* ---------------------------------------------------------------------
 * Minimaler WooCommerce-Ersatz für PMS_Pro_Woo_Product_Data. Bildet nur die
 * Getter/Funktionen nach, die die Klasse tatsächlich aufruft -- kein echtes
 * WooCommerce nötig (dieselbe Philosophie wie Test_PMS_Wpdb oben: kein
 * SQL-/DB-Layer, nur genug Oberfläche, um echten Plugin-Code zu bedienen).
 * "WooCommerce" ist bewusst eine leere Marker-Klasse (WooCommerce-Core
 * definiert selbst eine Klasse mit exakt diesem Namen) -- class_exists()-
 * Gates prüfen nur auf ihre Existenz. Sie wird unconditional deklariert;
 * es gibt daher (wie schon bei class_exists('PMS_Pro_UTM'), siehe Abschnitt
 * 18 unten) keinen automatisierten Test für eine Installation OHNE
 * WooCommerce -- derselbe bereits dokumentierte Trade-off.
 * ------------------------------------------------------------------- */

class WooCommerce {}

class WC_Product {
	protected $data;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'        => 0,
				'name'      => '',
				'sku'       => '',
				'parent_id' => 0,
			),
			$data
		);
	}

	public function get_id() {
		return (int) $this->data['id'];
	}

	public function get_name() {
		return (string) $this->data['name'];
	}

	public function get_sku() {
		return (string) $this->data['sku'];
	}

	public function get_parent_id() {
		return (int) $this->data['parent_id'];
	}
}

class WC_Product_Variation extends WC_Product {}

$GLOBALS['stub']['wc'] = array(
	'currency' => 'EUR',
	'prices'   => array(), // product_id => Preis (float).
	'terms'    => array(), // post_id => Array von (object) array('name' => ...).
);

function get_woocommerce_currency() {
	return $GLOBALS['stub']['wc']['currency'];
}
function wc_get_price_to_display( $product ) {
	$id = $product->get_id();
	return isset( $GLOBALS['stub']['wc']['prices'][ $id ] ) ? (float) $GLOBALS['stub']['wc']['prices'][ $id ] : 0.0;
}
function get_the_terms( $post_id, $taxonomy ) {
	return isset( $GLOBALS['stub']['wc']['terms'][ $post_id ] ) ? $GLOBALS['stub']['wc']['terms'][ $post_id ] : false;
}

/* ---------------------------------------------------------------------
 * Minimaler WC_Order/WC_Order_Item_Product-Ersatz für PMS_Pro_Woo_Purchase.
 * Dieselbe Philosophie wie WC_Product oben: nur die Getter, die die Klasse
 * tatsächlich aufruft. get_meta()/update_meta_data()/save() bilden WC_Order's
 * eigene, storage-agnostische Meta-API nach (siehe class-pro-woo-purchase.php,
 * warum PMS_Pro_Woo_Purchase bewusst NICHT get_post_meta()/update_post_meta()
 * nutzt -- HPOS-Sicherheit).
 * ------------------------------------------------------------------- */

class WC_Order_Item_Product {
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'quantity'   => 1,
				'total'      => 0.0,
				'product_id' => 0,
				'name'       => '',
				'product'    => null,
			),
			$data
		);
	}

	public function get_quantity() {
		return $this->data['quantity'];
	}
	public function get_total() {
		return $this->data['total'];
	}
	public function get_product_id() {
		return $this->data['product_id'];
	}
	public function get_name() {
		return $this->data['name'];
	}
	public function get_product() {
		return $this->data['product'];
	}
}

class WC_Order {
	private $data;
	private $meta = array();

	/** @var bool Wurde save() aufgerufen? Für Dedup-Tests. */
	public $saved = false;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'                 => 0,
				'items'              => array(),
				'total'              => 0.0,
				'total_tax'          => 0.0,
				'shipping_total'     => 0.0,
				'currency'           => 'EUR',
				'billing_email'      => '',
				'billing_phone'      => '',
				'billing_first_name' => '',
				'billing_last_name'  => '',
				'billing_address_1' => '',
				'billing_city'       => '',
				'billing_state'      => '',
				'billing_postcode'   => '',
				'billing_country'    => '',
			),
			$data
		);
	}

	public function get_id() {
		return $this->data['id'];
	}
	public function get_items() {
		return $this->data['items'];
	}
	public function get_total() {
		return $this->data['total'];
	}
	public function get_total_tax() {
		return $this->data['total_tax'];
	}
	public function get_shipping_total() {
		return $this->data['shipping_total'];
	}
	public function get_currency() {
		return $this->data['currency'];
	}
	public function get_checkout_order_received_url() {
		return 'https://example.com/checkout/order-received/' . $this->data['id'] . '/';
	}
	public function get_billing_email() {
		return $this->data['billing_email'];
	}
	public function get_billing_phone() {
		return $this->data['billing_phone'];
	}
	public function get_billing_first_name() {
		return $this->data['billing_first_name'];
	}
	public function get_billing_last_name() {
		return $this->data['billing_last_name'];
	}
	public function get_billing_address_1() {
		return $this->data['billing_address_1'];
	}
	public function get_billing_city() {
		return $this->data['billing_city'];
	}
	public function get_billing_state() {
		return $this->data['billing_state'];
	}
	public function get_billing_postcode() {
		return $this->data['billing_postcode'];
	}
	public function get_billing_country() {
		return $this->data['billing_country'];
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		$this->saved = true;
	}
}

$GLOBALS['stub']['wc_orders'] = array(); // id => WC_Order.

function wc_get_order( $id ) {
	return isset( $GLOBALS['stub']['wc_orders'][ $id ] ) ? $GLOBALS['stub']['wc_orders'][ $id ] : false;
}

/* ---------------------------------------------------------------------
 * Minimaler SureCart-Ersatz für PMS_Pro_SureCart(_Product_Data|_Purchase).
 * Die eigentlichen Model-Klassen (\SureCart\Models\Product/Price/LineItem/
 * Checkout/Order) leben in einer separaten Datei (stub-surecart-models.php,
 * dort per require weiter unten geladen) -- eine echte PHP-namespace-
 * Deklaration lässt sich nicht mitten in diese namespace-lose Datei
 * einmischen, siehe dortiger Datei-Kommentar. "SureCart" ist -- wie
 * "WooCommerce" oben -- eine leere Marker-Klasse, unconditional deklariert;
 * es gibt deshalb (derselbe bereits dokumentierte Trade-off) keinen
 * automatisierten Test für eine Installation OHNE SureCart.
 * ------------------------------------------------------------------- */

class Test_SC_LineItem_Query {
	private $items;

	public function __construct( array $items ) {
		$this->items = $items;
	}

	public function get() {
		return $this->items;
	}
}

class SureCart {}

class WP_Post {
	public $ID;
	public $post_content;

	public function __construct( $id, $content = '' ) {
		$this->ID           = $id;
		$this->post_content = $content;
	}
}

class WP_REST_Request {
	private $headers;

	public function __construct( array $headers = array() ) {
		$this->headers = $headers;
	}

	public function get_header( $name ) {
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}
}

function sc_get_product( $post_id ) {
	return isset( $GLOBALS['stub']['sc_product_by_post'][ $post_id ] ) ? $GLOBALS['stub']['sc_product_by_post'][ $post_id ] : null;
}

$GLOBALS['stub']['surecart'] = array(
	'is_singular'       => false,
	'queried_post_type' => '',
	'queried_object_id' => 0,
);
$GLOBALS['stub']['posts']                = array(); // id => WP_Post.
$GLOBALS['stub']['post_meta']            = array(); // id => array( key => value ).
$GLOBALS['stub']['sc_products']          = array(); // id => \SureCart\Models\Product.
$GLOBALS['stub']['sc_prices']            = array(); // id => \SureCart\Models\Price.
$GLOBALS['stub']['sc_checkouts']         = array(); // id => \SureCart\Models\Checkout.
$GLOBALS['stub']['sc_line_items']        = array(); // flache Liste, gefiltert über LineItem::where().
$GLOBALS['stub']['sc_product_by_post']   = array(); // WP-Post-ID => \SureCart\Models\Product (sc_get_product()).
$GLOBALS['stub']['captured_sc_updates']  = array(); // jeder Checkout::update()-Aufruf, fürs Dedup-Assert.

function is_singular( $post_types = '' ) {
	if ( ! $GLOBALS['stub']['surecart']['is_singular'] ) {
		return false;
	}
	if ( '' === $post_types || empty( $post_types ) ) {
		return true;
	}
	return in_array( $GLOBALS['stub']['surecart']['queried_post_type'], (array) $post_types, true );
}
function get_queried_object_id() {
	return (int) $GLOBALS['stub']['surecart']['queried_object_id'];
}
function get_post( $post_id = null ) {
	if ( null === $post_id ) {
		$post_id = $GLOBALS['stub']['surecart']['queried_object_id'];
	}
	return isset( $GLOBALS['stub']['posts'][ $post_id ] ) ? $GLOBALS['stub']['posts'][ $post_id ] : null;
}
function has_block( $block_name, $post = null ) {
	$content = ( is_object( $post ) && isset( $post->post_content ) ) ? $post->post_content : '';
	return false !== strpos( $content, '<!-- wp:' . $block_name );
}
function has_shortcode( $content, $tag ) {
	return false !== strpos( (string) $content, '[' . $tag );
}
function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['stub']['post_meta'][ $post_id ][ $key ] ) ? $GLOBALS['stub']['post_meta'][ $post_id ][ $key ] : '';
}

/**
 * Alle SureCart-Fixtures zwischen Testabschnitten zurücksetzen -- dasselbe
 * Muster wie wc_test_reset() oben.
 *
 * @return void
 */
function sc_test_reset() {
	$GLOBALS['stub']['surecart'] = array(
		'is_singular'       => false,
		'queried_post_type' => '',
		'queried_object_id' => 0,
	);
	$GLOBALS['stub']['posts']               = array();
	$GLOBALS['stub']['post_meta']           = array();
	$GLOBALS['stub']['sc_products']         = array();
	$GLOBALS['stub']['sc_prices']           = array();
	$GLOBALS['stub']['sc_checkouts']        = array();
	$GLOBALS['stub']['sc_line_items']       = array();
	$GLOBALS['stub']['sc_product_by_post']  = array();
	$GLOBALS['stub']['captured_sc_updates'] = array();
}

/* ---------------------------------------------------------------------
 * Plugin-Klassen laden (echter Code, kein Mock)
 * ------------------------------------------------------------------- */

$base = __DIR__ . '/../src/includes/';
require $base . 'class-pms-settings.php';
require $base . 'class-pms-logger.php';
require $base . 'class-pms-consent.php';
require $base . 'class-pms-capi.php';
require $base . 'class-pms-frontend.php';
require $base . 'class-pms-forms.php';
require $base . 'class-pms-debug.php';
require $base . 'class-pms-tools.php';
require_once $base . 'class-pms-admin.php';
// Seit Abschnitt 20 (Sidebar-Shortcut "Event Log", seit v0.6.4) auch hier
// geladen -- vorher rein über PMS_Logger direkt getestet, nie über die
// Admin-Tab-Rendering-Schicht selbst.
require_once $base . 'admin/class-pms-admin-event-log.php';

// PMS_Pro_UTM lebt seit v0.6.0 in pro/ (nur von pixel-made-simple-pro.php
// geladen), nicht mehr in includes/. Hier trotzdem unconditional geladen,
// damit alle bestehenden Attribution-/UTM-Form-Fill-Tests unverändert gegen
// echten Code laufen -- die Klasse selbst prüft PMS_IS_PRO nirgends, das
// Free/Pro-Gating passiert ausschließlich über require/class_exists() in den
// Bootstrap-Dateien bzw. in PMS_CAPI/PMS_Debug/PMS_Frontend. Siehe Abschnitt
// 16 weiter unten für die eigenständigen Tests des Gatings selbst
// (PMS_Settings::is_pro() / free_event_limit_reached()).
require __DIR__ . '/../src/pro/class-pro-utm.php';

// PMS_Pro_TikTok_CAPI (seit v0.6.11): gemeinsamer Versand + Protokollierung
// der TikTok Events API, von BEIDEN Purchase-Klassen genutzt -- muss deshalb
// vor ihnen geladen sein.
require __DIR__ . '/../src/pro/class-pro-tiktok-capi.php';

// PMS_Pro_Woo_Product_Data/PMS_Pro_WooCommerce: dasselbe Muster wie
// PMS_Pro_UTM oben -- unconditional geladen, obwohl beide Klassen im echten
// Betrieb nur von pixel-made-simple-pro.php require't werden. Siehe
// Abschnitt 18 weiter unten für die eigenständigen Tests.
require __DIR__ . '/../src/pro/class-pro-woo-product-data.php';
require __DIR__ . '/../src/pro/class-pro-woo.php';
require __DIR__ . '/../src/pro/class-pro-woo-purchase.php';

// PMS_Pro_SureCart_Product_Data/PMS_Pro_SureCart/PMS_Pro_SureCart_Purchase:
// dasselbe unconditional-Lade-Muster wie die WooCommerce-Klassen oben.
// Die namespaced \SureCart\Models\*-Stubs müssen VOR diesen drei Dateien
// geladen sein, da class-pro-surecart(-purchase).php sie per
// class_exists('\SureCart\Models\...') zur Laufzeit referenzieren (rein
// lazy, aber Test_SC_LineItem_Query -- von LineItem::where() referenziert --
// ist oben bereits deklariert, bevor stub-surecart-models.php hier geladen
// wird).
require __DIR__ . '/stub-surecart-models.php';
require __DIR__ . '/../src/pro/class-pro-surecart-product-data.php';
require __DIR__ . '/../src/pro/class-pro-surecart.php';
require __DIR__ . '/../src/pro/class-pro-surecart-purchase.php';

/* ---------------------------------------------------------------------
 * Test-Helfer (Reflection für private Properties/Methods)
 * ------------------------------------------------------------------- */

function reset_attribution() {
	$p = new ReflectionProperty( 'PMS_Pro_UTM', 'cache' );
	$p->setValue( null, null );
	unset( $_COOKIE['pms_attribution'] );
	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid' ) as $k ) {
		unset( $_GET[ $k ] );
	}
}
function call_private( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	return $m->invoke( null, ...$args );
}
function set_private( $class, $prop, $value ) {
	$p = new ReflectionProperty( $class, $prop );
	$p->setValue( null, $value );
}
function reset_consent_cache() {
	set_private( 'PMS_Consent', 'cache', null );
}
function consent_check() {
	reset_consent_cache();
	return PMS_Consent::has_marketing_consent();
}
function reset_frontend() {
	set_private( 'PMS_Frontend', 'matched_events', array() );
	set_private( 'PMS_Frontend', 'active', false );
	set_private( 'PMS_Frontend', 'settings', array() );
	reset_consent_cache();
	$GLOBALS['stub']['captured_posts']   = array();
	$GLOBALS['stub']['localized']        = array();
	$GLOBALS['stub']['enqueued_scripts'] = array();
}
function run_frontend() {
	reset_frontend();
	PMS_Frontend::prepare();
	ob_start();
	PMS_Frontend::print_scripts();
	return ob_get_clean();
}
/**
 * prepare() + enqueue_frontend() ausführen und die an pms-frontend
 * lokalisierten JS-Settings zurückgeben (null, wenn das Skript in diesem
 * Szenario gar nicht erst enqueued wurde).
 */
function run_enqueue() {
	reset_frontend();
	PMS_Frontend::prepare();
	PMS_Frontend::enqueue_frontend();
	return $GLOBALS['stub']['localized']['pms-frontend']['pms_settings'] ?? null;
}
function clear_consent_cookies() {
	foreach ( array_keys( $_COOKIE ) as $k ) {
		if ( '_fbp' !== $k && '_fbc' !== $k ) {
			unset( $_COOKIE[ $k ] );
		}
	}
}

echo "=== 1. sanitize_settings: Neue Felder & Test-Code-Zeitstempel ===\n";

$GLOBALS['stub']['options'] = array();
$out = PMS_Settings::sanitize_settings( array( 'test_event_code' => 'TEST999' ) );
check( 'Neuer Test-Code setzt Zeitstempel', abs( time() - $out['test_code_created_at'] ) < 5 );

$GLOBALS['stub']['options']['pms_settings'] = array( 'test_event_code' => 'TEST999', 'test_code_created_at' => 12345 );
$out = PMS_Settings::sanitize_settings( array( 'test_event_code' => 'TEST999' ) );
check( 'Unveränderter Test-Code behält Zeitstempel', 12345 === $out['test_code_created_at'] );

$out = PMS_Settings::sanitize_settings( array( 'test_event_code' => 'NEU111' ) );
check( 'Geänderter Test-Code erneuert Zeitstempel', abs( time() - $out['test_code_created_at'] ) < 5 );

$out = PMS_Settings::sanitize_settings( array( 'test_event_code' => '' ) );
check( 'Leerer Test-Code setzt Zeitstempel auf 0', 0 === $out['test_code_created_at'] );

$defaults = PMS_Settings::get();
check( 'Default: Consent-Erkennung ist AKTIV (Neuinstallation)', 1 === $defaults['consent_detection'] );
check( 'Default (Privacy-by-Default): Formular-Tracking ist INAKTIV', 0 === $defaults['form_tracking'] );
check( 'Default (Privacy-by-Default): UTM-Passthrough ist INAKTIV', 0 === $defaults['utm_passthrough'] );
check( 'Default: Live-Debug-Leiste ist AKTIV (nur für Admins wirksam)', 1 === $defaults['debug_bar'] );
check( 'Default (Privacy-by-Default, v0.5.6): UTM-Form-Fill ist INAKTIV', 0 === $defaults['enable_utm_form_fill'] );
check( 'Default: utm_form_fill_mode ist "all"', 'all' === $defaults['utm_form_fill_mode'] );
check( 'Default: utm_form_fill_urls ist leer', '' === $defaults['utm_form_fill_urls'] );

$fresh = PMS_Settings::sanitize_settings( array() );
check( 'sanitize_settings: form_tracking ohne Input -> 0', 0 === $fresh['form_tracking'] );
check( 'sanitize_settings: utm_passthrough ohne Input -> 0', 0 === $fresh['utm_passthrough'] );
check( 'sanitize_settings: enable_utm_form_fill ohne Input -> 0', 0 === $fresh['enable_utm_form_fill'] );
check( 'sanitize_settings: utm_form_fill_mode ohne Input -> "all"', 'all' === $fresh['utm_form_fill_mode'] );

$clean_fill = PMS_Settings::sanitize_settings(
	array(
		'enable_utm_form_fill' => '1',
		'utm_form_fill_mode'   => 'include',
		'utm_form_fill_urls'   => " /Kontakt \r\n /LP/*  \n/kontakt\n\n",
	)
);
check( 'sanitize_settings: enable_utm_form_fill übernommen', 1 === $clean_fill['enable_utm_form_fill'] );
check( 'sanitize_settings: utm_form_fill_mode "include" übernommen', 'include' === $clean_fill['utm_form_fill_mode'] );
check(
	'sanitize_settings: utm_form_fill_urls zeilenbasiert normalisiert (klein, getrimmt, dedupliziert, Wildcard bleibt)',
	"/kontakt\n/lp/*" === $clean_fill['utm_form_fill_urls'],
	$clean_fill['utm_form_fill_urls']
);

$clean_fill2 = PMS_Settings::sanitize_settings( array( 'utm_form_fill_mode' => 'unbekannt' ) );
check( 'sanitize_settings: unbekannter utm_form_fill_mode fällt auf "all" zurück', 'all' === $clean_fill2['utm_form_fill_mode'] );

check( 'sanitize_url_patterns: Markup wird entfernt', false === strpos( PMS_Settings::sanitize_url_patterns( '/x<script>alert(1)</script>' ), '<' ) );

echo "\n=== 1b. CAPI-Token: Erhalt bei Speicherungen ohne eigenes Feld (Bugfix v0.5.6) ===\n";

$GLOBALS['stub']['options']['pms_settings'] = array( 'capi_token' => 'BESTEHENDER-TOKEN' );
$out_no_key                                   = PMS_Settings::sanitize_settings( array( 'form_tracking' => '1' ) );
check( 'capi_token bleibt erhalten, wenn der Schlüssel im Input fehlt (z. B. Tab "Erweitertes Tracking")', 'BESTEHENDER-TOKEN' === $out_no_key['capi_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array( 'capi_token' => 'BESTEHENDER-TOKEN' );
$out_cleared                                  = PMS_Settings::sanitize_settings( array( 'capi_token' => '' ) );
check( 'capi_token wird geleert, wenn explizit ein Leerstring übergeben wird (Tab "Allgemein")', '' === $out_cleared['capi_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array( 'capi_token' => 'ALT' );
$out_new                                      = PMS_Settings::sanitize_settings( array( 'capi_token' => 'NEUER-TOKEN' ) );
check( 'capi_token wird überschrieben, wenn ein neuer Wert übergeben wird', 'NEUER-TOKEN' === $out_new['capi_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 2. Consent-Erkennung: Cookie-Muster (kein Banner-Plugin aktiv) ===\n";

$GLOBALS['stub']['options']['pms_settings'] = array( 'consent_detection' => 0 );
check( 'Erkennung deaktiviert -> Consent true', true === consent_check() );

$GLOBALS['stub']['options']['pms_settings'] = array( 'consent_detection' => 1 );
clear_consent_cookies();
check( 'Fallback: kein Banner erkannt -> Consent true', true === consent_check() );

$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'yes';
check( 'CLI/MHP: advertisement=yes -> true', true === consent_check() );
$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'no';
check( 'CLI/MHP: advertisement=no -> false', false === consent_check() );
clear_consent_cookies();

// Realer Cookie-Wert aus dem Live-Test ("Alle akzeptieren"), unverändert übernommen.
$_COOKIE['mhcookie'] = 'eyJncm91cHMiOlsiYWxsIl0sInNlcnZpY2VzIjp7ImNvb2tpZSI6W10sImRvbWFpbiI6W10sInJlc291cmNlIjpbXX0sImlhYl92ZW5kb3JzIjpbImFsbCJdLCJleHBpcnkiOjE4MTg0ODIxMjIsInRpbWVzdGFtcCI6MTc4Njk0NjAzMH0=';
check( 'MHP mhcookie: realer "Alle akzeptieren"-Cookie -> true', true === consent_check() );

$_COOKIE['mhcookie'] = base64_encode( '{"groups":["all"],"services":{"cookie":[],"domain":[],"resource":[]},"iab_vendors":["all"]}' );
check( 'MHP mhcookie: groups=["all"] -> true', true === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( '{"groups":["marketing"]}' );
check( 'MHP mhcookie: groups=["marketing"] -> true', true === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( '{"groups":["advertisement"]}' );
check( 'MHP mhcookie: groups=["advertisement"] -> true', true === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( '{"groups":["essential"]}' );
check( 'MHP mhcookie: groups=["essential"] (nur erforderlich) -> false', false === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( '{"groups":[]}' );
check( 'MHP mhcookie: groups=[] (leer) -> false', false === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( '{"services":{}}' );
check( 'MHP mhcookie: kein groups-Feld -> false', false === consent_check() );
$_COOKIE['mhcookie'] = '!!!kein-base64!!!';
check( 'MHP mhcookie: ungültiges Base64 -> false', false === consent_check() );
$_COOKIE['mhcookie'] = base64_encode( 'kein json' );
check( 'MHP mhcookie: ungültiges JSON -> false', false === consent_check() );
clear_consent_cookies();

echo "\n=== 2b. Security-Audit v0.5.5: Cookie-Längenbegrenzung vor JSON-Dekodierung ===\n";

$_COOKIE['mhcookie'] = base64_encode( '{"groups":["all"],"pad":"' . str_repeat( 'x', 9000 ) . '"}' );
check( 'MHP mhcookie: >8KB wird ungeparst verworfen (fail-closed)', false === consent_check() );
clear_consent_cookies();

$_COOKIE['mhcookie'] = base64_encode( '{"groups":["all"]}' );
check( 'MHP mhcookie: normal große Cookies funktionieren weiterhin', true === consent_check() );
clear_consent_cookies();

$_COOKIE['borlabs-cookie'] = rawurlencode( '{"consents":{"marketing":["x"],"pad":"' . str_repeat( 'y', 9000 ) . '"}}' );
check( 'Borlabs: >8KB wird ungeparst verworfen (fail-closed)', false === consent_check() );
clear_consent_cookies();

$_COOKIE['cookielawinfo-checkbox-marketing'] = 'yes';
check( 'CLI: marketing-Kategorie=yes -> true', true === consent_check() );
$_COOKIE['cookielawinfo-checkbox-marketing'] = 'no';
check( 'CLI: marketing-Kategorie=no -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['viewed_cookie_policy'] = 'yes';
check( 'BUGFIX 1: viewed_cookie_policy=yes ALLEIN -> false ("Nur erforderliche")', false === consent_check() );
$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'yes';
check( 'BUGFIX 1: viewed=yes + advertisement=yes -> true', true === consent_check() );
$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'no';
check( 'BUGFIX 1: viewed=yes + advertisement=no -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['cookieyes-consent'] = 'consent:yes,analytics:no,advertisement:yes';
check( 'CookieYes: advertisement:yes -> true', true === consent_check() );
$_COOKIE['cookieyes-consent'] = 'consent:yes,analytics:no,advertisement:no';
check( 'CookieYes: advertisement:no -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['borlabs-cookie'] = rawurlencode( '{"consents":{"essential":["borlabs-cookie"],"marketing":["facebook-pixel"]}}' );
check( 'Borlabs: consents.marketing befüllt -> true', true === consent_check() );
$_COOKIE['borlabs-cookie'] = rawurlencode( '{"consents":{"essential":["borlabs-cookie"]}}' );
check( 'Borlabs: kein marketing-Consent -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['cmplz_marketing'] = 'allow';
check( 'Complianz: cmplz_marketing=allow -> true', true === consent_check() );
$_COOKIE['cmplz_marketing'] = 'deny';
check( 'Complianz: cmplz_marketing=deny -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['complianz_consent_status'] = 'allow';
check( 'Complianz (alt): consent_status=allow -> true', true === consent_check() );
clear_consent_cookies();

$_COOKIE['CookieConsent'] = rawurlencode( '{stamp:xyz,necessary:true,preferences:false,statistics:false,marketing:true}' );
check( 'Cookiebot: marketing:true -> true', true === consent_check() );
$_COOKIE['CookieConsent'] = rawurlencode( '{stamp:xyz,necessary:true,marketing:false}' );
check( 'Cookiebot: marketing:false -> false', false === consent_check() );
clear_consent_cookies();

$_COOKIE['surecookies_consent'] = rawurlencode( '{"necessary":true,"marketing":true}' );
check( 'SureCookies: marketing true -> true', true === consent_check() );
clear_consent_cookies();

$_COOKIE['real_cookie_banner-1'] = 'consent-data';
check( 'Real Cookie Banner: Cookie vorhanden -> true', true === consent_check() );
clear_consent_cookies();

$GLOBALS['stub']['filters']['pms_has_marketing_consent'] = function () { return false; };
$_COOKIE['cmplz_marketing'] = 'allow';
check( 'Filter pms_has_marketing_consent überschreibt', false === consent_check() );
unset( $GLOBALS['stub']['filters']['pms_has_marketing_consent'] );
clear_consent_cookies();

echo "\n=== 3. Basis: Matching & Gating (Consent-Erkennung aus) ===\n";

$base_settings = array(
	'pixel_enabled' => 1, 'pixel_id' => '123456789012345', 'capi_enabled' => 1,
	'capi_token' => 'EAAtesttoken', 'test_event_code' => 'TEST123', 'test_code_created_at' => time(),
	'exclude_admins' => 1, 'hash_email' => 0, 'consent_detection' => 0,
	'google_enabled' => 1, 'google_tag_id' => 'AW-123456789', 'google_consent_mode' => 1,
	'tiktok_enabled' => 1, 'tiktok_pixel_id' => 'C123ABC456',
	'ga4_measurement_id' => 'G-ABC1234XYZ',
);
$GLOBALS['stub']['options']['pms_settings'] = $base_settings;
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'ev1', 'name' => 'Lead Danke', 'event_type' => 'Lead', 'match_type' => 'exact', 'match_value' => '/bestaetigung/', 'active' => 1,
		'meta_enabled' => 1, 'google_enabled' => 1, 'google_label' => 'AbCdEf123', 'tiktok_enabled' => 1, 'tiktok_event' => 'SubmitForm',
	),
);

$_SERVER['REQUEST_URI']     = '/bestaetigung/?fbclid=AbC123xyz_-';
$_SERVER['HTTP_HOST']       = 'example.com';
$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test)';
$_COOKIE['_fbp']            = 'fb.1.1700000000000.1234567890';
unset( $_COOKIE['_fbc'] );
$_GET['fbclid']             = 'AbC123xyz_-';

$html = run_frontend();

check( 'Meta: init + PageView + Lead direkt ausgegeben', false !== strpos( $html, "fbq('init','123456789012345')" ) && false !== strpos( $html, "fbq('track','PageView')" ) && false !== strpos( $html, "fbq('track','Lead'" ) );
check( 'Korrektur v0.5.7: KEIN test_event_code im PageView-Aufruf ans Browser-Pixel (Meta ignoriert es dort)', false === strpos( $html, 'test_event_code' ) );
check( 'Korrektur v0.5.7: KEIN test_event_code im URL-Event-Aufruf ans Browser-Pixel', false !== strpos( $html, "fbq('track','Lead',{}," ) );
// v0.6.2: Google Ads und TikTok sind Pro-only (siehe google_active()/
// tiktok_active()-Doku in class-pms-frontend.php) -- PMS_IS_PRO ist an dieser
// Stelle der Datei noch nicht definiert (siehe Abschnitt 17 ganz unten für
// den Pro-Nachweis derselben Szenarien), $base_settings oben hat beide
// Plattformen aber aktiviert konfiguriert. Bewusster Test: Free gibt trotz
// vollständiger Google/TikTok-Konfiguration keines von beidem aus.
check( 'Google: in Free NICHT ausgegeben (Pro-only seit v0.6.2)', false === strpos( $html, 'gtag(' ) );
check( 'TikTok: in Free NICHT ausgegeben (Pro-only seit v0.6.2)', false === strpos( $html, 'ttq.load(' ) );
// v0.6.8: GA4 ist ebenfalls Pro-only, gleiches Prinzip wie Google/TikTok --
// $base_settings oben hat testweise auch eine GA4-ID gesetzt (Pro-Nachweis
// derselben Konfiguration in Abschnitt 24).
check( 'GA4: in Free NICHT ausgegeben (Pro-only seit v0.6.8)', false === strpos( $html, "gtag('config','G-ABC1234XYZ')" ) );
check( 'noscript-Fallback vorhanden', false !== strpos( $html, '<noscript>' ) );
check( 'Kein Consent-Bootstrap nötig', false === strpos( $html, 'pmsHasConsent' ) );
check( 'BUGFIX 2: Direkter Block hinter globalem Init-Guard', false !== strpos( $html, 'window.pmsInitialized=window.pmsInitialized||false;' ) && false !== strpos( $html, 'if(!window.pmsInitialized)' ) );

$post = $GLOBALS['stub']['captured_posts'][0] ?? array( 'url' => '', 'args' => array() );
check( 'CAPI: Request an v26.0-Endpoint', 'https://graph.facebook.com/v26.0/123456789012345/events' === $post['url'] );
$body = json_decode( $post['args']['body'] ?? '', true );
$ev   = $body['data'][0] ?? array();
check( 'CAPI: test_event_code (frisch) enthalten', 'TEST123' === ( $body['test_event_code'] ?? '' ) );
preg_match( "/fbq\('track','Lead',\{[^}]*\},\{eventID:'([0-9a-f\-]{36})'\}\)/", $html, $m );
check( 'DEDUPLIZIERUNG: Browser-eventID === CAPI-event_id', ( $m[1] ?? 'x' ) === ( $ev['event_id'] ?? 'y' ) );

// v0.6.8: GA4 allein (ohne Meta/Google Ads/TikTok) darf in Free gar kein
// Tracking aktivieren -- der neue GA4-Zweig in should_track()s $any_platform
// muss denselben is_pro()-Guard tragen wie Google Ads/TikTok. Eigene,
// isolierte Settings-Fixture über sanitize_settings(array()) (alle Bools
// defaulten dabei auf 0/aus, siehe dortiges Muster), danach $base_settings
// wiederhergestellt -- Abschnitt 4 direkt im Anschluss braucht die
// ursprüngliche Fixture unverändert.
$GLOBALS['stub']['options']['pms_settings'] = PMS_Settings::sanitize_settings( array( 'ga4_measurement_id' => 'g-only123' ) );
$html_ga4_only = run_frontend();
check( 'GA4 allein reicht in Free NICHT, um Tracking zu aktivieren (Pro-only, should_track() liefert leeren Output)', '' === $html_ga4_only );
$GLOBALS['stub']['options']['pms_settings'] = $base_settings;

echo "\n=== 4. Test Event Code: 12h Auto-Expiry ===\n";

$GLOBALS['stub']['options']['pms_settings']['test_code_created_at'] = time() - ( 13 * HOUR_IN_SECONDS );
run_frontend();
$body2 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( 'Abgelaufener Test-Code wird NICHT gesendet', is_array( $body2 ) && ! array_key_exists( 'test_event_code', $body2 ) );
$saved = $GLOBALS['stub']['options']['pms_settings'];
check( 'Abgelaufener Test-Code wird in der DB geleert', '' === $saved['test_event_code'] && 0 === $saved['test_code_created_at'] );

$GLOBALS['stub']['options']['pms_settings']['test_event_code']      = 'TEST123';
$GLOBALS['stub']['options']['pms_settings']['test_code_created_at'] = time() - ( 11 * HOUR_IN_SECONDS );
run_frontend();
$body3 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( '11h alter Test-Code wird noch gesendet', 'TEST123' === ( $body3['test_event_code'] ?? '' ) );

echo "\n=== 5. Consent-Erkennung im Frontend (Banner aktiv: CLI_VERSION) ===\n";

define( 'CLI_VERSION', '3.3.0' );
clear_consent_cookies();
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 1;

check( 'Banner aktiv + keine Entscheidung -> Consent false', false === consent_check() );

$html5 = run_frontend();
check( 'Deferred: Consent-Bootstrap vorhanden', false !== strpos( $html5, 'pmsHasConsent' ) && false !== strpos( $html5, 'pmsInit' ) );
check( 'Deferred: Banner-Events registriert', false !== strpos( $html5, 'CLI_Cookie_Accept_All' ) && false !== strpos( $html5, 'borlabs-cookie-consent-saved' ) && false !== strpos( $html5, 'cmplz_fire_categories' ) && false !== strpos( $html5, 'CookiebotOnConsentReady' ) );
check( 'Deferred: Meta-Skript im Bootstrap gekapselt', false !== strpos( $html5, "fbq('init'" ) );
check( 'Deferred: TikTok in Free weiterhin NICHT ausgegeben (Pro-only seit v0.6.2)', false === strpos( $html5, 'ttq.load(' ) );
check( 'Deferred: kein noscript (kein Tracking ohne Consent)', false === strpos( $html5, '<noscript>' ) );
check( 'Deferred: Google in Free weiterhin NICHT ausgegeben, auch nicht der sonst sofortige Consent-Mode-Pfad (Pro-only seit v0.6.2)', false === strpos( $html5, 'googletagmanager.com/gtag/js' ) );
check( 'Deferred: CAPI wird NICHT gesendet', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
check( 'BUGFIX 2: Bootstrap nutzt globalen Guard pmsInitTracking', false !== strpos( $html5, 'function pmsInitTracking(){if(window.pmsInitialized){return;}window.pmsInitialized=true;' ) );
check( 'BUGFIX 2 (jetzt Pro-only): Google-Consent-Mode-Block in Free nicht vorhanden', false === strpos( $html5, 'pmsGtagInit' ) );
check( 'BUGFIX 1 (JS): marketing-Kategorie wird geprüft', false !== strpos( $html5, "cookielawinfo-checkbox-marketing=yes" ) );
check( 'BUGFIX 1 (JS): viewed_cookie_policy blockiert strikt', false !== strpos( $html5, "viewed_cookie_policy=')>-1)return false" ) && false === strpos( $html5, "viewed_cookie_policy=yes')>-1)return true" ) );
check( 'MHP (JS): mhcookie wird Base64-dekodiert + als JSON geparst', false !== strpos( $html5, 'mhcookie=' ) && false !== strpos( $html5, 'atob(' ) && false !== strpos( $html5, 'JSON.parse(' ) );
check( 'MHP (JS): groups-Array wird auf "all" geprüft', false !== strpos( $html5, 'mh.groups.indexOf("all")' ) );

$GLOBALS['stub']['options']['pms_settings']['google_consent_mode'] = 0;
$html6 = run_frontend();
check( 'Deferred ohne Consent Mode: Google in Free weiterhin NICHT ausgegeben (Pro-only seit v0.6.2)', false === strpos( $html6, 'pmsGs=document.createElement' ) && false === strpos( $html6, 'googletagmanager.com/gtag/js' ) );
$GLOBALS['stub']['options']['pms_settings']['google_consent_mode'] = 1;

$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'yes';
$html7 = run_frontend();
check( 'Consent erteilt: Skripte direkt, kein Bootstrap', false === strpos( $html7, 'pmsHasConsent' ) && false !== strpos( $html7, "fbq('track','Lead'" ) );
check( 'Consent erteilt: CAPI wird gesendet', 1 === count( $GLOBALS['stub']['captured_posts'] ) );

$_COOKIE['cookielawinfo-checkbox-advertisement'] = 'no';
run_frontend();
check( 'Consent verweigert: CAPI bricht vor Request ab', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
clear_consent_cookies();

echo "\n=== 6. WP Consent API (höchste Priorität) ===\n";

if ( ! function_exists( 'wp_has_consent' ) ) {
	function wp_has_consent( $category ) {
		return $GLOBALS['stub']['wp_consent'];
	}
}
$GLOBALS['stub']['wp_consent'] = true;
check( 'wp_has_consent(true) -> Consent true', true === consent_check() );
$GLOBALS['stub']['wp_consent'] = false;
check( 'wp_has_consent(false) -> Consent false', false === consent_check() );
$GLOBALS['stub']['wp_consent'] = true;

echo "\n=== 7. Negativfälle & XSS ===\n";

$GLOBALS['stub']['options']['pms_settings']['capi_enabled'] = 0;
$html8 = run_frontend();
check( 'CAPI aus: kein Server-Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
$GLOBALS['stub']['options']['pms_settings']['capi_enabled'] = 1;

$GLOBALS['stub']['options']['pms_settings']['capi_token'] = '';
run_frontend();
check( 'Leerer Token: kein CAPI-Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
$GLOBALS['stub']['options']['pms_settings']['capi_token'] = 'EAAtesttoken';

$GLOBALS['stub']['options']['pms_events_enabled'] = 0;
$html9 = run_frontend();
check( 'Globaler Event-Schalter aus: keine Custom Events, Basis bleibt', false === strpos( $html9, "'send_to'" ) && false !== strpos( $html9, "fbq('track','PageView')" ) );
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;

reset_frontend();
ob_start();
PMS_Frontend::print_scripts();
check( 'Ohne prepare(): print_scripts gibt nichts aus', '' === ob_get_clean() );

$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'evx', 'name' => "Böse'};alert(1);//", 'event_type' => 'CustomEvent', 'match_type' => 'contains', 'match_value' => 'bestaetigung', 'active' => 1,
		'meta_enabled' => 1, 'tiktok_enabled' => 1, 'tiktok_event' => 'CustomEvent',
	),
);
$html10 = run_frontend();
check( 'XSS: Anführungszeichen im Event-Namen escaped', false === strpos( $html10, "Böse'};" ) );

echo "\n=== 8. Feature 2: First-Touch & UTM-Attribution ===\n";

$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 1;

reset_attribution();
$_GET['utm_source']   = 'facebook';
$_GET['utm_medium']   = 'cpc';
$_GET['utm_campaign'] = 'sommer-2026';
$_GET['fbclid']       = 'AbC123xyz_-';
PMS_Pro_UTM::capture();
$attr = PMS_Pro_UTM::get();
check( 'Attribution: UTM-Parameter erfasst', 'facebook' === ( $attr['utm_source'] ?? '' ) && 'sommer-2026' === ( $attr['utm_campaign'] ?? '' ) );
check( 'Attribution: Zeitstempel gesetzt', ! empty( $attr['ts'] ) );

$custom = PMS_Pro_UTM::custom_data();
check( 'Attribution: custom_data enthält UTM', 'cpc' === ( $custom['utm_medium'] ?? '' ) );
check( 'Attribution: custom_data ohne fbclid/ts', ! isset( $custom['fbclid'] ) && ! isset( $custom['ts'] ) );
check( 'Attribution: fbc im Meta-Format', 1 === preg_match( '/^fb\.1\.\d{13}\.AbC123xyz_-$/', PMS_Pro_UTM::fbc() ), PMS_Pro_UTM::fbc() );

reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'utm_source' => 'google', 'utm_campaign' => 'alt', 'fbclid' => 'ALT1', 'ts' => 1700000000 ) );
$_GET['utm_source']           = 'facebook';
$_GET['fbclid']               = 'NEU2';
PMS_Pro_UTM::capture();
$attr2 = PMS_Pro_UTM::get();
check( 'First-Touch: bestehende utm_source bleibt erhalten', 'google' === ( $attr2['utm_source'] ?? '' ), $attr2['utm_source'] ?? '?' );
check( 'First-Touch: bestehende Kampagne bleibt erhalten', 'alt' === ( $attr2['utm_campaign'] ?? '' ) );
check( 'First-Touch: fbclid wird auf den letzten Klick aktualisiert', 'NEU2' === ( $attr2['fbclid'] ?? '' ) );

echo "\n=== 8b. Feature 2 (v0.5.6): gclid wie fbclid als Klick-ID behandelt ===\n";

reset_attribution();
$_GET['utm_source'] = 'google';
$_GET['gclid']       = 'GCLID123';
PMS_Pro_UTM::capture();
$attrG = PMS_Pro_UTM::get();
check( 'Attribution: gclid wird erfasst', 'GCLID123' === ( $attrG['gclid'] ?? '' ) );

$customG = PMS_Pro_UTM::custom_data();
check( 'Attribution: custom_data enthält KEIN gclid (Google-spezifisch, für Meta CAPI irrelevant)', ! isset( $customG['gclid'] ) );
check( 'Attribution: custom_data weiterhin ohne fbclid', ! isset( $customG['fbclid'] ) );

reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'gclid' => 'ALT-GCLID', 'utm_campaign' => 'alt', 'ts' => 1700000000 ) );
$_GET['gclid']                = 'NEU-GCLID';
PMS_Pro_UTM::capture();
$attrG2 = PMS_Pro_UTM::get();
check( 'gclid wird (wie fbclid) auf den letzten Klick aktualisiert, nicht First-Touch', 'NEU-GCLID' === ( $attrG2['gclid'] ?? '' ) );
check( 'First-Touch für utm_campaign bleibt von der gclid-Aktualisierung unberührt', 'alt' === ( $attrG2['utm_campaign'] ?? '' ) );

reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'utm_source' => '<script>x</script>evil', 'ts' => 1700000000 ) );
$sanitized = PMS_Pro_UTM::get();
check( 'Attribution: Cookie-Werte werden bereinigt', false === strpos( (string) ( $sanitized['utm_source'] ?? '' ), '<' ) );

reset_attribution();
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 0;
$_GET['utm_source'] = 'facebook';
check( 'Attribution deaktiviert: keine Daten', array() === PMS_Pro_UTM::get() && array() === PMS_Pro_UTM::custom_data() );
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 1;

reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'utm_source' => 'facebook', 'pad' => str_repeat( 'z', 9000 ) ) );
check( 'Attribution-Cookie: >8KB wird ungeparst verworfen', array() === PMS_Pro_UTM::get() );
reset_attribution();

echo "\n=== 9. Feature 2: UTM & fbc im CAPI-Payload ===\n";

reset_attribution();
$_GET['utm_source']   = 'facebook';
$_GET['utm_campaign'] = 'sommer-2026';
$_GET['fbclid']       = 'FbclidAusUrl';
unset( $_COOKIE['_fbc'] );
$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'ev1', 'name' => 'Lead Danke', 'event_type' => 'Lead', 'match_type' => 'exact', 'match_value' => '/bestaetigung/', 'active' => 1,
		'meta_enabled' => 1,
	),
);
$_SERVER['REQUEST_URI'] = '/bestaetigung/';
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 0;

run_frontend();
$bodyU = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
$evU   = $bodyU['data'][0] ?? array();
check( 'CAPI: custom_data mit utm_source', 'facebook' === ( $evU['custom_data']['utm_source'] ?? '' ) );
check( 'CAPI: custom_data mit utm_campaign', 'sommer-2026' === ( $evU['custom_data']['utm_campaign'] ?? '' ) );
check( 'CAPI: fbc aus fbclid gesetzt', 1 === preg_match( '/^fb\.1\.\d{13}\.FbclidAusUrl$/', $evU['user_data']['fbc'] ?? '' ) );

reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'fbclid' => 'AusCookie', 'ts' => 1700000000 ) );
run_frontend();
$bodyU2 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( 'CAPI: fbc aus Attribution-Cookie (Tage nach dem Klick)', 'fb.1.1700000000000.AusCookie' === ( $bodyU2['data'][0]['user_data']['fbc'] ?? '' ), $bodyU2['data'][0]['user_data']['fbc'] ?? '?' );

reset_attribution();
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 0;
run_frontend();
$bodyU3 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( 'CAPI: ohne Attribution kein custom_data', ! isset( $bodyU3['data'][0]['custom_data'] ) );
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 1;

echo "\n=== 10. Feature 1: Formular-Lead – Hashing & Payload ===\n";

check( 'hash_email: normalisiert (trim + lowercase)', hash( 'sha256', 'kunde@example.com' ) === PMS_CAPI::hash_email( '  Kunde@Example.COM ' ) );
check( 'hash_email: ungültige Adresse -> leer', '' === PMS_CAPI::hash_email( 'keine-mail' ) );
check( 'hash_email: leerer Wert -> leer', '' === PMS_CAPI::hash_email( '' ) );
check( 'hash_phone: Sonderzeichen entfernt', hash( 'sha256', '4901761234567' ) === PMS_CAPI::hash_phone( '+49 (0) 176 / 1234-567' ) );
check( 'hash_phone: führende Null entfernt', hash( 'sha256', '1761234567' ) === PMS_CAPI::hash_phone( '0176 1234567' ) );
check( 'hash_phone: Buchstaben -> leer', '' === PMS_CAPI::hash_phone( 'kein telefon' ) );
check( 'hash_phone: zu kurz -> leer', '' === PMS_CAPI::hash_phone( '123' ) );

echo "\n=== 10a-2. Security-Audit v0.5.5: Input-Längenbegrenzung ===\n";

$huge_local = str_repeat( 'a', 5000 ) . '@example.com';
check( 'hash_email: 5000 Zeichen langer Local-Part -> leer (kappt vor is_email)', '' === PMS_CAPI::hash_email( $huge_local ) );

$valid_long_ok = str_repeat( 'a', 60 ) . '@example.com';
check( 'hash_email: gültige, aber lange Adresse funktioniert weiterhin', '' !== PMS_CAPI::hash_email( $valid_long_ok ) );

$huge_phone = '+49' . str_repeat( '1', 5000 );
check( 'hash_phone: 5000-stellige Nummer wird auf 32 Zeichen gekappt vor dem Hashen',
	hash( 'sha256', preg_replace( '/\D+/', '', substr( $huge_phone, 0, 32 ) ) ) === PMS_CAPI::hash_phone( $huge_phone )
);

$GLOBALS['stub']['captured_posts'] = array();
// Seit v0.6.1 erwartet log() volle Event-Arrays (nicht nur Namen), damit es
// pro Event einen PMS_Logger-Eintrag mit event_id schreiben kann.
$log_ref = new ReflectionMethod( 'PMS_CAPI', 'log' );
$logged  = $log_ref->invoke( null, array( array( 'event_type' => 'Lead', 'name' => 'Lead', 'event_id' => 'log-test-1' ) ), 'error', 400, '<script>alert(1)</script>Meta-Fehler ' . str_repeat( 'x', 400 ), array() );
check( 'CAPI-Log: HTML/Script-Tags aus externer Meta-Antwort entfernt', false === strpos( $logged['message'], '<script>' ) && false !== strpos( $logged['message'], 'Meta-Fehler' ) );
check( 'CAPI-Log: Nachricht auf 300 Zeichen gekappt', strlen( $logged['message'] ) <= 300 );

$GLOBALS['stub']['captured_posts'] = array();
$lead_event = array( 'id' => 'form-lead', 'name' => 'Lead', 'event_type' => 'Lead', 'event_id' => 'aaaa-bbbb-cccc-dddd', 'meta_enabled' => 1 );
$status     = PMS_CAPI::send_events(
	array( $lead_event ),
	PMS_Settings::get(),
	'https://example.com/danke/',
	array( 'em' => array( PMS_CAPI::hash_email( 'kunde@example.com' ) ), 'ph' => array( PMS_CAPI::hash_phone( '0176 1234567' ) ) )
);
$bodyL = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
$evL   = $bodyL['data'][0] ?? array();
check( 'Form-Lead: event_name Lead', 'Lead' === ( $evL['event_name'] ?? '' ) );
check( 'Form-Lead: Event-ID vom Browser übernommen', 'aaaa-bbbb-cccc-dddd' === ( $evL['event_id'] ?? '' ) );
check( 'Form-Lead: gehashte E-Mail im user_data', array( hash( 'sha256', 'kunde@example.com' ) ) === ( $evL['user_data']['em'] ?? array() ) );
check( 'Form-Lead: gehashte Telefonnummer im user_data', array( hash( 'sha256', '1761234567' ) ) === ( $evL['user_data']['ph'] ?? array() ) );
check( 'Form-Lead: Klartext-Daten NICHT im Payload', false === strpos( $GLOBALS['stub']['captured_posts'][0]['args']['body'], 'kunde@example.com' ) );
check( 'Form-Lead: Status wird protokolliert', 'sent' === ( $status['status'] ?? '' ) );

$GLOBALS['stub']['options']['pms_settings']['form_tracking'] = 1;
check( 'Forms: aktiv laut Einstellung', true === PMS_Forms::enabled() );
$GLOBALS['stub']['options']['pms_settings']['form_tracking'] = 0;
check( 'Forms: deaktivierbar', false === PMS_Forms::enabled() );
$GLOBALS['stub']['options']['pms_settings']['form_tracking'] = 1;

$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 1;
$GLOBALS['stub']['wp_consent'] = false;
reset_consent_cache();
$GLOBALS['stub']['captured_posts'] = array();
$blocked = PMS_CAPI::send_events( array( $lead_event ), PMS_Settings::get(), 'https://example.com/danke/', array() );
check( 'Form-Lead ohne Consent: kein Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
check( 'Form-Lead ohne Consent: Status consent_blocked', 'consent_blocked' === ( $blocked['status'] ?? '' ) );
$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 0;

echo "\n=== 10b. Formular-Grabber: granulare Steuerung ===\n";

$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Contact';
check( 'Event-Typ: Contact wird übernommen', 'Contact' === PMS_Forms::event_type() );
$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Purchase';
check( 'Event-Typ: unerlaubter Wert fällt auf Lead zurück', 'Lead' === PMS_Forms::event_type() );
$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Lead';

$clean = PMS_Settings::sanitize_settings( array( 'form_event_type' => 'Contact' ) );
check( 'sanitize: Contact erlaubt', 'Contact' === $clean['form_event_type'] );
$clean = PMS_Settings::sanitize_settings( array( 'form_event_type' => 'CustomEvent' ) );
check( 'sanitize: fremder Event-Typ wird zu Lead', 'Lead' === $clean['form_event_type'] );

check(
	'URL-Filter: Normalisierung (klein, getrimmt, dedupliziert)',
	'/kontakt, /angebot' === PMS_Settings::sanitize_url_filter( ' /Kontakt , /ANGEBOT,  /kontakt ,, ' ),
	PMS_Settings::sanitize_url_filter( ' /Kontakt , /ANGEBOT,  /kontakt ,, ' )
);
check( 'URL-Filter: Markup wird entfernt', false === strpos( PMS_Settings::sanitize_url_filter( '/x<script>alert(1)</script>' ), '<' ) );

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '';
check( 'URL-Filter leer: überall aktiv', true === PMS_Forms::url_allowed( '/beliebige-seite/' ) );

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '/kontakt, /angebot';
check( 'URL-Filter: passende Seite erlaubt', true === PMS_Forms::url_allowed( '/kontakt/' ) );
check( 'URL-Filter: Teilstring-Treffer erlaubt', true === PMS_Forms::url_allowed( '/de/angebot-anfordern/' ) );
check( 'URL-Filter: Großschreibung ignoriert', true === PMS_Forms::url_allowed( '/KONTAKT/' ) );
check( 'URL-Filter: fremde Seite blockiert', false === PMS_Forms::url_allowed( '/blog/artikel/' ) );
check( 'URL-Filter: Startseite blockiert', false === PMS_Forms::url_allowed( '/' ) );

check( 'form_url_filters(): Array mit beiden Pfaden', array( '/kontakt', '/angebot' ) === PMS_Settings::form_url_filters() );
$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '';

$GLOBALS['stub']['captured_posts'] = array();
$contact_event = array( 'id' => 'form-lead', 'name' => 'Contact', 'event_type' => 'Contact', 'event_id' => 'cccc-dddd', 'meta_enabled' => 1 );
PMS_CAPI::send_events( array( $contact_event ), PMS_Settings::get(), 'https://example.com/kontakt/', array() );
$bodyC = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( 'CAPI: event_name Contact statt Lead', 'Contact' === ( $bodyC['data'][0]['event_name'] ?? '' ) );

echo "\n=== 10b-2. UTM-Form-Fill (v0.5.6): URL-Gating all/include/exclude + Wildcard ===\n";

check(
	'sanitize_url_patterns: zeilenbasiert, klein, getrimmt, dedupliziert, Wildcard bleibt erhalten',
	"/kontakt\n/lp/*" === PMS_Settings::sanitize_url_patterns( " /Kontakt \r\n /LP/*  \n/kontakt\n\n" ),
	PMS_Settings::sanitize_url_patterns( " /Kontakt \r\n /LP/*  \n/kontakt\n\n" )
);
check(
	'utm_form_fill_url_patterns(): liest die gespeicherten Zeilen als Array',
	array( '/kontakt', '/lp/*' ) === ( function () {
		$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_urls'] = "/kontakt\n/lp/*";
		return PMS_Settings::utm_form_fill_url_patterns();
	} )()
);

// Die URL-Auswertung (all/include/exclude, Wildcards) liegt seit v0.5.7
// ausschließlich in assets/frontend.js -- siehe dev-tools/test-frontend-js.js.
// Die frühere PHP-Doppelung PMS_Pro_UTM::form_fill_url_allowed() wurde in
// v0.7.0 als toter Code entfernt; PHP liefert nur noch Modus + Muster.
$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 0;
check( 'Form-Fill: form_fill_enabled() spiegelt die Einstellung', false === PMS_Pro_UTM::form_fill_enabled() );
$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 1;
check( 'Form-Fill: form_fill_enabled() aktivierbar', true === PMS_Pro_UTM::form_fill_enabled() );

$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 0;
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_mode']   = 'all';
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_urls']   = '';

echo "\n=== 10c. Konflikt-Erkennung URL-Event vs. Formular-Tracking ===\n";

$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$GLOBALS['stub']['options']['pms_settings']['form_tracking']   = 1;
$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Lead';
$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'evk', 'name' => 'Kontakt', 'event_type' => 'Lead', 'match_type' => 'exact',
		'match_value' => '/kontakt/', 'active' => 1, 'meta_enabled' => 1,
	),
);

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '';
check( 'Konflikt: leerer URL-Filter meldet nichts', array() === PMS_Admin::detect_form_url_conflicts() );

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '/kontakt';
check( 'Konflikt: gleiche URL + gleicher Event-Typ wird erkannt', array( '/kontakt/' ) === PMS_Admin::detect_form_url_conflicts(), json_encode( PMS_Admin::detect_form_url_conflicts() ) );

$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Contact';
check( 'Kein Konflikt bei unterschiedlichem Event-Typ', array() === PMS_Admin::detect_form_url_conflicts() );
$GLOBALS['stub']['options']['pms_settings']['form_event_type'] = 'Lead';

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '/angebot';
check( 'Kein Konflikt bei anderer URL', array() === PMS_Admin::detect_form_url_conflicts() );

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '/kontakt';
$GLOBALS['stub']['options']['pms_events'][0]['active'] = 0;
check( 'Kein Konflikt bei inaktiver Event-Regel', array() === PMS_Admin::detect_form_url_conflicts() );
$GLOBALS['stub']['options']['pms_events'][0]['active'] = 1;

$GLOBALS['stub']['options']['pms_settings']['form_tracking'] = 0;
check( 'Kein Konflikt bei deaktiviertem Formular-Tracking', array() === PMS_Admin::detect_form_url_conflicts() );
$GLOBALS['stub']['options']['pms_settings']['form_tracking'] = 1;

$GLOBALS['stub']['options']['pms_events_enabled'] = 0;
check( 'Kein Konflikt bei global deaktivierten Events', array() === PMS_Admin::detect_form_url_conflicts() );
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;

$GLOBALS['stub']['options']['pms_settings']['form_url_filter'] = '';
$GLOBALS['stub']['options']['pms_events'] = array();

echo "\n=== 11. Feature 4: Konfiguration exportieren & importieren ===\n";

$export = array(
	'format'         => 'pms-config',
	'version'        => '0.5.0',
	'settings'       => array(
		'pixel_enabled'   => 1,
		'pixel_id'        => '999888777666555',
		'capi_token'      => 'EAAimport',
		'form_tracking'   => 1,
		'utm_passthrough' => 0,
	),
	'events'         => array(
		array(
			'id' => 'imp1', 'name' => 'Importiertes Event', 'event_type' => 'Contact',
			'match_type' => 'contains', 'match_value' => 'kontakt', 'active' => 1, 'meta_enabled' => 1,
		),
	),
	'events_enabled' => 1,
);

check( 'Import: gültige Konfiguration wird übernommen', true === PMS_Tools::import_from_json( json_encode( $export ) ) );
$imported = PMS_Settings::get();
check( 'Import: Pixel-ID übernommen', '999888777666555' === $imported['pixel_id'] );
check( 'Import: Token übernommen', 'EAAimport' === $imported['capi_token'] );
check( 'Import: Toggle-Zustände übernommen', 0 === $imported['utm_passthrough'] && 1 === $imported['form_tracking'] );
$imported_events = PMS_Settings::get_events();
check( 'Import: Event-Regel übernommen', isset( $imported_events['imp1'] ) && 'Contact' === $imported_events['imp1']['event_type'] );

check( 'Import: falsches Format wird abgelehnt', false === PMS_Tools::import_from_json( json_encode( array( 'format' => 'fremd', 'settings' => array() ) ) ) );
check( 'Import: fehlende settings werden abgelehnt', false === PMS_Tools::import_from_json( json_encode( array( 'format' => 'pms-config' ) ) ) );
check( 'Import: kein JSON wird abgelehnt', false === PMS_Tools::import_from_json( 'kein json' ) );
check( 'Import: leerer String wird abgelehnt', false === PMS_Tools::import_from_json( '' ) );

$evil = json_encode(
	array(
		'format'   => 'pms-config',
		'settings' => array( 'pixel_id' => '123<script>alert(1)</script>456', 'capi_token' => "EAA<script>x</script>" ),
		'events'   => array( array( 'id' => 'x', 'name' => 'Y', 'event_type' => 'EvilType', 'match_type' => 'exact', 'match_value' => '/x/' ) ),
	)
);
PMS_Tools::import_from_json( $evil );
$after = PMS_Settings::get();
check( 'Import: Pixel-ID wird auf Ziffern reduziert', '1231456' === $after['pixel_id'], $after['pixel_id'] );
check( 'Import: Pixel-ID enthält kein Markup', 1 === preg_match( '/^\d+$/', $after['pixel_id'] ) );
check( 'Import: Token ohne HTML', false === strpos( $after['capi_token'], '<' ) );
check( 'Import: ungültiger Event-Typ wird verworfen', 0 === count( PMS_Settings::get_events() ) );

echo "\n=== 12. Feature 3: Live-Debug-Leiste (Sichtbarkeit) ===\n";

$GLOBALS['stub']['options']['pms_settings']['debug_bar'] = 1;
$GLOBALS['stub']['is_user_logged_in'] = false;
$GLOBALS['stub']['current_user_can']  = false;
check( 'Debug-Leiste: für Besucher NICHT aktiv', false === PMS_Debug::enabled() );

$GLOBALS['stub']['is_user_logged_in'] = true;
check( 'Debug-Leiste: für eingeloggte Nicht-Admins NICHT aktiv', false === PMS_Debug::enabled() );

$GLOBALS['stub']['current_user_can'] = true;
check( 'Debug-Leiste: für Administratoren aktiv', true === PMS_Debug::enabled() );

$GLOBALS['stub']['options']['pms_settings']['debug_bar'] = 0;
check( 'Debug-Leiste: per Einstellung abschaltbar', false === PMS_Debug::enabled() );

$GLOBALS['stub']['options']['pms_settings']['debug_bar'] = 1;
$GLOBALS['stub']['is_admin'] = true;
check( 'Debug-Leiste: im WP-Backend NICHT aktiv', false === PMS_Debug::enabled() );
$GLOBALS['stub']['is_admin'] = false;

$GLOBALS['stub']['current_user_can']  = false;
$GLOBALS['stub']['is_user_logged_in'] = false;

echo "\n=== 12b. Security-Audit v0.5.5: Debug-Leiste gegen Script-Breakout gehärtet ===\n";

$log_prop = new ReflectionProperty( 'PMS_CAPI', 'log' );
$log_prop->setValue( null, array( array(
	'events' => array( 'Lead' ), 'status' => 'error', 'code' => 400,
	'message' => '</script><script>alert(document.cookie)</script>',
	'match_keys' => array(),
) ) );

$GLOBALS['stub']['options']['pms_settings']['debug_bar']    = 1;
$GLOBALS['stub']['is_user_logged_in']                          = true;
$GLOBALS['stub']['current_user_can']                           = true;
$active_prop = new ReflectionProperty( 'PMS_Frontend', 'active' );
$active_prop->setValue( null, false );

ob_start();
PMS_Debug::render();
$debug_html = ob_get_clean();

check( 'Debug-Leiste: genau ein echtes schließendes </script> im Output (kein Breakout)', 1 === substr_count( $debug_html, '</script>' ), 'gefunden: ' . substr_count( $debug_html, '</script>' ) );
check( 'Debug-Leiste: kein zweites öffnendes <script> aus der Log-Nachricht', 1 === substr_count( $debug_html, '<script' ) );
check( 'Debug-Leiste: Nachrichteninhalt bleibt im JSON-Payload erhalten', false !== strpos( $debug_html, 'alert(document.cookie)' ) );

$log_prop->setValue( null, array() );
$GLOBALS['stub']['current_user_can']  = false;
$GLOBALS['stub']['is_user_logged_in'] = false;

echo "\n=== 13. enqueue_frontend() (v0.5.7): Toggle-only Gating, URL-Matching jetzt rein clientseitig ===\n";

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	$base_settings,
	array(
		'consent_detection'    => 0,
		'form_tracking'        => 1,
		'form_url_filter'      => '',
		'form_exclude_system'  => 1,
		'enable_utm_form_fill' => 0,
		'utm_form_fill_mode'   => 'all',
		'utm_form_fill_urls'   => '',
	)
);
$GLOBALS['stub']['options']['pms_events']         = array();
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$_SERVER['REQUEST_URI']                             = '/beliebige-seite/';

$loc = run_enqueue();
check( 'enqueue: nur Formular-Tracking aktiv -> formTracking=true', true === ( $loc['formTracking'] ?? null ) );
check( 'enqueue: nur Formular-Tracking aktiv -> utmFormFill=false', false === ( $loc['utmFormFill'] ?? null ) );
check( 'enqueue: Skript wird geladen', in_array( 'pms-frontend', $GLOBALS['stub']['enqueued_scripts'], true ) );
check( 'enqueue: lokalisiertes Objekt heißt pms_settings (v0.5.7, vorher pmsFront)', null !== $GLOBALS['stub']['localized']['pms-frontend']['pms_settings'] ?? null );
check( 'Korrektur v0.5.7: kein testEventCode mehr im lokalisierten Objekt', ! array_key_exists( 'testEventCode', $loc ) );

$GLOBALS['stub']['options']['pms_settings']['form_tracking']        = 0;
$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 1;

$loc2 = run_enqueue();
check( 'enqueue: nur UTM-Form-Fill aktiv -> formTracking=false', false === ( $loc2['formTracking'] ?? null ) );
check( 'enqueue: nur UTM-Form-Fill aktiv -> utmFormFill=true', true === ( $loc2['utmFormFill'] ?? null ) );
check( 'enqueue: Skript wird auch für ausschließlich UTM-Form-Fill geladen', in_array( 'pms-frontend', $GLOBALS['stub']['enqueued_scripts'], true ) );

$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 0;
$loc3                                                                 = run_enqueue();
check( 'enqueue: beide Features aus -> pms_settings wird nicht lokalisiert (0 Byte)', null === $loc3 );
check( 'enqueue: beide Features aus -> wp_enqueue_script wird nicht aufgerufen', array() === $GLOBALS['stub']['enqueued_scripts'] );

// Bugfix v0.5.7: Der Server prüft die URL-Include/Exclude-Regeln NICHT mehr
// vor dem Enqueue (das führte live zu ReferenceErrors, wenn Server- und
// Browser-Sicht auf den Pfad auseinanderliefen) – ein Pfad, der NICHT ins
// konfigurierte include-Muster passt, darf am "wird überhaupt geladen"
// nichts mehr ändern. Die URL-Auswertung übernimmt jetzt ausschließlich der
// Client (utmFormFillAllowed() in frontend.js), dem hier weiterhin Modus und
// Muster mitgegeben werden.
$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 1;
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_mode']   = 'include';
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_urls']   = '/kontakt';
$_SERVER['REQUEST_URI']                                               = '/seite-nicht-im-include-muster/';
$loc4 = run_enqueue();
check( 'Bugfix v0.5.7: Skript wird geladen, obwohl die Server-URL nicht ins include-Muster passt', true === ( $loc4['utmFormFill'] ?? null ) );
check( 'Bugfix v0.5.7: utmFormFillMode dennoch korrekt durchgereicht (Client filtert selbst)', 'include' === ( $loc4['utmFormFillMode'] ?? '' ) );
check( 'Bugfix v0.5.7: utmFormFillUrls dennoch korrekt durchgereicht (Client filtert selbst)', array( '/kontakt' ) === ( $loc4['utmFormFillUrls'] ?? null ) );

$GLOBALS['stub']['options']['pms_settings']['form_tracking']        = 0;
$GLOBALS['stub']['options']['pms_settings']['enable_utm_form_fill'] = 0;
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_mode']   = 'all';
$GLOBALS['stub']['options']['pms_settings']['utm_form_fill_urls']   = '';
$_SERVER['REQUEST_URI']                                               = '/bestaetigung/';

/* ---------------------------------------------------------------------
 * 14. v0.6.2: Free-Limit für die GESAMTZAHL an Custom Events (max. 2)
 * Ersetzt das bis v0.6.1 gültige "max. 2 AKTIVE, beliebig viele insgesamt"-
 * Modell: seit v0.6.2 ist bereits das ANLEGEN eines 3. Events gesperrt,
 * unabhängig vom Aktiv-Status. PMS_IS_PRO ist an dieser Stelle noch nicht
 * definiert -> is_pro() muss "Free" liefern (defensiver defined()-Check,
 * siehe Doku der Methode).
 * ------------------------------------------------------------------- */

echo "\n=== 14. Free-Limit für die Gesamtzahl an Custom Events (max. 2) ===\n";

function make_test_event( $id, $active ) {
	return PMS_Settings::sanitize_event(
		array(
			'id'           => $id,
			'name'         => 'Test Event ' . $id,
			'event_type'   => 'Lead',
			'match_type'   => 'exact',
			'match_value'  => '/' . $id . '/',
			'active'       => $active,
			'meta_enabled' => 1,
		)
	);
}

check( 'is_pro(): PMS_IS_PRO nicht definiert -> false', false === PMS_Settings::is_pro() );

$GLOBALS['stub']['options']['pms_events'] = array();
check( 'free_event_limit_reached(): 0 Events -> nicht erreicht', false === PMS_Settings::free_event_limit_reached() );

PMS_Settings::save_events(
	array(
		'ev-a' => make_test_event( 'ev-a', 0 ),
		'ev-b' => make_test_event( 'ev-b', 0 ),
	)
);
check(
	'free_event_limit_reached(): genau 2 Events -> erreicht, UNABHÄNGIG vom Aktiv-Status (beide hier inaktiv)',
	true === PMS_Settings::free_event_limit_reached()
);

// Bearbeiten/(De-)Aktivieren eines der beiden bestehenden Events ändert die
// Gesamtzahl nicht -- bleibt in der Free-Version immer möglich.
PMS_Settings::save_events(
	array(
		'ev-a' => make_test_event( 'ev-a', 1 ),
		'ev-b' => make_test_event( 'ev-b', 1 ),
	)
);
check( 'save_events(): beide bestehenden Events lassen sich weiterhin aktivieren', 1 === PMS_Settings::get_events()['ev-a']['active'] && 1 === PMS_Settings::get_events()['ev-b']['active'] );
check( 'free_event_limit_reached(): bleibt erreicht (weiterhin 2 Events, nur jetzt aktiv)', true === PMS_Settings::free_event_limit_reached() );

// save_events() ist die einzige Stelle, an der Events tatsächlich persistiert
// werden (Admin-UI-Handler UND JSON-Import laufen beide darüber) -- deshalb
// hier direkt mit 3 Events aufgerufen, so wie es z. B. ein Import einer auf
// einer Pro-Site exportierten Konfiguration in eine Free-Site tun würde. Die
// Admin-UI selbst verhindert das Anlegen eines 3. Events bereits vorher
// (deaktivierter "Event hinzufügen"-Button, siehe PMS_Admin::render_event_form()).
PMS_Settings::save_events(
	array(
		'ev-a' => make_test_event( 'ev-a', 1 ),
		'ev-b' => make_test_event( 'ev-b', 1 ),
		'ev-c' => make_test_event( 'ev-c', 1 ),
	)
);
$after_cap = PMS_Settings::get_events();
check( 'save_events(): 3. Event wird beim Speichern abgeschnitten, nicht nur deaktiviert', 2 === count( $after_cap ) );
check( 'save_events(): erste 2 Events (Array-Reihenfolge) bleiben erhalten', isset( $after_cap['ev-a'] ) && isset( $after_cap['ev-b'] ) );
check( 'save_events(): 3. Event ist nach dem Abschneiden nicht mehr vorhanden', ! isset( $after_cap['ev-c'] ) );

$GLOBALS['stub']['options']['pms_events'] = array();

/* ---------------------------------------------------------------------
 * 15. v0.6.1: PMS_Logger -- record/get_entries/cleanup/truncate
 * Weiterhin vor der PMS_IS_PRO-Definition (siehe Abschnitt 17 unten) --
 * retention_days() muss hier also durchgehend den Free-Wert (3) liefern.
 * ------------------------------------------------------------------- */

echo "\n=== 15. PMS_Logger: record/get_entries/cleanup/truncate (Free) ===\n";

$GLOBALS['wpdb']->rows = array();

check( 'PMS_Logger::retention_days(): Free -> 3 Tage, unabhängig vom gespeicherten Setting-Wert', 3 === PMS_Logger::retention_days() );

PMS_Logger::record( 'Lead', 'evt-1', 'capi', 200, array( 'em', 'fbc' ), '' );
$rows = $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ];
check( 'record(): genau eine Zeile geschrieben', 1 === count( $rows ) );
check( 'record(): event_name korrekt', 'Lead' === $rows[0]['event_name'] );
check( 'record(): event_id korrekt', 'evt-1' === $rows[0]['event_id'] );
check( 'record(): source korrekt', 'capi' === $rows[0]['source'] );
check( 'record(): http_status korrekt', 200 === $rows[0]['http_status'] );
check( 'record(): user_data_keys als kommagetrennte Liste, KEINE Werte enthalten', 'em, fbc' === $rows[0]['user_data_keys'] );
check( 'record(): leere error_message wird als NULL gespeichert, nicht als leerer String', null === $rows[0]['error_message'] );

PMS_Logger::record( 'Purchase', 'evt-2', 'capi', 400, array(), '<script>alert(1)</script>Invalid token' );
$rows = $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ];
check( 'record(): zweite Zeile zusätzlich zur ersten', 2 === count( $rows ) );
check( 'record(): error_message von HTML-Tags befreit', false === strpos( $rows[1]['error_message'], '<script>' ) && false !== strpos( $rows[1]['error_message'], 'Invalid token' ) );

PMS_Logger::record( str_repeat( 'x', 100 ), 'evt-3', 'browser', 0 );
$capped_row = $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ][2];
check( 'record(): event_name auf 64 Zeichen gekappt', 64 === strlen( $capped_row['event_name'] ) );

check( 'get_entries(): neueste zuerst (Insert-Reihenfolge umgekehrt sichtbar, da alle Test-Zeilen dieselbe Sekunde tragen -> zumindest 3 Einträge)', 3 === count( PMS_Logger::get_entries() ) );
check( 'get_entries(): Status-Filter "error" liefert nur Zeilen mit error_message', 1 === count( PMS_Logger::get_entries( array( 'status' => 'error' ) ) ) );
check( 'get_entries(): Event-Namen-Filter liefert nur passende Zeilen', 1 === count( PMS_Logger::get_entries( array( 'event_name' => 'Purchase' ) ) ) );
check( 'get_entries(): limit wird respektiert', 2 === count( PMS_Logger::get_entries( array( 'limit' => 2 ) ) ) );

check( 'get_distinct_event_names(): alle drei eindeutigen Namen', 3 === count( PMS_Logger::get_distinct_event_names() ) );

PMS_Logger::truncate();
check( 'truncate(): Tabelle danach leer', array() === PMS_Logger::get_entries() );

/* ---------------------------------------------------------------------
 * 16. v0.6.1: PMS_Logger <-> PMS_CAPI-Integration
 * ------------------------------------------------------------------- */

echo "\n=== 16. PMS_Logger <-> PMS_CAPI-Integration ===\n";

// WICHTIG: PMS_Consent::detection_enabled() liest consent_detection über
// PMS_Settings::get() IMMER aus dem AMBIENTEN Stub-Options-Stand -- ein
// vorheriger Testabschnitt (10a) hinterlässt diesen bewusst auf 0. Ein
// $settings-Array, das man nur als Parameter an send_events() durchreicht,
// beeinflusst PMS_Consent also NICHT; consent_detection muss deshalb direkt
// in den Stub-Options gesetzt werden, sonst befragt evaluate() nie unseren
// wp_has_consent()-Stub und "Consent" gilt unabhängig vom Testszenario immer
// als erteilt (detection_enabled() === false -> automatisch true).
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 1;

$log_test_settings = array_merge(
	PMS_Settings::get(),
	array(
		'capi_enabled' => 1,
		'pixel_id'     => '123456789012345',
		'capi_token'   => 'test-token',
	)
);

$GLOBALS['wpdb']->rows             = array();
$GLOBALS['stub']['captured_posts'] = array();
$GLOBALS['stub']['wp_consent']     = true;
reset_consent_cache();

$log_event = array( 'id' => 'ev-log', 'name' => 'Lead', 'event_type' => 'Lead', 'event_id' => 'capi-evt-1', 'meta_enabled' => 1 );

// 'sent' (Standard, nicht blockierend): wird trotzdem protokolliert, mit
// http_status=0 UND leerer error_message ("abgeschickt, Ergebnis unbekannt" --
// siehe PMS_CAPI::log()-Doku, NICHT gleichbedeutend mit einem Fehler).
PMS_CAPI::send_events( array( $log_event ), $log_test_settings, 'https://example.com/danke/' );
$rows = $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ] ?? array();
check( 'send_events() (sent/fire-and-forget): erzeugt einen PMS_Logger-Eintrag', 1 === count( $rows ) );
check( 'send_events() (sent): http_status=0, keine Fehlermeldung (kein Fehler, nur keine Rückmeldung)', 0 === $rows[0]['http_status'] && null === $rows[0]['error_message'] );
check( 'send_events() (sent): source ist "capi" ohne browser_confirmed', 'capi' === $rows[0]['source'] );

$GLOBALS['wpdb']->rows = array();
reset_consent_cache();
PMS_CAPI::send_events( array( $log_event ), $log_test_settings, 'https://example.com/danke/', array(), true /* browser_confirmed */ );
$rows = $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ] ?? array();
check( 'send_events() mit browser_confirmed=true: source wird "both"', 1 === count( $rows ) && 'both' === $rows[0]['source'] );

// consent_blocked/skipped duerfen NICHT geloggt werden (kein echter Sende-
// Versuch fand statt -- reine Rauschunterdrueckung, siehe PMS_CAPI::log()-Doku).
$GLOBALS['wpdb']->rows         = array();
$GLOBALS['stub']['wp_consent'] = false;
reset_consent_cache(); // PMS_Consent cached das Ergebnis request-lokal -- ohne Reset sähe dieser Aufruf noch den Consent-Stand vorheriger Aufrufe.
PMS_CAPI::send_events( array( $log_event ), $log_test_settings, 'https://example.com/danke/' );
check( 'send_events() (consent_blocked): erzeugt KEINEN PMS_Logger-Eintrag', empty( $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ] ?? array() ) );

// 'skipped': send_events() selbst prüft NUR pixel_id/capi_token auf leer --
// capi_enabled wird bereits von den Aufrufern (PMS_Frontend/PMS_Forms) VOR
// dem Aufruf geprüft, nicht hier noch einmal. Fehlender Token ist daher das
// richtige Szenario, um "skipped" tatsächlich auszulösen.
$GLOBALS['wpdb']->rows         = array();
$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();
PMS_CAPI::send_events( array( $log_event ), array_merge( $log_test_settings, array( 'capi_token' => '' ) ), 'https://example.com/danke/' );
check( 'send_events() (skipped, kein CAPI-Token): erzeugt KEINEN PMS_Logger-Eintrag', empty( $GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ] ?? array() ) );

reset_consent_cache();
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 0;

$GLOBALS['wpdb']->rows = array();

/* ---------------------------------------------------------------------
 * 17. v0.6.0: Pro-Version hat kein Event-Limit + v0.6.1 PMS_Logger-Retention
 * PHP-Konstanten lassen sich nicht "un-definieren" (siehe Hinweis im
 * Datei-Header) -- deshalb MUSS dieser Abschnitt der letzte der ganzen
 * Datei sein. Alles, was hier oder danach noch liefe, würde fälschlich
 * "Pro" statt "Free" sehen.
 * ------------------------------------------------------------------- */

echo "\n=== 17. Pro-Version: kein Event-Limit, konfigurierbare Log-Retention ===\n";

define( 'PMS_IS_PRO', true );

check( 'is_pro(): PMS_IS_PRO === true -> true', true === PMS_Settings::is_pro() );
check( 'class_exists(PMS_Pro_UTM): Testsuite hat die Klasse geladen (Invariante für Abschnitte oben)', class_exists( 'PMS_Pro_UTM' ) );

PMS_Settings::save_events(
	array(
		'ev-a' => make_test_event( 'ev-a', 1 ),
		'ev-b' => make_test_event( 'ev-b', 1 ),
		'ev-c' => make_test_event( 'ev-c', 1 ),
		'ev-d' => make_test_event( 'ev-d', 1 ),
	)
);
$pro_events = PMS_Settings::get_events();
check( 'free_event_limit_reached(): in Pro immer false, unabhängig von der aktiven Anzahl', false === PMS_Settings::free_event_limit_reached() );
check( 'save_events(): in Pro bleiben alle aktiven Events aktiv (kein Cap)', 1 === $pro_events['ev-a']['active'] && 1 === $pro_events['ev-b']['active'] && 1 === $pro_events['ev-c']['active'] && 1 === $pro_events['ev-d']['active'] );

$GLOBALS['stub']['options']['pms_events'] = array();

// v0.6.2: Google Ads/TikTok sind Pro-only (siehe google_active()/
// tiktok_active()-Doku in class-pms-frontend.php); Abschnitt 3 weiter oben
// bestätigt bereits, dass beide in Free trotz vollständiger Konfiguration
// NICHT ausgegeben werden. Hier zusätzlich der Pro-Nachweis, dass die
// zugrunde liegende Skript-Generierung selbst nach wie vor korrekt
// funktioniert, sobald is_pro() true ist.
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'pixel_enabled'    => 0,
		'google_enabled'   => 1,
		'google_tag_id'    => 'AW-999999999',
		'google_consent_mode' => 0,
		'tiktok_enabled'   => 1,
		'tiktok_pixel_id'  => 'C999XYZ000',
		'consent_detection' => 0,
	)
);
$_SERVER['REQUEST_URI'] = '/pro-google-tiktok-test/';
$html_pro = run_frontend();
check( 'Pro: Google-Skript wird ausgegeben (gtag config)', false !== strpos( $html_pro, "gtag('config','AW-999999999')" ) );
check( 'Pro: TikTok-Skript wird ausgegeben (ttq.load)', false !== strpos( $html_pro, "ttq.load('C999XYZ000')" ) );

$GLOBALS['stub']['options']['pms_settings'] = array();

// PMS_Logger::retention_days() in Pro: liest das gespeicherte Setting statt
// des Free-Fixwerts, fällt bei einem Wert außerhalb der Whitelist (z. B. nach
// einem Downgrade+Upgrade mit verändertem Rohwert) auf den Default zurück.
$GLOBALS['stub']['options']['pms_settings']['log_retention_days'] = 30;
check( 'retention_days(): Pro liest den gespeicherten Wert (30)', 30 === PMS_Logger::retention_days() );

$GLOBALS['stub']['options']['pms_settings']['log_retention_days'] = 5; // nicht in der Whitelist.
check( 'retention_days(): Pro fällt bei ungültigem Wert auf den Default (7) zurück', 7 === PMS_Logger::retention_days() );

$GLOBALS['stub']['options']['pms_settings']['log_retention_days'] = 14;
$GLOBALS['wpdb']->rows                                             = array();
PMS_Logger::record( 'Lead', 'old-evt', 'capi', 200, array(), '' );
$GLOBALS['wpdb']->rows[ PMS_Logger::table_name() ][0]['created_at'] = gmdate( 'Y-m-d H:i:s', strtotime( '-20 days' ) );
PMS_Logger::record( 'Lead', 'new-evt', 'capi', 200, array(), '' );
PMS_Logger::cleanup_old_entries();
$remaining = PMS_Logger::get_entries();
check( 'cleanup_old_entries(): Eintrag älter als die Retention (20 Tage bei 14 Tagen Limit) wird gelöscht', 1 === count( $remaining ) );
check( 'cleanup_old_entries(): der verbleibende Eintrag ist der neue', 'new-evt' === ( $remaining[0]['event_id'] ?? '' ) );
// v0.7.0: ein einziges, per $wpdb->prepare() vorbereitetes DELETE über den
// created_at-Index statt "alle Zeilen holen + einzeln löschen".
check(
	'cleanup_old_entries(): EIN vorbereitetes DELETE ... WHERE created_at < \'<GMT>\' (kein roher Platzhalter, kein SELECT-all)',
	1 === preg_match( "/^DELETE FROM wp_pms_event_log WHERE created_at < '\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}'$/", $GLOBALS['wpdb']->last_query ),
	$GLOBALS['wpdb']->last_query
);
$cutoff_expected = gmdate( 'Y-m-d', strtotime( '-14 days' ) );
check( 'cleanup_old_entries(): Stichtag = heute minus Retention (GMT)', false !== strpos( $GLOBALS['wpdb']->last_query, "'" . $cutoff_expected ) );

$GLOBALS['wpdb']->rows = array();

echo "\n=== 18. WooCommerce-Tracking (Pro): Produktdaten-Extraktion & CAPI-Anbindung ===\n";

function wc_test_reset() {
	$GLOBALS['stub']['wc'] = array(
		'currency' => 'EUR',
		'prices'   => array(),
		'terms'    => array(),
	);
}

// 18a. Simple Product: alle Felder, content_id-Modus "id" (Default).
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge( PMS_Settings::get(), array( 'wc_content_id_type' => 'id' ) );
$GLOBALS['stub']['wc']['prices'][101]       = 19.99;
$GLOBALS['stub']['wc']['terms'][101]        = array( (object) array( 'name' => 'Apparel' ) );
$simple = new WC_Product( array( 'id' => 101, 'name' => 'T-Shirt', 'sku' => 'TSHIRT-1' ) );
$data   = PMS_Pro_Woo_Product_Data::get_product_data( $simple, 3 );

check( 'Simple Product: content_id ist die ID (Default-Modus)', '101' === $data['content_id'] );
check( 'Simple Product: content_name', 'T-Shirt' === $data['content_name'] );
check( 'Simple Product: content_category aus get_the_terms()', 'Apparel' === $data['content_category'] );
check( 'Simple Product: value ist der Einzelpreis (NICHT mit qty multipliziert)', 19.99 === $data['value'] );
check( 'Simple Product: currency aus get_woocommerce_currency()', 'EUR' === $data['currency'] );
check( 'Simple Product: quantity wird durchgereicht', 3 === $data['quantity'] );

// 18b. content_id_type = "sku": SKU vorhanden -> SKU statt ID.
$GLOBALS['stub']['options']['pms_settings']['wc_content_id_type'] = 'sku';
$data_sku                                                          = PMS_Pro_Woo_Product_Data::get_product_data( $simple, 1 );
check( 'content_id_type=sku: content_id ist die SKU, wenn vorhanden', 'TSHIRT-1' === $data_sku['content_id'] );

// 18c. content_id_type = "sku", aber Produkt hat KEINE SKU -> Fallback auf ID.
$no_sku    = new WC_Product( array( 'id' => 102, 'name' => 'Ohne SKU', 'sku' => '' ) );
$data_none = PMS_Pro_Woo_Product_Data::get_product_data( $no_sku, 1 );
check( 'content_id_type=sku: Fallback auf ID, wenn die SKU leer ist', '102' === $data_none['content_id'] );
$GLOBALS['stub']['options']['pms_settings']['wc_content_id_type'] = 'id';

// 18d. Variation (Variable Product): Kategorie hängt am Elternprodukt, nicht
// an der Variation selbst -- get_parent_id() muss für die Kategorie-Auflösung
// genutzt werden.
$GLOBALS['stub']['wc']['terms'][200] = array( (object) array( 'name' => 'Shoes' ) );
$variation                            = new WC_Product_Variation( array( 'id' => 201, 'parent_id' => 200, 'name' => 'Sneaker - Blue, 42', 'sku' => '' ) );
$GLOBALS['stub']['wc']['prices'][201] = 89.5;
$data_variation                       = PMS_Pro_Woo_Product_Data::get_product_data( $variation, 2 );
check( 'Variation: content_id ist die Variations-ID, nicht die Parent-ID', '201' === $data_variation['content_id'] );
check( 'Variation: content_category kommt vom Elternprodukt (get_parent_id())', 'Shoes' === $data_variation['content_category'] );
check( 'Variation: content_name enthält die Variationsbezeichnung', 'Sneaker - Blue, 42' === $data_variation['content_name'] );

// 18e. Kein zugewiesener Term -> leerer String, kein Fehler/Notice.
$uncategorized      = new WC_Product( array( 'id' => 103, 'name' => 'Ohne Kategorie' ) );
$data_uncategorized = PMS_Pro_Woo_Product_Data::get_product_data( $uncategorized, 1 );
check( 'Kein Term zugewiesen: content_category ist ein leerer String', '' === $data_uncategorized['content_category'] );

// 18f. Andere Shop-Währung wird durchgereicht.
$GLOBALS['stub']['wc']['currency'] = 'USD';
$data_usd                          = PMS_Pro_Woo_Product_Data::get_product_data( $simple, 1 );
check( 'currency spiegelt get_woocommerce_currency() wider (USD)', 'USD' === $data_usd['currency'] );
$GLOBALS['stub']['wc']['currency'] = 'EUR';

// 18g. Ungültiges Produkt -> leeres Array statt Fehler.
check( 'get_product_data(): null ist kein WC_Product -> leeres Array', array() === PMS_Pro_Woo_Product_Data::get_product_data( null, 1 ) );
check( 'get_product_data(): stdClass ist kein WC_Product -> leeres Array', array() === PMS_Pro_Woo_Product_Data::get_product_data( new stdClass(), 1 ) );

// 18h. PMS_Pro_WooCommerce::enabled(): Pro + WooCommerce-Klasse (siehe Stub
// oben) + wc_tracking_enabled.
$GLOBALS['stub']['options']['pms_settings']['wc_tracking_enabled'] = 0;
check( 'enabled(): false, wenn wc_tracking_enabled aus ist', false === PMS_Pro_WooCommerce::enabled() );
$GLOBALS['stub']['options']['pms_settings']['wc_tracking_enabled'] = 1;
check( 'enabled(): true, wenn Pro + WooCommerce + wc_tracking_enabled aktiv sind', true === PMS_Pro_WooCommerce::enabled() );

// 18i. filter_capi_event_data(): mischt pms_woo_custom_data in payload['custom_data'],
// ohne bereits vorhandene Felder (z. B. aus PMS_Pro_UTM::custom_data()) zu verlieren.
$payload_unrelated = PMS_Pro_WooCommerce::filter_capi_event_data( array( 'event_name' => 'ViewContent' ), array( 'id' => 'x' ) );
check( 'filter_capi_event_data(): Payload bleibt unverändert ohne pms_woo_custom_data-Schlüssel', ! isset( $payload_unrelated['custom_data'] ) );

$payload_with_utm = array(
	'event_name'  => 'AddToCart',
	'custom_data' => array( 'utm_source' => 'newsletter' ),
);
$event_with_woo_data = array(
	'pms_woo_custom_data' => array( 'content_ids' => array( '101' ), 'value' => 19.99 ),
);
$merged = PMS_Pro_WooCommerce::filter_capi_event_data( $payload_with_utm, $event_with_woo_data );
check( 'filter_capi_event_data(): bestehende custom_data (z. B. UTM) bleibt erhalten', 'newsletter' === ( $merged['custom_data']['utm_source'] ?? null ) );
check( 'filter_capi_event_data(): WooCommerce-custom_data wird eingemischt', array( '101' ) === ( $merged['custom_data']['content_ids'] ?? null ) );

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 19. WooCommerce Purchase-Tracking (Pro): Order-Extraktion, Advanced Matching, Dedup ===\n";

/**
 * Test-Order mit zwei Positionen (eine mit noch existierendem Produkt, eine
 * mit gelöschtem Produkt -- deckt den content_id-Fallback auf
 * item->get_product_id() ab) + vollständiger Rechnungsadresse.
 */
function make_test_order( $overrides = array() ) {
	$product = new WC_Product( array( 'id' => 501, 'name' => 'Sneaker', 'sku' => 'SNK-1' ) );
	$GLOBALS['stub']['wc']['terms'][501] = array( (object) array( 'name' => 'Shoes' ) );

	$item1 = new WC_Order_Item_Product( array( 'quantity' => 2, 'total' => 40.00, 'product' => $product, 'product_id' => 501 ) );
	$item2 = new WC_Order_Item_Product( array( 'quantity' => 1, 'total' => 9.99, 'product' => null, 'product_id' => 777 ) );

	$defaults = array(
		'id'                 => 1001,
		'items'              => array( $item1, $item2 ),
		'total'              => 55.00,
		'total_tax'          => 5.00,
		'shipping_total'     => 4.99,
		'currency'           => 'EUR',
		'billing_email'      => 'kunde@example.com',
		'billing_phone'      => '+49 176 1234567',
		'billing_first_name' => 'Erika',
		'billing_last_name'  => 'Musterfrau',
		'billing_address_1'  => 'Musterstraße 1',
		'billing_city'       => 'New York',
		'billing_state'      => 'NY',
		'billing_postcode'   => '10001',
		'billing_country'    => 'US',
	);

	$order = new WC_Order( array_merge( $defaults, $overrides ) );
	$GLOBALS['stub']['wc_orders'][ $order->get_id() ] = $order;

	return $order;
}

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array( 'wc_tracking_enabled' => 1, 'wc_purchase_value_type' => 'gross', 'wc_purchase_advanced_matching' => 0 )
);

/* --- 19a. PMS_CAPI::hash_field() --- */

check( 'hash_field(): lowercase + trim vor dem Hash', hash( 'sha256', 'erika' ) === PMS_CAPI::hash_field( ' Erika ' ) );
check( 'hash_field(): strip_spaces entfernt alle Leerzeichen (city/zip)', hash( 'sha256', 'newyork' ) === PMS_CAPI::hash_field( 'New York', true ) );
check( 'hash_field(): ohne strip_spaces bleiben Leerzeichen erhalten', hash( 'sha256', 'new york' ) === PMS_CAPI::hash_field( 'New York', false ) );
check( 'hash_field(): leerer Wert -> leerer String', '' === PMS_CAPI::hash_field( '   ' ) );

/* --- 19b. build_order_custom_data(): Positionen, Netto/Brutto, Steuer/Versand --- */

$order = make_test_order();
$data  = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_custom_data', $order );

check( 'build_order_custom_data(): content_ids enthält beide Positionen', array( '501', '777' ) === $data['content_ids'] );
check( 'build_order_custom_data(): content_id der gelöschten Position fällt auf get_product_id() zurück', '777' === $data['contents'][1]['id'] );
check( 'build_order_custom_data(): item_price = Line-Total / Menge (40.00 / 2 = 20.00)', 20.00 === $data['contents'][0]['item_price'] );
check( 'build_order_custom_data(): quantity je Position korrekt', 2 === $data['contents'][0]['quantity'] && 1 === $data['contents'][1]['quantity'] );
check( 'build_order_custom_data(): value = Brutto (Default), also order->get_total()', 55.00 === $data['value'] );
check( 'build_order_custom_data(): currency aus der Bestellung', 'EUR' === $data['currency'] );
check( 'build_order_custom_data(): num_items = Summe aller Mengen (2+1=3)', 3 === $data['num_items'] );
check( 'build_order_custom_data(): tax wird extrahiert', 5.00 === $data['tax'] );
check( 'build_order_custom_data(): shipping wird extrahiert', 4.99 === $data['shipping'] );

$GLOBALS['stub']['options']['pms_settings']['wc_purchase_value_type'] = 'net';
$data_net = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_custom_data', $order );
check( 'build_order_custom_data(): value = Netto, wenn konfiguriert (55.00 - 5.00 = 50.00)', 50.00 === $data_net['value'] );
$GLOBALS['stub']['options']['pms_settings']['wc_purchase_value_type'] = 'gross';

$empty_order = make_test_order( array( 'id' => 1002, 'items' => array() ) );
check( 'build_order_custom_data(): Bestellung ohne Positionen -> null', null === call_private( 'PMS_Pro_Woo_Purchase', 'build_order_custom_data', $empty_order ) );

/* --- 19c. build_order_user_data(): Advanced Matching, gated + normalisiert --- */

$user_data_off = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_user_data', $order );
check( 'build_order_user_data(): leeres Array, wenn wc_purchase_advanced_matching aus ist', array() === $user_data_off );

$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 1;
$user_data = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_user_data', $order );

check( 'build_order_user_data(): em gehasht (PMS_CAPI::hash_email())', array( PMS_CAPI::hash_email( 'kunde@example.com' ) ) === $user_data['em'] );
check( 'build_order_user_data(): ph gehasht (PMS_CAPI::hash_phone())', array( PMS_CAPI::hash_phone( '+49 176 1234567' ) ) === $user_data['ph'] );
check( 'build_order_user_data(): fn gehasht', array( PMS_CAPI::hash_field( 'Erika' ) ) === $user_data['fn'] );
check( 'build_order_user_data(): ln gehasht', array( PMS_CAPI::hash_field( 'Musterfrau' ) ) === $user_data['ln'] );
check( 'build_order_user_data(): ct gehasht MIT entfernten Leerzeichen ("New York" -> "newyork")', array( hash( 'sha256', 'newyork' ) ) === $user_data['ct'] );
check( 'build_order_user_data(): st gehasht', array( PMS_CAPI::hash_field( 'NY' ) ) === $user_data['st'] );
check( 'build_order_user_data(): zp gehasht MIT entfernten Leerzeichen', array( hash( 'sha256', '10001' ) ) === $user_data['zp'] );
check( 'build_order_user_data(): country gehasht', array( PMS_CAPI::hash_field( 'US' ) ) === $user_data['country'] );

$order_no_phone = make_test_order( array( 'id' => 1003, 'billing_phone' => '' ) );
$user_data_partial = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_user_data', $order_no_phone );
check( 'build_order_user_data(): leere Felder (hier: Telefon) werden nicht in user_data aufgenommen', ! array_key_exists( 'ph', $user_data_partial ) );

$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 0;

/* --- 19d. event_id(): deterministisch --- */

check( 'event_id(): Format "pms_order_{id}"', 'pms_order_1001' === call_private( 'PMS_Pro_Woo_Purchase', 'event_id', 1001 ) );

/* --- 19e. already_tracked()/mark_tracked(): HPOS-sichere WC_Order-Meta-API --- */

$dedup_order = make_test_order( array( 'id' => 1004 ) );
check( 'already_tracked(): frische Bestellung ist noch nicht getrackt', false === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $dedup_order ) );

call_private( 'PMS_Pro_Woo_Purchase', 'mark_tracked', $dedup_order );
check( 'mark_tracked(): setzt _pms_purchase_tracked über update_meta_data()', 1 === $dedup_order->get_meta( PMS_Pro_Woo_Purchase::TRACKED_META_KEY ) );
check( 'mark_tracked(): ruft save() auf (HPOS-sicher, kein direkter update_post_meta()-Zugriff)', true === $dedup_order->saved );
check( 'already_tracked(): erkennt die eben gesetzte Markierung', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $dedup_order ) );

/* --- 19f. should_process()/enabled(): Gating --- */

$GLOBALS['stub']['options']['pms_settings']['wc_tracking_enabled'] = 0;
check( 'enabled(): false, wenn wc_tracking_enabled aus ist (Purchase hat keinen eigenen Master-Toggle)', false === PMS_Pro_Woo_Purchase::enabled() );
$GLOBALS['stub']['options']['pms_settings']['wc_tracking_enabled'] = 1;
check( 'enabled(): true, wenn Pro + WooCommerce + wc_tracking_enabled aktiv sind', true === PMS_Pro_Woo_Purchase::enabled() );

$GLOBALS['stub']['options']['pms_settings']['exclude_admins'] = 1;
$GLOBALS['stub']['current_user_can'] = true;
check( 'should_process(): false, wenn exclude_admins aktiv und der aktuelle Nutzer Admin ist', false === call_private( 'PMS_Pro_Woo_Purchase', 'should_process' ) );
$GLOBALS['stub']['current_user_can'] = false;
check( 'should_process(): true, wenn exclude_admins aktiv, aber kein Admin eingeloggt ist (z. B. Zahlungs-Webhook)', true === call_private( 'PMS_Pro_Woo_Purchase', 'should_process' ) );
$GLOBALS['stub']['options']['pms_settings']['exclude_admins'] = 0;

$GLOBALS['stub']['filters']['pms_allow_tracking'] = function () {
	return false;
};
check( 'should_process(): respektiert das pms_allow_tracking-Filter', false === call_private( 'PMS_Pro_Woo_Purchase', 'should_process' ) );
unset( $GLOBALS['stub']['filters']['pms_allow_tracking'] );

/* --- 19g. Ende-zu-Ende: track_thankyou()/maybe_track_fallback() inkl. Dedup
 * über beide Auslösewege hinweg, mit dem ECHTEN pms_capi_event_data-Filter
 * aus PMS_Pro_WooCommerce (siehe dortige Registrierung in init() -- hier
 * direkt registriert statt init() aufzurufen, um keine WooCommerce-Hooks
 * mitzuregistrieren, die dieser Test nicht braucht). Wird in Abschnitt 23
 * durch dieselbe Registrierung für PMS_Pro_SureCart abgelöst (der
 * add_filter()-Stub hält nur einen Callback pro Tag) -- unproblematisch, da
 * nichts zwischen hier und dort den WooCommerce-Zweig dieses Filters mehr
 * braucht. --- */

add_filter( 'pms_capi_event_data', array( 'PMS_Pro_WooCommerce', 'filter_capi_event_data' ) );

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled' => 1,
		'pixel_enabled'       => 1,
		'pixel_id'            => '1234567890',
		'capi_enabled'        => 1,
		'capi_token'          => 'test-token',
		'consent_detection'   => 0, // Consent gilt als erteilt (kein Banner-Plugin aktiv).
	)
);

$e2e_order = make_test_order( array( 'id' => 1005 ) );
$GLOBALS['stub']['captured_posts'] = array();

ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $e2e_order->get_id() );
$browser_output = ob_get_clean();

check( 'track_thankyou(): Browser-Pixel-Skript wird ausgegeben', false !== strpos( $browser_output, "fbq('track','Purchase'" ) );
check( 'track_thankyou(): eventID im Browser-Skript ist die deterministische ID', false !== strpos( $browser_output, "eventID:'pms_order_1005'" ) );

check( 'track_thankyou(): löst genau einen CAPI-Request aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
$sent_body = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'], true );
check( 'track_thankyou(): event_id im CAPI-Payload ist die deterministische ID', 'pms_order_1005' === $sent_body['data'][0]['event_id'] );
check( 'track_thankyou(): event_name ist "Purchase"', 'Purchase' === $sent_body['data'][0]['event_name'] );
check( 'track_thankyou(): custom_data kommt über den PMS_Pro_WooCommerce-Filter tatsächlich im Payload an', array( '501', '777' ) === ( $sent_body['data'][0]['custom_data']['content_ids'] ?? null ) );
check( 'track_thankyou(): markiert die Bestellung als getrackt', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $e2e_order ) );

// Erneuter Aufruf derselben Danke-Seite (z. B. F5) -> kein zweiter Request.
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $e2e_order->get_id() );
ob_end_clean();
check( 'track_thankyou(): wiederholter Aufruf derselben Bestellung löst KEINEN weiteren CAPI-Request aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );

// Server-Side-Fallback für DIESELBE (bereits über die Danke-Seite getrackte) Bestellung -> ebenfalls kein weiterer Request.
PMS_Pro_Woo_Purchase::maybe_track_fallback( $e2e_order->get_id() );
check( 'maybe_track_fallback(): greift NICHT, wenn die Danke-Seite dieselbe Bestellung bereits getrackt hat', 1 === count( $GLOBALS['stub']['captured_posts'] ) );

// Fallback-Pfad eigenständig (Bestellung, die NIE die Danke-Seite erreicht hat).
$fallback_order = make_test_order( array( 'id' => 1006 ) );
PMS_Pro_Woo_Purchase::maybe_track_fallback( $fallback_order->get_id() );
check( 'maybe_track_fallback(): trackt eigenständig eine Bestellung ohne vorherigen Danke-Seiten-Aufruf', 2 === count( $GLOBALS['stub']['captured_posts'] ) );
check( 'maybe_track_fallback(): markiert die Bestellung ebenfalls als getrackt', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $fallback_order ) );
$fallback_body = json_decode( $GLOBALS['stub']['captured_posts'][1]['args']['body'], true );
check( 'maybe_track_fallback(): source im Event Log ist "capi" (kein Browser-Dispatch)', 'capi' === PMS_Logger::get_entries( array( 'limit' => 1 ) )[0]['source'] );

$GLOBALS['stub']['captured_posts'] = array();
$GLOBALS['stub']['wc_orders']      = array();
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 20. Admin-Menü-Umstrukturierung (seit v0.6.4): Submenü-Registrierungen + Sidebar-Shortcuts ===\n";

$GLOBALS['stub']['registered_menu_pages']    = array();
$GLOBALS['stub']['registered_submenu_pages'] = array();
$GLOBALS['stub']['current_user_can']         = true; // render_page() prüft current_user_can( CAPABILITY ).
$GLOBALS['stub']['options']['pms_events']    = array();

PMS_Admin::register_menu();

$submenu_slugs = array_column( $GLOBALS['stub']['registered_submenu_pages'], 'menu_slug' );
check( 'register_menu(): registriert das Hauptmenü', 1 === count( $GLOBALS['stub']['registered_menu_pages'] ) );
check( 'register_menu(): "Event Log" wird als eigener Sidebar-Submenüpunkt registriert', in_array( PMS_Admin::EVENT_LOG_SLUG, $submenu_slugs, true ) );
check( 'register_menu(): "Import / Export" wird als eigener Sidebar-Submenüpunkt registriert', in_array( PMS_Admin::IMPORT_EXPORT_SLUG, $submenu_slugs, true ) );

$find_submenu = static function ( $slug ) {
	foreach ( $GLOBALS['stub']['registered_submenu_pages'] as $entry ) {
		if ( $slug === $entry['menu_slug'] ) {
			return $entry;
		}
	}
	return null;
};

$event_log_entry      = $find_submenu( PMS_Admin::EVENT_LOG_SLUG );
$import_export_entry  = $find_submenu( PMS_Admin::IMPORT_EXPORT_SLUG );

check( 'Event-Log-Submenü: hängt am Hauptmenü (PAGE_SLUG) statt an einer eigenen Top-Level-Seite', PMS_Admin::PAGE_SLUG === ( $event_log_entry['parent_slug'] ?? null ) );
check( 'Event-Log-Submenü: Menü-Titel ist "Event Log"', 'Event Log' === ( $event_log_entry['menu_title'] ?? null ) );
check( 'Event-Log-Submenü: dieselbe Capability wie die Haupt-Seite', PMS_Admin::CAPABILITY === ( $event_log_entry['capability'] ?? null ) );
check( 'Import-Export-Submenü: Menü-Titel ist "Import / Export" (nicht mehr "Tools"/"Werkzeuge")', 'Import / Export' === ( $import_export_entry['menu_title'] ?? null ) );
check( 'Import-Export-Submenü: hängt ebenfalls am Hauptmenü', PMS_Admin::PAGE_SLUG === ( $import_export_entry['parent_slug'] ?? null ) );

// enqueue_assets() muss auch für die beiden neuen Hook-Suffixe greifen (siehe
// dortige Erweiterung von einer Zwei- auf eine Vier-Wege-Prüfung), sonst
// laden admin.css/admin.js auf den neuen Sidebar-Seiten nicht.
$GLOBALS['stub']['enqueued_scripts'] = array();
PMS_Admin::enqueue_assets( $event_log_entry['hook'] );
check( 'enqueue_assets(): lädt Assets auch auf dem neuen Event-Log-Sidebar-Hook', in_array( 'pms-admin', $GLOBALS['stub']['enqueued_scripts'], true ) );

$GLOBALS['stub']['enqueued_scripts'] = array();
PMS_Admin::enqueue_assets( $import_export_entry['hook'] );
check( 'enqueue_assets(): lädt Assets auch auf dem neuen Import/Export-Sidebar-Hook', in_array( 'pms-admin', $GLOBALS['stub']['enqueued_scripts'], true ) );

$GLOBALS['stub']['enqueued_scripts'] = array();
PMS_Admin::enqueue_assets( 'irgendein_fremder_hook' );
check( 'enqueue_assets(): lädt weiterhin NICHT auf fremden Admin-Seiten', array() === $GLOBALS['stub']['enqueued_scripts'] );

/**
 * Extrahiert nur den <nav class="nav-tab-wrapper">...</nav>-Ausschnitt aus
 * einem render_page()-Output, damit die Abschnitt-21-Assertions gezielt die
 * TAB-LEISTE prüfen können statt zufällig auf denselben Text im übrigen
 * Seiteninhalt zu treffen.
 */
function extract_nav( $html ) {
	$start = strpos( $html, '<nav' );
	$end   = strpos( $html, '</nav>' );
	if ( false === $start || false === $end ) {
		return '';
	}
	return substr( $html, $start, $end - $start );
}

/* --- Sidebar-Shortcuts: wählen den richtigen Tab vor und rendern die
 * normale Haupt-Seite (kein eigenes Markup, siehe Klassen-Doku dort). Seit
 * v0.6.5 stehen "Event Log"/"Import / Export" NICHT mehr in der oberen
 * Tab-Leiste (siehe Abschnitt 21) -- die Shortcuts selbst funktionieren
 * trotzdem weiter, nur ohne eine "aktive" Markierung in der (jetzt kleineren)
 * Leiste, da keiner ihrer vier verbliebenen Einträge zu tab=log/tools passt. --- */

unset( $_GET['tab'] );
ob_start();
PMS_Admin::render_import_export_shortcut();
$import_export_output = ob_get_clean();
check( 'render_import_export_shortcut(): setzt tab=tools', 'tools' === ( $_GET['tab'] ?? null ) );
check( 'render_import_export_shortcut(): rendert den Import/Export-Tab-Inhalt (nicht "Global Options" von Tab "Allgemein")', false !== strpos( $import_export_output, 'Export configuration' ) && false === strpos( $import_export_output, 'Global Options' ) );
check( 'render_import_export_shortcut(): v0.6.5 -- KEIN Tab in der (jetzt kleineren) Leiste ist mehr "aktiv" markiert, da "Import / Export" dort nicht mehr auftaucht', false === strpos( extract_nav( $import_export_output ), 'nav-tab-active' ) );

unset( $_GET['tab'] );
ob_start();
PMS_Admin::render_event_log_shortcut();
$event_log_output = ob_get_clean();
check( 'render_event_log_shortcut(): setzt tab=log', 'log' === ( $_GET['tab'] ?? null ) );
check( 'render_event_log_shortcut(): rendert tatsächlich den Event-Log-Tab (PMS_Admin_Event_Log::render_tab())', false !== strpos( $event_log_output, 'Clear log' ) );
check( 'render_event_log_shortcut(): v0.6.5 -- ebenfalls kein "aktiver" Tab mehr in der Leiste', false === strpos( extract_nav( $event_log_output ), 'nav-tab-active' ) );

echo "\n=== 21. Tab-Bereinigung (seit v0.6.5): Event Log/Import-Export raus aus der oberen Leiste, E-Commerce immer sichtbar ===\n";

$GLOBALS['stub']['current_user_can'] = true;
unset( $_GET['tab'] );
ob_start();
PMS_Admin::render_page();
$general_output = ob_get_clean();
$general_nav    = extract_nav( $general_output );

check( 'Tab-Leiste enthält weiterhin "General"', false !== strpos( $general_nav, 'General' ) );
check( 'Tab-Leiste enthält weiterhin "URL Events"', false !== strpos( $general_nav, 'URL Events' ) );
check( 'Tab-Leiste enthält weiterhin "Advanced Tracking"', false !== strpos( $general_nav, 'Advanced Tracking' ) );
check( 'Tab-Leiste enthält "E-Commerce" (WooCommerce ist in diesem Harness immer aktiv, siehe Stub-Doku oben)', false !== strpos( $general_nav, 'E-Commerce' ) );
check( 'Tab-Leiste enthält "Event Log" NICHT mehr (jetzt reiner Sidebar-Shortcut)', false === strpos( $general_nav, 'Event Log' ) );
check( 'Tab-Leiste enthält "Import / Export" NICHT mehr (jetzt reiner Sidebar-Shortcut)', false === strpos( $general_nav, 'Import / Export' ) );
check( 'Normale Tab-Navigation funktioniert weiterhin: "General" ist als aktiv markiert', false !== strpos( $general_nav, 'nav-tab-active' ) );

// direkter ?tab=log/?tab=tools-Aufruf (z. B. ein alter Bookmark) muss trotz
// Entfernens aus der Leiste weiterhin den richtigen Tab-INHALT rendern --
// die Slugs bleiben über $valid_tabs gültig (siehe render_page()), nur die
// Navigation selbst verlinkt sie nicht mehr.
$_GET['tab'] = 'tools';
ob_start();
PMS_Admin::render_page();
$direct_tools_output = ob_get_clean();
check( 'Direkter ?tab=tools-Aufruf (z. B. alter Bookmark) rendert weiterhin den Import/Export-Inhalt', false !== strpos( $direct_tools_output, 'Export configuration' ) );

// render_ecommerce_tab() bisher nie über render_page() aufgerufen (siehe
// CLAUDE.md-Hinweis zu Abschnitt 20) -- WooCommerce ist in diesem Harness
// unconditional aktiv (siehe Stub-Doku oben), PMS_IS_PRO seit Abschnitt 17
// true, greift also der Pro+WooCommerce-Accordion-Zweig.
$_GET['tab'] = 'ecommerce';
ob_start();
PMS_Admin::render_page();
$ecommerce_output = ob_get_clean();
check( 'tab=ecommerce rendert die WooCommerce-Accordion (Pro+WooCommerce-Zweig), nicht den "nicht erkannt"-Hinweis', false !== strpos( $ecommerce_output, 'Enable WooCommerce tracking' ) && false === strpos( $ecommerce_output, 'was not detected' ) );
check( 'tab=ecommerce ist in der Leiste als aktiv markiert', false !== strpos( extract_nav( $ecommerce_output ), 'nav-tab-active' ) );
check( 'tab=ecommerce (seit v0.6.6) rendert das Google-Ads-Conversion-Label-Feld', false !== strpos( $ecommerce_output, 'pms_settings[wc_google_conversion_label]' ) );

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['registered_menu_pages']    = array();
$GLOBALS['stub']['registered_submenu_pages'] = array();
$GLOBALS['stub']['current_user_can']         = false;
$GLOBALS['stub']['enqueued_scripts']         = array();
$GLOBALS['stub']['options']['pms_settings']  = array();
$GLOBALS['stub']['options']['pms_events']    = array();

echo "\n=== 22. Multi-Platform-Erweiterung (v0.6.6): Google Ads Enhanced Conversions & TikTok Events API ===\n";

/* --- 22a. PMS_Settings::sanitize_settings(): tiktok_access_token (Erhalt-Muster wie capi_token),
 * tiktok_capi_enabled, wc_google_conversion_label --- */

$GLOBALS['stub']['options']['pms_settings'] = array( 'tiktok_access_token' => 'BESTEHENDES-TOKEN' );
$out_no_key = PMS_Settings::sanitize_settings( array( 'form_tracking' => '1' ) );
check( 'tiktok_access_token bleibt erhalten, wenn der Schlüssel im Input fehlt (z. B. anderer Tab)', 'BESTEHENDES-TOKEN' === $out_no_key['tiktok_access_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array( 'tiktok_access_token' => 'BESTEHENDES-TOKEN' );
$out_cleared = PMS_Settings::sanitize_settings( array( 'tiktok_access_token' => '' ) );
check( 'tiktok_access_token wird geleert, wenn explizit ein Leerstring übergeben wird (Tab "Allgemein")', '' === $out_cleared['tiktok_access_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array( 'tiktok_access_token' => 'ALT' );
$out_new = PMS_Settings::sanitize_settings( array( 'tiktok_access_token' => 'NEUER-TOKEN' ) );
check( 'tiktok_access_token wird überschrieben, wenn ein neuer Wert übergeben wird', 'NEUER-TOKEN' === $out_new['tiktok_access_token'] );

$GLOBALS['stub']['options']['pms_settings'] = array();

check( 'tiktok_capi_enabled: "1" im Input -> 1', 1 === PMS_Settings::sanitize_settings( array( 'tiktok_capi_enabled' => '1' ) )['tiktok_capi_enabled'] );
check( 'tiktok_capi_enabled: fehlt im Input -> 0', 0 === PMS_Settings::sanitize_settings( array() )['tiktok_capi_enabled'] );

check( 'wc_google_conversion_label: Default ist leer', '' === PMS_Settings::sanitize_settings( array() )['wc_google_conversion_label'] );
check( 'wc_google_conversion_label: alphanumerisch + _- bleibt erhalten, Leerzeichen entfernt (Whitelist wie beim per-Event google_label)', 'AbC-123_xyz' === PMS_Settings::sanitize_settings( array( 'wc_google_conversion_label' => ' AbC-123_xyz ' ) )['wc_google_conversion_label'] );
check( 'wc_google_conversion_label: Markup/Sonderzeichen werden entfernt (XSS-Schutz)', 'scriptalert1scriptabc' === PMS_Settings::sanitize_settings( array( 'wc_google_conversion_label' => '<script>alert(1)</script>abc' ) )['wc_google_conversion_label'] );

/* --- 22b. Google Ads: google_conversion_js() -- Conversion-Aufruf, Label-Gating, Enhanced Conversions --- */

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled'           => 1,
		'wc_purchase_value_type'        => 'gross',
		'wc_purchase_advanced_matching' => 0,
		'google_enabled'                => 1,
		'google_tag_id'                 => 'AW-123456789',
		'wc_google_conversion_label'    => 'AbCdEfGh',
	)
);

$g_order    = make_test_order( array( 'id' => 2001 ) );
$g_custom   = call_private( 'PMS_Pro_Woo_Purchase', 'build_order_custom_data', $g_order );
$g_settings = $GLOBALS['stub']['options']['pms_settings'];
$g_js       = call_private( 'PMS_Pro_Woo_Purchase', 'google_conversion_js', $g_order, $g_custom, $g_settings );

check( 'google_conversion_js(): gtag-Conversion-Aufruf enthalten', false !== strpos( $g_js, "window.gtag('event','conversion'" ) );
check( 'google_conversion_js(): send_to kombiniert Tag-ID und Label', false !== strpos( $g_js, '"send_to":"AW-123456789\/AbCdEfGh"' ) );
check( 'google_conversion_js(): transaction_id ist die ROHE Bestellnummer (seit v0.6.9), nicht die praefixierte Event-ID', false !== strpos( $g_js, '"transaction_id":"2001"' ) );
check( 'google_conversion_js(): value/currency aus custom_data', false !== strpos( $g_js, '"value":55' ) && false !== strpos( $g_js, '"currency":"EUR"' ) );
check( 'google_conversion_js(): ohne aktivierte Advanced Matching kein user_data', false === strpos( $g_js, 'user_data' ) );

$g_settings_no_label                               = $g_settings;
$g_settings_no_label['wc_google_conversion_label']  = '';
check( 'google_conversion_js(): leeres Label -> kein Aufruf (dieselbe Regel wie bei Google-URL-Events)', '' === call_private( 'PMS_Pro_Woo_Purchase', 'google_conversion_js', $g_order, $g_custom, $g_settings_no_label ) );

$g_settings_am                                   = $g_settings;
$g_settings_am['wc_purchase_advanced_matching']  = 1;
$g_js_am = call_private( 'PMS_Pro_Woo_Purchase', 'google_conversion_js', $g_order, $g_custom, $g_settings_am );
check( 'google_conversion_js(): mit Advanced Matching ist user_data enthalten', false !== strpos( $g_js_am, 'user_data' ) );

/* --- 22c. hash_google_phone(): E.164-Format, bewusst abweichend von Metas hash_phone() --- */

check( 'hash_google_phone(): E.164 mit führendem "+" (Metas hash_phone() hat kein "+")', PMS_CAPI::hash_field( '+491761234567' ) === call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '+49 176 1234567' ) );
check( 'hash_google_phone(): führende Null wird entfernt (wie bei Metas hash_phone())', call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '0176 1234567' ) === call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '176 1234567' ) );
check( 'hash_google_phone(): Ergebnis unterscheidet sich von Metas hash_phone() (unterschiedliche Normalisierung)', PMS_CAPI::hash_phone( '+49 176 1234567' ) !== call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '+49 176 1234567' ) );
check( 'hash_google_phone(): zu kurz -> leerer String', '' === call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '123' ) );
check( 'hash_google_phone(): leerer Wert -> leerer String', '' === call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '' ) );

/* --- 22d. build_google_user_data(): Enhanced-Conversions-Objekt -- email/phone/fn/ln/street
 * gehasht, city/region/postal_code/country laut Google-Doku im Klartext (siehe Klassen-Doku,
 * unverifiziert gegen echte Testdaten). --- */

$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 1;
$g_user_data = call_private( 'PMS_Pro_Woo_Purchase', 'build_google_user_data', $g_order );

check( 'build_google_user_data(): email gehasht (Metas hash_email(), wiederverwendet)', PMS_CAPI::hash_email( 'kunde@example.com' ) === $g_user_data['email'] );
check( 'build_google_user_data(): phone_number im E.164-Hash-Format', call_private( 'PMS_Pro_Woo_Purchase', 'hash_google_phone', '+49 176 1234567' ) === $g_user_data['phone_number'] );
check( 'build_google_user_data(): address ist ein Array mit genau einem Objekt', 1 === count( $g_user_data['address'] ) );
check( 'build_google_user_data(): first_name/last_name/street sind gehasht', PMS_CAPI::hash_field( 'Erika' ) === $g_user_data['address'][0]['first_name'] && PMS_CAPI::hash_field( 'Musterfrau' ) === $g_user_data['address'][0]['last_name'] && PMS_CAPI::hash_field( 'Musterstraße 1' ) === $g_user_data['address'][0]['street'] );
check( 'build_google_user_data(): city/region/postal_code/country bleiben Klartext (nur first_name/last_name/street/email/phone sind Hash-Felder)', 'New York' === $g_user_data['address'][0]['city'] && 'NY' === $g_user_data['address'][0]['region'] && '10001' === $g_user_data['address'][0]['postal_code'] && 'US' === $g_user_data['address'][0]['country'] );

$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 0;
check( 'build_google_user_data(): ohne Advanced Matching dennoch aufrufbar (Gating sitzt in google_conversion_js(), nicht hier) -- email weiterhin enthalten', '' !== call_private( 'PMS_Pro_Woo_Purchase', 'build_google_user_data', $g_order )['email'] );

/* --- 22e. TikTok Events API: dispatch_tiktok_capi() via track_thankyou() (Ende-zu-Ende,
 * derselbe Aufbau wie 19g) --- */

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled'            => 1,
		'capi_enabled'                   => 0, // Meta bewusst aus, damit nur der TikTok-Request in captured_posts landet.
		'consent_detection'              => 0, // Consent gilt als erteilt (kein Banner-Plugin aktiv).
		'tiktok_enabled'                 => 1,
		'tiktok_pixel_id'                => 'ABCD1234',
		'tiktok_capi_enabled'            => 1,
		'tiktok_access_token'            => 'tt-secret-token',
		'wc_purchase_advanced_matching'  => 0,
	)
);

$tt_order = make_test_order( array( 'id' => 2101 ) );
$GLOBALS['stub']['captured_posts'] = array();

ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order->get_id() );
ob_end_clean();

check( 'dispatch_tiktok_capi(): löst genau einen Request an die TikTok Events API aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
$tt_call = $GLOBALS['stub']['captured_posts'][0];
check( 'dispatch_tiktok_capi(): URL ist der TikTok-Events-API-Endpoint (v1.3)', 'https://business-api.tiktok.com/open_api/v1.3/event/track/' === $tt_call['url'] );
check( 'dispatch_tiktok_capi(): Access-Token-Header gesetzt', 'tt-secret-token' === $tt_call['args']['headers']['Access-Token'] );

$tt_body = json_decode( $tt_call['args']['body'], true );
check( 'dispatch_tiktok_capi(): event_source ist "web"', 'web' === $tt_body['event_source'] );
check( 'dispatch_tiktok_capi(): event_source_id ist die bereinigte Pixel-ID', 'ABCD1234' === $tt_body['event_source_id'] );
check( 'dispatch_tiktok_capi(): event ist "CompletePayment"', 'CompletePayment' === $tt_body['data'][0]['event'] );
check( 'dispatch_tiktok_capi(): event_id ist dieselbe deterministische ID wie bei Meta/Browser', 'pms_order_2101' === $tt_body['data'][0]['event_id'] );
check( 'dispatch_tiktok_capi(): properties.value/currency aus custom_data', 55 === $tt_body['data'][0]['properties']['value'] && 'EUR' === $tt_body['data'][0]['properties']['currency'] );
check( 'dispatch_tiktok_capi(): properties.contents enthält beide Positionen mit content_id/quantity/price', 2 === count( $tt_body['data'][0]['properties']['contents'] ) && '501' === $tt_body['data'][0]['properties']['contents'][0]['content_id'] );
check( 'dispatch_tiktok_capi(): ohne Advanced Matching kein email/phone im user-Objekt', ! isset( $tt_body['data'][0]['user']['email'] ) && ! isset( $tt_body['data'][0]['user']['phone'] ) );
check( 'dispatch_tiktok_capi(): ohne ttclid-Cookie kein ttclid im user-Objekt', ! isset( $tt_body['data'][0]['user']['ttclid'] ) );

// Advanced Matching an -> email/phone gehasht im user-Objekt (Metas hash_email()/hash_phone(), wiederverwendet).
$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 1;
$GLOBALS['stub']['captured_posts'] = array();
$tt_order2 = make_test_order( array( 'id' => 2102 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order2->get_id() );
ob_end_clean();
$tt_body2 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'], true );
check( 'dispatch_tiktok_capi(): mit Advanced Matching ist email gehasht enthalten', PMS_CAPI::hash_email( 'kunde@example.com' ) === $tt_body2['data'][0]['user']['email'] );
check( 'dispatch_tiktok_capi(): mit Advanced Matching ist phone gehasht enthalten', PMS_CAPI::hash_phone( '+49 176 1234567' ) === $tt_body2['data'][0]['user']['phone'] );
$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 0;

// ttclid aus dem Attribution-Cookie (PMS_Pro_UTM::ttclid()).
reset_attribution();
$_COOKIE['pms_attribution'] = json_encode( array( 'ttclid' => 'TTCLID-XYZ', 'ts' => 1700000000 ) );
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 1;
$GLOBALS['stub']['captured_posts'] = array();
$tt_order3 = make_test_order( array( 'id' => 2103 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order3->get_id() );
ob_end_clean();
$tt_body3 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'], true );
check( 'dispatch_tiktok_capi(): ttclid aus dem Attribution-Cookie landet im user-Objekt', 'TTCLID-XYZ' === $tt_body3['data'][0]['user']['ttclid'] );
reset_attribution();
$GLOBALS['stub']['options']['pms_settings']['utm_passthrough'] = 0;

// Gating: fehlender Access-Token -> kein Request.
$GLOBALS['stub']['options']['pms_settings']['tiktok_access_token'] = '';
$GLOBALS['stub']['captured_posts'] = array();
$tt_order4 = make_test_order( array( 'id' => 2104 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order4->get_id() );
ob_end_clean();
check( 'dispatch_tiktok_capi(): fehlender Access-Token -> kein Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
$GLOBALS['stub']['options']['pms_settings']['tiktok_access_token'] = 'tt-secret-token';

// Gating: tiktok_capi_enabled aus -> kein Request (der Browser-Pixel bliebe unabhängig davon aktiv).
$GLOBALS['stub']['options']['pms_settings']['tiktok_capi_enabled'] = 0;
$GLOBALS['stub']['captured_posts'] = array();
$tt_order5 = make_test_order( array( 'id' => 2105 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order5->get_id() );
ob_end_clean();
check( 'dispatch_tiktok_capi(): tiktok_capi_enabled aus -> kein Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
$GLOBALS['stub']['options']['pms_settings']['tiktok_capi_enabled'] = 1;

// Consent-Gating: derselbe Fail-closed-Grundsatz wie bei Meta CAPI (Abschnitt 16). Die WP-
// Consent-API hat in evaluate() höchste Priorität (siehe Abschnitt 6) und ist seit dort für
// den Rest des Prozesses als Stub aktiv ($GLOBALS['stub']['wp_consent'], Default seit
// Abschnitt 6 wieder "true") -- der einfachste deterministische Weg, hier "kein Consent"
// nachzustellen, ist deshalb dieses Flag, statt Banner-Cookie-Zustände nachzubauen, die von
// der WP-Consent-API ohnehin überstimmt würden. consent_detection muss dafür an sein, sonst
// greift has_marketing_consent()'s "Erkennung aus -> true"-Fast-Path VOR jeder Prüfung.
$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 1;
$GLOBALS['stub']['wp_consent'] = false;
reset_consent_cache();
check( 'Testaufbau: WP-Consent-API verweigert -> Consent false (Vorbedingung für den folgenden Gating-Test)', false === PMS_Consent::has_marketing_consent() );

$GLOBALS['stub']['captured_posts'] = array();
$tt_order6 = make_test_order( array( 'id' => 2106 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order6->get_id() );
ob_end_clean();
check( 'dispatch_tiktok_capi(): kein Marketing-Consent -> kein Request (derselbe Fail-closed-Grundsatz wie bei Meta CAPI)', 0 === count( $GLOBALS['stub']['captured_posts'] ) );

$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 0;
$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();

$GLOBALS['stub']['captured_posts']          = array();
$GLOBALS['stub']['wc_orders']               = array();
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 23. SureCart-Integration (v0.6.7): Product Data, Checkout-custom_data, Purchase (CAPI/Events API/Dedup) ===\n";

/* --- 23a. PMS_Settings: sanitize_settings()/Defaults/Helper für die fünf sc_*-Keys --- */

check( 'sc_tracking_enabled: Default ist 0', 0 === PMS_Settings::get()['sc_tracking_enabled'] );
check( 'sc_content_id_type: Default ist "id"', 'id' === PMS_Settings::get()['sc_content_id_type'] );
check( 'sc_purchase_value_type: Default ist "gross"', 'gross' === PMS_Settings::get()['sc_purchase_value_type'] );
check( 'sc_purchase_advanced_matching: Default ist 0 (Privacy-by-Default)', 0 === PMS_Settings::get()['sc_purchase_advanced_matching'] );

check( 'sanitize_settings(): sc_tracking_enabled "1" -> 1', 1 === PMS_Settings::sanitize_settings( array( 'sc_tracking_enabled' => '1' ) )['sc_tracking_enabled'] );
check( 'sanitize_settings(): sc_content_id_type "sku" bleibt erhalten', 'sku' === PMS_Settings::sanitize_settings( array( 'sc_content_id_type' => 'sku' ) )['sc_content_id_type'] );
check( 'sanitize_settings(): sc_content_id_type unbekannter Wert -> Fallback "id"', 'id' === PMS_Settings::sanitize_settings( array( 'sc_content_id_type' => 'xyz' ) )['sc_content_id_type'] );
check( 'sanitize_settings(): sc_purchase_value_type "net" bleibt erhalten', 'net' === PMS_Settings::sanitize_settings( array( 'sc_purchase_value_type' => 'net' ) )['sc_purchase_value_type'] );
check( 'sanitize_settings(): sc_google_conversion_label -- Markup wird entfernt (XSS-Schutz)', 'scriptalert1scriptabc' === PMS_Settings::sanitize_settings( array( 'sc_google_conversion_label' => '<script>alert(1)</script>abc' ) )['sc_google_conversion_label'] );

$GLOBALS['stub']['options']['pms_settings'] = array( 'sc_content_id_type' => 'sku', 'sc_purchase_value_type' => 'net' );
check( 'PMS_Settings::sc_content_id_type() liest den gespeicherten Wert', 'sku' === PMS_Settings::sc_content_id_type() );
check( 'PMS_Settings::sc_purchase_value_type() liest den gespeicherten Wert', 'net' === PMS_Settings::sc_purchase_value_type() );
$GLOBALS['stub']['options']['pms_settings'] = array();

/* --- 23b. PMS_Pro_SureCart_Product_Data::get_product_data() -- Content-ID-Modus,
 * Preisauflösung (embedded price -> prices-Collection -> metrics-Fallback), Währung,
 * Kategorie, Minor-Unit-Filter --- */

sc_test_reset();

$product_full = new \SureCart\Models\Product( array(
	'id'    => 'prod_full',
	'name'  => 'T-Shirt',
	'sku'   => 'TSHIRT-1',
	'price' => (object) array( 'amount' => 1999, 'currency' => 'eur' ),
) );

$GLOBALS['stub']['options']['pms_settings'] = array_merge( PMS_Settings::get(), array( 'sc_content_id_type' => 'id' ) );
$data_full = PMS_Pro_SureCart_Product_Data::get_product_data( $product_full, 3 );
check( 'get_product_data(): content_id ist die Produkt-ID (Default-Modus)', 'prod_full' === $data_full['content_id'] );
check( 'get_product_data(): content_name', 'T-Shirt' === $data_full['content_name'] );
check( 'get_product_data(): value aus embedded ->price->amount, Minor-Units-Umrechnung (/100)', 19.99 === $data_full['value'] );
check( 'get_product_data(): value ist der Einzelpreis (NICHT mit qty multipliziert)', 3 === $data_full['quantity'] );
check( 'get_product_data(): currency aus ->price->currency, großgeschrieben', 'EUR' === $data_full['currency'] );

$GLOBALS['stub']['options']['pms_settings']['sc_content_id_type'] = 'sku';
check( 'get_product_data(): content_id_type "sku" -- SKU vorhanden -> SKU statt ID', 'TSHIRT-1' === PMS_Pro_SureCart_Product_Data::get_product_data( $product_full, 1 )['content_id'] );

$product_no_sku = new \SureCart\Models\Product( array( 'id' => 'prod_nosku', 'name' => 'Ohne SKU', 'sku' => '', 'price' => (object) array( 'amount' => 500, 'currency' => 'usd' ) ) );
check( 'get_product_data(): content_id_type "sku", aber SKU leer -> Fallback auf Produkt-ID', 'prod_nosku' === PMS_Pro_SureCart_Product_Data::get_product_data( $product_no_sku, 1 )['content_id'] );
$GLOBALS['stub']['options']['pms_settings']['sc_content_id_type'] = 'id';

// Preis-Fallback-Kette: kein ->price, aber ->prices (Collection mit ->data[])
$product_prices_collection = new \SureCart\Models\Product( array(
	'id'     => 'prod_coll',
	'name'   => 'Aus Collection',
	'prices' => (object) array( 'data' => array( (object) array( 'amount' => 3200, 'currency' => 'gbp' ) ) ),
) );
$data_coll = PMS_Pro_SureCart_Product_Data::get_product_data( $product_prices_collection, 1 );
check( 'get_product_data(): Preis-Fallback über ->prices->data[0]->amount', 32.0 === $data_coll['value'] );
check( 'get_product_data(): Währungs-Fallback über ->prices->data[0]->currency', 'GBP' === $data_coll['currency'] );

// Preis-Fallback-Kette: weder ->price noch ->prices, aber ->metrics->min_price_amount
$product_metrics_only = new \SureCart\Models\Product( array(
	'id'      => 'prod_metrics',
	'name'    => 'Nur Metrics',
	'metrics' => (object) array( 'min_price_amount' => 750, 'currency' => 'eur' ),
) );
$data_metrics = PMS_Pro_SureCart_Product_Data::get_product_data( $product_metrics_only, 1 );
check( 'get_product_data(): Preis-Fallback über ->metrics->min_price_amount', 7.5 === $data_metrics['value'] );
check( 'get_product_data(): Währungs-Fallback über ->metrics->currency', 'EUR' === $data_metrics['currency'] );

// Kein Preis irgendwo auffindbar -> 0.0, kein Fatal Error.
$product_no_price = new \SureCart\Models\Product( array( 'id' => 'prod_nopricedata', 'name' => 'Ohne Preisdaten' ) );
check( 'get_product_data(): kein Preis auffindbar -> value 0.0 statt Fatal Error', 0.0 === PMS_Pro_SureCart_Product_Data::get_product_data( $product_no_price, 1 )['value'] );

check( 'get_product_data(): null ist kein Objekt -> leeres Array', array() === PMS_Pro_SureCart_Product_Data::get_product_data( null, 1 ) );
check( 'get_product_data(): Objekt ohne id -> leeres Array', array() === PMS_Pro_SureCart_Product_Data::get_product_data( new stdClass(), 1 ) );

// Kategorie: nur auflösbar, wenn eine Post-ID mitgegeben wird (get_the_terms()).
$GLOBALS['stub']['wc']['terms'][555] = array( (object) array( 'name' => 'Apparel' ) );
check( 'get_product_data(): content_category leer ohne post_id', '' === PMS_Pro_SureCart_Product_Data::get_product_data( $product_full, 1 )['content_category'] );
check( 'get_product_data(): content_category aus get_the_terms(), wenn post_id mitgegeben wird', 'Apparel' === PMS_Pro_SureCart_Product_Data::get_product_data( $product_full, 1, 555 )['content_category'] );

// Minor-Unit-Filter: JPY-artige Zero-Decimal-Währung (Exponent 0).
add_filter( 'pms_surecart_currency_minor_unit', function () { return 0; } );
check( 'get_product_data(): pms_surecart_currency_minor_unit=0 -- kein Teilen durch 100', 1999.0 === PMS_Pro_SureCart_Product_Data::get_product_data( $product_full, 1 )['value'] );
add_filter( 'pms_surecart_currency_minor_unit', function () { return 2; } );

/* --- 23c. PMS_Pro_SureCart::fetch_checkout() / build_checkout_custom_data() --- */

sc_test_reset();

check( 'fetch_checkout(): leere ID -> null', null === PMS_Pro_SureCart::fetch_checkout( '' ) );
check( 'fetch_checkout(): unbekannte ID -> null', null === PMS_Pro_SureCart::fetch_checkout( 'does-not-exist' ) );

$checkout_a = new \SureCart\Models\Checkout( array(
	'id' => 'chk_a', 'status' => 'draft', 'total_amount' => 5500, 'tax_amount' => 500, 'currency' => 'eur',
) );
$GLOBALS['stub']['sc_checkouts']['chk_a'] = $checkout_a;
check( 'fetch_checkout(): bekannte ID liefert das Checkout-Objekt', $checkout_a === PMS_Pro_SureCart::fetch_checkout( 'chk_a' ) );

$GLOBALS['stub']['sc_products']['prod_501'] = new \SureCart\Models\Product( array( 'id' => 'prod_501', 'name' => 'Sneaker', 'sku' => 'SNK-1' ) );
$GLOBALS['stub']['sc_prices']['price_501']  = new \SureCart\Models\Price( array( 'id' => 'price_501', 'amount' => 2000, 'currency' => 'eur', 'product' => 'prod_501' ) );
// Preis ohne auflösbares Produkt (bewusst NICHT in sc_products) -- prüft den
// content_id-Fallback auf die Price-ID.
$GLOBALS['stub']['sc_prices']['price_777'] = new \SureCart\Models\Price( array( 'id' => 'price_777', 'amount' => 1500, 'currency' => 'eur', 'product' => 'prod_missing' ) );

$GLOBALS['stub']['sc_line_items'] = array(
	new \SureCart\Models\LineItem( array( 'id' => 'li_1', 'quantity' => 2, 'price' => 'price_501', 'total_amount' => 4000, 'checkout' => 'chk_a' ) ),
	new \SureCart\Models\LineItem( array( 'id' => 'li_2', 'quantity' => 1, 'price' => 'price_777', 'total_amount' => 1500, 'checkout' => 'chk_a' ) ),
	// Gehört zu einem ANDEREN Checkout -- darf in den Ergebnissen für chk_a nicht auftauchen.
	new \SureCart\Models\LineItem( array( 'id' => 'li_3', 'quantity' => 1, 'price' => 'price_501', 'total_amount' => 2000, 'checkout' => 'chk_other' ) ),
);

$custom_gross = PMS_Pro_SureCart::build_checkout_custom_data( $checkout_a, 'gross' );
check( 'build_checkout_custom_data(): genau 2 Positionen (chk_other-Line-Item korrekt gefiltert)', 2 === count( $custom_gross['contents'] ) );
check( 'build_checkout_custom_data(): content_id über Price->product aufgelöst', 'prod_501' === $custom_gross['contents'][0]['id'] );
check( 'build_checkout_custom_data(): content_id-Fallback auf Price-ID, wenn Produkt nicht auflösbar', 'price_777' === $custom_gross['contents'][1]['id'] );
check( 'build_checkout_custom_data(): item_price aus LineItem::total_amount/quantity (historisch), NICHT aus Price::amount', 20.0 === $custom_gross['contents'][0]['item_price'] );
check( 'build_checkout_custom_data(): value (gross) aus Checkout::total_amount', 55.0 === $custom_gross['value'] );
check( 'build_checkout_custom_data(): currency aus Checkout::currency, großgeschrieben', 'EUR' === $custom_gross['currency'] );
check( 'build_checkout_custom_data(): tax aus Checkout::tax_amount', 5.0 === $custom_gross['tax'] );
check( 'build_checkout_custom_data(): num_items summiert die Mengen', 3 === $custom_gross['num_items'] );

$custom_net = PMS_Pro_SureCart::build_checkout_custom_data( $checkout_a, 'net' );
check( 'build_checkout_custom_data(): value (net) = total_amount - tax_amount', 50.0 === $custom_net['value'] );

check( 'build_checkout_custom_data(): kein Objekt -> leeres Array statt Fatal Error', array() === PMS_Pro_SureCart::build_checkout_custom_data( null ) );

$checkout_empty = new \SureCart\Models\Checkout( array( 'id' => 'chk_empty', 'total_amount' => 0 ) );
$GLOBALS['stub']['sc_checkouts']['chk_empty'] = $checkout_empty;
check( 'build_checkout_custom_data(): Checkout ohne Line-Items -> leeres Array (kein Fatal Error)', array() === PMS_Pro_SureCart::build_checkout_custom_data( $checkout_empty ) );

/* --- 23d. PMS_Pro_SureCart_Purchase: event_id(), Meta-CAPI + TikTok Events API
 * über track_confirmed()/maybe_track_fallback() (Ende-zu-Ende, dasselbe Muster wie
 * Abschnitt 19g/22e für WooCommerce), Dedup über Checkout-Metadata --- */

sc_test_reset();

check( 'PMS_Pro_SureCart_Purchase::event_id() -- deterministisches Format', 'pms_sc_order_chk_purchase_1' === PMS_Pro_SureCart_Purchase::event_id( 'chk_purchase_1' ) );

// Der add_filter()-Stub hält nur EINEN Callback pro Tag (siehe dessen
// Kommentar oben) -- registriert PMS_Pro_SureCart::filter_capi_event_data()
// für 'pms_capi_event_data' und löst damit bewusst die WooCommerce-
// Registrierung aus Abschnitt 19g ab (nichts danach braucht diese mehr,
// siehe Kommentar dort).
add_filter( 'pms_capi_event_data', array( 'PMS_Pro_SureCart', 'filter_capi_event_data' ) );

function sc_make_purchase_fixture( $checkout_id ) {
	$checkout = new \SureCart\Models\Checkout( array(
		'id'              => $checkout_id,
		'status'          => 'paid',
		'total_amount'    => 5500,
		'tax_amount'      => 500,
		'currency'        => 'eur',
		'email'           => 'kunde@example.com',
		'first_name'      => 'Erika',
		'last_name'       => 'Musterfrau',
		'phone'           => '+49 176 1234567',
		'billing_address' => (object) array( 'line1' => 'Musterstraße 1', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10001', 'country' => 'US' ),
		'metadata'        => array(),
	) );
	$GLOBALS['stub']['sc_checkouts'][ $checkout_id ] = $checkout;

	$GLOBALS['stub']['sc_products']['prod_501'] = new \SureCart\Models\Product( array( 'id' => 'prod_501', 'name' => 'Sneaker', 'sku' => 'SNK-1' ) );
	$GLOBALS['stub']['sc_prices']['price_501']  = new \SureCart\Models\Price( array( 'id' => 'price_501', 'amount' => 2000, 'currency' => 'eur', 'product' => 'prod_501' ) );
	$GLOBALS['stub']['sc_prices']['price_777']  = new \SureCart\Models\Price( array( 'id' => 'price_777', 'amount' => 1500, 'currency' => 'eur', 'product' => 'prod_missing' ) );

	$GLOBALS['stub']['sc_line_items'] = array_merge(
		$GLOBALS['stub']['sc_line_items'],
		array(
			new \SureCart\Models\LineItem( array( 'id' => 'li_' . $checkout_id . '_1', 'quantity' => 2, 'price' => 'price_501', 'total_amount' => 4000, 'checkout' => $checkout_id ) ),
			new \SureCart\Models\LineItem( array( 'id' => 'li_' . $checkout_id . '_2', 'quantity' => 1, 'price' => 'price_777', 'total_amount' => 1500, 'checkout' => $checkout_id ) ),
		)
	);

	return $checkout;
}

// -- Meta CAPI (TikTok bewusst aus, um den Meta-Request isoliert zu prüfen -- dasselbe
// Isolationsmuster wie bei PMS_Pro_Woo_Purchase in Abschnitt 19g.) --
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'sc_tracking_enabled' => 1,
		'capi_enabled'        => 1,
		'pixel_id'            => '999999',
		'capi_token'          => 'meta-secret-token',
		'consent_detection'   => 0,
		'tiktok_enabled'      => 0,
		'sc_purchase_value_type' => 'gross',
	)
);

$checkout_meta = sc_make_purchase_fixture( 'chk_meta_1' );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_meta, null );

$meta_posts = array_values( array_filter( $GLOBALS['stub']['captured_posts'], function ( $p ) {
	return false !== strpos( $p['url'], 'graph.facebook.com' );
} ) );
check( 'track_confirmed(): löst genau einen Meta-CAPI-Request aus', 1 === count( $meta_posts ) );

$meta_body  = json_decode( $meta_posts[0]['args']['body'], true );
$meta_event = $meta_body['data'][0];
check( 'track_confirmed(): Meta-Event-Name ist "Purchase"', 'Purchase' === $meta_event['event_name'] );
check( 'track_confirmed(): Meta event_id nutzt dieselbe deterministische Formel', 'pms_sc_order_chk_meta_1' === $meta_event['event_id'] );
// json_decode() liefert für einen ganzzahligen JSON-Zahlenwert (kein
// Nachkommaanteil im JSON) einen PHP int, nicht den ursprünglichen PHP
// float -- dieselbe Eigenheit, wegen der Abschnitt 22e's TikTok-Checks
// bereits "55 ===" statt "55.0 ===" nutzen. Direkte (nicht über JSON
// geführte) Aufrufe wie build_checkout_custom_data() in Abschnitt 23c
// bleiben davon unberührt und behalten "55.0 ===".
check( 'track_confirmed(): custom_data.value (gross) aus Checkout::total_amount', 55 === $meta_event['custom_data']['value'] );
check( 'track_confirmed(): custom_data.content_ids enthält das aufgelöste Produkt', in_array( 'prod_501', $meta_event['custom_data']['content_ids'], true ) );
check( 'track_confirmed(): ohne sc_purchase_advanced_matching kein em/ph in user_data', ! isset( $meta_event['user_data']['em'] ) );

check( 'track_confirmed(): markiert den Checkout als getrackt (Checkout::update() mit _pms_sc_purchase_tracked)', ! empty( $checkout_meta->metadata['_pms_sc_purchase_tracked'] ) );
check( 'already_tracked()-Dedup: ein zweiter track_confirmed()-Aufruf für denselben Checkout sendet nichts erneut', 1 === count( $meta_posts ) );

$updates_before = count( $GLOBALS['stub']['captured_sc_updates'] );
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_meta, null );
check( 'Dedup gilt auch für Checkout::update() selbst -- kein zweiter mark_tracked()-Aufruf', $updates_before === count( $GLOBALS['stub']['captured_sc_updates'] ) );
check( 'Dedup gilt auch für den Meta-Request -- weiterhin nur ein einziger captured_posts-Eintrag an Meta', 1 === count( array_filter( $GLOBALS['stub']['captured_posts'], function ( $p ) { return false !== strpos( $p['url'], 'graph.facebook.com' ); } ) ) );

// Advanced Matching an -> em/ph/fn/ln/ct/st/zp/country gehasht (Metas hash_email()/hash_phone()/hash_field(), wiederverwendet).
$GLOBALS['stub']['options']['pms_settings']['sc_purchase_advanced_matching'] = 1;
$checkout_am = sc_make_purchase_fixture( 'chk_meta_am' );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_am, null );
$am_body  = json_decode( array_values( array_filter( $GLOBALS['stub']['captured_posts'], function ( $p ) { return false !== strpos( $p['url'], 'graph.facebook.com' ); } ) )[0]['args']['body'], true );
$am_user  = $am_body['data'][0]['user_data'];
check( 'Advanced Matching an: em gehasht vorhanden', array( PMS_CAPI::hash_email( 'kunde@example.com' ) ) === $am_user['em'] );
check( 'Advanced Matching an: ph gehasht vorhanden', array( PMS_CAPI::hash_phone( '+49 176 1234567' ) ) === $am_user['ph'] );
check( 'Advanced Matching an: fn/ln gehasht vorhanden', array( PMS_CAPI::hash_field( 'Erika' ) ) === $am_user['fn'] && array( PMS_CAPI::hash_field( 'Musterfrau' ) ) === $am_user['ln'] );
check( 'Advanced Matching an: ct/zp gehasht mit entfernten Leerzeichen', array( PMS_CAPI::hash_field( 'New York', true ) ) === $am_user['ct'] );
$GLOBALS['stub']['options']['pms_settings']['sc_purchase_advanced_matching'] = 0;

/* --- 23e. build_google_user_data()/hash_google_phone() (private, via Reflection) --
 * dasselbe Feld-Set/Klartext-Ausnahmen wie PMS_Pro_Woo_Purchase, siehe Abschnitt 22d. --- */

$checkout_google = sc_make_purchase_fixture( 'chk_google_1' );
$google_user_data = call_private( 'PMS_Pro_SureCart_Purchase', 'build_google_user_data', $checkout_google );
check( 'build_google_user_data(): email gehasht', PMS_CAPI::hash_email( 'kunde@example.com' ) === $google_user_data['email'] );
check( 'build_google_user_data(): phone_number im E.164-Hash-Format (abweichend von Metas hash_phone())', call_private( 'PMS_Pro_SureCart_Purchase', 'hash_google_phone', '+49 176 1234567' ) === $google_user_data['phone_number'] );
check( 'build_google_user_data(): phone_number unterscheidet sich von Metas hash_phone()', PMS_CAPI::hash_phone( '+49 176 1234567' ) !== $google_user_data['phone_number'] );
check( 'build_google_user_data(): address ist ein Array mit genau einem Objekt', 1 === count( $google_user_data['address'] ) );
check( 'build_google_user_data(): first_name/last_name/street sind gehasht', PMS_CAPI::hash_field( 'Erika' ) === $google_user_data['address'][0]['first_name'] && PMS_CAPI::hash_field( 'Musterstraße 1' ) === $google_user_data['address'][0]['street'] );
check( 'build_google_user_data(): city/region/postal_code/country bleiben Klartext', 'New York' === $google_user_data['address'][0]['city'] && 'US' === $google_user_data['address'][0]['country'] );

/* --- 23f. TikTok Events API (Meta bewusst aus, um den TikTok-Request isoliert zu
 * prüfen). dispatch_capi()/PMS_CAPI::send_events() gaten NICHT auf capi_enabled --
 * genau wie bei PMS_Pro_Woo_Purchase (siehe dortiges Ende-zu-Ende-Muster in
 * Abschnitt 19g/22e) reicht Purchase-Tracking Meta-CAPI-Events unconditional an
 * send_events() durch, das selbst nur auf pixel_id/capi_token gated ist -- pixel_id/
 * capi_token müssen deshalb hier explizit LEER sein (nicht nur capi_enabled=0),
 * sonst würde ein aus Abschnitt 23d noch gespeicherter Wert Meta hier ebenfalls
 * feuern lassen. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'sc_tracking_enabled' => 1,
		'capi_enabled'        => 0,
		'pixel_id'            => '',
		'capi_token'          => '',
		'consent_detection'   => 0,
		'tiktok_enabled'      => 1,
		'tiktok_pixel_id'     => 'ABCD1234',
		'tiktok_capi_enabled' => 1,
		'tiktok_access_token' => 'tt-secret-token',
	)
);

$checkout_tt = sc_make_purchase_fixture( 'chk_tt_1' );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_tt, null );

check( 'TikTok: löst genau einen Request an die TikTok Events API aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
$tt_call = $GLOBALS['stub']['captured_posts'][0];
check( 'TikTok: URL ist der TikTok-Events-API-Endpoint (v1.3)', 'https://business-api.tiktok.com/open_api/v1.3/event/track/' === $tt_call['url'] );
$tt_body = json_decode( $tt_call['args']['body'], true );
check( 'TikTok: event ist "CompletePayment"', 'CompletePayment' === $tt_body['data'][0]['event'] );
check( 'TikTok: event_id nutzt dieselbe deterministische Formel wie Meta', 'pms_sc_order_chk_tt_1' === $tt_body['data'][0]['event_id'] );
check( 'TikTok: properties.value aus custom_data (gross)', 55 === $tt_body['data'][0]['properties']['value'] );

/* --- 23g. Fallback-Weg: surecart/order_updated (maybe_track_fallback()) -- Status-Gating,
 * Checkout als String-ID vs. bereits eingebettetes Objekt, Idempotenz gegen Weg 1 --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array( 'sc_tracking_enabled' => 1, 'capi_enabled' => 1, 'pixel_id' => '999999', 'capi_token' => 'x', 'consent_detection' => 0, 'tiktok_enabled' => 0 )
);

$checkout_fb = sc_make_purchase_fixture( 'chk_fallback_1' );

$order_pending = new \SureCart\Models\Order( array( 'id' => 'ord_1', 'status' => 'pending', 'checkout' => 'chk_fallback_1' ) );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::maybe_track_fallback( $order_pending );
check( 'maybe_track_fallback(): status != "paid" -> kein Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );

$order_paid_string_checkout = new \SureCart\Models\Order( array( 'id' => 'ord_2', 'status' => 'paid', 'checkout' => 'chk_fallback_1' ) );
PMS_Pro_SureCart_Purchase::maybe_track_fallback( $order_paid_string_checkout );
check( 'maybe_track_fallback(): status "paid", checkout als String-ID -> löst über fetch_checkout() auf und sendet', 1 === count( array_filter( $GLOBALS['stub']['captured_posts'], function ( $p ) { return false !== strpos( $p['url'], 'graph.facebook.com' ); } ) ) );

$checkout_fb2 = sc_make_purchase_fixture( 'chk_fallback_2' );
$order_paid_embedded = new \SureCart\Models\Order( array( 'id' => 'ord_3', 'status' => 'paid', 'checkout' => $checkout_fb2 ) );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::maybe_track_fallback( $order_paid_embedded );
check( 'maybe_track_fallback(): status "paid", checkout bereits als Objekt eingebettet -> sendet ohne fetch_checkout()', 1 === count( array_filter( $GLOBALS['stub']['captured_posts'], function ( $p ) { return false !== strpos( $p['url'], 'graph.facebook.com' ); } ) ) );

// Idempotenz zwischen Weg 1 (track_confirmed) und Weg 2 (maybe_track_fallback): einmal
// über Weg 1 getrackt, darf Weg 2 für dieselbe Bestellung nichts mehr senden.
$checkout_both = sc_make_purchase_fixture( 'chk_both_ways' );
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_both, null );
$GLOBALS['stub']['captured_posts'] = array();
$order_both = new \SureCart\Models\Order( array( 'id' => 'ord_4', 'status' => 'paid', 'checkout' => $checkout_both ) );
PMS_Pro_SureCart_Purchase::maybe_track_fallback( $order_both );
check( 'Idempotenz Weg 1 <-> Weg 2: bereits über track_confirmed() getrackt -> maybe_track_fallback() sendet nichts', 0 === count( $GLOBALS['stub']['captured_posts'] ) );

/* --- 23h. Consent-Gating: derselbe Fail-closed-Grundsatz wie bei WooCommerce (Abschnitt 22).
 * mark_tracked() läuft trotzdem (kein Retry bei fehlendem Consent, siehe CLAUDE.md
 * "Bekannte Trade-offs"). --- */

$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 1;
$GLOBALS['stub']['wp_consent'] = false;
reset_consent_cache();
check( 'Testaufbau: WP-Consent-API verweigert -> Consent false', false === PMS_Consent::has_marketing_consent() );

$checkout_noconsent = sc_make_purchase_fixture( 'chk_noconsent' );
$GLOBALS['stub']['captured_posts'] = array();
PMS_Pro_SureCart_Purchase::track_confirmed( $checkout_noconsent, null );
check( 'track_confirmed(): kein Marketing-Consent -> kein Request (weder Meta noch TikTok)', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
check( 'track_confirmed(): Checkout gilt trotzdem als bearbeitet (kein Retry-Mechanismus, wie bei WooCommerce)', ! empty( $checkout_noconsent->metadata['_pms_sc_purchase_tracked'] ) );

$GLOBALS['stub']['options']['pms_settings']['consent_detection'] = 0;
$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();

/* --- 23i. Admin-UI: Tab "E-Commerce" rendert die SureCart-Accordion zusätzlich zur
 * WooCommerce-Accordion (SureCart-Marker-Klasse ist in diesem Harness unconditional
 * aktiv, siehe Stub-Doku oben); sc_*-Keys bleiben auf Tab "Allgemein" als Hidden-Feld
 * geschützt. --- */

$GLOBALS['stub']['options']['pms_settings'] = array();
$GLOBALS['stub']['options']['pms_events']   = array();
$GLOBALS['stub']['current_user_can']        = true;

$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'ecommerce';
ob_start();
PMS_Admin::render_page();
$sc_ecommerce_output = ob_get_clean();
check( 'tab=ecommerce rendert zusätzlich die SureCart-Accordion (Pro+SureCart-Zweig)', false !== strpos( $sc_ecommerce_output, 'Enable SureCart tracking' ) );
check( 'tab=ecommerce: SureCart-Accordion zeigt das Google-Ads-Conversion-Label-Feld', false !== strpos( $sc_ecommerce_output, 'pms_settings[sc_google_conversion_label]' ) );

$_GET['tab'] = 'general';
ob_start();
PMS_Admin::render_page();
$sc_general_output = ob_get_clean();
check( 'tab=general schützt sc_tracking_enabled als Hidden-Feld (kein echtes Feld auf diesem Tab)', false !== strpos( $sc_general_output, 'name="pms_settings[sc_tracking_enabled]"' ) && false !== strpos( $sc_general_output, 'type="hidden"' ) );

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can'] = false;

$GLOBALS['stub']['captured_posts']          = array();
$GLOBALS['stub']['wc_orders']               = array();
wc_test_reset();
sc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 24. GA4-Integration (v0.6.8): Sanitizing, kombiniertes Google-Ads/GA4-Script-Rendering, Admin-UI ===\n";

/* --- 24a. sanitize_settings(): ga4_measurement_id -- Großbuchstaben/Trim,
 * Zeichen-Whitelist wie bei google_tag_id/tiktok_pixel_id (Abschnitt 22a/23). --- */

check( 'ga4_measurement_id: Default ist leer', '' === PMS_Settings::sanitize_settings( array() )['ga4_measurement_id'] );
check( 'ga4_measurement_id: Kleinbuchstaben werden zu Großbuchstaben normalisiert', 'G-ABC123XYZ' === PMS_Settings::sanitize_settings( array( 'ga4_measurement_id' => 'g-abc123xyz' ) )['ga4_measurement_id'] );
check( 'ga4_measurement_id: führende/nachgestellte Leerzeichen werden getrimmt', 'G-ABC123XYZ' === PMS_Settings::sanitize_settings( array( 'ga4_measurement_id' => '  g-abc123xyz  ' ) )['ga4_measurement_id'] );
check( 'ga4_measurement_id: Markup/Sonderzeichen werden entfernt (XSS-Schutz), Bindestrich bleibt erhalten', 'G-ABC123SCRIPTALERT1SCRIPT' === PMS_Settings::sanitize_settings( array( 'ga4_measurement_id' => 'g-abc123<script>alert(1)</script>' ) )['ga4_measurement_id'] );

/* --- 24b. Pro + NUR GA4 (kein Google Ads) -- eigener gtag.js-Loader, ein
 * einziger gtag('config', ...)-Aufruf, KEINE Google-Ads-Conversion. Belegt
 * zugleich die googleEnabled-Erweiterung in class-pro-woo.php/class-pro-
 * surecart.php ist NICHT nötig, damit gtag.js selbst hier lädt -- die
 * Frontend-Ausgabe hängt allein an ga4_active(), unabhängig von den
 * WooCommerce/SureCart-JS-Settings. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'pixel_enabled'       => 0,
		'google_enabled'      => 0,
		'google_tag_id'       => '',
		'google_consent_mode' => 0,
		'tiktok_enabled'      => 0,
		'ga4_measurement_id'  => 'G-ONLYGA4',
		'consent_detection'   => 0,
	)
);
$_SERVER['REQUEST_URI'] = '/ga4-only-test/';
$html_ga4_pro = run_frontend();
check( '24b.1 Pro + nur GA4: gtag.js-Loader wird ausgegeben', false !== strpos( $html_ga4_pro, 'window.dataLayer=window.dataLayer||[]' ) );
check( '24b.2 Pro + nur GA4: gtag(config, GA4-ID) wird ausgegeben', false !== strpos( $html_ga4_pro, "gtag('config','G-ONLYGA4')" ) );
check( '24b.3 Pro + nur GA4: EIN einziger gtag.js-<script>-Tag (src-Query trägt die GA4-ID, da kein Google Ads konfiguriert)', 1 === substr_count( $html_ga4_pro, 'googletagmanager.com/gtag/js?id=G-ONLYGA4' ) );
check( '24b.4 Pro + nur GA4: KEINE Google-Ads-Conversion (kein Tag-ID konfiguriert)', false === strpos( $html_ga4_pro, "gtag('event','conversion'" ) );

/* --- 24c. Pro + Google Ads UND GA4 gleichzeitig -- EIN gtag.js-Loader, ZWEI
 * config-Aufrufe, Conversion-Event weiterhin nur an die Google-Ads-ID
 * gebunden (GA4 kennt keine Conversion-Labels, siehe build_google_js()). --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'pixel_enabled'       => 0,
		'google_enabled'      => 1,
		'google_tag_id'       => 'AW-123456789',
		'google_consent_mode' => 0,
		'tiktok_enabled'      => 0,
		'ga4_measurement_id'  => 'G-BOTH4567',
		'consent_detection'   => 0,
	)
);
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$GLOBALS['stub']['options']['pms_events']         = array(
	array(
		'id' => 'ev-both', 'name' => 'Beide Google-Ziele', 'event_type' => 'Lead', 'match_type' => 'exact', 'match_value' => '/beide-ziele/', 'active' => 1,
		'meta_enabled' => 0, 'google_enabled' => 1, 'google_label' => 'AbCdEf123', 'tiktok_enabled' => 0,
	),
);
$_SERVER['REQUEST_URI'] = '/beide-ziele/';
$html_both = run_frontend();
check( '24c.1 Google Ads + GA4: EIN einziger gtag.js-<script>-Tag (nicht zweimal geladen)', 1 === substr_count( $html_both, 'googletagmanager.com/gtag/js?id=' ) );
check( '24c.2 Google Ads + GA4: gtag(config, Google-Ads-Tag-ID)', false !== strpos( $html_both, "gtag('config','AW-123456789')" ) );
check( '24c.3 Google Ads + GA4: gtag(config, GA4-Measurement-ID)', false !== strpos( $html_both, "gtag('config','G-BOTH4567')" ) );
check( '24c.4 Google Ads + GA4: genau ZWEI gtag(config, ...)-Aufrufe', 2 === substr_count( $html_both, "gtag('config'," ) );
check( '24c.5 Google Ads + GA4: Conversion-Event trägt weiterhin send_to mit der Google-Ads-ID, nicht der GA4-ID', false !== strpos( $html_both, "gtag('event','conversion',{'send_to':'AW-123456789/AbCdEf123'})" ) );

/* --- 24d. Admin-UI: Tab "Allgemein" zeigt das GA4-Feld innerhalb der
 * Google-Ads-Box (Pro), der Key bleibt auf jedem anderen Tab (hier:
 * "E-Commerce") als Hidden-Feld geschützt -- dasselbe Cross-Tab-Muster wie
 * bei sc_tracking_enabled in Abschnitt 23i. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge( PMS_Settings::get(), array( 'ga4_measurement_id' => 'G-ADMINTEST' ) );
$GLOBALS['stub']['options']['pms_events']   = array();
$GLOBALS['stub']['current_user_can']        = true;

$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'general';
ob_start();
PMS_Admin::render_page();
$ga4_general_output = ob_get_clean();
check( '24d.1 tab=general (Pro): GA4-Measurement-ID-Feld ist vorhanden', false !== strpos( $ga4_general_output, 'name="pms_settings[ga4_measurement_id]"' ) );
check( '24d.2 tab=general (Pro): GA4-Feld zeigt den gespeicherten Wert', false !== strpos( $ga4_general_output, 'value="G-ADMINTEST"' ) );
check( '24d.3 tab=general (Pro): GA4-Feld ist ein echtes Textfeld, kein Hidden-Feld', 1 === preg_match( '/<input type="text"[^>]*name="pms_settings\[ga4_measurement_id\]"/', $ga4_general_output ) );

$_GET['tab'] = 'ecommerce';
ob_start();
PMS_Admin::render_page();
$ga4_ecommerce_output = ob_get_clean();
check( '24d.4 tab=ecommerce schützt ga4_measurement_id als Hidden-Feld (kein echtes Feld auf diesem Tab)', 1 === preg_match( '/<input type="hidden" name="pms_settings\[ga4_measurement_id\]" value="G-ADMINTEST"/', $ga4_ecommerce_output ) );

/* --- 24e. WooCommerce Purchase (PMS_Pro_Woo_Purchase::ga4_purchase_js()) --
 * eigenständig von der Google-Ads-Conversion (google_tag_id bewusst leer):
 * gtag('event','purchase', ...) ohne send_to, transaction_id/value/currency/
 * items[] aus denselben Bestelldaten wie Meta/TikTok (make_test_order()).
 * current_user_can muss hier zurückgesetzt werden -- 24d hat es für den
 * Admin-Rendering-Test auf true gestellt, should_process() würde sonst
 * wegen exclude_admins (Default 1) fälschlich false liefern. --- */

$GLOBALS['stub']['current_user_can'] = false;
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled' => 1,
		'pixel_enabled'       => 0,
		'google_enabled'      => 0,
		'google_tag_id'       => '',
		'ga4_measurement_id'  => 'G-WOOPURCHASE',
		'consent_detection'   => 0,
	)
);
$ga4_order = make_test_order( array( 'id' => 2005 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $ga4_order->get_id() );
$ga4_purchase_output = ob_get_clean();

check( '24e.1 GA4-Purchase: gtag(event, purchase) wird ausgegeben', false !== strpos( $ga4_purchase_output, "gtag('event','purchase'" ) );
check( '24e.2 GA4-Purchase: transaction_id ist die ROHE Bestellnummer (seit v0.6.9) -- GA4-Berichte sollen sich den echten Bestellungen zuordnen lassen', false !== strpos( $ga4_purchase_output, '"transaction_id":"2005"' ) );
check( '24e.3 GA4-Purchase: value/currency aus der Bestellung (55.00 EUR gross, Default wc_purchase_value_type)', false !== strpos( $ga4_purchase_output, '"value":55' ) && false !== strpos( $ga4_purchase_output, '"currency":"EUR"' ) );
check( '24e.4 GA4-Purchase: items[] enthält beide Bestellpositionen mit item_id/price/quantity', false !== strpos( $ga4_purchase_output, '"item_id":"501","price":20,"quantity":2' ) && false !== strpos( $ga4_purchase_output, '"item_id":"777","price":9.99,"quantity":1' ) );
check( '24e.5 GA4-Purchase: KEIN send_to (unabhängig von einer Google-Ads-Conversion, die hier nicht konfiguriert ist)', false === strpos( $ga4_purchase_output, 'send_to' ) );

wc_test_reset();
$GLOBALS['stub']['wc_orders'] = array();

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can']        = false;
$GLOBALS['stub']['options']['pms_settings'] = array();
$GLOBALS['stub']['options']['pms_events']   = array();

echo "\n=== 25. Purchase-Dedup-Trennung Browser/Server (v0.6.9-Bugfix) ===\n";

/*
 * Bis v0.6.8 bewachte EIN gemeinsames Order-Meta-Flag (_pms_purchase_tracked)
 * sowohl den Browser- als auch den Server-Pfad. Weil WooCommerce bei vielen
 * Zahlungsarten schon WÄHREND des Checkouts payment_complete() bzw.
 * update_status('processing') aufruft (z. B. Nachnahme), lief der
 * Server-Side-Fallback dort regelmäßig VOR der Danke-Seite -- und der danach
 * folgende woocommerce_thankyou-Hook stieg wegen already_tracked() sofort
 * wieder aus. Ergebnis: kein einziger fbq/gtag/ttq-Aufruf im Quelltext der
 * Danke-Seite, obwohl die CAPI korrekt bedient wurde.
 *
 * Dieser Abschnitt stellt genau diese Reihenfolge nach (Fallback zuerst,
 * Danke-Seite danach) und prüft beide Richtungen der jetzt getrennten Flags.
 */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled' => 1,
		'pixel_enabled'       => 1,
		'pixel_id'            => '1234567890',
		'capi_enabled'        => 1,
		'capi_token'          => 'test-token',
		'consent_detection'   => 0,
	)
);
reset_consent_cache();

/* --- 25a. Der gemeldete Fall: Fallback feuert zuerst, Danke-Seite danach. --- */

$split_order = make_test_order( array( 'id' => 3001 ) );
$GLOBALS['stub']['captured_posts'] = array();

// Schritt 1: woocommerce_order_status_processing o. Ä. während des Checkouts.
PMS_Pro_Woo_Purchase::maybe_track_fallback( $split_order->get_id() );

check( '25a.1 Fallback vor der Danke-Seite: löst den CAPI-Request aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
check( '25a.2 Fallback vor der Danke-Seite: setzt NUR das Server-Flag', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $split_order ) );
check( '25a.3 Fallback vor der Danke-Seite: lässt das Browser-Flag unberührt', false === call_private( 'PMS_Pro_Woo_Purchase', 'already_browser_tracked', $split_order ) );

// Schritt 2: der Kunde landet auf /kasse/order-received/... -- genau hier
// erschien bis v0.6.8 nichts mehr im Quelltext.
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $split_order->get_id() );
$split_output = ob_get_clean();

check( '25a.4 Danke-Seite nach dem Fallback: Browser-Pixel wird TROTZDEM gerendert (der eigentliche Bugfix)', false !== strpos( $split_output, "fbq('track','Purchase'" ) );
check( '25a.5 Danke-Seite nach dem Fallback: identische event_id wie der Server-Pfad -> Meta dedupliziert', false !== strpos( $split_output, "eventID:'pms_order_3001'" ) );
check( '25a.6 Danke-Seite nach dem Fallback: KEIN zweiter CAPI-Request (Server-Flag greift weiterhin)', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
check( '25a.7 Danke-Seite nach dem Fallback: setzt jetzt auch das Browser-Flag', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_browser_tracked', $split_order ) );

// Schritt 3: F5 auf der Danke-Seite -> weder Browser- noch Server-Pfad erneut.
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $split_order->get_id() );
$split_reload = ob_get_clean();

check( '25a.8 Reload der Danke-Seite: kein zweiter Browser-Pixel', false === strpos( $split_reload, "fbq('track','Purchase'" ) );
check( '25a.9 Reload der Danke-Seite: weiterhin nur ein CAPI-Request', 1 === count( $GLOBALS['stub']['captured_posts'] ) );

/* --- 25b. Umgekehrte Reihenfolge (Danke-Seite zuerst): unverändertes
 * Verhalten gegenüber v0.6.8 -- beide Flags werden dort gemeinsam gesetzt,
 * ein späterer Fallback darf nichts mehr auslösen. --- */

$split_order2 = make_test_order( array( 'id' => 3002 ) );
$GLOBALS['stub']['captured_posts'] = array();

ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $split_order2->get_id() );
$split_output2 = ob_get_clean();

check( '25b.1 Danke-Seite zuerst: Browser-Pixel wird gerendert', false !== strpos( $split_output2, "eventID:'pms_order_3002'" ) );
check( '25b.2 Danke-Seite zuerst: CAPI-Request läuft im selben Aufruf', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
check( '25b.3 Danke-Seite zuerst: setzt BEIDE Flags in einem Durchgang', true === call_private( 'PMS_Pro_Woo_Purchase', 'already_tracked', $split_order2 ) && true === call_private( 'PMS_Pro_Woo_Purchase', 'already_browser_tracked', $split_order2 ) );

PMS_Pro_Woo_Purchase::maybe_track_fallback( $split_order2->get_id() );
check( '25b.4 Späterer Fallback: löst für dieselbe Bestellung KEINEN weiteren CAPI-Request aus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );

/* --- 25c. mark_tracked(): ein einziger save() für beide Flags, und der
 * Default-Parameter hält das bisherige Fallback-Verhalten unverändert. --- */

$mark_order = make_test_order( array( 'id' => 3003 ) );
$mark_order->saved = false;
call_private( 'PMS_Pro_Woo_Purchase', 'mark_tracked', $mark_order, array( PMS_Pro_Woo_Purchase::TRACKED_META_KEY, PMS_Pro_Woo_Purchase::BROWSER_TRACKED_META_KEY ) );
check( '25c.1 mark_tracked(): setzt beide übergebenen Meta-Keys', 1 === $mark_order->get_meta( PMS_Pro_Woo_Purchase::TRACKED_META_KEY ) && 1 === $mark_order->get_meta( PMS_Pro_Woo_Purchase::BROWSER_TRACKED_META_KEY ) );
check( '25c.2 mark_tracked(): speichert die Bestellung (ein save() für beide Flags)', true === $mark_order->saved );

$mark_order2 = make_test_order( array( 'id' => 3004 ) );
call_private( 'PMS_Pro_Woo_Purchase', 'mark_tracked', $mark_order2 );
check( '25c.3 mark_tracked(): Default-Aufruf setzt weiterhin NUR das Server-Flag (Fallback-Pfad unverändert)', 1 === $mark_order2->get_meta( PMS_Pro_Woo_Purchase::TRACKED_META_KEY ) && '' === (string) $mark_order2->get_meta( PMS_Pro_Woo_Purchase::BROWSER_TRACKED_META_KEY ) );

$mark_order3 = make_test_order( array( 'id' => 3005 ) );
$mark_order3->saved = false;
call_private( 'PMS_Pro_Woo_Purchase', 'mark_tracked', $mark_order3, array() );
check( '25c.4 mark_tracked(): leere Key-Liste schreibt die Bestellung gar nicht erst', false === $mark_order3->saved );

/* --- 25d. Die zwei ID-Formate nebeneinander in EINEM Danke-Seiten-Aufruf:
 * Meta/TikTok bekommen die präfixierte Dedup-Event-ID, Google Ads/GA4 die
 * rohe Bestellnummer (siehe transaction_id() in class-pro-woo-purchase.php).
 * Beide Werte im selben Output zu prüfen ist die eigentliche Absicherung --
 * eine spätere Vereinheitlichung "aus Konsistenzgründen" würde hier sofort
 * auffallen. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled'        => 1,
		'pixel_enabled'              => 1,
		'pixel_id'                   => '1234567890',
		'capi_enabled'               => 0,
		'capi_token'                 => '',
		'consent_detection'          => 0,
		'google_enabled'             => 1,
		'google_tag_id'              => 'AW-123456789',
		'wc_google_conversion_label' => 'AbCdEfGh',
		'ga4_measurement_id'         => 'G-ABC123',
		'tiktok_enabled'             => 1,
		'tiktok_pixel_id'            => 'TT123',
	)
);
reset_consent_cache();

$id_order = make_test_order( array( 'id' => 3010 ) );

ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $id_order->get_id() );
$id_output = ob_get_clean();

check( '25d.1 Meta: eventID bleibt die präfixierte Dedup-ID', false !== strpos( $id_output, "eventID:'pms_order_3010'" ) );
check( '25d.2 TikTok: event_id bleibt dieselbe präfixierte Dedup-ID wie bei Meta', false !== strpos( $id_output, "event_id:'pms_order_3010'" ) );
check( '25d.3 Google Ads: transaction_id ist die rohe Bestellnummer', false !== strpos( $id_output, '"send_to":"AW-123456789\/AbCdEfGh"' ) && false !== strpos( $id_output, '"transaction_id":"3010"' ) );
check( '25d.4 GA4: transaction_id ist ebenfalls die rohe Bestellnummer', false !== strpos( $id_output, "gtag('event','purchase'" ) && 2 === substr_count( $id_output, '"transaction_id":"3010"' ) );
check( '25d.5 Keine präfixierte ID in einem transaction_id-Feld (Google/GA4 dürfen sie nicht übernehmen)', false === strpos( $id_output, '"transaction_id":"pms_order_' ) );

$GLOBALS['stub']['captured_posts'] = array();
$GLOBALS['stub']['wc_orders']      = array();
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array();

echo "\n=== 26. v0.6.10: Consent-Modus, TikTok-Test-Code/contents[], Multi-Platform-Formular-Leads, Event-Log-Badges ===\n";

/* --- 26a. PMS_Settings: Sanitizing/Defaults/Helper der vier neuen Keys --- */

$GLOBALS['stub']['options']['pms_settings'] = array();

check( '26a.1 consent_mode: Default ist "strict" (Verhalten vor v0.6.10)', 'strict' === PMS_Settings::get()['consent_mode'] );
check( '26a.2 consent_mode: unbekannter Wert fällt auf "strict" zurück', 'strict' === PMS_Settings::sanitize_settings( array( 'consent_mode' => 'egal' ) )['consent_mode'] );
check( '26a.3 consent_mode: "browser_only" wird übernommen', 'browser_only' === PMS_Settings::sanitize_settings( array( 'consent_mode' => 'browser_only' ) )['consent_mode'] );
check( '26a.4 consent_mode(): liest den gespeicherten Wert', (function () {
	$GLOBALS['stub']['options']['pms_settings'] = array_merge( PMS_Settings::get(), array( 'consent_mode' => 'browser_only' ) );
	$mode = PMS_Settings::consent_mode();
	$GLOBALS['stub']['options']['pms_settings'] = array();
	return 'browser_only' === $mode;
})() );

check( '26a.5 tiktok_test_event_code: Default leer', '' === PMS_Settings::get()['tiktok_test_event_code'] );
check( '26a.6 tiktok_test_event_code: nur alphanumerisch (wie der Meta-Test-Code)', 'TEST12345' === PMS_Settings::sanitize_settings( array( 'tiktok_test_event_code' => ' TEST-123_45 ' ) )['tiktok_test_event_code'] );

check( '26a.7 form_tiktok_event: Default "SubmitForm"', 'SubmitForm' === PMS_Settings::get()['form_tiktok_event'] );
check( '26a.8 form_tiktok_event: Wert außerhalb der kuratierten Liste fällt zurück', 'SubmitForm' === PMS_Settings::sanitize_settings( array( 'form_tiktok_event' => 'CompletePayment' ) )['form_tiktok_event'] );
check( '26a.9 form_tiktok_event: gültiger Wert wird übernommen', 'Contact' === PMS_Settings::sanitize_settings( array( 'form_tiktok_event' => 'Contact' ) )['form_tiktok_event'] );
check( '26a.10 form_google_label: Zeichen-Whitelist wie bei den anderen Conversion-Labels (alphanumerisch plus _ und -)', 'AbCd_12-34' === PMS_Settings::sanitize_settings( array( 'form_google_label' => 'AbCd_12-34<>"/ !' ) )['form_google_label'] );
check( '26a.11 form_tiktok_event_types(): kuratierte Teilmenge, keine Shop-Events', PMS_Settings::form_tiktok_event_types() === array( 'SubmitForm', 'Contact', 'CompleteRegistration', 'Subscribe' ) );

/* --- 26b. PMS_Consent::has_server_consent(): der eigentliche Modus-Schalter.
 * "Kein Consent" wird -- wie in Abschnitt 22e -- über den WP-Consent-API-Stub
 * erzwungen (höchste Priorität in evaluate(), siehe Abschnitt 6); dafür muss
 * consent_detection an sein, sonst greift der Fast-Path in
 * has_marketing_consent() schon vor jeder Auswertung. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge( PMS_Settings::get(), array( 'consent_detection' => 1, 'consent_mode' => 'strict' ) );
$GLOBALS['stub']['wp_consent'] = false;
reset_consent_cache();

check( '26b.1 strict + kein Consent: Browser-Gate blockt', false === PMS_Consent::has_marketing_consent() );
check( '26b.2 strict + kein Consent: Server-Gate blockt ebenfalls (unverändertes Verhalten)', false === PMS_Consent::has_server_consent() );

$GLOBALS['stub']['options']['pms_settings']['consent_mode'] = 'browser_only';
reset_consent_cache();

check( '26b.3 browser_only + kein Consent: Browser-Gate blockt WEITERHIN', false === PMS_Consent::has_marketing_consent() );
check( '26b.4 browser_only + kein Consent: Server-Gate lässt durch (der eigentliche Zweck)', true === PMS_Consent::has_server_consent() );

// Escape-Hatch: pms_has_server_consent kann den flexiblen Modus wieder zumachen.
$GLOBALS['stub']['filters']['pms_has_server_consent'] = static function ( $consent, $strict ) {
	return false;
};
check( '26b.5 Filter pms_has_server_consent kann den flexiblen Modus überstimmen', false === PMS_Consent::has_server_consent() );
unset( $GLOBALS['stub']['filters']['pms_has_server_consent'] );

$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();
check( '26b.6 Consent erteilt: beide Gates identisch true (Modus ohne Wirkung)', true === PMS_Consent::has_marketing_consent() && true === PMS_Consent::has_server_consent() );

/* --- 26c. Ende-zu-Ende: derselbe Seitenaufruf ohne Consent sendet im
 * flexiblen Modus die CAPI, im strikten nicht -- und der Browser-Pixel bleibt
 * in BEIDEN Fällen zurückgehalten (Consent-Bootstrap statt Sofort-Ausgabe). --- */

$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$GLOBALS['stub']['options']['pms_events']         = array(
	array(
		'id' => 'ev-consentmode', 'name' => 'Consent-Modus', 'event_type' => 'Lead', 'match_type' => 'exact',
		'match_value' => '/consent-modus/', 'active' => 1, 'meta_enabled' => 1, 'google_enabled' => 0, 'tiktok_enabled' => 0,
	),
);
$_SERVER['REQUEST_URI'] = '/consent-modus/';

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'pixel_enabled' => 1, 'pixel_id' => '1234567890', 'capi_enabled' => 1, 'capi_token' => 'test-token',
		'consent_detection' => 1, 'consent_mode' => 'strict',
	)
);
$GLOBALS['stub']['wp_consent'] = false;

$strict_html = run_frontend();
check( '26c.1 strict + kein Consent: KEIN CAPI-Request', 0 === count( $GLOBALS['stub']['captured_posts'] ) );
check( '26c.2 strict + kein Consent: Browser-Pixel steckt im Consent-Bootstrap', false !== strpos( $strict_html, 'pmsInitTracking' ) );

$GLOBALS['stub']['options']['pms_settings']['consent_mode'] = 'browser_only';
$flex_html = run_frontend();
check( '26c.3 browser_only + kein Consent: CAPI-Request geht raus', 1 === count( $GLOBALS['stub']['captured_posts'] ) );
check( '26c.4 browser_only + kein Consent: Browser-Pixel wartet trotzdem auf den Bootstrap', false !== strpos( $flex_html, 'pmsInitTracking' ) );
$flex_body = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( '26c.5 browser_only: gesendetes Event trägt den Namen des gematchten Events', 'Lead' === ( $flex_body['data'][0]['event_name'] ?? '' ) );

$GLOBALS['stub']['wp_consent'] = true;
reset_consent_cache();
$GLOBALS['stub']['captured_posts'] = array();

/* --- 26d. enqueue_frontend(): consentMode + die drei neuen Plattform-Felder
 * für den Formular-Grabber. Google/TikTok sind Pro-only -- die Werte dürfen
 * nur rausgehen, wenn die jeweilige Plattform TATSÄCHLICH aktiv ist. --- */

$GLOBALS['stub']['options']['pms_events']   = array();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'pixel_enabled' => 1, 'pixel_id' => '1234567890', 'form_tracking' => 1,
		'consent_detection' => 0, 'consent_mode' => 'browser_only',
		'form_tiktok_event' => 'Contact', 'form_google_label' => 'FormLabel1',
		'google_enabled' => 0, 'google_tag_id' => '', 'tiktok_enabled' => 0, 'tiktok_pixel_id' => '',
	)
);
$cfg_off = run_enqueue();
check( '26d.1 consentMode wird an frontend.js lokalisiert', 'browser_only' === ( $cfg_off['consentMode'] ?? '' ) );
check( '26d.2 TikTok inaktiv: tiktokEvent bleibt leer, obwohl konfiguriert', '' === ( $cfg_off['tiktokEvent'] ?? 'x' ) );
check( '26d.3 Google inaktiv: googleTagId/googleLabel bleiben leer, obwohl ein Label konfiguriert ist', '' === ( $cfg_off['googleTagId'] ?? 'x' ) && '' === ( $cfg_off['googleLabel'] ?? 'x' ) );

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	$GLOBALS['stub']['options']['pms_settings'],
	array( 'google_enabled' => 1, 'google_tag_id' => 'AW-123456789', 'tiktok_enabled' => 1, 'tiktok_pixel_id' => 'TT123' )
);
$cfg_on = run_enqueue();
check( '26d.4 Beide Plattformen aktiv: tiktokEvent trägt den konfigurierten Event-Typ', 'Contact' === ( $cfg_on['tiktokEvent'] ?? '' ) );
check( '26d.5 Beide Plattformen aktiv: googleTagId/googleLabel werden ausgeliefert', 'AW-123456789' === ( $cfg_on['googleTagId'] ?? '' ) && 'FormLabel1' === ( $cfg_on['googleLabel'] ?? '' ) );

/* --- 26e. TikTok Events API: test_event_code als TOP-LEVEL-Feld (nicht in
 * data[]) und contents[] mit explizitem content_id -- Ende-zu-Ende über
 * track_thankyou(), wie in Abschnitt 22e. --- */

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled' => 1, 'pixel_enabled' => 0, 'capi_enabled' => 0, 'capi_token' => '',
		'consent_detection'   => 0,
		'tiktok_enabled'      => 1, 'tiktok_pixel_id' => 'TT123', 'tiktok_capi_enabled' => 1,
		'tiktok_access_token' => 'tt-token', 'tiktok_test_event_code' => 'TEST12345',
	)
);
reset_consent_cache();
$GLOBALS['stub']['captured_posts'] = array();

$tt_order = make_test_order( array( 'id' => 4001 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order->get_id() );
ob_get_clean();

$tt_post = $GLOBALS['stub']['captured_posts'][0] ?? array( 'url' => '', 'args' => array() );
$tt_body = json_decode( $tt_post['args']['body'] ?? '', true );
check( '26e.1 TikTok Events API wird angesprochen', false !== strpos( (string) $tt_post['url'], 'business-api.tiktok.com' ) );
check( '26e.2 test_event_code steht auf oberster Ebene des Requests', 'TEST12345' === ( $tt_body['test_event_code'] ?? '' ) );
check( '26e.3 test_event_code steckt NICHT zusätzlich in data[0]', ! isset( $tt_body['data'][0]['test_event_code'] ) );
check( '26e.4 contents[] trägt für jede Position ein explizites content_id', (function () use ( $tt_body ) {
	$contents = $tt_body['data'][0]['properties']['contents'] ?? array();
	if ( 2 !== count( $contents ) ) {
		return false;
	}
	foreach ( $contents as $item ) {
		if ( ! isset( $item['content_id'] ) || '' === (string) $item['content_id'] ) {
			return false;
		}
	}
	return true;
})() );

$GLOBALS['stub']['options']['pms_settings']['tiktok_test_event_code'] = '';
$GLOBALS['stub']['captured_posts'] = array();
$tt_order2 = make_test_order( array( 'id' => 4002 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order2->get_id() );
ob_get_clean();
$tt_body2 = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( '26e.5 Kein Test-Code konfiguriert -> Feld fehlt komplett (kein leerer String)', ! array_key_exists( 'test_event_code', (array) $tt_body2 ) );

// Browser-Pixel auf der Danke-Seite: dieselbe contents[]-Anforderung.
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	$GLOBALS['stub']['options']['pms_settings'],
	array( 'tiktok_capi_enabled' => 0, 'tiktok_access_token' => '' )
);
$tt_order3 = make_test_order( array( 'id' => 4003 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $tt_order3->get_id() );
$tt_pixel_html = ob_get_clean();
check( '26e.6 Browser-ttq.track(CompletePayment) enthält contents[] mit content_id', false !== strpos( $tt_pixel_html, '"contents":[{"content_id":"501"' ) );

wc_test_reset();
$GLOBALS['stub']['wc_orders']      = array();
$GLOBALS['stub']['captured_posts'] = array();

/* --- 26f. PMS_Logger::is_error_row(): eine Quelle für Badge UND Filter. --- */

check( '26f.1 Fehlertext vorhanden -> Fehler', true === PMS_Logger::is_error_row( array( 'http_status' => 0, 'error_message' => 'boom' ) ) );
check( '26f.2 4xx ohne Fehlertext -> Fehler (bis v0.6.9 nicht als solcher erkannt)', true === PMS_Logger::is_error_row( array( 'http_status' => 400, 'error_message' => null ) ) );
check( '26f.3 http_status 0 ohne Fehlertext -> KEIN Fehler (Fire-and-Forget)', false === PMS_Logger::is_error_row( array( 'http_status' => 0, 'error_message' => null ) ) );
check( '26f.4 2xx -> kein Fehler', false === PMS_Logger::is_error_row( array( 'http_status' => 200, 'error_message' => '' ) ) );

/* --- 26g. Admin-UI: Consent-Callout + Modus-Auswahl (Tab "Allgemein"),
 * TikTok-Test-Code-Feld, Multi-Platform-Formularfelder (Tab "Erweitertes
 * Tracking"), einklappbares Event-Formular (Tab "URL-Events"). PMS_IS_PRO ist
 * ab Abschnitt 17 true, dieser Abschnitt prüft also den Pro-Zweig. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array( 'consent_mode' => 'browser_only', 'tiktok_test_event_code' => 'TESTABC', 'form_google_label' => 'FormLbl', 'form_tiktok_event' => 'Contact' )
);
$GLOBALS['stub']['options']['pms_events'] = array();
$GLOBALS['stub']['current_user_can']      = true;

$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'general';
ob_start();
PMS_Admin::render_page();
$g26 = ob_get_clean();

check( '26g.1 Tab "Allgemein": DSGVO-Info-Callout wird gerendert', false !== strpos( $g26, 'pms-info-callout' ) && false !== strpos( $g26, 'About GDPR blocking:' ) );
check( '26g.2 Tab "Allgemein": Consent-Modus-Auswahl mit beiden Optionen', false !== strpos( $g26, 'name="pms_settings[consent_mode]"' ) && false !== strpos( $g26, 'value="strict"' ) && false !== strpos( $g26, 'value="browser_only"' ) );
check( '26g.3 Tab "Allgemein": gespeicherter Modus ist vorausgewählt', 1 === preg_match( '/value="browser_only"\s+selected="selected"/', $g26 ) );
check( '26g.4 Tab "Allgemein": TikTok-Test-Code ist ein echtes Textfeld mit gespeichertem Wert', 1 === preg_match( '/<input type="text"[^>]*name="pms_settings\[tiktok_test_event_code\]"/', $g26 ) && false !== strpos( $g26, 'value="TESTABC"' ) );

$_GET['tab'] = 'ecommerce';
ob_start();
PMS_Admin::render_page();
$e26 = ob_get_clean();
check( '26g.5 Fremder Tab schützt consent_mode als Hidden-Feld', 1 === preg_match( '/<input type="hidden" name="pms_settings\[consent_mode\]" value="browser_only"/', $e26 ) );
check( '26g.6 Fremder Tab schützt tiktok_test_event_code als Hidden-Feld', 1 === preg_match( '/<input type="hidden" name="pms_settings\[tiktok_test_event_code\]" value="TESTABC"/', $e26 ) );

$_GET['tab'] = 'advanced';
ob_start();
PMS_Admin::render_page();
$a26 = ob_get_clean();
check( '26g.7 Tab "Erweitertes Tracking" (Pro): TikTok-Event-Dropdown mit name-Attribut', false !== strpos( $a26, 'name="pms_settings[form_tiktok_event]"' ) && false !== strpos( $a26, 'value="SubmitForm"' ) );
check( '26g.8 Tab "Erweitertes Tracking" (Pro): Google-Label-Feld trägt den gespeicherten Wert', false !== strpos( $a26, 'name="pms_settings[form_google_label]"' ) && false !== strpos( $a26, 'value="FormLbl"' ) );
check( '26g.9 Tab "Erweitertes Tracking" (Pro): kein Hidden-Duplikat der beiden Keys (echte Felder vorhanden)', 0 === preg_match( '/<input type="hidden" name="pms_settings\[form_google_label\]"/', $a26 ) );
check( '26g.10 Tab "Erweitertes Tracking": Meta-Event-Dropdown existiert unverändert daneben', false !== strpos( $a26, 'name="pms_settings[form_event_type]"' ) );

$_GET['tab'] = 'events';
ob_start();
PMS_Admin::render_page();
$ev26 = ob_get_clean();
check( '26g.11 Tab "URL-Events": Event-Formular ist einklappbar und startet ZU', false !== strpos( $ev26, 'pms-event-form-card pms-collapsible closed' ) && false !== strpos( $ev26, 'data-pms-collapsible' ) );
check( '26g.12 Tab "URL-Events": Klapp-Button meldet aria-expanded="false"', false !== strpos( $ev26, 'aria-expanded="false"' ) );
check( '26g.13 Tab "URL-Events": Formularinhalt liegt im .pms-collapse-body', false !== strpos( $ev26, 'class="pms-collapse-body"' ) );

$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'ev-edit26', 'name' => 'Bearbeiten-Test', 'event_type' => 'Lead', 'match_type' => 'exact',
		'match_value' => '/danke/', 'active' => 1, 'meta_enabled' => 1, 'google_enabled' => 0, 'tiktok_enabled' => 0,
	),
);
$_GET['edit'] = 'ev-edit26';
ob_start();
PMS_Admin::render_page();
$ev26e = ob_get_clean();
unset( $_GET['edit'] );
check( '26g.14 Tab "URL-Events" im Bearbeiten-Modus: Formular startet OFFEN', false === strpos( $ev26e, 'pms-collapsible closed' ) && false !== strpos( $ev26e, 'pms-event-form-card pms-collapsible"' ) );
check( '26g.15 Tab "URL-Events" im Bearbeiten-Modus: aria-expanded="true"', false !== strpos( $ev26e, 'aria-expanded="true"' ) );

/* --- 26h. TikTok Test Event Code: 12h-Auto-Expiry (v0.6.10).
 * Gegenstück zu den Meta-Checks in Abschnitt 4 -- beide laufen seit v0.6.10
 * über dieselbe Implementierung (PMS_Settings::expire_test_code()), deshalb
 * prüft dieser Abschnitt zusätzlich, dass sie sich nicht gegenseitig ins
 * Gehege kommen. --- */

// Zeitstempel-Vergabe in sanitize_settings(), analog zu Abschnitt 4 für Meta.
$GLOBALS['stub']['options']['pms_settings'] = array();
$tt_out = PMS_Settings::sanitize_settings( array( 'tiktok_test_event_code' => 'TESTTT1' ) );
check( '26h.1 Neuer TikTok-Test-Code setzt einen Zeitstempel', abs( time() - $tt_out['tiktok_test_code_created_at'] ) < 5 );

$GLOBALS['stub']['options']['pms_settings'] = array( 'tiktok_test_event_code' => 'TESTTT1', 'tiktok_test_code_created_at' => 12345 );
$tt_out = PMS_Settings::sanitize_settings( array( 'tiktok_test_event_code' => 'TESTTT1' ) );
check( '26h.2 Unveränderter TikTok-Test-Code behält seinen Zeitstempel', 12345 === $tt_out['tiktok_test_code_created_at'] );

$tt_out = PMS_Settings::sanitize_settings( array( 'tiktok_test_event_code' => 'TESTTT2' ) );
check( '26h.3 Geänderter TikTok-Test-Code erneuert den Zeitstempel', abs( time() - $tt_out['tiktok_test_code_created_at'] ) < 5 );

$tt_out = PMS_Settings::sanitize_settings( array( 'tiktok_test_event_code' => '' ) );
check( '26h.4 Leerer TikTok-Test-Code setzt den Zeitstempel auf 0', 0 === $tt_out['tiktok_test_code_created_at'] );

// Meta und TikTok dürfen sich beim gemeinsamen Zeitstempel-Durchlauf nicht
// gegenseitig überschreiben (beide Codes gleichzeitig, unterschiedlich alt).
$GLOBALS['stub']['options']['pms_settings'] = array(
	'test_event_code' => 'METAOLD', 'test_code_created_at' => 111,
	'tiktok_test_event_code' => 'TTOLD', 'tiktok_test_code_created_at' => 222,
);
$both_out = PMS_Settings::sanitize_settings( array( 'test_event_code' => 'METAOLD', 'tiktok_test_event_code' => 'TTNEW' ) );
check( '26h.5 Beide Codes gleichzeitig: Meta behält seinen Zeitstempel, TikTok bekommt einen frischen', 111 === $both_out['test_code_created_at'] && abs( time() - $both_out['tiktok_test_code_created_at'] ) < 5 );

// active_tiktok_test_event_code(): Ablaufgrenze + Selbstheilung der Option.
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array( 'tiktok_test_event_code' => 'TESTTT9', 'tiktok_test_code_created_at' => time() - ( 11 * 3600 ) )
);
check( '26h.6 11h alter TikTok-Code gilt weiterhin', 'TESTTT9' === PMS_Settings::active_tiktok_test_event_code() );
check( '26h.7 11h alter TikTok-Code bleibt in der Datenbank stehen', 'TESTTT9' === PMS_Settings::get()['tiktok_test_event_code'] );

$GLOBALS['stub']['options']['pms_settings']['tiktok_test_code_created_at'] = time() - ( 13 * 3600 );
check( '26h.8 13h alter TikTok-Code gilt nicht mehr', '' === PMS_Settings::active_tiktok_test_event_code() );
$tt_saved = $GLOBALS['stub']['options']['pms_settings'];
check( '26h.9 Abgelaufener TikTok-Code wird in der Datenbank geleert (Code UND Zeitstempel)', '' === $tt_saved['tiktok_test_event_code'] && 0 === $tt_saved['tiktok_test_code_created_at'] );

// Zeitstempel 0 = "kein Ablauf bekannt" (nur bei von Hand gesetzten Werten).
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array( 'tiktok_test_event_code' => 'MANUAL1', 'tiktok_test_code_created_at' => 0 )
);
check( '26h.10 Zeitstempel 0 lässt den Code stehen (kein Ablauf berechenbar)', 'MANUAL1' === PMS_Settings::active_tiktok_test_event_code() );

// Das Ablaufen einer Plattform darf die andere nicht mitreißen.
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'test_event_code' => 'METAFRESH', 'test_code_created_at' => time() - 60,
		'tiktok_test_event_code' => 'TTEXPIRED', 'tiktok_test_code_created_at' => time() - ( 13 * 3600 ),
	)
);
check( '26h.11 Abgelaufener TikTok-Code: Meta-Code bleibt unangetastet', '' === PMS_Settings::active_tiktok_test_event_code() && 'METAFRESH' === PMS_Settings::get()['test_event_code'] );

// Ende-zu-Ende: der abgelaufene Code darf den Events-API-Request nicht mehr erreichen.
// current_user_can hier explizit zurücksetzen -- Abschnitt 26g hat es für die
// Admin-Rendering-Checks auf true gestellt, und should_process() würde sonst
// wegen exclude_admins (Default 1) aussteigen (dieselbe Falle wie in 24e).
$GLOBALS['stub']['current_user_can'] = false;
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled' => 1, 'pixel_enabled' => 0, 'capi_enabled' => 0, 'capi_token' => '',
		'consent_detection'   => 0,
		'tiktok_enabled'      => 1, 'tiktok_pixel_id' => 'TT123', 'tiktok_capi_enabled' => 1,
		'tiktok_access_token' => 'tt-token',
		'tiktok_test_event_code' => 'TESTEXP', 'tiktok_test_code_created_at' => time() - ( 13 * 3600 ),
	)
);
reset_consent_cache();
$GLOBALS['stub']['captured_posts'] = array();

$exp_order = make_test_order( array( 'id' => 4101 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $exp_order->get_id() );
ob_get_clean();
$exp_body = json_decode( $GLOBALS['stub']['captured_posts'][0]['args']['body'] ?? '', true );
check( '26h.12 Ende-zu-Ende: abgelaufener Code fehlt im Events-API-Request komplett', is_array( $exp_body ) && ! array_key_exists( 'test_event_code', $exp_body ) );
check( '26h.13 Ende-zu-Ende: der Request selbst geht trotzdem raus (nur ohne Test-Markierung)', is_array( $exp_body ) && 'CompletePayment' === ( $exp_body['data'][0]['event'] ?? '' ) );

wc_test_reset();
$GLOBALS['stub']['wc_orders']      = array();
$GLOBALS['stub']['captured_posts'] = array();

/* --- 26i. Admin: Der Ablauf räumt schon beim Öffnen der Einstellungsseite
 * auf -- bis v0.6.9 hing das (für Meta) ausschließlich am nächsten
 * CAPI-Request. --- */

$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'test_event_code' => 'METAEXP', 'test_code_created_at' => time() - ( 13 * 3600 ),
		'tiktok_test_event_code' => 'TTEXP', 'tiktok_test_code_created_at' => time() - ( 13 * 3600 ),
	)
);
$GLOBALS['stub']['current_user_can'] = true;
$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'general';

ob_start();
PMS_Admin::render_page();
$exp_html = ob_get_clean();

check( '26i.1 Tab "Allgemein": abgelaufene Codes werden beim Rendern aus der Datenbank geleert', '' === PMS_Settings::get()['test_event_code'] && '' === PMS_Settings::get()['tiktok_test_event_code'] );
check( '26i.2 Tab "Allgemein": beide Felder rendern leer, nicht mit dem abgelaufenen Wert', false === strpos( $exp_html, 'METAEXP' ) && false === strpos( $exp_html, 'TTEXP' ) );
check( '26i.3 Tab "Allgemein": Ablauf-Hinweis erscheint zweimal (einmal je Feld)', 2 === substr_count( $exp_html, 'pms-expired-hint' ) );

// Gegenprobe: frische Codes bleiben stehen und erzeugen keinen Hinweis.
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'test_event_code' => 'METAOK', 'test_code_created_at' => time() - 60,
		'tiktok_test_event_code' => 'TTOK', 'tiktok_test_code_created_at' => time() - 60,
	)
);
ob_start();
PMS_Admin::render_page();
$fresh_html = ob_get_clean();

check( '26i.4 Frische Codes bleiben in den Feldern stehen', false !== strpos( $fresh_html, 'value="METAOK"' ) && false !== strpos( $fresh_html, 'value="TTOK"' ) );
check( '26i.5 Frische Codes erzeugen keinen Ablauf-Hinweis', false === strpos( $fresh_html, 'pms-expired-hint' ) );

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can']        = false;
$GLOBALS['stub']['options']['pms_settings'] = array();

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can']        = false;
$GLOBALS['stub']['options']['pms_settings'] = array();
$GLOBALS['stub']['options']['pms_events']   = array();


echo "\n=== 27. v0.6.11: Event-Log-Plattformachse, TikTok-Events-API-Protokollierung, Google/GA4-Dispatches ===\n";

/* --- 27a. PMS_Logger: neue platform-Spalte --- */

$GLOBALS['stub']['options']['pms_settings'] = array();
PMS_Logger::truncate();

PMS_Logger::record( 'Lead', 'ev-1', 'capi', 200, array( 'em' ), '', PMS_Logger::PLATFORM_META );
PMS_Logger::record( 'CompletePayment', 'ev-2', 'capi', 200, array( 'ip', 'email' ), '', PMS_Logger::PLATFORM_TIKTOK );
PMS_Logger::record( 'Purchase', 'ev-3', 'browser', 0, array(), '', PMS_Logger::PLATFORM_GOOGLE );
PMS_Logger::record( 'Purchase', 'ev-3', 'browser', 0, array(), '', PMS_Logger::PLATFORM_GA4 );

$rows27 = PMS_Logger::get_entries();
check( '27a.1 Alle vier Plattform-Zeilen werden gespeichert', 4 === count( $rows27 ) );
check( '27a.2 record() schreibt die platform-Spalte mit', 'tiktok' === ( $rows27[1]['platform'] ?? '' ) || 'tiktok' === ( $rows27[2]['platform'] ?? '' ) );

// Default-Parameter: Aufrufer aus der Zeit vor v0.6.11 bleiben gültig.
PMS_Logger::record( 'Contact', 'ev-old', 'capi', 0, array() );
$rows_default = PMS_Logger::get_entries( array( 'event_name' => 'Contact' ) );
check( '27a.3 record() ohne platform-Argument schreibt weiterhin "meta"', 'meta' === ( $rows_default[0]['platform'] ?? '' ) );

// Unbekannte Plattform fällt auf meta zurück (Whitelist, kein Freitext).
PMS_Logger::record( 'Contact', 'ev-bad', 'capi', 0, array(), '', 'snapchat' );
$rows_bad = PMS_Logger::get_entries( array( 'event_name' => 'Contact' ) );
$bad_row  = null;
foreach ( $rows_bad as $r ) {
	if ( 'ev-bad' === $r['event_id'] ) {
		$bad_row = $r;
	}
}
check( '27a.4 Unbekannter platform-Wert fällt auf "meta" zurück', is_array( $bad_row ) && 'meta' === $bad_row['platform'] );

// Plattform-Filter.
check( '27a.5 get_entries(): Plattform-Filter "tiktok" liefert nur TikTok-Zeilen', (function () {
	$rows = PMS_Logger::get_entries( array( 'platform' => PMS_Logger::PLATFORM_TIKTOK ) );
	if ( 1 !== count( $rows ) ) {
		return false;
	}
	return 'CompletePayment' === $rows[0]['event_name'];
})() );
check( '27a.6 get_entries(): Plattform-Filter "ga4" trennt GA4 von der Google-Ads-Zeile desselben Events', (function () {
	$ga4 = PMS_Logger::get_entries( array( 'platform' => PMS_Logger::PLATFORM_GA4 ) );
	$ads = PMS_Logger::get_entries( array( 'platform' => PMS_Logger::PLATFORM_GOOGLE ) );
	return 1 === count( $ga4 ) && 1 === count( $ads ) && $ga4[0]['event_id'] === $ads[0]['event_id'];
})() );
check( '27a.7 platform_labels(): genau die vier unterstützten Plattformen', array( 'meta', 'google', 'tiktok', 'ga4' ) === array_keys( PMS_Logger::platform_labels() ) );

PMS_Logger::truncate();

/* --- 27b. PMS_Pro_TikTok_CAPI: Antwort-Auswertung + Protokollierung.
 * TikTok antwortet auch bei FACHLICHEN Fehlern mit HTTP 200 und legt den
 * Status in body.code -- ein reiner Statuscode-Check (wie er für Meta genügt)
 * würde einen abgelehnten Request als Erfolg protokollieren. --- */

$tt_log = new ReflectionProperty( 'PMS_Pro_TikTok_CAPI', 'log' );

// Fire-and-forget (Default): Status unbekannt, aber protokolliert.
$tt_log->setValue( null, array() );
PMS_Logger::truncate();
$GLOBALS['stub']['filters']['pms_tiktok_capi_blocking'] = static function () {
	return false;
};
$GLOBALS['stub']['captured_posts'] = array();
$res = PMS_Pro_TikTok_CAPI::send(
	array( 'event_source' => 'web', 'data' => array() ),
	'tt-token',
	array( 'event_name' => 'CompletePayment', 'event_id' => 'tt-1', 'match_keys' => array( 'ip', 'email' ), 'source' => 'capi' )
);
check( '27b.1 Fire-and-Forget: Status "sent", http_status 0', 'sent' === $res['status'] && 0 === $res['code'] );
$tt_rows = PMS_Logger::get_entries();
check( '27b.2 Fire-and-Forget: Event-Log-Zeile mit Plattform "tiktok" und Match Keys', 1 === count( $tt_rows ) && 'tiktok' === $tt_rows[0]['platform'] && 'ip, email' === $tt_rows[0]['user_data_keys'] );
check( '27b.3 Fire-and-Forget: gilt NICHT als Fehler (http 0 ohne Fehlertext)', false === PMS_Logger::is_error_row( $tt_rows[0] ) );

// Blockierend + echter Erfolg: HTTP 200 UND body.code 0.
$GLOBALS['stub']['filters']['pms_tiktok_capi_blocking'] = static function () {
	return true;
};
$GLOBALS['stub']['http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"code":0,"message":"OK"}',
);
$tt_log->setValue( null, array() );
PMS_Logger::truncate();
$res = PMS_Pro_TikTok_CAPI::send( array( 'data' => array() ), 'tt-token', array( 'event_name' => 'CompletePayment', 'event_id' => 'tt-2', 'match_keys' => array( 'ip' ), 'source' => 'both' ) );
check( '27b.4 Blockierend + code 0: Status "ok" mit HTTP 200', 'ok' === $res['status'] && 200 === $res['code'] );
$tt_rows = PMS_Logger::get_entries();
check( '27b.5 Blockierend: HTTP-Status landet im Event Log (analog zu Meta)', 1 === count( $tt_rows ) && 200 === (int) $tt_rows[0]['http_status'] );
check( '27b.6 Blockierend: source "both" wird durchgereicht', 'both' === $tt_rows[0]['source'] );

// Der eigentliche TikTok-Fallstrick: HTTP 200, aber fachlicher Fehler.
$GLOBALS['stub']['http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"code":40001,"message":"Invalid access token"}',
);
$tt_log->setValue( null, array() );
PMS_Logger::truncate();
$res = PMS_Pro_TikTok_CAPI::send( array( 'data' => array() ), 'bad-token', array( 'event_name' => 'CompletePayment', 'event_id' => 'tt-3', 'match_keys' => array(), 'source' => 'capi' ) );
check( '27b.7 HTTP 200 mit body.code != 0 gilt als FEHLER (nicht als Erfolg)', 'error' === $res['status'] );
check( '27b.8 Fehlermeldung stammt aus body.message', 'Invalid access token' === $res['message'] );
$tt_rows = PMS_Logger::get_entries();
check( '27b.9 Fehlerzeile wird im Event Log als Fehler erkannt', 1 === count( $tt_rows ) && true === PMS_Logger::is_error_row( $tt_rows[0] ) );

// Echter HTTP-Fehler.
$GLOBALS['stub']['http_response'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => 'Internal Server Error',
);
$tt_log->setValue( null, array() );
PMS_Logger::truncate();
$res = PMS_Pro_TikTok_CAPI::send( array( 'data' => array() ), 'tt-token', array( 'event_name' => 'CompletePayment', 'event_id' => 'tt-4', 'source' => 'capi' ) );
check( '27b.10 HTTP 500: Status "error" mit Code 500', 'error' === $res['status'] && 500 === $res['code'] );

check( '27b.11 get_log(): request-lokales Log für die Live-Debug-Leiste wird befüllt', 1 === count( PMS_Pro_TikTok_CAPI::get_log() ) );

unset( $GLOBALS['stub']['http_response'], $GLOBALS['stub']['filters']['pms_tiktok_capi_blocking'] );
$tt_log->setValue( null, array() );
PMS_Logger::truncate();

/* --- 27c. Ende-zu-Ende über track_thankyou(): Meta, TikTok, Google Ads und
 * GA4 erzeugen jeweils EINE eigene Zeile für dieselbe Bestellung. --- */

$GLOBALS['stub']['current_user_can'] = false;
wc_test_reset();
$GLOBALS['stub']['options']['pms_settings'] = array_merge(
	PMS_Settings::get(),
	array(
		'wc_tracking_enabled'        => 1,
		'consent_detection'          => 0,
		'pixel_enabled'              => 1,
		'pixel_id'                   => '1234567890',
		'capi_enabled'               => 1,
		'capi_token'                 => 'test-token',
		'google_enabled'             => 1,
		'google_tag_id'              => 'AW-123456789',
		'wc_google_conversion_label' => 'AbCdEfGh',
		'ga4_measurement_id'         => 'G-ABC123',
		'tiktok_enabled'             => 1,
		'tiktok_pixel_id'            => 'TT123',
		'tiktok_capi_enabled'        => 1,
		'tiktok_access_token'        => 'tt-token',
	)
);
reset_consent_cache();
PMS_Logger::truncate();
$GLOBALS['stub']['captured_posts'] = array();

$log_order = make_test_order( array( 'id' => 5001 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $log_order->get_id() );
ob_get_clean();

$all_rows = PMS_Logger::get_entries();
$by_platform = array();
foreach ( $all_rows as $r ) {
	$by_platform[ $r['platform'] ] = $r;
}

check( '27c.1 Meta-CAPI-Zeile vorhanden', isset( $by_platform['meta'] ) );
check( '27c.2 TikTok-Events-API-Zeile vorhanden (bis v0.6.10 fehlte sie komplett)', isset( $by_platform['tiktok'] ) );
check( '27c.3 Google-Ads-Conversion-Zeile vorhanden', isset( $by_platform['google'] ) );
check( '27c.4 GA4-Purchase-Zeile vorhanden', isset( $by_platform['ga4'] ) );
check( '27c.5 Alle vier Zeilen tragen dieselbe Event-ID (eine Bestellung, vier Ziele)', (function () use ( $by_platform ) {
	$ids = array();
	foreach ( $by_platform as $r ) {
		$ids[] = $r['event_id'];
	}
	return 4 === count( $ids ) && 1 === count( array_unique( $ids ) ) && 'pms_order_5001' === $ids[0];
})() );
check( '27c.6 Google/GA4-Zeilen sind als reine Browser-Dispatches markiert', 'browser' === ( $by_platform['google']['source'] ?? '' ) && 'browser' === ( $by_platform['ga4']['source'] ?? '' ) );
check( '27c.7 TikTok-Zeile dokumentiert die tatsächlich übergebenen Match Keys', false !== strpos( (string) ( $by_platform['tiktok']['user_data_keys'] ?? '' ), 'user_agent' ) );
check( '27c.8 GA4-Zeile trägt keine Match Keys (Standard-E-Commerce-Event ohne Nutzerdaten)', '' === (string) ( $by_platform['ga4']['user_data_keys'] ?? 'x' ) );

/* --- 27d. Kein Conversion-Label -> keine Google-Zeile. Eine protokollierte
 * Conversion, die nie gefeuert hat, wäre schlimmer als gar keine Zeile. --- */

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings']['wc_google_conversion_label'] = '';
PMS_Logger::truncate();
$GLOBALS['stub']['captured_posts'] = array();

$nolabel_order = make_test_order( array( 'id' => 5002 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $nolabel_order->get_id() );
$nolabel_html = ob_get_clean();

$nolabel_platforms = array();
foreach ( PMS_Logger::get_entries() as $r ) {
	$nolabel_platforms[] = $r['platform'];
}
check( '27d.1 Ohne Conversion-Label: KEINE Google-Ads-Zeile', ! in_array( 'google', $nolabel_platforms, true ) );
check( '27d.2 Ohne Conversion-Label: GA4-Zeile bleibt trotzdem (unabhängig von Google Ads)', in_array( 'ga4', $nolabel_platforms, true ) );
check( '27d.3 Ohne Conversion-Label: auch im Quelltext keine Ads-Conversion', false === strpos( $nolabel_html, "gtag('event','conversion'" ) );

/* --- 27e. Advanced Matching: die Google-Zeile dokumentiert die gehashten
 * Enhanced-Conversions-Felder als Match Keys. --- */

wc_test_reset();
$GLOBALS['stub']['options']['pms_settings']['wc_google_conversion_label']    = 'AbCdEfGh';
$GLOBALS['stub']['options']['pms_settings']['wc_purchase_advanced_matching'] = 1;
PMS_Logger::truncate();
$GLOBALS['stub']['captured_posts'] = array();

$am_order = make_test_order( array( 'id' => 5003 ) );
ob_start();
PMS_Pro_Woo_Purchase::track_thankyou( $am_order->get_id() );
ob_get_clean();

$am_google = null;
foreach ( PMS_Logger::get_entries() as $r ) {
	if ( 'google' === $r['platform'] ) {
		$am_google = $r;
	}
}
check( '27e.1 Mit Advanced Matching: Google-Zeile listet die user_data-Felder', is_array( $am_google ) && false !== strpos( $am_google['user_data_keys'], 'email' ) );
check( '27e.2 Mit Advanced Matching: TikTok-Zeile listet email/phone zusätzlich zu ip/user_agent', (function () {
	foreach ( PMS_Logger::get_entries() as $r ) {
		if ( 'tiktok' === $r['platform'] ) {
			return false !== strpos( $r['user_data_keys'], 'email' );
		}
	}
	return false;
})() );

wc_test_reset();
$GLOBALS['stub']['wc_orders']      = array();
$GLOBALS['stub']['captured_posts'] = array();
PMS_Logger::truncate();

/* --- 27f. Formular-Lead: die rein browserseitige Google-Ads-Conversion wird
 * protokolliert, sobald der Client sie meldet. --- */

check( '27f.1 google_fired=1 erzeugt eine Google-Zeile mit source "browser"', (function () {
	PMS_Logger::truncate();
	PMS_Logger::record( 'Lead', 'form-1', 'browser', 0, array(), '', PMS_Logger::PLATFORM_GOOGLE );
	$rows = PMS_Logger::get_entries( array( 'platform' => PMS_Logger::PLATFORM_GOOGLE ) );
	return 1 === count( $rows ) && 'browser' === $rows[0]['source'] && 0 === (int) $rows[0]['http_status'];
})() );

PMS_Logger::truncate();

/* --- 27g. Admin-UI: Plattform-Badge und -Filter im Event-Log-Tab. --- */

PMS_Logger::record( 'Purchase', 'ui-1', 'capi', 200, array( 'em' ), '', PMS_Logger::PLATFORM_META );
PMS_Logger::record( 'CompletePayment', 'ui-2', 'capi', 200, array( 'ip' ), '', PMS_Logger::PLATFORM_TIKTOK );
PMS_Logger::record( 'Purchase', 'ui-3', 'browser', 0, array(), '', PMS_Logger::PLATFORM_GA4 );

$GLOBALS['stub']['current_user_can'] = true;
$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'log';

ob_start();
PMS_Admin::render_page();
$log_html = ob_get_clean();

check( '27g.1 Event-Log-Tab: Plattform-Badges werden gerendert', false !== strpos( $log_html, 'pms-badge-meta' ) && false !== strpos( $log_html, 'pms-badge-tiktok' ) );
check( '27g.2 Event-Log-Tab: GA4 nutzt die Google-Optik, aber die eigene Beschriftung', false !== strpos( $log_html, 'pms-badge-google">GA4' ) );
check( '27g.3 Event-Log-Tab: Plattform-Filter ist vorhanden', false !== strpos( $log_html, 'name="log_platform"' ) && false !== strpos( $log_html, 'All platforms' ) );

// Filter greift (Pro).
$_GET['log_platform'] = 'tiktok';
ob_start();
PMS_Admin::render_page();
$filtered_html = ob_get_clean();
check( '27g.4 Plattform-Filter "tiktok" blendet die Meta-Zeile aus', false !== strpos( $filtered_html, 'ui-2' ) && false === strpos( $filtered_html, 'ui-1' ) );

// Unbekannter Filterwert wird ignoriert statt alles auszublenden.
$_GET['log_platform'] = 'snapchat';
ob_start();
PMS_Admin::render_page();
$bogus_html = ob_get_clean();
check( '27g.5 Unbekannter Plattform-Filter wird ignoriert (alle Zeilen bleiben sichtbar)', false !== strpos( $bogus_html, 'ui-1' ) && false !== strpos( $bogus_html, 'ui-2' ) );

/* --- 27h. Tab-Slug am Wrapper: Grundlage der tab-abhängigen Spaltenbreiten
 * in assets/admin.css (Überschrift/Notice richten sich danach, ob der Tab
 * 900px- oder 960px-Inhalte trägt). Ohne diese Klasse fiele das Layout
 * stillschweigend auf die 900px-Variante zurück. --- */

foreach ( array( 'general', 'events', 'advanced', 'ecommerce', 'log', 'tools' ) as $slug ) {
	$_GET['tab'] = $slug;
	ob_start();
	PMS_Admin::render_page();
	$tab_html = ob_get_clean();
	check(
		'27h Tab "' . $slug . '": Wrapper trägt die Klasse pms-tab-' . $slug,
		false !== strpos( $tab_html, 'class="wrap pms-wrap pms-tab-' . $slug . '"' )
	);
}

unset( $_GET['log_platform'], $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can'] = false;
PMS_Logger::truncate();
$GLOBALS['stub']['options']['pms_settings'] = array();


echo "\n=== 28. v0.6.12: Free-Locks (serverseitig), vereinheitlichte Badges, Info & Hilfe ===\n";

/* --- 28a. resolve_event_platforms(): die serverseitige Durchsetzung der
 * Pro-only-Plattformen. Bewusst mit explizitem $is_pro-Argument getestet --
 * die Konstante PMS_IS_PRO steht ab Abschnitt 17 auf true und lässt sich im
 * selben Prozess nicht mehr umdefinieren. --- */

$post_all_on = array(
	'platform_meta'   => true,
	'platform_google' => true,
	'platform_tiktok' => true,
	'google_label'    => 'HackLabel1',
	'tiktok_event'    => 'CompletePayment',
);

// Pro: alles wird übernommen.
$pro_res = call_private( 'PMS_Admin', 'resolve_event_platforms', $post_all_on, array(), true );
check( '28a.1 Pro: Google/TikTok aus dem Formular werden übernommen', true === $pro_res['google_enabled'] && true === $pro_res['tiktok_enabled'] );
check( '28a.2 Pro: Conversion-Label und TikTok-Event kommen aus dem Formular', 'HackLabel1' === $pro_res['google_label'] && 'CompletePayment' === $pro_res['tiktok_event'] );

// Free + NEUES Event: der POST darf Google/TikTok nicht aktivieren.
$free_new = call_private( 'PMS_Admin', 'resolve_event_platforms', $post_all_on, array(), false );
check( '28a.3 Free (neues Event): platform_google aus dem POST wird ignoriert', false === $free_new['google_enabled'] );
check( '28a.4 Free (neues Event): platform_tiktok aus dem POST wird ignoriert', false === $free_new['tiktok_enabled'] );
check( '28a.5 Free (neues Event): untergeschobenes Conversion-Label wird verworfen', '' === $free_new['google_label'] );
check( '28a.6 Free (neues Event): untergeschobener TikTok-Event-Typ wird verworfen', '' === $free_new['tiktok_event'] );
check( '28a.7 Free: Meta bleibt unangetastet (kein Pro-Feature)', true === $free_new['meta_enabled'] );

// Free + BEARBEITEN eines Events, das aus einer Pro-Phase noch eine
// Google-/TikTok-Konfiguration trägt: die muss erhalten bleiben.
$stored_pro_event = array(
	'google_enabled' => 1,
	'google_label'   => 'EchtesLabel',
	'tiktok_enabled' => 1,
	'tiktok_event'   => 'SubmitForm',
);
$free_edit = call_private( 'PMS_Admin', 'resolve_event_platforms', array( 'platform_meta' => true ), $stored_pro_event, false );
check( '28a.8 Free (Downgrade): gespeicherte Google-Konfiguration überlebt das Bearbeiten', true === $free_edit['google_enabled'] && 'EchtesLabel' === $free_edit['google_label'] );
check( '28a.9 Free (Downgrade): gespeicherte TikTok-Konfiguration überlebt das Bearbeiten', true === $free_edit['tiktok_enabled'] && 'SubmitForm' === $free_edit['tiktok_event'] );

// Free darf eine gespeicherte Konfiguration auch nicht ÄNDERN.
$free_tamper = call_private( 'PMS_Admin', 'resolve_event_platforms', $post_all_on, $stored_pro_event, false );
check( '28a.10 Free (Downgrade): der POST kann das gespeicherte Label nicht überschreiben', 'EchtesLabel' === $free_tamper['google_label'] );
check( '28a.11 Free (Downgrade): der POST kann den gespeicherten TikTok-Event nicht überschreiben', 'SubmitForm' === $free_tamper['tiktok_event'] );

// Free kann eine gespeicherte Pro-Konfiguration auch nicht DEAKTIVIEREN
// (das Formular sendet die Felder gar nicht mit -- der Zustand bleibt).
$free_off = call_private( 'PMS_Admin', 'resolve_event_platforms', array( 'platform_meta' => true ), $stored_pro_event, false );
check( '28a.12 Free: ein fehlendes Feld schaltet die gespeicherte Plattform nicht ab', true === $free_off['google_enabled'] );

// Label-Trimming/Sanitizing bleibt im Pro-Zweig aktiv.
$pro_trim = call_private( 'PMS_Admin', 'resolve_event_platforms', array( 'platform_google' => true, 'google_label' => '  AbC123  ' ), array(), true );
check( '28a.13 Pro: Conversion-Label wird getrimmt', 'AbC123' === $pro_trim['google_label'] );

/* --- 28b. Events-Tabelle: einheitliche Badge-Klasse (v0.6.12). --- */

$GLOBALS['stub']['options']['pms_settings'] = array();
$GLOBALS['stub']['options']['pms_events_enabled'] = 1;
$GLOBALS['stub']['options']['pms_events'] = array(
	array(
		'id' => 'ev-badge', 'name' => 'Alle Plattformen', 'event_type' => 'Lead',
		'match_type' => 'exact', 'match_value' => '/danke/', 'active' => 1,
		'meta_enabled' => 1, 'google_enabled' => 1, 'google_label' => 'AbCd1234',
		'tiktok_enabled' => 1, 'tiktok_event' => 'SubmitForm',
	),
);
$GLOBALS['stub']['current_user_can'] = true;
$_GET['page'] = PMS_Admin::PAGE_SLUG;
$_GET['tab']  = 'events';

ob_start();
PMS_Admin::render_page();
$ev_html = ob_get_clean();

check( '28b.1 Alle drei Plattform-Badges nutzen dieselbe Klasse pms-badge-active', 3 === substr_count( $ev_html, 'pms-badge pms-badge-active' ) );
check( '28b.2 Keine markenbunten Badge-Klassen mehr in der Events-Tabelle', false === strpos( $ev_html, 'pms-badge-meta' ) && false === strpos( $ev_html, 'pms-badge-google' ) && false === strpos( $ev_html, 'pms-badge-tiktok' ) );
check( '28b.3 Die Badge-Texte benennen die Plattform weiterhin ausdrücklich', false !== strpos( $ev_html, 'Meta · Lead' ) && false !== strpos( $ev_html, 'Google Ads · AbCd1234' ) && false !== strpos( $ev_html, 'TikTok · SubmitForm' ) );

/* --- 28c. Event-Log behält die Plattform-Farben: dort trägt jede Zeile genau
 * EIN Badge, das als Kategorie-Kennung dient (siehe CSS-Kommentar). --- */

PMS_Logger::truncate();
PMS_Logger::record( 'Purchase', 'badge-1', 'capi', 200, array(), '', PMS_Logger::PLATFORM_TIKTOK );
$_GET['tab'] = 'log';
ob_start();
PMS_Admin::render_page();
$log_badge_html = ob_get_clean();
check( '28c.1 Event-Log nutzt weiterhin die plattformspezifische Badge-Klasse', false !== strpos( $log_badge_html, 'pms-badge-tiktok' ) );
PMS_Logger::truncate();

/* --- 28d. Pro-Zweig der gesperrten Controls: In Pro darf KEIN PRO-Badge und
 * kein disabled-Attribut auftauchen (Gegenprobe zur Free-Ansicht, die
 * dev-tools/preview-admin.php rendert -- PMS_IS_PRO ist hier true). --- */

$_GET['tab'] = 'events';
ob_start();
PMS_Admin::render_page();
$ev_pro_html = ob_get_clean();

check( '28d.1 Pro: Event-Formular zeigt kein PRO-Badge', false === strpos( $ev_pro_html, 'pms-pro-inline' ) );
check( '28d.2 Pro: Google-/TikTok-Zeilen sind nicht gesperrt', false === strpos( $ev_pro_html, 'pms-platform-row pms-locked' ) );
check( '28d.3 Pro: die Plattform-Felder tragen ihre name-Attribute', false !== strpos( $ev_pro_html, 'name="platform_google"' ) && false !== strpos( $ev_pro_html, 'name="platform_tiktok"' ) && false !== strpos( $ev_pro_html, 'name="google_label"' ) && false !== strpos( $ev_pro_html, 'name="tiktok_event"' ) );

$_GET['tab'] = 'advanced';
ob_start();
PMS_Admin::render_page();
$adv_pro_html = ob_get_clean();
check( '28d.4 Pro: Tab "Erweitertes Tracking" zeigt kein PRO-Badge', false === strpos( $adv_pro_html, 'pms-pro-inline' ) );

/* --- 28e. E-Commerce-Upgrade-Callout: nur ohne Pro. Im Harness ist Pro aktiv
 * UND WooCommerce vorhanden -- der Callout darf hier also NICHT erscheinen. --- */

$_GET['tab'] = 'ecommerce';
ob_start();
PMS_Admin::render_page();
$eco_pro_html = ob_get_clean();
check( '28e.1 Pro + WooCommerce: kein Upgrade-Callout', false === strpos( $eco_pro_html, 'pms-info-callout-upgrade' ) );
check( '28e.2 Pro + WooCommerce: die echte Accordion rendert statt eines Teasers', false !== strpos( $eco_pro_html, 'name="pms_settings[wc_content_id_type]"' ) );

/* --- 28f. Info & Hilfe: Support, Branding, Doku und Tutorial-Hub. --- */

ob_start();
PMS_Admin::render_help_page();
$help_html = ob_get_clean();

check( '28f.1 Support-Adresse ist support@pixelmadesimple.com', false !== strpos( $help_html, 'mailto:support@pixelmadesimple.com' ) );
check( '28f.2 Die alte persönliche Support-Adresse taucht nicht mehr auf', false === strpos( $help_html, 'seitzdominik.de' ) );
check( '28f.3 Entwickler-Hinweis verlinkt auf pixelmadesimple.com', false !== strpos( $help_html, 'href="https://pixelmadesimple.com"' ) && false !== strpos( $help_html, 'Pixel Made Simple – Dominik Seitz' ) );
check( '28f.4 Dokumentations-Button verlinkt auf /docs', false !== strpos( $help_html, 'https://pixelmadesimple.com/docs' ) );
check( '28f.5 Tutorial-Grid wird gerendert', false !== strpos( $help_html, 'pms-tutorial-grid' ) );
check( '28f.6 Genau vier Tutorial-Karten', 4 === substr_count( $help_html, 'class="pms-tutorial-card"' ) );
check( '28f.7 Alle vier Themen sind vertreten', (function () use ( $help_html ) {
	foreach ( array( 'Quick start', 'Meta CAPI setup', 'Google Ads & GA4', 'E-commerce tracking' ) as $title ) {
		if ( false === strpos( $help_html, $title ) ) {
			return false;
		}
	}
	return true;
})() );
check( '28f.8 Karten und Sammel-Button verlinken auf die Tutorial-Übersicht', 5 === substr_count( $help_html, 'https://pixelmadesimple.com/tutorials' ) );
check( '28f.9 Version wird angezeigt', false !== strpos( $help_html, 'v' . PMS_VERSION ) );

unset( $_GET['tab'], $_GET['page'] );
$GLOBALS['stub']['current_user_can']        = false;
$GLOBALS['stub']['options']['pms_settings'] = array();
$GLOBALS['stub']['options']['pms_events']   = array();


echo "\n==============================\n";
echo 'Ergebnis: ' . $GLOBALS['t_pass'] . ' bestanden, ' . $GLOBALS['t_fail'] . " fehlgeschlagen\n";
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
