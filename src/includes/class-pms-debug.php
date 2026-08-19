<?php
/**
 * Live-Debug-Leiste für Administratoren.
 *
 * Wird ausschließlich gerendert, wenn der eingeloggte Nutzer die Berechtigung
 * "manage_options" besitzt – für reguläre Besucher entsteht kein einziges Byte
 * an Overhead (weder HTML noch CSS/JS).
 *
 * @package Pixel_Made_Simple
 */

defined( 'ABSPATH' ) || exit;

class PMS_Debug {

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		// Für aussagekräftige Statuscodes wird der CAPI-Request in diesem
		// Request blockierend gesendet (nur für eingeloggte Administratoren).
		add_filter( 'pms_capi_blocking', '__return_true' );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 99 );
	}

	/**
	 * Ist die Debug-Leiste für den aktuellen Aufruf aktiv?
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$settings = PMS_Settings::get();

		return ! empty( $settings['debug_bar'] );
	}

	/**
	 * Zustandsdaten für die Leiste zusammenstellen.
	 *
	 * @return array
	 */
	private static function payload() {
		$settings = PMS_Settings::get();
		$banner   = PMS_Consent::detected_banner();

		if ( ! PMS_Consent::detection_enabled() ) {
			$consent = array(
				'state' => 'off',
				'label' => __( 'Detection disabled', 'pixel-made-simple' ),
			);
		} elseif ( PMS_Consent::has_marketing_consent() ) {
			$consent = array(
				'state' => 'granted',
				'label' => $banner
					/* translators: %s: name of the detected cookie banner */
					? sprintf( __( 'Granted (%s)', 'pixel-made-simple' ), $banner )
					: __( 'Granted (no banner detected)', 'pixel-made-simple' ),
			);
		} else {
			$consent = array(
				'state' => 'blocked',
				'label' => $banner
					/* translators: %s: name of the detected cookie banner */
					? sprintf( __( 'Pending / denied (%s)', 'pixel-made-simple' ), $banner )
					: __( 'Pending / denied', 'pixel-made-simple' ),
			);
		}

		$events = array();

		if ( PMS_Frontend::is_active() ) {
			$events[] = array(
				'name'    => 'PageView',
				'eventId' => '',
				'browser' => PMS_Consent::has_marketing_consent() ? 'fired' : 'deferred',
				'capi'    => 'browser_only',
			);

			foreach ( PMS_Frontend::get_matched_events() as $event ) {
				$platforms = array();
				if ( ! empty( $event['meta_enabled'] ) ) {
					$platforms[] = 'Meta';
				}
				if ( ! empty( $event['google_enabled'] ) ) {
					$platforms[] = 'Google Ads';
				}
				if ( ! empty( $event['tiktok_enabled'] ) ) {
					$platforms[] = 'TikTok';
				}

				$events[] = array(
					'name'      => PMS_Settings::resolved_event_name( $event ),
					'eventId'   => (string) $event['event_id'],
					'browser'   => PMS_Consent::has_marketing_consent() ? 'fired' : 'deferred',
					'capi'      => ! empty( $event['meta_enabled'] ) && ! empty( $settings['capi_enabled'] ) ? 'pending' : 'off',
					'platforms' => $platforms,
				);
			}
		}

		$attribution = class_exists( 'PMS_Pro_UTM' ) ? PMS_Pro_UTM::custom_data() : array();

		return array(
			'consent'     => $consent,
			'active'      => PMS_Frontend::is_active(),
			'reason'      => self::reason_label( PMS_Frontend::get_skip_reason() ),
			'events'      => $events,
			'capi'        => PMS_CAPI::get_log(),
			'attribution' => $attribution,
			'i18n'        => array(
				'title'       => __( 'Pixel Made Simple – Live Debug', 'pixel-made-simple' ),
				'consent'     => __( 'Consent', 'pixel-made-simple' ),
				'browser'     => __( 'Browser', 'pixel-made-simple' ),
				'capi'        => __( 'CAPI', 'pixel-made-simple' ),
				'matchKeys'   => __( 'Match keys', 'pixel-made-simple' ),
				'attribution' => __( 'Attribution', 'pixel-made-simple' ),
				'noEvents'    => __( 'No events on this page yet.', 'pixel-made-simple' ),
				'adminNote'   => __( 'Only visible to administrators.', 'pixel-made-simple' ),
				'hide'        => __( 'Minimise', 'pixel-made-simple' ),
			),
		);
	}

	/**
	 * Grund für übersprungenes Tracking in Klartext.
	 *
	 * @param string $reason Interner Schlüssel.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		$labels = array(
			''               => '',
			'admin_excluded' => __( 'Tracking skipped: administrators are excluded', 'pixel-made-simple' ),
			'no_platform'    => __( 'Tracking skipped: no platform enabled or ID missing', 'pixel-made-simple' ),
			'filtered'       => __( 'Tracking skipped: disabled via pms_allow_tracking filter', 'pixel-made-simple' ),
			'context'        => __( 'Tracking skipped: not a trackable page (feed, preview, 404 …)', 'pixel-made-simple' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : '';
	}

	/**
	 * Leiste ausgeben.
	 *
	 * @return void
	 */
	public static function render() {
		// JSON_HEX_TAG etc.: Das Log enthält u. a. rohen Antworttext der Meta-
		// API (externe, nicht kontrollierte Quelle). Ohne diese Flags könnte
		// eine Sequenz wie "</script>" darin das umgebende Inline-Skript vom
		// HTML-Parser vorzeitig beenden lassen, bevor JS überhaupt läuft –
		// die client-seitige textContent-Escaping in render() greift erst
		// danach. Die Flags kodieren <, >, &, ' und " als \uXXXX und schließen
		// diesen Bruch aus.
		$payload = wp_json_encode( self::payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( ! is_string( $payload ) ) {
			return;
		}
		?>
<style id="pms-debug-css">
#pms-debug{position:fixed;left:0;right:0;bottom:0;z-index:99999;font:12px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#e6edf3;background:#0d1117;border-top:2px solid #2f81f7;box-shadow:0 -2px 14px rgba(0,0,0,.4)}
#pms-debug *{box-sizing:border-box}
#pms-debug-head{display:flex;align-items:center;gap:10px;padding:7px 12px;cursor:pointer;user-select:none}
#pms-debug-head strong{font-size:12px;font-weight:600;letter-spacing:.02em}
#pms-debug .pms-pill{padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap}
#pms-debug .pms-ok{background:#12261a;color:#3fb950;border:1px solid #2ea043}
#pms-debug .pms-warn{background:#2b2111;color:#d29922;border:1px solid #9e6a03}
#pms-debug .pms-err{background:#2d1418;color:#f85149;border:1px solid #da3633}
#pms-debug .pms-neutral{background:#161b22;color:#8b949e;border:1px solid #30363d}
#pms-debug-toggle{margin-left:auto;background:none;border:0;color:#8b949e;cursor:pointer;font-size:14px;padding:0 4px;line-height:1}
#pms-debug-body{max-height:34vh;overflow-y:auto;padding:0 12px 10px;border-top:1px solid #21262d}
#pms-debug.pms-min #pms-debug-body{display:none}
#pms-debug .pms-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #161b22}
#pms-debug .pms-row:last-child{border-bottom:0}
#pms-debug .pms-name{font-weight:600;min-width:120px}
#pms-debug code{background:#161b22;border:1px solid #30363d;border-radius:3px;padding:0 5px;font-size:11px;color:#79c0ff;word-break:break-all}
#pms-debug .pms-muted{color:#8b949e}
@media (max-width:600px){#pms-debug .pms-name{min-width:0;width:100%}}
</style>
<div id="pms-debug" class="pms-min" role="complementary" aria-label="Pixel Made Simple Debug">
	<div id="pms-debug-head">
		<strong></strong>
		<span class="pms-pill pms-neutral" data-pms="consent"></span>
		<span class="pms-pill pms-neutral" data-pms="reason" hidden></span>
		<span class="pms-muted" data-pms="note"></span>
		<button type="button" id="pms-debug-toggle" aria-expanded="false">▲</button>
	</div>
	<div id="pms-debug-body"></div>
</div>
		<?php
		wp_print_inline_script_tag(
			'(function(){var d=' . $payload . ';'
			. 'var root=document.getElementById("pms-debug");if(!root){return;}'
			. 'var head=document.getElementById("pms-debug-head"),body=document.getElementById("pms-debug-body"),btn=document.getElementById("pms-debug-toggle");'
			. 'head.querySelector("strong").textContent=d.i18n.title;'
			. 'head.querySelector(\'[data-pms="note"]\').textContent=d.i18n.adminNote;'
			. 'var cp=head.querySelector(\'[data-pms="consent"]\');'
			. 'cp.textContent=d.i18n.consent+": "+d.consent.label;'
			. 'cp.className="pms-pill "+("granted"===d.consent.state?"pms-ok":("blocked"===d.consent.state?"pms-err":"pms-neutral"));'
			. 'if(d.reason){var rp=head.querySelector(\'[data-pms="reason"]\');rp.hidden=false;rp.textContent=d.reason;rp.className="pms-pill pms-warn";}'
			. 'function esc(s){var e=document.createElement("span");e.textContent=String(s==null?"":s);return e.innerHTML;}'
			. 'function pill(cls,txt){return \'<span class="pms-pill \'+cls+\'">\'+esc(txt)+"</span>";}'
			. 'function capiPill(st,code,msg){if("ok"===st){return pill("pms-ok","CAPI: "+(code||200)+" OK");}'
			. 'if("sent"===st){return pill("pms-ok","CAPI: gesendet");}'
			. 'if("pending"===st){return pill("pms-neutral","CAPI: \\u23f3");}'
			. 'if("consent_blocked"===st||"no_consent"===st){return pill("pms-warn","CAPI: Consent blockiert");}'
			. 'if("off"===st||"browser_only"===st||"capi_inactive"===st){return pill("pms-neutral","CAPI: \\u2013");}'
			. 'if("admin_excluded"===st){return pill("pms-warn","CAPI: Admin ausgeschlossen");}'
			. 'if("skipped"===st){return pill("pms-warn","CAPI: übersprungen");}'
			. 'return pill("pms-err","CAPI: "+(code?code+" ":"")+(msg||st));}'
			. 'function addRow(ev){var row=document.createElement("div");row.className="pms-row";'
			. 'var html=\'<span class="pms-name">\'+esc(ev.name)+"</span>";'
			. 'html+=("fired"===ev.browser?pill("pms-ok","Pixel: \\u2705"):("deferred"===ev.browser?pill("pms-warn","Pixel: wartet auf Consent"):pill("pms-neutral","Pixel: \\u2013")));'
			. 'html+=capiPill(ev.capi,ev.code,ev.message);'
			. 'if(ev.eventId){html+=" <code>"+esc(ev.eventId)+"</code>";}'
			. 'if(ev.platforms&&ev.platforms.length){html+=\' <span class="pms-muted">\'+esc(ev.platforms.join(" · "))+"</span>";}'
			. 'if(ev.matchKeys&&ev.matchKeys.length){html+=\' <span class="pms-muted">\'+esc(d.i18n.matchKeys+": "+ev.matchKeys.join(", "))+"</span>";}'
			. 'row.innerHTML=html;row.dataset.eventId=ev.eventId||"";body.appendChild(row);return row;}'
			. 'var keys=[];d.capi.forEach(function(c){(c.match_keys||[]).forEach(function(k){if(keys.indexOf(k)<0){keys.push(k);}});});'
			. 'd.events.forEach(function(ev){var c=d.capi[0];'
			. 'if(ev.capi==="pending"&&c){ev.capi=c.status;ev.code=c.code;ev.message=c.message;}'
			. 'ev.matchKeys=("browser_only"===ev.capi||"off"===ev.capi)?[]:keys;addRow(ev);});'
			. 'if(!d.events.length){var e=document.createElement("div");e.className="pms-row pms-muted";e.textContent=d.i18n.noEvents;body.appendChild(e);}'
			. 'if(d.attribution&&Object.keys(d.attribution).length){var a=document.createElement("div");a.className="pms-row";'
			. 'a.innerHTML=\'<span class="pms-name">\'+esc(d.i18n.attribution)+"</span><code>"+esc(JSON.stringify(d.attribution))+"</code>";body.appendChild(a);}'
			. 'document.addEventListener("pms:event",function(e){var det=e.detail||{};addRow({name:det.event,eventId:det.eventId,browser:det.browser,capi:det.capi,matchKeys:[]});'
			. 'if(root.classList.contains("pms-min")){toggle();}});'
			. 'document.addEventListener("pms:capi",function(e){var det=e.detail||{};'
			. 'var rows=body.querySelectorAll(\'.pms-row[data-event-id="\'+(det.eventId||"")+\'"]\');'
			. 'if(!rows.length){return;}var row=rows[rows.length-1];'
			. 'var pills=row.querySelectorAll(".pms-pill");if(pills.length>1){pills[1].outerHTML=capiPill(det.status,det.code,det.message);}'
			. 'if(det.matchKeys&&det.matchKeys.length){var m=document.createElement("span");m.className="pms-muted";m.textContent=d.i18n.matchKeys+": "+det.matchKeys.join(", ");row.appendChild(m);}});'
			. 'function toggle(){var min=root.classList.toggle("pms-min");btn.textContent=min?"\\u25b2":"\\u25bc";btn.setAttribute("aria-expanded",min?"false":"true");}'
			. 'head.addEventListener("click",function(e){if(e.target===btn||!e.target.closest("code")){toggle();}});'
			. '})();'
		);
	}
}
