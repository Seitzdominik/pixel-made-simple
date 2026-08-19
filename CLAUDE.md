# Pixel Made Simple — Projekt-Notizen

Diese Datei ist für einen neuen Chat gedacht, der an diesem Plugin weiterarbeiten
soll. Sie beschreibt den aktuellen Aufbau, zentrale Architektur-Entscheidungen
und – besonders wichtig – die Fallstricke, die in bisherigen Sessions mehrfach
Zeit gekostet haben. Der komplette Feature-Verlauf der Vor-Rebrand-Ära (v1.0.0
bis v0.5.7, damals als „Lightweight Meta Pixel & CAPI Tracker") steht in
[`src/readme.txt`](src/readme.txt) (Abschnitt „Changelog" → „Ältere Versionen").
Diese Datei hier verdoppelt das nicht, sondern beschreibt den **Ist-Zustand**
und **wie man produktiv weiterarbeitet**.

**Stand:** Version 0.6.2 (Rebrand-Relaunch als „Pixel Made Simple", vormals
„Lightweight Meta Pixel & CAPI Tracker" — die Session, die den Rebrand baute,
hatte die Version versehentlich auf 1.0.0 gesetzt; Dominik hat das im
Folgeauftrag auf 0.6.0 korrigiert, die Versionszählung der Vor-Rebrand-Ära
also bewusst fortgesetzt statt neu gestartet). WordPress-Plugin, Autor
Dominik Seitz (sdv.design), Support-Kontakt laut Info&Hilfe-Seite
`dominik@seitzdominik.de`.

**Wichtig für den nächsten Chat:** Eine frühere Session hat das Repo von
einem einzelnen Plugin-Ordner in ein Free/Pro-Monorepo umgebaut (siehe
unten), eine direkt folgende Session hat die Version auf 0.6.0 korrigiert und
die erste echte Free/Pro-Funktionstrennung eingebaut (Custom-Events-Limit,
UTM/Attribution + Export als Pro-Features), eine dritte Session hat in 0.6.1
das Event Log ergänzt (eigene DB-Tabelle, Retention-Cron, neuer Admin-Tab
unter `src/includes/admin/` — siehe „Event Log" weiter unten), eine vierte
Session hat in 0.6.2 den Kollisionsschutz von symmetrisch auf **absichtlich
asymmetrisch** umgebaut (Pro gewinnt immer, siehe „Free/Pro-Kollisionsschutz"
weiter unten), das Sidebar-Menü-Label auf „Pixel Made Simple" vereinheitlicht
(inkl. zweier bis dahin übersehener „Pixel Tracker"-Reste in der Live-Debug-
Leiste), Google Ads & TikTok zu Pro-only-Plattformen gemacht, das
Custom-Events-Limit von „2 gleichzeitig aktiv" auf „2 insgesamt" umgestellt
(siehe „Bekannte Trade-offs" für die Begründung dieser Interpretation) und
den JSON-Import zusätzlich zum Export hinter das Pro-Gate gesetzt, dazu
`composer.json`/`package.json` als dünne Wrapper um die bestehenden
`dev-tools/`-Skripte ergänzt (siehe „Build/Test/Package" weiter unten). Es
wurde bewusst **kein `git init` ausgeführt** und **kein GitHub-Repo
angelegt/gepusht** – das war nie Teil eines der vier Aufträge. Der alte Plugin-Ordner liegt unangetastet
als `_pre-migration-backup_lightweight-meta-pixel-capi-tracker/` neben `src/`
(zusammen mit der alten `lightweight-meta-pixel-capi-tracker.zip`) und kann
gelöscht werden, sobald Dominik den neuen Stand verifiziert hat. Die
`.github/workflows/release.yml` wurde nie gegen ein echtes GitHub-Repo
getestet (kein Tag wurde je gepusht) – vor dem ersten echten Release einmal
genau gegenlesen.

## Was das Plugin macht

Schlanker Ersatz für Bloat-Tracking-Plugins (PixelYourSite & Co.): Meta Pixel +
Conversions API, Google Ads (gtag.js, Consent Mode v2) und TikTok Pixel, dazu
URL-basierte Custom Events, ein Formular-Auto-Grabber, First-Touch/UTM-
Attribution, eine Admin-Live-Debug-Leiste, JSON-Export/Import und eine
DSGVO-Cookie-Consent-Erkennung, die die gängigen DACH-Banner-Plugins erkennt.
Kein jQuery, keine Frameworks im Frontend, reines Vanilla JS/PHP.

Seit dem v0.6.0-Rebrand als **Freemium** angelegt: **Pixel Made Simple**
(Free, Slug `pixel-made-simple`) wird über WordPress.org vertrieben, **Pixel
Made Simple Pro** (Slug `pixel-made-simple-pro`) ist ein separates, sich
selbst aktualisierendes Plugin. Beide teilen sich denselben Options-Key
(`pms_settings`) und denselben Übersetzungskatalog. Aktuelle Tier-Aufteilung
(Stand v0.6.0, siehe „Freemium-Feature-Gating" weiter unten für die
Umsetzung):

| Feature | Free | Pro |
|---|---|---|
| Meta Pixel + Conversions API | ✅ | ✅ |
| Google Ads (gtag.js, Consent Mode v2) | ❌ (Teaser) | ✅ |
| TikTok Pixel | ❌ (Teaser) | ✅ |
| URL-Events (Custom Events) | max. 2 **insgesamt** | unbegrenzt |
| Formular-Auto-Grabber | ✅ | ✅ |
| Cookie-Consent-Erkennung | ✅ | ✅ |
| Admin-Live-Debug-Leiste (Pixel & CAPI Health Checker) | ✅ | ✅ |
| Event Log (Tab „Event Log") | ✅ (3 Tage Aufbewahrung, keine Filter) | ✅ (3/7/14/30 Tage, Status-/Event-Filter) |
| First-Touch-/UTM-Attribution + Auto-Form-Fill | ❌ (Teaser) | ✅ |
| Konfiguration exportieren (JSON) | ❌ (Teaser) | ✅ |
| Konfiguration importieren (JSON) | ❌ (Teaser) | ✅ |

`src/pro/class-pro-features.php` (Klasse `PMS_Pro_Features`) ist weiterhin
ein leerer Erweiterungspunkt für zukünftige Pro-Features ohne eigene Datei;
`src/pro/class-pro-utm.php` (`PMS_Pro_UTM`) ist das erste tatsächlich
befüllte Pro-Modul.

---

## Verzeichnisstruktur

```
lightweight_meta_pixel_and_capi_tracker/          <- Projekt-Root (Monorepo)
├── CLAUDE.md                                     <- diese Datei
├── README.md                                     <- kurzer Repo-Überblick für GitHub
├── composer.json                                  <- dünner Wrapper: "test" -> dev-tools/test-suite.php
├── package.json                                   <- dünner Wrapper: "test"/"build" -> dev-tools/*.js|php
├── .gitignore
├── .github/
│   └── workflows/
│       └── release.yml                           <- baut bei jedem "vX.Y.Z"-Tag beide ZIPs
├── dev-tools/                                     <- NUR Entwicklung, wird NIE mitgezippt
│   ├── test-suite.php                             <- 245 Tests, kein WordPress nötig
│   ├── test-frontend-js.js                        <- JS-Pendant für src/assets/frontend.js
│   ├── build-translations.php                     <- POT/PO/MO-Generator + Validator
│   └── preview-admin.php                          <- rendert Admin-Tabs als HTML z. Betrachten
└── src/                                            <- Quelle beider Pakete (Free + Pro)
    ├── pixel-made-simple.php                       <- Free-Hauptdatei (PMS_IS_PRO = false)
    ├── pixel-made-simple-pro.php                    <- Pro-Hauptdatei (PMS_IS_PRO = true)
    ├── uninstall.php                                <- Free/Pro-aware, siehe unten
    ├── readme.txt                                   <- WP-Standard-readme inkl. vollem Changelog
    ├── includes/                                    <- geteilte Core-Logik (Free UND Pro)
    │   ├── class-pms-settings.php                   <- EINZIGE Quelle für Options-Schema
    │   ├── class-pms-logger.php                     <- Event Log: DB-Tabelle, Retention-Cron, CRUD
    │   ├── class-pms-consent.php                    <- DSGVO-Consent-Erkennung (PHP + JS-Mirror)
    │   ├── class-pms-capi.php                       <- Meta Conversions API, Hashing, Request-Log
    │   ├── class-pms-frontend.php                   <- Event-Matching, JS-Ausgabe aller 3 Plattformen
    │   ├── class-pms-forms.php                      <- nopriv-AJAX-Endpunkt für Formular-Grabber
    │   ├── class-pms-debug.php                      <- Live-Debug-Leiste (nur Admins)
    │   ├── class-pms-tools.php                      <- JSON-Export/Import
    │   ├── class-pms-admin.php                      <- Admin-UI-Kern (General/Events/Advanced/Tools
    │   │                                                + Hilfe-Seite, Tab-Dispatch, geteilte UI-Bausteine)
    │   └── admin/
    │       └── class-pms-admin-event-log.php        <- Tab "Event Log" (erster ausgelagerter Tab,
    │                                                    siehe "Event Log" weiter unten)
    ├── assets/
    │   ├── admin.css / admin.js                      <- Admin-UI (Accordions, Toasts, Tooltips)
    │   └── frontend.js                                <- Formular-Auto-Grabber + UTM-Form-Fill
    ├── languages/                                     <- POT + de_DE.po/.mo (Loco-Translate-kompatibel)
    ├── plugin-update-checker/                         <- VENDORED Library (YahnisElsts v5.7), nur
    │                                                      von der Pro-Hauptdatei geladen
    └── pro/
        ├── class-pro-features.php                     <- Erweiterungspunkt, aktuell leer
        └── class-pro-utm.php                          <- First-Touch/UTM-Attribution + Form-Fill (Pro-only)
```

**Wichtig:** `dev-tools/` liegt weiterhin bewusst **außerhalb** von `src/`,
damit interne Test-Skripte, absolute Pfade und Entwickler-Kommentare niemals
in einem der beiden ausgelieferten ZIPs landen. Alles, was tatsächlich in ein
Paket muss, gehört unter `src/` – die GitHub Action kopiert `src/` 1:1 (mit
ein paar Ausschlüssen, siehe unten) in die jeweiligen ZIPs.

---

## Architektur

### Bootstrap & Hook-Reihenfolge

Jede Hauptdatei (`src/pixel-made-simple.php` bzw. `src/pixel-made-simple-pro.php`)
lädt dieselben geteilten Klassen aus `includes/` und hängt drei Dinge an
WordPress-Hooks:

```
PMS_Attribution::init()   -> add_action('init', ..., 1)
PMS_Frontend::init()      -> add_action('wp', 'prepare', 20) + add_action('wp_head', 'print_scripts', 4)
PMS_Forms::init()         -> add_action('wp_ajax_pms_form_lead' / wp_ajax_nopriv_...)
PMS_Tools::init()         -> add_action('admin_post_pms_export_settings' / ..._import_settings)
PMS_Debug::init()         -> add_action('wp', ..., 5)   <-- Priorität 5!
```

**Warum Priorität 5 für `PMS_Debug::init()` kritisch ist:** Die Debug-Leiste
setzt bei aktivierter Anzeige den Filter `pms_capi_blocking` auf `true`,
damit der CAPI-Request für Admins blockierend läuft und echte HTTP-Statuscodes
zurückliefert. Das MUSS passieren, bevor `PMS_Frontend::prepare()` (Priorität
20 auf demselben `wp`-Hook) die CAPI tatsächlich abschickt. Wenn jemand diese
Prioritäten „aufräumt", ohne das zu wissen, zeigt die Debug-Leiste plötzlich
nur noch `⏳` statt echter Antworten.

### Free/Pro-Kollisionsschutz

Free und Pro sind zwei separate Plugins, die dieselben Klassen aus `includes/`
`require`n. Liefen beide im selben PHP-Prozess durch, würde WordPress
„Cannot redeclare class PMS_Settings" werfen. Der Schutz besteht aus zwei
Bausteinen, die zusammen jede Kollision sauber auflösen:

1. **`register_activation_hook`** deaktiviert beim Aktivieren automatisch die
   jeweils andere Variante (`deactivate_plugins()`).
2. **Early Guard** ganz oben in jeder Datei: Free bricht ab, wenn
   `PMS_IS_PRO === true` bereits definiert ist; Pro bricht ab, wenn
   `PMS_IS_PRO === false` bereits definiert ist (statt nur "ist überhaupt
   definiert" – so entscheidet die Ladereihenfolge, welche Variante in DIESEM
   Request gewinnt).

**Warum Punkt 2 nicht optional ist:** `register_activation_hook` greift erst,
NACHDEM die gerade aktivierte Datei bereits vollständig geladen wurde. Beim
**Bulk-Aktivieren beider Plugins in einem einzigen wp-admin-Request** (oder
einem Bulk-Update, das beide gleichzeitig aktualisiert) lädt WordPress ggf.
beide Hauptdateien im selben PHP-Prozess, bevor überhaupt eine
Aktivierungs-Hook-Deaktivierung greifen konnte. Ohne den Early Guard wäre das
ein Fatal Error; mit ihm bricht die zweite Datei sauber ab und zeigt einen
Admin-Notice. Dieser Bulk-Activate-Fall ist der eigentliche Grund für den
Guard, nicht nur Doppelt-Aktivierung durch Versehen.

**Seit v0.6.2 absichtlich ASYMMETRISCH, nicht mehr symmetrisch.** Die
ursprüngliche Fassung (v0.6.0/v0.6.1) ließ im Kollisionsfall jede Datei sich
selbst deaktivieren – funktional korrekt, aber mit einer Schwäche: Welche
Version am Ende übrig blieb, hing rein von der Lade-/Aktivierungsreihenfolge
ab, nicht von einer bewussten Regel. Seit v0.6.2 gilt stattdessen: **im
Kollisionsfall bleibt immer Pro aktiv, nie Free.**

- Frees Guard (`pixel-made-simple.php`) prüft `PMS_IS_PRO === true` (Pro ist
  schon geladen) und deaktiviert in diesem Fall **sich selbst**
  (`deactivate_plugins( plugin_basename( __FILE__ ) )`).
- Pros Guard (`pixel-made-simple-pro.php`) prüft `PMS_IS_PRO === false` (Free
  ist schon geladen) und deaktiviert **nicht sich selbst, sondern Free**
  (`deactivate_plugins( 'pixel-made-simple/pixel-made-simple.php' )`).

Der Grund für diese Asymmetrie: Lädt Free zuerst und Pro erkennt die
Kollision, kann Pro in DIESEM Request ohnehin nicht mehr weiterladen (Free
hat die geteilten Klassen bereits deklariert) – Pro deaktiviert deshalb Free,
sodass ab dem NÄCHSTEN Request nur noch Pro lädt, ganz normal ohne Guard.
Würde Pro stattdessen sich selbst deaktivieren (wie in der alten
symmetrischen Fassung), gewänne im Kollisionsfall immer die zuerst geladene
Datei – bei alphabetischer Bulk-Aktivierung typischerweise Free, was der
Produktlogik (Pro ist ein Upgrade, kein gleichwertiges Sibling) widerspricht.
Lädt umgekehrt Pro zuerst und Free erkennt die Kollision, deaktiviert Free
direkt sich selbst – Pro läuft in diesem Fall bereits vollständig im selben
Request weiter, ganz ohne Verzögerung auf den nächsten Request.

Beide Zweige zeigen dieselbe Admin-Notice
(„Pixel Made Simple Pro is already active. The free version is not needed
and has been deactivated automatically.") – bewusst wortgleich in beiden
Dateien, damit der Text nicht zweimal separat gepflegt werden muss und immer
konsistent bleibt, unabhängig davon, welche der beiden Dateien ihn gerade
ausgibt.

### Geteilter Options-Key & Text-Domain (Freemium-Datenmodell)

Free und Pro nutzen denselben Options-Key `pms_settings` (siehe
`PMS_Settings::OPTION_SETTINGS`), damit ein Upgrade von Free auf Pro (oder ein
Downgrade) die bestehende Konfiguration nicht verliert. `uninstall.php` ist
deshalb **Free/Pro-aware**: Sie läuft unverändert in beiden ZIPs mit und nutzt
die von WordPress gesetzte Konstante `WP_UNINSTALL_PLUGIN` (= Basename des
gerade deinstallierten Plugins), um zu bestimmen, welches der beiden das
JEWEILS ANDERE ist – die gemeinsamen `pms_*`-Optionen werden nur gelöscht,
wenn dieses andere Plugin nicht mehr auf der Platte liegt. Kommt ein weiterer
Options-Key dazu, der Free/Pro-übergreifend gilt, gehört er in die
`$pms_known_plugins`-Prüfung in `src/uninstall.php`.

Beide Hauptdateien laden außerdem denselben Text-Domain-String
(`pixel-made-simple`, siehe `load_plugin_textdomain()` in beiden Dateien) aus
demselben `languages/`-Ordner – bewusst NICHT `pixel-made-simple-pro` für Pro,
sonst bräuchte Pro einen zweiten, separat zu pflegenden Übersetzungskatalog
für exakt dieselben UI-Strings. Der Plugin-*Slug* (Ordner-/Datei-Name) bleibt
trotzdem pro Paket eigenständig.

### Freemium-Feature-Gating (seit v0.6.0)

Drei Bausteine, die zusammen jedes Free/Pro-Feature absichern. Neue
Pro-only-Features sollten demselben Muster folgen.

1. **Ladeebene (schwerste Gating-Form):** Ein Feature, das komplett Pro-only
   ist (wie UTM/Attribution), lebt als eigene Klasse unter `src/pro/` und
   wird **nur** von `pixel-made-simple-pro.php` per `require_once`
   eingebunden – die Free-Hauptdatei kennt die Datei gar nicht. Jede geteilte
   Klasse, die optional mit so einer Pro-Klasse zusammenspielt, greift
   ausschließlich über `class_exists( 'PMS_Pro_Xyz' )` darauf zu und
   degradiert bei `false` auf "Feature einfach nicht vorhanden" statt zu
   fataln (Beispiel: `PMS_CAPI::send_events()`/`PMS_Debug::payload()`/
   `PMS_Frontend::enqueue_frontend()` prüfen alle `class_exists( 'PMS_Pro_UTM' )`,
   bevor sie `PMS_Pro_UTM::custom_data()`/`::fbc()`/`::form_fill_enabled()`
   aufrufen). Dieses Muster existierte schon vor v0.6.0 (ursprünglich nur zur
   Absicherung der Require-Reihenfolge) und ließ sich dadurch nahtlos als
   Free/Pro-Gate weiterverwenden, ohne die drei Aufrufer-Klassen anzufassen.

2. **Mengen-/Nutzungs-Limits (z. B. Custom Events):** `PMS_Settings::is_pro()`
   liest `PMS_IS_PRO` **defensiv** (`defined() && true === PMS_IS_PRO`), NIE
   nackt – `dev-tools/test-suite.php` lädt die Klassen ohne eines der beiden
   Bootstrap-Files, dort ist die Konstante bis zum letzten Testabschnitt
   absichtlich undefiniert (siehe „Tests ausführen" unten). Der eigentliche
   Cap sitzt an der **einen** Stelle, an der Daten tatsächlich persistiert
   werden (`PMS_Settings::save_events()`, deckt sowohl die Admin-UI-Handler
   als auch `PMS_Tools::import_from_json()` ab) – das ist dieselbe
   Single-Source-of-Truth-Idee wie `sanitize_settings()` für die Einstellungen.
   `PMS_Settings::free_event_limit_reached()` ist der parameterlose
   Vorab-Check für die Admin-UI (schönere Fehlermeldung statt stillem Cap) –
   seit v0.6.2 zählt er **alle** gespeicherten Events (`FREE_EVENT_LIMIT = 2`),
   unabhängig vom `active`-Status. Siehe „Bekannte Trade-offs" für die
   Begründung, warum das Limit auf Gesamtzahl statt aktive Zahl umgestellt
   wurde, und für den entfallenen `is_event_locked()`/
   `render_locked_event_toggle()`-Mechanismus der Vorgängerversion.
3. **Reine Feature-Sichtbarkeit (UI-Teaser, z. B. UTM-Tab, Export/Import,
   Google Ads, TikTok):** `PMS_Admin::render_pro_teaser_box()` ersetzt eine
   normale Accordion-/Card-Box 1:1 durch eine ausgegraute Variante mit
   Schloss-Icon, „Pro"-Badge und „Upgrade to Pro"-Button
   (`PMS_Admin::upgrade_url( $feature_slug )`, verlinkt auf pixelmadesimple.com
   mit `utm_campaign = $feature_slug`, z. B. `google-ads`/`tiktok`/`import`/
   `events-limit`, für Dominiks eigene Analytics – jeder Teaser/jede
   Limit-Meldung bekommt einen eigenen, unterscheidbaren Slug). Serverseitig
   zusätzlich abgesichert (z. B. `PMS_Tools::handle_export()` UND seit v0.6.2
   `PMS_Tools::handle_import()` prüfen `PMS_Settings::is_pro()` selbst und
   brechen mit `wp_die()` ab, falls doch jemand direkt auf den
   `admin-post.php`-Endpunkt POSTet; `PMS_Frontend::google_active()`/
   `::tiktok_active()` prüfen `is_pro()` unabhängig von der Admin-UI, siehe
   „Google Ads & TikTok sind seit v0.6.2 Pro-only" weiter unten) –
   UI-Ausblenden allein ist nie die einzige Absicherung, exakt dasselbe
   Defense-in-Depth-Prinzip wie beim CAPI-Sicherheitsmodell weiter unten.

**Fallstrick, der beim UTM-Teaser aufgetreten ist:** Der Tab „Erweitertes
Tracking" ist EIN `<form>`, das beim Speichern den kompletten Settings-Array
durch `sanitize_settings()` schickt (siehe `preserve_hidden_settings()`-
Fallstrick oben). Zeigt die Free-Version für `utm_passthrough` &Co. keine
echten `<input>`-Felder mehr an (weil der Teaser statt der Accordions
rendert), MÜSSEN diese vier Keys jetzt dort per Hidden-Feld erhalten bleiben
– sonst würde das Speichern des sichtbaren Tab-Rests (form_tracking,
debug_bar) eine unter Pro bereits gesetzte UTM-Konfiguration bei einem
Downgrade auf Free stillschweigend auf 0/leer zurücksetzen. Der Skip-Array
für `preserve_hidden_settings()` in `render_advanced_tab()` ist deshalb
`PMS_Settings::is_pro()`-abhängig aufgebaut (die vier UTM-Keys nur dann
ausschließen, wenn Pro tatsächlich die echten Felder rendert). Dasselbe
Prinzip gilt für jedes künftige Feature, das mal als echtes Formularfeld, mal
als Teaser ohne Feld auf demselben Tab landet. Seit v0.6.2 gilt derselbe
Fallstrick auch auf Tab „Allgemein": `render_general_tab()`s Skip-Array für
`preserve_hidden_settings()` schließt `google_enabled`/`google_tag_id`/
`google_consent_mode`/`tiktok_enabled`/`tiktok_pixel_id` nur dann aus, wenn
`is_pro()` die echten Accordions statt der beiden Teaser-Boxen rendert –
exakt dieselbe Downgrade-Falle wie beim UTM-Tab, nur mit Google/TikTok statt
Attribution als betroffene Felder.

### Google Ads & TikTok sind seit v0.6.2 Pro-only

Anders als UTM/Export/Import (reine Admin-UI-/Persistenz-Gates) greift dieses
Gate zusätzlich zur Admin-UI auch in der **Frontend-Skript-Ausgabe** – ohne
diesen zweiten Guard könnte ein Downgrade von Pro auf Free eine zuvor unter
Pro gespeicherte `google_tag_id`/`tiktok_pixel_id` weiter ausliefern, obwohl
die Admin-UI die Felder gar nicht mehr anzeigt (Settings bleiben ja erhalten,
siehe „Geteilter Options-Key" oben). Drei Stellen in `class-pms-frontend.php`
sind deshalb betroffen:

- `google_active()`/`tiktok_active()` (beide `private`) bekamen ein
  zusätzliches `PMS_Settings::is_pro() &&` vor die bestehende
  Enabled-/ID-vorhanden-Prüfung.
- `should_track()` berechnet VOR der eigentlichen Platform-Auswertung ein
  eigenes, separates `$any_platform` (Frage: "gibt es überhaupt einen Grund,
  das Skript zu laden?"), weil es läuft, bevor `self::$settings` befüllt ist
  – kann also `google_active()`/`tiktok_active()` selbst noch nicht
  aufrufen. Diese zweite, unabhängige Kopie der Google/TikTok-Bedingung
  brauchte deshalb denselben `is_pro()`-Zusatz **separat** – ein reines
  Gating von `google_active()`/`tiktok_active()` allein hätte das Skript in
  Free weiterhin geladen (nur eben ohne Google/TikTok-Ausgabe), sobald
  `google_enabled`/`tiktok_enabled` aus einer früheren Pro-Phase noch `1`
  waren.
- Admin-seitig zeigt `render_general_tab()` (siehe oben) beide Plattformen in
  Free als `render_pro_teaser_box()`-Karten statt der echten Accordions.

**Merke für jedes weitere Pro-only-Platform-Feature:** Reicht ein einzelner
`is_pro()`-Check in der „normalen" Aktiv-Prüfung nicht aus, wenn irgendwo im
Code – wie hier bei `should_track()` – aus Timing-Gründen eine zweite,
unabhängige Kopie derselben Bedingung existiert. Beide Stellen suchen
(`grep` nach dem Setting-Key hilft), nicht nur die naheliegendste.

### Event Log (seit v0.6.1)

Eigene Custom Table `{$wpdb->prefix}pms_event_log` (nicht `wp_options`) für
eine rollierende Historie von Browser-/CAPI-Events, sichtbar im neuen
Admin-Tab „Event Log". Kernentscheidungen, die für künftige Änderungen
wichtig sind:

**Tabellen-Erstellung ist zweifach abgesichert.** `PMS_Logger::activate()`
hängt an einer eigenen, unabhängigen `register_activation_hook()`-Registrierung
in beiden Hauptdateien (WordPress erlaubt mehrere pro Datei) und legt die
Tabelle sofort per `dbDelta()` an. Zusätzlich prüft `maybe_upgrade_table()`
bei jedem `plugins_loaded` eine gespeicherte Schema-Version
(`pms_log_db_version`) und holt `dbDelta()` nach, falls sie nicht zu
`PMS_Logger::DB_VERSION` passt – deckt den in der Praxis häufigen Fall ab,
dass ein Update eingespielt wird, ohne das Plugin zu deaktivieren/aktivieren
(wofür `register_activation_hook` sonst nie feuert). Der Versionsvergleich
läuft aber NICHT bei jedem Request in `dbDelta()` selbst, nur der günstige
Options-Abgleich.

**Retention-Cron wird absichtlich NIE bei Deaktivierung entfernt.** Ein
naheliegender `register_deactivation_hook()` mit `wp_clear_scheduled_hook()`
wäre hier eine Falle: Pro aktivieren deaktiviert Free automatisch
(`deactivate_plugins()`, siehe „Free/Pro-Kollisionsschutz" oben) – das würde
SYNCHRON Frees Deactivation-Hook auslösen, noch während Pros eigener
Activate-Hook läuft. Ein dort unbedingtes `wp_clear_scheduled_hook()` liefe
Pros gerade erst eingeplantem Cron in die Quere, abhängig von der
Ausführungsreihenfolge der beiden Hooks. Der Cron bleibt deshalb bei bloßer
Deaktivierung stehen (ein eingeplanter Hook ohne registrierten Listener ist
harmlos, `do_action()` auf einen Hook ohne Listener ist ein No-Op) und wird
stattdessen in `uninstall.php` per `wp_clear_scheduled_hook()` entfernt –
genau dort, wo auch die Tabelle selbst gedroppt wird (beides nur, wenn keine
der beiden Varianten mehr installiert ist, siehe „Geteilter Options-Key"
oben).

**`http_status = 0` hat zwei mögliche Bedeutungen, unterschieden durch
`error_message`.** Der Standard-Sendemodus ist nicht-blockierend
(`pms_capi_blocking`-Filter, siehe CAPI-Sicherheitsmodell) – `wp_remote_post()`
wartet die echte Antwort gar nicht erst ab, um die Ladezeit nicht zu
beeinflussen. Für den GROSSTEIL aller geloggten CAPI-Zeilen ist der reale
HTTP-Status also schlicht nicht bekannt. `PMS_CAPI::log()` protokolliert
diesen Fall trotzdem (Status `sent`) mit `http_status = 0` UND leerer
`error_message` – das UI zeigt dafür ein neutrales „Gesendet"-Badge, nicht
Rot. Ein ECHTER Fehler (Verbindungsfehler vor jeder Antwort) landet ebenfalls
mit `http_status = 0`, aber MIT gefüllter `error_message` – das UI
unterscheidet also nicht anhand des Codes, sondern zuerst anhand von
`error_message` (nicht leer → Rot, sonst 2xx → Grün, sonst neutral). Nur wer
die Live-Debug-Leiste aktiviert hat, sendet blockierend und bekommt echte
2xx/4xx/5xx-Codes in beide Logs (In-Memory-Log der Debug-Leiste UND
persistentes Event Log).

**`PMS_CAPI::log()` ist gleichzeitig die Quelle für zwei verschiedene Logs.**
Die Methode existierte schon vor v0.6.1 für das request-lokale
`self::$log`-Array (Live-Debug-Leiste, siehe CAPI-Sicherheitsmodell). Seit
v0.6.1 nimmt sie zusätzlich das VOLLE Event-Array entgegen (vorher nur
Namen als Strings) und schreibt pro Event einen `PMS_Logger::record()`-
Aufruf – aber NUR bei den Status `sent`/`ok`/`error` (ein echter
`wp_remote_post()`-Versuch fand statt). `consent_blocked`/`skipped` werden
bewusst NICHT persistiert – das sind Gründe, warum nicht getrackt wurde,
keine Events, die im Event Log auftauchen sollen (reine Rauschunterdrückung,
sonst würde fast jeder Seitenaufruf ohne Consent eine Zeile erzeugen).

**Browser-Dispatch-Bestätigung existiert nur für den Formular-Grabber, NICHT
für URL-Events.** `send_events()` hat einen optionalen fünften Parameter
`$browser_confirmed` (Default `false`), der die geloggte `source` zwischen
`capi` und `both` umschaltet. `class-pms-forms.php` kann ihn befüllen, weil
`frontend.js` im selben AJAX-Request, der die CAPI sendet, ein
`browser_fired`-Feld mitschickt (`typeof window.fbq === 'function'` zum
Zeitpunkt des Sendeversuchs). Für URL-Events (`class-pms-frontend.php`) gibt
es dagegen KEINEN Browser→Server-Rückkanal – der Browser-Pixel feuert dort
rein clientseitig per Inline-`fbq()`-Aufruf ohne jeden Request. Diese Zeilen
werden deshalb immer nur mit `source = 'capi'` geloggt, auch wenn der
Browser-Pixel tatsächlich mitgefeuert hat. Das wäre nur mit einer neuen
Beacon-basierten Bestätigung lösbar (nicht gebaut – expliziter Scope-Cut,
siehe „Bekannte Trade-offs").

**Erster Tab in `includes/admin/`.** `class-pms-admin.php` ist mit vier
Tabs + Hilfe-Seite bereits sehr groß; das Event Log ist bewusst NICHT als
weitere `render_*_tab()`-Methode dort hineingewachsen, sondern als eigene
Klasse `PMS_Admin_Event_Log` unter dem neuen Ordner `includes/admin/`. Sie
bindet sich nur über einen zusätzlichen `case 'log':` in
`PMS_Admin::render_page()`s Tab-Switch ein und registriert ihre eigenen
Hooks über ein eigenes `init()` (aufgerufen direkt neben `PMS_Admin::init()`
in beiden Bootstrap-Dateien) statt über `PMS_Admin::init()` mit-registriert
zu werden. Dafür mussten `PMS_Admin::tip()`/`::upgrade_url()`/
`::render_pro_teaser_box()` von `private` auf `public` wechseln, damit der
neue Tab dieselben UI-Bausteine wiederverwendet statt sie zu duplizieren.
**Präzedenzfall für künftige Tabs:** Wächst ein weiterer Tab über eine
einzelne `render_*_tab()`-Methode hinaus, gehört er nach demselben Muster in
eine eigene Datei unter `includes/admin/`, nicht zurück in
`class-pms-admin.php`.

**Free-Gating im Event-Log-Tab ist "sichtbar, aber gesperrt", nicht
"ausgeblendet".** Anders als die UTM-/Export-Teaser (ganze Box durch
`render_pro_teaser_box()` ersetzt) bleiben Status-/Event-Filter und die
Retention-Auswahl im Free-Markup vorhanden, nur mit `disabled` gerendert
(`PMS_Admin::tip()` daneben erklärt warum). `PMS_Admin_Event_Log::render_tab()`
ignoriert `$_GET['log_status']`/`$_GET['log_event']` in Free zusätzlich
serverseitig, selbst bei manipulierter URL – dasselbe Defense-in-Depth-Prinzip
wie überall sonst in diesem Plugin.

**`PMS_Settings::ALLOWED_LOG_RETENTION_DAYS`/`FREE_LOG_RETENTION_DAYS`/
`DEFAULT_LOG_RETENTION_DAYS` leben in `PMS_Settings`, nicht in `PMS_Logger`.**
Bewusste Entscheidung, die Abhängigkeitsrichtung zur „Settings ist die
Schaltzentrale"-Regel konsistent zu halten (siehe unten) – `PMS_Logger`
konsumiert diese Konstanten nur.

### Die Settings-Klasse ist die Schaltzentrale

`class-pms-settings.php` ist die **einzige** Stelle, die weiß, welche
Options-Keys existieren. Zwei Methoden sind entscheidend:

- `get()` — liefert Defaults (`wp_parse_args`) für jeden bekannten Key.
- `sanitize_settings( $input )` — baut ein **komplett neues Array** nur aus
  bekannten, typgeprüften Keys. Alles, was im `$input` sonst noch drinsteht,
  wird stillschweigend verworfen. Das ist die Whitelist, die auch den
  JSON-Import (`PMS_Tools::import_from_json()`) absichert.

**Regel, wenn ein neuer Setting-Key dazukommt:** An **drei** Stellen ergänzen,
sonst gibt es kaputte UI-States:

1. Default in `PMS_Settings::get()`
2. Sanitize-Zeile in `PMS_Settings::sanitize_settings()`
3. In der Exclude-Liste von `PMS_Admin::preserve_hidden_settings()` **in
   jedem Tab, der das Feld NICHT selbst anzeigt**

Punkt 3 ist der Fallstrick: Jeder Admin-Tab ist ein eigenes `<form>`, das beim
Absenden den **kompletten** Options-Array durch `sanitize_settings()` schickt.
Felder, die auf diesem Tab kein sichtbares `<input>` haben, werden deshalb als
verstecktes `<input type="hidden">` mitgeschickt (`preserve_hidden_settings()`).
Vergisst man das für einen neuen Key, setzt das Speichern eines *anderen* Tabs
diesen Key stillschweigend auf `0` zurück. (Der CAPI-Token ist die eine
bewusste Ausnahme: siehe `array_key_exists( 'capi_token', $input )` in
`sanitize_settings()` – er bleibt erhalten statt geleert, wenn der Schlüssel
im POST komplett fehlt, weil er nur auf Tab „Allgemein" ein echtes Feld hat.
Kommt ein weiterer besonders sensibler Key dazu, ist das hier das Muster.)

### Deduplizierungs-Pattern (Browser ↔ CAPI)

Für jedes Event wird **serverseitig einmal** `wp_generate_uuid4()` aufgerufen
und identisch verwendet als:
- `eventID` im `fbq('track', ..., {eventID: ...})`-Aufruf (Browser)
- `event_id` im Conversions-API-Payload (Server)

Meta dedupliziert dann automatisch. Das gilt für URL-Events
(`class-pms-frontend.php`) genauso wie für den Formular-Grabber
(`class-pms-forms.php` – dort generiert `frontend.js` die UUID **client-
seitig** per `crypto.randomUUID()` und schickt sie im AJAX-Request mit, damit
Browser-Event und Server-Event exakt dieselbe ID tragen).

### Consent-Gating ist zweifach implementiert — und muss synchron bleiben

`PMS_Consent::has_marketing_consent()` (PHP) entscheidet serverseitig, ob
die CAPI überhaupt einen Request absetzt. `PMS_Consent::consent_check_js()`
(PHP-Methode, die einen **JavaScript-Funktionskörper als String** zurückgibt)
spiegelt exakt dieselbe Cookie-Logik im Browser, damit das Tracking ohne
Seiten-Reload startet, sobald der Besucher im Banner auf „Akzeptieren" klickt.

**Wenn ein neues Cookie-Banner-Format unterstützt werden soll:** Beide Stellen
gemeinsam anpassen (`evaluate()` in PHP UND `consent_check_js()` in JS) – in
der Vor-Rebrand-Ära hat das Vergessen dieses Abgleichs zu drei Bugfix-Runden
für dasselbe Cookie-Format geführt (siehe „Ältere Versionen" in
`src/readme.txt`, v0.4.1 → v0.4.3). Die `evaluate()`-Methode ist ein
Waterfall mit 11 Fällen; der wichtigste Grundsatz: **kein erkanntes
Banner-Plugin → Consent gilt als erteilt** (sonst würden Websites ohne
Cookie-Banner grundlos blockiert). Die von `evaluate()` erkannten
Drittanbieter-Cookie-Namen (`mhcookie`, `borlabs-cookie`,
`cookielawinfo-checkbox-*`, `CookieConsent`, `cmplz_marketing`, …) gehören
**nicht** zum Plugin-eigenen Namensraum und werden deshalb NIE umbenannt,
egal welchen Prefix das Plugin selbst gerade trägt.

### Init-Guard gegen doppelte Events

Zwei Ebenen, beide wichtig:

1. **Global pro Seitenaufruf:** `window.pmsInitialized` (gesetzt in
   `class-pms-frontend.php`s `print_scripts()`) verhindert, dass Pixel-Init
   und PageView zweimal laufen, falls `wp_head` doppelt rendert oder mehrere
   Banner-Events kurz hintereinander feuern.
2. **Pro Formular:** `form.dataset.pmsSubmitted` mit 5-Sekunden-Lock
   (`assets/frontend.js`, `lockForm()`/`isLocked()`). Nötig, weil AJAX-
   Formulare (SureForms etc.) sowohl den nativen `submit` als auch einen
   Completion-Event feuern, oft mehrere Sekunden auseinander.

### CAPI-Sicherheitsmodell

- Alle POST-Felder im `nopriv`-AJAX-Endpunkt (`class-pms-forms.php`) werden
  **vor** jeder Verarbeitung hart längenbegrenzt (255/2000/64 Zeichen), nicht
  erst nach dem Sanitizing.
- `PMS_CAPI::hash_email()`/`hash_phone()` kappen zusätzlich selbst
  (Defense-in-Depth, da beide `public static` sind).
- Cookie-Werte, die per `json_decode()`/`base64_decode()` geparst werden
  (`mhcookie`, Borlabs, das eigene Attribution-Cookie), werden ab 8 KB
  ungeparst verworfen — serverseitig **und** im JS-Bootstrap.
- Die Live-Debug-Leiste kodiert ihren JSON-Payload mit `JSON_HEX_TAG` (siehe
  `class-pms-debug.php::render()`), weil dort auch die **rohe Fehlerantwort
  der Meta-API** landet – eine externe, nicht kontrollierte Quelle. Ohne die
  Flags könnte eine `</script>`-Sequenz darin das umgebende Inline-Skript vom
  HTML-Parser vorzeitig beenden lassen.
- Rohe E-Mail/Telefon-Werte existieren nur RAM-lokal zwischen Empfang und
  Hashing in `handle_lead()` – sie werden nie in `wp_options` gespeichert,
  nie geloggt, nie in einer Response zurückgegeben.

### Admin-UI-Pattern: Accordion-Boxen

Sowohl die Plattform-Boxen (Tab „Allgemein") als auch die Feature-Boxen (Tab
„Erweitertes Tracking") nutzen dasselbe Muster:
`PMS_Admin::accordion_open( $title, $toggle_name, $toggle_checked,
$toggle_label, $autosave_key, $tip_text )` … Inhalt … `accordion_close()`.
Aktive Boxen bekommen einen blauen Akzent-Rand (`pms-on`), inaktive starten
eingeklappt (`closed`). Der optionale 6. Parameter (`$tip_text`) hängt einen
Hover-Tooltip an den Box-Titel.

Einzelne Settings-Toggles speichern **sofort per AJAX** (kein Page-Reload):
`data-pms-autosave="<key>"` auf dem Checkbox-Input plus der
`wp_ajax_pms_save_toggle`-Handler in `class-pms-admin.php`
(`handle_toggle_autosave()`, eigene Whitelist erlaubter Keys, getrennt von
`sanitize_settings()`). Textfelder (Pixel-ID, Token …) laufen weiterhin über
den normalen „Einstellungen speichern"-Button.

Seit v0.6.0 gibt es daneben ein **nicht-interaktives** Box-Pattern für
Pro-Teaser (`postbox pms-pro-teaser`, siehe
`PMS_Admin::render_pro_teaser_box()`): bewusst NICHT mit `pms-accordion`
kombiniert, damit admin.js' generischer Accordion-Klick-Handler nicht
versehentlich eine "gesperrte" Box ein-/ausklappbar macht, wo es nichts zum
Auf-/Zuklappen gibt.

**Entfallen seit v0.6.2:** Es gab bis v0.6.1 ein drittes Pattern, einen
gesperrten Event-Status-Toggle (`pms-toggle pms-toggle-locked`,
`PMS_Admin::render_locked_event_toggle()` + zugehöriges CSS) für das damalige
„2 aktive Events"-Limit. Mit der Umstellung des Custom-Events-Limits auf
Gesamtzahl (siehe „Bekannte Trade-offs") gibt es keinen Zustand mehr, in dem
ein einzelnes Event isoliert gesperrt werden müsste – Methode, Aufrufer und
das zugehörige CSS wurden komplett entfernt statt als totes Pattern stehen zu
bleiben. Der Status-Toggle in der Events-Tabelle ist seitdem in Free wie Pro
ein normaler, funktionierender `toggle()`; das Gating sitzt stattdessen
ausschließlich am „Event hinzufügen"-Button im Formular darunter (siehe
„Bekannte Trade-offs" weiter unten für das aktuelle Limit-Modell).

### `frontend.js` trägt zwei unabhängige Features

`src/assets/frontend.js` lädt sowohl für den Formular-Auto-Grabber als auch
für den **UTM-Form-Fill** (schreibt Source/Campaign/Medium in passende
Formularfelder). Beide haben eigene Master-Toggles (`form_tracking` /
`enable_utm_form_fill`) und teilen sich dieselbe Datei; das lokalisierte
Settings-Objekt heißt `window.pms_settings`.

**Wichtig für Änderungen an `frontend.js`:** Die komplette Formular-Grabber-
Logik (die `document.addEventListener(...)`-Registrierungen) läuft weiterhin
unconditional beim Laden des Skripts — der eigentliche Ausschalter sitzt
bewusst **zentral in `handleFormSubmit()`**
(`if ( ! cfg.formTracking ) { return; }`, ganz am Anfang der Funktion, VOR
`isLocked()`/`lockForm()`), nicht als Wrapper um die vier Listener-Blöcke.
Grund: Jeder tatsächlich auslösende Pfad (nativer Submit, CF7, Fluent Forms,
WPForms, Gravity Forms, SureForms, jQuery-Events) läuft am Ende durch
`handleFormSubmit()` — ein einziger Guard dort deckt alle ab. Der
UTM-Form-Fill sitzt als eigener, unabhängiger `if ( cfg.utmFormFill ) { ... }`-
Block direkt danach (eigene Funktionen: `utmFormFillAllowed()`,
`resolveAttribution()`, `findAttributionField()`, `fillAttributionField()`).

Für den Enqueue gilt seit jeher nur: Ist Tracking aktiv UND ist mindestens
einer der beiden Master-Toggles eingeschaltet — Punkt. Die URL-Auswertung
(all/include/exclude, Wildcards) übernehmen ausschließlich `urlAllowed()`
bzw. `utmFormFillAllowed()` in `frontend.js` selbst, anhand des vom Browser
bereits aufgelösten Pfads (nicht serverseitig aus `REQUEST_URI`
rekonstruiert) — bei URL-Matching, das Server und Client unabhängig
voneinander berechnen, im Zweifel dem Client vertrauen, der seine eigene
tatsächlich aufgelöste URL zuverlässiger kennt als der Server sie aus
Kopfzeilen rekonstruieren kann (Trailing Slashes, Proxies/CDNs,
mehrsprachige Präfixe).

### Test Event Code bleibt CAPI-only (NICHT im Browser-Pixel)

Meta's Pixel-SDK akzeptiert `test_event_code` **nicht** als `custom_data`-Feld
in `fbq()`-Aufrufen (Browser) – ein so markiertes Event wird im Test-Events-
Stream schlicht ignoriert statt dort zu erscheinen. `test_event_code` taucht
deshalb an keiner Stelle in `PMS_Frontend::build_meta_js()` oder
`frontend.js` auf und bleibt ausschließlich Teil der serverseitigen
CAPI-Payload in `PMS_CAPI::send_events()`
(`PMS_CAPI::active_test_event_code()` ist `private`, nur intern gebraucht).
**Lektion:** Bei Meta-/Plattform-API-Verhalten, das nicht 100%ig durch
offizielle, aktuelle Doku belegt ist, im Zweifel gegen echte Testdaten aus dem
Events Manager prüfen, bevor es als „Bugfix" gilt (ein früherer Versuch, den
Code auch ins Browser-Pixel einzuschleusen, wurde genau deshalb wieder
zurückgebaut).

### gclid wie fbclid behandelt (First-Touch-Cookie, NICHT im CAPI-Payload)

`PMS_Attribution` persistiert auch `gclid` im `pms_attribution`-Cookie, mit
denselben Last-Touch-Regeln wie `fbclid` (`$click_ids`-Array in der Klasse).
Anders als `utm_source` & Co. taucht `gclid` **nicht** in `custom_data()` auf
– ein Google-Click-Identifier hat in der Meta Conversions API nichts
verloren. Es gibt keine serverseitige Google-Ads-API-Anbindung, die `gclid`
sonst irgendwo bräuchte.

Der UTM-Form-Fill hat kein eigenes `.gclid`-Formularfeld (Feld-Scope auf
Source/Campaign/Medium reduziert) — `gclid` bleibt aber als **Signal** für
die Source-Herleitung relevant (`frontend.js`s `resolveAttribution()`: kein
`utm_source` gefunden, aber `gclid` in Query oder Cookie vorhanden → Source =
`google`). Die PHP-seitige Cookie-Persistenz bleibt dafür nötig, sonst hätte
der Cookie-Fallback für diese Herleitung auf Unterseiten nichts zum Lesen.

### GitHub-Auto-Updates (nur Pro)

`plugin-update-checker/` ist die vendorte Library **YahnisElsts/plugin-
update-checker v5.7** – nicht von Hand anfassen, bei Bedarf komplett neu von
GitHub laden. **Nur `pixel-made-simple-pro.php` lädt und initialisiert sie** —
die Free-Version wird über WordPress.org aktualisiert und darf keinen
zweiten Update-Mechanismus registrieren (die GitHub-Action entfernt
`plugin-update-checker/` deshalb auch komplett aus dem Free-ZIP, siehe
unten).

Repo-URL im Pro-Bootstrap: `https://github.com/Seitzdominik/pixel-made-simple/`.
Da ein einzelnes GitHub-Release **beide** ZIPs als Assets trägt
(`pixel-made-simple.zip` und `pixel-made-simple-pro.zip`), reicht der reine
Slug-Parameter an `buildUpdateChecker()` nicht, um das richtige Asset zu
finden — `enableReleaseAssets()` bekommt deshalb einen Regex-Filter
(`'/^pixel-made-simple-pro\.zip$/'`), der ausschließlich das Pro-ZIP matcht.
**Wird dieser Filter vergessen oder falsch gesetzt, installiert Pro im
schlimmsten Fall das Free-ZIP als "Update".**

Zum Ausliefern eines Updates: `PMS_VERSION` + `Version:`-Header in **beiden**
Hauptdateien erhöhen (sie laufen synchron, ein Tag baut immer beide ZIPs),
Changelog-Eintrag in `src/readme.txt`, dann taggen und pushen — siehe
„Releases bauen" unten. Kein manuelles ZIP-Bauen mehr nötig.

---

## Build / Test / Package — Workflow & Fallstricke

### PHP-Pfad

PHP ist unter `C:\php\php.exe` installiert, **nicht im PATH**. Immer vollen
Pfad angeben:

```powershell
& "C:\php\php.exe" dev-tools\test-suite.php
```

### Tests ausführen

```powershell
& "C:\php\php.exe" dev-tools\test-suite.php
```

245 Tests, reiner PHP-Stub-Harness (kein WordPress nötig) — lädt die echten
Plugin-Klassen aus `../src/includes/` (inkl. `pro/class-pro-utm.php`, siehe
„Event Log" oben) per `require` und stubbt nur die WP-Funktionen, die sie
aufrufen. Nutzt `ReflectionMethod`/`ReflectionProperty` für Zugriff auf
private Properties/Methods (z. B. `PMS_Frontend::$matched_events`,
`PMS_CAPI::$log`, `PMS_Consent::$cache` via `reset_consent_cache()`-Helper —
**bei jedem neuen Test, der `PMS_Consent`/`send_events()` mit wechselndem
Consent-Status prüft, diesen Helper vorher aufrufen**, sonst sieht
`has_marketing_consent()` noch das request-lokal gecachte Ergebnis eines
früheren Testabschnitts; hat in dieser Session zwei Tests fälschlich fehlschlagen
lassen, bis der fehlende Reset gefunden wurde).

Seit v0.6.1 gibt es zusätzlich `Test_PMS_Wpdb` (ein minimaler `$wpdb`-Fake
für `PMS_Logger`, siehe Datei-Kommentar direkt davor in `test-suite.php`):
bewusst KEIN SQL-Parser, sondern ein Array pro "Tabelle" — funktioniert nur,
weil `PMS_Logger` selbst so geschrieben ist, dass es bis auf
TRUNCATE/DELETE-ohne-WHERE ausschließlich strukturierte `$wpdb`-Methoden
(`insert()`/`get_results()`/`delete()`) ohne dynamisches WHERE/JOIN nutzt.
**Ein neuer `PMS_Logger`-Query, der doch ein dynamisches WHERE bräuchte,
müsste entweder den Fake erweitern oder (bevorzugt) so umgebaut werden, dass
er ohne auskommt** — z. B. indem wie bei `cleanup_old_entries()` erst
ungefiltert geholt und dann in PHP gefiltert wird (die Retention hält die
Tabelle ohnehin klein). `create_table()`/`activate()`/`maybe_upgrade_table()`
werden NICHT getestet: sie rufen `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
auf, eine echte WP-Core-Datei, die im Stub-`ABSPATH` nicht existiert und
beim Aufruf fatal enden würde.

**Wichtigste Lektion beim Testen:** Ein Stub, der eine WP-Funktionssignatur
zu stark vereinfacht, kann einen echten Bug **verdecken**. Konkret passiert:
`function wp_json_encode($data){ return json_encode($data); }` (ohne
`$flags`-Parameter) hat den `JSON_HEX_TAG`-Security-Fix stillschweigend
wirkungslos gemacht — der Test wäre trotzdem grün gewesen, hätte also nichts
Reales geprüft. Der aktuelle Stub reicht `$flags`/`$depth` korrekt durch
(`function wp_json_encode($data, $flags = 0, $depth = 512)`). **Bei jedem
neuen Stub kurz gegenprüfen, ob er wirklich alle Parameter durchreicht, die
der Plugin-Code nutzt** — sonst testet man eine Fiktion. Gleiches gilt für
`wp_localize_script()`/`wp_enqueue_script()` (beide erfassen Handle/Objekt-
name/Daten in `$GLOBALS['stub']`, statt No-Ops zu sein).

### JavaScript-Tests (`assets/frontend.js`)

Zweites, analoges Test-Skript für die rein clientseitige Logik, die der
PHP-Harness nicht erreicht (UTM-Form-Fill: URL-Gating, Werte-Ermittlung,
Feld-Matching):

```powershell
node dev-tools\test-frontend-js.js
```

Reines Node, keine npm-Abhängigkeiten, kein Jest/Mocha — ein minimaler,
handgeschriebener DOM-Ersatz (`document.querySelector`, `document.cookie`,
Feld-Objekte mit `.value`/`.dispatchEvent`) plus `vm.runInContext()`, der die
**echte** `src/assets/frontend.js` lädt und ausführt — kein Reimplementieren
der Logik im Test. **Bei einer neuen Browser-API, die `frontend.js` künftig
nutzt** (z. B. `fetch`, `CustomEvent`), im Sandbox-Objekt von `run()` in
`test-frontend-js.js` ergänzen — sonst wirft `vm.runInContext` einen
`ReferenceError`, sobald der entsprechende Codepfad läuft.

### composer.json / package.json (seit v0.6.2)

Zwei dünne Wrapper im Projekt-Root, NICHT Teil eines der beiden ZIPs (liegen
außerhalb von `src/`, wie `dev-tools/` selbst). Sie deklarieren keine echten
Abhängigkeiten – es gibt kein `vendor/`, kein `node_modules/`, nichts zum
Installieren – sondern bilden nur die schon bestehenden `dev-tools/`-Skripte
auf die überall erwarteten Konventions-Befehle ab:

```powershell
composer test    # ruft intern: php dev-tools/test-suite.php
npm test         # ruft intern: node dev-tools/test-frontend-js.js
npm run build    # ruft intern: php dev-tools/build-translations.php
```

**`composer test` wurde nie end-to-end mit echtem Composer verifiziert** –
in der Session, die diese Dateien angelegt hat, war `composer` selbst nicht
installiert/im PATH. Verifiziert wurde nur, dass der Befehl, den das Script
aufruft (`php dev-tools/test-suite.php`), korrekt läuft. Vor dem ersten
echten Einsatz von `composer test` einmal mit installiertem Composer
gegenprüfen. `npm test`/`npm run build` liefen dagegen bereits real über
`npm` durch.

```powershell
& "C:\php\php.exe" dev-tools\build-translations.php
```

Extrahiert alle `__()`/`_e()`/`esc_html__()`/`esc_html_e()`/`esc_attr__()`/
`esc_attr_e()`-Aufrufe mit Text-Domain `pixel-made-simple` per Regex aus
`src/**/*.php` (Plugin-Header wird ausschließlich aus
`src/pixel-made-simple.php` gelesen, nicht aus der Pro-Datei — Pro braucht
keinen eigenen POT-Header-Eintrag, da beide denselben Katalog nutzen),
vergleicht gegen ein PHP-Array mit deutschen Übersetzungen (Du-Form) im
selben Skript, meldet `FEHLENDE ÜBERSETZUNG` (String im Code, keine
Übersetzung) oder `VERWAISTE ÜBERSETZUNG` (Übersetzung vorhanden, aber kein
passender String mehr im Code), schreibt `pixel-made-simple.pot` +
`pixel-made-simple-de_DE.po` und kompiliert die `.mo` **manuell** (kein
`msgfmt`/gettext-Toolchain nötig) inkl. Gegenprobe.

**Bei jeder neuen Übersetzungs-Zeichenkette im Plugin:** Übersetzung im
`$de`-Array in `dev-tools/build-translations.php` ergänzen, sonst bricht die
Validierung ab. Die Versionsnummer im `.pot`/`.po`-Header wird automatisch
aus `PMS_VERSION` in `src/pixel-made-simple.php` gelesen.

**`.mo`-Dateien nie per Texteditor/Skript anfassen:** Es ist ein binäres,
längenpräfixiertes Format – jede Textersetzung direkt an der `.mo` verschiebt
die internen Byte-Offsets und korrumpiert die Datei lautlos. Immer über
`build-translations.php` neu kompilieren lassen (bei einer Umbenennungs-
Aktion o. Ä. also die alte `.mo` einfach löschen und das Skript neu laufen
lassen, statt sie mitzubearbeiten).

### Releases bauen (GitHub Actions, seit v1.0.0)

Manuelles ZIP-Bauen entfällt komplett. Workflow:

1. `PMS_VERSION` + `Version:`-Header in **beiden** `src/pixel-made-simple.php`
   und `src/pixel-made-simple-pro.php` erhöhen, Changelog-Eintrag in
   `src/readme.txt` ergänzen.
2. `git tag v1.2.3 && git push origin v1.2.3`.
3. `.github/workflows/release.yml` baut auf `ubuntu-latest` zwei ZIPs aus
   `src/` (per `rsync --exclude`, dann `zip -r`) und hängt beide über die
   `gh`-CLI ans GitHub-Release des Tags an — `pixel-made-simple.zip` (ohne
   `/pro/`, `plugin-update-checker/`, `pixel-made-simple-pro.php`) und
   `pixel-made-simple-pro.zip` (ohne `pixel-made-simple.php`, sonst
   vollständig).

**Warum `ubuntu-latest` und nicht `windows-latest`:** Das ist die direkte
Lehre aus der alten manuellen Vor-Rebrand-Ära: PowerShells
`Compress-Archive` schrieb **Backslashes** in ZIP-Eintragspfade
(`pixel-made-simple\includes\class-...`), was den ZIP-Standard verletzt und
WordPress' Installer auf einem Linux-Server mit „Plugin file does not
exist." scheitern ließ, obwohl lokal (Windows) alles normal aussah. Linux'
`zip`-Kommando schreibt immer korrekte Vorwärtsschrägstrich-Pfade – deshalb
läuft der komplette Release-Job jetzt bewusst auf einem Linux-Runner statt
auf `windows-latest`.

**Noch nie live getestet:** Diese Workflow-Datei wurde in dieser Session neu
geschrieben, aber nie gegen ein echtes GitHub-Repo mit einem echten Tag-Push
verifiziert (es existiert noch kein Git-Repo in diesem Projektordner). Vor
dem ersten echten Release: einmal mit einem Testtag durchlaufen lassen und
beide ZIPs stichprobenartig entpacken/installieren, bevor man sich auf den
Automatismus verlässt.

### PowerShell-Encoding-Fallstrick (UTF-8 → Mojibake)

`Get-Content`/`Set-Content` **ohne** `-Encoding utf8` liest/schreibt in
Windows PowerShell 5.1 über die System-ANSI-Codepage. Bei einer UTF-8-
kodierten Datei mit Umlauten/Sonderzeichen führt das zu doppelter Kodierung
(„Mojibake", z. B. wird `→` zu `â†'`). Ist in einer früheren Session einmal
mit `(Get-Content x) -replace ... | Set-Content x` passiert und hat ein
i18n-Skript beschädigt.

**Lehre:** Für Datei-Edits an UTF-8-Dateien mit Sonderzeichen entweder das
`Edit`-Tool nutzen (kodierungssicher) oder in Bash/Node/PHP arbeiten,
**nicht** PowerShell `Get-Content`/`Set-Content` ohne explizites
`-Encoding utf8`. Die große LMPCT→PMS-Umbenennung in dieser Session lief
deshalb bewusst über ein kleines Node-Skript (`fs.readFileSync(path,'utf8')`
/ `writeFileSync(path, content, 'utf8')`) statt über PowerShell-Textersetzung.

### Admin-UI im Browser prüfen (ohne echtes WordPress)

```powershell
& "C:\php\php.exe" dev-tools\preview-admin.php
& "C:\php\php.exe" -S localhost:8321 -t dev-tools
```

Erzeugt `dev-tools/preview-{general,events,events-edit,advanced,tools,help}.html`
mit echten WP-Core-Styles (CSS via jsDelivr-CDN geladen) und den echten
Admin-Templates aus `src/`. Danach mit dem Browser-Tool
(`mcp__Claude_Browser__*`) öffnen, per `javascript_tool`/`read_page` Zustände
prüfen (z. B. Accordion auf/zu, Toggle-Farben, Konflikt-Notices) —
funktioniert zuverlässiger als Screenshots allein, weil man
`getComputedStyle()`, `classList` etc. direkt abfragen kann. Diese
`preview-*.html`-Dateien sind Wegwerf-Build-Output, werden nicht versioniert
(siehe `.gitignore`) und bei Bedarf einfach neu generiert.

**Cookies sind PORT-unabhängig (RFC 6265)!** `localhost:8321` und
`localhost:8322` teilen sich denselben Cookie-Speicher im Browser. Das hat in
einer früheren Session mehrfach zu falschen Testergebnissen geführt
(Cookie-Reste eines vorherigen Consent-Test-Szenarios haben ein neues
Szenario verfälscht). **Vor jedem neuen Consent-/Formular-Test-Szenario im
selben Browser-Tab alle Cookies explizit löschen:**

```js
document.cookie.split(';').forEach(function(c){
  var n = c.split('=')[0].trim();
  if (n) { document.cookie = n + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;'; }
});
```

…danach neu navigieren (nicht nur reloaden), damit der saubere Zustand auch
wirklich greift.

Für Frontend-Szenarien (Formular-Submits, Consent-Bootstrap) lohnt sich eine
kleine Wegwerf-PHP-Seite nach demselben Muster wie `preview-admin.php`: echte
`frontend.js`/`class-pms-consent.php`-Logik einbinden, `window.fbq`/
`window.fetch` mit einem einfachen Stub überschreiben, der Aufrufe in ein
Array sammelt, und dann im Browser-Tool per `javascript_exec` Submits
simulieren und die Arrays auslesen. Solche Einmal-Skripte lohnen sich, bei
Bedarf neu zu bauen, werden aber bewusst nicht dauerhaft im Repo gehalten
(zu szenario-spezifisch).

### Lokale PHP-Dev-Server aufräumen

Nach jeder `php -S localhost:PORT`-Session:

```powershell
Get-Process php -ErrorAction SilentlyContinue | ForEach-Object { try { $_ | Stop-Process -Force -Confirm:$false -ErrorAction Stop } catch {} }
```

---

## Formular-Vorschlag für den Auto-Grabber

Der Formular-Auto-Grabber (`assets/frontend.js`) erkennt E-Mail/Telefon über
Feld-`type`, dann über Name/ID/Placeholder/Autocomplete-Heuristiken, zuletzt
über „enthält @". Für zuverlässige Erkennung **ohne** auf Heuristiken
angewiesen zu sein, dieses Muster verwenden:

```html
<form id="kontaktformular">
  <label for="lead-name">Name</label>
  <input type="text" id="lead-name" name="name" autocomplete="name" required>

  <label for="lead-email">E-Mail</label>
  <input type="email" id="lead-email" name="email" autocomplete="email" required>

  <label for="lead-phone">Telefon (optional)</label>
  <input type="tel" id="lead-phone" name="phone" autocomplete="tel">

  <label for="lead-message">Nachricht</label>
  <textarea id="lead-message" name="message" required></textarea>

  <button type="submit">Absenden</button>
</form>
```

**Warum genau so:**
- `type="email"` / `type="tel"` werden vom Grabber **zuerst** geprüft — robust
  gegen jedes Namensschema, auch bei mehrsprachigen Feldnamen.
- `autocomplete="email"`/`autocomplete="tel"` sind die zweite Erkennungsebene
  und gute Praxis ohnehin (Browser-Autofill).
- **Kein** `type="password"`-Feld im Formular — jedes Formular mit einem
  Passwortfeld wird **grundsätzlich** ignoriert (Login-Schutz, siehe
  `isExcludedForm()` in `frontend.js`), unabhängig von der
  „Suche/Kommentare/Logins ignorieren"-Einstellung.
- **Kein** `name="s"` verwenden, wenn es kein Suchfeld ist — das wird als
  WordPress-Suchfeld erkannt und ignoriert.

Für den UTM-Form-Fill (Source/Campaign/Medium) werden entweder die
name-Attribute `utm_source`/`source`, `utm_campaign`/`campaign`,
`utm_medium`/`medium` erkannt, oder die CSS-Klassen `utm-source`/
`pms-utm-source`, `utm-campaign`/`pms-utm-campaign`, `utm-medium`/
`pms-utm-medium` (auf dem Feld selbst oder einem Wrapper-Element darum).

### Wichtig: CF7 / Elementor / Fluent Forms / WPForms / Gravity Forms brauchen dieses Muster NICHT

Für diese fünf Plugins lauscht `frontend.js` direkt auf ihre **nativen
Erfolgs-Events** (`wpcf7mailsent`, `submit_success`,
`fluentform_submission_success`, `wpformsAjaxSubmitSuccess`,
`gform_confirmation_loaded`) und liest die Feldwerte über deren eigene
Event-Payload bzw. per DOM-Query aus dem jeweiligen Formular-Container. Das
Muster oben ist gedacht für **native HTML-Formulare, Theme-Builder ohne
eigenes Event, oder eigene Formular-Implementierungen**. SureForms wird
zusätzlich über `srfm_form_submission_success`/
`sureforms_form_submission_success` abgedeckt (siehe `frontend.js`) — falls
SureForms diese Events (noch) nicht auslöst, greift automatisch der native
`submit`-Listener als Fallback.

### Empfohlene Einstellungen dazu

Im Tab „Erweitertes Tracking" → Box „Automatic form lead tracking":

- **Event-Typ:** `Contact` für allgemeine Anfragen/Support, `Lead` für echte
  Akquise-Formulare (Angebot anfordern, Whitepaper-Download …). Für echte
  Sales-Qualifizierung ggf. mehrere Formulare mit unterschiedlichem
  Event-Typ über getrennte URL-Filter abbilden.
- **URL-Filter:** Leer lassen für websiteweite Formulare (Kontaktformular im
  Footer o. Ä.). Auf bestimmte Pfade eingrenzen, wenn nur ein spezifisches
  Formular (z. B. `/angebot-anfordern/`) getrackt werden soll — dann wird das
  Skript auf allen anderen Seiten gar nicht erst geladen (0 Byte Overhead).
- **„Suche, Kommentare & Logins ignorieren":** Standardmäßig aktiv lassen.

**Merke die eingebaute Konflikt-Warnung:** Wenn für dieselbe URL sowohl eine
URL-Event-Regel (Tab „URL-Events") als auch das Formular-Tracking mit
demselben Event-Typ aktiv sind, zeigt das Backend automatisch eine gelbe
Warnung (`PMS_Admin::detect_form_url_conflicts()`). Grundsatz aus der
Info-Box im Tab „URL-Events": **URL-Events für Danke-/Bestätigungsseiten
nach einer Weiterleitung, Formular-Tracking für Formulare ohne
Weiterleitung.** Beides gleichzeitig für dieselbe Konversion aktivieren führt
zu Doppelzählung.

---

## Bekannte Trade-offs / offene Punkte

- **Event Log: Browser-Bestätigung fehlt für URL-Events.** Nur der
  Formular-Grabber meldet `browser_fired` mit (eigener AJAX-Request, siehe
  „Event Log" oben). URL-Events (Tab „URL-Events") werden im Event Log daher
  immer nur mit `source = 'capi'` protokolliert, selbst wenn der Browser-Pixel
  tatsächlich mitgefeuert hat – ein echter `source = 'both'`-Eintrag dafür
  bräuchte einen neuen Beacon-basierten Rückkanal vom Browser (nicht gebaut,
  bewusster Scope-Cut dieser Session).
- **Event-Log-Gating (`class_exists('PMS_Pro_UTM')`-Pfad in `PMS_CAPI`/
  `PMS_Debug`/`PMS_Frontend`) ist nur indirekt getestet.** Der PHP-Stub-
  Harness lädt `pro/class-pro-utm.php` unconditional (siehe „Tests
  ausführen" unten), damit alle bestehenden Attribution-Tests unverändert
  laufen – es gibt also keinen automatisierten Test, der eine ECHTE Free-
  Installation (Klasse gar nicht geladen) nachstellt. Vor einem Release lohnt
  sich ein manueller Rauchtest auf einer echten Free-Installation.
- **Pro hat aktuell zwei echte Features:** UTM/Attribution (`PMS_Pro_UTM`)
  und Konfiguration exportieren. `src/pro/class-pro-features.php`
  (`PMS_Pro_Features`) ist weiterhin ein leerer Erweiterungspunkt für alles
  Künftige, das kein eigenes Modul wie `class-pro-utm.php` rechtfertigt.
- **Free-Limit für Custom Events wurde in v0.6.2 von „2 aktiv" auf „2
  insgesamt" umgestellt – basierend auf einer Interpretationsentscheidung,
  die Dominik noch nicht explizit bestätigt hat.** Der Auftragstext für
  v0.6.2 sprach im Titel von „maximal 2 aktive URL-Events", beschrieb das
  gewünschte Verhalten aber so: „Sobald 2 Events existieren, wird der Button
  'Event hinzufügen' deaktiviert" – das ist eindeutig ein Gesamtzahl-Limit
  (setzt nur an der Anzahl existierender Regeln an, nicht am `active`-Status).
  Diese Session hat die detailliertere Verhaltensbeschreibung als
  maßgeblich behandelt und das v0.6.0-Modell („beliebig viele Regeln, nur 2
  gleichzeitig aktiv", inkl. gesperrtem Status-Toggle für weitere Events)
  vollständig ersetzt statt beide Modelle nebeneinander zu pflegen. Konkret
  umgesetzt: `PMS_Settings::FREE_EVENT_LIMIT = 2` (vormals
  `FREE_ACTIVE_EVENT_LIMIT`), `free_event_limit_reached()` zählt jetzt
  `count( get_events() )` ohne Rücksicht auf `active`, `save_events()`
  kürzt bei Überschreitung die Gesamtliste (`array_slice`) statt aktive
  Events zu deaktivieren, und `render_locked_event_toggle()`/
  `is_event_locked()` wurden komplett entfernt (kein Zustand mehr, der einen
  isoliert gesperrten Toggle bräuchte). **Falls Dominik eigentlich das
  wörtliche „2 aktiv"-Modell meinte**, sitzt die Änderung an denselben drei
  Stellen plus dem jetzt fehlenden gesperrten Toggle in `class-pms-admin.php`
  (`render_events_tab()`/`render_event_form()`) und `assets/admin.css`.
- **Breaking Change durch die v0.6.0-Umbenennung:** Alle Filter-Hooks,
  Options-Keys und das Attribution-Cookie wurden von `lmpct_*`/`LMPCT_*` auf
  `pms_*`/`PMS_*` umbenannt (vollständige Liste der Filter in
  `src/readme.txt` unter „Welche Filter gibt es?"). Eigener Code von
  Bestandskunden (z. B. `add_filter('lmpct_allow_tracking', ...)` in einer
  functions.php), der die alten alten Namen nutzt, wird nach einem Update
  stillschweigend NICHT mehr aufgerufen — es gibt bewusst keine Backwards-
  Compat-Shims (alte Filter-Namen als Alias mitlaufen zu lassen), das wurde
  nicht angefragt und würde die Namensraum-Bereinigung wieder aufweichen.
  Falls das in der Praxis zum Problem wird, wäre ein dünner Kompatibilitäts-
  Layer (alte Hooks auf die neuen durchreichen) eine spätere, bewusste
  Entscheidung – aktuell existiert er nicht.
- **Es gibt keine Klasse `PMS_Core`.** Der v0.6.2-Auftrag beschrieb den
  Kollisionsguard u. a. als Prüfung auf `class_exists('PMS_Core')` – im
  tatsächlichen Code gibt es diese Klasse nicht und gab es nie; der Guard
  prüft stattdessen die schon seit v0.6.0 bestehende Konstante `PMS_IS_PRO`
  (siehe „Free/Pro-Kollisionsschutz" oben), was denselben Zweck erfüllt.
  Diese Session hat das als ungenaue Beschreibung eines bestehenden
  Mechanismus behandelt, nicht als Auftrag, tatsächlich eine neue Klasse
  `PMS_Core` einzuführen.
- **Versionszählung:** Die erste Rebrand-Session hatte auf 1.0.0
  zurückgesetzt (neue Marke = neuer Zählbeginn); Dominik wollte stattdessen
  die Vor-Rebrand-Zählung fortsetzen, korrigiert auf 0.6.0. Beide
  Hauptdateien + `readme.txt` müssen bei jedem Release synchron bleiben
  (siehe „Releases bauen" oben) – es gibt keinen automatischen
  Konsistenz-Check zwischen den drei Stellen.
- **Kein Git-Repo, keine GitHub-Action je ausgeführt:** Siehe Hinweis ganz
  oben. `git init`, Remote setzen, initialen Commit und den ersten
  `vX.Y.Z`-Tag muss Dominik selbst anstoßen (oder explizit anfragen).
- **`_pre-migration-backup_lightweight-meta-pixel-capi-tracker/`** und die
  alte `lightweight-meta-pixel-capi-tracker.zip` im Projekt-Root sind der
  unveränderte Stand vor dem v1.0.0-Rebrand (Sicherheitsnetz dieser Session,
  da damals kein Git-Repo existierte). Können gelöscht werden, sobald der
  neue Stand verifiziert ist.
- **Privacy-by-Default seit der Vor-Rebrand-Ära:** `form_tracking` und
  `utm_passthrough` sind bei **Neuinstallationen** standardmäßig deaktiviert
  (verarbeiten zusätzliche personenbezogene Daten). Bestehende Installationen
  behalten ihre gespeicherten Werte — das betrifft nur den
  `PMS_Settings::get()`-Default-Fallback für noch nie gespeicherte Optionen.
- **`dev-tools/preview-*.html`** sind Wegwerf-Build-Output von
  `preview-admin.php` und werden nicht versioniert/aufbewahrt — bei Bedarf
  einfach neu generieren.

## Support-Kontakte (aus dem Plugin-Header bzw. der Info&Hilfe-Seite)

- Autor/Support laut Plugin: Dominik Seitz, `dominik@seitzdominik.de`, sdv.design
- `Last-Translator` in den PO-Headern (aus einer früheren Session übernommen):
  `seitz.entertainment@gmail.com` — leichte Inkonsistenz, aber unkritisch
  (nur Metadaten im PO-Header, keine funktionale Auswirkung).
