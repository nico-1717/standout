/* todo_buy.js — il "to buy", rifatto (ottobre 2026).

   Si aggancia al componente delle attività (todo_widget.php) e ne sostituisce
   solo la scheda degli acquisti. Cosa cambia rispetto a prima:

   - UNA RIGA PER AGGIUNGERE, sempre in cima. Si scrive "latte 1,20" e invio:
     l'articolo compare subito (un po' sbiadito finché il server non conferma)
     e la casella resta pronta per il successivo. Prima ogni "+" creava una
     riga vuota sul server, ricaricava tutto e solo dopo dava il cursore.
     Dentro la frase si capisce da solo:
       · un prezzo (1,20 · 1.20 · €3 · 3 euro)
       · la categoria con #nome (#casa), o dalle volte precedenti, o dalle
         parole (latte → spesa, shampoo → beauty) se una categoria così esiste
       · la priorità con !1 !2 !3 (o ! da solo = 1)
       · un link (https://…), che diventa il link dell'articolo
     Incollando più righe si aggiungono tutte.
   - SUGGERIMENTI dalla memoria degli acquisti: mentre si scrive, quelli che
     cominciano così; a casella vuota, «da ricomprare». Un tocco e l'articolo
     entra con la sua categoria e il suo prezzo. Tocco lungo = dimentica.
   - RIGHE ESSENZIALI: casella, nome, prezzo. Tutto il resto (priorità,
     categoria, link, foto, elimina) si apre toccando la freccetta.
   - SPUNTARE È ISTANTANEO: la riga si sbarra e scivola nel CARRELLO in fondo,
     con il suo totale; da lì si toglie la spunta o si svuota il carrello.
   - Totali sempre giusti: da comprare, nel carrello. */
(function () {
    'use strict';
    var W = window.standoutTodo;
    if (!W || !W.Todo || !W.h) return;
    var Todo = W.Todo, H = W.h;
    var el = H.el, api = H.api, euro = H.euro;

    function norm(s) {
        return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function chiave(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
    function centesimi(v) {
        var s = String(v == null ? '' : v).replace(/\s|€|eur(o)?/gi, '').replace(',', '.');
        if (s === '' || !/^\d{1,7}(\.\d{0,2})?$/.test(s)) return null;
        return Math.round(parseFloat(s) * 100);
    }
    function sommaCent(list) { var n = 0; list.forEach(function (t) { n += t.cents || 0; }); return n; }

    /* ---------------- il dizionario delle categorie ----------------
       Funziona solo se l'utente ha una categoria che si chiama in uno di
       questi modi: non ne inventa. */
    var DIZ = [
        { nomi: ['spesa', 'cibo', 'alimentari', 'alimenti', 'supermercato', 'food', 'groceries', 'grocery', 'supermarket'],
          parole: 'latte milk pane bread uova uovo eggs egg pasta riso rice farina flour zucchero sugar sale salt olio oil burro butter formaggio cheese mozzarella parmigiano yogurt carne meat pollo chicken manzo beef maiale pork pesce fish salmone salmon tonno tuna frutta fruit verdura vegetables veggies mele mela apples apple banane banana bananas arance oranges limoni lemons pomodori tomatoes patate potatoes cipolle onions aglio garlic insalata lettuce salad carote carrots zucchine courgettes caffe coffee te tea acqua water vino wine birra beer biscotti biscuits cookies cereali cereal prosciutto ham salame affettati legumi fagioli beans ceci lenticchie lentils cioccolato chocolate succo juice gelato ice cream pizza surgelati frozen marmellata jam miele honey noci nuts' },
        { nomi: ['casa', 'home', 'household', 'pulizia', 'pulizie', 'cleaning', 'domestico'],
          parole: 'detersivo detergent sapone piatti dish dishes spugna spugne sponge sponges carta igienica toilet paper scottex paper towels sacchetti bags spazzatura trash bin lampadina lampadine bulb bulbs pile batterie batteries candeggina bleach ammorbidente softener lavatrice washing anticalcare sgrassatore pellicola alluminio foil stracci cloth' },
        { nomi: ['beauty', 'bellezza', 'cura', 'cura personale', 'personal care', 'igiene', 'toiletries', 'cosmetici', 'cosmetics', 'bagno', 'bathroom'],
          parole: 'shampoo balsamo conditioner bagnoschiuma doccia shower gel deodorante deodorant dentifricio toothpaste spazzolino toothbrush filo interdentale floss crema creme cream rasoio razor lamette blades schiuma trucco makeup mascara rossetto lipstick profumo perfume smalto nail cotone cotton dischetti assorbenti tamponi pads' },
        { nomi: ['vestiti', 'abbigliamento', 'abiti', 'clothes', 'clothing', 'moda', 'fashion'],
          parole: 'maglia maglietta t shirt tshirt shirt camicia pantaloni trousers pants jeans calze calzini socks scarpe shoes sneakers stivali boots giacca jacket felpa hoodie sweater maglione gonna skirt vestito dress cappotto coat mutande boxer underwear reggiseno bra pigiama pyjamas cintura belt cappello hat sciarpa scarf guanti gloves' },
        { nomi: ['farmacia', 'salute', 'medicine', 'medicinali', 'pharmacy', 'health'],
          parole: 'tachipirina paracetamolo paracetamol ibuprofene ibuprofen moment aspirina aspirin cerotti plasters band aid vitamine vitamin vitamins sciroppo syrup termometro thermometer collirio gocce drops mascherine masks' },
        { nomi: ['tech', 'tecnologia', 'elettronica', 'electronics', 'informatica'],
          parole: 'cavo cable caricatore charger cuffie headphones auricolari earphones mouse tastiera keyboard usb hdmi chiavetta adattatore adapter powerbank monitor' },
        { nomi: ['animali', 'pet', 'pets', 'cane', 'gatto'],
          parole: 'crocchette kibble lettiera litter scatolette antiparassitario guinzaglio leash' },
        { nomi: ['cartoleria', 'scuola', 'ufficio', 'stationery', 'school', 'office', 'universita'],
          parole: 'penna penne pen pens quaderno quaderni notebook notebooks matita matite pencil evidenziatore highlighter gomma eraser righello ruler colla glue forbici scissors post it' }
    ];
    DIZ.forEach(function (d) { d.nomiN = d.nomi.map(norm); d.paroleN = ' ' + norm(d.parole) + ' '; });

    function catDaParole(titolo, cats) {
        var parole = norm(titolo).split(' ').filter(Boolean);
        for (var i = 0; i < DIZ.length; i++) {
            var d = DIZ[i];
            var trovata = parole.some(function (p) { return p.length > 1 && d.paroleN.indexOf(' ' + p + ' ') !== -1; });
            if (!trovata) continue;
            for (var j = 0; j < cats.length; j++) if (d.nomiN.indexOf(norm(cats[j].name)) !== -1) return cats[j].id;
        }
        return null;
    }

    /* "latte intero 1,20 #spesa !1" -> titolo, prezzo, categoria, priorità, link */
    function leggi(testo, inst) {
        var r = { title: '', cents: null, cat_id: null, catNuova: null, priority: null, url: null };
        var s = ' ' + String(testo || '').trim() + ' ';
        s = s.replace(/\s(https?:\/\/\S+|www\.\S+)/i, function (_, u) { r.url = u; return ' '; });
        s = s.replace(/\s!([123])?(?=\s)/, function (_, n) { r.priority = n ? +n : 1; return ' '; });
        s = s.replace(/\s#([^\s#!]+)/, function (_, nome) {
            var n = norm(nome);
            var c = inst.cats.filter(function (x) { return norm(x.name) === n; })[0]
                 || inst.cats.filter(function (x) { return norm(x.name).indexOf(n) === 0; })[0];
            if (c) r.cat_id = c.id; else r.catNuova = nome.replace(/[_-]+/g, ' ');
            return ' ';
        });
        /* il prezzo: un numero con i decimali, o con l'euro accanto. Un intero
           secco resta nel titolo ("2 avocado", "pile AA 4") */
        s = s.replace(/\s(?:€\s?(\d{1,7}(?:[.,]\d{1,2})?)|(\d{1,7}[.,]\d{1,2})\s?(?:€|eur(?:o)?)?|(\d{1,7})\s?(?:€|eur(?:o)?))(?=\s)/i,
            function (_, a, b, c) { r.cents = centesimi(a || b || c); return ' '; });
        r.title = s.replace(/\s+/g, ' ').trim();
        if (!r.title && r.url) { try { r.title = new URL(/^https?:/i.test(r.url) ? r.url : 'https://' + r.url).hostname.replace(/^www\./, ''); } catch (e) { r.title = r.url; } }
        /* quello che non è stato detto, dalla memoria e poi dalle parole */
        var m = inst.buyMem ? inst.buyMem[chiave(r.title)] : null;
        if (r.cat_id === null && !r.catNuova) r.cat_id = (m && m.cat_id && inst.catById(m.cat_id)) ? m.cat_id : catDaParole(r.title, inst.cats);
        if (r.cents === null && m && m.cents !== null) r.cents = m.cents;
        return r;
    }

    /* ---------------- memoria ---------------- */
    Todo.prototype.caricaMemoria = function () {
        var self = this;
        if (this._memInVolo) return this._memInVolo;
        this._memInVolo = api('buy_memory').then(function (r) {
            self._memInVolo = null;
            var m = {};
            self.buyMemList = (r && r.ok && r.items) || [];
            self.buyMemList.forEach(function (x) { m[x.k] = x; });
            self.buyMem = m;
            self.disegnaSuggerimenti();
        });
        return this._memInVolo;
    };

    /* ---------------- la barra in cima ---------------- */
    Todo.prototype.barraAcquisti = function () {
        var self = this;
        if (this.buyBar) return this.buyBar;
        var bar = el('div', 'tb-bar');
        var form = el('form', 'tb-add');
        form.setAttribute('autocomplete', 'off');
        var ic = el('span', 'tb-add-ic');
        ic.innerHTML = H.ICON_PLUS;
        var inp = el('input', 'tb-add-in');
        inp.type = 'text';
        inp.setAttribute('data-td-libero', '1');   // non blocca il ridisegno dell'elenco
        inp.setAttribute('enterkeyhint', 'done');
        inp.setAttribute('autocapitalize', 'sentences');
        inp.maxLength = 300;
        inp.placeholder = T('todo.tb_aggiungi_ph');
        var go = el('button', 'tb-add-go', T('todo.tb_aggiungi'));
        go.type = 'submit';
        form.appendChild(ic); form.appendChild(inp); form.appendChild(go);
        var aiuto = el('div', 'tb-hint');
        var sugg = el('div', 'tb-sugg');
        bar.appendChild(form); bar.appendChild(aiuto); bar.appendChild(sugg);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var v = inp.value.trim();
            if (!v) return;
            inp.value = '';
            self.aggiungiAcquisto(v);
            self.disegnaSuggerimenti();
            aiuto.textContent = '';
            inp.focus();
        });
        /* la sintassi si spiega una volta, a casella vuota e in mano */
        inp.addEventListener('focus', function () { if (!inp.value.trim()) aiuto.textContent = T('todo.tb_suggerimento'); });
        inp.addEventListener('blur', function () { if (!inp.value.trim()) aiuto.textContent = ''; });
        inp.addEventListener('input', function () {
            self.disegnaSuggerimenti();
            /* cosa ha capito, mentre si scrive: la conferma che "#casa" o
               "1,20" sono stati letti come categoria e prezzo */
            var v = inp.value.trim();
            if (!v) { aiuto.textContent = T('todo.tb_suggerimento'); return; }
            var r = leggi(v, self);
            var parti = [];
            if (r.cents !== null) parti.push(euro(r.cents));
            if (r.cat_id) { var c = self.catById(r.cat_id); if (c) parti.push('#' + c.name); }
            if (r.catNuova) parti.push('#' + r.catNuova + ' (' + T('todo.tb_nuova') + ')');
            if (r.priority) parti.push('!' + r.priority);
            if (r.url) parti.push('🔗');
            aiuto.textContent = parti.length ? '→ ' + (r.title || '…') + ' · ' + parti.join(' · ') : '';
        });
        /* più righe incollate = più articoli */
        inp.addEventListener('paste', function (e) {
            var testo = (e.clipboardData || window.clipboardData).getData('text');
            if (!testo || testo.indexOf('\n') === -1) return;
            e.preventDefault();
            testo.split(/\r?\n/).map(function (s) { return s.replace(/^[\s\-•*·\d.)]+(?=\D)/, '').trim(); })
                 .filter(Boolean).slice(0, 80).forEach(function (riga) { self.aggiungiAcquisto(riga); });
            self.disegnaSuggerimenti();
        });
        this.buyBar = bar;
        this.buyInput = inp;
        this.buySugg = sugg;
        return bar;
    };

    Todo.prototype.disegnaSuggerimenti = function () {
        var self = this, box = this.buySugg;
        if (!box || this.listType !== 'buy') return;
        box.innerHTML = '';
        var mem = this.buyMemList || [];
        if (!mem.length) return;
        var inLista = {};
        /* né quelli in elenco né quelli già nel carrello oggi */
        this.items.forEach(function (t) { inLista[chiave(t.title)] = true; });
        var q = norm(this.buyInput ? this.buyInput.value : '');
        var scelti;
        if (q) {
            scelti = mem.filter(function (m) { var n = norm(m.title); return !inLista[m.k] && n !== q && (n.indexOf(q) === 0 || n.indexOf(' ' + q) !== -1); }).slice(0, 6);
        } else {
            scelti = mem.filter(function (m) { return !inLista[m.k] && m.uses > 0; }).slice(0, 8);
            if (scelti.length) box.appendChild(el('span', 'tb-sugg-lab', T('todo.tb_ricompra')));
        }
        scelti.forEach(function (m) {
            var b = el('button', 'tb-chip');
            b.type = 'button';
            var c = m.cat_id ? self.catById(m.cat_id) : null;
            if (c) { var dot = el('i', 'tb-dot'); dot.style.background = c.color || '#B7AEA3'; b.appendChild(dot); }
            b.appendChild(el('span', null, m.title));
            if (m.cents !== null) b.appendChild(el('em', null, euro(m.cents)));
            var lungo = null, fatto = false;
            b.addEventListener('pointerdown', function () {
                fatto = false;
                lungo = setTimeout(function () {
                    fatto = true;
                    api('buy_forget', { k: m.k });
                    self.buyMemList = self.buyMemList.filter(function (x) { return x !== m; });
                    delete self.buyMem[m.k];
                    if (navigator.vibrate) { try { navigator.vibrate(12); } catch (e) {} }
                    self.disegnaSuggerimenti();
                }, 650);
            });
            ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { b.addEventListener(ev, function () { clearTimeout(lungo); }); });
            b.addEventListener('click', function () {
                if (fatto) return;
                self.aggiungiAcquisto(m.title, { cat_id: m.cat_id, cents: m.cents });
                if (self.buyInput) self.buyInput.value = '';
                self.disegnaSuggerimenti();
            });
            box.appendChild(b);
        });
    };

    /* ---------------- aggiungere: subito, poi il server ---------------- */
    Todo.prototype.aggiungiAcquisto = function (testo, gia) {
        var self = this;
        var r = gia ? { title: testo, cents: gia.cents, cat_id: gia.cat_id, priority: null, url: null } : leggi(testo, this);
        if (!r.title) return;
        this._tmp = (this._tmp || 0) - 1;
        var t = { id: this._tmp, list_type: 'buy', title: r.title, done: false, cat_id: r.cat_id || null,
                  priority: r.priority || 3, cents: r.cents, url: r.url, image: null, parent_id: null, _pend: true, _nuovo: true };
        this.items.push(t);
        (this._inViaggio = this._inViaggio || []).push(t);
        this.render();
        var crea = function () {
            var payload = { id: 0, title: r.title, parent_id: 0, list_type: 'buy', project_id: 0, date: '', time: '', minutes: 30,
                            source: 'page', buy_set: ['priority'], priority: t.priority };
            if (t.cat_id) { payload.cat_id = t.cat_id; payload.buy_set.push('cat_id'); }
            if (t.cents !== null && t.cents !== undefined) { payload.price = (t.cents / 100).toFixed(2); payload.buy_set.push('price'); }
            if (t.url) { payload.url = t.url; payload.buy_set.push('url'); }
            return api('save', payload).then(function (res) {
                self._inViaggio = (self._inViaggio || []).filter(function (x) { return x !== t; });
                if (!res || !res.ok || !res.todo) {
                    t._errore = true; t._pend = false;
                    if (self.items.indexOf(t) === -1) self.items.push(t);
                    self.render();
                    return;
                }
                /* tolto prima ancora di arrivare: si toglie anche di là */
                if (t._annullato) { api('delete', { id: res.todo.id }); return; }
                var vecchio = t.id;
                for (var k in res.todo) if (res.todo.hasOwnProperty(k)) t[k] = res.todo[k];
                t._pend = false;
                /* modifiche fatte mentre era ancora in viaggio */
                if (t._dopo) { var d = t._dopo; delete t._dopo; self.salvaAcquisto(t, d); }
                var riga = self.list.querySelector('.tb-r[data-id="' + vecchio + '"]');
                if (riga) { riga.dataset.id = t.id; riga.classList.remove('tb-pend'); }
                else self.render();
            });
        };
        /* una cosa per volta, nell'ordine in cui sono state scritte: due
           articoli aggiunti di fila non si scavalcano */
        if (r.catNuova) {
            this._codaBuy = (this._codaBuy || Promise.resolve()).then(function () {
                return api('cat_save', { id: 0, name: r.catNuova, color: '#B7AEA3' }).then(function (rc) {
                    if (rc && rc.ok && rc.categories) {
                        self.cats = rc.categories;
                        var c = rc.categories.filter(function (x) { return norm(x.name) === norm(r.catNuova); })[0];
                        if (c) t.cat_id = c.id;
                    }
                    self.render();
                });
            }).then(crea);
        } else {
            this._codaBuy = (this._codaBuy || Promise.resolve()).then(crea);
        }
        this._inVolo = this._codaBuy;
    };

    /* Un salvataggio di un articolo esistente; se è ancora in viaggio verso il
       server (id negativo) si accoda a quando arriva. */
    Todo.prototype.salvaAcquisto = function (t, cambi) {
        if (t.id < 0 || t._pend) { t._dopo = Object.assign(t._dopo || {}, cambi); return Promise.resolve(); }
        return this.save(t, cambi, true);
    };

    /* ---------------- disegno ---------------- */
    Todo.prototype.renderBuy = function () {
        var self = this;
        if (!this.buyMem && !this._memInVolo) this.caricaMemoria();
        /* la barra sta FUORI dall'elenco: l'elenco si ridisegna, la casella no
           (e il cursore resta dove è) */
        var bar = this.barraAcquisti();
        if (bar.parentNode !== this.wrap) this.wrap.insertBefore(bar, this.list);
        this.disegnaSuggerimenti();

        /* gli articoli ancora in viaggio verso il server restano in vista anche
           se nel frattempo l'elenco è stato riletto (e non li conteneva ancora) */
        var items = this.items.slice();
        (this._inViaggio || []).forEach(function (t) {
            if (t._annullato) return;
            if (items.indexOf(t) === -1 && !items.some(function (x) { return x.id === t.id; })) items.push(t);
        });
        var tutti = this.ordina(items).filter(function (t) { return !t.parent_id; });
        var daFare = tutti.filter(function (t) { return !t.done; });
        var fatti = tutti.filter(function (t) { return t.done; });

        var testa = el('div', 'tb-sum');
        var conto = el('div', 'tb-sum-n');
        conto.appendChild(el('b', null, String(daFare.length)));
        conto.appendChild(el('span', null, ' ' + (daFare.length === 1 ? T('todo.tb_da_comprare_1') : T('todo.tb_da_comprare_n'))));
        if (sommaCent(daFare)) { conto.appendChild(el('span', 'tb-sep', '·')); conto.appendChild(el('b', null, euro(sommaCent(daFare)))); }
        testa.appendChild(conto);
        var cb = el('button', 'tb-link', T('todo.categorie'));
        cb.type = 'button';
        cb.addEventListener('click', function () { self.catManager(); });
        testa.appendChild(cb);
        this.list.appendChild(testa);

        if (!daFare.length && !fatti.length) {
            var vuoto = el('div', 'tb-empty');
            vuoto.appendChild(el('b', null, T('todo.niente_da_comprare')));
            vuoto.appendChild(el('span', null, T('todo.tb_vuoto_aiuto')));
            this.list.appendChild(vuoto);
        }

        /* per categoria, nell'ordine delle categorie; senza categoria in fondo */
        var gruppi = [];
        this.cats.forEach(function (c) {
            var dentro = daFare.filter(function (t) { return self.catDi(t) === c.id; });
            if (dentro.length) gruppi.push({ cat: c, items: dentro });
        });
        var senza = daFare.filter(function (t) { return !self.catDi(t) || !self.catById(self.catDi(t)); });
        if (senza.length) gruppi.push({ cat: null, items: senza });

        gruppi.forEach(function (g) {
            var sez = el('section', 'tb-g');
            sez.style.setProperty('--c', (g.cat && g.cat.color) || '#B7AEA3');
            var h = el('div', 'tb-gh');
            h.appendChild(el('i', 'tb-dot'));
            h.appendChild(el('span', 'tb-gname', g.cat ? g.cat.name : T('todo.senza_categoria')));
            h.appendChild(el('span', 'tb-gn', String(g.items.length)));
            var sub = sommaCent(g.items);
            h.appendChild(el('span', 'tb-gtot', sub ? euro(sub) : ''));
            sez.appendChild(h);
            g.items.slice().sort(function (a, b) {
                var pa = self.prioDi(a), pb = self.prioDi(b);
                return pa !== pb ? pa - pb : 0;
            }).forEach(function (t) { sez.appendChild(self.rigaAcquisto(t)); });
            self.list.appendChild(sez);
        });

        /* il carrello: quello che è già stato preso oggi */
        if (fatti.length) {
            var cart = el('section', 'tb-cart' + (this.cartAperto ? ' tb-aperto' : ''));
            var ch = el('button', 'tb-cart-h');
            ch.type = 'button';
            ch.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.2 10.2a1.5 1.5 0 001.5 1.2h8.6a1.5 1.5 0 001.4-1.1L21 8H6.3"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/></svg>';
            ch.appendChild(el('span', 'tb-cart-t', T('todo.tb_carrello')));
            ch.appendChild(el('span', 'tb-gn', String(fatti.length)));
            ch.appendChild(el('span', 'tb-gtot', sommaCent(fatti) ? euro(sommaCent(fatti)) : ''));
            ch.addEventListener('click', function () { self.cartAperto = !self.cartAperto; cart.classList.toggle('tb-aperto', self.cartAperto); });
            cart.appendChild(ch);
            var corpo = el('div', 'tb-cart-b');
            fatti.forEach(function (t) { corpo.appendChild(self.rigaAcquisto(t)); });
            var svuota = el('button', 'tb-cart-clear', T('todo.tb_svuota_carrello'));
            svuota.type = 'button';
            svuota.addEventListener('click', function () {
                var ids = fatti.filter(function (t) { return t.id > 0; }).map(function (t) { return t.id; });
                self.items = self.items.filter(function (t) { return !t.done; });
                self.render();
                if (ids.length) api('bulk', { action: 'delete', ids: ids });
            });
            corpo.appendChild(svuota);
            corpo.appendChild(el('div', 'tb-cart-nota', T('todo.tb_carrello_nota')));
            cart.appendChild(corpo);
            this.list.appendChild(cart);
        }
    };

    Todo.prototype.rigaAcquisto = function (t) {
        var self = this;
        var aperta = !!(this.buyAperte && this.buyAperte[t.id]);
        var item = el('div', 'td-item tb-r' + (t.done ? ' tb-done' : '') + (t._pend ? ' tb-pend' : '') + (t._errore ? ' tb-err' : '')
                      + (aperta ? ' tb-open' : '') + (t._nuovo ? ' tb-nuovo' : '') + (this.edited[String(t.id)] ? ' td-in-modifica' : ''));
        delete t._nuovo;
        item.dataset.id = t.id;
        var row = el('div', 'td-row tb-row');

        if (this.selecting) row.appendChild(this.pickBox(t));
        else {
            var chk = el('button', 'tb-chk');
            chk.type = 'button';
            chk.setAttribute('aria-label', T(t.done ? 'todo.tb_togli_dal_carrello' : 'todo.tb_preso'));
            chk.innerHTML = H.ICON_CHECK;
            chk.addEventListener('click', function () { self.spuntaAcquisto(t, item); });
            row.appendChild(chk);
        }

        if (t.image) {
            var th = el('button', 'tb-thumb');
            th.type = 'button';
            th.style.backgroundImage = 'url("' + H.IMG_DIR + encodeURIComponent(t.image) + '")';
            th.setAttribute('aria-label', T('todo.immagine'));
            th.addEventListener('click', function () { self.pickImage(t, th); });
            row.appendChild(th);
        }

        var main = el('div', 'tb-main');
        var tit = el('input', 'td-title tb-tit');
        tit.type = 'text'; tit.value = t.title; tit.maxLength = 300;
        tit.placeholder = T('todo.cosa_vuoi_comprare');
        tit.addEventListener('input', function () {
            self.later('t' + t.id, 700, function () { t.title = tit.value; self.salvaAcquisto(t, { title: tit.value }); });
        });
        tit.addEventListener('blur', function () {
            if (tit.value.trim() === '') { self.togliAcquisto(t); return; }
            if (tit.value !== t.title) { t.title = tit.value; self.salvaAcquisto(t, { title: tit.value }); }
        });
        tit.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); tit.blur(); if (self.buyInput) self.buyInput.focus(); }
        });
        main.appendChild(tit);
        /* sotto il nome solo quello che c'è: priorità alta, link, categoria nel carrello */
        var meta = [];
        if ((t.priority || 3) < 3) meta.push(el('span', 'tb-pr tb-pr-' + t.priority, '!' + t.priority));
        if (t.url) {
            var a = el('a', 'tb-url');
            a.href = t.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
            try { a.textContent = new URL(t.url).hostname.replace(/^www\./, ''); } catch (e) { a.textContent = 'link'; }
            meta.push(a);
        }
        if (t._errore) meta.push(el('span', 'tb-errt', T('todo.tb_non_salvato')));
        if (meta.length) { var mb = el('div', 'tb-meta'); meta.forEach(function (m) { mb.appendChild(m); }); main.appendChild(mb); }
        row.appendChild(main);

        /* il prezzo: si tocca e si scrive lì */
        var pr = el('label', 'tb-price' + (t.cents !== null && t.cents !== undefined ? ' tb-set' : ''));
        var pin = el('input');
        pin.type = 'text'; pin.inputMode = 'decimal';
        pin.placeholder = '€';
        pin.value = (t.cents !== null && t.cents !== undefined) ? (t.cents / 100).toFixed(2).replace('.', ',') : '';
        pin.size = 5;
        pin.addEventListener('change', function () {
            var c = centesimi(pin.value);
            t.cents = c;
            pr.classList.toggle('tb-set', c !== null);
            pin.value = c !== null ? (c / 100).toFixed(2).replace('.', ',') : '';
            self.salvaAcquisto(t, { price: pin.value });
            self.aggiornaTotali();
        });
        pin.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); pin.blur(); } });
        pr.appendChild(pin);
        row.appendChild(pr);

        var more = el('button', 'tb-more');
        more.type = 'button';
        more.setAttribute('aria-label', T('todo.tb_dettagli'));
        more.setAttribute('aria-expanded', aperta ? 'true' : 'false');
        more.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
        more.addEventListener('click', function () {
            self.buyAperte = self.buyAperte || {};
            if (self.buyAperte[t.id]) delete self.buyAperte[t.id]; else self.buyAperte[t.id] = true;
            var nuova = self.rigaAcquisto(t);
            item.parentNode.replaceChild(nuova, item);
        });
        row.appendChild(more);
        item.appendChild(row);

        if (aperta) item.appendChild(this.dettagliAcquisto(t));
        item.appendChild(this.swipeTrash(t));
        this.enableSwipe(item, row);
        return item;
    };

    /* Il pannello sotto la riga: priorità, categoria, link, foto, elimina. */
    Todo.prototype.dettagliAcquisto = function (t) {
        var self = this;
        var box = el('div', 'tb-det');

        var r1 = el('div', 'tb-det-r');
        r1.appendChild(el('span', 'tb-det-l', T('todo.priorita')));
        var seg = el('div', 'tb-seg');
        [1, 2, 3].forEach(function (p) {
            var b = el('button', 'tb-seg-b' + ((t.priority || 3) === p ? ' tb-on' : ''), String(p));
            b.type = 'button';
            b.addEventListener('click', function () {
                self.inModifica(t.id);
                t.priority = p;
                seg.querySelectorAll('.tb-seg-b').forEach(function (x) { x.classList.toggle('tb-on', x === b); });
                self.salvaAcquisto(t, { priority: p });
            });
            seg.appendChild(b);
        });
        r1.appendChild(seg);
        box.appendChild(r1);

        var r2 = el('div', 'tb-det-r tb-det-cats');
        r2.appendChild(el('span', 'tb-det-l', T('todo.categoria')));
        var cats = el('div', 'tb-cats');
        [{ id: 0, name: T('todo.senza_categoria'), color: null }].concat(this.cats).forEach(function (c) {
            var b = el('button', 'tb-cat' + ((t.cat_id || 0) === c.id ? ' tb-on' : ''));
            b.type = 'button';
            if (c.color) { var d = el('i', 'tb-dot'); d.style.background = c.color; b.appendChild(d); }
            b.appendChild(el('span', null, c.name));
            b.addEventListener('click', function () {
                self.inModifica(t.id);
                t.cat_id = c.id || null;
                cats.querySelectorAll('.tb-cat').forEach(function (x) { x.classList.toggle('tb-on', x === b); });
                self.salvaAcquisto(t, { cat_id: c.id });
            });
            cats.appendChild(b);
        });
        var nuova = el('button', 'tb-cat tb-cat-add');
        nuova.type = 'button';
        nuova.innerHTML = H.ICON_PLUS;
        nuova.setAttribute('aria-label', T('todo.nuova_categoria'));
        nuova.addEventListener('click', function () { self.catManager(); });
        cats.appendChild(nuova);
        r2.appendChild(cats);
        box.appendChild(r2);

        var r3 = el('div', 'tb-det-r');
        r3.appendChild(el('span', 'tb-det-l', T('todo.tb_link')));
        var url = el('input', 'tb-det-in');
        url.type = 'url'; url.value = t.url || ''; url.placeholder = T('todo.link_articolo');
        url.addEventListener('change', function () { t.url = url.value.trim() || null; self.salvaAcquisto(t, { url: url.value }); });
        r3.appendChild(url);
        box.appendChild(r3);

        var r4 = el('div', 'tb-det-az');
        var foto = el('button', 'tb-det-b');
        foto.type = 'button';
        foto.innerHTML = H.ICON_IMG;
        foto.appendChild(el('span', null, t.image ? T('todo.immagine') : T('todo.tb_aggiungi_foto')));
        foto.addEventListener('click', function () { if (t.id > 0) self.pickImage(t, foto); });
        var del = el('button', 'tb-det-b tb-det-del');
        del.type = 'button';
        del.innerHTML = H.ICON_TRASH;
        del.appendChild(el('span', null, T('comune.elimina')));
        del.addEventListener('click', function () { self.togliAcquisto(t); });
        r4.appendChild(foto); r4.appendChild(del);
        box.appendChild(r4);
        return box;
    };

    /* Spuntare: la riga si sbarra subito e scivola nel carrello; il server
       lo sa un attimo dopo. Se dice di no, si torna indietro. */
    Todo.prototype.spuntaAcquisto = function (t, item) {
        var self = this;
        if (t.id < 0) return;   // ancora in viaggio
        t.done = !t.done;
        if (navigator.vibrate) { try { navigator.vibrate(t.done ? 10 : 6); } catch (e) {} }
        item.classList.add(t.done ? 'tb-va' : 'tb-torna');
        setTimeout(function () { self.render(); }, 260);
        api('toggle', { id: t.id }).then(function (r) {
            if (!r || !r.ok) { t.done = !t.done; self.render(); return; }
            t.done = !!r.done;
            /* comprato: entra (o sale) nella memoria */
            if (t.done) {
                var k = chiave(t.title);
                self.buyMem = self.buyMem || {};
                var m = self.buyMem[k];
                if (!m) { m = { k: k, title: t.title, cat_id: t.cat_id, cents: t.cents, uses: 0 }; self.buyMem[k] = m; (self.buyMemList = self.buyMemList || []).unshift(m); }
                m.uses++; if (t.cat_id) m.cat_id = t.cat_id; if (t.cents !== null) m.cents = t.cents;
            }
        });
    };

    Todo.prototype.togliAcquisto = function (t) {
        var self = this;
        this.items = this.items.filter(function (x) { return x !== t; });
        if (this.buyAperte) delete this.buyAperte[t.id];
        this.render();
        if (t.id > 0) api('delete', { id: t.id });
        else t._annullato = true;
    };

    /* i totali senza ridisegnare (cambiando un prezzo il cursore è lì) */
    Todo.prototype.aggiornaTotali = function () {
        var self = this;
        this.later('tot', 900, function () { self.render(); });
    };

    /* La casella in cima non deve bloccare il ridisegno: è fatta apposta per
       restare in mano all'utente mentre l'elenco cambia sotto. */
    var vecchioCampo = Todo.prototype.campoInMano;
    Todo.prototype.campoInMano = function () {
        var a = document.activeElement;
        if (a && a.getAttribute && a.getAttribute('data-td-libero') === '1') return false;
        return vecchioCampo.call(this);
    };

    /* Cambiando linguetta la barra degli acquisti va tolta. */
    var vecchioSet = Todo.prototype.setType;
    Todo.prototype.setType = function (tipo) {
        if (this.buyBar && this.buyBar.parentNode && tipo !== 'buy') this.buyBar.parentNode.removeChild(this.buyBar);
        return vecchioSet.call(this, tipo);
    };
})();
