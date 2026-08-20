<?php
/**
 * Plugin Name:       Pixel Made Simple Pro
 * Plugin URI:        https://pixelmadesimple.com
 * Description:       Pro add-on for Pixel Made Simple: everything in the free version plus [Pro-only features go here].
 * Version:           0.6.4
 * Author:            Dominik Seitz
 * Author URI:        https://sdv.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixel-made-simple
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Text Domain ist bewusst identisch mit der Free-Version (nicht
 * "pixel-made-simple-pro"): Pro lädt dieselbe languages/-Übersetzung wie
 * Free, damit es keinen zweiten, separat zu pflegenden Übersetzungskatalog
 * braucht. Der Plugin-*Slug* (Ordner-/Dateiname) bleibt trotzdem eigenständig.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Gegenseitiger Kollisionsschutz Free <-> Pro (siehe pixel-made-simple.php
 * für die spiegelbildliche Guard und die ausführliche Begründung, warum das
 * bewusst ASYMMETRISCH ist: Pro deaktiviert im Kollisionsfall immer Free,
 * nie sich selbst, damit Pro spätestens ab dem nächsten Request gewinnt).
 */
if ( defined( 'PMS_IS_PRO' ) && false === PMS_IS_PRO ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Pixel Made Simple Pro is already active. The free version is not needed and has been deactivated automatically.', 'pixel-made-simple' ) . '</p></div>';
		}
	);

	if ( is_admin() ) {
		$free_plugin = 'pixel-made-simple/pixel-made-simple.php';
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $free_plugin );
		}
	}

	return;
}

define( 'PMS_IS_PRO', true );
define( 'PMS_VERSION', '0.6.4' );
define( 'PMS_PLUGIN_FILE', __FILE__ );
define( 'PMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Deaktiviert Pixel Made Simple (Free) automatisch, falls es beim Aktivieren
 * von Pro noch aktiv ist (beide teilen sich denselben Options-Key
 * "pms_settings" und dürfen nie gleichzeitig laufen).
 *
 * @return void
 */
function pms_pro_deactivate_free_on_activation() {
	$free_plugin = 'pixel-made-simple/pixel-made-simple.php';

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( $free_plugin ) ) {
		deactivate_plugins( $free_plugin );
	}
}
register_activation_hook( __FILE__, 'pms_pro_deactivate_free_on_activation' );
// Eigene, unabhängige register_activation_hook()-Registrierung fürs Event-Log
// (WordPress erlaubt mehrere pro Datei, sie feuern der Reihe nach) -- legt die
// Log-Tabelle sofort an und plant den täglichen Retention-Cron ein, statt auf
// den nächsten plugins_loaded-Request zu warten.
register_activation_hook( __FILE__, array( 'PMS_Logger', 'activate' ) );

/*
 * Automatische Updates via GitHub Releases (YahnisElsts/plugin-update-checker
 * v5). Nur die Pro-Version prüft hier selbst auf Updates – die Free-Version
 * wird über WordPress.org aktualisiert und darf keinen zweiten Update-
 * Mechanismus registrieren.
 *
 * Das GitHub-Release trägt sowohl das Free- als auch das Pro-ZIP als Asset;
 * der Regex in enableReleaseAssets() sorgt dafür, dass Pro ausschließlich
 * sein eigenes ZIP installiert.
 */
if ( file_exists( PMS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once PMS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	$pms_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Seitzdominik/pixel-made-simple/',
		__FILE__,
		'pixel-made-simple-pro'
	);
	$pms_update_checker->getVcsApi()->enableReleaseAssets( '/^pixel-made-simple-pro\.zip$/' );
}

/**
 * Übersetzungen aus /languages laden (identisch mit der Free-Version, siehe
 * Hinweis im Datei-Header oben).
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
require_once PMS_PLUGIN_DIR . 'pro/class-pro-features.php';
require_once PMS_PLUGIN_DIR . 'pro/class-pro-utm.php';
require_once PMS_PLUGIN_DIR . 'pro/class-pro-woo-product-data.php';
require_once PMS_PLUGIN_DIR . 'pro/class-pro-woo.php';
require_once PMS_PLUGIN_DIR . 'pro/class-pro-woo-purchase.php';

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
PMS_Pro_Features::init();
PMS_Pro_UTM::init();
PMS_Pro_WooCommerce::init();
PMS_Pro_Woo_Purchase::init();

// Die Live-Debug-Leiste registriert sich erst, wenn ein Administrator im
// Frontend unterwegs ist – reguläre Besucher erzeugen keinerlei Overhead.
// Priorität 5: muss vor PMS_Frontend::prepare() (20) laufen, damit der
// CAPI-Request für echte Statuscodes blockierend gesendet wird.
add_action( 'wp', array( 'PMS_Debug', 'init' ), 5 );
