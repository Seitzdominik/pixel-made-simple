<?php
/**
 * Räumt bei der Deinstallation alle Plugin-Daten auf.
 *
 * Diese Datei wird unverändert in BEIDE Pakete (Free und Pro) kopiert und
 * läuft deshalb potenziell zweimal auf derselben Site. Free und Pro teilen
 * sich denselben Options-Key ("pms_settings" etc.), damit Einstellungen beim
 * Wechsel von Free zu Pro erhalten bleiben – wird aber gerade die Free-
 * Version gelöscht, während Pro noch installiert ist (oder umgekehrt), darf
 * dieser Lauf die gemeinsamen Daten NICHT wegräumen, sonst verliert die
 * verbleibende Version ihre Konfiguration.
 *
 * WordPress definiert WP_UNINSTALL_PLUGIN als den Basename des Plugins, das
 * gerade deinstalliert wird – daran lässt sich das jeweils ANDERE Plugin
 * bestimmen und prüfen, ob es noch auf der Platte liegt.
 *
 * @package Pixel_Made_Simple
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$pms_known_plugins = array(
	'pixel-made-simple/pixel-made-simple.php',
	'pixel-made-simple-pro/pixel-made-simple-pro.php',
);

$pms_sibling_plugins = array_diff( $pms_known_plugins, array( WP_UNINSTALL_PLUGIN ) );

$pms_sibling_still_present = false;
foreach ( $pms_sibling_plugins as $pms_sibling_plugin ) {
	if ( file_exists( WP_PLUGIN_DIR . '/' . $pms_sibling_plugin ) ) {
		$pms_sibling_still_present = true;
		break;
	}
}

if ( ! $pms_sibling_still_present ) {
	delete_option( 'pms_settings' );
	delete_option( 'pms_events' );
	delete_option( 'pms_events_enabled' );
	delete_option( 'pms_log_db_version' );

	wp_clear_scheduled_hook( 'pms_cleanup_event_log_cron' );

	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pms_event_log' );
}
