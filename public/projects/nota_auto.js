/* nota_auto.js — le note dei progetti si salvano da sole.

   Cosa fa:
   - disegna una nota (formato dell'editor) dentro un elemento: una sola
     funzione, usata da tutti i posti che la mostrano, cosi' dopo un
     salvataggio non si vede mai il testo grezzo {"v":2,"blocks":…};
   - salva da solo mentre si scrive (mezzo secondo dopo l'ultimo tasto), senza
     tasti Salva e Annulla;
   - tiene una pila di versioni, per il tasto «Annulla»;
   - funziona anche senza rete: ogni modifica entra PRIMA in una coda sul
     telefono (localStorage) e poi parte. Se la rete non c'e' resta li', un
     avviso lo dice, e riparte da sola appena torna (o alla prossima apertura
     della pagina). Vale l'ultima versione di ogni nota.

   Dipende da fc_parser.js (parseTextForPreview, fcdDisegnaFormule, fcdParse) e
   da format_toolbar.php (ftRender) — tutti caricati dalla pagina prima. */
(function () {
    'use strict';

    var CHIAVE = 'pr_nota_coda';
    var URL_SALVA = 'progetti.php?ajax=salva_nota';
    var RITARDO = 600;

    function T(k, p) { return (window.T ? window.T(k, p) : k); }

    /* ---------------- la coda sul dispositivo ---------------- */
    function leggiCoda() {
        try { return JSON.parse(localStorage.getItem(CHIAVE) || '{}') || {}; } catch (e) { return {}; }
    }
    function scriviCoda(c) {
        try { localStorage.setItem(CHIAVE, JSON.stringify(c)); } catch (e) { }
    }
    function inCoda(id) { var c = leggiCoda(); return c[id] ? c[id] : null; }
    function mettiInCoda(id, testo) {
        var c = leggiCoda(); var ts = Date.now();
        c[id] = { note: testo, ts: ts };
        scriviCoda(c);
        return ts;
    }
    /* si toglie solo se nel frattempo non e' arrivata una versione piu' nuova */
    function togliDallaCoda(id, ts) {
        var c = leggiCoda();
        if (c[id] && c[id].ts === ts) { delete c[id]; scriviCoda(c); }
    }
    function quanteInCoda() { return Object.keys(leggiCoda()).length; }

    /* ---------------- l'avviso «sei offline» ---------------- */
    var pillola = null;
    function aggiornaPillola() {
        var offline = window.navigator && navigator.onLine === false;
        var n = quanteInCoda();
        if (!offline && !n) { if (pillola) pillola.classList.remove('show'); return; }
        if (!pillola) {
            pillola = document.createElement('div');
            pillola.className = 'pr-offline';
            pillola.setAttribute('role', 'status');
            document.body.appendChild(pillola);
        }
        pillola.textContent = offline
            ? (n ? T('progetti.nota_offline_n', { n: n }) : T('progetti.offline'))
            : T('progetti.nota_sincronizzo');
        pillola.classList.toggle('is-sync', !offline);
        pillola.classList.add('show');
    }

    /* ---------------- l'invio ---------------- */
    var inVolo = {};
    /* Manda la versione in coda di una nota. Risolve con un oggetto
       { ok, offline, note }: ok = il server l'ha presa. */
    function invia(id) {
        var voce = inCoda(id);
        if (!voce) return Promise.resolve({ ok: true, note: null });
        if (inVolo[id]) return inVolo[id];
        var corpo = new URLSearchParams();
        corpo.set('project_id', id);
        corpo.set('note', voce.note);
        var p = fetch(URL_SALVA, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo.toString()
        }).then(function (r) {
            return r.json().then(function (d) { return { http: r.status, d: d }; });
        }).then(function (x) {
            if (x.d && x.d.ok) {
                togliDallaCoda(id, voce.ts);
                return { ok: true, note: x.d.note, nuovaVersione: !!inCoda(id) };
            }
            /* il server ha risposto ma non ha salvato (sessione scaduta, nota
               non piu' tua…): si tiene in coda e si riprova piu' tardi */
            return { ok: false, offline: false };
        }).catch(function () {
            return { ok: false, offline: true };
        }).then(function (esito) {
            delete inVolo[id];
            aggiornaPillola();
            /* se mentre partiva ne e' stata scritta una piu' nuova, parte anche quella */
            if (esito.ok && esito.nuovaVersione) return invia(id).then(function (e2) { e2.note = e2.note !== null ? e2.note : esito.note; return e2; });
            return esito;
        });
        inVolo[id] = p;
        return p;
    }

    var ascoltatori = [];
    /* Prova a svuotare tutta la coda (rete tornata, pagina riaperta). */
    function svuota() {
        var ids = Object.keys(leggiCoda());
        if (!ids.length) { aggiornaPillola(); return Promise.resolve(); }
        return Promise.all(ids.map(function (id) {
            return invia(id).then(function (e) {
                if (e.ok) ascoltatori.forEach(function (fn) { try { fn(id, e.note); } catch (x) { } });
            });
        }));
    }

    /* ---------------- disegnare una nota ---------------- */
    function disegna(el, raw) {
        if (!el) return;
        el.setAttribute('data-raw', raw || '');
        if (!raw) { el.textContent = ''; return; }
        try {
            el.innerHTML = window.parseTextForPreview(raw);
            if (window.fcdDisegnaFormule) window.fcdDisegnaFormule(el);
        } catch (e) { el.textContent = ''; }
    }

    /* ---------------- l'editor che si salva da solo ---------------- */
    /* opzioni:
         box       il contenitore dell'editor (contiene .fmt-editor-wrap)
         id        () => id del progetto
         iniziale  () => testo grezzo di partenza
         alSalvato (id, testo) => void, dopo ogni salvataggio riuscito
       Restituisce { apri(), chiudi(), valore() }. */
    function collega(o) {
        var box = o.box;
        var wrap = box.querySelector('.fmt-editor-wrap');
        if (!wrap) return null;
        var ed = document.getElementById(wrap.dataset.editorId);
        var campo = document.getElementById(wrap.dataset.field);

        /* la riga sotto l'editor: lo stato a sinistra, «Annulla» a destra */
        var riga = document.createElement('div');
        riga.className = 'pr-nota-stato';
        var stato = document.createElement('span');
        stato.className = 'pr-nota-st';
        stato.setAttribute('role', 'status');
        var annulla = document.createElement('button');
        annulla.type = 'button';
        annulla.className = 'pr-nota-undo';
        annulla.textContent = T('progetti.nota_annulla');
        annulla.disabled = true;
        riga.appendChild(stato); riga.appendChild(annulla);
        box.appendChild(riga);

        var timer = null, pila = [], ultimo = '', nascondi = null, id = 0, aperto = false;

        function valore() {
            if (ed && ed.dataset.vuoto === '1') return '';          // una nota svuotata e' una nota vuota
            return campo ? campo.value : '';
        }
        function mostra(msg, cls) {
            clearTimeout(nascondi);
            stato.textContent = msg;
            stato.className = 'pr-nota-st' + (cls ? ' ' + cls : '');
        }
        /* il cursore in fondo al testo: dopo un ripristino o un'apertura si riprende a scrivere da li' */
        function inFondo() {
            if (!ed) return;
            ed.focus();
            try {
                var r = document.createRange(); r.selectNodeContents(ed); r.collapse(false);
                var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            } catch (e) { }
        }
        function aggiornaUndo() { annulla.disabled = pila.length === 0; }

        function salvaOra() {
            clearTimeout(timer); timer = null;
            if (!aperto) return Promise.resolve();
            var v = valore();
            if (v === ultimo) return Promise.resolve();
            /* una nuova versione entra nella pila di «Annulla» */
            pila.push(ultimo); if (pila.length > 40) pila.shift();
            ultimo = v;
            aggiornaUndo();
            mettiInCoda(id, v);
            mostra(T('progetti.nota_salvo'), '');
            return invia(id).then(function (e) {
                if (e.ok) {
                    mostra(T('progetti.nota_salvata'), 'ok');
                    nascondi = setTimeout(function () { mostra('', ''); }, 1800);
                    if (o.alSalvato) o.alSalvato(id, e.note !== null ? e.note : v);
                } else if (e.offline) {
                    mostra(T('progetti.nota_in_coda'), 'off');
                } else {
                    mostra(T('progetti.nota_errore'), 'err');
                }
            });
        }
        function programma() {
            mostra(T('progetti.nota_salvo'), '');
            clearTimeout(timer);
            timer = setTimeout(salvaOra, RITARDO);
        }

        box.addEventListener('input', programma);
        /* un comando della barra (grassetto, elenco…) genera 'input' da solo,
           ma la dettatura e l'incolla in certi browser no: lo si prende anche
           quando si lascia l'editor */
        box.addEventListener('focusout', function () { if (timer) salvaOra(); });

        annulla.addEventListener('click', function () {
            if (!pila.length) return;
            var prima = pila.pop();
            clearTimeout(timer); timer = null;
            if (window.ftRender && window.fcdParse) {
                window.ftRender(wrap, window.fcdParse(prima || ''), null);
            }
            ultimo = valore();
            /* si salva la versione ripristinata, senza metterla di nuovo in pila */
            mettiInCoda(id, ultimo);
            aggiornaUndo();
            mostra(T('progetti.nota_salvo'), '');
            invia(id).then(function (e) {
                if (e.ok) {
                    mostra(T('progetti.nota_ripristinata'), 'ok');
                    nascondi = setTimeout(function () { mostra('', ''); }, 1800);
                    if (o.alSalvato) o.alSalvato(id, e.note !== null ? e.note : ultimo);
                } else {
                    mostra(e.offline ? T('progetti.nota_in_coda') : T('progetti.nota_errore'), e.offline ? 'off' : 'err');
                }
            });
            inFondo();
        });

        window.addEventListener('pagehide', function () { if (aperto && timer) { mettiInCoda(id, valore()); } });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && aperto && timer) salvaOra();
        });

        return {
            apri: function () {
                id = o.id();
                aperto = true; pila = []; aggiornaUndo(); mostra('', '');
                /* una versione ancora in coda vale piu' di quella scritta dal server */
                var q = inCoda(id);
                var base = q ? q.note : (o.iniziale ? o.iniziale() : '');
                if (window.ftRender && window.fcdParse && base !== valore()) window.ftRender(wrap, window.fcdParse(base || ''), null);
                ultimo = valore();
                inFondo();
            },
            chiudi: function () {
                var p = salvaOra();
                aperto = false;
                return p;
            },
            valore: valore,
            aperto: function () { return aperto; }
        };
    }

    window.addEventListener('online', function () { aggiornaPillola(); svuota(); });
    window.addEventListener('offline', aggiornaPillola);
    /* se la rete c'e' ma il server non rispondeva, si riprova ogni tanto */
    setInterval(function () { if (quanteInCoda() && navigator.onLine !== false) svuota(); }, 20000);

    window.PrNota = {
        disegna: disegna, collega: collega, inCoda: inCoda, svuota: svuota,
        alSincronizzare: function (fn) { ascoltatori.push(fn); },
        aggiornaAvviso: aggiornaPillola
    };
})();
