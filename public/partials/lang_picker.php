<?php
/* =====================================================================
   STANDOUT — SELETTORE DELLA LINGUA
   Due forme, stessa logica:
     standout_lang_picker('pill')  -> pulsantino con bandiera, per la pagina
                                      d'ingresso (accanto ad "Accedi")
     standout_lang_picker('row')   -> riga "Lingua" nel pannello del profilo
   La scelta va a api/lingua.php (salvata sul profilo se si e' connessi,
   in sessione e cookie se no) e la pagina si ricarica nella lingua nuova.
   ===================================================================== */
if (defined('STANDOUT_LANG_PICKER')) return;
define('STANDOUT_LANG_PICKER', true);

function standout_lang_picker(string $forma = 'pill'): void
{
    static $risorse = false;
    $attuale = standout_lingua();
    $info = STANDOUT_LINGUE_INFO;
    $flag = function (string $codice) use ($info): string {
        $b = $info[$codice]['bandiera'] ?? $codice;
        return PWA_BASE . '_assets/svg/flags/' . $b . '.svg';
    };
    $token = function_exists('sec_csrf_token') ? sec_csrf_token()
           : (function_exists('standout_csrf_token') ? standout_csrf_token() : '');

    if (!$risorse) {
        $risorse = true;

        ?>
<style>
.slp{ position:relative; font-family:inherit; -webkit-tap-highlight-color:transparent; }
.slp-flag{ width:20px; height:15px; border-radius:3px; object-fit:cover; flex-shrink:0;
    box-shadow:0 0 0 .5px rgba(0,0,0,.18); }
.slp-menu{
    position:absolute; z-index:1000000; min-width:190px; padding:6px; margin:0; list-style:none;
    border-radius:16px; background:var(--slp-bg, rgba(28,28,32,.96));
    -webkit-backdrop-filter:blur(20px) saturate(1.6); backdrop-filter:blur(20px) saturate(1.6);
    box-shadow:0 18px 50px -12px rgba(0,0,0,.45), 0 0 0 .5px rgba(255,255,255,.08);
    opacity:0; transform:translateY(-6px) scale(.97); pointer-events:none;
    transition:opacity .18s ease, transform .22s cubic-bezier(.32,.72,0,1);
}
.slp.slp-on .slp-menu{ opacity:1; transform:none; pointer-events:auto; }
.slp-opt{
    display:flex; align-items:center; gap:11px; width:100%; padding:10px 12px; border:none; border-radius:11px;
    background:none; cursor:pointer; text-align:left; font:inherit; font-size:14.5px; font-weight:600;
    color:var(--slp-text, #F5F5F7);
}
.slp-opt:hover, .slp-opt:focus-visible{ background:var(--slp-hover, rgba(255,255,255,.08)); outline:none; }
.slp-opt .slp-check{ margin-left:auto; width:17px; height:17px; color:var(--slp-accent, #0A84FF); visibility:hidden; }
.slp-opt[aria-checked="true"] .slp-check{ visibility:visible; }
.slp.slp-busy{ opacity:.6; pointer-events:none; }

/* --- forma "pill": pagina d'ingresso, sfondo scuro --- */
.slp--pill .slp-btn{
    appearance:none; display:inline-flex; align-items:center; gap:8px; cursor:pointer;
    padding:10px 14px 10px 12px; border-radius:999px; font:inherit; font-size:14px; font-weight:700;
    color:#fff; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16);
    -webkit-backdrop-filter:blur(14px); backdrop-filter:blur(14px);
    transition:background .2s ease, transform .2s cubic-bezier(.34,1.56,.64,1);
}
.slp--pill .slp-btn:hover{ background:rgba(255,255,255,.14); }
.slp--pill .slp-btn:active{ transform:scale(.96); }
.slp--pill .slp-chev{ width:14px; height:14px; opacity:.7; transition:transform .2s ease; }
.slp--pill.slp-on .slp-chev{ transform:rotate(180deg); }
.slp--pill .slp-menu{ top:calc(100% + 8px); right:0; }

/* --- forma "row": dentro il pannello del profilo --- */
.slp--row .slp-btn{
    appearance:none; display:flex; align-items:center; gap:11px; width:100%; cursor:pointer;
    padding:11px 12px; border-radius:13px; border:none; background:none; text-align:left;
    font:inherit; font-size:14.5px; font-weight:600; color:var(--fnw-text, inherit);
    transition:background .2s;
}
.slp--row .slp-btn:hover{ background:var(--fnw-bg2, rgba(0,0,0,.05)); }
.slp--row .slp-btn > svg{ width:19px; height:19px; flex-shrink:0; }
.slp--row .slp-val{ margin-left:auto; display:inline-flex; align-items:center; gap:7px; font-weight:600; color:var(--fnw-t2, #8A8A8E); }
.slp--row .slp-chev{ width:15px; height:15px; color:var(--fnw-t3, #AEAEB2); transition:transform .25s ease; }
.slp--row.slp-on .slp-chev{ transform:rotate(90deg); }
/* nel pannello la scelta si apre sotto la riga, non come menu volante */
.slp--row .slp-menu{
    position:static; display:grid; grid-template-rows:0fr; padding:0 4px; min-width:0;
    background:none; box-shadow:none; -webkit-backdrop-filter:none; backdrop-filter:none;
    opacity:1; transform:none; transition:grid-template-rows .3s cubic-bezier(.32,.72,0,1);
}
.slp--row .slp-menu > div{ overflow:hidden; min-height:0; }
.slp--row.slp-on .slp-menu{ grid-template-rows:1fr; }
.slp--row .slp-opt{ color:var(--fnw-text, inherit); padding:9px 12px 9px 42px; }
.slp--row .slp-opt:hover{ background:var(--fnw-bg2, rgba(0,0,0,.05)); }
.slp--row .slp-opt .slp-check{ color:var(--fnw-blue, #007AFF); }
.slp-swatch{ width:20px; height:15px; border-radius:4px; flex-shrink:0; position:relative;
    box-shadow:0 0 0 .5px rgba(0,0,0,.22); }
.slp-swatch::after{ content:""; position:absolute; right:3px; bottom:3px; width:5px; height:5px; border-radius:50%; }
.slp-swatch--standard{ background:#F2F2F7; }
.slp-swatch--standard::after{ background:#007AFF; }
.slp-swatch--paper{ background:#F5F1EA; }
.slp-swatch--paper::after{ background:#A9C3AE; }
.slp-nota{ display:block; padding:2px 12px 6px 42px; font-size:12px; font-weight:500; color:var(--fnw-t2, #8A8A8E); }
@media (prefers-reduced-motion: reduce){ .slp *, .slp-menu{ transition:none !important; } }
</style>
<script>
(function () {
    'use strict';
    function chiudiTutti(tranne) {
        document.querySelectorAll('.slp.slp-on').forEach(function (p) {
            if (p !== tranne) { p.classList.remove('slp-on'); var b = p.querySelector('.slp-btn'); if (b) b.setAttribute('aria-expanded', 'false'); }
        });
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.slp-btn');
        if (btn) {
            e.preventDefault(); e.stopPropagation();
            var p = btn.closest('.slp');
            var on = !p.classList.contains('slp-on');
            chiudiTutti(p);
            p.classList.toggle('slp-on', on);
            btn.setAttribute('aria-expanded', on ? 'true' : 'false');
            return;
        }
        var opt = e.target.closest && e.target.closest('.slp-opt');
        if (opt) {
            e.preventDefault(); e.stopPropagation();
            var picker = opt.closest('.slp');
            /* Lo stesso selettore serve a piu' scelte: data-campo dice cosa si
               manda all'API ("lingua", "stile"), data-valore il valore. */
            var campo = picker.getAttribute('data-campo') || 'lingua';
            var valore = opt.getAttribute('data-valore');
            if (opt.getAttribute('aria-checked') === 'true') { chiudiTutti(null); return; }
            picker.classList.add('slp-busy');
            var corpo = {}; corpo[campo] = valore;
            fetch(picker.getAttribute('data-api'), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': picker.getAttribute('data-token') || '' },
                body: JSON.stringify(corpo)
            }).then(function (r) { return r.json(); }).then(function (r) {
                if (!r || !r.ok) throw new Error('ko');
                /* se l'indirizzo aveva ?lang=, va tolto: vincerebbe sulla scelta appena fatta */
                var u = new URL(window.location.href);
                u.searchParams.delete('lang');
                window.location.replace(u.toString());
            }).catch(function () {
                if (campo === 'lingua') {
                    /* rete o token scaduto: ?lang= imposta comunque sessione e cookie */
                    var u = new URL(window.location.href);
                    u.searchParams.set('lang', valore);
                    window.location.href = u.toString();
                    return;
                }
                picker.classList.remove('slp-busy');
                try { picker.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }], { duration: 260 }); } catch (e) {}
            });
            return;
        }
        if (!(e.target.closest && e.target.closest('.slp'))) chiudiTutti(null);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') chiudiTutti(null); });
})();
</script>
        <?php
    }

    /* chiamata solo per avere stili e JS (lo fa standout_stile_picker) */
    if ($forma === '__solo_risorse__') return;

    $chev = '<svg class="slp-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
          . ($forma === 'row' ? '<path d="m9 6 6 6-6 6"/>' : '<path d="m6 9 6 6 6-6"/>') . '</svg>';
    $check = '<svg class="slp-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5 10 17l9-10"/></svg>';
    $nomeAttuale = $info[$attuale]['nome'] ?? strtoupper($attuale);
    ?>
<div class="slp slp--<?php echo $forma === 'row' ? 'row' : 'pill'; ?>" data-campo="lingua"
     data-api="<?php echo htmlspecialchars(PWA_BASE . 'api/lingua.php', ENT_QUOTES); ?>"
     data-token="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
    <button type="button" class="slp-btn" aria-haspopup="true" aria-expanded="false"
            aria-label="<?php te('lingua.scegli'); ?>" title="<?php te('lingua.scegli'); ?>">
        <?php if ($forma === 'row'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
            <span><?php te('lingua.titolo'); ?></span>
            <span class="slp-val"><img class="slp-flag" src="<?php echo htmlspecialchars($flag($attuale), ENT_QUOTES); ?>" alt=""><?php echo htmlspecialchars($nomeAttuale, ENT_QUOTES); ?></span>
            <?php echo $chev; ?>
        <?php else: ?>
            <img class="slp-flag" src="<?php echo htmlspecialchars($flag($attuale), ENT_QUOTES); ?>" alt="">
            <span><?php echo htmlspecialchars(strtoupper($attuale), ENT_QUOTES); ?></span>
            <?php echo $chev; ?>
        <?php endif; ?>
    </button>
    <div class="slp-menu" role="menu"><div>
        <?php foreach (STANDOUT_LINGUE as $codice): $n = $info[$codice]['nome'] ?? strtoupper($codice); ?>
            <button type="button" class="slp-opt" role="menuitemradio" lang="<?php echo htmlspecialchars($codice, ENT_QUOTES); ?>"
                    data-valore="<?php echo htmlspecialchars($codice, ENT_QUOTES); ?>"
                    aria-checked="<?php echo $codice === $attuale ? 'true' : 'false'; ?>">
                <img class="slp-flag" src="<?php echo htmlspecialchars($flag($codice), ENT_QUOTES); ?>" alt="">
                <span><?php echo htmlspecialchars($n, ENT_QUOTES); ?></span>
                <?php echo $check; ?>
            </button>
        <?php endforeach; ?>
    </div></div>
</div>
    <?php
}

/* La riga "Stile" del pannello del profilo: stessa meccanica della lingua
   (stesso JS, stesse classi), un'altra API. Vedi lib/stile.php. */
function standout_stile_picker(): void
{
    require_once __DIR__ . '/../../lib/stile.php';
    standout_lang_picker('__solo_risorse__');   // stili e JS condivisi, senza markup
    $attuale = standout_stile();
    $token = function_exists('sec_csrf_token') ? sec_csrf_token()
           : (function_exists('standout_csrf_token') ? standout_csrf_token() : '');
    $nomi = ['standard' => t('stile.standard'), 'paper' => t('stile.paper')];
    $check = '<svg class="slp-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5 10 17l9-10"/></svg>';
    ?>
<div class="slp slp--row" data-campo="stile"
     data-api="<?php echo htmlspecialchars(PWA_BASE . 'api/stile.php', ENT_QUOTES); ?>"
     data-token="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
    <button type="button" class="slp-btn" aria-haspopup="true" aria-expanded="false" title="<?php te('stile.titolo'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.8 1.8-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.8-.5-1.2 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3Z"/><circle cx="7.5" cy="11" r="1.2" fill="currentColor"/><circle cx="10.5" cy="7" r="1.2" fill="currentColor"/><circle cx="15" cy="7.5" r="1.2" fill="currentColor"/></svg>
        <span><?php te('stile.titolo'); ?></span>
        <span class="slp-val"><span class="slp-swatch slp-swatch--<?php echo $attuale; ?>"></span><?php echo htmlspecialchars($nomi[$attuale], ENT_QUOTES); ?></span>
        <svg class="slp-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
    </button>
    <div class="slp-menu" role="menu"><div>
        <?php foreach (STANDOUT_STILI as $s): ?>
            <button type="button" class="slp-opt" role="menuitemradio" data-valore="<?php echo $s; ?>"
                    aria-checked="<?php echo $s === $attuale ? 'true' : 'false'; ?>">
                <span class="slp-swatch slp-swatch--<?php echo $s; ?>"></span>
                <span><?php echo htmlspecialchars($nomi[$s], ENT_QUOTES); ?></span>
                <?php echo $check; ?>
            </button>
        <?php endforeach; ?>
        <span class="slp-nota"><?php te('stile.nota'); ?></span>
    </div></div>
</div>
    <?php
}
