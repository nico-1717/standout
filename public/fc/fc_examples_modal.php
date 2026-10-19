<?php
/* Per le lingue l'esempio e' una frase semplice (nessun editor ricco, niente
   fc_parser), senza vocale registrata (le parole si ascoltano con la sintesi)
   e per ora senza IA: vedi STANDOUT_PROGETTO.md, "rimandato". */
$exmVocab = function_exists('fc_is_vocab') && fc_is_vocab();
/* Il componente puo' comparire piu' volte nella stessa pagina: il foglio si
   carica una volta sola. Il <link> sta qui e non nel <head> perche' questo file
   e' incluso dentro il <body>; funziona, e per un componente e' il compromesso
   giusto rispetto a chiedere a ogni pagina di ricordarselo. */
if (!defined('EXM_CSS')) {
    define('EXM_CSS', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'fc/fc_examples_modal.css?v=' . (@filemtime(__DIR__ . '/fc_examples_modal.css') ?: 1) . '">' . "\n";
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'fc/fc_ai_fab.css?v='
       . (@filemtime(__DIR__ . '/fc_ai_fab.css') ?: 1) . '">' . "\n";
    /* I riquadri immagine/audio sono gli stessi del modulo della carta: le
       loro regole vivono in fc_media_box.css, non piu' dentro il foglio di
       una pagina sola. Serve anche qui perche' questo pannello finisce in
       fc_view.php, che fc_card_edit.css non lo carica. */
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'fc/fc_media_box.css?v=' . (@filemtime(__DIR__ . '/fc_media_box.css') ?: 1) . '">' . "\n";
}
if (!defined('NS_AUTOSAVE_CSS_INCLUDED')) {
    define('NS_AUTOSAVE_CSS_INCLUDED', true);
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'fc/fc_autosave_bar.css?v=' . (@filemtime(__DIR__ . '/fc_autosave_bar.css') ?: 1) . '">' . "\n";
}

/* Il livello condiviso (movimento, attesa, tendine). Stessa costante del
   pannello delle carte: se le due cose finiscono nella stessa pagina —
   succede in fc_insert.php — si carica una volta sola.
   Il tag <script> e' scritto nel markup e non aggiunto da JavaScript
   apposta: uno script inserito da codice non blocca DOMContentLoaded, e
   l'aggancio della tendina qui sotto gira proprio li'. */
if (!defined('STANDOUT_UI')) {
    define('STANDOUT_UI', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . '_assets/ui/standout_ui.css?v='
       . (@filemtime(__DIR__ . '/../_assets/ui/standout_ui.css') ?: 1) . '">' . "\n";
    echo '<script src="' . PWA_BASE . '_assets/ui/standout_ui.js?v='
       . (@filemtime(__DIR__ . '/../_assets/ui/standout_ui.js') ?: 1) . '"></script>' . "\n";
}
?>
<script>
/* Indirizzo di fc_examples_api.php con il tipo dentro (?course=N per le
   lingue): l'API deve sapere in quali tabelle cercare la carta. */
if (typeof window.fcExApi !== 'function') {
    window.fcExApi = function (q) {
        var base = 'fc_examples_api.php';
        if (window.FC && FC.qs) return base + '?' + FC.qs + (q ? '&' + q : '');
        return base + (q ? '?' + q : '');
    };
}
if (<?php echo $exmVocab ? 'false' : 'true'; ?> && !document.querySelector('script[src*="fc_parser.js"]')) {
    var __exmParserScript = document.createElement('script');
    __exmParserScript.src = '<?php echo PWA_BASE; ?>fc/fc_parser.js';
    document.head.appendChild(__exmParserScript);
}
/* Stesso schema per il motore audio: fc_card_edit.php e
   fc_insert_card.php lo caricano gia' per conto proprio (serve anche prima
   che questo componente entri in scena), qui si aggiunge solo per chi
   include il pannello esempi senza averlo gia'. */
if (!document.querySelector('script[src*="fc_audio_recorder.js"]')) {
    var __exmAudioScript = document.createElement('script');
    __exmAudioScript.src = '<?php echo PWA_BASE; ?>fc/fc_audio_recorder.js';
    document.head.appendChild(__exmAudioScript);
}
</script>


<!-- MODALE: LISTA ESEMPI -->

<div id="ex-list-modal" class="exm-overlay">

    <div class="exm-panel exm-panel-list">

        <button class="exm-close" onclick="chiudiElencoEsempi()" aria-label="<?php te('comune.chiudi'); ?>">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>

        <h3><?php te('fc.exm_titolo'); ?></h3>

        <p style="color: var(--text-2, #8A8A8E); font-size: 14px; margin-top:-10px; margin-bottom:16px;"><?php te('fc.exm_sottotitolo'); ?></p>

        <div id="ex-list-content" class="exm-list-content"></div>

        <button class="exm-fab" onclick="openExampleForm(currentFcId, currentFcType, 0)" aria-label="<?php te('fc.exm_aggiungi_nuovo'); ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        </button>

    </div>

</div>



<!-- MODALE: FORM EDITOR ESEMPIO -->

<div id="ex-form-modal" class="exm-overlay form">

    <div class="exm-panel">

        <div class="exm-form-topbar">

            <span class="exm-form-title" id="ex-form-title"><?php te('fc.exm_editor'); ?></span>

            <div class="exm-form-actions">

                <button id="ex-delete-btn" class="exm-icon-btn exm-icon-btn-danger" style="display:none;" onclick="deleteExample()" aria-label="<?php te('comune.elimina'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>
                </button>

                <?php $ns_instance = 'esempi'; include __DIR__ . '/fc_autosave_bar.php'; unset($ns_instance); ?>

            </div>

            <button class="exm-icon-btn exm-close-inline" onclick="closeExampleForm()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>

        </div>

        <label class="exm-label"><?php te('fc.exm_testo'); ?></label>

        <div style="margin-bottom: 16px; border: 1px solid var(--hairline-2, rgba(60,60,67,.25)); border-radius: 12px; overflow: hidden; background: var(--surface, #fff);">

            <?php if ($exmVocab): ?>
            <?php /* Un campo semplice: getExHiddenInput() lo trova per id
                     (ex_text) quando l'editor ricco non c'e'. */ ?>
            <textarea id="ex_text" class="exm-plain" rows="3" oninput="markExDirty()"
                      placeholder="<?php te('fcv.esempio_ph'); ?>"></textarea>
            <?php else: ?>
            <?php

                $ft_field = 'ex_text'; // ID del nuovo campo gestito dalla toolbar

                $ft_raw = ''; 

                $ft_placeholder = T('fc.exm_ph_testo');

                include __DIR__ . '/../partials/format_toolbar.php';

            ?>
            <?php endif; ?>

        </div>
        <?php if ($exmVocab): ?><p class="exm-hint"><?php te('fcv.esempio_hint'); ?></p><?php endif; ?>



        <?php /* IMMAGINE E AUDIO: GLI STESSI RIQUADRI DEL MODULO DELLA CARTA.
                 Non "simili": gli stessi. Stesse classi (.fc-media-affiancati,
                 .img-compare, .img-pick-box, .audio-card, .rec-btn), stesso
                 foglio di stile (fc_media_box.css), stesso ordine — immagine a
                 sinistra, audio a destra.

                 Prima qui c'era una coppia scritta a mano (.exm-img-box,
                 .exm-audio-card): un rettangolo azzurro col bordo pieno e un
                 pulsante "Scegli una foto dal dispositivo" da una parte, una
                 card grigia col microfono a sinistra del testo dall'altra.
                 Faceva le stesse identiche cose del modulo della carta con
                 una faccia diversa, e allegare una foto a un esempio o a una
                 carta e' la stessa azione: se il riquadro cambia forma
                 bisogna fermarsi a rileggerlo.

                 Le classi .exm-img-box / .exm-audio-card / .exm-rec-btn
                 restano in fc_examples_modal.css ma qui non le usa piu' nessuno. */ ?>
        <div class="fc-media-affiancati" id="ex-media-affiancati">

            <?php /* img-compare-solo: c'e' un riquadro solo, quello nuovo, e da
                     solo si prende tutta la colonna. L'anteprima dell'immagine
                     gia' salvata compare qui dentro, non in un secondo riquadro
                     "Attuale": un esempio ha una foto sola e la si sostituisce,
                     non la si confronta. */ ?>
            <div class="img-compare img-compare-solo" id="ex-img-compare">

                <?php /* IL RIQUADRO "ATTUALE", COME NEL MODULO DELLA CARTA
                         Prima l'immagine gia' salvata finiva DENTRO il riquadro
                         "Nuova": si vedeva la foto che c'era, ma il riquadro
                         diceva "Nuova" e non c'era modo di togliere quella foto
                         senza caricarne un'altra. Due cose diverse — quella che
                         c'e' e quella che si sta scegliendo — mostrate come una
                         sola.
                         Adesso sono due riquadri affiancati, esattamente come in
                         fc_card_edit.php: a sinistra quella che c'e',
                         con la sua casella per toglierla, a destra quella nuova.
                         Resta nascosto finche' un'immagine non c'e' davvero: su
                         un esempio nuovo mostrerebbe una cornice vuota. */ ?>
                <div class="img-box" id="ex-img-attuale" hidden>
                    <span class="img-tag"><?php te('vocab.mi_attuale'); ?></span>
                    <img id="ex-img-attuale-src" src="" alt="">
                    <label class="img-remove-row">
                        <input type="checkbox" id="ex-img-rimuovi" onchange="exSegnaRimozione(this)">
                        <span><?php te('vocab.mi_rimuovi_immagine'); ?></span>
                    </label>
                </div>

                <label for="ex-image-file" class="img-box img-pick-box" id="ex_img_wrap">
                    <input type="file" id="ex-image-file" accept="image/*" style="display:none;" onchange="previewExImage(this)">
                    <span class="img-tag img-tag-new"><?php te('vocab.mi_nuova'); ?></span>
                    <span class="img-pick-empty img-pick-empty-sm">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                        <span><?php te('vocab.mi_tocca_per_scegliere'); ?></span>
                    </span>
                    <?php /* Il contenitore resta, con il suo id: e' quello che il
                             JS accende e spegne (previewExImage, openExampleForm,
                             il ripristino). Cambiargli forma qui senza cambiarlo
                             li' avrebbe rotto l'anteprima in tre punti diversi. */ ?>
                    <span id="ex-img-preview-container" style="display:none;">
                        <img class="img-pick-preview" id="ex-img-preview" src="">
                    </span>
                </label>
            </div>

            <div class="audio-card"<?php echo $exmVocab ? ' hidden' : ''; ?>>
                <div class="audio-row">
                    <button type="button" class="rec-btn" id="ex-rec-btn" onclick="toggleExampleRecord()" aria-label="<?php te('fc.exm_vocale'); ?>">
                        <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                        <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>
                    </button>
                    <div class="audio-info">
                        <div class="audio-title"><?php te('fc.exm_vocale'); ?></div>
                        <div class="audio-sub" id="ex-rec-status"><?php te('fc.ifc_tocca_registrare'); ?></div>
                    </div>
                </div>
                <audio id="ex-audio-player" controls class="audio-player-row-new" style="display:none;"></audio>
            </div>

        </div><!-- .fc-media-affiancati -->

        <?php /* IL BLOCCO IA STA IN FONDO AL PANNELLO, NON PIU' IN CIMA.
                 Non e' un riordino estetico: e' quello che permette al cerchio
                 di restare in basso a destra.

                 Il pannello e' lui stesso il contenitore che scorre
                 (.exm-panel ha overflow-y:auto). Un elemento in
                 position:absolute li' dentro si ancora al riquadro INTERO, non
                 alla parte che si vede: quindi "bottom:16px" voleva dire in
                 fondo al contenuto: giusto all'apertura, quando il contenuto e'
                 corto quanto la finestra, sbagliato appena il modulo si allunga
                 e il fondo del contenuto se ne va sotto il bordo.

                 La soluzione e' position:sticky (in fc_examples_modal.css), che
                 pero' funziona in un verso solo: tiene un elemento dentro la
                 parte visibile spostandolo INDIETRO rispetto allo scorrimento,
                 mai in avanti. Un elemento in cima al contenuto, con
                 bottom:16px, e' gia' dentro il limite e non viene mosso di un
                 pixel — ecco perche' non bastava aggiungere la regola e
                 lasciarlo dov'era. Da qui in fondo, invece, la sua posizione
                 naturale e' sotto il bordo visibile, e sticky lo tira su fino a
                 16px dal fondo: esattamente dove deve stare, a ogni altezza del
                 modulo.

                 Fixed no: il pannello e' largo 640px al centro di uno schermo
                 che puo' esserne largo il triplo, e un pulsante ancorato alla
                 FINESTRA finirebbe a mezz'aria sul velo scuro, staccato dal
                 pannello a cui appartiene. */ ?>

        <div class="exm-ai-wrap" id="ex-ai-wrap"<?php echo $exmVocab ? ' hidden' : ''; ?>>

            <?php /* Stesso cerchio in sovraimpressione del modulo della carta
                     (fc_ai_fab.php): l'IA si chiede allo stesso modo ovunque, e
                     la pastiglia piccola qui dentro era l'ultima superstite del
                     disegno precedente. */
                   require_once __DIR__ . '/fc_ai_fab.php';
                   fc_ai_fab('ex-ai-fab', 'toggleAiBox(true)'); ?>

            <?php /* Popup vero, non piu' un riquadro che si espande in pagina: lo
                     stesso cambiamento gia' fatto per l'IA della carta (
                     fc_improve_card.php), qui applicato al suo equivalente
                     per gli esempi. */ ?>
            <div class="exm-ai-overlay" id="ex-ai-overlay" hidden>
                <div class="exm-ai-box" id="ex-ai-box">

                    <button type="button" class="exm-ai-collapse" onclick="toggleAiBox(false)" aria-label="<?php te('comune.chiudi'); ?>">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>

                    <div class="exm-ai-title"><?php te('fc.exm_genera_ia'); ?></div>

                    <?php /* Le stesse scelte del pannello delle carte, dallo stesso elenco
                             con cui il server le valida (fc_ai_stile_valori): qui solo
                             quelle che contano per un esempio. Tutto parte da
                             "Automatico" e impegno "Equilibrato". */
                          require_once __DIR__ . '/../../lib/fc/fc_ai_style.php';
                          $exm_valori = fc_ai_stile_valori(); ?>
                    <div class="exm-ai-lbl"><?php te('fc.ibfai_impegno_sep'); ?></div>
                    <?php /* La stessa tendina del pannello delle carte, dalla stessa
                             funzione: due copie del selettore restavano uguali solo
                             finche' nessuno ne toccava una. */
                          fc_ai_tendina_impegno('ex-ai-impegno', 'medio'); ?>
                    <div class="exm-ai-stile" id="ex-ai-stile">
                        <?php foreach (['esempio', 'lunghezza', 'registro', 'dominio'] as $campo): ?>
                            <label>
                                <span><?php te('fc.ibfai_stile_' . $campo); ?></span>
                                <select data-stile="<?php echo $campo; ?>">
                                    <?php foreach ($exm_valori[$campo] as $v): ?>
                                        <option value="<?php echo $v; ?>"><?php te($v === 'auto' ? 'fc.ibfai_stile_auto' : 'fc.ibfai_stile_' . $campo . '_' . $v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php /* Segnaposto e non contenuto: prima la frase d'esempio era il
                             testo vero del campo, e chi non la cancellava la mandava
                             all'IA come "indicazione aggiuntiva" a ogni richiesta. */ ?>
                    <textarea id="ex-ai-prompt" rows="2" placeholder="<?php echo sec_h(t('fc.exm_genera_ph')); ?>"></textarea>

                    <button id="ex-ai-btn" class="exm-ai-generate" onclick="onGenerateAiClick()"><?php te('fc.exm_genera_btn'); ?></button>

                </div>
            </div>

        </div>




    </div>

</div>



<script>
if (!window.__examplesModalCaricato) {
window.__examplesModalCaricato = true;

/* CORREZIONE — bug grave: "function" dichiarata dentro un blocco { } resta
   visibile SOLO in quel blocco. Le funzioni normali hanno un'eccezione
   storica (Annex B) che le fa comunque "uscire" nello scope globale in
   modalita' non strict, per compatibilita' con pagine vecchie — ma quella
   eccezione NON vale per le funzioni async: restano sempre confinate dentro
   il blocco in cui nascono, senza eccezioni, in ogni modalita'.
   Le quattro funzioni async di questo file nascevano dentro
   if (!window.__examplesModalCaricato) { ... } — nessun errore alla
   definizione, perche' e' codice perfettamente valido; ma "onclick=" nei
   pulsanti (qui e nelle pagine che includono questo file) le cerca nello
   scope globale, dove semplicemente non c'erano mai state. Diventano
   assegnazioni a window invece di dichiarazioni: un'assegnazione non risente
   dello scope del blocco in cui si trova. */

/* Il token di sessione lo emette questo file, non la pagina che lo include:
   il componente compare in piu' pagine e non tutte espongono una variabile
   con lo stesso nome. Prima si cercava una globale "csrf_token" che non
   esiste da nessuna parte, quindi il token non partiva mai e il server
   rispondeva 403. */
const EXM_CSRF = <?php echo json_encode(sec_csrf_token()); ?>;

const EXM_ICON_EXAMPLE = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>';

/* CORREZIONE — stessa categoria di bug delle quattro funzioni async
   corrette sopra, qui su una variabile: "let" e' SEMPRE confinata al
   blocco in cui nasce, senza nessuna eccezione — a differenza delle
   funzioni normali, "let" non ha mai avuto un'eccezione di compatibilita'
   che la faccia uscire dal blocco, in nessuna modalita'. Il pulsante "+"
   per un nuovo esempio la cerca da un onclick esterno (scope globale),
   dove semplicemente non e' mai esistita: "ReferenceError: currentFcId
   is not defined". Diventa una proprieta' di window, che non risente
   dello scope del blocco in cui si trova. */
window.currentFcId = 0; window.currentFcType = 'parent'; window.currentExId = 0;

function exChipKey(id, type) { return (type === 'child' ? 'c' : 'p') + id; }

/* ---- IL CONTATORE SUL PULSANTE DELLA CARTA ----
   Il pulsante "Esempi (n)" che sta sulla riga della carta — in
   fc_insert.php e nelle pagine coi voti — lo riscrive questo
   componente, perche' e' lui a sapere quando un esempio nasce o muore.

   Prima il numero nuovo veniva CALCOLATO qui: currentExamplesList.length + 1
   dopo un salvataggio, -1 dopo un'eliminazione. Funzionava per un giro solo.
   currentExamplesList e' la fotografia scattata all'apertura del pannello e
   non veniva mai aggiornata: aggiunto il secondo esempio senza chiudere e
   riaprire, il conto ripartiva sempre dalla stessa fotografia e il pulsante
   restava fermo sul numero di prima. Da fuori si vedeva solo il sintomo — si
   salva, si chiude, e il pulsante dice ancora quello che diceva.

   Ora il numero non si calcola: si chiede a chi lo sa, cioe' al server, che
   e' anche l'unico modo di essere giusti se nel frattempo qualcosa e'
   cambiato da un'altra scheda. Una richiesta piccola, solo quando un esempio
   e' stato davvero scritto o cancellato. */
function exAggiornaChip(fcId, fcType) {
    if (!fcId) return Promise.resolve();
    return fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(fcId)
                 + '&fc_type=' + encodeURIComponent(fcType))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) return;
            var esempi = d.examples || [];

            /* La fotografia si aggiorna insieme al pulsante: e' la stessa che
               legge l'elenco quando lo si riapre. */
            if (fcId === currentFcId && fcType === currentFcType) currentExamplesList = esempi;

            exScriviChip(exChipKey(fcId, fcType), esempi.length);
        })
        .catch(function () { /* il pulsante resta com'era: meglio vecchio che sbagliato */ });
}

/* Scrive il numero sui pulsanti di quella carta. Sono due perche' la stessa
   carta puo' comparire in due elenchi diversi nella stessa pagina (quello
   normale e quello dei voti, btn-ex-graded-*). */
function exScriviChip(chipKey, quanti) {
    ['btn-ex-' + chipKey, 'btn-ex-graded-' + chipKey].forEach(function (id) {
        var b = document.getElementById(id);
        if (!b) return;
        if (quanti > 0) {
            b.innerHTML = EXM_ICON_EXAMPLE + ' ' + T('fc.exm_conteggio', {n: quanti});
            b.style.background = 'var(--purple, #AF52DE)';
            b.style.color = 'white';
            b.style.borderColor = 'var(--purple, #AF52DE)';
        } else {
            b.innerText = T('fc.exm_aggiungi');
            b.style.background = 'var(--surface-3, #f1f3f5)';
            b.style.color = 'var(--text-3, #555)';
            b.style.borderColor = 'var(--hairline-2, #ccc)';
        }
    });
}

/* Stesso motivo della correzione qui sopra, anche se oggi nessun onclick
   esterno le referenzia ancora — cosi' non si riproduce lo stesso bug se
   in futuro qualcosa le usasse da fuori. */
window.currentQ = ''; window.currentA = '';

let currentExamplesList = []; // Memoria degli esempi

// Lo stato della registrazione (blob, timer, contatore) vive in
// fc_audio_recorder.js sotto la chiave 'esempio': niente piu' da dichiarare
// qui, ed e' lo stesso motore che usano fc_insert_card.php e
// fc_card_edit.php.



// Il campo "Testo dell'esempio" è generato da format_toolbar.php (che crea l'input
// nascosto e il div editabile dentro un wrapper .fmt-editor-wrap con data-field="ex_text").
// Cerchiamo quel wrapper invece di indovinare gli id esatti dei suoi figli: è più
// robusto e usa le stesse funzioni (ftRender/fcdParse/fcSyncAllEditors) che usa la toolbar stessa.
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
    if (wrap && typeof ftRender === 'function' && typeof fcdParse === 'function') {
        ftRender(wrap, fcdParse(rawText || ''));
        if (typeof ftMathRefresh === 'function') ftMathRefresh(wrap);
        return;
    }
    // Fallback se il wrapper non si trova per qualche motivo (o se
    // format_toolbar.php non fosse caricato): proviamo comunque a valorizzare
    // l'hidden e il div editabile con gli id più plausibili.
    let hidden = getExHiddenInput();
    if (hidden) hidden.value = rawText || '';
    let editorDiv = (wrap && document.getElementById(wrap.dataset.editorId)) || document.getElementById('ex_text_editor');
    if (editorDiv) {
        editorDiv.innerHTML = typeof parseTextForPreview === 'function' ? parseTextForPreview(rawText || '') : (rawText || '');
    }
}




/* ---- Pannello IA a comparsa ----
   Chiuso di default: solo il pulsante rotondo. Un tocco apre il campo del
   prompt e il pulsante di generazione; un altro tocco (o generare) richiude. */
function toggleAiBox(open) {
    /* Il pulsante non si nasconde piu': sta sempre dov'e', e a comparire e'
       un popup sopra — stesso cambiamento gia' fatto per l'IA della carta.
       Il tocco fuori dal riquadro chiude, come gli altri popup del sito. */
    var overlay = document.getElementById('ex-ai-overlay');
    overlay.hidden = !open;
    if (open) document.getElementById('ex-ai-prompt').focus();
}
(function () {
    var overlay = document.getElementById('ex-ai-overlay');
    if (overlay) {
        overlay.addEventListener('click', function (ev) {
            if (ev.target === overlay) toggleAiBox(false);
        });
    }
})();

/* ---- Salvataggio ----
   Se ne occupa la barra vera (StandoutSaveBar, istanza "esempi"): qui restano
   solo il segnale "qualcosa e' cambiato" e la funzione che sa davvero
   mandare i dati al server, che le viene passata in init() piu' sotto. */
function markExDirty() {
    if (typeof StandoutSaveBar !== 'undefined') StandoutSaveBar.touch('esempi');
}

/* Chiude scartando le modifiche non salvate, se ce ne sono: non si salva piu'
   nulla in questo caso, ne' subito ne' in un secondo momento (un eventuale
   salvataggio automatico gia' in coda va cancellato). */
/* Chiudendo il modulo di un esempio si torna all'ELENCO, non alla carta.
   Prima si tornava alla carta: scritto un esempio e premuta la X, il pannello
   spariva tutto insieme e per scriverne un secondo bisognava riaprire da capo
   dal pulsante della carta. Ma chi ne ha appena scritto uno quasi sempre ne
   ha in mente un altro, e l'elenco e' il posto da cui aggiungerlo (il "+" in
   basso) e da cui rileggere quello appena salvato.
   Cosi' le due X dicono due cose diverse e ognuna ne disfa una sola: questa
   chiude l'esempio, quella dell'elenco chiude gli esempi. */
function closeExampleForm() {
    let sporco = (typeof StandoutSaveBar !== 'undefined') && StandoutSaveBar.isDirty('esempi');
    if (sporco) {
        if (!confirm(T('fc.exm_conferma_uscita'))) return;
        StandoutSaveBar.discard('esempi');
    }
    document.getElementById('ex-form-modal').style.display = 'none';
    exTornaAllElenco();
}



function previewExImage(input) {

    let previewCont = document.getElementById('ex-img-preview-container');

    let previewImg = document.getElementById('ex-img-preview');

    /* has-img sul riquadro: e' la classe che nasconde l'invito "tocca per
       scegliere" e fa passare il bordo da tratteggiato a pieno. Il riquadro
       resta cliccabile, cosi' la foto si cambia toccando quella che c'e'. */
    let exImgWrap = document.getElementById('ex_img_wrap');

    markExDirty();

    if (input.files && input.files[0]) {

        let reader = new FileReader();

        reader.onload = function(e) {

            previewImg.src = e.target.result;

            previewCont.style.display = 'block';

            if (exImgWrap) exImgWrap.classList.add('has-img');

        }

        reader.readAsDataURL(input.files[0]);

    } else {

        previewCont.style.display = 'none';

        previewImg.src = '';

        if (exImgWrap) exImgWrap.classList.remove('has-img');

    }

}



window.openExamplesModal = async function (fc_id, fc_type, q_text, a_text) {

    currentFcId = fc_id; currentFcType = fc_type; currentQ = q_text; currentA = a_text;

    

    let data;
    try {
        let res = await fetch(fcExApi(`action=get&fc_id=${fc_id}&fc_type=${fc_type}`));
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

        openExampleForm(fc_id, fc_type, 0);

    } else {

        currentExamplesList = data.examples;
        exDisegnaElenco(fc_id, fc_type, data.examples);
        document.getElementById('ex-list-modal').style.display = 'flex';

    }

};

/* Il disegno dell'elenco era dentro openExamplesModal, che pero' fa anche
   un'altra cosa: se non trova nessun esempio apre da se' il modulo del nuovo.
   Serviva poterlo ridisegnare SENZA quella scorciatoia — chiudendo il modulo
   di un esempio si torna all'elenco, e se l'elenco fosse ancora vuoto
   riaprirebbe il modulo appena chiuso, all'infinito. */
/* Stessa domanda che si fa il carosello (fcHtmlSiVede in fc_examples_modal.js):
   non "il dato e' vuoto" ma "quello che verrebbe fuori si vede". Un esempio di
   sola immagine arriva con un documento di un paragrafo senza parole, che come
   stringa e' pienissimo e come riga e' vuoto.
   La copia locale c'e' perche' fc_examples_modal.js lo carica la PAGINA, non
   questo file, e non tutte quelle che includono questo pannello lo caricano:
   se c'e', si usa la sua, e resta una definizione sola da tenere giusta. */
function exTestoSiVede(t) {
    if (!t) return false;
    var html = (typeof parseTextForPreview === 'function') ? parseTextForPreview(t) : String(t);
    if (typeof fcHtmlSiVede === 'function') return fcHtmlSiVede(html);
    var d = document.createElement('div');
    d.innerHTML = html;
    if (d.querySelector('img, svg, table, hr, mjx-container')) return true;
    return d.textContent.replace(/\u00a0/g, ' ').trim() !== '';
}

function exDisegnaElenco(fc_id, fc_type, esempi) {
    var html = '';
    esempi.forEach(function (ex, i) {
        html += `<div class="exm-example-card">
                <div class="exm-example-label">${T('fc.exm_esempio_n', {n: i + 1})}</div>
                ${exTestoSiVede(ex.example_text) ? `<div class="raw-text exm-example-text">${(window.FC && FC.tipo === 'vocab' && typeof fcEsempioTestoLingua === 'function') ? fcEsempioTestoLingua(ex.example_text, ex._parola) : (typeof parseTextForPreview === 'function' ? parseTextForPreview(ex.example_text) : fcEscapeHtml(ex.example_text))}</div>` : ''}
                ${ex.example_audio ? `<audio src="../media/flashcard_audio/${ex.example_audio}" controls class="exm-example-audio"></audio>` : ''}
                ${ex.example_image ? `<img src="../media/example_images/${ex.example_image}" class="exm-example-image">` : ''}
                <button type="button" class="exm-example-edit-btn" onclick="openExampleForm(${fc_id}, '${fc_type}', ${ex.id})">${T('fc.exm_modifica')}</button>
            </div>`;
    });
    document.getElementById('ex-list-content').innerHTML = html;
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(document.getElementById('ex-list-content'));
}

/* Apre il modulo di UN esempio preciso partendo da zero — da un indirizzo, da
   un'altra pagina — senza essere passati dall'elenco.
   openExampleForm da sola non basta: i campi non li chiede al server, li pesca
   da currentExamplesList, che e' la memoria riempita da openExamplesModal.
   Arrivando dritti, quella memoria e' vuota: la funzione non trova l'esempio e
   apre il modulo di uno nuovo, vuoto. Con un esempio fatto di sola immagine il
   guasto sembra piu' piccolo di quello che e' — "manca l'immagine" — ma non
   manca l'immagine: manca tutto, e non c'era niente da vedere a parte lei.
   Quindi prima si riempie la memoria, poi si apre. */
/* Spuntata la casella, il riquadro si smorza: si vede subito che quella foto
   sta per andarsene, prima ancora di salvare. */
function exSegnaRimozione(cb) {
    var box = document.getElementById('ex-img-attuale');
    if (box) box.classList.toggle('img-marked-removed', cb.checked);
    markExDirty();
}

/* Accende o spegne il riquadro dell'immagine gia' salvata, e con lui il modo
   in cui i due riquadri si dividono la riga: da solo, quello "Nuova" si prende
   tutta la colonna (img-compare-solo); in due stanno affiancati. */
function exMostraImmagineAttuale(nomeFile) {
    var box = document.getElementById('ex-img-attuale');
    var img = document.getElementById('ex-img-attuale-src');
    var rim = document.getElementById('ex-img-rimuovi');
    var riga = document.getElementById('ex-img-compare');
    if (!box || !riga) return;

    var esiste = !!nomeFile;
    box.hidden = !esiste;
    box.classList.remove('img-marked-removed');
    if (rim) rim.checked = false;
    if (img) img.src = esiste ? ('../media/example_images/' + nomeFile) : '';
    riga.classList.toggle('img-compare-solo', !esiste);

    /* E CON DUE RIQUADRI IMMAGINE, L'AUDIO VA SOTTO.
       E' la stessa regola del modulo della carta: li' immagine e audio si
       affiancano solo quando l'immagine e' un riquadro solo. Con "Attuale" e
       "Nuova" le colonne diventano tre, e su un telefono tre colonne vogliono
       dire tre riquadri troppo stretti — il microfono con la sua scritta
       accanto non ci sta piu', e infatti nello schermo si vedeva il cerchio
       dell'IA appoggiato sopra la card dell'audio.
       Togliendo la classe, i due contenitori tornano uno sotto l'altro. */
    var affiancati = document.getElementById('ex-media-affiancati');
    if (affiancati) affiancati.classList.toggle('fc-media-affiancati', !esiste);
}

window.exApriEsempioSingolo = function (fc_id, fc_type, ex_id, q_text, a_text) {
    currentFcId = fc_id;
    currentFcType = fc_type;
    currentQ = q_text || '';
    currentA = a_text || '';
    return fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(fc_id)
                 + '&fc_type=' + encodeURIComponent(fc_type))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            currentExamplesList = (d && d.success && d.examples) ? d.examples : [];
            openExampleForm(fc_id, fc_type, ex_id);
        })
        .catch(function () { openExampleForm(fc_id, fc_type, ex_id); });
};

/* Riapre l'elenco con quello che c'e' ADESSO nel database. Se non c'e' piu'
   niente non apre un pannello vuoto: chiude e basta, che e' l'unica cosa
   sensata da mostrare quando non c'e' niente da mostrare. */
function exTornaAllElenco() {
    if (!currentFcId) { chiudiElencoEsempi(); return; }
    fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(currentFcId)
          + '&fc_type=' + encodeURIComponent(currentFcType))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var esempi = (d && d.success && d.examples) ? d.examples : [];
            currentExamplesList = esempi;
            if (!esempi.length) { chiudiElencoEsempi(); return; }
            exDisegnaElenco(currentFcId, currentFcType, esempi);
            document.getElementById('ex-list-modal').style.display = 'flex';
        })
        .catch(function () { chiudiElencoEsempi(); });
}

/* L'ultima porta che si chiude. Da qui si torna alla carta, e la carta ha una
   sezione "esempi" che nel frattempo puo' essere diventata vecchia di due
   esempi: l'avviso serve a chi ospita questo pannello per rinfrescarla. Un
   evento e non una chiamata diretta, perche' questo componente non sa in che
   pagina si trova — lo ascolta chi vuole. */
function chiudiElencoEsempi() {
    document.getElementById('ex-list-modal').style.display = 'none';
    document.dispatchEvent(new CustomEvent('fc-esempi-chiusi', {
        detail: { fc_id: currentFcId, fc_type: currentFcType }
    }));
}
window.chiudiElencoEsempi = chiudiElencoEsempi;
window.exTornaAllElenco = exTornaAllElenco;



function openExampleForm(fc_id, fc_type, ex_id) {

    currentFcId = fc_id; currentFcType = fc_type; currentExId = ex_id;

    let title = document.getElementById('ex-form-title');

    let deleteBtn = document.getElementById('ex-delete-btn');

    let player = document.getElementById('ex-audio-player');

    let imgPreviewCont = document.getElementById('ex-img-preview-container');

    let imgPreview = document.getElementById('ex-img-preview');

    let recBtn = document.getElementById('ex-rec-btn');

    let statusEl = document.getElementById('ex-rec-status');

    

    // Reset base

    setExEditorText('');

    toggleAiBox(false);

    if (typeof StandoutSaveBar !== 'undefined') {
        StandoutSaveBar.reset('esempi');
        StandoutSaveBar.setAuto(false, 'esempi', true);
    }

    player.style.display = 'none';

    player.src = '';

    document.getElementById('ex-image-file').value = '';

    imgPreviewCont.style.display = 'none';

    imgPreview.src = '';

    /* Il riquadro torna vuoto: senza togliere has-img resterebbe col bordo
       pieno e senza l'invito a scegliere, cioe' con l'aria di contenere
       ancora la foto dell'esempio precedente. */
    document.getElementById('ex_img_wrap').classList.remove('has-img');
    exMostraImmagineAttuale('');

    fcAudioResetta('esempio');



    recBtn.classList.remove('recording');

    recBtn.querySelector('.ic-mic').style.display = '';

    recBtn.querySelector('.ic-stop').style.display = 'none';

    statusEl.textContent = T('fc.ifc_tocca_registrare');

    

    if(ex_id > 0) {

        title.innerText = T('fc.exm_modifica_titolo');

        deleteBtn.style.display = 'flex'; 

        let ex = currentExamplesList.find(e => e.id == ex_id);

        if (ex) {

            setExEditorText(ex.example_text || '');

            

            if(ex.example_audio) {

                player.src = `../media/flashcard_audio/${ex.example_audio}`;

                player.style.display = 'block';

                statusEl.textContent = T('fc.exm_audio_salvato');

            }

            /* L'immagine gia' salvata va nel riquadro "Attuale", non dentro
               quello "Nuova": quello resta libero per la foto che eventualmente
               la sostituira'. */
            exMostraImmagineAttuale(ex.example_image || '');

        }

    } else {

        title.innerText = T('fc.exm_nuovo_titolo');

        deleteBtn.style.display = 'none';

    }



    document.getElementById('ex-list-modal').style.display = 'none';

    document.getElementById('ex-form-modal').style.display = 'flex';

}



function toggleExampleRecord() {
    toggleFcAudioRecord('esempio', 'ex-rec-btn', 'ex-rec-status', function (blob) {
        let player = document.getElementById('ex-audio-player');
        player.src = URL.createObjectURL(blob);
        player.style.display = 'block';
        markExDirty();
    });
}



let exAiTimerInterval = null;

function onGenerateAiClick() {
    let hiddenInput = getExHiddenInput();
    let hasContent = hiddenInput && hiddenInput.value.trim() !== '';
    if (hasContent && !confirm(T('fc.exm_conferma_sovrascrivi'))) return;
    generateAiExample();
}

/* ---- Stile e impegno degli esempi ----
   Si ricordano nel browser fra una volta e l'altra. Il primo giorno partono
   da tono e materia scelti nel pannello delle carte, se ci sono: chi ha
   detto "informatica" per le carte lo vuole anche per gli esempi. */
function exmStile() {
    var st = {};
    document.querySelectorAll('#ex-ai-stile select[data-stile]').forEach(function (s) { st[s.getAttribute('data-stile')] = s.value; });
    return st;
}
var exmTendinaImpegno = null;
function exmImpegno() {
    if (exmTendinaImpegno) return exmTendinaImpegno.valore();
    var b = document.querySelector('#ex-ai-impegno [data-impegno].is-on');
    return b ? b.getAttribute('data-impegno') : 'medio';
}
function exmScegliImpegno(v) {
    if (exmTendinaImpegno) { exmTendinaImpegno.imposta(v, true); return; }
    document.querySelectorAll('#ex-ai-impegno [data-impegno]').forEach(function (b) {
        var su = b.getAttribute('data-impegno') === v;
        b.classList.toggle('is-on', su);
        b.setAttribute('aria-selected', su ? 'true' : 'false');
    });
}
function exmSalvaStile() {
    try { localStorage.setItem('exm_stile', JSON.stringify({ stile: exmStile(), impegno: exmImpegno() })); } catch (e) {}
}
function exmCaricaStile() {
    var dati = null;
    try {
        dati = JSON.parse(localStorage.getItem('exm_stile') || 'null');
        if (!dati) {
            var carte = JSON.parse(localStorage.getItem('fcai_stile:ultimo') || 'null');
            if (carte && carte.stile) dati = { stile: { registro: carte.stile.registro, dominio: carte.stile.dominio, esempio: carte.stile.esempio }, impegno: 'medio' };
        }
    } catch (e) { dati = null; }
    if (!dati) return;
    document.querySelectorAll('#ex-ai-stile select[data-stile]').forEach(function (s) {
        var v = (dati.stile || {})[s.getAttribute('data-stile')];
        s.value = Array.prototype.some.call(s.options, function (o) { return o.value === v; }) ? v : 'auto';
    });
    exmScegliImpegno(dati.impegno || 'medio');
}
/* Il testo della carta come lo leggerebbe una persona. I campi della carta
   contengono il formato salvato (spesso il JSON dei blocchi): mandarlo cosi'
   all'IA voleva dire pagare token per parentesi e chiavi, e far leggere al
   modello la struttura invece del contenuto. */
function exmTestoPiano(raw) {
    if (!raw) return '';
    if (typeof fcdParse !== 'function' || typeof fcdBlockText !== 'function') return String(raw);
    try {
        return (fcdParse(raw).blocks || []).map(function (b) { return fcdBlockText(b); }).join('\n').trim() || String(raw);
    } catch (e) { return String(raw); }
}
document.addEventListener('DOMContentLoaded', function () {
    if (window.NS && NS.tendina) {
        exmTendinaImpegno = NS.tendina(document.getElementById('ex-ai-impegno'),
            { valore: 'medio', onCambia: exmSalvaStile });
    }
    if (!exmTendinaImpegno) {
        document.querySelectorAll('#ex-ai-impegno [data-impegno]').forEach(function (b) {
            b.addEventListener('click', function () { exmScegliImpegno(b.getAttribute('data-impegno')); exmSalvaStile(); });
        });
    }
    document.querySelectorAll('#ex-ai-stile select').forEach(function (s) { s.addEventListener('change', exmSalvaStile); });
    exmCaricaStile();
});

window.generateAiExample = async function () {

    let btn = document.getElementById('ex-ai-btn');

    let extra = document.getElementById('ex-ai-prompt').value;

    let exStats = (window.aiPerfStats && window.aiPerfStats.example) || { avg_seconds: null, slower_than_usual: false };
    if (exStats.slower_than_usual && typeof showApiSlowToast === 'function') showApiSlowToast();

    let exElapsed = 0;
    let exStimaLabel = exStats.avg_seconds ? T('fc.gm_di_solito', { s: exStats.avg_seconds }) : T('fc.gm_generazione');

    const exSpinnerSvg = '<svg class="exm-spin" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>';

    btn.disabled = true; btn.innerHTML = `${exSpinnerSvg} 0s · ${exStimaLabel}`;

    clearInterval(exAiTimerInterval);
    exAiTimerInterval = setInterval(() => {
        exElapsed++;
        btn.innerHTML = `${exSpinnerSvg} ${exElapsed}s · ${exStimaLabel}`;
    }, 1000);

    

    try {

        exmSalvaStile();
        let res = await fetch('../ai/ai_example.php', {

            /* Il token va sempre, esplicito: ai_example.php ora lo controlla
               (prima no), e questo popup vive anche in pagine dove non e' detto
               che standout_fetch.js sia caricato per primo. */
            method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': EXM_CSRF},

            body: JSON.stringify({
                q: exmTestoPiano(currentQ), a: exmTestoPiano(currentA), extra: extra.trim(),
                opzioni: { stile: exmStile(), impegno: exmImpegno() }
            })

        });

        let data = await res.json();

        if(data.success) {

            let hiddenInput = getExHiddenInput();

            let newDoc = fcdParse(data.example);

            if (hiddenInput && hiddenInput.value.trim() !== '') {

                let existingDoc = fcdParse(hiddenInput.value);

                newDoc = { v: FCD_V, blocks: existingDoc.blocks.concat(newDoc.blocks) };

            }

            let wrap = getExFormatWrap();
            if (wrap && typeof ftRender === 'function') {
                ftRender(wrap, newDoc);
                if (typeof ftMathRefresh === 'function') ftMathRefresh(wrap);
            } else {
                setExEditorText(data.example);
            }

            markExDirty();

            toggleAiBox(false);

        } else if (data.error === 'limite_piano' && typeof showTokenLimitModal === 'function') {

            showTokenLimitModal(data.message);

        } else {
            /* Il messaggio lo sceglie il server, che conosce la causa, ed e' gia'
               tradotto. Prima qui finivano il codice interno, la risposta grezza
               e i dettagli tecnici: utili a chi sviluppa, incomprensibili (e
               rivelatori) per chi usa il sito. */
            alert(data.message || T('fc.ibfai_errore_ia'));
        }

    } catch(e) { alert(T('vocab.errore_rete_p')); }

    

    clearInterval(exAiTimerInterval);
    btn.disabled = false; btn.innerText = T('fc.exm_genera_btn2');

};



/* Questa e' la funzione "save" passata a StandoutSaveBar.init() piu' sotto:
   manuale=true quando e' stato il tasto Salva a chiamarla, false quando e'
   l'automatico (o la sua rete di sicurezza ogni 25s) a farlo da solo. Deve
   restituire true/false (anche come Promise) e non deve mai ricaricare la
   pagina: ci pensa gia' StandoutSaveBar a gestire sporco/salvando/orario. */
window.exSalvaEsempio = async function (manuale) {

    if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();

    let wasNew = (currentExId === 0);

    let fd = new FormData();
    fd.append('action', 'save');
    fd.append('fc_id', currentFcId);
    fd.append('fc_type', currentFcType);
    fd.append('ex_id', currentExId);
    fd.append('text', getExHiddenInput() ? getExHiddenInput().value : '');

    /* Il token va sempre, non "se per caso esiste una variabile": senza,
       sec_guard_write chiude la porta con un 403 e il salvataggio non parte. */
    fd.append('csrf_token', EXM_CSRF);

    if (fcAudioBlobDi('esempio')) fd.append('audio', fcAudioBlobDi('esempio'), 'ex_audio.' + fcAudioExtDi('esempio'));

    let fileInput = document.getElementById('ex-image-file');
    if (fileInput.files.length > 0) fd.append('image', fileInput.files[0]);

    /* "Togli quella che c'e'". Senza, l'unico modo di levare un'immagine da un
       esempio era caricarne un'altra: non c'era. */
    var rimImg = document.getElementById('ex-img-rimuovi');
    if (rimImg && rimImg.checked) fd.append('remove_image', '1');

    try {
        let res = await fetch(fcExApi(''), { method: 'POST', body: fd });
        let data = await res.json();
        if (data.success) {
            if (wasNew) currentExId = data.ex_id;
            fcAudioResetta('esempio');
            fileInput.value = '';

            /* Il numero vero, chiesto al server: non si aspetta la chiusura del
               pannello ne' un ricaricamento della pagina. */
            exAggiornaChip(currentFcId, currentFcType);

            if (wasNew) {
                document.getElementById('ex-delete-btn').style.display = 'flex';
                document.getElementById('ex-form-title').textContent = T('fc.exm_modifica_titolo');
            }
            return true;
        } else {
            if (manuale) alert(T('fc.exm_errore_salva') + (data.error ? ": " + data.error : ""));
            return false;
        }
    } catch (e) {
        if (manuale) alert(T('fc.exm_errore_salva'));
        return false;
    }

};

/* Nome storico, per chi la richiama da fuori (es. una scorciatoia da tastiera):
   un salvataggio manuale come un altro, chiesto alla barra vera. */
function saveExample() {
    return (typeof StandoutSaveBar !== 'undefined') ? StandoutSaveBar.saveNow('esempi') : exSalvaEsempio(true);
}



window.deleteExample = async function () {

    if (!confirm(T('fc.exm_conferma_elimina'))) return;

    

    let btn = document.getElementById('ex-delete-btn');

    let originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<svg class="exm-spin" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>';



    try {

        /* Anche l'eliminazione passa da sec_guard_write, quindi vuole il token:
           in GET non c'era modo di mandarlo e finiva anche lei in 403. */
        let fdDel = new FormData();
        fdDel.append('action', 'delete');
        fdDel.append('ex_id', currentExId);
        fdDel.append('csrf_token', EXM_CSRF);

        let res = await fetch(fcExApi(''), { method: 'POST', body: fdDel });

        let data = await res.json();

        if(data.success) {

            if (typeof StandoutSaveBar !== 'undefined') StandoutSaveBar.discard('esempi');

            document.getElementById('ex-form-modal').style.display = 'none';

            /* exTornaAllElenco e non openExamplesModal: quest'ultima, se non
               trova piu' nessun esempio, riapre il modulo di uno nuovo — e
               subito dopo un'eliminazione e' l'ultima cosa che si vuole
               vedere. */
            exTornaAllElenco();

            /* Anche qui il numero si chiede, non si sottrae: currentExamplesList
               poteva gia' essere vecchia di un esempio, e -1 su un numero
               vecchio da' un numero vecchio. */
            exAggiornaChip(currentFcId, currentFcType);

        } else {

            /* Senza questo ramo un rifiuto del server non si vedeva: la finestra
               restava aperta senza dire niente e sembrava che il tasto fosse morto. */

            alert(T('fc.exm_errore_elimina') + (data.error ? ": " + data.error : ""));

            btn.disabled = false; btn.innerHTML = originalHtml;

        }

    } catch(e) {
        alert(T('fc.exm_errore_elimina'));
        btn.disabled = false; btn.innerHTML = originalHtml;
    }

};

/* Ogni cambiamento nel testo dell'editor (contenteditable, quindi genera
   davvero l'evento 'input' del browser) segna il form come da salvare. Un solo
   ascoltatore delegato: il componente e' incluso una volta sola per pagina, ma
   l'editor con id "ex_text_editor" e' quello generato da format_toolbar.php
   con $ft_field = 'ex_text' qui sopra. */
document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'ex_text_editor') markExDirty();
});

if (typeof StandoutSaveBar !== 'undefined') {
    StandoutSaveBar.init({
        instance: 'esempi',
        key: 'esempi_form',
        save: exSalvaEsempio,
        testoMaiSalvato: T('fc.exm_autosave_breve')
    });
    StandoutSaveBar.setAuto(false, 'esempi', true);
}

(function () {
    /* Registro condiviso "quali popup sono aperti": un Set su window invece
       di una bandiera calcolata guardando solo i pannelli di QUESTO
       componente, cosi' la pagina che lo include (es. fc_game.php) puo'
       registrarci i propri popup nello stesso posto - altrimenti due
       controlli scollegati rischierebbero di azzerarsi a vicenda (uno
       vede i suoi chiusi e sovrascrive la bandiera anche se un altro
       popup, che lui non conosce, e' ancora aperto). */
    window.__standoutModaliAperti = window.__standoutModaliAperti || new Set();

    window.standoutOsservaModale = window.standoutOsservaModale || function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        function aggiorna() {
            var visibile = el.style.display && el.style.display !== 'none';
            if (visibile) window.__standoutModaliAperti.add(id); else window.__standoutModaliAperti.delete(id);
            var prima = window.__standoutModaleAperto;
            window.__standoutModaleAperto = window.__standoutModaliAperti.size > 0;
            /* Se questa pagina vive dentro il popup di modifica (window.parent
               diverso da window), il pulsante IA di QUELLA carta sta nella
               finestra vera, fuori dall'iframe (fc_edit_popup.js lo crea li').
               Quando un popup di esempio si apre sopra di lui, resta comunque
               visibile e cliccabile — due cerchi viola sovrapposti, uno dei
               due per una carta che in quel momento non si sta nemmeno
               guardando. Un avviso ogni volta che __standoutModaleAperto cambia
               (solo al cambiamento, non ad ogni aggiornamento) gli dice
               quando nascondersi e quando tornare. */
            if (prima !== window.__standoutModaleAperto && window.parent && window.parent !== window) {
                window.parent.postMessage({
                    source: 'fc-modifica-popup',
                    type: window.__standoutModaleAperto ? 'esempio-aperto' : 'esempio-chiuso'
                }, window.location.origin);
            }
        }
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(aggiorna).observe(el, { attributes: true, attributeFilter: ['style'] });
        }
        aggiorna();
    };

    ['ex-list-modal', 'ex-form-modal'].forEach(window.standoutOsservaModale);
})();

}
</script>