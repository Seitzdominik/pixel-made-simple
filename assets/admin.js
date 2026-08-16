/**
 * Lightweight Meta Pixel & CAPI Tracker – Admin-JS (Vanilla, keine Abhängigkeiten).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Toggle-Switches, die ihr Formular direkt absenden (Status-Spalte, globaler Schalter).
		document.querySelectorAll( 'input[data-lmpct-autosubmit]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var form = input.closest( 'form' );
				if ( form ) {
					form.submit();
				}
			} );
		} );

		// Sicherheitsabfrage vor dem Löschen.
		document.querySelectorAll( '.lmpct-delete-button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				var message = button.getAttribute( 'data-lmpct-confirm' ) || 'Delete?';
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );

		// Aufklappbare Plattform-Boxen. Klicks auf den Master-Toggle im Header
		// dürfen die Box nicht ein-/ausklappen.
		document.querySelectorAll( '.lmpct-accordion-header' ).forEach( function ( header ) {
			header.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '.lmpct-toggle' ) ) {
					return;
				}
				var box = header.closest( '.lmpct-accordion' );
				if ( ! box ) {
					return;
				}
				box.classList.toggle( 'closed' );
				var button = header.querySelector( '.lmpct-accordion-button' );
				if ( button ) {
					button.setAttribute( 'aria-expanded', box.classList.contains( 'closed' ) ? 'false' : 'true' );
				}
			} );
		} );

		// Master-Toggle: blauer Akzent folgt dem Zustand, Aktivieren klappt die Box auf.
		document.querySelectorAll( '.lmpct-accordion' ).forEach( function ( box ) {
			var master = box.querySelector( '.lmpct-accordion-header .lmpct-toggle input' );
			if ( ! master ) {
				return;
			}
			master.addEventListener( 'change', function () {
				box.classList.toggle( 'lmpct-on', master.checked );
				if ( master.checked && box.classList.contains( 'closed' ) ) {
					box.classList.remove( 'closed' );
					var button = box.querySelector( '.lmpct-accordion-button' );
					if ( button ) {
						button.setAttribute( 'aria-expanded', 'true' );
					}
				}
			} );
		} );

		// Fokus auf das Feld einer nicht aktivierten Plattform aktiviert
		// deren Checkbox automatisch (die Controls sind bis dahin abgedimmt).
		document.querySelectorAll( '.lmpct-platform-row' ).forEach( function ( row ) {
			var checkbox = row.querySelector( '.lmpct-platform-check input' );
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
		var metaSelect = document.getElementById( 'lmpct-event-type' );
		var tiktokSelect = document.getElementById( 'lmpct-tiktok-event' );
		var hint = document.querySelector( '.lmpct-custom-event-hint' );

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
