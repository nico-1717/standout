/* planner/js/05_griglia_ed_eventi.js — costruzione della griglia, disegno e compattamento degli eventi.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Costruzione della griglia                                           */
/* ------------------------------------------------------------------ */
/* larghezza reale di un giorno, misurata sul DOM: corretta sia su
   mobile (un giorno = tutto lo schermo) sia su desktop (più giorni
   affiancati, colonna di larghezza fissa via CSS). */
function colWidth() {
    var c = document.querySelector('#plTrack .pl-col');
    if (c) return c.getBoundingClientRect().width;
    return Math.max(240, $('plScroller').clientWidth - 48);
}
/* quanti giorni entrano nello schermo: da qui dipende se le colonne hanno
   bisogno della loro etichetta (con un giorno solo la data è già nella
   barra in alto) e se il giorno a fuoco va centrato invece che a sinistra */
function clipWidth() {
    var h = document.querySelector('.pl-hclip');
    return h ? h.getBoundingClientRect().width : $('plScroller').clientWidth;
}
function visibleDays() {
    /* Deriva dalle impostazioni, non da una misura: buildTrack svuota le
       colonne prima di chiamarla, quindi misurarle dava sempre 1 e
       l'etichetta del giorno non compariva mai su schermo largo. */
    if (!window.matchMedia('(min-width:760px)').matches) return 1;
    return Math.max(1, Math.min(7, S.settings.days_visible || 3));
}

function buildTrack() {
    var track = $('plTrack');
    S.centerWeek = monday(S.focusDate);
    track.innerHTML = '';
    HEAD_H = 0;   // le colonne non hanno intestazione: sta nella fascia fissa
    document.documentElement.style.setProperty('--head-h', '0px');
    buildHeadTrack();
    [-1, 0, 1].forEach(function (w) {
        var ws = addDays(S.centerWeek, w * 7);
        for (var i = 0; i < 7; i++) track.appendChild(buildDayCol(addDays(ws, i)));
    });
    renderGutter();
    renderDays();
    /* ruotando il telefono si passa da uno a più giorni: la scena del meteo
       vale solo con un giorno solo, quindi va rivalutata qui */
    applyAmbient();
    scrollToDate(S.focusDate, false);
}

/* le etichette dei giorni: solo quando se ne vedono almeno due, altrimenti
   sarebbero un doppione di quello che già dice la barra in alto */
/* La striscia sotto le priorità: per ogni giorno la sua etichetta (solo se
   se ne vedono almeno due) e i suoi eventi di tutto il giorno. Stando dentro
   la striscia che si muove con la griglia, ogni evento resta incolonnato sul
   proprio giorno: prima erano in un'unica fascia larga come lo schermo, e
   sembravano appartenere a tutti i giorni insieme. */
function buildHeadTrack() {
    var row = $('plHeadRow'), track = $('plHeadTrack');
    if (!row || !track) return;
    var many = visibleDays() >= 2;
    track.innerHTML = '';

    [-1, 0, 1].forEach(function (w) {
        var ws = addDays(S.centerWeek, w * 7);
        for (var i = 0; i < 7; i++) {
            var ds = addDays(ws, i);
            var d = parseIso(ds);
            var cell = el('div', 'pl-hcol' + (ds === S.today ? ' pl-is-today' : '') + (ds < S.today ? ' pl-is-past' : ''));
            cell.dataset.date = ds;
            if (many) {
                var lab = el('div', 'pl-hcol-lab');
                lab.appendChild(el('span', 'pl-col-num pl-serif', String(d.getDate())));
                lab.appendChild(el('span', 'pl-col-wd', GIORNI[dowIndex(ds)].slice(0, 3)));
                cell.appendChild(lab);
                /* Il meteo sta qui solo quando i giorni sono più d'uno: con un
                   giorno solo la striscia spesso non compare nemmeno, e il tag
                   va accanto alla scritta del giorno nella barra in alto. */
                var wt = wxTag(ds);
                if (wt) cell.appendChild(wt);
            }
            cell.appendChild(el('div', 'pl-hcol-ad'));   // eventi di tutto il giorno
            track.appendChild(cell);
        }
    });
    renderAllDay();
}

function buildDayCol(date) {
    var col = el('div', 'pl-col');
    col.dataset.date = date;
    if (date === S.today) col.classList.add('pl-is-today');
    if (date < S.today) col.classList.add('pl-is-past');

    var canvas = el('div', 'pl-canvas');
    canvas.dataset.date = date;
    col.appendChild(canvas);
    return col;
}

function renderGutter() {
    var g = $('plGutter');
    if (!g) return;
    g.innerHTML = '';
    g.style.paddingTop = HEAD_H + 'px';
    if (isAgenda()) { g.style.height = '0px'; return; }
    var z = zoomCfg(S.zoom);
    g.style.height = '';   // la calcola il CSS dalla densità
    var majorEvery = z.slot >= 60 ? 1 : (z.slot === 30 ? 2 : (z.slot === 15 ? 4 : 12));
    for (var m = 0; m <= 1440; m += z.slot) {
        if (m === 1440) break;
        var isMajor = (m / z.slot) % majorEvery === 0;
        var lab = el('div', 'pl-gutter-t' + (isMajor ? ' pl-major' : ''), toHM(m));
        lab.style.top = 'calc(' + HEAD_H + 'px + var(--pxm) * ' + m + ')';
        g.appendChild(lab);
    }
    renderGutterMeteo(g, S.focusDate);
}

/* Il tag della previsione sotto l'orario, nella colonna delle ore: sotto le
   8 che tempo fa alle 8, sotto le 9 che tempo fa alle 9. Vale solo con un
   giorno davanti — la colonna degli orari è una sola e con più giorni
   racconterebbe il tempo di uno spacciandolo per quello di tutti.

   Sta in una funzione a sé, e non dentro il ciclo degli orari, perché il
   meteo cambia molto più spesso dello scatto: rifare tutte le etichette a
   ogni ridisegno vorrebbe dire ricostruire fino a trecento nodi per
   aggiornarne ventiquattro. */
function renderGutterMeteo(host, date) {
    host = host || $('plGutter');
    if (!host) return;
    /* La vista settimanale ha una colonna delle ore tutta sua: righe alte
       un'ora, non pixel al minuto, e niente canvas sotto. Il conto dove
       mettere il tag è talmente diverso da non poter stare nello stesso
       ciclo, ma il resto — quali ore, quale icona, quando i gradi ci stanno —
       è lo stesso, e passa da qui perché chi chiama non debba sapere in che
       vista si trova. */
    if (host.id === 'plWeekGut') { renderWeekGutMeteo(date || S.focusDate); return; }
    /* la copia usata dal cambio giorno è il contenitore esterno */
    if (!host.classList.contains('pl-gutter-canvas')) {
        host = host.querySelector('.pl-gutter-canvas');
        if (!host) return;
    }
    var vecchi = host.querySelectorAll('.pl-wxg');
    for (var k = 0; k < vecchi.length; k++) vecchi[k].parentNode.removeChild(vecchi[k]);

    if (!WX.on || isAgenda() || visibleDays() >= 2) return;
    /* Il tag dei gradi sta sotto l'orario e occupa la sua riga: quando gli
       orari si avvicinano finisce sopra a quello dopo. Succedeva soprattutto
       stringendo, poco prima di passare allo scatto più largo, dove la
       distanza fra due orari si riduce di molto. Sotto i 34px di spazio non
       si disegna: meglio niente meteo che un orario illeggibile. */
    if (60 * pxMin() < 34) return;
    date = date || S.focusDate;
    var g = WX.giorni[date];
    if (!g || !g.ore || !g.ore.length) return;

    var z = zoomCfg(S.zoom), px = pxMin();
    /* Ogni quante ore mettere un tag. Due condizioni: che ci stia (sotto i
       26 px si sovrappongono) e che accanto ci sia davvero un orario scritto,
       perché un tag appeso al nulla non si capisce a che ora si riferisce. */
    var passo = Math.max(1, Math.ceil(26 / (60 * px)), Math.ceil(z.slot / 60));

    var perOra = {};
    g.ore.forEach(function (o) { perOra[parseInt(o.h, 10)] = o; });

    for (var h = 0; h < 24; h += passo) {
        var o = perOra[h];
        if (!o) continue;
        var gNome = wxIconaNome(o.i, date, h * 60);
        var b = el('button', 'pl-wxg' + wxClasse(gNome));
        b.type = 'button';
        var ic = el('span', 'pl-wxg-ic');
        ic.innerHTML = WX_ICONE[gNome] || WX_ICONE.nubi;
        b.appendChild(ic);
        /* Con gli scatti da 5 minuti gli orari sono uno ogni 26 px e i gradi
           finiscono addosso a quello dopo: resta la sola icona, che dice già
           che tempo fa. Con tutti gli altri scatti lo spazio c'è. */
        if (z.slot > 5 && o.t !== null && o.t !== undefined) b.appendChild(el('span', 'pl-wxg-t', o.t + '°'));
        /* Alla densità, non a un pixel già calcolato: le etichette degli
           orari si posizionano così, e un tag scritto in pixel restava fermo
           mentre loro si muovevano — da qui i gradi che scivolavano addosso
           all'orario sbagliato durante il pizzico. */
        b.style.top = 'calc(' + HEAD_H + 'px + var(--pxm) * ' + (h * 60) + ' + 6px)';
        b.setAttribute('aria-label', T('planner.x_alle_ora', {cosa: (o.l || ''), ora: h}));
        b.dataset.wxDate = date;
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            wxPopup(this.dataset.wxDate);
        });
        host.appendChild(b);
    }
}

/* Il tempo di quell'ora dentro la colonna delle ore della vista
   settimanale, accanto al numero. Vale sul giorno scelto — la colonna è una
   sola e sette giorni diversi non ci stanno — che è lo stesso patto della
   vista giorno.

   Sotto una certa altezza di riga il tag non entra e si toglie del tutto:
   meglio niente che un'icona schiacciata sul numero dell'ora. I gradi
   arrivano più tardi ancora, quando c'è posto per due righe. */
function renderWeekGutMeteo(date) {
    var gut = $('plWeekGut');
    if (!gut) return;
    var vecchi = gut.querySelectorAll('.pl-wk-wxg');
    for (var v = 0; v < vecchi.length; v++) vecchi[v].parentNode.removeChild(vecchi[v]);

    if (!WX.on || WK.riga < 38) return;
    var g = WX.giorni[date];
    if (!g || !g.ore || !g.ore.length) return;

    var righe = gut.querySelectorAll('.pl-wk-ora');
    if (!righe.length) return;

    var perOra = {};
    g.ore.forEach(function (o) { perOra[parseInt(o.h, 10)] = o; });

    for (var h = 0; h < 24 && h < righe.length; h++) {
        var o = perOra[h];
        if (!o) continue;
        var nome = wxIconaNome(o.i, date, h * 60);
        var b = el('button', 'pl-wxg pl-wk-wxg' + wxClasse(nome));
        b.type = 'button';
        var ic = el('span', 'pl-wxg-ic');
        ic.innerHTML = WX_ICONE[nome] || WX_ICONE.nubi;
        b.appendChild(ic);
        if (WK.riga >= 56 && o.t !== null && o.t !== undefined) {
            b.appendChild(el('span', 'pl-wxg-t', o.t + '\u00B0'));
        }
        b.setAttribute('aria-label', T('planner.x_alle_ora', {cosa: (o.l || ''), ora: h}));
        b.dataset.wxDate = date;
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            wxPopup(this.dataset.wxDate);
        });
        righe[h].appendChild(b);
    }
}

/* ------------------------------------------------------------------ */
/* Disegno degli eventi                                                */
/* ------------------------------------------------------------------ */
/* gli eventi di tutta la giornata stanno sopra alle priorità: così il
   righello degli orari resta allineato a tutte le colonne */
/* I giri in corso, uno per contenitore: senza questa mappa un secondo giro
   sullo stesso posto si sovrapporrebbe al primo e i due si darebbero il
   cambio a caso. */
var giriAllDay = [];

function fermaGiro(box) {
    if (box) box.classList.remove('pl-ad-giro'); // Rimuove la modalità carosello
    giriAllDay = giriAllDay.filter(function (g) {
        if (g.box !== box) return true;
        clearInterval(g.t);
        return false;
    });
}

function giroTuttoIlGiorno(box) {
    fermaGiro(box);
    var voci = Array.prototype.slice.call(box.children);
    if (voci.length < 2) return;

    box.classList.add('pl-ad-giro');
    voci.forEach(function (v, k) { v.classList.toggle('pl-on', k === 0); });

    var pal = el('div', 'pl-ad-pal');
    voci.forEach(function (_, k) {
        pal.appendChild(el('span', 'pl-ad-pallino' + (k ? '' : ' pl-on')));
    });
    /* A sinistra, prima della voce: si legge da lì in poi, e i pallini dicono
       «di quanti» prima di dire «cosa», non dopo averlo già letto. */
    box.insertBefore(pal, box.firstChild);

    var i = 0;
    var t = setInterval(function () {
        /* Se il contenitore è stato buttato via da un ridisegno, il giro si
           spegne da solo invece di restare acceso a vuoto per sempre. */
        if (!box.isConnected) { fermaGiro(box); return; }
        i = (i + 1) % voci.length;
        voci.forEach(function (v, k) { v.classList.toggle('pl-on', k === i); });
        for (var k = 0; k < pal.children.length; k++) {
            pal.children[k].classList.toggle('pl-on', k === i);
        }
    }, 4000);
    giriAllDay.push({ box: box, t: t });
}

function renderAllDay() {
    /* I giri della volta scorsa muoiono qui: i contenitori vengono svuotati
       poco sotto, e un cronometro che punta a nodi buttati resta acceso a
       vuoto. È il modo in cui questi timer si moltiplicano senza che si
       veda, finché la pagina non rallenta. */
    giriAllDay.forEach(function (g) { clearInterval(g.t); });
    giriAllDay = [];

    var row = $('plHeadRow'), track = $('plHeadTrack');
    if (!row || !track) return;
    var many = visibleDays() >= 2;
    var anyAllDay = false;

    /* Conta solo i giorni che si stanno guardando adesso: la striscia tiene in
       memoria tre settimane, e prima bastava un evento di tutto il giorno in
       una qualsiasi di quelle per far comparire la fascia anche su giorni che
       non ne avevano, rubando spazio agli orari per niente. */
    var firstVisible = addDays(S.focusDate, -daysBefore());
    var lastVisible = addDays(firstVisible, visibleDays() - 1);

    var cells = track.querySelectorAll('.pl-hcol');
    for (var i = 0; i < cells.length; i++) {
        var cell = cells[i];
        var box = cell.querySelector('.pl-hcol-ad');
        if (!box) continue;
        box.innerHTML = '';
        if (isAgenda()) continue;
        
        var ds = cell.dataset.date;
        var isVis = (ds >= firstVisible && ds <= lastVisible);

        /* I due giorni ai lati vanno riempiti anche se non sono a fuoco:
           sono quelli che si vedono entrare mentre il dito sfoglia. Prima si
           riempivano solo dopo, e la fascia del giorno in arrivo compariva
           vuota per poi popolarsi a scorrimento finito — o restava lì vuota
           quando il giorno nuovo non aveva niente. */
        var isAccanto = (ds >= addDays(firstVisible, -1) && ds <= addDays(lastVisible, 1));

        /* L'ingombro si azzera solo per i giorni davvero lontani: azzerarlo
           anche ai vicini faceva saltare l'altezza della fascia a metà
           scorrimento. */
        cell.classList.toggle('pl-hcol-hidden', !isAccanto);
        if (!isAccanto) continue;
        
        var list = eventsOf(ds).filter(inFascia);
        list.forEach(function (ev) { box.appendChild(buildEvent(ev, true)); });
        if (list.length && isVis) anyAllDay = true;

        /* Uno per volta, a turno, come le priorità.

           Prima ci stavano tutti incolonnati e la fascia cresceva a ogni
           evento, rubando altezza agli orari e spostando tutto quello che
           sta sotto. Così invece l'altezza è sempre quella di una riga: si
           mostra il primo, ogni tanto passa al successivo, e i pallini di
           fianco dicono quanti sono e a che punto siamo — se no il cambio
           sembrerebbe un difetto invece che un giro. */
        /* Da chiusa una voce per volta a turno; aperta, tutte incolonnate.
           Come le priorità: si apre al tocco e si richiude toccando fuori. */
        box.classList.toggle('pl-ad-aperta', !S.adSlim);
        
        // FIX: Attiviamo il carosello (giroTuttoIlGiorno) anche per i giorni "accanto".
        // Se li escludessimo, i giorni adiacenti con più eventi si impilerebbero in verticale 
        // di nascosto, allungando in modo anomalo l'intera riga per colpa del flex-stretch.
        if (isAccanto && list.length > 1 && !many && S.adSlim) giroTuttoIlGiorno(box);
        else fermaGiro(box);
    }
    /* La striscia resta solo se ha davvero qualcosa da mostrare: le etichette
       dei giorni (schermi larghi) o un evento di tutto il giorno qui davanti.
       Nella vista a lista gli eventi di tutto il giorno stanno in mezzo agli
       altri, ma con più giorni affiancati l'etichetta serve eccome: senza, si
       vedono tre elenchi e nessuno dice a che giorno appartiene. */
    /* In vista settimanale la fascia in alto non ci va: si sovrappone alla
       riga che dice quali sono i giorni, che è la cosa che serve di più là.
       Gli eventi di tutto il giorno hanno una riga tutta loro sopra le 00,
       dentro la griglia (vedi wkFasciaTuttoIlGiorno). */
    if (S.view === 'week') { row.classList.add('pl-hidden'); syncScrollerTop(); return; }

    row.classList.toggle('pl-hidden', isAgenda() ? !many : (!many && !anyAllDay));
    row.classList.toggle('pl-noad', !anyAllDay);
    syncScrollerTop();
    /* Quanto spazio si prende la sezione degli eventi di tutto il giorno.
       Serve ai tastini, che se ne scontano l'altezza e restano dove sono
       invece di scivolare giù ogni volta che compare un evento di tutto il
       giorno: le passano sopra, così quella può occupare tutta la larghezza.

       Il margine va contato: `offsetHeight` non lo comprende, e senza
       restavano otto pixel di sfasamento fra la fascia che c'è e la fascia
       che non c'è.

       Con più giorni affiancati non si sconta niente: là quella riga porta i
       nomi dei giorni ed esiste comunque, e i tastini devono starle sotto
       invece di coprirla. */
    /* --- PULIZIA TOTALE DEI VECCHI OBSERVER --- */
    if (window.plHeadRowObserver) {
        window.plHeadRowObserver.disconnect();
        window.plHeadRowObserver = null;
    }
    if (window.plFabsObserver) {
        window.plFabsObserver.disconnect();
        window.plFabsObserver = null;
    }

    /* --- OBSERVER DEFINITIVO A COORDINATE FISICHE --- */
    window.plFabsObserver = new ResizeObserver(function() {
        var fabs = document.getElementById('plFabs');
        var isDesk = window.matchMedia('(min-width:760px)').matches;
        
        var headRow = document.getElementById('plHeadRow');
        var prioWrap = document.querySelector('.pl-prio-wrap');
        var rightArrow = document.getElementById('plNextDay');
        
        // L'ancora a cui appenderci: la barra tutto il giorno (su PC) o la sezione priorità (su Smartphone).
        var anchor = (isDesk && headRow && headRow.offsetHeight > 0) ? headRow : prioWrap;
        
        if (fabs && anchor) {
            // Misuriamo la coordinata Y reale in cui finisce l'elemento sullo schermo
            var bottomY = anchor.getBoundingClientRect().bottom;
            
            // Su PC dobbiamo garantire che i tastini stiano SEMPRE sotto la freccia destra, 
            // per evitare accavallamenti se la barra superiore dovesse risultare più corta.
            if (isDesk && rightArrow && getComputedStyle(rightArrow).display !== 'none') {
                var arrowBottom = rightArrow.getBoundingClientRect().bottom;
                if (arrowBottom > bottomY) {
                    bottomY = arrowBottom;
                }
            }
            
            // Inchiodiamo i tastini esattamente 16 pixel sotto la linea di ingombro massima
            fabs.style.top = (bottomY + 16) + 'px';
        }
    });
    
    // Osserviamo l'intera barra superiore: se ruotano le priorità o cambiano i dati,
    // l'observer legge le nuove coordinate e sposta i tastini in una frazione di millisecondo.
    var db = document.getElementById('plDayBar');
    if (db) window.plFabsObserver.observe(db);
}

function renderDays(soloVisibile) {
    /* Mentre si trascina un blocco (o se ne sta creando uno) la griglia non
       si tocca: ridisegnarla distrugge il nodo sotto le dita e lo rimette
       dov'era. Era questo a far "tornare indietro" un evento tenuto premuto
       a lungo: ogni minuto la linea dell'ora fa ridisegnare tutto. */
    if (S.interacting) { S.renderPending = true; return; }
    S.renderPending = false;
    var z = zoomCfg(S.zoom);
    /* Durante il pizzico si ridisegna SOLO il giorno che si sta guardando.
       La striscia ne tiene pronti ventuno: rifarli tutti a ogni fotogramma
       era il lavoro che faceva cadere la pagina, e diciannove di quelli non
       sono nemmeno sullo schermo. Gli altri si rifanno quando le dita si
       alzano. */
    var cols = soloVisibile
        ? document.querySelectorAll('.pl-col[data-date="' + S.focusDate + '"]')
        : document.querySelectorAll('.pl-col[data-date]');
    for (var i = 0; i < cols.length; i++) renderCol(cols[i], z);
    /* Fermarsi qui durante il pizzico. Quello che segue rilegge il DOM per
       misurarlo — la larghezza dei tastini, l'altezza della lista — e ogni
       lettura obbliga il browser a ricalcolare il foglio di stile mentre lo
       stiamo cambiando. Sessanta volte al secondo è il conto che fa cadere
       la pagina, e nessuna di quelle cose cambia mentre si zooma. */
    if (soloVisibile) return;
    renderGutterMeteo(null, S.focusDate);
    /* Il controllo della rotellina si fa qui, cioè sempre: era l'unico modo
       per essere certi che non resti in vista dopo un passaggio da "lista",
       qualunque strada abbia preso il cambio di scatto. */
    refreshZoomDial();
    renderAllDay();
    renderWeekPanel();
    renderRing();
    adattaAltezzaLista();
}

/* In vista a lista la pagina diventava altissima.

   Il motivo è che le colonne dei giorni stanno affiancate nella stessa riga:
   la riga è alta quanto la più alta delle colonne, e basta un giorno pieno
   tre schermate più in là perché su un giorno con due voci si possa scendere
   nel vuoto per mezzo minuto. Con gli orari non si vedeva, perché lì tutte le
   colonne sono alte uguali — 1440 minuti — ed è per questo che salta fuori
   solo passando a «lista».

   Si tiene la riga sull'altezza del giorno a fuoco, più un po' d'aria sotto
   l'ultima voce. Se ci stanno tutte, non c'è niente da scorrere. */
function adattaAltezzaLista() {
    var row = document.querySelector('.pl-row');
    var tutte = document.querySelectorAll('.pl-col[data-date] .pl-canvas');
    var i;

    /* L'inline di prima va sempre via, anche quando non siamo in vista a
       lista: se no resta appiccicato addosso al passaggio successivo. */
    if (row) { row.style.height = ''; row.style.overflow = ''; }
    for (i = 0; i < tutte.length; i++) { tutte[i].style.height = ''; tutte[i].style.overflow = ''; }

    if (!isAgenda() || visibleDays() !== 1) return;

    var mia = document.querySelector('.pl-col[data-date="' + S.focusDate + '"] .pl-canvas');
    if (!mia) return;

    /* Prima si tagliava la riga intera con `overflow: hidden`, e la riga
       contiene ANCHE la colonna che si sta guardando: bastava che la misura
       arrivasse un po' corta perché la cima della lista finisse fuori dal
       taglio — i primi eventi spariti, la scritta «nessun evento» invisibile
       e il colore del foglio troncato in alto.

       Si limitano invece le colonne dei giorni VICINI, che sono quelle che
       tiravano su lo scorrimento senza essere sullo schermo. Quella a fuoco
       non viene toccata: cresce quanto le serve e la riga cresce con lei. */
    var h = mia.scrollHeight;
    for (i = 0; i < tutte.length; i++) {
        if (tutte[i] === mia) continue;
        tutte[i].style.height = h + 'px';
        tutte[i].style.overflow = 'hidden';
    }
}


function renderCol(col, z) {
    var date = col.dataset.date;
    var canvas = col.querySelector('.pl-canvas');
    canvas.innerHTML = '';

    /* L'altezza di una riga adesso è calcolata, non presa da ZOOM: è il passo
       moltiplicato per la densità corrente, se no le righe dipinte sul fondo
       resterebbero ferme mentre gli eventi si allargano. */
    canvas.style.setProperty('--slot-h', z.slot ? 'calc(var(--pxm) * ' + z.slot + ')' : '0px');
    canvas.style.setProperty('--major-every', z.slot === 30 ? 2 : (z.slot === 15 ? 4 : (z.slot === 5 ? 12 : 1)));
    canvas.classList.toggle('pl-major-lines', z.slot < 60 && z.slot > 0);
    canvas.classList.toggle('pl-agenda-canvas', isAgenda());
    /* In vista a lista la colonna degli orari non ha niente da scrivere: la
       sua altezza era già azzerata, ma la LARGHEZZA restava, ed è quella la
       striscia vuota che si vedeva a sinistra dell'elenco.

       Si tocca la VARIABILE, non le tre regole che la usano. Il righello, la
       sua intestazione e la larghezza della colonna partono tutti da
       `--gutter-w`: cambiandone solo alcune, le colonne restavano larghe
       quanto prima mentre lo scorrimento le contava di un'altra misura, ed è
       per questo che sfogliando in orizzontale gli eventi finivano nel giorno
       accanto. Un numero solo e i tre conti tornano insieme. */
    document.body.classList.toggle('pl-lista', isAgenda());
    document.body.style.setProperty('--gutter-w', isAgenda() ? '0px' : '');
    canvas.style.height = '';   // idem: calc(var(--pxm) * 1440), o auto in vista a lista
    canvas.style.minHeight = isAgenda() ? '60vh' : '';

    var list = eventsOf(date);
    var timed = [];
    /* gli eventi a cavallo della mezzanotte entrano col solo pezzo di oggi */
    list.forEach(function (ev) { if (!inFascia(ev)) timed.push(pezzoDelGiorno(ev, date)); });

    if (isAgenda()) {
        var wrap = el('div', 'pl-agenda');
        /* Anche qui le routine si compattano o si aprono: prima ogni passaggio
           veniva sempre mostrato per conto suo, e la freccetta non serviva a
           niente perché non c'era nessun blocco unico da aprire o chiudere. */
        var agGroups = {}, agOrder = [];
        list.forEach(function (ev) {
            if (ev.routine_group) {
                if (!agGroups[ev.routine_group]) { agGroups[ev.routine_group] = { items: [], base: ev }; agOrder.push(ev.routine_group); }
                agGroups[ev.routine_group].items.push(ev);
            } else {
                agOrder.push(ev);
            }
        });
        agOrder.forEach(function (item) {
            if (typeof item !== 'string') { wrap.appendChild(buildEvent(item, true)); return; }
            var g = agGroups[item];
            if (!g) return;
            if (g.base.routine_collapsed) {
                var startMin = 1440, endMin = 0;
                g.items.forEach(function (x) { startMin = Math.min(startMin, toMin(x.start)); endMin = Math.max(endMin, toMin(x.end)); });
                var r = routineById(g.base.routine_id);
                var fake = {
                    id: g.base.id, date: g.base.date, title: (r ? r.name : T('planner.routine_t')),
                    start: toHM(startMin), end: toHM(endMin),
                    color: g.base.color, importance: 'normal', type: 'event', done: false,
                    routine_group: item, routine_collapsed: true, count: g.items.length
                };
                wrap.appendChild(buildEvent(fake, true));
            } else {
                g.items.forEach(function (x) { wrap.appendChild(buildEvent(x, true)); });
            }
        });
        if (!list.length) wrap.appendChild(el('div', 'pl-quote pl-serif', T('planner.nessun_evento_in_programma')));
        canvas.appendChild(wrap);
        return;
    }

    // routine compattate: un solo blocco per gruppo
    var groups = {};
    var render = [];
    timed.forEach(function (ev) {
        if (ev.routine_group && ev.routine_collapsed) {
            if (!groups[ev.routine_group]) { groups[ev.routine_group] = { items: [], base: ev }; render.push({ group: ev.routine_group }); }
            groups[ev.routine_group].items.push(ev);
        } else if (ev.routine_group) {
            if (!groups[ev.routine_group]) { groups[ev.routine_group] = { items: [], base: ev, open: true }; render.push({ groupOpen: ev.routine_group }); }
            groups[ev.routine_group].open = true;
            groups[ev.routine_group].items.push(ev);
        } else {
            render.push({ ev: ev });
        }
    });

    /* i blocchi di routine hanno inizio e fine dati dai loro passaggi:
       li calcoliamo qui, prima di spartire le corsie */
    render.forEach(function (item) {
        var key = item.group || item.groupOpen;
        if (!key) return;
        var g = groups[key];
        if (!g) return;
        var s = 1440, e = 0;
        g.items.forEach(function (x) { s = Math.min(s, toMin(x.start)); e = Math.max(e, toMin(x.end)); });
        item._start = s; item._end = e;
    });

    /* Gli eventi troppo bassi per mostrare il titolo cambiano forma prima di
       spartirsi lo spazio: un gruppo compattato copre il tempo di tutti i
       suoi eventi e alle corsie deve presentarsi come uno solo. */
    render = compattaMini(render);

    /* Chi si accavalla si divide lo spazio invece di coprirsi: raggruppiamo
       gli eventi che si toccano nel tempo e diamo a ciascuno la sua colonnina. */
    layoutOverlaps(render);

    render.forEach(function (item) {
        if (item.miniGroup) {
            var gn = buildMiniGroup(item.miniGroup, item._start, item._end);
            applyLane(gn, item);
            canvas.appendChild(gn);
            return;
        }
        if (item.ev) {
            var n = item.mini ? buildMiniEvent(item.ev)
                              : positioned(buildEvent(item.ev), item.ev.start, item.ev.end);
            applyLane(n, item);
            canvas.appendChild(n);
            return;
        }
        var g = groups[item.group || item.groupOpen];
        if (!g) return;
        var startMin = 1440, endMin = 0;
        g.items.forEach(function (x) { startMin = Math.min(startMin, toMin(x.start)); endMin = Math.max(endMin, toMin(x.end)); });
        /* con scatti larghi i passaggi non ci starebbero: la teniamo compatta
           anche se l'utente l'aveva lasciata aperta */
        var forceCompact = item.groupOpen && !canFoldRoutine(g.items);
        if (item.group || forceCompact) {
            var r = routineById(g.base.routine_id);
            var fake = {
                id: g.base.id, date: g.base.date, title: (r ? r.name : T('planner.routine_t')),
                start: toHM(startMin), end: toHM(endMin),
                color: g.base.color, importance: 'normal', type: 'event', done: false,
                routine_group: item.group || item.groupOpen, routine_collapsed: true,
                count: g.items.length, canFold: canFoldRoutine(g.items)
            };
            var node = positioned(buildEvent(fake), fake.start, fake.end);
            addRoutinePreview(node, g.items, (endMin - startMin) * pxMin());
            applyLane(node, item);
            canvas.appendChild(node);
        } else {
            /* aperta: ogni passaggio ha il suo blocco. La freccetta per
               richiuderla la mettiamo sul primo, così resta raggiungibile. */
            var wrapper = el('div', 'pl-routine-wrap');
            wrapper.style.top = 'calc(var(--pxm) * ' + startMin + ')';
            wrapper.style.height = 'calc(var(--pxm) * ' + (endMin - startMin) + ')';
            var lab = el('button', 'pl-routine-label');
            lab.type = 'button';
            var rr = routineById(g.base.routine_id);
            lab.appendChild(el('span', null, rr ? rr.name : T('planner.routine_t')));
            var thisGroup = item.groupOpen, thisDate = date;
            lab.addEventListener('click', function (ev2) {
                ev2.stopPropagation();
                openRoutineInstance(thisGroup, thisDate);
            });
            applyLane(wrapper, item);
            wrapper.appendChild(lab);
            g.items.forEach(function (x, idx) {
                var copy = {};
                for (var k in x) if (x.hasOwnProperty(k)) copy[k] = x[k];
                copy.canFold = idx === 0;     // la freccetta solo sul primo passaggio
                var node = positioned(buildEvent(copy), x.start, x.end);
                node.style.top = 'calc(var(--pxm) * ' + (toMin(x.start) - startMin) + ')';
                wrapper.appendChild(node);
            });
            canvas.appendChild(wrapper);
        }
    });

    // velo sul passato e linea dell'ora
    if (date < S.today) {
        var veil = el('div', 'pl-past');
        veil.style.height = 'calc(var(--pxm) * 1440)';
        canvas.appendChild(veil);
    } else if (date === S.today) {
        var now = new Date();
        var nowMin = now.getHours() * 60 + now.getMinutes();
        var v2 = el('div', 'pl-past');
        v2.style.height = 'calc(var(--pxm) * ' + nowMin + ')';
        canvas.appendChild(v2);
        var line = el('div', 'pl-now');
        line.style.top = 'calc(var(--pxm) * ' + nowMin + ')';
        canvas.appendChild(line);
    }
}

/* Una routine si può aprire solo se lo scatto attuale dà a ogni passaggio
   almeno un filo di altezza: con scatti larghi i pezzi si accavallerebbero
   in una poltiglia illeggibile, quindi la freccetta non compare nemmeno. */
function canFoldRoutine(items) {
    if (isAgenda()) return true;          // in elenco lo spazio non è un problema
    var shortest = 1440;
    items.forEach(function (x) { shortest = Math.min(shortest, toMin(x.end) - toMin(x.start)); });
    return shortest * pxMin() >= 13;
}

/* Sotto il titolo del blocco compatto: l'elenco dei passaggi per esteso se
   c'è spazio, altrimenti una riga sola che scorre da sé avanti e indietro. */
function addRoutinePreview(node, items, heightPx) {
    var righe = items.map(function (x, i) {
        return (i + 1) + '. ' + x.title + ' (' + (toMin(x.end) - toMin(x.start)) + ')';
    });
    /* i 30 pixel riservati sono il nome della routine più la sua riga di
       orario: quello spazio è già occupato e ai passaggi non resta */
    addPreviewLines(node, righe, heightPx, 30);
}

/* Le righe di dettaglio dentro un blocco compatto. Sapeva farle solo la
   routine, ma il blocco che raccoglie gli eventi bassi ha lo stesso identico
   problema (starci per esteso oppure scorrere), quindi chi chiama passa le
   righe già scritte e i pixel già occupati in cima. */
function addPreviewLines(node, righe, heightPx, riservato) {
    if (isAgenda() || heightPx < riservato) return;
    var perLine = 13;
    var roomLines = Math.floor((heightPx - riservato) / perLine);

    if (roomLines >= righe.length) {
        var box = el('div', 'pl-ev-sub');
        righe.forEach(function (t) {
            /* anche qui niente puntini: la riga che non ci sta scorre, come
               il titolo dei blocchi. Le misure finiscono nella stessa coda,
               quindi restano un calcolo di layout solo per fotogramma. */
            var riga = el('div', 'pl-ev-riga');
            var dentro = el('span', null, t);
            riga.appendChild(dentro);
            box.appendChild(riga);
            misuraStriscia(riga, dentro);
        });
        node.appendChild(box);
        return;
    }

    var strip = el('div', 'pl-ev-strip');
    var span = el('span', null, righe.join('; '));
    strip.appendChild(span);
    node.appendChild(strip);

    /* la riga parte ferma, scorre fino in fondo, si ferma di nuovo e torna.
       Toccandola si può scorrere a mano: in quel caso l'andirivieni si spegne. */
    misuraStriscia(strip, span);
    strip.addEventListener('pointerdown', function () { strip.classList.remove('pl-run'); });

    /* lo scorrimento a mano non deve trascinare via il giorno o le ore */
    strip.addEventListener('touchmove', function (e) { e.stopPropagation(); }, { passive: true });
}

/* Quanto deborda il testo si sa solo misurandolo, e misurare costa un
   ricalcolo del layout. Con gli scatti larghi le strisce da misurare in un
   ridisegno diventano parecchie, quindi si accodano tutte a un solo
   fotogramma e lì si fanno prima tutte le letture e poi tutte le scritture:
   alternandole, il browser rifarebbe i conti a ogni giro. */
var stripPending = [], stripRaf = 0;
function misuraStriscia(strip, span) {
    stripPending.push({ strip: strip, span: span });
    if (stripRaf) return;
    stripRaf = requestAnimationFrame(function () {
        stripRaf = 0;
        var lista = stripPending; stripPending = [];
        var over = lista.map(function (p) { return p.span.scrollWidth - p.strip.clientWidth; });
        lista.forEach(function (p, i) {
            if (over[i] <= 4) return;    // ci sta tutto, o è un nodo già buttato via
            p.strip.style.setProperty('--shift', over[i] + 'px');
            p.strip.style.setProperty('--dur', Math.max(9, Math.round(over[i] / 14) + 7) + 's');
            p.strip.classList.add('pl-run');
        });
    });
}

/* ------------------------------------------------------------------ */
/* Eventi troppo bassi per il proprio titolo (compattamento a vista)   */
/* ------------------------------------------------------------------ */
/* Con scatti fitti un evento da 15 minuti si legge benissimo; con scatti da
   un'ora o da tre diventa una lineetta schiacciata che sporge sul vicino,
   perché il blocco ha comunque un'altezza minima. Qui sotto la soglia gli
   eventi cambiano veste: uno solo diventa un tastino, più d'uno vicino
   diventa un blocco unico con l'elenco dentro.

   Vale solo per il disegno: nel database non cambia niente e rizoomando gli
   eventi tornano separati com'erano. */

/* Tarato sui due casi indicati: scatti da 30 minuti, evento da 15 → 22 px e
   il titolo si legge ancora; scatti da un'ora, stesso evento → 11,5 px e non
   si legge più. Il titolo occupa una riga da ~16 px più i 10 px di
   imbottitura del blocco, quindi qualunque valore fra i due estremi rispetta
   entrambi i casi. Sta a 14 e non più a 20 perché con gli scatti da tre ore
   un evento di un'ora fa 18 px: il titolo ci sta ancora, rimpicciolito, e
   toglierlo per sostituirlo con un tastino era una perdita secca. Sotto i 26
   px il carattere si stringe da sé (classe `pl-ev-stretto`). */
var MINI_ALTEZZA = 14;

/* Due eventi bassi si uniscono solo se sono vicini *sullo schermo*: la
   distanza si misura in pixel e non in minuti, perché è la scala a decidere
   se fra due blocchi resta un buco visibile oppure no. */
var MINI_STACCO = 12;

/* Il blocco che li raccoglie non può essere più basso di una riga di testo,
   anche quando gli eventi dentro durano in tutto pochi minuti. */
var MINI_GRUPPO_H = 24;

function evAltezzaPx(ev) {
    var s = toMin(ev.start), e = Math.max(toMin(ev.end), s + 5);
    return (e - s) * pxMin();
}

/* Riscrive l'elenco da disegnare sostituendo gli eventi bassi con la loro
   forma compatta. Va chiamata prima di spartire le corsie: un gruppo occupa
   il tempo di tutti i suoi eventi e deve contare come uno solo. */
function compattaMini(render) {
    /* in elenco non c'è una scala e il problema non esiste; durante la scelta
       per ritardo/anticipo servono le caselline dei singoli eventi, quindi lì
       si preferisce la vista schiacciata a una selezione che non si può fare */
    if (isAgenda() || S.shift.on) return render;

    var bassi = [], altri = [];
    render.forEach(function (item) {
        if (item.ev && evAltezzaPx(item.ev) < MINI_ALTEZZA) bassi.push(item.ev);
        else altri.push(item);
    });
    if (!bassi.length) return render;

    bassi.sort(function (a, b) { return toMin(a.start) - toMin(b.start); });

    var gruppi = [], corrente = null, fine = 0;
    bassi.forEach(function (ev) {
        var s = toMin(ev.start), e = Math.max(toMin(ev.end), s + 5);
        if (corrente && (s - fine) * pxMin() <= MINI_STACCO) {
            corrente.push(ev);
            fine = Math.max(fine, e);
        } else {
            corrente = [ev];
            gruppi.push(corrente);
            fine = e;
        }
    });

    gruppi.forEach(function (g) {
        if (g.length === 1) { altri.push({ ev: g[0], mini: true }); return; }
        var s = 1440, e = 0;
        g.forEach(function (x) {
            var xs = toMin(x.start);
            s = Math.min(s, xs);
            e = Math.max(e, Math.max(toMin(x.end), xs + 5));
        });
        altri.push({ miniGroup: g, _start: s, _end: e });
    });
    return altri;
}

/* La riga che descrive un evento quando il suo titolo non ci sta: è la stessa
   nell'elenco dentro il blocco e nel popup, così si legge la stessa cosa nei
   due posti. */
function miniRiga(ev, soloOrario) {
    var d = Math.max(5, toMin(ev.end) - toMin(ev.start));
    return (soloOrario ? '' : ((ev.title || T('planner.senza_titolo')) + ' · '))
        + ev.start + ' – ' + ev.end + ' · ' + wxDurata(d);
}

/* Evento basso e isolato: resta alto quanto i suoi minuti — senza altezza
   minima, che è proprio quella che gli faceva coprire il vicino — e perde il
   titolo, che sarebbe comunque tagliato a metà. Al suo posto un tastino, che
   non rischia di affollare la giornata: se gli eventi bassi fossero più
   d'uno verrebbero compattati e il tastino non comparirebbe. */
function buildMiniEvent(ev) {
    var node = el('div', 'pl-ev pl-ev-mini');
    node.style.setProperty('--ev', evColor(ev));
    applicaCalendario(node, ev);
    if (ev.importance === 'high') node.classList.add('pl-ev-high');
    if (ev.importance === 'highest') node.classList.add('pl-ev-highest');
    if (ev.done) node.classList.add('pl-done');
    node.dataset.id = ev.id;
    node.dataset.date = ev.date || S.focusDate;

    var s = toMin(ev.start), e = Math.max(toMin(ev.end), s + 5);
    node.style.top = 'calc(var(--pxm) * ' + s + ')';
    node.style.height = 'max(5px, calc(var(--pxm) * ' + (e - s) + '))';

    node.appendChild(bottoneMini((ev.title || T('planner.evento')) + ', ' + miniRiga(ev, true)));
    return node;
}

/* Il tastino a tre pallini: lo usano l'evento isolato e il gruppo che non ha
   spazio nemmeno per una riga. */
function bottoneMini(etichetta) {
    var b = el('button', 'pl-ev-mini-btn');
    b.type = 'button';
    b.dataset.act = 'mini';
    b.setAttribute('aria-label', etichetta);
    b.innerHTML = '<svg viewBox="0 0 22 6" fill="currentColor"><circle cx="4" cy="3" r="1.7"/><circle cx="11" cy="3" r="1.7"/><circle cx="18" cy="3" r="1.7"/></svg>';
    return b;
}

/* Più eventi bassi ravvicinati: un blocco solo con dentro l'elenco, uno per
   riga se c'è spazio e una riga che scorre se non ce n'è. Stessa veste del
   blocco di routine, ma senza il nome in cima: qui non c'è una routine, e un
   titolo inventato direbbe una cosa falsa. */
/* La durata vera di un gruppo di eventi: quanto tempo occupano davvero, non
   la distanza fra il primo inizio e l'ultima fine. Se fra due eventi c'è un
   buco, quel buco non è occupato da niente e non deve gonfiare il blocco; e
   se due si accavallano, il tempo in comune si conta una volta sola. */
function minutiUnione(items) {
    var seg = items.map(function (x) {
        var s = toMin(x.start);
        return [s, Math.max(toMin(x.end), s + 5)];
    }).sort(function (a, b) { return a[0] - b[0]; });

    var tot = 0, ini = seg[0][0], fin = seg[0][1];
    for (var i = 1; i < seg.length; i++) {
        if (seg[i][0] <= fin) { fin = Math.max(fin, seg[i][1]); continue; }
        tot += fin - ini;
        ini = seg[i][0]; fin = seg[i][1];
    }
    return tot + (fin - ini);
}

function buildMiniGroup(items, startMin, endMin) {
    var node = el('div', 'pl-ev pl-ev-mini-gruppo');

    /* il colore è quello degli eventi solo se vanno d'accordo: altrimenti il
       neutro, che non fa credere a una categoria comune che non c'è */
    var c = evColor(items[0]), uguale = true;
    items.forEach(function (x) { if (evColor(x) !== c) uguale = false; });
    node.style.setProperty('--ev', uguale ? c : NEUTRAL);

    /* l'identificativo serve al gestore del tocco, che parte sempre da un
       evento vero; l'elenco completo viaggia a parte */
    node.dataset.id = items[0].id;
    node.dataset.date = items[0].date || S.focusDate;
    node.dataset.miniGroup = '1';
    node.dataset.miniIds = items.map(function (x) { return x.id; }).join(',');

    node.style.top = 'calc(var(--pxm) * ' + startMin + ')';
    /* L'altezza è quella della durata vera, non dell'intervallo dal primo
       inizio all'ultima fine: allungare il blocco fino a coprire i buchi
       direbbe che quel tempo è occupato quando non lo è. */
    var h = Math.max(5, minutiUnione(items) * pxMin());
    node.style.height = h + 'px';

    /* Se non ci sta nemmeno una riga di testo, il testo non ci va: si fa come
       con l'evento isolato, un tastino che apre l'elenco. Una scritta che
       scorre dentro un blocco alto otto pixel non si legge comunque, e
       intanto si mangia lo spazio del blocco che viene dopo. */
    if (h < MINI_GRUPPO_H) {
        node.classList.add('pl-ev-mini');
        node.appendChild(bottoneMini(T('planner.n_eventi_ravvicinati', {n: items.length})));
        return node;
    }

    addPreviewLines(node, items.map(function (x) { return miniRiga(x, false); }), h, 8);
    return node;
}

/* Gli eventi di un blocco compatto, ripescati dai dati veri: nel nodo ci
   sono solo gli identificativi, così se qualcosa cambia nel frattempo si
   legge la versione aggiornata e non una copia vecchia. */
function miniEventiDi(node) {
    var lista = S.events[node.dataset.date] || [];
    var out = [];
    (node.dataset.miniIds || '').split(',').forEach(function (raw) {
        var id = parseInt(raw, 10);
        lista.forEach(function (x) { if (x.id === id) out.push(x); });
    });
    return out;
}

/* Il riepilogo di quello che il blocco non riesce a scrivere: nome, orario e
   durata. Da qui si arriva all'evento vero, se no il tastino sarebbe un
   vicolo cieco. */
function miniPopup(items) {
    if (!items.length) return;
    if (items.length === 1) {
        var ev = items[0];
        alertBox(ev.title || T('planner.senza_titolo'), miniRiga(ev, true), [
            { label: T('planner.chiudi') },
            { label: T('planner.apri'), kind: 'primary', onClick: function () { openEventSheet(ev, null); } }
        ]);
        return;
    }
    /* con pochi eventi ognuno ha il suo pulsante e ci si arriva in un tocco;
       con tanti la fila di pulsanti sarebbe più lunga del popup, quindi resta
       il solo elenco scritto */
    var azioni = [{ label: T('planner.chiudi') }];
    if (items.length <= 4) {
        items.forEach(function (ev) {
            azioni.push({
                label: ev.title || T('planner.senza_titolo'), kind: 'primary',
                onClick: function () { openEventSheet(ev, null); }
            });
        });
    }
    alertBox(T('planner.n_eventi_ravvicinati', {n: items.length}),
        items.map(function (x) { return miniRiga(x, false); }).join('\n'),
        azioni);
}

/* Assegna a ogni elemento una corsia, così due cose alla stessa ora stanno
   affiancate invece che una sopra l'altra. Gli elementi che si toccano nel
   tempo formano un gruppo e si spartiscono la larghezza in parti uguali. */
function layoutOverlaps(render) {
    var spans = render.map(function (item) {
        var s, e;
        if (item.ev) { s = toMin(item.ev.start); e = Math.max(toMin(item.ev.end), s + 5); }
        else {
            s = item._start; e = item._end;
        }
        return { item: item, s: s, e: e };
    }).filter(function (x) { return typeof x.s === 'number' && !isNaN(x.s); });

    spans.sort(function (a, b) { return a.s - b.s || a.e - b.e; });

    var group = [], groupEnd = -1;
    function closeGroup() {
        if (group.length < 2) {
            group.forEach(function (x) { x.item._lane = 0; x.item._lanes = 1; });
        } else {
            var lanes = [];   // per ogni corsia, il minuto in cui si libera
            group.forEach(function (x) {
                var placed = -1;
                for (var i = 0; i < lanes.length; i++) {
                    if (x.s >= lanes[i]) { placed = i; break; }
                }
                if (placed === -1) { lanes.push(x.e); placed = lanes.length - 1; }
                else lanes[placed] = x.e;
                x.item._lane = placed;
            });
            group.forEach(function (x) { x.item._lanes = lanes.length; });
        }
        group = []; groupEnd = -1;
    }
    spans.forEach(function (x) {
        if (group.length && x.s >= groupEnd) closeGroup();
        group.push(x);
        groupEnd = Math.max(groupEnd, x.e);
    });
    closeGroup();
}

/* Lo spazio che i blocchi accavallati si dividono non è tutta la colonna ma
   il 95%: l'ultimo pezzo resta vuoto perché non arrivino a filo del bordo
   destro dello schermo. È la sola aria voluta — a sinistra si parte attaccati
   alla barra degli orari, e fra una corsia e l'altra i blocchi si toccano,
   senza fessure che facevano sembrare due parti dello stesso evento due cose
   diverse. La stessa quota sta in `--ev-coda` nel foglio di stile, per i
   blocchi che una corsia non ce l'hanno. */
var EV_CODA = 5;   // per cento di colonna lasciato libero in fondo a destra

function applyLane(node, item) {
    var lanes = item._lanes || 1;
    if (lanes < 2) return;
    var lane = item._lane || 0;
    var w = (100 - EV_CODA) / lanes;
    node.style.left = (lane * w) + '%';
    node.style.width = w + '%';
    node.style.right = 'auto';
}

function positioned(node, start, end) {
    var s = toMin(start), e = Math.max(toMin(end), s + 5);
    /* In `calc` sulla densità, non in pixel già fatti: così pizzicando basta
       cambiare `--pxm` e il blocco si sposta da solo, senza che nessuno lo
       riscriva. È questo che rende il pizzico gratis invece che un ridisegno
       di tutta la pagina a ogni fotogramma. */
    node.style.top = 'calc(var(--pxm) * ' + s + ')';
    var h = (e - s) * pxMin();
    node.style.height = 'max(16px, calc(var(--pxm) * ' + (e - s) + ' - 2px))';
    if (h > 44) node.classList.add('pl-tall');
    /* casi al limite: il titolo ci sta ma per un pelo, quindi si stringe */
    else if (h < 26) node.classList.add('pl-ev-stretto');

    /* Sotto una certa altezza gli orari non ci stanno interi e uscivano
       tagliati a metà riga — un quarto d'ora con gli scatti da mezz'ora, una
       mezz'ora con quelli da un'ora. Mezza cifra non dice l'orario e ruba lo
       spazio al titolo, che è l'unica cosa che serve a riconoscere il blocco:
       meglio toglierli e lasciare il titolo da solo, in mezzo. L'orario esatto
       si legge aprendo l'evento, o allargando lo zoom. */
    node.classList.toggle('pl-ev-senzaora', h < 32);
    return node;
}

function buildEvent(ev, flow) {
    var node = el('div', 'pl-ev');
    if (flow) node.style.position = 'static';
    node.style.setProperty('--ev', evColor(ev));
    applicaCalendario(node, ev);
    if (ev.importance === 'high') node.classList.add('pl-ev-high');
    if (ev.importance === 'highest') node.classList.add('pl-ev-highest');
    if (ev.done) node.classList.add('pl-done');
    node.dataset.id = ev.id;
    node.dataset.date = ev.date || S.focusDate;
    if (ev.count) node.dataset.routineMerged = '1';   // il blocco compatto di una routine
    if (ev.giorno_tot > 1 && !ev.all_day) node.dataset.multi = '1';   // a cavallo di più giorni: non si trascina
    /* Una barra di tutto il giorno che viene da ieri o va a domani non deve
       finire come una che comincia e finisce oggi: dal lato in cui continua
       resta squadrata e arriva fino al bordo (vedi .pl-ev-da-prima e
       .pl-ev-continua in planner_griglia.css). Solo nella fascia (flow): nella
       griglia degli orari un blocco a cavallo di piu' giorni e' un'altra cosa. */
    if (flow && ev.giorno_tot > 1) {
        if (ev.giorno_num > 1) node.classList.add('pl-ev-da-prima');
        if (ev.giorno_num < ev.giorno_tot) node.classList.add('pl-ev-continua');
    }

    var row = el('div', 'pl-ev-row');
    if (S.shift.on) {
        var pick = el('span', 'pl-ev-box' + (S.shift.picked[ev.id] ? ' pl-picked' : ''));
        pick.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        row.appendChild(pick);
    } else if (ev.type === 'task' || ev.habit_id) {
        var box = el('span', 'pl-ev-box');
        box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        box.dataset.act = 'toggle';
        row.appendChild(box);
    }
    if (ev.habit_icon) row.appendChild(el('span', 'pl-ev-icon', ev.habit_icon));

    /* Il titolo non si tronca con i puntini: quello che non ci sta scorre
       avanti e indietro, come le righe dei blocchi compatti. I puntini
       tolgono proprio la parte che serve a distinguere due eventi che
       cominciano allo stesso modo. La misura costa un calcolo di layout, per
       questo passa dalla stessa coda che le raggruppa in un fotogramma solo. */
    var t = el('span', 'pl-ev-t');
    var tIn = el('span', null, ev.title);
    t.appendChild(tIn);
    misuraStriscia(t, tIn);
    row.appendChild(t);

    /* «2/4»: a che punto siamo di un evento che dura più giorni. Senza,
       aprendo il 12 un evento che va dal 10 al 14 si vedrebbe uguale a uno che
       comincia proprio quel giorno, e non si capirebbe né da quanto va avanti
       né quanto manca. Sta subito dopo il titolo perché è parte di che cosa è
       quell'evento, non un dettaglio da cercare aprendolo.
       Fuori dallo span del titolo: quello scorre avanti e indietro quando il
       testo non ci sta, e il progressivo si porterebbe via con lui. */
    if (ev.giorno_tot > 1) {
        var prog = el('span', 'pl-ev-prog', '(' + ev.giorno_num + '/' + ev.giorno_tot + ')');
        prog.title = T('planner.giorno_n_di_n', { n: ev.giorno_num, tot: ev.giorno_tot });
        row.appendChild(prog);
    }

    if (ev.routine_group && ev.canFold) {
        var fold = el('button', 'pl-ev-fold');
        fold.type = 'button';
        fold.dataset.act = 'fold';
        fold.dataset.group = ev.routine_group;
        fold.dataset.collapsed = ev.routine_collapsed ? '1' : '0';
        fold.innerHTML = ev.routine_collapsed
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 9l7 7 7-7"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 15l7-7 7 7"/></svg>';
        row.appendChild(fold);
    }
    /* con chi è, e dove: due tastini subito dopo il titolo */
    if (ev.guests && ev.guests.length && !S.shift.on) {
        var who = ev.guests.map(function (g) { return g.name || g.email.split('@')[0]; });
        var tagW = el('span', 'pl-ev-with', who.length === 1 ? who[0] : T('planner.n_persone', {n: who.length}));
        tagW.title = ev.guests.map(function (g) { return g.email; }).join(', ');
        row.appendChild(tagW);
    }
    if (ev.location && !S.shift.on) {
        var mapBtn = el('button', 'pl-ev-map');
        mapBtn.type = 'button';
        mapBtn.dataset.act = 'map';
        mapBtn.dataset.place = ev.location;
        mapBtn.setAttribute('aria-label', T('planner.portami_a', {posto: ev.location}));
        mapBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6.5-5.6-6.5-10a6.5 6.5 0 1113 0c0 4.4-6.5 10-6.5 10z"/><circle cx="12" cy="11" r="2.2"/></svg>';
        row.appendChild(mapBtn);
    }
    if (ev.project_id && !S.shift.on) {
        var pomo = el('button', 'pl-ev-pomo');
        pomo.type = 'button';
        pomo.dataset.act = 'pomo';
        pomo.dataset.project = ev.project_id;
        pomo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M8 5.5l10 6.5-10 6.5z"/></svg>';
        row.appendChild(pomo);
    }
    row.appendChild(el('span', 'pl-ev-spacer'));   // lo spazio avanzato resta in coda
    node.appendChild(row);
    if (!ev.all_day && (!flow || ev.start)) {
        /* il pezzo di un evento lungo mostra gli orari veri, non quelli
           ritagliati sulla giornata */
        var hS = ev.vero_start || ev.start, hE = ev.vero_end || ev.end;
        node.appendChild(el('div', 'pl-ev-h', hS + ' – ' + hE + (ev.count ? '  ·  ' + T('planner.n_passaggi', {n: ev.count}) : '')));
    }
    return node;
}
