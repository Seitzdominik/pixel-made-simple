=== Lightweight Meta Pixel & CAPI Tracker ===
Contributors: dominikseitz
Author: Dominik Seitz
Author URI: https://sdv.design
Tags: meta pixel, conversions api, google ads, tiktok pixel, consent mode
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schlankes, performantes Tracking für Meta Pixel & CAPI, Google Ads (Consent Mode v2) und TikTok Pixel – ohne Bloat, optimiert für Lead-Funnels.

== Description ==

Ein bewusst minimalistischer Ersatz für überladene Tracking-Plugins wie PixelYourSite:

* **PageSpeed-freundlich:** Nur die offiziellen Loader (asynchron) plus minimale Inline-Snippets. Kein jQuery, keine Frameworks, keine DOM-Listener im Frontend.
* **3 Plattformen:** Meta Pixel + Conversions API (Server), Google Ads (gtag.js) inkl. Consent Mode v2, TikTok Pixel.
* **URL-basierte Multi-Plattform-Events:** Ein Event, eine URL-Regel („Exakter Pfad" oder „URL enthält") – und pro Event frei wählbar, welche Plattformen feuern (Meta-Event-Typ, Google Conversion Label, TikTok-Event).
* **Saubere Meta-Deduplizierung:** Für jedes Meta-Event wird serverseitig eine UUID generiert und identisch an Browser-Pixel (`eventID`) und Conversions API (`event_id`) übergeben. Meta verwirft das Duplikat automatisch.
* **Conversions API fire-and-forget:** Serverseitiger Versand via `wp_remote_post()` nicht-blockierend – kein Einfluss auf die Ladezeit. user_data mit Client-IP, User-Agent, `_fbp`/`_fbc` (Fallback aus `fbclid`), optional SHA-256-gehashte E-Mail eingeloggter Nutzer.
* **Google Consent Mode v2:** Setzt auf Wunsch `ad_storage`, `ad_user_data`, `ad_personalization` und `analytics_storage` vor dem Tag-Laden auf `denied` – dein Consent-Banner sendet das Update.
* **Intelligente Cookie-Consent-Erkennung (DSGVO):** Erkennt installierte Cookie-Banner automatisch (Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies, WP Consent API) und blockiert Browser- und CAPI-Events bis zur Marketing-Einwilligung. Nach dem Klick auf „Akzeptieren" startet das Tracking sofort ohne Seiten-Reload. Websites ohne Banner werden niemals blockiert.
* **Formular-Auto-Grabber (Zero-Config Lead-Tracking, standardmäßig deaktiviert):** Erkennt Formular-Absendungen automatisch (Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms und native HTML-Formulare) und feuert „Lead" oder „Contact" im Browser und via CAPI mit identischer Event-ID. E-Mail und Telefonnummer werden SHA-256-gehasht übergeben – für maximalen Match-Score ohne Klartext-Datenweitergabe. Granular steuerbar: Event-Typ wählbar, optionaler URL-Filter (auf nicht passenden Seiten wird das Skript gar nicht geladen) und automatischer Ausschluss von Suche, Kommentaren und Logins.
* **First-Touch- & UTM-Attribution (standardmäßig deaktiviert):** Speichert utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid und gclid beim Erstbesuch 30 Tage in einem First-Party-Cookie und sendet die UTM-Parameter bei jedem Server-Event als `custom_data` mit. Die fbclid wird ins `fbc`-Format übersetzt – Conversions bleiben auch Tage nach dem Anzeigenklick zugeordnet.
* **Automatischer UTM-Formular-Fill (standardmäßig deaktiviert):** Schreibt utm_source, utm_medium, utm_campaign, utm_term, utm_content, fbclid und gclid automatisch in passende Formularfelder (per name-Attribut oder CSS-Klasse erkannt), bevor der Besucher absendet – die Kampagnendaten landen so direkt im CRM oder in der Benachrichtigungs-E-Mail. Werte werden zuerst aus der aktuellen URL gelesen, dann aus dem Attribution-Cookie (Unterseiten-Navigation) und zuletzt aus dem Referrer geschätzt. Granular steuerbar über „Auf allen Seiten", „Nur auf bestimmten URLs" oder „Auf bestimmten URLs ausschließen" mit zeilenbasierten Pfad-Mustern (inkl. `*`-Platzhalter).
* **Live-Debug-Konsole für Admins:** Dezente Leiste am unteren Bildschirmrand mit Consent-Status (inkl. erkanntem Banner), gefeuerten Events, Event-IDs, CAPI-Antwort (⏳ → ✅ 200 OK) und verwendeten Match-Keys. Wird ausschließlich für eingeloggte Administratoren gerendert – reguläre Besucher erhalten kein einziges zusätzliches Byte.
* **1-Klick Export & Import:** Komplette Konfiguration inkl. Event-Regeln als JSON exportieren und auf der nächsten Kundenwebsite importieren.
* **Test-Code Auto-Expiry:** Der Meta Test Event Code deaktiviert sich nach 12 Stunden automatisch – kein versehentliches Test-Tracking im Live-Betrieb.
* **Komfort:** Toggles speichern sofort per AJAX (nonce-gesichert, mit dezenter Bestätigung); das Einfügen eines CAPI-Tokens aktiviert die Conversions API automatisch.
* **Sicherheit:** Nonces, Capability-Checks (`manage_options`), konsequente Sanitization/Escaping, CAPI-Token nur serverseitig.
* **Übersetzbar (i18n):** Englische Quellstrings, POT-Vorlage und deutsche Übersetzung (`de_DE`) in `/languages`. Kompatibel mit Loco Translate, Poedit und Polylang/WPML-Sprachdateien.

== Installation ==

1. ZIP über „Plugins → Installieren → Plugin hochladen" installieren und aktivieren.
2. Unter „Pixel Tracker" im Admin-Menü die gewünschten Plattformen aufklappen, IDs eintragen und per Master-Toggle aktivieren.
3. Optional: CAPI Access Token hinterlegen und die Conversions API aktivieren.
4. Im Tab „Events verwalten" URL-Regeln für Conversion-Seiten anlegen und den Plattformen zuweisen.

== Frequently Asked Questions ==

= Wie teste ich die Meta-Server-Events? =

Trage den Test Event Code aus dem Events Manager (Tab „Test-Events") ein. Die Server-Events erscheinen dort in Echtzeit. Vor dem Livegang den Code wieder entfernen. Für Debugging kann der Versand blockierend geschaltet werden, dann landet die Meta-Antwort bei aktivem `WP_DEBUG_LOG` im Debug-Log:

`add_filter( 'lmpct_capi_blocking', '__return_true' );`

= Wie funktioniert das Zusammenspiel mit meinem Cookie-Banner? =

Die automatische Cookie-Banner-Erkennung (Standard: aktiv) prüft serverseitig die Consent-Cookies der gängigen Banner-Plugins und die WP Consent API. Ohne Marketing-Einwilligung werden die Browser-Skripte verzögert (sie lauschen auf die Banner-Events und starten sofort nach dem Klick auf „Akzeptieren") und die CAPI bricht vor dem HTTP-Request ab. Ist gar kein bekanntes Banner installiert, wird nichts blockiert.

Zusätzlich gilt: **Google Consent Mode v2** lädt gtag.js designgemäß sofort mit `denied`-Defaults – dein Banner sendet das `gtag('consent','update',...)`.

Für nicht unterstützte Banner lässt sich das Consent-Ergebnis per Filter setzen:

`add_filter( 'lmpct_has_marketing_consent', function ( $consent ) { return my_marketing_consent(); } );`

Und das gesamte Tracking serverseitig unterdrücken:

`add_filter( 'lmpct_allow_tracking', function ( $allow ) { return my_consent_check(); } );`

= Funktioniert das Plugin mit Page-Caching? =

Die Browser-Pixel: ja. Die Conversions API wird jedoch nur ausgelöst, wenn PHP die Seite tatsächlich rendert. Bei aggressivem Full-Page-Caching sollten die Conversion-/Danke-Seiten vom Cache ausgenommen werden, damit CAPI-Events zuverlässig und mit frischer Event-ID gesendet werden.

= Welche Filter gibt es? =

* `lmpct_allow_tracking` – Tracking global erlauben/unterbinden.
* `lmpct_has_marketing_consent` – Consent-Ergebnis der automatischen Banner-Erkennung überschreiben.
* `lmpct_consent_banner_active` – eigenes Banner bei der Erkennung registrieren.
* `lmpct_consent_events` – zusätzliche Banner-Events für den Frontend-Listener.
* `lmpct_capi_event_data` – einzelnes CAPI-Event vor dem Versand anpassen (z. B. `custom_data` mit Werten ergänzen).
* `lmpct_capi_user_data` – `user_data`-Payload anpassen.
* `lmpct_graph_api_version` – Graph-API-Version überschreiben (Zukunftssicherheit bei Meta-Deprecations).
* `lmpct_capi_blocking` – CAPI-Request blockierend senden (Debugging).

= Wie übersetze ich das Plugin (z. B. mit Loco Translate)? =

Die Quellstrings sind englisch. Im Ordner `/languages` liegen die POT-Vorlage sowie die fertige deutsche Übersetzung (`-de_DE.po`/`.mo`). Eigene Übersetzungen in Loco Translate am besten unter „System" bzw. `wp-content/languages/loco/plugins/` speichern, damit sie Updates überleben.

= Werden bei der Deinstallation alle Daten entfernt? =

Ja. `uninstall.php` löscht alle Plugin-Optionen inklusive des gespeicherten Access Tokens.

== Changelog ==

= 0.5.6 =
* Bugfix: Der Meta Test Event Code (Tab „Allgemein") wurde bisher nur an die Conversions API gesendet. Meta's Test-Events-Tool matcht Browser-Pixel-Events aber nur, wenn der Code auch im clientseitigen `fbq()`-Aufruf steckt – das Server-Event erschien dort, das Browser-Event fehlte. Der Code wird jetzt bei PageView, allen URL-Events und Formular-Submits in beiden Kanälen mitgesendet (inkl. des bestehenden 12-Stunden-Auto-Expiry, das jetzt einheitlich für Pixel und CAPI gilt).
* Neu: Automatischer, granular steuerbarer UTM-/Attribution-Formular-Fill (Tab „Erweitertes Tracking", standardmäßig deaktiviert) – siehe Beschreibung oben. Eigener Master-Toggle, unabhängig vom Formular-Auto-Grabber und vom First-Touch-/UTM-Passthrough-Cookie (nutzt Letzteres aber als optionalen Fallback, falls aktiviert).
* Erweiterung First-Touch-/UTM-Attribution: gclid wird jetzt wie fbclid als Klick-ID behandelt (Last-Touch statt First-Touch, 30 Tage im Attribution-Cookie) – primär als Datenquelle für den neuen Formular-Fill; wie fbclid nicht Teil des an die Meta CAPI gesendeten `custom_data`.
* Härtung: Der CAPI Access Token wird jetzt ausschließlich im Tab „Allgemein" ins Seitenquelltext-Markup gerendert. Zuvor tauchte er (esc_attr-escaped, aber unnötig) auch als verstecktes Feld im Tab „Erweitertes Tracking" auf, da jeder Tab sein eigenes Formular absendet. Kein extern ausnutzbares Risiko (erfordert ohnehin die eigene Admin-Session), aber unnötige Angriffsfläche im Admin-HTML. Speichern eines Tabs ohne eigenes Token-Feld überschreibt den Token jetzt nicht mehr mit einem Leerwert.

= 0.5.5 =
* Privacy-by-Default: Formular-Auto-Grabber und First-Touch-/UTM-Attribution sind bei Neuinstallation jetzt standardmäßig DEAKTIVIERT, da sie zusätzliche personenbezogene Daten verarbeiten (Formularinhalte bzw. Kampagnen-Cookie). Bestehende Installationen sind nicht betroffen, nur frische Installationen starten jetzt restriktiver. Die Live-Debug-Leiste bleibt aktiv (betrifft ausschließlich eingeloggte Administratoren).
* Security-Audit über alle PHP-/JS-Dateien durchgeführt. Gehärtet:
  * Explizite Längenbegrenzung aller POST-Felder im öffentlich erreichbaren Formular-Lead-Endpunkt (event_id, event_name, E-Mail, Telefon, source_url) sowie in LMPCT_CAPI::hash_email()/hash_phone() selbst – verhindert überdimensionierte Payloads, bevor Regex/Hashing/API-Call anfallen.
  * Live-Debug-Leiste: JSON-Payload wird jetzt mit JSON_HEX_TAG kodiert, damit eine Sequenz wie "</script>" in einer (externen) Meta-Fehlermeldung das umgebende Inline-Skript nicht vom HTML-Parser vorzeitig beenden lassen kann.
  * CAPI-Fehlermeldungen (rohe Antwort der Meta-API) werden vor der Protokollierung von HTML-Tags befreit.
  * Cookie-Werte, die per base64_decode()/json_decode() geparst werden (mhcookie, Borlabs-Cookie, Attribution-Cookie), werden ab 8 KB ungeparst verworfen (fail-closed) statt beliebig groß dekodiert zu werden – serverseitig und im JS-Consent-Bootstrap.
* Bestätigt (keine Änderung nötig): Alle Einstellungs-Speicherungen, AJAX-Endpunkte und der JSON-Import sind durchgehend mit Nonces und current_user_can('manage_options') abgesichert; der JSON-Import läuft bereits durch eine strikte Schlüssel-Whitelist (LMPCT_Settings::sanitize_settings()); alle Admin-Ausgaben sind mit esc_html/esc_attr/esc_url escaped; die Live-Debug-Leiste wird für nicht-eingeloggte Besucher serverseitig gar nicht erst registriert (0 Byte Overhead).

= 0.5.4 =
* UI-Refactoring: Tab „Erweitertes Tracking" nutzt jetzt dieselben aufklappbaren, blau akzentuierten Boxen wie die Plattformen im Tab „Allgemein". Formular-Auto-Grabber (Event-Typ, URL-Filter, Ausschluss-Filter) ist zu einer Box gebündelt, First-Touch/UTM-Weitergabe und Live-Debug-Leiste erhalten je eine eigene Box mit Master-Toggle.

= 0.5.3 =
* UX: Hinweis-Box im Tab „URL-Events" erklärt die Aufgabenteilung – URL-Events für Danke-/Bestätigungsseiten, Formular-Tracking für Formulare ohne Weiterleitung.
* UX: Ergänzter Hinweis beim Formular-Auto-Grabber, dass das Event beim Klick auf Absenden feuert und bei Weiterleitung auf eine Danke-Seite stattdessen ein URL-Event sinnvoll ist.
* Neu: Sanity-Check im Backend – ist für denselben Pfad sowohl eine aktive URL-Event-Regel als auch das Formular-Tracking mit demselben Event-Namen konfiguriert, erscheint eine gelbe Warnung (in beiden betroffenen Tabs) mit Hinweis auf mögliche Doppelzählungen.

= 0.5.2 =
* Bugfix (kritisch): Doppelte Formular-Events bei AJAX-Formularen (z. B. SureForms). Nativer Submit und AJAX-Completion-Handler erzeugten zwei Server-Events mit unterschiedlichen Event-IDs, sobald der Serverlauf länger als das bisherige 2-Sekunden-Fenster dauerte. Jetzt sperrt ein Lock pro Formular (`data-lmpct-submitted`, 5 Sekunden) jede weitere Auslösung – eine Formularinteraktion erzeugt garantiert genau eine Event-ID.
* Ergänzt: Erfolgs-Events von SureForms werden zusätzlich direkt ausgewertet; Erfolgs-Handler übergeben nun ihre Formular-Referenz, damit der Lock auch bei jQuery-basierten Plugins greift.

= 0.5.1 =
* Neu: Event-Typ für den Formular-Auto-Grabber wählbar („Lead" oder „Contact") – Support- und Kontaktanfragen lassen sich so sauber von echten Leads trennen.
* Neu: Optionaler URL-Filter (kommagetrennte Pfade). Auf nicht passenden Seiten wird das Frontend-Skript gar nicht erst ausgeliefert; der Filter wird zusätzlich serverseitig am AJAX-Endpunkt durchgesetzt.
* Neu: Ausschluss-Filter für Suche, Blog-Kommentare und Logins (Standard: aktiv). Formulare mit Passwortfeld werden grundsätzlich immer ignoriert.
* Härtung: Der vom Browser gemeldete Event-Name wird serverseitig gegen eine Whitelist geprüft und fällt sonst auf die konfigurierte Einstellung zurück.

= 0.5.0 =
* Neu (Feature 1): Formular-Auto-Grabber – erkennt Absendungen von Contact Form 7, Elementor Pro, Fluent Forms, WPForms, Gravity Forms und nativen HTML-Formularen, feuert „Lead" im Browser und via CAPI mit identischer Event-ID und übergibt E-Mail/Telefon SHA-256-gehasht.
* Neu (Feature 2): First-Touch- & UTM-Attribution im First-Party-Cookie `lmpct_attribution` (30 Tage) – UTM-Werte landen als `custom_data` im CAPI-Payload, eine gespeicherte fbclid wird ins `fbc`-Format übersetzt.
* Neu (Feature 3): Live-Debug-Konsole für Administratoren mit Consent-Status, Event-Stream, Event-IDs, CAPI-Statuscodes und Match-Keys – nur für `manage_options`, sonst 0 Byte Overhead.
* Neu (Feature 4): 1-Klick-Export und -Import der kompletten Konfiguration als JSON (nonce- und rechtegesichert, vollständig sanitized).
* Admin-Oberfläche in vier Tabs gegliedert: Allgemein, URL-Events, Erweitertes Tracking, Werkzeuge.
* Neue Filter: `lmpct_normalize_phone` (Telefon-Normalisierung vor dem Hashing).

= 0.4.3 =
* Bugfix (kritisch): Reale Cookie-Struktur von Must Have Plugins berücksichtigt. Das mhcookie speichert die Kategorien als Array unter "groups" (z. B. {"groups":["all"]} bei „Alle akzeptieren"), nicht als Marketing-/Advertisement-Schlüssel. Dadurch wurde volle Zustimmung bisher fälschlich blockiert. Die Erkennung prüft jetzt korrekt, ob "all", "marketing" oder "advertisement" im groups-Array enthalten ist – serverseitig und im JS-Bootstrap, verifiziert mit einem echten Live-Cookie.

= 0.4.2 =
* Bugfix (kritisch): Must Have Plugins Cookie Bar / GDPR Cookie Consent speichert die Entscheidung tatsächlich in einem Base64-kodierten JSON-Cookie namens "mhcookie", nicht in cookielawinfo-checkbox-*. Die Erkennung liest jetzt korrekt aus mhcookie (serverseitig und im JS-Bootstrap); explizit abgelehnte Marketing-/Advertisement-Kategorien blockieren weiterhin zuverlässig.

= 0.4.1 =
* Bugfix (kritisch): Strikte Consent-Prüfung für Must Have Plugins / GDPR Cookie Consent – „Nur erforderliche akzeptieren" (viewed_cookie_policy ohne Marketing-Kategorie) blockiert jetzt korrekt, serverseitig und im JS-Bootstrap. Zusätzlich wird cookielawinfo-checkbox-marketing unterstützt.
* Bugfix (kritisch): Globaler Init-Guard (window.lmpctInitialized) verhindert doppelte PageView-/Pixel-Events bei mehrfach feuernden Banner-Events oder doppeltem wp_head („Duplicate event within 2000ms").

= 0.4.0 =
* Neu: Intelligente Cookie-Consent-Erkennung (DSGVO, Standard: aktiv) – erkennt Must Have Plugins Cookie Bar, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, SureCookies und die WP Consent API; blockiert Browser- + CAPI-Events bis zur Einwilligung und startet das Tracking nach dem Banner-Klick ohne Reload. Ersetzt das bisherige text/plain-Script-Blocking.
* Neu: Test Event Code deaktiviert sich nach 12 Stunden automatisch (serverseitig, inkl. Aufräumen in der Datenbank).
* Neu: Toggles speichern sofort per AJAX (nonce-gesichert) mit dezenter Erfolgs-Meldung.
* Neu: CAPI-Toggle aktiviert sich automatisch beim Eingeben/Einfügen eines Access Tokens.
* Neue Filter: lmpct_has_marketing_consent, lmpct_consent_banner_active, lmpct_consent_events (ersetzt lmpct_capi_consent).

= 0.3.1 =
* Neu: Automatische Updates via GitHub Releases (plugin-update-checker v5, Release Assets).
* Neu: Unterseite „Info & Hilfe" mit Version, Support-Kontakt und Tutorial-Hinweis.
* Fix: Obere Toggle-Leiste im Events-Tab schließt jetzt bündig mit der Tabelle ab.
* Verbesserung: Beim Bearbeiten zeigt das Formular „Event ‚Name' bearbeiten".
* Hinweis: Versionszählung neu gestartet (0.x bis zum stabilen 1.0-Release).

= 2.0.2 =
* UI-Politur Events-Tab: Tabelle und Formular auf einheitliche Breite begrenzt, Formular-Card mit Titelleiste, Plattform-Zeilen als gruppierte Boxen, Controls nicht gewählter Plattformen abgedimmt (Fokus aktiviert die Plattform automatisch), aufgeräumter Leerzustand.

= 2.0.1 =
* UI-Politur: eigene Dashicons-Pfeile in den Plattform-Boxen, sauberere Header-Abstände, blauer Akzent für aktive Plattformen, deaktivierte Plattformen starten eingeklappt, Box öffnet sich beim Aktivieren des Master-Toggles, Tooltips neben den Feld-Labels.

= 2.0.0 =
* Neu: Google Ads (gtag.js) inkl. Google Consent Mode v2 Defaults.
* Neu: TikTok Pixel mit offiziellen Web-Events.
* Neu: Multi-Plattform-Events – pro Event frei wählbar: Meta-Event-Typ, Google Conversion Label, TikTok-Event.
* Neu: DSGVO-Script-Blocking-Modus (type="text/plain" + data-cookiecategory="marketing") inkl. CAPI-Consent-Gating.
* Neu: Aufklappbare Plattform-Boxen mit Master-Toggles und CSS-Info-Tooltips im WP-Design.
* Meta Graph API auf v26.0 aktualisiert (per Filter änderbar).
* Bestehende v1-Events werden automatisch als Meta-Events übernommen.

= 1.1.0 =
* Internationalisierung nach WordPress-Standard (POT + de_DE) – kompatibel mit Loco Translate.
* Robustheit: keine Abhängigkeit mehr von der mbstring-Extension.

= 1.0.0 =
* Erstveröffentlichung.
