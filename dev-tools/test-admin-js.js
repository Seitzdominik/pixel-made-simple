/**
 * Funktionaler Test-Harness für assets/admin.js (Node, keine Abhängigkeiten).
 *
 * Vierter JS-Harness neben test-frontend-js.js / test-frontend-woocommerce-js.js /
 * test-frontend-surecart-js.js, nach demselben Muster: ein handgeschriebener,
 * minimaler DOM-Ersatz (nur die APIs, die admin.js tatsächlich anfasst) plus
 * vm.runInContext() über die ECHTE Datei -- kein Reimplementieren der Logik.
 *
 * Anlass (v0.7.0): Das <select> für die Aufbewahrungsdauer im Event Log (Pro)
 * trug zwar data-pms-autosave, admin.js band den Autosave-Handler aber nur an
 * "input[data-pms-autosave]" -- das Dropdown wurde nie gespeichert. Kein
 * bestehender Test konnte das sehen, weil admin.js bis dahin gar keinen
 * Harness hatte. Abschnitt 1 unten ist der Regressionstest dafür.
 *
 * Ausführen:  node dev-tools\test-admin-js.js
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

const SRC = fs.readFileSync( path.join( __dirname, '..', 'src', 'assets', 'admin.js' ), 'utf8' );

/* ---------------------------------------------------------------------
 * Minimaler DOM-Ersatz. Elemente bilden einen echten Baum (parent/children),
 * Selektoren werden über eine kleine Compound-Selector-Auswertung gematcht:
 * tag, #id, .klasse, [attr], [attr="wert"] -- Nachfahren-Kombinatoren
 * ("a b") prüfen jeden Vorfahren gegen den linken Teil. Mehr braucht
 * admin.js nicht.
 * ------------------------------------------------------------------- */

function ClassList( initial ) {
	this.items = ( initial || [] ).slice();
}
ClassList.prototype.contains = function ( c ) {
	return this.items.indexOf( c ) > -1;
};
ClassList.prototype.add = function ( c ) {
	if ( ! this.contains( c ) ) {
		this.items.push( c );
	}
};
ClassList.prototype.remove = function ( c ) {
	this.items = this.items.filter( ( x ) => x !== c );
};
ClassList.prototype.toggle = function ( c, force ) {
	const shouldHave = undefined === force ? ! this.contains( c ) : !! force;
	if ( shouldHave ) {
		this.add( c );
	} else {
		this.remove( c );
	}
	return shouldHave;
};

function parseCompound( compound ) {
	const out = { tag: null, id: null, classes: [], attrs: [] };
	const re = /([a-zA-Z][\w-]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\]/g;
	let m;
	while ( null !== ( m = re.exec( compound ) ) ) {
		if ( m[ 1 ] ) {
			out.tag = m[ 1 ].toUpperCase();
		} else if ( m[ 2 ] ) {
			out.id = m[ 2 ];
		} else if ( m[ 3 ] ) {
			out.classes.push( m[ 3 ] );
		} else if ( m[ 4 ] ) {
			out.attrs.push( { name: m[ 4 ], value: undefined === m[ 5 ] ? null : m[ 5 ] } );
		}
	}
	return out;
}

function matchesCompound( el, parsed ) {
	if ( parsed.tag && el.nodeName !== parsed.tag ) {
		return false;
	}
	if ( parsed.id && el.id !== parsed.id ) {
		return false;
	}
	for ( const c of parsed.classes ) {
		if ( ! el.classList.contains( c ) ) {
			return false;
		}
	}
	for ( const a of parsed.attrs ) {
		if ( ! el.hasAttribute( a.name ) ) {
			return false;
		}
		if ( null !== a.value && el.getAttribute( a.name ) !== a.value ) {
			return false;
		}
	}
	return true;
}

function matchesSelector( el, selector ) {
	return selector.split( ',' ).some( ( single ) => {
		const compounds = single.trim().split( /\s+/ ).map( parseCompound );
		let current = el;
		// Letzter Compound muss auf das Element selbst passen, alle davor auf
		// irgendeinen Vorfahren (einfacher Nachfahren-Kombinator).
		if ( ! matchesCompound( current, compounds[ compounds.length - 1 ] ) ) {
			return false;
		}
		for ( let i = compounds.length - 2; i >= 0; i-- ) {
			current = current.parentNode;
			while ( current && ! matchesCompound( current, compounds[ i ] ) ) {
				current = current.parentNode;
			}
			if ( ! current ) {
				return false;
			}
		}
		return true;
	} );
}

function Element( tag, opts ) {
	opts = opts || {};
	this.nodeName = tag.toUpperCase();
	this.id = opts.id || '';
	this.classList = new ClassList( opts.classes );
	this.attributes = Object.assign( {}, opts.attrs || {} );
	this.type = opts.type || ( 'INPUT' === this.nodeName ? 'text' : '' );
	this.checked = !! opts.checked;
	this.value = undefined === opts.value ? '' : opts.value;
	this.hidden = !! opts.hidden;
	this.textContent = '';
	this.children = [];
	this.parentNode = null;
	this.listeners = {};
	this.submitted = 0;
	// innerHTML-Setter: erzeugt für jedes <tag class="..."> ein Kind-Element
	// (admin.js baut so den Toast: zwei <span>, eines davon .pms-toast-text).
	let html = '';
	Object.defineProperty( this, 'innerHTML', {
		get: () => html,
		set: ( value ) => {
			html = String( value );
			this.children = [];
			const re = /<([a-zA-Z][\w-]*)([^>]*)>/g;
			let m;
			while ( null !== ( m = re.exec( html ) ) ) {
				const classMatch = /class="([^"]*)"/.exec( m[ 2 ] );
				this.appendChild( new Element( m[ 1 ], { classes: classMatch ? classMatch[ 1 ].split( /\s+/ ) : [] } ) );
			}
		},
	} );
	( opts.children || [] ).forEach( ( child ) => this.appendChild( child ) );
}
Element.prototype.appendChild = function ( child ) {
	child.parentNode = this;
	this.children.push( child );
	return child;
};
Element.prototype.hasAttribute = function ( name ) {
	return Object.prototype.hasOwnProperty.call( this.attributes, name );
};
Element.prototype.getAttribute = function ( name ) {
	return this.hasAttribute( name ) ? this.attributes[ name ] : null;
};
Element.prototype.setAttribute = function ( name, value ) {
	this.attributes[ name ] = String( value );
};
Element.prototype.descendants = function () {
	const out = [];
	( function walk( node ) {
		node.children.forEach( ( child ) => {
			out.push( child );
			walk( child );
		} );
	}( this ) );
	return out;
};
Element.prototype.querySelectorAll = function ( selector ) {
	return this.descendants().filter( ( el ) => matchesSelector( el, selector ) );
};
Element.prototype.querySelector = function ( selector ) {
	return this.querySelectorAll( selector )[ 0 ] || null;
};
Element.prototype.closest = function ( selector ) {
	let current = this;
	while ( current ) {
		if ( current.nodeName && matchesSelector( current, selector ) ) {
			return current;
		}
		current = current.parentNode;
	}
	return null;
};
Element.prototype.addEventListener = function ( type, fn ) {
	this.listeners[ type ] = this.listeners[ type ] || [];
	this.listeners[ type ].push( fn );
};
Element.prototype.dispatchEvent = function ( evt ) {
	evt.target = evt.target || this;
	let current = this;
	while ( current ) {
		( current.listeners && current.listeners[ evt.type ] || [] ).forEach( ( fn ) => fn( evt ) );
		if ( ! evt.bubbles ) {
			break;
		}
		current = current.parentNode;
	}
	return ! evt.defaultPrevented;
};
Element.prototype.submit = function () {
	this.submitted++;
};

function makeEvent( type, opts ) {
	opts = opts || {};
	return {
		type,
		bubbles: !! opts.bubbles,
		target: opts.target || null,
		defaultPrevented: false,
		preventDefault() {
			this.defaultPrevented = true;
		},
	};
}

/* ---------------------------------------------------------------------
 * Sandbox-Fabrik: baut das Admin-Markup nach, lädt admin.js, feuert
 * DOMContentLoaded und liefert Handles auf alle relevanten Knoten.
 * ------------------------------------------------------------------- */

function run( opts ) {
	opts = opts || {};

	const root = new Element( 'body' );
	const docListeners = {};
	const fetchCalls = [];
	const confirmCalls = [];
	const timers = [];

	// Tab "Allgemein": Accordion mit Master-Toggle, CAPI-Token-Feld, CAPI-Toggle.
	const masterToggle = new Element( 'input', { type: 'checkbox', attrs: { 'data-pms-autosave': 'pixel_enabled' } } );
	const toggleLabel = new Element( 'label', { classes: [ 'pms-toggle' ], children: [ masterToggle ] } );
	const accordionButton = new Element( 'button', { classes: [ 'pms-accordion-button' ], attrs: { 'aria-expanded': 'false' } } );
	const accordionHeader = new Element( 'div', { classes: [ 'pms-accordion-header' ], children: [ toggleLabel, accordionButton ] } );
	const tokenField = new Element( 'textarea', { id: 'pms-capi-token', value: '' } );
	const capiToggle = new Element( 'input', { type: 'checkbox', attrs: { 'data-pms-autosave': 'capi_enabled' } } );
	const accordion = new Element( 'div', { classes: [ 'postbox', 'pms-accordion', 'closed' ], children: [ accordionHeader, tokenField, capiToggle ] } );

	// Tab "Event Log" (Pro): Aufbewahrungs-<select> mit Autosave.
	const retentionSelect = new Element( 'select', { id: 'pms-log-retention', attrs: { 'data-pms-autosave': 'log_retention_days' }, value: '7' } );

	// Tab "URL-Events": Autosubmit-Toggle, Löschen-Button mit/ohne Text,
	// einklappbares Formular, Plattform-Zeile, CustomEvent-Hinweis.
	const autosubmit = new Element( 'input', { type: 'checkbox', attrs: { 'data-pms-autosubmit': '1' } } );
	const autosubmitForm = new Element( 'form', { children: [ autosubmit ] } );
	const deleteButton = new Element( 'button', { classes: [ 'pms-delete-button' ], attrs: { 'data-pms-confirm': 'Really delete?' } } );
	const deleteButtonNoText = new Element( 'button', { classes: [ 'pms-delete-button' ] } );
	const collapseButton = new Element( 'button', { classes: [ 'pms-accordion-button' ], attrs: { 'aria-expanded': 'false' } } );
	const collapseHeader = new Element( 'h2', { classes: [ 'pms-collapse-header' ], children: [ collapseButton ] } );
	const collapsible = new Element( 'div', { classes: [ 'pms-collapsible', 'closed' ], attrs: { 'data-pms-collapsible': '' }, children: [ collapseHeader ] } );
	const platformCheckbox = new Element( 'input', { type: 'checkbox' } );
	const platformLabel = new Element( 'label', { classes: [ 'pms-platform-check' ], children: [ platformCheckbox ] } );
	const platformText = new Element( 'input', { type: 'text', attrs: { type: 'text' } } );
	const platformRow = new Element( 'div', { classes: [ 'pms-platform-row' ], children: [ platformLabel, platformText ] } );
	const metaSelect = new Element( 'select', { id: 'pms-event-type', value: 'Lead' } );
	const hint = new Element( 'p', { classes: [ 'pms-custom-event-hint' ], hidden: true } );

	[ accordion, retentionSelect, autosubmitForm, deleteButton, deleteButtonNoText, collapsible, platformRow, metaSelect, hint ].forEach( ( el ) => root.appendChild( el ) );

	const byId = {};
	root.descendants().forEach( ( el ) => {
		if ( el.id ) {
			byId[ el.id ] = el;
		}
	} );

	const document = {
		body: root,
		addEventListener( type, fn ) {
			docListeners[ type ] = docListeners[ type ] || [];
			docListeners[ type ].push( fn );
		},
		querySelectorAll: ( sel ) => root.querySelectorAll( sel ),
		querySelector: ( sel ) => root.querySelector( sel ),
		getElementById: ( id ) => byId[ id ] || null,
		createElement: ( tag ) => new Element( tag ),
	};

	const win = {
		document,
		pmsAdmin: false === opts.pmsAdmin ? undefined : {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'nonce123',
			savedText: 'Saved.',
			confirmText: 'Are you sure?',
		},
		fetch( url, init ) {
			fetchCalls.push( { url, body: new URLSearchParams( init.body ) } );
			return Promise.resolve( { json: () => Promise.resolve( { success: true } ) } );
		},
		confirm( message ) {
			confirmCalls.push( message );
			return false !== opts.confirmResult;
		},
		// Timer werden nur gesammelt, nicht ausgeführt -- sonst würde der
		// Toast im selben Tick wieder ausgeblendet, bevor der Test ihn sieht.
		setTimeout: ( fn ) => {
			timers.push( fn );
			return timers.length;
		},
		clearTimeout() {},
		URLSearchParams,
		Event: function ( type, init ) {
			return makeEvent( type, init );
		},
	};
	win.window = win;

	vm.createContext( win );
	vm.runInContext( SRC, win );
	( docListeners.DOMContentLoaded || [] ).forEach( ( fn ) => fn() );

	return {
		win,
		root,
		fetchCalls,
		confirmCalls,
		masterToggle,
		accordion,
		accordionHeader,
		accordionButton,
		tokenField,
		capiToggle,
		retentionSelect,
		autosubmit,
		autosubmitForm,
		deleteButton,
		deleteButtonNoText,
		collapsible,
		collapseHeader,
		collapseButton,
		platformCheckbox,
		platformText,
		metaSelect,
		hint,
		flush: () => new Promise( ( resolve ) => setImmediate( resolve ) ),
	};
}

async function main() {
	console.log( '=== 1. Autosave: Checkboxen UND Dropdowns (Regression v0.7.0) ===' );
	{
		const t = run();
		t.retentionSelect.value = '14';
		t.retentionSelect.dispatchEvent( makeEvent( 'change' ) );
		check( 'Select mit data-pms-autosave löst einen AJAX-Request aus (bis v0.6.12: nie)', 1 === t.fetchCalls.length );
		const body = t.fetchCalls[ 0 ] && t.fetchCalls[ 0 ].body;
		check( 'Request trägt action=pms_save_toggle', body && 'pms_save_toggle' === body.get( 'action' ) );
		check( 'Request trägt den Nonce aus pmsAdmin', body && 'nonce123' === body.get( 'nonce' ) );
		check( 'Request trägt key=log_retention_days', body && 'log_retention_days' === body.get( 'key' ) );
		check( 'Select sendet seinen tatsächlichen Wert (14), nicht 1/0', body && '14' === body.get( 'value' ) );
		check( 'Request geht an pmsAdmin.ajaxUrl', t.fetchCalls[ 0 ] && t.fetchCalls[ 0 ].url === t.win.pmsAdmin.ajaxUrl );

		t.masterToggle.checked = true;
		t.masterToggle.dispatchEvent( makeEvent( 'change' ) );
		check( 'Checkbox-Toggle sendet value=1 wenn angehakt', 2 === t.fetchCalls.length && '1' === t.fetchCalls[ 1 ].body.get( 'value' ) );
		check( 'Checkbox-Toggle sendet seinen Key (pixel_enabled)', 2 === t.fetchCalls.length && 'pixel_enabled' === t.fetchCalls[ 1 ].body.get( 'key' ) );

		t.masterToggle.checked = false;
		t.masterToggle.dispatchEvent( makeEvent( 'change' ) );
		check( 'Checkbox-Toggle sendet value=0 wenn abgehakt', 3 === t.fetchCalls.length && '0' === t.fetchCalls[ 2 ].body.get( 'value' ) );

		await t.flush();
		await t.flush();
		const toast = t.root.querySelector( '#pms-toast' );
		check( 'Erfolgreiche Antwort zeigt den Toast (#pms-toast) mit savedText', !! toast && toast.classList.contains( 'pms-toast-visible' ) && toast.querySelector( '.pms-toast-text' ) && 'Saved.' === toast.querySelector( '.pms-toast-text' ).textContent );
	}

	console.log( '\n=== 2. Ohne pmsAdmin/fetch kein Autosave (Fallback: Speichern-Button) ===' );
	{
		const t = run( { pmsAdmin: false } );
		t.retentionSelect.value = '30';
		t.retentionSelect.dispatchEvent( makeEvent( 'change' ) );
		t.masterToggle.checked = true;
		t.masterToggle.dispatchEvent( makeEvent( 'change' ) );
		check( 'Ohne window.pmsAdmin wird kein Request abgesetzt', 0 === t.fetchCalls.length );
	}

	console.log( '\n=== 3. CAPI-Token eingegeben -> Conversions API automatisch aktivieren ===' );
	{
		const t = run();
		t.tokenField.value = 'EAAB...token';
		t.tokenField.dispatchEvent( makeEvent( 'input' ) );
		check( 'CAPI-Toggle wird angehakt, sobald ein Token eingegeben wird', true === t.capiToggle.checked );
		check( 'Das ausgelöste change-Event speichert capi_enabled=1 per Autosave', t.fetchCalls.some( ( c ) => 'capi_enabled' === c.body.get( 'key' ) && '1' === c.body.get( 'value' ) ) );

		const before = t.fetchCalls.length;
		t.tokenField.dispatchEvent( makeEvent( 'input' ) );
		check( 'Bereits aktiver Toggle wird nicht erneut gespeichert', before === t.fetchCalls.length );
	}

	console.log( '\n=== 4. Autosubmit-Toggle & Löschen-Bestätigung ===' );
	{
		const t = run();
		t.autosubmit.dispatchEvent( makeEvent( 'change' ) );
		check( 'data-pms-autosubmit sendet das umgebende Formular ab', 1 === t.autosubmitForm.submitted );

		const evt = makeEvent( 'click' );
		t.deleteButton.dispatchEvent( evt );
		check( 'Löschen-Button fragt mit dem data-pms-confirm-Text nach', 'Really delete?' === t.confirmCalls[ 0 ] );
		check( 'Bestätigung -> Klick wird NICHT unterbunden', false === evt.defaultPrevented );

		const evt2 = makeEvent( 'click' );
		t.deleteButtonNoText.dispatchEvent( evt2 );
		check( 'Ohne data-pms-confirm greift der lokalisierte Fallback aus pmsAdmin.confirmText (kein hartcodiertes "Delete?")', 'Are you sure?' === t.confirmCalls[ 1 ] );
	}
	{
		const t = run( { confirmResult: false } );
		const evt = makeEvent( 'click' );
		t.deleteButton.dispatchEvent( evt );
		check( 'Abbruch im Bestätigungsdialog unterbindet den Klick (preventDefault)', true === evt.defaultPrevented );
	}

	console.log( '\n=== 5. Accordion, Klapp-Box, Master-Toggle, Plattform-Zeile, CustomEvent-Hinweis ===' );
	{
		const t = run();
		t.accordionHeader.dispatchEvent( makeEvent( 'click', { target: t.accordionHeader } ) );
		check( 'Klick auf den Accordion-Header klappt die Box auf (closed entfernt)', ! t.accordion.classList.contains( 'closed' ) );
		check( 'aria-expanded folgt dem Zustand (true)', 'true' === t.accordionButton.getAttribute( 'aria-expanded' ) );

		t.accordionHeader.dispatchEvent( makeEvent( 'click', { target: t.masterToggle } ) );
		check( 'Klick auf den Master-Toggle im Header klappt NICHT zu', ! t.accordion.classList.contains( 'closed' ) );

		t.accordionHeader.dispatchEvent( makeEvent( 'click', { target: t.accordionHeader } ) );
		t.masterToggle.checked = true;
		t.masterToggle.dispatchEvent( makeEvent( 'change' ) );
		check( 'Master-Toggle an -> blauer Akzent (pms-on) und Box öffnet sich', t.accordion.classList.contains( 'pms-on' ) && ! t.accordion.classList.contains( 'closed' ) );

		t.collapseHeader.dispatchEvent( makeEvent( 'click', { target: t.collapseHeader } ) );
		check( 'Klapp-Box ([data-pms-collapsible]) öffnet sich per Header-Klick', ! t.collapsible.classList.contains( 'closed' ) && 'true' === t.collapseButton.getAttribute( 'aria-expanded' ) );

		t.platformText.dispatchEvent( makeEvent( 'focus' ) );
		check( 'Fokus auf ein Feld einer Plattform-Zeile aktiviert deren Checkbox', true === t.platformCheckbox.checked );

		check( 'CustomEvent-Hinweis startet versteckt (Event-Typ Lead)', true === t.hint.hidden );
		t.metaSelect.value = 'CustomEvent';
		t.metaSelect.dispatchEvent( makeEvent( 'change' ) );
		check( 'Event-Typ CustomEvent blendet den Hinweis ein', false === t.hint.hidden );
	}

	console.log( '\n==============================' );
	console.log( 'Ergebnis: ' + pass + ' bestanden, ' + fail + ' fehlgeschlagen' );
	process.exit( fail > 0 ? 1 : 0 );
}

main();
