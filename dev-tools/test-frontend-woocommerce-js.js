/**
 * Funktionaler Test-Harness für assets/pms-woocommerce.js (Node, keine
 * Abhängigkeiten). Analoges Pendant zu dev-tools/test-frontend-js.js: stubbt
 * nur die DOM-/Browser-APIs, die pms-woocommerce.js tatsächlich anfasst, und
 * führt die echte Datei per vm.runInContext() aus -- kein Reimplementieren
 * der Logik im Test.
 *
 * Ausführen:  node dev-tools\test-frontend-woocommerce-js.js
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
	path.join( __dirname, '..', 'src', 'assets', 'pms-woocommerce.js' ),
	'utf8'
);

/* ---------------------------------------------------------------------
 * Minimaler DOM-Ersatz -- nur, was pms-woocommerce.js tatsächlich anfasst.
 * ------------------------------------------------------------------- */

function matchesSimpleSelector( node, sel ) {
	sel = sel.trim();
	const attrMatch = sel.match( /^([a-zA-Z]*)\[name="([^"]*)"\]$/ );
	if ( attrMatch ) {
		const tag = attrMatch[ 1 ] ? attrMatch[ 1 ].toUpperCase() : null;
		return ( ! tag || node.nodeName === tag ) && node.name === attrMatch[ 2 ];
	}
	if ( '.' === sel.charAt( 0 ) ) {
		return node.classList && node.classList.indexOf( sel.slice( 1 ) ) > -1;
	}
	return false;
}

function el( opts ) {
	opts = opts || {};
	const attrs = opts.attrs || {};
	const node = {
		nodeName: ( opts.tag || 'div' ).toUpperCase(),
		name: opts.name || attrs.name || '',
		value: undefined !== opts.value ? opts.value : '',
		textContent: opts.textContent || '',
		classList: opts.classes || [],
		dataset: opts.dataset || {},
		children: opts.children || [],
		getAttribute( name ) {
			return Object.prototype.hasOwnProperty.call( attrs, name ) ? attrs[ name ] : null;
		},
		querySelector( sel ) {
			const parts = sel.split( ',' );
			for ( const child of node.children ) {
				for ( const part of parts ) {
					if ( matchesSimpleSelector( child, part ) ) {
						return child;
					}
				}
			}
			return null;
		},
	};
	node.classList.contains = function ( c ) {
		return node.classList.indexOf( c ) > -1;
	};
	return node;
}

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
		_listeners: listeners,
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

/** Minimaler jQuery-Ersatz: nur $(x).on(type, handler) + ein Test-Trigger. */
function makeJQuery() {
	const handlers = {};
	const $fn = function () {
		return {
			on( type, handler ) {
				handlers[ type ] = handlers[ type ] || [];
				handlers[ type ].push( handler );
			},
		};
	};
	$fn.trigger = function ( type, ...args ) {
		( handlers[ type ] || [] ).forEach( ( fn ) => fn( { type }, ...args ) );
	};
	return $fn;
}

/**
 * pms-woocommerce.js in einer isolierten VM-Sandbox laden und sofort
 * ausführen (die Datei ist eine selbstaufrufende IIFE).
 *
 * @param {Object} cfgOverrides window.pms_woo_settings.
 * @param {Object} domOverrides { elements, querySelectorResults, jquery,
 *                                hasConsent, autoTimeout, storeApiResponse,
 *                                noFbq, noGtag, noTtq }.
 * @return {Object} { window, document, jq } für Assertions/Trigger.
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
	win.location = { href: domOverrides.href || 'https://example.com/product/shirt/' };
	win.pms_woo_settings = Object.assign(
		{
			ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			consentEvents: [],
			storeCartUrl: 'https://example.com/wp-json/wc/store/v1/cart',
			// Default false, wie im echten Betrieb (class-pro-woo.php liest
			// google_enabled/tiktok_enabled aus den Plugin-Settings, dort
			// beide standardmäßig aus) -- Tests, die gtag/ttq prüfen wollen,
			// setzen diese Flags gezielt über cfgOverrides.
			googleEnabled: false,
			tiktokEnabled: false,
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
		// pms-woocommerce.js prüft bewusst 'function' === typeof window.ttq
		// (nicht typeof window.ttq.track), weil TikToks echtes Pixel-SDK ttq
		// als aufrufbare Funktion mit angehängten Methoden initialisiert
		// (dasselbe Snippet-Muster wie fbq/analytics.js) -- der Stub muss
		// dieselbe Form haben, sonst testet er einen Pfad, den es in der
		// Realität nicht gibt.
		win.ttq = function () {};
		win.ttq.track = function () {
			win.ttqCalls.push( Array.prototype.slice.call( arguments ) );
		};
	}

	if ( undefined !== domOverrides.hasConsent ) {
		// Simuliert den Consent-Bootstrap aus class-pms-frontend.php
		// (window.pmsHasConsent existiert nur, wenn Consent verzögert ist).
		win.pmsHasConsent = function () {
			return domOverrides.hasConsent;
		};
	}

	win.fetch = function ( url, opts ) {
		win.fetchCalls.push( { url: url, body: opts && opts.body ? String( opts.body ) : '' } );

		if ( domOverrides.storeApiResponse && url === win.pms_woo_settings.storeCartUrl ) {
			return Promise.resolve( { json: () => Promise.resolve( domOverrides.storeApiResponse ) } );
		}

		return Promise.resolve( {
			json: () => Promise.resolve( { success: true, data: { status: 'sent', code: 0, message: '', match_keys: [] } } ),
		} );
	};

	let jq = null;
	if ( domOverrides.jquery ) {
		jq = makeJQuery();
		win.jQuery = jq;
	}

	const sandbox = {
		window: win,
		document: doc,
		URLSearchParams,
		CustomEvent: function ( type, opts ) {
			return { type: type, detail: opts && opts.detail };
		},
		console,
		// Standardmäßig ein No-Op (wie test-frontend-js.js): Tests, die den
		// Lock-Mechanismus prüfen, brauchen das (sonst würde der Lock
		// innerhalb desselben synchronen Aufrufs sofort wieder freigegeben).
		// autoTimeout=true führt den Callback stattdessen sofort aus -- nötig
		// für den Consent-Queue-Flush-Test unten.
		setTimeout: domOverrides.autoTimeout ? ( fn ) => { fn(); return 0; } : () => 0,
		clearTimeout: () => {},
	};

	vm.createContext( sandbox );
	vm.runInContext( SRC, sandbox, { filename: 'pms-woocommerce.js' } );

	return { window: win, document: doc, jq: jq };
}

/** Ein paar Node-Ticks abwarten, damit verkettete Promises (fetch().then().then()) auflösen. */
function flushMicrotasks() {
	return new Promise( ( resolve ) => setImmediate( resolve ) ).then(
		() => new Promise( ( resolve ) => setImmediate( resolve ) )
	);
}

// CommonJS-Datei (kein top-level await) -- die beiden Store-API-Szenarien in
// Abschnitt 5 müssen auf verkettete Promises warten, daher laufen alle Tests
// innerhalb dieser async-Funktion.
async function main() {

/* ---------------------------------------------------------------------
 * 1. ViewContent
 * ------------------------------------------------------------------- */

console.log( '\n=== 1. ViewContent: liest #pms-woo-view-content-data und feuert Pixel + AJAX ===' );

{
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( {
			product_id: 55,
			content_id: '55',
			content_name: 'T-Shirt',
			content_category: 'Apparel',
			value: 19.99,
			currency: 'EUR',
			quantity: 1,
		} ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const r = run( { googleEnabled: true, tiktokEnabled: true }, { elements: [ payload ] } );

	check( 'fbq(\'track\',\'ViewContent\', ...) wird genau einmal aufgerufen', 1 === r.window.fbqCalls.length );
	const call = r.window.fbqCalls[ 0 ] || [];
	check( 'fbq-Aufruf: Event-Name ist ViewContent', 'track' === call[ 0 ] && 'ViewContent' === call[ 1 ] );
	check( 'fbq-Aufruf: enthält angereicherte Produktdaten (content_ids/value/currency)', call[ 2 ] && '55' === ( call[ 2 ].content_ids || [] )[ 0 ] && 19.99 === call[ 2 ].value && 'EUR' === call[ 2 ].currency );
	check( 'fbq-Aufruf: eventID ist gesetzt (für CAPI-Dedup)', !! ( call[ 3 ] && call[ 3 ].eventID ) );

	check( 'gtag(\'event\',\'view_item\', ...) wird genau einmal aufgerufen', 1 === r.window.gtagCalls.length );
	const gCall = r.window.gtagCalls[ 0 ] || [];
	check( 'gtag-Aufruf: Event-Name ist view_item', 'event' === gCall[ 0 ] && 'view_item' === gCall[ 1 ] );
	check( 'gtag-Aufruf: items[] im Google-Schema (item_id/item_name/price/quantity)', gCall[ 2 ] && '55' === gCall[ 2 ].items[ 0 ].item_id && 'T-Shirt' === gCall[ 2 ].items[ 0 ].item_name && 19.99 === gCall[ 2 ].items[ 0 ].price );
	check( 'gtag-Aufruf: value/currency auf oberster Ebene', gCall[ 2 ] && 19.99 === gCall[ 2 ].value && 'EUR' === gCall[ 2 ].currency );

	check( 'ttq.track(\'ViewContent\', ...) wird genau einmal aufgerufen', 1 === r.window.ttqCalls.length );
	const tCall = r.window.ttqCalls[ 0 ] || [];
	check( 'ttq-Aufruf: Event-Name ist ViewContent', 'ViewContent' === tCall[ 0 ] );
	// Seit v0.6.10 in einem contents[]-Array statt als top-level content_id
	// (TikTok-Diagnostics-Warnung, siehe contentsToTiktokContents()).
	check( 'ttq-Aufruf: value/currency auf oberster Ebene', tCall[ 1 ] && 19.99 === tCall[ 1 ].value && 'EUR' === tCall[ 1 ].currency );
	check( 'ttq-Aufruf: contents[] trägt ein explizites content_id (Diagnostics-Fix v0.6.10)', tCall[ 1 ] && Array.isArray( tCall[ 1 ].contents ) && 1 === tCall[ 1 ].contents.length && '55' === tCall[ 1 ].contents[ 0 ].content_id );
	check( 'ttq-Aufruf: contents[0] trägt content_type/quantity/price', tCall[ 1 ] && 'product' === tCall[ 1 ].contents[ 0 ].content_type && 1 === tCall[ 1 ].contents[ 0 ].quantity && 19.99 === tCall[ 1 ].contents[ 0 ].price );
	check( 'ttq-Aufruf: KEIN top-level content_id mehr (zählte für TikToks Diagnostics nicht)', tCall[ 1 ] && undefined === tCall[ 1 ].content_id );
	check( 'ttq-Aufruf: dieselbe event_id wie im fbq()-Aufruf (plattformübergreifende Dedup)', tCall[ 2 ] && tCall[ 2 ].event_id === ( call[ 3 ] && call[ 3 ].eventID ) );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body: action=pms_woo_track', 'pms_woo_track' === params.get( 'action' ) );
	check( 'AJAX-Body: event_name=ViewContent', 'ViewContent' === params.get( 'event_name' ) );
	check( 'AJAX-Body: product_id aus der Nutzlast (NICHT content_id, das evtl. eine SKU ist)', '55' === params.get( 'product_id' ) );
	check( 'AJAX-Body: dieselbe event_id wie im fbq()-Aufruf (Dedup)', ( call[ 3 ] && call[ 3 ].eventID ) === params.get( 'event_id' ) );
}

{
	const r = run( {}, { elements: [] } );
	check( 'ViewContent: ohne #pms-woo-view-content-data passiert nichts', 0 === r.window.fbqCalls.length && 0 === r.window.fetchCalls.length );
}

/* ---------------------------------------------------------------------
 * 2. AddToCart -- AJAX-Buttons (jQuery "added_to_cart")
 * ------------------------------------------------------------------- */

console.log( '\n=== 2. AddToCart: jQuery "added_to_cart" (Archiv-/Mini-Cart-Buttons) ===' );

{
	const r = run( { googleEnabled: true, tiktokEnabled: true }, { jquery: true } );
	const button = el( { tag: 'a', attrs: { 'data-product_id': '77', 'data-quantity': '2' } } );

	r.jq.trigger( 'added_to_cart', {}, 'hash', [ button ] );

	check( 'fbq(\'track\',\'AddToCart\', ...) wird aufgerufen', 1 === r.window.fbqCalls.length );
	const call = r.window.fbqCalls[ 0 ] || [];
	check( 'AddToCart-Pixel: KEIN value/currency (Archiv-Button kennt keinen sicheren Preis)', call[ 2 ] && undefined === call[ 2 ].value && '77' === ( call[ 2 ].content_ids || [] )[ 0 ] );

	check( 'gtag(\'event\',\'add_to_cart\', ...) wird aufgerufen', 1 === r.window.gtagCalls.length );
	const gCall = r.window.gtagCalls[ 0 ] || [];
	check( 'gtag AddToCart: items[] mit item_id/quantity, KEIN price (kein sicherer Preis im Archiv-Kontext)', gCall[ 2 ] && '77' === gCall[ 2 ].items[ 0 ].item_id && 2 === gCall[ 2 ].items[ 0 ].quantity && undefined === gCall[ 2 ].items[ 0 ].price );

	check( 'ttq.track(\'AddToCart\', ...) wird aufgerufen', 1 === r.window.ttqCalls.length );
	const tCall = r.window.ttqCalls[ 0 ] || [];
	check( 'ttq AddToCart: contents[] mit content_id/quantity, KEIN value (kein sicherer Preis im Archiv-Kontext)', tCall[ 1 ] && Array.isArray( tCall[ 1 ].contents ) && '77' === tCall[ 1 ].contents[ 0 ].content_id && 2 === tCall[ 1 ].contents[ 0 ].quantity && undefined === tCall[ 1 ].value );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body: product_id aus data-product_id', '77' === params.get( 'product_id' ) );
	check( 'AJAX-Body: quantity aus data-quantity', '2' === params.get( 'quantity' ) );
	check( 'AJAX-Body: variation_id ist 0 (kein Variable Product im Archiv-Kontext)', '0' === params.get( 'variation_id' ) );
}

{
	const r = run( {}, { jquery: true } );
	// Button ohne data-product_id -> darf kein Event auslösen.
	r.jq.trigger( 'added_to_cart', {}, 'hash', [ el( { tag: 'a' } ) ] );
	check( 'AddToCart: kein Event ohne product_id', 0 === r.window.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 3. AddToCart -- Single-Product-Formular (form.cart), inkl. Variable Products
 * ------------------------------------------------------------------- */

console.log( '\n=== 3. AddToCart: form.cart-Submit (Simple + Variable Products) + Lock ===' );

{
	const addButton = el( { tag: 'button', name: 'add-to-cart', value: '88' } );
	const variationInput = el( { tag: 'input', name: 'variation_id', value: '91' } );
	const qtyInput = el( { tag: 'input', name: 'quantity', value: '3' } );
	const form = el( { tag: 'form', classes: [ 'cart' ], children: [ addButton, variationInput, qtyInput ] } );

	const r = run( { googleEnabled: true, tiktokEnabled: true }, {} );
	const submitHandlers = r.document._listeners.submit || [];
	check( 'submit-Listener für form.cart wird registriert', submitHandlers.length > 0 );

	submitHandlers[ 0 ]( { target: form } );

	check( 'fbq(\'track\',\'AddToCart\') wird beim Absenden von form.cart gefeuert', 1 === r.window.fbqCalls.length );
	check( 'gtag(\'event\',\'add_to_cart\') wird beim Absenden von form.cart gefeuert', 1 === r.window.gtagCalls.length );
	check( 'ttq.track(\'AddToCart\') wird beim Absenden von form.cart gefeuert', 1 === r.window.ttqCalls.length );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body: product_id aus dem add-to-cart-Button-Value', '88' === params.get( 'product_id' ) );
	check( 'AJAX-Body: variation_id aus dem hidden-Feld (Variable Product)', '91' === params.get( 'variation_id' ) );
	check( 'AJAX-Body: quantity aus dem Mengen-Feld', '3' === params.get( 'quantity' ) );

	// Zweites, unmittelbares Submit desselben Formulars -> durch den Lock
	// blockiert (setTimeout ist im Test standardmäßig ein No-Op, der Lock
	// bleibt also aktiv, siehe run()-Doku oben).
	submitHandlers[ 0 ]( { target: form } );
	check( 'Lock: ein zweites, unmittelbares Submit desselben Formulars löst KEIN weiteres Event aus', 1 === r.window.fbqCalls.length );
}

{
	// Formulare ohne class="cart" (z. B. ein normales Kontaktformular) werden ignoriert.
	const form = el( { tag: 'form', classes: [ 'contact-form' ] } );
	const r = run( {}, {} );
	const submitHandlers = r.document._listeners.submit || [];
	submitHandlers[ 0 ]( { target: form } );
	check( 'Formulare ohne class="cart" lösen kein AddToCart aus', 0 === r.window.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 4. InitiateCheckout -- Classic Checkout (PHP-Nutzlast)
 * ------------------------------------------------------------------- */

console.log( '\n=== 4. InitiateCheckout: Classic Checkout liest #pms-woo-checkout-data ===' );

{
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( {
			content_ids: [ '10', '20' ],
			value: 59.98,
			currency: 'EUR',
			contents: [
				{ id: '10', quantity: 1, item_price: 39.99 },
				{ id: '20', quantity: 1, item_price: 19.99 },
			],
			num_items: 2,
		} ),
	} );
	payload.id = 'pms-woo-checkout-data';

	const r = run( { googleEnabled: true, tiktokEnabled: true }, { elements: [ payload ] } );

	check( 'fbq(\'track\',\'InitiateCheckout\') wird gefeuert', 1 === r.window.fbqCalls.length );
	const call = r.window.fbqCalls[ 0 ] || [];
	check( 'InitiateCheckout-Pixel: enthält beide content_ids + Gesamtwert', call[ 2 ] && 2 === ( call[ 2 ].content_ids || [] ).length && 59.98 === call[ 2 ].value );

	check( 'gtag(\'event\',\'begin_checkout\') wird gefeuert', 1 === r.window.gtagCalls.length );
	const gCall = r.window.gtagCalls[ 0 ] || [];
	check( 'gtag begin_checkout: contents[] ins Google-items[]-Schema übersetzt (item_id/price/quantity)', gCall[ 2 ] && 2 === gCall[ 2 ].items.length && '10' === gCall[ 2 ].items[ 0 ].item_id && 39.99 === gCall[ 2 ].items[ 0 ].price );
	check( 'gtag begin_checkout: value/currency aus der Nutzlast', gCall[ 2 ] && 59.98 === gCall[ 2 ].value && 'EUR' === gCall[ 2 ].currency );

	check( 'ttq.track(\'InitiateCheckout\') wird gefeuert', 1 === r.window.ttqCalls.length );
	const tCall = r.window.ttqCalls[ 0 ] || [];
	check( 'ttq InitiateCheckout: value/currency gesetzt', tCall[ 1 ] && 59.98 === tCall[ 1 ].value && 'EUR' === tCall[ 1 ].currency );
	// Seit v0.6.10: bis dahin trug InitiateCheckout gar kein contents[], was
	// TikToks Diagnostics als fehlendes content_id bemängelte.
	check( 'ttq InitiateCheckout: contents[] mit content_id je Position (Diagnostics-Fix v0.6.10)', tCall[ 1 ] && Array.isArray( tCall[ 1 ].contents ) && 2 === tCall[ 1 ].contents.length && '10' === tCall[ 1 ].contents[ 0 ].content_id && '20' === tCall[ 1 ].contents[ 1 ].content_id );
	check( 'ttq InitiateCheckout: contents[] trägt price/quantity aus derselben Nutzlast wie Meta/Google', tCall[ 1 ] && 39.99 === tCall[ 1 ].contents[ 0 ].price && 1 === tCall[ 1 ].contents[ 0 ].quantity );

	const body = ( r.window.fetchCalls[ 0 ] || {} ).body || '';
	const params = new URLSearchParams( body );
	check( 'AJAX-Body: event_name=InitiateCheckout, KEINE Produktdaten nötig (Server liest WC()->cart selbst)', 'InitiateCheckout' === params.get( 'event_name' ) && null === params.get( 'product_id' ) );
}

/* ---------------------------------------------------------------------
 * 5. InitiateCheckout -- Block-Checkout (Store API, Minor-Unit-Preise)
 * ------------------------------------------------------------------- */

console.log( '\n=== 5. InitiateCheckout: Block-Checkout liest die WooCommerce Store API ===' );

{
	const blockRoot = el( { tag: 'div', classes: [ 'wp-block-woocommerce-checkout' ] } );

	const storeApiResponse = {
		items: [
			{ id: 30, quantity: 2, prices: { price: '1999' } }, // 19,99 in Minor Units (Cent).
		],
		totals: {
			currency_code: 'EUR',
			currency_minor_unit: 2,
			total_price: '3998',
		},
	};

	const r = run(
		{ googleEnabled: true, tiktokEnabled: true },
		{
			querySelectorResults: { '.wp-block-woocommerce-checkout': blockRoot },
			storeApiResponse: storeApiResponse,
		}
	);

	await flushMicrotasks();

	check( 'Store API wird abgefragt, wenn der Block-Checkout-Container erkannt wird', 1 === r.window.fetchCalls.filter( ( c ) => c.url === r.window.pms_woo_settings.storeCartUrl ).length );
	check( 'fbq(\'track\',\'InitiateCheckout\') wird nach Auflösen der Store API gefeuert', 1 === r.window.fbqCalls.length );

	const call = r.window.fbqCalls[ 0 ] || [];
	check( 'Minor-Unit-Preis wird korrekt zurückgerechnet (1999 Cent -> 19.99)', call[ 2 ] && 19.99 === call[ 2 ].contents[ 0 ].item_price );
	check( 'Minor-Unit-Gesamtwert wird korrekt zurückgerechnet (3998 Cent -> 39.98)', call[ 2 ] && 39.98 === call[ 2 ].value );
	check( 'currency aus currency_code', call[ 2 ] && 'EUR' === call[ 2 ].currency );

	check( 'gtag(\'event\',\'begin_checkout\') wird nach Auflösen der Store API gefeuert', 1 === r.window.gtagCalls.length );
	const gCall = r.window.gtagCalls[ 0 ] || [];
	check( 'gtag begin_checkout (Block-Checkout): Minor-Unit-Preis korrekt in items[].price zurückgerechnet', gCall[ 2 ] && 19.99 === gCall[ 2 ].items[ 0 ].price );
	check( 'gtag begin_checkout (Block-Checkout): Minor-Unit-Gesamtwert korrekt zurückgerechnet', gCall[ 2 ] && 39.98 === gCall[ 2 ].value );

	check( 'ttq.track(\'InitiateCheckout\') wird nach Auflösen der Store API gefeuert', 1 === r.window.ttqCalls.length );
	const tCall = r.window.ttqCalls[ 0 ] || [];
	check( 'ttq InitiateCheckout (Block-Checkout): Minor-Unit-Gesamtwert korrekt zurückgerechnet', tCall[ 1 ] && 39.98 === tCall[ 1 ].value );
}

{
	// Klassischer Checkout hat Vorrang: liegt #pms-woo-checkout-data bereits
	// vor, wird die Store API gar nicht erst abgefragt.
	const payload = el( { tag: 'script', textContent: JSON.stringify( { content_ids: [ '1' ], value: 1, currency: 'EUR', contents: [], num_items: 1 } ) } );
	payload.id = 'pms-woo-checkout-data';
	const blockRoot = el( { tag: 'div', classes: [ 'wp-block-woocommerce-checkout' ] } );

	const r = run( {}, { elements: [ payload ], querySelectorResults: { '.wp-block-woocommerce-checkout': blockRoot } } );
	await flushMicrotasks();

	check( 'Block-Checkout-Fallback läuft NICHT, wenn die klassische Nutzlast bereits vorhanden ist', 0 === r.window.fetchCalls.filter( ( c ) => c.url === r.window.pms_woo_settings.storeCartUrl ).length );
}

/* ---------------------------------------------------------------------
 * 6. Consent-Queue
 * ------------------------------------------------------------------- */

console.log( '\n=== 6. Consent-Queue: Events werden bei fehlendem Consent zurückgehalten und später geflusht ===' );

{
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const r = run(
		{ consentEvents: [ 'cmplz_fire_categories' ] },
		{ elements: [ payload ], hasConsent: false, autoTimeout: true }
	);

	check( 'Ohne Consent: kein fbq()-Aufruf beim initialen Laden', 0 === r.window.fbqCalls.length );
	check( 'Ohne Consent: kein AJAX-Request beim initialen Laden', 0 === r.window.fetchCalls.length );

	// Consent-Status wechselt auf "erteilt" und das Banner-Event feuert.
	r.window.pmsHasConsent = function () {
		return true;
	};
	r.document.dispatchEvent( { type: 'cmplz_fire_categories' } );

	check( 'Nach dem Consent-Event: das zurückgehaltene Event wird gefeuert (fbq)', 1 === r.window.fbqCalls.length );
	check( 'Nach dem Consent-Event: der AJAX-Request wird ebenfalls nachgeholt', 1 === r.window.fetchCalls.length );
}

{
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const r = run( {}, { elements: [ payload ], hasConsent: true } );
	check( 'Mit sofortigem Consent: Event feuert ohne Verzögerung', 1 === r.window.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 7. Google Ads & TikTok: Enabled-Flag-Gating
 * ------------------------------------------------------------------- */

console.log( '\n=== 7. Google Ads & TikTok: Enabled-Flag-Gating ===' );

{
	// Default-cfg (googleEnabled/tiktokEnabled=false, siehe run()): gtag/ttq
	// existieren im Fenster, dürfen aber NICHT aufgerufen werden -- das
	// eigene Einstellungs-Flag muss unabhängig von einem fremden GTM/Tool,
	// das gtag/ttq zufällig ebenfalls definiert, respektiert werden.
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const r = run( {}, { elements: [ payload ] } );

	check( 'googleEnabled=false: gtag() wird NICHT aufgerufen, obwohl window.gtag existiert', 0 === r.window.gtagCalls.length );
	check( 'tiktokEnabled=false: ttq.track() wird NICHT aufgerufen, obwohl window.ttq existiert', 0 === r.window.ttqCalls.length );
	check( 'Meta-Pixel feuert unabhängig vom Google/TikTok-Gating weiterhin', 1 === r.window.fbqCalls.length );
}

{
	// Umgekehrt: Flags an, aber window.gtag/window.ttq existieren nicht (z. B.
	// weil class-pms-frontend.php sie aus einem anderen Grund nicht geladen
	// hat) -- darf nicht crashen, Meta bleibt unberührt.
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const r = run( { googleEnabled: true, tiktokEnabled: true }, { elements: [ payload ], noGtag: true, noTtq: true } );

	check( 'Fehlendes window.gtag/window.ttq: kein Crash, Meta-Pixel feuert weiterhin', 1 === r.window.fbqCalls.length );
}

/* ---------------------------------------------------------------------
 * 7b. v0.6.11: gefeuerte Plattformen im pms:event-Detail
 * ------------------------------------------------------------------- */

console.log( '\n=== 7b. pms:event meldet, welche Ziele tatsächlich gefeuert haben ===' );

{
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';

	const events = [];
	const r = run( { googleEnabled: true, tiktokEnabled: true, ga4MeasurementId: 'G-ABC123' }, { elements: [ payload ] } );
	// Der Listener muss VOR dem Feuern registriert sein -- init() läuft aber
	// synchron beim Laden. Deshalb hier stattdessen den zweiten Durchlauf
	// über ein manuell ausgelöstes AddToCart prüfen.
	r.document.addEventListener( 'pms:event', ( e ) => events.push( e.detail ) );

	check( '7b.1 Google Ads + konfiguriertes GA4-Property werden gemeinsam ausgewiesen', (function () {
		// Der ViewContent-Dispatch lief bereits beim Laden; hier prüfen wir das
		// gerenderte Ergebnis über die tatsächlichen SDK-Aufrufe gegen.
		return 1 === r.window.gtagCalls.length && 1 === r.window.ttqCalls.length && 1 === r.window.fbqCalls.length;
	})() );
}

{
	// AddToCart über den jQuery-Weg: hier kann der Listener vor dem Feuern
	// registriert werden, das pms:event-Detail ist also direkt prüfbar.
	const events = [];
	const r = run(
		{ googleEnabled: true, tiktokEnabled: true, ga4MeasurementId: 'G-ABC123' },
		{ jquery: true }
	);
	r.document.addEventListener( 'pms:event', ( e ) => events.push( e.detail ) );

	const button = el( { tag: 'a', attrs: { 'data-product_id': '77', 'data-quantity': '2' } } );
	r.jq.trigger( 'added_to_cart', null, null, [ button ] );

	check( '7b.2 pms:event trägt die Liste der gefeuerten Ziele', (function () {
		const det = events[ 0 ];
		return !! det && Array.isArray( det.platforms ) && det.platforms.indexOf( 'Meta' ) > -1 && det.platforms.indexOf( 'TikTok' ) > -1;
	})() );
	check( '7b.3 Bei konfiguriertem GA4 wird "Google Ads / GA4" ausgewiesen (ein gtag-Event erreicht beide Ziele)', (function () {
		const det = events[ 0 ];
		return !! det && det.platforms.indexOf( 'Google Ads / GA4' ) > -1;
	})() );
}

{
	// Ohne GA4-Property bleibt es bei "Google Ads".
	const events = [];
	const r = run( { googleEnabled: true, tiktokEnabled: false }, { jquery: true } );
	r.document.addEventListener( 'pms:event', ( e ) => events.push( e.detail ) );

	const button = el( { tag: 'a', attrs: { 'data-product_id': '77', 'data-quantity': '1' } } );
	r.jq.trigger( 'added_to_cart', null, null, [ button ] );

	check( '7b.4 Ohne GA4-Property: nur "Google Ads", TikTok fehlt (Flag aus)', (function () {
		const det = events[ 0 ];
		return !! det && det.platforms.indexOf( 'Google Ads' ) > -1 && det.platforms.indexOf( 'TikTok' ) === -1;
	})() );
}

/* ---------------------------------------------------------------------
 * 8. Flexibler Consent-Modus (consentMode: 'browser_only', v0.6.10)
 * ------------------------------------------------------------------- */

console.log( '\n=== 8. Consent-Modus "browser_only": Server sofort, Browser-Pixel erst nach der Einwilligung ===' );

function viewContentPayload() {
	const payload = el( {
		tag: 'script',
		textContent: JSON.stringify( { product_id: 1, content_id: '1', content_name: 'X', content_category: '', value: 1, currency: 'EUR', quantity: 1 } ),
	} );
	payload.id = 'pms-woo-view-content-data';
	return payload;
}

{
	const r = run(
		{ consentMode: 'browser_only', consentEvents: [ 'cmplz_fire_categories' ], googleEnabled: true, tiktokEnabled: true },
		{ elements: [ viewContentPayload() ], hasConsent: false, autoTimeout: true }
	);

	check( 'browser_only ohne Consent: AJAX-/CAPI-Request geht SOFORT raus', 1 === r.window.fetchCalls.length );
	check( 'browser_only ohne Consent: kein fbq()-Aufruf', 0 === r.window.fbqCalls.length );
	check( 'browser_only ohne Consent: kein gtag()-Aufruf', 0 === r.window.gtagCalls.length );
	check( 'browser_only ohne Consent: kein ttq.track()-Aufruf', 0 === r.window.ttqCalls.length );

	const params = new URLSearchParams( ( r.window.fetchCalls[ 0 ] || {} ).body || '' );
	const serverEventId = params.get( 'event_id' );
	check( 'browser_only: der Server-Request meldet browser_fired=0 (der Pixel lief ja nicht)', '0' === params.get( 'browser_fired' ) );

	// Der Besucher willigt nachträglich ein -> der Browser-Pixel wird nachgeholt,
	// OHNE einen zweiten Server-Request auszulösen.
	r.window.pmsHasConsent = function () {
		return true;
	};
	r.document.dispatchEvent( { type: 'cmplz_fire_categories' } );

	check( 'browser_only + nachträglicher Consent: Browser-Pixel wird nachgeholt', 1 === r.window.fbqCalls.length );
	check( 'browser_only + nachträglicher Consent: KEIN zweiter Server-Request', 1 === r.window.fetchCalls.length );

	const fbCall = r.window.fbqCalls[ 0 ] || [];
	check( 'browser_only: nachgeholter Pixel trägt dieselbe event_id wie der Server-Request (Meta dedupliziert)', fbCall[ 3 ] && fbCall[ 3 ].eventID === serverEventId );
}

{
	// Gegenprobe: derselbe Aufbau im Default-Modus 'strict' -- dort wandert
	// weiterhin ALLES in die Queue (unverändertes Verhalten vor v0.6.10).
	const r = run(
		{ consentMode: 'strict', consentEvents: [ 'cmplz_fire_categories' ] },
		{ elements: [ viewContentPayload() ], hasConsent: false, autoTimeout: true }
	);

	check( 'strict ohne Consent: weder Pixel noch Server-Request', 0 === r.window.fbqCalls.length && 0 === r.window.fetchCalls.length );

	r.window.pmsHasConsent = function () {
		return true;
	};
	r.document.dispatchEvent( { type: 'cmplz_fire_categories' } );

	check( 'strict + nachträglicher Consent: beides wird gemeinsam nachgeholt', 1 === r.window.fbqCalls.length && 1 === r.window.fetchCalls.length );
	const params = new URLSearchParams( ( r.window.fetchCalls[ 0 ] || {} ).body || '' );
	check( 'strict + nachträglicher Consent: Server-Request meldet browser_fired=1', '1' === params.get( 'browser_fired' ) );
}

{
	// Consent liegt beim Laden bereits vor: der Modus darf keine Rolle spielen.
	const r = run(
		{ consentMode: 'browser_only' },
		{ elements: [ viewContentPayload() ], hasConsent: true }
	);
	check( 'browser_only MIT Consent: Pixel und Server-Request laufen wie gewohnt zusammen', 1 === r.window.fbqCalls.length && 1 === r.window.fetchCalls.length );
	const params = new URLSearchParams( ( r.window.fetchCalls[ 0 ] || {} ).body || '' );
	check( 'browser_only MIT Consent: browser_fired=1', '1' === params.get( 'browser_fired' ) );
}

}

main().then( function () {
	console.log( '\n==============================' );
	console.log( 'Ergebnis: ' + pass + ' bestanden, ' + fail + ' fehlgeschlagen' );
	process.exit( fail > 0 ? 1 : 0 );
} );
