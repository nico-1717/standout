<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();

// Categorie di questo utente (con supporto padre/figlio, come categories.php)
$all_categories = [];
$categories_by_parent = [];
foreach (sec_all("SELECT id, name, parent_id, color FROM categories WHERE user_id = ? ORDER BY name",
                 [(int)$current_user_id]) as $row) {
    $all_categories[$row['id']] = $row;
    $categories_by_parent[$row['parent_id']][] = $row['id'];
}

// ============================================================
// GESTORE INSERIMENTO (AJAX)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert') {
    header('Content-Type: application/json');

    /* Token di sessione, origine riconosciuta e limite di frequenza: prima non
       c'era niente, e un altro sito poteva far inserire parole dal browser di
       chi e' connesso. */
    sec_guard_write((int)$current_user_id, 'vocab_inserimento', 0, 120, 60);

    $catID = sec_int($_POST['category'] ?? 0, 1);
    if (!sec_owns('categories', $catID, (int)$current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('vocab.categoria_non_valida')]);
        exit();
    }

    /* sec_str/sec_text tolgono tag e caratteri di controllo, cose che trim non
       faceva; la maiuscola iniziale e l'apostrofo tipografico restano. */
    $description_en = ucfirst(str_replace("'", '’', sec_str($_POST['description_en'] ?? '', 200)));
    $description_it = ucfirst(str_replace("'", '’', sec_str($_POST['description_it'] ?? '', 200)));
    $meaning        = ucfirst(str_replace("'", '’', sec_text($_POST['meaning'] ?? '', 1000)));
    $example        = ucfirst(str_replace("'", '’', sec_text($_POST['example'] ?? '', 1000)));

    if ($description_en === '' || $description_it === '') {
        echo json_encode(['success' => false, 'empty' => true, 'error' => t('vocab.serve_parola_e_traduzione')]);
        exit();
    }

    /* IL FILE CARICATO.
       Prima il nome arrivava intero dal client e il file veniva spostato senza
       nessun controllo: "x.php" diventava un file eseguibile nella cartella
       delle immagini, e "../../qualcosa" sarebbe uscito dalla cartella.
       sec_file() guarda il contenuto vero con finfo, sec_safe_filename() genera
       il nome sul server. */
    $image = '';
    if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ris = sec_file($_FILES['image']);
        if (empty($ris['ok'])) {
            echo json_encode(['success' => false, 'error' => $ris['message'] ?? t('vocab.immagine_non_valida')]);
            exit();
        }
        $image = sec_safe_filename($ris['ext']);
        if (!move_uploaded_file($ris['tmp'], IMAGE_PATH_INSERT . $image)) {
            echo json_encode(['success' => false, 'error' => t('vocab.immagine_non_salvata')]);
            exit();
        }
    }

    // is_father settato a 0 di default
    sec_q("INSERT INTO images (image, description_it, description_en, meaning, example, category_id, is_father)
           VALUES (?, ?, ?, ?, ?, ?, 0)",
          [$image, $description_it, $description_en, $meaning, $example, $catID]);

    $_SESSION['en_success_msg'] = t('vocab.inserita_ok', [T('vocab.ph_parola') => $description_en]);
    $_SESSION['en_last_category'] = $catID;
    echo json_encode(['success' => true, 'category_id' => $catID]);
    exit();
}

// ============================================================
// MESSAGGIO DI SUCCESSO (flash post-redirect, evita doppi inserimenti)
// ============================================================
$success_message = "";
if (isset($_SESSION['en_success_msg'])) {
    $success_message = $_SESSION['en_success_msg'];
    unset($_SESSION['en_success_msg']);
}
$last_cat = isset($_SESSION['en_last_category']) ? $_SESSION['en_last_category'] : '';

// Se si arriva da visualizzazione.php con ?category_id=..., quella categoria ha priorità
// e diventa anche la nuova "ultima categoria" in sessione. La presenza esplicita di
// ?category_id= indica anche la provenienza, usata per "Annulla".
$came_from_view = isset($_GET['category_id']);
if ($came_from_view) {
    $gcid = sec_int($_GET['category_id'] ?? 0, 0);
    if ($gcid > 0 && isset($all_categories[$gcid])) {
        $last_cat = $gcid;
        $_SESSION['en_last_category'] = $gcid;
    } else {
        $came_from_view = false;
    }
}
$view_cat_id = $came_from_view ? sec_int($_GET['category_id'] ?? 0, 0) : 0;
$last_cat_name = (!empty($last_cat) && isset($all_categories[$last_cat])) ? $all_categories[$last_cat]['name'] : '';

// Lista indentata per il foglio T('vocab.cat_categoria') (stessa estetica di categories.php)
function ins_render_cat_list($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $nameEsc = htmlspecialchars($cat['name'], ENT_QUOTES);
        $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
        $pad = 14 + $level * 20;
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' data-name=\"" . htmlspecialchars($cat['name'], ENT_QUOTES) . "\" style='padding-left:{$pad}px' onclick='selezionaCategoria({$id}, this)'>";
        echo "<span class='cat-pick-dot' style='background:{$color}'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
        ins_render_cat_list($id, $all_cats, $cats_by_parent, $level + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title><?php te('vocab.ins_titolo'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>inserimento.css?v=<?php echo @filemtime(dirname(__FILE__) . '/inserimento.css') ?: 1; ?>">
</head>
<body>

<?php include 'sidebar.html'; ?>

<div class="app-header">
    <div class="hd-eyebrow"><?php te('vocab.ins_nuova_parola'); ?></div>
    <h1><?php te('vocab.ins_vocabolario'); ?></h1>
</div>

<div class="page">
    <form id="insForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="insert">

        <!-- ============== CARD PAROLA (bilingue, coerente con visualizzazione.php) ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_parola'); ?></span></div>
            <div class="bilingual-grid">
                <div class="bilingual-col col-en">
                    <div class="bilingual-eyebrow"><span>🇬🇧</span> <?php te('vocab.ins_english'); ?></div>
                    <input type="text" name="description_en" id="description_en" placeholder="<?php te('vocab.ins_ph_en'); ?>" required>
                </div>
                <div class="bilingual-col col-it">
                    <div class="bilingual-eyebrow"><span>🇮🇹</span> <?php te('vocab.ins_italiano'); ?></div>
                    <input type="text" name="description_it" id="description_it" placeholder="<?php te('vocab.ins_ph_it'); ?>" required>
                </div>
            </div>
        </section>

        <!-- ============== CARD ASSISTENTE IA (ridisegnata) ============== -->
        <section class="fc-card ai-card">
            <div class="ai-card-head">
                <span class="ai-card-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg></span>
                <div>
                    <div class="ai-card-title"><?php te('vocab.ins_assistente'); ?></div>
                    <div class="ai-card-sub"><?php te('vocab.ins_assistente_sub'); ?></div>
                </div>
            </div>

            <label class="field-label" for="img_directions"><?php te('vocab.ins_indicazioni'); ?></label>
            <input type="text" id="img_directions" placeholder="<?php te('vocab.ins_ph_dir'); ?>">

            <div class="ai-toggles">
                <label class="ai-toggle" id="toggleLinear">
                    <input type="checkbox" id="ai_linear" onchange="syncToggle('toggleLinear', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17c3-8 6 8 9 0s6-8 9 0"/></svg>
                    <?php te('vocab.ins_stile_vettoriale'); ?>
                </label>
                <label class="ai-toggle" id="toggleExample">
                    <input type="checkbox" id="ai_example" onchange="syncToggle('toggleExample', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                    <?php te('vocab.ins_usa_esempio'); ?>
                </label>
            </div>

            <div class="ai-action">
                <div class="ai-action-label"><?php te('vocab.ins_compila_sub'); ?></div>
                <div class="ai-btn-pair">
                    <button type="button" class="ai-btn ai-btn-pro" onclick="generateText(this, 'pro')">
                        <span class="ai-btn-badge">PRO</span> <?php te('vocab.ins_compila'); ?>
                    </button>
                    <button type="button" class="ai-btn ai-btn-basic" onclick="generateText(this, 'basic')">
                        <?php te('vocab.ins_compila_basic'); ?>
                    </button>
                </div>
            </div>

            <div class="ai-action">
                <div class="ai-action-label"><?php te('vocab.ins_genera_sub'); ?></div>
                <div class="ai-btn-pair">
                    <button type="button" class="ai-btn ai-btn-pro" onclick="generateImage(this, 'pro')">
                        <span class="ai-btn-badge">PRO</span> <?php te('vocab.ins_genera_img'); ?>
                    </button>
                    <button type="button" class="ai-btn ai-btn-basic" onclick="generateImage(this, 'basic')">
                        <?php te('vocab.ins_genera_basic'); ?>
                    </button>
                </div>
            </div>
        </section>

        <!-- ============== CARD IMMAGINE ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_immagine_opz'); ?></span></div>
            <div class="file-row">
                <input type="file" name="image" id="image" accept="image/*" style="display:none;">
                <label for="image" class="file-btn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                    <?php te('vocab.ins_scegli_foto'); ?>
                </label>
            </div>
            <div class="img-upload-box" id="imgUploadBox" style="display:none;">
                <img id="preview-image">
                <button type="button" class="mini-remove" onclick="clearImage()">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php te('vocab.ins_rimuovi'); ?>
                </button>
            </div>
        </section>

        <!-- ============== CARD SIGNIFICATO ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_significato'); ?></span></div>
            <input type="text" name="meaning" id="meaning" placeholder="<?php te('vocab.ins_ph_mean'); ?>">
        </section>

        <!-- ============== CARD ESEMPIO ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_esempio'); ?></span></div>
            <input type="text" name="example" id="example" value="_" placeholder="<?php te('vocab.ins_ph_ex'); ?>">
            <p class="field-hint err" id="example-error-message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                <?php te('vocab.ins_underscore'); ?>
            </p>
        </section>

        <!-- ============== CATEGORIA ============== -->
        <section class="fc-card">
            <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_categoria'); ?></span></div>
            <button type="button" class="cat-btn <?php echo $last_cat_name === '' ? 'is-placeholder' : ''; ?>" id="categoryBtn" onclick="openCatSheet()">
                <span class="cb-left">
                    <span class="cb-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>
                    <span id="categoryBtnLabel"><?php echo $last_cat_name !== '' ? htmlspecialchars($last_cat_name) : 'Scegli una categoria'; ?></span>
                </span>
                <span class="cb-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
            </button>
            <input type="hidden" name="category" id="categoryInput" value="<?php echo (int)$last_cat; ?>">
        </section>

        <!-- ============== BIG INSERT ============== -->
        <section class="fc-card fc-card-tight">
            <a id="bigInsertLink" class="cta-insert" href="inserimento_big.php<?php echo !empty($last_cat) ? ('?category_id=' . (int)$last_cat) : ''; ?>">
                <span class="cta-insert-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="14" height="4" rx="1"/><rect x="7" y="10" width="14" height="4" rx="1"/><rect x="3" y="16" width="14" height="4" rx="1"/></svg>
                </span>
                <span class="cta-insert-text">
                    <span class="cta-insert-title"><?php te('vocab.ins_multiplo'); ?></span>
                    <span class="cta-insert-sub"><?php te('vocab.ins_multiplo_sub'); ?></span>
                </span>
                <svg class="cta-insert-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </section>

        <!-- ============== BARRA AZIONI ============== -->
        <div class="bottom-bar">
            <div class="max-wrap">
                <div id="emptyBanner" class="alert-banner" style="display:none;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                    <span id="emptyBannerText"><?php te('vocab.ins_serve_tutto'); ?></span>
                </div>
            </div>
            <div class="bottom-bar-row">
                <?php if ($came_from_view): ?>
                    <button type="button" class="btn-cancel" onclick="window.location.href='visualizzazione.php?category_id=<?php echo (int)$view_cat_id; ?>'"><?php te('comune.annulla'); ?></button>
                <?php else: ?>
                    <button type="button" class="btn-cancel" onclick="goBackOrIndex()"><?php te('comune.annulla'); ?></button>
                <?php endif; ?>
                <button type="submit" name="save" value="1" class="btn-save" id="submitBtn"><?php te('vocab.ins_inserisci'); ?></button>
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

<!-- ============== FOGLIO CATEGORIA ============== -->
<div id="catSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title"><?php te('vocab.ins_scegli_cat'); ?></h3>
        <div class="cat-pick-list">
            <?php if (empty($all_categories)): ?>
                <div class="cat-pick-empty"><?php te('vocab.ins_nessuna_cat'); ?><br><a href="categories.php"><?php te('vocab.ins_creane_una'); ?></a>.</div>
            <?php else: ?>
                <?php ins_render_cat_list(0, $all_categories, $categories_by_parent); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============== POPUP ERRORI IA ============== -->
<div id="aiErrorOverlay" class="modal-overlay">
    <div class="modal-content ai-err-panel">
        <div class="ai-err-icon" id="aiErrIcon"></div>
        <h3 class="ai-err-title" id="aiErrTitle"><?php te('vocab.ins_errore'); ?></h3>
        <p class="ai-err-body" id="aiErrBody"></p>
        <p class="ai-err-detail" id="aiErrDetail" style="display:none;"></p>
        <div class="ai-err-actions">
            <button type="button" class="btn-cancel" onclick="closeAiError()"><?php te('comune.chiudi'); ?></button>
            <button type="button" class="btn-save" id="aiErrRetryBtn" style="display:none;"><?php te('vocab.ins_riprova'); ?></button>
        </div>
    </div>
</div>

<?php echo nisama_i18n_json(['comune', 'vocab']); ?>
<script>
function closeCatSheetOnOverlay(e){ if (e.target.id === 'catSheet') closeCatSheet(); }
document.getElementById('catSheet').addEventListener('click', closeCatSheetOnOverlay);

function openCatSheet(){ document.getElementById('catSheet').classList.add('show'); }
function closeCatSheet(){ document.getElementById('catSheet').classList.remove('show'); }

function selezionaCategoria(id, btn){
    document.getElementById('categoryInput').value = id;
    var label = document.getElementById('categoryBtnLabel');
    label.textContent = btn.dataset.name;
    document.getElementById('categoryBtn').classList.remove('is-placeholder');
    var link = document.getElementById('bigInsertLink');
    if (link) link.href = 'inserimento_big.php?category_id=' + id;
    closeCatSheet();
}

function syncToggle(wrapId, checkbox){
    document.getElementById(wrapId).classList.toggle('on', checkbox.checked);
}

function previewImage(src){
    var preview = document.getElementById('preview-image');
    preview.src = src;
    document.getElementById('imgUploadBox').style.display = 'flex';
}
document.getElementById('image').addEventListener('change', function(event){
    var input = event.target;
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { previewImage(e.target.result); };
        reader.readAsDataURL(input.files[0]);
    }
});
function clearImage(){
    document.getElementById('image').value = '';
    document.getElementById('imgUploadBox').style.display = 'none';
}

function validateExampleInput(){
    var exampleInput = document.getElementById('example');
    var exampleValue = exampleInput.value;
    var descriptionEnValue = document.getElementById('description_en').value.trim().toLowerCase();

    if (descriptionEnValue !== '') {
        var idx = exampleValue.toLowerCase().indexOf(descriptionEnValue);
        if (idx !== -1) {
            exampleValue = exampleValue.substring(0, idx) + '_' + exampleValue.substring(idx + descriptionEnValue.length);
        }
    }
    exampleInput.value = exampleValue;

    var isValid = exampleValue.indexOf('_') !== -1 || exampleValue.trim() === '';
    document.getElementById('example-error-message').classList.toggle('show', !isValid);
    exampleInput.classList.toggle('err-state', !isValid);
}
document.getElementById('example').addEventListener('blur', validateExampleInput);

function goBackOrIndex(){
    if (window.history.length > 1) { window.history.back(); }
    else { window.location.href = 'index.php'; }
}

function isFormEmpty(){
    var en = document.getElementById('description_en').value.trim();
    var it = document.getElementById('description_it').value.trim();
    var cat = document.getElementById('categoryInput').value;
    return !en || !it || !cat || cat === '0';
}
function showEmptyBanner(){
    document.getElementById('emptyBanner').style.display = 'flex';
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.classList.remove('shake');
    void submitBtn.offsetWidth;
    submitBtn.classList.add('shake');
}

document.getElementById('insForm').addEventListener('submit', async function(e){
    e.preventDefault();
    validateExampleInput();

    if (isFormEmpty()) { showEmptyBanner(); return; }
    document.getElementById('emptyBanner').style.display = 'none';

    var subBtn = document.getElementById('submitBtn');
    var originalText = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = T('vocab.ins_in_corso');

    var fd = new FormData(this);

    try {
        var res = await fetch('inserimento.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            var cat = data.category_id ? data.category_id : '';
            window.location.href = 'inserimento.php' + (cat ? ('?category_id=' + cat) : '');
        } else if (data.empty) {
            showEmptyBanner();
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        } else {
            alert('Errore: ' + data.error);
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        }
    } catch (err) {
        alert(T('vocab.ins_errore_conn'));
        subBtn.disabled = false;
        subBtn.innerHTML = originalText;
    }
});

// ============================================================
// POPUP ERRORI IA — spiega cosa è successo e se ha senso riprovare
// ============================================================
const AI_ERR_ICONS = {
    network: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 9a16 16 0 0 1 22 0M5 13a10 10 0 0 1 14 0M9 17a4 4 0 0 1 6 0"/><line x1="2" y1="2" x2="22" y2="22"/></svg>',
    timeout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
    server: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
    quota_service: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
    limite_piano: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 2.9 6.3 6.9.9-5 4.8 1.3 6.8-6.1-3.4-6.1 3.4 1.3-6.8-5-4.8 6.9-.9Z"/></svg>',
    invalid_format: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.6-2.4 1.8-2.4 3.3"/><path d="M12 17h.01"/></svg>',
    no_image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 17 4.5-4.5 3.5 3.5 3-3L20 17"/><line x1="2.5" y1="2.5" x2="21.5" y2="21.5"/></svg>',
    unknown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>'
};
const AI_ERR_PRESETS = {
    network:        { title: T('vocab.ai_connessione'),        tone: 'warn',  retry: true,  body: T('vocab.ai_connessione_txt') },
    timeout:        { title: T('vocab.ai_timeout'),     tone: 'warn',  retry: true,  body: T('vocab.ai_timeout_txt') },
    server:         { title: T('vocab.ai_errore'),          tone: 'warn',  retry: true,  body: T('vocab.ai_body_5') },
    quota_service:  { title: T('vocab.ai_pieno'),  tone: 'warn',  retry: true,  body: T('vocab.ai_body_6') },
    limite_piano:   { title: T('vocab.ai_limite_piano'),      tone: 'plan',  retry: false, body: null },
    invalid_format: { title: T('vocab.ai_formato'),  tone: 'warn',  retry: true,  body: T('vocab.ai_body_server') },
    no_image:       { title: T('vocab.ai_no_immagine'),           tone: 'warn',  retry: true,  body: "Il modello non è riuscito a creare un'immagine per questa richiesta (può succedere per il contenuto della parola o dell'esempio). Prova a modificare le indicazioni aggiuntive, poi riprova." },
    unknown:        { title: T('vocab.ai_imprevisto'),               tone: 'error', retry: true,  body: T('vocab.ai_imprevisto_txt') }
};
let aiErrorRetryFn = null;
function showAiError(data, retryFn){
    const type = (data && data.error_type) || 'unknown';
    const preset = AI_ERR_PRESETS[type] || AI_ERR_PRESETS.unknown;

    const iconEl = document.getElementById('aiErrIcon');
    iconEl.className = 'ai-err-icon tone-' + preset.tone;
    iconEl.innerHTML = AI_ERR_ICONS[type] || AI_ERR_ICONS.unknown;
    document.getElementById('aiErrTitle').textContent = preset.title;
    document.getElementById('aiErrBody').textContent = (data && data.message) || preset.body || T('vocab.ai_problema');

    const detailEl = document.getElementById('aiErrDetail');
    if (data && data.error) { detailEl.textContent = data.error; detailEl.style.display = 'block'; }
    else { detailEl.style.display = 'none'; }

    const retryBtn = document.getElementById('aiErrRetryBtn');
    if (preset.retry && retryFn) { retryBtn.style.display = ''; aiErrorRetryFn = retryFn; }
    else { retryBtn.style.display = 'none'; aiErrorRetryFn = null; }

    document.getElementById('aiErrorOverlay').classList.add('show');
}
function closeAiError(){ document.getElementById('aiErrorOverlay').classList.remove('show'); aiErrorRetryFn = null; }
document.getElementById('aiErrRetryBtn').addEventListener('click', function(){
    const fn = aiErrorRetryFn;
    closeAiError();
    if (fn) fn();
});
document.getElementById('aiErrorOverlay').addEventListener('click', function(e){ if (e.target.id === 'aiErrorOverlay') closeAiError(); });

// ============================================================
// ASSISTENTE IA — Pro (Google, a pagamento) / Basic (Pollinations, gratis)
// ============================================================

// Funzione per il cooldown di 60 secondi
function startBtnCooldown(btn, originalHTML) {
    let timeLeft = 60;
    btn.disabled = true;
    btn.innerHTML = `⏳ ${timeLeft}s`; // Mostra il timer

    let interval = setInterval(() => {
        timeLeft--;
        if (timeLeft <= 0) {
            clearInterval(interval);
            btn.innerHTML = originalHTML; // Ripristina il testo (es. "Genera (Basic)")
            btn.disabled = false;         // Riabilita il bottone
        } else {
            btn.innerHTML = `⏳ ${timeLeft}s`;
        }
    }, 1000); // Aggiorna ogni secondo (1000 ms)
}

async function generateText(btn, tier){
    if (btn.disabled) return;

    const word = document.getElementById('description_en').value.trim();
    if (!word) return alert("Inserisci prima una parola in inglese!");

    const endpoint = tier === 'pro' ? 'generate_ai_text.php' : 'generate_ai_text_pollination.php';
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ai-spinner"></span> Generazione…';

    const formData = new FormData();
    formData.append('word', word);
    const exampleText = document.getElementById('example').value.trim();
    const directions = document.getElementById('img_directions').value.trim();
    if ((exampleText === '_' || exampleText === '') && directions !== '') {
        formData.append('directions', directions);
    }

    try {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            document.getElementById('description_it').value = data.description_it || "";
            document.getElementById('meaning').value = data.meaning || "";
            document.getElementById('example').value = data.example || "_";
            validateExampleInput();
            
            btn.innerHTML = T('vocab.ins_fatto');
            // Aspetta 1.5 secondi per mostrare "✓ Fatto", poi fa partire i 60 secondi di blocco
            setTimeout(() => { startBtnCooldown(btn, originalHTML); }, 1500); 
        } else {
            startBtnCooldown(btn, originalHTML);
            showAiError(data, null); // Passiamo 'null' per nascondere il tasto "Riprova" (c'è il cooldown)
        }
    } catch (error) {
        startBtnCooldown(btn, originalHTML);
        showAiError({ error_type: 'network', error: error && error.message }, null);
    }
}

async function generateImage(btn, tier){
    if (btn.disabled) return;

    const word = document.getElementById('description_en').value.trim();
    let example = document.getElementById('example').value.trim();
    const directions = document.getElementById('img_directions').value.trim();
    const useExample = document.getElementById('ai_example').checked;
    const isLinear = document.getElementById('ai_linear').checked;

    if (!word) return alert("Inserisci prima una parola in inglese!");
    if (!useExample) example = "";

    const endpoint = tier === 'pro' ? 'generate_ai_image.php' : 'generate_ai_image_pollination.php';
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ai-spinner"></span> Disegno…';

    const formData = new FormData();
    formData.append('word', word);
    formData.append('example', example);
    formData.append('directions', directions);
    formData.append('is_linear', isLinear ? '1' : '0');

    try {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success || data.status === 'success') {
            const byteCharacters = atob(data.base64);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) byteNumbers[i] = byteCharacters.charCodeAt(i);
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], {type: 'image/jpeg'});

            const safeWord = word.replace(/\s+/g, '_').toLowerCase();
            const fileName = safeWord + "_" + Date.now() + ".jpg";
            const file = new File([blob], fileName, {type: 'image/jpeg'});

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('image').files = dataTransfer.files;

            previewImage("data:image/jpeg;base64," + data.base64);

            btn.innerHTML = T('vocab.ins_creata');
            // Aspetta 1.5 secondi per mostrare "✓ Creata", poi avvia i 60 secondi
            setTimeout(() => { startBtnCooldown(btn, originalHTML); }, 1500);
        } else {
            startBtnCooldown(btn, originalHTML);
            showAiError(data, null);
        }
    } catch (error) {
        startBtnCooldown(btn, originalHTML);
        showAiError({ error_type: 'network', error: error && error.message }, null);
    }
}

// Toast di successo: auto-rimozione dal DOM dopo l'animazione
var toastEl = document.getElementById('successToast');
if (toastEl) { setTimeout(function(){ toastEl.remove(); }, 3300); }
</script>

</body>
</html>