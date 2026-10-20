<?php
/* ============================================================================
   fc_languages.php — LE LINGUE CHE STAI IMPARANDO

   Un riquadro per corso ("italiano -> inglese"), con le sue parole, quante ne
   hai imparate e la serie di giorni. Toccandolo si entra nei suoi mazzi
   (fc_decks.php?course=N): da li' in poi le pagine sono le stesse delle
   flashcard di studio.

   Aggiungere una lingua deve costare un tocco: la lingua di partenza e' gia'
   la tua (quella dell'interfaccia), le piu' studiate sono in vista prima
   ancora di scrivere, e la ricerca trova "tedesco", "deutsch", "german" o
   "de" senza badare agli accenti. Il corso nuovo porta con se' il suo
   progetto in progetti.php, cosi' il tempo passato sulla lingua finisce nel
   pomodoro senza doverlo collegare a mano.
   ============================================================================ */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_summary.php';
fc_usa_tipo('vocab');


/* ----------------------------------------------------------------------------
   IL PROGETTO DEL CORSO
   Se esiste gia' un progetto con il nome della lingua (chi studia inglese ha
   spesso gia' "Inglese" fra i progetti) si collega quello invece di crearne
   un doppione. Solo se non e' gia' di un altro corso: due corsi sullo stesso
   progetto mescolerebbero i loro minuti.
   ---------------------------------------------------------------------------- */
function fcl_progetto_per_corso(int $uid, array $corso): ?int {
    $nome = fc_lingua_nome($corso['learn_lang'], false);
    $esistente = sec_one("SELECT p.id FROM projects p
                           WHERE p.user_id = ? AND LOWER(p.name) = LOWER(?)
                             AND NOT EXISTS (SELECT 1 FROM fc_vocab_courses k WHERE k.project_id = p.id AND k.id <> ?)
                           ORDER BY p.parent_id IS NOT NULL, p.id LIMIT 1",
                         [$uid, $nome, (int)$corso['id']], 'isi');
    if ($esistente) {
        $pid = (int)$esistente['id'];
    } else {
        $ordine = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM projects
                                  WHERE user_id = ? AND parent_id IS NULL", [$uid], 'i')['n'] ?? 0);
        sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
               VALUES (?, NULL, ?, '#5856D6', 1, 'in_progress', ?, NOW())", [$uid, $nome, $ordine], 'isi');
        $pid = sec_insert_id();
    }
    if ($pid <= 0) return null;
    sec_q("UPDATE fc_vocab_courses SET project_id = ? WHERE id = ? AND user_id = ?", [$pid, (int)$corso['id'], $uid], 'iii');
    return $pid;
}


/* ----------------------------------------------------------------------------
   AZIONI (chiamate dalla pagina con fetch: rispondono JSON)
   ---------------------------------------------------------------------------- */
$azione = sec_enum($_POST['action'] ?? null, ['add', 'project', 'variant', 'delete', 'order'], '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $azione !== '') {
    sec_guard_write($current_user_id, 'fc_courses', 0, 60, 60);

    if ($azione === 'add') {
        $impara  = sec_str($_POST['learn'] ?? '', 10);
        $conosce = sec_str($_POST['known'] ?? '', 10);
        if (!fc_lingua_valida($impara) || !fc_lingua_nota_valida($conosce)) {
            sec_die_json(t('fchub.err_lingua'), 422, 'lang');
        }
        if (fc_lingua_base($impara) === $conosce) {
            sec_die_json(t('fchub.err_stessa'), 422, 'same');
        }
        /* Gia' presente: non e' un errore, si entra nel corso che c'e'. Un
           doppio tocco o un "aggiungi" su una lingua dimenticata devono
           portare allo stesso posto. */
        $c = sec_one("SELECT id FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ?",
                     [$current_user_id, $conosce, $impara], 'iss');
        if (!$c) {
            $ordine = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM fc_vocab_courses WHERE user_id = ?",
                                    [$current_user_id], 'i')['n'] ?? 0);
            sec_q("INSERT INTO fc_vocab_courses (user_id, known_lang, learn_lang, custom_order) VALUES (?, ?, ?, ?)",
                  [$current_user_id, $conosce, $impara, $ordine], 'issi');
            $id = sec_insert_id();
            $corso = fc_leggi_corso($id, $current_user_id);
            if ($corso) {
                try { fcl_progetto_per_corso($current_user_id, $corso); }
                catch (Throwable $e) { sec_log_error('fc_languages', 'progetto: ' . $e->getMessage()); }
            }
        } else {
            $id = (int)$c['id'];
        }
        sec_json(['ok' => true, 'id' => $id, 'url' => 'fc_decks.php?course=' . $id]);
    }

    $corso = fc_leggi_corso(sec_int($_POST['course'] ?? 0, 0), $current_user_id);
    if (!$corso && $azione !== 'order') sec_die_json(t('fchub.err_non_trovato'), 404, 'not_found');

    if ($azione === 'project') {
        $pid = fcl_progetto_per_corso($current_user_id, $corso);
        sec_json(['ok' => $pid !== null, 'project_id' => $pid]);
    }

    if ($azione === 'variant') {
        /* Solo fra varianti della stessa lingua: cambiano voce e bandiera, le
           parole restano giuste. Passare da inglese a tedesco invece
           renderebbe sbagliate tutte le parole gia' scritte. */
        $nuova = sec_str($_POST['learn'] ?? '', 10);
        if (!fc_lingua_valida($nuova) || fc_lingua_base($nuova) !== fc_lingua_base($corso['learn_lang'])) {
            sec_die_json(t('fchub.err_lingua'), 422, 'lang');
        }
        $doppio = sec_one("SELECT id FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ? AND id <> ?",
                          [$current_user_id, $corso['known_lang'], $nuova, (int)$corso['id']], 'issi');
        if ($doppio) sec_die_json(t('fchub.err_gia_corso'), 409, 'exists');
        sec_q("UPDATE fc_vocab_courses SET learn_lang = ? WHERE id = ? AND user_id = ?",
              [$nuova, (int)$corso['id'], $current_user_id], 'sii');
        sec_json(['ok' => true]);
    }

    if ($azione === 'delete') {
        /* Le carte non hanno una chiave esterna verso il mazzo (come quelle di
           studio): cancellare solo i mazzi le lascerebbe orfane. Si tolgono
           prima le carte — figlie, esempi, giocate e preferiti seguono da soli
           per cascata — poi le parole imparate, poi il corso, che si porta via
           mazzi e partite. */
        $ids = array_map(fn($r) => (int)$r['id'],
                         sec_all("SELECT id FROM fc_vocab_cards WHERE category_id IN
                                    (SELECT id FROM fc_vocab_decks WHERE course_id = ? AND user_id = ?)",
                                 [(int)$corso['id'], $current_user_id], 'ii'));
        foreach (array_chunk($ids, 500) as $blocco) {
            $ph = implode(',', array_fill(0, count($blocco), '?'));
            sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND fc_image_id IN ($ph)", array_merge([$current_user_id], $blocco));
            sec_q("DELETE FROM fc_vocab_cards WHERE id IN ($ph)", $blocco);
        }
        sec_q("DELETE FROM fc_vocab_courses WHERE id = ? AND user_id = ?", [(int)$corso['id'], $current_user_id], 'ii');
        sec_audit('fc_course_delete', 'ok', 'corso ' . (int)$corso['id'] . ', parole ' . count($ids));
        sec_json(['ok' => true]);
    }

    if ($azione === 'order') {
        $n = 0;
        foreach (explode(',', (string)($_POST['ids'] ?? '')) as $grezzo) {
            $id = sec_int($grezzo, 0);
            if ($id <= 0) continue;
            sec_q("UPDATE fc_vocab_courses SET custom_order = ? WHERE id = ? AND user_id = ?", [$n++, $id, $current_user_id], 'iii');
        }
        sec_json(['ok' => true]);
    }
}


/* ----------------------------------------------------------------------------
   LETTURA
   ---------------------------------------------------------------------------- */
$corsi = fc_corsi_con_numeri($current_user_id);
$totParole = 0;
foreach ($corsi as $c) $totParole += $c['parole'];
$linguaNota = fc_lingua_nota_predefinita();
/* La lingua di partenza proposta: quella dei corsi che hai gia', se e' sempre
   la stessa (un italiano che impara anche il tedesco parte dall'italiano),
   altrimenti quella dell'interfaccia. */
$note = array_unique(array_map(fn($c) => $c['known_lang'], $corsi));
if (count($note) === 1) $linguaNota = reset($note);

/* L'elenco per la ricerca, gia' ordinato e con i nomi nella lingua
   dell'interfaccia: il JavaScript filtra e basta. */
$ui = standout_lingua();
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

/* "ieri", "3 giorni fa": la data esatta qui non serve, serve sapere quanto e'
   passato */
function fcl_quando(?string $giorno): string {
    if (!$giorno) return t('fchub.mai');
    $giorni = (int)round((strtotime('today 12:00') - strtotime($giorno . ' 12:00')) / 86400);
    if ($giorni <= 0) return t('fchub.oggi');
    if ($giorni === 1) return t('fchub.ieri');
    return t('fchub.n_giorni_fa', ['n' => $giorni]);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<title><?php te('fchub.lingue_titolo'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_hub.css?v=<?php echo @filemtime(__DIR__ . '/fc_hub.css') ?: 1; ?>">
<script src="<?php echo PWA_BASE; ?>_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>
<?php echo standout_i18n_json(['comune', 'fchub']); ?>
</head>
<body class="hub-body">

<header class="app-header">
    <div class="hdr-top">
        <a href="fc_home.php" class="hdr-back" aria-label="<?php te('fchub.torna_flashcard'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="hdr-titles">
            <h1 class="hdr-title"><?php te('fchub.lingue_titolo'); ?></h1>
            <div class="hdr-sub">
                <?php if ($corsi): ?>
                    <?php te(count($corsi) === 1 ? 'fchub.n_lingua_parole' : 'fchub.n_lingue_parole', ['n' => count($corsi), 'parole' => $totParole]); ?>
                <?php else: ?>
                    <?php te('fchub.lingue_sottotitolo_vuoto'); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($corsi): ?>
        <button type="button" class="hdr-add" id="btnAggiungi" aria-label="<?php te('fchub.aggiungi_lingua'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        </button>
        <?php endif; ?>
    </div>
</header>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<main class="page">

<?php if (!$corsi): ?>
    <?php /* Nessun corso: la pagina vuota non dice "non c'e' niente", chiede
             subito che lingua vuoi — e le piu' studiate si scelgono con un
             tocco, senza aprire niente. */ ?>
    <section class="lng-empty">
        <div class="lng-empty-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.8 3.9 6 3.9 9.5s-1.3 6.7-3.9 9.5c-2.6-2.8-3.9-6-3.9-9.5s1.3-6.7 3.9-9.5Z"/></svg>
        </div>
        <h2><?php te('fchub.vuoto_titolo'); ?></h2>
        <p><?php te('fchub.vuoto_testo', ['lingua' => $ui === 'it' ? mb_strtolower(fc_lingua_nome($linguaNota, false)) : fc_lingua_nome($linguaNota, false)]); ?></p>
        <div class="lng-pop-grid" id="popVuoto"></div>
        <button type="button" class="btn btn-tinted" id="btnAggiungiVuoto"><?php te('fchub.altre_lingue'); ?></button>
    </section>
<?php else: ?>
    <section class="lng-grid" id="lngGrid">
    <?php foreach ($corsi as $c):
        $pct = $c['parole'] > 0 ? (int)round($c['imparate'] / $c['parole'] * 100) : 0;
        $url = 'fc_decks.php?course=' . (int)$c['id'];
        $nome = fc_lingua_nome($c['learn_lang'], false);
        $var = FC_LINGUE[$c['learn_lang']] ?? null;
        $variante = $var ? ($ui === 'en' ? $var[5] : $var[3]) : '';
        $haVarianti = count(array_filter(array_keys(FC_LINGUE), fn($k) => fc_lingua_base($k) === fc_lingua_base($c['learn_lang']))) > 1;
    ?>
        <article class="lng-card" data-id="<?php echo (int)$c['id']; ?>"
                 data-learn="<?php echo sec_h($c['learn_lang']); ?>" data-known="<?php echo sec_h($c['known_lang']); ?>"
                 data-name="<?php echo sec_h($nome); ?>" data-words="<?php echo (int)$c['parole']; ?>"
                 data-project="<?php echo (int)($c['project_id'] ?? 0); ?>" data-variants="<?php echo $haVarianti ? 1 : 0; ?>">
            <a class="lng-card-main" href="<?php echo sec_h($url); ?>">
                <span class="lng-flags" aria-hidden="true">
                    <img class="flag flag-big" src="<?php echo sec_h(fc_lingua_bandiera($c['learn_lang'])); ?>" alt="">
                    <img class="flag flag-small" src="<?php echo sec_h(fc_lingua_bandiera($c['known_lang'])); ?>" alt="">
                </span>
                <span class="lng-info">
                    <span class="lng-name">
                        <?php echo sec_h($nome); ?>
                        <?php if ($variante !== '' && $haVarianti): ?><span class="lng-var"><?php echo sec_h($variante); ?></span><?php endif; ?>
                    </span>
                    <span class="lng-from"><?php te('fchub.dalla_lingua', ['lingua' => $ui === 'it' ? mb_strtolower(fc_lingua_nome($c['known_lang'], false)) : fc_lingua_nome($c['known_lang'], false)]); ?></span>
                </span>
                <?php if ($c['serie'] > 0): ?>
                <span class="lng-streak" title="<?php te('fchub.serie_corso'); ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.6 2.2c.4 2.6-.5 4.6-2.2 6.3-1.4 1.4-2.9 2.8-2.9 5.4 0 1 .3 1.8.8 2.5-.9-.3-1.7-1-2.2-1.9C5 16 5 17 5 17.6 5 21 8 23 12 23s7-2.4 7-6.5c0-3.2-1.6-5.2-3-7 .1 1.3-.3 2.4-1 3.1.2-3.9-.9-7.6-2.4-10.4Z"/></svg>
                    <?php echo (int)$c['serie']; ?>
                </span>
                <?php endif; ?>
            </a>
            <div class="lng-progress" role="progressbar" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php te('fchub.imparate_pct', ['pct' => $pct]); ?>">
                <span style="width:<?php echo $pct; ?>%"></span>
            </div>
            <div class="lng-foot">
                <span class="lng-meta">
                    <?php if ($c['parole'] > 0): ?>
                        <?php te($c['parole'] === 1 ? 'fchub.una_parola_imparate' : 'fchub.n_parole_imparate', ['n' => $c['parole'], 'imp' => $c['imparate']]); ?>
                        <span class="dot">·</span> <?php echo sec_h(fcl_quando($c['ultimo'])); ?>
                    <?php else: ?>
                        <?php te('fchub.nessuna_parola'); ?>
                    <?php endif; ?>
                </span>
                <span class="lng-actions">
                    <?php if ($c['parole'] > 0): ?>
                    <a class="pill-btn pill-play" href="<?php echo sec_h('fc_history.php?course=' . (int)$c['id']); ?>" aria-label="<?php te('fchub.gioca'); ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.1v13.8a1 1 0 0 0 1.5.9l11-6.9a1 1 0 0 0 0-1.8l-11-6.9A1 1 0 0 0 8 5.1Z"/></svg>
                        <span><?php te('fchub.gioca'); ?></span>
                    </a>
                    <?php else: ?>
                    <a class="pill-btn" href="<?php echo sec_h('fc_decks.php?course=' . (int)$c['id'] . '&new=1'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <span><?php te('fchub.primo_mazzo'); ?></span>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="icon-btn lng-more" aria-label="<?php te('fchub.opzioni'); ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                    </button>
                </span>
            </div>
        </article>
    <?php endforeach; ?>
        <button type="button" class="lng-card lng-card-add" id="btnAggiungiCard">
            <span class="lng-add-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>
            <span><?php te('fchub.aggiungi_lingua'); ?></span>
        </button>
    </section>
<?php endif; ?>

</main>


<!-- AGGIUNGI UNA LINGUA -->
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
<script src="<?php echo PWA_BASE; ?>fc/fc_languages.js?v=<?php echo @filemtime(__DIR__ . '/fc_languages.js') ?: 1; ?>"></script>
</body>
</html>
