<?php
/**
 * Admin-Oberfläche: Menü, Tabs, Plattform-Accordions und Events-Verwaltung.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Admin {

	const CAPABILITY         = 'manage_options';
	const PAGE_SLUG          = 'pms-settings';
	const HELP_SLUG          = 'pms-help';
	const EVENT_LOG_SLUG     = 'pms-event-log';
	const IMPORT_EXPORT_SLUG = 'pms-import-export';

	/**
	 * Hook-Suffixe der drei Unterseiten, die nicht der Haupt-Seitenaufruf
	 * sind (für gezieltes Asset-Loading, siehe enqueue_assets()).
	 *
	 * @var string
	 */
	private static $help_hook          = '';
	private static $event_log_hook     = '';
	private static $import_export_hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'wp_ajax_pms_save_toggle', array( __CLASS__, 'handle_toggle_autosave' ) );

		add_action( 'admin_post_pms_save_event', array( __CLASS__, 'handle_save_event' ) );
		add_action( 'admin_post_pms_delete_event', array( __CLASS__, 'handle_delete_event' ) );
		add_action( 'admin_post_pms_toggle_event', array( __CLASS__, 'handle_toggle_event' ) );
		add_action( 'admin_post_pms_toggle_all_events', array( __CLASS__, 'handle_toggle_all_events' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( PMS_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Link "Einstellungen" in der Pluginliste.
	 *
	 * @param string[] $links Bestehende Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'pixel-made-simple' ) . '</a>' );
		return $links;
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Meta Pixel & CAPI Tracker', 'pixel-made-simple' ),
			__( 'Pixel Made Simple', 'pixel-made-simple' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			81
		);

		// Ersten Submenü-Eintrag (Duplikat des Hauptmenüs) sinnvoll benennen.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Meta Pixel & CAPI Tracker', 'pixel-made-simple' ),
			__( 'Settings', 'pixel-made-simple' ),
			self::CAPABILITY,
			self::PAGE_SLUG
		);

		// Direkte Sidebar-Verknüpfungen zu zwei Tabs der Haupt-Seite: beide
		// Callbacks selektieren nur den passenden Tab vor und rendern
		// anschließend dieselbe render_page() -- keine eigene Rendering-Logik,
		// kein Duplikat. Landet der Nutzer über die Tab-Leiste selbst wieder
		// auf einem Tab, führt das normal zurück zu page=pms-settings (die
		// Tab-Links dort zeigen immer auf PAGE_SLUG) -- erwartetes Verhalten
		// für einen reinen Einstiegs-Shortcut.
		self::$event_log_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Event Log', 'pixel-made-simple' ),
			__( 'Event Log', 'pixel-made-simple' ),
			self::CAPABILITY,
			self::EVENT_LOG_SLUG,
			array( __CLASS__, 'render_event_log_shortcut' )
		);

		self::$import_export_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Import / Export', 'pixel-made-simple' ),
			__( 'Import / Export', 'pixel-made-simple' ),
			self::CAPABILITY,
			self::IMPORT_EXPORT_SLUG,
			array( __CLASS__, 'render_import_export_shortcut' )
		);

		self::$help_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'Info & Help', 'pixel-made-simple' ),
			__( 'Info & Help', 'pixel-made-simple' ),
			self::CAPABILITY,
			self::HELP_SLUG,
			array( __CLASS__, 'render_help_page' )
		);
	}

	/**
	 * Sidebar-Shortcut "Event Log" -> Haupt-Seite mit vorausgewähltem Tab.
	 *
	 * @return void
	 */
	public static function render_event_log_shortcut() {
		$_GET['tab'] = 'log';
		self::render_page();
	}

	/**
	 * Sidebar-Shortcut "Import / Export" -> Haupt-Seite mit vorausgewähltem Tab.
	 *
	 * @return void
	 */
	public static function render_import_export_shortcut() {
		$_GET['tab'] = 'tools';
		self::render_page();
	}

	public static function register_settings() {
		register_setting(
			'pms_settings_group',
			PMS_Settings::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'PMS_Settings', 'sanitize_settings' ),
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
		$own_hooks = array(
			'toplevel_page_' . self::PAGE_SLUG,
			self::$help_hook,
			self::$event_log_hook,
			self::$import_export_hook,
		);

		if ( ! in_array( $hook_suffix, $own_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'pms-admin', PMS_PLUGIN_URL . 'assets/admin.css', array( 'dashicons' ), PMS_VERSION );
		wp_enqueue_script( 'pms-admin', PMS_PLUGIN_URL . 'assets/admin.js', array(), PMS_VERSION, true );

		wp_localize_script(
			'pms-admin',
			'pmsAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'pms_toggle_autosave' ),
				'savedText' => __( 'Saved.', 'pixel-made-simple' ),
			)
		);
	}

	/**
	 * Whitelist der per AJAX autosavenden Einstellungs-Keys, getrennt von
	 * sanitize_settings(). 'bool' -> 0/1-Toggle (der historische Normalfall).
	 * 'log_retention_days' ist der erste nicht-boolesche Autosave-Key
	 * (Dropdown im Event-Log-Tab). Keys, die als Pro-only markiert sind,
	 * werden in handle_toggle_autosave() zusätzlich per is_pro() geprüft --
	 * die Free-UI zeigt für sie ohnehin keinen echten Toggle mehr an (siehe
	 * render_general_tab()/render_ecommerce_tab()), das hier ist reines
	 * Defense-in-Depth gegen direkte AJAX-Requests.
	 *
	 * @return array<string,array{type:string,pro_only:bool}>
	 */
	private static function autosave_allowed_keys() {
		return array(
			'exclude_admins'       => array( 'type' => 'bool', 'pro_only' => false ),
			'consent_detection'    => array( 'type' => 'bool', 'pro_only' => false ),
			'pixel_enabled'        => array( 'type' => 'bool', 'pro_only' => false ),
			'capi_enabled'         => array( 'type' => 'bool', 'pro_only' => false ),
			'hash_email'           => array( 'type' => 'bool', 'pro_only' => false ),
			'google_enabled'       => array( 'type' => 'bool', 'pro_only' => true ),
			'google_consent_mode'  => array( 'type' => 'bool', 'pro_only' => true ),
			'tiktok_enabled'       => array( 'type' => 'bool', 'pro_only' => true ),
			'tiktok_capi_enabled'  => array( 'type' => 'bool', 'pro_only' => true ),
			'form_tracking'        => array( 'type' => 'bool', 'pro_only' => false ),
			'form_exclude_system'  => array( 'type' => 'bool', 'pro_only' => false ),
			'utm_passthrough'      => array( 'type' => 'bool', 'pro_only' => true ),
			'enable_utm_form_fill' => array( 'type' => 'bool', 'pro_only' => true ),
			'debug_bar'            => array( 'type' => 'bool', 'pro_only' => false ),
			'log_retention_days'   => array( 'type' => 'log_retention_days', 'pro_only' => true ),
			'wc_tracking_enabled'  => array( 'type' => 'bool', 'pro_only' => true ),
			'wc_purchase_advanced_matching' => array( 'type' => 'bool', 'pro_only' => true ),
			'sc_tracking_enabled'  => array( 'type' => 'bool', 'pro_only' => true ),
			'sc_purchase_advanced_matching' => array( 'type' => 'bool', 'pro_only' => true ),
		);
	}

	/**
	 * AJAX: einzelnen Einstellungs-Toggle sofort speichern (nonce-gesichert).
	 *
	 * @return void
	 */
	public static function handle_toggle_autosave() {
		check_ajax_referer( 'pms_toggle_autosave', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$allowed = self::autosave_allowed_keys();
		$key     = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );

		if ( ! isset( $allowed[ $key ] ) ) {
			wp_send_json_error( array( 'message' => 'invalid_key' ), 400 );
		}

		if ( ! empty( $allowed[ $key ]['pro_only'] ) && ! PMS_Settings::is_pro() ) {
			wp_send_json_error( array( 'message' => 'pro_required' ), 403 );
		}

		$settings = PMS_Settings::get();

		if ( 'log_retention_days' === $allowed[ $key ]['type'] ) {
			$requested        = (int) wp_unslash( $_POST['value'] ?? 0 );
			$settings[ $key ] = in_array( $requested, PMS_Settings::ALLOWED_LOG_RETENTION_DAYS, true )
				? $requested
				: PMS_Settings::DEFAULT_LOG_RETENTION_DAYS;
		} else {
			$settings[ $key ] = empty( $_POST['value'] ) ? 0 : 1;
		}

		update_option( PMS_Settings::OPTION_SETTINGS, PMS_Settings::sanitize_settings( $settings ) );

		wp_send_json_success( array( 'key' => $key, 'value' => $settings[ $key ] ) );
	}

	/**
	 * Erfolgs-/Fehlermeldungen nach Redirects.
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || ! isset( $_GET['pms_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Auswahl einer statischen Meldung.
			return;
		}

		$messages = array(
			'saved'          => array( 'success', __( 'Event saved.', 'pixel-made-simple' ) ),
			'deleted'        => array( 'success', __( 'Event deleted.', 'pixel-made-simple' ) ),
			'toggled'        => array( 'success', __( 'Event status updated.', 'pixel-made-simple' ) ),
			'global_toggled' => array( 'success', __( 'Setting saved.', 'pixel-made-simple' ) ),
			'invalid'        => array( 'error', __( 'The event could not be saved. Please fill in all required fields correctly.', 'pixel-made-simple' ) ),
			'no_platform'    => array( 'error', __( 'Please enable at least one platform for this event.', 'pixel-made-simple' ) ),
			'missing_label'  => array( 'error', __( 'Google Ads is enabled for this event but the conversion label is missing.', 'pixel-made-simple' ) ),
			'not_found'      => array( 'error', __( 'The event could not be found.', 'pixel-made-simple' ) ),
			'imported'       => array( 'success', __( 'Configuration imported successfully.', 'pixel-made-simple' ) ),
			'import_invalid' => array( 'error', __( 'The file could not be imported. Please upload a valid export of this plugin.', 'pixel-made-simple' ) ),
			'import_missing' => array( 'error', __( 'Please choose a JSON file to import.', 'pixel-made-simple' ) ),
			'free_limit_reached' => array( 'error', __( 'The free version includes up to 2 URL events. Upgrade to Pro for unlimited events.', 'pixel-made-simple' ) ),
			'log_cleared'        => array( 'success', __( 'Event log cleared.', 'pixel-made-simple' ) ),
		);

		$key = sanitize_key( wp_unslash( $_GET['pms_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
		<label class="pms-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"
				<?php checked( $checked ); ?>
				<?php echo $submit ? 'data-pms-autosubmit="1"' : ''; ?>
				<?php echo '' !== $autosave_key ? 'data-pms-autosave="' . esc_attr( $autosave_key ) . '"' : ''; ?> />
			<span class="pms-toggle-slider" aria-hidden="true"></span>
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
	public static function tip( $text ) {
		?>
		<span class="pms-tip" tabindex="0">
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<span class="pms-tip-box" role="tooltip"><?php echo esc_html( $text ); ?></span>
		</span>
		<?php
	}

	/**
	 * Aufklappbare Plattform-Box im WP-Postbox-Stil öffnen.
	 *
	 * Deaktivierte Plattformen starten eingeklappt; aktive Boxen erhalten
	 * einen blauen Akzent (Klasse pms-on).
	 *
	 * @param string $title          Box-Titel.
	 * @param string $toggle_name    name-Attribut des Master-Toggles.
	 * @param bool   $toggle_checked Zustand des Master-Toggles.
	 * @param string $toggle_label   Screenreader-Label des Master-Toggles.
	 * @param string $autosave_key   Einstellungs-Schlüssel für sofortiges AJAX-Speichern.
	 * @param string $tip_text       Optionaler Hilfetext neben dem Box-Titel.
	 * @return void
	 */
	private static function accordion_open( $title, $toggle_name, $toggle_checked, $toggle_label, $autosave_key = '', $tip_text = '' ) {
		$classes = 'postbox pms-accordion';
		if ( $toggle_checked ) {
			$classes .= ' pms-on';
		} else {
			$classes .= ' closed';
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="postbox-header pms-accordion-header">
				<h2 class="hndle">
					<?php echo esc_html( $title ); ?>
					<?php
					if ( '' !== $tip_text ) {
						self::tip( $tip_text );
					}
					?>
				</h2>
				<div class="pms-accordion-controls">
					<?php self::toggle( $toggle_name, $toggle_checked, $toggle_label, false, $autosave_key ); ?>
					<button type="button" class="pms-accordion-button" aria-expanded="<?php echo $toggle_checked ? 'true' : 'false'; ?>">
						<span class="screen-reader-text">
							<?php
							/* translators: %s: platform box title */
							echo esc_html( sprintf( __( 'Toggle panel: %s', 'pixel-made-simple' ), $title ) );
							?>
						</span>
						<span class="pms-accordion-arrow" aria-hidden="true"></span>
					</button>
				</div>
			</div>
			<div class="inside">
		<?php
	}

	private static function accordion_close() {
		echo '</div></div>';
	}

	/**
	 * Ziel-URL für "Upgrade to Pro"-CTAs, mit einfacher UTM-Kennzeichnung
	 * je Herkunfts-Feature (eigene Analytics auf pixelmadesimple.com).
	 *
	 * @param string $feature Kurzer Slug des Features, das den Klick auslöst.
	 * @return string
	 */
	public static function upgrade_url( $feature ) {
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'upgrade-cta',
				'utm_campaign' => $feature,
			),
			'https://pixelmadesimple.com'
		);
	}

	/**
	 * Ausgegraute Teaser-Box für ein Pro-only-Feature (Ersatz für eine
	 * normale Accordion-/Card-Box, wenn ! PMS_Settings::is_pro()).
	 *
	 * @param string $title       Box-Titel.
	 * @param string $description Kurzbeschreibung des Pro-Features.
	 * @param string $feature     Slug für upgrade_url().
	 * @return void
	 */
	public static function render_pro_teaser_box( $title, $description, $feature ) {
		?>
		<div class="postbox pms-pro-teaser">
			<div class="pms-pro-teaser-header">
				<h2 class="hndle">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<?php echo esc_html( $title ); ?>
					<span class="pms-pro-badge"><?php esc_html_e( 'Pro', 'pixel-made-simple' ); ?></span>
				</h2>
			</div>
			<div class="inside">
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<p class="pms-upgrade-cta">
					<a class="button button-primary" href="<?php echo esc_url( self::upgrade_url( $feature ) ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
						<?php esc_html_e( 'Upgrade to Pro', 'pixel-made-simple' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Seiten-Rendering
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pixel-made-simple' ) );
		}

		// Seit v0.6.5 nur noch die vier "echten" Einstellungs-Tabs in der
		// oberen Leiste -- "Event Log" und "Import / Export" sind seit v0.6.4
		// eigene Einträge in der WP-Seitenleiste (siehe register_menu()) und
		// waren hier nur noch eine redundante zweite Navigation zu genau
		// demselben Ziel. "E-Commerce" ist jetzt IMMER sichtbar, auch ohne
		// WooCommerce (siehe render_ecommerce_tab() für den Hinweis-Zweig,
		// der in diesem Fall statt der echten Einstellungen rendert).
		$tabs = array(
			'general'   => __( 'General', 'pixel-made-simple' ),
			'events'    => __( 'URL Events', 'pixel-made-simple' ),
			'advanced'  => __( 'Advanced Tracking', 'pixel-made-simple' ),
			'ecommerce' => __( 'E-Commerce', 'pixel-made-simple' ),
		);

		// 'log'/'tools' tauchen in der Leiste selbst nicht mehr auf, bleiben
		// aber gültige Tab-Slugs für die Weiche unten -- die beiden Sidebar-
		// Shortcuts (render_event_log_shortcut()/render_import_export_shortcut())
		// rufen render_page() mit genau diesen $_GET['tab']-Werten auf, ohne
		// dass die Slugs in $tabs (und damit in der Navigation) stehen müssen.
		$valid_tabs = $tabs + array(
			'log'   => __( 'Event Log', 'pixel-made-simple' ),
			'tools' => __( 'Import / Export', 'pixel-made-simple' ), // Umbenannt von "Tools" -- Slug bleibt 'tools' (siehe PMS_Tools::redirect()).
		);

		$active_tab = 'general';
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Tab-Navigation.
			$requested = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $valid_tabs[ $requested ] ) ) {
				$active_tab = $requested;
			}
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap pms-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Pixel Made Simple', 'pixel-made-simple' ); ?></h1>
			<hr class="wp-header-end" />

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Plugin tabs', 'pixel-made-simple' ); ?>">
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
				case 'ecommerce':
					self::render_ecommerce_tab();
					break;
				case 'log':
					PMS_Admin_Event_Log::render_tab();
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
		$s = PMS_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'pms_settings_group' ); ?>
			<?php
			$advanced_skip = array(
				'form_tracking',
				'form_event_type',
				'form_url_filter',
				'form_exclude_system',
				'debug_bar',
				// Bewusst NICHT über ein verstecktes Feld auf diesem (oder jedem
				// anderen) Tab mitgeschickt: Der CAPI-Token soll ausschließlich
				// im Tab "Allgemein" im Seitenquelltext auftauchen. Fehlt der
				// Schlüssel im POST, behält PMS_Settings::sanitize_settings()
				// den bisherigen Wert bei, statt ihn zu leeren. Seit v0.6.6
				// gilt dasselbe für den TikTok-Events-API-Token.
				'capi_token',
				'tiktok_access_token',
			);
			if ( PMS_Settings::is_pro() ) {
				// Diese vier haben auf diesem Tab nur dann ein echtes Feld weiter
				// unten, wenn Pro tatsächlich rendert - deshalb hier ausgenommen.
				// In der Free-Version zeigt der Tab stattdessen einen Teaser ohne
				// echte Felder; dort MÜSSEN die vier Keys stattdessen ganz normal
				// über preserve_hidden_settings() erhalten bleiben (siehe unten),
				// sonst würde das Speichern dieses Formulars eine unter Pro bereits
				// gesetzte UTM-Konfiguration bei einem Downgrade auf Free
				// stillschweigend auf 0/leer zurücksetzen.
				$advanced_skip[] = 'utm_passthrough';
				$advanced_skip[] = 'enable_utm_form_fill';
				$advanced_skip[] = 'utm_form_fill_mode';
				$advanced_skip[] = 'utm_form_fill_urls';
			}
			self::preserve_hidden_settings( $s, $advanced_skip );
			?>

			<h2 class="pms-section-title"><?php esc_html_e( 'Advanced Tracking Features', 'pixel-made-simple' ); ?></h2>

			<?php self::render_conflict_notice(); ?>

			<?php
			self::accordion_open(
				__( 'Automatic form lead tracking', 'pixel-made-simple' ),
				'pms_settings[form_tracking]',
				! empty( $s['form_tracking'] ),
				__( 'Enable automatic form lead tracking', 'pixel-made-simple' ),
				'form_tracking',
				__( 'Supports Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms and plain HTML forms.', 'pixel-made-simple' )
			);
			?>
			<p class="description"><?php esc_html_e( 'Detects form submissions automatically and fires the configured event in the browser and via the Conversions API using the same event ID. Email address and phone number are hashed with SHA-256 before they are sent – raw values never leave your server and are never stored.', 'pixel-made-simple' ); ?></p>
			<p class="description"><?php esc_html_e( 'The event fires the moment the submit button is clicked. If your page redirects to a separate thank-you page after submitting, set up tracking via the “URL Events” tab instead.', 'pixel-made-simple' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pms-form-event-type"><?php esc_html_e( 'Event type', 'pixel-made-simple' ); ?></label></th>
					<td>
						<select id="pms-form-event-type" name="pms_settings[form_event_type]">
							<?php foreach ( PMS_Settings::form_event_types() as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $s['form_event_type'], $type ); ?>>
									<?php echo esc_html( $type ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Meta event fired on form submission. Use “Contact” for general enquiries and “Lead” for genuine acquisition forms.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pms-form-url-filter"><?php esc_html_e( 'Run on specific pages only (optional)', 'pixel-made-simple' ); ?></label></th>
					<td>
						<input type="text" id="pms-form-url-filter" class="large-text code"
							name="pms_settings[form_url_filter]" value="<?php echo esc_attr( $s['form_url_filter'] ); ?>"
							placeholder="/kontakt, /angebot, /anfrage" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Enter paths separated by commas (e.g. /kontakt, /angebot, /anfrage). Leave empty to track on the entire website. On pages that do not match, the script is not loaded at all.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ignore search, comments & logins', 'pixel-made-simple' ); ?></th>
					<td>
						<?php self::toggle( 'pms_settings[form_exclude_system]', ! empty( $s['form_exclude_system'] ), __( 'Ignore search, comments and logins', 'pixel-made-simple' ), false, 'form_exclude_system' ); ?>
						<p class="description"><?php esc_html_e( 'Prevents accidental tracking of search bars, blog comments and login fields. Forms containing a password field are always ignored, regardless of this setting.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::accordion_close(); ?>

			<?php if ( PMS_Settings::is_pro() ) : ?>
				<?php
				self::accordion_open(
					__( 'First-touch & UTM passthrough', 'pixel-made-simple' ),
					'pms_settings[utm_passthrough]',
					! empty( $s['utm_passthrough'] ),
					__( 'Enable UTM passthrough', 'pixel-made-simple' ),
					'utm_passthrough',
					__( 'Stores utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid and gclid in a first-party cookie for 30 days.', 'pixel-made-simple' )
				);
				?>
				<p class="description"><?php esc_html_e( 'Saves campaign parameters on the first visit and sends them along with every server-side event as custom_data. A stored fbclid is converted into the fbc format so conversions stay attributed even days after the ad click.', 'pixel-made-simple' ); ?></p>
				<?php self::accordion_close(); ?>

				<?php
				self::accordion_open(
					__( 'Automatic UTM form fill', 'pixel-made-simple' ),
					'pms_settings[enable_utm_form_fill]',
					! empty( $s['enable_utm_form_fill'] ),
					__( 'Enable automatic UTM form fill', 'pixel-made-simple' ),
					'enable_utm_form_fill',
					__( 'Writes Source, Campaign and Medium into matching form fields before the visitor submits.', 'pixel-made-simple' )
				);
				?>
				<p class="description"><?php esc_html_e( 'Fills hidden or visible form fields with the visitor’s campaign values so they land in your CRM or notification email together with the lead. Source is read from the current URL first, then – if “First-touch & UTM passthrough” above is enabled – from the attribution cookie for visitors who already navigated to a subpage, and finally guessed from a Facebook/Google click ID or the referrer. Campaign and Medium are only filled when an explicit value is found (URL or cookie).', 'pixel-made-simple' ); ?></p>
				<table class="widefat striped pms-utm-fields-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Value', 'pixel-made-simple' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Field name', 'pixel-made-simple' ); ?></th>
							<th scope="col"><?php esc_html_e( 'CSS class', 'pixel-made-simple' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'UTM Source', 'pixel-made-simple' ); ?></td>
							<td><code>utm_source</code> / <code>source</code></td>
							<td><code>utm-source</code> / <code>pms-utm-source</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'UTM Campaign', 'pixel-made-simple' ); ?></td>
							<td><code>utm_campaign</code> / <code>campaign</code></td>
							<td><code>utm-campaign</code> / <code>pms-utm-campaign</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'UTM Medium', 'pixel-made-simple' ); ?></td>
							<td><code>utm_medium</code> / <code>medium</code></td>
							<td><code>utm-medium</code> / <code>pms-utm-medium</code></td>
						</tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Field name takes priority over CSS class. Works both on the input field itself and on a surrounding form block/wrapper element carrying the class. If no UTM Source is found this way, it falls back to facebook, google or direct.', 'pixel-made-simple' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pms-utm-form-fill-mode"><?php esc_html_e( 'Run on', 'pixel-made-simple' ); ?></label></th>
						<td>
							<select id="pms-utm-form-fill-mode" name="pms_settings[utm_form_fill_mode]">
								<option value="all" <?php selected( $s['utm_form_fill_mode'], 'all' ); ?>><?php esc_html_e( 'On all pages', 'pixel-made-simple' ); ?></option>
								<option value="include" <?php selected( $s['utm_form_fill_mode'], 'include' ); ?>><?php esc_html_e( 'Only on specific URLs', 'pixel-made-simple' ); ?></option>
								<option value="exclude" <?php selected( $s['utm_form_fill_mode'], 'exclude' ); ?>><?php esc_html_e( 'Exclude specific URLs', 'pixel-made-simple' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pms-utm-form-fill-urls"><?php esc_html_e( 'URL patterns (one per line)', 'pixel-made-simple' ); ?></label></th>
						<td>
							<textarea id="pms-utm-form-fill-urls" class="large-text code" rows="4" spellcheck="false" autocomplete="off"
								name="pms_settings[utm_form_fill_urls]" placeholder="/kontakt&#10;/lp/*"><?php echo esc_textarea( $s['utm_form_fill_urls'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One path pattern per line, e.g. /kontakt or /lp/* for everything below /lp/. Only used for “Only on specific URLs” and “Exclude specific URLs”.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
				</table>
				<?php self::accordion_close(); ?>
			<?php else : ?>
				<?php
				self::render_pro_teaser_box(
					__( 'First-touch, UTM attribution & automatic form fill', 'pixel-made-simple' ),
					__( 'Store campaign parameters (UTM values + click IDs) in a first-party cookie, send them as custom_data with every server-side event, and automatically fill Source/Campaign/Medium into your forms. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
					'utm-attribution'
				);
				?>
			<?php endif; ?>

			<?php
			self::accordion_open(
				__( 'Admin live debug bar', 'pixel-made-simple' ),
				'pms_settings[debug_bar]',
				! empty( $s['debug_bar'] ),
				__( 'Show live debug bar in the frontend', 'pixel-made-simple' ),
				'debug_bar'
			);
			?>
			<p class="description"><?php esc_html_e( 'Shows a small bar at the bottom of the frontend with consent status, fired events, event IDs, CAPI response and match keys. Rendered exclusively for logged-in administrators – regular visitors get zero additional bytes.', 'pixel-made-simple' ); ?></p>
			<?php self::accordion_close(); ?>

			<?php submit_button( __( 'Save Settings', 'pixel-made-simple' ) ); ?>
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
		<h2 class="pms-section-title"><?php esc_html_e( 'Export & Import', 'pixel-made-simple' ); ?></h2>

		<?php if ( PMS_Settings::is_pro() ) : ?>
			<div class="pms-card">
				<h2><?php esc_html_e( 'Export configuration', 'pixel-made-simple' ); ?></h2>
				<div class="pms-card-body">
					<p><?php esc_html_e( 'Downloads all settings, platform IDs and event rules as a JSON file – ideal for rolling out a proven setup to another site.', 'pixel-made-simple' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'Note:', 'pixel-made-simple' ); ?></strong> <?php esc_html_e( 'The export contains your CAPI access token in plain text. Store the file securely and never share it publicly.', 'pixel-made-simple' ); ?></p>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<input type="hidden" name="action" value="pms_export_settings" />
						<?php wp_nonce_field( 'pms_export_settings' ); ?>
						<p>
							<button type="submit" class="button button-primary">
								<span class="dashicons dashicons-download" aria-hidden="true"></span>
								<?php esc_html_e( 'Export configuration', 'pixel-made-simple' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>
		<?php else : ?>
			<?php
			self::render_pro_teaser_box(
				__( 'Export configuration', 'pixel-made-simple' ),
				__( 'Download all settings, platform IDs and event rules as a JSON file – ideal for rolling out a proven setup to another site or handing off a client project. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
				'export'
			);
			?>
		<?php endif; ?>

		<?php if ( PMS_Settings::is_pro() ) : ?>
			<div class="pms-card">
				<h2><?php esc_html_e( 'Import configuration', 'pixel-made-simple' ); ?></h2>
				<div class="pms-card-body">
					<p><?php esc_html_e( 'Upload a previously exported JSON file. All values are validated and sanitised before they are saved.', 'pixel-made-simple' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'Note:', 'pixel-made-simple' ); ?></strong> <?php esc_html_e( 'The import overwrites the current settings and all event rules.', 'pixel-made-simple' ); ?></p>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="pms_import_settings" />
						<?php wp_nonce_field( 'pms_import_settings' ); ?>
						<p>
							<input type="file" name="pms_import_file" accept="application/json,.json" required />
						</p>
						<p>
							<button type="submit" class="button pms-delete-button"
								data-pms-confirm="<?php esc_attr_e( 'Really import? The current settings and event rules will be overwritten.', 'pixel-made-simple' ); ?>">
								<span class="dashicons dashicons-upload" aria-hidden="true"></span>
								<?php esc_html_e( 'Import configuration', 'pixel-made-simple' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>
		<?php else : ?>
			<?php
			self::render_pro_teaser_box(
				__( 'Import configuration', 'pixel-made-simple' ),
				__( 'Upload a previously exported JSON file to apply all settings, platform IDs and event rules in one step. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
				'import'
			);
			?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sanity-Check: Läuft für denselben Pfad sowohl eine URL-Event-Regel als
	 * auch das automatische Formular-Tracking mit demselben Event-Namen?
	 *
	 * Geprüft wird nur bei ausdrücklich gesetztem URL-Filter – ein leerer
	 * Filter (websiteweit) ist kein Konflikt, solange das Formular nicht auf
	 * eine Danke-Seite weiterleitet.
	 *
	 * @return string[] Betroffene Pfade.
	 */
	public static function detect_form_url_conflicts() {
		$settings = PMS_Settings::get();

		if ( empty( $settings['form_tracking'] ) || ! PMS_Settings::events_enabled() ) {
			return array();
		}

		$filters = PMS_Settings::form_url_filters();

		if ( empty( $filters ) ) {
			return array();
		}

		$form_event = PMS_Settings::form_event_type();
		$conflicts  = array();

		foreach ( PMS_Settings::get_events() as $event ) {
			if ( empty( $event['active'] ) || empty( $event['meta_enabled'] ) ) {
				continue;
			}

			if ( PMS_Settings::resolved_event_name( $event ) !== $form_event ) {
				continue;
			}

			$rule = self::normalize_for_compare( $event['match_value'] );

			if ( '' === $rule ) {
				continue;
			}

			foreach ( $filters as $filter ) {
				$needle = self::normalize_for_compare( $filter );

				if ( '' === $needle ) {
					continue;
				}

				if ( $rule === $needle || false !== strpos( $rule, $needle ) || false !== strpos( $needle, $rule ) ) {
					$conflicts[] = $event['match_value'];
					break;
				}
			}
		}

		return array_values( array_unique( $conflicts ) );
	}

	/**
	 * Pfad für den Vergleich vereinheitlichen (klein, ohne Slashes am Rand).
	 *
	 * @param string $path Pfad.
	 * @return string
	 */
	private static function normalize_for_compare( $path ) {
		return trim( strtolower( trim( (string) $path ) ), '/' );
	}

	/**
	 * Warnhinweis bei erkannter Doppelzählung ausgeben.
	 *
	 * @return void
	 */
	private static function render_conflict_notice() {
		$conflicts = self::detect_form_url_conflicts();

		if ( empty( $conflicts ) ) {
			return;
		}

		foreach ( $conflicts as $path ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: URL path that is covered by both a URL event and form tracking */
						__( 'Caution: For URL %s both a URL event and automatic form tracking are active. This can lead to duplicate counting.', 'pixel-made-simple' ),
						$path
					)
				)
			);
		}
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
				esc_attr( PMS_Settings::OPTION_SETTINGS . '[' . $key . ']' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pixel-made-simple' ) );
		}

		$support_email = 'dominik@seitzdominik.de';
		$video_url     = 'https://www.youtube.com/watch?v=PLATZHALTER'; // Platzhalter – hier später die Tutorial-URL eintragen.
		?>
		<div class="wrap pms-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Info & Help', 'pixel-made-simple' ); ?></h1>
			<hr class="wp-header-end" />

			<div class="pms-card">
				<h2><?php esc_html_e( 'Plugin Information', 'pixel-made-simple' ); ?></h2>
				<div class="pms-card-body">
					<p>
						<strong><?php esc_html_e( 'Pixel Made Simple', 'pixel-made-simple' ); ?></strong>
						<span class="pms-version-badge"><?php echo esc_html( 'v' . PMS_VERSION ); ?></span>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: support email address */
							esc_html__( 'If you have questions or found a bug, please email %s.', 'pixel-made-simple' ),
							'<a href="mailto:' . esc_attr( $support_email ) . '">' . esc_html( $support_email ) . '</a>'
						);
						?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: developer website link */
							esc_html__( 'Developed by %s.', 'pixel-made-simple' ),
							'<a href="https://sdv.design" target="_blank" rel="noopener noreferrer">Dominik Seitz – sdv.design</a>'
						);
						?>
					</p>
				</div>
			</div>

			<div class="pms-card">
				<h2><?php esc_html_e( 'Video Tutorial', 'pixel-made-simple' ); ?></h2>
				<div class="pms-card-body">
					<div class="pms-video-box">
						<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>
						<div>
							<p><?php esc_html_e( 'A step-by-step video tutorial on setting up the plugin is coming soon:', 'pixel-made-simple' ); ?></p>
							<p>
								<a class="button button-secondary" href="<?php echo esc_url( $video_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Watch the tutorial on YouTube', 'pixel-made-simple' ); ?>
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
		$s = PMS_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'pms_settings_group' ); ?>
			<?php
			// Einstellungen der anderen Tabs erhalten (sonst würden sie beim
			// Speichern dieses Formulars zurückgesetzt).
			$general_skip = array(
				'exclude_admins',
				'consent_detection',
				'pixel_enabled',
				'pixel_id',
				'capi_enabled',
				'capi_token',
				'test_event_code',
				'test_code_created_at',
				'hash_email',
				// tiktok_access_token wird -- wie capi_token oben -- IMMER
				// ausgenommen, unabhängig von is_pro() weiter unten: ein Secret
				// darf nie als Hidden-Feld im Seitenquelltext landen, auch nicht
				// auf einer Free-Installation mit einem Restwert aus einer
				// früheren Pro-Phase (siehe preserve_hidden_settings()-Doku).
				'tiktok_access_token',
			);
			if ( PMS_Settings::is_pro() ) {
				// Google/GA4/TikTok haben auf DIESEM Tab nur dann noch echte Felder,
				// wenn Pro tatsächlich rendert (siehe unten) -- in Free zeigt der
				// Tab stattdessen Teaser-Boxen ohne echte Felder; dort MÜSSEN diese
				// sieben Keys stattdessen ganz normal über preserve_hidden_settings()
				// erhalten bleiben (dasselbe Muster wie schon bei den UTM-Keys im
				// Tab "Erweitertes Tracking"), sonst würde das Speichern dieses
				// Formulars eine unter Pro bereits gesetzte Google/TikTok-
				// Konfiguration bei einem Downgrade auf Free stillschweigend auf
				// 0/leer zurücksetzen.
				$general_skip[] = 'google_enabled';
				$general_skip[] = 'google_tag_id';
				$general_skip[] = 'google_consent_mode';
				// ga4_measurement_id (seit v0.6.8) hat kein eigenes Bool-Toggle
				// (siehe PMS_Settings::get()-Doku), lebt aber im selben
				// Pro-only-Zweig wie google_tag_id direkt darüber -- dieselbe
				// Downgrade-Falle: ohne diesen Eintrag würde das Speichern
				// dieses Tabs auf Free eine unter Pro gesetzte GA4-ID leeren.
				$general_skip[] = 'ga4_measurement_id';
				$general_skip[] = 'tiktok_enabled';
				$general_skip[] = 'tiktok_pixel_id';
				$general_skip[] = 'tiktok_capi_enabled';
				// tiktok_access_token steht NICHT hier, sondern schon oben in der
				// unconditional-Liste (wie capi_token) -- ein Secret-Feld darf nie
				// als Hidden-Feld erscheinen, unabhängig vom Pro-Status.
			}
			// WooCommerce-/Purchase-Keys (wc_tracking_enabled, wc_content_id_type,
			// wc_purchase_value_type, wc_purchase_advanced_matching) haben auf
			// DIESEM Tab seit der Einführung des eigenen "E-Commerce"-Tabs nie
			// mehr ein echtes Feld -- sie bleiben deshalb IMMER über den
			// preserve_hidden_settings()-Default (nicht in $general_skip
			// gelistet = Hidden-Feld) erhalten, unabhängig von Pro/WooCommerce.
			self::preserve_hidden_settings( $s, $general_skip );
			?>

			<h2 class="pms-section-title"><?php esc_html_e( 'Global Options', 'pixel-made-simple' ); ?></h2>

			<?php
			self::accordion_open(
				__( 'Do not track administrators', 'pixel-made-simple' ),
				'pms_settings[exclude_admins]',
				! empty( $s['exclude_admins'] ),
				__( 'Do not track administrators', 'pixel-made-simple' ),
				'exclude_admins'
			);
			?>
			<p class="description"><?php esc_html_e( 'Recommended: logged-in administrators trigger neither pixel nor server events.', 'pixel-made-simple' ); ?></p>
			<?php self::accordion_close(); ?>

			<?php
			self::accordion_open(
				__( 'Automatic cookie banner detection (GDPR)', 'pixel-made-simple' ),
				'pms_settings[consent_detection]',
				! empty( $s['consent_detection'] ),
				__( 'Enable automatic cookie banner detection', 'pixel-made-simple' ),
				'consent_detection'
			);
			?>
			<p class="description"><?php esc_html_e( 'Automatically detects installed cookie banners and blocks browser and CAPI events until consent is given. Supports Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies and any banner using the WP Consent API. Automatic blocking cannot be guaranteed for unlisted third-party banners. Sites without a cookie banner are never blocked.', 'pixel-made-simple' ); ?></p>
			<?php self::accordion_close(); ?>

			<h2 class="pms-section-title"><?php esc_html_e( 'Platforms', 'pixel-made-simple' ); ?></h2>

			<?php self::accordion_open( __( 'Meta (Facebook)', 'pixel-made-simple' ), 'pms_settings[pixel_enabled]', ! empty( $s['pixel_enabled'] ), __( 'Enable Meta tracking', 'pixel-made-simple' ), 'pixel_enabled' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pms-pixel-id"><?php esc_html_e( 'Meta Pixel ID', 'pixel-made-simple' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Data sources → your pixel → copy the ID shown below its name.', 'pixel-made-simple' ) ); ?>
					</th>
					<td>
						<input type="text" id="pms-pixel-id" class="regular-text code" inputmode="numeric"
							name="pms_settings[pixel_id]" value="<?php echo esc_attr( $s['pixel_id'] ); ?>"
							placeholder="1234567890123456" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Conversions API', 'pixel-made-simple' ); ?></th>
					<td>
						<?php self::toggle( 'pms_settings[capi_enabled]', ! empty( $s['capi_enabled'] ), __( 'Enable Conversions API', 'pixel-made-simple' ), false, 'capi_enabled' ); ?>
						<p class="description"><?php esc_html_e( 'Additionally sends matched events to Meta server-side – deduplicated via the same event ID as in the browser.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pms-capi-token"><?php esc_html_e( 'CAPI Access Token', 'pixel-made-simple' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Data sources → Settings → Conversions API → “Generate access token”.', 'pixel-made-simple' ) ); ?>
					</th>
					<td>
						<textarea id="pms-capi-token" class="large-text code" rows="4" spellcheck="false" autocomplete="off"
							name="pms_settings[capi_token]"><?php echo esc_textarea( $s['capi_token'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Secret key. Used exclusively server-side and never rendered in the frontend.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pms-test-code"><?php esc_html_e( 'Test Event Code', 'pixel-made-simple' ); ?></label>
						<?php self::tip( __( 'Meta Events Manager → Test events → copy the “Server events code”. Clear it again after testing!', 'pixel-made-simple' ) ); ?>
					</th>
					<td>
						<input type="text" id="pms-test-code" class="regular-text code"
							name="pms_settings[test_event_code]" value="<?php echo esc_attr( $s['test_event_code'] ); ?>"
							placeholder="TEST12345" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'The test code is automatically deactivated after 12 hours to prevent accidental test tracking on a live site.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Advanced Matching (email)', 'pixel-made-simple' ); ?></th>
					<td>
						<?php self::toggle( 'pms_settings[hash_email]', ! empty( $s['hash_email'] ), __( 'Enable Advanced Matching', 'pixel-made-simple' ), false, 'hash_email' ); ?>
						<p class="description"><?php esc_html_e( 'Sends the email address of logged-in users to the Conversions API as a SHA-256 hash (better match quality). Mind data privacy.', 'pixel-made-simple' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::accordion_close(); ?>

			<?php if ( PMS_Settings::is_pro() ) : ?>
				<?php self::accordion_open( __( 'Google Ads', 'pixel-made-simple' ), 'pms_settings[google_enabled]', ! empty( $s['google_enabled'] ), __( 'Enable Google Ads tracking', 'pixel-made-simple' ), 'google_enabled' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pms-google-tag-id"><?php esc_html_e( 'Google Tag ID', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'Google Ads → Tools & Settings → Google Tag → copy the ID (AW-XXXXX).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<input type="text" id="pms-google-tag-id" class="regular-text code"
								name="pms_settings[google_tag_id]" value="<?php echo esc_attr( $s['google_tag_id'] ); ?>"
								placeholder="AW-123456789" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Google Consent Mode v2 (default)', 'pixel-made-simple' ); ?></th>
						<td>
							<?php self::toggle( 'pms_settings[google_consent_mode]', ! empty( $s['google_consent_mode'] ), __( 'Enable Google Consent Mode v2 defaults', 'pixel-made-simple' ), false, 'google_consent_mode' ); ?>
							<p class="description"><?php esc_html_e( 'Sets ad_storage, ad_user_data, ad_personalization and analytics_storage to "denied" by default before the tag loads. Your consent banner then sends the consent update. Recommended for the EU.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pms-ga4-measurement-id"><?php esc_html_e( 'GA4 Measurement ID', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'Google Analytics → Admin → Data streams → your web stream → copy the Measurement ID.', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<input type="text" id="pms-ga4-measurement-id" class="regular-text code"
								name="pms_settings[ga4_measurement_id]" value="<?php echo esc_attr( $s['ga4_measurement_id'] ); ?>"
								placeholder="G-XXXXXXXXXX" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Optional, independent of Google Ads above. Reuses the same gtag.js loader – view_item, add_to_cart, begin_checkout and purchase from WooCommerce/SureCart tracking are picked up automatically once set.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
				</table>
				<?php self::accordion_close(); ?>

				<?php self::accordion_open( __( 'TikTok', 'pixel-made-simple' ), 'pms_settings[tiktok_enabled]', ! empty( $s['tiktok_enabled'] ), __( 'Enable TikTok Pixel', 'pixel-made-simple' ), 'tiktok_enabled' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pms-tiktok-pixel-id"><?php esc_html_e( 'TikTok Pixel ID', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'TikTok Ads Manager → Assets → Events → Web events → copy the pixel ID.', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<input type="text" id="pms-tiktok-pixel-id" class="regular-text code"
								name="pms_settings[tiktok_pixel_id]" value="<?php echo esc_attr( $s['tiktok_pixel_id'] ); ?>"
								placeholder="C1234567890ABCDEFG" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Events API', 'pixel-made-simple' ); ?></th>
						<td>
							<?php self::toggle( 'pms_settings[tiktok_capi_enabled]', ! empty( $s['tiktok_capi_enabled'] ), __( 'Enable TikTok Events API', 'pixel-made-simple' ), false, 'tiktok_capi_enabled' ); ?>
							<p class="description"><?php esc_html_e( 'Additionally sends matched events to TikTok server-side. Currently used for WooCommerce Purchase tracking only (tab “E-Commerce”), deduplicated via the same event ID as in the browser.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pms-tiktok-access-token"><?php esc_html_e( 'Events API Access Token', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'TikTok Events Manager → your pixel → Settings → Events API → Generate access token.', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<textarea id="pms-tiktok-access-token" class="large-text code" rows="4" spellcheck="false" autocomplete="off"
								name="pms_settings[tiktok_access_token]"><?php echo esc_textarea( $s['tiktok_access_token'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Secret key. Used exclusively server-side and never rendered in the frontend.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
				</table>
				<?php self::accordion_close(); ?>
			<?php else : ?>
				<?php
				self::render_pro_teaser_box(
					__( 'Google Ads', 'pixel-made-simple' ),
					__( 'Track conversions with Google Ads and GA4 (gtag.js), including Google Consent Mode v2 defaults. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
					'google-ads'
				);
				self::render_pro_teaser_box(
					__( 'TikTok', 'pixel-made-simple' ),
					__( 'Track conversions with the TikTok Pixel using the official web events. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
					'tiktok'
				);
				?>
			<?php endif; ?>

			<?php submit_button( __( 'Save Settings', 'pixel-made-simple' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Tab: E-Commerce. Bündelt WooCommerce-Tracking (ViewContent/AddToCart/
	 * InitiateCheckout, seit v0.6.4) und Purchase-Tracking an einem Ort,
	 * statt sie über Tab "Allgemein" zu verstreuen (siehe dortige Doku zur
	 * Verschiebung). Seit v0.6.7 zusätzlich eine zweite, unabhängige
	 * SureCart-Box nach demselben Muster (siehe pro/class-pro-surecart.php)
	 * -- beide Plattform-Boxen haben ihren eigenen Drei-Wege-Zweig (nicht
	 * erkannt / Pro+erkannt / Free) und können unabhängig voneinander
	 * sichtbar sein, falls eine Installation ausnahmsweise beide
	 * E-Commerce-Plugins gleichzeitig einsetzt.
	 *
	 * Seit v0.6.5 IMMER Teil der Tab-Leiste, auch ohne WooCommerce (vorher
	 * fehlte der Tab auf Nicht-WooCommerce-Sites komplett, siehe render_page()) --
	 * ohne WooCommerce rendert diese Methode stattdessen einen reinen
	 * Hinweis-Card statt der Accordion/des Teasers, siehe erste Verzweigung
	 * unten. Dieselbe Regel gilt seit v0.6.7 unabhängig für SureCart.
	 *
	 * @return void
	 */
	private static function render_ecommerce_tab() {
		$s = PMS_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'pms_settings_group' ); ?>
			<?php
			// capi_token/tiktok_access_token haben auf DIESEM Tab nie ein echtes
			// Feld -- trotzdem unconditional (nicht nur im is_pro()-Zweig unten)
			// von preserve_hidden_settings() ausgenommen, exakt dasselbe
			// Exposure-Minimierungs-Prinzip wie in render_general_tab() und
			// render_advanced_tab(): ein Secret darf nie als Hidden-Feld im
			// Seitenquelltext eines FREMDEN Tabs auftauchen.
			$ecommerce_skip = array( 'capi_token', 'tiktok_access_token' );
			// Die vier wc_*-Keys (und seit v0.6.6 wc_google_conversion_label)
			// haben echte Felder nur, wenn Pro UND WooCommerce beide zutreffen
			// (siehe unten) -- in jedem anderen Fall (Free, oder Pro ohne
			// WooCommerce) zeigt dieser Tab stattdessen einen Teaser bzw.
			// Hinweis-Card ohne echte Felder; dort MÜSSEN diese Keys
			// stattdessen ganz normal über preserve_hidden_settings() erhalten
			// bleiben (dasselbe Muster wie überall sonst in diesem Plugin),
			// sonst würde das Speichern dieses Formulars eine bereits gesetzte
			// WooCommerce-/Purchase-Konfiguration bei einem Downgrade auf Free
			// ODER bei einer (vorübergehenden) WooCommerce-Deaktivierung
			// stillschweigend auf 0/leer zurücksetzen.
			if ( PMS_Settings::is_pro() && class_exists( 'WooCommerce' ) ) {
				$ecommerce_skip[] = 'wc_tracking_enabled';
				$ecommerce_skip[] = 'wc_content_id_type';
				$ecommerce_skip[] = 'wc_purchase_value_type';
				$ecommerce_skip[] = 'wc_purchase_advanced_matching';
				$ecommerce_skip[] = 'wc_google_conversion_label';
			}
			// SureCart -- zweite E-Commerce-Integration, eigener
			// Aktivitäts-Guard (class_exists('SureCart') ||
			// function_exists('surecart'), siehe PMS_Pro_SureCart::active()),
			// unabhängig davon, ob WooCommerce oben zutrifft. Dieselbe
			// Downgrade-/Deaktivierungs-Falle wie bei WooCommerce: die fünf
			// sc_*-Keys dürfen nur dann aus dem Skip-Array raus, wenn diese
			// Bedingung unten TATSÄCHLICH die echte Accordion statt eines
			// Hinweis-/Teaser-Zweigs rendert.
			if ( PMS_Settings::is_pro() && ( class_exists( 'SureCart' ) || function_exists( 'surecart' ) ) ) {
				$ecommerce_skip[] = 'sc_tracking_enabled';
				$ecommerce_skip[] = 'sc_content_id_type';
				$ecommerce_skip[] = 'sc_purchase_value_type';
				$ecommerce_skip[] = 'sc_purchase_advanced_matching';
				$ecommerce_skip[] = 'sc_google_conversion_label';
			}
			self::preserve_hidden_settings( $s, $ecommerce_skip );
			?>

			<h2 class="pms-section-title"><?php esc_html_e( 'E-Commerce', 'pixel-made-simple' ); ?></h2>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="pms-card">
					<div class="pms-card-body">
						<p><?php esc_html_e( 'WooCommerce was not detected on this site. Once WooCommerce is activated, the tracking options will appear here.', 'pixel-made-simple' ); ?></p>
					</div>
				</div>
			<?php elseif ( PMS_Settings::is_pro() ) : ?>
				<?php self::accordion_open( __( 'WooCommerce', 'pixel-made-simple' ), 'pms_settings[wc_tracking_enabled]', ! empty( $s['wc_tracking_enabled'] ), __( 'Enable WooCommerce tracking', 'pixel-made-simple' ), 'wc_tracking_enabled' ); ?>
				<p class="description"><?php esc_html_e( 'Automatically tracks ViewContent, AddToCart, InitiateCheckout and Purchase for WooCommerce, deduplicated via the same event ID as in the browser. Purchase additionally uses a server-side fallback for orders completed via external payment gateways that skip the order-received page.', 'pixel-made-simple' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Product identifier', 'pixel-made-simple' ); ?>
							<?php self::tip( __( 'Must match how your Meta catalog identifies products (content_id).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<select name="pms_settings[wc_content_id_type]">
								<option value="id" <?php selected( $s['wc_content_id_type'], 'id' ); ?>><?php esc_html_e( 'Product ID', 'pixel-made-simple' ); ?></option>
								<option value="sku" <?php selected( $s['wc_content_id_type'], 'sku' ); ?>><?php esc_html_e( 'SKU (falls back to Product ID when empty)', 'pixel-made-simple' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Purchase value', 'pixel-made-simple' ); ?>
							<?php self::tip( __( 'Whether the Purchase event value includes tax (gross, the amount actually paid) or excludes it (net).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<select name="pms_settings[wc_purchase_value_type]">
								<option value="gross" <?php selected( $s['wc_purchase_value_type'], 'gross' ); ?>><?php esc_html_e( 'Gross (incl. tax)', 'pixel-made-simple' ); ?></option>
								<option value="net" <?php selected( $s['wc_purchase_value_type'], 'net' ); ?>><?php esc_html_e( 'Net (excl. tax)', 'pixel-made-simple' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purchase Advanced Matching', 'pixel-made-simple' ); ?></th>
						<td>
							<?php self::toggle( 'pms_settings[wc_purchase_advanced_matching]', ! empty( $s['wc_purchase_advanced_matching'] ), __( 'Enable Purchase Advanced Matching', 'pixel-made-simple' ), false, 'wc_purchase_advanced_matching' ); ?>
							<p class="description"><?php esc_html_e( 'Sends hashed billing details from the order (email, phone, name, address) to the Conversions API for better match quality. Mind data privacy.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pms-wc-google-conversion-label"><?php esc_html_e( 'Google Ads conversion label (Purchase)', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'Optional. Google Ads → Conversions → your Purchase action → "Use tag" → the part after the slash in send_to. Leave empty to skip the Google Ads Purchase conversion (ViewContent/AddToCart/InitiateCheckout are not affected).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<input type="text" id="pms-wc-google-conversion-label" class="regular-text" name="pms_settings[wc_google_conversion_label]" value="<?php echo esc_attr( $s['wc_google_conversion_label'] ); ?>" placeholder="AbCdEfGhIjKlMnOp" />
						</td>
					</tr>
				</table>
				<?php self::accordion_close(); ?>
			<?php else : ?>
				<?php
				self::render_pro_teaser_box(
					__( 'WooCommerce', 'pixel-made-simple' ),
					__( 'Automatically track ViewContent, AddToCart, InitiateCheckout and Purchase for WooCommerce — deduplicated via the same event ID as in the browser, with a server-side fallback for orders completed via external payment gateways. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
					'woocommerce'
				);
				?>
			<?php endif; ?>

			<?php
			// SureCart -- zweite E-Commerce-Integration, unabhängiger
			// Drei-Wege-Zweig (nicht erkannt / Pro+erkannt / Free), exakt
			// dasselbe Muster wie die WooCommerce-Box direkt darüber, siehe
			// dortige Kommentare für die Begründung.
			?>
			<?php if ( ! class_exists( 'SureCart' ) && ! function_exists( 'surecart' ) ) : ?>
				<div class="pms-card">
					<div class="pms-card-body">
						<p><?php esc_html_e( 'SureCart was not detected on this site. Once SureCart is activated, the tracking options will appear here.', 'pixel-made-simple' ); ?></p>
					</div>
				</div>
			<?php elseif ( PMS_Settings::is_pro() ) : ?>
				<?php self::accordion_open( __( 'SureCart', 'pixel-made-simple' ), 'pms_settings[sc_tracking_enabled]', ! empty( $s['sc_tracking_enabled'] ), __( 'Enable SureCart tracking', 'pixel-made-simple' ), 'sc_tracking_enabled' ); ?>
				<p class="description"><?php esc_html_e( 'Automatically tracks ViewContent, AddToCart, InitiateCheckout and Purchase for SureCart, deduplicated via the same event ID as in the browser. Purchase additionally uses a server-side fallback for orders that reach a paid status outside the regular checkout confirmation (e.g. asynchronous payment methods).', 'pixel-made-simple' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Product identifier', 'pixel-made-simple' ); ?>
							<?php self::tip( __( 'Must match how your Meta catalog identifies products (content_id).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<select name="pms_settings[sc_content_id_type]">
								<option value="id" <?php selected( $s['sc_content_id_type'], 'id' ); ?>><?php esc_html_e( 'Product ID', 'pixel-made-simple' ); ?></option>
								<option value="sku" <?php selected( $s['sc_content_id_type'], 'sku' ); ?>><?php esc_html_e( 'SKU (falls back to Product ID when empty)', 'pixel-made-simple' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Purchase value', 'pixel-made-simple' ); ?>
							<?php self::tip( __( 'Whether the Purchase event value includes tax (gross, the amount actually paid) or excludes it (net).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<select name="pms_settings[sc_purchase_value_type]">
								<option value="gross" <?php selected( $s['sc_purchase_value_type'], 'gross' ); ?>><?php esc_html_e( 'Gross (incl. tax)', 'pixel-made-simple' ); ?></option>
								<option value="net" <?php selected( $s['sc_purchase_value_type'], 'net' ); ?>><?php esc_html_e( 'Net (excl. tax)', 'pixel-made-simple' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purchase Advanced Matching', 'pixel-made-simple' ); ?></th>
						<td>
							<?php self::toggle( 'pms_settings[sc_purchase_advanced_matching]', ! empty( $s['sc_purchase_advanced_matching'] ), __( 'Enable Purchase Advanced Matching', 'pixel-made-simple' ), false, 'sc_purchase_advanced_matching' ); ?>
							<p class="description"><?php esc_html_e( 'Sends hashed billing details from the checkout (email, phone, name, address) to the Conversions API for better match quality. Mind data privacy.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pms-sc-google-conversion-label"><?php esc_html_e( 'Google Ads conversion label (Purchase)', 'pixel-made-simple' ); ?></label>
							<?php self::tip( __( 'Optional. Google Ads → Conversions → your Purchase action → "Use tag" → the part after the slash in send_to. Leave empty to skip the Google Ads Purchase conversion (ViewContent/AddToCart/InitiateCheckout are not affected).', 'pixel-made-simple' ) ); ?>
						</th>
						<td>
							<input type="text" id="pms-sc-google-conversion-label" class="regular-text" name="pms_settings[sc_google_conversion_label]" value="<?php echo esc_attr( $s['sc_google_conversion_label'] ); ?>" placeholder="AbCdEfGhIjKlMnOp" />
						</td>
					</tr>
				</table>
				<?php self::accordion_close(); ?>
			<?php else : ?>
				<?php
				self::render_pro_teaser_box(
					__( 'SureCart', 'pixel-made-simple' ),
					__( 'Automatically track ViewContent, AddToCart, InitiateCheckout and Purchase for SureCart — deduplicated via the same event ID as in the browser, with a server-side fallback for orders that reach a paid status outside the regular checkout confirmation. Available in Pixel Made Simple Pro.', 'pixel-made-simple' ),
					'surecart'
				);
				?>
			<?php endif; ?>

			<?php submit_button( __( 'Save Settings', 'pixel-made-simple' ) ); ?>
		</form>
		<?php
	}

	private static function render_events_tab() {
		$events         = PMS_Settings::get_events();
		$events_enabled = PMS_Settings::events_enabled();
		$edit_event     = null;

		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur lesende Vorauswahl; alle Schreibaktionen sind nonce-gesichert.
			$edit_id = sanitize_key( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $events[ $edit_id ] ) ) {
				$edit_event = $events[ $edit_id ];
			}
		}

		$admin_post = admin_url( 'admin-post.php' );
		?>

		<h2 class="pms-section-title"><?php esc_html_e( 'Custom Events', 'pixel-made-simple' ); ?></h2>

		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Note: Use URL events ideally for thank-you and confirmation pages (e.g. /danke/, /kauf-erfolgreich/). For regular forms without a redirect, please use the automatic form tracking in tab “Advanced Tracking” to avoid duplicate counting.', 'pixel-made-simple' ); ?></p>
		</div>

		<?php self::render_conflict_notice(); ?>

		<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="pms-global-toggle-form">
			<input type="hidden" name="action" value="pms_toggle_all_events" />
			<?php wp_nonce_field( 'pms_toggle_all_events' ); ?>
			<?php self::toggle( 'events_enabled', $events_enabled, __( 'Enable all custom events', 'pixel-made-simple' ), true ); ?>
			<span class="pms-global-toggle-label">
				<?php esc_html_e( 'Enable/disable all custom events', 'pixel-made-simple' ); ?>
			</span>
			<noscript><?php submit_button( __( 'Apply', 'pixel-made-simple' ), 'secondary small', '', false ); ?></noscript>
		</form>

		<?php if ( ! $events_enabled ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Custom events are currently disabled globally. The rules below will not fire.', 'pixel-made-simple' ); ?></p></div>
		<?php endif; ?>

		<?php if ( PMS_Settings::free_event_limit_reached() ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'The free version includes up to 2 URL events.', 'pixel-made-simple' ); ?>
					<a href="<?php echo esc_url( self::upgrade_url( 'events-limit' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Pro for unlimited events.', 'pixel-made-simple' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<table class="widefat striped pms-events-table">
			<thead>
				<tr>
					<th scope="col" class="pms-col-status"><?php esc_html_e( 'Status', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Platforms', 'pixel-made-simple' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trigger Condition (URL)', 'pixel-made-simple' ); ?></th>
					<th scope="col" class="pms-col-actions"><?php esc_html_e( 'Actions', 'pixel-made-simple' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No events yet. Create your first event below.', 'pixel-made-simple' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td class="pms-col-status">
								<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
									<input type="hidden" name="action" value="pms_toggle_event" />
									<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>" />
									<?php wp_nonce_field( 'pms_toggle_event_' . $event['id'] ); ?>
									<?php
									self::toggle(
										'active',
										! empty( $event['active'] ),
										/* translators: %s: event name */
										sprintf( __( 'Enable event “%s”', 'pixel-made-simple' ), $event['name'] ),
										true
									);
									?>
									<noscript><button type="submit" class="button-link"><?php esc_html_e( 'Toggle', 'pixel-made-simple' ); ?></button></noscript>
								</form>
							</td>
							<td><strong><?php echo esc_html( $event['name'] ); ?></strong></td>
							<td class="pms-col-platforms">
								<?php if ( ! empty( $event['meta_enabled'] ) ) : ?>
									<span class="pms-badge pms-badge-meta">Meta · <?php echo esc_html( $event['event_type'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $event['google_enabled'] ) ) : ?>
									<span class="pms-badge pms-badge-google">Google Ads · <?php echo esc_html( $event['google_label'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $event['tiktok_enabled'] ) ) : ?>
									<span class="pms-badge pms-badge-tiktok">TikTok · <?php echo esc_html( $event['tiktok_event'] ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo esc_html(
									'exact' === $event['match_type']
										? __( 'Exact path:', 'pixel-made-simple' )
										: __( 'URL contains:', 'pixel-made-simple' )
								);
								?>
								<code><?php echo esc_html( $event['match_value'] ); ?></code>
							</td>
							<td class="pms-col-actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'events', 'edit' => $event['id'] ), admin_url( 'admin.php' ) ) . '#pms-event-form' ); ?>">
									<?php esc_html_e( 'Edit', 'pixel-made-simple' ); ?>
								</a>
								<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="pms-inline-form">
									<input type="hidden" name="action" value="pms_delete_event" />
									<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>" />
									<?php wp_nonce_field( 'pms_delete_event_' . $event['id'] ); ?>
									<button type="submit" class="button button-small pms-delete-button"
										data-pms-confirm="<?php esc_attr_e( 'Really delete this event?', 'pixel-made-simple' ); ?>">
										<?php esc_html_e( 'Delete', 'pixel-made-simple' ); ?>
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
		<div class="pms-event-form-card" id="pms-event-form">
			<h2>
				<?php
				if ( $is_edit ) {
					/* translators: %s: event name */
					echo esc_html( sprintf( __( 'Edit event “%s”', 'pixel-made-simple' ), $values['name'] ) );
				} else {
					esc_html_e( 'Create New Event', 'pixel-made-simple' );
				}
				?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pms_save_event" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $values['id'] ); ?>" />
				<?php wp_nonce_field( 'pms_save_event' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pms-event-name"><?php esc_html_e( 'Event Name (internal)', 'pixel-made-simple' ); ?></label></th>
						<td>
							<input type="text" id="pms-event-name" class="regular-text" name="name" required maxlength="50"
								value="<?php echo esc_attr( $values['name'] ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Lead magnet confirmation', 'pixel-made-simple' ); ?>" />
							<p class="description pms-custom-event-hint" hidden>
								<?php esc_html_e( 'With the “CustomEvent” type, this name is sent as the custom event name.', 'pixel-made-simple' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pms-match-type"><?php esc_html_e( 'Trigger', 'pixel-made-simple' ); ?></label></th>
						<td>
							<select id="pms-match-type" name="match_type">
								<option value="exact" <?php selected( $values['match_type'], 'exact' ); ?>><?php esc_html_e( 'Exact path', 'pixel-made-simple' ); ?></option>
								<option value="contains" <?php selected( $values['match_type'], 'contains' ); ?>><?php esc_html_e( 'URL contains', 'pixel-made-simple' ); ?></option>
							</select>
							<input type="text" id="pms-match-value" class="regular-text code" name="match_value" required
								value="<?php echo esc_attr( $values['match_value'] ); ?>"
								placeholder="/bestaetigung/" />
							<p class="description"><?php esc_html_e( '“Exact path” compares only the URL path (case and trailing slash are ignored). “URL contains” checks the path including the query string.', 'pixel-made-simple' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Platforms', 'pixel-made-simple' ); ?></th>
						<td class="pms-platform-rows">
							<div class="pms-platform-row">
								<label class="pms-platform-check">
									<input type="checkbox" name="platform_meta" value="1" <?php checked( ! empty( $values['meta_enabled'] ) ); ?> />
									<strong>Meta</strong>
								</label>
								<select id="pms-event-type" name="event_type" aria-label="<?php esc_attr_e( 'Meta event type', 'pixel-made-simple' ); ?>">
									<?php foreach ( PMS_Settings::meta_event_types() as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['event_type'], $type ); ?>>
											<?php echo esc_html( $type ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="pms-platform-row">
								<label class="pms-platform-check">
									<input type="checkbox" name="platform_google" value="1" <?php checked( ! empty( $values['google_enabled'] ) ); ?> />
									<strong>Google Ads</strong>
								</label>
								<input type="text" class="regular-text code" name="google_label"
									value="<?php echo esc_attr( $values['google_label'] ); ?>"
									placeholder="AbCdEfGhIjK123"
									aria-label="<?php esc_attr_e( 'Google Ads conversion label', 'pixel-made-simple' ); ?>" />
								<?php self::tip( __( 'Google Ads → Conversion goals → click the action → Tag setup → snippet → copy the label after the slash (AW-XXX/LABEL).', 'pixel-made-simple' ) ); ?>
							</div>
							<div class="pms-platform-row">
								<label class="pms-platform-check">
									<input type="checkbox" name="platform_tiktok" value="1" <?php checked( ! empty( $values['tiktok_enabled'] ) ); ?> />
									<strong>TikTok</strong>
								</label>
								<select id="pms-tiktok-event" name="tiktok_event" aria-label="<?php esc_attr_e( 'TikTok event type', 'pixel-made-simple' ); ?>">
									<?php foreach ( PMS_Settings::tiktok_event_types() as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['tiktok_event'], $type ); ?>>
											<?php echo esc_html( $type ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'pixel-made-simple' ); ?></th>
						<td>
							<?php self::toggle( 'active', ! empty( $values['active'] ), __( 'Event active', 'pixel-made-simple' ) ); ?>
							<span class="pms-global-toggle-label"><?php esc_html_e( 'Event active', 'pixel-made-simple' ); ?></span>
						</td>
					</tr>
				</table>

				<?php
				// Nur das ANLEGEN eines weiteren Events ist in Free begrenzt --
				// Bearbeiten eines bestehenden Events ändert die Gesamtzahl nicht
				// und bleibt deshalb immer möglich (siehe
				// PMS_Settings::free_event_limit_reached()-Doku).
				$limit_blocks_new = ! $is_edit && PMS_Settings::free_event_limit_reached();
				?>
				<p class="submit">
					<?php if ( $limit_blocks_new ) : ?>
						<button type="submit" class="button button-primary" disabled="disabled"><?php esc_html_e( 'Add Event', 'pixel-made-simple' ); ?></button>
					<?php else : ?>
						<?php
						submit_button(
							$is_edit ? __( 'Update Event', 'pixel-made-simple' ) : __( 'Add Event', 'pixel-made-simple' ),
							'primary',
							'submit',
							false
						);
						?>
					<?php endif; ?>
					<?php if ( $is_edit ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'events' ), admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'Cancel', 'pixel-made-simple' ); ?>
						</a>
					<?php endif; ?>
				</p>
				<?php if ( $limit_blocks_new ) : ?>
					<p class="description">
						<?php esc_html_e( 'The free version includes up to 2 URL events.', 'pixel-made-simple' ); ?>
						<a href="<?php echo esc_url( self::upgrade_url( 'events-limit' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Pro for unlimited events.', 'pixel-made-simple' ); ?></a>
					</p>
				<?php endif; ?>
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
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixel-made-simple' ), '', array( 'response' => 403 ) );
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
					'pms_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_event() {
		self::require_capability();
		check_admin_referer( 'pms_save_event' );

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		$is_new   = ( '' === $event_id );

		// Defense-in-depth: die Admin-UI zeigt für ein neues Event bereits einen
		// deaktivierten "Event hinzufügen"-Button, sobald das Free-Limit erreicht
		// ist (siehe render_event_form()). Bearbeiten eines bestehenden Events
		// ändert die Gesamtzahl nicht und ist deshalb nie betroffen.
		if ( $is_new && PMS_Settings::free_event_limit_reached() ) {
			self::redirect_events( 'free_limit_reached' );
		}

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

		$event = PMS_Settings::sanitize_event(
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

		$events = PMS_Settings::get_events();

		if ( ! $is_new && ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		$events[ $event_id ] = $event;
		PMS_Settings::save_events( $events );

		self::redirect_events( 'saved' );
	}

	public static function handle_delete_event() {
		self::require_capability();

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		check_admin_referer( 'pms_delete_event_' . $event_id );

		$events = PMS_Settings::get_events();

		if ( ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		unset( $events[ $event_id ] );
		PMS_Settings::save_events( $events );

		self::redirect_events( 'deleted' );
	}

	public static function handle_toggle_event() {
		self::require_capability();

		$event_id = sanitize_key( wp_unslash( $_POST['event_id'] ?? '' ) );
		check_admin_referer( 'pms_toggle_event_' . $event_id );

		$events = PMS_Settings::get_events();

		if ( ! isset( $events[ $event_id ] ) ) {
			self::redirect_events( 'not_found' );
		}

		// Free-Limit betrifft nur das ANLEGEN eines neuen Events (siehe
		// handle_save_event()) -- Umschalten eines bestehenden Events ändert die
		// Gesamtzahl nicht und ist deshalb nie eingeschränkt.
		$events[ $event_id ]['active'] = empty( $_POST['active'] ) ? 0 : 1;
		PMS_Settings::save_events( $events );

		self::redirect_events( 'toggled' );
	}

	public static function handle_toggle_all_events() {
		self::require_capability();
		check_admin_referer( 'pms_toggle_all_events' );

		update_option( PMS_Settings::OPTION_EVENTS_ENABLED, empty( $_POST['events_enabled'] ) ? 0 : 1, false );

		self::redirect_events( 'global_toggled' );
	}
}
