<?php
/* =====================================================================
   NISAMA — DIARIO DEL GIORNO (popup condiviso)
   Da includere una volta sola, in fondo alla pagina:

       include 'diario_giorno.php';

   Poi si apre da qualunque punto con:

       nisamaOpenDiary('2026-07-25');            // stile della pagina
       nisamaOpenDiary('2026-07-25', 'habits');  // forza uno stile

   Lo stile si adatta: 'planner' usa la carta pastello del planner,
   'habits' resta identico a com'è ora nella pagina delle abitudini.
   Scrive tutto tramite diary_api.php, quindi umore, energia, testo e i
   due elenchi sono gli stessi che vede la pagina del diario.

   Espone anche window.nisamaFace(kind, level, size): le faccine
   disegnate a matita, riusate dalla pagina diario.
   ===================================================================== */
if (!defined('NISAMA_DIARY_POPUP')):
define('NISAMA_DIARY_POPUP', true);
if (!function_exists('sec_csrf_token')) { require_once __DIR__ . '/security_core.php'; }
?>
<div class="dg-ov" id="dgOv" data-skin="planner" aria-hidden="true">
    <div class="dg-sheet" role="dialog" aria-label="Diario del giorno">
        <span class="dg-grab"></span>
        <div class="dg-head">
            <span class="dg-date" id="dgDate">Oggi</span>
            <button type="button" class="dg-txt" id="dgClose">Chiudi</button>
        </div>
        <div class="dg-body">
            <div class="dg-faces">
                <div class="dg-face-block">
                    <span class="dg-lab">Com'è andata</span>
                    <div class="dg-face-row" id="dgMood"></div>
                </div>
                <div class="dg-face-block">
                    <span class="dg-lab">Energia</span>
                    <div class="dg-face-row" id="dgEnergy"></div>
                </div>
            </div>

            <div class="dg-field">
                <span class="dg-lab">Cose belle di oggi</span>
                <div class="dg-list" id="dgGood"></div>
            </div>

            <div class="dg-field">
                <span class="dg-lab">Cosa ho imparato oggi</span>
                <div class="dg-list" id="dgLearned"></div>
            </div>

            <div class="dg-field">
                <div class="dg-lab-row">
                    <span class="dg-lab">Com'è andata oggi</span>
                    <button type="button" class="dg-mic" id="dgMic" hidden aria-label="Detta invece di scrivere">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0013 0"/><path d="M12 18v3"/></svg>
                        <span id="dgMicLab">Detta</span>
                    </button>
                </div>
                <textarea class="dg-text" id="dgText" rows="6" maxlength="20000"
                          placeholder="Scrivi come vuoi. Si salva da solo."></textarea>
            </div>

            <div class="dg-foot">
                <span class="dg-saved" id="dgSaved">Salvato</span>
                <a class="dg-txt dg-link" id="dgOpenFull" href="diario.php">Apri il diario</a>
            </div>
        </div>
    </div>
</div>

<div class="dg-peek-ov" id="dgPeekOv" aria-hidden="true">
    <div class="dg-peek" role="dialog" aria-label="Pagina di diario">
        <span class="dg-peek-tape" aria-hidden="true"></span>
        <div class="dg-peek-doodles" id="dgPeekFaces" aria-hidden="true"></div>
        <div class="dg-peek-date" id="dgPeekDate"></div>
        <div class="dg-peek-body" id="dgPeekBody"></div>
        <div class="dg-peek-foot">
            <a class="dg-peek-link" id="dgPeekOpen" href="diario.php">Apri nel diario</a>
        </div>
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Caveat:wght@400;500&display=swap');
/* ---------- token: due vestiti per lo stesso popup ---------- */
.dg-ov{
    --dg-paper:#FDFCF9; --dg-panel:#F5F1EA; --dg-ink:#4C463F; --dg-ink-2:#8A8177; --dg-ink-3:#B7AEA3;
    --dg-hair:rgba(76,70,63,.12); --dg-accent:#A9C3AE; --dg-accent-soft:#E7EFE8;
    --dg-r:26px; --dg-r-sm:14px;
    --dg-serif:'Cormorant Garamond', Georgia, serif;
    --dg-sans:'Jost', -apple-system, system-ui, sans-serif;
    --dg-shadow:0 -10px 50px -12px rgba(76,70,63,.32);
    position:fixed; inset:0; z-index:9900; display:flex; align-items:flex-end; justify-content:center;
    background:rgba(76,70,63,.24); -webkit-backdrop-filter:blur(3px); backdrop-filter:blur(3px);
    opacity:0; pointer-events:none; transition:opacity .32s cubic-bezier(.22,.61,.36,1);
}
.dg-ov[data-skin="habits"]{
    --dg-paper:var(--surface, #fff); --dg-panel:var(--surface-2, #F2F2F7);
    --dg-ink:var(--text, #1C1C1E); --dg-ink-2:var(--text-2, #8A8A8E); --dg-ink-3:var(--text-3, #AEAEB2);
    --dg-hair:var(--hairline, rgba(60,60,67,.12));
    --dg-accent:var(--accent, #7C5CFF); --dg-accent-soft:color-mix(in srgb, var(--accent, #7C5CFF) 16%, transparent);
    --dg-r:24px; --dg-r-sm:14px;
    --dg-serif:-apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    --dg-sans:-apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    background:rgba(0,0,0,.4);
}
.dg-ov.dg-open{ opacity:1; pointer-events:auto; }
.dg-sheet{
    width:100%; max-width:620px; max-height:88dvh; background:var(--dg-paper); color:var(--dg-ink);
    border-radius:var(--dg-r) var(--dg-r) 0 0; box-shadow:var(--dg-shadow);
    display:flex; flex-direction:column; overflow:hidden;
    transform:translateY(101%); transition:transform .44s cubic-bezier(.22,.61,.36,1);
    padding-bottom:env(safe-area-inset-bottom, 0px);
    font-family:var(--dg-sans);
}
.dg-ov.dg-open .dg-sheet{ transform:none; }
.dg-grab{ width:34px; height:4px; border-radius:99px; background:var(--dg-hair); margin:8px auto 0; flex-shrink:0; }
.dg-head{ display:flex; align-items:center; justify-content:space-between; padding:10px 16px 8px; flex-shrink:0; }
.dg-date{ font-family:var(--dg-serif); font-size:20px; font-weight:500; }
.dg-ov[data-skin="habits"] .dg-date{ font-size:17px; font-weight:700; }
.dg-txt{ border:none; background:none; font:inherit; font-size:14.5px; color:var(--dg-ink-2); cursor:pointer; padding:4px 2px; }
.dg-link{ text-decoration:none; }
.dg-body{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:2px 16px 20px; }
.dg-body::-webkit-scrollbar{ width:0; }
.dg-lab{
    display:block; font-size:9.5px; font-weight:500; letter-spacing:.18em; text-transform:uppercase;
    color:var(--dg-ink-3); margin:0 2px 7px;
}
.dg-lab-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
.dg-mic{
    display:flex; align-items:center; gap:6px; margin:0 2px 7px;
    padding:5px 11px; border-radius:999px; border:none; cursor:pointer;
    background:var(--dg-panel); color:var(--dg-ink-2); font:inherit; font-size:11.5px;
    transition:background-color .25s, color .25s, transform .15s;
}
.dg-mic svg{ width:13px; height:13px; }
.dg-mic:active{ transform:scale(.94); }
.dg-mic.dg-rec{ background:#F3DCDC; color:#8E5A5A; }
.dg-mic.dg-rec svg{ animation:dg-pulse 1.3s ease-in-out infinite; }
@keyframes dg-pulse{ 0%,100%{ opacity:1; } 50%{ opacity:.35; } }
.dg-field{ margin-bottom:16px; }
.dg-faces{ display:flex; gap:10px; margin-bottom:16px; }
.dg-face-block{ flex:1; min-width:0; }
.dg-face-row{ display:flex; gap:4px; }
.dg-face{
    flex:1; aspect-ratio:1; max-width:46px; border-radius:50%; padding:2px;
    color:var(--dg-ink-3); background:none; border:none; cursor:pointer;
    transition:transform .26s cubic-bezier(.34,1.3,.64,1), color .26s, background-color .26s;
}
.dg-face svg{ width:100%; height:100%; display:block; }
.dg-face.dg-on{ color:var(--dg-ink); background:var(--dg-accent-soft); transform:scale(1.08); }

/* elenchi con il pallino */
.dg-li{ display:flex; align-items:center; gap:9px; padding:5px 0; border-bottom:.5px solid var(--dg-hair); }
.dg-li:last-child{ border-bottom:none; }
.dg-dot{
    width:7px; height:7px; border-radius:50%; flex-shrink:0;
    background:var(--dg-ink-3); transition:background-color .3s, transform .3s;
}
.dg-li-filled .dg-dot{ background:var(--dg-accent); transform:scale(1.25); }
.dg-li input{
    flex:1; min-width:0; border:none; background:none; font:inherit; font-size:15px; color:var(--dg-ink);
    padding:4px 0; outline:none;
}
.dg-li input::placeholder{ color:var(--dg-ink-3); }
.dg-li-x{ border:none; background:none; color:var(--dg-ink-3); cursor:pointer; padding:4px; display:none; }
.dg-li-filled .dg-li-x{ display:block; }
.dg-li-x svg{ width:13px; height:13px; }

.dg-text{
    width:100%; border:none; border-radius:var(--dg-r-sm); background:var(--dg-panel); color:var(--dg-ink);
    padding:13px 14px; font:inherit; font-size:16px; line-height:1.6; resize:none; min-height:130px;
    box-shadow:inset 0 0 0 .5px var(--dg-hair);
}
.dg-ov[data-skin="planner"] .dg-text{ font-family:var(--dg-serif); font-size:17px; }
.dg-text::placeholder{ color:var(--dg-ink-3); }

.dg-foot{ display:flex; align-items:center; justify-content:space-between; padding-top:2px; }
.dg-saved{ font-size:11.5px; color:var(--dg-ink-3); opacity:0; transition:opacity .3s; }
.dg-saved.dg-show{ opacity:1; }
/* ---------- il foglio scritto a mano (sola lettura) ---------- */
.dg-peek-ov{
    position:fixed; inset:0; z-index:9910; display:grid; place-items:center; padding:22px;
    background:rgba(76,70,63,.3); -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
    opacity:0; pointer-events:none; transition:opacity .3s cubic-bezier(.22,.61,.36,1);
}
.dg-peek-ov.dg-open{ opacity:1; pointer-events:auto; }
.dg-peek{
    position:relative; width:100%; max-width:330px; max-height:78dvh;
    background:#FDFCF9; color:#4A443D;
    border-radius:3px 3px 12px 12px; padding:24px 20px 14px;
    box-shadow:0 26px 56px -22px rgba(74,68,61,.55), inset 0 0 0 .5px rgba(74,68,61,.10);
    transform:scale(.9) rotate(-.8deg); opacity:0;
    transition:transform .42s cubic-bezier(.34,1.3,.64,1), opacity .26s;
    display:flex; flex-direction:column; overflow:hidden;
}
.dg-peek-ov.dg-open .dg-peek{ transform:scale(1) rotate(-.8deg); opacity:1; }
.dg-peek-tape{
    position:absolute; top:-11px; left:50%; width:76px; height:21px;
    transform:translateX(-50%) rotate(-2.6deg);
    background:rgba(255,255,255,.66); border-radius:1px;
    box-shadow:0 2px 6px rgba(74,68,61,.15), inset 0 0 0 .5px rgba(255,255,255,.85);
}
.dg-peek-doodles{
    position:absolute; top:12px; right:12px; display:flex; gap:2px;
    color:#8C857A; opacity:.85;
}
.dg-peek-doodles > *{ width:30px; height:30px; }
.dg-peek-doodles > *:first-child{ transform:rotate(-8deg); }
.dg-peek-doodles > *:last-child{ transform:rotate(7deg) translateY(3px); }
.dg-peek-date{
    font-family:'Cormorant Garamond', Georgia, serif; font-size:19px; color:#4A443D;
    padding-right:70px; margin-bottom:10px;
}
.dg-peek-body{
    flex:1; overflow-y:auto; scrollbar-width:none;
    background-image:linear-gradient(transparent 25px, rgba(120,133,148,.18) 25px, rgba(120,133,148,.18) 26px);
    background-size:100% 26px; background-attachment:local;
}
.dg-peek-body::-webkit-scrollbar{ width:0; height:0; }
.dg-peek-body p, .dg-peek-body li{
    font-family:'Caveat', cursive; font-size:18px; line-height:26px; margin:0; color:#4A443D;
    font-style:normal;
}
.dg-peek-body ul{ margin:0 0 4px; padding-left:16px; }
.dg-peek-body li{ list-style:none; position:relative; }
.dg-peek-body li::before{
    content:''; position:absolute; left:-11px; top:11px; width:4px; height:4px;
    border-radius:50%; background:#A9C3AE;
}
.dg-peek-k{
    display:block; font-family:'Jost', system-ui, sans-serif;
    font-size:8.5px; letter-spacing:.18em; text-transform:uppercase; color:#BAB1A6;
    line-height:26px;
}
.dg-peek-foot{ padding-top:8px; text-align:right; }
.dg-peek-link{
    font-family:'Jost', system-ui, sans-serif; font-size:12px; color:#8A8177; text-decoration:none;
    border-bottom:.8px solid #BAB1A6; padding-bottom:1px;
}
@media (prefers-reduced-motion: reduce){
    .dg-ov, .dg-sheet, .dg-face, .dg-peek{ transition-duration:.01ms; }
}
</style>

<script>
(function () {
    if (window.nisamaOpenDiary) return;

    var CSRF = <?php echo json_encode(sec_csrf_token()); ?>;
    var GIORNI = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
    var MESI = ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'];

    var state = { date: null, data: null, timers: {} };
    var ov = document.getElementById('dgOv');

    function $(id) { return document.getElementById(id); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }

    /* ---------- le faccine, disegnate come a matita ---------- */
    var HEAD = 'M20 3.4c9.2 0 16.4 7.2 16.6 16.4.2 9.4-7.2 16.8-16.6 16.8S3.2 29.4 3.4 19.8C3.6 10.6 10.8 3.4 20 3.4z';
    var MOUTHS = {
        1: 'M13 27.5c1.8-3.4 5.6-3.4 7 -3.4 1.4 0 5.2 0 7 3.4',
        2: 'M13 26.4c2 -1.6 5.4-1.6 7 -1.6 1.6 0 5 0 7 1.6',
        3: 'M13.4 25.6h13.2',
        4: 'M13 24.2c2 1.8 5.4 1.8 7 1.8 1.6 0 5 0 7 -1.8',
        5: 'M12.4 23c1.4 3.8 5.4 5 7.6 5 2.2 0 6.2-1.2 7.6-5'
    };
    var EYES = {
        1: '<path d="M13.6 18.6c1-1.2 2.6-1.2 3.6 0"/><path d="M22.8 18.6c1-1.2 2.6-1.2 3.6 0"/>',
        2: '<path d="M14 18.4h3.2"/><path d="M22.8 18.4h3.2"/>',
        3: '<circle cx="15.4" cy="18" r="1.35" fill="currentColor" stroke="none"/><circle cx="24.6" cy="18" r="1.35" fill="currentColor" stroke="none"/>',
        4: '<circle cx="15.4" cy="17.8" r="1.7" fill="currentColor" stroke="none"/><circle cx="24.6" cy="17.8" r="1.7" fill="currentColor" stroke="none"/>',
        5: '<circle cx="15.4" cy="17.6" r="2.5"/><circle cx="24.6" cy="17.6" r="2.5"/><circle cx="15.4" cy="17.6" r=".9" fill="currentColor" stroke="none"/><circle cx="24.6" cy="17.6" r=".9" fill="currentColor" stroke="none"/>'
    };
    var SPARKS = {
        4: '<path d="M31 8.6l1.8-2.2M34.2 12l2.4-.8"/>',
        5: '<path d="M31 8.6l1.8-2.2M34.2 12l2.4-.8M28.4 6.2l.6-2.6"/>'
    };

    /* L'energia non usa faccine: usa una stellina, così a colpo d'occhio non
       si confonde con "com'è andata". Cinque posture:
       1 sdraiata a terra, punta che cade   2 seduta, spossata
       3 in piedi ma un po' giù             4 in piedi contenta
       5 che salta, con gli scatti di gioia intorno                        */
    var STAR_BODY = 'M20 4.6l4.2 8.8 9.6 1.3-7 6.7 1.7 9.5-8.5-4.7-8.5 4.7 1.7-9.5-7-6.7 9.6-1.3z';
    var STARS = {
        1: '<g transform="translate(20 26) rotate(-74) scale(.72) translate(-20 -20)">'
         + '<path d="' + STAR_BODY + '"/></g>'
         + '<path d="M6 35h28" opacity=".55"/>'
         + '<path d="M12.5 19.5c-1.6 1.4-2.6 3.2-3 5.2" opacity=".8"/>',
        2: '<g transform="translate(20 24) rotate(-16) scale(.8) translate(-20 -20)">'
         + '<path d="' + STAR_BODY + '"/></g>'
         + '<path d="M7 35h26" opacity=".55"/>',
        3: '<g transform="translate(20 21) rotate(-7) scale(.86) translate(-20 -20)">'
         + '<path d="' + STAR_BODY + '"/></g>'
         + '<path d="M8 35h24" opacity=".45"/>',
        4: '<g transform="translate(20 20) scale(.92) translate(-20 -20)">'
         + '<path d="' + STAR_BODY + '"/></g>'
         + '<path d="M9 36h22" opacity=".35"/>',
        5: '<g transform="translate(20 18) scale(.96) translate(-20 -20)">'
         + '<path d="' + STAR_BODY + '"/></g>'
         + '<path d="M6.5 12.5l-2.4-1.6M33.5 12.5l2.4-1.6M20 3.2V.6"/>'
         + '<path d="M11 35.5c3-1.6 15-1.6 18 0" opacity=".45"/>'
    };

    /**
     * kind: 'mood' oppure 'energy'. level: 1..5, oppure 0/null = da scegliere.
     * Ritorna il markup svg: matita morbida, niente colore pieno.
     */
    function nisamaFace(kind, level, size) {
        var s = size || 40;
        var open = '<svg viewBox="0 0 40 40" width="' + s + '" height="' + s + '" fill="none" stroke="currentColor" '
                 + 'stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">';
        if (!level) {
            var empty = kind === 'energy' ? STAR_BODY : HEAD;
            return open + '<path d="' + empty + '" stroke-dasharray="3.4 3.6" opacity=".75"/></svg>';
        }
        var lv = Math.max(1, Math.min(5, level));
        if (kind === 'energy') return open + STARS[lv] + '</svg>';
        var inner = '<path d="' + HEAD + '"/>'
                  + EYES[lv >= 4 ? 3 : (lv === 1 ? 1 : 2)]
                  + '<path d="' + MOUTHS[lv] + '"/>';
        return open + inner + '</svg>';
    }
    window.nisamaFace = nisamaFace;

    /* ---------- rete ---------- */
    function api(action, data, query) {
        var url = 'diary_api.php?ajax=' + encodeURIComponent(action);
        if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
        var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF } };
        if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
        return fetch(url, opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    function later(key, ms, fn) {
        if (state.timers[key]) clearTimeout(state.timers[key]);
        state.timers[key] = setTimeout(fn, ms);
    }
    function flashSaved() {
        var n = $('dgSaved');
        n.classList.add('dg-show');
        later('saved', 1400, function () { n.classList.remove('dg-show'); });
    }
    function announce() {
        document.dispatchEvent(new CustomEvent('diaryupdated', { detail: { date: state.date } }));
    }

    /* ---------- disegno ---------- */
    function drawFaces() {
        [['dgMood', 'mood'], ['dgEnergy', 'energy']].forEach(function (pair) {
            var host = $(pair[0]);
            var kind = pair[1];
            var current = state.data[kind];
            host.innerHTML = '';
            for (var lv = 1; lv <= 5; lv++) {
                (function (level) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'dg-face' + (current === level ? ' dg-on' : '');
                    b.innerHTML = nisamaFace(kind, level, 40);
                    b.setAttribute('aria-label', kind + ' ' + level);
                    b.addEventListener('click', function () {
                        var next = state.data[kind] === level ? null : level;
                        state.data[kind] = next;
                        drawFaces();
                        if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
                        api('save_mood', { date: state.date, which: kind, value: next === null ? '' : next })
                            .then(function () { flashSaved(); announce(); });
                    });
                    host.appendChild(b);
                })(lv);
            }
        });
    }

    function drawList(hostId, type) {
        var host = $(hostId);
        var items = (state.data[type === 'good' ? 'good' : 'learned'] || []).slice();
        host.innerHTML = '';
        items.push({ id: 0, content: '' });   // la riga vuota in fondo
        items.forEach(function (it) { host.appendChild(listRow(it, type, hostId)); });
    }

    function listRow(it, type, hostId) {
        var row = document.createElement('div');
        row.className = 'dg-li' + (it.content ? ' dg-li-filled' : '');
        var dot = document.createElement('span');
        dot.className = 'dg-dot';
        var input = document.createElement('input');
        input.type = 'text';
        input.value = it.content || '';
        input.maxLength = 300;
        input.placeholder = it.id ? '' : (type === 'good' ? 'Una cosa bella…' : 'Una cosa imparata…');
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'dg-li-x';
        x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';

        row.appendChild(dot); row.appendChild(input); row.appendChild(x);

        function save(redraw) {
            var text = input.value.trim();
            if (!it.id && !text) return;
            api('list_save', { date: state.date, list_type: type, item_id: it.id || 0, content: text })
                .then(function (r) {
                    if (!r.ok) return;
                    var arr = state.data[type === 'good' ? 'good' : 'learned'];
                    if (r.deleted) {
                        state.data[type === 'good' ? 'good' : 'learned'] = arr.filter(function (a) { return a.id !== it.id; });
                    } else if (r.id && !it.id) {
                        it.id = r.id; it.content = text; arr.push(it);
                    } else {
                        it.content = text;
                    }
                    flashSaved(); announce();
                    if (redraw) drawList(hostId, type);
                });
        }
        input.addEventListener('input', function () {
            row.classList.toggle('dg-li-filled', !!input.value.trim());
            later('li' + type + (it.id || 'new'), 700, function () { save(!it.id); });
        });
        input.addEventListener('blur', function () { save(true); });
        x.addEventListener('click', function () {
            if (!it.id) { input.value = ''; row.classList.remove('dg-li-filled'); return; }
            api('list_delete', { item_id: it.id }).then(function (r) {
                if (!r.ok) return;
                var key = type === 'good' ? 'good' : 'learned';
                state.data[key] = state.data[key].filter(function (a) { return a.id !== it.id; });
                drawList(hostId, type);
                announce();
            });
        });
        return row;
    }

    function drawAll() {
        var d = parseIso(state.date);
        var wd = (d.getDay() + 6) % 7;
        $('dgDate').textContent = GIORNI[wd] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()];
        $('dgOpenFull').href = 'diario.php?data=' + state.date;
        drawFaces();
        drawList('dgGood', 'good');
        drawList('dgLearned', 'learned');
        var ta = $('dgText');
        if (document.activeElement !== ta) ta.value = state.data.note || '';
    }

    /* ---------- punteggiatura automatica della dettatura ----------
       Il riconoscimento del browser consegna le frasi già con la maiuscola
       dopo una pausa, ma senza chiudere quella precedente. Qui indoviniamo
       il segno da mettere guardando come finisce e come comincia la frase:
       niente intelligenza artificiale, solo regole di italiano.

       In dubbio si mette il punto: è il caso più frequente e il meno
       fastidioso da correggere. */
    var CONTINUA = [
        /* congiunzioni e preposizioni: la frase non è finita, ci vuole la virgola */
        'e','ed','ma','però','perché','perche','quindi','allora','mentre','se','che',
        'oppure','anche','inoltre','cioè','cioe','ovvero','dunque','infatti','comunque',
        'con','per','di','da','in','su','tra','fra','a','al','allo','alla','ai','agli','alle',
        'del','dello','della','dei','degli','delle','nel','nello','nella','nei','negli','nelle',
        'sul','sullo','sulla','sui','sugli','sulle','come','quando','dove','mentre','finché','finche'
    ];
    var DOMANDA = [
        'chi','cosa','che','come','quando','dove','perché','perche','quale','quali',
        'quanto','quanta','quanti','quante','sono','è','e\u0300','ho','hai','ha'
    ];

    function nisamaPunctuate(phrase) {
        var s = String(phrase || '').trim();
        if (!s) return s;
        /* già chiusa da chi parlava ("punto", "virgola"…): non tocchiamo */
        if (/[.,;:!?…]$/.test(s)) return s;

        var words = s.toLowerCase().replace(/[^a-zàèéìòùü'\s]/g, ' ').split(/\s+/).filter(Boolean);
        if (!words.length) return s;
        var last = words[words.length - 1];
        var first = words[0];

        /* comincia con una parola da domanda e non è troppo lunga: punto interrogativo */
        if (DOMANDA.indexOf(first) !== -1 && words.length <= 12) return s + '?';

        /* finisce con una parola che regge un seguito: la frase continua */
        if (CONTINUA.indexOf(last) !== -1) return s + ',';

        /* frase molto corta: probabilmente un inciso, meglio la virgola */
        if (words.length <= 2) return s + ',';

        return s + '.';
    }
    window.nisamaPunctuate = nisamaPunctuate;

    /* ---------- dettatura ----------
       Usa il riconoscimento vocale del browser (Safari e Chrome): la voce
       diventa testo mentre si parla e finisce in fondo a quello che c'è già.
       Dove non c'è, il pulsante non compare nemmeno. */
    (function dictation() {
        var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
        var btn = $('dgMic');
        if (!Rec || !btn) return;
        btn.hidden = false;

        var rec = null, running = false, baseText = '';

        function stop() {
            running = false;
            btn.classList.remove('dg-rec');
            $('dgMicLab').textContent = 'Detta';
            if (rec) { try { rec.stop(); } catch (e) {} }
        }

        btn.addEventListener('click', function () {
            if (running) { stop(); return; }
            rec = new Rec();
            rec.lang = 'it-IT';
            rec.continuous = true;
            rec.interimResults = true;

            var ta = $('dgText');
            baseText = ta.value;

            rec.onresult = function (e) {
                var finalTxt = '', interim = '';
                for (var i = e.resultIndex; i < e.results.length; i++) {
                    var chunk = e.results[i][0].transcript;
                    if (e.results[i].isFinal) finalTxt += chunk;
                    else interim += chunk;
                }
                if (finalTxt) {
                    /* la frase appena conclusa viene chiusa con il segno giusto */
                    var chiusa = nisamaPunctuate(finalTxt.trim());
                    baseText = (baseText ? baseText.replace(/\s+$/, '') + ' ' : '') + chiusa;
                }
                ta.value = baseText + (interim ? (baseText ? ' ' : '') + interim : '');
                state.data.note = ta.value;
            };
            rec.onerror = function (ev) {
                stop();
                if (ev && ev.error === 'not-allowed') {
                    $('dgMicLab').textContent = 'Permesso negato';
                    setTimeout(function () { $('dgMicLab').textContent = 'Detta'; }, 2200);
                }
            };
            rec.onend = function () {
                if (running) { try { rec.start(); } catch (e) { stop(); } return; }  // pause naturali
                var v = $('dgText').value;
                api('save_text', { date: state.date, note: v }).then(function () { flashSaved(); announce(); });
            };

            try {
                rec.start();
                running = true;
                btn.classList.add('dg-rec');
                $('dgMicLab').textContent = 'Sto ascoltando';
            } catch (e) { stop(); }
        });

        /* chiudendo il popup la dettatura si ferma da sé */
        document.getElementById('dgClose').addEventListener('click', stop);
    })();

    $('dgText').addEventListener('input', function () {
        var v = this.value;
        state.data.note = v;
        later('text', 900, function () {
            api('save_text', { date: state.date, note: v }).then(function () { flashSaved(); announce(); });
        });
    });
    $('dgText').addEventListener('blur', function () {
        var v = this.value;
        api('save_text', { date: state.date, note: v }).then(function () { flashSaved(); announce(); });
    });

    function close() {
        ov.classList.remove('dg-open');
        ov.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
    }
    $('dgClose').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });

    window.nisamaOpenDiary = function (date, skin) {
        state.date = date || new Date().toISOString().slice(0, 10);
        if (skin) ov.setAttribute('data-skin', skin);
        else ov.setAttribute('data-skin', document.body.classList.contains('pl-body') ? 'planner' : 'habits');
        state.data = { mood: null, energy: null, note: '', good: [], learned: [] };
        drawAll();
        ov.classList.add('dg-open');
        ov.setAttribute('aria-hidden', 'false');
        api('day', null, { date: state.date }).then(function (r) {
            if (!r.ok) return;
            state.data = r.day;
            drawAll();
        });
    };
    window.plOpenDiary = function (date) { window.nisamaOpenDiary(date, 'planner'); };
    window.nisamaCloseDiary = close;

    /* ---------- foglio in sola lettura: quello che è stato scritto ---------- */
    var peekOv = document.getElementById('dgPeekOv');
    function closePeek() {
        peekOv.classList.remove('dg-open');
        peekOv.setAttribute('aria-hidden', 'true');
    }
    peekOv.addEventListener('click', function (e) { if (e.target === peekOv) closePeek(); });

    window.nisamaDiaryPeek = function (date) {
        var d = parseIso(date);
        var wd = (d.getDay() + 6) % 7;
        $('dgPeekDate').textContent = GIORNI[wd] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()];
        $('dgPeekOpen').href = 'diario.php?data=' + date;
        $('dgPeekBody').innerHTML = '<p style="opacity:.5">…</p>';
        $('dgPeekFaces').innerHTML = '';
        peekOv.classList.add('dg-open');
        peekOv.setAttribute('aria-hidden', 'false');

        api('day', null, { date: date }).then(function (r) {
            if (!r.ok) return;
            var day = r.day;
            var html = '';
            if (day.good && day.good.length) {
                html += '<span class="dg-peek-k">Cose belle</span><ul>';
                day.good.forEach(function (g) { html += '<li>' + escapeHtml(g.content) + '</li>'; });
                html += '</ul>';
            }
            if (day.learned && day.learned.length) {
                html += '<span class="dg-peek-k">Imparato</span><ul>';
                day.learned.forEach(function (g) { html += '<li>' + escapeHtml(g.content) + '</li>'; });
                html += '</ul>';
            }
            if (day.note && day.note.trim()) {
                html += '<span class="dg-peek-k">Com\'è andata</span>';
                day.note.split(/\n+/).forEach(function (line) {
                    if (line.trim()) html += '<p>' + escapeHtml(line) + '</p>';
                });
            }
            if (!html) html = '<p style="opacity:.55">Questa pagina è ancora bianca.</p>';
            $('dgPeekBody').innerHTML = html;

            var faces = $('dgPeekFaces');
            faces.innerHTML = '';
            [['mood', day.mood], ['energy', day.energy]].forEach(function (pair) {
                if (!pair[1]) return;
                var w = document.createElement('span');
                w.innerHTML = nisamaFace(pair[0], pair[1], 30);
                faces.appendChild(w);
            });
        });
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
</script>
<?php endif; ?>