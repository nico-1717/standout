/* todo_nota.js — la nota veloce della pagina To do, e lo smistamento.

   LA NOTA
   Un riquadro in cima, una nota sola: per segnare qualcosa in dieci secondi,
   quando non c'e' tempo di decidere se e' un'attivita', un acquisto o un
   appuntamento. Si tocca, si scrive, basta: si salva da sola (subito sul
   telefono, poco dopo sul server) e non c'e' un tasto «salva» da dimenticare.

   LO SMISTAMENTO
   Con piu' calma, la nota si svuota in due modi:
   - «A mano»: ogni riga viene letta dalle stesse regole della barra del to do
     (data, ora, durata, progetto, prezzo) e proposta nel posto che sembra
     giusto. Gratis e istantaneo.
   - «Con l'IA»: la nota va a todo_nota_ai.php, che propone dove mette ogni
     voce. Costa crediti, e per questo non parte mai da sola.
   In entrambi i casi non si salva niente subito: si apre la SCHERMATA DI
   CONFERMA, dove ogni voce si puo' correggere (dove va, titolo, giorno, ora,
   progetto, categoria, prezzo), togliere, e alla fine salvare o annullare.
   Le righe che non vengono salvate restano nella nota: non si perde niente.

   Si monta cosi', una volta sola, sopra l'elenco (solo todo.php):
       standoutTodoNota.mount(elemento);                                      */
(function () {
    'use strict';
    var W = window.standoutTodo;
    if (!W || !W.h || !window.todoSmart) return;
    var H = W.h, S = window.todoSmart, el = H.el, api = H.api;

    var LS_TESTO = 'nisama_todo_nota', LS_SPORCA = 'nisama_todo_nota_sporca';
    function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }

    var DEST = ['todo', 'buy', 'study', 'event', 'project'];
    var DEST_LAB = { todo: 'to do', buy: 'to buy', study: 'to study', event: null, project: null };
    function destEtichetta(d) {
        if (d === 'event') return T('todo.nota_d_event');
        if (d === 'project') return T('todo.nota_d_project');
        return DEST_LAB[d];
    }

    /* le righe della nota come le vede lo smistamento: senza vuote, senza
       puntini o numeri davanti. `orig` e' la posizione nel testo, per poter
       togliere dalla nota proprio quella riga dopo averla salvata. */
    function righeDi(testo) {
        var out = [];
        String(testo || '').split(/\r?\n/).forEach(function (r, i) {
            var pulita = r.replace(/^[\s\-•*·]+|^\d{1,2}[.)]\s+/, '').trim();
            if (pulita && out.length < 80) out.push({ orig: i, testo: pulita.slice(0, 300) });
        });
        return out;
    }

    /* ------------------------------------------------------------------
       il riquadro
       ------------------------------------------------------------------ */
    function Nota(host, opts) {
        this.host = host;
        this.opts = opts || {};
        this.testo = '';
        this.pronta = false;
        this.timer = null;
        this.build();
        this.carica();
    }

    Nota.prototype.build = function () {
        var self = this;
        var box = el('section', 'tnota');
        var testa = el('div', 'tnota-h');
        var ic = el('span', 'tnota-ic');
        ic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h11l3 3v13H5z"/><path d="M8 11h8M8 15h5"/></svg>';
        testa.appendChild(ic);
        testa.appendChild(el('span', 'tnota-t', T('todo.nota_titolo')));
        this.stato = el('span', 'tnota-st', '');
        testa.appendChild(this.stato);
        box.appendChild(testa);

        var ta = el('textarea', 'tnota-ta');
        ta.rows = 2;
        ta.placeholder = T('todo.nota_ph');
        ta.maxLength = 20000;
        ta.setAttribute('autocapitalize', 'sentences');
        ta.setAttribute('enterkeyhint', 'enter');
        ta.addEventListener('input', function () { self.cambiata(); });
        ta.addEventListener('focus', function () { box.classList.add('tnota-attiva'); });
        ta.addEventListener('blur', function () { self.salvaOra(); box.classList.remove('tnota-attiva'); });
        box.appendChild(ta);

        var azioni = el('div', 'tnota-az');
        this.conta = el('span', 'tnota-n', '');
        azioni.appendChild(this.conta);
        var mano = el('button', 'tnota-b', T('todo.nota_mano'));
        mano.type = 'button';
        mano.addEventListener('click', function () { self.smista('mano'); });
        var ia = el('button', 'tnota-b tnota-b-ia');
        ia.type = 'button';
        ia.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/></svg>';
        ia.appendChild(el('span', null, T('todo.nota_ia')));
        ia.addEventListener('click', function () { self.smista('ia'); });
        azioni.appendChild(mano); azioni.appendChild(ia);
        box.appendChild(azioni);

        this.box = box; this.ta = ta; this.btnMano = mano; this.btnIa = ia; this.azioni = azioni;
        this.host.appendChild(box);

        /* uscendo dalla pagina il salvataggio in attesa parte comunque */
        document.addEventListener('visibilitychange', function () { if (document.hidden) self.salvaOra(true); });
        window.addEventListener('pagehide', function () { self.salvaOra(true); });
        /* tornando sulla scheda la nota si rilegge: puo' essere stata scritta da un altro dispositivo */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && self.pronta && document.activeElement !== ta && !lsGet(LS_SPORCA)) self.carica(true);
        });
        this.aggiorna();
    };

    Nota.prototype.aggiorna = function () {
        var n = righeDi(this.ta.value).length;
        this.box.classList.toggle('tnota-piena', n > 0);
        this.conta.textContent = n ? T(n === 1 ? 'todo.nota_riga_1' : 'todo.nota_righe_n', { n: n }) : '';
        this.btnMano.disabled = this.btnIa.disabled = !n;
        /* cresce con il testo, fino a un limite: poi scorre */
        this.ta.style.height = 'auto';
        this.ta.style.height = Math.min(this.ta.scrollHeight + 2, 260) + 'px';
    };

    Nota.prototype.stato_ = function (t) { this.stato.textContent = t || ''; };

    Nota.prototype.carica = function (silenzioso) {
        var self = this;
        var locale = lsGet(LS_TESTO), sporca = lsGet(LS_SPORCA) === '1';
        return api('nota_leggi').then(function (r) {
            var server = (r && r.ok) ? String(r.body || '') : null;
            var scelto;
            /* se sul telefono c'e' una modifica non ancora arrivata al server,
               vince quella: e' l'ultima cosa scritta */
            if (sporca && locale !== null) scelto = locale;
            else if (server !== null) scelto = server;
            else scelto = locale || '';
            if (silenzioso && document.activeElement === self.ta) return;
            self.ta.value = scelto;
            self.testo = scelto;
            lsSet(LS_TESTO, scelto);
            self.pronta = true;
            self.aggiorna();
            if (sporca && server !== null && scelto !== server) self.salvaOra();
        });
    };

    Nota.prototype.cambiata = function () {
        var self = this;
        this.aggiorna();
        lsSet(LS_TESTO, this.ta.value);
        lsSet(LS_SPORCA, '1');
        this.stato_(T('todo.nota_salvo'));
        clearTimeout(this.timer);
        this.timer = setTimeout(function () { self.salvaOra(); }, 700);
    };

    Nota.prototype.salvaOra = function (insistente) {
        var self = this;
        clearTimeout(this.timer);
        if (lsGet(LS_SPORCA) !== '1' && !insistente) return Promise.resolve();
        if (!this.pronta) return Promise.resolve();
        var testo = this.ta.value;
        if (insistente && window.fetch) {
            /* la pagina si sta chiudendo: keepalive fa finire la richiesta */
            try {
                fetch('../planner/todo_api.php?ajax=nota_salva', {
                    method: 'POST', keepalive: true, credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.STANDOUT_CSRF || '' },
                    body: JSON.stringify({ body: testo })
                });
            } catch (e) {}
            return Promise.resolve();
        }
        return api('nota_salva', { body: testo }).then(function (r) {
            if (r && r.ok) {
                if (self.ta.value === testo) lsSet(LS_SPORCA, null);
                self.testo = testo;
                self.stato_(T('todo.nota_salvata'));
                setTimeout(function () { if (self.stato.textContent === T('todo.nota_salvata')) self.stato_(''); }, 1800);
            } else {
                self.stato_(T('todo.nota_offline'));
            }
        });
    };

    /* scrive nella nota (dopo uno smistamento) senza perdere quello che
       l'utente nel frattempo ha aggiunto in fondo */
    Nota.prototype.imposta = function (testo) {
        this.ta.value = testo;
        this.cambiata();
        this.salvaOra();
    };

    /* ------------------------------------------------------------------
       smistare
       ------------------------------------------------------------------ */
    Nota.prototype.smista = function (modo) {
        var self = this;
        var righe = righeDi(this.ta.value);
        if (!righe.length) return;
        var inst = W._last;
        this.salvaOra();
        if (modo === 'mano') {
            Promise.all([S.carica(), inst.progettiNoti()]).then(function () {
                var voci = [];
                righe.forEach(function (r, i) {
                    var letto = S.leggi(r.testo, inst._progetti || []);
                    var dest = S.destinazione(r.testo, letto);
                    voci.push(voceDaRiga(r.testo, letto, dest, i + 1));
                });
                new Conferma(self, righe, voci, inst);
            });
            return;
        }
        /* con l'IA: costa crediti, quindi un solo tocco ma con attesa visibile */
        if (this.inCorso) return;
        this.inCorso = true;
        this.btnIa.classList.add('tnota-attesa');
        this.btnIa.querySelector('span').textContent = T('todo.nota_ia_in_corso');
        var corpo = righe.map(function (r) { return r.testo; }).join('\n');
        fetch('../planner/todo_nota_ai.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.STANDOUT_CSRF || '' },
            body: JSON.stringify({ body: corpo })
        }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'rete' }; })
        .then(function (r) {
            self.inCorso = false;
            self.btnIa.classList.remove('tnota-attesa');
            self.btnIa.querySelector('span').textContent = T('todo.nota_ia');
            if (!r || !r.ok) {
                var m = (r && r.error === 'limite_piano') ? T('todo.nota_limite') : T('todo.nota_errore_ia');
                self.avviso(m);
                return;
            }
            Promise.all([inst.progettiNoti()]).then(function () {
                var voci = (r.items || []).map(function (it) {
                    return {
                        dest: it.dest, title: it.title, date: it.date || '', time: it.time || '', minutes: it.minutes || 0,
                        project_id: it.project_id || 0, cat_id: it.cat_id || 0, cat_name: it.cat_name || '',
                        price: it.price || '', priority: it.priority || 3, src: it.src || [], controlla: !!it.da_controllare, tieni: true
                    };
                });
                if (!voci.length) { self.avviso(T('todo.nota_errore_ia')); return; }
                new Conferma(self, righe, voci, inst, r.crediti);
            });
        });
    };

    Nota.prototype.avviso = function (msg) {
        var a = el('div', 'td-avviso', msg);
        document.body.appendChild(a);
        requestAnimationFrame(function () { a.classList.add('is-on'); });
        setTimeout(function () { a.classList.remove('is-on'); setTimeout(function () { a.remove(); }, 300); }, 3200);
    };

    /* una riga letta dalle regole -> una voce da confermare */
    function voceDaRiga(testo, letto, dest, n) {
        var v = { dest: dest, title: letto.title || testo, date: letto.date || '', time: letto.time || '', minutes: letto.minutes || 0,
                  project_id: letto.project ? letto.project.id : 0, cat_id: 0, cat_name: '', price: '', priority: 3, src: [n], tieni: true };
        if (dest === 'project') {
            v.title = testo.replace(/^(progetto|project)\s*[:\-]?\s+/i, '').replace(/^./, function (c) { return c.toUpperCase(); });
            v.date = ''; v.time = ''; v.minutes = 0; v.project_id = 0;
        }
        if (dest === 'buy') {
            var t = testo;
            /* il prezzo: un decimale o un numero con l'euro accanto */
            t = t.replace(/(?:^|\s)(?:€\s?(\d{1,6}(?:[.,]\d{1,2})?)|(\d{1,6}[.,]\d{1,2})\s?(?:€|eur(?:o)?)?|(\d{1,6})\s?(?:€|eur(?:o)?))(?=\s|$)/i,
                function (_, a, b, c) { v.price = String(a || b || c).replace(',', '.'); return ' '; });
            t = t.replace(/^(compra(?:re)?|acquista(?:re)?|ordina(?:re)?|prendere|buy|purchase|order|get)\s+/i, '').replace(/\s+/g, ' ').trim();
            v.title = (t || testo).replace(/^./, function (c) { return c.toUpperCase(); });
            v.date = ''; v.time = ''; v.minutes = 0;
        }
        if (dest === 'event' && !v.date) v.date = S.oggi();
        return v;
    }

    /* ------------------------------------------------------------------
       LA SCHERMATA DI CONFERMA
       ------------------------------------------------------------------ */
    function Conferma(nota, righe, voci, inst, crediti) {
        this.nota = nota; this.righe = righe; this.voci = voci; this.inst = inst; this.crediti = crediti || 0;
        this.cats = (inst._progettiCats || []).slice();
        var self = this;
        /* le categorie del to buy arrivano con i progetti */
        api('progetti').then(function (r) {
            self.progetti = (r && r.projects) || [];
            self.cats = (r && r.categories) || [];
            self.apri();
        });
    }

    Conferma.prototype.apri = function () {
        var self = this;
        var ov = el('div', 'tnc-ov');
        var card = el('div', 'tnc');
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'true');

        var testa = el('div', 'tnc-h');
        testa.appendChild(el('div', 'tnc-t', T('todo.nota_conferma_t')));
        this.sub = el('div', 'tnc-s', '');
        testa.appendChild(this.sub);
        card.appendChild(testa);

        this.lista = el('div', 'tnc-l');
        card.appendChild(this.lista);

        var piede = el('div', 'tnc-f');
        var ann = el('button', 'tnc-b', T('comune.annulla'));
        ann.type = 'button';
        ann.addEventListener('click', function () { self.chiudi(); });
        this.btnSalva = el('button', 'tnc-b tnc-b-ok', '');
        this.btnSalva.type = 'button';
        this.btnSalva.addEventListener('click', function () { self.salva(); });
        piede.appendChild(ann); piede.appendChild(this.btnSalva);
        card.appendChild(piede);

        ov.appendChild(card);
        ov.addEventListener('click', function (e) { if (e.target === ov) self.chiudi(); });
        this.keyh = function (e) { if (e.key === 'Escape') self.chiudi(); };
        document.addEventListener('keydown', this.keyh);
        document.body.appendChild(ov);
        document.body.classList.add('tnc-aperta');
        this.ov = ov; this.card = card;
        this.disegna();
    };

    Conferma.prototype.chiudi = function () {
        document.removeEventListener('keydown', this.keyh);
        document.body.classList.remove('tnc-aperta');
        if (this.ov) this.ov.remove();
    };

    Conferma.prototype.conta = function () { return this.voci.filter(function (v) { return v.tieni && String(v.title).trim(); }).length; };

    Conferma.prototype.aggiornaTesta = function () {
        var n = this.conta();
        this.sub.textContent = T('todo.nota_conferma_s', { n: this.voci.filter(function (v) { return v.tieni; }).length })
            + (this.crediti ? ' ' + T('todo.nota_crediti', { n: this.crediti }) : '');
        this.btnSalva.textContent = T(n === 1 ? 'todo.nota_salva_1' : 'todo.nota_salva_n', { n: n });
        this.btnSalva.disabled = !n;
    };

    Conferma.prototype.disegna = function () {
        var self = this;
        this.lista.innerHTML = '';
        this.voci.forEach(function (v, i) {
            if (!v.tieni) return;
            self.lista.appendChild(self.scheda(v, i));
        });
        if (!this.voci.some(function (v) { return v.tieni; })) {
            this.lista.appendChild(el('div', 'tnc-vuoto', T('todo.nota_tutto_tolto')));
        }
        this.aggiornaTesta();
    };

    function campo(etichetta, input) {
        var l = el('label', 'tnc-c');
        l.appendChild(el('span', null, etichetta));
        l.appendChild(input);
        return l;
    }

    Conferma.prototype.scheda = function (v, i) {
        var self = this;
        var s = el('div', 'tnc-v' + (v.controlla ? ' tnc-ctrl' : ''));
        s.dataset.i = i;

        /* dove va */
        var top = el('div', 'tnc-top');
        var seg = el('div', 'tnc-seg');
        seg.setAttribute('role', 'radiogroup');
        DEST.forEach(function (d) {
            var b = el('button', 'tnc-d tnc-d-' + d + (v.dest === d ? ' tnc-on' : ''), destEtichetta(d));
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', v.dest === d ? 'true' : 'false');
            b.addEventListener('click', function () {
                if (v.dest === d) return;
                v.dest = d;
                if (d === 'event' && !v.date) v.date = S.oggi();
                if (d === 'project') v.minutes = 0;
                self.sostituisci(s, v, i);
            });
            seg.appendChild(b);
        });
        top.appendChild(seg);
        var via = el('button', 'tnc-x');
        via.type = 'button';
        via.setAttribute('aria-label', T('todo.nota_togli'));
        via.title = T('todo.nota_togli_t');
        via.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        via.addEventListener('click', function () { v.tieni = false; self.disegna(); });
        top.appendChild(via);
        s.appendChild(top);

        /* il titolo */
        var tit = el('input', 'tnc-tit');
        tit.type = 'text'; tit.maxLength = 300; tit.value = v.title;
        tit.placeholder = T('todo.nota_titolo_ph');
        tit.addEventListener('input', function () { v.title = tit.value; self.aggiornaTesta(); });
        s.appendChild(tit);

        /* i dettagli, secondo dove va */
        var det = el('div', 'tnc-det');
        if (v.dest !== 'project') {
            if (v.dest !== 'buy' || v.date) {
                var d = el('input'); d.type = 'date'; d.value = v.date || '';
                d.addEventListener('change', function () { v.date = d.value; if (!d.value && v.dest !== 'event') { v.time = ''; } self.sostituisciPiano(s, v, i); });
                det.appendChild(campo(T('comune.data'), d));
            }
            if (v.dest !== 'buy' && (v.date || v.dest === 'event')) {
                var o = el('input'); o.type = 'time'; o.step = '300'; o.value = v.time || '';
                o.addEventListener('change', function () { v.time = o.value; });
                det.appendChild(campo(T('todo.nota_ora'), o));
                if (v.time) {
                    var m = el('input'); m.type = 'number'; m.min = '5'; m.max = '1440'; m.value = v.minutes || (v.dest === 'event' ? 60 : 30);
                    m.addEventListener('change', function () { v.minutes = +m.value || 0; });
                    det.appendChild(campo('min', m));
                }
            }
            if (v.dest !== 'buy') {
                var p = el('select');
                p.appendChild(new Option(T('todo.nota_senza_progetto'), '0'));
                (self.progetti || []).forEach(function (pr) { p.appendChild(new Option(pr.name, String(pr.id))); });
                p.value = String(v.project_id || 0);
                p.addEventListener('change', function () { v.project_id = +p.value || 0; });
                det.appendChild(campo(T('todo.progetto'), p));
            }
        }
        if (v.dest === 'buy') {
            var c = el('select');
            c.appendChild(new Option(T('todo.senza_categoria'), '0'));
            (self.cats || []).forEach(function (ct) { c.appendChild(new Option(ct.name, String(ct.id))); });
            if (v.cat_name && !v.cat_id) c.appendChild(new Option(v.cat_name + ' · ' + T('todo.nota_nuova_cat'), 'nuova'));
            c.value = v.cat_id ? String(v.cat_id) : (v.cat_name ? 'nuova' : '0');
            c.addEventListener('change', function () {
                if (c.value === 'nuova') return;
                v.cat_id = +c.value || 0; v.cat_name = '';
            });
            det.appendChild(campo(T('todo.nota_categoria'), c));
            var pr = el('input'); pr.type = 'text'; pr.inputMode = 'decimal'; pr.placeholder = '0,00'; pr.value = v.price ? String(v.price).replace('.', ',') : '';
            pr.addEventListener('input', function () { v.price = pr.value.replace(',', '.'); });
            det.appendChild(campo('€', pr));
        }
        s.appendChild(det);

        /* da dove viene, in piccolo: serve a ricordare cosa si era scritto */
        var da = (v.src || []).map(function (n) { return self.righe[n - 1] ? self.righe[n - 1].testo : ''; }).filter(Boolean).join(' · ');
        if (v.controlla) s.appendChild(el('div', 'tnc-avv', T('todo.nota_da_controllare')));
        if (da && da !== v.title) s.appendChild(el('div', 'tnc-da', T('todo.nota_da', { t: da })));
        return s;
    };

    Conferma.prototype.sostituisci = function (nodo, v, i) {
        var nuovo = this.scheda(v, i);
        nodo.parentNode.replaceChild(nuovo, nodo);
        this.aggiornaTesta();
    };
    /* cambia solo se serve ridisegnare i campi (togliere la data toglie l'ora) */
    Conferma.prototype.sostituisciPiano = function (nodo, v, i) { this.sostituisci(nodo, v, i); };

    Conferma.prototype.salva = function () {
        var self = this;
        var tieni = this.voci.filter(function (v) { return v.tieni && String(v.title).trim(); });
        if (!tieni.length || this.salvando) return;
        this.salvando = true;
        this.btnSalva.disabled = true;
        this.btnSalva.textContent = T('todo.nota_salvo');
        var items = tieni.map(function (v) {
            return { dest: v.dest, title: String(v.title).trim(), date: v.date || '', time: v.dest === 'buy' || !v.date && v.dest !== 'event' ? '' : (v.time || ''),
                     minutes: v.minutes || 0, project_id: v.project_id || 0, cat_id: v.cat_id || 0, cat_name: v.cat_name || '',
                     price: v.price || '', priority: v.priority || 3 };
        });
        api('nota_applica', { items: items }).then(function (r) {
            self.salvando = false;
            if (!r || !r.ok) {
                self.btnSalva.disabled = false;
                self.aggiornaTesta();
                self.nota.avviso(T('todo.nota_errore_salva'));
                return;
            }
            var esiti = r.results || [];
            var riuscite = [], fallite = 0;
            tieni.forEach(function (v, k) {
                if (esiti[k] && esiti[k].ok) riuscite.push(v); else { fallite++; v.fallita = true; }
            });
            /* una riga esce dalla nota solo se TUTTE le voci nate da lei sono
               state salvate (e nessuna e' stata tolta a mano) */
            var daTenere = {};
            self.voci.forEach(function (v) {
                var ok = v.tieni && riuscite.indexOf(v) !== -1;
                (v.src || []).forEach(function (n) { if (!ok) daTenere[n] = true; });
            });
            var tolte = {};
            self.voci.forEach(function (v) { (v.src || []).forEach(function (n) { if (!daTenere[n]) tolte[n] = true; }); });
            var origTolte = {};
            Object.keys(tolte).forEach(function (n) { if (self.righe[n - 1]) origTolte[self.righe[n - 1].orig] = true; });
            var rimaste = self.nota.ta.value.split(/\r?\n/).filter(function (_, i) { return !origTolte[i]; });
            /* la nota non si riempie di righe vuote lasciate dalle cancellazioni */
            self.nota.imposta(rimaste.join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\s+|\s+$/g, ''));

            self.chiudi();
            self.nota.avviso(fallite ? T('todo.nota_fatto_parziale', { n: riuscite.length, f: fallite })
                                     : T(riuscite.length === 1 ? 'todo.nota_fatto_1' : 'todo.nota_fatto_n', { n: riuscite.length }));
            if (r.categories && self.inst) self.inst.cats = r.categories;
            if (self.inst) { self.inst._progetti = null; self.inst.load(); }
            if (self.nota.opts.dopo) self.nota.opts.dopo(riuscite);
        });
    };

    window.standoutTodoNota = {
        mount: function (host, opts) { return new Nota(host, opts); }
    };
})();
