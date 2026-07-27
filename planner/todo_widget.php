<?php
/* =====================================================================
   NISAMA — ATTIVITÀ (componente condiviso)
   Da includere una volta sola in fondo alla pagina:

       include 'todo_widget.php';

   Poi si monta dove serve:

       nisamaTodo.mount(document.getElementById('box'), {
           scope: 'all' | 'project' | 'pomodoro',
           projectId: 12,          // per scope 'project'
           compact: false,         // niente data/ora/durata/progetto (pomodoro)
           selectable: true        // modalità seleziona (pagina principale)
       });

   Stessa estetica di planner e diario: carta calda, matita, angoli morbidi.
   ===================================================================== */
if (!defined('NISAMA_TODO_WIDGET')):
define('NISAMA_TODO_WIDGET', true);
if (!function_exists('sec_csrf_token')) { require_once __DIR__ . '/security_core.php'; }
?>
<style>
.td-wrap{
    /* niente ingrandimento automatico e niente zoom al doppio tocco */
    touch-action:manipulation;
    -webkit-text-size-adjust:100%; text-size-adjust:100%;
    --td-paper:#FDFCF9; --td-panel:#F5F1EA; --td-ink:#4A443D; --td-ink-2:#8A8177;
    --td-ink-3:#B7AEA3; --td-hair:rgba(74,68,61,.12); --td-accent:#A9C3AE;
    --td-accent-soft:#E7EFE8; --td-late:#B4645F; --td-late-soft:#F6E4E2;
    --td-serif:'Cormorant Garamond', Georgia, serif;
    --td-sans:'Jost', -apple-system, system-ui, sans-serif;
    font-family:var(--td-sans); color:var(--td-ink);
}
/* dentro il planner o il diario eredita le tinte già scelte dall'utente */
.pl-body .td-wrap, .dy-body .td-wrap{ --td-accent:var(--accent, #A9C3AE); --td-accent-soft:var(--accent-wash, #E7EFE8); }

.td-list{ display:flex; flex-direction:column; }

/* popup per dare data e ora a più attività insieme */
.td-modal{
    position:fixed; inset:0; z-index:9850; display:grid; place-items:center; padding:24px;
    background:rgba(74,68,61,.28); -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
    animation:td-fade .22s ease;
}
@keyframes td-fade{ from{ opacity:0; } to{ opacity:1; } }
.td-card{
    width:100%; max-width:320px; background:#FDFCF9; border-radius:22px; padding:20px 18px 14px;
    box-shadow:0 24px 54px -22px rgba(74,68,61,.5);
    font-family:'Jost', system-ui, sans-serif; color:#4A443D;
    animation:td-pop .3s cubic-bezier(.34,1.3,.64,1);
}
@keyframes td-pop{ from{ transform:scale(.93); } to{ transform:none; } }
.td-card-t{ font-family:'Cormorant Garamond', serif; font-size:21px; margin-bottom:4px; }
.td-card-s{ font-size:12.5px; color:#8A8177; line-height:1.45; margin-bottom:14px; }
.td-field{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:.5px solid rgba(74,68,61,.1); }
.td-field span{ font-size:14px; }
.td-field input{
    border:none; background:#F5F1EA; border-radius:10px; padding:8px 10px;
    font:inherit; font-size:14px; color:#4A443D; text-align:center; min-width:104px;
}
.td-card-acts{ display:flex; gap:8px; margin-top:16px; }
.td-card-acts button{
    flex:1; padding:11px; border-radius:13px; border:none; cursor:pointer;
    background:#F5F1EA; color:#8A8177; font:inherit; font-size:14px;
}
.td-card-acts button.td-primary{ background:var(--td-accent, #A9C3AE); color:#FFFDFA; }
/* Ogni attività su tre righe: titolo, poi data/ora/durata, poi progetto.
   Su una riga sola il titolo non aveva spazio. */
.td-item{ position:relative; overflow:hidden; border-bottom:.5px solid var(--td-hair); }
.td-item:last-child{ border-bottom:none; }
.td-row{
    display:flex; align-items:flex-start; gap:9px; padding:10px 2px;
    background:var(--td-paper); transition:transform .26s cubic-bezier(.22,.61,.36,1);
}
.td-main{ flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
.td-item.td-sub .td-row{ padding-left:24px; }

/* il cestino compare scorrendo la riga verso sinistra */
.td-swipe{
    position:absolute; left:0; top:0; bottom:0; width:64px;
    display:flex; align-items:center; justify-content:center;
    background:#F3DCDC; color:#8E5A5A; border:none; cursor:pointer;
    /* nascosto finché la riga non viene scostata: anche se qualcosa andasse
       storto con lo scorrimento, non può restare in vista */
    opacity:0; pointer-events:none; transition:opacity .2s ease;
}
.td-swipe svg{ width:17px; height:17px; }
/* il cestino spunta da sinistra scorrendo la riga verso destra */
.td-item.td-swiped .td-row{ transform:translateX(64px); }
.td-item.td-swiped .td-swipe{ opacity:1; pointer-events:auto; }
.td-box{
    width:19px; height:19px; border-radius:7px; flex-shrink:0;
    box-shadow:inset 0 0 0 1.2px var(--td-hair); display:grid; place-items:center;
    color:transparent; background:none; cursor:pointer;
    transition:background-color .3s, color .3s, transform .3s cubic-bezier(.34,1.3,.64,1);
}
.td-box svg{ width:11px; height:11px; }
.td-row.td-done .td-box{ background:var(--td-accent); color:#FFFDFA; box-shadow:none; transform:scale(1.05); }
.td-title{
    flex:1; min-width:0; border:none; background:none; outline:none; font:inherit;
    font-size:15px; color:var(--td-ink); padding:3px 0;
}
.td-title::placeholder{ color:var(--td-ink-3); }
.td-row.td-done .td-title{ color:var(--td-ink-3); text-decoration:line-through; text-decoration-thickness:.7px; }
.td-row.td-late .td-title{ color:var(--td-late); }
.td-row.td-late .td-box{ box-shadow:inset 0 0 0 1.4px var(--td-late); }

/* i comandi stanno sulla stessa riga: niente popup per cambiare data o durata */
.td-tools{ display:flex; align-items:center; gap:4px; flex-shrink:0; }
.td-chip{
    display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:999px;
    background:var(--td-panel); color:var(--td-ink-2); font-size:11px; white-space:nowrap;
    border:none; cursor:pointer; font-family:inherit;
    transition:background-color .2s, color .2s, transform .15s;
}
.td-chip:active{ transform:scale(.94); }
.td-chip svg{ width:12px; height:12px; }
.td-chip.td-set{ background:var(--td-accent-soft); color:var(--td-ink); }
.td-row.td-late .td-chip.td-set{ background:var(--td-late-soft); color:var(--td-late); }
.td-chip{ position:relative; }
/* campo invisibile ma cliccabile, steso su tutto il chip */
.td-input-cover{
    position:absolute; inset:0; width:100% !important; height:100%;
    opacity:0; cursor:pointer; z-index:2;
}
.td-chip input{
    border:none; background:none; outline:none; font:inherit; font-size:11px;
    color:inherit; width:auto; padding:0; cursor:pointer;
}
.td-chip input[type="number"]{ width:30px; text-align:center; }
.td-dot{ width:8px; height:8px; border-radius:50%; flex-shrink:0; }

.td-add{
    display:flex; align-items:center; gap:9px; padding:10px 2px; color:var(--td-ink-3);
    background:none; border:none; font:inherit; font-size:14px; cursor:pointer; width:100%;
}
.td-add svg{ width:15px; height:15px; }
.td-empty{
    font-family:var(--td-serif); font-style:italic; font-size:15px;
    color:var(--td-ink-2); padding:14px 2px; text-align:center;
}
.td-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px; }
.td-lab{
    font-size:9px; font-weight:500; letter-spacing:.18em; text-transform:uppercase; color:var(--td-ink-3);
}
.td-sel-btn{
    border:none; background:none; font:inherit; font-size:12px; color:var(--td-ink-2);
    cursor:pointer; padding:4px 2px;
}
.td-sel-btn.td-on{ color:var(--td-accent); font-weight:500; }

/* modalità seleziona */
.td-wrap.td-selecting .td-tools{ opacity:.35; pointer-events:none; }
.td-pick{
    width:19px; height:19px; border-radius:50%; flex-shrink:0; cursor:pointer;
    box-shadow:inset 0 0 0 1.2px var(--td-hair); display:grid; place-items:center;
    color:transparent; transition:background-color .25s, color .25s;
}
.td-pick svg{ width:11px; height:11px; }
.td-pick.td-on{ background:var(--td-accent); color:#FFFDFA; box-shadow:none; }
/* comandi della selezione: in alto, piccoli, con le icone */
.td-bulk{
    display:flex; gap:6px; align-items:center; padding:2px 0 10px;
    border-bottom:.5px solid var(--td-hair); margin-bottom:4px;
}
.td-bulk button{
    display:flex; align-items:center; gap:6px;
    padding:7px 12px; border-radius:999px; border:none;
    background:var(--td-panel); color:var(--td-ink-2); font:inherit; font-size:12.5px; cursor:pointer;
    transition:transform .15s, background-color .2s;
}
.td-bulk button:active{ transform:scale(.94); }
.td-bulk button svg{ width:15px; height:15px; }
.td-bulk button.td-danger{ background:#F3DCDC; color:#8E5A5A; }
.td-bulk .td-count{ margin-left:auto; font-size:11px; color:var(--td-ink-3); }
</style>

<script>
(function () {
    if (window.nisamaTodo) return;

    var CSRF = <?php echo json_encode(sec_csrf_token()); ?>;
    var MESI = ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'];

    function api(action, data, query) {
        var url = 'todo_api.php?ajax=' + encodeURIComponent(action);
        if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
        var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF } };
        if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
        return fetch(url, opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined && txt !== null) n.textContent = txt;
        return n;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }

    var ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    var ICON_CAL   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>';
    var ICON_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>';
    var ICON_HOUR  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 3h10M7 21h10"/><path d="M8 3c0 4 8 5 8 9s-8 5-8 9"/></svg>';
    var ICON_PLUS  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';

    /* ---------------- una istanza montata ---------------- */
    function Todo(host, opts) {
        this.host = host;
        this.opts = opts || {};
        this.scope = this.opts.scope || 'all';
        this.projectId = this.opts.projectId || 0;
        this.compact = !!this.opts.compact;
        this.selectable = !!this.opts.selectable;
        this.items = [];
        this.sessionIds = [];
        this.picked = {};
        this.selecting = false;
        this.timers = {};
        this.build();
        this.load();
    }

    Todo.prototype.build = function () {
        this.host.innerHTML = '';
        this.wrap = el('div', 'td-wrap');
        if (this.selectable) {
            var head = el('div', 'td-head');
            head.appendChild(el('span', 'td-lab', 'Attività'));
            this.selBtn = el('button', 'td-sel-btn', 'Seleziona');
            this.selBtn.type = 'button';
            var self = this;
            this.selBtn.addEventListener('click', function () { self.toggleSelect(); });
            head.appendChild(this.selBtn);
            this.wrap.appendChild(head);
        }
        this.list = el('div', 'td-list');
        this.wrap.appendChild(this.list);
        this.host.appendChild(this.wrap);
        /* si disegna subito con quello che c'è (niente): il tasto per
           aggiungere dev'esserci anche prima che la lettura risponda */
        this.render();
    };

    Todo.prototype.later = function (key, ms, fn) {
        if (this.timers[key]) clearTimeout(this.timers[key]);
        this.timers[key] = setTimeout(fn, ms);
    };

    Todo.prototype.load = function () {
        var self = this;
        var q = { scope: this.scope, project_id: this.projectId };
        /* nel pomodoro senza progetto si chiedono solo le attività create
           in questa sessione: l'elenco parte vuoto e si riempie scrivendo */
        var body = (this.scope === 'pomodoro') ? { ids: this.sessionIds || [] } : null;
        return api('list', body, q).then(function (r) {
            if (!r.ok) {
                /* Prima, se la lettura falliva, non disegnavo niente: restava un
                   riquadro vuoto senza nemmeno il tasto per aggiungere, e senza
                   dire cosa fosse andato storto. Ora almeno si può lavorare. */
                self.items = [];
                self.render();
                self.list.insertBefore(
                    el('div', 'td-empty', 'Elenco non caricato. Controlla di aver eseguito _db_todo.sql.'),
                    self.list.firstChild);
                return;
            }
            self.items = r.todos;
            self.today = r.today;
            self.now = r.now;
            self.render();
        });
    };

    /* un'attività è in ritardo se la sua data (e ora) è passata e non è spuntata */
    Todo.prototype.isLate = function (t) {
        if (t.done || !t.date) return false;
        if (t.date < this.today) return true;
        if (t.date > this.today) return false;
        return !!(t.time && t.time < this.now);
    };

    Todo.prototype.render = function () {
        var self = this;
        this.list.innerHTML = '';
        /* toccando fuori da una riga scostata, questa si richiude */
        if (!this._closeBound) {
            this._closeBound = true;
            document.addEventListener('pointerdown', function (e) {
                if (e.target.closest && e.target.closest('.td-swipe')) return;
                var open = self.list.querySelectorAll('.td-item.td-swiped');
                for (var i = 0; i < open.length; i++) {
                    if (!open[i].contains(e.target)) open[i].classList.remove('td-swiped');
                }
            }, true);
        }
        this.wrap.classList.toggle('td-selecting', this.selecting);

        if (this.selecting) this.list.appendChild(this.bulkBar());

        var roots = this.items.filter(function (t) { return !t.parent_id; });
        if (!roots.length) this.list.appendChild(el('div', 'td-empty', 'Niente da fare, per ora.'));

        roots.forEach(function (t) {
            self.list.appendChild(self.row(t, false));
            self.items.filter(function (k) { return k.parent_id === t.id; })
                      .forEach(function (k) { self.list.appendChild(self.row(k, true)); });
        });

        var add = el('button', 'td-add');
        add.type = 'button';
        add.innerHTML = ICON_PLUS;
        add.appendChild(el('span', null, 'Nuova attività'));
        add.addEventListener('click', function () { self.addNew(null); });
        this.list.appendChild(add);

    };

    Todo.prototype.row = function (t, isSub) {
        var self = this;
        var item = el('div', 'td-item' + (isSub ? ' td-sub' : ''));
        var row = el('div', 'td-row'
            + (t.done ? ' td-done' : '') + (this.isLate(t) ? ' td-late' : ''));

        if (this.selecting) {
            var pick = el('span', 'td-pick' + (this.picked[t.id] ? ' td-on' : ''));
            pick.innerHTML = ICON_CHECK;
            pick.addEventListener('click', function () {
                if (self.picked[t.id]) delete self.picked[t.id]; else self.picked[t.id] = true;
                pick.classList.toggle('td-on', !!self.picked[t.id]);
            });
            row.appendChild(pick);
        } else {
            var box = el('span', 'td-box');
            box.innerHTML = ICON_CHECK;
            box.addEventListener('click', function () {
                api('toggle', { id: t.id }).then(function (r) {
                    if (!r.ok) return;
                    t.done = r.done;
                    self.load();
                    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
                });
            });
            row.appendChild(box);
        }

        var main = el('div', 'td-main');
        var title = el('input', 'td-title');
        title.type = 'text';
        title.value = t.title;
        title.maxLength = 300;
        title.placeholder = 'Cosa c\'è da fare';
        /* Il titolo si salva senza ridisegnare l'elenco: ricostruire le righe
           mentre si scrive faceva perdere il fuoco al campo a ogni pausa. */
        title.addEventListener('input', function () {
            self.later('t' + t.id, 700, function () {
                t.title = title.value;
                self.save(t, { title: title.value }, true);
            });
        });
        title.addEventListener('blur', function () {
            t.title = title.value;
            /* uscendo dal campo si può ridisegnare: se è rimasto vuoto
               l'attività va tolta dall'elenco */
            self.save(t, { title: title.value }, title.value.trim() !== '');
        });
        title.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); title.blur(); self.addNew(isSub ? t.parent_id : null); }
        });
        main.appendChild(title);
        if (!this.compact) {
            main.appendChild(this.toolsWhen(t));    // data, ora, durata
            main.appendChild(this.toolsProject(t)); // progetto, sulla sua riga
        }
        row.appendChild(main);
        item.appendChild(row);

        /* cestino nascosto: compare scorrendo la riga verso sinistra */
        var trash = el('button', 'td-swipe');
        trash.type = 'button';
        trash.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
        trash.addEventListener('click', function () {
            api('delete', { id: t.id }).then(function () { self.load(); });
        });
        item.appendChild(trash);
        this.enableSwipe(item, row);
        return item;
    };

    /* scorrendo la riga verso sinistra compare il cestino; scorrendo indietro
       (o toccando altrove) si richiude */
    Todo.prototype.enableSwipe = function (item, row) {
        var self = this;
        var x0 = null, y0 = 0;
        function closeOthers() {
            var open = self.list.querySelectorAll('.td-item.td-swiped');
            for (var i = 0; i < open.length; i++) if (open[i] !== item) open[i].classList.remove('td-swiped');
        }
        row.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
        }, { passive: true });
        row.addEventListener('touchmove', function (e) {
            if (x0 === null) return;
            var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
            if (Math.abs(dx) < Math.abs(dy)) return;
            if (dx > 28) { closeOthers(); item.classList.add('td-swiped'); }
            if (dx < -28) item.classList.remove('td-swiped');
        }, { passive: true });
        row.addEventListener('touchend', function () { x0 = null; }, { passive: true });
    };

    /* il progetto sta su una riga tutta sua */
    Todo.prototype.toolsProject = function (t) {
        var self = this;
        var box = el('div', 'td-tools');
        if (this.scope === 'project') return box;
        var projChip = el('button', 'td-chip' + (t.project_id ? ' td-set' : ''));
        projChip.type = 'button';
        if (t.project_id) {
            var dot = el('span', 'td-dot');
            dot.style.background = t.project_color || '#B7AEA3';
            projChip.appendChild(dot);
            projChip.appendChild(el('span', null, t.project_name || 'Progetto'));
        } else {
            projChip.appendChild(el('span', null, 'Nessun progetto'));
        }
        projChip.addEventListener('click', function () { self.pickProject(t); });
        box.appendChild(projChip);
        return box;
    };

    /* data, ora e durata sulla loro riga: si toccano e si cambiano lì */
    Todo.prototype.toolsWhen = function (t) {
        var self = this;
        var box = el('div', 'td-tools');

        var dateChip = el('label', 'td-chip' + (t.date ? ' td-set' : ''));
        dateChip.innerHTML = ICON_CAL;
        var dateIn = el('input');
        dateIn.type = 'date';
        dateIn.value = t.date || '';
        dateIn.addEventListener('change', function () {
            self.save(t, { date: dateIn.value });
        });
        dateChip.appendChild(dateIn);
        if (t.date) {
            var d = parseIso(t.date);
            dateChip.appendChild(el('span', null, d.getDate() + ' ' + MESI[d.getMonth()]));
            /* Il campo resta sopra tutto il chip, invisibile ma cliccabile:
               prima lo riducevo a larghezza zero e da computer la data, una
               volta messa, non si poteva più cambiare. */
            dateIn.classList.add('td-input-cover');
        }
        box.appendChild(dateChip);

        var timeChip = el('label', 'td-chip' + (t.time ? ' td-set' : ''));
        timeChip.innerHTML = ICON_CLOCK;
        var timeIn = el('input');
        timeIn.type = 'time';
        timeIn.step = '300';
        timeIn.value = t.time || '';
        timeIn.addEventListener('change', function () { self.save(t, { time: timeIn.value }); });
        timeChip.appendChild(timeIn);
        box.appendChild(timeChip);

        if (t.time) {
            var durChip = el('label', 'td-chip td-set');
            durChip.innerHTML = ICON_HOUR;
            var durIn = el('input');
            durIn.type = 'number'; durIn.min = '1'; durIn.max = '1440';
            durIn.value = t.minutes || 30;
            durIn.addEventListener('change', function () { self.save(t, { minutes: durIn.value }); });
            durChip.appendChild(durIn);
            durChip.appendChild(el('span', null, 'min'));
            box.appendChild(durChip);
        }

        return box;
    };

    Todo.prototype.pickProject = function (t) {
        var self = this;
        var modal = document.getElementById('ps-modal-todo');
        if (modal && window.psOpen) {
            self._pending = t;
            window.psOpen(modal);
            return;
        }
        /* senza il selettore progetti sulla pagina, si toglie il collegamento */
        if (t.project_id) self.save(t, { project_id: 0 });
    };

    Todo.prototype.save = function (t, changes, keepFocus) {
        var self = this;
        var payload = {
            id: t.id,
            title: changes.title !== undefined ? changes.title : t.title,
            parent_id: t.parent_id || 0,
            project_id: changes.project_id !== undefined ? changes.project_id : (t.project_id || 0),
            date: changes.date !== undefined ? changes.date : (t.date || ''),
            time: changes.time !== undefined ? changes.time : (t.time || ''),
            minutes: changes.minutes !== undefined ? changes.minutes : (t.minutes || 30),
            source: this.scope === 'pomodoro' ? 'pomodoro' : (this.scope === 'project' ? 'project' : 'page')
        };
        return api('save', payload).then(function (r) {
            if (!r.ok) return;
            if (r.todo) {
                /* teniamo aggiornata la copia in memoria senza toccare il DOM */
                for (var k in r.todo) if (r.todo.hasOwnProperty(k)) t[k] = r.todo[k];
            }
            if (!keepFocus) self.load();
        });
    };

    Todo.prototype.addNew = function (parentId) {
        var self = this;
        /* nasce senza titolo: è il segnaposto a suggerire cosa scrivere,
           così non bisogna cancellare "Nuova attività" ogni volta */
        var payload = {
            id: 0, title: ' ', parent_id: parentId || 0,
            project_id: this.scope === 'project' ? this.projectId : (this.opts.defaultProjectId || 0),
            date: this.opts.defaultDate || '', time: this.opts.defaultTime || '',
            minutes: 30,
            source: this.scope === 'pomodoro' ? 'pomodoro' : (this.scope === 'project' ? 'project' : 'page')
        };
        api('save', payload).then(function (r) {
            if (!r.ok) return;
            var newId = r.todo ? r.todo.id : 0;
            if (newId && self.scope === 'pomodoro') {
                self.sessionIds = self.sessionIds || [];
                self.sessionIds.push(newId);
            }
            self.load().then(function () {
                var inputs = self.list.querySelectorAll('.td-title');
                for (var i = 0; i < inputs.length; i++) {
                    if (!inputs[i].value.trim()) { inputs[i].focus(); break; }
                }
            });
        });
    };

    Todo.prototype.toggleSelect = function () {
        this.selecting = !this.selecting;
        this.picked = {};
        if (this.selBtn) {
            this.selBtn.textContent = this.selecting ? 'Fine' : 'Seleziona';
            this.selBtn.classList.toggle('td-on', this.selecting);
        }
        this.render();
    };

    Todo.prototype.bulkBar = function () {
        var self = this;
        var bar = el('div', 'td-bulk');

        var when = el('button');
        when.type = 'button';
        when.title = 'Data e ora';
        when.innerHTML = ICON_CAL;
        when.addEventListener('click', function () { self.bulkSchedule(); });

        var proj = el('button');
        proj.type = 'button';
        proj.appendChild(el('span', null, 'Progetto'));
        proj.addEventListener('click', function () {
            var modal = document.getElementById('ps-modal-todo');
            if (modal && window.psOpen) { self._pendingBulk = true; window.psOpen(modal); }
        });

        var del = el('button', 'td-danger');
        del.type = 'button';
        del.title = 'Elimina';
        del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
        del.addEventListener('click', function () {
            var ids = Object.keys(self.picked).map(Number);
            if (!ids.length) return;
            api('bulk', { action: 'delete', ids: ids }).then(function () {
                self.picked = {}; self.load();
            });
        });

        bar.appendChild(when); bar.appendChild(proj); bar.appendChild(del);
        var n = Object.keys(this.picked).length;
        bar.appendChild(el('span', 'td-count', n ? n + ' scelte' : 'nessuna scelta'));
        return bar;
    };

    Todo.prototype.bulkSchedule = function () {
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        if (!ids.length) return;

        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', ids.length + (ids.length === 1 ? ' attività' : ' attività')));
        card.appendChild(el('div', 'td-card-s', 'Stessa data e ora per tutte. Lascia vuoto per togliere.'));

        function field(label, type, value) {
            var r = el('label', 'td-field');
            r.appendChild(el('span', null, label));
            var i = el('input');
            i.type = type;
            if (type === 'time') i.step = '300';
            if (type === 'number') { i.min = '1'; i.max = '1440'; }
            i.value = value;
            r.appendChild(i);
            card.appendChild(r);
            return i;
        }
        var dateIn = field('Data', 'date', this.today);
        var timeIn = field('Ora', 'time', '');
        var durIn  = field('Durata (min)', 'number', 30);

        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var yes = el('button', 'td-primary', 'Applica');
        yes.type = 'button';
        yes.addEventListener('click', function () {
            api('bulk', {
                action: 'schedule', ids: ids,
                date: dateIn.value, time: timeIn.value,
                minutes: parseInt(durIn.value, 10) || 30
            }).then(function (r) {
                ov.remove();
                if (!r.ok) return;
                self.picked = {};
                self.load();
            });
        });
        acts.appendChild(no); acts.appendChild(yes);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* il selettore progetti condiviso comunica così */
    document.addEventListener('projectselected', function (e) {
        if (!e.detail || e.detail.uid !== 'todo') return;
        var inst = window.nisamaTodo._last;
        if (!inst) return;
        var pid = e.detail.id > 0 ? e.detail.id : 0;
        if (inst._pendingBulk) {
            inst._pendingBulk = false;
            var ids = Object.keys(inst.picked).map(Number);
            if (ids.length) api('bulk', { action: 'project', ids: ids, project_id: pid })
                .then(function () { inst.picked = {}; inst.load(); });
            return;
        }
        if (inst._pending) {
            var t = inst._pending; inst._pending = null;
            inst.save(t, { project_id: pid });
        }
    });

    window.nisamaTodo = {
        mount: function (host, opts) {
            var inst = new Todo(host, opts);
            this._last = inst;
            return inst;
        },
        api: api
    };
})();
</script>
<?php endif; ?>