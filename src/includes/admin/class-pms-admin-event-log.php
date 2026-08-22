<?php
/**
 * Admin-Tab "Event Log": zeigt die von PMS_Logger protokollierten Browser-/
 * CAPI-Events und bietet "Log leeren" sowie (Pro) Status-/Event-Filter und
 * eine wählbare Aufbewahrungsdauer.
 *
 * Erste Datei unter includes/admin/ -- ein neuer Ordner speziell für
 * Admin-Tabs, die (anders als die bisherigen General/Events/Advanced/Tools-
 * Tabs in class-pms-admin.php) in eine eigene Datei ausgelagert sind. Bindet
 * sich über PMS_Admin::render_page()s Tab-Dispatch ein (siehe dort), nicht
 * über einen eigenen admin_menu-Eintrag, und nutzt bewusst PMS_Admin::tip()/
 * ::upgrade_url()/::render_pro_teaser_box() weiter (deshalb dort public statt
 * private), statt UI-Bausteine zu duplizieren.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Admin_Event_Log {

	const CAPABILITY = 'manage_options';

	/**
	 * Eigene Hooks registrieren (getrennt von PMS_Admin::init(), damit dieser
	 * Tab so unabhängig wie möglich vom Rest der Admin-Klasse bleibt).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_pms_clear_event_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	/**
	 * Tab-Inhalt rendern.
	 *
	 * @return void
	 */
	public static function render_tab() {
		$is_pro = PMS_Settings::is_pro();

		$status_filter   = '';
		$event_filter    = '';
		$platform_filter = '';

		if ( $is_pro ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur lesende Filter-Auswahl, keine Zustandsänderung.
			$status_filter = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( $_GET['log_status'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$event_filter = isset( $_GET['log_event'] ) ? sanitize_text_field( wp_unslash( $_GET['log_event'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$platform_filter = isset( $_GET['log_platform'] ) ? sanitize_key( wp_unslash( $_GET['log_platform'] ) ) : '';
			if ( '' !== $platform_filter && ! isset( PMS_Logger::platform_labels()[ $platform_filter ] ) ) {
				$platform_filter = '';
			}
		}
		// In der Free-Version werden $_GET-Filterparameter absichtlich ignoriert,
		// selbst wenn jemand die URL direkt manipuliert -- die Filter-Controls
		// sind serverseitig genauso gesperrt wie in der UI (Defense-in-Depth,
		// dasselbe Prinzip wie bei PMS_Tools::handle_export()).

		$entries = PMS_Logger::get_entries(
			array(
				'status'     => $status_filter,
				'event_name' => $event_filter,
				'platform'   => $platform_filter,
			)
		);

		$admin_post = admin_url( 'admin-post.php' );
		?>
		<h2 class="pms-section-title"><?php esc_html_e( 'Event Log', 'pixel-made-simple' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Shows recent browser and Conversions API events so you can verify your tracking is working, without leaving WordPress.', 'pixel-made-simple' ); ?></p>

		<div class="pms-log-toolbar">
			<?php self::render_filters( $is_pro, $status_filter, $event_filter, $platform_filter ); ?>
			<?php self::render_retention_control( $is_pro ); ?>

			<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="pms-inline-form">
				<input type="hidden" name="action" value="pms_clear_event_log" />
				<?php wp_nonce_field( 'pms_clear_event_log' ); ?>
				<button type="submit" class="button pms-delete-button"
					data-pms-confirm="<?php esc_attr_e( 'Really clear the entire event log? This cannot be undone.', 'pixel-made-simple' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<?php esc_html_e( 'Clear log', 'pixel-made-simple' ); ?>
				</button>
			</form>
		</div>

		<table class="widefat striped pms-events-table pms-log-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event ID', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Match Keys', 'pixel-made-simple' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr>
						<td colspan="6"><?php esc_html_e( 'No events logged yet.', 'pixel-made-simple' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $entries as $entry ) : ?>
						<?php self::render_row( $entry ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of days */
				esc_html__( 'Entries are automatically deleted after %d days.', 'pixel-made-simple' ),
				(int) PMS_Logger::retention_days()
			);
			?>
		</p>
		<?php
	}

	/**
	 * Status-/Event-Filter (GET-Formular). In Free gerendert, aber deaktiviert
	 * (nicht ausgeblendet) mit Upgrade-Tooltip.
	 *
	 * @param bool   $is_pro
	 * @param string $status_filter
	 * @param string $event_filter
	 * @param string $platform_filter
	 * @return void
	 */
	private static function render_filters( $is_pro, $status_filter, $event_filter, $platform_filter = '' ) {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pms-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( PMS_Admin::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />

			<label for="pms-log-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'pixel-made-simple' ); ?></label>
			<select id="pms-log-status" name="log_status" <?php disabled( ! $is_pro ); ?>>
				<option value="" <?php selected( $status_filter, '' ); ?>><?php esc_html_e( 'All statuses', 'pixel-made-simple' ); ?></option>
				<option value="error" <?php selected( $status_filter, 'error' ); ?>><?php esc_html_e( 'Errors only', 'pixel-made-simple' ); ?></option>
			</select>

			<label for="pms-log-event" class="screen-reader-text"><?php esc_html_e( 'Filter by event name', 'pixel-made-simple' ); ?></label>
			<select id="pms-log-event" name="log_event" <?php disabled( ! $is_pro ); ?>>
				<option value=""><?php esc_html_e( 'All events', 'pixel-made-simple' ); ?></option>
				<?php if ( $is_pro ) : ?>
					<?php foreach ( PMS_Logger::get_distinct_event_names() as $name ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $event_filter, $name ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>

			<label for="pms-log-platform" class="screen-reader-text"><?php esc_html_e( 'Filter by platform', 'pixel-made-simple' ); ?></label>
			<select id="pms-log-platform" name="log_platform" <?php disabled( ! $is_pro ); ?>>
				<option value="" <?php selected( $platform_filter, '' ); ?>><?php esc_html_e( 'All platforms', 'pixel-made-simple' ); ?></option>
				<?php foreach ( PMS_Logger::platform_labels() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $platform_filter, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php if ( $is_pro ) : ?>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'pixel-made-simple' ); ?></button>
			<?php else : ?>
				<?php PMS_Admin::tip( __( 'Filtering the event log is a Pro feature.', 'pixel-made-simple' ) ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Aufbewahrungsdauer-Auswahl. In Pro ein per AJAX autosavendes Dropdown
	 * (data-pms-autosave, siehe PMS_Admin::handle_toggle_autosave()), in Free
	 * fest auf "3 Tage" mit Upgrade-Tooltip. Bewusst AUSSERHALB des
	 * Filter-<form> oben, weil es rein per JS speichert, nicht Teil der
	 * GET-Filterung ist.
	 *
	 * @param bool $is_pro
	 * @return void
	 */
	private static function render_retention_control( $is_pro ) {
		?>
		<span class="pms-log-retention">
			<label for="pms-log-retention" class="screen-reader-text"><?php esc_html_e( 'Retention period', 'pixel-made-simple' ); ?></label>
			<?php if ( $is_pro ) : ?>
				<select id="pms-log-retention" data-pms-autosave="log_retention_days">
					<?php foreach ( PMS_Settings::ALLOWED_LOG_RETENTION_DAYS as $days ) : ?>
						<option value="<?php echo esc_attr( $days ); ?>" <?php selected( PMS_Logger::retention_days(), $days ); ?>>
							<?php echo esc_html( sprintf( __( '%d days', 'pixel-made-simple' ), $days ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<select id="pms-log-retention" disabled>
					<option><?php esc_html_e( '3 days (free limit)', 'pixel-made-simple' ); ?></option>
				</select>
				<?php PMS_Admin::tip( __( 'Choosing a longer retention period is a Pro feature.', 'pixel-made-simple' ) ); ?>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Eine Log-Zeile rendern.
	 *
	 * @param array $entry Zeile aus PMS_Logger::get_entries().
	 * @return void
	 */
	private static function render_row( array $entry ) {
		$local_time = get_date_from_gmt( (string) $entry['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$keys       = array_filter( array_map( 'trim', explode( ',', (string) $entry['user_data_keys'] ) ) );
		?>
		<tr>
			<td><?php echo esc_html( $local_time ); ?></td>
			<td>
				<strong><?php echo esc_html( (string) $entry['event_name'] ); ?></strong>
				<?php self::render_platform_badge( $entry ); ?>
			</td>
			<td><code><?php echo esc_html( (string) $entry['event_id'] ); ?></code></td>
			<td><?php echo esc_html( self::source_label( (string) $entry['source'] ) ); ?></td>
			<td><?php self::render_status_badge( $entry ); ?></td>
			<td>
				<?php foreach ( $keys as $key ) : ?>
					<span class="pms-badge"><?php echo esc_html( $key ); ?></span>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Plattform-Badge neben dem Event-Namen (seit v0.6.11).
	 *
	 * Bewusst als Badge in der Event-Spalte statt als siebte Tabellenspalte:
	 * die Tabelle ist bei 960px bereits gut gefüllt, und die
	 * .pms-badge-meta/-google/-tiktok-Stile existieren aus der Events-Tabelle
	 * ohnehin schon. Zeilen aus der Zeit vor der platform-Spalte tragen 'meta'
	 * (damals wurde nur Meta protokolliert).
	 *
	 * @param array $entry
	 * @return void
	 */
	private static function render_platform_badge( array $entry ) {
		$platform = (string) ( $entry['platform'] ?? PMS_Logger::PLATFORM_META );
		$labels   = PMS_Logger::platform_labels();

		if ( ! isset( $labels[ $platform ] ) ) {
			$platform = PMS_Logger::PLATFORM_META;
		}

		// GA4 teilt sich die Google-Optik (dieselbe Plattformfamilie), hat aber
		// eine eigene Beschriftung.
		$css = PMS_Logger::PLATFORM_GA4 === $platform ? PMS_Logger::PLATFORM_GOOGLE : $platform;

		printf(
			'<span class="pms-badge pms-badge-%1$s">%2$s</span>',
			esc_attr( $css ),
			esc_html( $labels[ $platform ] )
		);
	}

	/**
	 * Anzeigename für die "source"-Spalte.
	 *
	 * @param string $source 'browser' | 'capi' | 'both'.
	 * @return string
	 */
	private static function source_label( $source ) {
		$labels = array(
			'browser' => __( 'Browser', 'pixel-made-simple' ),
			'capi'    => __( 'CAPI', 'pixel-made-simple' ),
			'both'    => __( 'Browser + CAPI', 'pixel-made-simple' ),
		);

		return $labels[ $source ] ?? $source;
	}

	/**
	 * Status-Badge, seit v0.6.10 mit vereinheitlichter Farbgebung:
	 *
	 * - GRÜN für jeden Erfolgsfall -- sowohl ein bestätigtes 2xx ("200 OK")
	 *   als auch der Fire-and-Forget-Normalfall http_status=0 ohne
	 *   error_message ("Gesendet"). Letzterer bekam bis v0.6.9 ein neutrales
	 *   graues Badge, was in der Liste wie ein Zwischenzustand aussah, obwohl
	 *   das Event tatsächlich rausging (siehe PMS_CAPI::log()-Doku: der
	 *   Standard-Sendemodus wartet die Antwort bewusst nicht ab).
	 * - ROT für jeden Fehlerfall -- vorhandene error_message (Tooltip trägt
	 *   den Text) ODER ein 4xx/5xx-Statuscode. Deckungsgleich mit
	 *   PMS_Logger::is_error_row(), das denselben Zustand für den Filter
	 *   "Nur Fehler" auswertet.
	 * - Neutral bleibt einzig der praktisch unerreichbare Rest (1xx/3xx).
	 *
	 * @param array $entry
	 * @return void
	 */
	private static function render_status_badge( array $entry ) {
		$code    = (int) $entry['http_status'];
		$message = (string) ( $entry['error_message'] ?? '' );

		if ( PMS_Logger::is_error_row( $entry ) ) {
			printf(
				'<span class="pms-badge pms-badge-log-error"%1$s>%2$s</span>',
				'' !== $message ? ' title="' . esc_attr( $message ) . '"' : '',
				esc_html( $code > 0 ? $code . ' ' . __( 'Error', 'pixel-made-simple' ) : __( 'Error', 'pixel-made-simple' ) )
			);
			return;
		}

		if ( $code >= 200 && $code < 300 ) {
			printf( '<span class="pms-badge pms-badge-log-ok">%s</span>', esc_html( $code . ' OK' ) );
			return;
		}

		if ( 0 === $code ) {
			printf( '<span class="pms-badge pms-badge-log-ok">%s</span>', esc_html__( 'Sent', 'pixel-made-simple' ) );
			return;
		}

		printf( '<span class="pms-badge pms-badge-log-neutral">%s</span>', esc_html( (string) $code ) );
	}

	/**
	 * "Log leeren"-Button.
	 *
	 * @return void
	 */
	public static function handle_clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixel-made-simple' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'pms_clear_event_log' );

		PMS_Logger::truncate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => PMS_Admin::PAGE_SLUG,
					'tab'         => 'log',
					'pms_message' => 'log_cleared',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
