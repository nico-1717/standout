/* home_w_study.js — i riquadri dello studio: flashcard, curiosita', voti, numeri.

   FLASHCARD
   - «carta veloce»: scrivi solo il fronte e vai avanti con la vita; la bozza
     resta in «Da completare» finche' non hai tempo di scrivere il retro e
     scegliere il mazzo. Se non c'e' rete la bozza aspetta in localStorage e
     parte appena torna (e' proprio il momento in cui non hai tempo).
   - «Inserisci»: lo stesso popup della barra laterale (Universita' o
     Vocabolario, poi la lingua) e porta alla pagina delle carte.
   - la partita lasciata a meta' e il mazzo che conviene giocare adesso.   */
(function () {
    'use strict';
    var H = window.Home;
    if (!H) return;
    var el = H.el;
    var API = 'home/home_api.php';

    /* ------------------------------------------------------------------
       coda delle bozze create senza rete
       ------------------------------------------------------------------ */
    var LS_Q = 'ho_draft_q';
    function qGet() { try { return JSON.parse(H.lsGet(LS_Q) || '[]') || []; } catch (e) { return []; } }
    function qSet(q) { H.lsSet(LS_Q, q.length ? JSON.stringify(q) : null); }
    var flushing = false;
    function flush() {
        var q = qGet();
        if (!q.length || flushing) return;
        flushing = true;
        H.api(API, 'draft_add', { front: q[0].front }).then(function () {
            q.shift(); qSet(q); flushing = false;
            /* a coda finita si rilegge tutto: la bozza ora ha il suo id vero */
            if (q.length) flush(); else H.load();
        }).catch(function (e) {
            flushing = false;
            /* un rifiuto del server (testo vuoto, troppe bozze) non passa con i
               tentativi: si scarta, altrimenti la coda resterebbe bloccata */
            if (e && e.server) { q.shift(); qSet(q); }
        });
    }
    window.addEventListener('online', flush);

    /* ------------------------------------------------------------------
       popup «Inserisci» (come nella barra laterale)
       ------------------------------------------------------------------ */
    function opt(color, iconName, label, sub) {
        var b = el('button', 'ho-opt'); b.type = 'button'; b.style.setProperty('--c', color);
        var ic = el('span', 'ic'); ic.appendChild(H.svg(iconName, 18, 2)); b.appendChild(ic);
        var t = el('span'); t.appendChild(document.createTextNode(label));
        if (sub) t.appendChild(el('small', null, sub));
        b.appendChild(t);
        return b;
    }
    function torna() { return '&torna=' + encodeURIComponent(location.pathname + location.search); }
    function apriInserisci() {
        H.sheet(T('sidebar.inserisci'), function (s) {
            function vai(url) { s.close(); location.href = url; }
            function stepTipo() {
                s.body.innerHTML = '';
                s.body.appendChild(el('div', 'ho-cap', T('sidebar.ins_dove')));
                var uni = opt('#0A84FF', 'cap', T('sidebar.ins_uni'));
                uni.addEventListener('click', function () { vai(H.base + 'fc/fc_view.php?inserisci=1' + torna()); });
                var voc = opt('#BF5AF2', 'globe', T('sidebar.ins_lingue'));
                voc.addEventListener('click', function () {
                    voc.disabled = true;
                    fetch(H.base + 'fc/fc_corsi_lista.php', { credentials: 'same-origin', cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            voc.disabled = false;
                            var corsi = (d && d.corsi) || [];
                            if (corsi.length === 1) { vai(H.base + 'fc/fc_view.php?course=' + encodeURIComponent(corsi[0].id) + '&inserisci=1' + torna()); return; }
                            if (!corsi.length) {
                                s.body.innerHTML = '';
                                var m = el('div', 'ho-empty'); var a = el('a', null, T('sidebar.ins_nessuna')); a.href = H.base + 'fc/fc_home.php'; m.appendChild(a);
                                s.body.appendChild(m); return;
                            }
                            s.body.innerHTML = '';
                            s.body.appendChild(el('div', 'ho-cap', T('sidebar.ins_quale')));
                            corsi.forEach(function (c) {
                                var b = el('button', 'ho-opt'); b.type = 'button';
                                var img = el('img'); img.src = c.bandiera; img.alt = ''; b.appendChild(img);
                                var t = el('span'); t.appendChild(document.createTextNode(c.nome)); t.appendChild(el('small', null, c.da)); b.appendChild(t);
                                b.addEventListener('click', function () { vai(H.base + 'fc/fc_view.php?course=' + encodeURIComponent(c.id) + '&inserisci=1' + torna()); });
                                s.body.appendChild(b);
                            });
                            var back = el('button', 'ho-btn ho-btn--grey ho-btn--sm', T('sidebar.ins_indietro')); back.type = 'button';
                            back.addEventListener('click', stepTipo); s.body.appendChild(back);
                        })
                        .catch(function () { voc.disabled = false; vai(H.base + 'fc/fc_home.php'); });
                });
                s.body.appendChild(uni); s.body.appendChild(voc);
            }
            stepTipo();
        });
    }

    /* ------------------------------------------------------------------
       completare una bozza
       ------------------------------------------------------------------ */
    function completa(bozza, d) {
        H.sheet(T('home.completa_carta'), function (s) {
            var fc = d.fc;
            var f1 = el('div', 'ho-field'); f1.appendChild(el('label', 'ho-cap', T('home.fronte')));
            var front = el('input', 'ho-in'); front.type = 'text'; front.maxLength = 500; front.value = bozza.front; f1.appendChild(front);
            var f2 = el('div', 'ho-field'); f2.appendChild(el('label', 'ho-cap', T('home.retro')));
            var back = el('textarea', 'ho-in'); back.rows = 3; back.maxLength = 2000; back.placeholder = T('home.retro_ph'); back.value = bozza.back || '';
            f2.appendChild(back);
            var f3 = el('div', 'ho-field'); f3.appendChild(el('label', 'ho-cap', T('home.mazzo')));
            var sel = el('select', 'ho-in');
            var last = bozza.deck_id || parseInt(H.lsGet('ho_last_deck') || '0', 10);
            (fc.decks || []).forEach(function (m) {
                var o = el('option', null, m.path); o.value = m.id; if (m.id === last) o.selected = true; sel.appendChild(o);
            });
            f3.appendChild(sel);
            s.body.appendChild(f1); s.body.appendChild(f2);
            if (!(fc.decks || []).length) {
                var e = el('div', 'ho-empty'); var a = el('a', null, T('home.crea_mazzo')); a.href = H.base + 'fc/fc_decks.php'; e.appendChild(a);
                s.body.appendChild(e);
            } else s.body.appendChild(f3);
            var foot = el('div', 'ho-sh-foot');
            var del = el('button', 'ho-btn ho-btn--red', T('home.elimina')); del.type = 'button';
            var ok = el('button', 'ho-btn', T('home.salva_carta')); ok.type = 'button';
            del.addEventListener('click', function () {
                del.disabled = true;
                H.api(API, 'draft_delete', { id: bozza.id }).then(function () {
                    fc.drafts = fc.drafts.filter(function (x) { return x.id !== bozza.id; });
                    fc.draft_count = Math.max(0, fc.draft_count - 1);
                    s.close(); H.repaint(['cards']);
                }).catch(function (e2) { del.disabled = false; H.fail(e2); });
            });
            ok.addEventListener('click', function () {
                if (!back.value.trim()) { back.focus(); H.toast(T('home.scrivi_retro')); return; }
                ok.disabled = true;
                H.api(API, 'draft_finish', { id: bozza.id, front: front.value, back: back.value, deck_id: parseInt(sel.value || '0', 10) }).then(function () {
                    H.lsSet('ho_last_deck', sel.value);
                    fc.drafts = fc.drafts.filter(function (x) { return x.id !== bozza.id; });
                    fc.draft_count = Math.max(0, fc.draft_count - 1);
                    s.close(); H.repaint(['cards']); H.toast(T('home.carta_salvata'));
                }).catch(function (e2) { ok.disabled = false; H.fail(e2); });
            });
            foot.appendChild(del); foot.appendChild(ok); s.body.appendChild(foot);
            setTimeout(function () { back.focus(); }, 280);
        });
    }

    function draftRow(b, d, onDone) {
        var r = el('div', 'ho-draft');
        var q = el('div', 'q'); q.appendChild(document.createTextNode(b.front));
        if (b.pending) q.appendChild(el('small', null, T('home.in_attesa_rete')));
        r.appendChild(q);
        if (!b.pending) {
            var go = el('button', 'ho-btn ho-btn--soft ho-btn--sm', T('home.completa')); go.type = 'button';
            go.addEventListener('click', function () { if (onDone) onDone(); completa(b, d); });
            r.appendChild(go);
        }
        return r;
    }

    /* ------------------------------------------------------------------
       il riquadro flashcard
       ------------------------------------------------------------------ */
    H.register('cards', {
        title: 'home.w_flashcard', icon: 'cards', color: 'var(--purple)', wide: true, link: 'fc/fc_home.php',
        build: function (w) {
            w.goal = el('div', 'ho-goal');
            /* cattura veloce */
            var form = el('form', 'ho-cap-row'); form.autocomplete = 'off';
            w.input = el('input', 'ho-in'); w.input.type = 'text'; w.input.maxLength = 500; w.input.enterKeyHint = 'done';
            w.input.placeholder = T('home.carta_veloce_ph'); w.input.setAttribute('aria-label', T('home.carta_veloce'));
            var save = el('button', 'ho-btn'); save.type = 'submit'; save.setAttribute('aria-label', T('home.salva_per_dopo'));
            save.appendChild(H.svg('plus', 18, 2.6));
            form.appendChild(w.input); form.appendChild(save);
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var front = w.input.value.trim();
                if (!front) return;
                save.disabled = true;
                H.api(API, 'draft_add', { front: front }).then(function (r) {
                    save.disabled = false; w.input.value = '';
                    var fc = H.data.fc;
                    fc.drafts.unshift({ id: r.id, front: front, back: '', deck_id: null, at: '' }); fc.draft_count = r.count;
                    H.repaint(['cards']); H.toast(T('home.bozza_salvata'));
                }).catch(function (e) {
                    save.disabled = false;
                    if (e && e.server) { H.fail(e); return; }
                    /* senza rete la carta aspetta qui e parte da sola */
                    var q = qGet(); q.push({ front: front }); qSet(q); w.input.value = '';
                    H.repaint(['cards']); H.toast(T('home.bozza_in_coda'));
                });
            });
            w.capHint = el('div', 'ho-muted ho-sub', T('home.carta_veloce_aiuto'));
            w.btns = el('div'); w.btns.style.display = 'flex'; w.btns.style.gap = '8px'; w.btns.style.flexWrap = 'wrap';
            var ins = el('button', 'ho-btn ho-btn--soft ho-btn--sm'); ins.type = 'button';
            ins.appendChild(H.svg('plus', 15, 2.6)); ins.appendChild(document.createTextNode(T('sidebar.inserisci')));
            ins.addEventListener('click', apriInserisci);
            w.btns.appendChild(ins);
            w.split = el('div', 'ho-split');
            w.drafts = el('div');
            w.body.appendChild(w.goal); w.body.appendChild(form); w.body.appendChild(w.capHint); w.body.appendChild(w.btns);
            w.body.appendChild(w.split); w.body.appendChild(w.drafts);
        },
        update: function (w, d) {
            var fc = d.fc; if (!fc) return;
            flush();
            /* obiettivo di oggi */
            w.goal.innerHTML = '';
            w.goal.appendChild(el('span', 'ho-cap', T('home.carte_oggi')));
            var bar = el('div', 'ho-bar'); bar.style.setProperty('--c', fc.today >= fc.goal ? 'var(--green)' : 'var(--purple)');
            var i = el('i'); i.style.width = Math.min(100, fc.goal ? Math.round(fc.today / fc.goal * 100) : 0) + '%'; bar.appendChild(i);
            w.goal.appendChild(bar); w.goal.appendChild(el('span', 'v', fc.today + '/' + fc.goal));

            /* partita a meta' + mazzo da giocare */
            w.split.innerHTML = '';
            var u = fc.unfinished;
            if (u) {
                var t = el('div', 'ho-tile');
                var tx = el('div', 'tx');
                tx.appendChild(el('b', null, T('home.partita_a_meta')));
                tx.appendChild(el('span', null, (u.label ? u.label + ' · ' : '') + T('home.risposte_di', { n: u.answered, tot: u.total })));
                var pb = el('div', 'ho-bar'); pb.style.setProperty('--c', 'var(--orange)');
                var pi = el('i'); pi.style.width = Math.round(u.answered / u.total * 100) + '%'; pb.appendChild(pi); tx.appendChild(pb);
                t.appendChild(tx);
                var rs = el('a', 'ho-btn ho-btn--sm', T('home.riprendi')); rs.href = H.base + 'fc/fc_game.php?game_id=' + u.id;
                t.appendChild(rs); w.split.appendChild(t);
            }
            var n = fc.next;
            if (n) {
                var t2 = el('div', 'ho-tile');
                var tx2 = el('div', 'tx');
                tx2.appendChild(el('b', null, n.name));
                var why = n.exam_days !== null ? T('home.esame_tra', { n: n.exam_days }) : (n.days_ago === null ? T('home.mai_giocato') : T('home.giocato_giorni_fa', { n: n.days_ago }));
                tx2.appendChild(el('span', null, T('home.da_giocare') + ' · ' + why));
                t2.appendChild(tx2);
                var pl = el('a', 'ho-btn ho-btn--soft ho-btn--sm'); pl.href = H.base + 'fc/fc_game_setup.php?preselect_category=' + n.id;
                pl.appendChild(H.svg('play', 13, 2.4)); pl.appendChild(document.createTextNode(T('home.gioca')));
                t2.appendChild(pl); w.split.appendChild(t2);
            }
            w.split.style.display = w.split.childNodes.length ? '' : 'none';

            /* da completare: le bozze create offline vengono prima, segnate come in attesa */
            w.drafts.innerHTML = '';
            var pend = qGet().map(function (x) { return { id: null, front: x.front, pending: true }; });
            var tutte = pend.concat(fc.drafts);
            var tot = fc.draft_count + pend.length;
            if (tutte.length) {
                var hd = el('div', 'ho-cap', T('home.da_completare') + ' · ' + tot);
                w.drafts.appendChild(hd);
                tutte.slice(0, 3).forEach(function (b) { w.drafts.appendChild(draftRow(b, d)); });
                if (tot > 3) {
                    var more = el('button', 'ho-more', T('home.altre_n', { n: tot - 3 })); more.type = 'button';
                    more.addEventListener('click', function () {
                        H.sheet(T('home.da_completare'), function (s) {
                            tutte.forEach(function (b) { s.body.appendChild(draftRow(b, d, function () { s.close(); })); });
                        });
                    });
                    w.drafts.appendChild(more);
                }
            }
        }
    });

    /* ------------------------------------------------------------------
       curiosita' (inline, non piu' un popup)
       ------------------------------------------------------------------ */
    var FALLBACK = {
        en: [{ tipo: 'trivia', domanda: 'What is the capital of Romania?', risposta: 'Bucharest' },
             { tipo: 'trivia', domanda: 'How many bones does the adult human body have?', risposta: '206' },
             { tipo: 'quote', domanda: 'Live as if you were to die tomorrow. Learn as if you were to live forever.', risposta: 'Mahatma Gandhi (attributed)' }],
        it: [{ tipo: 'trivia', domanda: 'Qual è la capitale della Romania?', risposta: 'Bucarest' },
             { tipo: 'trivia', domanda: 'Quante ossa ha il corpo umano adulto?', risposta: '206' },
             { tipo: 'quote', domanda: 'Non smettere mai di imparare, perché la vita non smette mai di insegnare.', risposta: 'Proverbio' }]
    };
    var trivDb = null, trivLast = null;
    function trivLoad() {
        if (trivDb) return Promise.resolve(trivDb);
        var file = H.lang === 'it' ? 'home/trivia.json' : 'home/trivia_' + H.lang + '.json';
        return fetch(H.base + file).then(function (r) {
            if (!r.ok && file !== 'home/trivia.json') return fetch(H.base + 'home/trivia.json');
            return r;
        }).then(function (r) { if (!r.ok) throw new Error('x'); return r.json(); })
          .then(function (j) { trivDb = j; return j; })
          .catch(function () { trivDb = FALLBACK[H.lang] || FALLBACK.it; return trivDb; });
    }

    H.register('trivia', {
        title: 'home.w_curiosita', icon: 'bulb', color: 'var(--yellow)',
        build: function (w) {
            w.score = { ok: 0, tot: 0 };
            w.card = el('button', 'ho-triv'); w.card.type = 'button';
            w.tag = el('span', 'ho-cap'); w.q = el('div', 'q'); w.a = el('div', 'a'); w.h = el('div', 'h');
            var top = el('div'); top.appendChild(w.tag); top.appendChild(w.q); top.appendChild(w.a);
            w.card.appendChild(top); w.card.appendChild(w.h);
            w.row = el('div', 'ho-triv-row');
            w.cur = null; w.open = false;
            function draw() {
                var c = w.cur; if (!c) return;
                var quote = String(c.tipo).indexOf('quote') >= 0;
                w.tag.textContent = open_() ? T(quote ? 'dashboard.autore' : 'dashboard.risposta') : T(quote ? 'home.citazione' : 'dashboard.curiosita');
                w.q.style.display = quote || !open_() ? '' : 'none';
                w.q.textContent = c.domanda;
                w.a.style.display = open_() ? '' : 'none';
                w.a.textContent = c.risposta;
                w.h.textContent = open_() ? '' : T('dashboard.tocca_per_scoprire');
                w.row.innerHTML = '';
                if (!open_()) return;
                function next(ok) {
                    if (!quote) { w.score.tot++; if (ok) w.score.ok++; }
                    pick();
                }
                if (quote) {
                    var n = el('button', 'ho-btn ho-btn--soft', T('dashboard.prossima_carta')); n.type = 'button';
                    n.addEventListener('click', function () { next(false); }); w.row.appendChild(n);
                } else {
                    var m = el('button', 'ho-btn ho-btn--grey', T('home.mancata')); m.type = 'button';
                    m.addEventListener('click', function () { next(false); });
                    var g = el('button', 'ho-btn', T('home.presa_bene')); g.type = 'button';
                    g.addEventListener('click', function () { next(true); });
                    w.row.appendChild(m); w.row.appendChild(g);
                }
            }
            function open_() { return w.open; }
            function pick() {
                trivLoad().then(function (db) {
                    var pool = db.length > 1 && trivLast ? db.filter(function (x) { return x.domanda !== trivLast.domanda; }) : db;
                    w.cur = pool[Math.floor(Math.random() * pool.length)]; trivLast = w.cur; w.open = false; draw();
                    w.sc.textContent = w.score.tot ? T('home.punteggio', { ok: w.score.ok, tot: w.score.tot }) : '';
                });
            }
            w.card.addEventListener('click', function () { if (w.cur) { w.open = !w.open; draw(); } });
            w.sc = el('div', 'ho-muted');
            w.body.appendChild(w.card); w.body.appendChild(w.row); w.body.appendChild(w.sc);
            w.pick = pick; w.started = false;
        },
        update: function (w) { if (!w.started) { w.started = true; w.pick(); } }
    });

    /* ------------------------------------------------------------------
       voti
       ------------------------------------------------------------------ */
    H.register('grades', {
        title: 'home.w_voti', icon: 'cap', color: 'var(--teal)', link: 'exams/voti.php',
        build: function (w) { /* tutto in update */ },
        update: function (w, d) {
            var g = d.grades; w.body.innerHTML = '';
            if (!g || !g.has) {
                var e = el('div', 'ho-empty', T('home.voti_vuoti') + ' ');
                var a = el('a', null, T('home.voti_aggiungi')); a.href = H.base + 'exams/voti.php'; e.appendChild(a);
                w.body.appendChild(e); return;
            }
            var top = el('div', 'ho-avg');
            top.appendChild(el('b', null, g.avg !== null ? g.avg.toFixed(2) : '–'));
            top.appendChild(el('span', 'ho-muted', T('home.media_ponderata')));
            w.body.appendChild(top);
            if (g.target !== null && g.avg !== null) {
                var diff = g.avg - g.target;
                w.body.appendChild(el('span', 'ho-chip ' + (diff >= 0 ? 'ho-chip--green' : 'ho-chip--orange'),
                    T('home.obiettivo_n', { n: g.target.toFixed(1) }) + ' · ' + (diff >= 0 ? '+' : '') + diff.toFixed(2)));
                w.body.lastChild.style.alignSelf = 'flex-start';
            }
            var pr = el('div');
            var cap = el('div', 'ho-muted', T('home.cfu_fatti', { fatti: g.cfu_done, tot: g.cfu_total }));
            var bar = el('div', 'ho-bar'); bar.style.setProperty('--c', 'var(--teal)'); bar.style.marginTop = '6px';
            var i = el('i'); i.style.width = Math.min(100, g.cfu_total ? Math.round(g.cfu_done / g.cfu_total * 100) : 0) + '%'; bar.appendChild(i);
            pr.appendChild(cap); pr.appendChild(bar); w.body.appendChild(pr);
            if (g.last && g.last.length) {
                var bars = el('div', 'ho-bars');
                g.last.forEach(function (x) {
                    var col = el('div'); col.title = x.name;
                    var bi = el('i'); bi.style.height = Math.max(8, Math.round((Math.max(18, x.grade) - 17) / 13 * 44)) + 'px'; bi.style.setProperty('--c', x.grade >= 27 ? 'var(--green)' : x.grade >= 24 ? 'var(--teal)' : 'var(--orange)');
                    col.appendChild(bi); col.appendChild(document.createTextNode(x.grade + (x.honors ? 'L' : '')));
                    bars.appendChild(col);
                });
                w.body.appendChild(bars);
            }
            if (g.next) {
                var nx = el('div', 'ho-date-card'); nx.style.setProperty('--c', 'var(--teal)');
                var big = el('div', 'big'); big.appendChild(el('b', null, String(g.next.days))); big.appendChild(el('span', null, T(g.next.days === 1 ? 'home.giorno' : 'home.giorni')));
                var tx = el('div', 'tx'); tx.appendChild(el('b', null, g.next.name)); tx.appendChild(el('span', null, T('home.prossimo_esame') + ' · ' + H.fmtDate(g.next.date)));
                nx.appendChild(big); nx.appendChild(tx); w.body.appendChild(nx);
            }
        }
    });

    /* ------------------------------------------------------------------
       numeri
       ------------------------------------------------------------------ */
    H.register('stats', {
        title: 'home.w_numeri', icon: 'chart', color: 'var(--blue)', link: 'fc/fc_stats.php',
        build: function () {},
        update: function (w, d) {
            var fc = d.fc; w.body.innerHTML = ''; if (!fc) return;
            var row = el('div', 'ho-stat-row');
            var dn = el('div', 'ho-donut'), r = 40, c = 2 * Math.PI * r;
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 92 92');
            svg.innerHTML = '<circle cx="46" cy="46" r="' + r + '" fill="none" stroke="var(--hairline)" stroke-width="9"/><circle cx="46" cy="46" r="' + r +
                '" fill="none" stroke="var(--blue)" stroke-width="9" stroke-linecap="round" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + (c * (1 - fc.mastery / 100)).toFixed(1) + '"/>';
            dn.appendChild(svg); dn.appendChild(el('b', null, fc.mastery + '%'));
            row.appendChild(dn);
            var kv = el('div', 'ho-kv');
            [[fc.streak, T('home.serie')], [fc.accuracy + '%', T('home.precisione')], [fc.record, T('home.record')], [fc.cards_total, T('home.carte')]].forEach(function (x) {
                var b = el('div'); b.appendChild(el('b', null, String(x[0]))); b.appendChild(el('span', null, x[1])); kv.appendChild(b);
            });
            row.appendChild(kv); w.body.appendChild(row);
            var days = Object.keys(fc.week || {}).sort();
            var max = 1; days.forEach(function (k) { max = Math.max(max, fc.week[k]); });
            var bars = el('div', 'ho-bars');
            var G = ['g_lun', 'g_mar', 'g_mer', 'g_gio', 'g_ven', 'g_sab', 'g_dom'];
            days.forEach(function (k) {
                var col = el('div'); var bi = el('i'); bi.style.height = Math.max(6, Math.round(fc.week[k] / max * 40)) + 'px';
                if (k === d.today) bi.style.setProperty('--c', 'var(--purple)');
                col.appendChild(bi); col.appendChild(document.createTextNode(T('dashboard.' + G[(H.day(k).getDay() + 6) % 7])));
                col.title = fc.week[k] + ''; bars.appendChild(col);
            });
            w.body.appendChild(el('div', 'ho-cap', T('dashboard.ultimi_7_giorni')));
            w.body.appendChild(bars);
        }
    });
})();
