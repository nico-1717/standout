/* planner/js/13_avvio.js — fascia delle priorita (struttura), avvio, notifiche, colonna dei tastini.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
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
    ask.appendChild(el('div', 'pl-copyask-t', T('planner.vuoi_partire_copiando_un_altro')));
    var no = el('button', 'pl-mini-btn', T('comune.no'));
    no.type = 'button';
    no.addEventListener('click', function () { ask.classList.add('pl-hidden'); syncScrollerTop(); });
    var yes = el('button', 'pl-mini-btn pl-primary', T('comune.si'));
    yes.type = 'button';
    yes.addEventListener('click', openCopyDay);
    ask.appendChild(no); ask.appendChild(yes);
    bar.appendChild(ask);

    /* barra intelligente: si scrive in italiano e nasce l'evento */
    var smart = el('div', 'pl-smart');
    var smartIn = el('input', 'pl-smart-in');
    smartIn.type = 'text';
    smartIn.id = 'plSmartIn';
    smartIn.placeholder = T('planner.domani_pranzo_15');
    smartIn.maxLength = 200;
    var smartGo = el('button', 'pl-smart-go');
    smartGo.type = 'button';
    smartGo.setAttribute('aria-label', T('planner.crea'));
    smartGo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13"/><path d="M12.5 6l6 6-6 6"/></svg>';
    var smartMic = el('button', 'pl-smart-mic');
    smartMic.type = 'button';
    smartMic.id = 'plSmartMic';
    smartMic.hidden = true;
    smartMic.setAttribute('aria-label', T('planner.dettalo_invece_di_scriverlo'));
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
    wpBtn.setAttribute('aria-label', T('planner.liste_della_settimana'));
    wpBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 7h10M5 12h10M5 17h7"/><path d="M18.5 6.6l1.6 1.6M18.5 11.6l1.6 1.6"/></svg>';
    wpBtn.addEventListener('click', openWeekPanel);
    wrap.appendChild(wpBtn);

    var prio = el('div', 'pl-prio');
    prio.id = 'plPrio';
    var k = el('div', 'pl-prio-k');
    k.appendChild(el('span', 'pl-label', T('planner.priorita')));
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
        var tutte = el('button', 'pl-avviso-tutte', T('planner.chiudi_tutti'));
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
        ok.setAttribute('aria-label', T('planner.presa'));
        ok.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        ok.addEventListener('click', function () {
            rispondiPastiglia(d, 'taken', riga);
        });
        riga.appendChild(ok);

        var quando = d.oggi ? T('planner.alle_ora', {ora: d.time})
                            : T('planner.giorno_alle_ora', {giorno: nomeGiornoBreve(d.date), ora: d.time});
        /* Il nome della terapia in grassetto: è quello che si cerca leggendo,
           e con tre riquadri impilati la differenza fra uno e l'altro sta
           tutta lì. */
        var t = el('span', 'pl-avviso-t');
        t.appendChild(document.createTextNode(d.dose ? T('planner.hai_preso_dose', {dose: d.dose}) : T('planner.hai_preso')));
        t.appendChild(el('b', null, d.name));
        t.appendChild(document.createTextNode(' ' + quando + '?'));
        riga.appendChild(t);

        /* «Saltato» scrive nel database, non chiude e basta: quella presa non
           deve tornare nemmeno ricaricando la pagina. Se la stessa pastiglia
           è prevista un'altra volta, quella è un'altra presa e la chiederà. */
        var salta = el('button', 'pl-avviso-salta', T('planner.saltato'));
        salta.type = 'button';
        salta.addEventListener('click', function () {
            rispondiPastiglia(d, 'skipped', riga);
        });
        riga.appendChild(salta);

        /* La x invece chiude solo per adesso: al prossimo caricamento la
           domanda torna, perché la pastiglia resta da prendere. */
        var x = el('button', 'pl-avviso-x');
        x.type = 'button';
        x.setAttribute('aria-label', T('planner.chiudi_per_ora'));
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
        c.appendChild(el('div', 'pl-copyask-t', T('planner.vuoi_partire_copiando_un_altro')));
        var no = el('button', 'pl-mini-btn', T('comune.no'));
        no.type = 'button';
        no.addEventListener('click', function () { S.copiaChiusa = true; renderAvvisi(); });
        var si = el('button', 'pl-mini-btn pl-primary', T('comune.si'));
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
            toast((r && r.message) || T('planner.non_e_stato_possibile_registrarlo'));
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
    openSheet(T('planner.copia_un_altro_giorno'), function (body) {
        var picked = null;
        body.appendChild(el('div', 'pl-row-sub', T('planner.scegli_il_giorno_da_cui')));
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
        var cancel = el('button', 'pl-btn', T('planner.annulla'));
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
                preview.appendChild(el('div', 'pl-quote pl-serif', T('planner.questo_giorno_e_vuoto')));
                return;
            }
            list.forEach(function (ev) {
                var row = el('div', 'pl-li');
                row.appendChild(el('div', 'pl-row-sub', ev.all_day ? T('planner.tutto_il_giorno_l') : ev.start + ' – ' + ev.end));
                var t = el('div', 'pl-row-lab', ev.title);
                t.style.flex = '1';
                t.style.marginLeft = '8px';
                row.appendChild(t);
                preview.appendChild(row);
            });
            if (!acts.querySelector('.pl-btn-primary')) {
                var copy = el('button', 'pl-btn pl-btn-primary', T('planner.copia'));
                copy.type = 'button';
                copy.addEventListener('click', function () {
                    api('copy_day', { from: picked, to: S.focusDate }).then(function (r) {
                        if (!r.ok) { toast(r.message || T('planner.copia_non_riuscita')); return; }
                        indexEvents(r.events, { from: S.focusDate, to: S.focusDate });
                        closeOv('plSheetOv');
                        renderDays();
                        maybeAskCopy();
                        toast(T(r.copied === 1 ? 'planner.n_evento_copiato' : 'planner.n_eventi_copiati', {n: r.copied}));
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
        if (ev.all_day) return T('comune.oggi');
        if (off === 0) return T('planner.adesso_alle', {ora: ev.start});
        if (off === 30) return T('planner.tra_mezz_ora', {ora: ev.start});
        if (off === 60) return T('planner.tra_un_ora', {ora: ev.start});
        if (off === 1440) return T('planner.domani_alle', {ora: ev.start});
        if (off < 60) return T('planner.tra_n_minuti', {n: off, ora: ev.start});
        var h = Math.floor(off / 60), m = off % 60;
        return T('planner.tra_') + h + 'h' + (m ? ' ' + m + 'm' : '') + T('planner.alle_') + ev.start;
    }

    /* Su Android il costruttore Notification non è ammesso: bisogna passare
       dal service worker. Qui si prova prima quella strada e si ripiega
       sull'altra solo dove funziona (desktop, iOS installato). */
    function mostra(ev, off) {
        var titolo = ev.title || T('planner.promemoria');
        var opz = {
            body: testo(ev, off),
            tag: 'pl-ev-' + ev.id,
            icon: '../_assets/icons/icon-192.png',
            badge: '../_assets/icons/icon-192.png',
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
            toast(T('planner.questo_browser_non_sa_mostrare'));
            return Promise.resolve(false);
        }
        if (Notification.permission === 'granted') return Promise.resolve(true);
        if (Notification.permission === 'denied') {
            toast(T('planner.gli_avvisi_sono_bloccati_nelle'));
            return Promise.resolve(false);
        }
        return Notification.requestPermission().then(function (p) {
            if (p === 'granted') { avvia(); toast(T('planner.avvisi_attivi')); return true; }
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
            navigator.serviceWorker.register('../sw.js').then(function (reg) {
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

    /* le modifiche rimaste in coda dall'ultima visita (pagina chiusa senza
       rete, per esempio) tornano visibili e ripartono */
    codaCarica();
    indexEvents(B.events, B.range ? { from: B.range.from, to: B.range.to } : null);
    if (CODA.ops.length) { codaSpia(); setTimeout(codaGira, 400); }
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
