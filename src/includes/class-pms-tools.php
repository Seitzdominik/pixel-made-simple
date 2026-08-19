<?php
/**
 * Werkzeuge: Export und Import der kompletten Plugin-Konfiguration als JSON.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Tools {

	const CAPABILITY = 'manage_options';
	const FORMAT     = 'pms-config';

	public static function init() {
		add_action( 'admin_post_pms_export_settings', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_pms_import_settings', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Konfiguration als JSON-Download ausliefern.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixel-made-simple' ), '', array( 'response' => 403 ) );
		}

		// Defense-in-depth: die Free-Admin-UI zeigt für dieses Feature nur noch
		// einen Upgrade-Teaser statt des Export-Buttons (siehe
		// PMS_Admin::render_tools_tab()). Dieser Check fängt trotzdem jeden
		// direkten POST an diesen admin-post.php-Endpunkt ab, z. B. wenn jemand
		// die alte Formular-URL noch gespeichert hat.
		if ( ! PMS_Settings::is_pro() ) {
			wp_die( esc_html__( 'Exporting the configuration is a Pixel Made Simple Pro feature.', 'pixel-made-simple' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'pms_export_settings' );

		$export = array(
			'format'         => self::FORMAT,
			'version'        => PMS_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site'           => home_url( '/' ),
			'settings'       => PMS_Settings::get(),
			'events'         => array_values( PMS_Settings::get_events() ),
			'events_enabled' => PMS_Settings::events_enabled() ? 1 : 0,
		);

		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'The configuration could not be exported.', 'pixel-made-simple' ) );
		}

		$filename = 'pms-settings-export-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reiner JSON-Download, kein HTML-Kontext.
		exit;
	}

	/**
	 * Hochgeladene JSON-Konfiguration validieren und übernehmen.
	 *
	 * @return void
	 */
	public static function handle_import() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixel-made-simple' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'pms_import_settings' );

		if ( empty( $_FILES['pms_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pms_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Pfad wird nur an is_uploaded_file/file_get_contents übergeben.
			self::redirect( 'import_missing' );
		}

		$size = isset( $_FILES['pms_import_file']['size'] ) ? (int) $_FILES['pms_import_file']['size'] : 0;

		if ( $size <= 0 || $size > 512000 ) {
			self::redirect( 'import_invalid' );
		}

		$raw = file_get_contents( $_FILES['pms_import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Lokale Upload-Datei, kein Remote-Request.

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			self::redirect( 'import_invalid' );
		}

		$result = self::import_from_json( $raw );

		self::redirect( $result ? 'imported' : 'import_invalid' );
	}

	/**
	 * JSON-String validieren, sanitizen und speichern.
	 *
	 * Als eigene Methode ausgelagert, damit sie unabhängig von $_FILES
	 * testbar ist.
	 *
	 * @param string $raw JSON-Inhalt.
	 * @return bool True bei erfolgreichem Import.
	 */
	public static function import_from_json( $raw ) {
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || empty( $data['format'] ) || self::FORMAT !== $data['format'] ) {
			return false;
		}

		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return false;
		}

		// Einstellungen laufen durch dieselbe Sanitizing-Pipeline wie das Formular.
		update_option( PMS_Settings::OPTION_SETTINGS, PMS_Settings::sanitize_settings( $data['settings'] ) );

		if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$events = array();

			foreach ( $data['events'] as $event ) {
				$clean = PMS_Settings::sanitize_event( $event );
				if ( $clean ) {
					$events[ $clean['id'] ] = $clean;
				}
			}

			PMS_Settings::save_events( $events );
		}

		if ( isset( $data['events_enabled'] ) ) {
			update_option( PMS_Settings::OPTION_EVENTS_ENABLED, empty( $data['events_enabled'] ) ? 0 : 1, false );
		}

		return true;
	}

	/**
	 * Zurück zum Tools-Tab mit Meldung.
	 *
	 * @param string $message Meldungs-Schlüssel.
	 * @return void
	 */
	private static function redirect( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => PMS_Admin::PAGE_SLUG,
					'tab'           => 'tools',
					'pms_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
