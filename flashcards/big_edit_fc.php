<?php



// connessione al database

require_once 'config.php';

require_once 'auth_check.php'; // 1. Protezione con login



$current_user_id = (int)$utente_corrente['id']; // 2. ID utente sicuro
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

// --- CARICAMENTO DATI ---
$catID = sec_int($_GET['category_id'] ?? 0, 1);

// La categoria dev'essere sua
if (!sec_owns('fc_categories', $catID, $current_user_id)) {
    die(sec_h(t('fc.non_proprietario_categoria')));
}

$cards = [];
// Le carte principali (padri o indipendenti)
$padri = sec_all("SELECT i.*, (SELECT COUNT(*) FROM fc_examples e WHERE e.fc_image_id = i.id) AS ex_count,
                         0 AS is_child
                  FROM fc_images i WHERE i.category_id = ? ORDER BY i.custom_order ASC", [$catID]);

/* I figli con UNA query invece di una per padre: con cento concetti erano
   centouno viaggi al database, uno dietro l'altro. */
$figliPer = [];
if (!empty($padri)) {
    $ids = array_map(function ($r) { return (int)$r['id']; }, $padri);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (sec_all("SELECT c.*, c.id_child AS id, 1 AS is_child, 0 AS ex_count
                      FROM fc_child_cards c WHERE c.parent_id IN ($ph)
                      ORDER BY c.custom_order ASC", $ids) as $child) {
        $figliPer[(int)$child['parent_id']][] = $child;
    }
}

foreach ($padri as $row) {
    $row['id_fcimg'] = $row['id'];
    $cards[] = $row;
    foreach ($figliPer[(int)$row['id']] ?? [] as $child) {
        $child['id_fcimg'] = $child['id'];
        $cards[] = $child;
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







    <title><?php te('fc.befc_titolo'); ?></title>















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















<link rel="stylesheet" href="<?php echo PWA_BASE; ?>big_edit_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/big_edit_fc.css') ?: 1; ?>">





















    <script src="fc_parser.js?v=<?php echo time(); ?>"></script>







</head>







<body>















<div id="parent-selection-banner">







    <span><?php te('fc.befc_tocca_padre'); ?></span>







    <button type="button" onclick="cancelChildSelection()"><?php te('fc.befc_annulla_x'); ?></button>







</div>















<header class="app-header">

    <button type="button" class="hdr-back" onclick="goBack()" aria-label=T('vocab.torna_indietro')>

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>

    </button>

    <div class="hdr-text" style="flex:1;">

        <h1><?php te('vocab.vi_big_edit'); ?></h1>

        <div class="hdr-sub"><?php te('fc.befc_sottotitolo'); ?></div>

    </div>

    

    <div style="display:flex; align-items:center; gap:10px;">

        <span class="hdr-dirty-dot" title="Modifiche non salvate"></span>

        

        <button type="button" id="hdrSaveBtn" class="btn btn-success btn-sm" style="width:auto; padding:7px 12px; font-size:13px; margin:0; box-shadow:var(--raise-sm);" onclick="saveAll()">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg>

            <?php te('comune.salva'); ?>

        </button>

        

        <div class="hdr-chip" id="row-count-chip"><?php te('fc.ibf_n_righe', ['n' => 0]); ?></div>

    </div>

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
    <div class="fc-rows" id="fcRows"></div>
    <div class="empty-hint" id="emptyHint"><?php te('fc.befc_nessuna'); ?></div>
    <div id="loadMoreContainer" style="display:none; text-align:center; padding: 10px 14px 20px;">
        <button type="button" class="btn btn-ghost" style="width:auto; display:inline-flex; border:1.5px solid var(--hairline-2); font-size:14px; padding:10px 20px; box-shadow:var(--raise-sm);" onclick="renderNextBatch(20)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;margin-right:6px;"><path d="M6 9l6 6 6-6"/></svg>
            <?php te('fc.befc_carica_altre'); ?>
        </button>
    </div>
    <div class="action-stack">
        <button type="button" class="btn btn-primary" onclick="addRow()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            <?php te('fc.befc_aggiungi_riga'); ?>
        </button>
    </div>
</div>

<div class="save-bar">
    <button type="button" id="saveBtn" class="btn btn-success" onclick="saveAll()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
        <?php te('fc.befc_salva_tutte'); ?>
    </button>
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















<script>







    const CAT_ID = <?php echo (int)$catID; ?>;







    const INITIAL_CARDS = <?php echo json_encode($cards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;







</script>







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







    const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';







    const ICON_TRASH_SM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';







    const ICON_UNDO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h11a5 5 0 0 1 0 10H9M4 10l5-5M4 10l5 5"/></svg>';















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







    let isDirty = false;















    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }







    function isChildRow(row){ return row.classList.contains('is-child'); }







    function getBlockStart(row){ let r = row; while (r && isChildRow(r)) r = r.previousElementSibling; return r; }







    function getBlockRows(parentRow){







        const rows = [parentRow];







        let cur = parentRow.nextElementSibling;







        while (cur && isChildRow(cur)) { rows.push(cur); cur = cur.nextElementSibling; }







        return rows;







    }















    function updateDirtyUI(){ document.body.classList.toggle('is-dirty', isDirty); }















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







            okBtn.textContent = okLabel || T('vocab.conferma_b');







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







       FLIP â€” animazioni fluide su ogni riordino/mutazione del DOM







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







       CONTATORI / STATO RIGA







       ============================================================ */







    function updateRowCountChip(){







        const n = document.querySelectorAll('#fcRows .fc-row').length;







        const chip = document.getElementById('row-count-chip');







        if (chip) chip.textContent = n + T(n === 1 ? 'fc.ibf_una_riga' : 'fc.ibf_n_righe_plur', {n: n});







    }







    function toggleEmptyHint(){







        const hint = document.getElementById('emptyHint');







        if (hint) hint.style.display = document.getElementById('fcRows').children.length ? 'none' : 'block';







    }







    function updateStatusVisual(row){







        row.classList.remove('status-new', 'status-edited');







        if (row.dataset.status === 'new') row.classList.add('status-new');







        else if (row.dataset.status === 'edited') row.classList.add('status-edited');







    }

    function markEdited(el){
        const row = el.classList && el.classList.contains('fc-row') ? el : el.closest('.fc-row');
        if (!row) return;
        if (row.dataset.status === 'clean') row.dataset.status = 'edited';
        updateStatusVisual(row);
        isDirty = true;
        updateDirtyUI();
    }

    // Intercetta qualsiasi digitazione o cambio nei campi di testo
    document.getElementById('fcRows').addEventListener('input', function(e) {
        markEdited(e.target);
    });
    
    document.getElementById('fcRows').addEventListener('change', function(e) {
        markEdited(e.target);
    });

    /* ============================================================
       MOTORE PREVIEW / AUTOGROW
       (nomi dedicati per non entrare in conflitto con examples_modal.php,
       che definisce le sue proprie applyFormat/updatePreview globali)
       ============================================================ */

    let mathJaxTimeouts = {};







    







    















    















    /* ============================================================







       CHIP ESEMPI â€” si aggancia a examples_modal.php







       IMPORTANTE: examples_modal.php, dopo un salvataggio/eliminazione di un







       esempio, sovrascrive DIRETTAMENTE innerText + style.background/color/







       borderColor del bottone #btn-ex-{id}. Per restare sempre coerenti anche







       dopo quell'aggiornamento, qui usiamo lo STESSO identico testo/colori







       che usa quello script: la classe .ex-chip gestisce solo forma e







       animazione, mai i colori.







       ============================================================ */







    function exChipHTML(domId, dbId, isNew, exCount){

        if (isNew){

            return `<button type="button" class="ex-chip ex-disabled" style="background:var(--surface-3); color:var(--text-3); border:1.5px solid transparent;"

                onclick="showToast('Salva la tabella per generare un ID a questa carta e poter aggiungere esempi.')">${T('fc.befc_esempi')}</button>`;

        }

        const hasEx = exCount > 0;

        const bg = hasEx ? '#8e24aa' : '#f1f3f5';

        const color = hasEx ? '#fff' : '#555';

        const border = hasEx ? '#8e24aa' : '#ccc';

        const label = hasEx ? `💡 Esempi (${exCount})` : T('fc.exm_aggiungi');

        

        // Il suffisso _hidden è stato rimosso per puntare ai campi corretti della toolbar

        return `<button type="button" id="btn-ex-${dbId}" class="ex-chip" style="background:${bg}; color:${color}; border:1.5px solid ${border};"

            onclick="openExamplesModal(${dbId}, document.getElementById('q-desc-${domId}').value, document.getElementById('a-desc-${domId}').value)">${label}</button>`;

    }







</script>







<script>







    function fieldTemplate(prefix, id, text, label, imgUrl, imgName, audioUrl, isChild){

        const taId = `${prefix}-desc-${id}`;

        const imgPrevId = `${prefix}-img-preview-${id}`;

        const placeholder = prefix === 'q'

            ? (isChild ? '↳ Sottodomanda (Figlio)…' : 'Domanda / LaTeX…')

            : T('fc.ibf_ph_risposta');

        const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

        const audioClass = audioUrl ? 'audio-player has-audio' : 'audio-player';

        const audioSrc = audioUrl ? ` src="${escAttr(audioUrl)}"` : '';



        // 1. Preleviamo l'HTML base generato dal tuo format_toolbar.php

        let editorHtml = document.getElementById('editor-template-container').innerHTML;

        

        // 2. Sostituiamo i segnaposto con i dati veri di questa specifica flashcard.

        // Nota: usiamo () => escAttr(text) per evitare che i simboli '$' (usati 

        // in LaTeX) distruggano le regex di JavaScript.

        editorHtml = editorHtml

            .replace(/%%FIELD_ID%%/g, () => taId)

            .replace(/%%RAW_TEXT%%/g, () => escAttr(text))

            .replace(/%%PLACEHOLDER%%/g, () => placeholder);



        // 3. Ritorniamo il blocco pulito

        return `

        <div class="fc-field">

            <div class="field-label ${prefix === 'a' ? 'is-answer' : ''}">${label}</div>

            

            ${editorHtml}



            <div class="media-row">

                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="${prefix}-chip-${id}">

                    <input type="file" accept="image/*" onchange="markEdited(this); previewRowImage(this, '${prefix}', '${id}')">

                    <span class="chip-thumb" id="${imgPrevId}">${thumb}</span>

                    <span class="img-name-tag">${imgName ? escAttr(imgName) : T('vocab.immagine')}</span>

                </label>

                <button type="button" class="media-chip btn-rec" onclick="toggleRowRecord(this, '${prefix}')">

                    <span class="chip-thumb"><span class="rec-dot"></span></span>

                    <span class="rec-label">${T('fc.ibfc_registra')}</span>

                </button>

                <audio class="${audioClass} audio-player-${prefix}" controls${audioSrc}></audio>

            </div>

            <input type="hidden" class="${prefix}-img-name" value="${imgName ? escAttr(imgName) : ''}">

        </div>`;

    }















    function appendRowHTML(card, referenceNode){







        const list = document.getElementById('fcRows');







        const isChild = !!card.isChild;







        const wrap = document.createElement('div');







        wrap.className = 'fc-row data-row' + (isChild ? ' is-child' : '');







        wrap.dataset.rid = card.domId;







        wrap.dataset.id = card.dbId;







        wrap.dataset.status = card.status;







        wrap.dataset.origType = card.origType;







        wrap.dataset.cascade = '';







        wrap.innerHTML = `







            <div class="swipe-actions">







                <button type="button" class="swipe-btn swipe-btn-delete" onclick="swipeDeleteRow(this)">${ICON_TRASH}<span>${T('comune.elimina')}</span></button>







                <button type="button" class="swipe-btn swipe-btn-join" onclick="swipeJoinRow(this)">${ICON_LINK}<span>${T('fc.ibfc_figlio')}</span></button>







                <button type="button" class="swipe-btn swipe-btn-unjoin" onclick="swipeUnjoinRow(this)">${ICON_UNLINK}<span>${T('fc.ibfc_sgancia')}</span></button>







            </div>







            <div class="fc-row-content">







                <div class="deleted-stub">







                    <span class="stub-icon">${ICON_TRASH_SM}</span>







                    <span class="stub-text"></span>







                    <button type="button" class="btn-restore" onclick="onRestoreClick(this)">${ICON_UNDO}<span>${T('fc.befc_ripristina')}</span></button>







                </div>







                <div class="fc-row-strip">







                    <span class="grip">${ICON_GRIP}</span>







                    <span class="row-badge">${isChild ? T('vocab.figlio') : T('fc.ibf_concetto')}</span>







                    <span class="status-badge sb-new">${T('vocab.mi_nuova')}</span>







                    <span class="status-badge sb-edited">${T('fc.befc_modificata')}</span>







                    <span class="strip-spacer"></span>







                    ${exChipHTML(card.domId, card.dbId, card.status === 'new', card.exCount || 0)}







                    <span class="strip-hint">${ICON_REORDER}<span>${T('fc.befc_tieni_premuto')}</span></span>







                </div>







                <div class="fc-row-grid">







                    ${fieldTemplate('q', card.domId, card.q, 'Domanda', card.qImgUrl, card.qImgName, card.qAudioUrl, isChild)}







                    ${fieldTemplate('a', card.domId, card.a, 'Risposta', card.aImgUrl, card.aImgName, card.aAudioUrl, isChild)}







                </div>







            </div>`;







        if (referenceNode) list.insertBefore(wrap, referenceNode); else list.appendChild(wrap);







        if (referenceNode) list.insertBefore(wrap, referenceNode); else list.appendChild(wrap);







        // INIZIALIZZA I NUOVI EDITOR DELLA RIGA



        wrap.querySelectorAll('.fmt-editor-wrap').forEach(function(editorWrap) {



            if (typeof fcInitEditorWrap === 'function') fcInitEditorWrap(editorWrap);



        });







        updateStatusVisual(wrap);







        updateRowCountChip();







        toggleEmptyHint();







        return wrap;







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







            if (nameTag) nameTag.textContent = T('vocab.immagine');







            if (type === 'q') delete row.qNewImgFile; else delete row.aNewImgFile;







        }







    }















    /* ============================================================







       REGISTRAZIONE AUDIO







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







        if (currentRecorder) { showToast(T('fc.ibf_ferma_registrazione'), 'error'); return; }















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















                if (player) { player.src = URL.createObjectURL(blob); player.classList.add('has-audio'); }







                if (type === 'q') { row.qAudioBlob = blob; row.qAudioExt = ext; }







                else { row.aAudioBlob = blob; row.aAudioExt = ext; }















                markEdited(row);







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







            showToast(T('fc.ifc_no_microfono'), 'error');







        }







    }







</script>







<script>







    /* ============================================================







       BADGE / STATO FIGLIO / SWIPE ACTIONS







       ============================================================ */







    function updateRowBadgeText(row){







        const badge = row.querySelector('.row-badge');







        if (badge) badge.textContent = isChildRow(row) ? T('vocab.figlio') : T('fc.ibf_concetto');







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















    /* ============================================================







       ELIMINAZIONE TEMPORANEA (con cascata) / RIPRISTINO







       Le righe "nuove" (mai salvate) vengono rimosse subito per davvero.







       Le righe esistenti diventano uno "stub" compatto finchÃ© non si salva.







       ============================================================ */







    function collapseToStub(row){







        row.classList.add('is-deleted');







        closeSwipe(row);







        const stub = row.querySelector('.stub-text');







        const qVal = (row.querySelector('.q-desc') || {}).value || '';







        if (stub) stub.textContent = qVal.trim() ? qVal.trim() : T('fc.befc_vuota');







    }















    function hardRemoveRow(row){







        row.style.transition = 'transform .22s var(--spring), opacity .22s var(--spring)';







        row.style.transform = 'translateX(105%) scale(.95)';







        row.style.opacity = '0';







        row.style.pointerEvents = 'none';







        setTimeout(()=>{ row.remove(); updateRowCountChip(); toggleEmptyHint(); }, 220);







    }















    function deleteRowSoft(row, cascade){







        const wasParent = !isChildRow(row);







        let children = [];







        if (cascade && wasParent) {







            children = getBlockRows(row).slice(1).filter(c => c.dataset.status !== 'deleted');







        }







        if (row.dataset.status === 'new') {







            hardRemoveRow(row);







        } else {







            row.dataset.status = 'deleted';







            updateStatusVisual(row);







            collapseToStub(row);







        }







        children.forEach(child=>{







            child.dataset.cascade = '1';







            deleteRowSoft(child, false);







        });







        isDirty = true;







        updateDirtyUI();







    }















    function restoreRow(row){







        row.dataset.status = 'edited';







        row.classList.remove('is-deleted');







        updateStatusVisual(row);







        if (!isChildRow(row)) {







            let cur = row.nextElementSibling;







            while (cur && isChildRow(cur)) {







                const next = cur.nextElementSibling;







                if (cur.dataset.cascade === '1' && cur.dataset.status === 'deleted') {







                    cur.dataset.cascade = '';







                    restoreRow(cur);







                }







                cur = next;







            }







        }







        isDirty = true;







        updateDirtyUI();







    }















    function swipeDeleteRow(btn){







        const row = btn.closest('.fc-row');







        flip(document.getElementById('fcRows'), ()=> deleteRowSoft(row, true));







        updateRowCountChip();







    }







    function onRestoreClick(btn){







        const row = btn.closest('.fc-row');







        flip(document.getElementById('fcRows'), ()=> restoreRow(row));







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







        if (qArea && qArea.placeholder.indexOf(T('fc.ibf_sottodomanda')) !== -1) qArea.placeholder = T('fc.ibf_ph_domanda');















        // Sposta la riga fisicamente alla fine del blocco dei figli attuali,







        // altrimenti "catturerebbe" come propri figli i suoi ex fratelli minori.







        let lastChild = row;







        while (lastChild.nextElementSibling && lastChild.nextElementSibling.classList.contains('is-child')) {







            lastChild = lastChild.nextElementSibling;







        }







        if (lastChild !== row) {







            flip(document.getElementById('fcRows'), ()=> lastChild.after(row));







        }















        closeSwipe(row);







        markEdited(row);







        showToast(T('fc.ibf_sganciato'));







    }















    function markEligibleTargets(){







        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







            r.classList.remove('eligible-target');







            if (r !== childRowPending && !isChildRow(r) && !r.classList.contains('is-deleted')) r.classList.add('eligible-target');







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















        if (clickedRow.classList.contains('is-deleted')) {







            showToast(T('fc.befc_riga_eliminata'), 'error');







            return;







        }







        if (isChildRow(clickedRow)) {







            showToast(T('fc.ibf_no_figlio_su_figlio'), 'error');







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







        if (qArea) qArea.placeholder = T('fc.ibf_ph_sottodomanda');







        closeSwipe(pending);







        buzz(14);







        cancelChildSelection();







        markEdited(pending);







        showToast(T('fc.ibf_agganciato'));







    });







</script>







<script>















    /* ============================================================







       MOTORE GESTURE â€” swipe orizzontale + riordino a pressione prolungata







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







            if (row.classList.contains('is-deleted')) return null;







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







                    // Attiva il riordino SOLO se il dito Ã¨ rimasto davvero fermo:







                    // se nel frattempo si Ã¨ mosso (anche solo per iniziare uno scroll







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















            // PrioritÃ  allo scroll nativo: appena il movimento non Ã¨ NETTAMENTE







            // orizzontale, molliamo subito il gesto e lasciamo scorrere la pagina.







            // Un dito vero non Ã¨ mai perfettamente dritto: meglio richiedere uno







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







            ctx.blockRows.forEach(r=> markEdited(r));







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







</script>







<script>

    /* ============================================================
       AGGIUNGI RIGA
       ============================================================ */

    function addRow(){
        const domId = Date.now();
        const card = {
            domId, dbId: 0, status: 'new', origType: 'parent', isChild: false,
            q: '', a: '', qImgUrl: '', qImgName: '', aImgUrl: '', aImgName: '',
            qAudioUrl: '', aAudioUrl: '', exCount: 0
        };
        const row = appendRowHTML(card);
        isDirty = true;
        updateDirtyUI();
        setTimeout(()=>{
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const editorDiv = document.getElementById('q-desc-' + domId + '_editor');
            if (editorDiv) editorDiv.focus({ preventScroll: true });
        }, 80);
    }

/* ============================================================
   IDRATAZIONE DATI INIZIALI (con caricamento a blocchi sicuri)
   ============================================================ */
let currentRenderIndex = 0;

function renderNextBatch(amount) {
    let count = 0;
    
    while (currentRenderIndex < INITIAL_CARDS.length) {
        const c = INITIAL_CARDS[currentRenderIndex];
        const id = c.id_fcimg;
        const isChild = !!parseInt(c.is_child);
        
        const card = {
            domId: id, dbId: id, status: 'clean',
            origType: isChild ? 'child' : 'parent',
            isChild,
            q: c.question_desc || '',
            a: c.answer_desc || '',
            qImgUrl: c.question_image ? ('fc_Images/' + c.question_image) : '',
            qImgName: c.question_image || '',
            aImgUrl: c.answer_image ? ('fc_Images/' + c.answer_image) : '',
            aImgName: c.answer_image || '',
            qAudioUrl: c.question_audio ? ('fc_audio/' + c.question_audio) : '',
            aAudioUrl: c.answer_audio ? ('fc_audio/' + c.answer_audio) : '',
            exCount: parseInt(c.ex_count) || 0
        };
        
        appendRowHTML(card);
        
        currentRenderIndex++;
        count++;
        
        // Condizione di stop sicura per non separare padre dai figli
        if (count >= amount) {
            if (currentRenderIndex < INITIAL_CARDS.length) {
                const nextCard = INITIAL_CARDS[currentRenderIndex];
                if (parseInt(nextCard.is_child) !== 1) {
                    break;
                }
            }
        }
    }
    
    const btnContainer = document.getElementById('loadMoreContainer');
    if (btnContainer) {
        btnContainer.style.display = (currentRenderIndex < INITIAL_CARDS.length) ? 'block' : 'none';
    }
}

function hydrateInitialCards(){
    renderNextBatch(50);
}

    /* ============================================================
       USCITA / SALVATAGGIO
       ============================================================ */

    async function goBack(){
        if (isDirty) {
            const ok = await showConfirm(
                T('vocab.modifiche_non_salvate'),
                'Hai delle modifiche che non sono ancora state salvate. Se esci ora andranno perse.',
                'Esci senza salvare'
            );
            if (!ok) return;
        }
        window.location.href = 'visualizzazione_fc.php?category_id=' + CAT_ID;
    }

    async function saveAll(){
        try {
            if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();

            // Catturiamo SOLO il bottone in alto
            const hdrBtn = document.getElementById('hdrSaveBtn');

            // ==========================================
            // AUTO-RILEVAMENTO CAMBIAMENTI STRUTTURALI
            // ==========================================
            let haCambiatoOrdine = false;
            const rows = document.querySelectorAll('#fcRows .fc-row.data-row');
            
            // Modifica: usiamo 'currentRenderIndex' per capire se sono state aggiunte/tolte righe
            if (rows.length !== currentRenderIndex) {
                haCambiatoOrdine = true;
            } else {
                rows.forEach((row, i) => {
                    const orig = INITIAL_CARDS[i];
                    const isChildNow = row.classList.contains('is-child') ? 1 : 0;
                    
                    if (!orig || row.dataset.id != orig.id_fcimg || isChildNow != orig.is_child || row.dataset.status === 'deleted') {
                        haCambiatoOrdine = true;
                    }
                });
            }

            const fd = new FormData();
            fd.append('action', 'save_big_edit');
            fd.append('category_id', CAT_ID);
            fd.append('reordered', haCambiatoOrdine ? '1' : '0');

            let currentParentIndex = -1;
            let elementiModificati = 0;
            let indiceInvio = 0;

            rows.forEach((row) => {
                const status = row.dataset.status;
                
                // Saltiamo le righe non toccate se non c'è stato riordino strutturale
                if (!haCambiatoOrdine && status === 'clean') return;

                if (status !== 'clean') elementiModificati++;

                const isChild = row.classList.contains('is-child') ? 1 : 0;
                if (!isChild) currentParentIndex = indiceInvio; 
                
                // RECUPERO CORRETTO DEI CAMPI DI TESTO TRAMITE ID
                const rid = row.dataset.rid;
                const qInput = document.getElementById('q-desc-' + rid);
                const aInput = document.getElementById('a-desc-' + rid);

                fd.append(`cards[${indiceInvio}][id]`, row.dataset.id);
                fd.append(`cards[${indiceInvio}][status]`, status);
                fd.append(`cards[${indiceInvio}][orig_type]`, row.dataset.origType);
                fd.append(`cards[${indiceInvio}][is_child]`, isChild);
                fd.append(`cards[${indiceInvio}][parent_idx]`, currentParentIndex);

                // Se li trova prende il valore, altrimenti stringa vuota per non crashare
                fd.append(`cards[${indiceInvio}][q]`, qInput ? qInput.value : '');
                fd.append(`cards[${indiceInvio}][a]`, aInput ? aInput.value : '');

                if (row.qNewImgFile) fd.append(`row_q_img[${indiceInvio}]`, row.qNewImgFile);
                if (row.aNewImgFile) fd.append(`row_a_img[${indiceInvio}]`, row.aNewImgFile);
                if (row.qAudioBlob) fd.append(`audio_q[${indiceInvio}]`, row.qAudioBlob, `q_${indiceInvio}.${row.qAudioExt}`);
                if (row.aAudioBlob) fd.append(`audio_a[${indiceInvio}]`, row.aAudioBlob, `a_${indiceInvio}.${row.aAudioExt}`);
                
                indiceInvio++;
            });

            if (!haCambiatoOrdine && elementiModificati === 0) {
                showToast('Nessuna modifica da salvare.');
                return;
            }

            // Mostriamo lo spinner SOLO sul bottone in alto
            if (hdrBtn) {
                hdrBtn.disabled = true;
                hdrBtn.innerHTML = '<span class="spinner" style="width:13px; height:13px; border-width:2px; display:inline-block; vertical-align:middle; margin-right:5px;"></span> Salvataggio...';
            }

            const resetButtons = () => {
                if (hdrBtn) {
                    hdrBtn.disabled = false;
                    hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
                }
            };

            const res = await fetch('big_edit_save_fc.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                isDirty = false;
                
                // Fermiamo lo spinner e mostriamo che è finito
                if (hdrBtn) {
                    hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salvato!';
                }
                
                showToast('Salvataggio completato ✔');
                setTimeout(()=> window.location.reload(), 700);
            } else {
                showToast('Errore durante il salvataggio: ' + (data.error || ''), 'error');
                resetButtons();
            }

        } catch (err) {
            // Se c'è un qualsiasi errore JS, ora lo intercettiamo e lo mostriamo
            console.error(err);
            showToast('Errore di sistema: ' + err.message, 'error');
            
            const hdrBtn = document.getElementById('hdrSaveBtn');
            if (hdrBtn) {
                hdrBtn.disabled = false;
                hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
            }
        }
    }

    window.addEventListener('beforeunload', function(e){

        if (isDirty) { e.preventDefault(); e.returnValue = ''; }

    });

    hydrateInitialCards();

    updateRowCountChip();

    toggleEmptyHint();

</script>

<?php include 'examples_modal.php'; ?>

</body>

</html>