<?php
/**
 * Einstiegspunkt für alle Pro-exklusiven Funktionen.
 *
 * Wird ausschließlich von pixel-made-simple-pro.php geladen (siehe dortiger
 * require_once) – die Free-Version bindet diese Datei nie ein und das
 * Free-ZIP aus der GitHub-Action enthält /pro/ gar nicht erst.
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Pro_Features {

	/**
	 * Hooks der Pro-Features registrieren.
	 *
	 * @return void
	 */
	public static function init() {
		// Platzhalter: hier künftig Pro-exklusive Hooks registrieren
		// (z. B. add_action/add_filter für zusätzliche Plattformen, erweiterte
		// Attribution, o. Ä.). PMS_IS_PRO ist an dieser Stelle immer true.
	}
}
