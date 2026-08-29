/* fc_ai_abbina.js
   ================================================================
   ABBINAMENTO DELLE IMMAGINI: domande a sinistra, risposte a destra.

   Si apre solo quando l'utente ha scelto immagini per le domande E per le
   risposte, perche' e' l'unico caso in cui esiste una scelta da fare: con un
   gruppo solo, ogni immagine e' gia' una carta e non c'e' niente da decidere.

   PERCHE' PARTE GIA' ABBINATO
   All'apertura le coppie sono fatte nell'ordine dei file, che nella stragrande
   maggioranza dei casi e' gia' quello giusto (le foto di uno schema si
   scattano in ordine, gli export si numerano). Il trascinamento diventa cosi'
   lo strumento per CORREGGERE due o tre coppie, non per costruirle tutte da
   zero: su venti immagini sono due gesti invece di venti.

   COME SI COLLEGA
   Due modi, tutti e due con Pointer Events, quindi identici con il dito e con
   il mouse:
     - trascinare da una tessera all'altra;
     - toccare la prima, poi toccare la seconda (piu' comodo su telefono, dove
       trascinare attraverso lo schermo e' scomodo).
   Una tessera senza collegamento non e' un errore: diventa una carta con
   quella sola immagine, che e' esattamente cio' che serve quando le domande
   sono nove e le risposte otto.
   ================================================================ */

var FCAIAbbina = (function () {
    'use strict';

    /* Le stringhe vivono nel dizionario del sito (T()): niente piu' oggetto
       locale. "annulla" e "crea le carte" riusano le stesse chiavi gia' in
       fc_ai_pannello.php/.js — sono lo stesso identico testo, non serve un
       doppione. */

    var stato = null;

    function el(tag, classe, dentro) {
        var n = document.createElement(tag);
        if (classe) n.className = classe;
        if (dentro !== undefined) n.innerHTML = dentro;
        return n;
    }

    /* ============================================================
       COSTRUZIONE
       ============================================================ */

    function costruisci(fileQ, fileA) {
        var overlay = el('div', 'fcab-overlay');

        var scheda = el('div', 'fcab-scheda');
        scheda.appendChild(el('div', 'fcab-titolo', '<b>' + T('fc.ibfai_abbina_titolo') + '</b><span>' + T('fc.ibfai_abbina_aiuto') + '</span>'));

        var area = el('div', 'fcab-area');
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'fcab-fili');
        area.appendChild(svg);

        var colQ = el('div', 'fcab-col');
        colQ.appendChild(el('div', 'fcab-col-tit', T('fc.ibfai_abbina_domande')));
        var colA = el('div', 'fcab-col');
        colA.appendChild(el('div', 'fcab-col-tit', T('fc.ibfai_abbina_risposte')));

        var tessere = { q: [], a: [] };
        [['q', fileQ, colQ], ['a', fileA, colA]].forEach(function (gruppo) {
            var lato = gruppo[0];
            for (var i = 0; i < gruppo[1].length; i++) {
                var f = gruppo[1][i];
                var t = el('div', 'fcab-tessera');
                t.setAttribute('data-lato', lato);
                t.setAttribute('data-i', i);
                /* L'oggetto URL si revoca alla chiusura: senza, venti anteprime
                   restano in memoria finche' la pagina non viene ricaricata. */
                var url = URL.createObjectURL(f);
                t.innerHTML = '<img src="' + url + '" alt=""><span>' + (i + 1) + '</span>';
                t._url = url;
                gruppo[2].appendChild(t);
                tessere[lato].push(t);
            }
        });

        area.appendChild(colQ);
        area.appendChild(colA);
        scheda.appendChild(area);

        var piede = el('div', 'fcab-piede');
        var conto = el('div', 'fcab-conto');
        var azioni = el('div', 'fcab-azioni');
        var btnNo = el('button', 'btn btn-ghost btn-sm', T('fc.ibfai_annulla'));
        var btnSi = el('button', 'btn btn-primary btn-sm', T('fc.ibfai_crea_carte'));
        btnNo.type = btnSi.type = 'button';
        azioni.appendChild(btnNo);
        azioni.appendChild(btnSi);
        piede.appendChild(conto);
        piede.appendChild(azioni);
        scheda.appendChild(piede);

        overlay.appendChild(scheda);
        document.body.appendChild(overlay);

        return { overlay: overlay, area: area, svg: svg, tessere: tessere,
                 conto: conto, btnNo: btnNo, btnSi: btnSi };
    }

    /* ============================================================
       COLLEGAMENTI
       ============================================================ */

    /** coppie[iDomanda] = iRisposta. Una risposta sta in una coppia sola. */
    function collega(iQ, iA) {
        // se uno dei due era gia' impegnato, il vecchio legame si scioglie
        for (var k in stato.coppie) {
            if (stato.coppie[k] === iA) delete stato.coppie[k];
        }
        stato.coppie[iQ] = iA;
        disegna();
    }

    function scollega(iQ) { delete stato.coppie[iQ]; disegna(); }

    function centroDestro(t, area) {
        var r = t.getBoundingClientRect(), a = area.getBoundingClientRect();
        return { x: r.right - a.left + area.scrollLeft, y: r.top + r.height / 2 - a.top + area.scrollTop };
    }
    function centroSinistro(t, area) {
        var r = t.getBoundingClientRect(), a = area.getBoundingClientRect();
        return { x: r.left - a.left + area.scrollLeft, y: r.top + r.height / 2 - a.top + area.scrollTop };
    }

    function disegna() {
        var u = stato.ui;
        u.svg.setAttribute('width', u.area.scrollWidth);
        u.svg.setAttribute('height', u.area.scrollHeight);
        u.svg.innerHTML = '';

        var abbinate = 0;
        u.tessere.q.forEach(function (t) { t.classList.remove('is-legata'); });
        u.tessere.a.forEach(function (t) { t.classList.remove('is-legata'); });

        Object.keys(stato.coppie).forEach(function (k) {
            var iQ = parseInt(k, 10), iA = stato.coppie[k];
            var tq = u.tessere.q[iQ], ta = u.tessere.a[iA];
            if (!tq || !ta) return;
            abbinate++;
            tq.classList.add('is-legata');
            ta.classList.add('is-legata');

            var p1 = centroDestro(tq, u.area), p2 = centroSinistro(ta, u.area);
            var mx = (p1.x + p2.x) / 2;
            var filo = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            /* Curva e non retta: con piu' fili incrociati due segmenti dritti
               diventano indistinguibili nel punto in cui si tagliano, mentre
               due curve si seguono con l'occhio. */
            filo.setAttribute('d', 'M' + p1.x + ',' + p1.y + ' C' + mx + ',' + p1.y + ' ' + mx + ',' + p2.y + ' ' + p2.x + ',' + p2.y);
            filo.setAttribute('class', 'fcab-filo');
            u.svg.appendChild(filo);

            // pallino a meta' filo: toccandolo si scioglie la coppia
            var nodo = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            nodo.setAttribute('cx', mx);
            nodo.setAttribute('cy', (p1.y + p2.y) / 2);
            nodo.setAttribute('r', 9);
            nodo.setAttribute('class', 'fcab-nodo');
            nodo.addEventListener('click', function () { scollega(iQ); });
            u.svg.appendChild(nodo);
        });

        var sole = (u.tessere.q.length - abbinate) + (u.tessere.a.length - abbinate);
        u.conto.textContent = abbinate + ' ' + T('fc.ibfai_abbina_coppie') + (sole ? ' · ' + sole + ' ' + T('fc.ibfai_abbina_sole') : '');
    }

    /* ============================================================
       GESTO
       ============================================================ */

    function tesseraDa(ev) {
        var n = document.elementFromPoint(ev.clientX, ev.clientY);
        return n ? n.closest('.fcab-tessera') : null;
    }

    /* Tempo e tolleranza della pressione prolungata: sotto i 450ms senza
       muoversi e' un tocco (arma o collega, come prima); oltre i 450ms senza
       muoversi e' una richiesta di anteprima. 450ms e' il valore che sentono
       "voluto" senza sentirsi lenti — sotto rischia di scattare durante un
       tocco normale un po' esitante, sopra si sente un ritardo. */
    var FCAB_SOGLIA_PRESSIONE = 450;
    var FCAB_SOGLIA_MOVIMENTO = 10; // px oltre i quali non e' piu' una pressione ferma, e' un trascinamento

    function avviaGesti() {
        var u = stato.ui;
        var partenza = null;   // tessera da cui si sta trascinando
        var armata = null;     // tessera toccata in attesa della seconda
        var provvisorio = null;

        var timerPressione = null;
        var pressioneLunga = false;
        var puntoIniziale = null;
        var chiudiAnteprima = null;

        function annullaTimerPressione() {
            if (timerPressione) { clearTimeout(timerPressione); timerPressione = null; }
        }

        function pulisci() {
            if (provvisorio) { provvisorio.remove(); provvisorio = null; }
            if (partenza) partenza.classList.remove('is-attiva');
            partenza = null;
            annullaTimerPressione();
        }

        u.area.addEventListener('pointerdown', function (ev) {
            var t = tesseraDa(ev);
            if (!t) return;
            partenza = t;
            t.classList.add('is-attiva');
            /* Il puntatore si cattura sull'area: senza, trascinando fuori dalla
               tessera il browser smette di mandare gli eventi e il filo resta
               appeso a meta'. Cattura anche gli eventi della pressione lunga:
               chi tiene premuto puo' spostare leggermente il dito senza che
               l'anteprima si perda per strada. */
            u.area.setPointerCapture(ev.pointerId);

            provvisorio = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            provvisorio.setAttribute('class', 'fcab-filo is-provvisorio');
            u.svg.appendChild(provvisorio);

            pressioneLunga = false;
            puntoIniziale = { x: ev.clientX, y: ev.clientY };
            timerPressione = setTimeout(function () {
                timerPressione = null;
                pressioneLunga = true;
                /* Il collegamento in corso si annulla: si sta guardando
                   l'immagine, non tracciando un filo. */
                if (provvisorio) { provvisorio.remove(); provvisorio = null; }
                chiudiAnteprima = mostraAnteprima(t);
            }, FCAB_SOGLIA_PRESSIONE);
        });

        u.area.addEventListener('pointermove', function (ev) {
            if (!partenza) return;
            if (timerPressione && puntoIniziale) {
                var dx = ev.clientX - puntoIniziale.x, dy = ev.clientY - puntoIniziale.y;
                if (Math.hypot(dx, dy) > FCAB_SOGLIA_MOVIMENTO) annullaTimerPressione();
            }
            if (pressioneLunga || !provvisorio) return;
            ev.preventDefault();
            var lato = partenza.getAttribute('data-lato');
            var p1 = lato === 'q' ? centroDestro(partenza, u.area) : centroSinistro(partenza, u.area);
            var r = u.area.getBoundingClientRect();
            var p2 = { x: ev.clientX - r.left + u.area.scrollLeft, y: ev.clientY - r.top + u.area.scrollTop };
            var mx = (p1.x + p2.x) / 2;
            provvisorio.setAttribute('d', 'M' + p1.x + ',' + p1.y + ' C' + mx + ',' + p1.y + ' ' + mx + ',' + p2.y + ' ' + p2.x + ',' + p2.y);
        });

        u.area.addEventListener('pointerup', function (ev) {
            if (!partenza) return;
            annullaTimerPressione();

            /* Si rilascia il dito che teneva premuto: l'anteprima si chiude e
               il gesto finisce qui, senza toccare ne' collegamenti ne' voci
               armate — non era un tentativo di collegare niente. */
            if (pressioneLunga) {
                pressioneLunga = false;
                if (chiudiAnteprima) { chiudiAnteprima(); chiudiAnteprima = null; }
                pulisci();
                return;
            }

            var arrivo = tesseraDa(ev);
            var da = partenza;
            pulisci();

            /* Rilasciato dove si era partiti: e' un tocco, non un
               trascinamento. La tessera resta "armata" e si aspetta il secondo
               tocco — il modo comodo di lavorare su telefono. */
            if (!arrivo || arrivo === da) {
                if (armata && armata !== da && armata.getAttribute('data-lato') !== da.getAttribute('data-lato')) {
                    unisci(armata, da);
                    armata.classList.remove('is-armata');
                    armata = null;
                } else {
                    if (armata) armata.classList.remove('is-armata');
                    armata = (armata === da) ? null : da;
                    if (armata) armata.classList.add('is-armata');
                }
                return;
            }

            if (arrivo.getAttribute('data-lato') !== da.getAttribute('data-lato')) unisci(da, arrivo);
            if (armata) { armata.classList.remove('is-armata'); armata = null; }
        });

        u.area.addEventListener('pointercancel', function () {
            pressioneLunga = false;
            if (chiudiAnteprima) { chiudiAnteprima(); chiudiAnteprima = null; }
            pulisci();
        });
        u.area.addEventListener('scroll', disegna); // i fili seguono lo scorrimento
    }

    /**
     * Anteprima a schermo intero dell'immagine di una tessera. Restituisce la
     * funzione che la chiude, da richiamare al rilascio del dito.
     *
     * Usa la stessa URL gia' creata per la miniatura (niente ricaricamento del
     * file: e' la stessa immagine, solo mostrata piu' grande) e si appoggia al
     * blocco-scorrimento condiviso — che regge anche l'apertura sopra il
     * popup di abbinamento gia' aperto, perche' e' un contatore e non un
     * semplice acceso/spento.
     */
    function mostraAnteprima(tessera) {
        var sorgente = tessera.querySelector('img');
        if (!sorgente) return null;

        var overlay = el('div', 'fcab-zoom');
        var foto = document.createElement('img');
        foto.src = sorgente.src;
        foto.alt = '';
        overlay.appendChild(foto);
        document.body.appendChild(overlay);
        if (typeof FCAI !== 'undefined') FCAI.lockScroll();

        var chiusa = false;
        return function chiudi() {
            if (chiusa) return; // il rilascio del dito e pointercancel potrebbero chiamarla entrambi
            chiusa = true;
            overlay.remove();
            if (typeof FCAI !== 'undefined') FCAI.unlockScroll();
        };
    }

    function unisci(t1, t2) {
        var q = t1.getAttribute('data-lato') === 'q' ? t1 : t2;
        var a = q === t1 ? t2 : t1;
        collega(parseInt(q.getAttribute('data-i'), 10), parseInt(a.getAttribute('data-i'), 10));
    }

    /* ============================================================
       APERTURA
       ============================================================ */

    /**
     * Restituisce una promessa con [{qFile, aFile}] in ordine: prima le coppie
     * (nell'ordine delle domande), poi le domande sole, poi le risposte sole.
     * Se l'utente annulla, la promessa vale null.
     */
    function apri(fileQ, fileA) {
        var listaQ = Array.prototype.slice.call(fileQ);
        var listaA = Array.prototype.slice.call(fileA);

        return new Promise(function (risolvi) {
            var ui = costruisci(listaQ, listaA);
            stato = { ui: ui, coppie: {} };
            if (typeof FCAI !== 'undefined') FCAI.lockScroll();

            // abbinamento di partenza: nell'ordine
            for (var i = 0; i < Math.min(listaQ.length, listaA.length); i++) stato.coppie[i] = i;

            avviaGesti();
            requestAnimationFrame(disegna); // dopo il calcolo del layout, o le misure sono a zero

            function chiudi(risultato) {
                ui.tessere.q.concat(ui.tessere.a).forEach(function (t) { URL.revokeObjectURL(t._url); });
                ui.overlay.remove();
                if (typeof FCAI !== 'undefined') FCAI.unlockScroll();
                stato = null;
                risolvi(risultato);
            }

            ui.btnNo.addEventListener('click', function () { chiudi(null); });
            ui.overlay.addEventListener('click', function (ev) { if (ev.target === ui.overlay) chiudi(null); });

            ui.btnSi.addEventListener('click', function () {
                var carte = [], usateA = {};
                listaQ.forEach(function (f, iQ) {
                    var iA = stato.coppie[iQ];
                    if (iA === undefined) { carte.push({ qFile: f, aFile: null }); return; }
                    usateA[iA] = true;
                    carte.push({ qFile: f, aFile: listaA[iA] });
                });
                listaA.forEach(function (f, iA) {
                    if (!usateA[iA]) carte.push({ qFile: null, aFile: f });
                });
                chiudi(carte);
            });
        });
    }

    return { apri: apri };
})();
