/* =====================================================================
   STANDOUT — IL TRACKER DI UN PROGETTO (la quarta vista: Elenco · Calendario · Piano · Tracker)

   Un tracker e' l'insieme dei blocchi di un progetto: tabella con formule e totali, contatore, barra,
   spunte, nota, il ripasso NeetCode 150 e il collegamento a un sito. E' lo stesso del telefono: la logica
   (formule, scelta dei problemi, intervalli, import) sta in tracker_core.js, che si ricrea dal codice
   dell'app (`npm run build:web-tracker`), cosi' sito e telefono calcolano e scelgono allo stesso modo.
   Parla solo con tracker_api.php. Tutto il testo viene dai file lingua (gruppo «tracker»).

     StandoutTracker.open(host, projectId)
   ===================================================================== */
(function () {
    'use strict';

    var C = window.StandoutTrackerCore;
    var API = 'tracker_api.php';
    if (!C) return;

    /* ---------------- piccoli arnesi ---------------- */
    function t(key, vars) { return typeof window.T === 'function' ? window.T('tracker.' + key, vars) : key; }
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
    function input(cls, value, ph, onCommit, attrs) {
        var i = h('input', cls);
        i.type = 'text'; i.value = value === undefined || value === null ? '' : String(value);
        if (ph) i.placeholder = ph;
        i.autocomplete = 'off'; i.spellcheck = false;
        if (attrs) Object.keys(attrs).forEach(function (k) { i.setAttribute(k, attrs[k]); });
        i.addEventListener('change', function () { onCommit(i.value, i); });
        i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); i.blur(); } });
        return i;
    }
    function two(n) { return (n < 10 ? '0' : '') + n; }
    function today() { var d = new Date(); return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()); }
    function uid() {
        try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID(); } catch (e) { }
        return 'i' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }
    function num(x, d) { var n = parseFloat(String(x).replace(',', '.')); return isFinite(n) ? n : d; }
    function fmt(n) { return String(Math.round(n * 1e4) / 1e4); }

    function toast(msg) {
        var el = document.getElementById('ppToast');
        if (!el) { el = h('div', 'pp-toast'); el.id = 'ppToast'; el.setAttribute('role', 'status'); document.body.appendChild(el); }
        el.textContent = msg; el.classList.add('show');
        clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 2400);
    }
    function fail(e) { toast(e && e.message && e.message !== 'error' ? e.message : t('save_error')); }

    function call(action, data) {
        var post = data !== undefined;
        var opt = { credentials: 'same-origin' };
        var url = API + '?ajax=' + encodeURIComponent(action);
        if (post) { opt.method = 'POST'; opt.headers = { 'Content-Type': 'application/json' }; opt.body = JSON.stringify(data); }
        else if (data === undefined && action === 'list') url += '&project=' + S.pid;
        return fetch(url, opt).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || d.ok === false) throw new Error((d && (d.message || d.error)) || 'error');
            return d;
        });
    }

    var S = { host: null, pid: 0, blocks: [], max: 20, loaded: false, timers: {}, pending: {} };

    /* ---------------- fogli (bottom sheet), gli stessi del piano ---------------- */
    function sheet(title, body, onClose) {
        var ov = h('div', 'pp-ov'), box = h('div', 'pp-sheet');
        box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', title);
        var head = h('div', 'pp-sheet-head', [h('div', 'pp-sheet-title', title), btn('pp-x', '✕', close, t('cancel'))]);
        box.appendChild(head); box.appendChild(h('div', 'pp-sheet-body', body));
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
        return { close: close, body: box.querySelector('.pp-sheet-body'), title: box.querySelector('.pp-sheet-title') };
    }
    function confirmBox(text, onYes) {
        var body = h('div', 'tk-ask', [h('p', '', text)]);
        var sh = sheet(t('remove'), body);
        body.appendChild(h('div', 'tk-row', [
            btn('pp-btn', t('cancel'), sh.close),
            btn('pp-btn danger', t('remove'), function () { sh.close(); onYes(); })
        ]));
    }

    /* ---------------- salvataggio: un blocco alla volta, con un attimo di pazienza ---------------- */
    function flush(b) {
        var p = S.pending[b.id]; if (!p) return Promise.resolve();
        delete S.pending[b.id]; clearTimeout(S.timers[b.id]);
        var body = { id: b.id };
        if (p.title !== undefined) body.title = p.title;
        if (p.config !== undefined) body.config = JSON.stringify(p.config);
        if (p.data !== undefined) body.data = JSON.stringify(p.data);
        return call('save', body).catch(fail);
    }
    /** Cambia titolo, config e/o data: subito in locale, sul server fra poco. */
    function save(b, change, now) {
        ['title', 'config', 'data'].forEach(function (k) { if (change[k] !== undefined) b[k] = change[k]; });
        var p = S.pending[b.id] || (S.pending[b.id] = {});
        ['title', 'config', 'data'].forEach(function (k) { if (change[k] !== undefined) p[k] = change[k]; });
        clearTimeout(S.timers[b.id]);
        if (now) return flush(b);
        S.timers[b.id] = setTimeout(function () { flush(b); }, 500);
        return Promise.resolve();
    }
    window.addEventListener('pagehide', function () { S.blocks.forEach(function (b) { if (S.pending[b.id]) { flush(b); } }); });

    /* ---------------- l'elenco dei blocchi ---------------- */
    function kindName(k) { return t('k_' + k); }

    function render() {
        var host = S.host; host.textContent = '';
        var root = h('div', 'tk');
        if (!S.blocks.length) {
            root.appendChild(h('div', 'tk-empty', [h('div', 'tk-empty-t', t('empty_title')), h('div', 'pp-sub', t('empty_text'))]));
        }
        S.blocks.forEach(function (b, i) { root.appendChild(blockCard(b, i)); });
        root.appendChild(btn('pp-btn primary tk-add', '+ ' + t('add_block'), function () { addSheet(); }));
        host.appendChild(root);
    }

    function blockCard(b, i) {
        var card = h('section', 'pp-card tk-block'); card.setAttribute('data-kind', b.kind);
        var head = h('div', 'tk-head');
        head.appendChild(input('tk-title', b.title, kindName(b.kind), function (v) { save(b, { title: v.trim().slice(0, 120) }, true); }));
        var tools = h('div', 'tk-tools');
        if (i > 0) tools.appendChild(btn('tk-ic', '↑', function () { move(b, -1); }, t('move_up')));
        if (i < S.blocks.length - 1) tools.appendChild(btn('tk-ic', '↓', function () { move(b, 1); }, t('move_down')));
        tools.appendChild(btn('tk-ic', '✕', function () { confirmBox(t('remove_ask'), function () { remove(b); }); }, t('remove')));
        head.appendChild(tools);
        card.appendChild(head);
        var body = h('div', 'tk-body');
        card.appendChild(body);
        b._body = body;
        drawBody(b);
        return card;
    }
    function drawBody(b) {
        var body = b._body; if (!body) return;
        body.textContent = '';
        var fn = { table: bTable, counter: bCounter, progress: bProgress, checklist: bChecklist, note: bNote, review: bReview, connection: bConnection }[b.kind];
        if (fn) fn(body, b);
        else body.appendChild(h('div', 'pp-hint', t('k_' + b.kind)));
    }
    function redrawAll() { S.blocks.forEach(drawBody); }

    function move(b, dir) {
        flush(b);
        call('move', { id: b.id, dir: dir }).then(function () {
            var i = S.blocks.indexOf(b), j = i + dir;
            if (j < 0 || j >= S.blocks.length) return;
            S.blocks.splice(i, 1); S.blocks.splice(j, 0, b);
            render();
        }).catch(fail);
    }
    function remove(b) {
        call('remove', { id: b.id }).then(function () {
            S.blocks.splice(S.blocks.indexOf(b), 1);
            delete S.pending[b.id]; clearTimeout(S.timers[b.id]);
            render();
        }).catch(fail);
    }
    function addBlock(kind, title, start) {
        if (S.blocks.length >= S.max) { toast(t('full', { n: S.max })); return Promise.resolve(null); }
        if (kind === 'connection' && S.blocks.some(function (x) { return x.kind === 'connection'; })) { toast(t('full', { n: S.max })); return Promise.resolve(null); }
        var base = C.emptyBlock(kind);
        var config = start && start.config ? start.config : base.config;
        var data = start && start.data ? start.data : base.data;
        return call('add', { project: S.pid, kind: kind, title: title || '', config: JSON.stringify(config), data: JSON.stringify(data) }).then(function (d) {
            if (!d.block) throw new Error('error');
            S.blocks.push(d.block); render(); return d.block;
        }).catch(function (e) { fail(e); return null; });
    }

    /* il menu «Aggiungi un blocco»: una scelta alla volta; Avanzato apre altre opzioni */
    function addSheet() {
        var page = 'root';
        var body = h('div', 'tk-pick');
        var sh = sheet(t('pick_block'), body);
        function choice(label, desc, fn, chevron) {
            var b = btn('tk-choice', [h('span', 'tk-choice-t', label), h('span', 'tk-choice-d', desc), chevron ? h('span', 'tk-choice-c', '›') : null], fn);
            return b;
        }
        function pick(kind, title) { sh.close(); addBlock(kind, title); }
        function draw() {
            body.textContent = '';
            if (page !== 'root') body.appendChild(btn('tk-back', '‹ ' + t('back'), function () { page = page === 'programming' ? 'advanced' : 'root'; draw(); }));
            if (page === 'root') {
                ['table', 'counter', 'progress', 'checklist', 'note'].forEach(function (k) {
                    body.appendChild(choice(t('k_' + k), t('d_' + k), function () { pick(k, ''); }));
                });
                body.appendChild(choice(t('k_advanced'), t('d_advanced'), function () { page = 'advanced'; draw(); }, true));
            } else if (page === 'advanced') {
                body.appendChild(choice(t('k_connection'), t('d_connection'), function () { pick('connection', t('k_connection')); }));
                body.appendChild(choice(t('k_programming'), t('d_programming'), function () { page = 'programming'; draw(); }, true));
            } else {
                body.appendChild(choice(t('k_review'), t('d_review'), function () { pick('review', t('k_review')); }));
                /* i modelli già riempiti: blocchi normali (lista, tabella), poi si cambiano come gli altri */
                ['blind75', 'streak'].forEach(function (id) {
                    body.appendChild(choice(t('k_' + id), t('d_' + id), function () {
                        var tpl = C.templateBlock(id);
                        sh.close(); addBlock(tpl.kind, t('k_' + id), { config: tpl.config, data: tpl.data });
                    }));
                });
            }
        }
        draw();
    }

    /* =================================================================
       TABELLA
       ================================================================= */
    function bTable(root, b) {
        var cfg = C.tableConfig(b.config), cells = C.tableData(b.data).cells, values = C.evaluate(cells);
        function put(nextCells, nextCfg) { save(b, { config: nextCfg || cfg, data: { cells: nextCells } }); drawBody(b); }
        function setCell(key, v) { var n = Object.assign({}, cells); if (v === '') delete n[key]; else n[key] = v; put(n); }
        function resize(cols, rows) {
            cols = Math.min(C.MAX_COLS, Math.max(1, cols)); rows = Math.min(C.MAX_ROWS, Math.max(1, rows));
            if (cols === cfg.cols && rows === cfg.rows) return;
            var n = {};
            Object.keys(cells).forEach(function (k) {
                var m = /^([A-Z]+)(\d+)$/.exec(k); if (!m) return;
                var c = 0; for (var i = 0; i < m[1].length; i++) c = c * 26 + (m[1].charCodeAt(i) - 64);
                if (c - 1 < cols && Number(m[2]) <= rows) n[k] = cells[k];
            });
            put(n, { cols: cols, rows: rows, headers: cfg.headers.slice(0, cols) });
        }
        var tb = h('table', 'tk-grid'), hr = h('tr');
        hr.appendChild(h('th', 'tk-corner'));
        for (var c = 0; c < cfg.cols; c++) hr.appendChild(h('th', '', C.colName(c)));
        tb.appendChild(hr);
        for (var r = 0; r < cfg.rows; r++) {
            var tr = h('tr'); tr.appendChild(h('th', 'tk-rown', r + 1));
            for (c = 0; c < cfg.cols; c++) tr.appendChild(h('td', '', cellInput(C.cellKey(c, r))));
            tb.appendChild(tr);
        }
        var tot = h('tr', 'tk-tot'); tot.appendChild(h('th', 'tk-rown', 'Σ'));
        for (c = 0; c < cfg.cols; c++) {
            tot.appendChild(h('td', '', c === 0 && !cells[C.cellKey(0, 0)] ? t('t_total') : C.show(C.columnSum(values, c, cfg.rows))));
        }
        tb.appendChild(tot);
        function cellInput(key) {
            var raw = cells[key] || '', shown = C.show(values[key]);
            var i = input('tk-cell' + (typeof values[key] === 'number' ? ' num' : '') + (/^#/.test(shown) ? ' err' : ''), raw, '', function (v) { if (v !== raw) setCell(key, v); });
            if (raw.charAt(0) === '=') { i.value = shown; i.setAttribute('data-formula', raw); }
            i.addEventListener('focus', function () { i.value = raw; i.select(); });
            i.addEventListener('blur', function () { if (raw.charAt(0) === '=' && i.value === raw) i.value = shown; });
            return i;
        }
        root.appendChild(h('div', 'tk-scroll', tb));
        root.appendChild(h('div', 'tk-row wrap', [
            btn('pp-btn small', t('t_add_row'), function () { resize(cfg.cols, cfg.rows + 1); }),
            btn('pp-btn small', t('t_add_col'), function () { resize(cfg.cols + 1, cfg.rows); }),
            btn('pp-btn small', t('t_del_row'), function () { resize(cfg.cols, cfg.rows - 1); }),
            btn('pp-btn small', t('t_del_col'), function () { resize(cfg.cols - 1, cfg.rows); })
        ]));
        root.appendChild(h('div', 'pp-hint', t('t_hint')));
    }

    /* =================================================================
       CONTATORE · BARRA · SPUNTE · NOTA
       ================================================================= */
    function bCounter(root, b) {
        var cfg = C.counterConfig(b.config), value = C.counterData(b.data).value;
        function go(d) { save(b, { data: { value: Math.round((value + d) * 1e4) / 1e4 } }); redrawAll(); }
        root.appendChild(h('div', 'tk-counter', [
            btn('tk-round', '−', function () { go(-cfg.step); }, t('c_minus')),
            h('div', 'tk-bignum', [h('b', '', fmt(value)), cfg.unit ? h('small', '', cfg.unit) : null]),
            btn('tk-round', '+', function () { go(cfg.step); }, t('c_plus'))
        ]));
        root.appendChild(h('div', 'tk-row wrap', [
            h('label', 'tk-field', [h('span', '', t('c_unit_ph')), input('tk-in', cfg.unit, '', function (v) { save(b, { config: { step: cfg.step, unit: v.trim().slice(0, 20) } }, true); redrawAll(); })]),
            h('label', 'tk-field', [h('span', '', t('c_step')), input('tk-in short', fmt(cfg.step), '', function (v) { save(b, { config: { step: C.counterConfig({ step: num(v, 1) }).step, unit: cfg.unit } }, true); redrawAll(); }, { inputmode: 'decimal' })])
        ]));
    }
    function bProgress(root, b) {
        var cfg = C.progressConfig(b.config);
        var counters = S.blocks.filter(function (x) { return x.kind === 'counter'; });
        var source = null;
        counters.forEach(function (c) { if (cfg.source && c.uuid === cfg.source) source = c; });
        var value = source ? C.counterData(source.data).value : C.counterData(b.data).value;
        var ratio = Math.max(0, Math.min(1, value / cfg.goal));
        root.appendChild(h('div', 'tk-prog-top', [
            h('b', '', t('p_of', { a: fmt(value), b: fmt(cfg.goal) }) + (cfg.unit ? ' ' + cfg.unit : '')),
            h('span', 'pp-sub', Math.round(ratio * 100) + '%')
        ]));
        var bar = h('div', 'pp-bar big'), fill = h('i'); fill.style.width = (ratio * 100) + '%'; bar.appendChild(fill); root.appendChild(bar);
        var row = h('div', 'tk-row wrap');
        row.appendChild(h('label', 'tk-field', [h('span', '', t('p_goal')), input('tk-in short', fmt(cfg.goal), '', function (v) { save(b, { config: { goal: C.progressConfig({ goal: num(v, 100) }).goal, unit: cfg.unit, source: cfg.source } }, true); redrawAll(); }, { inputmode: 'decimal' })]));
        row.appendChild(h('label', 'tk-field', [h('span', '', t('c_unit_ph')), input('tk-in short', cfg.unit, '', function (v) { save(b, { config: { goal: cfg.goal, unit: v.trim().slice(0, 20), source: cfg.source } }, true); redrawAll(); })]));
        if (!source) row.appendChild(h('label', 'tk-field', [h('span', '', t('p_done')), input('tk-in short', fmt(value), '', function (v) { save(b, { data: { value: num(v, 0) } }, true); redrawAll(); }, { inputmode: 'decimal' })]));
        root.appendChild(row);
        if (counters.length) {
            var pick = h('div', 'tk-row wrap');
            pick.appendChild(h('span', 'pp-sub', t('p_source')));
            function setSource(id) { save(b, { config: { goal: cfg.goal, unit: cfg.unit, source: id } }, true); redrawAll(); }
            pick.appendChild(btn('pp-btn small' + (!cfg.source ? ' primary' : ''), t('p_by_hand'), function () { setSource(null); }));
            counters.forEach(function (c) { pick.appendChild(btn('pp-btn small' + (cfg.source === c.uuid ? ' primary' : ''), c.title || t('k_counter'), function () { setSource(c.uuid); })); });
            root.appendChild(pick);
        }
    }
    function bChecklist(root, b) {
        var items = C.checklistData(b.data).items;
        var done = items.filter(function (i) { return i.done; }).length;
        function put(next) { save(b, { data: { items: next } }); drawBody(b); redrawAll(); }
        if (items.length) root.appendChild(h('div', 'pp-sub', t('l_n_of_m', { n: done, m: items.length })));
        items.forEach(function (it) {
            var cb = h('input'); cb.type = 'checkbox'; cb.checked = !!it.done;
            cb.addEventListener('change', function () { put(items.map(function (x) { return x.id === it.id ? { id: x.id, text: x.text, done: cb.checked } : x; })); });
            root.appendChild(h('div', 'tk-item' + (it.done ? ' done' : ''), [
                h('label', 'tk-item-l', [cb, h('span', '', it.text)]),
                btn('tk-ic', '✕', function () { put(items.filter(function (x) { return x.id !== it.id; })); }, t('l_remove_item'))
            ]));
        });
        var add = input('tk-in wide', '', t('l_add_ph'), function (v, el) {
            var text = v.trim().slice(0, 200); if (!text || items.length >= 500) { el.value = ''; return; }
            put(items.concat([{ id: uid(), text: text, done: false }]));
        });
        root.appendChild(add);
    }
    function bNote(root, b) {
        var text = C.noteData(b.data).text;
        var ta = h('textarea', 'tk-note'); ta.value = text; ta.placeholder = t('n_ph'); ta.rows = 5;
        ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(600, ta.scrollHeight) + 'px'; });
        ta.addEventListener('change', function () { var v = ta.value.slice(0, 20000); if (v !== text) { text = v; save(b, { data: { text: v } }, true); } });
        root.appendChild(ta);
        setTimeout(function () { ta.style.height = 'auto'; ta.style.height = Math.min(600, Math.max(110, ta.scrollHeight)) + 'px'; }, 0);
    }

    /* =================================================================
       COLLEGAMENTO: l'indirizzo di un sito + i dati incollati = una tabella
       ================================================================= */
    function bConnection(root, b) {
        var url = typeof (b.config || {}).url === 'string' ? b.config.url : '';
        var tableId = typeof (b.data || {}).table === 'string' ? b.data.table : null;
        var linked = null;
        S.blocks.forEach(function (x) { if (tableId && x.uuid === tableId) linked = x; });
        var msg = h('div', 'tk-msg');
        root.appendChild(h('label', 'tk-field wide', [h('span', '', t('x_url')), input('tk-in wide', url, t('x_url_ph'), function (v, el) {
            v = v.trim();
            if (v && !C.cleanUrl(v)) { msg.textContent = t('x_url_bad'); return; }
            msg.textContent = '';
            var keep = Object.assign({}, b.config, { url: v });
            save(b, { config: keep }, true); drawBody(b);
        }, { inputmode: 'url', autocapitalize: 'none' })]));
        var clean = C.cleanUrl(url);
        if (clean) { var a = h('a', 'pp-btn small', t('x_open')); a.href = clean; a.target = '_blank'; a.rel = 'noopener noreferrer'; root.appendChild(h('div', 'tk-row', a)); }
        root.appendChild(h('div', 'pp-hint', t('x_hint')));
        var ta = h('textarea', 'tk-paste'); ta.placeholder = t('x_paste_ph'); ta.rows = 5; ta.setAttribute('autocapitalize', 'none'); ta.spellcheck = false;
        root.appendChild(ta);
        var go = btn('pp-btn primary', linked ? t('x_update') : t('x_build'), function () {
            var table = C.parseTable(ta.value);
            if (!table) { msg.textContent = t('x_bad'); return; }
            var config = { cols: table.cols, rows: table.rows, headers: [] }, data = { cells: table.cells };
            var finish = function (tb) {
                if (!tb) return;
                save(b, { data: { table: tb.uuid, last: new Date().toISOString() } }, true);
                ta.value = ''; redrawAll();
                var m = b._body && b._body.querySelector('.tk-msg'); if (m) m.textContent = t('x_done', { r: table.rows, c: table.cols });
            };
            if (linked) { save(linked, { config: config, data: data }, true).then(function () { finish(linked); }); }
            else addBlock('table', C.hostOf(url) || t('k_table'), { config: config, data: data }).then(function (nb) {
                if (nb) { finish(nb); } 
            });
        });
        root.appendChild(h('div', 'tk-row', go));
        root.appendChild(msg);
        root.appendChild(h('div', 'pp-hint', t('x_live')));
    }

    /* =================================================================
       NEETCODE 150: il ripasso a intervalli
       ================================================================= */
    var NC = {};   // lo stato dello schermo (scheda, filtri…) di ogni blocco, per id
    function ncUi(b) { return NC[b.id] || (NC[b.id] = { tab: 'today', diff: 'M', onlyStarted: true, msg: null, todoDiff: 'ALL', todoQ: '', solvedQ: '', selecting: false, selected: [] }); }

    function bReview(root, b) {
        var ui = ncUi(b), now = today();
        var data = C.reviewData(b.data), items = data.items;
        var picks = data.picks && data.picks.date === now ? data.picks : { date: now, review: [], news: [] };
        function again() { drawBody(b); }
        function write(next, nextPicks, lastExport) {
            save(b, { data: { items: next, lastExport: lastExport === undefined ? data.lastExport : lastExport, picks: nextPicks || picks } }, true);
            again();
        }
        var P = C.PROBLEMS;
        function diffName(d) { return t(d === 'E' ? 'r_easy' : d === 'M' ? 'r_medium' : 'r_hard'); }
        function pill(label, cls) { return h('span', 'tk-pill ' + (cls || ''), label); }
        function dueText(d) { return d.kind === 'overdue' ? t('r_over', { n: d.n }) : d.kind === 'today' ? t('r_due_today') : t('r_due_in', { n: d.n }); }
        function seenText(s) { return s.kind === 'today' ? t('r_seen_today') : s.kind === 'earlier' ? t('r_seen_earlier') : t('r_seen_ago', { n: s.n }); }
        function noteText(code) {
            return code === 'none_started' ? t('r_note_none_started', { n: C.STARTED_AT })
                : code === 'finished_started' ? t('r_note_finished')
                : code.indexOf('learn:') === 0 ? t('r_note_learn', { c: code.slice(6) }) : '';
        }
        function say(m) { ui.msg = m; }

        /* azioni */
        function doPickReview() {
            var ids = C.pickReview(items, now, Math.random);
            if (!ids.length) { say(Object.keys(items).length ? t('r_all_reviewed') : t('r_none_review')); again(); return; }
            say(null); write(items, { date: now, review: ids, news: picks.news });
        }
        function doPickNew() {
            var cand = C.newCandidates(items, ui.diff, ui.onlyStarted);
            if (!cand.list.length) { say(t('r_none_left', { d: diffName(ui.diff).toLowerCase() })); again(); return; }
            say(null);
            var chosen = C.pickNew(items, cand.list, Math.random);
            write(items, { date: now, review: picks.review, news: chosen.map(function (p, i) { return { id: p.id, note: i === 0 ? cand.note : '' }; }) });
        }
        function doSwap(index) {
            var cand = C.newCandidates(items, ui.diff, ui.onlyStarted);
            var id = C.swapNew(items, cand.list, picks.news.map(function (e) { return e.id; }), index, Math.random);
            if (id === null) { say(t('r_no_other')); again(); return; }
            say(null);
            write(items, { date: now, review: picks.review, news: picks.news.map(function (e, i) { return i === index ? { id: id, note: '' } : e; }) });
        }
        function doLearn() {
            var l = C.learnPattern(items);
            if (!l) { say(t('r_all_patterns')); again(); return; }
            say(null); write(items, { date: now, review: picks.review, news: [{ id: l.id, note: 'learn:' + l.cat }] });
        }
        function doLog(id, choice) {
            var rec = C.nextRecord(items[id] || null, id, choice, now);
            var match = choice.pattern === P[id].cat ? t('s_match') : t('s_other', { c: P[id].cat });
            say(t('s_saved', { due: dueText(C.dueLabel(rec, now)), match: match }));
            var next = Object.assign({}, items); next[id] = rec; write(next);
        }
        function doRemove(id) { var next = Object.assign({}, items); delete next[id]; say(t('s_removed')); write(next); }

        /* pezzi */
        function card(id, body) {
            return h('div', 'tk-pcard', [h('div', 'tk-pcard-top', [h('b', '', P[id].name), pill(diffName(P[id].diff), 'd' + P[id].diff)]), body]);
        }
        function reviewCard(id) {
            var it = items[id]; if (!it) return null;
            var done = it.lastReviewed === now;
            var meta = h('div', 'tk-meta', [pill(it.rating + ' ' + t('s_r' + it.rating), 'r' + it.rating), h('span', 'pp-sub', seenText(C.seenLabel(it, now))), done ? null : h('span', 'pp-sub', dueText(C.dueLabel(it, now)))]);
            return card(id, h('div', 'tk-stack', [meta, done ? h('div', 'tk-ok', t('r_reviewed_today', { n: it.rating })) : btn('pp-btn small', t('r_log_result'), function () { logSheet(id); })]));
        }
        function newCard(e, index) {
            var it = items[e.id];
            var kids = [];
            if (e.note) kids.push(h('div', 'tk-learn', noteText(e.note)));
            if (it) kids.push(h('div', 'tk-ok', t('r_solved_rated', { n: it.rating })));
            else {
                var row = h('div', 'tk-row', [btn('pp-btn small primary', t('r_log_solved'), function () { logSheet(e.id); })]);
                if (e.note.indexOf('learn:') !== 0) row.appendChild(btn('pp-btn small', t('r_swap'), function () { doSwap(index); }));
                kids.push(row);
            }
            return card(e.id, h('div', 'tk-stack', kids));
        }
        function seg(items2, value, onChange) {
            var s = h('div', 'pp-seg tk-seg');
            items2.forEach(function (x) { s.appendChild(btn('pp-seg-it' + (value === x[0] ? ' on' : ''), x[1], function () { onChange(x[0]); })); });
            return s;
        }
        function logSheet(id) {
            var prev = items[id] || null, rating = null, pattern = prev ? prev.pattern : null, when = 'today';
            var body = h('div', 'tk-log');
            var sh = sheet(P[id].name, body);
            function draw() {
                body.textContent = '';
                var top = h('div', 'tk-meta', [pill(diffName(P[id].diff), 'd' + P[id].diff)]);
                if (prev) top.appendChild(h('span', 'pp-sub', t('s_last', { r: prev.rating, s: seenText(C.seenLabel(prev, now)) })));
                body.appendChild(top);
                if (!prev) {
                    body.appendChild(h('div', 'pp-lab', t('s_when')));
                    body.appendChild(seg([['today', t('s_today')], ['earlier', t('s_earlier')]], when, function (v) { when = v; draw(); }));
                }
                body.appendChild(h('div', 'pp-lab', t('s_pattern')));
                var pw = h('div', 'tk-chips');
                C.PATTERNS.forEach(function (c) { pw.appendChild(btn('tk-chip' + (pattern === c ? ' on' : ''), c, function () { pattern = c; draw(); })); });
                body.appendChild(pw);
                body.appendChild(h('div', 'pp-lab', t('s_hard')));
                [1, 2, 3, 4].forEach(function (r) {
                    body.appendChild(btn('tk-rate' + (rating === r ? ' on' : ''), [h('b', 'r' + r, r), h('span', '', [h('span', 'tk-rate-t', t('s_r' + r)), h('span', 'pp-sub', t('s_r' + r + 'd') + ' · ' + t('s_next', { n: C.INT[r] }))])], function () { rating = r; draw(); }));
                });
                var ok = rating !== null && pattern !== null;
                var save1 = btn('pp-btn primary full', prev ? t('s_save_review') : t('s_save'), function () { if (!ok) return; sh.close(); doLog(id, { rating: rating, pattern: pattern, when: when }); });
                save1.disabled = !ok;
                body.appendChild(save1);
                body.appendChild(btn('pp-btn full', t('cancel'), sh.close));
                if (prev) body.appendChild(btn('pp-btn danger full', t('s_remove'), function () { sh.close(); doRemove(id); }));
            }
            draw();
        }

        /* le quattro schede */
        function viewToday() {
            var all = Object.keys(items).map(function (k) { return items[k]; });
            var due = all.filter(function (it) { return C.diffDays(now, it.nextDue) >= 0; }).length;
            var doneToday = all.filter(function (it) { return it.lastReviewed === now; }).length;
            var cand = C.newCandidates(items, ui.diff, ui.onlyStarted);
            var wrap = h('div', 'tk-stack');
            function tile(n, label) { return h('div', 'tk-tile', [h('b', '', n), h('span', 'pp-sub', label)]); }
            wrap.appendChild(h('div', 'tk-tiles', [tile(all.length + '/' + C.TOTAL, t('r_tile_solved')), tile(due, t('r_tile_due')), tile(doneToday, t('r_tile_done'))]));
            var c1 = h('div', 'pp-card-lite tk-stack', [h('div', 'pp-sum-t', t('r_hour')), h('div', 'pp-sub', t('r_hour_hint')),
                btn('pp-btn primary', picks.review.length ? t('r_pick3_again') : t('r_pick3'), doPickReview)]);
            picks.review.forEach(function (id) { var cEl = reviewCard(id); if (cEl) c1.appendChild(cEl); });
            wrap.appendChild(c1);
            var c2 = h('div', 'pp-card-lite tk-stack', [h('div', 'pp-sum-t', t('r_new')), h('div', 'pp-sub', t('r_new_hint')),
                seg([['M', t('r_medium')], ['E', t('r_easy')], ['H', t('r_hard')]], ui.diff, function (v) { ui.diff = v; again(); })]);
            var cb = h('input'); cb.type = 'checkbox'; cb.checked = ui.onlyStarted;
            cb.addEventListener('change', function () { ui.onlyStarted = cb.checked; again(); });
            c2.appendChild(h('label', 'tk-item-l', [cb, h('span', '', t('r_only_started', { n: C.STARTED_AT }))]));
            c2.appendChild(h('div', 'tk-row wrap', [btn('pp-btn primary', picks.news.length ? t('r_pick2_again') : t('r_pick2'), doPickNew), btn('pp-btn', t('r_learn'), doLearn)]));
            if (picks.news.length) picks.news.forEach(function (e, i) { c2.appendChild(newCard(e, i)); });
            else c2.appendChild(h('div', 'pp-hint', t('r_pool', { n: cand.list.length, d: diffName(ui.diff).toLowerCase() })));
            wrap.appendChild(c2);
            return wrap;
        }
        function viewSolved() {
            var counts = C.solvedCountByCat(items), started = C.startedCats(items);
            var wrap = h('div', 'tk-stack'), grid = h('div', 'tk-cats');
            C.CATEGORIES.forEach(function (c) {
                var total = P.filter(function (p) { return p.cat === c; }).length, n = counts[c] || 0;
                var bar = h('div', 'tk-mini'), fill = h('i'); fill.style.width = (100 * n / total) + '%'; bar.appendChild(fill);
                grid.appendChild(h('div', 'tk-cat' + (started.has(c) ? ' on' : ''), [h('span', 'tk-cat-n', c), h('b', '', n + '/' + total), bar]));
            });
            wrap.appendChild(grid);
            var list = h('div', 'tk-stack');
            function fill() {
                list.textContent = '';
                var rows = C.solvedList(items, ui.solvedQ);
                if (!rows.length) { list.appendChild(h('div', 'pp-hint tk-center', Object.keys(items).length ? t('r_no_match') : t('r_no_solved'))); return; }
                rows.forEach(function (it) {
                    list.appendChild(btn('tk-line', [h('span', 'tk-line-l', [h('b', '', P[it.n].name), h('span', 'pp-sub', it.pattern)]), h('span', 'tk-line-r', [pill(it.rating + ' ' + t('s_r' + it.rating), 'r' + it.rating), h('span', 'pp-sub', dueText(C.dueLabel(it, now)))])], function () { logSheet(it.n); }));
                });
            }
            wrap.appendChild(input('tk-in wide', ui.solvedQ, t('r_search_solved'), function () { }));
            var q = wrap.lastChild; q.addEventListener('input', function () { ui.solvedQ = q.value; fill(); });
            fill(); wrap.appendChild(list);
            return wrap;
        }
        function viewTodo() {
            var wrap = h('div', 'tk-stack');
            wrap.appendChild(h('div', 'pp-hint', t('r_todo_hint')));
            var list = h('div', 'tk-stack'), addBtn = null;
            function fill() {
                list.textContent = '';
                var q = ui.todoQ.trim().toLowerCase();
                var rows = P.filter(function (p) { return !items[p.id] && (ui.todoDiff === 'ALL' || p.diff === ui.todoDiff) && (!q || p.name.toLowerCase().indexOf(q) >= 0); });
                if (!rows.length) { list.appendChild(h('div', 'pp-hint tk-center', t('r_nothing_left'))); return; }
                rows.forEach(function (p) {
                    if (ui.selecting) {
                        var cb = h('input'); cb.type = 'checkbox'; cb.checked = ui.selected.indexOf(p.id) >= 0;
                        cb.addEventListener('change', function () { ui.selected = cb.checked ? ui.selected.concat([p.id]) : ui.selected.filter(function (x) { return x !== p.id; }); refreshAdd(); });
                        list.appendChild(h('label', 'tk-line', [h('span', 'tk-line-l', [h('b', '', p.name)]), pill(diffName(p.diff), 'd' + p.diff), cb]));
                    } else list.appendChild(btn('tk-line', [h('span', 'tk-line-l', [h('b', '', p.name)]), pill(diffName(p.diff), 'd' + p.diff)], function () { logSheet(p.id); }));
                });
            }
            function refreshAdd() { if (addBtn) { addBtn.textContent = t('r_add_n', { n: ui.selected.length }); addBtn.disabled = !ui.selected.length; } }
            var q = input('tk-in wide', ui.todoQ, t('r_search_todo'), function () { });
            q.addEventListener('input', function () { ui.todoQ = q.value; fill(); });
            wrap.appendChild(q);
            wrap.appendChild(h('div', 'tk-row wrap', [
                seg([['ALL', t('r_all')], ['E', t('r_easy')], ['M', t('r_medium')], ['H', t('r_hard')]], ui.todoDiff, function (v) { ui.todoDiff = v; again(); }),
                btn('pp-btn small' + (ui.selecting ? ' primary' : ''), ui.selecting ? t('r_select_off') : t('r_select'), function () { ui.selecting = !ui.selecting; ui.selected = []; again(); })
            ]));
            if (ui.selecting) {
                addBtn = btn('pp-btn primary full', t('r_add_n', { n: ui.selected.length }), function () {
                    var next = Object.assign({}, items);
                    ui.selected.forEach(function (id) { if (!next[id]) next[id] = C.bulkRecord(id, now); });
                    say(t('r_added_n', { n: ui.selected.length })); ui.selected = []; ui.selecting = false; write(next);
                });
                addBtn.disabled = !ui.selected.length; wrap.appendChild(addBtn);
            }
            fill(); wrap.appendChild(list);
            return wrap;
        }
        function viewBackup() {
            var text = C.exportBackup(items, now), wrap = h('div', 'tk-stack');
            wrap.appendChild(h('div', 'pp-hint', t('b_hint')));
            var ex = h('textarea', 'tk-paste'); ex.value = text; ex.readOnly = true; ex.rows = 5; wrap.appendChild(ex);
            wrap.appendChild(h('div', 'pp-sub', t('b_last', { d: data.lastExport || t('b_never') })));
            wrap.appendChild(btn('pp-btn', t('b_copy'), function () {
                var done = function () { toast(t('b_copied')); write(items, picks, now); };
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { ex.select(); document.execCommand('copy'); done(); });
                else { ex.select(); try { document.execCommand('copy'); } catch (e) { } done(); }
            }));
            var imp = h('textarea', 'tk-paste'); imp.placeholder = t('b_import_ph'); imp.rows = 5; imp.spellcheck = false; wrap.appendChild(imp);
            var go = btn('pp-btn primary', t('b_import'), function () {
                var r = C.importBackup(items, imp.value);
                if (r.error) { say(t('b_bad')); again(); return; }
                say(t('b_result', { a: r.added, u: r.updated, s: r.skipped })); write(r.items);
            });
            imp.addEventListener('input', function () { go.disabled = !imp.value.trim(); }); go.disabled = true;
            wrap.appendChild(go);
            return wrap;
        }

        var solvedCount = Object.keys(items).length;
        var lastAge = data.lastExport ? C.diffDays(now, data.lastExport) : null;
        var needBackup = solvedCount >= 5 && (lastAge === null || lastAge > 7);
        var a = h('a', 'tk-link', t('r_open')); a.href = 'https://neetcode.io/practice'; a.target = '_blank'; a.rel = 'noopener noreferrer';
        root.appendChild(h('div', 'tk-row between', [h('span', 'pp-sub', t('r_solved_of', { n: solvedCount, m: C.TOTAL })), a]));
        root.appendChild(seg([['today', t('r_tab_today')], ['solved', t('r_tab_solved')], ['todo', t('r_tab_todo')], ['backup', t('r_tab_backup')]], ui.tab, function (v) { ui.tab = v; ui.selecting = false; ui.selected = []; again(); }));
        if (needBackup && ui.tab !== 'backup') root.appendChild(btn('tk-note-bar', t('b_banner', { n: solvedCount }), function () { ui.tab = 'backup'; again(); }));
        if (ui.msg) root.appendChild(btn('tk-note-bar', ui.msg, function () { ui.msg = null; again(); }));
        root.appendChild(ui.tab === 'today' ? viewToday() : ui.tab === 'solved' ? viewSolved() : ui.tab === 'todo' ? viewTodo() : viewBackup());
    }

    /* =================================================================
       entrata
       ================================================================= */
    window.StandoutTracker = {
        open: function (host, pid) {
            if (S.host === host && S.pid === pid && S.loaded) return;
            S.host = host; S.pid = pid; S.loaded = false; S.blocks = [];
            host.textContent = '';
            host.appendChild(h('div', 'pp-hint', '…'));
            call('list').then(function (d) { S.blocks = d.blocks || []; S.max = d.max || 20; S.loaded = true; render(); }).catch(function (e) { host.textContent = ''; host.appendChild(h('div', 'pp-hint', t('save_error'))); fail(e); });
        }
    };
})();
