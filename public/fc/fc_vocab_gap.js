/* fc_vocab_gap.js — LA PARTE NASCOSTA DI UNA FRASE D'ESEMPIO (solo lingue)
 *
 * Un solo posto per tutto quello che riguarda "cosa sparisce nel gioco":
 * il calcolo (gemello di lib/fc/fc_vocab_gap.php: se cambia uno, va cambiato
 * l'altro) e il componente per sceglierlo a mano, che usano allo stesso modo
 * il modulo della parola (fc_vocab_examples.js), l'inserimento multiplo e
 * l'import (fc_insert.php). Prima la scelta stava scritta dentro il modulo
 * della parola e l'inserimento multiplo non l'aveva: due copie sarebbero
 * rimaste uguali solo fino al primo ritocco.
 *
 * LA PARTE NASCOSTA PUO' ESSERE A PEZZI. "I want to" in "I want you to come":
 * spariscono "I want" e "to", "you" resta. Per questo gap_text non e' piu'
 * solo un pezzo di frase:
 *   ''                         automatica (la parola della carta, o una sua forma)
 *   'I want'                   un pezzo solo, scritto come prima (vale ancora)
 *   '[[0,"I want"],[11,"to"]]' piu' pezzi: [posizione, testo] per ognuno
 * La posizione serve solo a scegliere QUALE "to" se nella frase ce ne sono
 * due: se la frase viene corretta, si prende il "to" piu' vicino a dove
 * stava. Il testo e' quello che conta.
 *
 * L'APOSTROFO SEPARA. Scegliendo a mano, "I'm" sono tre tasti: I ' m, cosi'
 * si puo' nascondere solo "I" o solo "m". Nel calcolo automatico invece
 * "I'm" vale "I am", "you're" "you are", ecc.: la carta "I am" trova da sola
 * "I'm tired".
 *
 * Le posizioni sono in caratteri UTF-16 (quelle di String.slice), che per
 * il testo delle frasi coincidono con i caratteri di mb_* in PHP: le lettere
 * fuori dal piano base (emoji) in un esempio non contano come parole.
 */
(function () {
    'use strict';
    if (window.fcvGap) return;

    /* ============================================================
       1. TESTO
       ============================================================ */
    function norm(s) { return String(s || '').replace(/[’‘ʼ]/g, "'"); }
    function lettera(ch) { return !!ch && /[\p{L}\p{N}\p{M}]/u.test(ch); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function minuscolo(t) {
        /* toLowerCase puo' cambiare la lunghezza di qualche carattere raro:
           allora le posizioni non tornerebbero, e si confronta com'e'. */
        var l = t.toLowerCase();
        return l.length === t.length ? l : t;
    }

    /* Tutte le volte che `ago` compare in `testo` (senza badare alle
       maiuscole), con un segno per quelle "intere": senza lettere attaccate
       prima o dopo. */
    function occorrenze(testo, ago) {
        var out = [];
        if (!ago) return out;
        var tl = minuscolo(norm(testo)), al = minuscolo(norm(ago));
        if (tl.length !== testo.length) tl = norm(testo);
        var da = 0, p;
        while ((p = tl.indexOf(al, da)) !== -1) {
            var bordoA = !lettera(al.charAt(0)) || !lettera(tl.charAt(p - 1));
            var bordoB = !lettera(al.charAt(al.length - 1)) || !lettera(tl.charAt(p + al.length));
            out.push({ p: p, intera: bordoA && bordoB });
            da = p + 1;
        }
        return out;
    }

    /* La prima volta intera (o, con ancheDentro, la prima qualunque). */
    function trova(testo, ago, ancheDentro) {
        var occ = occorrenze(testo, ago);
        for (var i = 0; i < occ.length; i++) if (occ[i].intera) return occ[i].p;
        return (ancheDentro && occ.length) ? occ[0].p : -1;
    }

    /* ============================================================
       2. LA SCELTA SALVATA (gap_text)
       ============================================================ */
    /* -> null (automatica) | [{ da, t }] */
    function leggi(gap) {
        var s = String(gap == null ? '' : gap).trim();
        if (!s) return null;
        if (s.charAt(0) === '[') {
            try {
                var j = JSON.parse(s);
                if (Array.isArray(j)) {
                    var pz = j.map(function (x) {
                        return Array.isArray(x) ? { da: parseInt(x[0], 10) || 0, t: String(x[1] || '') } : null;
                    }).filter(function (x) { return x && x.t.trim(); });
                    if (pz.length) return pz;
                }
            } catch (e) {}
        }
        return [{ da: -1, t: s }];   // il vecchio formato: un pezzo, posizione ignota
    }

    /* [{da, a}] -> gap_text. Un pezzo solo resta scritto come prima. */
    function scrivi(testo, pezzi) {
        if (!pezzi || !pezzi.length) return '';
        if (pezzi.length === 1) {
            var t1 = testo.slice(pezzi[0].da, pezzi[0].a);
            /* senza posizione si prende la prima: se e' proprio quella, basta il testo */
            if (trova(testo, t1, true) === pezzi[0].da) return t1;
        }
        return JSON.stringify(pezzi.map(function (p) { return [p.da, testo.slice(p.da, p.a)]; }));
    }

    /* I pezzi scelti, ritrovati nella frase com'e' adesso. null se anche uno
       solo non c'e' piu': meglio la scelta automatica che una a meta'. */
    function risolvi(testo, scelta) {
        var out = [], fine = 0;
        for (var i = 0; i < scelta.length; i++) {
            var s = scelta[i];
            var occ = occorrenze(testo, s.t).filter(function (o) { return o.p >= fine; });
            if (!occ.length) return null;
            var intere = occ.filter(function (o) { return o.intera; });
            var cand = intere.length ? intere : occ;
            var meglio = cand[0];
            if (s.da >= 0) {
                cand.forEach(function (o) { if (Math.abs(o.p - s.da) < Math.abs(meglio.p - s.da)) meglio = o; });
            }
            out.push({ da: meglio.p, a: meglio.p + s.t.length, tipo: 'scelta' });
            fine = meglio.p + s.t.length;
        }
        return out;
    }

    /* ============================================================
       3. IL CALCOLO: cosa sparisce
       ============================================================
       In quest'ordine (gemello di fcv_lacune() in PHP):
       1. 'scelta'   la scelta fatta a mano, se nella frase c'e' ancora;
       2. 'trattini' le vecchie file di _ (esempi importati prima);
       3. 'parola'   la parola della carta scritta uguale, intera;
       4. 'simile'   la parola con un'altra desinenza, contrazioni comprese,
                     anche a pezzi ("give up" in "give it up").
       Torna [{da, a, tipo}] in ordine, oppure []. */
    function lacune(testo, parola, gap) {
        testo = String(testo || '');
        if (!testo.trim()) return [];
        var scelta = leggi(gap);
        if (scelta) {
            var r = risolvi(testo, scelta);
            if (r) return r;
        }
        var tratt = [], re = /_+/g, m;
        while ((m = re.exec(testo)) !== null) tratt.push({ da: m.index, a: m.index + m[0].length, tipo: 'trattini' });
        if (tratt.length) return tratt;
        var w = String(parola || '').trim();
        if (w) {
            var p = trova(testo, w, false);
            if (p !== -1) return [{ da: p, a: p + w.length, tipo: 'parola' }];
            var sim = simile(testo, w);
            if (sim) return sim;
        }
        return [];
    }

    /* Le parole di un testo per il confronto: minuscole, con la posizione.
       Le contrazioni si aprono: "I'm" diventa "i" + "am" (lo stesso pezzo
       di frase, diviso all'apostrofo), "don't" resta intera. */
    var CONTRAZIONI = { m: 'am', re: 'are', s: 'is', ve: 'have', ll: 'will', d: 'would' };
    function paroleConfronto(testo) {
        var out = [], re = /[\p{L}\p{N}\p{M}]+(?:['’][\p{L}\p{N}\p{M}]+)*/gu, m;
        while ((m = re.exec(testo)) !== null) {
            var t = minuscolo(norm(m[0]));
            var c = /^(.+)'(m|re|s|ve|ll|d)$/.exec(t);
            if (c && !/n$/.test(c[1])) {
                var taglio = m.index + c[1].length;
                out.push({ t: c[1], da: m.index, a: taglio });
                out.push({ t: CONTRAZIONI[c[2]], da: taglio, a: m.index + m[0].length });
            } else {
                out.push({ t: t, da: m.index, a: m.index + m[0].length });
            }
        }
        return out;
    }

    var DESINENZE_CORTE = ['s', 'es', 'ed', 'd', 'ing', 'n', 'en', 'ne', 'er'];
    var PAROLINE = ['to', 'a', 'an', 'the', 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'el', 'los', 'las', 'der', 'die', 'das', 'les', 'une', 'you', 'he', 'she', 'it', 'we', 'they', 'io', 'tu', 'lui', 'lei', 'noi', 'voi', 'loro', 'yo', 'ich', 'du', 'er', 'sie', 'wir', 'je', 'elle', 'nous', 'vous', 'ils', 'elles'];

    /* Quanto una parola della frase (t) somiglia a una della carta (w):
       3 uguale, 2 con una desinenza, 1 con lo stesso inizio, 0 per niente. */
    function punteggio(w, t) {
        if (w === t) return 3;
        if (/n't$/.test(w) && /n't$/.test(t)) return punteggio(w.slice(0, -3), t.slice(0, -3)) ? 2 : 0;
        if (t.indexOf(w) === 0) {
            var suf = t.slice(w.length);
            if (w.length <= 3) {
                var ult = w.charAt(w.length - 1);
                return (DESINENZE_CORTE.indexOf(suf) >= 0 || suf === ult + 'ing' || suf === ult + 'ed' || suf === ult + 'er') ? 2 : 0;
            }
            return suf.length <= 4 ? 2 : 0;
        }
        if (w.length < 4 || t.length < 3 || Math.abs(t.length - w.length) > 4) return 0;
        var cp = 0;
        while (cp < w.length && cp < t.length && w.charAt(cp) === t.charAt(cp)) cp++;
        return cp >= Math.max(3, Math.ceil(w.length * 0.7)) ? 1 : 0;
    }

    /* La forma della parola nella frase. Per ogni parola della carta si cerca
       la piu' somigliante; fra una e l'altra possono stare fino a due parole
       ("set it up"), che RESTANO visibili: la parte nascosta e' fatta solo
       delle parole della carta, e quelle vicine si uniscono in un pezzo. */
    function simile(testo, parola) {
        var frase = paroleConfronto(testo);
        if (!frase.length) return null;
        var meglio = null;
        String(parola).split(/[,;\/]/).forEach(function (alt) {
            var pz = paroleConfronto(alt).map(function (x) { return x.t; });
            if (!pz.length) return;
            var varianti = [pz];
            if (pz.length > 1 && PAROLINE.indexOf(pz[0]) >= 0) varianti.push(pz.slice(1));
            varianti.forEach(function (v) {
                for (var i = 0; i < frase.length; i++) {
                    var s0 = punteggio(v[0], frase[i].t);
                    if (!s0) continue;
                    var tot = s0, j = i, presi = [i], ok = true;
                    for (var k = 1; k < v.length && ok; k++) {
                        ok = false;
                        for (var salto = 1; salto <= 3 && j + salto < frase.length; salto++) {
                            var sk = punteggio(v[k], frase[j + salto].t);
                            if (sk) { tot += sk - (salto - 1) * 0.5; j += salto; presi.push(j); ok = true; break; }
                        }
                    }
                    if (!ok) continue;
                    var punti = tot / v.length;
                    if (!meglio || punti > meglio.punti) meglio = { punti: punti, presi: presi };
                }
            });
        });
        if (!meglio) return null;
        var out = [];
        meglio.presi.forEach(function (idx, n) {
            var w = frase[idx];
            if (n > 0 && meglio.presi[n - 1] === idx - 1) out[out.length - 1].a = w.a;
            else out.push({ da: w.da, a: w.a, tipo: 'simile' });
        });
        return out;
    }

    /* Compatibilita': chi chiedeva un pezzo solo riceve dall'inizio del primo
       alla fine dell'ultimo. */
    function lacuna(testo, parola, gap) {
        var l = lacune(testo, parola, gap);
        if (!l.length) return null;
        return { da: l[0].da, a: l[l.length - 1].a, tipo: l[0].tipo };
    }

    function eAutomatica(l) { return !!(l.length && (l[0].tipo === 'parola' || l[0].tipo === 'simile')); }

    /* La frase in HTML con le parti nascoste marcate. dentro(pezzo) disegna
       una parte nascosta, fuori(pezzo) il resto (gia' messi in sicurezza da
       chi li riceve). Una fila di _ si riempie con la parola. */
    function html(testo, parola, gap, dentro, fuori) {
        testo = String(testo || '');
        var w = String(parola || '').trim();
        var l = lacune(testo, parola, gap);
        var out = '', da = 0;
        l.forEach(function (x) {
            out += fuori(testo.slice(da, x.da));
            out += dentro(x.tipo === 'trattini' ? (w || '…') : testo.slice(x.da, x.a), x);
            da = x.a;
        });
        return out + fuori(testo.slice(da));
    }

    /* La frase per l'editor: le parti nascoste in evidenza. */
    function htmlEvidenza(testo, parola, gap, classe) {
        classe = classe || 'fcv-ex-gap';
        return html(testo, parola, gap,
            function (t) { return '<mark class="' + classe + '">' + esc(t) + '</mark>'; },
            function (t) { return esc(t); });
    }

    /* La frase da far sentire: senza trattini. */
    function daSentire(testo, parola) {
        return String(testo || '').replace(/_+/g, String(parola || '').trim());
    }

    /* ============================================================
       4. I TASTI PER SCEGLIERE A MANO
       ============================================================
       Un tasto per ogni parola E per ogni apostrofo ("I'm" -> I ' m), e una
       vecchia fila di _ e' un tasto anche lei. Il resto (spazi, virgole) sta
       fra un tasto e l'altro. */
    function tasti(testo) {
        var out = [], re = /[\p{L}\p{N}\p{M}]+|['’‘ʼ]|_+/gu, m, ultimo = 0, k = 0;
        while ((m = re.exec(testo)) !== null) {
            if (m.index > ultimo) out.push({ t: testo.slice(ultimo, m.index), tasto: false });
            out.push({ t: m[0], tasto: true, k: k++, da: m.index, a: m.index + m[0].length, apostrofo: /^['’‘ʼ]$/.test(m[0]) });
            ultimo = m.index + m[0].length;
        }
        if (ultimo < testo.length) out.push({ t: testo.slice(ultimo), tasto: false });
        return out;
    }

    /* I tasti dentro le parti nascoste attuali. */
    function scelteDaLacune(testo, l) {
        var on = {};
        tasti(testo).forEach(function (x) {
            if (!x.tasto) return;
            for (var i = 0; i < l.length; i++) if (x.da < l[i].a && x.a > l[i].da) { on[x.k] = true; break; }
        });
        return on;
    }

    /* I tasti scelti diventano pezzi: due tasti uno dopo l'altro stanno nello
       stesso pezzo, con quello che c'e' in mezzo (lo spazio di "I want"). */
    function pezziDaScelte(testo, on) {
        var lista = tasti(testo).filter(function (x) { return x.tasto; });
        var out = [];
        lista.forEach(function (x) {
            if (!on[x.k]) return;
            var prec = out[out.length - 1];
            if (prec && prec.ultimo === x.k - 1) { prec.a = x.a; prec.ultimo = x.k; }
            else out.push({ da: x.da, a: x.a, ultimo: x.k });
        });
        return out.map(function (p) { return { da: p.da, a: p.a }; });
    }

    var IC = {
        mirino: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M8 12h8"/></svg>',
        magia: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 4 1.5 3L20 8.5 16.5 10 15 13l-1.5-3L10 8.5 13.5 7z"/><path d="M5 20 13 12"/></svg>'
    };
    function tr(k, ripiego, sost) {
        var v = (typeof T === 'function') ? T(k, sost) : '';
        return (v && v !== k && v.indexOf('<<') !== 0) ? v : (ripiego || k);
    }

    /* Il componente: si monta dentro `host`, che svuota. Le opzioni:
         testo, parola, gap, lingua
         fatto(gapNuovo)   alla conferma ('' = automatica)
         annulla()
       Un tocco su un tasto lo accende o lo spegne; col mouse si puo' anche
       trascinare sopra piu' tasti. */
    function monta(host, o) {
        var testo = String(o.testo || '');
        var lista = tasti(testo);
        var auto = lacune(testo, o.parola, '');
        var on = scelteDaLacune(testo, lacune(testo, o.parola, o.gap));

        function disegna() {
            var h = '';
            lista.forEach(function (x, n) {
                if (x.tasto) {
                    var acceso = !!on[x.k];
                    var conPrec = acceso && on[x.k - 1] && lista[n - 1] && lista[n - 1].tasto;
                    var conSucc = acceso && on[x.k + 1] && lista[n + 1] && lista[n + 1].tasto;
                    h += '<button type="button" class="fcv-ex-tok' + (acceso ? ' is-on' : '')
                       + (x.apostrofo ? ' is-apos' : '')
                       + (acceso && !conPrec ? ' is-inizio' : '') + (acceso && !conSucc ? ' is-fine' : '')
                       + '" data-k="' + x.k + '" aria-pressed="' + (acceso ? 'true' : 'false') + '">' + esc(x.t) + '</button>';
                } else {
                    /* lo spazio fra due tasti accesi si colora anche lui: e'
                       un pezzo solo, com'e' nel gioco */
                    var p = lista[n - 1], s = lista[n + 1];
                    var fra = p && s && p.tasto && s.tasto && on[p.k] && on[s.k];
                    h += '<span class="fcv-ex-sep' + (fra ? ' is-on' : '') + '">' + esc(x.t) + '</span>';
                }
            });
            var quanti = Object.keys(on).filter(function (k) { return on[k]; }).length;
            var giaAuto = sonoUguali(pezziDaScelte(testo, on), auto);
            host.innerHTML = '<div class="fcv-gp">'
                + '<p class="fcv-ex-guida">' + IC.mirino + '<span>' + esc(tr('fcv.es_guida2', 'Tap the words to hide. You can pick words that are not next to each other.')) + '</span></p>'
                + '<p class="fcv-ex-parole" lang="' + esc(o.lingua || '') + '">' + h + '</p>'
                + '<div class="fcv-ex-barra">'
                +   (auto.length ? '<button type="button" class="fcv-ex-btn is-piano" data-gp="auto"' + (giaAuto ? ' disabled' : '') + '>' + IC.magia + '<span>' + esc(tr('fcv.es_auto_btn', 'Automatic')) + '</span></button>' : '')
                +   '<button type="button" class="fcv-ex-btn is-piano" data-gp="azzera"' + (quanti ? '' : ' disabled') + '>' + esc(tr('fcv.es_azzera', 'Clear')) + '</button>'
                +   '<span class="fcv-ex-spazio"></span>'
                +   '<button type="button" class="fcv-ex-btn" data-gp="annulla">' + esc(tr('comune.annulla', 'Cancel')) + '</button>'
                +   '<button type="button" class="fcv-ex-btn is-primo" data-gp="fatto">' + esc(tr('fcv.es_fatto', 'Done')) + '</button>'
                + '</div></div>';
        }

        function sonoUguali(a, b) {
            if (a.length !== b.length) return false;
            for (var i = 0; i < a.length; i++) if (a[i].da !== b[i].da || a[i].a !== b[i].a) return false;
            return true;
        }

        /* Trascinamento col mouse: il primo tasto decide se si accende o si
           spegne, e gli altri sotto il puntatore fanno lo stesso. Col dito no:
           li' trascinare vuol dire scorrere la pagina. */
        var pittura = null;
        host.onpointerdown = function (e) {
            var t = e.target.closest && e.target.closest('.fcv-ex-tok');
            if (!t || e.pointerType === 'touch') return;
            var k = parseInt(t.getAttribute('data-k'), 10);
            pittura = { val: !on[k], toccati: {} };
            pittura.toccati[k] = true;
            on[k] = pittura.val;
            disegna();
            e.preventDefault();
        };
        host.onpointermove = function (e) {
            if (!pittura) return;
            var el = document.elementFromPoint(e.clientX, e.clientY);
            var t = el && el.closest ? el.closest('.fcv-ex-tok') : null;
            if (!t || !host.contains(t)) return;
            var k = parseInt(t.getAttribute('data-k'), 10);
            if (pittura.toccati[k]) return;
            pittura.toccati[k] = true;
            on[k] = pittura.val;
            disegna();
        };
        function finePittura() { if (pittura) { pittura.appenaFinita = true; setTimeout(function () { pittura = null; }, 0); } }
        host.onpointerup = finePittura;
        host.onpointercancel = finePittura;

        host.onclick = function (e) {
            e.stopPropagation();
            var t = e.target.closest('.fcv-ex-tok');
            if (t) {
                if (pittura) return;   // gia' fatto da pointerdown
                var k = parseInt(t.getAttribute('data-k'), 10);
                on[k] = !on[k];
                try { if (navigator.vibrate) navigator.vibrate(6); } catch (er) {}
                disegna();
                return;
            }
            var b = e.target.closest('[data-gp]');
            if (!b || b.disabled) return;
            switch (b.getAttribute('data-gp')) {
                case 'auto':    on = scelteDaLacune(testo, auto); disegna(); break;
                case 'azzera':  on = {}; disegna(); break;
                case 'annulla': smonta(); if (o.annulla) o.annulla(); break;
                case 'fatto':
                    var pz = pezziDaScelte(testo, on);
                    /* uguale a quella automatica: non si salva niente, cosi'
                       segue la parola se un giorno la si corregge */
                    var g = sonoUguali(pz, auto) ? '' : (pz.length ? scrivi(testo, pz) : '');
                    /* niente scelto e niente di automatico: si salva "niente"
                       come scelta vuota, cioe' come prima - la frase intera */
                    smonta();
                    if (o.fatto) o.fatto(g);
                    break;
            }
        };
        function smonta() {
            host.onpointerdown = host.onpointermove = host.onpointerup = host.onpointercancel = host.onclick = null;
        }
        disegna();
    }

    window.fcvGap = {
        norm: norm,
        leggi: leggi,
        scrivi: scrivi,
        lacune: lacune,
        lacuna: lacuna,
        eAutomatica: eAutomatica,
        html: html,
        htmlEvidenza: htmlEvidenza,
        daSentire: daSentire,
        tasti: tasti,
        monta: monta,
        esc: esc
    };

    /* I nomi di prima, che usano ancora alcune pagine. */
    window.fcvLacuna = lacuna;
    window.fcvLacune = lacune;
})();
