<?php
/**
 * Live-Debug-Leiste für Administratoren.
 *
 * Wird ausschließlich gerendert, wenn der eingeloggte Nutzer die Berechtigung
 * "manage_options" besitzt – für reguläre Besucher entsteht kein einziges Byte
 * an Overhead (weder HTML noch CSS/JS).
 *
 * @package Lightweight_Meta_Pixel_CAPI_Tracker
 */

defined( 'ABSPATH' ) || exit;

class LMPCT_Debug {

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		// Für aussagekräftige Statuscodes wird der CAPI-Request in diesem
		// Request blockierend gesendet (nur für eingeloggte Administratoren).
		add_filter( 'lmpct_capi_blocking', '__return_true' );
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

		$settings = LMPCT_Settings::get();

		return ! empty( $settings['debug_bar'] );
	}

	/**
	 * Zustandsdaten für die Leiste zusammenstellen.
	 *
	 * @return array
	 */
	private static function payload() {
		$settings = LMPCT_Settings::get();
		$banner   = LMPCT_Consent::detected_banner();

		if ( ! LMPCT_Consent::detection_enabled() ) {
			$consent = array(
				'state' => 'off',
				'label' => __( 'Detection disabled', 'lightweight-meta-pixel-capi-tracker' ),
			);
		} elseif ( LMPCT_Consent::has_marketing_consent() ) {
			$consent = array(
				'state' => 'granted',
				'label' => $banner
					/* translators: %s: name of the detected cookie banner */
					? sprintf( __( 'Granted (%s)', 'lightweight-meta-pixel-capi-tracker' ), $banner )
					: __( 'Granted (no banner detected)', 'lightweight-meta-pixel-capi-tracker' ),
			);
		} else {
			$consent = array(
				'state' => 'blocked',
				'label' => $banner
					/* translators: %s: name of the detected cookie banner */
					? sprintf( __( 'Pending / denied (%s)', 'lightweight-meta-pixel-capi-tracker' ), $banner )
					: __( 'Pending / denied', 'lightweight-meta-pixel-capi-tracker' ),
			);
		}

		$events = array();

		if ( LMPCT_Frontend::is_active() ) {
			$events[] = array(
				'name'    => 'PageView',
				'eventId' => '',
				'browser' => LMPCT_Consent::has_marketing_consent() ? 'fired' : 'deferred',
				'capi'    => 'browser_only',
			);

			foreach ( LMPCT_Frontend::get_matched_events() as $event ) {
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
					'name'      => LMPCT_Settings::resolved_event_name( $event ),
					'eventId'   => (string) $event['event_id'],
					'browser'   => LMPCT_Consent::has_marketing_consent() ? 'fired' : 'deferred',
					'capi'      => ! empty( $event['meta_enabled'] ) && ! empty( $settings['capi_enabled'] ) ? 'pending' : 'off',
					'platforms' => $platforms,
				);
			}
		}

		$attribution = class_exists( 'LMPCT_Attribution' ) ? LMPCT_Attribution::custom_data() : array();

		return array(
			'consent'     => $consent,
			'active'      => LMPCT_Frontend::is_active(),
			'reason'      => self::reason_label( LMPCT_Frontend::get_skip_reason() ),
			'events'      => $events,
			'capi'        => LMPCT_CAPI::get_log(),
			'attribution' => $attribution,
			'i18n'        => array(
				'title'       => __( 'Pixel Tracker – Live Debug', 'lightweight-meta-pixel-capi-tracker' ),
				'consent'     => __( 'Consent', 'lightweight-meta-pixel-capi-tracker' ),
				'browser'     => __( 'Browser', 'lightweight-meta-pixel-capi-tracker' ),
				'capi'        => __( 'CAPI', 'lightweight-meta-pixel-capi-tracker' ),
				'matchKeys'   => __( 'Match keys', 'lightweight-meta-pixel-capi-tracker' ),
				'attribution' => __( 'Attribution', 'lightweight-meta-pixel-capi-tracker' ),
				'noEvents'    => __( 'No events on this page yet.', 'lightweight-meta-pixel-capi-tracker' ),
				'adminNote'   => __( 'Only visible to administrators.', 'lightweight-meta-pixel-capi-tracker' ),
				'hide'        => __( 'Minimise', 'lightweight-meta-pixel-capi-tracker' ),
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
			'admin_excluded' => __( 'Tracking skipped: administrators are excluded', 'lightweight-meta-pixel-capi-tracker' ),
			'no_platform'    => __( 'Tracking skipped: no platform enabled or ID missing', 'lightweight-meta-pixel-capi-tracker' ),
			'filtered'       => __( 'Tracking skipped: disabled via lmpct_allow_tracking filter', 'lightweight-meta-pixel-capi-tracker' ),
			'context'        => __( 'Tracking skipped: not a trackable page (feed, preview, 404 …)', 'lightweight-meta-pixel-capi-tracker' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : '';
	}

	/**
	 * Leiste ausgeben.
	 *
	 * @return void
	 */
	public static function render() {
		$payload = wp_json_encode( self::payload() );

		if ( ! is_string( $payload ) ) {
			return;
		}
		?>
<style id="lmpct-debug-css">
#lmpct-debug{position:fixed;left:0;right:0;bottom:0;z-index:99999;font:12px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#e6edf3;background:#0d1117;border-top:2px solid #2f81f7;box-shadow:0 -2px 14px rgba(0,0,0,.4)}
#lmpct-debug *{box-sizing:border-box}
#lmpct-debug-head{display:flex;align-items:center;gap:10px;padding:7px 12px;cursor:pointer;user-select:none}
#lmpct-debug-head strong{font-size:12px;font-weight:600;letter-spacing:.02em}
#lmpct-debug .lmpct-pill{padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap}
#lmpct-debug .lmpct-ok{background:#12261a;color:#3fb950;border:1px solid #2ea043}
#lmpct-debug .lmpct-warn{background:#2b2111;color:#d29922;border:1px solid #9e6a03}
#lmpct-debug .lmpct-err{background:#2d1418;color:#f85149;border:1px solid #da3633}
#lmpct-debug .lmpct-neutral{background:#161b22;color:#8b949e;border:1px solid #30363d}
#lmpct-debug-toggle{margin-left:auto;background:none;border:0;color:#8b949e;cursor:pointer;font-size:14px;padding:0 4px;line-height:1}
#lmpct-debug-body{max-height:34vh;overflow-y:auto;padding:0 12px 10px;border-top:1px solid #21262d}
#lmpct-debug.lmpct-min #lmpct-debug-body{display:none}
#lmpct-debug .lmpct-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #161b22}
#lmpct-debug .lmpct-row:last-child{border-bottom:0}
#lmpct-debug .lmpct-name{font-weight:600;min-width:120px}
#lmpct-debug code{background:#161b22;border:1px solid #30363d;border-radius:3px;padding:0 5px;font-size:11px;color:#79c0ff;word-break:break-all}
#lmpct-debug .lmpct-muted{color:#8b949e}
@media (max-width:600px){#lmpct-debug .lmpct-name{min-width:0;width:100%}}
</style>
<div id="lmpct-debug" class="lmpct-min" role="complementary" aria-label="Pixel Tracker Debug">
	<div id="lmpct-debug-head">
		<strong></strong>
		<span class="lmpct-pill lmpct-neutral" data-lmpct="consent"></span>
		<span class="lmpct-pill lmpct-neutral" data-lmpct="reason" hidden></span>
		<span class="lmpct-muted" data-lmpct="note"></span>
		<button type="button" id="lmpct-debug-toggle" aria-expanded="false">▲</button>
	</div>
	<div id="lmpct-debug-body"></div>
</div>
		<?php
		wp_print_inline_script_tag(
			'(function(){var d=' . $payload . ';'
			. 'var root=document.getElementById("lmpct-debug");if(!root){return;}'
			. 'var head=document.getElementById("lmpct-debug-head"),body=document.getElementById("lmpct-debug-body"),btn=document.getElementById("lmpct-debug-toggle");'
			. 'head.querySelector("strong").textContent=d.i18n.title;'
			. 'head.querySelector(\'[data-lmpct="note"]\').textContent=d.i18n.adminNote;'
			. 'var cp=head.querySelector(\'[data-lmpct="consent"]\');'
			. 'cp.textContent=d.i18n.consent+": "+d.consent.label;'
			. 'cp.className="lmpct-pill "+("granted"===d.consent.state?"lmpct-ok":("blocked"===d.consent.state?"lmpct-err":"lmpct-neutral"));'
			. 'if(d.reason){var rp=head.querySelector(\'[data-lmpct="reason"]\');rp.hidden=false;rp.textContent=d.reason;rp.className="lmpct-pill lmpct-warn";}'
			. 'function esc(s){var e=document.createElement("span");e.textContent=String(s==null?"":s);return e.innerHTML;}'
			. 'function pill(cls,txt){return \'<span class="lmpct-pill \'+cls+\'">\'+esc(txt)+"</span>";}'
			. 'function capiPill(st,code,msg){if("ok"===st){return pill("lmpct-ok","CAPI: "+(code||200)+" OK");}'
			. 'if("sent"===st){return pill("lmpct-ok","CAPI: gesendet");}'
			. 'if("pending"===st){return pill("lmpct-neutral","CAPI: \\u23f3");}'
			. 'if("consent_blocked"===st||"no_consent"===st){return pill("lmpct-warn","CAPI: Consent blockiert");}'
			. 'if("off"===st||"browser_only"===st||"capi_inactive"===st){return pill("lmpct-neutral","CAPI: \\u2013");}'
			. 'if("admin_excluded"===st){return pill("lmpct-warn","CAPI: Admin ausgeschlossen");}'
			. 'if("skipped"===st){return pill("lmpct-warn","CAPI: übersprungen");}'
			. 'return pill("lmpct-err","CAPI: "+(code?code+" ":"")+(msg||st));}'
			. 'function addRow(ev){var row=document.createElement("div");row.className="lmpct-row";'
			. 'var html=\'<span class="lmpct-name">\'+esc(ev.name)+"</span>";'
			. 'html+=("fired"===ev.browser?pill("lmpct-ok","Pixel: \\u2705"):("deferred"===ev.browser?pill("lmpct-warn","Pixel: wartet auf Consent"):pill("lmpct-neutral","Pixel: \\u2013")));'
			. 'html+=capiPill(ev.capi,ev.code,ev.message);'
			. 'if(ev.eventId){html+=" <code>"+esc(ev.eventId)+"</code>";}'
			. 'if(ev.platforms&&ev.platforms.length){html+=\' <span class="lmpct-muted">\'+esc(ev.platforms.join(" · "))+"</span>";}'
			. 'if(ev.matchKeys&&ev.matchKeys.length){html+=\' <span class="lmpct-muted">\'+esc(d.i18n.matchKeys+": "+ev.matchKeys.join(", "))+"</span>";}'
			. 'row.innerHTML=html;row.dataset.eventId=ev.eventId||"";body.appendChild(row);return row;}'
			. 'var keys=[];d.capi.forEach(function(c){(c.match_keys||[]).forEach(function(k){if(keys.indexOf(k)<0){keys.push(k);}});});'
			. 'd.events.forEach(function(ev){var c=d.capi[0];'
			. 'if(ev.capi==="pending"&&c){ev.capi=c.status;ev.code=c.code;ev.message=c.message;}'
			. 'ev.matchKeys=("browser_only"===ev.capi||"off"===ev.capi)?[]:keys;addRow(ev);});'
			. 'if(!d.events.length){var e=document.createElement("div");e.className="lmpct-row lmpct-muted";e.textContent=d.i18n.noEvents;body.appendChild(e);}'
			. 'if(d.attribution&&Object.keys(d.attribution).length){var a=document.createElement("div");a.className="lmpct-row";'
			. 'a.innerHTML=\'<span class="lmpct-name">\'+esc(d.i18n.attribution)+"</span><code>"+esc(JSON.stringify(d.attribution))+"</code>";body.appendChild(a);}'
			. 'document.addEventListener("lmpct:event",function(e){var det=e.detail||{};addRow({name:det.event,eventId:det.eventId,browser:det.browser,capi:det.capi,matchKeys:[]});'
			. 'if(root.classList.contains("lmpct-min")){toggle();}});'
			. 'document.addEventListener("lmpct:capi",function(e){var det=e.detail||{};'
			. 'var rows=body.querySelectorAll(\'.lmpct-row[data-event-id="\'+(det.eventId||"")+\'"]\');'
			. 'if(!rows.length){return;}var row=rows[rows.length-1];'
			. 'var pills=row.querySelectorAll(".lmpct-pill");if(pills.length>1){pills[1].outerHTML=capiPill(det.status,det.code,det.message);}'
			. 'if(det.matchKeys&&det.matchKeys.length){var m=document.createElement("span");m.className="lmpct-muted";m.textContent=d.i18n.matchKeys+": "+det.matchKeys.join(", ");row.appendChild(m);}});'
			. 'function toggle(){var min=root.classList.toggle("lmpct-min");btn.textContent=min?"\\u25b2":"\\u25bc";btn.setAttribute("aria-expanded",min?"false":"true");}'
			. 'head.addEventListener("click",function(e){if(e.target===btn||!e.target.closest("code")){toggle();}});'
			. '})();'
		);
	}
}
