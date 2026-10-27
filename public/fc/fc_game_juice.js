/* fc_game_juice.js — IL GIOCO DELLE LINGUE, PIU' VIVO
 *
 * Una partita da 100 parole e' 100 volte la stessa cosa. Questo file ci mette
 * sopra un ritmo, senza toccare come si gioca:
 *
 *   SERIE        le risposte giuste di fila: una pastiglia con la fiamma sotto
 *                la barra in alto, che cresce a scalini (3, 5, 10, 15…) e
 *                festeggia ogni scalino. Sbagliando si azzera, con garbo.
 *   CHECKPOINT   ogni N parole (si sceglie nell'ingranaggio, o si spegne) una
 *                pausa: il percorso fatto, la precisione del tratto, la serie
 *                migliore, il tempo per carta, le parole prese al volo.
 *   BOSS         se nel tratto si e' sbagliato qualcosa, il checkpoint propone
 *                di rigiocare subito quelle parole. Vincerlo vale una festa.
 *   LIVELLI      ogni parola ha il suo (nuova, da imparare, sembra facile,
 *                la sai, insidiosa, difficile, difficilissima, in ripresa, ci
 *                sei quasi), dalla storia delle sue risposte. Si
 *                vede sulla carta e, quando cambia, lo si dice.
 *   PROGRESSO    la barra cambia colore andando avanti (blu → viola →
 *                arancio → oro) e nell'ultimo tratto luccica.
 *   RECORD       serie piu' lunga, tratto piu' veloce, parole padroneggiate:
 *                si festeggiano solo quando si battono davvero.
 *   SUONI        piccoli, sintetizzati sul momento (niente file), spegnibili.
 *
 * Si aggancia al gioco in due punti di fc_game.php: gradeAndAdvance() chiama
 * FcGioco.voto() a ogni risposta, e a ogni pagina nuova arriva l'evento
 * 'fc-pagina' con il suo contenitore. I numeri del server stanno nella pagina
 * (script.fcg-dati e data-fcv-stats sulle carte, vedi fc_vocab_gioco.php).
 *
 * Tutto quello che si vede vive nel <body>, fuori dal contenitore della
 * pagina, che a ogni carta viene sostituito.
 */
(function () {
    'use strict';
    /* Lingue e studio: le differenze (cosa si chiede nel boss, "parole" o
       "carte" nei testi) passano tutte da VOCAB. */
    var VOCAB = !!(window.FC && FC.tipo === 'vocab');

    /* ============================================================
       0. ATTREZZI
       ============================================================ */
    var root = document.documentElement;
    var RIDOTTO = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function $(sel, el) { return (el || document).querySelector(sel); }
    function $$(sel, el) { return Array.prototype.slice.call((el || document).querySelectorAll(sel)); }
    function tr(k, p, ripiego) {
        var v = (typeof T === 'function') ? T(k, p) : '';
        return (v && v.indexOf('<<') !== 0) ? v : (ripiego || k);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function leggi(k, def) { try { var v = localStorage.getItem(k); return v === null ? def : v; } catch (e) { return def; } }
    function scrivi(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function api(q) { return 'fc_vocab_game_api.php?' + (window.FC && FC.qs ? FC.qs + '&' : '') + q; }
    /* il testo di una carta di studio, disegnato come nel gioco (formule comprese) */
    function disegnaTesto(el, raw) {
        if (!el) return;
        try { el.innerHTML = typeof parseTextForPreview === 'function' ? parseTextForPreview(raw || '') : esc(raw); }
        catch (e) { el.textContent = raw || ''; }
        try { if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(el); } catch (e) {}
    }
    function vibra(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

    var IC = {
        fiamma: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.6 2.2c.3 3-1.4 4.6-2.9 6.2C8.2 10 6.6 11.7 6.6 14.6A5.4 5.4 0 0 0 12 20a5.4 5.4 0 0 0 5.4-5.4c0-2.3-1-4-2.1-5.5-.2 1.3-.8 2.3-1.8 2.8.4-3.3-.1-6.8-.9-9.7Z"/></svg>',
        stella: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.2 6.8.8-5 4.7 1.3 6.7L12 17.6l-6 3.3 1.3-6.7-5-4.7 6.8-.8Z"/></svg>',
        spunta: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>',
        corona: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5Z"/></svg>',
        orologio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/></svg>',
        trofeo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4"/></svg>',
        freccia: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
        giu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>'
    };

    /* ============================================================
       1. IMPOSTAZIONI (si scelgono nell'ingranaggio del gioco)
       ============================================================ */
    var PASSI_CK = ['0', '5', '10', '15', '20', '25'];
    function ogniQuante() { var v = leggi('fcv_checkpoint', '10'); return PASSI_CK.indexOf(v) >= 0 ? parseInt(v, 10) : 10; }
    function suoniAccesi() { return leggi('fcv_suoni', '1') === '1'; }

    /* ============================================================
       2. I SUONI — sintetizzati, cortissimi, a volume basso
       ============================================================ */
    var Audio = (function () {
        var ctx = null;
        function pronto() {
            if (!suoniAccesi()) return null;
            try {
                /* su iPhone: rispetta l'interruttore del silenzioso */
                if (navigator.audioSession && navigator.audioSession.type !== 'ambient') navigator.audioSession.type = 'ambient';
                if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
                if (ctx.state === 'suspended') ctx.resume();
                return ctx;
            } catch (e) { return null; }
        }
        function nota(f, t0, dur, tipo, vol, fFine) {
            var c = pronto(); if (!c) return;
            var o = c.createOscillator(), g = c.createGain();
            o.type = tipo || 'sine';
            o.frequency.setValueAtTime(f, c.currentTime + t0);
            if (fFine) o.frequency.exponentialRampToValueAtTime(fFine, c.currentTime + t0 + dur);
            g.gain.setValueAtTime(0.0001, c.currentTime + t0);
            g.gain.exponentialRampToValueAtTime(vol || 0.07, c.currentTime + t0 + 0.012);
            g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + t0 + dur);
            o.connect(g); g.connect(c.destination);
            o.start(c.currentTime + t0); o.stop(c.currentTime + t0 + dur + 0.02);
        }
        function arpeggio(note, passo, dur, vol, tipo) {
            note.forEach(function (f, i) { nota(f, i * passo, dur, tipo || 'triangle', vol); });
        }
        return {
            /* la nota sale con la serie: dopo un po' si "sente" di essere lanciati */
            giusta: function (serie) {
                var base = 587 * Math.pow(2, Math.min(serie, 12) / 12);
                nota(base, 0, 0.09, 'sine', 0.06); nota(base * 1.5, 0.07, 0.14, 'sine', 0.05);
            },
            sbagliata: function () { nota(240, 0, 0.22, 'triangle', 0.06, 150); },
            scalino: function () { arpeggio([659, 831, 988, 1319], 0.06, 0.16, 0.06); },
            livello: function () { arpeggio([1047, 1319, 1568], 0.05, 0.12, 0.045, 'sine'); },
            checkpoint: function () { arpeggio([523, 659, 784, 1047, 1319], 0.08, 0.22, 0.06); },
            boss: function () { arpeggio([392, 523, 659, 784, 1047, 784, 1047, 1319], 0.085, 0.24, 0.065); },
            tocco: function () { nota(880, 0, 0.04, 'sine', 0.03); }
        };
    })();

    /* ============================================================
       3. LO STATO DELLA PARTITA (su questo dispositivo)
       ============================================================ */
    var S = null;          // stato salvato
    var DATI = null;       // gli ultimi numeri del server
    function chiave() { return 'fcg_' + (DATI ? DATI.partita : 0); }
    function salva() { if (S) scrivi(chiave(), JSON.stringify(S)); }
    function leggiDati(cont) {
        var s = cont && cont.querySelector('script.fcg-dati');
        if (!s) return null;
        try { return JSON.parse(s.textContent); } catch (e) { return null; }
    }

    /* Alla prima pagina: si riparte da quello che si sa qui, ma se il server ha
       contato risposte diverse (partita giocata altrove, memoria cancellata)
       vincono i suoi numeri. */
    function semina(d, primaVolta) {
        DATI = d;
        var vecchio = null;
        try { vecchio = JSON.parse(leggi(chiave(), 'null')); } catch (e) {}
        if (primaVolta && (!vecchio || vecchio.risposte !== d.risposte)) {
            var n = ogniQuante();
            S = {
                serie: d.serie, migliore: d.migliore, risposte: d.risposte,
                ckFatti: n ? Math.floor(d.fronte / n) : 0, ckOgni: n,
                recordCombo: (d.record && d.record.combo) || 0, recordBattuto: false,
                finale: false, blocco: { giuste: 0, sbagliate: 0, serieMax: 0, inizio: Date.now() }
            };
        } else if (vecchio) {
            S = vecchio;
        }
        /* cambiato l'intervallo dei checkpoint: si riparte dal punto in cui si e' */
        var n2 = ogniQuante();
        if (S.ckOgni !== n2) { S.ckOgni = n2; S.ckFatti = n2 ? Math.floor(d.fronte / n2) : 0; }
        if (d.record && d.record.combo > (S.recordCombo || 0)) S.recordCombo = d.record.combo;
        salva();
    }

    /* ============================================================
       4. I LIVELLI DELLE PAROLE — gemella di fcv_livello() (PHP)
       ============================================================ */
    /* Dal peggiore al migliore. Le regole sono spiegate in fc_vocab_gioco.php:
       la storia u ha 'C'/'W' per le partite normali e 'c'/'w' per i ripassi,
       e "era difficile" si sa solo ripercorrendola, un passo alla volta. */
    var LIVELLI = ['difficilissima', 'difficile', 'insidiosa', 'da_imparare', 'nuova', 'in_ripresa', 'sembra_facile', 'quasi', 'la_sai'];
    var STORIA = 30;
    function rango(l) { var i = LIVELLI.indexOf(l); return i < 0 ? 4 : i; }
    function livelloPrefisso(s, n, rec) {
        if (n <= 0 || !s) return 'nuova';
        var tutte = s.toUpperCase();
        var norm = s.replace(/[a-z]/g, '');
        if (n <= 2 && tutte.indexOf('C') === -1) return 'da_imparare';
        if (n >= 5 && tutte.slice(-5) === 'CCCCC') return 'la_sai';
        function finestra() {
            var wN = (norm.slice(-5).match(/W/g) || []).length;
            if (wN >= 2) return s.slice(-10).indexOf('w') !== -1 ? 'difficilissima' : 'difficile';
            return 'insidiosa';
        }
        if (rec) {
            var serie = norm.length - norm.replace(/C+$/, '').length;
            if (serie === 1) return 'in_ripresa';
            if (serie >= 2) return 'quasi';
            var f = finestra();
            return rango(f) < rango('difficile') ? f : 'difficile';
        }
        if (tutte.indexOf('W') === -1) return 'sembra_facile';
        return finestra();
    }
    function livello(c, w, u) {
        u = String(u || '');
        var n = c + w;
        if (n <= 0 || !u) return 'nuova';
        var st = { s: '', n: Math.max(0, n - u.length), rec: false, liv: 'nuova' };
        for (var i = 0; i < u.length; i++) {
            st.s = (st.s + u.charAt(i)).slice(-STORIA);
            st.n++;
            st.liv = livelloPrefisso(st.s, st.n, st.rec);
            if (st.liv === 'difficile' || st.liv === 'difficilissima') st.rec = true;
            else if (st.liv === 'la_sai') st.rec = false;
        }
        return st.liv;
    }
    function statsDi(card) {
        try { var s = JSON.parse(card.getAttribute('data-fcv-stats') || 'null'); if (s) return s; } catch (e) {}
        return null;
    }
    function badgeHtml(liv) {
        return '<span class="fcg-liv fcg-liv-' + liv + '" title="' + esc(tr('fcv.liv_' + liv)) + '">'
            + (liv === 'la_sai' ? IC.stella : '<i></i>')
            + '<b>' + esc(tr('fcv.liv_' + liv)) + '</b></span>';
    }
    /* Il livello NON si scrive piu' nel titolo della carta: dirti "difficile"
       prima ancora di rispondere ti suggerisce com'e' andata le altre volte e
       falsa il ripasso. Si vede solo nel messaggio a comparsa dopo il voto
       (sezione 5) e nell'elenco delle carte. */

    /* ============================================================
       5. I MESSAGGI A COMPARSA (in alto, uno alla volta)
       ============================================================ */
    /* Un messaggio vecchio non serve: si risponde in fretta, e "3 di fila"
       mostrato quando si e' gia' a 6 dice il falso. Chi aspetta in coda da
       troppo si butta (i record no: quelli si vedono sempre), e un messaggio
       nuovo dello stesso tipo prende il posto di quello che aspettava. */
    var coda = [], inVista = false;
    var IMPORTANTI = { record: 1, finale: 1 };
    function avviso(html, tipo, durata) {
        tipo = tipo || '';
        coda = coda.filter(function (x) { return x.tipo !== tipo || IMPORTANTI[tipo]; });
        coda.push({ html: html, tipo: tipo, durata: durata || 1500, quando: Date.now() });
        if (!inVista) prossimoAvviso();
    }
    function prossimoAvviso() {
        var a = coda.shift();
        while (a && !IMPORTANTI[a.tipo] && Date.now() - a.quando > 1800) a = coda.shift();
        if (!a) { inVista = false; return; }
        inVista = true;
        var t = el('div', 'fcg-toast ' + (a.tipo ? 'fcg-toast-' + a.tipo : ''), a.html);
        t.style.top = (sottoTesta() + 30) + 'px';
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('is-on'); });
        setTimeout(function () {
            t.classList.remove('is-on'); t.classList.add('is-via');
            setTimeout(function () { t.remove(); prossimoAvviso(); }, 260);
        }, coda.length ? Math.min(a.durata, 1100) : a.durata);
    }
    function sottoTesta() {
        var h = $('.container:last-of-type .app-header') || $('.app-header');
        var r = h ? h.getBoundingClientRect() : null;
        return r && r.bottom > 0 ? Math.round(r.bottom) : 96;
    }

    /* ============================================================
       6. CORIANDOLI — una tela sopra tutto, che si toglie da sola
       ============================================================ */
    function coriandoli(quanti, daY) {
        if (RIDOTTO) return;
        var c = el('canvas', 'fcg-coriandoli');
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        var W = innerWidth, H = innerHeight;
        c.width = W * dpr; c.height = H * dpr;
        document.body.appendChild(c);
        var g = c.getContext('2d'); g.scale(dpr, dpr);
        var colori = ['#BF5AF2', '#0A84FF', '#30D158', '#FFD60A', '#FF9F0A', '#FF375F', '#64D2FF'];
        var p = [];
        for (var i = 0; i < (quanti || 110); i++) {
            p.push({
                x: W / 2 + (Math.random() - 0.5) * W * 0.3, y: daY != null ? daY : H * 0.32,
                vx: (Math.random() - 0.5) * 13, vy: -Math.random() * 13 - 4,
                r: 3 + Math.random() * 4, a: Math.random() * 6, va: (Math.random() - 0.5) * 0.35,
                col: colori[i % colori.length], forma: i % 3
            });
        }
        var t0 = performance.now();
        (function passo(t) {
            var dt = Math.min(2, (t - (passo.u || t)) / 16.7 || 1); passo.u = t;
            g.clearRect(0, 0, W, H);
            var vivi = 0;
            p.forEach(function (q) {
                q.vy += 0.38 * dt; q.vx *= 0.99; q.x += q.vx * dt; q.y += q.vy * dt; q.a += q.va * dt;
                if (q.y < H + 20) vivi++;
                g.save(); g.translate(q.x, q.y); g.rotate(q.a); g.fillStyle = q.col;
                g.globalAlpha = Math.max(0, 1 - (t - t0) / 2600);
                if (q.forma === 0) g.fillRect(-q.r, -q.r / 2, q.r * 2, q.r);
                else if (q.forma === 1) { g.beginPath(); g.arc(0, 0, q.r * 0.7, 0, 6.3); g.fill(); }
                else { g.beginPath(); g.moveTo(0, -q.r); g.lineTo(q.r, q.r); g.lineTo(-q.r, q.r); g.fill(); }
                g.restore();
            });
            if (vivi && t - t0 < 2600) requestAnimationFrame(passo); else c.remove();
        })(t0);
    }

    /* ============================================================
       7. LA SERIE — la pastiglia con la fiamma
       ============================================================ */
    var SCALINI = [3, 5, 10, 15, 20, 30, 50, 75, 100];
    var pill = null;
    function scalinoDi(n) { var s = 0; SCALINI.forEach(function (x) { if (n >= x) s = x; }); return s; }
    function nomeScalino(s) { return tr('fcv.gj_t' + Math.min(s, 50), null, ''); }
    function disegnaSerie(animata) {
        if (!pill) {
            pill = el('div', 'fcg-combo', '<span class="fcg-combo-ic">' + IC.fiamma + '</span><b class="fcg-combo-n"></b><span class="fcg-combo-x">×</span>');
            pill.setAttribute('aria-live', 'polite');
            document.body.appendChild(pill);
        }
        var n = S ? S.serie : 0;
        pill.querySelector('.fcg-combo-n').textContent = n;
        pill.style.top = (sottoTesta() - 17) + 'px';
        var sc = scalinoDi(n);
        pill.className = 'fcg-combo' + (n >= 2 ? ' is-on' : '') + (sc ? ' fcg-sc-' + Math.min(sc, 30) : '');
        pill.setAttribute('aria-label', tr('fcv.gj_serie_aria', { n: n }, n + ' in a row'));
        if (animata && n >= 2) { pill.classList.remove('is-batte'); void pill.offsetWidth; pill.classList.add('is-batte'); }
    }

    /* ============================================================
       8. A OGNI RISPOSTA
       ============================================================ */
    function voto(esito, card) {
        if (!S) return;
        var giusta = esito !== 'wrong';
        var prima = S.serie;
        S.risposte++;
        if (giusta) {
            S.serie++; S.blocco.giuste++;
            if (S.serie > S.migliore) S.migliore = S.serie;
            if (S.serie > S.blocco.serieMax) S.blocco.serieMax = S.serie;
            Audio.giusta(S.serie);
            var sc = scalinoDi(S.serie);
            if (sc && sc === S.serie) {
                avviso('<span class="fcg-toast-ic">' + IC.fiamma + '</span><span><b>' + tr('fcv.gj_di_fila', { n: S.serie }, S.serie + ' in a row') + '</b> ' + esc(nomeScalino(sc)) + '</span>', 'fuoco');
                Audio.scalino(); vibra([8, 30, 8]);
                if (sc >= 10) coriandoli(40 + sc, sottoTesta() + 30);
            }
            /* record della serie: si festeggia una volta per partita, e solo se
               un record c'era gia' (la prima partita li batterebbe tutti) */
            if (S.recordCombo >= 3 && S.serie > S.recordCombo && !S.recordBattuto) {
                S.recordBattuto = true;
                avviso('<span class="fcg-toast-ic">' + IC.trofeo + '</span><span><b>' + tr('fcv.gj_record_combo', { n: S.serie }, 'New record!') + '</b></span>', 'record', 2200);
                coriandoli(90, sottoTesta() + 30);
            }
            if (S.serie > S.recordCombo) S.comboDaSalvare = S.serie;
        } else {
            S.serie = 0; S.blocco.sbagliate++;
            Audio.sbagliata();
            if (prima >= 3) avviso('<span class="fcg-toast-ic">' + IC.fiamma + '</span><span>' + esc(tr('fcv.gj_persa', { n: prima }, 'Streak lost')) + '</span>', 'spenta', 1500);
        }
        disegnaSerie(true);
        if (!giusta && pill && prima >= 2) { pill.classList.add('is-spenta'); setTimeout(function () { pill && pill.classList.remove('is-spenta'); }, 500); }
        livelloDopoVoto(card, giusta);
        salva();
    }

    function livelloDopoVoto(card, giusta) {
        var s = card && statsDi(card);
        if (!s) return;
        var prima = livello(s.c, s.w, s.u);
        /* in un ripasso la risposta si scrive in minuscolo, come fa il server */
        var lettera = giusta ? 'C' : 'W';
        if (DATI && DATI.ripasso) lettera = lettera.toLowerCase();
        var n = { c: s.c + (giusta ? 1 : 0), w: s.w + (giusta ? 0 : 1), u: (String(s.u || '') + lettera).slice(-STORIA), k: s.k };
        var dopo = livello(n.c, n.w, n.u);
        card.setAttribute('data-fcv-stats', JSON.stringify(n));
        if (dopo === prima) return;
        var giu = dopo === 'difficile' || dopo === 'difficilissima';
        var su = !giu && rango(dopo) > rango(prima);
        /* si dice solo quello che conta: arrivare a "la sai" o "ci sei quasi",
           riprendersi da "difficile", finirci dentro. La prima risposta a una
           parola nuova succede di continuo e non e' una notizia. */
        var conta = (su && (dopo === 'la_sai' || dopo === 'quasi' || dopo === 'in_ripresa')) || (giu && prima !== dopo);
        if (!conta) return;
        var testo = dopo === 'la_sai' ? tr('fcv.gj_liv_la_sai', null, 'Now you know it!')
                  : dopo === 'in_ripresa' ? tr('fcv.gj_liv_ripresa', null, 'Bouncing back!')
                  : su ? tr('fcv.gj_liv_su', null, 'Level up!')
                  : (giu ? tr('fcv.gj_liv_difficile', null, 'Marked as hard') : tr('fcv.gj_liv_giu', null, 'Level down'));
        var parola = VOCAB ? ((card.querySelector('.fcv-game-text.is-word') || {}).textContent || '')
                           : ((card.querySelector('.q-section .raw-text') || {}).textContent || '');
        parola = parola.replace(/\s+/g, ' ').trim();
        if (parola.length > 34) parola = parola.slice(0, 32).trim() + '…';
        avviso('<span class="fcg-toast-ic fcg-toast-ic-' + (su ? 'su' : 'giu') + '">' + (su ? IC.freccia : IC.giu) + '</span>'
             + '<span class="fcg-toast-col"><span><b>' + esc(testo) + '</b>' + (parola ? ' · ' + esc(parola.trim()) : '') + '</span>'
             + '<span class="fcg-toast-liv">' + badgeHtml(prima) + '<span class="fcg-toast-arr">→</span>' + badgeHtml(dopo) + '</span></span>',
               su ? 'su' : 'giu', 2100);
        if (su) { Audio.livello(); if (dopo === 'la_sai') coriandoli(60, sottoTesta() + 40); }
    }

    /* ============================================================
       9. IL PROGRESSO CHE SI SENTE — colore della barra e ultimo tratto
       ============================================================ */
    var TAPPE = [[0, [10, 132, 255]], [0.5, [191, 90, 242]], [0.82, [255, 159, 10]], [1, [255, 214, 10]]];
    function coloreA(p) {
        for (var i = 1; i < TAPPE.length; i++) {
            if (p <= TAPPE[i][0]) {
                var a = TAPPE[i - 1], b = TAPPE[i], f = (p - a[0]) / (b[0] - a[0]);
                return 'rgb(' + a[1].map(function (x, j) { return Math.round(x + (b[1][j] - x) * f); }).join(',') + ')';
            }
        }
        return 'rgb(255,214,10)';
    }
    function progresso(d) {
        var tot = Math.max(1, d.totale), p = Math.min(1, d.fronte / tot);
        root.style.setProperty('--fcg-col', coloreA(p));
        var restano = tot - d.fronte;
        var soglia = Math.max(5, Math.round(tot * 0.1));
        var finale = tot >= 20 && restano > 0 && restano <= soglia;
        root.classList.toggle('fcg-finale', finale);
        if (finale && !S.finale) {
            S.finale = true; salva();
            avviso('<span class="fcg-toast-ic">' + IC.stella + '</span><span><b>' + tr('fcv.gj_finale', { n: restano }, 'Final stretch!') + '</b></span>', 'finale', 2000);
        }
    }

    /* ============================================================
       10. IL CHECKPOINT
       ============================================================ */
    var foglio = null;
    function apriFoglio(classe) {
        chiudiFoglio(true);
        foglio = el('div', 'fcg-foglio ' + (classe || ''));
        foglio.setAttribute('role', 'dialog'); foglio.setAttribute('aria-modal', 'true');
        foglio.innerHTML = '<div class="fcg-pannello"></div>';
        document.body.appendChild(foglio);
        window.__standoutModaleAperto = true;
        requestAnimationFrame(function () { foglio && foglio.classList.add('is-on'); });
        return foglio.firstChild;
    }
    function chiudiFoglio(subito) {
        if (!foglio) return;
        var f = foglio; foglio = null;
        window.__standoutModaleAperto = false;
        if (subito) { f.remove(); return; }
        f.classList.remove('is-on'); f.classList.add('is-via');
        setTimeout(function () { f.remove(); }, 280);
    }

    function percorsoHtml(k, tot) {
        /* i nodi del percorso: tanti quanti i checkpoint della partita; se sono
           troppi se ne mostra una finestra intorno a quello appena raggiunto */
        var n = Math.max(k, Math.ceil(tot / S.ckOgni));
        var da = 1, a = n;
        if (n > 9) { da = Math.max(1, Math.min(k - 4, n - 8)); a = da + 8; }
        var h = '<div class="fcg-percorso" style="--fcg-nodi:' + (a - da + 1) + '">';
        for (var i = da; i <= a; i++) {
            var st = i < k ? 'fatto' : (i === k ? 'ora' : 'dopo');
            h += '<span class="fcg-nodo fcg-nodo-' + st + '" style="--i:' + (i - da) + '">'
               + (i === n ? IC.trofeo : (i <= k ? IC.spunta : '<b>' + i + '</b>')) + '</span>';
            if (i < a) h += '<span class="fcg-tratto' + (i < k ? ' is-pieno' : '') + '" style="--i:' + (i - da) + '"></span>';
        }
        return h + '</div>';
    }

    function contaSu(nodo, fino, suffisso, decimali) {
        if (!nodo) return;
        if (RIDOTTO) { nodo.textContent = fino.toFixed(decimali || 0) + (suffisso || ''); return; }
        var t0 = performance.now(), d = 900;
        (function f(t) {
            var x = Math.min(1, (t - t0) / d), e = 1 - Math.pow(1 - x, 3);
            nodo.textContent = (fino * e).toFixed(decimali || 0) + (suffisso || '');
            if (x < 1) requestAnimationFrame(f);
        })(t0);
    }

    function checkpoint(k, da, a, totale, fine) {
        var blocco = S.blocco;
        S.blocco = { giuste: 0, sbagliate: 0, serieMax: S.serie, inizio: Date.now() };
        salva();
        var nTot = Math.max(k, Math.ceil(totale / S.ckOgni));
        var p = apriFoglio('fcg-ck');
        p.innerHTML =
              '<div class="fcg-ck-testa">'
            +   '<span class="fcg-occhiello">' + esc(tr('fcv.ck_occhiello', { k: k, n: nTot }, 'Checkpoint ' + k)) + '</span>'
            +   '<h2 class="fcg-ck-tit">…</h2>'
            + '</div>'
            + percorsoHtml(k, totale)
            + '<div class="fcg-ck-dati">'
            +   '<div class="fcg-dato fcg-dato-anello"><svg viewBox="0 0 44 44"><circle cx="22" cy="22" r="18" class="fcg-anello-bg"/><circle cx="22" cy="22" r="18" class="fcg-anello"/></svg><b class="fcg-dato-n fcg-n-prec">–</b><span>' + esc(tr('fcv.ck_precisione', null, 'Accuracy')) + '</span></div>'
            +   '<div class="fcg-dato"><span class="fcg-dato-ic fcg-ic-fuoco">' + IC.fiamma + '</span><b class="fcg-dato-n">' + (blocco.serieMax || 0) + '</b><span>' + esc(tr('fcv.ck_serie', null, 'Best streak')) + '</span></div>'
            +   '<div class="fcg-dato"><span class="fcg-dato-ic fcg-ic-tempo">' + IC.orologio + '</span><b class="fcg-dato-n fcg-n-tempo">–</b><span>' + esc(tr('fcv.ck_tempo', null, 'Per card')) + '</span></div>'
            + '</div>'
            + '<div class="fcg-ck-record"></div>'
            + '<div class="fcg-ck-presi" hidden><span class="fcg-sottotit">' + esc(tr('fcv.ck_presi', null, 'Nailed it')) + '</span><div class="fcg-chips"></div></div>'
            + '<div class="fcg-azioni"><button type="button" class="fcg-btn fcg-btn-primo" data-az="continua">' + esc(tr('fcv.ck_continua', null, 'Continue')) + '</button></div>';
        Audio.checkpoint(); vibra([10, 40, 10]);

        p.addEventListener('click', function (e) {
            var b = e.target.closest('[data-az]'); if (!b) return;
            var az = b.getAttribute('data-az');
            if (az === 'continua' || az === 'salta') chiudiFoglio();
            if (az === 'boss') boss(p._sbagliate || []);
        });

        fetch(api('action=blocco&game_id=' + DATI.partita + '&da=' + da + '&a=' + a), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok && foglio && foglio.firstChild === p) riempiCheckpoint(p, d, blocco, fine); else riempiCheckpoint(p, null, blocco, fine); })
            .catch(function () { riempiCheckpoint(p, null, blocco, fine); });
    }

    function riempiCheckpoint(p, d, blocco, fine) {
        var g = d ? d.giuste : blocco.giuste, s = d ? d.sbagliate : blocco.sbagliate;
        var prec = (g + s) ? Math.round(g / (g + s) * 100) : 0;
        var tit = prec === 100 ? 'ck_perfetto' : prec >= 80 ? 'ck_ottimo' : prec >= 60 ? 'ck_buono' : 'ck_avanti';
        if (fine) tit = prec >= 80 ? 'ck_fine_top' : 'ck_fine';
        $('.fcg-ck-tit', p).textContent = tr('fcv.' + tit, null, 'Checkpoint!');
        p.classList.toggle('is-perfetto', prec === 100);
        contaSu($('.fcg-n-prec', p), prec, '%');
        var anello = $('.fcg-anello', p);
        if (anello) {
            anello.style.setProperty('--fcg-p', prec / 100);
            anello.classList.add(prec >= 80 ? 'is-verde' : prec >= 60 ? 'is-giallo' : 'is-rosso');
        }
        if (d && d.al_carta != null) contaSu($('.fcg-n-tempo', p), d.al_carta, 's', 1);
        else $('.fcg-n-tempo', p).textContent = '–';

        var rec = [];
        if (d && d.record) {
            var v = d.record.veloce;
            if (v && v.nuovo) rec.push([IC.orologio, tr('fcv.ck_record_veloce', { s: v.valore }, 'Fastest level!')]);
            var m = d.record.padroneggiate;
            var tappe = [5, 10, 25, 50, 100, 200, 300, 500, 750, 1000];
            if (m && m.prima != null) {
                var passata = tappe.filter(function (x) { return m.prima < x && m.valore >= x; }).pop();
                if (passata) rec.push([IC.stella, tr(VOCAB ? 'fcv.ck_padroneggiate' : 'fcv.ck_padroneggiate_carte', { n: m.valore }, m.valore + ' mastered!')]);
            }
        }
        if (S.comboDaSalvare && S.recordBattuto) rec.push([IC.fiamma, tr('fcv.ck_record_serie', { n: S.migliore }, 'Record streak!')]);
        $('.fcg-ck-record', p).innerHTML = rec.map(function (r, i) {
            return '<div class="fcg-record" style="--i:' + i + '"><span class="fcg-record-ic">' + r[0] + '</span><b>' + esc(r[1]) + '</b></div>';
        }).join('');

        var presi = d ? d.giuste_lista : [];
        if (presi && presi.length) {
            var box = $('.fcg-ck-presi', p); box.hidden = false;
            $('.fcg-chips', box).innerHTML = presi.map(function (w, i) { return '<span class="fcg-chip" style="--i:' + i + '">' + esc(w) + '</span>'; }).join('');
        }

        var sb = d ? d.sbagliate_lista : [];
        p._sbagliate = sb || [];
        if (sb && sb.length) {
            $('.fcg-azioni', p).innerHTML =
                  '<button type="button" class="fcg-btn fcg-btn-boss" data-az="boss">' + IC.corona + '<span>' + esc(tr('fcv.ck_boss', { n: sb.length }, 'Boss round')) + '</span></button>'
                + '<button type="button" class="fcg-btn fcg-btn-piano" data-az="salta">' + esc(tr(fine ? 'fcv.ck_continua' : 'fcv.ck_salta', null, 'Skip')) + '</button>';
        }
        if (prec >= 80 || rec.length) coriandoli(prec === 100 ? 150 : 90);
        salvaCombo();
    }

    /* ============================================================
       11. IL BOSS — le parole sbagliate del tratto, subito
       ============================================================ */
    function boss(lista) {
        if (!lista.length) { chiudiFoglio(); return; }
        var p = apriFoglio('fcg-boss');
        var i = 0, prese = 0;
        function cartaStudio() {
            var w = lista[i];
            p.innerHTML =
                  '<div class="fcg-boss-testa"><span class="fcg-boss-ic">' + IC.corona + '</span><span class="fcg-occhiello">' + esc(tr('fcv.boss_titolo', null, 'Boss round')) + '</span>'
                + '<span class="fcg-boss-punti">' + lista.map(function (_, j) { return '<i class="' + (j < i ? 'is-fatto' : j === i ? 'is-ora' : '') + '"></i>'; }).join('') + '</span></div>'
                + '<div class="fcg-boss-carta fcg-boss-studio">'
                +   '<span class="fcg-boss-dom">' + esc(tr('fcv.boss_domanda_studio', null, 'Do you remember?')) + '</span>'
                +   (w.img_domanda ? '<img class="fcg-boss-img" src="' + esc(w.img_domanda) + '" alt="">' : '')
                +   '<div class="fcg-boss-testo fcg-boss-q"></div>'
                +   '<div class="fcg-boss-parola fcg-boss-a" aria-live="polite"></div>'
                + '</div>'
                + '<div class="fcg-azioni"><button type="button" class="fcg-btn fcg-btn-primo" data-az="mostra">' + esc(tr('fcv.boss_mostra', null, 'Show')) + '</button></div>';
            disegnaTesto($('.fcg-boss-q', p), w.domanda);
        }
        function carta() {
            if (!VOCAB) { cartaStudio(); return; }
            var w = lista[i];
            p.innerHTML =
                  '<div class="fcg-boss-testa"><span class="fcg-boss-ic">' + IC.corona + '</span><span class="fcg-occhiello">' + esc(tr('fcv.boss_titolo', null, 'Boss round')) + '</span>'
                + '<span class="fcg-boss-punti">' + lista.map(function (_, j) { return '<i class="' + (j < i ? 'is-fatto' : j === i ? 'is-ora' : '') + '"></i>'; }).join('') + '</span></div>'
                + '<div class="fcg-boss-carta">'
                +   '<span class="fcg-boss-dom">' + esc(tr('fcv.boss_domanda', null, 'How do you say it?')) + '</span>'
                +   '<div class="fcg-boss-trad">' + esc(w.traduzione) + '</div>'
                +   (w.esempio ? '<div class="fcg-boss-es" lang="">' + w.esempio + '</div>' : '')
                +   '<div class="fcg-boss-parola" aria-live="polite"></div>'
                + '</div>'
                + '<div class="fcg-azioni"><button type="button" class="fcg-btn fcg-btn-primo" data-az="mostra">' + esc(tr('fcv.boss_mostra', null, 'Show')) + '</button></div>';
        }
        function mostra() {
            var w = lista[i];
            $('.fcg-boss-carta', p).classList.add('is-vista');
            if (!VOCAB) {
                var a = $('.fcg-boss-a', p);
                a.innerHTML = (w.img_risposta ? '<img class="fcg-boss-img" src="' + esc(w.img_risposta) + '" alt="">' : '') + '<div class="fcg-boss-testo"></div>';
                disegnaTesto($('.fcg-boss-testo', a), w.risposta);
            } else
            $('.fcg-boss-parola', p).innerHTML = '<span>' + esc(w.parola) + '</span>' + (w.significato ? '<small>' + esc(w.significato) + '</small>' : '');
            $('.fcg-azioni', p).innerHTML =
                  '<button type="button" class="fcg-btn fcg-btn-no" data-az="no">' + esc(tr('fcv.boss_mancato', null, 'Missed it')) + '</button>'
                + '<button type="button" class="fcg-btn fcg-btn-si" data-az="si">' + esc(tr('fcv.boss_preso', null, 'Got it')) + '</button>';
            Audio.tocco();
        }
        function fine() {
            var tutto = prese === lista.length;
            p.innerHTML =
                  '<div class="fcg-boss-fine' + (tutto ? ' is-vinto' : '') + '">'
                +   '<span class="fcg-boss-medaglia">' + (tutto ? IC.corona : IC.fiamma) + '</span>'
                +   '<h2>' + esc(tutto ? tr('fcv.boss_vinto', null, 'Boss defeated!') : tr('fcv.boss_parziale', { g: prese, n: lista.length }, prese + '/' + lista.length)) + '</h2>'
                +   '<p>' + esc(tutto ? tr('fcv.boss_vinto_sub', null, '') : tr('fcv.boss_parziale_sub', null, '')) + '</p>'
                + '</div>'
                + '<div class="fcg-azioni"><button type="button" class="fcg-btn fcg-btn-primo" data-az="fine">' + esc(tr('fcv.ck_continua', null, 'Continue')) + '</button></div>';
            if (tutto) { Audio.boss(); coriandoli(170); vibra([10, 40, 10, 40, 20]); }
            else Audio.checkpoint();
        }
        p.addEventListener('click', function (e) {
            var b = e.target.closest('[data-az]'); if (!b) return;
            var az = b.getAttribute('data-az');
            if (az === 'mostra') mostra();
            else if (az === 'si' || az === 'no') {
                if (az === 'si') { prese++; Audio.giusta(prese + 2); } else Audio.sbagliata();
                i++;
                if (i < lista.length) carta(); else fine();
            } else if (az === 'fine') chiudiFoglio();
        });
        carta();
    }

    /* ============================================================
       12. RECORD DELLA SERIE (si salva sul server con calma)
       ============================================================ */
    function salvaCombo() {
        if (!S || !S.comboDaSalvare || !DATI) return;
        var v = S.comboDaSalvare;
        var fd = new FormData();
        fd.append('action', 'combo'); fd.append('game_id', DATI.partita); fd.append('valore', v);
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('csrf_token', meta.content);
        fetch(api(''), { method: 'POST', body: fd, credentials: 'same-origin', headers: meta ? { 'X-CSRF-Token': meta.content } : {} })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { if (S.recordCombo < v) S.recordCombo = v; if (S.comboDaSalvare === v) S.comboDaSalvare = 0; salva(); }
            }).catch(function () {});
    }

    /* ============================================================
       13. A OGNI PAGINA NUOVA
       ============================================================ */
    var prima = true;
    function pagina(cont) {
        var d = leggiDati(cont);
        if (!d) return;
        semina(d, prima);
        prima = false;
        root.classList.add('fcg-on');
        disegnaSerie(false);
        progresso(d);
        /* il checkpoint: si e' appena superata la soglia di un tratto */
        var n = S.ckOgni;
        if (n > 0) {
            var k = Math.floor(d.fronte / n);
            var fine = d.fronte >= d.totale;
            if (fine && d.fronte % n) k++;          // l'ultimo tratto, anche se piu' corto
            if (k > S.ckFatti) {
                var da = S.ckFatti * n + 1, a = Math.min(d.fronte, k * n);
                S.ckFatti = k; salva();
                setTimeout(function () { checkpoint(k, da, a, d.totale, fine); }, 450);
            }
        }
        if (S.comboDaSalvare && S.comboDaSalvare >= 3 && S.serie === 0) salvaCombo();
    }

    document.addEventListener('fc-pagina', function (e) { pagina(e.detail && e.detail.container); });
    window.addEventListener('resize', function () { if (pill) pill.style.top = (sottoTesta() - 17) + 'px'; });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && foglio && !foglio.classList.contains('fcg-boss')) chiudiFoglio();
    });
    /* salvare la serie anche uscendo dalla pagina */
    window.addEventListener('pagehide', function () { if (S && S.comboDaSalvare >= 3) salvaCombo(); });

    /* ============================================================
       14. LE IMPOSTAZIONI NELL'INGRANAGGIO (checkpoint, suoni)
       ============================================================ */
    /* Le righe "checkpoint" e "suoni" stanno nell'ingranaggio delle lingue e
       nelle impostazioni dello studio, e durante il cambio di carta le pagine
       sono due: si aggiornano tutte. */
    function segnaImpostazioni() {
        $$('.fcv-gs-riga[data-fcg="checkpoint"]').forEach(function (r) {
            var v = String(ogniQuante());
            $$('[data-v]', r).forEach(function (b) {
                var on = b.getAttribute('data-v') === v;
                b.classList.toggle('is-on', on); b.setAttribute('aria-checked', on ? 'true' : 'false');
            });
            var seg = $('.fcv-gs-seg', r);
            if (seg) seg.style.setProperty('--fcv-i', String(PASSI_CK.indexOf(v)));
            $('.fcv-gs-spiega', r).textContent = v === '0' ? tr('fcv.gs_ck_spento', null, '') : tr(VOCAB ? 'fcv.gs_ck_ogni' : 'fcv.gs_ck_ogni_carte', { n: v }, '');
        });
        $$('.fcg-suoni').forEach(function (sw) { sw.checked = suoniAccesi(); });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('.fcv-gs-riga[data-fcg="checkpoint"] [data-v]');
        if (b) {
            scrivi('fcv_checkpoint', b.getAttribute('data-v'));
            if (S && DATI) { var n = ogniQuante(); S.ckOgni = n; S.ckFatti = n ? Math.floor(DATI.fronte / n) : 0; salva(); }
            segnaImpostazioni();
        }
    });
    document.addEventListener('fcv-impostazioni', segnaImpostazioni);
    /* studio: le impostazioni sono quelle della lettura (#ttsSettingsBtn) */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#ttsSettingsBtn')) setTimeout(segnaImpostazioni, 0);
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('fcg-suoni')) {
            scrivi('fcv_suoni', e.target.checked ? '1' : '0');
            if (e.target.checked) Audio.giusta(3);
        }
    });

    window.FcGioco = { voto: voto, pagina: pagina, _livello: livello, _checkpoint: function (k) { checkpoint(k || 1, 1, DATI ? DATI.fronte : 1, DATI ? DATI.totale : 10, false); } };
})();
