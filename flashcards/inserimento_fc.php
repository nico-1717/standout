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

    // ============================================================
    // GESTORE INSERIMENTO (AJAX)
    // ============================================================
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert') {
        header('Content-Type: application/json');

        $catID = isset($_POST['category_id']) ? (int)$_POST['category_id'] : (isset($_POST['category']) ? (int)$_POST['category'] : 0);

        // --- CONTROLLO PROPRIETÀ CATEGORIA (SICUREZZA) ---
        if ($catID <= 0 || !isset($categories[$catID])) {
            echo json_encode(['success' => false, 'error' => 'Categoria non valida o non tua.']);
            mysqli_close($conn);
            exit();
        }

        $quest = mysqli_real_escape_string($conn, trim($_POST['quest'] ?? ''));
        $answ  = mysqli_real_escape_string($conn, trim($_POST['answ'] ?? ''));

        $has_q_img = isset($_FILES['question_image']) && $_FILES['question_image']['name'] != '';
        $has_a_img = isset($_FILES['answer_image']) && $_FILES['answer_image']['name'] != '';
        $has_q_aud = isset($_FILES['audio_q']) && $_FILES['audio_q']['size'] > 0;
        $has_a_aud = isset($_FILES['audio_a']) && $_FILES['audio_a']['size'] > 0;

        // --- CONTROLLO CONTENUTO MINIMO ---
        if ($quest === '' && $answ === '' && !$has_q_img && !$has_a_img && !$has_q_aud && !$has_a_aud) {
            echo json_encode(['success' => false, 'empty' => true, 'error' => 'Aggiungi almeno del testo, un\'immagine o un audio prima di salvare.']);
            mysqli_close($conn);
            exit();
        }

        $question_image = "";
        if ($has_q_img) {
            $question_image = time() . "_q_" . $_FILES["question_image"]["name"];
            move_uploaded_file($_FILES["question_image"]["tmp_name"], FC_IMAGE_PATH_INSERT . $question_image);
        }

        $answer_image = "";
        if ($has_a_img) {
            $answer_image = time() . "_a_" . $_FILES["answer_image"]["name"];
            move_uploaded_file($_FILES["answer_image"]["tmp_name"], FC_IMAGE_PATH_INSERT . $answer_image);
        }

        // Gestione AUDIO registrato dal browser (supporto dinamico .webm e .m4a)
        if (!is_dir('fc_audio')) { mkdir('fc_audio', 0777, true); }

        $question_audio = "";
        if ($has_q_aud) {
            $ext_q = pathinfo($_FILES['audio_q']['name'], PATHINFO_EXTENSION);
            $question_audio = "audio_q_" . time() . "." . $ext_q;
            move_uploaded_file($_FILES['audio_q']['tmp_name'], 'fc_audio/' . $question_audio);
        }

        $answer_audio = "";
        if ($has_a_aud) {
            $ext_a = pathinfo($_FILES['audio_a']['name'], PATHINFO_EXTENSION);
            $answer_audio = "audio_a_" . time() . "." . $ext_a;
            move_uploaded_file($_FILES['audio_a']['tmp_name'], 'fc_audio/' . $answer_audio);
        }

        // TROVA L'ORDINE MASSIMO
        $max_res = mysqli_query($conn, "SELECT MAX(custom_order) as m FROM fc_images WHERE category_id = $catID");
        $max_o = intval(mysqli_fetch_assoc($max_res)['m']) + 1;

        $queryInsert = "INSERT INTO fc_images (question_image, answer_image, question_desc, answer_desc, category_id, question_audio, answer_audio, custom_order)
                        VALUES ('$question_image', '$answer_image', '$quest', '$answ', $catID, '$question_audio', '$answer_audio', $max_o)";

        if (mysqli_query($conn, $queryInsert)) {
            $_SESSION['fc_success_msg'] = "Flashcard salvata correttamente!";
            $_SESSION['fc_last_category'] = $catID;
            echo json_encode(['success' => true, 'category_id' => $catID]);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        mysqli_close($conn);
        exit();
    }

    // ============================================================
    // MESSAGGIO DI SUCCESSO (flash post-redirect)
    // ============================================================
    $success_message = "";
    if (isset($_SESSION['fc_success_msg'])) {
        $success_message = $_SESSION['fc_success_msg'];
        unset($_SESSION['fc_success_msg']);
    }

    $last_fc_cat = isset($_SESSION['fc_last_category']) ? $_SESSION['fc_last_category'] : '';

    // Se si arriva da visualizzazione_fc.php (o altrove) con ?category_id=..., quella
    // categoria ha priorità e diventa anche la nuova "ultima categoria" in sessione.
    // La presenza esplicita di ?category_id= indica anche la provenienza, usata per "Annulla".
    $came_from_view = isset($_GET['category_id']);
    if ($came_from_view) {
        $gcid = intval($_GET['category_id']);
        if ($gcid > 0 && isset($categories[$gcid])) {
            $last_fc_cat = $gcid;
            $_SESSION['fc_last_category'] = $gcid;
        } else {
            $came_from_view = false;
        }
    }
    $view_cat_id = $came_from_view ? (int)$_GET['category_id'] : 0;

    // ============================================================
    // Funzioni di supporto per generare i blocchi HTML ripetuti
    // ============================================================
    function fc_new_image_block($p, $label) {
        ?>
        <div class="up-label"><?php echo htmlspecialchars($label); ?></div>
        <div class="file-row">
            <input type="file" name="<?php echo $p; ?>_image" id="<?php echo $p; ?>_image" accept="image/*" style="display:none;">
            <label for="<?php echo $p; ?>_image" class="file-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                Scegli foto
            </label>
        </div>
        <div class="img-upload-box" id="<?php echo $p; ?>_img_wrap" style="display:none;">
            <img id="<?php echo $p; ?>_preview">
            <button type="button" class="mini-remove" onclick="clearImage('<?php echo $p; ?>')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Rimuovi
            </button>
        </div>
        <?php
    }

    function fc_new_audio_block($p, $label) {
        ?>
        <div class="audio-card">
            <div class="audio-row">
                <button type="button" class="rec-btn" id="btn_rec_<?php echo $p; ?>" onclick="toggleRecord('<?php echo $p; ?>')" aria-label="Registra audio">
                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>
                </button>
                <div class="audio-info">
                    <div class="audio-title"><?php echo htmlspecialchars($label); ?></div>
                    <div class="audio-sub" id="rec_status_<?php echo $p; ?>">Tocca per registrare</div>
                </div>
            </div>
            <audio id="audio_preview_<?php echo $p; ?>" controls style="display:none;" class="audio-player-row-new"></audio>
        </div>
        <?php
    }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuova Flashcard · Materie</title>
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
           DESIGN SYSTEM — stesso linguaggio visivo di modifica_immagine_fc.php
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

        /* ---------------- Immagine (upload singolo) ---------------- */
        .up-label{ font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin-bottom:6px; }
        .file-row{ margin-bottom:10px; }
        .file-btn{
            display:inline-flex; align-items:center; gap:7px; cursor:pointer;
            background:var(--surface-2); color:var(--text); font-weight:700; font-size:13.5px;
            padding:9px 14px; border-radius:11px; box-shadow:inset 0 0 0 .5px var(--hairline);
            transition:transform .15s var(--spring);
        }
        .file-btn:active{ transform:scale(.96); }
        .img-upload-box{
            margin-top:10px; border-radius:14px; padding:10px; text-align:center;
            background:var(--blue-soft); box-shadow:inset 0 0 0 1.5px rgba(0,122,255,.4);
            display:flex; flex-direction:column; align-items:center; gap:8px;
        }
        .img-upload-box img{ max-width:100%; max-height:120px; border-radius:9px; object-fit:contain; display:block; }
        .mini-remove{
            display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:var(--r-ink);
            white-space:nowrap; cursor:pointer; border:none; background:none; padding:4px 8px; font-family:inherit;
        }

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
        .audio-player-row-new{ width:100%; height:34px; }

        /* ---------------- Categoria ---------------- */
        .cat-btn{
            width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px;
            background:var(--surface-2); border:none; border-radius:13px; padding:14px 15px; cursor:pointer;
            font-family:inherit; font-size:15px; font-weight:700; color:var(--text);
            box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring);
        }
        .cat-btn:active{ transform:scale(.98); }

        /* ---------------- CTA Inserimento multiplo (Big Insert) ---------------- */
        .cta-insert{
            display:flex; align-items:center; gap:14px; width:100%; text-decoration:none; border:none; cursor:pointer;
            font-family:inherit; text-align:left;
            background:linear-gradient(135deg, color-mix(in srgb, var(--purple), #fff 10%), var(--purple) 55%, color-mix(in srgb, var(--purple), #000 14%));
            border-radius:var(--r-card); padding:16px;
            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 14px 30px -10px rgba(175,82,222,.55);
            transition:transform .16s var(--spring), filter .2s;
        }
        .cta-insert:active{ transform:translateY(1px) scale(.99); filter:brightness(.96); }
        .cta-insert-icon{
            flex-shrink:0; width:46px; height:46px; border-radius:14px; background:rgba(255,255,255,.22);
            display:flex; align-items:center; justify-content:center; color:#fff;
        }
        .cta-insert-text{ flex:1; min-width:0; }
        .cta-insert-title{ font-size:15.5px; font-weight:800; color:#fff; letter-spacing:-.01em; }
        .cta-insert-sub{ font-size:12.5px; font-weight:600; color:rgba(255,255,255,.88); margin-top:2px; }
        .cta-insert-chev{ color:rgba(255,255,255,.9); flex-shrink:0; }

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

        .max-wrap{ max-width:620px; margin:0 auto; }

        /* ---------------- Banner di validazione ---------------- */
        .alert-banner{
            display:flex; align-items:center; gap:8px; background:var(--r-fill); color:var(--r-ink);
            border-radius:13px; padding:11px 14px; font-size:13px; font-weight:700; margin-bottom:10px;
            box-shadow:inset 0 0 0 1px var(--r-line); animation:bannerIn .32s var(--spring);
        }
        .alert-banner svg{ flex-shrink:0; }
        @keyframes bannerIn{ from{ opacity:0; transform:translateY(6px); } }
        @keyframes shakeX{
            10%,90%{ transform:translateX(-1px); } 20%,80%{ transform:translateX(2px); }
            30%,50%,70%{ transform:translateX(-4px); } 40%,60%{ transform:translateX(4px); }
        }
        .shake{ animation:shakeX .5s; }

        /* ---------------- Toast di successo ---------------- */
        .success-toast{
            position:fixed; top:calc(var(--safe-top) + 10px); left:50%; transform:translate(-50%,-14px);
            background:var(--g-fill); color:var(--g-ink); padding:11px 20px; border-radius:99px; font-weight:800;
            font-size:13.5px; box-shadow:var(--shadow-card), inset 0 0 0 1px var(--g-line); z-index:1000;
            display:flex; align-items:center; gap:8px; opacity:0;
            animation:toastIn .42s var(--spring) forwards, toastOut .4s var(--spring) 2.8s forwards;
        }
        @keyframes toastIn{ to{ opacity:1; transform:translate(-50%,0); } }
        @keyframes toastOut{ to{ opacity:0; transform:translate(-50%,-14px); } }

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

        @keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } }

        @media (min-width:640px){
            .modal-overlay{ align-items:center; padding:20px; }
            .modal-content{ border-radius:26px; }
        }
    </style>
</head>
<body>

<?php include 'sidebar.html'; ?>

<div class="app-header">
    <div class="hd-eyebrow">NUOVA SCHEDA</div>
    <h1>Domanda &amp; Risposta</h1>
</div>

<div class="page">
    <form id="fcForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="insert">

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
            $ft_raw = '';
            $ft_placeholder = 'Scrivi qui la domanda…';
            include 'format_toolbar.php';
            ?>

            <?php fc_new_image_block('question', 'Immagine domanda (opzionale)'); ?>
            <?php fc_new_audio_block('q', 'Vocale Domanda'); ?>
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
            $ft_raw = '';
            $ft_placeholder = 'Scrivi qui la risposta…';
            include 'format_toolbar.php';
            ?>

            <?php fc_new_image_block('answer', 'Immagine risposta (opzionale)'); ?>
            <?php fc_new_audio_block('a', 'Vocale Risposta'); ?>
        </section>

        <!-- ============== CATEGORIA ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title">Categoria</span></div>
            <?php
                $cs_id = 'insert_fc';
                $cs_selected_id = $last_fc_cat;
                $cs_selected_name = (!empty($last_fc_cat) && isset($categories[$last_fc_cat])) ? $categories[$last_fc_cat] : '';
                $cs_placeholder = 'Scegli una categoria';
                include 'category_selector.php';
            ?>
        </section>

        <!-- ============== BIG INSERT ============== -->
        <section class="fc-card fc-card-tight">
            <a id="bigInsertLink" class="cta-insert" href="inserimento_big_fc.php<?php echo !empty($last_fc_cat) ? ('?category_id=' . (int)$last_fc_cat) : ''; ?>">
                <span class="cta-insert-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="14" height="4" rx="1"/><rect x="7" y="10" width="14" height="4" rx="1"/><rect x="3" y="16" width="14" height="4" rx="1"/></svg>
                </span>
                <span class="cta-insert-text">
                    <span class="cta-insert-title">Inserimento multiplo</span>
                    <span class="cta-insert-sub">Crea tante flashcard in sequenza, più veloce</span>
                </span>
                <svg class="cta-insert-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </section>

        <!-- ============== BARRA AZIONI ============== -->
        <div class="bottom-bar">
            <div class="max-wrap">
                <div id="emptyBanner" class="alert-banner" style="display:none;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                    <span id="emptyBannerText">Aggiungi almeno del testo, un'immagine o un audio prima di salvare.</span>
                </div>
            </div>
            <div class="bottom-bar-row">
                <?php if ($came_from_view): ?>
                    <button type="button" class="btn-cancel" onclick="window.location.href='visualizzazione_fc.php?category_id=<?php echo $view_cat_id; ?>'">Annulla</button>
                <?php else: ?>
                    <button type="button" class="btn-cancel" onclick="goBackOrIndex()">Annulla</button>
                <?php endif; ?>
                <button type="submit" name="save" value="1" class="btn-save" id="submitBtn">Inserisci Flashcard</button>
            </div>
        </div>

    </form>
</div>

<?php if (!empty($success_message)): ?>
<div id="successToast" class="success-toast">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    <span><?php echo htmlspecialchars($success_message); ?></span>
</div>
<?php endif; ?>

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
// GUIDA
// ============================================================
function openAgenda() { document.getElementById('agendaModal').style.display = 'flex'; }
function closeAgenda() { document.getElementById('agendaModal').style.display = 'none'; }

// ============================================================
// ANTEPRIME IMMAGINI
// ============================================================
function setupPreview(inputId, wrapId, imgId) {
    var inputEl = document.getElementById(inputId);
    if (!inputEl) return;
    inputEl.addEventListener('change', function (e) {
        if (this.files && this.files[0]) {
            var r = new FileReader();
            r.onload = function (ex) {
                document.getElementById(imgId).src = ex.target.result;
                document.getElementById(wrapId).style.display = 'flex';
            };
            r.readAsDataURL(this.files[0]);
        }
    });
}
setupPreview('question_image', 'question_img_wrap', 'question_preview');
setupPreview('answer_image', 'answer_img_wrap', 'answer_preview');

function clearImage(p) {
    document.getElementById(p + '_image').value = '';
    document.getElementById(p + '_img_wrap').style.display = 'none';
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
// ANNULLA — torna a visualizzazione_fc (già gestito lato server)
// oppure torna indietro nella cronologia, altrimenti index.php
// ============================================================
function goBackOrIndex() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = 'index.php';
    }
}

// ============================================================
// VALIDAZIONE + INVIO FORM (AJAX, con audio registrato)
// ============================================================
function isFormEmpty() {
    var q = document.getElementById('quest') ? document.getElementById('quest').value.trim() : '';
    var a = document.getElementById('answ') ? document.getElementById('answ').value.trim() : '';
    var qImg = document.getElementById('question_image').files.length > 0;
    var aImg = document.getElementById('answer_image').files.length > 0;
    return !q && !a && !qImg && !aImg && !blobQ && !blobA;
}

function showEmptyBanner() {
    var banner = document.getElementById('emptyBanner');
    banner.style.display = 'flex';
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.classList.remove('shake');
    void submitBtn.offsetWidth;
    submitBtn.classList.add('shake');
}

document.getElementById('fcForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (typeof fcSyncAllEditors === 'function') { fcSyncAllEditors(); }

    if (isFormEmpty()) {
        showEmptyBanner();
        return;
    }
    document.getElementById('emptyBanner').style.display = 'none';

    var subBtn = document.getElementById('submitBtn');
    var originalText = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = 'Salvataggio in corso…';

    var fd = new FormData(this);
    if (blobQ) fd.append('audio_q', blobQ, 'q.' + extQ);
    if (blobA) fd.append('audio_a', blobA, 'a.' + extA);

    try {
        var res = await fetch('inserimento_fc.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            var cat = data.category_id ? data.category_id : '';
            window.location.href = 'inserimento_fc.php' + (cat ? ('?category_id=' + cat) : '');
        } else if (data.empty) {
            showEmptyBanner();
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        } else {
            alert('Errore DB: ' + data.error);
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        }
    } catch (err) {
        alert('Errore di connessione.');
        subBtn.disabled = false;
        subBtn.innerHTML = originalText;
    }
});

// Tiene sincronizzato il link "Big Insert" se si cambia categoria dal modale
document.addEventListener('categoryselected', function (e) {
    var link = document.getElementById('bigInsertLink');
    if (link && e.detail && e.detail.id) {
        link.href = 'inserimento_big_fc.php?category_id=' + e.detail.id;
    }
});

// Toast di successo: auto-rimozione dal DOM dopo l'animazione
var toastEl = document.getElementById('successToast');
if (toastEl) {
    setTimeout(function () { toastEl.remove(); }, 3300);
}
</script>

</body>
</html>