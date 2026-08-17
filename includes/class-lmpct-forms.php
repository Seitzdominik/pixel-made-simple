<?php
/**
 * Formular-Auto-Grabber: serverseitige Verarbeitung der Lead-Events.
 *
 * Das Frontend erkennt Formular-Absendungen, feuert das Browser-Event und
 * meldet Event-ID sowie Kontaktdaten hier an. Die Rohdaten werden ausschließlich
 * gehasht (SHA-256) an die Conversions API übergeben und niemals gespeichert.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Forms {

	const NONCE_ACTION = 'lmpct_form_lead';

	public static function init() {
		add_action( 'wp_ajax_lmpct_form_lead', array( __CLASS__, 'handle_lead' ) );
		add_action( 'wp_ajax_nopriv_lmpct_form_lead', array( __CLASS__, 'handle_lead' ) );
	}

	/**
	 * Ist das automatische Formular-Tracking aktiv?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = LMPCT_Settings::get();
		return ! empty( $settings['form_tracking'] );
	}

	/**
	 * AJAX: Lead-Event verarbeiten und serverseitig an die CAPI senden.
	 *
	 * @return void
	 */
	public static function handle_lead() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'reason' => 'disabled' ), 200 );
		}

		$settings = LMPCT_Settings::get();

		if ( ! empty( $settings['exclude_admins'] ) && current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'reason' => 'admin_excluded' ), 200 );
		}

		/** Dokumentiert in class-lmpct-frontend.php */
		if ( ! apply_filters( 'lmpct_allow_tracking', true ) ) {
			wp_send_json_error( array( 'reason' => 'tracking_disabled' ), 200 );
		}

		if ( ! LMPCT_Consent::has_marketing_consent() ) {
			wp_send_json_error( array( 'reason' => 'no_consent' ), 200 );
		}

		if ( empty( $settings['capi_enabled'] ) || empty( $settings['pixel_id'] ) || empty( $settings['capi_token'] ) ) {
			wp_send_json_error( array( 'reason' => 'capi_inactive' ), 200 );
		}

		$event_id = sanitize_text_field( wp_unslash( $_POST['event_id'] ?? '' ) );
		$event_id = preg_replace( '/[^A-Za-z0-9\-]/', '', $event_id );

		if ( '' === $event_id ) {
			wp_send_json_error( array( 'reason' => 'missing_event_id' ), 400 );
		}

		$event_name = sanitize_text_field( wp_unslash( $_POST['event_name'] ?? 'Lead' ) );
		$event_name = preg_replace( '/[^A-Za-z0-9_ \-]/', '', $event_name );
		if ( '' === $event_name ) {
			$event_name = 'Lead';
		}

		// Kontaktdaten ausschließlich gehasht weiterreichen.
		$user_data = array();

		$email_hash = LMPCT_CAPI::hash_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( '' !== $email_hash ) {
			$user_data['em'] = array( $email_hash );
		}

		$phone_hash = LMPCT_CAPI::hash_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( '' !== $phone_hash ) {
			$user_data['ph'] = array( $phone_hash );
		}

		$event = array(
			'id'           => 'form-lead',
			'name'         => $event_name,
			'event_type'   => 'Lead',
			'event_id'     => $event_id,
			'meta_enabled' => 1,
		);

		$status = LMPCT_CAPI::send_events(
			array( $event ),
			$settings,
			self::source_url(),
			$user_data
		);

		wp_send_json_success(
			array(
				'event'      => $event_name,
				'event_id'   => $event_id,
				'status'     => $status['status'],
				'code'       => $status['code'],
				'message'    => $status['message'],
				'match_keys' => $status['match_keys'],
			)
		);
	}

	/**
	 * event_source_url für das Lead-Event ermitteln (nur eigene Domain).
	 *
	 * @return string
	 */
	private static function source_url() {
		$url = esc_url_raw( wp_unslash( $_POST['source_url'] ?? '' ) );

		if ( '' === $url ) {
			$url = (string) wp_get_referer();
		}

		if ( '' === $url ) {
			return home_url( '/' );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $host && $home && strtolower( $host ) !== strtolower( $home ) ) {
			return home_url( '/' );
		}

		return $url;
	}
}
