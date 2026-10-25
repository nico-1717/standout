/* todo_smart.js — la barra intelligente del to do (e dello smistamento a mano).

   Capisce una frase scritta come viene: «chiamare il dentista domani alle 15
   #salute», «ripasso cap 3 venerdì 1h». Ne ricava titolo, data, ora, durata e
   progetto; quello che avanza e' il titolo.

   PERCHE' UN FILE A PARTE
   Le regole per data e ora sono quelle del planner (planner_smart.json, lo
   stesso elenco di parole: aggiungere una parola là, la impara anche qui).
   Il codice, invece, e' una copia ridotta di smartParse() del planner
   (js/04_barra_e_priorita.js), non lo stesso codice: quello vive dentro la
   funzione unica del planner e usa il suo stato (S, RUBRICA...). Riusarlo
   avrebbe voluto dire toccare il planner, che funziona. Se le regole del
   planner cambiano nel JSON valgono per entrambi; se cambia l'algoritmo, va
   aggiornato anche qui.

   Nessuna IA: solo elenchi di parole e schemi, quindi istantaneo e gratis. */
(function () {
    'use strict';
    var SMART = null, caricando = null;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function daIso(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function oggi() { return iso(new Date()); }
    function piuGiorni(s, n) { var d = daIso(s); d.setDate(d.getDate() + n); return iso(d); }
    function giornoSett(s) { return (daIso(s).getDay() + 6) % 7; }   // 0 = lunedi'

    function carica() {
        if (SMART) return Promise.resolve(SMART);
        if (caricando) return caricando;
        caricando = fetch('../planner/planner_smart.json', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { SMART = d; return d; })
            .catch(function () { caricando = null; return null; });
        return caricando;
    }

    /* La Pasqua dell'anno (Meeus): serve a Pasquetta, Carnevale... */
    function pasquaDi(anno) {
        var a = anno % 19, b = Math.floor(anno / 100), c = anno % 100;
        var dd = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
        var g = Math.floor((b - f + 1) / 3), h = (19 * a + b - dd - g + 15) % 30;
        var i = Math.floor(c / 4), k = c % 4;
        var l = (32 + 2 * e + 2 * i - h - k) % 7;
        var m = Math.floor((a + 11 * h + 22 * l) / 451);
        var mese = Math.floor((h + l - 7 * m + 114) / 31);
        var giorno = ((h + l - 7 * m + 114) % 31) + 1;
        return anno + '-' + pad(mese) + '-' + pad(giorno);
    }

    /* quante correzioni da una parola all'altra: perdona «agsoto» */
    function distanza(a, b) {
        if (Math.abs(a.length - b.length) > 3) return 99;
        var prev = [], cur = [], j, i;
        for (j = 0; j <= b.length; j++) prev[j] = j;
        for (i = 1; i <= a.length; i++) {
            cur[0] = i;
            for (j = 1; j <= b.length; j++) {
                cur[j] = Math.min(cur[j - 1] + 1, prev[j] + 1, prev[j - 1] + (a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1));
            }
            prev = cur.slice();
        }
        return prev[b.length];
    }
    function trovaChiave(text, chiavi, tol) {
        var ord = chiavi.slice().sort(function (x, y) { return y.length - x.length; });
        for (var i = 0; i < ord.length; i++) if (text.indexOf(' ' + ord[i] + ' ') !== -1) return { key: ord[i], match: ord[i] };
        if (!tol) return null;
        var parole = text.split(/\s+/).filter(Boolean);
        for (var p = 0; p < parole.length; p++) {
            if (parole[p].length < 4) continue;
            for (var c = 0; c < ord.length; c++) {
                if (ord[c].indexOf(' ') !== -1) continue;
                if (distanza(parole[p], ord[c]) <= (ord[c].length <= 5 ? 1 : tol)) return { key: ord[c], match: parole[p] };
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------
       leggi(frase, progetti) -> { title, date, time, minutes, project, used }
       `progetti` = [{id, name}] per riconoscere «#nome». Date e ora sono
       null se la frase non le dice: chi chiama decide cosa fare del vuoto.
       --------------------------------------------------------------- */
    function leggi(raw, progetti) {
        var r = { title: '', date: null, time: null, minutes: 0, project: null, projectNew: null, used: [] };
        var text = ' ' + String(raw || '').trim() + ' ';
        var low = text.toLowerCase();
        /* si lavora sul minuscolo per capire, ma il titolo deve restare scritto
           come l'ha scritto l'utente: si tengono due testi allineati */
        var orig = text;
        function mangia(re) {
            var m = low.match(re);
            if (!m) return null;
            r.used.push(m[0].trim());
            low = low.slice(0, m.index) + ' '.repeat(m[0].length) + low.slice(m.index + m[0].length);
            orig = orig.slice(0, m.index) + ' '.repeat(m[0].length) + orig.slice(m.index + m[0].length);
            return m;
        }
        function togli(da, a) {
            low = low.slice(0, da) + ' '.repeat(a - da) + low.slice(a);
            orig = orig.slice(0, da) + ' '.repeat(a - da) + orig.slice(a);
        }
        function togliParola(parola) {
            var k = low.indexOf(' ' + parola + ' ');
            if (k !== -1) togli(k + 1, k + 1 + parola.length);
        }

        /* --- il progetto: #nome, anche parziale. Un #tag che non e' un progetto
               resta nel titolo: toglierlo in silenzio perderebbe quello che si
               era scritto --- */
        var mp = low.match(/\s#([^\s#!]+)/);
        if (mp) {
            var n = mp[1].toLowerCase().replace(/[_-]+/g, ' ');
            var trovato = (progetti || []).filter(function (p) { return String(p.name).toLowerCase() === n; })[0]
                       || (progetti || []).filter(function (p) { return String(p.name).toLowerCase().indexOf(n) === 0; })[0];
            if (trovato) { r.project = trovato; mangia(/\s#([^\s#!]+)/); }
            else r.projectNew = mp[1].replace(/[_-]+/g, ' ');
        }

        /* --- durata: «per 30 min», «1h», «1h30», «45'» --- */
        var md = mangia(/\s(?:per\s+|for\s+)?(\d{1,2})\s*(?:h|ore|ora|hours?|hr)\s*(\d{1,2})?\s*(?:min|m)?(?=\s)/)
              || null;
        if (md) r.minutes = Math.min(1440, (+md[1]) * 60 + (+md[2] || 0));
        else {
            md = mangia(/\s(?:per\s+|for\s+)?(\d{1,3})\s*(?:min|minuti|minutes?|')(?=\s)/);
            if (md) r.minutes = Math.min(1440, +md[1]);
        }

        if (!SMART) {
            r.title = pulisci(orig);
            return r;
        }
        var tol = SMART.tolleranza_errori_battitura || 0;
        var esplicita = false;

        /* --- ora --- */
        var ma = mangia(/\s(?:at\s+)?(1[0-2]|0?[1-9])(?:[:.]([0-5]\d))?\s*(am|pm)(?=\s)/);
        if (ma) { r.time = pad(parseInt(ma[1], 10) % 12 + (ma[3] === 'pm' ? 12 : 0)) + ':' + (ma[2] || '00'); esplicita = true; }
        if (!ma) {
            var mt = mangia(/\s(?:alle|alla|ore|ora|verso|at)?\s*([01]?\d|2[0-3])[:.]([0-5]\d)(?=\s)/);
            if (mt) { r.time = pad(+mt[1]) + ':' + mt[2]; esplicita = true; }
            else {
                var mh = mangia(/\s(?:alle|ore|ora|verso|at|around)\s+([01]?\d|2[0-3])(?=\s)/);
                if (mh) { r.time = pad(+mh[1]) + ':00'; esplicita = true; }
                else {
                    /* «domani 15 dentista»: un numero subito dopo un giorno e' l'ora,
                       a meno che dopo ci sia un mese («venerdì 23 ottobre») */
                    var giorni = Object.keys(SMART.giorni_settimana).concat(Object.keys(SMART.giorni_relativi))
                        .sort(function (a, b) { return b.length - a.length; })
                        .map(function (w) { return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
                    var mg = low.match(new RegExp('\\s(' + giorni.join('|') + ')\\s+([01]?\\d|2[0-3])(?=\\s)'));
                    if (mg) {
                        var dopo = (low.slice(mg.index + mg[0].length).trim().split(/\s+/)[0]) || '';
                        var eMese = dopo.length >= 3 && trovaChiave(' ' + dopo + ' ', Object.keys(SMART.mesi), tol);
                        if (!eMese) {
                            var da = mg.index + mg[0].length - mg[2].length;
                            r.time = pad(+mg[2]) + ':00'; esplicita = true; r.used.push(mg[2]);
                            togli(da, da + mg[2].length);
                        }
                    }
                }
            }
        }

        var anno = daIso(oggi()).getFullYear();
        /* --- feste fisse e mobili --- */
        var f = trovaChiave(low, Object.keys(SMART.festivita || {}), tol);
        if (f) {
            var inf = SMART.festivita[f.key];
            var c1 = anno + '-' + pad(inf.mese) + '-' + pad(inf.giorno);
            if (c1 < oggi()) c1 = (anno + 1) + '-' + pad(inf.mese) + '-' + pad(inf.giorno);
            r.date = c1; r.used.push(f.key); togliParola(f.match);
        }
        if (!r.date) {
            var fm = trovaChiave(low, Object.keys(SMART.festivita_mobili || {}), tol);
            if (fm) {
                var c2 = piuGiorni(pasquaDi(anno), SMART.festivita_mobili[fm.key]);
                if (c2 < oggi()) c2 = piuGiorni(pasquaDi(anno + 1), SMART.festivita_mobili[fm.key]);
                r.date = c2; r.used.push(fm.key); togliParola(fm.match);
            }
        }
        /* --- data scritta: 13/8, 13-08-2026 --- */
        if (!r.date) {
            var mn = mangia(/\s(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?(?=\s)/);
            if (mn) {
                var yy = mn[3] ? +mn[3] : anno; if (yy < 100) yy += 2000;
                r.date = yy + '-' + pad(+mn[2]) + '-' + pad(+mn[1]);
            }
        }
        /* --- «13 agosto», «august 13th» --- */
        if (!r.date) {
            var m2 = low.match(/\s(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?([a-zàèéìòù]{3,12})(?=\s)/);
            var m2b = m2 ? null : low.match(/\s([a-z]{3,12})\s+(\d{1,2})(?:st|nd|rd|th)?(?=\s)/);
            if (m2b && trovaChiave(' ' + m2b[1] + ' ', Object.keys(SMART.mesi), 0)) m2 = [m2b[0], m2b[2], m2b[1]];
            if (m2) {
                var mese = trovaChiave(' ' + m2[2] + ' ', Object.keys(SMART.mesi), tol);
                if (mese) {
                    var c3 = anno + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(+m2[1]);
                    if (c3 < oggi()) c3 = (anno + 1) + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(+m2[1]);
                    r.date = c3; r.used.push(m2[1] + ' ' + mese.key);
                    var k = low.indexOf(m2[0]); if (k !== -1) togli(k, k + m2[0].length);
                }
            }
        }
        /* --- fasce del giorno («stasera» = 20:00); i pasti qui NO: «preparare
               il pranzo» non e' un appuntamento alle 12:30 --- */
        if (!r.time && SMART.fasce_orarie) {
            var fasce = Object.keys(SMART.fasce_orarie).filter(function (k) {
                return (SMART.fasce_da_conservare || []).indexOf(k) !== -1 ||
                    ['mattina', 'pomeriggio', 'sera', 'notte', 'mezzogiorno', 'morning', 'afternoon', 'evening', 'night', 'noon'].indexOf(k) !== -1;
            });
            var fa = trovaChiave(low, fasce, 0);
            if (fa) r.time = SMART.fasce_orarie[fa.key];
        }
        /* --- oggi, domani, dopodomani --- */
        if (!r.date) {
            var rel = Object.keys(SMART.giorni_relativi).sort(function (a, b) { return b.length - a.length; });
            for (var i = 0; i < rel.length; i++) {
                if (low.indexOf(' ' + rel[i] + ' ') === -1) continue;
                r.date = piuGiorni(oggi(), SMART.giorni_relativi[rel[i]]);
                r.used.push(rel[i]); togliParola(rel[i]);
                break;
            }
        }
        /* --- giorno della settimana: il prossimo che capita --- */
        if (!r.date) {
            var wd = trovaChiave(low, Object.keys(SMART.giorni_settimana), tol);
            if (wd) {
                var delta = (SMART.giorni_settimana[wd.key] - 1 - giornoSett(oggi()) + 7) % 7;
                if (delta === 0) delta = 7;
                var piu = (SMART.settimana_prossima || []).some(function (w) { return low.indexOf(w) !== -1; });
                if (piu) delta += 7;
                r.date = piuGiorni(oggi(), delta); r.used.push(wd.key); togliParola(wd.match);
            }
        }
        /* un'ora senza giorno vale per oggi (o domani, se e' gia' passata) */
        if (r.time && !r.date) {
            var ora = new Date(), adesso = pad(ora.getHours()) + ':' + pad(ora.getMinutes());
            r.date = r.time < adesso && esplicita ? piuGiorni(oggi(), 1) : oggi();
        }

        r.title = pulisci(orig);
        return r;
    }

    /* il titolo e' cio' che resta, senza le paroline che accompagnavano data e ora */
    function pulisci(orig) {
        var tog = (SMART && SMART.parole_da_togliere) || [];
        var parole = String(orig).split(/\s+/).filter(Boolean);
        /* le paroline di cortesia si tolgono solo ai bordi: «dentista di mamma»
           deve restare com'e' */
        while (parole.length && tog.indexOf(parole[parole.length - 1].toLowerCase()) !== -1) parole.pop();
        while (parole.length > 1 && tog.indexOf(parole[0].toLowerCase()) !== -1) parole.shift();
        var t = parole.join(' ').trim();
        return t ? t.charAt(0).toUpperCase() + t.slice(1) : '';
    }

    /* ---------------------------------------------------------------
       Dove andrebbe una riga di nota (smistamento a mano, senza IA).
       Solo un suggerimento: la schermata di conferma lascia cambiare tutto.
       --------------------------------------------------------------- */
    var RE_BUY = /^(compra(?:re)?|acquista(?:re)?|ordina(?:re)?|prendere|buy|purchase|order|get)\b|€|\beur(?:o)?\b/i;
    var RE_EVENT = /\b(appuntamento|riunione|meeting|visita|dentista|dottore|cena|pranzo|aperitivo|call|colloquio|appointment|dinner|lunch|interview|concerto|concert|compleanno|birthday|volo|flight)\b/i;
    var RE_PROJECT = /^(progetto|project)\s*[:\-]?\s+/i;

    function destinazione(riga, letto) {
        var s = String(riga || '').trim();
        if (RE_PROJECT.test(s)) return 'project';
        if (RE_BUY.test(s)) return 'buy';
        if (letto && letto.date && RE_EVENT.test(s)) return 'event';
        return 'todo';
    }

    window.todoSmart = {
        carica: carica, leggi: leggi, destinazione: destinazione,
        oggi: oggi, piuGiorni: piuGiorni, giornoSett: giornoSett, iso: iso, daIso: daIso
    };
})();
