/**
 * Funktionaler Test-Harness für assets/pms-surecart.js (Node, keine
 * Abhängigkeiten). Analoges Pendant zu dev-tools/test-frontend-woocommerce-js.js:
 * stubbt nur die DOM-/Browser-APIs, die pms-surecart.js tatsächlich anfasst,
 * und führt die echte Datei per vm.runInContext() aus -- kein Reimplementieren
 * der Logik im Test.
 *
 * Besonderheit gegenüber dem WooCommerce-Pendant: pms-surecart.js beobachtet
 * fetch()-Traffic (siehe dortiger Datei-Kommentar), statt auf DOM-Events zu
 * lauschen -- der Mock-fetch() hier liefert deshalb ein Response-artiges
 * Objekt mit .clone() (Pflicht, siehe installFetchObserver()) UND simuliert
 * gleichzeitig "SureCarts eigenen Code, der fetch() aufruft" durch einen
 * direkten Testaufruf von window.fetch(...) NACH dem Initialisieren (dann
 * bereits die gewrappte Version).
 *
 * Ausführen:  node dev-tools\test-frontend-surecart-js.js
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
	path.join( __dirname, '..', 'src', 'assets', 'pms-surecart.js' ),
	'utf8'
);

/* ---------------------------------------------------------------------
 * Minimaler DOM-Ersatz -- nur, was pms-surecart.js tatsächlich anfasst.
 * ------------------------------------------------------------------- */

function createDocument( domOverrides ) {
	const byId = {};
	( domOverrides.elements || [] ).forEach( ( node ) => {
		if ( node.id ) {
			byId[ node.id ] = node;
		}
	} );

	const listeners = {};

	return {
		readyState: 'complete',
		getElementById( id ) {
			return byId[ id ] || null;
		},
		querySelector( sel ) {
			if ( domOverrides.querySelectorResults && Object.prototype.hasOwnProperty.call( domOverrides.querySelectorResults, sel ) ) {
				return domOverrides.querySelectorResults[ sel ];
			}
			return null;
		},
		addEventListener( type, fn ) {
			listeners[ type ] = listeners[ type ] || [];
			listeners[ type ].push( fn );
		},
		dispatchEvent( evt ) {
			( listeners[ evt.type ] || [] ).forEach( ( fn ) => fn( evt ) );
			return true;
		},
	};
}

/**
 * Response-artiges Mock-Objekt: .json() UND .clone() (installFetchObserver()
 * ruft response.clone().json() auf, damit die echte Antwort für den
 * eigentlichen Aufrufer unangetastet bleibt -- clone() liefert hier einfach
 * dasselbe Objekt zurück, da das Mock ohnehin keinen einmal konsumierbaren
 * Body-Stream simuliert).
 */
function makeResponse( data ) {
	const resp = {
		json() {
			return Promise.resolve( data );
		},
	};
	resp.clone = function () {
		return resp;
	};
	return resp;
}

/**
 * pms-surecart.js in einer isolierten VM-Sandbox laden und sofort ausführen.
 *
 * @param {Object} cfgOverrides window.pms_surecart_settings.
 * @param {Object} domOverrides { elements, querySelectorResults, hasConsent,
 *                                autoTimeout, fetchResponses (url -> data),
 *                                defaultFetchResponse, noFbq, noGtag, noTtq }.
 * @return {Object} { window, document } für Assertions/weitere fetch()-Aufrufe.
 */
function run( cfgOverrides, domOverrides ) {
	domOverrides = domOverrides || {};

	const doc = createDocument( domOverrides );
	const winListeners = {};
	const win = {
		fbqCalls: [],
		gtagCalls: [],
		ttqCalls: [],
		fetchCalls: [],
		addEventListener( type, fn ) {
			winListeners[ type ] = winListeners[ type ] || [];
			winListeners[ type ].push( fn );
		},
		dispatchEvent( evt ) {
			( winListeners[ evt.type ] || [] ).forEach( ( fn ) => fn( evt ) );
			return true;
		},
	};
	win.location = { href: domOverrides.href || 'https://example.com/checkout/' };
	win.pms_surecart_settings = Object.assign(
		{
			ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			consentEvents: [],
			googleEnabled: false,
			tiktokEnabled: false,
			googleTagId: '',
			ga4MeasurementId: '',
			scGoogleConversionLabel: '',
			scGoogleAdvancedMatching: false,
			purchaseNonce: 'test-purchase-nonce',
			currencyMinorUnitDivisor: 100,
		},
		cfgOverrides
	);

	if ( ! domOverrides.noFbq ) {
		win.fbq = function () {
			win.fbqCalls.push( Array.prototype.slice.call( arguments ) );
		};
	}
	if ( ! domOverrides.noGtag ) {
		win.gtag = function () {
			win.gtagCalls.push( Array.prototype.slice.call( arguments ) );
		};
	}
	if ( ! domOverrides.noTtq ) {
		// Dasselbe SDK-Snippet-Muster wie bei WooCommerce (ttq als aufrufbare
		// Funktion MIT angehängter .track-Methode) -- siehe dortiger Kommentar.
		win.ttq = function () {};
		win.ttq.track = function () {
			win.ttqCalls.push( Array.prototype.slice.call( arguments ) );
		};
	}

	if ( undefined !== domOverrides.hasConsent ) {
		win.pmsHasConsent = function () {
			return domOverrides.hasConsent;
		};
	}

	win.fetch = function ( url, opts ) {
		const method = ( opts && opts.method ) || 'GET';
		win.fetchCalls.push( { url: url, method: method, body: opts && opts.body ? String( opts.body ) : '' } );

		let data = domOverrides.defaultFetchResponse || { success: true, data: {} };
		if ( domOverrides.fetchResponses && Object.prototype.hasOwnProperty.call( domOverrides.fetchResponses, url ) ) {
			data = domOverrides.fetchResponses[ url ];
		}
		return Promise.resolve( makeResponse( data ) );
	};

	const sandbox = {
		window: win,
		document: doc,
		URLSearchParams,
		CustomEvent: function ( type, opts ) {
			return { type: type, detail: opts && opts.detail };
		},
		console,
		setInterval: domOverrides.autoInterval
			? function ( fn ) {
				fn();
				return 0;
			}
			: function () {
				return 0;
			},
		clearInterval: () => {},
		setTimeout: domOverrides.autoTimeout ? ( fn ) => { fn(); return 0; } : () => 0,
		clearTimeout: () => {},
	};

	vm.createContext( sandbox );
	vm.runInContext( SRC, sandbox, { filename: 'pms-surecart.js' } );

	return { window: win, document: doc };
}

/** Ein paar Node-Ticks abwarten, damit verkettete Promises auflösen. */
function flushMicrotasks() {
	return new Promise( ( resolve ) => setImmediate( resolve ) ).then(
		() => new Promise( ( resolve ) => setImmediate( resolve ) )
	).then(
		() => new Promise( ( resolve ) => setImmediate( resolve ) )
	);
}

const CHECKOUT_PAGE_SELECTOR = '.wp-block-surecart-line-items, .wp-block-surecart-submit, .wp-block-surecart-totals, .wp-block-surecart-coupon';

// CommonJS-Datei (kein top-level await) -- mehrere Szenarien warten auf
// verkettete Promises (fetch().then().then()), daher laufen alle Tests
// innerhalb dieser async-Funktion (dasselbe Muster wie
// test-frontend-woocommerce-js.js).
async function main() {

/* ---------------------------------------------------------------------
 * 1. ViewContent
 * ------------------------------------------------------------------- */

console.log( '\n=== 1. ViewContent: liest #pms-surecart-view-content-data und feuert Pixel + AJAX ===' );

{
	const dataEl = {
		id: 'pms-surecart-view-content-data',
		nodeName: 'SCRIPT',
		textContent: JSON.stringify( {
			product_id: 'prod_501',
			content_id: 'prod_501',
			content_name: 'Sneaker',
			content_category: 'Shoes',
			value: 89.99,
			currency: 'EUR',
			quantity: 1,
		} ),
	};

	const { window: win } = run(
		{ googleEnabled: true, tiktokEnabled: true },
		{ elements: [ dataEl ] }
	);

	await flushMicrotasks();

	check( '1.1 fbq(track, ViewContent) feuert mit den richtigen Werten', win.fbqCalls.length === 1 && 'ViewContent' === win.fbqCalls[ 0 ][ 1 ] && 89.99 === win.fbqCalls[ 0 ][ 2 ].value );
	check( '1.2 gtag(event, view_item) feuert', win.gtagCalls.length === 1 && 'view_item' === win.gtagCalls[ 0 ][ 1 ] );
	check( '1.3 ttq.track(ViewContent) feuert', win.ttqCalls.length === 1 && 'ViewContent' === win.ttqCalls[ 0 ][ 0 ] );
	check( '1.4 AJAX-Request an pms_surecart_track mit product_id', win.fetchCalls.length === 1 && win.fetchCalls[ 0 ].body.indexOf( 'action=pms_surecart_track' ) !== -1 && win.fetchCalls[ 0 ].body.indexOf( 'product_id=prod_501' ) !== -1 );
}

{
	// Kein Element -> kein Tracking, kein Fehler.
	const { window: win } = run( {}, {} );
	await flushMicrotasks();
	check( '1.5 Ohne #pms-surecart-view-content-data: kein fbq-Aufruf', 0 === win.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 2. Consent-Queue
 * ------------------------------------------------------------------- */

console.log( '\n=== 2. Consent-Queue: Events warten auf pmsHasConsent() === ' );

{
	const dataEl = {
		id: 'pms-surecart-view-content-data',
		textContent: JSON.stringify( { product_id: 'prod_1', content_id: 'prod_1', value: 10, currency: 'EUR', quantity: 1 } ),
	};
	const { window: win } = run( {}, { elements: [ dataEl ], hasConsent: false } );
	await flushMicrotasks();
	check( '2.1 Ohne Consent: fbq feuert NICHT sofort', 0 === win.fbqCalls.length );
}

{
	const dataEl = {
		id: 'pms-surecart-view-content-data',
		textContent: JSON.stringify( { product_id: 'prod_1', content_id: 'prod_1', value: 10, currency: 'EUR', quantity: 1 } ),
	};
	const { window: win, document: doc } = run(
		{ consentEvents: [ 'pms_consent_granted' ] },
		{ elements: [ dataEl ], hasConsent: false, autoTimeout: true }
	);
	await flushMicrotasks();
	check( '2.2 Vor dem Consent-Event: noch nichts gefeuert', 0 === win.fbqCalls.length );

	win.pmsHasConsent = function () {
		return true;
	};
	doc.dispatchEvent( { type: 'pms_consent_granted' } );
	await flushMicrotasks();
	check( '2.3 Nach dem Consent-Event (autoTimeout flusht sofort): fbq feuert', 1 === win.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 3. fetch()-Beobachtung: Grundverhalten
 * ------------------------------------------------------------------- */

console.log( '\n=== 3. fetch()-Beobachtung: Grundverhalten (Nicht-SureCart-URLs, echte Response bleibt lesbar) ===' );

{
	const { window: win } = run( {}, {} );
	await flushMicrotasks();

	// Eine Nicht-SureCart-URL (z. B. ein fremdes Analytics-Tool) darf nie
	// beobachtet werden UND die echte Antwort muss weiterhin normal lesbar sein.
	const result = await win.fetch( 'https://example.com/some-other-api/', { method: 'POST' } ).then( ( r ) => r.json() );
	check( '3.1 Nicht-SureCart-URL: fetch() liefert die echte Antwort unverändert', true === result.success );
	check( '3.2 Nicht-SureCart-URL: löst kein AddToCart/Purchase aus', 0 === win.fbqCalls.length );
}

{
	// SureCart-URL, deren Antwort keiner der drei bekannten Formen entspricht
	// (z. B. eine Customer- oder Coupon-Antwort) -- darf nicht crashen und
	// löst nichts aus.
	const { window: win } = run(
		{},
		{ fetchResponses: { 'https://example.com/wp-json/surecart/v1/coupons/apply': { id: 'coupon_1', valid: true } } }
	);
	const result = await win.fetch( 'https://example.com/wp-json/surecart/v1/coupons/apply', { method: 'POST' } ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '3.3 SureCart-URL mit unbekannter Form: echte Antwort bleibt lesbar', true === result.valid );
	check( '3.4 SureCart-URL mit unbekannter Form: löst nichts aus', 0 === win.fbqCalls.length && 0 === win.ttqCalls.length );
}

/* ---------------------------------------------------------------------
 * 4. AddToCart -- einzelne LineItem-Antwort auf einen POST-Request
 * ------------------------------------------------------------------- */

console.log( '\n=== 4. AddToCart: beobachtete einzelne LineItem-POST-Antwort ===' );

{
	const lineItemUrl = 'https://example.com/wp-json/surecart/v1/line_items';
	const { window: win } = run(
		{ googleEnabled: true, tiktokEnabled: true },
		{
			fetchResponses: {
				[ lineItemUrl ]: { id: 'li_1', quantity: 2, price: 'price_501', total_amount: 4000, checkout: 'chk_1' },
			},
		}
	);

	// Simuliert SureCarts eigenen Code, der nach einem Klick auf "In den
	// Warenkorb" selbst fetch() aufruft (jetzt bereits die gewrappte Version).
	await win.fetch( lineItemUrl, { method: 'POST' } ).then( ( r ) => r.json() );
	await flushMicrotasks();

	check( '4.1 fbq(track, AddToCart) feuert mit der Price-ID als content_id', win.fbqCalls.length === 1 && 'AddToCart' === win.fbqCalls[ 0 ][ 1 ] && [ 'price_501' ] + '' === win.fbqCalls[ 0 ][ 2 ].content_ids + '' );
	check( '4.2 ttq.track(AddToCart) feuert', win.ttqCalls.some( ( c ) => 'AddToCart' === c[ 0 ] ) );
	check( '4.3 gtag(event, add_to_cart) feuert mit quantity=2', win.gtagCalls.some( ( c ) => 'add_to_cart' === c[ 1 ] && 2 === c[ 2 ].items[ 0 ].quantity ) );
	check( '4.4 AJAX an pms_surecart_track mit price_id (kein product_id bekannt)', win.fetchCalls.some( ( c ) => c.body.indexOf( 'action=pms_surecart_track' ) !== -1 && c.body.indexOf( 'price_id=price_501' ) !== -1 ) );
}

{
	// GET-Request mit derselben LineItem-Form (z. B. beim Laden der Checkout-
	// Seite) darf KEIN AddToCart auslösen -- nur eine tatsächliche POST-Antwort
	// bedeutet "gerade hinzugefügt".
	const lineItemUrl = 'https://example.com/wp-json/surecart/v1/line_items/li_1';
	const { window: win } = run(
		{},
		{ fetchResponses: { [ lineItemUrl ]: { id: 'li_1', quantity: 1, price: 'price_501', total_amount: 2000, checkout: 'chk_1' } } }
	);
	await win.fetch( lineItemUrl, { method: 'GET' } ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '4.5 GET-Request mit LineItem-Form löst KEIN AddToCart aus', 0 === win.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 5. InitiateCheckout -- Checkout-Snapshot (unbezahlt) + Line-Items,
 * nur auf einer erkannten Checkout-Seite.
 * ------------------------------------------------------------------- */

console.log( '\n=== 5. InitiateCheckout: Checkout-Snapshot (status=draft) + Line-Items, DOM-Erkennung ===' );

{
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_ic_1';
	const lineItemsUrl = 'https://example.com/wp-json/surecart/v1/line_items?checkout=chk_ic_1';

	const { window: win } = run(
		{ googleEnabled: true, tiktokEnabled: true },
		{
			querySelectorResults: { [ CHECKOUT_PAGE_SELECTOR ]: { tag: 'div' } },
			fetchResponses: {
				[ checkoutUrl ]: { id: 'chk_ic_1', status: 'draft', total_amount: 5500, currency: 'eur' },
				[ lineItemsUrl ]: {
					data: [
						{ id: 'li_1', quantity: 2, price: 'price_501', total_amount: 4000, checkout: 'chk_ic_1' },
						{ id: 'li_2', quantity: 1, price: 'price_777', total_amount: 1500, checkout: 'chk_ic_1' },
					],
				},
			},
		}
	);

	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await win.fetch( lineItemsUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();

	check( '5.1 InitiateCheckout feuert genau einmal (Meta)', win.fbqCalls.filter( ( c ) => 'InitiateCheckout' === c[ 1 ] ).length === 1 );
	const meta = win.fbqCalls.find( ( c ) => 'InitiateCheckout' === c[ 1 ] )[ 2 ];
	check( '5.2 value/currency korrekt aus dem Checkout-Snapshot (Minor Units / 100)', 55 === meta.value && 'EUR' === meta.currency );
	check( '5.3 contents enthält beide Positionen mit historischem item_price (total_amount/quantity)', 2 === meta.contents.length && 20 === meta.contents[ 0 ].item_price && 15 === meta.contents[ 1 ].item_price );
	check( '5.4 gtag(event, begin_checkout) feuert mit denselben Items', win.gtagCalls.some( ( c ) => 'begin_checkout' === c[ 1 ] && 2 === c[ 2 ].items.length ) );
	check( '5.5 AJAX an pms_surecart_track mit checkout_id (Server löst den Rest serverseitig auf)', win.fetchCalls.some( ( c ) => c.body.indexOf( 'action=pms_surecart_track' ) !== -1 && c.body.indexOf( 'checkout_id=chk_ic_1' ) !== -1 ) );

	// Erneutes Beobachten desselben Checkouts (z. B. Mengenänderung) darf
	// InitiateCheckout nicht ein zweites Mal auslösen.
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '5.6 Ein zweiter beobachteter Snapshot desselben (weiterhin unbezahlten) Checkouts feuert NICHT erneut', win.fbqCalls.filter( ( c ) => 'InitiateCheckout' === c[ 1 ] ).length === 1 );
}

{
	// Derselbe Checkout-Snapshot, aber KEINE Checkout-Seite erkannt (keine der
	// vier Block-Klassen im DOM) -- kein InitiateCheckout.
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_ic_2';
	const { window: win } = run(
		{},
		{ fetchResponses: { [ checkoutUrl ]: { id: 'chk_ic_2', status: 'draft', total_amount: 1000, currency: 'eur' } } }
	);
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '5.7 Ohne erkannte Checkout-Seite (keine Block-Klasse im DOM): kein InitiateCheckout', 0 === win.fbqCalls.filter( ( c ) => 'InitiateCheckout' === c[ 1 ] ).length );
}

/* ---------------------------------------------------------------------
 * 6. Purchase -- Checkout-Snapshot mit "bezahlt"-Status
 * ------------------------------------------------------------------- */

console.log( '\n=== 6. Purchase: Checkout-Snapshot mit status=paid, deterministische event_id, kein AJAX-Roundtrip ===' );

{
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_1';
	const lineItemsUrl = 'https://example.com/wp-json/surecart/v1/line_items?checkout=chk_paid_1';

	const { window: win } = run(
		{ googleEnabled: true, tiktokEnabled: true, googleTagId: 'AW-123456789', scGoogleConversionLabel: 'AbCdEfGh' },
		{
			fetchResponses: {
				[ checkoutUrl ]: { id: 'chk_paid_1', status: 'paid', total_amount: 5500, currency: 'eur' },
				[ lineItemsUrl ]: { data: [ { id: 'li_1', quantity: 1, price: 'price_501', total_amount: 5500, checkout: 'chk_paid_1' } ] },
			},
		}
	);
	win.pmsInitialized = true; // Sonst würde firePurchase() unbegrenzt auf window.pmsInitialized pollen.

	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await win.fetch( lineItemsUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();

	check( '6.1 fbq(track, Purchase) feuert mit der deterministischen event_id "pms_sc_order_chk_paid_1"', win.fbqCalls.some( ( c ) => 'Purchase' === c[ 1 ] && 'pms_sc_order_chk_paid_1' === c[ 3 ].eventID ) );
	check( '6.2 ttq.track(CompletePayment) feuert mit derselben event_id', win.ttqCalls.some( ( c ) => 'CompletePayment' === c[ 0 ] && 'pms_sc_order_chk_paid_1' === c[ 2 ].event_id ) );
	check( '6.3 gtag(event, conversion) feuert mit send_to = TagID/Label und transaction_id', win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] && 'AW-123456789/AbCdEfGh' === c[ 2 ].send_to && 'pms_sc_order_chk_paid_1' === c[ 2 ].transaction_id ) );
	check( '6.4 KEIN AJAX-Roundtrip an pms_surecart_track für Purchase (läuft serverseitig über die WP-Hooks)', ! win.fetchCalls.some( ( c ) => c.body.indexOf( 'action=pms_surecart_track' ) !== -1 ) );

	// Ein zweiter beobachteter "paid"-Snapshot desselben Checkouts (z. B. eine
	// erneute Status-Abfrage) darf Purchase nicht ein zweites Mal auslösen.
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '6.5 Dedup: ein zweiter beobachteter "paid"-Snapshot feuert NICHT erneut', win.fbqCalls.filter( ( c ) => 'Purchase' === c[ 1 ] ).length === 1 );
}

{
	// Ohne konfiguriertes Google-Ads-Conversion-Label (Purchase) -> kein
	// gtag('event','conversion')-Aufruf, dieselbe Regel wie serverseitig.
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_2';
	const { window: win } = run(
		{ googleEnabled: true, googleTagId: 'AW-123456789', scGoogleConversionLabel: '' },
		{ fetchResponses: { [ checkoutUrl ]: { id: 'chk_paid_2', status: 'paid', total_amount: 1000, currency: 'eur' } } }
	);
	win.pmsInitialized = true;
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '6.6 Kein Conversion-Label konfiguriert -> kein gtag(conversion)-Aufruf', ! win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] ) );
	check( '6.7 Meta/TikTok feuern trotzdem unabhängig vom fehlenden Google-Label', win.fbqCalls.some( ( c ) => 'Purchase' === c[ 1 ] ) );
}

{
	// GA4-Purchase (seit v0.6.8): eigenständig von der Google-Ads-Conversion
	// oben -- feuert OHNE send_to und deshalb auch OHNE konfiguriertes
	// Google-Ads-Conversion-Label/Tag-ID, sobald nur ga4MeasurementId gesetzt
	// ist (dieselbe Unterscheidung wie serverseitig in
	// PMS_Pro_Woo_Purchase::ga4_purchase_js()). Line-Items werden hier bewusst
	// VOR dem "paid"-Checkout-Snapshot beobachtet: Purchase feuert -- anders
	// als InitiateCheckout -- ohne Gnadenfrist sofort beim Snapshot (siehe
	// CLAUDE.md "SureCart-Tracking (Pro)"), contents/items sind also nur dann
	// befüllt, wenn die Line-Items zu diesem Zeitpunkt schon bekannt waren.
	const checkoutUrl   = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_ga4';
	const lineItemsUrl  = 'https://example.com/wp-json/surecart/v1/line_items?checkout=chk_paid_ga4';
	const { window: win } = run(
		{ googleEnabled: true, ga4MeasurementId: 'G-ABC123XYZ', googleTagId: '', scGoogleConversionLabel: '' },
		{
			fetchResponses: {
				[ checkoutUrl ]: { id: 'chk_paid_ga4', status: 'paid', total_amount: 2500, currency: 'eur' },
				[ lineItemsUrl ]: { data: [ { id: 'li_9', quantity: 2, price: 'price_9', total_amount: 2500, checkout: 'chk_paid_ga4' } ] },
			},
		}
	);
	win.pmsInitialized = true;

	await win.fetch( lineItemsUrl ).then( ( r ) => r.json() );
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();

	check(
		'6.8 GA4: gtag(event, purchase) feuert ohne send_to, mit transaction_id/value/currency/items',
		win.gtagCalls.some( ( c ) => 'purchase' === c[ 1 ]
			&& 'pms_sc_order_chk_paid_ga4' === c[ 2 ].transaction_id
			&& 25 === c[ 2 ].value
			&& 'EUR' === c[ 2 ].currency
			&& undefined === c[ 2 ].send_to
			&& Array.isArray( c[ 2 ].items ) && 1 === c[ 2 ].items.length )
	);
	check( '6.9 GA4: kein Google-Ads-Conversion-Aufruf ohne konfiguriertes Tag-ID/Label', ! win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] ) );
}

{
	// Keine ga4MeasurementId konfiguriert -> kein gtag(event, purchase),
	// Google-Ads-Conversion feuert unabhängig davon weiterhin.
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_noga4';
	const { window: win } = run(
		{ googleEnabled: true, ga4MeasurementId: '', googleTagId: 'AW-1', scGoogleConversionLabel: 'LBL' },
		{ fetchResponses: { [ checkoutUrl ]: { id: 'chk_paid_noga4', status: 'paid', total_amount: 1000, currency: 'eur' } } }
	);
	win.pmsInitialized = true;
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '6.10 Keine ga4MeasurementId konfiguriert -> kein gtag(event, purchase)', ! win.gtagCalls.some( ( c ) => 'purchase' === c[ 1 ] ) );
	check( '6.11 Google-Ads-Conversion feuert unabhängig von GA4 weiterhin', win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] ) );
}

/* ---------------------------------------------------------------------
 * 7. Google Enhanced Conversions: gehashte user_data werden vor dem
 * Conversion-Aufruf nachgeladen, wenn scGoogleAdvancedMatching aktiv ist.
 * ------------------------------------------------------------------- */

console.log( '\n=== 7. Google Enhanced Conversions: purchaseNonce-AJAX-Anreicherung ===' );

{
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_am';
	const ajaxUrl = 'https://example.com/wp-admin/admin-ajax.php';

	const { window: win } = run(
		{
			googleEnabled: true,
			googleTagId: 'AW-1',
			scGoogleConversionLabel: 'LBL',
			scGoogleAdvancedMatching: true,
			purchaseNonce: 'matching-nonce',
		},
		{
			fetchResponses: {
				[ checkoutUrl ]: { id: 'chk_paid_am', status: 'paid', total_amount: 1000, currency: 'eur' },
				[ ajaxUrl ]: { success: true, data: { user_data: { email: 'HASHED_EMAIL' } } },
			},
		}
	);
	win.pmsInitialized = true;

	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();

	check( '7.1 Zusätzlicher AJAX-Request an pms_surecart_purchase_matching mit checkout_id', win.fetchCalls.some( ( c ) => c.body.indexOf( 'action=pms_surecart_purchase_matching' ) !== -1 && c.body.indexOf( 'checkout_id=chk_paid_am' ) !== -1 && c.body.indexOf( 'nonce=matching-nonce' ) !== -1 ) );
	check( '7.2 gtag(event, conversion) enthält die nachgeladenen gehashten user_data', win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] && c[ 2 ].user_data && 'HASHED_EMAIL' === c[ 2 ].user_data.email ) );
}

{
	// scGoogleAdvancedMatching aus -> kein Zusatz-Request, Conversion feuert
	// trotzdem (ohne user_data).
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_paid_noam';
	const { window: win } = run(
		{ googleEnabled: true, googleTagId: 'AW-1', scGoogleConversionLabel: 'LBL', scGoogleAdvancedMatching: false },
		{ fetchResponses: { [ checkoutUrl ]: { id: 'chk_paid_noam', status: 'paid', total_amount: 1000, currency: 'eur' } } }
	);
	win.pmsInitialized = true;
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	check( '7.3 scGoogleAdvancedMatching aus: kein Zusatz-Request an pms_surecart_purchase_matching', ! win.fetchCalls.some( ( c ) => c.body.indexOf( 'action=pms_surecart_purchase_matching' ) !== -1 ) );
	check( '7.4 Conversion feuert trotzdem, ohne user_data', win.gtagCalls.some( ( c ) => 'conversion' === c[ 1 ] && undefined === c[ 2 ].user_data ) );
}

/* ---------------------------------------------------------------------
 * 8. Minor-Unit-Divisor wird respektiert (currencyMinorUnitDivisor)
 * ------------------------------------------------------------------- */

console.log( '\n=== 8. currencyMinorUnitDivisor wird für die Preisumrechnung genutzt ===' );

{
	// Zero-Decimal-Währung (z. B. JPY): Divisor 1 statt 100.
	const checkoutUrl = 'https://example.com/wp-json/surecart/v1/checkouts/chk_jpy';
	// Nur der Checkout-Snapshot wird beobachtet (keine Line-Items) -- löst
	// evaluate()'s Gnadenfrist aus (siehe dortige Doku); autoTimeout lässt sie
	// hier sofort ablaufen, statt den Test künstlich zu verzögern.
	const { window: win } = run(
		{ currencyMinorUnitDivisor: 1 },
		{
			querySelectorResults: { [ CHECKOUT_PAGE_SELECTOR ]: { tag: 'div' } },
			fetchResponses: { [ checkoutUrl ]: { id: 'chk_jpy', status: 'draft', total_amount: 5500, currency: 'jpy' } },
			autoTimeout: true,
		}
	);
	await win.fetch( checkoutUrl ).then( ( r ) => r.json() );
	await flushMicrotasks();
	const meta = win.fbqCalls.find( ( c ) => 'InitiateCheckout' === c[ 1 ] );
	check( '8.1 currencyMinorUnitDivisor=1: value bleibt 5500 (kein /100)', !! meta && 5500 === meta[ 2 ].value );
}

console.log( '\n==============================' );
console.log( 'Ergebnis: ' + pass + ' bestanden, ' + fail + ' fehlgeschlagen' );
process.exit( fail > 0 ? 1 : 0 );

}

main();
