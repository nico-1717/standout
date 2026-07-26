<?php



require_once 'config.php';

require_once 'auth_check.php'; // 1. Protezione della pagina con login obbligatorio



$current_user_id = $utente_corrente['id']; // 2. ID Utente sicuro dal server



$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);















if (!isset($_GET['game_id'])) { header('Location: index.php'); exit; }







$game_id = intval($_GET['game_id']);















if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_user_id'])) {

    // SICUREZZA: Sovrascriviamo l'ID del form usando solo l'ID dell'utente autenticato
    $new_user_id = $current_user_id;

    // Controlliamo che la partita appartenga già a lui o sia attualmente non assegnata (0 o NULL)
    $check_stmt = $conn->prepare("SELECT user_id FROM games WHERE id = ?");
    $check_stmt->bind_param("i", $game_id);
    $check_stmt->execute();
    $res_check = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($res_check && ($res_check['user_id'] == null || intval($res_check['user_id']) === 0 || intval($res_check['user_id']) === $current_user_id)) {
        $upd_stmt = $conn->prepare("UPDATE games SET user_id = ? WHERE id = ?");
        $upd_stmt->bind_param("ii", $new_user_id, $game_id);
        $upd_stmt->execute();
        $upd_stmt->close();
    } else {
        die("Errore di sicurezza: Non sei autorizzato a modificare questa partita.");
    }
}

$query = "SELECT g.*, u.username FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close(); // Chiudi lo statement

// --- INIZIO CONTROLLO PROPRIETÀ ---
if ($game) {
    if (intval($game['user_id']) !== $current_user_id) {
        die("Errore di sicurezza: Non sei il proprietario di questa partita.");
    }
} else {
    die("Partita non trovata.");
}
// --- FINE CONTROLLO PROPRIETÀ ---

// ================= DURATA TOTALE PARTITA (somma di tutte le sessioni) =================

$q_durata = mysqli_query($conn, "SELECT COALESCE(SUM(duration_seconds),0) as tot FROM session_durations WHERE game_id = $game_id");



$durata_totale_secondi = $q_durata ? (int) mysqli_fetch_assoc($q_durata)['tot'] : 0;







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
$q_play = "SELECT * FROM fc_gameplays WHERE game_id = $game_id ORDER BY position ASC, id ASC";

$res_play = mysqli_query($conn, $q_play);

$cards = [];
$played_items = 0;
$correct_count = 0;
$error_count = 0;
$positions_played = [];

while ($gp = mysqli_fetch_assoc($res_play)) {

    // Se fc_image_id è valorizzato, stiamo leggendo un concetto principale (Padre)
    if (!empty($gp['fc_image_id']) && intval($gp['fc_image_id']) > 0) {
        $id = intval($gp['fc_image_id']);
        $c_res = mysqli_query($conn, "SELECT question_image, question_desc, answer_image, answer_desc FROM fc_images WHERE id = $id");
        $card = mysqli_fetch_assoc($c_res);
        if ($card) $card['is_child'] = false;
        
    // Altrimenti, se è valorizzato fc_child_id, stiamo leggendo una sotto-carta (Figlio)
    } else if (!empty($gp['fc_child_id']) && intval($gp['fc_child_id']) > 0) {
        $real_id = intval($gp['fc_child_id']);
        $c_res = mysqli_query($conn, "SELECT question_image, question_desc, answer_image, answer_desc FROM fc_child_cards WHERE id_child = $real_id");
        $card = mysqli_fetch_assoc($c_res);
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
$display_username = isset($game['username']) ? htmlspecialchars($game['username']) : 'Utente Sconosciuto';

// ==========================================
// FIX RESUME INTELLIGENTE "CONTINUA PARTITA"
// ==========================================

$q_resume = mysqli_query($conn, "SELECT MIN(position) as resume_pos FROM fc_gameplays WHERE game_id = $game_id AND user_answer = ''");
$row_resume = mysqli_fetch_assoc($q_resume);
if ($row_resume && $row_resume['resume_pos']) {
    // C'è una domanda (o figlio) non risposta, riprendiamo da qui!
    $resume_position = $row_resume['resume_pos'];
} else {
    // Tutte le domande attuali sono risposte, passiamo alla prossima posizione inedita
    $q_max = mysqli_query($conn, "SELECT MAX(position) as max_pos FROM fc_gameplays WHERE game_id = $game_id");
    $row_max = mysqli_fetch_assoc($q_max);
    $resume_position = ($row_max && $row_max['max_pos']) ? $row_max['max_pos'] + 1 : 1;
}
?>















<!DOCTYPE html>



<html lang="it">



<head>



    <meta charset="UTF-8">



    <title>Risultato · Materie</title>



    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">



    <meta name="mobile-web-app-capable" content="yes">

    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">



    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">







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



           DESIGN SYSTEM — stesso linguaggio di game_fc (iOS, light+dark)



           ================================================================ */



        :root{



            color-scheme: light dark;



            --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;



            --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;



            --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);



            --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;



            --blue-soft:rgba(0,122,255,.09);



            --g-fill:#E7F8ED; --g-ink:#1E8A44; --g-line:rgba(52,199,89,.85); --g-sh:rgba(52,199,89,.34);



            --r-fill:#FDECEC; --r-ink:#D70015; --r-line:rgba(255,59,48,.8);  --r-sh:rgba(255,59,48,.3);



            --w-fill:#FFF3E0; --w-ink:#C77700; --w-line:rgba(255,149,0,.7);  --w-sh:rgba(255,149,0,.28);



            --chip-bg:#FFFFFF;



            --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);



            --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);



            --pill-hl:inset 0 0 0 .5px var(--hairline);



            --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);



            --header-glass:rgba(249,249,251,.82);



            --spring:cubic-bezier(.32,.72,0,1);



            --safe-top:env(safe-area-inset-top, 0px);



            --safe-bottom:env(safe-area-inset-bottom, 0px);



        }



        @media (prefers-color-scheme: dark){



            :root{



                --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;



                --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;



                --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);



                --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;



                --blue-soft:rgba(10,132,255,.16);



                --g-fill:rgba(48,209,88,.15); --g-ink:#4CE07E; --g-line:rgba(48,209,88,.75); --g-sh:rgba(48,209,88,.5);



                --r-fill:rgba(255,69,58,.16); --r-ink:#FF6F68; --r-line:rgba(255,69,58,.75); --r-sh:rgba(255,69,58,.45);



                --w-fill:rgba(255,159,10,.16); --w-ink:#FFB340; --w-line:rgba(255,159,10,.65); --w-sh:rgba(255,159,10,.4);



                --chip-bg:#3A3A3C;



                --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);



                --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);



                --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);



                --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);



                --header-glass:rgba(16,16,18,.78);



            }



        }







        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }



        html{ -webkit-text-size-adjust:100%; }



        body{



            margin:0; background:var(--bg); color:var(--text);



            font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, sans-serif;



            font-size:16px; line-height:1.45; -webkit-font-smoothing:antialiased;



        }







        /* ---------------- Header vetro ---------------- */



        .app-header{



            position:sticky; top:0; z-index:400;



            padding:calc(var(--safe-top) + 12px) 16px 12px; text-align:center;



            background:var(--header-glass);



            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);



            border-bottom:.5px solid var(--hairline);



        }



        .hd-eyebrow{ font-size:10.5px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:var(--text-3); }



        h1{ margin:2px 0 0; font-size:21px; font-weight:800; letter-spacing:-.02em; color:var(--text); }







        .page{ max-width:700px; margin:0 auto; padding:16px 14px calc(120px + var(--safe-bottom)); }







        /* ---------------- Hero punteggio ---------------- */



        .hero-card{



            position:relative; overflow:hidden;



            background:var(--surface); border-radius:26px; border:.5px solid var(--hairline);



            box-shadow:var(--shadow-card); padding:26px 16px 16px; text-align:center;



        }



        .hero-card::before{ content:""; position:absolute; inset:0; pointer-events:none; }



        .hero-card.s-high::before{ background:radial-gradient(70% 45% at 50% 0%, rgba(48,209,88,.13), transparent 70%); }



        .hero-card.s-mid::before { background:radial-gradient(70% 45% at 50% 0%, rgba(255,159,10,.13), transparent 70%); }



        .hero-card.s-low::before { background:radial-gradient(70% 45% at 50% 0%, rgba(255,69,58,.12), transparent 70%); }







        .ring-wrap{ position:relative; width:176px; height:176px; margin:0 auto 8px; }



        .s-high .ring-wrap, .ring-wrap.s-high{ color:var(--green); }



        .s-mid  .ring-wrap, .ring-wrap.s-mid { color:var(--orange); }



        .s-low  .ring-wrap, .ring-wrap.s-low { color:var(--red); }



        .ring-wrap svg{ width:100%; height:100%; transform:rotate(-90deg); display:block; }



        .ring-bg{ fill:none; stroke:var(--hairline); stroke-width:11; }



        .ring-fg{ fill:none; stroke-width:11; stroke-linecap:round; }



        .s-high .ring-fg{ filter:drop-shadow(0 0 9px rgba(48,209,88,.45)); }



        .s-mid  .ring-fg{ filter:drop-shadow(0 0 9px rgba(255,159,10,.4)); }



        .s-low  .ring-fg{ filter:drop-shadow(0 0 9px rgba(255,69,58,.4)); }



        .ring-center{



            position:absolute; inset:0; display:flex; flex-direction:column;



            align-items:center; justify-content:center; gap:2px;



        }



        .score-line{ display:flex; align-items:baseline; gap:2px; color:currentColor; font-variant-numeric:tabular-nums; }



        .score-line #scoreNum{ font-size:42px; font-weight:800; letter-spacing:-.03em; line-height:1; }



        .score-line .pct{ font-size:19px; font-weight:800; }



        .score-eyebrow{ font-size:10px; font-weight:800; letter-spacing:.16em; text-transform:uppercase; color:var(--text-2); }







        /* utente */



        .user-line{



            display:inline-flex; align-items:center; gap:7px; margin-top:4px;



            font-size:15px; font-weight:700; color:var(--text);



        }



        .user-line .u-ic{ font-size:14px; }



        .edit-icon{ cursor:pointer; font-size:13px; opacity:.55; transition:opacity .2s; }



        .edit-icon:hover{ opacity:1; }



        .edit-input{



            flex:1; min-width:0; padding:11px 34px 11px 13px; font-family:inherit; font-size:15px; font-weight:600;



            color:var(--text); background-color:var(--surface-2);



            border:.5px solid var(--hairline-2); border-radius:12px;



            -webkit-appearance:none; appearance:none;



            background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");



            background-repeat:no-repeat; background-position:right 11px center;



        }



        .save-btn{



            border:none; border-radius:12px; padding:11px 16px; cursor:pointer;



            font-family:inherit; font-size:14px; font-weight:800; color:var(--g-ink);



            background:var(--g-fill);



            box-shadow:inset 0 0 0 1.5px var(--g-line), 0 5px 14px -5px var(--g-sh);



            transition:transform .15s var(--spring);



        }



        .cancel-btn{



            border:none; border-radius:12px; padding:11px 14px; cursor:pointer;



            font-family:inherit; font-size:14px; font-weight:700; color:var(--text-2);



            background:var(--chip-bg); box-shadow:var(--pill-hl), var(--raise-sm);



            transition:transform .15s var(--spring);



        }



        .save-btn:active, .cancel-btn:active{ transform:scale(.94); }







        /* statistiche */



        .stats-grid{ display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; margin-top:18px; }



        .stats-grid .stat.tm{ grid-column:1 / -1; }



        @media (min-width:560px){



            .stats-grid{ grid-template-columns:repeat(5, 1fr); }



            .stats-grid .stat.tm{ grid-column:auto; }



        }



        .stat{



            background:var(--surface-2); border-radius:16px; padding:13px 8px 11px;



            box-shadow:inset 0 0 0 .5px var(--hairline);



        }



        .stat-num{ font-size:24px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; line-height:1.1; color:var(--text); }



        .stat-num span{ font-size:14px; font-weight:700; color:var(--text-3); }



        .stat-lab{ margin-top:3px; font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:var(--text-2); }



        .stat.ok .stat-num{ color:var(--g-ink); }



        .stat.ko .stat-num{ color:var(--r-ink); }



        .stat.pg .stat-num{ color:var(--blue); }



        .stat.tm .stat-num{ color:var(--purple); font-size:20px; }







        /* ---------------- Revisione carte ---------------- */



        .sec-title{



            display:flex; align-items:center; gap:9px; margin:26px 4px 12px;



            font-size:11.5px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--text-2);



        }



        .sec-title::after{ content:""; flex:1; height:.5px; background:var(--hairline); }



        .sec-count{



            background:var(--surface-2); color:var(--text-2);



            padding:3px 10px; border-radius:99px; font-size:11px; font-weight:800; letter-spacing:0;



            box-shadow:inset 0 0 0 .5px var(--hairline);



        }







        .rev-card{



            background:var(--surface); border-radius:22px; border:.5px solid var(--hairline);



            box-shadow:var(--shadow-card); padding:15px 16px 12px; margin-bottom:14px;



        }



        .rev-head{



            display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:2px;



        }



        .rev-head-l{



            display:flex; align-items:center; gap:7px;



            font-size:10.5px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--text-3);



        }



        .rev-head-l::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }



        .rev-jump{



            display:inline-flex; align-items:center; gap:3px; text-decoration:none;



            font-size:12px; font-weight:800; color:var(--blue);



            padding:6px 11px; border-radius:99px; background:var(--blue-soft);



            transition:transform .15s var(--spring);



        }



        .rev-jump:active{ transform:scale(.92); }



        .rev-jump svg{ width:12px; height:12px; }







        .rev-entry{ padding:11px 0 6px; }



        .rev-entry + .rev-entry{ border-top:1px dashed var(--hairline-2); margin-top:6px; }



        .rev-top{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }



        .numb{



            display:inline-flex; align-items:center; justify-content:center; gap:3px;



            min-width:26px; height:24px; padding:0 8px; border-radius:9px;



            font-size:11.5px; font-weight:800; font-variant-numeric:tabular-nums;



        }



        .numb.padre{ background:var(--blue-soft); color:var(--blue); }



        .numb.figlio{ background:rgba(255,149,0,.13); color:var(--orange); }







        .oc-pill{



            display:inline-flex; align-items:center; gap:6px;



            padding:6px 12px; border-radius:99px;



            font-size:12.5px; font-weight:800; letter-spacing:.01em;



        }



        .oc-pill .gi{ font-size:13px; font-weight:800; line-height:1; }



        .oc-pill.ok{ color:var(--g-ink); background:var(--g-fill); box-shadow:inset 0 0 0 1.5px var(--g-line), 0 4px 10px -4px var(--g-sh); }



        .oc-pill.ko{ color:var(--r-ink); background:var(--r-fill); box-shadow:inset 0 0 0 1.5px var(--r-line), 0 4px 10px -4px var(--r-sh); }



        .oc-pill.wait{ color:var(--w-ink); background:var(--w-fill); box-shadow:inset 0 0 0 1.5px var(--w-line), 0 4px 10px -4px var(--w-sh); }







        .fc-img{ max-height:130px; max-width:100%; border-radius:12px; display:block; margin:0 0 8px; border:.5px solid var(--hairline); }



        .raw-text{



            font-size:15.5px; font-weight:600; line-height:1.5; letter-spacing:-.01em;



            color:var(--text); margin:0; text-align:left;



            white-space:pre-wrap; word-wrap:break-word;



        }



        .raw-text mjx-container{ max-width:100%; overflow-x:auto; overflow-y:hidden; }



        .scroll-text{ max-height:160px; overflow-y:auto; padding-right:4px; }



        .scroll-text::-webkit-scrollbar{ width:4px; }



        .scroll-text::-webkit-scrollbar-thumb{ background:var(--hairline-2); border-radius:4px; }







        .rev-a{



            margin-top:10px; padding:11px 12px 10px;



            background:var(--blue-soft); border-radius:14px;



        }



        .rev-a-label{



            display:flex; align-items:center; gap:7px; margin-bottom:6px;



            font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; color:var(--blue);



        }



        .rev-a-label::after{ content:""; flex:1; height:.5px; background:var(--hairline); }



        .rev-a .raw-text{ font-size:14.5px; font-weight:450; }







        .empty-card{



            background:var(--surface); border-radius:22px; border:.5px solid var(--hairline);



            box-shadow:var(--shadow-card); padding:34px 20px; text-align:center; color:var(--text-2);



            font-size:15px; font-weight:600;



        }







        /* ---------------- Barra inferiore fissa ---------------- */



        .bottom-bar{



            position:fixed; left:0; right:0; bottom:0; z-index:500;



            padding:12px 16px calc(12px + var(--safe-bottom));



            background:var(--header-glass);



            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);



            border-top:.5px solid var(--hairline);



        }



        .btn-continue{



            display:flex; align-items:center; justify-content:center; gap:8px;



            width:100%; max-width:668px; margin:0 auto; padding:16px;



            border-radius:16px; text-decoration:none; cursor:pointer;



            font-size:17px; font-weight:800; letter-spacing:-.01em; color:#fff;



            background:var(--blue);



            background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));



            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px rgba(10,132,255,.55);



            transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s;



        }



        .btn-continue svg{ width:17px; height:17px; }



        .btn-continue:active{



            transform:translateY(1.5px) scale(.985); filter:brightness(.96);



            box-shadow:inset 0 1px 0 rgba(255,255,255,.1), inset 0 3px 8px rgba(0,0,0,.18), 0 4px 10px -5px rgba(10,132,255,.5);



        }



        .btn-continue.is-done{



            pointer-events:none; color:var(--text-3); background:var(--surface-2);



            box-shadow:inset 0 0 0 .5px var(--hairline);



        }







        .sec-sub{ margin:-6px 4px 12px; font-size:12px; font-weight:600; color:var(--text-3); }







        /* ---------------- Tiles azione (pulsanti rivoluzionati) ---------------- */



        .act-grid{ display:grid; gap:10px; margin-top:18px; }



        .act-grid.cols-3{ grid-template-columns:repeat(3, 1fr); }



        .act-grid.cols-2{ grid-template-columns:repeat(2, 1fr); }



        .act-tile{



            position:relative; display:flex; flex-direction:column; align-items:center; gap:8px;



            padding:15px 6px 12px; border:none; border-radius:18px; cursor:pointer; text-decoration:none;



            font-family:inherit; background:var(--chip-bg);



            box-shadow:var(--pill-hl), var(--raise-sm);



            transition:transform .18s var(--spring), box-shadow .2s var(--spring);



        }



        .act-tile:active{ transform:scale(.93); box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.09); }



        .t-ic{



            width:42px; height:42px; border-radius:14px;



            display:flex; align-items:center; justify-content:center;



        }



        .t-ic svg{ width:21px; height:21px; }



        .t-lab{ font-size:12.5px; font-weight:800; letter-spacing:-.01em; color:var(--text); }



        .t-purple .t-ic{ color:var(--purple); background:rgba(175,82,222,.13); box-shadow:inset 0 0 0 1.5px rgba(175,82,222,.5), 0 4px 10px -4px rgba(175,82,222,.45); }



        .t-red .t-ic{ color:var(--r-ink); background:var(--r-fill); box-shadow:inset 0 0 0 1.5px var(--r-line), 0 4px 10px -4px var(--r-sh); }



        .t-blue .t-ic{ color:var(--blue); background:var(--blue-soft); box-shadow:inset 0 0 0 1.5px rgba(0,122,255,.45), 0 4px 10px -4px rgba(10,132,255,.4); }



        .t-badge{



            position:absolute; top:7px; right:9px; min-width:20px; height:20px; padding:0 6px;



            border-radius:99px; display:flex; align-items:center; justify-content:center;



            font-size:11px; font-weight:800; color:#fff;



            background:linear-gradient(180deg, #FF6B60, #FF3B30);



            box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 3px 8px -2px rgba(255,59,48,.6);



        }







        /* Le carte della revisione invitano al tocco */



        .rev-entry{ cursor:pointer; transition:transform .15s var(--spring); }



        .rev-entry:active{ transform:scale(.988); }







        /* ---------------- CTA "Continua" viva: respiro + riflesso + spinta ---------------- */



        .btn-continue{ position:relative; overflow:hidden; }



        .btn-continue:not(.is-done){ animation:ctaBreathe 3.4s ease-in-out 1.4s infinite; }



        .btn-continue:not(.is-done)::after{



            content:""; position:absolute; inset:-2px; pointer-events:none;



            background:linear-gradient(115deg, transparent 32%, rgba(255,255,255,.35) 48%, transparent 62%);



            transform:translateX(-160%);



            animation:ctaSheen 3.4s ease-in-out 2s infinite;



        }



        .btn-continue:not(.is-done) svg{ animation:ctaNudge 3.4s ease-in-out infinite; }



        @keyframes ctaBreathe{



            0%, 100%{ box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px rgba(10,132,255,.55); }



            50%{ box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 17px 36px -8px rgba(10,132,255,.85); }



        }



        @keyframes ctaSheen{ 0%, 58%{ transform:translateX(-160%); } 84%, 100%{ transform:translateX(160%); } }



        @keyframes ctaNudge{ 0%, 60%, 100%{ transform:translateX(0); } 72%{ transform:translateX(4px); } 84%{ transform:translateX(0); } }







        /* ================================================================



           VISORE FLASHCARD — flip 3D, swipe, trascina giù per chiudere



           ================================================================ */



        body.fcv-lock{ overflow:hidden; }



        .fcv{



            position:fixed; inset:0; z-index:3000;



            display:flex; flex-direction:column;



            visibility:hidden; pointer-events:none;



            transition:visibility 0s .36s;



        }



        .fcv.on{ visibility:visible; pointer-events:auto; transition:visibility 0s 0s; }



        .fcv-back{



            position:absolute; inset:0;



            background:rgba(8,8,10,.62);



            -webkit-backdrop-filter:blur(16px) saturate(1.3); backdrop-filter:blur(16px) saturate(1.3);



            opacity:0; transition:opacity .32s ease;



        }



        .fcv.on .fcv-back{ opacity:1; }



        .fcv-top, .fcv-progress, .fcv-nav{ opacity:0; transition:opacity .3s ease .06s; }



        .fcv.on .fcv-top, .fcv.on .fcv-progress, .fcv.on .fcv-nav{ opacity:1; }







        .fcv-top{



            position:relative; display:flex; align-items:center; justify-content:space-between; gap:10px;



            padding:calc(var(--safe-top) + 12px) 16px 10px;



        }



        #fcvClose{



            width:36px; height:36px; border:none; border-radius:50%; cursor:pointer; padding:0;



            background:rgba(255,255,255,.16); color:#fff; font-size:14px; font-weight:700;



            display:flex; align-items:center; justify-content:center;



            -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);



        }



        .fcv-count{ color:#fff; font-size:15px; font-weight:800; font-variant-numeric:tabular-nums; letter-spacing:.02em; text-shadow:0 1px 8px rgba(0,0,0,.4); }



        .fcv-progress{ position:relative; height:3px; margin:0 16px; border-radius:99px; background:rgba(255,255,255,.2); overflow:hidden; }



        #fcvBar{ position:absolute; left:0; top:0; bottom:0; width:0; display:block; border-radius:99px; background:#fff; transition:width .35s var(--spring); }







        .fcv-stage{



            position:relative; flex:1; min-height:0;



            display:flex; align-items:center; justify-content:center;



            padding:16px; perspective:1500px;



        }



        .fcv-card{ position:relative; width:100%; max-width:540px; height:100%; max-height:min(64vh, 560px); }



        .fcv-inner{



            position:absolute; inset:0; transform-style:preserve-3d;



            transition:transform .62s cubic-bezier(.34,1.22,.4,1);



        }



        .fcv-inner.flipped{ transform:rotateY(180deg); }



        .fcv-face{



            position:absolute; inset:0; overflow:hidden;



            backface-visibility:hidden; -webkit-backface-visibility:hidden;



            background:var(--surface); border-radius:26px; border:.5px solid var(--hairline);



            box-shadow:0 26px 70px -18px rgba(0,0,0,.55);



            padding:18px 18px 14px; display:flex; flex-direction:column;



        }



        .fcv-backface{



            transform:rotateY(180deg);



            background:var(--surface);



            background:color-mix(in srgb, var(--surface), var(--blue) 6%);



        }



        .fcv-eyebrow{



            display:flex; align-items:center; gap:8px; margin-bottom:10px; flex-shrink:0;



            font-size:11px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; color:var(--text-2);



        }



        .fcv-eyebrow::after{ content:""; flex:1; height:.5px; background:var(--hairline); }



        .fcv-eyebrow.ans{ color:var(--blue); }



        .fcv-body{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 6px; }



        .fcv-body .scroll-text{ max-height:none; overflow:visible; padding:0; }



        .fcv-body .fc-img{ max-height:34vh; margin:8px auto; border-radius:14px; }



        .fcv-front .raw-text{ font-size:19px; font-weight:650; }



        .fcv-backface .raw-text{ font-size:16.5px; font-weight:480; }



        .fcv-hint{



            flex-shrink:0; margin-top:8px; text-align:center;



            font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:var(--text-3);



            animation:hintPulse 2.4s ease-in-out infinite;



        }



        @keyframes hintPulse{ 0%, 100%{ opacity:.5; } 50%{ opacity:1; } }







        .fcv-nav{



            position:relative; display:flex; align-items:center; justify-content:center; gap:26px;



            padding:12px 16px calc(16px + var(--safe-bottom));



        }



        .fcv-nb{



            width:52px; height:52px; border:none; border-radius:50%; cursor:pointer; padding:0;



            background:rgba(255,255,255,.15); color:#fff;



            display:flex; align-items:center; justify-content:center;



            -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);



            box-shadow:inset 0 0 0 .5px rgba(255,255,255,.25);



            transition:transform .15s var(--spring), opacity .2s;



        }



        .fcv-nb:active{ transform:scale(.86); }



        .fcv-nb svg{ width:22px; height:22px; }



        .fcv-nb.off{ opacity:.3; pointer-events:none; }



        #fcvFlip{



            width:66px; height:66px;



            background:var(--purple);



            background:linear-gradient(180deg, color-mix(in srgb, var(--purple), #fff 18%), var(--purple) 55%, color-mix(in srgb, var(--purple), #000 12%));



            box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 12px 28px -6px rgba(175,82,222,.65);



        }



        #fcvFlip svg{ width:26px; height:26px; }







        @media (prefers-reduced-motion: reduce){



            *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }



        }



    </style>



    <script src="fc_parser.js"></script>



</head>



<body>



    <?php include 'sidebar.html'; ?>







    <header class="app-header">



        <div class="hd-eyebrow">Materie · Flashcard</div>



        <h1>Risultato</h1>



    </header>







    <main class="page">







        <?php $score_class = ($score >= 80) ? 's-high' : (($score >= 50) ? 's-mid' : 's-low'); ?>







        <!-- ============ HERO PUNTEGGIO ============ -->



        <section class="hero-card <?php echo $score_class; ?>">



            <div class="ring-wrap">



                <svg viewBox="0 0 120 120" aria-hidden="true">



                    <defs>



                        <linearGradient id="grad-s-high" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#66EA96"/><stop offset="1" stop-color="#1FA84C"/></linearGradient>



                        <linearGradient id="grad-s-mid" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFCB60"/><stop offset="1" stop-color="#ED8900"/></linearGradient>



                        <linearGradient id="grad-s-low" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FF8B80"/><stop offset="1" stop-color="#E12F26"/></linearGradient>



                    </defs>



                    <circle class="ring-bg" cx="60" cy="60" r="52"/>



                    <circle class="ring-fg" id="scoreRing" cx="60" cy="60" r="52" stroke="url(#grad-<?php echo $score_class; ?>)"/>



                </svg>



                <div class="ring-center">



                    <div class="score-line"><span id="scoreNum" data-score="<?php echo number_format($score, 2, '.', ''); ?>">0</span><span class="pct">%</span></div>



                    <div class="score-eyebrow">Punteggio</div>



                </div>



            </div>









            <div class="stats-grid">



                <div class="stat ok">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo $correct_count; ?>"><?php echo $correct_count; ?></span></div>



                    <div class="stat-lab">Corrette</div>



                </div>



                <div class="stat ko">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo $error_count; ?>"><?php echo $error_count; ?></span></div>



                    <div class="stat-lab">Errori</div>



                </div>



                <div class="stat">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo $played_items; ?>"><?php echo $played_items; ?></span></div>



                    <div class="stat-lab">Valutate</div>



                </div>



                <div class="stat pg">



                    <div class="stat-num"><span class="cnt" data-val="<?php echo $played_positions_count; ?>"><?php echo $played_positions_count; ?></span><span> / <?php echo $total_pages; ?></span></div>



                    <div class="stat-lab">Pagine</div>



                </div>



                <div class="stat tm">



                    <div class="stat-num"><?php echo htmlspecialchars($durata_display); ?></div>



                    <div class="stat-lab">Durata</div>



                </div>



            </div>







            <div class="act-grid <?php echo ($error_count > 0) ? 'cols-3' : 'cols-2'; ?>">



                <button type="button" class="act-tile t-purple" onclick="fcvOpen(0)">



                    <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="14" height="13" rx="3"/><path d="M8.5 4H19a2 2 0 0 1 2 2v10.5"/></svg></span>



                    <span class="t-lab">Flashcard</span>



                </button>



                <?php if ($error_count > 0): ?>



                    <a href="start_retry.php?parent_id=<?php echo $game_id; ?>" class="act-tile t-red">



                        <span class="t-badge"><?php echo $error_count; ?></span>



                        <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 2.8-6.5"/><path d="M3 4v5h5"/></svg></span>



                        <span class="t-lab">Rigioca errori</span>



                    </a>



                <?php endif; ?>



                <a href="history_fc.php" class="act-tile t-blue">



                    <span class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>



                    <span class="t-lab">Storico</span>



                </a>



            </div>



        </section>







        <!-- ============ REVISIONE CARTE ============ -->



        <div class="sec-title">Revisione carte <span class="sec-count"><?php echo count($cards); ?></span></div>



        <div class="sec-sub">Tocca una carta per sfogliarla a schermo intero</div>







        <?php if (count($cards) === 0): ?>



            <div class="empty-card">Nessuna carta giocata in questa partita.</div>



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
                    $oc_class = "ok"; $oc_icon = "&#10003;"; $oc_text = "Corretto";
                } elseif ($c['user_answer'] == 'Wrong') {
                    $oc_class = "ko"; $oc_icon = "&#10005;"; $oc_text = "Errato";
                } else {
                    $oc_class = "wait"; $oc_icon = "&#9203;"; $oc_text = "Da fare";
                }
            ?>



                <?php if ($is_new_group): ?>



                <div class="rev-card">



                    <div class="rev-head">



                        <span class="rev-head-l">Pagina <?php echo $current_pos; ?></span>



                        <a class="rev-jump" href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $current_pos; ?>&status=<?php echo $is_learnt; ?>">Rivedi <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>



                    </div>



                <?php endif; ?>







                    <div class="rev-entry">



                        <div class="rev-top">



                            <?php if ($c['is_child']): ?>



                                <span class="numb figlio"><?php echo $child_counter; ?> &#8627;</span>



                            <?php else: ?>



                                <span class="numb padre">1</span>



                            <?php endif; ?>



                            <span class="oc-pill <?php echo $oc_class; ?>"><span class="gi"><?php echo $oc_icon; ?></span> <?php echo $oc_text; ?></span>



                        </div>







                        <div class="rev-q">



                            <?php if(!empty($c['question_image'])): ?>



                                <img src="fc_Images/<?php echo $c['question_image']; ?>" class="fc-img">



                            <?php endif; ?>



                            <div class="scroll-text">



                                <span class="raw-text" data-raw="<?php echo htmlspecialchars($c['question_desc'] ?? '', ENT_QUOTES); ?>"></span>



                            </div>



                        </div>







                        <div class="rev-a">



                            <div class="rev-a-label">Risposta</div>



                            <div class="rev-a-body">



                                <?php if(!empty($c['answer_image'])): ?>



                                    <img src="fc_Images/<?php echo $c['answer_image']; ?>" class="fc-img">



                                <?php endif; ?>



                                <div class="scroll-text">



                                    <span class="raw-text" data-raw="<?php echo htmlspecialchars($c['answer_desc'] ?? '', ENT_QUOTES); ?>"></span>



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



            <a href="game_fc.php?game_id=<?php echo $game_id; ?>&position=<?php echo $resume_position; ?>&status=<?php echo $is_learnt; ?>&newsession=1" class="btn-continue">Continua partita <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>



        <?php else: ?>



            <a href="#" class="btn-continue is-done">🏁 Categoria finita</a>



        <?php endif; ?>



    </div>







    <!-- ============ VISORE FLASHCARD ============ -->



    <div id="fcv" class="fcv" aria-hidden="true">



        <div class="fcv-back"></div>



        <header class="fcv-top">



            <button id="fcvClose" type="button" aria-label="Chiudi">&#10005;</button>



            <div class="fcv-count"><span id="fcvIdx">1</span> / <span id="fcvTot">1</span></div>



            <span id="fcvPill" class="oc-pill ok"></span>



        </header>



        <div class="fcv-progress"><span id="fcvBar"></span></div>



        <div class="fcv-stage" id="fcvStage">



            <div class="fcv-card" id="fcvCard">



                <div class="fcv-inner" id="fcvInner">



                    <div class="fcv-face fcv-front">



                        <div class="fcv-eyebrow">Domanda</div>



                        <div class="fcv-body" id="fcvQ"></div>



                        <div class="fcv-hint">Tocca per girare</div>



                    </div>



                    <div class="fcv-face fcv-backface">



                        <div class="fcv-eyebrow ans">Risposta</div>



                        <div class="fcv-body" id="fcvA"></div>



                    </div>



                </div>



            </div>



        </div>



        <div class="fcv-nav">



            <button id="fcvPrev" class="fcv-nb" type="button" aria-label="Precedente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>



            <button id="fcvFlip" class="fcv-nb" type="button" aria-label="Gira la carta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.3"/><path d="M21 3v5h-5"/></svg></button>



            <button id="fcvNext" class="fcv-nb" type="button" aria-label="Successiva"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>



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

