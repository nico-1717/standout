/* =====================================================================
   NISAMA — DIARIO (logica della pagina)
   Si sfoglia con lo swipe, si salva da sé, si annulla con la freccia.
   Gli stessi dati del popup "diario del giorno": qui però è la casa.
   ===================================================================== */
(function () {
'use strict';

var B = window.DY_BOOT || {};
var MESI = [T('diario.mese_1'), T('diario.mese_2'), T('diario.mese_3'), T('diario.mese_4'), T('diario.mese_5'), T('diario.mese_6'), T('diario.mese_7'), T('diario.mese_8'), T('diario.mese_9'), T('diario.mese_10'), T('diario.mese_11'), T('diario.mese_12')];
var GIORNI = [T('diario.gg_1'), T('diario.gg_2'), T('diario.gg_3'), T('diario.gg_4'), T('diario.gg_5'), T('diario.gg_6'), T('diario.gg_7')];
/* le iniziali della griglia del mese: in inglese sono M T W T F S S */
var GIORNI_S = [T('diario.ggs_1'), T('diario.ggs_2'), T('diario.ggs_3'), T('diario.ggs_4'), T('diario.ggs_5'), T('diario.ggs_6'), T('diario.ggs_7')];
var ESEMPIO = T('diario.esempio_scrittura');
var FONTS = [
    { key: 'caveat',    label: 'Caveat',   sample: ESEMPIO },
    { key: 'kalam',     label: 'Kalam',    sample: ESEMPIO },
    { key: 'patrick',   label: 'Patrick',  sample: ESEMPIO },
    { key: 'cormorant', label: 'Garamond', sample: ESEMPIO },
    { key: 'jost',      label: 'Jost',     sample: ESEMPIO }
];

var MOOD_WORDS   = { 1: T('diario.umore_1'), 2: T('diario.umore_2'), 3: T('diario.umore_3'), 4: T('diario.umore_4'), 5: T('diario.umore_5') };
var ENERGY_WORDS = { 1: T('diario.energia_1'), 2: T('diario.energia_2'), 3: T('diario.energia_3'), 4: T('diario.energia_4'), 5: T('diario.energia_5') };

var S = {
    csrf:  B.csrf || '',
    today: B.today,
    date:  B.date || B.today,
    day:   { mood: null, energy: null, note: '', good: [], learned: [], habits: { done: 0, total: 0 } },
    settings: { font: 'caveat', size: 18, paper: 'lined' },
    monthCursor: (B.date || B.today).slice(0, 7),
    month: null,
    view: 'day',
    undo: [],
    timers: {}
};

/* ------------------------------------------------------------------ */
/* Utility                                                            */
/* ------------------------------------------------------------------ */
function $(id) { return document.getElementById(id); }
function el(tag, cls, txt) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt !== undefined && txt !== null) n.textContent = txt;
    return n;
}
function pad(n) { return (n < 10 ? '0' : '') + n; }
function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
function addDays(s, n) { var d = parseIso(s); d.setDate(d.getDate() + n); return iso(d); }
function monday(s) { var d = parseIso(s); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return iso(d); }
function dow(s) { return (parseIso(s).getDay() + 6) % 7; }

function later(key, ms, fn) {
    if (S.timers[key]) clearTimeout(S.timers[key]);
    S.timers[key] = setTimeout(fn, ms);
}
function haptic(ms) { if (navigator.vibrate) { try { navigator.vibrate(ms || 8); } catch (e) {} } }

function api(action, data, query) {
    var url = 'diary_api.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
    var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || S.csrf } };
    if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    return fetch(url, opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
}

var toastTimer = null;
function toast(msg) {
    var t = $('dyToast');
    t.textContent = msg;
    t.classList.add('dy-show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('dy-show'); }, 2400);
}
function flashSaved() {
    var n = $('dySaved');
    n.classList.add('dy-show');
    later('saved', 1500, function () { n.classList.remove('dy-show'); });
}

/* le faccine arrivano dal popup condiviso; se manca, un cerchio semplice */
function face(kind, level, size) {
    if (window.nisamaFace) return window.nisamaFace(kind, level, size);
    /* senza il componente condiviso restiamo essenziali: un cerchio per
       l'umore, una stellina per l'energia */
    if (kind === 'energy') {
        return '<svg viewBox="0 0 40 40" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">'
             + '<path d="M20 4.6l4.2 8.8 9.6 1.3-7 6.7 1.7 9.5-8.5-4.7-8.5 4.7 1.7-9.5-7-6.7 9.6-1.3z"'
             + (level ? '' : ' stroke-dasharray="3.4 3.6"') + '/></svg>';
    }
    return '<svg viewBox="0 0 40 40" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.5">'
         + '<circle cx="20" cy="20" r="16.5"' + (level ? '' : ' stroke-dasharray="3.4 3.6"') + '/></svg>';
}

/* ------------------------------------------------------------------ */
/* Annulla: una pila di azioni, ognuna sa come tornare indietro         */
/* ------------------------------------------------------------------ */
function pushUndo(label, revert) {
    S.undo.push({ label: label, revert: revert });
    if (S.undo.length > 40) S.undo.shift();
    $('dyUndo').disabled = false;
}
$('dyUndo').addEventListener('click', function () {
    var step = S.undo.pop();
    if (!step) { this.disabled = true; return; }
    haptic(10);
    step.revert();
    toast('Annullato: ' + step.label);
    if (!S.undo.length) this.disabled = true;
});

/* ------------------------------------------------------------------ */
/* Impostazioni: scrittura e corpo                                     */
/* ------------------------------------------------------------------ */
function applySettings() {
    var body = document.body;
    body.className = body.className.replace(/dy-font-\S+/g, '').replace(/dy-paper-\S+/g, '').trim();
    body.classList.add('dy-body', 'dy-font-' + S.settings.font, 'dy-paper-' + S.settings.paper);
    document.documentElement.style.setProperty('--dy-size', S.settings.size + 'px');
    var line = Math.round(S.settings.size * 1.62);
    document.documentElement.style.setProperty('--dy-line', line + 'px');
    $('dySizeV').textContent = S.settings.size;
}

/* ------------------------------------------------------------------ */
/* Pagina del giorno                                                   */
/* ------------------------------------------------------------------ */
function renderDay() {
    var d = parseIso(S.date);
    $('dyNum').textContent = d.getDate();
    $('dyWd').textContent = GIORNI[dow(S.date)];
    $('dyMonthLabel').textContent = MESI[d.getMonth()] + ' ' + d.getFullYear();
    /* non porta più via subito: apre il riepilogo, e da lì si sceglie */

    $('dyMoodBtn').innerHTML = face('mood', S.day.mood, 40);
    $('dyMoodBtn').classList.toggle('dy-set', !!S.day.mood);
    $('dyEnergyBtn').innerHTML = face('energy', S.day.energy, 40);
    $('dyEnergyBtn').classList.toggle('dy-set', !!S.day.energy);

    disegnaAnelli(S.day.rings || []);

    renderList('dyGood', 'good');
    renderList('dyLearned', 'learned');

    var ta = $('dyText');
    if (document.activeElement !== ta) ta.value = S.day.note || '';
}

function renderList(hostId, type) {
    var host = $(hostId);
    var items = (S.day[type] || []).slice();
    host.innerHTML = '';
    items.push({ id: 0, content: '' });
    items.forEach(function (it) { host.appendChild(listRow(it, type, hostId)); });
}

function listRow(it, type, hostId) {
    var row = el('div', 'dy-li' + (it.content ? ' dy-li-filled' : ''));
    row.appendChild(el('span', 'dy-li-dot'));
    var input = el('input');
    input.type = 'text';
    input.value = it.content || '';
    input.maxLength = 300;
    input.placeholder = it.id ? '' : (type === 'good' ? T('diario.una_cosa_bella') : T('diario.una_cosa_imparata'));
    row.appendChild(input);

    var before = it.content || '';
    function save(redraw) {
        var text = input.value.trim();
        if (!it.id && !text) return;
        if (text === before && it.id) return;
        api('list_save', { date: S.date, list_type: type, item_id: it.id || 0, content: text }).then(function (r) {
            if (!r.ok) return;
            var wasId = it.id, wasText = before;
            if (r.deleted) {
                S.day[type] = S.day[type].filter(function (a) { return a.id !== it.id; });
            } else if (r.id && !it.id) {
                it.id = r.id; it.content = text;
                S.day[type].push(it);
            } else {
                it.content = text;
            }
            before = text;
            flashSaved();
            var restoreId = r.deleted ? 0 : (r.id || wasId || 0);
            var restoreDate = S.date;
            pushUndo(type === 'good' ? T('diario.cosa_bella') : T('diario.cosa_imparata'), function () {
                api('list_save', { date: restoreDate, list_type: type, item_id: restoreId, content: wasText })
                    .then(function () { loadDay(restoreDate, false); });
            });
            if (redraw) renderList(hostId, type);
        });
    }
    input.addEventListener('input', function () {
        row.classList.toggle('dy-li-filled', !!input.value.trim());
        later('li' + type + (it.id || 'new'), 800, function () { save(!it.id); });
    });
    input.addEventListener('blur', function () { save(true); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    });
    return row;
}

/* testo libero: si salva mentre scrivi */
$('dyText').addEventListener('input', function () {
    var v = this.value;
    later('text', 900, function () { saveText(v); });
});
$('dyText').addEventListener('blur', function () { saveText(this.value); });

function saveText(v) {
    if (v === S.day.note) return;
    var before = S.day.note || '';
    var date = S.date;
    S.day.note = v;
    api('save_text', { date: date, note: v }).then(function (r) {
        if (!r.ok) return;
        flashSaved();
        pushUndo(T('diario.la_scrittura_u'), function () {
            S.day.note = before;
            if (S.date === date) { $('dyText').value = before; }
            api('save_text', { date: date, note: before });
        });
    });
}

/* faccine: il fogliettino con le 5 scelte */
function openFacePop(kind, anchor) {
    var pop = $('dyFacePop');
    $('dyPopLab').textContent = T(kind === 'mood' ? 'diario.com_e_andata' : 'diario.energia');
    var row = $('dyPopRow');
    row.innerHTML = '';
    for (var lv = 1; lv <= 5; lv++) {
        (function (level) {
            var b = el('button', 'dy-pop-face' + (S.day[kind] === level ? ' dy-on' : ''));
            b.type = 'button';
            b.innerHTML = face(kind, level, 46);
            b.addEventListener('click', function () {
                var before = S.day[kind];
                var next = before === level ? null : level;
                S.day[kind] = next;
                renderDay();
                closePops();
                haptic(10);
                api('save_mood', { date: S.date, which: kind, value: next === null ? '' : next }).then(function () {
                    flashSaved();
                    pushUndo(T(kind === 'mood' ? 'diario.com_e_andata_u' : 'diario.l_energia_u'), function () {
                        S.day[kind] = before;
                        renderDay();
                        api('save_mood', { date: S.date, which: kind, value: before === null ? '' : before });
                    });
                });
            });
            row.appendChild(b);
        })(lv);
    }
    placePop(pop, anchor);
}

function placePop(pop, anchor) {
    closePops(pop);
    pop.hidden = false;
    var r = anchor.getBoundingClientRect();
    var w = pop.offsetWidth, h = pop.offsetHeight;
    var left = Math.min(window.innerWidth - w - 12, Math.max(12, r.left + r.width / 2 - w / 2));
    var top = r.bottom + 10;
    if (top + h > window.innerHeight - 12) top = Math.max(12, r.top - h - 10);
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
}
function closePops(except) {
    ['dyFacePop', 'dyFontPop'].forEach(function (id) {
        if ($(id) !== except) $(id).hidden = true;
    });
}
document.addEventListener('click', function (e) {
    if (e.target.closest('.dy-pop') || e.target.closest('.dy-face-btn') || e.target.closest('#dyFontBtn')) return;
    closePops();
});

$('dyMoodBtn').addEventListener('click', function () { openFacePop('mood', this); });
$('dyEnergyBtn').addEventListener('click', function () { openFacePop('energy', this); });

/* scrittura */
$('dyFontBtn').addEventListener('click', function () {
    var pop = $('dyFontPop');
    if (!pop.hidden) { closePops(); return; }
    var list = $('dyFontList');
    list.innerHTML = '';
    FONTS.forEach(function (f) {
        var b = el('button', 'dy-font-opt' + (S.settings.font === f.key ? ' dy-on' : ''));
        b.type = 'button';
        var sample = el('span', null, f.sample);
        sample.style.fontFamily = f.key === 'caveat' ? "'Caveat', cursive"
            : f.key === 'kalam' ? "'Kalam', cursive"
            : f.key === 'patrick' ? "'Patrick Hand', cursive"
            : f.key === 'cormorant' ? "'Cormorant Garamond', serif" : "'Jost', sans-serif";
        b.appendChild(sample);
        b.appendChild(el('span', null, f.label));
        b.addEventListener('click', function () {
            S.settings.font = f.key;
            applySettings();
            api('settings_save', { font: f.key });
            Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('dy-on'); });
            b.classList.add('dy-on');
        });
        list.appendChild(b);
    });
    placePop(pop, this);
});
Array.prototype.forEach.call(document.querySelectorAll('.dy-size'), function (b) {
    b.addEventListener('click', function () {
        var step = parseInt(b.dataset.step, 10);
        S.settings.size = Math.max(14, Math.min(26, S.settings.size + step));
        applySettings();
        api('settings_save', { size: S.settings.size });
    });
});

/* ------------------------------------------------------------------ */
/* Caricamento di un giorno e sfogliata                                */
/* ------------------------------------------------------------------ */
/* Ogni caricamento porta un numero progressivo: se si sfoglia in fretta e le
   risposte tornano fuori ordine, quelle vecchie vengono scartate invece di
   sovrascrivere la pagina giusta. */
var dayReq = 0;

/* Gli anelli concentrici delle abitudini.

   Uno per famiglia di frequenza, e solo per le famiglie che hanno almeno
   un'abitudine: il più interno è il giornaliero, poi verso l'esterno
   settimanale, mensile, personalizzate. Il conto lo fa il server (dg_rings),
   perché settimana e mese hanno bisogno di tutti i registri, non solo di
   quelli del giorno aperto.

   Le corsie sono attaccate e divise da un filo sottilissimo. Quante siano
   dipende da chi legge, quindi il disegno si costruisce qui invece di stare
   fisso nel markup della pagina. */
var DY_LARGHEZZA = 3.6;   // spessore di ogni corsia
var DY_STACCO    = 1;     // il filo che divide una corsia dall'altra
var DY_ESTERNO   = 24;    // raggio della corsia più esterna

function disegnaAnelli(dati) {
    var ring = $('dyRing');
    if (!ring) return;
    var svg = ring.querySelector('svg');
    if (!svg) return;
    dati = dati || [];

    var pezzi = '';
    for (var i = 0; i < dati.length; i++) {
        /* i = 0 è il più interno: si parte da dentro e si viene fuori */
        var r = DY_ESTERNO - (dati.length - 1 - i) * (DY_LARGHEZZA + DY_STACCO);
        if (r < 3.5) break;                       // troppe famiglie: non ci sta più
        var C = 2 * Math.PI * r;
        var frac = dati[i].total > 0 ? Math.min(1, dati[i].done / dati[i].total) : 0;
        pezzi += '<circle class="dy-ring-track" cx="27" cy="27" r="' + r.toFixed(2)
              + '" stroke-width="' + DY_LARGHEZZA + '"/>'
              + '<circle class="dy-ring-fill dy-ring-' + dati[i].key + '" cx="27" cy="27" r="' + r.toFixed(2)
              + '" stroke-width="' + DY_LARGHEZZA + '"'
              + ' stroke-dasharray="' + C.toFixed(1) + '"'
              + ' stroke-dashoffset="' + (C * (1 - frac)).toFixed(1) + '"/>';
    }
    svg.innerHTML = pezzi;
    ring.dataset.n = dati.length;

    /* Al centro resta il numero, ma di quello che conta di più: il giorno.
       Se non ci sono giornaliere si mostra la prima famiglia che c'è. */
    var primo = dati.length ? dati[0] : null;
    $('dyRingN').textContent = primo ? (primo.done + '/' + primo.total) : '–';

    var descr = dati.map(function (d) { return d.label + ' ' + d.done + ' su ' + d.total; }).join(', ');
    ring.setAttribute('aria-label', descr || 'Nessuna abitudine');
}

function loadDay(date, animate) {
    var myReq = ++dayReq;
    S.date = date;
    if (history.replaceState) history.replaceState(null, '', 'diario.php?data=' + date);

    /* L'anello si azzera subito. Prima renderDay() girava con i dati del
       giorno appena lasciato: per tutto il tempo del fetch si vedevano le
       abitudini di ieri, ed era questo il "ritardo" delle barre. */
    S.day = { mood: null, energy: null, note: '', good: [], learned: [],
              habits: { done: 0, total: 0 }, habits_list: [], weekly: [], rings: [], loading: true };
    renderDay();

    return api('day', null, { date: date }).then(function (r) {
        if (myReq !== dayReq) return;          // è arrivata tardi: la pagina è già un'altra
        if (!r.ok) return;
        S.day = r.day;
        if (r.settings) { S.settings = r.settings; applySettings(); }
        renderDay();
    });
}

function turnPage(delta) {
    var page = S.view === 'month' ? $('dyMonthPage') : $('dyPage');
    var cls = delta > 0 ? 'dy-flip-next' : 'dy-flip-prev';
    page.classList.remove('dy-flip-next', 'dy-flip-prev');
    void page.offsetWidth;
    page.classList.add(cls);
    setTimeout(function () { page.classList.remove(cls); }, 540);
    haptic(8);

    if (S.view === 'month') {
        var c = parseIso(S.monthCursor + '-01');
        var next = new Date(c.getFullYear(), c.getMonth() + delta, 1);
        S.monthCursor = next.getFullYear() + '-' + pad(next.getMonth() + 1);
        loadMonth();
    } else {
        loadDay(addDays(S.date, delta), true);
    }
}

(function swipe() {
    var stage = $('dyStage');
    var x0 = null, y0 = 0, t0 = 0, fromField = false, fromText = false;
    stage.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        /* Lo scorrimento per cambiare pagina funzionava solo in alto, perché
           partendo dall'area di scrittura il gesto veniva scartato del tutto.
           Ora dal testo si accetta comunque, purché sia chiaramente
           orizzontale: scrivere e sfogliare non si pestano i piedi. */
        var t = e.target;
        fromField = !!(t.closest && t.closest('.dy-taped-body'));
        fromText = !!(t.closest && t.closest('textarea, input'));
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; t0 = Date.now();
    }, { passive: true });
    stage.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var t = e.changedTouches[0];
        var dx = t.clientX - x0, dy = t.clientY - y0;
        x0 = null;
        if (fromField) return;
        if (Date.now() - t0 > 800) return;
        /* dal testo serve un gesto più deciso, per non cambiare pagina mentre
           si sposta il cursore; altrove bastano trenta pixel */
        var soglia = fromText ? 70 : 30;
        var rapporto = fromText ? 2 : 1.1;
        if (Math.abs(dx) < soglia || Math.abs(dy) > Math.abs(dx) * rapporto) return;
        turnPage(dx < 0 ? 1 : -1);
    }, { passive: true });
})();

$('dyTodayBtn').addEventListener('click', function () {
    if (S.view === 'month') { S.monthCursor = S.today.slice(0, 7); loadMonth(); return; }
    if (S.date === S.today) { toast(T('diario.gia_su_oggi')); return; }
    loadDay(S.today, true);
});

/* ------------------------------------------------------------------ */
/* Pagina del mese                                                     */
/* ------------------------------------------------------------------ */
$('dyMonthBtn').addEventListener('click', function () { setView('month'); });
$('dyMonthBack').addEventListener('click', function () { setView('day'); });

function setView(v) {
    S.view = v;
    $('dyPage').hidden = v !== 'day';
    $('dyMonthPage').hidden = v !== 'month';
    if (v === 'month') {
        S.monthCursor = S.date.slice(0, 7);
        loadMonth();
    } else {
        renderDay();
    }
}

function loadMonth() {
    var c = parseIso(S.monthCursor + '-01');
    $('dyMTitle').textContent = MESI[c.getMonth()];
    $('dyMYear').textContent = c.getFullYear();
    renderCal(null);
    return api('month', null, { month: S.monthCursor }).then(function (r) {
        if (!r.ok) return;
        S.month = r;
        renderCal(r);
        renderStats(r.stats);
        renderTaped(r.learned || []);
    });
}

function renderCal(data) {
    var host = $('dyCal');
    host.innerHTML = '';
    var wd = el('div', 'dy-cal-wd');
    GIORNI_S.forEach(function (g) { wd.appendChild(el('span', null, g)); });
    host.appendChild(wd);

    var grid = el('div', 'dy-cal-grid');
    var first = S.monthCursor + '-01';
    var start = monday(first);
    var fd = parseIso(first);
    var month = fd.getMonth();
    var daysInMonth = new Date(fd.getFullYear(), month + 1, 0).getDate();
    var weeks = Math.ceil((dow(first) + daysInMonth) / 7);

    for (var i = 0; i < weeks * 7; i++) {
        (function (ds) {
            var dd = parseIso(ds);
            var inMonth = dd.getMonth() === month;
            var info = data && data.days ? data.days[ds] : null;
            var written = !!(info && (info.has_text || info.has_lists));
            var cell = el('button', 'dy-cal-d' + (inMonth ? '' : ' dy-out') + (written ? ' dy-written' : '') + (ds === S.today ? ' dy-today' : ''));
            cell.type = 'button';
            cell.appendChild(el('span', null, String(dd.getDate())));
            if (info && info.mood) {
                var f = document.createElement('div');
                f.innerHTML = face('mood', info.mood, 17);
                cell.appendChild(f.firstChild);
            }
            cell.addEventListener('click', function () {
                S.date = ds;
                setView('day');
                loadDay(ds, false);
            });
            grid.appendChild(cell);
        })(addDays(start, i));
    }
    host.appendChild(grid);
}

function renderStats(st) {
    var host = $('dyStats');
    host.innerHTML = '';
    if (!st) return;
    function stat(iconHtml, value, label) {
        var s = el('div', 'dy-stat');
        var ico = el('div', 'dy-stat-ico');
        ico.innerHTML = iconHtml;
        s.appendChild(ico);
        var t = el('div', 'dy-stat-t');
        t.appendChild(el('div', 'dy-stat-v', value));
        t.appendChild(el('div', 'dy-stat-k', label));
        s.appendChild(t);
        return s;
    }
    var moodAvgLevel = st.mood_avg ? Math.round(st.mood_avg) : null;
    var enAvgLevel = st.energy_avg ? Math.round(st.energy_avg) : null;
    var num = function (v) { return v === null || v === undefined ? '–' : String(v).replace('.', ','); };

    host.appendChild(stat(face('mood', st.mood_mode, 34), st.mood_mode ? MOOD_WORDS[st.mood_mode] : '–', T('diario.piu_frequente')));
    host.appendChild(stat(face('mood', moodAvgLevel, 34), num(st.mood_avg), T('diario.media_come_andata')));
    host.appendChild(stat(face('energy', enAvgLevel, 34), num(st.energy_avg), T('diario.energia_media')));
    host.appendChild(stat(
        '<svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M11 6h14a3 3 0 013 3v25H14a3 3 0 01-3-3z"/><path d="M11 6v28"/><path d="M17 14h9M17 20h9M17 26h6"/></svg>',
        st.filled + ' / ' + st.total, T('diario.pagine_scritte')));
}

function renderTaped(learned) {
    $('dyTapedN').textContent = learned.length;
    var body = $('dyTapedBody');
    body.innerHTML = '';
    if (!learned.length) {
        body.appendChild(el('div', 'dy-taped-empty', T('diario.ancora_niente_scritto')));
        return;
    }
    learned.forEach(function (l) {
        var li = el('div', 'dy-taped-li');
        li.appendChild(el('p', null, l.content));
        var d = parseIso(l.date);
        li.appendChild(el('small', null, d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()));
        body.appendChild(li);
    });
}
$('dyTapedHead').addEventListener('click', function () {
    $('dyTaped').classList.toggle('dy-open');
});

/* se il popup del diario ha scritto qualcosa altrove, ricarichiamo */
document.addEventListener('diaryupdated', function (e) {
    if (!e.detail || e.detail.date === S.date) loadDay(S.date, false);
    if (S.view === 'month') loadMonth();
});

/* ------------------------------------------------------------------ */
/* Riepilogo delle abitudini: cosa c'era e cosa è stato fatto           */
/* ------------------------------------------------------------------ */
$('dyRing').addEventListener('click', function () {
    var giorno = S.day.habits_list || [];
    var sett = S.day.weekly || [];
    var d = parseIso(S.date);

    var ov = el('div', 'dy-modal');
    var card = el('div', 'dy-card');
    card.style.maxWidth = '340px';
    card.appendChild(el('div', 'dy-card-t', 'Abitudini'));
    card.appendChild(el('div', 'dy-card-s',
        GIORNI[dow(S.date)].toLowerCase() + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()));

    var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';

    if (giorno.length) {
        var s1 = el('div', 'dy-hb-sec');
        var fatte = giorno.filter(function (x) { return x.done; }).length;
        s1.appendChild(el('div', 'dy-hb-lab', T('diario.di_oggi_su', {fatte: fatte, totale: giorno.length})));
        giorno.forEach(function (x) {
            var r = el('div', 'dy-hb-row' + (x.done ? ' is-done' : ''));
            var dot = el('span', 'dy-hb-dot');
            dot.style.background = x.color || '#B7AEA3';
            r.appendChild(dot);
            r.appendChild(el('span', 'dy-hb-name', (x.icon ? x.icon + ' ' : '') + x.name));
            if (x.done) { var ck = el('span', 'dy-hb-check'); ck.innerHTML = CHECK; r.appendChild(ck); }
            s1.appendChild(r);
        });
        card.appendChild(s1);
    }

    if (sett.length) {
        var s2 = el('div', 'dy-hb-sec');
        s2.appendChild(el('div', 'dy-hb-lab', T('diario.questa_settimana')));
        sett.forEach(function (x) {
            var r = el('div', 'dy-hb-row');
            var dot = el('span', 'dy-hb-dot');
            dot.style.background = x.color || '#B7AEA3';
            r.appendChild(dot);
            r.appendChild(el('span', 'dy-hb-name', (x.icon ? x.icon + ' ' : '') + x.name));
            r.appendChild(el('span', 'dy-hb-val', x.done + '/' + x.target));
            s2.appendChild(r);
        });
        card.appendChild(s2);
    }

    if (!giorno.length && !sett.length) {
        card.appendChild(el('div', 'dy-hb-sec', T('diario.nessuna_abitudine')));
    }

    var acts = el('div', 'dy-card-acts');
    var close = el('button', null, T('comune.chiudi'));
    close.type = 'button';
    close.addEventListener('click', function () { ov.remove(); });
    var go = el('button', 'dy-primary', T('diario.vai_alle_abitudini'));
    go.type = 'button';
    go.addEventListener('click', function () { window.location.href = 'habits.php?data=' + S.date; });
    acts.appendChild(close); acts.appendChild(go);
    card.appendChild(acts);

    ov.appendChild(card);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
});

/* frecce da computer, dove lo scorrimento con il dito non c'è */
$('dyPrev').addEventListener('click', function () { turnPage(-1); });
$('dyNext').addEventListener('click', function () { turnPage(1); });

/* ------------------------------------------------------------------ */
/* Dettatura: la voce diventa testo, per riempire la pagina in fretta   */
/* ------------------------------------------------------------------ */
(function dictation() {
    var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    var btn = $('dyMic');
    if (!Rec || !btn) return;           // dove non c'è, il tasto non compare
    btn.hidden = false;

    var rec = null, running = false, base = '';

    function stop() {
        running = false;
        btn.classList.remove('dy-rec');
        if (rec) { try { rec.stop(); } catch (e) {} }
    }

    btn.addEventListener('click', function () {
        if (running) { stop(); return; }
        rec = new Rec();
        rec.lang = 'it-IT';
        rec.continuous = true;
        rec.interimResults = true;

        var ta = $('dyText');
        base = ta.value;

        rec.onresult = function (e) {
            var fin = '', interim = '';
            for (var i = e.resultIndex; i < e.results.length; i++) {
                var chunk = e.results[i][0].transcript;
                if (e.results[i].isFinal) fin += chunk; else interim += chunk;
            }
            if (fin) {
                /* punteggiatura indovinata dal componente condiviso */
                var chiusa = window.nisamaPunctuate ? window.nisamaPunctuate(fin.trim()) : fin.trim();
                base = (base ? base.replace(/\s+$/, '') + ' ' : '') + chiusa;
            }
            ta.value = base + (interim ? (base ? ' ' : '') + interim : '');
            ta.scrollTop = ta.scrollHeight;
        };
        rec.onerror = function () { stop(); toast(T('diario.dettatura_non_disponibile')); };
        rec.onend = function () {
            if (running) { try { rec.start(); } catch (e) { stop(); } return; }
            saveText($('dyText').value);
        };
        try {
            rec.start();
            running = true;
            btn.classList.add('dy-rec');
            toast('Parla pure, ti ascolto');
        } catch (e) { stop(); }
    });
})();

/* ------------------------------------------------------------------ */
/* Avvio                                                               */
/* ------------------------------------------------------------------ */
function boot() {
    applySettings();
    $('dyUndo').disabled = true;
    renderDay();
    loadDay(S.date, false);
    document.addEventListener('gesturestart', function (e) { e.preventDefault(); });
    document.addEventListener('dblclick', function (e) { e.preventDefault(); }, { passive: false });
    window.addEventListener('resize', function () { closePops(); });
}
boot();
window.DY = { state: S, load: loadDay, toast: toast };

})();