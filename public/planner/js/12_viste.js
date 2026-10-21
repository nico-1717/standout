/* planner/js/12_viste.js — vista mese, settimana a tabella, menu delle viste, elenco degli eventi.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Vista mese                                                          */
/* ------------------------------------------------------------------ */

function setView(v) {
    S.view = v;
    disarmaSeFuoriPosto();
    document.body.classList.toggle('pl-view-month', v === 'month');
    document.body.classList.toggle('pl-view-week', v === 'week');
    var vb = $('plViewBtn');
    vb.classList.toggle('pl-on', v === 'month');
    vb.setAttribute('aria-label', v === 'month' ? T('planner.torna_alla_settimana') : T('planner.passa_alla_vista_mese'));
    scriviVista();
    if (v === 'week') {
        closeMonthAgenda();
        $('plMonthsBar').classList.remove('pl-open');
        renderWeek();
    } else if (v === 'month') {
        S.monthCursor = S.focusDate.slice(0, 7) + '-01';
        renderMonth();
    } else {
        closeMonthAgenda();
        $('plMonthsBar').classList.remove('pl-open');
        buildTrack();
        renderWeekBits();
        renderPrio();
        ensureStartScroll();
    }
    syncScrollerTop();
    applyAmbient();
    applySky();
    renderWeekBits();
}

/* Sul tasto delle viste c'e' scritto dove si e': Giorno, Settimana o Mese. */
function scriviVista() {
    var lab = $('plViewLab');
    if (!lab) return;
    lab.textContent = S.view === 'month' ? T('planner.mese')
        : (S.view === 'week' ? T('planner.settimana_2') : T('planner.giorno'));
}

/* ------------------------------------------------------------------ */
/* Vista settimanale a tabella (solo telefono in verticale)            */
/* ------------------------------------------------------------------ */
/* Sette giorni in colonna e le ore in riga. Serve dove la griglia a orari è
   troppo stretta per leggersi e il mese troppo grosso per dire a che ora
   sono le cose.

   Si disegna una finestra di WK_GIORNI giorni con il giorno scelto in mezzo,
   e si scorre di giorno in giorno con l'aggancio dello scorrimento: il giorno
   scelto è quello che si ferma a sinistra. Quando ci si avvicina a un bordo
   la finestra viene ridisegnata intorno al nuovo giorno, così lo scorrimento
   non finisce mai. */
var WK_GIORNI = 21, WK_PRIMA = 7;   // quanti giorni in tutto, e quanti prima
/* `zoomando` dice che due dita stanno cambiando l'altezza della riga: in quel
   momento la posizione verticale la scrive l'ancoraggio dello zoom, e il
   trascinamento a un dito deve stare fermo. Il verso del gesto non sta qui
   ma dentro il gesto stesso, che dura quanto il dito. */
var WK = { riga: 44, inizio: null, zoomando: false, adH: 0 };
var wkScrollTimer = null;

/* Porta l'orario `min` all'altezza `clientY` dello schermo. È il gemello di
   scrollToMinuteAt() della vista giorno, ma contato in altezze di riga
   invece che in pixel al minuto: qui una riga è un'ora, ed è quella la
   misura che lo zoom cambia.
   `scTop` e `scH` arrivano da fuori perché si misurano una volta sola,
   all'inizio del gesto: leggerli a ogni fotogramma vorrebbe dire far rifare
   i conti al browser proprio mentre sta disegnando. Anche il fondo si
   calcola invece di misurarlo — il contenuto è alto ventiquattro righe e non
   c'è niente da chiedere a nessuno. */
function wkScrollToMinuteAt(min, clientY, scTop, scH) {
    var sc = $('plWeekScroll');
    if (!sc) return;
    var y = (min / 60) * WK.riga - (clientY - scTop);
    var giu = Math.max(0, 24 * WK.riga - scH);
    sc.scrollTop = Math.max(0, Math.min(giu, y));
}

function isSchermoStretto() {
    return window.matchMedia('(max-width: 700px) and (orientation: portrait)').matches;
}

function renderWeek() {
    var grid = $('plWeekGrid'), heads = $('plWeekHeads'), gut = $('plWeekGut');
    if (!grid || !heads || !gut) return;
    var inizio = addDays(S.focusDate, -WK_PRIMA);
    WK.inizio = inizio;

    /* La finestra copre più settimane di quella caricata. Si guarda quali
       mancano davvero e si chiedono solo quelle: al ritorno si ridisegna una
       volta sola, e siccome al secondo giro non ne manca più nessuna il giro
       si ferma da sé invece di rincorrersi. */
    var viste = {}, mancanti = [];
    for (var d = 0; d < WK_GIORNI; d += 1) {
        var m = monday(addDays(inizio, d));
        if (viste[m]) continue;
        viste[m] = 1;
        if (!S.weekCache[m]) mancanti.push(m);
    }
    if (mancanti.length) {
        Promise.all(mancanti.map(function (x) { return loadWeek(x); })).then(function () {
            if (S.view === 'week') renderWeek();
        });
    }

    var colonne = 'repeat(' + WK_GIORNI + ', var(--wk-col))';
    grid.style.gridTemplateColumns = colonne;
    heads.style.gridTemplateColumns = colonne;
    grid.style.setProperty('--wk-row', WK.riga + 'px');

    /* --- intestazioni --- */
    heads.innerHTML = '';
    for (var i = 0; i < WK_GIORNI; i++) {
        var date = addDays(inizio, i);
        var dd = parseIso(date);
        var head = el('button', 'pl-wk-head' + (date === S.today ? ' pl-oggi' : ''));
        head.type = 'button';
        head.dataset.date = date;
        head.appendChild(el('span', 'pl-wk-dow', GIORNI[dowIndex(date)].slice(0, 3)));
        head.appendChild(el('span', 'pl-wk-num', String(dd.getDate())));
        var tutti = (S.events[date] || []).filter(inFascia);
        if (tutti.length) {
            var pt = el('span', 'pl-wk-allday');
            pt.style.setProperty('--ev', evColor(tutti[0]));
            head.appendChild(pt);
        }
        head.addEventListener('click', function () {
            S.focusDate = this.dataset.date;
            setView('day');
        });
        heads.appendChild(head);
    }

    /* --- la riga di tutto il giorno, sopra le 00 --- */
    var adPerGiorno = [], adQualcuno = false;
    for (var a = 0; a < WK_GIORNI; a++) {
        var dAd = addDays(inizio, a);
        var listAd = (S.events[dAd] || []).filter(inFascia);
        adPerGiorno.push({ date: dAd, list: listAd });
        if (listAd.length) adQualcuno = true;
    }
    /* La riga esiste solo se c'è qualcosa da metterci: una fascia vuota sopra
       le 00 ruberebbe altezza agli orari tutti i giorni per niente. */
    WK.adH = adQualcuno ? Math.min(3, adPerGiorno.reduce(function (m, g) {
        return Math.max(m, g.list.length);
    }, 0)) * 15 + 6 : 0;

    /* --- colonna delle ore --- */
    gut.innerHTML = '';
    gut.style.setProperty('--wk-row', WK.riga + 'px');
    for (var o = 0; o < 24; o++) {
        var riga = el('div', 'pl-wk-ora');
        riga.appendChild(el('span', 'pl-wk-ora-n', (o < 10 ? '0' : '') + o));
        gut.appendChild(riga);
    }
    /* il tempo di quell'ora accanto all'ora, come nella vista giorno */
    renderGutterMeteo(gut, S.focusDate);

    /* Rifare la griglia azzera lo scorrimento verticale: dopo uno zoom si
       tornava a mezzanotte proprio mentre si stava guardando il pomeriggio.
       Lo si tiene da parte e lo si rimette, con le righe già alte quanto
       devono essere. */
    var scPrima = $('plWeekScroll');
    var altezzaPrima = scPrima ? scPrima.scrollTop : 0;

    /* --- celle --- */
    grid.innerHTML = '';
    for (var h = 0; h < 24; h++) {
        for (var k = 0; k < WK_GIORNI; k++) {
            grid.appendChild(wkCella(addDays(inizio, k), h));
        }
    }

    /* La fascia di tutto il giorno NON è una riga della griglia.
       Dentro la griglia veniva stirata a un'ora intera da `grid-auto-rows`, e
       da lì le righe degli orari smettevano di corrispondere a quelle scritte
       nella colonna a sinistra. Adesso è una striscia sua, appiccicata in alto
       mentre si scorre — in sovraimpressione, come chiesto — con le stesse
       colonne della griglia così resta allineata. */
    var vecchiaAd = $('plWeekAllDay');
    if (vecchiaAd) vecchiaAd.parentNode.removeChild(vecchiaAd);
    var vecchiaEt = $('plWeekAllDayLab');
    if (vecchiaEt) vecchiaEt.parentNode.removeChild(vecchiaEt);
    if (WK.adH) {
        var fascia = el('div', 'pl-wk-ad');
        fascia.id = 'plWeekAllDay';
        fascia.style.gridTemplateColumns = colonne;
        fascia.style.height = WK.adH + 'px';
        adPerGiorno.forEach(function (g) {
            var cAd = el('div', 'pl-wk-adcell'
                + (g.date === S.today ? ' pl-oggi' : '')
                + (g.date === S.focusDate ? ' pl-scelto' : ''));
            cAd.dataset.date = g.date;
            g.list.slice(0, 3).forEach(function (ev) {
                var b2 = el('div', 'pl-wk-chip pl-wk-chip-ad' + (ev.done ? ' pl-done' : ''), ev.title || T('planner.senza_titolo_l'));
                b2.style.setProperty('--ev', evColor(ev));
                b2.dataset.id = ev.id;
                b2.dataset.date = ev.date || g.date;
                cAd.appendChild(b2);
            });
            if (g.list.length > 3) cAd.appendChild(el('div', 'pl-wk-piu', '+' + (g.list.length - 3)));
            fascia.appendChild(cAd);
        });
        /* Va nell'intestazione, non dentro lo scorrevole.
           `position: sticky` non poteva funzionare: la colonna delle ore non
           scorre davvero, la sposta una translateY scritta a mano, e sticky
           non sa niente di quella. Risultato: la fascia scorreva via con gli
           orari e gli eventi di tutto il giorno sembravano avere un orario.
           Qui invece sta sopra la griglia e non si muove mai in verticale. */
        var clip = document.querySelector('.pl-wk-topclip');
        if (clip) clip.appendChild(fascia);

        var corner = document.querySelector('.pl-wk-corner');
        if (corner) {
            var et = el('div', 'pl-wk-adlab', 'gg');
            et.id = 'plWeekAllDayLab';
            et.style.height = WK.adH + 'px';
            corner.appendChild(et);
        }
    }

    var sc = $('plWeekScroll');
    if (sc) {
        sc.scrollLeft = WK_PRIMA * wkLarghezzaCol();
        sc.scrollTop = Math.max(0, Math.min(24 * WK.riga - sc.clientHeight, altezzaPrima));
        wkSegue();
    }
    wkSegnaScelto();
    wkLineaAdesso();
    /* la scena della barra in alto racconta il giorno scelto anche qui */
    applyAmbient();
}

/* La riga di adesso, che nella vista giorno c'è da sempre e qui mancava.

   Sono due righe e non una. Quella pallida attraversa tutta la striscia
   perché l'ora è la stessa in tutte le colonne, e serve a leggere «a che
   altezza siamo» anche mentre si guarda giovedì; quella marcata, con il
   pallino, sta solo sulla colonna di oggi, perché è lì e solo lì che quella
   riga vuol dire «adesso» e non «la stessa ora di un altro giorno».

   La posizione orizzontale si scrive con `calc(var(--wk-col) * n)` invece di
   misurare la colonna: la larghezza la sa già il CSS, e chiederla al browser
   in un ridisegno sarebbe un calcolo di layout in più per niente. */
function wkLineaAdesso() {
    var grid = $('plWeekGrid');
    if (!grid) return;
    var vecchie = grid.querySelectorAll('.pl-wk-now');
    for (var v = 0; v < vecchie.length; v++) vecchie[v].parentNode.removeChild(vecchie[v]);
    if (!WK.inizio) return;

    var ora = new Date();
    var min = ora.getHours() * 60 + ora.getMinutes();
    /* Nessun correttivo per la fascia di tutto il giorno: sta fuori dalla
       griglia e la riga si misura dentro la griglia, che comincia sotto. */
    var top = (min / 60) * WK.riga;

    var riga = el('div', 'pl-wk-now');
    riga.style.top = top + 'px';
    grid.appendChild(riga);

    /* oggi può essere fuori dalla finestra disegnata: in quel caso la riga
       marcata non c'è, e resta solo quella pallida */
    var i = diffDays(WK.inizio, S.today);
    if (i < 0 || i >= WK_GIORNI) return;
    var oggi = el('div', 'pl-wk-now pl-wk-now-oggi');
    oggi.style.top = top + 'px';
    oggi.style.left = 'calc(var(--wk-col) * ' + i + ')';
    oggi.style.width = 'var(--wk-col)';
    grid.appendChild(oggi);
}

/* Una casella: gli eventi che cominciano in quell'ora. Quando sono troppi
   per lo spazio, i meno importanti si stringono in un solo mucchietto —
   toccandolo si aprono tutti. È la stessa idea del compattamento nella
   griglia a orari: in una casella alta quaranta pixel tre nomi non ci
   stanno, e stamparli lo stesso vorrebbe dire non leggerne nessuno. */
function wkCella(giorno, h) {
    var cella = el('div', 'pl-wk-cell'
        + (giorno === S.today ? ' pl-oggi' : '')
        + (giorno === S.focusDate ? ' pl-scelto' : ''));
    cella.dataset.date = giorno;
    cella.dataset.ora = h;

    /* un evento a cavallo della mezzanotte compare all'ora in cui comincia
       il suo pezzo di oggi: il 28 a mezzanotte, non di nuovo alle 11 */
    var dentro = (S.events[giorno] || []).filter(function (ev) {
        return !inFascia(ev) && Math.floor(toMin(pezzoDelGiorno(ev, giorno).start) / 60) === h;
    });
    /* prima i più importanti, e a parità i più lunghi: se qualcosa deve
       finire nel mucchietto, che sia il meno significativo */
    var peso = { highest: 0, high: 1, normal: 2, low: 3 };
    dentro.sort(function (a, b) {
        var pa = peso[a.importance] !== undefined ? peso[a.importance] : 2;
        var pb = peso[b.importance] !== undefined ? peso[b.importance] : 2;
        if (pa !== pb) return pa - pb;
        return durataMinuti(b) - durataMinuti(a);
    });

    var posti = Math.max(1, Math.floor((WK.riga - 2) / 13));
    var mostrati = dentro.length > posti ? dentro.slice(0, posti - 1) : dentro;

    mostrati.forEach(function (ev) {
        var chip = el('div', 'pl-wk-chip');
        chip.style.setProperty('--ev', evColor(ev));
        if (ev.done) chip.classList.add('pl-done');
        chip.textContent = ev.title || T('planner.senza_titolo');
        chip.addEventListener('click', function (e) {
            e.stopPropagation();
            openEventSheet(ev, null);
        });
        cella.appendChild(chip);
    });

    var restano = dentro.slice(mostrati.length);
    if (restano.length) {
        var piu = el('div', 'pl-wk-piu', '+' + restano.length);
        piu.addEventListener('click', function (e) {
            e.stopPropagation();
            miniPopup(restano);
        });
        cella.appendChild(piu);
    }

    cella.addEventListener('click', function () {
        var oo = parseInt(this.dataset.ora, 10);
        openEventSheet(null, {
            date: this.dataset.date,
            start: toHM(oo * 60), end: toHM(Math.min(1439, oo * 60 + 60))
        });
    });
    return cella;
}

function wkLarghezzaCol() {
    var h = $('plWeekHeads');
    var c = h ? h.querySelector('.pl-wk-head') : null;
    return c ? c.getBoundingClientRect().width : 1;
}

/* Intestazioni e colonna delle ore seguono lo scorrimento a mano: è il
   prezzo per averle fuori dalla griglia, ed è una scrittura sola per gesto. */
function wkSegue() {
    var sc = $('plWeekScroll'), heads = $('plWeekHeads'), gut = $('plWeekGut');
    if (!sc || !heads || !gut) return;
    heads.style.transform = 'translateX(' + (-sc.scrollLeft) + 'px)';
    var fascia = $('plWeekAllDay');
    if (fascia) fascia.style.transform = 'translateX(' + (-sc.scrollLeft) + 'px)';
    gut.style.transform = 'translateY(' + (-sc.scrollTop) + 'px)';
}

(function wkScorrimento() {
    var sc = $('plWeekScroll');
    if (!sc) return;

    /* ==================================================================
       Lo scorrimento della settimana è fatto a mano, con i puntatori, e
       non è più quello del browser. Tre cose lo chiedevano insieme, e
       nessuna delle tre si poteva ottenere con lo scorrimento nativo:

       - UN VERSO PER VOLTA. Con lo scorrimento del browser il verso
         perdente si può solo rimettere a posto *dopo* che si è mosso, e su
         iOS toccare la posizione in mezzo a un lancio lo interrompe: si
         finiva a scorrere in diagonale, e certi lanci morivano a metà (era
         questo, e non un limite di giorni, a far sembrare che oltre il
         terzo giorno la striscia fosse bloccata).
       - IL GIORNO SI SCEGLIE QUANDO SI ALZA IL DITO. A metà strada fra due
         colonne non c'è nessun giorno da scegliere; sceglierlo lo stesso a
         ogni fotogramma faceva ballare intestazioni, colonna delle ore e
         scena in alto mentre il dito era ancora giù.
       - L'AGGANCIO ALLA COLONNA DEVE ESSERE NOSTRO, se no lo scatto del
         browser (`scroll-snap`) e il nostro si contendono la stessa
         posizione e vince a caso uno dei due.

       In cambio si perde lo slancio nativo, e quindi ce lo facciamo noi:
       sono venti righe, e almeno si ferma dove diciamo. Il riquadro ha
       `overflow:hidden` e `touch-action:none` nel CSS — resta scorrevole da
       codice, che è tutto quello che serve.
       ================================================================== */
    var g = null;      // il gesto in corso, con tutte le misure prese all'inizio
    var anim = null;   // slancio o aggancio in corso

    function fermaAnim() { if (anim) { cancelAnimationFrame(anim); anim = null; } }

    /* Un'animazione sola per tutt'e due i versi: si parte da dove si è e si
       arriva dove si deve, con la frenata dolce. `poi` scatta a fine corsa —
       ed è lì, e solo lì, che il giorno cambia. */
    function animaA(x, y, ms, poi) {
        fermaAnim();
        var x0 = sc.scrollLeft, y0 = sc.scrollTop, t0 = 0;
        function passo(t) {
            if (!t0) t0 = t;
            var k = Math.min(1, (t - t0) / ms);
            var e = 1 - Math.pow(1 - k, 3);
            if (x !== null) sc.scrollLeft = x0 + (x - x0) * e;
            if (y !== null) sc.scrollTop = y0 + (y - y0) * e;
            wkSegue();
            if (k < 1) { anim = requestAnimationFrame(passo); return; }
            anim = null;
            if (poi) poi();
        }
        anim = requestAnimationFrame(passo);
    }

    /* Il giorno scelto. Si arriva qui a gesto finito e a striscia ferma:
       l'indice della colonna è esatto, non c'è niente da indovinare. */
    function scegliGiorno(i) {
        var nuovo = addDays(WK.inizio, i);
        if (nuovo === S.focusDate) return;
        S.focusDate = nuovo;
        renderWeekBits();
        /* Vicino a un bordo la finestra si rifà intorno al giorno nuovo.

           Il bordo destro non è l'ultima colonna disegnata ma l'ultima a cui
           si può *arrivare*: con ventun colonne e sette in vista, la striscia
           si ferma alla quattordicesima, e la soglia scritta sul totale
           (`i > 17`) non veniva mai raggiunta. Risultato: la finestra non si
           rifaceva mai in avanti e dopo qualche giorno si sbatteva contro il
           muro, mentre all'indietro tutto funzionava. Qui l'ultima colonna
           raggiungibile si calcola da quante ne stanno sullo schermo. */
        var largh = wkLarghezzaCol();
        var inVista = Math.max(1, Math.round(sc.clientWidth / Math.max(1, largh)));
        var ultima = Math.max(1, WK_GIORNI - inVista);
        if (i < 3 || i > ultima - 3) renderWeek();
        else {
            wkSegnaScelto();
            /* colonna delle ore e scena in alto parlano del giorno scelto:
               senza questo restavano su quello di prima */
            renderGutterMeteo($('plWeekGut'), S.focusDate);
            applyAmbient();
        }
    }

    sc.addEventListener('pointerdown', function (e) {
        if (WK.zoomando) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        fermaAnim();
        /* tutte le misure del gesto si prendono qui, una volta: dentro il
           movimento si fanno solo conti e scritture */
        var w = wkLarghezzaCol();
        g = {
            id: e.pointerId,
            x: e.clientX, y: e.clientY, lx: e.clientX, ly: e.clientY, t: e.timeStamp,
            left: sc.scrollLeft, top: sc.scrollTop, w: w,
            mx: Math.max(0, WK_GIORNI * w - sc.clientWidth),
            my: Math.max(0, 24 * WK.riga - sc.clientHeight),
            asse: null, vx: 0, vy: 0, mosso: false
        };
        try { sc.setPointerCapture(e.pointerId); } catch (err) {}
    });

    sc.addEventListener('pointermove', function (e) {
        if (!g || e.pointerId !== g.id || WK.zoomando) return;
        var dx = e.clientX - g.x, dy = e.clientY - g.y;
        if (g.asse === null) {
            /* sei pixel di soglia: sotto, il verso non lo sa nemmeno chi sta
               toccando, e deciderlo lì vorrebbe dire tirare a sorte */
            if (Math.max(Math.abs(dx), Math.abs(dy)) < 6) return;
            g.asse = Math.abs(dx) >= Math.abs(dy) ? 'x' : 'y';
            g.mosso = true;
        }
        var dt = Math.max(1, e.timeStamp - g.t);
        g.vx = (e.clientX - g.lx) / dt;
        g.vy = (e.clientY - g.ly) / dt;
        g.t = e.timeStamp; g.lx = e.clientX; g.ly = e.clientY;
        if (g.asse === 'x') sc.scrollLeft = Math.max(0, Math.min(g.mx, g.left - dx));
        else                sc.scrollTop  = Math.max(0, Math.min(g.my, g.top - dy));
        wkSegue();
    });

    function fine(e) {
        if (!g || e.pointerId !== g.id) return;
        var gg = g; g = null;
        if (!gg.asse) return;              // un tocco fermo: era un tocco, non un gesto
        if (gg.asse === 'x') {
            var w = Math.max(1, gg.w);
            var i = Math.round(sc.scrollLeft / w);
            /* un colpo secco vale un giorno anche se il dito non è arrivato a
               metà colonna: è il gesto con cui si sfoglia, e pretendere metà
               strada lo farebbe sembrare rotto */
            if (Math.abs(gg.vx) > 0.4) {
                i = gg.vx < 0 ? Math.ceil(sc.scrollLeft / w) : Math.floor(sc.scrollLeft / w);
            }
            i = Math.max(0, Math.min(WK_GIORNI - 1, i));
            animaA(i * w, null, 220, function () { scegliGiorno(i); });
        } else {
            /* slancio verticale con attrito: si ferma da sé o contro il
               fondo, e non c'è nessun giorno da scegliere */
            var v = gg.vy * 16;
            (function passo() {
                v *= 0.94;
                if (Math.abs(v) < 0.4) { anim = null; return; }
                var y = sc.scrollTop - v;
                var lim = Math.max(0, Math.min(gg.my, y));
                sc.scrollTop = lim;
                wkSegue();
                if (Math.abs(lim - y) > 0.5) { anim = null; return; }   // muro
                anim = requestAnimationFrame(passo);
            })();
        }
    }
    sc.addEventListener('pointerup', fine);
    sc.addEventListener('pointercancel', fine);

    /* Con il riquadro non più scorrevole da sé, la rotellina e il tappetino
       del computer resterebbero senza risposta. */
    sc.addEventListener('wheel', function (e) {
        if (WK.zoomando) return;
        fermaAnim();
        var my = Math.max(0, 24 * WK.riga - sc.clientHeight);
        var mx = Math.max(0, WK_GIORNI * wkLarghezzaCol() - sc.clientWidth);
        if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
            sc.scrollLeft = Math.max(0, Math.min(mx, sc.scrollLeft + e.deltaX));
        } else {
            sc.scrollTop = Math.max(0, Math.min(my, sc.scrollTop + e.deltaY));
        }
        wkSegue();
        e.preventDefault();
        if (wkScrollTimer) clearTimeout(wkScrollTimer);
        wkScrollTimer = setTimeout(function () {
            scegliGiorno(Math.max(0, Math.min(WK_GIORNI - 1,
                Math.round(sc.scrollLeft / Math.max(1, wkLarghezzaCol())))));
        }, 200);
    }, { passive: false });

    /* Zoom a due dita: cambia l'altezza della riga, che qui è l'unica cosa
       che voglia dire "più vicino". Niente ridisegno durante il gesto —
       basta la variabile — quindi il gesto non può morirci sotto.

       L'orario che sta in mezzo alle due dita al primo tocco resta inchiodato
       all'altezza dello schermo in cui era, esattamente come nella vista
       giorno: l'altezza si prende una volta all'inizio e non la si guarda
       più. Tenerlo agganciato al centro delle dita di adesso vorrebbe dire
       farlo scivolare via proprio mentre lo si sta guardando. */
    var punti = {}, base = 0, rigaBase = 0;
    var ancoraMin = 0, ancoraY = 0, scTop = 0, scH = 0;
    function dist2() {
        var k = Object.keys(punti);
        if (k.length !== 2) return 0;
        var a = punti[k[0]], b = punti[k[1]];
        return Math.hypot(a.x - b.x, a.y - b.y);
    }
    function meta2() {
        var k = Object.keys(punti);
        if (k.length !== 2) return 0;
        return (punti[k[0]].y + punti[k[1]].y) / 2;
    }
    sc.addEventListener('pointerdown', function (e) {
        if (e.pointerType !== 'touch') return;
        punti[e.pointerId] = { x: e.clientX, y: e.clientY };
        if (Object.keys(punti).length === 2) {
            /* arrivato il secondo dito, il trascinamento a uno solo non c'è
               più: se no si sposterebbe e si zoomerebbe insieme */
            g = null;
            fermaAnim();
            Object.keys(punti).forEach(function (id) {
                try { sc.setPointerCapture(Number(id)); } catch (err) {}
            });
            base = dist2(); rigaBase = WK.riga;
            WK.zoomando = true;
            var box = sc.getBoundingClientRect();
            scTop = box.top; scH = sc.clientHeight;
            ancoraY = meta2();
            ancoraMin = ((sc.scrollTop + (ancoraY - scTop)) / Math.max(1, rigaBase)) * 60;
            document.documentElement.classList.add('pl-zoomando');
        }
    });
    sc.addEventListener('pointermove', function (e) {
        if (!(e.pointerId in punti)) return;
        punti[e.pointerId] = { x: e.clientX, y: e.clientY };
        if (Object.keys(punti).length !== 2 || !base) return;
        WK.riga = Math.max(28, Math.min(130, Math.round(rigaBase * dist2() / base)));
        $('plWeekGrid').style.setProperty('--wk-row', WK.riga + 'px');
        $('plWeekGut').style.setProperty('--wk-row', WK.riga + 'px');
        /* con le righe che cambiano altezza cambia anche dove cade adesso:
           lasciarla ferma la farebbe scivolare su un'ora che non è la sua */
        wkLineaAdesso();
        wkScrollToMinuteAt(ancoraMin, ancoraY, scTop, scH);
        wkSegue();
    });
    function su(e) {
        if (!(e.pointerId in punti)) return;
        delete punti[e.pointerId];
        if (Object.keys(punti).length < 2 && base) {
            base = 0;
            WK.zoomando = false;
            document.documentElement.classList.remove('pl-zoomando');
            /* con la riga cambiata cambia anche quanto ci sta dentro: le
               caselle vanno rifatte, ma a gesto finito */
            if (S.view === 'week') renderWeek();
        }
    }
    sc.addEventListener('pointerup', su);
    sc.addEventListener('pointercancel', su);
})();

/* Il giorno su cui lo scorrimento si è fermato, segnato in modo diverso da
   oggi: sono due cose diverse e confonderle toglierebbe senso a tutt'e due. */
function wkSegnaScelto() {
    ['plWeekGrid', 'plWeekHeads'].forEach(function (id) {
        var box = $(id);
        if (!box) return;
        var vecchi = box.querySelectorAll('.pl-scelto');
        for (var i = 0; i < vecchi.length; i++) vecchi[i].classList.remove('pl-scelto');
        var nuovi = box.querySelectorAll('[data-date="' + S.focusDate + '"]');
        for (var k = 0; k < nuovi.length; k++) nuovi[k].classList.add('pl-scelto');
    });
}

/* ------------------------------------------------------------------ */
/* Il menu delle viste                                                 */
/* ------------------------------------------------------------------ */
/* Su schermo largo il pulsante resta l'interruttore di sempre fra mese e
   griglia a orari. Su telefono in verticale le viste sono tre, e un
   interruttore non sa dire tre cose: si apre un menu, con la voce di adesso
   tinta del colore della pagina. */
(function menuViste() {
    var btn = $('plViewBtn'), menu = $('plViewMenu');
    if (!btn || !menu) return;
    scriviVista();
    /* Il menu vive nel body e non nell'intestazione: la barra in alto ha il
       vetro (backdrop-filter), che per un figlio `fixed` diventa il riquadro
       di riferimento e lo ritaglia. Con il pulsante nella seconda riga il
       menu usciva dal fondo della barra e se ne vedeva solo metà. */
    document.body.appendChild(menu);

    function chiudi() {
        if (!menu.classList.contains('pl-open')) return;
        menu.classList.remove('pl-open');
        menu.setAttribute('aria-hidden', 'true');
        /* in vista mese il tastino resta acceso di suo: chiudendo il menu
           non gli si spegne addosso il segno che non era nostro */
        btn.classList.toggle('pl-on', S.view === 'month');
    }

    function apri() {
        var voci = menu.querySelectorAll('button');
        for (var i = 0; i < voci.length; i++) {
            voci[i].classList.toggle('pl-on', voci[i].dataset.v === S.view);
        }
        menu.classList.add('pl-open');
        menu.setAttribute('aria-hidden', 'false');
        /* il tastino resta premuto: è da lì che il menu è sceso, e senza
           quel segno sembra comparso da nessuna parte */
        btn.classList.add('pl-on');

        /* La posizione si prende dal tastino, una volta sola all'apertura.
           Serve `fixed` perché dentro la barra in alto il menu veniva
           ritagliato, e perché deve passare sopra la fascia delle priorità. */
        var r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.left = '0px';
        var w = menu.getBoundingClientRect().width;
        var x = r.left + r.width / 2 - w / 2;
        menu.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x)) + 'px';
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!isSchermoStretto()) { setView(S.view === 'day' ? 'month' : 'day'); return; }
        if (menu.classList.contains('pl-open')) chiudi();
        else apri();
    });

    menu.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button') : null;
        if (!b) return;
        e.stopPropagation();
        chiudi();
        setView(b.dataset.v);
    });

    /* Qualunque altra cosa lo chiude: un tocco altrove, uno scorrimento, la
       rotella. Il menu è appeso al tastino, e appena si guarda da un'altra
       parte non ha più motivo di stare lì. Si ascolta in fase di cattura per
       arrivare prima di chi si ferma il gesto per sé. */
    function altrove(e) {
        if (!menu.classList.contains('pl-open')) return;
        var t = e && e.target;
        if (t && t.closest && (t.closest('#plViewMenu') || t.closest('#plViewBtn'))) return;
        chiudi();
    }
    document.addEventListener('pointerdown', altrove, true);
    document.addEventListener('wheel', function () { chiudi(); }, { passive: true, capture: true });
    document.addEventListener('touchmove', function () { chiudi(); }, { passive: true, capture: true });
    document.addEventListener('scroll', function () { chiudi(); }, true);
    window.addEventListener('resize', chiudi);
})();

function renderMonth() {
    var cursor = parseIso(S.monthCursor);
    var bar = $('plMonthsBar');
    var wasOpen = bar.classList.contains('pl-open');
    bar.innerHTML = '';
    for (var m = -18; m <= 18; m++) {   // tre anni di mesi: la barra scorre
        var dd = new Date(cursor.getFullYear(), cursor.getMonth() + m, 1);
        var chip = el('button', 'pl-mchip' + (m === 0 ? ' pl-on' : ''), MESI[dd.getMonth()].slice(0, 3) + (dd.getFullYear() !== cursor.getFullYear() ? ' ' + String(dd.getFullYear()).slice(2) : ''));
        chip.type = 'button';
        chip.dataset.month = iso(dd);
        chip.addEventListener('click', function () {
            S.monthCursor = this.dataset.month;
            bar.classList.remove('pl-open');
            renderMonth();
            syncScrollerTopAnimated();
        });
        bar.appendChild(chip);
    }
    if (wasOpen) bar.classList.add('pl-open');
    setTimeout(function () {
        var onChip = bar.querySelector('.pl-mchip.pl-on');
        if (onChip) bar.scrollLeft = onChip.offsetLeft - bar.clientWidth / 2 + onChip.clientWidth / 2;
    }, 30);

    $('plMonthName').textContent = MESI[cursor.getMonth()];
    $('plMonthYear').textContent = cursor.getFullYear();
    applyAmbient();

    var grid = $('plMonthGrid');
    grid.innerHTML = '';
    var wd = el('div', 'pl-mwd');
    GIORNI_S.forEach(function (g) { wd.appendChild(el('span', null, g)); });
    grid.appendChild(wd);

    var start = monday(iso(new Date(cursor.getFullYear(), cursor.getMonth(), 1)));
    var need = [];
    for (var i = 0; i < 42; i++) need.push(addDays(start, i));
    var missing = {};
    need.forEach(function (d) { if (!S.events[d]) missing[monday(d)] = true; });
    Object.keys(missing).forEach(function (w) { loadWeek(w); });

    for (var r = 0; r < 6; r++) {
        var row = el('div', 'pl-mrow');
        var inizioRiga = addDays(start, r * 7);
        var sett = corsieDellaSettimana(inizioRiga);
        for (var c = 0; c < 7; c++) {
            var ds = addDays(start, r * 7 + c);
            row.appendChild(monthCell(ds, parseIso(ds).getMonth() === cursor.getMonth(),
                                      sett.corsie.length, sett.conBarra));
        }
        if (sett.corsie.length) row.appendChild(stricheDellaSettimana(inizioRiga, sett.corsie));
        grid.appendChild(row);
    }
}

/* Quante barre servono in questa settimana e su che corsia sta ognuna.
   Un evento lungo non può stare dentro una casella — le caselle hanno il
   taglio ai bordi e il testo sparirebbe al primo giorno — quindi le barre si
   disegnano su uno strato sopra la riga, che è largo tutta la settimana.
   Qui si decide solo la geometria; il disegno lo fa la funzione dopo. */
var PL_MAX_CORSIE = 3;

function corsieDellaSettimana(inizioRiga) {
    var fineRiga = addDays(inizioRiga, 6);

    /* Gli eventi lunghi stanno in tutte le giornate che coprono (li mette lì
       indexEvents), quindi la stessa riga si incontra fino a sette volte:
       si tiene una volta sola, per id. */
    var visti = {}, pezzi = [];
    for (var i = 0; i < 7; i++) {
        eventsOf(addDays(inizioRiga, i)).forEach(function (ev) {
            if (!ev.giorno_tot || ev.giorno_tot < 2 || visti[ev.id]) return;
            visti[ev.id] = true;
            /* Il pezzo di questa settimana: un evento dal 28 al 3 si spezza in
               due, e ogni metà viene ritagliata sui bordi della sua riga. */
            var da = ev.date > inizioRiga ? ev.date : inizioRiga;
            var a  = (ev.end_date || ev.date) < fineRiga ? (ev.end_date || ev.date) : fineRiga;
            pezzi.push({
                ev: ev,
                col: giorniFra(inizioRiga, da) - 1,
                lung: giorniFra(da, a),
                apre:  da === ev.date,                       // il vero primo giorno
                chiude: a === (ev.end_date || ev.date)       // il vero ultimo
            });
        });
    }
    if (!pezzi.length) return { corsie: [], conBarra: {} };

    /* Chi comincia prima sta più in alto; a parità, il più lungo — così le
       barre lunghe fanno da base e le corte si infilano sopra, invece di
       spezzare una lunga in mezzo alla settimana. */
    pezzi.sort(function (a, b) {
        if (a.col !== b.col) return a.col - b.col;
        if (a.lung !== b.lung) return b.lung - a.lung;
        return a.ev.id - b.ev.id;
    });

    var corsie = [], conBarra = {};
    pezzi.forEach(function (p) {
        for (var k = 0; k < corsie.length; k++) {
            if (corsiaLibera(corsie[k], p)) { corsie[k].push(p); conBarra[p.ev.id] = true; return; }
        }
        if (corsie.length < PL_MAX_CORSIE) { corsie.push([p]); conBarra[p.ev.id] = true; }
        /* Oltre il massimo non si aggiungono corsie. `conBarra` resta senza
           questo id apposta: la casella lo rimetterà nel suo elenco come una
           riga qualsiasi, e finirà nel "+N" come tutti gli altri.
           Prima veniva tolto dalla casella perché "è disegnato come barra" e
           poi non disegnato perché le corsie erano finite: spariva e basta. */
    });
    return { corsie: corsie, conBarra: conBarra };
}

function corsiaLibera(corsia, p) {
    for (var i = 0; i < corsia.length; i++) {
        var q = corsia[i];
        if (p.col < q.col + q.lung && q.col < p.col + p.lung) return false;
    }
    return true;
}

function stricheDellaSettimana(inizioRiga, corsie) {
    var strato = el('div', 'pl-mspans');
    corsie.forEach(function (corsia, k) {
        corsia.forEach(function (p) {
            var b = el('div', 'pl-mspan'
                + (p.apre ? ' pl-msp-apre' : '')
                + (p.chiude ? ' pl-msp-chiude' : ''));
            b.style.gridColumn = (p.col + 1) + ' / span ' + p.lung;
            b.style.gridRow = (k + 1);
            b.style.setProperty('--ev', evColor(p.ev));
            /* Il titolo si scrive all'inizio del pezzo, quindi anche a capo
               settimana: una barra che entra da lunedì senza nome non si
               saprebbe di cosa è la continuazione. */
            b.textContent = p.apre ? p.ev.title : ('… ' + p.ev.title);
            strato.appendChild(b);
        });
    });
    return strato;
}

function monthCell(date, inMonth, corsie, conBarra) {
    var cell = el('div', 'pl-mcell' + (inMonth ? '' : ' pl-out')
        + (date < S.today ? ' pl-past-d' : '') + (date === S.today ? ' pl-today-d' : ''));
    cell.dataset.date = date;   // serve a "tieni premuto e trascina" (MP, piu' sotto)
    var wtag = wxTag(date);
    if (wtag) cell.appendChild(wtag);   // sta nell'angolo, fuori dal flusso
    cell.appendChild(el('div', 'pl-mcell-n pl-serif', String(parseIso(date).getDate())));

    /* Lo spazio che le barre occupano sopra la casella. Le barre stanno su uno
       strato a parte, quindi non spingono niente da sole: senza questo vuoto
       si stamperebbero sopra i primi eventi della casella. Tutte le caselle
       della stessa riga ricevono lo stesso numero di corsie, così i giorni
       restano allineati anche dove la barra non passa. */
    if (corsie > 0) {
        var vuoto = el('div', 'pl-mcell-corsie');
        /* L'altezza di una corsia sta nel CSS (--pl-corsia): scriverla anche
           qui vorrebbe dire due numeri da tenere d'accordo, e il giorno che
           uno dei due cambia le barre finiscono sopra agli eventi. */
        vuoto.style.height = 'calc(' + corsie + ' * var(--pl-corsia, 15px))';
        cell.appendChild(vuoto);
    }

    /* nella casella del mese contano prima gli importantissimi, poi gli
       importanti, e all'interno di ciascun gruppo l'ordine dell'orario */
    var rank = { highest: 0, high: 1, normal: 2 };
    var list = eventsOf(date).slice()
        /* Gli eventi di più giorni che hanno ottenuto una barra sono già
           disegnati sopra: qui tornerebbero una seconda volta, una per ogni
           giornata coperta. Quelli rimasti fuori dalle corsie invece restano,
           altrimenti sparirebbero da entrambe le parti. */
        .filter(function (e) { return !(e.giorno_tot > 1 && conBarra && conBarra[e.id]); })
        .sort(function (a, b) {
        var ra = rank[a.importance] !== undefined ? rank[a.importance] : 2;
        var rb = rank[b.importance] !== undefined ? rank[b.importance] : 2;
        if (ra !== rb) return ra - rb;
        if (a.all_day !== b.all_day) return a.all_day ? -1 : 1;
        return toMin(a.start) - toMin(b.start);
    });
    list.slice(0, 3).forEach(function (ev) {
        var e = el('div', 'pl-mev', ev.title);
        e.style.setProperty('--ev', evColor(ev));
        cell.appendChild(e);
    });
    if (list.length > 3) cell.appendChild(el('div', 'pl-mmore', '+' + (list.length - 3)));

    var dj = S.diary[date];
    if (dj && (dj.has_text || dj.has_lists)) {
        var foot = el('div', 'pl-mcell-foot');
        var tag2 = el('button', 'pl-mtag');
        tag2.type = 'button';
        tag2.setAttribute('aria-label', T('planner.leggi_il_diario_di_questo'));
        tag2.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 4h12v16H8a2 2 0 01-2-2z"/></svg>';
        tag2.addEventListener('click', function (e) {
            e.stopPropagation();
            if (window.standoutDiaryPeek) window.standoutDiaryPeek(date);
        });
        foot.appendChild(tag2);
        cell.appendChild(foot);
    }
    /* Un tocco entra nel giorno, e basta: è l'unica cosa che ci si aspetta
       toccando una casella del calendario. L'elenco degli eventi ha adesso la
       sua maniglia sotto la griglia, così i due gesti non si contendono più
       lo stesso tocco. */
    cell.addEventListener('click', function () {
        /* Con l'elenco aperto, toccare fuori vuol dire "chiudi" e basta: è il
           primo gesto che viene in mente per far sparire un pannello. */
        if (monthAgendaAperta()) { closeMonthAgenda(); return; }
        if (Date.now() - magendaChiusaA < 400) return;
        /* il tocco che chiude un "tieni premuto" non e' un tocco per entrare */
        if (MP.attivo || Date.now() - MP.finitoA < 500) return;
        entraNelGiorno(date, cell);
    });
    return cell;
}

/* Da quanto il pannello è stato chiuso. Serve perché sul telefono la
   chiusura avviene già al `pointerdown` sulla griglia, e il `click` che
   arriva subito dopo trovava il pannello chiuso: apriva il giorno su cui il
   dito si era appoggiato per chiudere, che non è quello che si voleva. */
var magendaChiusaA = 0;

function monthAgendaAperta() {
    var box = $('plMonthAgenda');
    return !!(box && box.classList.contains('pl-open'));
}

/* Dal mese al giorno: l'elenco si chiude, la casella fa la sua animazione e
   poi si passa alla vista settimanale su quel giorno. */
function entraNelGiorno(date, cell) {
    closeMonthAgenda();
    if (cell) cell.classList.add('pl-anim-open');
    setTimeout(function () {
        S.focusDate = date;
        setView('day');
        renderWeekBits();
        renderPrio();
        loadWeek(monday(date));
    }, 140);
}

/* ------------------------------------------------------------------ */
/* Elenco degli eventi dal giorno scelto in avanti                     */
/* ------------------------------------------------------------------ */
function closeMonthAgenda() {
    var box = $('plMonthAgenda');
    if (!box) return;
    box.classList.remove('pl-open');
    box.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('pl-magenda-open');
    magendaChiusaA = Date.now();
    var pull = $('plMonthPull');
    if (pull) pull.classList.remove('pl-aperto');
}

/* La maniglia sotto il calendario tira su l'elenco. Parte da oggi se oggi è
   nel mese che si sta guardando, se no dal suo primo giorno: è il punto da cui
   uno si aspetta che l'elenco cominci. */
(function maniglia() {
    var b = $('plMonthPull');
    if (!b) return;
    b.addEventListener('click', function (e) {
        e.stopPropagation();
        if (monthAgendaAperta()) { closeMonthAgenda(); return; }
        var c = parseIso(S.monthCursor);
        var primo = iso(new Date(c.getFullYear(), c.getMonth(), 1));
        var oggiQui = parseIso(S.today).getMonth() === c.getMonth()
                   && parseIso(S.today).getFullYear() === c.getFullYear();
        openMonthAgenda(oggiQui ? S.today : primo);
    });
})();

/* Toccare fuori dal pannello lo chiude, non solo sulle caselle: il dito può
   finire anche sullo sfondo della griglia o sull'intestazione del mese. */
(function chiudiMeseFuori() {
    var mv = $('plMonthView');
    if (!mv) return;
    mv.addEventListener('click', function (e) {
        if (!monthAgendaAperta()) return;
        if (e.target.closest && e.target.closest('#plMonthAgenda')) return;
        closeMonthAgenda();
    });
})();

function openMonthAgenda(fromDate) {
    var box = $('plMonthAgenda'), body = $('plMagendaBody');
    if (!box || !body) return;
    S.agendaFrom = fromDate;
    body.innerHTML = '';
    body.scrollTop = 0;

    /* trenta giorni in avanti: quanto basta per farsi un'idea senza
       trascinare in memoria mesi interi */
    var giorni = 30, trovati = 0, mancanti = {};
    for (var i = 0; i < giorni; i++) {
        var ds = addDays(fromDate, i);
        if (!S.events[ds]) mancanti[monday(ds)] = true;
    }
    Object.keys(mancanti).forEach(function (w) { loadWeek(w); });

    for (var k = 0; k < giorni; k++) {
        var d2 = addDays(fromDate, k);
        var lista = eventsOf(d2);
        if (!lista.length) continue;
        body.appendChild(agendaDayHeader(d2));
        lista.sort(function (a, b) {
            if (a.all_day !== b.all_day) return a.all_day ? -1 : 1;
            return toMin(a.start) - toMin(b.start);
        }).forEach(function (ev) { body.appendChild(agendaRow(ev)); });
        trovati++;
    }
    if (!trovati) {
        body.appendChild(agendaDayHeader(fromDate));
        body.appendChild(el('div', 'pl-ma-empty', T('planner.niente_in_programma_nei_prossimi')));
    }

    box.classList.add('pl-open');
    box.setAttribute('aria-hidden', 'false');
    document.body.classList.add('pl-magenda-open');
    var pull = $('plMonthPull');
    if (pull) pull.classList.add('pl-aperto');
}

/* "OGGI 15/9", "DOMANI 16/9", poi il giorno della settimana */
function agendaDayHeader(date) {
    var d = parseIso(date);
    var head = el('div', 'pl-ma-day');
    var etichetta;
    if (date === S.today) etichetta = T('comune.oggi');
    else if (date === addDays(S.today, 1)) etichetta = T('comune.domani');
    else if (date === addDays(S.today, -1)) etichetta = T('planner.ieri_t');
    else etichetta = GIORNI[dowIndex(date)];

    /* Il nome del giorno è un pulsante: toccandolo si va a vedere quel giorno
       nella vista settimanale. Il tag del meteo resta fuori, che ha già il
       suo tocco e aprirebbe due cose insieme. */
    var vai = el('button', 'pl-ma-goto');
    vai.type = 'button';
    vai.setAttribute('aria-label', T('planner.vai_a', {giorno: etichetta + ' ' + d.getDate() + '/' + (d.getMonth() + 1)}));
    vai.appendChild(el('span', 'pl-ma-when' + (date < S.today ? ' pl-ma-past' : ''), etichetta));
    vai.appendChild(el('span', 'pl-ma-date', d.getDate() + '/' + (d.getMonth() + 1)));
    vai.addEventListener('click', function (e) {
        e.stopPropagation();
        entraNelGiorno(date, null);
    });
    head.appendChild(vai);
    var wt = wxTag(date);
    if (wt) head.appendChild(wt);
    return head;
}

function agendaRow(ev) {
    var row = el('button', 'pl-ma-ev' + (ev.done ? ' pl-ma-done' : ''));
    row.type = 'button';
    var dot = el('span', 'pl-ma-dot');
    dot.style.background = evColor(ev);
    row.appendChild(dot);

    var mid = el('div');
    mid.style.cssText = 'flex:1;min-width:0;display:flex;gap:10px;align-items:flex-start';
    if (ev.all_day) {
        var chip = el('span', 'pl-ma-allday', T('planner.tutto_il_giorno_l'));
        chip.style.flexShrink = '0';
        mid.appendChild(chip);
    } else {
        mid.appendChild(el('span', 'pl-ma-hours', ev.start + ' – ' + ev.end
            + (ev.giorno_tot > 1 ? ' (' + ev.giorno_num + '/' + ev.giorno_tot + ')' : '')));
    }
    var txt = el('div');
    txt.style.cssText = 'flex:1;min-width:0';
    txt.appendChild(el('div', 'pl-ma-t', ev.title));
    var sotto = [];
    if (ev.project_name) sotto.push(ev.project_name);
    if (ev.category_id) { var c = catById(ev.category_id); if (c) sotto.push(c.name); }
    if (sotto.length) txt.appendChild(el('div', 'pl-ma-sub', sotto.join(' · ')));
    mid.appendChild(txt);
    row.appendChild(mid);

    row.addEventListener('click', function () { openEventSheet(ev, null); });
    return row;
}

$('plMagendaClose').addEventListener('click', closeMonthAgenda);

/* Si chiude anche trascinando la maniglia verso il basso, o toccando la
   griglia del mese sopra: prima l'unico modo era centrare quel trattino
   sottile, e da telefono era una piccola tortura. */
(function magendaClose() {
    var grab = $('plMagendaClose'), box = $('plMonthAgenda');
    if (!grab || !box) return;
    var y0 = null;
    grab.addEventListener('touchstart', function (e) {
        if (e.touches.length === 1) y0 = e.touches[0].clientY;
    }, { passive: true });
    grab.addEventListener('touchmove', function (e) {
        if (y0 === null) return;
        if (e.touches[0].clientY - y0 > 40) { y0 = null; closeMonthAgenda(); }
    }, { passive: true });
    grab.addEventListener('touchend', function () { y0 = null; }, { passive: true });

    /* un tocco sulla griglia del mese, sopra al pannello, lo richiude */
    var grid = $('plMonthGrid');
    if (grid) grid.addEventListener('pointerdown', function () {
        if (box.classList.contains('pl-open')) closeMonthAgenda();
    }, true);
})();

/* su e giù cambia mese, destra e sinistra cambia anno */
function monthStep(months, years, dir) {
    var c = parseIso(S.monthCursor);
    var next = new Date(c.getFullYear() + years, c.getMonth() + months, 1);
    var mv = $('plMonthView');
    var cls = 'pl-slide-' + dir;
    mv.classList.add(cls);
    setTimeout(function () {
        S.monthCursor = iso(next);
        renderMonth();
        mv.classList.remove(cls);
        if ($('plMonthAgenda').classList.contains('pl-open')) closeMonthAgenda();
    }, 210);
}

(function monthSwipe() {
    var mv = $('plMonthView');
    if (!mv) return;
    var x0 = 0, y0 = 0, t0 = 0, on = false;
    mv.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; t0 = Date.now(); on = true;
    }, { passive: true });
    mv.addEventListener('touchend', function (e) {
        if (!on) return;
        on = false;
        /* il dito stava allungando un evento nuovo, non sfogliando i mesi */
        if (MP.attivo || Date.now() - MP.finitoA < 500) return;
        if (Date.now() - t0 > 900) return;
        var t = e.changedTouches[0];
        var dx = t.clientX - x0, dy = t.clientY - y0;
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 42) {
            if (dy < 0) monthStep(1, 0, 'up');      // dito verso l'alto: mese successivo
            else monthStep(-1, 0, 'down');
            haptic(8);
        } else if (Math.abs(dx) > 48) {
            // dito da destra verso sinistra (dx negativo): anno successivo
            if (dx < 0) monthStep(0, 1, 'left');
            else monthStep(0, -1, 'right');
            haptic(8);
        }
    }, { passive: true });

    /* rotella del mouse: verticale cambia mese, orizzontale cambia anno
       (stessa direzione invertita dello swipe: verso sinistra = avanti). */
    var wheelLock = 0;
    mv.addEventListener('wheel', function (e) {
        var adx = Math.abs(e.deltaX), ady = Math.abs(e.deltaY);
        if (adx < 3 && ady < 3) return;
        e.preventDefault();
        var now = Date.now();
        if (now - wheelLock < 320) return;
        wheelLock = now;
        if (ady >= adx) {
            if (e.deltaY > 0) monthStep(1, 0, 'up'); else monthStep(-1, 0, 'down');
        } else {
            if (e.deltaX < 0) monthStep(0, 1, 'left'); else monthStep(0, -1, 'right');
        }
        haptic(6);
    }, { passive: false });
})();


/* ------------------------------------------------------------------ */
/* Tenere premuto un giorno del mese crea un evento                    */
/* ------------------------------------------------------------------ */
/* Il dito si appoggia su un giorno e ci resta: dopo un attimo compare il
   blocco del nuovo evento in quel giorno. Muovendo il dito il blocco si
   allunga fino al giorno sotto il dito (anche all'indietro, e anche su piu'
   settimane): alzandolo si apre il popup del nuovo evento, gia' con quei
   giorni. Un giorno solo resta un evento con orario; piu' giorni diventano
   un evento "tutto il giorno", che e' quello che di solito si vuole quando
   si segna una vacanza o un viaggio.
   Prima dello scatto basta muovere il dito di poco per tornare ai gesti di
   sempre: tocco per entrare nel giorno, scorrimento per cambiare mese. */
var MP = { timer: null, attivo: false, da: null, a: null, x0: 0, y0: 0, id: null, finitoA: 0 };
var MP_ATTESA = 380, MP_TOLLERANZA = 9;

(function pressioneMese() {
    var grid = $('plMonthGrid');
    if (!grid) return;

    function cellaSotto(x, y) {
        var t = document.elementFromPoint(x, y);
        return t && t.closest ? t.closest('.pl-mcell') : null;
    }
    function intervallo() {
        return MP.da <= MP.a ? [MP.da, MP.a] : [MP.a, MP.da];
    }
    function pulisci() {
        Array.prototype.forEach.call(grid.querySelectorAll('.pl-mprev'), function (n) { n.remove(); });
        Array.prototype.forEach.call(grid.querySelectorAll('.pl-msel'), function (n) { n.classList.remove('pl-msel'); });
    }
    /* Il blocco si disegna riga per riga, come le barre degli eventi lunghi
       (stricheDellaSettimana): uno strato sopra la settimana, largo quanto i
       giorni scelti, arrotondato solo dove l'evento comincia e finisce. */
    function disegna(primo) {
        pulisci();
        var r = intervallo(), n = giorniFra(r[0], r[1]);
        Array.prototype.forEach.call(grid.querySelectorAll('.pl-mrow'), function (row) {
            var celle = row.querySelectorAll('.pl-mcell');
            var c0 = -1, c1 = -1;
            for (var i = 0; i < celle.length; i++) {
                var d = celle[i].dataset.date;
                if (d >= r[0] && d <= r[1]) {
                    celle[i].classList.add('pl-msel');
                    if (c0 < 0) c0 = i;
                    c1 = i;
                }
            }
            if (c0 < 0) return;
            var strato = el('div', 'pl-mprev');
            var apre = celle[c0].dataset.date === r[0], chiude = celle[c1].dataset.date === r[1];
            var b = el('div', 'pl-mprev-bar' + (primo ? ' pl-anim' : '') + (apre ? ' pl-msp-apre' : '') + (chiude ? ' pl-msp-chiude' : ''));
            b.style.gridColumn = (c0 + 1) + ' / span ' + (c1 - c0 + 1);
            var testo = T('planner.nuovo_evento');
            if (n > 1) testo += ' · ' + T('planner.mp_n_giorni', {n: n});
            b.textContent = apre ? testo : ('… ' + T('planner.nuovo_evento'));
            strato.appendChild(b);
            row.appendChild(strato);
        });
    }
    function fermaAttesa() {
        if (MP.timer) { clearTimeout(MP.timer); MP.timer = null; }
    }
    function annulla() {
        fermaAttesa();
        if (MP.attivo) { MP.attivo = false; MP.finitoA = Date.now(); pulisci(); }
        document.body.classList.remove('pl-mpress');
        MP.id = null;
    }
    function apriNuovo(da, a) {
        var piuGiorni = a > da;
        var slot = (zoomCfg(S.zoom) || {}).slot || 30;
        var inizio = 9 * 60, durata = Math.max(60, slot);
        openEventSheet(null, {
            date: da, end_date: a, all_day: piuGiorni,
            start: toHM(inizio), end: toHM(Math.min(1439, inizio + durata))
        });
    }

    grid.addEventListener('pointerdown', function (e) {
        if (e.button) return;                       // solo il tasto principale / il dito
        if (MP.id !== null) { annulla(); return; }  // un secondo dito annulla
        if (monthAgendaAperta()) return;
        var c = e.target.closest ? e.target.closest('.pl-mcell') : null;
        if (!c || !c.dataset.date) return;
        if (e.target.closest('.pl-mtag, .pl-wx')) return;   // i tastini della casella fanno altro
        MP.id = e.pointerId; MP.x0 = e.clientX; MP.y0 = e.clientY;
        MP.da = MP.a = c.dataset.date;
        MP.timer = setTimeout(function () {
            MP.timer = null;
            MP.attivo = true;
            document.body.classList.add('pl-mpress');
            haptic(14);
            disegna(true);
        }, MP_ATTESA);
    });

    window.addEventListener('pointermove', function (e) {
        if (MP.id === null || e.pointerId !== MP.id) return;
        if (!MP.attivo) {
            /* il dito si e' mosso prima dello scatto: e' uno scorrimento */
            if (Math.abs(e.clientX - MP.x0) > MP_TOLLERANZA || Math.abs(e.clientY - MP.y0) > MP_TOLLERANZA) {
                fermaAttesa(); MP.id = null;
            }
            return;
        }
        var c = cellaSotto(e.clientX, e.clientY);
        if (c && c.dataset.date && c.dataset.date !== MP.a) {
            MP.a = c.dataset.date;
            haptic(5);
            disegna();
        }
    });

    window.addEventListener('pointerup', function (e) {
        if (MP.id === null || e.pointerId !== MP.id) return;
        if (!MP.attivo) { fermaAttesa(); MP.id = null; return; }
        var r = intervallo();
        MP.attivo = false; MP.id = null; MP.finitoA = Date.now();
        document.body.classList.remove('pl-mpress');
        apriNuovo(r[0], r[1]);
        /* il blocco resta un attimo, mentre sale il popup: poi va via */
        setTimeout(pulisci, 320);
    });

    window.addEventListener('pointercancel', function (e) {
        if (MP.id !== null && e.pointerId === MP.id) annulla();
    });
    /* su telefono il tieni-premuto aprirebbe il menu del browser */
    grid.addEventListener('contextmenu', function (e) { e.preventDefault(); });
})();
