<?php
/* =========================================================================
   NISAMA · PALLINO NOTIFICHE (avatar in alto a destra)
   Uso:
     <?php include 'notifiche_widget.php'; ?>              -> flottante, in alto a destra
     <?php $fr_widget_inline = true; include '...'; ?>     -> nel punto esatto in cui lo includi
   Si porta dietro tutto: stile, pannello, chiamate al server. Zero dipendenze.
   ========================================================================= */

require_once __DIR__ . '/friends_core.php';

if (defined('FR_WIDGET_RENDERED')) { return; }   // mai due volte nella stessa pagina
define('FR_WIDGET_RENDERED', 1);

$fw_u = fr_utente_corrente();
if (!$fw_u) { return; }

$fw_c      = fr_conn();
$fw_uid    = (int)$fw_u['id'];
$fw_nome   = (string)$fw_u['username'];
$fw_n      = fr_conta_notifiche($fw_c, $fw_uid);
$fw_items  = fr_notifiche($fw_c, $fw_uid);
$fw_inline = !empty($fr_widget_inline);
?>
<style>
/* ---------- token isolati: il widget funziona su qualsiasi pagina ---------- */
.fnw-scope{
    --nis-accent: #5B8DEF;
    --fnw-glass:rgba(252,252,254,.86); --fnw-solid:#FFFFFF; --fnw-bg2:#F2F2F7;
    --fnw-text:#1C1C1E; --fnw-t2:#8A8A8E; --fnw-t3:#AEAEB2;
    --fnw-line:rgba(60,60,67,.13);
    --fnw-blue:#007AFF; --fnw-green:#34C759; --fnw-red:#FF3B30;
    --fnw-shadow:0 2px 6px rgba(0,0,0,.07), 0 24px 60px -14px rgba(0,0,0,.3);
    --fnw-spring:cubic-bezier(.32,.72,0,1);
    font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display",system-ui,"Segoe UI",Roboto,sans-serif;
    -webkit-tap-highlight-color:transparent;
}
@media (prefers-color-scheme: dark){
    .fnw-scope{
        --fnw-glass:rgba(30,30,32,.88); --fnw-solid:#1C1C1E; --fnw-bg2:#2C2C2E;
        --fnw-text:#F5F5F7; --fnw-t2:#98989F; --fnw-t3:#636366;
        --fnw-line:rgba(84,84,88,.45);
        --fnw-blue:#0A84FF; --fnw-green:#30D158; --fnw-red:#FF453A;
        --fnw-shadow:0 2px 6px rgba(0,0,0,.5), 0 24px 60px -14px rgba(0,0,0,.7);
    }
}
.fnw-scope *{ box-sizing:border-box; }

/* ---------- il pallino ---------- */
.nisama-fab{ position:fixed; top:18px; right:18px; z-index:999990; }
button.nisama-fab--login{
  appearance:none; border:none; cursor:pointer; background:var(--nis-ink); color:#fff;
  font-family:inherit; font-weight:700; font-size:14.5px; padding:11px 22px; border-radius:999px;
  box-shadow:0 6px 20px rgba(0,0,0,.18); transition:transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s ease;
}
button.nisama-fab--login:hover{ transform:translateY(-2px) scale(1.03); box-shadow:0 10px 26px rgba(0,0,0,.24); }
button.nisama-fab--login:active{ transform:scale(.96); }

.nisama-fab--user{ display:flex; flex-direction:column; align-items:flex-end; }
.nisama-fab-avatar{
  appearance:none; border:2px solid #fff; cursor:pointer; width:42px; height:42px; border-radius:50%;
  background:linear-gradient(135deg, var(--nis-accent), #A78BFA); color:#fff; font-weight:800; font-size:16px;
  box-shadow:0 6px 18px rgba(0,0,0,.22); transition:transform .2s cubic-bezier(.34,1.56,.64,1);
}
.nisama-fab-avatar:hover{ transform:scale(1.06); }
.fnw-avatar:active { transform: scale(.96); }

.fnw-badge{
    position:absolute; top:-4px; right:-4px;
    min-width:21px; height:21px; padding:0 5px; border-radius:999px;
    background:var(--fnw-red); color:#fff;
    font-size:12px; font-weight:800; line-height:21px; text-align:center;
    font-variant-numeric:tabular-nums;
    box-shadow:0 3px 10px rgba(255,59,48,.55);
    animation:fnwPop .45s var(--fnw-spring) both, fnwBeat 2.6s ease-in-out 1s infinite;
}
.fnw-badge[hidden]{ display:none; }
@keyframes fnwPop{ from{ transform:scale(0); } to{ transform:scale(1); } }
@keyframes fnwBeat{ 0%,88%,100%{ transform:scale(1); } 92%{ transform:scale(1.16); } 96%{ transform:scale(.97); } }

/* ---------- scrim + pannello ---------- */
.fnw-scrim{
    position:fixed; inset:0; z-index:9550; background:rgba(0,0,0,.28);
    -webkit-backdrop-filter:blur(2px); backdrop-filter:blur(2px);
    opacity:0; pointer-events:none; transition:opacity .28s ease;
}
.fnw-scrim.fnw-on{ opacity:1; pointer-events:auto; }

.fnw-panel{
    position:fixed; z-index:9600; top:70px; right:14px;
    width:min(364px, calc(100vw - 20px));
    max-height:min(74vh, 640px); display:flex; flex-direction:column;
    background:var(--fnw-glass);
    -webkit-backdrop-filter:blur(30px) saturate(1.8); backdrop-filter:blur(30px) saturate(1.8);
    border:.5px solid var(--fnw-line); border-radius:20px; box-shadow:var(--fnw-shadow);
    transform-origin:top right; transform:scale(.86) translateY(-10px); opacity:0; pointer-events:none;
    transition:transform .38s var(--fnw-spring), opacity .22s ease;
    overflow:hidden;
}
.fnw-panel.fnw-on{ transform:none; opacity:1; pointer-events:auto; }

.fnw-head{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:15px 16px 11px; border-bottom:.5px solid var(--fnw-line); flex-shrink:0;
}
.fnw-title{ font-size:17px; font-weight:800; color:var(--fnw-text); letter-spacing:-.01em; }
.fnw-count{
    font-size:11px; font-weight:800; color:#fff; background:var(--fnw-red);
    padding:2px 8px; border-radius:999px; font-variant-numeric:tabular-nums;
}
.fnw-count[hidden]{ display:none; }

.fnw-list{ overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; padding:6px; min-height:0; }
.fnw-list::-webkit-scrollbar{ width:4px; }
.fnw-list::-webkit-scrollbar-thumb{ background:var(--fnw-line); border-radius:9px; }

.fnw-item{
    display:flex; gap:11px; padding:10px; border-radius:14px; align-items:flex-start;
    animation:fnwIn .42s var(--fnw-spring) both; position:relative;
    transition:opacity .3s ease, transform .3s var(--fnw-spring), max-height .35s ease, padding .3s ease;
    max-height:180px;
}
@keyframes fnwIn{ from{ opacity:0; transform:translateY(-7px); } to{ opacity:1; transform:none; } }
.fnw-item.fnw-out{ opacity:0; transform:translateX(30px); max-height:0; padding-top:0; padding-bottom:0; overflow:hidden; }
.fnw-item.fnw-new{ background:rgba(0,122,255,.07); }
@media (prefers-color-scheme: dark){ .fnw-item.fnw-new{ background:rgba(10,132,255,.14); } }

.fnw-ava{
    width:40px; height:40px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:16px;
    box-shadow:inset 0 0 0 1.5px rgba(255,255,255,.25);
}
.fnw-body{ flex:1; min-width:0; }
.fnw-txt{ font-size:13.5px; line-height:1.4; color:var(--fnw-text); font-weight:500; }
.fnw-txt b{ font-weight:800; }
.fnw-when{ font-size:11px; color:var(--fnw-t3); font-weight:600; margin-top:2px; }
.fnw-acts{ display:flex; gap:7px; margin-top:9px; }

.fnw-b{
    border:none; cursor:pointer; font-family:inherit; font-weight:700; font-size:12.5px;
    padding:7px 14px; border-radius:999px; transition:transform .18s var(--fnw-spring), opacity .2s;
}
.fnw-b:active{ transform:scale(.92); }
.fnw-b[disabled]{ opacity:.5; pointer-events:none; }
.fnw-b-ok{ background:var(--fnw-blue); color:#fff; box-shadow:0 4px 12px -3px rgba(0,122,255,.6); }
.fnw-b-no{ background:var(--fnw-bg2); color:var(--fnw-t2); }
.fnw-b-go{ background:var(--fnw-bg2); color:var(--fnw-blue); }

.fnw-empty{ text-align:center; padding:34px 18px 30px; }
.fnw-empty svg{ width:34px; height:34px; color:var(--fnw-t3); margin-bottom:10px; }
.fnw-empty p{ margin:0; font-size:13.5px; color:var(--fnw-t2); font-weight:600; line-height:1.5; }

.fnw-foot{ border-top:.5px solid var(--fnw-line); padding:6px; flex-shrink:0; }
.fnw-row{
    display:flex; align-items:center; gap:11px; padding:11px 12px; border-radius:13px;
    text-decoration:none; color:var(--fnw-text); font-size:14.5px; font-weight:600;
    transition:background .2s, transform .18s var(--fnw-spring);
}
.fnw-row:active{ transform:scale(.975); background:var(--fnw-bg2); }
.fnw-row:hover{ background:var(--fnw-bg2); }
.fnw-row svg{ width:19px; height:19px; flex-shrink:0; }
.fnw-row.fnw-danger{ color:var(--fnw-red); }
.fnw-row .fnw-pill{
    margin-left:auto; font-size:11px; font-weight:800; color:#fff; background:var(--fnw-red);
    padding:2px 7px; border-radius:999px;
}

@media (prefers-reduced-motion: reduce){
    .fnw-scope *{ animation:none !important; transition-duration:.01ms !important; }
}
</style>

<div class="nisama-fab nisama-fab--user fnw-scope fnw-anchor">
    <button type="button" class="nisama-fab-avatar" id="fnwBtn" 
            aria-label="Notifiche e profilo" aria-haspopup="true" aria-expanded="false">
        <?php echo htmlspecialchars(fr_iniziale($fw_nome), ENT_QUOTES); ?>
        <span class="fnw-badge" id="fnwBadge" <?php echo $fw_n > 0 ? '' : 'hidden'; ?>><?php echo $fw_n > 99 ? '99+' : $fw_n; ?></span>
    </button>
</div>

<div class="fnw-scope fnw-scrim" id="fnwScrim"></div>

<div class="fnw-scope fnw-panel" id="fnwPanel" role="dialog" aria-label="Notifiche">
    <div class="fnw-head">
        <span class="fnw-title">Notifiche</span>
        <span class="fnw-count" id="fnwCount" <?php echo $fw_n > 0 ? '' : 'hidden'; ?>><?php echo (int)$fw_n; ?> da vedere</span>
    </div>

    <div class="fnw-list" id="fnwList">
        <?php if (empty($fw_items)): ?>
            <div class="fnw-empty" id="fnwEmpty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                <p>Nessuna notifica.<br>Aggiungi un amico e iniziate a confrontarvi.</p>
            </div>
        <?php else: foreach ($fw_items as $it):
            $n = htmlspecialchars($it['username'], ENT_QUOTES);
            $u = (int)$it['uid'];
        ?>
            <div class="fnw-item <?php echo $it['nuova'] ? 'fnw-new' : ''; ?>" data-uid="<?php echo $u; ?>" data-tipo="<?php echo $it['tipo']; ?>">
                <span class="fnw-ava" style="background:<?php echo fr_gradiente($u); ?>"><?php echo htmlspecialchars(fr_iniziale($it['username']), ENT_QUOTES); ?></span>
                <div class="fnw-body">
                    <?php if ($it['tipo'] === 'ricevuta'): ?>
                        <div class="fnw-txt"><b><?php echo $n; ?></b> ti ha inviato una richiesta di amicizia.</div>
                        <div class="fnw-when"><?php echo htmlspecialchars(fr_quando($it['quando']), ENT_QUOTES); ?></div>
                        <div class="fnw-acts">
                            <button type="button" class="fnw-b fnw-b-ok" data-az="accetta">Accetta</button>
                            <button type="button" class="fnw-b fnw-b-no" data-az="rifiuta">Rifiuta</button>
                        </div>
                    <?php elseif ($it['tipo'] === 'accettata'): ?>
                        <div class="fnw-txt"><b><?php echo $n; ?></b> ha accettato la tua richiesta. Ora potete confrontarvi.</div>
                        <div class="fnw-when"><?php echo htmlspecialchars(fr_quando($it['quando']), ENT_QUOTES); ?></div>
                        <div class="fnw-acts">
                            <a class="fnw-b fnw-b-go" href="friends.php?u=<?php echo $u; ?>">Vedi il confronto</a>
                        </div>
                    <?php else: ?>
                        <div class="fnw-txt">Richiesta inviata a <b><?php echo $n; ?></b>. In attesa di risposta.</div>
                        <div class="fnw-when"><?php echo htmlspecialchars(fr_quando($it['quando']), ENT_QUOTES); ?></div>
                        <div class="fnw-acts">
                            <button type="button" class="fnw-b fnw-b-no" data-az="annulla">Annulla</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="fnw-foot">
        <a class="fnw-row" href="friends.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Amici e classifica</span>
            <span class="fnw-pill" id="fnwPill" <?php echo $fw_n > 0 ? '' : 'hidden'; ?>><?php echo (int)$fw_n; ?></span>
        </a>
        <a class="fnw-row" href="stats_fc.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            <span>Le tue statistiche</span>
        </a>
        <a class="fnw-row fnw-danger" href="logout.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            <span>Logout</span>
        </a>
    </div>
</div>

<script>
(function () {
    'use strict';
    var btn   = document.getElementById('fnwBtn');
    var panel = document.getElementById('fnwPanel');
    var scrim = document.getElementById('fnwScrim');
    var list  = document.getElementById('fnwList');
    var badge = document.getElementById('fnwBadge');
    var count = document.getElementById('fnwCount');
    var pill  = document.getElementById('fnwPill');
    if (!btn || !panel) { return; }

    var LEGACY = <?php echo json_encode(FR_LEGACY_AVATAR_SELECTOR); ?>;
    if (LEGACY) { try { document.querySelectorAll(LEGACY).forEach(function (e) { e.style.display = 'none'; }); } catch (e) {} }

    var aperto = false;
    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

    /* il pannello nasce esattamente sotto al pallino, ovunque esso sia */
    function posiziona() {
        var r = btn.getBoundingClientRect();
        var w = panel.offsetWidth || 360;
        var right = Math.max(10, Math.min(window.innerWidth - r.right, window.innerWidth - w - 10));
        panel.style.top = Math.round(r.bottom + 10) + 'px';
        panel.style.right = Math.round(right) + 'px';
    }

    function apri() {
        aperto = true;
        posiziona();
        panel.classList.add('fnw-on');
        scrim.classList.add('fnw-on');
        btn.setAttribute('aria-expanded', 'true');
        buzz(10);
        segnaLette();
    }
    function chiudi() {
        aperto = false;
        panel.classList.remove('fnw-on');
        scrim.classList.remove('fnw-on');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) { e.stopPropagation(); aperto ? chiudi() : apri(); });
    scrim.addEventListener('click', chiudi);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && aperto) chiudi(); });
    window.addEventListener('resize', function () { if (aperto) posiziona(); });

    /* ---------- badge ---------- */
    function setBadge(n) {
        n = parseInt(n, 10) || 0;
        [badge, pill].forEach(function (el) {
            if (!el) return;
            el.textContent = n > 99 ? '99+' : String(n);
            el.hidden = n === 0;
        });
        if (count) { count.textContent = n + (n === 1 ? ' da vedere' : ' da vedere'); count.hidden = n === 0; }
        var sb = document.getElementById('sbFriendsBadge');
        if (sb) { sb.textContent = n > 9 ? '9+' : String(n); sb.hidden = n === 0; }
        var fab = document.getElementById('sbFab');
        if (fab) { fab.classList.toggle('sb-has-dot', n > 0); }
        document.dispatchEvent(new CustomEvent('fnw:count', { detail: { n: n } }));
    }
    setBadge(<?php echo (int)$fw_n; ?>);

    function api(azione, dati) {
        var body = new URLSearchParams(Object.assign({ azione: azione }, dati || {}));
        return fetch('friends_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Nisama': '1' },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }
    window.FNW = { api: api, setBadge: setBadge, chiudi: chiudi };

    function segnaLette() {
        if (!list || !list.querySelector('.fnw-item.fnw-new[data-tipo="accettata"]')) { return; }
        api('leggi').then(function (r) {
            if (r && r.ok) {
                list.querySelectorAll('.fnw-item[data-tipo="accettata"]').forEach(function (e) { e.classList.remove('fnw-new'); });
                setBadge(r.n);
            }
        }).catch(function () {});
    }

    function svuotaSeVuoto() {
        if (list.querySelector('.fnw-item')) { return; }
        list.innerHTML = '<div class="fnw-empty">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>' +
            '<p>Nessuna notifica.<br>Aggiungi un amico e iniziate a confrontarvi.</p></div>';
    }

    /* ---------- azioni sulle richieste ---------- */
    if (list) {
        list.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-az]');
            if (!b) { return; }
            var riga = b.closest('.fnw-item');
            var uid  = riga.getAttribute('data-uid');
            var az   = b.getAttribute('data-az');
            riga.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
            buzz(az === 'accetta' ? 22 : 8);

            api(az, { uid: uid }).then(function (r) {
                if (!r || !r.ok) { throw new Error((r && r.errore) || 'errore'); }
                if (az === 'accetta' && window.frCoriandoli) { window.frCoriandoli(); }
                riga.classList.add('fnw-out');
                setTimeout(function () { riga.remove(); svuotaSeVuoto(); }, 340);
                setBadge(r.n);
                if (window.frToast) { window.frToast(r.messaggio); }
                if (window.frRicarica) { window.frRicarica(); }
            }).catch(function () {
                riga.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
                if (window.frToast) { window.frToast('Non ha funzionato. Riprova.'); }
            });
        });
    }

    /* ---------- aggiornamento silenzioso del contatore ---------- */
    setInterval(function () {
        if (document.hidden || aperto) { return; }
        api('notifiche').then(function (r) { if (r && r.ok) { setBadge(r.n); } }).catch(function () {});
    }, 45000);
})();
</script>
