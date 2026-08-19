<?php
/**
 * Event Log: persistiert Browser-/CAPI-Events in einer eigenen Tabelle, damit
 * sie im Admin-Tab "Event Log" nachvollzogen werden können (nicht zu
 * verwechseln mit PMS_CAPI::$log, dem rein request-lokalen In-Memory-Log für
 * die Live-Debug-Leiste).
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Logger {

	/**
	 * Schema-Version der Tabelle. Bei einer künftigen Spaltenänderung hier
	 * hochzählen -- maybe_upgrade_table() erkennt den Unterschied und ruft
	 * dbDelta() automatisch erneut auf (deckt den häufigen Fall ab, dass ein
	 * Update ohne erneute Aktivierung eingespielt wird, register_activation_hook
	 * also gar nicht feuert).
	 */
	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'pms_log_db_version';

	const CRON_HOOK = 'pms_cleanup_event_log_cron';

	/**
	 * Obergrenzen für die Admin-Tabelle: MAX_FETCH wird unblockiert per SQL
	 * geholt (kein WHERE, kein dynamisches LIMIT -- siehe get_entries()-Doku,
	 * warum bewusst kein $wpdb->prepare() nötig ist), DISPLAY_LIMIT ist die
	 * Anzahl Zeilen, die die Tabelle nach Filterung tatsächlich anzeigt.
	 */
	const MAX_FETCH     = 500;
	const DISPLAY_LIMIT = 100;

	/**
	 * Tabellenname inkl. Präfix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pms_event_log';
	}

	/**
	 * Cron-Hook registrieren + Tabellen-Version bei Bedarf nachziehen (deckt
	 * Updates ohne erneute Aktivierung ab). Von beiden Bootstrap-Dateien
	 * unconditional aufgerufen (Logging passiert auch im Frontend, nicht nur
	 * im Admin).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_old_entries' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_table' ) );
	}

	/**
	 * Bei Plugin-Aktivierung (Free UND Pro, siehe register_activation_hook in
	 * beiden Hauptdateien): Tabelle sofort anlegen und Cron einplanen, statt
	 * auf den nächsten plugins_loaded-Request zu warten.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::schedule_cron();
	}

	/**
	 * Läuft bei jedem Request früh (plugins_loaded). Legt die Tabelle nur an,
	 * wenn die gespeicherte Version von DB_VERSION abweicht (frische
	 * Installation oder Update ohne Deaktivieren/Aktivieren) -- kein
	 * dbDelta()-Aufruf bei jedem normalen Seitenaufruf.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
			self::schedule_cron();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Tabelle per dbDelta anlegen/aktualisieren (idempotent, sicher mehrfach
	 * aufzurufen).
	 *
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta ist strikt bei der SQL-Formatierung (u. a. zwei Leerzeichen
		// vor "PRIMARY KEY", ein Feld pro Zeile) -- Abweichungen führen dazu,
		// dass Änderungen beim nächsten Aufruf still ignoriert werden.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			event_name VARCHAR(64) NOT NULL DEFAULT '',
			event_id VARCHAR(128) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT '',
			http_status SMALLINT NOT NULL DEFAULT 0,
			user_data_keys VARCHAR(255) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event_id (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Cron einplanen, falls noch nicht geschehen. Wird NICHT bei Deaktivierung
	 * wieder entfernt (siehe uninstall.php für den eigentlichen Cleanup) --
	 * würde Free/Pro deaktivieren einander sonst über register_activation_hook
	 * gegenseitig die Ereignisplanung des jeweils anderen zerschießen (Pro
	 * aktivieren deaktiviert Free automatisch, was Frees Deactivation-Hook
	 * auslöst; ein dort unbedingtes wp_clear_scheduled_hook() liefe der
	 * Cron-Planung aus Pros gerade laufendem Activate-Hook in die Quere,
	 * je nach Ausführungsreihenfolge). Ein weiterhin eingeplanter, aber
	 * ungenutzter Cron-Hook ohne registrierten Listener ist harmlos.
	 *
	 * @return void
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Aufbewahrungsdauer in Tagen. Free: fest 3 Tage. Pro: konfigurierbar
	 * (3/7/14/30), fällt auf den Default zurück, wenn ein gespeicherter Wert
	 * (z. B. nach einem Downgrade + erneutem Upgrade) nicht in der Whitelist
	 * steht.
	 *
	 * @return int
	 */
	public static function retention_days() {
		if ( ! PMS_Settings::is_pro() ) {
			return PMS_Settings::FREE_LOG_RETENTION_DAYS;
		}

		$settings = PMS_Settings::get();
		$days     = (int) ( $settings['log_retention_days'] ?? PMS_Settings::DEFAULT_LOG_RETENTION_DAYS );

		return in_array( $days, PMS_Settings::ALLOWED_LOG_RETENTION_DAYS, true ) ? $days : PMS_Settings::DEFAULT_LOG_RETENTION_DAYS;
	}

	/**
	 * Ein Event protokollieren.
	 *
	 * Speichert bewusst NUR die Namen der übergebenen user_data-Schlüssel
	 * (z. B. "em, fbc"), niemals die Werte selbst -- die Tabelle darf keine
	 * personenbezogenen Daten oder Hashes enthalten.
	 *
	 * @param string   $event_name    Event-Name (z. B. "Lead", "Purchase").
	 * @param string   $event_id      Event-ID (Dedup-Abgleich mit dem Browser-Pixel).
	 * @param string   $source        'browser' | 'capi' | 'both'.
	 * @param int      $http_status   HTTP-Status der CAPI-Antwort, 0 wenn keiner
	 *                                bekannt ist (reines Browser-Event ODER
	 *                                Fire-and-Forget-Versand ohne Rückmeldung --
	 *                                siehe class-pms-capi.php für die genaue
	 *                                Unterscheidung anhand von error_message).
	 * @param string[] $user_data_keys Namen der übergebenen user_data-Felder.
	 * @param string   $error_message  Fehlertext der Meta-API, falls vorhanden.
	 * @return void
	 */
	public static function record( $event_name, $event_id, $source, $http_status, array $user_data_keys = array(), $error_message = '' ) {
		global $wpdb;

		$error_message = wp_strip_all_tags( (string) $error_message );

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'     => current_time( 'mysql', true ), // GMT -- konsistente Basis für die Retention-Berechnung.
				'event_name'     => substr( (string) $event_name, 0, 64 ),
				'event_id'       => substr( (string) $event_id, 0, 128 ),
				'source'         => substr( (string) $source, 0, 20 ),
				'http_status'    => (int) $http_status,
				'user_data_keys' => substr( implode( ', ', array_map( 'sanitize_key', $user_data_keys ) ), 0, 255 ),
				'error_message'  => '' !== $error_message ? substr( $error_message, 0, 1000 ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Protokollierte Events lesen (neueste zuerst).
	 *
	 * Holt bewusst eine feste, kleine Obergrenze (MAX_FETCH) OHNE dynamisches
	 * WHERE/LIMIT aus der Datenbank und filtert/kürzt anschließend in PHP --
	 * die Retention hält die Tabelle ohnehin klein (max. 30 Tage), das macht
	 * einen echten Query-Builder mit $wpdb->prepare() für Status-/Event-Filter
	 * unnötig.
	 *
	 * @param array $args {
	 *     @type string $status     '' (alle) oder 'error' (nur Einträge mit error_message).
	 *     @type string $event_name '' (alle) oder exakter Event-Name.
	 *     @type int    $limit      Max. Anzahl Ergebnisse (Default DISPLAY_LIMIT).
	 * }
	 * @return array[] Assoziative Zeilen-Arrays.
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY created_at DESC LIMIT ' . self::MAX_FETCH, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_filter = isset( $args['status'] ) ? (string) $args['status'] : '';
		$event_filter  = isset( $args['event_name'] ) ? (string) $args['event_name'] : '';

		if ( '' !== $status_filter || '' !== $event_filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $status_filter, $event_filter ) {
						if ( '' !== $event_filter && $row['event_name'] !== $event_filter ) {
							return false;
						}
						if ( 'error' === $status_filter && '' === (string) ( $row['error_message'] ?? '' ) ) {
							return false;
						}
						return true;
					}
				)
			);
		}

		$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : self::DISPLAY_LIMIT;

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Eindeutige Event-Namen im aktuell gespeicherten Log (für den
	 * Event-Namen-Filter der Pro-Version).
	 *
	 * @return string[]
	 */
	public static function get_distinct_event_names() {
		$names = array();
		foreach ( self::get_entries( array( 'limit' => self::MAX_FETCH ) ) as $row ) {
			$name = (string) ( $row['event_name'] ?? '' );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * Abgelaufene Einträge löschen (Cron-Callback). Holt id+created_at aller
	 * Zeilen ohne WHERE (siehe get_entries()-Doku) und löscht die
	 * abgelaufenen einzeln über die strukturierte delete()-Methode -- bei der
	 * durch die Retention klein gehaltenen Tabellengröße unproblematisch und
	 * ohne eigenes SQL-WHERE einfacher korrekt zu halten.
	 *
	 * @return void
	 */
	public static function cleanup_old_entries() {
		global $wpdb;

		$table     = self::table_name();
		$cutoff_ts = strtotime( '-' . self::retention_days() . ' days', current_time( 'timestamp', true ) );
		$rows      = $wpdb->get_results( 'SELECT id, created_at FROM ' . $table, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( strtotime( $row['created_at'] . ' UTC' ) < $cutoff_ts ) {
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			}
		}
	}

	/**
	 * Kompletten Log leeren ("Log leeren"-Button im Admin).
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}
}
