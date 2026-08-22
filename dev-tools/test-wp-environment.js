#!/usr/bin/env node
'use strict';

/**
 * Headless WordPress-Testumgebung fuer Pixel Made Simple (Free + Pro) via
 * @wp-playground/cli (WASM-PHP + SQLite, kein Docker/MySQL noetig).
 *
 * Baut aus src/ zwei temporaere Plugin-Ordner (dieselben rsync --exclude-
 * Listen wie .github/workflows/release.yml, siehe stagePluginBuild() unten),
 * mountet beide in eine frische Playground-Instanz und aktiviert dort per
 * WordPress' eigener activate_plugin() zuerst Free, dann Pro -- innerhalb
 * DESSELBEN PHP-Prozesses (ein einzelner runPHP-Blueprint-Schritt).
 *
 * Das ist absichtlich EIN Prozess, nicht zwei getrennte Playground-Boots:
 * "Free aktivieren, dann Pro aktivieren" landet in echtem WordPress zwar
 * meist in zwei getrennten HTTP-Requests (Dominik klickt heute Free an,
 * naechste Woche Pro) -- der eigentliche Härtefall ist aber, dass WordPress'
 * eigener Bulk-Activate-Admin-Callback (mehrere Plugins auf einmal ankreuzen)
 * GENAU SO EINEN einzelnen Prozess erzeugt, in dem beide Hauptdateien nach-
 * einander per include_once geladen werden. Der Early Guard in beiden
 * Bootstrap-Dateien existiert laut CLAUDE.md ("Free/Pro-Kollisionsschutz")
 * genau fuer diesen Fall -- ein einzelner Prozess mit zwei activate_plugin()-
 * Aufrufen deckt ihn also bereits ab, eine zusaetzliche "Bulk"-Variante waere
 * hier keine staerkere Pruefung, nur Redundanz.
 *
 * WICHTIG, falls dieses Skript um weitere Aktivierungs-Szenarien ergaenzt
 * wird: activate_plugin() inkludiert die Haupt-Datei per include_once, PHPs
 * Once-Dedup gilt fuer die gesamte Lebensdauer des PHP-Prozesses. Ein
 * zweiter activate_plugin()-Aufruf fuer DIESELBE Datei im SELBEN Prozess
 * (z. B. nach einem zwischenzeitlichen deactivate_plugins() zum Zuruecksetzen)
 * fuehrt NICHT erneut den Dateiinhalt aus -- auch nicht den Kollisionsguard
 * am Dateianfang. Ein frueherer Entwurf dieses Skripts hatte genau deshalb
 * ein zweites "bulk"-Szenario im selben Prozess wie das erste, das dadurch
 * einen falschen Fehlschlag produzierte (Free blieb scheinbar aktiv, weil
 * Pros Guard beim zweiten Anlauf gar nicht mehr lief). Ein echtes zweites
 * Szenario braucht einen komplett neuen runCLI()-Aufruf (= neuer PHP-Prozess).
 *
 * Fuer das eine Szenario wird geprueft: kein Fatal Error bei der Aktivierung,
 * am Ende ist ausschliesslich Pro aktiv (Free hat sich selbst deaktiviert),
 * die Tabelle {$wpdb->prefix}pms_event_log wurde angelegt, und die
 * Standard-WordPress-Optionstabelle existiert.
 *
 * Die eigentliche Pruefung laeuft komplett in PHP (siehe PHP_TEST_SCRIPT
 * weiter unten) und schreibt ein JSON-Ergebnis in ein gemountetes Host-
 * Verzeichnis -- absichtlich NICHT per Text-Parsing der Playground-CLI-
 * Konsolenausgabe, das waere fragil (siehe CLAUDE.md "Wichtigste Lektion
 * beim Testen" zum Stub-Testing: ein zu grob vereinfachter Pruefmechanismus
 * kann einen echten Bug verdecken). Ein per try/catch nicht aufgefangener
 * Fatal Error laesst runCLI() selbst fehlschlagen (kein report.json) -- auch
 * das fuehrt zu Exit-Code 1.
 *
 * Aufruf:
 *   npm run test:wp
 *
 * Nur die Free/Pro-Ordner unter build/ neu bauen, ohne Playground zu
 * starten (wird von "npm run wp:serve" als prewp:serve-Hook genutzt, damit
 * der manuelle Server immer einen frischen Stand mountet):
 *   node dev-tools/test-wp-environment.js --stage-only
 */

const fs = require('fs');
const path = require('path');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SRC_DIR = path.join(ROOT, 'src');
const BUILD_DIR = path.join(ROOT, 'build');
const FREE_DIR = path.join(BUILD_DIR, 'pixel-made-simple');
const PRO_DIR = path.join(BUILD_DIR, 'pixel-made-simple-pro');

const FREE_SLUG = 'pixel-made-simple/pixel-made-simple.php';
const PRO_SLUG = 'pixel-made-simple-pro/pixel-made-simple-pro.php';

// Muss exakt den --exclude-Listen in .github/workflows/release.yml
// entsprechen. Als Nebeneffekt validiert dieses Skript bei jedem Lauf, dass
// diese beiden Listen ueberhaupt ein startfaehiges Plugin ergeben -- die
// Release-Action selbst wurde laut CLAUDE.md noch nie gegen einen echten
// Tag-Push verifiziert.
const FREE_EXCLUDES = ['pixel-made-simple-pro.php', 'pro', 'plugin-update-checker'];
const PRO_EXCLUDES = ['pixel-made-simple.php'];

function stagePluginBuild(destDir, excludeTopLevelEntries) {
	fs.rmSync(destDir, { recursive: true, force: true });
	fs.mkdirSync(destDir, { recursive: true });
	fs.cpSync(SRC_DIR, destDir, {
		recursive: true,
		filter(srcPath) {
			const rel = path.relative(SRC_DIR, srcPath);
			if (rel === '') {
				return true;
			}
			const topLevelEntry = rel.split(path.sep)[0];
			return !excludeTopLevelEntries.includes(topLevelEntry);
		},
	});
}

function stageBuilds() {
	stagePluginBuild(FREE_DIR, FREE_EXCLUDES);
	stagePluginBuild(PRO_DIR, PRO_EXCLUDES);
	console.log(`Free gebaut nach: ${path.relative(ROOT, FREE_DIR)}`);
	console.log(`Pro gebaut nach:  ${path.relative(ROOT, PRO_DIR)}`);
}

// WP_ADMIN muss VOR wp-load.php definiert werden: is_admin() haengt genau
// daran, und der Kollisionsguard in beiden Hauptdateien ruft
// deactivate_plugins() nur "if ( is_admin() )" auf (siehe pixel-made-simple.php
// / pixel-made-simple-pro.php). Ohne diese Zeile wuerde der Guard hier zwar
// korrekt die Redeklaration verhindern, aber die eigentliche Deaktivierung
// der jeweils anderen Version stillschweigend uebersprungen -- ein reiner
// Testumgebungs-Artefakt, kein echtes Verhalten von activate_plugin() im
// echten wp-admin (das immer mit WP_ADMIN=true laeuft).
const PHP_TEST_SCRIPT = `<?php
define( 'WP_ADMIN', true );
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$free_slug = 'pixel-made-simple/pixel-made-simple.php';
$pro_slug  = 'pixel-made-simple-pro/pixel-made-simple-pro.php';

function pms_test_state() {
	global $wpdb;
	$table = $wpdb->prefix . 'pms_event_log';
	return array(
		'active_plugins'         => array_values( (array) get_option( 'active_plugins', array() ) ),
		'event_log_table_exists' => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
		'options_table_exists'   => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->options ) ),
		'log_db_version_option'  => get_option( 'pms_log_db_version', null ),
	);
}

function pms_test_activate( $slug ) {
	try {
		$result = activate_plugin( $slug );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'error' => $result->get_error_message() );
		}
		return array( 'ok' => true, 'error' => null );
	} catch ( \Throwable $e ) {
		return array( 'ok' => false, 'error' => get_class( $e ) . ': ' . $e->getMessage() );
	}
}

$report                     = array();
$report['activate_free']    = pms_test_activate( $free_slug );
$report['state_after_free'] = pms_test_state();
$report['activate_pro']     = pms_test_activate( $pro_slug );
$report['state_after_pro']  = pms_test_state();

$out_dir = '/wordpress/wp-content/pms-test-output';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}
file_put_contents( $out_dir . '/report.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) );

echo "PMS_TEST_REPORT_WRITTEN\\n";
`;

function evaluateReport(report) {
	const failures = [];

	if (!report.activate_free || report.activate_free.ok !== true) {
		failures.push(`Free-Aktivierung fehlgeschlagen: ${report.activate_free && report.activate_free.error}`);
	}
	if (!report.activate_pro || report.activate_pro.ok !== true) {
		failures.push(`Pro-Aktivierung fehlgeschlagen (Fatal Error?): ${report.activate_pro && report.activate_pro.error}`);
	}

	const state = report.state_after_pro;
	if (!state) {
		failures.push('state_after_pro fehlt im Report.');
		return failures;
	}

	const active = state.active_plugins;
	const isOnlyPro = Array.isArray(active) && active.length === 1 && active[0] === PRO_SLUG;
	if (!isOnlyPro) {
		failures.push(
			`Kollisionsschutz hat nicht wie erwartet gegriffen: active_plugins sollte genau ["${PRO_SLUG}"] sein (Pro aktiv, Free hat sich selbst deaktiviert), ist aber ${JSON.stringify(active)}`
		);
	}
	if (!state.event_log_table_exists) {
		failures.push('Tabelle pms_event_log wurde nicht angelegt.');
	}
	if (!state.options_table_exists) {
		failures.push('WordPress-Optionstabelle (wp_options) wurde nicht gefunden.');
	}
	if (!state.log_db_version_option) {
		failures.push('Option pms_log_db_version wurde nicht gesetzt (PMS_Logger::activate() lief nicht?).');
	}

	return failures;
}

async function runPlaygroundChecks() {
	stageBuilds();

	let runCLI;
	try {
		({ runCLI } = await import('@wp-playground/cli'));
	} catch (err) {
		console.error('@wp-playground/cli ist nicht installiert. Bitte zuerst "npm install" ausfuehren.');
		process.exitCode = 1;
		return;
	}

	const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pms-wp-test-'));
	const reportFile = path.join(outputDir, 'report.json');

	console.log('Starte WordPress Playground (WASM-PHP + SQLite, kein Docker/MySQL noetig)...');
	console.log('Beim allerersten Lauf laedt Playground WordPress-Core + PHP-Runtime herunter -- das kann ein paar Minuten dauern, danach wird lokal gecacht.');

	try {
		await runCLI({
			command: 'run-blueprint',
			mount: [
				{ hostPath: FREE_DIR, vfsPath: '/wordpress/wp-content/plugins/pixel-made-simple' },
				{ hostPath: PRO_DIR, vfsPath: '/wordpress/wp-content/plugins/pixel-made-simple-pro' },
				{ hostPath: outputDir, vfsPath: '/wordpress/wp-content/pms-test-output' },
			],
			blueprint: {
				steps: [{ step: 'runPHP', code: PHP_TEST_SCRIPT }],
			},
		});
	} catch (err) {
		console.error('Playground-Blueprint ist fehlgeschlagen (siehe Ausgabe oben) -- vermutlich ein Fatal Error ausserhalb der try/catch-Absicherung im Test-PHP:');
		console.error(err && err.message ? err.message : err);
		process.exitCode = 1;
		return;
	}

	if (!fs.existsSync(reportFile)) {
		console.error('report.json wurde nicht geschrieben -- das Blueprint ist vermutlich vor Erreichen des runPHP-Schritts abgebrochen. Siehe Playground-Ausgabe oben.');
		process.exitCode = 1;
		return;
	}

	const report = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
	fs.rmSync(outputDir, { recursive: true, force: true });

	const failures = evaluateReport(report);

	// Szenario 2 laeuft unabhaengig vom Ergebnis von Szenario 1, damit ein
	// Fehlschlag oben nicht die Sicht auf einen zweiten, anderen Fehler
	// verdeckt -- beide Fehlerlisten werden gemeinsam ausgegeben.
	failures.push(...(await runEcommerceHookChecks(runCLI)));

	if (failures.length > 0) {
		console.error(`\n${failures.length} Pruefung(en) fehlgeschlagen:\n`);
		failures.forEach((failure) => console.error(`  - ${failure}`));
		process.exitCode = 1;
		return;
	}

	console.log('\nAlle Pruefungen erfolgreich:');
	console.log('  - Free aktiviert, dann Pro aktiviert (ein PHP-Prozess, wie beim Bulk-Aktivieren): kein Fatal Error.');
	console.log('  - Kollisionsschutz griff: Free hat sich selbst deaktiviert, am Ende ist nur Pro aktiv.');
	console.log('  - Tabelle pms_event_log und WordPress-Optionstabelle wurden angelegt.');
	console.log('  - E-Commerce-Hooks (WooCommerce + SureCart) werden trotz alphabetischer');
	console.log('    Ladereihenfolge registriert -- inkl. woocommerce_thankyou mit Prioritaet 10.');
}

/**
 * Szenario 2 "E-Commerce-Hooks" (seit v0.6.9).
 *
 * Regressionstest fuer den v0.6.9-Bugfix in pixel-made-simple-pro.php: Die
 * E-Commerce-Integrationen pruefen in ihrem init() per class_exists(), ob
 * WooCommerce/SureCart ueberhaupt vorhanden sind. Wurden diese init()-Aufrufe
 * -- wie bis v0.6.8 -- direkt im Bootstrap ausgefuehrt, war die Antwort immer
 * false: WordPress laedt aktive Plugins in der alphabetisch sortierten
 * Reihenfolge der active_plugins-Option, "pixel-made-simple-pro" liegt damit
 * VOR "surecart" und VOR "woocommerce". Das Ergebnis war ein voellig stiller
 * Totalausfall -- kein Fatal Error, keine Warnung, nur nie registrierte Hooks
 * (und damit u. a. kein Purchase-Skript auf der Danke-Seite und kein
 * Event-Log-Eintrag). Genau diese Stille ist der Grund, warum der Fall einen
 * eigenen automatisierten Test bekommt: der PHP-Stub-Harness
 * (dev-tools/test-suite.php) kann ihn strukturell nicht finden, weil er die
 * WooCommerce-Marker-Klasse immer schon vor dem Test deklariert.
 *
 * Aufbau (bewusst ein EIGENER runCLI()-Aufruf, siehe Datei-Kopfkommentar zur
 * include_once-Prozess-Dedup): zwei runPHP-Schritte in EINEM Blueprint, also
 * dieselbe WordPress-Instanz/DB, aber zwei getrennte PHP-Prozesse. Schritt 1
 * aktiviert die Plugins, Schritt 2 ist ein frischer Request, in dem WordPress
 * sie erstmals selbst in der gespeicherten Reihenfolge laedt -- nur Schritt 2
 * bildet den echten Seitenaufruf ab, um den es hier geht.
 */
const FAKE_SHOP_PLUGINS = {
	// Minimal, aber im entscheidenden Punkt originalgetreu: beide echten
	// Plugins definieren ihre Hauptklasse beim Einbinden der Hauptdatei und
	// nicht erst auf einem Hook. Genau darauf stuetzt sich der
	// class_exists()-Guard in den init()-Methoden.
	woocommerce: { file: 'woocommerce.php', code: "<?php\n/**\n * Plugin Name: WooCommerce\n * Description: Minimal-Attrappe fuer den Ladereihenfolge-Test (kein echtes WooCommerce).\n * Version: 9.0.0\n */\ndefined( 'ABSPATH' ) || exit;\nif ( ! class_exists( 'WooCommerce', false ) ) {\n\tclass WooCommerce {}\n}\n" },
	surecart: { file: 'surecart.php', code: "<?php\n/**\n * Plugin Name: SureCart\n * Description: Minimal-Attrappe fuer den Ladereihenfolge-Test (kein echtes SureCart).\n * Version: 3.0.0\n */\ndefined( 'ABSPATH' ) || exit;\nif ( ! class_exists( 'SureCart', false ) ) {\n\tclass SureCart {}\n}\n" },
};

const ECOM_ACTIVATE_SCRIPT = "<?php\ndefine( 'WP_ADMIN', true );\nrequire_once '/wordpress/wp-load.php';\nrequire_once ABSPATH . 'wp-admin/includes/plugin.php';\n\n$report = array( 'activation_errors' => array() );\n\nforeach ( array(\n\t'woocommerce/woocommerce.php',\n\t'surecart/surecart.php',\n\t'pixel-made-simple-pro/pixel-made-simple-pro.php',\n) as $slug ) {\n\ttry {\n\t\t$result = activate_plugin( $slug );\n\t\tif ( is_wp_error( $result ) ) {\n\t\t\t$report['activation_errors'][ $slug ] = $result->get_error_message();\n\t\t}\n\t} catch ( \\Throwable $e ) {\n\t\t$report['activation_errors'][ $slug ] = get_class( $e ) . ': ' . $e->getMessage();\n\t}\n}\n\n// Die Reihenfolge, in der wp-settings.php die Plugins beim NAECHSTEN\n// Request einbinden wird -- genau die Ursache, um die es hier geht.\n$report['active_plugins_order'] = array_values( (array) get_option( 'active_plugins', array() ) );\n\nfile_put_contents( '/wordpress/wp-content/pms-test-output/ecommerce-report.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) );\n";

const ECOM_VERIFY_SCRIPT = "<?php\nrequire_once '/wordpress/wp-load.php';\n\n$file   = '/wordpress/wp-content/pms-test-output/ecommerce-report.json';\n$report = json_decode( (string) file_get_contents( $file ), true );\n$report = is_array( $report ) ? $report : array();\n\n$report['woocommerce_class_exists'] = class_exists( 'WooCommerce' );\n$report['surecart_class_exists']    = class_exists( 'SureCart' );\n$report['pro_classes_loaded']       = class_exists( 'PMS_Pro_Woo_Purchase' ) && class_exists( 'PMS_Pro_SureCart_Purchase' );\n\n// Der eigentliche Kern: sind die Hooks tatsaechlich registriert?\n$report['hooks'] = array(\n\t'woocommerce_thankyou'         => false !== has_action( 'woocommerce_thankyou', array( 'PMS_Pro_Woo_Purchase', 'track_thankyou' ) ),\n\t'woocommerce_payment_complete' => false !== has_action( 'woocommerce_payment_complete', array( 'PMS_Pro_Woo_Purchase', 'maybe_track_fallback' ) ),\n\t'woo_enqueue_scripts'          => false !== has_action( 'wp_enqueue_scripts', array( 'PMS_Pro_WooCommerce', 'enqueue_scripts' ) ),\n\t'woo_ajax_track'               => false !== has_action( 'wp_ajax_nopriv_pms_woo_track', array( 'PMS_Pro_WooCommerce', 'handle_track' ) ),\n\t'woo_capi_filter'              => false !== has_filter( 'pms_capi_event_data', array( 'PMS_Pro_WooCommerce', 'filter_capi_event_data' ) ),\n\t'surecart_checkout_confirmed'  => false !== has_action( 'surecart/checkout_confirmed', array( 'PMS_Pro_SureCart_Purchase', 'track_confirmed' ) ),\n\t'surecart_enqueue_scripts'     => false !== has_action( 'wp_enqueue_scripts', array( 'PMS_Pro_SureCart', 'enqueue_scripts' ) ),\n);\n\n// woocommerce_thankyou muss mit Prioritaet 10 haengen -- has_action()\n// liefert die Prioritaet zurueck, nicht nur true.\n$report['thankyou_priority'] = has_action( 'woocommerce_thankyou', array( 'PMS_Pro_Woo_Purchase', 'track_thankyou' ) );\n\nfile_put_contents( $file, wp_json_encode( $report, JSON_PRETTY_PRINT ) );\n";

function stageFakeShopPlugins(baseDir) {
	const dirs = {};
	Object.entries(FAKE_SHOP_PLUGINS).forEach(([slug, def]) => {
		const dir = path.join(baseDir, slug);
		fs.rmSync(dir, { recursive: true, force: true });
		fs.mkdirSync(dir, { recursive: true });
		fs.writeFileSync(path.join(dir, def.file), def.code, 'utf8');
		dirs[slug] = dir;
	});
	return dirs;
}

function evaluateEcommerceReport(report) {
	const failures = [];

	Object.entries(report.activation_errors || {}).forEach(([slug, message]) => {
		failures.push(`Aktivierung fehlgeschlagen (${slug}): ${message}`);
	});

	const order = report.active_plugins_order || [];
	const proIndex = order.indexOf(PRO_SLUG);
	const wooIndex = order.indexOf('woocommerce/woocommerce.php');

	// Keine eigentliche Pruefung, sondern die Absicherung der Ausgangslage:
	// Stuende Pro hier ploetzlich HINTER WooCommerce, wuerde dieser Test den
	// Bug gar nicht mehr nachstellen und stillschweigend wertlos werden.
	if (proIndex === -1 || wooIndex === -1 || proIndex > wooIndex) {
		failures.push(
			`Testvoraussetzung verletzt: Pro muesste in active_plugins VOR WooCommerce stehen (alphabetisch), ist aber ${JSON.stringify(order)}`
		);
	}

	if (!report.woocommerce_class_exists || !report.surecart_class_exists) {
		failures.push('Die Shop-Attrappen wurden im Verifikations-Request nicht geladen -- der Test prueft damit nichts Reales.');
	}
	if (!report.pro_classes_loaded) {
		failures.push('Die Pro-E-Commerce-Klassen wurden nicht geladen.');
	}

	Object.entries(report.hooks || {}).forEach(([name, registered]) => {
		if (!registered) {
			failures.push(
				`Hook "${name}" wurde NICHT registriert -- die E-Commerce-init()-Aufrufe laufen offenbar wieder zu frueh (vor dem Laden von WooCommerce/SureCart). Siehe den plugins_loaded-Block am Ende von src/pixel-made-simple-pro.php.`
			);
		}
	});

	if (report.thankyou_priority !== 10) {
		failures.push(`woocommerce_thankyou haengt mit Prioritaet ${report.thankyou_priority} statt der erwarteten 10.`);
	}

	return failures;
}

async function runEcommerceHookChecks(runCLI) {
	const fakeBase = fs.mkdtempSync(path.join(os.tmpdir(), 'pms-fake-shops-'));
	const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pms-wp-ecom-'));
	const reportFile = path.join(outputDir, 'ecommerce-report.json');
	const fakeDirs = stageFakeShopPlugins(fakeBase);

	console.log('\nSzenario 2: E-Commerce-Hooks bei alphabetischer Plugin-Ladereihenfolge...');

	try {
		await runCLI({
			command: 'run-blueprint',
			mount: [
				{ hostPath: PRO_DIR, vfsPath: '/wordpress/wp-content/plugins/pixel-made-simple-pro' },
				{ hostPath: fakeDirs.woocommerce, vfsPath: '/wordpress/wp-content/plugins/woocommerce' },
				{ hostPath: fakeDirs.surecart, vfsPath: '/wordpress/wp-content/plugins/surecart' },
				{ hostPath: outputDir, vfsPath: '/wordpress/wp-content/pms-test-output' },
			],
			blueprint: {
				steps: [
					{ step: 'runPHP', code: ECOM_ACTIVATE_SCRIPT },
					{ step: 'runPHP', code: ECOM_VERIFY_SCRIPT },
				],
			},
		});
	} catch (err) {
		console.error('Playground-Blueprint (E-Commerce-Hooks) ist fehlgeschlagen:');
		console.error(err && err.message ? err.message : err);
		fs.rmSync(fakeBase, { recursive: true, force: true });
		fs.rmSync(outputDir, { recursive: true, force: true });
		return ['Szenario "E-Commerce-Hooks" konnte nicht ausgefuehrt werden.'];
	}

	fs.rmSync(fakeBase, { recursive: true, force: true });

	if (!fs.existsSync(reportFile)) {
		fs.rmSync(outputDir, { recursive: true, force: true });
		return ['ecommerce-report.json wurde nicht geschrieben.'];
	}

	const report = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
	fs.rmSync(outputDir, { recursive: true, force: true });

	return evaluateEcommerceReport(report);
}

async function main() {
	const args = process.argv.slice(2);
	if (args.includes('--stage-only')) {
		stageBuilds();
		return;
	}
	await runPlaygroundChecks();
}

main().catch((err) => {
	console.error('Unerwarteter Fehler im Test-Runner:');
	console.error(err);
	process.exitCode = 1;
});
