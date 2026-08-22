<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();

$categories = array();
foreach (sec_all("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name",
                 [(int)$current_user_id]) as $row) { $categories[$row['id']] = $row['name']; }

// ============================================================
// SALVATAGGIO / ELIMINAZIONE (AJAX)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && (isset($_POST['image_id']) || isset($_POST['child_id']))) {
    header('Content-Type: application/json');

    /* Token di sessione, origine riconosciuta e limite di frequenza: prima non
       c'erano, su una pagina che modifica ed elimina. */
    sec_guard_write((int)$current_user_id, 'vocab_modifica', 0, 120, 60);

    $is_child = isset($_POST['child_id']);
    $id = $is_child ? sec_int($_POST['child_id'] ?? 0, 1) : sec_int($_POST['image_id'] ?? 0, 1);

    /* La riga dev'essere sua: si passa dalla categoria, e per un figlio dal
       padre. Le due query sono scritte per esteso invece di comporre il nome
       della tabella in una variabile: i nomi di tabella non si possono legare a
       un segnaposto, quindi meno se ne scrivono a runtime meglio e'. */
    if ($is_child) {
        $check_row = sec_one("SELECT c.user_id, i.category_id FROM english_child_words ch
                    JOIN images i ON ch.parent_id = i.id
                    JOIN categories c ON i.category_id = c.id
                    WHERE ch.id_child = ?", [$id]);
    } else {
        $check_row = sec_one("SELECT c.user_id, i.category_id FROM images i
                    JOIN categories c ON i.category_id = c.id
                    WHERE i.id = ?", [$id]);
    }
    if (!$check_row || (int)$check_row['user_id'] !== (int)$current_user_id) {
        echo json_encode(['success' => false, 'error' => t('vocab.non_autorizzato_parola')]);
        exit;
    }
    $prevCatId = (int)$check_row['category_id'];

    $action = sec_enum($_POST['action'] ?? null, ['save', 'delete'], '');

    // --- ELIMINAZIONE ---
    if ($action === 'delete') {
        $old = $is_child
            ? sec_one("SELECT image FROM english_child_words WHERE id_child = ?", [$id])
            : sec_one("SELECT image FROM images WHERE id = ?", [$id]);
        if ($old && !empty($old['image'])) {
            /* basename(): il nome viene dal database, ma se ci finisse un
               percorso non deve poter uscire dalla cartella */
            $f = IMAGE_PATH_INSERT . basename($old['image']);
            if (is_file($f)) @unlink($f);
        }
        if ($is_child) sec_q("DELETE FROM english_child_words WHERE id_child = ?", [$id]);
        else           sec_q("DELETE FROM images WHERE id = ?", [$id]);
        echo json_encode(['success' => true, 'category_id' => $prevCatId]);
        exit;
    }

    // --- SALVATAGGIO ---
    $catID = $is_child ? $prevCatId : sec_int($_POST['category'] ?? $prevCatId, 1, PHP_INT_MAX, $prevCatId);

    // Se il padre cambia categoria, anche la nuova dev'essere sua
    if (!$is_child && $catID !== $prevCatId && !sec_owns('categories', $catID, (int)$current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('vocab.non_autorizzato_categoria')]);
        exit;
    }

    $description_en = ucfirst(str_replace("'", '’', sec_str($_POST['description_en'] ?? '', 200)));
    $description_it = ucfirst(str_replace("'", '’', sec_str($_POST['description_it'] ?? '', 200)));
    $meaning        = ucfirst(str_replace("'", '’', sec_text($_POST['meaning'] ?? '', 1000)));
    $example        = ucfirst(str_replace("'", '’', sec_text($_POST['example'] ?? '', 1000)));

    $old2 = $is_child
        ? sec_one("SELECT image FROM english_child_words WHERE id_child = ?", [$id])
        : sec_one("SELECT image FROM images WHERE id = ?", [$id]);

    function mi_togli_file(?array $riga): void {
        if (!$riga || empty($riga['image'])) return;
        $f = IMAGE_PATH_INSERT . basename($riga['image']);
        if (is_file($f)) @unlink($f);
    }

    /* $nuovaImg: null = non toccare l'immagine, '' = toglierla, altrimenti il
       nome nuovo. Prima era un pezzo di SQL costruito a mano ("image = '...'")
       incollato nella UPDATE: con i segnaposto quel modo non regge. */
    $nuovaImg = null;
    if (sec_bool($_POST['remove_image'] ?? false)) {
        mi_togli_file($old2);
        $nuovaImg = '';
    } elseif (sec_bool($_POST['replace_image'] ?? false)
              && isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        /* IL FILE CARICATO. Prima il nome arrivava dal client e non si guardava
           il contenuto: "x.php" finiva eseguibile nella cartella delle immagini.
           sec_file() controlla il tipo vero con finfo, sec_safe_filename()
           genera il nome sul server. */
        $ris = sec_file($_FILES['image']);
        if (empty($ris['ok'])) {
            echo json_encode(['success' => false, 'error' => $ris['message'] ?? t('vocab.immagine_non_valida')]);
            exit;
        }
        $nome = sec_safe_filename($ris['ext']);
        if (!move_uploaded_file($ris['tmp'], IMAGE_PATH_INSERT . $nome)) {
            echo json_encode(['success' => false, 'error' => t('vocab.immagine_non_salvata')]);
            exit;
        }
        mi_togli_file($old2);
        $nuovaImg = $nome;
    }

    /* Quattro forme di query invece di incollare pezzi di SET: figlio o padre,
       con o senza cambio immagine. Piu' righe, ma ogni query si legge per intero. */
    if ($is_child) {
        if ($nuovaImg === null) {
            sec_q("UPDATE english_child_words SET description_it = ?, description_en = ?, meaning = ?, example = ?
                    WHERE id_child = ?", [$description_it, $description_en, $meaning, $example, $id]);
        } else {
            sec_q("UPDATE english_child_words SET image = ?, description_it = ?, description_en = ?, meaning = ?, example = ?
                    WHERE id_child = ?", [$nuovaImg, $description_it, $description_en, $meaning, $example, $id]);
        }
    } else {
        if ($nuovaImg === null) {
            sec_q("UPDATE images SET description_it = ?, description_en = ?, meaning = ?, example = ?, category_id = ?
                    WHERE id = ?", [$description_it, $description_en, $meaning, $example, $catID, $id]);
        } else {
            sec_q("UPDATE images SET image = ?, description_it = ?, description_en = ?, meaning = ?, example = ?, category_id = ?
                    WHERE id = ?", [$nuovaImg, $description_it, $description_en, $meaning, $example, $catID, $id]);
        }
    }
    echo json_encode(['success' => true, 'category_id' => $catID]);
    exit;
}

// Lista indentata per il foglio T('vocab.ins_categoria') (stessa estetica di inserimento.php)
function edit_render_cat_list($parentId, $all_cats_flat, $selected_id) {
    // qui usiamo una lista piatta (id => name) perché la tabella categories supporta
    // padre/figlio ma per semplicità di selezione mostriamo tutte in ordine alfabetico
    foreach ($all_cats_flat as $id => $name) {
        $nameEsc = sec_h($name);
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' data-name=\"" . sec_h($name) . "\" onclick='selezionaCategoria({$id}, this)'>";
        echo "<span class='cat-pick-dot' style='background:#A0A0A0'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title><?php te('vocab.mi_titolo'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>modifica_immagine.css?v=<?php echo @filemtime(dirname(__FILE__) . '/modifica_immagine.css') ?: 1; ?>">
</head>
<body>

<?php include 'sidebar.html'; ?>

<?php
// ============================================================
// CARICAMENTO RECORD (GET, con verifica proprietà)
// ============================================================
if (isset($_GET['image_id']) || isset($_GET['child_id'])) {

    $is_child = isset($_GET['child_id']);
    $id = $is_child ? sec_int($_GET['child_id'] ?? 0, 1) : sec_int($_GET['image_id'] ?? 0, 1);
    $prev_cat = sec_int($_GET['previous_catid'] ?? 0, 0);

    if ($is_child) {
        $row = sec_one("SELECT t.*, i.category_id AS parent_category_id FROM english_child_words t
                      JOIN images i ON t.parent_id = i.id
                      JOIN categories c ON i.category_id = c.id
                      WHERE t.id_child = ? AND c.user_id = ?", [$id, (int)$current_user_id]);
    } else {
        $row = sec_one("SELECT t.* FROM images t
                      JOIN categories c ON t.category_id = c.id
                      WHERE t.id = ? AND c.user_id = ?", [$id, (int)$current_user_id]);
    }

    if ($row) {
        $category_id = $is_child ? (int)$row['parent_category_id'] : (int)$row['category_id'];
        $cancel_url = "visualizzazione.php?category_id=" . $category_id;
        ?>
        <div class="app-header">
            <div class="hd-eyebrow"><?php te('vocab.mi_modifica_parola'); ?></div>
            <h1><?php te('vocab.ins_vocabolario'); ?><?php if ($is_child) echo ' <span class="child-badge">' . sec_h(t('vocab.figlio')) . '</span>'; ?></h1>
        </div>

        <div class="page">
            <form id="editForm" enctype="multipart/form-data">
                <?php if ($is_child): ?>
                    <input type="hidden" name="child_id" value="<?php echo (int)$id; ?>">
                <?php else: ?>
                    <input type="hidden" name="image_id" value="<?php echo (int)$id; ?>">
                <?php endif; ?>
                <input type="hidden" name="previous_catid" value="<?php echo $prev_cat ?: $category_id; ?>">

                <!-- ============== CARD PAROLA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_parola'); ?></span></div>
                    <div class="bilingual-grid">
                        <div class="bilingual-col col-en">
                            <div class="bilingual-eyebrow"><span>🇬🇧</span> <?php te('vocab.ins_english'); ?></div>
                            <input type="text" name="description_en" id="description_en" value="<?php echo sec_h($row['description_en']); ?>" placeholder="<?php te('vocab.mi_ph_en'); ?>">
                        </div>
                        <div class="bilingual-col col-it">
                            <div class="bilingual-eyebrow"><span>🇮🇹</span> <?php te('vocab.ins_italiano'); ?></div>
                            <input type="text" name="description_it" id="description_it" value="<?php echo sec_h($row['description_it']); ?>" placeholder="<?php te('vocab.mi_ph_it'); ?>">
                        </div>
                    </div>
                </section>

                <!-- ============== CARD ASSISTENTE IA ============== -->
                <section class="fc-card ai-card">
                    <div class="ai-card-head">
                        <span class="ai-card-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg></span>
                        <div>
                            <div class="ai-card-title"><?php te('vocab.ins_assistente'); ?></div>
                            <div class="ai-card-sub"><?php te('vocab.mi_assistente_sub'); ?></div>
                        </div>
                    </div>

                    <label class="field-label" for="img_directions"><?php te('vocab.ins_indicazioni'); ?></label>
                    <input type="text" id="img_directions" placeholder="<?php te('vocab.ins_ph_dir'); ?>">

                    <div class="ai-toggles">
                        <label class="ai-toggle" id="toggleLinear">
                            <input type="checkbox" id="ai_linear" onchange="syncToggle('toggleLinear', this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17c3-8 6 8 9 0s6-8 9 0"/></svg>
                            <?php te('vocab.mi_stile_lineare'); ?>
                        </label>
                        <label class="ai-toggle" id="toggleExample">
                            <input type="checkbox" id="ai_example" onchange="syncToggle('toggleExample', this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                            <?php te('vocab.ins_usa_esempio'); ?>
                        </label>
                    </div>

                    <div class="ai-action">
                        <div class="ai-action-label"><?php te('vocab.mi_ricompila_sub'); ?></div>
                        <div class="ai-btn-pair">
                            <button type="button" class="ai-btn ai-btn-pro" onclick="generateText(this, 'pro')"><span class="ai-btn-badge">PRO</span> <?php te('vocab.ins_compila'); ?></button>
                            <button type="button" class="ai-btn ai-btn-basic" onclick="generateText(this, 'basic')"><?php te('vocab.ins_compila_basic'); ?></button>
                        </div>
                    </div>

                    <div class="ai-action">
                        <div class="ai-action-label"><?php te('vocab.mi_genera_sub'); ?></div>
                        <div class="ai-btn-pair">
                            <button type="button" class="ai-btn ai-btn-pro" onclick="generateImage(this, 'pro')"><span class="ai-btn-badge">PRO</span> <?php te('vocab.ins_genera_img'); ?></button>
                            <button type="button" class="ai-btn ai-btn-basic" onclick="generateImage(this, 'basic')"><?php te('vocab.ins_genera_basic'); ?></button>
                        </div>
                    </div>
                </section>

                <!-- ============== CARD IMMAGINE ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.immagine'); ?></span></div>
                    <div class="img-compare">
                        <div class="img-box">
                            <span class="img-tag"><?php te('vocab.mi_attuale'); ?></span>
                            <?php if (!empty($row['image'])): ?>
                                <img src="<?php echo IMAGE_PATH_VIEW . '/' . sec_h($row['image']); ?>">
                            <?php else: ?>
                                <div class="img-empty"><?php te('vocab.mi_nessuna_immagine'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="img-box img-box-new">
                            <span class="img-tag img-tag-new"><?php te('vocab.mi_nuova'); ?></span>
                            <img id="preview-image" style="display:none;">
                            <div id="no-img-text" class="img-empty img-empty-warn" style="display:none;"><?php te('vocab.mi_senza_immagine'); ?></div>
                        </div>
                    </div>
                    <div class="file-row">
                        <input type="file" name="image" id="image" accept="image/*" style="display:none;">
                        <label for="image" class="file-btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                            <?php te('vocab.ins_scegli_foto'); ?>
                        </label>
                    </div>
                    <div class="control-panel">
                        <label class="chk-row chk-blue"><input type="checkbox" name="replace_image" id="replace_image" value="1"> <?php te('vocab.mi_sostituisci'); ?></label>
                        <label class="chk-row chk-red"><input type="checkbox" name="remove_image" id="remove_image" value="1" onchange="toggleRemoveImage()"> <?php te('vocab.mi_rimuovi_immagine'); ?></label>
                    </div>
                </section>

                <!-- ============== CARD SIGNIFICATO ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_significato'); ?></span></div>
                    <input type="text" name="meaning" id="meaning" value="<?php echo sec_h($row['meaning']); ?>" placeholder="<?php te('vocab.mi_ph_mean'); ?>">
                </section>

                <!-- ============== CARD ESEMPIO ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_esempio'); ?></span></div>
                    <input type="text" name="example" id="example" value="<?php echo sec_h($row['example']); ?>" placeholder="<?php te('vocab.mi_ph_ex'); ?>">
                    <p class="field-hint err" id="example-error-message">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                        <?php te('vocab.ins_underscore'); ?>
                    </p>
                </section>

                <!-- ============== CATEGORIA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.ins_categoria'); ?></span></div>
                    <?php if (!$is_child): ?>
                        <button type="button" class="cat-btn" id="categoryBtn" onclick="openCatSheet()">
                            <span class="cb-left">
                                <span class="cb-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>
                                <span id="categoryBtnLabel"><?php echo isset($categories[$category_id]) ? sec_h($categories[$category_id]) : T('vocab.ins_scegli_cat'); ?></span>
                            </span>
                            <span class="cb-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
                        </button>
                        <input type="hidden" name="category" id="categoryInput" value="<?php echo (int)$category_id; ?>">
                    <?php else: ?>
                        <div class="inherited-note"><?php te('vocab.mi_categoria_ereditata'); ?></div>
                    <?php endif; ?>
                </section>

                <!-- ============== BARRA AZIONI ============== -->
                <div class="bottom-bar">
                    <div class="max-wrap">
                        <div id="emptyBanner" class="alert-banner" style="display:none;">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                            <span><?php te('vocab.mi_serve_minimo'); ?></span>
                        </div>
                    </div>
                    <div class="bottom-bar-row">
                        <button type="button" class="btn-cancel" onclick="window.location.href='<?php echo sec_h($cancel_url); ?>'"><?php te('comune.annulla'); ?></button>
                        <button type="submit" id="submitBtn" class="btn-save"><?php te('vocab.mi_salva'); ?></button>
                    </div>
                    <div class="max-wrap">
                        <button type="button" class="btn-delete-link" onclick="openDeleteSheet()"><?php te('vocab.mi_elimina_def'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    } else {
        echo "<div class='page'><div class='empty-state'>" . sec_h(t('vocab.mi_non_trovata')) . "</div></div>";
    }
} else {
    echo "<div class='page'><div class='empty-state'>" . sec_h(t('vocab.mi_id_mancante')) . "</div></div>";
}
?>

<!-- ============== FOGLIO CATEGORIA ============== -->
<div id="catSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title"><?php te('vocab.ins_scegli_cat'); ?></h3>
        <div class="cat-pick-list">
            <?php if (empty($categories)): ?>
                <div class="cat-pick-empty"><?php te('vocab.ins_nessuna_cat'); ?></div>
            <?php else: ?>
                <?php edit_render_cat_list(0, $categories, null); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============== FOGLIO CONFERMA ELIMINAZIONE ============== -->
<div id="deleteSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title"><?php te('vocab.eliminare_parola'); ?></h3>
        <p class="confirm-body"><?php te('vocab.mi_elimina_txt'); ?></p>
        <div class="confirm-actions">
            <button type="button" class="btn-ghost" onclick="closeDeleteSheet()"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn-danger" id="confirmDeleteBtn">Elimina</button>
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
/* ---------- Categoria ---------- */
document.getElementById('catSheet').addEventListener('click', function(e){ if (e.target.id === 'catSheet') closeCatSheet(); });
function openCatSheet(){ document.getElementById('catSheet').classList.add('show'); }
function closeCatSheet(){ document.getElementById('catSheet').classList.remove('show'); }
function selezionaCategoria(id, btn){
    var input = document.getElementById('categoryInput');
    if (input) input.value = id;
    var label = document.getElementById('categoryBtnLabel');
    if (label) label.textContent = btn.dataset.name;
    closeCatSheet();
}

/* ---------- Eliminazione ---------- */
document.getElementById('deleteSheet').addEventListener('click', function(e){ if (e.target.id === 'deleteSheet') closeDeleteSheet(); });
function openDeleteSheet(){ document.getElementById('deleteSheet').classList.add('show'); }
function closeDeleteSheet(){ document.getElementById('deleteSheet').classList.remove('show'); }
document.getElementById('confirmDeleteBtn').addEventListener('click', async function(){
    const btn = this;
    btn.disabled = true;
    btn.textContent = T('vocab.mi_eliminazione');
    const fd = new FormData();
    fd.append('action', 'delete');
    const form = document.getElementById('editForm');
    const childInput = form.querySelector('input[name="child_id"]');
    const imageInput = form.querySelector('input[name="image_id"]');
    if (childInput) fd.append('child_id', childInput.value);
    if (imageInput) fd.append('image_id', imageInput.value);
    try {
        const res = await fetch('modifica_immagine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.href = 'visualizzazione.php?category_id=' + data.category_id;
        } else {
            closeDeleteSheet();
            alert('Errore: ' + (data.error || 'sconosciuto'));
            btn.disabled = false;
            btn.textContent = T('vocab.cat_elimina_attr');
        }
    } catch (err) {
        closeDeleteSheet();
        alert(T('vocab.ins_errore_conn'));
        btn.disabled = false;
        btn.textContent = T('vocab.cat_elimina_attr');
    }
});

/* ---------- Anteprima immagine ---------- */
function previewImage(src){
    var preview = document.getElementById('preview-image');
    preview.src = src;
    preview.style.display = 'inline-block';
    document.getElementById('no-img-text').style.display = 'none';
    document.getElementById('replace_image').checked = true;
    document.getElementById('remove_image').checked = false;
}
document.getElementById('image').addEventListener('change', function(event){
    var input = event.target;
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { previewImage(e.target.result); };
        reader.readAsDataURL(input.files[0]);
    }
});
function toggleRemoveImage(){
    const isRemoved = document.getElementById('remove_image').checked;
    if (isRemoved) {
        document.getElementById('preview-image').style.display = 'none';
        document.getElementById('no-img-text').style.display = 'block';
        document.getElementById('image').value = '';
        document.getElementById('replace_image').checked = false;
    } else {
        document.getElementById('no-img-text').style.display = 'none';
    }
}

/* ---------- Validazione esempio ---------- */
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

function syncToggle(wrapId, checkbox){ document.getElementById(wrapId).classList.toggle('on', checkbox.checked); }

/* ---------- Invio form (AJAX) ---------- */
function isFormEmpty(){
    var en = document.getElementById('description_en').value.trim();
    var it = document.getElementById('description_it').value.trim();
    return !en || !it;
}
function showEmptyBanner(){
    document.getElementById('emptyBanner').style.display = 'flex';
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.classList.remove('shake');
    void submitBtn.offsetWidth;
    submitBtn.classList.add('shake');
}
document.getElementById('editForm').addEventListener('submit', async function(e){
    e.preventDefault();
    validateExampleInput();
    if (isFormEmpty()) { showEmptyBanner(); return; }
    document.getElementById('emptyBanner').style.display = 'none';

    var subBtn = document.getElementById('submitBtn');
    var originalText = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = T('vocab.mi_salvataggio');

    var fd = new FormData(this);
    fd.append('action', 'update');

    try {
        var res = await fetch('modifica_immagine.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            window.location.href = 'visualizzazione.php?category_id=' + data.category_id;
        } else {
            alert('Errore: ' + (data.error || 'sconosciuto'));
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        }
    } catch (err) {
        alert(T('vocab.ins_errore_conn'));
        subBtn.disabled = false;
        subBtn.innerHTML = originalText;
    }
});

/* ---------- Popup errori IA ---------- */
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

/* ---------- Assistente IA ---------- */

// Funzione per il cooldown di 60 secondi
function startBtnCooldown(btn, originalHTML) {
    let timeLeft = 60;
    btn.disabled = true;
    btn.innerHTML = `⏳ ${timeLeft}s`; // Mostra il timer

    let interval = setInterval(() => {
        timeLeft--;
        if (timeLeft <= 0) {
            clearInterval(interval);
            btn.innerHTML = originalHTML; // Ripristina il testo originale
            btn.disabled = false;         // Riabilita il bottone
        } else {
            btn.innerHTML = `⏳ ${timeLeft}s`;
        }
    }, 1000); // Aggiorna ogni secondo
}

async function generateText(btn, tier){
    if (btn.disabled) return; // Evita clic ripetuti

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
            // Aspetta 1.5 secondi per mostrare "✓ Fatto", poi fa partire il cooldown
            setTimeout(() => { startBtnCooldown(btn, originalHTML); }, 1500);
        } else {
            startBtnCooldown(btn, originalHTML);
            showAiError(data, null); // Passiamo 'null' per rimuovere il tasto T('vocab.ins_riprova')
        }
    } catch (error) {
        startBtnCooldown(btn, originalHTML);
        showAiError({ error_type: 'network', error: error && error.message }, null);
    }
}

async function generateImage(btn, tier){
    if (btn.disabled) return; // Evita clic ripetuti

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
            // Aspetta 1.5 secondi per mostrare "✓ Creata", poi fa partire il cooldown
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
</script>
</body>
</html>