/* todo_barra.js — la barra intelligente del to do, e il to do piu' veloce da usare.

   Si aggancia al componente delle attivita' (todo_widget.php) come todo_buy.js
   fa per gli acquisti, e non tocca il suo codice. Cosa porta:

   - UNA RIGA PER AGGIUNGERE, in cima, come nel planner: si scrive «chiamare il
     dentista domani alle 15 #salute» e invio. L'attivita' compare subito (un
     po' sbiadita finche' il server non conferma) e la casella resta pronta per
     la prossima. Prima ogni «+» creava una riga vuota sul server, rileggeva
     tutto e solo dopo dava il cursore. Mentre si scrive, sotto, si vede cosa
     e' stato capito (giorno, ora, durata, progetto) e si puo' toccare per
     togliere. Il microfono detta la frase. Incollando piu' righe si aggiungono
     tutte.
   - GRUPPI PER GIORNO: In ritardo / Oggi / Domani / Questa settimana / Piu'
     avanti / Senza data / Fatte. Si vede subito cosa urge, senza leggere date.
   - RIGHE PULITE: a riposo una riga mostra solo il titolo e quello che e'
     impostato (giorno scritto «domani», ora, progetto). Toccando la riga si
     aprono gli strumenti, con le scorciatoie per la data: Oggi, Domani,
     Weekend, Fra una settimana, Senza data.
   Vale per to do e to study; il to buy ha la sua barra (todo_buy.js). */
(function () {
    'use strict';
    var W = window.standoutTodo;
    if (!W || !W.Todo || !W.h || !window.todoSmart) return;
    var Todo = W.Todo, H = W.h, S = window.todoSmart;
    var el = H.el, api = H.api;

    var LINGUA = document.documentElement.lang || 'it';
    function inLingua(opz) { try { return new Intl.DateTimeFormat(LINGUA, opz); } catch (e) { return null; } }
    var FMT_GIORNO = inLingua({ weekday: 'long' });
    var FMT_DATA = inLingua({ day: 'numeric', month: 'short' });
    var FMT_BREVE = inLingua({ weekday: 'short', day: 'numeric', month: 'short' });
    function mai(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

    /* il giorno detto come lo direbbe una persona: Oggi, Domani, Martedi', 3 nov */
    function giornoUmano(iso, oggi) {
        if (!iso) return '';
        var d = S.daIso(iso), diff = Math.round((d - S.daIso(oggi)) / 86400000);
        if (diff === 0) return T('todo.tn_oggi');
        if (diff === 1) return T('todo.tn_domani');
        if (diff === -1) return T('todo.tn_ieri');
        if (diff > 1 && diff < 7 && FMT_GIORNO) return mai(FMT_GIORNO.format(d));
        return FMT_DATA ? FMT_DATA.format(d) : iso;
    }

    /* ---------------- i progetti, per riconoscere «#nome» ---------------- */
    Todo.prototype.progettiNoti = function () {
        var self = this;
        if (this._progetti) return Promise.resolve(this._progetti);
        if (this._progettiInVolo) return this._progettiInVolo;
        S.carica();   // le regole di data e ora: partono insieme ai progetti
        this._progettiInVolo = api('progetti').then(function (r) {
            self._progettiInVolo = null;
            self._progetti = (r && r.ok && r.projects) || [];
            return self._progetti;
        });
        return this._progettiInVolo;
    };

    function usabile(t) { return t.listType === 'todo' || t.listType === 'study'; }
    function conBarra(t) { return usabile(t) && (t.scope === 'all' || t.scope === 'project'); }

    /* ---------------- la barra ---------------- */
    Todo.prototype.barraAttivita = function () {
        var self = this;
        if (this.taBar) return this.taBar;
        var bar = el('div', 'tn-bar');
        var form = el('form', 'tn-add');
        form.setAttribute('autocomplete', 'off');
        var inp = el('input', 'tn-in');
        inp.type = 'text';
        inp.maxLength = 300;
        inp.setAttribute('data-td-libero', '1');   // non blocca il ridisegno dell'elenco
        inp.setAttribute('enterkeyhint', 'done');
        inp.setAttribute('autocapitalize', 'sentences');
        form.appendChild(inp);

        var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
        var mic = null;
        if (Rec) {
            mic = el('button', 'tn-mic');
            mic.type = 'button';
            mic.setAttribute('aria-label', T('todo.tn_detta'));
            mic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0013 0M12 17.5V21"/></svg>';
            form.appendChild(mic);
        }
        var go = el('button', 'tn-go');
        go.type = 'submit';
        go.setAttribute('aria-label', T('todo.tn_aggiungi'));
        go.innerHTML = H.ICON_PLUS;
        form.appendChild(go);

        var capito = el('div', 'tn-capito');
        bar.appendChild(form); bar.appendChild(capito);

        var tolti = {};   // pezzi capiti che l'utente ha scelto di non usare
        function leggi() { return S.leggi(inp.value, self._progetti || []); }
        function disegna() {
            capito.innerHTML = '';
            var v = inp.value.trim();
            if (!v) { capito.classList.remove('tn-on'); return; }
            var r = leggi(), oggi = S.oggi();
            var pezzi = [];
            if (r.date && !tolti.date) pezzi.push({ k: 'date', t: giornoUmano(r.date, oggi) });
            if (r.time && !tolti.time) pezzi.push({ k: 'time', t: r.time });
            if (r.minutes && !tolti.minutes) pezzi.push({ k: 'minutes', t: r.minutes + ' min' });
            if (r.project && !tolti.project) pezzi.push({ k: 'project', t: '#' + r.project.name });
            if (r.projectNew && !tolti.project) pezzi.push({ k: 'nessuno', t: T('todo.tn_progetto_no', { n: r.projectNew }) });
            capito.classList.toggle('tn-on', pezzi.length > 0);
            pezzi.forEach(function (p) {
                var c = el('span', 'tn-pez tn-pez-' + p.k, p.t);
                capito.appendChild(c);
            });
        }
        inp.addEventListener('input', function () { tolti = {}; disegna(); });
        inp.addEventListener('focus', function () { Promise.all([self.progettiNoti(), S.carica()]).then(disegna); });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var v = inp.value.trim();
            if (!v) return;
            inp.value = '';
            capito.classList.remove('tn-on');
            self.aggiungiAttivita(v);
            inp.focus();
        });
        /* più righe incollate = più attività */
        inp.addEventListener('paste', function (e) {
            var testo = (e.clipboardData || window.clipboardData).getData('text');
            if (!testo || testo.indexOf('\n') === -1) return;
            e.preventDefault();
            testo.split(/\r?\n/).map(function (s) { return s.replace(/^[\s\-•*·]+|^\d{1,2}[.)]\s+/, '').trim(); })
                 .filter(Boolean).slice(0, 60).forEach(function (riga) { self.aggiungiAttivita(riga); });
        });

        /* il microfono: una frase, poi si aggiunge da sola */
        if (mic) {
            var rec = null, attivo = false;
            mic.addEventListener('click', function () {
                if (attivo && rec) { try { rec.stop(); } catch (e) {} return; }
                try {
                    rec = new Rec();
                    rec.lang = LINGUA === 'en' ? 'en-US' : 'it-IT';
                    rec.interimResults = true;
                    rec.maxAlternatives = 1;
                    var finale = '';
                    rec.onresult = function (ev) {
                        var t = '';
                        for (var i = ev.resultIndex; i < ev.results.length; i++) t += ev.results[i][0].transcript;
                        inp.value = t.trim();
                        if (ev.results[ev.results.length - 1].isFinal) finale = t.trim();
                        disegna();
                    };
                    rec.onerror = function () { attivo = false; mic.classList.remove('tn-reg'); };
                    rec.onend = function () {
                        attivo = false; mic.classList.remove('tn-reg');
                        if (finale) { inp.value = ''; capito.classList.remove('tn-on'); self.aggiungiAttivita(finale); }
                    };
                    Promise.all([self.progettiNoti(), S.carica()]).then(function () { rec.start(); attivo = true; mic.classList.add('tn-reg'); });
                } catch (e) { attivo = false; }
            });
        }

        this.taBar = bar;
        this.taInput = inp;
        this.taDisegna = disegna;
        this.taAggiorna = function () {
            inp.placeholder = self.listType === 'study' ? T('todo.tn_ph_study') : T('todo.tn_ph_todo');
        };
        return bar;
    };

    /* ---------------- aggiungere: subito, poi il server ---------------- */
    Todo.prototype.aggiungiAttivita = function (testo) {
        var self = this;
        Promise.all([this.progettiNoti(), S.carica()]).then(function () {
            var r = S.leggi(testo, self._progetti || []);
            if (!r.title) return;
            var proj = r.project || null;
            /* dentro un progetto l'attivita' nasce gia' in quel progetto */
            var progId = proj ? proj.id : (self.scope === 'project' ? self.projectId : (self.opts.defaultProjectId || 0));
            self._tmp = (self._tmp || 0) - 1;
            var t = { id: self._tmp, list_type: self.listType, title: r.title, done: false,
                      date: r.date || (self.opts.defaultDate || null), time: r.time || null,
                      minutes: r.minutes || 30, parent_id: null,
                      project_id: progId || null, project_name: proj ? proj.name : null, project_color: proj ? proj.color : null,
                      _pend: true };
            self.items.push(t);
            (self._inViaggio = self._inViaggio || []).push(t);
            self.render();

            var crea = function () {
                var payload = { id: 0, title: r.title, parent_id: 0, list_type: self.listType, project_id: progId || 0,
                                date: t.date || '', time: t.time || '', minutes: t.minutes, source: self.scope === 'project' ? 'project' : 'page' };
                return api('save', payload).then(function (res) {
                    self._inViaggio = (self._inViaggio || []).filter(function (x) { return x !== t; });
                    if (!res || !res.ok || !res.todo) {
                        self.items = self.items.filter(function (x) { return x !== t; });
                        self.render();
                        if (self.taInput && !self.taInput.value) self.taInput.value = testo;   // non si perde quello che si e' scritto
                        return;
                    }
                    var ancora = self.items.indexOf(t) !== -1;
                    for (var k in res.todo) if (res.todo.hasOwnProperty(k)) t[k] = res.todo[k];
                    t._pend = false;
                    if (ancora) self.render(); else self.load();
                });
            };
            /* una per volta, nell'ordine in cui sono state scritte */
            self._codaTa = (self._codaTa || Promise.resolve()).then(crea);
            self._inVolo = self._codaTa;
        });
    };

    /* ---------------- il disegno: gruppi per giorno e righe pulite ---------------- */
    /* L'ordine del server (da fare per prime, per giorno e ora, senza data in
       fondo) rifatto qui: le attivita' appena aggiunte arrivano in coda, e
       senza riordinare i gruppi per giorno uscirebbero a pezzi. */
    function chiaveOrdine(t, i) {
        return [t.done ? 1 : 0, t.date ? 0 : 1, t.date || '', t.time ? 0 : 1, t.time || '', i];
    }
    function confronta(a, b) {
        for (var k = 0; k < a.length; k++) if (a[k] !== b[k]) return a[k] < b[k] ? -1 : 1;
        return 0;
    }
    var congelaBase = Todo.prototype.congela;
    Todo.prototype.congela = function () {
        var primaVolta = !this.frozen;
        congelaBase.call(this);
        /* il gruppo di ogni riga si fotografa insieme all'ordine: cambiandole
           il giorno resta nel suo gruppo finche' non si tocca fuori */
        if (primaVolta && this.frozen) {
            var f = this.frozen;
            this.items.forEach(function (t) {
                if (f[String(t.id)]) { f[String(t.id)].date = t.date || null; f[String(t.id)].time = t.time || null; f[String(t.id)].done = !!t.done; }
            });
        }
    };

    var renderBase = Todo.prototype.render;
    Todo.prototype.render = function () {
        if (this.listType === 'todo' && !this.frozen) {
            this.items = this.items.map(function (t, i) { return { t: t, k: chiaveOrdine(t, i) }; })
                .sort(function (a, b) { return confronta(a.k, b.k); })
                .map(function (x) { return x.t; });
        }
        /* le attivita' ancora in viaggio verso il server restano in vista anche
           se nel frattempo l'elenco e' stato riletto */
        if (usabile(this) && this._inViaggio && this._inViaggio.length) {
            var self = this;
            this._inViaggio.forEach(function (t) {
                if (self.items.indexOf(t) === -1 && !self.items.some(function (x) { return x.id === t.id; })) self.items.push(t);
            });
        }
        renderBase.call(this);
        this.decoraTodo();
    };

    Todo.prototype.decoraTodo = function () {
        var self = this;
        if (!this.wrap) return;
        var attiva = usabile(this);
        this.wrap.classList.toggle('tn', attiva);
        /* la barra: sta FUORI dall'elenco (l'elenco si ridisegna, la casella no) */
        if (conBarra(this) && !this.selecting) {
            var bar = this.barraAttivita();
            this.taAggiorna();
            if (bar.parentNode !== this.wrap) this.wrap.insertBefore(bar, this.list);
            if (this.taDisegna) this.taDisegna();
        } else if (this.taBar && this.taBar.parentNode) {
            this.taBar.parentNode.removeChild(this.taBar);
        }
        if (!attiva || !this.list) return;

        /* i vecchi titoli di gruppo si tolgono sempre: l'elenco puo' non essere
           stato ridisegnato (casella in mano) e non si vogliono doppioni */
        Array.prototype.forEach.call(this.list.querySelectorAll('.tn-gh'), function (n) { n.remove(); });
        /* il «+» e il vuoto di prima non servono piu': c'e' la barra */
        if (conBarra(this) && !this.selecting) {
            Array.prototype.forEach.call(this.list.querySelectorAll('.td-add'), function (n) { n.remove(); });
        }
        if (this.listType !== 'todo' || this.selecting) return;

        var oggi = this.today || S.oggi();
        var domani = S.piuGiorni(oggi, 1), fraUnaSett = S.piuGiorni(oggi, 7);
        var byId = {};
        this.items.forEach(function (t) { byId[String(t.id)] = t; });
        var ultimo = null, conto = {}, intest = {};
        var righe = this.list.querySelectorAll('.td-item:not(.td-sub)');
        Array.prototype.forEach.call(righe, function (riga) {
            var t = byId[riga.dataset.id];
            if (!t) return;
            /* durante una modifica vale la fotografia (vedi congela) */
            var fo = self.frozen && self.frozen[String(t.id)];
            var tt = fo && fo.date !== undefined ? { done: fo.done, date: fo.date, time: fo.time } : t;
            var g;
            if (tt.done) g = 'fatte';
            else if (!tt.date) g = 'senza';
            else if (tt.date < oggi || (tt.date === oggi && self.isLate(tt))) g = 'ritardo';
            else if (tt.date === oggi) g = 'oggi';
            else if (tt.date === domani) g = 'domani';
            else if (tt.date < fraUnaSett) g = 'settimana';
            else g = 'dopo';
            conto[g] = (conto[g] || 0) + 1;
            if (g !== ultimo) {
                var h = el('div', 'tn-gh tn-g-' + g);
                h.appendChild(el('span', 'tn-gt', T('todo.tn_g_' + g)));
                var n = el('span', 'tn-gn', '');
                h.appendChild(n);
                riga.parentNode.insertBefore(h, riga);
                intest[g] = n;
                ultimo = g;
            }
        });
        for (var g in intest) if (intest.hasOwnProperty(g)) intest[g].textContent = String(conto[g]);
    };

    /* righe pulite + scorciatoie per la data */
    var rigaBase = Todo.prototype.row;
    Todo.prototype.row = function (t, isSub) {
        var item = rigaBase.call(this, t, isSub);
        if (!usabile(this)) return item;
        if (t._pend) item.classList.add('tn-pend');
        if (this.compact || isSub) return item;
        var self = this;

        /* il giorno detto come una persona: «Domani» invece di «26 ott» */
        var oggiS = this.today || S.oggi();
        if (t.date) {
            var sp = item.querySelector('label.td-chip.td-set > span');
            if (sp) sp.textContent = giornoUmano(t.date, oggiS);
        }
        /* A riposo la riga mostra una frase sola («Domani · 15:00 · Salute»,
           in rosso se e' in ritardo); gli strumenti veri compaiono aprendola. */
        var parti = [];
        if (t.date) parti.push(giornoUmano(t.date, oggiS));
        if (t.time) parti.push(t.time + (t.minutes && t.minutes !== 30 ? ' · ' + t.minutes + ' min' : ''));
        var rigaMeta = null;
        if (parti.length || t.project_id) {
            rigaMeta = el('div', 'tn-meta');
            if (parti.length) rigaMeta.appendChild(el('span', 'tn-meta-q', parti.join(' · ')));
            if (t.project_id) {
                var pj = el('span', 'tn-meta-p');
                var pt = el('i', 'tn-meta-dot'); pt.style.background = t.project_color || '#B7AEA3';
                pj.appendChild(pt); pj.appendChild(el('span', null, t.project_name || T('todo.progetto')));
                rigaMeta.appendChild(pj);
            }
            rigaMeta.addEventListener('click', function () {
                var ti = item.querySelector('.td-title'); if (ti) ti.focus();
            });
        }
        /* le scorciatoie, visibili solo con la riga aperta (vedi il foglio di stile) */
        var main = item.querySelector('.td-main');
        if (main && t.id > 0) {
            var q = el('div', 'tn-quick');
            var oggi = this.today || S.oggi();
            var sab = S.piuGiorni(oggi, (5 - S.giornoSett(oggi) + 7) % 7 || 7);
            [['todo.tn_oggi', oggi], ['todo.tn_domani', S.piuGiorni(oggi, 1)], ['todo.tn_weekend', sab],
             ['todo.tn_fra_sett', S.piuGiorni(oggi, 7)], ['todo.tn_senza_data', '']].forEach(function (p) {
                var b = el('button', 'tn-q' + ((t.date || '') === p[1] ? ' tn-q-on' : ''), T(p[0]));
                b.type = 'button';
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    self.save(t, p[1] ? { date: p[1] } : { date: '', time: '' });
                });
                q.appendChild(b);
            });
            main.insertBefore(q, main.children[1] || null);
        }
        if (rigaMeta && main) main.insertBefore(rigaMeta, main.children[1] || null);
        return item;
    };

    /* Il vuoto: con la barra in cima non serve il «+», basta una frase. */
    var vuotoBase = Todo.prototype.emptyText;
    Todo.prototype.emptyText = function () {
        if (conBarra(this)) return this.listType === 'study' ? T('todo.tn_vuoto_study') : T('todo.tn_vuoto_todo');
        return vuotoBase.call(this);
    };
})();
