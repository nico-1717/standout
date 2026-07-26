<?php

// connessione al database
require_once 'config.php';
require_once 'auth_check.php'; // 1. Protezione con login obbligatorio

$current_user_id = $utente_corrente['id']; // 2. ID utente sicuro dal server

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }







// --- RICEVITORE AJAX PER L'INSERIMENTO MULTIPLO ---



if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert_bulk') {



    header('Content-Type: application/json');







    // Assicuriamoci che le cartelle esistano



    if (!is_dir(FC_IMAGE_PATH_INSERT)) { mkdir(FC_IMAGE_PATH_INSERT, 0777, true); }



    if (!is_dir('fc_audio')) { mkdir('fc_audio', 0777, true); }







    $categoryId = intval($_POST['category_id']);

    // --- INIZIO SICUREZZA: Verifica proprietario categoria ---
    $check_cat = mysqli_query($conn, "SELECT user_id FROM fc_categories WHERE id = $categoryId");
    $row_cat = mysqli_fetch_assoc($check_cat);
    if (!$row_cat || (int)$row_cat['user_id'] !== $current_user_id) {
        echo json_encode(['success' => false, 'error' => 'Sicurezza: Non sei autorizzato ad aggiungere carte a questa categoria.']);
        exit;
    }
    // --- FINE SICUREZZA ---

    // Trova ordine
    $max_q = mysqli_query($conn, "SELECT MAX(custom_order) as m FROM fc_images WHERE category_id = $categoryId");
    $max_o = intval(mysqli_fetch_assoc($max_q)['m']) + 1;







    $rows_count = isset($_POST['q_desc']) ? count($_POST['q_desc']) : 0;



    $inserted_count = 0;







    $last_parent_id = 0;



    $child_order = 1;







    for ($i = 0; $i < $rows_count; $i++) {



        $qd = mysqli_real_escape_string($conn, trim($_POST['q_desc'][$i] ?? ''));



        $ad = mysqli_real_escape_string($conn, trim($_POST['a_desc'][$i] ?? ''));







        $q_img_name = $_POST['q_img_name'][$i] ?? '';



        $a_img_name = $_POST['a_img_name'][$i] ?? '';







        $is_child = isset($_POST['is_child'][$i]) ? intval($_POST['is_child'][$i]) : 0;







        // Se la riga è completamente vuota, la saltiamo



        if ($qd == '' && $ad == '' && empty($q_img_name) && empty($a_img_name)) {



            continue;



        }







        // --- GESTIONE IMMAGINE DOMANDA ---



        $final_q_img = "";



        if (isset($_FILES['row_q_img']['name'][$i]) && $_FILES['row_q_img']['error'][$i] == 0) {



            $ext = pathinfo($_FILES['row_q_img']['name'][$i], PATHINFO_EXTENSION);



            $final_q_img = time() . "_q_row_$i." . $ext;



            move_uploaded_file($_FILES['row_q_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_q_img);



        } else if (!empty($q_img_name) && isset($_FILES['question_images'])) {



            foreach ($_FILES['question_images']['name'] as $idx => $fname) {



                if ($fname == $q_img_name) {



                    $ext = pathinfo($fname, PATHINFO_EXTENSION);



                    $final_q_img = time() . "_q_bulk_$i." . $ext;



                    move_uploaded_file($_FILES['question_images']['tmp_name'][$idx], FC_IMAGE_PATH_INSERT . $final_q_img);



                    break;



                }



            }



        }







        // --- GESTIONE IMMAGINE RISPOSTA ---



        $final_a_img = "";



        if (isset($_FILES['row_a_img']['name'][$i]) && $_FILES['row_a_img']['error'][$i] == 0) {



            $ext = pathinfo($_FILES['row_a_img']['name'][$i], PATHINFO_EXTENSION);



            $final_a_img = time() . "_a_row_$i." . $ext;



            move_uploaded_file($_FILES['row_a_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_a_img);



        } else if (!empty($a_img_name) && isset($_FILES['answer_images'])) {



            foreach ($_FILES['answer_images']['name'] as $idx => $fname) {



                if ($fname == $a_img_name) {



                    $ext = pathinfo($fname, PATHINFO_EXTENSION);



                    $final_a_img = time() . "_a_bulk_$i." . $ext;



                    move_uploaded_file($_FILES['answer_images']['tmp_name'][$idx], FC_IMAGE_PATH_INSERT . $final_a_img);



                    break;



                }



            }



        }







        // Se la riga è completamente vuota, la saltiamo



        if ($qd == '' && $ad == '' && empty($final_q_img) && empty($final_a_img)) {



            continue;



        }







        // --- GESTIONE AUDIO DOMANDA ---



        $final_q_aud = "";



        if (isset($_FILES['audio_q']['name'][$i]) && $_FILES['audio_q']['error'][$i] == 0) {



            $ext = pathinfo($_FILES['audio_q']['name'][$i], PATHINFO_EXTENSION);



            $final_q_aud = "audio_q_" . time() . "_$i." . $ext;



            move_uploaded_file($_FILES['audio_q']['tmp_name'][$i], 'fc_audio/' . $final_q_aud);



        }







        // --- GESTIONE AUDIO RISPOSTA ---



        $final_a_aud = "";



        if (isset($_FILES['audio_a']['name'][$i]) && $_FILES['audio_a']['error'][$i] == 0) {



            $ext = pathinfo($_FILES['audio_a']['name'][$i], PATHINFO_EXTENSION);



            $final_a_aud = "audio_a_" . time() . "_$i." . $ext;



            move_uploaded_file($_FILES['audio_a']['tmp_name'][$i], 'fc_audio/' . $final_a_aud);



        }







        // --- INSERIMENTO NEL DB ---

        if ($is_child == 0) {

            // Rimossa la colonna 'is_father' non esistente in fc_images
            mysqli_query($conn, "INSERT INTO fc_images (category_id, question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, custom_order)

                                 VALUES ($categoryId, '$qd', '$final_q_img', '$final_q_aud', '$ad', '$final_a_img', '$final_a_aud', $max_o)");

            $last_parent_id = mysqli_insert_id($conn);

            $max_o++;

            $child_order = 1;

            $inserted_count++;

        } else {

            if ($last_parent_id > 0) {

                mysqli_query($conn, "INSERT INTO fc_child_cards (parent_id, question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, custom_order)

                                     VALUES ($last_parent_id, '$qd', '$final_q_img', '$final_q_aud', '$ad', '$final_a_img', '$final_a_aud', $child_order)");

                $child_order++;

                // Corretto l'identificatore della chiave primaria da 'id_fcimg' a 'id'
                mysqli_query($conn, "UPDATE fc_images SET is_father = 1 WHERE id = $last_parent_id");

                $inserted_count++;

            }

        }



    }







    echo json_encode(['success' => true, 'count' => $inserted_count, 'category_id' => $categoryId]);



    exit;



}







// Query per ottenere le categorie (FILTRATE PER UTENTE LOGGATO)
$queryCategories = "SELECT id, name FROM fc_categories WHERE user_id = $current_user_id ORDER BY name";
$resultCategories = mysqli_query($conn, $queryCategories);

// Categoria pre-selezionata (es. arrivo da inserimento_fc.php con ?category_id=123)
$preselected_cat_id = 0;
$preselected_cat_name = '';
if (isset($_GET['category_id'])) {
    $preselected_cat_id = intval($_GET['category_id']);
    if ($preselected_cat_id > 0) {
        // SICUREZZA AGGIUNTIVA: Pre-seleziona la categoria SOLO se appartiene all'utente loggato
        $pcRes = mysqli_query($conn, "SELECT name FROM fc_categories WHERE id = $preselected_cat_id AND user_id = $current_user_id");
        if ($pcRes && $pcRow = mysqli_fetch_assoc($pcRes)) {
            $preselected_cat_name = $pcRow['name'];
        } else {
            $preselected_cat_id = 0; // id non valido o di un altro utente: ignora in modo sicuro
        }
    }
}



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



    <title>Big Insert · Flashcards Universitarie</title>







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



           (coerente con game_fc.php)



           ================================================================ */



        :root{



            color-scheme: light dark;



            --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EDEDF2;



            --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;



            --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);



            --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --pink:#FF2D55; --gray:#8E8E93;



            --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);



            --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12); --pink-soft:rgba(255,45,85,.12); --gray-soft:rgba(142,142,147,.14);



            --header-glass:rgba(249,249,251,.82);



            --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);



            --chip-bg:#FFFFFF;



            --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);



            --raise:0 1px 2px rgba(0,0,0,.07), 0 10px 22px -6px rgba(0,0,0,.16);



            --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);



            --pill-hl:inset 0 0 0 .5px var(--hairline);



            --r-card:22px; --r-btn:15px; --r-chip:100px; --r-tile:16px;



            --spring:cubic-bezier(.32,.72,0,1);



            --snap:cubic-bezier(.24,1.3,.35,1);



            --header-h:64px;



            --safe-top:env(safe-area-inset-top, 0px);



            --safe-bottom:env(safe-area-inset-bottom, 0px);



        }



        @media (prefers-color-scheme: dark){



            :root{



                --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#333336;



                --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;



                --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);



                --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --pink:#FF375F; --gray:#98989F;



                --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);



                --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18); --pink-soft:rgba(255,55,95,.18); --gray-soft:rgba(152,152,159,.18);



                --header-glass:rgba(16,16,18,.78);



                --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);



                --chip-bg:#3A3A3C;



                --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);



                --raise:0 1px 2px rgba(0,0,0,.5), 0 12px 26px -6px rgba(0,0,0,.65);



                --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);



                --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);



            }



        }







        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }



        html{ -webkit-text-size-adjust:100%; }



        html, body{ min-height:100%; overflow-x:hidden; }



        body{



            margin:0; background:var(--bg); color:var(--text);



            font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;



            font-size:16px; line-height:1.45;



            -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;



            padding-bottom: calc(60px + var(--safe-bottom));



        }



        body.no-select, body.no-select *{ -webkit-user-select:none !important; user-select:none !important; }



        :focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }



        a{ color:var(--blue); }







        /* ================================================================



           HEADER



           ================================================================ */



        .app-header{



            position:sticky; top:0; left:0; right:0; z-index:200;



            padding:calc(var(--safe-top) + 12px) 16px 12px;



            background:var(--header-glass);



            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);



            border-bottom:.5px solid var(--hairline);



            display:flex; align-items:center; justify-content:space-between; gap:12px;



        }



        .app-header .hdr-text{ min-width:0; }



        .app-header h1{



            margin:0; font-size:19px; font-weight:800; letter-spacing:-.02em; color:var(--text);



            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;



        }



        .app-header .hdr-sub{ margin-top:1px; font-size:12px; font-weight:600; color:var(--text-2); }



        .hdr-chip{



            flex-shrink:0; display:inline-flex; align-items:center; gap:6px;



            padding:7px 13px; border-radius:var(--r-chip); font-size:12.5px; font-weight:800;



            background:var(--blue-soft); color:var(--blue); font-variant-numeric:tabular-nums;



        }







        .page-wrap{ max-width:760px; margin:0 auto; padding:16px 14px calc(24px + var(--safe-bottom)); }







        /* ================================================================



           BOTTONI — sistema condiviso (coerente con game_fc)



           ================================================================ */



        .btn{



            display:flex; align-items:center; justify-content:center; gap:8px;



            width:100%; padding:15px 16px; margin:0;



            border:none; border-radius:var(--r-btn); cursor:pointer;



            font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em;



            color:#fff; background:var(--text);



            text-decoration:none; text-align:center;



            -webkit-user-select:none; user-select:none;



            --glow:rgba(0,0,0,.3);



            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px var(--glow);



            transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s, opacity .2s;



        }



        .btn:active{



            transform:translateY(1.5px) scale(.985);



            filter:brightness(.95);



            box-shadow:inset 0 1px 0 rgba(255,255,255,.1), inset 0 3px 8px rgba(0,0,0,.18), 0 1px 2px rgba(0,0,0,.12), 0 4px 10px -5px var(--glow);



        }



        .btn:disabled{ opacity:.55; cursor:not-allowed; transform:none !important; filter:none !important; }



        .btn-primary{



            --glow:rgba(10,132,255,.5);



            background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));



        }



        .btn-success{



            --glow:rgba(52,199,89,.5);



            background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 18%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%));



        }



        .btn-warn{



            --glow:rgba(255,149,0,.5);



            background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 16%), var(--orange) 52%, color-mix(in srgb, var(--orange), #000 10%));



        }



        .btn-flat-warn{ color:var(--gray); background:var(--gray-soft); box-shadow:inset 0 0 0 1.5px var(--hairline-2); --glow:transparent; }



        .btn-ghost{



            color:var(--text); background:var(--surface);



            box-shadow:var(--pill-hl), var(--raise);



        }



        .btn-ghost:active{ filter:none; box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.08); }



        .btn-sm{ padding:11px 14px; font-size:14.5px; }



        .btn svg{ width:18px; height:18px; flex-shrink:0; }







        .spinner{ width:17px; height:17px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; animation:spin .7s linear infinite; }



        @keyframes spin{ to{ transform:rotate(360deg); } }







        /* ================================================================



           CARD / SETUP SECTIONS



           ================================================================ */



        .card-surface{



            background:var(--surface); border-radius:var(--r-card);



            border:.5px solid var(--hairline); box-shadow:var(--shadow-card);



            padding:18px 16px;



        }



        .setup-grid{ display:grid; grid-template-columns:1fr; gap:12px; margin-bottom:14px; }



        @media (min-width:680px){ .setup-grid{ grid-template-columns:1fr 1fr; } .setup-grid .full{ grid-column:1/-1; } }







        .setup-card{ padding:16px; }



        .setup-card .sc-label{



            display:flex; align-items:center; gap:8px;



            font-size:11px; font-weight:800; letter-spacing:.09em; text-transform:uppercase;



            color:var(--blue); margin-bottom:12px;



        }



        .setup-card .sc-label .ic{ width:20px; height:20px; border-radius:7px; background:var(--blue-soft); display:flex; align-items:center; justify-content:center; flex-shrink:0; }



        .setup-card .sc-label .ic svg{ width:12.5px; height:12.5px; color:var(--blue); }



        .mini-label{ font-size:12.5px; font-weight:700; color:var(--text-2); margin:0 0 6px; display:block; }







        input[type="file"]{ font-size:13px; color:var(--text-2); }



        input[type="file"]::file-selector-button{



            border:none; padding:8px 12px; margin-right:8px; border-radius:10px;



            background:var(--blue-soft); color:var(--blue); font-weight:700; font-size:12.5px; cursor:pointer;



        }







        textarea, select, input[type="text"]{



            width:100%; box-sizing:border-box; font-family:inherit; color:var(--text);



            background:var(--surface-2); border:1px solid var(--hairline-2); border-radius:12px;



            padding:11px 12px; font-size:16px; resize:vertical;



        }



        select{ -webkit-appearance:none; appearance:none; padding-right:34px;



            background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");



            background-repeat:no-repeat; background-position:right 11px center;



        }



        textarea:focus, select:focus, input:focus{ outline:none; border-color:var(--blue); }







        .category-btn{



            width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px;



            padding:15px 16px; border-radius:14px; border:1.5px dashed var(--hairline-2);



            background:var(--surface-2); color:var(--text-3); font-weight:700; font-size:15.5px;



            cursor:pointer; font-family:inherit; text-align:left; transition:border-color .2s, background .2s, transform .15s var(--spring);



        }



        .category-btn.is-set{ color:var(--text); border-style:solid; border-color:var(--blue); background:var(--blue-soft); }



        .category-btn:active{ transform:scale(.99); }



        .category-btn .cb-ic{ width:34px; height:34px; border-radius:10px; background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); display:flex; align-items:center; justify-content:center; flex-shrink:0; }



        .category-btn .cb-ic svg{ width:17px; height:17px; color:var(--blue); }



        .category-btn .cb-chev{ color:var(--text-3); flex-shrink:0; }



        .category-btn .cb-chev svg{ width:16px; height:16px; }







        .import-row{ display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; }



        .import-row > div{ flex:1; min-width:130px; }







        .ai-prompt-box{



            background:var(--surface-2); border:1.5px dashed var(--hairline-2); border-radius:14px;



            margin-top:10px; overflow:hidden; position:relative;



        }



        .ai-prompt-box textarea{



            width:100%; height:150px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12.5px; line-height:1.55;



            border:none; background:transparent; resize:vertical; outline:none; cursor:copy; padding:14px;



        }



        .ai-copy-hint{



            position:absolute; top:8px; right:8px; font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase;



            color:var(--purple); background:var(--purple-soft); padding:4px 9px; border-radius:99px; pointer-events:none;



        }



        .format-help{



            background:var(--purple-soft); border-radius:var(--r-card); padding:16px; margin-bottom:14px;



            border:.5px solid transparent;



        }



        .format-help strong{ color:var(--purple); font-size:14.5px; }



        .format-help p{ margin:6px 0 0; font-size:13px; color:var(--text-2); line-height:1.5; }







        #success-msg{



            display:none; background:var(--green-soft); color:var(--green); padding:22px 18px; text-align:center;



            border-radius:var(--r-card); margin:0 0 16px; font-size:16px; font-weight:700; line-height:1.5;



            animation:popIn .4s var(--spring) both;



        }



        #success-msg small{ display:block; margin-top:6px; font-weight:600; color:var(--text-2); font-size:13px; }



        @keyframes popIn{ from{ opacity:0; transform:translateY(-8px) scale(.97); } }







        /* ================================================================



           BANNER SELEZIONE PADRE



           ================================================================ */



        #parent-selection-banner{



            position:fixed; top:0; left:0; right:0; z-index:9000;



            display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; text-align:center;



            padding:calc(var(--safe-top) + 12px) 16px 12px;



            background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 10%), var(--orange));



            color:#fff; font-weight:700; font-size:14.5px;



            box-shadow:0 6px 24px rgba(255,149,0,.35);



            transform:translateY(-100%); transition:transform .4s var(--snap);



        }



        #parent-selection-banner.show{ transform:translateY(0); }



        #parent-selection-banner button{



            padding:8px 16px; font-weight:800; font-size:13px; border:none; border-radius:99px; cursor:pointer;



            background:rgba(255,255,255,.22); color:#fff; backdrop-filter:blur(4px);



        }



        body.selecting-mode{ padding-top:0; }



        body.selecting-mode .fc-row{ transition:box-shadow .2s, opacity .2s; }



        body.selecting-mode .fc-row.eligible-target .fc-row-content{ box-shadow:0 0 0 2px var(--orange), var(--shadow-card); }



        body.selecting-mode .fc-row.is-child:not(.pending-child) .fc-row-content{ opacity:.45; }



        body.selecting-mode .fc-row.pending-child .fc-row-content{ box-shadow:0 0 0 2px var(--blue), var(--shadow-card); }



        /* durante la selezione del padre, i figli non devono poter essere eliminati o sganciati */



        body.selecting-mode .fc-row.is-child .swipe-btn-delete,



        body.selecting-mode .fc-row.is-child .swipe-btn-unjoin{ visibility:hidden; }







        /* ================================================================



           TOAST



           ================================================================ */



        .app-toast{



            position:fixed; left:50%; bottom:calc(24px + var(--safe-bottom)); z-index:11000;



            transform:translateX(-50%) translateY(20px); opacity:0;



            max-width:88%; padding:13px 20px; border-radius:16px;



            background:var(--text); color:var(--bg); font-size:14px; font-weight:600; text-align:center;



            box-shadow:0 14px 40px rgba(0,0,0,.3); transition:opacity .3s var(--spring), transform .3s var(--spring);



        }



        .app-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }



        .app-toast.toast-error{ background:var(--red); color:#fff; }







        /* ================================================================



           MODALE CONFERMA



           ================================================================ */



        .confirm-overlay{



            position:fixed; inset:0; z-index:10500; display:none;



            align-items:flex-end; justify-content:center;



            background:rgba(0,0,0,.4); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);



        }



        .confirm-overlay.show{ display:flex; }



        .confirm-panel{



            width:100%; max-width:440px; background:var(--surface); color:var(--text);



            border-radius:24px 24px 0 0; padding:26px 20px calc(20px + var(--safe-bottom));



            box-shadow:0 -10px 50px rgba(0,0,0,.3); animation:sheetUp .4s var(--snap) both; position:relative;



        }



        @keyframes sheetUp{ from{ transform:translateY(40%); opacity:.3; } to{ transform:none; opacity:1; } }



        .confirm-panel::before{ content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }



        .confirm-panel h3{ margin:4px 0 8px; font-size:18.5px; font-weight:800; letter-spacing:-.01em; }



        .confirm-panel p{ margin:0 0 18px; font-size:14.5px; color:var(--text-2); line-height:1.5; }



        .confirm-actions{ display:flex; gap:10px; }



        .confirm-actions .btn{ flex:1; }



        @media (min-width:480px){ .confirm-overlay{ align-items:center; } .confirm-panel{ border-radius:24px; } }



    </style>



    <style>



        /* ================================================================



           LISTA RIGHE (ex-tabella) — card eleganti, gesture iOS



           ================================================================ */



        .fc-rows{ display:flex; flex-direction:column; gap:14px; margin:16px 0; }



        .fc-rows:empty{ display:none; }







        .fc-row{



            position:relative; border-radius:var(--r-card); overflow:hidden;



            box-shadow:var(--shadow-card);



        }



        .fc-row.is-dragging-row{ box-shadow:none; }



        .fc-row.is-child{ margin-left:26px; }







        /* --- livello azioni swipe (sotto, rivelato scorrendo verso destra) ---



           IMPORTANTE: niente inset:0/right:0 qui. Deve restringersi al contenuto



           (i 2 pulsanti, ~172px) altrimenti offsetWidth = larghezza di tutta la riga



           e lo swipe potrebbe trascinare l'intera card fuori dallo schermo. */



        .swipe-actions{



            position:absolute; top:0; left:0; bottom:0; display:flex; align-items:stretch; justify-content:flex-start;



        }



        .swipe-btn{



            width:86px; flex-shrink:0; border:none; cursor:pointer;



            display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px;



            color:#fff; font-family:inherit; font-size:11.5px; font-weight:800; letter-spacing:.01em;



        }



        .swipe-btn svg{ width:21px; height:21px; }



        .swipe-btn-delete{ background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 12%), var(--red)); }



        .swipe-btn-join{ background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 12%), var(--orange)); }



        .swipe-btn-unjoin{ background:linear-gradient(180deg, color-mix(in srgb, var(--gray), #fff 12%), var(--gray)); }



        .swipe-btn:active{ filter:brightness(.92); }



        .fc-row:not(.is-child) .swipe-btn-unjoin{ display:none; }



        .fc-row.is-child .swipe-btn-join{ display:none; }



        .fc-drag-ghost{ cursor:grabbing; }







        /* --- contenuto principale della riga (si sposta orizzontalmente) --- */



        .fc-row-content{



            position:relative; z-index:2; background:var(--surface);



            border-radius:var(--r-card); border:.5px solid var(--hairline);



            will-change:transform; touch-action:pan-y;



        }



        /* Nota: background sempre 100% opaco (mix di due colori pieni) — mai da colori



           -soft con alpha, altrimenti si intravedono i pulsanti dello swipe sotto. */



        .fc-row.is-child .fc-row-content{



            background: color-mix(in srgb, var(--surface), var(--orange) 14%);



            border-left:3px solid var(--orange);



        }







        .fc-row-strip{



            display:flex; align-items:center; gap:10px; padding:12px 14px 8px;



            cursor:grab; touch-action:pan-y;



        }



        .fc-row-strip .grip{



            display:flex; flex-direction:column; gap:3px; padding:2px; flex-shrink:0; color:var(--text-3);



        }



        .fc-row-strip .grip svg{ width:18px; height:18px; }



        .row-badge{



            display:inline-flex; align-items:center; gap:6px;



            font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text-2);



            background:var(--surface-3); padding:4px 10px; border-radius:99px;



        }



        .row-badge::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }



        .fc-row.is-child .row-badge{ background:var(--orange); color:#fff; }



        .fc-row.is-child .row-badge::before{ display:none; }



        .strip-spacer{ flex:1; }



        .strip-hint{ font-size:11px; color:var(--text-3); font-weight:600; display:flex; align-items:center; gap:4px; opacity:.85; }



        .strip-hint svg{ width:13px; height:13px; }







        .fc-row-grid{ display:grid; grid-template-columns:1fr; gap:2px; padding:0 10px 12px; }



        @media (min-width:720px){ .fc-row-grid{ grid-template-columns:1fr 1fr; gap:14px; padding:0 14px 14px; } }







        .fc-field{ padding:8px 6px 4px; }



        @media (min-width:720px){ .fc-field{ padding:6px 0; } .fc-field + .fc-field{ border-left:.5px solid var(--hairline); padding-left:14px; } }







        .field-label{



            display:flex; align-items:center; gap:7px; font-size:10.5px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;



            color:var(--text-3); margin-bottom:7px;



        }



        .field-label.is-answer{ color:var(--blue); }







        .editor-wrap{ border-radius:14px; overflow:hidden; border:1px solid var(--hairline-2); background:var(--surface-2); }



        textarea.cell-input{



            display:block; width:100%; border:none; border-radius:0; background:transparent;



            padding:11px 12px 8px; font-size:16px; line-height:1.5; min-height:64px; box-shadow:none;



            overscroll-behavior:contain;



        }



        textarea.cell-input:focus{ border:none; outline:none; background:var(--surface); }







        .toolbar{



            display:flex; gap:4px; flex-wrap:wrap; padding:5px 6px; border-top:.5px solid var(--hairline);



            background:var(--surface-3);



        }



        .tbtn{



            width:27px; height:27px; border-radius:8px; border:none; cursor:pointer;



            display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:800;



            background:var(--surface); color:var(--text-2); box-shadow:var(--pill-hl);



            transition:transform .12s var(--spring);



        }



        .tbtn:active{ transform:scale(.88); }



        .tbtn.tc-red{ color:var(--red); } .tbtn.tc-blue{ color:var(--blue); } .tbtn.tc-green{ color:var(--green); }



        .tbtn.tc-purple{ color:var(--purple); } .tbtn.tc-orange{ color:var(--orange); }



        .tbtn.tc-yellow{ background:#FFE066; color:#7a5b00; }



        .tsep{ width:1px; align-self:stretch; background:var(--hairline-2); margin:2px 2px; }







        .live-preview{



            margin-top:7px; padding:10px 11px; background:var(--purple-soft); border-radius:12px;



            min-height:18px; font-size:13.5px; color:var(--text); text-align:left; white-space:pre-wrap; word-wrap:break-word;



            max-height:180px; overflow-y:auto; line-height:1.5; overscroll-behavior:contain;



        }



        .live-preview:empty{ display:none; }



        .live-preview mjx-container{ max-width:100%; overflow-x:auto; overflow-y:hidden; }







        .media-row{ display:flex; gap:8px; margin-top:9px; flex-wrap:wrap; align-items:center; }



        .media-chip{



            display:flex; align-items:center; gap:7px; padding:8px 12px 8px 8px; border-radius:99px;



            background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); cursor:pointer;



            font-size:12.5px; font-weight:700; color:var(--text-2); border:none; font-family:inherit;



            transition:transform .15s var(--spring);



        }



        .media-chip:active{ transform:scale(.95); }



        .media-chip input[type=file]{ display:none; }



        .chip-thumb{



            width:26px; height:26px; border-radius:50%; overflow:hidden; flex-shrink:0;



            background:var(--surface-3); display:flex; align-items:center; justify-content:center; color:var(--text-3);



        }



        .chip-thumb svg{ width:13px; height:13px; }



        .chip-thumb img{ width:100%; height:100%; object-fit:cover; }



        .img-chip.has-img{ color:var(--text); }



        .img-name-tag{ max-width:90px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }







        .btn-rec{



            color:var(--pink); background:var(--pink-soft);



            box-shadow:inset 0 0 0 1.5px rgba(255,45,85,.35), var(--pill-hl);



        }



        .btn-rec .chip-thumb{ background:transparent; color:var(--pink); }



        .btn-rec.recording{



            color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--pink), #fff 15%), var(--pink));



            box-shadow:0 0 0 4px var(--pink-soft), 0 6px 16px -6px rgba(255,45,85,.6);



            animation:recPulse 1.4s ease-in-out infinite;



        }



        .btn-rec.recording .chip-thumb{ color:#fff; }



        @keyframes recPulse{



            0%,100%{ box-shadow:0 0 0 4px var(--pink-soft), 0 6px 16px -6px rgba(255,45,85,.6); }



            50%{ box-shadow:0 0 0 8px var(--pink-soft), 0 6px 20px -4px rgba(255,45,85,.75); }



        }



        .rec-dot{ width:8px; height:8px; border-radius:50%; background:#fff; flex-shrink:0; }



        .btn-rec:not(.recording) .rec-dot{ background:var(--pink); }



        .btn-rec.recording .rec-dot{ animation:recBlink 1s step-start infinite; }



        @keyframes recBlink{ 50%{ opacity:.25; } }







        .audio-player{ display:none; width:100%; height:36px; margin-top:8px; border-radius:99px; }



        .audio-player.has-audio{ display:block; }







        /* --- placeholder & drag ghost (riordino) --- */



        .fc-row-placeholder{



            border-radius:var(--r-card); border:2px dashed var(--hairline-2);



            background:var(--surface-2); opacity:.7;



        }



        .fc-drag-ghost{ pointer-events:none; }



        .fc-drag-ghost .fc-row{ box-shadow:0 22px 44px -12px rgba(0,0,0,.35), 0 4px 14px rgba(0,0,0,.14); }



        .fc-drag-ghost .fc-row-content{ box-shadow:0 0 0 2px var(--blue); }







        /* ================================================================



           BOTTONE AGGIUNGI / GENERA / SALVA



           ================================================================ */



        .action-stack{ display:flex; flex-direction:column; gap:10px; margin-top:6px; }



        .empty-hint{



            text-align:center; padding:26px 18px; color:var(--text-3); font-size:13.5px; font-weight:600;



            border:1.5px dashed var(--hairline-2); border-radius:var(--r-card);



        }







        @media (prefers-reduced-motion: reduce){



            *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }



        }



    </style>



    <script src="fc_parser.js"></script>



</head>



<body>



<?php include 'sidebar.html'; ?>







<div id="parent-selection-banner">



    <span>👇 Tocca il concetto padre a cui agganciare questo figlio</span>



    <button type="button" onclick="cancelChildSelection()">✕ Annulla</button>



</div>







<header class="app-header">



    <div class="hdr-text">



        <h1>Big Insert</h1>



        <div class="hdr-sub">Flashcards Universitarie</div>



    </div>



    <div class="hdr-chip" id="row-count-chip">0 righe</div>



</header>

<div style="display: none;" id="editor-template-container">
    <?php 
        $ft_field = '%%FIELD_ID%%';
        $ft_raw = '%%RAW_TEXT%%';
        $ft_placeholder = '%%PLACEHOLDER%%';
        include 'format_toolbar.php'; 
    ?>
</div>





<div class="page-wrap">







    <div id="success-msg">



        ✅ Inserimento completato — <strong id="success-count">0</strong> flashcards salvate.



        <small>Ritorno alla categoria in corso…</small>



    </div>







    <div class="format-help">



        <strong>🤖 Prompt IA per la generazione automatica</strong>



        <p>Tocca il riquadro sottostante per copiare il testo e incollalo su ChatGPT/Gemini per generare flashcards già compatibili.</p>



        <div class="ai-prompt-box">
            <span class="ai-copy-hint">Tocca per copiare</span>
            <textarea id="ai_prompt_textarea" readonly onclick="this.select(); navigator.clipboard.writeText(this.value).then(() => showToast('Copiato negli appunti! 📋')).catch(() => { document.execCommand('copy'); showToast('Copiato negli appunti! 📋'); });"></textarea>
        </div>



    </div>







    <form id="imageForm">



        <div class="setup-grid">



            <div class="card-surface setup-card full">



                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>Categoria di destinazione</div>



                <?php 



                    $cs_id = 'inserimento_big';



                    $cs_placeholder = 'Tocca per selezionare…';



                    $cs_selected_id = $preselected_cat_id;



                    $cs_selected_name = $preselected_cat_name;



                    include 'category_selector.php'; 



                ?>



            </div>







            <div class="card-surface setup-card">



                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M4 7h2.2L8 4h8l1.8 3H20a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/></svg></span>Immagini (opzionale)</div>



                <label class="mini-label">Immagini Domanda</label>



                <input type="file" id="question_images" accept="image/*" multiple style="margin-bottom:12px;">



                <label class="mini-label">Immagini Risposta</label>



                <input type="file" id="answer_images" accept="image/*" multiple>



            </div>







            <div class="card-surface setup-card">
                <div class="sc-label">
                    <span class="ic">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                    </span>
                    Importa da testo
                </div>
                
                <!-- Switch per il formato -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; background: var(--surface-2); padding: 10px 12px; border-radius: 12px; border: 1px solid var(--hairline-2);">
                    <label class="mini-label" style="margin:0; font-size: 13.5px;">Usa separatori personalizzati (### e @@@)</label>
                    <input type="checkbox" id="format_switch" style="transform: scale(1.2); accent-color: var(--blue); cursor: pointer;" onchange="toggleImportFormat()">
                </div>

                <textarea id="import_text" rows="3" placeholder='Incolla qui l&#39;array JSON. Esempio: [{"q": "Domanda", "a": "Risposta"}]' style="font-size:14.5px;"></textarea>
                
                <!-- Contenitore separatori, nascosto di default -->
                <div class="import-row" id="custom_separators_row" style="display: none;">
                    <div>
                        <label class="mini-label">Separatore Flashcards</label>
                        <select id="import_row_separator" onchange="updatePlaceholder()">
                            <option value="@@@" selected>Tre chiocciole ( @@@ )</option>
                            <option value="@">Chiocciola singola ( @ )</option>
                            <option value="\n">A capo (Invio)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mini-label">Separatore Dom/Risp</label>
                        <select id="import_separator" onchange="updatePlaceholder()">
                            <option value="###" selected>Tre hash ( ### )</option>
                            <option value="|">Pipe ( | )</option>
                            <option value=";">Punto e virgola ( ; )</option>
                        </select>
                    </div>
                </div>
            </div>



        </div>







        <button type="button" id="btn-generate-table" class="btn btn-warn" onclick="generateTable()">



            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>



            Genera Tabella Dati



        </button>







        <div class="fc-rows" id="fcRows"></div>



        <div class="empty-hint" id="emptyHint">Nessuna riga ancora — genera dai dati importati oppure aggiungine una vuota qui sotto.</div>







        <div class="action-stack">



            <button type="button" class="btn btn-primary" onclick="addSingleRow()">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>



                Aggiungi Riga Vuota



            </button>



            <button type="submit" id="submitBtn" class="btn btn-success">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>



                Salva Tutto nel Database



            </button>



        </div>



    </form>



</div>







<div class="confirm-overlay" id="confirmOverlay">



    <div class="confirm-panel">



        <h3 id="confirmTitle">Sei sicuro?</h3>



        <p id="confirmMessage"></p>



        <div class="confirm-actions">



            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn">Annulla</button>



            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn">Conferma</button>



        </div>



    </div>



</div>



<script>



    /* ============================================================



       ICONE INLINE



       ============================================================ */



    const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';



    const ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 14.5 14.5 9.5M8 17l-2 2a3.5 3.5 0 0 1-5-5l3-3a3.5 3.5 0 0 1 5 0M16 7l2-2a3.5 3.5 0 0 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></svg>';



    const ICON_UNLINK= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17H8a4 4 0 0 1 0-8h1M11 7h5a4 4 0 0 1 0 8h-1M4 4l16 16"/></svg>';



    const ICON_GRIP  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"/><circle cx="16" cy="6" r="1.6"/><circle cx="8" cy="12" r="1.6"/><circle cx="16" cy="12" r="1.6"/><circle cx="8" cy="18" r="1.6"/><circle cx="16" cy="18" r="1.6"/></svg>';



    const ICON_REORDER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m8 9 4-4 4 4M8 15l4 4 4-4"/></svg>';



    const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';



    const ICON_GEN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';



    const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';







    function escAttr(s){



        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');



    }







    /* ============================================================



       STATO GLOBALE



       ============================================================ */



    let isSelectingParent = false;



    let childRowPending = null;



    let gestureBlock = false;



    let activeDrag = null;



    let autoScrollRAF = null;



    let lastPointerY = 0;



    let suppressNextRowClick = false;







    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }



    function isChildRow(row){ return row.classList.contains('is-child'); }



    function getBlockStart(row){ let r = row; while (r && isChildRow(r)) r = r.previousElementSibling; return r; }



    function getBlockRows(parentRow){



        const rows = [parentRow];



        let cur = parentRow.nextElementSibling;



        while (cur && isChildRow(cur)) { rows.push(cur); cur = cur.nextElementSibling; }



        return rows;



    }







    /* ============================================================



       TOAST & MODALE CONFERMA



       ============================================================ */



    function showToast(msg, kind){



        const t = document.createElement('div');



        t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');



        t.textContent = msg;



        document.body.appendChild(t);



        requestAnimationFrame(()=> t.classList.add('show'));



        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=> t.remove(), 320); }, 3200);



    }







    function showConfirm(title, message, okLabel){



        return new Promise(resolve=>{



            const overlay = document.getElementById('confirmOverlay');



            document.getElementById('confirmTitle').textContent = title;



            document.getElementById('confirmMessage').textContent = message;



            const okBtn = document.getElementById('confirmOkBtn');



            const cancelBtn = document.getElementById('confirmCancelBtn');



            okBtn.textContent = okLabel || 'Conferma';



            overlay.classList.add('show');



            function cleanup(result){



                overlay.classList.remove('show');



                okBtn.removeEventListener('click', onOk);



                cancelBtn.removeEventListener('click', onCancel);



                resolve(result);



            }



            function onOk(){ cleanup(true); }



            function onCancel(){ cleanup(false); }



            okBtn.addEventListener('click', onOk);



            cancelBtn.addEventListener('click', onCancel);



        });



    }







    /* ============================================================



       FLIP — animazioni fluide su ogni riordino del DOM



       ============================================================ */



    function flip(container, mutate){



        const rows = Array.from(container.children);



        const first = new Map();



        rows.forEach(r=> first.set(r, r.getBoundingClientRect()));



        mutate();



        Array.from(container.children).forEach(r=>{



            const f = first.get(r);



            if (!f) return;



            const l = r.getBoundingClientRect();



            const dx = f.left - l.left, dy = f.top - l.top;



            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5){



                r.style.transition = 'none';



                r.style.transform = `translate(${dx}px, ${dy}px)`;



                requestAnimationFrame(()=>{



                    r.style.transition = 'transform .38s var(--spring)';



                    r.style.transform = '';



                });



            }



        });



    }







    /* ============================================================



       CONTATORI / STATO PULSANTI



       ============================================================ */



    function updateRowCountChip(){



        const n = document.querySelectorAll('#fcRows .fc-row').length;



        const chip = document.getElementById('row-count-chip');



        if (chip) chip.textContent = n + (n === 1 ? ' riga' : ' righe');



    }



    function toggleEmptyHint(){



        const hint = document.getElementById('emptyHint');



        if (hint) hint.style.display = document.getElementById('fcRows').children.length ? 'none' : 'block';



    }



    function updateGenerateButtonState(){



        const list = document.getElementById('fcRows');



        const btn = document.getElementById('btn-generate-table');



        if (list.children.length > 0){



            btn.classList.remove('btn-warn'); btn.classList.add('btn-flat-warn');



            btn.innerHTML = '⚠️ Tabella piena — tocca per ricreare';



        } else {



            btn.classList.add('btn-warn'); btn.classList.remove('btn-flat-warn');



            btn.innerHTML = ICON_GEN + ' Genera Tabella Dati';



        }



        updateRowCountChip();



        toggleEmptyHint();



    }







    /* ============================================================



       MOTORE PREVIEW 



       ============================================================ */



    let mathJaxTimeouts = {};







    /* ============================================================



       GENERAZIONE TABELLA & RIGHE



       ============================================================ */

    function updateAIPrompt() {
        const isCustom = document.getElementById('format_switch').checked;
        const rowSepVal = document.getElementById('import_row_separator').value;
        const colSepVal = document.getElementById('import_separator').value;
        
        let rule4 = "";
        let exampleOutput = "";
        
        // Regole di base sempre uguali
        const baseRules = `Sto utilizzando un sito personalizzato per le flashcards. Vorrei che mi generassi delle flashcards sull'argomento: [INSERISCI QUI ARGOMENTO]
Utilizzando ESATTAMENTE le seguenti regole di formattazione del sito:
1. TESTO: Puoi andare a capo quando vuoi per rendere più chiara la lettura. Usa questi comandi per evidenziare le parti più importanti:
    per colorare il testo: /red{testo}, /blue{testo}, /green{testo}, /purple{testo}, /orange{testo}, /gray{testo}.
    per evidenziare il testo: /yellow{testo}.
    per formattarlo: per cose importanti: /bold{testo}, /underline{testo}. per aggiunte o esempi: /italic{testo}.
2. LISTE: Si usano per i punti elenco. Puoi scegliere il colore della barra laterale usando: /list-red{}, /list-blue{}, /list-green{}, /list-purple{}, /list-orange{}, /list-gray{} (oppure usa semplicemente /list{} per il colore predefinito).
    I punti elenco seguono queste regole:
    - Usa i trattini iniziali per i livelli ("-" livello 1, "--" livello 2, "---" livello 3 e così via senza limiti).
    - Per andare a capo dentro un punto elenco senza creare un nuovo pallino, vai a capo e scrivi il testo senza trattino.
    Questo è un esempio di elenco formattato:
/list-blue{
    - primo punto principale
    - secondo punto principale
      testo che va a capo dentro il secondo punto senza generare un nuovo pallino
        -- primo sottopunto del secondo punto
            --- primo sotto-sottopunto
    - terzo punto principale}
3. FORMULE LATEX: Il LaTeX in riga va tra $ $ (es. la formula è $ formula $, bla bla). Quello isolato che va a capo va tra $$ $$ (es. la formula è $$ formula $$ Bla bla). ATTENZIONE: non mettere MAI il punto o la virgola subito dopo il doppio dollaro, altrimenti ci sarà una riga con un solo punto! Sbagliato: $$ formula $$, bla bla.`;

        // Aggiunta dinamica della Regola 4 e dell'esempio
        // Aggiunta dinamica della Regola 4 e dell'esempio
    if (!isCustom) {
        rule4 = `4. FORMATO E LATEX (CRITICO): L'output deve essere ESATTAMENTE ed UNICAMENTE un array JSON. Usa "q" per la domanda e "a" per la risposta.
ATTENZIONE ALLE REGOLE JSON:
- NON andare MAI a capo fisicamente all'interno dei testi (usa SEMPRE e solo "\\n" per indicare gli a capo).
- Raddoppia TUTTI i backslash dei comandi LaTeX (es. scrivi "\\\\mu" invece di "\\mu", "\\\\frac" invece di "\\frac", "\\\\%" invece di "\\%"), altrimenti il formato si rompe.`;
                
                // Uso i doppi e quadrupli backslash per farli stampare correttamente nella textarea
                exampleOutput = `Esempio di output perfetto:
[
{"q": "Prima domanda /bold{importante}", "a": "Prima risposta"},
{"q": "Formula di $\\\\mu$?", "a": "La risposta:\\n$$\\\\frac{\\\\sigma}{\\\\sqrt{n}}$$\\n/red{Attenzione...}"}
]`;
            } else {
            const rowSepText = rowSepVal === '\\n' ? "un invio (a capo)" : `"${rowSepVal}"`;
            const colSepText = colSepVal === '\\t' ? "una tabulazione" : `"${colSepVal}"`;
            const actualRowSep = rowSepVal === '\\n' ? '\n' : rowSepVal;
            
            rule4 = `4. SEPARATORI: L'output deve essere un testo grezzo. Tra la Domanda e la Risposta inserisci SEMPRE e SOLO ${colSepText}. Tra una flashcard e la successiva inserisci SEMPRE e SOLO ${rowSepText}.`;
            exampleOutput = `Esempio di output perfetto:
Prima domanda /bold{importante} ${colSepVal} Prima risposta${actualRowSep}Dimmi la formula di $domanda$ ${colSepVal} La risposta è $$formula$$ /red{attenzione...}`;
        }

        const finalPrompt = `${baseRules}\n${rule4}\n\n${exampleOutput}\n\nle flash cards che dovrai generare, dovranno riguardare questi concetti:`;
        
        document.getElementById('ai_prompt_textarea').value = finalPrompt;
    }

    function updatePlaceholder() {
        const isCustom = document.getElementById('format_switch').checked;
        const textArea = document.getElementById('import_text');

        if (isCustom) {
            let rowSep = document.getElementById('import_row_separator').value;
            let colSep = document.getElementById('import_separator').value;
            if (rowSep === '\\n') rowSep = 'Invio (a capo)';
            
            textArea.placeholder = `Incolla qui il testo generato dall'IA con ${rowSep} e ${colSep}…`;
        } else {
            textArea.placeholder = 'Incolla qui l\'array JSON. Esempio: [{"q": "Domanda", "a": "Risposta"}]';
        }
        
        // Aggiorniamo anche il prompt
        updateAIPrompt();
    }

    function toggleImportFormat() {
        const isCustom = document.getElementById('format_switch').checked;
        const customRow = document.getElementById('custom_separators_row');

        customRow.style.display = isCustom ? 'flex' : 'none';
        
        updatePlaceholder();
    }

    // Inizializza i testi corretti appena si carica la pagina
    window.addEventListener('DOMContentLoaded', () => {
        updatePlaceholder();
    });

    async function generateTable(){
        const list = document.getElementById('fcRows');
        if (list.children.length > 0){
            const ok = await showConfirm(
                'Ricreare la tabella?',
                'Hai già delle righe. Continuando, tutti i dati inseriti o modificati qui sotto andranno persi per fare spazio ai nuovi.',
                'Sì, ricrea'
            );
            if (!ok) return;
        }
        list.innerHTML = '';

        const filesQ = document.getElementById('question_images').files;
        const filesA = document.getElementById('answer_images').files;
        const textContent = document.getElementById('import_text').value.trim();
        const isCustom = document.getElementById('format_switch').checked;

        let parsedData = []; // Salveremo qui le domande e risposte strutturate

        if (textContent !== '') {
            if (!isCustom) {
                // LOGICA DI PARSING JSON
                try {
                    // 1. Ripara gli a capo "fisici" inseriti per errore (es. nelle formule isolate $$...$$)
                    // Trova gli invii che non fanno parte della struttura del JSON e li trasforma in \n
                    let safeText = textContent.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"q"|"a"|\{))/g, '\\n');
                    
                    // 2. Raddoppia i backslash del LaTeX (\mu diventa \\mu, % diventa \\%), per evitare che
                    // il JSON li scambi per comandi di sistema non validi. Esclude \n, \t, " e / che sono già ok.
                    safeText = safeText.replace(/(?<!\\)\\(?!n|t|r|"|\\|\/)/g, '\\\\');
                    
                    const jsonArr = JSON.parse(safeText);
                    if (!Array.isArray(jsonArr)) throw new Error("Il JSON deve essere un array.");
                    
                    parsedData = jsonArr.map(item => ({
                        // Estrae il testo e sostituisce tutti i "\%" con "%"
                        q: (item.q || item.domanda || item.question || '').replace(/\\%/g, '%'),
                        a: (item.a || item.risposta || item.answer || '').replace(/\\%/g, '%')
                    }));
                } catch (error) {
                    showToast('Errore: Il testo non è un JSON valido. Controlla la formattazione.', 'error');
                    console.error("Dettaglio errore JSON:", error); // Così puoi vederlo in console se serve
                    return; 
                }
            } else {
                // VECCHIA LOGICA DI PARSING CON SEPARATORI
                let colSeparator = document.getElementById('import_separator').value;
                if (colSeparator === '\\t') colSeparator = '\t';
                let rowSeparator = document.getElementById('import_row_separator').value;
                if (rowSeparator === '\\n') rowSeparator = '\n';

                const parsedLines = textContent.split(rowSeparator).map(l=>l.trim()).filter(l=> l !== '');
                parsedData = parsedLines.map(line => {
                    const parts = line.split(colSeparator);
                    return {
                        // Estrae il testo e sostituisce tutti i "\%" con "%"
                        q: (parts[0] ? parts[0].trim() : '').replace(/\\%/g, '%'),
                        a: parts.slice(1).join(colSeparator).trim().replace(/\\%/g, '%')
                    };
                });
            }
        }

        const maxRows = Math.max(filesQ.length, filesA.length, parsedData.length);

        for (let i=0; i<maxRows; i++){
            let qText = '', aText = '';
            if (i < parsedData.length){
                qText = parsedData[i].q;
                aText = parsedData[i].a;
            }
            
            const qUrl = i < filesQ.length ? URL.createObjectURL(filesQ[i]) : '';
            const qName = i < filesQ.length ? filesQ[i].name : '';
            const aUrl = i < filesA.length ? URL.createObjectURL(filesA[i]) : '';
            const aName = i < filesA.length ? filesA[i].name : '';
            
            appendRowHTML(i, qText, aText, qUrl, qName, aUrl, aName, false);
        }

        updateGenerateButtonState();
    }







    function addSingleRow(){

        const uniqueId = Date.now();

        appendRowHTML(uniqueId, '', '', '', '', '', '', false);

        updateGenerateButtonState();

        setTimeout(()=>{

            const row = document.querySelector(`.fc-row[data-rid="${uniqueId}"]`);

            if (row) row.scrollIntoView({behavior:'smooth', block:'center'});

            // Punta all'editor nuovo

            const editorDiv = document.getElementById('q-desc-'+uniqueId+'_editor');

            if (editorDiv) editorDiv.focus({preventScroll:true});

        }, 80);

    }







    





    function fieldTemplate(prefix, id, text, label, imgUrl, imgName, isChild){
        const taId = `${prefix}-desc-${id}`;
        const imgPrevId = `${prefix}-img-preview-${id}`;
        const placeholder = prefix === 'q'
            ? (isChild ? '↳ Sottodomanda (Figlio)…' : 'Domanda / LaTeX…')
            : 'Risposta / LaTeX…';
        const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

        // 1. Preleviamo l'HTML base generato dal template nascosto
        let editorHtml = document.getElementById('editor-template-container').innerHTML;
        
        // 2. Sostituiamo i segnaposto con i dati veri
        editorHtml = editorHtml
            .replace(/%%FIELD_ID%%/g, () => taId)
            .replace(/%%RAW_TEXT%%/g, () => escAttr(text))
            .replace(/%%PLACEHOLDER%%/g, () => placeholder);

        return `
        <div class="fc-field">
            <div class="field-label ${prefix === 'a' ? 'is-answer' : ''}">${label}</div>
            
            ${editorHtml}

            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="${prefix}-chip-${id}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${prefix}', '${id}')">
                    <span class="chip-thumb" id="${imgPrevId}">${thumb}</span>
                    <span class="img-name-tag">${imgName ? escAttr(imgName) : 'Immagine'}</span>
                </label>
                <button type="button" class="media-chip btn-rec" onclick="toggleRowRecord(this, '${prefix}')">
                    <span class="chip-thumb"><span class="rec-dot"></span></span>
                    <span class="rec-label">Registra</span>
                </button>
                <audio class="audio-player audio-player-${prefix}" controls></audio>
            </div>
            <input type="hidden" class="${prefix}-img-name" value="${imgName ? escAttr(imgName) : ''}">
        </div>`;
    }







    function appendRowHTML(id, qText, aText, qImgUrl, qImgName, aImgUrl, aImgName, isChild){



        const list = document.getElementById('fcRows');



        const wrap = document.createElement('div');



        wrap.className = 'fc-row data-row' + (isChild ? ' is-child' : '');



        wrap.dataset.rid = id;



        wrap.innerHTML = `



            <div class="swipe-actions">



                <button type="button" class="swipe-btn swipe-btn-delete" onclick="swipeDeleteRow(this)">${ICON_TRASH}<span>Elimina</span></button>



                <button type="button" class="swipe-btn swipe-btn-join" onclick="swipeJoinRow(this)">${ICON_LINK}<span>Figlio</span></button>



                <button type="button" class="swipe-btn swipe-btn-unjoin" onclick="swipeUnjoinRow(this)">${ICON_UNLINK}<span>Sgancia</span></button>



            </div>



            <div class="fc-row-content">



                <div class="fc-row-strip">



                    <span class="grip">${ICON_GRIP}</span>



                    <span class="row-badge">${isChild ? '↳ Figlio' : 'Concetto'}</span>



                    <span class="strip-spacer"></span>



                    <span class="strip-hint">${ICON_REORDER} Tieni premuto</span>



                </div>



                <div class="fc-row-grid">



                    ${fieldTemplate('q', id, qText, 'Domanda', qImgUrl, qImgName, isChild)}



                    ${fieldTemplate('a', id, aText, 'Risposta', aImgUrl, aImgName, isChild)}



                </div>



            </div>`;



        list.appendChild(wrap);



        // INIZIALIZZA I NUOVI EDITOR

        wrap.querySelectorAll('.fmt-editor-wrap').forEach(function(editorWrap) {

            if (typeof fcInitEditorWrap === 'function') fcInitEditorWrap(editorWrap);

        });



        updateGenerateButtonState();



    }







    







    function previewRowImage(input, type, id){



        const file = input.files[0];



        const thumb = document.getElementById(`${type}-img-preview-${id}`);



        const chip = document.getElementById(`${type}-chip-${id}`);



        const nameTag = chip.querySelector('.img-name-tag');



        const row = input.closest('.fc-row');







        if (file){



            const url = URL.createObjectURL(file);



            thumb.innerHTML = `<img src="${url}">`;



            chip.classList.add('has-img');



            if (nameTag) nameTag.textContent = file.name;



            if (type === 'q') row.qNewImgFile = file; else row.aNewImgFile = file;



        } else {



            thumb.innerHTML = ICON_IMG;



            chip.classList.remove('has-img');



            if (nameTag) nameTag.textContent = 'Immagine';



            if (type === 'q') delete row.qNewImgFile; else delete row.aNewImgFile;



        }



    }



</script>



<script>



    /* ============================================================



       BADGE / STATO FIGLIO / SWIPE ACTIONS



       ============================================================ */



    function updateRowBadgeText(row){



        const badge = row.querySelector('.row-badge');



        if (badge) badge.textContent = isChildRow(row) ? '↳ Figlio' : 'Concetto';



    }







    function closeSwipe(row){



        if (!row) return;



        const content = row.querySelector('.fc-row-content');



        content.style.transition = 'transform .32s var(--snap)';



        content.style.transform = 'translateX(0px)';



        row._swipeX = 0;



    }



    function closeAllSwipes(except){



        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



            if (r !== except && r._swipeX) closeSwipe(r);



        });



    }







    function swipeDeleteRow(btn){



        const row = btn.closest('.fc-row');



        const family = isChildRow(row) ? [row] : getBlockRows(row);



        const list = document.getElementById('fcRows');



        family.forEach(r=>{



            r.style.transition = 'transform .26s var(--spring), opacity .26s var(--spring)';



            r.style.transform = 'translateX(105%) scale(.95)';



            r.style.opacity = '0';



            r.style.pointerEvents = 'none';



        });



        setTimeout(()=>{



            flip(list, ()=> family.forEach(r=> r.remove()));



            updateGenerateButtonState();



        }, 230);



    }







    function swipeJoinRow(btn){



        const row = btn.closest('.fc-row');



        closeSwipe(row);



        startChildSelection(row);



    }







    function swipeUnjoinRow(btn){



        const row = btn.closest('.fc-row');



        unjoinRow(row);



    }







    function unjoinRow(row){



        row.classList.remove('is-child');



        updateRowBadgeText(row);



        const qArea = row.querySelector('.q-desc');



        if (qArea && qArea.placeholder.indexOf('Sottodomanda') !== -1) qArea.placeholder = 'Domanda / LaTeX…';







        // Sposta la riga fisicamente alla fine del blocco dei figli attuali,



        // altrimenti "catturerebbe" come propri figli i suoi ex fratelli minori



        // (getBlockRows considera figlia ogni is-child consecutiva dopo una non-figlia).



        let lastChild = row;



        while (lastChild.nextElementSibling && lastChild.nextElementSibling.classList.contains('is-child')) {



            lastChild = lastChild.nextElementSibling;



        }



        if (lastChild !== row) {



            flip(document.getElementById('fcRows'), ()=> lastChild.after(row));



        }







        closeSwipe(row);



        updateGenerateButtonState();



        showToast('Concetto sganciato — ora è di nuovo un padre indipendente.');



    }







    function markEligibleTargets(){



        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



            r.classList.remove('eligible-target');



            if (r !== childRowPending && !isChildRow(r)) r.classList.add('eligible-target');



        });



    }







    function startChildSelection(row){



        childRowPending = row;



        isSelectingParent = true;



        closeAllSwipes();



        row.classList.add('pending-child');



        document.getElementById('parent-selection-banner').classList.add('show');



        document.body.classList.add('selecting-mode');



        markEligibleTargets();



    }







    function cancelChildSelection(){



        isSelectingParent = false;



        if (childRowPending) childRowPending.classList.remove('pending-child');



        childRowPending = null;



        document.getElementById('parent-selection-banner').classList.remove('show');



        document.body.classList.remove('selecting-mode');



        document.querySelectorAll('.eligible-target').forEach(r=> r.classList.remove('eligible-target'));



    }







    document.getElementById('fcRows').addEventListener('click', function(e){



        if (!isSelectingParent) return;



        if (suppressNextRowClick) { suppressNextRowClick = false; return; }



        if (e.target.closest('button, input, textarea, a, select')) return;







        const clickedRow = e.target.closest('.fc-row');



        if (!clickedRow || clickedRow === childRowPending) return;







        if (isChildRow(clickedRow)) {



            showToast('Non puoi agganciare un figlio a un altro figlio. Scegli un concetto padre.', 'error');



            return;



        }







        const list = document.getElementById('fcRows');



        const block = getBlockRows(clickedRow);



        const insertAfter = block[block.length - 1];



        const pending = childRowPending;







        flip(list, ()=>{



            insertAfter.after(pending);



            pending.classList.add('is-child');



        });







        updateRowBadgeText(pending);



        const qArea = pending.querySelector('.q-desc');



        if (qArea) qArea.placeholder = '↳ Sottodomanda (Figlio)…';



        closeSwipe(pending);



        buzz(14);



        cancelChildSelection();



        updateGenerateButtonState();



        showToast('Concetto agganciato come figlio ✓');



    });







    /* ============================================================



       MOTORE GESTURE — swipe orizzontale + riordino a pressione prolungata



       ============================================================ */



    (function(){



        const TAP_SLOP = 9;



        const SWIPE_SLOP = 15;



        const STILL_SLOP = 6;



        const LONG_PRESS_MS = 420;



        let pend = null;







        function eligibleRow(e){



            if (!e.target || !e.target.closest) return null;



            if (e.target.closest('textarea, input, select, button, audio, a')) return null;



            const row = e.target.closest('.fc-row');



            if (!row || !row.closest('#fcRows')) return null;



            return row;



        }







        document.addEventListener('pointerdown', function(e){



            if (activeDrag || isSelectingParent) return;



            const insideRow = e.target.closest && e.target.closest('.fc-row');



            document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



                if (r._swipeX && r !== insideRow) closeSwipe(r);



            });



        }, true);







        document.addEventListener('pointerdown', function(e){



            if (isSelectingParent) return;



            if (pend || activeDrag) return;



            if (e.pointerType === 'mouse' && e.button !== 0) return;



            const row = eligibleRow(e);



            if (!row) return;



            const content = row.querySelector('.fc-row-content');



            pend = {



                row, content, pointerId: e.pointerId,



                startX: e.clientX, startY: e.clientY,



                lastX: e.clientX, lastY: e.clientY,



                swiping: false, reordering: false,



                longPressTimer: setTimeout(()=>{



                    // Attiva il riordino SOLO se il dito è rimasto davvero fermo:



                    // se nel frattempo si è mosso (anche solo per iniziare uno scroll



                    // lento), non dobbiamo rubare il gesto alla pagina.



                    if (!pend || pend.swiping) return;



                    const stillDx = Math.abs(pend.lastX - pend.startX);



                    const stillDy = Math.abs(pend.lastY - pend.startY);



                    if (stillDx < STILL_SLOP && stillDy < STILL_SLOP) activateReorder(pend);



                }, LONG_PRESS_MS)



            };



        });







        document.addEventListener('pointermove', function(e){



            if (!pend || e.pointerId !== pend.pointerId) return;



            pend.lastX = e.clientX; pend.lastY = e.clientY;



            if (pend.reordering) { doReorderMove(e); return; }



            if (pend.swiping) { doSwipeMove(e); return; }







            const dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;



            const adx = Math.abs(dx), ady = Math.abs(dy);







            // Priorità allo scroll nativo: appena il movimento non è NETTAMENTE



            // orizzontale, molliamo subito il gesto e lasciamo scorrere la pagina.



            // Un dito vero non è mai perfettamente dritto: meglio richiedere uno



            // swipe deciso piuttosto che rubare scroll verticali per errore.



            if (ady > TAP_SLOP && adx < ady * 1.8) {



                clearTimeout(pend.longPressTimer);



                pend = null; // lascia proseguire lo scroll nativo della pagina



                return;



            }



            if (adx > SWIPE_SLOP && adx > ady * 1.8) {



                clearTimeout(pend.longPressTimer);



                pend.swiping = true;



                activateSwipeGesture(pend);



                doSwipeMove(e);



            }



        }, {passive:true});







        document.addEventListener('pointerup', function(e){ finishGesture(e, true); });



        document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });







        function finishGesture(e, committed){



            if (!pend || e.pointerId !== pend.pointerId) return;



            const p = pend; pend = null;



            clearTimeout(p.longPressTimer);



            if (p.reordering) finalizeReorder(p);



            else if (p.swiping) finalizeSwipe(p, committed);



        }







        /* ---- SWIPE ORIZZONTALE ---- */



        function activateSwipeGesture(ctx){



            gestureBlock = true;



            closeAllSwipes(ctx.row);



            ctx.content.style.transition = 'none';



            ctx.maxReveal = ctx.row.querySelector('.swipe-actions').offsetWidth || 172;



            ctx.startTranslate = ctx.row._swipeX || 0;



            document.body.classList.add('no-select');



        }



        function doSwipeMove(e){



            const ctx = pend;



            let x = ctx.startTranslate + (e.clientX - ctx.startX);



            if (x < 0) x = x * 0.15;



            if (x > ctx.maxReveal) x = ctx.maxReveal; // stop netto: mai oltre i pulsanti rivelati



            ctx.content.style.transform = `translateX(${x}px)`;



            ctx.currentX = x;



        }



        function finalizeSwipe(ctx, committed){



            document.body.classList.remove('no-select');



            gestureBlock = false;



            ctx.content.style.transition = 'transform .34s var(--snap)';



            let target = 0;



            if (committed && ctx.currentX !== undefined) target = ctx.currentX > ctx.maxReveal * 0.4 ? ctx.maxReveal : 0;



            ctx.content.style.transform = `translateX(${target}px)`;



            ctx.row._swipeX = target;



            if (target > 0) { suppressNextRowClick = true; setTimeout(()=> suppressNextRowClick = false, 260); buzz(8); }



        }







        /* ---- RIORDINO A PRESSIONE PROLUNGATA ---- */



        function activateReorder(ctx){



            ctx.reordering = true;



            gestureBlock = true;



            buzz(12);



            document.body.classList.add('no-select');







            const row = ctx.row;



            const child = isChildRow(row);



            ctx.blockRows = child ? [row] : getBlockRows(row);



            ctx.scopeParent = child ? getBlockStart(row) : null;







            const list = document.getElementById('fcRows');



            const firstRect = ctx.blockRows[0].getBoundingClientRect();



            const lastRect = ctx.blockRows[ctx.blockRows.length - 1].getBoundingClientRect();



            const totalHeight = lastRect.bottom - firstRect.top;



            const referenceNode = ctx.blockRows[ctx.blockRows.length - 1].nextElementSibling;







            const placeholder = document.createElement('div');



            placeholder.className = 'fc-row-placeholder';



            placeholder.style.height = totalHeight + 'px';



            list.insertBefore(placeholder, referenceNode);



            ctx.placeholder = placeholder;







            const ghost = document.createElement('div');



            ghost.className = 'fc-drag-ghost';



            ghost.style.position = 'fixed';



            ghost.style.left = firstRect.left + 'px';



            ghost.style.top = firstRect.top + 'px';



            ghost.style.width = firstRect.width + 'px';



            ghost.style.zIndex = 9999;



            ghost.style.display = 'flex';



            ghost.style.flexDirection = 'column';



            ghost.style.gap = '14px';



            ctx.blockRows.forEach(r=>{ r.classList.add('is-dragging-row'); ghost.appendChild(r); });



            document.body.appendChild(ghost);



            ctx.ghost = ghost;



            activeDrag = ctx;



        }







        function doReorderMove(e){



            const ctx = pend;



            const dy = e.clientY - ctx.startY;



            ctx.ghost.style.transform = `translateY(${dy}px) scale(1.015)`;



            lastPointerY = e.clientY;



            ensureAutoScroll();



            updateReorderPlacement(ctx, e.clientY);



        }







        function scopeListFor(ctx){



            const list = document.getElementById('fcRows');



            if (ctx.scopeParent){



                const out = [];



                let cur = ctx.scopeParent.nextElementSibling;



                while (cur && (cur.classList.contains('is-child') || cur === ctx.placeholder)) {



                    out.push(cur);



                    cur = cur.nextElementSibling;



                }



                return out;



            }



            return Array.from(list.children).filter(el=> el === ctx.placeholder || !el.classList.contains('is-child'));



        }







        function nodeRect(el, ctx){



            if (el === ctx.placeholder) return el.getBoundingClientRect();



            if (!ctx.scopeParent && !el.classList.contains('is-child')){



                const rows = getBlockRows(el);



                const a = rows[0].getBoundingClientRect();



                const b = rows[rows.length - 1].getBoundingClientRect();



                return { top: a.top, bottom: b.bottom };



            }



            return el.getBoundingClientRect();



        }







        function updateReorderPlacement(ctx, pointerY){



            const list = document.getElementById('fcRows');



            const scope = scopeListFor(ctx);



            const idx = scope.indexOf(ctx.placeholder);



            if (idx < 0) return;







            if (idx > 0){



                const prev = scope[idx - 1];



                const r = nodeRect(prev, ctx);



                const mid = r.top + (r.bottom - r.top) / 2;



                if (pointerY < mid){



                    flip(list, ()=> list.insertBefore(ctx.placeholder, prev));



                    buzz(4);



                    return;



                }



            }



            if (idx < scope.length - 1){



                const next = scope[idx + 1];



                const r = nodeRect(next, ctx);



                const mid = r.top + (r.bottom - r.top) / 2;



                if (pointerY > mid){



                    const afterEl = (!ctx.scopeParent && !next.classList.contains('is-child'))



                        ? getBlockRows(next)[getBlockRows(next).length - 1].nextElementSibling



                        : next.nextElementSibling;



                    flip(list, ()=> list.insertBefore(ctx.placeholder, afterEl));



                    buzz(4);



                    return;



                }



            }



        }







        function finalizeReorder(ctx){



            stopAutoScroll();



            document.body.classList.remove('no-select');



            gestureBlock = false;



            activeDrag = null;







            const list = document.getElementById('fcRows');



            const placeholder = ctx.placeholder;



            flip(list, ()=>{



                ctx.blockRows.forEach(r=>{ r.classList.remove('is-dragging-row'); list.insertBefore(r, placeholder); });



                placeholder.remove();



            });



            ctx.ghost.remove();



            buzz(10);



        }







        /* ---- AUTO-SCROLL AI BORDI ---- */



        function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }



        function autoScrollLoop(){



            const margin = 110, maxSpeed = 16;



            const vh = window.innerHeight;



            let dy = 0;



            if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);



            else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);



            if (dy && activeDrag) window.scrollBy(0, dy);



            autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;



        }



        function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }







        document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, {passive:false});



    })();







    /* ============================================================



       REGISTRAZIONE AUDIO (con timer live, tinta elegante)



       ============================================================ */



    let currentRecorder = null;



    let currentBtn = null;



    let currentChunks = [];



    let recTimerInterval = null;



    let recStartTime = 0;







    function startRecTimer(btn){



        recStartTime = Date.now();



        clearInterval(recTimerInterval);



        recTimerInterval = setInterval(()=>{



            const s = Math.floor((Date.now() - recStartTime) / 1000);



            const mm = Math.floor(s / 60), ss = String(s % 60).padStart(2, '0');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = mm + ':' + ss;



        }, 500);



    }



    function stopRecTimer(){ clearInterval(recTimerInterval); recTimerInterval = null; }







    async function toggleRowRecord(btn, type){



        if (btn.classList.contains('recording')) {



            currentRecorder.stop();



            stopRecTimer();



            btn.classList.remove('recording');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = 'Registra';



            return;



        }







        if (currentRecorder) {



            showToast('Ferma prima la registrazione in corso.', 'error');



            return;



        }







        try {



            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });



            currentRecorder = new MediaRecorder(stream);



            currentChunks = [];



            currentBtn = btn;







            currentRecorder.ondataavailable = e=>{ if (e.data.size > 0) currentChunks.push(e.data); };







            currentRecorder.onstop = () => {



                const row = currentBtn.closest('.fc-row');



                const mimeType = currentRecorder.mimeType || 'audio/webm';



                const blob = new Blob(currentChunks, { type: mimeType });



                const ext = mimeType.includes('mp4') ? 'm4a' : 'webm';



                const player = row.querySelector('.audio-player-' + type);







                if (player) {



                    player.src = URL.createObjectURL(blob);



                    player.classList.add('has-audio');



                }



                if (type === 'q') { row.qAudioBlob = blob; row.qAudioExt = ext; }



                else { row.aAudioBlob = blob; row.aAudioExt = ext; }







                stream.getTracks().forEach(t=> t.stop());



                currentRecorder = null;



            };







            currentRecorder.start();



            btn.classList.add('recording');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = '0:00';



            startRecTimer(btn);



            buzz(10);



        } catch (err) {



            showToast('Impossibile accedere al microfono.', 'error');



        }



    }







    /* ============================================================



       INVIO AJAX



       ============================================================ */



    function restoreSubmitLabel(){ return ICON_CHECK + ' Salva Tutto nel Database'; }







    document.getElementById('imageForm').addEventListener('submit', async function(e){



        e.preventDefault();

        

        // Riversa l'HTML dagli editor agli input nascosti prima di leggere i campi

        if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();







        const catIdInput = document.querySelector('input[name="category_id"]').value;



        if (catIdInput === '') { showToast('Seleziona una categoria prima di salvare.', 'error'); return; }







        const rows = document.querySelectorAll('#fcRows .fc-row.data-row');



        if (rows.length === 0) { showToast('Aggiungi almeno una riga prima di salvare.', 'error'); return; }







        const subBtn = document.getElementById('submitBtn');



        subBtn.disabled = true;



        subBtn.innerHTML = '<span class="spinner"></span> Salvataggio in corso…';







        const fd = new FormData();



        fd.append('action', 'insert_bulk');



        fd.append('category_id', catIdInput);







        const qFiles = document.getElementById('question_images').files;



        for (let i=0; i<qFiles.length; i++) fd.append('question_images[]', qFiles[i]);



        const aFiles = document.getElementById('answer_images').files;



        for (let i=0; i<aFiles.length; i++) fd.append('answer_images[]', aFiles[i]);







        rows.forEach((row, index)=>{



            const isChild = row.classList.contains('is-child') ? 1 : 0;



            fd.append('is_child[' + index + ']', isChild);



            fd.append('q_desc[]', row.querySelector('.q-desc').value);



            fd.append('a_desc[]', row.querySelector('.a-desc').value);



            fd.append('q_img_name[]', row.querySelector('.q-img-name').value);



            fd.append('a_img_name[]', row.querySelector('.a-img-name').value);







            if (row.qAudioBlob) fd.append('audio_q[' + index + ']', row.qAudioBlob, 'q_' + index + '.' + row.qAudioExt);



            if (row.aAudioBlob) fd.append('audio_a[' + index + ']', row.aAudioBlob, 'a_' + index + '.' + row.aAudioExt);



            if (row.qNewImgFile) fd.append('row_q_img[' + index + ']', row.qNewImgFile);



            if (row.aNewImgFile) fd.append('row_a_img[' + index + ']', row.aNewImgFile);



        });







        try {



            const res = await fetch('inserimento_big_fc.php', { method: 'POST', body: fd });



            const data = await res.json();







            if (data.success) {



                document.getElementById('imageForm').style.display = 'none';



                document.getElementById('success-msg').style.display = 'block';



                document.getElementById('success-count').textContent = data.count;



                window.scrollTo({ top: 0, behavior: 'smooth' });



                setTimeout(()=>{ window.location.href = 'visualizzazione_fc.php?category_id=' + data.category_id; }, 2000);



            } else {



                showToast('Errore database: ' + (data.error || ''), 'error');



                subBtn.disabled = false;



                subBtn.innerHTML = restoreSubmitLabel();



            }



        } catch (err) {



            showToast('Errore di rete o disconnessione.', 'error');



            subBtn.disabled = false;



            subBtn.innerHTML = restoreSubmitLabel();



        }



    });







    updateGenerateButtonState();



</script>




</body>



</html>

