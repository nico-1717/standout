/* autosave_bar.js — il motore dietro la barra di salvataggio flottante.
 *
 * PERCHE' ESISTE
 * Nelle pagine "big" si costruiscono decine di righe a mano. Finche' il
 * salvataggio era una cosa sola alla fine, bastava un ricaricamento, una
 * connessione caduta o un tocco sbagliato per buttare via mezz'ora di lavoro.
 * Qui il salvataggio diventa una cosa che si puo' fare in qualsiasi momento,
 * e - se l'interruttore e' acceso - che si fa da sola.
 *
 * COME SI USA
 *   NisamaSaveBar.init({ key: 'big_edit_fc', save: saveAll });
 * dove save() e' la funzione della pagina che manda i dati al server e
 * restituisce (anche come Promise) true se e' andata bene, false altrimenti.
 * La pagina chiama NisamaSaveBar.touch() ogni volta che qualcosa cambia.
 *
 * IMPORTANTE: save() non deve ricaricare la pagina. Se lo facesse, il
 * salvataggio automatico diventerebbe un ricaricamento automatico, cioe'
 * esattamente il problema che questo file esiste per togliere.
 */
(function () {
    'use strict';

    var S = {
        save: null,
        chiave: 'nisama_autosave',
        attesa: 1800,      // ms di quiete prima di un salvataggio automatico
        controllo: 25000,  // rete di sicurezza: ogni 25s, se e' rimasto sporco
        sporco: false,
        salvando: false,
        inCoda: false,
        auto: false,
        ultimo: null,
        timer: null,
        pronto: false
    };

    function el(id) { return document.getElementById(id); }

    function dueCifre(n) { return n < 10 ? '0' + n : '' + n; }

    function oraDi(d) {
        return dueCifre(d.getHours()) + ':' + dueCifre(d.getMinutes()) + ':' + dueCifre(d.getSeconds());
    }

    /* Ridisegna la barra a partire dallo stato. Un punto solo: cosi' non
       esistono combinazioni di classi che nessuno ha previsto. */
    function disegna() {
        var barra = el('nsSaveBar');
        if (!barra) return;
        barra.classList.toggle('ns-dirty', S.sporco);
        barra.classList.toggle('ns-saving', S.salvando);

        var btn = el('nsSaveNow');
        if (btn) btn.disabled = S.salvando;

        var etichetta = el('nsLastSaved');
        if (etichetta) {
            if (S.salvando) {
                etichetta.textContent = 'salvo…';
                etichetta.classList.remove('ns-mai');
            } else if (S.ultimo) {
                etichetta.textContent = oraDi(S.ultimo);
                etichetta.classList.remove('ns-mai');
            } else {
                /* Vuoto invece di "mai": prima di un qualunque salvataggio
                   non c'e' niente di utile da dire li', e "mai" suonava piu'
                   allarmante di quanto la situazione richieda — e' solo che
                   non si e' ancora scritto niente. */
                etichetta.textContent = '';
                etichetta.classList.add('ns-mai');
            }
        }
    }

    function programma() {
        clearTimeout(S.timer);
        S.timer = setTimeout(function () { esegui(false); }, S.attesa);
    }

    /* manuale = true quando l'ha chiesto l'utente: in quel caso si salva anche
       se non risulta niente di sporco (male non fa, e toglie il dubbio). */
    function esegui(manuale) {
        if (!S.save) return Promise.resolve(false);
        if (S.salvando) { S.inCoda = true; return Promise.resolve(false); }
        if (!manuale && !S.sporco) return Promise.resolve(false);

        clearTimeout(S.timer);
        S.salvando = true;
        disegna();

        return Promise.resolve()
            .then(function () { return S.save(manuale); })
            .then(function (esito) {
                if (esito !== false) {
                    S.sporco = false;
                    S.ultimo = new Date();
                }
                return esito !== false;
            })
            .catch(function (err) {
                console.error('[autosave] salvataggio fallito', err);
                return false;
            })
            .then(function (ok) {
                S.salvando = false;
                disegna();
                /* Se qualcuno ha scritto mentre salvavamo, quella modifica non
                   e' compresa nel giro appena finito: se ne rifa' uno. */
                if (S.inCoda) {
                    S.inCoda = false;
                    if (S.auto && S.sporco) programma();
                }
                return ok;
            });
    }

    function accendiAuto(acceso, silenzioso) {
        S.auto = !!acceso;
        try { localStorage.setItem(S.chiave, S.auto ? '1' : '0'); } catch (e) { /* modalita' privata: pazienza */ }
        var toggle = el('nsAutoToggle');
        if (toggle) toggle.checked = S.auto;
        if (S.auto && S.sporco) programma(); else clearTimeout(S.timer);
        if (!silenzioso && typeof showToast === 'function') {
            showToast(S.auto ? 'Salvataggio automatico attivo' : 'Salvataggio automatico spento');
        }
    }

    function init(opzioni) {
        opzioni = opzioni || {};
        S.save = opzioni.save || null;
        if (opzioni.key) S.chiave = 'nisama_autosave_' + opzioni.key;
        if (opzioni.delay) S.attesa = opzioni.delay;

        var memorizzato = null;
        try { memorizzato = localStorage.getItem(S.chiave); } catch (e) { /* niente */ }
        accendiAuto(memorizzato === '1', true);

        var btn = el('nsSaveNow');
        if (btn) btn.addEventListener('click', function () { esegui(true); });

        var toggle = el('nsAutoToggle');
        if (toggle) toggle.addEventListener('change', function () { accendiAuto(toggle.checked, false); });

        /* Rete di sicurezza: se per qualche motivo il timer di quiete non e'
           scattato (un errore, una scheda in background), ogni 25 secondi si
           riprova finche' c'e' qualcosa da salvare. */
        setInterval(function () {
            if (S.auto && S.sporco && !S.salvando) esegui(false);
        }, S.controllo);

        /* Uscendo dalla pagina con l'automatico acceso si tenta un ultimo
           salvataggio. Non e' garantito che il browser lo porti a termine:
           serve a recuperare il caso comune, non a sostituire il pulsante. */
        window.addEventListener('pagehide', function () {
            if (S.auto && S.sporco && !S.salvando) esegui(false);
        });

        S.pronto = true;
        disegna();
    }

    window.NisamaSaveBar = {
        init: init,
        /* qualcosa e' cambiato in pagina */
        touch: function () {
            if (S.sporco && S.auto) { programma(); return; }
            S.sporco = true;
            disegna();
            if (S.auto) programma();
        },
        /* la pagina ha salvato per conto suo (es. il pulsante in fondo) */
        markSaved: function () {
            S.sporco = false;
            S.ultimo = new Date();
            disegna();
        },
        saveNow: function () { return esegui(true); },
        isDirty: function () { return S.sporco; },
        isAuto: function () { return S.auto; },
        /* la barra si toglie di mezzo mentre si trascina una riga */
        hide: function (nascondi) {
            var barra = el('nsSaveBar');
            if (barra) barra.classList.toggle('ns-hidden', nascondi !== false);
        }
    };
})();
