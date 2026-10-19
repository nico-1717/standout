/* planner/js/08_creazione.js — creazione: tocco singolo e press-and-drag.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Creazione: tocco singolo e press-and-drag                           */
/* ------------------------------------------------------------------ */
(function creation() {
    var sc = $('plScroller');
    var track = $('plTrack');
    var st = null;

    function canvasAt(target) { return target.closest ? target.closest('.pl-canvas') : null; }
    function minuteAt(canvas, clientY) {
        var box = canvas.getBoundingClientRect();
        return Math.max(0, Math.min(1439, Math.round((clientY - box.top) / pxMin())));
    }
    function snap(min, up) {
        var s = zoomCfg(S.zoom).slot || 30;
        return (up ? Math.ceil(min / s) : Math.floor(min / s)) * s;
    }
    function trackNow() {
        var m = /translateX\((-?[\d.]+)px\)/.exec(track.style.transform || '');
        return m ? parseFloat(m[1]) : trackOffset(S.focusDate);
    }

    /* I tastini che galleggiano sopra la griglia stanno FUORI dallo
       scorrevole — nel markup sono fratelli di <main>, non figli — quindi
       nessun gesto registrato là li vedeva mai: partendo dalla metà destra
       dello schermo, dove i tastini sono aperti, non si cambiava giorno né si
       scorrevano gli orari, e il dito sembrava appoggiato sul vetro.

       Il gesto vive tutto qui e non tocca la macchina a stati della griglia:
       provarci l'altra volta faceva credere a `finish()` che ci fosse un
       blocco da creare, e un semplice tocco su un tastino apriva un evento
       nuovo a mezzanotte. */
    ['plFabs', 'plZoom'].forEach(function (id) {
        var host = $(id);
        if (!host) return;
        var g = null;

        host.addEventListener('pointerdown', function (e) {
            if (S.pinching || S.shift.on) return;
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            g = { id: e.pointerId, x0: e.clientX, y0: e.clientY, top0: sc.scrollTop,
                  dir: null, base: 0, page1: false, focus0: S.focusDate };
        });

        host.addEventListener('pointermove', function (e) {
            if (!g || e.pointerId !== g.id) return;
            var dx = e.clientX - g.x0, dy = e.clientY - g.y0;

            if (!g.dir) {
                if (Math.abs(dy) > 8 && Math.abs(dy) >= Math.abs(dx)) {
                    g.dir = 'v';
                } else if (Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy) * 1.4) {
                    g.dir = 'h';
                    g.base = trackNow();
                    g.page1 = psAble() ? psBegin() : false;
                    track.style.transition = 'none';
                } else {
                    return;   // ancora indeciso: potrebbe essere un tocco
                }
                try { host.setPointerCapture(g.id); } catch (err) {}
                return;
            }

            if (g.dir === 'v') {
                /* Gli orari si scorrono a mano: il dito è appoggiato fuori
                   dallo scorrevole, e da lì il browser non muove niente. */
                e.preventDefault();
                sc.scrollTop = g.top0 - dy;
                return;
            }

            e.preventDefault();
            if (g.page1) { psMove(dx); return; }
            var cw = colWidth();
            setTrackX(g.base + Math.max(-9 * cw, Math.min(9 * cw, dx)), false);
        }, { passive: false });

        function fine(e) {
            if (!g || (e && e.pointerId !== g.id)) return;
            var gg = g; g = null;
            if (gg.dir !== 'h') return;   // verticale o tocco: non c'è niente da chiudere

            /* Il tocco che segue non deve anche premere il tastino da cui il
               gesto è partito: il browser manda il click comunque, e si
               finiva per cambiare giorno e aprire la rotellina in un colpo. */
            S.sfogliatoA = Date.now();

            var dx = e ? (e.clientX - gg.x0) : 0;
            if (gg.page1) { psEnd(dx); return; }
            var cw = colWidth();
            var passi = -dx / cw;
            var giorni = Math.abs(passi) < 0.16 ? 0
                : (passi > 0 ? Math.max(1, Math.round(passi)) : Math.min(-1, Math.round(passi)));
            track.style.transition = '';
            goToDate(addDays(gg.focus0, Math.max(-7, Math.min(7, giorni))), true);
        }
        host.addEventListener('pointerup', fine);
        host.addEventListener('pointercancel', fine);
    });

    /* Solo dopo una sfogliata partita dai tastini: fuori da quella finestra
       i tocchi passano come sempre, se no si perderebbero i click sugli
       eventi. */
    document.addEventListener('click', function (e) {
        /* Il click subito dopo l'apertura della fascia di tutto il giorno non
           deve aprire anche l'evento che stava sotto il dito: quel tocco
           serviva solo ad aprire la sezione. Fermare il pointerdown non
           basta, il popup si apre sul click, che è un evento a sé. */
        if (Date.now() - (S.adApertaA || 0) < 500 && e.target.closest('.pl-hcol-ad')) {
            S.adApertaA = 0;
            e.stopPropagation();
            e.preventDefault();
            return;
        }
        if (Date.now() - (S.sfogliatoA || 0) > 350) return;
        S.sfogliatoA = 0;
        e.stopPropagation();
        e.preventDefault();
    }, true);

    sc.addEventListener('pointerdown', function (e) {
        /* secondo dito appoggiato mentre si cambia scatto: non comincia
           nessun gesto nuovo, il pizzico ha la precedenza */
        if (S.pinching) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        var evNode = e.target.closest('.pl-ev');
        var canvas = canvasAt(e.target);
        if (evNode) {
            // un blocco posizionato sulla griglia si sposta tenendolo premuto;
            // il tocco semplice lo gestisce il click qui sotto
            /* Il blocco che raccoglie più eventi bassi resta fermo: sotto ci
               sono cose diverse e trascinarlo sposterebbe solo la prima,
               facendo credere di averle spostate tutte. Per muoverli si
               rizooma, e tornano separati. */
            if (!S.shift.on && !isAgenda() && canvas && evNode.style.position !== 'static'
                && evNode.dataset.miniGroup !== '1' && evNode.dataset.multi !== '1') {
                startEventDrag(e, evNode, canvas);
            }
            /* Il dito è partito sopra un evento, ma se scorre in orizzontale
               vuole cambiare giorno: teniamo aperta quella possibilità invece
               di ignorare del tutto il gesto. Il trascinamento del blocco si
               attiva solo dopo la pressione prolungata, quindi i due non si
               pestano i piedi. */
            st = {
                canvas: canvas, date: canvas ? canvas.dataset.date : S.focusDate, id: e.pointerId,
                x0: e.clientX, y0: e.clientY, phase: 'pending', fromEvent: true,
                startMin: 0, endMin: 0, ghost: null, baseX: 0, focusAtStart: S.focusDate
            };
            return;
        }
        if (S.shift.on || !canvas) return;

        var slot = zoomCfg(S.zoom).slot || 30;
        var startMin = snap(minuteAt(canvas, e.clientY), false);
        /* Con un attrezzo armato la durata non è quella dello scatto ma la
           sua: l'abitudine dura quanto dice la sua scheda. */
        var durIniziale = S.armato ? durataArmato() : slot;
        st = {
            canvas: canvas, date: canvas.dataset.date, id: e.pointerId,
            x0: e.clientX, y0: e.clientY, startMin: startMin, endMin: Math.min(1440, startMin + durIniziale),
            ghost: null, phase: 'pending',   // 'pending' -> 'create' | 'paging' | 'scrolling'
            armato: S.armato ? S.armato.kind : null,
            baseX: 0, focusAtStart: S.focusDate
        };
        st.timer = setTimeout(function () {
            if (!st || st.phase !== 'pending' || isAgenda()) return;
            if (S.pinching) { st = null; return; }   // due dita: si sta zoomando
            st.phase = 'create';
            S.interacting = true;
            haptic(12);
            st.ghost = el('div', 'pl-ev pl-ghost');
            st.ghost.style.setProperty('--ev', S.armato ? (S.armato.item.color || NEUTRAL)
            : (S.defaultCat ? (catById(S.defaultCat) || {}).color || NEUTRAL : NEUTRAL));
            st.ghost.appendChild(el('div', 'pl-ev-h', toHM(st.startMin) + ' – ' + toHM(st.endMin)));
            positioned(st.ghost, toHM(st.startMin), toHM(st.endMin));
            st.canvas.appendChild(st.ghost);
            /* Da qui il dito comanda il blocco, non la pagina: togliamo al
               browser il permesso di scorrere, altrimenti trascinando in giù
               scorrerebbero gli orari invece di allungarsi l'evento. */
            sc.style.touchAction = 'none';
            st.lastY = st.y0;
            try { sc.setPointerCapture(st.id); } catch (err) {}
            edgeLoop();
        }, 190);
    });

    document.addEventListener('pointermove', function (e) {
        if (!st || e.pointerId !== st.id) return;
        /* due dita: si sta cambiando scatto, il giorno resta dov'è */
        if (S.pinching) { annulla(); return; }

        if (st.phase === 'pending') {
            var dx = e.clientX - st.x0, dy = e.clientY - st.y0;
            var adx = Math.abs(dx), ady = Math.abs(dy);
            /* Lo scroll delle ore deve partire con niente: appena il dito
               scende un po', è suo. Il cambio giorno invece deve essere un
               gesto orizzontale dichiarato: più lungo e netto (almeno il
               doppio dello spostamento verticale). Così un dito che scorre
               le ore non finisce mai per cambiare giorno o settimana. */
            if (ady > 8) { st.phase = 'scrolling'; if (st.timer) clearTimeout(st.timer); return; }
            if (adx > 10 && adx > ady * 1.4) {
                if (S.movingEvent) return;   // il blocco è già in mano al dito
                st.phase = 'paging';
                st.baseX = trackNow();
                st.startedAt = Date.now();
                /* Con un giorno solo davanti non scorre la striscia ma tutta
                   la pagina: orari, barra in alto e priorità comprese. */
                st.page1 = psAble() ? psBegin() : false;
                track.style.transition = 'none';
                clearTimeout(st.timer);
                try { sc.setPointerCapture(st.id); } catch (err) {}
            }
            return;   // sotto le soglie: aspettiamo, il tocco potrebbe essere un press
        }

        if (st.phase === 'paging') {
            e.preventDefault();
            var raw = e.clientX - st.x0;
            if (st.page1) { psMove(raw); return; }
            var cw = colWidth();
            var clamped = Math.max(-9 * cw, Math.min(9 * cw, raw));   // resta dentro le colonne già pronte
            setTrackX(st.baseX + clamped, false);
            return;
        }

        if (st.phase === 'create') {
            e.preventDefault();
            st.lastY = e.clientY;
            growGhost();
        }
        // fase 'scrolling': non facciamo nulla, se ne occupa il browser
    });

    /* ricalcola la fine del blocco dalla posizione attuale del dito */
    function growGhost() {
        if (!st || st.phase !== 'create' || !st.ghost) return;
        /* L'abitudine ha una durata sua e trascinando si SPOSTA, non si
           allunga: la durata sta nella sua scheda, e chi la vuole diversa
           apre il blocco dopo e la cambia lì. Il progetto preferito invece
           fa come una categoria e si allunga. */
        if (st.armato === 'habit') {
            var dur = st.endMin - st.startMin;
            var nuovo = snap(minuteAt(st.canvas, st.lastY), false);
            st.startMin = Math.max(0, Math.min(1440 - dur, nuovo));
            st.endMin = st.startMin + dur;
            positioned(st.ghost, toHM(st.startMin), toHM(st.endMin));
            var hh = st.ghost.querySelector('.pl-ev-h');
            if (hh) hh.textContent = toHM(st.startMin) + ' – ' + toHM(st.endMin);
            return;
        }
        var m = snap(minuteAt(st.canvas, st.lastY), true);
        st.endMin = Math.max(st.startMin + (zoomCfg(S.zoom).slot || 30), Math.min(1440, m));
        positioned(st.ghost, toHM(st.startMin), toHM(st.endMin));
        var h = st.ghost.querySelector('.pl-ev-h');
        if (h) h.textContent = toHM(st.startMin) + ' – ' + toHM(st.endMin);
    }

    /* vicino al bordo basso (o alto) gli orari scorrono da soli, così il
       blocco può crescere oltre lo schermo. Gira a ogni fotogramma, quindi
       funziona anche tenendo il dito fermo giù in fondo. */
    function edgeLoop() {
        if (!st || st.phase !== 'create') return;
        var vh = window.innerHeight;
        var y = st.lastY || 0;
        /* Il bordo alto utile non è quello dello schermo: è dove comincia
           davvero la sezione degli orari, cioè sotto priorità e striscia dei
           giorni. Sopra quella riga il dito è già fuori dalla griglia. */
        var top = sc.getBoundingClientRect().top;

        /* Quanto deve avvicinarsi il dito prima che gli orari comincino a
           scorrere da soli.

           Erano 110px fissi sopra e sotto: un terzo dello schermo utile su un
           telefono, e la griglia partiva a scorrere mentre si stava ancora
           scegliendo dove mettere il blocco. I due bordi ora si misurano su
           quello che c'è davvero lì: sotto, la metà del tasto +; sopra, lo
           spazio fra il primo e il secondo tastino. Sono i punti che l'utente
           riconosce guardando lo schermo, invece di un numero uguale
           dappertutto. */
        var zoneGiu = 70, zoneSu = 60;
        var plus = $('plPlus');
        if (plus) {
            var bp = plus.getBoundingClientRect();
            zoneGiu = Math.max(24, vh - (bp.top + bp.height / 2));
        }
        var tastini = $('plFabs') && $('plFabs').querySelectorAll('.pl-fab, .pl-ring');
        if (tastini && tastini.length >= 2) {
            var b1 = tastini[0].getBoundingClientRect(), b2 = tastini[1].getBoundingClientRect();
            zoneSu = Math.max(24, (b1.bottom + b2.top) / 2 - top);
        }

        var max = 16, speed = 0;
        if (y > vh - zoneGiu)      speed =  Math.min(max, (y - (vh - zoneGiu)) / 4);
        else if (y < top + zoneSu) speed = -Math.min(max, ((top + zoneSu) - y) / 4);
        if (speed) {
            var before = sc.scrollTop;
            sc.scrollTop += speed;
            if (sc.scrollTop !== before) growGhost();
        }
        st.raf = requestAnimationFrame(edgeLoop);
    }

    function releaseScroll() {
        sc.style.touchAction = '';
        S.interacting = false;
        if (st && st.raf) { cancelAnimationFrame(st.raf); st.raf = null; }
        if (S.renderPending) setTimeout(renderDays, 0);
    }

    function finish(e) {
        if (!st || (e && e.pointerId !== st.id)) return;
        clearTimeout(st.timer);
        releaseScroll();
        var phase = st.phase;

        if (phase === 'paging') {
            var dx = e ? (e.clientX - st.x0) : 0;
            if (st.page1) { st = null; psEnd(dx); return; }
            var cw = colWidth();
            var steps = -dx / cw;
            /* zona morta: uno scivolamento breve torna al punto di partenza.
               E comunque non si va oltre una settimana per gesto: è questo
               che impediva i salti improvvisi di una o due settimane. */
            var daysMoved = Math.abs(steps) < 0.16 ? 0
                : (steps > 0 ? Math.max(1, Math.round(steps)) : Math.min(-1, Math.round(steps)));
            daysMoved = Math.max(-7, Math.min(7, daysMoved));
            var target = addDays(st.focusAtStart, daysMoved);
            track.style.transition = '';
            st = null;
            goToDate(target, true);   // se daysMoved è 0, riporta la striscia al suo posto
            return;
        }
        if (phase === 'scrolling') { st = null; return; }

        // 'pending' (tocco rapido, il blocco non ha fatto in tempo a comparire)
        // oppure 'create' (tenuto premuto e allungato): in entrambi i casi si apre
        // il popup, con l'orario di default o quello allungato dal trascinamento
        /* partito da un evento: niente creazione, il tocco lo gestisce il click */
        if (st.fromEvent) { st = null; return; }
        var data = { date: st.date, start: toHM(st.startMin), end: toHM(st.endMin) };
        if (st.ghost && st.ghost.parentNode) st.ghost.parentNode.removeChild(st.ghost);
        var eraArmato = st.armato;
        st = null;
        /* Con un attrezzo armato non si apre nessun popup: si è già detto
           cosa mettere scegliendolo dal tastino, e adesso si è detto dove.
           Chiedere ancora sarebbe far ripetere una cosa già decisa. */
        if (eraArmato && S.armato) {
            inserisciArmato(data.date, toMin(data.start), toMin(data.end));
            return;
        }
        openEventSheet(null, data);
    }
    /* Il vero interruttore dello scorrimento sui touchscreen.
       Su iOS e Android cambiare touch-action a gesto già iniziato non serve a
       niente: il browser ha deciso al primo contatto cosa può scorrere. La sola
       cosa che lo ferma è preventDefault su touchmove, e per poterlo fare
       l'ascoltatore deve essere non passivo fin da subito: per questo sta qui,
       registrato una volta e sempre attivo, e agisce solo quando c'è un blocco
       in costruzione o un trascinamento orizzontale in corso. */
    sc.addEventListener('touchmove', function (e) {
        /* spostamento di un evento già in agenda: vale lo stesso blocco della
           creazione, altrimenti sotto il dito scorrerebbero gli orari */
        if (S.movingEvent && e.touches.length === 1) { e.preventDefault(); return; }
        if (!st || e.touches.length !== 1) return;   // due dita = pizzico, non nostro
        if (st.phase !== 'create' && st.phase !== 'paging') return;
        e.preventDefault();
        if (st.phase === 'create') {
            st.lastY = e.touches[0].clientY;
            growGhost();          // il blocco cresce col dito, gli orari stanno fermi
        }
    }, { passive: false });

    /* Chiude il gesto orizzontale dov'è, riportando la striscia al suo posto.
       Serve al browser quando ci toglie il puntatore, e serve al pizzico: da
       quando si è capito che si sta cambiando scatto, il giorno non si cambia
       più. O una cosa o l'altra, non le due insieme. */
    function annulla() {
        if (!st) return;
        clearTimeout(st.timer);
        releaseScroll();
        if (st.phase === 'paging') {
            if (st.page1) psEnd(0);
            else setTrackX(trackOffset(S.focusDate), true);
        }
        if (st.ghost && st.ghost.parentNode) st.ghost.parentNode.removeChild(st.ghost);
        st = null;
        S.interacting = false;
    }
    window.plAnnullaScorrimento = annulla;

    document.addEventListener('pointerup', finish);
    document.addEventListener('pointercancel', annulla);
})();

/* Rotella del mouse / trackpad: verticale = scorrono le ore (nativo, non
   lo tocchiamo); orizzontale = cambia giorno; con Ctrl o Alt premuto =
   cambia lo scatto degli orari, che su PC non si può pizzicare. */
(function wheelPaging() {
    var sc = $('plScroller');
    var lock = 0;
    sc.addEventListener('wheel', function (e) {
        if (S.shift.on) return;
        if (e.ctrlKey || e.altKey || e.metaKey) {
            e.preventDefault();
            var now2 = Date.now();
            if (now2 - lock < 220) return;
            lock = now2;
            var i = ZOOM.findIndex(function (z) { return z.key === S.zoom; });
            var next = Math.max(0, Math.min(ZOOM.length - 1, i + (e.deltaY > 0 ? -1 : 1)));
            if (!isAgenda()) {
                /* Anche con la rotella la distanza fra gli orari cambia poco
                   per volta: un passo per scatto del mouse era una scalinata,
                   e l'orario sotto il cursore saltava invece di scorrere. */
                var canvasW = document.querySelector('.pl-canvas');
                var ancoraW = null;
                if (canvasW && pxMin()) {
                    var boxW = canvasW.getBoundingClientRect();
                    ancoraW = { min: Math.max(0, Math.min(1439, (e.clientY - boxW.top) / pxMin())), y: e.clientY };
                }
                var pxmW = pxMin() * (e.deltaY > 0 ? 0.88 : 1.14);
                if (pxmW <= PXM_MIN * 0.92 && S.zoom === 'h3') { setZoom('agenda'); return; }
                if (setDensita(pxmW, ancoraW)) refreshZoomDial();
                showZoomDial('stay');
                return;
            }
            if (ZOOM[next].key !== S.zoom) {
                /* Il cursore è il dito del computer: l'orario che sta sotto
                   deve restare dov'è, come sotto le due dita sul telefono.
                   L'ancora si prende adesso, con lo scatto ancora vecchio. */
                setZoom(ZOOM[next].key, false, ancoraA(e.clientY));
                showZoomDial(zoomHideDial_keep() ? 'stay' : 'temporary');
            }
            return;
        }
        /* Niente cambio giorno con lo scorrimento orizzontale del trackpad:
           era troppo facile farlo per sbaglio scorrendo le ore, e su alcuni
           browser innescava anche il "torna indietro" della cronologia.
           Su PC si cambia giorno con il tasto, la barra o il calendario. */
    }, { passive: false });
})();


var suppressClick = 0;

/* tocco su un evento: apre il popup, spunta il task, apre la routine… */
document.addEventListener('click', function (e) {
    var node = e.target.closest('.pl-ev');
    if (!node) return;
    if (Date.now() - suppressClick < 450) return;
    var id = parseInt(node.dataset.id, 10);
    var date = node.dataset.date;
    var ev = (S.events[date] || []).filter(function (x) { return x.id === id; })[0];
    if (!ev) return;
    handleEventTap(e, node, ev);
});

/* spostamento di un evento già esistente (press lungo e trascina) */
function startEventDrag(e, node, canvas) {
    var sc = $('plScroller');
    var id = parseInt(node.dataset.id, 10);
    var date = node.dataset.date;
    var ev = (S.events[date] || []).filter(function (x) { return x.id === id; })[0];
    if (!ev) return;

    /* Il blocco compatto di una routine porta l'identificativo del solo primo
       passaggio: trascinandolo si spostava quello e gli altri restavano dove
       erano, quindi la fine del blocco non si muoveva. Qui prendiamo tutto il
       gruppo e lo trattiamo come un pezzo unico. */
    var group = (node.dataset.routineMerged === '1' && ev.routine_group)
        ? (S.events[date] || []).filter(function (x) { return x.routine_group === ev.routine_group; })
        : [ev];
    var blockStart = group.reduce(function (m, x) { return Math.min(m, toMin(x.start)); }, 1440);
    var blockEnd   = group.reduce(function (m, x) { return Math.max(m, toMin(x.end)); }, 0);
    var dur = blockEnd - blockStart;
    var startTop = parseFloat(node.style.top) || 0;
    var y0 = e.clientY, held = false, moved = false;

    /* Spostamento a un giorno vicino. Ha senso solo con più colonne davanti:
       con un giorno solo il gesto orizzontale è il cambio pagina, e ruberglielo
       renderebbe impossibile scorrere i giorni partendo da sopra un evento.
       I blocchi compatti di routine restano nella loro colonna: si spostano con
       `events_shift`, che sposta i minuti di tutti i passaggi ma non conosce la
       data, quindi meglio non offrire un movimento che poi non possiamo salvare. */
    var canMoveDay = visibleDays() > 1 && group.length === 1;
    var x0 = e.clientX, lastX = x0;
    var colBoxes = null;          // geometria delle colonne, letta una volta sola
    var curDate = date;           // giorno sotto il dito adesso
    var reparented = false;       // il blocco ha cambiato colonna almeno una volta

    var timer = setTimeout(function () {
        held = true;
        haptic(14);
        node.classList.add('pl-dragging');
        /* da qui il dito muove il blocco, non la pagina */
        S.movingEvent = true;
        S.interacting = true;
        sc.style.touchAction = 'none';
        lastY = y0; lastX = x0; scrolled = 0;
        if (canMoveDay) measureCols();
        try { sc.setPointerCapture(e.pointerId); } catch (err) {}
        edge();
    }, 300);

    var lastY = 0, raf = null, scrolled = 0;

    /* Le posizioni delle colonne si leggono qui, una volta sola, all'inizio del
       trascinamento: mentre il blocco è in mano al dito la striscia dei giorni
       non si sposta in orizzontale (il cambio pagina è bloccato da
       S.movingEvent), quindi le misure restano buone fino alla fine del gesto e
       non serve rileggere il layout a ogni fotogramma. */
    function measureCols() {
        colBoxes = [];
        var cols = document.querySelectorAll('#plTrack .pl-col[data-date]');
        for (var i = 0; i < cols.length; i++) {
            var r = cols[i].getBoundingClientRect();
            if (r.width <= 0) continue;
            colBoxes.push({
                date: cols[i].dataset.date,
                left: r.left, right: r.right,
                canvas: cols[i].querySelector('.pl-canvas')
            });
        }
    }

    /* Il blocco passa davvero nell'altra colonna invece di limitarsi a
       sconfinare: così si vede subito dove finirà, e la posizione orizzontale
       (che è in percentuale sulla colonna) resta giusta senza ricalcoli. */
    function maybeChangeDay() {
        if (!colBoxes) return;
        for (var i = 0; i < colBoxes.length; i++) {
            var b = colBoxes[i];
            if (lastX < b.left || lastX >= b.right) continue;
            if (b.date === curDate || !b.canvas) return;
            b.canvas.appendChild(node);
            curDate = b.date;
            node.dataset.date = curDate;
            reparented = true;
            haptic(8);
            return;
        }
    }

    function place() {
        var slot = zoomCfg(S.zoom).slot || 30;
        /* "scrolled" tiene conto di quanto la pagina si è mossa da sola:
           senza, avvicinandosi al bordo il blocco resterebbe indietro */
        var delta = (lastY - y0 + scrolled) / pxMin();
        var newStart = Math.max(0, Math.min(1440 - dur, Math.round((blockStart + delta) / slot) * slot));
        node.style.top = (newStart * pxMin()) + 'px';
        var h = node.querySelector('.pl-ev-h');
        if (h) h.textContent = toHM(newStart) + ' – ' + toHM(newStart + dur);
        node.dataset.newStart = toHM(newStart);
    }

    /* vicino ai bordi la sezione orari scorre, così il blocco può finire
       più in alto o più in basso di quanto lo schermo mostri */
    function edge() {
        if (!held) { raf = null; return; }
        var vh = window.innerHeight;
        var top = sc.getBoundingClientRect().top;

        // Calcoliamo dinamicamente i margini "morti" basandoci sugli elementi reali della UI
        // (stessa identica logica usata durante la creazione di un nuovo evento)
        var zoneGiu = 70, zoneSu = 60;
        var plus = $('plPlus');
        if (plus) {
            var bp = plus.getBoundingClientRect();
            zoneGiu = Math.max(24, vh - (bp.top + bp.height / 2));
        }
        var tastini = $('plFabs') && $('plFabs').querySelectorAll('.pl-fab, .pl-ring');
        if (tastini && tastini.length >= 2) {
            var b1 = tastini[0].getBoundingClientRect(), b2 = tastini[1].getBoundingClientRect();
            zoneSu = Math.max(24, (b1.bottom + b2.top) / 2 - top);
        }

        var max = 16, speed = 0;
        if (lastY > vh - zoneGiu)      speed =  Math.min(max, (lastY - (vh - zoneGiu)) / 4);
        else if (lastY < top + zoneSu) speed = -Math.min(max, ((top + zoneSu) - lastY) / 4);

        if (speed) {
            var before = sc.scrollTop;
            sc.scrollTop += speed;
            var moved2 = sc.scrollTop - before;
            if (moved2) { scrolled += moved2; place(); }
        }
        raf = requestAnimationFrame(edge);
    }

    function move(ev2) {
        if (ev2.pointerId !== e.pointerId) return;
        if (!held) { if (Math.abs(ev2.clientY - y0) > 8) { moved = true; clearTimeout(timer); cleanup(); } return; }
        ev2.preventDefault();
        lastY = ev2.clientY;
        lastX = ev2.clientX;
        maybeChangeDay();
        place();
    }
    function up(ev2) {
        if (ev2.pointerId !== e.pointerId) return;
        clearTimeout(timer);
        node.classList.remove('pl-dragging');
        cleanup();
        if (!held) return;
        suppressClick = Date.now();
        var dayChanged = curDate !== date;
        /* Il giorno può cambiare senza che cambi l'ora: in quel caso l'orario da
           salvare è quello di partenza, che altrimenti non sarebbe mai scritto
           in dataset.newStart (place() lo scrive solo se il dito sale o scende). */
        var ns = node.dataset.newStart || toHM(blockStart);
        if (!dayChanged && toMin(ns) === blockStart) {
            /* Niente da salvare: l'evento è tornato esattamente da dove era partito.
               Invece di affidarci a un calcolo fallato dei pixel CSS, ricarichiamo
               direttamente la griglia in modo solido dalla memoria. */
            renderDays();
            return;
        }

        if (group.length > 1) {
            /* tutti i passaggi si spostano dello stesso scarto, così le
               distanze fra loro restano quelle di prima */
            var shift = toMin(ns) - blockStart;
            var idsGruppo = group.map(function (x) { return x.id; });
            /* tutto il blocco si sposta subito, la conferma arriva dopo */
            group.forEach(function (x) {
                var copia = Object.assign({}, x, {
                    start: toHM(Math.max(0, Math.min(1439, toMin(x.start) + shift))),
                    end: toHM(Math.max(0, Math.min(1439, toMin(x.end) + shift)))
                });
                mostraLocale(x.id, copia);
            });
            renderDays();
            accoda('events_shift', {
                ids: idsGruppo,
                minutes: Math.abs(shift),
                direction: shift > 0 ? 'late' : 'early'
            }, {
                ev: idsGruppo,
                ok: function (r) {
                    if (r.moved === 0) toast(T('planner.il_blocco_non_ci_sta'));
                    S.weekCache = {};
                    loadWeek(monday(date), true);
                }
            });
            return;
        }

        /* Si sposta subito, in locale; il server lo sa quando può. Se la rete
           manca la modifica aspetta in coda, sbiadita, e parte da sola. */
        var spostato = Object.assign({}, ev, { start: ns, end: toHM(Math.min(1439, toMin(ns) + dur)) });
        if (dayChanged) {
            var giorniPrima = giorniFra(ev.date, ev.end_date || ev.date);
            spostato.date = curDate;
            spostato.end_date = giorniPrima > 1 ? addDays(curDate, giorniPrima - 1) : curDate;
        }
        var ripetuto = !!ev.is_occurrence;
        if (ripetuto) {
            Object.assign(ev, { start: spostato.start, end: spostato.end });
        } else {
            mostraLocale(id, spostato);
        }
        renderDays();

        accoda('event_move', { id: id, date: curDate, start: ns }, {
            ev: [ripetuto ? (ev.uid || id) : id],
            ok: function (r) {
                if (!ripetuto && S.locali[id]) { S.locali[id].start = r.start; S.locali[id].end = r.end; }
                if (ripetuto || (dayChanged && monday(curDate) !== monday(date))) {
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true);
                }
            }
        });
    }
    function cleanup() {
        S.movingEvent = false;
        S.interacting = false;
        sc.style.touchAction = '';
        if (raf) { cancelAnimationFrame(raf); raf = null; }
        if (S.renderPending) renderDays();   // recuperiamo il ridisegno rimandato
        sc.removeEventListener('pointermove', move);
        sc.removeEventListener('pointerup', up);
        sc.removeEventListener('pointercancel', up);
    }
    sc.addEventListener('pointermove', move);
    sc.addEventListener('pointerup', up);
    sc.addEventListener('pointercancel', up);
}

/* Il primo orario libero a partire da quello in cima allo schermo: se lì
   c'è già qualcosa, si scorre in avanti fino a dove finisce l'ultimo evento
   attaccato. Così la proposta non finisce mai sopra a un evento esistente. */
function firstFreeMinute(durMin) {
    var slot = zoomCfg(S.zoom).slot || 30;
    var start = isAgenda() ? 8 * 60 : Math.floor(firstVisibleMinute() / slot) * slot;
    var dur = Math.max(5, durMin || slot);

    var busy = eventsOf(S.focusDate)
        .filter(function (e) { return !inFascia(e); })
        .map(function (e) { var p = pezzoDelGiorno(e, S.focusDate); return { s: toMin(p.start), e: toMin(p.end) }; })
        .sort(function (a, b) { return a.s - b.s; });

    var t = start, guard = 0;
    while (guard++ < 400) {
        var clash = null;
        for (var i = 0; i < busy.length; i++) {
            // si sovrappone se comincia prima che l'altro finisca e finisce dopo che è iniziato
            if (t < busy[i].e && (t + dur) > busy[i].s) { clash = busy[i]; break; }
        }
        if (!clash) break;
        t = Math.ceil(clash.e / slot) * slot;      // riparte dalla fine di quello che c'è
        if (t + dur > 1440) { t = start; break; }
    }
    return Math.max(0, Math.min(1440 - dur, t));
}

/* Prima di inserire una routine si chiede conferma dell'orario, modificabile:
   evita di ritrovarsi il blocco nel punto sbagliato e doverlo spostare dopo. */
function confirmRoutineTime(r, defaultStart) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = r.name;
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');
    wrap.appendChild(el('div', 'pl-row-sub', T('planner.routine_quanti_passaggi', {durata: durLabel(r.total), n: r.items.length})));
    var inp = el('input', 'pl-in');
    inp.type = 'time'; inp.step = '300'; inp.value = defaultStart;
    inp.style.marginTop = '10px'; inp.style.textAlign = 'center';
    wrap.appendChild(inp);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', T('planner.annulla')); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', T('planner.inserisci')); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = inp.value || defaultStart;
        ov.classList.remove('pl-open');
        var giorno = S.focusDate;
        var gruppoTmp = 'tmp' + Date.now();
        var cursore = toMin(start), tmpIds = [];
        (r.items || []).forEach(function (it) {
            if (cursore >= 1439) return;
            var fine = Math.min(1439, cursore + Math.max(1, it.minutes || 1));
            var tmp = idProvvisorio();
            tmpIds.push(tmp);
            mostraLocale(tmp, eventoLocale({ title: it.name, date: giorno, start: toHM(cursore), end: toHM(fine),
                                             color: r.color, type: 'event' }, null, tmp));
            Object.assign(S.locali[tmp], { routine_id: r.id, routine_group: gruppoTmp, routine_collapsed: true, routine_name: r.name });
            indexEvents([S.locali[tmp]], null, true);
            cursore = fine;
        });
        renderDays();
        haptic(14);
        accoda('routine_insert', { routine_id: r.id, date: giorno, start: start }, {
            ev: tmpIds,
            ok: function (res) {
                tmpIds.forEach(togliDaGiorni);
                indexEvents(res.events, { from: giorno, to: giorno });
            }
        });
    });
    acts.appendChild(no); acts.appendChild(yes);
    ov.classList.add('pl-open');
    setTimeout(function () { inp.focus(); }, 320);
}

/* Come per le routine: prima di mettere un'abitudine in agenda si conferma
   l'orario, con la durata già proposta da quella stimata in habits. */
/* NON PIÙ IN USO: le abitudini ora si armano dal tastino e si posano toccando
   il calendario, come le categorie. Resta qui perché è il popup di conferma
   già scritto e collaudato, nel caso si voglia tornare a chiedere l'orario. */
function confirmHabitTime(h, defaultStart, dur) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = h.name;
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');
    wrap.appendChild(el('div', 'pl-row-sub', T('planner.durata_a_che_ora', {durata: durLabel(dur)})));
    var inp = el('input', 'pl-in');
    inp.type = 'time'; inp.step = '300'; inp.value = defaultStart;
    inp.style.marginTop = '10px'; inp.style.textAlign = 'center';
    wrap.appendChild(inp);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', T('planner.annulla')); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', T('planner.inserisci')); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = toMin(inp.value || defaultStart);
        ov.classList.remove('pl-open');
        api('event_save', {
            title: h.name, date: S.focusDate, start: toHM(start),
            end: toHM(Math.min(1439, start + dur)), habit_id: h.id,
            type: 'task', color: h.color, notify: 'none'
        }).then(function (r) {
            if (!r.ok) { toast(T('planner.non_e_stato_possibile_aggiungere')); return; }
            indexEvents([r.event]);
            renderDays();
            haptic(12);
        });
    });
    acts.appendChild(no); acts.appendChild(yes);
    ov.classList.add('pl-open');
    setTimeout(function () { inp.focus(); }, 320);
}

/* Il popup del blocco routine già inserito: modifica SOLO questa istanza in
   agenda, non la routine salvata nelle impostazioni. Elenca i passaggi con
   la loro durata, uno alla volta modificabile o eliminabile; in fondo si può
   togliere l'intero blocco. */
function openRoutineInstance(group, date) {
    var items = (S.events[date] || [])
        .filter(function (e) { return e.routine_group === group; })
        .sort(function (a, b) { return toMin(a.start) - toMin(b.start); });
    if (!items.length) return;
    var routine = routineById(items[0].routine_id);

    openSheet(routine ? routine.name : T('planner.routine_t'), function (body) {
        var d = parseIso(date);
        body.appendChild(el('div', 'pl-row-sub', d.getDate() + ' ' + minuscolo(MESI[d.getMonth()])
            + '  ·  ' + items[0].start + ' – ' + items[items.length - 1].end));

        var rows = el('div', 'pl-rows');
        rows.style.marginTop = '12px';
        items.forEach(function (it) { rows.appendChild(routineStepRow(it, group, date)); });
        body.appendChild(rows);

        var delAll = el('button', 'pl-btn pl-btn-danger', T('planner.elimina_tutta_la_routine'));
        delAll.type = 'button';
        delAll.style.marginTop = '18px';
        delAll.addEventListener('click', function () {
            alertBox(T('planner.elimino_tutta_la_routine'), T('planner.tutti_i_passaggi_di_questo'), [
                { label: T('planner.annulla') },
                { label: T('planner.elimina'), kind: 'danger', onClick: function () {
                    var idsRoutine = items.map(function (x) { return x.id; });
                    idsRoutine.forEach(function (x) { mostraLocale(x, null); });
                    closeOv('plSheetOv');
                    renderDays();
                    accoda('event_delete', { id: items[0].id, scope: 'routine' }, { ev: idsRoutine });
                } }
            ]);
        });
        body.appendChild(delAll);
    });
}

/* Dopo aver tolto o accorciato un passaggio, i successivi si riattaccano al
   precedente: il blocco dura quanto la somma dei passaggi rimasti, invece di
   conservare la durata della routine originale con un buco dentro.
   Il conto lo fa il server, che è l'unico a poterlo fare in un colpo solo. */
function ricompattaRoutine(group, date, poi) {
    return api('routine_recompact', { routine_group: group, date: date }).then(function (r) {
        if (r && r.ok && r.events) {
            /* si riparte dalla risposta: gli orari vecchi non valgono più */
            S.events[date] = (S.events[date] || []).filter(function (e) { return e.routine_group !== group; });
            indexEvents(r.events);
        }
        renderDays();
        if (poi) poi(r);
    });
}

function routineStepRow(it, group, date) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');
    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', it.title));
    var sub = el('div', 'pl-row-sub', it.start + ' – ' + it.end);
    txt.appendChild(sub);
    inner.appendChild(txt);

    var dur = el('input', 'pl-pick');
    dur.type = 'number'; dur.min = '1'; dur.max = '600'; dur.inputMode = 'numeric';
    dur.value = toMin(it.end) - toMin(it.start);
    dur.style.width = '58px'; dur.style.textAlign = 'center'; dur.style.flexShrink = '0';
    dur.addEventListener('change', function () {
        var mins = Math.max(1, parseInt(dur.value, 10) || 1);
        var newEnd = toHM(toMin(it.start) + mins);
        api('event_save', {
            id: it.id, title: it.title, notes: it.notes || '', date: it.date, start: it.start, end: newEnd,
            all_day: false, category_id: 0, project_id: 0, habit_id: 0,
            importance: it.importance || 'normal', color: it.color || '', type: it.type || 'event',
            notify: it.notify || 'none', notify_min: it.notify_min || 0,
            repeat: 'none', repeat_interval: 1, repeat_unit: 'week', repeat_until: ''
        }).then(function (r) {
            if (!r.ok) { toast(T('planner.non_salvato')); dur.value = toMin(it.end) - toMin(it.start); return; }
            it.end = r.event.end;
            indexEvents([r.event]);
            sub.textContent = it.start + ' – ' + it.end;
            /* cambiata la durata di un passaggio, i seguenti si rimettono in
               fila: l'elenco si riapre già aggiornato */
            ricompattaRoutine(group, date, function () { openRoutineInstance(group, date); });
        });
    });
    inner.appendChild(dur);

    var del = el('button', 'pl-icon-btn');
    del.type = 'button';
    del.style.marginLeft = '8px'; del.style.flexShrink = '0';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        api('event_delete', { id: it.id, scope: 'one' }).then(function (r) {
            if (!r.ok) { toast(T('planner.non_e_stato_possibile_eliminare')); return; }
            S.events[date] = (S.events[date] || []).filter(function (e) { return e.id !== it.id; });
            var remain = (S.events[date] || []).filter(function (e) { return e.routine_group === group; });
            if (!remain.length) { renderDays(); closeOv('plSheetOv'); return; }
            /* il buco lasciato dal passaggio tolto si chiude: gli altri
               risalgono e il blocco si accorcia di conseguenza */
            ricompattaRoutine(group, date, function () { openRoutineInstance(group, date); });
        });
    });
    inner.appendChild(del);
    row.appendChild(inner);
    return row;
}

function handleEventTap(e, node, ev) {
    var act = e.target.closest('[data-act]');
    var kind = act ? act.dataset.act : null;

    if (S.shift.on) {
        /* Basta toccare il blocco, non serve centrare la casellina.
           Il blocco compatto di una routine porta l'identificativo del solo
           primo passaggio: selezionandolo si sceglievano i suoi minuti e non
           quelli degli altri, così il ritardo spostava solo il primo pezzo e
           la routine si allungava invece di spostarsi. Qui la selezione si
           estende a tutti i passaggi del gruppo. */
        var ids = [ev.id];
        if (node.dataset.routineMerged === '1' && ev.routine_group) {
            ids = (S.events[ev.date] || [])
                .filter(function (x) { return x.routine_group === ev.routine_group; })
                .map(function (x) { return x.id; });
            if (!ids.length) ids = [ev.id];
        }
        var on = !S.shift.picked[ids[0]];
        ids.forEach(function (id) {
            if (on) S.shift.picked[id] = true; else delete S.shift.picked[id];
        });
        var box = node.querySelector('.pl-ev-box');
        if (box) box.classList.toggle('pl-picked', on);
        node.classList.toggle('pl-sel', on);
        haptic(6);
        return;
    }
    /* le due forme del compattamento: il tastino dell'evento isolato e il
       blocco che ne raccoglie più d'uno. Vanno intercettate prima di tutto il
       resto, perché il nodo del gruppo porta l'identificativo del solo primo
       evento e qualunque altra azione finirebbe per riguardare quello. */
    /* Il gruppo va guardato per primo: il suo tastino è identico a quello
       dell'evento isolato, e chiedendo prima del tastino si finiva per
       mostrare solo il primo evento del gruppo invece di tutti. */
    if (node.dataset.miniGroup === '1') { miniPopup(miniEventiDi(node)); return; }
    if (kind === 'mini') { miniPopup([ev]); return; }

    if (kind === 'toggle') {
        /* la spunta si vede subito; il conto degli anelli arriva con la conferma */
        var fatto = !ev.done;
        ev.done = fatto;
        node.classList.toggle('pl-done', fatto);
        node.classList.add('pl-attesa');
        if (!ev.is_occurrence) S.locali[ev.id] = Object.assign({}, ev);
        haptic(12);
        accoda('event_toggle', { id: ev.id }, { ev: [ev.id], ok: function (r) {
            ev.done = r.done;
            if (S.locali[ev.id]) S.locali[ev.id].done = r.done;
            node.classList.toggle('pl-done', r.done);
            if (ev.habit_id && S.habits[ev.date]) {
                S.habits[ev.date].forEach(function (h) { if (h.id === ev.habit_id) h.done = r.done; });
                /* la spunta cambia il conto degli anelli: il conto lo fa il
                   server, quindi qui si butta via la copia e si richiede */
                delete S.rings[ev.date];
                if (ev.date === S.focusDate) renderRing();
            }
            haptic(12);
            renderRing();
            /* La stessa cosa vive in due posti: sul calendario e negli
               elenchi. Spuntandola qui il pannello restava con la voce non
               sbarrata finché non lo si riapriva, e i due posti raccontavano
               storie diverse dello stesso to do. */
            if (ev.type === 'task' && S.todos) loadWeekTodos(S.todoTab, true);
        } });
        return;
    }
    if (kind === 'fold') {
        var group = act.dataset.group;
        var collapsed = act.dataset.collapsed === '1';
        api('routine_fold', { routine_group: group, collapsed: !collapsed }).then(function (r) {
            if (!r.ok) return;
            (S.events[ev.date] || []).forEach(function (x) {
                if (x.routine_group === group) x.routine_collapsed = !collapsed;
            });
            renderDays();
        });
        return;
    }
    if (kind === 'map') {
        /* su telefono apre l'app di navigazione predefinita, su computer la
           mappa nel browser: la scelta la fa il sistema operativo */
        var q = encodeURIComponent(act.dataset.place || '');
        var isApple = /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent);
        window.open(isApple ? 'maps://?q=' + q
                            : 'https://www.google.com/maps/search/?api=1&query=' + q,
                    '_blank', 'noopener');
        return;
    }
    if (kind === 'pomo') {
        /* il pomodoro legge "project_id": con il vecchio nome arrivava senza
           progetto selezionato */
        window.location.href = '../projects/pomodoro.php?project_id=' + encodeURIComponent(act.dataset.project);
        return;
    }
    /* il blocco compatto di una routine apre l'elenco dei suoi passaggi, non
       l'editor del singolo evento (che qui sarebbe solo il primo passaggio) */
    if (node.dataset.routineMerged === '1' && ev.routine_group) {
        openRoutineInstance(ev.routine_group, ev.date);
        return;
    }
    openEventSheet(ev, null);
}
