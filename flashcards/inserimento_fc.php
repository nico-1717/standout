<?php
    require_once 'config.php';
    require_once 'auth_check.php';
    $current_user_id = $utente_corrente['id'];

    /* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
    $conn = sec_db();

    $categories = array();
    foreach (sec_all("SELECT id, name FROM fc_categories WHERE user_id = ? ORDER BY name",
                     [(int)$current_user_id]) as $row) { $categories[$row['id']] = $row['name']; }

    /* I tipi di audio ammessi: non sono immagini, quindi hanno un elenco loro.
       sec_file() guarda il contenuto vero con finfo, non l'estensione. */
    const FC_AUDIO_MIME = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
        'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
    ];

    /* Salva un allegato e restituisce il nome scritto dal server, o '' se non
       c'era niente o il contenuto non e' del tipo giusto. Il nome scelto
       dall'utente non viene mai usato: prima "x.php" finiva eseguibile nella
       cartella delle immagini. */
    function fc_salva($campo, $cartella, $audio) {
        if (!isset($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
        $ris = $audio ? sec_file($_FILES[$campo], FC_AUDIO_MIME, 10485760) : sec_file($_FILES[$campo]);
        if (empty($ris['ok'])) return '';
        if (!is_dir($cartella)) { @mkdir($cartella, 0755, true); }
        $nome = sec_safe_filename($ris['ext']);
        return move_uploaded_file($ris['tmp'], rtrim($cartella, '/') . '/' . $nome) ? $nome : '';
    }

    // ============================================================
    // GESTORE INSERIMENTO (AJAX)
    // ============================================================
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert') {
        header('Content-Type: application/json');

        /* Token di sessione, origine e limite di frequenza: prima non c'erano. */
        sec_guard_write((int)$current_user_id, 'fc_inserimento', 0, 120, 60);

        $catID = sec_int($_POST['category_id'] ?? $_POST['category'] ?? 0, 1);
        if (!sec_owns('fc_categories', $catID, (int)$current_user_id)) {
            echo json_encode(['success' => false, 'error' => t('fc.categoria_non_valida')]);
            exit();
        }

        $quest = sec_text($_POST['quest'] ?? '', 2000);
        $answ  = sec_text($_POST['answ'] ?? '', 2000);

        $has_q_img = isset($_FILES['question_image']) && $_FILES['question_image']['name'] != '';
        $has_a_img = isset($_FILES['answer_image']) && $_FILES['answer_image']['name'] != '';
        $has_q_aud = isset($_FILES['audio_q']) && $_FILES['audio_q']['size'] > 0;
        $has_a_aud = isset($_FILES['audio_a']) && $_FILES['audio_a']['size'] > 0;

        // --- CONTROLLO CONTENUTO MINIMO ---
        if ($quest === '' && $answ === '' && !$has_q_img && !$has_a_img && !$has_q_aud && !$has_a_aud) {
            echo json_encode(['success' => false, 'empty' => true, 'error' => t('fc.serve_qualcosa')]);
            exit();
        }

        $question_image = fc_salva('question_image', FC_IMAGE_PATH_INSERT, false);
        $answer_image   = fc_salva('answer_image',   FC_IMAGE_PATH_INSERT, false);
        $question_audio = fc_salva('audio_q', 'fc_audio', true);
        $answer_audio   = fc_salva('audio_a', 'fc_audio', true);

        // TROVA L'ORDINE MASSIMO
        $max_o = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_images WHERE category_id = ?",
                               [$catID])['m'] ?? 0) + 1;

        sec_q("INSERT INTO fc_images (question_image, answer_image, question_desc, answer_desc, category_id, question_audio, answer_audio, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
              [$question_image, $answer_image, $quest, $answ, $catID, $question_audio, $answer_audio, $max_o]);

        $_SESSION['fc_success_msg'] = t('fc.flashcard_salvata');
        $_SESSION['fc_last_category'] = $catID;
        echo json_encode(['success' => true, 'category_id' => $catID]);
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
        $gcid = sec_int($_GET['category_id'] ?? 0, 0);
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
        <div class="up-label"><?php echo sec_h($label); ?></div>
        <div class="file-row">
            <input type="file" name="<?php echo sec_h($p); ?>_image" id="<?php echo sec_h($p); ?>_image" accept="image/*" style="display:none;">
            <label for="<?php echo sec_h($p); ?>_image" class="file-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                <?php te('vocab.ins_scegli_foto'); ?>
            </label>
        </div>
        <div class="img-upload-box" id="<?php echo sec_h($p); ?>_img_wrap" style="display:none;">
            <img id="<?php echo sec_h($p); ?>_preview">
            <button type="button" class="mini-remove" onclick="clearImage('<?php echo sec_h($p); ?>')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php te('vocab.ins_rimuovi'); ?>
            </button>
        </div>
        <?php
    }

    function fc_new_audio_block($p, $label) {
        ?>
        <div class="audio-card">
            <div class="audio-row">
                <button type="button" class="rec-btn" id="btn_rec_<?php echo sec_h($p); ?>" onclick="toggleRecord('<?php echo sec_h($p); ?>')" aria-label="<?php te('fc.ifc_registra'); ?>">
                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>
                </button>
                <div class="audio-info">
                    <div class="audio-title"><?php echo sec_h($label); ?></div>
                    <div class="audio-sub" id="rec_status_<?php echo sec_h($p); ?>"><?php te('fc.ifc_tocca_registrare'); ?></div>
                </div>
            </div>
            <audio id="audio_preview_<?php echo sec_h($p); ?>" controls style="display:none;" class="audio-player-row-new"></audio>
        </div>
        <?php
    }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?php te('fc.ifc_titolo'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    <?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>
        window.MathJax = {
            tex: {
                inlineMath: [['$', '$'], ['\\(', '\\)']],
                displayMath: [['$$', '$$'], ['\\[', '\\]']]
            }
        };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>inserimento_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/inserimento_fc.css') ?: 1; ?>">
</head>
<body>

<?php include 'sidebar.html'; ?>

<div class="app-header">
    <div class="hd-eyebrow"><?php te('fc.ifc_nuova_scheda'); ?></div>
    <h1><?php te('fc.ifc_domanda_risposta'); ?></h1>
</div>

<div class="page">
    <form id="fcForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="insert">

        <!-- ============== CARD DOMANDA ============== -->
        <section class="fc-card">
            <div class="fc-card-head">
                <span class="fc-card-title"><?php te('fc.ifc_domanda'); ?></span>
                <button type="button" class="pill-help" onclick="openAgenda()">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                    <?php te('fc.ifc_guida'); ?>
                </button>
            </div>

            <?php
            $ft_field = 'quest';
            $ft_raw = '';
            $ft_placeholder = t('fc.ifc_ph_domanda');
            include 'format_toolbar.php';
            ?>

            <?php fc_new_image_block('question', t('fc.ifc_img_domanda')); ?>
            <?php fc_new_audio_block('q', t('fc.ifc_voc_domanda')); ?>
        </section>

        <!-- ============== CARD RISPOSTA ============== -->
        <section class="fc-card">
            <div class="fc-card-head">
                <span class="fc-card-title"><?php te('fc.ifc_risposta'); ?></span>
                <button type="button" class="pill-help" onclick="openAgenda()">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                    <?php te('fc.ifc_guida'); ?>
                </button>
            </div>

            <?php
            $ft_field = 'answ';
            $ft_raw = '';
            $ft_placeholder = t('fc.ifc_ph_risposta');
            include 'format_toolbar.php';
            ?>

            <?php fc_new_image_block('answer', t('fc.ifc_img_risposta')); ?>
            <?php fc_new_audio_block('a', t('fc.ifc_voc_risposta')); ?>
        </section>

        <!-- ============== CATEGORIA ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_categoria'); ?></span></div>
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
                    <span class="cta-insert-title"><?php te('vocab.ins_multiplo'); ?></span>
                    <span class="cta-insert-sub"><?php te('fc.ifc_multiplo_sub'); ?></span>
                </span>
                <svg class="cta-insert-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </section>

        <!-- ============== BARRA AZIONI ============== -->
        <div class="bottom-bar">
            <div class="max-wrap">
                <div id="emptyBanner" class="alert-banner" style="display:none;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                    <span id="emptyBannerText"><?php te('fc.serve_qualcosa'); ?></span>
                </div>
            </div>
            <div class="bottom-bar-row">
                <?php if ($came_from_view): ?>
                    <button type="button" class="btn-cancel" onclick="window.location.href='visualizzazione_fc.php?category_id=<?php echo (int)$view_cat_id; ?>'"><?php te('comune.annulla'); ?></button>
                <?php else: ?>
                    <button type="button" class="btn-cancel" onclick="goBackOrIndex()"><?php te('comune.annulla'); ?></button>
                <?php endif; ?>
                <button type="submit" name="save" value="1" class="btn-save" id="submitBtn"><?php te('fc.ifc_inserisci'); ?></button>
            </div>
        </div>

    </form>
</div>

<?php if (!empty($success_message)): ?>
<div id="successToast" class="success-toast">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    <span><?php echo sec_h($success_message); ?></span>
</div>
<?php endif; ?>

<div id="agendaModal" class="modal-overlay">
    <div class="modal-content">
        <button class="close-btn" onclick="closeAgenda()">&times;</button>
        <h3 class="modal-title"><?php te('fc.ifc_guida_rapida'); ?></h3>
        <p class="modal-sub"><?php te('fc.ifc_guida_intro'); ?></p>
        <ul class="guide-list">
            <li><span class="g-ic" style="color:var(--red)">A</span> <?php te('fc.ifc_guida_colore'); ?></li>
            <li><span class="g-ic"><b>B</b></span> <?php te('fc.ifc_guida_stili'); ?></li>
            <li><span class="g-ic">🖊</span> <?php te('fc.ifc_guida_evidenzia'); ?></li>
            <li><span class="g-ic">≡</span><?php te('fc.ifc_guida_elenco'); ?></li>
            <li><span class="g-ic">🎨</span> <?php te('fc.ifc_guida_barra'); ?></li>
            <li><span class="g-ic">$x$</span><?php te('fc.ifc_guida_formula_inline'); ?></li>
            <li><span class="g-ic">$$x$$</span> <?php te('fc.ifc_guida_formula_blocco'); ?></li>
            <li><span class="g-ic">&lt;/&gt;</span><?php te('fc.ifc_guida_grezzo'); ?></li>
        </ul>
        <p class="modal-note"><?php te('fc.ifc_guida_formula_modifica'); ?></p>
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
        catch (err) { alert(T('fc.ifc_no_microfono')); return; }
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
        statusEl.textContent = T('fc.ifc_audio_pronto');
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
    subBtn.innerHTML = T('fc.ifc_salvataggio');

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
            alert(T('fc.ifc_errore_salvataggio') + data.error);
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        }
    } catch (err) {
        alert(T('fc.ifc_errore_conn'));
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