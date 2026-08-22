/**
 * Pixel Made Simple – Admin-JS (Vanilla, keine Abhängigkeiten).
 */
( function () {
	'use strict';

	// Dezente Erfolgs-Meldung unten rechts (Toast).
	var toastTimer = null;
	function showToast( text ) {
		var toast = document.getElementById( 'pms-toast' );
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.id = 'pms-toast';
			toast.setAttribute( 'role', 'status' );
			toast.innerHTML = '<span class="dashicons dashicons-yes-alt"></span><span class="pms-toast-text"></span>';
			document.body.appendChild( toast );
		}
		toast.querySelector( '.pms-toast-text' ).textContent = text;
		toast.classList.add( 'pms-toast-visible' );
		if ( toastTimer ) {
			clearTimeout( toastTimer );
		}
		toastTimer = setTimeout( function () {
			toast.classList.remove( 'pms-toast-visible' );
		}, 2000 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Toggle-Switches, die ihr Formular direkt absenden (Status-Spalte, globaler Schalter).
		document.querySelectorAll( 'input[data-pms-autosubmit]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var form = input.closest( 'form' );
				if ( form ) {
					form.submit();
				}
			} );
		} );

		// Einstellungs-Toggles sofort per AJAX speichern (nonce-gesichert).
		if ( window.pmsAdmin && window.fetch ) {
			document.querySelectorAll( 'input[data-pms-autosave]' ).forEach( function ( input ) {
				input.addEventListener( 'change', function () {
					var body = new URLSearchParams( {
						action: 'pms_save_toggle',
						nonce: window.pmsAdmin.nonce,
						key: input.getAttribute( 'data-pms-autosave' ),
						value: input.checked ? '1' : '0'
					} );
					window.fetch( window.pmsAdmin.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} ).then( function ( response ) {
						return response.json();
					} ).then( function ( result ) {
						if ( result && result.success ) {
							showToast( window.pmsAdmin.savedText );
						}
					} ).catch( function () {
						/* Fehler still ignorieren – der reguläre Speichern-Button bleibt der Fallback. */
					} );
				} );
			} );
		}

		// CAPI-Token eingegeben/eingefügt -> Conversions API automatisch aktivieren.
		var tokenField = document.getElementById( 'pms-capi-token' );
		var capiToggle = document.querySelector( 'input[data-pms-autosave="capi_enabled"]' );
		if ( tokenField && capiToggle ) {
			var maybeEnableCapi = function () {
				if ( '' !== tokenField.value.trim() && ! capiToggle.checked ) {
					capiToggle.checked = true;
					capiToggle.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			};
			tokenField.addEventListener( 'input', maybeEnableCapi );
			tokenField.addEventListener( 'change', maybeEnableCapi );
		}

		// Sicherheitsabfrage vor dem Löschen.
		document.querySelectorAll( '.pms-delete-button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				var message = button.getAttribute( 'data-pms-confirm' ) || 'Delete?';
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );

		// Aufklappbare Plattform-Boxen. Klicks auf den Master-Toggle im Header
		// dürfen die Box nicht ein-/ausklappen.
		document.querySelectorAll( '.pms-accordion-header' ).forEach( function ( header ) {
			header.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '.pms-toggle' ) ) {
					return;
				}
				var box = header.closest( '.pms-accordion' );
				if ( ! box ) {
					return;
				}
				box.classList.toggle( 'closed' );
				var button = header.querySelector( '.pms-accordion-button' );
				if ( button ) {
					button.setAttribute( 'aria-expanded', box.classList.contains( 'closed' ) ? 'false' : 'true' );
				}
			} );
		} );

		// Generische Klapp-Boxen ohne Master-Toggle (z. B. "Neues Event
		// erstellen" im Tab "URL-Events", seit v0.6.10). Bewusst getrennt vom
		// Accordion-Handler darüber: dort sitzt im Header ein Toggle-Switch,
		// dessen Klicks NICHT klappen dürfen – hier gibt es keinen.
		document.querySelectorAll( '[data-pms-collapsible] .pms-collapse-header' ).forEach( function ( header ) {
			header.addEventListener( 'click', function () {
				var box = header.closest( '[data-pms-collapsible]' );
				if ( ! box ) {
					return;
				}
				box.classList.toggle( 'closed' );
				var button = header.querySelector( '.pms-accordion-button' );
				if ( button ) {
					button.setAttribute( 'aria-expanded', box.classList.contains( 'closed' ) ? 'false' : 'true' );
				}
			} );
		} );

		// Master-Toggle: blauer Akzent folgt dem Zustand, Aktivieren klappt die Box auf.
		document.querySelectorAll( '.pms-accordion' ).forEach( function ( box ) {
			var master = box.querySelector( '.pms-accordion-header .pms-toggle input' );
			if ( ! master ) {
				return;
			}
			master.addEventListener( 'change', function () {
				box.classList.toggle( 'pms-on', master.checked );
				if ( master.checked && box.classList.contains( 'closed' ) ) {
					box.classList.remove( 'closed' );
					var button = box.querySelector( '.pms-accordion-button' );
					if ( button ) {
						button.setAttribute( 'aria-expanded', 'true' );
					}
				}
			} );
		} );

		// Fokus auf das Feld einer nicht aktivierten Plattform aktiviert
		// deren Checkbox automatisch (die Controls sind bis dahin abgedimmt).
		document.querySelectorAll( '.pms-platform-row' ).forEach( function ( row ) {
			var checkbox = row.querySelector( '.pms-platform-check input' );
			if ( ! checkbox ) {
				return;
			}
			row.querySelectorAll( 'select, input[type="text"]' ).forEach( function ( control ) {
				control.addEventListener( 'focus', function () {
					if ( ! checkbox.checked ) {
						checkbox.checked = true;
						checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
			} );
		} );

		// Hinweis einblenden, wenn bei Meta oder TikTok "CustomEvent" gewählt ist.
		var metaSelect = document.getElementById( 'pms-event-type' );
		var tiktokSelect = document.getElementById( 'pms-tiktok-event' );
		var hint = document.querySelector( '.pms-custom-event-hint' );

		if ( hint && ( metaSelect || tiktokSelect ) ) {
			var updateHint = function () {
				var custom =
					( metaSelect && 'CustomEvent' === metaSelect.value ) ||
					( tiktokSelect && 'CustomEvent' === tiktokSelect.value );
				hint.hidden = ! custom;
			};
			if ( metaSelect ) {
				metaSelect.addEventListener( 'change', updateHint );
			}
			if ( tiktokSelect ) {
				tiktokSelect.addEventListener( 'change', updateHint );
			}
			updateHint();
		}
	} );
}() );
