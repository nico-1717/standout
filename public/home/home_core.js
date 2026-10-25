/* home_core.js — il motore della home.

   La home e' una griglia di riquadri. Questo file non sa niente di cosa
   contiene ciascuno: gestisce quello che hanno in comune.

     - Home.register(id, {...})  ogni riquadro (home_w_*.js) si presenta qui
     - Home.load()               una sola richiesta (home_api.php?ajax=summary)
                                 e poi ogni riquadro ridisegna la sua parte
     - il layout                 quali riquadri si vedono e in che ordine,
                                 salvato sul server e, per averlo subito,
                                 anche in localStorage
     - la modalita' «Personalizza»  su/giu e occhio su ogni riquadro
     - gli attrezzi comuni       fogli (popup), avviso, chiamate, date, icone

   Senza rete: l'ultima risposta resta in localStorage e la home si
   disegna lo stesso, con un avviso. Le scritture (spunte, nuove voci)
   dicono chiaramente che serve la rete: meglio un no onesto che una spunta
   che sembra fatta e non lo e'. Fa eccezione la nota, che ha una sua
   coda (vedi home_w_tasks.js). */
(function () {
    'use strict';

    var H = window.Home = { widgets: {}, nodes: {}, data: null, layout: null, loadedAt: 0, serverMin: 0 };
    var root = document.getElementById('hoRoot');
    if (!root) return;
    H.base = root.getAttribute('data-base') || '/';
    H.lang = (document.documentElement.lang || 'it').slice(0, 2);
    H.locale = H.lang === 'it' ? 'it-IT' : 'en-GB';

    var LS_CACHE = 'ho_cache_v1', LS_LAYOUT = 'ho_layout_v1';
    function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
    H.lsGet = lsGet; H.lsSet = lsSet;

    /* ---------------- piccoli attrezzi ---------------- */
    H.el = function (tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    };
    var el = H.el;

    H.IC = {
        calendar: '<path d="M7 3v3M17 3v3M4 8h16"/><rect x="4" y="5" width="16" height="16" rx="2.5"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        folder: '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
        list: '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        note: '<path d="M5 4h11l3 3v13H5z"/><path d="M8 11h8M8 15h5"/>',
        heart: '<path d="M12 21s-7-4.4-9.3-9A5.2 5.2 0 0112 6.6 5.2 5.2 0 0121.3 12C19 16.6 12 21 12 21z"/>',
        cards: '<rect x="7" y="3" width="13" height="16" rx="2.4"/><path d="M4 7.2V19a2 2 0 002 2h9.6"/>',
        bulb: '<path d="M9 18h6M10 21h4M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z"/>',
        cap: '<path d="M2 9l10-5 10 5-10 5-10-5z"/><path d="M6 11.4V17c0 1.1 2.7 3 6 3s6-1.9 6-3v-5.6"/>',
        chart: '<path d="M3 3v18h18"/><path d="M19 9l-5 5-4-4-3 3"/>',
        bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        up: '<path d="M6 15l6-6 6 6"/>',
        down: '<path d="M6 9l6 6 6-6"/>',
        eye: '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        eyeoff: '<path d="M17.9 17.9A10.9 10.9 0 0112 19C5 19 1 12 1 12a18.5 18.5 0 015.1-5.9M9.9 4.2A9.1 9.1 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.2 3.2M14.1 14.1a3 3 0 11-4.2-4.2M1 1l22 22"/>',
        sliders: '<path d="M4 6h8M18 6h2M4 12h2M12 12h8M4 18h10M20 18h0"/><circle cx="15" cy="6" r="2.2"/><circle cx="9" cy="12" r="2.2"/><circle cx="17" cy="18" r="2.2"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        x: '<path d="M6 6l12 12M18 6L6 18"/>',
        play: '<path d="M7 4l13 8-13 8z"/>',
        pill: '<path d="M10.5 20.5a5 5 0 01-7-7l10-10a5 5 0 017 7z"/><path d="M8.5 8.5l7 7"/>',
        cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 001 .6h9.2a1 1 0 001-.8L21 7H6"/>',
        timer: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/>',
        book: '<path d="M4 4h12a3 3 0 013 3v13H7a3 3 0 01-3-3z"/><path d="M4 17a3 3 0 013-3h12"/>',
        users: '<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
        globe: '<path d="M4 5h8M8 3v2M5.5 5c.8 3.6 3 6.4 6 8M10.5 5c-.9 4-3.4 7.2-6.5 9"/><path d="M13 21l4-10 4 10M14.5 17.5h5"/>',
        flag: '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        refresh: '<path d="M20 11a8 8 0 00-14.9-3M4 13a8 8 0 0014.9 3M4 4v4h4M20 20v-4h-4"/>',
        wifi: '<path d="M2 9a15 15 0 0120 0M5.5 12.5a10 10 0 0113 0M9 16a5 5 0 016 0M12 19.5h.01"/>',
        star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>'
    };
    H.svg = function (name, size, sw) {
        var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
        s.setAttribute('stroke-width', sw || '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round');
        s.setAttribute('aria-hidden', 'true');
        if (size) { s.setAttribute('width', size); s.setAttribute('height', size); }
        s.innerHTML = H.IC[name] || '';   // solo costanti di questo file: nessun dato dell'utente
        return s;
    };

    /* ---------------- chiamate ----------------
       GET senza corpo; POST in JSON, oppure «form» (habits.php legge $_POST).
       Il token CSRF lo aggiunge standout_fetch.js a ogni scrittura. */
    H.api = function (file, action, data, form) {
        var url = H.base + file + '?ajax=' + encodeURIComponent(action);
        var opt = { credentials: 'same-origin' };
        if (data !== undefined && data !== null) {
            opt.method = 'POST';
            if (form) {
                var p = new URLSearchParams();
                Object.keys(data).forEach(function (k) { p.append(k, data[k]); });
                opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
                opt.body = p.toString();
            } else {
                opt.headers = { 'Content-Type': 'application/json' };
                opt.body = JSON.stringify(data);
            }
        }
        return fetch(url, opt).then(function (r) {
            return r.json().catch(function () { throw new Error('bad'); });
        }).then(function (d) {
            if (!d || d.ok === false) { var e = new Error((d && (d.message || d.error)) || 'error'); e.server = true; throw e; }
            return d;
        });
    };
    H.get = function (file, action, qs) {
        var url = H.base + file + '?ajax=' + encodeURIComponent(action) + (qs ? '&' + qs : '');
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || d.ok === false) { var e = new Error((d && (d.message || d.error)) || 'error'); e.server = true; throw e; }
            return d;
        });
    };
    /* un errore di rete e' «offline», uno del server ha un suo messaggio */
    H.fail = function (e) {
        H.toast(e && e.server && e.message && e.message !== 'error' ? e.message : T('home.serve_rete'));
    };

    /* ---------------- date ---------------- */
    H.pad = function (n) { return (n < 10 ? '0' : '') + n; };
    H.day = function (iso) { var p = String(iso).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); };
    H.diffDays = function (a, b) { return Math.round((H.day(b) - H.day(a)) / 86400000); };
    H.toMin = function (hhmm) { var p = String(hhmm || '0:0').split(':'); return (+p[0]) * 60 + (+p[1] || 0); };
    H.fromMin = function (m) { m = ((m % 1440) + 1440) % 1440; return H.pad(Math.floor(m / 60)) + ':' + H.pad(m % 60); };
    /* i minuti di adesso secondo il SERVER (Europe/Rome), che e' l'ora in cui
       sono scritti gli eventi; fra una richiesta e l'altra corre l'orologio locale */
    H.nowMin = function () { return H.serverMin + Math.floor((Date.now() - H.loadedAt) / 60000); };
    H.today = function () { return H.data ? H.data.today : new Date().toISOString().slice(0, 10); };
    H.fmtDate = function (iso, opt) { return H.day(iso).toLocaleDateString(H.locale, opt || { weekday: 'short', day: 'numeric', month: 'short' }); };
    /* «Oggi», «Domani», «Mer 28 ott»; il passato lo dice con chiarezza */
    H.rel = function (iso) {
        var d = H.diffDays(H.today(), iso);
        if (d === 0) return T('home.oggi');
        if (d === 1) return T('home.domani');
        if (d === -1) return T('home.ieri');
        return H.fmtDate(iso);
    };
    H.mins = function (m) {
        m = Math.round(m);
        if (m < 60) return m + ' min';
        var h = Math.floor(m / 60), r = m % 60;
        return r ? h + ' h ' + r + ' min' : h + ' h';
    };

    /* ---------------- avviso in basso ---------------- */
    H.toast = function (msg, label, fn) {
        var t = document.getElementById('hoToast');
        if (!t) { t = el('div', 'ho-toast'); t.id = 'hoToast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
        t.innerHTML = '';
        t.appendChild(el('span', null, msg));
        if (label && fn) {
            var b = el('button', null, label); b.type = 'button';
            b.addEventListener('click', function () { t.classList.remove('show'); fn(); });
            t.appendChild(b);
        }
        t.classList.add('show');
        clearTimeout(t._t);
        t._t = setTimeout(function () { t.classList.remove('show'); }, label ? 5200 : 2800);
    };

    /* ---------------- foglio (bottom sheet su telefono, finestra su desktop) ---------------- */
    H.sheet = function (title, build, onClose) {
        var ov = el('div', 'ho-ov');
        var box = el('div', 'ho-sheet');
        box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', title);
        box.appendChild(el('div', 'ho-grab'));
        var head = el('div', 'ho-sh-head');
        head.appendChild(el('h2', null, title));
        var x = el('button', 'ho-x'); x.type = 'button'; x.setAttribute('aria-label', T('comune.chiudi'));
        x.appendChild(H.svg('x', 14, 2.8));
        head.appendChild(x); box.appendChild(head);
        var body = el('div', 'ho-sh-body'); body.style.display = 'flex'; body.style.flexDirection = 'column'; body.style.gap = '14px';
        box.appendChild(body); ov.appendChild(box);
        var api = {
            body: body, box: box, head: head,
            close: function () {
                document.removeEventListener('keydown', onKey);
                ov.classList.remove('show');
                setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 240);
                document.documentElement.style.overflow = '';
                if (onClose) onClose();
            }
        };
        function onKey(e) { if (e.key === 'Escape') api.close(); }
        x.addEventListener('click', api.close);
        ov.addEventListener('mousedown', function (e) { if (e.target === ov) api.close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
        document.documentElement.style.overflow = 'hidden';
        build(api);
        requestAnimationFrame(function () { ov.classList.add('show'); });
        return api;
    };

    /* ---------------- riquadri ---------------- */
    H.register = function (id, def) { H.widgets[id] = def; };

    function makeNode(id) {
        var def = H.widgets[id];
        var sec = el('section', 'ho-w' + (def.wide ? ' ho-w--wide' : '') + (def.full ? ' ho-w--full' : '') + (def.bare ? ' ho-w--bare' : ''));
        sec.setAttribute('data-id', id);
        var title = T(def.title);
        /* barra del «Personalizza»: nome, su, giu, occhio. Sempre nel DOM, la
           mostra la classe .ho-edit del contenitore. */
        var bar = el('div', 'ho-ebar');
        bar.appendChild(el('span', 'nm', title));
        [['up', T('home.sposta_su'), -1], ['down', T('home.sposta_giu'), 1]].forEach(function (a) {
            var b = el('button', a[0]); b.type = 'button'; b.setAttribute('aria-label', a[1]); b.title = a[1];
            b.appendChild(H.svg(a[0], 16, 2.4));
            b.addEventListener('click', function () { move(id, a[2]); });
            bar.appendChild(b);
        });
        var eye = el('button', 'eye'); eye.type = 'button';
        eye.addEventListener('click', function () { toggle(id); });
        bar.appendChild(eye);
        sec.appendChild(bar);
        var w = { id: id, el: sec, bar: bar, eye: eye, body: null, def: def };
        if (!def.bare) {
            var head = el('div', 'ho-wh');
            var ic = el('span', 'ho-wi'); ic.style.setProperty('--c', def.color || 'var(--blue)'); ic.appendChild(H.svg(def.icon || 'star'));
            head.appendChild(ic);
            head.appendChild(el('h2', 'ho-wt', title));
            if (def.link) {
                var a = el('a', 'ho-wa', T(def.linkLabel || 'home.apri')); a.href = H.base + def.link;
                head.appendChild(a);
            }
            sec.appendChild(head);
            w.head = head;
        }
        w.body = el('div', 'ho-wb'); w.body.style.display = 'flex'; w.body.style.flexDirection = 'column'; w.body.style.gap = '12px';
        if (def.bare) w.body.style.gap = '0';
        sec.appendChild(w.body);
        if (def.build) def.build(w);
        return w;
    }

    function safe(fn, w) {
        /* un riquadro che si rompe non deve portarsi dietro la pagina */
        try { fn(); } catch (e) { if (window.console) console.error('home widget ' + (w && w.id) + ':', e); }
    }

    /* ---------------- layout ---------------- */
    function defaultLayout() { return Object.keys(H.widgets).map(function (id) { return { id: id, on: true }; }); }
    function cleanLayout(l) {
        var out = [], seen = {};
        (l || []).forEach(function (v) {
            if (v && H.widgets[v.id] && !seen[v.id]) { seen[v.id] = 1; out.push({ id: v.id, on: !!v.on }); }
        });
        Object.keys(H.widgets).forEach(function (id) { if (!seen[id]) out.push({ id: id, on: true }); });
        return out;
    }
    function applyLayout() {
        var grid = document.getElementById('hoGrid');
        H.layout.forEach(function (v) {
            var w = H.nodes[v.id];
            if (!w) return;
            grid.appendChild(w.el);   // riappendere sposta il nodo nella nuova posizione
            w.el.classList.toggle('is-off', !v.on);
            w.eye.innerHTML = ''; w.eye.appendChild(H.svg(v.on ? 'eye' : 'eyeoff', 16, 2.2));
            w.eye.classList.toggle('is-off', !v.on);
            var lab = T(v.on ? 'home.nascondi' : 'home.mostra');
            w.eye.setAttribute('aria-label', lab); w.eye.title = lab;
        });
        H.layout.forEach(function (v, i) {
            var w = H.nodes[v.id]; if (!w) return;
            w.bar.querySelector('.up').disabled = i === 0;
            w.bar.querySelector('.down').disabled = i === H.layout.length - 1;
        });
    }
    var saveTimer = null;
    function saveLayout() {
        lsSet(LS_LAYOUT, JSON.stringify(H.layout));
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            saveTimer = null;
            H.api('home/home_api.php', 'layout_save', { layout: H.layout }).catch(function () {
                /* resta in localStorage: al prossimo caricamento con rete vince il server solo se lo e' piu' recente */
            });
        }, 500);
    }
    function move(id, dir) {
        var i = H.layout.findIndex(function (v) { return v.id === id; });
        var j = i + dir;
        if (i < 0 || j < 0 || j >= H.layout.length) return;
        var t = H.layout[i]; H.layout[i] = H.layout[j]; H.layout[j] = t;
        applyLayout(); saveLayout();
        var w = H.nodes[id];
        if (w) w.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    function toggle(id) {
        var v = H.layout.find(function (x) { return x.id === id; });
        if (!v) return;
        v.on = !v.on;
        applyLayout(); saveLayout();
    }
    H.setEdit = function (on) {
        var wrap = document.getElementById('hoWrap');
        wrap.classList.toggle('ho-edit', on);
        var b = document.getElementById('hoEditBtn');
        if (b) { b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
    };
    H.resetLayout = function () {
        H.layout = defaultLayout(); applyLayout(); saveLayout();
        H.toast(T('home.layout_ripristinato'));
    };

    /* ---------------- dati ---------------- */
    function paint(fromCache) {
        var d = H.data;
        H.serverMin = H.toMin(d.now);
        H.loadedAt = Date.now();
        var hello = document.getElementById('hoHello'), dt = document.getElementById('hoDate'), st = document.getElementById('hoStreak');
        if (dt) dt.textContent = H.fmtDate(d.today, { weekday: 'long', day: 'numeric', month: 'long' });
        if (hello) {
            var h = Math.floor(H.serverMin / 60);
            var k = h < 5 ? 'home.saluto_notte' : h < 12 ? 'home.saluto_mattina' : h < 18 ? 'home.saluto_pomeriggio' : 'home.saluto_sera';
            hello.textContent = T(k, { nome: d.name });
        }
        if (st) {
            var s = d.fc ? d.fc.streak : 0;
            st.hidden = !(s > 0);
            st.textContent = '';
            st.appendChild(document.createTextNode('🔥 ' + s));
            st.title = T(s === 1 ? 'dashboard.giorno_di_fila' : 'dashboard.giorni_di_fila');
        }
        H.layout.forEach(function (v) {
            var w = H.nodes[v.id];
            if (w && w.def.update) safe(function () { w.def.update(w, d, fromCache); }, w);
        });
    }
    H.repaint = function (ids) {
        /* ridisegna solo alcuni riquadri, dopo una modifica locale; salva anche la cache */
        if (!H.data) return;
        (ids || Object.keys(H.nodes)).forEach(function (id) {
            var w = H.nodes[id];
            if (w && w.def.update) safe(function () { w.def.update(w, H.data, false); }, w);
        });
        try { lsSet(LS_CACHE, JSON.stringify(H.data)); } catch (e) {}
    };
    function setOffline(on) {
        var b = document.getElementById('hoOffline');
        if (b) b.classList.toggle('is-on', on);
    }
    H.load = function () {
        return H.get('home/home_api.php', 'summary').then(function (d) {
            H.data = d;
            /* il layout del server vince, tranne se questo dispositivo ha una
               modifica piu' recente non ancora arrivata (salvataggio fallito offline) */
            if (saveTimer === null) H.layout = cleanLayout(d.layout);   // un salvataggio in corso e' piu' recente del server
            lsSet(LS_LAYOUT, JSON.stringify(H.layout));
            applyLayout();
            try { lsSet(LS_CACHE, JSON.stringify(d)); } catch (e) {}
            setOffline(false);
            paint(false);
            return true;
        }).catch(function (e) {
            var c = lsGet(LS_CACHE);
            if (c && !H.data) {
                try { H.data = JSON.parse(c); } catch (x) { H.data = null; }
            }
            if (H.data) {
                var l = lsGet(LS_LAYOUT);
                try { H.layout = cleanLayout(l ? JSON.parse(l) : H.data.layout); } catch (x) { H.layout = cleanLayout(H.data.layout); }
                applyLayout(); paint(true);
            }
            setOffline(true);
            return false;
        });
    };

    /* ---------------- avvio ---------------- */
    function start() {
        var grid = document.getElementById('hoGrid');
        grid.innerHTML = '';
        /* il layout locale serve a disegnare subito, prima che il server risponda */
        var l = lsGet(LS_LAYOUT), loc = null;
        try { loc = l ? JSON.parse(l) : null; } catch (e) {}
        H.layout = cleanLayout(loc);
        H.layout.forEach(function (v) {
            var w = makeNode(v.id);
            H.nodes[v.id] = w;
            grid.appendChild(w.el);
        });
        applyLayout();

        var eb = document.getElementById('hoEditBtn');
        if (eb) eb.addEventListener('click', function () { H.setEdit(!document.getElementById('hoWrap').classList.contains('ho-edit')); });
        var done = document.getElementById('hoEditDone');
        if (done) done.addEventListener('click', function () { H.setEdit(false); });
        var rst = document.getElementById('hoEditReset');
        if (rst) rst.addEventListener('click', H.resetLayout);
        var retry = document.getElementById('hoRetry');
        if (retry) retry.addEventListener('click', function () { H.load(); });

        H.load();

        /* tornando sulla scheda dopo un po', o quando torna la rete, si aggiorna */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && Date.now() - H.loadedAt > 60000) H.load();
        });
        window.addEventListener('online', function () { H.load(); });
        window.addEventListener('offline', function () { setOffline(true); });
        /* ogni minuto "adesso" si sposta: l'agenda ridisegna la sua parte */
        setInterval(function () {
            if (document.hidden || !H.data) return;
            if (H.widgets.agenda) H.repaint(['agenda']);
        }, 60000);
    }
    H.start = start;
})();
