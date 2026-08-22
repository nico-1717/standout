<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();

$catID = sec_int($_GET['category_id'] ?? 0, 1);

/* La categoria dev'essere sua: stessa verifica di prima, detta con la funzione
   che il documento di sicurezza indica per questo scopo. */
if (!sec_owns('categories', $catID, $current_user_id)) {
    die(sec_h(t('vocab.non_proprietario')));
}
$catName = (string)(sec_one("SELECT name FROM categories WHERE id = ?", [$catID])['name'] ?? '');

/* Le schede della categoria: i padri, e per ognuno i figli.
   I figli arrivano con una query sola invece di una per padre: con cento schede
   erano centouno viaggi al database. */
$words = [];
$padri = sec_all("SELECT * FROM images WHERE category_id = ? ORDER BY id DESC", [$catID]);

$figliPer = [];
if (!empty($padri)) {
    $ids = array_map(function ($r) { return (int)$r['id']; }, $padri);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (sec_all("SELECT * FROM english_child_words WHERE parent_id IN ($ph) ORDER BY id_child ASC", $ids) as $ch) {
        $figliPer[(int)$ch['parent_id']][] = $ch;
    }
}
foreach ($padri as $row) {
    $row['is_child'] = 0;
    $words[] = $row;
    foreach ($figliPer[(int)$row['id']] ?? [] as $child) {
        $child['is_child'] = 1;
        $words[] = $child;
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
<title><?php te('vocab.titolo_big_edit'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>big_edit.css?v=<?php echo @filemtime(dirname(__FILE__) . '/big_edit.css') ?: 1; ?>">
</head>
<body>

<?php include 'sidebar.html'; ?>

<header class="app-header">
    <button type="button" class="hdr-back" onclick="goBack()" aria-label="<?php te('vocab.torna_indietro'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <div class="hdr-text">
        <h1>Big Edit</h1>
        <div class="hdr-sub"><?php echo sec_h($catName); ?></div>
    </div>
    <span class="hdr-dirty-dot" title="<?php te('vocab.modifiche_non_salvate'); ?>"></span>
    <button type="button" id="hdrSaveBtn" class="btn btn-success btn-sm" style="width:auto; padding:7px 12px; font-size:13px; margin:0; box-shadow:var(--raise-sm);" onclick="saveAll()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg>
        Salva
    </button>
    <div class="hdr-chip" id="row-count-chip">0 parole</div>
</header>

<div class="page-wrap">
    <div class="fc-rows" id="fcRows"></div>
    <div class="empty-hint" id="emptyHint">Nessuna parola in questa categoria — tocca T('vocab.aggiungi_parola') per iniziare.</div>
        <div id="loadMoreContainer" style="display:none; text-align:center; padding: 10px 14px 20px;">
        <button type="button" class="btn btn-ghost" style="width:auto; display:inline-flex; border:1.5px solid var(--hairline-2); font-size:14px; padding:10px 20px; box-shadow:var(--raise-sm);" onclick="renderNextBatch(20)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;margin-right:6px;"><path d="M6 9l6 6 6-6"/></svg>
            Caricane altre 20
        </button>
    </div>
    <div class="action-stack">
        <button type="button" class="btn btn-primary" onclick="addRow()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Aggiungi Parola
        </button>
    </div>
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

<!-- POPUP ERRORI IA -->
<div id="aiErrorOverlay" class="modal-overlay">
    <div class="modal-content ai-err-panel">
        <div class="ai-err-icon" id="aiErrIcon"></div>
        <h3 class="ai-err-title" id="aiErrTitle">Errore</h3>
        <p class="ai-err-body" id="aiErrBody"></p>
        <p class="ai-err-detail" id="aiErrDetail" style="display:none;"></p>
        <div class="ai-err-actions">
            <button type="button" class="btn-cancel2" onclick="closeAiError()">Chiudi</button>
            <button type="button" class="btn-retry" id="aiErrRetryBtn" style="display:none;">Riprova</button>
        </div>
    </div>
</div>

<?php
/* Le stringhe che servono al JavaScript di questa pagina. */
echo nisama_i18n_json(['comune', 'vocab']);
?>
<script>
const CAT_ID = <?php echo (int)$catID; ?>;
const INITIAL_WORDS = <?php echo json_encode($words, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';
const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';
const ICON_SPARK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg>';
const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';

function escAttr(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

let isDirty = false;
function updateDirtyUI(){ document.body.classList.toggle('is-dirty', isDirty); }
function markDirty(){ isDirty = true; updateDirtyUI(); }

/* ---------- Toast & conferma ---------- */
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
        okBtn.textContent = okLabel || T('vocab.conferma_b');
        overlay.classList.add('show');
        function cleanup(result){ overlay.classList.remove('show'); okBtn.removeEventListener('click', onOk); cancelBtn.removeEventListener('click', onCancel); resolve(result); }
        function onOk(){ cleanup(true); }
        function onCancel(){ cleanup(false); }
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
    });
}

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
    no_image:       { title: T('vocab.ai_no_immagine'),           tone: 'warn',  retry: true,  body: T('vocab.ai_body_rate') },
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
document.getElementById('aiErrRetryBtn').addEventListener('click', function(){ const fn = aiErrorRetryFn; closeAiError(); if (fn) fn(); });
document.getElementById('aiErrorOverlay').addEventListener('click', function(e){ if (e.target.id === 'aiErrorOverlay') closeAiError(); });

/* ---------- Contatori ---------- */
function updateRowCountChip(){
    const n = document.querySelectorAll('#fcRows .fc-row').length;
    document.getElementById('row-count-chip').textContent = n + T(n === 1 ? 'vocab.n_parola' : 'vocab.n_parole');
}
function toggleEmptyHint(){ document.getElementById('emptyHint').style.display = document.getElementById('fcRows').children.length ? 'none' : 'block'; }

/* ---------- Creazione riga ---------- */
function rowTemplate(word){
    const isChild = !!parseInt(word.is_child);
    const id = word.__domId;
    const en = word.description_en || '', it = word.description_it || '', meaning = word.meaning || '', example = word.example || '_';
    const imgUrl = word.image ? ('<?php echo IMAGE_PATH_VIEW; ?>/' + word.image) : '';
    const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

    const wrap = document.createElement('div');
    wrap.className = 'fc-row' + (isChild ? ' is-child' : '');
    wrap.dataset.rid = id;
    wrap.dataset.id = word.__dbId || 0;
    wrap.dataset.status = word.__status || 'clean';
    wrap.dataset.isChild = isChild ? 1 : 0;
    wrap.dataset.parentIdx = '-1';

    wrap.innerHTML = `
        <div class="fc-row-content">
            <div class="fc-row-strip">
                <span class="row-badge">${isChild ? T('vocab.figlio') : T('vocab.parola')}</span>
                <span class="row-dirty-badge">${T('vocab.non_salvato')}</span>
                <span class="strip-spacer"></span>
                <button type="button" class="row-del-btn" onclick="deleteRow(this)" aria-label="${T('vocab.elimina_attr')}">${ICON_TRASH}</button>
            </div>
            <div class="row-bilingual">
                <div class="row-col col-en">
                    <div class="row-eyebrow">${T('vocab.lbl_en')}</div>
                    <input type="text" class="row-en" value="${escAttr(en)}" placeholder="${T('vocab.ph_parola')}" oninput="onRowChange(this)">
                </div>
                <div class="row-col col-it">
                    <div class="row-eyebrow">${T('vocab.lbl_it')}</div>
                    <input type="text" class="row-it" value="${escAttr(it)}" placeholder="${T('vocab.ph_traduzione')}" oninput="onRowChange(this)">
                </div>
            </div>
            <div class="row-field">
                <div class="field-label">${T('vocab.significato')}</div>
                <input type="text" class="row-meaning" value="${escAttr(meaning)}" placeholder="${T('vocab.ph_spiegazione')}" oninput="onRowChange(this)">
            </div>
            <div class="row-field">
                <div class="field-label">${T('vocab.esempio')}</div>
                <input type="text" class="row-example" value="${escAttr(example)}" placeholder="${T('vocab.ph_frase')}" oninput="onRowChange(this)">
            </div>
            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${id}')">
                    <span class="chip-thumb" id="img-preview-${id}">${thumb}</span>
                    <span class="img-name-tag">${T('vocab.immagine')}</span>
                </label>
                <button type="button" class="media-chip ai-row-pro" onclick="generateRowImage('${id}','pro')">
                    <span class="chip-thumb">${ICON_SPARK}</span>PRO
                </button>
                <button type="button" class="media-chip ai-row-basic" onclick="generateRowImage('${id}','basic')">
                    <span class="chip-thumb">${ICON_SPARK}</span>Basic
                </button>
            </div>
            <input type="hidden" class="row-img-name" value="${word.image ? escAttr(word.image) : ''}">
        </div>`;
    return wrap;
}

function onRowChange(input){
    const row = input.closest('.fc-row');
    if (row.dataset.status !== 'new') row.dataset.status = 'edited';
    markDirty();
}

function addRow(){
    const id = 'new_' + Date.now();
    const row = rowTemplate({ is_child: 0, description_en: '', description_it: '', meaning: '', example: '_', image: '', __domId: id, __dbId: 0, __status: 'new' });
    document.getElementById('fcRows').prepend(row);
    updateRowCountChip();
    toggleEmptyHint();
    markDirty();
    setTimeout(() => { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); row.querySelector('.row-en').focus({ preventScroll: true }); }, 80);
}

async function deleteRow(btn){
    const row = btn.closest('.fc-row');
    const isNew = row.dataset.status === 'new';
    if (!isNew) {
        const ok = await showConfirm(T('vocab.eliminare_parola'), T('vocab.eliminare_parola_txt'), T('comune.elimina'));
        if (!ok) return;
    }
    row.classList.add('is-removing');
    row.dataset.status = 'deleted';
    markDirty();
    setTimeout(() => { row.remove(); updateRowCountChip(); toggleEmptyHint(); }, 260);
}

/* ---------- Immagine per riga ---------- */
function previewRowImage(input, id){
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e){ document.getElementById('img-preview-' + id).innerHTML = `<img src="${e.target.result}">`; };
        reader.readAsDataURL(input.files[0]);
        const chip = input.closest('.img-chip');
        chip.classList.add('has-img');
        onRowChange(input);
    }
}

// Variabile globale per tenere traccia del tempo di attesa di 1 minuto
let aiCooldown = false;

async function generateRowImage(id, tier){
    // Se il blocco è attivo, impediamo ulteriori click
    if (aiCooldown) {
        showToast(T('vocab.ai_attendi'), 'warn');
        return;
    }

    const row = document.querySelector(`.fc-row[data-rid="${id}"]`);
    const word = row.querySelector('.row-en').value.trim();
    if (!word) { showToast(T('vocab.ai_manca_parola'), 'error'); return; }
    const example = row.querySelector('.row-example').value.trim();

    // --- INIZIO BLOCCO 1 MINUTO ---
    aiCooldown = true;
    const allAiButtons = document.querySelectorAll('.ai-row-pro, .ai-row-basic');
    
    // Disattiviamo visivamente e funzionalmente tutti i bottoni IA della pagina
    allAiButtons.forEach(b => {
        b.disabled = true;
        b.style.opacity = '0.5';
        b.style.cursor = 'not-allowed';
    });

    // Impostiamo il ripristino dopo 60.000 millisecondi (1 minuto)
    setTimeout(() => {
        aiCooldown = false;
        const currentAiButtons = document.querySelectorAll('.ai-row-pro, .ai-row-basic');
        currentAiButtons.forEach(b => {
            // Se il pulsante non sta caricando attivamente in questo istante
            if (!b.querySelector('.ai-spinner-sm')) {
                b.disabled = false;
                b.style.opacity = '1';
                b.style.cursor = 'pointer';
            }
        });
    }, 60000); 
    // --- FINE BLOCCO 1 MINUTO ---

    const endpoint = tier === 'pro' ? 'generate_ai_image.php' : 'generate_ai_image_pollination.php';
    const btn = row.querySelector(tier === 'pro' ? '.ai-row-pro' : '.ai-row-basic');
    const originalHTML = btn.innerHTML;
    
    // Mostriamo lo spinner solo sul pulsante cliccato, tenendolo ben visibile
    btn.innerHTML = '<span class="ai-spinner-sm"></span>' + (tier === 'pro' ? ' PRO' : ' Basic');
    btn.style.opacity = '1'; 

    const fd = new FormData();
    fd.append('word', word);
    fd.append('example', example);
    fd.append('directions', '');
    fd.append('is_linear', '0');

    try {
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success || data.status === 'success') {
            const byteCharacters = atob(data.base64);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) byteNumbers[i] = byteCharacters.charCodeAt(i);
            const blob = new Blob([new Uint8Array(byteNumbers)], { type: 'image/jpeg' });
            const safeWord = word.replace(/\s+/g, '_').toLowerCase();
            const file = new File([blob], safeWord + '_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            const fileInput = row.querySelector('input[type=file]');
            fileInput.files = dt.files;
            document.getElementById('img-preview-' + id).innerHTML = `<img src="data:image/jpeg;base64,${data.base64}">`;
            row.querySelector('.img-chip').classList.add('has-img');
            onRowChange(fileInput);
            showToast(T('vocab.ai_immagine_ok'));
        } else {
            showAiError(data, () => generateRowImage(id, tier));
        }
    } catch (err) {
        showAiError({ error_type: 'network', error: err && err.message }, () => generateRowImage(id, tier));
    } finally {
        // Ripristiniamo l'HTML originale del pulsante cliccato
        btn.innerHTML = originalHTML;
        
        // Se il minuto di cooldown è ancora in corso, assicuriamoci che torni opaco/disabilitato
        if (aiCooldown) {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        } else {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    }
}

/* ---------- Idratazione dati iniziali (con caricamento a blocchi) ---------- */
let currentRenderIndex = 0;

function renderNextBatch(amount) {
    let count = 0;
    const container = document.getElementById('fcRows');
    
    while (currentRenderIndex < INITIAL_WORDS.length) {
        const w = INITIAL_WORDS[currentRenderIndex];
        const id = w.is_child ? ('child_' + w.id_child) : ('father_' + w.id);
        
        w.__domId = id;
        w.__dbId = w.is_child ? w.id_child : w.id;
        w.__status = 'clean';
        
        const row = rowTemplate(w);
        container.appendChild(row);
        
        currentRenderIndex++;
        count++;
        
        // Condizione di stop: fermati se abbiamo raggiunto il numero richiesto,
        // MA assicurati che la PROSSIMA parola non sia un figlio (non separare le famiglie)
        if (count >= amount) {
            if (currentRenderIndex < INITIAL_WORDS.length) {
                const nextWord = INITIAL_WORDS[currentRenderIndex];
                if (parseInt(nextWord.is_child) !== 1) {
                    break; // La prossima è un padre, possiamo fermarci sicuri
                }
            }
        }
    }
    
    updateRowCountChip();
    toggleEmptyHint();
    
    // Mostra o nascondi il bottone "Caricane altre 20"
    const btnContainer = document.getElementById('loadMoreContainer');
    if (btnContainer) {
        btnContainer.style.display = (currentRenderIndex < INITIAL_WORDS.length) ? 'block' : 'none';
    }
}

function hydrateInitialWords(){
    renderNextBatch(50); // Inizialmente ne carichiamo 50
}
hydrateInitialWords();

/* ---------- Uscita / Salvataggio ---------- */
async function goBack(){
    if (isDirty) {
        const ok = await showConfirm('Modifiche non salvate', T('vocab.uscire_txt'), T('vocab.esci_senza_salvare'));
        if (!ok) return;
    }
    window.location.href = 'visualizzazione.php?category_id=' + CAT_ID;
}
window.addEventListener('beforeunload', function(e){ if (isDirty) { e.preventDefault(); e.returnValue = ''; } });

async function saveAll(){
    const hdrBtn = document.getElementById('hdrSaveBtn');
    
    // 1. Disabilita il pulsante e mostra lo spinner
    hdrBtn.disabled = true; 
    hdrBtn.innerHTML = '<span class="spinner" style="width:13px;height:13px;border-width:2px; display:inline-block; vertical-align:middle; margin-right:5px;"></span> Salvataggio...'; 

    const fd = new FormData();
    fd.append('action', 'save_big_edit');
    fd.append('category_id', CAT_ID);

    const rows = document.querySelectorAll('#fcRows .fc-row');
    let elementiModificati = 0;

    // 2. Cicliamo tutte le righe, ma processiamo SOLO quelle non "clean"
    rows.forEach((row) => {
        const status = row.dataset.status;
        
        // Se la riga non è stata toccata, la ignoriamo completamente
        if (status === 'clean') return; 

        const isChild = row.dataset.isChild === '1' ? 1 : 0;
        
        fd.append(`cards[${elementiModificati}][id]`, row.dataset.id);
        fd.append(`cards[${elementiModificati}][status]`, status);
        fd.append(`cards[${elementiModificati}][is_child]`, isChild);
        fd.append(`cards[${elementiModificati}][en]`, row.querySelector('.row-en').value);
        fd.append(`cards[${elementiModificati}][it]`, row.querySelector('.row-it').value);
        fd.append(`cards[${elementiModificati}][meaning]`, row.querySelector('.row-meaning').value);
        fd.append(`cards[${elementiModificati}][example]`, row.querySelector('.row-example').value);
        
        const fileInput = row.querySelector('input[type=file]');
        if (fileInput && fileInput.files[0]) { 
            fd.append(`row_img[${elementiModificati}]`, fileInput.files[0]); 
        }

        elementiModificati++;
    });

    const resetButtons = () => {
        hdrBtn.disabled = false; 
        hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
    };

    // 3. Se non ci sono modifiche, fermiamo tutto ed evitiamo chiamate inutili al server
    if (elementiModificati === 0) {
        showToast(T('vocab.nessuna_modifica'));
        resetButtons();
        return;
    }

    try {
        const res = await fetch('big_edit_save.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            isDirty = false;
            updateDirtyUI();
            showToast(T('vocab.salvataggio_ok'));
            setTimeout(() => window.location.reload(), 700);
        } else {
            showToast('Errore durante il salvataggio: ' + (data.error || ''), 'error');
            resetButtons();
        }
    } catch (e) {
        showToast(T('vocab.errore_rete_p'), 'error');
        resetButtons();
    }
}
</script>
</body>
</html>