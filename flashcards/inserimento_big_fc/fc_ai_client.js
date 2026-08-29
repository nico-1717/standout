/* fc_ai_client.js
   ================================================================
   IL MOTORE, SENZA INTERFACCIA.

   Tutto quello che serve alla generazione IA delle flashcard tranne i pulsanti:
   leggere il file, contarne i caratteri, scegliere le pagine, parlare con
   fc_ai.php, ritagliare gli estratti e mandare le carte a lotti.

   PERCHE' IL TESTO SI ESTRAE QUI E NON SUL SERVER

   Il file non viene mai caricato. Tre motivi, in ordine di importanza:
   1. su hosting condiviso non c'e' un lettore di PDF affidabile, e installarlo
      significherebbe portarsi dietro una libreria PHP per sempre;
   2. il conteggio dei caratteri serve MENTRE l'utente sceglie l'intervallo di
      pagine, cioe' prima di qualunque invio: farlo sul server vorrebbe dire un
      giro di rete a ogni spostamento del cursore;
   3. un file che non arriva sul server e' un file che non puo' essere salvato
      per sbaglio, non occupa spazio e non va cancellato dopo.
   Al server arriva solo testo, che viene comunque rivalidato di la'.

   DOCX E XLSX SENZA LIBRERIE

   Sono archivi zip. Il browser sa gia' scompattarli con DecompressionStream,
   quindi qui c'e' un lettore zip di ottanta righe invece di una dipendenza.
   Per il PDF invece pdf.js e' inevitabile: va messo in /Vocabolary/pdfjs/ e la
   CSP deve permetterne il worker (vedi FCAI.PDFJS in fondo).
   ================================================================ */

var FCAI = (function () {
    'use strict';

    /* ============================================================
       0. BLOCCO DELLO SCORRIMENTO DI SFONDO
       ============================================================
       Un popup che arriva in fondo al suo contenuto non basta a fermare lo
       scorrimento: se non si blocca ANCHE la pagina sotto, continuare a
       scorrere sposta lei. Il rimedio che funziona anche su iOS e' rendere il
       body "fixed" mentre il popup e' aperto, ricordandosi la posizione per
       rimetterla identica alla chiusura — altrimenti la pagina salterebbe in
       cima ogni volta che si apre un popup.

       Il contatore permette a piu' popup annidati o in sequenza di chiamare
       lockScroll()/unlockScroll() senza doversi coordinare fra loro: la pagina
       si sblocca solo quando l'ultimo che la teneva bloccata la rilascia. */
    var scrollBloccatoDa = 0;
    var scrollY = 0;

    function lockScroll() {
        if (scrollBloccatoDa === 0) {
            scrollY = window.scrollY || window.pageYOffset || 0;
            var s = document.body.style;
            s.position = 'fixed';
            s.top = -scrollY + 'px';
            s.left = '0'; s.right = '0'; s.width = '100%';
        }
        scrollBloccatoDa++;
    }

    function unlockScroll() {
        scrollBloccatoDa = Math.max(0, scrollBloccatoDa - 1);
        if (scrollBloccatoDa === 0) {
            var s = document.body.style;
            s.position = ''; s.top = ''; s.left = ''; s.right = ''; s.width = '';
            window.scrollTo(0, scrollY);
        }
    }

    /* ============================================================
       1. LETTURA DEGLI ARCHIVI ZIP (docx, xlsx)
       ============================================================ */

    /* Il zip si legge dalla FINE: l'indice centrale sta in coda, e solo li' c'e'
       la posizione di ogni file. Cercare i nomi scorrendo dall'inizio
       funzionerebbe quasi sempre e sbaglierebbe sui file grandi, dove
       l'intestazione locale non dichiara le dimensioni. */
    async function zipVoci(buffer) {
        var dv = new DataView(buffer);
        var fine = -1;
        for (var i = buffer.byteLength - 22; i >= 0 && i > buffer.byteLength - 66000; i--) {
            if (dv.getUint32(i, true) === 0x06054b50) { fine = i; break; }
        }
        if (fine < 0) throw new Error('zip: indice centrale non trovato');

        var quanti = dv.getUint16(fine + 10, true);
        var pos = dv.getUint32(fine + 16, true);
        var voci = {};

        for (var k = 0; k < quanti; k++) {
            if (dv.getUint32(pos, true) !== 0x02014b50) break;
            var metodo   = dv.getUint16(pos + 10, true);
            var compressa = dv.getUint32(pos + 20, true);
            var lenNome  = dv.getUint16(pos + 28, true);
            var lenExtra = dv.getUint16(pos + 30, true);
            var lenComm  = dv.getUint16(pos + 32, true);
            var offset   = dv.getUint32(pos + 42, true);
            var nome = new TextDecoder().decode(new Uint8Array(buffer, pos + 46, lenNome));
            voci[nome] = { metodo: metodo, compressa: compressa, offset: offset };
            pos += 46 + lenNome + lenExtra + lenComm;
        }
        return { buffer: buffer, dv: dv, voci: voci };
    }

    async function zipLeggi(zip, nome) {
        var v = zip.voci[nome];
        if (!v) return null;

        /* I campi "nome" ed "extra" dell'intestazione locale possono avere
           lunghezze diverse da quelle dell'indice centrale: vanno riletti qui,
           o si comincia a decomprimere qualche byte piu' in la'. */
        var lenNome  = zip.dv.getUint16(v.offset + 26, true);
        var lenExtra = zip.dv.getUint16(v.offset + 28, true);
        var inizio = v.offset + 30 + lenNome + lenExtra;
        var dati = new Uint8Array(zip.buffer, inizio, v.compressa);

        if (v.metodo === 0) return new TextDecoder().decode(dati); // non compresso

        var flusso = new Blob([dati]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        var testo = await new Response(flusso).arrayBuffer();
        return new TextDecoder().decode(testo);
    }

    function xmlTesto(xml) {
        return xml
            .replace(/<w:tab\/>/g, '\t')
            .replace(/<\/w:p>/g, '\n')
            .replace(/<w:br\/>/g, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
            .replace(/&amp;/g, '&');
    }

    /* ============================================================
       2. ESTRAZIONE PER TIPO
       ============================================================
       Restituisce sempre la stessa forma: { pagine: [testo, ...], tipo }.
       I formati senza pagine ne hanno una sola: cosi' la scelta
       dell'intervallo funziona uguale per tutti senza casi speciali. */

    async function daTesto(file) {
        return { tipo: 'testo', pagine: [await file.text()] };
    }

    async function daDocx(file) {
        var zip = await zipVoci(await file.arrayBuffer());
        var xml = await zipLeggi(zip, 'word/document.xml');
        if (!xml) throw new Error('docx: documento non trovato');
        return { tipo: 'docx', pagine: [xmlTesto(xml)] };
    }

    async function daXlsx(file) {
        var zip = await zipVoci(await file.arrayBuffer());

        /* Le celle di testo non contengono il testo: contengono il numero di
           riga di una tabella condivisa. Senza quella si leggono solo i numeri. */
        var condivise = [];
        var ss = await zipLeggi(zip, 'xl/sharedStrings.xml');
        if (ss) {
            var m = ss.match(/<si>[\s\S]*?<\/si>/g) || [];
            condivise = m.map(function (s) { return xmlTesto(s).replace(/\n/g, ' ').trim(); });
        }

        var nomi = Object.keys(zip.voci).filter(function (n) { return /^xl\/worksheets\/sheet\d+\.xml$/.test(n); }).sort();
        var pagine = [];
        for (var i = 0; i < nomi.length; i++) {
            var sheet = await zipLeggi(zip, nomi[i]);
            if (!sheet) continue;
            var righe = (sheet.match(/<row[\s\S]*?<\/row>/g) || []).map(function (r) {
                var celle = (r.match(/<c[\s\S]*?(?:\/>|<\/c>)/g) || []).map(function (c) {
                    var v = /<v>([\s\S]*?)<\/v>/.exec(c);
                    if (!v) {
                        var t = /<t[^>]*>([\s\S]*?)<\/t>/.exec(c); // stringa scritta in linea
                        return t ? xmlTesto(t[1]) : '';
                    }
                    return / t="s"/.test(c) ? (condivise[parseInt(v[1], 10)] || '') : v[1];
                });
                return celle.join('\t').replace(/\t+$/, '');
            });
            pagine.push(righe.join('\n'));
        }
        return { tipo: 'xlsx', pagine: pagine.length ? pagine : [''] };
    }

    async function daPdf(file, onProgresso) {
        var lib = await caricaPdfJs();
        var doc = await lib.getDocument({ data: await file.arrayBuffer() }).promise;
        var pagine = [];
        for (var n = 1; n <= doc.numPages; n++) {
            var p = await doc.getPage(n);
            var c = await p.getTextContent();

            /* Gli spazi nei PDF spesso non esistono: sono salti di coordinata.
               Ricostruirli guardando il flag hasEOL e la distanza verticale e'
               cio' che separa "unteoremafondamentale" da un testo leggibile. */
            var riga = '', testo = '';
            c.items.forEach(function (it) {
                riga += it.str;
                if (it.hasEOL) { testo += riga + '\n'; riga = ''; }
            });
            pagine.push(testo + riga);
            if (onProgresso) onProgresso(n, doc.numPages);
        }
        return { tipo: 'pdf', pagine: pagine };
    }

    var pdfPromise = null;
    function caricaPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (pdfPromise) return pdfPromise;

        /* Due modi di caricarlo, scelti dall'estensione. Dalla versione 4 pdf.js
           esce solo come modulo ES (.mjs), che con un <script src> normale non
           parte; fino alla 3.11.174 e' uno script classico che espone
           window.pdfjsLib. Riconoscere il caso qui evita di dover riscrivere
           questa funzione il giorno in cui si cambia versione. */
        var moderno = /\.mjs($|\?)/.test(FCAI.PDFJS.script);

        pdfPromise = moderno
            ? import(FCAI.PDFJS.script).then(function (mod) {
                  window.pdfjsLib = mod;
                  mod.GlobalWorkerOptions.workerSrc = FCAI.PDFJS.worker;
                  return mod;
              })
            : new Promise(function (risolvi, rifiuta) {
                  var s = document.createElement('script');
                  s.src = FCAI.PDFJS.script;
                  s.onload = function () {
                      window.pdfjsLib.GlobalWorkerOptions.workerSrc = FCAI.PDFJS.worker;
                      risolvi(window.pdfjsLib);
                  };
                  s.onerror = function () { rifiuta(new Error('pdfjs non caricato')); };
                  document.head.appendChild(s);
              });
        return pdfPromise;
    }

    /**
     * Punto d'ingresso: da un File a { tipo, pagine[], caratteri, pulito }.
     * Non decide niente sull'intervallo: quello lo fa l'interfaccia con
     * intervallo() qui sotto.
     */
    async function estrai(file, onProgresso) {
        var nome = (file.name || '').toLowerCase();
        var r;
        if (/\.(txt|md|csv|tsv|json)$/.test(nome)) r = await daTesto(file);
        else if (/\.docx$/.test(nome)) r = await daDocx(file);
        else if (/\.(xlsx|xlsm)$/.test(nome)) r = await daXlsx(file);
        else if (/\.pdf$/.test(nome)) r = await daPdf(file, onProgresso);
        else if (/\.(doc|xls|ppt|pptx|odt)$/.test(nome)) throw new Error('formato_vecchio');
        else r = await daTesto(file); // sconosciuto ma forse testuale: si prova

        r.pagine = r.pagine.map(pulisci);
        r.caratteri = r.pagine.reduce(function (t, p) { return t + p.length; }, 0);

        /* Un PDF di sole immagini estrae zero caratteri: e' il caso da
           riconoscere SUBITO, perche' l'utente ha scelto un file "che ha del
           testo" e va detto adesso, non dopo aver speso una chiamata. */
        r.haTesto = r.caratteri > 200;
        return r;
    }

    /* ============================================================
       3. PULIZIA
       ============================================================
       Ogni carattere tolto qui e' un carattere non pagato. Su un PDF esportato
       da slide o da un libro, intestazioni e numeri di pagina da soli valgono
       il 10-15% del totale. */

    function pulisci(t) {
        return String(t || '')
            .replace(/\r\n?/g, '\n')
            /* Sillabazione a fine riga: "conti-\nnuita'" e' una parola sola.
               Va fatta PRIMA di unire le righe, o il trattino resta in mezzo. */
            .replace(/([a-zà-ù])-\n([a-zà-ù])/g, '$1$2')
            .replace(/[ \t]+/g, ' ')
            .replace(/ *\n */g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    /** Toglie le righe identiche che si ripetono su quasi tutte le pagine:
        sono intestazioni e pie' di pagina, e non insegnano niente. */
    function togliRicorrenti(pagine) {
        if (pagine.length < 4) return pagine;
        var conta = {};
        pagine.forEach(function (p) {
            var righe = p.split('\n');
            [righe[0], righe[righe.length - 1]].forEach(function (r) {
                r = (r || '').trim();
                if (r.length > 2 && r.length < 90) conta[r] = (conta[r] || 0) + 1;
            });
        });
        var soglia = Math.max(3, Math.floor(pagine.length * 0.6));
        var daTogliere = Object.keys(conta).filter(function (r) { return conta[r] >= soglia; });
        if (!daTogliere.length) return pagine;

        return pagine.map(function (p) {
            return p.split('\n').filter(function (r) {
                var t = r.trim();
                if (daTogliere.indexOf(t) !== -1) return false;
                return !/^[-–\s]*\d{1,4}[-–\s]*$/.test(t); // numero di pagina isolato
            }).join('\n');
        });
    }

    /** Testo di un intervallo di pagine (1-based, estremi inclusi). */
    function intervallo(estratto, da, a) {
        var pagine = togliRicorrenti(estratto.pagine);
        da = Math.max(1, da | 0);
        a = Math.min(pagine.length, a | 0);
        return pagine.slice(da - 1, a).join('\n\n');
    }

    /** Fin dove si puo' arrivare partendo da "da" senza sfondare il limite. */
    function ultimaPaginaPossibile(estratto, da, maxCaratteri) {
        var pagine = estratto.pagine, totale = 0, i;
        for (i = da - 1; i < pagine.length; i++) {
            totale += pagine[i].length + 2;
            if (totale > maxCaratteri) return Math.max(da, i); // i e' gia' 1-based sulla precedente
        }
        return pagine.length;
    }

    function stimaToken(caratteri) { return Math.ceil(caratteri / 3.3); }

    /* ============================================================
       4. ANCORE ED ESTRATTI
       ============================================================
       Il cuore del risparmio: alla generazione non si rimanda il documento, ma
       solo le finestre di testo attorno ai concetti di QUEL lotto. */

    /* Le lettere accentate diventano la lettera semplice. Serve davvero: nei PDF
       italiani "probabilita'" con l'apostrofo e "probabilità" con l'accento
       convivono nello stesso documento, e il modello riscrive l'ancora con
       l'accento giusto — che nel testo originale non c'e'. La conversione e'
       carattere per carattere apposta, cosi' la lunghezza non cambia e la mappa
       delle posizioni resta allineata. */
    var FCAI_ACCENTI = { 'à': 'a', 'á': 'a', 'â': 'a', 'ä': 'a', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e',
                         'ì': 'i', 'í': 'i', 'î': 'i', 'ï': 'i', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'ö': 'o',
                         'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ç': 'c', 'ñ': 'n' };

    function piatto(c) { return FCAI_ACCENTI[c] || c; }

    function normalizza(s) {
        var out = '';
        for (var i = 0; i < s.length; i++) {
            var c = piatto(s[i].toLowerCase());
            out += /[a-z0-9]/.test(c) ? c : ' ';
        }
        return out.replace(/\s+/g, ' ').trim();
    }

    /* Indice di ricerca costruito una volta sola: la versione normalizzata del
       testo, piu' la corrispondenza fra ogni posizione normalizzata e la
       posizione originale. Senza, ritrovare un'ancora richiederebbe una
       scansione diversa per ogni concetto. */
    function indice(testo) {
        var norm = '', mappa = [], precedenteSpazio = true;
        for (var i = 0; i < testo.length; i++) {
            var c = piatto(testo[i].toLowerCase());
            if (/[a-z0-9]/.test(c)) { norm += c; mappa.push(i); precedenteSpazio = false; }
            else if (!precedenteSpazio) { norm += ' '; mappa.push(i); precedenteSpazio = true; }
        }
        return { testo: testo, norm: norm, mappa: mappa };
    }

    /** Posizione dell'ancora nel testo, o -1. */
    function trovaAncora(idx, ancora) {
        if (!ancora) return -1;
        var diretto = idx.testo.indexOf(ancora);
        if (diretto !== -1) return diretto;

        /* Il modello riscrive quasi sempre qualcosa (accenti, spaziatura,
           maiuscole): il confronto normalizzato recupera questi casi. Se
           fallisce anche quello, si prova con le prime parole soltanto —
           un'ancora corta ma giusta vale piu' di nessuna ancora. */
        var n = normalizza(ancora);
        var p = idx.norm.indexOf(n);
        if (p === -1 && n.split(' ').length > 3) {
            p = idx.norm.indexOf(n.split(' ').slice(0, 3).join(' '));
        }
        return p === -1 ? -1 : idx.mappa[p];
    }

    /**
     * Estratto per un gruppo di concetti: le finestre attorno alle loro ancore,
     * unite quando si sovrappongono.
     * I concetti senza ancora ritrovata non spariscono: semplicemente non
     * portano contesto, e la carta si scrivera' sul solo titolo.
     */
    function estrattoPer(idx, concetti, raggio) {
        raggio = raggio || 450;
        var finestre = [];

        concetti.forEach(function (c) {
            /* Un concetto nato dalla fusione di due porta due ancore: se se ne
               guardasse una sola, meta' del contesto sparirebbe proprio nella
               carta che ne ha piu' bisogno, perche' ne tratta due cose. */
            var ancore = c.ancore && c.ancore.length ? c.ancore : [c.a];
            ancore.forEach(function (anc) {
                var p = trovaAncora(idx, anc);
                if (p === -1) return;
                var da = Math.max(0, p - Math.round(raggio / 3));   // piu' contesto DOPO che prima:
                var a = Math.min(idx.testo.length, p + raggio);      // la definizione segue il concetto
                finestre.push([da, a]);
            });
        });
        if (!finestre.length) return '';

        finestre.sort(function (x, y) { return x[0] - y[0]; });
        var unite = [finestre[0]];
        for (var i = 1; i < finestre.length; i++) {
            var u = unite[unite.length - 1];
            if (finestre[i][0] <= u[1] + 120) u[1] = Math.max(u[1], finestre[i][1]);
            else unite.push(finestre[i]);
        }

        return unite.map(function (f) {
            return idx.testo.slice(f[0], f[1]).trim();
        }).join('\n[...]\n');
    }

    /* ============================================================
       5. DIALOGO CON fc_ai.php
       ============================================================ */

    /* nisama_fetch.js aggiunge da se' il token CSRF; qui si aggiunge solo
       no-store, perche' una risposta IA riletta dalla cache sarebbe la stessa
       identica generazione due volte. */
    async function chiama(azione, dati) {
        var r = await fetch('fc_ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify(Object.assign({ azione: azione }, dati))
        });
        var j = null;
        try { j = await r.json(); } catch (e) { /* risposta non JSON: gestita sotto */ }
        if (!j) throw { codice: 'rete', http: r.status };
        if (!j.ok) throw Object.assign({ codice: j.error || 'errore' }, j);
        return j;
    }

    function stima(per, dati) { return chiama('stima', Object.assign({ per: per }, dati)); }

    function concetti(dati) {
        return chiama('concetti', dati).then(function (j) { return j.concetti; });
    }

    /** verso: 'piu' | 'meno'. Restituisce il nuovo elenco gia' applicato. */
    async function densita(elenco, verso, testo) {
        var titoli = elenco.map(function (c) { return c.t; });
        var j = await chiama('densita', { verso: verso, titoli: titoli, testo: verso === 'piu' ? testo : '' });

        if (verso === 'meno') {
            if (!j.modificato) return { elenco: elenco, modificato: false };
            /* Le fusioni si applicano QUI, non dall'IA: cosi' i concetti che non
               vanno fusi restano bit per bit quelli che l'utente aveva
               eventualmente gia' corretto a mano. */
            var fusi = {}, nuovo = [];
            j.fusioni.forEach(function (g, k) {
                var capo = Math.min.apply(null, g);
                g.forEach(function (i) { fusi[i] = capo; });
                fusi['t' + capo] = j.titoli[k];
            });
            elenco.forEach(function (c, i) {
                if (fusi[i] === undefined) { nuovo.push(c); return; }
                if (fusi[i] !== i) return;                 // assorbito dal capogruppo
                nuovo.push(Object.assign({}, c, { t: fusi['t' + i] || c.t }));
            });
            return { elenco: nuovo, modificato: true, token: j.token };
        }

        if (!j.aggiunte.length) return { elenco: elenco, modificato: false, token: j.token };
        /* Si inserisce dal fondo: inserire dall'inizio sposterebbe gli indici
           "d" di tutte le aggiunte successive. */
        var out = elenco.slice();
        j.aggiunte.slice().sort(function (a, b) { return b.d - a.d; }).forEach(function (a) {
            out.splice(a.d + 1, 0, { t: a.t, a: a.a, p: null, e: 0 });
        });
        return { elenco: out, modificato: true, token: j.token };
    }

    /**
     * Genera tutte le carte, a lotti.
     * onLotto(carte, fatti, totale) viene chiamato a ogni lotto: le carte
     * compaiono man mano invece di far aspettare al buio, e se un lotto salta
     * i precedenti restano.
     * $nota (facoltativa) e' un'indicazione dello studente valida per l'intera
     * rigenerazione — es. "carte piu' brevi" — inoltrata a ogni lotto.
     */
    async function generaTutte(elenco, testo, opzioni, onLotto, nota) {
        var idx = indice(testo || '');
        var LOTTO = 12;
        var carte = [], mancanti = [], token = 0;

        for (var i = 0; i < elenco.length; i += LOTTO) {
            var gruppo = elenco.slice(i, i + LOTTO).map(function (c, k) {
                /* Un figlio riceve l'esempio solo se l'utente lo ha chiesto. Il
                   controllo sta qui e non nel prompt perche' la gerarchia la
                   conosciamo con certezza, mentre il modello la ricostruirebbe
                   a occhio: una regola che si puo' far rispettare in codice non
                   si delega a chi puo' sbagliarla. */
                var haFiglio = c.p !== null && c.p !== undefined;
                var esempio = c.e && (opzioni.esempi_figli || !haFiglio) ? 1 : 0;
                return { i: i + k, t: c.t, e: esempio, a: c.a || '', ancore: c.ancore || null, p: c.p };
            });

            var j = await chiama('carte', {
                concetti: gruppo.map(function (c) { return { i: c.i, t: c.t, e: c.e, p: c.p }; }),
                estratto: testo ? estrattoPer(idx, gruppo) : '',
                opzioni: opzioni,
                nota: nota || '',
                lingua: opzioni.lingua || 'italiano'
            });

            carte = carte.concat(j.carte);
            mancanti = mancanti.concat(j.mancanti || []);
            token += j.token || 0;
            if (onLotto) onLotto(j.carte, Math.min(i + LOTTO, elenco.length), elenco.length);
        }
        return { carte: carte, mancanti: mancanti, token: token };
    }

    /**
     * Migliora (o crea da zero) una carta sola, qualunque sia la sua origine —
     * generata dall'IA, scritta a mano, importata. $dati: { domanda, risposta,
     * nota, estratto, opzioni:{esempi}, lingua }.
     */
    function migliora(dati) {
        return chiama('migliora', dati).then(function (j) { return j.carta; });
    }

    /** Rigenerazione di una carta sola: stesso endpoint, un concetto solo. */
    async function rigenera(elenco, posizione, testo, opzioni, nota) {
        var idx = indice(testo || '');
        var c = elenco[posizione];
        var uno = [{ i: posizione, t: c.t, e: c.e ? 1 : 0, a: c.a || '', ancore: c.ancore || null }];
        var j = await chiama('carte', {
            concetti: [{ i: posizione, t: c.t, e: c.e ? 1 : 0 }],
            estratto: testo ? estrattoPer(idx, uno, 700) : '', // piu' contesto: costa poco per una sola
            opzioni: opzioni,
            nota: nota || '',
            lingua: opzioni.lingua || 'italiano'
        });
        return j.carte[0] || null;
    }

    /* ============================================================
       6. FUORI
       ============================================================ */

    return {
        /* pdf.js va copiato in /Vocabolary/pdfjs/. Non e' su CDN apposta: la
           CSP del sito non permette script esterni, e allargarla per una
           libreria varrebbe meno di due file copiati una volta sola. */
        PDFJS: { script: 'pdfjs/pdf.min.js', worker: 'pdfjs/pdf.worker.min.js' },

        estrai: estrai,
        intervallo: intervallo,
        ultimaPaginaPossibile: ultimaPaginaPossibile,
        stimaToken: stimaToken,
        pulisci: pulisci,

        indice: indice,
        trovaAncora: trovaAncora,
        estrattoPer: estrattoPer,

        stima: stima,
        concetti: concetti,
        densita: densita,
        generaTutte: generaTutte,
        rigenera: rigenera,
        migliora: migliora,
        chiama: chiama,

        lockScroll: lockScroll,
        unlockScroll: unlockScroll
    };
})();
