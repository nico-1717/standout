/* fc_improve_card.js — "Genera con l'IA" / "Migliora con l'IA" per una
 * carta sola. Usato da fc_insert_card.php e fc_card_edit.php.
 *
 * Non carica fc_ai_client.js: quel file porta con se' tutta la logica per
 * elenchi di concetti, densita', estratti di documento — roba pensata per
 * generare MOLTE carte insieme, che qui non serve. La chiamata a fc_ai.php
 * (azione "migliora", la stessa che usano le pagine "big" per il pulsante
 * per-riga) e' scritta qui sotto, piccola e autosufficiente.
 *
 * COME SI USA
 *   fcMigliora.inizializza({ campoQ: 'quest', campoA: 'answ' });
 * I due nomi sono quelli che format_toolbar.php usa per $ft_field: lo stesso
 * nome compare nell'id del campo nascosto e nell'attributo
 * data-field="..." del wrapper .fmt-editor-wrap.
 */
var fcMigliora = (function () {
    'use strict';

    var campoQ = 'quest', campoA = 'answ';
    var esempioGenerato = null; // {testo} se l'ultima generazione ha prodotto un esempio, altrimenti null

    function el(id) { return document.getElementById(id); }

    function wrapDi(campo) {
        return document.querySelector('.fmt-editor-wrap[data-field="' + campo + '"]');
    }

    function leggiGrezzo(campo) {
        var wrap = wrapDi(campo);
        if (wrap) {
            var hidden = wrap.querySelector('input[type="hidden"]');
            if (hidden) return hidden.value || '';
        }
        var input = el(campo);
        return input ? (input.value || '') : '';
    }

    function scriviGrezzo(campo, rawText) {
        var wrap = wrapDi(campo);
        if (wrap && typeof ftRender === 'function' && typeof fcdParse === 'function') {
            ftRender(wrap, fcdParse(rawText || ''));
            if (typeof ftMathRefresh === 'function') ftMathRefresh(wrap);
            return;
        }
        var input = el(campo);
        if (input) input.value = rawText || '';
    }

    /* Stesso testoPiano() di fc_ai_panel.js: il testo semplice, senza tag
       di formattazione ne' JSON del documento — quello che serve per capire
       se un campo e' vuoto, per l'anteprima, e per mandare all'IA (che
       legge testo leggibile, non la nostra rappresentazione interna). */
    function testoPiano(raw) {
        if (!raw) return '';
        if (typeof fcdParse !== 'function' || typeof fcdBlockText !== 'function') return String(raw);
        try {
            var doc = fcdParse(raw);
            return (doc.blocks || []).map(function (b) { return fcdBlockText(b); }).join('\n').trim();
        } catch (e) {
            return String(raw);
        }
    }

    function tronca(s, max) {
        s = String(s || '');
        return s.length > max ? s.slice(0, max) + '…' : s;
    }

    /* La stessa chiamata che usa fc_ai_client.js, riscritta qui piccola:
       corpo JSON, azione nel corpo (non nell'indirizzo). */
    async function chiamaAi(dati) {
        var r = await fetch('fc_ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify(Object.assign({ azione: 'migliora' }, dati))
        });
        var j = null;
        try { j = await r.json(); } catch (e) { /* gestito sotto */ }
        if (!j) throw { codice: 'rete' };
        if (!j.ok) throw Object.assign({ codice: j.error || 'errore' }, j);
        return j;
    }

    /* Il pulsante non cambia piu' testo fra "genera" e "migliora": negli
       esempi e' una pastiglia fissa che dice solo "AI", e questa deve essere
       identica. Cosa fara' davvero lo decide il riquadro quando si apre,
       guardando se la carta e' gia' piena. */
    /* Il pulsante e' un cerchio con un'icona: non ha piu' un testo da
       aggiornare. Cosa fara' davvero lo decide il riquadro quando si apre,
       guardando se la carta e' gia' piena. */
    function aggiornaTestoBottone() {}

    function apri() {
        var q = testoPiano(leggiGrezzo(campoQ));
        var a = testoPiano(leggiGrezzo(campoA));
        var rispostaVuota = a.trim() === '';
        var domandaVuota = q.trim() === '';
        var stadio = rispostaVuota ? (domandaVuota ? 'vuota' : 'genera') : 'modifica';

        /* Niente anteprima della carta: il riquadro si apre nel flusso della
           pagina, con i campi veri visibili subito sotto — ricopiarne il
           testo qui dentro sarebbe solo rumore. */
        el('fcMiglioraTit').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_titolo_modifica')
            : stadio === 'genera' ? T('fc.ibfai_migliora_titolo_genera')
            : T('fc.ibfai_migliora_titolo_vuota');
        el('fcMiglioraSub').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_sub_modifica')
            : stadio === 'genera' ? T('fc.ibfai_migliora_sub_genera')
            : T('fc.ibfai_migliora_sub_vuota');
        el('fcMiglioraChips').hidden = stadio !== 'modifica';
        document.querySelectorAll('#fcMiglioraChips .fcm-chip').forEach(function (c) { c.classList.remove('is-on'); });

        el('fcMiglioraNota').value = '';
        el('fcMiglioraNota').placeholder = stadio === 'vuota' ? T('fc.ibfai_migliora_nota_ph_vuota')
            : stadio === 'genera' ? T('fc.ibfai_migliora_nota_ph_genera')
            : T('fc.ibfai_migliora_nota_ph_default');
        el('fcMiglioraSi').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_bottone') : T('fc.ibfai_genera_bottone');

        el('fcMiglioraEsempio').checked = false;
        el('fcMiglioraErrore').hidden = true;

        /* Il pulsante non si nasconde piu': sta sempre in basso a destra, e a
           comparire e' un popup sopra il modulo. Cosi' la pagina sotto non si
           riorganizza ogni volta che si apre o si chiude l'IA. */
        el('fcMiglioraOverlay').hidden = false;
        document.body.classList.add('fcm-aperto');
        el('fcMiglioraNota').focus();
    }

    function chiudi() {
        el('fcMiglioraOverlay').hidden = true;
        document.body.classList.remove('fcm-aperto');
    }

    function mostraErrore(e) {
        var box = el('fcMiglioraErrore');
        box.hidden = false;
        if (e && e.codice === 'limite_piano') {
            box.textContent = T('fc.ibfai_limite_dettaglio_breve', {stima: e.stima, disponibili: (e.disponibili === null ? '∞' : e.disponibili)});
        } else if (e && e.codice === 'serve_indicazione') {
            box.textContent = T('fc.ibfai_serve_indicazione');
        } else {
            box.textContent = T('fc.ibfai_errore_ia');
        }
    }

    async function conferma() {
        var q = testoPiano(leggiGrezzo(campoQ));
        var a = testoPiano(leggiGrezzo(campoA));
        var nota = el('fcMiglioraNota').value.trim();
        var esempio = el('fcMiglioraEsempio').checked;

        if (q.trim() === '' && a.trim() === '' && nota === '') {
            mostraErrore({ codice: 'serve_indicazione' });
            return;
        }

        var btn = el('fcMiglioraSi');
        var testoBtn = btn.textContent;
        btn.disabled = true;
        btn.textContent = T('fc.ibfai_un_momento');
        el('fcMiglioraErrore').hidden = true;

        try {
            var risultato = await chiamaAi({
                domanda: q, risposta: a, nota: nota,
                opzioni: { esempi: esempio, lingua: 'italiano' }
            });
            var carta = risultato.carta;
            scriviGrezzo(campoQ, carta.q);
            scriviGrezzo(campoA, carta.a);
            esempioGenerato = (esempio && carta.e) ? { testo: carta.e } : null;

            chiudi();
            aggiornaTestoBottone();
            if (typeof showToast === 'function') showToast(T('fc.ibfai_carta_aggiornata'));

            /* Un esempio generato insieme alla carta non ha ancora un id a
               cui agganciarsi (fc_study_examples vuole l'id della carta, che su
               una carta nuova non esiste finche' non si salva): si tiene da
               parte e si propone di aggiungerlo DOPO che la pagina che ci
               ospita ha salvato — e' lei a sapere quando succede. */
            if (esempioGenerato && typeof fcMiglioraEsempioPronto === 'function') {
                fcMiglioraEsempioPronto(esempioGenerato.testo);
            }
        } catch (e) {
            mostraErrore(e);
        } finally {
            btn.disabled = false;
            btn.textContent = testoBtn;
        }
    }

    /* Sposta il pulsante e il suo popup a figli diretti di <body>. Non e' un
       capriccio: position:fixed va storto se un QUALSIASI antenato ha
       transform, filter o backdrop-filter (crea un nuovo "contenitore" e il
       fixed si ancora a quello invece che alla finestra) — ed e' esattamente
       il sintomo visto (il pulsante compariva in alto invece che in basso a
       destra). Nei fogli di questa pagina non ne ho trovato uno responsabile,
       ma la pagina riceve anche markup iniettato da file che non ho mai
       visto (pwa_head.php, la barra del pomodoro, aggiunti da config.php a
       OGNI pagina del sito): invece di continuare a cercare alla cieca,
       tolgo il problema alla radice. Appeso direttamente a <body>, il
       pulsante non ha piu' nessun antenato che possa interferire, chiunque
       esso sia. */
    function inizializza(opz) {
        opz = opz || {};
        campoQ = opz.campoQ || 'quest';
        campoA = opz.campoA || 'answ';

        /* Il pulsante resta dove nasce nel markup, dentro .page.fc-page-rel:
           niente piu' spostamenti a document.body ne' canale con la finestra
           che lo ospita. Serviva perche' il pulsante compariva fuori posto —
           ma la causa vera, trovata poi sul suo equivalente per gli esempi
           (#ex-ai-fab in fc_examples_modal.css), era un contenitore intermedio
           con position:relative di troppo: piu' vicino nel DOM, ancorava lui
           il pulsante invece di .page. Sistemato quello (fc_ai_fab.css e
           .fc-page-rel in fc_card_edit.css), position:absolute
           funziona correttamente anche dentro un popup annidato in un
           iframe, senza bisogno di farlo vivere altrove. */

        aggiornaTestoBottone();
        /* Il testo del pulsante segue quello che si scrive: ogni input nella
           pagina lo ricalcola, non solo i due campi domanda/risposta — e'
           una spesa piccola, e cosi' non serve agganciare un ascoltatore
           mirato che rischia di perderne uno. */
        document.addEventListener('input', aggiornaTestoBottone);

        el('fcMiglioraSi').addEventListener('click', conferma);
        el('fcMiglioraOverlay').addEventListener('click', function (ev) {
            if (ev.target === el('fcMiglioraOverlay')) chiudi();
        });
        document.querySelectorAll('#fcMiglioraChips .fcm-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                el('fcMiglioraNota').value = chip.getAttribute('data-nota');
                document.querySelectorAll('#fcMiglioraChips .fcm-chip').forEach(function (c) { c.classList.remove('is-on'); });
                chip.classList.add('is-on');
            });
        });
    }

    return {
        inizializza: inizializza,
        apri: apri,
        chiudi: chiudi,
        /* L'esempio pronto ma non ancora salvato, se ce n'e' uno: la pagina
           che ospita questo componente lo legge dopo il proprio salvataggio,
           per proporre di aggiungerlo alla carta appena ottenuto un id. */
        esempioInSospeso: function () { return esempioGenerato; },
        scartaEsempioInSospeso: function () { esempioGenerato = null; }
    };
})();
