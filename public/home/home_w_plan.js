/* home_w_plan.js — i riquadri del «quando»: accesso rapido, oggi, progetti.

   - shortcuts  le pagine del sito a un tocco (le scrive PHP in un blocco JSON,
                con i nomi gia' tradotti e il pallino delle notifiche)
   - agenda     «Oggi», come nel Planner: cosa fare ADESSO, le ore che seguono,
                domani, la prossima data importante, le pastiglie, le attivita'
                e gli acquisti di oggi, e l'anello delle abitudini (che toccato
                apre il popup con l'elenco completo)
   - projects   i blocchi di studio di oggi (dal piano dei progetti)         */
(function () {
    'use strict';
    var H = window.Home;
    if (!H) return;
    var el = H.el;

    /* ------------------------------------------------------------------
       accesso rapido
       ------------------------------------------------------------------ */
    H.IC.left = '<path d="M15 6l-6 6 6 6"/>';
    H.IC.right = '<path d="M9 6l6 6-6 6"/>';

    /* L'ordine dei tasti sta nel layout (voce «shortcuts», campo `order`: gli
       href), cosi' viaggia sul server insieme all'ordine dei riquadri. Un tasto
       nuovo, non ancora nell'ordine salvato, va in fondo. In «Personalizza»
       ogni tasto ha due frecce per spostarlo. */
    function ordineTasti(lista) {
        var v = H.layout && H.layout.filter(function (x) { return x.id === 'shortcuts'; })[0];
        var ord = (v && v.order) || [];
        return lista.slice().sort(function (a, b) {
            var x = ord.indexOf(a.href), y = ord.indexOf(b.href);
            return (x < 0 ? 99 : x) - (y < 0 ? 99 : y);
        });
    }
    function sposta(w, href, dir) {
        var v = H.layout.filter(function (x) { return x.id === 'shortcuts'; })[0];
        if (!v) return;
        var ord = ordineTasti(w.lista).map(function (s) { return s.href; });
        var i = ord.indexOf(href), j = i + dir;
        if (i < 0 || j < 0 || j >= ord.length) return;
        var t = ord[i]; ord[i] = ord[j]; ord[j] = t;
        v.order = ord;
        H.saveLayout();
        disegnaTasti(w);
    }
    function disegnaTasti(w) {
        var ord = ordineTasti(w.lista);
        var firma = ord.map(function (s) { return s.href; }).join(',');
        if (w.firmaTasti === firma) return;       // niente da ridisegnare: non si sfarfalla
        w.firmaTasti = firma;
        w.box.innerHTML = '';
        ord.forEach(function (s, k) {
            var a = el('a', 'ho-qa'); a.href = H.base + s.href; a.style.setProperty('--c', s.color);
            var ic = el('span', 'ic'); ic.appendChild(H.svg(s.icon, 22, 1.9)); a.appendChild(ic);
            a.appendChild(el('span', 'nm', s.label));
            if (s.badge) a.appendChild(el('span', 'bdg', String(s.badge)));
            /* le frecce si vedono solo in «Personalizza» (vedi .ho-edit in dashboard.css) */
            var mv = el('span', 'ho-qmv');
            [['left', -1, T('home.sposta_sinistra')], ['right', 1, T('home.sposta_destra')]].forEach(function (m) {
                var b = el('button', null); b.type = 'button'; b.setAttribute('aria-label', m[2]); b.title = m[2];
                b.appendChild(H.svg(m[0], 14, 2.6));
                b.disabled = (m[1] < 0 && k === 0) || (m[1] > 0 && k === ord.length - 1);
                b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); sposta(w, s.href, m[1]); });
                mv.appendChild(b);
            });
            a.appendChild(mv);
            /* in «Personalizza» un tocco sul tasto non deve portare via dalla pagina */
            a.addEventListener('click', function (e) {
                var wrap = document.getElementById('hoWrap');
                if (wrap && wrap.classList.contains('ho-edit')) e.preventDefault();
            });
            w.box.appendChild(a);
        });
    }
    H.register('shortcuts', {
        title: 'home.w_accesso', bare: true, full: true,
        build: function (w) {
            w.box = el('nav', 'ho-quick');
            w.box.setAttribute('aria-label', T('home.w_accesso'));
            w.lista = [];
            try { w.lista = JSON.parse(document.getElementById('hoShortcuts').textContent) || []; } catch (e) {}
            w.body.appendChild(w.box);
            disegnaTasti(w);
        },
        /* il layout del server puo' arrivare dopo il primo disegno */
        onLayout: function (w) { disegnaTasti(w); }
    });

    /* ------------------------------------------------------------------
       oggi (agenda)
       ------------------------------------------------------------------ */
    function evRow(e, today, nowMin, opt) {
        var row = el('div', 'ho-ev');
        var allDay = e.all_day;
        var multi = e.date < today;   // evento cominciato i giorni scorsi e ancora in corso
        row.style.setProperty('--c', e.color || 'var(--blue)');
        var s = allDay || multi ? 0 : H.toMin(e.start), f = allDay ? 1440 : (e.end_date > e.date ? 1440 : H.toMin(e.end));
        var isToday = e.date === today || multi;
        if (isToday && !allDay && f <= nowMin) row.classList.add('is-past');
        if (isToday && !allDay && s <= nowMin && nowMin < f) row.classList.add('is-cur');
        if (e.done) row.classList.add('is-done');
        row.appendChild(el('span', 'tm', allDay || multi ? T('home.tutto_giorno') : e.start));
        row.appendChild(el('span', 'bar'));
        var mid = el('div');
        mid.appendChild(el('div', 'nm', e.title || T('home.senza_titolo')));
        var sub = [];
        if (e.project) sub.push(e.project);
        if (e.place) sub.push(e.place);
        if (!allDay && !multi) sub.push(e.start + '–' + e.end);
        if (sub.length) mid.appendChild(el('div', 'sb', sub.join(' · ')));
        row.appendChild(mid);
        /* si spunta solo un'attivita' vera: le occorrenze di una serie dividono
           lo stesso id del master e spuntarne una le spunterebbe tutte */
        if (e.type === 'task' && !e.occurrence && e.date === today) {
            var c = el('button', 'ho-chk' + (e.done ? ' is-on' : '')); c.type = 'button';
            c.setAttribute('aria-label', T('home.segna_fatto'));
            c.appendChild(H.svg('check', 14, 3.4));
            c.addEventListener('click', function () {
                var was = e.done; e.done = !was;
                c.classList.toggle('is-on', e.done); row.classList.toggle('is-done', e.done);
                H.api('planner/planner_api.php', 'event_toggle', { id: e.id }).then(function () {
                    H.repaint(['agenda']);
                }).catch(function (err) {
                    e.done = was; c.classList.toggle('is-on', was); row.classList.toggle('is-done', was); H.fail(err);
                });
            });
            row.appendChild(c);
        } else {
            row.appendChild(el('span'));
        }
        return row;
    }

    var pastOpen = false;
    H.register('agenda', {
        title: 'home.w_oggi', icon: 'calendar', color: 'var(--red)', wide: true, link: 'planner/planner.php',
        /* quasi tutto cio' che «Oggi» mostra arriva prima del disegno, cosi' il
           riquadro si disegna una volta sola e completo */
        needs: ['agenda', 'important', 'tasks', 'habits'],
        /* le pillole non servono al primo disegno: arrivano subito dopo (later)
           e il tastino compare quando ci sono */
        later: [{ key: '_pills', file: 'planner/todo_api.php', action: 'pills' }],
        build: function (w) {
            /* in alto: la scheda «adesso» e l'anello delle abitudini */
            var top = el('div', 'ho-today-top');
            w.now = el('div', 'ho-today-now');
            w.ring = el('button', 'ho-ring'); w.ring.type = 'button';
            w.ring.addEventListener('click', function () { if (H.ui) H.ui.openHabits(); });
            /* a destra: l'anello e, se ci sono terapie, il tastino delle pillole */
            var side = el('div', 'ho-today-side');
            w.pillBtn = el('button', 'ho-pillbtn'); w.pillBtn.type = 'button'; w.pillBtn.hidden = true;
            w.pillBtn.appendChild(H.svg('pill', 20, 2));
            w.pillBadge = el('span', 'ho-pillbtn-n'); w.pillBtn.appendChild(w.pillBadge);
            w.pillBtn.addEventListener('click', function () { openPills(w); });
            side.appendChild(w.ring); side.appendChild(w.pillBtn);
            top.appendChild(w.now); top.appendChild(side);
            w.imp = el('div');
            /* sotto: a sinistra le ore, a destra pastiglie / attivita' / acquisti */
            var cols = el('div', 'ho-today-cols');
            var left = el('div', 'ho-today-l'), right = el('div', 'ho-today-r');
            w.list = el('div', 'ho-tl'); w.tom = el('div');
            w.todo = el('div', 'ho-tsec'); w.buy = el('div', 'ho-tsec');
            left.appendChild(w.list); left.appendChild(w.tom);
            right.appendChild(w.todo); right.appendChild(w.buy);
            cols.appendChild(left); cols.appendChild(right);
            w.body.appendChild(top); w.body.appendChild(w.imp); w.body.appendChild(cols);
        },
        update: function (w, d) {
            var today = d.today, nowMin = H.nowMin();
            var tomorrow = new Date(H.day(today).getTime() + 86400000);
            var tIso = tomorrow.getFullYear() + '-' + H.pad(tomorrow.getMonth() + 1) + '-' + H.pad(tomorrow.getDate());
            var evs = d.agenda.filter(function (e) { return e.date <= today && e.end_date >= today; });
            var tom = d.agenda.filter(function (e) { return e.date === tIso; });
            function startOf(e) { return e.all_day || e.date < today ? -1 : H.toMin(e.start); }
            function endOf(e) { return e.all_day ? 1440 : (e.end_date > today ? 1440 : H.toMin(e.end)); }
            evs.sort(function (a, b) { return startOf(a) - startOf(b); });

            /* la scheda «adesso»: l'evento in corso, o il prossimo con quanto manca */
            var cur = null, nxt = null;
            evs.forEach(function (e) {
                if (e.all_day || e.done) return;
                var s = startOf(e), f = endOf(e);
                if (s <= nowMin && nowMin < f && !cur) cur = e;
                else if (s > nowMin && !nxt) nxt = e;
            });
            w.now.innerHTML = '';
            var card = el('div', 'ho-now' + (cur ? '' : ' ho-now--free'));
            var k = el('div', 'k'); if (cur) k.appendChild(el('i'));
            k.appendChild(document.createTextNode(cur ? T('home.adesso') : T('home.libero')));
            card.appendChild(k);
            if (cur) {
                card.appendChild(el('div', 't', cur.title));
                card.appendChild(el('div', 'm', T('home.fino_alle', { ora: cur.end_date > today ? '24:00' : cur.end }) + (nxt ? ' · ' + T('home.poi', { titolo: nxt.title, ora: nxt.start }) : '')));
            } else if (nxt) {
                var tra = H.toMin(nxt.start) - nowMin;
                card.appendChild(el('div', 't', nxt.title));
                card.appendChild(el('div', 'm', T('home.alle_tra', { ora: nxt.start, tra: H.mins(tra) })));
            } else {
                card.appendChild(el('div', 't', T('home.niente_altro')));
                card.appendChild(el('div', 'm', T('home.resto_giornata_libero')));
            }
            w.now.appendChild(card);

            /* le ore: prima quello che e' gia' passato (chiuso, si apre con un tocco), poi il resto */
            w.list.innerHTML = '';
            var passati = evs.filter(function (e) { return !e.all_day && endOf(e) <= nowMin; });
            var restanti = evs.filter(function (e) { return passati.indexOf(e) < 0; });
            if (passati.length) {
                var bt = el('button', 'ho-more', pastOpen ? T('home.nascondi_passati') : T('home.passati_n', { n: passati.length }));
                bt.type = 'button';
                bt.addEventListener('click', function () { pastOpen = !pastOpen; H.repaint(['agenda']); });
                w.list.appendChild(bt);
                if (pastOpen) passati.forEach(function (e) { w.list.appendChild(evRow(e, today, nowMin)); });
            }
            restanti.slice(0, 8).forEach(function (e) { w.list.appendChild(evRow(e, today, nowMin)); });
            if (restanti.length > 8) {
                var more = el('a', 'ho-more', T('home.altri_n', { n: restanti.length - 8 })); more.href = H.base + 'planner/planner.php';
                w.list.appendChild(more);
            }
            if (!evs.length) w.list.appendChild(el('div', 'ho-empty', T('home.nessun_evento_oggi')));

            w.tom.innerHTML = '';
            if (tom.length) {
                w.tom.appendChild(el('div', 'ho-cap ho-day', T('home.domani')));
                var tl = el('div', 'ho-tl');
                tom.slice(0, 4).forEach(function (e) { tl.appendChild(evRow(e, today, nowMin)); });
                w.tom.appendChild(tl);
            }

            /* il resto di «Oggi». H.ui lo mette home_w_tasks.js (caricato dopo
               questo file, ma prima del primo disegno). */
            if (!H.ui) return;
            drawRing(w, d);
            drawImportant(w, d);
            drawPills(w, d);
            drawTodo(w, d);
            drawBuy(w, d);
        }
    });

    /* ------------------------------------------------------------------
       pezzi di «Oggi»
       ------------------------------------------------------------------ */
    /* L'anello delle abitudini: cerchi concentrici come nel Planner, uno per
       ritmo e solo per i ritmi che l'utente ha davvero (giornaliere,
       settimanali, mensili, ogni N giorni; il piu' interno e' il primo). Il
       conto e' quello del Planner (pl_rings): giornaliere e a intervallo
       «fatte su previste», settimanali e mensili «volte fatte su volte
       volute», senza gonfiare oltre l'obiettivo. Il disegno si ricrea solo se
       cambia l'insieme dei ritmi; per il resto si aggiorna il riempimento,
       cosi' il ridisegno di ogni minuto non lo fa ripartire da zero. */
    var RING_ORDINE = ['daily', 'weekly', 'monthly', 'interval'];
    var RING_LARG = 3.4, RING_STACCO = 0.9, RING_EXT = 21;
    function anelliAbitudini(d) {
        var out = [];
        RING_ORDINE.forEach(function (k) {
            var g = (d.habit_groups || []).filter(function (x) { return x.key === k; })[0];
            if (!g || !g.items.length) return;
            var weekly = k === 'weekly' || k === 'monthly', fatte = 0, tot = 0;
            g.items.forEach(function (h) {
                if (weekly) { tot += h.target; fatte += Math.min(h.n, h.target); }
                else { tot++; if (h.done) fatte++; }
            });
            out.push({ key: k, label: T(GRUPPI_LAB[k]), done: fatte, total: tot });
        });
        return out;
    }
    var GRUPPI_LAB = { daily: 'home.hab_giornaliere', weekly: 'home.hab_settimanali', monthly: 'home.hab_mensili', interval: 'home.hab_intervallo' };
    function drawRing(w, d) {
        var btn = w.ring, rings = anelliAbitudini(d);
        var sig = rings.map(function (r) { return r.key; }).join(',');
        if (!btn.firstChild || btn.getAttribute('data-sig') !== sig) {
            btn.innerHTML = '';
            /* rotate(-90) SOLO sul disegno degli anelli (`.ho-ring-svg`): cosi' partono
               da ore 12 e l'icona al centro resta dritta */
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 46 46'); svg.setAttribute('class', 'ho-ring-svg');
            var n = Math.max(1, rings.length), pezzi = '';
            for (var i = 0; i < n; i++) {
                var r = RING_EXT - (n - 1 - i) * (RING_LARG + RING_STACCO);
                if (r < 3) break;
                var C = 2 * Math.PI * r;
                pezzi += '<circle class="ho-ring-track" cx="23" cy="23" r="' + r.toFixed(2) + '" stroke-width="' + RING_LARG + '"/>' +
                    (rings[i] ? '<circle class="ho-ring-fill ho-ring-' + rings[i].key + '" cx="23" cy="23" r="' + r.toFixed(2) +
                        '" stroke-width="' + RING_LARG + '" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + C.toFixed(1) +
                        '" data-c="' + C.toFixed(1) + '"/>' : '');
            }
            svg.innerHTML = pezzi;
            btn.appendChild(svg);
            w.ringTx = el('span', 'ho-ring-face'); w.ringTx.appendChild(H.svg('check', 16, 2.4));
            btn.appendChild(w.ringTx);
            btn.setAttribute('data-sig', sig);
        }
        Array.prototype.forEach.call(btn.querySelectorAll('.ho-ring-fill'), function (c, i) {
            var rg = rings[i]; if (!rg) return;
            var C = parseFloat(c.getAttribute('data-c'));
            var frac = rg.total > 0 ? Math.min(1, rg.done / rg.total) : 0;
            requestAnimationFrame(function () { c.style.strokeDashoffset = (C * (1 - frac)).toFixed(1); });
        });
        btn.classList.toggle('is-full', rings.length > 0 && rings.every(function (r) { return r.total > 0 && r.done >= r.total; }));
        var descr = rings.map(function (r) { return r.label + ' ' + r.done + '/' + r.total; }).join(', ');
        btn.title = T('home.w_abitudini');
        btn.setAttribute('aria-label', T('home.w_abitudini') + (descr ? ': ' + descr : ''));
    }

    /* la prossima data importante, in piccolo */
    function drawImportant(w, d) {
        w.imp.innerHTML = '';
        var imp = d.important || [];
        if (!imp.length) return;
        var x = imp[0];
        var card = dateCard(x);
        card.classList.add('ho-date-card--sm');
        var cap = el('div', 'ho-cap', T('home.prossima_data'));
        w.imp.appendChild(cap); w.imp.appendChild(card);
    }

    /* Il tastino «Apri …»: una pillola col colore della sezione e una freccia
       dentro un cerchietto. Sta sulla riga del titolo (risparmia una riga
       intera), oppure da solo in fondo a un popup. */
    function openBtn(href, testo, colore) {
        var a = el('a', 'ho-sec-open'); a.href = H.base + href;
        if (colore) a.style.setProperty('--c', colore);
        a.appendChild(el('span', 'tx', testo));
        var ar = el('span', 'ar'); ar.appendChild(H.svg('right', 12, 2.8)); a.appendChild(ar);
        return a;
    }
    /* intestazione di una sezione: titolo e conto a sinistra, «Apri» a destra */
    function secHead(box, titolo, n, href, testo, colore) {
        var h = el('div', 'ho-hg-h');
        var sx = el('span', 'ho-sec-t');
        sx.appendChild(el('span', 'ho-cap', titolo));
        if (n > 0) sx.appendChild(el('span', 'ho-chip', String(n)));
        h.appendChild(sx);
        if (href) h.appendChild(openBtn(href, testo, colore));
        box.appendChild(h);
    }

    /* Le pillole sono un popup, e solo quelle IN RITARDO: arretrate dei giorni
       scorsi e quelle di oggi la cui ora e' gia' passata. Quelle della sera,
       la mattina, non si vedono. La pagina completa si apre dal popup. */
    function pillsInRitardo(d) {
        var pills = d._pills;
        if (!pills || pills.installed === false) return { has: false, list: [] };
        function pend(x) { return x.status === 'pending' || !x.status; }
        var tardi = (pills.late || []).filter(pend).map(function (p) { return { p: p, late: true }; });
        var ora = (pills.doses || []).filter(function (x) { return pend(x) && H.toMin(x.time) <= H.nowMin(); })
                                     .map(function (p) { return { p: p, late: false }; });
        return { has: !!((pills.plans || []).length || tardi.length || ora.length), list: tardi.concat(ora) };
    }
    var pillsSheet = null;
    /* Una riga come l'avviso delle pastiglie nel Planner: una pillola con un
       filo del colore della terapia, la spunta tonda, la domanda con il nome in
       grassetto e «Saltata» sottolineato. La spunta scrive nel registro delle
       prese, quindi risulta presa ovunque. */
    function avvisoPill(w, p, late) {
        var riga = el('div', 'ho-av');
        riga.style.setProperty('--ev', p.color || 'var(--purple)');
        function rispondi(stato) {
            riga.classList.add('ho-av-via');
            H.api('planner/todo_api.php', 'pill_mark', { id: p.id, status: stato }).then(function () {
                p.status = stato;
                setTimeout(function () { H.repaint(['agenda']); }, 260);
            }).catch(function (e) { riga.classList.remove('ho-av-via'); H.fail(e); });
        }
        var ok = el('button', 'ho-av-ok'); ok.type = 'button'; ok.setAttribute('aria-label', T('home.presa'));
        ok.appendChild(H.svg('check', 13, 3));
        ok.addEventListener('click', function () { rispondi('taken'); });
        riga.appendChild(ok);
        var quando = late && p.date
            ? T('home.hp_giorno_alle', { giorno: H.fmtDate(p.date, { weekday: 'short' }).toLowerCase(), ora: p.time })
            : T('home.hp_alle', { ora: p.time });
        var t = el('span', 'ho-av-t');
        t.appendChild(document.createTextNode(p.dose ? T('home.hp_hai_preso_dose', { dose: p.dose }) : T('home.hp_hai_preso')));
        t.appendChild(el('b', null, p.name));
        t.appendChild(document.createTextNode(' ' + quando + '?'));
        riga.appendChild(t);
        var sk = el('button', 'ho-av-salta', T('home.hp_saltato')); sk.type = 'button';
        sk.addEventListener('click', function () { rispondi('skipped'); });
        riga.appendChild(sk);
        return riga;
    }
    function fillPills(body, w, d) {
        body.innerHTML = '';
        var due = pillsInRitardo(d).list;
        var list = el('div', 'ho-avs');
        if (!due.length) list.appendChild(el('div', 'ho-empty', T('home.nessuna_pillola')));
        due.forEach(function (x) { list.appendChild(avvisoPill(w, x.p, x.late)); });
        body.appendChild(list);
        var piede = el('div', 'ho-av-foot');
        piede.appendChild(openBtn('planner/todo.php?tab=pills', T('home.apri_pillole'), 'var(--purple)'));
        body.appendChild(piede);
    }
    function openPills(w) {
        if (pillsSheet) return;
        pillsSheet = H.sheet(T('home.tab_pillole'), function (sh) { fillPills(sh.body, w, H.data); }, function () { pillsSheet = null; });
        pillsSheet.w = w;
    }
    function drawPills(w, d) {
        var r = pillsInRitardo(d);
        w.pillBtn.hidden = !r.has;
        w.pillBadge.textContent = r.list.length ? String(r.list.length) : '';
        w.pillBadge.hidden = !r.list.length;
        w.pillBtn.classList.toggle('is-late', r.list.length > 0);
        var lab = T('home.tab_pillole') + (r.list.length ? ': ' + r.list.length : '');
        w.pillBtn.title = lab; w.pillBtn.setAttribute('aria-label', lab);
        if (pillsSheet) fillPills(pillsSheet.body, w, d);
    }

    function drawTodo(w, d) {
        var box = w.todo; box.innerHTML = '';
        /* «di oggi»: quelle con scadenza oggi o gia' passata; le altre restano
           nella pagina To do (e il conto lo dice) */
        var oggi = (d.todo || []).filter(function (t) { return t.date && t.date <= d.today; });
        secHead(box, T('home.tab_todo'), oggi.length, 'planner/todo.php', T('home.apri_todo'), 'var(--green)');
        var list = el('div');
        if (!oggi.length) list.appendChild(el('div', 'ho-empty', d.todo_done_today > 0 ? T('home.tutto_fatto') : T('home.nessuna_attivita_oggi')));
        oggi.slice(0, 5).forEach(function (t) { list.appendChild(H.ui.rowTodo(w, t, d)); });
        box.appendChild(list);
    }

    function drawBuy(w, d) {
        var box = w.buy; box.innerHTML = '';
        var buy = d.buy || [];
        /* Pochi, di proposito: prima quelli con la data gia' arrivata (o passata);
           se non ce ne sono, quelli con la priorita' piu' alta; se nemmeno
           quelli, solo il primo della lista. La lista intera sta nella pagina. */
        var scaduti = buy.filter(function (t) { return t.date && t.date <= d.today; });
        var urgenti = buy.filter(function (t) { return t.priority === 1; });
        var scelti = scaduti.length ? scaduti : (urgenti.length ? urgenti : buy.slice(0, 1));
        secHead(box, T('home.tab_buy'), d.buy_open, 'planner/todo.php?tab=buy', T('home.apri_acquisti'), 'var(--orange)');
        var list = el('div');
        if (!buy.length) list.appendChild(el('div', 'ho-empty', T('home.niente_da_comprare')));
        scelti.slice(0, 4).forEach(function (t) { list.appendChild(H.ui.rowBuy(w, t, d)); });
        box.appendChild(list);
    }

    /* ------------------------------------------------------------------
       progetti di oggi + prossima data importante
       ------------------------------------------------------------------ */
    function dateCard(x) {
        var card = el('div', 'ho-date-card');
        card.style.setProperty('--c', x.color || 'var(--red)');
        var big = el('div', 'big');
        big.appendChild(el('b', null, String(x.days)));
        big.appendChild(el('span', null, T(x.days === 1 ? 'home.giorno' : 'home.giorni')));
        card.appendChild(big);
        var tx = el('div', 'tx');
        tx.appendChild(el('b', null, x.title));
        tx.appendChild(el('span', null, H.fmtDate(x.date) + (x.time ? ' · ' + x.time : '')));
        card.appendChild(tx);
        return card;
    }

    H.register('projects', {
        title: 'home.w_progetti', icon: 'folder', color: 'var(--indigo)', link: 'projects/progetti.php',
        /* il piano di oggi sta in un altro endpoint: lo carica il motore insieme
           al resto (extra), cosi' il riquadro si disegna UNA volta sola, completo */
        needs: ['important'],
        extra: [{ key: '_piano', file: 'projects/piano_api.php', action: 'today' }],
        build: function (w) {
            w.date = el('div'); w.plan = el('div'); w.next = el('div');
            w.body.appendChild(w.plan); w.body.appendChild(w.date); w.body.appendChild(w.next);
            w.planned = false;
        },
        update: function (w, d, fromCache) {
            /* la prossima data importante ora sta in «Oggi»; qui restano le
               successive, in elenco corto */
            w.date.innerHTML = ''; w.next.innerHTML = '';
            var imp = (d.important || []).slice(1);
            if (imp.length) {
                w.date.appendChild(el('div', 'ho-cap', T('home.date_successive')));
                imp.slice(0, 3).forEach(function (x) {
                    var r = el('div', 'ho-next');
                    r.appendChild(el('span', 'd', x.days === 0 ? T('home.oggi') : x.days + ' ' + T('home.g_breve')));
                    var dot = el('i', 'ho-dot'); dot.style.setProperty('--c', x.color || 'var(--text-3)'); r.appendChild(dot);
                    r.appendChild(el('span', 'n', x.title));
                    w.next.appendChild(r);
                });
            }
            /* il piano di oggi arriva dal motore (extra _piano); se non c'e'
               (richiesta fallita e nessuna copia) il riquadro lo dice. */
            drawPlan(w, d._piano || null);
        }
    });

    function drawPlan(w, p) {
        w.plan.innerHTML = '';
        if (!p) { w.plan.appendChild(el('div', 'ho-empty', T('home.piano_non_disponibile'))); return; }
        var blocks = (p.blocks || []).filter(function (b) { return b.kind === 'study'; });
        var rip = (p.blocks || []).filter(function (b) { return b.kind !== 'study'; });
        w.plan.appendChild(el('div', 'ho-cap', T('home.da_fare_oggi')));
        if (!blocks.length && !rip.length) {
            var e = el('div', 'ho-empty', T('home.nessun_blocco') + ' ');
            var a = el('a', null, T('home.pianifica')); a.href = H.base + 'projects/progetti.php?view=plan';
            e.appendChild(a); w.plan.appendChild(e);
        }
        (blocks.concat(rip)).slice(0, 5).forEach(function (b) {
            var row = el('div', 'ho-pj'); row.style.setProperty('--c', b.root_color || b.color || 'var(--indigo)');
            var top = el('div', 'ho-pj-top');
            var dot = el('i', 'ho-dot'); dot.style.setProperty('--c', b.root_color || b.color || 'var(--indigo)'); top.appendChild(dot);
            var nm = el('span', 'ho-pj-nm', b.name + (b.kind !== 'study' ? ' · ' + T('home.ripasso') : '')); top.appendChild(nm);
            top.appendChild(el('span', 'ho-pj-min', Math.min(b.done, b.minutes) + '/' + b.minutes + ' min'));
            var go = el('a', 'ho-btn ho-btn--soft ho-btn--sm'); go.href = H.base + 'projects/pomodoro.php?project_id=' + b.project;
            go.setAttribute('aria-label', T('home.avvia_pomodoro', { nome: b.name }));
            go.appendChild(H.svg('play', 13, 2.4));
            top.appendChild(go);
            row.appendChild(top);
            var bar = el('div', 'ho-bar'); bar.style.setProperty('--c', b.root_color || b.color || 'var(--indigo)');
            var i = el('i'); i.style.width = Math.min(100, b.minutes ? Math.round(b.done / b.minutes * 100) : 0) + '%'; bar.appendChild(i);
            row.appendChild(bar);
            w.plan.appendChild(row);
        });
        if (p.skipped && p.skipped.length) {
            var s = p.skipped[0];
            var warn = el('a', 'ho-warn'); warn.href = H.base + 'projects/progetti.php?id=' + s.project;
            warn.appendChild(H.svg('flag', 16, 2.2));
            warn.appendChild(el('span', null, T('home.saltato_ieri', { min: s.missing, nome: s.name })));
            w.plan.appendChild(warn);
        }
        if (p.week && p.week.planned > 0) {
            var wk = el('div', 'ho-muted', T('home.settimana_ore', { fatto: H.mins(p.week.done), piano: H.mins(p.week.planned) }));
            w.plan.appendChild(wk);
        }
    }
})();
