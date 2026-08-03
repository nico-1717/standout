<?php
/* =====================================================================
   NISAMA — ATTIVITÀ (componente condiviso)
   Da includere una volta sola in fondo alla pagina:

       include 'todo_widget.php';

   Poi si monta dove serve:

       nisamaTodo.mount(document.getElementById('box'), {
           scope: 'all' | 'project' | 'pomodoro',
           projectId: 12,          // per scope 'project'
           compact: false,         // niente data/ora/durata/progetto (pomodoro)
           selectable: true,       // modalità seleziona (pagina principale)
           tabs: true              // to do / to buy / to study (solo scope 'all')
       });

   Stessa estetica di planner e diario: carta calda, matita, angoli morbidi.
   ===================================================================== */
if (!defined('NISAMA_TODO_WIDGET')):
define('NISAMA_TODO_WIDGET', true);
if (!function_exists('sec_csrf_token')) { require_once __DIR__ . '/security_core.php'; }
?>
<style>
.td-wrap{
    /* niente ingrandimento automatico e niente zoom al doppio tocco */
    touch-action:manipulation;
    -webkit-text-size-adjust:100%; text-size-adjust:100%;
    --td-paper:#FDFCF9; --td-panel:#F5F1EA; --td-ink:#4A443D; --td-ink-2:#8A8177;
    --td-ink-3:#B7AEA3; --td-hair:rgba(74,68,61,.12); --td-accent:#A9C3AE;
    --td-accent-soft:#E7EFE8; --td-late:#B4645F; --td-late-soft:#F6E4E2;
    --td-serif:'Cormorant Garamond', Georgia, serif;
    --td-sans:'Jost', -apple-system, system-ui, sans-serif;
    font-family:var(--td-sans); color:var(--td-ink);
}
/* dentro il planner o il diario eredita le tinte già scelte dall'utente */
.pl-body .td-wrap, .dy-body .td-wrap{ --td-accent:var(--accent, #A9C3AE); --td-accent-soft:var(--accent-wash, #E7EFE8); }

.td-list{ display:flex; flex-direction:column; }

/* popup per dare data e ora a più attività insieme */
.td-modal{
    position:fixed; inset:0; z-index:9850; display:grid; place-items:center; padding:24px;
    background:rgba(74,68,61,.28); -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
    animation:td-fade .22s ease;
}
@keyframes td-fade{ from{ opacity:0; } to{ opacity:1; } }
.td-card{
    width:100%; max-width:320px; background:#FDFCF9; border-radius:22px; padding:20px 18px 14px;
    box-shadow:0 24px 54px -22px rgba(74,68,61,.5);
    font-family:'Jost', system-ui, sans-serif; color:#4A443D;
    animation:td-pop .3s cubic-bezier(.34,1.3,.64,1);
    max-height:82vh; overflow-y:auto; -webkit-overflow-scrolling:touch;
}
@keyframes td-pop{ from{ transform:scale(.93); } to{ transform:none; } }
.td-card-t{ font-family:'Cormorant Garamond', serif; font-size:21px; margin-bottom:4px; }
.td-card-s{ font-size:12.5px; color:#8A8177; line-height:1.45; margin-bottom:14px; }
.td-field{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:.5px solid rgba(74,68,61,.1); }
.td-field span{ font-size:14px; }
.td-field input{
    border:none; background:#F5F1EA; border-radius:10px; padding:8px 10px;
    font:inherit; font-size:14px; color:#4A443D; text-align:center; min-width:104px;
}
.td-card-acts{ display:flex; gap:8px; margin-top:16px; }
.td-card-acts button{
    flex:1; padding:11px; border-radius:13px; border:none; cursor:pointer;
    background:#F5F1EA; color:#8A8177; font:inherit; font-size:14px;
}
.td-card-acts button.td-primary{ background:var(--td-accent, #A9C3AE); color:#FFFDFA; }

/* immagine a tutto schermo: niente scheda intorno, solo la foto e i comandi */
.td-modal-img{ padding:16px; }
.td-imgview{ display:flex; flex-direction:column; align-items:stretch; width:auto; max-width:min(560px, 94vw); }
.td-imgview img{
    display:block; max-width:100%; max-height:70dvh; margin:0 auto;
    border-radius:18px; background:#FDFCF9; object-fit:contain;
    box-shadow:0 24px 60px -24px rgba(0,0,0,.55);
}
.td-imgview .td-card-acts{ margin-top:14px; }

/* Riga che si sta modificando: sfondo diverso per dire che è ferma dov'era e
   non in ordine con le altre. Il colore è quello dell'accento molto diluito —
   deve farsi notare al primo sguardo senza sembrare un errore. */
.td-item.td-in-modifica{
    background:color-mix(in srgb, var(--td-accent, #A9C3AE) 15%, var(--td-paper));
    border-radius:13px;
    box-shadow:inset 0 0 0 .5px color-mix(in srgb, var(--td-accent, #A9C3AE) 35%, transparent);
    padding-left:8px; padding-right:8px;
    transition:background-color .25s ease;
}

/* ---------- pastiglie ---------- */
.td-in{
    width:100%; padding:9px 11px; border-radius:11px; border:none; outline:none;
    background:var(--td-panel); color:var(--td-ink); font:inherit; font-size:14px;
}
.td-pill-field{ margin-bottom:10px; }
.td-pill-lab{ display:block; font-size:10px; letter-spacing:.16em; text-transform:uppercase; color:var(--td-ink-3); margin-bottom:5px; }
/* l'ora fa da titolo al gruppo: le prese si leggono a colpo d'occhio in ordine
   di orologio, che è come uno se le ricorda */
.td-pill-hour{
    display:flex; align-items:baseline; gap:8px; margin:14px 0 6px;
    font-size:12px; letter-spacing:.1em; color:var(--td-ink-3);
}
.td-pill-late{ color:#B08A8A; font-size:11px; letter-spacing:0; }
.td-pill-row{ display:flex; align-items:center; gap:11px; padding:7px 0; }
.td-pill-check{
    width:30px; height:30px; flex-shrink:0; border-radius:50%; cursor:pointer;
    display:grid; place-items:center; padding:0;
    border:1.5px solid color-mix(in srgb, var(--pill, #B7A6D6) 60%, transparent);
    background:none; color:#FFFDFA;
}
.td-pill-check svg{ width:16px; height:16px; }
.td-pill-taken .td-pill-check{ background:var(--pill, #B7A6D6); border-color:var(--pill, #B7A6D6); }
.td-pill-skipped .td-pill-check{ border-style:dashed; color:var(--td-ink-3); }
.td-pill-taken .td-pill-txt, .td-pill-skipped .td-pill-txt{ opacity:.5; }
.td-pill-txt{ min-width:0; }
.td-pill-name{ font-size:14.5px; }
.td-pill-sub{ font-size:11.5px; color:var(--td-ink-3); }
.td-pill-stats{ margin-top:14px; font-size:11.5px; color:var(--td-ink-3); }
.td-pill-sect{
    margin:22px 0 8px; font-size:10px; letter-spacing:.2em;
    text-transform:uppercase; color:var(--td-ink-3);
}
.td-pill-plan{
    display:flex; align-items:center; gap:10px; width:100%; text-align:left;
    padding:9px 11px; margin-bottom:6px; border:none; border-radius:13px;
    background:var(--td-panel); color:var(--td-ink); font:inherit; cursor:pointer;
}
.td-pill-plan.td-off{ opacity:.5; }
.td-pill-planbox{ display:flex; flex-direction:column; min-width:0; }
.td-pill-dot{ width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.td-pill-seg{ display:flex; gap:4px; }
.td-pill-segb{
    flex:1; padding:7px 6px; border:none; border-radius:10px; cursor:pointer;
    background:var(--td-panel); color:var(--td-ink-3); font:inherit; font-size:11.5px;
}
.td-pill-segb.td-on{ background:var(--td-accent, #A9C3AE); color:#FFFDFA; }
.td-pill-ritmo{ margin:8px 0 12px; }
.td-pill-days{ display:flex; gap:4px; }
.td-pill-day{
    flex:1; padding:7px 0; border:none; border-radius:9px; cursor:pointer;
    background:var(--td-panel); color:var(--td-ink-3); font:inherit; font-size:11px;
}
.td-pill-day.td-on{ background:var(--td-accent, #A9C3AE); color:#FFFDFA; }
.td-pill-ora{ display:flex; align-items:center; gap:6px; margin-bottom:6px; }
.td-pill-ora .td-in{ flex:1; }
.td-pill-step{
    width:28px; height:28px; flex-shrink:0; border:none; border-radius:9px; cursor:pointer;
    background:var(--td-panel); color:var(--td-ink); font:inherit; font-size:15px; line-height:1;
}
.td-pill-n{ min-width:26px; text-align:center; font-size:13px; color:var(--td-ink-3); }
.td-pill-addora{
    border:none; background:none; color:var(--td-ink-3); font:inherit; font-size:12.5px;
    cursor:pointer; padding:4px 0;
}
.td-pill-att{
    padding:8px 14px; border:none; border-radius:11px; cursor:pointer;
    background:var(--td-panel); color:var(--td-ink-3); font:inherit; font-size:13px;
}
.td-pill-att.td-on{ background:var(--td-accent, #A9C3AE); color:#FFFDFA; }
.td-card-wide{ max-width:380px; max-height:82dvh; overflow:auto; }

/* ---------- le tre linguette ---------- */
.td-tabs{
    display:flex; gap:4px; padding:3px; margin-bottom:12px;
    background:var(--td-panel); border-radius:999px;
}
.td-tab{
    flex:1; border:none; background:none; cursor:pointer; font:inherit; font-size:12.5px;
    color:var(--td-ink-2); padding:8px 4px; border-radius:999px; letter-spacing:.02em;
    transition:background-color .24s ease, color .24s ease;
}
.td-tab.td-on{ background:var(--td-paper); color:var(--td-ink); box-shadow:0 1px 3px rgba(74,68,61,.09); }

/* Ogni attività su tre righe: titolo, poi data/ora/durata, poi progetto.
   Su una riga sola il titolo non aveva spazio. */
.td-item{ position:relative; overflow:hidden; border-bottom:.5px solid var(--td-hair); }
.td-item:last-child{ border-bottom:none; }
.td-row{
    display:flex; align-items:flex-start; gap:9px; padding:10px 2px;
    background:var(--td-paper); transition:transform .26s cubic-bezier(.22,.61,.36,1);
}
.td-main{ flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
.td-item.td-sub .td-row{ padding-left:24px; }

/* il cestino compare scorrendo la riga verso sinistra */
.td-swipe{
    position:absolute; left:0; top:0; bottom:0; width:64px;
    display:flex; align-items:center; justify-content:center;
    background:#F3DCDC; color:#8E5A5A; border:none; cursor:pointer;
    /* nascosto finché la riga non viene scostata: anche se qualcosa andasse
       storto con lo scorrimento, non può restare in vista */
    opacity:0; pointer-events:none; transition:opacity .2s ease;
}
.td-swipe svg{ width:17px; height:17px; }
/* il cestino spunta da sinistra scorrendo la riga verso destra */
.td-item.td-swiped .td-row{ transform:translateX(64px); }
.td-item.td-swiped .td-swipe{ opacity:1; pointer-events:auto; }
.td-box{
    width:19px; height:19px; border-radius:7px; flex-shrink:0;
    box-shadow:inset 0 0 0 1.2px var(--td-hair); display:grid; place-items:center;
    color:transparent; background:none; cursor:pointer;
    transition:background-color .3s, color .3s, transform .3s cubic-bezier(.34,1.3,.64,1);
}
.td-box svg{ width:11px; height:11px; }
.td-row.td-done .td-box{ background:var(--td-accent); color:#FFFDFA; box-shadow:none; transform:scale(1.05); }
.td-title{
    flex:1; min-width:0; border:none; background:none; outline:none; font:inherit;
    font-size:15px; color:var(--td-ink); padding:3px 0;
}
.td-title::placeholder{ color:var(--td-ink-3); }
.td-row.td-done .td-title{ color:var(--td-ink-3); text-decoration:line-through; text-decoration-thickness:.7px; }
.td-row.td-late .td-title{ color:var(--td-late); }
.td-row.td-late .td-box{ box-shadow:inset 0 0 0 1.4px var(--td-late); }

/* i comandi stanno sulla stessa riga: niente popup per cambiare data o durata */
.td-tools{ display:flex; align-items:center; gap:4px; flex-shrink:0; }
.td-chip{
    display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:999px;
    background:var(--td-panel); color:var(--td-ink-2); font-size:11px; white-space:nowrap;
    border:none; cursor:pointer; font-family:inherit;
    transition:background-color .2s, color .2s, transform .15s;
}
.td-chip:active{ transform:scale(.94); }
.td-chip svg{ width:12px; height:12px; }
.td-chip.td-set{ background:var(--td-accent-soft); color:var(--td-ink); }
/* il cestino sta in fondo alla riga degli strumenti, discreto finché non lo
   si tocca: si vede che c'è, ma non tira l'occhio più del resto */
.td-tools{ flex-wrap:wrap; }
.td-chip-del{ margin-left:auto; color:#B08A8A; background:transparent; }
.td-chip-del:hover, .td-chip-del:focus-visible{ background:#F3DCDC; color:#8E5A5A; }
/* Da telefono il cestino in fondo alla riga non serve e ruba spazio ai chip:
   lì c'è già lo scorrimento, che su un touch screen è il gesto naturale.
   Da computer resta, perché lo scorrimento col mouse non è ovvio. */
@media (pointer:coarse){
    .td-chip-del{ display:none; }
}
.td-card-acts button.td-danger-btn{ background:#D98C8C; color:#FFFDFA; }
.td-row.td-late .td-chip.td-set{ background:var(--td-late-soft); color:var(--td-late); }
.td-chip{ position:relative; }
/* campo invisibile ma cliccabile, steso su tutto il chip */
.td-input-cover{
    position:absolute; inset:0; width:100% !important; height:100%;
    opacity:0; cursor:pointer; z-index:2;
}
.td-chip input{
    border:none; background:none; outline:none; font:inherit; font-size:11px;
    color:inherit; width:auto; padding:0; cursor:pointer;
}
.td-chip input[type="number"]{ width:30px; text-align:center; }
.td-dot{ width:8px; height:8px; border-radius:50%; flex-shrink:0; }

.td-add{
    display:flex; align-items:center; gap:9px; padding:10px 2px; color:var(--td-ink-3);
    background:none; border:none; font:inherit; font-size:14px; cursor:pointer; width:100%;
}
.td-add svg{ width:15px; height:15px; }
.td-empty{
    font-family:var(--td-serif); font-style:italic; font-size:15px;
    color:var(--td-ink-2); padding:14px 2px; text-align:center;
}
.td-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px; }
.td-lab{
    font-size:9px; font-weight:500; letter-spacing:.18em; text-transform:uppercase; color:var(--td-ink-3);
}
.td-sel-btn{
    border:none; background:none; font:inherit; font-size:12px; color:var(--td-ink-2);
    cursor:pointer; padding:4px 2px;
}
.td-sel-btn.td-on{ color:var(--td-accent); font-weight:500; }

/* modalità seleziona */
.td-wrap.td-selecting .td-tools{ opacity:.35; pointer-events:none; }
.td-pick{
    width:19px; height:19px; border-radius:50%; flex-shrink:0; cursor:pointer;
    box-shadow:inset 0 0 0 1.2px var(--td-hair); display:grid; place-items:center;
    color:transparent; transition:background-color .25s, color .25s;
}
.td-pick svg{ width:11px; height:11px; }
.td-pick.td-on{ background:var(--td-accent); color:#FFFDFA; box-shadow:none; }
/* comandi della selezione: in alto, piccoli, con le icone */
/* Resta in vista mentre l'elenco scorre sotto: le azioni di gruppo servono
   soprattutto in fondo alla lista, e prima bisognava risalire fino in cima per
   raggiungerle (con i prezzi, che stanno qui, fuori vista per tutto il tempo). */
.td-bulk{
    position:sticky; top:0; z-index:6;
    display:flex; gap:6px; align-items:center; padding:10px 0; flex-wrap:wrap;
    border-bottom:.5px solid var(--td-hair); margin-bottom:4px;
    background:var(--td-paper);
}
.td-bulk button{
    display:flex; align-items:center; gap:6px;
    padding:7px 12px; border-radius:999px; border:none;
    background:var(--td-panel); color:var(--td-ink-2); font:inherit; font-size:12.5px; cursor:pointer;
    transition:transform .15s, background-color .2s;
}
.td-bulk button:active{ transform:scale(.94); }
.td-bulk button svg{ width:15px; height:15px; }
.td-bulk button.td-danger{ background:#F3DCDC; color:#8E5A5A; }
.td-bulk .td-count{ margin-left:auto; font-size:11px; color:var(--td-ink-3); text-align:right; }
.td-bulk .td-count b{ font-weight:500; color:var(--td-ink); display:block; font-size:13px; }

/* ---------- to buy: gruppi, prezzi, immagini ---------- */
.td-group{ margin-top:14px; }
.td-group:first-of-type{ margin-top:2px; }
.td-ghead{
    display:flex; align-items:center; gap:7px; padding:6px 2px 5px;
    border-bottom:1px solid var(--td-hair);
}
.td-ghead .td-gname{
    font-family:var(--td-serif); font-size:19px; font-weight:500; flex:1; min-width:0;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.td-gtot{ font-size:12.5px; color:var(--td-ink-2); white-space:nowrap; }
/* la fascia della priorità: sottile, così separa senza pesare quanto la categoria */
.td-phead{
    display:flex; align-items:center; gap:8px; padding:9px 2px 3px;
}
.td-plab{
    font-size:9px; letter-spacing:.16em; text-transform:uppercase; color:var(--td-ink-3);
}
.td-phead .td-line{ flex:1; height:.5px; background:var(--td-hair); }
.td-ptot{ font-size:11px; color:var(--td-ink-3); white-space:nowrap; }

.td-buy .td-row{ align-items:flex-start; }
.td-thumb{
    width:62px; height:62px; border-radius:14px; flex-shrink:0; cursor:pointer;
    background:var(--td-panel) center/cover no-repeat; border:none; padding:0;
    display:grid; place-items:center; color:var(--td-ink-3);
    box-shadow:inset 0 0 0 .8px var(--td-hair);
    transition:transform .15s;
}
.td-thumb:active{ transform:scale(.96); }
.td-thumb svg{ width:18px; height:18px; }
.td-thumb.td-has svg{ display:none; }
.td-buylink{
    display:flex; align-items:center; gap:5px; font-size:11.5px; color:var(--td-ink-2);
    min-width:0;
}
.td-buylink input{
    flex:1; min-width:0; border:none; outline:none; background:none; font:inherit;
    font-size:11.5px; color:var(--td-ink-2); padding:2px 0;
}
.td-buylink input::placeholder{ color:var(--td-ink-3); }
.td-buylink a{ color:var(--td-accent); flex-shrink:0; display:flex; }
.td-buylink a svg{ width:13px; height:13px; }
.td-price{ min-width:56px; }
.td-price input{ width:46px; text-align:right; }
/* la priorità si legge a colpo d'occhio: più è alta, più il pallino è pieno */
.td-prio-1{ background:#EADCD9 !important; color:#8E5A5A !important; }
.td-prio-2{ background:#EDE7DC !important; color:#8A7B5F !important; }
.td-prio-3{ background:#E6EAE6 !important; color:#6F7D6F !important; }

.td-total{
    display:flex; align-items:baseline; justify-content:space-between; gap:10px;
    margin-top:18px; padding:12px 14px; border-radius:16px; background:var(--td-accent-soft);
}
.td-total span{ font-size:10px; letter-spacing:.18em; text-transform:uppercase; color:var(--td-ink-2); }
.td-total b{ font-family:var(--td-serif); font-size:24px; font-weight:500; }

.td-catrow{
    display:flex; align-items:center; gap:9px; padding:9px 2px;
    border-bottom:.5px solid var(--td-hair);
}
.td-catrow:last-child{ border-bottom:none; }
.td-catrow .td-cname{ flex:1; min-width:0; border:none; background:none; font:inherit; font-size:14px; color:var(--td-ink); outline:none; }
.td-catrow input[type="color"]{
    width:22px; height:22px; padding:0; border:none; background:none; border-radius:50%;
    flex-shrink:0; cursor:pointer;
}
.td-catrow .td-cdel{ border:none; background:none; color:#B08A8A; cursor:pointer; padding:4px; display:flex; }
.td-catrow .td-cdel svg{ width:15px; height:15px; }
.td-catpick{
    display:flex; align-items:center; gap:9px; width:100%; padding:10px 2px;
    border:none; background:none; font:inherit; font-size:14px; color:var(--td-ink);
    cursor:pointer; text-align:left; border-bottom:.5px solid var(--td-hair);
}
</style>

<script>
(function () {
    if (window.nisamaTodo) return;

    var CSRF = <?php echo json_encode(sec_csrf_token()); ?>;
    var MESI = ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'];
    /* La quarta linguetta non è un quarto elenco di attività: è la sezione
       delle pastiglie, che ha dati suoi (terapie e prese) e un disegno suo.
       Sta qui perché è qui che uno guarda la mattina, insieme al resto della
       giornata, non in una pagina che si ricorderebbe di aprire solo dopo
       essersene dimenticato. */
    var TIPI = ['todo', 'buy', 'study', 'pills'];
    var TIPO_LAB = { todo: 'to do', buy: 'to buy', study: 'to study', pills: 'pastiglie' };
    var IMG_DIR = 'uploads/tobuy/';

    function api(action, data, query) {
        var url = 'todo_api.php?ajax=' + encodeURIComponent(action);
        if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
        var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF } };
        if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
        return fetch(url, opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    /* L'immagine viaggia come multipart: un file dentro il JSON andrebbe
       codificato in base64, cioè un terzo di peso in più per niente. */
    function apiFile(action, form) {
        return fetch('todo_api.php?ajax=' + encodeURIComponent(action), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF },
            body: form
        }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined && txt !== null) n.textContent = txt;
        return n;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    /* I prezzi si contano in centesimi e si scrivono in euro solo qui, in un
       posto solo: se ogni punto facesse la sua divisione i totali finirebbero
       per non tornare con le righe. */
    function euro(cents) {
        var v = (Math.round(cents || 0) / 100).toFixed(2);
        return v.replace('.', ',') + ' €';
    }

    var ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    var ICON_CAL   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>';
    var ICON_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>';
    var ICON_HOUR  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 3h10M7 21h10"/><path d="M8 3c0 4 8 5 8 9s-8 5-8 9"/></svg>';
    var ICON_PLUS  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    var ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    var ICON_TAG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11V4h7l9 9-7 7-9-9z"/><circle cx="8" cy="8" r="1.2"/></svg>';
    var ICON_FLAG  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 21V4h12l-2.5 4L18 12H6"/></svg>';
    var ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 13a4 4 0 0 0 6 .5l2-2a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 11a4 4 0 0 0-6-.5l-2 2A4 4 0 0 0 11.7 18l1-1"/></svg>';
    var ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="14" rx="2.6"/><path d="M4 16l4.5-4 3.5 3 3-2.5L20 16"/><circle cx="9" cy="9.5" r="1.1"/></svg>';

    /* ---------------- una istanza montata ---------------- */
    function Todo(host, opts) {
        this.host = host;
        this.opts = opts || {};
        this.scope = this.opts.scope || 'all';
        this.projectId = this.opts.projectId || 0;
        this.compact = !!this.opts.compact;
        this.selectable = !!this.opts.selectable;
        /* le linguette hanno senso solo sulla pagina intera: dentro un
           progetto o nel pomodoro esiste solo il to do */
        this.tabs = !!this.opts.tabs && this.scope === 'all';
        this.listType = 'todo';
        if (this.tabs) {
            try {
                var memo = localStorage.getItem('nisama_todo_tab');
                if (TIPI.indexOf(memo) >= 0) this.listType = memo;
            } catch (e) {}
        }
        this.items = [];
        this.cats = [];
        this.sessionIds = [];
        this.picked = {};
        this.selecting = false;
        this.timers = {};
        /* righe che si stanno modificando adesso e fotografia dell'ordine:
           vedi congela() più sotto */
        this.edited = {};
        this.frozen = null;
        this.build();
        this.load();
    }

    Todo.prototype.build = function () {
        var self = this;
        this.host.innerHTML = '';
        this.wrap = el('div', 'td-wrap');

        if (this.tabs) {
            this.tabBar = el('div', 'td-tabs');
            this.tabBtns = {};
            TIPI.forEach(function (t) {
                var b = el('button', 'td-tab' + (t === self.listType ? ' td-on' : ''), TIPO_LAB[t]);
                b.type = 'button';
                b.addEventListener('click', function () { self.setType(t); });
                self.tabBtns[t] = b;
                self.tabBar.appendChild(b);
            });
            this.wrap.appendChild(this.tabBar);
        }

        if (this.selectable) {
            var head = el('div', 'td-head');
            this.headLab = el('span', 'td-lab', this.tabs ? TIPO_LAB[this.listType] : 'Attività');
            head.appendChild(this.headLab);
            this.selBtn = el('button', 'td-sel-btn', 'Seleziona');
            this.selBtn.type = 'button';
            this.selBtn.addEventListener('click', function () { self.toggleSelect(); });
            head.appendChild(this.selBtn);
            this.wrap.appendChild(head);
        }
        this.list = el('div', 'td-list');
        this.wrap.appendChild(this.list);
        this.host.appendChild(this.wrap);
        /* si disegna subito con quello che c'è (niente): il tasto per
           aggiungere dev'esserci anche prima che la lettura risponda */
        this.render();
    };

    /* Cambiare linguetta è come cambiare pagina: la selezione in corso non ha
       più senso, perché le azioni di gruppo si applicherebbero a righe che
       non si stanno più guardando. */
    Todo.prototype.setType = function (t) {
        if (this.listType === t) return;
        this.lasciaCampo();
        this.listType = t;
        this.picked = {};
        this.selecting = false;
        if (this.selBtn) { this.selBtn.textContent = 'Seleziona'; this.selBtn.classList.remove('td-on'); }
        if (this.headLab) this.headLab.textContent = TIPO_LAB[t];
        for (var k in this.tabBtns) if (this.tabBtns.hasOwnProperty(k)) {
            this.tabBtns[k].classList.toggle('td-on', k === t);
        }
        try { localStorage.setItem('nisama_todo_tab', t); } catch (e) {}
        this.items = [];
        this.render();
        this.load();
    };

    /* Prima di cambiare vista si lascia la casella aperta: il fuoco fa da
       freno al ridisegno, e in più è uscendo dal titolo che un'attività
       rimasta senza nome viene tolta. Senza questo, cambiando linguetta con il
       cursore ancora dentro, l'elenco restava quello di prima. */
    Todo.prototype.lasciaCampo = function () {
        var a = document.activeElement;
        if (a && this.wrap && this.wrap.contains(a) && a.blur) a.blur();
    };

    /* -----------------------------------------------------------------
       ORDINE CONGELATO MENTRE SI MODIFICA

       Cambiare la data di un'attività la manda in cima; cambiarle categoria o
       priorità la manda in un altro gruppo. Se succede nell'istante del tocco,
       la riga sparisce da sotto il dito e per fare la modifica successiva
       bisogna ricercarla in mezzo alle altre.

       Qui, al primo cambiamento, l'elenco viene fotografato com'è: posizione,
       categoria e priorità di ogni riga. Da quel momento il disegno usa la
       fotografia, non i dati veri — la riga si aggiorna ma resta dov'è, con lo
       sfondo diverso a dire "questa non è in ordine con le altre, la stai
       modificando". Toccando fuori la fotografia si strappa e l'ordine vero
       torna, con tutto al suo posto.
       ----------------------------------------------------------------- */
    Todo.prototype.congela = function () {
        if (this.frozen) return;
        var f = {};
        this.items.forEach(function (t, i) {
            f[String(t.id)] = { i: i, cat: t.cat_id, prio: t.priority || 3 };
        });
        this.frozen = f;
    };

    Todo.prototype.inModifica = function (id) {
        if (!id) return;
        this.congela();
        this.edited[String(id)] = true;
        /* il colore si mette subito sulla riga che è già in pagina: scrivendo
           nel titolo il ridisegno non arriva (la casella è in uso) e senza
           questo la riga resterebbe uguale alle altre proprio mentre la si
           sta modificando */
        var riga = this.list && this.list.querySelector('.td-item[data-id="' + id + '"]');
        if (riga) riga.classList.add('td-in-modifica');
        this.ascoltaFuori();
    };

    /* Fuori dalla riga, ma non dentro una nostra finestra: mentre si sceglie
       una data o una categoria si sta ancora modificando quella riga, e
       liberare l'ordine lì in mezzo sarebbe il difetto di prima con un passo
       in più. */
    Todo.prototype.ascoltaFuori = function () {
        if (this._fuori) return;
        var self = this;
        this._fuori = function (e) {
            if (!self.frozen || !e.target || !e.target.closest) return;
            if (e.target.closest('.td-modal')) return;
            var riga = e.target.closest('.td-item');
            if (riga && riga.dataset.id && self.edited[riga.dataset.id]) return;
            self.liberaOrdine();
        };
        document.addEventListener('pointerdown', this._fuori, true);
    };

    Todo.prototype.liberaOrdine = function () {
        if (!this.frozen) return;
        this.frozen = null;
        this.edited = {};
        if (this._fuori) {
            document.removeEventListener('pointerdown', this._fuori, true);
            this._fuori = null;
        }
        /* si rilegge dal server: l'ordine vero lo decide lui, non noi */
        this.load();
    };

    /* Durante il congelamento questi tre dicono dove va disegnata una riga.
       Il contenuto mostra i dati nuovi: si sposta solo il posto, non il testo. */
    Todo.prototype.ordina = function (list) {
        if (!this.frozen) return list;
        var f = this.frozen;
        return list.slice().sort(function (a, b) {
            var pa = f[String(a.id)] ? f[String(a.id)].i : 9e9;
            var pb = f[String(b.id)] ? f[String(b.id)].i : 9e9;
            return pa - pb;
        });
    };
    Todo.prototype.catDi = function (t) {
        var f = this.frozen && this.frozen[String(t.id)];
        return f ? f.cat : t.cat_id;
    };
    Todo.prototype.prioDi = function (t) {
        var f = this.frozen && this.frozen[String(t.id)];
        return f ? f.prio : (t.priority || 3);
    };

    Todo.prototype.later = function (key, ms, fn) {
        if (this.timers[key]) clearTimeout(this.timers[key]);
        this.timers[key] = setTimeout(fn, ms);
    };

    Todo.prototype.load = function () {
        var self = this;
        /* le pastiglie hanno le loro tabelle: non passano dall'elenco attività */
        if (this.listType === 'pills') return this.loadPills();
        var q = { scope: this.scope, project_id: this.projectId, list_type: this.listType };
        /* nel pomodoro senza progetto si chiedono solo le attività create
           in questa sessione: l'elenco parte vuoto e si riempie scrivendo */
        var body = (this.scope === 'pomodoro') ? { ids: this.sessionIds || [] } : null;
        return api('list', body, q).then(function (r) {
            if (!r.ok) {
                /* Prima, se la lettura falliva, non disegnavo niente: restava un
                   riquadro vuoto senza nemmeno il tasto per aggiungere, e senza
                   dire cosa fosse andato storto. Ora almeno si può lavorare. */
                self.items = [];
                self.render();
                self.list.insertBefore(
                    el('div', 'td-empty', 'Elenco non caricato. Riprova fra un momento.'),
                    self.list.firstChild);
                return;
            }
            /* la risposta di una linguetta che nel frattempo è stata lasciata
               non deve sovrascrivere quella che si sta guardando adesso */
            if (r.list_type && r.list_type !== self.listType) return;
            self.items = r.todos;
            if (r.categories) self.cats = r.categories;
            self.today = r.today;
            self.now = r.now;
            self.render();
        });
    };

    /* un'attività è in ritardo se la sua data (e ora) è passata e non è spuntata */
    Todo.prototype.isLate = function (t) {
        if (t.done || !t.date) return false;
        if (t.date < this.today) return true;
        if (t.date > this.today) return false;
        return !!(t.time && t.time < this.now);
    };

    /* C'è una casella in mano all'utente? Titolo, prezzo, link, data, orario:
       finché il cursore sta là dentro, ridisegnare vuol dire distruggere quella
       casella mentre la si sta usando — si perde quello che si stava scrivendo
       e, con il riordino, anche la riga di vista. I pulsanti non contano: lì il
       tocco è già finito. */
    Todo.prototype.campoInMano = function () {
        var a = document.activeElement;
        if (!a || !this.wrap || !this.wrap.contains(a)) return false;
        return a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' ||
               a.tagName === 'SELECT' || a.isContentEditable === true;
    };

    /* Ridisegno e rilettura non sono mai istantanei sotto il dito: mezzo
       secondo di respiro fa vedere la modifica al suo posto prima che la riga
       salti altrove per via del riordino. */
    Todo.prototype.renderLater = function (ms) {
        var self = this;
        this.later('render', ms || 450, function () { self.render(); });
    };
    Todo.prototype.loadLater = function (ms) {
        var self = this;
        this.later('render', ms || 450, function () { self.load(); });
    };

    Todo.prototype.render = function () {
        var self = this;
        /* Il ridisegno non si perde: si segna e si fa appena la casella viene
           lasciata (vedi l'ascoltatore su 'focusout' qui sotto). Nel frattempo
           i dati nuovi sono già in memoria, quindi quando arriva è quello
           giusto e non uno vecchio. */
        if (this.campoInMano()) { this._daRidisegnare = true; return; }
        this._daRidisegnare = false;
        if (!this._blurBound && this.wrap) {
            this._blurBound = true;
            this.wrap.addEventListener('focusin', function (e) {
                var riga = e.target.closest ? e.target.closest('.td-item') : null;
                if (riga && riga.dataset.id) self.inModifica(riga.dataset.id);
            });
            this.wrap.addEventListener('focusout', function () {
                /* piccola attesa: passando da una casella all'altra il fuoco
                   esce e rientra, e non ha senso ridisegnare nel mezzo */
                self.later('blur', 300, function () {
                    if (!self.campoInMano() && self._daRidisegnare) self.render();
                });
            });
        }
        this.list.innerHTML = '';
        /* toccando fuori da una riga scostata, questa si richiude */
        if (!this._closeBound) {
            this._closeBound = true;
            document.addEventListener('pointerdown', function (e) {
                if (e.target.closest && e.target.closest('.td-swipe')) return;
                var open = self.list.querySelectorAll('.td-item.td-swiped');
                for (var i = 0; i < open.length; i++) {
                    if (!open[i].contains(e.target)) open[i].classList.remove('td-swiped');
                }
            }, true);
        }
        this.wrap.classList.toggle('td-selecting', this.selecting);

        /* prima della selezione multipla: qui non si seleziona niente */
        if (this.listType === 'pills') { this.renderPills(); return; }

        if (this.selecting) this.list.appendChild(this.bulkBar());
        if (this.listType === 'buy') { this.renderBuy(); return; }

        var roots = this.ordina(this.items).filter(function (t) { return !t.parent_id; });
        if (!roots.length) this.list.appendChild(el('div', 'td-empty', this.emptyText()));

        roots.forEach(function (t) {
            self.list.appendChild(self.row(t, false));
            self.items.filter(function (k) { return k.parent_id === t.id; })
                      .forEach(function (k) { self.list.appendChild(self.row(k, true)); });
        });

        this.list.appendChild(this.addButton());
    };

    Todo.prototype.emptyText = function () {
        if (this.listType === 'buy') return 'Niente da comprare, per ora.';
        if (this.listType === 'study') return 'Niente da studiare, per ora.';
        return 'Niente da fare, per ora.';
    };

    Todo.prototype.addButton = function () {
        var self = this;
        var lab = this.listType === 'buy' ? 'Nuovo articolo'
                : (this.listType === 'study' ? 'Nuovo argomento' : 'Nuova attività');
        var add = el('button', 'td-add');
        add.type = 'button';
        add.innerHTML = ICON_PLUS;
        add.appendChild(el('span', null, lab));
        add.addEventListener('click', function () { self.addNew(null); });
        return add;
    };

    /* ---------------- to buy ----------------
       Raggruppato per categoria e, dentro, per priorità. Il totale si calcola
       qui e non sul server perché è lo stesso conto che serve, in diretta, per
       la selezione: un solo modo di sommare, un solo risultato possibile. */
    Todo.prototype.catById = function (id) {
        for (var i = 0; i < this.cats.length; i++) if (this.cats[i].id === id) return this.cats[i];
        return null;
    };
    function sumCents(list) {
        var n = 0;
        list.forEach(function (t) { n += t.cents || 0; });
        return n;
    }

    Todo.prototype.renderBuy = function () {
        var self = this;
        var roots = this.ordina(this.items).filter(function (t) { return !t.parent_id; });

        var manage = el('div', 'td-head');
        var b = el('button', 'td-sel-btn', 'Categorie');
        b.type = 'button';
        b.addEventListener('click', function () { self.catManager(); });
        manage.appendChild(el('span', 'td-lab', roots.length ? roots.length + ' articoli' : ''));
        manage.appendChild(b);
        this.list.appendChild(manage);

        if (!roots.length) {
            this.list.appendChild(el('div', 'td-empty', this.emptyText()));
            this.list.appendChild(this.addButton());
            return;
        }

        /* i gruppi seguono l'ordine delle categorie; quelli senza categoria
           chiudono la fila, così non si mettono davanti a niente */
        var gruppi = [];
        this.cats.forEach(function (c) {
            var inCat = roots.filter(function (t) { return self.catDi(t) === c.id; });
            if (inCat.length) gruppi.push({ cat: c, items: inCat });
        });
        var senza = roots.filter(function (t) { return !self.catDi(t) || !self.catById(self.catDi(t)); });
        if (senza.length) gruppi.push({ cat: null, items: senza });

        gruppi.forEach(function (g) {
            var box = el('div', 'td-group');
            var head = el('div', 'td-ghead');
            if (g.cat) {
                var dot = el('span', 'td-dot');
                dot.style.background = g.cat.color || '#B7AEA3';
                head.appendChild(dot);
            }
            head.appendChild(el('div', 'td-gname', g.cat ? g.cat.name : 'Senza categoria'));
            head.appendChild(el('div', 'td-gtot', euro(sumCents(g.items))));
            box.appendChild(head);

            [1, 2, 3].forEach(function (p) {
                var inPrio = g.items.filter(function (t) { return self.prioDi(t) === p; });
                if (!inPrio.length) return;
                var ph = el('div', 'td-phead');
                ph.appendChild(el('span', 'td-plab', 'priorità ' + p));
                ph.appendChild(el('span', 'td-line'));
                ph.appendChild(el('span', 'td-ptot', euro(sumCents(inPrio))));
                box.appendChild(ph);
                inPrio.forEach(function (t) { box.appendChild(self.buyRow(t)); });
            });
            self.list.appendChild(box);
        });

        var tot = el('div', 'td-total');
        tot.appendChild(el('span', null, 'totale'));
        tot.appendChild(el('b', null, euro(sumCents(roots))));
        this.list.appendChild(tot);
        this.list.appendChild(this.addButton());
    };

    Todo.prototype.buyRow = function (t) {
        var self = this;
        var item = el('div', 'td-item td-buy' + (this.edited[String(t.id)] ? ' td-in-modifica' : ''));
        item.dataset.id = t.id;
        var row = el('div', 'td-row' + (t.done ? ' td-done' : ''));

        if (this.selecting) {
            row.appendChild(this.pickBox(t));
        } else {
            row.appendChild(this.checkBox(t));
        }

        /* l'immagine: si tocca e si sceglie il file, si tiene premuto (o si
           tocca da spuntata) per toglierla */
        var thumb = el('button', 'td-thumb' + (t.image ? ' td-has' : ''));
        thumb.type = 'button';
        thumb.innerHTML = ICON_IMG;
        if (t.image) thumb.style.backgroundImage = 'url("' + IMG_DIR + encodeURIComponent(t.image) + '")';
        thumb.addEventListener('click', function () { self.pickImage(t, thumb); });
        row.appendChild(thumb);

        var main = el('div', 'td-main');
        main.appendChild(this.titleInput(t, false));

        var tools = el('div', 'td-tools');
        tools.appendChild(this.priceChip(t));
        tools.appendChild(this.prioChip(t));
        tools.appendChild(this.catChip(t));
        tools.appendChild(this.trashChip(t));
        main.appendChild(tools);

        /* il link sta sotto l'immagine, come chiesto: si incolla e basta */
        var lk = el('div', 'td-buylink');
        lk.appendChild(el('span', null, '↳'));
        var url = el('input');
        url.type = 'url';
        url.value = t.url || '';
        url.placeholder = 'link dell\'articolo';
        url.addEventListener('change', function () {
            self.save(t, { url: url.value }, true).then(function () { self.renderLater(); });
        });
        lk.appendChild(url);
        if (t.url) {
            var open = el('a');
            open.href = t.url;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.title = 'Apri';
            open.innerHTML = ICON_LINK;
            lk.appendChild(open);
        }
        main.appendChild(lk);

        row.appendChild(main);
        item.appendChild(row);
        item.appendChild(this.swipeTrash(t));
        this.enableSwipe(item, row);
        return item;
    };

    Todo.prototype.priceChip = function (t) {
        var self = this;
        var chip = el('label', 'td-chip td-price' + (t.cents !== null && t.cents !== undefined ? ' td-set' : ''));
        var inp = el('input');
        inp.type = 'text';
        inp.inputMode = 'decimal';
        inp.placeholder = '0,00';
        inp.value = (t.cents !== null && t.cents !== undefined) ? (t.cents / 100).toFixed(2).replace('.', ',') : '';
        inp.addEventListener('change', function () {
            self.save(t, { price: inp.value }, true).then(function () { self.renderLater(); });
        });
        chip.appendChild(inp);
        chip.appendChild(el('span', null, '€'));
        return chip;
    };

    /* La priorità gira toccando: da 1 a 3 e daccapo. Con tre soli valori un
       popup sarebbe più lento del gesto che deve sostituire. */
    Todo.prototype.prioChip = function (t) {
        var self = this;
        /* Di partenza 3, cioè l'ultima: quello che conta davvero lo si alza a
           mano, mentre marcare tutto come importante non direbbe più niente. */
        var p = t.priority || 3;
        var chip = el('button', 'td-chip td-prio-' + p);
        chip.type = 'button';
        chip.title = 'Priorità';
        chip.innerHTML = ICON_FLAG;
        chip.appendChild(el('span', null, String(p)));
        chip.addEventListener('click', function () {
            var next = p >= 3 ? 1 : p + 1;
            self.save(t, { priority: next }, true).then(function () { self.renderLater(); });
        });
        return chip;
    };

    Todo.prototype.catChip = function (t) {
        var self = this;
        var c = t.cat_id ? this.catById(t.cat_id) : null;
        var chip = el('button', 'td-chip' + (c ? ' td-set' : ''));
        chip.type = 'button';
        if (c) {
            var dot = el('span', 'td-dot');
            dot.style.background = c.color || '#B7AEA3';
            chip.appendChild(dot);
            chip.appendChild(el('span', null, c.name));
        } else {
            chip.innerHTML = ICON_TAG;
            chip.appendChild(el('span', null, 'Categoria'));
        }
        chip.addEventListener('click', function () {
            self.catPicker(function (id) {
                self.save(t, { cat_id: id }, true).then(function () { self.renderLater(); });
            });
        });
        return chip;
    };

    /* Il file si sceglie con un campo creato al momento e buttato subito
       dopo: tenerne uno per riga significherebbe decine di campi nascosti
       nella pagina, tutti uguali e tutti inutili tranne uno. */
    /* Toccando la miniatura si guarda l'immagine per quello che è, grande
       quanto lo schermo lo permette: da un quadratino di 44 pixel non si
       capisce se l'articolo è quello giusto. I due comandi stanno sotto, fuori
       dall'immagine, per non coprirne un pezzo proprio mentre la si guarda. */
    Todo.prototype.pickImage = function (t, thumb) {
        var self = this;
        if (t.image) {
            var ov = el('div', 'td-modal td-modal-img');
            var box = el('div', 'td-imgview');
            var big = document.createElement('img');
            big.src = IMG_DIR + encodeURIComponent(t.image);
            big.alt = (t.title || 'Immagine').trim();
            box.appendChild(big);

            var acts = el('div', 'td-card-acts');
            var rem = el('button', null, 'Togli');
            rem.type = 'button';
            rem.addEventListener('click', function () {
                ov.remove();
                api('image_del', { id: t.id }).then(function (r) {
                    if (!r.ok) return;
                    t.image = null;
                    self.render();
                });
            });
            var chg = el('button', 'td-primary', 'Sostituisci');
            chg.type = 'button';
            chg.addEventListener('click', function () { ov.remove(); self.openFile(t); });
            acts.appendChild(rem); acts.appendChild(chg);
            box.appendChild(acts);

            ov.appendChild(box);
            ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
            return;
        }
        this.openFile(t);
    };

    Todo.prototype.openFile = function (t) {
        var self = this;
        var inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = 'image/jpeg,image/png,image/webp,image/gif';
        inp.style.display = 'none';
        inp.addEventListener('change', function () {
            var f = inp.files && inp.files[0];
            inp.remove();
            if (!f) return;
            var form = new FormData();
            form.append('id', t.id);
            form.append('file', f);
            apiFile('image', form).then(function (r) {
                if (!r.ok) {
                    self.tell('Immagine non caricata', r.message || 'Riprova con un file più piccolo.');
                    return;
                }
                t.image = r.image;
                self.render();
            });
        });
        document.body.appendChild(inp);
        inp.click();
    };

    Todo.prototype.tell = function (titolo, testo) {
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', titolo));
        card.appendChild(el('div', 'td-card-s', testo));
        var acts = el('div', 'td-card-acts');
        var ok = el('button', 'td-primary', 'Ho capito');
        ok.type = 'button';
        ok.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(ok);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* ---------------- categorie ---------------- */
    Todo.prototype.catPicker = function (onPick) {
        var self = this;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', 'Categoria'));

        function riga(label, color, id) {
            var b = el('button', 'td-catpick');
            b.type = 'button';
            var dot = el('span', 'td-dot');
            dot.style.background = color || 'transparent';
            if (!color) dot.style.boxShadow = 'inset 0 0 0 1px rgba(74,68,61,.2)';
            b.appendChild(dot);
            b.appendChild(el('span', null, label));
            b.addEventListener('click', function () { ov.remove(); onPick(id); });
            card.appendChild(b);
        }
        riga('Senza categoria', null, 0);
        this.cats.forEach(function (c) { riga(c.name, c.color, c.id); });

        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var mng = el('button', 'td-primary', 'Gestisci');
        mng.type = 'button';
        mng.addEventListener('click', function () { ov.remove(); self.catManager(); });
        acts.appendChild(no); acts.appendChild(mng);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    Todo.prototype.catManager = function () {
        var self = this;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', 'Categorie'));
        card.appendChild(el('div', 'td-card-s', 'Il nome si cambia scrivendoci sopra. Cancellandone una, gli articoli restano senza categoria.'));

        var box = el('div');
        card.appendChild(box);

        function disegna() {
            box.innerHTML = '';
            self.cats.forEach(function (c) {
                var r = el('div', 'td-catrow');
                var col = el('input');
                col.type = 'color';
                col.value = (c.color && /^#[0-9a-f]{6}$/i.test(c.color)) ? c.color : '#B7AEA3';
                var nome = el('input', 'td-cname');
                nome.type = 'text';
                nome.value = c.name;
                nome.maxLength = 60;
                function salva() {
                    if (!nome.value.trim()) { nome.value = c.name; return; }
                    api('cat_save', { id: c.id, name: nome.value, color: col.value }).then(function (r2) {
                        if (!r2.ok) return;
                        self.cats = r2.categories;
                        c.name = nome.value; c.color = col.value;
                    });
                }
                nome.addEventListener('change', salva);
                col.addEventListener('change', salva);
                var del = el('button', 'td-cdel');
                del.type = 'button';
                del.innerHTML = ICON_TRASH;
                del.addEventListener('click', function () {
                    api('cat_delete', { id: c.id }).then(function (r2) {
                        if (!r2.ok) return;
                        self.cats = r2.categories;
                        disegna();
                        self.load();
                    });
                });
                r.appendChild(col); r.appendChild(nome); r.appendChild(del);
                box.appendChild(r);
            });

            /* la riga per aggiungerne una sta in fondo, vuota: si scrive e si
               conferma, senza un secondo popup sopra questo */
            var add = el('div', 'td-catrow');
            var ncol = el('input');
            ncol.type = 'color';
            ncol.value = '#B7AEA3';
            var nnome = el('input', 'td-cname');
            nnome.type = 'text';
            nnome.placeholder = 'Nuova categoria…';
            nnome.maxLength = 60;
            function crea() {
                var v = nnome.value.trim();
                if (!v) return;
                api('cat_save', { id: 0, name: v, color: ncol.value }).then(function (r2) {
                    if (!r2.ok) return;
                    self.cats = r2.categories;
                    nnome.value = '';
                    disegna();
                });
            }
            nnome.addEventListener('change', crea);
            var ok = el('button', 'td-cdel');
            ok.type = 'button';
            ok.style.color = 'var(--td-accent)';
            ok.innerHTML = ICON_PLUS;
            ok.addEventListener('click', crea);
            add.appendChild(ncol); add.appendChild(nnome); add.appendChild(ok);
            box.appendChild(add);
        }
        disegna();

        var acts = el('div', 'td-card-acts');
        var fine = el('button', 'td-primary', 'Fine');
        fine.type = 'button';
        fine.addEventListener('click', function () { ov.remove(); self.render(); });
        acts.appendChild(fine);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) { ov.remove(); self.render(); } });
        document.body.appendChild(ov);
    };

    /* ---------------- pezzi comuni alle righe ---------------- */
    Todo.prototype.pickBox = function (t) {
        var self = this;
        var pick = el('span', 'td-pick' + (this.picked[t.id] ? ' td-on' : ''));
        pick.innerHTML = ICON_CHECK;
        pick.addEventListener('click', function () {
            if (self.picked[t.id]) delete self.picked[t.id]; else self.picked[t.id] = true;
            pick.classList.toggle('td-on', !!self.picked[t.id]);
            /* il totale della selezione deve muoversi mentre si sceglie:
               ridisegnare tutto perderebbe la posizione della pagina, così
               si aggiorna solo il contatore */
            self.refreshCount();
        });
        return pick;
    };

    Todo.prototype.checkBox = function (t) {
        var self = this;
        var box = el('span', 'td-box');
        box.innerHTML = ICON_CHECK;
        box.addEventListener('click', function () {
            api('toggle', { id: t.id }).then(function (r) {
                if (!r.ok) return;
                t.done = r.done;
                self.load();
                if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
            });
        });
        return box;
    };

    Todo.prototype.titleInput = function (t, isSub) {
        var self = this;
        var title = el('input', 'td-title');
        title.type = 'text';
        title.value = t.title;
        title.maxLength = 300;
        title.placeholder = this.listType === 'buy' ? 'Cosa vuoi comprare'
                          : (this.listType === 'study' ? 'Cosa c\'è da studiare' : 'Cosa c\'è da fare');
        /* Il titolo si salva senza ridisegnare l'elenco: ricostruire le righe
           mentre si scrive faceva perdere il fuoco al campo a ogni pausa. */
        title.addEventListener('input', function () {
            self.later('t' + t.id, 700, function () {
                t.title = title.value;
                self.save(t, { title: title.value }, true);
            });
        });
        title.addEventListener('blur', function () {
            t.title = title.value;
            /* uscendo dal campo si può ridisegnare: se è rimasto vuoto
               l'attività va tolta dall'elenco */
            self.save(t, { title: title.value }, title.value.trim() !== '');
        });
        title.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); title.blur(); self.addNew(isSub ? t.parent_id : null); }
        });
        return title;
    };

    Todo.prototype.swipeTrash = function (t) {
        var self = this;
        var trash = el('button', 'td-swipe');
        trash.type = 'button';
        trash.innerHTML = ICON_TRASH;
        trash.addEventListener('click', function () {
            api('delete', { id: t.id }).then(function () { self.load(); });
        });
        return trash;
    };

    Todo.prototype.row = function (t, isSub) {
        var item = el('div', 'td-item' + (isSub ? ' td-sub' : '')
                      + (this.edited[String(t.id)] ? ' td-in-modifica' : ''));
        item.dataset.id = t.id;
        var row = el('div', 'td-row'
            + (t.done ? ' td-done' : '') + (this.isLate(t) ? ' td-late' : ''));

        row.appendChild(this.selecting ? this.pickBox(t) : this.checkBox(t));

        var main = el('div', 'td-main');
        main.appendChild(this.titleInput(t, isSub));
        if (!this.compact) {
            main.appendChild(this.toolsWhen(t));    // data, ora, durata
            main.appendChild(this.toolsProject(t)); // progetto, sulla sua riga
        }
        row.appendChild(main);
        item.appendChild(row);

        /* cestino nascosto: compare scorrendo la riga verso sinistra */
        item.appendChild(this.swipeTrash(t));
        this.enableSwipe(item, row);
        return item;
    };

    /* scorrendo la riga verso sinistra compare il cestino; scorrendo indietro
       (o toccando altrove) si richiude */
    Todo.prototype.enableSwipe = function (item, row) {
        var self = this;
        var x0 = null, y0 = 0, pid = null, daCampo = false;
        function closeOthers() {
            var open = self.list.querySelectorAll('.td-item.td-swiped');
            for (var i = 0; i < open.length; i++) if (open[i] !== item) open[i].classList.remove('td-swiped');
        }
        /* Passato ai pointer event: prima era solo touch, quindi da computer il
           cestino non si raggiungeva in nessun modo.

           Il gesto parte da qualunque punto della riga, caselle e pastiglie
           comprese: nel "to buy" la riga è quasi tutta campi (titolo, prezzo,
           categoria, link) e tenendoli fuori restavano due millimetri buoni da
           cui cominciare, che non si azzeccavano mai. Col dito il rischio di
           confusione non c'è: si distingue guardando la direzione. Col mouse,
           invece, i campi di testo restano esclusi, perché lì trascinare vuol
           dire selezionare il testo, non spostare la riga. */
        row.addEventListener('pointerdown', function (e) {
            daCampo = !!e.target.closest('input, textarea');
            if (daCampo && e.pointerType === 'mouse') { x0 = null; return; }
            x0 = e.clientX; y0 = e.clientY; pid = e.pointerId;
        });
        row.addEventListener('pointermove', function (e) {
            if (x0 === null || e.pointerId !== pid) return;
            var dx = e.clientX - x0, dy = e.clientY - y0;
            /* orizzontale dichiarato: scorrendo la pagina in verticale con il
               dito appoggiato su una riga, il cestino non deve spuntare */
            if (Math.abs(dx) < Math.abs(dy) * 1.3) return;
            /* soglia più bassa di prima (28 px erano mezza riga); partendo da
               dentro una casella se ne chiede un po' di più, così un tocco
               storto per posizionare il cursore non apre niente */
            var soglia = daCampo ? 26 : 14;
            if (dx > soglia) { closeOthers(); item.classList.add('td-swiped'); }
            if (dx < -soglia) item.classList.remove('td-swiped');
        });
        function stop() { x0 = null; pid = null; }
        row.addEventListener('pointerup', stop);
        row.addEventListener('pointercancel', stop);
        row.addEventListener('pointerleave', stop);
    };

    /* Il cestino, in chiaro. Il gesto di scorrimento resta, ma non può essere
       l'unica strada: da computer non c'è, e sul telefono capitava di non
       azzeccarlo. Chiede conferma, perché un'attività cancellata non torna. */
    Todo.prototype.trashChip = function (t) {
        var self = this;
        var chip = el('button', 'td-chip td-chip-del');
        chip.type = 'button';
        chip.title = 'Elimina';
        chip.setAttribute('aria-label', 'Elimina attività');
        chip.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
        chip.addEventListener('click', function (e) {
            e.stopPropagation();
            self.confirmDelete(t);
        });
        return chip;
    };

    Todo.prototype.confirmDelete = function (t) {
        var self = this;
        var nome = (t.title || '').trim();
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', this.listType === 'buy' ? 'Elimino l\'articolo?' : 'Elimino l\'attività?'));
        card.appendChild(el('div', 'td-card-s', nome ? '«' + nome + '» non si potrà recuperare.' : 'Non si potrà recuperare.'));
        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var si = el('button', 'td-primary td-danger-btn', 'Elimina');
        si.type = 'button';
        si.addEventListener('click', function () {
            ov.remove();
            api('delete', { id: t.id }).then(function () { self.load(); });
        });
        acts.appendChild(no); acts.appendChild(si);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* il progetto sta su una riga tutta sua */
    Todo.prototype.toolsProject = function (t) {
        var self = this;
        var box = el('div', 'td-tools');
        /* Dentro un progetto il chip del progetto non serve, ma il cestino sì:
           prima qui la riga tornava vuota e l'attività non si poteva eliminare
           in nessun modo se non con lo scorrimento. */
        if (this.scope === 'project') { box.appendChild(this.trashChip(t)); return box; }
        var projChip = el('button', 'td-chip' + (t.project_id ? ' td-set' : ''));
        projChip.type = 'button';
        if (t.project_id) {
            var dot = el('span', 'td-dot');
            dot.style.background = t.project_color || '#B7AEA3';
            projChip.appendChild(dot);
            projChip.appendChild(el('span', null, t.project_name || 'Progetto'));
        } else {
            projChip.appendChild(el('span', null, 'Nessun progetto'));
        }
        projChip.addEventListener('click', function () { self.pickProject(t); });
        box.appendChild(projChip);
        box.appendChild(this.trashChip(t));
        return box;
    };

    /* data, ora e durata sulla loro riga: si toccano e si cambiano lì */
    Todo.prototype.toolsWhen = function (t) {
        var self = this;
        var box = el('div', 'td-tools');

        var dateChip = el('label', 'td-chip' + (t.date ? ' td-set' : ''));
        dateChip.innerHTML = ICON_CAL;
        var dateIn = el('input');
        dateIn.type = 'date';
        dateIn.value = t.date || '';
        dateIn.addEventListener('change', function () {
            self.save(t, { date: dateIn.value });
        });
        dateChip.appendChild(dateIn);
        if (t.date) {
            var d = parseIso(t.date);
            dateChip.appendChild(el('span', null, d.getDate() + ' ' + MESI[d.getMonth()]));
            /* Il campo resta sopra tutto il chip, invisibile ma cliccabile:
               prima lo riducevo a larghezza zero e da computer la data, una
               volta messa, non si poteva più cambiare. */
            dateIn.classList.add('td-input-cover');
        }
        box.appendChild(dateChip);

        var timeChip = el('label', 'td-chip' + (t.time ? ' td-set' : ''));
        timeChip.innerHTML = ICON_CLOCK;
        var timeIn = el('input');
        timeIn.type = 'time';
        timeIn.step = '300';
        timeIn.value = t.time || '';
        timeIn.addEventListener('change', function () { self.save(t, { time: timeIn.value }); });
        timeChip.appendChild(timeIn);
        box.appendChild(timeChip);

        if (t.time) {
            var durChip = el('label', 'td-chip td-set');
            durChip.innerHTML = ICON_HOUR;
            var durIn = el('input');
            durIn.type = 'number'; durIn.min = '1'; durIn.max = '1440';
            durIn.value = t.minutes || 30;
            durIn.addEventListener('change', function () { self.save(t, { minutes: durIn.value }); });
            durChip.appendChild(durIn);
            durChip.appendChild(el('span', null, 'min'));
            box.appendChild(durChip);
        }

        return box;
    };

    Todo.prototype.pickProject = function (t) {
        var self = this;
        var modal = document.getElementById('ps-modal-todo');
        if (modal && window.psOpen) {
            self._pending = t;
            window.psOpen(modal);
            return;
        }
        /* senza il selettore progetti sulla pagina, si toglie il collegamento */
        if (t.project_id) self.save(t, { project_id: 0 });
    };

    Todo.prototype.save = function (t, changes, keepFocus) {
        /* qualunque modifica, da qualunque pastiglia: la riga si segna e
           l'elenco smette di riordinarsi finché non si tocca fuori */
        this.inModifica(t.id);
        var self = this;
        var payload = {
            id: t.id,
            list_type: t.list_type || this.listType,
            title: changes.title !== undefined ? changes.title : t.title,
            parent_id: t.parent_id || 0,
            project_id: changes.project_id !== undefined ? changes.project_id : (t.project_id || 0),
            date: changes.date !== undefined ? changes.date : (t.date || ''),
            time: changes.time !== undefined ? changes.time : (t.time || ''),
            minutes: changes.minutes !== undefined ? changes.minutes : (t.minutes || 30),
            source: this.scope === 'pomodoro' ? 'pomodoro' : (this.scope === 'project' ? 'project' : 'page')
        };
        /* I campi del "to buy" si mandano solo se cambiano davvero, e si
           dichiarano in `buy_set`: il server lascia intatto quello che non gli
           viene nominato, così salvare il titolo non azzera prezzo e
           categoria, e svuotare il prezzo resta possibile. */
        var toccati = [];
        if (changes.cat_id !== undefined)   { payload.cat_id = changes.cat_id;     toccati.push('cat_id'); }
        if (changes.priority !== undefined) { payload.priority = changes.priority; toccati.push('priority'); }
        if (changes.price !== undefined)    { payload.price = changes.price;       toccati.push('price'); }
        if (changes.url !== undefined)      { payload.url = changes.url;           toccati.push('url'); }
        if (toccati.length) payload.buy_set = toccati;

        return api('save', payload).then(function (r) {
            if (!r.ok) return;
            if (r.todo) {
                /* teniamo aggiornata la copia in memoria senza toccare il DOM */
                for (var k in r.todo) if (r.todo.hasOwnProperty(k)) t[k] = r.todo[k];
            }
            /* Rilettura non immediata: è quella che riordina l'elenco, e
               farla scattare nell'istante del tocco fa sparire da sotto il dito
               la riga appena toccata. */
            if (!keepFocus) self.loadLater();
        });
    };

    Todo.prototype.addNew = function (parentId) {
        var self = this;
        /* nasce senza titolo: è il segnaposto a suggerire cosa scrivere,
           così non bisogna cancellare "Nuova attività" ogni volta */
        var payload = {
            id: 0, title: ' ', parent_id: parentId || 0,
            list_type: this.listType,
            project_id: this.scope === 'project' ? this.projectId : (this.opts.defaultProjectId || 0),
            date: this.opts.defaultDate || '', time: this.opts.defaultTime || '',
            minutes: 30,
            source: this.scope === 'pomodoro' ? 'pomodoro' : (this.scope === 'project' ? 'project' : 'page')
        };
        api('save', payload).then(function (r) {
            if (!r.ok) return;
            var newId = r.todo ? r.todo.id : 0;
            if (newId && self.scope === 'pomodoro') {
                self.sessionIds = self.sessionIds || [];
                self.sessionIds.push(newId);
            }
            self.load().then(function () {
                var inputs = self.list.querySelectorAll('.td-title');
                for (var i = 0; i < inputs.length; i++) {
                    if (!inputs[i].value.trim()) { inputs[i].focus(); break; }
                }
            });
        });
    };

    Todo.prototype.toggleSelect = function () {
        this.lasciaCampo();
        this.selecting = !this.selecting;
        this.picked = {};
        if (this.selBtn) {
            this.selBtn.textContent = this.selecting ? 'Fine' : 'Seleziona';
            this.selBtn.classList.toggle('td-on', this.selecting);
        }
        this.render();
    };

    /* quante ne ho scelte e, nel "to buy", quanto costano: si aggiorna a ogni
       tocco senza ridisegnare l'elenco */
    Todo.prototype.refreshCount = function () {
        if (!this.countBox) return;
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        this.countBox.innerHTML = '';
        if (this.listType === 'buy') {
            var scelti = this.items.filter(function (t) { return self.picked[t.id]; });
            this.countBox.appendChild(el('b', null, euro(sumCents(scelti))));
        }
        this.countBox.appendChild(document.createTextNode(ids.length ? ids.length + ' scelti' : 'nessuna scelta'));
    };

    Todo.prototype.bulkBar = function () {
        var self = this;
        var bar = el('div', 'td-bulk');

        if (this.listType === 'buy') {
            /* categoria e priorità a tutti insieme: è la ragione per cui la
               selezione esiste in questa scheda */
            var cat = el('button');
            cat.type = 'button';
            cat.innerHTML = ICON_TAG;
            cat.appendChild(el('span', null, 'Categoria'));
            cat.addEventListener('click', function () {
                var ids = Object.keys(self.picked).map(Number);
                if (!ids.length) return;
                self.catPicker(function (cid) {
                    api('bulk', { action: 'buy', ids: ids, buy_set: ['cat_id'], cat_id: cid }).then(function (r) {
                        if (!r.ok) return;
                        self.picked = {}; self.load();
                    });
                });
            });
            var pr = el('button');
            pr.type = 'button';
            pr.innerHTML = ICON_FLAG;
            pr.appendChild(el('span', null, 'Priorità'));
            pr.addEventListener('click', function () { self.bulkPriority(); });
            bar.appendChild(cat); bar.appendChild(pr);
        } else {
            var when = el('button');
            when.type = 'button';
            when.title = 'Data e ora';
            when.innerHTML = ICON_CAL;
            when.addEventListener('click', function () { self.bulkSchedule(); });

            var proj = el('button');
            proj.type = 'button';
            proj.appendChild(el('span', null, 'Progetto'));
            proj.addEventListener('click', function () {
                var modal = document.getElementById('ps-modal-todo');
                if (modal && window.psOpen) { self._pendingBulk = true; window.psOpen(modal); }
            });
            bar.appendChild(when); bar.appendChild(proj);
        }

        var del = el('button', 'td-danger');
        del.type = 'button';
        del.title = 'Elimina';
        del.innerHTML = ICON_TRASH;
        del.addEventListener('click', function () {
            var ids = Object.keys(self.picked).map(Number);
            if (!ids.length) return;
            api('bulk', { action: 'delete', ids: ids }).then(function () {
                self.picked = {}; self.load();
            });
        });
        bar.appendChild(del);

        this.countBox = el('span', 'td-count');
        bar.appendChild(this.countBox);
        this.refreshCount();
        return bar;
    };

    Todo.prototype.bulkPriority = function () {
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        if (!ids.length) return;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', 'Priorità'));
        card.appendChild(el('div', 'td-card-s', ids.length + ' articoli. 1 è la più alta.'));
        [1, 2, 3].forEach(function (p) {
            var b = el('button', 'td-catpick');
            b.type = 'button';
            b.appendChild(el('span', null, 'Priorità ' + p));
            b.addEventListener('click', function () {
                ov.remove();
                api('bulk', { action: 'buy', ids: ids, buy_set: ['priority'], priority: p }).then(function (r) {
                    if (!r.ok) return;
                    self.picked = {}; self.load();
                });
            });
            card.appendChild(b);
        });
        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(no);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    Todo.prototype.bulkSchedule = function () {
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        if (!ids.length) return;

        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', ids.length + (ids.length === 1 ? ' attività' : ' attività')));
        card.appendChild(el('div', 'td-card-s', 'Stessa data e ora per tutte. Lascia vuoto per togliere.'));

        function field(label, type, value) {
            var r = el('label', 'td-field');
            r.appendChild(el('span', null, label));
            var i = el('input');
            i.type = type;
            if (type === 'time') i.step = '300';
            if (type === 'number') { i.min = '1'; i.max = '1440'; }
            i.value = value;
            r.appendChild(i);
            card.appendChild(r);
            return i;
        }
        var dateIn = field('Data', 'date', this.today);
        var timeIn = field('Ora', 'time', '');
        var durIn  = field('Durata (min)', 'number', 30);

        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var yes = el('button', 'td-primary', 'Applica');
        yes.type = 'button';
        yes.addEventListener('click', function () {
            api('bulk', {
                action: 'schedule', ids: ids,
                date: dateIn.value, time: timeIn.value,
                minutes: parseInt(durIn.value, 10) || 30
            }).then(function (r) {
                ov.remove();
                if (!r.ok) return;
                self.picked = {};
                self.load();
            });
        });
        acts.appendChild(no); acts.appendChild(yes);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* il selettore progetti condiviso comunica così */
    document.addEventListener('projectselected', function (e) {
        if (!e.detail || e.detail.uid !== 'todo') return;
        var inst = window.nisamaTodo._last;
        if (!inst) return;
        var pid = e.detail.id > 0 ? e.detail.id : 0;
        if (inst._pendingBulk) {
            inst._pendingBulk = false;
            var ids = Object.keys(inst.picked).map(Number);
            if (ids.length) api('bulk', { action: 'project', ids: ids, project_id: pid })
                .then(function () { inst.picked = {}; inst.load(); });
            return;
        }
        if (inst._pending) {
            var t = inst._pending; inst._pending = null;
            inst.save(t, { project_id: pid });
        }
    });

    /* =================================================================
       PASTIGLIE E INTEGRATORI

       Due cose distinte, e si vedono separate anche qui: in alto le prese di
       oggi, una riga per ognuna, che è quello che serve dieci volte al giorno;
       sotto le terapie, che si aprono solo quando c'è da cambiarle.

       La spunta non chiede conferma e non riordina niente: se ogni mattina
       devi confermare due volte per dire che hai preso una pastiglia, dopo tre
       giorni smetti di aprirla. Il tocco lungo, invece, la segna come saltata:
       serve a distinguere "non l'ho presa" da "non ci ho ancora pensato", e
       quella differenza è il motivo per cui teniamo le prese una per una.
       ================================================================= */
    var PILL_GG = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];

    function pillOggi() {
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    Todo.prototype.loadPills = function () {
        var self = this;
        var ds = pillOggi();
        return api('pills', null, { date: ds }).then(function (r) {
            self.pills = (r && r.ok) ? r : { installed: false, plans: [], doses: [], stats: {} };
            self.pillDate = ds;
            self.render();
        });
    };

    Todo.prototype.renderPills = function () {
        var self = this;
        var p = this.pills || { installed: false, plans: [], doses: [], stats: {} };

        if (!p.installed) {
            this.list.appendChild(el('div', 'td-empty',
                'Sezione non ancora installata: manca la tabella delle pastiglie sul database.'));
            return;
        }

        /* --- le prese di oggi, in ordine di orologio --- */
        var doses = p.doses || [];
        if (!doses.length) {
            this.list.appendChild(el('div', 'td-empty',
                p.plans && p.plans.length ? 'Oggi non c\'è niente da prendere.'
                                          : 'Aggiungi la prima terapia qui sotto.'));
        }
        var oraOra = pad(new Date().getHours()) + ':' + pad(new Date().getMinutes());
        var ultimoOrario = null;
        doses.forEach(function (d) {
            if (d.time !== ultimoOrario) {
                ultimoOrario = d.time;
                var h = el('div', 'td-pill-hour');
                h.appendChild(el('span', null, d.time));
                /* in ritardo: l'ora è passata e la spunta non è arrivata */
                if (d.status === 'pending' && d.time < oraOra) {
                    h.appendChild(el('span', 'td-pill-late', 'in ritardo'));
                }
                self.list.appendChild(h);
            }
            self.list.appendChild(self.pillDoseRow(d));
        });

        /* --- come sta andando --- */
        var st = p.stats || {};
        if ((st.taken || 0) + (st.skipped || 0) + (st.missed || 0) > 0) {
            this.list.appendChild(el('div', 'td-pill-stats',
                'Ultimi 30 giorni: ' + (st.taken || 0) + ' prese, ' +
                (st.skipped || 0) + ' saltate, ' + (st.missed || 0) + ' dimenticate.'));
        }

        /* --- le terapie --- */
        this.list.appendChild(el('div', 'td-pill-sect', 'Le mie terapie'));
        (p.plans || []).forEach(function (pl) {
            var r = el('button', 'td-pill-plan' + (pl.active ? '' : ' td-off'));
            r.type = 'button';
            var dot = el('span', 'td-pill-dot');
            dot.style.background = pl.color || '#B7A6D6';
            r.appendChild(dot);
            var box = el('span', 'td-pill-planbox');
            box.appendChild(el('span', 'td-pill-name', pl.name + (pl.dose ? '  ·  ' + pl.dose : '')));
            box.appendChild(el('span', 'td-pill-sub', pillRitmo(pl)));
            r.appendChild(box);
            r.addEventListener('click', function () { self.pillEditor(pl); });
            self.list.appendChild(r);
        });

        var add = el('button', 'td-add');
        add.type = 'button';
        add.innerHTML = ICON_PLUS;
        add.appendChild(el('span', null, 'Nuova terapia'));
        add.addEventListener('click', function () { self.pillEditor(null); });
        this.list.appendChild(add);
    };

    /* Il ritmo detto a parole: "ogni giorno, 10:00 ×2 e 20:00" si legge, una
       riga di codici no. */
    function pillRitmo(pl) {
        var q = [];
        if (pl.freq_type === 'monthly') {
            q.push('il ' + (pl.month_days || '?') + ' di ogni mese');
        } else if (pl.freq_type === 'interval') {
            q.push(pl.interval_days === 1 ? 'ogni giorno' : 'ogni ' + pl.interval_days + ' giorni');
        } else {
            var w = String(pl.weekdays || '1111111');
            if (w.indexOf('0') < 0) {
                q.push('ogni giorno');
            } else {
                var gg = [];
                for (var i = 0; i < 7; i++) if (w.charAt(i) === '1') gg.push(PILL_GG[i]);
                q.push(gg.length ? gg.join(', ') : 'mai');
            }
        }
        var ore = String(pl.times || '').split(',').map(function (t) {
            var p = t.split('x');
            return p[0] + (p[1] && +p[1] > 1 ? ' ×' + p[1] : '');
        });
        if (ore.length) q.push(ore.join(', '));
        if (pl.end_date) q.push('fino al ' + pl.end_date.slice(8, 10) + '/' + pl.end_date.slice(5, 7));
        if (!pl.active) q.push('sospesa');
        return q.join('  ·  ');
    }

    Todo.prototype.pillDoseRow = function (d) {
        var self = this;
        var r = el('div', 'td-pill-row td-pill-' + d.status);

        var box = el('button', 'td-pill-check');
        box.type = 'button';
        box.setAttribute('aria-label', d.status === 'taken' ? 'Presa' : 'Segna come presa');
        box.style.setProperty('--pill', d.color || '#B7A6D6');
        if (d.status === 'taken') {
            box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
        } else if (d.status === 'skipped') {
            box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>';
        }

        function segna(stato) {
            /* la riga cambia subito e la chiamata parte dopo: qui l'attesa di
               una risposta dal server si sentirebbe tutta */
            d.status = stato;
            d.taken_at = stato === 'taken' ? new Date().toISOString() : null;
            self.renderLater(120);
            api('pill_mark', { id: d.id, status: stato });
            if (navigator.vibrate) { try { navigator.vibrate(stato === 'taken' ? 12 : 6); } catch (e) {} }
        }

        var lungo = null;
        box.addEventListener('pointerdown', function () {
            /* tocco lungo = saltata. È un gesto nascosto, ma la spunta normale
               deve restare di un tocco solo: è quella che si usa ogni giorno. */
            lungo = setTimeout(function () { lungo = null; segna('skipped'); }, 550);
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
            box.addEventListener(ev, function () { if (lungo) { clearTimeout(lungo); lungo = null; } });
        });
        box.addEventListener('click', function () {
            if (d.status === 'taken') segna('pending');
            else segna('taken');
        });

        var box2 = el('div', 'td-pill-txt');
        box2.appendChild(el('div', 'td-pill-name', d.name + (d.dose ? '  ·  ' + d.dose : '')));
        var sotto = [];
        if (d.notes) sotto.push(d.notes);
        if (d.status === 'taken' && d.taken_at) {
            sotto.push('presa alle ' + String(d.taken_at).slice(11, 16));
        }
        if (d.status === 'skipped') sotto.push('saltata');
        if (sotto.length) box2.appendChild(el('div', 'td-pill-sub', sotto.join('  ·  ')));

        r.appendChild(box);
        r.appendChild(box2);
        return r;
    };

    /* La scheda della terapia. Un campo per volta, nell'ordine in cui uno ci
       pensa: cosa prendo, con che ritmo, a che ore, da quando a quando. */
    Todo.prototype.pillEditor = function (pl) {
        var self = this;
        var nuovo = !pl;
        pl = pl || {
            id: 0, name: '', dose: '', notes: '', color: '#B7A6D6',
            freq_type: 'daily', weekdays: '1111111', month_days: '', interval_days: 1,
            times: '08:00x1', start_date: pillOggi(), end_date: null, active: true
        };
        var stato = {
            freq: pl.freq_type,
            wd: String(pl.weekdays || '1111111').split(''),
            times: String(pl.times || '08:00x1').split(',').map(function (t) {
                var p = t.split('x');
                return { ora: p[0] || '08:00', n: Math.max(1, parseInt(p[1] || '1', 10)) };
            })
        };

        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card td-card-wide');
        card.appendChild(el('div', 'td-card-t', nuovo ? 'Nuova terapia' : 'Terapia'));

        function campo(lab, node) {
            var f = el('div', 'td-pill-field');
            f.appendChild(el('label', 'td-pill-lab', lab));
            f.appendChild(node);
            card.appendChild(f);
            return node;
        }
        function testo(val, ph, max) {
            var i = el('input', 'td-in');
            i.type = 'text'; i.value = val || ''; i.placeholder = ph || ''; i.maxLength = max || 120;
            return i;
        }

        var iNome  = campo('Cosa', testo(pl.name, 'Vitamina D, magnesio…', 120));
        var iDose  = campo('Quanto', testo(pl.dose, 'una compressa, 10 gocce…', 60));
        var iNote  = campo('Nota', testo(pl.notes, 'a stomaco pieno, lontano dal caffè…', 255));

        /* --- ritmo --- */
        var rit = el('div', 'td-pill-seg');
        var ritBtn = {};
        [['daily', 'giorni della settimana'], ['monthly', 'giorni del mese'], ['interval', 'ogni N giorni']]
        .forEach(function (o) {
            var b = el('button', 'td-pill-segb' + (stato.freq === o[0] ? ' td-on' : ''), o[1]);
            b.type = 'button';
            b.addEventListener('click', function () {
                stato.freq = o[0];
                for (var k in ritBtn) ritBtn[k].classList.toggle('td-on', k === o[0]);
                disegnaRitmo();
            });
            ritBtn[o[0]] = b;
            rit.appendChild(b);
        });
        campo('Quando', rit);
        var ritBox = el('div', 'td-pill-ritmo');
        card.appendChild(ritBox);

        var iMese = testo(pl.month_days, 'per esempio 13 oppure 1,15', 100);
        var iInt = el('input', 'td-in');
        iInt.type = 'number'; iInt.min = '1'; iInt.max = '365'; iInt.value = pl.interval_days || 1;

        function disegnaRitmo() {
            ritBox.innerHTML = '';
            if (stato.freq === 'daily') {
                var gg = el('div', 'td-pill-days');
                PILL_GG.forEach(function (g, i) {
                    var b = el('button', 'td-pill-day' + (stato.wd[i] === '1' ? ' td-on' : ''), g);
                    b.type = 'button';
                    b.addEventListener('click', function () {
                        stato.wd[i] = stato.wd[i] === '1' ? '0' : '1';
                        b.classList.toggle('td-on', stato.wd[i] === '1');
                    });
                    gg.appendChild(b);
                });
                ritBox.appendChild(gg);
            } else if (stato.freq === 'monthly') {
                ritBox.appendChild(iMese);
            } else {
                ritBox.appendChild(iInt);
            }
        }
        disegnaRitmo();

        /* --- orari --- */
        var oreBox = el('div', 'td-pill-ore');
        function disegnaOre() {
            oreBox.innerHTML = '';
            stato.times.forEach(function (t, idx) {
                var r = el('div', 'td-pill-ora');
                var i = el('input', 'td-in');
                i.type = 'time'; i.step = '60'; i.value = t.ora;
                i.addEventListener('change', function () { t.ora = i.value || '08:00'; });
                r.appendChild(i);
                /* quante volte a quell'ora: due compresse alle 10 sono una riga
                   sola nella terapia, ma restano due spunte nella giornata */
                var meno = el('button', 'td-pill-step', '−'); meno.type = 'button';
                var quante = el('span', 'td-pill-n', '×' + t.n);
                var piu = el('button', 'td-pill-step', '+'); piu.type = 'button';
                meno.addEventListener('click', function () {
                    t.n = Math.max(1, t.n - 1); quante.textContent = '×' + t.n;
                });
                piu.addEventListener('click', function () {
                    t.n = Math.min(9, t.n + 1); quante.textContent = '×' + t.n;
                });
                r.appendChild(meno); r.appendChild(quante); r.appendChild(piu);
                var via = el('button', 'td-pill-step', '×'); via.type = 'button';
                via.addEventListener('click', function () {
                    if (stato.times.length <= 1) return;   // almeno un orario deve restare
                    stato.times.splice(idx, 1); disegnaOre();
                });
                r.appendChild(via);
                oreBox.appendChild(r);
            });
            var piuOra = el('button', 'td-pill-addora', '+ un altro orario');
            piuOra.type = 'button';
            piuOra.addEventListener('click', function () {
                stato.times.push({ ora: '20:00', n: 1 });
                disegnaOre();
            });
            oreBox.appendChild(piuOra);
        }
        disegnaOre();
        campo('A che ora', oreBox);

        var iDa = el('input', 'td-in'); iDa.type = 'date'; iDa.value = pl.start_date || pillOggi();
        campo('Da', iDa);
        var iA = el('input', 'td-in'); iA.type = 'date'; iA.value = pl.end_date || '';
        campo('Fino a (vuoto = sempre)', iA);

        var att = el('button', 'td-pill-att' + (pl.active ? ' td-on' : ''), pl.active ? 'Attiva' : 'Sospesa');
        att.type = 'button';
        att.dataset.on = pl.active ? '1' : '0';
        att.addEventListener('click', function () {
            var on = att.dataset.on !== '1';
            att.dataset.on = on ? '1' : '0';
            att.classList.toggle('td-on', on);
            att.textContent = on ? 'Attiva' : 'Sospesa';
        });
        campo('Stato', att);

        var acts = el('div', 'td-card-acts');
        var ann = el('button', null, 'Annulla');
        ann.type = 'button';
        ann.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(ann);

        if (!nuovo) {
            var del = el('button', null, 'Elimina');
            del.type = 'button';
            del.addEventListener('click', function () {
                ov.remove();
                api('pill_del', { id: pl.id }).then(function (r) {
                    if (r.ok) self.loadPills();
                });
            });
            acts.appendChild(del);
        }

        var ok = el('button', 'td-primary', 'Salva');
        ok.type = 'button';
        ok.addEventListener('click', function () {
            if (!iNome.value.trim()) { iNome.focus(); return; }
            ok.disabled = true;
            api('pill_save', {
                id: pl.id || 0,
                name: iNome.value, dose: iDose.value, notes: iNote.value, color: pl.color,
                freq_type: stato.freq,
                weekdays: stato.wd.join(''),
                month_days: iMese.value,
                interval_days: iInt.value,
                times: stato.times.map(function (t) { return t.ora + 'x' + t.n; }).join(','),
                start_date: iDa.value, end_date: iA.value,
                active: att.dataset.on === '1'
            }).then(function (r) {
                ok.disabled = false;
                if (!r.ok) return;
                ov.remove();
                self.loadPills();
            });
        });
        acts.appendChild(ok);
        card.appendChild(acts);

        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
        iNome.focus();
    };

    window.nisamaTodo = {
        mount: function (host, opts) {
            var inst = new Todo(host, opts);
            this._last = inst;
            return inst;
        },
        api: api
    };
})();
</script>
<?php endif; ?>