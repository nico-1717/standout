/* fc_vocab_examples.js — GLI ESEMPI DI UNA PAROLA, NEL SUO MODULO
 * (fc_card_edit.php, solo lingue: ?course=N)
 *
 * Gli esempi uno sotto l'altro, e in ognuno la parte che nel gioco diventa lo
 * spazio da indovinare, in evidenza:
 *   - se la parola della carta compare uguale nella frase, e' segnata da sola
 *     (fcvLacuna in fc_examples_modal.js, gemella di lib/fc/fc_vocab_gap.php);
 *   - se no (verbo coniugato, plurale, frase riformulata) c'e' il tasto
 *     «Scegli cosa nascondere»: le parole della frase diventano tasti, se ne
 *     toccano una o piu' di fila, e quel pezzo si salva in gap_text.
 *
 * Prima che la carta esista (creazione) gli esempi restano qui e partono con
 * lei, come campi del modulo (examples[] / examples_gap[]). Dopo, ogni
 * aggiunta, correzione o eliminazione si salva subito con fc_examples_api.php
 * — la stessa strada del pannello degli esempi.
 *
 * Lo usa lo script di fc_card_edit.php con:
 *   fcvEsempi.aggiungi(testo, daIA)   un esempio nuovo in fondo (l'IA, «Compila»)
 *   fcvEsempi.primaDelSalvataggio()   la frase scritta ma non aggiunta parte con la carta
 *   fcvEsempi.cartaCreata()           la carta ora ha un id: si rilegge dal database
 *   fcvEsempi.azzera()                «Inserisci un'altra»: elenco vuoto
 *   fcvEsempi.ricarica()              dopo il pannello completo (immagine, audio)
 *   fcvEsempi.primo() / conta()
 *
 * Legge FC_ID_CORRENTE, FC_TIPO e fcSporco, che sono le variabili globali
 * dello script del modulo: cambiano durante la vita della pagina (una carta
 * nuova nasce con id 0), quindi si leggono ogni volta e non una volta sola.
 */
(function () {
    'use strict';

    var sez, lista, vuoto, nuovo, bAdd, campi, conta, nota;
    var voci = [];      // { id, testo, gap, img, aud, stato: ''|'scelta'|'modifica', sel: [i, j] | null, nuova }
    var avviato = false;

    function $(id) { return document.getElementById(id); }
    function tr(k, ripiego) {
        var v = (typeof T === 'function') ? T(k) : '';
        return (v && v !== k) ? v : (ripiego || k);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function cartaId() { return (typeof FC_ID_CORRENTE !== 'undefined') ? (parseInt(FC_ID_CORRENTE, 10) || 0) : 0; }
    function cartaTipo() { return (typeof FC_TIPO !== 'undefined' && FC_TIPO) ? FC_TIPO : 'parent'; }
    function inSospeso() { return !cartaId(); }
    function parola() { var w = $('fcWord'); return w ? w.value.trim() : ''; }
    function lacuna(v) { return typeof fcvLacuna === 'function' ? fcvLacuna(v.testo, parola(), v.gap) : null; }

    /* Gli esempi in attesa fanno parte della carta da creare: toccarli e' una
       modifica della carta (pallino arancione, salvataggio automatico). */
    function cartaSporca() {
        if (!inSospeso()) return;
        try { fcSporco = true; } catch (e) {}
        if (window.StandoutSaveBar) StandoutSaveBar.touch('scheda');
    }

    /* ---------- le parole di una frase, per sceglierne un pezzo ----------
       Una parola e' una fila di lettere/cifre (anche con l'apostrofo dentro:
       "don't" e' una sola). Tutto il resto (spazi, virgole) sta in mezzo. */
    var RE_PAROLA = /[\p{L}\p{N}\p{M}_]+(?:['’][\p{L}\p{N}\p{M}]+)*/gu;
    function pezzi(testo) {
        var out = [], m, ultimo = 0;
        RE_PAROLA.lastIndex = 0;
        while ((m = RE_PAROLA.exec(testo)) !== null) {
            if (m.index > ultimo) out.push({ t: testo.slice(ultimo, m.index), parola: false });
            out.push({ t: m[0], parola: true, da: m.index, a: m.index + m[0].length });
            ultimo = m.index + m[0].length;
        }
        if (ultimo < testo.length) out.push({ t: testo.slice(ultimo), parola: false });
        var n = 0;
        out.forEach(function (p) { if (p.parola) p.k = n++; });
        return out;
    }

    /* ---------- disegno ---------- */
    var IC = {
        occhio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 9.5 7 9.5 7a17 17 0 0 1-2.6 3.4M6.3 6.3C3.8 8 2.5 12 2.5 12S6 19 12 19a9.5 9.5 0 0 0 4.4-1.1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>',
        mirino: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M8 12h8"/></svg>',
        voce: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg>',
        matita: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg>',
        cestino: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>',
        freccia: '<svg class="fcv-ex-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>',
        media: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-8 9"/></svg>'
    };

    /* La frase con la parte nascosta in evidenza. Una vecchia fila di _ si
       riempie con la parola, come nel resto del sito. */
    function fraseHtml(v) {
        var w = parola();
        var riempi = function (s) {
            return esc(s).replace(/_+/g, function () { return '<mark class="fcv-ex-gap">' + (w ? esc(w) : '&hellip;') + '</mark>'; });
        };
        var l = lacuna(v);
        if (!l) return riempi(v.testo);
        var dentro = l.tipo === 'trattini' ? (w ? esc(w) : '&hellip;') : esc(v.testo.slice(l.da, l.a));
        return riempi(v.testo.slice(0, l.da)) + '<mark class="fcv-ex-gap">' + dentro + '</mark>' + riempi(v.testo.slice(l.a));
    }

    function vocePronta(v, i) {
        var l = lacuna(v);
        var w = parola();
        var daSentire = v.testo.replace(/_+/g, w || '');
        var lingua = sez.getAttribute('data-lingua') || '';
        var media = '';
        if (v.img) media += '<img class="fcv-ex-img" src="../media/example_images/' + esc(v.img) + '" alt="" loading="lazy">';
        if (v.aud) media += '<audio class="fcv-ex-aud" src="../media/flashcard_audio/' + esc(v.aud) + '" controls preload="none"></audio>';
        /* Una pastiglia sola, che si tocca per cambiare la scelta: "Nascosta
           nel gioco" (con «auto» se l'ha trovata da sola) o, se non c'e'
           niente da nascondere, il tasto arancione per sceglierlo. */
        var stato = !v.testo.trim() ? '' : l
            ? '<button type="button" class="fcv-ex-stato" data-az="scegli" aria-label="' + esc(tr('fcv.es_nascosta', 'Hidden in the game') + ' — ' + tr('fcv.es_cambia', 'Change')) + '">'
              + IC.occhio + '<span class="fcv-ex-stato-t">' + esc(tr('fcv.es_nascosta', 'Hidden in the game')) + '</span>'
              /* «auto» al posto della freccia: due segni insieme non ci stanno */
              + ((l.tipo === 'parola' || l.tipo === 'simile') ? '<em>' + esc(tr('fcv.es_auto', 'auto')) + '</em>' : IC.freccia) + '</button>'
            : '<button type="button" class="fcv-ex-scegli" data-az="scegli">' + IC.mirino
              + '<span>' + esc(tr('fcv.es_scegli', 'Choose what to hide')) + '</span></button>';
        return '<li class="fcv-ex-item' + (l || !v.testo.trim() ? '' : ' is-senza') + (v.nuova ? ' is-nuova' : '') + '" data-i="' + i + '">'
            + (v.testo.trim() ? '<p class="fcv-ex-frase" lang="' + esc(lingua) + '">' + fraseHtml(v) + '</p>' : '')
            + (media ? '<div class="fcv-ex-media">' + media + '</div>' : '')
            + '<div class="fcv-ex-piede">'
            +   '<div class="fcv-ex-stato-riga">' + stato + '</div>'
            +   '<div class="fcv-ex-azioni">'
            +     '<button type="button" class="fcv-ex-ico" data-speak="learn" data-text="' + esc(daSentire) + '" aria-label="' + esc(tr('fcv.ascolta', 'Listen')) + '">' + IC.voce + '</button>'
            +     '<button type="button" class="fcv-ex-ico" data-az="modifica" aria-label="' + esc(tr('comune.modifica', 'Edit')) + '">' + IC.matita + '</button>'
            +     '<button type="button" class="fcv-ex-ico is-rosso" data-az="elimina" aria-label="' + esc(tr('comune.elimina', 'Delete')) + '">' + IC.cestino + '</button>'
            +   '</div>'
            + '</div>'
            + '</li>';
    }

    function voceScelta(v, i) {
        var lingua = sez.getAttribute('data-lingua') || '';
        var p = pezzi(v.testo), sel = v.sel;
        var dentro = function (k) { return sel && k >= sel[0] && k <= sel[1]; };
        var html = '', prec = -1;
        p.forEach(function (x, n) {
            if (x.parola) {
                var on = dentro(x.k);
                var cls = 'fcv-ex-tok' + (on ? ' is-on' : '')
                        + (on && x.k === sel[0] ? ' is-inizio' : '') + (on && x.k === sel[1] ? ' is-fine' : '');
                html += '<button type="button" class="' + cls + '" data-k="' + x.k + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(x.t) + '</button>';
                prec = x.k;
            } else {
                /* lo spazio fra due parole scelte si colora anche lui: la parte
                   nascosta si vede come un pezzo solo, com'e' nel gioco */
                var fra = sel && dentro(prec) && dentro(prec + 1) && p[n + 1] && p[n + 1].parola;
                html += '<span class="fcv-ex-sep' + (fra ? ' is-on' : '') + '">' + esc(x.t) + '</span>';
            }
        });
        return '<li class="fcv-ex-item is-scelta" data-i="' + i + '">'
            + '<p class="fcv-ex-guida">' + IC.mirino + '<span>' + esc(tr('fcv.es_guida', 'Tap the words to hide in the game')) + '</span></p>'
            + '<p class="fcv-ex-parole" lang="' + esc(lingua) + '">' + html + '</p>'
            + '<div class="fcv-ex-barra">'
            +   '<button type="button" class="fcv-ex-btn is-piano" data-az="azzera"' + (sel ? '' : ' disabled') + '>' + esc(tr('fcv.es_azzera', 'Clear')) + '</button>'
            +   '<span class="fcv-ex-spazio"></span>'
            +   '<button type="button" class="fcv-ex-btn" data-az="annulla">' + esc(tr('comune.annulla', 'Cancel')) + '</button>'
            +   '<button type="button" class="fcv-ex-btn is-primo" data-az="fatto">' + esc(tr('fcv.es_fatto', 'Done')) + '</button>'
            + '</div>'
            + '</li>';
    }

    function voceModifica(v, i) {
        var lingua = sez.getAttribute('data-lingua') || '';
        return '<li class="fcv-ex-item is-modifica" data-i="' + i + '">'
            + '<textarea class="fcv-ex-area" rows="2" maxlength="1000" lang="' + esc(lingua) + '">' + esc(v.testo) + '</textarea>'
            + '<div class="fcv-ex-barra">'
            +   (v.id ? '<button type="button" class="fcv-ex-btn is-piano" data-az="media">' + IC.media + '<span>' + esc(tr('fcv.es_media', 'Image & audio')) + '</span></button>' : '')
            +   '<span class="fcv-ex-spazio"></span>'
            +   '<button type="button" class="fcv-ex-btn" data-az="annulla">' + esc(tr('comune.annulla', 'Cancel')) + '</button>'
            +   '<button type="button" class="fcv-ex-btn is-primo" data-az="salva">' + esc(tr('comune.salva', 'Save')) + '</button>'
            + '</div>'
            + '</li>';
    }

    function disegna() {
        if (!avvia()) return;
        lista.innerHTML = voci.map(function (v, i) {
            return v.stato === 'scelta' ? voceScelta(v, i) : v.stato === 'modifica' ? voceModifica(v, i) : vocePronta(v, i);
        }).join('');
        voci.forEach(function (v) { v.nuova = false; });
        vuoto.hidden = voci.length > 0;
        conta.textContent = voci.length ? String(voci.length) : '';
        nota.hidden = !inSospeso();
        sincronizzaCampi();
        var area = lista.querySelector('.fcv-ex-area');
        if (area) { area.focus(); area.setSelectionRange(area.value.length, area.value.length); cresci(area); }
    }

    /* In creazione gli esempi viaggiano col modulo della carta. */
    function sincronizzaCampi() {
        if (!campi) return;
        campi.innerHTML = '';
        if (!inSospeso()) return;
        voci.forEach(function (v) {
            if (!v.testo.trim()) return;
            [['examples[]', v.testo], ['examples_gap[]', v.gap || '']].forEach(function (c) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = c[0]; inp.value = c[1];
                campi.appendChild(inp);
            });
        });
    }

    /* ---------- il server ---------- */
    function chiedi(dati) {
        var fd = new FormData();
        Object.keys(dati).forEach(function (k) { fd.append(k, dati[k]); });
        var meta = document.querySelector('meta[name="csrf-token"]');
        fd.append('csrf_token', meta ? meta.content : '');
        return fetch(fcExApi(''), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j || !j.success) throw new Error((j && j.error) || 'errore'); return j; });
    }
    /* Un esempio appena aggiunto non ha ancora un id finche' il server non
       risponde: una scelta fatta in quel mezzo secondo aspetta la prima
       risposta, altrimenti partirebbe come un secondo esempio nuovo. */
    function salva(v, conLacuna) {
        if (!v.id && v.attesa) return v.attesa.then(function () { return salva(v, conLacuna); });
        var dati = { action: 'save', fc_id: cartaId(), fc_type: cartaTipo(), ex_id: v.id || 0, text: v.testo };
        if (conLacuna) dati.gap_text = v.gap || '';
        var p = chiedi(dati).then(function (j) { if (j.ex_id) v.id = parseInt(j.ex_id, 10) || v.id; v.attesa = null; return v; });
        if (!v.id) v.attesa = p.catch(function () { v.attesa = null; });
        return p;
    }
    function errore() { alert(tr('fcv.es_errore', "Couldn't save the example. Try again.")); }

    function ricarica() {
        if (!avvia()) return;
        if (inSospeso()) { disegna(); return; }
        fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(cartaId()) + '&fc_type=' + encodeURIComponent(cartaTipo()), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) return;
                voci = (d.examples || []).map(function (e) {
                    return { id: parseInt(e.id, 10) || 0, testo: String(e.example_text || ''), gap: String(e.gap_text || ''),
                             img: String(e.example_image || ''), aud: String(e.example_audio || ''), stato: '' };
                }).filter(function (v) { return v.testo.trim() || v.img || v.aud; });
                disegna();
            })
            .catch(function () {});
    }

    /* ---------- le azioni ---------- */
    function aggiungi(testo, daIA) {
        if (!avvia()) return;
        testo = String(testo || '').trim();
        if (!testo) return;
        if (daIA && voci.some(function (v) { return v.testo.trim() === testo; })) return;
        var v = { id: 0, testo: testo, gap: '', img: '', aud: '', stato: '', nuova: true };
        voci.push(v);
        if (inSospeso()) { cartaSporca(); disegna(); return; }
        disegna();
        salva(v, false).catch(function () {
            voci.splice(voci.indexOf(v), 1); disegna(); errore();
        });
    }

    function scegli(v) {
        voci.forEach(function (x) { if (x !== v && x.stato) x.stato = ''; });
        /* si parte dalla parte gia' nascosta, se c'e' */
        v.sel = null;
        var l = lacuna(v);
        if (l && l.tipo !== 'trattini') {
            var dentro = pezzi(v.testo).filter(function (p) { return p.parola && p.da < l.a && p.a > l.da; });
            if (dentro.length) v.sel = [dentro[0].k, dentro[dentro.length - 1].k];
        }
        v.stato = 'scelta';
        disegna();
    }

    /* Un tocco su una parola:
       - nessuna scelta ancora → quella parola;
       - fuori dalla scelta → la scelta si allunga fino a lei (le parti
         nascoste sono sempre un pezzo unico, come lo spazio del gioco);
       - dentro una scelta di piu' parole → si riparte da quella sola;
       - sull'unica scelta → si toglie. */
    function tocca(v, k) {
        var s = v.sel;
        if (!s) v.sel = [k, k];
        else if (k < s[0]) v.sel = [k, s[1]];
        else if (k > s[1]) v.sel = [s[0], k];
        else if (s[0] === s[1]) v.sel = null;
        else v.sel = [k, k];
        disegna();
    }

    function conferma(v) {
        var gap = '';
        if (v.sel) {
            var p = pezzi(v.testo).filter(function (x) { return x.parola; });
            var a = p[v.sel[0]], b = p[v.sel[1]];
            if (a && b) gap = v.testo.slice(a.da, b.a);
        }
        /* se e' proprio la parola della carta, non serve ricordarla: resta
           "automatica" e segue la parola se un giorno la si corregge */
        var auto = typeof fcvLacuna === 'function' ? fcvLacuna(v.testo, parola(), '') : null;
        if (gap && auto && (auto.tipo === 'parola' || auto.tipo === 'simile') && v.testo.slice(auto.da, auto.a) === gap) gap = '';
        var prima = v.gap;
        v.gap = gap;
        v.stato = ''; v.sel = null;
        disegna();
        if (inSospeso()) { cartaSporca(); return; }
        salva(v, true).catch(function () { v.gap = prima; disegna(); errore(); });
    }

    function salvaTesto(v, li) {
        var area = li.querySelector('.fcv-ex-area');
        var testo = area ? area.value.trim() : v.testo;
        if (!testo) { elimina(v, true); return; }
        var prima = v.testo;
        v.testo = testo; v.stato = '';
        disegna();
        if (inSospeso()) { cartaSporca(); return; }
        if (testo === prima) return;
        /* la parte scelta resta: se nella frase nuova c'e' ancora, vale ancora */
        salva(v, false).catch(function () { v.testo = prima; disegna(); errore(); });
    }

    function elimina(v, senzaChiedere) {
        if (!senzaChiedere && !confirm(tr('fcv.es_elimina_conf', 'Delete this example?'))) return;
        var i = voci.indexOf(v);
        if (i === -1) return;
        voci.splice(i, 1);
        disegna();
        if (inSospeso()) { cartaSporca(); return; }
        /* ancora in viaggio verso il server: si elimina appena ha un id */
        var dopo = (!v.id && v.attesa) ? v.attesa : Promise.resolve();
        dopo.then(function () {
            if (!v.id) return;
            return chiedi({ action: 'delete', ex_id: v.id });
        }).catch(function () { voci.splice(i, 0, v); disegna(); errore(); });
    }

    /* Immagine e audio dell'esempio: il pannello completo, che sa gia' farlo. */
    function apriMedia(v) {
        if (!v.id || typeof openExamplesModal !== 'function') return;
        v.stato = ''; disegna();
        var tradu = $('fcTranslation');
        openExamplesModal(cartaId(), cartaTipo(), parola(), tradu ? tradu.value : '');
        if (typeof openExampleForm === 'function') {
            setTimeout(function () { openExampleForm(cartaId(), cartaTipo(), v.id); }, 260);
        }
    }

    function cresci(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }

    /* ---------- avvio ---------- */
    function avvia() {
        if (avviato) return true;
        sez = $('fcvEsempi');
        if (!sez) return false;
        lista = $('fcvExList'); vuoto = $('fcvExVuoto'); nuovo = $('fcvExNuovo');
        bAdd = $('fcvExAdd'); campi = $('fcvExCampi'); conta = $('fcvExConta'); nota = $('fcvExNota');
        avviato = true;

        try {
            var dati = JSON.parse(($('fcvExDati') || {}).textContent || '[]');
            voci = (dati || []).map(function (e) {
                return { id: e.id || 0, testo: String(e.testo || ''), gap: String(e.gap || ''), img: e.img || '', aud: e.aud || '', stato: '' };
            });
        } catch (e) { voci = []; }

        lista.addEventListener('click', function (e) {
            var li = e.target.closest('.fcv-ex-item');
            if (!li) return;
            var v = voci[parseInt(li.getAttribute('data-i'), 10)];
            if (!v) return;
            var tok = e.target.closest('.fcv-ex-tok');
            if (tok) { tocca(v, parseInt(tok.getAttribute('data-k'), 10)); return; }
            var b = e.target.closest('[data-az]');
            if (!b) return;
            switch (b.getAttribute('data-az')) {
                case 'scegli':   scegli(v); break;
                case 'azzera':   v.sel = null; disegna(); break;
                case 'annulla':  v.stato = ''; v.sel = null; disegna(); break;
                case 'fatto':    conferma(v); break;
                case 'modifica': voci.forEach(function (x) { x.stato = ''; }); v.stato = 'modifica'; disegna(); break;
                case 'salva':    salvaTesto(v, li); break;
                case 'elimina':  elimina(v, false); break;
                case 'media':    apriMedia(v); break;
            }
        });
        lista.addEventListener('input', function (e) { if (e.target.classList.contains('fcv-ex-area')) cresci(e.target); });
        lista.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && e.target.classList.contains('fcv-ex-area')) {
                var v = voci[parseInt(e.target.closest('.fcv-ex-item').getAttribute('data-i'), 10)];
                if (v) { v.stato = ''; disegna(); }
            }
        });

        function puoiAggiungere() { bAdd.disabled = !nuovo.value.trim(); }
        function daNuovo() {
            var t = nuovo.value.trim();
            if (!t) return;
            nuovo.value = ''; cresci(nuovo); puoiAggiungere();
            aggiungi(t, false);
        }
        nuovo.addEventListener('input', function () { cresci(nuovo); puoiAggiungere(); });
        /* Invio aggiunge (una frase d'esempio non va a capo); Maiusc+Invio va a capo lo stesso */
        nuovo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); daNuovo(); }
        });
        bAdd.addEventListener('click', daNuovo);

        /* La parola cambia → cambia anche quello che e' segnato da solo. */
        var w = $('fcWord'), attesa = null;
        if (w) w.addEventListener('input', function () {
            clearTimeout(attesa);
            attesa = setTimeout(function () {
                if (!voci.some(function (v) { return v.stato; })) disegna();
            }, 250);
        });

        disegna();
        return true;
    }

    window.fcvEsempi = {
        aggiungi: aggiungi,
        ricarica: ricarica,
        primaDelSalvataggio: function () {
            if (!avvia() || !inSospeso()) return;
            var t = nuovo.value.trim();
            if (t) { nuovo.value = ''; cresci(nuovo); bAdd.disabled = true; voci.push({ id: 0, testo: t, gap: '', img: '', aud: '', stato: '' }); }
            /* una scelta o una correzione lasciata a meta' vale come chiusa */
            voci.forEach(function (v) { v.stato = ''; v.sel = null; });
            disegna();
        },
        cartaCreata: function () { if (!avvia()) return; voci = []; ricarica(); },
        azzera: function () {
            if (!avvia()) return;
            voci = []; nuovo.value = ''; cresci(nuovo); bAdd.disabled = true;
            disegna();
        },
        primo: function () {
            if (!avvia()) return '';
            var v = voci.filter(function (x) { return x.testo.trim(); })[0];
            return v ? v.testo : nuovo.value.trim();
        },
        conta: function () { return avvia() ? voci.length : 0; }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
    else avvia();
})();
