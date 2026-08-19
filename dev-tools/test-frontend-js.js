/**
 * Funktionaler Test-Harness für assets/frontend.js (Node, keine Abhängigkeiten).
 *
 * Analoges Pendant zu dev-tools/test-suite.php: Stubbt nur die DOM-/Browser-
 * APIs, die frontend.js tatsächlich anfasst (kein echtes DOM, kein Browser,
 * kein npm-Paket), und führt die echte Datei per vm.runInContext() aus – kein
 * Reimplementieren der Logik im Test. Stand: v0.5.7, deckt den UTM-Form-Fill
 * (3 Kernfelder Source/Campaign/Medium inkl. Namens-Aliase) sowie die
 * Korrektur ab, dass test_event_code NICHT mehr im Browser-Pixel auftaucht.
 *
 * Ausführen:  node dev-tools\test-frontend-js.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

let pass = 0;
let fail = 0;

function check( label, cond, detail ) {
	if ( cond ) {
		pass++;
		console.log( 'PASS  ' + label );
	} else {
		fail++;
		console.log( 'FAIL  ' + label + ( undefined !== detail ? '  (' + detail + ')' : '' ) );
	}
}

const SRC = fs.readFileSync(
	path.join( __dirname, '..', 'src', 'assets', 'frontend.js' ),
	'utf8'
);

/* ---------------------------------------------------------------------
 * Minimaler DOM-Ersatz – nur, was frontend.js tatsächlich anfasst.
 * ------------------------------------------------------------------- */

function createField( opts ) {
	opts = opts || {};
	return {
		nodeName: ( opts.tag || 'input' ).toUpperCase(),
		name: opts.name || '',
		classList: opts.classes || [],
		value: opts.value || '',
		disabled: !! opts.disabled,
		children: opts.children || [],
		dispatched: [],
		dataset: {},
		matchesSelector( sel ) {
			const nameMatch = sel.match( /^\[name="([^"]*)"\]$/ );
			if ( nameMatch ) {
				return this.name === nameMatch[ 1 ];
			}
			if ( '.' === sel.charAt( 0 ) ) {
				return this.classList.indexOf( sel.slice( 1 ) ) > -1;
			}
			return false;
		},
		querySelector( sel ) {
			const parts = sel.split( ',' ).map( ( s ) => s.trim().toLowerCase() );
			for ( const child of this.children ) {
				if ( parts.indexOf( child.nodeName.toLowerCase() ) > -1 ) {
					return child;
				}
			}
			return null;
		},
		querySelectorAll() {
			return this.children;
		},
		matches() {
			return false; // Formular gilt nie als System-Formular (Suche/Kommentare/Login).
		},
		closest() {
			return null; // Kein AJAX-Formular-Plugin-Container.
		},
		addEventListener() {},
		dispatchEvent( evt ) {
			this.dispatched.push( evt.type );
			return true;
		},
	};
}

/**
 * Standard-Feldset für die UTM-Form-Fill-Szenarien (v0.5.7: 3 Kernfelder).
 * Source per name-Attribut, Medium per direkte CSS-Klasse, Campaign per
 * Wrapper-Klasse mit innerem <input> – deckt alle Erkennungswege gleichzeitig ab.
 */
function utmFields() {
	const source = createField( { name: 'utm_source' } );
	const medium = createField( { classes: [ 'utm-medium' ] } );
	const campaignInner = createField( {} );
	const campaignWrapper = createField( { tag: 'div', classes: [ 'pms-utm-campaign' ], children: [ campaignInner ] } );

	return {
		registry: [ source, medium, campaignWrapper ],
		source,
		medium,
		campaign: campaignInner,
	};
}

/** Feldset, das nur über die kurzen Namens-Aliase (source/campaign/medium) auffindbar ist. */
function utmAliasFields() {
	const source = createField( { name: 'source' } );
	const campaign = createField( { name: 'campaign' } );
	const medium = createField( { name: 'medium' } );

	return { registry: [ source, campaign, medium ], source, campaign, medium };
}

function createDocument() {
	const listeners = {};
	return {
		cookie: '',
		referrer: '',
		_listeners: listeners,
		_registry: [],
		querySelector( sel ) {
			for ( const el of this._registry ) {
				if ( el.matchesSelector( sel ) ) {
					return el;
				}
			}
			return null;
		},
		addEventListener( type, fn ) {
			listeners[ type ] = listeners[ type ] || [];
			listeners[ type ].push( fn );
		},
		dispatchEvent() {
			return true;
		},
	};
}

function FakeEvent( type, opts ) {
	this.type = type;
	this.bubbles = !! ( opts && opts.bubbles );
}

/**
 * frontend.js in einer isolierten VM-Sandbox laden und sofort ausführen
 * (die Datei ist eine selbstaufrufende IIFE).
 *
 * @param {Object} cfgOverrides window.pms_settings.
 * @param {Object} domOverrides { registry, cookie, referrer, path, search }.
 * @return {Object} { window, document } für Assertions.
 */
function run( cfgOverrides, domOverrides ) {
	domOverrides = domOverrides || {};

	const doc = createDocument();
	doc._registry = domOverrides.registry || [];
	doc.cookie = domOverrides.cookie || '';
	doc.referrer = domOverrides.referrer || '';

	const win = { fbqCalls: [], fetchCalls: [] };
	win.location = { pathname: domOverrides.path || '/', search: domOverrides.search || '' };
	win.pms_settings = Object.assign( { formTracking: false, utmFormFill: false }, cfgOverrides );

	// noFbq: simuliert eine deaktivierte Meta-Plattform (kein Pixel-Loader,
	// window.fbq daher gar nicht vorhanden) -- Grundlage für den
	// browser_fired-Test unten (v0.6.1 Event Log).
	if ( ! domOverrides.noFbq ) {
		win.fbq = function () {
			win.fbqCalls.push( Array.prototype.slice.call( arguments ) );
		};
	}

	// window.fetch-Stub für den AJAX-Body des Formular-Grabbers (v0.6.1: prüft
	// browser_fired). Antwortet immer mit einem einfachen Erfolg; fireLead()
	// selbst wertet die Response nur für das "pms:capi"-Event aus, das hier
	// nicht getestet wird.
	win.fetch = function ( url, opts ) {
		win.fetchCalls.push( { url: url, body: opts && opts.body ? String( opts.body ) : '' } );
		return Promise.resolve( {
			json: function () {
				return Promise.resolve( { success: true, data: { status: 'sent', code: 0, message: '', match_keys: [] } } );
			},
		} );
	};

	const sandbox = {
		window: win,
		document: doc,
		URLSearchParams,
		Event: FakeEvent,
		console,
		setTimeout: () => 0,
		clearTimeout: () => {},
	};

	vm.createContext( sandbox );
	vm.runInContext( SRC, sandbox, { filename: 'frontend.js' } );

	return { window: win, document: doc };
}

/* ---------------------------------------------------------------------
 * 1. Korrektur v0.5.7: KEIN test_event_code im Browser-Pixel
 * ------------------------------------------------------------------- */

console.log( '\n=== 1. Korrektur v0.5.7: test_event_code darf NICHT im fbq()-Aufruf auftauchen ===' );

{
	const r = run(
		{ formTracking: true, utmFormFill: false, eventType: 'Lead', urlFilter: [], excludeSystem: true, ajaxUrl: '', nonce: '' },
		{}
	);
	const submitHandlers = r.document._listeners.submit || [];
	check( 'submit-Listener wird registriert, wenn formTracking aktiv ist', submitHandlers.length > 0 );

	const fakeForm = createField( { tag: 'form' } );
	submitHandlers[ 0 ]( { target: fakeForm } );

	const call = r.window.fbqCalls[ 0 ] || [];
	check(
		'fbq()-Aufruf enthält kein test_event_code (Meta ignoriert das Event sonst im Test-Stream)',
		'track' === call[ 0 ] && 'Lead' === call[ 1 ] && call[ 2 ] && ! ( 'test_event_code' in call[ 2 ] ) && 0 === Object.keys( call[ 2 ] ).length,
		JSON.stringify( r.window.fbqCalls )
	);
}

/* ---------------------------------------------------------------------
 * 1b. v0.6.1 Event Log: browser_fired im AJAX-Body des Formular-Grabbers
 * ------------------------------------------------------------------- */

console.log( '\n=== 1b. Event Log: browser_fired im Formular-AJAX-Body ===' );

{
	const r = run(
		{ formTracking: true, utmFormFill: false, eventType: 'Lead', urlFilter: [], excludeSystem: true, ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php', nonce: 'test-nonce' },
		{}
	);
	const submitHandlers = r.document._listeners.submit || [];
	submitHandlers[ 0 ]( { target: createField( { tag: 'form' } ) } );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body enthält browser_fired=1, wenn window.fbq vorhanden ist (Meta-Pixel aktiv)', '1' === params.get( 'browser_fired' ), body );
}

{
	const r = run(
		{ formTracking: true, utmFormFill: false, eventType: 'Lead', urlFilter: [], excludeSystem: true, ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php', nonce: 'test-nonce' },
		{ noFbq: true }
	);
	const submitHandlers = r.document._listeners.submit || [];
	submitHandlers[ 0 ]( { target: createField( { tag: 'form' } ) } );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body enthält browser_fired=0, wenn window.fbq fehlt (z. B. Meta-Plattform deaktiviert)', '0' === params.get( 'browser_fired' ), body );
}

/* ---------------------------------------------------------------------
 * 2. UTM-Form-Fill: Werte-Ermittlung (Query -> Cookie -> Referrer)
 * ------------------------------------------------------------------- */

console.log( '\n=== 2. UTM-Form-Fill: Werte-Ermittlung für Source/Campaign/Medium ===' );

{
	const f = utmFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{ registry: f.registry, path: '/landing/', search: '?utm_source=newsletter&utm_medium=email&utm_campaign=launch' }
	);
	check( 'Source aus Query gefüllt (name="utm_source")', 'newsletter' === f.source.value );
	check( 'Medium aus Query gefüllt (CSS-Klasse .utm-medium)', 'email' === f.medium.value );
	check( 'Campaign aus Query gefüllt (Wrapper-Klasse .pms-utm-campaign -> inneres Feld)', 'launch' === f.campaign.value );
	check( 'input-Event wird beim Befüllen gefeuert', f.source.dispatched.indexOf( 'input' ) > -1 );
	check( 'change-Event wird beim Befüllen gefeuert', f.source.dispatched.indexOf( 'change' ) > -1 );
}

{
	const f = utmAliasFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{ registry: f.registry, path: '/landing/', search: '?utm_source=newsletter&utm_medium=email&utm_campaign=launch' }
	);
	check( 'Source-Alias name="source" wird ebenfalls erkannt', 'newsletter' === f.source.value );
	check( 'Campaign-Alias name="campaign" wird ebenfalls erkannt', 'launch' === f.campaign.value );
	check( 'Medium-Alias name="medium" wird ebenfalls erkannt', 'email' === f.medium.value );
}

{
	const f = utmFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{
			registry: f.registry,
			path: '/unterseite/',
			search: '',
			cookie: 'pms_attribution=' + encodeURIComponent( JSON.stringify( { utm_source: 'facebook', utm_campaign: 'from-cookie' } ) ),
		}
	);
	check( 'Source-Fallback auf Attribution-Cookie, wenn die URL keine Parameter mehr hat', 'facebook' === f.source.value );
	check( 'Campaign-Fallback auf Attribution-Cookie', 'from-cookie' === f.campaign.value );
}

{
	const f = utmFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{
			registry: f.registry,
			path: '/landing/',
			search: '?utm_source=query-wins',
			cookie: 'pms_attribution=' + encodeURIComponent( JSON.stringify( { utm_source: 'cookie-loses' } ) ),
		}
	);
	check( 'URL-Parameter haben Vorrang vor dem Cookie', 'query-wins' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: f.registry, path: '/x/', referrer: 'https://www.instagram.com/reel/xyz' } );
	check( 'Referrer instagram.com -> Source=facebook', 'facebook' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: f.registry, path: '/x/', referrer: 'https://www.google.de/search?q=x' } );
	check( 'Referrer google.* -> Source=google', 'google' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: f.registry, path: '/x/', referrer: '' } );
	check( 'kein Referrer, kein Klick-Parameter -> Source=direct', 'direct' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: f.registry, path: '/x/', search: '?fbclid=ABC', referrer: 'https://www.google.com/' } );
	check( 'fbclid ohne utm_source -> Source=facebook (Vorrang vor Referrer-Vermutung, kein eigenes fbclid-Feld mehr)', 'facebook' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: f.registry, path: '/x/', search: '?gclid=XYZ' } );
	check( 'gclid ohne utm_source/fbclid -> Source=google (kein eigenes gclid-Feld mehr)', 'google' === f.source.value );
}

{
	const f = utmFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{ registry: f.registry, path: '/unterseite/', search: '', cookie: 'pms_attribution=' + encodeURIComponent( JSON.stringify( { gclid: 'AUS-COOKIE' } ) ) }
	);
	check( 'gclid-Signal auch aus dem Cookie (Unterseiten-Navigation nach dem Klick) -> Source=google', 'google' === f.source.value );
}

{
	const f = utmFields();
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{ registry: f.registry, path: '/landing/', search: '?fbclid=ABC' }
	);
	check( 'Campaign bleibt leer, wenn kein Wert gefunden wird (kein Rate-Fallback für Campaign)', '' === f.campaign.value );
}

/* ---------------------------------------------------------------------
 * 3. UTM-Form-Fill: URL-Gating (all/include/exclude + Wildcard)
 * ------------------------------------------------------------------- */

console.log( '\n=== 3. UTM-Form-Fill: URL-Gating (spiegelt PMS_Pro_UTM::form_fill_url_allowed()) ===' );

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'include', utmFormFillUrls: [ '/kontakt' ] }, { registry: f.registry, path: '/blog/artikel/', search: '?utm_source=x' } );
	check( 'mode=include, URL passt nicht -> Felder bleiben leer', '' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'include', utmFormFillUrls: [ '/kontakt' ] }, { registry: f.registry, path: '/kontakt/', search: '?utm_source=x' } );
	check( 'mode=include, URL passt -> Felder werden gefüllt', 'x' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'exclude', utmFormFillUrls: [ '/blog' ] }, { registry: f.registry, path: '/blog/artikel/', search: '?utm_source=x' } );
	check( 'mode=exclude, URL ausgeschlossen -> Felder bleiben leer', '' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'exclude', utmFormFillUrls: [ '/blog' ] }, { registry: f.registry, path: '/kontakt/', search: '?utm_source=x' } );
	check( 'mode=exclude, URL nicht ausgeschlossen -> Felder werden gefüllt', 'x' === f.source.value );
}

{
	const f = utmFields();
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'include', utmFormFillUrls: [ '/lp/*' ] }, { registry: f.registry, path: '/lp/campaign-1/', search: '?utm_source=x' } );
	check( 'Wildcard-Muster "/lp/*" matcht Unterseiten', 'x' === f.source.value );
}

/* ---------------------------------------------------------------------
 * 4. UTM-Form-Fill: Randfälle
 * ------------------------------------------------------------------- */

console.log( '\n=== 4. UTM-Form-Fill: Randfälle ===' );

{
	const disabledField = createField( { name: 'utm_source', disabled: true } );
	run( { utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] }, { registry: [ disabledField ], path: '/x/', search: '?utm_source=should-not-apply' } );
	check( 'deaktiviertes Feld wird nicht befüllt', '' === disabledField.value );
}

{
	const f = utmFields();
	run( { utmFormFill: false, formTracking: false }, { registry: f.registry, path: '/x/', search: '?utm_source=irrelevant' } );
	check( 'beide Features aus: Skript beendet sich sofort, keine Felder werden befüllt', '' === f.source.value );
}

{
	// name-Attribut hat Vorrang vor CSS-Klasse, wenn zufällig beides existiert.
	const byName = createField( { name: 'utm_source', value: '' } );
	const byClass = createField( { classes: [ 'utm-source' ] } );
	run(
		{ utmFormFill: true, formTracking: false, utmFormFillMode: 'all', utmFormFillUrls: [] },
		{ registry: [ byName, byClass ], path: '/x/', search: '?utm_source=per-name' }
	);
	check( 'name-Attribut hat Vorrang vor CSS-Klasse', 'per-name' === byName.value && '' === byClass.value );
}

console.log( '\n==============================' );
console.log( 'Ergebnis: ' + pass + ' bestanden, ' + fail + ' fehlgeschlagen' );
process.exit( fail > 0 ? 1 : 0 );
