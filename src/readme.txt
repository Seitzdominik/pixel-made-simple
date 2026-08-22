=== Pixel Made Simple ===
Contributors: dominikseitz
Author: Dominik Seitz
Author URI: https://sdv.design
Tags: meta pixel, conversions api, google ads, google analytics, tiktok pixel, consent mode
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.6.12
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
* **Automatischer UTM-Formular-Fill (standardmäßig deaktiviert):** Schreibt die 3 Kernwerte Source, Campaign und Medium automatisch in passende Formularfelder (per name-Attribut wie `utm_source`/`source` oder CSS-Klasse wie `utm-source`/`pms-utm-source`, auch auf einem Wrapper-Element erkannt), bevor der Besucher absendet – die Kampagnendaten landen so direkt im CRM oder in der Benachrichtigungs-E-Mail. Source wird zuerst aus der aktuellen URL gelesen, dann aus dem Attribution-Cookie (Unterseiten-Navigation) und zuletzt aus einer Facebook-/Google-Klick-ID oder dem Referrer geschätzt (facebook/google/direct); Campaign und Medium werden nur bei explizitem Wert befüllt. Granular steuerbar über „Auf allen Seiten", „Nur auf bestimmten URLs" oder „Auf bestimmten URLs ausschließen" mit zeilenbasierten Pfad-Mustern (inkl. `*`-Platzhalter) – die URL-Auswertung übernimmt dabei ausschließlich der Browser.
* **Live-Debug-Konsole für Admins:** Dezente Leiste am unteren Bildschirmrand mit Consent-Status (inkl. erkanntem Banner), gefeuerten Events, Event-IDs, CAPI-Antwort (⏳ → ✅ 200 OK) und verwendeten Match-Keys. Wird ausschließlich für eingeloggte Administratoren gerendert – reguläre Besucher erhalten kein einziges zusätzliches Byte.
* **1-Klick Export & Import:** Komplette Konfiguration inkl. Event-Regeln als JSON exportieren und auf der nächsten Kundenwebsite importieren.
* **Test-Code Auto-Expiry:** Der Meta Test Event Code deaktiviert sich nach 12 Stunden automatisch – kein versehentliches Test-Tracking im Live-Betrieb.
* **Komfort:** Toggles speichern sofort per AJAX (nonce-gesichert, mit dezenter Bestätigung); das Einfügen eines CAPI-Tokens aktiviert die Conversions API automatisch.
* **Sicherheit:** Nonces, Capability-Checks (`manage_options`), konsequente Sanitization/Escaping, CAPI-Token nur serverseitig.
* **Übersetzbar (i18n):** Englische Quellstrings, POT-Vorlage und deutsche Übersetzung (`de_DE`) in `/languages`. Kompatibel mit Loco Translate, Poedit und Polylang/WPML-Sprachdateien.

Dieses Free-Plugin deckt den kompletten Funktionsumfang oben ab. **Pixel Made Simple Pro** (separates Plugin, selber Options-Key – Upgrade übernimmt deine bestehende Konfiguration nahtlos) ergänzt das um Zusatzfunktionen für Agenturen und Power-User.

== Installation ==

1. ZIP über „Plugins → Installieren → Plugin hochladen" installieren und aktivieren.
2. Unter „Pixel Made Simple" im Admin-Menü die gewünschten Plattformen aufklappen, IDs eintragen und per Master-Toggle aktivieren.
3. Optional: CAPI Access Token hinterlegen und die Conversions API aktivieren.
4. Im Tab „Events verwalten" URL-Regeln für Conversion-Seiten anlegen und den Plattformen zuweisen.

== Frequently Asked Questions ==

= Wie teste ich die Meta-Server-Events? =

Trage den Test Event Code aus dem Events Manager (Tab „Test-Events") ein. Die Server-Events erscheinen dort in Echtzeit. Vor dem Livegang den Code wieder entfernen. Für Debugging kann der Versand blockierend geschaltet werden, dann landet die Meta-Antwort bei aktivem `WP_DEBUG_LOG` im Debug-Log:

`add_filter( 'pms_capi_blocking', '__return_true' );`

= Wie funktioniert das Zusammenspiel mit meinem Cookie-Banner? =

Die automatische Cookie-Banner-Erkennung (Standard: aktiv) prüft serverseitig die Consent-Cookies der gängigen Banner-Plugins und die WP Consent API. Ohne Marketing-Einwilligung werden die Browser-Skripte verzögert (sie lauschen auf die Banner-Events und starten sofort nach dem Klick auf „Akzeptieren") und die CAPI bricht vor dem HTTP-Request ab. Ist gar kein bekanntes Banner installiert, wird nichts blockiert.

Zusätzlich gilt: **Google Consent Mode v2** lädt gtag.js designgemäß sofort mit `denied`-Defaults – dein Banner sendet das `gtag('consent','update',...)`.

Für nicht unterstützte Banner lässt sich das Consent-Ergebnis per Filter setzen:

`add_filter( 'pms_has_marketing_consent', function ( $consent ) { return my_marketing_consent(); } );`

Und das gesamte Tracking serverseitig unterdrücken:

`add_filter( 'pms_allow_tracking', function ( $allow ) { return my_consent_check(); } );`

= Funktioniert das Plugin mit Page-Caching? =

Die Browser-Pixel: ja. Die Conversions API wird jedoch nur ausgelöst, wenn PHP die Seite tatsächlich rendert. Bei aggressivem Full-Page-Caching sollten die Conversion-/Danke-Seiten vom Cache ausgenommen werden, damit CAPI-Events zuverlässig und mit frischer Event-ID gesendet werden.

= Welche Filter gibt es? =

* `pms_allow_tracking` – Tracking global erlauben/unterbinden.
* `pms_has_marketing_consent` – Consent-Ergebnis der automatischen Banner-Erkennung überschreiben (bewacht den Browser-Pixel).
* `pms_has_server_consent` – dasselbe für die serverseitigen Signale (Conversions API, TikTok Events API). Nur nötig, wenn der Consent-Modus auf „Nur Browser-Pixel blockieren“ steht – im strikten Modus liefert dieser Filter ohnehin dasselbe Ergebnis wie `pms_has_marketing_consent`.
* `pms_consent_banner_active` – eigenes Banner bei der Erkennung registrieren.
* `pms_consent_events` – zusätzliche Banner-Events für den Frontend-Listener.
* `pms_capi_event_data` – einzelnes CAPI-Event vor dem Versand anpassen (z. B. `custom_data` mit Werten ergänzen).
* `pms_capi_user_data` – `user_data`-Payload anpassen.
* `pms_graph_api_version` – Graph-API-Version überschreiben (Zukunftssicherheit bei Meta-Deprecations).
* `pms_capi_blocking` – CAPI-Request blockierend senden (Debugging).
* `pms_tiktok_capi_blocking` – dasselbe für TikTok-Events-API-Requests (Debugging).

Hinweis für Umsteiger von „Lightweight Meta Pixel & CAPI Tracker": Diese Filter hießen dort `lmpct_*`. Eigener Code (z. B. in der functions.php), der einen dieser Filter nutzt, muss auf den neuen `pms_*`-Namen umgestellt werden – siehe Changelog 1.0.0.

= Wie übersetze ich das Plugin (z. B. mit Loco Translate)? =

Die Quellstrings sind englisch. Im Ordner `/languages` liegen die POT-Vorlage sowie die fertige deutsche Übersetzung (`-de_DE.po`/`.mo`). Eigene Übersetzungen in Loco Translate am besten unter „System" bzw. `wp-content/languages/loco/plugins/` speichern, damit sie Updates überleben.

= Werden bei der Deinstallation alle Daten entfernt? =

Ja, sobald keine der beiden Varianten (Free oder Pro) mehr installiert ist. `uninstall.php` löscht dann alle Plugin-Optionen inklusive des gespeicherten Access Tokens. Wechselst du von Free zu Pro (oder umgekehrt), bleibt die Konfiguration erhalten – beide nutzen denselben Options-Key.

== Changelog ==

= 0.6.12 =
* Verbessert: **Einheitliche Plattform-Badges im Tab „URL-Events".** Die Spalte „Plattformen" zeigte Meta blau, Google Ads grün und TikTok grau – bei mehreren aktiven Plattformen standen so bis zu drei Farben in einer Zelle, ohne zusätzliche Information zu tragen (der Plattformname steht ohnehin im Badge). Alle drei nutzen jetzt dasselbe dezente Status-Grün. Im Event Log bleiben die Plattformfarben erhalten, da dort pro Zeile nur ein Badge steht und die Farbe beim Scannen der Spalte hilft.
* Neu: **Überarbeiteter Tab „Info & Hilfe".** Neue Support-Adresse (support@pixelmadesimple.com), Entwickler-Hinweis mit Link auf pixelmadesimple.com, eine Info-Box mit Button zur offiziellen Dokumentation sowie ein zweispaltiger Tutorial-Bereich mit vier Karten (Schnellstart, Meta CAPI einrichten, Google Ads & GA4, E-Commerce Tracking) und einem Sammel-Link zur Tutorial-Übersicht.
* Verbessert (kostenlose Version): **Klar gekennzeichnete Pro-Funktionen statt stiller Wirkungslosigkeit.** Im Tab „URL-Events" sind die Felder für Google Ads und TikTok jetzt gesperrt und mit einem „PRO"-Badge versehen; dasselbe gilt für den TikTok-Event-Typ und das Google-Ads-Conversion-Label beim Formular-Tracking. Bisher ließen sich diese Felder ausfüllen, obwohl die Plattformen in der kostenlosen Version ohnehin nicht senden.
* Verbessert (kostenlose Version): Ist WooCommerce oder SureCart installiert, aber Pro nicht aktiv, erklärt der Tab „E-Commerce" jetzt mit einem eigenen Hinweis, dass automatisches E-Commerce-Tracking inklusive Conversions API Pixel Made Simple Pro vorbehalten ist – mit direktem Link zum Upgrade.
* Sicherheit: Die gesperrten Plattform-Felder werden zusätzlich **serverseitig** durchgesetzt – ein manuell abgeschickter Formular-Request kann Google Ads oder TikTok in der kostenlosen Version nicht aktivieren. Eine nach einem Downgrade von Pro noch gespeicherte Konfiguration bleibt dabei erhalten und wird beim Bearbeiten eines Events nicht überschrieben.
* `dev-tools/test-suite.php` deckt die serverseitige Durchsetzung in beiden Richtungen sowie die neuen Oberflächen ab (621 → 653 PHP-Tests).

= 0.6.11 =
* Neu (Pro): **TikTok-Events-API-Anfragen erscheinen jetzt im Event Log** – mit HTTP-Status und den tatsächlich übergebenen Match Keys, genau wie die Meta Conversions API. Bisher wurden sie zwar gesendet, aber nirgends protokolliert. Besonderheit dabei: TikTok antwortet auch auf abgelehnte Anfragen mit HTTP 200 und meldet den eigentlichen Fehler nur in einem Feld der Antwort – solche Fälle galten bisher als Erfolg und werden jetzt korrekt als Fehler ausgewiesen.
* Neu: **Plattform-Spalte im Event Log.** Jede Zeile zeigt jetzt als Badge, an welche Plattform das Ereignis ging (Meta, Google Ads, TikTok, GA4); in Pro lässt sich zusätzlich danach filtern. Ein Kauf erzeugt damit eine eigene, nachvollziehbare Zeile je Ziel statt nur einer Meta-Zeile.
* Neu (Pro): **Google-Ads-Conversions und GA4-Kaufereignisse sind nachvollziehbar.** Die Purchase-Conversion für Google Ads und das GA4-Kaufereignis (WooCommerce) erscheinen als eigene Zeilen im Event Log; Formular-Leads melden ihre Google-Ads-Conversion ebenfalls. Da beide Plattformen ausschließlich im Browser arbeiten, sind diese Zeilen als „Browser" gekennzeichnet – es gibt für sie keinen Serverstatus.
* Verbessert: **Live-Debug-Leiste.** Sie zeigt jetzt zu jedem Ereignis, welche Ziele im Browser tatsächlich gefeuert haben (Meta, Google Ads/GA4, TikTok), und listet TikTok-Events-API-Anfragen mit eigener Zeile neben den Meta-Anfragen. Für Administratoren werden TikTok-Anfragen – wie bisher schon die Meta-Anfragen – blockierend gesendet, damit statt „gesendet" der echte Statuscode erscheint.
* Verbessert: **Einheitliche Spaltenbreiten in allen Tabs.** Überschriften und Hinweisboxen richten sich jetzt nach der Breite des jeweiligen Tab-Inhalts (Einstellungs-Tabs 900px, Tabellen-Tabs 960px). Vorher endete die Linie unter der Überschrift im Tab „URL-Events" sichtbar vor der Tabelle, die Konflikt-Warnung im Tab „Erweitertes Tracking" ragte über die Boxen hinaus, und der Tab „Import / Export" war in Pro breiter als in der kostenlosen Version.
* Geändert: Beschreibung von Pixel Made Simple Pro im Plugin-Header aktualisiert.
* `dev-tools/test-suite.php` deckt die Plattform-Achse, die TikTok-Antwortauswertung und die neuen Log-Zeilen ab (578 → 621 PHP-Tests); der Formular-Test-Harness kann jetzt erstmals die Meldungen an die Debug-Leiste prüfen (47/86/53 JS-Tests).

= 0.6.10 =
* Neu: **Consent-Modus (Tab „Allgemein" → Box „Automatische Cookie-Banner-Erkennung").** Ein aufklärendes Hinweisfeld erklärt, was die DSGVO-Blockade konkret bewirkt, darunter lässt sich zwischen zwei Modi wählen: „Vollständig DSGVO-konform (empfohlen für die EU)" blockiert wie bisher Browser-Pixel UND serverseitige Signale bis zur Einwilligung; „Nur Browser-Pixel blockieren" hält lediglich die Pixel zurück, während Conversions API und TikTok Events API unabhängig vom Banner-Status senden. Voreinstellung ist der strikte Modus – bestehende Installationen ändern ihr Verhalten durch das Update also nicht. Willigt der Besucher nachträglich ein, wird der Browser-Pixel im flexiblen Modus mit derselben Event-ID nachgeholt, sodass Meta/TikTok weiterhin deduplizieren.
* Neu (Pro): **TikTok Test Event Code** (Tab „Allgemein" → Box „TikTok"). Ist er gesetzt, werden Events-API-Anfragen als Testereignisse markiert und erscheinen in TikToks „Test Events"-Ansicht statt in den Livedaten. Er wird – genau wie der Meta-Test-Code – nach 12 Stunden automatisch deaktiviert, damit ein vergessener Code keine echten Käufe dauerhaft aus den Live-Berichten heraushält.
* Verbessert: **Abgelaufene Test-Event-Codes werden jetzt schon beim Öffnen der Einstellungen aufgeräumt.** Bisher wurde ein abgelaufener Meta-Test-Code erst beim nächsten Conversions-API-Request geleert – auf einer ruhigen Website konnte er also noch tagelang im Feld stehen, obwohl er längst wirkungslos war. Beide Felder (Meta und TikTok) leeren sich jetzt beim Aufruf des Tabs „Allgemein" selbst und zeigen einen kurzen Hinweis, dass der Code wegen Zeitablauf entfernt wurde.
* Behoben (Pro): **TikTok-Diagnostics-Warnung „content_id fehlt".** ViewContent und AddToCart sendeten die Produkt-ID bisher als einzelnes Feld statt innerhalb des `contents`-Arrays, InitiateCheckout trug gar kein `contents`. Alle E-Commerce-Events (ViewContent, AddToCart, InitiateCheckout, CompletePayment) übermitteln jetzt für jede Position ein explizites `content_id` samt `content_type` – für WooCommerce wie für SureCart, im Browser-Pixel wie in der Events API.
* Neu (Pro): **Formular-Leads an Google Ads und TikTok.** Das automatische Formular-Tracking (Tab „Erweitertes Tracking") hat neben dem bestehenden Meta-Event-Typ jetzt zusätzlich eine Auswahl für den TikTok-Event-Typ (SubmitForm, Contact, CompleteRegistration, Subscribe) sowie ein Feld für ein Google-Ads-Conversion-Label. Beide Plattformen feuern für dieselbe Absendung mit derselben Event-ID mit; ohne Conversion-Label wird – wie bei den URL-Events – kein Google-Ads-Event gesendet.
* Verbessert: **Oberfläche.** Hinweis- und Infoboxen berechnen ihre Breite jetzt einheitlich inklusive Innenabstand und Akzentbalken, sodass sie exakt bündig mit den übrigen Boxen abschließen statt seitlich herauszuragen. Die Hinweisboxen für nicht erkanntes WooCommerce/SureCart im Tab „E-Commerce" tragen keinen blauen Aktiv-Balken mehr, sondern sind mit dem Zusatz „Nicht erkannt" klar als inaktiv gekennzeichnet.
* Verbessert: Der Bereich „Neues Event erstellen" im Tab „URL-Events" ist jetzt einklappbar und startet zugeklappt – die Ereignisliste rückt damit in den Vordergrund. Beim Bearbeiten eines vorhandenen Events öffnet er sich automatisch.
* Verbessert: **Statusanzeige im Event Log.** Erfolgreich abgeschickte Ereignisse werden jetzt einheitlich grün dargestellt („Gesendet" bzw. „200 OK"); bisher bekam der Normalfall ohne Serverantwort ein neutrales graues Feld und wirkte dadurch wie ein Zwischenzustand. Fehler erscheinen weiterhin rot – neu auch dann, wenn die Plattform einen Fehlercode ohne Fehlertext liefert, was bislang weder farblich noch im Filter „Nur Fehler" auffiel.
* `dev-tools/test-suite.php` deckt Consent-Modus, TikTok-Test-Code inkl. 12-Stunden-Ablauf, die neuen Formularfelder und die Oberflächenänderungen ab (508 → 578 PHP-Tests); die drei JS-Test-Harnesses prüfen die neuen `contents`-Nutzlasten sowie das Zusammenspiel von Browser-Pixel und Serverversand im flexiblen Consent-Modus (30/64/40 → 44/82/53 Tests).

= 0.6.9 =
* **Behoben (Pro): WooCommerce- und SureCart-Tracking wurden überhaupt nicht ausgeführt.** Die E-Commerce-Integrationen wurden beim Start des Plugins initialisiert – also zu einem Zeitpunkt, zu dem WordPress WooCommerce bzw. SureCart noch gar nicht geladen hatte, weil es Plugins in alphabetischer Reihenfolge lädt und „pixel-made-simple-pro" vor beiden liegt. Ihre Prüfung „Ist WooCommerce überhaupt aktiv?" fiel dadurch immer negativ aus, und es wurde kein einziger Hook registriert – ohne jede Fehlermeldung. Betroffen waren sämtliche Shop-Events beider Plattformen (ViewContent, AddToCart, InitiateCheckout und Purchase). Die Initialisierung erfolgt jetzt erst, nachdem WordPress alle Plugins geladen hat.
* **Behoben (Pro): Auf der WooCommerce-Danke-Seite wurde kein Browser-Tracking-Code ausgegeben.** Browser- und Serverpfad des Purchase-Events teilten sich bisher eine gemeinsame Markierung an der Bestellung. Da WooCommerce bei vielen Zahlungsarten (z. B. Nachnahme) den Bestellstatus schon während des Bezahlvorgangs setzt, lief der serverseitige Versand regelmäßig vor dem Aufruf der Danke-Seite – und markierte die Bestellung als erledigt, bevor der Browser-Pixel überhaupt zum Zug kam. Beide Wege haben jetzt eine eigene Markierung: Der Browser-Pixel wird auf der Danke-Seite zuverlässig ausgegeben, ein erneuter Seitenaufruf (F5) löst weiterhin nichts doppelt aus, und die gemeinsame Event-ID sorgt dafür, dass Meta, TikTok und GA4 Browser- und Server-Event weiterhin als ein einziges Ereignis zählen.
* Geändert (Pro): Die Transaktions-ID der WooCommerce-Purchase-Events für Google Ads und GA4 ist jetzt die reine Bestellnummer (vorher mit vorangestelltem „pms_order_"). Damit lassen sich GA4-Umsatzberichte direkt den Bestellungen im Shop zuordnen. Die interne Event-ID für die Meta-/TikTok-Deduplizierung bleibt davon unberührt.
* `dev-tools/test-wp-environment.js` prüft jetzt zusätzlich in einer echten WordPress-Instanz, dass sämtliche WooCommerce- und SureCart-Hooks trotz der alphabetischen Ladereihenfolge registriert werden; `dev-tools/test-suite.php` deckt die getrennten Browser-/Server-Markierungen und beide ID-Formate ab (486 → 508 PHP-Tests).

= 0.6.8 =
* Neu (Pro): **Google Analytics 4 (GA4).** Neues Feld „GA4 Measurement ID" in der bestehenden Google-Ads-Box auf Tab „Allgemein" – eigenständig von Google Ads, funktioniert auch ohne konfigurierten Google-Ads-Tag. Teilt sich denselben gtag.js-Loader wie Google Ads: Sind auf einer Website sowohl Google Ads als auch GA4 konfiguriert, wird gtag.js nur einmal geladen, mit je einem eigenen `gtag('config', …)`-Aufruf pro Ziel. `view_item`/`add_to_cart`/`begin_checkout` aus dem bestehenden WooCommerce-/SureCart-Tracking erreichen GA4 automatisch, sobald die Measurement-ID gesetzt ist; `purchase` (WooCommerce-Danke-Seite inkl. Server-Side-Fallback sowie SureCart) sendet zusätzlich ein eigenes, von der Google-Ads-Conversion unabhängiges GA4-Standardevent mit Bestellwert, Währung und Positionen.
* Intern: Die an `pms-woocommerce.js`/`pms-surecart.js` lokalisierte `googleEnabled`-Einstellung berücksichtigt jetzt auch eine konfigurierte GA4-ID (vorher ausschließlich an Google Ads gebunden) – ohne diese Korrektur hätte ein Shop mit ausschließlich GA4 (ohne Google Ads) keines der drei Browser-Events an Google gesendet, obwohl gtag.js technisch bereits geladen war.
* `dev-tools/test-suite.php` sowie die JS-Test-Harnesses für WooCommerce/SureCart decken das Sanitizing der Measurement-ID, das kombinierte Google-Ads/GA4-Script-Rendering, das GA4-Purchase-Event (WooCommerce serverseitig, SureCart clientseitig) und die Admin-Oberfläche ab (462 → 486 PHP-Tests, SureCart-JS-Harness 36 → 40 Tests).

= 0.6.7 =
* Neu (Pro): **SureCart-Tracking.** Zweite E-Commerce-Integration neben WooCommerce, eigene Box auf Tab „E-Commerce" (nur sichtbar, wenn SureCart installiert ist). Trackt `ViewContent` (Produktseite), `AddToCart` und `InitiateCheckout` fürs Meta Pixel, Google Ads und TikTok Pixel, dedupliziert über dieselbe Event-ID wie im Browser. `Purchase` löst serverseitig über SureCarts eigene Hooks (`surecart/checkout_confirmed`, mit Fallback über `surecart/order_updated` für Bestellungen, die außerhalb der regulären Checkout-Bestätigung bezahlt werden) sowohl die Meta Conversions API als auch die TikTok Events API aus, dedupliziert über eine deterministische Event-ID (`pms_sc_order_{Checkout-ID}`) und ein Dedup-Flag in der Checkout-Metadata. Optionale Advanced Matching (gehashte Rechnungsdaten) für Meta/TikTok sowie ein eigenes Google-Ads-Conversion-Label für Purchase, analog zu WooCommerce.
* Intern: SureCart bietet – anders als WooCommerce – keinen Seiten-Render-Hook für die Bestellbestätigung; die Browser-Pixel-Aufrufe (inkl. optionaler Google Enhanced Conversions) laufen deshalb über `assets/pms-surecart.js`, das den SureCart-REST-Traffic der Checkout-Seite beobachtet, statt sich auf ein serverseitig eingebettetes Skript zu verlassen. Neuer Produktdaten-Resolver `PMS_Pro_SureCart_Product_Data` sowie zwei neue Klassen `PMS_Pro_SureCart`/`PMS_Pro_SureCart_Purchase` unter `src/pro/`.
* Hinweis: Die genaue REST-API-Struktur (insbesondere die Preis-/Produkt-Auflösungskette und die Event-Namen `surecart/checkout_confirmed`/`surecart/order_updated`) wurde anhand der offiziellen SureCart-Entwicklerdokumentation umgesetzt, aber noch nicht gegen ein echtes SureCart-Backend verifiziert – dieselbe Vorsicht wie bei der Google-Ads-/TikTok-Integration in 0.6.6. Vor einem produktiven Einsatz empfiehlt sich ein Testlauf mit einer echten SureCart-Installation und den Diagnose-Tools aller drei Plattformen.
* `dev-tools/test-suite.php` und ein neues `dev-tools/test-frontend-surecart-js.js` decken die Produktdaten-Auflösung, den Checkout-custom_data-Aufbau, den Purchase-Dispatch (Meta/TikTok, Dedup über beide Auslösewege, Consent-Gating) sowie die Browser-Beobachtung (ViewContent/AddToCart/InitiateCheckout/Purchase, Google-Enhanced-Conversions-Nachladung) ab (384 → 462 PHP-Tests, neuer JS-Harness mit 36 Tests). Nebenbei eine seit mehreren Versionen bestehende Lücke im deutschen Übersetzungs-Array geschlossen: Die WooCommerce-/E-Commerce-Tab-Strings aus 0.6.4–0.6.6 waren nie ins `$de`-Array in `dev-tools/build-translations.php` übernommen worden.

= 0.6.6 =
* Neu (Pro): **Google Ads Enhanced Conversions & TikTok Events API für WooCommerce.** Das bestehende WooCommerce-Tracking (seit 0.6.4) sendet `ViewContent`/`AddToCart`/`InitiateCheckout` jetzt zusätzlich an Google Ads (`gtag`) und TikTok Pixel (`ttq.track`), sofern die jeweilige Plattform aktiv ist – dieselbe Event-ID wie beim Meta-Pixel-Aufruf sorgt für plattformübergreifende Deduplizierung. `Purchase` auf der Danke-Seite feuert zusätzlich eine Google-Ads-Conversion (optionales eigenes Conversion-Label auf Tab „E-Commerce", da ein Store i. d. R. ein eigenes Label für den Kauf-Abschluss nutzt) inkl. optionaler Enhanced Conversions (gehashte Rechnungsdaten, sofern Purchase Advanced Matching aktiv ist) sowie einen echten Server-seitigen TikTok-Events-API-Request (neuer Toggle „Enable Events API" + Access Token auf Tab „Allgemein" in der TikTok-Box) – beide nutzen dieselbe deterministische Event-ID (`pms_order_{Bestell-ID}`) wie der bestehende Meta-CAPI-Versand. Google Ads hat bewusst keinen Server-seitigen Pfad (Enhanced Conversions ist bei Google rein Browser-seitig über `gtag`). Nur in Pixel Made Simple Pro.
* Hinweis: Die genauen Feld-/Hash-Anforderungen von Google Enhanced Conversions und der TikTok Events API wurden anhand der offiziellen Dokumentation umgesetzt, aber – anders als die Meta-Integration – noch nicht gegen echte Testdaten im Google-Ads- bzw. TikTok-Events-Manager verifiziert. Vor einem produktiven Einsatz empfiehlt sich ein Testlauf mit den jeweiligen Diagnose-Tools beider Plattformen.
* Intern: TikTok-Events-API-Requests werden bewusst nicht im Event Log protokolliert (dessen Schema ist auf Meta-Sprachgebrauch zugeschnitten) – Debugging läuft über die Diagnose-Tools von Google Ads/TikTok selbst. `dev-tools/test-suite.php` und `dev-tools/test-frontend-woocommerce-js.js` decken die neue Payload-Generierung, das Conversion-Label-Sanitizing und die neuen Browser-Events ab (339 → 384 PHP-Tests, 36 → 64 Tests im WooCommerce-JS-Harness).

= 0.6.5 =
* Admin-Oberfläche weiter aufgeräumt: „Event Log" und „Import / Export" sind seit 0.6.4 eigene Einträge in der WordPress-Seitenleiste – ihre Reiter in der oberen Tab-Leiste der Haupt-Einstellungsseite waren damit eine reine Dopplung und wurden entfernt. Die obere Leiste zeigt jetzt nur noch „Allgemein", „URL-Events", „Erweitertes Tracking" und „E-Commerce". Alte Lesezeichen/Links auf `?tab=log` bzw. `?tab=tools` funktionieren unverändert weiter.
* Tab „E-Commerce" ist jetzt immer sichtbar, auch ohne installiertes/aktives WooCommerce (vorher fehlte er auf Nicht-WooCommerce-Sites komplett). Ohne WooCommerce zeigt der Tab einen kurzen Hinweis, dass die Tracking-Optionen erscheinen, sobald WooCommerce aktiviert wird.
* Fix: Die WooCommerce-/Purchase-Einstellungen auf Tab „E-Commerce" blieben bislang auch dann als verstecktes Feld ungeschützt, wenn Pro aktiv, aber WooCommerce nicht installiert war – ein Speichern in diesem Zustand hätte eine zuvor gesetzte Konfiguration stillschweigend auf die Standardwerte zurückgesetzt. Die Prüfung berücksichtigt jetzt korrekt beide Voraussetzungen (Pro UND WooCommerce).
* Intern: `dev-tools/preview-admin.php` rendert jetzt auch den Tab „E-Commerce" sowie beide Sidebar-Shortcuts; `dev-tools/test-suite.php` deckt die bereinigte Tab-Leiste und die Persistenz-Korrektur ab (328 → 339 Tests).

= 0.6.4 =
* Neu (Pro): **WooCommerce-Tracking.** Trackt automatisch `ViewContent` (Produktseite), `AddToCart` (Archiv-/Mini-Cart-AJAX-Buttons und Single-Product-Formulare, inkl. Variable Products) und `InitiateCheckout` (klassischer Checkout und Block-/Cart-Checkout) im Browser-Pixel und via Conversions API – dedupliziert über dieselbe Event-ID wie überall sonst im Plugin. Produktidentifikator wählbar (Produkt-ID oder SKU, Tab „E-Commerce"), Preise/Namen/Kategorien werden dabei immer serverseitig frisch aus WooCommerce aufgelöst statt vom Browser übernommen. Auf Produkt-/Archivseiten wird bewusst keine feste Event-ID serverseitig eingebacken (Cache-Sicherheit auf vollständig gecachten Seiten); ist beim Laden noch keine Marketing-Einwilligung erteilt, werden Events zurückgehalten und automatisch nachgeholt, sobald der Besucher im Cookie-Banner zustimmt. Nur in Pixel Made Simple Pro und nur bei aktivem WooCommerce.
* Neu (Pro): **Purchase-Tracking mit Server-Side-Fallback.** Trackt `Purchase` auf der Danke-Seite (Browser-Pixel + Conversions API, mit derselben deterministischen Event-ID `pms_order_{Bestell-ID}` für beide) inkl. Positionen, Gesamtwert (Netto/Brutto wählbar, Tab „E-Commerce"), Steuer und Versand. Zusätzlicher Server-Side-Fallback (bei Zahlungsabschluss bzw. Bestellstatus „Abgeschlossen"/„In Bearbeitung") fängt Bestellungen ab, bei denen der Kunde nach der Zahlung nicht zur Danke-Seite zurückkehrt (z. B. externe Payment-Gateways) – beide Wege sind über ein Bestell-Flag gegenseitig gegen Doppelzählung abgesichert. Optionale Advanced Matching (Tab „E-Commerce", standardmäßig deaktiviert): sendet gehashte Rechnungsdaten (E-Mail, Telefon, Name, Adresse) für eine bessere Match-Qualität.
* Admin-Oberfläche neu strukturiert: eigener Tab **„E-Commerce"** bündelt sämtliche WooCommerce-/Purchase-Einstellungen (vorher verstreut auf Tab „Allgemein"); Tab „Werkzeuge" heißt jetzt **„Import / Export"**; „Event Log" und „Import / Export" sind zusätzlich direkt als eigene Einträge im Seitenleisten-Menü erreichbar, nicht mehr nur über die Tab-Leiste.
* Intern: `dev-tools/test-suite.php` und ein neues `dev-tools/test-frontend-woocommerce-js.js` decken die neue Produktdaten-Extraktion, das Purchase-Tracking (inkl. Dedup-Verhalten über beide Auslösewege), die neuen Sidebar-Menüpunkte und das Frontend-Skript ab (`npm test`/`composer test`; 245 → 328 Tests im PHP-Harness).

= 0.6.3 =
* Fix: Zusätzliche `function_exists()`-Absicherung um `pms_load_textdomain()` in beiden Hauptdateien, um einen Fatal Error „Cannot redeclare pms_load_textdomain()" beim gleichzeitigen Laden von Free und Pro sicher auszuschließen. Der bestehende Kollisionsschutz (siehe 0.6.2) verhindert diesen Fall bereits strukturell, da er per `return` abbricht, bevor die Funktion je deklariert wird – dieser zusätzliche Guard ist bewusste Doppelabsicherung nach demselben Muster, das an anderen Stellen der beiden Hauptdateien schon für `deactivate_plugins()`/`is_plugin_active()` verwendet wird.
* Kollisionsguard-Reihenfolge verifiziert: Der Guard steht in beiden Hauptdateien unmittelbar nach `defined('ABSPATH') || exit;` und vor jeder Konstanten-Definition, Funktionsdeklaration und jedem `require_once`.

= 0.6.2 =
* Härtung des Free/Pro-Kollisionsschutzes: Die Admin-Notice bei gleichzeitiger Aktivierung ist jetzt in beiden Versionen identisch formuliert und der automatische Deaktivierungs-Mechanismus bewusst asymmetrisch – im Kollisionsfall bleibt immer Pro aktiv, nie Free (spätestens ab dem nächsten Seitenaufruf, siehe Code-Kommentar in `pixel-made-simple.php`/`pixel-made-simple-pro.php` für den Bulk-Aktivieren-Sonderfall).
* Menü-Label in der WordPress-Seitenleiste von „Pixel Tracker" zu „Pixel Made Simple" geändert.
* **Weitere Free/Pro-Abgrenzung:**
  * **Google Ads & TikTok Pixel (Pro):** Beide Plattformen sind jetzt Pixel Made Simple Pro vorbehalten. Tab „Allgemein" zeigt sie in der Free-Version als ausgegraute Teaser-Boxen mit „Upgrade to Pro"-Button; serverseitig zusätzlich abgesichert (die Skript-Ausgabe prüft den Pro-Status unabhängig von der UI). Meta Pixel & Conversions API bleiben vollständig kostenlos.
  * **Custom Events (Free-Limit geändert):** Die Free-Version erlaubt jetzt maximal 2 Event-Regeln **insgesamt** (vorher: beliebig viele Regeln, aber nur 2 gleichzeitig aktiv). Ab dem 2. Event ist der „Event hinzufügen"-Button deaktiviert mit dem Hinweis „In der Free-Version sind bis zu 2 URL-Events enthalten. Upgrade auf Pro für unbegrenzte Events."
  * **Konfiguration importieren (jetzt Pro):** Der JSON-Import ist jetzt zusammen mit dem Export ein reines Pro-Feature (vorher war nur der Export Pro-only). Serverseitig zusätzlich abgesichert, nicht nur in der UI ausgeblendet.
  * **Unverändert kostenlos:** Meta Pixel & Conversions API, URL-Events (bis zum neuen 2er-Limit), Formular-Auto-Grabber, Cookie-Consent-Erkennung, Admin-Live-Debug-Leiste, Event Log (mit den seit 0.6.1 bestehenden Free-Einschränkungen).
* Neu: `composer.json`/`package.json` im Projekt-Root als dünne Wrapper um die bestehenden `dev-tools/`-Test- und Build-Skripte (`composer test`, `npm test`, `npm run build`) – rein für Entwickler-Tooling, ohne Auswirkung auf die ausgelieferten Plugin-ZIPs.

= 0.6.1 =
* Neu: **Event Log** (Tab „Event Log") – protokolliert Browser- und Conversions-API-Events in einer eigenen Datenbanktabelle, damit du Conversions direkt im WordPress-Backend nachvollziehen kannst, ohne den Meta Events Manager zu öffnen. Zeigt Zeitpunkt, Event, Event-ID (für den Dedup-Abgleich mit dem Meta Events Manager), Quelle (Browser/CAPI/beides), Status (grün „200 OK", rot „Fehler" inkl. Tooltip mit der Fehlermeldung, neutral „Gesendet" für nicht-blockierende Fire-and-Forget-Sends ohne Rückmeldung) und die übergebenen Match-Keys (z. B. `em`, `fbc`) – niemals die Werte selbst.
* Datenschutz: Es werden ausschließlich Feldnamen (`user_data_keys`), niemals Klartext-Werte oder Hashes gespeichert.
* Automatisches Aufräumen: ein täglicher Cron löscht abgelaufene Einträge. Free: fest 3 Tage. Pro: 3/7/14/30 Tage wählbar (Tab „Event Log", Dropdown).
* Pro-Funktionen im Event Log: Filter nach Status (alle/nur Fehler) und Event-Name, wählbare Aufbewahrungsdauer. In der Free-Version werden diese Controls sichtbar, aber deaktiviert angezeigt (mit Upgrade-Hinweis) statt komplett ausgeblendet zu werden.
* „Log leeren"-Button (nonce-gesichert, mit Bestätigungsdialog) in beiden Versionen uneingeschränkt nutzbar.
* Der Formular-Auto-Grabber meldet jetzt zusätzlich, ob der Browser-Pixel tatsächlich gefeuert hat (`browser_fired`) – ermöglicht einen eigenständigen Event-Log-Eintrag, wenn z. B. die Conversions API (noch) nicht konfiguriert ist, der Browser-Pixel aber ganz normal lief.

= 0.6.0 =
* Rebrand & Relaunch als **„Pixel Made Simple"** (vormals „Lightweight Meta Pixel & CAPI Tracker"). Gleicher Funktionskern, jetzt als Free/Pro-Aufteilung angelegt: Free bleibt vollständig eigenständig nutzbar und wird über WordPress.org vertrieben; **Pixel Made Simple Pro** ist ein separates Plugin für Zusatzfunktionen, verteilt über GitHub Releases mit automatischen Updates. Beide teilen sich denselben Options-Key (`pms_settings`), ein Upgrade übernimmt die bestehende Konfiguration nahtlos.
* **Breaking Change für Umsteiger:** Alle internen Bezeichner wurden von `LMPCT_`/`lmpct_` auf `PMS_`/`pms_` umbenannt – u. a. alle Filter-Hooks (`lmpct_allow_tracking` → `pms_allow_tracking` usw., vollständige Liste oben unter „Welche Filter gibt es?"), der Options-Key (`lmpct_settings` → `pms_settings`) sowie das First-Touch-Cookie (`lmpct_attribution` → `pms_attribution`). Eigener Code, der einen `lmpct_*`-Filter nutzt, wird nach dem Update stillschweigend nicht mehr aufgerufen und muss manuell auf `pms_*` angepasst werden.
* Quellcode liegt jetzt in einem `src/`-Monorepo mit automatisiertem GitHub-Actions-Release-Build (beide ZIPs werden bei jedem Versions-Tag automatisch gebaut) – kein manuelles ZIP-Bauen mehr nötig.
* Erste konkrete Free/Pro-Aufteilung:
  * **Custom Events (Free-Limit):** Die kostenlose Version erlaubt maximal 2 gleichzeitig aktive Custom Events (Tab „URL-Events"). Beliebig viele Event-Regeln lassen sich weiterhin anlegen; ab dem 3. aktiven Event zeigt die Tabelle einen gesperrten Status-Schalter mit Upgrade-Hinweis statt einer funktionierenden Aktivierung.
  * **UTM- & Attribution-Tracking (Pro):** First-Touch-/UTM-Passthrough-Cookie, `custom_data`/`fbc` für die CAPI und der automatische UTM-Formular-Fill sind jetzt Pixel Made Simple Pro vorbehalten. Der Tab „Erweitertes Tracking" zeigt in der Free-Version dafür einen ausgegrauten Teaser mit „Upgrade to Pro"-Button; die Einstellungen selbst bleiben beim Umstieg auf Pro erhalten.
  * **Konfiguration exportieren (Pro):** Der JSON-Export von Einstellungen/Event-Regeln ist jetzt ein Pro-Feature (serverseitig zusätzlich abgesichert, nicht nur in der UI ausgeblendet). Der Import bleibt in beiden Versionen uneingeschränkt nutzbar.
  * **Unverändert kostenlos in beiden Versionen:** alle Plattformen (Meta/Google/TikTok), URL-Events, Formular-Auto-Grabber, Cookie-Consent-Erkennung und die Admin-Live-Debug-Leiste (Pixel & CAPI Health Checker).

= Ältere Versionen (als „Lightweight Meta Pixel & CAPI Tracker") =

Die folgenden Einträge sind unverändert die historischen Changelog-Einträge aus der Zeit vor dem Rebrand oben – Bezeichner darin (`LMPCT_*`, `lmpct_*`, Klassen-/Dateinamen) spiegeln absichtlich den jeweils damals gültigen Stand wider, nicht die aktuellen `PMS_*`-Namen.

= 0.5.7 =
* Kritischer Bugfix: `assets/frontend.js` (Formular-Auto-Grabber und UTM-Form-Fill) konnte auf einzelnen Seiten trotz aktivem Feature gar nicht erst geladen werden, weil das serverseitige URL-Include/Exclude-Matching aus v0.5.6 und die tatsächliche Browser-URL in bestimmten Konstellationen (Trailing Slashes, Proxies/CDNs, mehrsprachige URL-Präfixe u. Ä.) auseinanderlaufen konnten – sichtbar u. a. als `Uncaught ReferenceError` im Browser, wenn nachgelagerter Code auf das nicht vorhandene Skript zugriff. `LMPCT_Frontend::enqueue_frontend()` liefert das Skript jetzt aus, sobald Tracking aktiv ist UND mindestens einer der beiden Master-Toggles (Formular-Tracking oder UTM-Form-Fill) eingeschaltet ist; die eigentliche URL-Auswertung (Alle Seiten/Include/Exclude, `*`-Wildcards) übernimmt ab jetzt ausschließlich der Browser anhand seines eigenen, zuverlässig aufgelösten `window.location.pathname`.
* Korrektur zu v0.5.6: Der Meta Test Event Code wird nicht mehr an den Browser-Pixel übergeben. Entgegen der ursprünglichen Annahme akzeptiert Meta's Pixel-SDK ihn nicht als `custom_data`-Feld in `fbq()`-Aufrufen und ignoriert das Event dann im Test-Events-Stream, statt es dort anzuzeigen. Der Test-Code bleibt ausschließlich in der serverseitigen Conversions-API-Payload (`class-lmpct-capi.php`) – dort hat er immer funktioniert.
* UTM-Form-Fill: Feld-Umfang auf die 3 in der Praxis relevanten Kernwerte Source, Campaign und Medium reduziert (zuvor 7 generische UTM-/Klick-ID-Felder). Zusätzlich zu `utm_source`/`utm_campaign`/`utm_medium` werden jetzt auch die kürzeren Namens-Alternativen `source`/`campaign`/`medium` erkannt; fbclid/gclid bleiben als Signale für die Source-Herleitung erhalten, schreiben aber kein eigenes Formularfeld mehr. Tab „Erweitertes Tracking" zeigt die erkannten Feldnamen/CSS-Klassen jetzt als übersichtliche Tabelle statt als Fließtext.
* Das an `frontend.js` übergebene JavaScript-Objekt heißt jetzt `lmpct_settings` (vorher `lmpctFront`).

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
