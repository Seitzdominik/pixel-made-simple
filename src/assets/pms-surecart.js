/**
 * Pixel Made Simple – SureCart-Tracking (ViewContent, AddToCart,
 * InitiateCheckout, Purchase-Browser-Pixel). Pro-only, siehe
 * pro/class-pro-surecart.php / pro/class-pro-surecart-purchase.php.
 *
 * STRUKTURELL ANDERS als assets/pms-woocommerce.js, weil SureCart -- anders
 * als WooCommerce (jQuery-Event "added_to_cart", Template-Hooks
 * woocommerce_after_single_product/woocommerce_before_checkout_form) --
 * KEIN dokumentiertes JavaScript-Event für "Produkt zum Warenkorb
 * hinzugefügt" oder "Checkout betreten" bereitstellt (recherchiert gegen
 * developer.surecart.com, Stand dieser Session). ViewContent ist davon
 * NICHT betroffen (reine serverseitige is_singular('sc_product')-Erkennung,
 * siehe #pms-surecart-view-content-data) und entsprechend zuverlässig.
 *
 * Für AddToCart/InitiateCheckout/Purchase beobachtet dieses Skript
 * stattdessen den tatsächlichen SureCart-REST-API-Traffic: SureCarts
 * Checkout-/Warenkorb-Web-Components sprechen laut offizieller
 * REST-API-Referenz direkt mit einer öffentlichen REST-API (Line Items,
 * Checkouts) -- jeder "zum Warenkorb hinzufügen"-Klick MUSS also einen
 * fetch()-Request auslösen, unabhängig vom verwendeten Theme/Block. Ein
 * transparenter fetch()-Wrapper (installFetchObserver(), siehe unten) liest
 * NUR mit (via response.clone(), der echte Request/die echte Response
 * bleiben für SureCart selbst komplett unangetastet) und erkennt anhand der
 * JSON-Form der Antwort, ob es sich um einen einzelnen neuen Warenkorb-
 * Eintrag, eine Line-Item-Liste oder einen Checkout-Snapshot handelt.
 *
 * UNVERIFIZIERT gegen ein echtes SureCart-Backend: Die exakten REST-
 * Response-Formen (insbesondere ob eine einzelne Line-Item-Erstellung
 * tatsächlich das rohe LineItem-Objekt zurückgibt) basieren auf der
 * offiziellen REST-API-Referenz (developer.surecart.com/api-reference), NICHT
 * auf einer Live-Beobachtung im Browser-Netzwerk-Tab. Bekannter, bewusst in
 * Kauf genommener Trade-off: Lädt SureCarts eigenes UI seinen initialen
 * Warenkorb-/Checkout-Zustand, BEVOR dieses (deferred geladene) Skript seinen
 * fetch()-Wrapper installiert hat, wird dieser erste Snapshot nicht erfasst
 * -- spätere Zustandsänderungen (Menge ändern, Coupon anwenden, zur Kasse
 * gehen) lösen aber i. d. R. weitere Requests aus, die weiterhin erfasst
 * werden. Vor Produktiveinsatz gegen die Netzwerk-Requests einer echten
 * SureCart-Checkout-Seite prüfen (Browser-DevTools) -- dieselbe Vorsicht wie
 * bei jedem anderen "gegen offizielle Doku statt Live-Test gebauten" Teil
 * dieser Integration.
 *
 * Purchase (Meta/Google/TikTok Browser-Pixel) feuert ebenfalls aus diesem
 * Skript heraus, sobald ein beobachteter Checkout-Snapshot einen
 * "bezahlt"-Status zeigt -- siehe pro/class-pro-surecart-purchase.php für
 * die ausführliche Begründung, warum SureCart (anders als WooCommerce)
 * keinen PHP-Seiten-Render-Hook für die Bestätigung bietet, über den sich
 * ein <script>-Tag serverseitig einbetten ließe. Die deterministische
 * event_id ("pms_sc_order_" + checkout.id) ist hier client- UND
 * serverseitig (PMS_Pro_SureCart_Purchase::event_id()) identisch aufgebaut,
 * damit Meta/TikTok Browser- und Server-Event weiterhin deduplizieren.
 *
 * Vanilla JS, keine Abhängigkeiten (kein jQuery -- SureCarts eigene
 * Web-Components basieren nicht darauf).
 */
( function () {
	'use strict';

	var cfg = window.pms_surecart_settings || {};

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = 'x' === c ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function emit( name, detail ) {
		try {
			document.dispatchEvent( new CustomEvent( name, { detail: detail } ) );
		} catch ( e ) {
			/* Ältere Browser ohne CustomEvent-Konstruktor: Debug-Ausgabe entfällt. */
		}
	}

	function safeParseJSON( text ) {
		try {
			return JSON.parse( text );
		} catch ( e ) {
			return null;
		}
	}

	function round2( n ) {
		return Math.round( ( n + Number.EPSILON ) * 100 ) / 100;
	}

	function minorUnitDivisor() {
		return cfg.currencyMinorUnitDivisor || 100;
	}

	function contentsToGoogleItems( contents ) {
		return ( contents || [] ).map( function ( item ) {
			return { item_id: String( item.id ), price: item.item_price || 0, quantity: item.quantity || 1 };
		} );
	}

	/* -----------------------------------------------------------------
	 * Consent-Queue (identisch zu assets/pms-woocommerce.js)
	 * ------------------------------------------------------------------- */

	var pendingQueue = [];

	function hasConsent() {
		if ( 'function' === typeof window.pmsHasConsent ) {
			return window.pmsHasConsent();
		}
		return true;
	}

	function flushQueue() {
		if ( ! pendingQueue.length || ! hasConsent() ) {
			return;
		}
		var queued = pendingQueue;
		pendingQueue = [];
		queued.forEach( function ( fn ) {
			fn();
		} );
	}

	function enqueueOrFire( fn ) {
		if ( hasConsent() ) {
			fn();
			return;
		}
		pendingQueue.push( fn );
	}

	( cfg.consentEvents || [] ).forEach( function ( name ) {
		var handler = function () {
			setTimeout( flushQueue, 100 );
		};
		document.addEventListener( name, handler );
		window.addEventListener( name, handler );
	} );

	/**
	 * Wartet auf denselben globalen Init-Guard wie class-pms-frontend.php
	 * (window.pmsInitialized) statt eine zweite Consent-Prüfung nachzubauen
	 * -- identisches Polling-Muster zu
	 * PMS_Pro_Woo_Purchase::print_pixel_scripts()' generiertem JS, hier nur
	 * nativ statt aus PHP heraus als String gebaut.
	 */
	function waitForPmsInit( fn ) {
		function attempt() {
			if ( window.pmsInitialized ) {
				fn();
				return true;
			}
			return false;
		}
		if ( attempt() ) {
			return;
		}
		var iv = setInterval( function () {
			if ( attempt() ) {
				clearInterval( iv );
			}
		}, 150 );
		setTimeout( function () {
			clearInterval( iv );
		}, 30000 );
	}

	/* -----------------------------------------------------------------
	 * Gemeinsamer Dispatch: Browser-Pixel(e) + asynchrone Meta-CAPI --
	 * identisch zu sendTracking() in assets/pms-woocommerce.js.
	 * ------------------------------------------------------------------- */

	function sendTracking( eventName, ajaxFields, platforms ) {
		var eventId = uuid();
		platforms = platforms || {};

		function fire() {
			var browserFired = false;

			if ( platforms.meta && 'function' === typeof window.fbq ) {
				window.fbq( 'track', eventName, platforms.meta, { eventID: eventId } );
				browserFired = true;
			}

			if ( cfg.googleEnabled && platforms.google && 'function' === typeof window.gtag ) {
				window.gtag( 'event', platforms.google.event, platforms.google.params );
			}

			if ( cfg.tiktokEnabled && platforms.tiktok && 'function' === typeof window.ttq ) {
				window.ttq.track( platforms.tiktok.event, platforms.tiktok.params, { event_id: eventId } );
			}

			emit( 'pms:event', {
				event: eventName,
				eventId: eventId,
				browser: browserFired ? 'fired' : 'no_pixel',
				capi: 'pending'
			} );

			if ( ! window.fetch || ! cfg.ajaxUrl ) {
				return;
			}

			var body = new URLSearchParams();
			body.append( 'action', 'pms_surecart_track' );
			body.append( 'nonce', cfg.nonce || '' );
			body.append( 'event_id', eventId );
			body.append( 'event_name', eventName );
			body.append( 'source_url', window.location.href );
			body.append( 'browser_fired', browserFired ? '1' : '0' );

			Object.keys( ajaxFields || {} ).forEach( function ( key ) {
				body.append( key, String( ajaxFields[ key ] ) );
			} );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				var payload = ( result && result.data ) || {};
				emit( 'pms:capi', {
					eventId: eventId,
					status: result && result.success ? ( payload.status || 'sent' ) : ( payload.reason || 'error' ),
					code: payload.code || 0,
					message: payload.message || '',
					matchKeys: payload.match_keys || []
				} );
			} ).catch( function () {
				emit( 'pms:capi', { eventId: eventId, status: 'error', message: 'request failed' } );
			} );
		}

		enqueueOrFire( fire );
	}

	/* -----------------------------------------------------------------
	 * ViewContent (Produktseite) -- hoch zuverlässig, reine serverseitige
	 * is_singular('sc_product')-Erkennung, siehe class-pro-surecart.php.
	 * ------------------------------------------------------------------- */

	function initViewContent() {
		var el = document.getElementById( 'pms-surecart-view-content-data' );
		if ( ! el ) {
			return;
		}

		var data = safeParseJSON( el.textContent );
		if ( ! data || ! data.product_id ) {
			return;
		}

		sendTracking(
			'ViewContent',
			{ product_id: data.product_id, quantity: data.quantity || 1 },
			{
				meta: {
					content_ids: [ String( data.content_id ) ],
					content_type: 'product',
					content_name: data.content_name || '',
					content_category: data.content_category || '',
					value: data.value || 0,
					currency: data.currency || ''
				},
				google: {
					event: 'view_item',
					params: {
						currency: data.currency || '',
						value: data.value || 0,
						items: [ {
							item_id: String( data.content_id ),
							item_name: data.content_name || '',
							price: data.value || 0,
							quantity: data.quantity || 1
						} ]
					}
				},
				tiktok: {
					event: 'ViewContent',
					params: {
						content_id: String( data.content_id ),
						content_type: 'product',
						content_name: data.content_name || '',
						value: data.value || 0,
						currency: data.currency || ''
					}
				}
			}
		);
	}

	/* -----------------------------------------------------------------
	 * REST-Traffic-Beobachtung (AddToCart / InitiateCheckout / Purchase)
	 * ------------------------------------------------------------------- */

	/**
	 * checkoutId -> { checkout, lineItems, initiateFired, purchaseFired,
	 * waiting, graceElapsed }. "waiting"/"graceElapsed" steuern die kurze
	 * Gnadenfrist in evaluate() unten (siehe dortige Doku), bevor mit
	 * unvollständigen Daten (Checkout ohne beobachtete Line-Items) gefeuert
	 * wird.
	 */
	var checkoutCache = {};

	/** Millisekunden, die evaluate() nach einem Checkout-Snapshot ohne
	 * begleitende Line-Items abwartet, bevor es trotzdem (mit unvollständigen
	 * Daten) feuert -- siehe dortige Doku. */
	var LINE_ITEMS_GRACE_MS = 300;

	function cacheFor( id ) {
		if ( ! checkoutCache[ id ] ) {
			checkoutCache[ id ] = {
				checkout: null,
				lineItems: null,
				initiateFired: false,
				purchaseFired: false,
				waiting: false,
				graceElapsed: false
			};
		}
		return checkoutCache[ id ];
	}

	function isCheckoutShape( obj ) {
		return !! obj && 'object' === typeof obj && ! Array.isArray( obj )
			&& 'id' in obj && 'total_amount' in obj && 'currency' in obj && 'status' in obj;
	}

	function isLineItemObject( obj ) {
		return !! obj && 'object' === typeof obj && ! Array.isArray( obj )
			&& 'quantity' in obj && 'price' in obj && 'total_amount' in obj;
	}

	function extractLineItemList( value ) {
		var arr = null;
		if ( Array.isArray( value ) ) {
			arr = value;
		} else if ( value && Array.isArray( value.data ) ) {
			arr = value.data;
		}
		if ( ! arr || ! arr.length || ! isLineItemObject( arr[ 0 ] ) ) {
			return null;
		}
		return arr;
	}

	function lineItemCheckoutId( item ) {
		if ( item.checkout && 'object' === typeof item.checkout ) {
			return item.checkout.id || '';
		}
		return item.checkout || '';
	}

	function isPaidStatus( status ) {
		var normalized = String( status || '' ).toLowerCase();
		return [ 'paid', 'complete', 'completed', 'confirmed' ].indexOf( normalized ) !== -1;
	}

	function onCheckoutPage() {
		return !! document.querySelector(
			'.wp-block-surecart-line-items, .wp-block-surecart-submit, .wp-block-surecart-totals, .wp-block-surecart-coupon'
		);
	}

	function rememberCheckout( checkout ) {
		if ( ! checkout.id ) {
			return;
		}
		cacheFor( checkout.id ).checkout = checkout;
		evaluate( checkout.id );
	}

	function rememberLineItems( checkoutId, items ) {
		cacheFor( checkoutId ).lineItems = items;
		evaluate( checkoutId );
	}

	/**
	 * Entscheidet, ob/was für einen beobachteten Checkout gefeuert werden
	 * soll. Checkout-Snapshot und die zugehörige Line-Items-Liste kommen aus
	 * ZWEI unabhängigen beobachteten Requests (siehe Datei-Kommentar oben).
	 *
	 * Purchase feuert IMMER sofort, sobald ein "bezahlt"-Snapshot beobachtet
	 * wird -- value/currency (die wichtigsten Felder für Meta/Google/TikToks
	 * eigene Optimierung) stehen bereits vollständig im Checkout-Snapshot
	 * selbst (siehe buildPayloadFromLineItems()), Line-Items liefern hier nur
	 * die contents[]-Aufschlüsselung als Bonus. Geschwindigkeit/
	 * Zuverlässigkeit wiegen für ein Kaufabschluss-Event schwerer als eine
	 * künstliche Wartezeit auf Zusatzdaten.
	 *
	 * InitiateCheckout wartet dagegen einmalig LINE_ITEMS_GRACE_MS auf die
	 * Line-Items, falls sie zum Zeitpunkt des ersten Aufrufs noch fehlen --
	 * ohne diese Gnadenfrist würde ein Checkout-Snapshot, der (wie in der
	 * Praxis üblich) knapp VOR seinen Line-Items eintrifft, sofort mit leeren
	 * contents[] feuern, bevor die zweite Beobachtung überhaupt Gelegenheit
	 * hatte zu landen. Trifft die zweite Beobachtung in der Zwischenzeit ein,
	 * feuert der dadurch ausgelöste evaluate()-Aufruf sofort mit
	 * vollständigen Daten (der Timer läuft dann ins Leere, siehe
	 * initiateFired-Guard); andernfalls feuert der Timer selbst nach Ablauf
	 * der Frist mit den bis dahin verfügbaren (unvollständigen) Daten -- ein
	 * Event mit weniger Daten ist besser als gar keins, dieselbe Haltung wie
	 * überall sonst in dieser Integration.
	 *
	 * @param {string} id Checkout-ID.
	 */
	function evaluate( id ) {
		var entry = checkoutCache[ id ];
		if ( ! entry || ! entry.checkout ) {
			return;
		}

		if ( isPaidStatus( entry.checkout.status ) ) {
			if ( ! entry.purchaseFired ) {
				entry.purchaseFired = true;
				firePurchase( entry.checkout, entry.lineItems || [] );
			}
			return;
		}

		if ( entry.initiateFired || ! onCheckoutPage() ) {
			return;
		}

		if ( ! entry.lineItems && ! entry.graceElapsed ) {
			if ( ! entry.waiting ) {
				entry.waiting = true;
				setTimeout( function () {
					entry.graceElapsed = true;
					evaluate( id );
				}, LINE_ITEMS_GRACE_MS );
			}
			return;
		}

		entry.initiateFired = true;
		fireInitiateCheckout( entry.checkout, entry.lineItems || [] );
	}

	/**
	 * checkout+lineItems in ein pixel-taugliches Payload übersetzen.
	 * content_ids nutzen hier mangels client-seitig auflösbarer
	 * SKU/Produkt-ID die Price-ID als Notlösung -- die AUTORITATIVE
	 * content_id (SKU-oder-ID je nach sc_content_id_type) löst ausschließlich
	 * der Server für die Meta-CAPI auf (siehe
	 * PMS_Pro_SureCart::build_checkout_custom_data()); dieses Payload dient
	 * NUR den Browser-Pixel-Aufrufen.
	 */
	function buildPayloadFromLineItems( checkout, lineItems ) {
		var contentIds = [];
		var contents = [];

		( lineItems || [] ).forEach( function ( item ) {
			var priceId = item.price && 'object' === typeof item.price ? item.price.id : item.price;
			var qty = item.quantity || 1;
			var itemPrice = 'number' === typeof item.total_amount
				? round2( item.total_amount / minorUnitDivisor() / qty )
				: 0;
			var id = String( priceId || '' );

			contentIds.push( id );
			contents.push( { id: id, quantity: qty, item_price: itemPrice } );
		} );

		var value = checkout && 'number' === typeof checkout.total_amount
			? round2( checkout.total_amount / minorUnitDivisor() )
			: 0;
		var currency = checkout && checkout.currency ? String( checkout.currency ).toUpperCase() : '';

		return {
			content_ids: contentIds,
			content_type: 'product',
			value: value,
			currency: currency,
			contents: contents,
			num_items: contents.reduce( function ( sum, c ) {
				return sum + c.quantity;
			}, 0 )
		};
	}

	function tiktokContentsPayload( payload ) {
		return {
			content_type: 'product',
			contents: payload.contents.map( function ( item ) {
				return { content_id: item.id, quantity: item.quantity, price: item.item_price };
			} ),
			value: payload.value,
			currency: payload.currency
		};
	}

	/* -----------------------------------------------------------------
	 * AddToCart
	 * ------------------------------------------------------------------- */

	function fireAddToCart( lineItem ) {
		var priceId = lineItem.price && 'object' === typeof lineItem.price ? lineItem.price.id : lineItem.price;
		var qty = lineItem.quantity || 1;
		var id = String( priceId || '' );

		if ( '' === id ) {
			return;
		}

		// Bewusst kein value/currency (siehe assets/pms-woocommerce.js für
		// dasselbe Muster bei den WooCommerce-Archiv-AJAX-Buttons): die hier
		// beobachtete LineItem-Antwort trägt zwar total_amount, aber ohne
		// zusätzlichen Price-Lookup ist die Währung nicht sicher bekannt --
		// der Server löst für die Meta-CAPI ohnehin frisch über price_id auf
		// (siehe class-pro-surecart.php::resolve_custom_data()).
		sendTracking( 'AddToCart', { price_id: id, quantity: qty }, {
			meta: {
				content_ids: [ id ],
				content_type: 'product'
			},
			google: {
				event: 'add_to_cart',
				params: {
					items: [ { item_id: id, quantity: qty } ]
				}
			},
			tiktok: {
				event: 'AddToCart',
				params: {
					content_id: id,
					content_type: 'product'
				}
			}
		} );
	}

	/* -----------------------------------------------------------------
	 * InitiateCheckout
	 * ------------------------------------------------------------------- */

	function fireInitiateCheckout( checkout, lineItems ) {
		var payload = buildPayloadFromLineItems( checkout, lineItems );

		sendTracking( 'InitiateCheckout', { checkout_id: checkout.id }, {
			meta: payload,
			google: {
				event: 'begin_checkout',
				params: {
					currency: payload.currency,
					value: payload.value,
					items: contentsToGoogleItems( payload.contents )
				}
			},
			tiktok: {
				event: 'InitiateCheckout',
				params: {
					content_type: 'product',
					value: payload.value,
					currency: payload.currency
				}
			}
		} );
	}

	/* -----------------------------------------------------------------
	 * Purchase -- Browser-Pixel only (Meta-CAPI/TikTok-Events-API laufen
	 * unabhängig davon serverseitig über PMS_Pro_SureCart_Purchase, siehe
	 * dortige Klassen-Doku). Kein AJAX-Roundtrip zu pms_surecart_track
	 * nötig, NUR ggf. ein separater, eigens genonce'ter Request für gehashte
	 * Google-Enhanced-Conversions-Felder (siehe fireGoogleConversion()).
	 * ------------------------------------------------------------------- */

	function firePurchase( checkout, lineItems ) {
		var eventId = 'pms_sc_order_' + checkout.id;
		var payload = buildPayloadFromLineItems( checkout, lineItems );

		waitForPmsInit( function () {
			if ( 'function' === typeof window.fbq ) {
				window.fbq( 'track', 'Purchase', payload, { eventID: eventId } );
			}

			if ( cfg.googleEnabled && 'function' === typeof window.gtag ) {
				fireGoogleConversion( checkout, payload, eventId );
				fireGA4Purchase( payload, eventId );
			}

			if ( cfg.tiktokEnabled && 'function' === typeof window.ttq ) {
				window.ttq.track( 'CompletePayment', tiktokContentsPayload( payload ), { event_id: eventId } );
			}

			emit( 'pms:event', { event: 'Purchase', eventId: eventId, browser: 'fired', capi: 'server' } );
		} );
	}

	/**
	 * Google Ads Purchase-Conversion. Kein konfiguriertes Label -> kein
	 * Aufruf, dieselbe Regel wie serverseitig
	 * (PMS_Pro_Woo_Purchase::google_conversion_js()). Holt bei aktivierter
	 * sc_purchase_advanced_matching-Einstellung vorab gehashte user_data
	 * nach (siehe PMS_Pro_SureCart_Purchase::handle_purchase_matching_ajax()
	 * für die ausführliche Begründung, warum das nicht serverseitig
	 * vorgerendert werden kann) -- feuert den Conversion-Aufruf aber auch,
	 * wenn dieser Zusatz-Request fehlschlägt (ein Event ohne
	 * Enhanced-Conversions-Daten ist besser als gar keins).
	 */
	function fireGoogleConversion( checkout, payload, eventId ) {
		if ( ! cfg.googleTagId || ! cfg.scGoogleConversionLabel ) {
			return;
		}

		var params = {
			send_to: cfg.googleTagId + '/' + cfg.scGoogleConversionLabel,
			value: payload.value,
			currency: payload.currency,
			transaction_id: eventId
		};

		function fireWithParams() {
			window.gtag( 'event', 'conversion', params );
		}

		if ( ! cfg.scGoogleAdvancedMatching || ! cfg.purchaseNonce || ! cfg.ajaxUrl || ! window.fetch ) {
			fireWithParams();
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'pms_surecart_purchase_matching' );
		body.append( 'nonce', cfg.purchaseNonce );
		body.append( 'checkout_id', checkout.id );

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( result ) {
			if ( result && result.success && result.data && result.data.user_data ) {
				params.user_data = result.data.user_data;
			}
			fireWithParams();
		} ).catch( function () {
			fireWithParams();
		} );
	}

	/**
	 * GA4-Purchase (Standard-Ecommerce-Event "purchase"), seit v0.6.8 --
	 * unabhängig von fireGoogleConversion() oben: KEIN send_to, deshalb ohne
	 * Google-Ads-Conversion-Label auskommend (GA4 kennt dieses Konzept nicht,
	 * siehe PMS_Frontend::build_google_js() für dieselbe Unterscheidung
	 * serverseitig). transaction_id = dieselbe deterministische Event-ID wie
	 * bei Meta/TikTok/Google Ads (eventId, "pms_sc_order_{Checkout-ID}") --
	 * GA4 dedupliziert purchase-Events serverseitig anhand von
	 * transaction_id, exakt derselbe Zweck wie Metas eventID.
	 *
	 * @param {Object} payload Meta-Payload aus buildPayloadFromLineItems() (value/currency/contents).
	 * @param {string} eventId Deterministische Event-ID.
	 */
	function fireGA4Purchase( payload, eventId ) {
		if ( ! cfg.ga4MeasurementId ) {
			return;
		}

		window.gtag( 'event', 'purchase', {
			transaction_id: eventId,
			value: payload.value,
			currency: payload.currency,
			items: contentsToGoogleItems( payload.contents )
		} );
	}

	/* -----------------------------------------------------------------
	 * fetch()-Beobachtung -- siehe Datei-Kommentar oben für die
	 * ausführliche Begründung. Rein lesend (response.clone()), verändert
	 * nie den tatsächlichen Request/die tatsächliche Response.
	 * ------------------------------------------------------------------- */

	function observeResponse( body, method ) {
		if ( isCheckoutShape( body ) ) {
			rememberCheckout( body );
			return;
		}

		var items = extractLineItemList( body );
		if ( items ) {
			var byCheckout = {};
			items.forEach( function ( item ) {
				var cid = lineItemCheckoutId( item );
				if ( ! cid ) {
					return;
				}
				byCheckout[ cid ] = byCheckout[ cid ] || [];
				byCheckout[ cid ].push( item );
			} );
			Object.keys( byCheckout ).forEach( function ( cid ) {
				rememberLineItems( cid, byCheckout[ cid ] );
			} );
			return;
		}

		if ( 'POST' === method && isLineItemObject( body ) ) {
			fireAddToCart( body );
		}
	}

	function installFetchObserver() {
		if ( ! window.fetch || window.fetch.__pmsWrapped ) {
			return;
		}

		var originalFetch = window.fetch;

		var wrapped = function ( input, init ) {
			var promise = originalFetch.apply( window, arguments );

			try {
				var url = '';
				if ( 'string' === typeof input ) {
					url = input;
				} else if ( input && input.url ) {
					url = input.url;
				}
				var method = ( init && init.method ) || ( input && input.method ) || 'GET';

				if ( url.toLowerCase().indexOf( 'surecart' ) !== -1 ) {
					promise.then( function ( response ) {
						try {
							response.clone().json().then( function ( respBody ) {
								try {
									observeResponse( respBody, String( method ).toUpperCase() );
								} catch ( e ) {
									/* Beobachtung darf den echten Request nie stören. */
								}
							} ).catch( function () {
								/* Antwort ist kein JSON -- nicht relevant für diese Beobachtung. */
							} );
						} catch ( e ) {
							/* response.clone() nicht verfügbar/fehlgeschlagen -- ignorieren. */
						}
					} ).catch( function () {
						/* Fehlgeschlagener Request -- nichts zu beobachten. */
					} );
				}
			} catch ( e ) {
				/* Nie den echten fetch()-Aufruf beeinträchtigen. */
			}

			return promise;
		};
		wrapped.__pmsWrapped = true;

		window.fetch = wrapped;
	}

	/* -----------------------------------------------------------------
	 * Einsprungpunkt
	 * ------------------------------------------------------------------- */

	function init() {
		initViewContent();
		installFetchObserver();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
