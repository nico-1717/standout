/* =====================================================================
   NISAMA — PLANNER (motore della pagina)
   Tutto quello che si muove: griglia degli orari con zoom, creazione
   con tocco o tenendo premuto, popup dell'evento, priorità, liste
   della settimana, routine, abitudini, vista mese, anticipi/ritardi.
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
    pillsDue:    B.pills_due || [],   // prese la cui ora è passata e nessuno ha risposto
    pillsChiuse: {},          // chiuse con la x: tornano al prossimo caricamento
    weekCache:   {},          // 'lunedì' -> { week, lists }
    todos:       null,        // 'todo'|'buy'|'study' -> [voce], letto da todo_api.php
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
    { key: 'agenda', label: 'Solo eventi', slot: 0,   h: 0  },
    { key: 'h3',     label: '3 ore',       slot: 180, h: 54 },
    { key: 'h1',     label: '1 ora',       slot: 60,  h: 46 },
    { key: 'm30',    label: '30 minuti',   slot: 30,  h: 44 },
    { key: 'm15',    label: '15 minuti',   slot: 15,  h: 38 },
    { key: 'm5',     label: '5 minuti',    slot: 5,   h: 26 }
];
var HEAD_H = 0;   // le colonne non hanno più un'intestazione propria
var NEUTRAL = '#E4DDD3';
var MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
var GIORNI = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
var GIORNI_S = ['L','M','M','G','V','S','D'];
var LIST_LABEL = { todo: 'To do', buy: 'To buy', study: 'To study', pills: 'Pastiglie' };
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
function api(action, data, query, opt) {
    opt = opt || {};
    if (savingNow) opt.keepalive = true;   // stiamo svuotando la coda alla chiusura
    var url = 'planner.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }

    function invia() {
        var req = {
            method: data ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf }
        };
        if (data) { req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(data); }
        if (opt.keepalive) req.keepalive = true;
        return fetch(url, req).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Risposta non valida.' }; });
        });
    }

    return invia().catch(function () {
        if (!data || opt.keepalive || opt.noRetry) throw 0;
        /* La rete è caduta a metà. Una sola ripetizione, dopo un attimo:
           basta a coprire il buco di copertura in ascensore o il cambio di
           cella, senza rischiare di applicare due volte la stessa cosa. */
        return new Promise(function (res) { setTimeout(res, 900); }).then(invia);
    }).catch(function () {
        return { ok: false, message: 'Connessione non riuscita.' };
    });
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
    (actions || [{ label: 'Ho capito' }]).forEach(function (a) {
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
    (list || []).forEach(function (ev) {
        if (!S.events[ev.date]) S.events[ev.date] = [];
        S.events[ev.date] = S.events[ev.date].filter(function (x) { return x.id !== ev.id; });
        S.events[ev.date].push(ev);
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
        renderPrio();
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

/* ------------------------------------------------------------------ */
/* Cielo, insetto, palette                                             */
/* ------------------------------------------------------------------ */
/* ---- alberi, foglie, insetti: una scena diversa per ogni mese ---- */
/* ambKey: che cosa c'è disegnato adesso nella barra ('mese|7', 'wx|2026-08-01|3').
   Serve a non ricostruire la scena a ogni ridisegno, ma solo quando cambia. */
var AMB = null, ambKey = null;


function svgWrap(inner, extra) {
    return '<svg viewBox="0 0 60 80" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round">'
         + inner + (extra || '') + '</svg>';
}
/* (treeMarkup, TREES e BUDS sono spariti: gli alberi non si disegnano più a
   codice, sono file dentro ambient/ — uno per mese, disegni veri invece di
   quattro curve approssimate.) */

var PART_SVG = {
    snow:  '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="3.6" fill="currentColor"/></svg>',
    petal: '<svg viewBox="0 0 10 10"><path d="M5 .8C7.6 2.6 8.8 5 7.4 7.2 6.4 8.8 3.6 8.8 2.6 7.2 1.2 5 2.4 2.6 5 .8z" fill="currentColor"/></svg>',
    leaf:  '<svg viewBox="0 0 12 12"><path d="M11 1C6 1 1 4 1 8.2c0 1.6.8 2.6 2 2.8C4.6 8 7.4 5 11 1z" fill="currentColor"/></svg>',
    seed:  '<svg viewBox="0 0 12 12"><circle cx="6" cy="8.6" r="1.5" fill="currentColor"/><g stroke="currentColor" stroke-width=".7" stroke-linecap="round"><path d="M6 7.2V2M6 7.2L2.6 3.4M6 7.2l3.4-3.8M6 7.2L3.2 6M6 7.2L8.8 6"/></g></svg>',
    rain:  '<svg viewBox="0 0 4 14"><path d="M2 1v12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
    mote:  '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="3" fill="currentColor" opacity=".7"/></svg>',
    star:  '<svg viewBox="0 0 12 12"><path d="M6 0l1 5 5 1-5 1-1 5-1-5-5-1 5-1z" fill="currentColor"/></svg>'
};
var DRIFT_SVG = {
    butterfly:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M12 6v11"/><path d="M12 8c-2-4-6-5-8-3 0 3 3 6 8 7"/><path d="M12 8c2-4 6-5 8-3 0 3-3 6-8 7"/><path d="M12 6l-1.6-2.4M12 6l1.6-2.4"/></svg>',
    bee:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><ellipse cx="13" cy="14" rx="5" ry="3.6"/><path d="M11 11.2v5.6M14 10.8v6.4"/><path d="M11 11c-2-3-5-4-7-2 0 2.4 2.4 4 5 4.4"/><path d="M18 12.4c1.6-.8 2.6-.4 3 .6"/></svg>',
    ladybug:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><circle cx="12" cy="13" r="5.6"/><path d="M12 7.4v11"/><circle cx="9" cy="11.6" r=".9" fill="currentColor"/><circle cx="15" cy="14.4" r=".9" fill="currentColor"/><path d="M10 6.4l-.8-1.6M14 6.4l.8-1.6"/></svg>',
    dragonfly:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"><path d="M12 7v11"/><circle cx="12" cy="6" r="1.4"/><path d="M12 9.4c-3.4-2-6.6-2-8 0 1.4 1.6 4.6 1.8 8 .6"/><path d="M12 9.4c3.4-2 6.6-2 8 0-1.4 1.6-4.6 1.8-8 .6"/><path d="M12 12.6c-2.6-1.4-5-1.4-6 0 1 1.2 3.4 1.4 6 .5"/><path d="M12 12.6c2.6-1.4 5-1.4 6 0-1 1.2-3.4 1.4-6 .5"/></svg>',
    bird:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M3 13c3-4 6-4 9 0 3-4 6-4 9 0"/></svg>',
    swallow:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M2 11c4-3.6 7.4-3 10 1 2.6-4 6-4.6 10-1"/><path d="M12 12v3.4"/></svg>'
};

function rand(a, b) { return a + Math.random() * (b - a); }

/* La cartella dei disegni veri di alberi e animali: un file per mese, esterni
   e non scritti a mano nel codice, così il browser scarica solo quello che
   serve e se lo tiene in cache.

   Si caricano come MASCHERA, non come immagine: la maschera ritaglia la forma
   e il colore lo mette il fondo. Un solo file basta quindi per qualunque
   tinta, e l'albero di ottobre può essere ramato senza avere un file ramato. */
/* La cartella dei disegni di alberi e animali. Il percorso è relativo alla
   PAGINA, non a questo file: viene risolto a partire dall'indirizzo di
   planner.php, perché gli indirizzi delle maschere sono impostati qui via
   JavaScript. Quindi 'svg/' significa la cartella svg accanto a planner.php.
   Se un giorno sposti i disegni, cambia solo questa riga. */
var AMBIENT_DIR = 'svg/';

function fondale(nome, colore, classe) {
    var n = el('div', classe);
    var u = 'url("' + AMBIENT_DIR + nome + '.svg")';
    n.style.webkitMaskImage = u;
    n.style.maskImage = u;
    n.style.backgroundColor = colore;
    return n;
}

/* Chi si muove e chi no.

   Quasi tutti stanno fermi: sono animali che, visti dal vero, se ne stanno
   lì — il gatto dorme, la talpa spunta dalla terra, il pinguino guarda. Un
   pinguino che sfreccia da un lato all'altro sembrava una lepre.
   Camminano solo quelli che camminando ci si vedono davvero, e piano.
   La slitta è l'unica che vola, e plana come i gabbiani. */
var ANIMALI_CHE_CAMMINANO = ['cane', 'tartaruga', 'riccio'];
var ANIMALI_CHE_VOLANO    = ['slitta'];

function renderAmbient(cfg) {
    var host = $('plAmbient');
    if (!host) return;
    host.innerHTML = '';
    /* solo questa scena obbedisce alla barra dell'opacità */
    host.classList.add('pl-amb-natura');
    var color = cfg.treeColor || '#9BAA9C';

    /* Un albero solo, verso il primo quarto della barra. Prima ce n'erano due,
       uno per lato, ed erano due copie della stessa sagoma: con un disegno
       vero la ripetizione si notava. */
    host.appendChild(fondale(cfg.tree || 'albero-giugno', color, 'pl-tree'));

    /* l'animale del mese */
    if (cfg.animal) {
        var moto = '';
        if (ANIMALI_CHE_CAMMINANO.indexOf(cfg.animal) !== -1) moto = ' pl-animal-cammina';
        else if (ANIMALI_CHE_VOLANO.indexOf(cfg.animal) !== -1) moto = ' pl-animal-vola';
        /* Una classe col nome della bestia (pl-animal-cane, pl-animal-volpe…)
           così nel CSS si può ritoccare posizione e dimensione di una sola
           senza toccare le altre: un cane e una tartaruga non stanno bene
           nello stesso riquadro. */
        var a = fondale(cfg.animal, cfg.animalColor || '#7A6F64',
                        'pl-animal pl-animal-' + cfg.animal + moto);
        if (moto) a.style.animationDelay = '-' + rand(0, 20).toFixed(1) + 's';
        host.appendChild(a);
    }

    (cfg.particles || []).forEach(function (p) {
        var still = p.type === 'star';
        for (var n = 0; n < (p.count || 8); n++) {
            var size = rand(p.size ? p.size[0] : 4, p.size ? p.size[1] : 8);
            var part = el('div', 'pl-part ' + (still ? 'pl-part-still' : (p.type === 'rain' ? 'pl-part-rain' : 'pl-part-fall')));
            part.style.left = rand(-2, 98).toFixed(1) + '%';
            part.style.width = size.toFixed(1) + 'px';
            part.style.height = (p.type === 'rain' ? size * 1.6 : size).toFixed(1) + 'px';
            part.style.color = (p.colors || ['#FFFFFF'])[n % (p.colors || ['#FFFFFF']).length];
            part.style.animationDuration = rand(p.duration ? p.duration[0] : 16, p.duration ? p.duration[1] : 26).toFixed(1) + 's';
            part.style.animationDelay = '-' + rand(0, 22).toFixed(1) + 's';
            part.style.setProperty('--sway', rand(-(p.sway || 20), p.sway || 20).toFixed(0) + 'px');
            if (still) { part.style.top = rand(6, 62).toFixed(0) + '%'; part.style.animationDuration = rand(3, 6).toFixed(1) + 's'; }
            part.innerHTML = PART_SVG[p.type] || PART_SVG.mote;
            host.appendChild(part);
        }
    });

    (cfg.drifters || []).forEach(function (d) {
        for (var n = 0; n < (d.count || 1); n++) {
            var isFly = d.type === 'firefly';
            /* chi cammina o scivola non batte le ali: niente ondeggio veloce */
            /* pinguino, scoiattolo, riccio, renna e slitta non passano più di
               qui: sono diventati l'animale del mese, disegnato da file.
               Restano insetti e uccelli. */
            var terrestre = false;
            var slitta = false;
            var wrap = el('div', 'pl-drift'
                + (isFly ? ' pl-firefly' : '')
                + (d.type === 'bird' || d.type === 'swallow' ? ' pl-drift-slow' : '')
                + (terrestre ? ' pl-drift-walk' : '')
                + (slitta ? ' pl-drift-walk pl-drift-fly' : ''));
            var size = d.size || 15;
            wrap.style.top = rand(8, 62).toFixed(0) + '%';
            wrap.style.width = size + 'px';
            wrap.style.height = (isFly ? size : size) + 'px';
            wrap.style.color = d.color || '#B7AEA3';
            wrap.style.animationDuration = ((d.duration || 42) + rand(-6, 6)).toFixed(1) + 's';
            wrap.style.animationDelay = '-' + rand(0, 30).toFixed(1) + 's';
            if (!isFly) {
                var inner = el('span', 'pl-drift-in');
                inner.style.animationDuration = rand(2.4, 4.2).toFixed(1) + 's';
                inner.innerHTML = DRIFT_SVG[d.type] || DRIFT_SVG.butterfly;
                wrap.appendChild(inner);
            }
            host.appendChild(wrap);
        }
    });
}

/* ------------------------------------------------------------------ */
/* La barra in alto quando il meteo è acceso                           */
/* ------------------------------------------------------------------ */

/* Sagome della scena. Sono qui e non in `svg/` perché non sono disegni:
   sono forme geometriche di poche righe, e caricarle come file vorrebbe
   dire una richiesta in più per ogni nuvola. */
var WXA_SVG = {
    nuvola: '<svg viewBox="0 0 100 46" preserveAspectRatio="none"><path d="M20 44h58c11 0 18-7 18-15 0-9-8-15-17-14-2-9-10-14-19-14-11 0-19 7-21 16-9 0-16 6-16 14 0 7 5 13 10 13z" fill="currentColor"/></svg>',
    sole:   '<svg viewBox="0 0 60 60"><circle cx="30" cy="30" r="13" fill="currentColor"/><g stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M30 4v8M30 48v8M4 30h8M48 30h8M11.6 11.6l5.6 5.6M42.8 42.8l5.6 5.6M11.6 48.4l5.6-5.6M42.8 17.2l5.6-5.6"/></g></svg>',
    luna:   '<svg viewBox="0 0 60 60"><path d="M38 6a24 24 0 100 48 20 20 0 010-48z" fill="currentColor"/></svg>'
};

/* Quante gocce (o fiocchi) per ciascun tipo di tempo: la quantità è il
   modo più immediato per far sentire la differenza fra pioviggine e
   rovescio, più delle icone. */
/* I numeri sono raddoppiati abbondanti rispetto a prima: la scena era così
   discreta che a colpo d'occhio pioggia e nuvolo si somigliavano, e la barra
   non diceva niente. Ora un rovescio si vede che è un rovescio. Restano
   elementi animati solo con `transform` e `opacity`, quindi il costo cresce
   di poco anche a sessanta gocce. */
var WXA_SCENA = {
    'sole':            { sole: true,  nuvole: 0, gocce: 0,  tipo: null },
    'sole-nubi':       { sole: true,  nuvole: 3, gocce: 0,  tipo: null },
    'nubi':            { sole: false, nuvole: 7, gocce: 0,  tipo: null, coperto: true },
    'nebbia':          { sole: false, nuvole: 2, gocce: 0,  tipo: null, nebbia: true },
    'pioggia-leggera': { sole: false, nuvole: 4, gocce: 22, tipo: 'rain' },
    'pioggia':         { sole: false, nuvole: 6, gocce: 40, tipo: 'rain', coperto: true },
    'pioggia-forte':   { sole: false, nuvole: 7, gocce: 56, tipo: 'rain', coperto: true },
    'neve':            { sole: false, nuvole: 5, gocce: 34, tipo: 'snow' },
    'temporale':       { sole: false, nuvole: 7, gocce: 46, tipo: 'rain', lampo: true, coperto: true }
};

/* Quanto fa caldo, in tre gradini. Il colore lo decide la massima del giorno
   e non l'icona, perché una giornata di sole a 18° e una a 38° hanno la
   stessa icona ma non sono affatto la stessa cosa. */
function wxCaldo(g) {
    var t = (g && g.tmax !== null && g.tmax !== undefined) ? g.tmax : null;
    if (t === null) return 0;
    if (t >= 36) return 3;
    if (t >= 32) return 2;
    if (t >= 28) return 1;
    return 0;
}

/* Vale la pena sostituire alberi e bestie con il cielo del giorno solo
   quando si guarda un giorno solo: con più giorni davanti, una scena sola
   racconterebbe il tempo di uno e mentirebbe sugli altri. */
/* La scena della barra racconta il tempo di *un* giorno, quindi vale solo
   dove se ne guarda uno: la vista giorno con una colonna sola e la vista
   settimanale, dove il giorno scelto è quello agganciato a sinistra. Con più
   colonne davanti direbbe il tempo di una spacciandolo per quello di tutte.
   `visibleDays()` non basta a dirlo: in vista settimana non conta quante
   colonne si vedono, perché il giorno scelto è comunque uno solo. */
function ambUnGiorno() {
    if (S.view === 'month') return false;
    if (S.view === 'week') return true;
    return visibleDays() < 2;
}

function ambientMeteo() {
    if (!WX.on || !ambUnGiorno()) return null;
    var g = WX.giorni[S.focusDate];
    if (!g) return null;
    /* Su oggi conta che tempo fa adesso, non il riassunto della giornata: una
       giornata segnata "poco nuvolosa" che alle sei di sera sta piovendo deve
       mostrare la pioggia, se no la barra racconta una cosa che non c'è più.
       Sugli altri giorni il riassunto è l'unica cosa che si possa dire. */
    if (S.focusDate === S.today && WX.adesso && WX.adesso.icon) {
        return { icon: WX.adesso.icon, code: 'ora-' + WX.adesso.icon,
                 tmax: g.tmax, tmin: g.tmin, label: WX.adesso.label || g.label };
    }
    return g;
}

/* Sera e notte. Vale solo su oggi: sugli altri giorni non c'è un'ora
   corrente da guardare, e una previsione non è né di giorno né di notte. */
function wxNotte(date) {
    if (date !== S.today) return false;
    /* Il server manda già se è giorno o notte lì dove ci si trova, calcolato
       su alba e tramonto veri: a dicembre alle sei di sera è buio, a giugno
       no, e un orario fisso avrebbe sbagliato mezzo anno. L'orologio resta
       come ripiego se quel dato non arriva. */
    if (WX.adesso && WX.adesso.giorno !== null && WX.adesso.giorno !== undefined) {
        return WX.adesso.giorno === false;
    }
    var h = new Date().getHours();
    return h < 6 || h >= 20;
}

function renderAmbientMeteo(g, date) {
    var host = $('plAmbient');
    if (!host) return;
    host.innerHTML = '';
    host.classList.remove('pl-amb-natura');
    var s = WXA_SCENA[g.icon] || WXA_SCENA['nubi'];

    /* Il rosso del caldo va per primo, così sole e nuvole gli restano sopra
       invece di finirci sotto. Al terzo gradino la barra è tutta rossa: è
       esattamente il colpo d'occhio che deve dare una giornata a 36 gradi. */
    var caldo = wxCaldo(g);
    if (caldo) host.appendChild(el('div', 'pl-wxa-caldo pl-wxa-caldo-' + caldo));
    /* cielo coperto: un velo grigio dietro le nuvole, se no fra tre nuvole e
       sette si vede solo che sono di più, non che il cielo è chiuso */
    if (s.coperto) host.appendChild(el('div', 'pl-wxa-coperto'));

    /* Di sera e di notte la scena cambia registro: un velo scuro sopra tutto
       e, a cielo libero, qualche stella. Senza, alle undici di sera la barra
       sembrava un pomeriggio d'estate. */
    var notte = wxNotte(date);
    if (notte) {
        host.appendChild(el('div', 'pl-wxa-notte'));
        if (!s.coperto && !s.gocce && !s.nebbia) {
            for (var st = 0; st < 12; st++) {
                var stella = el('div', 'pl-part pl-part-still pl-wxa-stella');
                stella.style.left = rand(2, 96).toFixed(1) + '%';
                stella.style.top = rand(6, 62).toFixed(0) + '%';
                var sz = rand(4, 8);
                stella.style.width = sz.toFixed(1) + 'px';
                stella.style.height = sz.toFixed(1) + 'px';
                stella.style.animationDuration = rand(2.4, 5.5).toFixed(1) + 's';
                stella.style.animationDelay = '-' + rand(0, 4).toFixed(1) + 's';
                stella.innerHTML = PART_SVG.star;
                host.appendChild(stella);
            }
        }
    }

    /* Sole o luna secondo l'ora: alle nove di sera un sole pieno nella
       barra è la cosa più stonata che si possa vedere. */
    if (s.sole) {
        var astro = el('div', 'pl-wxa-sun' + (notte ? ' pl-wxa-moon' : '')
                       + (!notte && caldo >= 2 ? ' pl-wxa-sun-caldo' : ''));
        astro.innerHTML = notte ? WXA_SVG.luna : WXA_SVG.sole;
        host.appendChild(astro);
    }

    for (var n = 0; n < s.nuvole; n++) {
        var c = el('div', 'pl-wxa-cloud');
        c.innerHTML = WXA_SVG.nuvola;
        c.style.top = rand(4, 34).toFixed(0) + '%';
        c.style.width = rand(78, 132).toFixed(0) + 'px';
        /* durate lunghe e sfasate: due nuvole che viaggiano appaiate si
           vedrebbero come un blocco unico */
        c.style.animationDuration = rand(90, 150).toFixed(0) + 's';
        c.style.animationDelay = '-' + rand(0, 80).toFixed(0) + 's';
        c.style.opacity = (0.62 + Math.random() * 0.34).toFixed(2);
        host.appendChild(c);
    }

    if (s.nebbia) {
        for (var f = 0; f < 3; f++) {
            var banda = el('div', 'pl-wxa-fog');
            banda.style.top = (24 + f * 20) + '%';
            banda.style.animationDuration = rand(28, 46).toFixed(0) + 's';
            banda.style.animationDelay = '-' + rand(0, 20).toFixed(0) + 's';
            host.appendChild(banda);
        }
    }

    for (var i = 0; i < s.gocce; i++) {
        var neve = s.tipo === 'snow';
        var p = el('div', 'pl-part ' + (neve ? 'pl-part-fall' : 'pl-part-rain'));
        var size = neve ? rand(4, 7.5) : rand(7, 13);
        p.style.left = rand(-2, 98).toFixed(1) + '%';
        p.style.width = (neve ? size : size / 3.4).toFixed(1) + 'px';
        p.style.height = size.toFixed(1) + 'px';
        p.style.color = neve ? '#FFFFFF' : '#6F9BB8';
        p.style.animationDuration = (neve ? rand(9, 16) : rand(1.1, 2.2)).toFixed(1) + 's';
        p.style.animationDelay = '-' + rand(0, 12).toFixed(1) + 's';
        p.style.setProperty('--sway', rand(-14, 14).toFixed(0) + 'px');
        p.innerHTML = neve ? PART_SVG.snow : PART_SVG.rain;
        host.appendChild(p);
    }

    if (s.lampo) {
        var lampo = el('div', 'pl-wxa-flash');
        host.appendChild(lampo);
    }

    /* Niente riassunto al centro: copriva la scena ed era un doppione, il
       popup si apre già dal tag accanto al giorno, dalla striscia sotto le
       priorità e dal tastino del meteo. Così la barra torna tutta decorazione
       e lascia passare i tocchi (le regole `.pl-wxa-tag` restano nel foglio
       di stile, semmai il riassunto dovesse tornare). */
}

/* Decide che cosa mettere nella barra: la scena del meteo se il meteo è
   acceso su un giorno solo, altrimenti l'albero e la bestia del mese.
   La chiave serve a non ridisegnare tutto a ogni scorrimento: si ricostruisce
   solo quando cambia davvero la scena (mese diverso, o tempo diverso). */
/* Quanto si vede la scena del mese: 0 invisibile, 10 piena. C'è chi la trova
   invadente ma non la vuole spegnere del tutto, e prima l'unica scelta era
   fra tutto e niente. Il valore di partenza è 9 — quasi il massimo — cioè
   com'era prima che la barra esistesse. Il meteo non è compreso: quella
   scena dice che tempo fa, non è una decorazione. */
function ambOpacita() {
    var v = S.settings.ambient_opacity;
    return (v === 0 || v) ? Math.max(0, Math.min(10, v)) : 9;
}
function applyAmbientOpacity() {
    document.documentElement.style.setProperty('--ambiente-op', (ambOpacita() / 10).toFixed(2));
}

/* La carta d'identità della scena di un giorno: due giorni con la stessa
   chiave hanno la stessa barra. Serve a due cose — non ricostruirla quando
   non è cambiato niente, e sapere se la copia fatta per il giorno vicino
   durante il cambio pagina va bene o va buttata. Prima il conto stava dentro
   applyAmbient e nessun altro poteva farlo: è il motivo per cui oltre
   l'ultimo giorno di previsione si continuava a vedere il tempo di prima. */
function ambChiave(date) {
    if (S.settings.ambient_enabled === false) return 'niente';
    var g = (WX.on && ambUnGiorno()) ? WX.giorni[date] : null;
    if (g) {
        var w = (date === S.today && WX.adesso && WX.adesso.icon)
            ? { icon: WX.adesso.icon, code: 'ora-' + WX.adesso.icon, tmax: g.tmax, tmin: g.tmin }
            : g;
        return 'wx|' + date + '|' + w.code + '|' + wxCaldo(w) + '|' + (wxNotte(date) ? 'n' : 'g');
    }
    if (!AMB) return 'niente';
    var base = S.view === 'month' ? (S.monthCursor || date) : date;
    return 'mese|' + (parseIso(base).getMonth() + 1);
}

function applyAmbient(force) {
    var host = $('plAmbient');
    if (!host) return;
    if (S.settings.ambient_enabled === false) { host.innerHTML = ''; ambKey = 'niente'; return; }

    var chiave = ambChiave(S.focusDate);
    if (!force && chiave === ambKey) return;
    ambKey = chiave;

    var wxg = ambientMeteo();
    if (wxg) { renderAmbientMeteo(wxg, S.focusDate); return; }

    if (!AMB) return;
    /* mese nuovo, cielo nuovo */
    setTimeout(applySky, 0);
    var base = S.view === 'month' ? (S.monthCursor || S.focusDate) : S.focusDate;
    renderAmbient((AMB.months && AMB.months[String(parseIso(base).getMonth() + 1)]) || {});
}

/* Tutto quello che il meteo tocca, rifatto in una volta sola. Prima ogni
   punto che accendeva o spegneva il meteo si ricordava a mano quali cinque
   cose ridisegnare, e bastava dimenticarne una perché un tag o una scena
   restassero indietro fino al ridisegno successivo. */
function wxRedraw() {
    renderDays();
    renderWeekBits();
    renderGutterMeteo(null, S.focusDate);
    /* la colonna delle ore della settimana è un'altra: accendendo il meteo
       da lì, senza questo i tag comparivano solo al ridisegno dopo */
    if (S.view === 'week') renderGutterMeteo($('plWeekGut'), S.focusDate);
    applyAmbient(true);
    if (S.view === 'month') renderMonth();
}

function loadAmbient() {
    return fetch('planner_ambient.json', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { AMB = d; applyAmbient(true); applySky(); })
        .catch(function () { /* senza il file la barra resta pulita */ });
}

/* Il colore della fascia in alto: con alberi e insetti attivi segue il mese
   (freddo d'inverno, caldo d'estate, così si sente la stagione); altrimenti
   segue il colore che l'utente ha scelto per la pagina. Prima era sempre
   una sfumatura calda fissa, che con le palette fredde stonava. */
function applySky() {
    var bar = $('plTopbar');
    if (!bar) return;
    bar.className = 'pl-topbar';

    var base = S.view === 'month' ? (S.monthCursor || S.focusDate) : S.focusDate;
    var m = String(parseIso(base).getMonth() + 1);
    var cfg = AMB && AMB.months ? AMB.months[m] : null;

    if (S.settings.ambient_enabled !== false && cfg && cfg.sky) {
        bar.style.setProperty('--sky-grad',
            'linear-gradient(168deg, ' + cfg.sky[0] + ' 0%, ' + cfg.sky[1] + ' 52%, var(--paper) 94%)');
    } else {
        /* nessun ambiente: la fascia è un velo del colore scelto */
        bar.style.setProperty('--sky-grad',
            'linear-gradient(168deg, var(--accent-wash) 0%, var(--paper) 78%)');
    }
    applyAmbient();
}
function applyPalette(key) {
    var root = document.documentElement;
    root.className = root.className.replace(/pl-palette-\S+/g, '').trim() + ' pl-palette-' + key;
}

/* ------------------------------------------------------------------ */
/* Barra in alto: settimana, mese, mini calendario, affermazione        */
/* ------------------------------------------------------------------ */
function renderWeekBits() {
    var d = parseIso(S.focusDate);
    if (S.view !== 'month') {
        $('plMonthName').textContent = MESI[d.getMonth()];
        $('plMonthYear').textContent = d.getFullYear();
    }
    var nd = visibleDays();
    /* il tag del meteo si rifà da capo a ogni giro: senza toglierlo, cambiando
       giorno se ne accumulerebbe uno accanto all'altro */
    var wxVecchio = $('plDayWx');
    if (wxVecchio && wxVecchio.parentNode) wxVecchio.parentNode.removeChild(wxVecchio);
    if (S.view === 'month') {
        // nella vista mese il giorno non ha senso: si guarda il mese intero
        $('plDayNum').textContent = '';
        $('plDayWd').textContent = '';
    } else if (nd >= 2) {
        /* il giorno a fuoco sta in mezzo, quindi l'intervallo comincia prima
           di lui: "24 ven – 26 dom" con il 25 al centro */
        var firstIso = addDays(S.focusDate, -daysBefore());
        var lastIso = addDays(firstIso, nd - 1);
        var first = parseIso(firstIso);
        var last = parseIso(lastIso);
        var host = $('plDayNum');
        host.textContent = '';
        host.appendChild(document.createTextNode(String(first.getDate())));
        host.appendChild(el('span', 'pl-day-wd-in', GIORNI[dowIndex(firstIso)].slice(0, 3)));
        host.appendChild(el('span', 'pl-day-dash', '–'));
        host.appendChild(document.createTextNode(String(last.getDate())));
        host.appendChild(el('span', 'pl-day-wd-in', GIORNI[dowIndex(lastIso)].slice(0, 3)));
        $('plDayWd').textContent = '';
    } else {
        $('plDayNum').textContent = String(d.getDate());
        $('plDayWd').textContent = GIORNI[dowIndex(S.focusDate)];
        /* Con un giorno solo il meteo si appoggia qui, accanto al nome del
           giorno: è l'unico posto dove si vede sempre. Sta *fuori* dal
           pulsante del giorno e non dentro: un tasto dentro un altro tasto
           confonde i lettori di schermo e rende ambiguo il tocco. */
        var wtop = wxTag(S.focusDate);
        if (wtop) {
            wtop.id = 'plDayWx';
            wtop.classList.add('pl-day-wx');
            var btn = $('plDayBtn');
            btn.parentNode.insertBefore(wtop, btn.nextSibling);
        }
    }
    $('plTodayBtn').textContent = String(parseIso(S.today).getDate());
    $('plWeekN').textContent = S.week.number || 1;
    $('plWeekDone').classList.toggle('pl-on', !!S.week.completed);
    $('plWeekDone').setAttribute('aria-checked', S.week.completed ? 'true' : 'false');

    var aff = $('plAffirm');
    aff.textContent = S.settings.show_affirmations === false ? '' : (S.week.affirmation || '');
    aff.classList.toggle('pl-hidden', !aff.textContent);

    var qb = $('plQuoteBar');
    var showQuote = S.settings.show_motivational !== false && !!S.week.quote;
    $('plQuoteText').textContent = S.week.quote || '';
    qb.classList.toggle('pl-hidden', !showQuote);

    renderMiniStatic();
    renderMini();
    updateWeekPanelBtn();
    syncScrollerTop();
}

/* calendarietto sempre visibile: la settimana in corso è una fascia colorata */
function renderMiniStatic() {
    var box = $('plMiniStatic');
    if (!box) return;
    box.innerHTML = '';
    GIORNI_S.forEach(function (g) {
        var s = el('span', 'pl-sw-wd', g);
        box.appendChild(s);
    });
    var focus = parseIso(S.focusDate);
    var start = monday(iso(new Date(focus.getFullYear(), focus.getMonth(), 1)));
    var wStart = monday(S.focusDate);
    for (var i = 0; i < 42; i++) {
        var ds = addDays(start, i);
        var dd = parseIso(ds);
        var s2 = el('span', null, String(dd.getDate()));
        if (dd.getMonth() !== focus.getMonth()) s2.className = 'pl-sw-out';
        if (monday(ds) === wStart) s2.className += ' pl-sw-week';
        if (ds === S.today) s2.className += ' pl-sw-today';
        box.appendChild(s2);
    }
}

function renderMini() {
    var box = $('plMini');
    box.innerHTML = '';

    var cursor = parseIso(S.miniCursor || S.focusDate);
    var head = el('div', 'pl-mini-head pl-serif', MESI[cursor.getMonth()] + ' ' + cursor.getFullYear());
    box.appendChild(head);
    GIORNI_S.forEach(function (g) { box.appendChild(el('div', 'pl-mini-wd', g)); });

    var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    var start = monday(iso(first));
    var wStart = monday(S.focusDate);

    for (var i = 0; i < 42; i++) {
        var ds = addDays(start, i);
        var dd = parseIso(ds);
        var cell = el('div', 'pl-mini-d', String(dd.getDate()));
        if (dd.getMonth() !== cursor.getMonth()) cell.classList.add('pl-out');
        if (monday(ds) === wStart) cell.classList.add('pl-inweek');
        if (ds === S.today) cell.classList.add('pl-today-d');
        cell.dataset.date = ds;
        box.appendChild(cell);
        if (i >= 34 && monday(addDays(start, i + 1)) !== monday(ds) && dd.getMonth() !== cursor.getMonth()) break;
    }
}

/* sfoglia i mesi DENTRO al popup, senza toccare il giorno a fuoco */
function stepMini(delta) {
    var c = parseIso(S.miniCursor || S.focusDate);
    var next = new Date(c.getFullYear(), c.getMonth() + delta, 1);
    S.miniCursor = iso(next);
    renderMini();
}
function openMini() {
    S.miniCursor = null;
    renderMini();
    $('plMini').classList.add('pl-open');
}
function toggleMini() {
    var box = $('plMini');
    if (box.classList.contains('pl-open')) box.classList.remove('pl-open');
    else openMini();
}

/* mese: in vista mese sfoglia i nomi dei mesi; in vista giorno passa alla
   vista mese (il pulsante dedicato non serve più). */
$('plMonthBtn').addEventListener('click', function () {
    if (S.view === 'month') {
        $('plMonthsBar').classList.toggle('pl-open');
        syncScrollerTopAnimated();
        return;
    }
    setView('month');
});
/* giorno e calendarietto: in vista mese tornano al giorno; in vista giorno
   aprono il popup del calendario. */
$('plDayBtn').addEventListener('click', function () {
    if (S.view === 'month') { setView('day'); return; }
    toggleMini();
});
$('plMiniStatic').addEventListener('click', function () {
    if (S.view === 'month') { setView('day'); return; }
    toggleMini();
});
$('plMini').addEventListener('click', function (e) {
    var c = e.target.closest('.pl-mini-d');
    if (!c || !c.dataset.date) return;
    goToDate(c.dataset.date, true);
    $('plMini').classList.remove('pl-open');
});
/* dentro al popup: scorri a destra o sinistra per vedere gli altri mesi */
(function miniSwipe() {
    var box = $('plMini');
    var x0 = null, y0 = 0, horiz = false;
    box.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; horiz = false;
    }, { passive: true });
    box.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) horiz = true;
    }, { passive: true });
    box.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var t = e.changedTouches[0];
        var dx = t.clientX - x0;
        x0 = null;
        if (!horiz || Math.abs(dx) < 30) return;
        stepMini(dx < 0 ? 1 : -1);   // scorri a sinistra: mese successivo
        haptic(6);
    }, { passive: true });
    box.addEventListener('wheel', function (e) {
        if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return;
        e.preventDefault();
        stepMini(e.deltaX > 0 ? 1 : -1);
    }, { passive: false });
})();
/* un tocco fuori chiude i menù dei pulsanti a destra: lasciarli aperti
   copriva la griglia e bloccava lo scorrimento degli orari */
document.addEventListener('pointerdown', function (e) {
    if (e.target.closest('.pl-fab-menu, .pl-fabs button, .pl-ring')) return;
    closeMenus();
}, true);

/* toccare fuori dal calendario lo richiude: ora è un pannello indipendente
   sopra la pagina, non più una sezione che spinge giù il resto. */
document.addEventListener('click', function (e) {
    var mini = $('plMini');
    if (!mini.classList.contains('pl-open')) return;
    if (e.target.closest('#plMini, #plMonthBtn, #plDayBtn, #plMiniStatic')) return;
    mini.classList.remove('pl-open');
});

$('plWeekDone').addEventListener('click', function (e) {
    e.stopPropagation();
    var next = !S.week.completed;
    S.week.completed = next;
    this.classList.toggle('pl-on', next);
    haptic(10);
    api('week_save', { week_start: monday(S.focusDate), completed: next });
});
$('plWeekBtn').addEventListener('click', function (e) {
    if (e.target.closest('#plWeekDone')) return;
    askWeekNumber();
});

/* Riepilogo della settimana: il numero si modifica in cima, sotto c'è
   quanto stai davvero spendendo su abitudini, categorie e routine, e
   com'è andato il diario (quest'ultimo solo da martedì in avanti). */
function askWeekNumber() {
    var week = monday(S.focusDate);
    openSheet('La settimana', function (body) {
        /* numero, in piccolo e modificabile */
        var top = el('div', 'pl-row-sb');
        var lab = el('div');
        lab.appendChild(el('div', 'pl-label', 'Settimana numero'));
        lab.appendChild(el('div', 'pl-row-sub', 'Cambiandolo, le successive si rinumerano da sole.'));
        top.appendChild(lab);
        var num = el('input', 'pl-pick');
        num.type = 'number'; num.min = '1'; num.max = '9999';
        num.value = S.week.number || 1;
        num.style.width = '76px';
        num.style.textAlign = 'center';
        num.addEventListener('change', function () {
            var n = Math.max(1, Math.min(9999, parseInt(num.value, 10) || 1));
            api('week_save', { week_start: week, number: n }).then(function (r) {
                if (!r.ok) return;
                S.week = r.week;
                if (S.weekCache[week]) S.weekCache[week].week = r.week;
                renderWeekBits();
            });
        });
        top.appendChild(num);
        body.appendChild(top);

        var box = el('div');
        box.appendChild(el('div', 'pl-quote pl-serif', 'Sto mettendo insieme i conti…'));
        body.appendChild(box);

        api('week_summary', null, { week_start: week }).then(function (r) {
            box.innerHTML = '';
            if (!r.ok) {
                box.appendChild(el('div', 'pl-quote pl-serif', 'Riepilogo non disponibile.'));
                return;
            }
            box.appendChild(summaryHabits(r));
            box.appendChild(summaryCats(r));
            box.appendChild(summaryRoutines(r));
            if (r.show_diary && r.diary) box.appendChild(summaryDiary(r.diary));
        });
    });
}

/* una riga del riepilogo: pallino colorato, nome, dettaglio, valore a destra */
function sumRow(color, name, detail, value) {
    var row = el('div', 'pl-row-sb');
    var left = el('div', 'pl-row');
    if (color) {
        var dot = el('span');
        dot.style.cssText = 'width:9px;height:9px;border-radius:50%;flex-shrink:0;background:' + color;
        left.appendChild(dot);
    }
    var txt = el('div');
    txt.appendChild(el('div', 'pl-row-lab', name));
    if (detail) txt.appendChild(el('div', 'pl-row-sub', detail));
    left.appendChild(txt);
    row.appendChild(left);
    if (value) {
        var v = el('div', 'pl-row-lab', value);
        v.style.whiteSpace = 'nowrap';
        row.appendChild(v);
    }
    return row;
}
function sumSection(title, note) {
    var f = el('div', 'pl-field');
    f.style.marginTop = '20px';
    f.appendChild(el('label', 'pl-label', title));
    if (note) f.appendChild(el('div', 'pl-row-sub', note));
    return f;
}
function hoursLabel(min) {
    if (!min) return '—';
    if (min < 60) return min + ' min';
    var h = Math.floor(min / 60), m = min % 60;
    return h + 'h' + (m ? ' ' + pad(m) : '');
}

function summaryHabits(r) {
    var f = sumSection('Abitudini');
    var any = false;
    (r.habits || []).forEach(function (h) {
        if (!h.planned && !h.minutes) return;
        any = true;
        var goal = h.goal ? ' su ' + h.goal + ' volte' : '';
        var detail = h.planned + ' volt' + (h.planned === 1 ? 'a' : 'e') + goal
                   + (h.done ? '  ·  ' + h.done + ' fatt' + (h.done === 1 ? 'a' : 'e') : '');
        f.appendChild(sumRow(h.color, (h.icon ? h.icon + ' ' : '') + h.name, detail, hoursLabel(h.minutes)));
    });
    if (!any) {
        f.appendChild(el('div', 'pl-row-sub', 'Nessuna abitudine messa in agenda questa settimana.'));
        return f;
    }
    var tot = sumRow(null, 'In tutto sulle abitudini', null, hoursLabel(r.habit_minutes));
    tot.style.borderTop = '.5px solid var(--hair)';
    f.appendChild(tot);
    return f;
}

function summaryCats(r) {
    var f = sumSection('Categorie');
    var any = false;
    (r.categories || []).forEach(function (c) {
        if (!c.minutes && !c.count) return;
        any = true;
        f.appendChild(sumRow(c.color, c.name,
            c.count + ' event' + (c.count === 1 ? 'o' : 'i'), hoursLabel(c.minutes)));
    });
    if (!any) f.appendChild(el('div', 'pl-row-sub', 'Ancora niente in agenda.'));
    return f;
}

function summaryRoutines(r) {
    var f = sumSection('Routine');
    var any = false;
    (r.routines || []).forEach(function (x) {
        if (!x.times && !x.minutes) return;
        any = true;
        f.appendChild(sumRow(x.color, x.name,
            x.times + ' volt' + (x.times === 1 ? 'a' : 'e'), hoursLabel(x.minutes)));
    });
    if (!any) f.appendChild(el('div', 'pl-row-sub', 'Nessuna routine inserita.'));
    return f;
}

function summaryDiary(d) {
    var f = sumSection('Diario');
    f.appendChild(sumRow(null, 'Giorni scritti', null, d.days + ' su 7'));
    var faces = window.nisamaFace;
    var moodRow = sumRow(null, 'Come è andata, in media', null,
        d.mood_avg !== null ? String(d.mood_avg).replace('.', ',') : '—');
    var enRow = sumRow(null, 'Energia, in media', null,
        d.energy_avg !== null ? String(d.energy_avg).replace('.', ',') : '—');
    if (faces) {
        /* le stesse faccine e stelline del diario, disegnate a matita */
        if (d.mood_avg !== null) {
            var mi = el('span');
            mi.style.cssText = 'width:26px;height:26px;color:var(--ink-2);flex-shrink:0';
            mi.innerHTML = faces('mood', Math.round(d.mood_avg), 26);
            moodRow.querySelector('.pl-row').insertBefore(mi, moodRow.querySelector('.pl-row').firstChild);
        }
        if (d.energy_avg !== null) {
            var ei = el('span');
            ei.style.cssText = 'width:26px;height:26px;color:var(--ink-2);flex-shrink:0';
            ei.innerHTML = faces('energy', Math.round(d.energy_avg), 26);
            enRow.querySelector('.pl-row').insertBefore(ei, enRow.querySelector('.pl-row').firstChild);
        }
    }
    f.appendChild(moodRow);
    f.appendChild(enRow);
    return f;
}

/* ------------------------------------------------------------------ */
/* Meteo (Open-Meteo, dati CC BY 4.0)                                  */
/* ------------------------------------------------------------------ */
/* `manuale` dice che il luogo l'ha scelto l'utente dal popup, non il
   telefono. DECISIONE (la stessa è scritta anche nell'endpoint `preview` di
   meteo_api.php): quel luogo NON si salva da nessuna parte. Vive nella
   pagina aperta e muore ricaricandola, o spegnendo e riaccendendo il meteo.
   È controintuitivo — di solito quello che si sceglie resta — ma è quello
   che serve: cambiare posto qui vuol dire sbirciare che tempo fa dove si
   andrà domani, non trasferirsi. In archivio `weather_lat/lon/place`
   continuano a dire dove si è davvero. */
var WX = { on: false, giorni: {}, adesso: null, place: null, lat: null, lon: null, fonte: null, fonte_url: null, nota: null, manuale: false };

var WX_ICONE = {
    'sole':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></svg>',
    'sole-nubi':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="8.5" cy="8.5" r="3"/><path d="M8.5 3.5v1.4M3.5 8.5h1.4M5 5l1 1"/><path d="M12 19h6a2.6 2.6 0 100-5.2 3.4 3.4 0 00-6.5-1A2.4 2.4 0 0012 19z"/></svg>',
    'nubi':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 18h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 18z"/></svg>',
    'nebbia':          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h16M6 14h12M4 18h16"/></svg>',
    'pioggia-leggera': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 15h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 15z"/><path d="M10 18.5v1.5M14 18.5v1.5"/></svg>',
    'pioggia':         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 14h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 14z"/><path d="M9 17v3M12.5 17v3M16 17v3"/></svg>',
    'pioggia-forte':   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M8 16v4.5M12 16v4.5M16 16v4.5"/></svg>',
    'neve':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M9.5 17.5h.01M13 19h.01M16 17.5h.01"/></svg>',
    'temporale':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M13 15l-3 4h3l-1 3"/></svg>',
    /* Le due notturne. Non arrivano mai dal server — il servizio manda solo
       'sole' e 'sole-nubi' — ma ci arriva sopra `wxIconaNome()` quando l'ora
       di cui si sta parlando cade fra il tramonto e l'alba. Sono qui e non in
       WXA_SVG perché quelle sono le sagome della scena della barra, disegnate
       piene: qui serve lo stesso tratto delle altre icone, se no la luna
       stonerebbe accanto a una nuvola. */
    'luna':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.6A8.6 8.6 0 019.4 4a7.6 7.6 0 1010.6 10.6z"/></svg>',
    'luna-nubi':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 9.4A5.1 5.1 0 016.2 3a4.5 4.5 0 106.4 6.4z"/><path d="M12 19h6a2.6 2.6 0 100-5.2 3.4 3.4 0 00-6.5-1A2.4 2.4 0 0012 19z"/></svg>'
};
function wxClasse(icona) {
    if (icona === 'sole' || icona === 'sole-nubi') return ' pl-wx-sun';
    /* la notte ha un colore suo: il giallo del sole su una luna sembra un
       errore di stampa, e il grigio delle nuvole non distingue più niente */
    if (icona === 'luna' || icona === 'luna-nubi') return ' pl-wx-moon';
    if (icona === 'neve') return ' pl-wx-snow';
    if (icona === 'temporale') return ' pl-wx-storm';
    if (icona.indexOf('pioggia') === 0) return ' pl-wx-rain';
    return '';
}

/* Fra il tramonto e l'alba il sole non c'è: disegnarlo lo stesso faceva
   sembrare pieno giorno un cielo che a quell'ora è soltanto sereno e buio.
   Cambiano solo le due icone che hanno il sole dentro — la pioggia delle
   undici di sera resta pioggia come quella di mezzogiorno.

   `minuti` è l'ora di cui si sta parlando, contata dalla mezzanotte. Quando
   manca vuol dire "il riassunto della giornata": su oggi vale l'ora corrente,
   come già fa la scena della barra in alto, mentre sugli altri giorni si
   resta di giorno, perché il riassunto di un giorno intero non ha un'ora sua
   e mostrarlo con la luna direbbe una cosa falsa.

   Alba e tramonto si prendono dal giorno *di cui si parla* e non da oggi: a
   dicembre il buio arriva due ore prima che a giugno, e una soglia fissa
   avrebbe sbagliato metà anno. */
function wxIconaNome(nome, date, minuti) {
    if (nome !== 'sole' && nome !== 'sole-nubi') return nome;
    if (minuti === null || minuti === undefined) {
        if (date !== S.today) return nome;
        var adesso = new Date();
        minuti = adesso.getHours() * 60 + adesso.getMinutes();
    }
    var g = WX.giorni[date];
    /* se il servizio non manda alba e tramonto restano le sei e le venti:
       sbagliano di poco e mai di molto, ed è meglio di non decidere */
    var alba = (g && g.alba) ? toMin(g.alba) : 6 * 60;
    var tramonto = (g && g.tramonto) ? toMin(g.tramonto) : 20 * 60;
    if (minuti >= alba && minuti < tramonto) return nome;
    return nome === 'sole' ? 'luna' : 'luna-nubi';
}
function wxIcona(nome, date, minuti) {
    var n = wxIconaNome(nome, date, minuti);
    return WX_ICONE[n] || WX_ICONE.nubi;
}

function wxCall(action, data) {
    var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } };
    if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    return fetch('meteo_api.php?ajax=' + action, opt)
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false }; });
}

function wxApply(r) {
    if (!r || !r.ok) return;
    WX.on = !!r.enabled;
    WX.giorni = r.giorni || {};
    WX.adesso = r.adesso || null;
    /* La posizione arriva quando si accende il meteo; se un aggiornamento
       successivo non la ripete, quella di prima resta buona invece di
       sparire dal popup. */
    /* Con il luogo scelto a mano il nome va scritto anche quando è vuoto: se
       di quel punto non si sa il nome, tenere quello di prima direbbe che la
       previsione è di una città in cui non si sta guardando. */
    if (r.manuale) WX.place = r.place || null;
    else if (r.place) WX.place = r.place;
    if (r.lat !== undefined && r.lat !== null) { WX.lat = r.lat; WX.lon = r.lon; }
    WX.manuale = !!r.manuale;
    WX.fonte = r.fonte || null;
    WX.fonte_url = r.fonte_url || null;
    if (r.nota_affidabilita) WX.nota = r.nota_affidabilita;
    $('plWeatherBtn').classList.toggle('pl-weather-on', WX.on);
    /* scena, tag del giorno e tag nella colonna degli orari: renderDays() da
       solo non li tocca tutti, e appena acceso il meteo i gradi orari
       comparivano solo al ridisegno dopo */
    wxRedraw();
}

/* Se il luogo è stato scelto a mano, ricaricare vuol dire ricaricare
   *quello*: chiedere `status` riporterebbe la posizione rilevata e il posto
   scelto sparirebbe da sotto le mani senza che nessuno l'abbia chiesto. */
function wxLoad() {
    if (WX.manuale && WX.lat !== null && WX.lat !== undefined) {
        return wxCall('preview', { lat: WX.lat, lon: WX.lon, place: WX.place || '' }).then(wxApply);
    }
    return wxCall('status').then(wxApply);
}

/* Il tag da mettere in una cella: solo per i giorni che abbiamo. */
function wxTag(date) {
    if (!WX.on) return null;
    var g = WX.giorni[date];
    if (!g) return null;
    /* niente minuti: è il riassunto del giorno, e wxIconaNome() sa già che
       su oggi vuol dire "adesso" e sugli altri giorni vuol dire "di giorno" */
    var nome = wxIconaNome(g.icon, date, null);
    var b = el('button', 'pl-wx' + wxClasse(nome));
    b.type = 'button';
    b.innerHTML = WX_ICONE[nome] || WX_ICONE.nubi;
    var t = wxTagTesto(date, g);
    if (t) b.appendChild(el('span', null, t));
    b.setAttribute('aria-label', g.label + (t ? ' · ' + t : ''));
    b.addEventListener('click', function (e) { e.stopPropagation(); wxPopup(date); });
    return b;
}

/* I gradi accanto all'icona. Guardando un giorno solo c'è posto per dire
   tutto: su oggi prima quanto fa adesso e poi la forbice della giornata, sugli
   altri giorni la forbice e basta. Con più giorni davanti i tag stanno in fila
   e resta spazio per un numero solo, che è la massima. */
function wxTagTesto(date, g) {
    var pezzi = [];
    var uno = visibleDays() === 1 && S.view !== 'month';
    if (uno && date === S.today && WX.adesso
        && WX.adesso.temp !== null && WX.adesso.temp !== undefined) {
        pezzi.push(WX.adesso.temp + '°');
    }
    var min = g.tmin, max = g.tmax;
    if (uno && min !== null && min !== undefined && max !== null && max !== undefined) {
        pezzi.push(min + '/' + max + '°');
    } else if (max !== null && max !== undefined) {
        pezzi.push(max + '°');
    }
    return pezzi.join(' · ');
}

/* L'indice UV da solo non dice niente: 7 è tanto o poco? */
function wxUvTesto(uv) {
    if (uv === null || uv === undefined) return null;
    if (uv < 3) return 'basso';
    if (uv < 6) return 'moderato';
    if (uv < 8) return 'alto';
    if (uv < 11) return 'molto alto';
    return 'estremo';
}
/* minuti → "6 h 20 min": più leggibile di 380 */
function wxDurata(min) {
    if (min === null || min === undefined) return null;
    var h = Math.floor(min / 60), m = min % 60;
    if (!h) return m + ' min';
    return h + ' h' + (m ? ' ' + m + ' min' : '');
}

/* La striscia ora per ora. Per oggi parte dall'ora corrente: le ore già
   passate occuperebbero il posto migliore per niente. */
/* ------------------------------------------------------------------ */
/* Il grafico ora per ora                                              */
/* ------------------------------------------------------------------ */
/* Una striscia di caselle diceva i numeri ma non l'andamento: per capire se
   la giornata si sta scaldando o raffreddando bisognava leggerli tutti e
   confrontarli a mente. La curva lo dice a colpo d'occhio, e i numeri
   restano lì sotto per chi li vuole.

   Ci sono tutte le ventiquattro ore, non solo quelle che restano: si scorre
   in orizzontale, e aprendo il popup su oggi la vista si porta da sé
   sull'ora corrente. */
var WXC = { w: 54, h: 84, pad: 13 };

function wxGrafico(g, date) {
    if (!g.ore || !g.ore.length) return null;
    var ore = g.ore, W = WXC.w, H = WXC.h;
    var largh = ore.length * W;
    var oraBase = parseInt(ore[0].h, 10) || 0;
    var adesso = date === S.today ? new Date().getHours() : -1;

    /* la scala verticale si adatta alla giornata: con l'escursione di un
       giorno d'agosto schiacciata su un asse fisso 0–40 la curva sarebbe
       piatta e non racconterebbe niente */
    var temp = ore.map(function (o) { return o.t; }).filter(function (t) { return t !== null && t !== undefined; });
    var tMin = Math.min.apply(null, temp), tMax = Math.max.apply(null, temp);
    if (!temp.length) { tMin = 0; tMax = 1; }
    if (tMax - tMin < 3) { tMax = tMin + 3; }   // giornate senza sbalzi: un minimo di respiro

    function xDi(i) { return i * W + W / 2; }
    function yDi(t) { return H - WXC.pad - ((t - tMin) / (tMax - tMin)) * (H - WXC.pad * 2); }

    /* `pl-wxh` resta il nome di fuori: è quello che lo sfoglia-giorni del
       popup riconosce per non rubare lo scorrimento orizzontale a questo. */
    var box = el('div', 'pl-wxh pl-wxc');
    var dentro = el('div', 'pl-wxc-in');
    dentro.style.width = largh + 'px';

    /* Dove cade sull'asse un certo orario. Vale per alba, tramonto e per la
       riga di adesso: tutte e tre stanno dove stanno davvero, non sull'ora
       più vicina. */
    function xDiOra(hhmm) {
        if (!hhmm) return null;
        return (toMin(hhmm) / 60 - oraBase) * W + W / 2;
    }

    /* --- riga di sopra: le ore --- */
    var su = el('div', 'pl-wxc-riga');
    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell' + (parseInt(o.h, 10) === adesso ? ' pl-ora' : ''));
        c.style.width = W + 'px';
        /* l'ora e basta, anche sulla colonna di adesso: a dire dove siamo
           c'è già la riga verticale, e la parola al posto del numero toglieva
           l'unica cosa che quella colonna doveva dire */
        c.appendChild(el('div', 'pl-wxc-h', parseInt(o.h, 10) + ''));
        su.appendChild(c);
    });
    dentro.appendChild(su);

    /* --- riga delle icone, con la fascia del giorno e della notte ---
       La fascia era il fondo delle icone stesse, quindi cambiava colore al
       bordo della casella: con il tramonto alle 20:45 il blu cominciava alle
       20:30, mezz'ora prima del buio. Ora è una sfumatura a gradini netti
       messa sotto tutta la riga, e i due gradini cadono esattamente dove
       cadono le righe tratteggiate dell'alba e del tramonto. */
    var righeIc = el('div', 'pl-wxc-riga pl-wxc-icone');
    var xAlba = xDiOra(g.alba), xTram = xDiOra(g.tramonto);
    var GIORNO = '#F8EFD4', NOTTE = '#E3E6F1';
    var dA = (xAlba === null) ? 0 : Math.max(0, Math.min(largh, xAlba));
    var dT = (xTram === null) ? largh : Math.max(0, Math.min(largh, xTram));
    if (dT < dA) dT = dA;
    var tappe = [];
    function tratto(da, a, colore) {
        if (a <= da) return;
        tappe.push(colore + ' ' + da.toFixed(1) + 'px', colore + ' ' + a.toFixed(1) + 'px');
    }
    tratto(0, dA, NOTTE);
    tratto(dA, dT, GIORNO);
    tratto(dT, largh, NOTTE);
    if (tappe.length) righeIc.style.background = 'linear-gradient(to right, ' + tappe.join(', ') + ')';

    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell');
        c.style.width = W + 'px';
        /* qui l'ora c'è, ed è quella della casella: le colonne dopo il
           tramonto passano alla luna una per una, come nella realtà */
        var iNome = wxIconaNome(o.i, date, parseInt(o.h, 10) * 60);
        var ic = el('div', 'pl-wxc-ic' + wxClasse(iNome));
        ic.innerHTML = WX_ICONE[iNome] || WX_ICONE.nubi;
        c.appendChild(ic);
        righeIc.appendChild(c);
    });
    dentro.appendChild(righeIc);

    /* --- la curva --- */
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'pl-wxc-svg');
    svg.setAttribute('width', largh);
    svg.setAttribute('height', H);
    svg.setAttribute('viewBox', '0 0 ' + largh + ' ' + H);

    /* Le etichette di alba e tramonto stanno in un contenitore insieme alla
       curva, non nel grafico intero: appese in cima a tutto finirebbero
       sopra i nomi delle ore. */
    var area = el('div', 'pl-wxc-area');

    function tag(nome, attrs) {
        var n = document.createElementNS('http://www.w3.org/2000/svg', nome);
        Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }

    var punti = [];
    ore.forEach(function (o, i) {
        svg.appendChild(tag('line', { x1: xDi(i), y1: 0, x2: xDi(i), y2: H, class: 'pl-wxc-guida' }));
        if (o.t === null || o.t === undefined) return;
        punti.push({ x: xDi(i), y: yDi(o.t), i: i });
    });

    /* Alba e tramonto: una riga tratteggiata dove cadono davvero, non
       incollata all'ora più vicina — un tramonto alle 20:41 non è alle 21. */
    [['alba', g.alba, 'Alba'], ['tramonto', g.tramonto, 'Tramonto']].forEach(function (v) {
        if (!v[1]) return;
        var x = (toMin(v[1]) / 60 - oraBase) * W + W / 2;
        if (x < 0 || x > largh) return;
        svg.appendChild(tag('line', { x1: x, y1: 0, x2: x, y2: H, class: 'pl-wxc-sole pl-wxc-' + v[0] }));
        var et = el('div', 'pl-wxc-et pl-wxc-et-' + v[0], (v[0] === 'alba' ? '↑ ' : '↓ ') + v[1]);
        et.style.left = x + 'px';
        area.appendChild(et);
    });

    /* La riga di adesso, solo su oggi. Le colonne dicono le ore tonde, ma
       quello che si cerca guardando il grafico è dove si è in questo momento:
       senza una riga bisognava contare a mente fra le nove e le dieci. Sta
       dove sta davvero, come alba e tramonto. */
    if (date === S.today) {
        var oraOra = new Date();
        var xOra = (oraOra.getHours() + oraOra.getMinutes() / 60 - oraBase) * W + W / 2;
        if (xOra >= 0 && xOra <= largh) {
            svg.appendChild(tag('line', { x1: xOra, y1: 0, x2: xOra, y2: H, class: 'pl-wxc-adesso' }));
            var eo = el('div', 'pl-wxc-et pl-wxc-et-adesso', 'adesso');
            eo.style.left = xOra + 'px';
            area.appendChild(eo);
        }
    }

    if (punti.length > 1) {
        /* curva morbida: ogni tratto è una cubica con le maniglie a metà
           strada, che è il modo più semplice per non avere spigoli sui punti */
        var d = 'M' + punti[0].x + ',' + punti[0].y.toFixed(1);
        for (var k = 1; k < punti.length; k++) {
            var a = punti[k - 1], b = punti[k], mx = (a.x + b.x) / 2;
            d += ' C' + mx + ',' + a.y.toFixed(1) + ' ' + mx + ',' + b.y.toFixed(1)
               + ' ' + b.x + ',' + b.y.toFixed(1);
        }
        svg.appendChild(tag('path', { d: d, class: 'pl-wxc-curva' }));
    }
    punti.forEach(function (p) {
        svg.appendChild(tag('circle', {
            cx: p.x, cy: p.y.toFixed(1), r: ore[p.i] && parseInt(ore[p.i].h, 10) === adesso ? 5 : 3.6,
            class: 'pl-wxc-punto'
        }));
    });
    area.appendChild(svg);
    dentro.appendChild(area);

    /* --- riga di sotto: gradi e pioggia --- */
    var giu = el('div', 'pl-wxc-riga');
    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell' + (parseInt(o.h, 10) === adesso ? ' pl-ora' : ''));
        c.style.width = W + 'px';
        c.appendChild(el('div', 'pl-wxc-t', (o.t !== null && o.t !== undefined) ? o.t + '°' : '—'));
        /* la probabilità si scrive solo quando c'è: una colonna di zeri
           riempirebbe il grafico di rumore */
        c.appendChild(el('div', 'pl-wxc-p', o.p ? o.p + '%' : ''));
        c.title = o.l + (o.ap !== null && o.ap !== undefined ? ' · percepiti ' + o.ap + '°' : '')
                + (o.v !== null && o.v !== undefined ? ' · vento ' + o.v + ' km/h' : '')
                + (o.u !== null && o.u !== undefined ? ' · umidità ' + o.u + '%' : '');
        giu.appendChild(c);
    });
    dentro.appendChild(giu);

    box.appendChild(dentro);
    /* su oggi si parte da adesso, ma indietro si può tornare: le ore passate
       ci sono tutte, semplicemente non sono quelle che interessano per prime */
    if (adesso >= 0) {
        setTimeout(function () {
            box.scrollLeft = Math.max(0, (adesso - oraBase) * W - W);
        }, 0);
    }
    return box;
}

/* Su quale posto è calcolata la previsione. Il nome se il server è riuscito
   a trovarlo, altrimenti le coordinate: sono meno belle da leggere ma sono
   pur sempre una risposta, e "non lo so" non lo è. */
function wxDove() {
    if (WX.place) return WX.place;
    if (WX.lat !== null && WX.lat !== undefined && WX.lon !== null && WX.lon !== undefined) {
        return WX.lat.toFixed(2).replace('.', ',') + ' / ' + WX.lon.toFixed(2).replace('.', ',');
    }
    return null;
}

/* Un giorno avanti o indietro dentro il popup del meteo: si riscrive il
   contenuto di quello aperto invece di impilarne un secondo sopra. */
function wxVaiA(date, passo) {
    var nuovo = addDays(date, passo);
    if (!WX.giorni[nuovo]) return;
    wxPopup(nuovo);
}

/* ------------------------------------------------------------------ */
/* Cambio del luogo dal popup                                          */
/* ------------------------------------------------------------------ */
/* Il pannellino di ricerca vive dentro la testa del popup, non in un foglio
   suo: aprire un secondo foglio sopra il primo avrebbe voluto dire perdere
   di vista la previsione che si sta guardando, che è proprio la cosa che si
   vuole confrontare con quella dell'altro posto. */
function wxCambiaLuogo(date, testa) {
    var aperto = testa.querySelector('.pl-wx-cerca');
    /* secondo tocco sulla posizione: si richiude, come ci si aspetta da una
       cosa che si è aperta toccandola */
    if (aperto) { aperto.parentNode.removeChild(aperto); return; }

    var box = el('div', 'pl-wx-cerca');
    var inp = el('input', 'pl-in');
    inp.type = 'search';
    inp.placeholder = 'Città o paese';
    inp.maxLength = 60;
    box.appendChild(inp);

    var res = el('div', 'pl-wx-cerca-res');
    box.appendChild(res);

    /* La via del ritorno c'è solo quando serve. Non si tiene in memoria la
       posizione vera: la sa il server, che non l'ha mai cambiata, e
       richiederla è più onesto che fidarsi di una copia. */
    if (WX.manuale) {
        var back = el('button', 'pl-wx-cerca-back', 'Torna alla posizione rilevata');
        back.type = 'button';
        back.addEventListener('click', function () { wxPosizioneVera(date); });
        box.appendChild(back);
    }

    testa.appendChild(box);
    inp.focus();

    inp.addEventListener('input', function () {
        var q = inp.value.trim();
        if (q.length < 3) { res.innerHTML = ''; return; }
        /* si aspetta che smetta di scrivere: una chiamata per lettera
           battuta sarebbe otto chiamate per «milano» */
        debounceSave('wxluogo', 350, function () {
            wxCall('place_search', { q: q }).then(function (r) {
                res.innerHTML = '';
                if (!r || !r.ok || !r.luoghi || !r.luoghi.length) {
                    res.appendChild(el('div', 'pl-row-sub', 'Nessun posto con questo nome.'));
                    return;
                }
                r.luoghi.forEach(function (p) {
                    var b = el('button', 'pl-wx-cerca-row');
                    b.type = 'button';
                    b.appendChild(el('span', 'pl-row-lab', p.nome));
                    if (p.dove) b.appendChild(el('span', 'pl-row-sub', p.dove));
                    b.addEventListener('click', function () {
                        wxUsaLuogo(p.lat, p.lon, p.nome, date);
                    });
                    res.appendChild(b);
                });
            });
        });
    });
}

/* Il posto scelto vale per la pagina aperta e basta: niente scrittura in
   archivio, né qui né nell'endpoint. Vedi il commento su WX.manuale. */
function wxUsaLuogo(lat, lon, nome, date) {
    wxCall('preview', { lat: lat, lon: lon, place: nome || '' }).then(function (r) {
        if (!r || !r.ok) { toast('Previsione non disponibile per quel posto.'); return; }
        wxApply(r);
        /* si riapre sullo stesso giorno se in quel posto c'è ancora: la
           finestra dei giorni è la stessa dappertutto, ma su un giorno vuoto
           il popup non avrebbe niente da dire */
        wxPopup(WX.giorni[date] ? date : S.today);
        toast('Previsione per ' + (r.place || 'il posto scelto') + '.');
    });
}

function wxPosizioneVera(date) {
    wxCall('status').then(function (r) {
        if (!r || !r.ok) return;
        wxApply(r);
        wxPopup(WX.giorni[date] ? date : S.today);
        toast('Torno alla posizione rilevata.');
    });
}

function wxPopup(date) {
    var g = WX.giorni[date];
    if (!g) return;
    var d = parseIso(date);
    openSheet('Meteo', function (root) {
        /* Tutto dentro un contenitore nostro invece che direttamente nel
           corpo del popup: gli ascoltatori dello scorrimento se ne vanno con
           lui quando il contenuto si riscrive, e non si accumulano a ogni
           giorno sfogliato. */
        var body = el('div', 'pl-wx-sheet');
        root.appendChild(body);

        /* Giorno e posizione restano in vista mentre si scorre: sono la
           didascalia di tutto il resto — senza sapere di che giorno e di che
           posto si parla, i numeri qui sotto non vogliono dire niente — e
           scorrendo fino in fondo sparivano proprio mentre servivano.
           Si appiccica al riquadro che scorre, che è il corpo del foglio:
           qui non c'è nessuna griglia che possa trattenerlo dentro una cella
           come succedeva alla colonna delle ore. */
        var testa = el('div', 'pl-wx-testa');
        body.appendChild(testa);

        /* Che giorno è e su quale posto è calcolata: senza, la previsione è
           un numero che non si sa a cosa si riferisca. Le frecce servono su
           computer, dove lo scorrimento con il dito non c'è. */
        var nav = el('div', 'pl-wx-nav');
        var prima = el('button', 'pl-wx-nav-b');
        prima.type = 'button';
        prima.setAttribute('aria-label', 'Giorno precedente');
        prima.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
        var dopo = el('button', 'pl-wx-nav-b');
        dopo.type = 'button';
        dopo.setAttribute('aria-label', 'Giorno successivo');
        dopo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>';
        prima.disabled = !WX.giorni[addDays(date, -1)];
        dopo.disabled  = !WX.giorni[addDays(date, 1)];
        prima.addEventListener('click', function () { wxVaiA(date, -1); });
        dopo.addEventListener('click', function () { wxVaiA(date, 1); });
        nav.appendChild(prima);
        nav.appendChild(el('div', 'pl-label',
            GIORNI[dowIndex(date)] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()));
        nav.appendChild(dopo);
        testa.appendChild(nav);

        /* La posizione su una riga sua e non appesa alla data: infilata lì in
           mezzo alle frecce spariva, ed era proprio la cosa che serviva
           sapere per fidarsi del numero dei gradi.
           Ed è un tasto: toccandola si cerca un altro posto. Il gesto è
           quello che viene da sé — si tocca quello che si vuole cambiare —
           e non serve un tastino in più in una testa già piena. */
        var dove = wxDove();
        var bDove = el('button', 'pl-wx-dove' + (WX.manuale ? ' pl-wx-dove-man' : ''));
        bDove.type = 'button';
        bDove.setAttribute('aria-label', 'Cambia il luogo della previsione');
        bDove.appendChild(el('span', null, dove ? 'previsione per ' + dove
                                                : 'posizione non disponibile'));
        bDove.innerHTML += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/></svg>';
        bDove.addEventListener('click', function () { wxCambiaLuogo(date, testa); });
        testa.appendChild(bDove);

        var big = el('div', 'pl-row');
        big.style.margin = '10px 0 14px';
        var ico = el('div');
        ico.style.cssText = 'width:44px;height:44px;color:var(--ink-2);flex-shrink:0';
        ico.innerHTML = wxIcona(g.icon, date, null);
        big.appendChild(ico);
        var txt = el('div');
        txt.appendChild(el('div', 'pl-sheet-title pl-serif', g.label));
        if (g.tmax !== null) {
            txt.appendChild(el('div', 'pl-row-sub',
                'massima ' + g.tmax + '°' + (g.tmin !== null ? ', minima ' + g.tmin + '°' : '')));
        }
        if (g.percepita_max !== null && g.percepita_max !== undefined) {
            txt.appendChild(el('div', 'pl-row-sub',
                'percepiti ' + g.percepita_max + '°'
                + (g.percepita_min !== null && g.percepita_min !== undefined ? ' / ' + g.percepita_min + '°' : '')));
        }
        big.appendChild(txt);
        body.appendChild(big);

        /* Adesso: ha senso solo sul giorno di oggi, altrove sarebbe un dato
           che parla di un altro giorno. */
        if (date === S.today && WX.adesso && WX.adesso.temp !== null) {
            /* la riga di adesso ha un'ora precisa: quella del server se c'è,
               se no quella dell'orologio. Alle nove di sera qui ci va la luna. */
            var oMin = WX.adesso.ora ? toMin(WX.adesso.ora)
                                     : (new Date().getHours() * 60 + new Date().getMinutes());
            var oNome = wxIconaNome(WX.adesso.icon, date, oMin);
            var ora = el('div', 'pl-wx-now' + wxClasse(oNome));
            var oIco = el('span', 'pl-wx-now-ic');
            oIco.innerHTML = WX_ICONE[oNome] || WX_ICONE.nubi;
            ora.appendChild(oIco);
            var pezzi = ['adesso ' + WX.adesso.temp + '°'];
            if (WX.adesso.percepita !== null && WX.adesso.percepita !== undefined) pezzi.push('percepiti ' + WX.adesso.percepita + '°');
            if (WX.adesso.vento !== null && WX.adesso.vento !== undefined) pezzi.push(WX.adesso.vento + ' km/h');
            if (WX.adesso.umidita !== null && WX.adesso.umidita !== undefined) pezzi.push('umidità ' + WX.adesso.umidita + '%');
            ora.appendChild(el('span', null, WX.adesso.label + ' · ' + pezzi.join(' · ')));
            body.appendChild(ora);
        }

        var strip = wxGrafico(g, date);
        if (strip) {
            body.appendChild(el('div', 'pl-label', 'Ora per ora'));
            body.appendChild(strip);
        }

        function riga(k, v) {
            if (v === null || v === undefined || v === '') return;
            var r = el('div', 'pl-row-sb');
            r.appendChild(el('span', 'pl-row-lab', k));
            r.appendChild(el('span', 'pl-row-sub', v));
            body.appendChild(r);
        }
        riga('Probabilità di pioggia', (g.pioggia !== null && g.pioggia !== undefined) ? g.pioggia + '%' : null);
        riga('Pioggia prevista', (g.pioggia_mm !== null && g.pioggia_mm !== undefined && g.pioggia_mm > 0)
            ? g.pioggia_mm + ' mm' + (g.pioggia_ore ? ' in ' + g.pioggia_ore + (g.pioggia_ore === 1 ? ' ora' : ' ore') : '') : null);
        riga('Vento massimo', (g.vento !== null && g.vento !== undefined)
            ? g.vento + ' km/h' + (g.vento_da ? ' da ' + g.vento_da : '') : null);
        riga('Raffiche fino a', (g.raffiche !== null && g.raffiche !== undefined) ? g.raffiche + ' km/h' : null);
        riga('Indice UV', (g.uv !== null && g.uv !== undefined) ? g.uv + ' (' + wxUvTesto(g.uv) + ')' : null);
        riga('Alba e tramonto', g.alba && g.tramonto ? g.alba + ' — ' + g.tramonto : null);
        riga('Ore di sole', wxDurata(g.sole_min));
        riga('Attendibilità stimata', g.affidabilita + '%');

        if (WX.nota) {
            var nota = el('div', 'pl-row-sub', WX.nota);
            nota.style.marginTop = '12px';
            body.appendChild(nota);
        }

        /* attribuzione: i dati sono CC BY 4.0, citare la fonte è dovuto */
        var fonte = el('div', 'pl-row-sub');
        fonte.style.marginTop = '14px';
        fonte.appendChild(document.createTextNode('Dati '));
        var a = el('a', null, WX.fonte || 'Open-Meteo');
        a.href = WX.fonte_url || 'https://open-meteo.com';
        a.target = '_blank';
        a.rel = 'noopener';
        a.style.color = 'var(--accent-ink)';
        fonte.appendChild(a);
        fonte.appendChild(document.createTextNode(' · licenza CC BY 4.0 · previsioni fino a 5 giorni'));
        body.appendChild(fonte);

        var off = el('button', 'pl-btn', 'Disattiva il meteo');
        off.type = 'button';
        off.style.marginTop = '18px';
        off.addEventListener('click', function () {
            /* Si spegne subito e si avvisa il server dopo. Aspettando la
               risposta, tag e animazione restavano in vista per tutto il
               tempo del giro in rete: spegnere una cosa deve sembrare
               immediato, perché la decisione è già stata presa. */
            /* si torna anche alla posizione vera: riaccendendolo dopo, il
               posto sbirciato ieri sera non deve tornare da solo */
            WX.on = false; WX.giorni = {}; WX.adesso = null; WX.manuale = false;
            $('plWeatherBtn').classList.remove('pl-weather-on');
            closeOv('plSheetOv');
            wxRedraw();   // tornano l'albero e la bestia del mese
            wxCall('disable', {}).then(function () { toast('Meteo disattivato.'); });
        });
        body.appendChild(off);

        /* Scorrimento orizzontale per sfogliare i giorni. La striscia ora per
           ora scorre già per conto suo in orizzontale: se il dito parte da lì
           il gesto è suo, e rubarglielo renderebbe la striscia inservibile. */
        var sx = 0, sy = 0, vivo = false;
        body.addEventListener('pointerdown', function (e) {
            /* Dalla testa il gesto non parte: lì dentro ci sono il campo di
               ricerca del luogo e i suoi risultati, e trascinare per scegliere
               una parola cambiava giorno. */
            vivo = !(e.target.closest && e.target.closest('.pl-wxh, .pl-wx-testa'));
            sx = e.clientX; sy = e.clientY;
        });
        body.addEventListener('pointerup', function (e) {
            if (!vivo) return;
            vivo = false;
            var dx = e.clientX - sx, dy = e.clientY - sy;
            /* più orizzontale che verticale, se no si cambierebbe giorno
               ogni volta che si scorre il popup per leggerlo fino in fondo */
            /* soglia bassa: sfogliare i giorni è la cosa che si fa più
               spesso qui dentro, e chiederla con un gesto ampio la rendeva
               faticosa */
            if (Math.abs(dx) < 26 || Math.abs(dx) < Math.abs(dy)) return;
            wxVaiA(date, dx < 0 ? 1 : -1);
        });
    });
}

$('plWeatherBtn').addEventListener('click', function () {
    if (WX.on) {
        /* il tasto accende e spegne: le informazioni si aprono dai tag */
        WX.on = false; WX.giorni = {}; WX.adesso = null; WX.manuale = false;
        $('plWeatherBtn').classList.remove('pl-weather-on');
        wxRedraw();
        wxCall('disable', {}).then(function () { toast('Meteo disattivato.'); });
        return;
    }
    if (!navigator.geolocation) { wxChiediLuogo('Questo browser non sa dire la posizione.'); return; }
    toast('Chiedo la posizione…');
    navigator.geolocation.getCurrentPosition(function (pos) {
        wxCall('enable', {
            lat: pos.coords.latitude,
            lon: pos.coords.longitude
        }).then(function (r) {
            if (!r.ok) { toast('Meteo non attivato.'); return; }
            wxApply(r);
            toast('Meteo attivo' + (r.place ? ' per ' + r.place : '') + '.');
        });
    }, function () {
        /* Il permesso negato non è la fine del discorso: il posto lo si sa,
           basta poterlo scrivere. Prima qui c'era solo il dispiacere, e
           l'unico modo di avere le previsioni era andare a cambiare i
           permessi del browser — cioè, in pratica, rinunciare. */
        wxChiediLuogo('Non riesco a rilevare la posizione.');
    }, { timeout: 12000, maximumAge: 600000 });
});

/* Il luogo scritto a mano quando il telefono non lo dice. A differenza del
   cambio luogo dal popup, QUESTO si salva: non è una sbirciata a un'altra
   città, è la posizione che vale per questa pagina e per le prossime, e
   ritrovarsi il meteo spento a ogni ricarica sarebbe come non averlo. */
function wxChiediLuogo(perche) {
    /* Stesso foglio degli altri, costruito a mano perché è l'unico che nasce
       da un rifiuto e non da un tocco: non ha un posto suo in planner.php e
       non ne merita uno, visto che quasi sempre non serve. */
    var ov = el('div', 'pl-ov');
    ov.id = 'plWxLuogoOv';
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));
    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', 'Dove sei?'));
    var chiudi = el('button', 'pl-txt-btn', 'Chiudi');
    chiudi.type = 'button';
    chiudi.addEventListener('click', function () { ov.remove(); });
    head.appendChild(chiudi);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');
    body.appendChild(el('div', 'pl-row-sub', perche + ' Scrivi il posto e le previsioni partono lo stesso.'));

    var box = el('div', 'pl-wx-cerca');
    var inp = el('input', 'pl-in');
    inp.type = 'search';
    inp.placeholder = 'Città o paese';
    inp.maxLength = 60;
    box.appendChild(inp);
    var res = el('div', 'pl-wx-cerca-res');
    box.appendChild(res);
    body.appendChild(box);
    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    /* la classe che lo fa salire va messa dopo l'inserimento, se no il foglio
       comparirebbe già su e la salita non si vedrebbe */
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
    setTimeout(function () { inp.focus(); }, 260);

    inp.addEventListener('input', function () {
        var q = inp.value.trim();
        if (q.length < 3) { res.innerHTML = ''; return; }
        debounceSave('wxluogo', 350, function () {
            wxCall('place_search', { q: q }).then(function (r) {
                res.innerHTML = '';
                if (!r || !r.ok || !r.luoghi || !r.luoghi.length) {
                    res.appendChild(el('div', 'pl-row-sub', 'Nessun posto con questo nome.'));
                    return;
                }
                r.luoghi.forEach(function (p) {
                    var b = el('button', 'pl-wx-cerca-row');
                    b.type = 'button';
                    b.appendChild(el('span', 'pl-row-lab', p.nome));
                    if (p.dove) b.appendChild(el('span', 'pl-row-sub', p.dove));
                    b.addEventListener('click', function () {
                        /* `enable` e non `preview`: qui la posizione la si sta
                           dichiarando, non sbirciando */
                        wxCall('enable', { lat: p.lat, lon: p.lon }).then(function (rr) {
                            if (!rr || !rr.ok) { toast('Meteo non attivato.'); return; }
                            ov.remove();
                            wxApply(rr);
                            toast('Meteo attivo per ' + (rr.place || p.nome) + '.');
                        });
                    });
                    res.appendChild(b);
                });
            });
        });
    });
}

/* ------------------------------------------------------------------ */
/* Barra intelligente: da una frase a un evento                        */
/* ------------------------------------------------------------------ */
var SMART = null;
var RUBRICA = [];

/* La rubrica serve alla barra intelligente per riconoscere "con luchino".
   È un elenco corto, quindi lo teniamo in memoria invece di interrogare il
   server a ogni frase scritta. */
function loadRubrica() {
    return api('contacts').then(function (r) {
        RUBRICA = (r && r.ok) ? r.contacts : [];
    }).catch(function () { RUBRICA = []; });
}

function loadSmart() {
    return fetch('planner_smart.json', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { SMART = d; })
        .catch(function () { /* senza il file la barra resta muta */ });
}

/* La Pasqua dell'anno chiesto (algoritmo di Meeus): serve per Pasquetta,
   Carnevale e le altre feste che si spostano ogni anno. */
function pasquaDi(anno) {
    var a = anno % 19, b = Math.floor(anno / 100), c = anno % 100;
    var dd = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
    var g = Math.floor((b - f + 1) / 3), h = (19 * a + b - dd - g + 15) % 30;
    var i = Math.floor(c / 4), k = c % 4;
    var l = (32 + 2 * e + 2 * i - h - k) % 7;
    var m = Math.floor((a + 11 * h + 22 * l) / 451);
    var mese = Math.floor((h + l - 7 * m + 114) / 31);
    var giorno = ((h + l - 7 * m + 114) % 31) + 1;
    return anno + '-' + pad(mese) + '-' + pad(giorno);
}

/* Quante correzioni servono per passare da una parola all'altra: serve per
   perdonare gli errori di battitura ("agsoto" invece di "agosto"). */
function distanzaParole(a, b) {
    a = String(a); b = String(b);
    if (Math.abs(a.length - b.length) > 3) return 99;
    var prev = [], cur = [];
    for (var j2 = 0; j2 <= b.length; j2++) prev[j2] = j2;
    for (var i2 = 1; i2 <= a.length; i2++) {
        cur[0] = i2;
        for (var k2 = 1; k2 <= b.length; k2++) {
            var costo = a.charAt(i2 - 1) === b.charAt(k2 - 1) ? 0 : 1;
            cur[k2] = Math.min(cur[k2 - 1] + 1, prev[k2] + 1, prev[k2 - 1] + costo);
        }
        prev = cur.slice();
    }
    return prev[b.length];
}

/* Cerca nel testo una delle chiavi date, perdonando qualche lettera sbagliata.
   Ritorna { key, match } oppure null. Le chiavi più lunghe hanno la
   precedenza, così "san valentino" vince su "valentino". */
function trovaChiave(text, chiavi, tolleranza) {
    var ordinate = chiavi.slice().sort(function (x, y) { return y.length - x.length; });
    /* prima le corrispondenze esatte */
    for (var i = 0; i < ordinate.length; i++) {
        if (text.indexOf(' ' + ordinate[i] + ' ') !== -1) return { key: ordinate[i], match: ordinate[i] };
    }
    if (!tolleranza) return null;
    /* poi quelle con qualche svista, parola per parola */
    var parole = text.split(/\s+/).filter(Boolean);
    for (var p = 0; p < parole.length; p++) {
        if (parole[p].length < 4) continue;          // troppo corta: rischio di scambi
        for (var c = 0; c < ordinate.length; c++) {
            if (ordinate[c].indexOf(' ') !== -1) continue;   // solo parole singole
            var limite = ordinate[c].length <= 5 ? 1 : tolleranza;
            if (distanzaParole(parole[p], ordinate[c]) <= limite) {
                return { key: ordinate[c], match: parole[p] };
            }
        }
    }
    return null;
}

/* Legge la frase e ne ricava data, ora e titolo. Le parti riconosciute
   vengono tolte dal testo: quello che avanza è il titolo dell'evento. */
function smartParse(raw) {
    if (!SMART) return null;
    var text = ' ' + String(raw).toLowerCase().trim() + ' ';
    var used = [];
    var date = null, time = null, mealFound = false;
    /* segna se l'ora è stata scritta davvero, invece di essere dedotta */
    var timeExplicit = false;

    function eat(re) {
        var m = text.match(re);
        if (m) { used.push(m[0].trim()); text = text.replace(m[0], ' '); }
        return m;
    }

    /* --- ora: 12:30, 12.30, "alle 15", "ore 9" --- */
    var mTime = eat(/\s(?:alle|alla|ore|ora|verso)?\s*([01]?\d|2[0-3])[:.]([0-5]\d)\s/);
    if (mTime) {
        time = pad(parseInt(mTime[1], 10)) + ':' + mTime[2];
        timeExplicit = true;
    } else {
        var mHour = eat(/\s(?:alle|ore|ora|verso)\s+([01]?\d|2[0-3])\s/);
        if (!mHour) mHour = eat(/\s([01]?\d|2[0-3])\s*$/);
        if (mHour) { time = pad(parseInt(mHour[1], 10)) + ':00'; timeExplicit = true; }
    }

    /* --- feste a data fissa: ferragosto, natale, capodanno… --- */
    var tol = SMART.tolleranza_errori_battitura || 0;
    if (!date && SMART.festivita) {
        var f = trovaChiave(text, Object.keys(SMART.festivita), tol);
        if (f) {
            var info = SMART.festivita[f.key];
            var y1 = parseIso(S.today).getFullYear();
            var cand1 = y1 + '-' + pad(info.mese) + '-' + pad(info.giorno);
            if (cand1 < S.today) cand1 = (y1 + 1) + '-' + pad(info.mese) + '-' + pad(info.giorno);
            date = cand1;
            used.push(f.key);
            text = text.replace(' ' + f.match + ' ', ' ');
        }
    }

    /* --- feste che si spostano: pasqua, pasquetta, carnevale --- */
    if (!date && SMART.festivita_mobili) {
        var fm = trovaChiave(text, Object.keys(SMART.festivita_mobili), tol);
        if (fm) {
            var y2 = parseIso(S.today).getFullYear();
            var cand2 = addDays(pasquaDi(y2), SMART.festivita_mobili[fm.key]);
            if (cand2 < S.today) cand2 = addDays(pasquaDi(y2 + 1), SMART.festivita_mobili[fm.key]);
            date = cand2;
            used.push(fm.key);
            text = text.replace(' ' + fm.match + ' ', ' ');
        }
    }

    /* --- data esplicita: 13/8, 13-08-2026 --- */
    var mNum = eat(/\s(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\s/);
    if (mNum) {
        var yy = mNum[3] ? parseInt(mNum[3], 10) : parseIso(S.today).getFullYear();
        if (yy < 100) yy += 2000;
        date = yy + '-' + pad(parseInt(mNum[2], 10)) + '-' + pad(parseInt(mNum[1], 10));
    }

    /* --- giorno e mese scritti: "13 agosto", anche con qualche svista --- */
    if (!date) {
        var mm2 = text.match(/\s(\d{1,2})\s+([a-zàèéìòù]{3,12})\s/);
        if (mm2) {
            var mese = trovaChiave(' ' + mm2[2] + ' ', Object.keys(SMART.mesi), tol);
            if (mese) {
                var y3 = parseIso(S.today).getFullYear();
                var cand3 = y3 + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(parseInt(mm2[1], 10));
                if (cand3 < S.today) cand3 = (y3 + 1) + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(parseInt(mm2[1], 10));
                date = cand3;
                used.push(mm2[1] + ' ' + mese.key);
                text = text.replace(mm2[0], ' ');
            }
        }
    }

    /* --- fasce del giorno: si guardano PRIMA di togliere le parole del
           giorno relativo. Era questo il difetto: "stasera" vale sia come
           giorno (oggi) sia come ora (20:00), ma veniva cancellata dal testo
           come giorno e poi non si trovava più come ora. Così "cena stasera"
           funzionava (l'ora arrivava da "cena") e "mangio bene stasera" no. --- */
    if (!time && SMART.fasce_orarie) {
        var fa = trovaChiave(text, Object.keys(SMART.fasce_orarie), 0);
        if (fa) time = SMART.fasce_orarie[fa.key];
    }
    /* I pasti danno un'ora solo se non è stata scritta: "domani pranzo alle
       18" deve finire alle 18, non alle 12:30. L'orario detto dall'utente
       vince sempre su quello dedotto da una parola. */
    if (SMART.pasti) {
        var pa = trovaChiave(text, Object.keys(SMART.pasti), tol);
        if (pa) {
            mealFound = true;                    // basta a non farlo diventare "tutto il giorno"
            if (!timeExplicit) time = SMART.pasti[pa.key];
        }
    }

    /* --- giorni relativi: oggi, domani, dopodomani --- */
    if (!date) {
        var rel = Object.keys(SMART.giorni_relativi).sort(function (a, b) { return b.length - a.length; });
        for (var i = 0; i < rel.length; i++) {
            if (text.indexOf(' ' + rel[i] + ' ') === -1) continue;
            date = addDays(S.today, SMART.giorni_relativi[rel[i]]);
            used.push(rel[i]);
            /* le parole che dicono anche l'ora restano nel titolo solo se non
               sono già state usate come tali */
            var conserva = (SMART.fasce_da_conservare || []).indexOf(rel[i]) !== -1;
            if (!conserva) text = text.replace(' ' + rel[i] + ' ', ' ');
            else text = text.replace(' ' + rel[i] + ' ', ' ');
            break;
        }
    }

    /* --- giorni della settimana: il prossimo che capita --- */
    if (!date) {
        var wdKeys = Object.keys(SMART.giorni_settimana);
        var wd = trovaChiave(text, wdKeys, tol);
        if (wd) {
            var target = SMART.giorni_settimana[wd.key];      // 1 = lunedì
            var oggiDow = dowIndex(S.today) + 1;
            var delta = (target - oggiDow + 7) % 7;
            if (delta === 0) delta = 7;                        // "martedì" detto di martedì = il prossimo
            var piu = false;
            (SMART.settimana_prossima || []).forEach(function (w) { if (text.indexOf(w) !== -1) piu = true; });
            if (piu) delta += 7;
            date = addDays(S.today, delta);
            used.push(wd.key);
            text = text.replace(' ' + wd.match + ' ', ' ');
        }
    }

    /* --- con chi: "con luchino" invita luchino, e il nome resta nel titolo
           perché l'evento si chiama davvero "pranzo con luchino" --- */
    var invitati = [];
    if (RUBRICA.length) {
        var tol2 = SMART.tolleranza_errori_battitura || 0;
        RUBRICA.forEach(function (c) {
            var nome = String(c.name || '').toLowerCase().trim();
            if (!nome) return;
            if (invitati.some(function (g) { return g.email === c.email; })) return;

            /* "con luchino" è il caso sicuro: la preposizione dice che si
               parla di una persona e non di una parola qualsiasi */
            if (text.indexOf(' con ' + nome + ' ') !== -1) {
                invitati.push({ email: c.email, name: c.name });
                return;
            }
            /* il nome da solo vale solo se è abbastanza lungo da non
               confondersi con una parola comune */
            if (nome.length >= 4) {
                var trovato = trovaChiave(text, [nome], tol2);
                if (trovato) invitati.push({ email: c.email, name: c.name });
            }
        });
    }

    /* --- quello che resta è il titolo --- */
    var parole = text.split(/\s+/).filter(Boolean).filter(function (w) {
        return (SMART.parole_da_togliere || []).indexOf(w) === -1;
    });
    var titolo = parole.join(' ').trim();
    if (titolo) titolo = titolo.charAt(0).toUpperCase() + titolo.slice(1);

    return {
        title: titolo,
        guests: invitati,
        date: date || S.focusDate,
        time: time,
        /* senza un'ora indicata l'evento dura tutto il giorno, a meno che la
           frase parlasse di un pasto: "pranzo" senza ora va a mezzogiorno */
        allDay: !time && SMART.tutto_il_giorno_se_manca_ora !== false && !mealFound,
        dateGuessed: !date,
        timeGuessed: !time,
        used: used
    };
}

function smartCreate(input) {
    var raw = (input.value || '').trim();
    if (!raw) return;
    var p = smartParse(raw);
    if (!p || !p.title) { toast('Non ho capito cosa scrivere come titolo.'); return; }

    var dur = (SMART && SMART.durata_predefinita_minuti) || 60;
    var start = p.allDay ? '00:00' : (p.time || toHM(firstFreeMinute(dur)));
    var end = p.allDay ? '23:59' : toHM(Math.min(1439, toMin(start) + dur));

    api('event_save', {
        title: p.title, date: p.date, start: start, end: end,
        all_day: p.allDay ? 1 : 0,
        guests: p.guests || [],
        type: 'event', notify: p.allDay ? 'none' : 'h1'
    }).then(function (r) {
        if (!r.ok) { toast(r.message || 'Non è stato possibile creare l\'evento.'); return; }
        indexEvents([r.event]);
        input.value = '';
        input.blur();
        if (p.date !== S.focusDate) goToDate(p.date, true); else renderDays();
        haptic(12);
        smartReport(p, start, end, r.event);
        scheduleSync();
    });
}

/* Dettatura della barra: si parla, e dopo un paio di secondi di silenzio
   l'evento viene creato da sé. Se non si dice niente, si spegne e basta. */
function setupSmartMic(btn, input) {
    var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Rec) return;                 // dove non c'è, il tasto resta nascosto
    btn.hidden = false;

    var rec = null, running = false, silence = null, spoken = false;

    function stop(andCreate) {
        running = false;
        btn.classList.remove('pl-rec');
        if (silence) { clearTimeout(silence); silence = null; }
        if (rec) { try { rec.stop(); } catch (e) {} }
        if (andCreate && input.value.trim()) smartCreate(input);
    }

    function armSilence() {
        if (silence) clearTimeout(silence);
        /* due secondi e mezzo di silenzio: se qualcosa è stato detto lo si
           inserisce, altrimenti si spegne senza fare nulla */
        silence = setTimeout(function () { stop(spoken); }, 2500);
    }

    btn.addEventListener('click', function () {
        if (running) { stop(false); return; }
        rec = new Rec();
        rec.lang = 'it-IT';
        rec.continuous = true;
        rec.interimResults = true;
        spoken = false;

        rec.onresult = function (e) {
            var testo = '';
            for (var i = 0; i < e.results.length; i++) testo += e.results[i][0].transcript;
            testo = testo.trim();
            if (testo) { input.value = testo; spoken = true; }
            armSilence();
        };
        rec.onerror = function () { stop(false); };
        rec.onend = function () { if (running) { try { rec.start(); } catch (e) { stop(spoken); } } };

        try {
            rec.start();
            running = true;
            btn.classList.add('pl-rec');
            armSilence();
        } catch (e) { stop(false); }
    });
}

/* Il riepilogo di quello che ha capito: resta qualche secondo e se ne va. */
function smartReport(p, start, end, created) {
    var d = parseIso(p.date);
    var quando = GIORNI[dowIndex(p.date)] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase();
    var box = $('plSmartReport');
    if (!box) {
        box = el('div', 'pl-smart-report');
        box.id = 'plSmartReport';
        document.body.appendChild(box);
    }
    box.innerHTML = '';
    box.appendChild(el('div', 'pl-smart-title', '“' + p.title + '”'));
    box.appendChild(el('div', 'pl-smart-line', quando + (p.allDay ? ', tutto il giorno' : ', dalle ' + start + ' alle ' + end)));
    if (p.guests && p.guests.length) {
        box.appendChild(el('div', 'pl-smart-line',
            'con ' + p.guests.map(function (g) { return g.name || g.email; }).join(', ') + ' · invito inviato'));
    }
    var note = [];
    if (p.dateGuessed) note.push('giorno non indicato: ho usato quello che stavi guardando');
    if (p.timeGuessed && p.allDay) note.push('ora non indicata: messo su tutto il giorno');
    else if (p.timeGuessed) note.push('ora dedotta dalla frase');
    note.push('tocca per sistemarlo');
    box.appendChild(el('div', 'pl-smart-note', note.join(' · ')));

    box.classList.add('pl-open');
    if (S._smartTimer) clearTimeout(S._smartTimer);
    /* qualche secondo in più: il tempo di leggere e decidere se ritoccarlo */
    S._smartTimer = setTimeout(function () { box.classList.remove('pl-open'); }, 9000);
    /* toccandolo si apre la modifica, per dargli una categoria o correggerlo */
    box.onclick = function () {
        box.classList.remove('pl-open');
        if (created) openEventSheet(created, null);
    };
}

/* ------------------------------------------------------------------ */
/* Fascia priorità (fissa, appartiene al giorno a fuoco)               */
/* ------------------------------------------------------------------ */
function prioOf(date) {
    var list = (S.prio[date] || []).slice();
    var manual = {};
    var autos = [];
    list.forEach(function (p) {
        if (p.auto) autos.push(p); else manual[p.position] = p;
    });
    return { manual: manual, autos: autos };
}

function renderPrio() {
    var host = $('plPrioRows');
    if (!host) return;
    renderPrioInto(host, S.focusDate);
    $('plPrio').classList.toggle('pl-slim', S.prioSlim);
    syncScrollerTop();
}

/* Il disegno vero e proprio sta qui, staccato dal nodo fisso della pagina:
   serve anche per la pagina del giorno vicino che entra con lo scorrimento,
   che ha le sue caselle e non può usare quelle in uso adesso. */
function renderPrioInto(host, giorno) {
    var data = prioOf(giorno);
    host.innerHTML = '';

    var cells = [];
    /* la cella automatica si porta dietro tutto quello che il server ha
       detto del progetto: è quello che poi il popup ha da raccontare */
    data.autos.forEach(function (a) {
        cells.push({
            auto: true, content: a.content, project_id: a.project_id, project: a.project,
            notes: a.notes, stato: a.stato, color: a.color,
            target_date: a.target_date, due_date: a.due_date
        });
    });

    /* Obiettivi e scadenze dei progetti occupano una casella come le altre:
       vanno contati nel totale, altrimenti restano in giro due caselle vuote
       invece di una (era il bug che vedevi con un "obiettivo" in elenco). */
    var filled = 0;
    for (var p = 1; p <= 6; p++) if (data.manual[p] && data.manual[p].content) filled = p;
    var wanted = cells.length + filled + 1;               // una sola casella libera in coda
    var total = Math.min(6 + cells.length, Math.max(2, Math.ceil(wanted / 2) * 2));
    var slots = Math.max(1, total - cells.length);
    for (var i = 1; i <= slots; i++) {
        var m = data.manual[i];
        cells.push({ position: i, content: m ? m.content : '', done: m ? m.done : false });
    }

    /* da chiusa la sezione mostra solo ciò che è scritto: le caselle vuote
       compaiono quando la si tocca, altrimenti rubano spazio per niente */
    var shown = S.prioSlim
        ? cells.filter(function (x) { return x.auto || (x.content && x.content.trim()); })
        : cells;
    if (!shown.length && S.prioSlim) {
        host.appendChild(el('div', 'pl-row-sub', 'Nessuna priorità. Tocca per aggiungerne.'));
    }
    /* Due per riga sempre, aperta o chiusa: tre affiancate su un telefono
       sarebbero tre parole tagliate. Da chiusa, però, di righe se ne vede una
       sola per volta, e le altre passano da sé. */
    var righe = [];
    for (var r = 0; r < shown.length; r += 2) {
        var row = el('div', 'pl-prio-row');
        for (var c = r; c < Math.min(r + 2, shown.length); c++) {
            row.appendChild(prioCell(shown[c], S.prioSlim));
        }
        host.appendChild(row);
        righe.push(row);
    }

    /* Da chiusa si vede una riga per volta e le righe girano da sé: con sei
       priorità e una riga sola in vista, le altre quattro sarebbero
       invisibili e tanto varrebbe non averle scritte. La colonnina di pallini
       dice quante righe ci sono e a che punto siamo: senza, il cambio
       sembrerebbe un difetto invece che un giro.
       Si parte sempre dalla prima riga — quello che si è scritto per primo è
       quello che conta di più, e deve essere la prima cosa che si vede. */
    var aGiro = S.prioSlim && righe.length > 1;
    righe.forEach(function (riga, i) { riga.classList.toggle('pl-on', !aGiro || i === 0); });

    var padre = host.parentNode;
    if (padre) {
        var vecchi = padre.querySelector('.pl-prio-pal');
        if (vecchi) vecchi.parentNode.removeChild(vecchi);
    }
    /* il giro e i pallini valgono per la fascia vera e non per la copia della
       pagina vicina che entra con lo scorrimento: due giri contemporanei si
       darebbero il cambio a caso */
    if (aGiro && padre && host.id === 'plPrioRows') {
        var pal = el('div', 'pl-prio-pal');
        righe.forEach(function (_, i) {
            pal.appendChild(el('span', 'pl-prio-pallino' + (i ? '' : ' pl-on')));
        });
        padre.appendChild(pal);
        avviaGiroPrio(righe, pal);
    } else if (host.id === 'plPrioRows') {
        fermaGiroPrio();
    }
}

/* Il riassunto di un progetto, aperto da una priorità automatica.

   Piccolo di proposito: quello che serve qui è capire in un colpo d'occhio
   di che progetto si tratta e se è ancora vivo, non gestirlo. Per gestirlo
   c'è la sua pagina, e il tasto in fondo ci porta già aperto su quello — che
   è poi la ragione per cui l'identificativo viaggia insieme alla priorità. */
function prioProgetto(data) {
    var ov = el('div', 'pl-ov');
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));

    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', data.project || 'Progetto'));
    var chiudi = el('button', 'pl-txt-btn', 'Chiudi');
    chiudi.type = 'button';
    chiudi.addEventListener('click', function () { ov.remove(); });
    head.appendChild(chiudi);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');

    /* il pallino del colore accanto allo stato: è il colore con cui quel
       progetto si riconosce dappertutto, e ritrovarlo qui evita di dover
       leggere il nome per capire quale sia */
    var riga = el('div', 'pl-prj-stato');
    var pallino = el('span', 'pl-prj-dot');
    pallino.style.background = data.color || 'var(--accent)';
    riga.appendChild(pallino);
    riga.appendChild(el('span', null, STATI_PRJ[data.stato] || 'in corso'));
    body.appendChild(riga);

    if (data.target_date) body.appendChild(rowInfo('Obiettivo', dataLunga(data.target_date)));
    if (data.due_date)    body.appendChild(rowInfo('Scadenza', dataLunga(data.due_date)));

    /* Le note per intero e non tagliate: sono poche righe e sono la ragione
       per cui uno apre il riassunto invece di andare direttamente alla
       pagina. Se non ci sono, non si scrive niente: una riga vuota che dice
       «nessuna nota» occupa lo stesso spazio e non aggiunge nulla. */
    if (data.notes) {
        var note = el('div', 'pl-prj-note');
        note.textContent = data.notes;
        body.appendChild(note);
    }

    var vai = el('a', 'pl-btn pl-btn-primary pl-prj-vai', 'Apri il progetto');
    vai.href = 'progetti.php?id=' + encodeURIComponent(data.project_id);
    body.appendChild(vai);

    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
}

/* gli stessi tre stati che gira la pagina progetti, in parole */
var STATI_PRJ = { in_progress: 'in corso', completed: 'completato', planned: 'da iniziare' };

/* una riga «etichetta — valore», che qui serve due o tre volte */
function rowInfo(lab, val) {
    var r = el('div', 'pl-prj-riga');
    r.appendChild(el('span', 'pl-prj-lab', lab));
    r.appendChild(el('span', 'pl-prj-val', val));
    return r;
}

function dataLunga(iso) {
    var d = parseIso(iso);
    return GIORNI[dowIndex(iso)] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()
         + (d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : '');
}

/* Il giro delle righe da chiusa. Quattro secondi: il tempo di leggere due
   priorità senza doverle rincorrere, e abbastanza perché anche un'occhiata
   di passaggio ne prenda comunque una.
   Il timer si spegne da sé quando le righe non sono più attaccate alla
   pagina — succede a ogni ridisegno — così non se ne accumulano uno sopra
   l'altro senza che nessuno li fermi. */
var prioGiro = null;
function fermaGiroPrio() {
    if (prioGiro) { clearInterval(prioGiro); prioGiro = null; }
}
function avviaGiroPrio(righe, pal) {
    fermaGiroPrio();
    if (righe.length < 2) return;
    var i = 0;
    prioGiro = setInterval(function () {
        if (!S.prioSlim || !righe[0].isConnected) { fermaGiroPrio(); return; }
        i = (i + 1) % righe.length;
        righe.forEach(function (r, k) { r.classList.toggle('pl-on', k === i); });
        for (var k = 0; k < pal.children.length; k++) {
            pal.children[k].classList.toggle('pl-on', k === i);
        }
    }, 4000);
}

function prioCell(data, compatta) {
    var cell = el('div', 'pl-prio-cell' + (data.done ? ' pl-done' : '') + (data.auto ? ' pl-auto' : ''));
    var dot = el('span', 'pl-prio-dot' + (data.done ? ' pl-on' : ''));
    dot.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';

    /* Da chiusa non è un campo ma una scritta, e la scritta che non ci sta
       scorre invece di finire nei puntini, come i titoli degli eventi. Con
       un campo di testo non si potrebbe: un `input` il testo lo taglia e
       basta, e non c'è niente dentro da far scivolare. Non è una perdita —
       da chiusa non si scrive comunque, il tocco apre la sezione — e in
       cambio in una riga sola ci sta anche una priorità lunga. */
    if (compatta) {
        var tx = el('span', 'pl-prio-tx');
        var dentro = el('span', null, data.content || '');
        tx.appendChild(dentro);
        cell.appendChild(dot);
        cell.appendChild(tx);
        misuraStriscia(tx, dentro);
        if (data.auto) {
            if (data.project_id) {
                tx.style.cursor = 'pointer';
                tx.addEventListener('click', function (e) {
                    e.stopPropagation();   // se no il tocco aprirebbe la sezione
                    prioProgetto(data);
                });
            }
            return cell;
        }
        dot.addEventListener('click', function (e) {
            e.stopPropagation();     // se no il tocco aprirebbe anche la sezione
            var now = !cell.classList.contains('pl-done');
            cell.classList.toggle('pl-done', now);
            dot.classList.toggle('pl-on', now);
            haptic(8);
            savePrio(data.position, data.content || '', now);
        });
        return cell;
    }

    var inp = el('input', 'pl-prio-in');
    inp.type = 'text';
    inp.value = data.content || '';
    inp.placeholder = data.auto ? '' : 'Priorità';
    inp.maxLength = 200;
    if (data.auto) inp.readOnly = true;
    cell.appendChild(dot);
    cell.appendChild(inp);
    if (data.auto) {
        /* Un campo di sola lettura non riceve il tocco come un tasto: si
           ascolta lo stesso e si apre il riassunto del progetto. È l'unico
           punto del planner in cui un progetto si affaccia, e fin qui si
           poteva solo leggerlo. */
        if (data.project_id) {
            inp.style.cursor = 'pointer';
            inp.addEventListener('click', function () { prioProgetto(data); });
        }
        return cell;
    }

    dot.addEventListener('click', function () {
        var now = !cell.classList.contains('pl-done');
        cell.classList.toggle('pl-done', now);
        dot.classList.toggle('pl-on', now);
        haptic(8);
        savePrio(data.position, inp.value, now);
    });
    inp.addEventListener('input', function () {
        debounceSave('prio' + data.position, 650, function () {
            savePrio(data.position, inp.value, cell.classList.contains('pl-done'));
        });
    });
    inp.addEventListener('blur', function () {
        savePrio(data.position, inp.value, cell.classList.contains('pl-done'));
        renderPrio();
    });

    /* La crocetta c'è solo da aperta e solo su una casella scritta: svuotare
       il campo a mano cancellava già la priorità, ma bisognava saperlo, e su
       una riga lunga voleva dire selezionare tutto e cancellare. Su una
       casella vuota non ci sarebbe niente da togliere. */
    if (data.content) {
        var via = el('button', 'pl-prio-x');
        via.type = 'button';
        via.setAttribute('aria-label', 'Elimina questa priorità');
        via.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        via.addEventListener('click', function (e) {
            e.stopPropagation();
            haptic(8);
            savePrio(data.position, '', false);
            renderPrio();
        });
        cell.appendChild(via);
    }
    return cell;
}

function savePrio(position, content, done) {
    var date = S.focusDate;
    var list = (S.prio[date] || []).filter(function (p) { return p.auto || p.position !== position; });
    if (content) list.push({ position: position, content: content, done: !!done });
    S.prio[date] = list;
    api('priority_save', { date: date, position: position, content: content, done: !!done });
}

function setPrioSlim(slim) {
    if (S.prioSlim === slim) return;
    S.prioSlim = slim;
    renderPrio();
}

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
        b.setAttribute('aria-label', (o.l || '') + ' alle ' + h);
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
        b.setAttribute('aria-label', (o.l || '') + ' alle ' + h);
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
        
        var list = eventsOf(ds).filter(function (e) { return e.all_day; });
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
        
        // L'ancora a cui appenderci: la barra tutto il giorno (su PC) o la sezione priorità (su Smartphone)
        var anchor = isDesk ? document.getElementById('plHeadRow') : document.querySelector('.pl-prio-wrap');
        
        if (fabs && anchor) {
            // Misuriamo la coordinata Y reale in cui finisce l'elemento sullo schermo
            var bottomY = anchor.getBoundingClientRect().bottom;
            
            // Inchiodiamo i tastini esattamente 8 pixel sotto quella linea
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
    list.forEach(function (ev) { if (!ev.all_day) timed.push(ev); });

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
                    id: g.base.id, date: g.base.date, title: (r ? r.name : 'Routine'),
                    start: toHM(startMin), end: toHM(endMin),
                    color: g.base.color, importance: 'normal', type: 'event', done: false,
                    routine_group: item, routine_collapsed: true, count: g.items.length
                };
                wrap.appendChild(buildEvent(fake, true));
            } else {
                g.items.forEach(function (x) { wrap.appendChild(buildEvent(x, true)); });
            }
        });
        if (!list.length) wrap.appendChild(el('div', 'pl-quote pl-serif', 'Nessun evento in programma.'));
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
                id: g.base.id, date: g.base.date, title: (r ? r.name : 'Routine'),
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
            lab.appendChild(el('span', null, rr ? rr.name : 'Routine'));
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
    return (soloOrario ? '' : ((ev.title || 'Senza titolo') + ' · '))
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

    node.appendChild(bottoneMini((ev.title || 'Evento') + ', ' + miniRiga(ev, true)));
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
        node.appendChild(bottoneMini(items.length + ' eventi ravvicinati'));
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
        alertBox(ev.title || 'Senza titolo', miniRiga(ev, true), [
            { label: 'Chiudi' },
            { label: 'Apri', kind: 'primary', onClick: function () { openEventSheet(ev, null); } }
        ]);
        return;
    }
    /* con pochi eventi ognuno ha il suo pulsante e ci si arriva in un tocco;
       con tanti la fila di pulsanti sarebbe più lunga del popup, quindi resta
       il solo elenco scritto */
    var azioni = [{ label: 'Chiudi' }];
    if (items.length <= 4) {
        items.forEach(function (ev) {
            azioni.push({
                label: ev.title || 'Senza titolo', kind: 'primary',
                onClick: function () { openEventSheet(ev, null); }
            });
        });
    }
    alertBox(items.length + ' eventi ravvicinati',
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
        var tagW = el('span', 'pl-ev-with', who.length === 1 ? who[0] : who.length + ' persone');
        tagW.title = ev.guests.map(function (g) { return g.email; }).join(', ');
        row.appendChild(tagW);
    }
    if (ev.location && !S.shift.on) {
        var mapBtn = el('button', 'pl-ev-map');
        mapBtn.type = 'button';
        mapBtn.dataset.act = 'map';
        mapBtn.dataset.place = ev.location;
        mapBtn.setAttribute('aria-label', 'Portami a ' + ev.location);
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
        node.appendChild(el('div', 'pl-ev-h', ev.start + ' – ' + ev.end + (ev.count ? '  ·  ' + ev.count + ' passaggi' : '')));
    }
    return node;
}

/* ------------------------------------------------------------------ */
/* Liste della settimana: to do, to buy, to study                      */
/* ------------------------------------------------------------------ */
/* Il pannello non tiene più liste sue. Mostra gli stessi dati della pagina
   attività, filtrati sulla settimana che si sta guardando: prima esistevano
   due elenchi con lo stesso nome che non sapevano l'uno dell'altro, e una
   cosa scritta di qua non compariva di là.

   Le vecchie tabelle `planner_week_lists` restano com'erano, insieme ai loro
   endpoint: non si cancella niente finché non si è sicuri di non averne più
   bisogno, e quello che c'è dentro è comunque recuperabile. */

/* Le attività non passano da planner.php: todo_api.php è già l'unico punto
   di scrittura di quei dati, e duplicare qui la validazione vorrebbe dire
   avere due posti in cui sbagliarla. */
function todoApi(action, data, query) {
    var url = 'todo_api.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
    var req = {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        /* La lettura degli elenchi è una GET su un indirizzo che non cambia
           mai: subito dopo un salvataggio il browser rispondeva con la copia
           di prima. È per questo che la priorità del to buy cambiava per un
           attimo e poi tornava indietro — non era il salvataggio a fallire,
           era la rilettura a non arrivare mai al server. */
        cache: 'no-store',
        headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf }
    };
    if (data) { req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(data); }
    return fetch(url, req)
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .catch(function () { return { ok: false }; });
}

/* Una lettura per linguetta, tenuta in memoria: il pannello si ridisegna a
   ogni render della pagina e non può chiedere l'elenco al server ogni volta. */
function loadWeekTodos(type, force) {
    S.todos = S.todos || {};
    if (S.todos[type] && !force) return Promise.resolve();
    return todoApi('list', null, { scope: 'all', list_type: type }).then(function (r) {
        if (!r.ok) return;
        S.todos[type] = r.todos || [];
        S.todosToday = r.today;
        renderWeekPanel();
        updateWeekPanelBtn();
    });
}

function weekTodoRange() {
    var from = monday(S.focusDate);
    return { from: from, to: addDays(from, 6) };
}

function renderWeekPanel() {
    var wrap = $('plWeekPanelBody');
    if (!wrap) return;
    /* finché il pannello è chiuso non c'è niente da disegnare: render() passa
       di qui a ogni ridisegno della pagina, anche mentre si trascina */
    var ov = $('plWeekPanelOv');
    if (!ov || !ov.classList.contains('pl-open')) return;

    var rg = weekTodoRange();
    wrap.innerHTML = '';

    var d1 = parseIso(rg.from), d2 = parseIso(rg.to);
    var range = d1.getDate() + ' ' + MESI[d1.getMonth()].toLowerCase() + ' – ' + d2.getDate() + ' ' + MESI[d2.getMonth()].toLowerCase();
    wrap.appendChild(el('div', 'pl-label', range));

    /* Le linguette: i tre elenchi più le pastiglie. Nella pagina attività ci
       sono tutte e quattro, e qui ne mancava una sola — che è poi quella che
       si guarda più spesso durante la giornata. */
    var tabs = el('div', 'pl-wl-tabs');
    PANEL_TABS.forEach(function (t) {
        var b = el('button', 'pl-wl-tab' + (t === S.todoTab ? ' pl-on' : ''), LIST_LABEL[t]);
        b.type = 'button';
        b.addEventListener('click', function () {
            if (S.todoTab === t) return;
            S.todoTab = t;
            S.todoOthers = false;
            renderWeekPanel();
            if (t === 'pills') loadWeekPills(S.focusDate);
            else loadWeekTodos(t);
        });
        tabs.appendChild(b);
    });
    wrap.appendChild(tabs);

    var host = el('div', 'pl-lists');
    wrap.appendChild(host);
    host.appendChild(S.todoTab === 'pills' ? weekPillsCard() : weekTodoList());

    var learned = el('div', 'pl-learned');
    learned.appendChild(el('div', 'pl-label', 'Cosa ho imparato'));
    var ta = el('textarea');
    ta.placeholder = 'Questa settimana mi ha insegnato…';
    ta.value = S.week.learned || '';
    ta.maxLength = 4000;
    ta.addEventListener('input', function () {
        S.week.learned = ta.value;
        debounceSave('learned', 900, function () {
            api('week_save', { week_start: rg.from, learned: ta.value });
        });
    });
    learned.appendChild(ta);
    host.appendChild(learned);
}

function weekTodoList() {
    var rg = weekTodoRange();
    var tipo = S.todoTab;
    var card = el('div', 'pl-list-card');

    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', LIST_LABEL[tipo] || tipo));
    var link = el('a', 'pl-wl-link', 'apri');
    /* la linguetta viaggia con il collegamento: si sta guardando questo
       elenco, e aprirne un altro sarebbe un salto che non ha chiesto nessuno */
    link.href = 'todo.php?tab=' + encodeURIComponent(tipo);
    head.appendChild(link);
    card.appendChild(head);

    var tutti = (S.todos && S.todos[tipo]) || null;
    if (tutti === null) {
        card.appendChild(el('div', 'pl-wl-empty', 'Un momento…'));
        return card;
    }
    /* le sotto-attività seguono la principale nella pagina attività: qui
       sarebbero righe orfane, senza il loro contesto */
    var roots = tutti.filter(function (t) { return !t.parent_id; });

    var dentro = roots.filter(function (t) { return t.date && t.date >= rg.from && t.date <= rg.to; });
    dentro.sort(function (a, b) {
        /* Nel "to buy" comanda la priorità: la spesa non si fa in ordine di
           data, si fa cominciando da quello che serve davvero. Negli altri
           elenchi la data resta la chiave, perché lì è una scadenza. */
        if (tipo === 'buy') {
            var pa = a.priority || 3, pb = b.priority || 3;
            if (pa !== pb) return pa - pb;
        }
        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
        return (a.time || '99:99') < (b.time || '99:99') ? -1 : 1;
    });

    var box = el('div', 'pl-list-items');
    if (!dentro.length) {
        box.appendChild(el('div', 'pl-wl-empty', 'Niente in questa settimana.'));
    } else {
        dentro.forEach(function (t) { box.appendChild(weekTodoRow(t, false)); });
    }
    card.appendChild(box);

    /* aggiungerne una qui dà per scontata la data mostrata: è il motivo per
       cui si sta guardando questa settimana e non un'altra */
    var add = el('button', 'pl-wl-add');
    add.type = 'button';
    add.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    add.appendChild(el('span', null, 'Aggiungi al ' + parseIso(S.focusDate).getDate() + ' ' + MESI[parseIso(S.focusDate).getMonth()].toLowerCase()));
    add.addEventListener('click', function () {
        todoApi('save', {
            id: 0, title: ' ', list_type: tipo, parent_id: 0, project_id: 0,
            date: S.focusDate, time: '', minutes: 30, source: 'page'
        }).then(function (r) {
            if (!r.ok) return;
            loadWeekTodos(tipo, true).then(function () {
                /* il campo nuovo è quello rimasto vuoto: ci si scrive dentro
                   senza doverlo cercare */
                var ins = $('plWeekPanelBody').querySelectorAll('.pl-li-in');
                for (var i = 0; i < ins.length; i++) {
                    if (!ins[i].value.trim()) { ins[i].focus(); break; }
                }
            });
            loadWeek(monday(S.focusDate), true);
        });
    });
    card.appendChild(add);

    /* il resto sta sotto e si chiede: senza data, poi più avanti, poi quelle
       rimaste indietro — che non sono nominate nella richiesta ma sono
       esattamente quelle che si vuole tirare dentro la settimana */
    var senza  = roots.filter(function (t) { return !t.date; });
    var dopo   = roots.filter(function (t) { return t.date && t.date > rg.to; });
    var prima  = roots.filter(function (t) { return t.date && t.date < rg.from; });
    dopo.sort(function (a, b) { return a.date < b.date ? -1 : 1; });
    prima.sort(function (a, b) { return a.date < b.date ? -1 : 1; });

    if (senza.length || dopo.length || prima.length) {
        var more = el('button', 'pl-wl-more', S.todoOthers ? 'Nascondi gli altri' : 'Mostra anche gli altri');
        more.type = 'button';
        more.addEventListener('click', function () {
            S.todoOthers = !S.todoOthers;
            renderWeekPanel();
        });
        card.appendChild(more);

        if (S.todoOthers) {
            var altri = el('div', 'pl-wl-others');
            function gruppo(label, list) {
                if (!list.length) return;
                altri.appendChild(el('div', 'pl-wl-glab', label));
                list.forEach(function (t) { altri.appendChild(weekTodoRow(t, true)); });
            }
            gruppo('senza data', senza);
            gruppo('più avanti', dopo);
            gruppo('rimaste indietro', prima);
            card.appendChild(altri);
        }
    }
    return card;
}

/* ------------------------------------------------------------------ */
/* Pastiglie: le prese del giorno                                      */
/* ------------------------------------------------------------------ */
/* Le prese si leggono e si spuntano da todo_api.php, che è già l'unico posto
   che le scrive e l'unico che sa quando una terapia tocca un giorno (giorni
   della settimana, giorni del mese, ogni N giorni, orari e quante volte per
   orario). Rifare quel conto qui vorrebbe dire avere due calendari delle
   prese che possono non essere d'accordo, ed è esattamente l'errore che il
   pannello settimana ha già fatto una volta con gli elenchi.

   A differenza delle altre tre linguette qui si mostra UN GIORNO, quello a
   fuoco, e non la settimana: una presa la si spunta il giorno stesso, e sette
   giorni di prese sarebbero venti righe di cui diciotto non toccabili.
   L'endpoint stesso è fatto per un giorno solo — è lui che crea le prese
   mancanti di quella data — e chiedergliene sette vorrebbe dire scrivere in
   archivio una settimana di prese solo per aver aperto un pannello. */
function loadWeekPills(date, force) {
    date = date || S.focusDate;
    if (S.pills && S.pills.date === date && !force) return Promise.resolve();
    return todoApi('pills', null, { date: date }).then(function (r) {
        if (!r || !r.ok) return;
        S.pills = {
            date: date,
            installed: r.installed !== false,
            doses: r.doses || [],
            /* le arretrate non appartengono al giorno chiesto: sono di ieri
               comunque, e il server le manda sempre le stesse */
            late: r.late || [],
            stats: r.stats || null
        };
        renderWeekPanel();
    });
}

function weekPillsCard() {
    var date = S.focusDate;
    var card = el('div', 'pl-list-card');

    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', 'Pastiglie'));
    var link = el('a', 'pl-wl-link', 'apri');
    link.href = 'todo.php?tab=pills';
    head.appendChild(link);
    card.appendChild(head);

    /* Il giorno scritto per esteso: le altre linguette parlano di tutta la
       settimana, e senza dirlo qui si crederebbe di vedere le prese di
       tutt'e sette. */
    var d = parseIso(date);
    card.appendChild(el('div', 'pl-pill-day',
        'prese di ' + (date === S.today ? 'oggi' : GIORNI[dowIndex(date)].toLowerCase())
        + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()));

    if (!S.pills || S.pills.date !== date) {
        card.appendChild(el('div', 'pl-wl-empty', 'Un momento…'));
        /* il giorno a fuoco può essere cambiato mentre il pannello era
           aperto: si chiede quello giusto invece di mostrare l'altro */
        loadWeekPills(date);
        return card;
    }
    if (!S.pills.installed) {
        card.appendChild(el('div', 'pl-wl-empty', 'Le pastiglie non sono installate.'));
        return card;
    }

    /* --- quelle rimaste indietro ---
       Stanno anche qui e non solo nella pagina attività: se il planner mostra
       le pastiglie, deve mostrare per prima l'unica su cui si può ancora fare
       qualcosa. Non dipendono dal giorno che si sta guardando — sono di ieri
       comunque — quindi compaiono anche sfogliando la settimana. */
    var late = S.pills.late || [];
    if (late.length) {
        card.appendChild(el('div', 'pl-label pl-pill-lasect',
            late.length === 1 ? 'Rimasta indietro' : 'Rimaste indietro'));
        var lbox = el('div', 'pl-list-items');
        late.forEach(function (p) { lbox.appendChild(pillRow(p, 'arretrata')); });
        card.appendChild(lbox);
    }

    var box = el('div', 'pl-list-items');
    if (!S.pills.doses.length) {
        box.appendChild(el('div', 'pl-wl-empty', 'Nessuna presa prevista.'));
    } else {
        /* Le prese si spuntano il giorno stesso: su un giorno futuro le
           caselle restano da guardare e non da toccare. Segnare oggi una
           pastiglia di dopodomani non vuol dire niente, e l'ora della presa
           che ne uscirebbe sarebbe una data inventata. */
        var futuro = date > S.today;
        S.pills.doses.forEach(function (p) { box.appendChild(pillRow(p, futuro ? 'futura' : null)); });
        if (futuro) {
            box.appendChild(el('div', 'pl-row-sub', 'Si spuntano il giorno stesso.'));
        }
    }
    card.appendChild(box);

    /* Quante ne sono state saltate o dimenticate negli ultimi trenta giorni:
       è il numero per cui vale la pena tenere le prese una per una, e in
       fondo all'elenco non ruba spazio a quelle di oggi. */
    var st = S.pills.stats;
    if (st && (st.taken || st.skipped || st.missed)) {
        card.appendChild(el('div', 'pl-pill-stats',
            'ultimi 30 giorni: ' + st.taken + ' prese'
            + (st.skipped ? ', ' + st.skipped + ' saltate' : '')
            + (st.missed ? ', ' + st.missed + ' dimenticate' : '')));
    }
    return card;
}

/* Una presa. Tre casi, e il modo che è che cambia:

   - OGGI: solo la casella. La crocetta che c'era prima non serviva a niente
     — quello che segnava lo si toglieva già togliendo il flag — e in una
     riga stretta un tastino che non aggiunge niente è solo un modo in più
     per sbagliare.
   - GIORNO FUTURO: niente da toccare. Spuntare oggi la pastiglia di
     dopodomani non vuol dire nulla, e l'ora della presa che ne verrebbe
     fuori sarebbe inventata.
   - ARRETRATA: casella *e* crocetta, e qui la crocetta serve davvero. La
     casella dice «l'ho presa» e chiede quando, perché su una presa di ieri
     l'orologio di adesso sbaglierebbe di sicuro; la crocetta dice «non l'ho
     presa» e la toglie dall'elenco, se no resterebbe lì per sempre. */
function pillRow(p, modo) {
    var arretrata = modo === 'arretrata';
    var futura = modo === 'futura';
    var row = el('div', 'pl-li pl-pill'
        + (p.status === 'taken' ? ' pl-done' : '')
        + (p.status === 'skipped' ? ' pl-pill-skip' : '')
        + (arretrata ? ' pl-pill-late' : '')
        + (futura ? ' pl-pill-futura' : ''));

    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    row.appendChild(box);

    row.appendChild(el('span', 'pl-pill-h', p.time || ''));

    var pallino = el('span', 'pl-pill-dot');
    pallino.style.setProperty('--ev', p.color || 'var(--accent)');
    row.appendChild(pallino);

    var txt = el('div', 'pl-pill-txt');
    /* di che giorno parliamo: tre righe uguali di tre giorni diversi, senza,
       non si distinguono */
    if (arretrata && p.date) txt.appendChild(el('div', 'pl-pill-quando', pillGiornoBreve(p.date)));
    txt.appendChild(el('div', 'pl-pill-name', p.name || 'senza nome'));
    /* dose e avvertenze sulla stessa riga: sono la cosa che si dimentica
       ("mezza compressa", "a stomaco pieno") e sta bene sotto il nome */
    var sotto = [p.dose, p.notes].filter(Boolean).join(' · ');
    if (sotto) txt.appendChild(el('div', 'pl-pill-dose', sotto));
    row.appendChild(txt);

    if (futura) return row;   // da guardare, non da toccare

    function segna(stato, quando) {
        p.status = stato;
        row.classList.toggle('pl-done', stato === 'taken');
        row.classList.toggle('pl-pill-skip', stato === 'skipped');
        haptic(8);
        var dati = { id: p.id, status: stato };
        if (stato === 'taken' && quando) {
            dati.at_date = quando.slice(0, 10);
            dati.at_time = quando.slice(11, 16);
        }
        /* si segna subito e si avvisa il server dopo: la spunta di una
           pastiglia deve sembrare immediata come tutte le altre */
        todoApi('pill_mark', dati);
        /* un'arretrata sistemata non è più arretrata: sparisce dall'elenco
           alla lettura dopo, che si chiede qui */
        if (arretrata) setTimeout(function () { loadWeekPills(S.focusDate, true); }, 400);
    }

    box.addEventListener('click', function () {
        if (p.status === 'taken') { segna('pending'); return; }
        if (arretrata) { pillQuandoPresa(p, function (q) { segna('taken', q); }); return; }
        segna('taken');
    });

    if (arretrata) {
        var salta = el('button', 'pl-pill-x');
        salta.type = 'button';
        salta.setAttribute('aria-label', 'Non l\'ho presa');
        salta.title = 'Non l\'ho presa: toglila dall\'elenco';
        salta.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        salta.addEventListener('click', function (e) {
            e.stopPropagation();
            segna(p.status === 'skipped' ? 'pending' : 'skipped');
        });
        row.appendChild(salta);
    }
    return row;
}

/* «ieri» quando è ieri, se no «lun 3». Il nome del giorno da solo, su una
   cosa passata, si legge come il giorno che deve venire. */
function pillGiornoBreve(iso) {
    if (iso === addDays(S.today, -1)) return 'ieri';
    var d = parseIso(iso);
    return GIORNI[dowIndex(iso)].slice(0, 3) + ' ' + d.getDate();
}

/* Quando l'hai presa davvero. Si chiede solo sulle arretrate: su quelle di
   oggi l'orologio va già bene, e una domanda in più a ogni pastiglia sarebbe
   un pedaggio su un gesto che si fa tre volte al giorno.
   Si parte dall'ora prevista e non da adesso: quasi sempre la pastiglia è
   stata presa lì o poco dopo, e così spesso basta confermare. */
function pillQuandoPresa(p, poi) {
    /* Nasce da dentro il pannello della settimana, che sta più in alto di
       tutti i fogli normali: senza la classe in più finiva sotto la barra
       delle linguette, e si vedeva spuntare a metà. */
    var ov = el('div', 'pl-ov pl-ov-sopra');
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));
    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', 'Quando l\'hai presa?'));
    var no = el('button', 'pl-txt-btn', 'Annulla');
    no.type = 'button';
    no.addEventListener('click', function () { ov.remove(); });
    head.appendChild(no);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');
    body.appendChild(el('div', 'pl-row-sub',
        (p.name || 'la pastiglia') + ' di ' + pillGiornoBreve(p.date || S.today)));

    /* Il giorno c'è perché una presa di lunedì spuntata mercoledì appartiene
       a lunedì: scriverla su mercoledì sposterebbe il buco invece di
       chiuderlo. */
    var iData = el('input', 'pl-pick');
    iData.type = 'date';
    iData.value = p.date || S.today;
    body.appendChild(rowSB('Giorno', iData));

    var iOra = el('input', 'pl-pick');
    iOra.type = 'time'; iOra.step = '60';
    iOra.value = p.time || '08:00';
    body.appendChild(rowSB('Ora', iOra));

    var ok = el('button', 'pl-btn pl-btn-primary pl-pill-ok', 'Segna come presa');
    ok.type = 'button';
    ok.addEventListener('click', function () {
        var g = iData.value || p.date || S.today;
        var o = (iOra.value || p.time || '08:00').slice(0, 5);
        ov.remove();
        poi(g + ' ' + o + ':00');
    });
    body.appendChild(ok);

    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
}

/* Che cosa scrivere nel tastino «quando» di una voce.

   L'ora, da sola, vale solo per oggi: su una cosa di giovedì un «18:30»
   secco fa credere che sia per stasera, ed è l'equivoco che costa di più
   perché ci si accorge solo dopo. Quindi: oggi l'ora, gli altri giorni il
   giorno.

   Entro una settimana il nome del giorno dice tutto e si legge di colpo
   («mer» è più immediato di «6 ago»); oltre, il nome torna ambiguo — fra
   sei settimane è comunque un mercoledì — e allora ci vuole il numero.
   All'indietro il numero sempre: «mar» su una cosa scaduta si leggerebbe
   come il martedì che deve venire. */
function todoQuandoTesto(t) {
    if (!t.date) return t.time ? t.time : 'quando';
    var avanti = diffDays(S.today, t.date);
    if (avanti === 0) return t.time ? t.time : 'oggi';
    if (avanti > 0 && avanti <= 7) return GIORNI[dowIndex(t.date)].slice(0, 3);
    var d = parseIso(t.date);
    return d.getDate() + ' ' + MESI[d.getMonth()].slice(0, 3).toLowerCase();
}

/* Una riga. Dentro la settimana si spunta e si modifica; fuori si tocca e
   basta, perché lì il gesto ha un altro significato: prendere la voce e
   portarla al giorno che si sta guardando. */
function weekTodoRow(t, isOther) {
    var row = el('div', 'pl-li' + (t.done ? ' pl-done' : '') + (isOther ? ' pl-li-other' : ''));

    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    row.appendChild(box);

    if (isOther) {
        row.appendChild(el('span', 'pl-li-txt', t.title || 'senza titolo'));
        if (t.date) row.appendChild(el('span', 'pl-li-when', todoQuandoTesto(t)));
        row.addEventListener('click', function () { takeIntoWeek(t); });
        return row;
    }

    var inp = el('input', 'pl-li-in');
    inp.type = 'text';
    inp.value = t.title || '';
    inp.placeholder = 'Aggiungi…';
    inp.maxLength = 300;
    inp.addEventListener('change', function () { salvaTitolo(); });
    inp.addEventListener('blur', function () { salvaTitolo(); });
    function salvaTitolo() {
        if (inp.value === t.title) return;
        t.title = inp.value;
        todoApi('save', {
            id: t.id, title: inp.value, list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: t.date || '', time: t.time || '', minutes: t.minutes || 30, source: 'page'
        }).then(function () {
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    }
    row.appendChild(inp);

    /* La priorità nel "to buy": si legge e si cambia da qui, senza passare
       dalla pagina attività. Gira 1 → 2 → 3 → 1, come là: con tre soli valori
       un menù sarebbe più lavoro del giro. */
    if (S.todoTab === 'buy') {
        var prio = el('button', 'pl-li-prio pl-prio-' + (t.priority || 3));
        prio.type = 'button';
        prio.textContent = String(t.priority || 3);
        prio.setAttribute('aria-label', 'Priorità ' + (t.priority || 3));
        prio.addEventListener('click', function (e) {
            e.stopPropagation();
            var next = (t.priority || 3) % 3 + 1;
            t.priority = next;
            prio.className = 'pl-li-prio pl-prio-' + next;
            prio.textContent = String(next);
            haptic(6);
            todoApi('save', {
                id: t.id, title: t.title || '', list_type: t.list_type || S.todoTab,
                parent_id: t.parent_id || 0, project_id: t.project_id || 0,
                date: t.date || '', time: t.time || '', minutes: t.minutes || 30,
                source: 'page', buy_set: ['priority'], priority: next
            }).then(function () { loadWeekTodos(S.todoTab, true); });
        });
        row.appendChild(prio);
    }

    /* Data e ora si toccano e si cambiano da qui: prima erano una scritta
       morta, e per spostare una voce di un giorno bisognava andare nella
       pagina attività. */
    var when = el('button', 'pl-li-when');
    when.type = 'button';
    when.textContent = todoQuandoTesto(t);
    when.addEventListener('click', function (e) { e.stopPropagation(); todoQuando(t); });
    row.appendChild(when);

    if (S.todoTab === 'buy' && t.cents !== null && t.cents !== undefined) {
        row.appendChild(el('span', 'pl-li-price', (t.cents / 100).toFixed(2).replace('.', ',') + ' €'));
    }

    box.addEventListener('click', function () {
        t.done = !t.done;
        row.classList.toggle('pl-done', t.done);
        haptic(8);
        /* Il blocco sul calendario si sbarra subito, senza aspettare la
           rilettura: la stessa cosa vive in due posti, e vederla spuntata di
           qua e intatta di là per mezzo secondo fa dubitare che il tocco sia
           arrivato. La rilettura poi conferma. */
        if (t.event_id) {
            Object.keys(S.events).forEach(function (g) {
                (S.events[g] || []).forEach(function (x) {
                    if (x.id === t.event_id) x.done = !t.done;
                });
            });
            renderDays();
        }
        todoApi('toggle', { id: t.id }).then(function () {
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    });

    /* Il cestino sta sotto la riga e si scopre scorrendola, come nella pagina
       attività: stesso gesto nei due posti, così non c'è niente da imparare
       due volte. Su computer lo scorrimento con il mouse funziona lo stesso,
       ma i campi di testo restano esclusi — lì trascinare vuol dire
       selezionare, non spostare. */
    var wrap = el('div', 'pl-li-wrap');
    var trash = el('button', 'pl-li-trash');
    trash.type = 'button';
    trash.setAttribute('aria-label', 'Elimina');
    trash.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    trash.addEventListener('click', function () {
        alertBox('Elimino?', '«' + (t.title || 'senza titolo') + '» sparisce dagli elenchi e dal planner.', [
            { label: 'Lascia stare' },
            { label: 'Elimina', kind: 'danger', onClick: function () {
                todoApi('delete', { id: t.id }).then(function () {
                    loadWeekTodos(S.todoTab, true);
                    loadWeek(monday(S.focusDate), true);
                });
            } }
        ]);
    });
    wrap.appendChild(trash);
    wrap.appendChild(row);
    abilitaSwipeLi(wrap, row);
    return wrap;
}

/* Scorrendo la riga verso destra spunta il cestino; scorrendo indietro, o
   toccando un'altra riga, si richiude. */
function abilitaSwipeLi(wrap, row) {
    var x0 = null, y0 = 0, pid = null, daCampo = false;
    function chiudiAltre() {
        var aperte = document.querySelectorAll('.pl-li-wrap.pl-swiped');
        for (var i = 0; i < aperte.length; i++) if (aperte[i] !== wrap) aperte[i].classList.remove('pl-swiped');
    }
    row.addEventListener('pointerdown', function (e) {
        daCampo = !!(e.target.closest && e.target.closest('input, textarea'));
        if (daCampo && e.pointerType === 'mouse') { x0 = null; return; }
        x0 = e.clientX; y0 = e.clientY; pid = e.pointerId;
    });
    row.addEventListener('pointermove', function (e) {
        if (x0 === null || e.pointerId !== pid) return;
        var dx = e.clientX - x0, dy = e.clientY - y0;
        /* orizzontale dichiarato: scorrendo il pannello in verticale con il
           dito appoggiato su una riga, il cestino non deve spuntare */
        if (Math.abs(dx) < Math.abs(dy) * 1.3) return;
        var soglia = daCampo ? 26 : 14;
        if (dx > soglia) { chiudiAltre(); wrap.classList.add('pl-swiped'); }
        if (dx < -soglia) wrap.classList.remove('pl-swiped');
    });
    function stop() { x0 = null; pid = null; }
    row.addEventListener('pointerup', stop);
    row.addEventListener('pointercancel', stop);
    row.addEventListener('pointerleave', stop);
}

/* Data, ora e durata di un'attività, cambiate da dove la si sta guardando.
   L'ora vuota vuol dire "in giornata": è la stessa convenzione del
   database (due_time NULL) e della pagina attività. */
function todoQuando(t) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = t.title || 'Senza titolo';
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');

    function riga(lab, campo) {
        var r = el('div', 'pl-row-sb');
        r.appendChild(el('span', 'pl-row-lab', lab));
        r.appendChild(campo);
        wrap.appendChild(r);
    }
    var dIn = el('input', 'pl-pick'); dIn.type = 'date'; dIn.value = t.date || S.focusDate;
    var oIn = el('input', 'pl-pick'); oIn.type = 'time'; oIn.step = '300'; oIn.value = t.time || '';
    var mIn = el('input', 'pl-pick');
    mIn.type = 'number'; mIn.min = '5'; mIn.max = '1440'; mIn.step = '5';
    mIn.value = String(t.minutes || 30);
    riga('Data', dIn);
    riga('Ora', oIn);
    riga('Durata (minuti)', mIn);
    /* senza orario l'attività vale per tutto il giorno: serve un modo per
       tornare indietro, perché un campo orario svuotato a mano non sempre
       si lascia svuotare */
    var senza = el('button', 'pl-linkish', 'Togli l\'orario');
    senza.type = 'button';
    senza.addEventListener('click', function () { oIn.value = ''; });
    wrap.appendChild(senza);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var si = el('button', 'pl-btn pl-btn-primary', 'Salva'); si.type = 'button';
    si.addEventListener('click', function () {
        ov.classList.remove('pl-open');
        t.date = dIn.value || t.date;
        t.time = oIn.value || null;
        t.minutes = Math.max(5, Math.min(1440, parseInt(mIn.value, 10) || 30));
        todoApi('save', {
            id: t.id, title: t.title || '', list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: t.date || '', time: t.time || '', minutes: t.minutes, source: 'page'
        }).then(function (r) {
            if (!r.ok) { toast('Non è stato possibile salvare.'); return; }
            loadWeekTodos(S.todoTab, true);
            /* la stessa attività è anche un evento in agenda: il planner va
               riletto, se no la riga si sposta e il blocco resta dov'era */
            loadWeek(monday(S.focusDate), true);
        });
    });
    acts.appendChild(no); acts.appendChild(si);
    ov.classList.add('pl-open');
}

/* Portare una voce nella settimana. Se una data ce l'aveva già si chiede
   conferma: spostarla può voler dire mancare una scadenza, e un tocco preso
   per sbaglio non deve poterlo fare in silenzio. */
function takeIntoWeek(t) {
    function applica() {
        todoApi('save', {
            id: t.id, title: t.title, list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: S.focusDate, time: t.time || '', minutes: t.minutes || 30, source: 'page'
        }).then(function (r) {
            if (!r.ok) return;
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    }
    if (!t.date) { applica(); return; }
    var d = parseIso(t.date), n = parseIso(S.focusDate);
    alertBox('Cambio la data?',
        '«' + (t.title || 'senza titolo') + '» è al ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase() +
        '. Passa al ' + n.getDate() + ' ' + MESI[n.getMonth()].toLowerCase() + '.', [
        { label: 'Lascia stare' },
        { label: 'Sposta', kind: 'primary', onClick: applica }
    ]);
}

/* il pulsantino a sinistra di "Priorità" si accende se la settimana è compilata */
function updateWeekPanelBtn() {
    var btn = $('plWeekPanelBtn');
    if (!btn) return;
    var filled = !!(S.week.learned && S.week.learned.trim());
    if (!filled && S.todos) {
        var rg = weekTodoRange();
        for (var k in S.todos) if (S.todos.hasOwnProperty(k)) {
            var hit = (S.todos[k] || []).some(function (t) {
                return !t.parent_id && t.date && t.date >= rg.from && t.date <= rg.to;
            });
            if (hit) { filled = true; break; }
        }
    }
    btn.classList.toggle('pl-filled', filled);
}

function openWeekPanel() {
    /* la classe prima del disegno: renderWeekPanel non lavora a pannello
       chiuso, e verrebbe chiamato a vuoto */
    $('plWeekPanelOv').classList.add('pl-open');
    document.documentElement.classList.add('pl-locked');
    renderWeekPanel();
    if (S.todoTab === 'pills') loadWeekPills(S.focusDate, true);
    else loadWeekTodos(S.todoTab, true);
}
function closeWeekPanel() {
    $('plWeekPanelOv').classList.remove('pl-open');
    if (!document.querySelector('.pl-ov.pl-open')) document.documentElement.classList.remove('pl-locked');
}

/* ------------------------------------------------------------------ */
/* Anello delle abitudini                                              */
/* ------------------------------------------------------------------ */
/* Gli anelli concentrici delle abitudini.

   Uno per famiglia di frequenza, e solo per le famiglie che hanno almeno
   un'abitudine: il più interno è il giornaliero, poi andando verso l'esterno
   settimanale, mensile, personalizzate. Il conto arriva già fatto dal server
   (pl_rings): da qui non si potrebbe sapere quante volte una mensile è stata
   fatta nel mese, perché al client arrivano solo le abitudini del giorno
   mostrato.

   Gli anelli sono attaccati: ognuno occupa una corsia dello stesso spessore,
   separata dalla successiva da un filo sottilissimo. Il numero di anelli
   cambia da utente a utente, quindi il disegno si costruisce qui invece di
   stare fisso nel markup. */
var RING_LARGHEZZA = 3.4;    // spessore di ogni corsia
var RING_STACCO    = 0.9;    // il filo che divide una corsia dall'altra
var RING_ESTERNO   = 21;     // raggio della corsia più esterna

function renderRing() {
    var ring = $('plHabitRing');
    if (!ring) return;
    var date = S.focusDate;
    /* Il giorno chiesto viene appuntato qui: se nel frattempo se ne sfoglia un
       altro, la risposta che arriva tardi non ridisegna più l'anello del
       giorno sbagliato. */
    ring.dataset.want = date;

    /* L'animazione degli anelli racconta «è cambiato il giorno». Zoomando il
       giorno è lo stesso, e vederli ripartire da zero a ogni pizzico faceva
       sembrare che fosse successo qualcosa che non era successo. Se il giorno
       disegnato è già questo e i dati sono gli stessi, non si ridisegna. */
    var pronti = S.rings[date];
    if (pronti && ring.dataset.drawn === date && ring.querySelector('.pl-ring-fill')) return;

    if (pronti) { ring.dataset.drawn = date; disegnaAnelli(ring, pronti); return; }
    ring.dataset.drawn = '';

    /* Finché non si sa, si svuota: meglio niente che i dati di ieri. */
    disegnaAnelli(ring, null);
    loadRings(date).then(function (dati) {
        if (ring.dataset.want !== date) return;
        ring.dataset.drawn = date;
        disegnaAnelli(ring, dati);
    });
}

function loadRings(date) {
    if (S.rings[date]) return Promise.resolve(S.rings[date]);
    return api('habits_day', null, { date: date }).then(function (r) {
        S.habits[date] = r.ok ? r.habits : [];
        if (r.ok && r.weekly) S.weekly[monday(date)] = r.weekly;
        S.rings[date] = r.ok && r.rings ? r.rings : [];
        return S.rings[date];
    });
}

function disegnaAnelli(ring, dati) {
    var svg = ring.querySelector('svg');
    if (!svg) return;
    dati = dati || [];

    var pezzi = '';
    var offsetFinali = []; // Memorizziamo qui i valori finali

    for (var i = 0; i < dati.length; i++) {
        /* i = 0 è il più interno, quindi si parte da dentro e si esce */
        var r = RING_ESTERNO - (dati.length - 1 - i) * (RING_LARGHEZZA + RING_STACCO);
        if (r < 3) break;                       // troppe famiglie: non ci sta più
        var C = 2 * Math.PI * r;
        var frac = dati[i].total > 0 ? Math.min(1, dati[i].done / dati[i].total) : 0;
        
        // Salviamo l'offset che l'anello dovrà raggiungere alla fine
        offsetFinali.push((C * (1 - frac)).toFixed(1));

        // INSERIAMO L'ANELLO VUOTO: stroke-dashoffset uguale all'intera circonferenza (C)
        pezzi += '<circle class="pl-ring-track" cx="23" cy="23" r="' + r.toFixed(2)
              + '" stroke-width="' + RING_LARGHEZZA + '"/>'
              + '<circle class="pl-ring-fill pl-ring-' + dati[i].key + '" cx="23" cy="23" r="' + r.toFixed(2)
              + '" stroke-width="' + RING_LARGHEZZA + '"'
              + ' stroke-dasharray="' + C.toFixed(1) + '"'
              + ' stroke-dashoffset="' + C.toFixed(1) + '"/>'; 
    }
    
    svg.innerHTML = pezzi;

    /* Diamo il tempo al browser di disegnare gli anelli vuoti, poi applichiamo i valori 
       reali. Questo fa scattare la transizione CSS morbida e li vedi riempirsi. */
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            var cerchiAnimati = svg.querySelectorAll('.pl-ring-fill');
            for (var j = 0; j < cerchiAnimati.length; j++) {
                cerchiAnimati[j].style.strokeDashoffset = offsetFinali[j];
            }
        });
    });

    /* Quante corsie ci sono decide quanto spazio resta al centro: con quattro
       famiglie il foro interno scende a circa 13px, e la spunta da 17px ci
       finirebbe sopra. Il numero passa al CSS, che rimpicciolisce l'icona. */
    ring.dataset.n = dati.length;

    /* tutto completo: la spunta al centro si accende */
    var tutto = dati.length > 0 && dati.every(function (d) { return d.total > 0 && d.done >= d.total; });
    ring.classList.toggle('pl-ring-full', tutto);

    var descr = dati.map(function (d) { return d.label + ' ' + d.done + ' su ' + d.total; }).join(', ');
    ring.setAttribute('aria-label', descr || 'Nessuna abitudine');
}

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
        if (S.adSlim) {
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

/* un tocco fuori dalla sezione priorità la richiude */
document.addEventListener('pointerdown', function (e) {
    if (S.prioSlim) return;
    if (e.target.closest('#plPrio')) return;
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

        var suoi = eventsOf(date).filter(function (e) { return e.all_day; });
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

        /* Da 'lista' non c'è densità da moltiplicare: si rientra negli orari
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
    api('event_save', payload).then(function (r) {
        if (!r.ok) { toast('Non è stato possibile inserirlo.'); return; }
        indexEvents([r.event]);
        renderDays();
        if (a.kind === 'habit') { delete S.rings[date]; renderRing(); }
        haptic(12);
        scheduleSync();
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
    box.appendChild(el('div', 'pl-zoom-lab', 'Scatti'));
    ZOOM.forEach(function (z) {
        var short = z.key === 'agenda' ? 'lista'
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
                && evNode.dataset.miniGroup !== '1') {
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
        var zone = 110, max = 16, speed = 0;
        if (lastY > vh - zone)       speed =  Math.min(max, (lastY - (vh - zone)) / 4);
        else if (lastY < top + zone) speed = -Math.min(max, ((top + zone) - lastY) / 4);
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
            /* niente da salvare: se il blocco era passato da un'altra colonna e
               poi è tornato, lo rimette a posto il ridisegno */
            if (reparented) renderDays(); else node.style.top = startTop + 'px';
            return;
        }

        if (group.length > 1) {
            /* tutti i passaggi si spostano dello stesso scarto, così le
               distanze fra loro restano quelle di prima */
            var shift = toMin(ns) - blockStart;
            api('events_shift', {
                ids: group.map(function (x) { return x.id; }),
                minutes: Math.abs(shift),
                direction: shift > 0 ? 'late' : 'early'
            }).then(function (r) {
                if (!r.ok) { node.style.top = startTop + 'px'; return; }
                /* Il server limita lo scarto una volta sola guardando il pezzo
                   più estremo: contro la mezzanotte il blocco non si muove
                   affatto e tornerebbe dov'era senza spiegazioni. */
                if (r.moved === 0) toast('Il blocco non ci sta più in giornata.');
                S.weekCache = {};
                loadWeek(monday(date), true).then(renderDays);
                scheduleSync();
            });
            return;
        }

        api('event_move', { id: id, date: curDate, start: ns }).then(function (r) {
            if (!r.ok) {
                /* rifiutato dal server: il blocco torna dov'era, colonna compresa */
                node.style.top = startTop + 'px';
                if (reparented) { node.dataset.date = date; renderDays(); }
                return;
            }
            ev.start = r.start; ev.end = r.end;
            if (dayChanged) {
                /* La copia in memoria è indicizzata per data: senza spostarla di
                   mano il ridisegno successivo rimetterebbe l'evento nella
                   colonna di partenza, e il giorno nuovo resterebbe vuoto fino
                   al prossimo caricamento. Il confronto è sull'identificativo e
                   non sull'oggetto: se nel frattempo è arrivata una ricarica di
                   sfondo, la copia in elenco non è più quella che abbiamo in
                   mano. */
                var vecchi = S.events[date] || [];
                for (var vi = vecchi.length - 1; vi >= 0; vi--) {
                    if (vecchi[vi] === ev || vecchi[vi].id === id) vecchi.splice(vi, 1);
                }
                ev.date = curDate;
                if (!S.events[curDate]) S.events[curDate] = [];
                S.events[curDate] = S.events[curDate].filter(function (x) { return x.id !== id; });
                S.events[curDate].push(ev);
                /* Il pannello settimana e i conteggi vengono dalla cache della
                   settimana: se il giorno nuovo cade in un'altra settimana quella
                   cache non è più vera e va rifatta. */
                if (monday(curDate) !== monday(date)) {
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true).then(renderDays);
                }
            }
            renderDays();
            scheduleSync();
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
        .filter(function (e) { return !e.all_day; })
        .map(function (e) { return { s: toMin(e.start), e: toMin(e.end) }; })
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
    wrap.appendChild(el('div', 'pl-row-sub', durLabel(r.total) + ' in tutto, ' + r.items.length + ' passaggi. A che ora inizia?'));
    var inp = el('input', 'pl-in');
    inp.type = 'time'; inp.step = '300'; inp.value = defaultStart;
    inp.style.marginTop = '10px'; inp.style.textAlign = 'center';
    wrap.appendChild(inp);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', 'Inserisci'); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = inp.value || defaultStart;
        ov.classList.remove('pl-open');
        api('routine_insert', { routine_id: r.id, date: S.focusDate, start: start }).then(function (res) {
            if (!res.ok) { toast(res.message || 'Non è stato possibile inserire la routine.'); return; }
            indexEvents(res.events, { from: S.focusDate, to: S.focusDate });
            renderDays();
            haptic(14);
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
    wrap.appendChild(el('div', 'pl-row-sub', 'Durata ' + durLabel(dur) + '. A che ora la metto?'));
    var inp = el('input', 'pl-in');
    inp.type = 'time'; inp.step = '300'; inp.value = defaultStart;
    inp.style.marginTop = '10px'; inp.style.textAlign = 'center';
    wrap.appendChild(inp);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', 'Inserisci'); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = toMin(inp.value || defaultStart);
        ov.classList.remove('pl-open');
        api('event_save', {
            title: h.name, date: S.focusDate, start: toHM(start),
            end: toHM(Math.min(1439, start + dur)), habit_id: h.id,
            type: 'task', color: h.color, notify: 'none'
        }).then(function (r) {
            if (!r.ok) { toast('Non è stato possibile aggiungere l\'abitudine.'); return; }
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

    openSheet(routine ? routine.name : 'Routine', function (body) {
        var d = parseIso(date);
        body.appendChild(el('div', 'pl-row-sub', d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()
            + '  ·  ' + items[0].start + ' – ' + items[items.length - 1].end));

        var rows = el('div', 'pl-rows');
        rows.style.marginTop = '12px';
        items.forEach(function (it) { rows.appendChild(routineStepRow(it, group, date)); });
        body.appendChild(rows);

        var delAll = el('button', 'pl-btn pl-btn-danger', 'Elimina tutta la routine');
        delAll.type = 'button';
        delAll.style.marginTop = '18px';
        delAll.addEventListener('click', function () {
            alertBox('Elimino tutta la routine?', 'Tutti i passaggi di questo blocco spariranno dall\'agenda.', [
                { label: 'Annulla' },
                { label: 'Elimina', kind: 'danger', onClick: function () {
                    api('event_delete', { id: items[0].id, scope: 'routine' }).then(function (r) {
                        if (!r.ok) { toast('Non è stato possibile eliminare.'); return; }
                        S.events[date] = (S.events[date] || []).filter(function (e) { return e.routine_group !== group; });
                        closeOv('plSheetOv');
                        renderDays();
                    });
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
            if (!r.ok) { toast('Non salvato.'); dur.value = toMin(it.end) - toMin(it.start); return; }
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
            if (!r.ok) { toast('Non è stato possibile eliminare.'); return; }
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
        api('event_toggle', { id: ev.id }).then(function (r) {
            if (!r.ok) return;
            ev.done = r.done;
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
        });
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
        window.location.href = 'pomodoro.php?project_id=' + encodeURIComponent(act.dataset.project);
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

/* ------------------------------------------------------------------ */
/* Popup dell'evento                                                   */
/* ------------------------------------------------------------------ */
function openEventSheet(existing, seed) {
    var d = existing ? {
        id: existing.id, title: existing.title, notes: existing.notes || '',
        location: existing.location || '', guests: (existing.guests || []).slice(),
        place_name: existing.place_name || null,
        place_lat: (existing.place_lat === 0 || existing.place_lat) ? existing.place_lat : null,
        place_lon: (existing.place_lon === 0 || existing.place_lon) ? existing.place_lon : null,
        place_ref: existing.place_ref || null,
        /* il lucchetto arriva dal server; per gli eventi salvati prima della
           colonna il valore manca, e allora vale chiuso come per i nuovi */
        duration_locked: existing.duration_locked !== false,
        calendar_id: existing.calendar_id || null,
        date: existing.date,
        start: existing.start, end: existing.end, all_day: existing.all_day,
        category_id: existing.category_id, project_id: existing.project_id,
        project_name: existing.project_name, project_color: existing.project_color,
        importance: existing.importance, color: existing.color, type: existing.type,
        notify: existing.notify, notify_min: existing.notify_min,
        repeat: existing.repeat, repeat_interval: existing.repeat_interval,
        repeat_unit: existing.repeat_unit, repeat_until: existing.repeat_until,
        habit_id: existing.habit_id
    } : {
        id: 0, title: '', notes: '', location: '', guests: [], calendar_id: null,
        place_name: null, place_lat: null, place_lon: null, place_ref: null,
        duration_locked: false,  // si parte da 'auto': la durata segue l'inizio, non la fine
        date: seed.date, start: seed.start, end: seed.end,
        all_day: false, category_id: S.defaultCat, project_id: null, importance: 'normal',
        color: null, type: 'event', notify: 'h1', notify_min: 60,
        repeat: 'none', repeat_interval: 1, repeat_unit: 'week', repeat_until: null
    };
    S.draft = { data: d, dirty: false, isNew: !existing, origDate: existing ? existing.date : null };
    // Salviamo la regola originale per capire se viene modificata
    S.draft.origRepeat = existing ? {
        repeat: existing.repeat,
        repeat_interval: existing.repeat_interval,
        repeat_unit: existing.repeat_unit,
        repeat_until: existing.repeat_until
    } : null;

    $('plEvKicker').textContent = existing ? 'Modifica' : 'Nuovo evento';
    $('plEvTrash').classList.toggle('pl-hidden', !existing);
    renderEventSheet();
    openOv('plEventOv');
    /* nuovo evento: il titolo è già pronto con la tastiera aperta, così si
       scrive subito senza un tocco in più */
    if (!existing) {
        var focusTitle = function () {
            var i = $('plEvTitle');
            if (i) { i.focus(); try { i.setSelectionRange(0, 0); } catch (e) {} }
        };
        focusTitle();
        setTimeout(focusTitle, 60);
        setTimeout(focusTitle, 420);
    }
}

function tintSheet() {
    var d = S.draft.data;
    var color = d.color || (d.category_id ? (catById(d.category_id) || {}).color : null) || d.project_color || NEUTRAL;
    $('plEventSheet').style.setProperty('--ev', color);
}

function renderEventSheet() {
    var d = S.draft.data;
    var body = $('plEvBody');
    body.innerHTML = '';
    tintSheet();

    /* titolo + progetto */
    var f1 = el('div', 'pl-field');
    var trow = el('div', 'pl-title-row');
    var title = el('input', 'pl-in');
    title.type = 'text'; title.id = 'plEvTitle'; title.placeholder = 'Titolo'; title.maxLength = 180;
    title.value = d.title;
    title.addEventListener('input', function () {
        d.title = title.value;
        S.draft.dirty = true;
        /* il luogo può arrivare da solo, se il titolo nomina un posto della
           rubrica (la funzione sta più in basso, nella parte del luogo) */
        agganciaLuogo();
    });
    trow.appendChild(title);
    var pbtn = el('button', 'pl-icon-btn');
    pbtn.type = 'button';
    pbtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7.5A1.5 1.5 0 015.5 6h4l1.6 2H18a1.5 1.5 0 011.5 1.5v7A1.5 1.5 0 0118 18H5.5A1.5 1.5 0 014 16.5z"/></svg>';
    pbtn.addEventListener('click', function () {
        var modal = $('ps-modal-plev');
        if (modal && window.psOpen) window.psOpen(modal);
        else toast('Selettore progetti non disponibile.');
    });
    trow.appendChild(pbtn);
    f1.appendChild(trow);
    if (d.project_name) {
        var pn = el('div', 'pl-row-sub', 'Progetto: ' + d.project_name);
        pn.style.margin = '6px 2px 0';
        f1.appendChild(pn);
    }
    /* Evento o task, subito sotto il titolo e su una riga sola.
       Stava in fondo, in un campo con l'etichetta sopra e il selettore a capo:
       occupava l'altezza di un campo intero per una scelta fra due parole, e
       si trovava dopo aver scorso tutto il popup. Qui è la seconda cosa che si
       decide di un evento, ed è giusto che stia dove la si decide. */
    var trow2 = el('div', 'pl-tipo-riga');
    trow2.appendChild(el('span', 'pl-tipo-lab', 'Tipo'));
    trow2.appendChild(seg([['event', 'Evento'], ['task', 'Task']], d.type, function (v) {
        d.type = v; S.draft.dirty = true; renderEventSheet();
    }));
    f1.appendChild(trow2);
    body.appendChild(f1);

    /* data, orari, durata */
    var fd = el('div', 'pl-field');
    fd.appendChild(el('label', 'pl-label', 'Quando'));
    var rDate = rowSB('Data', dateInput(d.date, function (v) { d.date = v; S.draft.dirty = true; }));
    fd.appendChild(rDate);

    var allRow = rowSB('Tutto il giorno', toggle(d.all_day, function (v) {
        d.all_day = v;
        /* Togliendo la spunta restavano 00:00–23:59, cioè un evento di tutto
           il giorno con un altro nome. Gli si dà un'ora vera: la prima libera
           a partire da quella che si sta guardando. Il controllo sugli orari
           serve a non buttare via un orario già scelto e poi ripreso. */
        if (!v && (!d.start || d.start === '00:00') && (!d.end || d.end === '23:59')) {
            var inizio = firstFreeMinute(60);
            d.start = toHM(inizio);
            d.end = toHM(Math.min(1439, inizio + 60));
        }
        S.draft.dirty = true; renderEventSheet();
    }));
    fd.appendChild(allRow);

    if (!d.all_day) {
        /* Gli orari si aggiornano fra loro senza ridisegnare il popup:
           ricostruirlo a ogni cambio faceva perdere il fuoco al campo e
           rimetteva il valore di partenza appena si staccava il dito. */
        var sIn, eIn, durIn;
        /* TRE STATI, non due.
           - 'auto' (leggerissimo, chiuso): spostando l'inizio la durata resta
             e la fine segue; spostando la fine l'inizio non si muove e la
             durata cambia. È il comportamento che ci si aspetta senza
             pensarci, ed è quello di partenza.
           - 'chiuso' (deciso): la durata è ferma sempre, quindi spostando la
             fine si trascina anche l'inizio.
           - 'aperto' (chiaro): la durata non è mai ferma, quindi anche
             spostando l'inizio la fine resta dov'è.
           Aprendo un evento si riparte sempre da 'auto', anche se l'ultima
           volta era stato chiuso: il lucchetto dice come si comporteranno i
           campi *adesso*, non che aria tirava l'altra volta, e ritrovarselo
           chiuso senza averlo toccato spostava orari che non si volevano
           spostare. Nel database resta il sì/no `duration_locked`, che ormai
           serve solo a chi legge l'evento da fuori. */
        var stato = 'auto';
        d.duration_locked = false;
        function refreshTimes(skip) {
            if (sIn && skip !== 'start') sIn.value = d.start;
            if (eIn && skip !== 'end') eIn.value = d.end;
            if (durIn && skip !== 'dur') durIn.value = toHM(Math.min(1439, toMin(d.end) - toMin(d.start)));
            tintSheet();
        }
        sIn = timeInput(d.start, function (v) {
            var dur = Math.max(5, toMin(d.end) - toMin(d.start));
            d.start = v;
            /* con il lucchetto aperto la fine resta dov'è; negli altri due
               stati spostare l'inizio porta con sé la fine */
            if (stato !== 'aperto') {
                d.end = toHM(Math.min(1439, toMin(v) + dur));
            } else if (toMin(d.end) <= toMin(v)) {
                /* aperto: la fine resta dov'è, ma non può cadere prima
                   dell'inizio, altrimenti l'evento avrebbe durata negativa */
                d.end = toHM(Math.min(1439, toMin(v) + 5));
            }
            S.draft.dirty = true;
            refreshTimes('start');
        });
        eIn = timeInput(d.end, function (v) {
            /* solo il lucchetto chiuso trascina l'inizio: negli altri due
               stati la fine si sposta da sola e la durata cambia */
            if (stato === 'chiuso') {
                /* chiuso: spostando la fine si trascina anche l'inizio */
                var dur2 = Math.max(5, toMin(d.end) - toMin(d.start));
                var fine = Math.min(1439, toMin(v));
                var inizio = fine - dur2;
                if (inizio < 0) { inizio = 0; fine = Math.min(1439, dur2); }
                d.start = toHM(inizio);
                d.end = toHM(fine);
            } else {
                if (toMin(v) <= toMin(d.start)) v = toHM(toMin(d.start) + 5);
                d.end = v;
            }
            S.draft.dirty = true;
            refreshTimes('end');
        });
        /* Durata in mezzo, fra inizio e fine: è quello che sta fra i due,
           e vederla altrove costringeva a ricostruire l'ordine a mente. La
           scritta \"Durata\" sta a destra insieme al campo, in corsivo, per
           non farsi scambiare per una terza voce alla pari delle altre. */
        var dur = toMin(d.end) - toMin(d.start);
        durIn = el('input', 'pl-pick');
        durIn.type = 'time'; durIn.step = '300';
        durIn.style.width = '104px'; durIn.style.textAlign = 'center';
        durIn.value = toHM(Math.min(1439, dur));
        durIn.addEventListener('change', function () {
            var mins = toMin(durIn.value);
            if (!mins) { durIn.value = toHM(toMin(d.end) - toMin(d.start)); return; }
            /* cambiare la durata a mano muove sempre la fine: è l'inizio il
               riferimento che l'utente ha già fissato */
            d.end = toHM(Math.min(1439, toMin(d.start) + mins));
            S.draft.dirty = true;
            refreshTimes('dur');
        });

        var lock = el('button', 'pl-lock');
        lock.type = 'button';
        function drawLock() {
            lock.className = 'pl-lock pl-lock-' + stato;
            lock.title = stato === 'chiuso'
                ? 'Durata bloccata: spostando un orario si sposta anche l’altro'
                : (stato === 'aperto'
                    ? 'Durata libera: spostando un orario l’altro resta fermo'
                    : 'Spostando l’inizio la durata resta; spostando la fine cambia');
            lock.setAttribute('aria-label', lock.title);
            lock.setAttribute('aria-pressed', stato === 'chiuso' ? 'true' : 'false');
            /* Tre stati, tre disegni. Il corpo del lucchetto è sempre lo
               stesso: cambia l'archetto (chiuso o scostato) e, sul terzo
               stato, una riga che lo sbarra.
               La sbarra sta sullo stato leggerissimo perché è l'unico in cui
               la durata non è né sempre ferma né mai ferma: dipende da quale
               dei due orari si tocca. Un lucchetto e basta, per quanto
               pallido, sembrava una via di mezzo fra gli altri due; sbarrato
               si legge come «non deciso», che è quello che è. */
            var arco = stato === 'aperto'
                ? '<path d="M8.5 11V8a3.5 3.5 0 016.9-1.2"/>'
                : '<path d="M8.5 11V8a3.5 3.5 0 017 0v3"/>';
            var sbarra = stato === 'auto' ? '<path d="M4.6 19.4L19.4 4.6"/>' : '';
            lock.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                + 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
                + '<rect x="5" y="11" width="14" height="9" rx="2.2"/>' + arco + sbarra + '</svg>';
        }
        drawLock();
        lock.addEventListener('click', function () {
            /* Il giro: leggerissimo → deciso → chiaro → leggerissimo. */
            stato = stato === 'auto' ? 'chiuso' : (stato === 'chiuso' ? 'aperto' : 'auto');
            d.duration_locked = (stato === 'chiuso');
            S.draft.dirty = true;
            drawLock();
            haptic(8);
        });

        fd.appendChild(rowSB('Inizio', sIn));

        var durRow = el('div', 'pl-row-sb');
        durRow.appendChild(el('span', 'pl-row-lab'));   // vuota: la scritta sta a destra
        var durBox = el('div', 'pl-durbox');
        durBox.appendChild(el('span', 'pl-dur-lab', 'Durata'));
        durBox.appendChild(lock);
        durBox.appendChild(durIn);
        durRow.appendChild(durBox);
        fd.appendChild(durRow);

        fd.appendChild(rowSB('Fine', eIn));
    }
    body.appendChild(fd);

    /* ripetizione */
    var fr = el('div', 'pl-field');
    fr.appendChild(el('label', 'pl-label', 'Ripeti'));
    var repSel = select([
        ['none', 'Mai'], ['daily', 'Ogni giorno'], ['weekly', 'Ogni settimana'],
        ['monthly', 'Ogni mese'], ['yearly', 'Ogni anno'], ['custom', 'Personalizzato']
    ], d.repeat, function (v) { d.repeat = v; S.draft.dirty = true; renderEventSheet(); });
    fr.appendChild(rowSB('Frequenza', repSel));
    if (d.repeat === 'custom') {
        var wrap = el('div', 'pl-row');
        var n = el('input', 'pl-pick');
        n.type = 'number'; n.min = '1'; n.max = '365'; n.value = d.repeat_interval || 1; n.style.width = '76px';
        n.addEventListener('change', function () { d.repeat_interval = Math.max(1, parseInt(n.value, 10) || 1); S.draft.dirty = true; });
        wrap.appendChild(el('span', 'pl-row-sub', 'ogni'));
        wrap.appendChild(n);
        wrap.appendChild(select([['day', 'giorni'], ['week', 'settimane'], ['month', 'mesi'], ['year', 'anni']], d.repeat_unit,
            function (v) { d.repeat_unit = v; S.draft.dirty = true; }));
        fr.appendChild(rowSB('Intervallo', wrap));
    }
    if (d.repeat !== 'none') {
        var untilWrap = el('div');
        if (!d.repeat_until && !S.draft.editing_until) {
            var infBtn = el('button', 'pl-pick');
            infBtn.type = 'button';
            infBtn.textContent = '∞ Infinito';
            infBtn.addEventListener('click', function() {
                S.draft.editing_until = true;
                renderEventSheet();
            });
            untilWrap.appendChild(infBtn);
        } else {
            var dInp = el('input', 'pl-pick');
            dInp.type = 'date';
            dInp.value = d.repeat_until || '';
            dInp.addEventListener('change', function() {
                d.repeat_until = dInp.value;
                S.draft.dirty = true;
                S.draft.editing_until = false;
                renderEventSheet();
            });
            dInp.addEventListener('blur', function() {
                // Se l'utente clicca fuori e ha lasciato il campo vuoto, torna a infinito
                if (!dInp.value) {
                    S.draft.editing_until = false;
                    renderEventSheet();
                }
            });
            untilWrap.appendChild(dInp);
            
            // Auto-focus e apertura del calendario nativo se è stato appena cliccato
            if (S.draft.editing_until) {
                setTimeout(function() { 
                    dInp.focus(); 
                    try { dInp.showPicker(); } catch(e) {} 
                }, 50);
            }
        }
        fr.appendChild(rowSB('Fino al', untilWrap));
    }
    body.appendChild(fr);

    /* calendario */
    if (S.calendars.length > 1) {
        var fcal = el('div', 'pl-field');
        fcal.appendChild(el('label', 'pl-label', 'Calendario'));
        
        var calOptions = [];
        var activeCalStr = '';
        
        alpha(S.calendars, 'name').forEach(function (c) {
            calOptions.push([String(c.id), c.name]);
            if (d.calendar_id ? d.calendar_id === c.id : c.is_default) {
                activeCalStr = String(c.id);
            }
        });
        
        var calSel = select(calOptions, activeCalStr, function (v) {
            d.calendar_id = parseInt(v, 10);
            S.draft.dirty = true;
            renderEventSheet();
        });
        calSel.style.width = '100%';
        
        fcal.appendChild(calSel);
        body.appendChild(fcal);
    }

    /* categoria */
    var fc = el('div', 'pl-field');
    fc.appendChild(el('label', 'pl-label', 'Categoria'));
    var tags = el('div', 'pl-tags');
    if (d.project_id && d.project_name) {
        tags.appendChild(tag(d.project_name, d.project_color || NEUTRAL, d.category_id === null, function () {
            d.category_id = null; S.draft.dirty = true; renderEventSheet();
        }));
    }
    alpha(S.categories, 'name').forEach(function (c) {
        tags.appendChild(tag(c.name, c.color, d.category_id === c.id, function () {
            d.category_id = d.category_id === c.id ? null : c.id;
            d.color = null;
            S.draft.dirty = true;
            renderEventSheet();
        }));
    });
    fc.appendChild(tags);
    body.appendChild(fc);

    /* importanza */
    var fi = el('div', 'pl-field');
    fi.appendChild(el('label', 'pl-label', 'Importanza'));
    fi.appendChild(seg([['normal', 'Normale'], ['high', 'Importante'], ['highest', 'Importantissimo']], d.importance, function (v) {
        d.importance = v; S.draft.dirty = true; renderEventSheet();
    }));
    body.appendChild(fi);

    /* notifica */
    var fn = el('div', 'pl-field');
    fn.appendChild(el('label', 'pl-label', 'Notifica'));
    fn.appendChild(rowSB('Avvisami', select([
        ['none', 'Nessuna'], ['at_time', 'Nel momento'], ['m30', '30 minuti prima'],
        ['h1', "Un'ora prima"], ['d1', 'Un giorno prima'], ['custom', 'Personalizzata']
    ], d.notify, function (v) {
        d.notify = v; S.draft.dirty = true;
        /* Scegliere un avviso è il momento buono per chiedere il permesso:
           qui la richiesta si spiega da sé, all'apertura della pagina no. */
        if (v !== 'none') PL_NOTIFY.chiediPermesso();
        renderEventSheet();
    })));
    if (d.notify !== 'none' && 'Notification' in window && Notification.permission !== 'granted') {
        var avviso = el('div', 'pl-row-sub');
        avviso.style.margin = '4px 2px 0';
        avviso.textContent = Notification.permission === 'denied'
            ? 'Gli avvisi sono bloccati per questo sito: si riattivano dalle impostazioni del browser.'
            : 'Per ricevere gli avvisi serve dare il permesso al browser.';
        fn.appendChild(avviso);
    }
    if (d.notify === 'custom') {
        var nm = el('input', 'pl-pick');
        nm.type = 'number'; nm.min = '0'; nm.max = '40320'; nm.value = d.notify_min || 60; nm.style.width = '96px';
        nm.addEventListener('change', function () { d.notify_min = Math.max(0, parseInt(nm.value, 10) || 0); S.draft.dirty = true; });
        fn.appendChild(rowSB('Minuti prima', nm));
    }
    body.appendChild(fn);

    /* colore */
    var fcol = el('div', 'pl-field');
    fcol.appendChild(el('label', 'pl-label', 'Colore'));
    var sw = el('div', 'pl-swatches');
    var base = d.category_id ? (catById(d.category_id) || {}).color : (d.project_color || NEUTRAL);
    [base, '#C7DED6', '#F0D3D6', '#D8D3E8', '#F3E4C0', '#CFDDEA', '#E8D5C7', '#DCE7C8'].forEach(function (c, i) {
        var b = el('button', 'pl-sw' + ((d.color === c || (i === 0 && !d.color)) ? ' pl-on' : ''));
        b.type = 'button';
        b.style.setProperty('--ev', c);
        b.addEventListener('click', function () {
            d.color = i === 0 ? null : c;
            S.draft.dirty = true;
            renderEventSheet();
        });
        sw.appendChild(b);
    });
    fcol.appendChild(sw);
    body.appendChild(fcol);

    /* luogo */
    var floc = el('div', 'pl-field');
    floc.appendChild(el('label', 'pl-label', 'Luogo'));
    var locRow = el('div', 'pl-title-row');
    var locIn = el('input', 'pl-in');
    locIn.type = 'text';
    locIn.placeholder = 'Cerca un indirizzo o un posto';
    locIn.maxLength = 255;
    locIn.autocomplete = 'off';
    locIn.value = d.location || '';
    locRow.appendChild(locIn);
    var locBtn = el('button', 'pl-icon-btn');
    locBtn.type = 'button';
    locBtn.setAttribute('aria-label', 'Apri nella mappa');
    locBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6.5-5.6-6.5-10a6.5 6.5 0 1113 0c0 4.4-6.5 10-6.5 10z"/><circle cx="12" cy="11" r="2.2"/></svg>';
    locBtn.addEventListener('click', function () {
        /* con le coordinate si apre il punto esatto; senza, resta la ricerca
           per testo, che è quello che si poteva fare prima */
        var q = (d.place_lat !== null && d.place_lat !== undefined && d.place_lon !== null)
            ? (d.place_lat + ',' + d.place_lon)
            : ((locIn.value || '').trim() || 'vicino a me');
        window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q),
                    '_blank', 'noopener');
    });
    locRow.appendChild(locBtn);

    /* Rubrica: i posti di tutti i giorni non si cercano ogni volta. Da qui si
       sceglie uno di quelli salvati e si salva quello agganciato adesso. */
    var rubBtn = el('button', 'pl-icon-btn');
    rubBtn.type = 'button';
    rubBtn.setAttribute('aria-label', 'Luoghi salvati');
    rubBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10a1 1 0 011 1v15l-6-3.5L6 20V5a1 1 0 011-1z"/></svg>';
    rubBtn.addEventListener('click', apriRubrica);
    locRow.appendChild(rubBtn);
    floc.appendChild(locRow);

    /* Riga sotto il campo: dice se il posto è agganciato a una posizione vera
       o è solo testo scritto a mano. Senza questa riga non si capisce se il
       pulsante della mappa aprirà il punto giusto. */
    var locStato = el('div', 'pl-row-sub pl-loc-stato');
    var locSugg = el('div', 'pl-sugg pl-hidden');
    var locMappa = el('div', 'pl-loc-mappa pl-hidden');
    var richiesta = 0;   // le risposte lente non devono coprire quelle nuove
    var timerLuogo = null;
    var inAttesa = false;   // coordinate del posto scelto: chiamata in corso

    /* La mappa vera del posto, quella che si vede sui siti dei ristoranti.
       È un riquadro di Google (servizio Embed): l'unico che può stare in
       pagina, perché la sua chiave è legata al dominio e non si paga. Se la
       chiave non c'è in config.php il riquadro non compare e basta.
       Attenzione: questo riquadro lo carica il browser, quindi qui Google
       vede chi sta guardando — la ricerca, invece, continua a passare dal
       server. Si mostra solo a posto agganciato, mai mentre si scrive. */
    function drawMappa() {
        var chiave = B.map_key || '';
        var ok = chiave && d.place_lat !== null && d.place_lat !== undefined && d.place_lon !== null;
        locMappa.classList.toggle('pl-hidden', !ok);
        if (!ok) { locMappa.innerHTML = ''; locMappa.dataset.src = ''; return; }
        /* con l'identificativo del posto si vede la scheda giusta (nome e
           recensioni); con le sole coordinate, il punto sulla mappa */
        var q = d.place_ref ? ('place_id:' + d.place_ref) : (d.place_lat + ',' + d.place_lon);
        var src = 'https://www.google.com/maps/embed/v1/place?key=' + encodeURIComponent(chiave)
                + '&q=' + encodeURIComponent(q) + '&language=it&zoom=16';
        if (locMappa.dataset.src === src) return;   // è già quella giusta: non ricaricarla
        locMappa.dataset.src = src;
        locMappa.innerHTML = '';
        var f = document.createElement('iframe');
        f.src = src;
        f.loading = 'lazy';
        f.title = 'Mappa del luogo';
        f.setAttribute('allowfullscreen', '');
        /* la chiave è ristretta al dominio: se il browser non lo dichiara,
           Google rifiuta il riquadro */
        f.referrerPolicy = 'no-referrer-when-downgrade';
        locMappa.appendChild(f);
    }

    function drawStato() {
        locStato.innerHTML = '';
        if (inAttesa) {
            locStato.textContent = 'Cerco la posizione…';
            drawMappa();
            return;
        }
        if (d.place_lat === null || d.place_lat === undefined || d.place_lon === null) {
            locStato.textContent = locIn.value.trim() ? 'Posto scritto a mano, senza posizione.' : '';
            drawMappa();
            return;
        }
        var pin = el('span', null, '📍 ' + (d.place_name || 'Posizione salvata'));
        var via = el('button', 'pl-linkish', 'togli');
        via.type = 'button';
        via.addEventListener('click', function () {
            /* il testo resta: si toglie solo l'aggancio alla mappa */
            d.place_lat = null; d.place_lon = null; d.place_ref = null; d.place_name = null;
            S.draft.dirty = true;
            drawStato();
        });
        locStato.appendChild(pin);
        locStato.appendChild(via);
        drawMappa();
    }

    function chiudiSugg() { locSugg.classList.add('pl-hidden'); locSugg.innerHTML = ''; }

    function cercaLuoghi(q) {
        var mia = ++richiesta;
        api('place_search', null, { q: q }).then(function (r) {
            if (mia !== richiesta || !S.draft) return;      // risposta sorpassata
            locSugg.innerHTML = '';
            if (!r.ok || !r.places || !r.places.length) {
                locSugg.appendChild(el('div', 'pl-sugg-vuoto', 'Nessun posto trovato.'));
                locSugg.classList.remove('pl-hidden');
                return;
            }
            r.places.forEach(function (p) {
                var b = el('button', 'pl-sugg-row');
                b.type = 'button';
                b.appendChild(el('div', 'pl-row-lab', p.name));
                if (p.detail) b.appendChild(el('div', 'pl-row-sub', p.detail));
                /* pointerdown e non click: il campo perde il fuoco appena si
                   tocca, e con il click l'elenco sarebbe già sparito */
                b.addEventListener('pointerdown', function (e) {
                    e.preventDefault();
                    d.location = p.full || p.name;
                    d.place_name = p.name;
                    d.place_ref = p.ref || null;
                    locIn.value = d.location;
                    S.draft.dirty = true;
                    chiudiSugg();
                    haptic(8);

                    if (p.lat !== null && p.lat !== undefined) {
                        /* la scorta (OpenStreetMap) manda già le coordinate
                           insieme ai suggerimenti: niente seconda chiamata */
                        d.place_lat = p.lat;
                        d.place_lon = p.lon;
                        drawStato();
                        return;
                    }

                    /* Google le dà solo per il posto che si sceglie davvero:
                       è questa la chiamata che si paga (quando si pagherà), e
                       parte una volta sola, non per tutti i suggerimenti. */
                    d.place_lat = null; d.place_lon = null;
                    inAttesa = true;
                    drawStato();
                    var quale = d.place_ref;
                    api('place_details', null, { ref: quale }).then(function (r) {
                        inAttesa = false;
                        /* nel frattempo si può aver scelto un altro posto o
                           chiuso la scheda: la risposta vecchia non comanda */
                        if (!S.draft || d.place_ref !== quale) return;
                        if (r.ok && r.place) {
                            d.place_lat = r.place.lat;
                            d.place_lon = r.place.lon;
                            S.draft.dirty = true;
                        } else {
                            toast('Posizione non trovata: resta il testo.');
                        }
                        drawStato();
                    });
                });
                locSugg.appendChild(b);
            });
            locSugg.classList.remove('pl-hidden');
        });
    }

    locIn.addEventListener('input', function () {
        d.location = locIn.value;
        /* Scrivendo a mano si perde l'aggancio: tenere le vecchie coordinate
           accanto a un indirizzo diverso sarebbe peggio che non averle. */
        if (d.place_lat !== null && d.place_lat !== undefined) {
            d.place_lat = null; d.place_lon = null; d.place_ref = null; d.place_name = null;
        }
        /* se una ricerca di coordinate era in volo, quando torna non troverà
           più il suo posto e si fermerà da sola: qui si toglie solo l'attesa */
        inAttesa = false;
        S.draft.dirty = true;
        drawStato();
        var q = locIn.value.trim();
        clearTimeout(timerLuogo);
        if (q.length < 3) { chiudiSugg(); return; }
        /* mezzo secondo di attesa: si cerca a frase finita, non a ogni lettera,
           per non tempestare il servizio delle mappe. Timer suo e non la coda
           dei salvataggi: alla chiusura della pagina una ricerca non ha senso
           che parta lo stesso. */
        timerLuogo = setTimeout(function () { cercaLuoghi(q); }, 500);
    });
    locIn.addEventListener('blur', function () { setTimeout(chiudiSugg, 150); });

    /* Mette nell'evento un posto della rubrica: testo, nome e coordinate in
       un colpo solo, come se lo avessi appena scelto dalla ricerca. */
    function applicaPosto(p) {
        d.location   = p.address || p.name || p.label;
        d.place_name = p.name || p.label;
        d.place_lat  = (p.lat === 0 || p.lat) ? p.lat : null;
        d.place_lon  = (p.lon === 0 || p.lon) ? p.lon : null;
        d.place_ref  = p.ref || null;
        locIn.value  = d.location;
        inAttesa = false;
        S.draft.dirty = true;
        chiudiSugg();
        drawStato();
    }

    /* Aggancio automatico dal titolo: "palestra alle 18" si porta dietro la
       palestra. Vale solo a campo del luogo ancora vuoto — correggere una
       scelta già fatta sarebbe peggio che non aiutare — e con parole di almeno
       tre lettere, perché "a" o "in" starebbero dentro qualunque titolo. */
    function agganciaLuogo() {
        if ((locIn.value || '').trim() !== '') return;
        var t = ' ' + (d.title || '').toLowerCase() + ' ';
        var trovato = null;
        (B.places || []).forEach(function (p) {
            (p.keywords || '').split(',').forEach(function (k) {
                k = k.trim().toLowerCase();
                if (!trovato && k.length >= 3 && t.indexOf(k) >= 0) trovato = p;
            });
        });
        if (trovato) applicaPosto(trovato);
    }

    function apriRubrica() {
        openSheet('Luoghi salvati', function (bd) {
            var lista = el('div', 'pl-places');

            function draw() {
                lista.innerHTML = '';
                var ps = B.places || [];
                if (!ps.length) {
                    lista.appendChild(el('div', 'pl-row-sub',
                        'Ancora nessun posto. Scegline uno dalla ricerca e salvalo qui sotto: da allora basterà nominarlo nel titolo.'));
                }
                ps.forEach(function (p) {
                    var r = el('div', 'pl-place-row');
                    var pick = el('button', 'pl-place-pick');
                    pick.type = 'button';
                    pick.appendChild(el('div', 'pl-row-lab', p.label));
                    var sotto = (p.address || p.name || '');
                    if (p.keywords) sotto += (sotto ? '  ·  ' : '') + p.keywords;
                    if (sotto) pick.appendChild(el('div', 'pl-row-sub', sotto));
                    pick.addEventListener('click', function () {
                        applicaPosto(p);
                        closeOv('plSheetOv');
                        haptic(8);
                    });
                    var x = el('button', 'pl-icon-btn');
                    x.type = 'button';
                    x.setAttribute('aria-label', 'Togli dalla rubrica');
                    x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                    x.addEventListener('click', function () {
                        api('place_book_delete', { id: p.id }).then(function (rr) {
                            if (!rr.ok) return;
                            B.places = rr.places || [];
                            draw();
                        });
                    });
                    r.appendChild(pick); r.appendChild(x);
                    lista.appendChild(r);
                });
            }
            draw();
            bd.appendChild(lista);

            /* Si salva solo un posto agganciato a una posizione vera: senza
               coordinate la rubrica servirebbe a poco, e il pulsante della
               mappa tornerebbe a cercare per testo. */
            if (d.place_lat === null || d.place_lat === undefined) {
                bd.appendChild(el('div', 'pl-row-sub',
                    'Per salvarne uno nuovo, scegli prima un posto dalla ricerca qui sopra: servono le sue coordinate.'));
                return;
            }
            bd.appendChild(el('div', 'pl-label', 'Salva questo posto'));
            var nome = el('input', 'pl-in');
            nome.type = 'text'; nome.maxLength = 60;
            nome.placeholder = 'Come lo chiami (casa, lavoro…)';
            nome.value = d.place_name || '';
            var kw = el('input', 'pl-in');
            kw.type = 'text'; kw.maxLength = 255;
            kw.placeholder = 'Parole che lo agganciano, separate da virgola';
            var salva = el('button', 'pl-btn pl-btn-primary', 'Salva nella rubrica');
            salva.type = 'button';
            salva.addEventListener('click', function () {
                var lab = nome.value.trim();
                if (!lab) { nome.focus(); return; }
                salva.disabled = true;
                api('place_book_save', {
                    id: 0, label: lab, keywords: kw.value,
                    name: d.place_name || '', address: locIn.value || '',
                    lat: d.place_lat, lon: d.place_lon, ref: d.place_ref || ''
                }).then(function (rr) {
                    salva.disabled = false;
                    if (!rr.ok) { toast('Non sono riuscito a salvarlo.'); return; }
                    B.places = rr.places || [];
                    kw.value = '';
                    draw();
                    haptic(10);
                });
            });
            bd.appendChild(nome);
            bd.appendChild(kw);
            bd.appendChild(salva);
        });
    }

    floc.appendChild(locSugg);
    floc.appendChild(locStato);
    floc.appendChild(locMappa);
    drawStato();
    body.appendChild(floc);

    /* invitati */
    var fg = el('div', 'pl-field');
    fg.appendChild(el('label', 'pl-label', 'Invitati'));
    var gTags = el('div', 'pl-tags');
    function drawGuests() {
        gTags.innerHTML = '';
        (d.guests || []).forEach(function (g) {
            var t = el('button', 'pl-tag pl-on');
            t.type = 'button';
            t.style.setProperty('--ev', '#CFDDEA');
            t.appendChild(el('span', null, g.name || g.email));
            var x = el('span', 'pl-chip-x');
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            t.appendChild(x);
            t.addEventListener('click', function () {
                d.guests = d.guests.filter(function (y) { return y.email !== g.email; });
                S.draft.dirty = true;
                drawGuests();
            });
            gTags.appendChild(t);
        });
    }
    drawGuests();
    fg.appendChild(gTags);

    var gRow = el('div', 'pl-row');
    gRow.style.marginTop = '8px';
    var gIn = el('input', 'pl-in');
    gIn.type = 'email';
    gIn.placeholder = 'nome@esempio.it';
    gIn.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        addGuest(d, gIn.value, null, drawGuests);
        gIn.value = '';
    });
    var gBook = el('button', 'pl-icon-btn');
    gBook.type = 'button';
    gBook.setAttribute('aria-label', 'Apri la rubrica');
    gBook.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h11a1 1 0 011 1v14a1 1 0 01-1 1H7z"/><path d="M4 8h3M4 12h3M4 16h3"/><circle cx="12.5" cy="10" r="2"/><path d="M9.5 16c.4-1.6 1.6-2.5 3-2.5s2.6.9 3 2.5"/></svg>';
    gBook.addEventListener('click', function () { openRubrica(d, drawGuests); });
    gRow.appendChild(gIn); gRow.appendChild(gBook);
    fg.appendChild(gRow);

    /* Code degli indirizzi più comuni: compaiono solo mentre si scrive nel
       campo, e toccandone una completa l'indirizzo. Spariscono appena si
       esce, per non tenere occupata la schermata. */
    var domini = el('div', 'pl-tags pl-hidden');
    domini.style.marginTop = '7px';
    ['@gmail.com', '@icloud.com', '@outlook.it', '@libero.it', '@hotmail.it', '@yahoo.it', '@virgilio.it']
        .forEach(function (dom) {
            var t = el('button', 'pl-tag');
            t.type = 'button';
            t.appendChild(el('span', null, dom));
            t.addEventListener('pointerdown', function (e) {
                /* pointerdown e non click: altrimenti il campo perde il fuoco
                   e le code spariscono prima che il tocco arrivi */
                e.preventDefault();
                var v = (gIn.value || '').split('@')[0];
                gIn.value = v + dom;
                gIn.focus();
            });
            domini.appendChild(t);
        });
    fg.appendChild(domini);
    gIn.addEventListener('focus', function () { domini.classList.remove('pl-hidden'); });
    gIn.addEventListener('blur', function () {
        setTimeout(function () { domini.classList.add('pl-hidden'); }, 120);
    });

    body.appendChild(fg);

    /* note */
    var fnote = el('div', 'pl-field');
    fnote.appendChild(el('label', 'pl-label', 'Note'));
    var ta = el('textarea', 'pl-in pl-note');
    ta.placeholder = 'Qualcosa da ricordare…';
    ta.value = d.notes || '';
    ta.maxLength = 4000;
    /* Il campo si allarga con quello che ci si scrive, fino al tetto messo dal
       CSS: da lì in poi scorre. Così una nota lunga non si legge più da una
       fessura di tre righe. */
    function growNote() {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight + 2, Math.round(window.innerHeight * 0.52)) + 'px';
    }
    ta.addEventListener('input', function () {
        d.notes = ta.value; S.draft.dirty = true; growNote();
    });
    fnote.appendChild(ta);
    body.appendChild(fnote);
    requestAnimationFrame(growNote);

    if (d.id) {
        var del = el('button', 'pl-btn pl-btn-danger', 'Elimina evento');
        del.type = 'button';
        del.style.marginTop = '4px';
        del.addEventListener('click', function () { confirmDeleteEvent(d); });
        body.appendChild(del);
    }
}

/* Aggiunge un invitato alla bozza, evitando i doppioni. */
function addGuest(d, email, name, redraw) {
    var mail = String(email || '').trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { toast('Indirizzo non valido.'); return; }
    d.guests = d.guests || [];
    if (d.guests.some(function (g) { return g.email === mail; })) return;
    d.guests.push({ email: mail, name: name || null });
    S.draft.dirty = true;
    if (redraw) redraw();
}

/* La rubrica: in ordine alfabetico, con la ricerca che guarda sia il nome
   sia l'indirizzo. Da qui si invita, si aggiunge e si cancella. */
function openRubrica(draft, redraw) {
    openSheet('Rubrica', function (body) {
        var cerca = el('input', 'pl-in');
        cerca.type = 'search';
        cerca.placeholder = 'Cerca per nome o indirizzo';
        body.appendChild(cerca);

        var rows = el('div', 'pl-rows');
        rows.style.marginTop = '12px';
        body.appendChild(rows);

        function carica(q) {
            api('contacts', null, q ? { q: q } : null).then(function (r) {
                rows.innerHTML = '';
                if (!r.ok || !r.contacts.length) {
                    rows.appendChild(el('div', 'pl-quote pl-serif',
                        q ? 'Nessun contatto con questo nome.' : 'La rubrica è ancora vuota.'));
                    return;
                }
                r.contacts.forEach(function (c) {
                    var row = el('div', 'pl-srow');
                    var inner = el('div', 'pl-srow-in');
                    var txt = el('div');
                    txt.style.flex = '1';
                    txt.appendChild(el('div', 'pl-row-lab', c.name));
                    txt.appendChild(el('div', 'pl-row-sub', c.email));
                    inner.appendChild(txt);
                    inner.addEventListener('click', function () {
                        if (row.classList.contains('pl-swiped')) return;
                        if (draft) { addGuest(draft, c.email, c.name, redraw); closeOv('plSheetOv'); }
                    });
                    var acts = el('div', 'pl-srow-acts');
                    var edit = el('button', 'pl-srow-act');
                    edit.type = 'button';
                    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
                    edit.addEventListener('click', function () { editContact(c, function () { carica(cerca.value.trim()); }); });
                    var del = el('button', 'pl-srow-act pl-del');
                    del.type = 'button';
                    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
                    del.addEventListener('click', function () {
                        api('contact_delete', { id: c.id }).then(function () { carica(cerca.value.trim()); });
                    });
                    acts.appendChild(edit); acts.appendChild(del);
                    row.appendChild(inner); row.appendChild(acts);
                    enableSwipe(row, inner);
                    rows.appendChild(row);
                });
            });
        }
        carica('');
        cerca.addEventListener('input', function () {
            debounceSave('rubrica', 250, function () { carica(cerca.value.trim()); });
        });

        /* aggiungere un contatto nuovo */
        var f = el('div', 'pl-field');
        f.style.marginTop = '18px';
        f.appendChild(el('label', 'pl-label', 'Nuovo contatto'));
        var nome = el('input', 'pl-in');
        nome.type = 'text'; nome.placeholder = 'Come lo chiami: luchino'; nome.maxLength = 80;
        var mail = el('input', 'pl-in');
        mail.type = 'email'; mail.placeholder = 'lucapercassi@esempio.it';
        mail.style.marginTop = '8px';
        f.appendChild(nome); f.appendChild(mail);
        var add = el('button', 'pl-btn pl-btn-primary', 'Salva in rubrica');
        add.type = 'button';
        add.style.marginTop = '10px';
        add.addEventListener('click', function () {
            api('contact_save', { name: nome.value, email: mail.value }).then(function (r) {
                if (!r.ok) { toast(r.message || 'Non salvato.'); return; }
                nome.value = ''; mail.value = '';
                carica(cerca.value.trim());
                loadRubrica();   // così la barra intelligente lo riconosce subito
                toast('Contatto salvato.');
            });
        });
        f.appendChild(add);
        body.appendChild(f);
    });
}

/* Modifica di un contatto: nome e indirizzo, in un avviso semplice. */
function editContact(c, done) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = 'Modifica contatto';
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');
    var nome = el('input', 'pl-in');
    nome.type = 'text'; nome.value = c.name; nome.maxLength = 80;
    nome.placeholder = 'Nome';
    var mail = el('input', 'pl-in');
    mail.type = 'email'; mail.value = c.email; mail.style.marginTop = '8px';
    wrap.appendChild(nome); wrap.appendChild(mail);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var si = el('button', 'pl-btn pl-btn-primary', 'Salva'); si.type = 'button';
    si.addEventListener('click', function () {
        api('contact_save', { id: c.id, name: nome.value, email: mail.value }).then(function (r) {
            ov.classList.remove('pl-open');
            if (!r.ok) { toast(r.message || 'Non salvato.'); return; }
            loadRubrica();
            if (done) done();
        });
    });
    acts.appendChild(no); acts.appendChild(si);
    ov.classList.add('pl-open');
}

/* pezzi riusabili del popup */
function rowSB(label, control) {
    var r = el('div', 'pl-row-sb');
    r.appendChild(el('span', 'pl-row-lab', label));
    r.appendChild(control);
    return r;
}
function toggle(on, onChange) {
    var t = el('span', 'pl-toggle' + (on ? ' pl-on' : ''));
    t.addEventListener('click', function () {
        var next = !t.classList.contains('pl-on');
        t.classList.toggle('pl-on', next);
        onChange(next);
    });
    return t;
}
function dateInput(value, onChange) {
    var i = el('input', 'pl-pick');
    i.type = 'date'; i.value = value || '';
    i.addEventListener('change', function () { onChange(i.value); });
    return i;
}
function timeInput(value, onChange) {
    var i = el('input', 'pl-pick');
    i.type = 'time'; i.step = '300'; i.value = value || '';
    i.addEventListener('change', function () { onChange(i.value); });
    return i;
}
function select(options, value, onChange) {
    var s = el('select', 'pl-pick');
    options.forEach(function (o) {
        var op = el('option', null, o[1]);
        op.value = o[0];
        if (o[0] === value) op.selected = true;
        s.appendChild(op);
    });
    s.addEventListener('change', function () { onChange(s.value); });
    return s;
}
function seg(options, value, onChange) {
    var box = el('div', 'pl-seg');
    options.forEach(function (o) {
        var b = el('button', o[0] === value ? 'pl-on' : '', o[1]);
        b.type = 'button';
        b.addEventListener('click', function () { onChange(o[0]); });
        box.appendChild(b);
    });
    return box;
}
/* cursore a scatti: mostra il valore e lo cambia trascinando */
function slider(min, max, value, onChange) {
    var wrap = el('div', 'pl-row');
    var input = el('input');
    input.type = 'range';
    input.min = String(min); input.max = String(max); input.step = '1';
    input.value = String(value);
    input.style.width = '120px';
    input.style.accentColor = 'var(--accent)';
    var out = el('span', 'pl-row-sub', String(value));
    out.style.minWidth = '14px';
    input.addEventListener('input', function () {
        var v = parseInt(input.value, 10);
        out.textContent = String(v);
        onChange(v);
    });
    wrap.appendChild(input);
    wrap.appendChild(out);
    return wrap;
}

/* quanto marcati sono linee e testi: 1 tenue, 5 deciso */
function applyContrast() {
    var k = Math.max(1, Math.min(5, S.settings.contrast_level || 3));
    document.documentElement.style.setProperty('--k', k);
}

/* quanti giorni affiancati sugli schermi larghi */
function applyDaysVisible() {
    var n = Math.max(1, Math.min(7, S.settings.days_visible || 3));
    document.documentElement.style.setProperty('--days-visible', n);
    buildTrack();
    syncScrollerTop();
}

function tag(label, color, on, onClick) {
    var t = el('button', 'pl-tag' + (on ? ' pl-on' : ''));
    t.type = 'button';
    t.style.setProperty('--ev', color);
    t.appendChild(el('span', 'pl-dot'));
    t.appendChild(el('span', null, label));
    t.addEventListener('click', onClick);
    return t;
}
function durLabel(min) {
    if (min < 60) return min + ' min';
    var h = Math.floor(min / 60), m = min % 60;
    return h + 'h' + (m ? ' ' + pad(m) : '');
}
function parseDur(raw) {
    var s = String(raw).toLowerCase().replace(',', '.').trim();
    var m = s.match(/^(\d+)\s*h(?:\s*(\d+))?/);
    if (m) return (+m[1]) * 60 + (+(m[2] || 0));
    var n = parseInt(s, 10);
    return isNaN(n) ? 0 : Math.max(5, Math.min(1439, n));
}

/* progetto scelto dal selettore condiviso */
document.addEventListener('projectselected', function (e) {
    if (!S.draft || !e.detail || e.detail.uid !== 'plev') return;
    var d = S.draft.data;
    d.project_id = e.detail.id > 0 ? e.detail.id : null;
    d.project_name = e.detail.id > 0 ? e.detail.name : null;
    d.project_color = e.detail.color || null;
    if (d.project_id && !d.title.trim()) d.title = e.detail.name;
    S.draft.dirty = true;
    renderEventSheet();
});

/* salvataggio, annullamento, chiusura toccando fuori */
function saveEvent() {
    var d = S.draft.data;
    var payload = {
        id: d.id, title: d.title, notes: d.notes, date: d.date, start: d.start, end: d.end,
        all_day: d.all_day, category_id: d.category_id || 0, project_id: d.project_id || 0,
        habit_id: d.habit_id || 0, importance: d.importance, color: d.color || '',
        type: d.type, notify: d.notify, notify_min: d.notify_min,
        repeat: d.repeat, repeat_interval: d.repeat_interval, repeat_unit: d.repeat_unit,
        repeat_until: d.repeat_until || '',
        location: d.location || '', guests: d.guests || [],
        place_name: d.place_name || '',
        place_lat: (d.place_lat === 0 || d.place_lat) ? d.place_lat : '',
        place_lon: (d.place_lon === 0 || d.place_lon) ? d.place_lon : '',
        place_ref: d.place_ref || '',
        duration_locked: d.duration_locked ? 1 : 0,
        calendar_id: d.calendar_id || 0
    };

    // Controlliamo se la frequenza è stata alterata
    var repeatChanged = false;
    if (S.draft.origRepeat) {
        if (d.repeat !== S.draft.origRepeat.repeat ||
            d.repeat_interval !== S.draft.origRepeat.repeat_interval ||
            d.repeat_unit !== S.draft.origRepeat.repeat_unit ||
            d.repeat_until !== S.draft.origRepeat.repeat_until) {
            repeatChanged = true;
        }
    }

    function eseguiSalvataggio(dati) {
        var oldDate = S.draft.origDate;
        var eventId = dati.id;
        
        closeOv('plEventOv');
        S.draft = null;

        /* OTTIMIZZAZIONE FLUIDITÀ (Optimistic Update):
           Spostiamo istantaneamente l'evento in locale così non si avverte lag, 
           mentre il server macina il salvataggio in background. */
        if (eventId && S.events[oldDate]) {
            var idx = S.events[oldDate].findIndex(function(x) { return x.id === eventId; });
            if (idx > -1) {
                var oldEv = S.events[oldDate][idx];
                S.events[oldDate].splice(idx, 1); // Lo togliamo dalla vecchia data
                
                // Creiamo un fantasma temporaneo nel nuovo giorno/orario
                var tempEv = Object.assign({}, oldEv, {
                    date: dati.date,
                    start: dati.start,
                    end: dati.end,
                    all_day: dati.all_day,
                    title: dati.title
                });
                
                if (!S.events[dati.date]) S.events[dati.date] = [];
                S.events[dati.date].push(tempEv);
            }
        }
        renderDays(); // Ridisegna tutto lo schermo all'istante senza attese

        return api('event_save', dati).then(function (r) {
            if (!r.ok) { 
                toast(r.message || 'Non è stato possibile salvare.'); 
                // Se fallisce, ricarichiamo per annullare la modifica istantanea
                loadWeek(monday(S.focusDate), true);
                return; 
            }
            
            // PULIZIA: Rimuoviamo gli eventuali cloni
            if (oldDate && r.event && oldDate !== r.event.date && S.events[oldDate]) {
                S.events[oldDate] = S.events[oldDate].filter(function (x) { return x.id !== r.event.id; });
            }
            
            indexEvents([r.event]);
            if (dati.repeat !== 'none' || dati.edit_mode === 'all') { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }
            renderDays();
            if (r.event && r.event.type === 'task' && S.todos) loadWeekTodos(S.todoTab, true);
            haptic(10);
            scheduleSync();
        });
    }

    if (d.id > 0 && d.repeat && d.repeat !== 'none') {
        if (repeatChanged) {
            // Se ha toccato le regole di frequenza, è ovvio che voglia modificare l'intera serie
            payload.edit_mode = 'all';
            eseguiSalvataggio(payload);
            return;
        }
        alertBox('Modifica evento ripetuto', 'Vuoi modificare solo questa data o l\'intera serie?', [
            { label: 'Annulla' },
            { label: 'Tutta la serie', onClick: function() {
                payload.edit_mode = 'all';
                eseguiSalvataggio(payload);
            }},
            { label: 'Solo questo', kind: 'primary', onClick: function() {
                payload.edit_mode = 'one';
                payload.original_date = d.date;
                eseguiSalvataggio(payload);
            }}
        ]);
        return;
    }

    eseguiSalvataggio(payload);
}
/* chiede conferma prima di eliminare: un tocco sbagliato sul cestino non
   deve far sparire un evento senza appello */
function confirmDeleteEvent(d) {
    if (d.repeat && d.repeat !== 'none') {
        alertBox('Elimina evento', 'Vuoi eliminare solo questa data o l\'intera serie?', [
            { label: 'Annulla' },
            { label: 'Tutta la serie', kind: 'danger', onClick: function() { deleteEvent(d, 'series'); } },
            { label: 'Solo questo', kind: 'danger', onClick: function() { deleteEvent(d, 'one'); } }
        ]);
        return;
    }
    alertBox('Elimino questo evento?', (d.title ? '"' + d.title + '" ' : '') + 'verrà rimosso dal planner.', [
        { label: 'Annulla' },
        { label: 'Elimina', kind: 'danger', onClick: function () { deleteEvent(d, 'one'); } }
    ]);
}

function deleteEvent(d, scopeParam) {
    closeOv('plEventOv');
    S.draft = null;
    api('event_delete', { id: d.id, scope: scopeParam || 'one', original_date: d.date }).then(function (r) {
        if (!r.ok) { toast('Non è stato possibile eliminare.'); return; }
        S.events[d.date] = (S.events[d.date] || []).filter(function (x) { return x.id !== d.id; });
        S.weekCache = {}; 
        loadWeek(monday(S.focusDate), true).then(renderDays);
        scheduleSync();
    });
}
$('plEvSave').addEventListener('click', function () { if (S.draft) saveEvent(); });
$('plEvTrash').addEventListener('click', function () {
    if (!S.draft || !S.draft.data.id) return;
    confirmDeleteEvent(S.draft.data);
});
$('plEvCancel').addEventListener('click', function () { S.draft = null; closeOv('plEventOv'); });
$('plEventOv').addEventListener('click', function (e) {
    if (e.target !== this || !S.draft) return;
    // toccando fuori: se l'utente ha scritto qualcosa vale come "salva", altrimenti come "cancella"
    if (S.draft.dirty && (S.draft.data.title.trim() || S.draft.data.category_id || S.draft.data.project_id || S.draft.data.notes)) saveEvent();
    else { S.draft = null; closeOv('plEventOv'); }
});

/* il + in basso a destra */
$('plPlus').addEventListener('click', function () {
    var slot = zoomCfg(S.zoom).slot || 30;
    var start = firstFreeMinute(slot);   // il primo buco libero, non il primo orario a schermo
    openEventSheet(null, { date: S.focusDate, start: toHM(start), end: toHM(Math.min(1439, start + slot)) });
});

/* ------------------------------------------------------------------ */
/* Pulsanti laterali: routine, categoria, abitudini, anticipi          */
/* ------------------------------------------------------------------ */
function closeMenus(except) {
    ['plMenuRoutine', 'plMenuCat', 'plMenuHabit'].forEach(function (id) {
        if (id !== except) { $(id).classList.remove('pl-open'); $(id).style.maxHeight = ''; }
    });
    ['plMenuFav'].forEach(function (id) {
        if (id !== except) { $(id).classList.remove('pl-open'); $(id).style.maxHeight = ''; }
    });
    var map = { plFabRoutine: 'plMenuRoutine', plFabCat: 'plMenuCat', plHabitRing: 'plMenuHabit', plFabFav: 'plMenuFav' };
    Object.keys(map).forEach(function (id) {
        if (map[id] !== except) $(id).classList.remove('pl-on');
    });
    /* con un tastino a destra aperto la rotellina si nasconde: coprirebbe
       la vista, o starebbe lì ferma senza motivo. Torna quando si chiude. */
    if (!except) refreshZoomDial();
    else { var b = $('plZoom'); if (b) b.classList.remove('pl-show'); }
}

/* il menù non deve finire sotto il + : gli diamo l'altezza che resta */
function sizeMenu(menu) {
    var top = menu.getBoundingClientRect().top;
    var qh = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--quotebar-h'), 10) || 0;
    menu.style.maxHeight = Math.max(120, window.innerHeight - top - qh - 96) + 'px';
}

$('plFabRoutine').addEventListener('click', function () {
    var menu = $('plMenuRoutine');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuRoutine' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.routines, 'name').forEach(function (r) {
        var c = el('button', 'pl-chip');
        c.type = 'button';
        c.style.setProperty('--ev', r.color);
        c.appendChild(el('span', 'pl-dot'));
        c.appendChild(el('span', null, r.name));
        c.appendChild(el('span', 'pl-chip-sub', durLabel(r.total)));
        c.addEventListener('click', function () {
            closeMenus();
            $('plFabRoutine').classList.remove('pl-on');
            confirmRoutineTime(r, toHM(firstFreeMinute(r.total)));
        });
        menu.appendChild(c);
    });
    var manage = el('button', 'pl-chip');
    manage.type = 'button';
    manage.appendChild(el('span', null, 'Gestisci routine'));
    manage.addEventListener('click', function () { closeMenus(); openSettings('routines'); });
    menu.appendChild(manage);
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

$('plFabCat').addEventListener('click', function () {
    var btn = this;
    if (S.defaultCat) { posaCategoria(); return; }
    var menu = $('plMenuCat');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuCat' : null);
    btn.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.categories, 'name').forEach(function (c) {
        var chip = el('button', 'pl-chip');
        chip.type = 'button';
        chip.style.setProperty('--ev', c.color);
        chip.appendChild(el('span', 'pl-dot'));
        chip.appendChild(el('span', null, c.name));
        chip.addEventListener('click', function () {
            armaAttrezzo(null, null);   // l'abitudine o il progetto armati si posano
            S.defaultCat = c.id;
            btn.classList.add('pl-picked');
            btn.classList.remove('pl-on');
            btn.style.setProperty('--ev', c.color);
            btn.innerHTML = '';
            btn.appendChild(el('span', 'pl-dot'));
            btn.appendChild(el('span', null, c.name));
            var x = el('span', 'pl-chip-x');
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            btn.appendChild(x);
            menu.classList.remove('pl-open');
            menu.style.maxHeight = '';
        });
        menu.appendChild(chip);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

$('plHabitRing').addEventListener('click', function () {
    /* Ritoccando il tastino con l'abitudine già armata la si posa, come si fa
       con la categoria: se no non ci sarebbe modo di cambiare idea senza
       inserirla da qualche parte. */
    if (S.armato && S.armato.kind === 'habit') { armaAttrezzo(null, null); return; }
    var menu = $('plMenuHabit');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuHabit' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    loadHabits(S.focusDate).then(function (habits) {
        var sett = weeklyOf(S.focusDate);
        if (!habits.length && !sett.length) {
            var none = el('div', 'pl-chip');
            none.appendChild(el('span', null, 'Nessuna abitudine per oggi'));
            menu.appendChild(none);
            sizeMenu(menu);
            return;
        }

        /* prima quelle di oggi */
        habits.forEach(function (h) { menu.appendChild(habitChip(h, false)); });

        /* poi una linea sottile, e sotto le settimanali */
        if (sett.length) {
            if (habits.length) menu.appendChild(el('div', 'pl-chip-sep'));
            menu.appendChild(el('div', 'pl-chip-lab', 'Questa settimana'));
            sett.forEach(function (h) { menu.appendChild(habitChip(h, true)); });
        }
        sizeMenu(menu);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* progetti preferiti: il tastino compare solo se ce n'è almeno uno */
$('plFabFav').addEventListener('click', function () {
    if (S.armato && S.armato.kind === 'project') { armaAttrezzo(null, null); return; }
    var menu = $('plMenuFav');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuFav' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.favorites, 'name').forEach(function (p) {
        var chip = el('button', 'pl-chip');
        chip.type = 'button';
        chip.style.setProperty('--ev', p.color || NEUTRAL);
        chip.appendChild(el('span', 'pl-dot'));
        chip.appendChild(el('span', null, p.name));
        chip.addEventListener('click', function () {
            closeMenus();
            armaAttrezzo('project', p, 0);
            toast('Tocca il calendario dove metterlo.');
        });
        menu.appendChild(chip);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* Conferma di orario e durata prima di mettere un progetto in agenda.
   Il titolo è il nome del progetto e il colore è il suo. */
/* NON PIÙ IN USO: le progetti preferiti ora si armano dal tastino e si posano toccando
   il calendario, come le categorie. Resta qui perché è il popup di conferma
   già scritto e collaudato, nel caso si voglia tornare a chiedere l'orario. */
function confirmProjectTime(p, defaultStart, defaultDur) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = p.name;
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');

    var r1 = el('div', 'pl-row-sb');
    r1.appendChild(el('span', 'pl-row-lab', 'Inizio'));
    var startIn = el('input', 'pl-pick');
    startIn.type = 'time'; startIn.step = '300'; startIn.value = defaultStart;
    r1.appendChild(startIn);
    wrap.appendChild(r1);

    /* Qui c'era una "Durata" scritta in un campo orario: l'orologio di
       sistema mostra un'ora del giorno, e chi lo apriva scriveva l'orario di
       fine. Con inizio 15:00 e "16:00" letto come durata l'evento finiva a
       31:00, che il limite del giorno riportava a 23:59 — l'ora di fine
       sembrava ignorata. Adesso il campo è quello che tutti ci leggevano. */
    var r2 = el('div', 'pl-row-sb');
    r2.appendChild(el('span', 'pl-row-lab', 'Fine'));
    var endIn = el('input', 'pl-pick');
    endIn.type = 'time'; endIn.step = '300';
    endIn.value = toHM(Math.min(1439, toMin(defaultStart) + defaultDur));
    r2.appendChild(endIn);
    wrap.appendChild(r2);
    $('plAlertMsg').appendChild(wrap);

    /* Spostando l'inizio la fine lo segue, così la durata resta quella
       proposta: solo finché la fine non viene toccata a mano, se no si
       cancellerebbe una scelta appena fatta. */
    var fineToccata = false;
    endIn.addEventListener('input', function () { fineToccata = true; });
    startIn.addEventListener('input', function () {
        if (fineToccata) return;
        endIn.value = toHM(Math.min(1439, toMin(startIn.value || defaultStart) + defaultDur));
    });

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', 'Inserisci'); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = toMin(startIn.value || defaultStart);
        var end = toMin(endIn.value || toHM(Math.min(1439, start + defaultDur)));
        /* fine prima dell'inizio: non si indovina cosa intendesse, si dice
           che non va e si lascia il popup aperto per correggere */
        if (end <= start) { toast('La fine deve venire dopo l\'inizio.'); return; }
        ov.classList.remove('pl-open');
        api('event_save', {
            title: p.name, date: S.focusDate, start: toHM(start),
            end: toHM(Math.min(1439, end)),
            project_id: p.id, type: 'event', notify: 'none'
        }).then(function (r) {
            if (!r.ok) { toast('Non è stato possibile inserire il progetto.'); return; }
            indexEvents([r.event]);
            renderDays();
            haptic(12);
        });
    });
    acts.appendChild(no); acts.appendChild(yes);
    ov.classList.add('pl-open');
}

/* Un'abitudine nel menù. Quelle settimanali portano il loro anellino con
   "1/4" dentro: si vede a colpo d'occhio quanto manca all'obiettivo. */
function habitChip(h, settimanale) {
    var chip = el('button', 'pl-chip');
    chip.type = 'button';
    chip.style.setProperty('--ev', h.color);

    /* Se l'abitudine è già in agenda nel giorno mostrato, il chip si spegne su
       un fondo grigio: dice "questa sarebbe la seconda volta di oggi" senza
       impedire niente, perché a volte è proprio quello che si vuole.
       Solo per quelle a completamento: su un'abitudine a quantità mettere più
       passaggi nella giornata è il modo normale di usarla, e avvisare
       sarebbe un falso allarme. */
    var giaInserita = eventsOf(S.focusDate).some(function (e) { return e.habit_id === h.id; });
    if (giaInserita && h.a_completamento) chip.classList.add('pl-chip-messa');

    if (settimanale) {
        var C = 62.8, frac = h.target ? Math.min(1, h.done / h.target) : 0;
        var r = el('span', 'pl-chip-ring');
        r.innerHTML = '<svg viewBox="0 0 22 22">'
            + '<circle class="bg" cx="11" cy="11" r="10"/>'
            + '<circle class="fg" cx="11" cy="11" r="10" stroke-dasharray="' + C + '" stroke-dashoffset="'
            + (C * (1 - frac)).toFixed(1) + '"/></svg>';
        r.appendChild(el('span', null, h.done + '/' + h.target));
        chip.appendChild(r);
    } else {
        chip.appendChild(el('span', 'pl-dot'));
    }
    if (h.icon) chip.appendChild(el('span', 'pl-ev-icon', h.icon));
    chip.appendChild(el('span', null, h.name));
    if (settimanale && h.done > h.target) {
        /* oltre l'obiettivo non è un errore: è un merito */
        chip.appendChild(el('span', 'pl-chip-sub', 'oltre'));
    }

    chip.addEventListener('click', function () {
        /* Niente più popup che chiede l'ora a scatola chiusa: si arma
           l'abitudine e poi si tocca il calendario nel punto voluto, che è
           il modo in cui si sceglie un orario guardandolo. */
        closeMenus();
        armaAttrezzo('habit', h, h.minutes && h.minutes > 0 ? h.minutes : (zoomCfg(S.zoom).slot || 30));
        toast('Tocca il calendario dove metterla.');
    });
    return chip;
}

/* modalità anticipi / ritardi */
$('plFabShift').addEventListener('click', function () { setShift(!S.shift.on); });
$('plShiftCancel').addEventListener('click', function () { setShift(false); });
$('plShiftSwitch').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    S.shift.dir = b.dataset.dir;
    Array.prototype.forEach.call(this.querySelectorAll('button'), function (x) { x.classList.toggle('pl-on', x === b); });
});
/* Il tasto che applica anticipi e ritardi.

   PERCHÉ SBAGLIAVA. Spostare molti eventi richiede tempo, e il tasto restava
   vivo e identico per tutta l'attesa: sembrava non aver fatto niente, quindi
   lo si premeva ancora. Ogni pressione mandava una richiesta completa, e il
   server le eseguiva tutte: tre pressioni = tre ritardi sommati.
   Ora alla prima pressione il tasto si spegne e lo dice. */
$('plShiftSave').addEventListener('click', function () {
    var btn = this;
    if (btn.disabled) return;                       // già in corso: si ignora

    var ids = Object.keys(S.shift.picked).map(Number);
    if (!ids.length) { toast('Scegli almeno un evento.'); return; }
    var parts = String($('plShiftTime').value || '00:10').split(':');
    var minutes = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    if (!minutes) { toast('Indica di quanto spostarli.'); return; }

    var testo = btn.textContent;
    btn.disabled = true;
    btn.classList.add('pl-busy');
    btn.textContent = 'Caricamento…';

    function libera() {
        btn.disabled = false;
        btn.classList.remove('pl-busy');
        btn.textContent = testo;
    }

    /* niente ripetizione automatica: spostare due volte gli stessi eventi è
       proprio il danno che stiamo evitando */
    api('events_shift', { ids: ids, minutes: minutes, direction: S.shift.dir }, null, { noRetry: true })
        .then(function (r) {
            if (!r.ok) { libera(); toast(r.message || 'Spostamento non riuscito.'); return; }
            setShift(false);
            libera();
            S.weekCache = {};
            loadWeek(monday(S.focusDate), true);
            toast(r.moved + (r.moved === 1 ? ' evento spostato' : ' eventi spostati'));
        });
});
function setShift(on) {
    S.shift.on = on;
    S.shift.picked = {};
    document.body.classList.toggle('pl-shift-on', on);
    closeMenus();
    renderDays();
}

/* diario del giorno */
$('plFabDiary').addEventListener('click', function () {
    if (window.nisamaOpenDiary) { window.nisamaOpenDiary(S.focusDate, 'planner'); return; }
    toast('Manca il file diario_giorno.php.');
});

/* quando il diario cambia, i tag della vista mese si aggiornano */
document.addEventListener('diaryupdated', function () {
    S.weekCache = {};
    loadWeek(monday(S.focusDate), true).then(function () { if (S.view === 'month') renderMonth(); });
});

$('plWeekPanelClose').addEventListener('click', closeWeekPanel);
$('plWeekPanelOv').addEventListener('click', function (e) { if (e.target === this) closeWeekPanel(); });

/* dal lunedì, un trascinamento verso destra sul bordo apre il pannello */
(function edgePull() {
    var edge = $('plEdge');
    if (!edge) return;
    var x0 = null, y0 = 0;
    edge.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
    }, { passive: true });
    edge.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0;
        var dy = e.touches[0].clientY - y0;
        if (dx > 44 && Math.abs(dy) < 40) {
            x0 = null;
            if (dowIndex(S.focusDate) === 0) openWeekPanel();
            else toast('Le liste della settimana si aprono dal lunedì o dal tasto accanto a Priorità.');
        }
    }, { passive: true });
    edge.addEventListener('touchend', function () { x0 = null; }, { passive: true });
})();

/* ------------------------------------------------------------------ */
/* Ricerca, filtro, impostazioni                                       */
/* ------------------------------------------------------------------ */
function openSheet(title, build) {
    $('plSheetTitle').textContent = title;
    var body = $('plSheetBody');
    body.innerHTML = '';
    build(body);
    openOv('plSheetOv');
}

$('plSearchBtn').addEventListener('click', function () {
    openSheet('Cerca', function (body) {
        var inp = el('input', 'pl-in');
        inp.type = 'search'; inp.placeholder = 'Titolo dell\'evento'; inp.maxLength = 60;
        body.appendChild(inp);
        var res = el('div', 'pl-rows');
        res.style.marginTop = '12px';
        body.appendChild(res);
        inp.addEventListener('input', function () {
            debounceSave('search', 300, function () {
                if (inp.value.trim().length < 2) { res.innerHTML = ''; return; }
                api('search', null, { q: inp.value.trim() }).then(function (r) {
                    res.innerHTML = '';
                    if (!r.ok || !r.results.length) {
                        res.appendChild(el('div', 'pl-quote pl-serif', 'Nessun evento con questa parola.'));
                        return;
                    }
                    r.results.forEach(function (x) {
                        var row = el('button', 'pl-srow');
                        row.type = 'button';
                        var inner = el('div', 'pl-srow-in');
                        var dot = el('span', 'pl-dot');
                        dot.style.background = x.color || (x.category_id ? (catById(x.category_id) || {}).color : NEUTRAL) || NEUTRAL;
                        dot.style.width = '9px'; dot.style.height = '9px'; dot.style.borderRadius = '50%';
                        inner.appendChild(dot);
                        var txt = el('div');
                        txt.style.flex = '1';
                        txt.appendChild(el('div', 'pl-row-lab', x.title));
                        var dd = parseIso(x.date);
                        txt.appendChild(el('div', 'pl-row-sub',
                            dd.getDate() + ' ' + MESI[dd.getMonth()].toLowerCase() + (x.all_day ? '' : '  ·  ' + x.start)));
                        inner.appendChild(txt);
                        row.appendChild(inner);
                        row.addEventListener('click', function () {
                            closeOv('plSheetOv');
                            goToDate(x.date, false);
                            if (!x.all_day) setTimeout(function () { scrollToMinute(Math.max(0, toMin(x.start) - 60), true); }, 320);
                        });
                        res.appendChild(row);
                    });
                });
            });
        });
        setTimeout(function () { inp.focus(); }, 380);
    });
});

$('plFilterBtn').addEventListener('click', function () {
    openSheet('Cosa mostrare', function (body) {
        /* ---- calendari ---- */
        var fc = el('div', 'pl-field');
        var head = el('div', 'pl-row-sb');
        head.appendChild(el('span', 'pl-label', 'Calendari'));
        var tutti = el('button', 'pl-mini-btn', 'Mostra tutti');
        tutti.type = 'button';
        tutti.addEventListener('click', function () {
            api('calendar_only', { id: 0 }).then(function (r) {
                if (!r.ok) return;
                S.calendars = r.calendars;
                renderDays();
                if (S.view === 'month') renderMonth();
                closeOv('plSheetOv');
            });
        });
        head.appendChild(tutti);
        fc.appendChild(head);

        var rows = el('div', 'pl-rows');
        alpha(S.calendars, 'name').forEach(function (c) { rows.appendChild(calendarRow(c)); });
        fc.appendChild(rows);

        var addCal = el('button', 'pl-addlist');
        addCal.type = 'button';
        addCal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addCal.appendChild(el('span', null, 'Nuovo calendario'));
        addCal.style.marginTop = '8px';
        addCal.addEventListener('click', function () { editCalendar(null); });
        fc.appendChild(addCal);
        body.appendChild(fc);

        /* ---- categorie ---- */
        var lab = el('div', 'pl-label', 'Categorie');
        lab.style.marginTop = '20px';
        body.appendChild(lab);
        body.appendChild(el('div', 'pl-row-sub', 'Tocca una categoria per nasconderne gli eventi.'));
        var tags = el('div', 'pl-tags');
        tags.style.marginTop = '12px';
        alpha(S.categories, 'name').forEach(function (c) {
            var t = tag(c.name, c.color, !S.hiddenCats[c.id], function () {
                var hidden = !S.hiddenCats[c.id];
                if (hidden) S.hiddenCats[c.id] = true; else delete S.hiddenCats[c.id];
                t.classList.toggle('pl-on', !hidden);
                t.classList.toggle('pl-off', hidden);
                api('category_hide', { id: c.id, hidden: hidden });
                renderDays();
                if (S.view === 'month') renderMonth();
                $('plFilterBtn').classList.toggle('pl-on', Object.keys(S.hiddenCats).length > 0);
            });
            if (S.hiddenCats[c.id]) t.classList.add('pl-off');
            tags.appendChild(t);
        });
        body.appendChild(tags);
    });
});

$('plSettingsBtn').addEventListener('click', function () { openSettings(); });

/* Una sezione delle impostazioni che si apre e si chiude. Categorie e routine
   aperte riempivano da sole tutto il popup: le voci sotto c'erano, ma per
   arrivarci bisognava scorrere oltre elenchi che quasi sempre non si stava
   cercando. Chiuse restano una riga, con quante ce ne sono. */
function sezionePieghevole(titolo, quante) {
    var field = el('div', 'pl-field');
    var head = el('button', 'pl-fold-head');
    head.type = 'button';
    head.appendChild(el('span', 'pl-label', titolo));
    head.appendChild(el('span', 'pl-fold-n', quante ? String(quante) : ''));
    var arrow = el('span', 'pl-fold-arrow');
    arrow.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>';
    head.appendChild(arrow);

    var corpo = el('div', 'pl-fold-body pl-hidden');
    field.appendChild(head);
    field.appendChild(corpo);

    function apri(on) {
        corpo.classList.toggle('pl-hidden', !on);
        head.classList.toggle('pl-open', on);
        head.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    apri(false);
    head.addEventListener('click', function () { apri(corpo.classList.contains('pl-hidden')); });
    return { field: field, corpo: corpo, apri: apri };
}

function openSettings(focus) {
    openSheet('Impostazioni', function (body) {
        /* palette */
        var fp = el('div', 'pl-field');
        fp.appendChild(el('label', 'pl-label', 'Colore della pagina'));
        var sw = el('div', 'pl-swatches');
        [['sage', '#A9C3AE'], ['mint', '#A5CCC4'], ['blush', '#E7B9C0'], ['lavender', '#C2B8DC'],
         ['butter', '#E8D4A2'], ['sky', '#B0C7DD'], ['clay', '#DDBCA6'], ['rose', '#DFAFB4'],
         ['sea', '#9FC2C6'], ['olive', '#BFC49B'], ['plum', '#C3A9BE'], ['sand', '#E0CDA9']].forEach(function (p) {
            var b = el('button', 'pl-sw' + (S.settings.palette === p[0] ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', p[1]);
            b.addEventListener('click', function () {
                S.settings.palette = p[0];
                applyPalette(p[0]);
                applySky();
                api('settings_save', { palette: p[0] });
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fp.appendChild(sw);
        body.appendChild(fp);

        /* categorie */
        var sc2 = sezionePieghevole('Categorie', (S.categories || []).length);
        var rows = el('div', 'pl-rows');
        alpha(S.categories, 'name').forEach(function (c) { rows.appendChild(categoryRow(c)); });
        sc2.corpo.appendChild(rows);
        var addCat = el('button', 'pl-addlist');
        addCat.type = 'button';
        addCat.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addCat.appendChild(el('span', null, 'Nuova categoria'));
        addCat.style.marginTop = '8px';
        addCat.addEventListener('click', function () { editCategory(null); });
        sc2.corpo.appendChild(addCat);
        body.appendChild(sc2.field);

        /* routine */
        var sr2 = sezionePieghevole('Routine', (S.routines || []).length);
        sr2.field.id = 'plSetRoutines';
        var rr = el('div', 'pl-rows');
        alpha(S.routines, 'name').forEach(function (r) { rr.appendChild(routineRow(r)); });
        sr2.corpo.appendChild(rr);
        var addR = el('button', 'pl-addlist');
        addR.type = 'button';
        addR.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addR.appendChild(el('span', null, 'Nuova routine'));
        addR.style.marginTop = '8px';
        addR.addEventListener('click', function () { editRoutine(null); });
        sr2.corpo.appendChild(addR);
        body.appendChild(sr2.field);

        /* frasi + zoom + ambiente */
        var fo = el('div', 'pl-field');
        fo.appendChild(el('label', 'pl-label', 'Sulla pagina'));
        fo.appendChild(rowSB('Frasi motivazionali', toggle(S.settings.show_motivational !== false, function (v) {
            S.settings.show_motivational = v; api('settings_save', { show_motivational: v }); renderWeekBits();
        })));
        fo.appendChild(rowSB('Affermazioni positive', toggle(S.settings.show_affirmations !== false, function (v) {
            S.settings.show_affirmations = v; api('settings_save', { show_affirmations: v }); renderWeekBits();
        })));
        /* La barra vive sotto la spunta e sparisce con lei: regolare quanto si
           vede una cosa spenta non vuol dire niente. */
        var rigaAmb = rowSB('Quanto si vedono', slider(0, 10, ambOpacita(), function (v) {
            S.settings.ambient_opacity = v;
            applyAmbientOpacity();
            debounceSave('ambop', 500, function () { api('settings_save', { ambient_opacity: v }); });
        }));
        fo.appendChild(rowSB('Alberi e animali', toggle(S.settings.ambient_enabled !== false, function (v) {
            S.settings.ambient_enabled = v; api('settings_save', { ambient_enabled: v });
            rigaAmb.classList.toggle('pl-hidden', !v);
            applyAmbient(true); applySky();
        })));
        rigaAmb.classList.toggle('pl-hidden', S.settings.ambient_enabled === false);
        fo.appendChild(rigaAmb);
        fo.appendChild(rowSB('Scatti degli orari', select(ZOOM.map(function (z) { return [z.key, z.label]; }), S.zoom, function (v) {
            setZoom(v);
        })));
        fo.appendChild(rowSB('Quanto marcato', slider(1, 5, S.settings.contrast_level || 3, function (v) {
            S.settings.contrast_level = v;
            applyContrast();
            debounceSave('contrast', 500, function () { api('settings_save', { contrast_level: v }); });
        })));
        if (window.matchMedia('(min-width:760px)').matches) {
            fo.appendChild(rowSB('Giorni affiancati', slider(1, 7, S.settings.days_visible || 3, function (v) {
                S.settings.days_visible = v;
                applyDaysVisible();
                debounceSave('daysv', 500, function () { api('settings_save', { days_visible: v }); });
            })));
        }
        body.appendChild(fo);

        /* ---- Google Calendar ---- */
        var fg = el('div', 'pl-field');
        fg.appendChild(el('label', 'pl-label', 'Google Calendar'));
        var gBox = el('div');
        gBox.appendChild(el('div', 'pl-row-sub', 'Controllo del collegamento…'));
        fg.appendChild(gBox);
        body.appendChild(fg);
        drawGoogle(gBox);

        /* chi arriva qui per le routine le sta cercando: chiuse gli
           mostrerebbero una riga sola con dentro niente */
        if (focus === 'routines') {
            sr2.apri(true);
            setTimeout(function () { $('plSetRoutines').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300);
        }
    });
}

/* Una riga della lista calendari: si accende e si spegne, si tocca il nome
   per vedere solo quello, e scorrendo compaiono modifica ed elimina. */
function calendarRow(c) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');

    var sw = el('span', 'pl-cal-dot' + (c.visible ? ' pl-on' : ''));
    sw.style.setProperty('--ev', c.color || '#B7AEA3'); // FIX checkbox invisibile
    sw.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    sw.addEventListener('click', function (e) {
        e.stopPropagation();
        var next = !c.visible;
        c.visible = next;
        sw.classList.toggle('pl-on', next);
        api('calendar_visible', { id: c.id, visible: next });
        renderDays();
        if (S.view === 'month') renderMonth();
    });
    inner.appendChild(sw);

    var txt = el('div');
    txt.style.flex = '1';
    
    // Riga dedicata a Nome del calendario + Bottone delle Regole
    var tRow = el('div', 'pl-row-sb');
    tRow.style.padding = '0'; tRow.style.borderBottom = 'none';
    tRow.appendChild(el('div', 'pl-row-lab', c.name));
    
    var rulesBtn = el('button', 'pl-icon-btn');
    rulesBtn.type = 'button';
    rulesBtn.style.width = '24px'; rulesBtn.style.height = '24px'; rulesBtn.style.background = 'none'; rulesBtn.style.boxShadow = 'none';
    rulesBtn.setAttribute('aria-label', 'Regole speciali');
    rulesBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>';
    rulesBtn.addEventListener('click', function(e) { e.stopPropagation(); openCalendarRules(c); });
    tRow.appendChild(rulesBtn);
    
    txt.appendChild(tRow);

    var note = [];
    if (c.is_default) note.push('predefinito');
    if (c.from_google) note.push('da Google');
    if (note.length) txt.appendChild(el('div', 'pl-row-sub', note.join(' · ')));
    inner.appendChild(txt);

    var solo = el('button', 'pl-mini-btn', 'Solo questo');
    solo.type = 'button';
    solo.addEventListener('click', function (e) {
        e.stopPropagation();
        api('calendar_only', { id: c.id }).then(function (r) {
            if (!r.ok) return;
            S.calendars = r.calendars;
            renderDays();
            if (S.view === 'month') renderMonth();
            closeOv('plSheetOv');
        });
    });
    inner.appendChild(solo);

    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editCalendar(c); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox('Elimino ' + c.name + '?', 'Gli eventi non si perdono: passano al calendario predefinito.', [
            { label: 'Annulla' },
            { label: 'Elimina', kind: 'danger', onClick: function () {
                api('calendar_delete', { id: c.id }).then(function (r) {
                    if (!r.ok) { toast(r.message || 'Non eliminato.'); return; }
                    S.calendars = r.calendars;
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true);
                    closeOv('plSheetOv');
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    return row;
}

function editCalendar(c) {
    var isNew = !c;
    var data = c ? { id: c.id, name: c.name, color: c.color }
                 : { id: 0, name: '', color: '#A9C3AE' };
    openSheet(isNew ? 'Nuovo calendario' : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = 'Compleanni, Vacanze, Lavoro…';
        name.value = data.name; name.maxLength = 80;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', 'Colore'));
        var sw = el('div', 'pl-swatches');
        ['#A9C3AE', '#E7B9C0', '#C2B8DC', '#E8D4A2', '#B0C7DD', '#DDBCA6',
         '#9FC2C6', '#BFC49B', '#C3A9BE', '#DFAFB4'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        var acts = el('div', 'pl-alert-acts');
        var no = el('button', 'pl-btn', 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { closeOv('plSheetOv'); });
        var yes = el('button', 'pl-btn pl-btn-primary', isNew ? 'Crea' : 'Salva');
        yes.type = 'button';
        yes.addEventListener('click', function () {
            api('calendar_save', { id: data.id, name: name.value, color: data.color }).then(function (r) {
                if (!r.ok) { toast(r.message || 'Non salvato.'); return; }
                S.calendars = r.calendars;
                closeOv('plSheetOv');
                renderDays();
            });
        });
        acts.appendChild(no); acts.appendChild(yes);
        body.appendChild(acts);
    });
}

function openCalendarRules(c) {
    openSheet('Regole: ' + c.name, function(body) {
        var f1 = el('div', 'pl-field');
        f1.appendChild(el('label', 'pl-label', 'Auto-assegnazione'));
        f1.appendChild(el('div', 'pl-row-sub', 'Se il titolo contiene una di queste parole (es. "compleanno, bday"), finirà automaticamente in questo calendario.'));
        var kwIn = el('input', 'pl-in');
        kwIn.type = 'text'; kwIn.value = c.keywords || ''; kwIn.placeholder = 'Parole separate da virgola';
        kwIn.style.marginTop = '6px';
        f1.appendChild(kwIn);
        body.appendChild(f1);
        
        var f2 = el('div', 'pl-field');
        f2.style.marginTop = '20px';
        f2.appendChild(el('label', 'pl-label', 'Proprietà di Default'));
        f2.appendChild(el('div', 'pl-row-sub', 'I nuovi eventi in questo calendario si compileranno da soli con queste impostazioni:'));
        
        var repSel = select([
            ['none', 'Nessuna'], ['daily', 'Ogni giorno'], ['weekly', 'Ogni settimana'],
            ['monthly', 'Ogni mese'], ['yearly', 'Ogni anno']
        ], c.def_repeat || 'none', function() {});
        repSel.style.maxWidth = '55%';
        f2.appendChild(rowSB('Ripetizione', repSel));
        
        var notSel = select([
            ['none', 'Nessuna'], ['at_time', 'Nel momento'], ['m30', '30 minuti prima'],
            ['h1', "Un'ora prima"], ['d1', 'Un giorno prima']
        ], c.def_notify || 'none', function() {});
        notSel.style.maxWidth = '55%';
        f2.appendChild(rowSB('Notifica', notSel));
        body.appendChild(f2);
        
        var f3 = el('div', 'pl-field');
        f3.style.marginTop = '20px';
        var retroLabel = el('label', 'pl-row');
        var retroCheck = el('input');
        retroCheck.type = 'checkbox';
        retroCheck.style.width = '18px'; retroCheck.style.height = '18px';
        retroLabel.appendChild(retroCheck);
        var spanLab = el('span', null, 'Applica retroattivamente agli eventi già esistenti');
        spanLab.style.fontSize = '14px'; spanLab.style.color = 'var(--ink)';
        retroLabel.appendChild(spanLab);
        f3.appendChild(retroLabel);
        body.appendChild(f3);
        
        var acts = el('div', 'pl-alert-acts');
        acts.style.marginTop = '20px';
        var cancel = el('button', 'pl-btn', 'Annulla');
        cancel.type = 'button';
        cancel.addEventListener('click', function() { closeOv('plSheetOv'); });
        var save = el('button', 'pl-btn pl-btn-primary', 'Salva regole');
        save.type = 'button';
        save.addEventListener('click', function() {
            save.disabled = true;
            save.textContent = 'Salvataggio...';
            api('calendar_rules_save', {
                id: c.id,
                keywords: kwIn.value.trim(),
                def_repeat: repSel.value,
                def_notify: notSel.value,
                apply_retro: retroCheck.checked
            }).then(function(r) {
                save.disabled = false;
                save.textContent = 'Salva regole';
                if (!r.ok) { toast('Errore nel salvataggio'); return; }
                S.calendars = r.calendars;
                closeOv('plSheetOv');
                toast('Regole speciali salvate.');
                if (retroCheck.checked) {
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true);
                }
            });
        });
        acts.appendChild(cancel); acts.appendChild(save);
        body.appendChild(acts);
    });
}

function categoryRow(c) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');
    var dot = el('span', 'pl-dot');
    dot.style.cssText = 'width:12px;height:12px;border-radius:50%;flex-shrink:0;background:' + c.color;
    inner.appendChild(dot);
    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', c.name));
    txt.appendChild(el('div', 'pl-row-sub', c.keywords.length ? c.keywords.join(' · ') : 'nessuna parola chiave'));
    inner.appendChild(txt);
    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editCategory(c); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox('Elimino ' + c.name + '?', 'Gli eventi resteranno, ma senza categoria.', [
            { label: 'Annulla' },
            { label: 'Elimina', kind: 'danger', onClick: function () {
                api('category_delete', { id: c.id }).then(function (r) {
                    if (!r.ok) return;
                    S.categories = r.categories;
                    renderDays(); openSettings();
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    inner.addEventListener('click', function () { if (!row.classList.contains('pl-swiped')) editCategory(c); });
    return row;
}

function enableSwipe(row, inner) {
    var x0 = null;
    inner.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    inner.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0;
        if (dx < -24) row.classList.add('pl-swiped');
        if (dx > 24) row.classList.remove('pl-swiped');
    }, { passive: true });
    inner.addEventListener('touchend', function () { x0 = null; }, { passive: true });
}

function editCategory(c) {
    var isNew = !c;
    var data = c ? { id: c.id, name: c.name, color: c.color, keywords: c.keywords.slice() } : { id: 0, name: '', color: '#C7DED6', keywords: [] };
    openSheet(isNew ? 'Nuova categoria' : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = 'Nome'; name.value = data.name; name.maxLength = 60;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', 'Colore'));
        var sw = el('div', 'pl-swatches');
        ['#C7DED6', '#F0D3D6', '#D8D3E8', '#F3E4C0', '#CFDDEA', '#E8D5C7', '#DCE7C8', '#E4DDD3',
         '#E9A7A0', '#A9C8B4', '#B3A8D8', '#F2CE8A', '#8FB6CE', '#D9A8C6', '#AEC98A', '#C9A88E',
         '#7FB0AB', '#E7B7CF', '#9AA0D2', '#D8C48E'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        /* due tasti affiancati: si può uscire senza salvare, prima non c'era
           modo di annullare una modifica iniziata per sbaglio */
        var acts = el('div', 'pl-alert-acts');
        acts.style.marginTop = '4px';
        var cancel = el('button', 'pl-btn', 'Annulla');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { openSettings(); });
        var save = el('button', 'pl-btn pl-btn-primary', isNew ? 'Crea categoria' : 'Salva');
        save.type = 'button';
        save.addEventListener('click', function () {
            api('category_save', { id: data.id, name: name.value, color: data.color }).then(function (r) {
                if (!r.ok) { toast(r.message || 'Non salvato.'); return; }
                S.categories = r.categories;
                renderDays();
                openSettings();
            });
        });
        acts.appendChild(cancel); acts.appendChild(save);
        body.appendChild(acts);

        if (!isNew) {
            var fk = el('div', 'pl-field');
            fk.style.marginTop = '18px';
            fk.appendChild(el('label', 'pl-label', 'Parole che assegnano questa categoria'));
            var tags = el('div', 'pl-tags');
            function drawTags() {
                tags.innerHTML = '';
                data.keywords.forEach(function (k) {
                    var t = el('button', 'pl-tag pl-on');
                    t.type = 'button';
                    t.style.setProperty('--ev', data.color);
                    t.appendChild(el('span', null, k));
                    var x = el('span', 'pl-chip-x');
                    x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                    t.appendChild(x);
                    t.addEventListener('click', function () {
                        api('keyword_delete', { category_id: data.id, keyword: k }).then(function (r) {
                            if (!r.ok) return;
                            S.categories = r.categories;
                            data.keywords = data.keywords.filter(function (y) { return y !== k; });
                            drawTags();
                        });
                    });
                    tags.appendChild(t);
                });
            }
            drawTags();
            fk.appendChild(tags);
            var kwRow = el('div', 'pl-row');
            kwRow.style.marginTop = '10px';
            var kw = el('input', 'pl-in');
            kw.type = 'text'; kw.placeholder = 'es. palestra'; kw.maxLength = 60;
            var addKw = el('button', 'pl-btn pl-btn-primary', 'Aggiungi');
            addKw.type = 'button';
            addKw.style.flex = '0 0 auto';
            addKw.style.padding = '12px 18px';
            addKw.addEventListener('click', function () {
                var word = kw.value.trim().toLowerCase();
                if (!word) return;
                api('keyword_add', { category_id: data.id, keyword: word }).then(function (r) {
                    if (r.ok) {
                        S.categories = r.categories;
                        data.keywords.push(word);
                        kw.value = '';
                        drawTags();
                        return;
                    }
                    if (r.error === 'duplicate') {
                        alertBox('Parola già usata', '"' + word + '" è già assegnata alla categoria ' + r.category_name + '. Una parola può appartenere a una sola categoria.', [{ label: 'Ho capito' }]);
                    } else {
                        toast(r.message || 'Non è stato possibile aggiungere la parola.');
                    }
                });
            });
            kwRow.appendChild(kw); kwRow.appendChild(addKw);
            fk.appendChild(kwRow);
            body.appendChild(fk);
        }
    });
}

function routineRow(r) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');
    var dot = el('span');
    dot.style.cssText = 'width:12px;height:12px;border-radius:50%;flex-shrink:0;background:' + r.color;
    inner.appendChild(dot);
    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', r.name));
    txt.appendChild(el('div', 'pl-row-sub', durLabel(r.total) + ' · ' + r.items.length + ' passaggi'));
    inner.appendChild(txt);
    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editRoutine(r); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox('Elimino ' + r.name + '?', 'Gli eventi già inseriti restano dove sono.', [
            { label: 'Annulla' },
            { label: 'Elimina', kind: 'danger', onClick: function () {
                api('routine_delete', { id: r.id }).then(function (res) {
                    if (!res.ok) return;
                    S.routines = res.routines;
                    openSettings('routines');
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    inner.addEventListener('click', function () { if (!row.classList.contains('pl-swiped')) editRoutine(r); });
    return row;
}

function editRoutine(r) {
    var isNew = !r;
    var data = r ? { id: r.id, name: r.name, color: r.color, items: r.items.map(function (i) { return { name: i.name, minutes: i.minutes }; }) }
                 : { id: 0, name: '', color: '#F6EBD2', items: [{ name: '', minutes: 5 }] };
    openSheet(isNew ? 'Nuova routine' : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = 'Nome della routine'; name.value = data.name; name.maxLength = 80;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', 'Colore'));
        var sw = el('div', 'pl-swatches');
        ['#F6EBD2', '#E6E1F1', '#D7EAE5', '#F6E1E4', '#DCE7F0', '#F1E0D3',
         '#EFE1C4', '#DDD6EE', '#C9E2DC', '#F2D6DA', '#CFDEEC', '#E9D6C6',
         '#E4EBC9', '#D6E7E9', '#EFD9E9', '#F0E6CE'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        var fi = el('div', 'pl-field');
        var head = el('div', 'pl-row-sb');
        head.appendChild(el('span', 'pl-label', 'Passaggi'));
        var totalLab = el('span', 'pl-row-sub', '');
        head.appendChild(totalLab);
        fi.appendChild(head);
        var itemsBox = el('div');
        fi.appendChild(itemsBox);

        function total() {
            var t = 0;
            data.items.forEach(function (i) { t += (parseInt(i.minutes, 10) || 0); });
            totalLab.textContent = durLabel(t) + ' in tutto';
        }
        function drawItems() {
            itemsBox.innerHTML = '';
            data.items.forEach(function (it, idx) {
                var row = el('div', 'pl-row');
                row.style.margin = '7px 0';
                var n = el('input', 'pl-in');
                n.type = 'text'; n.placeholder = 'Passaggio'; n.value = it.name; n.maxLength = 120;
                n.addEventListener('input', function () { it.name = n.value; });
                var m = el('input', 'pl-pick');
                m.type = 'number'; m.min = '1'; m.max = '600'; m.value = it.minutes; m.style.width = '78px';
                m.addEventListener('input', function () { it.minutes = Math.max(1, parseInt(m.value, 10) || 1); total(); });
                var x = el('button', 'pl-icon-btn');
                x.type = 'button';
                x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                x.addEventListener('click', function () { data.items.splice(idx, 1); drawItems(); total(); });
                row.appendChild(n); row.appendChild(m); row.appendChild(x);
                itemsBox.appendChild(row);
            });
        }
        drawItems(); total();

        var addIt = el('button', 'pl-addlist');
        addIt.type = 'button';
        addIt.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addIt.appendChild(el('span', null, 'Aggiungi passaggio'));
        addIt.addEventListener('click', function () { data.items.push({ name: '', minutes: 5 }); drawItems(); total(); });
        fi.appendChild(addIt);
        body.appendChild(fi);

        var save = el('button', 'pl-btn pl-btn-primary', isNew ? 'Crea routine' : 'Salva');
        save.type = 'button';
        save.style.marginTop = '14px';
        save.addEventListener('click', function () {
            api('routine_save', {
                id: data.id, name: name.value, color: data.color,
                items: data.items.filter(function (i) { return i.name.trim(); })
            }).then(function (res) {
                if (!res.ok) { toast(res.message || 'Non salvata.'); return; }
                S.routines = res.routines;
                openSettings('routines');
            });
        });
        body.appendChild(save);
    });
}

/* ------------------------------------------------------------------ */
/* Google Calendar                                                     */
/* ------------------------------------------------------------------ */
function gcall(action, write, dati) {
    var opt = { method: write ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf } };
    if (write) opt.headers['Content-Type'] = 'application/json';
    if (write) opt.body = JSON.stringify(dati || {});
    return fetch('google_calendar.php?ajax=' + action, opt)
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, message: 'Connessione non riuscita.' }; });
}

function drawGoogle(box) {
    gcall('status').then(function (st) {
        box.innerHTML = '';
        if (!st.ok) {
            box.appendChild(el('div', 'pl-row-sub', 'Stato non disponibile.'));
            return;
        }
        if (!st.configured) {
            box.appendChild(el('div', 'pl-row-sub',
                'Non ancora configurato su questo sito: servono le credenziali Google in config.php.'));
            return;
        }
        if (!st.connected) {
            box.appendChild(el('div', 'pl-row-sub',
                'Collegando il tuo account, gli eventi del planner finiscono in Google Calendar e quelli di Google compaiono qui.'));
            var connect = el('button', 'pl-btn pl-btn-primary', 'Collega Google Calendar');
            connect.type = 'button';
            connect.style.marginTop = '10px';
            connect.addEventListener('click', function () {
                window.location.href = 'google_calendar.php?action=connect';
            });
            box.appendChild(connect);
            return;
        }

        var info = el('div', 'pl-row-sb');
        var left = el('div');
        left.appendChild(el('div', 'pl-row-lab', st.email || 'Account collegato'));
        var when = st.last_sync ? new Date(st.last_sync.replace(' ', 'T')) : null;
        left.appendChild(el('div', 'pl-row-sub', when
            ? 'ultima sincronizzazione: ' + when.getDate() + ' ' + MESI[when.getMonth()].toLowerCase() +
              ', ' + pad(when.getHours()) + ':' + pad(when.getMinutes())
            : 'mai sincronizzato'));
        info.appendChild(left);

        /* piccoli e accanto all'account, non grandi in fondo alla pagina:
           presi per sbaglio potevano sembrare il pulsante di salvataggio */
        var actsSmall = el('div', 'pl-row');
        actsSmall.style.flexShrink = '0';
        var sync = el('button', 'pl-mini-btn pl-primary', 'Sincronizza');
        sync.type = 'button';
        sync.addEventListener('click', function () {
            sync.textContent = '…';
            sync.disabled = true;
            runSync().then(function () { drawGoogle(box); });
        });
        var off = el('button', 'pl-mini-btn', 'Scollega');
        off.type = 'button';
        off.addEventListener('click', function () {
            alertBox('Scollego Google Calendar?', 'Gli eventi già scritti restano dove sono, da entrambe le parti. Semplicemente non si parleranno più.', [
                { label: 'Annulla' },
                { label: 'Scollega', kind: 'danger', onClick: function () {
                    gcall('disconnect', true).then(function () { drawGoogle(box); toast('Google Calendar scollegato.'); });
                } }
            ]);
        });
        actsSmall.appendChild(off); actsSmall.appendChild(sync);
        info.appendChild(actsSmall);
        box.appendChild(info);

        box.appendChild(el('div', 'pl-row-sub',
            (st.calendar
                ? 'Il calendario dedicato si chiama "' + st.calendar + '": puoi nasconderlo o cancellarlo da Google quando vuoi. '
                : '')
            + 'Si allinea da ' + st.window.back + ' giorni prima a ' + st.window.forward + ' giorni dopo oggi, da solo a ogni modifica.'));

        /* Cosa mandare, e dove. Il calendario principale ha la precedenza:
           se scegli di mandare là gli importanti, quelli non vanno nel
           dedicato ma solo nel principale. */
        var modes = st.modes || { dedicated: 'all', primary: 'none' };

        var f1 = el('div', 'pl-field');
        f1.style.marginTop = '16px';
        f1.appendChild(el('label', 'pl-label', 'Nel calendario dedicato'));
        f1.appendChild(select([
            ['all', 'Tutti gli eventi'],
            ['high', 'Solo importanti e importantissimi'],
            ['highest', 'Solo importantissimi'],
            ['none', 'Nessuno']
        ], modes.dedicated, function (v) {
            S.settings.gc_dedicated = v;
            api('settings_save', { gc_dedicated: v }).then(function () {
                toast('Scelta salvata. Si allinea al prossimo giro.');
                drawGoogle(box);
            });
        }));
        box.appendChild(f1);

        var f2 = el('div', 'pl-field');
        f2.appendChild(el('label', 'pl-label', 'Nel calendario principale di Google'));
        f2.appendChild(select([
            ['none', 'Nessuno'],
            ['high', 'Importanti e importantissimi'],
            ['highest', 'Solo importantissimi']
        ], modes.primary, function (v) {
            S.settings.gc_primary = v;
            api('settings_save', { gc_primary: v }).then(function () {
                toast('Scelta salvata. Si allinea al prossimo giro.');
                drawGoogle(box);
            });
        }));
        f2.appendChild(el('div', 'pl-row-sub',
            'Quello che mandi qui non finisce nel dedicato: sta solo nel principale.'));
        box.appendChild(f2);

        /* i due elenchi dei calendari, che si caricano da soli */
        var ponte = el('div', 'pl-gc-ponte');
        box.appendChild(ponte);
        drawCalendarBridge(ponte);

        /* Riparazione, in fondo e con l'aria di quello che è: un attrezzo, non
           una funzione di tutti i giorni. Serve dopo un pasticcio — di solito
           uno scollegamento e ricollegamento — e toglie da Google le copie in
           più che abbiamo creato noi. */
        var fr = el('div', 'pl-field');
        fr.style.marginTop = '22px';
        fr.appendChild(el('label', 'pl-label', 'Se qualcosa è andato storto'));
        var rip = el('button', 'pl-btn', 'Cerca e togli i doppioni su Google');
        rip.type = 'button';
        rip.style.width = '100%';
        rip.addEventListener('click', function () {
            alertBox('Cercare i doppioni?',
                'Guardo nel calendario dedicato e nel principale di Google. Tolgo solo le copie '
                + 'in più di eventi che il planner riconosce: quello che c\'era prima, o che hai '
                + 'scritto direttamente su Google, non lo tocco.', [
                { label: 'Annulla' },
                { label: 'Cerca', kind: 'primary', onClick: function () {
                    rip.disabled = true;
                    rip.textContent = 'Sto guardando…';
                    gcall('repair', true).then(function (r) {
                        rip.disabled = false;
                        rip.textContent = 'Cerca e togli i doppioni su Google';
                        if (!r || !r.ok) { toast((r && r.message) || 'Non riuscito.'); return; }
                        toast(r.removed ? r.removed + ' copie tolte da Google.' : 'Nessun doppione trovato.');
                    });
                } }
            ]);
        });
        fr.appendChild(rip);
        /* Il marcatore di versione: senza, non c'è modo di sapere da fuori se
           il file che risponde è quello caricato per ultimo — ed è una
           domanda che ci si fa spesso quando qualcosa non cambia. */
        if (st.build) fr.appendChild(el('div', 'pl-row-sub', 'Versione: ' + st.build));
        box.appendChild(fr);
    });
}

/* ------------------------------------------------------------------ */
/* I calendari di Google e i miei: chi va dove                         */
/* ------------------------------------------------------------------ */
/* Due elenchi e non uno, perché sono due domande diverse e vanno tenute
   separate: «gli eventi di questo calendario di Google dove li metto?» e
   «gli eventi di questo mio calendario dove li mando?». Metterle sulla
   stessa riga sembrava più compatto ma faceva credere che fossero la stessa
   cosa al contrario, e non lo sono — si può leggere un calendario senza
   scriverci, e viceversa. */
function drawCalendarBridge(host) {
    host.innerHTML = '';
    host.appendChild(el('div', 'pl-row-sub', 'Cerco i tuoi calendari su Google…'));

    gcall('calendars').then(function (r) {
        host.innerHTML = '';
        if (!r || !r.ok) {
            host.appendChild(el('div', 'pl-row-sub', r && r.message ? r.message : 'Elenco non disponibile.'));
            return;
        }
        var miei = r.mine || [];

        /* --- da Google a qui --- */
        var s1 = el('div', 'pl-field');
        s1.style.marginTop = '20px';
        s1.appendChild(el('label', 'pl-label', 'Dai calendari di Google'));
        s1.appendChild(el('div', 'pl-row-sub',
            'Per ognuno, in quale dei tuoi calendari far entrare i suoi eventi. '
            + 'Scegliendo «nessuno» quegli eventi non entrano — e se ce n\'erano già, se ne vanno.'));

        (r.google || []).forEach(function (g) {
            var riga = el('div', 'pl-gc-riga');
            var testa = el('div', 'pl-gc-nome');
            var pallino = el('span', 'pl-gc-dot');
            pallino.style.background = g.color || 'var(--hair-2)';
            testa.appendChild(pallino);
            testa.appendChild(el('span', null, g.name || g.ref));
            /* il calendario che scriviamo noi si segnala: cambiarne la
               destinazione è legittimo, ma bisogna sapere che quelli sono i
               propri eventi che tornano indietro */
            if (g.is_ours) testa.appendChild(el('span', 'pl-gc-tag', 'nostro'));
            else if (g.is_primary) testa.appendChild(el('span', 'pl-gc-tag', 'principale'));
            /* Quanti ne sono già entrati da lì. È la risposta alla domanda che
               uno si fa guardando l'elenco — «e allora questi da dove sono
               arrivati?» — e dice anche cosa si sta per buttare scegliendo
               «nessuno». */
            if (g.entrati) testa.appendChild(el('span', 'pl-gc-conta', g.entrati + ' qui'));
            riga.appendChild(testa);

            var opzioni = [['0', 'Nessuno']];
            miei.forEach(function (m) { opzioni.push([String(m.id), m.name]); });
            riga.appendChild(select(opzioni, String(g.calendar_id || 0), function (v) {
                var dest = parseInt(v, 10) || 0;
                if (dest === 0 && (g.calendar_id || g.entrati)) {
                    /* si chiede conferma solo quando c'è davvero qualcosa da
                       perdere: è l'unica scelta di questa pagina che cancella
                       roba già entrata, e non si torna indietro */
                    alertBox('Togliere ' + (g.name || 'questo calendario') + '?',
                        (g.entrati ? g.entrati + ' eventi arrivati da qui spariranno dal planner, '
                                   : 'Gli eventi arrivati da qui spariranno dal planner, ')
                        + 'anche quelli passati. Su Google restano dove sono.', [
                        { label: 'Annulla', onClick: function () { drawCalendarBridge(host); } },
                        { label: 'Togli', kind: 'danger', onClick: function () { mapSet(g, 0, host); } }
                    ]);
                    return;
                }
                mapSet(g, dest, host);
            }));
            s1.appendChild(riga);
        });
        host.appendChild(s1);

        /* --- da qui a Google --- */
        var s2 = el('div', 'pl-field');
        s2.appendChild(el('label', 'pl-label', 'Dai tuoi calendari a Google'));
        s2.appendChild(el('div', 'pl-row-sub',
            'Dove finiscono su Google gli eventi che scrivi qui. '
            + 'Lasciando «come impostato sopra» valgono le scelte per importanza.'));

        var refs = [['', 'Come impostato sopra']];
        /* i calendari di sola lettura non compaiono: Google rifiuta sempre chi
           prova a scriverci, e una scelta che fallirà comunque è peggio che
           una scelta in meno */
        (r.google || []).forEach(function (g) {
            if (g.can_write) refs.push([g.ref, g.name || g.ref]);
        });

        miei.forEach(function (m) {
            var riga = el('div', 'pl-gc-riga');
            var testa = el('div', 'pl-gc-nome');
            var p2 = el('span', 'pl-gc-dot');
            /* il principale non ha colore: si mostra un cerchietto vuoto
               invece di inventargliene uno */
            if (m.color) p2.style.background = m.color;
            else p2.classList.add('pl-gc-dot-vuoto');
            testa.appendChild(p2);
            testa.appendChild(el('span', null, m.name));
            riga.appendChild(testa);
            riga.appendChild(select(refs, m.push_ref || '', function (v) {
                gcall('push_set', true, { calendar_id: m.id, ref: v }).then(function (rr) {
                    if (!rr || !rr.ok) { toast((rr && rr.message) || 'Scelta non salvata.'); return; }
                    toast('Scelta salvata. Si allinea al prossimo giro.');
                });
            }));
            s2.appendChild(riga);
        });
        host.appendChild(s2);
    });
}

function mapSet(g, dest, host) {
    gcall('map_set', true, { ref: g.ref, calendar_id: dest }).then(function (rr) {
        if (!rr || !rr.ok) { toast((rr && rr.message) || 'Scelta non salvata.'); drawCalendarBridge(host); return; }
        if (rr.removed) toast(rr.removed + ' eventi tolti dal planner.');
        else if (rr.moved) toast(rr.moved + ' eventi spostati.');
        else toast('Scelta salvata.');
        /* la lettura riparte da capo per quel calendario, quindi conviene
           allineare subito invece di far aspettare il giro automatico */
        S.weekCache = {};
        runSync(true).then(function () { drawCalendarBridge(host); });
    });
}

/* Sincronizzazione automatica.
   Prima partiva solo premendo il tasto nelle impostazioni: gli eventi creati
   dopo restavano qui e su Google non comparivano mai. Ora ogni modifica ne
   programma una silenziosa poco dopo, e una parte anche all'apertura della
   pagina se l'ultima è vecchia. */
var syncTimer = null;
function scheduleSync() {
    if (!S.settings.google_sync) return;
    if (syncTimer) clearTimeout(syncTimer);
    /* si aspetta qualche secondo: modificando più eventi di fila
       parte una sola sincronizzazione invece di una per ciascuno */
    syncTimer = setTimeout(function () { syncTimer = null; runSync(true); }, 6000);
}

function runSync(quiet) {
    return gcall('sync', true).then(function (r) {
        if (!r.ok) {
            if (quiet && r.error !== 'reauth') return r;   // in silenzio: riproverà
            if (r.error === 'reauth') {
                alertBox('Serve ricollegare Google', 'Il permesso è scaduto o è stato revocato dal tuo account Google.', [
                    { label: 'Più tardi' },
                    { label: 'Ricollega', kind: 'primary', onClick: function () {
                        window.location.href = 'google_calendar.php?action=connect';
                    } }
                ]);
            } else {
                toast(r.message || 'Sincronizzazione non riuscita.');
            }
            return r;
        }
        var arrived = r.pulled + r.removed_here;
        var sent = r.created + r.updated + r.removed_there;
        if (!quiet) {
            if (!arrived && !sent) toast('Già tutto allineato.');
            else toast(arrived + ' novità da Google · ' + sent + ' mandati');
            if (r.failed) toast(r.failed + ' eventi non sono stati accettati da Google.');
        }
        S.weekCache = {};
        loadWeek(monday(S.focusDate), true).then(function () { if (S.view === 'month') renderMonth(); });
        return r;
    });
}

/* messaggio al ritorno dal consenso Google */
(function googleReturn() {
    var m = /[?&]google=([a-z]+)/.exec(window.location.search);
    if (!m) return;
    if (history.replaceState) history.replaceState(null, '', 'planner.php');
    if (m[1] === 'collegato') {
        toast('Google Calendar collegato. Sto allineando gli eventi…');
        setTimeout(runSync, 600);
    } else if (m[1] === 'annullato') {
        toast('Collegamento annullato.');
    } else {
        toast('Collegamento non riuscito. Riprova dalle impostazioni.');
    }
})();

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
    vb.setAttribute('aria-label', v === 'month' ? 'Torna alla settimana' : 'Passa alla vista mese');
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
        var tutti = (S.events[date] || []).filter(function (x) { return x.all_day; });
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
        var listAd = (S.events[dAd] || []).filter(function (x) { return x.all_day; });
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
                var b2 = el('div', 'pl-wk-chip pl-wk-chip-ad' + (ev.done ? ' pl-done' : ''), ev.title || 'senza titolo');
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

    var dentro = (S.events[giorno] || []).filter(function (ev) {
        return !ev.all_day && Math.floor(toMin(ev.start) / 60) === h;
    });
    /* prima i più importanti, e a parità i più lunghi: se qualcosa deve
       finire nel mucchietto, che sia il meno significativo */
    var peso = { highest: 0, high: 1, normal: 2, low: 3 };
    dentro.sort(function (a, b) {
        var pa = peso[a.importance] !== undefined ? peso[a.importance] : 2;
        var pb = peso[b.importance] !== undefined ? peso[b.importance] : 2;
        if (pa !== pb) return pa - pb;
        return (toMin(b.end) - toMin(b.start)) - (toMin(a.end) - toMin(a.start));
    });

    var posti = Math.max(1, Math.floor((WK.riga - 2) / 13));
    var mostrati = dentro.length > posti ? dentro.slice(0, posti - 1) : dentro;

    mostrati.forEach(function (ev) {
        var chip = el('div', 'pl-wk-chip');
        chip.style.setProperty('--ev', evColor(ev));
        if (ev.done) chip.classList.add('pl-done');
        chip.textContent = ev.title || 'Senza titolo';
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
        for (var c = 0; c < 7; c++) {
            var ds = addDays(start, r * 7 + c);
            row.appendChild(monthCell(ds, parseIso(ds).getMonth() === cursor.getMonth()));
        }
        grid.appendChild(row);
    }
}

function monthCell(date, inMonth) {
    var cell = el('div', 'pl-mcell' + (inMonth ? '' : ' pl-out')
        + (date < S.today ? ' pl-past-d' : '') + (date === S.today ? ' pl-today-d' : ''));
    var wtag = wxTag(date);
    if (wtag) cell.appendChild(wtag);   // sta nell'angolo, fuori dal flusso
    cell.appendChild(el('div', 'pl-mcell-n pl-serif', String(parseIso(date).getDate())));
    /* nella casella del mese contano prima gli importantissimi, poi gli
       importanti, e all'interno di ciascun gruppo l'ordine dell'orario */
    var rank = { highest: 0, high: 1, normal: 2 };
    var list = eventsOf(date).slice().sort(function (a, b) {
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
        tag2.setAttribute('aria-label', 'Leggi il diario di questo giorno');
        tag2.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 4h12v16H8a2 2 0 01-2-2z"/></svg>';
        tag2.addEventListener('click', function (e) {
            e.stopPropagation();
            if (window.nisamaDiaryPeek) window.nisamaDiaryPeek(date);
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
        body.appendChild(el('div', 'pl-ma-empty', 'Niente in programma nei prossimi giorni.'));
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
    if (date === S.today) etichetta = 'Oggi';
    else if (date === addDays(S.today, 1)) etichetta = 'Domani';
    else if (date === addDays(S.today, -1)) etichetta = 'Ieri';
    else etichetta = GIORNI[dowIndex(date)];

    /* Il nome del giorno è un pulsante: toccandolo si va a vedere quel giorno
       nella vista settimanale. Il tag del meteo resta fuori, che ha già il
       suo tocco e aprirebbe due cose insieme. */
    var vai = el('button', 'pl-ma-goto');
    vai.type = 'button';
    vai.setAttribute('aria-label', 'Vai a ' + etichetta + ' ' + d.getDate() + '/' + (d.getMonth() + 1));
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
        var chip = el('span', 'pl-ma-allday', 'tutto il giorno');
        chip.style.flexShrink = '0';
        mid.appendChild(chip);
    } else {
        mid.appendChild(el('span', 'pl-ma-hours', ev.start + ' – ' + ev.end));
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
/* Fascia priorità: struttura fissa sotto la barra                     */
/* ------------------------------------------------------------------ */
function buildDayBar() {
    if ($('plDayBar')) return;
    var bar = el('div', 'pl-daybar');
    bar.id = 'plDayBar';

    /* Etichette dei giorni: stanno qui, nella fascia fissa, non dentro le
       colonne. Dentro le colonne erano incollate alla mezzanotte e
       scomparivano appena si scorreva; qui restano sempre sotto le priorità
       e si spostano di lato insieme alla griglia. */
    var headRow = el('div', 'pl-headrow pl-hidden');
    headRow.id = 'plHeadRow';
    headRow.appendChild(el('div', 'pl-headgutter'));
    var headClip = el('div', 'pl-headclip');
    var headTrack = el('div', 'pl-headtrack');
    headTrack.id = 'plHeadTrack';
    headClip.appendChild(headTrack);
    headRow.appendChild(headClip);
    /* la aggiungiamo in fondo alla fascia (vedi sotto): deve stare sotto le
       priorità, appena sopra la sezione degli orari */

    var ask = el('div', 'pl-copyask pl-hidden');
    ask.id = 'plCopyAsk';
    ask.appendChild(el('div', 'pl-copyask-t', 'Vuoi partire copiando un altro giorno?'));
    var no = el('button', 'pl-mini-btn', 'No');
    no.type = 'button';
    no.addEventListener('click', function () { ask.classList.add('pl-hidden'); syncScrollerTop(); });
    var yes = el('button', 'pl-mini-btn pl-primary', 'Sì');
    yes.type = 'button';
    yes.addEventListener('click', openCopyDay);
    ask.appendChild(no); ask.appendChild(yes);
    bar.appendChild(ask);

    /* barra intelligente: si scrive in italiano e nasce l'evento */
    var smart = el('div', 'pl-smart');
    var smartIn = el('input', 'pl-smart-in');
    smartIn.type = 'text';
    smartIn.id = 'plSmartIn';
    smartIn.placeholder = 'Domani pranzo 15…';
    smartIn.maxLength = 200;
    var smartGo = el('button', 'pl-smart-go');
    smartGo.type = 'button';
    smartGo.setAttribute('aria-label', 'Crea');
    smartGo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13"/><path d="M12.5 6l6 6-6 6"/></svg>';
    var smartMic = el('button', 'pl-smart-mic');
    smartMic.type = 'button';
    smartMic.id = 'plSmartMic';
    smartMic.hidden = true;
    smartMic.setAttribute('aria-label', 'Dettalo invece di scriverlo');
    smartMic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0013 0"/><path d="M12 18v3"/></svg>';
    smart.appendChild(smartIn);
    smart.appendChild(smartMic);
    smart.appendChild(smartGo);
    setupSmartMic(smartMic, smartIn);
    smartGo.addEventListener('click', function () { smartCreate(smartIn); });
    smartIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); smartCreate(smartIn); }
    });
    bar.appendChild(smart);

    var wrap = el('div', 'pl-prio-wrap');
    var wpBtn = el('button', 'pl-wp-btn');
    wpBtn.type = 'button';
    wpBtn.id = 'plWeekPanelBtn';
    wpBtn.setAttribute('aria-label', 'Liste della settimana');
    wpBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 7h10M5 12h10M5 17h7"/><path d="M18.5 6.6l1.6 1.6M18.5 11.6l1.6 1.6"/></svg>';
    wpBtn.addEventListener('click', openWeekPanel);
    wrap.appendChild(wpBtn);

    var prio = el('div', 'pl-prio');
    prio.id = 'plPrio';
    var k = el('div', 'pl-prio-k');
    k.appendChild(el('span', 'pl-label', 'Priorità'));
    prio.appendChild(k);
    var rows = el('div', 'pl-prio-rows');
    rows.id = 'plPrioRows';
    prio.appendChild(rows);
    prio.addEventListener('click', function () {
        if (S.prioSlim) setPrioSlim(false);
    });
    wrap.appendChild(prio);
    bar.appendChild(wrap);
    bar.appendChild(headRow);   // ultima: resta appena sopra gli orari

    document.body.insertBefore(bar, $('plScroller'));
}

function syncScrollerTop() {
    var root = document.documentElement.style;
    var top = $('plTopbar').offsetHeight;
    var bar = $('plDayBar');
    var inMonth = S.view === 'month';
    var h = (bar && !inMonth) ? bar.offsetHeight : 0;
    var qb = $('plQuoteBar');
    var qh = (qb && !qb.classList.contains('pl-hidden') && !inMonth) ? qb.offsetHeight : 0;

    root.setProperty('--topbar-h', top + 'px');
    root.setProperty('--daybar-h', h + 'px');
    root.setProperty('--quotebar-h', qh + 'px');

    var sc = $('plScroller');
    sc.style.top = (top + h) + 'px';
    sc.style.bottom = qh + 'px';
    if (bar) bar.style.top = top + 'px';
    var fabs = $('plFabs');
    if (fabs) fabs.style.top = (top + h + 8) + 'px';
    var plus = $('plPlus');
    if (plus) plus.style.bottom = 'calc(' + (qh + 14) + 'px + var(--safe-bottom))';
    placeZoomDial();   // la rotellina resta allineata al tasto delle routine
}

/* le sezioni si muovono insieme all'apertura del calendario, senza salti */
function syncScrollerTopAnimated(ms) {
    var end = Date.now() + (ms || 480);
    (function step() {
        syncScrollerTop();
        if (Date.now() < end) requestAnimationFrame(step);
    })();
}

/* ==================================================================
   Gli avvisi in basso: una riga sola all'altezza del tasto +.

   Ci finiscono le pastiglie la cui ora è passata e la domanda «vuoi partire
   copiando un altro giorno?». Quest'ultima stava in cima alla pagina e
   allungava la barra ogni volta che compariva, spostando gli orari e tutto
   quello che sta sotto: un avviso non deve rimettere in fila la pagina per
   farsi leggere.
   ================================================================== */
function avvisiHost() {
    var h = $('plAvvisi');
    if (h) return h;
    h = el('div', 'pl-avvisi');
    h.id = 'plAvvisi';
    document.body.appendChild(h);
    return h;
}

function renderAvvisi() {
    var host = avvisiHost();
    host.innerHTML = '';

    var pastiglie = (S.pillsDue || []).filter(function (d) { return !S.pillsChiuse[d.id]; });

    /* La copia del giorno vale solo per un giorno futuro e vuoto: è l'unico
       momento in cui la domanda ha senso. */
    var copia = !(S.events[S.focusDate] || []).length && S.focusDate > S.today
                && S.view === 'day' && !S.copiaChiusa;

    if (!pastiglie.length && !copia) { host.classList.add('pl-hidden'); return; }
    host.classList.remove('pl-hidden');

    /* Una x sola che li chiude tutti, quando ce n'è più di uno: con cinque
       arretrate, cinque tocchi per liberare lo schermo sono cinque troppi. */
    if (pastiglie.length > 1) {
        var tutte = el('button', 'pl-avviso-tutte', 'Chiudi tutti');
        tutte.type = 'button';
        tutte.addEventListener('click', function () {
            pastiglie.forEach(function (d) { S.pillsChiuse[d.id] = true; });
            renderAvvisi();
        });
        host.appendChild(tutte);
    }

    pastiglie.forEach(function (d) {
        var riga = el('div', 'pl-avviso');
        riga.style.setProperty('--ev', d.color || 'var(--accent)');

        /* La spunta scrive nel registro delle prese, quindi risulta spuntata
           anche nella sezione pastiglie e negli elenchi: è la stessa presa,
           non una copia. */
        var ok = el('button', 'pl-avviso-ok');
        ok.type = 'button';
        ok.setAttribute('aria-label', 'Presa');
        ok.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        ok.addEventListener('click', function () {
            rispondiPastiglia(d, 'taken', riga);
        });
        riga.appendChild(ok);

        var quando = d.oggi ? ('alle ' + d.time)
                            : (nomeGiornoBreve(d.date) + ' alle ' + d.time);
        /* Il nome della terapia in grassetto: è quello che si cerca leggendo,
           e con tre riquadri impilati la differenza fra uno e l'altro sta
           tutta lì. */
        var t = el('span', 'pl-avviso-t');
        t.appendChild(document.createTextNode('Hai preso ' + (d.dose ? d.dose + ' di ' : '')));
        t.appendChild(el('b', null, d.name));
        t.appendChild(document.createTextNode(' ' + quando + '?'));
        riga.appendChild(t);

        /* «Saltato» scrive nel database, non chiude e basta: quella presa non
           deve tornare nemmeno ricaricando la pagina. Se la stessa pastiglia
           è prevista un'altra volta, quella è un'altra presa e la chiederà. */
        var salta = el('button', 'pl-avviso-salta', 'Saltato');
        salta.type = 'button';
        salta.addEventListener('click', function () {
            rispondiPastiglia(d, 'skipped', riga);
        });
        riga.appendChild(salta);

        /* La x invece chiude solo per adesso: al prossimo caricamento la
           domanda torna, perché la pastiglia resta da prendere. */
        var x = el('button', 'pl-avviso-x');
        x.type = 'button';
        x.setAttribute('aria-label', 'Chiudi per ora');
        x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        x.addEventListener('click', function () {
            S.pillsChiuse[d.id] = true;
            renderAvvisi();
        });
        riga.appendChild(x);

        host.appendChild(riga);
    });

    if (copia) {
        /* Stesse classi di prima — `pl-copyask`, il testo, i due tastini con
           sì e no — così si porta dietro il colore scelto per la pagina e
           l'aspetto che aveva. È cambiato solo dove sta: qui sotto invece che
           in cima, dove allungava la barra ogni volta che compariva. */
        var c = el('div', 'pl-copyask pl-copyask-basso');
        c.appendChild(el('div', 'pl-copyask-t', 'Vuoi partire copiando un altro giorno?'));
        var no = el('button', 'pl-mini-btn', 'No');
        no.type = 'button';
        no.addEventListener('click', function () { S.copiaChiusa = true; renderAvvisi(); });
        var si = el('button', 'pl-mini-btn pl-primary', 'Sì');
        si.type = 'button';
        si.addEventListener('click', function () { openCopyDay(); });
        c.appendChild(no); c.appendChild(si);
        host.appendChild(c);
    }
}

function rispondiPastiglia(d, stato, riga) {
    riga.classList.add('pl-avviso-via');
    haptic(8);
    todoApi('pill_mark', { id: d.id, status: stato }).then(function (r) {
        if (r && r.ok === false) {
            riga.classList.remove('pl-avviso-via');
            toast((r && r.message) || 'Non e stato possibile registrarlo.');
            return;
        }
        S.pillsDue = (S.pillsDue || []).filter(function (x) { return x.id !== d.id; });
        /* la presa risulta spuntata anche negli elenchi, se sono aperti */
        if (S.todos && S.todos.pills) loadWeekTodos('pills', true);
        setTimeout(renderAvvisi, 260);
    });
}

/* Il giorno a tre lettere, per le arretrate: «mar alle 09:00» dice più di una
   data intera in un riquadro alto una riga. */
function nomeGiornoBreve(ds) {
    /* Tre lettere da GIORNI, non la sigla di GIORNI_S: là lunedì e martedì
       sono entrambi «M» e in una domanda su una presa dimenticata non si
       capirebbe di quale giorno si parla. */
    var d = parseIso(ds);
    return GIORNI[(d.getDay() + 6) % 7].slice(0, 3).toLowerCase();
}

function maybeAskCopy() {
    /* Il riquadro in cima non esiste più: la domanda è scesa in fondo, fra
       gli avvisi, così la barra sopra resta sempre della stessa altezza.
       La funzione resta perché la chiamano in cinque posti diversi. */
    var ask = $('plCopyAsk');
    if (ask) ask.classList.add('pl-hidden');
    renderAvvisi();
}

function openCopyDay() {
    openSheet('Copia un altro giorno', function (body) {
        var picked = null;
        body.appendChild(el('div', 'pl-row-sub', 'Scegli il giorno da cui copiare gli eventi.'));
        var grid = el('div');
        grid.style.marginTop = '10px';
        var cursor = parseIso(S.focusDate);
        var start = monday(iso(new Date(cursor.getFullYear(), cursor.getMonth(), 1)));
        var wd = el('div', 'pl-mwd');
        GIORNI_S.forEach(function (g) { wd.appendChild(el('span', null, g)); });
        grid.appendChild(wd);
        for (var r = 0; r < 5; r++) {
            var row = el('div', 'pl-mrow');
            row.style.height = '62px';
            for (var c = 0; c < 7; c++) {
                (function (ds) {
                    var cell = el('div', 'pl-mcell' + (parseIso(ds).getMonth() !== cursor.getMonth() ? ' pl-out' : ''));
                    cell.appendChild(el('div', 'pl-mcell-n pl-serif', String(parseIso(ds).getDate())));
                    eventsOf(ds).slice(0, 2).forEach(function (ev) {
                        var e = el('div', 'pl-mev', ev.title);
                        e.style.setProperty('--ev', evColor(ev));
                        cell.appendChild(e);
                    });
                    cell.addEventListener('click', function () {
                        picked = ds;
                        Array.prototype.forEach.call(grid.querySelectorAll('.pl-mcell'), function (x) { x.style.boxShadow = ''; });
                        cell.style.boxShadow = 'inset 0 0 0 1.5px var(--accent)';
                        drawPreview();
                    });
                    row.appendChild(cell);
                })(addDays(start, r * 7 + c));
            }
            grid.appendChild(row);
        }
        body.appendChild(grid);

        var preview = el('div');
        preview.style.marginTop = '14px';
        body.appendChild(preview);

        var acts = el('div', 'pl-alert-acts');
        acts.style.marginTop = '16px';
        var cancel = el('button', 'pl-btn', 'Annulla');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { closeOv('plSheetOv'); });
        acts.appendChild(cancel);
        body.appendChild(acts);

        function drawPreview() {
            preview.innerHTML = '';
            if (!picked) return;
            var d = parseIso(picked);
            preview.appendChild(el('div', 'pl-label', d.getDate() + ' ' + MESI[d.getMonth()]));
            var list = eventsOf(picked);
            if (!list.length) {
                preview.appendChild(el('div', 'pl-quote pl-serif', 'Questo giorno è vuoto.'));
                return;
            }
            list.forEach(function (ev) {
                var row = el('div', 'pl-li');
                row.appendChild(el('div', 'pl-row-sub', ev.all_day ? 'tutto il giorno' : ev.start + ' – ' + ev.end));
                var t = el('div', 'pl-row-lab', ev.title);
                t.style.flex = '1';
                t.style.marginLeft = '8px';
                row.appendChild(t);
                preview.appendChild(row);
            });
            if (!acts.querySelector('.pl-btn-primary')) {
                var copy = el('button', 'pl-btn pl-btn-primary', 'Copia');
                copy.type = 'button';
                copy.addEventListener('click', function () {
                    api('copy_day', { from: picked, to: S.focusDate }).then(function (r) {
                        if (!r.ok) { toast(r.message || 'Copia non riuscita.'); return; }
                        indexEvents(r.events, { from: S.focusDate, to: S.focusDate });
                        closeOv('plSheetOv');
                        renderDays();
                        maybeAskCopy();
                        toast(r.copied + (r.copied === 1 ? ' evento copiato' : ' eventi copiati'));
                    });
                });
                acts.appendChild(copy);
            }
        }
    });
}

/* ------------------------------------------------------------------ */
/* Avvio                                                               */
/* ------------------------------------------------------------------ */
/* ------------------------------------------------------------------ */
/* Notifiche                                                           */
/* ------------------------------------------------------------------ */
/* Finora "Avvisami" si limitava a salvare la preferenza: nessuno la leggeva
   mai, quindi non è mai arrivato un avviso. Qui c'è chi la legge.

   Come funziona: ogni mezzo minuto si guarda quali eventi hanno un orario di
   avviso ormai passato ma non ancora suonato, e li si annuncia. Niente timer
   lunghi (il telefono che si addormenta li manda a monte) e niente coda da
   ricostruire: basta l'orologio. Ciò che è già suonato resta segnato, così
   riaprendo la pagina non ripiove tutto insieme.

   Limite da sapere: così gli avvisi arrivano quando il planner è aperto (anche
   solo in sottofondo, se installato come app). Per riceverli a pagina chiusa
   serve un push dal server, che è un lavoro a parte. */
var PL_NOTIFY = (function () {

    var OFFSET = { none: null, at_time: 0, m30: 30, h1: 60, d1: 1440 };
    var STORE = 'plNotifiedV1';
    var GRACE = 10 * 60 * 1000;      // oltre dieci minuti di ritardo non si annuncia più
    var swReg = null;
    var fired = {};
    var ticker = null;

    function load() {
        try { fired = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; }
        catch (e) { fired = {}; }
    }
    function save() {
        /* si tiene solo l'ultima giornata: la memoria non deve crescere per sempre */
        var limite = Date.now() - 36 * 3600 * 1000, pulito = {};
        for (var k in fired) if (fired.hasOwnProperty(k) && fired[k] > limite) pulito[k] = fired[k];
        fired = pulito;
        try { localStorage.setItem(STORE, JSON.stringify(fired)); } catch (e) {}
    }

    /* quanti minuti prima va dato l'avviso, oppure null se non ne vuole */
    function minutiPrima(ev) {
        if (!ev || ev.done) return null;
        var t = ev.notify || 'none';
        if (t === 'custom') {
            var m = parseInt(ev.notify_min, 10);
            return isNaN(m) ? null : Math.max(0, m);
        }
        var o = OFFSET[t];
        return (o === undefined || o === null) ? null : o;
    }

    /* il momento esatto in cui suonare, in millisecondi */
    function quando(ev) {
        var off = minutiPrima(ev);
        if (off === null) return null;
        var p = String(ev.date || '').split('-');
        if (p.length !== 3) return null;
        /* gli eventi di giornata intera si annunciano alle nove del mattino,
           non a mezzanotte: un avviso nel sonno non serve a nessuno */
        var min = ev.all_day ? 9 * 60 : toMin(ev.start || '00:00');
        var d = new Date(+p[0], +p[1] - 1, +p[2], 0, min - off, 0, 0);
        return d.getTime();
    }

    function testo(ev, off) {
        if (ev.all_day) return 'Oggi';
        if (off === 0) return 'Adesso, ' + ev.start;
        if (off === 30) return 'Tra mezz\'ora, alle ' + ev.start;
        if (off === 60) return 'Tra un\'ora, alle ' + ev.start;
        if (off === 1440) return 'Domani alle ' + ev.start;
        if (off < 60) return 'Tra ' + off + ' minuti, alle ' + ev.start;
        var h = Math.floor(off / 60), m = off % 60;
        return 'Tra ' + h + 'h' + (m ? ' ' + m + 'm' : '') + ', alle ' + ev.start;
    }

    /* Su Android il costruttore Notification non è ammesso: bisogna passare
       dal service worker. Qui si prova prima quella strada e si ripiega
       sull'altra solo dove funziona (desktop, iOS installato). */
    function mostra(ev, off) {
        var titolo = ev.title || 'Promemoria';
        var opz = {
            body: testo(ev, off),
            tag: 'pl-ev-' + ev.id,
            icon: 'icon-192.png',
            badge: 'icon-192.png',
            data: { date: ev.date, id: ev.id },
            requireInteraction: false
        };
        if (swReg && swReg.showNotification) {
            swReg.showNotification(titolo, opz).catch(function () {});
            return;
        }
        try { new Notification(titolo, opz); } catch (e) {}
    }

    function giro() {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        var ora = Date.now(), cambiato = false;

        /* si guardano solo ieri, oggi e domani: più in là non c'è nulla da
           annunciare adesso, e l'archivio degli eventi può essere grosso */
        [addDays(S.today, -1), S.today, addDays(S.today, 1)].forEach(function (giorno) {
            (S.events[giorno] || []).forEach(function (ev) {
                var off = minutiPrima(ev);
                if (off === null) return;
                var t = quando(ev);
                if (t === null || t > ora || ora - t > GRACE) return;
                var chiave = ev.id + '@' + t;
                if (fired[chiave]) return;
                fired[chiave] = ora;
                cambiato = true;
                mostra(ev, off);
            });
        });
        if (cambiato) save();
    }

    /* Il permesso si chiede solo quando ha senso chiederlo: nel momento in cui
       si sceglie "Avvisami" su un evento. Una richiesta a freddo all'apertura
       della pagina viene rifiutata quasi sempre, e poi non si può più tornare
       indietro. */
    function chiediPermesso() {
        if (!('Notification' in window)) {
            toast('Questo browser non sa mostrare avvisi.');
            return Promise.resolve(false);
        }
        if (Notification.permission === 'granted') return Promise.resolve(true);
        if (Notification.permission === 'denied') {
            toast('Gli avvisi sono bloccati nelle impostazioni del browser.');
            return Promise.resolve(false);
        }
        return Notification.requestPermission().then(function (p) {
            if (p === 'granted') { avvia(); toast('Avvisi attivi.'); return true; }
            return false;
        }).catch(function () { return false; });
    }

    function avvia() {
        if (ticker) return;
        giro();
        ticker = setInterval(giro, 30 * 1000);
    }

    function init() {
        if (!('Notification' in window)) return;
        load();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').then(function (reg) {
                swReg = reg;
            }).catch(function () { /* senza service worker si ripiega sul resto */ });
        }
        if (Notification.permission === 'granted') avvia();
        /* tornando sulla pagina dopo un po' si recupera subito quel che era
           maturato mentre era in sottofondo */
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') giro();
        });
    }

    return { init: init, chiediPermesso: chiediPermesso, giro: giro };
})();

/* ------------------------------------------------------------------ */
/* La colonna dei tastini che si richiude                              */
/* ------------------------------------------------------------------ */
/* Le abitudini restano sempre in vista: sono l'unica cosa che si guarda
   senza doverla cercare. Tutto il resto sta dentro `plFabGroup` e scende da
   sotto il tondo delle abitudini quando si apre.

   L'altezza si anima con `max-height`, che chiede un ritaglio: ma i menù dei
   tastini escono dal gruppo, e ritagliati sparirebbero. Quindi il ritaglio
   c'è solo mentre la cosa si muove, e a fine corsa il gruppo torna libero.
   È il motivo della classe `pl-libero` e del suo ritardo. */
var fabTimer = null;
function applyFabs(aperto, animato) {
    var g = $('plFabGroup'), t = $('plFabToggle');
    if (!g || !t) return;
    if (fabTimer) { clearTimeout(fabTimer); fabTimer = null; }

    t.classList.toggle('pl-aperto', aperto);
    t.setAttribute('aria-expanded', aperto ? 'true' : 'false');
    if (!animato) g.classList.add('pl-nomoto');

    if (aperto) {
        g.classList.remove('pl-libero');      // si richiude il ritaglio per la corsa
        g.classList.add('pl-open');
        fabTimer = setTimeout(function () { g.classList.add('pl-libero'); }, animato ? 340 : 0);
    } else {
        /* prima si toglie la libertà, se no il ritaglio non c'è e i tastini
           invece di rientrare sotto le abitudini svanirebbero sul posto */
        g.classList.remove('pl-libero');
        closeMenus(null);
        void g.offsetHeight;                  // una misura sola, per far partire la transizione
        g.classList.remove('pl-open');
    }
    if (!animato) {
        void g.offsetHeight;
        g.classList.remove('pl-nomoto');
    }
}

function initFabs() {
    var t = $('plFabToggle');
    if (!t) return;
    applyFabs(S.settings.fabs_open !== false, false);
    t.addEventListener('click', function () {
        var aperto = !$('plFabGroup').classList.contains('pl-open');
        S.settings.fabs_open = aperto;
        applyFabs(aperto, true);
        haptic(8);
        api('settings_save', { fabs_open: aperto });
        /* la rotellina si appoggia al tasto delle routine, che ora è altrove */
        setTimeout(placeZoomDial, 360);
    });
}

function boot() {
    applyPalette(S.settings.palette || 'sage');
    applyContrast();
    applyAmbientOpacity();
    initFabs();
    document.documentElement.style.setProperty('--days-visible', Math.max(1, Math.min(7, S.settings.days_visible || 3)));
    S.pointerFine = window.matchMedia('(pointer:fine)').matches;
    renderZoomDial();
    refreshZoomDial();   // su PC e in vista lista resta a portata
    applySky();
    setInterval(applySky, 5 * 60 * 1000);

    $('plFabFav').classList.toggle('pl-hidden', !(S.favorites && S.favorites.length));
    S.categories.forEach(function (c) { if (c.hidden) S.hiddenCats[c.id] = true; });
    $('plFilterBtn').classList.toggle('pl-on', Object.keys(S.hiddenCats).length > 0);

    indexEvents(B.events, B.range ? { from: B.range.from, to: B.range.to } : null);
    indexPrio(B.priorities || {});
    S.habits[S.today] = B.habits || [];
    S.rings[S.today]  = B.rings || [];
    S.weekly[monday(S.today)] = B.weekly || [];
    S.weekCache[monday(S.today)] = { week: B.week || {}, lists: B.lists || [] };

    buildDayBar();
    buildTrack();
    renderWeekBits();
    renderPrio();
    syncScrollerTop();
    maybeAskCopy();

    ensureStartScroll();
    loadAmbient();
    loadSmart();
    loadRubrica();
    wxLoad();
    PL_NOTIFY.init();

    /* all'apertura: se l'ultimo allineamento con Google è vecchio, se ne fa
       uno in sottofondo senza disturbare */
    if (S.settings.google_sync) {
        setTimeout(function () {
            gcall('status').then(function (st) {
                if (!st || !st.ok || !st.connected) return;
                var last = st.last_sync ? new Date(st.last_sync.replace(' ', 'T')).getTime() : 0;
                if (Date.now() - last > 10 * 60 * 1000) runSync(true);
            });
        }, 2500);
    }

    if (S.settings.default_view === 'month') setView('month');

    // la linea dell'ora si aggiorna da sola
    setInterval(function () {
        if (S.view === 'day') renderDays();
        /* nella settimana basta spostare la riga: rifare la griglia ogni
           minuto vorrebbe dire rifare anche tutte le caselle, e lo scorrimento
           in corso ne uscirebbe scosso */
        else if (S.view === 'week') wkLineaAdesso();
    }, 60000);

    // niente zoom del browser con due dita fuori dalla griglia
    document.addEventListener('gesturestart', function (e) { e.preventDefault(); });
    document.addEventListener('dblclick', function (e) { e.preventDefault(); }, { passive: false });
    window.addEventListener('resize', function () { syncScrollerTop(); scrollToDate(S.focusDate, false); });
    window.addEventListener('orientationchange', function () { setTimeout(function () { buildTrack(); syncScrollerTop(); }, 260); });
}

// l'utente che torna dalla pagina habits deve rivedere le spunte aggiornate
document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    S.habits = {};
    S.rings = {};              // tornando da habits le spunte possono essere cambiate
    S.weekCache = {};
    loadWeek(monday(S.focusDate), true).then(renderRing);
});

boot();
window.PL = { state: S, api: api, reload: function () { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }, toast: toast };

})();
