<?php
/**
 * Plugin Name:       Lightweight Meta Pixel & CAPI Tracker
 * Plugin URI:        https://sdv.design
 * Description:       Lightweight, high-performance tracking for Meta Pixel & Conversions API, Google Ads (Consent Mode v2) and TikTok Pixel – with URL-based multi-platform events and clean event deduplication.
 * Version:           0.5.3
 * Author:            Dominik Seitz
 * Author URI:        https://sdv.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lightweight-meta-pixel-capi-tracker
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'LMPCT_VERSION', '0.5.3' );
define( 'LMPCT_PLUGIN_FILE', __FILE__ );
define( 'LMPCT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LMPCT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Automatische Updates via GitHub Releases (YahnisElsts/plugin-update-checker v5).
 * Der Update-Checker vergleicht die installierte Version mit dem neuesten
 * GitHub-Release und installiert das dort angehängte Release-Asset (ZIP).
 */
if ( file_exists( LMPCT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once LMPCT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	$lmpct_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Seitzdominik/lightweigth-tracker/',
		__FILE__,
		'lightweight-meta-pixel-capi-tracker'
	);
	$lmpct_update_checker->getVcsApi()->enableReleaseAssets();
}

/**
 * Übersetzungen aus /languages laden (z. B. von Loco Translate generierte
 * lightweight-meta-pixel-capi-tracker-de_DE.mo).
 */
function lmpct_load_textdomain() {
	load_plugin_textdomain(
		'lightweight-meta-pixel-capi-tracker',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'lmpct_load_textdomain' );

require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-settings.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-consent.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-attribution.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-capi.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-frontend.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-forms.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-debug.php';
require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-tools.php';

if ( is_admin() ) {
	require_once LMPCT_PLUGIN_DIR . 'includes/class-lmpct-admin.php';
	LMPCT_Admin::init();
}

LMPCT_Attribution::init();
LMPCT_Frontend::init();
LMPCT_Forms::init();
LMPCT_Tools::init();

// Die Live-Debug-Leiste registriert sich erst, wenn ein Administrator im
// Frontend unterwegs ist – reguläre Besucher erzeugen keinerlei Overhead.
// Priorität 5: muss vor LMPCT_Frontend::prepare() (20) laufen, damit der
// CAPI-Request für echte Statuscodes blockierend gesendet wird.
add_action( 'wp', array( 'LMPCT_Debug', 'init' ), 5 );
