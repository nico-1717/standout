/* Standout - gestori di eventi SENZA codice inline.
 *
 * PERCHE' ESISTE
 * La Content-Security-Policy del sito non ammette piu' 'unsafe-inline' sugli
 * script. Un attributo come onclick="salva(5)" e' codice scritto dentro la
 * pagina: il browser lo rifiuta. Qui sotto c'e' il modo di scrivere la stessa
 * cosa senza eseguire testo come codice:
 *
 *     <button data-on-click="salva(5)">   invece di   <button onclick="salva(5)">
 *
 * Il nome dell'attributo e' "data-on-" + il nome dell'evento: data-on-click,
 * data-on-change, data-on-input, data-on-submit, data-on-keydown, data-on-focus, ...
 *
 * COSA CAPISCE
 * Un sottoinsieme MOLTO piccolo di JavaScript, letto da un interprete scritto
 * qui dentro (niente eval, niente new Function: sarebbero la stessa cosa che si
 * voleva vietare):
 *   - chiamate:            salva(5)   window.salva(5)   oggetto.metodo('x')
 *   - this, event:         apri(this)   apri(event, this)   this.value   event.target
 *   - letterali:           numeri, 'testo', true, false, null, {a:1}, [1,2]
 *   - operatori:           !  -  +  ===  !==  ==  !=  <  >  <=  >=  &&  ||
 *   - istruzioni:          a(); b();    if (...) a();    return a();
 *   - assegnazioni solo a: value, checked, hidden, disabled, textContent,
 *                          className, style.*, window.nome, location.href
 * Tutto il resto (funzioni scritte al volo, new, innerHTML=, eval, setTimeout,
 * fetch, ...) viene RIFIUTATO e scritto in console. Se un gestore serve di piu',
 * si scrive una funzione vera in un file .js e dall'attributo si chiama quella.
 *
 * COSA NON PROTEGGE
 * Se qualcuno riesce a far stampare del suo HTML nella pagina (cioe' se manca un
 * escape da qualche parte) puo' ancora scrivere un data-on-click che chiama una
 * funzione GIA' esistente del sito. Non puo' pero' eseguire codice suo, caricare
 * script da altri siti, ne' usare eval. Resta indispensabile fare l'escape di
 * tutto cio' che si stampa (sec_h) - questo file e' la seconda difesa, non la
 * prima.
 *
 * COME LEGA GLI EVENTI
 * Un listener per ogni elemento (non "delegato" sul documento): cosi' l'ordine
 * e stopPropagation() si comportano come con onclick="". Gli elementi aggiunti
 * dopo (innerHTML, cloneNode, template) li prende un MutationObserver.
 */
(function (global) {
    'use strict';
    if (global.StandoutEventi) return;

    var EVENTI = ['click', 'dblclick', 'change', 'input', 'submit', 'keydown', 'keyup', 'keypress',
        'focus', 'blur', 'mousedown', 'mouseup', 'mouseover', 'mouseout', 'mouseenter', 'mouseleave',
        'contextmenu', 'paste', 'dragstart', 'dragover', 'load', 'error'];

    /* Nomi che non si possono mai chiamare ne' leggere da un attributo. */
    var VIETATI = {
        eval: 1, Function: 1, constructor: 1, __proto__: 1, prototype: 1, __defineGetter__: 1,
        __defineSetter__: 1, __lookupGetter__: 1, __lookupSetter__: 1, setTimeout: 1, setInterval: 1,
        setImmediate: 1, execScript: 1, write: 1, writeln: 1, importScripts: 1, import: 1, fetch: 1,
        XMLHttpRequest: 1, sendBeacon: 1, open: 1, postMessage: 1, WebSocket: 1, Worker: 1,
        SharedWorker: 1, EventSource: 1, innerHTML: 1, outerHTML: 1, insertAdjacentHTML: 1,
        srcdoc: 1, createContextualFragment: 1, cookie: 1, localStorage: 1, sessionStorage: 1
    };

    /* Proprieta' in cui un gestore puo' scrivere. Niente innerHTML/src/href di link:
       le assegnazioni servono a stati e stili, non a cambiare il contenuto. */
    var SCRIVIBILI = { value: 1, checked: 1, hidden: 1, disabled: 1, textContent: 1, className: 1,
        selectedIndex: 1, scrollTop: 1, scrollLeft: 1, indeterminate: 1, readOnly: 1, media: 1 };

    function ko(msg) { var e = new Error(msg); e.standout = true; throw e; }

    /* ---------------- 1. TOKEN ---------------- */
    function token(src) {
        var out = [], i = 0, n = src.length, c, m;
        var RE_ID = /[A-Za-z_$][\w$]*/y, RE_NUM = /(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/y;
        var PUNT = ['===', '!==', '==', '!=', '<=', '>=', '&&', '||', '(', ')', '{', '}', '[', ']', ',', ';', '.', ':', '?', '=', '!', '+', '-', '<', '>'];
        while (i < n) {
            c = src.charAt(i);
            if (/\s/.test(c)) { i++; continue; }
            if (c === '/' && src.charAt(i + 1) === '/') { while (i < n && src.charAt(i) !== '\n') i++; continue; }
            if (c === '/' && src.charAt(i + 1) === '*') { var f = src.indexOf('*/', i + 2); i = f < 0 ? n : f + 2; continue; }
            if (c === '"' || c === "'") {
                var s = '', q = c; i++;
                while (i < n && src.charAt(i) !== q) {
                    var ch = src.charAt(i);
                    if (ch === '\\') {
                        i++; var e = src.charAt(i);
                        if (e === 'n') s += '\n'; else if (e === 't') s += '\t'; else if (e === 'r') s += '\r';
                        else if (e === 'u') { s += String.fromCharCode(parseInt(src.substr(i + 1, 4), 16)); i += 4; }
                        else if (e === 'x') { s += String.fromCharCode(parseInt(src.substr(i + 1, 2), 16)); i += 2; }
                        else s += e;
                        i++;
                    } else { s += ch; i++; }
                }
                if (i >= n) ko('stringa non chiusa');
                i++; out.push({ t: 'str', v: s }); continue;
            }
            RE_NUM.lastIndex = i;
            if ((/\d/.test(c) || (c === '.' && /\d/.test(src.charAt(i + 1)))) && (m = RE_NUM.exec(src))) {
                out.push({ t: 'num', v: parseFloat(m[0]) }); i += m[0].length; continue;
            }
            RE_ID.lastIndex = i;
            if ((m = RE_ID.exec(src))) { out.push({ t: 'id', v: m[0] }); i += m[0].length; continue; }
            var trovato = null;
            for (var k = 0; k < PUNT.length; k++) { if (src.substr(i, PUNT[k].length) === PUNT[k]) { trovato = PUNT[k]; break; } }
            if (!trovato) ko('carattere non ammesso: ' + c);
            out.push({ t: 'p', v: trovato }); i += trovato.length;
        }
        return out;
    }

    /* ---------------- 2. ALBERO ---------------- */
    function Parser(tok) { this.k = tok; this.i = 0; }
    Parser.prototype.peek = function () { return this.k[this.i]; };
    Parser.prototype.isP = function (v) { var t = this.k[this.i]; return t && t.t === 'p' && t.v === v; };
    Parser.prototype.isId = function (v) { var t = this.k[this.i]; return t && t.t === 'id' && t.v === v; };
    Parser.prototype.eatP = function (v) { if (this.isP(v)) { this.i++; return true; } return false; };
    Parser.prototype.needP = function (v) { if (!this.eatP(v)) ko('atteso "' + v + '"'); };

    Parser.prototype.programma = function () {
        var s = [];
        while (this.i < this.k.length) { if (this.eatP(';')) continue; s.push(this.istruzione()); }
        return s;
    };
    Parser.prototype.blocco = function () {
        if (this.eatP('{')) {
            var s = [];
            while (!this.isP('}')) { if (this.i >= this.k.length) ko('manca }'); if (this.eatP(';')) continue; s.push(this.istruzione()); }
            this.needP('}');
            return { k: 'blocco', s: s };
        }
        return this.istruzione();
    };
    Parser.prototype.istruzione = function () {
        var t = this.peek();
        if (t && t.t === 'id' && t.v === 'if') {
            this.i++; this.needP('(');
            var cond = this.espressione(); this.needP(')');
            var si = this.blocco(), no = null;
            this.eatP(';');
            if (this.isId('else')) { this.i++; no = this.blocco(); }
            return { k: 'if', c: cond, a: si, b: no };
        }
        if (t && t.t === 'id' && t.v === 'return') {
            this.i++;
            var v = null;
            if (this.i < this.k.length && !this.isP(';') && !this.isP('}')) v = this.espressione();
            this.eatP(';');
            return { k: 'return', v: v };
        }
        var e = this.espressione();
        if (this.eatP('=')) {
            var r = this.espressione();
            this.eatP(';');
            return { k: 'assegna', a: e, v: r };
        }
        if (this.i < this.k.length && !this.isP(';') && !this.isP('}')) ko('istruzione non valida');
        this.eatP(';');
        return { k: 'expr', e: e };
    };
    Parser.prototype.espressione = function () { return this.ternario(); };
    Parser.prototype.ternario = function () {
        var c = this.o();
        if (this.eatP('?')) { var a = this.ternario(); this.needP(':'); var b = this.ternario(); return { k: 'ter', c: c, a: a, b: b }; }
        return c;
    };
    Parser.prototype.o = function () { var l = this.e(); while (this.isP('||')) { this.i++; l = { k: 'log', op: '||', l: l, r: this.e() }; } return l; };
    Parser.prototype.e = function () { var l = this.uguale(); while (this.isP('&&')) { this.i++; l = { k: 'log', op: '&&', l: l, r: this.uguale() }; } return l; };
    Parser.prototype.uguale = function () {
        var l = this.conf();
        while (this.isP('===') || this.isP('!==') || this.isP('==') || this.isP('!=')) { var op = this.k[this.i++].v; l = { k: 'bin', op: op, l: l, r: this.conf() }; }
        return l;
    };
    Parser.prototype.conf = function () {
        var l = this.somma();
        while (this.isP('<') || this.isP('>') || this.isP('<=') || this.isP('>=')) { var op = this.k[this.i++].v; l = { k: 'bin', op: op, l: l, r: this.somma() }; }
        return l;
    };
    Parser.prototype.somma = function () {
        var l = this.unario();
        while (this.isP('+') || this.isP('-')) { var op = this.k[this.i++].v; l = { k: 'bin', op: op, l: l, r: this.unario() }; }
        return l;
    };
    Parser.prototype.unario = function () {
        if (this.isP('!') || this.isP('-') || this.isP('+')) { var op = this.k[this.i++].v; return { k: 'un', op: op, v: this.unario() }; }
        if (this.isId('typeof')) { this.i++; return { k: 'un', op: 'typeof', v: this.unario() }; }
        if (this.isId('new') || this.isId('function') || this.isId('delete') || this.isId('void')) ko('non ammesso: ' + this.peek().v);
        return this.suffisso();
    };
    Parser.prototype.suffisso = function () {
        var x = this.primario();
        for (;;) {
            if (this.eatP('.')) {
                var t = this.k[this.i++];
                if (!t || t.t !== 'id') ko('nome atteso dopo il punto');
                x = { k: 'mem', o: x, p: t.v };
            } else if (this.isP('[')) {
                this.i++; var ix = this.espressione(); this.needP(']');
                x = { k: 'idx', o: x, p: ix };
            } else if (this.isP('(')) {
                this.i++; var args = [];
                while (!this.isP(')')) { if (this.i >= this.k.length) ko('manca )'); args.push(this.espressione()); if (!this.eatP(',')) break; }
                this.needP(')');
                x = { k: 'call', f: x, a: args };
            } else break;
        }
        return x;
    };
    Parser.prototype.primario = function () {
        var t = this.k[this.i++];
        if (!t) ko('espressione incompleta');
        if (t.t === 'num' || t.t === 'str') return { k: 'lit', v: t.v };
        if (t.t === 'id') {
            if (t.v === 'true') return { k: 'lit', v: true };
            if (t.v === 'false') return { k: 'lit', v: false };
            if (t.v === 'null') return { k: 'lit', v: null };
            if (t.v === 'undefined') return { k: 'lit', v: undefined };
            if (t.v === 'NaN') return { k: 'lit', v: NaN };
            if (t.v === 'this') return { k: 'this' };
            if (t.v === 'event') return { k: 'event' };
            return { k: 'id', n: t.v };
        }
        if (t.t === 'p' && t.v === '(') { var x = this.espressione(); this.needP(')'); return x; }
        if (t.t === 'p' && t.v === '[') {
            var arr = [];
            while (!this.isP(']')) { if (this.i >= this.k.length) ko('manca ]'); arr.push(this.espressione()); if (!this.eatP(',')) break; }
            this.needP(']'); return { k: 'arr', a: arr };
        }
        if (t.t === 'p' && t.v === '{') {
            var props = [];
            while (!this.isP('}')) {
                if (this.i >= this.k.length) ko('manca }');
                var kt = this.k[this.i++];
                if (!kt || (kt.t !== 'id' && kt.t !== 'str' && kt.t !== 'num')) ko('chiave non valida');
                var key = String(kt.v);
                if (VIETATI[key]) ko('chiave non ammessa: ' + key);
                this.needP(':');
                props.push([key, this.espressione()]);
                if (!this.eatP(',')) break;
            }
            this.needP('}'); return { k: 'obj', p: props };
        }
        ko('simbolo inatteso: ' + t.v);
    };

    /* ---------------- 3. ESECUZIONE ---------------- */
    var RITORNO = { r: 1 };

    function nomeOk(n) { return !(VIETATI[n] || n.charAt(0) === '_' && n.charAt(1) === '_'); }

    function valuta(n, amb) {
        switch (n.k) {
            case 'lit': return n.v;
            case 'this': return amb.el;
            case 'event': return amb.ev;
            case 'id': if (!nomeOk(n.n)) ko('nome non ammesso: ' + n.n); return global[n.n];
            case 'mem': {
                var o = valuta(n.o, amb);
                if (!nomeOk(n.p)) ko('nome non ammesso: ' + n.p);
                if (o === null || o === undefined) ko('"' + n.p + '" letto da un valore vuoto');
                return o[n.p];
            }
            case 'idx': {
                var o2 = valuta(n.o, amb), p = valuta(n.p, amb);
                if (typeof p === 'string' && !nomeOk(p)) ko('nome non ammesso: ' + p);
                if (o2 === null || o2 === undefined) ko('lettura da un valore vuoto');
                return o2[p];
            }
            case 'call': {
                var f = n.f, ricevente, fn;
                if (f.k === 'mem') {
                    ricevente = valuta(f.o, amb);
                    if (!nomeOk(f.p)) ko('nome non ammesso: ' + f.p);
                    if (ricevente === null || ricevente === undefined) ko('"' + f.p + '" chiamato su un valore vuoto');
                    fn = ricevente[f.p];
                    if (typeof fn !== 'function') ko('non e\' una funzione: ' + f.p);
                } else if (f.k === 'id') {
                    if (!nomeOk(f.n)) ko('nome non ammesso: ' + f.n);
                    fn = global[f.n]; ricevente = global;
                    if (typeof fn !== 'function') ko('funzione non trovata: ' + f.n);
                } else ko('chiamata non ammessa');
                var args = [];
                for (var i = 0; i < n.a.length; i++) args.push(valuta(n.a[i], amb));
                return fn.apply(ricevente, args);
            }
            case 'un': {
                if (n.op === 'typeof') {
                    if (n.v.k === 'id') return typeof global[n.v.n];
                    return typeof valuta(n.v, amb);
                }
                var v = valuta(n.v, amb);
                return n.op === '!' ? !v : n.op === '-' ? -v : +v;
            }
            case 'bin': {
                var a = valuta(n.l, amb), b = valuta(n.r, amb);
                switch (n.op) {
                    case '===': return a === b; case '!==': return a !== b;
                    case '==': return a == b; case '!=': return a != b; // eslint-disable-line eqeqeq
                    case '<': return a < b; case '>': return a > b; case '<=': return a <= b; case '>=': return a >= b;
                    case '+': return a + b; case '-': return a - b;
                }
                return undefined;
            }
            case 'log': { var l = valuta(n.l, amb); return n.op === '&&' ? (l ? valuta(n.r, amb) : l) : (l ? l : valuta(n.r, amb)); }
            case 'ter': return valuta(n.c, amb) ? valuta(n.a, amb) : valuta(n.b, amb);
            case 'arr': return n.a.map(function (x) { return valuta(x, amb); });
            case 'obj': { var ob = {}; n.p.forEach(function (kv) { ob[kv[0]] = valuta(kv[1], amb); }); return ob; }
        }
        ko('nodo sconosciuto');
    }

    function assegna(n, amb) {
        var t = n.a, v = valuta(n.v, amb);
        if (t.k === 'id') { if (!nomeOk(t.n)) ko('nome non ammesso'); global[t.n] = v; return; }
        if (t.k !== 'mem') ko('assegnazione non ammessa');
        if (!nomeOk(t.p)) ko('nome non ammesso: ' + t.p);
        var o = valuta(t.o, amb);
        if (o === null || o === undefined) ko('assegnazione su un valore vuoto');
        /* location.href = '...': ok, ma mai javascript: (la CSP lo bloccherebbe, qui non lo si prova nemmeno) */
        if (t.p === 'href' && o === global.location) {
            if (typeof v !== 'string' || /^\s*(javascript|data|vbscript):/i.test(v)) ko('indirizzo non ammesso');
            o.href = v; return;
        }
        /* element.style.qualcosa = '...' */
        if (t.o.k === 'mem' && t.o.p === 'style') { o[t.p] = v; return; }
        /* window.nome = ... */
        if (o === global) { global[t.p] = v; return; }
        if (!SCRIVIBILI[t.p]) ko('non si puo\' scrivere in "' + t.p + '"');
        o[t.p] = v;
    }

    function esegui(s, amb) {
        switch (s.k) {
            case 'expr': valuta(s.e, amb); return;
            case 'assegna': assegna(s, amb); return;
            case 'return': amb.ritorno = s.v ? valuta(s.v, amb) : undefined; amb.finito = true; return;
            case 'if': {
                if (valuta(s.c, amb)) esegui(s.a, amb); else if (s.b) esegui(s.b, amb);
                return;
            }
            case 'blocco':
                for (var i = 0; i < s.s.length && !amb.finito; i++) esegui(s.s[i], amb);
                return;
        }
    }

    var CACHE = {};
    function compila(src) {
        if (CACHE.hasOwnProperty(src)) return CACHE[src];
        var p = new Parser(token(src));
        return (CACHE[src] = p.programma());
    }

    /* Esegue il testo di un attributo come farebbe onclick="...": this = elemento,
       event = evento; se il gestore ritorna false l'azione predefinita si ferma. */
    function eseguiTesto(src, el, ev) {
        var amb = { el: el, ev: ev, ritorno: undefined, finito: false };
        var prog;
        try { prog = compila(src); } catch (e) {
            console.error('[standout_events] gestore rifiutato: ' + src + ' -> ' + e.message);
            return;
        }
        try {
            for (var i = 0; i < prog.length && !amb.finito; i++) esegui(prog[i], amb);
        } catch (e) {
            if (e && e.standout) { console.error('[standout_events] ' + e.message + ' in: ' + src); return; }
            throw e; // un errore vero del codice del sito: resta visibile come sempre
        }
        if (amb.ritorno === false && ev && ev.cancelable) ev.preventDefault();
    }

    /* ---------------- 4. LEGAME AGLI ELEMENTI ---------------- */
    var SELETTORE = EVENTI.map(function (n) { return '[data-on-' + n + ']'; }).join(',');

    function lega(el) {
        if (!el || el.nodeType !== 1 || !el.hasAttribute) return;
        for (var i = 0; i < EVENTI.length; i++) {
            var nome = EVENTI[i];
            if (!el.hasAttribute('data-on-' + nome)) continue;
            var chiave = '__soLegato_' + nome;
            if (el[chiave]) continue;
            el[chiave] = true;
            (function (nome) {
                el.addEventListener(nome, function (ev) {
                    var testo = el.getAttribute('data-on-' + nome);
                    if (testo) eseguiTesto(testo, el, ev);
                });
            })(nome);
        }
    }
    function leggaTutto(radice) {
        if (!radice) return;
        if (radice.nodeType === 1) lega(radice);
        if (radice.querySelectorAll) {
            var lista = radice.querySelectorAll(SELETTORE);
            for (var i = 0; i < lista.length; i++) lega(lista[i]);
        }
    }

    function avvia() {
        leggaTutto(document);
        if (typeof MutationObserver === 'function') {
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    var r = records[i];
                    if (r.type === 'attributes') { lega(r.target); continue; }
                    for (var j = 0; j < r.addedNodes.length; j++) leggaTutto(r.addedNodes[j]);
                }
            }).observe(document.documentElement, {
                childList: true, subtree: true, attributes: true,
                attributeFilter: EVENTI.map(function (n) { return 'data-on-' + n; })
            });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { leggaTutto(document); });
    }

    /* ---------------- 5. <style> CREATI DA JAVASCRIPT ----------------
       La CSP accetta uno <style> solo col nonce della pagina. Gli stili scritti nei
       template PHP lo portano gia'; quelli creati da uno script (MathJax ne crea
       uno suo per le formule) no: qui ogni <style> nato con createElement riceve il
       nonce. E' sicuro perche' lo puo' fare solo codice che gira GIA' nella pagina:
       del codice HTML infilato da un attaccante, che non passa da createElement,
       il browser continua a rifiutare gli stili. */
    function nonceDellaPagina() {
        var m = document.querySelector('meta[name="csp-nonce"]');
        return m ? m.getAttribute('content') : '';
    }
    function curaStiliCreati() {
        var nonce = nonceDellaPagina();
        if (!nonce || document.__soNonceStili) return;
        document.__soNonceStili = true;
        var originale = document.createElement;
        document.createElement = function (tag) {
            var el = originale.apply(this, arguments);
            if (typeof tag === 'string' && tag.toLowerCase() === 'style') el.setAttribute('nonce', nonce);
            return el;
        };
        var originaleNS = document.createElementNS;
        document.createElementNS = function (ns, tag) {
            var el = originaleNS.apply(this, arguments);
            if (typeof tag === 'string' && tag.toLowerCase() === 'style' && ns === 'http://www.w3.org/1999/xhtml') el.setAttribute('nonce', nonce);
            return el;
        };
    }

    global.StandoutEventi = { esegui: eseguiTesto, lega: leggaTutto, _compila: compila };
    if (typeof document !== 'undefined') { avvia(); curaStiliCreati(); }
})(typeof window !== 'undefined' ? window : globalThis);
