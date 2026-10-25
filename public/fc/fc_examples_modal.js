/* fc_examples_modal.js — il riquadro "N esempi" con il carosello, condiviso.
 *
 * PERCHE' ESISTE
 * Questo pezzo viveva incollato dentro lo script di fc_view.php:
 * il riquadro compatto sotto una carta, con i pallini e le frecce per
 * scorrere fra piu' esempi. fc_card_edit.php aveva solo il pulsante
 * "Gestisci esempi"/"Aggiungi esempio", senza questa anteprima — portarla lì
 * significava o duplicare qualche centinaio di righe, o estrarle una volta
 * sola qui e farle usare a entrambe le pagine.
 *
 * COSA FA E COSA NON FA
 * Riempie i riquadri con la classe .qa-examples-box[data-fc-id] (li rende
 * render_examples_section() lato PHP, o l'equivalente in
 * fc_card_edit.php), li fa scorrere con pallini/frecce, e reagisce
 * allo swipe orizzontale sul tocco. NON decide cosa succede quando si tocca
 * un riquadro gia' pieno: quello resta a chi include questo file, perche' le
 * due pagine vogliono cose diverse — fc_view.php apre un visore a
 * schermo intero di sola lettura, fc_card_edit.php apre il popup di
 * modifica che ha gia'. Ogni pagina resta libera di ascoltare i click sui
 * riquadri .qa-examples-box-filled come preferisce; fcExUltimoSwipe (qui
 * sotto) e' pensata apposta per essere letta da quell'ascoltatore, per non
 * scambiare la fine di uno swipe per un tocco.
 *
 * COME SI USA
 *   fcLoadExampleBoxes()          — dopo aver disegnato le carte in pagina,
 *                                    riempie ogni riquadro vuoto via
 *                                    fc_examples_api.php
 *   fcLoadExampleBoxes(root)      — come sopra, ma solo dentro root (utile
 *                                    dopo aver aggiunto carte via AJAX)
 */

/* Indirizzo di fc_examples_api.php con il tipo dentro (?course=N per le
   lingue): l'API deve sapere in quali tabelle cercare la carta. */
if (typeof window.fcExApi !== 'function') {
    window.fcExApi = function (q) {
        var base = 'fc_examples_api.php';
        if (window.FC && FC.qs) return base + '?' + FC.qs + (q ? '&' + q : '');
        return base + (q ? '?' + q : '');
    };
}

var fcExUltimoSwipe = 0;   /* ultimo swipe fra esempi: per non farlo scambiare per un tocco */

function fcLoadExampleBoxes(root) {
    var boxes = (root || document).querySelectorAll('.qa-examples-box[data-fc-id]');
    boxes.forEach(function (box) {
        var realId = parseInt(box.getAttribute('data-fc-id'), 10) || 0;
        var type = box.getAttribute('data-fc-type') || 'parent';
        if (!realId) return;

        fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(realId) + '&fc_type=' + encodeURIComponent(type))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.examples || !data.examples.length) return;
                /* per le lingue l'API manda anche la parola: serve a riempire
                   i trattini della frase d'esempio */
                if (data.word) data.examples.forEach(function (e) { e._parola = data.word; });
                fcRenderExampleBox(box, data.examples);
            })
            .catch(function () { /* la scheda resta col placeholder tratteggiato */ });
    });
}

function fcEscapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = (s == null) ? '' : String(s);
    return d.innerHTML;
}

/* ---------- Esempi: un esempio alla volta, con pallini e frecce se sono piu' di uno ----------
   Il box arriva vuoto e viene riempito qui via fetch. Per ogni scheda si
   tengono l'elenco e l'indice corrente come proprieta' JS sull'elemento
   (box._fcExamples/_fcIndex): sono l'unica fonte di verita', il box si
   ridisegna per intero a ogni cambio invece di tenere pallini/frecce/
   contenuto sincronizzati a mano in tre punti diversi.

   Il visore a flashcard di fc_view.php prende pero' questa
   sezione con un outerHTML (una fotografia statica): un clone cosi' non
   porta con se' le proprieta' JS dell'originale. Per questo gli esempi
   vengono scritti ANCHE in un attributo data-fc-examples (che sopravvive
   alla copia), e fcHydrateEsempioBox() lo rilegge al primo tocco se
   _fcExamples manca — cosi' il carosello funziona anche dentro una copia. */

/* VUOTO SI DECIDE GUARDANDO IL RISULTATO, NON IL DATO
   ---------------------------------------------------------------
   Un esempio di sola immagine arriva dal database con un testo che vuoto non
   e'. Nel caso visto: {"v":2,"blocks":[{"t":"p","runs":[]}]} — il documento
   di un solo paragrafo senza parole dentro. Per un `if` e' una stringa piena;
   ripulendola dagli spazi resta piena lo stesso, perche' di spazi non ne ha:
   ha delle parentesi graffe. Il mio primo tentativo tagliava spazi e <br>, e
   contro questo non poteva funzionare.
   Quel paragrafo, disegnato, diventa un <div class="fc-p"> vuoto, e .fc-p ha
   min-height:1.65em — cioe' esattamente la riga vuota che si vedeva sopra
   l'immagine.
   La domanda giusta quindi non e' "il dato e' vuoto?" ma "quello che verrebbe
   fuori si vede?". Si disegna in un elemento appeso per aria, e si guarda se
   contiene una parola o qualcosa da guardare. Cosi' vale per qualunque formato
   il testo abbia oggi e per quelli che avra' domani, senza doverli conoscere. */
function fcHtmlSiVede(html) {
    if (!html) return false;
    var d = document.createElement('div');
    d.innerHTML = html;
    /* Roba che si vede anche senza parole: un'immagine dentro il testo, una
       formula, una tabella, una riga divisoria. */
    if (d.querySelector('img, svg, table, hr, mjx-container, .fc-img, .fc-formula')) return true;
    return d.textContent.replace(/\u00a0/g, ' ').trim() !== '';
}

/* LA PARTE NASCOSTA DI UNA FRASE D'ESEMPIO (solo lingue): il calcolo sta
   in fc_vocab_gap.js (window.fcvGap), condiviso con il modulo della parola e
   con l'inserimento multiplo. fcvLacuna/fcvLacune restano come nomi. */

/* FRASE D'ESEMPIO DI UNA PAROLA
   La parte che nel gioco si nasconde (fcvLacuna) qui si vede in evidenza;
   una vecchia fila di trattini bassi ("The _ is barking.") si riempie con la
   parola. Accanto c'e' il tasto per sentire la frase intera. */
function fcEsempioTestoLingua(testo, parola, scelta) {
    var p = String(parola || '').trim();
    testo = String(testo || '');
    var html, frase;
    if (window.fcvGap) {
        html = fcvGap.html(testo, p, scelta,
            function (t) { return '<strong class="qa-ex-fill">' + fcEscapeHtml(t) + '</strong>'; },
            function (t) { return fcEscapeHtml(t); });
        frase = fcvGap.daSentire(testo, p);
    } else {
        html = fcEscapeHtml(testo);
        frase = testo;
    }
    return html + ' <button type="button" class="qa-speak qa-speak-ex" data-speak="learn" data-text="'
         + fcEscapeHtml(frase) + '" aria-label="' + fcEscapeHtml(T('fcv.ascolta')) + '">'
         + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button>';
}

function fcEsempioHtml(ex) {
    var parts = [];
    if (ex.example_text) {
        var vocab = window.FC && FC.tipo === 'vocab';
        var htmlText = vocab ? fcEsempioTestoLingua(ex.example_text, ex._parola, ex.gap_text)
            : (typeof parseTextForPreview === 'function')
            ? parseTextForPreview(ex.example_text)
            : fcEscapeHtml(ex.example_text);
        if (fcHtmlSiVede(htmlText)) parts.push('<div class="qa-example-text">' + htmlText + '</div>');
    }
    if (ex.example_image) parts.push('<img class="qa-example-image" src="../media/example_images/' + fcEscapeHtml(ex.example_image) + '" alt="">');
    if (ex.example_audio) parts.push('<audio class="qa-example-audio" src="../media/flashcard_audio/' + fcEscapeHtml(ex.example_audio) + '" controls></audio>');
    return parts.join('');
}

function fcNavEsempiHtml(box) {
    var n = (box._fcExamples || []).length;
    if (n <= 1) return '';
    var i = box._fcIndex || 0;
    var html = '<div class="qa-ex-nav">';
    if (i > 0) {
        html += '<button type="button" class="qa-ex-arrow qa-ex-prev" onclick="event.stopPropagation(); fcEsempioFreccia(this,-1)" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_prec')) + '">'
              + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>';
    } else {
        html += '<span class="qa-ex-arrow-spacer"></span>';
    }
    html += '<span class="qa-ex-dots">';
    for (var d = 0; d < n; d++) html += '<span class="qa-ex-dot' + (d === i ? ' active' : '') + '"></span>';
    html += '</span>';
    if (i < n - 1) {
        html += '<button type="button" class="qa-ex-arrow qa-ex-next" onclick="event.stopPropagation(); fcEsempioFreccia(this,1)" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_succ')) + '">'
              + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>';
    } else {
        html += '<span class="qa-ex-arrow-spacer"></span>';
    }
    html += '</div>';
    return html;
}

function fcAggiornaNavEsempi(box) {
    var vecchio = box.querySelector('.qa-ex-nav');
    var nuovo = fcNavEsempiHtml(box);
    if (vecchio) {
        if (nuovo) vecchio.outerHTML = nuovo; else vecchio.remove();
    } else if (nuovo) {
        box.insertAdjacentHTML('afterbegin', nuovo);
    }
    box.setAttribute('data-fc-index', String(box._fcIndex || 0));
}

function fcDisegnaEsempio(box) {
    var esempi = box._fcExamples || [];
    var i = box._fcIndex || 0;
    if (!esempi.length) return;
    box.innerHTML = fcNavEsempiHtml(box)
        + '<div class="qa-ex-viewport"><div class="qa-example-item">' + fcEsempioHtml(esempi[i]) + '</div></div>';
    box.setAttribute('data-fc-index', String(i));
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(box);
}

/* Se _fcExamples manca (caso del clone nel visore a flashcard) lo si
   recupera dall'attributo, che invece sopravvive a un outerHTML. */
function fcHydrateEsempioBox(box) {
    if (box._fcExamples) return true;
    var raw = box.getAttribute('data-fc-examples');
    if (!raw) return false;
    try {
        box._fcExamples = JSON.parse(raw);
        box._fcIndex = parseInt(box.getAttribute('data-fc-index') || '0', 10) || 0;
        return true;
    } catch (e) { return false; }
}

function fcEsempioFreccia(btn, verso) {
    var box = btn.closest('.qa-examples-box');
    if (!box || !fcHydrateEsempioBox(box)) return;
    var n = box._fcExamples.length;
    var ni = box._fcIndex + verso;
    /* Le frecce non fanno il giro: ai bordi semplicemente non ci sono (vedi
       fcNavEsempiHtml), questo e' solo un secondo controllo di sicurezza. */
    if (ni < 0 || ni >= n) return;
    fcCambiaEsempioAnimato(box, ni, verso);
}

/* Cambio esempio animato: il contenuto vecchio sparisce (opacita' a 0) prima
   di essere sostituito, cosi' non c'e' mai un istante in cui si vede un
   contenuto sbagliato durante lo scambio. */
function fcCambiaEsempioAnimato(box, nuovoIndice, verso) {
    var vp = box.querySelector('.qa-ex-viewport');
    if (!vp) { box._fcIndex = nuovoIndice; fcDisegnaEsempio(box); return; }

    box._fcIndex = nuovoIndice;
    fcAggiornaNavEsempi(box);

    var distanza = verso > 0 ? 16 : -16;
    vp.style.transition = 'opacity .14s ease-in, transform .14s ease-in';
    vp.style.opacity = '0';
    vp.style.transform = 'translateX(' + (-distanza) + 'px)';

    setTimeout(function () {
        vp.innerHTML = '<div class="qa-example-item">' + fcEsempioHtml(box._fcExamples[box._fcIndex]) + '</div>';
        if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(vp);

        vp.style.transition = 'none';
        vp.style.transform = 'translateX(' + distanza + 'px)';
        void vp.offsetWidth;
        vp.style.transition = 'opacity .22s var(--spring), transform .22s var(--spring)';
        vp.style.opacity = '1';
        vp.style.transform = 'none';
    }, 150);
}

/* Sfogliata orizzontale sui dispositivi touch: qui, a differenza delle
   frecce, si fa il giro (dal primo si passa all'ultimo e viceversa). Un solo
   ascoltatore delegato per tutta la pagina, non uno per scheda: le schede
   con esempi possono essere molte, su entrambe le pagine che includono
   questo file. */
(function () {
    var box = null, x0 = 0, y0 = 0, deciso = '';

    document.addEventListener('touchstart', function (e) {
        var vp = e.target.closest && e.target.closest('.qa-examples-box');
        if (!vp || e.touches.length !== 1) { box = null; return; }
        if (!fcHydrateEsempioBox(vp) || vp._fcExamples.length < 2) { box = null; return; }
        box = vp;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
        deciso = '';
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!box) return;
        var dx = e.touches[0].clientX - x0;
        var dy = e.touches[0].clientY - y0;
        if (!deciso) {
            if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
            /* Un verso solo: sotto al margine e' lo scroll verticale (per
               leggere l'esempio intero) ad avere la meglio. */
            deciso = Math.abs(dx) > Math.abs(dy) * 1.2 ? 'x' : 'y';
        }
        if (deciso === 'x') e.preventDefault();
    }, { passive: false });

    function fine(e) {
        if (!box) return;
        var b = box; box = null;
        if (deciso !== 'x') return;
        var dx = (e.changedTouches ? e.changedTouches[0].clientX : x0) - x0;
        if (Math.abs(dx) < 40) return;
        var n = b._fcExamples.length;
        var verso = dx < 0 ? 1 : -1;
        var ni = (b._fcIndex + verso + n) % n;
        /* Alla fine di una sfogliata il browser manda comunque un click: lo si
           marca qui perche' non venga scambiato per un tocco sul riquadro,
           da chi ascolta i click su .qa-examples-box-filled nella pagina che
           include questo file. */
        fcExUltimoSwipe = Date.now();
        fcCambiaEsempioAnimato(b, ni, verso);
    }
    document.addEventListener('touchend', fine);
    document.addEventListener('touchcancel', function () { box = null; });
})();

function fcRenderExampleBox(box, examples) {
    box.classList.add('qa-examples-box-filled');
    box.setAttribute('data-fc-examples', JSON.stringify(examples));
    box._fcExamples = examples;
    box._fcIndex = 0;
    fcDisegnaEsempio(box);
}
