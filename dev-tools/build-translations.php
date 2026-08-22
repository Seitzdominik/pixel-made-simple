<?php
/**
 * i18n-Toolchain für Pixel Made Simple:
 * 1. Extrahiert alle gettext-Strings (mit file:line-Referenzen und translators-Kommentaren)
 * 2. Schreibt languages/<domain>.pot
 * 3. Schreibt languages/<domain>-de_DE.po (Du-Form)
 * 4. Validiert: alles übersetzt, Platzhalter identisch, keine Karteileichen
 * 5. Kompiliert languages/<domain>-de_DE.mo (kein msgfmt nötig) + Gegenprobe
 *
 * Ausführen:  & "C:\php\php.exe" dev-tools\build-translations.php
 *
 * Bei einer NEUEN __()/esc_html__()/... Zeichenkette im Plugin-Code:
 * Übersetzung unten im $de-Array ergänzen, sonst bricht die Validierung mit
 * "FEHLENDE ÜBERSETZUNG" ab. Wird ein String aus dem Code entfernt, meldet
 * der Validator "VERWAISTE ÜBERSETZUNG" – dann die Zeile hier ebenfalls löschen.
 */

error_reporting( E_ALL );

$plugin_dir = __DIR__ . '/../src';
$domain     = 'pixel-made-simple';
$lang_dir   = $plugin_dir . '/languages';

if ( ! is_dir( $lang_dir ) ) {
	mkdir( $lang_dir, 0777, true );
}

/* ---------------------------------------------------------------
 * 1. Strings extrahieren
 * ------------------------------------------------------------- */

$entries = array(); // msgid => [ 'refs' => [], 'comment' => '' ]

function add_entry( &$entries, $msgid, $ref = null, $comment = '' ) {
	if ( ! isset( $entries[ $msgid ] ) ) {
		$entries[ $msgid ] = array( 'refs' => array(), 'comment' => '' );
	}
	if ( $ref ) {
		$entries[ $msgid ]['refs'][] = $ref;
	}
	if ( $comment && '' === $entries[ $msgid ]['comment'] ) {
		$entries[ $msgid ]['comment'] = $comment;
	}
}

// Plugin-Header (wie wp i18n make-pot)
$main_file = $plugin_dir . '/pixel-made-simple.php';
$main      = file_get_contents( $main_file );
$headers   = array(
	'Plugin Name' => 'Plugin Name of the plugin',
	'Plugin URI'  => 'Plugin URI of the plugin',
	'Description' => 'Description of the plugin',
	'Author'      => 'Author of the plugin',
	'Author URI'  => 'Author URI of the plugin',
);
foreach ( $headers as $header => $comment ) {
	if ( preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $main, $m ) ) {
		add_entry( $entries, trim( $m[1] ), 'pixel-made-simple.php', '#. ' . $comment );
	}
}

// Gettext-Aufrufe in allen PHP-Dateien
$php_files = array();
$iter      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	if ( 'php' === strtolower( $file->getExtension() ) ) {
		$php_files[] = str_replace( '\\', '/', $file->getPathname() );
	}
}
sort( $php_files );

$call_regex = '/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'' . preg_quote( $domain, '/' ) . '\'\s*\)/';

foreach ( $php_files as $path ) {
	$rel   = ltrim( str_replace( $plugin_dir, '', $path ), '/' );
	$lines = file( $path );
	foreach ( $lines as $i => $line ) {
		if ( ! preg_match_all( $call_regex, $line, $matches ) ) {
			continue;
		}
		// translators-Kommentar in den 2 Zeilen davor suchen
		$comment = '';
		for ( $back = 1; $back <= 2; $back++ ) {
			if ( isset( $lines[ $i - $back ] ) && preg_match( '/\/\*\s*(translators:.*?)\*\//', $lines[ $i - $back ], $cm ) ) {
				$comment = '#. ' . trim( $cm[1] );
				break;
			}
		}
		foreach ( $matches[1] as $raw ) {
			$msgid = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $raw );
			add_entry( $entries, $msgid, $rel . ':' . ( $i + 1 ), $comment );
		}
	}
}

echo 'Extrahiert: ' . count( $entries ) . " eindeutige Strings\n";

/* ---------------------------------------------------------------
 * 2. Deutsche Übersetzungen (Du-Form)
 * ------------------------------------------------------------- */

$de = array(
	// Plugin-Header
	'Pixel Made Simple' => 'Pixel Made Simple',
	'https://sdv.design' => 'https://sdv.design',
	'https://pixelmadesimple.com' => 'https://pixelmadesimple.com',
	'Pixel Made Simple Pro is already active. The free version is not needed and has been deactivated automatically.' => 'Pixel Made Simple Pro ist bereits aktiv. Die kostenlose Version wird nicht benötigt und wurde automatisch deaktiviert.',
	'Lightweight, high-performance tracking for Meta Pixel & Conversions API, Google Ads (Consent Mode v2) and TikTok Pixel – with URL-based multi-platform events and clean event deduplication.' => 'Schlankes, performantes Tracking für Meta Pixel & Conversions API, Google Ads (Consent Mode v2) und TikTok Pixel – mit URL-basierten Multi-Plattform-Events und sauberer Event-Deduplizierung.',
	'Dominik Seitz' => 'Dominik Seitz',
	// v2.0: Global & Plattformen
	'Global Options' => 'Globale Optionen',
	'Automatic cookie banner detection (GDPR)' => 'Automatische Cookie-Banner-Erkennung (DSGVO)',
	'Enable automatic cookie banner detection' => 'Automatische Cookie-Banner-Erkennung aktivieren',
	'Automatically detects installed cookie banners and blocks browser and CAPI events until consent is given. Supports Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies and any banner using the WP Consent API. Automatic blocking cannot be guaranteed for unlisted third-party banners. Sites without a cookie banner are never blocked.' => 'Erkennt installierte Cookie-Banner automatisch und blockiert Browser- und CAPI-Events bis zur Einwilligung. Unterstützt u. a. Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies sowie alle Banner mit WP Consent API. Bei nicht gelisteten Drittanbieter-Bannern kann die automatische Blockierung nicht garantiert werden. Websites ohne Cookie-Banner werden niemals blockiert.',
	'The test code is automatically deactivated after 12 hours to prevent accidental test tracking on a live site.' => 'Der Test-Code wird nach 12 Stunden automatisch deaktiviert, um versehentliches Test-Tracking im Live-Betrieb zu verhindern.',
	'Saved.' => 'Gespeichert.',
	'Platforms' => 'Plattformen',
	'Meta (Facebook)' => 'Meta (Facebook)',
	'Enable Meta tracking' => 'Meta-Tracking aktivieren',
	'Meta Events Manager → Data sources → your pixel → copy the ID shown below its name.' => 'Meta Events Manager → Datenquellen → Dein Pixel → ID unter dem Namen kopieren.',
	'Meta Events Manager → Data sources → Settings → Conversions API → “Generate access token”.' => 'Meta Events Manager → Datenquellen → Einstellungen → Conversions API → „Zugriffstoken generieren“.',
	'Secret key. Used exclusively server-side and never rendered in the frontend.' => 'Geheimschlüssel. Wird ausschließlich serverseitig verwendet und niemals im Frontend ausgegeben.',
	'Meta Events Manager → Test events → copy the “Server events code”. Clear it again after testing!' => 'Meta Events Manager → Test-Events → „Code für Server-Events“ kopieren. Nach dem Testen wieder leeren!',
	'Google Ads' => 'Google Ads',
	'Enable Google Ads tracking' => 'Google Ads Tracking aktivieren',
	'Google Tag ID' => 'Google Tag ID',
	'Google Ads → Tools & Settings → Google Tag → copy the ID (AW-XXXXX).' => 'Google Ads → Tools & Einstellungen → Google-Tag → ID kopieren (AW-XXXXX).',
	'Google Consent Mode v2 (default)' => 'Google Consent Mode v2 (Standard)',
	'Enable Google Consent Mode v2 defaults' => 'Google Consent Mode v2 Defaults aktivieren',
	'Sets ad_storage, ad_user_data, ad_personalization and analytics_storage to "denied" by default before the tag loads. Your consent banner then sends the consent update. Recommended for the EU.' => 'Setzt ad_storage, ad_user_data, ad_personalization und analytics_storage vor dem Tag-Laden standardmäßig auf „denied“. Dein Consent-Banner sendet anschließend das Consent-Update. Empfohlen für die EU.',
	'GA4 Measurement ID' => 'GA4 Measurement ID',
	'Google Analytics → Admin → Data streams → your web stream → copy the Measurement ID.' => 'Google Analytics → Verwaltung → Datenstreams → Dein Web-Datenstream → Measurement-ID kopieren.',
	'Optional, independent of Google Ads above. Reuses the same gtag.js loader – view_item, add_to_cart, begin_checkout and purchase from WooCommerce/SureCart tracking are picked up automatically once set.' => 'Optional, unabhängig von Google Ads oben. Nutzt denselben gtag.js-Loader – view_item, add_to_cart, begin_checkout und purchase aus dem WooCommerce-/SureCart-Tracking werden automatisch mit erfasst, sobald hier ein Wert eingetragen ist.',
	'TikTok' => 'TikTok',
	'Enable TikTok Pixel' => 'TikTok Pixel aktivieren',
	'TikTok Pixel ID' => 'TikTok Pixel ID',
	'TikTok Ads Manager → Assets → Events → Web events → copy the pixel ID.' => 'TikTok Ads Manager → Assets → Events → Web-Events → Pixel-ID kopieren.',
	'Toggle panel: %s' => 'Bereich umschalten: %s',
	// v0.5.0: Tabs
	'General' => 'Allgemein',
	'URL Events' => 'URL-Events',
	'Advanced Tracking' => 'Erweitertes Tracking',
	// v0.5.0: Feature 1–3 (Erweiterte Tracking-Features)
	'Advanced Tracking Features' => 'Erweiterte Tracking-Features',
	'Automatic form lead tracking' => 'Automatisches Formular-Lead-Tracking',
	'Supports Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms and plain HTML forms.' => 'Unterstützt Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms sowie einfache HTML-Formulare.',
	'Enable automatic form lead tracking' => 'Automatisches Formular-Lead-Tracking aktivieren',
	'Detects form submissions automatically and fires the configured event in the browser and via the Conversions API using the same event ID. Email address and phone number are hashed with SHA-256 before they are sent – raw values never leave your server and are never stored.' => 'Erkennt Formular-Absendungen automatisch und feuert das konfigurierte Event im Browser sowie über die Conversions API mit derselben Event-ID. E-Mail-Adresse und Telefonnummer werden vor dem Versand mit SHA-256 gehasht – Klartext-Daten verlassen deinen Server nie und werden nicht gespeichert.',
	// v0.5.3: Hinweise zur Konflikt-Vermeidung
	'Note: Use URL events ideally for thank-you and confirmation pages (e.g. /danke/, /kauf-erfolgreich/). For regular forms without a redirect, please use the automatic form tracking in tab “Advanced Tracking” to avoid duplicate counting.' => 'Hinweis: Verwende URL-Events idealerweise für Danke- und Bestätigungsseiten (z. B. /danke/, /kauf-erfolgreich/). Für reguläre Formulare ohne Weiterleitung nutze bitte das automatische Formular-Tracking im Tab „Erweitertes Tracking“, um doppelte Zählungen zu vermeiden.',
	'The event fires the moment the submit button is clicked. If your page redirects to a separate thank-you page after submitting, set up tracking via the “URL Events” tab instead.' => 'Das Event feuert beim Klick auf den Absenden-Button. Falls deine Seite nach dem Absenden auf eine separate Danke-Seite weiterleitet, richte das Tracking stattdessen über den Tab „URL-Events“ ein.',
	'Caution: For URL %s both a URL event and automatic form tracking are active. This can lead to duplicate counting.' => 'Achtung: Für URL %s ist sowohl ein URL-Event als auch automatisches Formular-Tracking aktiv. Dies kann zu Doppelzählungen führen.',
	// v0.5.1: Granulare Steuerung des Formular-Grabbers
	'Event type' => 'Event-Typ',
	'Meta event fired on form submission. Use “Contact” for general enquiries and “Lead” for genuine acquisition forms.' => 'Meta-Event, das beim Absenden ausgelöst wird. Nutze „Contact“ für allgemeine Anfragen und „Lead“ für echte Akquise-Formulare.',
	'Run on specific pages only (optional)' => 'Nur auf bestimmten Seiten ausführen (optional)',
	'Enter paths separated by commas (e.g. /kontakt, /angebot, /anfrage). Leave empty to track on the entire website. On pages that do not match, the script is not loaded at all.' => 'Pfade kommagetrennt eingeben (z. B. /kontakt, /angebot, /anfrage). Bleibt das Feld leer, ist das Tracking auf der gesamten Website aktiv. Auf nicht passenden Seiten wird das Skript gar nicht erst geladen.',
	'Ignore search, comments & logins' => 'Suche, Kommentare & Logins ignorieren',
	'Ignore search, comments and logins' => 'Suche, Kommentare und Logins ignorieren',
	'Prevents accidental tracking of search bars, blog comments and login fields. Forms containing a password field are always ignored, regardless of this setting.' => 'Verhindert versehentliches Tracking in Suchleisten, Blog-Kommentaren und Login-Feldern. Formulare mit einem Passwortfeld werden unabhängig von dieser Einstellung immer ignoriert.',
	'First-touch & UTM passthrough' => 'First-Touch- & UTM-Weitergabe',
	'Stores utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid and gclid in a first-party cookie for 30 days.' => 'Speichert utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid und gclid 30 Tage lang in einem First-Party-Cookie.',
	'Enable UTM passthrough' => 'UTM-Weitergabe aktivieren',
	'Saves campaign parameters on the first visit and sends them along with every server-side event as custom_data. A stored fbclid is converted into the fbc format so conversions stay attributed even days after the ad click.' => 'Speichert Kampagnen-Parameter beim Erstbesuch und sendet sie bei jedem serverseitigen Event als custom_data mit. Eine gespeicherte fbclid wird ins fbc-Format übersetzt, damit Conversions auch Tage nach dem Anzeigenklick korrekt zugeordnet werden.',
	// v0.5.6/v0.5.7: Automatischer UTM-Formular-Fill (Feld-Scope in v0.5.7 auf
	// die 3 Kernfelder Source/Campaign/Medium reduziert und im Admin als
	// Tabelle statt Fließtext dokumentiert)
	'Automatic UTM form fill' => 'Automatischer UTM-Formular-Fill',
	'Enable automatic UTM form fill' => 'Automatischen UTM-Formular-Fill aktivieren',
	'Writes Source, Campaign and Medium into matching form fields before the visitor submits.' => 'Schreibt Source, Campaign und Medium in passende Formularfelder, bevor der Besucher absendet.',
	'Fills hidden or visible form fields with the visitor’s campaign values so they land in your CRM or notification email together with the lead. Source is read from the current URL first, then – if “First-touch & UTM passthrough” above is enabled – from the attribution cookie for visitors who already navigated to a subpage, and finally guessed from a Facebook/Google click ID or the referrer. Campaign and Medium are only filled when an explicit value is found (URL or cookie).' => 'Füllt versteckte oder sichtbare Formularfelder mit den Kampagnen-Werten des Besuchers, damit sie zusammen mit dem Lead in deinem CRM oder in der Benachrichtigungs-E-Mail landen. Source wird zuerst aus der aktuellen URL gelesen, dann – falls „First-Touch- & UTM-Weitergabe“ oben aktiviert ist – aus dem Attribution-Cookie für Besucher, die bereits zu einer Unterseite navigiert sind, und zuletzt aus einer Facebook-/Google-Klick-ID oder dem Referrer geschätzt. Campaign und Medium werden nur befüllt, wenn ein expliziter Wert gefunden wird (URL oder Cookie).',
	'Value' => 'Wert',
	'Field name' => 'Feldname',
	'CSS class' => 'CSS-Klasse',
	'UTM Source' => 'UTM Source',
	'UTM Campaign' => 'UTM Campaign',
	'UTM Medium' => 'UTM Medium',
	'Field name takes priority over CSS class. Works both on the input field itself and on a surrounding form block/wrapper element carrying the class. If no UTM Source is found this way, it falls back to facebook, google or direct.' => 'Feldname hat Vorrang vor CSS-Klasse. Funktioniert sowohl auf dem Input-Feld selbst als auch auf einem umgebenden Formular-Block/Wrapper-Element mit dieser Klasse. Wird auf diesem Weg kein UTM Source gefunden, greift der Fallback auf facebook, google oder direct.',
	'Run on' => 'Ausführen auf',
	'On all pages' => 'Auf allen Seiten',
	'Only on specific URLs' => 'Nur auf bestimmten URLs',
	'Exclude specific URLs' => 'Auf bestimmten URLs ausschließen',
	'URL patterns (one per line)' => 'URL-Muster (eines pro Zeile)',
	'One path pattern per line, e.g. /kontakt or /lp/* for everything below /lp/. Only used for “Only on specific URLs” and “Exclude specific URLs”.' => 'Ein Pfad-Muster pro Zeile, z. B. /kontakt oder /lp/* für alles unterhalb von /lp/. Wird nur für „Nur auf bestimmten URLs“ und „Auf bestimmten URLs ausschließen“ verwendet.',
	'Admin live debug bar' => 'Admin Live-Debug-Leiste',
	'Show live debug bar in the frontend' => 'Live-Debug-Leiste im Frontend anzeigen',
	'Shows a small bar at the bottom of the frontend with consent status, fired events, event IDs, CAPI response and match keys. Rendered exclusively for logged-in administrators – regular visitors get zero additional bytes.' => 'Zeigt am unteren Rand des Frontends eine kleine Leiste mit Consent-Status, gefeuerten Events, Event-IDs, CAPI-Antwort und Match-Keys. Wird ausschließlich für eingeloggte Administratoren gerendert – reguläre Besucher erhalten kein zusätzliches Byte.',
	// v0.5.0: Feature 4 (Werkzeuge)
	'Export & Import' => 'Export & Import',
	'Export configuration' => 'Konfiguration exportieren',
	'Downloads all settings, platform IDs and event rules as a JSON file – ideal for rolling out a proven setup to another site.' => 'Lädt alle Einstellungen, Plattform-IDs und Event-Regeln als JSON-Datei herunter – ideal, um ein bewährtes Setup auf eine weitere Website zu übertragen.',
	'Note:' => 'Hinweis:',
	'The export contains your CAPI access token in plain text. Store the file securely and never share it publicly.' => 'Der Export enthält deinen CAPI Access Token im Klartext. Bewahre die Datei sicher auf und teile sie niemals öffentlich.',
	'Import configuration' => 'Konfiguration importieren',
	'Upload a previously exported JSON file. All values are validated and sanitised before they are saved.' => 'Lade eine zuvor exportierte JSON-Datei hoch. Alle Werte werden vor dem Speichern validiert und bereinigt.',
	'The import overwrites the current settings and all event rules.' => 'Der Import überschreibt die aktuellen Einstellungen und alle Event-Regeln.',
	'Really import? The current settings and event rules will be overwritten.' => 'Wirklich importieren? Die aktuellen Einstellungen und Event-Regeln werden überschrieben.',
	'The configuration could not be exported.' => 'Die Konfiguration konnte nicht exportiert werden.',
	'Configuration imported successfully.' => 'Konfiguration erfolgreich importiert.',
	'The file could not be imported. Please upload a valid export of this plugin.' => 'Die Datei konnte nicht importiert werden. Bitte lade einen gültigen Export dieses Plugins hoch.',
	'Please choose a JSON file to import.' => 'Bitte wähle eine JSON-Datei zum Importieren aus.',
	// v0.5.0: Live-Debug-Leiste
	'No marketing consent' => 'Keine Marketing-Einwilligung',
	'Pixel ID or access token missing' => 'Pixel-ID oder Access Token fehlt',
	'Detection disabled' => 'Erkennung deaktiviert',
	'Granted (%s)' => 'Erteilt (%s)',
	'Granted (no banner detected)' => 'Erteilt (kein Banner erkannt)',
	'Pending / denied (%s)' => 'Ausstehend / abgelehnt (%s)',
	'Pending / denied' => 'Ausstehend / abgelehnt',
	'Pixel Made Simple – Live Debug' => 'Pixel Made Simple – Live-Debug',
	'Consent' => 'Consent',
	'Browser' => 'Browser',
	'CAPI' => 'CAPI',
	'Match keys' => 'Match-Keys',
	'Attribution' => 'Attribution',
	'No events on this page yet.' => 'Auf dieser Seite bisher keine Events.',
	'Only visible to administrators.' => 'Nur für Administratoren sichtbar.',
	'Minimise' => 'Minimieren',
	'Tracking skipped: administrators are excluded' => 'Tracking übersprungen: Administratoren sind ausgeschlossen',
	'Tracking skipped: no platform enabled or ID missing' => 'Tracking übersprungen: keine Plattform aktiv oder ID fehlt',
	'Tracking skipped: disabled via pms_allow_tracking filter' => 'Tracking übersprungen: per Filter pms_allow_tracking deaktiviert',
	'Tracking skipped: not a trackable page (feed, preview, 404 …)' => 'Tracking übersprungen: keine trackbare Seite (Feed, Vorschau, 404 …)',
	// v0.3.1: Info & Hilfe
	'Info & Help' => 'Info & Hilfe',
	'Plugin Information' => 'Plugin-Informationen',
	'If you have questions or found a bug, please email %s.' => 'Bei Fragen oder Bugs melde dich gerne per E-Mail an %s.',
	'Developed by %s.' => 'Entwickelt von %s.',
	'Edit event “%s”' => 'Event „%s“ bearbeiten',
	// v2.0: Events
	'Please enable at least one platform for this event.' => 'Bitte aktiviere mindestens eine Plattform für dieses Event.',
	'Google Ads is enabled for this event but the conversion label is missing.' => 'Google Ads ist für dieses Event aktiviert, aber das Conversion Label fehlt.',
	'e.g. Lead magnet confirmation' => 'z. B. Lead Magnet Bestätigung',
	'With the “CustomEvent” type, this name is sent as the custom event name.' => 'Beim Typ „CustomEvent“ wird dieser Name als Custom-Event-Name gesendet.',
	'Meta event type' => 'Meta Event-Typ',
	'Google Ads conversion label' => 'Google Ads Conversion Label',
	'Google Ads → Conversion goals → click the action → Tag setup → snippet → copy the label after the slash (AW-XXX/LABEL).' => 'Google Ads → Ziel-Conversions → Aktion anklicken → Tag-Einrichtung → Snippet → Label hinter dem Schrägstrich kopieren (AW-XXX/LABEL).',
	'TikTok event type' => 'TikTok Event-Typ',
	// Admin
	'Settings' => 'Einstellungen',
	'Meta Pixel & CAPI Tracker' => 'Meta Pixel & CAPI Tracker',
	'Event saved.' => 'Event gespeichert.',
	'Event deleted.' => 'Event gelöscht.',
	'Event status updated.' => 'Event-Status aktualisiert.',
	'Setting saved.' => 'Einstellung gespeichert.',
	'The event could not be saved. Please fill in all required fields correctly.' => 'Das Event konnte nicht gespeichert werden. Bitte fülle alle Pflichtfelder korrekt aus.',
	'The event could not be found.' => 'Das Event wurde nicht gefunden.',
	'You do not have permission to access this page.' => 'Du hast keine Berechtigung, diese Seite aufzurufen.',
	'Plugin tabs' => 'Plugin-Tabs',
	'Meta Pixel ID' => 'Meta Pixel ID',
	'Enable Conversions API' => 'Conversions API aktivieren',
	'Additionally sends matched events to Meta server-side – deduplicated via the same event ID as in the browser.' => 'Sendet gematchte Events zusätzlich serverseitig an Meta – dedupliziert über dieselbe Event-ID wie im Browser.',
	'CAPI Access Token' => 'CAPI Access Token',
	'Test Event Code' => 'Test Event Code',
	'Do not track administrators' => 'Administratoren nicht tracken',
	'Recommended: logged-in administrators trigger neither pixel nor server events.' => 'Empfohlen: Eingeloggte Administratoren lösen weder Pixel- noch Server-Events aus.',
	'Advanced Matching (email)' => 'Advanced Matching (E-Mail)',
	'Enable Advanced Matching' => 'Advanced Matching aktivieren',
	'Sends the email address of logged-in users to the Conversions API as a SHA-256 hash (better match quality). Mind data privacy.' => 'Sendet bei eingeloggten Nutzern die E-Mail-Adresse als SHA-256-Hash an die Conversions API (bessere Zuordnungsqualität). Beachte den Datenschutz.',
	'Save Settings' => 'Einstellungen speichern',
	'Custom Events' => 'Benutzerdefinierte Events',
	'Enable all custom events' => 'Alle benutzerdefinierten Events aktivieren',
	'Enable/disable all custom events' => 'Alle benutzerdefinierten Events aktivieren/deaktivieren',
	'Apply' => 'Übernehmen',
	'Custom events are currently disabled globally. The rules below will not fire.' => 'Benutzerdefinierte Events sind derzeit global deaktiviert. Die Regeln unten werden nicht ausgelöst.',
	'Status' => 'Status',
	'Name' => 'Name',
	'Trigger Condition (URL)' => 'Trigger-Bedingung (URL)',
	'Actions' => 'Aktionen',
	'No events yet. Create your first event below.' => 'Noch keine Events angelegt. Erstelle unten dein erstes Event.',
	'Enable event “%s”' => 'Event „%s“ aktivieren',
	'Toggle' => 'Umschalten',
	'Exact path:' => 'Exakter Pfad:',
	'URL contains:' => 'URL enthält:',
	'Edit' => 'Bearbeiten',
	'Really delete this event?' => 'Möchtest du dieses Event wirklich löschen?',
	'Delete' => 'Löschen',
	'Create New Event' => 'Neues Event erstellen',
	'Event Name (internal)' => 'Event-Name (intern)',
	'Trigger' => 'Trigger',
	'Exact path' => 'Exakter Pfad',
	'URL contains' => 'URL enthält',
	'“Exact path” compares only the URL path (case and trailing slash are ignored). “URL contains” checks the path including the query string.' => '„Exakter Pfad“ vergleicht nur den URL-Pfad (Groß-/Kleinschreibung und abschließender Slash werden ignoriert). „URL enthält“ prüft den Pfad inklusive Query-String.',
	'Event active' => 'Event aktiv',
	'Update Event' => 'Event aktualisieren',
	'Add Event' => 'Event hinzufügen',
	'Cancel' => 'Abbrechen',
	'You do not have permission to perform this action.' => 'Du hast keine Berechtigung für diese Aktion.',
	// v0.6.0: Free/Pro-Gating (Custom-Events-Limit, UTM- & Export-Teaser)
	'Pro' => 'Pro',
	'Upgrade to Pro' => 'Auf Pro upgraden',
	'Upgrade to Pro for unlimited events.' => 'Upgrade auf Pro für unbegrenzt viele Events.',
	'The free version includes up to 2 URL events.' => 'Die kostenlose Version enthält bis zu 2 URL-Events.',
	'The free version includes up to 2 URL events. Upgrade to Pro for unlimited events.' => 'Die kostenlose Version enthält bis zu 2 URL-Events. Upgrade auf Pro für unbegrenzte Events.',
	'First-touch, UTM attribution & automatic form fill' => 'First-Touch, UTM-Attribution & automatisches Formular-Fill',
	'Store campaign parameters (UTM values + click IDs) in a first-party cookie, send them as custom_data with every server-side event, and automatically fill Source/Campaign/Medium into your forms. Available in Pixel Made Simple Pro.' => 'Speichert Kampagnen-Parameter (UTM-Werte + Klick-IDs) in einem First-Party-Cookie, sendet sie als custom_data bei jedem serverseitigen Event mit und füllt automatisch Source/Campaign/Medium in deine Formulare. Verfügbar in Pixel Made Simple Pro.',
	'Download all settings, platform IDs and event rules as a JSON file – ideal for rolling out a proven setup to another site or handing off a client project. Available in Pixel Made Simple Pro.' => 'Lädt alle Einstellungen, Plattform-IDs und Event-Regeln als JSON-Datei herunter – ideal, um ein bewährtes Setup auf eine andere Website zu übertragen oder ein Kundenprojekt zu übergeben. Verfügbar in Pixel Made Simple Pro.',
	'Exporting the configuration is a Pixel Made Simple Pro feature.' => 'Der Export der Konfiguration ist eine Pixel Made Simple Pro-Funktion.',
	'Importing a configuration is a Pixel Made Simple Pro feature.' => 'Der Import einer Konfiguration ist eine Pixel Made Simple Pro-Funktion.',
	'Upload a previously exported JSON file to apply all settings, platform IDs and event rules in one step. Available in Pixel Made Simple Pro.' => 'Lädt eine zuvor exportierte JSON-Datei hoch, um alle Einstellungen, Plattform-IDs und Event-Regeln in einem Schritt zu übernehmen. Verfügbar in Pixel Made Simple Pro.',
	'Track conversions with Google Ads and GA4 (gtag.js), including Google Consent Mode v2 defaults. Available in Pixel Made Simple Pro.' => 'Trackt Conversions mit Google Ads und GA4 (gtag.js) inklusive Google Consent Mode v2 Defaults. Verfügbar in Pixel Made Simple Pro.',
	'Track conversions with the TikTok Pixel using the official web events. Available in Pixel Made Simple Pro.' => 'Trackt Conversions mit dem TikTok Pixel über die offiziellen Web-Events. Verfügbar in Pixel Made Simple Pro.',
	// v0.6.1: Event Log
	'Event Log' => 'Event Log',
	'Shows recent browser and Conversions API events so you can verify your tracking is working, without leaving WordPress.' => 'Zeigt aktuelle Browser- und Conversions-API-Events, damit du dein Tracking direkt in WordPress überprüfen kannst.',
	'Really clear the entire event log? This cannot be undone.' => 'Wirklich das komplette Event Log leeren? Das kann nicht rückgängig gemacht werden.',
	'Clear log' => 'Log leeren',
	'Time' => 'Zeitpunkt',
	'Event' => 'Event',
	'Event ID' => 'Event-ID',
	'Source' => 'Quelle',
	'Match Keys' => 'Match Keys',
	'No events logged yet.' => 'Noch keine Events protokolliert.',
	'Entries are automatically deleted after %d days.' => 'Einträge werden automatisch nach %d Tagen gelöscht.',
	'Filter by status' => 'Nach Status filtern',
	'All statuses' => 'Alle Status',
	'Errors only' => 'Nur Fehler',
	'Filter by event name' => 'Nach Event-Name filtern',
	'All events' => 'Alle Events',
	'Filter' => 'Filtern',
	'Filtering the event log is a Pro feature.' => 'Das Filtern des Event Logs ist eine Pro-Funktion.',
	'Retention period' => 'Aufbewahrungsdauer',
	'%d days' => '%d Tage',
	'3 days (free limit)' => '3 Tage (Free-Limit)',
	'Choosing a longer retention period is a Pro feature.' => 'Eine längere Aufbewahrungsdauer zu wählen ist eine Pro-Funktion.',
	'Browser + CAPI' => 'Browser + CAPI',
	'Error' => 'Fehler',
	'Sent' => 'Gesendet',
	'Event log cleared.' => 'Event Log geleert.',
	// v0.6.4/v0.6.5: E-Commerce-Tab, WooCommerce-Tracking + Import/Export-Umbenennung
	// (ersetzt die frühere 'Tools' => 'Werkzeuge'-Zeile, der Tab-Slug blieb 'tools',
	// nur das UI-Label wurde umbenannt, siehe CLAUDE.md)
	'Import / Export' => 'Import / Export',
	'E-Commerce' => 'E-Commerce',
	'WooCommerce was not detected on this site. Once WooCommerce is activated, the tracking options will appear here.' => 'WooCommerce wurde auf dieser Website nicht erkannt. Sobald WooCommerce aktiviert ist, erscheinen hier die Tracking-Optionen.',
	'WooCommerce' => 'WooCommerce',
	'Enable WooCommerce tracking' => 'WooCommerce-Tracking aktivieren',
	'Automatically tracks ViewContent, AddToCart, InitiateCheckout and Purchase for WooCommerce, deduplicated via the same event ID as in the browser. Purchase additionally uses a server-side fallback for orders completed via external payment gateways that skip the order-received page.' => 'Trackt automatisch ViewContent, AddToCart, InitiateCheckout und Purchase für WooCommerce, dedupliziert über dieselbe Event-ID wie im Browser. Purchase nutzt zusätzlich einen serverseitigen Fallback für Bestellungen, die über externe Zahlungsanbieter abgeschlossen werden und dabei die Danke-Seite überspringen.',
	'Product identifier' => 'Produktkennung',
	'Must match how your Meta catalog identifies products (content_id).' => 'Muss damit übereinstimmen, wie dein Meta-Katalog Produkte identifiziert (content_id).',
	'Product ID' => 'Produkt-ID',
	'SKU (falls back to Product ID when empty)' => 'SKU (Fallback auf Produkt-ID, wenn leer)',
	'Purchase value' => 'Purchase-Wert',
	'Whether the Purchase event value includes tax (gross, the amount actually paid) or excludes it (net).' => 'Ob der Wert des Purchase-Events die Steuer enthält (brutto, der tatsächlich bezahlte Betrag) oder nicht (netto).',
	'Gross (incl. tax)' => 'Brutto (inkl. Steuer)',
	'Net (excl. tax)' => 'Netto (exkl. Steuer)',
	'Purchase Advanced Matching' => 'Purchase Advanced Matching',
	'Enable Purchase Advanced Matching' => 'Purchase Advanced Matching aktivieren',
	'Sends hashed billing details from the order (email, phone, name, address) to the Conversions API for better match quality. Mind data privacy.' => 'Sendet gehashte Rechnungsdaten der Bestellung (E-Mail, Telefon, Name, Adresse) für eine bessere Zuordnungsqualität an die Conversions API. Beachte den Datenschutz.',
	'Automatically track ViewContent, AddToCart, InitiateCheckout and Purchase for WooCommerce — deduplicated via the same event ID as in the browser, with a server-side fallback for orders completed via external payment gateways. Available in Pixel Made Simple Pro.' => 'Trackt automatisch ViewContent, AddToCart, InitiateCheckout und Purchase für WooCommerce – dedupliziert über dieselbe Event-ID wie im Browser, mit serverseitigem Fallback für Bestellungen über externe Zahlungsanbieter. Verfügbar in Pixel Made Simple Pro.',
	// v0.6.6: Google Ads Enhanced Conversions & TikTok Events API (Purchase)
	'Enable Events API' => 'Events API aktivieren',
	'Enable TikTok Events API' => 'TikTok Events API aktivieren',
	'Additionally sends matched events to TikTok server-side. Currently used for WooCommerce Purchase tracking only (tab “E-Commerce”), deduplicated via the same event ID as in the browser.' => 'Sendet gematchte Events zusätzlich serverseitig an TikTok. Aktuell nur für WooCommerce-Purchase-Tracking genutzt (Tab „E-Commerce“), dedupliziert über dieselbe Event-ID wie im Browser.',
	'Events API Access Token' => 'Events API Access Token',
	'TikTok Events Manager → your pixel → Settings → Events API → Generate access token.' => 'TikTok Events Manager → dein Pixel → Einstellungen → Events API → Zugriffstoken generieren.',
	'Google Ads conversion label (Purchase)' => 'Google Ads Conversion Label (Purchase)',
	'Optional. Google Ads → Conversions → your Purchase action → "Use tag" → the part after the slash in send_to. Leave empty to skip the Google Ads Purchase conversion (ViewContent/AddToCart/InitiateCheckout are not affected).' => 'Optional. Google Ads → Conversions → deine Purchase-Aktion → „Use tag“ → der Teil nach dem Schrägstrich in send_to. Leer lassen, um die Google-Ads-Purchase-Conversion zu überspringen (ViewContent/AddToCart/InitiateCheckout sind davon nicht betroffen).',
	// v0.6.7: SureCart-Integration (zweite E-Commerce-Plattform neben WooCommerce)
	'SureCart was not detected on this site. Once SureCart is activated, the tracking options will appear here.' => 'SureCart wurde auf dieser Website nicht erkannt. Sobald SureCart aktiviert ist, erscheinen hier die Tracking-Optionen.',
	'SureCart' => 'SureCart',
	'Enable SureCart tracking' => 'SureCart-Tracking aktivieren',
	'Automatically tracks ViewContent, AddToCart, InitiateCheckout and Purchase for SureCart, deduplicated via the same event ID as in the browser. Purchase additionally uses a server-side fallback for orders that reach a paid status outside the regular checkout confirmation (e.g. asynchronous payment methods).' => 'Trackt automatisch ViewContent, AddToCart, InitiateCheckout und Purchase für SureCart, dedupliziert über dieselbe Event-ID wie im Browser. Purchase nutzt zusätzlich einen serverseitigen Fallback für Bestellungen, die außerhalb der regulären Checkout-Bestätigung einen bezahlten Status erreichen (z. B. asynchrone Zahlungsmethoden).',
	'Sends hashed billing details from the checkout (email, phone, name, address) to the Conversions API for better match quality. Mind data privacy.' => 'Sendet gehashte Rechnungsdaten des Checkouts (E-Mail, Telefon, Name, Adresse) für eine bessere Zuordnungsqualität an die Conversions API. Beachte den Datenschutz.',
	'Automatically track ViewContent, AddToCart, InitiateCheckout and Purchase for SureCart — deduplicated via the same event ID as in the browser, with a server-side fallback for orders that reach a paid status outside the regular checkout confirmation. Available in Pixel Made Simple Pro.' => 'Trackt automatisch ViewContent, AddToCart, InitiateCheckout und Purchase für SureCart – dedupliziert über dieselbe Event-ID wie im Browser, mit serverseitigem Fallback für Bestellungen, die außerhalb der regulären Checkout-Bestätigung einen bezahlten Status erreichen. Verfügbar in Pixel Made Simple Pro.',
	// v0.6.12: Free-Locks (PRO-Badges), Info & Hilfe (Support/Doku/Tutorials)
	'Available in Pixel Made Simple Pro' => 'Verfügbar in Pixel Made Simple Pro',
	'Automatic e-commerce tracking for WooCommerce & SureCart including the Conversions API is exclusive to Pixel Made Simple Pro.' => 'Automatisches E-Commerce Tracking für WooCommerce & SureCart mit CAPI ist exklusiv in Pixel Made Simple Pro verfügbar.',
	'Pixel Made Simple – Dominik Seitz' => 'Pixel Made Simple – Dominik Seitz',
	'Documentation' => 'Dokumentation',
	'Setup guides, every setting explained and answers to the most common tracking questions.' => 'Einrichtungs-Anleitungen, jede Einstellung erklärt und Antworten auf die häufigsten Tracking-Fragen.',
	'Official documentation' => 'Offizielle Dokumentation',
	'Video tutorials' => 'Video-Tutorials',
	'Short, focused walkthroughs for the four things people set up most often.' => 'Kurze, fokussierte Anleitungen für die vier Dinge, die am häufigsten eingerichtet werden.',
	'View all tutorials' => 'Alle Tutorials ansehen',
	'Quick start' => 'Schnellstart',
	'Install, connect your first pixel and verify that events arrive – in about ten minutes.' => 'Installieren, das erste Pixel verbinden und prüfen, ob Events ankommen – in etwa zehn Minuten.',
	'Meta CAPI setup' => 'Meta CAPI einrichten',
	'Access token, Conversions API and event deduplication via a shared event ID.' => 'Access Token, Conversions API und Event-Deduplizierung über eine gemeinsame Event-ID.',
	'Google Ads & GA4' => 'Google Ads & GA4',
	'Google Tag, Consent Mode v2, conversion labels and a GA4 property side by side.' => 'Google Tag, Consent Mode v2, Conversion Labels und ein GA4-Property nebeneinander.',
	'E-commerce tracking' => 'E-Commerce Tracking',
	'ViewContent, AddToCart, InitiateCheckout and Purchase for WooCommerce and SureCart.' => 'ViewContent, AddToCart, InitiateCheckout und Purchase für WooCommerce und SureCart.',
	// v0.6.11: Plattform-Achse im Event Log
	'Filter by platform' => 'Nach Plattform filtern',
	'All platforms' => 'Alle Plattformen',
	// Markennamen -- bleiben unübersetzt, brauchen aber einen Eintrag,
	// damit der Validator sie nicht als fehlend meldet.
	'Meta' => 'Meta',
	'GA4' => 'GA4',
	// v0.6.10: Consent-Modus, TikTok-Test-Code, Multi-Platform-Formular-Leads, UI-Politur
	'About GDPR blocking:' => 'Hinweis zur DSGVO-Blockade:',
	'When this mode is active, both client-side pixels and server-side CAPI calls are blocked until the visitor consents in the cookie banner. Without consent no data is transmitted to Meta, Google or TikTok. This can visibly reduce the conversion numbers reported in your ad account.' => 'Ist dieser Modus aktiv, werden sowohl clientseitige Pixel als auch serverseitige CAPI-Calls blockiert, bis der Besucher im Consent-Banner zustimmt. Ohne Einwilligung werden keine Daten an Meta, Google oder TikTok übermittelt. Dies kann die gemeldeten Conversion-Zahlen im Werbekonto sichtbar reduzieren.',
	'Consent mode' => 'Consent-Modus',
	'Only takes effect while a cookie banner is actually blocking. Once consent is given, both modes behave identically.' => 'Wirkt sich nur aus, solange ein Cookie-Banner tatsächlich blockiert. Sobald die Einwilligung vorliegt, verhalten sich beide Modi identisch.',
	'Fully GDPR compliant (recommended for the EU)' => 'Vollständig DSGVO-konform (Empfohlen für EU)',
	'Block browser pixels only' => 'Nur Browser-Pixel blockieren',
	'“Fully GDPR compliant” blocks pixels and CAPI until consent is given. “Block browser pixels only” keeps the server-side signals (Conversions API, TikTok Events API) running independently of the banner status – check with your data protection officer before enabling it.' => '„Vollständig DSGVO-konform“ blockiert Pixel und CAPI bis zur Einwilligung. „Nur Browser-Pixel blockieren“ lässt die serverseitigen Signale (Conversions API, TikTok Events API) unabhängig vom Banner-Status weiterlaufen – kläre das vorher mit deinem Datenschutzbeauftragten ab.',
	'TikTok Test Event Code' => 'TikTok Test Event Code',
	'TikTok Events Manager → your pixel → Test Events → copy the test event code. Clear it again after testing!' => 'TikTok Events Manager → dein Pixel → Test Events → Test-Event-Code kopieren. Nach dem Test wieder leeren!',
	'Optional. While set, Events API requests are marked as test events and appear under “Test Events” instead of your live data. Like the Meta test code above, it is automatically deactivated after 12 hours.' => 'Optional. Solange gesetzt, werden Events-API-Anfragen als Testereignisse markiert und erscheinen unter „Test Events“ statt in deinen Livedaten. Wie der Meta-Test-Code darüber wird er nach 12 Stunden automatisch deaktiviert.',
	'The test code was older than 12 hours and has been removed automatically. Events are being sent as live data again.' => 'Der Test-Code war älter als 12 Stunden und wurde automatisch entfernt. Events werden wieder als Livedaten gesendet.',
	'Sending form leads to TikTok is a Pro feature.' => 'Formular-Leads an TikTok zu senden ist ein Pro-Feature.',
	'TikTok web event fired for the same submission, using the same event ID as the Meta event. Only fires when the TikTok Pixel is enabled in tab “General”.' => 'TikTok-Web-Event, das für dieselbe Absendung mit derselben Event-ID wie das Meta-Event gefeuert wird. Feuert nur, wenn das TikTok Pixel im Tab „Allgemein“ aktiviert ist.',
	'Google Ads conversion label (form leads)' => 'Google Ads Conversion Label (Formular-Leads)',
	'Optional. Google Ads → Conversions → your lead action → “Use tag” → the part after the slash in send_to. Leave empty to skip the Google Ads conversion for form leads.' => 'Optional. Google Ads → Conversions → deine Lead-Aktion → „Use tag“ → der Teil nach dem Schrägstrich in send_to. Leer lassen, um die Google-Ads-Conversion für Formular-Leads zu überspringen.',
	'Only fires when Google Ads is enabled in tab “General” and a label is set here.' => 'Feuert nur, wenn Google Ads im Tab „Allgemein“ aktiviert ist und hier ein Label hinterlegt wurde.',
	'Not detected' => 'Nicht erkannt',
	'Toggle panel: Create New Event' => 'Bereich ein-/ausklappen: Neues Event erstellen',
);

/* ---------------------------------------------------------------
 * 3. Validierung
 * ------------------------------------------------------------- */

$errors = array();

foreach ( $entries as $msgid => $meta ) {
	if ( ! isset( $de[ $msgid ] ) ) {
		$errors[] = "FEHLENDE ÜBERSETZUNG: \"$msgid\"";
		continue;
	}
	preg_match_all( '/%(\d+\$)?[sdf]/', $msgid, $src_ph );
	preg_match_all( '/%(\d+\$)?[sdf]/', $de[ $msgid ], $dst_ph );
	$a = $src_ph[0];
	$b = $dst_ph[0];
	sort( $a );
	sort( $b );
	if ( $a !== $b ) {
		$errors[] = "PLATZHALTER-ABWEICHUNG: \"$msgid\" -> \"{$de[$msgid]}\"";
	}
}
foreach ( array_keys( $de ) as $msgid ) {
	if ( ! isset( $entries[ $msgid ] ) ) {
		$errors[] = "VERWAISTE ÜBERSETZUNG (msgid nicht im Code): \"$msgid\"";
	}
}

if ( $errors ) {
	echo "VALIDIERUNG FEHLGESCHLAGEN:\n" . implode( "\n", $errors ) . "\n";
	exit( 1 );
}
echo "Validierung OK: alle Strings übersetzt, Platzhalter identisch\n";

/* ---------------------------------------------------------------
 * 4. POT + PO schreiben
 * ------------------------------------------------------------- */

function po_escape( $s ) {
	return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s ) . '"';
}

$version = 'dev';
if ( preg_match( "/define\\(\\s*'PMS_VERSION',\\s*'([^']+)'/", $main, $vm ) ) {
	$version = $vm[1];
}
$date = gmdate( 'Y-m-d H:i' ) . '+0000';

$pot_header = <<<POT
# Copyright (C) 2026 Dominik Seitz
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: Pixel Made Simple $version\\n"
"Report-Msgid-Bugs-To: https://sdv.design\\n"
"POT-Creation-Date: $date\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: pixel-made-simple\\n"


POT;

$po_header = <<<PO
# German translation (Du-Form) for Pixel Made Simple.
# Copyright (C) 2026 Dominik Seitz
msgid ""
msgstr ""
"Project-Id-Version: Pixel Made Simple $version\\n"
"Report-Msgid-Bugs-To: https://sdv.design\\n"
"POT-Creation-Date: $date\\n"
"PO-Revision-Date: $date\\n"
"Last-Translator: Dominik Seitz <seitz.entertainment@gmail.com>\\n"
"Language-Team: German\\n"
"Language: de_DE\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: pixel-made-simple\\n"


PO;

$pot = $pot_header;
$po  = $po_header;

foreach ( $entries as $msgid => $meta ) {
	$block = '';
	if ( $meta['comment'] ) {
		$block .= $meta['comment'] . "\n";
	}
	foreach ( $meta['refs'] as $ref ) {
		$block .= '#: ' . $ref . "\n";
	}
	$block .= 'msgid ' . po_escape( $msgid ) . "\n";

	$pot .= $block . "msgstr \"\"\n\n";
	$po  .= $block . 'msgstr ' . po_escape( $de[ $msgid ] ) . "\n\n";
}

file_put_contents( $lang_dir . '/' . $domain . '.pot', $pot );
file_put_contents( $lang_dir . '/' . $domain . '-de_DE.po', $po );
echo "Geschrieben: $domain.pot und $domain-de_DE.po\n";

/* ---------------------------------------------------------------
 * 5. MO kompilieren (Syntaxtest + Auslieferung)
 * ------------------------------------------------------------- */

$mo_entries = array( '' => "Project-Id-Version: Pixel Made Simple $version\nLanguage: de_DE\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n != 1);\nX-Domain: pixel-made-simple\n" );
foreach ( $entries as $msgid => $meta ) {
	$mo_entries[ $msgid ] = $de[ $msgid ];
}
ksort( $mo_entries, SORT_STRING );

$count       = count( $mo_entries );
$ids         = '';
$strs        = '';
$id_offsets  = array();
$str_offsets = array();

foreach ( $mo_entries as $id => $str ) {
	$id_offsets[]  = array( strlen( $id ), strlen( $ids ) );
	$ids          .= $id . "\0";
	$str_offsets[] = array( strlen( $str ), strlen( $strs ) );
	$strs         .= $str . "\0";
}

$header_size = 28;
$table_size  = 8 * $count;
$ids_start   = $header_size + 2 * $table_size;
$strs_start  = $ids_start + strlen( $ids );

$mo  = pack( 'V*', 0x950412de, 0, $count, $header_size, $header_size + $table_size, 0, 0 );
foreach ( $id_offsets as $off ) {
	$mo .= pack( 'V*', $off[0], $ids_start + $off[1] );
}
foreach ( $str_offsets as $off ) {
	$mo .= pack( 'V*', $off[0], $strs_start + $off[1] );
}
$mo .= $ids . $strs;

file_put_contents( $lang_dir . '/' . $domain . '-de_DE.mo', $mo );
echo "Kompiliert: $domain-de_DE.mo (" . strlen( $mo ) . " Bytes, $count Einträge)\n";

/* Gegenprobe: MO wieder einlesen und 3 Stichproben prüfen */
$data  = file_get_contents( $lang_dir . '/' . $domain . '-de_DE.mo' );
$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
if ( 0x950412de !== $magic ) {
	echo "MO-GEGENPROBE FEHLGESCHLAGEN: falsche Magic Number\n";
	exit( 1 );
}
$n         = unpack( 'V', substr( $data, 8, 4 ) )[1];
$ids_tbl   = unpack( 'V', substr( $data, 12, 4 ) )[1];
$strs_tbl  = unpack( 'V', substr( $data, 16, 4 ) )[1];
$found     = 0;
$samples   = array( 'Advanced Tracking Features' => 'Erweiterte Tracking-Features', 'Enable event “%s”' => 'Event „%s“ aktivieren', 'Really delete this event?' => 'Möchtest du dieses Event wirklich löschen?' );
for ( $i = 0; $i < $n; $i++ ) {
	list( , $len, $off ) = unpack( 'V2', substr( $data, $ids_tbl + 8 * $i, 8 ) );
	$id = substr( $data, $off, $len );
	if ( isset( $samples[ $id ] ) ) {
		list( , $slen, $soff ) = unpack( 'V2', substr( $data, $strs_tbl + 8 * $i, 8 ) );
		if ( substr( $data, $soff, $slen ) === $samples[ $id ] ) {
			$found++;
		}
	}
}
echo "MO-Gegenprobe: $found/3 Stichproben korrekt aufgelöst\n";
exit( 3 === $found ? 0 : 1 );
