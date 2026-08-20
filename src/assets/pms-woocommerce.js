/**
 * Pixel Made Simple – WooCommerce-Tracking (ViewContent, AddToCart,
 * InitiateCheckout). Pro-only, siehe pro/class-pro-woo.php.
 *
 * Seit v0.6.6 Multi-Platform: Meta (Pixel + CAPI, wie zuvor), Google Ads
 * (gtag.js: view_item/add_to_cart/begin_checkout) und TikTok Pixel (ttq.track:
 * ViewContent/AddToCart/InitiateCheckout) -- Google/TikTok NUR Browser-seitig
 * für diese drei Events (kein Server-Dispatch, anders als Purchase, siehe
 * pro/class-pro-woo-purchase.php). gtag/ttq werden NICHT von diesem Skript
 * geladen -- class-pms-frontend.php lädt beide bereits auf jeder Seite, wenn
 * google_enabled/tiktok_enabled aktiv sind; hier wird nur geprüft, ob sie
 * existieren UND das jeweilige *Enabled-Flag gesetzt ist (siehe cfg.googleEnabled/
 * cfg.tiktokEnabled, lokalisiert in class-pro-woo.php).
 *
 * Cache-Sicherheit: ViewContent- und AddToCart-Nutzlasten enthalten NIE eine
 * serverseitig gebackene event_id (Cache-Gift auf vollständig gecachten
 * Produkt-/Archivseiten). Die event_id entsteht ausschließlich hier im
 * Browser (crypto.randomUUID()) und wird synchron für fbq()/ttq.track() UND
 * den asynchronen Meta-CAPI-Request wiederverwendet, damit Meta (und TikTok,
 * bei Purchase) Browser- und Server-Event dedupliziert.
 *
 * Consent-Queue: Ist beim Laden noch keine Marketing-Einwilligung erteilt
 * (Cookie-Banner blockiert noch), werden Events zwischengespeichert und erst
 * beim nächsten erkannten Consent-Event (dieselbe Liste wie der Consent-
 * Bootstrap in class-pms-frontend.php) tatsächlich gefeuert.
 *
 * Vanilla JS, keine Abhängigkeiten (jQuery wird nur für das WooCommerce-
 * eigene "added_to_cart"-Event genutzt, das WooCommerce-Core ohnehin per
 * jQuery auslöst).
 */
( function () {
	'use strict';

	var cfg = window.pms_woo_settings || {};

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

	/**
	 * contents[] (gemeinsames {id,quantity,item_price}-Format von Classic- und
	 * Block-Checkout, siehe initClassicCheckout()/initBlockCheckout()) in
	 * Google Ads' items[]-Schema übersetzen ({item_id,price,quantity}).
	 */
	function contentsToGoogleItems( contents ) {
		return ( contents || [] ).map( function ( item ) {
			return { item_id: String( item.id ), price: item.item_price || 0, quantity: item.quantity || 1 };
		} );
	}

	/* -----------------------------------------------------------------
	 * Consent-Queue
	 * ------------------------------------------------------------------- */

	var pendingQueue = [];

	function hasConsent() {
		if ( 'function' === typeof window.pmsHasConsent ) {
			return window.pmsHasConsent();
		}
		return true; // Kein Bootstrap aktiv -> der Server hat Consent bereits vorausgesetzt.
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

	/* -----------------------------------------------------------------
	 * Gemeinsamer Dispatch: Browser-Pixel(e) + asynchrone Meta-CAPI,
	 * identische event_id für alle Ziele (siehe Datei-Kommentar oben).
	 * Google Ads/TikTok sind seit v0.6.6 zusätzliche, unabhängige Ziele --
	 * jedes Ziel prüft sein eigenes *Enabled-Flag UND die tatsächliche
	 * Funktionsexistenz (fbq/gtag/ttq), bevor es feuert; das Fehlen eines
	 * Ziels blockiert die anderen nicht.
	 * ------------------------------------------------------------------- */

	/**
	 * @param {string}      eventName  'ViewContent' | 'AddToCart' | 'InitiateCheckout'
	 *                                 (Meta-Namenskonvention -- Google/TikTok
	 *                                 haben pro Aufrufer ihre eigenen Namen,
	 *                                 siehe platforms.google.event/platforms.tiktok.event).
	 * @param {Object} ajaxFields      Zusätzliche Felder für den AJAX-Request
	 *                                 (z. B. product_id/variation_id/quantity).
	 *                                 Der Server löst Preis/Name/Kategorie
	 *                                 IMMER selbst neu auf, siehe class-pro-woo.php.
	 *                                 Nur für die Meta-CAPI relevant -- Google/
	 *                                 TikTok haben für diese drei Events (anders
	 *                                 als Purchase) keinen Server-Dispatch.
	 * @param {Object} platforms       { meta: Object|null, google: {event,params}|null, tiktok: {event,params}|null }.
	 */
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
			body.append( 'action', 'pms_woo_track' );
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
	 * ViewContent (Single Product Page)
	 * ------------------------------------------------------------------- */

	function initViewContent() {
		var el = document.getElementById( 'pms-woo-view-content-data' );
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
	 * AddToCart (AJAX-Archiv-/Mini-Cart-Buttons + Single-Product-Forms)
	 * ------------------------------------------------------------------- */

	var ATC_LOCK_MS = 3000;

	function fireAddToCart( productId, variationId, qty ) {
		if ( ! productId ) {
			return;
		}

		// Bewusst KEIN Preis/Name in KEINEM der Browser-Pixel-Aufrufe: Anders
		// als ViewContent/InitiateCheckout ist hier nichts sicher serverseitig
		// vorgerendert (Archiv-Button kennt nur product_id/quantity aus den
		// data-Attributen von WooCommerce-Core). Der Server löst Preis/Name
		// für die Meta-CAPI trotzdem selbst auf (siehe class-pro-woo.php);
		// Google/TikTok haben für dieses Event keinen Server-Dispatch (siehe
		// sendTracking()-Doku oben), bekommen also dauerhaft nur die schlanke
		// Variante ohne value/currency.
		sendTracking( 'AddToCart', {
			product_id: productId,
			variation_id: variationId || 0,
			quantity: qty || 1
		}, {
			meta: {
				content_ids: [ String( productId ) ],
				content_type: 'product'
			},
			google: {
				event: 'add_to_cart',
				params: {
					items: [ { item_id: String( productId ), quantity: qty || 1 } ]
				}
			},
			tiktok: {
				event: 'AddToCart',
				params: {
					content_id: String( productId ),
					content_type: 'product'
				}
			}
		} );
	}

	function initAddToCart() {
		// 1. AJAX-Buttons: Archiv-/Shop-Loop, verwandte Produkte, Mini-Cart-
		//    Quick-Adds. WooCommerce-Core löst dieses jQuery-Event nach jedem
		//    erfolgreichen AJAX-Add aus, unabhängig davon, wo der Button sitzt.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart', function ( event, fragments, cartHash, $button ) {
				var button = $button && $button[ 0 ] ? $button[ 0 ] : null;
				if ( ! button ) {
					return;
				}
				var productId = parseInt( button.getAttribute( 'data-product_id' ) || '0', 10 );
				var qty = parseInt( button.getAttribute( 'data-quantity' ) || '1', 10 );
				fireAddToCart( productId, 0, qty );
			} );
		}

		// 2. Single-Product-Formulare (form.cart), inkl. Variable Products.
		//    Läuft beim Absenden mit, OHNE die Navigation zu blockieren (kein
		//    preventDefault) -- der klassische Add-to-Cart-Ablauf ist ein
		//    normaler POST mit Redirect, kein AJAX-Call.
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;

			if ( ! form || 'FORM' !== form.nodeName || ! form.classList || ! form.classList.contains( 'cart' ) ) {
				return;
			}

			if ( form.dataset && form.dataset.pmsWooAtcLock ) {
				return;
			}
			if ( form.dataset ) {
				form.dataset.pmsWooAtcLock = '1';
				setTimeout( function () {
					try {
						delete form.dataset.pmsWooAtcLock;
					} catch ( e ) {
						form.removeAttribute( 'data-pms-woo-atc-lock' );
					}
				}, ATC_LOCK_MS );
			}

			var addButton = form.querySelector( 'button[name="add-to-cart"], input[name="add-to-cart"]' );
			var productId = parseInt( ( addButton && addButton.value ) || '0', 10 );
			var variationInput = form.querySelector( 'input[name="variation_id"]' );
			var variationId = variationInput ? parseInt( variationInput.value || '0', 10 ) : 0;
			var qtyInput = form.querySelector( 'input[name="quantity"]' );
			var qty = qtyInput ? parseInt( qtyInput.value || '1', 10 ) : 1;

			fireAddToCart( productId, variationId, qty );
		}, true );
	}

	/* -----------------------------------------------------------------
	 * InitiateCheckout (Classic via PHP-Nutzlast, Block via Store API)
	 * ------------------------------------------------------------------- */

	function initClassicCheckout() {
		var el = document.getElementById( 'pms-woo-checkout-data' );
		if ( ! el ) {
			return false;
		}

		var data = safeParseJSON( el.textContent );
		if ( ! data || ! data.content_ids || ! data.content_ids.length ) {
			return true; // Checkout-Seite erkannt, aber ohne auswertbare Nutzlast -- Block-Fallback wäre hier ebenfalls sinnlos.
		}

		sendTracking( 'InitiateCheckout', {}, {
			meta: {
				content_ids: data.content_ids,
				content_type: 'product',
				value: data.value || 0,
				currency: data.currency || '',
				contents: data.contents || [],
				num_items: data.num_items || 0
			},
			google: {
				event: 'begin_checkout',
				params: {
					currency: data.currency || '',
					value: data.value || 0,
					items: contentsToGoogleItems( data.contents )
				}
			},
			tiktok: {
				event: 'InitiateCheckout',
				params: {
					content_type: 'product',
					value: data.value || 0,
					currency: data.currency || ''
				}
			}
		} );

		return true;
	}

	/**
	 * Block-Checkout: kein PHP-Template-Hook verfügbar, daher direkte Abfrage
	 * des aktuellen Warenkorbs über die öffentliche WooCommerce Store API
	 * (dieselbe Sitzung, die auch der Block-Checkout selbst nutzt). Preise
	 * liegen dort in Minor-Units vor (z. B. Cent) und werden per
	 * currency_minor_unit zurückgerechnet.
	 */
	function initBlockCheckout() {
		if ( ! document.querySelector( '.wp-block-woocommerce-checkout' ) ) {
			return;
		}
		if ( ! cfg.storeCartUrl || ! window.fetch ) {
			return;
		}

		window.fetch( cfg.storeCartUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( cart ) {
				var items = ( cart && cart.items ) || [];
				if ( ! items.length ) {
					return;
				}

				var minorUnit = ( cart.totals && cart.totals.currency_minor_unit ) || 2;
				var divisor = Math.pow( 10, minorUnit );
				var currency = ( cart.totals && cart.totals.currency_code ) || '';
				var contentIds = [];
				var contents = [];
				var numItems = 0;

				items.forEach( function ( item ) {
					var id = String( item.id || '' );
					var qty = parseInt( item.quantity, 10 ) || 1;
					var price = ( item.prices && item.prices.price ) ? ( parseInt( item.prices.price, 10 ) / divisor ) : 0;

					contentIds.push( id );
					contents.push( { id: id, quantity: qty, item_price: price } );
					numItems += qty;
				} );

				var value = ( cart.totals && cart.totals.total_price ) ? ( parseInt( cart.totals.total_price, 10 ) / divisor ) : 0;

				sendTracking( 'InitiateCheckout', {}, {
					meta: {
						content_ids: contentIds,
						content_type: 'product',
						value: value,
						currency: currency,
						contents: contents,
						num_items: numItems
					},
					google: {
						event: 'begin_checkout',
						params: {
							currency: currency,
							value: value,
							items: contentsToGoogleItems( contents )
						}
					},
					tiktok: {
						event: 'InitiateCheckout',
						params: {
							content_type: 'product',
							value: value,
							currency: currency
						}
					}
				} );
			} )
			.catch( function () {
				/* Store API nicht erreichbar -- kein Tracking für diesen Aufruf. */
			} );
	}

	/* -----------------------------------------------------------------
	 * Einsprungpunkt
	 * ------------------------------------------------------------------- */

	function init() {
		initViewContent();
		initAddToCart();

		if ( ! initClassicCheckout() ) {
			initBlockCheckout();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
