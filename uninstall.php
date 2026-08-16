<?php
/**
 * Räumt bei der Deinstallation alle Plugin-Daten auf.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lmpct_settings' );
delete_option( 'lmpct_events' );
delete_option( 'lmpct_events_enabled' );
