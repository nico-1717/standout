<?php
/* ============================================================================
   fc_home.php — LA PAGINA PRINCIPALE DELLE FLASHCARD

   Due strade: le flashcard di studio e le lingue. Sopra, i numeri che valgono
   per entrambe — la serie di giorni, le carte di oggi rispetto all'obiettivo,
   la settimana — perche' studiare una parola di inglese o una formula di
   analisi tiene viva la stessa abitudine.

   I numeri vengono da fc_summary.php, lo stesso file del cruscotto: due
   pagine che contano le stesse cose devono contarle nello stesso modo.
   ============================================================================ */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_summary.php';

/* L'obiettivo giornaliero si cambia da qui, toccando l'anello. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'goal') {
    sec_guard_write($current_user_id, 'fc_goal', 0, 60, 60);
    $goal = sec_int($_POST['daily_goal'] ?? 20, 1, 1000, 20);
    try {
        sec_q("INSERT INTO fc_goals (user_id, daily_goal) VALUES (?, ?) ON DUPLICATE KEY UPDATE daily_goal = ?",
              [$current_user_id, $goal, $goal], 'iii');
        sec_json(['ok' => true, 'daily_goal' => $goal]);
    } catch (Throwable $e) {
        sec_log_error('fc_home', 'fc_goals: ' . $e->getMessage());
        sec_die_json(t('fchub.err_rete'), 500, 'goal');
    }
}

$R = fc_riepilogo($current_user_id);
$corsi = $R['vocab']['corsi'];
$pctOggi = min(100, (int)round($R['oggi'] / max(1, $R['obiettivo']) * 100));
$sPct = $R['study']['carte'] > 0 ? (int)round($R['study']['imparate'] / $R['study']['carte'] * 100) : 0;
$vPct = $R['vocab']['parole'] > 0 ? (int)round($R['vocab']['imparate'] / $R['vocab']['parole'] * 100) : 0;
$weekMax = max(1, max($R['settimana']));
$giorniLbl = [t('dashboard.g_lun'), t('dashboard.g_mar'), t('dashboard.g_mer'),
              t('dashboard.g_gio'), t('dashboard.g_ven'), t('dashboard.g_sab'), t('dashboard.g_dom')];

/* Il messaggio sotto l'anello: dice a che punto sei con parole, non solo con
   un numero. */
if ($R['oggi'] >= $R['obiettivo'])      $frase = t('fchub.obiettivo_raggiunto');
elseif ($R['oggi'] > 0)                 $frase = t('fchub.ancora_n', ['n' => $R['obiettivo'] - $R['oggi']]);
elseif ($R['serie'] > 0)                $frase = t('fchub.salva_serie');
else                                    $frase = t('fchub.inizia_oggi');

$circ = 2 * M_PI * 46;   // circonferenza dell'anello (r = 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<title><?php te('fchub.home_titolo'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_hub.css?v=<?php echo @filemtime(__DIR__ . '/fc_hub.css') ?: 1; ?>">
<script src="<?php echo PWA_BASE; ?>_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>
<?php echo standout_i18n_json(['comune', 'fchub']); ?>
</head>
<body class="hub-body">

<header class="app-header">
    <div class="hdr-top">
        <div class="hdr-titles">
            <h1 class="hdr-title"><?php te('fchub.home_titolo'); ?></h1>
            <div class="hdr-sub"><?php echo sec_h($frase); ?></div>
        </div>
        <a href="fc_stats.php" class="hdr-icon" aria-label="<?php te('fchub.statistiche'); ?>" title="<?php te('fchub.statistiche'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6.5 20v-6M12 20V5M17.5 20v-9"/></svg>
        </a>
        <?php /* pallino del profilo, in fila con i pulsanti dell'intestazione */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
    </div>
</header>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<main class="page">

    <!-- OGGI: obiettivo, serie, settimana -->
    <section class="today-card">
        <button type="button" class="ring-btn" id="btnGoal" aria-label="<?php te('fchub.cambia_obiettivo'); ?>">
            <svg class="ring" viewBox="0 0 108 108" aria-hidden="true">
                <circle class="ring-bg" cx="54" cy="54" r="46"/>
                <circle class="ring-fg<?php echo $pctOggi >= 100 ? ' is-done' : ''; ?>" cx="54" cy="54" r="46"
                        stroke-dasharray="<?php echo round($circ, 2); ?>"
                        stroke-dashoffset="<?php echo round($circ * (1 - $pctOggi / 100), 2); ?>"/>
            </svg>
            <span class="ring-txt">
                <span class="ring-num"><?php echo (int)$R['oggi']; ?></span>
                <span class="ring-of"><?php te('fchub.su_n', ['n' => $R['obiettivo']]); ?></span>
            </span>
        </button>

        <div class="today-side">
            <div class="today-stat">
                <span class="ts-ico ts-flame<?php echo $R['serie'] > 0 ? ' is-on' : ''; ?>" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.6 2.2c.4 2.6-.5 4.6-2.2 6.3-1.4 1.4-2.9 2.8-2.9 5.4 0 1 .3 1.8.8 2.5-.9-.3-1.7-1-2.2-1.9C5 16 5 17 5 17.6 5 21 8 23 12 23s7-2.4 7-6.5c0-3.2-1.6-5.2-3-7 .1 1.3-.3 2.4-1 3.1.2-3.9-.9-7.6-2.4-10.4Z"/></svg>
                </span>
                <span>
                    <span class="ts-num"><?php echo (int)$R['serie']; ?></span>
                    <span class="ts-lbl"><?php te($R['serie'] === 1 ? 'fchub.giorno_di_fila' : 'fchub.giorni_di_fila'); ?></span>
                </span>
            </div>
            <div class="today-stat">
                <span class="ts-ico ts-target" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8" fill="currentColor"/></svg>
                </span>
                <span>
                    <span class="ts-num"><?php echo (int)$R['precisione']; ?>%</span>
                    <span class="ts-lbl"><?php te('fchub.precisione_30'); ?></span>
                </span>
            </div>
            <?php if ($R['record'] > $R['serie']): ?>
            <div class="today-record"><?php te('fchub.record_n', ['n' => $R['record']]); ?></div>
            <?php endif; ?>
        </div>

        <div class="week" aria-label="<?php te('fchub.settimana'); ?>">
            <?php $i = 0; foreach ($R['settimana'] as $data => $n):
                $h = $n > 0 ? max(10, (int)round($n / $weekMax * 100)) : 0;
                $wd = (int)date('N', strtotime($data)) - 1; ?>
            <div class="week-day<?php echo $data === date('Y-m-d') ? ' is-today' : ''; ?>" title="<?php echo (int)$n; ?>">
                <span class="week-bar"><span style="height:<?php echo $h; ?>%"></span></span>
                <span class="week-lbl"><?php echo sec_h($giorniLbl[$wd]); ?></span>
            </div>
            <?php $i++; endforeach; ?>
        </div>
    </section>


    <!-- LE DUE STRADE -->
    <section class="modes">

        <article class="mode-card mode-study">
            <a class="mode-main" href="fc_decks.php">
                <span class="mode-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l10-5 10 5-10 5-10-5z"/><path d="M6 11.4V17c0 1.1 2.7 3 6 3s6-1.9 6-3v-5.6"/><path d="M22 9v6.2"/></svg>
                </span>
                <span class="mode-txt">
                    <span class="mode-title"><?php te('fchub.studio'); ?></span>
                    <span class="mode-sub"><?php te('fchub.studio_sub'); ?></span>
                </span>
                <svg class="mode-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <div class="mode-stats">
                <span><b><?php echo (int)$R['study']['mazzi']; ?></b> <?php te($R['study']['mazzi'] === 1 ? 'fchub.mazzo' : 'fchub.mazzi'); ?></span>
                <span><b><?php echo (int)$R['study']['carte']; ?></b> <?php te($R['study']['carte'] === 1 ? 'fchub.carta' : 'fchub.carte'); ?></span>
                <span><b><?php echo $sPct; ?>%</b> <?php te('fchub.imparate'); ?></span>
            </div>
            <div class="mode-bar"><span style="width:<?php echo $sPct; ?>%"></span></div>
            <div class="mode-actions">
                <a class="pill-btn pill-play" href="fc_game_setup.php">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                    <span><?php te('fchub.gioca'); ?></span>
                </a>
                <a class="pill-btn" href="fc_card_edit.php?modo=insert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span><?php te('fchub.nuova_carta'); ?></span>
                </a>
                <a class="pill-btn" href="fc_history.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>
                    <span><?php te('fchub.storico'); ?></span>
                </a>
                <?php if ($R['oggi_study'] > 0): ?><span class="mode-today"><?php te('fchub.oggi_n', ['n' => $R['oggi_study']]); ?></span><?php endif; ?>
            </div>
        </article>

        <article class="mode-card mode-vocab">
            <a class="mode-main" href="fc_languages.php">
                <?php if ($corsi): ?>
                <span class="flag-stack" aria-hidden="true">
                    <?php foreach (array_slice($corsi, 0, 3) as $c): ?>
                    <img class="flag" src="<?php echo sec_h(fc_lingua_bandiera($c['learn_lang'])); ?>" alt="">
                    <?php endforeach; ?>
                </span>
                <?php else: ?>
                <span class="mode-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.8 3.9 6 3.9 9.5s-1.3 6.7-3.9 9.5c-2.6-2.8-3.9-6-3.9-9.5s1.3-6.7 3.9-9.5Z"/></svg>
                </span>
                <?php endif; ?>
                <span class="mode-txt">
                    <span class="mode-title"><?php te('fchub.lingue'); ?></span>
                    <span class="mode-sub">
                        <?php if ($corsi):
                            echo sec_h(implode(', ', array_map(fn($c) => fc_lingua_nome($c['learn_lang'], false), array_slice($corsi, 0, 3)))
                                       . (count($corsi) > 3 ? ' +' . (count($corsi) - 3) : ''));
                        else: te('fchub.lingue_sub_vuoto'); endif; ?>
                    </span>
                </span>
                <svg class="mode-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <?php if ($corsi): ?>
            <div class="mode-stats">
                <span><b><?php echo count($corsi); ?></b> <?php te(count($corsi) === 1 ? 'fchub.lingua' : 'fchub.lingue_min'); ?></span>
                <span><b><?php echo (int)$R['vocab']['parole']; ?></b> <?php te($R['vocab']['parole'] === 1 ? 'fchub.parola' : 'fchub.parole'); ?></span>
                <span><b><?php echo $vPct; ?>%</b> <?php te('fchub.imparate'); ?></span>
            </div>
            <div class="mode-bar"><span style="width:<?php echo $vPct; ?>%"></span></div>
            <?php /* Scorciatoie: un tocco e sei dentro il corso, senza passare
                     dall'elenco delle lingue. */ ?>
            <div class="course-chips">
                <?php foreach ($corsi as $c): ?>
                <a class="course-chip" href="<?php echo sec_h('fc_decks.php?course=' . (int)$c['id']); ?>">
                    <img class="flag flag-xs" src="<?php echo sec_h(fc_lingua_bandiera($c['learn_lang'])); ?>" alt="">
                    <span><?php echo sec_h(fc_lingua_nome($c['learn_lang'], false)); ?></span>
                    <?php if ($c['oggi'] > 0): ?><span class="chip-n"><?php echo (int)$c['oggi']; ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <a class="course-chip chip-add" href="fc_languages.php#add" aria-label="<?php te('fchub.aggiungi_lingua'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                </a>
            </div>
            <?php else: ?>
            <div class="mode-actions">
                <a class="pill-btn pill-play" href="fc_languages.php#add">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span><?php te('fchub.aggiungi_lingua'); ?></span>
                </a>
            </div>
            <?php endif; ?>
        </article>

    </section>

</main>


<!-- OBIETTIVO GIORNALIERO -->
<div class="modal-overlay" id="sheetGoal" data-sb-popup aria-hidden="true">
    <div class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="goalTitolo">
        <h3 class="sheet-title" id="goalTitolo"><?php te('fchub.obiettivo_titolo'); ?></h3>
        <p class="sheet-sub"><?php te('fchub.obiettivo_testo'); ?></p>
        <div class="goal-pick" id="goalPick">
            <?php foreach ([10, 20, 30, 50, 80, 120] as $g): ?>
            <button type="button" class="goal-opt<?php echo $g === $R['obiettivo'] ? ' on' : ''; ?>" data-goal="<?php echo $g; ?>"><?php echo $g; ?></button>
            <?php endforeach; ?>
        </div>
        <div class="goal-custom">
            <button type="button" class="icon-btn" data-step="-5" aria-label="−5">−</button>
            <input type="number" id="goalInput" min="1" max="1000" inputmode="numeric" value="<?php echo (int)$R['obiettivo']; ?>">
            <button type="button" class="icon-btn" data-step="5" aria-label="+5">+</button>
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn btn-secondary" data-chiudi><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-primary" id="goalSalva"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };
    function apri(el) { el.style.display = 'flex'; el.classList.remove('closing'); el.setAttribute('aria-hidden', 'false'); document.documentElement.classList.add('hub-locked'); }
    function chiudi(el) {
        el.classList.add('closing');
        setTimeout(function () { el.style.display = 'none'; el.classList.remove('closing'); el.setAttribute('aria-hidden', 'true'); document.documentElement.classList.remove('hub-locked'); }, 220);
    }
    var sheet = $('sheetGoal'), input = $('goalInput');
    $('btnGoal').addEventListener('click', function () { apri(sheet); try { navigator.vibrate && navigator.vibrate(8); } catch (e) {} });
    sheet.addEventListener('click', function (e) {
        if (e.target === sheet || e.target.closest('[data-chiudi]')) { chiudi(sheet); return; }
        var o = e.target.closest('[data-goal]');
        if (o) {
            input.value = o.getAttribute('data-goal');
            sheet.querySelectorAll('.goal-opt').forEach(function (b) { b.classList.toggle('on', b === o); });
        }
        var s = e.target.closest('[data-step]');
        if (s) {
            input.value = Math.max(1, Math.min(1000, (parseInt(input.value, 10) || 0) + parseInt(s.getAttribute('data-step'), 10)));
            sheet.querySelectorAll('.goal-opt').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-goal') === input.value); });
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.getAttribute('aria-hidden') === 'false') chiudi(sheet); });
    $('goalSalva').addEventListener('click', function () {
        var b = this; b.disabled = true;
        var corpo = new URLSearchParams();
        corpo.set('action', 'goal'); corpo.set('daily_goal', input.value);
        fetch('fc_home.php', { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.ok) location.reload(); else { b.disabled = false; } })
            .catch(function () {
                b.disabled = false;
                var t = $('toast'); t.textContent = T('fchub.err_rete'); t.classList.add('show');
                setTimeout(function () { t.classList.remove('show'); }, 2600);
            });
    });
})();
</script>
</body>
</html>
