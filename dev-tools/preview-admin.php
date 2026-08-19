<?php
/**
 * Rendert die echten Admin-Tabs als statische HTML-Dateien mit echten
 * WP-Core-Styles (via jsDelivr-CDN), um die UI ohne WordPress-Installation
 * im Browser zu prüfen. Ausgabe landet neben diesem Skript.
 *
 * Ausführen:  & "C:\php\php.exe" dev-tools\preview-admin.php
 * Dann z. B.: & "C:\php\php.exe" -S localhost:8321 -t dev-tools
 * und im Browser-Tool http://localhost:8321/preview-general.html öffnen.
 *
 * WICHTIG: Erzeugt reines Vorschau-HTML mit Fake-Daten – speichert NICHTS
 * in einer echten WordPress-Installation und sendet keine echten Requests.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
// false = Free-Ansicht (Pro-Teaser für UTM/Export, Event-Limit greift);
// auf true setzen, um die Pro-Ansicht zu generieren.
define( 'PMS_IS_PRO', false );
define( 'PMS_VERSION', '0.6.2' );
define( 'PMS_PLUGIN_FILE', __FILE__ );
define( 'PMS_PLUGIN_URL', './' );
define( 'ARRAY_A', 'ARRAY_A' );

/* --- Minimal-Stubs für das Admin-Rendering --- */
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_attr__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_html_e( $t, $d = null ) { echo htmlspecialchars( $t, ENT_QUOTES ); }
function esc_attr_e( $t, $d = null ) { echo htmlspecialchars( $t, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
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
function get_date_from_gmt( $gmt_date, $format = 'Y-m-d H:i:s' ) {
	return gmdate( $format, strtotime( $gmt_date . ' UTC' ) );
}
function submit_button( $text = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) {
	$btn = '<input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '" />';
	echo $wrap ? '<p class="submit">' . $btn . '</p>' : $btn;
}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="preview" />'; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) { $args = array( $args => $url ); $url = func_get_arg( 2 ); }
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function get_option( $name, $default = false ) {
	// Anpassen für andere Vorschau-Szenarien (z. B. form_url_filter leeren,
	// um den Konflikt-Hinweis auszublenden).
	if ( 'pms_settings' === $name ) {
		return array(
			'pixel_enabled' => 1, 'pixel_id' => '1234567890123456', 'capi_enabled' => 1,
			'capi_token' => 'EAAB...preview...token', 'test_event_code' => 'TEST12345',
			'exclude_admins' => 1, 'hash_email' => 0, 'consent_detection' => 1,
			'google_enabled' => 1, 'google_tag_id' => 'AW-123456789', 'google_consent_mode' => 1,
			'tiktok_enabled' => 0, 'tiktok_pixel_id' => '',
			'form_tracking' => 1, 'utm_passthrough' => 1, 'debug_bar' => 1,
			// Konfliktfall: gleiche URL wie die Lead-Event-Regel unten (zeigt die Warn-Notice).
			'form_event_type' => 'Lead', 'form_url_filter' => '/bestaetigung',
			'form_exclude_system' => 1,
		);
	}
	if ( 'pms_events' === $name ) {
		return array(
			array(
				'id' => 'ev1', 'name' => 'Lead Magnet Bestätigung', 'event_type' => 'Lead',
				'match_type' => 'exact', 'match_value' => '/bestaetigung/', 'active' => 1,
				'meta_enabled' => 1, 'google_enabled' => 1, 'google_label' => 'AbCdEfGhIjK123',
				'tiktok_enabled' => 1, 'tiktok_event' => 'SubmitForm',
			),
			array(
				'id' => 'ev2', 'name' => 'Kontakt Danke', 'event_type' => 'Contact',
				'match_type' => 'contains', 'match_value' => 'kontakt-danke', 'active' => 1,
				'meta_enabled' => 1, 'google_enabled' => 0, 'google_label' => '',
				'tiktok_enabled' => 0, 'tiktok_event' => 'CompleteRegistration',
			),
			// Drittes Event: im Free-Preview (PMS_IS_PRO=false) sorgt es dafür,
			// dass das Gesamt-Limit von 2 (seit v0.6.2, siehe FREE_EVENT_LIMIT)
			// bereits überschritten ist -- zeigt die Warn-Notice oben im Tab UND
			// den deaktivierten "Event hinzufügen"-Button im Formular darunter.
			array(
				'id' => 'ev3', 'name' => 'Newsletter Anmeldung', 'event_type' => 'Lead',
				'match_type' => 'exact', 'match_value' => '/newsletter-danke/', 'active' => 0,
				'meta_enabled' => 1, 'google_enabled' => 0, 'google_label' => '',
				'tiktok_enabled' => 0, 'tiktok_event' => 'CompleteRegistration',
			),
		);
	}
	if ( 'pms_events_enabled' === $name ) { return 1; }
	if ( 'date_format' === $name ) { return 'Y-m-d'; }
	if ( 'time_format' === $name ) { return 'H:i'; }
	return $default;
}
function add_action() {}
function add_filter() {}
function plugin_basename( $f ) { return basename( $f ); }

/**
 * Minimaler $wpdb-Ersatz nur für PMS_Logger::get_entries() (Event-Log-Tab-
 * Vorschau) -- liefert drei feste Beispielzeilen, die die drei Status-Badge-
 * Varianten (neutral/ok/error) und source-Varianten abdecken. insert()/
 * delete()/query() werden vom reinen Tab-Rendering nie aufgerufen, aber
 * sicherheitshalber als No-Ops vorhanden.
 */
class PMS_Preview_Wpdb {
	public $prefix = 'wp_';
	public function get_charset_collate() { return ''; }
	public function get_results( $query, $output = null ) {
		return array(
			array(
				'id' => 3, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ),
				'event_name' => 'Lead', 'event_id' => 'abcd1234-ef56-7890-abcd-1234567890ab',
				'source' => 'both', 'http_status' => 0,
				'user_data_keys' => 'em, fbc, client_ip_address', 'error_message' => null,
			),
			array(
				'id' => 2, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'event_name' => 'Purchase', 'event_id' => 'efgh5678-ab12-3456-efgh-567890abcdef',
				'source' => 'capi', 'http_status' => 200,
				'user_data_keys' => 'client_ip_address, client_user_agent', 'error_message' => null,
			),
			array(
				'id' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
				'event_name' => 'Lead', 'event_id' => 'ijkl9012-cd34-5678-ijkl-901234567890',
				'source' => 'capi', 'http_status' => 400,
				'user_data_keys' => 'client_ip_address', 'error_message' => 'Invalid parameter: access_token',
			),
		);
	}
	public function insert( $table, $data, $format = null ) { return 1; }
	public function delete( $table, $where, $format = null ) { return 0; }
	public function query( $sql ) { return true; }
}
$GLOBALS['wpdb'] = new PMS_Preview_Wpdb();

$base = __DIR__ . '/../src/';
require $base . 'includes/class-pms-settings.php';
require $base . 'includes/class-pms-logger.php';
require $base . 'includes/class-pms-admin.php';
require $base . 'includes/admin/class-pms-admin-event-log.php';

$admin_css = file_get_contents( $base . 'assets/admin.css' );
$admin_js  = file_get_contents( $base . 'assets/admin.js' );

$wp  = 'https://cdn.jsdelivr.net/gh/WordPress/WordPress@6.7.1';
$head = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="$wp/wp-includes/css/dashicons.min.css">
<link rel="stylesheet" href="$wp/wp-includes/css/buttons.min.css">
<link rel="stylesheet" href="$wp/wp-admin/css/common.min.css">
<link rel="stylesheet" href="$wp/wp-admin/css/forms.min.css">
<link rel="stylesheet" href="$wp/wp-admin/css/list-tables.min.css">
<link rel="stylesheet" href="$wp/wp-admin/css/nav-menus.min.css">
<style>body{background:#f0f0f1;padding:20px 20px 40px;}#wpcontent{padding:0;}</style>
<style>$admin_css</style>
</head>
<body class="wp-admin wp-core-ui js">
<div id="wpcontent"><div id="wpbody-content">
HTML;

$foot = "</div></div>"
	. "<script>window.pmsAdmin={ajaxUrl:'/fake-ajax',nonce:'preview',savedText:'Gespeichert.'};"
	. "window.pmsFetchCalls=[];window.fetch=function(url,opts){window.pmsFetchCalls.push({url:url,body:opts&&opts.body});return Promise.resolve({json:function(){return Promise.resolve({success:true});}});};</script>"
	. "<script>$admin_js</script></body></html>";

foreach ( array( 'general', 'events', 'events-edit', 'advanced', 'log', 'tools', 'help' ) as $view ) {
	$_GET = array( 'page' => 'pms-settings' );
	ob_start();
	if ( 'help' === $view ) {
		PMS_Admin::render_help_page();
	} else {
		if ( 'events' === $view || 'events-edit' === $view ) {
			$_GET['tab'] = 'events';
		}
		if ( 'advanced' === $view || 'log' === $view || 'tools' === $view ) {
			$_GET['tab'] = $view;
		}
		if ( 'events-edit' === $view ) {
			$_GET['edit'] = 'ev1';
		}
		PMS_Admin::render_page();
	}
	$body = ob_get_clean();
	file_put_contents( __DIR__ . "/preview-$view.html", $head . $body . $foot );
	echo "geschrieben: preview-$view.html\n";
}
