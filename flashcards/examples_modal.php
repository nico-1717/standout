<?php
/* Il componente puo' comparire piu' volte nella stessa pagina: il foglio si
   carica una volta sola. Il <link> sta qui e non nel <head> perche' questo file
   e' incluso dentro il <body>; funziona, e per un componente e' il compromesso
   giusto rispetto a chiedere a ogni pagina di ricordarselo. */
if (!defined('EXM_CSS')) {
    define('EXM_CSS', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'examples_modal.css?v=' . (@filemtime(dirname(__FILE__) . '/examples_modal.css') ?: 1) . '">' . "\n";
}
?>
">



<!-- MODALE: LISTA ESEMPI -->

<div id="ex-list-modal" class="exm-overlay">

    <div class="exm-panel">

        <button class="exm-close" onclick="document.getElementById('ex-list-modal').style.display='none'" aria-label=T('comune.chiudi')>&#10005;</button>

        <h3><?php te('fc.exm_titolo'); ?></h3>

        <p style="color: var(--text-2, #8A8A8E); font-size: 14px; margin-top:-10px; margin-bottom:16px;"><?php te('fc.exm_sottotitolo'); ?></p>

        <div id="ex-list-content" style="flex:1; max-height:50vh; overflow-y:auto; margin-bottom:16px; padding-bottom:10px;"></div>

        <button class="exm-btn green" onclick="openExampleForm(currentFcId, 0)"><?php te('fc.exm_aggiungi_nuovo'); ?></button>

    </div>

</div>



<!-- MODALE: FORM EDITOR ESEMPIO -->

<div id="ex-form-modal" class="exm-overlay form">

    <div class="exm-panel">

        <button class="exm-close" onclick="document.getElementById('ex-form-modal').style.display='none'" aria-label=T('comune.chiudi')>&#10005;</button>

        <h3 id="ex-form-title"><?php te('fc.exm_editor'); ?></h3>



        <div class="exm-ai-box">

            <div class="exm-ai-title"><?php te('fc.exm_genera_ia'); ?></div>

            <textarea id="ex-ai-prompt" rows="2" onclick="this.select()"><?php te('fc.exm_genera_sub'); ?></textarea>

            <button id="ex-ai-btn" class="exm-btn purple sm" style="margin-top:10px;" onclick="generateAiExample()"><?php te('fc.exm_genera_btn'); ?></button>

        </div>



        <label class="exm-label"><?php te('fc.exm_testo'); ?></label>

        <div style="margin-bottom: 16px; border: 1px solid var(--hairline-2, rgba(60,60,67,.25)); border-radius: 12px; overflow: hidden; background: var(--surface, #fff);">

            <?php

                $ft_field = 'ex_text'; // ID del nuovo campo gestito dalla toolbar

                $ft_raw = ''; 

                $ft_placeholder = T('fc.exm_ph_testo');

                include 'format_toolbar.php';

            ?>

        </div>



        <label class="exm-label"><?php te('fc.exm_audio'); ?></label>

        <div class="exm-audio-card">

            <div class="exm-audio-row">

                <button type="button" class="exm-rec-btn" id="ex-rec-btn" onclick="toggleExampleRecord()">

                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>

                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>

                </button>

                <div style="flex:1; min-width:0;">

                    <div style="font-size:13.5px; font-weight:800; color:var(--text, #1C1C1E);"><?php te('fc.exm_vocale'); ?></div>

                    <div id="ex-rec-status" style="font-size:12px; font-weight:600; color:var(--text-2, #8A8A8E); margin-top:1px; font-variant-numeric:tabular-nums;">Tocca per registrare</div>

                </div>

            </div>

            <audio id="ex-audio-player" controls style="display:none; height:34px;"></audio>

        </div>



        <label class="exm-label"><?php te('vocab.immagine'); ?></label>

        <div class="exm-img-box">

            <div id="ex-img-preview-container" style="display:none; margin-bottom:12px;">

                <img id="ex-img-preview" src="" style="max-width:100%; max-height:140px; border-radius:9px; display:block; margin:0 auto; box-shadow:0 2px 8px rgba(0,0,0,.1);">

            </div>

            <input type="file" id="ex-image-file" accept="image/*" style="display:none;" onchange="previewExImage(this)">

            <label for="ex-image-file" class="exm-file-btn">

                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>

                <?php te('fc.exm_scegli_foto'); ?>

            </label>

        </div>



        <div class="exm-actions">

            <button id="ex-delete-btn" class="exm-btn red" style="flex:1; display:none;" onclick="deleteExample()"><?php te('comune.elimina'); ?></button>

            <button id="ex-save-btn" class="exm-btn blue" style="flex:2;" onclick="saveExample()"><?php te('fc.exm_salva'); ?></button>

        </div>

    </div>

</div>



<script>

/* Il token di sessione lo emette questo file, non la pagina che lo include:
   il componente compare in piu' pagine e non tutte espongono una variabile
   con lo stesso nome. Prima si cercava una globale "csrf_token" che non
   esiste da nessuna parte, quindi il token non partiva mai e il server
   rispondeva 403. */
const EXM_CSRF = <?php echo json_encode(sec_csrf_token()); ?>;

let currentFcId = 0, currentExId = 0;

let currentQ = '', currentA = '';

let currentExamplesList = []; // Memoria degli esempi

let exRecorder = null, exAudioChunks = [], exAudioBlob = null, exAudioExt = '';

let exRecTimer = null, exRecSec = 0;

// Il campo "Testo dell'esempio" è generato da format_toolbar.php (che crea l'input
// nascosto e il div editabile dentro un wrapper .fmt-editor-wrap con data-field="ex_text").
// Cerchiamo quel wrapper invece di indovinare gli id esatti dei suoi figli: è più
// robusto e usa le stesse funzioni (fcHydrateWrap/fcSyncHiddenFor) che usa la toolbar stessa.
function getExFormatWrap() {
    let modal = document.getElementById('ex-form-modal');
    return modal ? modal.querySelector('.fmt-editor-wrap[data-field="ex_text"]') : null;
}
function getExHiddenInput() {
    let wrap = getExFormatWrap();
    if (wrap) { let el = wrap.querySelector('input[type="hidden"]'); if (el) return el; }
    return document.getElementById('ex_text'); // fallback per compatibilità
}
function setExEditorText(rawText) {
    let wrap = getExFormatWrap();
    if (wrap && typeof fcHydrateWrap === 'function') {
        fcHydrateWrap(wrap, rawText || '');
        return;
    }
    // Fallback se il wrapper non si trova per qualche motivo: proviamo comunque
    // a valorizzare l'hidden e il div editabile con gli id più plausibili.
    let hidden = getExHiddenInput();
    if (hidden) hidden.value = rawText || '';
    let editorDiv = document.getElementById('ex_text_editor') || document.getElementById('editor_ex_text');
    if (editorDiv) {
        editorDiv.innerHTML = typeof parseTextForPreview === 'function' ? parseTextForPreview(rawText || '') : (rawText || '');
    }
}



// Utility formattazione tempo per il registratore

function fmtExTime(s) {

    let m = Math.floor(s / 60), ss = s % 60;

    return m + ':' + String(ss).padStart(2, '0');

}



function previewExImage(input) {

    let previewCont = document.getElementById('ex-img-preview-container');

    let previewImg = document.getElementById('ex-img-preview');

    if (input.files && input.files[0]) {

        let reader = new FileReader();

        reader.onload = function(e) {

            previewImg.src = e.target.result;

            previewCont.style.display = 'block';

        }

        reader.readAsDataURL(input.files[0]);

    } else {

        previewCont.style.display = 'none';

        previewImg.src = '';

    }

}



async function openExamplesModal(fc_id, q_text, a_text) {

    currentFcId = fc_id; currentQ = q_text; currentA = a_text;

    

    let data;
    try {
        let res = await fetch(`examples_api.php?action=get&fc_id=${fc_id}`);
        data = await res.json();
    } catch (e) {
        alert(T('fc.exm_errore_carica'));
        console.error(e);
        return;
    }

    if (!data || !data.success) {
        alert(T('fc.exm_errore_carica2') + (data && data.error ? ": " + data.error : "."));
        return;
    }

    if (data.examples.length === 0) {

        currentExamplesList = [];

        openExampleForm(fc_id, 0); 

    } else {

        currentExamplesList = data.examples;

        let html = '';

        data.examples.forEach((ex, i) => {

            html += `<div style="background:var(--surface-2, #F7F7FA); border-radius:18px; border:.5px solid var(--hairline-2, #ccc); padding:16px; margin-bottom:14px; position:relative;">

                <div style="font-size:11px; font-weight:800; color:var(--text-3, #888); letter-spacing:.08em; text-transform:uppercase; margin-bottom:10px;">Esempio ${i+1}</div>

                ${ex.example_text ? `<div class="raw-text" style="margin-bottom:10px; font-size:15px; color:var(--text, #111);">${typeof parseTextForPreview === 'function' ? parseTextForPreview(ex.example_text) : ex.example_text}</div>` : ''}

                ${ex.example_audio ? `<audio src="fc_audio/${ex.example_audio}" controls style="width:100%; height:34px; margin-bottom:10px; border-radius:12px;"></audio>` : ''}
                ${ex.example_image ? `<img src="fc_images/${ex.example_image}" style="max-width:100%; max-height:200px; border-radius:9px; display:block; margin-top:8px;">` : ''}

                <button style="position:absolute; top:12px; right:12px; background:var(--surface, #fff); color:var(--text, #333); border:none; padding:6px 12px; border-radius:99px; font-weight:700; font-size:12px; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.1), inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1));" onclick="openExampleForm(${fc_id}, ${ex.id})">${T('fc.exm_modifica')}</button>

            </div>`;

        });

        document.getElementById('ex-list-content').innerHTML = html;

        if (typeof MathJax !== 'undefined') MathJax.typesetPromise([document.getElementById('ex-list-content')]);

        document.getElementById('ex-list-modal').style.display = 'flex';

    }

}



function openExampleForm(fc_id, ex_id) {

    currentFcId = fc_id; currentExId = ex_id;

    let title = document.getElementById('ex-form-title');

    let deleteBtn = document.getElementById('ex-delete-btn');

    let player = document.getElementById('ex-audio-player');

    let imgPreviewCont = document.getElementById('ex-img-preview-container');

    let imgPreview = document.getElementById('ex-img-preview');

    let recBtn = document.getElementById('ex-rec-btn');

    let statusEl = document.getElementById('ex-rec-status');

    

    // Reset base

    setExEditorText('');

    

    player.style.display = 'none';

    player.src = '';

    document.getElementById('ex-image-file').value = '';

    imgPreviewCont.style.display = 'none';

    imgPreview.src = '';

    exAudioBlob = null;



    recBtn.classList.remove('recording');

    recBtn.querySelector('.ic-mic').style.display = '';

    recBtn.querySelector('.ic-stop').style.display = 'none';

    clearInterval(exRecTimer);

    statusEl.textContent = T('fc.ifc_tocca_registrare');

    

    if(ex_id > 0) {

        title.innerText = T('fc.exm_modifica_titolo');

        deleteBtn.style.display = 'block'; 

        let ex = currentExamplesList.find(e => e.id == ex_id);

        if (ex) {

            setExEditorText(ex.example_text || '');

            

            if(ex.example_audio) {

                player.src = `fc_audio/${ex.example_audio}`;

                player.style.display = 'block';

                statusEl.textContent = T('fc.exm_audio_salvato');

            }

            if(ex.example_image) {
                imgPreview.src = `fc_images/${ex.example_image}`;
                imgPreviewCont.style.display = 'block';
            }

        }

    } else {

        title.innerText = T('fc.exm_nuovo_titolo');

        deleteBtn.style.display = 'none';

    }



    document.getElementById('ex-list-modal').style.display = 'none';

    document.getElementById('ex-form-modal').style.display = 'flex';

}



async function toggleExampleRecord() {

    let btn = document.getElementById('ex-rec-btn');

    let statusEl = document.getElementById('ex-rec-status');

    let isRecording = btn.classList.contains('recording');



    if (!isRecording) {

        try {

            let stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            exRecorder = new MediaRecorder(stream); 

            exAudioChunks = [];

            

            exRecorder.ondataavailable = e => { if (e.data.size > 0) exAudioChunks.push(e.data); };

            exRecorder.onstop = () => {

                let mimeType = exRecorder.mimeType || 'audio/webm';

                exAudioBlob = new Blob(exAudioChunks, { type: mimeType });

                exAudioExt = mimeType.includes('mp4') ? 'm4a' : 'webm';

                let player = document.getElementById('ex-audio-player');

                player.src = URL.createObjectURL(exAudioBlob);

                player.style.display = 'block';

                stream.getTracks().forEach(t => t.stop());

            };

            

            exRecorder.start(); 

            btn.classList.add('recording');

            btn.querySelector('.ic-mic').style.display = 'none';

            btn.querySelector('.ic-stop').style.display = '';

            

            exRecSec = 0;

            statusEl.textContent = '0:00';

            exRecTimer = setInterval(function () { 

                exRecSec++; statusEl.textContent = fmtExTime(exRecSec); 

            }, 1000);



        } catch (err) { alert(T('fc.ifc_no_microfono')); }

    } else {

        exRecorder.stop();

        clearInterval(exRecTimer);

        btn.classList.remove('recording');

        btn.querySelector('.ic-mic').style.display = '';

        btn.querySelector('.ic-stop').style.display = 'none';

        statusEl.textContent = T('fc.exm_audio_pronto');

    }

}



let exAiTimerInterval = null;

async function generateAiExample() {

    let btn = document.getElementById('ex-ai-btn');

    let extra = document.getElementById('ex-ai-prompt').value;

    let exStats = (window.aiPerfStats && window.aiPerfStats.example) || { avg_seconds: null, slower_than_usual: false };
    if (exStats.slower_than_usual && typeof showApiSlowToast === 'function') showApiSlowToast();

    let exElapsed = 0;
    let exStimaLabel = exStats.avg_seconds ? `di solito ≈ ${exStats.avg_seconds}s` : 'generazione in corso';

    btn.disabled = true; btn.innerText = `⏳ 0s · ${exStimaLabel}`;

    clearInterval(exAiTimerInterval);
    exAiTimerInterval = setInterval(() => {
        exElapsed++;
        btn.innerText = `⏳ ${exElapsed}s · ${exStimaLabel}`;
    }, 1000);

    

    try {

        let res = await fetch('ai_example.php', {

            method: 'POST', headers: {'Content-Type': 'application/json'},

            body: JSON.stringify({ q: currentQ, a: currentA, extra: extra })

        });

        let data = await res.json();

        if(data.success) {

            let hiddenInput = getExHiddenInput();

            let newText = data.example;

            if(hiddenInput && hiddenInput.value.trim() !== '') {

                newText = hiddenInput.value + "\n\n" + data.example;

            }

            setExEditorText(newText);

        } else if (data.error === 'limite_piano' && typeof showTokenLimitModal === 'function') {

            showTokenLimitModal(data.message);

        } else { alert("Errore IA: " + data.error + (data.raw ? "\nRaw: " + data.raw : "") + (data.debug ? "\n(" + data.debug + ")" : "")); }

    } catch(e) { alert(T('vocab.errore_rete_p')); }

    

    clearInterval(exAiTimerInterval);
    btn.disabled = false; btn.innerText = T('fc.exm_genera_btn2');

}



async function saveExample() {

    if (typeof fcSyncAllEditors === 'function') {

        fcSyncAllEditors();

    }

    

    let btn = document.getElementById('ex-save-btn');

    let originalText = btn.innerHTML;

    btn.disabled = true; btn.innerText = T('fc.exm_salvataggio');

    

    let fd = new FormData();
    fd.append('action', 'save');
    fd.append('fc_id', currentFcId);
    fd.append('ex_id', currentExId);
    
    fd.append('text', getExHiddenInput() ? getExHiddenInput().value : '');
    
    /* Il token va sempre, non "se per caso esiste una variabile": senza,
       sec_guard_write chiude la porta con un 403 e il salvataggio non parte. */
    fd.append('csrf_token', EXM_CSRF);
    
    if (exAudioBlob) fd.append('audio', exAudioBlob, 'ex_audio.' + exAudioExt);
    
    let fileInput = document.getElementById('ex-image-file');
    if (fileInput.files.length > 0) fd.append('image', fileInput.files[0]);



    try {
        let res = await fetch('examples_api.php', { method: 'POST', body: fd });
        let data = await res.json();
        if(data.success) {
            document.getElementById('ex-form-modal').style.display = 'none';
            openExamplesModal(currentFcId, currentQ, currentA);
            
            let btnUI = document.getElementById('btn-ex-' + currentFcId);
            let btnUIGraded = document.getElementById('btn-ex-graded-' + currentFcId);
            let count = currentExamplesList.length + (currentExId === 0 ? 1 : 0);
            
            let updateBtn = (b) => {
                if (count > 0) {
                    b.innerText = `💡 Esempi (${count})`;
                    b.style.background = 'var(--purple, #8e24aa)'; b.style.color = 'white'; b.style.borderColor = 'var(--purple, #8e24aa)';
                } else {
                    b.innerText = T('fc.exm_aggiungi');
                    b.style.background = 'var(--surface-3, #f1f3f5)'; b.style.color = 'var(--text-3, #555)'; b.style.borderColor = 'var(--hairline-2, #ccc)';
                }
            };
            if(btnUI) updateBtn(btnUI);
            if(btnUIGraded) updateBtn(btnUIGraded);
        } else {
            // Nuova gestione dell'errore
            alert(T('fc.exm_errore_salva') + (data.error ? ": " + data.error : ""));
        }
    } catch(e) { alert(T('fc.exm_errore_salva')); }

    

    btn.disabled = false; btn.innerHTML = originalText;

}



async function deleteExample() {

    if (!confirm(T('fc.exm_conferma_elimina'))) return;

    

    let btn = document.getElementById('ex-delete-btn');

    let originalText = btn.innerHTML;

    btn.disabled = true; btn.innerText = T('fc.exm_eliminazione');



    try {

        /* Anche l'eliminazione passa da sec_guard_write, quindi vuole il token:
           in GET non c'era modo di mandarlo e finiva anche lei in 403. */
        let fdDel = new FormData();
        fdDel.append('action', 'delete');
        fdDel.append('ex_id', currentExId);
        fdDel.append('csrf_token', EXM_CSRF);

        let res = await fetch('examples_api.php', { method: 'POST', body: fdDel });

        let data = await res.json();

        if(data.success) {

            document.getElementById('ex-form-modal').style.display = 'none';

            openExamplesModal(currentFcId, currentQ, currentA);

            

            let btnUI = document.getElementById('btn-ex-' + currentFcId);

            let btnUIGraded = document.getElementById('btn-ex-graded-' + currentFcId);

            let count = Math.max(0, currentExamplesList.length - 1);

            

            let updateBtn = (b) => {

                if (count > 0) {

                    b.innerText = `💡 Esempi (${count})`;

                    b.style.background = 'var(--purple, #8e24aa)'; b.style.color = 'white'; b.style.borderColor = 'var(--purple, #8e24aa)';

                } else {

                    b.innerText = T('fc.exm_aggiungi');

                    b.style.background = 'var(--surface-3, #f1f3f5)'; b.style.color = 'var(--text-3, #555)'; b.style.borderColor = 'var(--hairline-2, #ccc)';

                }

            };

            if(btnUI) updateBtn(btnUI);

            if(btnUIGraded) updateBtn(btnUIGraded);

        } else {

            /* Senza questo ramo un rifiuto del server non si vedeva: la finestra
               restava aperta senza dire niente e sembrava che il tasto fosse morto. */

            alert(T('fc.exm_errore_elimina') + (data.error ? ": " + data.error : ""));

        }

    } catch(e) { alert(T('fc.exm_errore_elimina')); }

    

    btn.disabled = false; btn.innerHTML = originalText;

}

</script>