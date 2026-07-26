<style>

/* ================================================================

   MODALE ESEMPI - Stile iOS e Modifica Immagine

   ================================================================ */

.exm-overlay{

    display:none; position:fixed; inset:0; z-index:11000;

    justify-content:center; align-items:flex-end;

    background:rgba(0,0,0,.5); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

    box-sizing:border-box;

    font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",system-ui,sans-serif;

}

.exm-overlay.form{ z-index:11005; }

.exm-panel{

    position:relative; width:100%; max-width:640px; max-height:92vh; overflow-y:auto;

    -webkit-overflow-scrolling:touch;

    background:var(--surface, #fff); color:var(--text, #1C1C1E);

    border-radius:26px 26px 0 0; padding:28px 20px calc(24px + env(safe-area-inset-bottom,0px));

    box-shadow:0 -12px 50px rgba(0,0,0,.4);

    animation:exmUp .42s cubic-bezier(.32,.72,0,1) both; text-align:left;

}

@keyframes exmUp{ from{ transform:translateY(60%); opacity:.4; } }

.exm-panel::before{ content:""; position:absolute; top:9px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2, rgba(60,60,67,.25)); }

.exm-panel h3{ margin:2px 0 16px; font-size:20px; font-weight:800; letter-spacing:-.02em; color:var(--text, #1C1C1E); }



.exm-close{

    position:absolute; top:16px; right:16px; z-index:2; width:32px; height:32px;

    border:none; border-radius:50%; cursor:pointer; padding:0;

    background:var(--surface-2, #EFEFF4); color:var(--text-2, #8A8A8E); font-size:14px; font-weight:700;

    display:flex; align-items:center; justify-content:center;

    box-shadow:inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1));

}



/* Bottoni base */

.exm-btn{

    display:inline-flex; align-items:center; justify-content:center; gap:7px;

    width:100%; padding:15px 16px; border:none; border-radius:15px; cursor:pointer;

    font-family:inherit; font-size:16px; font-weight:800; color:#fff;

    box-shadow:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16), 0 10px 20px -8px rgba(0,0,0,.3);

    transition:transform .16s cubic-bezier(.32,.72,0,1), filter .2s;

}

.exm-btn:active{ transform:translateY(1.5px) scale(.98); filter:brightness(.95); }

.exm-btn.blue{ background:linear-gradient(180deg, color-mix(in srgb, var(--blue, #007AFF), #fff 16%), var(--blue, #007AFF) 52%, color-mix(in srgb, var(--blue, #007AFF), #000 10%)); }

.exm-btn.green{ background:linear-gradient(180deg, color-mix(in srgb, var(--green, #34C759), #fff 16%), var(--green, #34C759) 52%, color-mix(in srgb, var(--green, #34C759), #000 10%)); }

.exm-btn.purple{ background:linear-gradient(180deg, color-mix(in srgb, var(--purple, #AF52DE), #fff 16%), var(--purple, #AF52DE) 52%, color-mix(in srgb, var(--purple, #AF52DE), #000 10%)); }

.exm-btn.red{ background:var(--chip-bg, #EFEFF4); color:var(--red, #FF3B30); box-shadow:inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1)); }

.exm-btn.sm{ padding:11px 14px; font-size:14px; }



/* AI Box */

.exm-ai-box{ background:var(--blue-soft, rgba(0,122,255,.09)); padding:16px; border-radius:16px; border:1px dashed rgba(0,122,255,.4); margin-bottom:18px; }

.exm-ai-title{ font-weight:800; color:var(--blue, #007AFF); margin-bottom:8px; font-size:14.5px; }



/* Form Field & Labels */

.exm-label{ font-weight:800; color:var(--text-2, #8A8A8E); font-size:12.5px; text-transform:uppercase; letter-spacing:.04em; display:block; margin:18px 0 8px; }

.exm-panel textarea{

    width:100%; padding:12px 14px; border:1px solid var(--hairline-2, rgba(60,60,67,.25)); border-radius:12px;

    font-family:inherit; font-size:16px; color:var(--text, #1C1C1E); background:var(--surface-2, #F7F7FA); box-sizing:border-box; resize:vertical;

}

.exm-panel textarea:focus{ outline:none; border-color:var(--blue, #007AFF); }



/* Audio (Stile Modifica Immagine) */

.exm-audio-card{

    background:var(--surface-2, #F7F7FA); border-radius:16px; padding:12px 14px;

    border:.5px solid var(--hairline-2, rgba(60,60,67,.25)); margin-bottom:16px;

    display:flex; flex-direction:column; gap:10px;

}

.exm-audio-row{ display:flex; align-items:center; gap:12px; }

.exm-rec-btn{

    width:48px; height:48px; border-radius:50%; flex-shrink:0; border:none; cursor:pointer; padding:0;

    display:flex; align-items:center; justify-content:center; position:relative;

    background:var(--surface, #fff); color:var(--text-2, #8A8A8E);

    box-shadow:inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1)), 0 2px 6px rgba(0,0,0,.08);

    transition:transform .15s cubic-bezier(.32,.72,0,1), background .2s, color .2s;

}

.exm-rec-btn:active{ transform:scale(.9); }

.exm-rec-btn.recording{

    background:var(--red-soft, rgba(255,59,48,.15)); color:var(--red, #FF3B30);

    box-shadow:inset 0 0 0 1.5px var(--red, #FF3B30);

    animation:recPulse 1.6s ease-in-out infinite;

}

@keyframes recPulse{

    0%,100%{ box-shadow:inset 0 0 0 1.5px var(--red, #FF3B30), 0 0 0 0 rgba(255,59,48,.3); }

    50%{ box-shadow:inset 0 0 0 1.5px var(--red, #FF3B30), 0 0 0 8px rgba(255,59,48,0); }

}



/* Immagine (Stile Modifica Immagine) */

.exm-img-box{

    background:var(--blue-soft, rgba(0,122,255,.09)); border-radius:14px; padding:14px; text-align:center;

    box-shadow:inset 0 0 0 1.5px rgba(0,122,255,.4); margin-bottom:20px;

}

.exm-file-btn{

    display:inline-flex; align-items:center; gap:7px; cursor:pointer; margin:0 auto;

    background:var(--surface, #fff); color:var(--text, #1C1C1E); font-weight:700; font-size:13.5px;

    padding:9px 14px; border-radius:11px; box-shadow:inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1)), 0 2px 5px rgba(0,0,0,.05);

    transition:transform .15s cubic-bezier(.32,.72,0,1);

}

.exm-file-btn:active{ transform:scale(.96); }



/* Liste Card ed Elementi */

.exm-panel audio{ width:100%; border-radius:12px; }

.exm-panel .raw-text{ font-size:15px; color:var(--text, #1C1C1E); line-height:1.5; }

.exm-actions{ display:flex; gap:10px; margin-top:24px; }



@media (min-width:640px){

    .exm-overlay{ align-items:center; padding:20px; }

    .exm-panel{ border-radius:26px; }

}

</style>



<!-- MODALE: LISTA ESEMPI -->

<div id="ex-list-modal" class="exm-overlay">

    <div class="exm-panel">

        <button class="exm-close" onclick="document.getElementById('ex-list-modal').style.display='none'" aria-label="Chiudi">&#10005;</button>

        <h3>📚 Esempi associati</h3>

        <p style="color: var(--text-2, #8A8A8E); font-size: 14px; margin-top:-10px; margin-bottom:16px;">Gestisci gli esempi pratici per questa flashcard.</p>

        <div id="ex-list-content" style="flex:1; max-height:50vh; overflow-y:auto; margin-bottom:16px; padding-bottom:10px;"></div>

        <button class="exm-btn green" onclick="openExampleForm(currentFcId, 0)">➕ Aggiungi nuovo esempio</button>

    </div>

</div>



<!-- MODALE: FORM EDITOR ESEMPIO -->

<div id="ex-form-modal" class="exm-overlay form">

    <div class="exm-panel">

        <button class="exm-close" onclick="document.getElementById('ex-form-modal').style.display='none'" aria-label="Chiudi">&#10005;</button>

        <h3 id="ex-form-title">✍️ Editor esempio</h3>



        <div class="exm-ai-box">

            <div class="exm-ai-title">🤖 Genera con IA</div>

            <textarea id="ex-ai-prompt" rows="2" onclick="this.select()">Crea un esercizio pratico da esame su questo argomento.</textarea>

            <button id="ex-ai-btn" class="exm-btn purple sm" style="margin-top:10px;" onclick="generateAiExample()">✨ Genera esempio IA</button>

        </div>



        <label class="exm-label">Testo dell'esempio</label>

        <div style="margin-bottom: 16px; border: 1px solid var(--hairline-2, rgba(60,60,67,.25)); border-radius: 12px; overflow: hidden; background: var(--surface, #fff);">

            <?php

                $ft_field = 'ex_text'; // ID del nuovo campo gestito dalla toolbar

                $ft_raw = ''; 

                $ft_placeholder = 'Scrivi qui l\'esempio...';

                include 'format_toolbar.php';

            ?>

        </div>



        <label class="exm-label">Audio</label>

        <div class="exm-audio-card">

            <div class="exm-audio-row">

                <button type="button" class="exm-rec-btn" id="ex-rec-btn" onclick="toggleExampleRecord()">

                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>

                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>

                </button>

                <div style="flex:1; min-width:0;">

                    <div style="font-size:13.5px; font-weight:800; color:var(--text, #1C1C1E);">Vocale Esempio</div>

                    <div id="ex-rec-status" style="font-size:12px; font-weight:600; color:var(--text-2, #8A8A8E); margin-top:1px; font-variant-numeric:tabular-nums;">Tocca per registrare</div>

                </div>

            </div>

            <audio id="ex-audio-player" controls style="display:none; height:34px;"></audio>

        </div>



        <label class="exm-label">Immagine</label>

        <div class="exm-img-box">

            <div id="ex-img-preview-container" style="display:none; margin-bottom:12px;">

                <img id="ex-img-preview" src="" style="max-width:100%; max-height:140px; border-radius:9px; display:block; margin:0 auto; box-shadow:0 2px 8px rgba(0,0,0,.1);">

            </div>

            <input type="file" id="ex-image-file" accept="image/*" style="display:none;" onchange="previewExImage(this)">

            <label for="ex-image-file" class="exm-file-btn">

                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>

                Scegli foto dal dispositivo

            </label>

        </div>



        <div class="exm-actions">

            <button id="ex-delete-btn" class="exm-btn red" style="flex:1; display:none;" onclick="deleteExample()">Elimina</button>

            <button id="ex-save-btn" class="exm-btn blue" style="flex:2;" onclick="saveExample()">💾 Salva esempio</button>

        </div>

    </div>

</div>



<script>

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
        alert("Errore di rete: impossibile caricare gli esempi.");
        console.error(e);
        return;
    }

    if (!data || !data.success) {
        alert("Errore nel caricamento degli esempi" + (data && data.error ? ": " + data.error : "."));
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

                <button style="position:absolute; top:12px; right:12px; background:var(--surface, #fff); color:var(--text, #333); border:none; padding:6px 12px; border-radius:99px; font-weight:700; font-size:12px; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.1), inset 0 0 0 .5px var(--hairline, rgba(0,0,0,.1));" onclick="openExampleForm(${fc_id}, ${ex.id})">✏️ Modifica</button>

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

    statusEl.textContent = 'Tocca per registrare';

    

    if(ex_id > 0) {

        title.innerText = "✏️ Modifica Esempio";

        deleteBtn.style.display = 'block'; 

        let ex = currentExamplesList.find(e => e.id == ex_id);

        if (ex) {

            setExEditorText(ex.example_text || '');

            

            if(ex.example_audio) {

                player.src = `fc_audio/${ex.example_audio}`;

                player.style.display = 'block';

                statusEl.textContent = 'Audio salvato presente';

            }

            if(ex.example_image) {
                imgPreview.src = `fc_images/${ex.example_image}`;
                imgPreviewCont.style.display = 'block';
            }

        }

    } else {

        title.innerText = "✍️ Nuovo Esempio";

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



        } catch (err) { alert("Impossibile accedere al microfono."); }

    } else {

        exRecorder.stop();

        clearInterval(exRecTimer);

        btn.classList.remove('recording');

        btn.querySelector('.ic-mic').style.display = '';

        btn.querySelector('.ic-stop').style.display = 'none';

        statusEl.textContent = 'Audio registrato pronto';

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

    } catch(e) { alert("Errore di rete."); }

    

    clearInterval(exAiTimerInterval);
    btn.disabled = false; btn.innerText = "✨ Genera Esempio IA";

}



async function saveExample() {

    if (typeof fcSyncAllEditors === 'function') {

        fcSyncAllEditors();

    }

    

    let btn = document.getElementById('ex-save-btn');

    let originalText = btn.innerHTML;

    btn.disabled = true; btn.innerText = "⏳ Salvataggio...";

    

    let fd = new FormData();

    fd.append('action', 'save');

    fd.append('fc_id', currentFcId);

    fd.append('ex_id', currentExId);

    

    fd.append('text', getExHiddenInput() ? getExHiddenInput().value : '');

    

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

                    b.innerText = `➕ Aggiungi Esempio`;

                    b.style.background = 'var(--surface-3, #f1f3f5)'; b.style.color = 'var(--text-3, #555)'; b.style.borderColor = 'var(--hairline-2, #ccc)';

                }

            };

            if(btnUI) updateBtn(btnUI);

            if(btnUIGraded) updateBtn(btnUIGraded);

        }

    } catch(e) { alert("Errore salvataggio."); }

    

    btn.disabled = false; btn.innerHTML = originalText;

}



async function deleteExample() {

    if (!confirm("Sei sicuro di voler eliminare questo esempio?")) return;

    

    let btn = document.getElementById('ex-delete-btn');

    let originalText = btn.innerHTML;

    btn.disabled = true; btn.innerText = "⏳ Eliminazione...";



    try {

        let res = await fetch(`examples_api.php?action=delete&ex_id=${currentExId}`);

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

                    b.innerText = `➕ Aggiungi Esempio`;

                    b.style.background = 'var(--surface-3, #f1f3f5)'; b.style.color = 'var(--text-3, #555)'; b.style.borderColor = 'var(--hairline-2, #ccc)';

                }

            };

            if(btnUI) updateBtn(btnUI);

            if(btnUIGraded) updateBtn(btnUIGraded);

        }

    } catch(e) { alert("Errore durante l'eliminazione."); }

    

    btn.disabled = false; btn.innerHTML = originalText;

}

</script>