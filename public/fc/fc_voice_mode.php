<!-- ================================================================
     GIOCA CON AUDIO — modalità di gioco vocale "mani libere"
     ----------------------------------------------------------------
     Il sito legge ad alta voce domande/risposte e resta sempre in
     ascolto per riconoscere i comandi parlati dall'utente (ripeti,
     pausa, riprendi, risposta, leggi/spiega/metafora, corretto/errato,
     successiva, frantuma, chiudi).

     Questo file va incluso una sola volta in fc_game.php, DOPO
     fc_audio_player.php (da cui riusa attachFloatingPlayer/
     isNativeTTSActive/activeAudioElement) e dopo lo script principale
     del gioco (da cui riusa speakText, pauseNativeTTS, resumeNativeTTS,
     revealCard, shatterCard, revealShatterCard, closeShatterModal,
     closeAiModal, ttsSettings, currentAiAudio). Tutte queste funzioni
     sono dichiarate a livello di script "classico" in fc_game.php,
     quindi sono visibili anche da qui senza bisogno di passarle
     esplicitamente.

     Espone un'unica API pubblica: window.VoiceGameMode.handleHeaderButton()
     richiamata dal bottone microfono nell'intestazione di fc_game.php.
     ================================================================ -->

<div id="vg-intro-overlay" class="vg-overlay" style="display:none;">
    <div class="vg-sheet" role="dialog" aria-modal="true" aria-labelledby="vg-sheet-title">
        <div class="vg-sheet-handle"></div>

        <div class="vg-sheet-head">
            <div class="vg-sheet-icon">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3z"/><path d="M17.3 11a5.3 5.3 0 0 1-10.6 0H5a7 7 0 0 0 6 6.93V21h2v-3.07A7 7 0 0 0 19 11h-1.7z"/></svg>
            </div>
            <h3 id="vg-sheet-title"><?php te('fc.vg_titolo'); ?></h3>
            <p class="vg-sheet-sub"><?php te('fc.vg_intro'); ?></p>
        </div>

        <div id="vg-error-box" class="vg-error-box" style="display:none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            <span id="vg-error-text"></span>
        </div>

        <div class="vg-cmd-list">
            <div class="vg-group-label"><?php te('fc.vg_mentre_ascolti'); ?></div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-indigo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_ripeti'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_ripeti'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-blue"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="6.3" y="4.5" width="4" height="15" rx="1.3"/><rect x="13.7" y="4.5" width="4" height="15" rx="1.3"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_pausa'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_pausa'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-blue"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.3 5.06v13.88a1 1 0 0 0 1.53.85l11.14-6.94a1 1 0 0 0 0-1.7L9.83 4.21a1 1 0 0 0-1.53.85z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_riprendi'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_riprendi'); ?></span></span>
            </div>

            <div class="vg-group-label"><?php te('fc.vg_per_rispondere'); ?></div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1v.2h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_risposta'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_risposta'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-purple"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_leggi'); ?>», «<?php te('fc.lbl_spiega'); ?>», «<?php te('fc.lbl_metafora'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_ai'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_corretto'); ?>» / «<?php te('fc.vg_giusto'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_corretto'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_errato'); ?>» / «<?php te('fc.vg_sbagliato'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_errato'); ?></span></span>
            </div>

            <div class="vg-group-label"><?php te('fc.vg_per_avanti'); ?></div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_successiva'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_successiva'); ?></span></span>
            </div>

            <div class="vg-group-label"><?php te('fc.vg_frantuma_titolo'); ?></div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-purple"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 3 14h7l-1 8 11-14h-7z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_frantuma'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_frantuma'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-gray"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_chiudi'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_chiudi'); ?></span></span>
            </div>
        </div>

        <div class="vg-sheet-actions">
            <button id="vg-cancel-btn" class="vg-btn vg-btn-ghost" type="button"><?php te('comune.annulla'); ?></button>
            <button id="vg-start-btn" class="vg-btn vg-btn-primary" type="button"><?php te('fc.vg_ho_capito'); ?></button>
        </div>
        <button id="vg-exit-btn" class="vg-btn vg-btn-danger" type="button" style="display:none;"><?php te('fc.vg_termina'); ?></button>
    </div>
</div>

<div id="vg-orb-wrap">
    <div id="vg-coldstart-hint" class="vg-coldstart-hint"></div>
    <div id="vg-caption" class="vg-caption" aria-live="polite"></div>
    <button id="vg-orb" type="button" aria-label=T('fc.vg_attiva')>
        <svg class="vg-mic-ic" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3z"/><path d="M17.3 11a5.3 5.3 0 0 1-10.6 0H5a7 7 0 0 0 6 6.93V21h2v-3.07A7 7 0 0 0 19 11h-1.7z"/></svg>
        <div id="vg-coldstart-badge" class="vg-coldstart-badge" aria-live="polite"></div>
    </button>
</div>

<!-- Chiesto SOLO su iOS/WebKit, al termine di una sessione, per decidere se
     avvisato del bug noto prima di uscire davvero. -->
<div id="vg-ask-overlay" class="vg-ask-overlay" style="display:none;">
    <div class="vg-ask-card" role="dialog" aria-modal="true" aria-labelledby="vg-ask-title">
        <h3 id="vg-ask-title" class="vg-ask-title"><?php te('fc.vg_conferma_uscita'); ?></h3>
        <p class="vg-ask-body">
            <?php te('fc.vg_avviso_iphone'); ?></p>
        <div class="vg-ask-actions">
            <button id="vg-ask-no-btn" class="vg-btn vg-btn-primary" type="button"><?php te('fc.vg_resta'); ?></button>
            <button id="vg-ask-yes-btn" class="vg-btn vg-btn-ghost" type="button"><?php te('fc.vg_esci'); ?></button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_voice_mode.css?v=<?php echo @filemtime(__DIR__ . '/fc_voice_mode.css') ?: 1; ?>">

<script>
(function () {
    'use strict';

    /* ============================================================
       0. RIFERIMENTI DOM + STATO
       ============================================================ */
    var elOverlay, elStartBtn, elCancelBtn, elExitBtn, elErrorBox, elErrorText;
    var elOrbWrap, elOrb, elCaption;
    var elAskOverlay, elAskYesBtn, elAskNoBtn;
    var elColdBadge;
    var elColdHint;

    var VG = {
        active: false,           // modalità vocale avviata?
        phase: 'idle',           // idle | question | answer | awaiting-next | ai-audio |
                                  // shatter-loading | shatter-q | shatter-a | shatter-finished
        shatterIndex: 0,
        shatterCount: 0,
        pendingGrade: false,     // true tra il click su corretto/errato e l'arrivo della pagina nuova
        prevPhaseBeforeAi: null,
        savedTtsMode: null,
        shouldListen: false,
        running: false
    };

    var recognition = null;
    var pageObserver = null;
    var captionHideTimer = null;
    var isRecognizing = false; // <--- NUOVA: Lock per l'OS
    var coldStartTimer = null;
    var coldStartResolved = true;

    /* ============================================================
       0-ter. CONVIVENZA CON LE VOCI "FILE" (Piper / voci via server)
       ------------------------------------------------------------
       CAUSA VERA del "micro morto + 40s" (rivista dopo i test sul campo):
       non è il thread bloccato. È il CONFLITTO DI SESSIONE AUDIO di iOS.
       SpeechRecognition tiene aperta una sessione di REGISTRAZIONE; quando
       parte un <audio> (Piper), iOS attiva una sessione di RIPRODUZIONE e,
       sull'iPhone, INTERROMPE la registrazione. recognition.onend scatta.
       Fin qui è inevitabile: nessuna pagina web può impedirlo.

       Il DISASTRO da 40s invece lo causavamo NOI: onend tentava subito un
       safeStart, ma con l'audio ancora in riproduzione iOS rifiuta l'avvio
       e mette la sessione in "penalty" (~40s). Ogni ulteriore tentativo
       (heartbeat, watchdog, altri onend) rinnovava la penalità.

       CURA: mentre una voce-file suona, NON tentare MAI di riavviare il
       microfono. Restiamo fermi. Un solo restart pulito quando l'audio è
       finito ('piper:ended'), dopo un attimo che lascia a iOS il tempo di
       rilasciare la sessione di riproduzione. Così non si entra in penalty
       e il micro riparte quasi sempre subito.
       ============================================================ */
    var piperPlaybackAttivo = false;  // una voce-file sta suonando adesso
    var lastCaptionAt = 0;
    var piperGateTimer = null;

    // È il "cancello": qualsiasi punto che vorrebbe (ri)avviare il micro deve
    // rispettarlo. Durante il playback il micro DEVE restare spento.
    function micPuoRiavviarsi() {
        // Ignora isSpeakingNative se a parlare è Piper e non la voce robotica di Apple
        var isRealNativeVoice = window.isSpeakingNative && !piperPlaybackAttivo;
        return VG.active && VG.shouldListen && !isRealNativeVoice;
    }

    function apriGatePiper() {
        // Sblocca il cancello e riaccende il micro con UN solo tentativo pulito.
        if (piperGateTimer) { clearTimeout(piperGateTimer); piperGateTimer = null; }
        piperPlaybackAttivo = false;
        if (!VG.active || !VG.shouldListen) return;
        setTimeout(function () {
            if (micPuoRiavviarsi() && !isRecognizing) safeStart();
        }, 350);
    }

    window.addEventListener('piper:willplay', function () {
        piperPlaybackAttivo = true;
        
        // AGGIUNTA 1: Attiva lo scudo acustico usato per AI Tutor
        acquireEchoCancelledStream();

        if (piperGateTimer) clearTimeout(piperGateTimer);
        piperGateTimer = setTimeout(function () {
            piperPlaybackAttivo = false;
            releaseEchoCancelledStream(); // Rilascia lo scudo in caso di timeout estremo
            if (micPuoRiavviarsi() && !isRecognizing) safeStart();
        }, 30000);
    });

    window.addEventListener('piper:ended', function () {
                
        apriGatePiper();
        
        // AGGIUNTA 3: Forza il riavvio del microfono esattamente come in currentAiAudio.onended
        if (!isRecognizing && VG.shouldListen) {
            setTimeout(function() { safeStart(); }, 150);
        }
    });

    function stopTuttoIlParlato() {
        var fatto = false;
        try { if (typeof stopNativeTTS === 'function') { stopNativeTTS(); fatto = true; } } catch (e) {}
        if (!fatto) {
            try { window.speechSynthesis.cancel(); } catch (e) {}
            try { if (window.PiperTTS) window.PiperTTS.ferma(); } catch (e) {}
        }
    }

    /* Guardiano leggero: gira ogni 5s, SOLO per togliere una didascalia rimasta
       appesa e per riavviare il micro se è fermo SENZA che stia suonando nulla.
       Non tocca mai il micro durante il playback: quello è territorio proibito. */
    var watchdogTimer = null;
    function startWatchdog() {
        stopWatchdog();
        watchdogTimer = setInterval(function () {
            if (!VG.active) return;
            if (elCaption && elCaption.classList.contains('vg-show') &&
                lastCaptionAt > 0 && Date.now() - lastCaptionAt > 12000) {
                elCaption.classList.remove('vg-show');
            }
            if (micPuoRiavviarsi() && !isRecognizing) safeStart();
        }, 5000);
    }
    function stopWatchdog() {
        if (watchdogTimer) { clearInterval(watchdogTimer); watchdogTimer = null; }
    }

    /* ============================================================
       0-bis. RILEVAMENTO PIATTAFORMA APPLE (WebKit/iOS)
       Il bug della "sessione zombie sorda" è specifico di iOS
       (Safari e Chrome-su-iOS usano entrambi WebKit sotto il
       cofano). Su iPadOS 13+ Safari si spaccia per "Macintosh":
       lo distinguiamo controllando i punti di tocco. Su qualunque
       altra piattaforma il comportamento resta quello di sempre,
       identico al file originale.
       ============================================================ */
    function isAppleZombieProne() {
        var ua = navigator.userAgent || '';
        var isIOSClassic = /iPhone|iPod|iPad/.test(ua);
        var isIPadOS13Plus = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return isIOSClassic || isIPadOS13Plus;
    }

    /* ============================================================
       0-quater. MEMORIA PERSISTENTE (localStorage) DEL MICROFONO
       window.vgLastStopTime da solo non basta: è in memoria, sparisce
       ad ogni ricarica di pagina. Dato che il cambio di flashcard sembra
       comportarsi come una ricarica vera (il log stesso si azzera), un
       vero spegnimento + un "sembra essersi ricaricato di recente" vanno
       trattati allo stesso modo: entrambi meritano il countdown, perché
       in entrambi i casi la sessione di riconoscimento va rifatta da zero.
       ============================================================ */
    var LS_HEARTBEAT_KEY = 'vg_micHeartbeat';
    var LS_STOP_KEY = 'vg_lastStopTime';
    var MIC_RISK_WINDOW_MS = 5 * 60 * 1000; // 5 minuti: unico dato certo che abbiamo (a 5' serviva ancora, a 2h no)
    var heartbeatTimer = null;

    function lsSet(key, val) { try { localStorage.setItem(key, String(val)); } catch (e) {} }
    function lsGetNum(key) { try { return parseInt(localStorage.getItem(key) || '0', 10) || 0; } catch (e) { return 0; } }

    function writeHeartbeat() { lsSet(LS_HEARTBEAT_KEY, Date.now()); }
    function writeStopTime() { lsSet(LS_STOP_KEY, Date.now()); }

    function startHeartbeat() {
        stopHeartbeat();
        writeHeartbeat();
        heartbeatTimer = setInterval(writeHeartbeat, 3000);
    }
    function stopHeartbeat() {
        if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    }

    // true se: un vero stop recente, O un battito recente (microfono
    // probabilmente ancora attivo quando la pagina si è (ri)caricata).
    function recentMicRiskDetected() {
        var mostRecent = Math.max(lsGetNum(LS_STOP_KEY), lsGetNum(LS_HEARTBEAT_KEY), window.vgLastStopTime || 0);
        return mostRecent > 0 && (Date.now() - mostRecent) < MIC_RISK_WINDOW_MS;
    }

    /* ============================================================
       1. NORMALIZZAZIONE TESTO + FUZZY MATCH
       (tollerante a piccoli errori di trascrizione: lettere scambiate,
       mancanti o in più vengono comunque riconosciute)
       ============================================================ */
    function normalize(s) {
        return (s || '')
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /* Le parole di cortesia da togliere prima di riconoscere il comando
       ("puoi ripeti per favore" -> "ripeti"). Vengono dal dizionario perche' in
       inglese sono altre, e in italiano non aiuterebbero. */
    var FILLERS = T('fc.vg_fillers').split(',').map(function (x) { return x.trim().toLowerCase(); }).filter(Boolean);
    function stripFillers(s) {
        var out = ' ' + s + ' ';
        FILLERS.forEach(function (f) {
            out = out.split(' ' + f + ' ').join(' ');
        });
        return out.replace(/\s+/g, ' ').trim();
    }

    function levenshtein(a, b) {
        var al = a.length, bl = b.length;
        if (al === 0) return bl;
        if (bl === 0) return al;
        var dp = new Array(bl + 1);
        for (var j = 0; j <= bl; j++) dp[j] = j;
        for (var i = 1; i <= al; i++) {
            var prev = dp[0];
            dp[0] = i;
            for (j = 1; j <= bl; j++) {
                var tmp = dp[j];
                dp[j] = Math.min(dp[j] + 1, dp[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
                prev = tmp;
            }
        }
        return dp[bl];
    }

    function similarity(a, b) {
        if (!a.length && !b.length) return 1;
        var d = levenshtein(a, b);
        return 1 - d / Math.max(a.length, b.length);
    }

    // Dizionario comandi: intento -> frasi candidate (sinonimi e varianti naturali)
    /* Sinonimi di ogni comando, presi dal dizionario: sono le frasi che il
       microfono confronta, quindi devono essere nella lingua in cui l'utente
       parla. Nel dizionario stanno come elenco separato da virgole. */
    var COMMANDS = {};
    ['ripeti', 'pausa', 'riprendi', 'risposta', 'leggi', 'spiega', 'metafora', 'corretto', 'errato', 'successiva', 'frantuma', 'chiudi'].forEach(function (intento) {
        COMMANDS[intento] = T('fc.cmd_' + intento).split(',').map(function (x) {
            return x.trim().toLowerCase();
        }).filter(Boolean);
    });

    /* Le etichette sono separate dai sinonimi: questa e' quella che si legge
       sullo schermo, i sinonimi sono quelli che si dicono a voce. */
    var INTENT_LABELS = {};
    Object.keys(COMMANDS).forEach(function (intento) {
        INTENT_LABELS[intento] = T('fc.lbl_' + intento);
    });

    var COMMAND_INDEX = Object.keys(COMMANDS).map(function (intent) {
        return {
            intent: intent,
            phrases: COMMANDS[intent].map(function (p) {
                var n = normalize(p);
                return { text: n, words: n.split(' ').length };
            })
        };
    });

    // Soglie di riconoscimento (tarabili qui in un unico punto)
    var MATCH_SIM_MIN = 0.72;       // somiglianza minima frase-per-frase
    var MATCH_WEIGHTED_MIN = 0.6;   // punteggio minimo dopo la penalità di lunghezza

    // Trova il miglior intento per un transcript. Penalizza i comandi che
    // coprono solo una piccola frazione di un'affermazione molto più lunga:
    // questo riduce il rischio che il microfono scambi per un comando una
    // parola incontrata per caso dentro la lettura automatica della domanda.
    function matchIntentDetailed(transcript) {
        var cleaned = stripFillers(normalize(transcript));
        if (!cleaned) return null;
        var uWords = cleaned.split(' ').filter(Boolean);
        var uCount = uWords.length;
        var best = null;

        COMMAND_INDEX.forEach(function (entry) {
            entry.phrases.forEach(function (ph) {
                var score = similarity(cleaned, ph.text);
                if (uCount >= ph.words) {
                    var escaped = ph.text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    var re = new RegExp('(^|\\s)' + escaped + '(\\s|$)');
                    if (re.test(cleaned)) score = Math.max(score, 0.97);
                }
                var lenRatio = Math.min(1, (ph.words + 2) / Math.max(uCount, 1));
                var weighted = score * (0.55 + 0.45 * lenRatio);
                if (!best || weighted > best.weighted) {
                    best = { intent: entry.intent, score: score, weighted: weighted };
                }
            });
        });

        if (best && best.score >= MATCH_SIM_MIN && best.weighted >= MATCH_WEIGHTED_MIN) return best;
        return null;
    }

    /* ============================================================
       2. LETTURA DEL DOM DI GAME_FC (carta corrente, testo grezzo)
       ============================================================ */
    function lastContainer() {
        var all = document.querySelectorAll('.container');
        return all.length ? all[all.length - 1] : null;
    }
    function currentCardBox() {
        var c = lastContainer();
        if (!c) return null;
        return c.querySelector('.card-box:not(.graded)');
    }
    function cardIndex(card) {
        if (!card) return null;
        var m = card.id.match(/(\d+)$/);
        return m ? m[1] : null;
    }
    function cardIdOf(card) {
        if (!card) return null;
        var url = card.getAttribute('data-url-correct') || card.getAttribute('data-url-wrong');
        if (!url) return null;
        try {
            return new URL(url, window.location.href).searchParams.get('img_id');
        } catch (e) { return null; }
    }
    function rawTextOf(selector) {
        var el = document.querySelector(selector);
        if (!el) return '';
        var raw = el.getAttribute('data-raw');
        return (raw !== null && raw !== undefined) ? raw : (el.textContent || '');
    }

    /* ============================================================
       3. FEEDBACK VISIVO (orb + caption)
       ============================================================ */
    function setOrbState(state) {
        if (!elOrb) return;
        elOrb.classList.remove('vg-state-listening', 'vg-state-speaking', 'vg-state-paused', 'vg-state-thinking', 'vg-state-waiting', 'vg-state-coldstart');
        elOrb.classList.add('vg-state-' + state);
    }

    // Badge piccolo agganciato all'orb per il countdown "bug iOS": resta
    // volutamente separato da showCaption/elCaption, che deve restare
    // libero per il riconoscimento vero e proprio e non venirne oscurato.
    function showColdStartBadge(text, success) {
        if (!elColdBadge) return;
        elColdBadge.textContent = text;
        elColdBadge.classList.toggle('vg-success', !!success);
        elColdBadge.classList.add('vg-show');
    }
    function hideColdStartBadge() {
        if (!elColdBadge) return;
        elColdBadge.classList.remove('vg-show', 'vg-success');
    }

    // Countdown "attendi il reset del bug iPhone": parte SEMPRE da 40s alla
    // riattivazione (su iOS, dopo un vero spegnimento) — non proviamo a
    // indovinare un tempo residuo più corto, perché dai test non è ancora
    // chiaro quanto sia affidabile farlo. Vive nel badge separato, mai nel
    // caption principale, così il riconoscimento vero non viene mai coperto.
    function startColdStartCountdown() {
        var secondsLeft = 40;
        coldStartResolved = false;

        // Non copriamo lo stato "sta parlando": se il countdown parte mentre una
        // voce sta ancora leggendo, l'orb deve continuare a dirlo; il badge del
        // countdown è comunque visibile di suo, accanto all'orb.
        if (!window.isSpeakingNative) setOrbState('coldstart');
        showColdStartBadge(secondsLeft + 's');

        if (elColdHint) {
            elColdHint.textContent = T('fc.vg_bug_iphone');
            elColdHint.classList.add('vg-show');
            setTimeout(function () {
                if (elColdHint) elColdHint.classList.remove('vg-show');
            }, 5000);
        }

        if (coldStartTimer) clearInterval(coldStartTimer);
        coldStartTimer = setInterval(function () {
            if (coldStartResolved) { clearInterval(coldStartTimer); return; }
            secondsLeft--;
            if (secondsLeft > 0) {
                showColdStartBadge(secondsLeft + 's');
            } else {
                resolveColdStart(false);
            }
        }, 1000);
    }

    // earlyDetection=true quando arriva un segnale reale di ascolto
    // (result/speechstart) prima dei 40s; false quando il countdown arriva
    // naturalmente a 0.
    function resolveColdStart(earlyDetection) {
        if (coldStartResolved) return;
        coldStartResolved = true;
        if (coldStartTimer) { clearInterval(coldStartTimer); coldStartTimer = null; }
        if (elColdHint) elColdHint.classList.remove('vg-show');

        if (earlyDetection) {
            showColdStartBadge(T('fc.vg_pronto'), true);
            setTimeout(hideColdStartBadge, 1500);
        } else {
            hideColdStartBadge();
        }
        if (VG.active) setOrbState('listening');
        if (micPuoRiavviarsi() && !isRecognizing) safeStart();
    }

    // type: 'interim' (live, tenue) | 'recognized' (comando riconosciuto) | 'hint' (suggerimento del sistema)
    function showCaption(text, type) {
        if (!elCaption || !text) return;
        elCaption.textContent = text;
        elCaption.classList.remove('vg-recognized', 'vg-hint');
        if (type === 'recognized') elCaption.classList.add('vg-recognized');
        else if (type === 'hint') elCaption.classList.add('vg-hint');
        elCaption.classList.add('vg-show');
        lastCaptionAt = Date.now();
        if (captionHideTimer) clearTimeout(captionHideTimer);
        var dur = type === 'hint' ? 3200 : (type === 'recognized' ? 2000 : 1500);
        captionHideTimer = setTimeout(function () { elCaption.classList.remove('vg-show'); }, dur);
    }

    /* ============================================================
       4. FLUSSO PRINCIPALE: DOMANDA / RISPOSTA / VALUTAZIONE
       ============================================================ */
    function readQuestion() {
        var card = currentCardBox();
        if (!card) { setOrbState('listening'); return; }
        var idx = cardIndex(card);
        VG.phase = 'question';
        setOrbState('speaking');
        var raw = rawTextOf('#q-section-' + idx + ' .raw-text');
        var audioEl = document.getElementById('player_q_' + idx);
        speakText(raw, function () {
            if (audioEl) { try { audioEl.currentTime = 0; audioEl.play(); } catch (e) {} }
            if (VG.active && VG.phase === 'question') setOrbState('listening');
        });
    }

    function showAndReadAnswer() {
        var card = currentCardBox();
        if (!card) return;
        var idx = cardIndex(card);
        var ansBox = document.getElementById('ans-section-' + idx);
        if (ansBox && (ansBox.style.display === 'none' || ansBox.style.display === '')) {
            revealCard(idx);
        }
        VG.phase = 'answer';
        setOrbState('speaking');
        var raw = rawTextOf('#ans-section-' + idx + ' .raw-text');
        var audioEl = document.getElementById('player_a_' + idx);
        speakText(raw, function () {
            if (audioEl) { try { audioEl.currentTime = 0; audioEl.play(); } catch (e) {} }
            if (VG.active && VG.phase === 'answer') setOrbState('listening');
        });
    }

    function markCard(result) {
        if (VG.phase !== 'answer' && VG.phase !== 'ai-audio') return;
        var card = currentCardBox();
        if (!card) return;
        var idx = cardIndex(card);
        
        var ansBox = document.getElementById('ans-section-' + idx) || document.getElementById('graded-ans-' + idx);
        var link = ansBox ? ansBox.querySelector(result === 'correct' ? 'a.btn-green' : 'a.btn-red') : null;
        if (!link) return;
        
        stopTuttoIlParlato();
        
        // BLOCCA IMMEDIATAMENTE IL CARICAMENTO E L'AUDIO DELL'AI
        if (typeof window.cancelAiAudio === 'function') {
            window.cancelAiAudio();
        } else if (typeof currentAiAudio !== 'undefined' && currentAiAudio) {
            currentAiAudio.pause();
        }
        
        VG.pendingGrade = true;
        VG.phase = 'awaiting-next';
        setOrbState('waiting');
        link.click(); 
    }

    function goSuccessiva() {
        if (VG.phase === 'shatter-a') { advanceShatter(); return; }
        if (VG.phase === 'awaiting-next') {
            VG.phase = 'idle';
            readQuestion();
            return;
        }
        // in ogni altra fase non c'è nulla su cui avanzare: comando ignorato
    }

    /* ============================================================
       5. STRUMENTI AI (leggi / spiega / metafora)
       ============================================================ */
    // FIX interferenza audio AI: a differenza della voce nativa del browser,
    // l'audio AI (un <audio> qualunque) non viene escluso automaticamente
    // dall'ascolto. Il microfono RESTA ACCESO (puoi dire "pausa"/"corretto"
    // mentre parla, come volevi) — teniamo aperto in parallelo, per tutta
    // la riproduzione, uno stream con echoCancellation esplicito: non lo
    // usiamo per elaborare audio, serve solo a tenere la sessione audio
    // condivisa in modalità di cancellazione eco. Tecnica standard per
    // "il mio stesso audio non deve finire nella trascrizione" (stesso
    // consiglio dato da Deepgram per i loro voice agent) — non garantita al
    // 100% con SpeechRecognition (che non accetta stream esterni), ma è il
    // miglior tentativo fondato che abbiamo, da validare sul dispositivo.
    var aiEchoStream = null;
    function acquireEchoCancelledStream() {
        if (aiEchoStream || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
        navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
        }).then(function (stream) {
            aiEchoStream = stream;
        }).catch(function () {
            aiEchoStream = null;
        });
    }
    function releaseEchoCancelledStream() {
        if (aiEchoStream) {
            try { aiEchoStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
            aiEchoStream = null;
        }
    }

    function triggerAiTool(mode) {
        if (VG.phase !== 'answer') return;
        var card = currentCardBox();
        if (!card) return;
        var idx = cardIndex(card);
        
        // Aggiunto il supporto per le carte già valutate
        var ansBox = document.getElementById('ans-section-' + idx) || document.getElementById('graded-ans-' + idx);
        if (!ansBox) return;
        
        var btn = ansBox.querySelector('.chip-ai[onclick*="playAI(\'' + mode + '\'"]');
        if (!btn) return;
        
        // Muta istantaneamente il TTS nativo e resetta i suoi controlli
        stopTuttoIlParlato();
        if (typeof window.currentUtterance !== 'undefined') window.currentUtterance = null;
        if (typeof window.isSpeakingNative !== 'undefined') window.isSpeakingNative = false;

        acquireEchoCancelledStream();

        VG.prevPhaseBeforeAi = VG.phase;
        VG.phase = 'ai-audio';
        setOrbState('thinking');
        btn.click();
    }

    var aiAudioListenersWired = false;
    function wireAiAudioListeners() {
        if (aiAudioListenersWired || typeof currentAiAudio === 'undefined' || !currentAiAudio) return;
        
        currentAiAudio.addEventListener('playing', function () {
            if (currentAiAudio.src && currentAiAudio.src.indexOf('data:audio') !== -1) return;
            if (VG.active && VG.phase === 'ai-audio') setOrbState('speaking');
        });
        
        currentAiAudio.addEventListener('ended', function () {
            if (currentAiAudio.src && currentAiAudio.src.indexOf('data:audio') !== -1) return;

            if (VG.active && VG.phase === 'ai-audio') {
                VG.phase = VG.prevPhaseBeforeAi || 'answer';
                setOrbState('listening');
                
                if (!isRecognizing && VG.shouldListen) {
                    safeStart();
                }
            }
        });
        
        aiAudioListenersWired = true;
    }

    /* ============================================================
       6. FRANTUMA (mini-domande)
       ============================================================ */
    function startFrantuma() {
        if (VG.phase !== 'question') return;
        var card = currentCardBox();
        if (!card) return;
        var idx = cardIndex(card);
        var cid = cardIdOf(card);
        var btn = card.querySelector('.chip-shatter');
        if (!btn) return;
        stopTuttoIlParlato();
        VG.phase = 'shatter-loading';
        setOrbState('thinking');
        shatterCard(idx, cid, btn);
        waitForShatterReady();
    }

    function waitForShatterReady() {
        var elapsed = 0, step = 300, limit = 20000;
        var timer = setInterval(function () {
            elapsed += step;
            var modal = document.getElementById('shatter-modal');
            if (!modal || modal.style.display === 'none') {
                clearInterval(timer);
                if (VG.phase === 'shatter-loading') { VG.phase = 'question'; setOrbState('listening'); }
                return;
            }
            if (document.getElementById('shatter-qraw-0')) {
                clearInterval(timer);
                VG.shatterIndex = 0;
                VG.shatterCount = document.querySelectorAll('[id^="shatter-qraw-"]').length;
                readShatterQuestion(0);
                return;
            }
            if (elapsed >= limit) {
                clearInterval(timer);
                VG.phase = 'question';
                setOrbState('speaking');
                speakText(T('fc.vg_no_frantuma'), function () {
                    if (VG.active && VG.phase === 'question') setOrbState('listening');
                });
            }
        }, step);
    }

    function readShatterQuestion(i) {
        VG.phase = 'shatter-q';
        VG.shatterIndex = i;
        setOrbState('speaking');
        var raw = rawTextOf('#shatter-qraw-' + i);
        speakText(raw, function () {
            if (VG.active && VG.phase === 'shatter-q') setOrbState('listening');
        });
    }

    function readShatterAnswer() {
        var i = VG.shatterIndex;
        var ansBox = document.getElementById('shatter-ans-' + i);
        if (ansBox && ansBox.style.display === 'none') revealShatterCard(i);
        VG.phase = 'shatter-a';
        setOrbState('speaking');
        var raw = rawTextOf('#shatter-araw-' + i);
        speakText(raw, function () {
            if (VG.active && VG.phase === 'shatter-a') setOrbState('listening');
        });
    }

    function advanceShatter() {
        var next = VG.shatterIndex + 1;
        if (next < VG.shatterCount) {
            readShatterQuestion(next);
        } else {
            VG.phase = 'shatter-finished';
            setOrbState('speaking');
            speakText(T('fc.vg_frantuma_finita'), function () {
                if (VG.active && VG.phase === 'shatter-finished') {
                    setOrbState('listening');
                    showCaption(T('fc.vg_di_corretta_sbagliata', {corretta: T('fc.lbl_corretto'), sbagliata: T('fc.lbl_errato')}), 'hint');
                }
            });
        }
    }

    function judgeShatter(result) {
        if (VG.phase !== 'shatter-finished') return;
        var link = document.getElementById(result === 'correct' ? 'shatter-correct-btn' : 'shatter-wrong-btn');
        if (!link) return;
        stopTuttoIlParlato();
        VG.pendingGrade = true;
        VG.phase = 'awaiting-next';
        setOrbState('waiting');
        link.click(); // chiama anche closeShatterModal() tramite il suo onclick, poi naviga
    }

    function closeFrantuma() {
        var shatterModal = document.getElementById('shatter-modal');
        var aiModal = document.getElementById('ai-modal');
        if (shatterModal && shatterModal.style.display !== 'none') {
            stopTuttoIlParlato();
            closeShatterModal();
            var card = currentCardBox();
            if (card) {
                var idx = cardIndex(card);
                VG.phase = 'question';
                setOrbState('speaking');
                var raw = rawTextOf('#q-section-' + idx + ' .raw-text');
                var audioEl = document.getElementById('player_q_' + idx);
                speakText(raw, function () {
                    if (audioEl) { try { audioEl.currentTime = 0; audioEl.play(); } catch (e) {} }
                    if (VG.active && VG.phase === 'question') setOrbState('listening');
                });
            }
            return;
        }
        if (aiModal && aiModal.style.display !== 'none') {
            closeAiModal();
            VG.phase = VG.prevPhaseBeforeAi || 'answer';
            setOrbState('listening');
        }
    }

    /* ============================================================
       7. RIPETI / PAUSA / RIPRENDI (dipendono dalla fase corrente)
       ============================================================ */
    function doRipeti() {
        var card, idx;
        switch (VG.phase) {
            case 'question':
                card = currentCardBox(); if (!card) return;
                idx = cardIndex(card);
                setOrbState('speaking');
                speakText(rawTextOf('#q-section-' + idx + ' .raw-text'), function () {
                    var aEl = document.getElementById('player_q_' + idx);
                    if (aEl) { try { aEl.currentTime = 0; aEl.play(); } catch (e) {} }
                    if (VG.active && VG.phase === 'question') setOrbState('listening');
                });
                break;
            case 'answer':
                card = currentCardBox(); if (!card) return;
                idx = cardIndex(card);
                setOrbState('speaking');
                speakText(rawTextOf('#ans-section-' + idx + ' .raw-text'), function () {
                    var aEl2 = document.getElementById('player_a_' + idx);
                    if (aEl2) { try { aEl2.currentTime = 0; aEl2.play(); } catch (e) {} }
                    if (VG.active && VG.phase === 'answer') setOrbState('listening');
                });
                break;
            case 'shatter-q':
                readShatterQuestion(VG.shatterIndex);
                break;
            case 'shatter-a':
                setOrbState('speaking');
                speakText(rawTextOf('#shatter-araw-' + VG.shatterIndex), function () {
                    if (VG.active && VG.phase === 'shatter-a') setOrbState('listening');
                });
                break;
            case 'shatter-finished':
                setOrbState('speaking');
                speakText(T('fc.vg_frantuma_finita'), function () {
                    if (VG.active && VG.phase === 'shatter-finished') setOrbState('listening');
                });
                break;
            case 'ai-audio':
                if (typeof currentAiAudio !== 'undefined' && currentAiAudio && currentAiAudio.src) {
                    currentAiAudio.currentTime = 0;
                    currentAiAudio.play().catch(function () {});
                }
                break;
            default: break;
        }
    }

    function doPausa() {
        if (VG.phase === 'ai-audio') {
            if (typeof currentAiAudio !== 'undefined' && currentAiAudio && !currentAiAudio.paused) currentAiAudio.pause();
        } else if (window.currentUtterance !== null) {
            pauseNativeTTS();
        }
        setOrbState('paused');
    }

    function doRiprendi() {
        if (VG.phase === 'ai-audio') {
            if (typeof currentAiAudio !== 'undefined' && currentAiAudio && currentAiAudio.paused) {
                currentAiAudio.play().catch(function () {});
            }
            setOrbState('speaking');
        } else if (window.currentUtterance === null && window.currentCleanTextForTTS) {
            resumeNativeTTS();
            setOrbState('speaking');
        }
    }

    /* ============================================================
       8. DISPATCH COMANDI
       ============================================================ */
    function doRisposta() {
        if (VG.phase === 'question') showAndReadAnswer();
        else if (VG.phase === 'shatter-q') readShatterAnswer();
    }
    function doCorretto() {
        if (VG.phase === 'answer' || VG.phase === 'ai-audio') markCard('correct');
        else if (VG.phase === 'shatter-finished') judgeShatter('correct');
        // "corretto" solo dall'ultima mini-card: per essere sicuri di aver
        // saputo TUTTA la carta serve averle riviste tutte.
        else if (VG.phase === 'shatter-a' && VG.shatterIndex === VG.shatterCount - 1) {
            VG.phase = 'shatter-finished';
            judgeShatter('correct');
        }
    }

    function doErrato() {
        if (VG.phase === 'answer' || VG.phase === 'ai-audio') markCard('wrong');
        else if (VG.phase === 'shatter-finished') judgeShatter('wrong');
        // "errato" invece da QUALSIASI mini-card: basta sbagliarne una per
        // sapere di non aver saputo l'intera carta, niente bisogno di
        // rivederle tutte prima di poterlo dire.
        else if (VG.phase === 'shatter-a') {
            VG.phase = 'shatter-finished';
            judgeShatter('wrong');
        }
    }

    var HANDLERS = {
        ripeti: doRipeti,
        pausa: doPausa,
        riprendi: doRiprendi,
        risposta: doRisposta,
        leggi: function () { triggerAiTool('read'); },
        spiega: function () { triggerAiTool('explain'); },
        metafora: function () { triggerAiTool('metaphor'); },
        corretto: doCorretto,
        errato: doErrato,
        successiva: goSuccessiva,
        frantuma: startFrantuma,
        chiudi: closeFrantuma
    };

    /* ============================================================
       9. RICONOSCIMENTO VOCALE — sempre in ascolto
       ============================================================ */
    // Inizializza l'oggetto una sola volta. Se esiste già, non lo tocca.
    function initRecognitionSingleton() {
        if (recognition) return recognition;

        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return null;
        recognition = new SR();
        /* La lingua del riconoscimento segue quella del sito: era fissa a
           'it-IT', quindi in inglese il microfono non capiva nessun comando. */
        recognition.lang = T('fc.voice_lang');
        recognition.continuous = false; // Obbligatorio su iOS per non bloccare il demone
        recognition.interimResults = true;
        recognition.maxAlternatives = 3;

        // Ascoltatori "in parallelo" (addEventListener, non sovrascrivono onX):
        // servono solo a capire il prima possibile che il countdown "bug
        // iPhone" non è più necessario perché il microfono ascolta per
        // davvero. 'result' è il segnale più affidabile che abbiamo;
        // 'speechstart' è un bonus best-effort (affidabilità non confermata
        // su iOS, ma se scatta prematuramente il peggio che succede è che
        // il badge sparisce un filo troppo presto — nessun danno reale).
        recognition.addEventListener('result', function () { resolveColdStart(true); });
        recognition.addEventListener('speechstart', function () { resolveColdStart(true); });

        recognition.onstart = function () {
            isRecognizing = true;
            if (elOrb) elOrb.classList.add('vg-hearing');
        };

        recognition.onresult = function (event) {
            if (!VG.active || !VG.shouldListen) return;
            // Mentre una voce-file (Piper/server) parla dalle casse, il microfono
            // può sentire NOI: alziamo l'asticella dei comandi (accettiamo solo
            // match molto netti) e non mostriamo le trascrizioni live, che
            // sarebbero pezzi della lettura in corso, non parole dell'utente.
            var piperStaParlando = !!(window.PiperTTS && window.PiperTTS.inRiproduzione && window.PiperTTS.inRiproduzione());
            var interim = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var res = event.results[i];
                if (res.isFinal) {
                    var best = null;
                    for (var a = 0; a < res.length; a++) {
                        var cand = matchIntentDetailed(res[a].transcript);
                        if (cand && (!best || cand.weighted > best.weighted)) best = cand;
                    }
                    if (best && HANDLERS[best.intent] && (!piperStaParlando || best.weighted >= 0.8)) {
                        showCaption(INTENT_LABELS[best.intent] || best.intent, 'recognized');
                        HANDLERS[best.intent]();
                    } else if (!piperStaParlando) {
                        showCaption(res[0].transcript, 'interim');
                    }
                } else {
                    interim += res[0].transcript;
                }
            }
            if (interim && !piperStaParlando) showCaption(interim, 'interim');
        };

        recognition.onerror = function (e) {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                VG.shouldListen = false;
                showMicError();
            }
        };

        recognition.onend = function () {
            isRecognizing = false; // Il server Apple ha chiuso il socket correttamente
            if (elOrb) elOrb.classList.remove('vg-hearing');

            if (micPuoRiavviarsi()) {
                setTimeout(function () { if (micPuoRiavviarsi() && !isRecognizing) safeStart(); }, 150);
            }
        };

        return recognition;
    }

    function safeStart() {
        if (!recognition || !VG.active || !VG.shouldListen || isRecognizing) return;
        try {
            recognition.start();
        } catch (err) {
            var msg = String(err && err.message || err);
            if (/already started|already running/i.test(msg)) { isRecognizing = true; return; }
            setTimeout(function () { if (micPuoRiavviarsi() && !isRecognizing) safeStart(); }, 300);
        }
    }

    function showMicError() {
        if (elErrorBox && elErrorText) {
            elErrorText.textContent = T('fc.vg_serve_permesso');
            elErrorBox.style.display = 'flex';
        }
        deactivate(true); // "true" impedisce la creazione del blocco di 40s
        openIntro(false);
    }

    /* ============================================================
       10. CICLO DI VITA (popup, avvio/stop, osservatore pagine)
       ============================================================ */
    var cooldownTimer = null;

    function openIntro(reminder) {
        if (!elOverlay) return;
        if (reminder) {
            elStartBtn.textContent = T('fc.vg_continua');
            elCancelBtn.style.display = 'none';
            elExitBtn.style.display = 'block';
        } else {
            elStartBtn.textContent = T('fc.vg_ho_capito');
            elCancelBtn.style.display = '';
            elExitBtn.style.display = 'none';
        }
        if (elErrorBox && !elErrorBox.dataset.forced) elErrorBox.style.display = 'none';
        
        // Rimuoviamo i blocchi: l'utente può sempre premere Inizia
        elStartBtn.disabled = false;
        elStartBtn.style.opacity = '1';
        elStartBtn.style.cursor = '';
        
        elOverlay.style.display = 'flex';
    }

    function closeIntro() {
        if (elOverlay) elOverlay.style.display = 'none';
    }

    function onStartClick() {
        if (VG.active) { closeIntro(); return; }
        
        // SBLOCCO AUDIO iOS CORRETTO: 
        // Passiamo un audio WAV vuoto ma valido durante il click fisico dell'utente
        if (typeof currentAiAudio !== 'undefined' && currentAiAudio) {
            currentAiAudio.src = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA';
            currentAiAudio.play().catch(function(e){});
        }
        
        activate();
    }

    function activate() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            if (elErrorBox && elErrorText) {
                elErrorBox.dataset.forced = '1';
                elErrorText.textContent = T('fc.vg_no_supporto');
                elErrorBox.style.display = 'flex';
            }
            return;
        }

        delete elErrorBox.dataset.forced;
        closeIntro();

        VG.active = true;
        VG.phase = 'idle';
        VG.pendingGrade = false;
        VG.shouldListen = true; 

        acquireEchoCancelledStream();

        if (typeof ttsSettings !== 'undefined') {
            VG.savedTtsMode = ttsSettings.mode;
            ttsSettings.mode = 'auto'; 
            
            var modeSelect = document.getElementById('tts-mode');
            if (modeSelect) {
                modeSelect.value = 'auto';
                modeSelect.disabled = true;
            }
            try { localStorage.setItem('ttsSettings', JSON.stringify(ttsSettings)); } catch(e){}
        }

        if (elOrbWrap) elOrbWrap.style.display = 'flex';
        setOrbState('listening');
        wireAiAudioListeners();

        initRecognitionSingleton();
        VG.shouldListen = true;

        // Avviamo subito il microfono: iOS lo mette nella sua coda interna e inizia a sbloccarsi
        safeStart(); 

        if (isAppleZombieProne()) startHeartbeat();

        if (isAppleZombieProne()) {
            // --- Su iOS/WebKit: countdown SEMPRE a 40s da questo preciso
            // avvio (non proviamo a calcolare un residuo più corto: non
            // abbiamo dati abbastanza solidi sulla curva reale), ma con
            // smentita anticipata se il motore comincia ad ascoltare per
            // davvero prima dello scadere. Vive nel badge separato: non
            // tocca mai il caption del riconoscimento vero e proprio.
            // recentMicRiskDetected() (e non il solo vgLastStopTime) copre
            // anche il caso "la pagina si è ricaricata da sola col microfono
            // ancora acceso" (es. cambio flashcard), non solo lo spegnimento
            // esplicito. ---
            if (recentMicRiskDetected()) {
                startColdStartCountdown();
            }
        } else {
            // --- Comportamento originale, INVARIATO, per tutti i browser non Apple ---
            var timeSinceStop = Date.now() - (window.vgLastStopTime || 0);
            if (window.vgLastStopTime > 0 && timeSinceStop < 40000) {
                var timeLeft = Math.ceil((40000 - timeSinceStop) / 1000);
                setOrbState('waiting');
                showCaption(T('fc.vg_attesa_ios') + timeLeft + 's', 'hint');
                if (window.vgCooldownTimer) clearInterval(window.vgCooldownTimer);
                window.vgCooldownTimer = setInterval(function() {
                    timeLeft--;
                    if (timeLeft > 0) {
                        showCaption(T('fc.vg_attesa_ios') + timeLeft + 's', 'hint');
                    } else {
                        clearInterval(window.vgCooldownTimer);
                        showCaption(T('fc.vg_micro_pronto'), 'recognized');
                        setOrbState('listening');
                        if (!isRecognizing) safeStart();
                    }
                }, 1000);
            }
        }

        observePageChanges();

        // Avviamo il guardiano leggero: da qui vigila su didascalie appese e
        // microfono fermo (mai durante il playback).
        piperPlaybackAttivo = false;
        startWatchdog();
        
        setTimeout(function() {
            var card = currentCardBox();
            if (!card) {
                readQuestion();
                return;
            }
            
            var idx = cardIndex(card);
            var ansBox = document.getElementById('ans-section-' + idx);
            var gradedAns = document.getElementById('graded-ans-' + idx);
            
            var isAnsVisible = (ansBox && ansBox.style.display !== 'none' && ansBox.style.display !== '') || 
                            (gradedAns && gradedAns.style.display !== 'none' && gradedAns.style.display !== '');
            
            if (isAnsVisible) {
                VG.phase = 'answer';
                showAndReadAnswer(); 
            } else {
                readQuestion();
            }
        }, 350);
    }

    function deactivate(isError) {
        VG.active = false;
        VG.shouldListen = false;
        VG.phase = 'idle';
        stopHeartbeat();
        stopWatchdog();
        releaseEchoCancelledStream();
        if (isError !== true) writeStopTime();
        
        if (window.vgCooldownTimer) clearInterval(window.vgCooldownTimer);
        if (coldStartTimer) { clearInterval(coldStartTimer); coldStartTimer = null; }
        coldStartResolved = true;
        hideColdStartBadge();
        
        // Spegnimento dolce di Apple
        if (recognition && isRecognizing) {
            try { recognition.stop(); } catch (e) {} 
        }
        
        stopTuttoIlParlato();
        try { if (typeof currentAiAudio !== 'undefined' && currentAiAudio) currentAiAudio.pause(); } catch (e) {}
        
        if (typeof ttsSettings !== 'undefined') {
            ttsSettings.mode = 'no';
            var modeSelect = document.getElementById('tts-mode');
            if (modeSelect) {
                modeSelect.value = 'no';
                modeSelect.disabled = false;
            }
            try { localStorage.setItem('ttsSettings', JSON.stringify(ttsSettings)); } catch(e){}
        }
        
        if (elOrbWrap) elOrbWrap.style.display = 'none';

        // Salviamo il momento esatto in cui hai spento il microfono
        if (isError !== true) {
            window.vgLastStopTime = Date.now();
        }
        
        closeIntro();
    }

    function closeAskOverlay() {
        if (elAskOverlay) elAskOverlay.style.display = 'none';
    }

    // Punto d'ingresso del pulsante "Termina modalità vocale" su Apple:
    // invece di spegnere subito, chiediamo prima le intenzioni dell'utente.
    function handleExitClick() {
        if (!isAppleZombieProne()) { deactivate(); return; }
        if (elAskOverlay) {
            closeIntro(); // evitiamo due sheet sovrapposte
            elAskOverlay.style.display = 'flex';
        } else {
            deactivate(); // rete di sicurezza se il markup non è presente
        }
    }

    function observePageChanges() {
        if (pageObserver) return;
        pageObserver = new MutationObserver(function (mutations) {
            for (var m = 0; m < mutations.length; m++) {
                var added = mutations[m].addedNodes;
                for (var n = 0; n < added.length; n++) {
                    var node = added[n];
                    if (node.nodeType === 1 && node.classList && node.classList.contains('container') && node.hasAttribute('data-pos')) {
                        onNewPage();
                        return;
                    }
                }
            }
        });
        pageObserver.observe(document.body, { childList: true });
    }

    function onNewPage() {
        if (!VG.active) return;
        stopTuttoIlParlato();
        if (VG.pendingGrade) {
            VG.pendingGrade = false;
            VG.phase = 'awaiting-next';
            setOrbState('waiting');
            showCaption(T('fc.vg_di_successiva', {successiva: T('fc.lbl_successiva')}), 'hint');
        } else {
            VG.phase = 'idle';
            setTimeout(readQuestion, 350);
        }
    }

    /* ---------- Cablaggio UI statica ---------- */
    function wireUi() {
        elOverlay = document.getElementById('vg-intro-overlay');
        elStartBtn = document.getElementById('vg-start-btn');
        elCancelBtn = document.getElementById('vg-cancel-btn');
        elExitBtn = document.getElementById('vg-exit-btn');
        elErrorBox = document.getElementById('vg-error-box');
        elErrorText = document.getElementById('vg-error-text');
        elOrbWrap = document.getElementById('vg-orb-wrap');
        elOrb = document.getElementById('vg-orb');
        elCaption = document.getElementById('vg-caption');
        elColdBadge = document.getElementById('vg-coldstart-badge');
        elColdHint = document.getElementById('vg-coldstart-hint');
        elAskOverlay = document.getElementById('vg-ask-overlay');
        elAskYesBtn = document.getElementById('vg-ask-yes-btn');
        elAskNoBtn = document.getElementById('vg-ask-no-btn');

        if (elStartBtn) elStartBtn.addEventListener('click', onStartClick);
        if (elCancelBtn) elCancelBtn.addEventListener('click', closeIntro);
        // Su Apple passiamo dalla domanda "lo riuserai a breve?"; su tutti
        // gli altri browser il pulsante spegne subito, come sempre.
        if (elExitBtn) elExitBtn.addEventListener('click', handleExitClick);
        if (elAskYesBtn) elAskYesBtn.addEventListener('click', function () { closeAskOverlay(); deactivate(); });
        if (elAskNoBtn) elAskNoBtn.addEventListener('click', function () { closeAskOverlay(); });
        if (elOrb) elOrb.addEventListener('click', function () { openIntro(true); });
        if (elOverlay) elOverlay.addEventListener('click', function (e) { if (e.target === elOverlay) closeIntro(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireUi);
    } else {
        wireUi();
    }

    /* ---------- Prevenzione doppi audio nativi ---------- */
    // Mettiamo il silenziatore alle funzioni standard del sito mentre la voce è attiva
    var origPlay = window.playAudioFC;
    window.playAudioFC = function(idx, type) {
        if (VG && VG.active) return; // Ignora l'autoplay nativo: ci pensa la mod. vocale!
        if (origPlay) origPlay(idx, type);
    };

    /* AGGIUNTA: Alza lo scudo acustico PRIMA che Piper inizi a caricare l'audio */
    var origSpeakText = window.speakText;
    window.speakText = function(rawText, onEndCallback, resumeFromIndex) {
        if (VG && VG.active && typeof ttsSettings !== 'undefined' && window.PiperTTS && window.PiperTTS.isPiperVoice(ttsSettings.nativeVoice)) {
            acquireEchoCancelledStream();
        }
        if (origSpeakText) {
            origSpeakText(rawText, onEndCallback, resumeFromIndex);
        }
    };

    /* ---------- API pubblica ---------- */
    window.VoiceGameMode = {
        handleHeaderButton: function () { openIntro(VG.active); },
        isActive: function () { return VG.active; }
    };
})();
</script>