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
    routines:    B.routines || [],
    favorites:   B.favorites || [],
    week:        B.week || {},
    lists:       B.lists || [],
    diary:       B.diary || {},
    events:      {},          // 'YYYY-MM-DD' -> [evento]
    prio:        {},          // 'YYYY-MM-DD' -> [priorità]
    habits:      {},          // 'YYYY-MM-DD' -> [abitudine]
    focusDate:   B.today,
    centerWeek:  null,
    zoom:        (B.settings && B.settings.zoom) || 'm30',
    view:        'day',
    monthCursor: null,
    defaultCat:  null,
    hiddenCats:  {},
    prioSlim:    false,
    weekCache:   {},          // 'lunedì' -> { week, lists }
    shift:       { on: false, dir: 'late', picked: {} },
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
var LIST_LABEL = { todo: 'To do', buy: 'To buy', study: 'To study' };

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
function pxMin() { var z = zoomCfg(S.zoom); return z.slot ? z.h / z.slot : 0; }
function dayHeight() { return Math.round(1440 * pxMin()); }
function isAgenda() { return S.zoom === 'agenda'; }

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
    return NEUTRAL;
}
function evHidden(ev) { return !!(ev.category_id && S.hiddenCats[ev.category_id]); }

function haptic(ms) { if (navigator.vibrate) { try { navigator.vibrate(ms || 8); } catch (e) {} } }

/* ------------------------------------------------------------------ */
/* Chiamate al server                                                  */
/* ------------------------------------------------------------------ */
function api(action, data, query) {
    var url = 'planner.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
    var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf } };
    if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    return fetch(url, opt).then(function (r) {
        return r.json().catch(function () { return { ok: false, message: 'Risposta non valida.' }; });
    }).catch(function () {
        return { ok: false, message: 'Connessione non riuscita.' };
    });
}

var saveQueue = {};
function debounceSave(key, ms, fn) {
    if (saveQueue[key]) clearTimeout(saveQueue[key]);
    saveQueue[key] = setTimeout(function () { saveQueue[key] = null; fn(); }, ms || 700);
}

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
    return api('range', null, { from: weekStart, to: addDays(weekStart, 6) }).then(function (r) {
        if (!r.ok) return;
        indexEvents(r.events, { from: r.from, to: r.to });
        indexPrio(r.priorities || {});
        for (var d in r.diary) if (r.diary.hasOwnProperty(d)) S.diary[d] = r.diary[d];
        S.weekCache[weekStart] = { week: r.week, lists: r.lists || [] };
        if (monday(S.focusDate) === weekStart) applyWeek(weekStart);
        renderDays();
        renderPrio();
        maybeAskCopy();
    });
}

function loadHabits(date) {
    if (S.habits[date]) return Promise.resolve(S.habits[date]);
    return api('habits_day', null, { date: date }).then(function (r) {
        S.habits[date] = r.ok ? r.habits : [];
        return S.habits[date];
    });
}

/* ------------------------------------------------------------------ */
/* Cielo, insetto, palette                                             */
/* ------------------------------------------------------------------ */
/* ---- alberi, foglie, insetti: una scena diversa per ogni mese ---- */
var AMB = null, ambMonth = null;

var TREES = {
    bare:   '<path d="M30 80V30"/><path d="M30 44c-8-3-13-10-14-18 8 2 13 8 14 14"/><path d="M30 38c8-4 12-12 12-20-8 2-12 8-12 14"/><path d="M30 56c-6-2-10-7-11-13 6 1 10 4 11 8"/>',
    buds:   '<path d="M30 80V30"/><path d="M30 44c-8-3-13-10-14-18 8 2 13 8 14 14"/><path d="M30 38c8-4 12-12 12-20-8 2-12 8-12 14"/>',
    blossom:'<path d="M30 80V32"/><path d="M30 46c-9-3-14-11-15-19 9 2 14 9 15 15"/><path d="M30 40c9-4 13-12 13-21-9 3-13 9-13 15"/>',
    leafy:  '<path d="M30 80V36"/><ellipse cx="30" cy="22" rx="15" ry="12"/><ellipse cx="19" cy="33" rx="10" ry="8"/><ellipse cx="42" cy="32" rx="9" ry="7"/>',
    lush:   '<path d="M30 80V40"/><ellipse cx="30" cy="20" rx="17" ry="14"/><ellipse cx="16" cy="34" rx="11" ry="9"/><ellipse cx="44" cy="33" rx="11" ry="9"/><ellipse cx="30" cy="36" rx="13" ry="8"/>',
    dry:    '<path d="M30 80V34"/><path d="M30 48c-7-3-11-9-12-16 7 2 11 7 12 12"/><path d="M30 42c7-3 10-10 10-17-7 2-10 7-10 12"/><path d="M30 60c-5-1-8-5-9-9 5 1 8 3 9 6"/>',
    autumn: '<path d="M30 80V38"/><path d="M30 40c-11-2-17-10-18-19 11 2 17 9 18 16"/><path d="M30 36c10-3 15-11 15-20-10 3-15 9-15 15"/>'
};
var BUDS = {
    buds:   [[18,26],[24,20],[38,22],[43,16],[22,36],[40,34]],
    blossom:[[17,25],[23,18],[30,14],[37,20],[43,15],[20,34],[40,32],[27,28],[34,30]],
    autumn: [[16,24],[24,17],[33,15],[41,19],[45,26],[21,32],[38,30]]
};

function svgWrap(inner, extra) {
    return '<svg viewBox="0 0 60 80" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round">'
         + inner + (extra || '') + '</svg>';
}
function treeMarkup(kind, budColor) {
    var body = TREES[kind] || TREES.leafy;
    var dots = '';
    (BUDS[kind] || []).forEach(function (p) {
        dots += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="1.9" fill="' + (budColor || 'currentColor') + '" stroke="none"/>';
    });
    return svgWrap(body, dots);
}

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

function renderAmbient(cfg) {
    var host = $('plAmbient');
    if (!host) return;
    host.innerHTML = '';
    var color = cfg.treeColor || '#9BAA9C';

    ['pl-tree-a', 'pl-tree-b'].forEach(function (cls, i) {
        var t = el('div', 'pl-tree ' + cls);
        t.style.color = color;
        if (i === 1) t.style.animationDelay = '-4s';
        t.innerHTML = treeMarkup(cfg.tree || 'leafy', cfg.budColor);
        host.appendChild(t);
    });

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
            var wrap = el('div', 'pl-drift' + (isFly ? ' pl-firefly' : '') + (d.type === 'bird' || d.type === 'swallow' ? ' pl-drift-slow' : ''));
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

function applyAmbient(force) {
    var host = $('plAmbient');
    if (!host) return;
    if (S.settings.ambient_enabled === false) { host.innerHTML = ''; ambMonth = null; return; }
    if (!AMB) return;
    var base = S.view === 'month' ? (S.monthCursor || S.focusDate) : S.focusDate;
    var m = parseIso(base).getMonth() + 1;
    if (!force && m === ambMonth) return;
    ambMonth = m;
    /* mese nuovo, cielo nuovo */
    setTimeout(applySky, 0);
    renderAmbient((AMB.months && AMB.months[String(m)]) || {});
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
    var data = prioOf(S.focusDate);
    host.innerHTML = '';

    var cells = [];
    data.autos.forEach(function (a) { cells.push({ auto: true, content: a.content }); });

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
    for (var r = 0; r < shown.length; r += 2) {
        var row = el('div', 'pl-prio-row');
        for (var c = r; c < Math.min(r + 2, shown.length); c++) row.appendChild(prioCell(shown[c]));
        host.appendChild(row);
    }
    $('plPrio').classList.toggle('pl-slim', S.prioSlim);
    syncScrollerTop();
}

function prioCell(data) {
    var cell = el('div', 'pl-prio-cell' + (data.done ? ' pl-done' : '') + (data.auto ? ' pl-auto' : ''));
    var dot = el('span', 'pl-prio-dot' + (data.done ? ' pl-on' : ''));
    dot.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    var inp = el('input', 'pl-prio-in');
    inp.type = 'text';
    inp.value = data.content || '';
    inp.placeholder = data.auto ? '' : 'Priorità';
    inp.maxLength = 200;
    if (data.auto) inp.readOnly = true;
    cell.appendChild(dot);
    cell.appendChild(inp);
    if (data.auto) return cell;

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
    g.style.height = dayHeight() + 'px';
    var majorEvery = z.slot >= 60 ? 1 : (z.slot === 30 ? 2 : (z.slot === 15 ? 4 : 12));
    for (var m = 0; m <= 1440; m += z.slot) {
        if (m === 1440) break;
        var isMajor = (m / z.slot) % majorEvery === 0;
        var lab = el('div', 'pl-gutter-t' + (isMajor ? ' pl-major' : ''), toHM(m));
        lab.style.top = (HEAD_H + m * pxMin()) + 'px';
        g.appendChild(lab);
    }
}

/* ------------------------------------------------------------------ */
/* Disegno degli eventi                                                */
/* ------------------------------------------------------------------ */
/* gli eventi di tutta la giornata stanno sopra alle priorità: così il
   righello degli orari resta allineato a tutte le colonne */
function renderAllDay() {
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
        /* nella vista a lista gli eventi di tutto il giorno stanno in mezzo
           agli altri, quindi qui non ne mettiamo nessuno */
        if (isAgenda()) continue;
        var ds = cell.dataset.date;
        var list = eventsOf(ds).filter(function (e) { return e.all_day; });
        list.forEach(function (ev) { box.appendChild(buildEvent(ev, true)); });
        if (list.length && ds >= firstVisible && ds <= lastVisible) anyAllDay = true;
    }
    /* la striscia resta solo se ha davvero qualcosa da mostrare: le etichette
       dei giorni (schermi larghi) o un evento di tutto il giorno qui davanti */
    row.classList.toggle('pl-hidden', isAgenda() || (!many && !anyAllDay));
    row.classList.toggle('pl-noad', !anyAllDay);
    syncScrollerTop();
}

function renderDays() {
    var z = zoomCfg(S.zoom);
    var cols = document.querySelectorAll('.pl-col[data-date]');
    for (var i = 0; i < cols.length; i++) renderCol(cols[i], z);
    renderAllDay();
    renderWeekPanel();
    renderRing();
}

function renderCol(col, z) {
    var date = col.dataset.date;
    var canvas = col.querySelector('.pl-canvas');
    canvas.innerHTML = '';

    canvas.style.setProperty('--slot-h', z.h + 'px');
    canvas.style.setProperty('--major-every', z.slot === 30 ? 2 : (z.slot === 15 ? 4 : (z.slot === 5 ? 12 : 1)));
    canvas.classList.toggle('pl-major-lines', z.slot < 60 && z.slot > 0);
    canvas.classList.toggle('pl-agenda-canvas', isAgenda());
    canvas.style.height = isAgenda() ? 'auto' : dayHeight() + 'px';
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

    /* Chi si accavalla si divide lo spazio invece di coprirsi: raggruppiamo
       gli eventi che si toccano nel tempo e diamo a ciascuno la sua colonnina. */
    layoutOverlaps(render);

    render.forEach(function (item) {
        if (item.ev) {
            var n = positioned(buildEvent(item.ev), item.ev.start, item.ev.end);
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
            wrapper.style.top = (startMin * pxMin()) + 'px';
            wrapper.style.height = ((endMin - startMin) * pxMin()) + 'px';
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
                node.style.top = ((toMin(x.start) - startMin) * pxMin()) + 'px';
                wrapper.appendChild(node);
            });
            canvas.appendChild(wrapper);
        }
    });

    // velo sul passato e linea dell'ora
    if (date < S.today) {
        var veil = el('div', 'pl-past');
        veil.style.height = dayHeight() + 'px';
        canvas.appendChild(veil);
    } else if (date === S.today) {
        var now = new Date();
        var nowMin = now.getHours() * 60 + now.getMinutes();
        var v2 = el('div', 'pl-past');
        v2.style.height = (nowMin * pxMin()) + 'px';
        canvas.appendChild(v2);
        var line = el('div', 'pl-now');
        line.style.top = (nowMin * pxMin()) + 'px';
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
    if (isAgenda() || heightPx < 30) return;
    var perLine = 13;
    var roomLines = Math.floor((heightPx - 30) / perLine);

    if (roomLines >= items.length) {
        var box = el('div', 'pl-ev-sub');
        items.forEach(function (x, i) {
            box.appendChild(el('div', null, (i + 1) + '. ' + x.title
                + ' (' + (toMin(x.end) - toMin(x.start)) + ')'));
        });
        node.appendChild(box);
        return;
    }

    var text = items.map(function (x, i) {
        return (i + 1) + '. ' + x.title + ' (' + (toMin(x.end) - toMin(x.start)) + ')';
    }).join('; ');

    var strip = el('div', 'pl-ev-strip');
    var span = el('span', null, text);
    strip.appendChild(span);
    node.appendChild(strip);

    /* la riga parte ferma, scorre fino in fondo, si ferma di nuovo e torna.
       Toccandola si può scorrere a mano: in quel caso l'andirivieni si spegne. */
    requestAnimationFrame(function () {
        var over = span.scrollWidth - strip.clientWidth;
        if (over <= 4) return;
        strip.style.setProperty('--shift', over + 'px');
        strip.style.setProperty('--dur', Math.max(9, Math.round(over / 14) + 7) + 's');
        strip.classList.add('pl-run');
    });
    strip.addEventListener('pointerdown', function () { strip.classList.remove('pl-run'); });

    /* lo scorrimento a mano non deve trascinare via il giorno o le ore */
    strip.addEventListener('touchmove', function (e) { e.stopPropagation(); }, { passive: true });
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

function applyLane(node, item) {
    var lanes = item._lanes || 1;
    if (lanes < 2) return;
    var lane = item._lane || 0;
    var w = 100 / lanes;
    node.style.left = 'calc(' + (lane * w) + '% + 5px)';
    node.style.width = 'calc(' + w + '% - 7px)';
    node.style.right = 'auto';
}

function positioned(node, start, end) {
    var s = toMin(start), e = Math.max(toMin(end), s + 5);
    node.style.top = (s * pxMin()) + 'px';
    node.style.height = Math.max(16, (e - s) * pxMin() - 2) + 'px';
    if ((e - s) * pxMin() > 44) node.classList.add('pl-tall');
    return node;
}

function buildEvent(ev, flow) {
    var node = el('div', 'pl-ev');
    if (flow) node.style.position = 'static';
    node.style.setProperty('--ev', evColor(ev));
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

    var t = el('span', 'pl-ev-t', ev.title);
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
/* Liste della settimana                                               */
/* ------------------------------------------------------------------ */
function renderWeekPanel() {
    var wrap = $('plWeekPanelBody');
    if (!wrap) return;
    var week = monday(S.focusDate);
    wrap.innerHTML = '';
    var host = el('div', 'pl-lists');
    wrap.appendChild(host);
    var d1 = parseIso(week), d2 = parseIso(addDays(week, 6));
    var range = d1.getDate() + ' ' + MESI[d1.getMonth()].toLowerCase() + ' – ' + d2.getDate() + ' ' + MESI[d2.getMonth()].toLowerCase();
    wrap.insertBefore(el('div', 'pl-label', range), host);

    var used = {};
    (S.lists || []).forEach(function (l) {
        used[l.type] = true;
        host.appendChild(listCard(l));
    });
    ['todo', 'buy', 'study'].forEach(function (t) {
        if (used[t]) return;
        var add = el('button', 'pl-addlist');
        add.type = 'button';
        add.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        add.appendChild(el('span', null, 'Aggiungi ' + LIST_LABEL[t]));
        add.addEventListener('click', function () {
            api('list_add', { week_start: week, type: t }).then(function (r) {
                if (!r.ok) return;
                S.lists = r.lists;
                if (S.weekCache[week]) S.weekCache[week].lists = r.lists;
                renderWeekPanel();
            });
        });
        host.appendChild(add);
    });

    var learned = el('div', 'pl-learned');
    learned.appendChild(el('div', 'pl-label', 'Cosa ho imparato'));
    var ta = el('textarea');
    ta.placeholder = 'Questa settimana mi ha insegnato…';
    ta.value = S.week.learned || '';
    ta.maxLength = 4000;
    ta.addEventListener('input', function () {
        S.week.learned = ta.value;
        debounceSave('learned', 900, function () {
            api('week_save', { week_start: week, learned: ta.value });
        });
    });
    learned.appendChild(ta);
    host.appendChild(learned);
    updateWeekPanelBtn();
}

/* il pulsantino a sinistra di "Priorità" si accende se la settimana è compilata */
function updateWeekPanelBtn() {
    var btn = $('plWeekPanelBtn');
    if (!btn) return;
    var filled = !!(S.week.learned && S.week.learned.trim());
    (S.lists || []).forEach(function (l) {
        (l.items || []).forEach(function (i) { if (i.content && i.content.trim()) filled = true; });
    });
    btn.classList.toggle('pl-filled', filled);
}

function openWeekPanel() {
    renderWeekPanel();
    $('plWeekPanelOv').classList.add('pl-open');
    document.documentElement.classList.add('pl-locked');
}
function closeWeekPanel() {
    $('plWeekPanelOv').classList.remove('pl-open');
    if (!document.querySelector('.pl-ov.pl-open')) document.documentElement.classList.remove('pl-locked');
}

function listCard(l) {
    var card = el('div', 'pl-list-card');
    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', LIST_LABEL[l.type] || l.type));
    var del = el('button', 'pl-icon-btn');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox('Elimino la lista?', 'Le voci di ' + (LIST_LABEL[l.type] || l.type) + ' andranno perse.', [
            { label: 'Annulla' },
            { label: 'Elimina', kind: 'danger', onClick: function () {
                api('list_remove', { list_id: l.id }).then(function (r) {
                    if (!r.ok) return;
                    S.lists = S.lists.filter(function (x) { return x.id !== l.id; });
                    renderWeekPanel();
                });
            } }
        ]);
    });
    head.appendChild(del);
    card.appendChild(head);

    var box = el('div', 'pl-list-items');
    (l.items || []).forEach(function (it) { box.appendChild(listItem(l, it)); });
    box.appendChild(listItem(l, { id: 0, content: '', done: false }));
    card.appendChild(box);
    return card;
}

function listItem(l, it) {
    var row = el('div', 'pl-li' + (it.done ? ' pl-done' : ''));
    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    var inp = el('input', 'pl-li-in');
    inp.type = 'text';
    inp.value = it.content || '';
    inp.placeholder = it.id ? '' : 'Aggiungi…';
    inp.maxLength = 200;
    row.appendChild(box); row.appendChild(inp);

    box.addEventListener('click', function () {
        if (!it.id) return;
        it.done = !it.done;
        row.classList.toggle('pl-done', it.done);
        haptic(8);
        api('list_item_save', { item_id: it.id, content: inp.value, done: it.done });
    });
    inp.addEventListener('change', function () { saveItem(); });
    inp.addEventListener('blur', function () { saveItem(); });
    function saveItem() {
        var text = inp.value.trim();
        if (!it.id && !text) return;
        api('list_item_save', { item_id: it.id || 0, list_id: l.id, content: text, done: it.done })
            .then(function (r) {
                if (!r.ok) return;
                if (r.deleted) { it.id = 0; }
                else if (r.id) { it.id = r.id; }
                it.content = text;
                var arr = l.items || (l.items = []);
                if (it.id && arr.indexOf(it) === -1) arr.push(it);
                if (!text) l.items = arr.filter(function (x) { return x !== it; });
                renderWeekPanel();
            });
    }
    return row;
}

/* ------------------------------------------------------------------ */
/* Anello delle abitudini                                              */
/* ------------------------------------------------------------------ */
function renderRing() {
    var ring = $('plHabitRing');
    if (!ring) return;
    var date = S.focusDate;
    loadHabits(date).then(function (habits) {
        var total = habits.length || 1;
        var doneN = 0;
        habits.forEach(function (h) { if (h.done) doneN++; });
        var planned = 0;
        (S.events[date] || []).forEach(function (ev) { if (ev.habit_id && !ev.done) planned++; });

        var C = 125.6;
        var doneFrac = Math.min(1, doneN / total);
        var planFrac = Math.min(1, (doneN + planned) / total);
        if (date < S.today) planFrac = doneFrac;
        ring.querySelector('.pl-ring-done').style.strokeDashoffset = (C * (1 - doneFrac)).toFixed(1);
        ring.querySelector('.pl-ring-plan').style.strokeDashoffset = (C * (1 - planFrac)).toFixed(1);
    });
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
    [$('plTrack'), $('plHeadTrack')].forEach(function (t) {
        if (!t) return;
        t.style.transition = smooth ? 'transform .38s var(--ease)' : 'none';
        t.style.transform = 'translateX(' + x + 'px)';
    });
}
function scrollToMinute(min, smooth) {
    var sc = $('plScroller');
    sc.scrollTo({ top: Math.max(0, min * pxMin() - 10), behavior: smooth ? 'smooth' : 'auto' });
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
    maybeAskCopy();
    applyAmbient();
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

$('plTodayBtn').addEventListener('click', function () {
    if (S.view === 'month') { S.monthCursor = S.today.slice(0, 7) + '-01'; renderMonth(); return; }
    goToDate(S.today, true);
    setTimeout(function () {
        var now = new Date();
        scrollToMinute(Math.max(0, now.getHours() * 60 + now.getMinutes() - 90), true);
    }, 260);
});

/* Anche la barra in alto cambia giorno con uno scorrimento orizzontale:
   è la zona più comoda da raggiungere col pollice. */
(function topbarSwipe() {
    var bar = $('plTopbar');
    if (!bar) return;
    var x0 = null, y0 = 0, horiz = false;
    bar.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1 || S.view === 'month') return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; horiz = false;
    }, { passive: true });
    bar.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
        if (Math.abs(dx) > 16 && Math.abs(dx) > Math.abs(dy) * 1.6) horiz = true;
    }, { passive: true });
    bar.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        x0 = null;
        if (!horiz || Math.abs(dx) < 34) return;
        pageDays(dx < 0 ? 1 : -1);
        haptic(6);
    }, { passive: true });
})();

/* ------------------------------------------------------------------ */
/* Zoom con due dita                                                   */
/* ------------------------------------------------------------------ */
(function pinch() {
    var sc = $('plScroller');
    var start = 0, startZoom = 0, active = false;
    function dist(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.hypot(dx, dy);
    }
    sc.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 2) return;
        active = true;
        start = dist(e.touches);
        startZoom = ZOOM.findIndex(function (z) { return z.key === S.zoom; });
        S.pinching = true;
        showZoomDial('hold');   // resta finché le dita non si alzano
    }, { passive: true });
    sc.addEventListener('touchmove', function (e) {
        if (!active || e.touches.length !== 2) return;
        var ratio = dist(e.touches) / (start || 1);
        var steps = ratio > 1 ? Math.floor((ratio - 1) / 0.22) : -Math.floor((1 / ratio - 1) / 0.22);
        var next = Math.max(0, Math.min(ZOOM.length - 1, startZoom + steps));
        if (ZOOM[next].key !== S.zoom) setZoom(ZOOM[next].key);
    }, { passive: true });
    sc.addEventListener('touchend', function (e) {
        if (e.touches.length >= 2) return;
        active = false;
        /* finché resta un dito appoggiato consideriamo il gesto in corso:
           la rotellina se ne va solo quando lo schermo è libero */
        if (e.touches.length === 0) { S.pinching = false; hideZoomDial(); }
    }, { passive: true });
    sc.addEventListener('touchcancel', function () {
        active = false; S.pinching = false; hideZoomDial();
    }, { passive: true });
})();

function setZoom(key, quiet) {
    var anchor = firstVisibleMinute();
    S.zoom = key;
    renderGutter();
    renderDays();
    scrollToMinute(anchor, false);
    renderZoomDial();
    refreshZoomDial();   // in vista lista resta visibile anche su telefono
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
        b.addEventListener('click', function () { setZoom(z.key); showZoomDial(zoomHideDial_keep() ? 'stay' : 'temporary'); });
        box.appendChild(b);
    });
}
/* su PC resta visibile (è l'unico modo per cambiare scatto senza pizzicare),
   su telefono compare solo mentre si pizzica e poi sparisce */
/* modo: 'hold' resta finché non si chiama hideZoomDial (le due dita sono
   giù), 'temporary' sparisce da sé, 'stay' resta sempre (PC e vista lista) */
function placeZoomDial() {
    var box = $('plZoom'), ref = $('plFabRoutine');
    if (!box || !ref) return;
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
        zoomHideTimer = setTimeout(function () { box.classList.remove('pl-show'); }, 1400);
    }
}
function hideZoomDial() {
    var box = $('plZoom');
    if (!box) return;
    if (zoomHideDial_keep()) return;   // su PC, o in vista lista, resta
    if (zoomHideTimer) clearTimeout(zoomHideTimer);
    box.classList.remove('pl-show');   // le dita si sono alzate: via subito
}
/* la rotellina resta sempre a portata su PC e nella vista a lista, dove
   non c'è una griglia da pizzicare */
function zoomHideDial_keep() { return S.pointerFine || isAgenda(); }
function refreshZoomDial() {
    if (zoomHideDial_keep() || S.pinching) showZoomDial('stay');
    else { var b = $('plZoom'); if (b) b.classList.remove('pl-show'); }
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

    sc.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        var evNode = e.target.closest('.pl-ev');
        var canvas = canvasAt(e.target);
        if (evNode) {
            // un blocco posizionato sulla griglia si sposta tenendolo premuto;
            // il tocco semplice lo gestisce il click qui sotto
            if (!S.shift.on && !isAgenda() && canvas && evNode.style.position !== 'static') {
                startEventDrag(e, evNode, canvas);
            }
            return;
        }
        if (S.shift.on || !canvas) return;

        var slot = zoomCfg(S.zoom).slot || 30;
        var startMin = snap(minuteAt(canvas, e.clientY), false);
        st = {
            canvas: canvas, date: canvas.dataset.date, id: e.pointerId,
            x0: e.clientX, y0: e.clientY, startMin: startMin, endMin: Math.min(1440, startMin + slot),
            ghost: null, phase: 'pending',   // 'pending' -> 'create' | 'paging' | 'scrolling'
            baseX: 0, focusAtStart: S.focusDate
        };
        st.timer = setTimeout(function () {
            if (!st || st.phase !== 'pending' || isAgenda()) return;
            if (S.pinching) { st = null; return; }   // due dita: si sta zoomando
            st.phase = 'create';
            haptic(12);
            st.ghost = el('div', 'pl-ev pl-ghost');
            st.ghost.style.setProperty('--ev', S.defaultCat ? (catById(S.defaultCat) || {}).color || NEUTRAL : NEUTRAL);
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

    sc.addEventListener('pointermove', function (e) {
        if (!st || e.pointerId !== st.id) return;

        if (st.phase === 'pending') {
            var dx = e.clientX - st.x0, dy = e.clientY - st.y0;
            var adx = Math.abs(dx), ady = Math.abs(dy);
            /* Lo scroll delle ore deve partire con niente: appena il dito
               scende un po', è suo. Il cambio giorno invece deve essere un
               gesto orizzontale dichiarato: più lungo e netto (almeno il
               doppio dello spostamento verticale). Così un dito che scorre
               le ore non finisce mai per cambiare giorno o settimana. */
            if (ady > 8) { st.phase = 'scrolling'; clearTimeout(st.timer); return; }
            if (adx > 10 && adx > ady * 1.4) {
                st.phase = 'paging';
                st.baseX = trackNow();
                st.startedAt = Date.now();
                track.style.transition = 'none';
                clearTimeout(st.timer);
                try { sc.setPointerCapture(st.id); } catch (err) {}
            }
            return;   // sotto le soglie: aspettiamo, il tocco potrebbe essere un press
        }

        if (st.phase === 'paging') {
            e.preventDefault();
            var raw = e.clientX - st.x0;
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
        var zone = 110, max = 16, speed = 0;
        if (y > vh - zone)        speed =  Math.min(max, (y - (vh - zone)) / 4);
        else if (y < top + zone)  speed = -Math.min(max, ((top + zone) - y) / 4);
        if (speed) {
            var before = sc.scrollTop;
            sc.scrollTop += speed;
            if (sc.scrollTop !== before) growGhost();
        }
        st.raf = requestAnimationFrame(edgeLoop);
    }

    function releaseScroll() {
        sc.style.touchAction = '';
        if (st && st.raf) { cancelAnimationFrame(st.raf); st.raf = null; }
    }

    function finish(e) {
        if (!st || (e && e.pointerId !== st.id)) return;
        clearTimeout(st.timer);
        releaseScroll();
        var phase = st.phase;

        if (phase === 'paging') {
            var cw = colWidth();
            var dx = e ? (e.clientX - st.x0) : 0;
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
        var data = { date: st.date, start: toHM(st.startMin), end: toHM(st.endMin) };
        if (st.ghost && st.ghost.parentNode) st.ghost.parentNode.removeChild(st.ghost);
        st = null;
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

    sc.addEventListener('pointerup', finish);
    sc.addEventListener('pointercancel', function () {
        if (!st) return;
        clearTimeout(st.timer);
        releaseScroll();
        if (st.phase === 'paging') { setTrackX(trackOffset(S.focusDate), true); }
        if (st.ghost && st.ghost.parentNode) st.ghost.parentNode.removeChild(st.ghost);
        st = null;
    });
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
            if (ZOOM[next].key !== S.zoom) { setZoom(ZOOM[next].key); showZoomDial(zoomHideDial_keep() ? 'stay' : 'temporary'); }
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
    var timer = setTimeout(function () {
        held = true;
        haptic(14);
        node.classList.add('pl-dragging');
        /* da qui il dito muove il blocco, non la pagina */
        S.movingEvent = true;
        sc.style.touchAction = 'none';
        lastY = y0; scrolled = 0;
        try { sc.setPointerCapture(e.pointerId); } catch (err) {}
        edge();
    }, 300);

    var lastY = 0, raf = null, scrolled = 0;

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
        place();
    }
    function up(ev2) {
        if (ev2.pointerId !== e.pointerId) return;
        clearTimeout(timer);
        node.classList.remove('pl-dragging');
        cleanup();
        if (!held) return;
        suppressClick = Date.now();
        var ns = node.dataset.newStart;
        if (!ns || toMin(ns) === blockStart) { node.style.top = startTop + 'px'; return; }

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
                S.weekCache = {};
                loadWeek(monday(date), true).then(renderDays);
                scheduleSync();
            });
            return;
        }

        api('event_move', { id: id, date: date, start: ns }).then(function (r) {
            if (!r.ok) { node.style.top = startTop + 'px'; return; }
            ev.start = r.start; ev.end = r.end;
            renderDays();
            scheduleSync();
        });
    }
    function cleanup() {
        S.movingEvent = false;
        sc.style.touchAction = '';
        if (raf) { cancelAnimationFrame(raf); raf = null; }
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
            renderDays();
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
            renderDays();
            var remain = (S.events[date] || []).filter(function (e) { return e.routine_group === group; });
            if (remain.length) openRoutineInstance(group, date); else closeOv('plSheetOv');
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
    if (kind === 'toggle') {
        api('event_toggle', { id: ev.id }).then(function (r) {
            if (!r.ok) return;
            ev.done = r.done;
            node.classList.toggle('pl-done', r.done);
            if (ev.habit_id && S.habits[ev.date]) {
                S.habits[ev.date].forEach(function (h) { if (h.id === ev.habit_id) h.done = r.done; });
            }
            haptic(12);
            renderRing();
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
        id: existing.id, title: existing.title, notes: existing.notes || '', date: existing.date,
        start: existing.start, end: existing.end, all_day: existing.all_day,
        category_id: existing.category_id, project_id: existing.project_id,
        project_name: existing.project_name, project_color: existing.project_color,
        importance: existing.importance, color: existing.color, type: existing.type,
        notify: existing.notify, notify_min: existing.notify_min,
        repeat: existing.repeat, repeat_interval: existing.repeat_interval,
        repeat_unit: existing.repeat_unit, repeat_until: existing.repeat_until,
        habit_id: existing.habit_id
    } : {
        id: 0, title: '', notes: '', date: seed.date, start: seed.start, end: seed.end,
        all_day: false, category_id: S.defaultCat, project_id: null, importance: 'normal',
        color: null, type: 'event', notify: 'h1', notify_min: 60,
        repeat: 'none', repeat_interval: 1, repeat_unit: 'week', repeat_until: null
    };
    S.draft = { data: d, dirty: false, isNew: !existing };
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
    title.addEventListener('input', function () { d.title = title.value; S.draft.dirty = true; });
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
    body.appendChild(f1);

    /* data, orari, durata */
    var fd = el('div', 'pl-field');
    fd.appendChild(el('label', 'pl-label', 'Quando'));
    var rDate = rowSB('Data', dateInput(d.date, function (v) { d.date = v; S.draft.dirty = true; }));
    fd.appendChild(rDate);

    var allRow = rowSB('Tutto il giorno', toggle(d.all_day, function (v) {
        d.all_day = v; S.draft.dirty = true; renderEventSheet();
    }));
    fd.appendChild(allRow);

    if (!d.all_day) {
        /* Gli orari si aggiornano fra loro senza ridisegnare il popup:
           ricostruirlo a ogni cambio faceva perdere il fuoco al campo e
           rimetteva il valore di partenza appena si staccava il dito. */
        var sIn, eIn, durIn;
        function refreshTimes(skip) {
            if (sIn && skip !== 'start') sIn.value = d.start;
            if (eIn && skip !== 'end') eIn.value = d.end;
            if (durIn && skip !== 'dur') durIn.value = toHM(Math.min(1439, toMin(d.end) - toMin(d.start)));
            tintSheet();
        }
        sIn = timeInput(d.start, function (v) {
            var dur = toMin(d.end) - toMin(d.start);
            d.start = v;
            d.end = toHM(Math.min(1439, toMin(v) + Math.max(5, dur)));
            S.draft.dirty = true;
            refreshTimes('start');
        });
        eIn = timeInput(d.end, function (v) {
            if (toMin(v) <= toMin(d.start)) v = toHM(toMin(d.start) + 5);
            d.end = v; S.draft.dirty = true;
            refreshTimes('end');
        });
        fd.appendChild(rowSB('Inizio', sIn));
        fd.appendChild(rowSB('Fine', eIn));

        /* la durata è un vero campo orario: toccandolo si aprono ore e minuti,
           invece di dover scrivere "1h 30" a mano */
        var dur = toMin(d.end) - toMin(d.start);
        durIn = el('input', 'pl-pick');
        durIn.type = 'time'; durIn.step = '300';
        durIn.style.width = '104px'; durIn.style.textAlign = 'center';
        durIn.value = toHM(Math.min(1439, dur));
        durIn.addEventListener('change', function () {
            var mins = toMin(durIn.value);
            if (!mins) { durIn.value = toHM(toMin(d.end) - toMin(d.start)); return; }
            d.end = toHM(Math.min(1439, toMin(d.start) + mins));
            S.draft.dirty = true;
            refreshTimes('dur');
        });
        fd.appendChild(rowSB('Durata', durIn));
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
        fr.appendChild(rowSB('Fino al', dateInput(d.repeat_until || '', function (v) { d.repeat_until = v; S.draft.dirty = true; })));
    }
    body.appendChild(fr);

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
    ], d.notify, function (v) { d.notify = v; S.draft.dirty = true; renderEventSheet(); })));
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

    /* evento o task */
    var ft = el('div', 'pl-field');
    ft.appendChild(el('label', 'pl-label', 'Tipo'));
    ft.appendChild(seg([['event', 'Evento'], ['task', 'Task']], d.type, function (v) { d.type = v; S.draft.dirty = true; renderEventSheet(); }));
    body.appendChild(ft);

    /* note */
    var fnote = el('div', 'pl-field');
    fnote.appendChild(el('label', 'pl-label', 'Note'));
    var ta = el('textarea', 'pl-in');
    ta.placeholder = 'Qualcosa da ricordare…';
    ta.value = d.notes || '';
    ta.maxLength = 4000;
    ta.addEventListener('input', function () { d.notes = ta.value; S.draft.dirty = true; });
    fnote.appendChild(ta);
    body.appendChild(fnote);

    if (d.id) {
        var del = el('button', 'pl-btn pl-btn-danger', 'Elimina evento');
        del.type = 'button';
        del.style.marginTop = '4px';
        del.addEventListener('click', function () { confirmDeleteEvent(d); });
        body.appendChild(del);
    }
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
        repeat_until: d.repeat_until || ''
    };
    closeOv('plEventOv');
    S.draft = null;
    return api('event_save', payload).then(function (r) {
        if (!r.ok) { toast(r.message || 'Non è stato possibile salvare.'); return; }
        indexEvents([r.event]);
        if (payload.repeat !== 'none') { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }
        renderDays();
        haptic(10);
        scheduleSync();
    });
}
/* chiede conferma prima di eliminare: un tocco sbagliato sul cestino non
   deve far sparire un evento senza appello */
function confirmDeleteEvent(d) {
    alertBox('Elimino questo evento?', (d.title ? '"' + d.title + '" ' : '') + 'verrà rimosso dal planner.', [
        { label: 'Annulla' },
        { label: 'Elimina', kind: 'danger', onClick: function () { deleteEvent(d); } }
    ]);
}

function deleteEvent(d) {
    closeOv('plEventOv');
    S.draft = null;
    api('event_delete', { id: d.id, scope: 'one' }).then(function (r) {
        if (!r.ok) { toast('Non è stato possibile eliminare.'); return; }
        S.events[d.date] = (S.events[d.date] || []).filter(function (x) { return x.id !== d.id; });
        renderDays();
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
    if (S.defaultCat) {
        S.defaultCat = null;
        btn.classList.remove('pl-picked', 'pl-on');
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 4h7.7L20 10.3 13.3 17 6 9.7z"/><circle cx="10" cy="8.5" r="1.2"/></svg>';
        return;
    }
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
    var menu = $('plMenuHabit');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuHabit' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    loadHabits(S.focusDate).then(function (habits) {
        if (!habits.length) {
            var none = el('div', 'pl-chip');
            none.appendChild(el('span', null, 'Nessuna abitudine per oggi'));
            menu.appendChild(none);
            return;
        }
        habits.forEach(function (h) {
            var chip = el('button', 'pl-chip');
            chip.type = 'button';
            chip.style.setProperty('--ev', h.color);
            chip.appendChild(el('span', 'pl-dot'));
            if (h.icon) chip.appendChild(el('span', 'pl-ev-icon', h.icon));
            chip.appendChild(el('span', null, h.name));
            chip.addEventListener('click', function () {
                closeMenus();
                var slot = zoomCfg(S.zoom).slot || 30;
                /* durata: quella stimata scritta in habits; se manca, lo scatto */
                var dur = h.minutes && h.minutes > 0 ? h.minutes : slot;
                confirmHabitTime(h, toHM(firstFreeMinute(dur)), dur);
            });
            menu.appendChild(chip);
        });
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* progetti preferiti: il tastino compare solo se ce n'è almeno uno */
$('plFabFav').addEventListener('click', function () {
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
            var slot = zoomCfg(S.zoom).slot || 30;
            confirmProjectTime(p, toHM(firstFreeMinute(slot)), slot);
        });
        menu.appendChild(chip);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* Conferma di orario e durata prima di mettere un progetto in agenda.
   Il titolo è il nome del progetto e il colore è il suo. */
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

    var r2 = el('div', 'pl-row-sb');
    r2.appendChild(el('span', 'pl-row-lab', 'Durata'));
    var durIn = el('input', 'pl-pick');
    durIn.type = 'time'; durIn.step = '300'; durIn.value = toHM(defaultDur);
    r2.appendChild(durIn);
    wrap.appendChild(r2);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', 'Annulla'); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', 'Inserisci'); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = toMin(startIn.value || defaultStart);
        var dur = Math.max(5, toMin(durIn.value || toHM(defaultDur)));
        ov.classList.remove('pl-open');
        api('event_save', {
            title: p.name, date: S.focusDate, start: toHM(start),
            end: toHM(Math.min(1439, start + dur)),
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

/* modalità anticipi / ritardi */
$('plFabShift').addEventListener('click', function () { setShift(!S.shift.on); });
$('plShiftCancel').addEventListener('click', function () { setShift(false); });
$('plShiftSwitch').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    S.shift.dir = b.dataset.dir;
    Array.prototype.forEach.call(this.querySelectorAll('button'), function (x) { x.classList.toggle('pl-on', x === b); });
});
$('plShiftSave').addEventListener('click', function () {
    var ids = Object.keys(S.shift.picked).map(Number);
    if (!ids.length) { toast('Scegli almeno un evento.'); return; }
    var parts = String($('plShiftTime').value || '00:10').split(':');
    var minutes = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    if (!minutes) { toast('Indica di quanto spostarli.'); return; }
    api('events_shift', { ids: ids, minutes: minutes, direction: S.shift.dir }).then(function (r) {
        if (!r.ok) { toast(r.message || 'Spostamento non riuscito.'); return; }
        setShift(false);
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
    openSheet('Mostra categorie', function (body) {
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
        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', 'Categorie'));
        var rows = el('div', 'pl-rows');
        alpha(S.categories, 'name').forEach(function (c) { rows.appendChild(categoryRow(c)); });
        fc.appendChild(rows);
        var addCat = el('button', 'pl-addlist');
        addCat.type = 'button';
        addCat.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addCat.appendChild(el('span', null, 'Nuova categoria'));
        addCat.style.marginTop = '8px';
        addCat.addEventListener('click', function () { editCategory(null); });
        fc.appendChild(addCat);
        body.appendChild(fc);

        /* routine */
        var fr = el('div', 'pl-field');
        fr.id = 'plSetRoutines';
        fr.appendChild(el('label', 'pl-label', 'Routine'));
        var rr = el('div', 'pl-rows');
        alpha(S.routines, 'name').forEach(function (r) { rr.appendChild(routineRow(r)); });
        fr.appendChild(rr);
        var addR = el('button', 'pl-addlist');
        addR.type = 'button';
        addR.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addR.appendChild(el('span', null, 'Nuova routine'));
        addR.style.marginTop = '8px';
        addR.addEventListener('click', function () { editRoutine(null); });
        fr.appendChild(addR);
        body.appendChild(fr);

        /* frasi + zoom + ambiente */
        var fo = el('div', 'pl-field');
        fo.appendChild(el('label', 'pl-label', 'Sulla pagina'));
        fo.appendChild(rowSB('Frasi motivazionali', toggle(S.settings.show_motivational !== false, function (v) {
            S.settings.show_motivational = v; api('settings_save', { show_motivational: v }); renderWeekBits();
        })));
        fo.appendChild(rowSB('Affermazioni positive', toggle(S.settings.show_affirmations !== false, function (v) {
            S.settings.show_affirmations = v; api('settings_save', { show_affirmations: v }); renderWeekBits();
        })));
        fo.appendChild(rowSB('Alberi e insetti', toggle(S.settings.ambient_enabled !== false, function (v) {
            S.settings.ambient_enabled = v; api('settings_save', { ambient_enabled: v });
            applyAmbient(true); applySky();
        })));
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

        if (focus === 'routines') setTimeout(function () { $('plSetRoutines').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300);
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
         '#F2C9C0', '#CFE3D2', '#D4C9E8', '#F6E0B8', '#BFD8E3', '#E9D5E2', '#CEDDB8', '#E7CFC0',
         '#B9D6D2', '#F1D8E4', '#C6C9E6', '#EFE3C6', '#D9E4EE', '#E5D2C2'].forEach(function (col) {
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
        body.appendChild(save);

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
function gcall(action, write) {
    var opt = { method: write ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf } };
    if (write) opt.headers['Content-Type'] = 'application/json';
    if (write) opt.body = '{}';
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
$('plViewBtn').addEventListener('click', function () { setView(S.view === 'day' ? 'month' : 'day'); });

function setView(v) {
    S.view = v;
    document.body.classList.toggle('pl-view-month', v === 'month');
    var vb = $('plViewBtn');
    vb.classList.toggle('pl-on', v === 'month');
    vb.setAttribute('aria-label', v === 'month' ? 'Torna alla settimana' : 'Passa alla vista mese');
    if (v === 'month') {
        S.monthCursor = S.focusDate.slice(0, 7) + '-01';
        renderMonth();
    } else {
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
    cell.appendChild(el('div', 'pl-mcell-n pl-serif', String(parseIso(date).getDate())));
    var list = eventsOf(date);
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
    cell.addEventListener('click', function () {
        cell.classList.add('pl-anim-open');
        setTimeout(function () {
            S.focusDate = date;
            setView('day');
            renderWeekBits();
            renderPrio();
            loadWeek(monday(date));
        }, 140);
    });
    return cell;
}

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

function maybeAskCopy() {
    var ask = $('plCopyAsk');
    if (!ask) return;
    var empty = !(S.events[S.focusDate] || []).length;
    var future = S.focusDate > S.today;
    ask.classList.toggle('pl-hidden', !(empty && future));
    syncScrollerTop();
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
function boot() {
    applyPalette(S.settings.palette || 'sage');
    applyContrast();
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
    S.weekCache[monday(S.today)] = { week: B.week || {}, lists: B.lists || [] };

    buildDayBar();
    buildTrack();
    renderWeekBits();
    renderPrio();
    syncScrollerTop();
    maybeAskCopy();

    ensureStartScroll();
    loadAmbient();

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
    setInterval(function () { if (S.view === 'day') renderDays(); }, 60000);

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
    S.weekCache = {};
    loadWeek(monday(S.focusDate), true).then(renderRing);
});

boot();
window.PL = { state: S, api: api, reload: function () { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }, toast: toast };

})();