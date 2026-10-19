/* =====================================================================
   STANDOUT — PLANNER (motore della pagina)
   Tutto quello che si muove: griglia degli orari con zoom, creazione
   con tocco o tenendo premuto, popup dell'evento, priorità, liste
   della settimana, routine, abitudini, vista mese, anticipi/ritardi.

   Questo e' il primo di 13 pezzi (planner/js/01..13): la funzione si apre
   qui e si chiude in 13_avvio.js. planner_js.php li unisce in ordine.
   ===================================================================== */
(function () {
'use strict';

var B = window.PL_BOOT || {};

/* ------------------------------------------------------------------ */
/* Stato                                                               */
/* ------------------------------------------------------------------ */
var S = {
    csrf:        B.csrf || '',
    today:       B.today,
    settings:    B.settings || {},
    categories:  B.categories || [],
    calendars:   B.calendars || [],
    routines:    B.routines || [],
    favorites:   B.favorites || [],
    week:        B.week || {},
    lists:       B.lists || [],
    diary:       B.diary || {},
    events:      {},          // 'YYYY-MM-DD' -> [evento]
    prio:        {},          // 'YYYY-MM-DD' -> [priorità]
    habits:      {},          // 'YYYY-MM-DD' -> [abitudine del giorno]
    rings:       {},          // 'YYYY-MM-DD' -> [anelli per famiglia di frequenza]
    weekly:      {},          // 'lunedì' -> [abitudine settimanale]
    focusDate:   B.today,
    centerWeek:  null,
    zoom:        (B.settings && B.settings.zoom) || 'm30',
    pxm:         0,           // pixel per minuto: la grandezza continua dello zoom
    /* L'attrezzo armato: un'abitudine o un progetto preferito scelto dal
       tastino e in attesa che si tocchi il calendario per dire DOVE. È lo
       stesso modo di lavorare della categoria — si sceglie prima cosa, poi
       dove — invece del popup che chiedeva l'orario a scatola chiusa. */
    armato:      null,        // { kind:'habit'|'project', item, dur }
    view:        'day',
    monthCursor: null,
    defaultCat:  null,
    hiddenCats:  {},
    /* Chiuse all'apertura della pagina, non aperte: appena caricata quello che
       serve è vedere la giornata, e due sezioni spiegate mangiano mezzo
       schermo di orari prima ancora di aver guardato niente. Si aprono al
       tocco. */
    prioSlim:    true,
    adSlim:      true,        // stessa cosa per la fascia di tutto il giorno
    /* Importanze spente. Vive solo in memoria e non sul server: è un filtro di
       lettura come lo strizzare gli occhi, non una preferenza — e ricordarselo
       fra una sessione e l'altra vorrebbe dire riaprire il planner senza gli
       eventi importanti senza sapere perché. */
    hiddenImp:   {},
    pillsDue:    B.pills_due || [],   // prese la cui ora è passata e nessuno ha risposto
    pillsChiuse: {},          // chiuse con la x: tornano al prossimo caricamento
    weekCache:   {},          // 'lunedì' -> { week, lists }
    todos:       null,        // 'todo'|'buy' -> [voce], letto da todo_api.php
    study:       null,        // { date, ok, argomenti } letto da study_api.php
    todoTab:     'todo',      // linguetta aperta nel pannello settimana
    pills:       null,        // prese del giorno a fuoco, lette da todo_api.php
    todoOthers:  false,       // "mostra anche gli altri" premuto
    shift:       { on: false, dir: 'late', picked: {} },
    interacting: false,       // un dito sta trascinando o creando: non ridisegnare
    renderPending: false,
    draft:       null,        // evento in lavorazione nel popup
    miniCursor:  null         // mese mostrato nel popup calendario (null = quello del giorno a fuoco)
};

var ZOOM = [
    { key: 'agenda', label: T('planner.solo_eventi'), slot: 0,   h: 0  },
    { key: 'h3',     label: T('planner.3_ore'),       slot: 180, h: 54 },
    { key: 'h1',     label: T('planner.1_ora'),       slot: 60,  h: 46 },
    { key: 'm30',    label: T('planner.30_minuti'),   slot: 30,  h: 44 },
    { key: 'm15',    label: T('planner.15_minuti'),   slot: 15,  h: 38 },
    { key: 'm5',     label: T('planner.5_minuti'),    slot: 5,   h: 26 }
];
var HEAD_H = 0;   // le colonne non hanno più un'intestazione propria
var NEUTRAL = '#E4DDD3';
var MESI = [T('planner.mese_1'),T('planner.mese_2'),T('planner.mese_3'),T('planner.mese_4'),T('planner.mese_5'),T('planner.mese_6'),T('planner.mese_7'),T('planner.mese_8'),T('planner.mese_9'),T('planner.mese_10'),T('planner.mese_11'),T('planner.mese_12')];
var GIORNI = [T('planner.gg_1'),T('planner.gg_2'),T('planner.gg_3'),T('planner.gg_4'),T('planner.gg_5'),T('planner.gg_6'),T('planner.gg_7')];
/* iniziali della griglia: in inglese sono M T W T F S S */
var GIORNI_S = [T('planner.gs_1'),T('planner.gs_2'),T('planner.gs_3'),T('planner.gs_4'),T('planner.gs_5'),T('planner.gs_6'),T('planner.gs_7')];
var LIST_LABEL = { todo: T('planner.to_do'), buy: T('planner.to_buy'), study: T('planner.to_study'), pills: T('planner.pastiglie') };
/* I tipi di elenco che todo_api.php conosce come `list_type`: le pastiglie
   NON sono uno di questi — stanno in tabelle loro e hanno endpoint loro —
   quindi restano fuori di qui e compaiono solo fra le linguette. Mescolarle
   vorrebbe dire mandare `list_type=pills` a un endpoint che non lo accetta. */
var TODO_TIPI = ['todo', 'buy', 'study'];
var PANEL_TABS = ['todo', 'buy', 'study', 'pills'];

/* ------------------------------------------------------------------ */
/* Piccole utility                                                     */
/* ------------------------------------------------------------------ */
function $(id) { return document.getElementById(id); }
function el(tag, cls, txt) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt !== undefined && txt !== null) n.textContent = txt;
    return n;
}
function esc(s) { return String(s == null ? '' : s); }
function pad(n) { return (n < 10 ? '0' : '') + n; }

function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
function addDays(s, n) { var d = parseIso(s); d.setDate(d.getDate() + n); return iso(d); }
function monday(s) { var d = parseIso(s); var dow = (d.getDay() + 6) % 7; d.setDate(d.getDate() - dow); return iso(d); }
function dowIndex(s) { return (parseIso(s).getDay() + 6) % 7; }
function diffDays(a, b) { return Math.round((parseIso(b) - parseIso(a)) / 86400000); }

function toMin(hhmm) { var p = String(hhmm || '0:0').split(':'); return (+p[0]) * 60 + (+p[1] || 0); }
function toHM(min) { min = Math.max(0, Math.min(1439, Math.round(min))); return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
function zoomCfg(key) { for (var i = 0; i < ZOOM.length; i++) if (ZOOM[i].key === key) return ZOOM[i]; return ZOOM[3]; }

/* ---- densità degli orari ------------------------------------------------
   Prima l'altezza era una proprietà dello scatto: sei valori fissi, e il
   pizzico saltava dall'uno all'altro. Ora la grandezza vera è UNA sola e
   continua — quanti pixel vale un minuto — e lo scatto si RICAVA da lei:
   si mostra il passo più fitto le cui righe restano abbastanza distanti da
   leggersi. Allargando le dita gli orari si allontanano davvero, e quando
   c'è spazio comparaiono da sé quelli di mezzo.
   Le altezze in ZOOM restano: sono la densità naturale di ogni scatto,
   quella che si ottiene premendo il tastino invece di pizzicare. */
/* Il fondo è la densità naturale dello scatto da tre ore, non meno: sotto
   quella gli orari si accavallano e la giornata diventa una striscia
   illeggibile. Chi stringe ancora non vuole orari più piccoli, vuole
   un'altra cosa — e quella cosa è la vista a lista. */
/* Il fondo è la densità naturale dello scatto da tre ore, non meno: sotto
   quella gli orari si accavallano e la giornata diventa una striscia
   illeggibile. Chi stringe ancora non vuole orari più piccoli, vuole
   un'altra cosa — e quella cosa è la vista a lista. */
var PXM_MIN = 54 / 180;
var PXM_MAX = 400 / 60;      // un'ora alta 400px: oltre, si vede solo vuoto

/* Il passo più fitto che a questa densità resta leggibile.

   La soglia di ogni scatto è la SUA altezza naturale, quella scritta in ZOOM,
   non un numero unico per tutti. Con una soglia unica bassa lo scatto da
   un'ora entrava in scena quando fra le 16 e le 17 c'erano ventisei pixel:
   tecnicamente leggibile, in pratica appiccicato. Così invece ogni scatto
   comincia dove il suo tastino lo mette e finisce dove comincia il
   successivo — lo scatto largo arriva prima, quello fitto più tardi, e la
   distanza fra due orari non scende mai sotto quella di partenza. */
function scattoPer(pxm) {
    for (var i = ZOOM.length - 1; i >= 0; i--) {
        if (!ZOOM[i].slot) continue;                       // 'agenda' non è un passo
        if (ZOOM[i].slot * pxm >= ZOOM[i].h) return ZOOM[i];
    }
    return zoomCfg('h3');
}

function pxMin() {
    if (S.zoom === 'agenda') return 0;
    if (!S.pxm) {
        var z = zoomCfg(S.zoom);
        applicaDensita(z.slot ? z.h / z.slot : 0);
    }
    return S.pxm;
}
function dayHeight() { return Math.round(1440 * pxMin()); }
function isAgenda() { return S.zoom === 'agenda'; }

/* Cambia la densità e, se serve, il passo. Ritorna true se il passo è
   cambiato, cioè se sono comparsi o spariti degli orari: è il momento in cui
   la rotellina deve accendere un'altra tacca. */
/* La densità vive anche come variabile CSS, e tutto quello che sta sulla
   griglia si posiziona a partire da lei: `top: calc(var(--pxm) * 480)` invece
   di `top: 368px` scritto in JS.

   PERCHÉ. Prima ogni millimetro di pizzico ricostruiva il DOM: fino a 288
   etichette di orari più tutti i blocchi di ventuno colonne, sessanta volte al
   secondo. Su iPhone il browser non regge e butta giù la scheda — è quello il
   «this web page was reloaded because a problem occurred». Scrivendo un solo
   numero, invece, si sposta tutto senza toccare un nodo, e il ridisegno vero
   serve solo quando cambia lo scatto, cioè tre o quattro volte per gesto. */
function applicaDensita(pxm) {
    S.pxm = pxm;
    /* NON sulla radice.

       Una proprietà personalizzata scritta su <html> invalida lo stile
       dell'INTERO documento: il browser non sa chi la eredita, quindi
       ricalcola tutto — barra in alto, pannelli, popup, griglia del mese,
       quella della settimana. Sessanta volte al secondo su diecimila nodi.
       Qui sotto invece la riga che contiene righello e colonne è l'unica
       parte che ha bisogno del numero, e il resto della pagina non viene
       nemmeno guardato. Il valore di scorta resta nel :root del CSS per il
       primo disegno. */
    var riga = document.querySelector('.pl-row');
    if (riga) riga.style.setProperty('--pxm', pxm + 'px');
}

var densitaInAttesa = null, densitaRaf = 0, ultimoRidisegno = 0;

/* Cambia la densità. Il lavoro vero è rimandato al fotogramma successivo:
   il dito manda molti più eventi di quanti schermi il telefono disegni, e
   rispondere a tutti vuol dire fare tre volte lo stesso calcolo e buttarne
   via due. */
function setDensita(pxm, ancora) {
    pxm = Math.max(PXM_MIN, Math.min(PXM_MAX, pxm));
    var nuovo = scattoPer(pxm).key;
    var cambiato = nuovo !== S.zoom;

    applicaDensita(pxm);
    S.zoom = nuovo;
    /* `cambiato` si SOMMA a quello già in attesa. Le dita mandano più
       movimenti per fotogramma: se lo scatto cambiava al secondo e il terzo
       diceva «no», l'ultimo vinceva e il ridisegno degli orari non avveniva
       mai. Da qui il pizzico che non passava mai allo scatto successivo pur
       avendo già cambiato S.zoom. */
    densitaInAttesa = {
        ancora: ancora,
        cambiato: (densitaInAttesa ? densitaInAttesa.cambiato : false) || cambiato
    };

    if (!densitaRaf) {
        densitaRaf = requestAnimationFrame(function () {
            densitaRaf = 0;
            var d = densitaInAttesa; densitaInAttesa = null;
            if (!d) return;
            /* Solo un cambio di scatto richiede DOM nuovo: gli orari da
               scrivere sono altri. Fuori da quel caso basta la variabile. */
            /* Le etichette degli orari cambiano solo cambiando scatto: fuori
               da quel caso le sposta la variabile e non c'è niente da
               ricostruire. La colonna a fuoco invece si rifà sempre, perché
               il compattamento dei blocchi piccoli dipende da quanto sono
               alti adesso, e quello nessuna variabile lo sa calcolare. */
            if (d.cambiato) {
                renderGutter();
                renderZoomDial();
                riallineaStriscia();
            }

            /* Il compattamento dei blocchi piccoli dipende da quanto sono
               alti adesso, e quello nessuna variabile CSS lo sa calcolare:
               va rifatto il DOM della colonna. Ma non sessanta volte al
               secondo — ricostruire migliaia di nodi al secondo riempie la
               memoria più in fretta di quanto il browser la liberi, ed è
               questo che dopo un po' fa cadere la scheda. Otto volte al
               secondo l'occhio non vede differenza e la memoria respira. */
            var ora = Date.now();
            if (d.cambiato || ora - ultimoRidisegno > 120) {
                ultimoRidisegno = ora;
                renderDays(true);
            }
            if (d.ancora) scrollToMinuteAt(d.ancora.min, d.ancora.y);
        });
    }
    return cambiato;
}

/* L'altezza della giornata segue la densità: è l'unica misura che non si può
   scrivere in `calc` perché serve anche allo scorrimento. */
/* L'altezza della giornata la calcola il CSS da sola, con la stessa
   variabile: `height: calc(var(--pxm) * 1440)`. Prima la riscriveva il JS su
   ventidue elementi a ogni fotogramma, e ogni scrittura obbliga il browser a
   rimisurare tutto prima della successiva. Resta qui solo per i casi in cui
   qualcuno aveva messo un'altezza fissa a mano. */
function aggiornaAltezzaCanvas() {
    if (isAgenda()) return;
    var g = $('plGutter');
    if (g && g.style.height) g.style.height = '';
    var cs = document.querySelectorAll('.pl-canvas');
    for (var i = 0; i < cs.length; i++) if (cs[i].style.height) cs[i].style.height = '';
}

/* elenchi sempre in ordine alfabetico: categorie, routine e preferiti */
function alpha(list, key) {
    return (list || []).slice().sort(function (a, b) {
        return String(a[key] || '').localeCompare(String(b[key] || ''), 'it', { sensitivity: 'base' });
    });
}

function catById(id) {
    for (var i = 0; i < S.categories.length; i++) if (S.categories[i].id === id) return S.categories[i];
    return null;
}
function routineById(id) {
    for (var i = 0; i < S.routines.length; i++) if (S.routines[i].id === id) return S.routines[i];
    return null;
}
function evColor(ev) {
    if (ev.color) return ev.color;
    if (ev.category_id) { var c = catById(ev.category_id); if (c) return c.color; }
    if (ev.project_color) return ev.project_color;
    if (ev.habit_color) return ev.habit_color;
    /* Il colore del calendario arriva per ultimo, quando l'evento non ne ha
       già uno suo: un evento dentro «vacanze» e senza categoria è verde come
       il suo calendario, che è quello che uno si aspetta creandolo lì. */
    var cc = calColor(ev);
    if (cc) return cc;
    return NEUTRAL;
}

/* Il colore del calendario di un evento, se ne ha uno. Il principale non ne
   ha, ed è giusto così: i suoi eventi restano come sono sempre stati. */
function calColor(ev) {
    if (!ev.calendar_id) return null;
    var c = calById(ev.calendar_id);
    return (c && c.color) ? c.color : null;
}

/* Il calendario dipinge il bordo, la categoria il blocco.

   Quando l'evento ha già un colore suo — la categoria «spostamenti» è rossa —
   il verde di «vacanze» non può prendersi anche il fondo, se no una delle due
   informazioni sparisce. Va sul contorno, ma non come una riga verde piantata
   sul rosso: il bordo è il colore del calendario *mescolato al fondo del
   blocco*, così si legge come una cornice imparentata con quello che
   racchiude invece che come un ritaglio incollato sopra.
   Se il colore del calendario è già quello del blocco non si fa niente:
   sarebbe un contorno dello stesso colore, cioè niente. */
function applicaCalendario(node, ev) {
    var cc = calColor(ev);
    if (!cc || cc === evColor(ev)) return;
    node.style.setProperty('--cal', cc);
    node.classList.add('pl-ev-cal');
}
function calById(id) {
    for (var i = 0; i < S.calendars.length; i++) if (S.calendars[i].id === id) return S.calendars[i];
    return null;
}
function evHidden(ev) {
    /* L'importanza si filtra come un calendario: «fammi vedere solo quello che
       conta» è la stessa domanda di «fammi vedere solo il calendario del
       lavoro», e prima si poteva fare la seconda e non la prima. */
    if (ev.importance && ev.importance !== 'normal' && S.hiddenImp[ev.importance]) return true;
    if (ev.category_id && S.hiddenCats[ev.category_id]) return true;
    /* un evento senza calendario appartiene a quello predefinito, che è
       sempre visibile finché non lo si nasconde di proposito */
    if (ev.calendar_id) {
        var c = calById(ev.calendar_id);
        if (c && !c.visible) return true;
    }
    return false;
}

function haptic(ms) { if (navigator.vibrate) { try { navigator.vibrate(ms || 8); } catch (e) {} } }

/* ------------------------------------------------------------------ */
/* Chiamate al server                                                  */
/* ------------------------------------------------------------------ */
/* Una richiesta al server. `opt.keepalive` serve nell'ultimo istante di vita
   della pagina: dice al browser di portare a termine l'invio anche se la
   scheda si sta chiudendo. `opt.retry` riprova una volta sola quando è caduta
   la rete — non quando il server ha risposto "no", che è una risposta valida
   e ripeterla non cambierebbe nulla. */
/* Le letture identiche già in volo non si rifanno.
   Guardando le richieste di un minuto qualunque si vedeva `habits_day` sullo
   stesso giorno quattro volte e `range` sullo stesso periodo tre: non perché
   qualcuno le chiedesse quattro volte di proposito, ma perché quattro pezzi
   diversi hanno bisogno degli stessi dati e ognuno se li andava a prendere per
   conto suo. Ogni copia costa un viaggio di rete e una query.
   Si condivide solo la LETTURA (senza corpo): una scrittura ripetuta è una
   cosa che si vuole davvero fare due volte. E la promessa si dimentica appena
   risolta — è una finestra di pochi millisecondi, non una cache: i dati veri
   li tengono già S.events, S.habits e compagnia. */
var letturaInVolo = {};

function api(action, data, query, opt) {
    opt = opt || {};
    if (savingNow) opt.keepalive = true;   // stiamo svuotando la coda alla chiusura
    var url = 'planner_api.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }

    if (!data && !opt.keepalive) {
        var giaInVolo = letturaInVolo[url];
        if (giaInVolo) return giaInVolo;
    }

    function invia() {
        var req = {
            method: data ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.STANDOUT_CSRF || S.csrf }
        };
        if (data) { req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(data); }
        if (opt.keepalive) req.keepalive = true;
        return fetch(url, req).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: T('planner.risposta_non_valida') }; });
        });
    }

    var p = invia().catch(function () {
        if (!data || opt.keepalive || opt.noRetry) throw 0;
        /* La rete è caduta a metà. Una sola ripetizione, dopo un attimo:
           basta a coprire il buco di copertura in ascensore o il cambio di
           cella, senza rischiare di applicare due volte la stessa cosa. */
        return new Promise(function (res) { setTimeout(res, 900); }).then(invia);
    }).catch(function () {
        return { ok: false, message: T('planner.connessione_non_riuscita') };
    });

    if (!data && !opt.keepalive) {
        letturaInVolo[url] = p;
        // la si dimentica comunque vada: tenerla dopo un errore vorrebbe dire
        // servire quell'errore a chiunque richieda la stessa cosa
        p.then(function () { delete letturaInVolo[url]; },
               function () { delete letturaInVolo[url]; });
    }
    return p;
}

/* I salvataggi rimandati.

   PERCHÉ SI PERDEVANO. Scrivendo in una priorità o in una nota, il salvataggio
   parte 650ms dopo l'ultimo tasto. Se in quei 650ms si chiudeva la pagina, si
   passava a un'altra app o si bloccava lo schermo, il timer moriva con la
   pagina e quello che era stato scritto non arrivava mai al server. Nessun
   errore, nessun avviso: semplicemente non c'era più. È questo il "a volte non
   vengono salvate".

   Ora ogni salvataggio in attesa viene mandato subito quando la pagina sta per
   sparire, con keepalive perché parta davvero anche mentre si chiude. */
var saveQueue = {};
function debounceSave(key, ms, fn) {
    if (saveQueue[key]) clearTimeout(saveQueue[key].timer);
    saveQueue[key] = {
        fn: fn,
        timer: setTimeout(function () { saveQueue[key] = null; fn(); }, ms || 700)
    };
}

/* Manda tutto quello che è in attesa, adesso. */
function flushSaves(chiusura) {
    savingNow = !!chiusura;
    for (var key in saveQueue) {
        if (!saveQueue.hasOwnProperty(key) || !saveQueue[key]) continue;
        var voce = saveQueue[key];
        saveQueue[key] = null;
        clearTimeout(voce.timer);
        try { voce.fn(); } catch (e) {}
    }
    savingNow = false;
}
/* Le funzioni di salvataggio chiamano api(): questa spia dice loro di usare
   keepalive, senza doverle riscrivere una per una. */
var savingNow = false;

/* pagehide copre la chiusura e il passaggio ad altra app su iPhone;
   visibilitychange copre il blocco schermo e il cambio di scheda. Servono
   entrambi: nessuno dei due da solo copre tutti i casi. */
window.addEventListener('pagehide', function () { flushSaves(true); });
document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flushSaves(true);
});

/* ------------------------------------------------------------------ */
/* Avvisi e messaggi                                                   */
/* ------------------------------------------------------------------ */
var toastTimer = null;
function toast(msg) {
    var t = $('plToast');
    t.textContent = msg;
    t.classList.add('pl-open');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('pl-open'); }, 2600);
}

function alertBox(title, msg, actions) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = title;
    $('plAlertMsg').textContent = msg;
    var acts = $('plAlertActs');
    acts.innerHTML = '';
    (actions || [{ label: T('planner.ho_capito') }]).forEach(function (a) {
        var b = el('button', 'pl-btn' + (a.kind === 'primary' ? ' pl-btn-primary' : (a.kind === 'danger' ? ' pl-btn-danger' : '')), a.label);
        b.type = 'button';
        b.addEventListener('click', function () {
            ov.classList.remove('pl-open');
            if (a.onClick) a.onClick();
        });
        acts.appendChild(b);
    });
    ov.classList.add('pl-open');
}
$('plAlertOv').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('pl-open'); });

function openOv(id) { $(id).classList.add('pl-open'); document.documentElement.classList.add('pl-locked'); }
function closeOv(id) {
    $(id).classList.remove('pl-open');
    if (!document.querySelector('.pl-ov.pl-open')) document.documentElement.classList.remove('pl-locked');
}
document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-pl-close]') : null;
    if (t) closeOv(t.getAttribute('data-pl-close'));
});

/* ------------------------------------------------------------------ */
/* Dati: indicizzazione e caricamento                                  */
/* ------------------------------------------------------------------ */
function indexEvents(list, clearRange) {
    if (clearRange) {
        for (var d = clearRange.from; d <= clearRange.to; d = addDays(d, 1)) S.events[d] = [];
    }
    function posa(giorno, ev) {
        if (!S.events[giorno]) S.events[giorno] = [];
        S.events[giorno] = S.events[giorno].filter(function (x) { return x.id !== ev.id; });
        S.events[giorno].push(ev);
    }
    (list || []).forEach(function (ev) {
        /* Un evento di più giorni arriva dal server UNA volta sola, con la sua
           data d'inizio: è una riga sola nel database e deve restare tale. Qui
           lo si mette in tutte le giornate che copre, perché aprendo il 12 un
           evento che va dal 10 al 14 deve esserci — sul 12, non solo sul 10.
           Le copie tengono `date` uguale alla data VERA d'inizio: è quella che
           usano modifica, spostamento e cancellazione, e cambiarla vorrebbe
           dire che aprendo la copia del 12 si sposterebbe l'evento al 12.
           Per ora solo gli eventi di tutto il giorno: quelli con un orario
           vivono nella griglia delle ore, dove una copia trascinabile su un
           altro giorno farebbe più danni che comodo. */
        var tot = giorniFra(ev.date, ev.end_date || ev.date);
        if (ev.all_day && tot > 1) {
            ev.giorno_num = 1;
            ev.giorno_tot = tot;
            for (var i = 1; i < tot; i++) {
                var g = piuGiorni(ev.date, i);
                var copia = Object.assign({}, ev, { giorno_num: i + 1, giorno_tot: tot, mostrato_il: g });
                posa(g, copia);
            }
        }
        ev.mostrato_il = ev.date;
        posa(ev.date, ev);
    });
}
function indexPrio(map) {
    for (var k in map) if (map.hasOwnProperty(k)) S.prio[k] = map[k];
}

function indexPills(list) {
    if (!list) return;
    S.pillsDue = list;
    renderAvvisi();
}
function eventsOf(date) {
    var list = (S.events[date] || []).filter(function (e) { return !evHidden(e); });
    list.sort(function (a, b) {
        if (a.all_day !== b.all_day) return a.all_day ? -1 : 1;
        return toMin(a.start) - toMin(b.start);
    });
    return list;
}

function applyWeek(weekStart) {
    var c = S.weekCache[weekStart];
    if (!c) return false;
    S.week = c.week;
    S.lists = c.lists;
    renderWeekBits();
    return true;
}

/* Come sono fatte adesso le priorità del giorno a fuoco. Serve solo a capire
   se sono cambiate: il contenuto vero lo disegna renderPrio. */
function firmaPrio(date) {
    var l = (S.prio && S.prio[date]) || [];
    var out = [];
    for (var i = 0; i < l.length; i++) {
        var p = l[i] || {};
        out.push([p.position, p.content, p.done ? 1 : 0, p.auto ? 1 : 0, p.overdue ? 1 : 0].join('\u0001'));
    }
    return date + '\u0002' + out.join('\u0003');
}

function loadWeek(weekStart, force) {
    if (S.weekCache[weekStart] && !force) {
        if (monday(S.focusDate) === weekStart) applyWeek(weekStart);
        return Promise.resolve();
    }
    // FIX 4: Scarica 60 giorni per popolare in automatico la griglia della vista mensile
    return api('range', null, { from: addDays(weekStart, -14), to: addDays(weekStart, 45) }).then(function (r) {
        if (!r.ok) return;
        indexEvents(r.events, { from: r.from, to: r.to });
        indexPrio(r.priorities || {});
        indexPills(r.pills_due);
        for (var d in r.diary) if (r.diary.hasOwnProperty(d)) S.diary[d] = r.diary[d];
        S.weekCache[weekStart] = { week: r.week, lists: r.lists || [] };
        if (monday(S.focusDate) === weekStart) applyWeek(weekStart);
        renderDays();
        /* Le priorità si ridisegnano solo se sono davvero cambiate.
           È lo stesso difetto già visto sul righello: cambiando giorno la
           sezione veniva disegnata subito con quello che c'era in memoria e
           poi UNA SECONDA VOLTA quando tornava la rete, con gli stessi
           identici dati. Il secondo disegno rifà da capo i nodi, le strisce
           che scorrono ripartono da zero e le altezze si assestano di nuovo:
           da fuori è uno scattino a giorno appena cambiato.
           La firma è una stringa e non un confronto profondo: si costruisce e
           si paragona in un attimo, e qui basta sapere SE è cambiato — non
           cosa. */
        var firmaOra = firmaPrio(S.focusDate);
        if (firmaOra !== S.firmaPrio) { S.firmaPrio = firmaOra; renderPrio(); }
        maybeAskCopy();
        var ag = $('plMonthAgenda');
        if (ag && ag.classList.contains('pl-open') && S.agendaFrom) {
            var y = $('plMagendaBody').scrollTop;
            openMonthAgenda(S.agendaFrom);
            $('plMagendaBody').scrollTop = y;
        }
    });
}

function loadHabits(date) {
    if (S.habits[date]) return Promise.resolve(S.habits[date]);
    return api('habits_day', null, { date: date }).then(function (r) {
        S.habits[date] = r.ok ? r.habits : [];
        S.weekly[monday(date)] = r.ok ? (r.weekly || []) : [];
        return S.habits[date];
    });
}
function weeklyOf(date) { return S.weekly[monday(date)] || []; }
