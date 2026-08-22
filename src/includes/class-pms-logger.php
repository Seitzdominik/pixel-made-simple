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
	 * Migrations-Version des Logs. Bei einer künftigen Spaltenänderung ODER
	 * einer anderen einmalig nachzuziehenden Änderung hier hochzählen --
	 * maybe_upgrade_table() erkennt den Unterschied und ruft dbDelta() sowie
	 * die übrigen Migrationsschritte automatisch erneut auf (deckt den
	 * häufigen Fall ab, dass ein Update ohne erneute Aktivierung eingespielt
	 * wird, register_activation_hook also gar nicht feuert).
	 *
	 * 1.1.1 (v0.7.0): keine Schemaänderung, sondern eine reine
	 * Autoload-Migration -- siehe ensure_autoloaded_options(). Der
	 * Versionssprung ist nötig, damit Bestandsinstallationen den
	 * Migrationszweig genau einmal durchlaufen.
	 */
	const DB_VERSION        = '1.1.1';
	const DB_VERSION_OPTION = 'pms_log_db_version';

	const CRON_HOOK = 'pms_cleanup_event_log_cron';

	/**
	 * Ziel-Plattform einer Log-Zeile (Spalte "platform", seit v0.6.11).
	 *
	 * Zweite, von "source" UNABHÄNGIGE Achse: "platform" beantwortet WOHIN das
	 * Event ging (Meta/Google Ads/TikTok/GA4), "source" WIE es dorthin kam
	 * (browser/capi/both). Bis v0.6.10 gab es nur die zweite Achse, weil
	 * ausschließlich Meta protokolliert wurde -- Zeilen aus dieser Zeit
	 * bekommen über den Spalten-Default korrekt 'meta'.
	 */
	const PLATFORM_META   = 'meta';
	const PLATFORM_GOOGLE = 'google';
	const PLATFORM_TIKTOK = 'tiktok';
	const PLATFORM_GA4    = 'ga4';

	/**
	 * Anzeigenamen der Plattformen (Event-Log-Tabelle + Filter).
	 *
	 * @return array<string,string>
	 */
	public static function platform_labels() {
		return array(
			self::PLATFORM_META   => __( 'Meta', 'pixel-made-simple' ),
			self::PLATFORM_GOOGLE => __( 'Google Ads', 'pixel-made-simple' ),
			self::PLATFORM_TIKTOK => __( 'TikTok', 'pixel-made-simple' ),
			self::PLATFORM_GA4    => __( 'GA4', 'pixel-made-simple' ),
		);
	}

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
		self::schedule_cron();
		self::ensure_autoloaded_options();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Läuft bei jedem Request früh (plugins_loaded). Legt die Tabelle nur an,
	 * wenn die gespeicherte Version von DB_VERSION abweicht (frische
	 * Installation oder Update ohne Deaktivieren/Aktivieren) -- kein
	 * dbDelta()-Aufruf bei jedem normalen Seitenaufruf.
	 *
	 * Die Versions-Option selbst wird seit v0.7.0 AUTOGELADEN (dritter
	 * Parameter true, vorher false): Dieser Vergleich läuft bei jedem einzelnen
	 * Request -- als nicht autogeladene Option kostete er auf Sites ohne
	 * persistenten Object Cache jedes Mal eine eigene SELECT-Abfrage auf
	 * wp_options, obwohl der Wert ein paar Bytes groß ist und sich praktisch
	 * nie ändert. Autogeladen ist er Teil der einen alloptions-Abfrage, die
	 * WordPress ohnehin stellt.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
			self::schedule_cron();
			self::ensure_autoloaded_options();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * Einmalige Autoload-Migration (v0.7.0) für die drei kleinen Optionen,
	 * die auf jedem Frontend-Seitenaufruf gelesen werden: pms_events und
	 * pms_events_enabled wurden bis v0.6.12 mit autoload=false gespeichert
	 * (PMS_Frontend::prepare() liest beide bei jedem getrackten Seitenaufruf
	 * -- zwei zusätzliche SELECTs pro Seite ohne Object Cache), und eine noch
	 * nie gespeicherte Option löst ebenfalls bei jedem Request eine Abfrage
	 * aus, weil der "notoptions"-Cache von WordPress nicht persistent ist.
	 *
	 * Deshalb: fehlende Optionen mit ihrem Default anlegen (add_option() ist
	 * ein No-Op, wenn die Option schon existiert) und bestehende per
	 * wp_set_option_autoload() (WordPress 6.4+) auf autoload umstellen. Auf
	 * älteren Cores flippt das Flag spätestens beim nächsten Speichern, weil
	 * PMS_Settings::save_events()/PMS_Admin::handle_toggle_all_events() seit
	 * v0.7.0 mit autoload=true schreiben.
	 *
	 * Bewusst hier statt in PMS_Settings: maybe_upgrade_table() ist der
	 * einzige versionierte Migrationspfad des Plugins -- ein zweiter
	 * Versionszähler nur für Optionen wäre unnötige Doppelung.
	 *
	 * @return void
	 */
	private static function ensure_autoloaded_options() {
		add_option( PMS_Settings::OPTION_EVENTS, array(), '', true );
		add_option( PMS_Settings::OPTION_EVENTS_ENABLED, 1, '', true );

		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( PMS_Settings::OPTION_EVENTS, true );
			wp_set_option_autoload( PMS_Settings::OPTION_EVENTS_ENABLED, true );
			wp_set_option_autoload( PMS_Settings::OPTION_SETTINGS, true );
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
			platform VARCHAR(20) NOT NULL DEFAULT 'meta',
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
	 * @param string   $error_message  Fehlertext der Plattform-API, falls vorhanden.
	 * @param string   $platform       Ziel-Plattform (PLATFORM_*), Default 'meta'
	 *                                 -- siehe Konstanten oben. Der Default hält
	 *                                 alle Aufrufer aus der Zeit vor v0.6.11
	 *                                 unverändert gültig.
	 * @return void
	 */
	public static function record( $event_name, $event_id, $source, $http_status, array $user_data_keys = array(), $error_message = '', $platform = self::PLATFORM_META ) {
		global $wpdb;

		$error_message = wp_strip_all_tags( (string) $error_message );

		$platform = (string) $platform;
		if ( ! isset( self::platform_labels()[ $platform ] ) ) {
			$platform = self::PLATFORM_META;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Eigene Tabelle.
			self::table_name(),
			array(
				'created_at'     => current_time( 'mysql', true ), // GMT -- konsistente Basis für die Retention-Berechnung.
				'event_name'     => substr( (string) $event_name, 0, 64 ),
				'event_id'       => substr( (string) $event_id, 0, 128 ),
				'source'         => substr( (string) $source, 0, 20 ),
				'platform'       => $platform,
				'http_status'    => (int) $http_status,
				'user_data_keys' => substr( implode( ', ', array_map( 'sanitize_key', $user_data_keys ) ), 0, 255 ),
				'error_message'  => '' !== $error_message ? substr( $error_message, 0, 1000 ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Gilt eine Log-Zeile als Fehler?
	 *
	 * Einzige Quelle der Wahrheit für beide Stellen, die das beantworten
	 * müssen: den Filter "Nur Fehler" in get_entries() und das rote Badge in
	 * PMS_Admin_Event_Log::render_status_badge(). Bis v0.6.9 hatten beide
	 * ihre eigene, leicht unterschiedliche Bedingung -- ein 4xx/5xx ohne
	 * Fehlertext (möglich, wenn Meta einen Statuscode ohne verwertbaren
	 * error.message liefert) färbte das Badge nicht rot und tauchte auch im
	 * Filter nicht auf.
	 *
	 * http_status=0 OHNE error_message ist ausdrücklich KEIN Fehler, sondern
	 * der Fire-and-Forget-Normalfall (siehe PMS_CAPI::log()).
	 *
	 * @param array $row Zeile aus get_entries().
	 * @return bool
	 */
	public static function is_error_row( array $row ) {
		if ( '' !== (string) ( $row['error_message'] ?? '' ) ) {
			return true;
		}

		return (int) ( $row['http_status'] ?? 0 ) >= 400;
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
	 *     @type string $status     '' (alle) oder 'error' (nur Fehlerzeilen, siehe is_error_row()).
	 *     @type string $event_name '' (alle) oder exakter Event-Name.
	 *     @type string $platform   '' (alle) oder eine PLATFORM_*-Konstante.
	 *     @type int    $limit      Max. Anzahl Ergebnisse (Default DISPLAY_LIMIT).
	 * }
	 * @return array[] Assoziative Zeilen-Arrays.
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY created_at DESC LIMIT ' . self::MAX_FETCH, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Eigene Tabelle, keine Nutzereingabe (Name aus $wpdb->prefix, LIMIT ist eine Klassenkonstante).

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_filter   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$event_filter    = isset( $args['event_name'] ) ? (string) $args['event_name'] : '';
		$platform_filter = isset( $args['platform'] ) ? (string) $args['platform'] : '';

		if ( '' !== $status_filter || '' !== $event_filter || '' !== $platform_filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $status_filter, $event_filter, $platform_filter ) {
						if ( '' !== $event_filter && $row['event_name'] !== $event_filter ) {
							return false;
						}
						// Zeilen aus der Zeit vor der platform-Spalte zählen als
						// Meta (damals wurde nur Meta protokolliert).
						if ( '' !== $platform_filter && ( $row['platform'] ?? self::PLATFORM_META ) !== $platform_filter ) {
							return false;
						}
						if ( 'error' === $status_filter && ! self::is_error_row( $row ) ) {
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
	 * Abgelaufene Einträge löschen (Cron-Callback).
	 *
	 * Seit v0.7.0 ein einziges, vorbereitetes DELETE über den created_at-Index
	 * statt "alle Zeilen holen und einzeln löschen": Bei 30 Tagen Retention in
	 * einem Shop mit ViewContent-Tracking sind das schnell zehntausende Zeilen
	 * -- die alte Variante las sie komplett in PHP ein und schickte pro Zeile
	 * ein eigenes DELETE. Der Stichtag wird in PHP berechnet (GMT, dieselbe
	 * Basis wie created_at in record()) und als %s-Platzhalter übergeben; der
	 * Tabellenname enthält keine Nutzereingabe (nur $wpdb->prefix), deshalb
	 * ist ausschließlich der Wert zu preparen.
	 *
	 * @return void
	 */
	public static function cleanup_old_entries() {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::retention_days() . ' days', time() ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle, Name aus $wpdb->prefix.
	}

	/**
	 * Kompletten Log leeren ("Log leeren"-Button im Admin).
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Eigene Tabelle, Name aus $wpdb->prefix.
	}
}
