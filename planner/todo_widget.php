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
           selectable: true,       // modalità seleziona (pagina principale)
           tabs: true              // to do / to buy / to study (solo scope 'all')
       });

   Stessa estetica di planner e diario: carta calda, matita, angoli morbidi.
   ===================================================================== */
if (!defined('NISAMA_TODO_WIDGET')):
define('NISAMA_TODO_WIDGET', true);
if (!function_exists('sec_csrf_token')) { require_once __DIR__ . '/security_core.php'; }
?>
<?php
/* Il componente puo' comparire piu' volte nella stessa pagina (todo.php, i
   progetti, il pomodoro): il foglio si carica una volta sola. */
if (!defined('TODO_WIDGET_CSS')) {
    define('TODO_WIDGET_CSS', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . 'todo_widget.css?v=' . (@filemtime(dirname(__FILE__) . '/todo_widget.css') ?: 1) . '">' . "\n";
    echo nisama_i18n_json(['comune', 'todo']);
}
?>

<script>
(function () {
    if (window.nisamaTodo) return;

    var CSRF = <?php echo json_encode(sec_csrf_token()); ?>;
    var MESI = ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'];
    /* La quarta linguetta non è un quarto elenco di attività: è la sezione
       delle pastiglie, che ha dati suoi (terapie e prese) e un disegno suo.
       Sta qui perché è qui che uno guarda la mattina, insieme al resto della
       giornata, non in una pagina che si ricorderebbe di aprire solo dopo
       essersene dimenticato. */
    var TIPI = ['todo', 'buy', 'study', 'pills'];
    var TIPO_LAB = { todo: 'to do', buy: 'to buy', study: 'to study', pills: 'pastiglie' };
    var IMG_DIR = 'uploads/tobuy/';

    function api(action, data, query) {
        var url = 'todo_api.php?ajax=' + encodeURIComponent(action);
        if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
        var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF } };
        if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
        return fetch(url, opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    /* L'immagine viaggia come multipart: un file dentro il JSON andrebbe
       codificato in base64, cioè un terzo di peso in più per niente. */
    function apiFile(action, form) {
        return fetch('todo_api.php?ajax=' + encodeURIComponent(action), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.NISAMA_CSRF || CSRF },
            body: form
        }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }
    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined && txt !== null) n.textContent = txt;
        return n;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parseIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    /* I prezzi si contano in centesimi e si scrivono in euro solo qui, in un
       posto solo: se ogni punto facesse la sua divisione i totali finirebbero
       per non tornare con le righe. */
    function euro(cents) {
        var v = (Math.round(cents || 0) / 100).toFixed(2);
        return v.replace('.', ',') + ' €';
    }

    var ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    var ICON_CAL   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>';
    var ICON_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>';
    var ICON_HOUR  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 3h10M7 21h10"/><path d="M8 3c0 4 8 5 8 9s-8 5-8 9"/></svg>';
    var ICON_PLUS  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    var ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    var ICON_TAG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11V4h7l9 9-7 7-9-9z"/><circle cx="8" cy="8" r="1.2"/></svg>';
    var ICON_FLAG  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 21V4h12l-2.5 4L18 12H6"/></svg>';
    var ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 13a4 4 0 0 0 6 .5l2-2a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 11a4 4 0 0 0-6-.5l-2 2A4 4 0 0 0 11.7 18l1-1"/></svg>';
    var ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="14" rx="2.6"/><path d="M4 16l4.5-4 3.5 3 3-2.5L20 16"/><circle cx="9" cy="9.5" r="1.1"/></svg>';

    /* ---------------- una istanza montata ---------------- */
    function Todo(host, opts) {
        this.host = host;
        this.opts = opts || {};
        this.scope = this.opts.scope || 'all';
        this.projectId = this.opts.projectId || 0;
        this.compact = !!this.opts.compact;
        this.selectable = !!this.opts.selectable;
        /* le linguette hanno senso solo sulla pagina intera: dentro un
           progetto o nel pomodoro esiste solo il to do */
        this.tabs = !!this.opts.tabs && this.scope === 'all';
        this.listType = 'todo';
        if (this.tabs) {
            try {
                var memo = localStorage.getItem('nisama_todo_tab');
                if (TIPI.indexOf(memo) >= 0) this.listType = memo;
            } catch (e) {}
            /* Chi arriva da fuori dice su quale elenco vuole aprire, e vince
               sull'ultimo usato: se dal planner si tocca «apri» stando sulle
               pastiglie, ritrovarsi sul to do perché era quello di ieri
               sarebbe un salto che non ha chiesto nessuno. */
            if (this.opts.tab && TIPI.indexOf(this.opts.tab) >= 0) this.listType = this.opts.tab;
        }
        this.items = [];
        this.cats = [];
        this.sessionIds = [];
        this.picked = {};
        this.selecting = false;
        this.timers = {};
        /* righe che si stanno modificando adesso e fotografia dell'ordine:
           vedi congela() più sotto */
        this.edited = {};
        this.frozen = null;
        this.build();
        this.load();
    }

    Todo.prototype.build = function () {
        var self = this;
        this.host.innerHTML = '';
        this.wrap = el('div', 'td-wrap');

        if (this.tabs) {
            this.tabBar = el('div', 'td-tabs');
            this.tabBtns = {};
            TIPI.forEach(function (t) {
                var b = el('button', 'td-tab' + (t === self.listType ? ' td-on' : ''), TIPO_LAB[t]);
                b.type = 'button';
                b.addEventListener('click', function () { self.setType(t); });
                self.tabBtns[t] = b;
                self.tabBar.appendChild(b);
            });
            this.wrap.appendChild(this.tabBar);
        }

        if (this.selectable) {
            var head = el('div', 'td-head');
            this.headLab = el('span', 'td-lab', this.tabs ? TIPO_LAB[this.listType] : 'Attività');
            head.appendChild(this.headLab);
            this.selBtn = el('button', 'td-sel-btn', 'Seleziona');
            this.selBtn.type = 'button';
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

    /* Cambiare linguetta è come cambiare pagina: la selezione in corso non ha
       più senso, perché le azioni di gruppo si applicherebbero a righe che
       non si stanno più guardando. */
    Todo.prototype.setType = function (t) {
        if (this.listType === t) return;
        this.lasciaCampo();
        this.listType = t;
        this.picked = {};
        this.selecting = false;
        if (this.selBtn) { this.selBtn.textContent = 'Seleziona'; this.selBtn.classList.remove('td-on'); }
        if (this.headLab) this.headLab.textContent = TIPO_LAB[t];
        for (var k in this.tabBtns) if (this.tabBtns.hasOwnProperty(k)) {
            this.tabBtns[k].classList.toggle('td-on', k === t);
        }
        try { localStorage.setItem('nisama_todo_tab', t); } catch (e) {}
        this.items = [];
        this.render();
        this.load();
    };

    /* Prima di cambiare vista si lascia la casella aperta: il fuoco fa da
       freno al ridisegno, e in più è uscendo dal titolo che un'attività
       rimasta senza nome viene tolta. Senza questo, cambiando linguetta con il
       cursore ancora dentro, l'elenco restava quello di prima. */
    Todo.prototype.lasciaCampo = function () {
        var a = document.activeElement;
        if (a && this.wrap && this.wrap.contains(a) && a.blur) a.blur();
    };

    /* -----------------------------------------------------------------
       ORDINE CONGELATO MENTRE SI MODIFICA

       Cambiare la data di un'attività la manda in cima; cambiarle categoria o
       priorità la manda in un altro gruppo. Se succede nell'istante del tocco,
       la riga sparisce da sotto il dito e per fare la modifica successiva
       bisogna ricercarla in mezzo alle altre.

       Qui, al primo cambiamento, l'elenco viene fotografato com'è: posizione,
       categoria e priorità di ogni riga. Da quel momento il disegno usa la
       fotografia, non i dati veri — la riga si aggiorna ma resta dov'è, con lo
       sfondo diverso a dire "questa non è in ordine con le altre, la stai
       modificando". Toccando fuori la fotografia si strappa e l'ordine vero
       torna, con tutto al suo posto.
       ----------------------------------------------------------------- */
    Todo.prototype.congela = function () {
        if (this.frozen) return;
        var f = {};
        this.items.forEach(function (t, i) {
            f[String(t.id)] = { i: i, cat: t.cat_id, prio: t.priority || 3 };
        });
        this.frozen = f;
    };

    Todo.prototype.inModifica = function (id) {
        if (!id) return;
        this.congela();
        this.edited[String(id)] = true;
        /* il colore si mette subito sulla riga che è già in pagina: scrivendo
           nel titolo il ridisegno non arriva (la casella è in uso) e senza
           questo la riga resterebbe uguale alle altre proprio mentre la si
           sta modificando */
        var riga = this.list && this.list.querySelector('.td-item[data-id="' + id + '"]');
        if (riga) riga.classList.add('td-in-modifica');
        this.ascoltaFuori();
    };

    /* Fuori dalla riga, ma non dentro una nostra finestra: mentre si sceglie
       una data o una categoria si sta ancora modificando quella riga, e
       liberare l'ordine lì in mezzo sarebbe il difetto di prima con un passo
       in più. */
    Todo.prototype.ascoltaFuori = function () {
        if (this._fuori) return;
        var self = this;
        this._fuori = function (e) {
            if (!self.frozen || !e.target || !e.target.closest) return;
            if (e.target.closest('.td-modal')) return;
            var riga = e.target.closest('.td-item');
            if (riga && riga.dataset.id && self.edited[riga.dataset.id]) return;
            self.liberaOrdine();
        };
        document.addEventListener('pointerdown', this._fuori, true);
    };

    Todo.prototype.liberaOrdine = function () {
        if (!this.frozen) return;
        this.frozen = null;
        this.edited = {};
        if (this._fuori) {
            document.removeEventListener('pointerdown', this._fuori, true);
            this._fuori = null;
        }
        /* si rilegge dal server: l'ordine vero lo decide lui, non noi */
        this.load();
    };

    /* Durante il congelamento questi tre dicono dove va disegnata una riga.
       Il contenuto mostra i dati nuovi: si sposta solo il posto, non il testo. */
    Todo.prototype.ordina = function (list) {
        if (!this.frozen) return list;
        var f = this.frozen;
        return list.slice().sort(function (a, b) {
            var pa = f[String(a.id)] ? f[String(a.id)].i : 9e9;
            var pb = f[String(b.id)] ? f[String(b.id)].i : 9e9;
            return pa - pb;
        });
    };
    Todo.prototype.catDi = function (t) {
        var f = this.frozen && this.frozen[String(t.id)];
        return f ? f.cat : t.cat_id;
    };
    Todo.prototype.prioDi = function (t) {
        var f = this.frozen && this.frozen[String(t.id)];
        return f ? f.prio : (t.priority || 3);
    };

    Todo.prototype.later = function (key, ms, fn) {
        var self = this;
        if (this.timers[key]) clearTimeout(this.timers[key].id);
        /* si tiene anche la funzione e non solo il numero del rinvio: senza,
           svuotaRinvii() potrebbe soltanto annullarli, e annullare il
           salvataggio di un titolo è esattamente il modo di perderlo */
        this.timers[key] = {
            fn: fn,
            id: setTimeout(function () { self.timers[key] = null; fn(); }, ms)
        };
    };

    /* Fa scattare SUBITO tutti i rinvii in attesa invece di aspettarli.
       Serve prima di creare una voce nuova: il salvataggio del titolo che si
       stava scrivendo è dentro un rinvio da 700ms, e partire senza aspettarlo
       vuol dire scavalcarlo. */
    Todo.prototype.svuotaRinvii = function () {
        for (var k in this.timers) {
            if (!this.timers.hasOwnProperty(k) || !this.timers[k]) continue;
            var t = this.timers[k];
            this.timers[k] = null;
            clearTimeout(t.id);
            try { t.fn(); } catch (e) {}
        }
    };

    /* L'ultimo salvataggio partito, così chi deve accodarsi può aspettarlo.
       Sempre una promessa, anche quando non c'è niente in volo: chi chiama non
       deve controllare ogni volta se esiste. */
    Todo.prototype.attesaSalvataggi = function () {
        return this._inVolo || Promise.resolve();
    };

    Todo.prototype.load = function () {
        var self = this;
        /* le pastiglie hanno le loro tabelle: non passano dall'elenco attività */
        if (this.listType === 'pills') return this.loadPills();
        var q = { scope: this.scope, project_id: this.projectId, list_type: this.listType };
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
                    el('div', 'td-empty', T('todo.elenco_non_caricato')),
                    self.list.firstChild);
                return;
            }
            /* la risposta di una linguetta che nel frattempo è stata lasciata
               non deve sovrascrivere quella che si sta guardando adesso */
            if (r.list_type && r.list_type !== self.listType) return;
            self.items = r.todos;
            if (r.categories) self.cats = r.categories;
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

    /* C'è una casella in mano all'utente? Titolo, prezzo, link, data, orario:
       finché il cursore sta là dentro, ridisegnare vuol dire distruggere quella
       casella mentre la si sta usando — si perde quello che si stava scrivendo
       e, con il riordino, anche la riga di vista. I pulsanti non contano: lì il
       tocco è già finito. */
    Todo.prototype.campoInMano = function () {
        var a = document.activeElement;
        if (!a || !this.wrap || !this.wrap.contains(a)) return false;
        return a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' ||
               a.tagName === 'SELECT' || a.isContentEditable === true;
    };

    /* Ridisegno e rilettura non sono mai istantanei sotto il dito: mezzo
       secondo di respiro fa vedere la modifica al suo posto prima che la riga
       salti altrove per via del riordino. */
    Todo.prototype.renderLater = function (ms) {
        var self = this;
        this.later('render', ms || 450, function () { self.render(); });
    };
    Todo.prototype.loadLater = function (ms) {
        var self = this;
        this.later('render', ms || 450, function () { self.load(); });
    };

    Todo.prototype.render = function () {
        var self = this;
        /* Il ridisegno non si perde: si segna e si fa appena la casella viene
           lasciata (vedi l'ascoltatore su 'focusout' qui sotto). Nel frattempo
           i dati nuovi sono già in memoria, quindi quando arriva è quello
           giusto e non uno vecchio. */
        if (this.campoInMano()) { this._daRidisegnare = true; return; }
        this._daRidisegnare = false;
        if (!this._blurBound && this.wrap) {
            this._blurBound = true;
            this.wrap.addEventListener('focusin', function (e) {
                var riga = e.target.closest ? e.target.closest('.td-item') : null;
                if (riga && riga.dataset.id) self.inModifica(riga.dataset.id);
            });
            this.wrap.addEventListener('focusout', function () {
                /* piccola attesa: passando da una casella all'altra il fuoco
                   esce e rientra, e non ha senso ridisegnare nel mezzo */
                self.later('blur', 300, function () {
                    if (!self.campoInMano() && self._daRidisegnare) self.render();
                });
            });
        }
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

        /* prima della selezione multipla: qui non si seleziona niente */
        if (this.listType === 'pills') { this.renderPills(); return; }

        if (this.selecting) this.list.appendChild(this.bulkBar());
        if (this.listType === 'buy') { this.renderBuy(); return; }

        var roots = this.ordina(this.items).filter(function (t) { return !t.parent_id; });
        /* Un "+" anche in cima, oltre a quello in fondo. Con venti attività in
           elenco il tasto in fondo è lontano uno schermo e mezzo, e aggiungere
           una cosa nuova è il gesto più frequente di tutti: deve stare dove il
           pollice è già. Compare solo quando l'elenco è abbastanza lungo da
           rendere scomodo quello in fondo — sotto le cinque righe due tasti
           uguali a due dita di distanza sarebbero solo confusione. */
        if (roots.length >= 5 && !this.selecting) this.list.appendChild(this.addButton(true));
        if (!roots.length) this.list.appendChild(el('div', 'td-empty', this.emptyText()));

        roots.forEach(function (t) {
            self.list.appendChild(self.row(t, false));
            self.items.filter(function (k) { return k.parent_id === t.id; })
                      .forEach(function (k) { self.list.appendChild(self.row(k, true)); });
        });

        this.list.appendChild(this.addButton());
    };

    Todo.prototype.emptyText = function () {
        if (this.listType === 'buy') return T('todo.niente_da_comprare');
        if (this.listType === 'study') return T('todo.niente_da_studiare');
        return T('todo.niente_da_fare');
    };

    Todo.prototype.addButton = function (inCima) {
        var self = this;
        var lab = this.listType === 'buy' ? T('todo.nuovo_articolo')
                : (this.listType === 'study' ? T('todo.nuovo_argomento') : T('todo.nuova_attivita'));
        var add = el('button', 'td-add' + (inCima ? ' td-add-top' : ''));
        add.type = 'button';
        add.innerHTML = ICON_PLUS;
        add.appendChild(el('span', null, lab));
        add.addEventListener('click', function () { self.addNew(null); });
        return add;
    };

    /* ---------------- to buy ----------------
       Raggruppato per categoria e, dentro, per priorità. Il totale si calcola
       qui e non sul server perché è lo stesso conto che serve, in diretta, per
       la selezione: un solo modo di sommare, un solo risultato possibile. */
    Todo.prototype.catById = function (id) {
        for (var i = 0; i < this.cats.length; i++) if (this.cats[i].id === id) return this.cats[i];
        return null;
    };
    function sumCents(list) {
        var n = 0;
        list.forEach(function (t) { n += t.cents || 0; });
        return n;
    }

    Todo.prototype.renderBuy = function () {
        var self = this;
        var roots = this.ordina(this.items).filter(function (t) { return !t.parent_id; });

        var manage = el('div', 'td-head');
        var b = el('button', 'td-sel-btn', 'Categorie');
        b.type = 'button';
        b.addEventListener('click', function () { self.catManager(); });
        manage.appendChild(el('span', 'td-lab', roots.length ? roots.length + ' articoli' : ''));
        manage.appendChild(b);
        this.list.appendChild(manage);

        if (!roots.length) {
            this.list.appendChild(el('div', 'td-empty', this.emptyText()));
            this.list.appendChild(this.addButton());
            return;
        }

        /* i gruppi seguono l'ordine delle categorie; quelli senza categoria
           chiudono la fila, così non si mettono davanti a niente */
        var gruppi = [];
        this.cats.forEach(function (c) {
            var inCat = roots.filter(function (t) { return self.catDi(t) === c.id; });
            if (inCat.length) gruppi.push({ cat: c, items: inCat });
        });
        var senza = roots.filter(function (t) { return !self.catDi(t) || !self.catById(self.catDi(t)); });
        if (senza.length) gruppi.push({ cat: null, items: senza });

        gruppi.forEach(function (g) {
            var box = el('div', 'td-group');
            var head = el('div', 'td-ghead');
            if (g.cat) {
                var dot = el('span', 'td-dot');
                dot.style.background = g.cat.color || '#B7AEA3';
                head.appendChild(dot);
            }
            head.appendChild(el('div', 'td-gname', g.cat ? g.cat.name : T('todo.senza_categoria')));
            head.appendChild(el('div', 'td-gtot', euro(sumCents(g.items))));
            /* Il "+" sull'intestazione: l'articolo nasce già dentro questa
               categoria. Prima si poteva solo aggiungerlo in fondo, fra quelli
               senza categoria, e poi spostarcelo — due passaggi per una cosa
               che si sa già mentre si guarda il gruppo giusto. */
            if (g.cat) {
                var piu = el('button', 'td-gadd');
                piu.type = 'button';
                piu.innerHTML = ICON_PLUS;
                piu.setAttribute('aria-label', T('todo.nuovo_articolo'));
                piu.addEventListener('click', function () { self.addNew(null, { cat_id: g.cat.id }); });
                head.appendChild(piu);
            }
            box.appendChild(head);

            [1, 2, 3].forEach(function (p) {
                var inPrio = g.items.filter(function (t) { return self.prioDi(t) === p; });
                if (!inPrio.length) return;
                var ph = el('div', 'td-phead');
                ph.appendChild(el('span', 'td-plab', T('todo.priorita_n', {n: p})));
                ph.appendChild(el('span', 'td-line'));
                ph.appendChild(el('span', 'td-ptot', euro(sumCents(inPrio))));
                box.appendChild(ph);
                inPrio.forEach(function (t) { box.appendChild(self.buyRow(t)); });
            });
            self.list.appendChild(box);
        });

        var tot = el('div', 'td-total');
        tot.appendChild(el('span', null, 'totale'));
        tot.appendChild(el('b', null, euro(sumCents(roots))));
        this.list.appendChild(tot);
        this.list.appendChild(this.addButton());
    };

    Todo.prototype.buyRow = function (t) {
        var self = this;
        var item = el('div', 'td-item td-buy' + (this.edited[String(t.id)] ? ' td-in-modifica' : ''));
        item.dataset.id = t.id;
        var row = el('div', 'td-row' + (t.done ? ' td-done' : ''));

        if (this.selecting) {
            row.appendChild(this.pickBox(t));
        } else {
            row.appendChild(this.checkBox(t));
        }

        /* l'immagine: si tocca e si sceglie il file, si tiene premuto (o si
           tocca da spuntata) per toglierla */
        var thumb = el('button', 'td-thumb' + (t.image ? ' td-has' : ''));
        thumb.type = 'button';
        thumb.innerHTML = ICON_IMG;
        if (t.image) thumb.style.backgroundImage = 'url("' + IMG_DIR + encodeURIComponent(t.image) + '")';
        thumb.addEventListener('click', function () { self.pickImage(t, thumb); });
        row.appendChild(thumb);

        var main = el('div', 'td-main');
        main.appendChild(this.titleInput(t, false));

        var tools = el('div', 'td-tools');
        tools.appendChild(this.priceChip(t));
        tools.appendChild(this.prioChip(t));
        tools.appendChild(this.catChip(t));
        tools.appendChild(this.trashChip(t));
        main.appendChild(tools);

        /* il link sta sotto l'immagine, come chiesto: si incolla e basta */
        var lk = el('div', 'td-buylink');
        lk.appendChild(el('span', null, '↳'));
        var url = el('input');
        url.type = 'url';
        url.value = t.url || '';
        url.placeholder = 'link dell\'articolo';
        url.addEventListener('change', function () {
            self.save(t, { url: url.value }, true).then(function () { self.renderLater(); });
        });
        lk.appendChild(url);
        if (t.url) {
            var open = el('a');
            open.href = t.url;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.title = 'Apri';
            open.innerHTML = ICON_LINK;
            lk.appendChild(open);
        }
        main.appendChild(lk);

        row.appendChild(main);
        item.appendChild(row);
        item.appendChild(this.swipeTrash(t));
        this.enableSwipe(item, row);
        return item;
    };

    Todo.prototype.priceChip = function (t) {
        var self = this;
        var chip = el('label', 'td-chip td-price' + (t.cents !== null && t.cents !== undefined ? ' td-set' : ''));
        var inp = el('input');
        inp.type = 'text';
        inp.inputMode = 'decimal';
        inp.placeholder = '0,00';
        inp.value = (t.cents !== null && t.cents !== undefined) ? (t.cents / 100).toFixed(2).replace('.', ',') : '';
        inp.addEventListener('change', function () {
            self.save(t, { price: inp.value }, true).then(function () { self.renderLater(); });
        });
        chip.appendChild(inp);
        chip.appendChild(el('span', null, '€'));
        return chip;
    };

    /* La priorità gira toccando: da 1 a 3 e daccapo. Con tre soli valori un
       popup sarebbe più lento del gesto che deve sostituire. */
    Todo.prototype.prioChip = function (t) {
        var self = this;
        /* Di partenza 3, cioè l'ultima: quello che conta davvero lo si alza a
           mano, mentre marcare tutto come importante non direbbe più niente. */
        var p = t.priority || 3;
        var chip = el('button', 'td-chip td-prio-' + p);
        chip.type = 'button';
        chip.title = T('todo.priorita');
        chip.innerHTML = ICON_FLAG;
        chip.appendChild(el('span', null, String(p)));
        chip.addEventListener('click', function () {
            var next = p >= 3 ? 1 : p + 1;
            self.save(t, { priority: next }, true).then(function () { self.renderLater(); });
        });
        return chip;
    };

    Todo.prototype.catChip = function (t) {
        var self = this;
        var c = t.cat_id ? this.catById(t.cat_id) : null;
        var chip = el('button', 'td-chip' + (c ? ' td-set' : ''));
        chip.type = 'button';
        if (c) {
            var dot = el('span', 'td-dot');
            dot.style.background = c.color || '#B7AEA3';
            chip.appendChild(dot);
            chip.appendChild(el('span', null, c.name));
        } else {
            chip.innerHTML = ICON_TAG;
            chip.appendChild(el('span', null, 'Categoria'));
        }
        chip.addEventListener('click', function () {
            self.catPicker(function (id) {
                self.save(t, { cat_id: id }, true).then(function () { self.renderLater(); });
            });
        });
        return chip;
    };

    /* Il file si sceglie con un campo creato al momento e buttato subito
       dopo: tenerne uno per riga significherebbe decine di campi nascosti
       nella pagina, tutti uguali e tutti inutili tranne uno. */
    /* Toccando la miniatura si guarda l'immagine per quello che è, grande
       quanto lo schermo lo permette: da un quadratino di 44 pixel non si
       capisce se l'articolo è quello giusto. I due comandi stanno sotto, fuori
       dall'immagine, per non coprirne un pezzo proprio mentre la si guarda. */
    Todo.prototype.pickImage = function (t, thumb) {
        var self = this;
        if (t.image) {
            var ov = el('div', 'td-modal td-modal-img');
            var box = el('div', 'td-imgview');
            var big = document.createElement('img');
            big.src = IMG_DIR + encodeURIComponent(t.image);
            big.alt = (t.title || 'Immagine').trim();
            box.appendChild(big);

            var acts = el('div', 'td-card-acts');
            var rem = el('button', null, 'Togli');
            rem.type = 'button';
            rem.addEventListener('click', function () {
                ov.remove();
                api('image_del', { id: t.id }).then(function (r) {
                    if (!r.ok) return;
                    t.image = null;
                    self.render();
                });
            });
            var chg = el('button', 'td-primary', 'Sostituisci');
            chg.type = 'button';
            chg.addEventListener('click', function () { ov.remove(); self.openFile(t); });
            acts.appendChild(rem); acts.appendChild(chg);
            box.appendChild(acts);

            ov.appendChild(box);
            ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
            return;
        }
        this.openFile(t);
    };

    Todo.prototype.openFile = function (t) {
        var self = this;
        var inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = 'image/jpeg,image/png,image/webp,image/gif';
        inp.style.display = 'none';
        inp.addEventListener('change', function () {
            var f = inp.files && inp.files[0];
            inp.remove();
            if (!f) return;
            var form = new FormData();
            form.append('id', t.id);
            form.append('file', f);
            apiFile('image', form).then(function (r) {
                if (!r.ok) {
                    self.tell(T('todo.immagine_non_caricata'), r.message || T('todo.riprova_file_piccolo'));
                    return;
                }
                t.image = r.image;
                self.render();
            });
        });
        document.body.appendChild(inp);
        inp.click();
    };

    Todo.prototype.tell = function (titolo, testo) {
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', titolo));
        card.appendChild(el('div', 'td-card-s', testo));
        var acts = el('div', 'td-card-acts');
        var ok = el('button', 'td-primary', 'Ho capito');
        ok.type = 'button';
        ok.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(ok);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* ---------------- categorie ---------------- */
    Todo.prototype.catPicker = function (onPick) {
        var self = this;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', 'Categoria'));

        function riga(label, color, id) {
            var b = el('button', 'td-catpick');
            b.type = 'button';
            var dot = el('span', 'td-dot');
            dot.style.background = color || 'transparent';
            if (!color) dot.style.boxShadow = 'inset 0 0 0 1px rgba(74,68,61,.2)';
            b.appendChild(dot);
            b.appendChild(el('span', null, label));
            b.addEventListener('click', function () { ov.remove(); onPick(id); });
            card.appendChild(b);
        }
        riga(T('todo.senza_categoria'), null, 0);
        this.cats.forEach(function (c) { riga(c.name, c.color, c.id); });

        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var mng = el('button', 'td-primary', 'Gestisci');
        mng.type = 'button';
        mng.addEventListener('click', function () { ov.remove(); self.catManager(); });
        acts.appendChild(no); acts.appendChild(mng);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    Todo.prototype.catManager = function () {
        var self = this;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', 'Categorie'));
        card.appendChild(el('div', 'td-card-s', 'Il nome si cambia scrivendoci sopra. Cancellandone una, gli articoli restano senza categoria.'));

        var box = el('div');
        card.appendChild(box);

        function disegna() {
            box.innerHTML = '';
            self.cats.forEach(function (c) {
                var r = el('div', 'td-catrow');
                var col = el('input');
                col.type = 'color';
                col.value = (c.color && /^#[0-9a-f]{6}$/i.test(c.color)) ? c.color : '#B7AEA3';
                var nome = el('input', 'td-cname');
                nome.type = 'text';
                nome.value = c.name;
                nome.maxLength = 60;
                function salva() {
                    if (!nome.value.trim()) { nome.value = c.name; return; }
                    api('cat_save', { id: c.id, name: nome.value, color: col.value }).then(function (r2) {
                        if (!r2.ok) return;
                        self.cats = r2.categories;
                        c.name = nome.value; c.color = col.value;
                    });
                }
                nome.addEventListener('change', salva);
                col.addEventListener('change', salva);
                var del = el('button', 'td-cdel');
                del.type = 'button';
                del.innerHTML = ICON_TRASH;
                del.addEventListener('click', function () {
                    api('cat_delete', { id: c.id }).then(function (r2) {
                        if (!r2.ok) return;
                        self.cats = r2.categories;
                        disegna();
                        self.load();
                    });
                });
                r.appendChild(col); r.appendChild(nome); r.appendChild(del);
                box.appendChild(r);
            });

            /* la riga per aggiungerne una sta in fondo, vuota: si scrive e si
               conferma, senza un secondo popup sopra questo */
            var add = el('div', 'td-catrow');
            var ncol = el('input');
            ncol.type = 'color';
            ncol.value = '#B7AEA3';
            var nnome = el('input', 'td-cname');
            nnome.type = 'text';
            nnome.placeholder = T('todo.nuova_categoria');
            nnome.maxLength = 60;
            function crea() {
                var v = nnome.value.trim();
                if (!v) return;
                api('cat_save', { id: 0, name: v, color: ncol.value }).then(function (r2) {
                    if (!r2.ok) return;
                    self.cats = r2.categories;
                    nnome.value = '';
                    disegna();
                });
            }
            nnome.addEventListener('change', crea);
            var ok = el('button', 'td-cdel');
            ok.type = 'button';
            ok.style.color = 'var(--td-accent)';
            ok.innerHTML = ICON_PLUS;
            ok.addEventListener('click', crea);
            add.appendChild(ncol); add.appendChild(nnome); add.appendChild(ok);
            box.appendChild(add);
        }
        disegna();

        var acts = el('div', 'td-card-acts');
        var fine = el('button', 'td-primary', 'Fine');
        fine.type = 'button';
        fine.addEventListener('click', function () { ov.remove(); self.render(); });
        acts.appendChild(fine);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) { ov.remove(); self.render(); } });
        document.body.appendChild(ov);
    };

    /* ---------------- pezzi comuni alle righe ---------------- */
    Todo.prototype.pickBox = function (t) {
        var self = this;
        var pick = el('span', 'td-pick' + (this.picked[t.id] ? ' td-on' : ''));
        pick.innerHTML = ICON_CHECK;
        pick.addEventListener('click', function () {
            if (self.picked[t.id]) delete self.picked[t.id]; else self.picked[t.id] = true;
            pick.classList.toggle('td-on', !!self.picked[t.id]);
            /* il totale della selezione deve muoversi mentre si sceglie:
               ridisegnare tutto perderebbe la posizione della pagina, così
               si aggiorna solo il contatore */
            self.refreshCount();
        });
        return pick;
    };

    Todo.prototype.checkBox = function (t) {
        var self = this;
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
        return box;
    };

    Todo.prototype.titleInput = function (t, isSub) {
        var self = this;
        var title = el('input', 'td-title');
        title.type = 'text';
        title.value = t.title;
        title.maxLength = 300;
        title.placeholder = this.listType === 'buy' ? T('todo.cosa_vuoi_comprare')
                          : (this.listType === 'study' ? T('todo.cosa_da_studiare') : T('todo.cosa_da_fare'));
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
        return title;
    };

    Todo.prototype.swipeTrash = function (t) {
        var self = this;
        var trash = el('button', 'td-swipe');
        trash.type = 'button';
        trash.innerHTML = ICON_TRASH;
        trash.addEventListener('click', function () {
            api('delete', { id: t.id }).then(function () { self.load(); });
        });
        return trash;
    };

    Todo.prototype.row = function (t, isSub) {
        var item = el('div', 'td-item' + (isSub ? ' td-sub' : '')
                      + (this.edited[String(t.id)] ? ' td-in-modifica' : ''));
        item.dataset.id = t.id;
        var row = el('div', 'td-row'
            + (t.done ? ' td-done' : '') + (this.isLate(t) ? ' td-late' : ''));

        row.appendChild(this.selecting ? this.pickBox(t) : this.checkBox(t));

        var main = el('div', 'td-main');
        main.appendChild(this.titleInput(t, isSub));
        if (!this.compact) {
            main.appendChild(this.toolsWhen(t));    // data, ora, durata
            main.appendChild(this.toolsProject(t)); // progetto, sulla sua riga
        }
        row.appendChild(main);
        item.appendChild(row);

        /* cestino nascosto: compare scorrendo la riga verso sinistra */
        item.appendChild(this.swipeTrash(t));
        this.enableSwipe(item, row);
        return item;
    };

    /* scorrendo la riga verso sinistra compare il cestino; scorrendo indietro
       (o toccando altrove) si richiude */
    Todo.prototype.enableSwipe = function (item, row) {
        var self = this;
        var x0 = null, y0 = 0, pid = null, daCampo = false;
        function closeOthers() {
            var open = self.list.querySelectorAll('.td-item.td-swiped');
            for (var i = 0; i < open.length; i++) if (open[i] !== item) open[i].classList.remove('td-swiped');
        }
        /* Passato ai pointer event: prima era solo touch, quindi da computer il
           cestino non si raggiungeva in nessun modo.

           Il gesto parte da qualunque punto della riga, caselle e pastiglie
           comprese: nel "to buy" la riga è quasi tutta campi (titolo, prezzo,
           categoria, link) e tenendoli fuori restavano due millimetri buoni da
           cui cominciare, che non si azzeccavano mai. Col dito il rischio di
           confusione non c'è: si distingue guardando la direzione. Col mouse,
           invece, i campi di testo restano esclusi, perché lì trascinare vuol
           dire selezionare il testo, non spostare la riga. */
        row.addEventListener('pointerdown', function (e) {
            daCampo = !!e.target.closest('input, textarea');
            if (daCampo && e.pointerType === 'mouse') { x0 = null; return; }
            x0 = e.clientX; y0 = e.clientY; pid = e.pointerId;
        });
        row.addEventListener('pointermove', function (e) {
            if (x0 === null || e.pointerId !== pid) return;
            var dx = e.clientX - x0, dy = e.clientY - y0;
            /* orizzontale dichiarato: scorrendo la pagina in verticale con il
               dito appoggiato su una riga, il cestino non deve spuntare */
            if (Math.abs(dx) < Math.abs(dy) * 1.3) return;
            /* soglia più bassa di prima (28 px erano mezza riga); partendo da
               dentro una casella se ne chiede un po' di più, così un tocco
               storto per posizionare il cursore non apre niente */
            var soglia = daCampo ? 26 : 14;
            if (dx > soglia) { closeOthers(); item.classList.add('td-swiped'); }
            if (dx < -soglia) item.classList.remove('td-swiped');
        });
        function stop() { x0 = null; pid = null; }
        row.addEventListener('pointerup', stop);
        row.addEventListener('pointercancel', stop);
        row.addEventListener('pointerleave', stop);
    };

    /* Il cestino, in chiaro. Il gesto di scorrimento resta, ma non può essere
       l'unica strada: da computer non c'è, e sul telefono capitava di non
       azzeccarlo. Chiede conferma, perché un'attività cancellata non torna. */
    Todo.prototype.trashChip = function (t) {
        var self = this;
        var chip = el('button', 'td-chip td-chip-del');
        chip.type = 'button';
        chip.title = 'Elimina';
        chip.setAttribute('aria-label', T('todo.elimina_attivita'));
        chip.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
        chip.addEventListener('click', function (e) {
            e.stopPropagation();
            self.confirmDelete(t);
        });
        return chip;
    };

    Todo.prototype.confirmDelete = function (t) {
        var self = this;
        var nome = (t.title || '').trim();
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', this.listType === 'buy' ? 'Elimino l\'articolo?' : T('todo.elimino_attivita')));
        card.appendChild(el('div', 'td-card-s', nome ? T('todo.nome_non_recuperabile', {nome: nome}) : T('todo.non_recuperabile')));
        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        var si = el('button', 'td-primary td-danger-btn', 'Elimina');
        si.type = 'button';
        si.addEventListener('click', function () {
            ov.remove();
            api('delete', { id: t.id }).then(function () { self.load(); });
        });
        acts.appendChild(no); acts.appendChild(si);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    /* il progetto sta su una riga tutta sua */
    Todo.prototype.toolsProject = function (t) {
        var self = this;
        var box = el('div', 'td-tools');
        /* Dentro un progetto il chip del progetto non serve, ma il cestino sì:
           prima qui la riga tornava vuota e l'attività non si poteva eliminare
           in nessun modo se non con lo scorrimento. */
        if (this.scope === 'project') { box.appendChild(this.trashChip(t)); return box; }
        var projChip = el('button', 'td-chip' + (t.project_id ? ' td-set' : ''));
        projChip.type = 'button';
        if (t.project_id) {
            var dot = el('span', 'td-dot');
            dot.style.background = t.project_color || '#B7AEA3';
            projChip.appendChild(dot);
            projChip.appendChild(el('span', null, t.project_name || 'Progetto'));
        } else {
            projChip.appendChild(el('span', null, T('todo.nessun_progetto_w')));
        }
        projChip.addEventListener('click', function () { self.pickProject(t); });
        box.appendChild(projChip);
        box.appendChild(this.trashChip(t));
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
        /* qualunque modifica, da qualunque pastiglia: la riga si segna e
           l'elenco smette di riordinarsi finché non si tocca fuori */
        this.inModifica(t.id);
        var self = this;
        var payload = {
            id: t.id,
            list_type: t.list_type || this.listType,
            title: changes.title !== undefined ? changes.title : t.title,
            parent_id: t.parent_id || 0,
            project_id: changes.project_id !== undefined ? changes.project_id : (t.project_id || 0),
            date: changes.date !== undefined ? changes.date : (t.date || ''),
            time: changes.time !== undefined ? changes.time : (t.time || ''),
            minutes: changes.minutes !== undefined ? changes.minutes : (t.minutes || 30),
            source: this.scope === 'pomodoro' ? 'pomodoro' : (this.scope === 'project' ? 'project' : 'page')
        };
        /* I campi del "to buy" si mandano solo se cambiano davvero, e si
           dichiarano in `buy_set`: il server lascia intatto quello che non gli
           viene nominato, così salvare il titolo non azzera prezzo e
           categoria, e svuotare il prezzo resta possibile. */
        var toccati = [];
        if (changes.cat_id !== undefined)   { payload.cat_id = changes.cat_id;     toccati.push('cat_id'); }
        if (changes.priority !== undefined) { payload.priority = changes.priority; toccati.push('priority'); }
        if (changes.price !== undefined)    { payload.price = changes.price;       toccati.push('price'); }
        if (changes.url !== undefined)      { payload.url = changes.url;           toccati.push('url'); }
        if (toccati.length) payload.buy_set = toccati;

        return this._inVolo = api('save', payload).then(function (r) {
            if (!r.ok) return;
            if (r.todo) {
                /* teniamo aggiornata la copia in memoria senza toccare il DOM */
                for (var k in r.todo) if (r.todo.hasOwnProperty(k)) t[k] = r.todo[k];
            }
            /* Rilettura non immediata: è quella che riordina l'elenco, e
               farla scattare nell'istante del tocco fa sparire da sotto il dito
               la riga appena toccata. */
            if (!keepFocus) self.loadLater();
        });
    };

    Todo.prototype.addNew = function (parentId, extra) {
        var self = this;
        /* LE VOCI DEL TO BUY CHE SPARIVANO.
           Era una corsa fra due richieste. Toccando "+" mentre si stava ancora
           scrivendo un titolo partivano insieme il salvataggio del titolo e la
           creazione della voce nuova; se la creazione tornava per prima, la
           rilettura riportava la voce di prima ancora SENZA titolo, e il fuoco
           andava "sulla prima casella vuota" — che era quella di prima, non
           quella nuova. Si scriveva il secondo articolo sopra al primo, e del
           primo non restava niente.
           Due correzioni: si aspetta che il salvataggio in corso sia finito, e
           il fuoco va sulla riga con l'id che il server ha appena restituito,
           non su "una casella vuota qualsiasi". */
        this.svuotaRinvii();
        return this.attesaSalvataggi().then(function () {
            /* nasce senza titolo: è il segnaposto a suggerire cosa scrivere,
               così non bisogna cancellare "Nuova attività" ogni volta */
            var payload = {
                id: 0, title: ' ', parent_id: parentId || 0,
                list_type: self.listType,
                project_id: self.scope === 'project' ? self.projectId : (self.opts.defaultProjectId || 0),
                date: self.opts.defaultDate || '', time: self.opts.defaultTime || '',
                minutes: 30,
                source: self.scope === 'pomodoro' ? 'pomodoro' : (self.scope === 'project' ? 'project' : 'page')
            };
            /* usato dal "+" sull'intestazione di una categoria del to buy: la
               voce nasce già dentro quella categoria invece di finire in fondo
               fra quelle senza, da dove andrebbe poi spostata a mano */
            if (extra && extra.cat_id) { payload.cat_id = extra.cat_id; payload.buy_set = ['cat_id']; }

            return api('save', payload).then(function (r) {
                if (!r.ok) return;
                var newId = r.todo ? r.todo.id : 0;
                if (newId && self.scope === 'pomodoro') {
                    self.sessionIds = self.sessionIds || [];
                    self.sessionIds.push(newId);
                }
                return self.load().then(function () {
                    var riga = newId ? self.list.querySelector('.td-item[data-id="' + newId + '"] .td-title') : null;
                    if (riga) { riga.focus(); return; }
                    // ripiego: se la riga non è a schermo (filtro, gruppo chiuso)
                    var inputs = self.list.querySelectorAll('.td-title');
                    for (var i = 0; i < inputs.length; i++) {
                        if (!inputs[i].value.trim()) { inputs[i].focus(); break; }
                    }
                });
            });
        });
    };

    Todo.prototype.toggleSelect = function () {
        this.lasciaCampo();
        this.selecting = !this.selecting;
        this.picked = {};
        if (this.selBtn) {
            this.selBtn.textContent = this.selecting ? 'Fine' : 'Seleziona';
            this.selBtn.classList.toggle('td-on', this.selecting);
        }
        this.render();
    };

    /* quante ne ho scelte e, nel "to buy", quanto costano: si aggiorna a ogni
       tocco senza ridisegnare l'elenco */
    Todo.prototype.refreshCount = function () {
        if (!this.countBox) return;
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        this.countBox.innerHTML = '';
        if (this.listType === 'buy') {
            var scelti = this.items.filter(function (t) { return self.picked[t.id]; });
            this.countBox.appendChild(el('b', null, euro(sumCents(scelti))));
        }
        this.countBox.appendChild(document.createTextNode(ids.length ? ids.length + ' scelti' : 'nessuna scelta'));
    };

    Todo.prototype.bulkBar = function () {
        var self = this;
        var bar = el('div', 'td-bulk');

        if (this.listType === 'buy') {
            /* categoria e priorità a tutti insieme: è la ragione per cui la
               selezione esiste in questa scheda */
            var cat = el('button');
            cat.type = 'button';
            cat.innerHTML = ICON_TAG;
            cat.appendChild(el('span', null, 'Categoria'));
            cat.addEventListener('click', function () {
                var ids = Object.keys(self.picked).map(Number);
                if (!ids.length) return;
                self.catPicker(function (cid) {
                    api('bulk', { action: 'buy', ids: ids, buy_set: ['cat_id'], cat_id: cid }).then(function (r) {
                        if (!r.ok) return;
                        self.picked = {}; self.load();
                    });
                });
            });
            var pr = el('button');
            pr.type = 'button';
            pr.innerHTML = ICON_FLAG;
            pr.appendChild(el('span', null, T('todo.priorita')));
            pr.addEventListener('click', function () { self.bulkPriority(); });
            bar.appendChild(cat); bar.appendChild(pr);
        } else {
            var when = el('button');
            when.type = 'button';
            when.title = T('todo.data_e_ora');
            when.innerHTML = ICON_CAL;
            when.addEventListener('click', function () { self.bulkSchedule(); });

            var proj = el('button');
            proj.type = 'button';
            proj.appendChild(el('span', null, 'Progetto'));
            proj.addEventListener('click', function () {
                var modal = document.getElementById('ps-modal-todo');
                if (modal && window.psOpen) { self._pendingBulk = true; window.psOpen(modal); }
            });
            bar.appendChild(when); bar.appendChild(proj);
        }

        var del = el('button', 'td-danger');
        del.type = 'button';
        del.title = 'Elimina';
        del.innerHTML = ICON_TRASH;
        del.addEventListener('click', function () {
            var ids = Object.keys(self.picked).map(Number);
            if (!ids.length) return;
            api('bulk', { action: 'delete', ids: ids }).then(function () {
                self.picked = {}; self.load();
            });
        });
        bar.appendChild(del);

        this.countBox = el('span', 'td-count');
        bar.appendChild(this.countBox);
        this.refreshCount();
        return bar;
    };

    Todo.prototype.bulkPriority = function () {
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        if (!ids.length) return;
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', T('todo.priorita')));
        card.appendChild(el('div', 'td-card-s', T('todo.n_articoli_priorita', {n: ids.length})));
        [1, 2, 3].forEach(function (p) {
            var b = el('button', 'td-catpick');
            b.type = 'button';
            b.appendChild(el('span', null, T('todo.priorita_maiusc_n', {n: p})));
            b.addEventListener('click', function () {
                ov.remove();
                api('bulk', { action: 'buy', ids: ids, buy_set: ['priority'], priority: p }).then(function (r) {
                    if (!r.ok) return;
                    self.picked = {}; self.load();
                });
            });
            card.appendChild(b);
        });
        var acts = el('div', 'td-card-acts');
        var no = el('button', null, 'Annulla');
        no.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(no);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    Todo.prototype.bulkSchedule = function () {
        var self = this;
        var ids = Object.keys(this.picked).map(Number);
        if (!ids.length) return;

        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', ids.length + (ids.length === 1 ? ' attività' : ' attività')));
        card.appendChild(el('div', 'td-card-s', T('todo.stessa_data_per_tutte')));

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

    /* =================================================================
       PASTIGLIE E INTEGRATORI

       Due cose distinte, e si vedono separate anche qui: in alto le prese di
       oggi, una riga per ognuna, che è quello che serve dieci volte al giorno;
       sotto le terapie, che si aprono solo quando c'è da cambiarle.

       La spunta non chiede conferma e non riordina niente: se ogni mattina
       devi confermare due volte per dire che hai preso una pastiglia, dopo tre
       giorni smetti di aprirla. Il tocco lungo, invece, la segna come saltata:
       serve a distinguere "non l'ho presa" da "non ci ho ancora pensato", e
       quella differenza è il motivo per cui teniamo le prese una per una.
       ================================================================= */
    /* le sigle dei giorni: in inglese sono mon/tue/wed…, non si ricavano
       tagliando il nome italiano */
    var PILL_GG = [T('todo.gg_lun'), T('todo.gg_mar'), T('todo.gg_mer'), T('todo.gg_gio'),
                   T('todo.gg_ven'), T('todo.gg_sab'), T('todo.gg_dom')];

    function pillOggi() {
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    /* L'ora della spunta scritta come la scrive il server: «2026-08-04
       09:15:00», ora locale. Prima si usava toISOString(), che è UTC: alle
       9:15 di agosto la riga diceva «presa alle 07:15», due ore prima, e
       sembrava che il registro sbagliasse. Il valore vero sul database era
       giusto — a sbagliare era solo questa copia mostrata subito. */
    function pillAdesso() {
        var d = new Date();
        return pillOggi() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00';
    }
    function pillOraDi(stamp) { return String(stamp || '').slice(11, 16); }
    function pillGiornoDi(stamp) { return String(stamp || '').slice(0, 10); }

    Todo.prototype.loadPills = function () {
        var self = this;
        var ds = pillOggi();
        return api('pills', null, { date: ds }).then(function (r) {
            self.pills = (r && r.ok) ? r : { installed: false, plans: [], doses: [], stats: {} };
            self.pillDate = ds;
            self.render();
        });
    };

    Todo.prototype.renderPills = function () {
        var self = this;
        var p = this.pills || { installed: false, plans: [], doses: [], stats: {} };

        if (!p.installed) {
            this.list.appendChild(el('div', 'td-empty',
                T('todo.pastiglie_non_installate')));
            return;
        }

        /* --- quelle rimaste indietro ---
           Vanno in cima e in rosso perché sono l'unica cosa qui dentro su cui
           si può ancora fare qualcosa di utile: le prese di oggi le si spunta
           quando arriva l'ora, una dimenticata di ieri invece o la si sistema
           adesso o resta un buco. */
        var late = p.late || [];
        if (late.length) {
            self.list.appendChild(el('div', 'td-pill-sect td-pill-lasect',
                late.length === 1 ? 'Rimasta indietro' : 'Rimaste indietro'));
            late.forEach(function (d) { self.list.appendChild(self.pillDoseRow(d, true)); });
        }

        /* --- le prese di oggi, in ordine di orologio --- */
        var doses = p.doses || [];
        if (!doses.length) {
            this.list.appendChild(el('div', 'td-empty',
                p.plans && p.plans.length ? T('todo.niente_da_prendere')
                                          : T('todo.aggiungi_prima_terapia')));
        }
        var oraOra = pad(new Date().getHours()) + ':' + pad(new Date().getMinutes());
        var ultimoOrario = null;
        doses.forEach(function (d) {
            if (d.time !== ultimoOrario) {
                ultimoOrario = d.time;
                var h = el('div', 'td-pill-hour');
                h.appendChild(el('span', null, d.time));
                /* in ritardo: l'ora è passata e la spunta non è arrivata */
                if (d.status === 'pending' && d.time < oraOra) {
                    h.appendChild(el('span', 'td-pill-late', T('todo.in_ritardo')));
                }
                self.list.appendChild(h);
            }
            self.list.appendChild(self.pillDoseRow(d));
        });

        /* --- come sta andando --- */
        var st = p.stats || {};
        if ((st.taken || 0) + (st.skipped || 0) + (st.missed || 0) > 0) {
            this.list.appendChild(el('div', 'td-pill-stats',
                T('todo.ultimi_30_giorni', {prese: (st.taken || 0), saltate: (st.skipped || 0), dimenticate: (st.missed || 0)})));
        }

        /* --- le terapie --- */
        this.list.appendChild(el('div', 'td-pill-sect', T('todo.le_mie_terapie')));
        (p.plans || []).forEach(function (pl) {
            var r = el('button', 'td-pill-plan' + (pl.active ? '' : ' td-off'));
            r.type = 'button';
            var dot = el('span', 'td-pill-dot');
            dot.style.background = pl.color || '#B7A6D6';
            r.appendChild(dot);
            var box = el('span', 'td-pill-planbox');
            box.appendChild(el('span', 'td-pill-name', pl.name + (pl.dose ? '  ·  ' + pl.dose : '')));
            box.appendChild(el('span', 'td-pill-sub', pillRitmo(pl)));
            r.appendChild(box);
            r.addEventListener('click', function () { self.pillEditor(pl); });
            self.list.appendChild(r);
        });

        var add = el('button', 'td-add');
        add.type = 'button';
        add.innerHTML = ICON_PLUS;
        add.appendChild(el('span', null, T('todo.nuova_terapia')));
        add.addEventListener('click', function () { self.pillEditor(null); });
        this.list.appendChild(add);
    };

    /* Il ritmo detto a parole: "ogni giorno, 10:00 ×2 e 20:00" si legge, una
       riga di codici no. */
    function pillRitmo(pl) {
        var q = [];
        if (pl.freq_type === 'monthly') {
            q.push(T('todo.il_n_di_ogni_mese', {giorni: (pl.month_days || '?')}));
        } else if (pl.freq_type === 'interval') {
            q.push(pl.interval_days === 1 ? T('todo.ogni_giorno') : T('todo.ogni_n_giorni_val', {n: pl.interval_days}));
        } else {
            var w = String(pl.weekdays || '1111111');
            if (w.indexOf('0') < 0) {
                q.push(T('todo.ogni_giorno'));
            } else {
                var gg = [];
                for (var i = 0; i < 7; i++) if (w.charAt(i) === '1') gg.push(PILL_GG[i]);
                q.push(gg.length ? gg.join(', ') : T('todo.mai'));
            }
        }
        var ore = String(pl.times || '').split(',').map(function (t) {
            var p = t.split('x');
            return p[0] + (p[1] && +p[1] > 1 ? ' ×' + p[1] : '');
        });
        if (ore.length) q.push(ore.join(', '));
        if (pl.end_date) q.push('fino al ' + pl.end_date.slice(8, 10) + '/' + pl.end_date.slice(5, 7));
        if (!pl.active) q.push('sospesa');
        return q.join('  ·  ');
    }

    /* ==================================================================
       Il calendario delle prese: un mese per volta, un pallino per giorno,
       le frecce per cambiare mese.

       La striscia di quadratini di prima non si leggeva: sessanta giorni di
       fila senza sabati, domeniche né numeri sono sessanta quadratini uguali,
       e per capire *quando* era saltata una presa bisognava contarli a dito.
       Un mese disposto come un calendario invece si legge da sé — la colonna
       del lunedì è sempre la stessa colonna — ed è la stessa forma che hanno
       già le abitudini, quindi non c'è niente di nuovo da imparare.

       Il colore è quello della terapia: il verde e il rosso di tutti gli
       altri grafici qui direbbero “bravo” e “male”, e su una cura non è
       quello il punto.
       ================================================================== */
    var CAL_GG = ['L', 'M', 'M', 'G', 'V', 'S', 'D'];
    var CAL_MESI = ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio',
                    'agosto','settembre','ottobre','novembre','dicembre'];

    /* Quante prese prevede in un giorno: «10:00x2,20:00x1» fa tre. È il
       numero da cui dipende tutto il resto — quanti colori hanno senso e
       come si chiamano. */
    function pillPerGiorno(pl) {
        var n = 0;
        String((pl && pl.times) || '').split(',').forEach(function (t) {
            if (!t.trim()) return;
            var p = t.split('x');
            n += Math.max(1, parseInt(p[1] || '1', 10));
        });
        return Math.max(1, n);
    }

    /* Che colore prende un giorno.

       Le vie di mezzo si dividono in due sole categorie — fino a metà e oltre
       metà — e non una per numero: con cinque prese al giorno servirebbero
       quattro sfumature, che nessuno distinguerebbe a colpo d'occhio e che
       nella legenda diventerebbero un elenco più lungo del calendario.
       Con due prese al giorno di via di mezzo ce n'è una sola, e infatti di
       colore ne esce uno solo; con una sola presa non ce ne sono affatto. */
    function pillLivello(prese, tot) {
        if (tot <= 0) return '';
        if (prese >= tot) return 'td-ok';
        if (prese <= 0) return 'td-no';
        return prese * 2 <= tot ? 'td-mezzo' : 'td-mezzo2';
    }

    /* La legenda si scrive sulla terapia che si sta guardando, non in
       generale: spiegare il colore «presa in parte» a chi prende una
       pastiglia al giorno vuol dire descrivere una cosa che non gli
       succederà mai, e da lì in poi la legenda non la legge più nessuno. */
    function pillVolte(lista, tot) {
        /* "1 volta su 3" / "2–3 volte su 3": in inglese e' "1 time out of 3",
           quindi la frase intera dev'essere una chiave sola. */
        return lista.length === 1
            ? T(lista[0] === 1 ? 'todo.volta_su' : 'todo.volte_su', {n: lista[0], tot: tot})
            : T('todo.volte_da_a_su', {da: lista[0], a: lista[lista.length - 1], tot: tot});
    }
    function pillLegenda(tot) {
        var voci = [['td-ok', tot > 1 ? T('todo.presa_tutte_le_volte', {n: tot}) : T('todo.presa')]];
        var basso = [], alto = [];
        for (var k = 1; k < tot; k++) (k * 2 <= tot ? basso : alto).push(k);
        if (basso.length) voci.push(['td-mezzo', T('todo.presa_n_volte', {volte: pillVolte(basso, tot)})]);
        if (alto.length) voci.push(['td-mezzo2', T('todo.presa_n_volte', {volte: pillVolte(alto, tot)})]);
        voci.push(['td-no', T('todo.non_presa')]);
        voci.push(['td-attesa', T('todo.prevista_giorno_non_finito')]);
        return voci;
    }

    function pillCalendario(pl, titolo) {
        var box = el('div', 'td-pill-cal-box');
        box.style.setProperty('--cc', pl.color || '#B7A6D6');

        var oggi = pillOggi();
        var mese = oggi.slice(0, 7);

        var testa = el('div', 'td-pill-cal-head');
        var giu = el('button', 'td-pill-cal-nav'); giu.type = 'button';
        giu.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
        var su = el('button', 'td-pill-cal-nav'); su.type = 'button';
        su.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>';
        var lab = el('div', 'td-pill-cal-mese');
        testa.appendChild(giu); testa.appendChild(lab); testa.appendChild(su);
        box.appendChild(testa);

        if (titolo) box.appendChild(el('div', 'td-pill-sub', titolo));

        var dows = el('div', 'td-pill-cal-dows');
        CAL_GG.forEach(function (g) { dows.appendChild(el('span', 'td-pill-cal-dow', g)); });
        box.appendChild(dows);

        var griglia = el('div', 'td-pill-cal');
        box.appendChild(griglia);

        /* La legenda, scritta sulla terapia che si sta guardando: nessuno
           indovina i colori da solo, e senza, un giorno sfumato si legge come
           «mezzo cancellato» invece che «una delle due presa». Sta sotto e non
           sopra perché la si guarda una volta e poi non serve più. */
        var perGiorno = pillPerGiorno(pl);
        var leg = el('div', 'td-pill-cal-leg');
        pillLegenda(perGiorno).forEach(function (v) {
            var r = el('div', 'td-pill-cal-legv');
            var q = el('i', 'td-pill-cal-c ' + v[0]);
            q.style.setProperty('--cc', pl.color || '#B7A6D6');
            r.appendChild(q);
            r.appendChild(el('span', null, v[1]));
            leg.appendChild(r);
        });
        box.appendChild(leg);

        var nota = el('div', 'td-pill-cal-nota');
        box.appendChild(nota);

        function passoMese(n) {
            var y = +mese.slice(0, 4), m = +mese.slice(5, 7) - 1 + n;
            var d = new Date(y, m, 1);
            mese = d.getFullYear() + '-' + pad(d.getMonth() + 1);
            disegna();
        }
        giu.addEventListener('click', function () { passoMese(-1); });
        su.addEventListener('click', function () { passoMese(1); });

        /* Si sfoglia anche col dito, come ci si aspetta da un calendario:
           trascinare verso sinistra porta avanti, come voltare una pagina.
           Quaranta pixel di soglia, se no basterebbe un dito appena storto
           mentre si guarda un giorno per cambiare mese senza volerlo.
           Il gesto sta sulla griglia e non sulla scheda intera: la scheda in
           verticale scorre, e rubarle il movimento vorrebbe dire non poter
           più arrivare in fondo. */
        var px = 0, py = 0, vivo = false;
        griglia.addEventListener('pointerdown', function (e) {
            px = e.clientX; py = e.clientY; vivo = true;
        });
        griglia.addEventListener('pointerup', function (e) {
            if (!vivo) return;
            vivo = false;
            var dx = e.clientX - px, dy = e.clientY - py;
            if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
            /* indietro sempre; avanti solo se c'è un mese da vedere, cioè
               mai oltre quello in corso */
            if (dx < 0) { if (!su.disabled) passoMese(1); }
            else passoMese(-1);
        });
        griglia.addEventListener('pointercancel', function () { vivo = false; });

        function disegna() {
            lab.textContent = CAL_MESI[+mese.slice(5, 7) - 1] + ' ' + mese.slice(0, 4);
            /* avanti oltre il mese in corso non c'è niente da vedere: le
               prese future non sono ancora state né prese né saltate */
            su.disabled = mese >= oggi.slice(0, 7);
            griglia.innerHTML = '';
            nota.textContent = '';

            api('pill_history', null, { plan_id: pl.id, month: mese }).then(function (r) {
                griglia.innerHTML = '';
                var per = {};
                if (r && r.ok) (r.days || []).forEach(function (g) { per[g.date] = g; });

                var y = +mese.slice(0, 4), m = +mese.slice(5, 7) - 1;
                var primo = new Date(y, m, 1);
                var quanti = new Date(y, m + 1, 0).getDate();
                /* la settimana comincia di lunedì, come dappertutto qui */
                var vuoti = (primo.getDay() + 6) % 7;
                for (var v = 0; v < vuoti; v++) griglia.appendChild(el('div', 'td-pill-cal-c td-vuoto'));

                var prese = 0, buchi = 0;
                for (var d = 1; d <= quanti; d++) {
                    var iso = y + '-' + pad(m + 1) + '-' + pad(d);
                    var g = per[iso];
                    var cls = 'td-pill-cal-c';
                    if (g) {
                        var tot = (g.taken || 0) + (g.skipped || 0) + (g.pending || 0);
                        /* niente da dire su un giorno ancora in corso in cui
                           non è successo ancora niente: non è un buco */
                        if (!g.taken && !g.skipped && iso >= oggi) {
                            cls += ' td-attesa';
                        } else {
                            var liv = pillLivello(g.taken || 0, tot);
                            cls += ' ' + liv;
                            if (liv === 'td-ok') prese++;
                            else if (liv === 'td-no') buchi++;
                        }
                    }
                    if (iso === oggi) cls += ' td-oggi';
                    if (iso > oggi) cls += ' td-futuro';
                    var c = el('div', cls, String(d));
                    if (g) {
                        c.title = iso + ': ' + (g.taken || 0) + ' su '
                                + ((g.taken || 0) + (g.skipped || 0) + (g.pending || 0));
                    }
                    griglia.appendChild(c);
                }
                nota.textContent = buchi
                    ? T('todo.giorni_presa_no', {presa: prese, no: buchi})
                    : (prese ? T('todo.presa_tutti_i_giorni') : T('todo.nessuna_presa'));
            });
        }
        disegna();
        return box;
    }

    /* Il calendario da solo, senza la scheda della terapia intorno: si apre
       toccando una presa nell'elenco del giorno, che è il momento in cui
       viene da chiedersi “ma quante ne ho saltate?”. */
    Todo.prototype.pillGrafico = function (plan) {
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card td-card-wide');
        card.appendChild(el('div', 'td-card-t', plan.name || 'Terapia'));
        card.appendChild(pillCalendario(plan, plan.dose || ''));
        var acts = el('div', 'td-card-acts');
        var ok = el('button', 'td-primary', 'Chiudi');
        ok.type = 'button';
        ok.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(ok);
        card.appendChild(acts);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    };

    Todo.prototype.pillDoseRow = function (d, arretrata) {
        var self = this;
        var r = el('div', 'td-pill-row td-pill-' + d.status + (arretrata ? ' td-pill-late-row' : ''));

        var box = el('button', 'td-pill-check');
        box.type = 'button';
        box.setAttribute('aria-label', d.status === 'taken' ? 'Presa' : T('todo.segna_come_presa'));
        box.style.setProperty('--pill', d.color || '#B7A6D6');
        if (d.status === 'taken') {
            box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
        } else if (d.status === 'skipped') {
            box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>';
        }

        function segna(stato, quando) {
            /* la riga cambia subito e la chiamata parte dopo: qui l'attesa di
               una risposta dal server si sentirebbe tutta */
            d.status = stato;
            d.taken_at = stato === 'taken' ? (quando || pillAdesso()) : null;
            self.renderLater(120);
            var dati = { id: d.id, status: stato };
            if (stato === 'taken' && quando) {
                dati.at_date = pillGiornoDi(quando);
                dati.at_time = pillOraDi(quando);
            }
            api('pill_mark', dati);
            if (navigator.vibrate) { try { navigator.vibrate(stato === 'taken' ? 12 : 6); } catch (e) {} }
        }
        /* la riga la conosce solo chi la disegna: si appende qui perché la
           possano usare anche le arretrate, che hanno tastini in più */
        r.pillSegna = segna;

        var lungo = null;
        box.addEventListener('pointerdown', function () {
            /* tocco lungo = saltata. È un gesto nascosto, ma la spunta normale
               deve restare di un tocco solo: è quella che si usa ogni giorno. */
            lungo = setTimeout(function () { lungo = null; segna('skipped'); }, 550);
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
            box.addEventListener(ev, function () { if (lungo) { clearTimeout(lungo); lungo = null; } });
        });
        box.addEventListener('click', function () {
            if (d.status === 'taken') segna('pending');
            else segna('taken');
        });

        var box2 = el('div', 'td-pill-txt');
        box2.appendChild(el('div', 'td-pill-name', d.name + (d.dose ? '  ·  ' + d.dose : '')));
        var sotto = [];
        if (d.notes) sotto.push(d.notes);
        if (d.status === 'skipped') sotto.push(T('todo.non_presa'));
        if (sotto.length) box2.appendChild(el('div', 'td-pill-sub', sotto.join('  ·  ')));

        /* L'ora della presa si corregge toccandola. Il caso non è raro, è
           quello normale: la pastiglia si prende al mattino e ci si ricorda
           di spuntarla nel pomeriggio, e senza poterla correggere il registro
           racconterebbe un ritardo che non c'è mai stato. */
        if (d.status === 'taken' && d.taken_at) {
            var quando = el('button', 'td-pill-sub td-pill-when');
            quando.type = 'button';
            quando.textContent = 'presa ' + (d.date && d.date !== pillOggi()
                ? 'il ' + pillGiornoDi(d.taken_at).slice(8, 10) + '/' + pillGiornoDi(d.taken_at).slice(5, 7) + ' alle '
                : 'alle ') + pillOraDi(d.taken_at);
            quando.addEventListener('click', function () {
                self.pillQuando(d, function (nuovoQuando) { segna('taken', nuovoQuando); });
            });
            box2.appendChild(quando);
        }

        /* Toccando il nome si apre il calendario di quella terapia. La
           casella resta quello che è — la si tocca per spuntare — ma il nome
           non faceva niente, ed è proprio lì che uno tocca chiedendosi come
           sta andando. */
        box2.style.cursor = 'pointer';
        box2.addEventListener('click', function (e) {
            if (e.target.closest('.td-pill-when')) return;   // quella corregge l'ora
            /* La terapia intera e non solo nome e colore: al calendario serve
               `times` per sapere quante prese prevede un giorno, ed è da lì
               che dipendono i colori e la legenda. Nella riga della presa
               quel dato non c'è, ma nell'elenco delle terapie sì. */
            var pieno = null;
            ((self.pills && self.pills.plans) || []).forEach(function (p) {
                if (+p.id === +d.plan_id) pieno = p;
            });
            self.pillGrafico(pieno || { id: d.plan_id, name: d.name, dose: d.dose, color: d.color });
        });

        r.appendChild(box);
        r.appendChild(box2);

        if (arretrata) {
            /* Il giorno di cui si sta parlando, scritto: senza, tre righe
               tutte uguali di tre giorni diversi non si distinguono. */
            box2.insertBefore(el('div', 'td-pill-lab-day', pillGiornoLungo(d.date)), box2.firstChild);

            /* Spuntarla qui chiede *quando*: su una presa di ieri l'ora
               dell'orologio sarebbe sicuramente sbagliata, ed è il dato che
               poi si guarda per capire se la terapia sta reggendo. */
            box.addEventListener('click', function (e) {
                if (d.status === 'taken') return;
                e.stopPropagation();
                self.pillQuando(d, function (quando) { r.pillSegna('taken', quando); }, true);
            }, true);

            var scorda = el('button', 'td-pill-scorda', 'dimenticata');
            scorda.type = 'button';
            scorda.title = T('todo.non_ho_presa');
            scorda.addEventListener('click', function () { r.pillSegna('skipped'); });
            r.appendChild(scorda);
        }
        return r;
    };

    /* «lunedì 3 agosto», per dire di che giorno è una presa arretrata. */
    function pillGiornoLungo(iso) {
        var GG = ['domenica','lunedì','martedì','mercoledì','giovedì','venerdì','sabato'];
        var MM = ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio',
                  'agosto','settembre','ottobre','novembre','dicembre'];
        var d = new Date(iso + 'T12:00:00');
        var ieri = new Date(); ieri.setDate(ieri.getDate() - 1);
        var isoIeri = ieri.getFullYear() + '-' + pad(ieri.getMonth() + 1) + '-' + pad(ieri.getDate());
        if (iso === isoIeri) return 'ieri';
        return GG[d.getDay()] + ' ' + d.getDate() + ' ' + MM[d.getMonth()];
    }

    /* Quando l'hai presa davvero. Sulle arretrate si chiede anche il giorno,
       perché una presa di lunedì spuntata mercoledì appartiene a lunedì e
       scriverla su mercoledì sposterebbe il buco invece di chiuderlo.
       Si parte dall'ora prevista e non da adesso: nove volte su dieci la
       pastiglia è stata presa lì o poco dopo, e così spesso basta confermare. */
    Todo.prototype.pillQuando = function (d, poi, chiediGiorno) {
        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card');
        card.appendChild(el('div', 'td-card-t', T('todo.quando_presa')));

        var iData = null;
        if (chiediGiorno) {
            var fd = el('div', 'td-pill-field');
            fd.appendChild(el('label', 'td-pill-lab', 'Giorno'));
            iData = el('input', 'td-in');
            iData.type = 'date';
            iData.value = pillGiornoDi(d.taken_at) || d.date || pillOggi();
            fd.appendChild(iData);
            card.appendChild(fd);
        }
        var ft = el('div', 'td-pill-field');
        ft.appendChild(el('label', 'td-pill-lab', 'Ora'));
        var iOra = el('input', 'td-in');
        iOra.type = 'time'; iOra.step = '60';
        iOra.value = pillOraDi(d.taken_at) || d.time || '08:00';
        ft.appendChild(iOra);
        card.appendChild(ft);

        var az = el('div', 'td-card-az');
        var no = el('button', 'td-b', 'Annulla'); no.type = 'button';
        var si = el('button', 'td-b td-b-p', 'Salva'); si.type = 'button';
        no.addEventListener('click', function () { ov.remove(); });
        si.addEventListener('click', function () {
            var g = iData ? (iData.value || d.date || pillOggi()) : (d.date || pillOggi());
            var o = iOra.value || d.time || '08:00';
            ov.remove();
            poi(g + ' ' + o.slice(0, 5) + ':00');
        });
        az.appendChild(no); az.appendChild(si);
        card.appendChild(az);
        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
        iOra.focus();
    };

    /* La scheda della terapia. Un campo per volta, nell'ordine in cui uno ci
       pensa: cosa prendo, con che ritmo, a che ore, da quando a quando. */
    Todo.prototype.pillEditor = function (pl) {
        var self = this;
        var nuovo = !pl;
        pl = pl || {
            id: 0, name: '', dose: '', notes: '', color: '#B7A6D6',
            freq_type: 'daily', weekdays: '1111111', month_days: '', interval_days: 1,
            times: '08:00x1', start_date: pillOggi(), end_date: null, active: true
        };
        var stato = {
            freq: pl.freq_type,
            wd: String(pl.weekdays || '1111111').split(''),
            times: String(pl.times || '08:00x1').split(',').map(function (t) {
                var p = t.split('x');
                return { ora: p[0] || '08:00', n: Math.max(1, parseInt(p[1] || '1', 10)) };
            })
        };

        var ov = el('div', 'td-modal');
        var card = el('div', 'td-card td-card-wide');
        card.appendChild(el('div', 'td-card-t', nuovo ? T('todo.nuova_terapia') : 'Terapia'));

        function campo(lab, node) {
            var f = el('div', 'td-pill-field');
            f.appendChild(el('label', 'td-pill-lab', lab));
            f.appendChild(node);
            card.appendChild(f);
            return node;
        }
        function testo(val, ph, max) {
            var i = el('input', 'td-in');
            i.type = 'text'; i.value = val || ''; i.placeholder = ph || ''; i.maxLength = max || 120;
            return i;
        }

        var iNome  = campo('Cosa', testo(pl.name, 'Vitamina D, magnesio…', 120));
        var iDose  = campo('Quanto', testo(pl.dose, T('todo.esempio_dose'), 60));
        var iNote  = campo('Nota', testo(pl.notes, T('todo.esempio_note'), 255));

        /* --- ritmo --- */
        var rit = el('div', 'td-pill-seg');
        var ritBtn = {};
        [['daily', 'giorni della settimana'], ['monthly', T('todo.giorni_del_mese')], ['interval', T('todo.ogni_n_giorni')]]
        .forEach(function (o) {
            var b = el('button', 'td-pill-segb' + (stato.freq === o[0] ? ' td-on' : ''), o[1]);
            b.type = 'button';
            b.addEventListener('click', function () {
                stato.freq = o[0];
                for (var k in ritBtn) ritBtn[k].classList.toggle('td-on', k === o[0]);
                disegnaRitmo();
            });
            ritBtn[o[0]] = b;
            rit.appendChild(b);
        });
        campo('Quando', rit);
        var ritBox = el('div', 'td-pill-ritmo');
        card.appendChild(ritBox);

        var iMese = testo(pl.month_days, T('todo.esempio_giorni'), 100);
        var iInt = el('input', 'td-in');
        iInt.type = 'number'; iInt.min = '1'; iInt.max = '365'; iInt.value = pl.interval_days || 1;

        function disegnaRitmo() {
            ritBox.innerHTML = '';
            if (stato.freq === 'daily') {
                var gg = el('div', 'td-pill-days');
                PILL_GG.forEach(function (g, i) {
                    var b = el('button', 'td-pill-day' + (stato.wd[i] === '1' ? ' td-on' : ''), g);
                    b.type = 'button';
                    b.addEventListener('click', function () {
                        stato.wd[i] = stato.wd[i] === '1' ? '0' : '1';
                        b.classList.toggle('td-on', stato.wd[i] === '1');
                    });
                    gg.appendChild(b);
                });
                ritBox.appendChild(gg);
            } else if (stato.freq === 'monthly') {
                ritBox.appendChild(iMese);
            } else {
                ritBox.appendChild(iInt);
            }
        }
        disegnaRitmo();

        /* --- orari --- */
        var oreBox = el('div', 'td-pill-ore');
        function disegnaOre() {
            oreBox.innerHTML = '';
            stato.times.forEach(function (t, idx) {
                var r = el('div', 'td-pill-ora');
                var i = el('input', 'td-in');
                i.type = 'time'; i.step = '60'; i.value = t.ora;
                i.addEventListener('change', function () { t.ora = i.value || '08:00'; });
                r.appendChild(i);
                /* quante volte a quell'ora: due compresse alle 10 sono una riga
                   sola nella terapia, ma restano due spunte nella giornata */
                var meno = el('button', 'td-pill-step', '−'); meno.type = 'button';
                var quante = el('span', 'td-pill-n', '×' + t.n);
                var piu = el('button', 'td-pill-step', '+'); piu.type = 'button';
                meno.addEventListener('click', function () {
                    t.n = Math.max(1, t.n - 1); quante.textContent = '×' + t.n;
                });
                piu.addEventListener('click', function () {
                    t.n = Math.min(9, t.n + 1); quante.textContent = '×' + t.n;
                });
                r.appendChild(meno); r.appendChild(quante); r.appendChild(piu);
                var via = el('button', 'td-pill-step', '×'); via.type = 'button';
                via.addEventListener('click', function () {
                    if (stato.times.length <= 1) return;   // almeno un orario deve restare
                    stato.times.splice(idx, 1); disegnaOre();
                });
                r.appendChild(via);
                oreBox.appendChild(r);
            });
            var piuOra = el('button', 'td-pill-addora', T('todo.altro_orario'));
            piuOra.type = 'button';
            piuOra.addEventListener('click', function () {
                stato.times.push({ ora: '20:00', n: 1 });
                disegnaOre();
            });
            oreBox.appendChild(piuOra);
        }
        disegnaOre();
        campo(T('todo.a_che_ora'), oreBox);

        var iDa = el('input', 'td-in'); iDa.type = 'date'; iDa.value = pl.start_date || pillOggi();
        campo('Da', iDa);
        var iA = el('input', 'td-in'); iA.type = 'date'; iA.value = pl.end_date || '';
        campo('Fino a (vuoto = sempre)', iA);

        var att = el('button', 'td-pill-att' + (pl.active ? ' td-on' : ''), pl.active ? 'Attiva' : 'Sospesa');
        att.type = 'button';
        att.dataset.on = pl.active ? '1' : '0';
        att.addEventListener('click', function () {
            var on = att.dataset.on !== '1';
            att.dataset.on = on ? '1' : '0';
            att.classList.toggle('td-on', on);
            att.textContent = on ? 'Attiva' : 'Sospesa';
        });
        campo('Stato', att);

        /* Come è andata, in fondo e non in cima: la scheda si apre per
           cambiare la terapia, e la storia è quello che si guarda dopo aver
           finito di guardare le regole. Sopra i tasti, però, perché con i
           tasti a metà scheda si perde di vista dove si salva. */
        if (!nuovo && pl.id) card.appendChild(pillCalendario(pl));

        var acts = el('div', 'td-card-acts');
        var ann = el('button', null, 'Annulla');
        ann.type = 'button';
        ann.addEventListener('click', function () { ov.remove(); });
        acts.appendChild(ann);

        if (!nuovo) {
            var del = el('button', null, 'Elimina');
            del.type = 'button';
            del.addEventListener('click', function () {
                ov.remove();
                api('pill_del', { id: pl.id }).then(function (r) {
                    if (r.ok) self.loadPills();
                });
            });
            acts.appendChild(del);
        }

        var ok = el('button', 'td-primary', 'Salva');
        ok.type = 'button';
        ok.addEventListener('click', function () {
            if (!iNome.value.trim()) { iNome.focus(); return; }
            ok.disabled = true;
            api('pill_save', {
                id: pl.id || 0,
                name: iNome.value, dose: iDose.value, notes: iNote.value, color: pl.color,
                freq_type: stato.freq,
                weekdays: stato.wd.join(''),
                month_days: iMese.value,
                interval_days: iInt.value,
                times: stato.times.map(function (t) { return t.ora + 'x' + t.n; }).join(','),
                start_date: iDa.value, end_date: iA.value,
                active: att.dataset.on === '1'
            }).then(function (r) {
                ok.disabled = false;
                if (!r.ok) return;
                ov.remove();
                self.loadPills();
            });
        });
        acts.appendChild(ok);
        card.appendChild(acts);

        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
        iNome.focus();
    };

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