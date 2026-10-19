/* planner/js/07_navigazione.js — scroll e snap, giorno a fuoco, cambio giorno a pagina intera, zoom con due dita.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Navigazione: scroll, snap, giorno a fuoco                           */
/* ------------------------------------------------------------------ */
function colIndexOf(date) {
    var wIdx = Math.round(diffDays(S.centerWeek, monday(date)) / 7) + 1;
    return wIdx * 7 + dowIndex(date);
}
/* Il giorno a fuoco si mostra spostando la striscia con una trasformazione,
   mai con lo scroll nativo: è per questo che scorrere le ore in verticale
   non può più, per costruzione, cambiare anche il giorno. */
/* quanti giorni restano a sinistra del giorno a fuoco: è questo che lo mette
   in mezzo. Una sola funzione, usata sia per posizionare la striscia sia per
   scrivere l'intervallo nella barra: così i due non possono discordare. */
function daysBefore() {
    return Math.floor((visibleDays() - 1) / 2);
}
function trackOffset(date) {
    /* il giorno a fuoco viene mostrato dopo "daysBefore" giorni interi: nessuna
       colonna tagliata a metà, e l'intervallo scritto in alto è esatto */
    return -(colIndexOf(date) - daysBefore()) * colWidth();
}
function scrollToDate(date, smooth) {
    setTrackX(trackOffset(date), smooth);
}
/* sposta griglia ed etichette insieme: sono due strisce diverse ma devono
   restare incolonnate */
function setTrackX(x, smooth) {
    var isMobile = window.innerWidth < 760;
    var cw = colWidth();
    var headX = x;
    
    /* Su smartphone la fascia degli eventi di tutto il giorno occupa il
       100% dello schermo (avendo rimosso la finta colonna orari). 
       Dobbiamo ricalcolare l'offset di scorrimento per quella specifica striscia. */
    if (isMobile && cw > 0) {
        var passi = x / cw;
        headX = passi * window.innerWidth;
    }

    var tr = $('plTrack');
    if (tr) {
        tr.style.transition = smooth ? 'transform .38s var(--ease)' : 'none';
        tr.style.transform = 'translateX(' + x + 'px)';
    }
    var ht = $('plHeadTrack');
    if (ht) {
        ht.style.transition = smooth ? 'transform .38s var(--ease)' : 'none';
        ht.style.transform = 'translateX(' + headX + 'px)';
    }
}
/* Porta il minuto indicato esattamente a quell'altezza dello schermo.
   Serve al pinch: il punto in mezzo alle due dita deve restare dov'è mentre
   gli scatti si stringono o si allargano, altrimenti si perde il segno di
   dove si stava guardando e bisogna ricercarlo ogni volta.
   `box.top + scrollTop` è il punto in cui starebbe la mezzanotte se non ci
   fosse scorrimento: non cambia con lo zoom, quindi si può misurare dopo il
   ridisegno senza sbagliare il conto. */
/* Quanto in basso si può arrivare. Allargando gli scatti la giornata si
   accorcia, e la posizione di prima può cadere sotto la mezzanotte: lì non
   c'è più niente da mostrare e la sezione diventava una pagina bianca.
   Il fondo va riportato alla fine della giornata. */
function scrollMax(sc) {
    return Math.max(0, sc.scrollHeight - sc.clientHeight);
}

/* Il fondo vero della giornata, che non è il fondo del contenuto.

   Allargando gli scatti la giornata si accorcia: quello che prima erano
   tremila pixel diventano cinquecento, e una posizione presa da prima cade
   sotto la mezzanotte, dove non c'è più niente da disegnare — schermo bianco.
   `scrollMax()` da solo non basta a impedirlo, perché sotto l'ultima riga
   c'è comunque il riempimento del fondo pagina: il limite giusto è l'ultimo
   minuto del giorno, e sotto quello non si scende. */
function scrollMaxGiorno(sc) {
    var fineGiorno = 1440 * pxMin();
    return Math.max(0, Math.min(scrollMax(sc), fineGiorno - sc.clientHeight));
}

function scrollToMinuteAt(min, clientY) {
    var sc = $('plScroller');
    var canvas = document.querySelector('.pl-canvas');
    if (!canvas || !pxMin()) { scrollToMinute(min, false); return; }
    var box = canvas.getBoundingClientRect();
    var zero = box.top + sc.scrollTop;
    var y = zero - clientY + Math.min(1440, min) * pxMin();
    sc.scrollTo({ top: Math.max(0, Math.min(scrollMaxGiorno(sc), y)), behavior: 'auto' });
}

function scrollToMinute(min, smooth) {
    var sc = $('plScroller');
    var y = Math.min(1440, min) * pxMin() - 10;
    sc.scrollTo({ top: Math.max(0, Math.min(scrollMaxGiorno(sc), y)), behavior: smooth ? 'smooth' : 'auto' });
}

/* Il minuto che si vede al centro dello schermo e a che altezza sta.
   Serve a chi cambia scatto senza dita e senza cursore — i tastini degli
   scatti — perché anche lì qualcosa deve restare fermo, e la cosa che uno
   sta guardando è quella in mezzo, non quella in cima. */
function ancoraCentro() {
    var sc = $('plScroller');
    var canvas = document.querySelector('.pl-canvas');
    if (!sc || !canvas || !pxMin()) return null;
    var box = sc.getBoundingClientRect();
    var y = box.top + sc.clientHeight / 2;
    var cv = canvas.getBoundingClientRect();
    return { min: Math.max(0, Math.min(1439, (y - cv.top) / pxMin())), y: y };
}

/* Il minuto sotto un punto qualsiasi dello schermo: per lo zoom da computer,
   dove il punto fermo è il cursore. */
function ancoraA(clientY) {
    var canvas = document.querySelector('.pl-canvas');
    if (!canvas || !pxMin()) return null;
    var cv = canvas.getBoundingClientRect();
    return { min: Math.max(0, Math.min(1439, (clientY - cv.top) / pxMin())), y: clientY };
}
/* la giornata si apre già scesa all'orario di partenza (di base le 06:00):
   lo riapplichiamo qualche volta perché l'altezza della barra cambia mentre
   il calendarietto e le priorità si assestano */
function ensureStartScroll() {
    if (isAgenda()) return;
    var startMin = (S.settings.day_start_hour || 6) * 60;
    if (S.focusDate === S.today) {
        /* oggi si apre sull'ora attuale, con la linea di adesso più o meno a
           metà schermo: un po' di prima e un po' di dopo sotto gli occhi */
        var now = new Date();
        var nowMin = now.getHours() * 60 + now.getMinutes();
        var halfScreen = ($('plScroller').clientHeight / 2) / Math.max(0.001, pxMin());
        startMin = Math.max(0, nowMin - halfScreen);
    }
    var go = function () { scrollToMinute(startMin, false); };
    go();
    requestAnimationFrame(go);
    setTimeout(go, 120);
    setTimeout(go, 420);
}

function firstVisibleMinute() {
    if (isAgenda()) return 9 * 60;
    var sc = $('plScroller');
    return Math.max(0, Math.round(sc.scrollTop / pxMin()));
}

function goToDate(date, smooth) {
    var needRebuild = Math.abs(diffDays(S.centerWeek || date, monday(date))) > 7;
    S.focusDate = date;
    if (needRebuild) buildTrack();
    else scrollToDate(date, smooth);
    applyWeek(monday(date));
    renderWeekBits();
    renderPrio();
    S.firmaPrio = firmaPrio(date);   // quello che si sta guardando adesso
    renderAllDay();   // la fascia compare o sparisce secondo il giorno mostrato
    /* L'anello delle abitudini si aggiorna qui, con il giorno. Prima toccava
       solo a renderDays(), che cambiando giorno non passa: l'anello restava
       fermo sul giorno precedente finché non scattava il timer del minuto o
       non tornava loadWeek. Era questo il ritardo delle barre. */
    renderRing();
    maybeAskCopy();
    applyAmbient();
    /* Il righello si rifà adesso, non quando torna la rete. Gli orari
       venivano ridisegnati due volte — prima senza i gradi, poi con — e la
       seconda volta le etichette si spostavano di qualche pixel: era quello
       lo scattino a sinistra subito dopo aver cambiato giorno. */
    renderGutter();
    /* Cambiando giorno cambia anche l'altezza della lista: renderDays() qui
       non passa, quindi il taglio va rifatto a mano o resterebbe quello del
       giorno precedente. */
    adattaAltezzaLista();
    loadWeek(monday(date));
}

/* Lo scroll che resta nativo qui è solo quello verticale (le ore): serve
   solo per restringere le priorità mentre si scorre. Il giorno a fuoco
   non dipende più da questo evento: cambia in modo esplicito quando un
   gesto orizzontale, la rotella o un tocco lo decide (vedi più sotto). */
$('plScroller').addEventListener('scroll', function () {
    /* scorrendo, le priorità si stringono e ci restano: si riaprono solo
       toccandole. Prima si riaprivano da sole appena finito lo scroll. */
    setPrioSlim(true);
}, { passive: true });

/* La fascia di tutto il giorno si apre e si chiude come le priorità. */
function setAdSlim(v) {
    if (S.adSlim === v) return;
    S.adSlim = v;
    renderAllDay();
}
/* Stesso gesto delle priorità, e sullo stesso evento: `pointerdown`, non
   `click`. Con il click il tocco poteva essere ingoiato da chi lo intercetta
   prima (la sfogliata partita dai tastini ne mangia uno), e la sezione
   restava aperta senza un motivo visibile. */
document.addEventListener('pointerdown', function (e) {
    if (e.target.closest('.pl-hcol-ad')) {
        /* Da chiusa il primo tocco apre la sezione e non fa altro, anche se
           cade su un evento: da chiusa se ne vede uno solo a turno, e quale
           sia dipende da dove è arrivato il giro — si finirebbe per aprire un
           evento che non si era ancora deciso di aprire. Da aperta invece si
           vedono tutti e toccarne uno significa proprio quello. */
        /* Con un evento solo non c'è niente da espandere: la fascia da chiusa
           mostra già tutto quello che c'è, e il primo tocco veniva mangiato
           per aprire una sezione che era già aperta di fatto. Toccandolo si
           apre l'evento, che è la sola cosa sensata da fare.
           Il conto si fa sulla colonna toccata e non sul giorno a fuoco: la
           fascia mostra anche i giorni accanto mentre si sfoglia. */
        var colonna = e.target.closest('.pl-hcol-ad');
        var quanti = colonna ? colonna.querySelectorAll('.pl-ev').length : 0;
        if (S.adSlim && quanti > 1) {
            e.preventDefault();
            e.stopPropagation();
            /* Fermare il pointerdown non basta: il popup dell'evento si apre
               sul `click`, che arriva dopo ed è un evento a sé. Si segna
               l'istante, e chi ascolta i click ingoia quello che segue. */
            S.adApertaA = Date.now();
            setAdSlim(false);
        }
        return;
    }
    if (S.adSlim) return;
    if (e.target.closest('.pl-ov, .pl-wp-ov, .pl-sheet, .pl-alert')) return;
    if (document.querySelector('.pl-ov.pl-open, .pl-wp-ov.pl-open')) return;
    setAdSlim(true);
}, true);

/* Un tocco che cade su un velo, o dentro un pannello aperto, appartiene a
   quel pannello e a nessun altro: serve solo a chiuderlo, e la pagina sotto
   non deve accorgersene. Senza questo, chiudere le liste della settimana (o
   il riassunto del progetto) compattava anche le priorità — e chi il
   pannello l'aveva aperto proprio da una priorità se le ritrovava chiuse
   senza aver toccato niente. */
function daPannello(e) {
    var t = e.target;
    if (t && t.closest && t.closest('.pl-ov, .pl-wp-ov, .pl-wp, .pl-sheet, .pl-alert')) return true;
    return !!document.querySelector('.pl-ov.pl-open, .pl-wp-ov.pl-open');
}

/* …e nemmeno il click che chiude deve arrivare a qualcos'altro. Sui telefoni
   il velo sparisce mentre il dito è ancora giù, e il click che segue viene
   riassegnato a quello che si trova sotto: un evento che si apre, un'ora
   vuota che ne crea uno. Si segna l'istante della chiusura e per mezzo
   secondo si ingoia ogni click che non nasca da un pannello. */
var chiusuraA = 0;
function assorbiDopoChiusura() { chiusuraA = Date.now(); }
document.addEventListener('click', function (e) {
    if (Date.now() - chiusuraA > 450) return;
    if (daPannello(e)) return;
    chiusuraA = 0;
    e.stopPropagation();
    e.preventDefault();
}, true);

/* un tocco fuori dalla sezione priorità la richiude */
document.addEventListener('pointerdown', function (e) {
    if (S.prioSlim) return;
    if (e.target.closest('#plPrio')) return;
    if (daPannello(e)) return;
    setPrioSlim(true);
}, true);

/* Cambia il giorno a fuoco di un tot di giorni, spostando la striscia con
   un'animazione fino al nuovo giorno (goToDate ricostruisce da sé la
   griglia se serve uscire dalle tre settimane già pronte). */
function pageDays(delta) {
    goToDate(addDays(S.focusDate, delta), true);
}

/* frecce laterali: cambiano giorno dove non si può scorrere col dito */
$('plPrevDay').addEventListener('click', function () { pageDays(-1); });
$('plNextDay').addEventListener('click', function () { pageDays(1); });

$('plTodayBtn').addEventListener('click', function () {
    if (S.view === 'month') { S.monthCursor = S.today.slice(0, 7) + '-01'; renderMonth(); return; }
    /* in settimana "oggi" vuol dire portarlo a sinistra e segnarlo scelto,
       non scorrere le ore: qui le ore ci sono già tutte sotto gli occhi */
    if (S.view === 'week') { S.focusDate = S.today; renderWeek(); renderWeekBits(); return; }
    goToDate(S.today, true);
    setTimeout(function () {
        var now = new Date();
        scrollToMinute(Math.max(0, now.getHours() * 60 + now.getMinutes() - 90), true);
    }, 260);
});

/* ------------------------------------------------------------------ */
/* Cambio giorno a pagina intera (un solo giorno visibile)             */
/* ------------------------------------------------------------------ */
/* Con una colonna sola davanti, far scorrere la sola striscia dei giorni
   mostrava mezza pagina in movimento: gli orari a sinistra, la barra in alto
   e la fascia delle priorità restavano ferme e cambiavano di scatto alla
   fine. Qui si muove tutta la pagina, come sul calendario di Google.

   Come è fatto, in tre idee:
   - le parti fisse (barra in alto e fascia sotto) si spostano tutte con la
     stessa variabile CSS `--pl-swipe-x`: a ogni movimento del dito si scrive
     un valore solo e il resto lo compone il browser, senza toccare il
     layout e senza una riga di JavaScript per elemento;
   - la pagina che entra è un clone della barra in alto e della fascia,
     riempito con i dati del giorno vicino e messo a una schermata di
     distanza. La colonna del giorno vicino, invece, è già dentro la striscia
     e non va clonata: basta lasciarla scorrere;
   - la colonna degli orari che deve entrare insieme al giorno nuovo è una
     copia infilata *dentro la striscia*, fra una colonna e l'altra. Così
     viaggia insieme alle colonne senza calcoli di posizione, e quella vera
     resta al suo posto (solo invisibile) per non far ballare l'impaginazione.

   Limite noto e accettato: il clone porta con sé l'ambiente della barra
   (albero, bestia o scena del meteo) così com'è adesso. Per due giorni
   dentro lo stesso mese è identico; a cavallo di un mese, o con il meteo
   acceso, l'ambiente giusto compare alla fine della transizione. Ricostruirlo
   nel clone vorrebbe dire duplicare tutto il disegno dell'ambiente per un
   dettaglio che dura tre decimi di secondo. */
var PS = { on: false, anim: false, W: 0, T0: 0, dx: 0, dir: 0, spacers: [], panes: {}, timer: 0, pending: null };

/* Vale solo con un giorno davanti: con più colonne lo scorrimento attuale va
   già bene ed è quello che la consegna dice di non toccare. */
function psAble() {
    return visibleDays() === 1 && S.view !== 'month' && !S.movingEvent && !S.pinching;
}

/* Un clone non deve portarsi dietro gli identificativi: sarebbero doppi, e
   ogni $('...') della pagina rischierebbe di finire sul clone invece che
   sull'originale. */
function psStripIds(root) {
    if (root.id) root.removeAttribute('id');
    var n = root.querySelectorAll('[id]');
    for (var i = 0; i < n.length; i++) n[i].removeAttribute('id');
}

/* Se una transizione è ancora in volo e il dito riparte, la si chiude subito
   invece di lasciarla a metà: due gesti sovrapposti lascerebbero in giro
   cloni e copie degli orari. */
function psFlush() {
    if (!PS.anim) return;
    clearTimeout(PS.timer);
    psFinish(PS.pending);
}

function psBegin() {
    psFlush();
    if (PS.on) return true;
    var track = $('plTrack');
    var gut = document.querySelector('.pl-gutter');
    var cur = track ? track.querySelector('.pl-col[data-date="' + S.focusDate + '"]') : null;
    /* Se manca uno solo dei pezzi (pagina non ancora montata del tutto) si
       rinuncia e resta il comportamento di prima: meglio un gesto vecchio che
       una pagina a metà. */
    if (!track || !gut || !cur || !$('plTopbar') || !$('plDayBar')) return false;

    PS.W = window.innerWidth;
    PS.dx = 0; PS.dir = 0; PS.spacers = []; PS.panes = {}; PS.pending = null;

    /* Una copia degli orari davanti alle tre colonne in gioco (quella di
       adesso e le due vicine): senza, il giorno nuovo entrerebbe attaccato a
       quello vecchio, senza la sua colonna degli orari davanti. */
    var cols = track.querySelectorAll('.pl-col[data-date]');
    for (var i = 0; i < cols.length; i++) {
        var ds = cols[i].dataset.date;
        if (ds !== S.focusDate && ds !== addDays(S.focusDate, 1) && ds !== addDays(S.focusDate, -1)) continue;
        var copia = gut.cloneNode(true);
        psStripIds(copia);
        copia.classList.add('pl-swipe-gutter');
        /* la copia porta i tag del giorno a fuoco: sulla colonna del giorno
           vicino direbbero il tempo sbagliato, quindi si riscrivono */
        renderGutterMeteo(copia, ds);
        track.insertBefore(copia, cols[i]);
        PS.spacers.push(copia);
    }

    /* Dov'è UN orario nel righello vero, misurato prima di nascondere
       l'originale. Serve subito sotto, per rimettere le copie esattamente
       sopra di lui. */
    var campione = gut.querySelector('.pl-gutter-t');
    var xVero = campione ? campione.getBoundingClientRect().left : null;

    document.documentElement.classList.add('pl-swipe1');
    /* La posizione di partenza si misura invece di calcolarla: le copie appena
       infilate hanno spostato le colonne, e rifare il conto a mano sarebbe un
       secondo posto in cui sbagliarsi. È una lettura sola, all'inizio del
       gesto, non una per fotogramma. */
    PS.T0 = gut.getBoundingClientRect().width - cur.offsetLeft;

    track.style.transition = 'none';
    PS.on = true;
    psPlace();

    /* Gli orari delle copie vanno inchiodati dove stanno quelli veri. */
    if (xVero !== null) {
        var difetto = 0;
        // Misuriamo il piccolo difetto sub-pixel solo sulla copia del giorno centrale, 
        // che si trova già nella posizione giusta.
        for (var k = 0; k < PS.spacers.length; k++) {
            var ds = PS.spacers[k].nextElementSibling.dataset.date;
            if (ds === S.focusDate) {
                var camp2 = PS.spacers[k].querySelector('.pl-gutter-t');
                if (camp2) difetto = xVero - camp2.getBoundingClientRect().left;
                break;
            }
        }
        // Riapplichiamo questo minuscolo scarto a tutte le copie per allinearle
        if (Math.abs(difetto) > 0.4) {
            for (var k = 0; k < PS.spacers.length; k++) {
                PS.spacers[k].style.transform = 'translateX(' + difetto + 'px)';
            }
        }
    }
    return true;
}

/* La pagina del giorno vicino. Si costruisce quando si capisce da che parte
   sta andando il dito, non prima: nella maggior parte dei gesti l'altra metà
   non serve. */
function psPane(dir) {
    if (PS.panes[dir]) return PS.panes[dir];
    var date = addDays(S.focusDate, dir);
    var d = parseIso(date);
    var pane = el('div', 'pl-swipe-page' + (dir < 0 ? ' pl-swipe-prev' : ''));
    pane.setAttribute('aria-hidden', 'true');

    var tb = $('plTopbar').cloneNode(true);
    var db = $('plDayBar').cloneNode(true);
    psStripIds(tb); psStripIds(db);

    var num = tb.querySelector('.pl-day-num');    if (num) num.textContent = String(d.getDate());
    var wd  = tb.querySelector('.pl-day-wd');     if (wd)  wd.textContent  = GIORNI[dowIndex(date)];
    var mn  = tb.querySelector('.pl-month-name'); if (mn)  mn.textContent  = MESI[d.getMonth()];
    var yr  = tb.querySelector('.pl-month-year'); if (yr)  yr.textContent  = String(d.getFullYear());

    /* Sincronizza ed impagina gli eventi di tutto il giorno della pagina che entra,
       lasciando che la fascia prenda naturalmente l'altezza che le serve e mostri le righe. */
    var hrow = db.querySelector('.pl-headrow');
    var ht = db.querySelector('.pl-headtrack');

    if (hrow && ht) {
        // Pulisci tutti gli stili inline che ingabbiavano l'altezza
        hrow.style.height = '';
        hrow.style.marginTop = '';
        hrow.style.overflow = '';
        hrow.style.boxSizing = '';
        hrow.style.borderTop = '';
        hrow.style.background = '';

        var suoi = eventsOf(date).filter(inFascia);
        var many = visibleDays() >= 2;

        // Gestione corretta della visibilità per il giorno che entra
        hrow.classList.toggle('pl-hidden', isAgenda() ? !many : (!many && !suoi.length));
        hrow.classList.toggle('pl-noad', !suoi.length);

        ht.style.transform = 'none';
        ht.innerHTML = '';

        // Cloniamo la colonna dal documento reale e reiniettiamo gli eventi
        var cella = document.querySelector('#plHeadTrack .pl-hcol[data-date="' + date + '"]');
        if (cella) {
            var cloneCol = cella.cloneNode(true);
            cloneCol.classList.remove('pl-hcol-hidden');
            ht.appendChild(cloneCol);

            // Popola e attiva il carosello del clone
            var adBox = cloneCol.querySelector('.pl-hcol-ad');
            if (adBox) {
                adBox.innerHTML = '';
                suoi.forEach(function (ev) { adBox.appendChild(buildEvent(ev, true)); });
                adBox.classList.toggle('pl-ad-aperta', !S.adSlim);
                
                // Se la sezione deve ciclare da chiusa, avviamo il giro anche sul clone
                if (suoi.length > 1 && !many && S.adSlim) giroTuttoIlGiorno(adBox);
                else fermaGiro(adBox);
            }
        }
    }

    /* Il tag del meteo è del giorno, non della pagina: quello copiato va tolto
       e rifatto per il giorno vicino, se una previsione c'è. */
    var wxOld = tb.querySelector('.pl-day-wx');
    if (wxOld && wxOld.parentNode) wxOld.parentNode.removeChild(wxOld);
    var wxNew = wxTag(date);
    if (wxNew) {
        wxNew.classList.add('pl-day-wx');
        var dayBtn = tb.querySelector('.pl-day-btn');
        if (dayBtn && dayBtn.parentNode) dayBtn.parentNode.insertBefore(wxNew, dayBtn.nextSibling);
    }

    var rows = db.querySelector('.pl-prio-rows');
    if (rows) renderPrioInto(rows, date);
    var smart = db.querySelector('.pl-smart-in');
    if (smart) smart.value = '';
    var ask = db.querySelector('.pl-copyask');
    if (ask) ask.classList.add('pl-hidden');

    /* La scena della barra è del giorno, non della pagina. La copia porta
       quella del giorno a fuoco: se il giorno vicino ne vuole un'altra —
       succede sempre oltre l'ultimo giorno di previsione, dove il meteo non
       c'è più — va svuotata, se no si vede la pioggia di ieri su un giorno
       per cui nessuno ha previsto niente. */
    var amb = tb.querySelector('.pl-ambient');
    if (amb && ambChiave(date) !== ambChiave(S.focusDate)) amb.innerHTML = '';

    /* Il clone si porta dietro anche lo stile scritto a mano sull'originale,
       trasformazione compresa: qui la togliamo, perché a muovere le due parti
       ci pensa il clone che le contiene. */
    tb.style.removeProperty('transform');
    db.style.removeProperty('transform');

    pane.appendChild(tb);
    pane.appendChild(db);
    document.body.appendChild(pane);
    PS.panes[dir] = pane;

    /* ALLINEAMENTO VERTICALE DEGLI ORARI DURANTE LO SWIPE */
    var realDb = $('plDayBar');
    var sc = $('plScroller');
    if (realDb && sc) {
        var dy = db.offsetHeight - realDb.offsetHeight;
        var colIn = document.querySelector('#plTrack .pl-col[data-date="' + date + '"]');
        var colCurr = document.querySelector('#plTrack .pl-col[data-date="' + S.focusDate + '"]');

        var spIn = colIn && colIn.previousElementSibling && colIn.previousElementSibling.classList.contains('pl-swipe-gutter') ? colIn.previousElementSibling : null;
        var spCurr = colCurr && colCurr.previousElementSibling && colCurr.previousElementSibling.classList.contains('pl-swipe-gutter') ? colCurr.previousElementSibling : null;

        // Reset eventuali margini precedenti (es. se cambi direzione durante lo swipe)
        if (colIn) colIn.style.marginTop = '0px';
        if (spIn) spIn.style.marginTop = '0px';
        if (colCurr) colCurr.style.marginTop = '0px';
        if (spCurr) spCurr.style.marginTop = '0px';
        sc.style.top = '';

        if (dy < 0) {
            // Il giorno in arrivo è più CORTO. 
            // Alziamo il tetto dello scroller per far arrivare la griglia fino alla nuova barra,
            // e spingiamo in giù la colonna corrente di pari passo per farla rimanere ferma visivamente.
            sc.style.top = (realDb.getBoundingClientRect().bottom + dy) + 'px';
            if (colCurr) colCurr.style.marginTop = Math.abs(dy) + 'px';
            if (spCurr) spCurr.style.marginTop = Math.abs(dy) + 'px';
        } else if (dy > 0) {
            // Il giorno in arrivo è più LUNGO. 
            // Lo scroller rimane dov'è, ma spingiamo in giù solo la colonna in arrivo
            // per non farla sovrapporre visivamente alla sua barra più grande.
            if (colIn) colIn.style.marginTop = dy + 'px';
            if (spIn) spIn.style.marginTop = dy + 'px';
        }
    }

    psPlace();
    return pane;
}

function psMove(dx) {
    if (!PS.on) return;
    /* Oltre una schermata non si va: un gesto = un giorno, come su Google.
       Prima un lancio veloce poteva saltare fino a una settimana, e con la
       pagina intera in movimento non ci sarebbe niente da mostrare in mezzo. */
    dx = Math.max(-PS.W, Math.min(PS.W, dx));
    PS.dx = dx;
    var dir = dx < 0 ? 1 : (dx > 0 ? -1 : 0);
    if (dir && dir !== PS.dir) { PS.dir = dir; psPane(dir); }
    psPlace();
}

/* Le trasformazioni si scrivono direttamente sui pochi elementi in gioco.
   Passare da una variabile CSS sulla radice sarebbe stato più corto da
   scrivere, ma obbliga il browser a rivedere lo stile di tutta la pagina a
   ogni movimento del dito: qui sono cinque scritture e nessuna rilettura. */
function psPlace() {
    var dx = PS.dx;
    var tb = $('plTopbar'), db = $('plDayBar'), track = $('plTrack');
    if (tb) tb.style.transform = 'translateX(' + dx + 'px)';
    if (db) db.style.transform = 'translateX(' + dx + 'px)';
    if (track) track.style.transform = 'translateX(' + (PS.T0 + dx) + 'px)';
    if (PS.panes[1]) PS.panes[1].style.transform = 'translateX(' + (dx + PS.W) + 'px)';
    if (PS.panes[-1]) PS.panes[-1].style.transform = 'translateX(' + (dx - PS.W) + 'px)';
}

function psEnd(dx) {
    if (!PS.on) { if (PS.anim) return; psFinish(null); return; }
    dx = Math.max(-PS.W, Math.min(PS.W, dx || 0));
    /* Un quarto di schermata basta per cambiare giorno: sotto, la pagina
       torna indietro. È la stessa soglia morbida del trascinamento di prima,
       detta in frazione di schermo invece che in pixel. */
    var passo = Math.abs(dx) >= PS.W * 0.25 ? (dx < 0 ? 1 : -1) : 0;
    if (passo) { psPane(passo); haptic(6); }

    PS.on = false;
    PS.anim = true;
    PS.pending = passo ? addDays(S.focusDate, passo) : null;

    document.documentElement.classList.add('pl-swipe-anim');
    var track = $('plTrack');
    if (track) track.style.transition = 'transform .3s var(--ease)';
    /* l'ultimo tratto lo fa il CSS: PS.on è già falso, quindi psPlace() va
       chiamato a mano dopo aver messo il valore d'arrivo */
    PS.dx = passo ? -passo * PS.W : 0;
    psPlace();
    PS.timer = setTimeout(function () { psFinish(PS.pending); }, 320);
}

/* Fine della transizione: si smonta tutto e solo adesso cambia il giorno a
   fuoco. Da qui in poi ci pensa la strada normale (goToDate), che rimette la
   striscia al suo posto e ridisegna barra, priorità e ambiente: il clone
   sparisce nello stesso istante in cui la pagina vera è già giusta, quindi
   non si vede nessuno scatto. */
function psFinish(date) {
    clearTimeout(PS.timer);
    var track = $('plTrack');
    for (var k in PS.panes) {
        if (PS.panes.hasOwnProperty(k) && PS.panes[k] && PS.panes[k].parentNode) {
            PS.panes[k].parentNode.removeChild(PS.panes[k]);
        }
    }
    PS.panes = {};
    PS.spacers.forEach(function (s) { if (s.parentNode) s.parentNode.removeChild(s); });
    PS.spacers = [];
    
    /* Puliamo i margini verticali temporanei che avevamo applicato alle colonne */
    var sc = $('plScroller');
    if (sc) sc.style.top = ''; // Rimuove l'allungamento forzato dello scroller

    if (track) {
        var cols = track.querySelectorAll('.pl-col');
        for (var i = 0; i < cols.length; i++) cols[i].style.marginTop = '';
    }

    var html = document.documentElement;
    var tb = $('plTopbar'), db = $('plDayBar');
    /* Lo spostamento va tolto *senza* animarlo. Togliendo le classi si
       riaccende la transizione scritta nel foglio di stile, e la barra, che in
       quel momento è ancora fuori schermo a sinistra, tornerebbe indietro
       scivolando sopra la pagina nuova. Si spegne qui a mano e si riaccende al
       fotogramma dopo, quando ormai è ferma al suo posto. */
    if (tb) tb.style.transition = 'none';
    if (db) db.style.transition = 'none';
    html.classList.remove('pl-swipe1', 'pl-swipe-anim');
    if (tb) tb.style.removeProperty('transform');
    if (db) db.style.removeProperty('transform');
    requestAnimationFrame(function () {
        if (tb) tb.style.removeProperty('transition');
        if (db) db.style.removeProperty('transition');
    });
    if (track) track.style.transition = 'none';
    PS.on = false; PS.anim = false; PS.pending = null; PS.dir = 0; PS.dx = 0;

    if (date) {
        goToDate(date, false);
        /* Il giorno è cambiato: scena e tag orari sono ancora quelli di
           prima. goToDate ridisegna la griglia, non il meteo — ed è il motivo
           per cui i tag comparivano durante lo scorrimento e sparivano
           appena la pagina si fermava. */
        renderGutterMeteo(null, S.focusDate);
        applyAmbient(false);
    }
    else setTrackX(trackOffset(S.focusDate), false);
}

/* Girando lo schermo cambia sia la larghezza sia il numero di giorni davanti:
   una transizione cominciata prima non avrebbe più senso, si chiude dov'è. */
window.addEventListener('resize', function () {
    if (PS.on || PS.anim) psFinish(null);
});

/* Anche la barra in alto cambia giorno con uno scorrimento orizzontale:
   è la zona più comoda da raggiungere col pollice. */
(function topbarSwipe() {
    var bar = $('plTopbar');
    if (!bar) return;
    var x0 = null, y0 = 0, horiz = false, pagina = false;
    bar.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1 || S.view === 'month') return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; horiz = false; pagina = false;
    }, { passive: true });
    bar.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
        if (!horiz && Math.abs(dx) > 16 && Math.abs(dx) > Math.abs(dy) * 1.6) {
            horiz = true;
            /* Con un giorno solo anche da qui la pagina segue il dito: è lo
               stesso movimento che parte dalla griglia, quindi il gesto si
               comporta allo stesso modo ovunque lo si cominci. */
            pagina = psAble() ? psBegin() : false;
        }
        if (pagina) psMove(dx);
    }, { passive: true });
    bar.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        x0 = null;
        if (pagina) { pagina = false; psEnd(dx); return; }
        if (!horiz || Math.abs(dx) < 34) return;
        pageDays(dx < 0 ? 1 : -1);
        haptic(6);
    }, { passive: true });
    bar.addEventListener('touchcancel', function () {
        x0 = null;
        if (pagina) { pagina = false; psEnd(0); }
    }, { passive: true });
})();

/* ------------------------------------------------------------------ */
/* Zoom con due dita                                                   */
/* ------------------------------------------------------------------ */
(function pinch() {
    var sc = $('plScroller');
    var start = 0, startZoom = 0, active = false, ancoraMin = 0, ancoraY = 0;
    function dist(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.hypot(dx, dy);
    }
    function metaY(t) { return (t[0].clientY + t[1].clientY) / 2; }
    /* Che minuto si trova adesso sotto un certo punto dello schermo. Si
       misura sul canvas, che parte esattamente dalla mezzanotte. */
    function minutoA(clientY) {
        var canvas = document.querySelector('.pl-canvas');
        if (!canvas || !pxMin()) return firstVisibleMinute();
        var box = canvas.getBoundingClientRect();
        return Math.max(0, Math.min(1439, (clientY - box.top) / pxMin()));
    }
    /* ------------------------------------------------------------------
       Il gesto a due dita, con la presa dei puntatori.

       Prima si ascoltavano i `touch`, e c'era un guaio di fondo: cambiando
       scatto la pagina viene ridisegnata da capo e il nodo su cui il dito era
       appoggiato finisce nel cestino. I tocchi di quel dito continuano ad
       andare a lui, che non è più attaccato a niente: allo scorrevole non
       arriva più nulla, il gesto muore dopo il primo scatto e nessuno lo
       chiude. Da qui la rotellina che restava in vista e il pizzico che da
       "lista" non riusciva a passare oltre le tre ore.

       Con i `pointer` si può chiedere la *presa*: da quel momento tutti gli
       spostamenti di quei due diti vengono consegnati allo scorrevole
       qualunque cosa succeda alla pagina sotto. È esattamente il rimedio
       pensato per questo caso. Restano il riaggancio e il guardiano come
       reti di sicurezza, perché la presa il browser può sempre toglierla.
       ------------------------------------------------------------------ */
    var punti = {}, watch = null, zoomIniziale = null, pxmIniziale = 0, cambiatoModo = false;

    function dueDita() {
        var ids = Object.keys(punti);
        return ids.length === 2 ? [punti[ids[0]], punti[ids[1]]] : null;
    }

    function avvia(t) {
        active = true;
        start = dist(t);
        startZoom = ZOOM.findIndex(function (z) { return z.key === S.zoom; });
        pxmIniziale = pxMin();
        /* Il minuto sotto le dita al momento in cui si comincia, e *dove*
           stava sullo schermo. Servono tutt'e due, e il secondo è quello che
           mancava: si teneva fermo il minuto giusto ma lo si riportava ogni
           volta in mezzo alle dita di adesso, così l'orario agganciato
           scivolava su e giù seguendo la mano invece di restare inchiodato
           al punto in cui era al primo tocco. L'altezza si prende una volta
           qui e non si guarda più. */
        ancoraY = metaY(t);
        ancoraMin = minutoA(ancoraY);
        S.pinching = true;
        S.pinchAt = Date.now();
        /* O si cambia scatto o si cambia giorno: mai tutt'e due insieme.
           Partendo con uno scorrimento orizzontale e aggiungendo il secondo
           dito si finiva per fare le due cose in una volta. */
        if (window.plAnnullaScorrimento) window.plAnnullaScorrimento();
        document.documentElement.classList.add('pl-zoomando');
        showZoomDial('hold');
        if (!watch) {
            watch = setInterval(function () {
                if (Date.now() - (S.pinchAt || 0) < 800) return;
                chiudi();
            }, 300);
        }
    }

    function chiudi() {
        if (watch) { clearInterval(watch); watch = null; }
        /* Alzate le dita, si rifanno anche le colonne che durante il gesto
           erano rimaste indietro: dal vivo se ne teneva aggiornata una sola,
           e scorrendo di lato le altre mostrerebbero il compattamento
           dello scatto di prima. */
        if (active) { renderGutter(); renderDays(); }
        /* durante il gesto nessuno degli scatti attraversati viene salvato:
           si salva qui, una volta sola, e solo se si è finiti altrove */
        if (active && zoomIniziale && zoomIniziale !== S.zoom) {
            api('settings_save', { zoom: S.zoom });
        }
        active = false;
        zoomIniziale = null;
        S.pinching = false;
        document.documentElement.classList.remove('pl-zoomando');
        hideZoomDial();
    }

    sc.addEventListener('pointerdown', function (e) {
        if (e.pointerType !== 'touch') return;
        punti[e.pointerId] = { clientX: e.clientX, clientY: e.clientY };
        var t = dueDita();
        if (!t) return;
        Object.keys(punti).forEach(function (id) {
            /* la presa può essere rifiutata (dito già alzato, per esempio):
               non è un errore da fermare tutto, il gesto funziona lo stesso */
            try { sc.setPointerCapture(Number(id)); } catch (err) {}
        });
        zoomIniziale = S.zoom;
        cambiatoModo = false;
        avvia(t);
    });

    sc.addEventListener('pointermove', function (e) {
        if (!(e.pointerId in punti)) return;
        punti[e.pointerId] = { clientX: e.clientX, clientY: e.clientY };
        var t = dueDita();
        if (!t) return;
        /* due dita sullo schermo ma nessun gesto in corso: il filo si era
           spezzato, si riparte da qui con la distanza di adesso come zero */
        if (!active) { avvia(t); return; }
        S.pinchAt = Date.now();
        var ratio = dist(t) / (start || 1);

        /* Da T('planner.lista') non c'è densità da moltiplicare: si rientra negli orari
           al passo più largo. Basta un accenno di apertura — 1.18 voleva dire
           allargare le dita di un quinto prima che succedesse qualcosa, e nel
           frattempo sembrava rotto. */
        if (isAgenda()) {
            if (ratio > 1.05 && !cambiatoModo) {
                cambiatoModo = true;
                setZoom('h3', true, { min: ancoraMin, y: ancoraY });
                /* Il gesto riparte da qui: senza azzerare, il rapporto resta
                   sopra la soglia e al movimento dopo si tornerebbe indietro. */
                start = dist(t);
                pxmIniziale = pxMin();
                /* Da adesso il gesto è come se fosse cominciato sulle tre ore:
                   allargando ancora si sale di scatto senza doverlo rifare. */
                zoomIniziale = 'h3';
                cambiatoModo = false;
            }
            return;
        }

        /* La densità segue le dita in modo continuo: allargandole gli orari si
           allontanano davvero, invece di restare fermi fino allo scatto
           successivo. `ancoraY` e non `metaY(t)`: il punto dello schermo è
           quello del primo tocco, le dita nel frattempo si sono mosse, ed è
           l'orario di partenza che deve restare inchiodato dov'era. */
        var pxm = pxmIniziale * ratio;

        /* Sotto il minimo si esce dagli orari e si passa alla lista.

           Ma solo se si era GIÀ sulle tre ore quando il gesto è cominciato, e
           solo una volta per gesto. Prima bastava stringere un po' da mezz'ora:
           la densità toccava il fondo, lo scatto diventava tre ore e al
           movimento successivo la stessa stretta valeva come «vai alla lista».
           Da lì si rimbalzava avanti e indietro fra tre ore e lista finché non
           si alzavano le dita. Adesso passare a tre ore è un traguardo: per
           andare oltre bisogna mollare e ristringere. */
        if (!cambiatoModo && zoomIniziale === 'h3' && pxm <= PXM_MIN * 0.8) {
            cambiatoModo = true;
            setZoom('agenda', true);
            return;
        }
        if (setDensita(pxm, { min: ancoraMin, y: ancoraY })) haptic(6);
    });

    function via(e) {
        if (!(e.pointerId in punti)) return;
        delete punti[e.pointerId];
        /* basta che un dito si alzi: il gesto a due dita è finito comunque */
        if (active) chiudi();
    }
    sc.addEventListener('pointerup', via);
    sc.addEventListener('pointercancel', via);
})();

/* `ancora` = { min: minuto da tenere fermo, y: a che altezza dello schermo }.
   Se non c'è, si tiene fermo il primo minuto visibile in alto, come prima:
   è il comportamento giusto quando lo scatto lo si cambia dalla rotellina,
   dove non esistono dita a cui restare agganciati. */
/* Arma o disarma l'abitudine / il progetto scelto dal tastino. */
/* Riporta il tastino delle categorie all'icona di partenza. */
function posaCategoria() {
    if (!S.defaultCat) return;
    S.defaultCat = null;
    var b = $('plFabCat');
    if (!b) return;
    b.classList.remove('pl-picked', 'pl-on');
    b.style.removeProperty('--ev');
    b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 4h7.7L20 10.3 13.3 17 6 9.7z"/><circle cx="10" cy="8.5" r="1.2"/></svg>';
}

function armaAttrezzo(kind, item, dur) {
    /* Uno solo alla volta fra i tre. Sono tutti e tre risposte alla stessa
       domanda — «cosa sto per mettere sul calendario» — e tenerne due accesi
       vorrebbe dire non sapere quale delle due vince al tocco successivo. */
    if (item) posaCategoria();
    S.armato = item ? { kind: kind, item: item, dur: dur || 0 } : null;
    document.documentElement.classList.toggle('pl-armato', !!S.armato);

    [['plHabitRing', 'habit'], ['plFabFav', 'project']].forEach(function (par) {
        var b = $(par[0]);
        if (!b) return;
        var mio = S.armato && S.armato.kind === par[1];
        b.classList.toggle('pl-picked', !!mio);
        if (mio) {
            /* Il tastino mostra COSA si è scelto, come fa quello delle
               categorie: con la sola icona di prima non si capiva se
               l'abitudine fosse davvero armata, e si restava a toccare il
               calendario aspettandosi il popup di prima. */
            if (b.dataset.iconaBase === undefined) b.dataset.iconaBase = b.innerHTML;
            b.style.setProperty('--ev', S.armato.item.color || NEUTRAL);
            b.innerHTML = '';
            b.appendChild(el('span', 'pl-dot'));
            if (par[1] === 'habit' && S.armato.item.icon) b.appendChild(el('span', 'pl-ev-icon', S.armato.item.icon));
            b.appendChild(el('span', null, S.armato.item.name));
            var x = el('span', 'pl-chip-x');
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            b.appendChild(x);
        } else if (b.dataset.iconaBase !== undefined) {
            b.innerHTML = b.dataset.iconaBase;
            b.style.removeProperty('--ev');
        }
    });
}

/* La durata che l'attrezzo armato porta con sé.
   L'abitudine ha la sua, decisa nelle impostazioni; il progetto no, e prende
   quella dello scatto come fa una categoria. */
function durataArmato() {
    if (!S.armato) return 0;
    if (S.armato.kind === 'habit') {
        return S.armato.item.minutes > 0 ? S.armato.item.minutes : (zoomCfg(S.zoom).slot || 30);
    }
    return zoomCfg(S.zoom).slot || 30;
}

/* Inserisce l'attrezzo armato nel punto toccato.
   A differenza della categoria, che resta selezionata per infilarne diverse
   di fila, questi due si disarmano subito: un'abitudine o un progetto si
   mettono in agenda una volta, e restare armati vorrebbe dire creare un
   doppione al primo tocco distratto. */
function inserisciArmato(date, startMin, endMin) {
    var a = S.armato;
    if (!a) return;
    var payload = a.kind === 'habit'
        ? { title: a.item.name, habit_id: a.item.id, type: 'task', color: a.item.color, notify: 'none' }
        : { title: a.item.name, project_id: a.item.id, type: 'event', notify: 'none' };
    payload.date = date;
    payload.start = toHM(startMin);
    payload.end = toHM(Math.min(1439, endMin));
    armaAttrezzo(null, null);
    haptic(12);
    creaSubito(payload, function () {
        if (a.kind === 'habit') { delete S.rings[date]; renderRing(); }
    });
}

/* Uscendo dalla vista giorno l'attrezzo si posa da sé: puntava a una griglia
   che non c'è più, e restare armati senza un posto dove appoggiarlo confonde
   e basta. */
function disarmaSeFuoriPosto() {
    if (S.armato && (S.view !== 'day' || isAgenda())) armaAttrezzo(null, null);
}

/* Riporta la striscia dei giorni sul giorno a fuoco.

   Cambiando scatto cambia la LARGHEZZA della colonna — entrando in vista a
   lista sparisce il righello e la colonna si allarga — mentre lo spostamento
   laterale resta quello calcolato con la larghezza di prima: da lì la lista
   che si apre a cavallo fra due giorni. E da una striscia storta il gesto
   successivo parte da un punto che non corrisponde a nessun giorno, che è
   anche l'origine del ricaricamento con errore.

   Va fatto DOPO il ridisegno e sul fotogramma dopo: `trackOffset` MISURA la
   colonna, e prima che il browser abbia applicato la larghezza nuova
   misurerebbe ancora quella vecchia, cioè rifarebbe lo stesso errore. Era
   questo il motivo per cui il riallineamento della volta scorsa non bastava. */
function riallineaStriscia() {
    requestAnimationFrame(function () {
        if (S.view === 'day') scrollToDate(S.focusDate, false);
    });
}

function setZoom(key, quiet, ancora) {
    var anchor = firstVisibleMinute();
    S.zoom = key;
    /* Premendo un tastino si torna alla densità naturale di quello scatto:
       è la differenza fra scegliere un passo e pizzicare. */
    var zz = zoomCfg(key);
    applicaDensita(zz.slot ? zz.h / zz.slot : 0);
    renderGutter();
    renderDays();
    if (ancora) scrollToMinuteAt(ancora.min, ancora.y);
    else scrollToMinute(anchor, false);
    renderZoomDial();
    refreshZoomDial();   // in vista lista resta visibile anche su telefono
    disarmaSeFuoriPosto();
    riallineaStriscia();
    if (!quiet) api('settings_save', { zoom: key });
}

/* ---- rotellina degli scatti ---- */
var zoomHideTimer = null;
function renderZoomDial() {
    var box = $('plZoom');
    if (!box) return;
    box.innerHTML = '';
    box.appendChild(el('div', 'pl-zoom-lab', T('planner.scatti')));
    ZOOM.forEach(function (z) {
        var short = z.key === 'agenda' ? T('planner.lista')
            : z.key === 'h3' ? '3 h' : z.key === 'h1' ? '1 h'
            : z.key === 'm30' ? "30'" : z.key === 'm15' ? "15'" : "5'";
        var b = el('button', 'pl-zoom-step' + (z.key === S.zoom ? ' pl-on' : ''), short);
        b.type = 'button';
        b.addEventListener('click', function () {
            /* Qui non c'è né un dito né un cursore sulla griglia: il punto
               fermo è quello che si sta guardando, cioè il centro. Prima
               restava fermo il primo minuto in alto, e cambiando scatto
               l'ora che si stava guardando scappava fuori dallo schermo. */
            setZoom(z.key, false, ancoraCentro());
            /* dopo il cambio: se lo scatto nuovo non è di quelli che la
               tengono a portata, si rivede solo un istante e poi va via */
            showZoomDial(zoomHideDial_keep() ? 'stay' : 'temporary');
        });
        box.appendChild(b);
    });
}
/* su PC resta visibile (è l'unico modo per cambiare scatto senza pizzicare),
   su telefono compare solo mentre si pizzica e poi sparisce */
/* modo: 'hold' resta finché non si chiama hideZoomDial (le due dita sono
   giù), 'temporary' sparisce da sé, 'stay' resta sempre (PC e vista lista) */
function placeZoomDial() {
    var box = $('plZoom'), ref = $('plFabRoutine');
    if (!box) return;
    /* A colonna richiusa il tasto delle routine non ha più altezza: ci si
       appoggia alle abitudini, l'unico che resta sempre in vista. */
    if (!ref || !ref.getBoundingClientRect().height) ref = $('plHabitRing');
    if (!ref) return;
    var r = ref.getBoundingClientRect();
    if (r.height) box.style.top = r.top + 'px';   // stessa partenza del tasto routine
}
function showZoomDial(mode) {
    var box = $('plZoom');
    if (!box) return;
    placeZoomDial();
    box.classList.add('pl-show');
    if (zoomHideTimer) { clearTimeout(zoomHideTimer); zoomHideTimer = null; }
    if (mode === 'temporary') {
        zoomHideTimer = setTimeout(function () {
            /* il timer si spegne da sé prima di agire, se no resta in giro un
               riferimento a una sveglia già suonata; e ricontrolla, perché
               nel frattempo si può essere finiti dove la rotellina deve
               restare in vista */
            zoomHideTimer = null;
            if (!zoomHideDial_keep()) box.classList.remove('pl-show');
        }, 650);
    }
}
function hideZoomDial() {
    var box = $('plZoom');
    if (!box) return;
    if (zoomHideDial_keep()) return;   // su PC, o in vista lista, resta
    if (zoomHideTimer) { clearTimeout(zoomHideTimer); zoomHideTimer = null; }
    box.classList.remove('pl-show');   // le dita si sono alzate: via subito
}
/* la rotellina resta sempre a portata su PC e nella vista a lista, dove
   non c'è una griglia da pizzicare */
function zoomHideDial_keep() { return S.pointerFine || isAgenda(); }
/* Il controllo si fa sempre e la decisione è una sola: la rotellina resta
   solo dove serve (su computer e in vista lista), altrove sparisce. Prima,
   passando da "lista" a un altro scatto, poteva restare in vista perché era
   ancora appeso un timer di quelli "temporanei" acceso poco prima: ora
   nascondendola il timer viene spento, così non può riaccenderla dopo. */
function refreshZoomDial() {
    var b = $('plZoom');
    if (!b) return;
    /* Rete di sicurezza sul pizzico. Mentre si zooma le colonne vengono
       ridisegnate, e il dito che si alza da un nodo ormai staccato non fa
       arrivare il touchend allo scorrevole: lo stato "si sta zoomando"
       restava acceso per sempre e la rotellina non se ne andava più. Se non
       arrivano più notizie del gesto da un secondo e mezzo, è finito. */
    if (S.pinching && Date.now() - (S.pinchAt || 0) > 1500) S.pinching = false;
    if (zoomHideDial_keep() || S.pinching) { showZoomDial('stay'); return; }
    if (zoomHideTimer) { clearTimeout(zoomHideTimer); zoomHideTimer = null; }
    b.classList.remove('pl-show');
}
