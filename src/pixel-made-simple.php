<?php
/**
 * Plugin Name:       Pixel Made Simple
 * Plugin URI:        https://pixelmadesimple.com
 * Description:       Lightweight, high-performance tracking for Meta Pixel & Conversions API, Google Ads (Consent Mode v2) and TikTok Pixel – with URL-based multi-platform events and clean event deduplication.
 * Version:           0.6.9
 * Author:            Dominik Seitz
 * Author URI:        https://sdv.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixel-made-simple
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

/*
 * Gegenseitiger Kollisionsschutz Free <-> Pro (siehe pixel-made-simple-pro.php
 * für die spiegelbildliche Guard). Beide Haupt-Dateien inkludieren dieselben
 * Klassen aus includes/; liefen beide durch, würde WordPress "Cannot redeclare
 * class PMS_Settings" werfen – am wahrscheinlichsten, wenn Free und Pro im
 * selben Bulk-Activate-Request aktiviert oder per Bulk-Update gleichzeitig
 * aktualisiert werden. Deshalb bricht die zweite Datei, die in einem
 * PHP-Prozess lädt, hier sofort ab, statt fatal zu enden.
 *
 * Asymmetrisch mit Absicht: Pro soll im Kollisionsfall immer die Version
 * sein, die am Ende aktiv bleibt. Lädt Free zuerst und Pro erkennt die
 * Kollision, kann Pro in DIESEM Request trotzdem nicht weiterladen (Free hat
 * die geteilten Klassen bereits deklariert) – Pro deaktiviert deshalb Free
 * (nicht sich selbst), sodass ab dem NÄCHSTEN Request nur noch Pro lädt, ganz
 * normal ohne Guard. Lädt Free zuerst und erkennt seinerseits, dass Pro schon
 * aktiv ist, deaktiviert Free direkt sich selbst – Pro läuft in diesem Fall
 * bereits vollständig im selben Request weiter.
 */
if ( defined( 'PMS_IS_PRO' ) && true === PMS_IS_PRO ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Pixel Made Simple Pro is already active. The free version is not needed and has been deactivated automatically.', 'pixel-made-simple' ) . '</p></div>';
		}
	);

	if ( is_admin() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}

	return;
}

define( 'PMS_IS_PRO', false );
define( 'PMS_VERSION', '0.6.9' );
define( 'PMS_PLUGIN_FILE', __FILE__ );
define( 'PMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Deaktiviert Pixel Made Simple Pro automatisch, falls es beim Aktivieren
 * der Free-Version noch aktiv ist (Free und Pro teilen sich denselben
 * Options-Key "pms_settings" und dürfen nie gleichzeitig laufen).
 *
 * @return void
 */
function pms_free_deactivate_pro_on_activation() {
	$pro_plugin = 'pixel-made-simple-pro/pixel-made-simple-pro.php';

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( $pro_plugin ) ) {
		deactivate_plugins( $pro_plugin );
	}
}
register_activation_hook( __FILE__, 'pms_free_deactivate_pro_on_activation' );
// Eigene, unabhängige register_activation_hook()-Registrierung fürs Event-Log
// (WordPress erlaubt mehrere pro Datei, sie feuern der Reihe nach) -- legt die
// Log-Tabelle sofort an und plant den täglichen Retention-Cron ein, statt auf
// den nächsten plugins_loaded-Request zu warten.
register_activation_hook( __FILE__, array( 'PMS_Logger', 'activate' ) );

/**
 * Übersetzungen aus /languages laden (z. B. von Loco Translate generierte
 * pixel-made-simple-de_DE.mo). Domain und Sprachordner sind mit der Pro-
 * Version identisch, damit beide dieselbe Übersetzungsdatei nutzen.
 *
 * function_exists()-Guard: Der Kollisionsguard oben deckt diesen Fall
 * bereits ab (er kehrt per return zurück, bevor diese Zeile je erreicht
 * wird) – der zusätzliche Guard hier ist bewusste Doppelabsicherung, falls
 * eine künftige Änderung versehentlich Code vor den Kollisionsguard
 * verschiebt oder eine dritte Stelle diese Datei erneut einbindet.
 */
if ( ! function_exists( 'pms_load_textdomain' ) ) {
	function pms_load_textdomain() {
		load_plugin_textdomain(
			'pixel-made-simple',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
}
add_action( 'init', 'pms_load_textdomain' );

require_once PMS_PLUGIN_DIR . 'includes/class-pms-settings.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-logger.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-consent.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-capi.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-frontend.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-forms.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-debug.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-tools.php';

// KEIN class-pms-attribution.php hier: First-Touch/UTM-Attribution und der
// automatische Formular-Fill sind ein Pro-Feature (pro/class-pro-utm.php,
// nur von pixel-made-simple-pro.php geladen). PMS_CAPI/PMS_Debug/PMS_Frontend
// prüfen konsequent class_exists('PMS_Pro_UTM') und degradieren in der Free-
// Version auf "keine Attribution-Daten", statt zu fataln.

if ( is_admin() ) {
	require_once PMS_PLUGIN_DIR . 'includes/class-pms-admin.php';
	require_once PMS_PLUGIN_DIR . 'includes/admin/class-pms-admin-event-log.php';
	PMS_Admin::init();
	PMS_Admin_Event_Log::init();
}

PMS_Logger::init();
PMS_Frontend::init();
PMS_Forms::init();
PMS_Tools::init();

// Die Live-Debug-Leiste registriert sich erst, wenn ein Administrator im
// Frontend unterwegs ist – reguläre Besucher erzeugen keinerlei Overhead.
// Priorität 5: muss vor PMS_Frontend::prepare() (20) laufen, damit der
// CAPI-Request für echte Statuscodes blockierend gesendet wird.
add_action( 'wp', array( 'PMS_Debug', 'init' ), 5 );
