<?php



require_once 'config.php';

require_once 'auth_check.php'; // 1. Protezione della pagina con login obbligatorio



$current_user_id = (int)$utente_corrente['id']; // 2. ID Utente sicuro dal server
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();
if (!isset($_GET['game_id'])) { header('Location: index.php'); exit; }
$game_id = sec_int($_GET['game_id'] ?? 0, 1);















if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_user_id'])) {
    /* Token di sessione, origine e limite di frequenza. L'id dell'utente viene
       dalla sessione (il new_user_id del modulo si ignora), quindi non c'era una
       falla; ma senza CSRF un altro sito poteva far assegnare a te una partita
       orfana dal tuo browser. */
    sec_guard_write($current_user_id, 'fc_result', 0, 30, 60);

    /* Si adotta solo una partita che e' gia' tua, o che non ha proprietario. */
    $res_check = sec_one("SELECT user_id FROM games WHERE id = ?", [$game_id]);
    if ($res_check && ($res_check['user_id'] === null || (int)$res_check['user_id'] === 0
                       || (int)$res_check['user_id'] === $current_user_id)) {
        sec_q("UPDATE games SET user_id = ? WHERE id = ?", [$current_user_id, $game_id]);
    } else {
        die(sec_h(t('fc.res_non_autorizzato')));
    }
}

$game = sec_one("SELECT g.*, u.username FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ?",
                [$game_id]);
// --- CONTROLLO PROPRIETÀ ---
if (!$game) { die(sec_h(t('fc.res_partita_non_trovata'))); }
if ((int)$game['user_id'] !== $current_user_id) { die(sec_h(t('fc.res_non_proprietario'))); }
// --- FINE CONTROLLO PROPRIETÀ ---

// ================= DURATA TOTALE PARTITA (somma di tutte le sessioni) =================

$durata_totale_secondi = (int)(sec_one("SELECT COALESCE(SUM(duration_seconds),0) AS tot FROM session_durations WHERE game_id = ?",
                                       [$game_id])['tot'] ?? 0);







function fc_formatta_durata($secondi) {



    $secondi = max(0, (int) $secondi);



    $h = intdiv($secondi, 3600);



    $m = intdiv($secondi % 3600, 60);



    if ($h > 0) return $h . 'h ' . $m . 'm';



    if ($m > 0) return $m . 'm';



    return '< 1m';



}



$durata_display = fc_formatta_durata($durata_totale_secondi);











// FETCH DI TUTTI I GAMEPLAYS (Padri e Figli) - Aggiornato su fc_gameplays
$gameplays = sec_all("SELECT * FROM fc_gameplays WHERE game_id = ? ORDER BY position ASC, id ASC", [$game_id]);
$cards = [];
$played_items = 0;
$correct_count = 0;
$error_count = 0;
$positions_played = [];

/* Le schede si prendono con DUE query invece di una per carta giocata: con
   cinquanta carte erano cinquanta viaggi al database, uno dietro l'altro. */
$idPadri = []; $idFigli = [];
foreach ($gameplays as $gp) {
    if (!empty($gp['fc_image_id']) && (int)$gp['fc_image_id'] > 0) $idPadri[] = (int)$gp['fc_image_id'];
    elseif (!empty($gp['fc_child_id']) && (int)$gp['fc_child_id'] > 0) $idFigli[] = (int)$gp['fc_child_id'];
}
$padri = []; $figli = [];
if ($idPadri) {
    $ph = implode(',', array_fill(0, count($idPadri), '?'));
    foreach (sec_all("SELECT id, question_image, question_desc, answer_image, answer_desc
                      FROM fc_images WHERE id IN ($ph)", $idPadri) as $r) $padri[(int)$r['id']] = $r;
}
if ($idFigli) {
    $ph = implode(',', array_fill(0, count($idFigli), '?'));
    foreach (sec_all("SELECT id_child, question_image, question_desc, answer_image, answer_desc
                      FROM fc_child_cards WHERE id_child IN ($ph)", $idFigli) as $r) $figli[(int)$r['id_child']] = $r;
}

foreach ($gameplays as $gp) {
    if (!empty($gp['fc_image_id']) && (int)$gp['fc_image_id'] > 0) {
        $card = $padri[(int)$gp['fc_image_id']] ?? null;
        if ($card) $card['is_child'] = false;
    } elseif (!empty($gp['fc_child_id']) && (int)$gp['fc_child_id'] > 0) {
        $card = $figli[(int)$gp['fc_child_id']] ?? null;
        if ($card) $card['is_child'] = true;
    } else {
        $card = null;
    }

    if ($card) {







        $card['user_answer'] = $gp['user_answer'] ?? '';







        $card['position'] = $gp['position'];







        $cards[] = $card;















        $positions_played[$gp['position']] = true;







        







        // FIX: Contiamo come "giocate" solo quelle che hanno effettivamente una risposta per non sballare le percentuali







        if ($card['user_answer'] != '') {







            $played_items++;







            if ($card['user_answer'] == 'Correct') $correct_count++;







            else $error_count++;







        }







    }







}















$score = ($played_items > 0) ? ($correct_count / $played_items) * 100 : 0;

$played_positions_count = count($positions_played);

// Aggiorna punteggio Partita
$update_query = "UPDATE games SET score = ? WHERE id = ?";

$stmt_up = $conn->prepare($update_query);
$stmt_up->bind_param("di", $score, $game_id);
$stmt_up->execute();
$stmt_up->close();

$is_learnt = (strpos($game['type'], '_L') !== false) ? 1 : 0;
$total_pages = $game['total_father_words'] > 0 ? $game['total_father_words'] : max($played_positions_count, 1);
$display_username = isset($game['username']) ? sec_h($game['username']) : t('fc.res_utente_sconosciuto');

// ==========================================
// FIX RESUME INTELLIGENTE "CONTINUA PARTITA"
// ==========================================

$q_resume = sec_one("SELECT MIN(position) AS resume_pos FROM fc_gameplays WHERE game_id = ? AND user_answer = ''", [$game_id]);
$row_resume = $q_resume;
if ($row_resume && $row_resume['resume_pos']) {
    // C'è una domanda (o figlio) non risposta, riprendiamo da qui!
    $resume_position = $row_resume['resume_pos'];
} else {
    // Tutte le domande attuali sono risposte, passiamo alla prossima posizione inedita
    $q_max = sec_one("SELECT MAX(position) AS max_pos FROM fc_gameplays WHERE game_id = ?", [$game_id]);
    $row_max = $q_max;
    $resume_position = ($row_max && $row_max['max_pos']) ? $row_max['max_pos'] + 1 : 1;
}
?>















<!DOCTYPE html>



<html lang="it">



<head>



    <meta charset="UTF-8">



    <title><?php te('fc.res_titolo'); ?></title>



    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">



    <meta name="mobile-web-app-capable" content="yes">

    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">



    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">







    <?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>



        window.MathJax = {

        /* I dollari NON sono piu' delimitatori. Col formato nuovo la
           formattazione non sta dentro il testo: il LaTeX arriva a MathJax
           gia' racchiuso in \\( \\) o \\[ \\]. Lasciare attivo il "$"
           farebbe prendere per formula una frase come "costa 5$ e 3$". */

        tex: {

            inlineMath: [['\\(', '\\)']],

            displayMath: [['\\[', '\\]']]

        }

        };



    </script>



    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>







<link rel="stylesheet" href="<?php echo PWA_BASE; ?>result_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/result_fc.css') ?: 1; ?>">



    <script src="fc_parser.js"></script>



</head>



<body>



    <?php include 'sidebar.html'; ?>







    <header class="app-header">



        <div class="hd-eyebrow"><?php te('fc.res_materie_fc'); ?></div>



        <h1><?php te('fc.res_risultato'); ?></h1>



    </header>







    <main class="page">







        <?php $score_class = ($score >= 80) ? 's-high' : (($score >= 50) ? 's-mid' : 's-low'); ?>







        <!-- ============ HERO PUNTEGGIO ============ -->



        <section class="hero-card <?php echo sec_h($score_class); ?>">



            <div class="ring-wrap">



                <svg viewBox="0 0 120 120" aria-hidden="true">



                    <defs>



                        <linearGradient id="grad-s-high" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#66EA96"/><stop offset="1" stop-color="#1FA84C"/></linearGradient>



                        <linearGradient id="grad-s-mid" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFCB60"/><stop offset="1" stop-color="#ED8900"/></linearGradient>



                        <linearGradient id="grad-s-low" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FF8B80"/><stop offset="1" stop-color="#E12F26"/></linearGradient>



                    </defs>



                    <circle class="ring-bg" cx="60" cy="60" r="52"/>



                    <circle class="ring-fg" id="scoreRing" cx="60" cy="60" r="52" stroke="url(#grad-<?php echo sec_h($score_class); ?>)"/>



                </svg>



                <div class="ring-center">



                    <div class="score-line"><span id="scoreNum" data-score="<?php echo number_format($score, 2, '.', ''); ?>">0</span><span class="pct">%</span></div>



                    <div class="score-eyebrow"><?php te('fc.res_punteggio'); ?></div>



                </div>



            </div>









            <div class="stats-grid">



                <div class="stat ok">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo (int)$correct_count; ?>"><?php echo (int)$correct_count; ?></span></div>



                    <div class="stat-lab"><?php te('fc.js_corrette'); ?></div>



                </div>



                <div class="stat ko">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo (int)$error_count; ?>"><?php echo (int)$error_count; ?></span></div>



                    <div class="stat-lab"><?php te('fc.res_errori'); ?></div>



                </div>



                <div class="stat">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo (int)$played_items; ?>"><?php echo (int)$played_items; ?></span></div>



                    <div class="stat-lab"><?php te('fc.res_valutate'); ?></div>



                </div>



                <div class="stat pg">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo (int)$played_positions_count; ?>"><?php echo (int)$played_positions_count; ?></span><span> / <?php echo (int)$total_pages; ?></span></div>



                    <div class="stat-lab"><?php te('fc.res_pagine'); ?></div>



                </div>



                <div class="stat tm">



                    <div class="stat-num"><?php echo sec_h($durata_display); ?></div>



                    <div class="stat-lab"><?php te('fc.res_durata'); ?></div>



                </div>



            </div>







            <div class="act-grid <?php echo ($error_count > 0) ? 'cols-3' : 'cols-2'; ?>">



                <a href="history_fc.php" class="act-tile t-blue">



                    <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>



                    <span class="t-lab"><?php te('fc.js_storico'); ?></span>



                </a>



                <?php if ($error_count > 0): ?>



                    <a href="start_retry.php?parent_id=<?php echo (int)$game_id; ?>" class="act-tile t-red">



                        <span class="t-badge"><?php echo (int)$error_count; ?></span>



                        <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 2.8-6.5"/><path d="M3 4v5h5"/></svg></span>



                        <span class="t-lab"><?php te('fc.res_rigioca'); ?></span>



                    </a>



                <?php endif; ?>



                <button type="button" class="act-tile t-purple" onclick="fcvOpen(0)">



                    <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="14" height="13" rx="3"/><path d="M8.5 4H19a2 2 0 0 1 2 2v10.5"/></svg></span>



                    <span class="t-lab"><?php te('fc.flashcard'); ?></span>



                </button>



            </div>



        </section>







        <!-- ============ REVISIONE CARTE ============ -->



        <div class="sec-title"><?php te('fc.res_revisione'); ?> <span class="sec-count"><?php echo count($cards); ?></span></div>



        <div class="sec-sub"><?php te('fc.res_tocca_carta'); ?></div>







        <?php if (count($cards) === 0): ?>



            <div class="empty-card"><?php te('fc.res_nessuna_carta'); ?></div>



        <?php else: ?>







            <?php 



            $current_pos = -1;



            $child_counter = 1;







            foreach ($cards as $c): 
                $is_new_group = ($c['position'] !== $current_pos);            
                if ($is_new_group) {
                    if ($current_pos !== -1) { echo '</div>'; }
                    $current_pos = $c['position'];
                    $child_counter = 1;
                } else {
                    $child_counter++;
                }
                // Mettiamo colore e badge in base all'esito. Se vuoto = Da fare
                if ($c['user_answer'] == 'Correct') {
                    $oc_class = "ok"; $oc_icon = "\u{2713}"; $oc_text = t('fc.res_corretto');
                } elseif ($c['user_answer'] == 'Wrong') {
                    $oc_class = "ko"; $oc_icon = "\u{2715}"; $oc_text = t('fc.res_errato');
                } else {
                    $oc_class = "wait"; $oc_icon = "\u{23F3}"; $oc_text = t('fc.res_da_fare');
                }
            ?>



                <?php if ($is_new_group): ?>



                <div class="rev-card">



                    <div class="rev-head">



                        <span class="rev-head-l"><?php te('fc.res_pagina_n', ['n' => (int)$current_pos]); ?></span>



                        <a class="rev-jump" href="game_fc.php?game_id=<?php echo (int)$game_id; ?>&position=<?php echo sec_h($current_pos); ?>&status=<?php echo sec_h($is_learnt); ?>"><?php te('fc.res_rivedi'); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>



                    </div>



                <?php endif; ?>







                    <div class="rev-entry">



                        <div class="rev-top">



                            <?php if ($c['is_child']): ?>



                                <span class="numb figlio"><?php echo sec_h($child_counter); ?> &#8627;</span>



                            <?php else: ?>



                                <span class="numb padre">1</span>



                            <?php endif; ?>



                            <span class="oc-pill <?php echo sec_h($oc_class); ?>"><span class="gi"><?php echo sec_h($oc_icon); ?></span> <?php echo sec_h($oc_text); ?></span>



                        </div>







                        <div class="rev-q">



                            <?php if(!empty($c['question_image'])): ?>



                                <img src="fc_Images/<?php echo sec_h($c['question_image']); ?>" class="fc-img">



                            <?php endif; ?>



                            <div class="scroll-text">



                                <span class="raw-text" data-raw="<?php echo sec_h($c['question_desc'] ?? ''); ?>"></span>



                            </div>



                        </div>







                        <div class="rev-a">



                            <div class="rev-a-label"><?php te('fc.ifc_risposta'); ?></div>



                            <div class="rev-a-body">



                                <?php if(!empty($c['answer_image'])): ?>



                                    <img src="fc_Images/<?php echo sec_h($c['answer_image']); ?>" class="fc-img">



                                <?php endif; ?>



                                <div class="scroll-text">



                                    <span class="raw-text" data-raw="<?php echo sec_h($c['answer_desc'] ?? ''); ?>"></span>



                                </div>



                            </div>



                        </div>



                    </div>







            <?php endforeach; ?>



            <?php if ($current_pos !== -1) { echo '</div>'; } ?>







        <?php endif; ?>



    </main>







    <div class="bottom-bar">



        <?php 



        // Mostra continua partita solo se ci sono pagine da finire o domande appese



        if ($played_positions_count < $total_pages || ($row_resume && $row_resume['resume_pos'])): ?>



            <a href="game_fc.php?game_id=<?php echo (int)$game_id; ?>&position=<?php echo sec_h($resume_position); ?>&status=<?php echo sec_h($is_learnt); ?>&newsession=1" class="btn-continue"><?php te('fc.res_continua'); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>



        <?php else: ?>



            <a href="#" class="btn-continue is-done"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg> <?php te('fc.res_categoria_finita'); ?></a>



        <?php endif; ?>



    </div>







    <!-- ============ VISORE FLASHCARD ============ -->



    <!-- Filtro "solo sbagliate" e apertura del visore dalla carta in vista -->
    <div class="res-fabs">
        <button type="button" id="fabSoloErrori" class="res-fab" onclick="toggleSoloErrori()" title="<?php te('fc.res_solo_errate'); ?>" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4 2v-8Z"/></svg>
        </button>
        <button type="button" class="res-fab res-fab-primary" onclick="apriVisoreDaCartaCorrente()" title="<?php te('fc.flashcard'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="14" height="13" rx="3"/><path d="M8.5 4H19a2 2 0 0 1 2 2v10.5"/></svg>
        </button>
    </div>

    <div id="fcv" class="fcv" aria-hidden="true">



        <div class="fcv-back"></div>



        <header class="fcv-top">



            <button id="fcvClose" type="button" aria-label="<?php te('comune.chiudi'); ?>">&#10005;</button>



            <div class="fcv-count"><span id="fcvIdx">1</span> / <span id="fcvTot">1</span></div>



            <span id="fcvPill" class="oc-pill ok"></span>



        </header>



        <div class="fcv-progress"><span id="fcvBar"></span></div>



        <div class="fcv-stage" id="fcvStage">



            <div class="fcv-card" id="fcvCard">



                <div class="fcv-inner" id="fcvInner">



                    <div class="fcv-face fcv-front">



                        <div class="fcv-eyebrow"><?php te('fc.ifc_domanda'); ?></div>



                        <div class="fcv-body" id="fcvQ"></div>



                        <div class="fcv-hint"><?php te('fc.res_tocca_girare'); ?></div>



                    </div>



                    <div class="fcv-face fcv-backface">



                        <div class="fcv-eyebrow ans"><?php te('fc.ifc_risposta'); ?></div>



                        <div class="fcv-body" id="fcvA"></div>



                    </div>



                </div>



            </div>



        </div>



        <div class="fcv-nav">



            <button id="fcvPrev" class="fcv-nb" type="button" aria-label="<?php te('fc.res_precedente'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>



            <button id="fcvFlip" class="fcv-nb" type="button" aria-label="<?php te('fc.res_gira'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.3"/><path d="M21 3v5h-5"/></svg></button>



            <button id="fcvNext" class="fcv-nb" type="button" aria-label="<?php te('fc.res_successiva'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>



        </div>



    </div>







    <script>







        // ==========================================



        // MOTORE DI FORMATTAZIONE E PREVIEW (Centralizzato)



        // ==========================================







        let mathJaxTimeouts = {};



        



        // Usa direttamente fc_parser.js per l'anteprima in tempo reale



        function updatePreview(textarea, previewId) {



            const previewEl = document.getElementById(previewId);



            if (previewEl) {



                // Chiamata alla funzione del file esterno



                previewEl.innerHTML = parseTextForPreview(textarea.value);

                if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(previewEl);



                clearTimeout(mathJaxTimeouts[previewId]);



                mathJaxTimeouts[previewId] = setTimeout(() => {



                    if (typeof MathJax !== 'undefined') {



                        MathJax.typesetPromise([previewEl]).catch(err => console.log(err));



                    }



                }, 500); 



            }



        }











        // Rendiamo la funzione globale legandola a "window", così il visore delle flashcard 



        // può richiamarla in sicurezza (es. dentro la funzione ensureParsed)



        window.renderAllRawTexts = function() {



            // Controllo di sicurezza nel caso in cui il file JS esterno non fosse caricato



            if (typeof parseTextForPreview !== 'function') {



                console.error("Errore: fc_parser.js non è caricato correttamente in questa pagina!");



                return;



            }







            document.querySelectorAll('.raw-text').forEach(function(el) {



                let rawData = el.getAttribute('data-raw');



                if (rawData) {



                    // DELEGA TOTALE A fc_parser.js



                    el.innerHTML = parseTextForPreview(rawData);



                }



            });







            /* parseTextForPreview() produce il TeX come TESTO. Per vederlo
               disegnato servono i delimitatori, che li mette
               fcdDisegnaFormule(): senza questa chiamata MathJax non trova
               niente da comporre e le formule restano codice a schermo. */

            if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(document);



            // Eseguiamo il rendering delle formule matematiche sul testo appena formattato



            if (typeof MathJax !== 'undefined' && MathJax.typesetPromise) {



                MathJax.typesetPromise().catch(err => console.log(err));



            }



        };







        // Avvio immediato non appena la struttura della pagina è pronta (come in inserimento_fc)



        if (document.readyState === 'loading') {



            document.addEventListener("DOMContentLoaded", function() {



                window.renderAllRawTexts();



            });



        } else {



            window.renderAllRawTexts();



        }







        /* ================================================================



           EFFETTI RIEPILOGO — anello, count-up, stagger, coriandoli



           (solo presentazione: nessuna logica di gioco qui)



           ================================================================ */



        (function () {



            'use strict';



            var R = 2 * Math.PI * 52; // circonferenza anello







            function init() {



                var num = document.getElementById('scoreNum');



                var ring = document.getElementById('scoreRing');



                var target = num ? (parseFloat(num.getAttribute('data-score')) || 0) : 0;







                if (ring) {



                    ring.style.strokeDasharray = R;



                    ring.style.strokeDashoffset = R;



                    requestAnimationFrame(function () {



                        requestAnimationFrame(function () {



                            ring.style.transition = 'stroke-dashoffset 1.25s cubic-bezier(.32,.72,0,1)';



                            ring.style.strokeDashoffset = R * (1 - Math.min(100, target) / 100);



                        });



                    });



                }







                if (num) {



                    var dec = (target % 1 === 0) ? 0 : 2;



                    var t0 = null, D = 1150;



                    var step = function (ts) {



                        if (!t0) t0 = ts;



                        var p = Math.min(1, (ts - t0) / D);



                        var e = 1 - Math.pow(1 - p, 3);



                        num.textContent = (target * e).toFixed(dec);



                        if (p < 1) requestAnimationFrame(step);



                        else {



                            num.textContent = target.toFixed(dec);



                            try { num.parentElement.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.09)' }, { transform: 'scale(1)' }], { duration: 340, easing: 'cubic-bezier(.32,.72,0,1)' }); } catch (err) {}



                        }



                    };



                    requestAnimationFrame(step);



                }







                try {



                    document.querySelectorAll('.hero-card, .rev-card, .empty-card').forEach(function (el, i) {



                        el.animate(



                            [{ opacity: 0, transform: 'translateY(24px) scale(.985)' }, { opacity: 1, transform: 'none' }],



                            { duration: 460, delay: 60 + Math.min(i, 8) * 60, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' }



                        );



                    });



                } catch (e) {}







                if (target >= 80) setTimeout(burst, 700);







                statsFx();



            }







            function statsFx() {



                document.querySelectorAll('.stat-num .cnt').forEach(function (el, i) {



                    var v = parseFloat(el.getAttribute('data-val')) || 0;



                    setTimeout(function () {



                        var t0 = null, D = 720;



                        var step = function (ts) {



                            if (!t0) t0 = ts;



                            var p = Math.min(1, (ts - t0) / D);



                            var e2 = 1 - Math.pow(1 - p, 3);



                            el.textContent = Math.round(v * e2);



                            if (p < 1) requestAnimationFrame(step);



                            else el.textContent = Math.round(v);



                        };



                        requestAnimationFrame(step);



                    }, 260 + i * 90);



                });



                try {



                    document.querySelectorAll('.stat').forEach(function (el, i) {



                        el.animate([{ opacity: 0, transform: 'translateY(12px)' }, { opacity: 1, transform: 'none' }],



                            { duration: 420, delay: 240 + i * 70, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' });



                    });



                    document.querySelectorAll('.act-tile').forEach(function (el, i) {



                        el.animate([{ opacity: 0, transform: 'scale(.78)' }, { opacity: 1, transform: 'scale(1.045)', offset: .72 }, { opacity: 1, transform: 'scale(1)' }],



                            { duration: 460, delay: 460 + i * 85, easing: 'cubic-bezier(.24,1.1,.4,1)', fill: 'backwards' });



                    });



                } catch (e) {}



            }







            function burst() {



                var wrap = document.querySelector('.ring-wrap');



                if (!wrap) return;



                var r = wrap.getBoundingClientRect();



                var layer = document.createElement('div');



                layer.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2000;overflow:hidden;';



                document.body.appendChild(layer);



                var cols = ['#30D158', '#34C759', '#FFD60A', '#0A84FF', '#ffffff', '#A7F3C8'];



                for (var i = 0; i < 26; i++) {



                    var s = document.createElement('i');



                    s.style.cssText = 'position:absolute;display:block;width:8px;height:12px;border-radius:3px;background:' + cols[i % cols.length] + ';left:' + (r.left + r.width / 2) + 'px;top:' + (r.top + r.height * 0.22) + 'px;';



                    if (i % 3 === 0) { s.style.height = '8px'; s.style.borderRadius = '50%'; }



                    layer.appendChild(s);



                    var ang = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.5;



                    var dist = 90 + Math.random() * 160;



                    var dx = Math.cos(ang) * dist;



                    var dy = Math.sin(ang) * dist + 200 + Math.random() * 80;



                    var rot = (Math.random() - 0.5) * 720;



                    try {



                        var a = s.animate(



                            [



                                { transform: 'translate(-50%,-50%) rotate(0deg)', opacity: 1 },



                                { transform: 'translate(calc(-50% + ' + dx + 'px), calc(-50% + ' + dy + 'px)) rotate(' + rot + 'deg)', opacity: 0 }



                            ],



                            { duration: 750 + Math.random() * 400, easing: 'cubic-bezier(.16,.8,.4,1)' }



                        );



                        a.onfinish = (function (el) { return function () { el.remove(); }; })(s);



                    } catch (e) { s.remove(); }



                }



                setTimeout(function () { layer.remove(); }, 1700);



                try { if (navigator.vibrate) navigator.vibrate(12); } catch (e) {}



            }







            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);



            else init();



        })();







        /* ================================================================



           VISORE FLASHCARD — flip 3D, swipe orizzontale, trascina giù



           per chiudere, tastiera. Legge le carte già presenti in pagina.



           ================================================================ */



        (function () {



            'use strict';



            var deck = [], idx = 0, flipped = false, busy = false;



            var fcv, inner, qEl, aEl, pill, cnt, tot, bar, prevB, nextB, card;







            function els() {



                fcv = document.getElementById('fcv');



                inner = document.getElementById('fcvInner');



                qEl = document.getElementById('fcvQ');



                aEl = document.getElementById('fcvA');



                pill = document.getElementById('fcvPill');



                cnt = document.getElementById('fcvIdx');



                tot = document.getElementById('fcvTot');



                bar = document.getElementById('fcvBar');



                prevB = document.getElementById('fcvPrev');



                nextB = document.getElementById('fcvNext');



                card = document.getElementById('fcvCard');



            }







            function buzz(p) { try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) {} }







            function ensureParsed() {



                var first = document.querySelector('.rev-entry .raw-text');



                if (first && first.innerHTML === '' && first.getAttribute('data-raw')) {



                    try { renderAllRawTexts(); } catch (e) {}



                }



            }







            function buildDeck() {



                deck = [];



                document.querySelectorAll('.rev-card .rev-entry').forEach(function (en) {

                    /* Col filtro "solo sbagliate" attivo, il mazzo del visore
                       contiene solo quelle: altrimenti si filtrava l'elenco ma
                       poi le flashcard le mostravano comunque tutte. */
                    if (en.style.display === 'none') return;

                    var q = en.querySelector('.rev-q');



                    var ab = en.querySelector('.rev-a-body');



                    var oc = en.querySelector('.oc-pill');



                    deck.push({



                        q: q ? q.innerHTML : '',



                        a: ab ? ab.innerHTML : '',



                        pill: oc ? oc.outerHTML : '',



                        el: en



                    });



                });



            }







            function fill(i) {



                var d = deck[i];



                qEl.innerHTML = d.q;



                aEl.innerHTML = d.a;



                if (d.pill) {



                    pill.outerHTML = d.pill.replace('<span class="oc-pill', '<span id="fcvPill" class="oc-pill');



                    pill = document.getElementById('fcvPill');



                }



                cnt.textContent = (i + 1);



                bar.style.width = ((i + 1) / deck.length * 100) + '%';



                prevB.classList.toggle('off', i === 0);



                nextB.classList.toggle('off', i === deck.length - 1);



                qEl.scrollTop = 0; aEl.scrollTop = 0;



                try { if (window.MathJax && MathJax.typesetPromise) MathJax.typesetPromise([qEl, aEl]); } catch (e) {}



            }







            function unflip(instant) {



                flipped = false;



                if (instant) {



                    inner.style.transition = 'none';



                    inner.classList.remove('flipped');



                    void inner.offsetWidth;



                    inner.style.transition = '';



                } else {



                    inner.classList.remove('flipped');



                }



            }







            /* Apre il visore dalla carta che si sta guardando adesso nell'elenco,
               invece che sempre dalla prima: si prende la .rev-entry piu' vicina
               al centro dello schermo e si cerca la sua posizione nel mazzo. */
            window.apriVisoreDaCartaCorrente = function () {
                var entries = Array.prototype.slice.call(document.querySelectorAll('.rev-card .rev-entry'))
                    .filter(function (en) { return en.offsetParent !== null; });   // solo quelle visibili col filtro attivo
                if (!entries.length) { window.fcvOpen(0); return; }

                var centro = window.innerHeight / 2;
                var migliore = entries[0], minDist = Infinity;
                entries.forEach(function (en) {
                    var r = en.getBoundingClientRect();
                    var d = Math.abs((r.top + r.bottom) / 2 - centro);
                    if (d < minDist) { minDist = d; migliore = en; }
                });

                /* L'indice va calcolato sulle sole carte che finiranno nel mazzo
                   (le visibili), non su tutte: col filtro attivo i numeri non
                   corrisponderebbero. */
                var i = entries.indexOf(migliore);
                window.fcvOpen(i < 0 ? 0 : i);
            };

            /* Filtro "solo sbagliate": nasconde le carte corrette e le pagine che
               restano senza nessuna carta da mostrare. */
            window.toggleSoloErrori = function () {
                var btn = document.getElementById('fabSoloErrori');
                var attivo = btn.getAttribute('aria-pressed') !== 'true';
                btn.setAttribute('aria-pressed', attivo ? 'true' : 'false');
                btn.classList.toggle('is-active', attivo);

                document.querySelectorAll('.rev-card .rev-entry').forEach(function (en) {
                    var pill = en.querySelector('.oc-pill');
                    var sbagliata = pill && pill.classList.contains('ko');
                    en.style.display = (attivo && !sbagliata) ? 'none' : '';
                });

                /* Una pagina senza piu' nessuna carta visibile sparisce anche lei,
                   altrimenti resterebbero intestazioni vuote. */
                document.querySelectorAll('.rev-card').forEach(function (card) {
                    var visibili = Array.prototype.slice.call(card.querySelectorAll('.rev-entry'))
                        .filter(function (en) { return en.style.display !== 'none'; });
                    card.style.display = (attivo && visibili.length === 0) ? 'none' : '';
                });
            };

            window.fcvOpen = function (i) {



                els(); ensureParsed(); buildDeck();



                if (!deck.length) return;



                idx = Math.max(0, Math.min(deck.length - 1, i || 0));



                tot.textContent = deck.length;



                unflip(true);



                fill(idx);



                document.body.classList.add('fcv-lock');



                fcv.classList.add('on');



                fcv.setAttribute('aria-hidden', 'false');



                try {



                    card.animate([{ opacity: 0, transform: 'translateY(46px) scale(.92)' }, { opacity: 1, transform: 'none' }],



                        { duration: 430, easing: 'cubic-bezier(.24,1.2,.35,1)' });



                } catch (e) {}



                buzz(8);



            };







            /* Trascinamento verso il basso per chiudere, come nei fogli di iOS:
               il visore segue il dito, e se si e' scesi abbastanza (o si e'
               lasciato andare di slancio) si chiude, altrimenti torna su. */
            (function () {
                var y0 = 0, dy = 0, attivo = false, deciso = '', t0 = 0;

                document.addEventListener('touchstart', function (e) {
                    if (!fcv || !fcv.classList.contains('on') || e.touches.length !== 1) return;
                    /* Non si parte se il dito e' su una zona che scorre per conto
                       suo e non e' gia' in cima: li' comanda lo scorrimento. */
                    var scroller = e.target.closest ? e.target.closest('.fcv-body') : null;
                    if (scroller && scroller.scrollTop > 0) return;
                    if (e.target.closest && e.target.closest('.fcv-nb, #fcvClose')) return;
                    attivo = true; deciso = ''; dy = 0;
                    y0 = e.touches[0].clientY; t0 = Date.now();
                }, { passive: true });

                document.addEventListener('touchmove', function (e) {
                    if (!attivo) return;
                    dy = e.touches[0].clientY - y0;
                    if (!deciso) {
                        if (Math.abs(dy) < 8) return;
                        /* Solo verso il basso: verso l'alto non chiude niente. */
                        deciso = dy > 0 ? 'giu' : 'no';
                    }
                    if (deciso !== 'giu') return;
                    e.preventDefault();
                    var scala = Math.max(.88, 1 - dy / 1400);
                    fcv.style.transition = 'none';
                    fcv.style.transform = 'translateY(' + dy + 'px) scale(' + scala + ')';
                    fcv.style.opacity = String(Math.max(.35, 1 - dy / 600));
                }, { passive: false });

                function fine() {
                    if (!attivo) return;
                    attivo = false;
                    if (deciso !== 'giu') return;
                    var velocita = dy / Math.max(1, Date.now() - t0);   // px per ms
                    fcv.style.transition = 'transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease-out';
                    if (dy > 130 || velocita > 0.6) {
                        fcv.style.transform = 'translateY(100vh) scale(.92)';
                        fcv.style.opacity = '0';
                        setTimeout(function () {
                            closeV();
                            fcv.style.transition = ''; fcv.style.transform = ''; fcv.style.opacity = '';
                        }, 200);
                    } else {
                        fcv.style.transform = ''; fcv.style.opacity = '';
                    }
                    dy = 0;
                }
                document.addEventListener('touchend', fine);
                document.addEventListener('touchcancel', fine);
            })();

            function closeV() {



                fcv.classList.remove('on');



                fcv.setAttribute('aria-hidden', 'true');



                document.body.classList.remove('fcv-lock');



            }







            function flip() {



                if (busy) return;



                flipped = !flipped;



                inner.classList.toggle('flipped', flipped);



                buzz(9);



            }







            function go(dir) {



                var ni = idx + dir;



                if (busy || ni < 0 || ni >= deck.length) return;



                busy = true;



                try {



                    var out = card.animate(



                        [{ transform: 'translateX(0) rotate(0deg)', opacity: 1 }, { transform: 'translateX(' + (dir * -76) + 'px) rotate(' + (dir * -3) + 'deg)', opacity: 0 }],



                        { duration: 185, easing: 'ease-in', fill: 'forwards' }



                    );



                    out.onfinish = function () {



                        idx = ni; unflip(true); fill(idx);



                        var inn = card.animate(



                            [{ transform: 'translateX(' + (dir * 76) + 'px) rotate(' + (dir * 3) + 'deg)', opacity: 0 }, { transform: 'none', opacity: 1 }],



                            { duration: 300, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'both' }



                        );



                        out.cancel();



                        inn.onfinish = function () { inn.cancel(); busy = false; };



                        buzz(7);



                    };



                } catch (e) {



                    idx = ni; unflip(true); fill(idx); busy = false;



                }



            }







            /* click: apertura da lista, controlli, flip sul tocco della carta */



            document.addEventListener('click', function (e) {



                if (!e.target || !e.target.closest) return;



                if (e.target.closest('#fcvClose')) { closeV(); return; }



                if (e.target.closest('#fcvPrev')) { go(-1); return; }



                if (e.target.closest('#fcvNext')) { go(1); return; }



                if (e.target.closest('#fcvFlip')) { flip(); return; }



                if (fcv && fcv.classList.contains('on')) {



                    if (e.target.closest('#fcvCard')) {



                        if (!e.target.closest('a, button, audio')) flip();



                        return;



                    }



                    if (e.target.closest('.fcv-back')) { closeV(); return; }



                    return;



                }



                var en = e.target.closest('.rev-entry');



                if (en && !e.target.closest('a')) {



                    els(); ensureParsed(); buildDeck();



                    var i = -1;



                    for (var k = 0; k < deck.length; k++) { if (deck[k].el === en) { i = k; break; } }



                    window.fcvOpen(i < 0 ? 0 : i);



                }



            });







            /* gesture: swipe orizzontale per navigare, trascina giù per chiudere */



            var tg = null;



            document.addEventListener('touchstart', function (e) {



                if (!fcv || !fcv.classList.contains('on')) return;



                if (!e.target.closest || !e.target.closest('#fcvCard')) return;



                var t = e.touches[0];



                var body = e.target.closest('.fcv-body');



                tg = { x: t.clientX, y: t.clientY, dx: 0, dy: 0, mode: null, atTop: !body || body.scrollTop <= 0 };



            }, { passive: true });







            document.addEventListener('touchmove', function (e) {



                if (!tg) return;



                var t = e.touches[0];



                tg.dx = t.clientX - tg.x;



                tg.dy = t.clientY - tg.y;



                if (!tg.mode) {



                    if (Math.abs(tg.dx) > 10 && Math.abs(tg.dx) > Math.abs(tg.dy) * 1.2) tg.mode = 'h';



                    else if (tg.dy > 14 && tg.dy > Math.abs(tg.dx) && tg.atTop) tg.mode = 'v';



                    else if (Math.abs(tg.dy) > 14) { tg = null; return; }



                    else return;



                }



                e.preventDefault();



                if (tg.mode === 'h') {



                    card.style.transform = 'translateX(' + tg.dx + 'px) rotate(' + (tg.dx * 0.03) + 'deg)';



                } else {



                    var k = Math.max(0, tg.dy);



                    card.style.transform = 'translateY(' + (k * 0.55) + 'px) scale(' + (1 - Math.min(.06, k / 900)) + ')';



                    var bk = fcv.querySelector('.fcv-back');



                    if (bk) bk.style.opacity = Math.max(.35, 1 - k / 380);



                }



            }, { passive: false });







            document.addEventListener('touchend', function () {



                if (!tg) return;



                var g = tg; tg = null;



                var bk = fcv ? fcv.querySelector('.fcv-back') : null;



                var reset = function () {



                    card.style.transition = 'transform .42s cubic-bezier(.24,1.2,.35,1)';



                    card.style.transform = '';



                    if (bk) bk.style.opacity = '';



                    setTimeout(function () { card.style.transition = ''; }, 440);



                };



                if (g.mode === 'h' && Math.abs(g.dx) > 70) {



                    var dir = g.dx < 0 ? 1 : -1;



                    if ((dir === 1 && idx < deck.length - 1) || (dir === -1 && idx > 0)) {



                        card.style.transform = ''; go(dir); return;



                    }



                    reset();



                } else if (g.mode === 'v' && g.dy > 110) {



                    card.style.transition = 'transform .26s ease-in';



                    card.style.transform = 'translateY(70vh) scale(.9)';



                    buzz(8);



                    setTimeout(function () {



                        closeV();



                        card.style.transition = ''; card.style.transform = '';



                        if (bk) bk.style.opacity = '';



                    }, 240);



                } else if (g.mode) {



                    reset();



                }



            });







            /* tastiera */



            document.addEventListener('keydown', function (e) {



                if (!fcv || !fcv.classList.contains('on')) return;



                if (e.key === 'Escape') closeV();



                else if (e.key === 'ArrowRight') go(1);



                else if (e.key === 'ArrowLeft') go(-1);



                else if (e.key === ' ' || e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); flip(); }



            });







            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', els);



            else els();



        })();



    </script>



</body>



</html>

