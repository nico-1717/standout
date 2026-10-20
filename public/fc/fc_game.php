<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php'; // 1. Protezione con login obbligatorio
require_once __DIR__ . '/../../lib/piano_helpers.php';

/* Oltre questa pausa non si sta piu' giocando: il tempo non viene contato, e
   riaprendo la partita si apre una sessione nuova invece di continuare quella
   vecchia (era il motivo per cui una partita di 5 minuti ripresa il giorno
   dopo ne segnava molti di piu').

   Deve stare QUI, in cima: la usa anche il gestore del voto, molto prima del
   blocco del cronometro dove l'avevo messa - e una costante non ancora
   definita, in PHP 8, e' un errore fatale. Era questo il 500 che faceva
   finire ogni voto nella coda "in attesa di connessione", pur essendo il
   voto gia' stato salvato. */
define('STANDOUT_PAUSA_MAX', 300);   // 5 minuti

$current_user_id = (int)$utente_corrente['id']; // 2. ID utente sicuro
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();
require_once __DIR__ . '/fc_examples_box.php';
require_once __DIR__ . '/../../lib/fc/fc_vocab_gap.php';
require_once __DIR__ . '/../../lib/fc/fc_vocab_gioco.php';

/**
 * Vero se il campo (answer_desc, question_desc...) non contiene testo o
 * formule vere — solo struttura vuota. Serve perche' il campo non e' mai una
 * stringa semplice: e' il documento di fc_parser.js, a blocchi con run di
 * testo o di formula, e un documento "vuoto" resta comunque un JSON non
 * vuoto (es. un paragrafo senza testo dentro).
 */
function fc_campo_e_vuoto(?string $raw): bool {
    if ($raw === null) return true;
    $raw = trim($raw);
    if ($raw === '') return true;

    // Formato nuovo (JSON a blocchi): si guarda dentro ogni blocco, non la
    // stringa nel suo insieme.
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
        foreach ($decoded['blocks'] as $block) {
            if (!is_array($block)) continue;
            if (($block['t'] ?? '') === 'fx') {                      // formula a blocco intero
                if (trim((string)($block['tex'] ?? '')) !== '') return false;
                continue;
            }
            foreach ((array)($block['runs'] ?? []) as $run) {
                if (!is_array($run)) continue;
                if (isset($run['x']) && trim((string)$run['x']) !== '') return false;  // formula in linea
                if (isset($run['s']) && trim((string)$run['s']) !== '') return false;  // testo
            }
        }
        return true; // JSON valido ma ogni blocco e' vuoto
    }

    // Non era JSON: e' il vecchio formato a tag (/bold{...} ecc.) o testo
    // semplice. Si tolgono i tag noti e si guarda se resta qualcosa.
    $senza_tag = preg_replace('/\/[a-zA-Z]+(-[a-zA-Z]+)?\{|\}/', '', $raw);
    return trim($senza_tag) === '';
}

/**
 * Prime parole di un campo testo, in chiaro: serve per elenchi e riepiloghi.
 * Il campo non e' testo semplice ma il documento di fc_parser.js, quindi si
 * estraggono i pezzi di testo dei blocchi invece di stampare il JSON.
 */
function fc_anteprima_testo(?string $raw, int $max = 70): string {
    if ($raw === null || trim($raw) === '') return '';

    $pezzi = [];
    $decoded = json_decode($raw, true);

    if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
        foreach ($decoded['blocks'] as $block) {
            if (!is_array($block)) continue;
            if (($block['t'] ?? '') === 'fx') { $pezzi[] = '[formula]'; continue; }
            foreach ((array)($block['runs'] ?? []) as $run) {
                if (!is_array($run)) continue;
                if (isset($run['s']) && $run['s'] !== '') $pezzi[] = (string)$run['s'];
                elseif (isset($run['x']) && $run['x'] !== '') $pezzi[] = '[formula]';
            }
        }
    } else {
        /* Vecchio formato a tag: si tolgono i marcatori noti. */
        $pezzi[] = preg_replace('/\/[a-zA-Z]+(-[a-zA-Z]+)?\{|\}/', '', $raw);
    }

    $testo = trim(preg_replace('/\s+/u', ' ', implode(' ', $pezzi)));
    if ($testo === '') return '';

    return mb_strlen($testo, 'UTF-8') > $max
        ? mb_substr($testo, 0, $max - 1, 'UTF-8') . '…'
        : $testo;
}

/** Vero se questa riga di fc_study_cards va trattata come titolo, non come domanda. */
function fc_e_titolo(array $padre, int $numero_figli): bool {
    return $numero_figli >= 2
        && fc_campo_e_vuoto($padre['answer_desc'] ?? null)
        && empty($padre['answer_image'])
        && empty($padre['answer_audio']);
}

// Stima tempo/lentezza IA per "spiega" e "metafora", da mostrare col timer nel popup
// prima ancora di partire con la chiamata (vedi window.aiPerfStats più sotto).
$ai_perf_stats_js = [
    'explain'  => standout_get_stima_tempo_ia('explain'),
    'metaphor' => standout_get_stima_tempo_ia('metaphor'),
    'example'  => standout_get_stima_tempo_ia('example'),
    'shatter'  => standout_get_stima_tempo_ia('shatter'),
];

$game_id = sec_int($_GET['game_id'] ?? 0, 1);
$position = sec_int($_GET['position'] ?? 1, 1);
$status = sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0'); 

// 1. Info di base sulla partita
$game_info = sec_one("SELECT * FROM fc_games WHERE id = ?", [$game_id]);
// --- SICUREZZA: la partita dev'essere sua ---
if (!$game_info || (int)$game_info['user_id'] !== $current_user_id) {
    die(sec_h(t('fc.game_non_tua')));
}
// --- FINE SICUREZZA ---

/* Studio o lingua: lo dice la partita (fc_games.kind), non l'indirizzo.
   Per le lingue la carta si mostra secondo il verso scelto all'avvio
   (fc_verso_da_tipo / fc_gira_carta in fc_type.php): parola davanti e
   traduzione dietro, o il contrario. Il resto del gioco — posizioni,
   figli, voti, barra, coda offline — e' lo stesso per i due tipi. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia_da_gioco($game_info);
$fc_vocab = fc_is_vocab();
$fc_verso = $fc_vocab ? fc_verso_da_tipo((string)$game_info['type']) : 'w2t';
$fc_qs_corso = $fc_vocab ? '&course=' . fc_corso_id() : '';

/**
 * Il testo di un lato della carta. Studio: il documento di fc_parser, che il
 * browser disegna da data-raw. Lingua: testo semplice gia' scritto, con il
 * tasto per ascoltarlo nella sua lingua e, dietro, il significato. data-raw
 * resta anche qui: la lettura ad alta voce e gli esempi lo leggono da li'.
 */
function fc_gioco_testo(array $c, string $lato, string $classe = 'fc-text raw-text'): void {
    $raw = trim((string)($c[$lato === 'q' ? 'question_desc' : 'answer_desc'] ?? ''));
    if (!fc_is_vocab()) {
        if ($raw !== '') echo '<div class="' . $classe . '" data-raw="' . sec_h($raw) . '"></div>';
        return;
    }
    $parola = ($c['fc_lato_parola'] ?? 'q') === $lato;
    $chi = $parola ? 'learn' : 'known';
    $lingua = $parola ? fc_corso()['learn_lang'] : fc_corso()['known_lang'];
    if ($raw !== '') {
        echo '<div class="fcv-game-line"><div class="' . $classe . ' fcv-game-text' . ($parola ? ' is-word' : '') . '" lang="' . sec_h($lingua) . '"'
           . ' data-raw="' . sec_h($raw) . '" data-lato="' . $chi . '">' . sec_h($raw) . '</div>'
           . '<button type="button" class="fcv-speak" data-speak="' . $chi . '" data-text="' . sec_h($raw) . '"'
           . ' aria-label="' . sec_h(t('fcv.ascolta')) . '" title="' . sec_h(t('fcv.ascolta')) . '">'
           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.8 5.2a9.5 9.5 0 0 1 0 13.6"/></svg>'
           . '</button></div>';
    }
    if ($lato === 'a' && trim((string)($c['meaning'] ?? '')) !== '') {
        echo '<div class="fcv-meaning" lang="' . sec_h(fc_corso()['learn_lang']) . '">' . sec_h(trim((string)$c['meaning'])) . '</div>';
    }
}

/**
 * GLI INDIZI DEL DAVANTI (solo lingue): il significato e un esempio.
 * Stanno coperti da due etichette, «Significato» ed «Esempio»: toccandole
 * l'etichetta sparisce e compare il testo. Così si prova prima a ricordare da
 * soli, e l'aiuto c'è quando serve. Dalle opzioni della partita si può
 * scegliere di vederli già scoperti.
 * Nell'esempio la parola è uno spazio vuoto, che si riempie quando si vede la
 * risposta. Quale parte sia lo decide fcv_lacuna() (lib/fc/fc_vocab_gap.php):
 * quella scelta nel modulo, o la parola se c'è scritta uguale.
 * Come si vedono (subito / al tocco / con la risposta) lo decidono le classi
 * fcv-sig-* e fcv-es-* su <html>, scelte nelle impostazioni del gioco.
 */
function fc_gioco_indizi(array $c): void {
    if (!fc_is_vocab()) return;
    $lato = $c['fc_lato_parola'] ?? 'q';
    $parola = trim((string)($c[$lato === 'q' ? 'question_desc' : 'answer_desc'] ?? ''));
    $significato = trim((string)($c['meaning'] ?? ''));
    $esempio = trim((string)($c['fcv_esempio'] ?? ''));
    if ($significato === '' && $esempio === '') return;
    /* Nell'esempio la parola e' sempre uno spazio vuoto, qualunque sia il
       verso: si riempie quando si vede la risposta (fcv_lacuna_html). */
    $esHtml = $esempio !== ''
        ? fcv_lacuna_html($esempio, $parola, $c['fcv_esempio_gap'] ?? '')
        : '';
    $lingua = sec_h(fc_corso()['learn_lang']);
    echo '<div class="fcv-hints">';
    foreach ([['significato', $significato !== '' ? sec_h($significato) : ''], ['esempio', $esHtml]] as [$k, $html]) {
        if ($html === '') continue;
        echo '<button type="button" class="fcv-hint fcv-hint-' . $k . '" onclick="event.stopPropagation(); this.classList.toggle(\'is-open\')"'
           . ' aria-label="' . sec_h(t('fcv.indizio_' . $k)) . '">'
           . '<span class="fcv-hint-lab">' . sec_h(t('fcv.indizio_' . $k)) . '</span>'
           . '<span class="fcv-hint-txt" lang="' . $lingua . '">' . $html . '</span>'
           . '</button>';
    }
    echo '</div>';
}

/** L'etichetta di un lato: "Domanda"/"Risposta", o la lingua con la sua bandiera. */
function fc_gioco_etichetta(array $c, string $lato): string {
    if (!fc_is_vocab()) return sec_h(t($lato === 'q' ? 'fc.ifc_domanda' : 'fc.ifc_risposta'));
    $parola = ($c['fc_lato_parola'] ?? 'q') === $lato;
    $codice = $parola ? fc_corso()['learn_lang'] : fc_corso()['known_lang'];
    return '<img class="fcv-flag" src="' . sec_h(fc_lingua_bandiera($codice)) . '" alt=""> ' . sec_h(fc_lingua_nome($codice, false));
}

$parent_id = isset($game_info['parent_id']) ? intval($game_info['parent_id']) : 0;

// 3. Forziamo l'uso dell'ID sicuro per il resto dello script
$user_id = $current_user_id;

require_once __DIR__ . '/post_action.php';

/* LE AZIONI CHE SCRIVONO ARRIVANO SOLO IN POST.
   Prima erano link (?action=grade, ?action=concludi, ?newsession=1) e su GET
   sec_require_csrf() esce subito: il token non proteggeva niente, e bastava un
   tag immagine su un altro sito per far chiudere una partita.
   I parametri di navigazione - game_id, position, status - restano in GET:
   quelli non scrivono, e devono stare nella barra dell'indirizzo. */
$azione = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = sec_enum($_POST['action'] ?? null, ['grade', 'concludi', 'newsession', 'setorder', 'togglelearnt', 'togglefavorite'], '');
    if ($azione !== '') {
        /* Ora il metodo e' POST, quindi sec_guard_write controlla davvero il
           token oltre a limitare la frequenza. */
        sec_guard_write($current_user_id, 'fc_game', 0, 300, 60);
    }
}



// --- CRONOMETRO: nuova sessione quando si riprende la partita da "Continua partita" (fc_result.php) ---
// (fc_game_start.php e fc_game_retry.php creano già la sessione principale quando la partita nasce)
if ($azione === 'newsession' && $game_id > 0 && $game_info) {
    sec_q("INSERT INTO fc_game_durations (game_id, start_time, duration_seconds, updated_at) VALUES (?, NOW(), 0, NOW())", [$game_id]);
    header("Location: fc_game.php?game_id=$game_id&position=$position&status=$status");
    exit();
}



// --- GESTIONE ORDINE DAL DATABASE ---

if ($azione === 'setorder' || isset($_GET['order'])) {
    /* INIEZIONE SQL: prima $_GET['order'] finiva dentro apici in questa UPDATE
       senza validazione ne' escape. Un apice nell'indirizzo chiudeva la stringa
       e da li' si poteva scrivere SQL a piacere. Ora e' un elenco chiuso di due
       valori e viaggia come parametro.
       Il valore si legge dal POST se l'azione e' arrivata da li', altrimenti
       dalla querystring - ma in quel caso NON si scrive: l'ordine in GET serve
       solo a sapere come mostrare le carte, non a cambiare il database. */
    $order_param = ($azione === 'setorder')
        ? sec_enum($_POST['order'] ?? null, ['in_order', 'random'], 'random')
        : sec_enum($_GET['order'] ?? null, ['in_order', 'random'], 'random');
    if ($azione === 'setorder') {
        sec_q("UPDATE fc_games SET game_order = ? WHERE id = ? AND user_id = ?",
              [$order_param, $game_id, $current_user_id]);
    }
} else {

    // Se non c'è nell'URL (es. tasto "Continua Partita"), lo peschiamo dal database

    $order_param = isset($game_info['game_order']) ? $game_info['game_order'] : 'random';

}



// =========================================================================

// FIX SICUREZZA E BUG FIGLI: Gestione chiusura partita e pulizia intelligente

// =========================================================================

if ($azione === 'concludi') {

        $empty_positions = [];
    foreach (sec_all("SELECT position FROM " . fc_t('plays') . " WHERE game_id = ? GROUP BY position
                      HAVING SUM(CASE WHEN user_answer != '' THEN 1 ELSE 0 END) = 0", [$game_id]) as $row) {
        $empty_positions[] = (int)$row['position'];
    }
    if (!empty($empty_positions)) {
        $pos_ph = implode(',', array_fill(0, count($empty_positions), '?'));
        sec_q("DELETE FROM " . fc_t('plays') . " WHERE game_id = ? AND position IN ($pos_ph)",
              array_merge([$game_id], $empty_positions));
    }

    

    header("Location: fc_result.php?game_id=$game_id");

    exit();

}



$row_unanswered = sec_one("SELECT MIN(position) AS min_p FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer = ''", [$game_id]);
$first_unanswered = $row_unanswered ? $row_unanswered['min_p'] : null;



/* Chiedendo una posizione piu' avanti della prima ancora da rispondere si
   viene rimandati indietro a quella: e' giusto per una visita vera, ma NON
   per un precaricamento - li' si vuole proprio vedere cosa c'e' a quella
   posizione, e il rimando faceva arrivare all'anteprima la pagina corrente
   invece di quella successiva (da cui "sotto compare se stessa" o "il suo
   stesso padre"). Con questo parametro il precaricamento chiede la pagina
   cosi' com'e', senza rimandi. */
$solo_anteprima = sec_bool($_GET['prefetch'] ?? false);

/* Il rimando NON deve mai scattare per una richiesta che sta registrando un
   voto: verrebbe eseguito prima del gestore piu' sotto, e quel voto andrebbe
   perso - la pagina tornerebbe indietro e bisognerebbe premere Avanti a mano,
   che e' esattamente il sintomo osservato. Un voto si registra e basta, poi
   sara' il suo gestore a dire dove andare. */
$sta_votando = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== '');

if (!$solo_anteprima && !$sta_votando && $first_unanswered && $first_unanswered < $position) {

    header("Location: fc_game.php?game_id=$game_id&position=$first_unanswered&status=$status&order=$order_param");

    exit();

}

// =========================================================================



$cat_ids = [];
foreach (sec_all("SELECT fc_category_id FROM " . fc_t('game_decks') . " WHERE game_id = ?", [$game_id]) as $c_row) {
    if ((int)$c_row['fc_category_id'] > 0) { $cat_ids[] = (int)$c_row['fc_category_id']; }
}
/* $cat_filter e $where_learnt sono pezzi di SQL riusati da piu' query: portano
   con se' i propri segnaposto, e i valori stanno in $cat_par e $learnt_par.
   Vanno passati nell'ordine in cui i pezzi compaiono nella query. */
$cat_filter = '';
$cat_par = [];
if (count($cat_ids) > 0) {
    $cat_filter = "AND category_id IN (" . implode(',', array_fill(0, count($cat_ids), '?')) . ")";
    $cat_par = $cat_ids;
}
$learnt_par = [];
if ($status === 'all') {
    $where_learnt = "1=1";
} elseif ($status === '1') {
    $where_learnt = "id IN (SELECT fc_image_id FROM " . fc_t('learnt') . " WHERE user_id = ?)";
    $learnt_par = [$user_id];
} else {
    $where_learnt = "id NOT IN (SELECT fc_image_id FROM " . fc_t('learnt') . " WHERE user_id = ?)";
    $learnt_par = [$user_id];
}



// 2. Gestione dell'azione di Grading (Sbagliato / Corretto / Imparato)

if ($azione === 'grade') {
    $esito = sec_enum($_POST['result'] ?? null, ['correct', 'learnt', 'wrong'], 'wrong');
    $result = ($esito === 'correct' || $esito === 'learnt') ? 'Correct' : 'Wrong';
    $id_to_mark = sec_int($_POST['img_id'] ?? 0, 1);
    $is_child_vote = sec_bool($_POST['is_child'] ?? false);

    if ($esito === 'learnt') {
        $parent_to_mark = $id_to_mark;
        if ($is_child_vote) {
            $c_row = sec_one("SELECT parent_id FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id_to_mark]);
            if ($c_row) { $parent_to_mark = (int)$c_row['parent_id']; }
        }
        /* La carta dev'essere sua: senza questo controllo si poteva riempire il
           proprio storico "imparate" con le carte di un altro. */
        if ($parent_to_mark > 0
            && sec_one("SELECT i.id FROM " . fc_t('cards') . " i JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                        WHERE i.id = ? AND c.user_id = ?", [$parent_to_mark, $user_id])) {
            sec_q("INSERT IGNORE INTO " . fc_t('learnt') . " (user_id, fc_image_id, learnt_date) VALUES (?, ?, CURDATE())",
                  [$user_id, $parent_to_mark]);
        }
    }

    /* La clausola porta sempre game_id, e la partita e' gia' stata verificata
       come sua in cima al file: una riga di un'altra partita non si tocca. */
    if (!$is_child_vote) {
        sec_q("UPDATE " . fc_t('plays') . " SET user_answer = ?, play_date = NOW()
                WHERE game_id = ? AND position = ? AND fc_image_id = ?",
              [$result, $game_id, $position, $id_to_mark]);
    } else {
        sec_q("UPDATE " . fc_t('plays') . " SET user_answer = ?, play_date = NOW()
                WHERE game_id = ? AND position = ? AND fc_child_id = ?",
              [$result, $game_id, $position, $id_to_mark]);
    }
    /* CRONOMETRO: si aggiunge il tempo passato DALL'ULTIMA attivita', non
       quello dall'inizio della sessione. Prima era TIMESTAMPDIFF(start_time,
       NOW()): riprendendo una partita ore dopo, quelle ore finivano tutte
       nel conteggio anche se non si stava giocando. Con LEAST() una pausa
       piu' lunga della soglia conta come la soglia, cosi' nemmeno una pausa
       dentro la stessa sessione gonfia il totale. */
    sec_q("UPDATE fc_game_durations
              SET duration_seconds = duration_seconds + LEAST(TIMESTAMPDIFF(SECOND, updated_at, NOW()), " . STANDOUT_PAUSA_MAX . "),
                  updated_at = NOW()
            WHERE game_id = ? ORDER BY id DESC LIMIT 1", [$game_id]);
    $unanswered = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('plays') . " WHERE game_id = ? AND position = ? AND user_answer = ''",
                                [$game_id, $position])['c'] ?? 0);



    if ($unanswered == 0) {

        /* Se questa posizione era un ripasso (stava DIETRO al fronte gia'
           raggiunto altrove), si torna dritti al fronte invece di continuare
           ad avanzare una posizione alla volta fra pagine gia' fatte: prima,
           corretto un errore alla 7 con 40 gia' raggiunta, si finiva alla 8 e
           bisognava premere "avanti" altre 32 volte per tornare dove si era.
           Stessa definizione di "risposta vera" usata ovunque nel file: non
           conta ne' una riga vuota ne' una carta titolo, altrimenti il fronte
           calcolato qui non coinciderebbe con $furthest_page piu' sotto. */
        $row_fronte = sec_one(
            "SELECT MAX(position) AS p FROM " . fc_t('plays') . "
              WHERE game_id = ? AND user_answer <> '' AND user_answer <> 'Titolo'",
            [$game_id]
        );
        $fronte = ($row_fronte && $row_fronte['p']) ? (int)$row_fronte['p'] : $position;
        $prossima_pos = ($position < $fronte) ? $fronte : ($position + 1);

        header("Location: fc_game.php?game_id=$game_id&position=$prossima_pos&status=$status&order=$order_param");

    } else {

        header("Location: fc_game.php?game_id=$game_id&position=$position&status=$status&order=$order_param");

    }

    exit();

}



if ($azione === 'togglelearnt') {
    header('Content-Type: application/json');

    $id_to_mark = sec_int($_POST['img_id'] ?? 0, 1);
    $is_child_vote = sec_bool($_POST['is_child'] ?? false);
    $parent_to_mark = $id_to_mark;
    if ($is_child_vote) {
        $c_row = sec_one("SELECT parent_id FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id_to_mark]);
        if ($c_row) { $parent_to_mark = (int)$c_row['parent_id']; }
    }

    /* La carta dev'essere sua: stesso controllo che gia' c'era per il voto
       "learnt", solo spostato qui perche' ora e' un'azione a se'. */
    $mia = $parent_to_mark > 0 && sec_one("SELECT i.id FROM " . fc_t('cards') . " i JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                                          WHERE i.id = ? AND c.user_id = ?", [$parent_to_mark, $user_id]);
    if (!$mia) {
        echo json_encode(['success' => false, 'error' => t('fc.non_autorizzato_scheda')]);
        exit();
    }

    $gia_imparata = sec_one("SELECT 1 AS x FROM " . fc_t('learnt') . " WHERE user_id = ? AND fc_image_id = ?", [$user_id, $parent_to_mark]);

    if ($gia_imparata) {
        sec_q("DELETE FROM " . fc_t('learnt') . " WHERE user_id = ? AND fc_image_id = ?", [$user_id, $parent_to_mark]);
        echo json_encode(['success' => true, 'learnt' => false]);
    } else {
        sec_q("INSERT IGNORE INTO " . fc_t('learnt') . " (user_id, fc_image_id, learnt_date) VALUES (?, ?, CURDATE())", [$user_id, $parent_to_mark]);
        echo json_encode(['success' => true, 'learnt' => true]);
    }
    exit();
}

if ($azione === 'togglefavorite') {
    header('Content-Type: application/json');

    $id_to_mark = sec_int($_POST['img_id'] ?? 0, 1);
    $is_child_vote = sec_bool($_POST['is_child'] ?? false);
    $parent_to_mark = $id_to_mark;
    if ($is_child_vote) {
        $c_row = sec_one("SELECT parent_id FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id_to_mark]);
        if ($c_row) { $parent_to_mark = (int)$c_row['parent_id']; }
    }

    $mia = $parent_to_mark > 0 && sec_one("SELECT i.id FROM " . fc_t('cards') . " i JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                                          WHERE i.id = ? AND c.user_id = ?", [$parent_to_mark, $user_id]);
    if (!$mia) {
        echo json_encode(['success' => false, 'error' => t('fc.non_autorizzato_scheda')]);
        exit();
    }

    /* A differenza di "imparato" (che vale sempre per l'intera scheda, quindi
       risale al padre), la tabella dei preferiti ha due colonne: si puo'
       mettere fra i preferiti anche un singolo figlio. Quindi qui NON si
       risale: si segna esattamente la carta toccata. */
    $col = $is_child_vote ? 'fc_child_id' : 'fc_image_id';
    $gia_preferita = sec_one("SELECT 1 AS x FROM " . fc_t('favorites') . " WHERE user_id = ? AND $col = ?", [$user_id, $id_to_mark]);

    if ($gia_preferita) {
        sec_q("DELETE FROM " . fc_t('favorites') . " WHERE user_id = ? AND $col = ?", [$user_id, $id_to_mark]);
        echo json_encode(['success' => true, 'favorite' => false]);
    } else {
        sec_q("INSERT IGNORE INTO " . fc_t('favorites') . " (user_id, $col, favorited_date) VALUES (?, ?, CURDATE())", [$user_id, $id_to_mark]);
        echo json_encode(['success' => true, 'favorite' => true]);
    }
    exit();
}



// --- CRONOMETRO: tempo EFFETTIVAMENTE giocato (le pause lunghe non contano) ---
$chrono_base_seconds = 0;

$chrono_start_ts = 0;

$chrono_last = sec_one("SELECT id, duration_seconds, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS fermo_da
                        FROM fc_game_durations WHERE game_id = ? ORDER BY id DESC LIMIT 1", [$game_id]);

if ($chrono_last) {
    $fermo_da = (int)$chrono_last['fermo_da'];

    if ($fermo_da > STANDOUT_PAUSA_MAX) {
        /* Ferma da troppo: quella sessione e' finita quando si e' smesso di
           giocare, non adesso. Si lascia il suo totale com'e' (non si aggiunge
           la pausa) e se ne apre una nuova che parte da zero. */
        sec_q("INSERT INTO fc_game_durations (game_id, start_time, duration_seconds, updated_at)
               VALUES (?, NOW(), 0, NOW())", [$game_id]);
    } else {
        /* Pausa breve: e' tempo di gioco, si aggiunge alla sessione corrente. */
        sec_q("UPDATE fc_game_durations
                  SET duration_seconds = duration_seconds + LEAST(TIMESTAMPDIFF(SECOND, updated_at, NOW()), " . STANDOUT_PAUSA_MAX . "),
                      updated_at = NOW()
                WHERE id = ?", [(int)$chrono_last['id']]);
    }

    /* Il totale e' la somma di quanto EFFETTIVAMENTE giocato in tutte le
       sessioni; il contatore che scorre in pagina riparte da li'. */
    $chrono_base_seconds = (int)(sec_one("SELECT COALESCE(SUM(duration_seconds),0) AS tot
                                          FROM fc_game_durations WHERE game_id = ?", [$game_id])['tot'] ?? 0);
    $chrono_start_ts = time();
}



// 3. Recupero o Generazione delle carte per questa Pagina (Position)

$gameplays = sec_all("SELECT g.* FROM " . fc_t('plays') . " g
                      LEFT JOIN " . fc_t('subcards') . " ch ON ch.id_child = g.fc_child_id
                      WHERE g.game_id = ? AND g.position = ?
                      /* Il padre (fc_child_id NULL) sempre per primo, poi i figli nel
                         loro ordine: senza ORDER BY l'ordine lo decideva il database,
                         ed e' cosi' che il concetto principale finiva in fondo. */
                      ORDER BY (g.fc_child_id IS NOT NULL) ASC, ch.custom_order ASC, g.id ASC",
                     [$game_id, $position]);

if (empty($gameplays)) {

    /* La sequenza della partita e' gia' stata decisa tutta insieme
       all'avvio (fc_game_start.php, o fc_game_retry.php per un ripasso: la
       lista delle carte sbagliate nella partita di origine e' fissa da
       quando il ripasso e' stato creato, non cambia durante il gioco -
       non c'e' motivo per cui debba essere trattata diversamente da una
       partita normale). Qui c'e' solo da leggere quale padre tocca a
       questa posizione e scriverlo - un'operazione che il precaricamento
       e la navigazione vera possono benissimo fare insieme: scrivono la
       STESSA riga (la sequenza e' fissa), quindi il vincolo di unicita'
       su fc_study_plays lascia passare solo la prima e scarta la seconda
       (INSERT IGNORE) invece di creare un doppione. Nessun lucchetto
       necessario. */
    $seq = sec_one("SELECT fc_image_id FROM " . fc_t('sequence') . " WHERE game_id = ? AND position = ?", [$game_id, $position]);

    if (!$seq) {
        header("Location: fc_result.php?game_id=$game_id");
        exit();
    }

    $img_id = (int)$seq['fc_image_id'];
    $img_row = sec_one("SELECT " . fc_colonne_carta() . " FROM " . fc_t('cards') . " WHERE id = ?", [$img_id]);
    $verso_pos = fc_verso_posizione($fc_verso, $game_id, $position);
    if ($img_row) $img_row = fc_gira_carta($img_row, $verso_pos);

    if ($img_row) {
        $n_figli = (int)((int)$img_row['is_father'] === 1
            ? (sec_one("SELECT COUNT(*) AS c FROM " . fc_t('subcards') . " WHERE parent_id = ?", [$img_id])['c'] ?? 0)
            : 0);
        $e_titolo = (int)$img_row['is_father'] === 1 && fc_e_titolo($img_row, $n_figli);

        $correct_ans = $e_titolo ? '' : mb_substr((string)$img_row['answer_desc'], 0, 100, 'UTF-8');

        /* MEMORIA ERRORI, solo per un ripasso: se questo padre risulta gia'
           corretto altrove nella storia della partita di origine (puo'
           capitare se una carta compare a piu' posizioni), si parte gia'
           segnati cosi' invece che vuoti. Per una partita normale $parent_id
           e' sempre 0 e questo blocco non fa nulla. */
        $user_answer_iniziale = $e_titolo ? 'Titolo' : '';
        if (!$e_titolo && $parent_id > 0) {
            $row_prev = sec_one("SELECT user_answer FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id = ?", [$parent_id, $img_id]);
            if ($row_prev && $row_prev['user_answer'] == 'Correct') $user_answer_iniziale = 'Correct';
        }

        sec_q("INSERT IGNORE INTO " . fc_t('plays') . " (game_id, fc_image_id, correct_answer, user_answer, position)
               VALUES (?, ?, ?, ?, ?)", [$game_id, $img_id, $correct_ans, $user_answer_iniziale, $position]);

        if ((int)$img_row['is_father'] === 1) {
            foreach (sec_all("SELECT " . fc_colonne_figlia() . " FROM " . fc_t('subcards') . " WHERE parent_id = ? ORDER BY custom_order ASC", [$img_id]) as $child) {
                $child = fc_gira_carta($child, $verso_pos);
                $c_id = (int)$child['id_child'];
                $c_ans = mb_substr((string)$child['answer_desc'], 0, 100, 'UTF-8');

                $child_user_answer_iniziale = '';
                if ($parent_id > 0) {
                    $row_prev_c = sec_one("SELECT user_answer FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_child_id = ?", [$parent_id, $c_id]);
                    if ($row_prev_c && $row_prev_c['user_answer'] == 'Correct') $child_user_answer_iniziale = 'Correct';
                }

                sec_q("INSERT IGNORE INTO " . fc_t('plays') . " (game_id, fc_child_id, correct_answer, user_answer, position)
                       VALUES (?, ?, ?, ?, ?)", [$game_id, $c_id, $c_ans, $child_user_answer_iniziale, $position]);
            }
        }
    }

    /* Rilettura: che sia stata questa richiesta o quella parallela a
       scrivere per prima, ora la o le righe ci sono comunque. */
    $gameplays = sec_all("SELECT g.* FROM " . fc_t('plays') . " g
                      LEFT JOIN " . fc_t('subcards') . " ch ON ch.id_child = g.fc_child_id
                      WHERE g.game_id = ? AND g.position = ?
                      /* Il padre (fc_child_id NULL) sempre per primo, poi i figli nel
                         loro ordine: senza ORDER BY l'ordine lo decideva il database,
                         ed e' cosi' che il concetto principale finiva in fondo. */
                      ORDER BY (g.fc_child_id IS NOT NULL) ASC, ch.custom_order ASC, g.id ASC",
                     [$game_id, $position]);
}




// 4. Preparazione Dati Visuali per la Pagina

$learnt_ids_set = array_flip(array_column(
    sec_all("SELECT fc_image_id FROM " . fc_t('learnt') . " WHERE user_id = ?", [$user_id]),
    'fc_image_id'
));
/* Due insiemi separati: un preferito puo' essere su un padre o su un singolo
   figlio (la tabella ha entrambe le colonne). */
$fav_padri_set = [];
$fav_figli_set = [];
foreach (sec_all("SELECT fc_image_id, fc_child_id FROM " . fc_t('favorites') . " WHERE user_id = ?", [$user_id]) as $r) {
    if ($r['fc_image_id'] !== null) $fav_padri_set[(int)$r['fc_image_id']] = true;
    if ($r['fc_child_id'] !== null) $fav_figli_set[(int)$r['fc_child_id']] = true;
}

$cards_to_show = [];

$all_answered = true;



foreach ($gameplays as $gp) {

    if ($gp['user_answer'] == '') { $all_answered = false; }



    if (isset($gp['fc_image_id'])) {

                $id = intval($gp['fc_image_id']);
        $card = sec_one("SELECT " . fc_colonne_carta() . " FROM " . fc_t('cards') . " WHERE id = ?", [$id]);

        if($card) {
            $card['is_child'] = false;
            $card['gameplay_idimmagine'] = $id; // Manteniamo la variabile JS compatibility per l'UI HTML
        }

    } else {

                $real_id = intval($gp['fc_child_id']);
        $card = sec_one("SELECT " . fc_colonne_figlia() . " FROM " . fc_t('subcards') . " WHERE id_child = ?", [$real_id]);

        if($card) {
            $card['is_child'] = true;
            $card['gameplay_idimmagine'] = $real_id; // sempre positivo: il tipo lo dice is_child
        }

    }

    

    if ($card) {

        $card = fc_gira_carta($card, fc_verso_posizione($fc_verso, $game_id, (int)$gp['position']));

        $card['user_answer'] = $gp['user_answer'];

        $card['is_titolo'] = ($gp['user_answer'] === 'Titolo');

        $learnt_parent_id = $card['is_child'] ? (int)($card['parent_id'] ?? 0) : (int)$card['gameplay_idimmagine'];
        $card['learnt_parent_id'] = $learnt_parent_id;
        $card['is_learnt'] = isset($learnt_ids_set[$learnt_parent_id]);
        $card['is_favorite'] = $card['is_child']
            ? isset($fav_figli_set[(int)$card['gameplay_idimmagine']])
            : isset($fav_padri_set[(int)$card['gameplay_idimmagine']]);

        // Conta quanti esempi ha questa flashcard, sulla colonna giusta per il suo tipo
        $col = $card['is_child'] ? 'child_id' : 'parent_id';
        $card['examples_count'] = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('examples') . " WHERE $col = ?",
                                        [(int)$card['gameplay_idimmagine']])['c'] ?? 0);
        /* Lingue: il primo esempio, per l'indizio sul davanti della carta */
        $card['fcv_esempio'] = '';
        $card['fcv_esempio_gap'] = '';
        if ($fc_vocab && $card['examples_count'] > 0) {
            /* gap_text: la parte che nel gioco si nasconde, scelta nel modulo */
            $ex = sec_one("SELECT example_text, gap_text FROM " . fc_t('examples') . " WHERE $col = ? AND example_text IS NOT NULL AND example_text <> '' ORDER BY id LIMIT 1",
                          [(int)$card['gameplay_idimmagine']]);
            $card['fcv_esempio'] = $ex ? trim((string)$ex['example_text']) : '';
            $card['fcv_esempio_gap'] = $ex ? (string)($ex['gap_text'] ?? '') : '';
        }

        

        $cards_to_show[] = $card; // Inseriamo la carta UNA SOLA volta alla fine

    }

}



/* IL GIOCO DELLE LINGUE PIU' VIVO (fc_game_juice.js): il livello di ogni
   parola sulla pagina, la serie di risposte giuste, i record del corso e fin
   dove si e' arrivati. Viaggiano dentro la pagina (#fcg-dati e
   data-fcv-stats), cosi' arrivano anche con le pagine caricate al volo. */
$fcg_dati = null;
if (true) { /* lingue e studio */
    $fcg_padri = []; $fcg_figli = [];
    foreach ($cards_to_show as $cc) {
        if (!empty($cc['is_titolo'])) continue;
        if ($cc['is_child']) $fcg_figli[] = (int)$cc['gameplay_idimmagine']; else $fcg_padri[] = (int)$cc['gameplay_idimmagine'];
    }
    $fcg_stats = fcv_stats_carte($fcg_padri, $fcg_figli);
    $fcg_serie = fcv_serie_partita($game_id);
    $fcg_fronte = (int)(sec_one("SELECT MAX(position) AS p FROM " . fc_t('plays') . "
                                  WHERE game_id = ? AND user_answer IN ('Correct', 'Wrong')", [$game_id])['p'] ?? 0);
    $fcg_dati = [
        'partita' => (int)$game_id,
        'corso' => $fc_vocab ? fc_corso_id() : 0,
        'vocab' => $fc_vocab,
        'pos' => (int)$position,
        'fronte' => $fcg_fronte,
        'serie' => $fcg_serie['serie'],
        'migliore' => $fcg_serie['migliore'],
        'risposte' => $fcg_serie['risposte'],
        'record' => fcv_record($user_id, $fc_vocab ? fc_corso_id() : 0),
    ];
}

// 5. Calcoli Progresso e Statistiche DOPPIA BARRA

$played_data = sec_one("SELECT COUNT(DISTINCT position) AS c, COUNT(*) AS c2 FROM " . fc_t('plays') . " WHERE game_id = ?", [$game_id]);

$played_count = $played_data['c'];

$played_cards_count = $played_data['c2'];



// NUOVO: Conta quante carte sono state effettivamente risposte

$answered_cards_count = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer IN ('Correct', 'Wrong')",
                                      [$game_id])['c'] ?? 0);



// NUOVO: Conta quante pagine sono state COMPLETATE (ovvero senza nessuna carta 'vuota')

$answered_pages_count = (int)(sec_one("SELECT COUNT(DISTINCT position) AS c FROM " . fc_t('plays') . "
    WHERE game_id = ? AND position NOT IN (SELECT DISTINCT position FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer = '')",
    [$game_id, $game_id])['c'] ?? 0);



if ($parent_id > 0) {

    // POSIZIONI RIMANENTI
    // Filtriamo rigorosamente sui padri presenti in db e sulle posizioni dove almeno una carta è Wrong

        $to_play_count = (int)(sec_one("SELECT COUNT(DISTINCT position) AS c FROM " . fc_t('plays') . "
                                      WHERE game_id = ?
                                      AND position IN (SELECT DISTINCT position FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer = 'Wrong')
                                      AND fc_image_id IS NOT NULL
                                      AND fc_image_id NOT IN (SELECT fc_image_id FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id IS NOT NULL)",
                                   [$parent_id, $parent_id, $game_id])['c'] ?? 0);



    /* CARTE SINGOLE RIMANENTI: i padri che restano più i loro figli.
       Prima si contavano le righe della partita di origine con
       «fc_child_id NOT IN (…)»: per una carta senza figli fc_child_id è
       vuoto, e in SQL «vuoto NOT IN (insieme vuoto)» è vero — quindi ogni
       carta restava «da giocare» anche dopo averla giocata. Il totale delle
       carte non tornava mai con quello dei concetti, e nel ripasso compariva
       sempre la seconda barra, quella dei figli, anche senza figli. */
    $restanti = "SELECT DISTINCT fc_image_id FROM " . fc_t('plays') . "
                  WHERE game_id = ? AND fc_image_id IS NOT NULL
                    AND position IN (SELECT DISTINCT position FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer = 'Wrong')
                    AND fc_image_id NOT IN (SELECT fc_image_id FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id IS NOT NULL)";
    $figli_restanti = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('subcards') . " ch
                                     JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id AND i.is_father = 1
                                     WHERE ch.parent_id IN ($restanti)",
                                    [$parent_id, $parent_id, $game_id])['c'] ?? 0);
    $to_play_cards_count = $to_play_count + $figli_restanti;

} else {

    // POSIZIONI RIMANENTI

        /* ordine: i parametri di $where_learnt, poi quelli di $cat_filter, poi game_id */
    $to_play_count = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('cards') . " WHERE $where_learnt $cat_filter
                                    AND id NOT IN (SELECT fc_image_id FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id IS NOT NULL)",
                                   array_merge($learnt_par, $cat_par, [$game_id]))['c'] ?? 0);



    // CARTE SINGOLE RIMANENTI

        /* $where_learnt e $cat_filter compaiono DUE volte (i due rami della UNION):
       i loro parametri vanno ripetuti nello stesso ordine. E' il punto piu'
       delicato del file. */
    $to_play_cards_count = (int)(sec_one("
        SELECT SUM(cnt) AS c FROM (
            SELECT COUNT(*) AS cnt FROM " . fc_t('cards') . " WHERE $where_learnt $cat_filter
                AND id NOT IN (SELECT fc_image_id FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id IS NOT NULL)
            UNION ALL
            SELECT COUNT(*) AS cnt FROM " . fc_t('subcards') . " c JOIN " . fc_t('cards') . " i ON c.parent_id = i.id AND i.is_father = 1
             WHERE $where_learnt $cat_filter
                AND i.id NOT IN (SELECT fc_image_id FROM " . fc_t('plays') . " WHERE game_id = ? AND fc_image_id IS NOT NULL)
        ) AS t",
        array_merge($learnt_par, $cat_par, [$game_id], $learnt_par, $cat_par, [$game_id]))['c'] ?? 0);

}



$total_words = $played_count + $to_play_count;

if ($total_words == 0) $total_words = 1;



$total_cards = $played_cards_count + $to_play_cards_count;

if ($total_cards == 0) $total_cards = 1;



sec_q("UPDATE fc_games SET total_father_words = ?, total_words_with_children = ? WHERE id = ? AND user_id = ?",
      [$total_words, $total_cards, $game_id, $current_user_id]);



/* ---------- Una sola convenzione per le percentuali sulle barre ----------
   Prima ogni elemento se la calcolava per conto suo: i pallini rossi al
   CENTRO della loro cella ((n - 0.5) / tot), tutti gli altri al bordo destro
   (n / tot). A piena larghezza lo scarto e' mezza cella e non si nota; con lo
   zoom attivo viene moltiplicato per la scala (circa x8) e diventa uno scarto
   ben visibile - si toccava un pallino rosso e il pallino "sei qui" compariva
   accanto invece che sopra, da cui l'impressione che la barra non seguisse.
   Da qui in avanti esistono solo queste due funzioni:
     - fc_pct_marker(): qualunque PALLINO, sempre al centro della sua cella;
     - fc_pct_fill():   qualunque RIEMPIMENTO, al bordo destro della cella
                        raggiunta (e' una lunghezza, non un punto). */
function fc_pct_marker ($n, $tot) {
    $tot = max(1, (int)$tot);
    return max(0.0, min(100.0, (((float)$n - 0.5) / $tot) * 100));
}
function fc_pct_fill ($n, $tot) {
    $tot = max(1, (int)$tot);
    return max(0.0, min(100.0, ((float)$n / $tot) * 100));
}

// 6. GESTIONE STORICO ERRORI E CHECKPOINT (Pallini Rossi e Bianchi)

$errors_pages = [];

$errors_cards = [];

$furthest_page = 1;

/* Come furthest_page, ma per la barra delle carte: si aggiorna dentro il
   ciclo solo quando c'e' gia' una risposta, non per ogni riga che esiste
   (il precaricamento ne crea anche per posizioni mai visitate davvero). */
$furthest_card_index = 0;

$jump_back_card_index = 0;

$first_unanswered_card_idx = null;



/* Stesso ordine con cui le carte vengono poi mostrate (padre per primo, poi
   i figli nel loro custom_order): numerandole diversamente, l'indice usato
   dalla barra delle carte avrebbe indicato una carta diversa da quella che si
   vede davvero. */
$q_hist = sec_all("SELECT g.position, g.fc_image_id, g.fc_child_id, g.user_answer
                   FROM " . fc_t('plays') . " g
                   LEFT JOIN " . fc_t('subcards') . " ch ON ch.id_child = g.fc_child_id
                   WHERE g.game_id = ?
                   ORDER BY g.position ASC, (g.fc_child_id IS NOT NULL) ASC, ch.custom_order ASC, g.id ASC", [$game_id]);



$card_index = 0;

$page_status = []; 



foreach ($q_hist as $h) {

    $card_index++;

    $p = intval($h['position']);

    /* !== '' da solo non basta: in PHP null !== '' e' vero, quindi una riga
       con user_answer NULL (anziche' stringa vuota) verrebbe scambiata per
       "gia' risposta". trim((string)...) tratta allo stesso modo null,
       stringa vuota e stringa fatta di soli spazi. */
    $ans = trim((string)($h['user_answer'] ?? ''));

    /* 'Titolo' NON e' una risposta: e' il segno che quella carta e' una
       intestazione, scritto quando la riga viene creata. E le righe le crea
       anche il precaricamento, fino a dieci posizioni piu' avanti di dove si
       e' davvero - quindi contarlo come "risposto" spingeva la barra fino
       alla posizione piu' lontana mai precaricata, non a quella raggiunta.
       Era questo a farla arrivare sempre troppo avanti. */
    $ans_e_risposta = ($ans !== '' && $ans !== 'Titolo');

    

    /* Non basta che la riga esista: il precaricamento crea la riga di una
       posizione ancora prima che l'utente ci arrivi davvero, quindi "esiste
       una riga" non vuol dire "ci sono passato". Conta solo se c'e' gia' una
       risposta - e' quello il segno che quella posizione e' stata vissuta
       per davvero, non solo scaricata in anticipo dietro le quinte. */
    if ($ans_e_risposta && $p > $furthest_page) $furthest_page = $p;

    /* Il ciclo va in ordine, quindi l'ultima volta che questo si avvera' e'
       gia' la piu' avanzata: non serve confrontare, si sovrascrive e basta. */
    if ($ans_e_risposta) $furthest_card_index = $card_index;

    if (!isset($page_status[$p])) $page_status[$p] = false;

    

    // Se c'è un errore, segna la pagina (padre) come errata e registra l'errore per la barra delle carte

    if ($ans === 'Wrong') {

        $page_status[$p] = true;

        /* Si tiene anche di che carta si tratta: serve alla lista compatta degli
           errori, che mostra l'inizio di ogni domanda invece di una fila di
           pallini indistinguibili. */
        $errors_cards[] = [
            'idx'   => $card_index,
            'pos'   => $p,
            'img'   => $h['fc_image_id'] !== null ? (int)$h['fc_image_id'] : 0,
            'child' => $h['fc_child_id'] !== null ? (int)$h['fc_child_id'] : 0,
        ];

    }

    

    // Registra la prima carta NON risposta per piazzare il pallino bianco in modo chirurgico

    if ($ans === '' && $first_unanswered_card_idx === null) {

        $first_unanswered_card_idx = $card_index;

    }

}



foreach ($page_status as $p => $has_err) {

    if ($has_err) { $errors_pages[] = $p; }

}




$card_index_map = [];
$idx_tmp = 0;
foreach ($q_hist as $h) {
    $idx_tmp++;
    $chiave = $h['position'] . ':' . ($h['fc_child_id'] ? ('c' . $h['fc_child_id']) : ('p' . $h['fc_image_id']));
    $card_index_map[$chiave] = $idx_tmp;
}

$jump_back_pos = $first_unanswered ? $first_unanswered : $furthest_page;

$jump_back_card_index = $first_unanswered_card_idx ? $first_unanswered_card_idx : $card_index;

/* Tratti di categoria sulla barra superiore: dalla sequenza (gia' decisa a
   inizio partita) si sa quale categoria tocca a ogni posizione, quindi si
   raggruppano le posizioni contigue della stessa categoria in un tratto con
   il suo colore e il suo nome. Solo con piu' di una categoria: con una sola
   non ci sarebbe niente da distinguere. */
$segmenti_categorie = [];
$cat_di_posizione = sec_all("SELECT s.position, c.id AS cid, c.name, c.color
                             FROM " . fc_t('sequence') . " s
                             JOIN " . fc_t('cards') . " i ON i.id = s.fc_image_id
                             JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                             WHERE s.game_id = ?
                             ORDER BY s.position ASC", [$game_id]);

if (count($cat_di_posizione) > 0) {
    $viste = [];
    foreach ($cat_di_posizione as $r) { $viste[(int)$r['cid']] = true; }

    if (count($viste) > 1) {
        $corrente = null;
        foreach ($cat_di_posizione as $r) {
            $cid = (int)$r['cid'];
            if ($corrente !== null && $corrente['cid'] === $cid) {
                $corrente['fine'] = (int)$r['position'];
                continue;
            }
            if ($corrente !== null) $segmenti_categorie[] = $corrente;
            $corrente = ['cid' => $cid, 'nome' => (string)$r['name'],
                         'colore' => (string)$r['color'],
                         'inizio' => (int)$r['position'], 'fine' => (int)$r['position']];
        }
        if ($corrente !== null) $segmenti_categorie[] = $corrente;
    }
}

/* Errori raggruppati per categoria: con piu' di una categoria, un pallino
   raggruppato solo non direbbe quanti errori sono in ciascuna - li si separa
   qui, usando gli stessi tratti gia' calcolati sopra per la barra colorata.
   Con una sola categoria (o nessuna) resta un unico gruppo "generale", e il
   comportamento e' identico a prima. */
$errori_per_categoria = [];
foreach ($errors_pages as $err_p) {
    $nome_cat = null; $colore_cat = null;
    foreach ($segmenti_categorie as $sg) {
        if ($err_p >= $sg['inizio'] && $err_p <= $sg['fine']) {
            $nome_cat = $sg['nome']; $colore_cat = $sg['colore'];
            break;
        }
    }
    $chiave = $nome_cat ?? '';
    if (!isset($errori_per_categoria[$chiave])) {
        $errori_per_categoria[$chiave] = ['nome' => $nome_cat, 'colore' => $colore_cat, 'posizioni' => []];
    }
    $errori_per_categoria[$chiave]['posizioni'][] = $err_p;
}
$errori_per_categoria = array_values($errori_per_categoria);

/* Chiavi delle carte gia' risposte sul server: servono a ripulire la coda
   locale dai voti che in realta' erano arrivati (ma il browser non se n'era
   accorto, per esempio perche' la risposta si era persa per strada). Senza
   questo, i pallini grigi restavano li' per sempre. */
$chiavi_risposte = [];
foreach (sec_all("SELECT position, fc_image_id, fc_child_id FROM " . fc_t('plays') . "
                  WHERE game_id = ? AND user_answer <> ''", [$game_id]) as $r) {
    $id_carta = $r['fc_child_id'] !== null ? (int)$r['fc_child_id'] : (int)$r['fc_image_id'];
    $chiavi_risposte[] = ((int)$r['position']) . ':' . $id_carta;
}

/* Indice (nella numerazione globale delle carte) della carta su cui ci si
   trova adesso: e' li' che deve stare la testa della barra "carte". Prima si
   usava sempre "quante ne ho risposte + 1", che tornando indietro a rivedere
   una carta vecchia lasciava la barra ferma in fondo invece di seguirla. */
$idx_prima_carta_pos = 0;
$n_carte_pos = 0;
$risposte_in_pos = 0;
$scorri = 0;
foreach ($q_hist as $h) {
    $scorri++;
    if ((int)$h['position'] !== (int)$position) continue;
    if ($idx_prima_carta_pos === 0) $idx_prima_carta_pos = $scorri;
    $n_carte_pos++;
    /* Stessa normalizzazione di $ans_e_risposta qui sopra: senza
       trim((string)...) una riga con user_answer NULL passa il test (in PHP
       null !== '' e' vero) e viene contata come risposta, spingendo il
       pallino "sei qui" della barra carte una carta piu' avanti di dove si e'
       davvero. 'Titolo' invece qui conta DI PROPOSITO come carta consumata:
       una carta titolo non si vota, quindi la carta corrente e' gia' la prima
       figlia. E' l'unico punto del file in cui 'Titolo' va contato. */
    if (trim((string)($h['user_answer'] ?? '')) !== '') $risposte_in_pos++;
}
if ($idx_prima_carta_pos === 0) $idx_prima_carta_pos = 1;

$carta_corrente_idx = min(
    $idx_prima_carta_pos + $risposte_in_pos,
    $idx_prima_carta_pos + max(0, $n_carte_pos - 1)
);

/* Il tratto TENUE (oltre pippo) deve arrivare esattamente dove punta il
   pallino di ritorno (dot-target, cioe' jump_back_pos) - non fermarsi a
   furthest_page. Le due cose quasi sempre NON coincidono: in un gioco
   lineare jump_back_pos e' furthest_page + 1 (la prima posizione ancora da
   rispondere, subito dopo l'ultima vera), quindi fermarsi a furthest_page
   lasciava un'intera cella di vuoto fra la punta del tratto tenue e il
   pallino che dovrebbe segnarne la fine - un buco ben visibile, non un
   dettaglio.
   MAX con position per la stessa ragione di sempre: se ti trovi PIU' avanti
   di jump_back_pos (capita nell'istante subito dopo aver votato, prima che
   la pagina rifletta il nuovo fronte) il tratto deve comunque raggiungere
   pippo, mai fermarsi prima. */
$furthest_page_vista = max($jump_back_pos, $position);
$furthest_card_vista = max($jump_back_card_index, $carta_corrente_idx);

/* Inizio della domanda di ogni carta sbagliata, per la lista compatta che
   compare quando i pallini rossi sono troppi per essere distinguibili. Due
   query sole (padri e figli), non una per errore. */
if (!empty($errors_cards)) {
    $id_padri = array_values(array_filter(array_column($errors_cards, 'img')));
    $id_figli = array_values(array_filter(array_column($errors_cards, 'child')));

    $testi_padri = [];
    if (!empty($id_padri)) {
        $ph = implode(',', array_fill(0, count($id_padri), '?'));
        foreach (sec_all("SELECT id, " . fc_col_fronte() . " AS question_desc FROM " . fc_t('cards') . " WHERE id IN ($ph)", $id_padri) as $r) {
            $testi_padri[(int)$r['id']] = (string)$r['question_desc'];
        }
    }

    $testi_figli = [];
    if (!empty($id_figli)) {
        $ph = implode(',', array_fill(0, count($id_figli), '?'));
        foreach (sec_all("SELECT id_child, " . fc_col_fronte() . " AS question_desc FROM " . fc_t('subcards') . " WHERE id_child IN ($ph)", $id_figli) as $r) {
            $testi_figli[(int)$r['id_child']] = (string)$r['question_desc'];
        }
    }

    foreach ($errors_cards as $k => $e) {
        $grezzo = $e['child'] ? ($testi_figli[$e['child']] ?? '') : ($testi_padri[$e['img']] ?? '');
        $errors_cards[$k]['testo'] = fc_anteprima_testo($grezzo, 70);
        $errors_cards[$k]['is_child'] = $e['child'] ? 1 : 0;
    }
}

$row_max = sec_one("SELECT MAX(position) AS max_p FROM " . fc_t('plays') . " WHERE game_id = ?", [$game_id]);



$max_position_generated = $row_max ? $row_max['max_p'] : 1;



$status_text = ($status === 'all') ? T('fc.gm_filtro_all') : (($status == 1) ? T('fc.cg_learnt') : T('fc.cg_to_learn'));

?>



<!DOCTYPE html>

<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">

<head>
<script>
/* Gli indizi delle lingue (significato, esempio) scoperti fin dall'inizio, se
   lo si è scelto nelle opzioni della partita. Si decide prima di disegnare la
   pagina, così non si vedono coperti e poi aprirsi. */
/* Come si vedono significato ed esempio (impostazioni del gioco, l'ingranaggio):
   aperto / tocco / dopo. Chi aveva solo la vecchia scelta "gia' aperti" la
   ritrova uguale. */
try {
    var fcvOk = ['aperto', 'tocco', 'dopo'];
    var fcvVecchio = localStorage.getItem('fcv_indizi_aperti') === '1' ? 'aperto' : 'tocco';
    var fcvSig = localStorage.getItem('fcv_mostra_significato'); if (fcvOk.indexOf(fcvSig) < 0) fcvSig = fcvVecchio;
    var fcvEs = localStorage.getItem('fcv_mostra_esempio'); if (fcvOk.indexOf(fcvEs) < 0) fcvEs = fcvVecchio;
    document.documentElement.classList.add('fcv-sig-' + fcvSig, 'fcv-es-' + fcvEs);
} catch (e) { document.documentElement.classList.add('fcv-sig-tocco', 'fcv-es-tocco'); }
</script>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

    <meta name="mobile-web-app-capable" content="yes">

    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <meta name="format-detection" content="telephone=no">

    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">

    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    <title><?php te('fc.gm_titolo'); ?></title>



    <?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>
    <?php echo fc_boot_json(); ?>
<?php standout_azione_form(); ?>
<script>
    /* ================================================================
       SPIA ERRORI JS — visibile a schermo.
       Serve su iPhone/iPad, dove non c'è una console da consultare: se un
       errore JavaScript rompe la pagina (tipico: una funzione o una sintassi
       che Chrome supporta e Safari no, che fa fallire l'INTERO blocco script
       e quindi "non funziona più niente"), qui sotto compare un riquadro rosso
       con il messaggio esatto, il file e la riga.
       È il primo script della pagina apposta: così intercetta anche gli errori
       degli script che vengono dopo.
       Quando tutto funziona, questo riquadro non compare mai.
       ================================================================ */
    (function () {
        var box = null;

        function ensureBox() {
            if (box) return box;
            box = document.createElement('div');
            box.id = 'js-error-box';
            box.setAttribute('style',
                'position:fixed;left:8px;right:8px;bottom:8px;z-index:2147483647;' +
                'background:#FF3B30;color:#fff;padding:12px 14px;border-radius:14px;' +
                'font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;' +
                'white-space:pre-wrap;word-break:break-word;max-height:46vh;overflow:auto;' +
                '-webkit-overflow-scrolling:touch;box-shadow:0 10px 40px rgba(0,0,0,.45)');

            var close = document.createElement('button');
            close.textContent = '✕';
            close.setAttribute('style',
                'position:absolute;top:6px;right:8px;background:rgba(255,255,255,.25);' +
                'color:#fff;border:none;border-radius:50%;width:24px;height:24px;font-size:13px;');
            close.onclick = function () { box.style.display = 'none'; };
            box.appendChild(close);

            var title = document.createElement('div');
            title.textContent = T('fc.gm_errore_js');
            title.setAttribute('style', 'font-weight:700;margin-bottom:6px;padding-right:26px;');
            box.appendChild(title);

            (document.body || document.documentElement).appendChild(box);
            return box;
        }

        function show(msg) {
            try {
                var b = ensureBox();
                b.style.display = 'block';
                var line = document.createElement('div');
                line.setAttribute('style', 'margin-top:6px;padding-top:6px;border-top:1px solid rgba(255,255,255,.3);');
                line.textContent = msg;
                b.appendChild(line);
            } catch (e) {}
        }

        window.addEventListener('error', function (e) {
            var file = (e.filename || '').split('/').pop();
            show((e.message || T('fc.gm_errore_sconosciuto')) + '\n→ ' + file + ' riga ' + (e.lineno || '?') + ':' + (e.colno || '?'));
        });

        window.addEventListener('unhandledrejection', function (e) {
            var r = e.reason;
            show('Promise non gestita: ' + ((r && r.message) ? r.message : String(r)));
        });
    })();
    </script>



    <?php if (!$fc_vocab): /* parole: niente formule */ ?>
    <script>

        window.MathJax = {

        tex: {

            inlineMath: [['$', '$'], ['\\(', '\\)']],

            displayMath: [['$$', '$$'], ['\\[', '\\]']]

        }

        };

    </script>

    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <?php endif; ?>



<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_game.css?v=<?php echo @filemtime(__DIR__ . '/fc_game.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_examples_box.css?v=<?php echo @filemtime(__DIR__ . '/fc_examples_box.css') ?: 1; ?>">
<script src="<?php echo PWA_BASE; ?>fc/fc_examples_modal.js?v=<?php echo @filemtime(__DIR__ . '/fc_examples_modal.js') ?: 1; ?>"></script>
<?php /* serie, checkpoint, boss, livelli: lingue e studio */ ?>
<?php if (true): ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_game_juice.css?v=<?php echo @filemtime(__DIR__ . '/fc_game_juice.css') ?: 1; ?>">
<script src="<?php echo PWA_BASE; ?>fc/fc_game_juice.js?v=<?php echo @filemtime(__DIR__ . '/fc_game_juice.js') ?: 1; ?>"></script>
<?php endif; ?>

    <?php if ($fc_vocab): /* fc_parser vive solo nelle flashcard di studio */ ?>
    <script src="<?php echo PWA_BASE; ?>fc/fc_speak.js?v=<?php echo @filemtime(__DIR__ . '/fc_speak.js') ?: 1; ?>"></script>
    <?php else: ?>
    <script src="fc_parser.js"></script>
    <?php endif; ?>
</head>

<body class="<?php echo $fc_vocab ? 'fc-vocab' : 'fc-study'; ?>">

    <script>
        // Stime lato server: tempo medio storico e se le ultime chiamate sono state
        // più lente del solito, per ciascuna modalità IA testuale (vedi playAI()/shatterCard()).
        window.aiPerfStats = <?php echo json_encode($ai_perf_stats_js); ?>;
    </script>



    <div id="image-overlay" style="display: none;">

        <div id="close-overlay">&#10005;</div>

        <img id="overlay-img" src="" alt="">

    </div>



    <div class="container" id="game-container"

         data-pos="<?php echo (int)($position); ?>"

         data-can-back="<?php echo ($position > 1) ? '1' : '0'; ?>"

         data-back-url="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position-1); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>"

         data-can-forward="<?php echo ($position < $max_position_generated || ($all_answered && $position < $total_words)) ? '1' : '0'; ?>"

         data-forward-url="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position+1); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>"

         data-chrono-base="<?php echo (int)($chrono_base_seconds); ?>"

         data-chrono-start="<?php echo (int)($chrono_start_ts); ?>"
         data-total-words="<?php echo (int)($total_words); ?>"
         data-total-cards="<?php echo (int)($total_cards); ?>"
         data-carte-testa="<?php echo (int)min($total_cards, max(1, $carta_corrente_idx)); ?>"
         <?php /* Il MODELLO del progresso, non la sua geometria: il JavaScript
                  deve poter ricalcolare ogni percentuale da questi numeri
                  senza mai dedurla rileggendo uno style.width gia' scritto -
                  era quella lettura a mandare fuori scala la barra zoomata. */ ?>
         data-furthest-page="<?php echo (int)min($total_words, max(1, $furthest_page_vista)); ?>"
         data-furthest-card="<?php echo (int)min($total_cards, max(0, $furthest_card_vista)); ?>"
         data-carte-risposte="<?php echo (int)$answered_cards_count; ?>"
         data-jump-back-pos="<?php echo (int)$jump_back_pos; ?>"
         data-jump-back-card="<?php echo (int)$jump_back_card_index; ?>"
         data-status="<?php echo sec_h($status); ?>"
         data-order="<?php echo sec_h($order_param); ?>">
        <?php if ($fcg_dati): ?><script type="application/json" class="fcg-dati"><?php echo json_encode($fcg_dati + ['totale' => (int)$total_words], JSON_HEX_TAG | JSON_HEX_AMP); ?></script><?php endif; ?>



        <header class="app-header">
            <div class="bars" onclick="alternaVistaBarra(event)" title="<?php te('fc.gm_barra_vista'); ?>">
                <?php /* Traccia d'insieme, visibile solo quando la barra sotto e'
                         ingrandita. Serve perche' la finestra ingrandita e'
                         CENTRATA sulla posizione corrente: il suo riempimento
                         finisce quindi sempre a meta' (o schizza a 100% se si e'
                         tornati indietro), e smette di dire "quanto manca". Qui
                         invece la scala e' sempre l'intera partita, col tratto
                         gia' fatto. */ ?>
                <div class="barra-insieme" aria-hidden="true">
                    <span class="insieme-fill"></span>
                </div>
                <?php if (!empty($segmenti_categorie)): ?>
                <div class="cat-strip">
                    <?php foreach ($segmenti_categorie as $sg):
                        $sx = (($sg['inizio'] - 1) / max(1, $total_words)) * 100;
                        $largh = (($sg['fine'] - $sg['inizio'] + 1) / max(1, $total_words)) * 100;
                    ?>
                        <span class="cat-seg" style="left:<?php echo (float)$sx; ?>%; width:<?php echo (float)$largh; ?>%; --cat:<?php echo sec_h($sg['colore']); ?>;" title="<?php echo sec_h($sg['nome']); ?>"><span class="cat-seg-nome"><?php echo sec_h($sg['nome']); ?></span></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="progress-bar-bg<?php echo !empty($segmenti_categorie) ? ' ha-categorie' : ''; ?>">
                    <?php /* Il riempimento PIENO arriva sempre esattamente fino a pippo
                             (posizione corrente) - mai oltre, per non sembrare "sballato"
                             quando in realta' e' solo piu' avanti di dove stai guardando.

                             ATTENZIONE alla convenzione: deve usare fc_pct_marker, la
                             STESSA di pippo qui sotto (centro cella), non fc_pct_fill
                             (bordo cella) come prima. Con fc_pct_fill il bordo del
                             riempimento cadeva mezza cella DOPO il centro di pippo: a
                             tinta unica non si notava, ma con la tinta tenue che segue
                             (sotto) quella mezza cella e' diventata un gradino di colore
                             ben visibile subito dopo pippo, come un padding indesiderato.

                             Il fatto che tu sia ANCHE piu' avanti di cosi' (jump_back_pos
                             piu' avanti di position, perche' stai rivedendo un vecchio
                             errore) non deve sparire - altrimenti il colore
                             retrocederebbe ogni volta che torni indietro, che e' il difetto
                             opposto gia' visto in passato. Resta visibile ma in un tono piu'
                             tenue, DOPO pippo, fino a dot-target incluso: stessa convenzione
                             di dot-target qui sotto (anche lui fc_pct_marker), altrimenti il
                             tratto tenue si fermava mezza cella prima del pallino che
                             dovrebbe segnarne la fine. */
                          $bordo_pos = fc_pct_marker($position, $total_words);
                          $bordo_fronte = fc_pct_marker($furthest_page_vista, $total_words);
                    ?>
                    <div class="progress-bar-fill fill-pages" style="width: <?php echo $bordo_pos; ?>%;"></div>
                    <div class="progress-bar-fill fill-pages-oltre" style="left: <?php echo $bordo_pos; ?>%; width: <?php echo max(0, $bordo_fronte - $bordo_pos); ?>%;"></div>
                    <?php /* Un pallino esplicito per "sei qui": prima c'era solo il
                             bordo del riempimento a dirlo, che quando la barra e'
                             ingrandita e ritagliata puo' non essere un buon indizio
                             visivo. Questo resta sempre ancorato al centro della
                             finestra ingrandita (vedi applicaZoomBarra).

                             ATTENZIONE alla convenzione, e alla sua storia: e'
                             stato provato anche a BORDO cella (come il
                             riempimento), per farlo toccare esattamente la
                             punta della barra quando ci si trova sul fronte.
                             Ma "sei qui" e' prima di tutto un pallino che si
                             clicca e si raggiunge, ESATTAMENTE come i pallini
                             rossi, quello di ritorno e quello raggruppato: se
                             usa una convenzione diversa dalla loro, cliccare
                             un pallino rosso porta pippo a comparire mezza
                             cella PIU' A DESTRA di dove quel pallino stava -
                             uno scarto piccolo a vista intera ma vistoso con
                             lo zoom, che sembra un padding sbagliato. Deve
                             quindi stare al CENTRO della sua cella come tutti
                             gli altri: il prezzo e' che sul fronte esatto
                             resta mezza cella prima della punta del
                             riempimento, ma coincidere con cio' che si clicca
                             conta piu' che toccare quella punta. */
                          $qui_percent = fc_pct_marker($position, $total_words);
                          /* Un tocco in piu': se la posizione che stai guardando
                             ORA e' proprio una di quelle sbagliate (stai
                             ricontrollando un errore), l'interno di pippo si tinge
                             leggermente di rosso - un promemoria visivo che non
                             sei semplicemente "avanti", sei qui apposta perche' hai
                             sbagliato. Il confronto e' volutamente non-strict
                             (in_array senza terzo parametro): $errors_pages puo'
                             contenere numeri gia' interi o stringhe numeriche a
                             seconda di come sono usciti dalla query, e qui basta
                             che il VALORE coincida. */
                          $sta_su_errore = in_array($position, $errors_pages);
                    ?>
                    <span class="dot dot-qui<?php echo $sta_su_errore ? ' dot-qui-errore' : ''; ?>" style="left: <?php echo $qui_percent; ?>%;"></span>
                    <?php foreach ($segmenti_categorie as $sg):
                        $sx = (($sg['inizio'] - 1) / max(1, $total_words)) * 100;
                        $largh = (($sg['fine'] - $sg['inizio'] + 1) / max(1, $total_words)) * 100; ?>
                        <span class="cat-tratto" style="left:<?php echo (float)$sx; ?>%; width:<?php echo (float)$largh; ?>%; background:<?php echo sec_h($sg['colore']); ?>;"></span>
                        <?php if ($sg['fine'] < $total_words): $tacca = ($sg['fine'] / max(1, $total_words)) * 100; ?>
                            <span class="cat-tacca" style="left:<?php echo (float)$tacca; ?>%;"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php
                    /* Finche' sono pochi, un pallino per errore: si vede a colpo
                       d'occhio dove sono. Oltre questa soglia si ammassano fino a
                       diventare una macchia indistinguibile e impossibile da
                       centrare col dito, quindi si compattano in uno solo che apre
                       l'elenco con l'inizio di ogni domanda. */
                    $errori_compatti = count($errors_pages) > 6;
                    ?>

                    <?php if (!$errori_compatti): ?>
                        <?php foreach($errors_pages as $err_p):
                            /* Se e' proprio la posizione su cui ci si trova ora, non si
                               disegna: ci pensa gia' il pallino "sei qui" a segnarla, e
                               due indicatori uno sopra l'altro sarebbero confusi. */
                            if ((int)$err_p === (int)$position) continue;
                            $percent = fc_pct_marker($err_p, $total_words); ?>
                            <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo sec_h($err_p); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="dot dot-red" style="left: <?php echo sec_h($percent); ?>%;" title="<?php te('fc.gm_errore_pagina', ['n' => (int)$err_p]); ?>"></a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php /* Un pallino raggruppato per categoria, non uno solo per
                                 tutta la partita: cosi' si vede anche QUANTI errori sono
                                 in ciascuna, non solo che ce ne sono in totale. Con una
                                 sola categoria resta un pallino solo, comportamento
                                 identico a prima. */ ?>
                        <?php foreach ($errori_per_categoria as $ec):
                            /* MEDIANA, non media: con errori alla 5 e alla 95 la
                               media cadeva a meta' barra, cioe' su un punto dove
                               non c'e' nessun errore. La mediana cade sempre su
                               una posizione sbagliata vera, quindi il pallino
                               indica un posto che esiste davvero. */
                            $posizioni = $ec['posizioni'];
                            sort($posizioni);
                            $mediana = $posizioni[intdiv(count($posizioni), 2)];
                            $percent_medio = fc_pct_marker($mediana, $total_words);
                        ?>
                            <button type="button" class="dot dot-red dot-cluster" style="left: <?php echo (float)$percent_medio; ?>%; <?php echo $ec['colore'] ? 'background:' . sec_h($ec['colore']) . ';' : ''; ?>" onclick="apriElencoErrori(event)" title="<?php echo $ec['nome'] ? sec_h($ec['nome']) : ''; ?>"><span><?php echo count($posizioni); ?></span></button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php /* Se ci si e' tornati indietro a rivedere una carta, la prima
                             ancora da rispondere sta altrove rispetto a dove si e' ora
                             (dot-qui, poco sopra): si aggiunge un riferimento vuoto e
                             distinto per tornarci con un tocco. Se invece si coincide gia'
                             con quel punto non serve mostrarlo due volte. */
                          $mostra_ritorno = ($jump_back_pos > 0 && (int)$jump_back_pos !== (int)$position);
                          $ritorno_percent = fc_pct_marker($jump_back_pos, $total_words); ?>
                    <?php if ($mostra_ritorno): ?>
                        <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo sec_h($jump_back_pos); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="dot dot-target" style="left: <?php echo sec_h($ritorno_percent); ?>%;" title="<?php te('fc.gm_torna_pagina'); ?>"></a>
                    <?php endif; ?>
                </div>

                <?php if ($total_words != $total_cards): ?>
                <div class="progress-bar-bg">
                    <?php /* Stessa divisione in due toni della barra concetti qui sopra,
                             stessa convenzione (fc_pct_marker, centro cella, non
                             fc_pct_fill): pieno fino a pippo esatto, tenue oltre fino a
                             dot-target-alt esatto se si e' arrivati piu' avanti altrove. */
                          $carte_qui = min($total_cards, max(1, $carta_corrente_idx));
                          $carte_bordo_qui = fc_pct_marker($carte_qui, $total_cards);
                          $carte_bordo_fronte = fc_pct_marker($furthest_card_vista, $total_cards);
                    ?>
                    <div class="progress-bar-fill fill-cards" style="width: <?php echo $carte_bordo_qui; ?>%;"></div>
                    <div class="progress-bar-fill fill-cards-oltre" style="left: <?php echo $carte_bordo_qui; ?>%; width: <?php echo max(0, $carte_bordo_fronte - $carte_bordo_qui); ?>%;"></div>
                    <span class="dot dot-qui dot-qui-alt<?php echo $sta_su_errore ? ' dot-qui-errore' : ''; ?>" style="left: <?php echo fc_pct_marker($carte_qui, $total_cards); ?>%;"></span>

                    <?php /* Niente pallini rossi qui: gli errori si leggono sulla barra
                             sopra. Questa serve a vedere a che punto si e' DENTRO la
                             posizione corrente (utile coi figli), e riempirla di segni
                             la rendeva solo illeggibile. */ ?>
                    <?php /* Il pallino "sei qui" (dot-qui-alt, poco sopra) ha gia' preso
                             il posto di quello che c'era qui prima: mostrarli entrambi alla
                             stessa posizione sarebbe stato ridondante. */
                          $ritorno_percent2 = fc_pct_marker($jump_back_card_index, $total_cards); ?>
                    <?php if ($mostra_ritorno && $jump_back_card_index > 0): ?>
                        <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo sec_h($jump_back_pos); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="dot dot-target dot-target-alt" style="left: <?php echo sec_h($ritorno_percent2); ?>%;" title="<?php te('fc.gm_torna_carta'); ?>"></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="hdr-row">
                <div class="hdr-left">
                    <div class="hdr-title"><?php echo (int)($position); ?> <span>/ <?php echo (int)($total_words); ?></span></div>
                    <div class="hdr-sub">
                        <?php th('fc.gm_n_concetti', ['conteggio' => '<b>' . (int)$answered_pages_count . '/' . (int)$total_words . '</b>']); ?>
                        <?php if ($total_words != $total_cards): ?>
                            &middot; Card <b><?php echo (int)($answered_cards_count); ?>/<?php echo (int)($total_cards); ?></b>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hdr-right">
                    <button id="chrono-pill" class="chrono-pill" type="button" title="<?php te('fc.gm_durata'); ?>" aria-label="<?php te('fc.gm_durata'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="chrono-ic"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></svg>
                        <span class="chrono-txt" id="chrono-txt">0:00</span>
                    </button>
                    <span class="chip chip-status <?php echo ($status === 'all') ? 'st-all' : (($status == 1) ? 'st-learnt' : ''); ?>"><?php echo sec_h($status_text); ?></span>
                    <?php if ($fc_vocab): /* le lingue non leggono ad alta voce le carte: le loro impostazioni sono gli indizi */ ?>
                    <button id="fcvSettingsBtn" class="icon-btn" type="button" title="<?php te('fcv.gs_titolo'); ?>" aria-label="<?php te('fcv.gs_titolo'); ?>" aria-haspopup="dialog">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <?php else: ?>
                    <button id="ttsSettingsBtn" class="icon-btn" type="button" title="<?php te('fc.gm_impostazioni'); ?>" aria-label="<?php te('fc.gm_impostazioni'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a9 9 0 0 1 0 14"/></svg>
                    </button>
                    <?php endif; ?>
                    <button id="voiceGameBtn" class="icon-btn" type="button" title="<?php te('fc.gm_gioca_audio'); ?>" aria-label="<?php te('fc.gm_gioca_audio'); ?>" onclick="if(window.VoiceGameMode) VoiceGameMode.handleHeaderButton();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>
                    </button>
                </div>
            </div>
        </header>



        <?php if ($fc_vocab): ?>
        <?php /* IMPOSTAZIONI DEL GIOCO DELLE LINGUE
                 Al posto di quelle della lettura ad alta voce (voce, lingua,
                 LaTeX), che qui non servono: si sceglie come vedere il
                 significato e l'esempio prima di rispondere. Ogni tocco vale
                 subito, anche sulla carta che si ha sotto — il foglio e' anche
                 l'anteprima di se stesso. Si ricorda su questo dispositivo. */
              $fcv_gs = [
                  ['significato', 'fcv_mostra_significato', 'sig', '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>'],
                  ['esempio', 'fcv_mostra_esempio', 'es', '<path d="M7 7h10M7 12h6"/><path d="M5 3h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-6l-5 4v-4H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/>'],
              ]; ?>
        <div id="fcv-settings" class="fcv-gs" hidden>
            <div class="sheet-panel fcv-gs-panel" role="dialog" aria-modal="true" aria-labelledby="fcvGsTit">
                <button class="sheet-close" type="button" data-fcv-chiudi aria-label="<?php te('comune.chiudi'); ?>">&#10005;</button>
                <h3 id="fcvGsTit"><?php te('fcv.gs_titolo'); ?></h3>
                <p class="fcv-gs-sub"><?php te('fcv.gs_sub'); ?></p>
                <?php foreach ($fcv_gs as [$k, $chiave, $cl, $ic]): ?>
                <div class="fcv-gs-riga" data-chiave="<?php echo $chiave; ?>" data-classe="<?php echo $cl; ?>">
                    <div class="fcv-gs-testa">
                        <span class="fcv-gs-ic fcv-gs-ic-<?php echo $k; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><?php echo $ic; ?></svg></span>
                        <span class="fcv-gs-nome"><?php te('fcv.indizio_' . $k); ?></span>
                    </div>
                    <div class="fcv-gs-seg" role="radiogroup" aria-label="<?php te('fcv.indizio_' . $k); ?>">
                        <?php foreach (['aperto', 'tocco', 'dopo'] as $v): ?>
                        <button type="button" role="radio" aria-checked="false" data-v="<?php echo $v; ?>"><?php te('fcv.gs_' . $v); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="fcv-gs-spiega" aria-live="polite"></p>
                </div>
                <?php endforeach; ?>
                <?php /* Checkpoint e suoni: li gestisce fc_game_juice.js */ ?>
                <div class="fcv-gs-riga" data-fcg="checkpoint">
                    <div class="fcv-gs-testa">
                        <span class="fcv-gs-ic fcv-gs-ic-ck"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg></span>
                        <span class="fcv-gs-nome"><?php te('fcv.gs_checkpoint'); ?></span>
                    </div>
                    <div class="fcv-gs-seg fcv-gs-seg-6" role="radiogroup" aria-label="<?php te('fcv.gs_checkpoint'); ?>">
                        <?php foreach (['0', '5', '10', '15', '20', '25'] as $v): ?>
                        <button type="button" role="radio" aria-checked="false" data-v="<?php echo $v; ?>"><?php echo $v === '0' ? sec_h(t('fcv.gs_ck_no')) : $v; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="fcv-gs-spiega" aria-live="polite"></p>
                </div>
                <label class="fcv-gs-riga fcv-gs-switch">
                    <span class="fcv-gs-testa">
                        <span class="fcv-gs-ic fcv-gs-ic-suoni"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>
                        <span class="fcv-gs-nome"><?php te('fcv.gs_suoni'); ?><small><?php te('fcv.gs_suoni_sub'); ?></small></span>
                    </span>
                    <span class="fcv-gs-toggle"><input type="checkbox" class="fcg-suoni" checked><span></span></span>
                </label>
                <div class="fcv-gs-nota">
                    <span class="fcv-gs-demo" aria-hidden="true">
                        <span class="fcv-gap fcv-gap-m"><span class="fcv-gap-vuoto"></span></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <span class="fcv-gs-demo-parola"><?php te('fcv.gs_demo_parola'); ?></span>
                    </span>
                    <span><?php te('fcv.gs_nota_esempio'); ?></span>
                </div>
                <button type="button" class="btn btn-primary fcv-gs-fatto" data-fcv-chiudi><?php te('fcv.es_fatto'); ?></button>
            </div>
        </div>
        <script>
        /* Il foglio sta dentro la pagina, che a ogni carta viene sostituita con
           quella nuova: e gli script di una pagina arrivata cosi' non partono.
           Quindi tutto passa da document (una volta sola) e il foglio si
           cerca ogni volta nella pagina in cui si e' toccato l'ingranaggio. */
        (function () {
            if (window.__fcvGsPronto) return;
            window.__fcvGsPronto = true;
            var OK = ['aperto', 'tocco', 'dopo'];
            var root = document.documentElement;
            var aperto = null, bottone = null;
            function attuale(classe) {
                for (var i = 0; i < OK.length; i++) if (root.classList.contains('fcv-' + classe + '-' + OK[i])) return OK[i];
                return 'tocco';
            }
            function segna(riga) {
                var v = attuale(riga.getAttribute('data-classe'));
                riga.querySelectorAll('[data-v]').forEach(function (b) {
                    var on = b.getAttribute('data-v') === v;
                    b.classList.toggle('is-on', on);
                    b.setAttribute('aria-checked', on ? 'true' : 'false');
                    b.tabIndex = on ? 0 : -1;
                });
                riga.querySelector('.fcv-gs-seg').style.setProperty('--fcv-i', String(OK.indexOf(v)));
                riga.querySelector('.fcv-gs-spiega').textContent = T('fcv.gs_' + v + '_sub');
            }
            function scegli(riga, v) {
                var classe = riga.getAttribute('data-classe');
                OK.forEach(function (x) { root.classList.remove('fcv-' + classe + '-' + x); });
                root.classList.add('fcv-' + classe + '-' + v);
                try { localStorage.setItem(riga.getAttribute('data-chiave'), v); } catch (e) {}
                segna(riga);
                try { if (navigator.vibrate) navigator.vibrate(6); } catch (e) {}
            }
            function chiudi() {
                var f = aperto; if (!f) return;
                aperto = null;
                f.classList.add('is-uscita');
                setTimeout(function () { f.hidden = true; f.classList.remove('is-uscita'); if (bottone && bottone.isConnected) bottone.focus(); }, 200);
            }
            document.addEventListener('click', function (e) {
                var apri = e.target.closest && e.target.closest('#fcvSettingsBtn');
                if (apri) {
                    e.stopPropagation();
                    var cont = apri.closest('.container') || document;
                    var foglio = cont.querySelector('#fcv-settings') || document.getElementById('fcv-settings');
                    if (!foglio) return;
                    bottone = apri; aperto = foglio;
                    foglio.querySelectorAll('.fcv-gs-riga[data-classe]').forEach(segna);
                    foglio.hidden = false;
                    var primo = foglio.querySelector('.fcv-gs-seg .is-on');
                    if (primo) primo.focus({ preventScroll: true });
                    document.dispatchEvent(new CustomEvent('fcv-impostazioni', { detail: { foglio: foglio } }));
                    return;
                }
                if (!aperto || !aperto.contains(e.target)) return;
                if (e.target === aperto || e.target.closest('[data-fcv-chiudi]')) { chiudi(); return; }
                var b = e.target.closest('.fcv-gs-riga[data-classe] .fcv-gs-seg [data-v]');
                if (b) scegli(b.closest('.fcv-gs-riga'), b.getAttribute('data-v'));
            }, true);
            document.addEventListener('keydown', function (e) {
                if (!aperto) return;
                if (e.key === 'Escape') { e.preventDefault(); chiudi(); return; }
                var b = e.target.closest && e.target.closest('.fcv-gs-riga[data-classe] .fcv-gs-seg [data-v]');
                if (!b || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
                e.preventDefault();
                var i = OK.indexOf(b.getAttribute('data-v')) + (e.key === 'ArrowRight' ? 1 : -1);
                i = (i + OK.length) % OK.length;
                var riga = b.closest('.fcv-gs-riga');
                scegli(riga, OK[i]);
                riga.querySelector('[data-v="' + OK[i] + '"]').focus();
            });
        })();
        </script>
        <?php endif; ?>

        <div id="tts-modal" style="display:none;">

            <div class="sheet-panel">

                <h3><?php te('fc.gm_impostazioni'); ?></h3>

                <?php /* STUDIO: checkpoint e suoni del gioco (fc_game_juice.js),
                         gli stessi dell'ingranaggio delle lingue. */ ?>
                <div class="fcv-gs-riga" data-fcg="checkpoint">
                    <div class="fcv-gs-testa">
                        <span class="fcv-gs-ic fcv-gs-ic-ck"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg></span>
                        <span class="fcv-gs-nome"><?php te('fcv.gs_checkpoint'); ?></span>
                    </div>
                    <div class="fcv-gs-seg fcv-gs-seg-6" role="radiogroup" aria-label="<?php te('fcv.gs_checkpoint'); ?>">
                        <?php foreach (['0', '5', '10', '15', '20', '25'] as $v): ?>
                        <button type="button" role="radio" aria-checked="false" data-v="<?php echo $v; ?>"><?php echo $v === '0' ? sec_h(t('fcv.gs_ck_no')) : $v; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="fcv-gs-spiega" aria-live="polite"></p>
                </div>
                <label class="fcv-gs-riga fcv-gs-switch">
                    <span class="fcv-gs-testa">
                        <span class="fcv-gs-ic fcv-gs-ic-suoni"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>
                        <span class="fcv-gs-nome"><?php te('fcv.gs_suoni'); ?><small><?php te('fcv.gs_suoni_sub'); ?></small></span>
                    </span>
                    <span class="fcv-gs-toggle"><input type="checkbox" class="fcg-suoni" checked><span></span></span>
                </label>



                <div class="field">

                    <label for="tts-mode"><?php te('fc.gm_modalita'); ?></label>

                    <select id="tts-mode">

                        <option value="no"><?php te('comune.no'); ?></option>

                        <option value="click"><?php te('fc.gm_solo_click'); ?></option>

                        <option value="auto"><?php te('fc.gm_automatica'); ?></option>

                    </select>

                    <div class="field-hint" id="tts-mode-hint" style="display:none;"><?php th('fc.gm_modo_microfono', ['modo' => '<b>' . sec_h(t('fc.gm_modalita_microfono')) . '</b>']); ?>: <?php te('fc.gm_microfono_spiega'); ?></div>

                </div>



                <div class="field">

                    <label for="tts-lang"><?php te('comune.lingua'); ?></label>

                    <select id="tts-lang">

                        <option value="it"><?php te('vocab.ins_italiano'); ?></option>

                        <option value="en"><?php te('vocab.vi_inglese'); ?></option>

                        <option value="auto"><?php te('fc.gm_auto'); ?></option>

                    </select>

                </div>



                <div class="field">

                    <label for="tts-voice"><?php te('fc.gm_voce_dispositivo'); ?></label>

                    <select id="tts-voice">

                        <option value=""><?php te('fc.gm_auto_migliore'); ?></option>

                    </select>

                    <div class="field-hint" id="tts-voice-hint"><?php te('fc.gm_voce_nota'); ?></div>

                    <div class="field-hint field-warn" id="tts-voice-warn" style="display:none;"></div>

                </div>



                <div class="field">

                    <label for="tts-ai-voice"><?php te('fc.gm_voce_ia'); ?></label>

                    <select id="tts-ai-voice">

                        <optgroup label=T('fc.gm_maschili')>

                            <option value="it-IT-Chirp3-HD-Charon" selected>Charon</option>

                            <option value="it-IT-Chirp3-HD-Puck">Puck</option>

                            <option value="it-IT-Chirp3-HD-Fenrir">Fenrir</option>

                            <option value="it-IT-Chirp3-HD-Orus">Orus</option>

                        </optgroup>

                        <optgroup label=T('fc.gm_femminili')>

                            <option value="it-IT-Chirp3-HD-Aoede">Aoede</option>

                            <option value="it-IT-Chirp3-HD-Kore">Kore</option>

                            <option value="it-IT-Chirp3-HD-Leda">Leda</option>

                            <option value="it-IT-Chirp3-HD-Zephyr">Zephyr</option>

                        </optgroup>

                    </select>

                </div>



                <div class="field">

                    <label for="tts-speed"><?php te('fc.fp_velocita'); ?></label>

                    <select id="tts-speed">

                        <option value="0.8">x0.8</option>

                        <option value="1" selected>x1.0</option>

                        <option value="1.2">x1.2</option>

                        <option value="1.5">x1.5</option>

                        <option value="2">x2.0</option>

                    </select>

                    <div class="field-hint" id="tts-speed-hint" style="display:none;"></div>

                </div>



                <div class="field">

                    <label for="tts-math"><?php te('fc.gm_leggi_latex'); ?></label>

                    <select id="tts-math">

                        <option value="yes"><?php te('comune.si'); ?></option>

                        <option value="no"><?php te('comune.no'); ?></option>

                    </select>

                </div>



                <button id="tts-save" class="btn btn-primary" style="margin-top:22px;"><?php te('fc.gm_salva_chiudi'); ?></button>

            </div>

        </div>



        <div id="ai-modal" style="display:none;">

            <div class="sheet-panel">

                <button class="sheet-close" type="button" onclick="closeAiModal()" aria-label="<?php te('comune.chiudi'); ?>">&#10005;</button>

                <h3 id="ai-modal-title"><?php te('fc.gm_spiegazione_ai'); ?></h3>

                <div id="ai-timer" class="ai-timer"><span class="dot"></span><span id="ai-timer-text"></span></div>

                <div id="ai-audio-status" class="ai-status" style="display: none;"><?php te('fc.gm_audio_in_corso'); ?></div>

                <div id="ai-modal-content" class="raw-text ai-body"></div>

            </div>

        </div>



        <div id="shatter-modal" style="display:none;">

            <div class="sheet-panel">

                <button class="sheet-close" type="button" onclick="closeShatterModal()" aria-label="<?php te('comune.chiudi'); ?>">&#10005;</button>

                <h3 style="color: var(--purple);"><?php te('fc.gm_frantumata'); ?></h3>

                <div id="shatter-content"></div>

                <div id="shatter-actions" class="sheet-actions" style="display:none;">

                    <a href="#" id="shatter-wrong-btn" class="btn gc-wrong action-btn" onclick="closeShatterModal()" aria-label="<?php te('fc.vg_sbagliato'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></a>

                    <a href="#" id="shatter-correct-btn" class="btn gc-correct action-btn" onclick="closeShatterModal()" aria-label="<?php te('fc.res_corretto'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg></a>

                </div>

            </div>

        </div>



        <div id="token-limit-modal" style="display:none;">

            <div class="sheet-panel" style="text-align:center;">

                <button class="sheet-close" type="button" onclick="closeTokenLimitModal()" aria-label="<?php te('comune.chiudi'); ?>">&#10005;</button>

                <div class="limit-icon">⚡️</div>

                <h3 style="text-align:center;"><?php te('fc.gm_token_finiti'); ?></h3>

                <div class="limit-modal-msg" id="token-limit-message"><?php te('fc.gm_limite_mensile'); ?></div>

                <div class="limit-modal-sub"><?php te('fc.gm_limite_azzera'); ?></div>

                <button class="btn btn-primary" type="button" onclick="closeTokenLimitModal()"><?php te('fc.gm_ho_capito'); ?></button>

            </div>

        </div>

        <div id="api-slow-toast" class="api-slow-toast"><span>⏳</span><span><?php te('fc.gm_google_lente'); ?></span></div>

        <div id="offlineIndicator" class="offline-indicator" style="display:none;">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M8.5 16.5a5 5 0 0 1 7 0M5 12.5a10 10 0 0 1 3-2.1M19 12.5a10 10 0 0 0-3.4-2.4M2 8.8a15 15 0 0 1 4-2.6M22 8.8a15 15 0 0 0-6-3.5M12 20h.01"/></svg>
            <span><?php te('fc.gm_offline'); ?></span>
        </div>

        <div id="pendingToast" class="pending-toast"></div>

        <!-- Elenco compatto degli errori: si apre dal pallino raggruppato sulla
             barra, e mostra l'inizio di ogni domanda sbagliata invece di una
             fila di pallini tutti uguali. -->
        <div id="erroriElenco" class="err-elenco" style="display:none;">
            <div class="err-elenco-testa">
                <span><?php te('fc.gm_errori_elenco'); ?></span>
                <button type="button" class="err-elenco-chiudi" onclick="chiudiElencoErrori()" aria-label="<?php te('comune.chiudi'); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="err-elenco-corpo">
                <?php
                /* Con piu' di una categoria si mostra il nome come intestazione
                   ogni volta che cambia: scorrendo si capisce sempre in quale
                   zona della partita ci si trova, coerente coi pallini separati
                   sulla barra. Con una sola categoria non serve, e non compare. */
                $cat_precedente = null;
                $multi_categoria = count($segmenti_categorie) > 1;
                ?>
                <?php foreach ($errors_cards as $err_c):
                    $nome_cat = null;
                    if ($multi_categoria) {
                        foreach ($segmenti_categorie as $sg) {
                            if ($err_c['pos'] >= $sg['inizio'] && $err_c['pos'] <= $sg['fine']) { $nome_cat = $sg['nome']; break; }
                        }
                    }
                ?>
                    <?php if ($multi_categoria && $nome_cat !== $cat_precedente): $cat_precedente = $nome_cat; ?>
                        <div class="err-voce-categoria"><?php echo sec_h($nome_cat ?? ''); ?></div>
                    <?php endif; ?>
                    <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo sec_h($err_c['pos']); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="err-voce action-btn">
                        <span class="err-voce-num<?php echo !empty($err_c['is_child']) ? ' figlio' : ''; ?>"><?php echo (int)$err_c['pos']; ?></span>
                        <span class="err-voce-testo"><?php echo sec_h($err_c['testo'] !== '' ? $err_c['testo'] : '—'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>



        <main class="feed">

            <div class="feed-inner">



                <?php foreach ($cards_to_show as $index => $c): 

                    $is_graded = ($c['user_answer'] != '');
                    $edit_param = $c['is_child'] ? 'child_id=' . abs($c['gameplay_idimmagine']) : 'fc_id=' . $c['gameplay_idimmagine'];

                ?>

                <?php if (!empty($c['is_titolo'])): ?>

                    <div class="card-title-header">

                        <?php if (!empty(trim($c['question_image'] ?? ''))): ?>
                            <img src="<?php echo fc_cartella_immagini() . sec_h(trim((string)($c['question_image'] ?? ''))); ?>" class="fc-image">
                        <?php endif; ?>

                        <?php fc_gioco_testo($c, 'q', 'raw-text'); ?>

                    </div>

                <?php continue; endif; ?>

                <div class="card-box <?php echo $is_graded ? 'graded' : ''; ?><?php if ($is_graded) echo ($c['user_answer'] == 'Correct') ? ' res-correct' : ' res-wrong'; ?><?php if (count($cards_to_show) <= 1) echo ' senza-etichetta'; ?><?php echo $c['is_favorite'] ? ' is-favorita' : ''; ?>" id="card-<?php echo sec_h($index); ?>"

                     data-card-id="<?php echo sec_h($c['gameplay_idimmagine']); ?>"

                     data-card-index="<?php echo (int)($card_index_map[$position . ':' . ($c['is_child'] ? 'c' : 'p') . $c['gameplay_idimmagine']] ?? $position); ?>"

                     data-qaudio="<?php echo sec_h($c['question_audio']); ?>" data-aaudio="<?php echo sec_h($c['answer_audio']); ?>"
                     <?php if (true): $fcg_k = ($c['is_child'] ? 'c' : 'p') . (int)$c['gameplay_idimmagine']; ?>data-fcv-stats="<?php echo sec_h(json_encode(($fcg_stats[$fcg_k] ?? ['c' => 0, 'w' => 0, 'u' => '']) + ['k' => $fcg_k])); ?>"<?php endif; ?>

                     <?php if (!$is_graded): ?>

                     data-swipe="1"

                     data-url-wrong="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position); ?>&action=grade&img_id=<?php echo sec_h($c['gameplay_idimmagine']); ?>&is_child=<?php echo $c['is_child'] ? 1 : 0; ?>&result=wrong&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>"

                     data-url-correct="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position); ?>&action=grade&img_id=<?php echo sec_h($c['gameplay_idimmagine']); ?>&is_child=<?php echo $c['is_child'] ? 1 : 0; ?>&result=correct&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>"

                     <?php endif; ?>>

                    <button type="button" class="btn-edit-fab" onclick="event.stopPropagation(); location.href='fc_card_edit.php?<?php echo sec_h($edit_param . $fc_qs_corso); ?>&return_to_game=1&game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>';" title="<?php te('comune.modifica'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></button>

                    <button type="button" class="btn-fav-fab<?php echo $c['is_favorite'] ? ' is-active' : ''; ?>" data-learnt-id="<?php echo sec_h($c['gameplay_idimmagine']); ?>" data-is-child="<?php echo $c['is_child'] ? '1' : '0'; ?>" onclick="event.stopPropagation(); toggleFavorite(this)" aria-label="<?php te('fc.gm_preferiti'); ?>" aria-pressed="<?php echo $c['is_favorite'] ? 'true' : 'false'; ?>" title="<?php te('fc.gm_preferiti'); ?>"><svg viewBox="0 0 24 24" fill="<?php echo $c['is_favorite'] ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2.5 3 6.6 7.2.8-5.4 4.9 1.5 7.2L12 18.2l-6.3 3.8 1.5-7.2-5.4-4.9 7.2-.8Z"/></svg></button>



                    <?php if(count($cards_to_show) > 1): ?>

                        <div class="card-title <?php echo $c['is_child'] ? 'is-child' : ''; ?>"><?php echo $c['is_child'] ? '&#8627; ' . sec_h(t('fc.gm_sottoconcetto')) : sec_h(t('fc.gm_concetto_principale')); ?></div>

                    <?php endif; ?>



                    <div class="q-section" id="q-section-<?php echo sec_h($index); ?>" onclick="playAudioFC(<?php echo sec_h($index); ?>, 'Q')" style="cursor: pointer;">

                        <div class="section-title"><?php echo fc_gioco_etichetta($c, 'q'); ?></div>

                        <?php if (!empty($c['question_audio'])): ?>

                            <audio id="player_q_<?php echo sec_h($index); ?>" src="../media/flashcard_audio/<?php echo sec_h($c['question_audio']); ?>" controls onclick="event.stopPropagation();"></audio>

                        <?php endif; ?>

                        <?php if (!empty(trim((string)($c['question_image'] ?? '')))): ?>

                            <img src="<?php echo fc_cartella_immagini() . sec_h(trim((string)($c['question_image'] ?? ''))); ?>" class="fc-image">

                        <?php endif; ?>

                        <?php fc_gioco_testo($c, 'q'); ?>
                        <?php fc_gioco_indizi($c); ?>

                    </div>



                    <?php if (!$is_graded): ?>

                        <?php if (!$fc_vocab): /* l'IA per le parole arrivera' piu' avanti */ ?>
                        <div class="tool-row">

                            <button type="button" class="chip-shatter" onclick="shatterCard(<?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_frantuma'); ?></button>

                        </div>
                        <?php endif; ?>



                        <button id="btn-show-<?php echo sec_h($index); ?>" class="btn btn-primary" onclick="revealCard(<?php echo sec_h($index); ?>)"><?php te('fc.gm_mostra_risposta'); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>



                        <div class="answer-box ans-block" id="ans-section-<?php echo sec_h($index); ?>" style="display:none;">

                            <div class="section-title st-answer"><?php echo fc_gioco_etichetta($c, 'a'); ?></div>

                            <?php if (!empty($c['answer_audio'])): ?>

                                <audio id="player_a_<?php echo sec_h($index); ?>" src="../media/flashcard_audio/<?php echo sec_h($c['answer_audio']); ?>" controls></audio>

                            <?php endif; ?>

                            <?php if (!empty(trim((string)($c['answer_image'] ?? '')))): ?>

                                <img src="<?php echo fc_cartella_immagini() . sec_h(trim((string)($c['answer_image'] ?? ''))); ?>" class="fc-image">

                            <?php endif; ?>

                            <?php fc_gioco_testo($c, 'a'); ?>

                            <?php render_examples_section($c['gameplay_idimmagine'], $c['is_child'] ? 'child' : 'parent', $c['gameplay_idimmagine'], [$c['gameplay_idimmagine'] => $c['examples_count']]); ?>

                            <!-- BOTTONI AI TUTOR -->

                            <?php 

                                // Preparazione sicura per il Javascript

                                $qTextJs = "document.querySelector('#q-section-{$index} .raw-text') ? document.querySelector('#q-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                                $aTextJs = "document.querySelector('#ans-section-{$index} .raw-text') ? document.querySelector('#ans-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                            ?>

                            <?php if (!$fc_vocab): /* lingue: restava solo il tasto «Esempi (n)», ma gli esempi sono gia' aperti sopra */ ?>
                            <div class="ai-tools-box">

                                <?php if (!$fc_vocab): ?><span class="ai-spark"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span><?php endif; ?>



                                <?php if($c['examples_count'] > 0): ?>

                                    <button id="btn-ex-<?php echo $c['is_child'] ? 'c' : 'p'; ?><?php echo sec_h($c['gameplay_idimmagine']); ?>" type="button" class="nav-btn chip-ai has-ex" onclick="event.stopPropagation(); openExamplesModal(<?php echo sec_h($c['gameplay_idimmagine']); ?>, '<?php echo $c['is_child'] ? 'child' : 'parent'; ?>', <?php echo sec_h($qTextJs); ?>, <?php echo sec_h($aTextJs); ?>)"><?php te('fc.gm_n_esempi', ['n' => (int)$c['examples_count']]); ?></button>

                                <?php else: ?>

                                    <button id="btn-ex-<?php echo $c['is_child'] ? 'c' : 'p'; ?><?php echo sec_h($c['gameplay_idimmagine']); ?>" type="button" class="nav-btn chip-ai" onclick="event.stopPropagation(); openExamplesModal(<?php echo sec_h($c['gameplay_idimmagine']); ?>, '<?php echo $c['is_child'] ? 'child' : 'parent'; ?>', <?php echo sec_h($qTextJs); ?>, <?php echo sec_h($aTextJs); ?>)"><?php te('fc.gm_aggiungi_esempio'); ?></button>

                                <?php endif; ?>



                                <?php if (!$fc_vocab): ?><div class="ai-sep"></div>



                                <button type="button" class="nav-btn chip-ai" onclick="playAI('read', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_leggi'); ?></button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('explain', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_spiega'); ?></button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('metaphor', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_metafora'); ?></button><?php endif; ?>

                            </div>
                            <?php endif; ?>



                            <div class="grade-row">

                                <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position); ?>&action=grade&img_id=<?php echo sec_h($c['gameplay_idimmagine']); ?>&is_child=<?php echo $c['is_child'] ? 1 : 0; ?>&result=wrong&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="btn gc-wrong action-btn" aria-label="<?php te('fc.vg_sbagliato'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></a>

                                <button type="button" class="btn gc-learnt<?php echo $c['is_learnt'] ? ' is-active' : ''; ?>" data-learnt-id="<?php echo sec_h($c['gameplay_idimmagine']); ?>" data-is-child="<?php echo $c['is_child'] ? '1' : '0'; ?>" onclick="event.stopPropagation(); toggleLearnt(this)" aria-label="<?php te('fc.gm_imparato'); ?>" aria-pressed="<?php echo $c['is_learnt'] ? 'true' : 'false'; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="M6 10.5V16c0 1 2.7 3 6 3s6-2 6-3v-5.5"/><path d="M22 8v6"/></svg></button>

                                <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position); ?>&action=grade&img_id=<?php echo sec_h($c['gameplay_idimmagine']); ?>&is_child=<?php echo $c['is_child'] ? 1 : 0; ?>&result=correct&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="btn gc-correct action-btn" aria-label="<?php te('fc.res_corretto'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg></a>

                            </div>



                        </div>



                    <?php else: ?>



                        <?php 

                        if ($c['user_answer'] == 'Correct') {

                            $msg = T('fc.lbl_corretto');

                        } else {

                            $msg = T('fc.lbl_errato');

                        }

                        ?>

                        <div class="graded-head">

                            <div class="status-pill <?php echo ($c['user_answer'] == 'Correct') ? 'ok' : 'ko'; ?>"><span class="gi"><?php echo ($c['user_answer'] == 'Correct') ? '&#10003;' : '&#10005;'; ?></span> <?php echo sec_h($msg); ?></div>

                            <button class="nav-btn pill-toggle action-btn" type="button" onclick="toggleAnswer(<?php echo sec_h($index); ?>, this)"><?php te('fc.gm_mostra_risposta'); ?></button>

                        </div>



                        <div class="answer-box ans-block is-revealed" id="graded-ans-<?php echo sec_h($index); ?>" style="display:none; margin-top:12px;">

                            <div class="section-title st-answer"><?php echo fc_gioco_etichetta($c, 'a'); ?></div>

                            <?php if (!empty($c['answer_audio'])): ?>

                                <audio id="player_a_<?php echo sec_h($index); ?>" src="../media/flashcard_audio/<?php echo sec_h($c['answer_audio']); ?>" controls></audio>

                            <?php endif; ?>

                            <?php if (!empty(trim((string)($c['answer_image'] ?? '')))): ?>

                                <img src="<?php echo fc_cartella_immagini() . sec_h(trim((string)($c['answer_image'] ?? ''))); ?>" class="fc-image">

                            <?php endif; ?>

                            <?php fc_gioco_testo($c, 'a'); ?>

                            <?php render_examples_section($c['gameplay_idimmagine'], $c['is_child'] ? 'child' : 'parent', $c['gameplay_idimmagine'], [$c['gameplay_idimmagine'] => $c['examples_count']]); ?>

                            <?php 

                                $qTextJs = "document.querySelector('#q-section-{$index} .raw-text') ? document.querySelector('#q-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                                $aTextJs = "document.querySelector('#graded-ans-{$index} .raw-text') ? document.querySelector('#graded-ans-{$index} .raw-text').getAttribute('data-raw') : ''";

                            ?>

                            <?php if (!$fc_vocab): /* lingue: restava solo il tasto «Esempi (n)», ma gli esempi sono gia' aperti sopra */ ?>
                            <div class="ai-tools-box">

                                <?php if (!$fc_vocab): ?><span class="ai-spark"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span><?php endif; ?>



                                <?php if($c['examples_count'] > 0): ?>

                                    <button id="btn-ex-graded-<?php echo $c['is_child'] ? 'c' : 'p'; ?><?php echo sec_h($c['gameplay_idimmagine']); ?>" type="button" class="nav-btn chip-ai has-ex" onclick="event.stopPropagation(); openExamplesModal(<?php echo sec_h($c['gameplay_idimmagine']); ?>, '<?php echo $c['is_child'] ? 'child' : 'parent'; ?>', <?php echo sec_h($qTextJs); ?>, <?php echo sec_h($aTextJs); ?>)"><?php te('fc.gm_n_esempi', ['n' => (int)$c['examples_count']]); ?></button>

                                <?php else: ?>

                                    <button id="btn-ex-graded-<?php echo $c['is_child'] ? 'c' : 'p'; ?><?php echo sec_h($c['gameplay_idimmagine']); ?>" type="button" class="nav-btn chip-ai" onclick="event.stopPropagation(); openExamplesModal(<?php echo sec_h($c['gameplay_idimmagine']); ?>, '<?php echo $c['is_child'] ? 'child' : 'parent'; ?>', <?php echo sec_h($qTextJs); ?>, <?php echo sec_h($aTextJs); ?>)"><?php te('fc.gm_aggiungi_esempio'); ?></button>

                                <?php endif; ?>



                                <?php if (!$fc_vocab): ?><div class="ai-sep"></div>



                                <button type="button" class="nav-btn chip-ai" onclick="playAI('read', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_leggi'); ?></button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('explain', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_spiega'); ?></button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('metaphor', <?php echo sec_h($index); ?>, <?php echo sec_h($c['gameplay_idimmagine']); ?>, this)"><?php te('fc.gm_metafora'); ?></button><?php endif; ?>

                            </div>
                            <?php endif; ?>

                        </div>



                    <?php endif; ?>

                </div>

                <?php endforeach; ?>



                <div class="nav-row">

                    <?php if ($position > 1): ?>

                        <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position-1); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="nav-btn action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg> <?php te('comune.indietro'); ?></a>

                    <?php endif; ?>



                    <?php /* Avanti solo quando qui non c'e' piu' niente da rispondere:
                             su una carta ancora da valutare offrire "Avanti" invita
                             a saltarla, e la posizione resterebbe indietro. */ ?>
                    <?php if ($all_answered && $position < $max_position_generated): ?>

                        <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position+1); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="nav-btn nav-fwd action-btn"><?php te('fc.gm_avanti'); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>

                    <?php elseif ($all_answered && $position < $total_words): ?>

                        <a href="fc_game.php?game_id=<?php echo (int)($game_id); ?>&position=<?php echo (int)($position+1); ?>&status=<?php echo sec_h($status); ?>&order=<?php echo sec_h($order_param); ?>" class="nav-btn nav-fwd action-btn"><?php te('fc.gm_avanti'); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>

                    <?php endif; ?>

                </div>



                <div id="end-game-section">

                    <button type="button" id="btn-concludi-1" class="btn btn-ghost end-btn" onclick="mostraConfermaFinale()"><?php te('fc.gm_concludi'); ?></button>

                    <div id="conferma-finale-box" class="card-warn" style="display: none;">

                        <p><?php te('fc.gm_conferma_concludi'); ?></p>

                        <button type="button" class="btn btn-red end-link"
                                onclick="standoutAzione('fc_game.php?game_id=<?php echo (int)($game_id); ?>', {action: 'concludi', game_id: <?php echo (int)($game_id); ?>})"><?php te('fc.gm_si_risultati'); ?></button>

                        <button type="button" class="nav-btn" onclick="annullaChiusura()"><?php te('fc.gm_no_continua'); ?></button>

                    </div>

                </div>



            </div>

        </main>

    </div> 



    <script>

        // ==========================================

        // GESTIONE POPUP IMMAGINI

        // ==========================================

        document.addEventListener('click', function(e) {

            if (window.__standoutModaleAperto) return;

            /* Anche le immagini dentro un esempio si aprono a schermo intero:
               prima reagiva solo a .fc-image, quindi quelle degli esempi
               (.qa-example-image) non si aprivano affatto. */
            if (e.target && (e.target.classList.contains('fc-image') || e.target.classList.contains('qa-example-image'))) {

                document.getElementById('overlay-img').src = e.target.src;

                document.getElementById('image-overlay').style.display = 'flex';

                document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover');

            }

        });



        function chiudiOverlayImmagine() {
            document.getElementById('image-overlay').style.display = 'none';
            document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover');
        }

        document.getElementById('close-overlay').addEventListener('click', chiudiOverlayImmagine);

        /* Trascinamento verso il basso per chiudere l'immagine a schermo intero,
           come nei visori di iOS: segue il dito, si chiude se si scende
           abbastanza o si lascia andare di slancio, altrimenti torna su. */
        (function () {
            var ov = document.getElementById('image-overlay');
            var y0 = 0, dy = 0, attivo = false, deciso = '', t0 = 0;

            ov.addEventListener('touchstart', function (e) {
                if (e.touches.length !== 1) return;   /* con due dita si sta ingrandendo */
                attivo = true; deciso = ''; dy = 0;
                y0 = e.touches[0].clientY; t0 = Date.now();
            }, { passive: true });

            ov.addEventListener('touchmove', function (e) {
                if (!attivo || e.touches.length !== 1) return;
                dy = e.touches[0].clientY - y0;
                if (!deciso) {
                    if (Math.abs(dy) < 8) return;
                    deciso = dy > 0 ? 'giu' : 'no';
                }
                if (deciso !== 'giu') return;
                e.preventDefault();
                var scala = Math.max(.85, 1 - dy / 1200);
                ov.style.transition = 'none';
                ov.style.transform = 'translateY(' + dy + 'px) scale(' + scala + ')';
                ov.style.opacity = String(Math.max(.3, 1 - dy / 500));
            }, { passive: false });

            function fine() {
                if (!attivo) return;
                attivo = false;
                if (deciso !== 'giu') return;
                var velocita = dy / Math.max(1, Date.now() - t0);
                ov.style.transition = 'transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease-out';
                if (dy > 120 || velocita > 0.6) {
                    ov.style.transform = 'translateY(100vh) scale(.9)';
                    ov.style.opacity = '0';
                    setTimeout(function () {
                        chiudiOverlayImmagine();
                        ov.style.transition = ''; ov.style.transform = ''; ov.style.opacity = '';
                    }, 200);
                } else {
                    ov.style.transform = ''; ov.style.opacity = '';
                }
                dy = 0;
            }
            ov.addEventListener('touchend', fine);
            ov.addEventListener('touchcancel', fine);
        })();



        // ==========================================

        // UI & LOGICA DI GIOCO

        // ==========================================

        function mostraConfermaFinale() {

            document.getElementById('btn-concludi-1').style.display = 'none';

            document.getElementById('conferma-finale-box').style.display = 'block';

            var fbox = document.querySelector('.container:last-of-type .feed');

            if (fbox) { fbox.scrollTo({ top: fbox.scrollHeight, behavior: 'smooth' }); }

            else { window.scrollTo(0, document.body.scrollHeight); }

        }

        function annullaChiusura() {

            document.getElementById('btn-concludi-1').style.display = 'block';

            document.getElementById('conferma-finale-box').style.display = 'none';

        }



        // ==========================================

        // MOTORE TTS E AUDIO AVANZATO (FIX iOS, AJAX & LaTeX)

        // ==========================================

        

        // 1. Configurazione di default e caricamento

        let ttsSettings = JSON.parse(localStorage.getItem('ttsSettings')) || { mode: 'no', lang: 'it', speed: 1, readMath: 'yes', aiVoice: 'it-IT-Chirp3-HD-Charon', nativeVoice: '' };

        if (typeof ttsSettings.nativeVoice === 'undefined') ttsSettings.nativeVoice = '';

        // Ogni volta che si inizia o si riprende una partita si riparte da fermi:
        // lettura automatica spenta e velocità normale. Sono scelte "della sessione",
        // non preferenze permanenti come lingua, voce o lettura del LaTeX (quelle
        // restano salvate). Questo script viene eseguito solo al caricamento vero
        // della pagina: cambiando card con lo swipe le impostazioni restano quelle
        // scelte dall'utente durante la partita in corso.
        ttsSettings.mode = 'no';
        ttsSettings.speed = 1;
        delete ttsSettings.engine; // impostazione rimossa: per la voce IA c'è già "Leggi"
        try { localStorage.setItem('ttsSettings', JSON.stringify(ttsSettings)); } catch (e) {}

        let availableVoices = [];

        // getVoices() restituisce spesso un array vuoto alla prima chiamata: l'elenco
        // arriva in modo asincrono. Ricarichiamolo sia subito che a ogni notifica del
        // browser, così la scelta della voce migliore non ricade sul default robotico
        // solo perché l'elenco non era ancora pronto.
        function refreshVoices() {
            // Tutto incapsulato: il menu delle voci è una comodità, non deve MAI
            // poter buttare giù il resto della pagina se qualcosa va storto qui
            // (è esattamente ciò che è successo con l'errore TDZ su Safari:
            // un problema nel selettore voci rendeva inutilizzabile tutto il sito).
            try {
                let v = window.speechSynthesis.getVoices();
                if (v && v.length) {
                    availableVoices = v;
                    populateNativeVoiceSelect();
                }
            } catch (e) {
                console.warn(T('fc.gm_voci_non_disponibili'), e);
            }
        }



        // Restituisce l'ultimo contenitore di gioco presente (durante le transizioni
        // AJAX ce ne sono due per ~mezzo secondo: quello vecchio in uscita e quello
        // nuovo). Filtriamo su [data-pos] perché è l'attributo che identifica i
        // contenitori del gioco: un eventuale altro elemento con classe "container"
        // proveniente da un file incluso (modali, player, voice mode...) verrebbe
        // altrimenti scambiato per il contenitore corrente, e la ricerca della card
        // dentro di esso fallirebbe in silenzio.
        function currentContainer() {
            let all = document.querySelectorAll('.container[data-pos]');
            if (!all.length) all = document.querySelectorAll('.container');
            return all[all.length - 1] || null;
        }

        // ATTENZIONE: prima questa funzione contava le .card-box di TUTTO il documento.
        // Durante una transizione AJAX il contenitore vecchio è ancora nel DOM insieme
        // al nuovo, quindi le card risultavano 2+ e la funzione diceva "ci sono
        // sotto-concetti" anche quando non era vero: checkAutoPlayQuestion() usciva
        // subito e la domanda non veniva MAI letta dopo uno swipe. Contiamo solo
        // dentro il contenitore giusto.
        function checkHasChildren(scope) {

            let cont = scope || currentContainer() || document;

            return cont.querySelectorAll('.card-box:not(.anteprima-card)').length > 1;

        }



        let audioUnlocked = false;

        // La lettura in attesa di un tocco dell'utente: i browser (Safari e Chrome in
        // testa) rifiutano di far partire la sintesi vocale finché non c'è stata almeno
        // un'interazione con la pagina. Al primo caricamento quindi la domanda non
        // partiva comunque, in silenzio. Ora la teniamo da parte e la facciamo partire
        // appena l'utente tocca lo schermo.
        let pendingAutoRead = null;

        function unlockAudio() {

            if (audioUnlocked) return;

            audioUnlocked = true;

            try {

                let silentMsg = new SpeechSynthesisUtterance('');

                silentMsg.volume = 0;

                window.speechSynthesis.speak(silentMsg);

            } catch (e) {}

            if (pendingAutoRead) {

                let fn = pendingAutoRead;

                pendingAutoRead = null;

                setTimeout(fn, 60);

            }

        }

        document.addEventListener('click', unlockAudio);

        document.addEventListener('touchstart', unlockAudio, { passive: true });

        document.addEventListener('keydown', unlockAudio);



        window.speechSynthesis.onvoiceschanged = refreshVoices;

        refreshVoices();

        // Alcuni browser (Safari in testa) non emettono mai onvoiceschanged: riproviamo
        // qualche volta nei primi secondi invece di restare con l'elenco vuoto.
        [200, 600, 1500, 3000].forEach(ms => setTimeout(refreshVoices, ms));



        // 2. EVENT DELEGATION

        document.addEventListener('click', function(e) {

            if (window.__standoutModaleAperto) return;

            let ttsBtn = e.target.closest('#ttsSettingsBtn');

            if (ttsBtn) {

                document.getElementById('tts-mode').value = ttsSettings.mode;

                document.getElementById('tts-lang').value = ttsSettings.lang;

                // La velocità va impostata in modo difensivo: il player flottante
                // permette valori (es. 2) che prima non esistevano tra le opzioni di
                // questo menu, e assegnare un valore senza <option> corrispondente
                // lascia il select VUOTO. In quel caso torniamo allo standard x1.
                let speedSel = document.getElementById('tts-speed');

                speedSel.value = String(ttsSettings.speed || 1);

                if (!speedSel.value) { speedSel.value = '1'; ttsSettings.speed = 1; }

                document.getElementById('tts-math').value = ttsSettings.readMath || 'yes';

                document.getElementById('tts-ai-voice').value = ttsSettings.aiVoice || 'it-IT-Chirp3-HD-Charon'; // <-- NUOVO

                refreshVoices();

                populateNativeVoiceSelect();

                // In modalità microfono è la modalità vocale a decidere cosa leggere e
                // quando: lasciare il menu "Modalità" attivo darebbe l'illusione di
                // poterlo cambiare, mentre il valore verrebbe comunque ignorato.
                let vocaleOn = !!(window.VoiceGameMode && typeof window.VoiceGameMode.isActive === 'function' && window.VoiceGameMode.isActive());

                let modeSel = document.getElementById('tts-mode');

                let modeHint = document.getElementById('tts-mode-hint');

                modeSel.disabled = vocaleOn;

                modeSel.style.opacity = vocaleOn ? '.5' : '';

                if (modeHint) modeHint.style.display = vocaleOn ? 'block' : 'none';

                document.getElementById('tts-modal').style.display = 'flex';

                return;

            }



            let saveBtn = e.target.closest('#tts-save');

            if (saveBtn) {

                // In modalità vocale (microfono) il flusso è guidato da voice_game_mode,
                // che ha bisogno di mode='auto' e della catena di callback della voce.
                // Salvare le impostazioni non deve né sovrascrivere quella modalità né
                // interrompere la lettura in corso: altrimenti aprire questo popup
                // durante la modalità microfono spegneva tutto e il player spariva.
                let vocaleAttiva = !!(window.VoiceGameMode && typeof window.VoiceGameMode.isActive === 'function' && window.VoiceGameMode.isActive());

                let vecchiaVelocita = parseFloat(ttsSettings.speed) || 1;

                ttsSettings = {

                    mode: vocaleAttiva ? 'auto' : document.getElementById('tts-mode').value,

                    lang: document.getElementById('tts-lang').value,

                    speed: parseFloat(document.getElementById('tts-speed').value) || 1,

                    readMath: document.getElementById('tts-math').value,

                    aiVoice: document.getElementById('tts-ai-voice').value, // <-- NUOVO

                    nativeVoice: document.getElementById('tts-voice') ? document.getElementById('tts-voice').value : ''

                };

                localStorage.setItem('ttsSettings', JSON.stringify(ttsSettings));

                document.getElementById('tts-modal').style.display = 'none';

                // Salvare NON deve interrompere quello che si sta ascoltando: prima si
                // fermava sempre la lettura anche solo aprendo e richiudendo il popup
                // senza cambiare nulla. Fermiamo solo se l'utente ha davvero spento la
                // lettura; le altre modifiche (voce, lingua, LaTeX) valgono da quella
                // successiva, perché la frase in corso è già stata sintetizzata.
                if (ttsSettings.mode === 'no') {

                    stopNativeTTS();

                } else if (parseFloat(ttsSettings.speed) !== vecchiaVelocita && window.isSpeakingNative) {

                    // La velocità invece si può applicare subito: stessa tecnica del
                    // player flottante, si mette in pausa e si riprende dal punto esatto.
                    pauseNativeTTS();

                    setTimeout(function () { resumeNativeTTS(); }, 100);

                }

                return;

            }



            let ansBlock = e.target.closest('.ans-block');

            if (ansBlock && ttsSettings.mode === 'click') {

                let match = ansBlock.id.match(/\d+$/);

                if (match) playAudioFC(match[0], 'A');

            }

        });



        // Cambiando lingua nelle impostazioni, l'elenco delle voci del dispositivo
        // deve seguire: le voci italiane non servono a leggere in inglese.
        document.addEventListener('change', function(e) {
            if (!e.target) return;
            if (e.target.id === 'tts-lang') {
                let prev = ttsSettings.lang;
                ttsSettings.lang = e.target.value;
                let sel = document.getElementById('tts-voice');
                if (sel && prev !== e.target.value) ttsSettings.nativeVoice = '';
                populateNativeVoiceSelect();
            }
            if (e.target.id === 'tts-voice') {
                ttsSettings.nativeVoice = e.target.value;
                aggiornaAvvisoVoce();
            }
            if (e.target.id === 'tts-speed') {
                aggiornaAvvisoVoce();
            }
        });



        // 3. PARSER LaTeX AVANZATO E DEFINITIVO (V3)

        function sanitizeMathForTTS(text, lang, readMath) {

            let it = (lang === 'it');



            // Se l'utente seleziona "No", sostituisci tutto il blocco matematico

            if (readMath === 'no') {

                return text.replace(/\$\$(.*?)\$\$/gs, it ? " formula. " : " formula. ");

            }



            // Altrimenti, analizza e traduci il LaTeX

            return text.replace(/\$\$(.*?)\$\$|\$(.*?)\$/gs, (match, blockEq, inlineEq) => {

                let eq = blockEq || inlineEq; // Prende il contenuto di uno dei due

                let s = eq;



                // 1. Strutture complesse (Consumano porzioni grandi di testo)

                s = s.replace(/\\lim_\{?(.*?)\\to(.*?)\}?/g, it ? " limite per $1 che tende a $2 di " : " limit as $1 approaches $2 of ");

                s = s.replace(/\\int_\{?(.*?)\}\^\{?(.*?)\}?/g, it ? " integrale da $1 a $2 di " : " integral from $1 to $2 of ");

                s = s.replace(/\\sum_\{?(.*?)\}\^\{?(.*?)\}?/g, it ? " sommatoria per $1 fino a $2 di " : " sum from $1 to $2 of ");

                

                // 2. Frazioni e Radici (Consumano le proprie graffe interne)

                s = s.replace(/\\frac\{(.*?)\}\{(.*?)\}/g, it ? " $1 fratto $2 " : " $1 over $2 ");

                s = s.replace(/\\sqrt\[(.*?)\]\{?(.*?)\}?/g, it ? " radice $1 di $2 " : " $1 root of $2 "); 

                s = s.replace(/\\sqrt\{?(.*?)\}?/g, it ? " radice di $1 " : " square root of $1 ");

                

                // 3. Potenze e Pedici (Consumano le proprie graffe interne)

                s = s.replace(/\^2/g, it ? " alla seconda " : " squared ");

                s = s.replace(/\^3/g, it ? " alla terza " : " cubed ");

                s = s.replace(/\^\{(.*?)\}/g, it ? " elevato alla $1 " : " to the power of $1 ");

                s = s.replace(/\^/g, it ? " elevato a " : " to the power of ");

                s = s.replace(/_\{?(.*?)\}?/g, " $1 ");  // Rimosso "pedice"

                s = s.replace(/_/g, " "); // Rimosso "pedice"

                

                // 4. SIMBOLI LUNGHI (Rigorasamente PRIMA di quelli corti per evitare collisioni)

                s = s.replace(/\\infty/g, it ? " infinito " : " infinity "); // Deve precedere \in

                s = s.replace(/\\notin/g, it ? " non appartiene a " : " does not belong to "); // Deve precedere \in

                s = s.replace(/\\nexists/g, it ? " non esiste " : " there does not exist ");

                s = s.replace(/\\subseteq/g, it ? " sottoinsieme o uguale a " : " subset or equal to ");

                s = s.replace(/\\Rightarrow|\\implies/g, it ? " implica che " : " implies ");

                s = s.replace(/\\rightarrow/g, it ? " tende a " : " right arrow ");

                s = s.replace(/\\approx/g, it ? " circa uguale a " : " approximately ");

                s = s.replace(/\\geq/g, it ? " maggiore o uguale a " : " greater than or equal to ");

                s = s.replace(/\\leq/g, it ? " minore o uguale a " : " less than or equal to ");

                s = s.replace(/\\partial/g, it ? " derivata parziale " : " partial derivative ");

                s = s.replace(/\\to|->/g, it ? " tende a " : " goes to ");



                // 5. SIMBOLI CORTI (Ora possono essere tradotti in sicurezza)

                s = s.replace(/\\in/g, it ? " appartiene a " : " belongs to "); 

                s = s.replace(/\\int/g, it ? " integrale di " : " integral of ");

                s = s.replace(/\\sum/g, it ? " sommatoria di " : " sum of ");

                s = s.replace(/\\exists/g, it ? " esiste " : " there exists ");

                s = s.replace(/\\subset/g, it ? " sottoinsieme di " : " subset of ");

                s = s.replace(/\\cup/g, it ? " unito a " : " union ");

                s = s.replace(/\\cap/g, it ? " intersecato con " : " intersection ");

                s = s.replace(/\\nabla/g, " nabla ");

                s = s.replace(/\\pm/g, it ? " più o meno " : " plus or minus ");

                s = s.replace(/\\times/g, " per ");

                s = s.replace(/\\div/g, it ? " diviso " : " divided by ");

                s = s.replace(/!/g, it ? " fattoriale " : " factorial ");

                s = s.replace(/\\to/g, it ? " tende a " : " goes to ");



                // 6. Funzioni e Operatori Base

                s = s.replace(/\\sin/g, " seno di ");

                s = s.replace(/\\cos/g, " coseno di ");

                s = s.replace(/\\tan/g, " tangente di ");

                s = s.replace(/\\log|\\ln/g, it ? " logaritmo " : " log ");

                s = s.replace(/\\pi/g, it ? " pi greco " : " pi ");

                

                s = s.replace(/=/g, it ? " uguale a " : " equals ");

                s = s.replace(/\+/g, " più ");

                s = s.replace(/-/g, " meno ");

                s = s.replace(/\\cdot|\*/g, " per ");

                s = s.replace(/\\neq/g, it ? " diverso da " : " not equal to ");

                s = s.replace(/\\ge/g, it ? " maggiore o uguale a " : " greater than or equal to ");

                s = s.replace(/\\le/g, it ? " minore o uguale a " : " less than or equal to ");

                s = s.replace(/>/g, it ? " maggiore di " : " greater than ");

                s = s.replace(/</g, it ? " minore di " : " less than ");



                // 7. Lettere greche

                s = s.replace(/\\alpha/g, " alfa ");

                s = s.replace(/\\beta/g, " beta ");

                s = s.replace(/\\gamma/g, " gamma ");

                s = s.replace(/\\delta/g, " delta ");

                s = s.replace(/\\epsilon|\\varepsilon/g, " epsilon ");

                s = s.replace(/\\theta/g, " theta ");

                s = s.replace(/\\omega/g, " omega ");

                s = s.replace(/\\lambda/g, " lambda ");

                s = s.replace(/\\sigma/g, " sigma ");

                s = s.replace(/\\mu/g, " mu ");

                s = s.replace(/\\rho/g, " ro ");



                // 8. GENERAZIONE PAUSE (Gestione Parentesi) e PULIZIA

                s = s.replace(/\\left|\\right/g, ""); // Rimuove i comandi di resize prima delle parentesi

                s = s.replace(/\(|\)|\[|\]|\\\{|\\\}/g, ", "); // Converte tonde, quadre e graffe letterali in virgole (pausa)

                s = s.replace(/\{|\}/g, ", "); // Converte le graffe rimanenti (usate per raggruppare) in virgole (pausa)

                s = s.replace(/\\/g, " "); // Pulisce gli slash residui

                

                return s;

            });

        }



        // ==========================================
        // SCELTA DELLA VOCE DEL DISPOSITIVO (qualità)
        // ==========================================
        // Su iOS convivono più voci per la stessa lingua: quelle "compatte"
        // (Alice) sono molto robotiche, mentre le Enhanced/Premium/Siri sono
        // decisamente migliori — ma vanno scaricate da
        // Impostazioni > Accessibilità > Contenuto pronunciato > Voci.
        // Il vecchio codice prendeva la PRIMA voce che contenesse uno qualsiasi
        // tra "Siri/Premium/Enhanced/Alice/Google": siccome "Alice" (compatta)
        // di solito compare per prima nell'elenco, vinceva sempre lei anche
        // quando era disponibile "Alice (Enhanced)". Ora diamo un punteggio a
        // ogni voce e teniamo la migliore.
        // Voci "novità"/Eloquence: sono sintetizzatori vecchi (stile DECtalk), quelli
        // tremolanti e metallici. iOS ne espone parecchi (Eddy, Flo, Grandma, Rocko...),
        // macOS aggiunge le novelty (Zarvox, Bubbles, Trinoids...). Non vanno mai
        // scelte in automatico: le teniamo in fondo alla lista, in un gruppo a parte.
        //
        // ATTENZIONE (bug risolto): questo elenco stava in una `const` dichiarata QUI,
        // ma veniva usato da refreshVoices() che gira MOLTO più in alto nello script.
        // Su Chrome non si notava (getVoices() al primo giro torna un array vuoto,
        // quindi la funzione usciva subito e non toccava la costante), mentre Safari
        // restituisce le voci all'istante: la costante veniva letta prima di essere
        // inizializzata -> "ReferenceError: Cannot access uninitialized variable" ->
        // l'INTERO blocco <script> moriva e su iPhone non funzionava più nulla.
        // Tenendo la regex DENTRO la funzione il problema non può più ripetersi:
        // le dichiarazioni di funzione sono hoistate insieme al loro corpo.
        function voiceIsPoor(v) {
            const VOCI_SCARSE = /^(eddy|flo|grandma|grandpa|reed|rocko|sandy|shelley|albert|bad news|bahh|bells|boing|bubbles|cellos|good news|jester|organ|superstar|trinoids|whisper|wobble|zarvox|junior|ralph|kathy|fred|princess|deranged|hysterical|bruce|agnes|vicki|victoria)\b/i;
            let n = ((v && v.name) || '').trim();
            return VOCI_SCARSE.test(n) || /eloquence/i.test(n);
        }

        function voiceQualityScore(v) {
            let n = (v.name || '');
            let score = 0;
            if (voiceIsPoor(v))                    score -= 1000; // mai in automatico
            if (/premium/i.test(n))                score += 100;
            else if (/enhanced/i.test(n))          score += 90;
            else if (/neural|natural/i.test(n))    score += 85;
            else if (/siri/i.test(n))              score += 80;
            if (/compact/i.test(n))                score -= 60;
            if (v.localService === false)          score += 40; // voci di rete: di norma le migliori
            if (/google/i.test(n))                 score += 30;
            if (v.default)                         score += 5;
            return score;
        }

        function voicesForLang(lang) {
            let base = (lang === 'it') ? 'it' : 'en';
            return (availableVoices || []).filter(v => v.lang && v.lang.toLowerCase().startsWith(base));
        }

        function pickNativeVoice(lang) {
            let list = voicesForLang(lang);
            if (!list.length) return null;

            // 1. La voce scelta a mano dall'utente nelle impostazioni, se esiste ancora
            if (ttsSettings.nativeVoice) {
                let wanted = list.find(v => v.voiceURI === ttsSettings.nativeVoice || v.name === ttsSettings.nativeVoice);
                if (wanted) return wanted;
            }

            // 2. Altrimenti la migliore per punteggio (a parità, la prima dell'elenco)
            return list.slice().sort((a, b) => voiceQualityScore(b) - voiceQualityScore(a))[0];
        }

        // Riempie il menu a tendina "Voce del dispositivo", separando le voci
        // consigliate da quelle scadenti invece di mescolarle in un elenco unico.
        function populateNativeVoiceSelect() {
            let sel = document.getElementById('tts-voice');
            if (!sel) return;
            let lang = (ttsSettings.lang === 'auto') ? 'it' : ttsSettings.lang;
            let list = voicesForLang(lang).slice().sort((a, b) => voiceQualityScore(b) - voiceQualityScore(a));

            sel.innerHTML = '';
            let autoOpt = document.createElement('option');
            autoOpt.value = '';
            autoOpt.textContent = list.length ? T('fc.gm_auto_migliore') : T('fc.gm_nessuna_voce');
            sel.appendChild(autoOpt);

            function etichetta(v) {
                if (/premium/i.test(v.name)) return ' ⭐️ Premium';
                if (/enhanced/i.test(v.name)) return ' ⭐️ Enhanced';
                if (v.localService === false) return ' ☁️ ' + T('fc.gm_di_rete');
                return '';
            }
            function addTo(parent, v) {
                let o = document.createElement('option');
                o.value = v.voiceURI || v.name;
                o.textContent = v.name + etichetta(v);
                parent.appendChild(o);
            }

            let buone = list.filter(v => !voiceIsPoor(v));
            let scarse = list.filter(voiceIsPoor);

            if (buone.length) {
                let g = document.createElement('optgroup');
                g.label = 'Consigliate';
                buone.forEach(v => addTo(g, v));
                sel.appendChild(g);
            }
            if (scarse.length) {
                let g2 = document.createElement('optgroup');
                g2.label = T('fc.gm_bassa_qualita');
                scarse.forEach(v => addTo(g2, v));
                sel.appendChild(g2);
            }

            sel.value = ttsSettings.nativeVoice || '';
            if (sel.value !== (ttsSettings.nativeVoice || '')) sel.value = ''; // la voce salvata non esiste più

            aggiornaAvvisoVoce();
        }

        // Le voci di rete di Chrome ("Google italiano") sono le più belle ma hanno un
        // difetto noto del browser: oltre ~1.2x mangiano le parole. Non è una cosa che
        // si possa correggere da codice, quindi almeno avvisiamo invece di lasciare
        // credere che sia rotto il sito.
        function aggiornaAvvisoVoce() {
            let hint = document.getElementById('tts-voice-warn');
            if (!hint) return;
            let speedEl = document.getElementById('tts-speed');
            let speed = speedEl ? parseFloat(speedEl.value) : 1;
            let v = pickNativeVoice((ttsSettings.lang === 'auto') ? 'it' : ttsSettings.lang);
            let isRete = v && v.localService === false;
            if (isRete && speed > 1.2) {
                hint.style.display = 'block';
                hint.innerHTML = '⚠️ ' + T('fc.gm_voce_rete_avviso', { nome: v.name, leggi: T('fc.gm_leggi').replace(/^\S+\s/, '') });
            } else {
                hint.style.display = 'none';
            }
        }

        // ==========================================
        // PIPELINE DI PULIZIA TESTO PER LA LETTURA — UNICA E CONDIVISA
        // ==========================================
        // Qualunque testo destinato a essere LETTO (voce nativa del dispositivo
        // oppure audio generato da Google TTS in "leggi"/"spiega"/"metafora")
        // deve passare da qui. Altrimenti la voce finisce per pronunciare i
        // comandi di fc_parser ("slash bold graffa...") e il LaTeX grezzo
        // ("backslash frac...") invece del loro significato.
        //
        //   1. parseTextForPreview() trasforma i tag /bold{} /red{} /list{}... in HTML
        //   2. textContent butta via l'HTML e tiene solo il testo leggibile
        //   3. sanitizeMathForTTS() traduce le formule ($...$ e $$...$$) in parole
        //      ("\frac{a}{b}" -> "a fratto b"), o le sostituisce con "formula"
        //      se l'utente ha scelto di non farsi leggere il LaTeX.
        function ttsDetectLang(rawText) {
            let finalLang = ttsSettings.lang;
            if (finalLang === 'auto') {
                const itWords = /\b(il|lo|la|i|gli|le|un|uno|una|di|a|da|in|con|su|per|tra|fra|che|e|o|ma|se|perché)\b/i;
                finalLang = itWords.test(rawText || '') ? 'it' : 'en';
            }
            return finalLang;
        }

        function ttsCleanText(rawText, lang) {
            if (!rawText) return '';
            let div = document.createElement('div');
            try {
                div.innerHTML = (typeof parseTextForPreview === 'function') ? parseTextForPreview(rawText) : rawText;
            } catch (e) {
                div.textContent = rawText;
            }
            let cleanText = div.textContent || div.innerText || "";
            cleanText = sanitizeMathForTTS(cleanText, lang || ttsDetectLang(rawText), ttsSettings.readMath);
            // Le andate a capo diventano pause parlate, non righe vuote lette come nulla
            cleanText = cleanText.replace(/\n+/g, ", , ");
            // Spazi multipli lasciati dalle sostituzioni
            cleanText = cleanText.replace(/[ \t]{2,}/g, ' ').trim();
            return cleanText;
        }

        // 4. FUNZIONE CORE PER PARLARE

        // ==========================================

        // VARIABILI GLOBALI PER IL RIAVVIO DELLA VOCE NATIVA

        // ==========================================

        window.lastRawTextForTTS = "";

        window.currentCleanTextForTTS = "";

        window.lastTTSCallback = null;

        window.isSpeakingNative = false;

        window.currentTTSCharIndex = 0;

        window.currentUtterance = null;

        window.ttsFinalLang = "it";

        

        // NUOVE VARIABILI PER IL TRACCIAMENTO TEMPORALE (Fix Bug Smartphone)

        window.ttsStartTime = 0;

        window.ttsStartCharIndex = 0;

        window.onboundaryFired = false;



        // 4. FUNZIONE CORE PER PARLARE

        function speakText(rawText, onEndCallback = null, resumeFromIndex = 0) {

            if (!rawText || ttsSettings.mode === 'no') {

                if (onEndCallback) onEndCallback();

                return;

            }



            // Se partiamo da zero, elaboriamo il testo e lo salviamo

            if (resumeFromIndex === 0) {

                window.lastRawTextForTTS = rawText;

                window.lastTTSCallback = onEndCallback;

                window.currentTTSCharIndex = 0;



                let finalLang = ttsDetectLang(rawText);

                window.currentCleanTextForTTS = ttsCleanText(rawText, finalLang);

                window.ttsFinalLang = finalLang; 

            }



            // Se dopo la pulizia non è rimasto nulla da leggere (es. card di sola
            // immagine, o solo formule con "Leggi LaTeX" su No), non ha senso
            // mostrare il player: chiudiamo subito passando al callback.

            if (!window.currentCleanTextForTTS) {

                window.isSpeakingNative = false;

                window.currentUtterance = null;

                if (onEndCallback) onEndCallback();

                return;

            }



            window.currentUtterance = null; 

            window.speechSynthesis.cancel(); 

            window.isSpeakingNative = true;

            

            // Inizializziamo i timer per il calcolo infallibile

            window.ttsStartCharIndex = resumeFromIndex;

            window.ttsStartTime = Date.now();

            window.onboundaryFired = false;



            if (resumeFromIndex < 0) resumeFromIndex = 0;

            if (resumeFromIndex >= window.currentCleanTextForTTS.length) {

                resumeFromIndex = 0;

                window.currentTTSCharIndex = 0;

                window.ttsStartCharIndex = 0;

            }



            let textToSpeak = window.currentCleanTextForTTS.substring(resumeFromIndex);

            let utterance = new SpeechSynthesisUtterance(textToSpeak);

            window.currentUtterance = utterance; 



            let langCode = window.ttsFinalLang === 'it' ? 'it-IT' : 'en-US';

            utterance.lang = langCode;

            utterance.rate = ttsSettings.speed;

            utterance.pitch = 1; 



            let chosenVoice = pickNativeVoice(window.ttsFinalLang);

            if (chosenVoice) utterance.voice = chosenVoice;



            // Se il browser funziona bene, useremo le sue coordinate

            utterance.onboundary = (event) => {

                if (event.charIndex !== undefined) {

                    window.onboundaryFired = true; 

                    window.currentTTSCharIndex = resumeFromIndex + event.charIndex;

                }

            };



            // Il player va mostrato SUBITO. Una versione precedente lo mostrava solo
            // dentro utterance.onstart, ma su iOS quell'evento è inaffidabile — e con
            // il microfono della modalità vocale attivo può non arrivare affatto.
            // Risultato: su iPhone il player non compariva più (su PC sì, perché lì
            // onstart scatta sempre). Lo mostriamo subito e, se la voce davvero non
            // parte, ci pensa la rete di sicurezza qui sotto a toglierlo.

            attachNativeTTSPlayer();

            utterance.onstart = function() {

                if (window.currentUtterance !== this) return;

                clearTimeout(window.ttsStartWatchdog);

                window.ttsStartTime = Date.now();

                attachNativeTTSPlayer(); // riallinea icona e velocità quando l'evento c'è

            };



            utterance.onend = function(e) {

                if (window.currentUtterance === this) {

                    clearTimeout(window.ttsStartWatchdog);

                    window.isSpeakingNative = false;

                    window.currentUtterance = null;

                    hideFloatingPlayer(); 

                    if (window.lastTTSCallback) window.lastTTSCallback();

                }

            };



            utterance.onerror = function(e) {

                if (window.currentUtterance === this) {

                    clearTimeout(window.ttsStartWatchdog);

                    window.isSpeakingNative = false;

                    window.currentUtterance = null;

                    hideFloatingPlayer(); 

                }

            };



            setTimeout(() => {

                if (window.currentUtterance === utterance) {

                    window.ttsStartTime = Date.now(); // Resettiamo il tempo esatto di partenza

                    window.speechSynthesis.speak(utterance);

                }

            }, 50);



            // Rete di sicurezza SOLO PER IL PLAYER: se dopo 3s la sintesi non risulta
            // né in riproduzione né in coda, vuol dire che il sistema l'ha bloccata:
            // togliamo il player invece di lasciarlo appeso.
            // Qui prima veniva chiamata stopNativeTTS(), che però azzera anche
            // lastTTSCallback: in modalità vocale quella callback è l'anello che fa
            // avanzare il gioco (domanda -> ascolto -> risposta), quindi su iPhone,
            // dove la sintesi con microfono attivo parte in ritardo, dopo 2,5s moriva
            // tutto il flusso. Ora ci limitiamo a nascondere il player, senza toccare
            // lo stato della voce.

            clearTimeout(window.ttsStartWatchdog);

            window.ttsStartWatchdog = setTimeout(() => {

                if (window.currentUtterance === utterance

                    && !window.speechSynthesis.speaking

                    && !window.speechSynthesis.pending) {

                    try { hideFloatingPlayer(); } catch (e) {}

                }

            }, 3000);

        }



        // --- IL CRONOMETRO SALVAVITA ---

        function updateCurrentTTSCharIndex() {

            if (window.isSpeakingNative && !window.onboundaryFired && window.ttsStartTime > 0) {

                let elapsedMs = Date.now() - window.ttsStartTime;

                if (elapsedMs > 200) { 

                    let speedMult = parseFloat(ttsSettings.speed || 1);

                    // Abbassato a 15: è la media esatta dell'italiano, evita i salti in avanti!

                    let charsPerSec = 15 * speedMult; 

                    let charsRead = Math.floor(((elapsedMs - 200) / 1000) * charsPerSec);

                    

                    window.currentTTSCharIndex = window.ttsStartCharIndex + charsRead;

                    if (window.currentTTSCharIndex > window.currentCleanTextForTTS.length) {

                        window.currentTTSCharIndex = window.currentCleanTextForTTS.length;

                    }

                }

            }

        }



        // --- FUNZIONI DI CONTROLLO NATIVE ---

        // Stop DEFINITIVO (diverso dalla pausa): azzera anche lo stato, così un
        // eventuale speak() già programmato non riparte a sorpresa dopo che
        // l'utente ha cambiato pagina, e il player non resta appeso.
        window.stopNativeTTS = function() {

            clearTimeout(window.ttsStartWatchdog);

            window.currentUtterance = null;

            window.isSpeakingNative = false;

            window.currentTTSCharIndex = 0;

            window.lastTTSCallback = null;

            try { window.speechSynthesis.cancel(); } catch (e) {}

            try { hideFloatingPlayer(); } catch (e) {}

        };

        function pauseNativeTTS() {

            if (window.isSpeakingNative && window.currentUtterance !== null) {

                updateCurrentTTSCharIndex(); // Registra ESATTAMENTE dove siamo arrivati prima di bloccare

                window.currentUtterance = null; 

                window.speechSynthesis.cancel(); 

            }

        }



        function resumeNativeTTS() {

            if (window.currentCleanTextForTTS) {

                speakText(window.lastRawTextForTTS, window.lastTTSCallback, window.currentTTSCharIndex);

            }

        }



        // --- LA NUOVA LOGICA DI SKIP ---

        function skipNativeTTS(seconds) {

            if (window.currentCleanTextForTTS) {

                let wasSpeaking = (window.currentUtterance !== null);

                

                // 1. Mettiamo in pausa (il cronometro salva la posizione esatta ORA)

                pauseNativeTTS();

                

                // 2. Calcoliamo i caratteri da saltare (15 caratteri medi al secondo)

                let speedMult = parseFloat(ttsSettings.speed || 1);

                let charSkip = Math.floor(seconds * 15 * speedMult); 

                

                // 3. Aggiungiamo il salto alla posizione appena salvata

                window.currentTTSCharIndex += charSkip;

                

                // Limiti di sicurezza per non crashare

                if (window.currentTTSCharIndex < 0) window.currentTTSCharIndex = 0;

                if (window.currentTTSCharIndex >= window.currentCleanTextForTTS.length) {

                    window.currentTTSCharIndex = window.currentCleanTextForTTS.length - 1;

                }

                

                // 4. Se stava parlando, riavviamo da soli

                if (wasSpeaking) {

                    setTimeout(() => { resumeNativeTTS(); }, 100);

                }

            }

        }



        function restartNativeTTSWithNewSpeed() {

            if (window.isSpeakingNative && window.currentCleanTextForTTS) {

                pauseNativeTTS(); 

                setTimeout(() => { resumeNativeTTS(); }, 100);

            }

        }



        /* PAROLE: ogni lato ha la sua lingua, e la voce si sceglie da quella
           (fc_speak.js) invece di indovinarla dal testo, che qui conosce solo
           italiano e inglese. La parola si sente due volte, la seconda piu'
           lenta; la traduzione una volta. Passa di qui tutto cio' che legge:
           il tocco sulla carta, la lettura automatica e la modalita' vocale.
           Un testo che non e' un lato della carta (una risposta dell'IA, per
           esempio) torna al lettore di sempre. */
        if (window.FC && FC.tipo === 'vocab') {
            const speakStudio = speakText;
            speakText = function (rawText, onEndCallback = null, resumeFromIndex = 0) {
                const el = Array.prototype.find.call(document.querySelectorAll('.fcv-game-text'),
                    function (e) { return e.getAttribute('data-raw') === rawText; });
                if (!el || !window.fcSpeak || !rawText || ttsSettings.mode === 'no') {
                    return speakStudio(rawText, onEndCallback, resumeFromIndex);
                }
                const impara = el.getAttribute('data-lato') === 'learn';
                const voce = impara ? (FC.impara || {}).voce : (FC.conosce || {}).voce;
                fcSpeak(null, rawText, voce, !impara, onEndCallback);
            };
        }

        function playAudioFC(index, type) {

            if (ttsSettings.mode === 'no') return;


            

            document.querySelectorAll('audio').forEach(a => { a.pause(); a.currentTime = 0; });

            stopNativeTTS(); // reset completo: annulla anche eventuali speak() in coda



            let sectionId = type === 'Q' ? '#q-section-' + index : '#ans-section-' + index;

            if (type === 'A' && document.getElementById('graded-ans-' + index)) {

                sectionId = '#graded-ans-' + index;

            }



            let rawTextEl = document.querySelector(sectionId + ' .raw-text');

            let textToRead = rawTextEl ? rawTextEl.getAttribute('data-raw') : "";

            let audioFilePlayer = document.getElementById(type === 'Q' ? 'player_q_' + index : 'player_a_' + index);



            speakText(textToRead, () => {

                if (audioFilePlayer) audioFilePlayer.play();

            });

        }



        function toggleAnswer(index, btn) {

            let ansBox = document.getElementById('graded-ans-' + index);

            if (ansBox.style.display === 'none' || ansBox.style.display === '') {

                ansBox.style.display = 'block';

                ansBox.classList.add('reveal-anim');

                btn.innerText = T('fc.gm_nascondi_risposta');

            } else {

                ansBox.style.display = 'none';

                btn.innerText = T('fc.gm_mostra_risposta');

            }

        }



        function revealCard(index) {

            var showBtn = document.getElementById('btn-show-' + index);

            var ansBox = document.getElementById('ans-section-' + index);

            if (showBtn) showBtn.style.display = 'none';

            ansBox.style.display = 'block';

            ansBox.classList.add('reveal-anim');

            var cardBox = ansBox.closest('.card-box');
            if (cardBox) cardBox.classList.add('risposta-mostrata');

            try { if (navigator.vibrate) navigator.vibrate(8); } catch (e) {}

            

            if (ttsSettings.mode === 'auto' && !checkHasChildren(ansBox.closest('.container'))) {

                playAudioFC(index, 'A');

            }

        }



        function checkAutoPlayQuestion() {

            if (ttsSettings.mode !== 'auto') return;

            // ATTENZIONE: qui prima c'era '.container:last-of-type .card-box:not(.graded)'.
            // ':last-of-type' non ragiona per classe ma per TIPO di tag: chiedeva
            // "l'ultimo <div> tra i figli del body, che abbia anche classe container".
            // Siccome dopo il contenitore del gioco il body contiene altri <div>
            // (floating_player, examples_modal, voice_game_mode...), il contenitore
            // non era mai "l'ultimo div" e il selettore non trovava NULLA.
            let cont = currentContainer();

            if (!cont) return;

            // Il conteggio va fatto DENTRO questo contenitore: vedi checkHasChildren().
            if (checkHasChildren(cont)) return;

            let primaDomanda = cont.querySelector('.card-box:not(.graded):not(.anteprima-card)');

            if (!primaDomanda || !primaDomanda.id) return;

            let match = primaDomanda.id.match(/\d+$/);

            if (!match) return;

            // Se l'utente non ha ancora toccato la pagina, il browser bloccherebbe la
            // voce: mettiamo in coda e partiamo al primo tocco.
            if (!audioUnlocked) {

                pendingAutoRead = () => playAudioFC(match[0], 'Q');

                return;

            }

            playAudioFC(match[0], 'Q');

        }



        // Diagnostica: da lanciare in Console con  debugAutoRead()
        // Su iPhone non c'è una console: tieni premuto per 1 secondo il tasto
        // "Impostazioni lettura" (l'altoparlante) e la stessa tabella compare a
        // schermo, pronta da fotografare.
        // Riporta passo per passo cosa vede la lettura automatica, così si vede
        // subito QUALE anello della catena si interrompe invece di tirare a indovinare.
        window.debugAutoRead = function(mostraASchermo) {
            let out = {};
            out['1_modo_lettura'] = ttsSettings.mode + (ttsSettings.mode === 'auto' ? ' ✅' : ' ❌ (deve essere "auto")');
            out['2_voce_scelta'] = ttsSettings.nativeVoice || '(automatica)';
            out['3_audio_sbloccato'] = audioUnlocked ? 'sì ✅' : 'no (serve un tocco sulla pagina)';
            out['4_lettura_in_coda'] = pendingAutoRead ? 'sì, in attesa di un tocco' : 'no';

            let tuttiContainer = document.querySelectorAll('.container');
            let containerGioco = document.querySelectorAll('.container[data-pos]');
            out['5_container_totali'] = tuttiContainer.length;
            out['6_container_di_gioco'] = containerGioco.length;

            let cont = currentContainer();
            out['7_container_scelto'] = cont ? (cont.id || '(senza id)') + ' data-pos=' + cont.getAttribute('data-pos') : '❌ NESSUNO';
            if (!cont) return debugAutoReadOut(out, mostraASchermo);

            let cards = cont.querySelectorAll('.card-box:not(.anteprima-card)');
            out['8_card_nel_container'] = cards.length + (cards.length > 1 ? ' ❌ (>1 = considerate sotto-concetti, lettura saltata)' : '');

            let prima = cont.querySelector('.card-box:not(.graded):not(.anteprima-card)');
            out['9_card_da_leggere'] = prima ? (prima.id || '❌ SENZA ID') : '❌ NESSUNA (tutte già "graded"?)';
            if (!prima) return debugAutoReadOut(out, mostraASchermo);

            let match = (prima.id || '').match(/\d+$/);
            out['10_indice_estratto'] = match ? match[0] : '❌ nessun numero nell\'id';
            if (!match) return debugAutoReadOut(out, mostraASchermo);

            let sez = document.querySelector('#q-section-' + match[0]);
            out['11_sezione_domanda'] = sez ? 'trovata ✅' : '❌ #q-section-' + match[0] + ' NON TROVATA';
            let rawEl = sez ? sez.querySelector('.raw-text') : null;
            out['12_elemento_testo'] = rawEl ? 'trovato ✅' : '❌ nessun .raw-text dentro la sezione domanda';
            let raw = rawEl ? rawEl.getAttribute('data-raw') : null;
            out['13_testo_grezzo'] = raw ? '"' + raw.slice(0, 60) + '..."' : '❌ data-raw vuoto/assente';
            if (raw) {
                let pulito = ttsCleanText(raw, ttsDetectLang(raw));
                out['14_testo_pulito'] = pulito ? '"' + pulito.slice(0, 60) + '..."' : '❌ VUOTO dopo la pulizia (non verrebbe letto)';
            }
            out['15_voci_disponibili'] = (availableVoices || []).length;

            return debugAutoReadOut(out, mostraASchermo);
        };

        // Stampa il risultato in console E, se richiesto, in un riquadro a schermo
        // (indispensabile su iPhone, dove la console non è raggiungibile).
        function debugAutoReadOut(out, mostraASchermo) {
            try { console.table(out); } catch (e) { console.log(out); }
            if (mostraASchermo) {
                let vecchio = document.getElementById('debug-autoread-box');
                if (vecchio) vecchio.remove();
                let box = document.createElement('div');
                box.id = 'debug-autoread-box';
                box.setAttribute('style',
                    'position:fixed;left:8px;right:8px;top:8px;z-index:2147483647;' +
                    'background:#1C1C1E;color:#fff;padding:12px 14px;border-radius:14px;' +
                    'font:11px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;' +
                    'white-space:pre-wrap;word-break:break-word;max-height:80vh;overflow:auto;' +
                    '-webkit-overflow-scrolling:touch;box-shadow:0 10px 40px rgba(0,0,0,.5)');
                let righe = [T('fc.gm_diagnostica'), ''];
                Object.keys(out).forEach(function (k) { righe.push(k + ': ' + out[k]); });
                righe.push('');
                righe.push('UA: ' + navigator.userAgent);
                box.textContent = righe.join('\n');
                let close = document.createElement('button');
                close.textContent = T('fc.gm_chiudi_x');
                close.setAttribute('style',
                    'display:block;margin-top:10px;background:#FF3B30;color:#fff;border:none;' +
                    'border-radius:8px;padding:8px 12px;font-size:12px;font-weight:700;');
                close.onclick = function () { box.remove(); };
                box.appendChild(close);
                document.body.appendChild(box);
            }
            return out;
        }

        // Pressione lunga (1s) sul tasto impostazioni lettura -> diagnostica a schermo.
        (function () {
            let t = null;
            function giu(e) {
                let b = e.target.closest && e.target.closest('#ttsSettingsBtn');
                if (!b) return;
                t = setTimeout(function () { t = null; window.debugAutoRead(true); }, 1000);
            }
            function su() { if (t) { clearTimeout(t); t = null; } }
            document.addEventListener('touchstart', giu, { passive: true });
            document.addEventListener('touchend', su);
            document.addEventListener('touchmove', su, { passive: true });
            document.addEventListener('mousedown', giu);
            document.addEventListener('mouseup', su);
        })();



        // 6. FUNZIONE AI TUTOR (GEMINI + EDGE TTS)

        // ==========================================
        // POPUP "TOKEN INSUFFICIENTI" + TIMER/STIMA CHIAMATE IA + AVVISO API LENTE
        // ==========================================
        function closeTokenLimitModal() {
            document.getElementById('token-limit-modal').style.display = 'none';
        }

        function showTokenLimitModal(message) {
            document.getElementById('token-limit-message').textContent = message || T('fc.gm_limite_mensile');
            document.getElementById('token-limit-modal').style.display = 'flex';
        }

        let apiSlowToastTimer = null;
        function showApiSlowToast() {
            let el = document.getElementById('api-slow-toast');
            if (!el) return;
            el.classList.add('show');
            clearTimeout(apiSlowToastTimer);
            apiSlowToastTimer = setTimeout(() => el.classList.remove('show'), 5000);
        }

        // Timer "0s · di solito ≈ Xs" mostrato dentro il modale AI mentre si aspetta
        // la risposta testuale di Gemini (solo per 'explain'/'metaphor', le modalità lente).
        let aiTimerInterval = null;
        function startAiTimer(mode) {
            let timerEl = document.getElementById('ai-timer');
            let textEl = document.getElementById('ai-timer-text');
            if (!timerEl || !textEl) return;
            let stats = (window.aiPerfStats && window.aiPerfStats[mode]) || { avg_seconds: null, slower_than_usual: false };
            let elapsed = 0;
            let stimaLabel = stats.avg_seconds ? T('fc.gm_di_solito', { s: stats.avg_seconds }) : T('fc.gm_generazione');
            timerEl.style.display = 'flex';
            textEl.textContent = `0s · ${stimaLabel}`;
            clearInterval(aiTimerInterval);
            aiTimerInterval = setInterval(() => {
                elapsed++;
                textEl.textContent = `${elapsed}s · ${stimaLabel}`;
            }, 1000);
            if (stats.slower_than_usual) showApiSlowToast();
        }
        function stopAiTimer() {
            clearInterval(aiTimerInterval);
            let timerEl = document.getElementById('ai-timer');
            if (timerEl) timerEl.style.display = 'none';
        }

        let currentAiAudio = new Audio(); 
        let currentAiActionId = 0; 
        
        // AGGIUNTA: Funzione globale per stoppare e annullare l'AI in corso
        window.cancelAiAudio = function() {
            currentAiActionId++; // Questo invalida i caricamenti asincroni pendenti!
            if (currentAiAudio) {
                currentAiAudio.pause();
                currentAiAudio.currentTime = 0;
            }
        };

        function closeAiModal() {
            document.getElementById('ai-modal').style.display = 'none';
            window.cancelAiAudio(); // Usiamo la nuova funzione
            stopNativeTTS();
            hideFloatingPlayer();
            stopAiTimer();
        }

        async function playAI(mode, index, cardId, btnElement) {
            currentAiActionId++; 
            let myActionId = currentAiActionId;

            stopNativeTTS(); // reset completo, non solo cancel()
            document.querySelectorAll('audio').forEach(a => a.pause());
            currentAiAudio.pause();



            let originalText = btnElement.innerHTML;

            btnElement.innerHTML = "⏳...";

            btnElement.disabled = true;



            let sectionId = document.getElementById('ans-section-' + index).style.display !== 'none' 

                            ? '#ans-section-' + index 

                            : '#graded-ans-' + index;

            

            let rawTextEl = document.querySelector(sectionId + ' .raw-text');

            let rawText = rawTextEl.getAttribute('data-raw');

            let textForAudio = rawText;



            try {

                if (mode !== 'read') {

                    // Genera testo
                    startAiTimer(mode);

                    let textResponse = await fetch('../ai/ai_generate_text.php', {

                        method: 'POST',

                        headers: { 'Content-Type': 'application/json' },

                        body: JSON.stringify({ mode: mode, text: rawText, id: cardId })

                    });



                    if (myActionId !== currentAiActionId) return; // Se hai cliccato altrove, fermati.



                    let textRawResult = await textResponse.text();

                    let textData;

                    try { textData = JSON.parse(textRawResult); } 

                    catch (e) {

                        stopAiTimer();

                        alert(T('fc.gm_errore_server_testo') + "\n\n" + textRawResult);

                        btnElement.innerHTML = originalText;

                        btnElement.disabled = false;

                        return;

                    }



                    stopAiTimer();

                    if (!textData.success) {

                        if (textData.error === 'limite_piano') {

                            showTokenLimitModal(textData.message);

                        } else {

                            alert("Errore AI: " + textData.error);

                        }

                        btnElement.innerHTML = originalText;

                        btnElement.disabled = false;

                        return;

                    }



                    textForAudio = textData.text;



                    // MOSTRA IL POPUP CON AVVISO AUDIO IN CORSO

                    let modalTitle = document.getElementById('ai-modal-title');

                    modalTitle.innerText = mode === 'explain' ? T('fc.gm_spiegazione') : T('fc.gm_metafora');

                    modalTitle.style.color = mode === 'explain' ? '#8e24aa' : '#f57c00';

                    

                    let modalContent = document.getElementById('ai-modal-content');

                    modalContent.innerHTML = parseTextForPreview(textData.text);

                    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(modalContent);

                    

                    document.getElementById('ai-audio-status').style.display = 'block';

                    document.getElementById('ai-modal').style.display = 'flex';

                }



                // Genera Audio Google Cloud
                //
                // IMPORTANTE: mandiamo a Google il testo già passato per la stessa
                // pipeline della voce nativa. Prima gli arrivava il testo GREZZO, e
                // quindi leggeva ad alta voce i comandi del parser e il LaTeX
                // ("slash bold graffa", "backslash frac"...). Ora "leggi", "spiega" e
                // "metafora" seguono le stesse traduzioni delle formule del resto del sito.

                let audioText = ttsCleanText(textForAudio, ttsDetectLang(textForAudio));

                if (!audioText) {

                    document.getElementById('ai-audio-status').style.display = 'none';

                    return; // niente di leggibile: inutile spendere caratteri audio

                }

                let response = await fetch('../ai/ai_tutor.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ mode: mode, text: audioText, id: cardId, voice: ttsSettings.aiVoice || 'it-IT-Chirp3-HD-Charon' })

                });

                

                if (myActionId !== currentAiActionId) return; // Se l'utente ha chiuso il popup, fermati.



                let rawResult = await response.text(); 

                let data;

                try { data = JSON.parse(rawResult); } 

                catch(e) {

                    alert(T('fc.gm_errore_server_audio') + "\n\n" + rawResult);

                    btnElement.innerHTML = originalText;

                    btnElement.disabled = false;

                    return;

                }

                

                if (data.success) {

                    if (data.audio_url) {

                        document.getElementById('ai-audio-status').style.display = 'none';

                        currentAiAudio.src = data.audio_url + "?t=" + new Date().getTime();

                        

                        attachFloatingPlayer(currentAiAudio);



                        currentAiAudio.play().catch(e => {

                            // Se il browser blocca lo stesso l'audio, mettiamo un bottone di emergenza

                            console.log("Autoplay bloccato", e);

                            document.getElementById('ai-audio-status').style.display = 'block';

                            document.getElementById('ai-audio-status').innerHTML = T('fc.gm_audio_pronto') + ' <button onclick="currentAiAudio.play()" style="padding:4px 8px; border-radius:4px; cursor:pointer;">' + T('fc.gm_ascolta') + '</button>';

                        });

                    }

                } else {

                    if (data.error === 'limite_piano') {
                        showTokenLimitModal(data.message);
                    } else {
                        alert("Errore AI: " + data.error);
                    }

                }

            } catch (error) {

                stopAiTimer();

                alert(T('fc.gm_errore_connessione'));

                console.error(error);

            } finally {

                stopAiTimer();

                // Ripristina il tasto se siamo ancora sulla stessa azione

                if (myActionId === currentAiActionId) {

                    btnElement.innerHTML = originalText;

                    btnElement.disabled = false;

                }

            }

        }



        // 5. INTERCETTAZIONE AJAX — sostituita dal NUOVO MOTORE DI NAVIGAZIONE

        //    con transizioni stile TikTok (vedi "NUOVO MOTORE UI" in fondo al file).

        //    Comportamento preservato: stessa cattura di a.btn / a.nav-btn, stessi

        //    esclusi (end-link, result_fc, concludi), stesso pushState + re-render.



        // ==========================================

        // GESTIONE "FRANTUMA" FLASHCARDS

        // ==========================================

        function closeShatterModal() {

            document.getElementById('shatter-modal').style.display = 'none';

        }



        function revealShatterCard(idx) {

            document.getElementById('shatter-ans-' + idx).style.display = 'block';

            document.getElementById('shatter-btn-' + idx).style.display = 'none';

        }



        async function shatterCard(index, cardId, btn) {

            let originalText = btn.innerHTML;

            btn.innerHTML = "⏳ Frantumazione...";

            btn.disabled = true;



            // Recuperiamo la Domanda e la Risposta originali dai div nascosti

            let qTextEl = document.querySelector('#q-section-' + index + ' .raw-text');

            let aTextEl = document.querySelector('#ans-section-' + index + ' .raw-text');

            

            let qText = qTextEl ? qTextEl.getAttribute('data-raw') : '';

            let aText = aTextEl ? aTextEl.getAttribute('data-raw') : '';



            let contentDiv = document.getElementById('shatter-content');

            let actionsDiv = document.getElementById('shatter-actions');

            

            let shatterStats = (window.aiPerfStats && window.aiPerfStats.shatter) || { avg_seconds: null, slower_than_usual: false };

            let shatterEta = shatterStats.avg_seconds ? T('fc.gm_shatter_eta', { s: shatterStats.avg_seconds }) : T('fc.gm_shatter_attesa');

            contentDiv.innerHTML = `<div style="text-align:center; padding: 30px; font-weight:700; color:var(--blue); line-height:1.5;">${T('fc.gm_shatter_analizza')}<br><small style="color:var(--text-2); font-weight:500;">${shatterEta}</small></div>`;

            actionsDiv.style.display = 'none';

            document.getElementById('shatter-modal').style.display = 'flex';

            if (shatterStats.slower_than_usual) showApiSlowToast();



            try {

                let response = await fetch('../ai/ai_shatter.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ q: qText, a: aText, id: cardId })

                });



                let rawResult = await response.text();

                let data;

                try { data = JSON.parse(rawResult); } catch(e) {

                    contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">' + T('fc.gm_errore_formato') + '</div>';

                    console.error("Raw result:", rawResult);

                    return;

                }



                if (data.success && data.cards && data.cards.length > 0) {

                    let html = '';

                    let escAttr = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                    data.cards.forEach((mini, i) => {

                        html += `

                        <div class="mini-card">

                            <div class="mini-card-label">Mini card ${i+1}</div>

                            <div class="raw-text mini-q" id="shatter-qraw-${i}" data-raw="${escAttr(mini.q)}">${parseTextForPreview(mini.q)}</div>

                            <button id="shatter-btn-${i}" class="btn btn-primary btn-sm" onclick="revealShatterCard(${i})">${T('fc.gm_mostra_risposta')}</button>

                            <div id="shatter-ans-${i}" class="mini-ans" style="display:none;">

                                <div class="raw-text mini-a" id="shatter-araw-${i}" data-raw="${escAttr(mini.a)}">${parseTextForPreview(mini.a)}</div>

                            </div>

                        </div>`;

                    });

                    contentDiv.innerHTML = html;

                    

                    // Applica LaTeX se presente

                    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(contentDiv);



                    // Copia magicamente gli URL T('fc.lbl_corretto') e "Sbagliato" della flashcard originale!

                    let wrongUrl = document.querySelector('#ans-section-' + index + ' a.gc-wrong').href;

                    let correctUrl = document.querySelector('#ans-section-' + index + ' a.gc-correct').href;

                    

                    document.getElementById('shatter-wrong-btn').href = wrongUrl;

                    document.getElementById('shatter-correct-btn').href = correctUrl;

                    

                    actionsDiv.style.display = 'flex';

                } else if (data.error === 'limite_piano') {

                    closeShatterModal();

                    showTokenLimitModal(data.message);

                } else {

                    contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">' + T('fc.gm_nessun_risultato') + '</div>';

                }

            } catch (err) {

                contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">' + T('fc.gm_errore_rete') + '</div>';

                console.error(err);

            }



            btn.innerHTML = originalText;

            btn.disabled = false;

        }

        // ==========================================
        // RENDERING TESTI CON IL NUOVO PARSER
        // ==========================================
        function renderAllRawTexts() {
            /* Parole: il testo e' gia' scritto dal server, non c'e' niente da disegnare. */
            if (window.FC && FC.tipo === 'vocab') return;
            try {
                let nodes = [];
                document.querySelectorAll('.raw-text').forEach(function(el) {
                    let rawData = el.getAttribute('data-raw');
                    if (rawData !== null) {
                        el.innerHTML = parseTextForPreview(rawData);
                        nodes.push(el); // Salviamo solo gli elementi appena aggiornati
                    }
                });

                // fcdDisegnaFormule avvolge il LaTeX grezzo nei delimitatori
                // \( \)/\[ \] e SOLO DOPO chiama MathJax: chiamare MathJax
                // direttamente su testo senza delimitatori non fa nulla.
                if (typeof fcdDisegnaFormule === 'function') {
                    nodes.forEach(function (el) { fcdDisegnaFormule(el); });
                }
            } catch (error) {
                console.error("Errore nel rendering dei testi:", error);
            }
        }



        window.onload = () => {

            renderAllRawTexts();

            availableVoices = window.speechSynthesis.getVoices();

            setTimeout(checkAutoPlayQuestion, 400); 

        };



        /* ================================================================

           NUOVO MOTORE UI — iOS · Swipe stile Tinder · Feed stile TikTok

           La logica di gioco (PHP + funzioni sopra) è invariata: questo

           motore gestisce solo gesture, animazioni e navigazione AJAX.

           ================================================================ */

        (function () {

            'use strict';



            var EASE = 'cubic-bezier(.32,.72,0,1)';

            var SNAP = 'cubic-bezier(.24,1.3,.35,1)';

            var PULL_TH = 92;                       // soglia pull verticale (px)

            var navLock = false;                    // blocca doppie navigazioni

            var gestureBlock = false;               // blocca lo scroll nativo durante le gesture

            var suppressNextClick = false;          // assorbe il ghost-click post trascinamento



            function $$ (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

            function curC () { var all = document.querySelectorAll('.container'); return all[all.length - 1] || null; }

            function buzz (p) { try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) {} }

            function TH () { return Math.min(130, window.innerWidth * 0.34); }   // soglia swipe orizzontale



            /* ---------- Layer fissi: tinta schermo, timbro, chips, coriandoli ---------- */

            var tint = document.createElement('div'); tint.id = 'swipe-tint';

            var stamp = document.createElement('div'); stamp.className = 'swipe-stamp'; stamp.textContent = 'CORRECT';

            var confettiLayer = document.createElement('div'); confettiLayer.id = 'confetti-layer';



            var CHEV_UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>';

            var CHEV_DN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';

            function mkChip (pos, icon) {

                var d = document.createElement('div');

                d.className = 'pull-chip ' + pos;

                d.innerHTML = '<span class="pc-ic">' + icon + '</span><span class="pc-tx"></span>';

                return d;

            }

            var hintTop = mkChip('top', CHEV_UP);       // tirando in giù → pagina precedente

            var hintBottom = mkChip('bottom', CHEV_DN); // tirando in su  → pagina successiva



            document.body.appendChild(tint);

            document.body.appendChild(stamp);

            document.body.appendChild(confettiLayer);

            document.body.appendChild(hintTop);

            document.body.appendChild(hintBottom);



            /* ---------- Utility ---------- */

            function stopAllMedia () {

                try { currentAiActionId++; } catch (e) {}

                // stopNativeTTS azzera anche currentUtterance: senza, il speak()
                // programmato da speakText ripartiva 50ms dopo aver lasciato la pagina.
                try { stopNativeTTS(); } catch (e) { try { window.speechSynthesis.cancel(); } catch (e2) {} }

                $$('audio').forEach(function (a) { try { a.pause(); } catch (e) {} });

                try { if (currentAiAudio) currentAiAudio.pause(); } catch (e) {}

                try { hideFloatingPlayer(); } catch (e) {}

            }



            function syncHeader () {

                var c = curC(); if (!c) return;

                var h = c.querySelector('.app-header'); if (!h) return;

                document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');

            }

            window.addEventListener('resize', syncHeader);



            /* ---------------- Cronometro partita (multi-sessione, live) ---------------- */

            var chronoCollapsed = false;

            try { chronoCollapsed = localStorage.getItem('chronoCollapsed') === '1'; } catch (e) {}

            function fmtChrono (totalSeconds) {

                var mins = Math.floor(totalSeconds / 60);

                var h = Math.floor(mins / 60);

                var m = mins % 60;

                return h + ':' + (m < 10 ? '0' : '') + m;

            }

            function chronoTick () {

                var c = curC(); if (!c) return;

                var start = parseInt(c.getAttribute('data-chrono-start') || '0', 10);

                if (!start) return;

                var base = parseInt(c.getAttribute('data-chrono-base') || '0', 10);

                var elapsed = base + Math.max(0, Math.round(Date.now() / 1000) - start);

                var txt = document.getElementById('chrono-txt');

                if (txt) txt.textContent = fmtChrono(elapsed);

            }

            function chronoApplyState () {

                var pill = document.getElementById('chrono-pill');

                if (!pill) return;

                pill.classList.toggle('is-collapsed', chronoCollapsed);

                chronoTick();

            }

            document.addEventListener('click', function (e) {

                if (window.__standoutModaleAperto) return;

                var pill = e.target.closest && e.target.closest('#chrono-pill');

                if (!pill) return;

                e.preventDefault();

                chronoCollapsed = !chronoCollapsed;

                pill.classList.toggle('is-collapsed', chronoCollapsed);

                try { localStorage.setItem('chronoCollapsed', chronoCollapsed ? '1' : '0'); } catch (err) {}

            });

            chronoApplyState();

            setInterval(chronoTick, 1000);



            /* rapido = durante un cambio di posizione: li' e' gia' il pannello
               intero a scorrere, e far entrare anche le carte una alla volta
               (partendo da invisibili, con 90ms + 70ms a carta di ritardo)
               voleva dire guardare uno spazio vuoto per mezzo secondo - era
               questo il "bianco" durante lo swipe. Al primo caricamento della
               pagina l'entrata a scaglioni resta, li' non da' fastidio. */
            function cardsIn (root, rapido) {

                if (!root) return;

                if (rapido) {

                    $$('.card-box', root).forEach(function (card) {

                        try {

                            card.animate([{ opacity: .4 }, { opacity: 1 }], { duration: 140, easing: 'ease-out' });

                        } catch (e) {}

                    });

                    return;

                }

                $$('.card-box', root).forEach(function (card, i) {

                    try {

                        card.animate(

                            [{ opacity: 0, transform: 'translateY(28px) scale(.98)' }, { opacity: 1, transform: 'none' }],

                            { duration: 480, delay: 90 + i * 70, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' }

                        );

                    } catch (e) {}

                });

            }



            function flashTint (color, peak) {

                stamp.style.opacity = '0';

                tint.style.background = color;

                try { tint.animate([{ opacity: 0 }, { opacity: peak }, { opacity: 0 }], { duration: 480, easing: 'ease-out' }); } catch (e) {}

            }



            var GREENS = ['#30D158', '#34C759', '#A7F3C8', '#0A84FF', '#FFD60A', '#ffffff'];

            var GOLDS  = ['#FF9F0A', '#FFD60A', '#FFB340', '#ffffff', '#FF9500'];

            function confetti (x, y, colors) {

                for (var i = 0; i < 18; i++) {

                    var s = document.createElement('i');

                    if (i % 3 === 0) s.className = 'rnd';

                    s.style.background = colors[i % colors.length];

                    s.style.left = x + 'px';

                    s.style.top = y + 'px';

                    confettiLayer.appendChild(s);

                    var ang = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.3;

                    var dist = 70 + Math.random() * 130;

                    var dx = Math.cos(ang) * dist;

                    var dy = Math.sin(ang) * dist + 150 + Math.random() * 60;

                    var rot = (Math.random() - 0.5) * 720;

                    try {

                        var anim = s.animate(

                            [

                                { transform: 'translate(-50%,-50%) rotate(0deg)', opacity: 1 },

                                { transform: 'translate(calc(-50% + ' + dx + 'px), calc(-50% + ' + dy + 'px)) rotate(' + rot + 'deg)', opacity: 0 }

                            ],

                            { duration: 650 + Math.random() * 350, easing: 'cubic-bezier(.16,.8,.4,1)' }

                        );

                        anim.onfinish = (function (el) { return function () { el.remove(); }; })(s);

                    } catch (e) { s.remove(); }

                }

            }



            /* Feedback "dopaminico" alla valutazione: card che vola + tinta + coriandoli */

            function gradeFeedback (card, result) {

                /* Premendo Corretto/Sbagliato parte la stessa animazione di
                   uscita dello swipe, quindi vale la stessa anteprima: senza,
                   sotto la carta che vola via restava il bianco. La si apre
                   subito al massimo, perche' qui non c'e' un trascinamento
                   graduale da seguire - il gesto e' gia' deciso. */
                try {
                    if (typeof creaAnteprima === 'function') {
                        creaAnteprima(card);
                        setTimeout(function () {
                            if (typeof aggiornaAnteprima === 'function') aggiornaAnteprima(1);
                        }, 30);
                    }
                } catch (e) {}

                return new Promise(function (resolve) {

                    var good = (result === 'correct');

                    var learnt = (result === 'learnt');

                    flashTint(learnt ? 'var(--orange)' : (good ? 'var(--tint-green)' : 'var(--tint-red)'), 0.42);

                    buzz(good || learnt ? 12 : 24);

                    if (card && card.getBoundingClientRect) {

                        var r = card.getBoundingClientRect();

                        if (good)   confetti(r.left + r.width / 2, r.top + r.height * 0.35, GREENS);

                        if (learnt) confetti(r.left + r.width / 2, r.top + r.height * 0.3, GOLDS);

                        var to = learnt

                            ? 'translateY(-120vh) rotate(-7deg) scale(.92)'

                            : (good ? 'translate(120vw, -6vh) rotate(20deg)' : 'translate(-120vw, -6vh) rotate(-20deg)');

                        card.style.zIndex = '50';

                        card.style.transition = 'transform .38s cubic-bezier(.5,0,.9,.45), opacity .38s ease-out';

                        requestAnimationFrame(function () {

                            card.style.transform = to;

                            card.style.opacity = '0';

                        });

                        setTimeout(resolve, 290);

                    } else {

                        setTimeout(resolve, 170);

                    }

                });

            }



            /* Le barre di progresso si riempiono animate dal valore vecchio al
               nuovo. Due regole che prima non erano rispettate:

               1) si chiama DOPO applicaZoomBarra(newC), non prima. Prima
                  girava per prima e scriveva in style.width la larghezza gia'
                  scalata della pagina vecchia; subito dopo applicaZoomBarra
                  memorizzava la base dello zoom leggendo proprio quel valore
                  (dataset.baseWidth) e restava avvelenata per sempre, e il
                  requestAnimationFrame qui sotto finiva per riscrivere la
                  percentuale NON zoomata cancellando lo zoom. Era il motivo
                  principale per cui riempimento e pallini finivano su due
                  scale diverse dopo ogni navigazione.

               2) non tocca MAI dataset.baseWidth: quello e' il modello, qui si
                  anima solo la geometria a schermo. Entrambe le larghezze
                  (vecchia e nuova) sono gia' passate per lo zoom, quindi
                  l'interpolazione avviene fra due valori omogenei. */

            function animateProgress (oldC, newC) {

                /* Le -oltre restano fuori: non rappresentano un avanzamento
                   che sta accadendo ora, ma progresso gia' fatto in
                   precedenza - farle "riempire" a ogni cambio pagina
                   sembrerebbe un progresso nuovo che non c'e' stato. Devono
                   solo comparire/aggiornarsi di scatto nella posizione
                   finale (gia' impostata da applicaZoomBarra, chiamata prima
                   di questa funzione) - vedi anche la transition:none
                   dedicata a loro nel CSS. */
                var SEL = '.progress-bar-fill:not(.fill-pages-oltre):not(.fill-cards-oltre)';

                var vecchie = $$(SEL, oldC);

                $$(SEL, newC).forEach(function (f, i) {

                    var prec = vecchie[i];

                    if (!prec) return;

                    var da = prec.style.width;

                    var a = f.style.width;   /* gia' definitivo: lo zoom e' passato */

                    if (!da || !a || da === a) return;

                    /* Web Animations invece di scrivere style.width e rimetterlo
                       a posto in un requestAnimationFrame: cosi' non si tocca
                       nessuna proprieta' inline, e se nel frattempo qualcun
                       altro chiede un ridisegno (renderAllPendingDots, un voto
                       su un figlio) l'animazione non viene ne' troncata a meta'
                       ne' riportata indietro al valore di partenza. */
                    try {
                        f.animate([{ width: da }, { width: a }], { duration: 420, easing: EASE });
                    } catch (e) {}

                });

            }



            /* ---------- NAVIGAZIONE AJAX CON TRANSIZIONI (sostituisce il vecchio interceptor) ---------- */

                        /* Separa un indirizzo in "dove vado" e "cosa faccio".
               I parametri action/result/img_id sono un'AZIONE: scrivono nel
               database, quindi devono viaggiare in POST, dove il token CSRF vale.
               Gli altri - game_id, position, status, order - sono navigazione e
               restano nell'indirizzo. */
            function splitAzione (url) {
                var u;
                try { u = new URL(url, window.location.href); } catch (err) { return { url: url, post: null }; }
                var azione = u.searchParams.get('action');
                if (!azione) return { url: url, post: null };

                var post = { action: azione };
                ['result', 'img_id', 'is_child'].forEach(function (k) {
                    var v = u.searchParams.get(k);
                    if (v !== null) { post[k] = v; u.searchParams.delete(k); }
                });
                u.searchParams.delete('action');
                return { url: u.pathname + (u.search ? u.search : '') , post: post };
            }

            var __prefetchCache = {};   // url normalizzato -> {promise, html, resUrl}

function prefetchGet (url, finito) {
    if (!url) { if (finito) finito(); return; }
    if (__prefetchCache[url]) { if (finito) finito(); return; }   // gia' in corso o gia' pronta
    var entry = { html: null, resUrl: null };
    entry.promise = fetch(url, { method: 'GET', redirect: 'manual' }).then(function (res) {
        /* Oltre l'ultima carta il server rimanda a fc_result.php. Seguire quel
           redirect qui vorrebbe dire caricare la pagina dei risultati - che fa
           pulizia dei dati - mentre si sta ancora giocando. Si scarta e basta:
           quando ci si arrivera' per davvero, navigateTo fara' la richiesta
           normale e seguira' il redirect come si deve. */
        if (res.type === 'opaqueredirect' || res.redirected) {
            delete __prefetchCache[url];
            return;
        }
        return res.text().then(function (html) { entry.html = html; entry.resUrl = res.url; });
    }).catch(function () {
        delete __prefetchCache[url];   // fallita: si puo' ritentare piu' avanti
    }).then(function () {
        if (finito) finito();          // libera il posto per la prossima in coda
    });
    __prefetchCache[url] = entry;
}

/* Precarica in una finestra scorrevole (5 avanti, 3 indietro) invece che
   tenere tutta la partita in cache: abbastanza per rendere istantanea sia la
   lettura in avanti sia un ripasso di quelle appena viste, senza accumulare
   all'infinito man mano che la partita va avanti. Non dipende in alcun modo
   dal voto che si dara' qui: il gestore di action=grade fa solo un redirect a
   position+1 (o resta sulla stessa posizione se ci sono altri fratelli da
   valutare) - la scelta della carta a ciascuna posizione e' gia' fissata da
   fc_game_start.php, quindi si puo' precaricare gia' ora, mentre si sta
   ancora leggendo. */
/* ---------- Precaricamento a finestra scorrevole ----------
   Politica: si tengono pronte le 5 posizioni successive e le 3 precedenti.
   Avanzando di una carta entra una sola posizione nuova (la piu' lontana in
   avanti) ed escono quelle uscite dalla finestra, che vengono buttate via
   per non tenere in memoria mezza partita.

   Le richieste NON partono tutte insieme (ogni posizione e' una pagina
   intera, padre piu' tutti i suoi figli - chiederne molte insieme sovraccarica
   l'hosting): la successiva parte subito e da sola (serve per l'anteprima
   dello swipe e per il salto istantaneo), le altre arrivano in due ondate
   successive, distanziate nel tempo - vedi schedulePrefetch(). */
var PREFETCH_AVANTI = 5;        // dimezzata: dieci posizioni erano dieci PAGINE intere
                                // (padre + tutti i suoi figli), troppe per l'hosting
var PREFETCH_INDIETRO = 3;
var PREFETCH_PARALLELE = 2;   // quante richieste "di scorta" contemporaneamente

var __codaPrefetch = [];
var __ondataPrefetch2 = null;   // timer della seconda ondata
var __ondataPrefetch3 = null;   // timer della terza
var __inCorsoPrefetch = 0;

function accodaPrefetch (url) {
    if (!url || __prefetchCache[url]) return;
    if (__codaPrefetch.indexOf(url) !== -1) return;
    __codaPrefetch.push(url);
    scorriCodaPrefetch();
}

function scorriCodaPrefetch () {
    while (__inCorsoPrefetch < PREFETCH_PARALLELE && __codaPrefetch.length) {
        var url = __codaPrefetch.shift();
        if (__prefetchCache[url]) continue;
        __inCorsoPrefetch++;
        prefetchGet(url, function () {
            __inCorsoPrefetch--;
            scorriCodaPrefetch();
        });
    }
}

function urlPerPosizione (pos, perAnteprima) {
    var qs = new URLSearchParams(window.location.search);
    var gameId = qs.get('game_id');
    var status = qs.get('status') || '0';
    var order = qs.get('order') || 'random';
    return 'fc_game.php?game_id=' + gameId + '&position=' + pos + '&status=' + status + '&order=' + order
         + (perAnteprima ? '&prefetch=1' : '');
}

function pulisciCacheFuoriFinestra (posAttuale, totale) {
    var min = Math.max(1, posAttuale - PREFETCH_INDIETRO);
    var max = totale ? Math.min(totale, posAttuale + PREFETCH_AVANTI) : (posAttuale + PREFETCH_AVANTI);
    Object.keys(__prefetchCache).forEach(function (url) {
        var m = url.match(/[?&]position=(\d+)/);
        if (!m) return;
        var p = parseInt(m[1], 10);
        if (p < min || p > max) delete __prefetchCache[url];
    });
    /* Anche la coda: quello che e' uscito dalla finestra non serve piu'
       chiederlo, e chiederlo comunque ruberebbe il posto a cio' che serve. */
    __codaPrefetch = __codaPrefetch.filter(function (url) {
        var m = url.match(/[?&]position=(\d+)/);
        if (!m) return false;
        var p = parseInt(m[1], 10);
        return p >= min && p <= max;
    });
}

/* ---------- Elenco compatto degli errori ---------- */
function apriElencoErrori (ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    var el = document.getElementById('erroriElenco');
    if (!el) return;
    el.style.display = 'block';
    requestAnimationFrame(function () { el.classList.add('aperto'); });
}

function chiudiElencoErrori () {
    var el = document.getElementById('erroriElenco');
    if (!el) return;
    el.classList.remove('aperto');
    setTimeout(function () { el.style.display = 'none'; }, 180);
}
window.apriElencoErrori = apriElencoErrori;
window.chiudiElencoErrori = chiudiElencoErrori;

/* Si chiude toccando fuori: un pannello che resta aperto mentre si torna a
   giocare darebbe fastidio piu' che aiutare. */
document.addEventListener('click', function (e) {
    var el = document.getElementById('erroriElenco');
    if (!el || el.style.display === 'none') return;
    if (e.target.closest && (e.target.closest('#erroriElenco') || e.target.closest('.dot-cluster'))) return;
    chiudiElencoErrori();
});

/* ---------- Barra ingrandita sulla zona in cui si sta giocando ----------
   Con un centinaio di posizioni e piu' categorie la barra intera comprime
   tutto in pochi pixel: non si distingue dove si e' ne' quali categorie si
   stanno attraversando. Si mostra allora solo la finestra attorno alla
   posizione corrente, ingrandita; un tocco sulla barra passa alla vista
   d'insieme, un altro torna a ingrandire. */
var BARRA_SOGLIA_ZOOM = 30;     // sotto questo numero di posizioni sta gia' larga
var BARRA_FINESTRA = 12;        // quante posizioni mostrare attorno a quella corrente

/* Gemelle di fc_pct_marker()/fc_pct_fill() lato PHP: la stessa convenzione
   deve valere per i pallini disegnati dal server e per quelli aggiunti dal
   browser (voti in sospeso, avanzamento fra i figli), altrimenti gli stessi
   numeri finiscono in due punti diversi della stessa barra. */
function pctMarker (n, tot) {
    tot = Math.max(1, tot || 0);
    return Math.max(0, Math.min(100, ((n - 0.5) / tot) * 100));
}
function pctFill (n, tot) {
    tot = Math.max(1, tot || 0);
    return Math.max(0, Math.min(100, (n / tot) * 100));
}

/* La scelta "vista intera / vista ingrandita" e' dell'UTENTE, non della
   pagina: stava in una classe sul contenitore corrente, e siccome ogni
   navigazione AJAX sostituisce il contenitore con uno fresco dal server, la
   scelta si perdeva ogni volta. Tipico: si apriva la vista d'insieme per
   vedere tutti gli errori, si toccava un errore, e la barra tornava zoomata
   da sola. Ora vive qui e viene riapplicata a ogni ridisegno. */
var __vistaIntera = false;

/* La finestra ingrandita stessa vive qui, non sul contenitore, per lo
   stesso motivo: un oggetto {0: inizio-concetti, 1: inizio-carte}, ciascuno
   in frazione 0-1 sulla propria scala. Persistere questo valore e' quello
   che rende la finestra "appiccicosa" invece che ricentrata ad ogni singolo
   spostamento - vedi la nota estesa dentro inizioFinestraDi(). */
var __finestraInizio = {};

/* Le basi (il modello in scala 0-100% sul totale vero) si scrivono UNA volta
   per contenitore, leggendole dai data-attribute che il server pubblica - mai
   rileggendo uno style.width che nel frattempo qualcun altro puo' avere
   cambiato. Gli altri elementi (pallini rossi, tratti di categoria) non li
   tocca nessuno dopo il render, quindi per loro va bene la lettura pigra
   dentro rimappa(). */
function seedBasiBarre (c) {
    var bars = c.querySelector('.bars');
    if (!bars || bars.dataset.basiPronte === '1') return;

    var tw = parseInt(c.getAttribute('data-total-words') || '0', 10);
    var tc = parseInt(c.getAttribute('data-total-cards') || '0', 10) || tw;
    var pos = parseInt(c.getAttribute('data-pos') || '1', 10);
    var testa = parseInt(c.getAttribute('data-carte-testa') || '1', 10);
    var frontePagine = parseInt(c.getAttribute('data-furthest-page') || '0', 10);
    var fronteCarte = parseInt(c.getAttribute('data-furthest-card') || '0', 10);

    /* Il riempimento PIENO arriva sempre fino a pippo (mai oltre): vedi la
       nota estesa nel PHP su $bordo_pos. L'eventuale tratto in piu' fino al
       fronte vero (quando si sta rivedendo qualcosa di passato) e' un
       elemento SEPARATO e piu' tenue - .fill-pages-oltre - che parte da dove
       finisce quello pieno, non da 0: per questo serve seedare anche il suo
       baseLeft, non solo la larghezza.
       ATTENZIONE alla convenzione: pctMarker (centro cella) come pippo e
       dot-target, NON pctFill (bordo cella) - altrimenti il bordo pieno cade
       mezza cella dopo pippo, e quello tenue mezza cella prima di
       dot-target: esattamente il "gradino subito dopo pippo" e il "si ferma
       prima del pallino finale" notati. */
    var fp = bars.querySelector('.progress-bar-fill.fill-pages');
    var bordoQuiPagine = pctMarker(pos, tw);
    if (fp) {
        fp.dataset.baseLeft = 0;
        fp.dataset.baseWidth = bordoQuiPagine;
    }
    var fpOltre = bars.querySelector('.progress-bar-fill.fill-pages-oltre');
    if (fpOltre) {
        fpOltre.dataset.baseLeft = bordoQuiPagine;
        fpOltre.dataset.baseWidth = Math.max(0, pctMarker(frontePagine, tw) - bordoQuiPagine);
    }

    var fc = bars.querySelector('.progress-bar-fill.fill-cards');
    var bordoQuiCarte = pctMarker(testa, tc);
    if (fc) {
        fc.dataset.baseLeft = 0;
        fc.dataset.baseWidth = bordoQuiCarte;
    }
    var fcOltre = bars.querySelector('.progress-bar-fill.fill-cards-oltre');
    if (fcOltre) {
        fcOltre.dataset.baseLeft = bordoQuiCarte;
        fcOltre.dataset.baseWidth = Math.max(0, pctMarker(fronteCarte, tc) - bordoQuiCarte);
    }

    var dq = bars.querySelector('.dot-qui:not(.dot-qui-alt)');
    /* Stessa convenzione dei pallini discreti (centro cella, non bordo): vedi
       la nota nel PHP sopra a $qui_percent - coincidere con cio' che si
       clicca (rossi, ritorno, raggruppato) conta piu' che toccare la punta
       esatta del riempimento. */
    if (dq) dq.dataset.baseLeft = pctMarker(pos, tw);

    var dqa = bars.querySelector('.dot-qui-alt');
    if (dqa) dqa.dataset.baseLeft = pctMarker(testa, tc);

    bars.dataset.basiPronte = '1';
}

function applicaZoomBarra (c) {
    c = c || curC();
    if (!c) return;

    var bars = c.querySelector('.bars');
    if (!bars) return;

    var barreEl = bars.querySelectorAll('.progress-bar-bg');
    if (!barreEl.length) return;

    seedBasiBarre(c);

    /* La scelta dell'utente vive fuori dal contenitore (vedi __vistaIntera):
       qui la si riporta sulla classe, non il contrario. */
    bars.classList.toggle('vista-intera', __vistaIntera);

    /* Si decide SE ingrandire guardando sempre i concetti (e' la barra che
       da' il senso "quante ne mancano"): le carte possono essere di piu' o
       di meno a seconda di quanti figli ci sono, ma non sono loro a decidere
       se la vista e' troppo compressa per essere leggibile. */
    var totaleConcetti = parseInt(c.getAttribute('data-total-words') || '0', 10);
    var attivo = totaleConcetti > BARRA_SOGLIA_ZOOM && !__vistaIntera;
    bars.classList.toggle('zoom-attivo', attivo);

    /* La finestra e' una FRAZIONE del totale (es. 12 concetti su 104 = un
       ottavo circa): la stessa frazione, applicata al totale delle carte,
       tiene le due barre sincronizzate senza bisogno di convertire una
       posizione nell'altra scala. */
    var frazione = BARRA_FINESTRA / Math.max(1, totaleConcetti);
    var scala = attivo ? (1 / frazione) : 1;

    /* Ogni barra ha il proprio totale e la propria posizione corrente: usare
       quelli dei concetti anche per la barra delle carte (che ha un totale
       diverso, es. 173 invece di 104) la spingeva a percentuali fuori scala
       - da qui la barra "sparita". */
    var infoPerBarra = [
        { totale: totaleConcetti, pos: parseInt(c.getAttribute('data-pos') || '1', 10) },
        { totale: parseInt(c.getAttribute('data-total-cards') || '0', 10) || totaleConcetti,
          pos: parseInt(c.getAttribute('data-carte-testa') || '1', 10) }
    ];

    function inizioFinestraDi (info, idx) {
        if (!attivo || !info.totale) return 0;
        var meta = frazione / 2;
        /* ATTENZIONE alla convenzione: dot-qui usa di nuovo il centro-cella
           ((pos - 0.5) / totale, come ogni altro pallino discreto - vedi la
           nota su $qui_percent lato PHP). Il centro "ideale" della finestra
           usa la STESSA formula, cosi' quando la finestra SI ricentra
           dot-qui finisce sempre esattamente a meta'. */
        var posFraz = (info.pos - 0.5) / info.totale;
        var idealeInizio = Math.max(0, Math.min(1 - frazione, posFraz - meta));

        /* La finestra e' APPICCICOSA: resta ferma finche' la posizione
           corrente ci sta ancora dentro, e si ricentra SOLO quando ne
           uscirebbe. Prima si ricentrava a ogni singola navigazione, anche
           per un passo solo (da 10 a 8): tutti i pallini - anche quelli che
           con quello spostamento non c'entravano nulla - cambiavano posto
           sullo schermo, perche' cambiavano i bordi della finestra. Con
           l'isteresi, spostandosi DENTRO alla finestra gia' visibile resta
           tutto fermo e si muove solo pippo; la finestra scatta a un nuovo
           centro solo quando la posizione richiesta e' davvero fuori vista -
           esattamente come lo scorrimento automatico di un editor di testo,
           non come una macchina fotografica che insegue ogni movimento. */
        var corrente = __finestraInizio[idx];
        var fuoriVista = (corrente === undefined)
            || posFraz < corrente - 1e-9
            || posFraz > corrente + frazione + 1e-9;

        if (fuoriVista) __finestraInizio[idx] = idealeInizio;

        return __finestraInizio[idx] * 100;
    }

    /* Alcuni pallini non devono MAI sparire del tutto, anche se la loro
       posizione vera cade fuori dalla finestra ingrandita: restano ancorati
       al bordo piu' vicino invece di essere ritagliati dal clip-path del
       contenitore - che prima li faceva sparire senza alcun indizio che ci
       fosse qualcosa da quella parte.
       dot-target e' stato aggiunto all'elenco: e' il riferimento per tornare
       alla prima carta ancora da rispondere, e sparire era proprio quello che
       faceva nell'unico momento in cui serve davvero. Tornando indietro a un
       errore (es. dalla 40 alla 7) la finestra si ricentra sulla 7, il
       riferimento alla 40 finisce oltre il bordo destro e restava invisibile:
       per tornare avanti non c'era piu' niente da toccare. */
    function nonSparireMai (el) {
        return el.classList.contains('dot-qui')
            || el.classList.contains('dot-cluster')
            || el.classList.contains('dot-target');
    }

    function rimappa (elementi, inizioFinestra) {
        elementi.forEach(function (el) {
            if (el.dataset.baseLeft === undefined) {
                el.dataset.baseLeft = parseFloat(el.style.left) || 0;
                el.dataset.baseWidth = parseFloat(el.style.width);
            }
        });
        elementi.forEach(function (el) {
            var base = parseFloat(el.dataset.baseLeft) || 0;
            var largh = parseFloat(el.dataset.baseWidth);

            if (el.classList.contains('progress-bar-fill')) {
                /* Le due -oltre non partono da 0 come le altre (partono da
                   dove finisce il tratto pieno, cioe' da pippo): serve il
                   ritaglio pieno contro la finestra (inizio E fine), non solo
                   la scorciatoia "width = (largh - inizio) * scala" buona
                   quando si parte sempre dal bordo sinistro vero. */
                if (el.classList.contains('fill-pages-oltre') || el.classList.contains('fill-cards-oltre')) {
                    /* A vista intera (attivo=false) la "finestra" e' l'intera
                       barra (0-100), non frazione*100: altrimenti qui si
                       userebbe una finestra stretta anche senza zoom,
                       tagliando via il tratto oltre pippo pure a vista intera. */
                    var fineFinestra = attivo ? (inizioFinestra + frazione * 100) : 100;
                    var visStart = Math.max(base, inizioFinestra);
                    var visEnd = Math.min(base + largh, fineFinestra);
                    if (visEnd <= visStart) {
                        el.style.width = '0%';
                    } else {
                        el.style.left = ((visStart - inizioFinestra) * scala) + '%';
                        el.style.width = ((visEnd - visStart) * scala) + '%';
                    }
                    return;
                }
                el.style.width = Math.max(0, Math.min(100, (largh - inizioFinestra) * scala)) + '%';
                return;
            }

            var sinistra = (base - inizioFinestra) * scala;

            /* Il blocco al bordo (e la freccetta che indica "sta di la'") ha
               senso SOLO quando la finestra ingrandita e' davvero ritagliata
               (attivo === true): e' li' che un valore fuori [0,100] verrebbe
               tagliato via dal clip-path senza alcun indizio. In vista intera
               (attivo === false) sinistra e' gia' la percentuale vera sull'
               intera barra, e forzarla comunque tra 3 e 97 spingeva il
               pallino "sei qui" della prima carta a 3% invece che a bordo
               sinistro vero - che e' esattamente il "non scorre piu' indietro
               della prima carta" notato. */
            if (nonSparireMai(el)) {
                if (attivo) {
                    var bloccato = Math.max(3, Math.min(97, sinistra));
                    el.classList.toggle('dot-oltre-sx', sinistra < bloccato - 0.01);
                    el.classList.toggle('dot-oltre-dx', sinistra > bloccato + 0.01);
                    sinistra = bloccato;
                } else {
                    el.classList.remove('dot-oltre-sx', 'dot-oltre-dx');
                }
            }

            el.style.left = sinistra + '%';
            if (!isNaN(largh)) el.style.width = (largh * scala) + '%';
        });
    }

    barreEl.forEach(function (barra, idx) {
        var info = infoPerBarra[idx] || infoPerBarra[0];
        var elementi = barra.querySelectorAll('.dot, .cat-tacca, .cat-tratto, .progress-bar-fill');
        rimappa(elementi, inizioFinestraDi(info, idx));
    });

    /* La striscia dei nomi di categoria segue la barra dei concetti, la
       prima: e' su quella scala che le categorie sono definite - e usa
       percio' la finestra "appiccicosa" con indice 0, la stessa della barra
       dei concetti, non una sua copia indipendente. */
    var catStrip = bars.querySelector('.cat-strip');
    if (catStrip) {
        rimappa(catStrip.querySelectorAll('.cat-seg'), inizioFinestraDi(infoPerBarra[0], 0));
    }

    /* Traccia d'insieme: sempre in scala 1:1 sull'intera partita, quindi NON
       passa da rimappa(). E' l'unico elemento che continua a dire "quanto
       manca" quando la barra sotto e' ingrandita su una finestra mobile. */
    var insieme = bars.querySelector('.barra-insieme');
    if (insieme) {
        var insFill = insieme.querySelector('.insieme-fill');
        if (insFill) {
            insFill.style.width = pctFill(
                parseInt(c.getAttribute('data-furthest-page') || '0', 10), totaleConcetti) + '%';
        }
    }
}

function alternaVistaBarra (ev) {
    var c = curC();
    if (!c) return;
    var totale = parseInt(c.getAttribute('data-total-words') || '0', 10);
    if (!totale || totale <= BARRA_SOGLIA_ZOOM) return;

    /* Solo i pallini che FANNO qualcosa si tengono il tocco: rosso (vai
       all'errore), raggruppato (apri l'elenco), ritorno (torna al fronte),
       grigio (voto in sospeso). Il pallino "sei qui" invece non ha nessuna
       azione propria - e' un indicatore - quindi prima toccarlo non faceva
       assolutamente niente, e siccome e' ancorato al centro della finestra
       era anche il punto piu' naturale su cui premere. Adesso si comporta
       come il resto della barra e alterna la vista. */
    if (ev && ev.target.closest && ev.target.closest('.dot:not(.dot-qui)')) return;

    __vistaIntera = !__vistaIntera;

    /* Rientrando nello zoom dopo aver visto la vista intera, la finestra
       riparte centrata da capo sulla posizione attuale, invece di riprendere
       quella di prima (che nel frattempo, guardando la vista intera, si
       poteva anche aver toccato un errore lontano - riprenderla li' sarebbe
       stato spiazzante quanto il difetto appena corretto). */
    if (!__vistaIntera) __finestraInizio = {};

    applicaZoomBarra(c);
}
window.alternaVistaBarra = alternaVistaBarra;

function schedulePrefetch () {
    /* Sicuro: la sequenza della partita e' fissata all'avvio, quindi
       visitare una posizione e' una pura lettura e non crea nulla. */
    var c = curC();
    if (!c) return;
    var pos = parseInt(c.getAttribute('data-pos') || '1', 10);
    var totale = parseInt(c.getAttribute('data-total-words') || '0', 10);

    /* Le ondate programmate per la posizione precedente non servono piu':
       si annullano, altrimenti arriverebbero a chiedere pagine ormai fuori
       dalla finestra proprio mentre servono quelle nuove. */
    clearTimeout(__ondataPrefetch2);
    clearTimeout(__ondataPrefetch3);

    /* Prima si fa spazio, poi si chiede: cosi' la coda non parte gia' piena
       di roba che sta per essere buttata. */
    pulisciCacheFuoriFinestra(pos, totale);

    /* Tre ondate invece di una sola raffica: ogni posizione e' una PAGINA
       intera (padre piu' tutti i suoi figli), quindi chiederne cinque tutte
       insieme significa cinque volte il lavoro di database in un colpo -
       ed e' quello che faceva rifiutare qualche richiesta all'hosting.

       Prima ondata: la successiva, subito e da sola. E' l'unica che serve
       entro un secondo, perche' la usa anche l'anteprima dello swipe. */
    if (!totale || pos + 1 <= totale) prefetchGet(urlPerPosizione(pos + 1, true));

    function accodaIntervallo (da, a) {
        for (var i = da; i <= a; i++) {
            if (totale && pos + i > totale) return;
            accodaPrefetch(urlPerPosizione(pos + i, true));
        }
    }

    /* Seconda ondata: le due dopo, quando la prima ha gia' avuto il tempo di
       arrivare. Servono per chi sfoglia veloce, non per il caso normale. */
    __ondataPrefetch2 = setTimeout(function () {
        accodaIntervallo(2, 3);

        /* Terza ondata: le ultime due, piu' l'indietro. Qui siamo nel
           "casomai", quindi si aspetta ancora un po' e non si ruba banda a
           niente di piu' urgente. */
        __ondataPrefetch3 = setTimeout(function () {
            accodaIntervallo(4, PREFETCH_AVANTI);
            for (var j = 1; j <= PREFETCH_INDIETRO; j++) {
                if (pos - j < 1) break;
                accodaPrefetch(urlPerPosizione(pos - j, true));
            }
        }, 1200);
    }, 500);
}

/* ========================================================================
   RESILIENZA OFFLINE: un voto mandato in background che fallisce NON deve
   sparire nel nulla. Resta in una coda salvata in localStorage (sopravvive
   anche chiudendo la scheda), mostra un pallino grigio sulla barra al posto
   giusto, e si ritenta da solo appena torna la connessione (o comunque ogni
   pochi secondi come rete di sicurezza).
   ======================================================================== */

var GAME_ID_PER_CODA = <?php echo (int)$game_id; ?>;
var CHIAVI_GIA_RISPOSTE = <?php echo json_encode($chiavi_risposte); ?>;
var PENDING_KEY = 'standout_pending_grades_' + GAME_ID_PER_CODA;

function loadPendingGrades () {
    try {
        var raw = localStorage.getItem(PENDING_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (e) { return {}; }
}
function savePendingGrades () {
    try { localStorage.setItem(PENDING_KEY, JSON.stringify(pendingGrades)); } catch (e) { /* modalita' privata: pazienza */ }
}
var pendingGrades = loadPendingGrades();   // chiave "position:imgId" -> {url, position, cardIndex}

/* L'icona "manca la connessione" non deve comparire per un rallentamento
   passeggero: solo se i tentativi falliscono da un po'. */
var OFFLINE_SHOW_AFTER_MS = 8000;
var offlineSince = null;
var offlineShowTimer = null;

function noteGradeFailure (eraDiRete) {
    /* Un errore del server (che quindi ha risposto) non e' assenza di
       connessione: si mette in coda per riprovare, ma senza dire all'utente
       che manca la rete. E se il browser stesso dice di essere in linea, non
       lo si contraddice. */
    if (eraDiRete === false) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === true && eraDiRete !== true) return;
    if (!offlineSince) {
        offlineSince = Date.now();
        clearTimeout(offlineShowTimer);
        offlineShowTimer = setTimeout(function () {
            if (offlineSince) setOfflineIndicator(true);
        }, OFFLINE_SHOW_AFTER_MS);
    }
}
function noteGradeSuccess () {
    offlineSince = null;
    clearTimeout(offlineShowTimer);
    setOfflineIndicator(false);
}
function setOfflineIndicator (mostra) {
    var el = document.getElementById('offlineIndicator');
    if (el) el.style.display = mostra ? 'flex' : 'none';
}

var pendingToastTimer = null;
function showPendingToast (messaggio) {
    var el = document.getElementById('pendingToast');
    if (!el) return;
    el.textContent = messaggio || T('fc.gm_in_attesa_salvataggio');
    el.classList.add('show');
    clearTimeout(pendingToastTimer);
    pendingToastTimer = setTimeout(function () { el.classList.remove('show'); }, 3200);
}

/* Aggiunge (se non c'e' gia') il pallino grigio sulle barre di progresso per
   questa voce in sospeso. Sulla barra "carte" serve l'indice giusto - se non
   lo conosciamo (carta non piu' in pagina) si salta quella barra piuttosto
   che piazzarlo nel punto sbagliato. */
function addPendingDot (entry) {
    var c = curC();
    if (!c) return;
    var totalWords = parseInt(c.getAttribute('data-total-words') || '0', 10) || 1;
    var totalCards = parseInt(c.getAttribute('data-total-cards') || '0', 10) || totalWords;
    /* Solo le barre del contenitore CORRENTE: con document.querySelectorAll,
       durante una transizione i contenitori in pagina sono due e l'elenco
       diventava [vecchia-concetti, vecchia-carte, nuova-concetti, ...] - cosi'
       il test i === 1 scambiava la barra delle carte con quella vecchia, e il
       pallino finiva sulla barra sbagliata o spariva col contenitore vecchio. */
    var bars = c.querySelectorAll('.progress-bar-bg');

    bars.forEach(function (bar, i) {
        if (bar.querySelector('.dot-pending[data-pending-key="' + entry.key + '"]')) return;
        var isCardsBar = (i === 1);
        if (isCardsBar && !entry.cardIndex) return;   // non sappiamo dove metterlo: meglio ometterlo che sbagliare
        var total = isCardsBar ? totalCards : totalWords;
        var idx = isCardsBar ? entry.cardIndex : entry.position;
        var percent = pctMarker(idx, total);

        var a = document.createElement('a');
        a.href = 'fc_game.php?game_id=' + GAME_ID_PER_CODA + '&position=' + entry.position + '&status=' + (c.getAttribute('data-status') || '0') + '&order=' + (c.getAttribute('data-order') || 'random');
        a.className = 'dot dot-gray dot-pending';
        a.setAttribute('data-pending-key', entry.key);
        /* Prima si scriveva solo style.left con la percentuale grezza: su una
           barra ingrandita finiva nel punto sbagliato e nessuno lo rimappava
           piu', perche' renderAllPendingDots() gira DOPO applicaZoomBarra().
           Scrivendo la base, la chiamata qui sotto lo mette dove va. */
        a.dataset.baseLeft = percent;
        a.style.left = percent + '%';
        a.title = T('fc.gm_in_attesa_salvataggio');
        a.addEventListener('click', function () { showPendingToast(); }, true);
        bar.appendChild(a);
    });

    if (typeof applicaZoomBarra === 'function') applicaZoomBarra(c);
}
function removePendingDots (key) {
    document.querySelectorAll('.dot-pending[data-pending-key="' + key + '"]').forEach(function (el) { el.remove(); });
}
/* Toglie dalla coda i voti che il server risulta aver gia' registrato: senza
   questa pulizia un pallino grigio poteva restare per sempre, anche a voto
   ormai salvato. */
function purgaVotiGiaSalvati () {
    if (!CHIAVI_GIA_RISPOSTE || !CHIAVI_GIA_RISPOSTE.length) return;
    var salvate = {};
    CHIAVI_GIA_RISPOSTE.forEach(function (k) { salvate[k] = true; });

    var cambiato = false;
    Object.keys(pendingGrades).forEach(function (key) {
        if (salvate[key]) {
            delete pendingGrades[key];
            removePendingDots(key);
            cambiato = true;
        }
    });
    if (cambiato) { savePendingGrades(); noteGradeSuccess(); }
}

function renderAllPendingDots () {
    Object.keys(pendingGrades).forEach(function (key) { addPendingDot(pendingGrades[key]); });
}

/* Un solo punto che manda davvero la richiesta POST del voto, usato sia dal
   primo tentativo sia da ogni riprova successiva. */
function inviaVotoRichiesta (entry) {
    var sp = splitAzione(entry.url);
    if (!sp.post) return Promise.resolve(true);
    var fd = new FormData();
    Object.keys(sp.post).forEach(function (k) { fd.append(k, sp.post[k]); });
    fd.append('csrf_token', STANDOUT_CSRF);
    return fetch(sp.url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': STANDOUT_CSRF }, redirect: 'manual' })
        .then(function (res) {
            /* Una risposta "opaca" e' proprio il caso buono: il server ha
               accettato il voto e ha risposto con un redirect, che qui non
               vogliamo seguire. */
            if (res.type === 'opaqueredirect' || res.status === 0) return true;
            if (!res.ok) {
                /* Il server ha RISPOSTO, anche se con un errore: la connessione
                   c'e' eccome. Si segnala come problema del server, non come
                   assenza di rete - altrimenti compariva "manca la connessione"
                   con la rete perfettamente funzionante. */
                var e = new Error('risposta HTTP ' + res.status);
                e.rete = false;
                throw e;
            }
            return true;
        })
        .catch(function (err) {
            /* Solo qui la rete e' davvero il sospetto: fetch fallisce senza
               risposta. Si marca l'errore perche' chi chiama sappia se deve
               parlare di connessione o no. */
            if (err.rete === undefined) err.rete = true;
            throw err;
        });
}

function retryPendingGrades () {
    var keys = Object.keys(pendingGrades);
    keys.forEach(function (key) {
        var entry = pendingGrades[key];
        inviaVotoRichiesta(entry).then(function () {
            noteGradeSuccess();
            delete pendingGrades[key];
            savePendingGrades();
            removePendingDots(key);
        }).catch(function (err) {
            noteGradeFailure(err && err.rete);   // resta in coda: si ritenta al prossimo giro
        });
    });
}
window.addEventListener('online', retryPendingGrades);
setInterval(retryPendingGrades, 7000);

function inviaVotoConCoda (url, cardIndex) {
    var sp = splitAzione(url);
    if (!sp.post) return;
    var position = 0;
    try { position = parseInt(new URL(url, window.location.href).searchParams.get('position'), 10) || 0; } catch (e) {}
    var key = position + ':' + (sp.post.img_id || '');
    var entry = { key: key, url: url, position: position, cardIndex: cardIndex || 0 };

    inviaVotoRichiesta(entry).then(function () {
        noteGradeSuccess();
    }).catch(function (err) {
        console.error('[voto] invio fallito, verra\' ritentato automaticamente', err);
        noteGradeFailure(err && err.rete);
        pendingGrades[key] = entry;
        savePendingGrades();
        addPendingDot(entry);
    });
}

/* "Imparato" e' indipendente dal voto: non fa avanzare la carta, funziona
   anche rivedendo una carta gia' valutata, e si puo' sia accendere sia
   spegnere. Una chiamata leggera, senza passare da navigateTo(). */
document.addEventListener('click', function (e) {
    if (window.__standoutModaleAperto) return;
    if (typeof fcExUltimoSwipe !== 'undefined' && Date.now() - fcExUltimoSwipe < 350) return;
    var box = e.target.closest('.qa-examples-box-filled');
    if (!box) return;
    var fcId = parseInt(box.getAttribute('data-fc-id'), 10) || 0;
    var fcType = box.getAttribute('data-fc-type') || 'parent';
    var card = box.closest('.card-box');
    var qEl = card ? card.querySelector('.q-section .raw-text') : null;
    var aEl = card ? card.querySelector('.answer-box .raw-text') : null;
    var qText = qEl ? (qEl.getAttribute('data-raw') || '') : '';
    var aText = aEl ? (aEl.getAttribute('data-raw') || '') : '';
    if (typeof openExamplesModal === 'function') openExamplesModal(fcId, fcType, qText, aText);
});

function toggleLearnt (btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'togglelearnt');
    fd.append('img_id', btn.getAttribute('data-learnt-id'));
    fd.append('is_child', btn.getAttribute('data-is-child') || '0');
    fd.append('csrf_token', STANDOUT_CSRF);

    fetch('fc_game.php?game_id=' + GAME_ID_PER_CODA, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': STANDOUT_CSRF } })
        .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.error || 'errore');
            btn.setAttribute('aria-pressed', data.learnt ? 'true' : 'false');
            btn.classList.toggle('is-active', data.learnt);
            if (data.learnt) {
                btn.classList.remove('pop');
                void btn.offsetWidth;   // riavvia l'animazione anche se era gia' stata fatta poco prima
                btn.classList.add('pop');
            }
        })
        .catch(function (err) {
            console.error('[imparato] non salvato', err);
            if (typeof showPendingToast === 'function') showPendingToast(T('fc.gm_imparato_errore'));
        })
        .then(function () { btn.disabled = false; });
}

/* Gli onclick scritti nell'HTML cercano queste funzioni su window: qui non
   erano globali (stanno dentro lo scope del blocco <script>), da cui
   "toggleFavorite is not defined" al primo clic sulla stella. */
window.toggleLearnt = toggleLearnt;

function toggleFavorite (btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'togglefavorite');
    fd.append('img_id', btn.getAttribute('data-learnt-id'));
    fd.append('is_child', btn.getAttribute('data-is-child') || '0');
    fd.append('csrf_token', STANDOUT_CSRF);

    /* L'indirizzo deve portare il game_id: senza, la pagina non trova la
       partita e reindirizza PRIMA di arrivare al gestore dell'azione - al
       browser tornava una pagina HTML invece del JSON, da cui l'errore. */
    fetch('fc_game.php?game_id=' + GAME_ID_PER_CODA, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': STANDOUT_CSRF } })
        .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.error || 'errore');
            btn.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
            btn.classList.toggle('is-active', data.favorite);
            var cardEl = btn.closest('.card-box');
            if (cardEl) cardEl.classList.toggle('is-favorita', data.favorite);
            if (data.favorite) {
                btn.classList.remove('pop');
                void btn.offsetWidth;
                btn.classList.add('pop');
            }
        })
        .catch(function (err) {
            console.error('[preferiti] non salvato', err);
            if (typeof showPendingToast === 'function') showPendingToast(T('fc.gm_imparato_errore'));
        })
        .then(function () { btn.disabled = false; });
}
window.toggleFavorite = toggleFavorite;

/* Se questa e' l'ultima carta non ancora valutata a questa posizione, il voto
   fara' sicuramente avanzare a position+1 - e quale carta sia non dipende in
   alcun modo dall'esito appena dato (il gestore di action=grade fa solo un
   redirect, la selezione della carta e' la stessa identica funzione sia
   arrivandoci votando sia navigando avanti a mano). Percio' si puo' mostrare
   subito quella gia' precaricata, mandando il voto per davvero ma senza
   aspettarne la risposta. Se ci sono altri fratelli ancora da valutare in
   questa posizione, invece, si resta con il percorso sicuro: bisogna sapere
   davvero cosa dice il server, perche' potrebbe restare qui. */
/* Segna la carta come valutata SUL POSTO, senza ricaricare la posizione.
   Prima, votare un figlio faceva una transizione completa verso la stessa
   posizione: mezzo secondo di animazione a tutta pagina (da cui lo schermo
   bianco) solo per marcare una carta e restare esattamente li'. Qui invece
   si aggiorna la sola carta toccata e le altre restano ferme sotto gli
   occhi. */
function segnaCartaValutataInPagina (card, esito) {
    if (!card) return;

    /* gradeFeedback() e lo swipe fanno volare via la carta (traslata fuori
       schermo, opacita' 0): serviva quando subito dopo si cambiava pagina.
       Restando qui, quella carta deve tornare al suo posto - altrimenti
       resta invisibile e lascia solo il buco dove stava. */
    card.style.zIndex = '';
    card.style.transition = 'none';
    card.style.transform = 'translateY(-6px) scale(.99)';
    card.style.opacity = '0';
    void card.offsetWidth;
    card.style.transition = 'transform .3s var(--spring), opacity .3s ease-out';
    card.style.transform = 'none';
    card.style.opacity = '1';

    card.classList.add('graded');
    card.classList.add(esito === 'correct' ? 'res-correct' : 'res-wrong');
    card.setAttribute('data-swipe', '0');

    avanzaBarraCarte();

    /* Via i comandi di voto: da qui in poi questa carta non si vota piu'. */
    var gradeRow = card.querySelector('.grade-row');
    if (gradeRow) gradeRow.remove();

    /* Via anche "frantuma" e il tasto "Mostra risposta" grande: una carta
       gia' risposta li' non li ha (vedi il ramo "gia' valutata" del PHP). */
    var toolRow = card.querySelector('.tool-row');
    if (toolRow) toolRow.remove();
    var btnShow = card.querySelector('[id^="btn-show-"]');
    if (btnShow) btnShow.remove();

    /* La risposta si richiude, con un tastino per riaprirla: identico a come
       la disegna il server per una carta gia' risposta. */
    var ansBox = card.querySelector('.answer-box.ans-block');
    if (ansBox) {
        ansBox.style.display = 'none';
        ansBox.classList.add('is-revealed');
        ansBox.style.marginTop = '12px';
    }

    /* La pastiglia con l'esito, come quella che disegnerebbe il server. */
    if (!card.querySelector('.status-pill')) {
        var testa = document.createElement('div');
        testa.className = 'graded-head';
        var ok = (esito === 'correct');
        testa.innerHTML = '<div class="status-pill ' + (ok ? 'ok' : 'ko') + '">'
            + '<span class="gi">' + (ok ? '\u2713' : '\u2715') + '</span> '
            + fcEscapeHtmlLocale(ok ? T('fc.res_corretto') : T('fc.vg_sbagliato')) + '</div>'
            + '<button class="nav-btn pill-toggle action-btn" type="button">' + fcEscapeHtmlLocale(T('fc.gm_mostra_risposta')) + '</button>';
        var titolo = card.querySelector('.card-title');
        if (titolo) titolo.insertAdjacentElement('afterend', testa);
        else card.insertAdjacentElement('afterbegin', testa);
        var btnToggle = testa.querySelector('.pill-toggle');
        if (btnToggle && ansBox) {
            btnToggle.addEventListener('click', function () {
                var chiuso = (ansBox.style.display === 'none' || ansBox.style.display === '');
                ansBox.style.display = chiuso ? 'block' : 'none';
                if (chiuso) ansBox.classList.add('reveal-anim');
                btnToggle.innerText = chiuso ? T('fc.gm_nascondi_risposta') : T('fc.gm_mostra_risposta');
                if (chiuso && typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(ansBox);
            });
        }

        var pill = testa.querySelector('.status-pill');
        try {
            pill.animate([{ transform: 'scale(.6)', opacity: 0 }, { transform: 'scale(1.08)', opacity: 1 }, { transform: 'scale(1)', opacity: 1 }], { duration: 420, easing: EASE });
        } catch (e) {}
    }
}

/* Rispondendo a un figlio non si ricarica la pagina, quindi la barra delle
   carte - che il server disegna al caricamento - resterebbe ferma per tutta
   la posizione. Qui la si fa avanzare di una carta, come farebbe il server
   al giro dopo.

   ATTENZIONE alle TRE grandezze distinte, che prima erano una sola:
     - "testa"     = su quale carta si e' ADESSO      -> muove il pallino
     - "furthest"  = la carta piu' avanzata raggiunta -> muove il riempimento
     - "risposte"  = quante ne sono state risposte    -> muove l'etichetta
   Usare la testa anche per il riempimento faceva crollare la barra arancione
   all'indietro (da 87% a 7%) appena si rispondeva a un errore vecchio, per poi
   rivederla risalire alla ricarica successiva: era il salto piu' vistoso di
   tutti. Il riempimento non deve MAI retrocedere. */
function avanzaBarraCarte () {
    var c = curC();
    if (!c) return;

    var totale = parseInt(c.getAttribute('data-total-cards') || '0', 10);
    if (!totale) return;

    var testa = parseInt(c.getAttribute('data-carte-testa') || '1', 10) + 1;
    if (testa > totale) testa = totale;
    c.setAttribute('data-carte-testa', String(testa));

    /* Si aggiorna il MODELLO (dataset.base*), non la geometria a schermo: e'
       da li' che applicaZoomBarra() ricalcola tutto con lo zoom giusto.
       Stessa convenzione a centro cella dei pallini discreti: vedi la nota
       su $qui_percent lato PHP. */
    var dotQui = c.querySelector('.dot-qui-alt');
    if (dotQui) dotQui.dataset.baseLeft = pctMarker(testa, totale);

    /* Il tratto PIENO segue sempre pippo (testa), mai il fronte: stessa
       divisione in due toni spiegata lato PHP su $bordo_pos. Convenzione
       pctMarker come pippo/dot-target, non pctFill: vedi la stessa nota
       nel PHP e in seedBasiBarre. */
    var bordoQuiCarte = pctMarker(testa, totale);
    var fill = c.querySelector('.progress-bar-fill.fill-cards');
    if (fill) fill.dataset.baseWidth = bordoQuiCarte;

    var furthest = parseInt(c.getAttribute('data-furthest-card') || '0', 10);
    if (testa > furthest) {
        furthest = testa;
        c.setAttribute('data-furthest-card', String(furthest));
    }
    /* Il tratto tenue oltre pippo: resta a 0 nel caso comune (si sta
       avanzando proprio sul fronte, quindi furthest === testa), ma non deve
       sparire se si sta valutando un figlio di una posizione gia' dietro al
       fronte vero raggiunto altrove. */
    var fillOltre = c.querySelector('.progress-bar-fill.fill-cards-oltre');
    if (fillOltre) {
        fillOltre.dataset.baseLeft = bordoQuiCarte;
        fillOltre.dataset.baseWidth = Math.max(0, pctMarker(furthest, totale) - bordoQuiCarte);
    }

    if (typeof applicaZoomBarra === 'function') applicaZoomBarra(c);

    /* L'etichetta "Card 9/173" conta le carte RISPOSTE, che e' quello che ci
       scrive il server ($answered_cards_count). Prima qui ci finiva l'indice
       della carta corrente: due grandezze diverse nello stesso posto, quindi
       il numero cambiava significato a ogni ricaricamento della pagina. */
    var risposte = parseInt(c.getAttribute('data-carte-risposte') || '0', 10) + 1;
    if (risposte > totale) risposte = totale;
    c.setAttribute('data-carte-risposte', String(risposte));

    var etichette = c.querySelectorAll('.hdr-sub b');
    if (etichette.length > 1) etichette[etichette.length - 1].textContent = risposte + '/' + totale;
}

function fcEscapeHtmlLocale (s) {
    var d = document.createElement('div');
    d.textContent = (s == null) ? '' : String(s);
    return d.innerHTML;
}

/* La prossima carta di QUESTA posizione ancora da valutare, cercando prima
   sotto quella appena votata (e' li' che l'utente si aspetta di scendere) e
   solo dopo, se sotto non ce n'e', tornando a guardare sopra. Se e' rimasta
   solo quella appena votata, restituisce null: allora si passa davvero alla
   posizione successiva. */
function prossimaCartaDaValutare (contenitore, cardAppenaVotata) {
    var tutte = Array.prototype.slice.call(contenitore.querySelectorAll('.card-box:not(.anteprima-card)'));
    var i = tutte.indexOf(cardAppenaVotata);
    for (var k = i + 1; k < tutte.length; k++) {
        if (!tutte[k].classList.contains('graded')) return tutte[k];
    }
    return null;
}

function gradeAndAdvance (url, feedbackPromise, cardIndex, cardEl, esito) {
    /* lingue: serie, livello della parola, suoni (fc_game_juice.js) */
    try { if (window.FcGioco) FcGioco.voto(esito, cardEl); } catch (e) {}
    var curContainer = curC();
    var ungraded = curContainer ? curContainer.querySelectorAll('.card-box:not(.graded):not(.anteprima-card)').length : 2;
    var fwdUrl = curContainer ? curContainer.getAttribute('data-forward-url') : null;

    /* Restano altre carte da valutare qui: si aggiorna solo questa e si
       scende alla prima non ancora risposta SOTTO, senza ricaricare niente.
       Se sotto non ce n'e' piu' (l'utente ne aveva saltata una piu' su),
       si resta fermi: la trovera' lui, come chiesto. */
    if (ungraded > 1 && cardEl && curContainer) {
        inviaVotoConCoda(url, cardIndex);
        feedbackPromise.then(function () {
            segnaCartaValutataInPagina(cardEl, esito);
            var succ = prossimaCartaDaValutare(curContainer, cardEl);
            if (succ) {
                /* Scorrimento ISTANTANEO, non 'smooth': con quello animato
                   restava una finestra di alcune centinaia di ms in cui la
                   pagina era ancora in movimento quando il tocco successivo
                   arrivava. Su iPhone, un tocco che arriva mentre la pagina
                   sta ancora scorrendo viene spesso interpretato come "ferma
                   lo scorrimento", non come un tap sul bottone sotto il dito
                   - da qui la necessita' di toccare due volte "Mostra
                   risposta" proprio e solo sui figli (la carta padre, la
                   prima della pagina, non viene mai raggiunta con uno scroll
                   appena prima di essere toccata, quindi non soffriva dello
                   stesso problema). Un salto secco e' meno elegante di uno
                   animato, ma non lascia mai quella finestra di conflitto. */
                try { succ.scrollIntoView({ behavior: 'auto', block: 'center' }); }
                catch (e) { succ.scrollIntoView(); }
            }
        });
        return;
    }

    /* Si passa SEMPRE dal percorso normale: si manda il voto e si aspetta la
       risposta del server, che registra e dice dove andare. E' lui poi a
       ridisegnare tutto - carta, barre, conteggi - in modo coerente.

       Il salto "ottimistico" (mostrare subito la carta successiva mandando il
       voto in background) sembrava piu' veloce ma lasciava lo stato a meta':
       il server, non avendo ancora registrato il voto, rimandava indietro, e
       il progresso restava fermo. La velocita' percepita la da' l'anteprima,
       che copre l'animazione: quando finisce, la pagina vera e' gia' li'. */
    if (fwdUrl) prefetchGet(fwdUrl);
    feedbackPromise.then(function () { navigateTo(url, { cardIndex: cardIndex }); });
}

function navigateTo (url, opts) {
                opts = opts || {};

                if (navLock) {
                    /* Non si scarta: si riprova a brevissimo. Un voto arrivato
                       mentre una transizione precedente e' ancora in corso non
                       deve sparire nel nulla - altrimenti quella posizione
                       (tipicamente un figlio subito dopo un altro) non
                       raggiungerebbe mai "tutti valutati" e la partita
                       resterebbe bloccata li'. */
                    setTimeout(function () { navigateTo(url, opts); }, 120);
                    return;
                }
                navLock = true;
                gestureBlock = false;
                suppressNextClick = false;
                stopAllMedia();
                var oldC = curC();
                if (!oldC) { window.location.href = url; return; }
                var oldFeed = oldC.querySelector('.feed');
                var oldHeader = oldC.querySelector('.app-header');
                var prevPos = parseInt(oldC.getAttribute('data-pos') || '1', 10);
                oldC.style.pointerEvents = 'none';

                /* Se c'e' un'azione, la richiesta diventa un POST con il token. */
                var sp = splitAzione(url);
                var urlOriginale = url;
                var richiesta = { method: 'GET' };
                if (sp.post) {
                    var fd = new FormData();
                    Object.keys(sp.post).forEach(function (k) { fd.append(k, sp.post[k]); });
                    fd.append('csrf_token', STANDOUT_CSRF);
                    richiesta = { method: 'POST', body: fd, headers: { 'X-CSRF-Token': STANDOUT_CSRF } };
                }
                url = sp.url;

                /* Quando si avanza subito dopo un voto mandato in background, il
                   server puo' non averlo ancora registrato: chiedendo la
                   posizione successiva ci rimanderebbe indietro a quella appena
                   votata (e' la sua regola per chi salta avanti). Si chiede
                   allora con &prefetch=1, che quel rimando non lo fa, ma
                   nell'indirizzo si scrive comunque quello pulito. */
                var urlRichiesta = url;
                var pref = !sp.post ? (__prefetchCache[url] || __prefetchCache[url + '&prefetch=1']) : null;
                delete __prefetchCache[url];

                function richiestaNormale () {
                    return fetch(urlRichiesta, richiesta).then(function (res) {
                        if (!res.ok) throw new Error('risposta HTTP ' + res.status);
                        return res.text().then(function (html) { return { res: res, html: html }; });
                    });
                }

                var richiestaPromise = pref
                    ? pref.promise.then(function () {
                        /* Precaricamento scartato (un redirect, o fallito): si
                           riparte da capo con una richiesta normale, senza che
                           l'utente se ne accorga. */
                        if (pref.html === null) return richiestaNormale();
                        return { res: { url: pref.resUrl }, html: pref.html };
                    })
                    : richiestaNormale();

                richiestaPromise.then(function (pack) {

                    if (pack.res.url && pack.res.url.indexOf('fc_result.php') !== -1) { window.location.href = pack.res.url; return; }

                    var doc = new DOMParser().parseFromString(pack.html, 'text/html');

                    var newC = doc.querySelector('.container');

                    if (!newC || !newC.getAttribute('data-pos')) { window.location.href = pack.res.url || url; return; }



                    var newPos = parseInt(newC.getAttribute('data-pos'), 10);

                    var dir = newPos > prevPos ? 'up' : (newPos < prevPos ? 'down' : 'same');



                    // Evita ID duplicati durante la transizione

                    $$('[id]', oldC).forEach(function (n) { n.removeAttribute('id'); });

                    if (oldC.id) oldC.removeAttribute('id');



                    /* Lo zoom si applica ORA, mentre newC e' ancora staccato dal
                       documento (non ancora inserito) - non dopo. Prima veniva
                       chiesto subito dopo document.body.appendChild(newC): il
                       contenitore entrava nel DOM visibile con gli stili
                       GREZZI del server (non zoomati) su pallini e riempimenti,
                       e solo un istante dopo applicaZoomBarra li cambiava in
                       quelli zoomati - un cambiamento che la transizione CSS su
                       left/width vedeva come un movimento vero, animandolo: da
                       "non zoomato" a "zoomato" in 0.3s, ogni singola volta.
                       Sistemando i valori PRIMA che il contenitore sia mai
                       visibile, il primo stato dipinto e' gia' quello zoomato
                       definitivo: la transizione non ha nulla da animare per
                       questo cambio pagina, e resta libera per i movimenti
                       veri (l'avanzamento del riempimento via animateProgress,
                       il prossimo vero spostamento della finestra). Funziona
                       su un nodo staccato perche' applicaZoomBarra lavora solo
                       su data-attribute e stili inline, mai su misure di
                       layout (offsetWidth e simili) che su un nodo fuori dal
                       documento darebbero comunque zero. */
                    applicaZoomBarra(newC);



                    /* Si guarda PRIMA di togliere l'anteprima: rimuovendola per
                       prima, il controllo piu' sotto la trovava sempre assente,
                       scambioMuto restava falso e partiva comunque l'animazione
                       di ingresso dal basso - era questo lo scorrimento che si
                       continuava a vedere. */
                    var scambioMuto = !!(window.anteprimaInCorso && window.anteprimaInCorso());

                    document.body.appendChild(newC);

                    /* Momento esatto in cui l'anteprima ha finito il suo
                       compito: la pagina vera e' ora in pagina. Prima si
                       lascerebbe un vuoto, dopo si sovrapporrebbe a lei. */
                    if (window.togliAnteprima) window.togliAnteprima(true);

                    var newFeed = newC.querySelector('.feed');



                    if (dir === 'same' && oldFeed && newFeed) newFeed.scrollTop = oldFeed.scrollTop;

                    /* Con lo scambio muto la carta nuova deve trovarsi esattamente
                       dove l'anteprima la stava gia' mostrando: in cima. Senza
                       questo, ereditando lo scorrimento di prima, si vedrebbe
                       comparire piu' in basso o piu' in alto. */
                    if (scambioMuto && newFeed) newFeed.scrollTop = 0;

                    /* L'animazione del riempimento (animateProgress) resta
                       DOPO l'inserimento nel DOM e DOPO lo zoom, come prima:
                       lei interpola fra due valori gia' definitivi e zoomati
                       (quello vecchio di oldC, quello nuovo di newC), quindi
                       l'ordine fra lei e l'inserimento non cambia nulla - e'
                       solo lo zoom che doveva anticipare l'inserimento, non
                       anche lei. */
                    animateProgress(oldC, newC);

                    syncHeader();

                    chronoApplyState();



                    var held = opts.held || 0;

                    /* Ridotta da 460ms: era il grosso dell'attesa percepita fra
                       una carta e l'altra, dato che navLock resta attivo per
                       tutta la durata (D+60) e blocca il voto successivo. */
                    var D = 260;

                    try {

                        if (scambioMuto) {

                            D = 1;

                            if (oldHeader) oldHeader.style.opacity = '0';

                            cardsIn(newC, true);

                        } else if (dir === 'up') {

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ transform: 'translateY(' + held + 'px)', opacity: 1 }, { transform: 'translateY(-34%)', opacity: 0 }], { duration: D, easing: EASE, fill: 'both' });

                            newFeed.animate([{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }], { duration: D, easing: EASE, fill: 'both' });

                            cardsIn(newC, true);

                        } else if (dir === 'down') {

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ transform: 'translateY(' + held + 'px)', opacity: 1 }, { transform: 'translateY(34%)', opacity: 0 }], { duration: D, easing: EASE, fill: 'both' });

                            newFeed.animate([{ transform: 'translateY(-100%)' }, { transform: 'translateY(0)' }], { duration: D, easing: EASE, fill: 'both' });

                            cardsIn(newC, true);

                        } else {

                            D = 200;

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200, easing: 'ease-out', fill: 'both' });

                            newFeed.animate([{ opacity: 0, transform: 'scale(.985)' }, { opacity: 1, transform: 'scale(1)' }], { duration: D, easing: EASE, fill: 'both' });

                            $$('.status-pill', newC).forEach(function (p) {

                                p.animate([{ transform: 'scale(.6)', opacity: 0 }, { transform: 'scale(1.08)', opacity: 1 }, { transform: 'scale(1)', opacity: 1 }], { duration: 420, easing: EASE });

                            });

                        }

                    } catch (e) {}



                    /* Mai l'indirizzo tecnico con prefetch=1 nella barra: si
                       scrive quello pulito, cosi' ricaricando la pagina o
                       tornando indietro si ottiene la navigazione normale. */
                    var urlPulito = url;
                    if (pack.res.url && pack.res.url.indexOf('prefetch=1') === -1) urlPulito = pack.res.url;
                    window.history.pushState({}, '', urlPulito);

                    renderAllRawTexts();

                    if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(newC);

                    /* applicaZoomBarra(newC) e' gia' stata chiamata piu' sopra,
                       prima di animateProgress: rifarla qui sovrascriverebbe
                       l'animazione appena avviata con il valore d'arrivo. */

                    schedulePrefetch();

                    renderAllPendingDots();

                    setTimeout(checkAutoPlayQuestion, 300);

                    maybeCoach();
                    document.dispatchEvent(new CustomEvent('fc-pagina', { detail: { container: newC } }));



                    setTimeout(function () {

                        oldC.remove();

                        navLock = false;

                    }, D + 60);

                }).catch(function () {

                    oldC.style.pointerEvents = '';

                    navLock = false;

                    if (sp.post) {

                        var posCatch = 0;

                        try { posCatch = parseInt(new URL(sp.url, window.location.href).searchParams.get('position'), 10) || 0; } catch (e) {}

                        var keyCatch = posCatch + ':' + (sp.post.img_id || '');

                        pendingGrades[keyCatch] = { key: keyCatch, url: urlOriginale, position: posCatch, cardIndex: opts.cardIndex || 0 };

                        savePendingGrades();

                        addPendingDot(pendingGrades[keyCatch]);

                        noteGradeFailure(true);

                        showPendingToast();

                    } else {

                        window.location.href = url;

                    }

                });

            }



            /* Interceptor click: stessa cattura del vecchio (a.btn / a.nav-btn) + pallini,

               stessi esclusi (end-link, result_fc, concludi) */

            document.addEventListener('click', function (e) {

                if (window.__standoutModaleAperto) return;

                if (suppressNextClick) { suppressNextClick = false; e.stopPropagation(); e.preventDefault(); return; }

                if (!e.target || !e.target.closest) return;

                var link = e.target.closest('a.btn, a.nav-btn, a.dot');

                if (!link || !link.href || link.classList.contains('end-link')) return;

                if (link.href.indexOf('fc_result.php') !== -1 || link.href.indexOf('action=concludi') !== -1) return;

                e.preventDefault();

                if (navLock && link.href.indexOf('action=grade') === -1) return;

                if (link.href.indexOf('fc_game.php') === -1) {

                    stopAllMedia();

                    window.location.href = link.href;

                    return;

                }

                if (link.href.indexOf('action=grade') !== -1) {

                    var result = 'correct';

                    try { result = new URL(link.href, window.location.href).searchParams.get('result') || 'correct'; } catch (err) {}

                    var card = link.closest('.card-box');

                    gradeAndAdvance(link.href, gradeFeedback(card, result), parseInt(card.getAttribute('data-card-index') || '0', 10), card, result);

                } else {

                    navigateTo(link.href);

                }

            }, true);



            /* ================================================================

               SWIPE STILE TINDER — tieni premuta la card e trascinala

               ================================================================ */

            var sw = null;



            document.addEventListener('pointerdown', function (e) {

                if (window.__standoutModaleAperto) return;

                if (navLock || sw) return;

                if (e.pointerType === 'mouse' && e.button !== 0) return;

                if (!e.target || !e.target.closest) return;

                var card = e.target.closest('.card-box[data-swipe="1"]:not(.anteprima-card)');

                if (!card) return;

                if (!card.classList.contains('risposta-mostrata')) return;

                var c = curC();

                if (!c || card.closest('.container') !== c) return;

                if (e.target.closest('a, button, audio, select, input, textarea')) return;



                sw = {

                    card: card, pid: e.pointerId,

                    sx: e.clientX, sy: e.clientY, dx: 0, dy: 0,

                    lx: e.clientX, ly: e.clientY,

                    active: false, crossed: false,

                    urlWrong: card.getAttribute('data-url-wrong'),

                    urlCorrect: card.getAttribute('data-url-correct'),

                    timer: setTimeout(activateSwipe, 240)

                };

            });



            /* ---------- Anteprima della carta successiva durante lo swipe ----------
               Mentre si trascina la carta verso il bordo, quella che verra' dopo
               si intravede gia' sotto, e cresce man mano che ci si avvicina al
               punto di rilascio. Se si riporta il dito al centro, torna a
               sparire: cosi' si capisce sempre cosa sta per succedere.

               Si mostra solo quando lo swipe portera' davvero a un'altra
               posizione (ultima carta da valutare qui): restando fra i figli
               non cambia pagina, quindi non ci sarebbe niente da anticipare.
               Il contenuto arriva dalla cache del precaricamento, quindi non
               costa una richiesta in piu'; se non e' ancora pronto si lascia
               perdere - meglio nessuna anteprima che una a scatti. */
            var anteprima = null;

            /* Di quanto sale la carta successiva mentre si trascina, in pixel.
               Sta qui, accanto alla variabile che accompagna, e non piu' in
               mezzo alle funzioni: nella riscrittura precedente era finita
               dentro il blocco sostituito ed e' sparita, lasciando i tre usi
               orfani - da cui l'errore a ogni movimento del dito. */
            var ANTEPRIMA_SALITA = 70;

            function costruisciAnteprima (html, cardTrascinata, cont) {
                if (anteprima) return;

                cont = cont || ((cardTrascinata && cardTrascinata.closest) ? cardTrascinata.closest('.container') : null);
                /* Se nel frattempo quel contenitore e' uscito di scena (la
                   pagina e' gia' cambiata), l'anteprima non serve piu' e
                   soprattutto non deve finire altrove. */
                if (!cont || !cont.isConnected || cont !== curC()) return;

                var doc;
                try { doc = new DOMParser().parseFromString(html, 'text/html'); } catch (e) { return; }

                /* Solo la PRIMA carta (il padre): se la posizione successiva ne
                   ha molte, farle salire tutte insieme sarebbe confuso e pesante.
                   Le altre arrivano con la pagina vera, subito dopo. */
                var primaCarta = doc.querySelector('.feed-inner .card-box, .feed-inner .card-title-header');
                if (!primaCarta) return;

                var feedAttuale = cont.querySelector('.feed-inner');
                if (!feedAttuale) return;

                anteprima = document.createElement('div');
                anteprima.className = 'swipe-anteprima';
                /* Ferma al suo posto e gia' visibile, non in salita da sotto:
                   deve sembrare che la carta successiva fosse li' da sempre,
                   coperta da quella che se ne va. Cosi' le due animazioni si
                   fondono in una sola e non si percepisce nessun "cambio". */
                anteprima.style.transform = 'none';
                anteprima.style.opacity = '1';
                var clone = primaCarta.cloneNode(true);
                /* Marcata come "solo anteprima": e' una fotografia, non una
                   carta del gioco. Senza questo segno veniva contata fra le
                   carte ancora da valutare di QUESTA posizione, il conteggio
                   passava da 1 a 2 e il gioco credeva di dover restare qui
                   invece di passare alla successiva - era questa la causa del
                   "mostra la carta ma non avanza". */
                clone.classList.add('anteprima-card');
                clone.querySelectorAll('.card-box').forEach(function (el) { el.classList.add('anteprima-card'); });
                clone.removeAttribute('data-swipe');
                anteprima.appendChild(clone);

                /* Niente id duplicati in pagina: e' una fotografia, non deve
                   essere raggiungibile da nessuno script. */
                anteprima.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
                anteprima.setAttribute('aria-hidden', 'true');

                /* Va messa dove comparira' la carta VERA, cioe' in cima all'area
                   visibile: la pagina nuova parte sempre dall'inizio. Prima la
                   mettevo all'altezza della carta trascinata - che con un padre
                   e piu' figli puo' essere a meta' pagina - e al momento dello
                   scambio la carta sembrava risalire da sotto. Non era
                   un'animazione: era proprio in un punto diverso. */
                var scroller = cont.querySelector('.feed');
                anteprima.style.top = (scroller ? scroller.scrollTop : 0) + 'px';

                /* Larghezza e rientro presi dalla carta vera, non da left:0/right:0:
                   un elemento in posizione assoluta si allinea al bordo interno del
                   contenitore, ignorandone il rientro laterale, quindi l'anteprima
                   veniva piu' larga della carta - e a fine animazione si vedeva lo
                   scatto di adattamento. */
                var rifCarta = cardTrascinata || feedAttuale.querySelector('.card-box:not(.anteprima-card)');
                if (rifCarta) {
                    anteprima.style.left = rifCarta.offsetLeft + 'px';
                    anteprima.style.width = rifCarta.offsetWidth + 'px';
                    anteprima.style.right = 'auto';
                }

                feedAttuale.appendChild(anteprima);

                /* Il testo delle carte non sta nell'HTML: sta nell'attributo
                   data-raw, e lo converte renderAllRawTexts() quando la pagina
                   viene disegnata. Nell'anteprima quel passaggio non era mai
                   avvenuto, quindi si vedeva la carta con l'etichetta QUESTION
                   ma senza domanda. Si fa qui, sulla sola anteprima. */
                try {
                    anteprima.querySelectorAll('.raw-text').forEach(function (el) {
                        var grezzo = el.getAttribute('data-raw');
                        if (grezzo !== null && typeof parseTextForPreview === 'function') {
                            el.innerHTML = parseTextForPreview(grezzo);
                        }
                    });
                } catch (e) {}

                if (typeof fcdDisegnaFormule === 'function') {
                    try { fcdDisegnaFormule(anteprima); } catch (e) {}
                }

                /* Si allinea subito al punto in cui e' gia' arrivato il dito:
                   se l'HTML e' arrivato a meta' trascinamento, non deve
                   ricominciare da zero. */
                if (sw) aggiornaAnteprima(Math.min(1, Math.abs(sw.dx) / TH()));
            }

            /* SPENTA di proposito.
               L'idea (far salire la carta successiva sotto quella che esce) e'
               giusta, ma ogni versione ha finito per restare appesa sopra la
               pagina nuova o per sfasare la navigazione: si vedevano due carte
               sovrapposte e il gioco non avanzava davvero. Finche' non c'e' un
               modo di ancorarla al contenitore in uscita con la certezza che se
               ne vada con lui, meglio non averla: qui conta piu' che il gioco
               avanzi in modo corretto.
               Per riattivarla basta togliere il return qui sotto. */
            var ANTEPRIMA_ATTIVA = true;

            function creaAnteprima (cardTrascinata) {
                if (!ANTEPRIMA_ATTIVA) return;
                /* Il contenitore si prende ORA, da dove sta la carta che esce,
                   e si porta dietro fino all'inserimento: leggendo "quello
                   corrente" al momento dell'append, una risposta arrivata tardi
                   finiva dentro la pagina NUOVA e ci restava sopra per sempre -
                   erano quelle le carte sovrapposte. */
                var cont = (cardTrascinata && cardTrascinata.closest) ? cardTrascinata.closest('.container') : curC();
                if (!cont) return;

                /* Solo se questo voto fara' cambiare posizione: restando fra i
                   figli non si cambia pagina, quindi non c'e' niente da
                   anticipare. Copre sia l'ultimo figlio di un elenco sia un
                   padre che di figli non ne ha. */
                if (cont.querySelectorAll('.card-box:not(.graded):not(.anteprima-card)').length > 1) return;

                var pos = parseInt(cont.getAttribute('data-pos') || '0', 10);
                if (!pos) return;

                /* Indirizzo con &prefetch=1: chiede la posizione successiva
                   cosi' com'e', senza farsi rimandare indietro alla prima non
                   ancora risposta (che, non avendo ancora votato questa, e'
                   proprio questa - ed e' il motivo per cui sotto compariva la
                   carta corrente invece della successiva). */
                var url = urlPerPosizione(pos + 1, true);

                var entry = __prefetchCache[url];

                if (entry && entry.html !== null) {
                    costruisciAnteprima(entry.html, cardTrascinata, cont);
                    return;
                }

                /* Precaricamento appena partito: ci si aggancia e si mostra
                   appena arriva, anche a trascinamento gia' iniziato. */
                if (entry && entry.promise) {
                    entry.promise.then(function () {
                        if (entry.html !== null) costruisciAnteprima(entry.html, cardTrascinata, cont);
                    });
                    return;
                }

                prefetchGet(url);
                var nuova = __prefetchCache[url];
                if (nuova && nuova.promise) {
                    nuova.promise.then(function () {
                        if (nuova.html !== null) costruisciAnteprima(nuova.html, cardTrascinata, cont);
                    });
                }
            }

            function aggiornaAnteprima (p) {
                /* Non fa piu' nulla: l'anteprima resta ferma e piena per tutto
                   il gesto. La funzione resta perche' e' chiamata da piu'
                   punti, e perche' se un domani si volesse tornare a un
                   ingresso graduale basta rimetterci il calcolo. */
                return;
            }

            function aggiornaAnteprimaInutilizzata (p) {
                if (!anteprima) return;
                /* Comincia a farsi vedere presto, cosi' si capisce subito cosa
                   sta arrivando, ma arriva a posto solo col rilascio. */
                var q = Math.max(0, Math.min(1, (p - 0.12) / 0.88));
                anteprima.style.transition = 'none';
                anteprima.style.opacity = (q * 0.95).toFixed(3);
                anteprima.style.transform = 'translateY(' + ((1 - q) * ANTEPRIMA_SALITA).toFixed(1) + 'px) scale(' + (0.93 + q * 0.07).toFixed(3) + ')';
            }

            window.togliAnteprima = function (subito) { togliAnteprima(subito); };
            window.anteprimaInCorso = function () { return !!anteprima; };

            function togliAnteprima (subito) {
                if (!anteprima) return;
                var el = anteprima;
                anteprima = null;
                if (subito) { el.remove(); return; }
                el.style.transition = 'opacity .18s ease-out';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 190);
            }

            function activateSwipe () {

                if (!sw) return;

                sw.active = true;

                gestureBlock = true;

                try { sw.card.setPointerCapture(sw.pid); } catch (e) {}

                buzz(10);

                document.body.classList.add('no-select');

                sw.card.classList.add('dragging');

                sw.card.style.transition = 'none';

                sw.card.style.zIndex = '50';

                sw.card.style.willChange = 'transform';

                sw.card.style.transform = 'scale(1.02)';

                creaAnteprima(sw.card);

            }



            document.addEventListener('pointermove', function (e) {

                if (!sw || e.pointerId !== sw.pid) return;

                sw.lx = e.clientX; sw.ly = e.clientY;

                sw.dx = e.clientX - sw.sx;

                sw.dy = e.clientY - sw.sy;

                if (!sw.active) {

                    var adx = Math.abs(sw.dx), ady = Math.abs(sw.dy);

                    if (adx > 10 && adx > ady * 1.2) {

                        // intento orizzontale: lo swipe parte SUBITO, come su Tinder

                        clearTimeout(sw.timer);

                        activateSwipe();

                    } else if (ady > 14 && ady > adx) {

                        // intento verticale: lascia lo scroll nativo del feed

                        clearTimeout(sw.timer); sw = null; return;

                    } else {

                        return; // ambiguo: attendi (o scatta il long-press)

                    }

                }

                var rot = Math.max(-14, Math.min(14, sw.dx * 0.06));

                sw.card.style.transform = 'translate(' + sw.dx + 'px, ' + (sw.dy * 0.4) + 'px) rotate(' + rot + 'deg) scale(1.02)';



                var p = Math.min(1, Math.abs(sw.dx) / TH());

                var good = sw.dx > 0;

                if (Math.abs(sw.dx) < 4) {

                    aggiornaAnteprima(0);

                    tint.style.opacity = '0';

                    stamp.style.opacity = '0';

                } else {

                    tint.style.background = good ? 'var(--tint-green)' : 'var(--tint-red)';

                    tint.style.opacity = (p * 0.55).toFixed(3);

                    stamp.textContent = good ? 'CORRECT' : 'WRONG';

                    stamp.style.opacity = Math.min(1, p * 1.25).toFixed(3);

                    stamp.style.transform = 'translate(-50%,-50%) rotate(' + (good ? -10 : 10) + 'deg) scale(' + (0.8 + p * 0.28).toFixed(3) + ')';

                    aggiornaAnteprima(p);

                    sw.card.style.boxShadow = '0 20px 55px rgba(' + (good ? '48,209,88' : '255,69,58') + ',' + (0.18 + p * 0.28).toFixed(3) + ')';

                }

                if (p >= 1 && !sw.crossed) { sw.crossed = true; buzz(18); }

                if (p < 1) sw.crossed = false;

            });



            function resetSwipeVisuals () {

                tint.style.opacity = '0';

                stamp.style.opacity = '0';

            }



            function endSwipe (commit) {

                var s = sw; sw = null;

                if (!s) return;

                clearTimeout(s.timer);

                document.body.classList.remove('no-select');

                if (!s.active) return;                 // era solo un tap: lascia proseguire il click

                gestureBlock = false;

                suppressNextClick = true;              // assorbe il ghost-click dopo il drag

                /* SOLO per una manciata di ms: il click-fantasma che questo
                   deve assorbire e' sintetizzato dal browser sotto il dito
                   quasi subito dopo il rilascio, come parte della STESSA
                   gesture appena finita - non un click qualunque che arrivera'
                   chissa' quando. Senza questa scadenza, restando sulla
                   pagina (si passa al figlio successivo senza ricaricare
                   nulla, vedi gradeAndAdvance) il primo vero tocco
                   dell'utente sul bottone "Mostra risposta" del figlio
                   successivo - un'azione nuova e deliberata, su tutt'altro
                   bottone - era quello che finiva assorbito al posto del
                   fantasma: da qui il bisogno di toccarlo due volte, e SOLO
                   sui figli, perche' un cambio di posizione vera passa invece
                   da navigateTo(), che gia' azzera il flag esplicitamente. */
                setTimeout(function () { suppressNextClick = false; }, 300);

                s.card.classList.remove('dragging');



                var committed = commit && Math.abs(s.dx) >= TH();

                if (!committed) {
                    /* Riportato al centro: l'anteprima si richiude, cosi' e'
                       chiaro che non si sta piu' andando avanti. */
                    togliAnteprima(false);
                }

                if (committed && (s.dx > 0 ? s.urlCorrect : s.urlWrong)) {

                    var good = s.dx > 0;

                    var url = good ? s.urlCorrect : s.urlWrong;

                    buzz(good ? 14 : 26);

                    tint.style.opacity = '0.68';

                    setTimeout(resetSwipeVisuals, 320);

                    if (good) confetti(s.lx, s.ly, GREENS);

                    s.card.style.transition = 'transform .34s cubic-bezier(.5,0,.9,.45), opacity .34s ease-out';

                    s.card.style.transform = 'translate(' + ((good ? 1 : -1) * window.innerWidth * 1.2) + 'px, ' + (s.dy * 0.4 + 60) + 'px) rotate(' + (good ? 26 : -26) + 'deg) scale(1.02)';

                    s.card.style.opacity = '0';

                    /* Restando su questa posizione (altri figli da valutare) la
                       carta non se ne va davvero: e' stata spinta fuori schermo
                       dall'animazione, e segnaCartaValutataInPagina la rimette
                       al suo posto marcata come risposta.

                       Cambiando posizione, invece, non si aspetta piu' i 140ms
                       di prima: la carta sta gia' volando via per conto suo e
                       intanto parte subito il caricamento della successiva,
                       cosi' e' pronta mentre l'animazione e' ancora in corso
                       invece di cominciare solo quando e' finita. */
                    var restaQui = (function () {
                        var cont = curC();
                        return cont && cont.querySelectorAll('.card-box:not(.graded):not(.anteprima-card)').length > 1;
                    })();

                    var attesa = restaQui
                        ? new Promise(function (r) { setTimeout(r, 140); })   /* si aspetta l'uscita, poi rientra */
                        : Promise.resolve();                                  /* si cambia pagina: subito */

                    gradeAndAdvance(url, attesa, parseInt(s.card.getAttribute('data-card-index') || '0', 10), s.card, good ? 'correct' : 'wrong');

                } else {

                    // sotto soglia: la card torna al suo posto con effetto molla

                    resetSwipeVisuals();

                    s.card.style.transition = 'transform .5s ' + SNAP + ', box-shadow .4s';

                    s.card.style.transform = '';

                    s.card.style.boxShadow = '';

                    setTimeout(function () {

                        if (s.card.isConnected) {

                            s.card.style.transition = '';

                            s.card.style.zIndex = '';

                            s.card.style.willChange = '';

                        }

                    }, 520);

                }

            }



            document.addEventListener('pointerup', function (e) { if (sw && e.pointerId === sw.pid) endSwipe(true); });

            document.addEventListener('pointercancel', function (e) { if (sw && e.pointerId === sw.pid) endSwipe(false); });

            document.addEventListener('contextmenu', function (e) {

                if ((sw && sw.active) || (e.target && e.target.closest && e.target.closest('.card-box[data-swipe="1"]') && sw)) e.preventDefault();

            });



            /* Blocca lo scroll nativo mentre una gesture è attiva */

            document.addEventListener('touchmove', function (e) {

                if (gestureBlock) e.preventDefault();

            }, { passive: false });



            /* ================================================================

               FEED VERTICALE STILE TIKTOK — trascina giù/su ai bordi del feed

               ================================================================ */

            var vp = null;



            document.addEventListener('touchstart', function (e) {

                if (navLock || (sw && sw.active)) return;

                if (!e.target || !e.target.closest) return;

                var feed = e.target.closest('.feed');

                var c = curC();

                if (!feed || !c || feed.closest('.container') !== c) return;

                var t = e.touches[0];

                vp = {

                    feed: feed, sy: t.clientY, sx: t.clientX, off: 0, mode: null, crossed: false,

                    atTop: feed.scrollTop <= 0,

                    atBottom: feed.scrollTop + feed.clientHeight >= feed.scrollHeight - 2,

                    canBack: c.getAttribute('data-can-back') === '1',

                    backUrl: c.getAttribute('data-back-url'),

                    canFwd: c.getAttribute('data-can-forward') === '1',

                    fwdUrl: c.getAttribute('data-forward-url')

                };

            }, { passive: true });



            document.addEventListener('touchmove', function (e) {

                if (!vp) return;

                if (sw && sw.active) { vp = null; return; }

                var t = e.touches[0];

                var dy = t.clientY - vp.sy;

                var dx = t.clientX - vp.sx;



                if (!vp.mode) {

                    if (Math.abs(dy) > 8 && Math.abs(dy) > Math.abs(dx) * 1.4) {

                        if (dy > 0 && vp.atTop) vp.mode = 'back';

                        else if (dy < 0 && vp.atBottom) vp.mode = 'fwd';

                        else { vp = null; return; }

                        gestureBlock = true;

                        vp.feed.style.transition = 'none';

                        vp.feed.style.willChange = 'transform';

                    } else if (Math.abs(dx) > 12) { vp = null; return; }

                    else return;

                }



                e.preventDefault();

                var can = vp.mode === 'back' ? vp.canBack : vp.canFwd;

                var damp = can ? 0.45 : 0.16;          // gomma dura se non si può andare oltre

                var off = dy * damp;

                off = vp.mode === 'back' ? Math.max(0, off) : Math.min(0, off);

                vp.off = off;

                vp.feed.style.transform = 'translateY(' + off + 'px)';



                var chip = vp.mode === 'back' ? hintTop : hintBottom;

                var P = Math.min(1, Math.abs(off) / PULL_TH);

                chip.querySelector('.pc-tx').textContent = can

                    ? (vp.mode === 'back' ? T('fc.gm_pagina_prec') : T('fc.gm_pagina_succ'))

                    : (vp.mode === 'back' ? T('fc.gm_prima_pagina') : T('fc.gm_rispondi'));

                chip.style.opacity = (can ? P : P * 0.7).toFixed(3);

                chip.style.transform = 'translateX(-50%) scale(' + (0.55 + P * 0.45).toFixed(3) + ')';

                chip.classList.toggle('armed', can && P >= 1);

                if (can && P >= 1 && !vp.crossed) { vp.crossed = true; buzz(14); }

                if (P < 1) vp.crossed = false;

            }, { passive: false });



            function hideChips () {

                [hintTop, hintBottom].forEach(function (ch) {

                    ch.style.opacity = '0';

                    ch.style.transform = 'translateX(-50%) scale(.5)';

                    ch.classList.remove('armed');

                });

            }



            document.addEventListener('touchend', function () {

                if (!vp) return;

                var v = vp; vp = null;

                gestureBlock = false;

                hideChips();

                if (!v.mode) return;

                var can = v.mode === 'back' ? v.canBack : v.canFwd;

                var committed = can && Math.abs(v.off) >= PULL_TH;

                if (committed) {

                    var held = (v.mode === 'back' ? 1 : -1) * PULL_TH * 1.35;

                    v.feed.style.transition = 'transform .22s ease-out';

                    v.feed.style.transform = 'translateY(' + held + 'px)';

                    buzz(12);

                    navigateTo(v.mode === 'back' ? v.backUrl : v.fwdUrl, { held: held });

                } else {

                    v.feed.style.transition = 'transform .5s ' + SNAP;

                    v.feed.style.transform = 'translateY(0)';

                    setTimeout(function () {

                        if (v.feed.isConnected) { v.feed.style.transition = ''; v.feed.style.transform = ''; v.feed.style.willChange = ''; }

                    }, 520);

                }

            });



            document.addEventListener('touchcancel', function () {

                if (!vp) return;

                var v = vp; vp = null;

                gestureBlock = false;

                hideChips();

                if (v.mode) {

                    v.feed.style.transition = 'transform .4s ' + SNAP;

                    v.feed.style.transform = 'translateY(0)';

                }

            });



            /* ---------- Tastiera (desktop): ← sbagliato · → corretto · ↑↓ pagine · spazio rivela ---------- */

            document.addEventListener('keydown', function (e) {

                if (window.__standoutModaleAperto) return;

                if (e.metaKey || e.ctrlKey || e.altKey) return;

                var tgt = e.target;

                if (tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'SELECT' || tgt.tagName === 'TEXTAREA')) return;

                var c = curC();

                if (!c || navLock) return;

                if (e.key === 'ArrowUp' && c.getAttribute('data-can-back') === '1') {

                    e.preventDefault(); navigateTo(c.getAttribute('data-back-url'));

                } else if (e.key === 'ArrowDown' && c.getAttribute('data-can-forward') === '1') {

                    e.preventDefault(); navigateTo(c.getAttribute('data-forward-url'));

                } else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {

                    var card = c.querySelector('.card-box[data-swipe="1"].risposta-mostrata:not(.anteprima-card)');

                    if (card) {

                        var good = (e.key === 'ArrowRight');

                        var url = good ? card.getAttribute('data-url-correct') : card.getAttribute('data-url-wrong');

                        if (url) {

                            e.preventDefault();

                            gradeAndAdvance(url, gradeFeedback(card, good ? 'correct' : 'wrong'), parseInt(card.getAttribute('data-card-index') || '0', 10), card, good ? 'correct' : 'wrong');

                        }

                    }

                } else if (e.key === ' ') {

                    var b = c.querySelector('.card-box:not(.graded):not(.anteprima-card) button[id^="btn-show-"]');

                    if (b && b.offsetParent) { e.preventDefault(); b.click(); }

                }

            });



            /* ---------- Suggerimento una-tantum sullo swipe ---------- */

            function maybeCoach () {

                var seen = false;

                try { seen = !!localStorage.getItem('fcCoachSeen2'); } catch (e) { seen = true; }

                if (seen) return;

                var c = curC();

                if (!c || !c.querySelector('.card-box[data-swipe="1"]:not(.anteprima-card)')) return;

                if (document.querySelector('.coach')) return;

                setTimeout(function () {

                    if (document.querySelector('.coach')) return;

                    var t = document.createElement('div');

                    t.className = 'coach';

                    t.innerHTML = T('fc.gm_trascina');

                    document.body.appendChild(t);

                    var kill = function () {

                        if (!t.isConnected) return;

                        t.classList.add('bye');

                        setTimeout(function () { t.remove(); }, 400);

                        try { localStorage.setItem('fcCoachSeen2', '1'); } catch (e) {}

                    };

                    setTimeout(kill, 4800);

                    document.addEventListener('pointerdown', kill, { once: true });

                }, 1300);

            }



            window.addEventListener('popstate', function () { window.location.reload(); });



            /* ---------- Avvio ---------- */

            function initApp () {

                syncHeader();

                setTimeout(syncHeader, 250);

                try { renderAllRawTexts(); } catch (e) {}

                if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes();

                applicaZoomBarra();

                schedulePrefetch();

                purgaVotiGiaSalvati();

                renderAllPendingDots();

                retryPendingGrades();

                cardsIn(curC());

                maybeCoach();
                document.dispatchEvent(new CustomEvent('fc-pagina', { detail: { container: curC() } }));

            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApp);

            else initApp();

        })();

    </script>

    <?php include __DIR__ . '/fc_audio_player.php'; ?>

    <?php include __DIR__ . '/fc_examples_modal.php'; ?>

    <?php include __DIR__ . '/fc_voice_mode.php'; ?>

</body>

</html>