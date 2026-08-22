<div id="floating-player" style="display:none;">

    <div id="fp-drag-handle">

        <span class="fp-grip"></span>

    </div>



    <div class="fp-controls">

        <div id="fp-skips" class="fp-skip-wrap">

            <button class="fp-skip" onclick="fpSkip(-10)" aria-label="<?php te('fc.fp_indietro_10'); ?>">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg>

            </button>

        </div>



        <button id="fp-playpause" onclick="fpTogglePlay()" aria-label="<?php te('fc.fp_play_pausa'); ?>">
            <svg class="fp-icon fp-icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6.3" y="4.8" width="4.1" height="14.4" rx="1.3"/><rect x="13.6" y="4.8" width="4.1" height="14.4" rx="1.3"/></svg>
            <svg class="fp-icon fp-icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="display:none;"><path d="M8.3 5.06v13.88a1 1 0 0 0 1.53.85l11.14-6.94a1 1 0 0 0 0-1.7L9.83 4.21a1 1 0 0 0-1.53.85z"/></svg>
        </button>



        <div id="fp-skips-right" class="fp-skip-wrap">

            <button class="fp-skip" onclick="fpSkip(10)" aria-label="<?php te('fc.fp_avanti_10'); ?>">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg>

            </button>

        </div>



        <select id="fp-speed" onchange="fpChangeSpeed(this.value)" aria-label="<?php te('fc.fp_velocita'); ?>">

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



<?php
/* Il componente puo' comparire piu' volte nella stessa pagina: il foglio si
   carica una volta sola. Il <link> sta qui e non nel <head> perche' questo file
   e' incluso dentro il <body>; funziona, e per un componente e' il compromesso
   giusto rispetto a chiedere a ogni pagina di ricordarselo. */
if (!defined('FP_CSS')) {
    define('FP_CSS', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'floating_player.css?v=' . (@filemtime(dirname(__FILE__) . '/floating_player.css') ?: 1) . '">' . "\n";
}
?>
">



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