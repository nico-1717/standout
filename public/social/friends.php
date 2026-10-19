<?php
/* =========================================================================
   STANDOUT · AMICI
   Profilo, confronto con gli amici, duello 1 contro 1, classifica globale.
   ========================================================================= */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';                 // stessa protezione di stats_fc.php
require_once __DIR__ . '/friends_core.php';

$dati_u = fr_utente_corrente();
if (!$dati_u) { die('Utente non trovato, ricarica la pagina.'); }
$me = (int)$dati_u['id'];
$utente_corrente = $dati_u; // Assicura la compatibilità con il resto della pagina
$c = fr_conn();

$ioStats  = fr_stats($c, $me, (string)$utente_corrente['username']);
$amiciIds = fr_amici_ids($c, $me);

$amici = [];
foreach ($amiciIds as $aid) { $amici[] = fr_stats($c, $aid); }
usort($amici, function ($a, $b) { return $b['focus_oggi'] <=> $a['focus_oggi']; });

$inFocus   = fr_in_focus_ora($c, array_merge([$me], $amiciIds));
$attiviOggi = fr_attivi_oggi($c, array_merge([$me], $amiciIds));

$richieste = [];
foreach (fr_notifiche($c, $me) as $n) { if ($n['tipo'] === 'ricevuta') { $richieste[] = $n; } }

$metriche   = fr_metriche();
$classifica = fr_classifica($c, 'oggi');
$quantiFocus = fr_quanti_in_focus($c);

$apriDuello = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$tabIniziale = isset($_GET['tab']) && in_array($_GET['tab'], ['io', 'amici', 'classifica'], true) ? $_GET['tab'] : 'io';
if ($apriDuello > 0) { $tabIniziale = 'amici'; }

$fr_widget_inline = true;   // il pallino va dentro l'header di questa pagina

/* dati per il JS */
$FRJS = [
    'me'        => $ioStats,
    'amici'     => $amici,
    'metriche'  => $metriche,
    'inFocus'   => $inFocus,
    'apriDuello'=> $apriDuello,
    'tab'       => $tabIniziale,
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title>Amici · Standout</title>
<style>
/* =================================================================
   DESIGN SYSTEM — identico a stats_fc.php, più l'accento "social"
   ================================================================= */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6; --yellow:#FFCC00;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px;
    --spring:cubic-bezier(.32,.72,0,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);

    /* accento social: usato solo dove conta (anello attivo, vincitore, podio) */
    --fx1:#FFB302; --fx2:#FF3B7A; --fx3:#7B3FE4;
    --fx:linear-gradient(135deg, var(--fx1), var(--fx2) 46%, var(--fx3));
    --oro:linear-gradient(135deg,#FFD84D,#FF9500);
    --argento:linear-gradient(135deg,#E6EAEE,#B4BCC4);
    --bronzo:linear-gradient(135deg,#E5AC79,#BE7238);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
        --teal:#40C8E0; --pink:#FF375F; --indigo:#5E5CE6; --yellow:#FFD60A;
        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; }
html.fr-lock, html.fr-lock body{ overflow:hidden; }
body{
    margin:0; min-height:100vh; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.4; -webkit-font-smoothing:antialiased;
    padding-bottom:calc(90px + var(--safe-bottom));
}
button{ font-family:inherit; }
a{ color:inherit; text-decoration:none; }
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:10px; }

@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }
@keyframes popIn{ from{ opacity:0; transform:scale(.72); } to{ opacity:1; transform:scale(1); } }
@keyframes pulse{ 0%{ box-shadow:0 0 0 0 rgba(52,199,89,.55); } 70%{ box-shadow:0 0 0 9px rgba(52,199,89,0); } 100%{ box-shadow:0 0 0 0 rgba(52,199,89,0); } }
@keyframes flicker{ 0%,100%{ transform:scale(1) rotate(0); } 30%{ transform:scale(1.08) rotate(-3deg); } 60%{ transform:scale(.96) rotate(2deg); } }
@keyframes shimmer{ to{ background-position:200% 0; } }

/* ---------------- header ---------------- */
.hdr{
    position:sticky; top:0; z-index:100;
    padding:calc(var(--safe-top) + 10px) 14px 0;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-bottom:.5px solid var(--hairline);
}
.hdr-row{ display:flex; align-items:center; gap:10px; max-width:640px; margin:0 auto; }
.hdr-back{
    width:36px; height:36px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text);
    display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .18s var(--spring);
}
.hdr-back:active{ transform:scale(.86); }
.hdr-back svg{ width:17px; height:17px; }
.hdr-title{ font-size:26px; font-weight:800; letter-spacing:-.025em; flex:1; }
.hdr-live{
    display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:800;
    color:var(--green); background:var(--green-soft); padding:4px 9px; border-radius:999px; white-space:nowrap;
}
.hdr-live i{ width:6px; height:6px; border-radius:50%; background:var(--green); animation:pulse 2s infinite; }

.tabs{ display:flex; max-width:640px; margin:11px auto 0; padding:3px; background:var(--surface-2); border-radius:12px; box-shadow:inset 0 0 0 .5px var(--hairline); position:relative; }
.tabs::before{
    content:""; position:absolute; top:3px; bottom:3px; left:3px; width:calc((100% - 6px) / 3);
    background:var(--surface); border-radius:9px; box-shadow:var(--raise-sm);
    transition:transform .42s var(--spring);
}
.tabs[data-t="amici"]::before{ transform:translateX(100%); }
.tabs[data-t="classifica"]::before{ transform:translateX(200%); }
.tabs button{
    position:relative; z-index:1; flex:1; border:none; background:none; cursor:pointer;
    padding:9px 4px 10px; font-size:13.5px; font-weight:700; color:var(--text-2);
    transition:color .3s; white-space:nowrap;
}
.tabs button.on{ color:var(--text); }
.hdr-sp{ height:11px; }

.page{ max-width:640px; margin:0 auto; padding:16px 14px 0; }
.tab{ display:none; }
.tab.on{ display:block; animation:cardIn .4s var(--spring) both; }

/* ---------------- carte ---------------- */
.card{ background:var(--surface); border-radius:var(--r-card); padding:18px; box-shadow:var(--shadow-card); margin-bottom:14px; animation:cardIn .5s var(--spring) both; }
.t-sec{ font-size:16.5px; font-weight:800; letter-spacing:-.01em; margin:0 0 3px; display:flex; align-items:center; gap:7px; }
.t-sub{ font-size:12.5px; color:var(--text-2); font-weight:500; margin:0 0 14px; }
.vuoto{ text-align:center; padding:26px 12px; color:var(--text-2); font-size:13.5px; font-weight:600; line-height:1.5; }

/* ---------------- profilo ---------------- */
.pro{ display:flex; align-items:center; gap:16px; }
.pro-ring{ position:relative; width:88px; height:88px; flex-shrink:0; display:grid; place-items:center; }
.pro-ring svg{ position:absolute; inset:0; width:88px; height:88px; transform:rotate(-90deg); }
.pro-ava{
    width:68px; height:68px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:27px; font-weight:800; box-shadow:inset 0 0 0 2px rgba(255,255,255,.3);
}
.pro-nome{ font-size:22px; font-weight:800; letter-spacing:-.02em; margin:0; overflow:hidden; text-overflow:ellipsis; }
.pro-liv{ font-size:12px; font-weight:800; color:var(--text-2); margin:2px 0 9px; }
.pro-liv b{ background:var(--fx); -webkit-background-clip:text; background-clip:text; color:transparent; }
.xp{ height:7px; border-radius:5px; background:var(--surface-2); overflow:hidden; box-shadow:inset 0 0 0 .5px var(--hairline); }
.xp i{ display:block; height:100%; border-radius:5px; background:var(--fx); width:0; transition:width 1.1s var(--spring) .25s; }
.xp-txt{ font-size:10.5px; color:var(--text-3); font-weight:700; margin-top:5px; font-variant-numeric:tabular-nums; }
.live-pill{
    display:inline-flex; align-items:center; gap:6px; margin-top:10px;
    font-size:11.5px; font-weight:800; color:var(--green); background:var(--green-soft);
    padding:5px 10px; border-radius:999px;
}
.live-pill i{ width:7px; height:7px; border-radius:50%; background:var(--green); animation:pulse 2s infinite; }

/* ---------------- streak ---------------- */
.scroll{ display:flex; gap:10px; overflow-x:auto; scroll-snap-type:x mandatory; margin:0 -14px 14px; padding:2px 14px 6px; }
.scroll::-webkit-scrollbar{ display:none; }
.streak{
    scroll-snap-align:start; flex:0 0 138px; background:var(--surface); border-radius:18px; padding:15px;
    box-shadow:var(--shadow-card); position:relative; overflow:hidden; animation:cardIn .5s var(--spring) both;
}
.streak .em{ font-size:24px; line-height:1; display:block; margin-bottom:9px; }
.streak.hot .em{ animation:flicker 1.9s ease-in-out infinite; transform-origin:50% 90%; }
.streak .n{ font-size:26px; font-weight:800; letter-spacing:-.03em; font-variant-numeric:tabular-nums; }
.streak .l{ font-size:11.5px; color:var(--text-2); font-weight:700; margin-top:1px; }
.streak .r{ font-size:10.5px; color:var(--text-3); font-weight:600; margin-top:6px; }
.streak.gem{ background:linear-gradient(150deg, rgba(123,63,228,.1), rgba(255,59,122,.09)); box-shadow:var(--shadow-card), inset 0 0 0 .5px rgba(123,63,228,.25); }

/* ---------------- focus ---------------- */
.focus-big{ display:flex; align-items:flex-end; justify-content:space-between; gap:14px; margin-bottom:16px; }
.focus-n{ font-size:42px; font-weight:800; letter-spacing:-.035em; line-height:1; font-variant-numeric:tabular-nums; }
.focus-l{ font-size:12.5px; color:var(--text-2); font-weight:700; margin-top:5px; }
.spark{ display:flex; align-items:flex-end; gap:5px; height:52px; }
.spark i{ width:8px; border-radius:4px; background:linear-gradient(180deg, var(--teal), var(--blue)); min-height:4px; display:block; transition:height .8s var(--spring); }
.spark i.oggi{ background:var(--fx); }
.grid4{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.tile{ background:var(--surface-2); border-radius:14px; padding:12px; }
.tile .n{ font-size:18px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
.tile .l{ font-size:11px; color:var(--text-2); font-weight:700; margin-top:2px; }
.tile .s{ font-size:10px; color:var(--text-3); font-weight:600; margin-top:3px; }

/* ---------------- categorie 24h ---------------- */
.cat{ display:flex; align-items:center; gap:11px; padding:11px 0; border-bottom:.5px solid var(--hairline); }
.cat:last-child{ border-bottom:none; padding-bottom:0; }
.cat-dot{ width:11px; height:11px; border-radius:4px; flex-shrink:0; }
.cat-b{ flex:1; min-width:0; }
.cat-n{ font-size:14px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cat-m{ font-size:11.5px; color:var(--text-2); font-weight:600; margin-top:2px; }
.cat-r{ text-align:right; flex-shrink:0; }
.cat-t{ font-size:14.5px; font-weight:800; font-variant-numeric:tabular-nums; }
.cat-p{ font-size:11px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; }
.cat-bar{ height:5px; border-radius:4px; background:var(--surface-2); margin-top:6px; overflow:hidden; }
.cat-bar i{ display:block; height:100%; border-radius:4px; width:0; transition:width .9s var(--spring); }

/* ---------------- progetti ---------------- */
.prj{ display:flex; align-items:center; gap:11px; background:var(--surface-2); border-radius:14px; padding:13px; }
.prj-dot{ width:36px; height:36px; border-radius:11px; flex-shrink:0; display:grid; place-items:center; color:#fff; font-size:16px; }
.prj-l{ font-size:10.5px; color:var(--text-2); font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
.prj-n{ font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.prj-t{ margin-left:auto; font-size:14px; font-weight:800; font-variant-numeric:tabular-nums; flex-shrink:0; }

/* ---------------- badge ---------------- */
.bg-grid{ display:grid; grid-template-columns:repeat(4, 1fr); gap:9px; }
@media (max-width:400px){ .bg-grid{ grid-template-columns:repeat(3, 1fr); } }
.bg{ display:flex; flex-direction:column; align-items:center; gap:6px; padding:12px 5px; border-radius:14px; background:var(--surface-2); text-align:center; }
.bg-i{ width:34px; height:34px; border-radius:50%; display:grid; place-items:center; background:var(--surface); color:var(--text-3); }
.bg-i svg{ width:17px; height:17px; }
.bg.on{ background:rgba(255,59,122,.08); animation:popIn .45s var(--spring) both; }
.bg.on .bg-i{ background:var(--fx); color:#fff; box-shadow:0 5px 14px -5px rgba(255,59,122,.75); }
.bg-l{ font-size:9.5px; font-weight:700; color:var(--text-2); line-height:1.25; }
.bg.on .bg-l{ color:var(--text); }

/* ---------------- storie amici ---------------- */
.storie{ display:flex; gap:14px; overflow-x:auto; margin:0 -14px 4px; padding:4px 14px 12px; scroll-snap-type:x proximity; }
.storie::-webkit-scrollbar{ display:none; }
.st{ flex:0 0 auto; width:70px; text-align:center; background:none; border:none; padding:0; cursor:pointer; scroll-snap-align:start; }
.st-r{ width:68px; height:68px; border-radius:50%; padding:2.5px; background:var(--hairline-2); position:relative; transition:transform .22s var(--spring); }
.st:active .st-r{ transform:scale(.9); }
.st-r.att{ background:conic-gradient(from 200deg, var(--fx1), var(--fx2), var(--fx3), var(--fx1)); }
.st-in{ width:100%; height:100%; border-radius:50%; padding:2.5px; background:var(--bg); }
.st-a{ width:100%; height:100%; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:21px; font-weight:800; box-shadow:inset 0 0 0 1.5px rgba(255,255,255,.25); }
.st-live{ position:absolute; right:1px; bottom:1px; width:15px; height:15px; border-radius:50%; background:var(--green); border:2.5px solid var(--bg); animation:pulse 2s infinite; }
.st-n{ font-size:11.5px; font-weight:700; margin-top:6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.st-s{ font-size:10.5px; color:var(--text-2); font-weight:700; font-variant-numeric:tabular-nums; }
.st.add .st-r{ background:var(--hairline); }
.st.add .st-a{ background:var(--surface); color:var(--blue); font-size:26px; box-shadow:inset 0 0 0 1px var(--hairline); }

/* ---------------- lista amici ---------------- */
.fr{ display:flex; align-items:center; gap:12px; width:100%; background:var(--surface); border:none; border-radius:16px; padding:12px; margin-bottom:9px; cursor:pointer; box-shadow:var(--raise-sm); transition:transform .2s var(--spring), box-shadow .2s; text-align:left; }
.fr:active{ transform:scale(.975); }
.fr:hover{ box-shadow:var(--shadow-card); }
.fr-a{ width:46px; height:46px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:18px; font-weight:800; box-shadow:inset 0 0 0 1.5px rgba(255,255,255,.25); position:relative; }
.fr-b{ flex:1; min-width:0; }
.fr-n{ font-size:15px; font-weight:800; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.fr-m{ font-size:12px; color:var(--text-2); font-weight:600; margin-top:2px; display:flex; align-items:center; gap:5px; }
.fr-m .dot{ width:6px; height:6px; border-radius:50%; background:var(--green); animation:pulse 2s infinite; }
.fr-r{ display:flex; align-items:center; gap:9px; flex-shrink:0; }
.fr-lv{ font-size:11px; font-weight:800; color:var(--text-2); background:var(--surface-2); padding:4px 9px; border-radius:999px; }
.fr-c{ color:var(--text-3); width:16px; height:16px; }

/* ---------------- bottoni ---------------- */
.btn{
    display:flex; align-items:center; justify-content:center; gap:8px; width:100%;
    border:none; cursor:pointer; padding:15px; border-radius:16px;
    font-size:15px; font-weight:800; color:#fff; background:var(--fx);
    box-shadow:0 10px 24px -10px rgba(255,59,122,.8);
    transition:transform .2s var(--spring), box-shadow .2s; margin-bottom:14px;
}
.btn:active{ transform:scale(.97); box-shadow:0 5px 14px -8px rgba(255,59,122,.8); }
.btn svg{ width:18px; height:18px; }
.btn.ghost{ background:var(--surface); color:var(--text); box-shadow:var(--raise-sm); }
.btn.ghost:active{ box-shadow:none; }

/* ---------------- richieste in sospeso ---------------- */
.req{ display:flex; align-items:center; gap:11px; padding:10px 0; border-bottom:.5px solid var(--hairline); }
.req:last-child{ border-bottom:none; padding-bottom:0; }
.req-a{ width:42px; height:42px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; }
.req-n{ flex:1; min-width:0; font-size:14px; font-weight:700; }
.req-n span{ display:block; font-size:11.5px; color:var(--text-2); font-weight:600; margin-top:1px; }
.mini{ border:none; cursor:pointer; font-weight:800; font-size:12.5px; padding:8px 14px; border-radius:999px; transition:transform .18s var(--spring); }
.mini:active{ transform:scale(.9); }
.mini[disabled]{ opacity:.45; pointer-events:none; }
.mini.ok{ background:var(--blue); color:#fff; }
.mini.no{ background:var(--surface-2); color:var(--text-2); }

/* ---------------- classifica ---------------- */
.seg{ display:flex; padding:3px; background:var(--surface-2); border-radius:12px; box-shadow:inset 0 0 0 .5px var(--hairline); position:relative; margin-bottom:16px; }
.seg::before{ content:""; position:absolute; top:3px; bottom:3px; left:3px; width:calc((100% - 6px) / 3); background:var(--surface); border-radius:9px; box-shadow:var(--raise-sm); transition:transform .42s var(--spring); }
.seg[data-p="settimana"]::before{ transform:translateX(100%); }
.seg[data-p="mese"]::before{ transform:translateX(200%); }
.seg button{ position:relative; z-index:1; flex:1; border:none; background:none; cursor:pointer; padding:9px 4px; font-size:13px; font-weight:700; color:var(--text-2); transition:color .3s; }
.seg button.on{ color:var(--text); }

.podio{ display:flex; align-items:flex-end; justify-content:center; gap:9px; margin:10px 0 6px; min-height:190px; }
.pd{ flex:1; max-width:112px; display:flex; flex-direction:column; align-items:center; }
.pd-a{ width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:21px; font-weight:800; box-shadow:inset 0 0 0 2px rgba(255,255,255,.3); position:relative; z-index:1; }
.pd.p1 .pd-a{ width:66px; height:66px; font-size:25px; box-shadow:inset 0 0 0 2px rgba(255,255,255,.35), 0 0 0 3px #FFC93C, 0 8px 26px -6px rgba(255,180,20,.85); }
.pd-crown{ font-size:22px; margin-bottom:-4px; animation:popIn .6s var(--spring) .5s both; }
.pd-n{ font-size:12.5px; font-weight:800; margin-top:7px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pd-t{ font-size:12px; font-weight:800; color:var(--text-2); font-variant-numeric:tabular-nums; margin-bottom:6px; }
.pd-col{ width:100%; border-radius:14px 14px 0 0; height:0; transition:height .9s var(--spring); display:flex; align-items:flex-start; justify-content:center; padding-top:8px; font-size:19px; font-weight:800; color:rgba(255,255,255,.95); }
.pd.p1 .pd-col{ background:var(--oro); box-shadow:0 -6px 24px -8px rgba(255,180,20,.7); }
.pd.p2 .pd-col{ background:var(--argento); color:rgba(0,0,0,.45); }
.pd.p3 .pd-col{ background:var(--bronzo); }
.pd.io .pd-n::after{ content:" (tu)"; color:var(--text-2); font-weight:700; }

.cl{ display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:.5px solid var(--hairline); }
.cl:last-child{ border-bottom:none; }
.cl-p{ width:22px; text-align:center; font-size:14px; font-weight:800; color:var(--text-3); font-variant-numeric:tabular-nums; flex-shrink:0; }
.cl-a{ width:40px; height:40px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:16px; }
.cl-n{ flex:1; min-width:0; font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cl-t{ font-size:15px; font-weight:800; font-variant-numeric:tabular-nums; flex-shrink:0; }
.cl.io{ background:var(--blue-soft); margin:0 -10px; padding:12px 10px; border-radius:14px; border-bottom:none; }
.nudge{ margin-top:14px; padding:13px 15px; border-radius:14px; background:var(--surface-2); font-size:13px; font-weight:700; line-height:1.45; display:flex; gap:9px; align-items:flex-start; }
.nudge b{ background:var(--fx); -webkit-background-clip:text; background-clip:text; color:transparent; }

/* ---------------- schermate impilate (duello / confronto) ---------------- */
.sub{
    position:fixed; inset:0; z-index:9400; background:var(--bg);
    transform:translateX(100%); transition:transform .42s var(--spring), visibility 0s linear .42s;
    display:flex; flex-direction:column; visibility:hidden;
}
.sub.on{ transform:none; visibility:visible; transition:transform .42s var(--spring), visibility 0s; }
.sub-h{
    position:sticky; top:0; z-index:2; display:flex; align-items:center; gap:10px;
    padding:calc(var(--safe-top) + 12px) 14px 12px;
    background:var(--header-glass); -webkit-backdrop-filter:blur(20px) saturate(1.8); backdrop-filter:blur(20px) saturate(1.8);
    border-bottom:.5px solid var(--hairline);
}
.sub-t{ font-size:17px; font-weight:800; letter-spacing:-.01em; }
.sub-b{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:16px 14px calc(30px + var(--safe-bottom)); }
.sub-b > *{ max-width:640px; margin-left:auto; margin-right:auto; }

/* tabellone del duello */
.vs{ display:flex; align-items:center; justify-content:center; gap:12px; padding:6px 0 18px; }
.vs-p{ flex:1; display:flex; flex-direction:column; align-items:center; gap:8px; max-width:130px; }
.vs-a{ width:66px; height:66px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:25px; font-weight:800; box-shadow:inset 0 0 0 2px rgba(255,255,255,.3); transition:box-shadow .5s; }
.vs-p.win .vs-a{ box-shadow:inset 0 0 0 2px rgba(255,255,255,.35), 0 0 0 3px #FFC93C, 0 8px 26px -8px rgba(255,180,20,.9); }
.vs-n{ font-size:13px; font-weight:800; text-align:center; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.vs-s{ font-size:38px; font-weight:800; letter-spacing:-.04em; font-variant-numeric:tabular-nums; }
.vs-mid{ text-align:center; }
.vs-lbl{ font-size:10px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:var(--text-3); }
.vs-esito{ text-align:center; font-size:13.5px; font-weight:700; color:var(--text-2); margin:-6px 0 18px; }
.vs-esito b{ color:var(--text); }

.duel{ background:var(--surface); border-radius:18px; padding:14px 15px; margin-bottom:10px; box-shadow:var(--raise-sm); animation:cardIn .45s var(--spring) both; }
.duel-h{ display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.duel-h svg{ width:15px; height:15px; color:var(--text-3); flex-shrink:0; }
.duel-h span{ font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-2); }
.duel-v{ display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:8px; }
.duel-v b{ font-size:19px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; color:var(--text-3); transition:color .4s; }
.duel-v b.w{ color:var(--text); }
.duel-track{ display:flex; height:9px; border-radius:5px; overflow:hidden; background:var(--surface-2); gap:2px; }
.duel-track i{ display:block; height:100%; border-radius:5px; width:50%; transition:width 1s var(--spring); background:var(--hairline-2); }
.duel-track i.w{ background:var(--fx); }
.duel-track i.me{ border-radius:5px 3px 3px 5px; }
.duel-track i.lui{ border-radius:3px 5px 5px 3px; }

/* confronto con tutti */
.mt{ background:var(--surface); border-radius:18px; padding:15px; margin-bottom:10px; box-shadow:var(--raise-sm); animation:cardIn .45s var(--spring) both; }
.mt-h{ display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.mt-h svg{ width:16px; height:16px; color:var(--text-3); }
.mt-h span{ font-size:13.5px; font-weight:800; }
.mr{ display:flex; align-items:center; gap:9px; margin-bottom:9px; }
.mr:last-child{ margin-bottom:0; }
.mr-a{ width:26px; height:26px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px; font-weight:800; }
.mr-n{ width:62px; flex-shrink:0; font-size:11.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-2); }
.mr.io .mr-n{ color:var(--text); font-weight:800; }
.mr-t{ flex:1; height:8px; border-radius:5px; background:var(--surface-2); overflow:hidden; }
.mr-t i{ display:block; height:100%; border-radius:5px; width:0; background:var(--hairline-2); transition:width .9s var(--spring); }
.mr.top .mr-t i{ background:var(--fx); }
.mr-v{ width:56px; text-align:right; flex-shrink:0; font-size:12px; font-weight:800; font-variant-numeric:tabular-nums; color:var(--text-2); }
.mr.top .mr-v{ color:var(--text); }
.mr-c{ width:16px; flex-shrink:0; font-size:12px; }

/* riepilogo confronto */
.sum{ background:var(--surface); border-radius:20px; padding:20px; margin-bottom:14px; box-shadow:var(--shadow-card); text-align:center; }
.sum-n{ font-size:44px; font-weight:800; letter-spacing:-.04em; background:var(--fx); -webkit-background-clip:text; background-clip:text; color:transparent; font-variant-numeric:tabular-nums; line-height:1.1; }
.sum-l{ font-size:13.5px; font-weight:700; color:var(--text-2); margin-top:4px; }

/* ---------------- foglio: aggiungi amico ---------------- */
.sheet-scrim{ position:fixed; inset:0; z-index:9700; background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(3px); backdrop-filter:blur(3px); opacity:0; pointer-events:none; transition:opacity .3s; }
.sheet-scrim.on{ opacity:1; pointer-events:auto; }
.sheet{
    position:fixed; left:0; right:0; bottom:0; z-index:9750;
    background:var(--surface); border-radius:26px 26px 0 0;
    padding:10px 16px calc(22px + var(--safe-bottom));
    transform:translateY(102%); transition:transform .46s var(--spring);
    max-height:86vh; display:flex; flex-direction:column;
    box-shadow:0 -14px 44px rgba(0,0,0,.28);
}
.sheet.on{ transform:none; }
.sheet-grip{ width:38px; height:5px; border-radius:3px; background:var(--hairline-2); margin:0 auto 14px; }
.sheet-t{ font-size:20px; font-weight:800; letter-spacing:-.02em; margin:0 0 4px; }
.sheet-s{ font-size:13px; color:var(--text-2); font-weight:500; margin:0 0 14px; }
.inp{ position:relative; margin-bottom:12px; }
.inp svg{ position:absolute; left:13px; top:50%; transform:translateY(-50%); width:17px; height:17px; color:var(--text-3); }
.inp input{
    width:100%; border:none; background:var(--surface-2); border-radius:13px;
    padding:14px 14px 14px 39px; font-size:16px; font-family:inherit; color:var(--text);
    box-shadow:inset 0 0 0 .5px var(--hairline); outline:none;
}
.inp input:focus{ box-shadow:inset 0 0 0 1.5px var(--blue); }
.res{ overflow-y:auto; -webkit-overflow-scrolling:touch; min-height:0; }
.res-i{ display:flex; align-items:center; gap:11px; padding:10px 0; border-bottom:.5px solid var(--hairline); animation:cardIn .3s var(--spring) both; }
.res-i:last-child{ border-bottom:none; }
.res-a{ width:44px; height:44px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:17px; }
.res-n{ flex:1; min-width:0; font-size:15px; font-weight:700; overflow:hidden; text-overflow:ellipsis; }

/* ---------------- toast + coriandoli ---------------- */
#toast{
    position:fixed; left:50%; bottom:calc(26px + var(--safe-bottom)); z-index:9900;
    transform:translate(-50%, 26px); opacity:0; pointer-events:none;
    background:rgba(28,28,30,.94); color:#fff; -webkit-backdrop-filter:blur(16px); backdrop-filter:blur(16px);
    padding:12px 20px; border-radius:999px; font-size:13.5px; font-weight:700;
    box-shadow:0 14px 34px rgba(0,0,0,.3); max-width:calc(100vw - 40px); text-align:center;
    transition:transform .4s var(--spring), opacity .3s;
}
#toast.on{ transform:translate(-50%, 0); opacity:1; }
@media (prefers-color-scheme: dark){ #toast{ background:rgba(240,240,245,.95); color:#111; } }
.conf{ position:fixed; top:-14px; width:9px; height:14px; z-index:9990; pointer-events:none; border-radius:2px; }
@keyframes cade{ to{ transform:translateY(102vh) rotate(720deg); opacity:0; } }

@media (prefers-reduced-motion: reduce){
    *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }
}
</style>
</head>
<body>

<div class="hdr">
    <div class="hdr-row">
        <a class="hdr-back" href="../index.php" aria-label="Torna alla home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="hdr-title">Amici</div>
        <?php if ($quantiFocus > 0): ?>
            <span class="hdr-live"><i></i><?php echo $quantiFocus; ?> in focus</span>
        <?php endif; ?>
        <?php include __DIR__ . '/../partials/notifiche_widget.php'; ?>
    </div>
    <div class="tabs" id="tabs" data-t="<?php echo htmlspecialchars($tabIniziale, ENT_QUOTES); ?>">
        <button type="button" data-tab="io">Io</button>
        <button type="button" data-tab="amici">Amici<?php echo count($amici) ? ' · ' . count($amici) : ''; ?></button>
        <button type="button" data-tab="classifica">Classifica</button>
    </div>
    <div class="hdr-sp"></div>
</div>

<div class="page">

<!-- ============================ IO ============================ -->
<section class="tab<?php echo $tabIniziale === "io" ? " on" : ""; ?>" id="tab-io">

    <div class="card">
        <div class="pro">
            <div class="pro-ring">
                <svg viewBox="0 0 88 88">
                    <circle cx="44" cy="44" r="40" fill="none" stroke="var(--hairline)" stroke-width="5"/>
                    <circle id="xpRing" cx="44" cy="44" r="40" fill="none" stroke="url(#gfx)" stroke-width="5" stroke-linecap="round"
                            stroke-dasharray="251.3" stroke-dashoffset="251.3" style="transition:stroke-dashoffset 1.2s cubic-bezier(.32,.72,0,1) .3s"/>
                    <defs><linearGradient id="gfx" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stop-color="#FFB302"/><stop offset="50%" stop-color="#FF3B7A"/><stop offset="100%" stop-color="#7B3FE4"/>
                    </linearGradient></defs>
                </svg>
                <div class="pro-ava" style="background:<?php echo $ioStats['gradiente']; ?>"><?php echo htmlspecialchars($ioStats['iniziale'], ENT_QUOTES); ?></div>
            </div>
            <div style="flex:1; min-width:0;">
                <h1 class="pro-nome"><?php echo htmlspecialchars($ioStats['username'], ENT_QUOTES); ?></h1>
                <div class="pro-liv">Livello <b><?php echo (int)$ioStats['livello']; ?></b></div>
                <div class="xp"><i id="xpBar"></i></div>
                <div class="xp-txt"><?php echo number_format($ioStats['xp'], 0, ',', '.'); ?> / <?php echo number_format($ioStats['xp_next'], 0, ',', '.'); ?> XP</div>
                <?php if (isset($inFocus[$me])): ?>
                    <div class="live-pill"><i></i>In focus da <?php echo (int)$inFocus[$me]; ?> min</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="scroll">
        <div class="streak <?php echo $ioStats['streak_fc'] > 0 ? 'hot' : ''; ?>" style="animation-delay:.03s">
            <span class="em">🔥</span>
            <div class="n"><?php echo (int)$ioStats['streak_fc']; ?></div>
            <div class="l">Streak flashcard</div>
            <div class="r">record <?php echo (int)$ioStats['streak_fc_rec']; ?></div>
        </div>
        <div class="streak <?php echo $ioStats['streak_pomo'] > 0 ? 'hot' : ''; ?>" style="animation-delay:.07s">
            <span class="em">🍅</span>
            <div class="n"><?php echo (int)$ioStats['streak_pomo']; ?></div>
            <div class="l">Streak pomodoro</div>
            <div class="r">record <?php echo (int)$ioStats['streak_pomo_rec']; ?></div>
        </div>
        <div class="streak gem" style="animation-delay:.11s">
            <span class="em">💎</span>
            <div class="n"><?php echo (int)$ioStats['streak_combo']; ?></div>
            <div class="l">Streak perfetta</div>
            <div class="r">giorni con entrambe</div>
        </div>
    </div>

    <div class="card" style="animation-delay:.05s">
        <div class="focus-big">
            <div>
                <div class="focus-n" data-count="<?php echo (int)$ioStats['focus_oggi']; ?>" data-fmt="durata"><?php echo fr_durata($ioStats['focus_oggi']); ?></div>
                <div class="focus-l">Focus di oggi</div>
            </div>
            <div class="spark" id="spark">
                <?php $maxS = max(1, max($ioStats['serie_7'])); foreach ($ioStats['serie_7'] as $k => $v): ?>
                    <i class="<?php echo $k === 6 ? 'oggi' : ''; ?>" data-h="<?php echo max(4, (int)round($v / $maxS * 52)); ?>"></i>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="grid4">
            <div class="tile">
                <div class="n"><?php echo fr_durata($ioStats['focus_record']); ?></div>
                <div class="l">Record in un giorno</div>
                <div class="s"><?php echo $ioStats['focus_record_d'] ? date('d/m/Y', strtotime($ioStats['focus_record_d'])) : '—'; ?></div>
            </div>
            <div class="tile">
                <div class="n"><?php echo fr_durata($ioStats['focus_media_7']); ?></div>
                <div class="l">Media settimana</div>
                <div class="s">al giorno, ultimi 7</div>
            </div>
            <div class="tile">
                <div class="n"><?php echo fr_durata($ioStats['focus_media_30']); ?></div>
                <div class="l">Media mese</div>
                <div class="s">al giorno, ultimi 30</div>
            </div>
            <div class="tile">
                <div class="n"><?php echo fr_durata($ioStats['focus_totale']); ?></div>
                <div class="l">Focus totale</div>
                <div class="s"><?php echo (int)$ioStats['focus_sessioni']; ?> sessioni</div>
            </div>
        </div>
    </div>

    <div class="card" style="animation-delay:.08s">
        <h2 class="t-sec">Categorie delle ultime 24 ore</h2>
        <p class="t-sub">Quanto ci hai messo e quanto ci hai preso.</p>
        <?php if (empty($ioStats['categorie'])): ?>
            <div class="vuoto">Nessuna categoria giocata nelle ultime 24 ore.<br><a href="../fc/fc_history.php" style="color:var(--blue); font-weight:800;">Fai una partita →</a></div>
        <?php else: $maxC = max(1, $ioStats['categorie'][0]['sec']); foreach ($ioStats['categorie'] as $cat): ?>
            <div class="cat">
                <span class="cat-dot" style="background:<?php echo htmlspecialchars($cat['colore'], ENT_QUOTES); ?>"></span>
                <div class="cat-b">
                    <div class="cat-n"><?php echo htmlspecialchars($cat['nome'], ENT_QUOTES); ?></div>
                    <div class="cat-m"><?php echo (int)$cat['carte']; ?> carte · <?php echo (int)$cat['pct']; ?>% corrette</div>
                    <div class="cat-bar"><i data-w="<?php echo (int)round($cat['sec'] / $maxC * 100); ?>" style="background:<?php echo htmlspecialchars($cat['colore'], ENT_QUOTES); ?>"></i></div>
                </div>
                <div class="cat-r">
                    <div class="cat-t"><?php echo fr_durata($cat['sec']); ?></div>
                    <div class="cat-p"><?php echo (int)$cat['pct']; ?>%</div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card" style="animation-delay:.11s">
        <h2 class="t-sec">Dove è finito il tuo focus</h2>
        <p class="t-sub">Il progetto che si è preso più tempo.</p>
        <div style="display:grid; gap:9px;">
            <?php
            $prjRender = function ($p, $etichetta) {
                if (!$p) {
                    echo '<div class="prj"><span class="prj-dot" style="background:var(--hairline-2)">–</span><div><div class="prj-l">' . $etichetta . '</div><div class="prj-n" style="color:var(--text-2)">Nessun progetto</div></div></div>';
                    return;
                }
                $col = htmlspecialchars($p['colore'], ENT_QUOTES);
                echo '<div class="prj"><span class="prj-dot" style="background:' . $col . '">◆</span>'
                   . '<div style="min-width:0"><div class="prj-l">' . $etichetta . '</div>'
                   . '<div class="prj-n">' . htmlspecialchars($p['nome'], ENT_QUOTES) . '</div></div>'
                   . '<span class="prj-t">' . fr_durata($p['sec']) . '</span></div>';
            };
            $prjRender($ioStats['progetto_oggi'], 'Oggi');
            $prjRender($ioStats['progetto_sett'], 'Questa settimana');
            ?>
        </div>
    </div>

    <div class="card" style="animation-delay:.14s">
        <h2 class="t-sec">Traguardi</h2>
        <p class="t-sub"><?php echo (int)$ioStats['badge_fatti']; ?> su <?php echo (int)$ioStats['badge_totali']; ?> sbloccati.</p>
        <div class="bg-grid">
            <?php foreach ($ioStats['badge'] as $i => $b): ?>
                <div class="bg <?php echo $b['v'] ? 'on' : ''; ?>" style="animation-delay:<?php echo 0.16 + $i * 0.03; ?>s">
                    <span class="bg-i"><?php echo fr_icona($b['t']); ?></span>
                    <span class="bg-l"><?php echo htmlspecialchars($b['l'], ENT_QUOTES); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================ AMICI ============================ -->
<section class="tab<?php echo $tabIniziale === "amici" ? " on" : ""; ?>" id="tab-amici">

    <div class="storie">
        <button type="button" class="st add" id="btnAdd">
            <div class="st-r"><div class="st-in"><div class="st-a">+</div></div></div>
            <div class="st-n">Aggiungi</div>
            <div class="st-s">&nbsp;</div>
        </button>
        <?php foreach ($amici as $a):
            $att  = in_array((int)$a['uid'], $attiviOggi, true);
            $live = isset($inFocus[(int)$a['uid']]);
        ?>
            <button type="button" class="st" data-duello="<?php echo (int)$a['uid']; ?>">
                <div class="st-r <?php echo $att ? 'att' : ''; ?>">
                    <div class="st-in"><div class="st-a" style="background:<?php echo $a['gradiente']; ?>"><?php echo htmlspecialchars($a['iniziale'], ENT_QUOTES); ?></div></div>
                    <?php if ($live): ?><span class="st-live"></span><?php endif; ?>
                </div>
                <div class="st-n"><?php echo htmlspecialchars($a['username'], ENT_QUOTES); ?></div>
                <div class="st-s"><?php echo $a['focus_oggi'] > 0 ? fr_durata($a['focus_oggi']) : '—'; ?></div>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($richieste)): ?>
    <div class="card" id="cardRichieste">
        <h2 class="t-sec">Richieste di amicizia</h2>
        <p class="t-sub">Ti stanno aspettando.</p>
        <?php foreach ($richieste as $r): ?>
            <div class="req" data-req="<?php echo (int)$r['uid']; ?>">
                <span class="req-a" style="background:<?php echo fr_gradiente((int)$r['uid']); ?>"><?php echo htmlspecialchars(fr_iniziale($r['username']), ENT_QUOTES); ?></span>
                <div class="req-n"><?php echo htmlspecialchars($r['username'], ENT_QUOTES); ?><span><?php echo htmlspecialchars(fr_quando($r['quando']), ENT_QUOTES); ?></span></div>
                <button type="button" class="mini ok" data-az="accetta">Accetta</button>
                <button type="button" class="mini no" data-az="rifiuta">Rifiuta</button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($amici)): ?>
        <div class="card">
            <div class="vuoto" style="padding:34px 12px 30px;">
                <div style="font-size:40px; margin-bottom:10px;">👋</div>
                <div style="font-size:16px; font-weight:800; color:var(--text); margin-bottom:6px;">Qui non c'è ancora nessuno</div>
                Aggiungi il primo amico: da domani vedrete<br>chi tiene più a lungo la concentrazione.
            </div>
            <button type="button" class="btn" id="btnAdd2" style="margin-bottom:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                Cerca un amico
            </button>
        </div>
    <?php else: ?>
        <button type="button" class="btn" id="btnTutti">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3a2 2 0 0 1-2 4M7 5H4a2 2 0 0 0 2 4"/></svg>
            Confronto con tutti
        </button>

        <?php foreach ($amici as $a):
            $live = isset($inFocus[(int)$a['uid']]);
        ?>
            <button type="button" class="fr" data-duello="<?php echo (int)$a['uid']; ?>">
                <span class="fr-a" style="background:<?php echo $a['gradiente']; ?>"><?php echo htmlspecialchars($a['iniziale'], ENT_QUOTES); ?></span>
                <span class="fr-b">
                    <span class="fr-n"><?php echo htmlspecialchars($a['username'], ENT_QUOTES); ?></span>
                    <span class="fr-m">
                        <?php if ($live): ?>
                            <span class="dot"></span>In focus adesso
                        <?php else: ?>
                            🔥 <?php echo (int)$a['streak_pomo']; ?> · oggi <?php echo fr_durata($a['focus_oggi']); ?>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="fr-r">
                    <span class="fr-lv">Liv <?php echo (int)$a['livello']; ?></span>
                    <svg class="fr-c" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </span>
            </button>
        <?php endforeach; ?>

        <button type="button" class="btn ghost" id="btnAdd3">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
            Aggiungi un altro amico
        </button>
    <?php endif; ?>
</section>

<!-- ========================== CLASSIFICA ========================== -->
<section class="tab<?php echo $tabIniziale === "classifica" ? " on" : ""; ?>" id="tab-classifica">
    <div class="seg" id="segPeriodo" data-p="oggi">
        <button type="button" data-p="oggi">Oggi</button>
        <button type="button" data-p="settimana">Settimana</button>
        <button type="button" data-p="mese">Mese</button>
    </div>

    <div class="card" id="cardClassifica">
        <h2 class="t-sec">Chi è stato più concentrato</h2>
        <p class="t-sub" id="clSub">Tempo di focus di oggi, tutti gli utenti.</p>
        <div id="clBody"></div>
    </div>
</section>

</div>

<!-- ---------------- schermata: duello 1 contro 1 ---------------- -->
<div class="sub" id="subDuello">
    <div class="sub-h">
        <button type="button" class="hdr-back" data-chiudi>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <span class="sub-t" id="duelloT">Duello</span>
    </div>
    <div class="sub-b" id="duelloB"></div>
</div>

<!-- ---------------- schermata: confronto con tutti ---------------- -->
<div class="sub" id="subTutti">
    <div class="sub-h">
        <button type="button" class="hdr-back" data-chiudi>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <span class="sub-t">Confronto con tutti</span>
    </div>
    <div class="sub-b" id="tuttiB"></div>
</div>

<!-- ---------------- foglio: aggiungi amico ---------------- -->
<div class="sheet-scrim" id="sheetScrim"></div>
<div class="sheet" id="sheet" role="dialog" aria-label="Aggiungi un amico">
    <div class="sheet-grip"></div>
    <h2 class="sheet-t">Aggiungi un amico</h2>
    <p class="sheet-s">Cerca per nome utente. Basta che sia iscritto.</p>
    <div class="inp">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="text" id="cerca" placeholder="Nome utente…" autocomplete="off" autocapitalize="none" spellcheck="false">
    </div>
    <div class="res" id="risultati"></div>
</div>

<div id="toast"></div>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<script>
(function () {
'use strict';
var FR = <?php echo json_encode($FRJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var ICONE = <?php
    $ic = [];
    foreach ($metriche as $m) { $ic[$m['i']] = fr_icona($m['i']); }
    echo json_encode($ic, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

/* ---------------- utilità ---------------- */
function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function durata(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
    if (h > 0) { return h + 'h ' + String(m).padStart(2, '0') + 'm'; }
    if (m > 0) { return m + 'm'; }
    return sec > 0 ? '<1m' : '0m';
}
function fmt(v, f) {
    if (f === 'durata') { return durata(v); }
    if (f === 'pct')    { return (parseInt(v, 10) || 0) + '%'; }
    if (f === 'giorni') { var n = parseInt(v, 10) || 0; return n + (n === 1 ? ' giorno' : ' giorni'); }
    return String(parseInt(v, 10) || 0);
}

var toastT;
window.frToast = function (msg) {
    var t = document.getElementById('toast');
    if (!t || !msg) { return; }
    t.textContent = msg;
    t.classList.add('on');
    clearTimeout(toastT);
    toastT = setTimeout(function () { t.classList.remove('on'); }, 2600);
};

window.frCoriandoli = function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    var col = ['#FFB302', '#FF3B7A', '#7B3FE4', '#34C759', '#007AFF', '#FFD84D'];
    for (var i = 0; i < 34; i++) {
        var d = document.createElement('i');
        d.className = 'conf';
        d.style.left = Math.random() * 100 + 'vw';
        d.style.background = col[i % col.length];
        d.style.opacity = '1';
        d.style.animation = 'cade ' + (1.5 + Math.random() * 1.1) + 's cubic-bezier(.2,.6,.5,1) ' + (Math.random() * .35) + 's forwards';
        document.body.appendChild(d);
        (function (el) { setTimeout(function () { el.remove(); }, 3200); })(d);
    }
};

window.frRicarica = function () { setTimeout(function () { location.reload(); }, 1300); };

function api(azione, dati) {
    var body = new URLSearchParams(Object.assign({ azione: azione }, dati || {}));
    return fetch('friends_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Standout': '1' },
        body: body, credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
}

/* conta alla rovescia dei numeri: i numeri che salgono si guardano volentieri */
function contaSu(el, a, f) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = fmt(a, f); return; }
    var t0 = performance.now(), dur = 900;
    function step(t) {
        var p = Math.min(1, (t - t0) / dur);
        var e = 1 - Math.pow(1 - p, 4);
        el.textContent = fmt(Math.round(a * e), f);
        if (p < 1) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
}

/* ---------------- schede ---------------- */
var tabs = document.getElementById('tabs');
function vaiA(nome) {
    tabs.dataset.t = nome;
    tabs.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === nome); });
    document.querySelectorAll('.tab').forEach(function (s) { s.classList.toggle('on', s.id === 'tab-' + nome); });
    window.scrollTo(0, 0);
    if (nome === 'classifica' && !clCaricata) { caricaClassifica('oggi'); }
    try { history.replaceState(null, '', 'friends.php?tab=' + nome); } catch (e) {}
}
tabs.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-tab]');
    if (b) { buzz(8); vaiA(b.dataset.tab); }
});

/* ---------------- animazioni d'ingresso ---------------- */
requestAnimationFrame(function () {
    var r = document.getElementById('xpRing');
    if (r) { r.style.strokeDashoffset = String(251.3 * (1 - FR.me.xp_pct / 100)); }
    var x = document.getElementById('xpBar');
    if (x) { x.style.width = FR.me.xp_pct + '%'; }
    document.querySelectorAll('#spark i').forEach(function (i, k) {
        setTimeout(function () { i.style.height = i.dataset.h + 'px'; }, 60 + k * 45);
    });
    document.querySelectorAll('.cat-bar i').forEach(function (i, k) {
        setTimeout(function () { i.style.width = i.dataset.w + '%'; }, 140 + k * 70);
    });
    var fn = document.querySelector('.focus-n');
    if (fn) { contaSu(fn, parseInt(fn.dataset.count, 10) || 0, 'durata'); }
});

/* ---------------- duello 1 contro 1 ---------------- */
var subD = document.getElementById('subDuello');
var subT = document.getElementById('subTutti');

function apriSub(el) {
    el.classList.add('on');
    document.documentElement.classList.add('fr-lock');
    buzz(12);
}
function chiudiSub() {
    [subD, subT].forEach(function (s) { s.classList.remove('on'); });
    document.documentElement.classList.remove('fr-lock');
}
document.querySelectorAll('[data-chiudi]').forEach(function (b) { b.addEventListener('click', chiudiSub); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { chiudiSub(); chiudiSheet(); } });

function trovaAmico(uid) {
    for (var i = 0; i < FR.amici.length; i++) { if (FR.amici[i].uid === uid) { return FR.amici[i]; } }
    return null;
}

function duello(uid) {
    var lui = trovaAmico(uid);
    if (!lui) { return; }
    var io = FR.me;
    document.getElementById('duelloT').textContent = 'Tu contro ' + lui.username;

    var vinteIo = 0, vinteLui = 0, righe = '';
    FR.metriche.forEach(function (m) {
        var a = parseInt(io[m.k], 10) || 0;
        var b = parseInt(lui[m.k], 10) || 0;
        if (a > b) { vinteIo++; } else if (b > a) { vinteLui++; }
        var tot = a + b;
        var pa = tot > 0 ? Math.round(a / tot * 100) : 50;
        righe +=
            '<div class="duel">' +
              '<div class="duel-h">' + (ICONE[m.i] || '') + '<span>' + esc(m.l) + '</span></div>' +
              '<div class="duel-v">' +
                '<b class="' + (a > b ? 'w' : '') + '">' + (a > b ? '👑 ' : '') + fmt(a, m.f) + '</b>' +
                '<b class="' + (b > a ? 'w' : '') + '">' + fmt(b, m.f) + (b > a ? ' 👑' : '') + '</b>' +
              '</div>' +
              '<div class="duel-track">' +
                '<i class="me ' + (a > b ? 'w' : '') + '" data-w="' + pa + '"></i>' +
                '<i class="lui ' + (b > a ? 'w' : '') + '" data-w="' + (100 - pa) + '"></i>' +
              '</div>' +
            '</div>';
    });

    var esito = vinteIo > vinteLui
        ? 'Sei avanti di <b>' + (vinteIo - vinteLui) + '</b>. Tieni il ritmo.'
        : (vinteLui > vinteIo
            ? 'Sei sotto di <b>' + (vinteLui - vinteIo) + '</b>. Una sessione di focus e recuperi.'
            : 'Siete <b>pari</b>. La prossima sessione decide.');

    document.getElementById('duelloB').innerHTML =
        '<div class="vs">' +
          '<div class="vs-p ' + (vinteIo > vinteLui ? 'win' : '') + '">' +
            '<div class="vs-a" style="background:' + io.gradiente + '">' + esc(io.iniziale) + '</div>' +
            '<div class="vs-n">Tu</div></div>' +
          '<div class="vs-mid"><div class="vs-s">' + vinteIo + ' – ' + vinteLui + '</div><div class="vs-lbl">metriche vinte</div></div>' +
          '<div class="vs-p ' + (vinteLui > vinteIo ? 'win' : '') + '">' +
            '<div class="vs-a" style="background:' + lui.gradiente + '">' + esc(lui.iniziale) + '</div>' +
            '<div class="vs-n">' + esc(lui.username) + '</div></div>' +
        '</div>' +
        '<div class="vs-esito">' + esito + '</div>' +
        righe +
        '<button type="button" class="btn ghost" data-rimuovi="' + lui.uid + '" style="margin-top:16px; color:var(--red);">Rimuovi amicizia</button>';

    apriSub(subD);
    requestAnimationFrame(function () {
        document.querySelectorAll('#duelloB .duel-track i').forEach(function (i, k) {
            setTimeout(function () { i.style.width = i.dataset.w + '%'; }, 90 + Math.floor(k / 2) * 55);
        });
    });
    if (vinteIo > vinteLui) { setTimeout(window.frCoriandoli, 380); }
}

document.addEventListener('click', function (e) {
    var d = e.target.closest('[data-duello]');
    if (d) { buzz(10); duello(parseInt(d.dataset.duello, 10)); return; }

    var rm = e.target.closest('[data-rimuovi]');
    if (rm) {
        if (!confirm('Vuoi davvero rimuovere questa amicizia?')) { return; }
        rm.disabled = true;
        api('rimuovi', { uid: rm.dataset.rimuovi }).then(function (r) {
            if (r && r.ok) { window.frToast(r.messaggio); chiudiSub(); window.frRicarica(); }
        });
    }
});

/* ---------------- confronto con tutti ---------------- */
document.getElementById('btnTutti') && document.getElementById('btnTutti').addEventListener('click', function () {
    var tutti = [FR.me].concat(FR.amici);
    var primi = 0, html = '';

    FR.metriche.forEach(function (m) {
        var vals = tutti.map(function (u) { return parseInt(u[m.k], 10) || 0; });
        var max = Math.max.apply(null, vals);
        var ord = tutti.map(function (u, i) { return { u: u, v: vals[i] }; })
                       .sort(function (a, b) { return b.v - a.v; });
        if (max > 0 && ord[0].u.uid === FR.me.uid && ord[0].v > (ord[1] ? ord[1].v : -1)) { primi++; }

        var righe = ord.map(function (o) {
            var top = max > 0 && o.v === max;
            var io  = o.u.uid === FR.me.uid;
            return '<div class="mr ' + (top ? 'top ' : '') + (io ? 'io' : '') + '">' +
                     '<span class="mr-a" style="background:' + o.u.gradiente + '">' + esc(o.u.iniziale) + '</span>' +
                     '<span class="mr-n">' + (io ? 'Tu' : esc(o.u.username)) + '</span>' +
                     '<span class="mr-t"><i data-w="' + (max > 0 ? Math.round(o.v / max * 100) : 0) + '"></i></span>' +
                     '<span class="mr-v">' + fmt(o.v, m.f) + '</span>' +
                     '<span class="mr-c">' + (top ? '👑' : '') + '</span>' +
                   '</div>';
        }).join('');

        html += '<div class="mt"><div class="mt-h">' + (ICONE[m.i] || '') + '<span>' + esc(m.l) + '</span></div>' + righe + '</div>';
    });

    document.getElementById('tuttiB').innerHTML =
        '<div class="sum"><div class="sum-n">' + primi + ' / ' + FR.metriche.length + '</div>' +
        '<div class="sum-l">' + (primi === 0
            ? 'Nessun primato, per ora. Il bello è che si ribalta in fretta.'
            : 'metriche in cui sei il migliore del gruppo') + '</div></div>' + html;

    apriSub(subT);
    requestAnimationFrame(function () {
        document.querySelectorAll('#tuttiB .mr-t i').forEach(function (i, k) {
            setTimeout(function () { i.style.width = i.dataset.w + '%'; }, 60 + k * 28);
        });
    });
    if (primi > FR.metriche.length / 2) { setTimeout(window.frCoriandoli, 420); }
});

/* ---------------- richieste in sospeso (scheda Amici) ---------------- */
document.addEventListener('click', function (e) {
    var b = e.target.closest('.req .mini');
    if (!b) { return; }
    var riga = b.closest('.req');
    var uid  = riga.dataset.req;
    var az   = b.dataset.az;
    riga.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
    buzz(az === 'accetta' ? 22 : 8);

    api(az, { uid: uid }).then(function (r) {
        if (!r || !r.ok) { throw new Error(); }
        if (az === 'accetta') { window.frCoriandoli(); }
        window.frToast(r.messaggio);
        if (window.FNW) { window.FNW.setBadge(r.n); }
        riga.style.transition = 'opacity .3s, transform .3s';
        riga.style.opacity = '0';
        riga.style.transform = 'translateX(24px)';
        setTimeout(function () { riga.remove(); }, 320);
        window.frRicarica();
    }).catch(function () {
        riga.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
        window.frToast('Non ha funzionato. Riprova.');
    });
});

/* ---------------- classifica ---------------- */
var clCaricata = false;
var seg = document.getElementById('segPeriodo');

function caricaClassifica(periodo) {
    var body = document.getElementById('clBody');
    body.innerHTML = '<div class="vuoto">Carico…</div>';
    api('classifica', { periodo: periodo }).then(function (r) {
        if (!r || !r.ok) { throw new Error(); }
        clCaricata = true;
        disegnaClassifica(r.righe, periodo);
    }).catch(function () {
        body.innerHTML = '<div class="vuoto">Classifica non disponibile. Riprova.</div>';
    });
}

function disegnaClassifica(righe, periodo) {
    var sub = { oggi: 'Tempo di focus di oggi, tutti gli utenti.',
                settimana: 'Tempo di focus degli ultimi 7 giorni.',
                mese: 'Tempo di focus degli ultimi 30 giorni.' };
    document.getElementById('clSub').textContent = sub[periodo];
    var body = document.getElementById('clBody');

    if (!righe.length) {
        body.innerHTML = '<div class="vuoto" style="padding:30px 12px;">'
            + '<div style="font-size:34px; margin-bottom:8px;">🍅</div>'
            + 'Nessuno ha ancora fatto focus.<br><b style="color:var(--text)">Sii il primo: bastano 25 minuti.</b></div>';
        return;
    }

    var top = righe.slice(0, 3);
    var maxSec = top[0].sec;
    var ordine = [top[1], top[0], top[2]];   // 2° · 1° · 3°
    var alt = { 0: 78, 1: 108, 2: 60 };

    var html = '<div class="podio">';
    ordine.forEach(function (p, i) {
        if (!p) { html += '<div class="pd" style="visibility:hidden"></div>'; return; }
        var cls = 'p' + p.pos + (p.uid === FR.me.uid ? ' io' : '');
        html += '<div class="pd ' + cls + '">' +
                  (p.pos === 1 ? '<div class="pd-crown">👑</div>' : '') +
                  '<div class="pd-a" style="background:' + p.gradiente + '">' + esc(p.iniziale) + '</div>' +
                  '<div class="pd-n">' + esc(p.username) + '</div>' +
                  '<div class="pd-t">' + durata(p.sec) + '</div>' +
                  '<div class="pd-col" data-h="' + alt[i] + '">' + p.pos + '</div>' +
                '</div>';
    });
    html += '</div>';

    righe.slice(3).forEach(function (p) {
        html += '<div class="cl ' + (p.uid === FR.me.uid ? 'io' : '') + '">' +
                  '<span class="cl-p">' + p.pos + '</span>' +
                  '<span class="cl-a" style="background:' + p.gradiente + '">' + esc(p.iniziale) + '</span>' +
                  '<span class="cl-n">' + esc(p.username) + (p.uid === FR.me.uid ? ' (tu)' : '') + '</span>' +
                  '<span class="cl-t">' + durata(p.sec) + '</span>' +
                '</div>';
    });

    /* la spinta: la distanza esatta da chi ti sta davanti */
    var mio = null, idx = -1;
    righe.forEach(function (p, i) { if (p.uid === FR.me.uid) { mio = p; idx = i; } });
    var spinta;
    if (!mio) {
        spinta = '<span>🎯</span><div>Non sei ancora in classifica. <b>Una sessione da 25 minuti</b> e ci entri.</div>';
    } else if (idx === 0) {
        spinta = '<span>👑</span><div>Sei in testa con <b>' + durata(mio.sec) + '</b>. Il secondo è a ' +
                 (righe[1] ? durata(mio.sec - righe[1].sec) : durata(mio.sec)) + ' da te.</div>';
    } else {
        var sopra = righe[idx - 1];
        spinta = '<span>⚡</span><div>Ti mancano <b>' + durata(sopra.sec - mio.sec + 60) +
                 '</b> per superare ' + esc(sopra.username) + '.</div>';
    }
    html += '<div class="nudge">' + spinta + '</div>';

    body.innerHTML = html;
    requestAnimationFrame(function () {
        body.querySelectorAll('.pd-col').forEach(function (c, k) {
            setTimeout(function () { c.style.height = c.dataset.h + 'px'; }, 90 + k * 90);
        });
    });
}

seg.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-p]');
    if (!b) { return; }
    buzz(8);
    seg.dataset.p = b.dataset.p;
    seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    caricaClassifica(b.dataset.p);
});

/* ---------------- foglio: aggiungi amico ---------------- */
var sheet = document.getElementById('sheet');
var sheetScrim = document.getElementById('sheetScrim');
var cerca = document.getElementById('cerca');
var risultati = document.getElementById('risultati');

function apriSheet() {
    sheet.classList.add('on');
    sheetScrim.classList.add('on');
    document.documentElement.classList.add('fr-lock');
    setTimeout(function () { cerca.focus(); }, 320);
    buzz(10);
}
function chiudiSheet() {
    sheet.classList.remove('on');
    sheetScrim.classList.remove('on');
    document.documentElement.classList.remove('fr-lock');
}
['btnAdd', 'btnAdd2', 'btnAdd3'].forEach(function (id) {
    var b = document.getElementById(id);
    if (b) { b.addEventListener('click', apriSheet); }
});
sheetScrim.addEventListener('click', chiudiSheet);

var tCerca;
cerca.addEventListener('input', function () {
    clearTimeout(tCerca);
    var q = cerca.value.trim();
    if (q.length < 1) { risultati.innerHTML = ''; return; }
    tCerca = setTimeout(function () {
        api('cerca', { q: q }).then(function (r) {
            if (!r || !r.ok) { throw new Error(); }
            if (!r.risultati.length) {
                risultati.innerHTML = '<div class="vuoto">Nessun utente con questo nome.</div>';
                return;
            }
            risultati.innerHTML = r.risultati.map(function (u) {
                var b;
                if (u.stato === 'amico')         { b = '<button class="mini no" disabled>Già amici</button>'; }
                else if (u.stato === 'inviata')  { b = '<button class="mini no" disabled>Inviata</button>'; }
                else if (u.stato === 'ricevuta') { b = '<button class="mini ok" data-add="' + u.uid + '">Accetta</button>'; }
                else                             { b = '<button class="mini ok" data-add="' + u.uid + '">Aggiungi</button>'; }
                return '<div class="res-i">' +
                         '<span class="res-a" style="background:' + u.gradiente + '">' + esc(u.iniziale) + '</span>' +
                         '<span class="res-n">' + esc(u.username) + '</span>' + b +
                       '</div>';
            }).join('');
        }).catch(function () { risultati.innerHTML = '<div class="vuoto">Ricerca non riuscita.</div>'; });
    }, 260);
});

risultati.addEventListener('click', function (e) {
    var b = e.target.closest('[data-add]');
    if (!b) { return; }
    b.disabled = true;
    buzz(16);
    api('richiedi', { uid: b.dataset.add }).then(function (r) {
        if (!r || !r.ok) { throw new Error((r && r.errore) || ''); }
        b.textContent = r.stato === 'amico' ? 'Amici!' : 'Inviata';
        b.className = 'mini no';
        window.frToast(r.messaggio);
        if (r.stato === 'amico') { window.frCoriandoli(); window.frRicarica(); }
    }).catch(function (err) {
        b.disabled = false;
        window.frToast((err && err.message) || 'Non ha funzionato. Riprova.');
    });
});

/* ---------------- avvio ---------------- */
vaiA(FR.tab);
if (FR.apriDuello > 0 && trovaAmico(FR.apriDuello)) {
    setTimeout(function () { duello(FR.apriDuello); }, 420);
}
})();
</script>
</body>
</html>
