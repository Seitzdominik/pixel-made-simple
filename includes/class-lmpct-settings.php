<?php
/**
 * Zentraler Zugriff auf Einstellungen und Events inkl. Sanitization.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Settings {

	const OPTION_SETTINGS       = 'lmpct_settings';
	const OPTION_EVENTS         = 'lmpct_events';
	const OPTION_EVENTS_ENABLED = 'lmpct_events_enabled';

	/**
	 * Von Meta unterstützte Standard-Events plus "CustomEvent".
	 *
	 * Bei "CustomEvent" wird der interne Event-Name via fbq('trackCustom', ...)
	 * bzw. als event_name an die CAPI gesendet.
	 *
	 * @return string[]
	 */
	public static function meta_event_types() {
		return array(
			'CustomEvent',
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		);
	}

	/**
	 * Offizielle TikTok-Web-Events plus "CustomEvent".
	 *
	 * @return string[]
	 */
	public static function tiktok_event_types() {
		return array(
			'CustomEvent',
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'ClickButton',
			'CompletePayment',
			'CompleteRegistration',
			'Contact',
			'Download',
			'InitiateCheckout',
			'PlaceAnOrder',
			'Search',
			'SubmitForm',
			'Subscribe',
			'ViewContent',
		);
	}

	/**
	 * Abwärtskompatibler Alias (v1.x).
	 *
	 * @return string[]
	 */
	public static function event_types() {
		return self::meta_event_types();
	}

	/**
	 * Einstellungen mit Defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = array(
			// Global. Consent-Erkennung ist bei Neuinstallation bewusst aktiv (DSGVO).
			'exclude_admins'        => 1,
			'consent_detection'     => 1,
			// Meta.
			'pixel_enabled'         => 0,
			'pixel_id'              => '',
			'capi_enabled'          => 0,
			'capi_token'            => '',
			'test_event_code'       => '',
			'test_code_created_at'  => 0,
			'hash_email'            => 0,
			// Google Ads.
			'google_enabled'      => 0,
			'google_tag_id'       => '',
			'google_consent_mode' => 1,
			// TikTok.
			'tiktok_enabled'      => 0,
			'tiktok_pixel_id'     => '',
			// Erweiterte Tracking-Features.
			'form_tracking'       => 1,
			'utm_passthrough'     => 1,
			'debug_bar'           => 1,
		);

		$settings = get_option( self::OPTION_SETTINGS, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Sanitize-Callback für die Settings API (Tab "Allgemein").
	 *
	 * @param mixed $input Rohdaten aus dem Formular.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		$test_code = preg_replace( '/[^A-Za-z0-9]+/', '', (string) ( $input['test_event_code'] ?? '' ) );

		// Test-Code-Zeitstempel: bei neuem/geändertem Code neu setzen, bei
		// unverändertem Code beibehalten (Basis für das 12h-Auto-Expiry).
		$old        = get_option( self::OPTION_SETTINGS, array() );
		$old_code   = is_array( $old ) ? (string) ( $old['test_event_code'] ?? '' ) : '';
		$old_ts     = is_array( $old ) ? (int) ( $old['test_code_created_at'] ?? 0 ) : 0;
		$created_at = 0;

		if ( '' !== $test_code ) {
			$created_at = ( $test_code === $old_code && $old_ts > 0 ) ? $old_ts : time();
		}

		return array(
			'exclude_admins'       => empty( $input['exclude_admins'] ) ? 0 : 1,
			'consent_detection'    => empty( $input['consent_detection'] ) ? 0 : 1,
			'pixel_enabled'        => empty( $input['pixel_enabled'] ) ? 0 : 1,
			'pixel_id'             => preg_replace( '/\D+/', '', (string) ( $input['pixel_id'] ?? '' ) ),
			'capi_enabled'         => empty( $input['capi_enabled'] ) ? 0 : 1,
			'capi_token'           => trim( sanitize_textarea_field( (string) ( $input['capi_token'] ?? '' ) ) ),
			'test_event_code'      => $test_code,
			'test_code_created_at' => $created_at,
			'hash_email'           => empty( $input['hash_email'] ) ? 0 : 1,
			'google_enabled'       => empty( $input['google_enabled'] ) ? 0 : 1,
			'google_tag_id'        => preg_replace( '/[^A-Za-z0-9\-]+/', '', (string) ( $input['google_tag_id'] ?? '' ) ),
			'google_consent_mode'  => empty( $input['google_consent_mode'] ) ? 0 : 1,
			'tiktok_enabled'       => empty( $input['tiktok_enabled'] ) ? 0 : 1,
			'tiktok_pixel_id'      => preg_replace( '/[^A-Za-z0-9]+/', '', (string) ( $input['tiktok_pixel_id'] ?? '' ) ),
			'form_tracking'        => empty( $input['form_tracking'] ) ? 0 : 1,
			'utm_passthrough'      => empty( $input['utm_passthrough'] ) ? 0 : 1,
			'debug_bar'            => empty( $input['debug_bar'] ) ? 0 : 1,
		);
	}

	/**
	 * Alle konfigurierten Events (id => Event-Array).
	 *
	 * @return array[]
	 */
	public static function get_events() {
		$events = get_option( self::OPTION_EVENTS, array() );

		if ( ! is_array( $events ) ) {
			return array();
		}

		$clean = array();
		foreach ( $events as $event ) {
			$event = self::sanitize_event( $event );
			if ( $event ) {
				$clean[ $event['id'] ] = $event;
			}
		}

		return $clean;
	}

	/**
	 * Events speichern.
	 *
	 * @param array $events Events, keyed by id.
	 * @return void
	 */
	public static function save_events( array $events ) {
		update_option( self::OPTION_EVENTS, array_values( $events ), false );
	}

	/**
	 * Sind benutzerdefinierte Events global aktiviert?
	 *
	 * @return bool
	 */
	public static function events_enabled() {
		return (bool) get_option( self::OPTION_EVENTS_ENABLED, 1 );
	}

	/**
	 * Einzelnes Event validieren und säubern.
	 *
	 * Events aus v1.x (ohne Plattform-Felder) gelten automatisch als Meta-Events.
	 *
	 * @param mixed $event Roh-Event.
	 * @return array|null Null, wenn das Event unbrauchbar ist.
	 */
	public static function sanitize_event( $event ) {
		if ( ! is_array( $event ) ) {
			return null;
		}

		$id          = sanitize_key( (string) ( $event['id'] ?? '' ) );
		$name        = sanitize_text_field( (string) ( $event['name'] ?? '' ) );
		$name        = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 50 ) : substr( $name, 0, 50 );
		$event_type  = (string) ( $event['event_type'] ?? '' );
		$match_type  = (string) ( $event['match_type'] ?? '' );
		$match_value = sanitize_text_field( (string) ( $event['match_value'] ?? '' ) );

		if ( '' === $id || '' === $name || '' === $match_value ) {
			return null;
		}

		if ( ! in_array( $event_type, self::meta_event_types(), true ) ) {
			return null;
		}

		if ( ! in_array( $match_type, array( 'exact', 'contains' ), true ) ) {
			$match_type = 'contains';
		}

		// Plattform-Zuweisung; fehlendes meta_enabled = Alt-Event aus v1.x.
		$meta_enabled   = array_key_exists( 'meta_enabled', $event ) ? ( empty( $event['meta_enabled'] ) ? 0 : 1 ) : 1;
		$google_enabled = empty( $event['google_enabled'] ) ? 0 : 1;
		$tiktok_enabled = empty( $event['tiktok_enabled'] ) ? 0 : 1;

		$google_label = preg_replace( '/[^A-Za-z0-9_\-]+/', '', (string) ( $event['google_label'] ?? '' ) );

		$tiktok_event = (string) ( $event['tiktok_event'] ?? '' );
		if ( '' === $tiktok_event ) {
			$tiktok_event = 'CompleteRegistration';
		}
		if ( ! in_array( $tiktok_event, self::tiktok_event_types(), true ) ) {
			return null;
		}

		// Mindestens eine Plattform; Google braucht zwingend ein Conversion Label.
		if ( ! $meta_enabled && ! $google_enabled && ! $tiktok_enabled ) {
			return null;
		}
		if ( $google_enabled && '' === $google_label ) {
			return null;
		}

		return array(
			'id'             => $id,
			'name'           => $name,
			'event_type'     => $event_type,
			'match_type'     => $match_type,
			'match_value'    => $match_value,
			'active'         => empty( $event['active'] ) ? 0 : 1,
			'meta_enabled'   => $meta_enabled,
			'google_enabled' => $google_enabled,
			'google_label'   => $google_label,
			'tiktok_enabled' => $tiktok_enabled,
			'tiktok_event'   => $tiktok_event,
		);
	}

	/**
	 * Der Meta-Event-Name, der tatsächlich gesendet wird.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function resolved_event_name( array $event ) {
		if ( 'CustomEvent' === $event['event_type'] ) {
			return $event['name'];
		}
		return $event['event_type'];
	}

	/**
	 * Der TikTok-Event-Name, der tatsächlich gesendet wird.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function resolved_tiktok_event_name( array $event ) {
		if ( 'CustomEvent' === ( $event['tiktok_event'] ?? '' ) ) {
			return $event['name'];
		}
		return (string) ( $event['tiktok_event'] ?? '' );
	}
}
