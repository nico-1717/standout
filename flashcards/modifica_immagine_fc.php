<?php
    require_once 'config.php';

    require_once 'auth_check.php';
    $current_user_id = $utente_corrente['id'];

    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

    $categories = array();
    $query = "SELECT id, name FROM fc_categories WHERE user_id = $current_user_id ORDER BY name";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) { $categories[$row['id']] = $row['name']; }
    }

    // --- INTERCETTAZIONE SALVATAGGIO / ELIMINAZIONE POST ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['fc_id']) || isset($_POST['child_id']))) {

        $is_child = isset($_POST['child_id']);
        $id = $is_child ? (int)$_POST['child_id'] : (int)$_POST['fc_id'];
        $table = $is_child ? "fc_child_cards" : "fc_images";
        $id_col = "id"; // Standardizzato a 'id' per entrambe le tabelle

        $catID = isset($_POST['category_id']) ? (int)$_POST['category_id'] : (isset($_POST['category']) ? (int)$_POST['category'] : (isset($_POST['previous_catid']) ? (int)$_POST['previous_catid'] : 0));

        
        // --- INIZIO CONTROLLO PROPRIETÀ (SICUREZZA) ---
        if ($is_child) {
            // Se è figlio, passiamo dal padre per arrivare alla categoria
            $check_q = "SELECT c.user_id FROM fc_child_cards ch 
                        JOIN fc_images i ON ch.parent_id = i.id 
                        JOIN fc_categories c ON i.category_id = c.id 
                        WHERE ch.id = $id";
        } else {
            // Se è padre, andiamo diretti alla categoria
            $check_q = "SELECT c.user_id FROM fc_images i 
                        JOIN fc_categories c ON i.category_id = c.id 
                        WHERE i.id = $id";
        }
        
        $check_res = mysqli_query($conn, $check_q);
        $check_row = mysqli_fetch_assoc($check_res);

        if (!$check_row || (int)$check_row['user_id'] !== $current_user_id) {
            die("Errore di sicurezza: Non sei il proprietario di questa flashcard.");
        }

        // Controllo aggiuntivo: se sta cambiando la categoria, deve essere proprietario anche della nuova categoria
        if (!$is_child && $catID > 0) {
            $cat_check = mysqli_query($conn, "SELECT user_id FROM fc_categories WHERE id = $catID");
            $cat_row = mysqli_fetch_assoc($cat_check);
            if (!$cat_row || (int)$cat_row['user_id'] !== $current_user_id) {
                die("Errore di sicurezza: Non sei il proprietario della categoria di destinazione.");
            }
        }
        // --- FINE CONTROLLO PROPRIETÀ ---
        
        // RECUPERO STATO ATTUALE FILE FISICI
        $q_old = mysqli_query($conn, "SELECT * FROM $table WHERE $id_col = $id");
        $old = mysqli_fetch_assoc($q_old);

        // AZIONE ELIMINA CARD
        if (isset($_POST['delete'])) {
            if (!empty($old['question_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['question_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['question_image']); }
            if (!empty($old['answer_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['answer_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['answer_image']); }
            if (!empty($old['question_audio']) && file_exists('fc_audio/' . $old['question_audio'])) { unlink('fc_audio/' . $old['question_audio']); }
            if (!empty($old['answer_audio']) && file_exists('fc_audio/' . $old['answer_audio'])) { unlink('fc_audio/' . $old['answer_audio']); }

            mysqli_query($conn, "DELETE FROM $table WHERE $id_col = $id");
            mysqli_close($conn);

            // Invece della vecchia riga di redirect, usa questa:
            $return_to_game = isset($_POST['return_to_game']) ? (int)$_POST['return_to_game'] : 0;

            if ($return_to_game == 1) {
                $redirect_url = "game_fc.php?game_id=" . $_POST['game_id'] . "&position=" . $_POST['position'] . "&status=" . $_POST['status'] . "&order=" . $_POST['order'];
            } else {
                $redirect_url = "visualizzazione_fc.php?category_id=" . $catID;
            }

            echo "<script>window.location.href = '$redirect_url';</script>";
            exit;
        }
        // AZIONE SALVA MODIFICHE
        else {
            $quest = mysqli_real_escape_string($conn, trim($_POST['quest']));
            $answ = mysqli_real_escape_string($conn, trim($_POST['answ']));

            // IMPOSTAZIONI IMMAGINE DOMANDA
            $q_img_field = "question_image = question_image";
            if (isset($_POST['remove_q_img']) && $_POST['remove_q_img'] == '1') {
                if (!empty($old['question_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['question_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['question_image']); }
                $q_img_field = "question_image = ''";
            } elseif (isset($_POST['replace_q_img']) && $_POST['replace_q_img'] == '1' && isset($_FILES['qNew_image']) && $_FILES['qNew_image']['name'] != '') {
                $new_q = time() . "_q_" . $_FILES['qNew_image']['name'];
                move_uploaded_file($_FILES['qNew_image']['tmp_name'], FC_IMAGE_PATH_INSERT . $new_q);
                if (!empty($old['question_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['question_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['question_image']); }
                $q_img_field = "question_image = '$new_q'";
            }

            // IMPOSTAZIONI IMMAGINE RISPOSTA
            $a_img_field = "answer_image = answer_image";
            if (isset($_POST['remove_a_img']) && $_POST['remove_a_img'] == '1') {
                if (!empty($old['answer_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['answer_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['answer_image']); }
                $a_img_field = "answer_image = ''";
            } elseif (isset($_POST['replace_a_img']) && $_POST['replace_a_img'] == '1' && isset($_FILES['aNew_image']) && $_FILES['aNew_image']['name'] != '') {
                $new_a = time() . "_a_" . $_FILES['aNew_image']['name'];
                move_uploaded_file($_FILES['aNew_image']['tmp_name'], FC_IMAGE_PATH_INSERT . $new_a);
                if (!empty($old['answer_image']) && file_exists(FC_IMAGE_PATH_INSERT . $old['answer_image'])) { unlink(FC_IMAGE_PATH_INSERT . $old['answer_image']); }
                $a_img_field = "answer_image = '$new_a'";
            }

            // IMPOSTAZIONI AUDIO DOMANDA (Dinamico .webm / .m4a)
            $q_aud_field = "question_audio = question_audio";
            if (isset($_POST['remove_q_aud']) && $_POST['remove_q_aud'] == '1') {
                if (!empty($old['question_audio']) && file_exists('fc_audio/' . $old['question_audio'])) { unlink('fc_audio/' . $old['question_audio']); }
                $q_aud_field = "question_audio = ''";
            } elseif (isset($_FILES['audio_q']) && $_FILES['audio_q']['size'] > 0) {
                $ext_q = pathinfo($_FILES['audio_q']['name'], PATHINFO_EXTENSION);
                $new_audio_q = "audio_q_" . time() . "." . $ext_q;
                move_uploaded_file($_FILES['audio_q']['tmp_name'], 'fc_audio/' . $new_audio_q);
                if (!empty($old['question_audio']) && file_exists('fc_audio/' . $old['question_audio'])) { unlink('fc_audio/' . $old['question_audio']); }
                $q_aud_field = "question_audio = '$new_audio_q'";
            }

            // IMPOSTAZIONI AUDIO RISPOSTA (Dinamico .webm / .m4a)
            $a_aud_field = "answer_audio = answer_audio";
            if (isset($_POST['remove_a_aud']) && $_POST['remove_a_aud'] == '1') {
                if (!empty($old['answer_audio']) && file_exists('fc_audio/' . $old['answer_audio'])) { unlink('fc_audio/' . $old['answer_audio']); }
                $a_aud_field = "answer_audio = ''";
            } elseif (isset($_FILES['audio_a']) && $_FILES['audio_a']['size'] > 0) {
                $ext_a = pathinfo($_FILES['audio_a']['name'], PATHINFO_EXTENSION);
                $new_audio_a = "audio_a_" . time() . "." . $ext_a;
                move_uploaded_file($_FILES['audio_a']['tmp_name'], 'fc_audio/' . $new_audio_a);
                if (!empty($old['answer_audio']) && file_exists('fc_audio/' . $old['answer_audio'])) { unlink('fc_audio/' . $old['answer_audio']); }
                $a_aud_field = "answer_audio = '$new_audio_a'";
            }

            if ($is_child) {
                $update_q = "UPDATE $table SET question_desc = '$quest', answer_desc = '$answ', $q_img_field, $a_img_field, $q_aud_field, $a_aud_field WHERE $id_col = $id";
            } else {
                $update_q = "UPDATE $table SET question_desc = '$quest', answer_desc = '$answ', category_id = $catID, $q_img_field, $a_img_field, $q_aud_field, $a_aud_field WHERE $id_col = $id";
            }

            mysqli_query($conn, $update_q);
            mysqli_close($conn);

            // Invece della vecchia riga di redirect, usa questa:
            $return_to_game = isset($_POST['return_to_game']) ? (int)$_POST['return_to_game'] : 0;

            if ($return_to_game == 1) {
                $redirect_url = "game_fc.php?game_id=" . $_POST['game_id'] . "&position=" . $_POST['position'] . "&status=" . $_POST['status'] . "&order=" . $_POST['order'];
            } else {
                $redirect_url = "visualizzazione_fc.php?category_id=" . $catID;
            }

            echo "<script>window.location.href = '$redirect_url';</script>";
            exit;
        }
    }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Modifica Flashcard · Materie</title>
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
           DESIGN SYSTEM — stesso linguaggio visivo di result_fc.php
           ================================================================ */
        :root{
            color-scheme: light dark;
            --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
            --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
            --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
            --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --teal:#30B0C7;
            --blue-soft:rgba(0,122,255,.09);
            --g-fill:#E7F8ED; --g-ink:#1E8A44; --g-line:rgba(52,199,89,.85); --g-sh:rgba(52,199,89,.34);
            --r-fill:#FDECEC; --r-ink:#D70015; --r-line:rgba(255,59,48,.8);  --r-sh:rgba(255,59,48,.3);
            --w-fill:#FFF3E0; --w-ink:#C77700; --w-line:rgba(255,149,0,.7);  --w-sh:rgba(255,149,0,.28);
            --p-fill:#F5E9FB; --p-ink:#8E24AA; --p-line:rgba(175,82,222,.55);
            --chip-bg:#FFFFFF;
            --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
            --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
            --pill-hl:inset 0 0 0 .5px var(--hairline);
            --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
            --header-glass:rgba(249,249,251,.82);
            --spring:cubic-bezier(.32,.72,0,1);
            --r-card:20px; --r-field:13px;
            --safe-top:env(safe-area-inset-top, 0px);
            --safe-bottom:env(safe-area-inset-bottom, 0px);
        }
        @media (prefers-color-scheme: dark){
            :root{
                --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
                --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
                --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
                --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --teal:#40C8E0;
                --blue-soft:rgba(10,132,255,.16);
                --g-fill:rgba(48,209,88,.15); --g-ink:#4CE07E; --g-line:rgba(48,209,88,.75); --g-sh:rgba(48,209,88,.5);
                --r-fill:rgba(255,69,58,.16); --r-ink:#FF6F68; --r-line:rgba(255,69,58,.75); --r-sh:rgba(255,69,58,.45);
                --w-fill:rgba(255,159,10,.16); --w-ink:#FFB340; --w-line:rgba(255,159,10,.65); --w-sh:rgba(255,159,10,.4);
                --p-fill:rgba(191,90,242,.16); --p-ink:#D68CFF; --p-line:rgba(191,90,242,.55);
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
        h1{ margin:2px 0 0; font-size:20px; font-weight:800; letter-spacing:-.02em; color:var(--text); }
        .child-badge{
            display:inline-flex; align-items:center; background:var(--orange); color:#fff; padding:3px 10px;
            border-radius:99px; font-size:.62em; vertical-align:middle; margin-left:6px; font-weight:800;
        }

        .page{ max-width:620px; margin:0 auto; padding:16px 14px calc(190px + var(--safe-bottom)); }

        /* ---------------- Card generica ---------------- */
        .fc-card{
            background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline);
            box-shadow:var(--shadow-card); padding:16px; margin-bottom:14px;
        }
        .fc-card-tight{ padding:12px 16px; }
        .fc-card-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
        .fc-card-title{ font-size:15.5px; font-weight:800; letter-spacing:-.01em; color:var(--text); }

        .pill-help{
            display:inline-flex; align-items:center; gap:5px; border:none; cursor:pointer;
            background:var(--blue-soft); color:var(--blue); padding:7px 12px; border-radius:99px;
            font-family:inherit; font-size:12.5px; font-weight:700; transition:transform .15s var(--spring);
        }
        .pill-help:active{ transform:scale(.94); }

        /* ---------------- Immagini ---------------- */
        .img-compare{ display:flex; gap:10px; margin-bottom:10px; }
        .img-box{
            flex:1; min-width:0; border-radius:14px; padding:10px; text-align:center;
            background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline);
        }
        .img-box-new{ box-shadow:inset 0 0 0 1.5px rgba(0,122,255,.4); background:var(--blue-soft); }
        .img-tag{ display:block; font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin-bottom:6px; }
        .img-tag-new{ color:var(--blue); }
        .img-box img{ max-width:100%; max-height:96px; border-radius:9px; object-fit:contain; display:block; margin:0 auto; }
        .img-empty{ color:var(--text-3); font-size:12.5px; font-style:italic; padding:18px 4px; }
        .img-empty-warn{ color:var(--r-ink); }

        .file-row{ margin-bottom:10px; }
        .file-btn{
            display:inline-flex; align-items:center; gap:7px; cursor:pointer;
            background:var(--surface-2); color:var(--text); font-weight:700; font-size:13.5px;
            padding:9px 14px; border-radius:11px; box-shadow:inset 0 0 0 .5px var(--hairline);
            transition:transform .15s var(--spring);
        }
        .file-btn:active{ transform:scale(.96); }

        .control-panel{ display:flex; flex-direction:column; gap:9px; margin-bottom:12px; }
        .chk-row{ display:flex; align-items:center; gap:9px; font-size:13.5px; font-weight:600; cursor:pointer; color:var(--text); }
        .chk-row input[type="checkbox"]{ width:19px; height:19px; accent-color:var(--blue); flex-shrink:0; margin:0; }
        .chk-blue{ color:var(--blue); }
        .chk-red{ color:var(--r-ink); }
        .chk-red input[type="checkbox"]{ accent-color:var(--red); }

        /* ---------------- Audio, compatto ed elegante ---------------- */
        .audio-card{
            background:var(--surface-2); border-radius:16px; padding:12px 14px;
            box-shadow:inset 0 0 0 .5px var(--hairline); display:flex; flex-direction:column; gap:10px;
        }
        .audio-row{ display:flex; align-items:center; gap:12px; }
        .rec-btn{
            width:48px; height:48px; border-radius:50%; flex-shrink:0; border:none; cursor:pointer; padding:0;
            display:flex; align-items:center; justify-content:center; position:relative;
            background:var(--chip-bg); color:var(--text-2);
            box-shadow:var(--pill-hl), var(--raise-sm);
            transition:transform .15s var(--spring), background .2s, color .2s;
        }
        .rec-btn:active{ transform:scale(.9); }
        .rec-btn.recording{
            background:var(--r-fill); color:var(--r-ink);
            box-shadow:inset 0 0 0 1.5px var(--r-line);
            animation:recPulse 1.6s ease-in-out infinite;
        }
        @keyframes recPulse{
            0%,100%{ box-shadow:inset 0 0 0 1.5px var(--r-line), 0 0 0 0 var(--r-sh); }
            50%{ box-shadow:inset 0 0 0 1.5px var(--r-line), 0 0 0 7px rgba(255,59,48,0); }
        }
        .audio-info{ flex:1; min-width:0; }
        .audio-title{ font-size:13.5px; font-weight:800; color:var(--text); }
        .audio-sub{ font-size:12px; font-weight:600; color:var(--text-2); margin-top:1px; font-variant-numeric:tabular-nums; }
        .audio-player-row{ display:flex; align-items:center; gap:10px; }
        .audio-player-row audio, .audio-player-row-new{ flex:1; min-width:0; height:34px; }
        .mini-remove{ display:flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:var(--r-ink); white-space:nowrap; cursor:pointer; }
        .mini-remove input{ width:15px; height:15px; accent-color:var(--red); margin:0; }

        /* ---------------- Categoria / Esempi ---------------- */
        .cat-btn{
            width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px;
            background:var(--surface-2); border:none; border-radius:13px; padding:14px 15px; cursor:pointer;
            font-family:inherit; font-size:15px; font-weight:700; color:var(--text);
            box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring);
        }
        .cat-btn:active{ transform:scale(.98); }
        .cat-btn-empty{ color:var(--text-3); font-weight:600; }
        .cat-btn svg{ color:var(--text-3); flex-shrink:0; }
        .inherited-note{
            text-align:center; padding:14px; border-radius:13px; background:var(--surface-2);
            color:var(--text-2); font-size:13.5px; font-style:italic; box-shadow:inset 0 0 0 .5px var(--hairline);
        }

        .ex-btn{
            width:100%; display:flex; align-items:center; justify-content:center; gap:8px;
            border:none; border-radius:13px; padding:14px; cursor:pointer; font-family:inherit;
            font-size:14.5px; font-weight:800; transition:transform .15s var(--spring);
        }
        .ex-btn:active{ transform:scale(.97); }
        .ex-btn-filled{ background:var(--p-fill); color:var(--p-ink); box-shadow:inset 0 0 0 1.5px var(--p-line); }
        .ex-btn-empty{ background:var(--surface-2); color:var(--text-2); box-shadow:inset 0 0 0 .5px var(--hairline); }
        .ex-badge{
            display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px;
            border-radius:99px; background:var(--p-ink); color:#fff; font-size:11.5px; font-weight:800;
        }

        /* ---------------- Barra azioni fissa in basso ---------------- */
        .bottom-bar{
            position:fixed; left:0; right:0; bottom:0; z-index:500;
            padding:12px 16px calc(10px + var(--safe-bottom));
            background:var(--header-glass);
            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);
            border-top:.5px solid var(--hairline);
        }
        .bottom-bar-row{ display:flex; gap:10px; max-width:620px; margin:0 auto; }
        .btn-cancel{
            flex:0 0 auto; display:flex; align-items:center; justify-content:center; padding:15px 18px;
            border:none; border-radius:15px; cursor:pointer; font-family:inherit; font-size:15.5px; font-weight:700;
            color:var(--text-2); background:var(--chip-bg); box-shadow:var(--pill-hl), var(--raise-sm);
            transition:transform .15s var(--spring);
        }
        .btn-cancel:active{ transform:scale(.96); }
        .btn-save{
            flex:1; display:flex; align-items:center; justify-content:center; gap:8px; padding:15px;
            border:none; border-radius:15px; cursor:pointer; font-family:inherit; font-size:16px; font-weight:800;
            letter-spacing:-.01em; color:#fff;
            background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 16%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%));
            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px rgba(52,199,89,.55);
            transition:transform .16s var(--spring), filter .2s;
        }
        .btn-save:active{ transform:translateY(1px) scale(.99); filter:brightness(.97); }
        .btn-save:disabled{ opacity:.7; }
        .btn-delete-link{
            display:block; width:100%; text-align:center; margin-top:9px; border:none; background:none; cursor:pointer;
            font-family:inherit; font-size:13px; font-weight:700; color:var(--text-3); padding:4px; transition:color .2s;
        }
        .btn-delete-link:active{ color:var(--r-ink); }

        /* ---------------- Modale guida ---------------- */
        .modal-overlay{
            display:none; position:fixed; inset:0; z-index:900;
            background:rgba(0,0,0,.5); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
            justify-content:center; align-items:flex-end;
        }
        .modal-content{
            background:var(--surface); color:var(--text); padding:28px 20px calc(24px + var(--safe-bottom));
            border-radius:26px 26px 0 0; max-width:540px; width:100%; position:relative;
            max-height:88vh; overflow-y:auto; box-shadow:0 -12px 50px rgba(0,0,0,.4);
            animation:sheetUp .42s var(--spring) both;
        }
        .modal-content::before{ content:""; position:absolute; top:9px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
        .close-btn{
            position:absolute; top:14px; right:16px; font-size:16px; cursor:pointer; color:var(--text-2);
            border:none; background:var(--surface-2); font-weight:700; width:32px; height:32px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
        }
        .modal-title{ margin:0 0 6px; font-size:19px; font-weight:800; letter-spacing:-.02em; }
        .modal-sub{ margin:0 0 16px; font-size:14px; color:var(--text-2); line-height:1.5; }
        .guide-list{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:13px; }
        .guide-list li{ display:flex; align-items:center; gap:12px; font-size:14px; color:var(--text); line-height:1.4; }
        .g-ic{
            flex-shrink:0; min-width:34px; height:30px; display:flex; align-items:center; justify-content:center;
            background:var(--surface-2); border-radius:9px; font-size:13px; font-weight:800;
            box-shadow:inset 0 0 0 .5px var(--hairline); font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        }
        .modal-note{ margin:16px 0 0; font-size:12.5px; color:var(--text-3); font-style:italic; }

        hr.fc-sep{ border:0; border-top:.5px solid var(--hairline); margin:20px 0; }

        .empty-state{
            text-align:center; padding:60px 20px; color:var(--text-2); font-size:15px; font-weight:600;
        }

        @keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } }

        @media (min-width:640px){
            .modal-overlay{ align-items:center; padding:20px; }
            .modal-content{ border-radius:26px; }
        }
    </style>
</head>
<body>

<?php
    include 'sidebar.html';

    // ============================================================
    // Funzioni di supporto per generare i blocchi HTML ripetuti
    // (domanda / risposta) senza duplicare markup — nessuna logica
    // di business qui dentro, solo presentazione.
    // ============================================================

    function fc_image_block($p, $img) {
        ?>
        <div class="img-compare">
            <div class="img-box">
                <span class="img-tag">Attuale</span>
                <?php if (!empty($img)): ?>
                    <img src="fc_Images/<?php echo htmlspecialchars($img); ?>">
                <?php else: ?>
                    <div class="img-empty">Nessuna immagine</div>
                <?php endif; ?>
            </div>
            <div class="img-box img-box-new">
                <span class="img-tag img-tag-new">Nuova</span>
                <img id="<?php echo $p; ?>_preview" style="display:none;">
                <div id="<?php echo $p; ?>_no_img" class="img-empty img-empty-warn" style="display:none;">Senza immagine</div>
            </div>
        </div>
        <div class="file-row">
            <input type="file" name="<?php echo $p; ?>New_image" id="<?php echo $p; ?>New_image" accept="image/*" style="display:none;">
            <label for="<?php echo $p; ?>New_image" class="file-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                Scegli foto
            </label>
        </div>
        <div class="control-panel">
            <label class="chk-row chk-blue"><input type="checkbox" name="replace_<?php echo $p; ?>_img" id="replace_<?php echo $p; ?>_img" value="1"> Sostituisci immagine</label>
            <label class="chk-row chk-red"><input type="checkbox" name="remove_<?php echo $p; ?>_img" id="remove_<?php echo $p; ?>_img" value="1" onchange="toggleImgRemove('<?php echo $p; ?>')"> Rimuovi immagine</label>
        </div>
        <?php
    }

    function fc_audio_block($p, $label, $audioFile) {
        ?>
        <div class="audio-card">
            <div class="audio-row">
                <button type="button" class="rec-btn" id="btn_rec_<?php echo $p; ?>" onclick="toggleRecord('<?php echo $p; ?>')" aria-label="Registra audio">
                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>
                </button>
                <div class="audio-info">
                    <div class="audio-title"><?php echo htmlspecialchars($label); ?></div>
                    <div class="audio-sub" id="rec_status_<?php echo $p; ?>"><?php echo !empty($audioFile) ? 'Audio presente' : 'Tocca per registrare'; ?></div>
                </div>
            </div>
            <?php if (!empty($audioFile)): ?>
                <div class="audio-player-row">
                    <audio src="fc_audio/<?php echo htmlspecialchars($audioFile); ?>" controls></audio>
                    <label class="mini-remove"><input type="checkbox" name="remove_<?php echo $p; ?>_aud" value="1"> Elimina</label>
                </div>
            <?php endif; ?>
            <audio id="audio_preview_<?php echo $p; ?>" controls style="display:none;" class="audio-player-row-new"></audio>
        </div>
        <?php
    }

    // CARICAMENTO RECORD IN EDIT MODE (GET)
    if (isset($_GET['fc_id']) || isset($_GET['child_id'])) {

        $is_child = isset($_GET['child_id']);
        $id = $is_child ? (int)$_GET['child_id'] : (int)$_GET['fc_id'];
        $prev_cat = isset($_GET['previous_catid']) ? (int)$_GET['previous_catid'] : 0;

        // --- NUOVA QUERY PROTETTA (Cerca il record SOLO se appartiene all'utente) ---
        if ($is_child) {
            $query_get = "SELECT t.* FROM fc_child_cards t 
                          JOIN fc_images i ON t.parent_id = i.id 
                          JOIN fc_categories c ON i.category_id = c.id 
                          WHERE t.id = $id AND c.user_id = $current_user_id";
        } else {
            $query_get = "SELECT t.* FROM fc_images t 
                          JOIN fc_categories c ON t.category_id = c.id 
                          WHERE t.id = $id AND c.user_id = $current_user_id";
        }
        
        $res = mysqli_query($conn, $query_get);
        $row = mysqli_fetch_assoc($res);
        // ---------------------------------------------------------------------------

        if ($row) {
            $category_id = $is_child ? $prev_cat : $row['category_id'];
            $ex_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_examples WHERE fc_image_id = $id");
            $ex_count = $ex_q ? mysqli_fetch_assoc($ex_q)['c'] : 0;

            $cancel_url = isset($_GET['return_to_game'])
                ? "game_fc.php?game_id=" . $_GET['game_id'] . "&position=" . $_GET['position'] . "&status=" . $_GET['status'] . "&order=" . $_GET['order']
                : "visualizzazione_fc.php?category_id=" . $category_id;
            ?>

            <div class="app-header">
                <div class="hd-eyebrow">MODIFICA SCHEDA</div>
                <h1>Domanda &amp; Risposta<?php if ($is_child) echo ' <span class="child-badge">↳ Figlio</span>'; ?></h1>
            </div>

            <div class="page">
            <form id="editFcForm" method="POST" enctype="multipart/form-data">

                <input type="hidden" name="return_to_game" value="<?php echo isset($_GET['return_to_game']) ? 1 : 0; ?>">
                <input type="hidden" name="game_id" value="<?php echo isset($_GET['game_id']) ? (int)$_GET['game_id'] : (isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0); ?>">
                <input type="hidden" name="position" value="<?php echo isset($_GET['position']) ? (int)$_GET['position'] : (isset($_POST['position']) ? (int)$_POST['position'] : 0); ?>">
                <input type="hidden" name="status" value="<?php echo isset($_GET['status']) ? htmlspecialchars($_GET['status']) : (isset($_POST['status']) ? htmlspecialchars($_POST['status']) : '0'); ?>">
                <input type="hidden" name="order" value="<?php echo isset($_GET['order']) ? htmlspecialchars($_GET['order']) : (isset($_POST['order']) ? htmlspecialchars($_POST['order']) : 'random'); ?>">

                <?php if ($is_child): ?>
                    <input type="hidden" name="child_id" value="<?php echo $id ?>">
                <?php else: ?>
                    <input type="hidden" name="fc_id" value="<?php echo $id ?>">
                <?php endif; ?>
                <input type="hidden" name="previous_catid" value="<?php echo $prev_cat; ?>">

                <!-- ============== CARD DOMANDA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head">
                        <span class="fc-card-title">Domanda</span>
                        <button type="button" class="pill-help" onclick="openAgenda()">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                            Guida
                        </button>
                    </div>

                    <?php
                    $ft_field = 'quest';
                    $ft_raw = $row['question_desc'] ?? '';
                    $ft_placeholder = 'Scrivi qui la domanda…';
                    include 'format_toolbar.php';
                    ?>

                    <?php fc_image_block('q', $row['question_image']); ?>
                    <?php fc_audio_block('q', 'Vocale Domanda', $row['question_audio']); ?>
                </section>

                <!-- ============== CARD RISPOSTA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head">
                        <span class="fc-card-title">Risposta</span>
                        <button type="button" class="pill-help" onclick="openAgenda()">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                            Guida
                        </button>
                    </div>

                    <?php
                    $ft_field = 'answ';
                    $ft_raw = $row['answer_desc'] ?? '';
                    $ft_placeholder = 'Scrivi qui la risposta…';
                    include 'format_toolbar.php';
                    ?>

                    <?php fc_image_block('a', $row['answer_image']); ?>
                    <?php fc_audio_block('a', 'Vocale Risposta', $row['answer_audio']); ?>
                </section>

                <!-- ============== ESEMPI ============== -->
                <section class="fc-card fc-card-tight">
                    <?php if ($ex_count > 0): ?>
                        <button type="button" class="ex-btn ex-btn-filled" onclick="openExamplesModal(<?php echo $id; ?>, document.getElementById('quest').value, document.getElementById('answ').value)">
                            💡 Gestisci Esempi <span class="ex-badge"><?php echo $ex_count; ?></span>
                        </button>
                    <?php else: ?>
                        <button type="button" class="ex-btn ex-btn-empty" onclick="openExamplesModal(<?php echo $id; ?>, document.getElementById('quest').value, document.getElementById('answ').value)">
                            ➕ Aggiungi Esempio
                        </button>
                    <?php endif; ?>
                </section>

                <!-- ============== CATEGORIA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Categoria</span></div>
                    <?php if (!$is_child): 
                        // Configuriamo le variabili per il nuovo componente "Plug & Play"
                        $cs_id = 'modifica_fc'; // ID univoco per sbloccare la modalità "moderna"
                        $cs_selected_id = $category_id;
                        $cs_selected_name = (!empty($category_id) && isset($categories[$category_id])) ? $categories[$category_id] : '';
                        $cs_placeholder = 'Scegli una categoria';
                        
                        // Il file genererà automaticamente il bottone, l'input nascosto e il popup!
                        include 'category_selector.php'; 
                    ?>
                    <?php else: ?>
                        <div class="inherited-note">Categoria ereditata dalla carta principale</div>
                    <?php endif; ?>
                </section>

                <!-- ============== BARRA AZIONI ============== -->
                <div class="bottom-bar">
                    <div class="bottom-bar-row">
                        <button type="button" class="btn-cancel" onclick="window.location.href='<?php echo htmlspecialchars($cancel_url); ?>'">Annulla</button>
                        <button type="submit" name="save" value="1" class="btn-save">Salva Modifiche</button>
                    </div>
                    <button type="submit" name="delete" value="1" class="btn-delete-link" onclick="return confirm('Eliminare definitivamente questa scheda?');">Elimina definitivamente</button>
                </div>

            </form>
            </div>

            <?php
        } else {
            echo "<div class='page'><div class='empty-state'>Scheda non trovata.</div></div>";
        }
    } else {
        echo "<div class='page'><div class='empty-state'>ID mancante.</div></div>";
    }

    mysqli_close($conn);
    ?>

    <div id="agendaModal" class="modal-overlay">
        <div class="modal-content">
            <button class="close-btn" onclick="closeAgenda()">&times;</button>
            <h3 class="modal-title">Guida rapida</h3>
            <p class="modal-sub">Scrivi direttamente nel testo: seleziona una parola e tocca un pulsante per formattarla, proprio come in Word.</p>
            <ul class="guide-list">
                <li><span class="g-ic" style="color:var(--red)">A</span> Colora il testo selezionato (rosso, blu, verde, viola, arancione)</li>
                <li><span class="g-ic"><b>B</b></span> Grassetto, corsivo e sottolineato sul testo selezionato</li>
                <li><span class="g-ic">🖊</span> Evidenzia il testo selezionato</li>
                <li><span class="g-ic">≡</span> Crea un elenco puntato — premi Tab per un sotto-punto (nessun limite di profondità), Maiusc+Tab per risalire, Invio per un nuovo punto</li>
                <li><span class="g-ic">🎨</span> Con il cursore in un elenco, scegli il colore della sua barra laterale</li>
                <li><span class="g-ic">$x$</span> Formula in linea: scrivi il codice LaTeX tra i simboli — esci dal campo per vederla renderizzata</li>
                <li><span class="g-ic">$$x$$</span> Formula evidenziata, a capo</li>
                <li><span class="g-ic">&lt;/&gt;</span> Passa al testo grezzo per modificarlo direttamente, poi ripremi per tornare alla vista formattata</li>
            </ul>
            <p class="modal-note">Tocca una formula già inserita per modificarla di nuovo.</p>
        </div>
    </div>

    <script>
    // ============================================================
    // MOTORE TESTO-TAG <-> AST, TOOLBAR ED EDITOR: ora vivono in
    // fc_parser.js e format_toolbar.php (riutilizzabili altrove).
    // Qui restano solo le parti specifiche di questa pagina.
    // ============================================================

    // ============================================================
    // COMPATIBILITÀ: funzioni storiche usate anche altrove
    // (agenda, esempi, anteprima immagini)
    // ============================================================
    function openAgenda() { document.getElementById('agendaModal').style.display = 'flex'; }
    function closeAgenda() { document.getElementById('agendaModal').style.display = 'none'; }

    var mathJaxTimeouts = {};
    function updatePreview(textarea, previewId) {
        var previewEl = document.getElementById(previewId);
        if (previewEl && typeof parseTextForPreview === 'function') {
            previewEl.innerHTML = parseTextForPreview(textarea.value);
            clearTimeout(mathJaxTimeouts[previewId]);
            mathJaxTimeouts[previewId] = setTimeout(function () { fcTypesetMath([previewEl]); }, 400);
        }
    }

    function setupPreview(inputId, imgId, checkId) {
        var inputEl = document.getElementById(inputId);
        if (!inputEl) return;
        inputEl.addEventListener('change', function (e) {
            if (this.files && this.files[0]) {
                var r = new FileReader();
                r.onload = function (ex) {
                    var img = document.getElementById(imgId);
                    img.src = ex.target.result;
                    img.style.display = 'inline-block';
                    document.getElementById(inputId.startsWith('q') ? 'q_no_img' : 'a_no_img').style.display = 'none';
                    document.getElementById(checkId).checked = true;
                };
                r.readAsDataURL(this.files[0]);
            }
        });
    }
    setupPreview('qNew_image', 'q_preview', 'replace_q_img');
    setupPreview('aNew_image', 'a_preview', 'replace_a_img');

    function toggleImgRemove(type) {
        var removed = document.getElementById('remove_' + type + '_img').checked;
        if (removed) {
            document.getElementById(type + '_preview').style.display = 'none';
            document.getElementById(type + '_no_img').style.display = 'block';
            document.getElementById(type + 'New_image').value = '';
            document.getElementById('replace_' + type + '_img').checked = false;
        } else {
            document.getElementById(type + '_no_img').style.display = 'none';
        }
    }

    // ============================================================
    // REGISTRAZIONE AUDIO
    // ============================================================
    var recQ, recA;
    var chunksQ = [], chunksA = [];
    var blobQ = null, blobA = null;
    var extQ = 'webm', extA = 'webm';
    var timerQ = null, timerA = null, secQ = 0, secA = 0;

    function fcFmtTime(s) {
        var m = Math.floor(s / 60), ss = s % 60;
        return m + ':' + String(ss).padStart(2, '0');
    }

    async function toggleRecord(type) {
        var btn = document.getElementById('btn_rec_' + type);
        var statusEl = document.getElementById('rec_status_' + type);
        var isRecording = btn.classList.contains('recording');

        if (!isRecording) {
            var stream;
            try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
            catch (err) { alert('Impossibile accedere al microfono.'); return; }
            var rec = new MediaRecorder(stream);
            if (type === 'q') { recQ = rec; chunksQ = []; } else { recA = rec; chunksA = []; }
            rec.ondataavailable = function (e) { (type === 'q' ? chunksQ : chunksA).push(e.data); };
            rec.onstop = function () {
                var chunks = type === 'q' ? chunksQ : chunksA;
                var ext = rec.mimeType.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                var blob = new Blob(chunks, { type: rec.mimeType });
                if (type === 'q') { blobQ = blob; extQ = ext; } else { blobA = blob; extA = ext; }
                var player = document.getElementById('audio_preview_' + type);
                player.src = URL.createObjectURL(blob);
                player.style.display = 'block';
            };
            rec.start();
            btn.classList.add('recording');
            btn.querySelector('.ic-mic').style.display = 'none';
            btn.querySelector('.ic-stop').style.display = '';
            if (type === 'q') { secQ = 0; statusEl.textContent = '0:00'; timerQ = setInterval(function () { secQ++; statusEl.textContent = fcFmtTime(secQ); }, 1000); }
            else { secA = 0; statusEl.textContent = '0:00'; timerA = setInterval(function () { secA++; statusEl.textContent = fcFmtTime(secA); }, 1000); }
        } else {
            if (type === 'q') { recQ.stop(); recQ.stream.getTracks().forEach(function (t) { t.stop(); }); clearInterval(timerQ); }
            else { recA.stop(); recA.stream.getTracks().forEach(function (t) { t.stop(); }); clearInterval(timerA); }
            btn.classList.remove('recording');
            btn.querySelector('.ic-mic').style.display = '';
            btn.querySelector('.ic-stop').style.display = 'none';
            statusEl.textContent = 'Audio pronto';
        }
    }

    // ============================================================
    // WIRING GLOBALE + INVIO FORM
    // ============================================================
    // ============================================================
    // NOTA: il wiring della toolbar/editor (fcSetupToolbar, fcSetupEditor,
    // idratazione iniziale, tocco su una formula per rieditarla, ecc.)
    // avviene già da solo dentro format_toolbar.php per ogni editor
    // presente in pagina — qui basta gestire l'invio del form.
    // ============================================================
    if (document.getElementById('editFcForm')) {
        document.getElementById('editFcForm').addEventListener('submit', async function (e) {
            var activeSubmit = e.submitter ? e.submitter.name : '';
            if (activeSubmit === 'delete') return true;

            e.preventDefault();

            fcSyncAllEditors();

            var subBtn = this.querySelector('button[name="save"]');
            var originalText = subBtn.innerHTML;
            subBtn.disabled = true;
            subBtn.innerHTML = 'Salvataggio in corso…';

            var fd = new FormData(this);
            if (blobQ) fd.append('audio_q', blobQ, 'q.' + extQ);
            if (blobA) fd.append('audio_a', blobA, 'a.' + extA);

            try {
                var res = await fetch('modifica_immagine_fc.php', { method: 'POST', body: fd });
                var text = await res.text();
                document.open();
                document.write(text);
                document.close();
            } catch (err) {
                alert('Errore durante il salvataggio.');
                subBtn.disabled = false;
                subBtn.innerHTML = originalText;
            }
        });
    }
    </script>

    <?php include 'examples_modal.php'; ?>

</body>
</html>