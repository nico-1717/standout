<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();

/* ============================================================
   IL SALVATAGGIO NON STA PIU' QUI
   ============================================================
   C'era un ricevitore "insert_bulk" che inseriva tutto in un colpo solo, alla
   fine. Adesso questa pagina salva quando vuole l'utente (o da sola, se
   l'interruttore e' acceso) e deve quindi saper anche AGGIORNARE una riga gia'
   inserita, non solo crearla: e' esattamente quello che fa big_edit_save.php.

   Tenere due copie della stessa logica - una che inserisce e una che aggiorna -
   voleva dire correggere ogni difetto in due posti, e dimenticarsene in uno.
   Le immagini abbinate per nome file non sono andate perse: adesso e' la pagina
   ad attaccare il file vero alla riga giusta al momento di generare la tabella,
   il che toglie di mezzo anche l'accoppiamento per nome.
   ============================================================ */

// ============================================================
// Categorie di questo utente (con supporto padre/figlio)
// ============================================================
$all_categories = [];
$categories_by_parent = [];
foreach (sec_all("SELECT id, name, parent_id, color FROM categories WHERE user_id = ? ORDER BY name",
                 [(int)$current_user_id]) as $row) {
    $all_categories[$row['id']] = $row;
    $categories_by_parent[$row['parent_id']][] = $row['id'];
}

// Categoria pre-selezionata (es. arrivo da inserimento.php con ?category_id=123)
$preselected_cat_id = 0;
$preselected_cat_name = '';
if (isset($_GET['category_id'])) {
    $preselected_cat_id = sec_int($_GET['category_id'] ?? 0, 0);
    if ($preselected_cat_id > 0 && isset($all_categories[$preselected_cat_id])) {
        $preselected_cat_name = $all_categories[$preselected_cat_id]['name'];
    } else {
        $preselected_cat_id = 0;
    }
}

// Lista indentata per il foglio "Categoria" (stessa estetica di categories.php / inserimento.php)
function big_render_cat_list($parentId, $all_cats, $cats_by_parent, $level = 0) {
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
        big_render_cat_list($id, $all_cats, $cats_by_parent, $level + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?php te('vocab.ib_titolo'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>inserimento_big.css?v=<?php echo @filemtime(dirname(__FILE__) . '/inserimento_big.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>autosave_bar.css?v=<?php echo @filemtime(dirname(__FILE__) . '/autosave_bar.css') ?: 1; ?>">
</head>
<body>

<?php include 'sidebar.html'; ?>

<header class="app-header">
    <div class="hdr-text">
        <h1><?php te('vocab.ins_multiplo'); ?></h1>
        <div class="hdr-sub"><?php te('vocab.ins_vocabolario'); ?></div>
    </div>
    <?php
    /* Salvataggio e interruttore dell'automatico: in alto, dove non ci finisce
       sopra la tastiera mentre si scrive. */
    include 'autosave_bar.php';
    ?>
    <div class="hdr-chip" id="row-count-chip"><?php te('vocab.ib_n_parole', ['n' => 0]); ?></div>
</header>

<div class="page-wrap">

    <div id="success-msg">
        <?php th('vocab.ib_completato', ['n' => '<strong id="success-count">0</strong>']); ?>
        <small><?php te('vocab.ib_ritorno'); ?></small>
    </div>

    <div class="format-help">
        <strong><?php te('vocab.ib_prompt_titolo'); ?></strong>
        <p><?php te('vocab.ib_prompt_sub'); ?></p>
        <div class="ai-prompt-box">
            <span class="ai-copy-hint"><?php te('vocab.ib_tocca_copiare'); ?></span>
            <textarea id="ai_prompt_textarea" readonly onclick="this.select(); navigator.clipboard.writeText(this.value).then(() => showToast('Copiato negli appunti')).catch(() => { document.execCommand('copy'); showToast('Copiato negli appunti'); });"></textarea>
        </div>
    </div>

    <form id="bigForm">
        <div class="setup-grid">
            <div class="card-surface setup-card full">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span><?php te('vocab.ib_categoria_dest'); ?></div>
                <button type="button" class="category-btn <?php echo $preselected_cat_id ? 'is-set' : ''; ?>" id="categoryBtn" onclick="openCatSheet()">
                    <span style="display:flex; align-items:center; gap:10px; min-width:0;">
                        <span class="cb-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>
                        <span id="categoryBtnLabel" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $preselected_cat_name !== '' ? sec_h($preselected_cat_name) : sec_h(t('vocab.ib_ph_seleziona')); ?></span>
                    </span>
                    <span class="cb-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
                </button>
                <input type="hidden" id="categoryInput" value="<?php echo (int)$preselected_cat_id; ?>">
            </div>

            <div class="card-surface setup-card">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M4 7h2.2L8 4h8l1.8 3H20a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/></svg></span><?php te('vocab.ib_immagini_opz'); ?></div>
                <label class="mini-label"><?php te('vocab.ib_una_per_parola'); ?></label>
                <input type="file" id="bulk_images" accept="image/*" multiple>
            </div>

            <div class="card-surface setup-card">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span><?php te('vocab.ib_importa_testo'); ?></div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; background:var(--surface-2); padding:10px 12px; border-radius:12px; border:1px solid var(--hairline-2);">
                    <label class="mini-label" style="margin:0; font-size:13.5px;"><?php te('vocab.ib_separatori_pers'); ?></label>
                    <input type="checkbox" id="format_switch" style="transform:scale(1.2); accent-color:var(--blue); cursor:pointer;" onchange="toggleImportFormat()">
                </div>

                <textarea id="import_text" rows="3" placeholder='Incolla qui l&#39;array JSON. Esempio: [{"en": "Run", "it": "Correre", ...}]' style="font-size:14.5px;"></textarea>

                <div class="import-row" id="custom_separators_row" style="display:none;">
                    <div>
                        <label class="mini-label"><?php te('vocab.ib_sep_parole'); ?></label>
                        <select id="import_row_separator" onchange="updatePlaceholder()">
                            <option value="@@@" selected><?php te('vocab.ib_sep_tre_chiocciole'); ?></option>
                            <option value="@"><?php te('vocab.ib_sep_chiocciola'); ?></option>
                            <option value="\n"><?php te('vocab.ib_sep_a_capo'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="mini-label"><?php te('vocab.ib_sep_campi'); ?></label>
                        <select id="import_separator" onchange="updatePlaceholder()">
                            <option value="###" selected><?php te('vocab.ib_sep_tre_hash'); ?></option>
                            <option value="|"><?php te('vocab.ib_sep_pipe'); ?></option>
                            <option value=";"><?php te('vocab.ib_sep_punto_virgola'); ?></option>
                        </select>
                    </div>
                </div>
                <p class="mini-label" style="margin-top:10px; font-weight:600;"><?php te('vocab.ib_ordine_campi'); ?></p>
            </div>
        </div>

        <button type="button" id="btn-generate-table" class="btn btn-warn" onclick="generateTable()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
            <?php te('vocab.ib_genera_tabella'); ?>
        </button>

        <div class="fc-rows" id="fcRows"></div>
        <div class="empty-hint" id="emptyHint"><?php te('vocab.ib_nessuna_parola'); ?></div>

        <div class="action-stack">
            <button type="button" class="btn btn-primary" onclick="addSingleRow()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <?php te('vocab.ib_aggiungi_vuota'); ?>
            </button>
            <button type="submit" id="submitBtn" class="btn btn-success" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
                <?php te('vocab.ib_salva_tutto'); ?>
            </button>
        </div>
    </form>
</div>

<!-- ============== FOGLIO CATEGORIA ============== -->
<div id="catSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title"><?php te('vocab.ins_scegli_cat'); ?></h3>
        <div class="cat-pick-list">
            <?php if (empty($all_categories)): ?>
                <div class="cat-pick-empty"><?php te('vocab.ins_nessuna_cat'); ?><br><a href="categories.php"><?php te('vocab.ins_creane_una'); ?></a>.</div>
            <?php else: ?>
                <?php big_render_cat_list(0, $all_categories, $categories_by_parent); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-panel">
        <h3 id="confirmTitle"><?php te('vocab.ib_sei_sicuro'); ?></h3>
        <p id="confirmMessage"></p>
        <div class="confirm-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn"><?php te('vocab.conferma_b'); ?></button>
        </div>
    </div>
</div>

<?php echo nisama_i18n_json(['comune', 'vocab']); ?>
<script>
const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';
const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';
const ICON_GEN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';
/* freccia che rientra: "figlio". Segno piu': "fratello". */
const ICON_KID     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5v6a3 3 0 0 0 3 3h9"/><path d="m13 10 4 4-4 4"/></svg>';
const ICON_SIBLING = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';

/* Il token di sessione viaggia insieme ai dati: cosi' il salvataggio funziona
   anche senza nisama_fetch.js. */
const CSRF_TOKEN = <?php echo json_encode(sec_csrf_token()); ?>;

/* Stato: la pagina non e' piu' un modulo che si invia una volta, e' un elenco
   di righe che possono essere gia' nel database oppure no. */
let isDirty = false;
function markDirty(){
    isDirty = true;
    if (window.NisamaSaveBar) NisamaSaveBar.touch();
}
/* Una riga cancellata resta nel DOM (nascosta) finche' il server non l'ha
   davvero tolta: se sparisse subito, il salvataggio non saprebbe che c'era
   qualcosa da eliminare. */
function righeVive(){ return document.querySelectorAll('#fcRows .fc-row:not([data-status="deleted"])'); }
function onRowChange(el){
    const row = el.closest ? el.closest('.fc-row') : null;
    if (row && row.dataset.status === 'clean') row.dataset.status = 'edited';
    markDirty();
}

function escAttr(s){
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
   TOAST & MODALE CONFERMA
   ============================================================ */
function showToast(msg, kind){
    const t = document.createElement('div');
    t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 320); }, 3200);
}
function showConfirm(title, message, okLabel){
    return new Promise(resolve => {
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
   CATEGORIA
   ============================================================ */
document.getElementById('catSheet').addEventListener('click', function(e){ if (e.target.id === 'catSheet') closeCatSheet(); });
function openCatSheet(){ document.getElementById('catSheet').classList.add('show'); }
function closeCatSheet(){ document.getElementById('catSheet').classList.remove('show'); }
function selezionaCategoria(id, btn){
    document.getElementById('categoryInput').value = id;
    document.getElementById('categoryBtnLabel').textContent = btn.dataset.name;
    document.getElementById('categoryBtn').classList.add('is-set');
    closeCatSheet();
}

/* ============================================================
   PROMPT IA (JSON preferito, adattato al vocabolario — nessun
   vincolo di formattazione, solo la struttura dati richiesta)
   ============================================================ */
function updateAIPrompt(){
    const isCustom = document.getElementById('format_switch').checked;
    let rule, exampleOutput;

    if (!isCustom) {
        rule = T('vocab.ib_rule_json');
        exampleOutput = `${T('vocab.ib_esempio_output')}
[
{"en": "Run", "it": "Correre", "meaning": "To move fast using your legs", "example": "I like to _ every morning"},
{"en": "Table", "it": "Tavolo", "meaning": "A piece of furniture with a flat top", "example": "Put the book on the _"}
]`;
    } else {
        const rowSepVal = document.getElementById('import_row_separator').value;
        const colSepVal = document.getElementById('import_separator').value;
        const rowSepText = rowSepVal === '\\n' ? T('vocab.ib_un_invio') : `"${rowSepVal}"`;
        const colSepText = colSepVal === '\\t' ? T('vocab.ib_una_tab') : `"${colSepVal}"`;
        const actualRowSep = rowSepVal === '\\n' ? '\n' : rowSepVal;

        rule = T('vocab.ib_rule_testo', {colonne: colSepText, righe: rowSepText});
        exampleOutput = `${T('vocab.ib_esempio_output')}
Run ${colSepVal} Correre ${colSepVal} To move fast using your legs ${colSepVal} I like to _ every morning${actualRowSep}Table ${colSepVal} Tavolo ${colSepVal} A piece of furniture with a flat top ${colSepVal} Put the book on the _`;
    }

    const finalPrompt = `${T('vocab.ib_prompt_intro')}

${rule}

${exampleOutput}

${T('vocab.ib_prompt_argomento')}`;

    document.getElementById('ai_prompt_textarea').value = finalPrompt;
}

function updatePlaceholder(){
    const isCustom = document.getElementById('format_switch').checked;
    const textArea = document.getElementById('import_text');
    if (isCustom) {
        let rowSep = document.getElementById('import_row_separator').value;
        let colSep = document.getElementById('import_separator').value;
        if (rowSep === '\\n') rowSep = 'Invio (a capo)';
        textArea.placeholder = `Incolla qui il testo generato dall'IA con ${rowSep} e ${colSep}…`;
    } else {
        textArea.placeholder = 'Incolla qui l\'array JSON. Esempio: [{"en": "Run", "it": "Correre", ...}]';
    }
    updateAIPrompt();
}
function toggleImportFormat(){
    const isCustom = document.getElementById('format_switch').checked;
    document.getElementById('custom_separators_row').style.display = isCustom ? 'flex' : 'none';
    updatePlaceholder();
}
window.addEventListener('DOMContentLoaded', () => { updatePlaceholder(); });

/* ============================================================
   GENERAZIONE TABELLA DA TESTO IMPORTATO
   ============================================================ */
async function generateTable(){
    const list = document.getElementById('fcRows');
    if (list.children.length > 0){
        const ok = await showConfirm(T('vocab.ib_ricreare'), T('vocab.ib_ricreare_txt'), T('vocab.ib_si_ricrea'));
        if (!ok) return;
    }
    list.innerHTML = '';

    const filesImg = document.getElementById('bulk_images').files;
    const textContent = document.getElementById('import_text').value.trim();
    const isCustom = document.getElementById('format_switch').checked;

    let parsedData = [];

    if (textContent !== '') {
        if (!isCustom) {
            try {
                let safeText = textContent.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"en"|"it"|"meaning"|"example"|\{))/g, '\\n');
                const jsonArr = JSON.parse(safeText);
                if (!Array.isArray(jsonArr)) throw new Error(T('vocab.ib_json_array'));
                parsedData = jsonArr.map(item => ({
                    en: item.en || item.english || item.word || item.description_en || '',
                    it: item.it || item.italian || item.description_it || '',
                    meaning: item.meaning || '',
                    example: item.example || '_'
                }));
            } catch (error) {
                showToast('Errore: il testo non è un JSON valido. Controlla la formattazione.', 'error');
                console.error(T('vocab.ib_json_dettaglio'), error);
                return;
            }
        } else {
            let colSeparator = document.getElementById('import_separator').value;
            if (colSeparator === '\\t') colSeparator = '\t';
            let rowSeparator = document.getElementById('import_row_separator').value;
            if (rowSeparator === '\\n') rowSeparator = '\n';

            const parsedLines = textContent.split(rowSeparator).map(l => l.trim()).filter(l => l !== '');
            parsedData = parsedLines.map(line => {
                const parts = line.split(colSeparator);
                return {
                    en: (parts[0] || '').trim(),
                    it: (parts[1] || '').trim(),
                    meaning: (parts[2] || '').trim(),
                    example: (parts[3] || '_').trim()
                };
            });
        }
    }

    const maxRows = Math.max(filesImg.length, parsedData.length);
    for (let i = 0; i < maxRows; i++){
        let en = '', it = '', meaning = '', example = '_';
        if (i < parsedData.length){ en = parsedData[i].en; it = parsedData[i].it; meaning = parsedData[i].meaning; example = parsedData[i].example || '_'; }
        const imgUrl = i < filesImg.length ? URL.createObjectURL(filesImg[i]) : '';
        const imgName = i < filesImg.length ? filesImg[i].name : '';
        /* Il file vero viaggia con la riga. Prima si mandava solo il nome e il
           server ripescava il file dal gruppo: un giro in piu' che poteva
           sbagliare bersaglio se due immagini si chiamavano uguale. */
        const file = i < filesImg.length ? filesImg[i] : null;
        appendRowHTML(Date.now() + i, en, it, meaning, example, imgUrl, imgName, { file: file });
    }
    markDirty();
    updateGenerateButtonState();
}

/* ============================================================
   RIGHE — creazione, anteprima immagine, rimozione
   ============================================================ */
function addSingleRow(){
    appendRowHTML(Date.now(), '', '', '', '_', '', '');
    markDirty();
    updateGenerateButtonState();
    const rows = righeVive();
    if (rows.length) rows[rows.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* Le righe di un blocco: il padre e i figli che lo seguono. */
function righeDelBlocco(padre){
    const righe = [padre];
    let cur = padre.nextElementSibling;
    while (cur && cur.dataset.isChild === '1') { righe.push(cur); cur = cur.nextElementSibling; }
    return righe;
}

/* Dal padre nasce un figlio (in fondo ai suoi figli), da un figlio nasce un
   fratello (subito sotto, quindi con lo stesso padre). Chi sia il padre non si
   scrive da nessuna parte: lo dice la posizione nell'elenco, ed e' cosi' anche
   al momento del salvataggio. */
function addKidFrom(btn){
    const row = btn.closest('.fc-row');
    const isChild = row.dataset.isChild === '1';
    const dopo = isChild ? row : righeDelBlocco(row).pop();

    const nuova = appendRowHTML(Date.now(), '', '', '', '_', '', '', { isChild: true, dopo: dopo });
    markDirty();
    updateGenerateButtonState();
    setTimeout(() => {
        nuova.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const campo = nuova.querySelector('.row-en');
        if (campo) campo.focus({ preventScroll: true });
    }, 80);
    return nuova;
}

/* opzioni: { isChild, dopo, file, dbId, status } */
function appendRowHTML(id, en, it, meaning, example, imgUrl, imgName, opzioni){
    opzioni = opzioni || {};
    const isChild = !!opzioni.isChild;
    const list = document.getElementById('fcRows');
    const wrap = document.createElement('div');
    wrap.className = 'fc-row' + (isChild ? ' is-child' : '');
    wrap.dataset.rid = id;
    wrap.dataset.id = opzioni.dbId || 0;          // 0 = non e' ancora nel database
    wrap.dataset.status = opzioni.status || 'new';
    wrap.dataset.isChild = isChild ? 1 : 0;
    if (opzioni.file) wrap.nuovoFile = opzioni.file;
    const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;
    wrap.innerHTML = `
        <div class="fc-row-content">
            <div class="fc-row-strip">
                <span class="row-badge">${isChild ? T('vocab.figlio') : T('vocab.parola')}</span>
                <span class="strip-spacer"></span>
                <button type="button" class="row-del-btn" onclick="deleteRow(this)" aria-label="<?php te('vocab.elimina_attr'); ?>">${ICON_TRASH}</button>
            </div>
            <div class="row-bilingual">
                <div class="row-col col-en">
                    <div class="row-eyebrow">${T('vocab.lbl_en')}</div>
                    <input type="text" class="row-en" value="${escAttr(en)}" placeholder="${T('vocab.ph_parola')}">
                </div>
                <div class="row-col col-it">
                    <div class="row-eyebrow">${T('vocab.lbl_it')}</div>
                    <input type="text" class="row-it" value="${escAttr(it)}" placeholder="${T('vocab.ph_traduzione')}">
                </div>
            </div>
            <div class="row-field">
                <div class="field-label">${T('vocab.significato')}</div>
                <input type="text" class="row-meaning" value="${escAttr(meaning)}" placeholder="${T('vocab.ph_spiegazione')}">
            </div>
            <div class="row-field">
                <div class="field-label">${T('vocab.esempio')}</div>
                <input type="text" class="row-example" value="${escAttr(example || '_')}" placeholder="${T('vocab.ph_frase')}">
            </div>
            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${id}')">
                    <span class="chip-thumb" id="img-preview-${id}">${thumb}</span>
                    <span class="img-name-tag">${imgName ? escAttr(imgName) : T('vocab.immagine')}</span>
                </label>
            </div>
            <input type="hidden" class="row-img-name" value="${imgName ? escAttr(imgName) : ''}">
            <div class="ns-row-foot">
                <button type="button" class="ns-add-kid" onclick="addKidFrom(this)">
                    ${isChild ? ICON_SIBLING + 'Aggiungi fratello' : ICON_KID + 'Aggiungi figlio'}
                </button>
            </div>
        </div>`;
    if (opzioni.dopo) opzioni.dopo.after(wrap); else list.appendChild(wrap);
    return wrap;
}

/* Qualunque digitazione o cambio marca la riga: un ascoltatore solo sul
   contenitore invece di un attributo su ogni campo. */
document.getElementById('fcRows').addEventListener('input',  e => onRowChange(e.target));
document.getElementById('fcRows').addEventListener('change', e => onRowChange(e.target));

function previewRowImage(input, id){
    if (input.files && input.files[0]){
        const reader = new FileReader();
        reader.onload = function(e){ document.getElementById('img-preview-' + id).innerHTML = `<img src="${e.target.result}">`; };
        reader.readAsDataURL(input.files[0]);
        const chip = input.closest('.img-chip');
        chip.classList.add('has-img');
        chip.querySelector('.img-name-tag').textContent = input.files[0].name;
        // Ora l'immagine di questa riga arriva dal file caricato direttamente qui,
        // non più dal pool "Immagini multiple" abbinato per nome file.
        const rowContent = input.closest('.fc-row-content');
        const hidden = rowContent ? rowContent.querySelector('.row-img-name') : null;
        if (hidden) hidden.value = '';
        const row = input.closest('.fc-row');
        if (row) delete row.nuovoFile;   // vince il file appena scelto qui
        onRowChange(input);
    }
}

function deleteRow(btn){
    const row = btn.closest('.fc-row');
    /* Eliminando un padre se ne vanno anche i figli: e' quello che fa il
       server, e la pagina deve mostrare la stessa cosa. */
    const famiglia = row.dataset.isChild === '1' ? [row] : righeDelBlocco(row);

    famiglia.forEach(r => {
        r.style.transition = 'opacity .25s var(--spring), transform .25s var(--spring)';
        r.style.opacity = '0';
        r.style.transform = 'scale(.95)';
        if (parseInt(r.dataset.id, 10) > 0) {
            /* Gia' nel database: resta qui, nascosta, finche' il salvataggio non
               l'ha eliminata per davvero. */
            r.dataset.status = 'deleted';
            setTimeout(() => { r.style.display = 'none'; updateGenerateButtonState(); }, 250);
        } else {
            setTimeout(() => { r.remove(); updateGenerateButtonState(); }, 250);
        }
    });
    markDirty();
}

/* ============================================================
   CONTATORI / STATO PULSANTI
   ============================================================ */
function updateRowCountChip(){
    const n = righeVive().length;
    const chip = document.getElementById('row-count-chip');
    if (chip) chip.textContent = n + T(n === 1 ? 'vocab.n_parola' : 'vocab.n_parole');
}
function toggleEmptyHint(){
    const hint = document.getElementById('emptyHint');
    if (hint) hint.style.display = righeVive().length ? 'none' : 'block';
}
function updateGenerateButtonState(){
    const list = document.getElementById('fcRows');
    const btn = document.getElementById('btn-generate-table');
    if (righeVive().length > 0){
        btn.classList.remove('btn-warn'); btn.classList.add('btn-flat-warn');
        btn.innerHTML = T('vocab.ib_tabella_piena');
    } else {
        btn.classList.add('btn-warn'); btn.classList.remove('btn-flat-warn');
        btn.innerHTML = ICON_GEN + T('vocab.ib_genera_tabella');
    }
    updateRowCountChip();
    toggleEmptyHint();
    document.getElementById('submitBtn').disabled = righeVive().length === 0;
}
updateGenerateButtonState();

/* ============================================================
   SALVATAGGIO
   ============================================================
   Una riga qui dentro puo' essere nuova (da inserire) oppure gia' salvata (da
   aggiornare): e' esattamente il lavoro di big_edit_save.php, quindi si manda
   li'. La pagina non si ricarica mai: il server risponde con l'id vero di ogni
   riga inviata e la pagina si allinea da sola.
   Restituisce true/false: e' quello che guarda la barra flottante per sapere
   se il salvataggio e' riuscito. */
async function salvaTutto(manuale){
    const catId = document.getElementById('categoryInput').value;
    if (!catId || catId === '0') {
        /* Senza categoria non si puo' scrivere niente. In automatico si tace e
           si riprova piu' tardi: un avviso ogni venticinque secondi sarebbe
           solo fastidioso. */
        if (manuale) { showToast(T('vocab.ib_prima_categoria'), 'error'); openCatSheet(); }
        return false;
    }

    const fd = new FormData();
    fd.append('action', 'save_big_edit');
    fd.append('category_id', catId);
    fd.append('csrf_token', CSRF_TOKEN);

    const rows = document.querySelectorAll('#fcRows .fc-row');
    const inviate = [];
    let indice = 0;

    /* Un figlio ha bisogno di sapere qual e' il suo padre. Se il padre e' gia'
       nel database basta il suo id; se nasce in questo stesso salvataggio l'id
       ancora non esiste, e allora si manda l'indice della sua riga
       nell'invio (parent_idx): a legare i due pensa il server. */
    let padreDbId = 0;
    let padreIdx  = -1;

    rows.forEach((row) => {
        const status  = row.dataset.status;
        const isChild = row.dataset.isChild === '1' ? 1 : 0;

        if (!isChild) {
            padreDbId = parseInt(row.dataset.id, 10) || 0;
            padreIdx  = (status === 'clean') ? -1 : indice;
        }

        if (status === 'clean') return;

        fd.append(`cards[${indice}][id]`, row.dataset.id);
        fd.append(`cards[${indice}][status]`, status);
        fd.append(`cards[${indice}][is_child]`, isChild);
        fd.append(`cards[${indice}][en]`, row.querySelector('.row-en').value);
        fd.append(`cards[${indice}][it]`, row.querySelector('.row-it').value);
        fd.append(`cards[${indice}][meaning]`, row.querySelector('.row-meaning').value);
        fd.append(`cards[${indice}][example]`, row.querySelector('.row-example').value);
        if (isChild) {
            fd.append(`cards[${indice}][parent_id]`, padreDbId);
            fd.append(`cards[${indice}][parent_idx]`, padreIdx);
        }

        /* L'immagine puo' venire dal campo della riga o dal gruppo abbinato al
           momento di generare la tabella: in tutti e due i casi e' un File. */
        const fileInput = row.querySelector('input[type=file]');
        const file = (fileInput && fileInput.files[0]) ? fileInput.files[0] : (row.nuovoFile || null);
        if (file) fd.append(`row_img[${indice}]`, file);

        inviate.push({ row: row, idx: indice });
        indice++;
    });

    if (inviate.length === 0) {
        if (manuale) showToast('Non c\u2019\u00e8 niente di nuovo da salvare.');
        isDirty = false;
        return true;
    }

    try {
        const res = await fetch('big_edit_save.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            applicaMappa(inviate, data.map || {});
            isDirty = false;
            if (manuale) showToast('Salvataggio completato');
            return true;
        }
        showToast('Errore durante il salvataggio: ' + (data.error || ''), 'error');
    } catch (err) {
        showToast(T('vocab.ib_errore_conn'), 'error');
    }
    return false;
}

/* Allinea la pagina a quello che il server ha davvero scritto. Le righe che il
   server ha saltato (una riga nuova e completamente vuota) non compaiono nella
   mappa: restano "nuove" e ci riproveranno al salvataggio successivo. */
function applicaMappa(inviate, mappa){
    inviate.forEach(function(v){
        const info = mappa[v.idx];
        if (!info) return;
        if (info.type === 'deleted') { v.row.remove(); return; }
        v.row.dataset.id = info.id;
        v.row.dataset.status = 'clean';
        /* Il file e' gia' sul server: se restasse qui verrebbe ricaricato tale e
           quale a ogni salvataggio successivo. */
        delete v.row.nuovoFile;
        v.row.querySelectorAll('input[type=file]').forEach(function(i){ i.value = ''; });
    });
    updateGenerateButtonState();
}

/* Il pulsante in fondo: salva e poi porta alla categoria. E' l'unica cosa che
   fa ancora "finire" la pagina; il salvataggio in se' non e' piu' un
   punto di non ritorno. */
document.getElementById('bigForm').addEventListener('submit', async function(e){
    e.preventDefault();

    if (righeVive().length === 0) { showToast(T('vocab.ib_almeno_una'), 'error'); return; }

    const subBtn = document.getElementById('submitBtn');
    const originalHTML = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = T('vocab.mi_salvataggio');

    const ok = await salvaTutto(true);
    if (!ok) {
        subBtn.disabled = false;
        subBtn.innerHTML = originalHTML;
        return;
    }
    if (window.NisamaSaveBar) NisamaSaveBar.markSaved();

    const catId = document.getElementById('categoryInput').value;
    document.getElementById('success-count').textContent = righeVive().length;
    document.getElementById('success-msg').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => { window.location.href = 'visualizzazione.php?category_id=' + catId; }, 2000);
});

/* Uscendo con delle modifiche non salvate il browser chiede conferma. */
window.addEventListener('beforeunload', function(e){ if (isDirty) { e.preventDefault(); e.returnValue = ''; } });

/* Barra flottante: pulsante sempre a portata di pollice e interruttore per il
   salvataggio automatico. L'aggancio aspetta il caricamento perche'
   autosave_bar.js viene incluso in fondo alla pagina. */
window.addEventListener('DOMContentLoaded', function(){
    if (window.NisamaSaveBar) NisamaSaveBar.init({ key: 'inserimento_big', save: salvaTutto });
});
</script>

</body>
</html>