<?php
/**
 * Admin-Oberfläche: Menü, Tabs, Plattform-Accordions und Events-Verwaltung.
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Admin {

	const CAPABILITY = 'manage_options';
	const PAGE_SLUG  = 'lmpct-settings';
	const HELP_SLUG  = 'lmpct-help';

	/**
	 * Hook-Suffix der Hilfe-Unterseite (für gezieltes Asset-Loading).
	 *
	 * @var string
	 */
	private static $help_hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'wp_ajax_lmpct_save_toggle', array( __CLASS__, 'handle_toggle_autosave' ) );

		add_action( 'admin_post_lmpct_save_event', array( __CLASS__, 'handle_save_event' ) );
		add_action( 'admin_post_lmpct_delete_event', array( __CLASS__, 'handle_delete_event' ) );
		add_action( 'admin_post_lmpct_toggle_event', array( __CLASS__, 'handle_toggle_event' ) );
		add_action( 'admin_post_lmpct_toggle_all_events', array( __CLASS__, 'handle_toggle_all_events' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( LMPCT_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Link "Einstellungen" in der Pluginliste.
	 *
	 * @param string[] $links Bestehende Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lightweight-meta-pixel-capi-tracker' ) . '</a>' );
		return $links;
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Meta Pixel & CAPI Tracker', 'lightweight-meta-pixel-capi-tracker' ),
			__( 'Pixel Tracker', 'lightweight-meta-pixel-capi-tracker' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			81
		);

		// Ersten Submenü-Eintrag (Duplikat des Hauptmenüs) sinnvoll benennen.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Meta Pixel & CAPI Tracker', 'lightweight-meta-pixel-capi-tracker' ),
			__( 'Settings', 'lightweight-meta-pixel-capi-tracker' ),
			self::CAPABILITY,
			self::PAGE_SLUG
		);

		self::$help_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Info & Help', 'lightweight-meta-pixel-capi-tracker' ),
			__( 'Info & Help', 'lightweight-meta-pixel-capi-tracker' ),
			self::CAPABILITY,
			self::HELP_SLUG,
			array( __CLASS__, 'render_help_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'lmpct_settings_group',
			LMPCT_Settings::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'LMPCT_Settings', 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Assets nur auf der eigenen Seite laden.
	 *
	 * @param string $hook_suffix Aktueller Admin-Screen.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix && self::$help_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'lmpct-admin', LMPCT_PLUGIN_URL . 'assets/admin.css', array( 'dashicons' ), LMPCT_VERSION );
		wp_enqueue_script( 'lmpct-admin', LMPCT_PLUGIN_URL . 'assets/admin.js', array(), LMPCT_VERSION, true );

		wp_localize_script(
			'lmpct-admin',
			'lmpctAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'lmpct_toggle_autosave' ),
				'savedText' => __( 'Saved.', 'lightweight-meta-pixel-capi-tracker' ),
			)
		);
	}

	/**
	 * AJAX: einzelnen Einstellungs-Toggle sofort speichern (nonce-gesichert).
	 *
	 * @return void
	 */
	public static function handle_toggle_autosave() {
		check_ajax_referer( 'lmpct_toggle_autosave', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$allowed = array(
			'exclude_admins',
			'consent_detection',
			'pixel_enabled',
			'capi_enabled',
			'hash_email',
			'google_enabled',
			'google_consent_mode',
			'tiktok_enabled',
			'form_tracking',
			'utm_passthrough',
			'debug_bar',
		);

		$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );

		if ( ! in_array( $key, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'invalid_key' ), 400 );
		}

		$settings         = LMPCT_Settings::get();
		$settings[ $key ] = empty( $_POST['value'] ) ? 0 : 1;

		update_option( LMPCT_Settings::OPTION_SETTINGS, LMPCT_Settings::sanitize_settings( $settings ) );

		wp_send_json_success( array( 'key' => $key, 'value' => $settings[ $key ] ) );
	}

	/**
	 * Erfolgs-/Fehlermeldungen nach Redirects.
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || ! isset( $_GET['lmpct_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Auswahl einer statischen Meldung.
			return;
		}

		$messages = array(
			'saved'          => array( 'success', __( 'Event saved.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'deleted'        => array( 'success', __( 'Event deleted.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'toggled'        => array( 'success', __( 'Event status updated.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'global_toggled' => array( 'success', __( 'Setting saved.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'invalid'        => array( 'error', __( 'The event could not be saved. Please fill in all required fields correctly.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'no_platform'    => array( 'error', __( 'Please enable at least one platform for this event.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'missing_label'  => array( 'error', __( 'Google Ads is enabled for this event but the conversion label is missing.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'not_found'      => array( 'error', __( 'The event could not be found.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'imported'       => array( 'success', __( 'Configuration imported successfully.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'import_invalid' => array( 'error', __( 'The file could not be imported. Please upload a valid export of this plugin.', 'lightweight-meta-pixel-capi-tracker' ) ),
			'import_missing' => array( 'error', __( 'Please choose a JSON file to import.', 'lightweight-meta-pixel-capi-tracker' ) ),
		);

		$key = sanitize_key( wp_unslash( $_GET['lmpct_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $key ][0] ),
			esc_html( $messages[ $key ][1] )
		);
	}

	/* ---------------------------------------------------------------------
	 * UI-Bausteine
	 * ------------------------------------------------------------------- */

	/**
	 * Wiederverwendbarer Toggle-Switch im WP-Stil.
	 *
	 * @param string $name         name-Attribut.
	 * @param bool   $checked      Zustand.
	 * @param string $label        Screenreader-Label.
	 * @param bool   $submit       Formular bei Änderung automatisch absenden.
	 * @param string $autosave_key Einstellungs-Schlüssel für das sofortige
	 *                             AJAX-Speichern (leer = kein Autosave).
	 * @return void
	 */
	private static function toggle( $name, $checked, $label, $submit = false, $autosave_key = '' ) {
		?>
		<label class="lmpct-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"
				<?php checked( $checked ); ?>
				<?php echo $submit ? 'data-lmpct-autosubmit="1"' : ''; ?>
				<?php echo '' !== $autosave_key ? 'data-lmpct-autosave="' . esc_attr( $autosave_key ) . '"' : ''; ?> />
			<span class="lmpct-toggle-slider" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Info-Tooltip (Dashicon mit reiner CSS-Hover-Box).
	 *
	 * @param string $text Hilfetext.
	 * @return void
	 */
	private static function tip( $text ) {
		?>
		<span class="lmpct-tip" tabindex="0">
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<span class="lmpct-tip-box" role="tooltip"><?php echo esc_html( $text ); ?></span>
		</span>
		<?php
	}

	/**
	 * Aufklappbare Plattform-Box im WP-Postbox-Stil öffnen.
	 *
	 * Deaktivierte Plattformen starten eingeklappt; aktive Boxen erhalten
	 * einen blauen Akzent (Klasse lmpct-on).
	 *
	 * @param string $title          Box-Titel.
	 * @param string $toggle_name    name-Attribut des Master-Toggles.
	 * @param bool   $toggle_checked Zustand des Master-Toggles.
	 * @param string $toggle_label   Screenreader-Label des Master-Toggles.
	 * @return void
	 */
	private static function accordion_open( $title, $toggle_name, $toggle_checked, $toggle_label, $autosave_key = '' ) {
		$classes = 'postbox lmpct-accordion';
		if ( $toggle_checked ) {
			$classes .= ' lmpct-on';
		} else {
			$classes .= ' closed';
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="postbox-header lmpct-accordion-header">
				<h2 class="hndle"><?php echo esc_html( $title ); ?></h2>
				<div class="lmpct-accordion-controls">
					<?php self::toggle( $toggle_name, $toggle_checked, $toggle_label, false, $autosave_key ); ?>
					<button type="button" class="lmpct-accordion-button" aria-expanded="<?php echo $toggle_checked ? 'true' : 'false'; ?>">
						<span class="screen-reader-text">
							<?php
							/* translators: %s: platform box title */
							echo esc_html( sprintf( __( 'Toggle panel: %s', 'lightweight-meta-pixel-capi-tracker' ), $title ) );
							?>
						</span>
						<span class="lmpct-accordion-arrow" aria-hidden="true"></span>
					</button>
				</div>
			</div>
			<div class="inside">
		<?php
	}

	private static function accordion_close() {
		echo '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Seiten-Rendering
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lightweight-meta-pixel-capi-tracker' ) );
		}

		$tabs = array(
			'general'  => __( 'General', 'lightweight-meta-pixel-capi-tracker' ),
			'events'   => __( 'URL Events', 'lightweight-meta-pixel-capi-tracker' ),
			'advanced' => __( 'Advanced Tracking', 'lightweight-meta-pixel-capi-tracker' ),
			'tools'    => __( 'Tools', 'lightweight-meta-pixel-capi-tracker' ),
		);

		$active_tab = 'general';
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Tab-Navigation.
			$requested = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $tabs[ $requested ] ) ) {
				$active_tab = $requested;
			}
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap lmpct-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Lightweight Meta Pixel & CAPI Tracker', 'lightweight-meta-pixel-capi-tracker' ); ?></h1>
			<hr class="wp-header-end" />

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Plugin tabs', 'lightweight-meta-pixel-capi-tracker' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( 'general' === $slug ? $base_url : add_query_arg( 'tab', $slug, $base_url ) ); ?>"
						class="nav-tab <?php echo $slug === $active_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $active_tab ) {
				case 'events':
					self::render_events_tab();
					break;
				case 'advanced':
					self::render_advanced_tab();
					break;
				case 'tools':
					self::render_tools_tab();
					break;
				default:
					self::render_general_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Tab 3: Erweiterte Tracking-Features.
	 *
	 * @return void
	 */
	private static function render_advanced_tab() {
		$s = LMPCT_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'lmpct_settings_group' ); ?>
			<?php self::preserve_hidden_settings( $s, array( 'form_tracking', 'utm_passthrough', 'debug_bar' ) ); ?>

			<h2 class="lmpct-section-title"><?php esc_html_e( 'Advanced Tracking Features', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Automatic form lead tracking', 'lightweight-meta-pixel-capi-tracker' ); ?>
						<?php self::tip( __( 'Supports Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms and plain HTML forms.', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<?php self::toggle( 'lmpct_settings[form_tracking]', ! empty( $s['form_tracking'] ), __( 'Enable automatic form lead tracking', 'lightweight-meta-pixel-capi-tracker' ), false, 'form_tracking' ); ?>
						<p class="description"><?php esc_html_e( 'Detects form submissions automatically and fires the “Lead” event in the browser and via the Conversions API using the same event ID. Email address and phone number are hashed with SHA-256 before they are sent – raw values never leave your server and are never stored.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'First-touch & UTM passthrough', 'lightweight-meta-pixel-capi-tracker' ); ?>
						<?php self::tip( __( 'Stores utm_source, utm_medium, utm_campaign, utm_content, utm_term and fbclid in a first-party cookie for 30 days.', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<?php self::toggle( 'lmpct_settings[utm_passthrough]', ! empty( $s['utm_passthrough'] ), __( 'Enable UTM passthrough', 'lightweight-meta-pixel-capi-tracker' ), false, 'utm_passthrough' ); ?>
						<p class="description"><?php esc_html_e( 'Saves campaign parameters on the first visit and sends them along with every server-side event as custom_data. A stored fbclid is converted into the fbc format so conversions stay attributed even days after the ad click.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin live debug bar', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[debug_bar]', ! empty( $s['debug_bar'] ), __( 'Show live debug bar in the frontend', 'lightweight-meta-pixel-capi-tracker' ), false, 'debug_bar' ); ?>
						<p class="description"><?php esc_html_e( 'Shows a small bar at the bottom of the frontend with consent status, fired events, event IDs, CAPI response and match keys. Rendered exclusively for logged-in administrators – regular visitors get zero additional bytes.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Tab 4: Werkzeuge (Export/Import).
	 *
	 * @return void
	 */
	private static function render_tools_tab() {
		$admin_post = admin_url( 'admin-post.php' );
		?>
		<h2 class="lmpct-section-title"><?php esc_html_e( 'Export & Import', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>

		<div class="lmpct-card">
			<h2><?php esc_html_e( 'Export configuration', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>
			<div class="lmpct-card-body">
				<p><?php esc_html_e( 'Downloads all settings, platform IDs and event rules as a JSON file – ideal for rolling out a proven setup to another site.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
				<p class="description"><strong><?php esc_html_e( 'Note:', 'lightweight-meta-pixel-capi-tracker' ); ?></strong> <?php esc_html_e( 'The export contains your CAPI access token in plain text. Store the file securely and never share it publicly.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<input type="hidden" name="action" value="lmpct_export_settings" />
					<?php wp_nonce_field( 'lmpct_export_settings' ); ?>
					<p>
						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Export configuration', 'lightweight-meta-pixel-capi-tracker' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>

		<div class="lmpct-card">
			<h2><?php esc_html_e( 'Import configuration', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>
			<div class="lmpct-card-body">
				<p><?php esc_html_e( 'Upload a previously exported JSON file. All values are validated and sanitised before they are saved.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
				<p class="description"><strong><?php esc_html_e( 'Note:', 'lightweight-meta-pixel-capi-tracker' ); ?></strong> <?php esc_html_e( 'The import overwrites the current settings and all event rules.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="lmpct_import_settings" />
					<?php wp_nonce_field( 'lmpct_import_settings' ); ?>
					<p>
						<input type="file" name="lmpct_import_file" accept="application/json,.json" required />
					</p>
					<p>
						<button type="submit" class="button lmpct-delete-button"
							data-lmpct-confirm="<?php esc_attr_e( 'Really import? The current settings and event rules will be overwritten.', 'lightweight-meta-pixel-capi-tracker' ); ?>">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<?php esc_html_e( 'Import configuration', 'lightweight-meta-pixel-capi-tracker' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Einstellungen, die auf dem aktuellen Tab kein Feld haben, als Hidden-Felder
	 * mitschicken. Ohne das würde die Settings-API sie beim Speichern leeren,
	 * weil sanitize_settings() immer den vollständigen Datensatz aufbaut.
	 *
	 * @param array    $settings Aktuelle Einstellungen.
	 * @param string[] $skip     Schlüssel, die auf diesem Tab ein eigenes Feld haben.
	 * @return void
	 */
	private static function preserve_hidden_settings( array $settings, array $skip ) {
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $skip, true ) || is_array( $value ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( LMPCT_Settings::OPTION_SETTINGS . '[' . $key . ']' ),
				esc_attr( (string) $value )
			);
		}
	}

	/**
	 * Unterseite "Info & Hilfe": Version, Support-Kontakt und Tutorial-Hinweis.
	 *
	 * @return void
	 */
	public static function render_help_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lightweight-meta-pixel-capi-tracker' ) );
		}

		$support_email = 'dominik@seitzdominik.de';
		$video_url     = 'https://www.youtube.com/watch?v=PLATZHALTER'; // Platzhalter – hier später die Tutorial-URL eintragen.
		?>
		<div class="wrap lmpct-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Info & Help', 'lightweight-meta-pixel-capi-tracker' ); ?></h1>
			<hr class="wp-header-end" />

			<div class="lmpct-card">
				<h2><?php esc_html_e( 'Plugin Information', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>
				<div class="lmpct-card-body">
					<p>
						<strong><?php esc_html_e( 'Lightweight Meta Pixel & CAPI Tracker', 'lightweight-meta-pixel-capi-tracker' ); ?></strong>
						<span class="lmpct-version-badge"><?php echo esc_html( 'v' . LMPCT_VERSION ); ?></span>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: support email address */
							esc_html__( 'If you have questions or found a bug, please email %s.', 'lightweight-meta-pixel-capi-tracker' ),
							'<a href="mailto:' . esc_attr( $support_email ) . '">' . esc_html( $support_email ) . '</a>'
						);
						?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: developer website link */
							esc_html__( 'Developed by %s.', 'lightweight-meta-pixel-capi-tracker' ),
							'<a href="https://sdv.design" target="_blank" rel="noopener noreferrer">Dominik Seitz – sdv.design</a>'
						);
						?>
					</p>
				</div>
			</div>

			<div class="lmpct-card">
				<h2><?php esc_html_e( 'Video Tutorial', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>
				<div class="lmpct-card-body">
					<div class="lmpct-video-box">
						<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>
						<div>
							<p><?php esc_html_e( 'A step-by-step video tutorial on setting up the plugin is coming soon:', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
							<p>
								<a class="button button-secondary" href="<?php echo esc_url( $video_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Watch the tutorial on YouTube', 'lightweight-meta-pixel-capi-tracker' ); ?>
								</a>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_general_tab() {
		$s = LMPCT_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'lmpct_settings_group' ); ?>
			<?php
			// Einstellungen der anderen Tabs erhalten (sonst würden sie beim
			// Speichern dieses Formulars zurückgesetzt).
			self::preserve_hidden_settings(
				$s,
				array(
					'exclude_admins',
					'consent_detection',
					'pixel_enabled',
					'pixel_id',
					'capi_enabled',
					'capi_token',
					'test_event_code',
					'test_code_created_at',
					'hash_email',
					'google_enabled',
					'google_tag_id',
					'google_consent_mode',
					'tiktok_enabled',
					'tiktok_pixel_id',
				)
			);
			?>

			<h2 class="lmpct-section-title"><?php esc_html_e( 'Global Options', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Do not track administrators', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[exclude_admins]', ! empty( $s['exclude_admins'] ), __( 'Do not track administrators', 'lightweight-meta-pixel-capi-tracker' ), false, 'exclude_admins' ); ?>
						<p class="description"><?php esc_html_e( 'Recommended: logged-in administrators trigger neither pixel nor server events.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic cookie banner detection (GDPR)', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[consent_detection]', ! empty( $s['consent_detection'] ), __( 'Enable automatic cookie banner detection', 'lightweight-meta-pixel-capi-tracker' ), false, 'consent_detection' ); ?>
						<p class="description"><?php esc_html_e( 'Automatically detects installed cookie banners and blocks browser and CAPI events until consent is given. Supports Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies and any banner using the WP Consent API. Automatic blocking cannot be guaranteed for unlisted third-party banners. Sites without a cookie banner are never blocked.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="lmpct-section-title"><?php esc_html_e( 'Platforms', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>

			<?php self::accordion_open( __( 'Meta (Facebook)', 'lightweight-meta-pixel-capi-tracker' ), 'lmpct_settings[pixel_enabled]', ! empty( $s['pixel_enabled'] ), __( 'Enable Meta tracking', 'lightweight-meta-pixel-capi-tracker' ), 'pixel_enabled' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="lmpct-pixel-id"><?php esc_html_e( 'Meta Pixel ID', 'lightweight-meta-pixel-capi-tracker' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Data sources → your pixel → copy the ID shown below its name.', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<input type="text" id="lmpct-pixel-id" class="regular-text code" inputmode="numeric"
							name="lmpct_settings[pixel_id]" value="<?php echo esc_attr( $s['pixel_id'] ); ?>"
							placeholder="1234567890123456" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Conversions API', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[capi_enabled]', ! empty( $s['capi_enabled'] ), __( 'Enable Conversions API', 'lightweight-meta-pixel-capi-tracker' ), false, 'capi_enabled' ); ?>
						<p class="description"><?php esc_html_e( 'Additionally sends matched events to Meta server-side – deduplicated via the same event ID as in the browser.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lmpct-capi-token"><?php esc_html_e( 'CAPI Access Token', 'lightweight-meta-pixel-capi-tracker' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Data sources → Settings → Conversions API → “Generate access token”.', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<textarea id="lmpct-capi-token" class="large-text code" rows="4" spellcheck="false" autocomplete="off"
							name="lmpct_settings[capi_token]"><?php echo esc_textarea( $s['capi_token'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Secret key. Used exclusively server-side and never rendered in the frontend.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lmpct-test-code"><?php esc_html_e( 'Test Event Code', 'lightweight-meta-pixel-capi-tracker' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Test events → copy the “Server events code”. Clear it again after testing!', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<input type="text" id="lmpct-test-code" class="regular-text code"
							name="lmpct_settings[test_event_code]" value="<?php echo esc_attr( $s['test_event_code'] ); ?>"
							placeholder="TEST12345" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'The test code is automatically deactivated after 12 hours to prevent accidental test tracking on a live site.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Advanced Matching (email)', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[hash_email]', ! empty( $s['hash_email'] ), __( 'Enable Advanced Matching', 'lightweight-meta-pixel-capi-tracker' ), false, 'hash_email' ); ?>
						<p class="description"><?php esc_html_e( 'Sends the email address of logged-in users to the Conversions API as a SHA-256 hash (better match quality). Mind data privacy.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::accordion_close(); ?>

			<?php self::accordion_open( __( 'Google Ads', 'lightweight-meta-pixel-capi-tracker' ), 'lmpct_settings[google_enabled]', ! empty( $s['google_enabled'] ), __( 'Enable Google Ads tracking', 'lightweight-meta-pixel-capi-tracker' ), 'google_enabled' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="lmpct-google-tag-id"><?php esc_html_e( 'Google Tag ID', 'lightweight-meta-pixel-capi-tracker' ); ?></label>
						<?php self::tip( __( 'Google Ads → Tools & Settings → Google Tag → copy the ID (AW-XXXXX).', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<input type="text" id="lmpct-google-tag-id" class="regular-text code"
							name="lmpct_settings[google_tag_id]" value="<?php echo esc_attr( $s['google_tag_id'] ); ?>"
							placeholder="AW-123456789" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Google Consent Mode v2 (default)', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<td>
						<?php self::toggle( 'lmpct_settings[google_consent_mode]', ! empty( $s['google_consent_mode'] ), __( 'Enable Google Consent Mode v2 defaults', 'lightweight-meta-pixel-capi-tracker' ), false, 'google_consent_mode' ); ?>
						<p class="description"><?php esc_html_e( 'Sets ad_storage, ad_user_data, ad_personalization and analytics_storage to "denied" by default before the tag loads. Your consent banner then sends the consent update. Recommended for the EU.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::accordion_close(); ?>

			<?php self::accordion_open( __( 'TikTok', 'lightweight-meta-pixel-capi-tracker' ), 'lmpct_settings[tiktok_enabled]', ! empty( $s['tiktok_enabled'] ), __( 'Enable TikTok Pixel', 'lightweight-meta-pixel-capi-tracker' ), 'tiktok_enabled' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="lmpct-tiktok-pixel-id"><?php esc_html_e( 'TikTok Pixel ID', 'lightweight-meta-pixel-capi-tracker' ); ?></label>
						<?php self::tip( __( 'TikTok Ads Manager → Assets → Events → Web events → copy the pixel ID.', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
					</th>
					<td>
						<input type="text" id="lmpct-tiktok-pixel-id" class="regular-text code"
							name="lmpct_settings[tiktok_pixel_id]" value="<?php echo esc_attr( $s['tiktok_pixel_id'] ); ?>"
							placeholder="C1234567890ABCDEFG" autocomplete="off" />
					</td>
				</tr>
			</table>
			<?php self::accordion_close(); ?>

			<?php submit_button( __( 'Save Settings', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
		</form>
		<?php
	}

	private static function render_events_tab() {
		$events         = LMPCT_Settings::get_events();
		$events_enabled = LMPCT_Settings::events_enabled();
		$edit_event     = null;

		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur lesende Vorauswahl; alle Schreibaktionen sind nonce-gesichert.
			$edit_id = sanitize_key( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $events[ $edit_id ] ) ) {
				$edit_event = $events[ $edit_id ];
			}
		}

		$admin_post = admin_url( 'admin-post.php' );
		?>

		<h2 class="lmpct-section-title"><?php esc_html_e( 'Custom Events', 'lightweight-meta-pixel-capi-tracker' ); ?></h2>

		<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="lmpct-global-toggle-form">
			<input type="hidden" name="action" value="lmpct_toggle_all_events" />
			<?php wp_nonce_field( 'lmpct_toggle_all_events' ); ?>
			<?php self::toggle( 'events_enabled', $events_enabled, __( 'Enable all custom events', 'lightweight-meta-pixel-capi-tracker' ), true ); ?>
			<span class="lmpct-global-toggle-label">
				<?php esc_html_e( 'Enable/disable all custom events', 'lightweight-meta-pixel-capi-tracker' ); ?>
			</span>
			<noscript><?php submit_button( __( 'Apply', 'lightweight-meta-pixel-capi-tracker' ), 'secondary small', '', false ); ?></noscript>
		</form>

		<?php if ( ! $events_enabled ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Custom events are currently disabled globally. The rules below will not fire.', 'lightweight-meta-pixel-capi-tracker' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped lmpct-events-table">
			<thead>
				<tr>
					<th scope="col" class="lmpct-col-status"><?php esc_html_e( 'Status', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Platforms', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trigger Condition (URL)', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
					<th scope="col" class="lmpct-col-actions"><?php esc_html_e( 'Actions', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No events yet. Create your first event below.', 'lightweight-meta-pixel-capi-tracker' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td class="lmpct-col-status">
								<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
									<input type="hidden" name="action" value="lmpct_toggle_event" />
									<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>" />
									<?php wp_nonce_field( 'lmpct_toggle_event_' . $event['id'] ); ?>
									<?php
									self::toggle(
										'active',
										! empty( $event['active'] ),
										/* translators: %s: event name */
										sprintf( __( 'Enable event “%s”', 'lightweight-meta-pixel-capi-tracker' ), $event['name'] ),
										true
									);
									?>
									<noscript><button type="submit" class="button-link"><?php esc_html_e( 'Toggle', 'lightweight-meta-pixel-capi-tracker' ); ?></button></noscript>
								</form>
							</td>
							<td><strong><?php echo esc_html( $event['name'] ); ?></strong></td>
							<td class="lmpct-col-platforms">
								<?php if ( ! empty( $event['meta_enabled'] ) ) : ?>
									<span class="lmpct-badge lmpct-badge-meta">Meta · <?php echo esc_html( $event['event_type'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $event['google_enabled'] ) ) : ?>
									<span class="lmpct-badge lmpct-badge-google">Google Ads · <?php echo esc_html( $event['google_label'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $event['tiktok_enabled'] ) ) : ?>
									<span class="lmpct-badge lmpct-badge-tiktok">TikTok · <?php echo esc_html( $event['tiktok_event'] ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo esc_html(
									'exact' === $event['match_type']
										? __( 'Exact path:', 'lightweight-meta-pixel-capi-tracker' )
										: __( 'URL contains:', 'lightweight-meta-pixel-capi-tracker' )
								);
								?>
								<code><?php echo esc_html( $event['match_value'] ); ?></code>
							</td>
							<td class="lmpct-col-actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'events', 'edit' => $event['id'] ), admin_url( 'admin.php' ) ) . '#lmpct-event-form' ); ?>">
									<?php esc_html_e( 'Edit', 'lightweight-meta-pixel-capi-tracker' ); ?>
								</a>
								<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="lmpct-inline-form">
									<input type="hidden" name="action" value="lmpct_delete_event" />
									<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>" />
									<?php wp_nonce_field( 'lmpct_delete_event_' . $event['id'] ); ?>
									<button type="submit" class="button button-small lmpct-delete-button"
										data-lmpct-confirm="<?php esc_attr_e( 'Really delete this event?', 'lightweight-meta-pixel-capi-tracker' ); ?>">
										<?php esc_html_e( 'Delete', 'lightweight-meta-pixel-capi-tracker' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php self::render_event_form( $edit_event ); ?>
		<?php
	}

	/**
	 * Formular zum Erstellen/Bearbeiten eines Events.
	 *
	 * @param array|null $event Vorhandenes Event oder null für "Neu".
	 * @return void
	 */
	private static function render_event_form( $event ) {
		$is_edit = is_array( $event );

		$values = wp_parse_args(
			$is_edit ? $event : array(),
			array(
				'id'             => '',
				'name'           => '',
				'event_type'     => 'Lead',
				'match_type'     => 'exact',
				'match_value'    => '',
				'active'         => 1,
				'meta_enabled'   => 1,
				'google_enabled' => 0,
				'google_label'   => '',
				'tiktok_enabled' => 0,
				'tiktok_event'   => 'CompleteRegistration',
			)
		);
		?>
		<div class="lmpct-event-form-card" id="lmpct-event-form">
			<h2>
				<?php
				if ( $is_edit ) {
					/* translators: %s: event name */
					echo esc_html( sprintf( __( 'Edit event “%s”', 'lightweight-meta-pixel-capi-tracker' ), $values['name'] ) );
				} else {
					esc_html_e( 'Create New Event', 'lightweight-meta-pixel-capi-tracker' );
				}
				?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lmpct_save_event" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $values['id'] ); ?>" />
				<?php wp_nonce_field( 'lmpct_save_event' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lmpct-event-name"><?php esc_html_e( 'Event Name (internal)', 'lightweight-meta-pixel-capi-tracker' ); ?></label></th>
						<td>
							<input type="text" id="lmpct-event-name" class="regular-text" name="name" required maxlength="50"
								value="<?php echo esc_attr( $values['name'] ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Lead magnet confirmation', 'lightweight-meta-pixel-capi-tracker' ); ?>" />
							<p class="description lmpct-custom-event-hint" hidden>
								<?php esc_html_e( 'With the “CustomEvent” type, this name is sent as the custom event name.', 'lightweight-meta-pixel-capi-tracker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lmpct-match-type"><?php esc_html_e( 'Trigger', 'lightweight-meta-pixel-capi-tracker' ); ?></label></th>
						<td>
							<select id="lmpct-match-type" name="match_type">
								<option value="exact" <?php selected( $values['match_type'], 'exact' ); ?>><?php esc_html_e( 'Exact path', 'lightweight-meta-pixel-capi-tracker' ); ?></option>
								<option value="contains" <?php selected( $values['match_type'], 'contains' ); ?>><?php esc_html_e( 'URL contains', 'lightweight-meta-pixel-capi-tracker' ); ?></option>
							</select>
							<input type="text" id="lmpct-match-value" class="regular-text code" name="match_value" required
								value="<?php echo esc_attr( $values['match_value'] ); ?>"
								placeholder="/bestaetigung/" />
							<p class="description"><?php esc_html_e( '“Exact path” compares only the URL path (case and trailing slash are ignored). “URL contains” checks the path including the query string.', 'lightweight-meta-pixel-capi-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Platforms', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
						<td class="lmpct-platform-rows">
							<div class="lmpct-platform-row">
								<label class="lmpct-platform-check">
									<input type="checkbox" name="platform_meta" value="1" <?php checked( ! empty( $values['meta_enabled'] ) ); ?> />
									<strong>Meta</strong>
								</label>
								<select id="lmpct-event-type" name="event_type" aria-label="<?php esc_attr_e( 'Meta event type', 'lightweight-meta-pixel-capi-tracker' ); ?>">
									<?php foreach ( LMPCT_Settings::meta_event_types() as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['event_type'], $type ); ?>>
											<?php echo esc_html( $type ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="lmpct-platform-row">
								<label class="lmpct-platform-check">
									<input type="checkbox" name="platform_google" value="1" <?php checked( ! empty( $values['google_enabled'] ) ); ?> />
									<strong>Google Ads</strong>
								</label>
								<input type="text" class="regular-text code" name="google_label"
									value="<?php echo esc_attr( $values['google_label'] ); ?>"
									placeholder="AbCdEfGhIjK123"
									aria-label="<?php esc_attr_e( 'Google Ads conversion label', 'lightweight-meta-pixel-capi-tracker' ); ?>" />
								<?php self::tip( __( 'Google Ads → Conversion goals → click the action → Tag setup → snippet → copy the label after the slash (AW-XXX/LABEL).', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
							</div>
							<div class="lmpct-platform-row">
								<label class="lmpct-platform-check">
									<input type="checkbox" name="platform_tiktok" value="1" <?php checked( ! empty( $values['tiktok_enabled'] ) ); ?> />
									<strong>TikTok</strong>
								</label>
								<select id="lmpct-tiktok-event" name="tiktok_event" aria-label="<?php esc_attr_e( 'TikTok event type', 'lightweight-meta-pixel-capi-tracker' ); ?>">
									<?php foreach ( LMPCT_Settings::tiktok_event_types() as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['tiktok_event'], $type ); ?>>
											<?php echo esc_html( $type ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'lightweight-meta-pixel-capi-tracker' ); ?></th>
						<td>
							<?php self::toggle( 'active', ! empty( $values['active'] ), __( 'Event active', 'lightweight-meta-pixel-capi-tracker' ) ); ?>
							<span class="lmpct-global-toggle-label"><?php esc_html_e( 'Event active', 'lightweight-meta-pixel-capi-tracker' ); ?></span>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php
					submit_button(
						$is_edit ? __( 'Update Event', 'lightweight-meta-pixel-capi-tracker' ) : __( 'Create Event', 'lightweight-meta-pixel-capi-tracker' ),
						'primary',
						'submit',
						false
					);
					?>
					<?php if ( $is_edit ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'events' ), admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'Cancel', 'lightweight-meta-pixel-capi-tracker' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Aktions-Handler (admin-post.php) – alle nonce- und rechtegeprüft
	 * ------------------------------------------------------------------- */

	/**
	 * Rechte prüfen, sonst abbrechen.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lightweight-meta-pixel-capi-tracker' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Zurück zum Events-Tab.
	 *
	 * @param string $message Meldungs-Schlüssel.
	 * @return void
	 */
	private static function redirect_events( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'tab'           => 'events',
					'lmpct_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_event() {
		self::require_capability();
		check_admin_referer( 'lmpct_save_event' );

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		$is_new   = ( '' === $event_id );

		if ( $is_new ) {
			$event_id = sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) );
		}

		$meta_enabled   = ! empty( $_POST['platform_meta'] );
		$google_enabled = ! empty( $_POST['platform_google'] );
		$tiktok_enabled = ! empty( $_POST['platform_tiktok'] );

		if ( ! $meta_enabled && ! $google_enabled && ! $tiktok_enabled ) {
			self::redirect_events( 'no_platform' );
		}

		$google_label = trim( sanitize_text_field( wp_unslash( $_POST['google_label'] ?? '' ) ) );
		if ( $google_enabled && '' === $google_label ) {
			self::redirect_events( 'missing_label' );
		}

		$event = LMPCT_Settings::sanitize_event(
			array(
				'id'             => $event_id,
				'name'           => wp_unslash( $_POST['name'] ?? '' ),
				'event_type'     => wp_unslash( $_POST['event_type'] ?? '' ),
				'match_type'     => wp_unslash( $_POST['match_type'] ?? '' ),
				'match_value'    => wp_unslash( $_POST['match_value'] ?? '' ),
				'active'         => ! empty( $_POST['active'] ),
				'meta_enabled'   => $meta_enabled,
				'google_enabled' => $google_enabled,
				'google_label'   => $google_label,
				'tiktok_enabled' => $tiktok_enabled,
				'tiktok_event'   => wp_unslash( $_POST['tiktok_event'] ?? '' ),
			)
		);

		if ( null === $event ) {
			self::redirect_events( 'invalid' );
		}

		$events = LMPCT_Settings::get_events();

		if ( ! $is_new && ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		$events[ $event_id ] = $event;
		LMPCT_Settings::save_events( $events );

		self::redirect_events( 'saved' );
	}

	public static function handle_delete_event() {
		self::require_capability();

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		check_admin_referer( 'lmpct_delete_event_' . $event_id );

		$events = LMPCT_Settings::get_events();

		if ( ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		unset( $events[ $event_id ] );
		LMPCT_Settings::save_events( $events );

		self::redirect_events( 'deleted' );
	}

	public static function handle_toggle_event() {
		self::require_capability();

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		check_admin_referer( 'lmpct_toggle_event_' . $event_id );

		$events = LMPCT_Settings::get_events();

		if ( ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		$events[ $event_id ]['active'] = empty( $_POST['active'] ) ? 0 : 1;
		LMPCT_Settings::save_events( $events );

		self::redirect_events( 'toggled' );
	}

	public static function handle_toggle_all_events() {
		self::require_capability();
		check_admin_referer( 'lmpct_toggle_all_events' );

		update_option( LMPCT_Settings::OPTION_EVENTS_ENABLED, empty( $_POST['events_enabled'] ) ? 0 : 1, false );

		self::redirect_events( 'global_toggled' );
	}
}
