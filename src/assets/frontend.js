/**
 * Pixel Made Simple – Formular-Auto-Grabber & UTM-Form-Fill.
 *
 * Zwei unabhängige Features (eigene Master-Toggles, siehe class-pms-frontend.php):
 * 1. Formular-Auto-Grabber: erkennt Formular-Absendungen (native und
 *    AJAX-basierte Formular-Plugins), feuert das Browser-Event "Lead" mit
 *    Event-ID und meldet dieselbe ID plus Kontaktdaten an das Plugin-Backend,
 *    das den Server-Event via CAPI sendet.
 * 2. UTM-Form-Fill: schreibt Source/Campaign/Medium in passende Formularfelder,
 *    rein clientseitig, kein Netzwerk-Call.
 *
 * Vanilla JS, keine Abhängigkeiten (jQuery wird nur genutzt, wenn ohnehin
 * vorhanden – manche Formular-Plugins senden ihre Erfolgs-Events darüber).
 */
( function () {
	'use strict';

	var cfg = window.pms_settings || {};

	// Zwei unabhängige Features teilen sich diese Datei (siehe unten): der
	// Formular-Auto-Grabber (cfg.formTracking) und der UTM-Form-Fill
	// (cfg.utmFormFill). Ist keines von beiden aktiv, gibt es nichts zu tun.
	if ( ! cfg.formTracking && ! cfg.utmFormFill ) {
		return;
	}

	// Duplicate-Guard: Eine Formularinteraktion darf exakt EIN Event erzeugen.
	// AJAX-Formulare (z. B. SureForms) feuern den nativen submit UND später
	// einen Completion-Handler – je nach Serverlaufzeit mehrere Sekunden
	// auseinander. Deshalb 5 s statt der früheren 2 s.
	var LOCK_MS = 5000;
	var lastFired = 0;
	var lastSubmit = null; // { data, time, form } der letzten Absendung.

	/**
	 * Ist für dieses Formular bereits ein Event erzeugt worden?
	 */
	function isLocked( form ) {
		return !! ( form && form.dataset && 'true' === form.dataset.pmsSubmitted );
	}

	/**
	 * Formular für LOCK_MS sperren, damit Submit und AJAX-Completion zusammen
	 * nur ein einziges Event mit einer einzigen Event-ID erzeugen.
	 */
	function lockForm( form ) {
		if ( ! form || ! form.dataset ) {
			return;
		}

		form.dataset.pmsSubmitted = 'true';

		setTimeout( function () {
			try {
				delete form.dataset.pmsSubmitted;
			} catch ( e ) {
				form.removeAttribute( 'data-pms-submitted' );
			}
		}, LOCK_MS );
	}

	// Konfigurierter Meta-Event-Typ (Lead oder Contact).
	var EVENT_NAME = ( 'Contact' === cfg.eventType ) ? 'Contact' : 'Lead';

	// Multi-Platform-Formular-Leads (seit v0.6.10): dieselbe Absendung feuert
	// zusätzlich ein TikTok-Web-Event und -- sofern ein Conversion-Label
	// konfiguriert ist -- eine Google-Ads-Conversion. Beide rein
	// browserseitig, exakt wie bei den URL-Events (PMS_Frontend::
	// build_google_js()/build_tiktok_js()); der AJAX-Roundtrip unten betrifft
	// weiterhin ausschließlich die Meta-CAPI. Leere Werte = Plattform in den
	// Einstellungen nicht aktiv/nicht konfiguriert (siehe
	// PMS_Frontend::enqueue_frontend()), dann wird nichts gefeuert.
	var TIKTOK_EVENT = String( cfg.tiktokEvent || '' );
	var GOOGLE_TAG_ID = String( cfg.googleTagId || '' );
	var GOOGLE_LABEL = String( cfg.googleLabel || '' );

	// Optionaler URL-Filter: leer = gesamte Website.
	var URL_FILTERS = Array.isArray( cfg.urlFilter ) ? cfg.urlFilter : [];

	/**
	 * Läuft der Grabber auf dieser Seite? (Der Server prüft das ebenfalls und
	 * liefert das Skript dann gar nicht erst aus – dies ist die Absicherung
	 * für Seitenwechsel ohne Reload.)
	 */
	function urlAllowed() {
		if ( ! URL_FILTERS.length ) {
			return true;
		}

		var path = ( window.location.pathname || '' ).toLowerCase();

		for ( var i = 0; i < URL_FILTERS.length; i++ ) {
			var needle = String( URL_FILTERS[ i ] || '' ).toLowerCase();
			if ( needle && path.indexOf( needle ) > -1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Such-, Kommentar- und Login-Formulare aussortieren.
	 * Passwortfelder werden immer ignoriert – Anmeldedaten sind niemals ein Lead.
	 */
	function isExcludedForm( form ) {
		if ( form.querySelector( 'input[type="password"]' ) ) {
			return true;
		}

		if ( false === cfg.excludeSystem ) {
			return false;
		}

		if ( form.matches( '.search-form, #searchform, form[role="search"], .wp-block-search__button-inside, #commentform, .comment-form, .wp-block-post-comments-form form, #loginform' ) ) {
			return true;
		}

		// WordPress-Suchfeld (name="s") und Kommentarfelder.
		return !! form.querySelector( 'input[name="s"], textarea[name="comment"]' );
	}

	/**
	 * Marketing-Consent. Ist der Inline-Bootstrap aktiv, nutzen wir dessen
	 * Prüfung; andernfalls hat der Server bereits Consent festgestellt.
	 */
	function hasConsent() {
		if ( 'function' === typeof window.pmsHasConsent ) {
			return window.pmsHasConsent();
		}
		return true;
	}

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

	var EMAIL_RE = /(^|[_\-\[])(e-?mail|mail)([_\-\]]|$)/i;
	var PHONE_RE = /(^|[_\-\[])(phone|tel|telefon|mobile?|handy|rufnummer)([_\-\]]|$)/i;

	function looksLike( field, regex ) {
		return regex.test( field.name || '' ) ||
			regex.test( field.id || '' ) ||
			regex.test( field.getAttribute( 'placeholder' ) || '' ) ||
			regex.test( field.getAttribute( 'autocomplete' ) || '' );
	}

	/**
	 * E-Mail und Telefonnummer aus einem Formular auslesen.
	 */
	function readForm( form ) {
		var data = { email: '', phone: '' };

		if ( ! form || ! form.querySelectorAll ) {
			return data;
		}

		var fields = form.querySelectorAll( 'input, textarea' );

		for ( var i = 0; i < fields.length; i++ ) {
			var field = fields[ i ];
			var value = ( field.value || '' ).trim();

			if ( ! value || field.disabled ) {
				continue;
			}

			var type = ( field.type || '' ).toLowerCase();

			if ( 'password' === type || 'hidden' === type ) {
				continue;
			}

			if ( ! data.email && ( 'email' === type || looksLike( field, EMAIL_RE ) || value.indexOf( '@' ) > 0 ) ) {
				data.email = value;
				continue;
			}

			if ( ! data.phone && ( 'tel' === type || looksLike( field, PHONE_RE ) ) ) {
				data.phone = value;
			}
		}

		return data;
	}

	/**
	 * Kontaktdaten aus der CF7-Event-Nutzlast lesen (CF7 leert das Formular
	 * unmittelbar nach dem Absenden).
	 */
	function readCf7Inputs( inputs ) {
		var data = { email: '', phone: '' };

		if ( ! inputs || ! inputs.length ) {
			return data;
		}

		for ( var i = 0; i < inputs.length; i++ ) {
			var name = inputs[ i ].name || '';
			var value = ( inputs[ i ].value || '' ).toString().trim();

			if ( ! value ) {
				continue;
			}
			if ( ! data.email && ( EMAIL_RE.test( name ) || value.indexOf( '@' ) > 0 ) ) {
				data.email = value;
			} else if ( ! data.phone && PHONE_RE.test( name ) ) {
				data.phone = value;
			}
		}

		return data;
	}

	/**
	 * Gehört das Formular zu einem Plugin, das per AJAX absendet? Dann warten
	 * wir auf dessen Erfolgs-Event, statt schon beim Absenden zu zählen.
	 */
	function isAjaxForm( form ) {
		if ( ! form || ! form.closest ) {
			return false;
		}
		return !! form.closest(
			'.wpcf7, .wpcf7-form, .elementor-form, .fluent_form, [class*="fluentform"], .wpforms-form, .gform_wrapper, form[data-wpforms-form]'
		);
	}

	/**
	 * Lead-Event auslösen: Browser-Pixel + serverseitige CAPI mit identischer ID.
	 */
	function fireLead( data, sourceLabel ) {
		var now = Date.now();

		if ( now - lastFired < LOCK_MS ) {
			return; // Globaler Guard für Erfolgs-Events ohne Formular-Referenz.
		}

		if ( ! urlAllowed() ) {
			return; // Seite steht nicht im URL-Filter.
		}

		// Flexibler Consent-Modus (seit v0.6.10, siehe
		// PMS_Consent::has_server_consent()): ohne Einwilligung bleibt der
		// Browser-Pixel aus, der CAPI-Request läuft aber weiter. Im
		// Default-Modus 'strict' bleibt es beim bisherigen Komplett-Abbruch.
		var pixelAllowed = hasConsent();

		if ( ! pixelAllowed && 'browser_only' !== cfg.consentMode ) {
			emit( 'pms:event', {
				event: EVENT_NAME,
				browser: 'blocked',
				capi: 'consent_blocked',
				source: sourceLabel
			} );
			return;
		}

		lastFired = now;

		var eventId = uuid();
		var browserFired = false;
		// Seit v0.6.11: welche Ziele tatsächlich gefeuert haben. Wird an die
		// Live-Debug-Leiste gemeldet (pms:event) UND an den Server, damit die
		// rein browserseitige Google-Ads-Conversion im Event Log auftaucht
		// (siehe PMS_Forms::handle_lead()).
		var fired = [];
		var googleFired = false;

		if ( pixelAllowed && 'function' === typeof window.fbq ) {
			// KEIN test_event_code hier (Bugfix v0.5.7): Meta's Pixel-SDK
			// akzeptiert es nicht als custom_data-Feld und ignoriert das Event
			// im Test-Stream. Der Test-Code bleibt CAPI-only (class-pms-capi.php).
			window.fbq( 'track', EVENT_NAME, {}, { eventID: eventId } );
			browserFired = true;
			fired.push( 'Meta' );
		}

		// Google Ads: nur mit konfiguriertem Conversion-Label (dieselbe Regel
		// wie bei URL-Events, siehe PMS_Settings::sanitize_event()).
		if ( pixelAllowed && GOOGLE_TAG_ID && GOOGLE_LABEL && 'function' === typeof window.gtag ) {
			window.gtag( 'event', 'conversion', { send_to: GOOGLE_TAG_ID + '/' + GOOGLE_LABEL } );
			googleFired = true;
			fired.push( 'Google Ads' );
		}

		// TikTok: dieselbe event_id wie Meta -- TikToks Events API sendet für
		// Formular-Leads zwar (noch) nichts serverseitig, die ID bleibt
		// trotzdem konsistent zu allen anderen Zielen dieses Events.
		if ( pixelAllowed && TIKTOK_EVENT && 'function' === typeof window.ttq ) {
			window.ttq.track( TIKTOK_EVENT, {}, { event_id: eventId } );
			fired.push( 'TikTok' );
		}

		emit( 'pms:event', {
			event: EVENT_NAME,
			eventId: eventId,
			browser: browserFired ? 'fired' : ( pixelAllowed ? 'no_pixel' : 'blocked' ),
			capi: 'pending',
			source: sourceLabel,
			platforms: fired,
			hasEmail: !! data.email,
			hasPhone: !! data.phone
		} );

		if ( ! window.fetch || ! cfg.ajaxUrl ) {
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'pms_form_lead' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'event_id', eventId );
		body.append( 'event_name', EVENT_NAME );
		body.append( 'source_url', window.location.href );
		// Event Log (v0.6.1): server-seitig gibt es sonst kein Signal, ob der
		// Browser-Pixel tatsächlich gefeuert hat (window.fbq könnte fehlen,
		// z. B. weil die Meta-Plattform selbst deaktiviert ist).
		body.append( 'browser_fired', browserFired ? '1' : '0' );
		// Nur die Google-Ads-Conversion braucht der Server: Meta deckt
		// browser_fired ab, und für TikTok gibt es bei Formular-Leads keinen
		// Server-Pfad (siehe CLAUDE.md, "Bekannte Trade-offs").
		body.append( 'google_fired', googleFired ? '1' : '0' );

		if ( data.email ) {
			body.append( 'email', data.email );
		}
		if ( data.phone ) {
			body.append( 'phone', data.phone );
		}

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true, // Überlebt den Seitenwechsel bei nativen Formularen.
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

	/**
	 * Zentrale Einsprungstelle: sperrt das Formular und löst genau ein Event aus.
	 *
	 * @param {HTMLFormElement|null} form        Formular (falls bekannt).
	 * @param {string}               sourceLabel Auslöser für die Debug-Leiste.
	 * @param {Object|null}          data        Bereits gelesene Kontaktdaten.
	 */
	function handleFormSubmit( form, sourceLabel, data ) {
		if ( ! cfg.formTracking ) {
			return; // Nur UTM-Form-Fill aktiv – der Auto-Grabber selbst ist aus.
		}

		if ( isLocked( form ) ) {
			return; // Bereits gefeuert -> Abbruch.
		}

		lockForm( form );

		fireLead( data || readForm( form ), sourceLabel );
	}

	/**
	 * Erfolgs-Event eines Formular-Plugins: zwischengespeicherte Werte nutzen.
	 * Ohne Formular-Referenz greifen wir auf die letzte Absendung zurück –
	 * so wirkt der Lock auch für Plugins, die ihre Events über jQuery auf
	 * document auslösen.
	 */
	function handleSuccess( label, fallbackData, form ) {
		var data = fallbackData;
		var target = form;

		if ( ( ! data || ( ! data.email && ! data.phone ) ) && lastSubmit && Date.now() - lastSubmit.time < 60000 ) {
			data = lastSubmit.data;
			if ( ! target ) {
				target = lastSubmit.form;
			}
		}

		handleFormSubmit( target || null, label, data || { email: '', phone: '' } );
	}

	// 1. Native Formular-Absendungen (Capture-Phase, damit auch Plugins erfasst
	//    werden, die den Submit per preventDefault abfangen).
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || 'FORM' !== form.nodeName ) {
			return;
		}

		if ( isExcludedForm( form ) ) {
			return;
		}

		var data = readForm( form );
		lastSubmit = { data: data, time: Date.now(), form: form };

		// Bekannte AJAX-Formulare: erst beim Erfolgs-Event des Plugins zählen.
		// Hier bewusst KEIN Lock setzen, sonst würde das Erfolgs-Event blockiert.
		if ( isAjaxForm( form ) ) {
			return;
		}

		handleFormSubmit( form, 'form-submit', data );
	}, true );

	// 2. Contact Form 7 (natives CustomEvent inkl. Feldwerten).
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var detail = event.detail || {};
		var form = event.target && event.target.querySelector ? event.target.querySelector( 'form' ) : null;
		handleSuccess( 'cf7', readCf7Inputs( detail.inputs ), form );
	} );

	// 3. Weitere native Erfolgs-Events (u. a. SureForms und Block-Formulare).
	[
		'fluentform_submission_success',
		'wpformsAjaxSubmitSuccess',
		'gform_confirmation_loaded',
		'srfm_form_submission_success',
		'sureforms_form_submission_success'
	].forEach( function ( name ) {
		document.addEventListener( name, function ( event ) {
			var form = event && event.target && 'FORM' === event.target.nodeName ? event.target : null;
			handleSuccess( name, null, form );
		} );
	} );

	// 4. Plugins, die ihre Erfolgs-Events über jQuery auslösen.
	if ( window.jQuery ) {
		var $ = window.jQuery;

		$( document ).on( 'fluentform_submission_success', function () {
			handleSuccess( 'fluentforms', null, null );
		} );

		$( document ).on( 'wpformsAjaxSubmitSuccess', function ( event ) {
			var form = event.target && 'FORM' === event.target.nodeName ? event.target : null;
			handleSuccess( 'wpforms', readForm( event.target ), form );
		} );

		$( document ).on( 'gform_confirmation_loaded', function () {
			handleSuccess( 'gravityforms', null, null );
		} );

		// Elementor Pro Formulare.
		$( document ).on( 'submit_success', function ( event ) {
			var form = event.target && 'FORM' === event.target.nodeName ? event.target : null;
			handleSuccess( 'elementor', readForm( event.target ), form );
		} );

		$( window ).on( 'elementor/popup/show', function () {
			/* Nur zur Kompatibilität registriert – Popups selbst sind kein Lead. */
		} );
	}

	/* -----------------------------------------------------------------
	 * UTM-/Attribution-Form-Fill: unabhängig vom Formular-Auto-Grabber
	 * oben. Schreibt die 3 Kernfelder – Source, Campaign, Medium – in
	 * passende Formularfelder, bevor der Besucher absendet – rein
	 * clientseitig, kein Netzwerk-Call. Dieselbe Doku (Feldnamen/-klassen)
	 * steht im Admin unter Tab "Erweitertes Tracking".
	 * ------------------------------------------------------------------- */
	if ( cfg.utmFormFill ) {
		var UTM_MODE = cfg.utmFormFillMode || 'all';
		var UTM_URLS = Array.isArray( cfg.utmFormFillUrls ) ? cfg.utmFormFillUrls : [];

		// Pro Kernfeld: name-Attribute (der Reihe nach geprüft) und CSS-Klassen
		// (direkt auf dem Feld oder auf einem Wrapper-Element darum).
		var FIELD_CONFIG = {
			source: { names: [ 'utm_source', 'source' ], classes: [ 'utm-source', 'pms-utm-source' ] },
			campaign: { names: [ 'utm_campaign', 'campaign' ], classes: [ 'utm-campaign', 'pms-utm-campaign' ] },
			medium: { names: [ 'utm_medium', 'medium' ], classes: [ 'utm-medium', 'pms-utm-medium' ] }
		};

		/**
		 * Läuft der Form-Fill auf dieser Seite? Der Server (Bugfix v0.5.7,
		 * class-pms-frontend.php) prüft nur noch, ob das Feature überhaupt
		 * aktiviert ist – die URL-Regeln (all/include/exclude, Wildcards)
		 * wertet ausschließlich der Browser anhand seines eigenen, zuverlässig
		 * aufgelösten window.location.pathname aus.
		 */
		function utmFormFillAllowed() {
			if ( 'all' === UTM_MODE ) {
				return true;
			}

			var path = ( window.location.pathname || '' ).toLowerCase();
			var matched = false;

			for ( var i = 0; i < UTM_URLS.length; i++ ) {
				// Ein abschließendes "*" ist ein einfacher Prefix-Platzhalter
				// (z. B. "/lp/*"); für den Teilstring-Vergleich wird es entfernt.
				var needle = String( UTM_URLS[ i ] || '' ).toLowerCase().replace( /\*+$/, '' );
				if ( needle && path.indexOf( needle ) > -1 ) {
					matched = true;
					break;
				}
			}

			return 'exclude' === UTM_MODE ? ! matched : matched;
		}

		/**
		 * Fallback auf das First-Touch-Cookie (pro/class-pro-utm.php, Pro-only),
		 * falls der Besucher bereits über Unterseiten navigiert ist und die
		 * Kampagnen-Parameter nicht mehr in der aktuellen URL stehen. Existiert
		 * nur, wenn "First-touch & UTM passthrough" aktiviert ist – sonst liefert
		 * dies einfach ein leeres Objekt (kein Fehler).
		 */
		function readCookieAttribution() {
			var match = document.cookie.match( /(?:^|;\s*)pms_attribution=([^;]*)/ );
			if ( ! match ) {
				return {};
			}
			try {
				var decoded = JSON.parse( decodeURIComponent( match[ 1 ] ) );
				return ( decoded && 'object' === typeof decoded ) ? decoded : {};
			} catch ( e ) {
				return {};
			}
		}

		function referrerSource() {
			var ref = ( document.referrer || '' ).toLowerCase();
			if ( ref.indexOf( 'facebook.com' ) > -1 || ref.indexOf( 'instagram.com' ) > -1 ) {
				return 'facebook';
			}
			if ( ref.indexOf( 'google.' ) > -1 ) {
				return 'google';
			}
			return 'direct';
		}

		/**
		 * Werte-Ermittlung für die 3 Kernfelder: URL-Parameter zuerst, dann
		 * Attribution-Cookie. Fehlt utm_source in beiden, wird die Quelle aus
		 * einer vorhandenen Klick-ID (fbclid -> facebook, gclid -> google) und
		 * zuletzt aus dem Referrer geschätzt, sonst "direct". fbclid/gclid
		 * sind hier nur Signale für Source, keine eigenen Ausgabefelder.
		 * Campaign/Medium haben keinen Rate-Fallback.
		 */
		function resolveAttribution() {
			var params = new URLSearchParams( window.location.search );
			var cookie = readCookieAttribution();

			var source = params.get( 'utm_source' ) || cookie.utm_source || '';

			if ( ! source ) {
				if ( params.get( 'fbclid' ) || cookie.fbclid ) {
					source = 'facebook';
				} else if ( params.get( 'gclid' ) || cookie.gclid ) {
					source = 'google';
				} else {
					source = referrerSource();
				}
			}

			return {
				source: source,
				campaign: params.get( 'utm_campaign' ) || cookie.utm_campaign || '',
				medium: params.get( 'utm_medium' ) || cookie.utm_medium || ''
			};
		}

		/**
		 * Feld für eines der 3 Kernfelder suchen: zuerst alle konfigurierten
		 * name-Attribute der Reihe nach, dann alle konfigurierten CSS-Klassen.
		 * Trägt die Klasse ein Wrapper-Element statt des Feldes selbst, wird
		 * dessen erstes Eingabefeld genutzt.
		 */
		function findAttributionField( key ) {
			var config = FIELD_CONFIG[ key ];
			if ( ! config ) {
				return null;
			}

			for ( var i = 0; i < config.names.length; i++ ) {
				var byName = document.querySelector( '[name="' + config.names[ i ] + '"]' );
				if ( byName ) {
					return byName;
				}
			}

			for ( var j = 0; j < config.classes.length; j++ ) {
				var el = document.querySelector( '.' + config.classes[ j ] );
				if ( ! el ) {
					continue;
				}
				if ( 'INPUT' === el.nodeName || 'TEXTAREA' === el.nodeName || 'SELECT' === el.nodeName ) {
					return el;
				}
				var inner = el.querySelector( 'input, textarea, select' );
				if ( inner ) {
					return inner;
				}
			}

			return null;
		}

		/**
		 * Wert setzen und input+change feuern, damit auch Form-Builder mit
		 * eigenem State (z. B. SureForms) den neuen Wert übernehmen.
		 */
		function fillAttributionField( field, value ) {
			if ( ! field || ! value || field.disabled ) {
				return;
			}
			field.value = value;
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		if ( utmFormFillAllowed() ) {
			var attribution = resolveAttribution();
			Object.keys( FIELD_CONFIG ).forEach( function ( key ) {
				fillAttributionField( findAttributionField( key ), attribution[ key ] );
			} );
		}
	}
}() );
