<div id="floating-player" style="display:none;">

    <div id="fp-drag-handle">

        <span class="fp-grip"></span>

    </div>



    <div class="fp-controls">

        <div id="fp-skips" class="fp-skip-wrap">

            <button class="fp-skip" onclick="fpSkip(-10)" aria-label="Indietro 10s">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg>

            </button>

        </div>



        <button id="fp-playpause" onclick="fpTogglePlay()" aria-label="Play/Pausa">
            <svg class="fp-icon fp-icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6.3" y="4.8" width="4.1" height="14.4" rx="1.3"/><rect x="13.6" y="4.8" width="4.1" height="14.4" rx="1.3"/></svg>
            <svg class="fp-icon fp-icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="display:none;"><path d="M8.3 5.06v13.88a1 1 0 0 0 1.53.85l11.14-6.94a1 1 0 0 0 0-1.7L9.83 4.21a1 1 0 0 0-1.53.85z"/></svg>
        </button>



        <div id="fp-skips-right" class="fp-skip-wrap">

            <button class="fp-skip" onclick="fpSkip(10)" aria-label="Avanti 10s">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg>

            </button>

        </div>



        <select id="fp-speed" onchange="fpChangeSpeed(this.value)" aria-label="Velocità">

            <option value="0.8">0.8&times;</option>

            <option value="1" selected>1.0&times;</option>

            <option value="1.2">1.2&times;</option>

            <option value="1.5">1.5&times;</option>

            <option value="2">2.0&times;</option>

        </select>

    </div>



    <div id="fp-progress-container">

        <span id="fp-time">0:00</span>

        <input type="range" id="fp-progress" value="0" min="0" max="100" oninput="fpSeek(this.value)">

        <span id="fp-duration">0:00</span>

    </div>

</div>



<style>

    #floating-player{

        position:fixed; bottom:calc(env(safe-area-inset-bottom,0px) + 18px); right:18px;

        z-index:10020; width:300px; max-width:calc(100vw - 28px);

        padding:12px 14px calc(env(safe-area-inset-bottom,0px) + 12px);

        border-radius:22px;

        background:rgba(255,255,255,.86);

        -webkit-backdrop-filter:saturate(1.8) blur(24px); backdrop-filter:saturate(1.8) blur(24px);

        box-shadow:0 1px 1px rgba(0,0,0,.05), 0 18px 50px -12px rgba(0,0,0,.32), inset 0 0 0 .5px rgba(60,60,67,.14);

        font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",system-ui,sans-serif;

        color:#1C1C1E;

        animation:fpIn .38s cubic-bezier(.24,1.3,.35,1);

    }

    @keyframes fpIn{ from{ opacity:0; transform:translateY(16px) scale(.96);} }



    #fp-drag-handle{

        display:flex; justify-content:center; align-items:center;

        height:20px; margin:-4px 0 8px; cursor:grab; user-select:none; touch-action:none;

    }

    #fp-drag-handle .fp-grip{

        width:40px; height:5px; border-radius:99px; background:rgba(60,60,67,.28);

        transition:background .2s;

    }

    #fp-drag-handle:active .fp-grip{ background:rgba(60,60,67,.5); }



    .fp-controls{ display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:12px; }

    .fp-skip-wrap{ display:flex; }

    .fp-skip{

        width:38px; height:38px; border:none; border-radius:50%; cursor:pointer; padding:0;

        background:rgba(120,120,128,.12); color:#1C1C1E;

        display:flex; align-items:center; justify-content:center;

        transition:transform .15s cubic-bezier(.32,.72,0,1), background .2s;

    }

    .fp-skip:active{ transform:scale(.86); background:rgba(120,120,128,.2); }

    .fp-skip svg{ width:20px; height:20px; }



    #fp-playpause{

        width:52px; height:52px; border:none; border-radius:50%; cursor:pointer; padding:0;

        color:#fff;

        background:linear-gradient(180deg,#3D96FF,#007AFF 60%,#0069DE);

        box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -3px rgba(0,122,255,.55);

        display:flex; align-items:center; justify-content:center;

        transition:transform .15s cubic-bezier(.32,.72,0,1), filter .2s;

    }

    #fp-playpause:active{ transform:scale(.92); filter:brightness(.95); }

    #fp-playpause .fp-icon{ width:21px; height:21px; }

    #fp-playpause .fp-icon-play{ margin-left:2px; }



    #fp-speed{

        border:none; border-radius:12px; padding:8px 22px 8px 12px; cursor:pointer;

        font-family:inherit; font-size:13px; font-weight:700; color:#1C1C1E;

        background:rgba(120,120,128,.12);

        -webkit-appearance:none; appearance:none; text-align:center;

        background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.75' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");

        background-repeat:no-repeat; background-position:right 8px center;

        transition:background-color .2s;

    }

    #fp-speed:active{ background-color:rgba(120,120,128,.2); }



    #fp-progress-container{ display:flex; align-items:center; gap:9px; }

    #fp-time, #fp-duration{

        font-size:11px; color:#8A8A8E; font-variant-numeric:tabular-nums;

        font-family:ui-monospace,SFMono-Regular,Menlo,monospace; min-width:30px;

    }

    #fp-duration{ text-align:right; }

    #fp-progress{

        flex:1; height:6px; cursor:pointer;

        -webkit-appearance:none; appearance:none; background:transparent;

    }

    #fp-progress::-webkit-slider-runnable-track{ height:6px; border-radius:99px; background:rgba(120,120,128,.22); }

    #fp-progress::-moz-range-track{ height:6px; border-radius:99px; background:rgba(120,120,128,.22); }

    #fp-progress::-webkit-slider-thumb{

        -webkit-appearance:none; width:16px; height:16px; border-radius:50%; margin-top:-5px;

        background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.3), inset 0 0 0 .5px rgba(0,0,0,.06);

    }

    #fp-progress::-moz-range-thumb{

        width:16px; height:16px; border:none; border-radius:50%;

        background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.3);

    }



    /* ============================================================
       CONVIVENZA CON I FOGLI (popup impostazioni audio, IA, esempi...)
       ------------------------------------------------------------
       I fogli sono ancorati in BASSO e stanno a z-index più basso del player
       (10001 contro 10020). Su schermi stretti (iPhone) il player finiva quindi
       proprio sopra i controlli del foglio e ne intercettava i tap: il popup
       si vedeva ma non rispondeva. Su desktop il problema non si notava perché
       da 640px in su i fogli sono centrati e non si sovrappongono.
       Quando un foglio è aperto spostiamo il player in alto, così restano
       usabili tutti e due. transform:none serve a resettare un eventuale
       trascinamento dell'utente, altrimenti tornerebbe a coprire il foglio.
       ============================================================ */
    body.sheet-open #floating-player{
        top:calc(env(safe-area-inset-top,0px) + 10px);
        bottom:auto;
        right:14px;
        /* lascia libero l'angolo per l'orb della modalità microfono */
        max-width:calc(100vw - 96px);
        padding-bottom:12px;
        transform:none !important;
    }

    @media (prefers-color-scheme: dark){

        #floating-player{

            background:rgba(30,30,32,.82); color:#F5F5F7;

            box-shadow:0 1px 1px rgba(0,0,0,.4), 0 18px 50px -12px rgba(0,0,0,.7), inset 0 0 0 .5px rgba(120,120,128,.35);

        }
        #fp-drag-handle .fp-grip{ background:rgba(235,235,245,.3); }

        #fp-drag-handle:active .fp-grip{ background:rgba(235,235,245,.5); }

        .fp-skip{ background:rgba(120,120,128,.24); color:#F5F5F7; }

        .fp-skip:active{ background:rgba(120,120,128,.34); }

        #fp-playpause{ background:linear-gradient(180deg,#3D96FF,#0A84FF 60%,#0069DE); }

        #fp-speed{ background:rgba(120,120,128,.28); color:#F5F5F7; }

        #fp-time, #fp-duration{ color:#98989F; }

        #fp-progress::-webkit-slider-runnable-track{ background:rgba(120,120,128,.34); }

        #fp-progress::-moz-range-track{ background:rgba(120,120,128,.34); }

    }

</style>



<script>

// ==========================================

// LOGICA FLOATING PLAYER AUDIO

// ==========================================

let activeAudioElement = null;

let isNativeTTSActive = false; 

let fpContainer = document.getElementById('floating-player');

let fpPlayPauseBtn = document.getElementById('fp-playpause');

let fpProgressBar = document.getElementById('fp-progress');

let fpTime = document.getElementById('fp-time');

let fpDuration = document.getElementById('fp-duration');

let fpProgressContainer = document.getElementById('fp-progress-container');

let fpSkips = document.getElementById('fp-skips');

let fpSkipsRight = document.getElementById('fp-skips-right');



function formatTime(seconds) {

    if (isNaN(seconds)) return "0:00";

    let m = Math.floor(seconds / 60); let s = Math.floor(seconds % 60);

    return m + ":" + (s < 10 ? "0" : "") + s;

}



// Mostra l'icona "pausa" (= sta suonando, un tap mette in pausa) o "play" (= è in pausa, un tap fa ripartire)

function setFpIcon(state) {

    let pauseIc = fpPlayPauseBtn.querySelector('.fp-icon-pause');

    let playIc = fpPlayPauseBtn.querySelector('.fp-icon-play');

    if (pauseIc) pauseIc.style.display = (state === 'pause') ? '' : 'none';

    if (playIc) playIc.style.display = (state === 'play') ? '' : 'none';

}



function attachFloatingPlayer(audioObj) {

    hideFloatingPlayer();

    isNativeTTSActive = false;

    fpProgressContainer.style.display = 'flex';

    fpSkips.style.display = 'flex'; fpSkipsRight.style.display = 'flex';

    

    activeAudioElement = audioObj;

    let currentSpeedMenu = document.getElementById('fp-speed');

    if (typeof ttsSettings !== 'undefined' && ttsSettings.speed) {

        currentSpeedMenu.value = ttsSettings.speed;

        activeAudioElement.playbackRate = parseFloat(ttsSettings.speed);

    } else {

        activeAudioElement.playbackRate = parseFloat(currentSpeedMenu.value);

    }

    

    fpContainer.style.display = 'block';

    setFpIcon('pause');



    activeAudioElement.ontimeupdate = () => {

        if (!activeAudioElement.duration) return;

        fpProgressBar.value = (activeAudioElement.currentTime / activeAudioElement.duration) * 100;

        fpTime.innerText = formatTime(activeAudioElement.currentTime);

        fpDuration.innerText = formatTime(activeAudioElement.duration);

    };



    activeAudioElement.onended = () => { hideFloatingPlayer(); };

}



function attachNativeTTSPlayer() {

    hideFloatingPlayer();

    isNativeTTSActive = true;

    fpProgressContainer.style.display = 'none'; 

    fpSkips.style.display = 'flex'; fpSkipsRight.style.display = 'flex'; 

    

    let currentSpeedMenu = document.getElementById('fp-speed');

    if (typeof ttsSettings !== 'undefined' && ttsSettings.speed) {

        currentSpeedMenu.value = ttsSettings.speed;

    }



    fpContainer.style.display = 'block';

    setFpIcon('pause');

}



function fpTogglePlay() {

    if (isNativeTTSActive) {

        if (window.currentUtterance === null) {

            if (typeof resumeNativeTTS === 'function') resumeNativeTTS();

            setFpIcon('pause');

        } else {

            if (typeof pauseNativeTTS === 'function') pauseNativeTTS();

            setFpIcon('play');

        }

    } else if (activeAudioElement) {

        if (activeAudioElement.paused) {

            activeAudioElement.play(); setFpIcon('pause');

        } else {

            activeAudioElement.pause(); setFpIcon('play');

        }

    }

}



function fpSkip(seconds) {

    if (isNativeTTSActive) {

        // Passiamo la palla al file principale che fa il calcolo logico perfetto

        if (typeof skipNativeTTS === 'function') skipNativeTTS(seconds);

        return;

    }

    if (!activeAudioElement) return;

    activeAudioElement.currentTime += seconds;

}



function fpChangeSpeed(speed) {

    let s = parseFloat(speed);

    

    // Aggiorna MP3 se attivo

    if (activeAudioElement) activeAudioElement.playbackRate = s;

    

    let needsRestart = false;

    

    // FERMIAMO L'AUDIO *PRIMA* DI AGGIORNARE LA VELOCITA'

    // Così il sistema calcola dove sei arrivato usando la velocità vecchia (giusto!)

    if (isNativeTTSActive) {

        if (typeof pauseNativeTTS === 'function') {

            pauseNativeTTS();

            needsRestart = true;

        }

    }



    // ORA salviamo la nuova velocità

    if (typeof ttsSettings !== 'undefined') {

        ttsSettings.speed = s;

        localStorage.setItem('ttsSettings', JSON.stringify(ttsSettings));

        let modalSpeedMenu = document.getElementById('tts-speed');

        if (modalSpeedMenu) modalSpeedMenu.value = s;

    }



    // Facciamo ripartire la voce con la nuova velocità

    if (needsRestart) {

        setTimeout(() => {

            if (typeof resumeNativeTTS === 'function') resumeNativeTTS();

            setFpIcon('pause');

        }, 100);

    }

}



function hideFloatingPlayer() {

    fpContainer.style.display = 'none';

    if (activeAudioElement) { activeAudioElement.pause(); activeAudioElement.onupdate = null; activeAudioElement.onended = null; }

    activeAudioElement = null;

    isNativeTTSActive = false;

}



// ==========================================
// RILEVAMENTO FOGLI APERTI
// ==========================================
// Segnaliamo su <body> quando un foglio (popup ancorato in basso) è aperto, così
// il player — e chiunque altro abbia bisogno di saperlo, es. l'orb del microfono —
// può spostarsi e non coprirlo. Usiamo un observer invece di modificare ogni
// singolo punto che apre o chiude un popup: quei punti sono sparsi in più file
// (game_fc, examples_modal, voice_game_mode) ed è facile dimenticarne uno.
//
// ATTENZIONE: i fogli di game_fc stanno DENTRO il contenitore di gioco, che a
// ogni cambio card viene distrutto e ricreato da capo. Osservare direttamente
// quegli elementi non funziona: dopo la prima card l'observer resterebbe
// agganciato a elementi ormai rimossi e non scatterebbe più (si notava soprattutto
// in modalità microfono, dove si cambia card di continuo a voce). Osserviamo
// quindi il body con subtree:true, che copre anche i contenitori creati dopo.
(function () {
    var IDS = ['tts-modal', 'ai-modal', 'shatter-modal', 'token-limit-modal', 'ex-list-modal', 'ex-form-modal'];
    var pending = false;

    function isVisible(el) {
        if (!el) return false;
        // I fogli vengono aperti impostando display:flex/block via JS
        return el.style.display !== 'none' && el.style.display !== '';
    }

    function sync() {
        pending = false;
        var aperto = IDS.some(function (id) { return isVisible(document.getElementById(id)); });
        document.body.classList.toggle('sheet-open', aperto);
    }

    // Le mutazioni di stile sono frequenti (il trascinamento del player ne genera
    // una per ogni movimento): accorpiamo tutto in un solo controllo per frame.
    function syncSoon() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(sync);
    }

    function osserva() {
        new MutationObserver(syncSoon).observe(document.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['style']
        });
        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', osserva);
    } else {
        osserva();
    }
})();



// --- LOGICA DI TRASCINAMENTO ---

let isDragging = false, currentX, currentY, initialX, initialY, xOffset = 0, yOffset = 0;

let dragHandle = document.getElementById('fp-drag-handle');

dragHandle.addEventListener("mousedown", dragStart); dragHandle.addEventListener("touchstart", dragStart, {passive: false});

document.addEventListener("mouseup", dragEnd); document.addEventListener("touchend", dragEnd);

document.addEventListener("mousemove", drag); document.addEventListener("touchmove", drag, {passive: false});

function dragStart(e) {

    if (e.type === "touchstart") { initialX = e.touches[0].clientX - xOffset; initialY = e.touches[0].clientY - yOffset; } 

    else { initialX = e.clientX - xOffset; initialY = e.clientY - yOffset; }

    isDragging = true; dragHandle.style.cursor = 'grabbing';

}

function dragEnd() { initialX = currentX; initialY = currentY; isDragging = false; dragHandle.style.cursor = 'grab'; }

function drag(e) {

    if (!isDragging) return; e.preventDefault();

    if (e.type === "touchmove") { currentX = e.touches[0].clientX - initialX; currentY = e.touches[0].clientY - initialY; } 

    else { currentX = e.clientX - initialX; currentY = e.clientY - initialY; }

    xOffset = currentX; yOffset = currentY;

    fpContainer.style.transform = `translate3d(${currentX}px, ${currentY}px, 0)`;

}

</script>