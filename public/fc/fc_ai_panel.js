/* fc_ai_panel.js
   ================================================================
   La logica del pannello. Il motore (lettura dei file, ancore, lotti, chiamate)
   sta in fc_ai_client.js: qui ci sono solo i pulsanti e cio' che mostrano.

   PUNTI DI CONTATTO CON fc_insert.php
   Quattro funzioni che esistono gia' e che NON vengono toccate:
     appendRowHTML({rid, q, a, qImgUrl, qImgName, aImgUrl, aImgName, isChild, ...}) -> la riga
     markDirty()             segna che c'e' roba da salvare
     showToast(msg, tipo)    avviso in basso
     showConfirm(t, m, ok)   conferma, restituisce una promessa
   Se un giorno cambiano nome, si cambia qui dentro, in fcaiInserisci().
   ================================================================ */

var FCAIP = (function () {
    'use strict';

    /* Le stringhe vivono nel dizionario del sito (T(), lato JS): niente piu'
       oggetto locale da tenere allineato a mano. Ogni chiamata T() e' fatta
       al momento dell'uso, non qui in cima — cosi' non dipende dal fatto che
       il dizionario sia gia' pronto quando questo script viene letto. */

    var stato = {
        fonte: 'file',
        modoImport: 'excel',
        estratto: null,   // risultato di FCAI.estrai()
        testo: '',        // il testo effettivamente scelto (intervallo compreso)
        elenco: [],       // [{t, a, p, e}]
        numeri: {},       // indice del concetto -> numero della carta
        righe: {},        // indice del concetto -> la riga <div> generata
        daUnire: -1       // voce in attesa della seconda, per la fusione
    };

    var MAX_CARATTERI = 40000; // deve coincidere con FC_AI_MAX_TESTO in fc_ai_lib.php
    /* Oltre MAX_CARATTERI il documento non si taglia piu': lo legge il motore
       dei job, a blocchi, in background. Questo e' il suo tetto (circa 300
       pagine fitte): deve coincidere con AI_JOB_TESTO_MAX in ai_jobs.php. */
    var MAX_JOB = 1200000;
    /* Oltre questo numero di concetti "meno concetti" non si puo' chiedere:
       fc_ai.php ne guarda al massimo tanti (FC_AI_MAX_CONCETTI), e fondere
       solo i primi centoventi di ottocento sarebbe un risultato a meta' senza
       dirlo. */
    var MAX_DENSITA = 120;

    /** Il mazzo scelto nella pagina, o 0. Il job ne ha bisogno: e' li' che vivra'. */
    function mazzo() {
        var el = document.querySelector('input[name="category_id"]');
        var v = el ? parseInt(el.value, 10) : 0;
        return v > 0 ? v : 0;
    }

    function $(id) { return document.getElementById(id); }

    /* Flashcard di lingua: una carta ha quattro campi (parola, traduzione,
       significato, esempio) invece di due. Qui cambia solo come si leggono i
       dati incollati; le righe le disegna comunque appendRowHTML. */
    var VOCAB = !!(window.FC && window.FC.tipo === 'vocab');
    function pulisci(v) { return String(v == null ? '' : v).trim().replace(/\\%/g, '%'); }
    function daCampi(p) {
        if (!VOCAB) return { q: (p[0] || '').trim(), a: p.slice(1).join('\t').trim() };   // come prima
        return { q: pulisci(p[0]), a: pulisci(p[1]), m: pulisci(p[2]), ex: pulisci(p.slice(3).join(' ')) };
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    }); }

    /* ============================================================
       1. SEZIONI COMPATTE
       ============================================================ */

    function apri(nome) {
        var sezioni = document.querySelectorAll('#fcaiPanel .fcai-sec');
        for (var i = 0; i < sezioni.length; i++) {
            var s = sezioni[i];
            /* Una aperta per volta: sono tre strade alternative per fare la
               stessa cosa, e tenerne aperte due insieme suggerirebbe che si
               possano combinare. */
            s.classList.toggle('is-open', s.getAttribute('data-sec') === nome && !s.classList.contains('is-open'));
        }
    }

    /* ============================================================
       2. INSERIMENTO DELLE CARTE NELLA TABELLA
       ============================================================ */

    /**
     * carte: [{q, a, qFile, aFile}]. Restituisce il numero della prima carta
     * inserita, che serve alla numerazione dell'elenco dei concetti.
     */
    async function fcaiInserisci(carte, svuota) {
        var lista = $('fcRows');
        if (svuota && lista.children.length > 0) {
            var ok = await showConfirm(T('fc.ibfai_ricrea_tit'), T('fc.ibfai_ricrea_testo'), T('fc.ibfai_si_ricrea'));
            if (!ok) return -1;
            lista.innerHTML = '';
        }
        var partenza = lista.querySelectorAll('.fc-row').length;

        carte.forEach(function (c, k) {
            var qUrl = c.qFile ? URL.createObjectURL(c.qFile) : '';
            var aUrl = c.aFile ? URL.createObjectURL(c.aFile) : '';
            var riga = appendRowHTML({
                rid: partenza + k,
                q: c.q || '', a: c.a || '',
                m: c.m || '', ex: c.ex || '',
                qImgUrl: qUrl, qImgName: c.qFile ? c.qFile.name : '',
                aImgUrl: aUrl, aImgName: c.aFile ? c.aFile.name : ''
            });
            /* Il file vero si attacca alla riga, come fa gia' generateTable():
               mandare solo il nome fa sbagliare bersaglio quando due immagini
               si chiamano uguale. */
            if (c.qFile) riga.qNewImgFile = c.qFile;
            if (c.aFile) riga.aNewImgFile = c.aFile;
        });

        markDirty();
        if (typeof updateGenerateButtonState === 'function') updateGenerateButtonState();
        return partenza + 1;
    }

    /* ============================================================
       3. IMPORTA
       ============================================================ */

    /**
     * Incollato da Excel. Non basta dividere per a capo e tabulazione: una
     * cella che contiene un a capo viene incollata racchiusa fra virgolette, e
     * un taglio secco spezzerebbe quella carta in due. Questo e' un lettore TSV
     * vero, che dentro le virgolette non taglia niente.
     */
    function leggiTsv(testo) {
        var righe = [], riga = [], campo = '', dentro = false, i = 0;
        testo = String(testo).replace(/\r\n?/g, '\n');

        while (i < testo.length) {
            var c = testo[i];
            if (dentro) {
                if (c === '"') {
                    if (testo[i + 1] === '"') { campo += '"'; i += 2; continue; } // virgoletta raddoppiata
                    dentro = false; i++; continue;
                }
                campo += c; i++; continue;
            }
            if (c === '"' && campo === '') { dentro = true; i++; continue; }
            if (c === '\t') { riga.push(campo); campo = ''; i++; continue; }
            if (c === '\n') { riga.push(campo); righe.push(riga); riga = []; campo = ''; i++; continue; }
            campo += c; i++;
        }
        riga.push(campo);
        if (riga.length > 1 || riga[0] !== '') righe.push(riga);

        return righe
            .map(daCampi)
            .filter(function (c) { return c.q !== '' || c.a !== ''; });
    }

    /** Testo libero: JSON oppure separatori scelti. E' la logica che c'era, spostata. */
    function leggiTesto() {
        var testo = $('import_text').value.trim();
        if (testo === '') return [];

        if (!$('format_switch').checked) {
            /* Il JSON generato dalle IA arriva quasi sempre con il LaTeX
               scritto male: le barre singole di \mu non sono valide dentro una
               stringa JSON, e gli a capo veri dentro le formule spezzano il
               documento. Si riparano prima di leggerlo, come faceva la pagina. */
            var sicuro = testo.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"q"|"a"|\{))/g, '\\n');
            sicuro = sicuro.replace(/(?<!\\)\\(?!n|t|r|"|\\|\/)/g, '\\\\');
            var arr = JSON.parse(sicuro); // l'errore lo raccoglie chi chiama
            if (!Array.isArray(arr)) throw new Error('non è un elenco');
            return arr.map(function (o) {
                if (VOCAB) return {
                    q:  pulisci(o.word || o.parola || o.w || o.q || ''),
                    a:  pulisci(o.translation || o.traduzione || o.t || o.a || ''),
                    m:  pulisci(o.meaning || o.significato || o.m || ''),
                    ex: pulisci(o.example || o.esempio || o.e || '')
                };
                return {
                    q: String(o.q || o.domanda || o.question || '').replace(/\\%/g, '%'),
                    a: String(o.a || o.risposta || o.answer || '').replace(/\\%/g, '%')
                };
            });
        }

        var sepCol = $('import_separator').value === '\\t' ? '\t' : $('import_separator').value;
        var sepRig = $('import_row_separator').value === '\\n' ? '\n' : $('import_row_separator').value;
        return testo.split(sepRig).map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) {
            var p = l.split(sepCol);
            if (VOCAB) return daCampi(p);
            return {
                q: (p[0] || '').trim().replace(/\\%/g, '%'),
                a: p.slice(1).join(sepCol).trim().replace(/\\%/g, '%')
            };
        });
    }

    async function importa() {
        var carte = [];
        try {
            carte = stato.modoImport === 'excel' ? leggiTsv($('fcaiExcel').value) : leggiTesto();
        } catch (e) {
            $('fcaiImportNota').className = 'fcai-nota is-err';
            $('fcaiImportNota').textContent = T('fc.ibfai_formato_import_errato', {errore: e.message});
            return;
        }
        if (!carte.length) { showToast(T('fc.ibfai_vuoto'), 'error'); return; }

        await fcaiInserisci(carte, true);
        $('fcaiImportNota').className = 'fcai-nota';
        $('fcaiImportNota').textContent = T('fc.ibfai_carte_create', {n: carte.length});
    }

    /* ============================================================
       4. IMMAGINI
       ============================================================ */

    function notaImmagini() {
        var q = $('question_images').files.length, a = $('answer_images').files.length;
        var n = $('fcaiImgNota');
        if (!q && !a) { n.textContent = ''; return; }
        if (q && a) {
            n.textContent = T('fc.ibfai_nota_abbinamento', {n: Math.max(q, a)});
        } else {
            n.textContent = T('fc.ibfai_nota_una_immagine', {n: (q || a)});
        }
    }

    async function daImmagini() {
        var fq = $('question_images').files, fa = $('answer_images').files;
        if (!fq.length && !fa.length) { showToast(T('fc.ibfai_vuoto'), 'error'); return; }

        var carte;
        if (fq.length && fa.length && typeof FCAIAbbina !== 'undefined') {
            /* Con due gruppi c'e' una scelta da fare, e la fa l'utente. Con un
               gruppo solo non c'e' niente da decidere: ogni immagine e' gia'
               una carta, e aprire un popup per confermarlo sarebbe un passaggio
               in piu' che non decide nulla. */
            carte = await FCAIAbbina.apri(fq, fa);
            if (!carte) return; // annullato
        } else {
            carte = [];
            for (var i = 0; i < Math.max(fq.length, fa.length); i++) {
                carte.push({ qFile: fq[i] || null, aFile: fa[i] || null });
            }
        }

        await fcaiInserisci(carte.map(function (c) {
            return { q: '', a: '', qFile: c.qFile, aFile: c.aFile };
        }), true);
    }

    /* ============================================================
       5. IA — lettura del file
       ============================================================ */

    async function scegliFile(file) {
        if (!file) return;
        var info = $('fcaiFileInfo');
        info.innerHTML = '<div class="fcai-nota">' + T('fc.ibfai_leggendo_file') + '</div>';

        try {
            stato.estratto = await FCAI.estrai(file, function (n, tot) {
                info.innerHTML = '<div class="fcai-nota">' + T('fc.ibfai_pagina_progresso', {n: n, tot: tot}) + '</div>';
            });
        } catch (e) {
            stato.estratto = null;
            info.innerHTML = '<div class="fcai-nota is-err">' +
                (e && e.message === 'formato_vecchio' ? T('fc.ibfai_formato_vecchio') : T('fc.ibfai_lettura_fallita')) + '</div>';
            return;
        }

        if (!stato.estratto.haTesto) {
            info.innerHTML = '<div class="fcai-nota is-err">' + T('fc.ibfai_nessun_testo') + '</div>';
            stato.estratto = null;
            return;
        }

        var pagine = stato.estratto.pagine.length;
        /* Di serie si propone TUTTO il documento: un file lungo non va piu'
           tagliato a mano, lo legge il motore dei job a blocchi. Si restringe
           solo oltre il tetto del motore. */
        var fino = stato.estratto.caratteri > MAX_JOB
            ? FCAI.ultimaPaginaPossibile(stato.estratto, 1, MAX_JOB) : pagine;
        var serveIntervallo = stato.estratto.caratteri > MAX_CARATTERI;

        info.innerHTML =
            '<div class="fcai-file">' +
                '<div style="flex:1; min-width:0;">' +
                    '<b>' + esc(file.name) + '</b>' +
                    '<small>' + pagine + ' ' + (pagine === 1 ? T('fc.ibfai_pagina_sing') : T('fc.ibfai_pagina_plur')) + ' · ' +
                        stato.estratto.caratteri.toLocaleString('it-IT') + ' ' + T('fc.ibfai_caratteri') + '</small>' +
                '</div>' +
                '<button type="button" class="fcai-x" id="fcaiTogliFile" aria-label="' + esc(T('fc.ibfai_togli')) + '">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>' +
                '</button>' +
            '</div>' +
            (serveIntervallo || pagine > 1 ?
                '<div class="fcai-riga" style="margin-top:10px;">' +
                    '<div><label class="fcai-label">Dalla pagina</label>' +
                        '<input type="number" id="fcaiDa" min="1" max="' + pagine + '" value="1"></div>' +
                    '<div><label class="fcai-label">alla</label>' +
                        '<input type="number" id="fcaiA" min="1" max="' + pagine + '" value="' + fino + '"></div>' +
                '</div>' : '') +
            '<div class="fcai-barra" id="fcaiBarra"><i style="width:0"></i></div>' +
            '<div class="fcai-nota" id="fcaiCosto"></div>';

        $('fcaiTogliFile').addEventListener('click', function () {
            stato.estratto = null; stato.testo = ''; info.innerHTML = ''; $('fcaiFile').value = '';
        });
        if ($('fcaiDa')) {
            $('fcaiDa').addEventListener('input', function () { aggiornaIntervallo('da'); });
            $('fcaiA').addEventListener('input', function () { aggiornaIntervallo('a'); });
        }
        aggiornaIntervallo();
    }

    /**
     * Riporta "da" e "a" dentro i limiti e li tiene coerenti fra loro.
     * $chi dice quale dei due ha appena toccato l'utente: e' l'altro che deve
     * cedere. Senza questo, scrivendo 100 in "da" si resta con "a" a 2 e la
     * selezione e' vuota senza che niente lo dica.
     */
    function normalizzaPagine(chi) {
        if (!stato.estratto || !$('fcaiDa')) return;
        var pagine = stato.estratto.pagine.length;
        var cDa = $('fcaiDa'), cA = $('fcaiA');

        var da = parseInt(cDa.value, 10);
        var a = parseInt(cA.value, 10);
        if (isNaN(da)) da = 1;
        if (isNaN(a)) a = pagine;

        da = Math.min(Math.max(da, 1), pagine);
        a = Math.min(Math.max(a, 1), pagine);

        /* Chi si e' mosso comanda: se sposto l'inizio oltre la fine, e' la fine
           a seguirlo, non l'inizio a tornare indietro annullando quello che ho
           appena scritto. */
        if (chi === 'da' && a < da) a = da;
        if (chi === 'a' && da > a) da = a;

        cDa.value = da;
        cA.value = a;
        cDa.max = pagine; cA.max = pagine;
        cDa.min = 1; cA.min = 1;
        return { da: da, a: a };
    }

    function aggiornaIntervallo(chi) {
        if (!stato.estratto) return;
        var pagine = stato.estratto.pagine.length;
        var r = normalizzaPagine(chi) || { da: 1, a: pagine };

        stato.testo = FCAI.intervallo(stato.estratto, r.da, r.a);
        var lungo = stato.testo.length > MAX_CARATTERI;
        var oltre = stato.testo.length > MAX_JOB;
        var quota = stato.testo.length / (lungo ? MAX_JOB : MAX_CARATTERI);

        var barra = $('fcaiBarra');
        barra.classList.toggle('is-over', oltre);
        barra.firstElementChild.style.width = Math.min(100, quota * 100) + '%';

        var costo = $('fcaiCosto');
        costo.className = oltre ? 'fcai-nota is-warn' : 'fcai-nota';
        if (oltre) {
            /* Non si tronca in silenzio: si dice fin dove arriverebbe, cosi' la
               correzione e' un numero da copiare invece di un tentativo. */
            var possibile = FCAI.ultimaPaginaPossibile(stato.estratto, r.da, MAX_JOB);
            costo.textContent = T('fc.ibfai_troppo_lungo') + ' ' + T('fc.ibfai_intervallo_max', {da: r.da, fino: possibile});
        } else if (lungo) {
            /* Oltre un blocco: si dice come verra' letto e che succede in
               background, cosi' la schermata di avanzamento non arriva a
               sorpresa. */
            costo.textContent = T('fc.ibfai_documento_lungo', {
                car: stato.testo.length.toLocaleString('it-IT'),
                blocchi: Math.ceil(stato.testo.length / MAX_CARATTERI)
            });
        } else {
            costo.textContent = T('fc.ibfai_selezione_costo', {car: stato.testo.length.toLocaleString('it-IT'), tok: FCAI.stimaToken(stato.testo.length).toLocaleString('it-IT')});
        }
    }

    /* ============================================================
       6. IA — concetti
       ============================================================ */

    function opzioni() {
        return {
            esempi: $('fcaiOpzEsempi').checked,
            // richiede ANCHE la gerarchia: senza carte figlie non c'e' niente a cui
            // applicare "anche sulle figlie", l'opzione da sola non significa nulla
            esempi_figli: $('fcaiOpzEsempi').checked && $('fcaiOpzGerarchia').checked && $('fcaiOpzEsempiFigli').checked,
            gerarchia: $('fcaiOpzGerarchia').checked,
            integra: $('fcaiOpzIntegra').checked,
            // NON dipende piu' da integra: "correggi" ha senso anche da solo — vuol
            // dire "non aggiungere nulla di tuo, ma verifica che quello che c'e' sia
            // corretto", non "aggiungi E correggi insieme"
            corregge: $('fcaiOpzCorregge').checked,
            testuale: $('fcaiOpzTestuale').checked,
            solo_elenco: $('fcaiSoloElenco') ? $('fcaiSoloElenco').checked : false,
            /* Le indicazioni libere valgono per qualunque fonte e arrivano sia
               alla scelta dei concetti sia alla scrittura delle carte. Prima
               esistevano solo per "argomento" e si fermavano ai concetti. */
            indicazioni: $('fcaiIndicazioni') ? $('fcaiIndicazioni').value.trim() : '',
            stile: leggiStile(),
            impegno: impegnoScelto(),
            lingua: 'italiano'
        };
    }

    /* ============================================================
       STILE E IMPEGNO
       ============================================================
       Tutto parte da "Automatico" e da impegno "medio": finche' l'utente non
       sceglie niente, le carte escono esattamente come prima. Il server valida
       di nuovo ogni valore (fc_ai_stile_leggi): qui si raccoglie e basta. */

    function leggiStile() {
        var st = {};
        document.querySelectorAll('#fcaiStile select[data-stile]').forEach(function (sel) {
            st[sel.getAttribute('data-stile')] = sel.value;
        });
        st.etichette = $('fcaiStileEtichette') ? $('fcaiStileEtichette').value.split('\n')
            .map(function (r) { return r.trim(); }).filter(Boolean) : [];
        st.modello_q = $('fcaiModelloQ') ? $('fcaiModelloQ').value.trim() : '';
        st.modello_a = $('fcaiModelloA') ? $('fcaiModelloA').value.trim() : '';
        return st;
    }

    /* L'impegno e' una tendina (NS.tendina): chiusa mostra la voce scelta,
       aperta mostra le quattro con la spiegazione di ciascuna. Il valore si
       legge sempre dal DOM e non da una variabile, cosi' resta una fonte
       sola anche se la tendina non si e' potuta agganciare (standout_ui.js non
       caricato): in quel caso le voci restano cliccabili come pulsanti. */
    var tendinaImpegno = null;

    function impegnoScelto() {
        if (tendinaImpegno) return tendinaImpegno.valore();
        var b = document.querySelector('#fcaiImpegno [data-impegno].is-on');
        return b ? b.getAttribute('data-impegno') : 'medio';
    }

    function scegliImpegno(valore) {
        if (tendinaImpegno) tendinaImpegno.imposta(valore, true);
        else document.querySelectorAll('#fcaiImpegno [data-impegno]').forEach(function (b) {
            var su = b.getAttribute('data-impegno') === valore;
            b.classList.toggle('is-on', su);
            b.setAttribute('aria-selected', su ? 'true' : 'false');
        });
        if ($('fcaiImpegnoDesc')) $('fcaiImpegnoDesc').textContent = T('fc.ibfai_impegno_' + valore + '_desc');
    }

    function applicaStile(dati) {
        if (!dati) return;
        var st = dati.stile || {};
        document.querySelectorAll('#fcaiStile select[data-stile]').forEach(function (sel) {
            var v = st[sel.getAttribute('data-stile')];
            // un valore che non esiste piu' fra le opzioni resta su "auto"
            sel.value = Array.prototype.some.call(sel.options, function (o) { return o.value === v; }) ? v : 'auto';
        });
        if ($('fcaiStileEtichette')) $('fcaiStileEtichette').value = (st.etichette || []).join('\n');
        if ($('fcaiModelloQ')) $('fcaiModelloQ').value = st.modello_q || '';
        if ($('fcaiModelloA')) $('fcaiModelloA').value = st.modello_a || '';
        if ($('fcaiModello')) $('fcaiModello').open = !!st.modello_a;
        scegliImpegno(dati.impegno || 'medio');
        aggiornaStileUI();
    }

    /* Le voci dello schema compaiono solo quando servono, e una scelta fatta
       si distingue da "Automatico": e' cio' che verra' verificato. */
    function aggiornaStileUI() {
        document.querySelectorAll('#fcaiStile select[data-stile]').forEach(function (sel) {
            sel.classList.toggle('is-scelto', sel.value !== 'auto');
        });
        var sel = document.querySelector('#fcaiStile select[data-stile="struttura"]');
        if ($('fcaiStileSchema')) $('fcaiStileSchema').hidden = !sel || sel.value !== 'schema';
    }

    /* Lo stile si ricorda PER MAZZO: un mazzo di algoritmi e uno di storia
       non vogliono le stesse risposte. Un mazzo mai visto parte dall'ultimo
       stile usato, che e' il piu' probabile. Resta nel browser: e' una
       comodita' di chi scrive, non un dato del mazzo. */
    function chiaveStile() { var m = mazzo(); return 'fcai_stile:' + (m || 'ultimo'); }

    function salvaStile() {
        var dati = JSON.stringify({ stile: leggiStile(), impegno: impegnoScelto() });
        try {
            localStorage.setItem(chiaveStile(), dati);
            localStorage.setItem('fcai_stile:ultimo', dati);
        } catch (e) {}
    }

    function caricaStile() {
        try {
            var r = localStorage.getItem(chiaveStile()) || localStorage.getItem('fcai_stile:ultimo');
            if (r) applicaStile(JSON.parse(r));
        } catch (e) {}
    }

    /* Dopo ogni tocco: stato visivo, salvataggio, riassunto e, se il popup e'
       quello della conferma, il costo ricalcolato. Per i campi di testo con
       un attimo di ritardo: il preventivo a ogni lettera sarebbe una chiamata
       per lettera. */
    var attesaConto = null;
    function stileCambiato(subito) {
        aggiornaStileUI();
        salvaStile();
        $('fcaiRiepilogo').textContent = riepilogo();
        if ($('fcaiModaleConto').hidden) return;
        clearTimeout(attesaConto);
        attesaConto = setTimeout(aggiornaConto, subito ? 0 : 500);
    }

    /* Un'opzione che dipende da un'altra si spegne e si blocca quando la
       maggiore e' spenta. Restare accesa mentre non ha effetto sarebbe la cosa
       peggiore: l'utente crederebbe di aver chiesto qualcosa che non arrivera'
       mai. Il valore pero' non si azzera, cosi' riaccendendo la maggiore si
       ritrova la scelta di prima.

       data-dipende puo' contenere piu' di un id separato da spazi (esempi sulle
       figlie dipende SIA da "genera esempi" SIA da "gerarchia"): servono tutte
       accese, non basta una sola. */
    function aggiornaDipendenze() {
        document.querySelectorAll('#fcaiModale [data-dipende]').forEach(function (riga) {
            var ids = riga.getAttribute('data-dipende').split(/\s+/);
            var tutte = ids.every(function (id) { var el = $(id); return el && el.checked; });
            riga.classList.toggle('is-bloccata', !tutte);
        });
        aggiornaFonteImpostazioni();
        $('fcaiRiepilogo').textContent = riepilogo();
    }

    /* "Da dove prende le informazioni" cambia contenuto secondo la fonte
       scelta nel pannello (non nel popup): un file offre integra/correggi/
       testuale, un elenco scritto a mano offre solo "attieniti al mio
       elenco", un argomento libero non ha nessuna fonte a cui essere fedeli e
       la sezione intera sparisce. */
    function aggiornaFonteImpostazioni() {
        var sezione = $('fcaiSezioneFonti');
        if (!sezione) return;
        sezione.hidden = (stato.fonte === 'argomento');
        document.querySelectorAll('[data-fonte-impostazioni]').forEach(function (blocco) {
            blocco.hidden = blocco.getAttribute('data-fonte-impostazioni') !== stato.fonte;
        });
    }

    /** Riassunto delle scelte in una riga, per il pannello. */
    function riepilogo() {
        var o = opzioni();
        var v = [];
        if (o.esempi) v.push(o.esempi_figli ? T('fc.ibfai_riep_esempi_figli') : T('fc.ibfai_riep_esempi'));
        if (o.gerarchia) v.push(T('fc.ibfai_riep_gerarchia'));
        v.push(o.integra ? (o.corregge ? T('fc.ibfai_riep_integra_corregge') : T('fc.ibfai_riep_integra')) : T('fc.ibfai_riep_solo_fonti'));
        if (o.testuale) v.push(T('fc.ibfai_riep_testuale'));
        if (o.impegno !== 'medio') v.push(T('fc.ibfai_riep_impegno', { livello: T('fc.ibfai_impegno_' + o.impegno) }));
        var scelte = Object.keys(o.stile).filter(function (k) {
            return typeof o.stile[k] === 'string' && k.indexOf('modello') !== 0 && o.stile[k] !== 'auto';
        }).length;
        if (scelte || o.stile.modello_a) v.push(T('fc.ibfai_riep_stile'));
        return v.join(' · ');
    }

    /* Una promessa sola in sospeso: il popup e' uno, e due attese contemporanee
       vorrebbero dire due finestre aperte, che non puo' succedere. */
    var modaleRisolvi = null;

    function chiudiModale(esito) {
        $('fcaiModale').hidden = true;
        FCAI.unlockScroll();
        var f = modaleRisolvi; modaleRisolvi = null;
        if (f) f(esito);
    }

    /**
     * Apre il popup. Con conto = null mostra solo le impostazioni; altrimenti
     * mostra anche riepilogo e costo, e i due pulsanti diventano quelli della
     * conferma. Restituisce una promessa: true se ha confermato.
     */
    function apriModale(titolo, sottotitolo, etichettaSi, conConto) {
        $('fcaiModaleTit').textContent = titolo;
        $('fcaiModaleSub').textContent = sottotitolo;
        $('fcaiModaleSi').textContent = etichettaSi;
        $('fcaiModaleNo').hidden = !conConto;
        $('fcaiModaleConto').hidden = !conConto;
        $('fcaiModale').hidden = false;
        FCAI.lockScroll();
        aggiornaDipendenze();
        if (conConto) aggiornaConto();
        return new Promise(function (r) { modaleRisolvi = r; });
    }

    /* ============================================================
       I TUOI CREDITI, SENZA USCIRE DA QUI
       ============================================================
       Il contenuto e' quello di credits.php (?riquadro=1: solo il contenuto,
       senza intestazione di pagina). I mesi si sfogliano dentro il popup: i
       link restano quelli veri — la pagina intera continua a funzionare da
       sola — e qui vengono intercettati e ricaricati al loro posto. */

    var creditiInCorso = null;

    function apriCrediti() {
        if (!$('fcaiCreditiModale')) return;
        if ($('fcaiCreditiModale').hidden) {
            $('fcaiCreditiModale').hidden = false;
            FCAI.lockScroll();        // una volta sola: sfogliare i mesi non riapre niente
        }
        caricaCrediti();
    }

    async function caricaCrediti(mese) {
        var box = $('fcaiCreditiCorpo');
        if (!box) return;

        var finito = window.NS ? NS.scheletro(box, { righe: 7 }) : function () {};
        var url = '../ai/credits.php?riquadro=1' + (mese ? '&mese=' + encodeURIComponent(mese) : '');
        var mio = creditiInCorso = {};
        try {
            var r = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
            var html = await r.text();
            if (mio !== creditiInCorso) return;      // nel frattempo ne e' partita un'altra
            finito();
            if (!r.ok) throw new Error('http ' + r.status);
            box.innerHTML = html;
            /* I link dei mesi restano <a href> veri nel markup — la pagina
               intera li usa cosi' — ma qui dentro navigare porterebbe via. */
            box.querySelectorAll('a[href*="mese="]').forEach(function (a) {
                a.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var m = (a.getAttribute('href').match(/mese=([0-9]{4}-[0-9]{2})/) || [])[1];
                    caricaCrediti(m);
                });
            });
            /* I numeri delle carte dentro il popup non portano da nessuna
               parte: sono un riepilogo, non una navigazione. */
            if (window.NS) NS.preme(box);
        } catch (e) {
            if (mio !== creditiInCorso) return;
            finito();
            box.innerHTML = '';
            var p = document.createElement('div');
            p.className = 'fcai-nota';
            p.textContent = T('fc.cred_non_caricato');
            box.appendChild(p);
        }
    }

    function chiudiCrediti() {
        if (!$('fcaiCreditiModale')) return;
        creditiInCorso = null;
        $('fcaiCreditiModale').hidden = true;
        $('fcaiCreditiCorpo').innerHTML = '';
        FCAI.unlockScroll();
    }

    /* Il costo si ricalcola a ogni interruttore toccato: gli esempi lo alzano
       di meta', ed e' proprio mentre si decide che il numero serve. */
    var contoInCorso = null;
    async function aggiornaConto() {
        var box = $('fcaiModaleConto');
        var o = opzioni();
        var vivi = concettiVivi();

        var testo = T('fc.ibfai_conto_concetti', {concetti: JSON.stringify('<b>' + vivi.length + ' concetti</b>')});
        if (stato.tabellaDaSovrascrivere) testo += ' ' + T('fc.ibfai_carte_sostituite');
        box.className = 'fcai-modale-conto';
        box.innerHTML = testo + '<br>' + T('fc.ibfai_calcolo_costo');

        var mio = {};
        contoInCorso = mio;
        try {
            var idx = FCAI.indice(stato.testo || '');
            var caratteri = stato.testo ? FCAI.estrattoPer(idx, vivi).length : 0;
            var st = await FCAI.stima('carte', {
                concetti: vivi.map(function (c) { return { t: c.t }; }),
                caratteri: caratteri,
                opzioni: o
            });
            if (contoInCorso !== mio) return; // nel frattempo ne e' partita un'altra
            box.innerHTML = testo + '<br>' + (st.disponibili === null
                ? T('fc.ibfai_costo_stimato_secco', {stima: st.stima.toLocaleString('it-IT')})
                : T('fc.ibfai_costo_stimato_resto', {stima: st.stima.toLocaleString('it-IT'), disp: st.disponibili.toLocaleString('it-IT')}));
            if (!st.sufficiente) {
                box.className = 'fcai-modale-conto is-warn';
                box.innerHTML += '<br>' + T('fc.ibfai_niente_token');
            }
        } catch (e) {
            if (contoInCorso !== mio) return;
            box.innerHTML = testo; // la stima e' un di piu': se non arriva, si genera lo stesso
        }
    }

    function mostraErrore(e) {
        var s = $('fcaiStato');
        s.className = 'fcai-nota is-err';
        s.textContent = window.FCAIJ ? FCAIJ.messaggio(e) : T('fc.ibfai_errore_ia');
    }

    /* Vero se sotto c'e' lavoro che una nuova ricerca di concetti butterebbe
       via. Le carte inserite a mano contano quanto quelle generate: chi le ha
       scritte non sa che nascono dallo stesso elenco. */
    function cSarebbePerso() {
        var carte = $('fcRows') ? $('fcRows').querySelectorAll('.fc-row').length : 0;
        return { elenco: concettiVivi().length, carte: carte };
    }

    async function trovaConcetti() {
        var perso = cSarebbePerso();
        if (perso.elenco || perso.carte) {
            var parti = [];
            if (perso.elenco) parti.push(T('fc.ibfai_perso_elenco', {n: perso.elenco}));
            if (perso.carte) parti.push(perso.carte + ' ' + (perso.carte === 1 ? T('fc.ibfai_carta_sing') : T('fc.ibfai_carta_plur')) + ' ' + T('fc.ibfai_in_tabella'));
            var ok = await showConfirm(T('fc.ibfai_ricomincia_tit'), T('fc.ibfai_ricomincia_testo', {parti: parti.join(' e ')}), T('fc.ibfai_si_ricomincia'));
            if (!ok) return;
        }

        var o = opzioni();
        var dati = { opzioni: o, lingua: o.lingua, indicazioni: o.indicazioni };

        if (stato.fonte === 'file') {
            if (!stato.testo) { showToast(T('fc.ibfai_vuoto'), 'error'); return; }
            if (stato.testo.length > MAX_JOB) { showToast(T('fc.ibfai_troppo_lungo'), 'error'); return; }

            /* Documento piu' lungo di un blocco: lo legge il motore dei job.
               Si fermera' da solo con l'elenco pronto (revisione), e l'elenco
               arrivera' qui, in questo stesso pannello, con i soliti comandi. */
            if (stato.testo.length > MAX_CARATTERI) {
                var m = mazzo();
                if (!m) { showToast(T('fc.ibf_serve_categoria'), 'error'); return; }
                stato.elenco = []; stato.numeri = {}; stato.jobRevisione = null;
                disegnaElenco();
                $('fcaiStato').className = 'fcai-nota';
                $('fcaiStato').textContent = '';
                FCAIJ.avvia({
                    context_id: m, testo: stato.testo,
                    opzioni: Object.assign({}, o, { fonte: 'file', revisione: true })
                });
                return;
            }
            dati.fonte = 'file';
            dati.testo = stato.testo;
        } else if (stato.fonte === 'elenco') {
            var righe = $('fcaiElencoTesto').value.split('\n').map(function (r) { return r.trim(); }).filter(Boolean);
            if (!righe.length) { showToast(T('fc.ibfai_vuoto'), 'error'); return; }
            dati.fonte = 'elenco';
            dati.elenco = righe;
            dati.titolo = $('fcaiElencoTitolo').value.trim();
        } else {
            var arg = $('fcaiArgomento').value.trim();
            if (!arg) { showToast(T('fc.ibfai_vuoto'), 'error'); return; }
            dati.fonte = 'argomento';
            dati.titolo = arg;
        }

        $('fcaiStato').className = 'fcai-nota';
        $('fcaiStato').textContent = T('fc.ibfai_cerco');
        $('fcaiBtnConcetti').disabled = true;
        try {
            /* L'attesa prende la forma di cio' che sta arrivando: sei righe
               finte al posto dell'elenco. Dice "sta arrivando una lista", che
               una rotella non dice, e fa sembrare l'attesa piu' corta. */
            $('fcaiRisultato').hidden = false;
            var fineAttesa = window.NS ? NS.scheletro($('fcaiElenco'), { righe: 6 }) : function () {};
            try {
                stato.elenco = await FCAI.concetti(dati);
            } finally {
                fineAttesa();
            }
            stato.numeri = {};
            disegnaElenco();
            $('fcaiStato').textContent = T('fc.ibfai_concetti_trovati', {n: stato.elenco.length});
        } catch (e) {
            mostraErrore(e);
        } finally {
            $('fcaiBtnConcetti').disabled = false;
        }
    }

    /** I concetti che verranno davvero generati: niente vuoti, niente sbarrati. */
    function concettiVivi() {
        return stato.elenco.filter(function (c) { return c.t.trim() !== '' && !c.tolto; });
    }

    /* ============================================================
       L'ELENCO E' NUMERATO PER POSIZIONE: SPOSTARE UNA VOCE SPOSTA TUTTO
       ============================================================
       Tre cose sono legate ai concetti tramite la loro POSIZIONE nell'elenco,
       non tramite un identificativo proprio:

         stato.numeri[i]   il numero della carta di quel concetto
         stato.righe[i]    la riga della tabella nata da quel concetto
         c.p               il concetto padre, indicato come posizione
         riga._concettoIdx il legame dalla parte della tabella

       Quindi infilare una voce a meta' elenco fa scorrere di uno tutte quelle
       sotto, e se non si aggiornano anche queste quattro cose il risultato e'
       proprio quello che si vede: la voce nuova si prende il numero di chi
       stava li' prima, l'ultima resta senza numero, e i figli finiscono
       appesi al padre sbagliato.

       Tutte le aggiunte e le rimozioni passano di qui. Una splice scritta a
       mano da qualche parte rimette il difetto: se serve toglierne o
       aggiungerne una, si usano queste due funzioni.
    */

    function mappaSposta(mappa, pos, delta) {
        var nuova = {};
        Object.keys(mappa).forEach(function (k) {
            var i = Number(k);
            if (delta < 0 && i === pos) return;   // la voce tolta si porta via il suo
            nuova[i >= pos ? i + delta : i] = mappa[k];
        });
        return nuova;
    }

    /** Riallinea numeri, righe, padri e legami della tabella dopo uno spostamento. */
    function elencoSposta(pos, delta, padreDelTolto) {
        stato.numeri = mappaSposta(stato.numeri, pos, delta);
        stato.righe  = mappaSposta(stato.righe,  pos, delta);

        stato.elenco.forEach(function (c) {
            if (c.p === null || c.p === undefined) return;
            var p = Number(c.p);
            if (delta < 0 && p === pos) { c.p = (padreDelTolto === undefined ? null : padreDelTolto); return; }
            c.p = p >= pos ? p + delta : p;
        });

        var cont = $('fcRows');
        if (!cont) return;
        Array.prototype.forEach.call(cont.querySelectorAll('.fc-row'), function (r) {
            if (r._concettoIdx === undefined || r._concettoIdx === null) return;
            var i = Number(r._concettoIdx);
            if (delta < 0 && i === pos) { r._concettoIdx = null; return; }
            r._concettoIdx = i >= pos ? i + delta : i;
        });
    }

    /* ---- gerarchia scritta a mano ---- */

    /** true se qualche voce ha $i come padre. */
    function haFigli(i) {
        return stato.elenco.some(function (c) { return c.p !== null && c.p !== undefined && Number(c.p) === i; });
    }

    /**
     * Il padre che spetta alla voce $i se la si fa rientrare: la prima voce
     * piena sopra di lei. Se quella e' gia' figlia, si diventa sue sorelle —
     * il sito ammette un livello solo, e un secondo livello che si appiattisce
     * da solo confonde piu' di un rientro rifiutato.
     */
    function padreDi(i) {
        for (var k = i - 1; k >= 0; k--) {
            var c = stato.elenco[k];
            if (c.t.trim() === '' || c.tolto) continue;
            return (c.p === null || c.p === undefined) ? k : Number(c.p);
        }
        return null;
    }

    function rientraConcetto(i) {
        var c = stato.elenco[i];
        if (c.p !== null && c.p !== undefined) {
            c.p = null;                       // torna al livello principale
        } else {
            if (haFigli(i)) return;           // un solo livello: chi ha figli non rientra
            var p = padreDi(i);
            if (p === null) return;
            c.p = p;
        }
        disegnaElenco();
    }

    /** Aggiunge una voce in posizione $pos (in fondo se $pos e' la lunghezza). */
    function elencoInserisci(pos, voce) {
        stato.elenco.splice(pos, 0, voce || { t: '', a: '', p: null, e: 0 });
        if (pos < stato.elenco.length - 1) elencoSposta(pos, 1);
    }

    /** Toglie la voce in posizione $pos. I suoi figli passano a $nuovoPadre. */
    function elencoTogli(pos, nuovoPadre) {
        stato.elenco.splice(pos, 1);
        elencoSposta(pos, -1, nuovoPadre);
    }

    function disegnaElenco() {
        var box = $('fcaiElenco');
        box.innerHTML = '';
        stato.elenco.forEach(function (c, i) {
            var riga = document.createElement('div');
            riga.className = 'fcai-voce' + (c.p !== null && c.p !== undefined ? ' is-figlio' : '')
                + (c.tolto ? ' is-tolto' : '');

            var num = stato.numeri[i];
            if (num !== undefined) {
                riga.innerHTML = '<button type="button" class="fcai-num" data-vai="' + num + '">' + num + '</button>';
            } else if (Object.keys(stato.numeri).length) {
                riga.innerHTML = '<span class="fcai-num is-vuoto">—</span>';
            }

            var campo = document.createElement('input');
            campo.type = 'text';
            campo.value = c.t;
            campo.addEventListener('input', function () { stato.elenco[i].t = campo.value; });
            /* Invio in fondo all'elenco = una voce nuova subito pronta: e' il
               modo piu' veloce di svuotarsi la testa senza staccare le mani. */
            campo.addEventListener('keydown', function (ev) {
                if (ev.key !== 'Enter') return;
                ev.preventDefault();
                elencoInserisci(i + 1);
                disegnaElenco();
                var campi = box.querySelectorAll('input');
                if (campi[i + 1]) campi[i + 1].focus();
            });
            riga.appendChild(campo);

            /* Fusione a due tocchi, come nell'abbinamento delle immagini:
               si arma una voce e si tocca la seconda. Nessun trascinamento, che
               su un elenco lungo costringerebbe a scorrere tenendo premuto. */
            var u = document.createElement('button');
            u.type = 'button';
            u.className = 'fcai-mini';
            u.title = T('fc.ibfai_unisci_titolo');
            u.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6"/><path d="M10.5 7.5 8 5a3.5 3.5 0 0 0-5 5l2.5 2.5"/><path d="m13.5 16.5 2.5 2.5a3.5 3.5 0 0 0 5-5L18.5 11.5"/></svg>';
            u.addEventListener('click', function () { unisciConcetto(i); });
            riga.appendChild(u);

            /* Rientro: rende questa voce un SOTTOCONCETTO di quella sopra, o
               la riporta al livello principale.
               Serviva e non c'era: chi scrive "disposizioni" e poi, sotto,
               "con ripetizione" e "senza ripetizione" si aspetta che le due
               finiscano appese alla prima — e finora non c'era nessun modo di
               dirlo, ne' trascinando ne' altrimenti. La gerarchia proposta
               dall'IA e' un'altra cosa: si accende dalle impostazioni, e
               questa qui vale comunque, anche a interruttore spento, perche'
               e' una scelta esplicita di chi scrive. */
            var rientro = document.createElement('button');
            rientro.type = 'button';
            rientro.className = 'fcai-mini fcai-rientro';
            var eFiglio = c.p !== null && c.p !== undefined;
            var bloccato = !eFiglio && (haFigli(i) || padreDi(i) === null);
            rientro.disabled = bloccato;
            rientro.title = T(eFiglio ? 'fc.ibfai_rientro_su_titolo'
                            : bloccato ? 'fc.ibfai_rientro_no_titolo' : 'fc.ibfai_rientro_giu_titolo');
            rientro.innerHTML = eFiglio
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 6H9"/><path d="M21 18H9"/><path d="M21 12h-9"/><path d="m7 9-3 3 3 3"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 6H9"/><path d="M21 18H9"/><path d="M21 12h-9"/><path d="m4 9 3 3-3 3"/></svg>';
            rientro.addEventListener('click', function () { rientraConcetto(i); });
            riga.appendChild(rientro);

            var piu = document.createElement('button');
            piu.type = 'button';
            piu.className = 'fcai-mini';
            piu.title = T('fc.ibfai_inserisci_sotto_titolo');
            piu.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>';
            piu.addEventListener('click', function () {
                elencoInserisci(i + 1);
                disegnaElenco();
                var campi = box.querySelectorAll('input');
                if (campi[i + 1]) campi[i + 1].focus();
            });
            riga.appendChild(piu);

            /* La X non cancella: sbarra. Un concetto tolto per sbaglio si
               rimette con un tocco, mentre riscriverlo a mano vuol dire
               ricordarsi come era scritto. Sparisce davvero solo al momento di
               generare, quando la scelta e' ormai confermata. */
            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'fcai-x';
            x.title = c.tolto ? T('fc.ibfai_rimetti') : T('fc.ibfai_togli');
            x.innerHTML = c.tolto
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
            x.addEventListener('click', function () {
                stato.elenco[i].tolto = !stato.elenco[i].tolto;
                disegnaElenco();
            });
            riga.appendChild(x);

            if (stato.daUnire === i) riga.classList.add('is-armata');

            box.appendChild(riga);
        });

        // una voce vuota in fondo, sempre pronta
        var ultima = stato.elenco[stato.elenco.length - 1];
        if (!stato.elenco.length || ultima.t !== '' || ultima.tolto) {
            var vuota = document.createElement('div');
            /* La riga per aggiungere a mano: e' un comando, non un concetto.
               Si marca, cosi' il filtro e i conteggi la lasciano fuori (e
               resta raggiungibile anche mentre si cerca). */
            vuota.className = 'fcai-voce is-aggiungi';
            vuota.innerHTML = '<input type="text" placeholder="' + esc(T('fc.ibfai_aggiungi_concetto_ph')) + '">';
            vuota.firstElementChild.addEventListener('focus', function () {
                stato.elenco.push({ t: '', a: '', p: null, e: 0 });
                disegnaElenco();
                var campi = box.querySelectorAll('input');
                campi[campi.length - 2].focus();
            });
            box.appendChild(vuota);
        }

        $('fcaiRisultato').hidden = stato.elenco.length === 0;
        aggiornaDensita();
        aggiornaRicerca();
        if (window.NS) {
            NS.entra(box.querySelectorAll('.fcai-voce:not(.is-aggiungi)'));
            NS.preme(box);
        }
    }

    /* ---- ricerca dentro l'elenco ----
       Su un documento lungo l'elenco arriva a centinaia di voci: senza un
       filtro, togliere il concetto sbagliato e' questione di scorrimento e
       fortuna. Si filtra cio' che e' gia' in pagina, senza chiedere niente al
       server: la risposta e' immediata perche' non c'e' rete di mezzo. */
    function aggiornaRicerca() {
        var riga = $('fcaiCercaRiga');
        if (!riga) return;
        var molti = stato.elenco.length > 30;
        riga.hidden = !molti;
        if (!molti) { if ($('fcaiCerca').value) filtraElenco(''); return; }
        if (!riga.__pronta && window.NS) {
            riga.__pronta = true;
            NS.ricerca($('fcaiCerca'), { onCerca: filtraElenco });
        }
        filtraElenco($('fcaiCerca').value.trim());
    }

    function filtraElenco(testo) {
        if (!window.NS) return;
        var voci = $('fcaiElenco').querySelectorAll('.fcai-voce:not(.is-aggiungi)');
        var visibili = NS.filtra(voci, testo, function (v) {
            var campo = v.querySelector('input');
            return campo ? campo.value : v.textContent;
        });
        $('fcaiCercaConta').textContent = testo === ''
            ? T('fc.ibfai_cerca_totale', { n: voci.length })
            : T('fc.ibfai_cerca_conta', { n: visibili, tot: voci.length });
        /* Niente risultati: si dice, invece di lasciare un vuoto che sembra un
           guasto. E si ricorda che i concetti nascosti verranno generati lo
           stesso: il filtro serve a guardare, non a scegliere. */
        NS.vuoto($('fcaiElenco'), visibili === 0 && testo !== '' ? T('fc.ibfai_cerca_niente', { q: testo }) : null);
    }

    /* "Piu' concetti" rilegge il documento, che per un file lungo supera
       quello che una chiamata singola puo' ricevere; "meno concetti" guarda al
       massimo MAX_DENSITA titoli. Oltre, i due pulsanti si spengono dicendo
       perche', invece di dare un risultato a meta'. */
    function aggiornaDensita() {
        var piu = $('fcaiPiu'), meno = $('fcaiMeno');
        if (!piu || !meno) return;
        var docLungo = (stato.testo || '').length > MAX_CARATTERI;
        var tanti = concettiVivi().length > MAX_DENSITA;
        piu.disabled = docLungo;
        piu.title = docLungo ? T('fc.ibfai_densita_doc_lungo') : '';
        meno.disabled = tanti;
        meno.title = tanti ? T('fc.ibfai_densita_troppi', { n: MAX_DENSITA }) : '';
    }

    /**
     * Primo tocco: arma. Secondo tocco su un'altra voce: le fonde.
     * La fusione la facciamo qui e non con una chiamata all'IA: l'utente ha
     * gia' deciso quali due, e chiedere a un modello di eseguire una decisione
     * gia' presa sarebbe token spesi per niente.
     */
    function attendi(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    async function unisciConcetto(i) {
        if (stato.daUnire === -1) { stato.daUnire = i; disegnaElenco(); return; }
        if (stato.daUnire === i) { stato.daUnire = -1; disegnaElenco(); return; }

        var a = Math.min(stato.daUnire, i), b = Math.max(stato.daUnire, i);
        var primo = stato.elenco[a], secondo = stato.elenco[b];
        var coda = ' e ' + secondo.t.charAt(0).toLowerCase() + secondo.t.slice(1);

        /* La seconda voce non sparisce: scivola dentro la prima. Senza il
           movimento sembra che una riga si sia persa, e l'occhio non collega
           quella sparizione al titolo che nel frattempo si e' allungato. */
        var righe = $('fcaiElenco').children;
        var ra = righe[a], rb = righe[b];
        if (ra && rb) {
            var dy = ra.getBoundingClientRect().top - rb.getBoundingClientRect().top;
            rb.classList.add('is-fondendo');
            rb.style.transform = 'translateY(' + dy + 'px) scale(.85)';
            await attendi(380);
        }

        var ancore = (primo.ancore || [primo.a]).concat(secondo.ancore || [secondo.a])
            .filter(function (x) { return x; });

        stato.elenco[a] = {
            t: primo.t,              // la coda si scrive dopo, un carattere per volta
            a: ancore[0] || '',
            ancore: ancore,          // il testo di entrambi finira' nell'estratto
            p: primo.p,
            e: primo.e || secondo.e  // se uno dei due voleva l'esempio, lo vuole anche l'unione
        };
        /* I figli del secondo passano al primo: e' dove e' finito il loro
           contenuto. E i numeri NON si buttano via — prima si azzeravano tutti,
           e dopo una fusione l'elenco perdeva il collegamento con le carte gia'
           generate. */
        elencoTogli(b, a);
        stato.daUnire = -1;
        disegnaElenco();

        // il titolo cresce sotto gli occhi, cosi' si vede DOVE e' finito il secondo
        var campo = $('fcaiElenco').querySelectorAll('input')[a];
        if (!campo) { stato.elenco[a].t = primo.t + coda; disegnaElenco(); return; }
        campo.focus();
        for (var k = 1; k <= coda.length; k++) {
            campo.value = primo.t + coda.slice(0, k);
            campo.scrollLeft = campo.scrollWidth;
            /* Veloce, e con un tetto: su un titolo lungo trenta caratteri a
               quaranta millesimi l'uno diventerebbe un'attesa, non un effetto. */
            await attendi(Math.max(8, Math.min(30, 600 / coda.length)));
        }
        stato.elenco[a].t = campo.value;
    }

    async function cambiaDensita(verso) {
        var vivi = concettiVivi();
        if (vivi.length < 2) return;
        $('fcaiStato').className = 'fcai-nota';
        $('fcaiStato').textContent = verso === 'piu' ? T('fc.ibfai_cerco_altri') : T('fc.ibfai_compatto');
        try {
            var r = await FCAI.densita(vivi, verso, stato.testo);
            stato.elenco = r.elenco;
            stato.numeri = {};
            disegnaElenco();
            $('fcaiStato').textContent = r.modificato
                ? T('fc.ibfai_n_concetti', {n: stato.elenco.length})
                : T('fc.ibfai_niente_da', {verbo: (verso === 'piu' ? T('fc.ibfai_aggiungere') : T('fc.ibfai_unire'))});
        } catch (e) { mostraErrore(e); }
    }

    /* ============================================================
       7. IA — generazione delle carte
       ============================================================ */

    /* Gli identificativi di riga devono essere unici in tutta la pagina: da
       loro nascono gli id degli editor (q-desc-12), e due righe con lo stesso
       numero farebbero scrivere l'una dentro l'altra. Si parte dall'orologio
       per non incrociare quelli che la pagina ha gia' assegnato. */
    var contatoreRid = Date.now() % 100000;
    function nuovoRid() { return ++contatoreRid; }

    /**
     * Marca sulla riga l'esempio generato dall'IA, che resta in attesa.
     *
     * PERCHE' NON SI SALVA SUBITO
     * fc_study_examples.parent_id punta a fc_study_cards.id: un esempio senza la sua carta
     * non e' sconsigliabile, e' impossibile. L'esempio quindi viaggia attaccato
     * all'elemento della riga, come fa gia' l'immagine non ancora caricata:
     * cosi' se l'utente cancella la riga prima di salvare, l'esempio sparisce
     * con lei invece di restare orfano in una cache che nessuno ripulisce.
     */
    function segnaEsempio(riga, testo) {
        riga.esempioIA = testo;
        var chip = riga.querySelector('.ex-chip');
        if (!chip) return;
        chip.style.background = 'var(--purple-soft)';
        chip.style.color = 'var(--purple)';
        chip.style.border = '1.5px solid var(--purple)';
        chip.innerHTML = chip.innerHTML.replace(/>[^<]*$/, '> ' + T('fc.ibfai_esempio_in_attesa'));
        /* L'attributo onclick del markup originale spiega che serve salvare
           prima: qui c'e' gia' un esempio pronto, quindi il messaggio cambia. */
        chip.onclick = async function () {
            /* Il testo salvato e' quello scritto dall'IA, coi tag del sito
               (/bold{}, $..$ eccetera): showConfirm mostra testo semplice, non
               lo interpreta. testoPiano() toglie i tag di formattazione, come
               gia' fa per l'anteprima di "Migliora con l'IA" — le formule LaTeX
               restano come sorgente leggibile, non renderizzate, ma almeno non
               compaiono piu' marcatori come /bold{...} in mezzo al testo. */
            var togli = await showConfirm(T('fc.ibfai_esempio_pronto_tit'), testoPiano(riga.esempioIA) + '\n\n' + T('fc.ibfai_esempio_pronto_testo'), T('fc.ibfai_togli_esempio'));
            if (togli) {
                riga.esempioIA = null;
                chip.style.background = ''; chip.style.color = ''; chip.style.border = '';
                chip.innerHTML = chip.innerHTML.replace(/>[^<]*$/, '> ' + T('fc.ibfai_esempi_label'));
                chip.onclick = null;
            }
        };
    }

    /**
     * Salva l'esempio rimasto in attesa su una carta appena scritta nel
     * database. Va chiamata da applicaMappa(), cioe' nel momento esatto in cui
     * la riga riceve il suo id vero.
     *
     * PERCHE' PASSA DA fc_examples_api.php E NON DAL SALVATAGGIO DELLE CARTE
     * E' la stessa strada che percorre un esempio scritto a mano dalla
     * finestra degli esempi: stesso controllo di proprieta', stesso limite di
     * frequenza, stessa colonna a seconda che la carta sia padre o figlia. Far
     * viaggiare l'esempio dentro fc_insert_save.php avrebbe voluto dire una
     * seconda strada per scrivere nella stessa tabella, con due punti da
     * tenere allineati per sempre.
     */
    async function salvaEsempio(riga, dbId, tipo) {
        if (!riga.esempioIA || !dbId) return;
        var fd = new FormData();
        fd.append('action', 'save');
        fd.append('fc_id', dbId);
        fd.append('fc_type', tipo === 'child' ? 'child' : 'parent');
        fd.append('text', riga.esempioIA);

        try {
            var r = await fetch('fc_examples_api.php', { method: 'POST', body: fd });
            var j = await r.json();
            if (!j || !j.success) throw new Error('rifiutato');
        } catch (e) {
            /* L'esempio resta attaccato alla riga: al prossimo salvataggio ci
               riprova. Perderlo in silenzio sarebbe il modo peggiore di
               fallire, perche' l'utente non saprebbe nemmeno cosa e' mancato. */
            showToast(T('fc.ibfai_esempio_non_salvato'), 'error');
            return;
        }

        delete riga.esempioIA;

        /* La pastiglia era stata riportata a zero da aggiornaExChip(): ora
           l'esempio c'e' davvero, e va contato. */
        var chip = riga.querySelector('.ex-chip');
        if (chip && typeof exChipHTML === 'function') {
            var cont = document.createElement('div');
            cont.innerHTML = exChipHTML(riga.dataset.rid, dbId, tipo === 'child', false, 1);
            if (cont.firstElementChild) chip.replaceWith(cont.firstElementChild);
        }
    }

    /** Inserisce una carta generata dall'IA e le attacca il tasto "rigenera". */
    function inserisciCartaIA(c, dopo) {
        /* Una carta figlia si inserisce SUBITO SOTTO il suo blocco: il
           salvataggio non legge nessun campo "padre", ricava la parentela
           dall'ordine delle righe e dalla classe is-child. Se il padre non c'e'
           ancora (il modello puo' indicare un indice che arrivera' in un lotto
           successivo) la carta nasce padre: meglio una gerarchia mancante che
           una carta persa.

           IL PADRE SI CERCA IN stato.elenco[c.i], NON IN c.p: lo schema di
           risposta di fc_ai.php (azione "carte") non ha mai avuto un campo "p"
           — il legame padre-figlio viaggia solo IN INGRESSO, per decidere cosa
           chiedere al modello, mai in uscita sulla carta generata. Leggere
           c.p direttamente lo trovava sempre undefined, e ogni carta finiva
           per nascere padre anche quando l'elenco la mostrava come figlia. */
        var concetto = stato.elenco[c.i];
        var pIdx = concetto ? concetto.p : (c.p !== undefined ? c.p : null);
        var padre = (pIdx !== null && pIdx !== undefined) ? stato.righe[pIdx] : null;
        var figlia = !!padre;
        if (padre && !dopo) {
            dopo = padre;
            while (dopo.nextElementSibling && dopo.nextElementSibling.classList.contains('is-child')) {
                dopo = dopo.nextElementSibling;
            }
        }
        var riga = appendRowHTML({ rid: nuovoRid(), q: c.q, a: c.a, isChild: figlia, dopo: dopo || null });
        stato.righe[c.i] = riga;
        /* Il legame con il concetto resta sulla riga: e' cio' che permette al
           tasto universale "Migliora con l'IA" (nel piede di OGNI riga, non
           solo di queste) di ritrovare il documento originale e la sua ancora
           quando si chiede di spiegare di nuovo proprio questa carta. */
        riga._concettoIdx = c.i;
        if (c.e) segnaEsempio(riga, c.e);
        return riga;
    }

    /* Il numero non si conta durante l'inserimento ma si rilegge dal DOM: le
       righe si possono spostare a mano, e un numero calcolato una volta sola
       resterebbe fermo mentre la carta si e' gia' spostata altrove. */
    function aggiornaNumeri() {
        var righe = $('fcRows').querySelectorAll('.fc-row');
        stato.numeri = {};
        Object.keys(stato.righe).forEach(function (k) {
            var pos = Array.prototype.indexOf.call(righe, stato.righe[k]);
            if (pos >= 0) stato.numeri[k] = pos + 1;
        });
        disegnaElenco();
    }

    /**
     * Sostituisce testo di una riga ESISTENTE, di qualunque origine — generata
     * dall'IA, scritta a mano, importata, gia' salvata nel database o ancora
     * no. La riga non viene "riempita" di nuovo: se ne crea una identica un
     * gradino sotto e si toglie la vecchia. Forzare il testo dentro un editor
     * gia' avviato vorrebbe dire conoscerne le viscere, mentre appendRowHTML e'
     * la strada che funziona di sicuro perche' e' quella che usa tutta la
     * pagina — ma qui, a differenza di una carta nuova di zecca, bisogna
     * PORTARE CON SE' tutto cio' che la riga vecchia gia' aveva: il suo id nel
     * database (o l'assenza di uno), lo stato di salvataggio, se e' figlia,
     * le immagini gia' scelte. Perderne anche solo uno di questi
     * trasformerebbe un "migliora il testo" in un "perdi l'immagine" o, peggio,
     * in una riga duplicata nel database al prossimo salvataggio.
     */
    function sostituisciContenutoRiga(rigaVecchia, nuovoQ, nuovoA) {
        var rid = rigaVecchia.dataset.rid;
        var isChild = rigaVecchia.classList.contains('is-child');
        var dbId = rigaVecchia.dataset.id || 0;
        // 'new' resta 'new' (non ancora salvata); qualunque altra cosa diventa
        // 'edited', perche' il contenuto e' appena cambiato
        var status = rigaVecchia.dataset.status === 'new' ? 'new' : 'edited';

        var qImgEl = document.getElementById('q-img-preview-' + rid);
        var aImgEl = document.getElementById('a-img-preview-' + rid);
        qImgEl = qImgEl ? qImgEl.querySelector('img') : null;
        aImgEl = aImgEl ? aImgEl.querySelector('img') : null;
        var qImgName = rigaVecchia.querySelector('.q-img-name');
        var aImgName = rigaVecchia.querySelector('.a-img-name');

        /* Anche l'audio gia' presente va riportato sulla riga nuova: prima
           non lo si faceva perche' appendRowHTML non sapeva riceverlo, e
           "Migliora con l'IA" su una carta con una registrazione la faceva
           sparire in silenzio. */
        var qAudioEl = rigaVecchia.querySelector('.audio-player-q');
        var aAudioEl = rigaVecchia.querySelector('.audio-player-a');

        var nuova = appendRowHTML({
            rid: nuovoRid(),
            q: nuovoQ, a: nuovoA,
            qImgUrl: qImgEl ? qImgEl.src : '', qImgName: qImgName ? qImgName.value : '',
            aImgUrl: aImgEl ? aImgEl.src : '', aImgName: aImgName ? aImgName.value : '',
            qAudioUrl: qAudioEl ? qAudioEl.getAttribute('src') || '' : '',
            aAudioUrl: aAudioEl ? aAudioEl.getAttribute('src') || '' : '',
            isChild: isChild, dopo: rigaVecchia, dbId: dbId, status: status
        });

        // immagini scelte ma non ancora caricate: sono proprieta' JS sulla riga,
        // spariscono col nodo se non le si trasporta a mano
        if (rigaVecchia.qNewImgFile) nuova.qNewImgFile = rigaVecchia.qNewImgFile;
        if (rigaVecchia.aNewImgFile) nuova.aNewImgFile = rigaVecchia.aNewImgFile;
        if (rigaVecchia.qAudioBlob) { nuova.qAudioBlob = rigaVecchia.qAudioBlob; nuova.qAudioExt = rigaVecchia.qAudioExt; }
        if (rigaVecchia.aAudioBlob) { nuova.aAudioBlob = rigaVecchia.aAudioBlob; nuova.aAudioExt = rigaVecchia.aAudioExt; }
        // un esempio dell'IA non ancora salvato aspetta anche lui il trasloco
        if (rigaVecchia.esempioIA) nuova.esempioIA = rigaVecchia.esempioIA;

        if (rigaVecchia._concettoIdx !== undefined) {
            nuova._concettoIdx = rigaVecchia._concettoIdx;
            stato.righe[rigaVecchia._concettoIdx] = nuova; // altrimenti l'elenco perderebbe il collegamento a questa carta
        }

        rigaVecchia.remove();
        markDirty();
        if (typeof updateGenerateButtonState === 'function') updateGenerateButtonState();
        aggiornaNumeri();
        return nuova;
    }

    async function rigeneraTutte() {
        $('fcaiRigeneraNota').value = '';
        $('fcaiRigeneraModale').hidden = false;
        FCAI.lockScroll();
    }

    function chiudiRigeneraModale() {
        $('fcaiRigeneraModale').hidden = true;
        FCAI.unlockScroll();
    }

    function confermaGenerazione(tabellaPiena) {
        stato.tabellaDaSovrascrivere = tabellaPiena;
        return apriModale(T('fc.ibfai_conferma_genera_tit'), T('fc.ibfai_conferma_genera_sub'), T('fc.ibfai_genera_bottone'), true);
    }

    async function generaCarte(giaConfermato, notaExtra) {
        /* Le voci vuote e quelle sbarrate si tolgono PRIMA di generare, cosi'
           l'indice di un concetto nell'elenco e' lo stesso che viaggia nel
           campo "i" delle carte. Togliendole, gli indici dei padri vanno
           rimappati: prima non lo si faceva, e un figlio dopo un concetto
           sbarrato puntava al padre sbagliato. */
        var mappa = {}, vivi = [];
        stato.elenco.forEach(function (c, i) {
            if (c.t.trim() !== '' && !c.tolto) { mappa[i] = vivi.length; vivi.push(c); }
        });
        stato.elenco = vivi.map(function (c) {
            var n = Object.assign({}, c);
            n.p = (c.p !== null && c.p !== undefined && mappa[c.p] !== undefined) ? mappa[c.p] : null;
            return n;
        });
        if (!stato.elenco.length) return;

        var m = mazzo();
        if (!m) { showToast(T('fc.ibf_serve_categoria'), 'error'); return; }
        if (FCAIJ.attivo() && !stato.jobRevisione) { showToast(T('fc.ibfai_job_gia_attivo'), 'error'); return; }

        var lista = $('fcRows');
        if (!giaConfermato) {
            var ok = await confermaGenerazione(lista.querySelectorAll('.fc-row').length > 0);
            if (!ok) return;
        }
        lista.innerHTML = '';
        stato.numeri = {};
        stato.righe = {};
        $('fcaiProgresso').textContent = '';

        /* Le ancore: quelle del motore (con il blocco del documento) se il
           concetto arriva da una revisione e non e' stato toccato da una
           fusione; altrimenti i testi, e il blocco lo ritrova il server. */
        var concetti = stato.elenco.map(function (c) {
            return {
                t: c.t,
                a: c.a || '',
                ancore: (c.ancoreJob && c.ancoreJob.length) ? c.ancoreJob : (c.ancore || (c.a ? [c.a] : [])),
                p: c.p,
                e: c.e ? 1 : 0
            };
        });

        /* LA GENERAZIONE PASSA SEMPRE DAL MOTORE DEI JOB. Prima era un ciclo
           nel browser, un lotto dopo l'altro: il primo lotto che falliva
           fermava tutti quelli dopo, e l'utente vedeva un errore dopo una
           lunga attesa. Ora ogni lotto e' un passo sul server, ritentato,
           diviso se troppo grande, e le carte arrivano comunque. */
        if (stato.jobRevisione) {
            var id = stato.jobRevisione;
            stato.jobRevisione = null;
            FCAIJ.conferma(id, concetti);
        } else {
            FCAIJ.avvia({ context_id: m, testo: stato.testo, concetti: concetti, nota: notaExtra || '', opzioni: opzioni() });
        }
    }

    /* ---- ganci per fc_ai_job.js ---- */

    /* Il testo piano di una domanda, per riconoscere una carta gia' in
       tabella: dopo una ripresa il server rimanda tutte le carte del job, e
       quelle gia' salvate (caricate dal database in modifica) non vanno
       inserite una seconda volta. */
    function chiaveDomanda(raw) {
        return testoPiano(raw || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function suCarte(carte, info) {
        var primaDi = $('fcRows').querySelectorAll('.fc-row').length;
        /* Dopo una ripresa l'elenco dei concetti non c'e' piu' (la pagina si
           e' ricaricata): lo si ricostruisce da quello del job, che serve a
           numerare le carte e a ritrovare i padri. */
        if (info.ripresa && info.job.concetti && !stato.elenco.length) {
            stato.elenco = info.job.concetti.map(function (c) {
                return { t: c.t, a: '', p: (c.p === undefined ? null : c.p), e: c.e || 0 };
            });
        }
        if (info.ripresa) {
            var gia = {};
            $('fcRows').querySelectorAll('.fc-row').forEach(function (r) {
                gia[chiaveDomanda(leggiCampo(r.dataset.rid, 'q'))] = true;
            });
            carte = carte.filter(function (c) { return !gia[chiaveDomanda(c.q)]; });
        }
        carte.forEach(function (c) { inserisciCartaIA(c); });
        if (window.NS && carte.length) {
            // solo le righe appena arrivate, non tutta la tabella
            NS.entra(Array.prototype.slice.call($('fcRows').querySelectorAll('.fc-row'), primaDi));
        }
        if (carte.length) {
            markDirty();
            aggiornaNumeri();
            if (typeof updateGenerateButtonState === 'function') updateGenerateButtonState();
        }
    }

    /** L'estrazione di un documento lungo e' finita: l'elenco torna qui, da rivedere. */
    function suRevisione(concetti, job) {
        stato.elenco = concetti.map(function (c) {
            var anc = c.ancore || [];
            return {
                t: c.t,
                a: anc.length ? anc[0].a : '',
                ancore: anc.map(function (x) { return x.a; }),  // la forma che usa la fusione
                ancoreJob: anc,                                   // la forma che capisce il server
                p: (c.p === undefined ? null : c.p),
                e: c.e || 0
            };
        });
        stato.jobRevisione = job.id;
        stato.numeri = {};
        disegnaElenco();
        $('fcaiStato').className = 'fcai-nota';
        $('fcaiStato').textContent = T('fc.ibfai_job_revisione', { n: stato.elenco.length });
        /* apri() e' un interruttore: su una sezione gia' aperta la chiude. Qui
           serve solo aprirla se e' chiusa, altrimenti l'elenco appena arrivato
           sparirebbe proprio mentre gli si dice di rivederlo. */
        var sez = $('fcaiSecIa');
        if (sez && !sez.classList.contains('is-open')) apri('ia');
        var box = $('fcaiRisultato');
        if (box && box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function suFine(job, conti) {
        $('fcaiRigeneraTutte').hidden = false;
        $('fcaiProgresso').textContent = conti.mancanti
            ? T('fc.ibfai_scoperti', { n: conti.mancanti })
            : T('fc.ibfai_carte_generate', { n: conti.ricevute });
        if (typeof updateGenerateButtonState === 'function') updateGenerateButtonState();
    }

    function suBlocco(attivo, revisione) {
        $('fcaiBtnConcetti').disabled = attivo;
        $('fcaiBtnCarte').disabled = attivo && !revisione;
        $('fcaiRigeneraTutte').disabled = attivo;
    }

    /* ============================================================
       9. MIGLIORA CON L'IA — su qualunque riga, anche vuota
       ============================================================
       Il pulsante vive nel piede di OGNI riga (aggiunto direttamente in
       fc_insert.php, dentro appendRowHTML: e' l'unico punto in cui
       nasce una riga, quindi e' l'unico punto che serve toccare per averlo
       ovunque — comprese le righe scritte a mano, importate, o gia' salvate
       da prima che questo pannello esistesse). */

    /* Legge il testo attuale di un campo (domanda o risposta) di una riga,
       con lo stesso ripiego che usa il salvataggio: se il campo nascosto e'
       vuoto perche' la sincronizzazione non e' ancora passata, si legge
       direttamente dall'editor visibile. */
    function leggiCampo(rid, prefix) {
        var campo = document.getElementById(prefix + '-desc-' + rid);
        var v = campo ? campo.value : '';
        if (!v) {
            var ed = document.getElementById(prefix + '-desc-' + rid + '_editor');
            if (ed) v = ed.value || ed.innerText || '';
        }
        return v;
    }

    /* Il valore salvato e' il documento di fc_parser.js (JSON a run, o il
       vecchio formato a tag: fcdParse riconosce da solo quale dei due). Per
       mandarlo all'IA basta il testo, non la formattazione — la scriverà lei
       di nuovo, da zero, come fa gia' per ogni carta generata. Se fcdParse non
       e' disponibile per qualche motivo, si manda il valore grezzo: peggio
       formattato, ma non si perde la richiesta. */
    function testoPiano(raw) {
        if (!raw) return '';
        if (typeof fcdParse !== 'function' || typeof fcdBlockText !== 'function') return String(raw);
        try {
            var doc = fcdParse(raw);
            return (doc.blocks || []).map(function (b) { return fcdBlockText(b); }).join('\n').trim();
        } catch (e) {
            return String(raw);
        }
    }

    function tronca(s, n) { return s.length > n ? s.slice(0, n) + '…' : s; }

    var miglioraRiga = null; // la riga su cui sta lavorando il popup, mentre e' aperto

    /**
     * Il popup e' UNO SOLO, ma cambia faccia secondo cosa c'e' gia' scritto:
     *   - risposta e domanda entrambe vuote -> serve un'indicazione per sapere
     *     di cosa scrivere, non c'e' niente da cui partire;
     *   - domanda scritta, risposta vuota -> propone di scrivere la risposta
     *     usando la domanda come traccia, le indicazioni sono un di piu';
     *   - risposta gia' scritta -> chiede cosa cambiare, con delle pastiglie
     *     rapide per le richieste piu' comuni oltre al testo libero.
     * Un solo meccanismo IA sulla carta, non due bottoni diversi da capire
     * quale usare quando.
     */
    function apriMigliora(pulsante) {
        var riga = pulsante.closest('.fc-row');
        if (!riga) return;
        miglioraRiga = riga;

        var q = testoPiano(leggiCampo(riga.dataset.rid, 'q'));
        var a = testoPiano(leggiCampo(riga.dataset.rid, 'a'));
        var rispostaVuota = a.trim() === '';
        var domandaVuota = q.trim() === '';
        var stadio = rispostaVuota ? (domandaVuota ? 'vuota' : 'genera') : 'modifica';

        var anteprima = $('fcaiMiglioraAnteprima');
        anteprima.hidden = domandaVuota && rispostaVuota;
        anteprima.textContent = anteprima.hidden ? '' :
            (q ? T('fc.ibfai_prefisso_domanda') + tronca(q, 140) : '') + (a ? '\n' + T('fc.ibfai_prefisso_risposta') + tronca(a, 140) : '');

        $('fcaiMiglioraTit').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_titolo_modifica')
            : stadio === 'genera' ? T('fc.ibfai_migliora_titolo_genera')
            : T('fc.ibfai_migliora_titolo_vuota');
        $('fcaiMiglioraSub').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_sub_modifica')
            : stadio === 'genera' ? T('fc.ibfai_migliora_sub_genera')
            : T('fc.ibfai_migliora_sub_vuota');
        $('fcaiMiglioraChips').hidden = stadio !== 'modifica';
        document.querySelectorAll('#fcaiMiglioraChips .fcai-chip-mini').forEach(function (c) { c.classList.remove('is-on'); });

        $('fcaiMiglioraNota').value = '';
        $('fcaiMiglioraNota').placeholder = stadio === 'vuota' ? T('fc.ibfai_migliora_nota_ph_vuota')
            : stadio === 'genera' ? T('fc.ibfai_migliora_nota_ph_genera')
            : T('fc.ibfai_migliora_nota_ph_default');
        $('fcaiMiglioraSi').textContent = stadio === 'modifica' ? T('fc.ibfai_migliora_bottone') : T('fc.ibfai_genera_bottone');

        $('fcaiMiglioraEsempio').checked = false;
        $('fcaiMiglioraErrore').hidden = true;

        $('fcaiMigliora').hidden = false;
        FCAI.lockScroll();
    }

    function chiudiMigliora() {
        $('fcaiMigliora').hidden = true;
        FCAI.unlockScroll();
        miglioraRiga = null;
    }

    function mostraErroreMigliora(e) {
        var box = $('fcaiMiglioraErrore');
        box.hidden = false;
        if (e && e.codice === 'limite_piano') {
            box.textContent = T('fc.ibfai_limite_dettaglio_breve', {stima: e.stima, disponibili: (e.disponibili === null ? '∞' : e.disponibili)});
        } else if (e && e.codice === 'serve_indicazione') {
            box.textContent = T('fc.ibfai_serve_indicazione');
        } else {
            box.textContent = T('fc.ibfai_errore_ia');
        }
    }

    async function confermaMigliora() {
        var riga = miglioraRiga;
        if (!riga) return;

        var q = testoPiano(leggiCampo(riga.dataset.rid, 'q'));
        var a = testoPiano(leggiCampo(riga.dataset.rid, 'a'));
        var nota = $('fcaiMiglioraNota').value.trim();
        var esempio = $('fcaiMiglioraEsempio').checked;

        if (q.trim() === '' && a.trim() === '' && nota === '') {
            mostraErroreMigliora({ codice: 'serve_indicazione' });
            return;
        }

        /* Se la carta viene da un concetto dell'elenco IA ancora presente in
           pagina, si riusa anche il pezzo di documento da cui e' nata: la
           stessa richiesta "spiegala diversamente" riesce meglio se l'IA puo'
           tornare a guardare da dove viene il concetto, non solo cio' che ha
           gia' scritto lei in precedenza. Su una carta scritta a mano o
           importata questo semplicemente non c'e', e va bene lo stesso. */
        var estratto = '';
        if (riga._concettoIdx !== undefined && stato.testo && stato.elenco[riga._concettoIdx]) {
            var idx = FCAI.indice(stato.testo);
            estratto = FCAI.estrattoPer(idx, [stato.elenco[riga._concettoIdx]], 700);
        }

        var btn = $('fcaiMiglioraSi');
        var testoBtn = btn.textContent;
        btn.disabled = true;
        btn.textContent = T('fc.ibfai_un_momento');
        $('fcaiMiglioraErrore').hidden = true;

        try {
            var carta = await FCAI.migliora({
                domanda: q, risposta: a, nota: nota, estratto: estratto,
                // lo stesso stile e lo stesso impegno delle carte generate: una
                // carta migliorata non deve uscire scritta in un altro modo
                opzioni: { esempi: esempio, stile: leggiStile(), impegno: impegnoScelto() }, lingua: 'italiano'
            });
            var nuova = sostituisciContenutoRiga(riga, carta.q, carta.a);
            if (carta.e) segnaEsempio(nuova, carta.e);
            chiudiMigliora();
            nuova.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (e) {
            mostraErroreMigliora(e);
        } finally {
            btn.disabled = false;
            btn.textContent = testoBtn;
        }
    }

    /* ============================================================
       10. AGGANCI
       ============================================================ */

    function avvia() {
        if (!$('fcaiPanel')) return;

        document.querySelectorAll('#fcaiPanel [data-apri]').forEach(function (b) {
            b.addEventListener('click', function () { apri(b.getAttribute('data-apri')); });
        });

        document.querySelectorAll('#fcaiFonti .fcai-chip').forEach(function (b) {
            b.addEventListener('click', function () {
                stato.fonte = b.getAttribute('data-fonte');
                document.querySelectorAll('#fcaiFonti .fcai-chip').forEach(function (x) {
                    x.classList.toggle('is-on', x === b);
                });
                document.querySelectorAll('[data-fonte-pan]').forEach(function (p) {
                    p.hidden = p.getAttribute('data-fonte-pan') !== stato.fonte;
                });
            });
        });

        document.querySelectorAll('#fcaiModiImport .fcai-chip').forEach(function (b) {
            b.addEventListener('click', function () {
                stato.modoImport = b.getAttribute('data-modo');
                document.querySelectorAll('#fcaiModiImport .fcai-chip').forEach(function (x) {
                    x.classList.toggle('is-on', x === b);
                });
                document.querySelectorAll('[data-modo-pan]').forEach(function (p) {
                    p.hidden = p.getAttribute('data-modo-pan') !== stato.modoImport;
                });
            });
        });

        $('fcaiFile').addEventListener('change', function () { scegliFile(this.files[0]); });

        var drop = $('fcaiDrop');
        ['dragenter', 'dragover'].forEach(function (e) {
            drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('is-hover'); });
        });
        ['dragleave', 'drop'].forEach(function (e) {
            drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('is-hover'); });
        });
        drop.addEventListener('drop', function (ev) {
            if (ev.dataTransfer.files.length) scegliFile(ev.dataTransfer.files[0]);
        });

        $('format_switch').addEventListener('change', function () {
            $('custom_separators_row').style.display = this.checked ? 'flex' : 'none';
            $('import_text').placeholder = this.checked
                ? T('fc.ibfai_incolla_separatori_ph')
                : T(VOCAB ? 'fcv.import_text_ph' : 'fc.ibfai_import_text_ph');
        });

        $('question_images').addEventListener('change', notaImmagini);
        $('answer_images').addEventListener('change', notaImmagini);

        $('fcaiBtnImmagini').addEventListener('click', daImmagini);
        $('fcaiBtnImporta').addEventListener('click', importa);
        $('fcaiBtnConcetti').addEventListener('click', trovaConcetti);
        $('fcaiApriImpostazioni').addEventListener('click', function () {
            apriModale(T('fc.ibfai_impostazioni'), T('fc.ibfai_modale_impostazioni_sub'), T('fc.ibfai_fatto'), false);
        });
        $('fcaiModaleNo').addEventListener('click', function () { chiudiModale(false); });
        $('fcaiModaleSi').addEventListener('click', function () { chiudiModale(true); });
        $('fcaiModale').addEventListener('click', function (ev) {
            if (ev.target === $('fcaiModale')) chiudiModale(false);
        });
        document.querySelectorAll('#fcaiModale input[type="checkbox"]').forEach(function (c) {
            c.addEventListener('change', function () {
                aggiornaDipendenze();
                if (!$('fcaiModaleConto').hidden) aggiornaConto();
            });
        });
        aggiornaDipendenze();

        $('fcaiMiglioraNo').addEventListener('click', chiudiMigliora);
        $('fcaiMiglioraSi').addEventListener('click', confermaMigliora);
        $('fcaiMigliora').addEventListener('click', function (ev) {
            if (ev.target === $('fcaiMigliora')) chiudiMigliora();
        });
        document.querySelectorAll('#fcaiMiglioraChips .fcai-chip-mini').forEach(function (chip) {
            chip.addEventListener('click', function () {
                $('fcaiMiglioraNota').value = chip.getAttribute('data-nota');
                document.querySelectorAll('#fcaiMiglioraChips .fcai-chip-mini').forEach(function (c) { c.classList.remove('is-on'); });
                chip.classList.add('is-on');
            });
        });

        $('fcaiBtnCarte').addEventListener('click', function () { generaCarte(false); });
        $('fcaiRigeneraTutte').addEventListener('click', rigeneraTutte);
        $('fcaiRigeneraNo').addEventListener('click', chiudiRigeneraModale);
        $('fcaiRigeneraModale').addEventListener('click', function (ev) {
            if (ev.target === $('fcaiRigeneraModale')) chiudiRigeneraModale();
        });
        $('fcaiRigeneraSi').addEventListener('click', function () {
            var nota = $('fcaiRigeneraNota').value.trim();
            chiudiRigeneraModale();
            generaCarte(true, nota);
        });
        $('fcaiMeno').addEventListener('click', function () { cambiaDensita('meno'); });
        $('fcaiPiu').addEventListener('click', function () { cambiaDensita('piu'); });

        if ($('fcaiApriCrediti')) $('fcaiApriCrediti').addEventListener('click', function () { apriCrediti(); });
        if ($('fcaiCreditiChiudi')) $('fcaiCreditiChiudi').addEventListener('click', chiudiCrediti);
        if ($('fcaiCreditiModale')) $('fcaiCreditiModale').addEventListener('click', function (ev) {
            if (ev.target === $('fcaiCreditiModale')) chiudiCrediti();
        });

        /* Il numero della carta porta alla carta: e' il controllo che l'utente
           fa davvero, cioe' "questo concetto e' stato trattato?". */
        $('fcaiElenco').addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-vai]');
            if (!b) return;
            var righe = $('fcRows').querySelectorAll('.fc-row');
            var r = righe[parseInt(b.getAttribute('data-vai'), 10) - 1];
            if (r) r.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        $('fcaiCopiaPrompt').addEventListener('click', function (ev) {
            ev.preventDefault();
            var p = typeof updateAIPrompt === 'function' && $('ai_prompt_textarea')
                ? (updateAIPrompt(), $('ai_prompt_textarea').value)
                : T('fc.ibfai_prompt_fallback');
            navigator.clipboard.writeText(p).then(function () { showToast(T('fc.ibfai_copiato')); });
        });

        /* Le indicazioni si ricordano fra una visita e l'altra: chi ha scritto
           una volta "voglio le risposte come elenco descrizione - codice" non
           deve riscriverlo per ogni mazzo. Restano nel browser, non sul
           server: sono una comodita' di chi scrive, non un dato del mazzo. */
        var ind = $('fcaiIndicazioni');
        if (ind) {
            try { if (!ind.value) ind.value = localStorage.getItem('fcai_indicazioni') || ''; } catch (e) {}
            ind.addEventListener('input', function () {
                try { localStorage.setItem('fcai_indicazioni', ind.value); } catch (e) {}
            });
        }

        if (window.NS && NS.tendina) {
            tendinaImpegno = NS.tendina($('fcaiImpegno'), {
                valore: 'medio',
                onCambia: function (v) { scegliImpegno(v); stileCambiato(true); }
            });
        }
        if (!tendinaImpegno) {
            // ripiego senza standout_ui.js: le voci restano quattro pulsanti
            document.querySelectorAll('#fcaiImpegno [data-impegno]').forEach(function (b) {
                b.addEventListener('click', function () { scegliImpegno(b.getAttribute('data-impegno')); stileCambiato(true); });
            });
        }
        document.querySelectorAll('#fcaiStile select').forEach(function (sel) {
            sel.addEventListener('change', function () { stileCambiato(true); });
        });
        ['fcaiStileEtichette', 'fcaiModelloQ', 'fcaiModelloA'].forEach(function (id) {
            if ($(id)) $(id).addEventListener('input', function () { stileCambiato(false); });
        });
        if ($('fcaiStileAzzera')) $('fcaiStileAzzera').addEventListener('click', function () {
            applicaStile({ stile: {}, impegno: 'medio' });
            stileCambiato(true);
        });
        caricaStile();
        $('fcaiRiepilogo').textContent = riepilogo();

        // la risposta al dito su tutti i comandi del pannello, non solo sull'elenco
        if (window.NS) NS.preme(document);

        if (window.FCAIJ) {
            FCAIJ.init({
                deck: mazzo,
                modo: typeof MODO !== 'undefined' ? MODO : 'insert',
                onCarte: suCarte,
                onRevisione: suRevisione,
                onFine: suFine,
                onBlocco: suBlocco
            });
            FCAIJ.riprendiAllAvvio();
        }

        // Nessuna sezione si apre da sola all'avvio: le tre partono tutte
        // compatte, e chi arriva sceglie da quale cominciare. Un tempo la
        // sezione IA si apriva sempre, ma su una pagina che si visita spesso
        // solo per aggiungere una riga a mano era un ingombro ripetuto ogni
        // volta, non un aiuto.
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
    else avvia();

    return { apri: apri, inserisci: fcaiInserisci, salvaEsempio: salvaEsempio, apriMigliora: apriMigliora, stato: stato };
})();
