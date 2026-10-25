<!-- ================================================================
     GIOCA CON AUDIO — modalità di gioco vocale "mani libere"
     ----------------------------------------------------------------
     Il sito legge ad alta voce domande/risposte e resta sempre in
     ascolto per riconoscere i comandi parlati dall'utente (ripeti,
     pausa, riprendi, risposta, leggi/spiega/metafora, corretto/errato,
     frantuma, chiudi). Dopo un voto la domanda successiva parte da sola.

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
            <?php if (!fc_is_vocab()): /* le parole non hanno l'IA nel gioco */ ?>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-purple"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2 5.7 5.7 2-5.7 2-2 5.7-2-5.7-5.7-2 5.7-2 2-5.7zM19 14l1.1 3 3 1.1-3 1.1-1.1 3-1.1-3-3-1.1 3-1.1 1.1-3z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_leggi'); ?>», «<?php te('fc.lbl_spiega'); ?>», «<?php te('fc.lbl_metafora'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_ai'); ?></span></span>
            </div>
            <?php endif; ?>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_corretto'); ?>» / «<?php te('fc.vg_giusto'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_corretto'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_errato'); ?>» / «<?php te('fc.vg_sbagliato'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_errato'); ?></span></span>
            </div>

            <?php /* «Successiva» non c'e' piu': dopo «corretto» o «sbagliato» la
                     domanda dopo parte da sola. Era un passaggio in piu' a
                     ogni carta, e a voce ogni passaggio e' una frase da dire
                     e da far capire al microfono. */ ?>
            <p class="vg-auto-note"><?php te('fc.vg_auto_avanti'); ?></p>
            <?php if (fc_is_vocab()): ?>
            <p class="vg-auto-note vg-auto-parole"><?php te('fc.vg_parole_nota'); ?></p>
            <?php endif; ?>

            <?php if (!fc_is_vocab()): ?>
            <div class="vg-group-label"><?php te('fc.vg_frantuma_titolo'); ?></div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-purple"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 3 14h7l-1 8 11-14h-7z"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_frantuma'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_frantuma'); ?></span></span>
            </div>
            <div class="vg-cmd-row">
                <span class="vg-cmd-badge vg-tint-gray"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg></span>
                <span class="vg-cmd-txt"><span class="vg-cmd-word">«<?php te('fc.lbl_chiudi'); ?>»</span><span class="vg-cmd-desc"><?php te('fc.vg_d_chiudi'); ?></span></span>
            </div>
            <?php endif; ?>
        </div>

        <?php /* LA LINGUA DEI COMANDI. Il microfono trascrive in UNA lingua
                 alla volta, ed e' quella a decidere quanto bene capisce: con
                 l'interfaccia in inglese era fissa a en-US, e un accento
                 italiano su "wrong" o "answer" diventava "Ron" o "and sir".
                 Si sceglie qui, si ricorda su questo dispositivo, e i
                 comandi dell'altra lingua valgono comunque. */ ?>
        <div class="vg-lang">
            <span class="vg-lang-lab"><?php te('fc.vg_lingua_comandi'); ?></span>
            <div class="vg-lang-seg" role="radiogroup" aria-label="<?php te('fc.vg_lingua_comandi'); ?>">
                <?php foreach (['it-IT' => 'Italiano', 'en-US' => 'English (US)', 'en-GB' => 'English (UK)'] as $vg_cod => $vg_nome): ?>
                <button type="button" role="radio" aria-checked="false" data-lang="<?php echo $vg_cod; ?>"><?php echo $vg_nome; ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <?php /* COSA LEGGE (solo parole). Quattro scelte, salvate sul profilo
                 (fc_settings.voice_read) cosi' le ritrova su ogni dispositivo.
                 La domanda si legge appena compare la carta; risposta,
                 significato ed esempi dopo che la risposta e' stata detta
                 giusta (o chiesta): leggerli prima la regalerebbe. */
        if (fc_is_vocab()):
            if (!function_exists('fc_impostazioni')) require_once __DIR__ . '/../../lib/fc/fc_impostazioni.php';
            $vg_lettura = fc_impostazioni((int)$current_user_id)['lettura'];
            $vg_righe = [
                'q' => ['fc.vg_legge_domanda',     [['0', 'fc.vg_no'], ['1', 'fc.vg_si']]],
                'a' => ['fc.vg_legge_risposta',    [['1', 'fc.vg_una_volta'], ['2', 'fc.vg_due_volte']]],
                'm' => ['fc.vg_legge_significato', [['0', 'fc.vg_no'], ['1', 'fc.vg_si']]],
                'e' => ['fc.vg_legge_esempi',      [['0', 'fc.vg_nessuno'], ['1', 'fc.vg_uno'], ['2', 'fc.vg_tutti']]],
            ];
            $vg_pos = array_flip(['q', 'a', 'm', 'e']);
        ?>
        <div class="vg-lang vg-lettura" id="vg-lettura" data-valore="<?php echo sec_h($vg_lettura); ?>">
            <span class="vg-lang-lab"><?php te('fc.vg_legge_titolo'); ?></span>
            <?php foreach ($vg_righe as $vg_k => [$vg_etich, $vg_opz]): ?>
            <div class="vg-lettura-riga" data-k="<?php echo $vg_k; ?>">
                <span class="vg-lettura-nome"><?php te($vg_etich); ?></span>
                <div class="vg-lang-seg vg-seg-n<?php echo count($vg_opz); ?>" role="radiogroup" aria-label="<?php te($vg_etich); ?>">
                    <?php foreach ($vg_opz as [$vg_v, $vg_t]): ?>
                    <button type="button" role="radio" aria-checked="<?php echo $vg_lettura[$vg_pos[$vg_k]] === $vg_v ? 'true' : 'false'; ?>" data-v="<?php echo $vg_v; ?>" class="<?php echo $vg_lettura[$vg_pos[$vg_k]] === $vg_v ? 'is-on' : ''; ?>"><?php te($vg_t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="vg-lettura-nota"><?php te('fc.vg_legge_nota'); ?></p>
        </div>
        <?php endif; ?>

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
    <button id="vg-orb" type="button" aria-label="<?php te('fc.vg_attiva'); ?>">
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

<?php
/* I comandi di TUTTE le lingue, non solo di quella del sito: chi ha il sito in
   inglese ma pensa in italiano dice «corretto» senza accorgersene, e chi
   studia inglese con il sito in italiano spesso risponde «correct». Le
   etichette sullo schermo restano quelle della lingua del sito; qui ci sono
   solo le frasi che il microfono confronta. */
$vg_intenti = ['ripeti', 'pausa', 'riprendi', 'risposta', 'leggi', 'spiega', 'metafora', 'corretto', 'errato', 'frantuma', 'chiudi'];
$vg_comandi = [];
foreach (['it', 'en'] as $vg_l) {
    $vg_diz = standout_dizionario($vg_l);
    foreach ($vg_intenti as $vg_i) {
        $vg_comandi[$vg_l][$vg_i] = (string)(standout_cerca_chiave($vg_diz, 'fc.cmd_' . $vg_i) ?? '');
    }
    $vg_comandi[$vg_l]['_fillers'] = (string)(standout_cerca_chiave($vg_diz, 'fc.vg_fillers') ?? '');
}
?>
<script type="application/json" id="vg-comandi"><?php echo json_encode(['lingua_sito' => standout_lingua(), 'comandi' => $vg_comandi, 'vocab' => fc_is_vocab(), 'lettura' => (fc_is_vocab() && isset($vg_lettura)) ? $vg_lettura : '1100'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>

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
        phase: 'idle',           // idle | question | answer | grading | ai-audio |
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
        try { seqTok++; } catch (e) {}   // ferma anche le letture a catena delle parole
        try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
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
    var VG_DATI = {};
    try { VG_DATI = JSON.parse(document.getElementById('vg-comandi').textContent) || {}; } catch (e) { VG_DATI = {}; }
    function elencoVirgole(s) {
        return String(s || '').split(',').map(function (x) { return x.trim().toLowerCase(); }).filter(Boolean);
    }
    /* Le lingue dei comandi: prima quella del sito, poi le altre. */
    var LINGUE_CMD = Object.keys(VG_DATI.comandi || {}).sort(function (a, b) {
        return (a === VG_DATI.lingua_sito ? -1 : 0) - (b === VG_DATI.lingua_sito ? -1 : 0);
    });
    var FILLERS = [];
    LINGUE_CMD.forEach(function (l) { FILLERS = FILLERS.concat(elencoVirgole(VG_DATI.comandi[l]._fillers)); });
    if (!FILLERS.length) FILLERS = elencoVirgole(T('fc.vg_fillers'));
    /* Le frasi piu' lunghe per prime: "thanks a lot" va tolto prima di "thanks". */
    FILLERS = FILLERS.map(function (f) { return normalize(f); }).filter(Boolean);
    FILLERS.sort(function (a, b) { return b.length - a.length; });
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
    /* Nelle parole non ci sono l'IA ne' "frantuma": comandi che non possono
       fare niente sono solo occasioni per capire male una frase. */
    var INTENTI = VG_DATI.vocab
        ? ['ripeti', 'pausa', 'riprendi', 'risposta', 'corretto', 'errato']
        : ['ripeti', 'pausa', 'riprendi', 'risposta', 'leggi', 'spiega', 'metafora', 'corretto', 'errato', 'frantuma', 'chiudi'];
    INTENTI.forEach(function (intento) {
        var frasi = [];
        LINGUE_CMD.forEach(function (l) { frasi = frasi.concat(elencoVirgole((VG_DATI.comandi[l] || {})[intento])); });
        if (!frasi.length) frasi = elencoVirgole(T('fc.cmd_' + intento));
        COMMANDS[intento] = frasi.filter(function (f, i) { return frasi.indexOf(f) === i; });
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
        /* .anteprima-card: la fotografia della carta successiva che sale
           durante il voto. Non e' una carta del gioco e non ha comandi. */
        return c.querySelector('.card-box:not(.graded):not(.anteprima-card)');
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
       3-bis. LE PAROLE: SI RISPONDE A VOCE, SI LEGGE COME SI E' SCELTO
       Nelle parole non si dice «corretto»: si dice la risposta. Finche' il
       microfono non la sente giusta non succede niente (le parole sbagliate
       si ignorano, niente voto d'ufficio). Quando la sente: si mostra la
       carta, si legge quello che si e' scelto (risposta, significato,
       esempi) e passati 3 secondi — e comunque dopo aver finito di leggere —
       si segna corretta e parte la domanda dopo. «Risposta» e «sbagliato»
       restano per chi non la sa.
       La voce e' fcSpeak (fc_speak.js): sa leggere la risposta due volte, la
       seconda piano, e sceglie la voce giusta per ogni lingua.
       ============================================================ */
    var VOC = !!VG_DATI.vocab;
    var PAUSA_AUTO_MS = 3000;
    var seqTok = 0;              // cambia a ogni lettura nuova: le vecchie si fermano
    var autoVotoTimer = null;    // c'e' un «corretto» in arrivo (fase 'auto-ok')

    function leggiLettura(s) {
        s = String(s || '');
        if (!/^[01][12][01][012]$/.test(s)) s = '1100';
        return { q: +s.charAt(0), a: +s.charAt(1), m: +s.charAt(2), e: +s.charAt(3) };
    }
    var LETTURA = leggiLettura(VG_DATI.lettura);

    function voceLato(lato) {
        var f = window.FC || {};
        var o = lato === 'learn' ? f.impara : f.conosce;
        return (o && o.voce) || (lato === 'learn' ? 'en-GB' : 'it-IT');
    }
    function latoDi(el) { return el && el.getAttribute('data-lato') === 'learn' ? 'learn' : 'known'; }

    /* Legge una lista [{testo, lingua, volte}] una voce dopo l'altra. Il
       microfono resta acceso: tutto cio' che si legge e' segnato come "eco"
       per non scambiarlo per la voce dell'utente. */
    function leggiSequenza(voci, fine) {
        var mio = ++seqTok;
        voci = (voci || []).filter(function (v) { return v && String(v.testo || '').trim(); });
        if (!voci.length || typeof window.fcSpeak !== 'function') { if (fine) setTimeout(fine, 0); return; }
        try { window.speechSynthesis.cancel(); } catch (e) {}
        segnaLettura(voci.map(function (v) { return v.testo; }).join(' '));
        var i = 0;
        (function prossima() {
            if (mio !== seqTok) return;
            if (i >= voci.length) { finitaLettura(); if (fine) fine(); return; }
            var v = voci[i++];
            window.fcSpeak(null, v.testo, v.lingua, v.volte !== 2, function () { prossima(); });
        })();
    }

    function testoDi(sel) {
        var el = document.querySelector(sel);
        if (!el) return null;
        var raw = el.getAttribute('data-raw');
        return { testo: (raw !== null ? raw : el.textContent || '').trim(), lato: latoDi(el) };
    }

    /* Dopo la risposta: risposta (1 o 2 volte), significato, esempi. */
    function vociDopoRisposta(card, idx) {
        var voci = [];
        var r = testoDi('#ans-section-' + idx + ' .raw-text') || testoDi('#graded-ans-' + idx + ' .raw-text');
        if (r && r.testo) voci.push({ testo: r.testo, lingua: voceLato(r.lato), volte: LETTURA.a });
        if (LETTURA.m) {
            var sig = card.querySelector('.fcv-hint-significato .fcv-hint-txt');
            if (sig && sig.textContent.trim()) voci.push({ testo: sig.textContent.trim(), lingua: voceLato('learn'), volte: 1 });
        }
        if (LETTURA.e) {
            var frasi = card.querySelectorAll('.fcv-esempio-voce');
            for (var k = 0; k < frasi.length && (LETTURA.e === 2 || k < 1); k++) {
                var t = (frasi[k].getAttribute('data-text') || '').trim();
                if (t) voci.push({ testo: t, lingua: voceLato('learn'), volte: 1 });
            }
        }
        return voci;
    }

    function leggiDomandaParola(card, idx, forza) {
        VG.phase = 'question';
        aggiornaAscoltoParola(card, idx);
        var q = testoDi('#q-section-' + idx + ' .raw-text');
        if ((!LETTURA.q && !forza) || !q || !q.testo) { setOrbState('listening'); return; }
        setOrbState('speaking');
        leggiSequenza([{ testo: q.testo, lingua: voceLato(q.lato), volte: 1 }], function () {
            if (VG.active && VG.phase === 'question') setOrbState('listening');
        });
    }

    function leggiRispostaParola(card, idx) {
        setOrbState('speaking');
        leggiSequenza(vociDopoRisposta(card, idx), function () {
            if (VG.active && VG.phase === 'answer') setOrbState('listening');
        });
    }

    /* ----- la risposta giusta, a voce ----- */
    /* Come normalize() ma senza buttare le lettere non latine (giapponese,
       russo, accentate...): per confrontare risposte in qualunque lingua. */
    function normRisp(s) {
        return String(s || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim();
    }
    function variantiRisposta(raw) {
        var out = [];
        String(raw || '').replace(/\([^)]*\)/g, ' ').split(/\s*[\/;|]\s*|\s*,\s*|\s+or\s+/i).forEach(function (pezzo) {
            var n = normRisp(pezzo);
            if (!n) return;
            out.push(n);
            /* l'articolo o il "to" davanti non lo dice nessuno: "to give up" -> "give up" */
            var senza = n.replace(/^(to|the|a|an|il|lo|la|i|gli|le|un|uno|una)\s+/, '');
            if (senza && senza !== n) out.push(senza);
        });
        return out.filter(function (v, i) { return v.length >= 2 && out.indexOf(v) === i; });
    }

    var risposteAttese = [];
    function leggiRispostaAttesa(card, idx) {
        var r = testoDi('#ans-section-' + idx + ' .raw-text');
        risposteAttese = r ? variantiRisposta(r.testo) : [];
        return r;
    }

    /* La frase detta contiene la risposta? Parole corte solo esatte (una
       lettera sbagliata cambia la parola), le altre con un po' di tolleranza:
       il riconoscitore scrive "gif up" per "give up". Si guardano anche
       finestre di parole dentro una frase piu' lunga ("uhm give up now"). */
    function eRispostaGiusta(trascritto) {
        var t = normRisp(trascritto);
        if (!t || !risposteAttese.length) return false;
        var parole = t.split(' ');
        for (var k = 0; k < risposteAttese.length; k++) {
            var v = risposteAttese[k];
            var n = v.split(' ').length;
            var soglia = v.length <= 4 ? 1 : 0.84;
            for (var i = 0; i + n <= parole.length; i++) {
                if (similarity(parole.slice(i, i + n).join(' '), v) >= soglia) return true;
            }
            if (n === 1 && v.length > 4 && similarity(t, v) >= soglia) return true;
        }
        return false;
    }

    function annullaAutoVoto() {
        if (autoVotoTimer) { clearTimeout(autoVotoTimer.t); autoVotoTimer = null; }
    }

    function rispostaDettaGiusta() {
        var card = currentCardBox();
        if (!card || VG.phase !== 'question' || autoVotoTimer) return;
        var idx = cardIndex(card);
        VG.phase = 'auto-ok';
        stopTuttoIlParlato();
        var ans = testoDi('#ans-section-' + idx + ' .raw-text');
        showCaption('✓ ' + (ans ? ans.testo : ''), 'recognized');
        try { revealCard(idx); } catch (e) {}
        setOrbState('speaking');
        var stato = { letto: false, tempo: false, t: null };
        autoVotoTimer = stato;
        function prova() {
            if (autoVotoTimer !== stato || !stato.letto || !stato.tempo) return;
            autoVotoTimer = null;
            markCard('correct');
        }
        stato.t = setTimeout(function () { stato.tempo = true; prova(); }, PAUSA_AUTO_MS);
        leggiSequenza(vociDopoRisposta(card, idx), function () { stato.letto = true; setOrbState('listening'); prova(); });
    }

    /* Il microfono ascolta nella lingua della RISPOSTA (e' li' che la
       trascrizione deve essere precisa), e gli si suggerisce la parola
       attesa: lo stesso trucco dei comandi, con un peso piu' alto. */
    function aggiornaAscoltoParola(card, idx) {
        var r = leggiRispostaAttesa(card, idx);
        if (!recognition || !r) return;
        var voce = voceLato(r.lato);
        if (recognition.lang !== voce) {
            recognition.lang = voce;
            if (isRecognizing) { try { recognition.abort(); } catch (e) {} }
        }
        applicaFrasiAttese();
    }

    /* Una trascrizione (provvisoria o finale, con le sue alternative): se e'
       la risposta giusta, la carta va avanti da sola. */
    function provaRispostaParola(res) {
        if (!VOC || VG.phase !== 'question' || autoVotoTimer) return false;
        for (var a = 0; a < res.length; a++) {
            if (eEco(res[a].transcript)) continue;
            if (eRispostaGiusta(res[a].transcript)) { rispostaDettaGiusta(); return true; }
        }
        return false;
    }

    /* ============================================================
       4. FLUSSO PRINCIPALE: DOMANDA / RISPOSTA / VALUTAZIONE
       ============================================================ */
    function readQuestion(forza) {
        var card = currentCardBox();
        if (!card) { setOrbState('listening'); return; }
        var idx = cardIndex(card);
        if (VOC) { leggiDomandaParola(card, idx, forza === true); return; }
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
        if (VOC) { leggiRispostaParola(card, idx); return; }
        setOrbState('speaking');
        var raw = rawTextOf('#ans-section-' + idx + ' .raw-text');
        var audioEl = document.getElementById('player_a_' + idx);
        speakText(raw, function () {
            if (audioEl) { try { audioEl.currentTime = 0; audioEl.play(); } catch (e) {} }
            if (VG.active && VG.phase === 'answer') setOrbState('listening');
        });
    }

    /* Il tasto del voto della carta. ERA QUI IL GUASTO PRINCIPALE: si
       cercavano a.btn-green / a.btn-red, i tasti di prima del ridisegno della
       riga dei voti, che ora sono a.gc-correct / a.gc-wrong. Il comando veniva
       riconosciuto (la didascalia lo mostrava) ma il tasto non si trovava mai,
       e la funzione usciva in silenzio. Si cercano entrambi, cosi' un prossimo
       ritocco della grafica non lo rompe di nuovo senza farsi notare. */
    function linkVoto(card, result) {
        var sel = result === 'correct' ? 'a.gc-correct, a.btn-green' : 'a.gc-wrong, a.btn-red';
        var idx = cardIndex(card);
        var box = document.getElementById('ans-section-' + idx);
        return (box && box.querySelector(sel)) || card.querySelector(sel);
    }

    function markCard(result) {
        var card = currentCardBox();
        if (!card) return;
        var link = linkVoto(card, result);
        if (!link) return;

        annullaAutoVoto();
        stopTuttoIlParlato();

        // BLOCCA IMMEDIATAMENTE IL CARICAMENTO E L'AUDIO DELL'AI
        if (typeof window.cancelAiAudio === 'function') {
            window.cancelAiAudio();
        } else if (typeof currentAiAudio !== 'undefined' && currentAiAudio) {
            currentAiAudio.pause();
        }

        /* Si puo' votare anche senza aver chiesto la risposta: chi sa di
           averla detta giusta non deve dire «risposta» e aspettare che venga
           letta per poi dire «corretto». La risposta si apre comunque, cosi'
           la si vede passare mentre la carta se ne va. */
        if (!card.classList.contains('risposta-mostrata')) {
            try { revealCard(cardIndex(card)); } catch (e) {}
        }

        VG.phase = 'grading';
        setOrbState('waiting');
        link.click();
    }

    /* DOPO UN VOTO: si legge da soli la domanda successiva (prima serviva
       dire «successiva»). Le strade sono due:
         - la posizione cambia: arriva un contenitore nuovo, e ci pensa
           onNewPage;
         - nella stessa pagina c'e' un'altra carta da fare (una carta con le
           sue sottocarte): la pagina non cambia, la carta votata diventa
           .graded e si scende alla successiva. Questo caso prima non aveva
           nessuno che lo guardasse, e la voce restava muta.
       Si guarda la pagina a intervalli brevi invece di indovinare quanto
       duri l'animazione del voto. */
    var dopoVotoTimer = null;
    function fermaAttesaVoto() {
        if (dopoVotoTimer) { clearInterval(dopoVotoTimer); dopoVotoTimer = null; }
    }
    function attendiDopoVoto(cardVotata) {
        fermaAttesaVoto();
        VG.phase = 'grading';
        setOrbState('waiting');
        var cont = lastContainer();
        var inizio = Date.now();
        dopoVotoTimer = setInterval(function () {
            if (!VG.active) { fermaAttesaVoto(); return; }
            if (lastContainer() !== cont) { fermaAttesaVoto(); return; }   // onNewPage
            var votata = !cardVotata || !cardVotata.isConnected || cardVotata.classList.contains('graded');
            if (votata) {
                if (currentCardBox()) {
                    fermaAttesaVoto();
                    VG.phase = 'idle';
                    setTimeout(readQuestion, 250);
                    return;
                }
                /* Voto rimasto in coda senza rete: la pagina non cambia ma
                   compare "Avanti". Si va avanti come se l'avesse toccato. */
                var avanti = cont ? cont.querySelector('.nav-fwd') : null;
                if (avanti) { fermaAttesaVoto(); avanti.click(); return; }
            }
            if (Date.now() - inizio > 20000) {
                fermaAttesaVoto();
                VG.phase = currentCardBox() ? 'question' : 'idle';
                setOrbState('listening');
            }
        }, 200);
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
            if (!(VG.active && VG.phase === 'shatter-a' && VG.shatterIndex === i)) return;
            setOrbState('listening');
            /* Niente «successiva» anche qui: letta la risposta, dopo un
               respiro si passa da soli alla mini-domanda dopo. In quel respiro
               si puo' ancora dire «ripeti» o «sbagliato». */
            setTimeout(function () {
                if (VG.active && VG.phase === 'shatter-a' && VG.shatterIndex === i) advanceShatter();
            }, 1600);
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
        VG.phase = 'grading';
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
                if (VOC) { leggiDomandaParola(card, idx, true); break; }
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
                if (VOC) { leggiRispostaParola(card, idx); break; }
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
                readShatterAnswer();   // rilegge, e poi va avanti da sola come la prima volta
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
        if (VOC) { try { window.speechSynthesis.pause(); } catch (e) {} setOrbState('paused'); return; }
        if (VG.phase === 'ai-audio') {
            if (typeof currentAiAudio !== 'undefined' && currentAiAudio && !currentAiAudio.paused) currentAiAudio.pause();
        } else if (window.currentUtterance !== null) {
            pauseNativeTTS();
        }
        setOrbState('paused');
    }

    function doRiprendi() {
        if (VOC) { try { window.speechSynthesis.resume(); } catch (e) {} setOrbState('speaking'); return; }
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
        if (VG.phase === 'question' || VG.phase === 'answer' || VG.phase === 'ai-audio' || VG.phase === 'auto-ok') markCard('correct');
        else if (VG.phase === 'shatter-finished') judgeShatter('correct');
        // "corretto" solo dall'ultima mini-card: per essere sicuri di aver
        // saputo TUTTA la carta serve averle riviste tutte.
        else if (VG.phase === 'shatter-a' && VG.shatterIndex === VG.shatterCount - 1) {
            VG.phase = 'shatter-finished';
            judgeShatter('correct');
        }
    }

    function doErrato() {
        if (VG.phase === 'question' || VG.phase === 'answer' || VG.phase === 'ai-audio' || VG.phase === 'auto-ok') markCard('wrong');
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
        frantuma: startFrantuma,
        chiudi: closeFrantuma
    };

    /* ============================================================
       8-bis. FASE, ECO, LINGUA
       ============================================================ */

    /* La fase la tiene questo file, ma la carta si puo' toccare anche con le
       dita mentre la voce e' accesa: chi apre la risposta con un tocco e poi
       dice «corretto» era ancora in fase 'question'. Prima di ogni comando la
       fase si riallinea a quello che c'e' davvero sullo schermo. */
    function sincronizzaFase() {
        if (VG.phase === 'grading' || VG.phase === 'ai-audio' || VG.phase === 'auto-ok' || VG.phase.indexOf('shatter') === 0) return;
        var card = currentCardBox();
        if (!card) return;
        if (card.classList.contains('risposta-mostrata')) {
            if (VG.phase === 'question' || VG.phase === 'idle') VG.phase = 'answer';
        } else if (VG.phase === 'idle' || VG.phase === 'answer') {
            VG.phase = 'question';
        }
    }

    /* ECO: il microfono sente anche la voce che legge la carta. Se quello
       che ha trascritto e' un pezzo del testo che si sta leggendo (o appena
       letto), non e' l'utente che parla: si ignora. Senza questo filtro, una
       carta che contiene "right" o "giusto" si votava da sola. */
    var letturaTesto = '';
    var letturaFinoA = 0;      // 0 = sta leggendo adesso
    function segnaLettura(testo) {
        letturaTesto = normalize(String(testo || '').replace(/<[^>]+>/g, ' ').replace(/[$\\{}_^]/g, ' '));
        letturaFinoA = 0;
    }
    function finitaLettura() { letturaFinoA = Date.now() + 1500; }
    function eEco(trascritto) {
        if (!letturaTesto) return false;
        if (letturaFinoA && Date.now() > letturaFinoA) return false;
        var t = normalize(trascritto);
        return t.length > 0 && (' ' + letturaTesto + ' ').indexOf(' ' + t + ' ') !== -1;
    }

    var LINGUE_RICONOSCIMENTO = ['it-IT', 'en-US', 'en-GB'];
    function linguaComandi() {
        try {
            var v = localStorage.getItem('vg_lingua_comandi');
            if (LINGUE_RICONOSCIMENTO.indexOf(v) !== -1) return v;
        } catch (e) {}
        var sito = T('fc.voice_lang');
        return LINGUE_RICONOSCIMENTO.indexOf(sito) !== -1 ? sito : 'it-IT';
    }

    /* Dove il browser lo permette (Chrome recente), si dice al riconoscitore
       quali parole aspettarsi: e' lo stesso principio dei comandi di un
       assistente vocale, che capisce "stop" anche detto male perche' sa che
       e' una delle poche cose che puoi dire. Dove non c'e', non succede
       niente. Solo le frasi corte e solo della lingua scelta. */
    function applicaFrasiAttese() {
        if (!recognition) return;
        try {
            if (typeof window.SpeechRecognitionPhrase !== 'function' || !('phrases' in recognition)) return;
            var l = recognition.lang.slice(0, 2);
            var cmd = (VG_DATI.comandi || {})[l] || {};
            var lista = [];
            INTENTI.forEach(function (i) {
                elencoVirgole(cmd[i]).forEach(function (f) {
                    if (f.split(' ').length <= 3 && lista.length < 80) lista.push(new window.SpeechRecognitionPhrase(f, 4.0));
                });
            });
            /* nelle parole anche la risposta attesa, con il peso piu' alto */
            if (VOC) risposteAttese.forEach(function (v) { if (v.split(' ').length <= 4 && lista.length < 90) lista.push(new window.SpeechRecognitionPhrase(v, 6.0)); });
            recognition.phrases = lista;
        } catch (e) {}
    }

    function impostaLinguaComandi(lang) {
        try { localStorage.setItem('vg_lingua_comandi', lang); } catch (e) {}
        segnaLinguaComandi();
        if (!recognition) return;
        recognition.lang = lang;
        applicaFrasiAttese();
        /* La lingua vale dalla sessione successiva: si chiude questa, e onend
           la riapre con quella nuova. */
        if (isRecognizing) { try { recognition.abort(); } catch (e) {} }
    }

    function segnaLinguaComandi() {
        var attuale = linguaComandi();
        document.querySelectorAll('.vg-lang-seg [data-lang]').forEach(function (b) {
            var on = b.getAttribute('data-lang') === attuale;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    }

    /* Un comando non deve partire due volte: puo' arrivare prima mentre si
       parla (risultato provvisorio) e poi alla fine (risultato definitivo). */
    var ultimoComando = { intento: '', t: 0 };
    var risultatiGiaUsati = {};
    function eseguiComando(best) {
        if (ultimoComando.intento === best.intent && Date.now() - ultimoComando.t < 1800) return;
        ultimoComando = { intento: best.intent, t: Date.now() };
        sincronizzaFase();
        showCaption(INTENT_LABELS[best.intent] || best.intent, 'recognized');
        HANDLERS[best.intent]();
    }

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
        recognition.lang = linguaComandi();
        recognition.continuous = false; // Obbligatorio su iOS per non bloccare il demone
        recognition.interimResults = true;
        /* Piu' alternative = piu' occasioni di trovare il comando fra le
           trascrizioni meno probabili ("Ron" come prima, "wrong" come quinta). */
        recognition.maxAlternatives = 5;
        applicaFrasiAttese();

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
            risultatiGiaUsati = {};
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
                if (risultatiGiaUsati[i]) continue;
                if (eEco(res[0].transcript)) continue;
                /* Nelle parole la risposta detta giusta viene PRIMA dei
                   comandi: se la parola da indovinare fosse «password» (o
                   «answer») non deve essere scambiata per un comando. */
                if (VOC && provaRispostaParola(res)) { risultatiGiaUsati[i] = true; continue; }
                if (res.isFinal) {
                    var best = null;
                    for (var a = 0; a < res.length; a++) {
                        if (eEco(res[a].transcript)) continue;
                        var cand = matchIntentDetailed(res[a].transcript);
                        if (cand && (!best || cand.weighted > best.weighted)) best = cand;
                    }
                    if (best && HANDLERS[best.intent] && (!piperStaParlando || best.weighted >= 0.8)) {
                        risultatiGiaUsati[i] = true;
                        eseguiComando(best);
                    } else if (!piperStaParlando) {
                        showCaption(res[0].transcript, 'interim');
                    }
                } else {
                    /* Risultato provvisorio: se e' gia' un comando netto e
                       corto («wrong», «risposta») si esegue subito, senza
                       aspettare la pausa che chiude la frase. E' quella pausa
                       a dare la sensazione di "lento". */
                    var provv = matchIntentDetailed(res[0].transcript);
                    var parole = normalize(res[0].transcript).split(' ').length;
                    if (provv && HANDLERS[provv.intent] && !piperStaParlando && provv.score >= 0.97 && parole <= 3) {
                        risultatiGiaUsati[i] = true;
                        eseguiComando(provv);
                        continue;
                    }
                    interim += res[0].transcript;
                }
            }
            if (interim && !piperStaParlando) showCaption(interim, 'interim');
        };

        recognition.onerror = function (e) {
            /* Le frasi attese non sono accettate da questo riconoscitore: si
               tolgono e si continua senza (onend riavvia). */
            if (e.error === 'phrases-not-supported') {
                try { recognition.phrases = []; } catch (er) {}
                return;
            }
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
        fermaAttesaVoto();
        annullaAutoVoto();
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

    /* Pagina nuova: si legge subito la domanda, che ci si arrivi da un voto o
       da "Indietro"/"Avanti". */
    function onNewPage() {
        if (!VG.active) return;
        fermaAttesaVoto();
        annullaAutoVoto();
        stopTuttoIlParlato();
        VG.pendingGrade = false;
        VG.phase = 'idle';
        setTimeout(readQuestion, 350);
    }

    /* Un voto, da qualunque parte arrivi (anche il tocco o lo swipe mentre
       la modalita' vocale e' accesa): fc_game.php lo annuncia con 'fc-voto'. */
    document.addEventListener('fc-voto', function (e) {
        if (!VG.active) return;
        stopTuttoIlParlato();
        attendiDopoVoto(e.detail ? e.detail.card : null);
    });

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

        /* Cosa leggere (solo nelle parole): ogni tocco cambia una cifra, si
           salva subito sul server e vale dal prossimo giro, partita in corso
           compresa. */
        var pannelloLettura = document.getElementById('vg-lettura');
        if (pannelloLettura) {
            var TOKEN_LETTURA = <?php echo json_encode(sec_csrf_token()); ?>;
            var POS_LETTURA = { q: 0, a: 1, m: 2, e: 3 };
            pannelloLettura.addEventListener('click', function (ev) {
                var bt = ev.target.closest ? ev.target.closest('button[data-v]') : null;
                var riga = bt && bt.closest('.vg-lettura-riga');
                if (!riga || !(riga.getAttribute('data-k') in POS_LETTURA)) return;
                var cifre = leggiLettura(pannelloLettura.getAttribute('data-valore')), v = [cifre.q, cifre.a, cifre.m, cifre.e];
                v[POS_LETTURA[riga.getAttribute('data-k')]] = bt.getAttribute('data-v');
                var nuovo = v.join('');
                pannelloLettura.setAttribute('data-valore', nuovo);
                LETTURA = leggiLettura(nuovo);
                riga.querySelectorAll('button[data-v]').forEach(function (x) {
                    var on = x === bt;
                    x.classList.toggle('is-on', on);
                    x.setAttribute('aria-checked', on ? 'true' : 'false');
                });
                var fd = new FormData();
                fd.append('lettura', nuovo);
                fd.append('csrf_token', TOKEN_LETTURA);
                fetch('fc_settings_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': TOKEN_LETTURA } }).catch(function () {});
            });
        }

        if (elStartBtn) elStartBtn.addEventListener('click', onStartClick);
        if (elCancelBtn) elCancelBtn.addEventListener('click', closeIntro);
        // Su Apple passiamo dalla domanda "lo riuserai a breve?"; su tutti
        // gli altri browser il pulsante spegne subito, come sempre.
        if (elExitBtn) elExitBtn.addEventListener('click', handleExitClick);
        if (elAskYesBtn) elAskYesBtn.addEventListener('click', function () { closeAskOverlay(); deactivate(); });
        if (elAskNoBtn) elAskNoBtn.addEventListener('click', function () { closeAskOverlay(); });
        if (elOrb) elOrb.addEventListener('click', function () { openIntro(true); });
        if (elOverlay) elOverlay.addEventListener('click', function (e) { if (e.target === elOverlay) closeIntro(); });
        document.querySelectorAll('.vg-lang-seg [data-lang]').forEach(function (b) {
            b.addEventListener('click', function () { impostaLinguaComandi(b.getAttribute('data-lang')); });
        });
        segnaLinguaComandi();
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
        var fine = onEndCallback;
        if (VG && VG.active) {
            segnaLettura(rawText);
            fine = function () { finitaLettura(); if (onEndCallback) onEndCallback.apply(this, arguments); };
        }
        if (origSpeakText) {
            origSpeakText(rawText, fine, resumeFromIndex);
        }
    };

    /* ---------- API pubblica ---------- */
    window.VoiceGameMode = {
        handleHeaderButton: function () { openIntro(VG.active); },
        isActive: function () { return VG.active; }
    };
})();
</script>