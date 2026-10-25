/* home_w_plan.js — i riquadri del «quando»: accesso rapido, oggi, progetti.

   - shortcuts  le pagine del sito a un tocco (le scrive PHP in un blocco JSON,
                con i nomi gia' tradotti e il pallino delle notifiche)
   - agenda     cosa fare ADESSO, le ore che seguono, e domani
   - projects   i blocchi di studio di oggi (dal piano dei progetti) e la
                prossima data importante                                     */
(function () {
    'use strict';
    var H = window.Home;
    if (!H) return;
    var el = H.el;

    /* ------------------------------------------------------------------
       accesso rapido
       ------------------------------------------------------------------ */
    H.register('shortcuts', {
        title: 'home.w_accesso', bare: true, full: true,
        build: function (w) {
            var box = el('nav', 'ho-quick');
            box.setAttribute('aria-label', T('home.w_accesso'));
            var list = [];
            try { list = JSON.parse(document.getElementById('hoShortcuts').textContent) || []; } catch (e) {}
            list.forEach(function (s) {
                var a = el('a', 'ho-qa'); a.href = H.base + s.href; a.style.setProperty('--c', s.color);
                var ic = el('span', 'ic'); ic.appendChild(H.svg(s.icon, 22, 1.9)); a.appendChild(ic);
                a.appendChild(el('span', 'nm', s.label));
                if (s.badge) a.appendChild(el('span', 'bdg', String(s.badge)));
                box.appendChild(a);
            });
            w.body.appendChild(box);
        }
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
        build: function (w) {
            w.now = el('div'); w.list = el('div', 'ho-tl'); w.tom = el('div');
            w.body.appendChild(w.now); w.body.appendChild(w.list); w.body.appendChild(w.tom);
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
        }
    });

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
        build: function (w) {
            w.date = el('div'); w.plan = el('div'); w.next = el('div');
            w.body.appendChild(w.plan); w.body.appendChild(w.date); w.body.appendChild(w.next);
            w.planned = false;
        },
        update: function (w, d, fromCache) {
            /* la prossima data importante */
            w.date.innerHTML = ''; w.next.innerHTML = '';
            var imp = d.important || [];
            if (imp.length) {
                w.date.appendChild(el('div', 'ho-cap', T('home.prossima_data')));
                w.date.appendChild(dateCard(imp[0]));
                imp.slice(1, 4).forEach(function (x) {
                    var r = el('div', 'ho-next');
                    r.appendChild(el('span', 'd', x.days === 0 ? T('home.oggi') : x.days + ' ' + T('home.g_breve')));
                    var dot = el('i', 'ho-dot'); dot.style.setProperty('--c', x.color || 'var(--text-3)'); r.appendChild(dot);
                    r.appendChild(el('span', 'n', x.title));
                    w.next.appendChild(r);
                });
            }
            /* il piano di oggi arriva da piano_api: una richiesta a parte, che
               non fa aspettare il resto della home. Offline si usa l'ultima. */
            var drawn = d._piano || null;
            if (drawn) drawPlan(w, drawn);
            if (!fromCache) {
                H.get('projects/piano_api.php', 'today').then(function (p) {
                    d._piano = p; drawPlan(w, p);
                    try { H.lsSet('ho_cache_v1', JSON.stringify(d)); } catch (e) {}
                }).catch(function () { if (!drawn) drawPlan(w, null); });
            } else if (!drawn) drawPlan(w, null);
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
