/**
 * Lightweight Meta Pixel & CAPI Tracker – Formular-Auto-Grabber.
 *
 * Erkennt Formular-Absendungen (native und AJAX-basierte Formular-Plugins),
 * feuert das Browser-Event "Lead" mit Event-ID und meldet dieselbe ID plus
 * Kontaktdaten an das Plugin-Backend, das den Server-Event via CAPI sendet.
 *
 * Vanilla JS, keine Abhängigkeiten (jQuery wird nur genutzt, wenn ohnehin
 * vorhanden – manche Formular-Plugins senden ihre Erfolgs-Events darüber).
 */
( function () {
	'use strict';

	var cfg = window.lmpctFront || {};

	if ( ! cfg.formTracking ) {
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
		return !! ( form && form.dataset && 'true' === form.dataset.lmpctSubmitted );
	}

	/**
	 * Formular für LOCK_MS sperren, damit Submit und AJAX-Completion zusammen
	 * nur ein einziges Event mit einer einzigen Event-ID erzeugen.
	 */
	function lockForm( form ) {
		if ( ! form || ! form.dataset ) {
			return;
		}

		form.dataset.lmpctSubmitted = 'true';

		setTimeout( function () {
			try {
				delete form.dataset.lmpctSubmitted;
			} catch ( e ) {
				form.removeAttribute( 'data-lmpct-submitted' );
			}
		}, LOCK_MS );
	}

	// Konfigurierter Meta-Event-Typ (Lead oder Contact).
	var EVENT_NAME = ( 'Contact' === cfg.eventType ) ? 'Contact' : 'Lead';

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
		if ( 'function' === typeof window.lmpctHasConsent ) {
			return window.lmpctHasConsent();
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

		if ( ! hasConsent() ) {
			emit( 'lmpct:event', {
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

		if ( 'function' === typeof window.fbq ) {
			window.fbq( 'track', EVENT_NAME, {}, { eventID: eventId } );
			browserFired = true;
		}

		emit( 'lmpct:event', {
			event: EVENT_NAME,
			eventId: eventId,
			browser: browserFired ? 'fired' : 'no_pixel',
			capi: 'pending',
			source: sourceLabel,
			hasEmail: !! data.email,
			hasPhone: !! data.phone
		} );

		if ( ! window.fetch || ! cfg.ajaxUrl ) {
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'lmpct_form_lead' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'event_id', eventId );
		body.append( 'event_name', EVENT_NAME );
		body.append( 'source_url', window.location.href );

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
			emit( 'lmpct:capi', {
				eventId: eventId,
				status: result && result.success ? ( payload.status || 'sent' ) : ( payload.reason || 'error' ),
				code: payload.code || 0,
				message: payload.message || '',
				matchKeys: payload.match_keys || []
			} );
		} ).catch( function () {
			emit( 'lmpct:capi', { eventId: eventId, status: 'error', message: 'request failed' } );
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
}() );
