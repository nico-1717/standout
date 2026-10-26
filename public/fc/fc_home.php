<?php
/* ============================================================================
   fc_home.php — LA PAGINA PRINCIPALE DELLE FLASHCARD

   Due strade: le flashcard di studio e le lingue. Sopra, i numeri che valgono
   per entrambe — la serie di giorni, le carte di oggi rispetto all'obiettivo,
   la settimana — perche' studiare una parola di inglese o una formula di
   analisi tiene viva la stessa abitudine.

   I numeri vengono da fc_summary.php, lo stesso file del cruscotto: due
   pagine che contano le stesse cose devono contarle nello stesso modo.

   Le lingue vivono tutte qui: ogni corso ha la sua riga (bandiera della lingua
   che impari con, in piccolo, quella da cui parti), "Gioca" porta allo storico
   e il menu "..." apre le opzioni del corso. L'aggiunta di una lingua e' un
   foglio di questa pagina. La vecchia pagina fc_languages.php non e' piu' una
   pagina: resta solo come indirizzo a cui il foglio manda le sue richieste
   (fc_languages.js) e rimanda qui chi ci arriva da un vecchio segnalibro.
   ============================================================================ */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_summary.php';
require_once __DIR__ . '/../../lib/fc/fc_ripresa.php';

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
/* CONTINUA O GIOCA, per studio e per lingue: ciascuno riprende la sua partita
   lasciata a meta' (stessa regola di Home e Storico, fc_ripresa.php) oppure ne
   inizia una nuova. Sta sopra i numeri perche' e' la cosa che si viene a fare. */
$goStudy = fc_partita_in_sospeso($current_user_id, 'study');
$goVocab = fc_partita_in_sospeso($current_user_id, 'vocab');
$goCorso = fc_corso_da_giocare($current_user_id);
$corsi = $R['vocab']['corsi'];

/* PER IL FOGLIO "AGGIUNGI UNA LINGUA". La lingua di partenza proposta e' quella
   dei corsi che hai gia', se e' sempre la stessa (un italiano che impara anche
   il tedesco parte dall'italiano), altrimenti quella dell'interfaccia. */
$ui = standout_lingua();
$linguaNota = fc_lingua_nota_predefinita();
$note = array_unique(array_map(fn($c) => $c['known_lang'], $corsi));
if (count($note) === 1) $linguaNota = reset($note);

/* L'elenco per la ricerca, gia' ordinato e con i nomi nella lingua
   dell'interfaccia: il JavaScript filtra e basta. */
$catalogo = [];
foreach (FC_LINGUE as $codice => $v) {
    $catalogo[] = [
        'c' => $codice, 'b' => fc_lingua_base($codice), 'f' => $v[0],
        'n' => $ui === 'en' ? $v[4] : $v[2], 'v' => $ui === 'en' ? $v[5] : $v[3],
        // gli altri nomi servono solo alla ricerca: "german" trova il tedesco anche in italiano
        'a' => trim(($ui === 'en' ? $v[2] : $v[4]) . ' ' . $v[6] . ' ' . $v[7] . ' ' . $codice),
        'nat' => $v[6],
    ];
}
$coll = class_exists('Collator') ? new Collator($ui) : null;
usort($catalogo, function ($x, $y) use ($coll) {
    $a = $x['n'] . ' ' . $x['v']; $b = $y['n'] . ' ' . $y['v'];
    return $coll ? $coll->compare($a, $b) : strcmp(iconv('UTF-8', 'ASCII//TRANSLIT', $a), iconv('UTF-8', 'ASCII//TRANSLIT', $b));
});
$giaCorsi = [];
foreach ($corsi as $c) $giaCorsi[] = ['id' => (int)$c['id'], 'k' => $c['known_lang'], 'l' => $c['learn_lang']];

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

/* LE CARTE CHE SAI, divise fra studio e lingue: chi studia le parole vuole
   sapere quante ne ha imparate, non un totale in cui si perdono. I conteggi di
   oggi / settimana / mese li calcola il JavaScript dai giorni (per tipo),
   cosi' cambiano insieme al grafico quando si sceglie studio o lingue
   (fcv_conosciute: col tastino, al livello la_sai o in un mazzo archiviato). */
$impStudio = (int)$R['study']['imparate'];   $unitaStudio = (int)$R['study']['carte'];
$impLingue = (int)$R['vocab']['imparate'];   $unitaLingue = (int)$R['vocab']['parole'];
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
            <?php if ($goStudy): ?>
            <?php /* la partita lasciata a meta': si riprende da qui */ ?>
            <a class="go-resume" href="<?php echo sec_h('fc_game.php?game_id=' . (int)$goStudy['id'] . '&position=' . (int)$goStudy['pos']); ?>">
                <span class="go-resume-txt">
                    <b><?php te('fchub.partita_in_corso'); ?></b>
                    <span><?php echo sec_h(($goStudy['label'] !== '' ? $goStudy['label'] . ' · ' : '') . t('fchub.carte_fatte', ['n' => $goStudy['closed'], 'tot' => $goStudy['total']])); ?></span>
                    <span class="go-resume-bar"><span style="width:<?php echo (int)round($goStudy['closed'] / max(1, $goStudy['total']) * 100); ?>%"></span></span>
                </span>
                <span class="pill-btn pill-play">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                    <span><?php te('fchub.continua'); ?></span>
                </span>
            </a>
            <?php endif; ?>
            <div class="mode-actions">
                <?php /* "Gioca" porta allo storico (da li' si sceglie cosa giocare), come nelle
                         lingue: il tasto "Storico" a parte non serve piu'. */ ?>
                <a class="pill-btn<?php echo $goStudy ? '' : ' pill-play'; ?>" href="fc_history.php">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                    <span><?php te('fchub.gioca'); ?></span>
                </a>
                <a class="pill-btn" href="fc_card_edit.php?modo=insert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span><?php te('fchub.nuova_carta'); ?></span>
                </a>
                <?php if ($R['oggi_study'] > 0): ?><span class="mode-today"><?php te('fchub.oggi_n', ['n' => $R['oggi_study']]); ?></span><?php endif; ?>
            </div>
        </article>

        <article class="mode-card mode-vocab">
            <?php /* Un'intestazione e basta, non un link: la pagina "Lingue" non c'e'
                     piu', ogni lingua ha qui sotto la sua riga con le sue azioni. */ ?>
            <div class="mode-main">
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
            </div>
            <?php if ($corsi): ?>
            <div class="mode-stats">
                <span><b><?php echo count($corsi); ?></b> <?php te(count($corsi) === 1 ? 'fchub.lingua' : 'fchub.lingue_min'); ?></span>
                <span><b><?php echo (int)$R['vocab']['parole']; ?></b> <?php te($R['vocab']['parole'] === 1 ? 'fchub.parola' : 'fchub.parole'); ?></span>
                <span><b><?php echo $vPct; ?>%</b> <?php te('fchub.imparate'); ?></span>
            </div>
            <div class="mode-bar"><span style="width:<?php echo $vPct; ?>%"></span></div>
            <?php /* Una riga grande per lingua. "Gioca" porta allo storico (da li' si
                     sceglie cosa giocare), quindi il tasto "Storico" a parte non
                     serve piu'. La bandiera grande e' la lingua che impari, quella
                     piccola la lingua da cui parti: come nella vecchia pagina Lingue. */ ?>
            <div class="course-rows">
                <?php foreach ($corsi as $c): $cid = (int)$c['id'];
                      $qualeGioco = ($goVocab && (int)$goVocab['course'] === $cid) ? $goVocab : null;
                      $cPct = $c['parole'] > 0 ? (int)round($c['imparate'] / $c['parole'] * 100) : 0;
                      $nomeCorso = fc_lingua_nome($c['learn_lang'], false);
                      $haVarianti = count(array_filter(array_keys(FC_LINGUE), fn($k) => fc_lingua_base($k) === fc_lingua_base($c['learn_lang']))) > 1; ?>
                <div class="course-big" data-course-card data-id="<?php echo $cid; ?>"
                     data-learn="<?php echo sec_h($c['learn_lang']); ?>" data-known="<?php echo sec_h($c['known_lang']); ?>"
                     data-name="<?php echo sec_h($nomeCorso); ?>" data-words="<?php echo (int)$c['parole']; ?>"
                     data-project="<?php echo (int)($c['project_id'] ?? 0); ?>" data-variants="<?php echo $haVarianti ? 1 : 0; ?>">
                    <div class="course-big-top">
                        <a class="course-big-main" href="<?php echo sec_h('fc_decks.php?course=' . $cid); ?>">
                            <span class="lng-flags" aria-hidden="true">
                                <img class="flag flag-big" src="<?php echo sec_h(fc_lingua_bandiera($c['learn_lang'])); ?>" alt="">
                                <img class="flag flag-small" src="<?php echo sec_h(fc_lingua_bandiera($c['known_lang'])); ?>" alt="">
                            </span>
                            <span class="course-big-txt">
                                <span class="course-big-name"><?php echo sec_h($nomeCorso); ?></span>
                                <span class="course-big-sub"><?php echo (int)$c['parole']; ?> <?php te($c['parole'] === 1 ? 'fchub.parola' : 'fchub.parole'); ?> · <?php echo $cPct; ?>% <?php te('fchub.imparate'); ?></span>
                            </span>
                            <?php if ($c['oggi'] > 0): ?><span class="chip-n"><?php echo (int)$c['oggi']; ?></span><?php endif; ?>
                            <svg class="mode-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        <?php /* il menu del corso (accento, progetto, elimina) sta fuori dal
                                 link: un tocco qui non deve aprire i mazzi */ ?>
                        <button type="button" class="icon-btn lng-more" aria-label="<?php te('fchub.opzioni'); ?>">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                        </button>
                    </div>
                    <?php if ($qualeGioco): ?>
                    <a class="go-resume" href="<?php echo sec_h('fc_game.php?game_id=' . (int)$qualeGioco['id'] . '&position=' . (int)$qualeGioco['pos']); ?>">
                        <span class="go-resume-txt">
                            <b><?php te('fchub.partita_in_corso'); ?></b>
                            <span><?php echo sec_h(($qualeGioco['label'] !== '' ? $qualeGioco['label'] . ' · ' : '') . t('fchub.carte_fatte', ['n' => $qualeGioco['closed'], 'tot' => $qualeGioco['total']])); ?></span>
                            <span class="go-resume-bar"><span style="width:<?php echo (int)round($qualeGioco['closed'] / max(1, $qualeGioco['total']) * 100); ?>%"></span></span>
                        </span>
                        <span class="pill-btn pill-play">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                            <span><?php te('fchub.continua'); ?></span>
                        </span>
                    </a>
                    <?php endif; ?>
                    <div class="mode-actions">
                        <?php if ($c['parole'] > 0): ?>
                        <a class="pill-btn<?php echo $qualeGioco ? '' : ' pill-play'; ?>" href="<?php echo sec_h('fc_history.php?course=' . $cid); ?>">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                            <span><?php te('fchub.gioca'); ?></span>
                        </a>
                        <a class="pill-btn" href="<?php echo sec_h('fc_card_edit.php?modo=insert&course=' . $cid); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            <span><?php te('fchub.nuova_carta'); ?></span>
                        </a>
                        <?php else: /* senza parole non c'e' niente da giocare: si parte dal primo mazzo */ ?>
                        <a class="pill-btn pill-play" href="<?php echo sec_h('fc_decks.php?course=' . $cid . '&new=1'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            <span><?php te('fchub.primo_mazzo'); ?></span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="button" class="course-add" id="btnAggiungiCard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span><?php te('fchub.aggiungi_lingua'); ?></span>
                </button>
            </div>
            <?php else: ?>
            <div class="mode-actions">
                <button type="button" class="pill-btn pill-play" id="btnAggiungiVuoto">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span><?php te('fchub.aggiungi_lingua'); ?></span>
                </button>
            </div>
            <?php endif; ?>
        </article>

    </section>

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


    <!-- LE CARTE CHE SAI -->
    <section class="learned-card" id="learned" aria-labelledby="learnedTit">
        <div class="lc-head">
            <div class="lc-tot">
                <span class="lc-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="M6 10.5V16c0 1 2.7 3 6 3s6-2 6-3v-5.5"/></svg></span>
                <span class="lc-lbl lc-title" id="learnedTit"><?php te('fchub.carte_che_sai'); ?></span>
            </div>
            <div class="lc-seg" role="radiogroup" aria-label="<?php te('fchub.imparate_per'); ?>">
                <button type="button" role="radio" aria-checked="true" class="is-on" data-passo="g"><?php te('fchub.per_giorno'); ?></button>
                <button type="button" role="radio" aria-checked="false" data-passo="s"><?php te('fchub.per_settimana'); ?></button>
                <button type="button" role="radio" aria-checked="false" data-passo="m"><?php te('fchub.per_mese'); ?></button>
            </div>
        </div>
        <?php /* Due numeri separati, studio e lingue. Sono anche tasti: toccandone uno il
                 grafico e i "+N" mostrano solo quel tipo, toccandolo di nuovo tornano
                 insieme. Le lingue compaiono solo se c'e' almeno un corso. */ ?>
        <div class="lc-split" role="group" aria-label="<?php te('fchub.carte_che_sai'); ?>">
            <button type="button" class="lc-tile" data-tipo="study" aria-pressed="false">
                <span class="lc-num"><?php echo $impStudio; ?><?php if ($unitaStudio > 0): ?><small>/<?php echo $unitaStudio; ?></small><?php endif; ?></span>
                <span class="lc-lbl"><?php te('fchub.studio'); ?></span>
            </button>
            <?php if ($corsi): ?>
            <button type="button" class="lc-tile" data-tipo="vocab" aria-pressed="false">
                <span class="lc-num"><?php echo $impLingue; ?><?php if ($unitaLingue > 0): ?><small>/<?php echo $unitaLingue; ?></small><?php endif; ?></span>
                <span class="lc-lbl"><?php te('fchub.lingue'); ?> · <?php te('fchub.parole'); ?></span>
            </button>
            <?php endif; ?>
        </div>
        <div class="lc-chips">
            <span class="lc-chip" data-chip="oggi"><b>+0</b> <?php te('fchub.oggi'); ?></span>
            <span class="lc-chip" data-chip="sett"><b>+0</b> <?php te('fchub.questa_settimana'); ?></span>
            <span class="lc-chip" data-chip="mese"><b>+0</b> <?php te('fchub.questo_mese'); ?></span>
        </div>
        <div class="lc-chart" id="learnedChart" aria-hidden="true"></div>
        <p class="lc-nota"><?php te('fchub.imparate_nota'); ?></p>
        <script type="application/json" id="learnedDati"><?php echo json_encode([
            'giorni' => $R['imparate_giorni'], 'study' => $R['imparate_giorni_study'], 'vocab' => $R['imparate_giorni_vocab'],
            'oggi' => date('Y-m-d'), 'lingua' => standout_lingua(),
        ], JSON_HEX_TAG); ?></script>
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

<!-- AGGIUNGI UNA LINGUA (la logica e' in fc_languages.js, le richieste vanno a fc_languages.php) -->
<div class="modal-overlay" id="sheetAdd" data-sb-popup aria-hidden="true">
    <div class="sheet-panel sheet-tall" role="dialog" aria-modal="true" aria-labelledby="addTitolo">
        <div class="sheet-head">
            <h3 class="sheet-title" id="addTitolo"><?php te('fchub.aggiungi_lingua'); ?></h3>
            <button type="button" class="sheet-close" data-chiudi aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <?php /* La lingua di partenza e' gia' scelta. Si cambia solo se serve
                 (un italiano che impara il giapponese dall'inglese): un tocco
                 qui, e l'elenco sotto diventa quello delle lingue di partenza. */ ?>
        <button type="button" class="known-row" id="knownRow">
            <span class="known-lbl"><?php te('fchub.parto_da'); ?></span>
            <img class="flag flag-xs" id="knownFlag" src="<?php echo sec_h(fc_lingua_bandiera($linguaNota)); ?>" alt="">
            <span class="known-name" id="knownName"><?php echo sec_h(fc_lingua_nome($linguaNota, false)); ?></span>
            <svg class="known-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" id="lngSearch" placeholder="<?php te('fchub.cerca_lingua'); ?>" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go">
        </div>

        <div class="lng-list" id="lngList" role="listbox" aria-labelledby="addTitolo"></div>
    </div>
</div>


<!-- OPZIONI DI UN CORSO -->
<div class="modal-overlay" id="sheetMore" data-sb-popup aria-hidden="true">
    <div class="sheet-panel" role="dialog" aria-modal="true">
        <h3 class="sheet-title" id="moreTitolo"></h3>
        <div class="row-actions">
            <a class="row-action" id="moreDecks" href="#">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6.5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/></svg>
                <span><?php te('fchub.apri_mazzi'); ?></span>
            </a>
            <a class="row-action" id="moreHistory" href="#">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>
                <span><?php te('fchub.storico_partite'); ?></span>
            </a>
            <button type="button" class="row-action" id="moreVariant">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
                <span><?php te('fchub.cambia_accento'); ?></span>
            </button>
            <button type="button" class="row-action" id="moreProject">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3.5h6v2.5H9z"/><path d="M8.5 11h7M8.5 14.5h7"/></svg>
                <span id="moreProjectTxt"><?php te('fchub.collega_progetto'); ?></span>
            </button>
            <button type="button" class="row-action row-danger" id="moreDelete">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                <span><?php te('fchub.elimina_corso'); ?></span>
            </button>
        </div>
    </div>
</div>


<!-- CONFERMA ELIMINAZIONE -->
<div class="modal-overlay" id="sheetDelete" data-sb-popup aria-hidden="true">
    <div class="sheet-panel" role="alertdialog" aria-modal="true">
        <h3 class="sheet-title" id="delTitolo"></h3>
        <p class="sheet-sub" id="delTesto"></p>
        <div class="sheet-actions">
            <button type="button" class="btn btn-secondary" data-chiudi><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-danger" id="delConferma"><?php te('fchub.elimina'); ?></button>
        </div>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script type="application/json" id="lng-dati"><?php
    echo json_encode([
        'catalogo' => $catalogo,
        'popolari' => FC_LINGUE_POPOLARI,
        'corsi'    => $giaCorsi,
        'nota'     => $linguaNota,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?></script>

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

    /* LE CARTE IMPARATE, per giorno / settimana / mese. I giorni arrivano dal
       server (fcv_conosciute); qui si raggruppano: 14 giorni, 8 settimane
       (da lunedi'), 6 mesi. Barre semplici, il numero sopra quelle piene. */
    (function () {
        var box = $('learnedChart'), datiEl = $('learnedDati');
        if (!box || !datiEl) return;
        var D; try { D = JSON.parse(datiEl.textContent); } catch (e) { return; }
        /* Quale tipo si guarda: '' = studio e lingue insieme, 'study' o 'vocab' = solo
           quello (si sceglie toccando uno dei due numeri sopra). */
        var tipo = '', passoAtt = 'g';
        function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
        var oggi = new Date(D.oggi + 'T12:00:00');
        function gruppi(passo) {
            var giorni = (tipo ? D[tipo] : D.giorni) || {};
            var out = [], i, d, k, n;
            if (passo === 'g') {
                for (i = 13; i >= 0; i--) {
                    d = new Date(oggi); d.setDate(d.getDate() - i); k = iso(d);
                    out.push({ v: giorni[k] || 0, l: String(d.getDate()), qui: i === 0 });
                }
            } else if (passo === 's') {
                var lun = new Date(oggi); lun.setDate(lun.getDate() - ((lun.getDay() + 6) % 7));
                for (i = 7; i >= 0; i--) {
                    var da = new Date(lun); da.setDate(da.getDate() - i * 7);
                    n = 0;
                    for (var j = 0; j < 7; j++) { d = new Date(da); d.setDate(d.getDate() + j); n += giorni[iso(d)] || 0; }
                    out.push({ v: n, l: da.getDate() + '/' + (da.getMonth() + 1), qui: i === 0 });
                }
            } else {
                for (i = 5; i >= 0; i--) {
                    d = new Date(oggi.getFullYear(), oggi.getMonth() - i, 1);
                    var pre = iso(d).slice(0, 7); n = 0;
                    Object.keys(giorni).forEach(function (g) { if (g.slice(0, 7) === pre) n += giorni[g]; });
                    var nome = '';
                    try { nome = d.toLocaleDateString(D.lingua || undefined, { month: 'short' }); } catch (e) { nome = String(d.getMonth() + 1); }
                    out.push({ v: n, l: nome, qui: i === 0 });
                }
            }
            return out;
        }
        /* i "+N" di oggi / questa settimana / questo mese, dello stesso tipo del grafico */
        function aggiornaChip() {
            var giorni = (tipo ? D[tipo] : D.giorni) || {};
            var lun = new Date(oggi); lun.setDate(lun.getDate() - ((lun.getDay() + 6) % 7));
            var lunIso = iso(lun), mese = D.oggi.slice(0, 7), v = { oggi: 0, sett: 0, mese: 0 };
            Object.keys(giorni).forEach(function (g) {
                var n = giorni[g] || 0;
                if (g === D.oggi) v.oggi += n;
                if (g >= lunIso && g <= D.oggi) v.sett += n;
                if (g.slice(0, 7) === mese) v.mese += n;
            });
            document.querySelectorAll('.lc-chip[data-chip]').forEach(function (c) {
                var n = v[c.getAttribute('data-chip')] || 0;
                c.querySelector('b').textContent = '+' + n;
                c.classList.toggle('is-on', n > 0);
            });
        }
        function disegna(passo) {
            passoAtt = passo;
            aggiornaChip();
            var g = gruppi(passo), max = 1;
            g.forEach(function (x) { if (x.v > max) max = x.v; });
            box.className = 'lc-chart lc-' + passo;
            box.innerHTML = g.map(function (x, i) {
                var h = x.v > 0 ? Math.max(8, Math.round(x.v / max * 100)) : 0;
                return '<div class="lc-col' + (x.qui ? ' is-qui' : '') + '" style="--i:' + i + '">'
                    + '<span class="lc-val">' + (x.v > 0 ? x.v : '') + '</span>'
                    + '<span class="lc-bar"><span style="height:' + h + '%"></span></span>'
                    + '<span class="lc-x">' + x.l + '</span></div>';
            }).join('');
        }
        document.querySelectorAll('.lc-seg [data-passo]').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('.lc-seg [data-passo]').forEach(function (x) {
                    var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-checked', on ? 'true' : 'false');
                });
                disegna(b.getAttribute('data-passo'));
                try { navigator.vibrate && navigator.vibrate(6); } catch (e) {}
            });
        });
        /* i due numeri sono anche tasti: uno solo alla volta, e di nuovo sullo stesso
           per tornare a vedere tutto insieme */
        document.querySelectorAll('.lc-tile[data-tipo]').forEach(function (t) {
            t.addEventListener('click', function () {
                var voglio = t.getAttribute('data-tipo');
                tipo = tipo === voglio ? '' : voglio;
                document.querySelectorAll('.lc-tile[data-tipo]').forEach(function (x) {
                    x.setAttribute('aria-pressed', x.getAttribute('data-tipo') === tipo ? 'true' : 'false');
                });
                disegna(passoAtt);
                try { navigator.vibrate && navigator.vibrate(6); } catch (e) {}
            });
        });
        disegna('g');
    })();
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
<script src="<?php echo PWA_BASE; ?>fc/fc_languages.js?v=<?php echo @filemtime(__DIR__ . '/fc_languages.js') ?: 1; ?>"></script>
</body>
</html>
