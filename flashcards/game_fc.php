<?php
require_once 'config.php';
require_once 'auth_check.php'; // 1. Protezione con login obbligatorio
require_once 'piano_helpers.php';

$current_user_id = $utente_corrente['id']; // 2. ID utente sicuro

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Stima tempo/lentezza IA per "spiega" e "metafora", da mostrare col timer nel popup
// prima ancora di partire con la chiamata (vedi window.aiPerfStats più sotto).
$ai_perf_stats_js = [
    'explain'  => nisama_get_stima_tempo_ia('explain'),
    'metaphor' => nisama_get_stima_tempo_ia('metaphor'),
    'example'  => nisama_get_stima_tempo_ia('example'),
    'shatter'  => nisama_get_stima_tempo_ia('shatter'),
];

$game_id = isset($_GET['game_id']) ? intval($_GET['game_id']) : 0;
$position = isset($_GET['position']) ? intval($_GET['position']) : 1;
$status = isset($_GET['status']) ? $_GET['status'] : '0'; 

// 1. Info di base sulla partita
$game_res = mysqli_query($conn, "SELECT * FROM games WHERE id = $game_id");
$game_info = mysqli_fetch_assoc($game_res);

// --- INIZIO SICUREZZA ---
// Controlliamo che la partita esista e che l'utente loggato ne sia il legittimo proprietario
if (!$game_info || (int)$game_info['user_id'] !== $current_user_id) {
    die("Errore di sicurezza: Partita non trovata o non appartiene al tuo utente.");
}
// --- FINE SICUREZZA ---

$parent_id = isset($game_info['parent_id']) ? intval($game_info['parent_id']) : 0;

// 3. Forziamo l'uso dell'ID sicuro per il resto dello script
$user_id = $current_user_id;



// --- CRONOMETRO: nuova sessione quando si riprende la partita da "Continua partita" (result_fc.php) ---
// (start_game_fc.php e start_retry.php creano già la sessione principale quando la partita nasce)
if (isset($_GET['newsession']) && $_GET['newsession'] == '1' && $game_id > 0 && $game_info) {

    mysqli_query($conn, "INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at) VALUES ($game_id, NOW(), 0, NOW())");

    header("Location: game_fc.php?game_id=$game_id&position=$position&status=$status");
    exit();
}



// --- GESTIONE ORDINE DAL DATABASE ---

if (isset($_GET['order'])) {

    // Se l'ordine è nell'URL (es. Nuova Partita), lo salviamo nel DB

    $order_param = $_GET['order'];

    mysqli_query($conn, "UPDATE games SET game_order = '$order_param' WHERE id = $game_id");

} else {

    // Se non c'è nell'URL (es. tasto "Continua Partita"), lo peschiamo dal database

    $order_param = isset($game_info['game_order']) ? $game_info['game_order'] : 'random';

}



// =========================================================================

// FIX SICUREZZA E BUG FIGLI: Gestione chiusura partita e pulizia intelligente

// =========================================================================

if (isset($_GET['action']) && $_GET['action'] == 'concludi') {

    $q_empty_pos = mysqli_query($conn, "SELECT position FROM fc_gameplays WHERE game_id = $game_id GROUP BY position HAVING SUM(CASE WHEN user_answer != '' THEN 1 ELSE 0 END) = 0");

    $empty_positions = [];

    while($row = mysqli_fetch_assoc($q_empty_pos)) {

        $empty_positions[] = $row['position'];

    }

    if (!empty($empty_positions)) {

        $pos_str = implode(',', $empty_positions);

        mysqli_query($conn, "DELETE FROM fc_gameplays WHERE game_id = $game_id AND position IN ($pos_str)");

    }

    

    header("Location: result_fc.php?game_id=$game_id");

    exit();

}



$check_unanswered = mysqli_query($conn, "SELECT MIN(position) as min_p FROM fc_gameplays WHERE game_id = $game_id AND user_answer = ''");

$row_unanswered = mysqli_fetch_assoc($check_unanswered);

$first_unanswered = $row_unanswered ? $row_unanswered['min_p'] : null;



if ($first_unanswered && $first_unanswered < $position) {

    header("Location: game_fc.php?game_id=$game_id&position=$first_unanswered&status=$status&order=$order_param");

    exit();

}

// =========================================================================



$q_cats = mysqli_query($conn, "SELECT fc_category_id FROM game_fc_categories WHERE game_id = $game_id");

$cat_ids = [];

while ($c_row = mysqli_fetch_assoc($q_cats)) { if ($c_row['fc_category_id'] > 0) { $cat_ids[] = $c_row['fc_category_id']; } }

$cat_filter = (count($cat_ids) > 0) ? "AND category_id IN (" . implode(',', $cat_ids) . ")" : "";



if ($status === 'all') { $where_learnt = "1=1"; } 

elseif ($status === '1') { $where_learnt = "id IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)"; } 

else { $where_learnt = "id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)"; }



// 2. Gestione dell'azione di Grading (Sbagliato / Corretto / Imparato)

if (isset($_GET['action']) && $_GET['action'] == 'grade') {

    $result = ($_GET['result'] == 'correct' || $_GET['result'] == 'learnt') ? 'Correct' : 'Wrong';

    $id_to_mark = intval($_GET['img_id']); // Usiamo la variabile id_to_mark ma facciamo riferimento alle due colonne separate



    if ($_GET['result'] == 'learnt') {

        $parent_to_mark = $id_to_mark;

        if ($id_to_mark < 0) {

            $real_id = abs($id_to_mark);

            $c_res = mysqli_query($conn, "SELECT parent_id FROM fc_child_cards WHERE id_child = $real_id");

            if ($c_row = mysqli_fetch_assoc($c_res)) { $parent_to_mark = $c_row['parent_id']; }

        }

        if ($parent_to_mark > 0) {

            mysqli_query($conn, "INSERT IGNORE INTO user_learnt_fc (user_id, fc_image_id, learnt_date) VALUES ($user_id, $parent_to_mark, CURDATE())");

        }

    }

    

    if ($id_to_mark > 0) {
        mysqli_query($conn, "UPDATE fc_gameplays SET user_answer = '$result', play_date = NOW() WHERE game_id = $game_id AND position = $position AND fc_image_id = $id_to_mark");
    } else {
        $real_id = abs($id_to_mark);
        mysqli_query($conn, "UPDATE fc_gameplays SET user_answer = '$result', play_date = NOW() WHERE game_id = $game_id AND position = $position AND fc_child_id = $real_id");
    }



    // CRONOMETRO: teniamo aggiornata la durata della sessione corrente ad ogni risposta
    mysqli_query($conn, "UPDATE session_durations SET duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()), updated_at = NOW() WHERE game_id = $game_id ORDER BY id DESC LIMIT 1");



    $check_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_gameplays WHERE game_id = $game_id AND position = $position AND user_answer = ''");

    $unanswered = mysqli_fetch_assoc($check_res)['c'];



    if ($unanswered == 0) {

        header("Location: game_fc.php?game_id=$game_id&position=" . ($position + 1) . "&status=$status&order=$order_param");

    } else {

        header("Location: game_fc.php?game_id=$game_id&position=$position&status=$status&order=$order_param");

    }

    exit();

}



// --- CRONOMETRO: tempo totale della partita (somma sessioni precedenti + sessione corrente) ---
$chrono_base_seconds = 0;

$chrono_start_ts = 0;

$q_chrono = mysqli_query($conn, "SELECT id, UNIX_TIMESTAMP(start_time) as ts FROM session_durations WHERE game_id = $game_id ORDER BY id DESC LIMIT 1");

if ($chrono_last = mysqli_fetch_assoc($q_chrono)) {

    $chrono_start_ts = intval($chrono_last['ts']);

    $q_chrono_base = mysqli_query($conn, "SELECT COALESCE(SUM(duration_seconds),0) as tot FROM session_durations WHERE game_id = $game_id AND id <> " . intval($chrono_last['id']));

    $chrono_base_seconds = intval(mysqli_fetch_assoc($q_chrono_base)['tot']);

}



// 3. Recupero o Generazione delle carte per questa Pagina (Position)

$play_res = mysqli_query($conn, "SELECT * FROM fc_gameplays WHERE game_id = $game_id AND position = $position");

$gameplays = [];

while($r = mysqli_fetch_assoc($play_res)) { $gameplays[] = $r; }



if (empty($gameplays)) {

$sql_order = ($order_param === 'in_order') 

        ? "ORDER BY gc.category_order ASC, i.custom_order ASC" 

        : "ORDER BY gc.category_order ASC, RAND()";



    if ($parent_id > 0) {

        $where_retry = "WHERE i.id IN (

                            SELECT fc_image_id FROM fc_gameplays 

                            WHERE game_id = $parent_id 

                            AND fc_image_id IS NOT NULL

                            AND position IN (SELECT DISTINCT position FROM fc_gameplays WHERE game_id = $parent_id AND user_answer = 'Wrong')

                        ) 

                        AND i.id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)";

                        

        $new_img_res = mysqli_query($conn, "SELECT i.* FROM fc_images i 

                                            JOIN game_fc_categories gc ON i.category_id = gc.fc_category_id 

                                            $where_retry AND gc.game_id = $game_id 

                                            $sql_order LIMIT 1");

    } else {

        // SQL ordinato: Prima per ordine categoria, poi per ordine personalizzato carta

        $sql_order = ($order_param === 'in_order') 

            ? "ORDER BY gc.category_order ASC, i.custom_order ASC" 

            : "ORDER BY gc.category_order ASC, RAND()";



        // Query con JOIN per rispettare l'ordine delle categorie

        $query = "SELECT i.* FROM fc_images i 

                  JOIN game_fc_categories gc ON i.category_id = gc.fc_category_id 

                  WHERE gc.game_id = $game_id 

                  AND $where_learnt 

                  AND i.id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)

                  $sql_order LIMIT 1";

        

        $new_img_res = mysqli_query($conn, $query);

    }

    

    $new_img = mysqli_fetch_assoc($new_img_res);

    

    if (!$new_img) { header("Location: game_fc.php?game_id=$game_id&action=concludi"); exit(); }

    

    $img_id = $new_img['id'];

    $correct_ans = mysqli_real_escape_string($conn, mb_substr($new_img['answer_desc'], 0, 100, 'UTF-8'));

    

    // MEMORIA ERRORI: Controlla se il padre era già corretto nella partita precedente

    $parent_user_answer = '';

    if ($parent_id > 0) {

        $prev_ans_q = mysqli_query($conn, "SELECT user_answer FROM fc_gameplays WHERE game_id = $parent_id AND fc_image_id = $img_id");

        if ($row_prev = mysqli_fetch_assoc($prev_ans_q)) {

            if ($row_prev['user_answer'] == 'Correct') $parent_user_answer = 'Correct';

        }

    }

    

    mysqli_query($conn, "INSERT INTO fc_gameplays (game_id, fc_image_id, correct_answer, user_answer, position) VALUES ($game_id, $img_id, '$correct_ans', '$parent_user_answer', $position)");

    // Per compatibilità javascript locale passiamo anche il mock per la view
    $gameplays[] = ['fc_image_id' => $img_id, 'user_answer' => $parent_user_answer];

    

    if ($new_img['is_father'] == 1) {

        $child_res = mysqli_query($conn, "SELECT * FROM fc_child_cards WHERE parent_id = $img_id ORDER BY custom_order ASC");

        while($child = mysqli_fetch_assoc($child_res)) {

            $c_id = $child['id']; 

            $c_ans = mysqli_real_escape_string($conn, mb_substr($child['answer_desc'], 0, 100, 'UTF-8'));

            

            // MEMORIA ERRORI: Controlla se questo specifico figlio era già corretto

            $child_user_answer = '';

            if ($parent_id > 0) {

                $prev_ans_c = mysqli_query($conn, "SELECT user_answer FROM fc_gameplays WHERE game_id = $parent_id AND fc_child_id = $c_id");

                if ($row_prev_c = mysqli_fetch_assoc($prev_ans_c)) {

                    if ($row_prev_c['user_answer'] == 'Correct') $child_user_answer = 'Correct';

                }

            }

            

            mysqli_query($conn, "INSERT INTO fc_gameplays (game_id, fc_child_id, correct_answer, user_answer, position) VALUES ($game_id, $c_id, '$c_ans', '$child_user_answer', $position)");

            // Manteniamo id_fcimg per non rompere il javascript nel while sotto, trasformando i figli
            $gameplays[] = ['fc_child_id' => $c_id, 'user_answer' => $child_user_answer];

        }

    }

}



// 4. Preparazione Dati Visuali per la Pagina

$cards_to_show = [];

$all_answered = true;



foreach ($gameplays as $gp) {

    if ($gp['user_answer'] == '') { $all_answered = false; }



    if (isset($gp['fc_image_id'])) {

        $id = intval($gp['fc_image_id']);
        $res = mysqli_query($conn, "SELECT * FROM fc_images WHERE id = $id");

        $card = mysqli_fetch_assoc($res);

        if($card) {
            $card['is_child'] = false;
            $card['gameplay_idimmagine'] = $id; // Manteniamo la variabile JS compatibility per l'UI HTML
        }

    } else {

        $real_id = intval($gp['fc_child_id']);

        $res = mysqli_query($conn, "SELECT * FROM fc_child_cards WHERE id_child = $real_id");

        $card = mysqli_fetch_assoc($res);

        if($card) {
            $card['is_child'] = true;
            $card['gameplay_idimmagine'] = -1 * $real_id; // Manteniamo compatibile il frontend JS
        }

    }

    

    if ($card) {

        $card['user_answer'] = $gp['user_answer'];

        // NUOVO: Conta quanti esempi ha questa flashcard (Usa sempre ID positivo assoluto anche per figli)
        $lookup_ex_id = isset($gp['fc_image_id']) ? $id : -1 * $real_id;
        $ex_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_examples WHERE fc_image_id = $lookup_ex_id");

        $card['examples_count'] = mysqli_fetch_assoc($ex_q)['c'];

        

        $cards_to_show[] = $card; // Inseriamo la carta UNA SOLA volta alla fine

    }

}



// 5. Calcoli Progresso e Statistiche DOPPIA BARRA

$q_played = mysqli_query($conn, "SELECT COUNT(DISTINCT position) as c, COUNT(*) as c2 FROM fc_gameplays WHERE game_id = $game_id");

$played_data = mysqli_fetch_assoc($q_played);

$played_count = $played_data['c'];

$played_cards_count = $played_data['c2'];



// NUOVO: Conta quante carte sono state effettivamente risposte

$q_answered_cards = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_gameplays WHERE game_id = $game_id AND user_answer IN ('Correct', 'Wrong')");

$answered_cards_count = mysqli_fetch_assoc($q_answered_cards)['c'];



// NUOVO: Conta quante pagine sono state COMPLETATE (ovvero senza nessuna carta 'vuota')

$q_answered_pages = mysqli_query($conn, "SELECT COUNT(DISTINCT position) as c FROM fc_gameplays WHERE game_id = $game_id AND position NOT IN (SELECT DISTINCT position FROM fc_gameplays WHERE game_id = $game_id AND user_answer = '')");

$answered_pages_count = mysqli_fetch_assoc($q_answered_pages)['c'];



if ($parent_id > 0) {

    // POSIZIONI RIMANENTI
    // Filtriamo rigorosamente sui padri presenti in db e sulle posizioni dove almeno una carta è Wrong

    $q_to_play = mysqli_query($conn, "SELECT COUNT(DISTINCT position) as c FROM fc_gameplays 

                                      WHERE game_id = $parent_id 

                                      AND position IN (SELECT DISTINCT position FROM fc_gameplays WHERE game_id = $parent_id AND user_answer = 'Wrong')

                                      AND fc_image_id IS NOT NULL 

                                      AND fc_image_id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)");

    $to_play_count = mysqli_fetch_assoc($q_to_play)['c'];



    // CARTE SINGOLE RIMANENTI

    $q_to_play_cards = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_gameplays 

                                            WHERE game_id = $parent_id 

                                            AND position IN (SELECT DISTINCT position FROM fc_gameplays WHERE game_id = $parent_id AND user_answer = 'Wrong')

                                            AND (fc_image_id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL) OR fc_child_id NOT IN (SELECT fc_child_id FROM fc_gameplays WHERE game_id = $game_id AND fc_child_id IS NOT NULL))");

    $to_play_cards_count = mysqli_fetch_assoc($q_to_play_cards)['c'];

} else {

    // POSIZIONI RIMANENTI

    $q_to_play = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_images WHERE $where_learnt $cat_filter AND id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)");

    $to_play_count = mysqli_fetch_assoc($q_to_play)['c'];



    // CARTE SINGOLE RIMANENTI

    $q_to_play_cards = mysqli_query($conn, "

        SELECT SUM(cnt) as c FROM (

            SELECT COUNT(*) as cnt FROM fc_images WHERE $where_learnt $cat_filter AND id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)

            UNION ALL

            SELECT COUNT(*) as cnt FROM fc_child_cards c JOIN fc_images i ON c.parent_id = i.id WHERE $where_learnt $cat_filter AND i.id NOT IN (SELECT fc_image_id FROM fc_gameplays WHERE game_id = $game_id AND fc_image_id IS NOT NULL)

        ) as t

    ");

    $to_play_cards_count = mysqli_fetch_assoc($q_to_play_cards)['c'];

}



$total_words = $played_count + $to_play_count;

if ($total_words == 0) $total_words = 1;



$total_cards = $played_cards_count + $to_play_cards_count;

if ($total_cards == 0) $total_cards = 1;



mysqli_query($conn, "UPDATE games SET total_father_words = $total_words, total_words_with_children = $total_cards WHERE id = $game_id");



// 6. GESTIONE STORICO ERRORI E CHECKPOINT (Pallini Rossi e Bianchi)

$errors_pages = [];

$errors_cards = [];

$furthest_page = 1;

$jump_back_card_index = 0;

$first_unanswered_card_idx = null;



$q_hist = mysqli_query($conn, "SELECT position, fc_image_id, fc_child_id, user_answer FROM fc_gameplays WHERE game_id = $game_id ORDER BY position ASC, fc_image_id DESC, fc_child_id ASC");



$card_index = 0;

$page_status = []; 



while($h = mysqli_fetch_assoc($q_hist)) {

    $card_index++;

    $p = intval($h['position']);

    $ans = $h['user_answer'];

    

    if ($p > $furthest_page) $furthest_page = $p;

    if (!isset($page_status[$p])) $page_status[$p] = false;

    

    // Se c'è un errore, segna la pagina (padre) come errata e registra l'errore per la barra delle carte

    if ($ans === 'Wrong') {

        $page_status[$p] = true;

        $errors_cards[] = [ 'idx' => $card_index, 'pos' => $p ];

    }

    

    // Registra la prima carta NON risposta per piazzare il pallino bianco in modo chirurgico

    if ($ans === '' && $first_unanswered_card_idx === null) {

        $first_unanswered_card_idx = $card_index;

    }

}



foreach ($page_status as $p => $has_err) {

    if ($has_err) { $errors_pages[] = $p; }

}



$jump_back_pos = $first_unanswered ? $first_unanswered : $furthest_page;

$jump_back_card_index = $first_unanswered_card_idx ? $first_unanswered_card_idx : $card_index;



$q_max_pos = mysqli_query($conn, "SELECT MAX(position) as max_p FROM fc_gameplays WHERE game_id = $game_id");

$row_max = mysqli_fetch_assoc($q_max_pos);

$max_position_generated = $row_max ? $row_max['max_p'] : 1;



$status_text = ($status === 'all') ? 'All' : (($status == 1) ? 'Learnt' : 'To Learn');

?>



<!DOCTYPE html>

<html lang="it">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

    <meta name="mobile-web-app-capable" content="yes">

    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <meta name="format-detection" content="telephone=no">

    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">

    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    <title>Flashcards Elettronica/Mate</title>



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
            title.textContent = '⚠️ Errore JavaScript';
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
            show((e.message || 'Errore sconosciuto') + '\n→ ' + file + ' riga ' + (e.lineno || '?') + ':' + (e.colno || '?'));
        });

        window.addEventListener('unhandledrejection', function (e) {
            var r = e.reason;
            show('Promise non gestita: ' + ((r && r.message) ? r.message : String(r)));
        });
    })();
    </script>



    <script>

        window.MathJax = {

        tex: {

            inlineMath: [['$', '$'], ['\\(', '\\)']],

            displayMath: [['$$', '$$'], ['\\[', '\\]']]

        }

        };

    </script>

    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>



    <style>

        /* ================================================================

           DESIGN SYSTEM — iOS nativo · light + dark automatico

           ================================================================ */

        :root{

            color-scheme: light dark;

            --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;

            --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;

            --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);

            --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;

            --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);

            --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12);

            --tint-green:#30D158; --tint-red:#FF453A;

            --header-glass:rgba(249,249,251,.82);

            --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);

            --chip-bg:#FFFFFF;

            --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);

            --raise:0 1px 2px rgba(0,0,0,.07), 0 10px 22px -6px rgba(0,0,0,.16);

            --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);

            --pill-hl:inset 0 0 0 .5px var(--hairline);

            --g-fill:#E7F8ED; --g-ink:#1E8A44; --g-line:rgba(52,199,89,.85); --g-sh:rgba(52,199,89,.34);

            --r-fill:#FDECEC; --r-ink:#D70015; --r-line:rgba(255,59,48,.8);  --r-sh:rgba(255,59,48,.3);

            --r-card:22px; --r-btn:15px; --r-chip:100px;

            --spring:cubic-bezier(.32,.72,0,1);

            --header-h:104px;

            --safe-top:env(safe-area-inset-top, 0px);

            --safe-bottom:env(safe-area-inset-bottom, 0px);

        }

        @media (prefers-color-scheme: dark){

            :root{

                --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;

                --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;

                --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);

                --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;

                --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);

                --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18);

                --header-glass:rgba(16,16,18,.78);

                --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);

                --chip-bg:#3A3A3C;

                --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);

                --raise:0 1px 2px rgba(0,0,0,.5), 0 12px 26px -6px rgba(0,0,0,.65);

                --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);

                --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);

                --g-fill:rgba(48,209,88,.15); --g-ink:#4CE07E; --g-line:rgba(48,209,88,.75); --g-sh:rgba(48,209,88,.5);

                --r-fill:rgba(255,69,58,.16); --r-ink:#FF6F68; --r-line:rgba(255,69,58,.75); --r-sh:rgba(255,69,58,.45);

            }

        }



        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

        html{ -webkit-text-size-adjust:100%; }

        html, body{ height:100%; }

        body{

            margin:0; position:fixed; inset:0; overflow:hidden;

            background:var(--bg); color:var(--text);

            font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;

            font-size:17px; line-height:1.45;

            -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;

            overscroll-behavior:none;

        }

        body.no-select, body.no-select *{ -webkit-user-select:none !important; user-select:none !important; }

        :focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }



        /* ================================================================

           HEADER — vetro smerigliato, barre di progresso ultrasottili

           ================================================================ */

        .app-header{

            position:fixed; top:0; left:0; right:0; z-index:100;

            padding:calc(var(--safe-top) + 8px) 0 10px;

            background:var(--header-glass);

            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);

            border-bottom:.5px solid var(--hairline);

            transition:opacity .25s var(--spring);

        }

        .bars{ padding:0 16px; display:flex; flex-direction:column; gap:6px; }

        .progress-bar-bg{

            position:relative; width:100%; height:3.5px; border-radius:99px;

            background:var(--hairline); overflow:visible;

        }

        .progress-bar-fill{

            height:100%; border-radius:99px; width:0;

            transition:width .7s var(--spring);

        }

        .fill-pages{ background:var(--blue); box-shadow:0 0 8px rgba(10,132,255,.35); }

        .fill-cards{ background:var(--orange); box-shadow:0 0 8px rgba(255,159,10,.3); }

        .dot{

            position:absolute; top:50%; transform:translate(-50%,-50%);

            width:8px; height:8px; border-radius:50%; z-index:10;

            transition:transform .2s var(--spring);

        }

        .dot::after{ content:""; position:absolute; inset:-11px; border-radius:50%; }

        .dot:active{ transform:translate(-50%,-50%) scale(1.7); }

        .dot-red{ background:var(--red); box-shadow:0 0 0 1.5px var(--surface), 0 0 7px rgba(255,69,58,.55); }

        .dot-white{

            width:11px; height:11px; background:var(--surface);

            border:2.5px solid var(--blue); z-index:11;

            box-shadow:0 1px 4px rgba(0,0,0,.25);

        }

        .dot-white-alt{ border-color:var(--orange); }



        .hdr-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 16px 0; }

        .hdr-left{ min-width:0; }

        .hdr-title{

            font-size:20px; font-weight:800; letter-spacing:-.02em; line-height:1.1;

            font-variant-numeric:tabular-nums;

        }

        .hdr-title span{ color:var(--text-2); font-weight:600; font-size:15px; }

        .hdr-sub{

            margin-top:2px; font-size:11.5px; color:var(--text-2); letter-spacing:.01em;

            font-variant-numeric:tabular-nums;

            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;

        }

        .hdr-sub b{ font-weight:700; color:var(--text); }

        .hdr-right{ display:flex; align-items:center; gap:8px; flex-shrink:0; }

        .chip{

            display:inline-flex; align-items:center; padding:5px 11px; border-radius:var(--r-chip);

            font-size:10.5px; font-weight:800; letter-spacing:.07em; text-transform:uppercase;

        }

        .chip-status{ background:var(--blue-soft); color:var(--blue); }

        .chip-status.st-learnt{ background:var(--green-soft); color:var(--green); }

        .chip-status.st-all{ background:var(--purple-soft); color:var(--purple); }

        .icon-btn{

            width:38px; height:38px; border-radius:50%; border:none; cursor:pointer; padding:0;

            background:var(--surface); color:var(--text-2);

            display:flex; align-items:center; justify-content:center;

            box-shadow:var(--pill-hl), var(--raise-sm);

            transition:transform .15s var(--spring), box-shadow .2s var(--spring);

        }

        .icon-btn:active{ transform:scale(.88); box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }

        .icon-btn svg{ width:19px; height:19px; }



        /* ---------------- Cronometro partita ---------------- */

        .chrono-pill{
            display:inline-flex; align-items:center; flex-shrink:0;
            height:38px; padding:0 13px 0 10px; gap:6px; border-radius:19px;
            border:none; cursor:pointer; overflow:hidden;
            background:var(--surface); color:var(--text-2);
            box-shadow:var(--pill-hl), var(--raise-sm);
            /* Aggiunta l'animazione anche sul gap */
            transition:width .38s var(--spring), padding .38s var(--spring),
                       border-radius .38s var(--spring), box-shadow .2s var(--spring),
                       transform .15s var(--spring), gap .38s var(--spring);
        }

        .chrono-pill:active{ transform:scale(.88); box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }

        .chrono-pill .chrono-ic{ width:17px; height:17px; flex-shrink:0; color:var(--blue); }

        .chrono-pill .chrono-txt{
            font-size:13px; font-weight:700; color:var(--text);
            font-variant-numeric:tabular-nums; letter-spacing:-.01em; white-space:nowrap;
            max-width:56px; opacity:1; overflow:hidden; /* Aggiunto overflow:hidden */
            transition:max-width .38s var(--spring), opacity .2s var(--spring);
        }

        .chrono-pill.is-collapsed{ width:38px; padding:0; border-radius:50%; justify-content:center; gap:0; }

        .chrono-pill.is-collapsed .chrono-txt{ max-width:0; opacity:0; margin:0; }



        /* ================================================================

           FEED — schermo intero, scroll verticale stile TikTok

           ================================================================ */

        .feed{

            position:fixed; inset:0; z-index:1;

            overflow-y:auto; overflow-x:hidden;

            -webkit-overflow-scrolling:touch; overscroll-behavior:none;

            padding-top:calc(var(--header-h) + 14px);

            transform:translateZ(0);

        }

        .feed::-webkit-scrollbar{ width:0; height:0; }

        .feed-inner{

            max-width:640px; margin:0 auto;

            padding:0 14px calc(44px + var(--safe-bottom));

        }



        /* ================================================================

           CARD

           ================================================================ */

        .card-box{

            position:relative;

            background:var(--surface); border-radius:var(--r-card);

            padding:20px 18px 18px; margin-bottom:16px;

            border:.5px solid var(--hairline);

            box-shadow:var(--shadow-card);

            transition:box-shadow .25s;

        }

        .card-box.dragging{ cursor:grabbing; }

        .card-box[data-swipe="1"]{ touch-action:pan-y; cursor:grab; }

        .card-box[data-swipe="1"] .fc-image,

        .card-box[data-swipe="1"] .raw-text{ -webkit-touch-callout:none; }

        .card-box.res-correct{ box-shadow:var(--shadow-card), 0 0 0 1.5px rgba(48,209,88,.4); }

        .card-box.res-wrong{ box-shadow:var(--shadow-card), 0 0 0 1.5px rgba(255,69,58,.4); }



        .card-title{

            display:flex; align-items:center; gap:7px;

            font-size:10.5px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;

            color:var(--text-3); margin-bottom:12px;

        }

        .card-title::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }

        .card-title.is-child::before{ background:var(--purple); }



        .section-title{

            display:flex; align-items:center; gap:8px;

            font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;

            color:var(--text-2); margin-bottom:10px;

            border:none; padding:0;

        }

        .section-title::after{ content:""; flex:1; height:.5px; background:var(--hairline); }

        .st-answer{ color:var(--blue); }



        .raw-text{

            margin:8px 0; text-align:left;

            font-size:18.5px; font-weight:600; line-height:1.5; letter-spacing:-.012em;

            color:var(--text);

            white-space:pre-wrap; word-wrap:break-word;

        }

        .answer-box .raw-text{ font-size:17px; font-weight:450; }

        .raw-text mjx-container{ max-width:100%; overflow-x:auto; overflow-y:hidden; padding:2px 0; }



        .fc-image{

            display:block; max-width:100%; height:auto; max-height:290px;

            margin:12px auto; border-radius:16px; cursor:zoom-in;

            border:.5px solid var(--hairline);

            box-shadow:0 4px 16px rgba(0,0,0,.1);

        }

        audio{ width:100%; height:40px; margin:6px 0 10px; border-radius:12px; }



        .answer-box{

            margin-top:14px; padding:16px 15px 15px;

            background:var(--blue-soft); border-radius:18px; border:none;

        }

        .reveal-anim{ animation:revealIn .5s var(--spring) both; }

        @keyframes revealIn{

            from{ opacity:0; transform:translateY(14px) scale(.985); }

            to{ opacity:1; transform:none; }

        }



        /* ================================================================

           BOTTONI

           ================================================================ */

        .btn{

            display:flex; align-items:center; justify-content:center; gap:8px;

            width:100%; padding:15px 16px; margin:10px 0 0;

            border:none; border-radius:var(--r-btn); cursor:pointer;

            font-family:inherit; font-size:16.5px; font-weight:700; letter-spacing:-.01em;

            color:#fff; background:var(--text);

            text-decoration:none; text-align:center;

            -webkit-user-select:none; user-select:none;

            --glow:rgba(0,0,0,.3);

            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px var(--glow);

            transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s, opacity .2s;

        }

        .btn:active{

            transform:translateY(1.5px) scale(.98);

            filter:brightness(.95);

            box-shadow:inset 0 1px 0 rgba(255,255,255,.1), inset 0 3px 8px rgba(0,0,0,.18),

                       0 1px 2px rgba(0,0,0,.12), 0 4px 10px -5px var(--glow);

        }

        .btn-primary{

            --glow:rgba(10,132,255,.55);

            background:var(--blue);

            background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

        }

        .btn-green{

            color:var(--g-ink); background:var(--g-fill);

            box-shadow:inset 0 0 0 1.5px var(--g-line), 0 2px 4px -1px rgba(0,0,0,.05), 0 14px 32px -10px var(--g-sh);

        }

        .btn-green:active{

            filter:none; transform:translateY(1.5px) scale(.985);

            box-shadow:inset 0 0 0 1.5px var(--g-line), inset 0 3px 7px -2px var(--g-sh), 0 4px 10px -6px var(--g-sh);

        }

        .btn-red{

            color:var(--r-ink); background:var(--r-fill);

            box-shadow:inset 0 0 0 1.5px var(--r-line), 0 2px 4px -1px rgba(0,0,0,.05), 0 14px 32px -10px var(--r-sh);

        }

        .btn-red:active{

            filter:none; transform:translateY(1.5px) scale(.985);

            box-shadow:inset 0 0 0 1.5px var(--r-line), inset 0 3px 7px -2px var(--r-sh), 0 4px 10px -6px var(--r-sh);

        }

        .btn-learnt{

            --glow:rgba(255,159,10,.55);

            background:var(--orange);

            background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 16%), var(--orange) 52%, color-mix(in srgb, var(--orange), #000 10%));

        }

        .btn-ghost{

            color:var(--text-2); background:var(--surface);

            box-shadow:var(--pill-hl), var(--raise);

        }

        .btn-ghost:active{

            filter:none;

            box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.08);

        }

        .btn-sm{ padding:11px 14px; font-size:14.5px; margin:0; }

        .btn .gi{ font-size:18px; font-weight:800; line-height:1; }

        .btn svg{ width:17px; height:17px; }



        .grade-row{ display:flex; gap:10px; margin-top:16px; }

        .grade-row .btn{ flex:1; margin:0; padding:16px 10px; }

        .learnt-row{ display:flex; gap:10px; margin-top:10px; }

        .learnt-row .btn{ margin:0; }

        .btn-edit{

            width:56px; flex:none;

            background:var(--chip-bg); color:var(--text-2);

            box-shadow:var(--pill-hl), var(--raise-sm);

        }

        .btn-edit:active{ filter:none; box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }



        .nav-btn{

            display:inline-flex; align-items:center; justify-content:center; gap:5px;

            padding:11px 20px; border:none; border-radius:var(--r-chip); cursor:pointer;

            font-family:inherit; font-size:14px; font-weight:700;

            background:var(--surface); color:var(--text); text-decoration:none;

            box-shadow:var(--pill-hl), var(--raise-sm);

            transition:transform .15s var(--spring), box-shadow .2s var(--spring);

        }

        .nav-btn:active{

            transform:translateY(1px) scale(.95);

            box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.08);

        }

        .nav-btn svg{ width:15px; height:15px; }

        .nav-fwd{

            color:#fff;

            background:var(--blue);

            background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

            box-shadow:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16), 0 1px 2px rgba(0,0,0,.12), 0 8px 18px -5px rgba(10,132,255,.55);

        }

        .nav-row{ display:flex; justify-content:center; gap:10px; margin:22px 0 4px; }



        /* Riga strumenti card (Frantuma) */

        .tool-row{ display:flex; justify-content:flex-end; margin:4px 0 2px; }

        .chip-shatter{

            display:inline-flex; align-items:center; gap:5px;

            padding:8px 14px; border:none; border-radius:var(--r-chip); cursor:pointer;

            font-family:inherit; font-size:12.5px; font-weight:700;

            background:var(--chip-bg); color:var(--purple);

            box-shadow:var(--pill-hl), var(--raise-sm);

            transition:transform .15s var(--spring), box-shadow .2s var(--spring);

        }

        .chip-shatter:active{

            transform:translateY(1px) scale(.94);

            box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.08);

        }



        /* Riga AI Tutor — chips scorrevoli */

        .ai-tools-box{

            display:flex; align-items:center; gap:7px;

            margin-top:16px; padding:9px 9px;

            background:var(--surface-2); border-radius:16px;

            box-shadow:inset 0 1px 3px rgba(0,0,0,.06), inset 0 0 0 .5px var(--hairline);

            overflow-x:auto; scrollbar-width:none;

        }

        .ai-tools-box::-webkit-scrollbar{ display:none; }

        .ai-spark{ display:flex; flex-shrink:0; color:var(--purple); padding-left:3px; }

        .ai-spark svg{ width:16px; height:16px; }

        .ai-sep{ width:1px; height:22px; background:var(--hairline-2); flex-shrink:0; margin:0 2px; }

        .chip-ai{

            flex-shrink:0; padding:9px 13px;

            font-size:13px; font-weight:600;

            background:var(--chip-bg); color:var(--text);

            box-shadow:var(--pill-hl), var(--raise-sm);

        }

        .chip-ai.has-ex{

            color:#fff;

            background:var(--purple);

            background:linear-gradient(180deg, color-mix(in srgb, var(--purple), #fff 16%), var(--purple) 52%, color-mix(in srgb, var(--purple), #000 10%));

            box-shadow:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16), 0 1px 2px rgba(0,0,0,.12), 0 6px 14px -4px rgba(191,90,242,.5);

        }



        /* Carta già valutata */

        .graded-head{ display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:10px; }

        .status-pill{

            display:inline-flex; align-items:center; gap:6px;

            padding:8px 14px; border-radius:var(--r-chip);

            font-size:13.5px; font-weight:800; letter-spacing:.01em;

        }

        .status-pill.ok{ background:var(--green-soft); color:var(--green); }

        .status-pill.ko{ background:var(--red-soft); color:var(--red); }

        .pill-toggle{ padding:9px 14px; font-size:13px; flex-shrink:0; }



        /* Fine partita */

        #end-game-section{ margin-top:26px; padding-top:18px; border-top:.5px solid var(--hairline); }

        .card-warn{

            background:var(--orange-soft); border-radius:18px; padding:16px;

            animation:revealIn .4s var(--spring) both;

        }

        .card-warn p{ margin:0 0 10px; font-size:14.5px; font-weight:700; color:var(--orange); }

        .card-warn .btn{ margin:0; }

        .card-warn .nav-btn{ width:100%; margin-top:10px; padding:13px; }



        /* ================================================================

           SHEET MODALI (TTS, AI, Frantuma) — stile foglio iOS dal basso

           ================================================================ */

        #tts-modal, #ai-modal, #shatter-modal{

            position:fixed; inset:0; z-index:10001; display:none;

            justify-content:center; align-items:flex-end;

            background:rgba(0,0,0,.45);

            -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

        }

        #ai-modal{ z-index:10005; }

        #shatter-modal{ z-index:10006; }

        .sheet-panel{

            position:relative; width:100%; max-width:600px;

            max-height:88vh; overflow-y:auto; -webkit-overflow-scrolling:touch;

            background:var(--surface); color:var(--text);

            border-radius:26px 26px 0 0;

            padding:28px 20px calc(22px + var(--safe-bottom));

            box-shadow:0 -10px 50px rgba(0,0,0,.35);

            animation:sheetUp .42s var(--spring) both;

            text-align:left;

        }

        @keyframes sheetUp{ from{ transform:translateY(55%); opacity:.4; } to{ transform:none; opacity:1; } }

        .sheet-panel::before{

            content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%);

            width:38px; height:5px; border-radius:99px; background:var(--hairline-2);

        }

        .sheet-panel h3{

            margin:2px 0 14px; padding:0; border:none;

            font-size:20px; font-weight:800; letter-spacing:-.02em; color:var(--text);

        }

        .sheet-close{

            position:absolute; top:16px; right:16px; z-index:2;

            width:32px; height:32px; border:none; border-radius:50%; cursor:pointer; padding:0;

            background:var(--surface-2); color:var(--text-2);

            font-size:13px; font-weight:700;

            display:flex; align-items:center; justify-content:center;

            box-shadow:inset 0 0 0 .5px var(--hairline);

        }

        .sheet-actions{ display:flex; gap:10px; margin-top:14px; padding-top:14px; border-top:.5px solid var(--hairline); }

        .sheet-actions .btn{ flex:1; margin:0; }

        @media (min-width:640px){

            #tts-modal, #ai-modal, #shatter-modal, #token-limit-modal{ align-items:center; padding:20px; }

            .sheet-panel{ border-radius:26px; }

        }

        /* ================================================================
           POPUP "TOKEN INSUFFICIENTI" — stesso linguaggio visivo degli altri
           fogli iOS (sheet-panel), più icona e testo centrati.
           ================================================================ */
        #token-limit-modal{
            position:fixed; inset:0; z-index:10010; display:none;
            justify-content:center; align-items:flex-end;
            background:rgba(0,0,0,.45);
            -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
        }
        .limit-icon{
            width:60px; height:60px; margin:4px auto 14px; border-radius:50%;
            display:flex; align-items:center; justify-content:center; font-size:28px;
            background:var(--orange-soft); color:var(--orange);
        }
        .limit-modal-msg{ font-size:15.5px; line-height:1.5; color:var(--text); text-align:center; margin-bottom:6px; }
        .limit-modal-sub{ font-size:12.5px; color:var(--text-2); text-align:center; margin-bottom:4px; }

        /* Timer/stima per le chiamate testuali IA più lente (spiega/metafora) */
        .ai-timer{
            display:none; align-items:center; gap:8px; margin-bottom:10px;
            font-size:13px; font-weight:700; color:var(--text-2);
        }
        .ai-timer .dot{ width:7px; height:7px; border-radius:50%; background:var(--blue); animation:aiTimerPulse 1s ease-in-out infinite; }
        @keyframes aiTimerPulse{ 0%,100%{ opacity:.35; } 50%{ opacity:1; } }

        /* Toast "le API di Google sembrano più lente del solito" */
        .api-slow-toast{
            position:fixed; top:calc(14px + var(--safe-top)); left:50%; z-index:10020;
            transform:translateX(-50%) translateY(-140%);
            max-width:88vw; padding:11px 18px; border-radius:100px;
            background:var(--surface); color:var(--text); font-size:13px; font-weight:700;
            box-shadow:var(--raise), inset 0 0 0 .5px var(--hairline);
            display:flex; align-items:center; gap:8px;
            transition:transform .38s var(--spring);
            /* È solo un avviso: non deve mai intercettare i tocchi. Sta allo stesso
               z-index del floating player e, quando un foglio è aperto, il player si
               sposta proprio qui in alto: senza questo gli coprirebbe i tasti. */
            pointer-events:none;
        }
        .api-slow-toast.show{ transform:translateX(-50%) translateY(0); }



        .field{ margin-top:14px; }

        .field label{

            display:block; margin-bottom:6px;

            font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;

            color:var(--text-2);

        }

        .field select{

            width:100%; padding:13px 38px 13px 14px;

            font-family:inherit; font-size:16px; font-weight:600;

            color:var(--text); background-color:var(--surface-2);

            border:.5px solid var(--hairline-2); border-radius:14px;

            -webkit-appearance:none; appearance:none;

            background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");

            background-repeat:no-repeat; background-position:right 13px center;

        }

        .field-hint{ margin-top:7px; font-size:11.5px; line-height:1.45; color:var(--text-2); }

        .field-hint b{ color:var(--text); font-weight:700; }

        .field-warn{ padding:8px 10px; border-radius:10px; background:var(--orange-soft); color:var(--text); }

        .ai-status{ margin-bottom:10px; font-size:13.5px; font-weight:700; color:var(--blue); }

        .ai-body{ font-size:16.5px; font-weight:450; line-height:1.55; }



        /* Mini-card della modalità Frantuma */

        .mini-card{

            background:var(--surface-2); border-radius:16px;

            padding:14px; margin-bottom:12px;

            box-shadow:inset 0 0 0 .5px var(--hairline);

        }

        .mini-card-label{

            font-size:10.5px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;

            color:var(--text-3); margin-bottom:8px;

        }

        .mini-q{ font-size:16.5px; margin:0 0 10px; }

        .mini-ans{ margin-top:12px; padding-top:12px; border-top:.5px solid var(--hairline); }

        .mini-a{ font-size:15.5px; font-weight:450; margin:0; }



        /* ================================================================

           OVERLAY ZOOM IMMAGINI

           ================================================================ */

        #image-overlay{

            position:fixed; inset:0; z-index:9999; display:none;

            justify-content:center; align-items:center;

            background:rgba(0,0,0,.94);

            -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);

        }

        #overlay-img{ max-width:100%; max-height:100%; object-fit:contain; animation:zoomIn .3s var(--spring); }

        @keyframes zoomIn{ from{ transform:scale(.85); opacity:0; } }

        #close-overlay{

            position:absolute; top:calc(var(--safe-top) + 12px); right:14px; z-index:10000;

            width:40px; height:40px; border-radius:50%;

            background:rgba(255,255,255,.16); color:#fff;

            font-size:17px; font-weight:700;

            display:flex; align-items:center; justify-content:center; cursor:pointer;

            -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);

            -webkit-user-select:none; user-select:none;

        }



        /* ================================================================

           LIVELLI SWIPE (tinta schermo + timbro stile Tinder)

           ================================================================ */

        #swipe-tint{

            position:fixed; inset:0; z-index:5000; pointer-events:none;

            opacity:0; background:var(--tint-green);

            transition:opacity .18s ease-out;

        }

        .swipe-stamp{

            position:fixed; top:38%; left:50%; z-index:5001; pointer-events:none;

            transform:translate(-50%,-50%) rotate(-10deg) scale(.8);

            padding:10px 30px; white-space:nowrap;

            border:5px solid #fff; border-radius:18px;

            font-size:46px; font-weight:900; letter-spacing:.1em; color:#fff;

            opacity:0;

            text-shadow:0 2px 14px rgba(0,0,0,.25);

            box-shadow:0 6px 30px rgba(0,0,0,.2);

        }



        /* Indicatori pull verticale (feed TikTok) */

        .pull-chip{

            position:fixed; left:50%; z-index:4000; pointer-events:none;

            transform:translateX(-50%) scale(.5); opacity:0;

            display:flex; flex-direction:column; align-items:center; gap:6px;

            transition:opacity .2s, transform .2s;

        }

        .pull-chip .pc-ic{

            width:46px; height:46px; border-radius:50%;

            background:var(--surface); color:var(--text);

            display:flex; align-items:center; justify-content:center;

            box-shadow:0 8px 30px rgba(0,0,0,.22), inset 0 0 0 .5px var(--hairline);

            transition:background .2s, color .2s;

        }

        .pull-chip .pc-ic svg{ width:20px; height:20px; }

        .pull-chip .pc-tx{

            padding:4px 11px; border-radius:99px;

            font-size:11.5px; font-weight:700; color:var(--text-2);

            background:var(--surface);

            box-shadow:0 2px 10px rgba(0,0,0,.14);

        }

        .pull-chip.top{ top:calc(var(--header-h) + 14px); }

        .pull-chip.bottom{ bottom:calc(var(--safe-bottom) + 26px); }

        .pull-chip.armed .pc-ic{ background:var(--blue); color:#fff; }



        /* Coriandoli */

        #confetti-layer{ position:fixed; inset:0; z-index:6000; pointer-events:none; overflow:hidden; }

        #confetti-layer i{ position:absolute; display:block; width:8px; height:12px; border-radius:2.5px; }

        #confetti-layer i.rnd{ width:8px; height:8px; border-radius:50%; }



        /* Suggerimento una-tantum */

        .coach{

            position:fixed; left:50%; bottom:calc(var(--safe-bottom) + 26px); z-index:7000;

            transform:translateX(-50%);

            max-width:88%; padding:12px 18px; border-radius:18px;

            background:var(--text); color:var(--bg);

            font-size:13.5px; font-weight:500; text-align:center;

            box-shadow:0 12px 40px rgba(0,0,0,.3);

            animation:coachIn .5s var(--spring) both;

        }

        @keyframes coachIn{ from{ transform:translate(-50%, 20px); opacity:0; } }

        .coach.bye{ transition:.35s; opacity:0; transform:translate(-50%, 14px); }



        @media (prefers-reduced-motion: reduce){

            *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }

        }

    </style>

    <script src="fc_parser.js"></script>

</head>

<body>

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

         data-pos="<?php echo $position; ?>"

         data-can-back="<?php echo ($position > 1) ? '1' : '0'; ?>"

         data-back-url="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position-1; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>"

         data-can-forward="<?php echo ($position < $max_position_generated || ($all_answered && $position < $total_words)) ? '1' : '0'; ?>"

         data-forward-url="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position+1; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>"

         data-chrono-base="<?php echo $chrono_base_seconds; ?>"

         data-chrono-start="<?php echo $chrono_start_ts; ?>">



        <header class="app-header">
            <div class="bars">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill fill-pages" style="width: <?php echo min(100, ($answered_pages_count / $total_words) * 100); ?>%;"></div>
                    <?php foreach($errors_pages as $err_p): $percent = (($err_p - 0.5) / $total_words) * 100; ?>
                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $err_p; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="dot dot-red" style="left: <?php echo $percent; ?>%;" title="Errore a pagina <?php echo $err_p; ?>"></a>
                    <?php endforeach; ?>
                    <?php $jump_percent = (($jump_back_pos - 0.5) / $total_words) * 100; ?>
                    <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $jump_back_pos; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="dot dot-white" style="left: <?php echo $jump_percent; ?>%;" title="Torna all'ultima pagina in sospeso"></a>
                </div>

                <?php if ($total_words != $total_cards): ?>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill fill-cards" style="width: <?php echo min(100, ($answered_cards_count / $total_cards) * 100); ?>%;"></div>
                    <?php foreach($errors_cards as $err_c): $percent = (($err_c['idx'] - 0.5) / $total_cards) * 100; ?>
                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $err_c['pos']; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="dot dot-red" style="left: <?php echo $percent; ?>%;" title="Errore a carta <?php echo $err_c['idx']; ?>"></a>
                    <?php endforeach; ?>
                    <?php $jump_percent2 = (($jump_back_card_index - 0.5) / $total_cards) * 100; ?>
                    <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $jump_back_pos; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="dot dot-white dot-white-alt" style="left: <?php echo $jump_percent2; ?>%;" title="Torna all'ultima carta in sospeso"></a>
                </div>
                <?php endif; ?>
            </div>

            <div class="hdr-row">
                <div class="hdr-left">
                    <div class="hdr-title"><?php echo $position; ?> <span>/ <?php echo $total_words; ?></span></div>
                    <div class="hdr-sub">
                        Concetti <b><?php echo $answered_pages_count; ?>/<?php echo $total_words; ?></b>
                        <?php if ($total_words != $total_cards): ?>
                            &middot; Card <b><?php echo $answered_cards_count; ?>/<?php echo $total_cards; ?></b>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hdr-right">
                    <button id="chrono-pill" class="chrono-pill" type="button" title="Durata partita" aria-label="Durata partita">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="chrono-ic"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></svg>
                        <span class="chrono-txt" id="chrono-txt">0:00</span>
                    </button>
                    <span class="chip chip-status <?php echo ($status === 'all') ? 'st-all' : (($status == 1) ? 'st-learnt' : ''); ?>"><?php echo $status_text; ?></span>
                    <button id="ttsSettingsBtn" class="icon-btn" type="button" title="Impostazioni lettura" aria-label="Impostazioni lettura">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a9 9 0 0 1 0 14"/></svg>
                    </button>
                    <button id="voiceGameBtn" class="icon-btn" type="button" title="Gioca con audio" aria-label="Gioca con audio" onclick="if(window.VoiceGameMode) VoiceGameMode.handleHeaderButton();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>
                    </button>
                </div>
            </div>
        </header>



        <div id="tts-modal" style="display:none;">

            <div class="sheet-panel">

                <h3>Impostazioni lettura</h3>



                <div class="field">

                    <label for="tts-mode">Modalità</label>

                    <select id="tts-mode">

                        <option value="no">No</option>

                        <option value="click">Solo al click</option>

                        <option value="auto">Automatica</option>

                    </select>

                    <div class="field-hint" id="tts-mode-hint" style="display:none;">🎙️ Sei in <b>modalità microfono</b>: è lei a decidere cosa leggere e quando, quindi qui non c'è nulla da scegliere. Le altre impostazioni (voce, velocità, LaTeX) restano valide.</div>

                </div>



                <div class="field">

                    <label for="tts-lang">Lingua</label>

                    <select id="tts-lang">

                        <option value="it">Italiano</option>

                        <option value="en">Inglese</option>

                        <option value="auto">Auto</option>

                    </select>

                </div>



                <div class="field">

                    <label for="tts-voice">Voce del dispositivo</label>

                    <select id="tts-voice">

                        <option value="">Automatica (migliore disponibile)</option>

                    </select>

                    <div class="field-hint" id="tts-voice-hint">Usata solo se il motore è "Voce del dispositivo". Le voci di bassa qualità (Eloquence e simili) sono raggruppate in fondo.</div>

                    <div class="field-hint field-warn" id="tts-voice-warn" style="display:none;"></div>

                </div>



                <div class="field">

                    <label for="tts-ai-voice">Voce IA (Cloud)</label>

                    <select id="tts-ai-voice">

                        <optgroup label="Maschili">

                            <option value="it-IT-Chirp3-HD-Charon" selected>Charon</option>

                            <option value="it-IT-Chirp3-HD-Puck">Puck</option>

                            <option value="it-IT-Chirp3-HD-Fenrir">Fenrir</option>

                            <option value="it-IT-Chirp3-HD-Orus">Orus</option>

                        </optgroup>

                        <optgroup label="Femminili">

                            <option value="it-IT-Chirp3-HD-Aoede">Aoede</option>

                            <option value="it-IT-Chirp3-HD-Kore">Kore</option>

                            <option value="it-IT-Chirp3-HD-Leda">Leda</option>

                            <option value="it-IT-Chirp3-HD-Zephyr">Zephyr</option>

                        </optgroup>

                    </select>

                </div>



                <div class="field">

                    <label for="tts-speed">Velocità</label>

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

                    <label for="tts-math">Leggi LaTeX</label>

                    <select id="tts-math">

                        <option value="yes">Sì</option>

                        <option value="no">No</option>

                    </select>

                </div>



                <button id="tts-save" class="btn btn-primary" style="margin-top:22px;">Salva e chiudi</button>

            </div>

        </div>



        <div id="ai-modal" style="display:none;">

            <div class="sheet-panel">

                <button class="sheet-close" type="button" onclick="closeAiModal()" aria-label="Chiudi">&#10005;</button>

                <h3 id="ai-modal-title">🧠 Spiegazione AI</h3>

                <div id="ai-timer" class="ai-timer"><span class="dot"></span><span id="ai-timer-text"></span></div>

                <div id="ai-audio-status" class="ai-status" style="display: none;">⏳ Generazione audio in corso...</div>

                <div id="ai-modal-content" class="raw-text ai-body"></div>

            </div>

        </div>



        <div id="shatter-modal" style="display:none;">

            <div class="sheet-panel">

                <button class="sheet-close" type="button" onclick="closeShatterModal()" aria-label="Chiudi">&#10005;</button>

                <h3 style="color: var(--purple);">💥 Flashcard frantumata</h3>

                <div id="shatter-content"></div>

                <div id="shatter-actions" class="sheet-actions" style="display:none;">

                    <a href="#" id="shatter-wrong-btn" class="btn btn-red action-btn" onclick="closeShatterModal()"><span class="gi">&#10005;</span> Sbagliato</a>

                    <a href="#" id="shatter-correct-btn" class="btn btn-green action-btn" onclick="closeShatterModal()"><span class="gi">&#10003;</span> Corretto</a>

                </div>

            </div>

        </div>



        <div id="token-limit-modal" style="display:none;">

            <div class="sheet-panel" style="text-align:center;">

                <button class="sheet-close" type="button" onclick="closeTokenLimitModal()" aria-label="Chiudi">&#10005;</button>

                <div class="limit-icon">⚡️</div>

                <h3 style="text-align:center;">Token IA quasi finiti</h3>

                <div class="limit-modal-msg" id="token-limit-message">Hai raggiunto il limite mensile di Token IA.</div>

                <div class="limit-modal-sub">Il limite si azzera a inizio mese.</div>

                <button class="btn btn-primary" type="button" onclick="closeTokenLimitModal()">Ho capito</button>

            </div>

        </div>

        <div id="api-slow-toast" class="api-slow-toast"><span>⏳</span><span>Le API di Google sembrano più lente del solito</span></div>



        <main class="feed">

            <div class="feed-inner">



                <?php foreach ($cards_to_show as $index => $c): 

                    $is_graded = ($c['user_answer'] != '');

                ?>

                <div class="card-box <?php echo $is_graded ? 'graded' : ''; ?><?php if ($is_graded) echo ($c['user_answer'] == 'Correct') ? ' res-correct' : ' res-wrong'; ?>" id="card-<?php echo $index; ?>"

                     data-card-id="<?php echo $c['gameplay_idimmagine']; ?>"

                     data-qaudio="<?php echo $c['question_audio']; ?>" data-aaudio="<?php echo $c['answer_audio']; ?>"

                     <?php if (!$is_graded): ?>

                     data-swipe="1"

                     data-url-wrong="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&action=grade&img_id=<?php echo $c['gameplay_idimmagine']; ?>&result=wrong&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>"

                     data-url-correct="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&action=grade&img_id=<?php echo $c['gameplay_idimmagine']; ?>&result=correct&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>"

                     <?php endif; ?>>



                    <?php if(count($cards_to_show) > 1): ?>

                        <div class="card-title <?php echo $c['is_child'] ? 'is-child' : ''; ?>"><?php echo $c['is_child'] ? '&#8627; Sottoconcetto' : 'Concetto principale'; ?></div>

                    <?php endif; ?>



                    <div class="q-section" id="q-section-<?php echo $index; ?>" onclick="playAudioFC(<?php echo $index; ?>, 'Q')" style="cursor: pointer;">

                        <div class="section-title">Domanda</div>

                        <?php if (!empty($c['question_audio'])): ?>

                            <audio id="player_q_<?php echo $index; ?>" src="fc_audio/<?php echo $c['question_audio']; ?>" controls onclick="event.stopPropagation();"></audio>

                        <?php endif; ?>

                        <?php if (!empty(trim($c['question_image']))): ?>

                            <img src="fc_Images/<?php echo trim($c['question_image']); ?>" class="fc-image">

                        <?php endif; ?>

                        <?php if (!empty(trim($c['question_desc']))): ?>

                            <div class="fc-text raw-text" data-raw="<?php echo htmlspecialchars(trim($c['question_desc']), ENT_QUOTES); ?>"></div>

                        <?php endif; ?>

                    </div>



                    <?php if (!$is_graded): ?>

                        <div class="tool-row">

                            <button type="button" class="chip-shatter" onclick="shatterCard(<?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">💥 Frantuma</button>

                        </div>



                        <button id="btn-show-<?php echo $index; ?>" class="btn btn-primary" onclick="revealCard(<?php echo $index; ?>)">Mostra risposta <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>



                        <div class="answer-box ans-block" id="ans-section-<?php echo $index; ?>" style="display:none;">

                            <div class="section-title st-answer">Risposta</div>

                            <?php if (!empty($c['answer_audio'])): ?>

                                <audio id="player_a_<?php echo $index; ?>" src="fc_audio/<?php echo $c['answer_audio']; ?>" controls></audio>

                            <?php endif; ?>

                            <?php if (!empty(trim($c['answer_image']))): ?>

                                <img src="fc_Images/<?php echo trim($c['answer_image']); ?>" class="fc-image">

                            <?php endif; ?>

                            <?php if (!empty(trim($c['answer_desc']))): ?>

                                <div class="fc-text raw-text" data-raw="<?php echo htmlspecialchars(trim($c['answer_desc']), ENT_QUOTES); ?>"></div>

                            <?php endif; ?>

                            <!-- BOTTONI AI TUTOR -->

                            <?php 

                                // Preparazione sicura per il Javascript

                                $qTextJs = "document.querySelector('#q-section-{$index} .raw-text') ? document.querySelector('#q-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                                $aTextJs = "document.querySelector('#ans-section-{$index} .raw-text') ? document.querySelector('#ans-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                            ?>

                            <div class="ai-tools-box">

                                <span class="ai-spark"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span>



                                <?php if($c['examples_count'] > 0): ?>

                                    <button id="btn-ex-<?php echo $c['gameplay_idimmagine']; ?>" type="button" class="nav-btn chip-ai has-ex" onclick="event.stopPropagation(); openExamplesModal(<?php echo $c['gameplay_idimmagine']; ?>, <?php echo $qTextJs; ?>, <?php echo $aTextJs; ?>)">💡 Esempi (<?php echo $c['examples_count']; ?>)</button>

                                <?php else: ?>

                                    <button id="btn-ex-<?php echo $c['gameplay_idimmagine']; ?>" type="button" class="nav-btn chip-ai" onclick="event.stopPropagation(); openExamplesModal(<?php echo $c['gameplay_idimmagine']; ?>, <?php echo $qTextJs; ?>, <?php echo $aTextJs; ?>)">➕ Esempio</button>

                                <?php endif; ?>



                                <div class="ai-sep"></div>



                                <button type="button" class="nav-btn chip-ai" onclick="playAI('read', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🔊 Leggi</button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('explain', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🧠 Spiega</button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('metaphor', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🎭 Metafora</button>

                            </div>



                            <div class="grade-row">

                                <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&action=grade&img_id=<?php echo $c['gameplay_idimmagine']; ?>&result=wrong&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="btn btn-red action-btn"><span class="gi">&#10005;</span> Sbagliato</a>

                                <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&action=grade&img_id=<?php echo $c['gameplay_idimmagine']; ?>&result=correct&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="btn btn-green action-btn"><span class="gi">&#10003;</span> Corretto</a>

                            </div>



                            <?php if($status === '0' || $status === 'all'): ?>

                                <div class="learnt-row">

                                    <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&action=grade&img_id=<?php echo $c['gameplay_idimmagine']; ?>&result=learnt&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="btn btn-learnt action-btn" style="flex:1;">⚡ Imparato</a>



                                    <?php 

                                    // Creiamo il parametro URL corretto a seconda che sia Padre (fc_id) o Figlio (child_id) convertendo il negativo in positivo

                                    $edit_param = $c['is_child'] ? 'child_id=' . abs($c['gameplay_idimmagine']) : 'fc_id=' . $c['gameplay_idimmagine']; 

                                    ?>



                                    <a href="modifica_immagine_fc.php?<?php echo $edit_param; ?>&return_to_game=1&game_id=<?php echo $game_id; ?>&position=<?php echo $position; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" 

                                    class="btn btn-edit action-btn" title="Modifica"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></a>

                                </div>

                            <?php endif; ?>



                        </div>



                    <?php else: ?>



                        <?php 

                        if ($c['user_answer'] == 'Correct') {

                            $msg = "Corretto";

                        } else {

                            $msg = "Errato";

                        }

                        ?>

                        <div class="graded-head">

                            <div class="status-pill <?php echo ($c['user_answer'] == 'Correct') ? 'ok' : 'ko'; ?>"><span class="gi"><?php echo ($c['user_answer'] == 'Correct') ? '&#10003;' : '&#10005;'; ?></span> <?php echo $msg; ?></div>

                            <button class="nav-btn pill-toggle action-btn" type="button" onclick="toggleAnswer(<?php echo $index; ?>, this)">Mostra Risposta</button>

                        </div>



                        <div class="answer-box ans-block is-revealed" id="graded-ans-<?php echo $index; ?>" style="display:none; margin-top:12px;">

                            <div class="section-title st-answer">Risposta</div>

                            <?php if (!empty($c['answer_audio'])): ?>

                                <audio id="player_a_<?php echo $index; ?>" src="fc_audio/<?php echo $c['answer_audio']; ?>" controls></audio>

                            <?php endif; ?>

                            <?php if (!empty(trim($c['answer_image']))): ?>

                                <img src="fc_Images/<?php echo trim($c['answer_image']); ?>" class="fc-image">

                            <?php endif; ?>

                            <?php if (!empty(trim($c['answer_desc']))): ?>

                                <div class="fc-text raw-text" data-raw="<?php echo htmlspecialchars(trim($c['answer_desc']), ENT_QUOTES); ?>"></div>

                            <?php endif; ?>

                            <?php 

                                $qTextJs = "document.querySelector('#q-section-{$index} .raw-text') ? document.querySelector('#q-section-{$index} .raw-text').getAttribute('data-raw') : ''";

                                $aTextJs = "document.querySelector('#graded-ans-{$index} .raw-text') ? document.querySelector('#graded-ans-{$index} .raw-text').getAttribute('data-raw') : ''";

                            ?>

                            <div class="ai-tools-box">

                                <span class="ai-spark"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span>



                                <?php if($c['examples_count'] > 0): ?>

                                    <button id="btn-ex-graded-<?php echo $c['gameplay_idimmagine']; ?>" type="button" class="nav-btn chip-ai has-ex" onclick="event.stopPropagation(); openExamplesModal(<?php echo $c['gameplay_idimmagine']; ?>, <?php echo $qTextJs; ?>, <?php echo $aTextJs; ?>)">💡 Esempi (<?php echo $c['examples_count']; ?>)</button>

                                <?php else: ?>

                                    <button id="btn-ex-graded-<?php echo $c['gameplay_idimmagine']; ?>" type="button" class="nav-btn chip-ai" onclick="event.stopPropagation(); openExamplesModal(<?php echo $c['gameplay_idimmagine']; ?>, <?php echo $qTextJs; ?>, <?php echo $aTextJs; ?>)">➕ Esempio</button>

                                <?php endif; ?>



                                <div class="ai-sep"></div>



                                <button type="button" class="nav-btn chip-ai" onclick="playAI('read', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🔊 Leggi</button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('explain', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🧠 Spiega</button>

                                <button type="button" class="nav-btn chip-ai" onclick="playAI('metaphor', <?php echo $index; ?>, <?php echo $c['gameplay_idimmagine']; ?>, this)">🎭 Metafora</button>

                            </div>

                        </div>



                    <?php endif; ?>

                </div>

                <?php endforeach; ?>



                <div class="nav-row">

                    <?php if ($position > 1): ?>

                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position-1; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="nav-btn action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg> Indietro</a>

                    <?php endif; ?>



                    <?php if ($position < $max_position_generated): ?>

                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position+1; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="nav-btn nav-fwd action-btn">Avanti <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>

                    <?php elseif ($all_answered && $position < $total_words): ?>

                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $position+1; ?>&status=<?php echo $status; ?>&order=<?php echo $order_param; ?>" class="nav-btn nav-fwd action-btn">Avanti <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>

                    <?php endif; ?>

                </div>



                <div id="end-game-section">

                    <button type="button" id="btn-concludi-1" class="btn btn-ghost end-btn" onclick="mostraConfermaFinale()">Concludi e vedi punteggio</button>

                    <div id="conferma-finale-box" class="card-warn" style="display: none;">

                        <p>Sei sicuro di voler concludere in anticipo?</p>

                        <a href="game_fc.php?game_id=<?php echo $game_id; ?>&action=concludi" class="btn btn-red end-link">Sì, mostra i risultati</a>

                        <button type="button" class="nav-btn" onclick="annullaChiusura()">No, continua</button>

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

            if (e.target && e.target.classList.contains('fc-image')) {

                document.getElementById('overlay-img').src = e.target.src;

                document.getElementById('image-overlay').style.display = 'flex';

                document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover');

            }

        });



        document.getElementById('close-overlay').addEventListener('click', function() {

            document.getElementById('image-overlay').style.display = 'none';

            document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover');

        });



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
                console.warn('Elenco voci non disponibile:', e);
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

            return cont.querySelectorAll('.card-box').length > 1;

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
            autoOpt.textContent = list.length ? 'Automatica (migliore disponibile)' : 'Nessuna voce disponibile';
            sel.appendChild(autoOpt);

            function etichetta(v) {
                if (/premium/i.test(v.name)) return ' ⭐️ Premium';
                if (/enhanced/i.test(v.name)) return ' ⭐️ Enhanced';
                if (v.localService === false) return ' ☁️ (di rete)';
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
                g2.label = 'Bassa qualità (Eloquence e simili)';
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
                hint.innerHTML = '⚠️ <b>' + v.name + '</b> è una voce di rete: sopra 1.2&times; il browser la fa sbiascicare. È un limite del browser, non del sito. Usa 1.2&times; o meno, oppure usa il tasto <b>Leggi</b>, che passa dalla voce IA.';
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

                btn.innerText = 'Nascondi Risposta';

            } else {

                ansBox.style.display = 'none';

                btn.innerText = 'Mostra Risposta';

            }

        }



        function revealCard(index) {

            var showBtn = document.getElementById('btn-show-' + index);

            var ansBox = document.getElementById('ans-section-' + index);

            if (showBtn) showBtn.style.display = 'none';

            ansBox.style.display = 'block';

            ansBox.classList.add('reveal-anim');

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

            let primaDomanda = cont.querySelector('.card-box:not(.graded)');

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

            let cards = cont.querySelectorAll('.card-box');
            out['8_card_nel_container'] = cards.length + (cards.length > 1 ? ' ❌ (>1 = considerate sotto-concetti, lettura saltata)' : '');

            let prima = cont.querySelector('.card-box:not(.graded)');
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
                let righe = ['DIAGNOSTICA LETTURA AUTOMATICA', ''];
                Object.keys(out).forEach(function (k) { righe.push(k + ': ' + out[k]); });
                righe.push('');
                righe.push('UA: ' + navigator.userAgent);
                box.textContent = righe.join('\n');
                let close = document.createElement('button');
                close.textContent = '✕ chiudi';
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
            document.getElementById('token-limit-message').textContent = message || 'Hai raggiunto il limite mensile di Token IA.';
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
            let stimaLabel = stats.avg_seconds ? `di solito ≈ ${stats.avg_seconds}s` : 'generazione in corso';
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

                    let textResponse = await fetch('ai_generate_text.php', {

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

                        alert("⚠️ ERRORE DAL SERVER (testo):\n\n" + textRawResult);

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

                    modalTitle.innerText = mode === 'explain' ? '🧠 Spiegazione' : '🎭 Metafora';

                    modalTitle.style.color = mode === 'explain' ? '#8e24aa' : '#f57c00';

                    

                    let modalContent = document.getElementById('ai-modal-content');

                    modalContent.innerHTML = parseTextForPreview(textData.text);

                    if (typeof MathJax !== 'undefined') MathJax.typesetPromise([modalContent]);

                    

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

                let response = await fetch('ai_tutor.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ mode: mode, text: audioText, id: cardId, voice: ttsSettings.aiVoice || 'it-IT-Chirp3-HD-Charon' })

                });

                

                if (myActionId !== currentAiActionId) return; // Se l'utente ha chiuso il popup, fermati.



                let rawResult = await response.text(); 

                let data;

                try { data = JSON.parse(rawResult); } 

                catch(e) {

                    alert("⚠️ ERRORE DAL SERVER (audio):\n\n" + rawResult);

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

                            document.getElementById('ai-audio-status').innerHTML = '⚠️ Audio pronto. <button onclick="currentAiAudio.play()" style="padding:4px 8px; border-radius:4px; cursor:pointer;">▶️ Ascolta</button>';

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

                alert("Errore di connessione.");

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

            let shatterEta = shatterStats.avg_seconds ? `Di solito ci mette circa ${shatterStats.avg_seconds}s.` : 'Potrebbe volerci qualche secondo, porta pazienza!';

            contentDiv.innerHTML = `<div style="text-align:center; padding: 30px; font-weight:700; color:var(--blue); line-height:1.5;">🧠 Gemini sta analizzando e dividendo la card in concetti atomici...<br><small style="color:var(--text-2); font-weight:500;">${shatterEta}</small></div>`;

            actionsDiv.style.display = 'none';

            document.getElementById('shatter-modal').style.display = 'flex';

            if (shatterStats.slower_than_usual) showApiSlowToast();



            try {

                let response = await fetch('ai_shatter.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ q: qText, a: aText, id: cardId })

                });



                let rawResult = await response.text();

                let data;

                try { data = JSON.parse(rawResult); } catch(e) {

                    contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">Errore di formattazione API. Gemini ha risposto in modo anomalo.</div>';

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

                            <button id="shatter-btn-${i}" class="btn btn-primary btn-sm" onclick="revealShatterCard(${i})">Mostra risposta</button>

                            <div id="shatter-ans-${i}" class="mini-ans" style="display:none;">

                                <div class="raw-text mini-a" id="shatter-araw-${i}" data-raw="${escAttr(mini.a)}">${parseTextForPreview(mini.a)}</div>

                            </div>

                        </div>`;

                    });

                    contentDiv.innerHTML = html;

                    

                    // Applica LaTeX se presente

                    if (typeof MathJax !== 'undefined') MathJax.typesetPromise([contentDiv]);



                    // Copia magicamente gli URL "Corretto" e "Sbagliato" della flashcard originale!

                    let wrongUrl = document.querySelector('#ans-section-' + index + ' a.btn-red').href;

                    let correctUrl = document.querySelector('#ans-section-' + index + ' a.btn-green').href;

                    

                    document.getElementById('shatter-wrong-btn').href = wrongUrl;

                    document.getElementById('shatter-correct-btn').href = correctUrl;

                    

                    actionsDiv.style.display = 'flex';

                } else if (data.error === 'limite_piano') {

                    closeShatterModal();

                    showTokenLimitModal(data.message);

                } else {

                    contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">Nessun risultato valido restituito.</div>';

                }

            } catch (err) {

                contentDiv.innerHTML = '<div style="color:red; font-weight:bold;">Errore di rete o server offline.</div>';

                console.error(err);

            }



            btn.innerHTML = originalText;

            btn.disabled = false;

        }

        // ==========================================
        // RENDERING TESTI CON IL NUOVO PARSER
        // ==========================================
        function renderAllRawTexts() {
            try {
                let mathNodes = [];
                document.querySelectorAll('.raw-text').forEach(function(el) {
                    let rawData = el.getAttribute('data-raw');
                    if (rawData !== null) {
                        el.innerHTML = parseTextForPreview(rawData);
                        mathNodes.push(el); // Salviamo solo gli elementi appena aggiornati
                    }
                });
                
                // Aggiorniamo MathJax in modo sicuro e isolato
                if (typeof MathJax !== 'undefined' && MathJax.typesetPromise) {
                    MathJax.typesetPromise(mathNodes).catch(function(err) {
                        console.warn("MathJax typeset in coda...", err);
                    });
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

                var pill = e.target.closest && e.target.closest('#chrono-pill');

                if (!pill) return;

                e.preventDefault();

                chronoCollapsed = !chronoCollapsed;

                pill.classList.toggle('is-collapsed', chronoCollapsed);

                try { localStorage.setItem('chronoCollapsed', chronoCollapsed ? '1' : '0'); } catch (err) {}

            });

            chronoApplyState();

            setInterval(chronoTick, 1000);



            function cardsIn (root) {

                if (!root) return;

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



            /* Le barre di progresso si riempiono animate dal valore vecchio al nuovo */

            function animateProgress (oldC, newC) {

                var oldFills = $$('.progress-bar-fill', oldC).map(function (f) { return f.style.width; });

                $$('.progress-bar-fill', newC).forEach(function (f, i) {

                    var target = f.style.width;

                    if (oldFills[i] !== undefined && oldFills[i] !== target) {

                        f.style.transition = 'none';

                        f.style.width = oldFills[i];

                        void f.offsetWidth;

                        f.style.transition = '';

                        requestAnimationFrame(function () { f.style.width = target; });

                    }

                });

            }



            /* ---------- NAVIGAZIONE AJAX CON TRANSIZIONI (sostituisce il vecchio interceptor) ---------- */

            function navigateTo (url, opts) {

                opts = opts || {};

                if (navLock) return;

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



                fetch(url).then(function (res) {

                    if (res.url && res.url.indexOf('result_fc.php') !== -1) { window.location.href = res.url; return null; }

                    return res.text().then(function (html) { return { res: res, html: html }; });

                }).then(function (pack) {

                    if (!pack) return;

                    var doc = new DOMParser().parseFromString(pack.html, 'text/html');

                    var newC = doc.querySelector('.container');

                    if (!newC || !newC.getAttribute('data-pos')) { window.location.href = pack.res.url || url; return; }



                    var newPos = parseInt(newC.getAttribute('data-pos'), 10);

                    var dir = newPos > prevPos ? 'up' : (newPos < prevPos ? 'down' : 'same');



                    // Evita ID duplicati durante la transizione

                    $$('[id]', oldC).forEach(function (n) { n.removeAttribute('id'); });

                    if (oldC.id) oldC.removeAttribute('id');



                    document.body.appendChild(newC);

                    var newFeed = newC.querySelector('.feed');



                    if (dir === 'same' && oldFeed && newFeed) newFeed.scrollTop = oldFeed.scrollTop;



                    animateProgress(oldC, newC);

                    syncHeader();

                    chronoApplyState();



                    var held = opts.held || 0;

                    var D = 460;

                    try {

                        if (dir === 'up') {

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ transform: 'translateY(' + held + 'px)', opacity: 1 }, { transform: 'translateY(-34%)', opacity: 0 }], { duration: D, easing: EASE, fill: 'both' });

                            newFeed.animate([{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }], { duration: D, easing: EASE, fill: 'both' });

                            cardsIn(newC);

                        } else if (dir === 'down') {

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ transform: 'translateY(' + held + 'px)', opacity: 1 }, { transform: 'translateY(34%)', opacity: 0 }], { duration: D, easing: EASE, fill: 'both' });

                            newFeed.animate([{ transform: 'translateY(-100%)' }, { transform: 'translateY(0)' }], { duration: D, easing: EASE, fill: 'both' });

                            cardsIn(newC);

                        } else {

                            D = 340;

                            if (oldHeader) oldHeader.style.opacity = '0';

                            oldFeed.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200, easing: 'ease-out', fill: 'both' });

                            newFeed.animate([{ opacity: 0, transform: 'scale(.985)' }, { opacity: 1, transform: 'scale(1)' }], { duration: D, easing: EASE, fill: 'both' });

                            $$('.status-pill', newC).forEach(function (p) {

                                p.animate([{ transform: 'scale(.6)', opacity: 0 }, { transform: 'scale(1.08)', opacity: 1 }, { transform: 'scale(1)', opacity: 1 }], { duration: 420, easing: EASE });

                            });

                        }

                    } catch (e) {}



                    window.history.pushState({}, '', pack.res.url || url);

                    renderAllRawTexts();

                    setTimeout(checkAutoPlayQuestion, 300);

                    maybeCoach();



                    setTimeout(function () {

                        oldC.remove();

                        navLock = false;

                    }, D + 60);

                }).catch(function () {

                    window.location.href = url;

                });

            }



            /* Interceptor click: stessa cattura del vecchio (a.btn / a.nav-btn) + pallini,

               stessi esclusi (end-link, result_fc, concludi) */

            document.addEventListener('click', function (e) {

                if (suppressNextClick) { suppressNextClick = false; e.stopPropagation(); e.preventDefault(); return; }

                if (!e.target || !e.target.closest) return;

                var link = e.target.closest('a.btn, a.nav-btn, a.dot');

                if (!link || !link.href || link.classList.contains('end-link')) return;

                if (link.href.indexOf('result_fc.php') !== -1 || link.href.indexOf('action=concludi') !== -1) return;

                e.preventDefault();

                if (navLock) return;

                if (link.href.indexOf('game_fc.php') === -1) {

                    stopAllMedia();

                    window.location.href = link.href;

                    return;

                }

                if (link.href.indexOf('action=grade') !== -1) {

                    var result = 'correct';

                    try { result = new URL(link.href, window.location.href).searchParams.get('result') || 'correct'; } catch (err) {}

                    var card = link.closest('.card-box');

                    gradeFeedback(card, result).then(function () { navigateTo(link.href); });

                } else {

                    navigateTo(link.href);

                }

            }, true);



            /* ================================================================

               SWIPE STILE TINDER — tieni premuta la card e trascinala

               ================================================================ */

            var sw = null;



            document.addEventListener('pointerdown', function (e) {

                if (navLock || sw) return;

                if (e.pointerType === 'mouse' && e.button !== 0) return;

                if (!e.target || !e.target.closest) return;

                var card = e.target.closest('.card-box[data-swipe="1"]');

                if (!card) return;

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

                    tint.style.opacity = '0';

                    stamp.style.opacity = '0';

                } else {

                    tint.style.background = good ? 'var(--tint-green)' : 'var(--tint-red)';

                    tint.style.opacity = (p * 0.55).toFixed(3);

                    stamp.textContent = good ? 'CORRECT' : 'WRONG';

                    stamp.style.opacity = Math.min(1, p * 1.25).toFixed(3);

                    stamp.style.transform = 'translate(-50%,-50%) rotate(' + (good ? -10 : 10) + 'deg) scale(' + (0.8 + p * 0.28).toFixed(3) + ')';

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

                s.card.classList.remove('dragging');



                var committed = commit && Math.abs(s.dx) >= TH();

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

                    setTimeout(function () { navigateTo(url); }, 140);

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

                    ? (vp.mode === 'back' ? 'Pagina precedente' : 'Pagina successiva')

                    : (vp.mode === 'back' ? 'Sei alla prima pagina' : 'Rispondi per continuare');

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

                    var card = c.querySelector('.card-box[data-swipe="1"]');

                    if (card) {

                        var good = (e.key === 'ArrowRight');

                        var url = good ? card.getAttribute('data-url-correct') : card.getAttribute('data-url-wrong');

                        if (url) {

                            e.preventDefault();

                            gradeFeedback(card, good ? 'correct' : 'wrong').then(function () { navigateTo(url); });

                        }

                    }

                } else if (e.key === ' ') {

                    var b = c.querySelector('.card-box:not(.graded) button[id^="btn-show-"]');

                    if (b && b.offsetParent) { e.preventDefault(); b.click(); }

                }

            });



            /* ---------- Suggerimento una-tantum sullo swipe ---------- */

            function maybeCoach () {

                var seen = false;

                try { seen = !!localStorage.getItem('fcCoachSeen2'); } catch (e) { seen = true; }

                if (seen) return;

                var c = curC();

                if (!c || !c.querySelector('.card-box[data-swipe="1"]')) return;

                if (document.querySelector('.coach')) return;

                setTimeout(function () {

                    if (document.querySelector('.coach')) return;

                    var t = document.createElement('div');

                    t.className = 'coach';

                    t.innerHTML = '👉 Trascina la card: <b>destra = corretto</b>, <b>sinistra = sbagliato</b>';

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

                cardsIn(curC());

                maybeCoach();

            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApp);

            else initApp();

        })();

    </script>

    <?php include 'floating_player.php'; ?>

    <?php include 'examples_modal.php'; ?>

    <?php include 'voice_game_mode.php'; ?>

</body>

</html>