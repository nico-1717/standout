/* home_w_tasks.js — i riquadri del «cosa devo fare»: attivita' / acquisti /
   pastiglie, la nota veloce, le abitudini.

   Tutto quello che si tocca qui scrive negli endpoint di sempre
   (todo_api.php, habits.php): la home e' una vetrina comoda, i dati sono
   gli stessi della pagina To do e della pagina Abitudini.

   La nota e' un riquadro a parte e non una scheda delle attivita': si
   scrive senza decidere niente, e si smista dopo dalla pagina To do. Usa le
   STESSE chiavi di localStorage di todo_nota.js, quindi una nota scritta
   offline sulla home e' quella che trova la pagina To do, e viceversa. */
(function () {
    'use strict';
    var H = window.Home;
    if (!H) return;
    var el = H.el;
    var TODO_API = 'planner/todo_api.php';

    /* ------------------------------------------------------------------
       attivita' / acquisti / pastiglie
       ------------------------------------------------------------------ */
    var MAX_RIGHE = 6;

    function dueChip(t, today) {
        if (!t.date) return null;
        var d = H.diffDays(today, t.date);
        var c = el('span', 'ho-chip' + (d < 0 ? ' ho-chip--red' : d === 0 ? ' ho-chip--blue' : ''), H.rel(t.date) + (t.time ? ' ' + t.time : ''));
        return c;
    }

    function rowTodo(w, t, d) {
        var li = el('div', 'ho-li');
        var c = el('button', 'ho-chk'); c.type = 'button'; c.setAttribute('aria-label', T('home.segna_fatto'));
        c.appendChild(H.svg('check', 14, 3.4));
        var nm = el('div', 'nm'); nm.appendChild(document.createTextNode(t.title));
        var sub = el('span', 'sb');
        var chip = dueChip(t, d.today);
        if (chip) sub.appendChild(chip);
        if (t.project) {
            var p = el('span', null, (chip ? '  ' : '') + t.project);
            sub.appendChild(p);
        }
        if (sub.childNodes.length) nm.appendChild(sub);
        c.addEventListener('click', function () {
            c.classList.add('is-on'); li.classList.add('is-gone');
            H.api(TODO_API, 'toggle', { id: t.id }).then(function () {
                d.todo = d.todo.filter(function (x) { return x.id !== t.id; });
                d.todo_open = Math.max(0, d.todo_open - 1); d.todo_done_today++;
                setTimeout(function () { H.repaint(['tasks']); }, 280);
                H.toast(T('home.fatto'), T('home.annulla'), function () {
                    H.api(TODO_API, 'toggle', { id: t.id }).then(function () { return H.load(); }).catch(H.fail);
                });
            }).catch(function (e) { c.classList.remove('is-on'); li.classList.remove('is-gone'); H.fail(e); });
        });
        li.appendChild(c); li.appendChild(nm);
        return li;
    }

    function rowBuy(w, t, d) {
        var li = el('div', 'ho-li');
        var c = el('button', 'ho-chk'); c.type = 'button'; c.setAttribute('aria-label', T('home.segna_comprato'));
        c.style.setProperty('--c', 'var(--green)');
        c.appendChild(H.svg('check', 14, 3.4));
        var nm = el('div', 'nm'); nm.appendChild(document.createTextNode(t.title));
        if (t.cat) nm.appendChild(el('span', 'sb', t.cat));
        /* priorita' alta: un pallino rosso prima del nome */
        var urgente = null;
        if (t.priority === 1) { urgente = el('i', 'ho-dot'); urgente.style.setProperty('--c', 'var(--red)'); urgente.title = T('home.urgente'); }
        c.addEventListener('click', function () {
            c.classList.add('is-on'); li.classList.add('is-gone');
            H.api(TODO_API, 'toggle', { id: t.id }).then(function () {
                d.buy = d.buy.filter(function (x) { return x.id !== t.id; });
                d.buy_open = Math.max(0, d.buy_open - 1);
                setTimeout(function () { H.repaint(['tasks']); }, 280);
                H.toast(T('home.comprato'), T('home.annulla'), function () {
                    H.api(TODO_API, 'toggle', { id: t.id }).then(function () { return H.load(); }).catch(H.fail);
                });
            }).catch(function (e) { c.classList.remove('is-on'); li.classList.remove('is-gone'); H.fail(e); });
        });
        li.appendChild(c);
        if (urgente) li.appendChild(urgente);
        li.appendChild(nm);
        return li;
    }

    function rowPill(w, p, d, late) {
        var li = el('div', 'ho-li');
        li.appendChild(el('span', 'ho-pill-t', late ? H.rel(p.date).slice(0, 6) + ' ' + p.time : p.time));
        var dot = el('i', 'ho-dot'); dot.style.setProperty('--c', p.color || 'var(--purple)'); li.appendChild(dot);
        var nm = el('div', 'nm', p.name);
        if (p.dose) nm.appendChild(el('span', 'sb', p.dose));
        li.appendChild(nm);
        var act = el('div', 'ho-pill-a');
        function mark(status) {
            li.classList.add('is-gone');
            H.api(TODO_API, 'pill_mark', { id: p.id, status: status }).then(function () {
                p.status = status;
                setTimeout(function () { H.repaint(['tasks']); }, 280);
            }).catch(function (e) { li.classList.remove('is-gone'); H.fail(e); });
        }
        var ok = el('button', 'ho-btn ho-btn--sm', T('home.presa')); ok.type = 'button';
        ok.addEventListener('click', function () { mark('taken'); });
        var no = el('button', 'ho-btn ho-btn--sm ho-btn--grey', T('home.salta')); no.type = 'button';
        no.addEventListener('click', function () { mark('skipped'); });
        act.appendChild(no); act.appendChild(ok);
        li.appendChild(act);
        return li;
    }

    H.register('tasks', {
        title: 'home.w_attivita', icon: 'list', color: 'var(--green)', wide: true, link: 'planner/todo.php',
        build: function (w) {
            w.tab = H.lsGet('ho_tab') || 'todo';
            w.seg = el('div', 'ho-seg'); w.seg.setAttribute('role', 'tablist');
            w.list = el('div');
            w.addRow = el('form', 'ho-add');
            w.addRow.autocomplete = 'off';
            w.input = el('input', 'ho-in'); w.input.type = 'text'; w.input.maxLength = 300; w.input.enterKeyHint = 'done';
            var go = el('button', 'ho-btn'); go.type = 'submit'; go.setAttribute('aria-label', T('home.aggiungi'));
            go.appendChild(H.svg('plus', 18, 2.6));
            w.addRow.appendChild(w.input); w.addRow.appendChild(go);
            w.addRow.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var title = w.input.value.trim();
                if (!title) return;
                var type = w.tab === 'buy' ? 'buy' : 'todo';
                go.disabled = true;
                H.api(TODO_API, 'save', { title: title, list_type: type, source: 'page' }).then(function (r) {
                    go.disabled = false; w.input.value = '';
                    var t = r.todo || {};
                    var d = H.data;
                    if (type === 'buy') { d.buy.unshift({ id: t.id, title: t.title || title, priority: 3, cat: null }); d.buy_open++; }
                    else { d.todo.push({ id: t.id, title: t.title || title, date: t.date || null, time: t.time || null, project: t.project_name || null }); d.todo_open++; }
                    H.repaint(['tasks']);
                    H.toast(T('home.aggiunta'));
                    w.input.focus();
                }).catch(function (e) { go.disabled = false; H.fail(e); });
            });
            w.body.appendChild(w.seg); w.body.appendChild(w.list); w.body.appendChild(w.addRow);
            w.pillsLoaded = false;
        },
        update: function (w, d, fromCache) {
            var pills = d._pills || null;
            if (!w.pillsLoaded && !fromCache) {
                w.pillsLoaded = true;
                H.get(TODO_API, 'pills').then(function (p) {
                    d._pills = p; H.repaint(['tasks']);
                }).catch(function () {});
            }
            var dosi = [], tardi = [];
            if (pills && pills.installed !== false) {
                (pills.doses || []).forEach(function (x) { if (x.status === 'pending' || !x.status) dosi.push(x); });
                tardi = (pills.late || []).slice(0, 4);
            }
            var nPill = dosi.length + tardi.length;
            var hasPills = !!(pills && pills.installed !== false && ((pills.plans || []).length || nPill));
            if (w.tab === 'pills' && !hasPills) w.tab = 'todo';

            var tabs = [['todo', T('home.tab_todo'), d.todo_open], ['buy', T('home.tab_buy'), d.buy_open]];
            if (hasPills) tabs.push(['pills', T('home.tab_pillole'), nPill]);
            w.seg.innerHTML = '';
            tabs.forEach(function (t) {
                var b = el('button', t[0] === w.tab ? 'is-on' : '', t[1]); b.type = 'button'; b.setAttribute('role', 'tab');
                b.setAttribute('aria-selected', t[0] === w.tab ? 'true' : 'false');
                if (t[2] > 0) b.appendChild(el('span', 'n', String(t[2])));
                b.addEventListener('click', function () { w.tab = t[0]; H.lsSet('ho_tab', w.tab); H.repaint(['tasks']); });
                w.seg.appendChild(b);
            });

            w.list.innerHTML = '';
            w.addRow.style.display = w.tab === 'pills' ? 'none' : '';
            w.input.placeholder = T(w.tab === 'buy' ? 'home.aggiungi_acquisto' : 'home.aggiungi_attivita');
            if (w.tab === 'todo') {
                if (!d.todo.length) w.list.appendChild(el('div', 'ho-empty', d.todo_done_today > 0 ? T('home.tutto_fatto') : T('home.nessuna_attivita')));
                d.todo.slice(0, MAX_RIGHE).forEach(function (t) { w.list.appendChild(rowTodo(w, t, d)); });
                if (d.todo_open > MAX_RIGHE) {
                    var a = el('a', 'ho-more', T('home.altre_n', { n: d.todo_open - MAX_RIGHE })); a.href = H.base + 'planner/todo.php'; w.list.appendChild(a);
                }
            } else if (w.tab === 'buy') {
                if (!d.buy.length) w.list.appendChild(el('div', 'ho-empty', T('home.niente_da_comprare')));
                d.buy.slice(0, MAX_RIGHE).forEach(function (t) { w.list.appendChild(rowBuy(w, t, d)); });
                if (d.buy_open > MAX_RIGHE) {
                    var b2 = el('a', 'ho-more', T('home.altre_n', { n: d.buy_open - MAX_RIGHE })); b2.href = H.base + 'planner/todo.php'; w.list.appendChild(b2);
                }
            } else {
                if (!nPill) w.list.appendChild(el('div', 'ho-empty', T('home.nessuna_pillola')));
                tardi.forEach(function (p) { w.list.appendChild(rowPill(w, p, d, true)); });
                dosi.forEach(function (p) { w.list.appendChild(rowPill(w, p, d, false)); });
            }
        }
    });

    /* ------------------------------------------------------------------
       nota veloce
       ------------------------------------------------------------------ */
    var LS_TESTO = 'nisama_todo_nota', LS_SPORCA = 'nisama_todo_nota_sporca';

    H.register('note', {
        title: 'home.w_nota', icon: 'note', color: 'var(--orange)', link: 'planner/todo.php', linkLabel: 'home.smista',
        build: function (w) {
            var ta = el('textarea', 'ho-in ho-note'); ta.maxLength = 20000; ta.rows = 4;
            ta.placeholder = T('home.nota_ph'); ta.setAttribute('aria-label', T('home.w_nota'));
            ta.setAttribute('autocapitalize', 'sentences');
            var st = el('div', 'ho-note-st');
            w.ta = ta; w.st = st; w.pronta = false; w.timer = null;
            w.body.appendChild(ta); w.body.appendChild(st);

            function stato(t) { w.st.textContent = t || ''; }
            function grow() { ta.style.height = 'auto'; ta.style.height = Math.max(112, Math.min(ta.scrollHeight + 2, 300)) + 'px'; }
            w.grow = grow;
            function salva(insistente) {
                clearTimeout(w.timer);
                if (H.lsGet(LS_SPORCA) !== '1' && !insistente) return Promise.resolve();
                var testo = ta.value;
                if (insistente) {
                    /* la pagina si sta chiudendo: keepalive fa finire la richiesta */
                    try {
                        fetch(H.base + TODO_API + '?ajax=nota_salva', {
                            method: 'POST', keepalive: true, credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.STANDOUT_CSRF || '' },
                            body: JSON.stringify({ body: testo })
                        });
                    } catch (e) {}
                    return Promise.resolve();
                }
                return H.api(TODO_API, 'nota_salva', { body: testo }).then(function () {
                    if (ta.value === testo) H.lsSet(LS_SPORCA, null);
                    if (H.data) H.data.note = testo;
                    stato(T('home.nota_salvata'));
                    setTimeout(function () { if (w.st.textContent === T('home.nota_salvata')) stato(''); }, 1800);
                }).catch(function () { stato(T('home.nota_offline')); });
            }
            w.salva = salva;
            ta.addEventListener('input', function () {
                grow();
                H.lsSet(LS_TESTO, ta.value); H.lsSet(LS_SPORCA, '1');
                stato(T('home.nota_salvo'));
                clearTimeout(w.timer); w.timer = setTimeout(function () { salva(); }, 700);
            });
            ta.addEventListener('blur', function () { salva(); });
            document.addEventListener('visibilitychange', function () { if (document.hidden) salva(true); });
            window.addEventListener('pagehide', function () { salva(true); });
            /* tornata la rete, quello che era rimasto in coda parte */
            window.addEventListener('online', function () { if (H.lsGet(LS_SPORCA) === '1') salva(); });
        },
        update: function (w, d) {
            var locale = H.lsGet(LS_TESTO), sporca = H.lsGet(LS_SPORCA) === '1';
            if (document.activeElement === w.ta) return;      // non si riscrive sotto le dita
            var scelto = (sporca && locale !== null) ? locale : (d.note !== undefined ? d.note : (locale || ''));
            w.ta.value = scelto;
            H.lsSet(LS_TESTO, scelto);
            w.pronta = true;
            w.grow();
            if (sporca && scelto !== d.note) w.salva();
            else if (sporca) H.lsSet(LS_SPORCA, null);
        }
    });

    /* ------------------------------------------------------------------
       abitudini
       ------------------------------------------------------------------ */
    function ring(done, total, color) {
        var box = el('div', 'ho-ring');
        var pct = total ? done / total : 0, r = 20, c = 2 * Math.PI * r;
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 48 48');
        svg.innerHTML = '<circle cx="24" cy="24" r="' + r + '" fill="none" stroke="var(--hairline)" stroke-width="6"/>' +
            '<circle cx="24" cy="24" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="6" stroke-linecap="round" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + (c * (1 - pct)).toFixed(1) + '"/>';
        box.appendChild(svg);
        box.appendChild(el('b', null, done + '/' + total));
        return box;
    }

    H.register('habits', {
        title: 'home.w_abitudini', icon: 'heart', color: 'var(--pink)', link: 'planner/habits.php',
        build: function (w) { w.top = el('div', 'ho-hab-top'); w.chips = el('div', 'ho-hab'); w.wk = el('div'); w.body.appendChild(w.top); w.body.appendChild(w.chips); w.body.appendChild(w.wk); },
        update: function (w, d) {
            var hs = d.habits || [];
            w.top.innerHTML = ''; w.chips.innerHTML = ''; w.wk.innerHTML = '';
            if (!hs.length && !(d.habits_weekly || []).length) {
                var e = el('div', 'ho-empty', T('home.nessuna_abitudine') + ' ');
                var a = el('a', null, T('home.crea_abitudine')); a.href = H.base + 'planner/habits.php'; e.appendChild(a);
                w.chips.appendChild(e); return;
            }
            if (hs.length) {
                var done = hs.filter(function (h) { return h.done; }).length;
                w.top.appendChild(ring(done, hs.length, 'var(--pink)'));
                w.top.appendChild(el('div', 'ho-muted', done === hs.length ? T('home.abitudini_tutte') : T('home.abitudini_mancano', { n: hs.length - done })));
            }
            hs.forEach(function (h) {
                var b = el('button', 'ho-hb' + (h.done ? ' is-on' : '')); b.type = 'button';
                b.style.setProperty('--c', h.color || 'var(--pink)');
                b.setAttribute('aria-pressed', h.done ? 'true' : 'false');
                b.appendChild(el('span', 'ic', h.icon || '⭐'));
                b.appendChild(el('span', null, h.name));
                var tk = H.svg('check', 15, 3.4); tk.setAttribute('class', 'tk'); b.appendChild(tk);
                b.addEventListener('click', function () {
                    var was = h.done; h.done = !was;
                    H.api('planner/habits.php', 'log_set', { habit_id: h.id, date: d.today, mode: 'toggle', source: 'tap' }, true).then(function (r) {
                        h.done = r.status === 'done' || (r.target > 0 && r.amount >= r.target);
                        H.repaint(['habits']);
                    }).catch(function (err) { h.done = was; H.repaint(['habits']); H.fail(err); });
                    H.repaint(['habits']);
                });
                w.chips.appendChild(b);
            });
            var wk = d.habits_weekly || [];
            if (wk.length) {
                w.wk.appendChild(el('div', 'ho-cap', T('home.questa_settimana')));
                wk.slice(0, 4).forEach(function (h) {
                    var r = el('div', 'ho-wk'); r.style.marginTop = '8px';
                    r.appendChild(el('span', 'nm', (h.icon || '') + ' ' + h.name));
                    var bar = el('div', 'ho-bar'); bar.style.setProperty('--c', h.color || 'var(--pink)');
                    var i = el('i'); i.style.width = Math.min(100, Math.round(h.done / h.target * 100)) + '%'; bar.appendChild(i);
                    r.appendChild(bar);
                    r.appendChild(el('span', 'v', h.done + '/' + h.target));
                    w.wk.appendChild(r);
                });
            }
        }
    });
})();
