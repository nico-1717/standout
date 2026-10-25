/* =====================================================================
   STANDOUT — IL PIANO (calendario dei progetti)
   Un solo file per le tre viste:
     · Calendario  — si sceglie un sotto-progetto e si evidenziano i giorni
                     passando il dito (o il mouse) come con un evidenziatore;
     · Piano       — le ore del progetto divise fra i figli, con cursori che
                     si compensano e lucchetti;
     · Oggi / Panoramica — nella vista «Calendario» di tutti i progetti:
                     cosa fare oggi e se tutto ci sta.

   Parla solo con piano_api.php. Tutto il testo che viene dal database
   entra nella pagina con textContent, mai con innerHTML.

   Entrate pubbliche:
     StandoutPiano.project(host, projectId, tab)   dentro un progetto
     StandoutPiano.setTab(tab)                     'cal' | 'plan'
     StandoutPiano.master(host, tab)               tutti i progetti
   ===================================================================== */
(function () {
    'use strict';

    var API = 'piano_api.php';
    var WD_FIRST = 1;                                // la settimana comincia di lunedì

    /* ---------------- piccoli arnesi ---------------- */
    function t(key, vars) { return typeof window.T === 'function' ? window.T('piano.' + key, vars) : key; }

    function h(tag, cls, kids) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (kids !== undefined && kids !== null) {
            if (!Array.isArray(kids)) kids = [kids];
            kids.forEach(function (k) {
                if (k === null || k === undefined || k === false) return;
                e.appendChild(typeof k === 'string' || typeof k === 'number' ? document.createTextNode(String(k)) : k);
            });
        }
        return e;
    }
    function btn(cls, label, fn, aria) {
        var b = h('button', cls, label);
        b.type = 'button';
        if (aria) b.setAttribute('aria-label', aria);
        if (fn) b.addEventListener('click', fn);
        return b;
    }
    function svg(path, size, sw) {
        var ns = 'http://www.w3.org/2000/svg';
        var s = document.createElementNS(ns, 'svg');
        s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 18); s.setAttribute('height', size || 18);
        s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', sw || 2);
        s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path'); p.setAttribute('d', path); s.appendChild(p);
        return s;
    }
    var IC = {
        brush: 'M4 20l1-4L16.5 4.5a2.1 2.1 0 013 3L8 19l-4 1z',
        erase: 'M8 20h11M5.5 14.5l7-8a2 2 0 013 0l2.5 2.5a2 2 0 010 3L12 18H8z',
        eye: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zM12 14.8a2.8 2.8 0 100-5.6 2.8 2.8 0 000 5.6z',
        spark: 'M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4zM18 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z',
        left: 'M14.5 5L8 12l6.5 7', right: 'M9.5 5L16 12l-6.5 7',
        lock: 'M7 11h10a1.5 1.5 0 011.5 1.5v6A1.5 1.5 0 0117 20H7a1.5 1.5 0 01-1.5-1.5v-6A1.5 1.5 0 017 11zM8.5 11V8a3.5 3.5 0 017 0v3',
        unlock: 'M7 11h10a1.5 1.5 0 011.5 1.5v6A1.5 1.5 0 0117 20H7a1.5 1.5 0 01-1.5-1.5v-6A1.5 1.5 0 017 11zM8.5 11V8a3.5 3.5 0 016.7-1.4',
        chev: 'M9 6l6 6-6 6', gear: 'M12 15.2a3.2 3.2 0 100-6.4 3.2 3.2 0 000 6.4zM19.4 13a7.5 7.5 0 000-2l2-1.5-2-3.5-2.3.9a7.4 7.4 0 00-1.7-1L15 3.5h-4l-.4 2.4a7.4 7.4 0 00-1.7 1l-2.3-.9-2 3.5 2 1.5a7.5 7.5 0 000 2l-2 1.5 2 3.5 2.3-.9c.5.4 1.1.7 1.7 1l.4 2.4h4l.4-2.4c.6-.3 1.2-.6 1.7-1l2.3.9 2-3.5z',
        play: 'M8 5.5v13l11-6.5z', check: 'M4 12.5l5.2 5.2L20 6.6', plus: 'M12 5v14M5 12h14', x: 'M6 6l12 12M18 6L6 18'
    };

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
    function parse(s) { return new Date(s + 'T12:00:00Z'); }
    function add(s, n) { var d = parse(s); d.setUTCDate(d.getUTCDate() + n); return ymd(d); }
    function dow(s) { var n = parse(s).getUTCDay(); return n === 0 ? 7 : n; }          // 1 lun … 7 dom
    function lang() { return document.documentElement.lang || undefined; }
    function fmtDate(s, opt) { try { return parse(s).toLocaleDateString(lang(), Object.assign({ timeZone: 'UTC' }, opt || { day: 'numeric', month: 'short' })); } catch (e) { return s; } }
    function monthTitle(s) { return fmtDate(s, { month: 'long', year: 'numeric' }); }
    function wdNames() {
        var out = [], base = '2024-01-01';                                                  // era un lunedì
        for (var i = 0; i < 7; i++) out.push(fmtDate(add(base, i), { weekday: 'short' }));
        return out;
    }

    /* 90 → «1 h 30», 45 → «45 min», 6000 → «100 h» */
    function fmtMin(m) {
        m = Math.round(m || 0);
        if (m < 60) return m + ' min';
        var hh = Math.floor(m / 60), r = m % 60;
        return r ? hh + ' h ' + r : hh + ' h';
    }
    function fmtShort(m) {
        m = Math.round(m || 0);
        if (m < 60) return m + "'";
        var hh = Math.floor(m / 60), r = m % 60;
        return r ? hh + 'h' + pad(r) : hh + 'h';
    }
    function hoursNum(m) { var x = Math.round(m / 6) / 10; return x; }                 // 90 → 1.5

    function store(k, v) { try { if (v === undefined) return localStorage.getItem('pp_' + k); localStorage.setItem('pp_' + k, v); } catch (e) { } return null; }

    function toast(msg) {
        var el = document.getElementById('ppToast');
        if (!el) { el = h('div', 'pp-toast'); el.id = 'ppToast'; el.setAttribute('role', 'status'); document.body.appendChild(el); }
        el.textContent = msg; el.classList.add('show');
        clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 2400);
    }

    function api(action, data) {
        var post = data !== undefined;
        var url = API + '?ajax=' + encodeURIComponent(action);
        var opt = { credentials: 'same-origin' };
        if (post) { opt.method = 'POST'; opt.headers = { 'Content-Type': 'application/json' }; opt.body = JSON.stringify(data); }
        return fetch(url, opt).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || d.ok === false) throw new Error((d && (d.message || d.error)) || 'error');
            return d;
        });
    }
    function get(action, qs) { return fetch(API + '?ajax=' + action + (qs ? '&' + qs : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (d) { if (!d || d.ok === false) throw new Error((d && (d.message || d.error)) || 'error'); return d; }); }
    function fail(e) { toast(e && e.message && e.message !== 'error' ? e.message : t('errore')); }

    /* ---------------- fogli (bottom sheet) ---------------- */
    function sheet(title, body, foot, onClose) {
        var ov = h('div', 'pp-ov');
        var box = h('div', 'pp-sheet');
        box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', title);
        var head = h('div', 'pp-sheet-head', [h('div', 'pp-sheet-title', title), btn('pp-x', svg(IC.x, 14, 2.8), close, t('chiudi'))]);
        var bd = h('div', 'pp-sheet-body', body);
        box.appendChild(head); box.appendChild(bd);
        if (foot) box.appendChild(h('div', 'pp-sheet-foot', foot));
        ov.appendChild(box);
        function onKey(e) { if (e.key === 'Escape') close(); }
        function close() {
            document.removeEventListener('keydown', onKey);
            ov.classList.remove('show');
            setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 200);
            if (onClose) onClose();
        }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
        requestAnimationFrame(function () { ov.classList.add('show'); });
        return { close: close, body: bd, box: box };
    }

    /* ---------------- il matematico dei cursori ----------------
       Un gruppo di fratelli che deve fare sempre il totale. Muovendone uno,
       gli altri (quelli senza lucchetto) danno o prendono in proporzione a
       quello che hanno già. I resti si arrotondano a 5 minuti, e la
       differenza va sul più grande, così la somma è sempre esatta. */
    function distribute(items, idxs, rest) {
        var STEP = 5;
        if (!idxs.length) return;
        rest = Math.max(0, rest);
        var base = 0; idxs.forEach(function (i) { base += items[i].v; });
        var parts = idxs.map(function (i) {
            var f = base > 0 ? rest * items[i].v / base : rest / idxs.length;
            var r = Math.floor(f / STEP) * STEP;
            return { i: i, r: r, frac: f - r };
        });
        var used = 0; parts.forEach(function (p) { used += p.r; });
        var left = rest - used;
        parts.sort(function (a, b) { return b.frac - a.frac; });
        var k = 0;
        while (left >= STEP && parts.length) { parts[k % parts.length].r += STEP; left -= STEP; k++; }
        if (left > 0) {                                                       // meno di 5 minuti: sul più grande
            var big = parts[0]; parts.forEach(function (p) { if (p.r > big.r) big = p; });
            big.r += left;
        }
        parts.forEach(function (p) { items[p.i].v = p.r; });
    }
    function rebalance(items, i, want, total) {
        var locked = 0, others = [];
        items.forEach(function (it, j) { if (j === i) return; if (it.locked) locked += it.v; else others.push(j); });
        var max = Math.max(0, total - locked);
        want = Math.max(0, Math.min(want, max));
        if (!others.length) want = max;                                       // nessuno con cui compensare: prende tutto il libero
        items[i].v = want;
        distribute(items, others, total - locked - want);
    }
    function rescale(items, total) {
        var locked = 0, free = [];
        items.forEach(function (it, j) { if (it.locked) locked += it.v; else free.push(j); });
        if (!free.length) return;
        distribute(items, free, Math.max(0, total - locked));
    }

    /* =================================================================
       IL CONTROLLORE
       ================================================================= */
    var S = null;                                                             // lo stato della vista corrente

    function newState(host, mode, pid) {
        var today = new Date(); var td = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
        return {
            host: host, mode: mode, pid: pid || 0, tab: 'cal', data: null, today: td,
            month: td.slice(0, 7) + '-01', scope: pid || 0, sel: null, tool: store('tool') || 'paint',
            nodes: {}, kids: {}, paint: {}, plan: {}, done: {}, cap: {}, openRows: {}, cascadeBusy: false
        };
    }

    function gridRange(month) {
        var first = month; var off = dow(first) - WD_FIRST; if (off < 0) off += 7;
        var from = add(first, -off);
        return { from: from, to: add(from, 41) };
    }

    function load() {
        var r = gridRange(S.month);
        return get('tree', 'project=' + S.pid + '&from=' + r.from + '&to=' + r.to).then(function (d) {
            S.data = d; S.today = d.today || S.today; S.settings = d.settings;
            S.nodes = {}; S.kids = {}; S.paint = {}; S.plan = {}; S.done = {}; S.cap = d.cap || {};
            d.nodes.forEach(function (n) { S.nodes[n.id] = n; });
            d.nodes.forEach(function (n) {
                var p = (n.parent !== null && S.nodes[n.parent]) ? n.parent : 0;
                (S.kids[p] = S.kids[p] || []).push(n.id);
            });
            Object.keys(d.paint || {}).forEach(function (k) { S.paint[k] = {}; d.paint[k].forEach(function (day) { S.paint[k][day] = true; }); });
            (d.plan || []).forEach(function (x) { var o = (S.plan[x.d] = S.plan[x.d] || {}); var e = (o[x.p] = o[x.p] || { study: 0, review: 0, manual: false }); e[x.k] = x.m; if (x.s === 'manual') e.manual = true; });
            (d.done || []).forEach(function (x) { (S.done[x.d] = S.done[x.d] || {})[x.p] = x.m; });
            if (S.mode === 'project' && !S.nodes[S.scope]) S.scope = S.pid;
            if (S.sel === null || !S.nodes[S.sel]) S.sel = S.mode === 'project' ? S.scope : (chipsOf(0)[0] || null);
            return d;
        });
    }

    function subtree(id) {
        var out = [], st = [id];
        while (st.length) { var x = st.pop(); out.push(x); (S.kids[x] || []).forEach(function (k) { st.push(k); }); }
        return out;
    }
    function chipsOf(scope) {
        var ids = (S.kids[scope] || []).filter(function (id) { return S.nodes[id] && S.nodes[id].status !== 'completed'; });
        /* nella vista di tutti i progetti, solo quelli che sono nel piano */
        if (S.mode === 'master' && scope === 0) ids = ids.filter(function (id) { return subtree(id).some(function (x) { return goalMin(x) > 0; }); });
        return ids;
    }
    function goalMin(id) { var n = S.nodes[id]; return n && n.goal && n.goal.minutes ? n.goal.minutes : 0; }
    function rootId(id) { var g = 0; while (S.nodes[id] && S.nodes[id].parent !== null && S.nodes[S.nodes[id].parent] && g++ < 30) id = S.nodes[id].parent; return id; }
    /* testo scuro o chiaro sopra il colore del sotto-progetto, a seconda di quanto è chiaro */
    function ink(color) {
        var m = /^#?([0-9a-f]{6})$/i.exec(color || ''); if (!m) return '#1C1C1E';
        var n = parseInt(m[1], 16), r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
        return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#1C1C1E' : '#FFFFFF';
    }
    function ptint(color, pct) { return 'color-mix(in srgb, ' + color + ' ' + pct + '%, transparent)'; }
    function dayPlanned(day, ids) { var o = S.plan[day]; if (!o) return 0; var m = 0; (ids || Object.keys(o)).forEach(function (id) { if (o[id]) m += (o[id].study || 0) + (o[id].review || 0); }); return m; }
    function nodeRemaining(id) { var n = S.nodes[id]; if (!n || !n.goal) return 0; return Math.max(0, n.goal.minutes - n.done_min); }

    /* ---------------- la cornice ---------------- */
    function paintAll() {
        var host = S.host;
        host.textContent = '';
        host.classList.add('pp');
        if (S.mode === 'master') host.appendChild(masterTabs());
        var body = h('div', 'pp-body');
        host.appendChild(body);
        S.body = body;
        if (S.tab === 'today') renderToday(body);
        else if (S.tab === 'cal') renderCalendar(body);
        else renderPlan(body);
    }
    function masterTabs() {
        var seg = h('nav', 'pp-seg');
        [['today', t('tab_oggi')], ['cal', t('tab_calendario')], ['plan', t('tab_piano')]].forEach(function (x) {
            var b = btn('pp-seg-it' + (S.tab === x[0] ? ' on' : ''), x[1], function () { S.tab = x[0]; store('mtab', x[0]); refresh(); });
            seg.appendChild(b);
        });
        return seg;
    }
    function refresh() {
        var y = window.scrollY;
        var p;
        if (S.mode === 'master') p = S.tab === 'today' ? loadToday() : (S.tab === 'plan' ? loadMaster() : load());
        else p = load();
        return p.then(function () { paintAll(); window.scrollTo(0, y); }).catch(fail);
    }

    /* =================================================================
       CALENDARIO — l'evidenziatore
       ================================================================= */
    function renderCalendar(root) {
        var scopeNode = S.nodes[S.scope];
        /* intestazione: dove sono e i sotto-progetti */
        var bar = h('div', 'pp-chips');
        if (S.mode === 'project' && S.scope !== S.pid) {
            bar.appendChild(btn('pp-chip pp-chip-up', [svg(IC.left, 14, 2.4), (S.nodes[S.nodes[S.scope].parent] || {}).name || ''], function () {
                S.scope = S.nodes[S.scope].parent; S.sel = S.scope; paintAll();
            }));
        }
        if (S.mode === 'project') bar.appendChild(chip(S.scope, true));
        chipsOf(S.scope).forEach(function (id) { bar.appendChild(chip(id, false)); });
        root.appendChild(bar);
        if (S.mode === 'project' && !chipsOf(S.scope).length && S.scope === S.pid) {
            root.appendChild(h('div', 'pp-hint', t('senza_figli')));
        }

        /* mese */
        var card = h('div', 'pp-card pp-cal');
        var head = h('div', 'pp-cal-head', [
            h('div', 'pp-cal-title', monthTitle(S.month)),
            h('div', 'pp-cal-nav', [
                btn('pp-ic', svg(IC.left), function () { S.month = add(S.month, -1).slice(0, 7) + '-01'; refresh(); }, t('mese_prima')),
                btn('pp-today', t('oggi'), function () { S.month = S.today.slice(0, 7) + '-01'; refresh(); }),
                btn('pp-ic', svg(IC.right), function () { S.month = add(S.month.slice(0, 7) + '-28', 7).slice(0, 7) + '-01'; refresh(); }, t('mese_dopo'))
            ])
        ]);
        card.appendChild(head);
        var wd = h('div', 'pp-wd'); wdNames().forEach(function (n) { wd.appendChild(h('span', '', n)); }); card.appendChild(wd);
        var grid = h('div', 'pp-grid pp-tool-' + S.tool);
        S.grid = grid;
        var r = gridRange(S.month);
        var chips = chipsOf(S.scope);
        var selC = S.sel && S.nodes[S.sel] ? S.nodes[S.sel].color : '#007AFF';
        var estimate = estimatePerDay(S.sel);
        for (var i = 0; i < 42; i++) {
            var d = add(r.from, i);
            var cell = h('button', 'pp-day');
            cell.type = 'button';
            cell.dataset.d = d;
            if (d.slice(0, 7) !== S.month.slice(0, 7)) cell.classList.add('out');
            if (d === S.today) cell.classList.add('today');
            if (d < S.today) cell.classList.add('past');
            var cp = S.cap[d];
            if (cp && cp.cap === 0 && d >= S.today) cell.classList.add('rest');
            var isSel = S.sel && S.paint[S.sel] && S.paint[S.sel][d];
            if (isSel) { cell.classList.add('sel'); cell.style.setProperty('--c', selC); cell.style.setProperty('--sel-ink', ink(selC)); }
            cell.appendChild(h('span', 'pp-dn', parse(d).getUTCDate()));
            var dots = h('span', 'pp-dots');
            chips.forEach(function (cid) {
                if (cid === S.sel) return;
                var painted = S.paint[cid] && S.paint[cid][d];
                var planned = S.plan[d] && subtree(cid).some(function (x) { return S.plan[d][x] && (S.plan[d][x].study || 0) > 0; });
                if (painted || planned) { var dot = h('i', 'pp-dot' + (painted ? '' : ' soft')); dot.style.background = S.nodes[cid].color; dots.appendChild(dot); }
            });
            cell.appendChild(dots);
            var m = dayPlanned(d, subtree(S.sel || S.scope));
            var lab = h('span', 'pp-dm');
            if (m > 0) lab.textContent = fmtShort(m);
            else if (isSel && estimate && d >= S.today) { lab.textContent = '~' + fmtShort(estimate); lab.classList.add('est'); }
            cell.appendChild(lab);
            var dn = 0; if (S.done[d]) subtree(S.sel || S.scope).forEach(function (x) { dn += S.done[d][x] || 0; });
            if (dn > 0) cell.appendChild(h('span', 'pp-tick', svg(IC.check, 9, 3.4)));
            (function (day) {
                cell.addEventListener('click', function (e) {
                    if (e.detail !== 0 || S.tool === 'look') { if (e.detail === 0) openDay(day); return; }   // il mouse e il dito passano dall'evidenziatore
                    if (!S.sel || day < S.today) return;
                    var on = S.paint[S.sel] && S.paint[S.sel][day];
                    api('paint', { project: S.sel, mode: on ? 'remove' : 'add', days: [day] }).then(function () { return refresh(); }).catch(fail);
                });
            })(d);
            grid.appendChild(cell);
        }
        card.appendChild(grid);
        bindPaint(grid);
        root.appendChild(card);

        root.appendChild(summaryCard());

        /* strumenti */
        var tools = h('div', 'pp-tools');
        [['paint', IC.brush, t('evidenzia')], ['erase', IC.erase, t('cancella')], ['look', IC.eye, t('guarda')]].forEach(function (x) {
            tools.appendChild(btn('pp-tool' + (S.tool === x[0] ? ' on' : ''), [svg(x[1], 20, 2), h('span', '', x[2])], function () { S.tool = x[0]; store('tool', x[0]); paintAll(); }));
        });
        var go = btn('pp-tool pp-go', [svg(IC.spark, 20, 1.8), h('span', '', t('pianifica_per_me'))], function () { autoplanFlow(); });
        tools.appendChild(go);
        root.appendChild(tools);
    }

    function chip(id, isScope) {
        var n = S.nodes[id]; if (!n) return h('span');
        var on = S.sel === id;
        var kids = (S.kids[id] || []).length;
        var b = h('button', 'pp-chip' + (on ? ' on' : ''));
        b.type = 'button';
        b.style.setProperty('--c', n.color);
        b.appendChild(h('i', 'pp-chip-dot'));
        b.appendChild(h('span', 'pp-chip-name', isScope ? t('tutto', { n: n.name }) : n.name));
        var g = goalMin(id);
        if (g > 0) b.appendChild(h('span', 'pp-chip-h', fmtMin(g)));
        b.addEventListener('click', function () { S.sel = id; paintAll(); });
        if (!isScope && kids && S.mode === 'project') {
            var wrap = h('span', 'pp-chip-wrap'); wrap.style.setProperty('--c', n.color);
            var dr = btn('pp-chip-drill', svg(IC.chev, 14, 2.6), function (e) { e.stopPropagation(); S.scope = id; S.sel = id; paintAll(); }, t('entra', { n: n.name }));
            wrap.appendChild(b); wrap.appendChild(dr);
            return wrap;
        }
        return b;
    }

    function estimatePerDay(id) {
        if (!id || !S.nodes[id]) return 0;
        var rem = nodeRemaining(id); if (rem <= 0) return 0;
        var days = 0; Object.keys(S.paint[id] || {}).forEach(function (d) { if (d >= S.today) days++; });
        return days > 0 ? Math.round(rem / days / 5) * 5 : 0;
    }

    function summaryCard() {
        var id = S.sel, n = S.nodes[id];
        var card = h('div', 'pp-card pp-sum');
        if (!n) { card.appendChild(h('div', 'pp-sum-t', t('scegli_figlio'))); return card; }
        var days = 0; Object.keys(S.paint[id] || {}).forEach(function (d) { if (d >= S.today) days++; });
        var main = h('div', 'pp-sum-main');
        main.appendChild(h('div', 'pp-sum-t', n.name + (days ? ' · ' + t('n_giorni', { n: days }) : '')));
        var g = goalMin(id), rem = nodeRemaining(id);
        var sub;
        if (g <= 0) sub = t('senza_ore');
        else if (days > 0) sub = t('ore_in_giorni', { h: fmtMin(rem), n: days, d: fmtMin(Math.round(rem / days / 5) * 5) });
        else sub = t('ore_restano', { h: fmtMin(rem) }) + ' · ' + t('nessun_giorno');
        main.appendChild(h('div', 'pp-sum-s', sub));
        card.appendChild(main);
        var acts = h('div', 'pp-sum-acts');
        acts.appendChild(btn('pp-link', t('modifica_ore'), function () { goPlan(id); }));
        if (days > 0) acts.appendChild(btn('pp-link pp-link-q', t('svuota_giorni'), function () {
            api('paint', { project: id, mode: 'clear' }).then(function () { refresh(); }).catch(fail);
        }));
        card.appendChild(acts);
        return card;
    }

    function goPlan(id) {
        if (S.mode === 'master') {
            if (id && S.nodes[id]) { window.location.href = 'progetti.php?id=' + rootId(id) + '&pp=plan'; return; }
            S.tab = 'plan'; refresh(); return;
        }
        S.scope = id; S.tab = 'plan';
        if (window.StandoutPiano && window.StandoutPiano._setSeg) window.StandoutPiano._setSeg('plan');
        paintAll();
    }

    /* l'evidenziatore: tocco o trascinamento. Il primo giorno toccato decide
       se si sta accendendo o spegnendo, e vale per tutto il trascinamento. */
    function bindPaint(grid) {
        var active = false, mode = 'add', touched = {}, order = [];
        function cellAt(x, y) {
            var el = document.elementFromPoint(x, y);
            return el && el.closest ? el.closest('.pp-day') : null;
        }
        function apply(cell) {
            var d = cell.dataset.d;
            if (touched[d] || d < S.today) return;
            touched[d] = true; order.push(d);
            if (!S.paint[S.sel]) S.paint[S.sel] = {};
            if (mode === 'add') { S.paint[S.sel][d] = true; cell.classList.add('sel'); cell.style.setProperty('--c', S.nodes[S.sel].color); cell.style.setProperty('--sel-ink', ink(S.nodes[S.sel].color)); }
            else { delete S.paint[S.sel][d]; cell.classList.remove('sel'); }
        }
        grid.addEventListener('pointerdown', function (e) {
            var cell = e.target.closest ? e.target.closest('.pp-day') : null;
            if (!cell) return;
            if (S.tool === 'look') { openDay(cell.dataset.d); return; }
            if (!S.sel || !S.nodes[S.sel]) { toast(t('scegli_figlio')); return; }
            active = true; touched = {}; order = [];
            var painted = S.paint[S.sel] && S.paint[S.sel][cell.dataset.d];
            mode = S.tool === 'erase' ? 'remove' : (painted ? 'remove' : 'add');
            apply(cell);
            try { grid.setPointerCapture(e.pointerId); } catch (x) { }
            e.preventDefault();
        });
        grid.addEventListener('pointermove', function (e) {
            if (!active) return;
            var c = cellAt(e.clientX, e.clientY);
            if (c) apply(c);
        });
        function end() {
            if (!active) return;
            active = false;
            if (!order.length) return;
            var id = S.sel, m = mode, days = order.slice();
            api('paint', { project: id, mode: m, days: days }).then(function () { return load(); }).then(function () { paintAll(); }).catch(function (e) { fail(e); refresh(); });
        }
        grid.addEventListener('pointerup', end);
        grid.addEventListener('pointercancel', end);
        grid.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    }

    /* ---------------- il giorno ---------------- */
    function openDay(d) {
        var box = h('div', 'pp-day-sheet');
        var cp = S.cap[d] || { cap: 0, busy: 0 };
        function draw() {
            box.textContent = '';
            var base = cp.cap + cp.busy;
            box.appendChild(h('div', 'pp-card-lite', [
                h('div', 'pp-day-cap', t('tempo_giorno', { h: fmtMin(cp.cap) })),
                cp.busy > 0 ? h('div', 'pp-sub', t('occupato_planner', { h: fmtMin(cp.busy) })) : null
            ]));
            var ids = S.plan[d] ? Object.keys(S.plan[d]).filter(function (id) { return S.nodes[id]; }) : [];
            var list = h('div', 'pp-day-list');
            ids.forEach(function (id) {
                var e = S.plan[d][id], n = S.nodes[id];
                var row = h('div', 'pp-day-row');
                var dot = h('i', 'pp-chip-dot'); dot.style.background = n.color;
                row.appendChild(dot);
                row.appendChild(h('div', 'pp-day-name', [n.name, e.review ? h('small', '', ' · ' + t('ripasso')) : null]));
                if (e.study) {
                    var step = h('div', 'pp-step', [
                        btn('pp-ic sm', '−', function () { setMin(id, Math.max(0, e.study - 15)); }, t('meno')),
                        h('span', 'pp-step-v', fmtMin(e.study)),
                        btn('pp-ic sm', '+', function () { setMin(id, e.study + 15); }, t('piu'))
                    ]);
                    row.appendChild(step);
                } else row.appendChild(h('span', 'pp-step-v', fmtMin(e.review)));
                list.appendChild(row);
            });
            if (!ids.length) list.appendChild(h('div', 'pp-sub', t('giorno_vuoto')));
            box.appendChild(list);
            /* aggiungere un blocco a mano */
            var sel = h('select', 'pp-inp');
            var chips = (S.mode === 'project' ? [S.scope].concat(chipsOf(S.scope)) : chipsOf(0));
            chips.forEach(function (id) { var o = h('option', '', S.nodes[id].name); o.value = id; if (id === S.sel) o.selected = true; sel.appendChild(o); });
            var add = btn('pp-btn', [svg(IC.plus, 16, 2.4), t('aggiungi_blocco')], function () { setMin(+sel.value, 30, true); });
            box.appendChild(h('div', 'pp-day-add', [sel, add]));
            /* il tempo libero di quel giorno */
            var hrs = h('input', 'pp-inp pp-inp-num'); hrs.type = 'number'; hrs.min = 0; hrs.max = 24; hrs.step = 0.5; hrs.value = hoursNum(base);
            var save = btn('pp-btn ghost', t('imposta_giorno'), function () {
                var ov = Object.assign({}, (S.settings && S.settings.overrides) || {}); ov[d] = Math.round((parseFloat(hrs.value) || 0) * 60);
                saveSettings({ overrides: ov }).then(function () { return refresh(); }).then(function () { s.close(); }).catch(fail);
            });
            var off = btn('pp-btn ghost', t('giorno_libero'), function () {
                var ov = Object.assign({}, (S.settings && S.settings.overrides) || {}); ov[d] = 0;
                saveSettings({ overrides: ov }).then(function () { return refresh(); }).then(function () { s.close(); }).catch(fail);
            });
            box.appendChild(h('div', 'pp-lab', t('ore_libere_giorno')));
            box.appendChild(h('div', 'pp-day-add', [hrs, save, off]));
        }
        function setMin(id, m, isNew) {
            api('day_set', { project: id, day: d, minutes: m }).then(function () { return load(); }).then(function () {
                if (isNew && !S.plan[d]) S.plan[d] = {};
                draw(); paintAll();
            }).catch(fail);
        }
        draw();
        var s = sheet(fmtDate(d, { weekday: 'long', day: 'numeric', month: 'long' }), box);
    }

    /* ---------------- impostazioni (il tempo libero) ---------------- */
    function saveSettings(patch) {
        var cur = S.settings || {};
        var body = {
            weekday_min: 'weekday_min' in patch ? patch.weekday_min : cur.weekday_min,
            weekend_min: 'weekend_min' in patch ? patch.weekend_min : cur.weekend_min,
            rest_dow: 'rest_dow' in patch ? patch.rest_dow : cur.rest_dow,
            buffer_days: 'buffer_days' in patch ? patch.buffer_days : cur.buffer_days,
            floor_min: 'floor_min' in patch ? patch.floor_min : cur.floor_min,
            max_subjects: 'max_subjects' in patch ? patch.max_subjects : cur.max_subjects,
            reviews: 'reviews' in patch ? (patch.reviews ? 1 : 0) : (cur.reviews ? 1 : 0),
            overrides: 'overrides' in patch ? patch.overrides : cur.overrides
        };
        return api('settings_save', body).then(function (d) { S.settings = d.settings; return d; });
    }

    function openSettings(after) {
        var st = S.settings;
        var m = st.measured || {};
        var box = h('div', 'pp-set');
        box.appendChild(h('div', 'pp-sub', m.enough ? t('misurato_da', { n: m.days }) : t('misurato_poco')));
        function hoursField(label, key, eff, measured) {
            var inp = h('input', 'pp-inp pp-inp-num'); inp.type = 'number'; inp.min = 0; inp.max = 24; inp.step = 0.5;
            inp.value = st[key] === null || st[key] === undefined ? '' : hoursNum(st[key]);
            inp.placeholder = hoursNum(eff);
            var auto = btn('pp-link', t('usa_misurato', { h: fmtMin(measured) }), function () { inp.value = ''; });
            return h('label', 'pp-field', [h('span', 'pp-field-l', label), h('div', 'pp-field-r', [inp, h('span', 'pp-unit', t('ore')), auto])]);
        }
        var wd = hoursField(t('feriali'), 'weekday_min', st.weekday_eff, m.weekday);
        var we = hoursField(t('festivi'), 'weekend_min', st.weekend_eff, m.weekend);
        box.appendChild(wd); box.appendChild(we);
        var rest = h('select', 'pp-inp');
        var names = wdNames();
        var o0 = h('option', '', t('nessun_riposo')); o0.value = 0; rest.appendChild(o0);
        for (var i = 1; i <= 7; i++) { var o = h('option', '', names[i - 1]); o.value = i; rest.appendChild(o); }
        rest.value = st.rest_dow;
        box.appendChild(h('label', 'pp-field', [h('span', 'pp-field-l', t('giorno_riposo')), h('div', 'pp-field-r', [rest])]));
        function num(label, val, min, max, hint) {
            var inp = h('input', 'pp-inp pp-inp-num'); inp.type = 'number'; inp.min = min; inp.max = max; inp.value = val;
            return { el: h('label', 'pp-field', [h('span', 'pp-field-l', [label, hint ? h('small', '', hint) : null]), h('div', 'pp-field-r', [inp])]), inp: inp };
        }
        var buf = num(t('giorni_cuscinetto'), st.buffer_days, 0, 14, t('giorni_cuscinetto_h'));
        var flo = num(t('blocco_minimo'), st.floor_min, 5, 120, t('blocco_minimo_h'));
        var mx = num(t('materie_giorno'), st.max_subjects, 1, 8, t('materie_giorno_h'));
        box.appendChild(buf.el); box.appendChild(flo.el); box.appendChild(mx.el);
        var rv = h('input'); rv.type = 'checkbox'; rv.checked = !!st.reviews;
        box.appendChild(h('label', 'pp-field pp-field-check', [rv, h('span', 'pp-field-l', [t('ripassi'), h('small', '', t('ripassi_h'))])]));
        function parseH(inp) { return inp.value === '' ? null : Math.round(parseFloat(inp.value) * 60); }
        var s = sheet(t('il_tuo_tempo'), box, [btn('pp-btn primary', t('salva'), function () {
            saveSettings({
                weekday_min: parseH(wd.querySelector('input')), weekend_min: parseH(we.querySelector('input')),
                rest_dow: +rest.value, buffer_days: +buf.inp.value || 0, floor_min: +flo.inp.value || 15, max_subjects: +mx.inp.value || 3, reviews: rv.checked
            }).then(function () { s.close(); return refresh(); }).catch(fail);
        })]);
    }

    /* ---------------- «pianifica per me» ---------------- */
    function autoplanFlow() {
        var pid = S.mode === 'project' ? S.pid : 0;
        api('autoplan', { project: pid, apply: 0 }).then(function (r) {
            var box = h('div', 'pp-ap');
            if (!r.units) {
                box.appendChild(h('div', 'pp-sub', t('ap_niente')));
                sheet(t('pianifica_per_me'), box);
                return;
            }
            var v = h('div', 'pp-verdict ' + (r.fits ? 'ok' : 'warn'));
            v.appendChild(h('div', 'pp-verdict-t', r.fits ? t('ap_ci_sta') : t('ap_non_ci_sta', { h: fmtMin(r.missing_min) })));
            v.appendChild(h('div', 'pp-sub', t('ap_dettaglio', { h: fmtMin(r.total_min), a: r.first ? fmtDate(r.first) : '-', b: r.last ? fmtDate(r.last) : '-' })));
            box.appendChild(v);
            if (r.warnings && r.warnings.length) {
                var w = h('div', 'pp-warn-list');
                r.warnings.slice(0, 8).forEach(function (x) {
                    w.appendChild(h('div', 'pp-warn-row', [h('span', '', x.name), h('b', '', '−' + fmtMin(x.minutes))]));
                    if (x.painted) w.appendChild(h('div', 'pp-sub', t('ap_pochi_giorni', { n: x.name, d: x.painted })));
                });
                box.appendChild(w);
                box.appendChild(h('div', 'pp-sub', t('ap_come_risolvere')));
            }
            var weeks = Object.keys(r.weeks || {}).sort();
            if (weeks.length) {
                var max = 1; weeks.forEach(function (k) { max = Math.max(max, r.weeks[k]); });
                var ch = h('div', 'pp-mini');
                weeks.slice(0, 30).forEach(function (k) {
                    var col = h('div', 'pp-mini-c'); var bar = h('i'); bar.style.height = Math.max(4, Math.round(r.weeks[k] / max * 64)) + 'px';
                    col.title = fmtDate(k) + ' · ' + fmtMin(r.weeks[k]); col.appendChild(bar); ch.appendChild(col);
                });
                box.appendChild(h('div', 'pp-lab', t('ap_settimane')));
                box.appendChild(ch);
            }
            box.appendChild(h('div', 'pp-sub', t('ap_mantiene')));
            var s = sheet(t('pianifica_per_me'), box, [
                btn('pp-btn ghost', t('il_tuo_tempo'), function () { s.close(); openSettings(); }),
                btn('pp-btn primary', t('applica'), function () {
                    api('autoplan', { project: pid, apply: 1 }).then(function () { s.close(); toast(t('ap_fatto')); return refresh(); }).catch(fail);
                })
            ]);
        }).catch(fail);
    }

    /* =================================================================
       PIANO (dentro un progetto) — ore, tipo, scadenza, divisione
       ================================================================= */
    function renderPlan(root) {
        if (S.mode === 'master') return renderOverview(root);
        var id = S.scope; var n = S.nodes[id];
        if (!n) { root.appendChild(h('div', 'pp-hint', t('errore'))); return; }
        if (id !== S.pid) {
            root.appendChild(btn('pp-chip pp-chip-up', [svg(IC.left, 14, 2.4), S.nodes[n.parent] ? S.nodes[n.parent].name : ''], function () { S.scope = n.parent; refresh2(); }));
        }
        root.appendChild(goalCard(n, false));
        var kids = (S.kids[id] || []).filter(function (k) { return S.nodes[k].status !== 'completed'; });
        if (kids.length) root.appendChild(splitCard(n, kids));
        /* il vecchio piano: i pomodori stimati diventano minuti */
        var oldN = subtree(id).filter(function (x) { return S.nodes[x].pom && !goalMin(x); }).length;
        if (oldN) {
            root.appendChild(h('div', 'pp-card pp-old', [
                h('div', 'pp-sum-t', t('vecchio_titolo')),
                h('div', 'pp-sub', t('vecchio_testo', { n: oldN })),
                btn('pp-btn', t('vecchio_importa'), function () {
                    api('import_old', {}).then(function (r) { toast(t('vecchio_fatto', { n: r.imported })); return refresh2(); }).catch(fail);
                })
            ]));
        }
        var acts = h('div', 'pp-tools pp-tools-plan');
        acts.appendChild(btn('pp-tool', [svg(IC.gear, 20, 1.8), h('span', '', t('il_tuo_tempo'))], function () { openSettings(); }));
        acts.appendChild(btn('pp-tool', h('span', '', t('modelli')), function () { openTemplates(id); }));
        acts.appendChild(btn('pp-tool pp-go', [svg(IC.spark, 20, 1.8), h('span', '', t('pianifica_per_me'))], function () { autoplanFlow(); }));
        root.appendChild(acts);
    }
    function refresh2() {
        var big = document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('pp-big-inp');
        var y = window.scrollY;
        return load().then(function () {
            paintAll(); window.scrollTo(0, y);
            if (big) { var el = S.host.querySelector('.pp-big-inp'); if (el) { el.focus(); var l = el.value.length; try { el.setSelectionRange(l, l); } catch (e) { } } }
        }).catch(fail);
    }

    /* la scheda del tempo di UN progetto */
    function goalCard(n, inSheet) {
        var g = n.goal || { minutes: 0, kind: 'plain', priority: 'should', deadline: null, start: null, active: true, items: null, unit_minutes: null };
        var card = h('div', 'pp-card pp-goal');
        var unit = store('unit') === 'days' ? 'days' : 'hours';
        var dayLen = Math.max(30, (S.settings && S.settings.weekday_eff) || 180);
        var top = h('div', 'pp-goal-top');
        top.appendChild(h('div', 'pp-lab', t('tempo_progetto')));
        var big = h('div', 'pp-big');
        var inp = h('input', 'pp-big-inp'); inp.type = 'text'; inp.inputMode = 'decimal'; inp.autocomplete = 'off';
        function showVal() { inp.value = g.minutes ? (unit === 'hours' ? hoursNum(g.minutes) : Math.round(g.minutes / dayLen * 10) / 10) : ''; inp.placeholder = '0'; }
        showVal();
        big.appendChild(inp);
        var seg = h('div', 'pp-unit-seg');
        ['hours', 'days'].forEach(function (u) {
            seg.appendChild(btn('pp-unit-b' + (unit === u ? ' on' : ''), t(u === 'hours' ? 'ore' : 'giorni'), function () { store('unit', u); paintAll(); }));
        });
        big.appendChild(seg);
        top.appendChild(big);
        if (unit === 'days') top.appendChild(h('div', 'pp-sub', t('giorno_vale', { h: fmtMin(dayLen) })));
        card.appendChild(top);

        var save = debounce(function (extra) {
            var body = Object.assign({ project: n.id, minutes: g.minutes, kind: g.kind, priority: g.priority, deadline: g.deadline, start: g.start, active: g.active ? 1 : 0, items: g.items, unit_minutes: g.unit_minutes }, extra || {});
            var resp = body.resplit; delete body.resplit;
            return api('goal_save', body).then(function (r) {
                g.minutes = r.minutes; n.goal = g;
                return resp ? resplit(n, r.minutes) : null;
            }).then(function () { return refresh2(); }).catch(fail);
        }, 500);
        inp.addEventListener('input', function () {
            var v = parseFloat(String(inp.value).replace(',', '.')) || 0;
            g.minutes = Math.round((unit === 'hours' ? v * 60 : v * dayLen) / 5) * 5;
            n.goal = g; save({ resplit: 1 });
        });

        /* quanto è fatto */
        if (g.minutes > 0) {
            var pct = Math.min(100, Math.round(n.done_min * 100 / g.minutes));
            var bar = h('div', 'pp-bar'); var fill = h('i'); fill.style.width = pct + '%'; fill.style.background = n.color; bar.appendChild(fill);
            card.appendChild(bar);
            card.appendChild(h('div', 'pp-sub', t('fatto_di', { a: fmtMin(n.done_min), b: fmtMin(g.minutes) })));
        }

        /* tipo */
        card.appendChild(h('div', 'pp-lab', t('tipo')));
        var kinds = h('div', 'pp-pills');
        ['plain', 'exam', 'steady', 'course', 'count'].forEach(function (k) {
            kinds.appendChild(btn('pp-pill' + (g.kind === k ? ' on' : ''), t('tipo_' + k), function () { g.kind = k; n.goal = g; save(); }));
        });
        card.appendChild(kinds);
        card.appendChild(h('div', 'pp-sub', t('tipo_' + g.kind + '_h')));
        if (g.kind === 'count') {
            var it = h('input', 'pp-inp pp-inp-num'); it.type = 'number'; it.min = 0; it.value = g.items || '';
            var um = h('input', 'pp-inp pp-inp-num'); um.type = 'number'; um.min = 0; um.value = g.unit_minutes || 30;
            function recalc() { g.items = parseInt(it.value, 10) || 0; g.unit_minutes = parseInt(um.value, 10) || 0; if (g.items && g.unit_minutes) { g.minutes = g.items * g.unit_minutes; } n.goal = g; save(); }
            it.addEventListener('input', recalc); um.addEventListener('input', recalc);
            card.appendChild(h('div', 'pp-count', [it, h('span', 'pp-unit', t('voci_per')), um, h('span', 'pp-unit', t('min_ciascuna'))]));
        }
        /* priorità */
        card.appendChild(h('div', 'pp-lab', t('priorita')));
        var pr = h('div', 'pp-pills');
        ['must', 'should', 'nice'].forEach(function (k) {
            pr.appendChild(btn('pp-pill' + (g.priority === k ? ' on' : ''), t('prio_' + k), function () { g.priority = k; n.goal = g; save(); }));
        });
        card.appendChild(pr);
        /* date */
        var dl = h('input', 'pp-inp'); dl.type = 'date'; dl.value = g.deadline || '';
        var st = h('input', 'pp-inp'); st.type = 'date'; st.value = g.start || '';
        dl.addEventListener('change', function () { g.deadline = dl.value || null; n.goal = g; save({ cascade: casc.checked ? 1 : 0 }); });
        st.addEventListener('change', function () { g.start = st.value || null; n.goal = g; save({ cascade: casc.checked ? 1 : 0 }); });
        var eff = n.deadline_eff && !g.deadline ? h('small', '', t('eredita', { d: fmtDate(n.deadline_eff) })) : null;
        card.appendChild(h('div', 'pp-dates', [
            h('label', 'pp-field v', [h('span', 'pp-field-l', [g.kind === 'exam' ? t('data_esame') : t('scadenza'), eff]), dl]),
            h('label', 'pp-field v', [h('span', 'pp-field-l', t('inizio')), st])
        ]));
        var casc = h('input'); casc.type = 'checkbox'; casc.checked = (S.kids[n.id] || []).length > 0;
        if ((S.kids[n.id] || []).length) card.appendChild(h('label', 'pp-field pp-field-check', [casc, h('span', 'pp-field-l', t('anche_figli'))]));
        return card;
    }
    function debounce(fn, ms) {
        var tm = null, last;
        return function () { last = arguments; clearTimeout(tm); tm = setTimeout(function () { fn.apply(null, last); }, ms); };
    }

    /* ---------------- la divisione fra i figli ---------------- */
    function splitCard(parent, kidIds) {
        var card = h('div', 'pp-card pp-split');
        var total = goalMin(parent.id);
        card.appendChild(h('div', 'pp-lab', t('dividi_titolo')));
        if (total <= 0) { card.appendChild(h('div', 'pp-sub', t('dividi_senza_ore'))); return card; }
        card.appendChild(splitBlock(parent, kidIds, total, 0));
        card.appendChild(h('div', 'pp-sub', t('dividi_aiuto', { t: fmtMin(total) })));
        var even = btn('pp-btn ghost', t('dividi_pari'), function () {
            var items = readItems(kidIds);
            items.forEach(function (it) { if (!it.locked) it.v = 1; });
            rescale(items, total);
            commitSplit(parent, kidIds, items, total);
        });
        card.appendChild(h('div', 'pp-split-foot', even));
        return card;
    }
    function readItems(kidIds) {
        return kidIds.map(function (k) { var g = S.nodes[k].goal; return { id: k, v: g ? g.minutes : 0, locked: !!(g && g.locked) }; });
    }
    function splitBlock(parent, kidIds, total, depth) {
        var items = readItems(kidIds);
        var sum = 0; items.forEach(function (i) { sum += i.v; });
        var wrap = h('div', 'pp-sp-wrap');
        /* barra d'insieme */
        var overall = h('div', 'pp-sp-bar');
        var segs = items.map(function (it) { var s = h('i'); s.style.background = S.nodes[it.id].color; overall.appendChild(s); return s; });
        function paintBar() { items.forEach(function (it, i) { segs[i].style.flexGrow = Math.max(0.0001, it.v); }); }
        paintBar();
        if (depth === 0) wrap.appendChild(overall);
        var rows = h('div', 'pp-sp-rows');
        var els = [];
        items.forEach(function (it, i) {
            var n = S.nodes[it.id];
            var row = h('div', 'pp-sp-row' + (depth ? ' nested' : ''));
            var line = h('div', 'pp-sp-line');
            var dot = h('i', 'pp-chip-dot'); dot.style.background = n.color;
            var name = h('span', 'pp-sp-name', n.name);
            var delta = h('span', 'pp-sp-delta');
            var val = h('button', 'pp-sp-val'); val.type = 'button';
            line.appendChild(dot); line.appendChild(name);
            var subKids = (S.kids[it.id] || []).filter(function (x) { return S.nodes[x].status !== 'completed'; });
            if (subKids.length) {
                var open = !!S.openRows[it.id];
                var ex = btn('pp-sp-ex' + (open ? ' open' : ''), svg(IC.chev, 14, 2.6), function () { S.openRows[it.id] = !S.openRows[it.id]; paintAll(); }, t('apri', { n: n.name }));
                line.insertBefore(ex, name);
            }
            line.appendChild(delta); line.appendChild(val);
            row.appendChild(line);
            var ctl = h('div', 'pp-sp-ctl');
            var sl = h('input', 'pp-range'); sl.type = 'range'; sl.min = 0; sl.max = total; sl.step = 15; sl.value = it.v;
            sl.style.setProperty('--c', n.color);
            sl.style.setProperty('--p', (total > 0 ? it.v * 100 / total : 0) + '%');
            sl.setAttribute('aria-label', n.name);
            var lk = btn('pp-lock' + (it.locked ? ' on' : ''), svg(it.locked ? IC.lock : IC.unlock, 20, 1.9), null, (it.locked ? t('sblocca', { n: n.name }) : t('blocca', { n: n.name })));
            sl.disabled = it.locked;
            ctl.appendChild(sl); ctl.appendChild(lk);
            row.appendChild(ctl);
            els.push({ sl: sl, val: val, delta: delta, row: row, lk: lk });
            rows.appendChild(row);

            /* ogni mossa riparte dai valori di quando il dito si è posato: gli
               altri si ricalcolano in proporzione a quelli, non a quello che
               hanno dopo il passo precedente. Così il risultato non dipende dal
               percorso e tornando indietro si ritrovano i numeri di prima. */
            var base = null;
            sl.addEventListener('input', function () {
                if (!base) base = items.map(function (x) { return x.v; });
                items.forEach(function (x, k) { x.v = base[k]; });
                rebalance(items, i, parseInt(sl.value, 10) || 0, total);
                paintAll2();
                sl.value = items[i].v;                // se i lucchetti lo hanno fermato prima, il cursore torna dove sta davvero
                paintBar();
            });
            sl.addEventListener('change', function () { base = null; commitSplit(parent, kidIds, items, total); });
            val.addEventListener('click', function () {
                editVal(val, it.v, function (v) {
                    rebalance(items, i, v, total); paintAll2(); paintBar();
                    commitSplit(parent, kidIds, items, total);
                });
            });
            lk.addEventListener('click', function () {
                it.locked = !it.locked;
                // il lucchetto si salva subito, con gli stessi valori
                commitSplit(parent, kidIds, items, total);
            });
            if (subKids.length && S.openRows[it.id]) {
                var inner = h('div', 'pp-sp-inner');
                if (it.v > 0) inner.appendChild(splitBlock(S.nodes[it.id], subKids, it.v, depth + 1));
                else inner.appendChild(h('div', 'pp-sub', t('dividi_senza_ore')));
                rows.appendChild(inner);
            }
        });
        function paintAll2() {
            items.forEach(function (it, i) {
                var e = els[i], was = S.nodes[it.id].goal ? S.nodes[it.id].goal.minutes : 0;
                e.val.textContent = fmtMin(it.v);
                var diff = it.v - was;
                e.delta.textContent = it.locked ? '' : (diff === 0 ? '' : (diff > 0 ? '+' : '−') + fmtMin(Math.abs(diff)));
                e.delta.className = 'pp-sp-delta' + (diff > 0 ? ' up' : '');
                e.sl.value = it.v;
                e.sl.style.setProperty('--p', (total > 0 ? it.v * 100 / total : 0) + '%');
                e.row.classList.toggle('locked', it.locked);
            });
        }
        paintAll2();
        wrap.appendChild(rows);
        return wrap;
    }
    /* un campo ore al posto del valore, al tocco: scrivi le ore, invio */
    function editVal(valEl, cur, cb) {
        var inp = h('input', 'pp-sp-edit'); inp.type = 'text'; inp.inputMode = 'decimal'; inp.value = hoursNum(cur);
        inp.setAttribute('aria-label', t('ore'));
        valEl.replaceWith(inp); inp.focus(); inp.select();
        var done = false;
        function fin(ok) {
            if (done) return; done = true;
            var x = parseFloat(String(inp.value).replace(',', '.'));
            inp.replaceWith(valEl);
            if (ok && !isNaN(x) && x >= 0) cb(Math.round(x * 60 / 5) * 5);
        }
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') fin(true); if (e.key === 'Escape') fin(false); });
        inp.addEventListener('blur', function () { fin(true); });
    }
    /* Il totale di un progetto è cambiato: i figli si adeguano in proporzione
       (quelli con lucchetto no). Se i figli non avevano ancora ore, si parte
       da una divisione in parti uguali. Lo stesso vale, a cascata, per i loro figli. */
    function resplit(n, total) {
        var kids = (S.kids[n.id] || []).filter(function (x) { return S.nodes[x].status !== 'completed'; });
        if (!kids.length || total <= 0) return Promise.resolve();
        var items = readItems(kids), sum = 0;
        items.forEach(function (i) { sum += i.v; });
        if (sum === 0) items.forEach(function (i) { if (!i.locked) i.v = 1; });
        rescale(items, total);
        return saveItems(n, items, total);
    }
    function saveItems(parent, items, total) {
        var payload = items.map(function (it) { return { id: it.id, minutes: it.v, locked: it.locked ? 1 : 0 }; });
        return api('split_save', { project: parent.id, total: total, items: payload }).then(function (r) {
            var jobs = [];
            r.items.forEach(function (it) {
                var was = S.nodes[it.id].goal ? S.nodes[it.id].goal.minutes : 0;
                if (it.minutes !== was) jobs.push(resplit(S.nodes[it.id], it.minutes));
            });
            return Promise.all(jobs);
        });
    }
    function commitSplit(parent, kidIds, items, total) {
        saveItems(parent, items, total).then(function () { return refresh2(); }).catch(function (e) { fail(e); refresh2(); });
    }

    /* ---------------- modelli ---------------- */
    function openTemplates(id) {
        var box = h('div', 'pp-tpl');
        function draw() {
            box.textContent = '';
            get('tpl_list').then(function (r) {
                box.textContent = '';
                box.appendChild(h('div', 'pp-sub', t('modelli_aiuto')));
                if (!r.templates.length) box.appendChild(h('div', 'pp-hint', t('modelli_vuoto')));
                r.templates.forEach(function (x) {
                    box.appendChild(h('div', 'pp-day-row', [
                        h('div', 'pp-day-name', x.name),
                        btn('pp-btn ghost', t('usa'), function () { api('tpl_apply', { id: x.id, project: id }).then(function (a) { toast(t('modello_applicato', { n: a.applied })); s.close(); return refresh2(); }).catch(fail); }),
                        btn('pp-ic sm', svg(IC.x, 12, 2.6), function () { api('tpl_delete', { id: x.id }).then(draw).catch(fail); }, t('elimina'))
                    ]));
                });
                var nm = h('input', 'pp-inp'); nm.placeholder = t('nome_modello'); nm.maxLength = 80;
                box.appendChild(h('div', 'pp-day-add', [nm, btn('pp-btn', t('salva_modello'), function () {
                    if (!nm.value.trim()) return;
                    api('tpl_save', { project: id, name: nm.value.trim() }).then(function (r2) { toast(t('modello_salvato', { n: r2.saved })); draw(); }).catch(fail);
                })]));
            }).catch(fail);
        }
        draw();
        var s = sheet(t('modelli'), box);
    }

    /* =================================================================
       PANORAMICA (tutti i progetti) — ci sta tutto?
       ================================================================= */
    var mdata = null;
    function loadMaster() {
        return Promise.all([get('master'), load()]).then(function (a) { mdata = a[0]; S.settings = a[0].settings; });
    }
    function renderOverview(root) {
        var m = mdata;
        if (!m) { root.appendChild(h('div', 'pp-hint', t('errore'))); return; }
        var v = h('div', 'pp-card pp-verdict ' + (m.fits ? 'ok' : 'warn'));
        if (!m.units) {
            v.appendChild(h('div', 'pp-verdict-t', t('ov_vuoto_t')));
            v.appendChild(h('div', 'pp-sub', t('ov_vuoto')));
        } else {
            v.appendChild(h('div', 'pp-verdict-t', m.fits ? t('ov_ci_sta', { h: fmtMin(Math.max(0, m.free_min - m.needed_min)) }) : t('ov_non_ci_sta', { h: fmtMin(m.missing_min) })));
            if (!m.fits && m.free_min >= m.needed_min) v.appendChild(h('div', 'pp-sub', t('ov_scadenze')));
            var pct = m.free_min > 0 ? Math.min(100, Math.round(m.needed_min * 100 / m.free_min)) : 100;
            var bar = h('div', 'pp-bar big'); var fi = h('i'); fi.style.width = pct + '%'; bar.appendChild(fi); v.appendChild(bar);
            v.appendChild(h('div', 'pp-ov-nums', [
                h('span', '', [t('serve') + ' ', h('b', '', fmtMin(m.needed_min))]),
                h('span', '', [t('tempo_libero') + ' ', h('b', '', fmtMin(m.free_min))])
            ]));
        }
        root.appendChild(v);

        /* ore per settimana */
        if (m.units) {
            var wk = h('div', 'pp-card pp-weeks');
            wk.appendChild(h('div', 'pp-wk-head', [h('div', 'pp-sum-t', t('ore_settimana')), h('div', 'pp-legend', [h('i'), t('tuo_tempo')])]));
            var max = 60; m.weeks.forEach(function (w) { var s = 0; Object.keys(w.parts).forEach(function (k) { s += w.parts[k]; }); max = Math.max(max, s, w.cap); });
            var H = 130;
            var chart = h('div', 'pp-wk'); chart.style.height = H + 'px';
            var byRoot = {}; m.goals.forEach(function (g) { byRoot[g.id] = g; });
            m.weeks.forEach(function (w) {
                var col = h('div', 'pp-wk-c');
                var capLine = h('i', 'pp-wk-cap'); capLine.style.bottom = Math.round(w.cap / max * H) + 'px'; col.appendChild(capLine);
                var tot = 0;
                Object.keys(w.parts).forEach(function (k) {
                    var seg = h('b'); seg.style.height = Math.max(1, Math.round(w.parts[k] / max * H)) + 'px'; seg.style.background = byRoot[k] ? byRoot[k].color : '#8E8E93'; col.appendChild(seg); tot += w.parts[k];
                });
                col.title = fmtDate(w.start) + ' · ' + fmtMin(tot) + ' / ' + fmtMin(w.cap);
                chart.appendChild(col);
            });
            wk.appendChild(chart);
            var labs = h('div', 'pp-wk-l');
            m.weeks.forEach(function (w, i) { var d = parse(w.start); labs.appendChild(h('span', '', (i === 0 || d.getUTCDate() <= 7) ? fmtDate(w.start, { month: 'short' }) : '')); });
            wk.appendChild(labs);
            root.appendChild(wk);
        }

        /* gli obiettivi */
        var list = h('div', 'pp-card pp-goals');
        m.goals.filter(function (g) { return g.status !== 'completed'; }).forEach(function (g) {
            var row = h('button', 'pp-goal-row'); row.type = 'button';
            var dot = h('i', 'pp-chip-dot'); dot.style.background = g.color;
            var info = h('div', 'pp-goal-info', [h('div', 'pp-goal-n', g.name), h('div', 'pp-sub', goalLine(g))]);
            row.appendChild(dot); row.appendChild(info);
            var right = h('div', 'pp-goal-r');
            right.appendChild(h('b', '', g.minutes ? fmtMin(g.minutes) : '–'));
            if (g.missing_min > 0) right.appendChild(h('small', 'bad', '−' + fmtMin(g.missing_min)));
            row.appendChild(right);
            row.addEventListener('click', function () { window.location.href = 'progetti.php?id=' + g.id + '&pp=plan'; });
            list.appendChild(row);
        });
        var rest = Object.keys(S.nodes).map(Number).filter(function (id) { return S.nodes[id].parent === null && S.nodes[id].status !== 'completed' && !mdata.goals.some(function (g) { return g.id === id; }); });
        if (!m.goals.length) list.appendChild(h('div', 'pp-hint', t('ov_nessun_obiettivo')));
        root.appendChild(list);
        var tools = h('div', 'pp-tools');
        tools.appendChild(btn('pp-tool', [svg(IC.plus, 20, 2), h('span', '', t('aggiungi_obiettivo'))], function () { addGoalSheet(rest); }));
        tools.appendChild(btn('pp-tool', [svg(IC.gear, 20, 1.8), h('span', '', t('il_tuo_tempo'))], function () { openSettings(); }));
        tools.appendChild(btn('pp-tool pp-go', [svg(IC.spark, 20, 1.8), h('span', '', t('riempi_calendario'))], function () { autoplanFlow(); }));
        root.appendChild(tools);
    }
    function goalLine(g) {
        var parts = [t('tipo_' + g.kind)];
        if (g.deadline) parts.push(fmtDate(g.deadline));
        if (g.kids) parts.push(t('n_sotto', { n: g.kids }));
        if (g.done_min) parts.push(t('fatto_breve', { h: fmtMin(g.done_min) }));
        return parts.join(' · ');
    }
    function addGoalSheet(ids) {
        var box = h('div', 'pp-add');
        if (!ids.length) box.appendChild(h('div', 'pp-hint', t('ov_tutti_aggiunti')));
        ids.forEach(function (id) {
            var n = S.nodes[id];
            var row = h('button', 'pp-goal-row'); row.type = 'button';
            var dot = h('i', 'pp-chip-dot'); dot.style.background = n.color;
            row.appendChild(dot); row.appendChild(h('div', 'pp-goal-info', h('div', 'pp-goal-n', n.name)));
            row.addEventListener('click', function () {
                api('goal_save', { project: id, active: 1, minutes: 0 }).then(function () { window.location.href = 'progetti.php?id=' + id + '&pp=plan'; }).catch(fail);
            });
            box.appendChild(row);
        });
        sheet(t('aggiungi_obiettivo'), box);
    }

    /* =================================================================
       OGGI
       ================================================================= */
    var tdata = null;
    function loadToday() { return Promise.all([get('today'), load()]).then(function (a) { tdata = a[0]; }); }
    function renderToday(root) {
        var d = tdata; if (!d) return;
        var tot = 0, dn = 0; d.blocks.forEach(function (b) { if (b.kind === 'study') { tot += b.minutes; dn += Math.min(b.done, b.minutes); } });
        root.appendChild(h('div', 'pp-today-head', [
            h('div', 'pp-sub', fmtDate(d.today, { weekday: 'long', day: 'numeric', month: 'long' })),
            h('div', 'pp-today-t', t('oggi')),
            h('div', 'pp-sub', d.blocks.length ? t('oggi_riga', { n: d.blocks.length, p: fmtMin(tot), f: fmtMin(dn) }) : t('oggi_niente'))
        ]));
        d.skipped.forEach(function (s) {
            var c = h('div', 'pp-card pp-skip');
            c.appendChild(h('div', 'pp-sum-t', t('saltato_t', { n: s.name, h: fmtMin(s.missing) })));
            var acts = h('div', 'pp-skip-acts');
            if (s.move_to) {
                c.appendChild(h('div', 'pp-sub', t('saltato_sposta', { d: s.move_to === d.today ? t('oggi').toLowerCase() : fmtDate(s.move_to, { weekday: 'long' }) })));
                acts.appendChild(btn('pp-btn primary', t('sposta'), function () { api('skip_fix', { project: s.project, mode: 'move', to: s.move_to }).then(refresh).catch(fail); }));
            } else c.appendChild(h('div', 'pp-sub', t('saltato_pieno')));
            acts.appendChild(btn('pp-btn ghost', t('lascia'), function () { api('skip_fix', { project: s.project, mode: 'leave' }).then(refresh).catch(fail); }));
            c.appendChild(acts);
            root.appendChild(c);
        });
        var list = h('div', 'pp-blocks');
        d.blocks.forEach(function (b) {
            var done = b.done >= b.minutes * 0.9 && b.kind === 'study';
            var card = h('div', 'pp-block' + (done ? ' done' : ''));
            var bar = h('i', 'pp-block-bar'); bar.style.background = b.root_color || b.color; card.appendChild(bar);
            card.appendChild(h('div', 'pp-block-info', [
                h('small', '', b.root_name + (b.kind === 'review' ? ' · ' + t('ripasso') : '')),
                h('div', 'pp-block-n', b.name),
                h('div', 'pp-sub', fmtMin(b.minutes) + (b.done ? ' · ' + t('fatti_n', { h: fmtMin(b.done) }) : ''))
            ]));
            var go = h('a', 'pp-play'); go.href = 'pomodoro.php?project_id=' + b.project; go.setAttribute('aria-label', t('avvia', { n: b.name }));
            go.appendChild(svg(done ? IC.check : IC.play, 20, done ? 3 : 0)); if (!done) go.firstChild.setAttribute('fill', 'currentColor');
            card.appendChild(go);
            list.appendChild(card);
        });
        root.appendChild(list);
        var wk = h('div', 'pp-card');
        var pct = d.week.planned > 0 ? Math.min(100, Math.round(d.week.done * 100 / d.week.planned)) : 0;
        wk.appendChild(h('div', 'pp-wk-head', [h('div', 'pp-sum-t', t('questa_settimana')), h('div', 'pp-sub', t('x_di_y', { a: fmtMin(d.week.done), b: fmtMin(d.week.planned) }))]));
        var bar = h('div', 'pp-bar big'); var fi = h('i'); fi.style.width = pct + '%'; bar.appendChild(fi); wk.appendChild(bar);
        if (d.next_exam) wk.appendChild(h('div', 'pp-ov-nums', [h('span', '', t('esame_tra', { n: d.next_exam.name, d: d.next_exam.days })), null]));
        root.appendChild(wk);
        if (!d.blocks.length) {
            var tools = h('div', 'pp-tools');
            tools.appendChild(btn('pp-tool pp-go', [svg(IC.spark, 20, 1.8), h('span', '', t('riempi_calendario'))], function () { autoplanFlow(); }));
            root.appendChild(tools);
        }
    }

    /* =================================================================
       ENTRATE PUBBLICHE
       ================================================================= */
    window.StandoutPiano = {
        project: function (host, pid, tab) {
            S = newState(host, 'project', pid);
            S.tab = tab === 'plan' ? 'plan' : 'cal';
            return load().then(function () { paintAll(); }).catch(fail);
        },
        setTab: function (tab) {
            if (!S || S.mode !== 'project') return;
            S.tab = tab === 'plan' ? 'plan' : 'cal';
            if (S.tab === 'plan') S.scope = S.scope || S.pid;
            refresh2();
        },
        master: function (host, tab) {
            S = newState(host, 'master', 0);
            S.tab = tab || store('mtab') || 'today';
            return Promise.all([get('today'), get('master'), load()]).then(function (a) {
                tdata = a[0]; mdata = a[1]; S.settings = a[1].settings;
                if (S.tab === 'today' && !tdata.blocks.length && !tdata.skipped.length && a[1].units === 0) S.tab = 'plan';
                paintAll();
            }).catch(fail);
        },
        _setSeg: function (tab) { var f = window.PR_PIANO_SEG; if (typeof f === 'function') f(tab); }
    };

})();
