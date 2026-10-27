/* home_w_tasks.js — il «cosa devo fare»: i pezzi di disegno di attivita' /
   acquisti / pastiglie / abitudini (li usa il riquadro Oggi, in home_w_plan.js,
   tramite Home.ui) e il riquadro della nota veloce.

   Attivita', acquisti e pastiglie non sono piu' un riquadro a parte: stanno
   dentro «Oggi», come nel Planner. Le abitudini sono un anello dentro «Oggi»
   che, toccato, apre un popup con l'elenco completo (drawHabits).

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
                setTimeout(function () { H.repaint(['agenda']); }, 280);
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
                setTimeout(function () { H.repaint(['agenda']); }, 280);
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
                setTimeout(function () { H.repaint(['agenda']); }, 280);
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

    /* ------------------------------------------------------------------
       nota veloce
       ------------------------------------------------------------------ */
    var LS_TESTO = 'nisama_todo_nota', LS_SPORCA = 'nisama_todo_nota_sporca';

    H.register('note', {
        title: 'home.w_nota', icon: 'note', color: 'var(--orange)', link: 'planner/todo.php', linkLabel: 'home.smista',
        needs: ['note'],
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
       Carte come quelle del selettore dei giorni nella pagina Abitudini:
       l'icona sopra dentro un anello di avanzamento, il nome sotto. Divise
       per ritmo (giornaliere, settimanali, mensili, ogni N giorni) da
       un'etichetta con quante ne sono completate.
       ------------------------------------------------------------------ */
    var GRUPPI = { daily: 'home.hab_giornaliere', weekly: 'home.hab_settimanali', monthly: 'home.hab_mensili', interval: 'home.hab_intervallo' };
    var R = 19, C = 2 * Math.PI * R;

    function habitCard(h, key, d, w) {
        var weekly = key === 'weekly' || key === 'monthly';
        var complete = weekly ? h.n >= h.target : h.done;
        var frac = weekly ? Math.min(1, h.n / h.target) : (h.done ? 1 : (h.part || 0));
        var b = el('button', 'ho-hc' + (complete ? ' is-on' : '')); b.type = 'button';
        b.style.setProperty('--c', h.color || 'var(--pink)');
        b.setAttribute('aria-pressed', h.done ? 'true' : 'false');
        var ring = el('span', 'ho-hc-ring');
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 44 44');
        svg.innerHTML = '<circle cx="22" cy="22" r="' + R + '" fill="none" stroke="var(--hairline)" stroke-width="3"/>' +
            '<circle cx="22" cy="22" r="' + R + '" fill="none" stroke="var(--c)" stroke-width="3" stroke-linecap="round" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + (C * (1 - frac)).toFixed(1) + '"/>';
        ring.appendChild(svg);
        ring.appendChild(el('span', 'ic', h.icon || '⭐'));
        if (h.done) { var tk = el('span', 'tk'); tk.appendChild(H.svg('check', 10, 4)); ring.appendChild(tk); }
        b.appendChild(ring);
        b.appendChild(el('span', 'nm', h.name));
        if (weekly) b.appendChild(el('span', 'cp', h.n + '/' + h.target));
        b.addEventListener('click', function () {
            /* subito sullo schermo; se il server dice no, si torna com'era */
            var prev = { done: h.done, n: h.n, part: h.part };
            h.done = !h.done;
            if (weekly) h.n = Math.max(0, h.n + (h.done ? 1 : -1));
            h.part = h.done ? 1 : 0;
            repaintHabits();
            H.api('planner/habits.php', 'log_set', { habit_id: h.id, date: d.today, mode: 'toggle', source: 'tap' }, true).then(function (r) {
                var ok = r.status === 'done' || (r.target > 0 && r.amount >= r.target);
                if (ok !== h.done) {   // abitudine a quantita': la spunta puo' non bastare
                    h.done = ok; h.part = ok ? 1 : 0;
                    if (weekly) h.n = Math.max(0, prev.n + (ok ? 1 : 0));
                    repaintHabits();
                }
            }).catch(function (err) { h.done = prev.done; h.n = prev.n; h.part = prev.part; repaintHabits(); H.fail(err); });
        });
        return b;
    }

    /* L'elenco completo delle abitudini, disegnato dentro `box` (il corpo del
       popup). Era il riquadro «Abitudini» della home. */
    function drawHabits(box, d) {
        var gs = d.habit_groups || [];
        box.innerHTML = '';
        var wrap = el('div', 'ho-hgs');
        box.appendChild(wrap);
        if (!gs.length) {
            var e = el('div', 'ho-empty', T('home.nessuna_abitudine') + ' ');
            var a = el('a', null, T('home.crea_abitudine')); a.href = H.base + 'planner/habits.php'; e.appendChild(a);
            wrap.appendChild(e); return;
        }
        gs.forEach(function (g) {
            var weekly = g.key === 'weekly' || g.key === 'monthly';
            var fatte = g.items.filter(function (h) { return weekly ? h.n >= h.target : h.done; }).length;
            var sec = el('div', 'ho-hg');
            var head = el('div', 'ho-hg-h');
            head.appendChild(el('span', 'ho-cap', T(GRUPPI[g.key])));
            head.appendChild(el('span', 'ho-chip' + (fatte === g.items.length ? ' ho-chip--green' : ''), fatte + '/' + g.items.length));
            sec.appendChild(head);
            var grid = el('div', 'ho-hgrid');
            g.items.forEach(function (h) { grid.appendChild(habitCard(h, g.key, d, null)); });
            sec.appendChild(grid);
            wrap.appendChild(sec);
        });
        var all = el('a', 'ho-more', T('home.apri')); all.href = H.base + 'planner/habits.php';
        box.appendChild(all);
    }

    var habitsSheet = null;
    function openHabits() {
        if (habitsSheet) return;
        habitsSheet = H.sheet(T('home.w_abitudini'), function (sh) { drawHabits(sh.body, H.data); }, function () { habitsSheet = null; });
    }
    /* Dopo una spunta: l'anello in «Oggi» e, se e' aperto, il popup. */
    function repaintHabits() {
        H.repaint(['agenda']);
        if (habitsSheet && H.data) drawHabits(habitsSheet.body, H.data);
    }

    /* Cio' che il riquadro Oggi (home_w_plan.js) va a prendere qui. Lo legge
       solo quando disegna, cioe' dopo che tutti i file sono stati caricati. */
    H.ui = { rowTodo: rowTodo, rowBuy: rowBuy, rowPill: rowPill, openHabits: openHabits, TODO_API: TODO_API };
})();
