/* diario_anno.js — il diario in numeri (diario_anno.php). */
(function () {
    'use strict';
    var B = window.DN_BOOT || {};
    var LINGUA = document.documentElement.lang || 'it';
    var S = { anno: B.anno, colore: 'm', periodo: 'w', dati: null, carico: 0 };
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, txt) { var n = document.createElement(tag); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function num(v, dec) { return v == null ? '–' : Number(v).toLocaleString(LINGUA, { maximumFractionDigits: dec == null ? 1 : dec }); }
    function toast(t) {
        var x = $('dyToast'); x.textContent = t; x.classList.add('dy-show');
        clearTimeout(x._t); x._t = setTimeout(function () { x.classList.remove('dy-show'); }, 2600);
    }
    function face(kind, level, size) {
        return window.standoutFace ? window.standoutFace(kind, level, size) : '';
    }

    /* i colori: l'umore dal rosso al verde, l'energia dal celeste al blu,
       le abitudini in viola — sempre più pieno quanto più è andata bene */
    var COL = {
        m: ['#E07A6B', '#EFA96A', '#E9CF6E', '#A8CF8E', '#57B07B'],
        e: ['#D3E2F4', '#A9C6EB', '#7DA6DE', '#5487D0', '#3466B8'],
        h: ['#E9E1F7', '#CDBDEE', '#A991DF', '#8A6BD0', '#6A49B8']
    };
    var MESI = (function () {
        var f = new Intl.DateTimeFormat(LINGUA, { month: 'short' }), o = [];
        for (var m = 0; m < 12; m++) o.push(f.format(new Date(2026, m, 15)));
        return o;
    })();
    var MESI_L = (function () {
        var f = new Intl.DateTimeFormat(LINGUA, { month: 'long' }), o = [];
        for (var m = 0; m < 12; m++) o.push(f.format(new Date(2026, m, 15)));
        return o;
    })();
    var GG = (function () {
        var f = new Intl.DateTimeFormat(LINGUA, { weekday: 'narrow' }), o = [];
        for (var i = 0; i < 7; i++) o.push(f.format(new Date(2024, 0, 1 + i)));
        return o;
    })();

    /* giorno compatto: [umore, energia, parole, voci, abitudini fatte, previste, pomodori, minuti] */
    function g(d) { var x = S.dati && S.dati.days[d]; return x ? { m: x[0], e: x[1], w: x[2], l: x[3], hd: x[4], hu: x[5], p: x[6], f: x[7] } : null; }
    function scritto(x) { return x && (x.w > 0 || x.l > 0); }
    function livello(x) {
        if (!x) return null;
        if (S.colore === 'm') return x.m;
        if (S.colore === 'e') return x.e;
        if (!x.hu) return null;
        var p = Math.min(1, x.hd / x.hu);
        return p <= 0 ? 1 : Math.min(5, 1 + Math.round(p * 4));
    }

    function riassunto(giorni) {
        var m = [], e = [], s = 0, w = 0, hd = 0, hu = 0, p = 0, f = 0;
        giorni.forEach(function (x) {
            if (!x) return;
            if (x.m != null) m.push(x.m);
            if (x.e != null) e.push(x.e);
            if (scritto(x)) s++;
            w += x.w; hd += x.hd; hu += x.hu; p += x.p; f += x.f;
        });
        var med = function (a) { return a.length ? a.reduce(function (t, v) { return t + v; }, 0) / a.length : null; };
        return { giorni: giorni.length, scritti: s, parole: w, umore: med(m), energia: med(e),
                 pct: hu > 0 ? Math.round(Math.min(1, hd / hu) * 100) : null, fatte: hd, pomodori: p, minuti: f };
    }

    /* ---------------- caricamento ---------------- */
    function carica() {
        var mio = ++S.carico;
        $('dnYear').textContent = S.anno;
        $('dnNext').disabled = S.anno >= +String(B.oggi).slice(0, 4);
        document.body.classList.add('dn-loading');
        fetch('diary_api.php?ajax=insights&year=' + S.anno, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (mio !== S.carico) return;
                document.body.classList.remove('dn-loading');
                if (!r || !r.ok) { toast(T('comune.errore_rete')); return; }
                S.dati = r;
                disegnaTutto();
            }).catch(function () { document.body.classList.remove('dn-loading'); toast(T('comune.errore_rete')); });
    }

    function disegnaTutto() { tessere(); calendario(); andamento(); trimestri(); libri(); }

    /* ---------------- le tessere in cima ---------------- */
    function tessere() {
        var box = $('dnTiles'); box.innerHTML = '';
        var tutti = Object.keys(S.dati.days).map(g);
        var r = riassunto(tutti);
        function t(icona, valore, etichetta) {
            var x = el('div', 'dn-tile');
            var i = el('div', 'dn-tile-i'); i.innerHTML = icona; x.appendChild(i);
            x.appendChild(el('div', 'dn-tile-v', valore));
            x.appendChild(el('div', 'dn-tile-l', etichetta));
            box.appendChild(x);
        }
        t('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 3.5h11A1.5 1.5 0 0119 5v14a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 015 19V5a1.5 1.5 0 011.5-1.5z"/><path d="M9 8h6M9 11.5h6M9 15h3.5"/></svg>',
          String(r.scritti), T('diario.dn_giorni_scritti'));
        t(face('mood', r.umore ? Math.round(r.umore) : null, 26), num(r.umore), T('diario.dn_umore_medio'));
        t(face('energy', r.energia ? Math.round(r.energia) : null, 26), num(r.energia), T('diario.dn_energia_media'));
        t('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M8.6 12.2l2.2 2.2 4.4-4.6"/></svg>',
          r.pct == null ? '–' : r.pct + '%', T('diario.dn_abitudini_fatte'));
        t('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="13" r="7.5"/><path d="M12 13V9.2M10 3.5h4"/></svg>',
          num(r.minuti / 60, 0) + ' h', T('diario.dn_concentrazione', { n: r.pomodori }));
    }

    /* ---------------- il calendario dell'anno ---------------- */
    function calendario() {
        var box = $('dnMonths'); box.innerHTML = '';
        var oggi = B.oggi;
        for (var m = 0; m < 12; m++) {
            var mese = el('div', 'dn-month');
            var lab = el('button', 'dn-month-l', MESI_L[m]);
            lab.type = 'button';
            (function (m) { lab.addEventListener('click', function () { location.href = 'diario.php?data=' + S.anno + '-' + pad(m + 1) + '-01'; }); })(m);
            mese.appendChild(lab);
            var grid = el('div', 'dn-mgrid');
            var primo = new Date(S.anno, m, 1);
            var vuoti = (primo.getDay() + 6) % 7;
            for (var v = 0; v < vuoti; v++) grid.appendChild(el('span', 'dn-d dn-vuoto'));
            var n = new Date(S.anno, m + 1, 0).getDate();
            for (var d = 1; d <= n; d++) {
                var data = S.anno + '-' + pad(m + 1) + '-' + pad(d);
                var x = g(data);
                var c = el('button', 'dn-d');
                c.type = 'button';
                if (data > oggi) c.classList.add('dn-futuro');
                else {
                    var liv = livello(x);
                    if (liv) { c.style.background = COL[S.colore][liv - 1]; c.classList.add('dn-pieno'); }
                    else if (scritto(x)) c.classList.add('dn-scritto');
                    if (scritto(x)) c.classList.add('dn-testo');
                    (function (data) { c.addEventListener('click', function () { location.href = 'diario.php?data=' + data; }); })(data);
                }
                if (data === oggi) c.classList.add('dn-oggi');
                c.setAttribute('aria-label', data);
                grid.appendChild(c);
            }
            mese.appendChild(grid);
            box.appendChild(mese);
        }
        /* la legenda */
        var leg = $('dnLegend'); leg.innerHTML = '';
        var parole = S.colore === 'm' ? [1, 2, 3, 4, 5].map(function (i) { return T('diario.umore_' + i); })
                   : S.colore === 'e' ? [1, 2, 3, 4, 5].map(function (i) { return T('diario.energia_' + i); })
                   : ['0%', '25%', '50%', '75%', '100%'];
        COL[S.colore].forEach(function (c, i) {
            var v = el('span', 'dn-leg');
            var p = el('i'); p.style.background = c; v.appendChild(p);
            v.appendChild(el('span', null, parole[i]));
            leg.appendChild(v);
        });
        var s = el('span', 'dn-leg'); s.appendChild(el('i', 'dn-leg-scritto')); s.appendChild(el('span', null, T('diario.dn_solo_scritto'))); leg.appendChild(s);
    }

    /* ---------------- settimane, mesi, anni ---------------- */
    function riga(etichetta, sotto, r, href) {
        var x = el(href ? 'a' : 'div', 'dn-row');
        if (href) x.href = href;
        var a = el('div', 'dn-row-l');
        a.appendChild(el('b', null, etichetta));
        if (sotto) a.appendChild(el('span', null, sotto));
        x.appendChild(a);
        function barra(v, max, colori, testo) {
            var c = el('div', 'dn-bar');
            var f = el('i');
            if (v != null) {
                f.style.width = Math.max(4, Math.min(100, v / max * 100)) + '%';
                f.style.background = colori[Math.max(0, Math.min(4, Math.round(v) - 1))];
            }
            c.appendChild(f);
            c.appendChild(el('span', null, testo));
            return c;
        }
        x.appendChild(barra(r.umore, 5, COL.m, num(r.umore)));
        x.appendChild(barra(r.energia, 5, COL.e, num(r.energia)));
        x.appendChild(barra(r.pct == null ? null : 1 + r.pct / 25, 5, COL.h, r.pct == null ? '–' : r.pct + '%'));
        x.appendChild(el('div', 'dn-row-n', r.scritti + '/' + r.giorni));
        x.appendChild(el('div', 'dn-row-n', String(r.pomodori)));
        return x;
    }
    function testaRighe(box, primo) {
        var h = el('div', 'dn-row dn-row-h');
        [primo, T('diario.dn_umore'), T('diario.dn_energia'), T('diario.dn_abitudini'), T('diario.dn_scritti'), '🍅'].forEach(function (t) { h.appendChild(el('span', null, t)); });
        box.appendChild(h);
    }
    function andamento() {
        var box = $('dnRows'); box.innerHTML = '';
        var oggi = B.oggi;
        if (S.periodo === 'w') {
            testaRighe(box, T('diario.dn_settimana'));
            /* le settimane dell'anno, dalla più recente; si mostrano le ultime 16 */
            var fine = new Date(Math.min(new Date(S.anno, 11, 31).getTime(), new Date(oggi + 'T12:00:00').getTime()));
            var lun = new Date(fine); lun.setDate(lun.getDate() - ((lun.getDay() + 6) % 7));
            var fmt = new Intl.DateTimeFormat(LINGUA, { day: 'numeric', month: 'short' });
            for (var k = 0; k < 16; k++) {
                var da = new Date(lun); da.setDate(lun.getDate() - k * 7);
                if (da.getFullYear() < S.anno && new Date(da.getTime() + 6 * 864e5).getFullYear() < S.anno) break;
                var giorni = [];
                for (var i = 0; i < 7; i++) { var d = new Date(da); d.setDate(da.getDate() + i); var s = iso(d); if (s <= oggi && d.getFullYear() === S.anno) giorni.push(g(s)); }
                if (!giorni.length) continue;
                var a = new Date(da); a.setDate(da.getDate() + 6);
                box.appendChild(riga(fmt.format(da), '→ ' + fmt.format(a), riassunto(giorni), 'diario.php?data=' + iso(da)));
            }
        } else if (S.periodo === 'm') {
            testaRighe(box, T('diario.dn_mese'));
            for (var m = 11; m >= 0; m--) {
                var gm = [];
                var n = new Date(S.anno, m + 1, 0).getDate();
                for (var d2 = 1; d2 <= n; d2++) { var s2 = S.anno + '-' + pad(m + 1) + '-' + pad(d2); if (s2 <= oggi) gm.push(g(s2)); }
                if (!gm.length) continue;
                box.appendChild(riga(MESI_L[m], null, riassunto(gm), 'diario.php?data=' + S.anno + '-' + pad(m + 1) + '-01'));
            }
        } else {
            testaRighe(box, T('diario.dn_anno'));
            (S.dati.anni || []).slice().reverse().forEach(function (y) {
                var r = { giorni: y.giorni, scritti: y.scritti, umore: y.umore, energia: y.energia, pct: y.abitudini_pct, pomodori: y.pomodori };
                var x = riga(String(y.anno), T('diario.dn_parole_n', { n: num(y.parole, 0) }), r);
                x.addEventListener('click', function () { S.anno = y.anno; carica(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
                x.classList.add('dn-row-tap');
                box.appendChild(x);
            });
        }
        if (box.children.length <= 1) box.appendChild(el('div', 'dn-vuoto-t', T('diario.dn_niente')));
    }

    /* ---------------- i trimestri: resoconto e libro ---------------- */
    function libriFatti() { return (S.dati.libri || []).filter(function (b) { return b.stato === 'fatto'; }); }

    function trimestri() {
        var box = $('dnQuarters'); box.innerHTML = '';
        var fmt = new Intl.DateTimeFormat(LINGUA, { month: 'short' });
        (S.dati.trimestri || []).forEach(function (q) {
            var c = el('div', 'dn-q' + (q.idoneo ? ' dn-q-ok' : ''));
            var h = el('div', 'dn-q-h');
            h.appendChild(el('b', null, T('diario.dn_trimestre_n', { n: q.q })));
            h.appendChild(el('span', null, fmt.format(new Date(q.da + 'T12:00:00')) + ' – ' + fmt.format(new Date(q.a + 'T12:00:00'))
                                   + (q.in_corso ? ' · ' + T('diario.dn_in_corso') : '')));
            c.appendChild(h);
            /* quanto manca per avere abbastanza da raccontare */
            var pr = el('div', 'dn-q-pr');
            function misura(v, min, etichetta) {
                var m = el('div', 'dn-q-m' + (v >= min ? ' dn-fatto' : ''));
                var b = el('div', 'dn-q-mb'); var f = el('i'); f.style.width = Math.min(100, v / min * 100) + '%'; b.appendChild(f);
                m.appendChild(el('span', null, etichetta));
                m.appendChild(b);
                return m;
            }
            pr.appendChild(misura(q.scritti, q.min_giorni, T('diario.dn_q_giorni', { n: q.scritti, min: q.min_giorni })));
            pr.appendChild(misura(q.parole, q.min_parole, T('diario.dn_q_parole', { n: num(q.parole, 0), min: num(q.min_parole, 0) })));
            c.appendChild(pr);

            var az = el('div', 'dn-q-az');
            if (q.resoconto) {
                var leggi = el('a', 'dn-btn dn-btn-p', T('diario.dn_leggi_resoconto'));
                leggi.href = 'diario_lettura.php?resoconto=' + q.resoconto;
                az.appendChild(leggi);
            }
            if (q.idoneo) {
                var rep = el('button', 'dn-btn' + (q.resoconto ? '' : ' dn-btn-p'), q.resoconto ? T('diario.dn_rifai_resoconto') : T('diario.dn_crea_resoconto'));
                rep.type = 'button';
                rep.addEventListener('click', function () { creaResoconto(q); });
                az.appendChild(rep);
                var lib = el('button', 'dn-btn', T('diario.dn_crea_libro'));
                lib.type = 'button';
                lib.addEventListener('click', function () { creaLibro(q); });
                az.appendChild(lib);
            } else {
                az.appendChild(el('span', 'dn-q-nota', T('diario.dn_scrivi_ancora')));
            }
            c.appendChild(az);
            box.appendChild(c);
        });
        if (!box.children.length) box.appendChild(el('div', 'dn-vuoto-t', T('diario.dn_niente')));
    }

    function libri() {
        var box = $('dnBooks'); box.innerHTML = '';
        var tutti = S.dati.libri || [];
        if (!tutti.length) return;
        box.appendChild(el('div', 'dn-books-h', T('diario.dn_i_tuoi_libri')));
        var fmt = new Intl.DateTimeFormat(LINGUA, { month: 'short', year: 'numeric' });
        tutti.forEach(function (b) {
            var r = el('div', 'dn-book');
            var copertina = el('div', 'dn-book-c'); copertina.appendChild(el('span', null, (b.titolo || '…').slice(0, 1)));
            r.appendChild(copertina);
            var t = el('div', 'dn-book-t');
            t.appendChild(el('b', null, b.titolo || T('diario.dn_libro_senza_titolo')));
            t.appendChild(el('span', null, fmt.format(new Date(b.da + 'T12:00:00')) + ' – ' + fmt.format(new Date(b.a + 'T12:00:00'))));
            r.appendChild(t);
            if (b.stato === 'fatto') {
                var a = el('a', 'dn-btn dn-btn-p', T('diario.dn_leggi'));
                a.href = 'diario_lettura.php?libro=' + b.id;
                r.appendChild(a);
            } else {
                var c = el('button', 'dn-btn', T('diario.dn_continua_scrittura'));
                c.type = 'button';
                c.addEventListener('click', function () { scriviLibro(b.id, b.titolo); });
                r.appendChild(c);
            }
            box.appendChild(r);
        });
    }

    /* ---------------- finestre ---------------- */
    function finestra(titolo, corpo, azioni) {
        $('dnModalT').textContent = titolo;
        var b = $('dnModalB'); b.innerHTML = '';
        if (typeof corpo === 'string') b.appendChild(el('p', null, corpo)); else if (corpo) b.appendChild(corpo);
        var a = $('dnModalActs'); a.innerHTML = '';
        (azioni || []).forEach(function (x) {
            var btn = el('button', 'dn-btn' + (x.primario ? ' dn-btn-p' : ''), x.testo);
            btn.type = 'button';
            if (x.id) btn.id = x.id;
            btn.addEventListener('click', function () { if (x.fai) x.fai(btn); else chiudi(); });
            a.appendChild(btn);
        });
        $('dnModal').hidden = false;
    }
    function chiudi() { $('dnModal').hidden = true; }
    $('dnModal').addEventListener('click', function (e) { if (e.target === this && !this.dataset.bloccato) chiudi(); });

    function ai(dati) {
        return fetch('diario_ai.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
            body: JSON.stringify(dati)
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'rete' }; }); })
          .catch(function () { return { ok: false, error: 'rete' }; });
    }
    function messaggioErrore(r) {
        var e = (r && r.error) || '';
        if (e === 'limite_piano') return T('diario.dn_err_crediti', { stima: r.stima, disp: r.disponibili == null ? '∞' : r.disponibili });
        if (e === 'troppo_poco') return T('diario.dn_scrivi_ancora');
        if (e === 'niente_pensieri') return T('diario.dn_err_pensieri');
        if (e === 'rete') return T('comune.errore_rete');
        return T('diario.dn_err_ia');
    }

    /* prima di inviare il diario: cosa succede e quanto costa */
    function conferma(q, per, unisci, poi) {
        var corpo = el('div');
        corpo.appendChild(el('p', null, per === 'resoconto' ? T('diario.dn_conferma_resoconto') : T('diario.dn_conferma_libro')));
        corpo.appendChild(el('p', 'dn-privacy', T('diario.dn_privacy')));
        var costo = el('p', 'dn-costo', T('diario.dn_calcolo_costo'));
        corpo.appendChild(costo);
        var unisciBox = null;
        if (per === 'libro' && libriFatti().length) {
            unisciBox = el('label', 'dn-unisci');
            var cb = el('input'); cb.type = 'checkbox'; cb.checked = !!unisci;
            unisciBox.appendChild(cb);
            unisciBox.appendChild(el('span', null, T('diario.dn_unisci', { titolo: libriFatti()[0].titolo || '' })));
            unisciBox.appendChild(el('small', null, T('diario.dn_unisci_nota')));
            corpo.appendChild(unisciBox);
            cb.addEventListener('change', function () { stima(); });
        }
        function idUnisci() { return unisciBox && unisciBox.querySelector('input').checked ? libriFatti()[0].id : 0; }
        function stima() {
            costo.textContent = T('diario.dn_calcolo_costo');
            ai({ azione: 'stima', anno: S.anno, q: q.q, per: per, unisci: idUnisci() }).then(function (r) {
                if (!r || !r.ok) { costo.textContent = ''; return; }
                costo.textContent = r.disponibili == null ? T('diario.dn_costo', { n: num(r.stima, 0) })
                                  : T('diario.dn_costo_resto', { n: num(r.stima, 0), disp: num(r.disponibili, 0) });
                costo.classList.toggle('dn-err', !r.sufficiente);
            });
        }
        stima();
        finestra(per === 'resoconto' ? T('diario.dn_crea_resoconto') : T('diario.dn_crea_libro'), corpo, [
            { testo: T('comune.annulla') },
            { testo: T('diario.dn_crea'), primario: true, fai: function () { poi(idUnisci()); } }
        ]);
    }

    function attesa(testo) {
        var corpo = el('div', 'dn-attesa');
        corpo.appendChild(el('div', 'dn-spinner'));
        var t = el('p', null, testo); corpo.appendChild(t);
        var barra = el('div', 'dn-prog'); var f = el('i'); barra.appendChild(f); corpo.appendChild(barra);
        $('dnModal').dataset.bloccato = '1';
        finestra(T('diario.dn_un_momento'), corpo, []);
        return { testo: function (s) { t.textContent = s; }, prog: function (p) { f.style.width = Math.round(p * 100) + '%'; } };
    }
    function sblocca() { delete $('dnModal').dataset.bloccato; }

    function creaResoconto(q) {
        conferma(q, 'resoconto', 0, function () {
            var w = attesa(T('diario.dn_scrivo_resoconto'));
            w.prog(.15);
            var t = setInterval(function () { var f = document.querySelector('.dn-prog i'); if (f) f.style.width = Math.min(92, parseFloat(f.style.width) + 3) + '%'; }, 900);
            ai({ azione: 'resoconto', anno: S.anno, q: q.q }).then(function (r) {
                clearInterval(t); sblocca();
                if (!r || !r.ok) {
                    finestra(T('diario.dn_ops'), messaggioErrore(r), [{ testo: T('comune.chiudi') },
                        r && r.riprovabile ? { testo: T('diario.dn_riprova'), primario: true, fai: function () { creaResoconto(q); } } : null].filter(Boolean));
                    return;
                }
                location.href = 'diario_lettura.php?resoconto=' + r.id;
            });
        });
    }

    function creaLibro(q) {
        conferma(q, 'libro', libriFatti().length ? 1 : 0, function (unisci) {
            var w = attesa(T('diario.dn_scrivo_piano'));
            w.prog(.05);
            ai({ azione: 'libro_piano', anno: S.anno, q: q.q, unisci: unisci }).then(function (r) {
                if (!r || !r.ok) {
                    sblocca();
                    finestra(T('diario.dn_ops'), messaggioErrore(r), [{ testo: T('comune.chiudi') }]);
                    return;
                }
                scriviLibro(r.id, r.titolo, w);
            });
        });
    }

    /* il libro si scrive un pezzo alla volta; chiudendo la pagina il lavoro
       fatto resta, e dall'elenco dei libri si riprende */
    function scriviLibro(id, titolo, w) {
        w = w || attesa(T('diario.dn_scrivo_libro', { titolo: titolo || '' }));
        var errori = 0;
        function passo() {
            ai({ azione: 'libro_avanti', id: id }).then(function (r) {
                if (!r || !r.ok) {
                    if (r && r.riprovabile && ++errori < 3) { setTimeout(passo, 1500); return; }
                    sblocca();
                    finestra(T('diario.dn_ops'), messaggioErrore(r), [{ testo: T('comune.chiudi'), fai: function () { chiudi(); carica(); } },
                        { testo: T('diario.dn_riprova'), primario: true, fai: function () { scriviLibro(id, titolo); } }]);
                    return;
                }
                errori = 0;
                w.prog(r.totale ? r.fatti / r.totale : 1);
                if (r.capitolo) w.testo(T('diario.dn_scritto_capitolo', { n: r.fatti, tot: r.totale, titolo: r.capitolo }));
                if (r.fatto) { sblocca(); location.href = 'diario_lettura.php?libro=' + id; return; }
                passo();
            });
        }
        passo();
    }

    /* ---------------- comandi ---------------- */
    function scelta(id, chiave) {
        $(id).addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            S[chiave] = b.dataset.v;
            this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('dn-on', x === b); });
            if (S.dati) { if (chiave === 'colore') calendario(); else andamento(); }
        });
    }
    scelta('dnColore', 'colore');
    scelta('dnPeriodo', 'periodo');
    $('dnPrev').addEventListener('click', function () { S.anno--; carica(); });
    $('dnNext').addEventListener('click', function () { S.anno++; carica(); });
    carica();
})();
