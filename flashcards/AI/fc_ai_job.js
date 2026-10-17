/* fc_ai_job.js
   ================================================================
   La generazione lunga, vista dal browser.

   Il lavoro vero lo fa il server (ai_jobs.php), un passo alla volta. Qui ci
   sono tre cose:

     1. IL CICLO. Finche' siamo su Altervista, senza cron, e' questa pagina a
        far avanzare il lavoro: chiede "avanza" e riceve lo stato aggiornato
        con le carte nuove. Appena un passo e' fatto richiede subito il
        successivo; quando non c'e' niente da fare aspetta qualche secondo.
        Dopo il trasloco sul VPS lo fara' un cron, e questo ciclo si limitera'
        a guardare.

     2. LA SCHERMATA. Blocca un secondo invio (lo blocca anche il server, ma
        un pulsante che risponde "no" dopo un clic e' peggio di un pulsante
        spento), dice a che punto e', quanto manca, e cosa succede se si
        chiude la pagina. Si riduce a una pastiglia, per continuare a lavorare
        sulle carte gia' arrivate.

     3. LA RIPRESA. Riaprendo il mazzo, un job vivo riparte da dove era.

   Il pannello (fc_ai_pannello.js) resta padrone della tabella e dell'elenco
   dei concetti: questo file non tocca mai la pagina direttamente, chiama i
   ganci che il pannello gli passa in init().
   ================================================================ */

var FCAIJ = (function () {
    'use strict';

    var g = {};               // i ganci del pannello
    var job = null;           // l'ultimo stato ricevuto
    var dopo = 0;             // cursore: id dell'ultimo passo di carte gia' ricevuto
    var inCorsa = false;      // un ciclo solo per pagina
    var ridotta = false;
    var ricevute = 0;         // carte arrivate in questa sessione di lavoro
    var mancanti = 0;
    var totaleCarte = 0;
    var ripresa = false;      // la prima tornata dopo una ripresa porta carte forse gia' in tabella
    var mediaPasso = null;    // durata media di un passo, per la stima del tempo

    function $(id) { return document.getElementById(id); }
    function attendi(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function num(n) { return Number(n || 0).toLocaleString(document.documentElement.lang || 'it'); }

    /* ============================================================
       1. DIALOGO CON ai_job_api.php
       ============================================================
       nisama_fetch.js aggiunge da se' il token CSRF. Il limite di 150 secondi
       e' largo apposta: un passo sta dentro il tempo massimo di PHP, ma su un
       hosting lento la risposta puo' arrivare dopo un minuto buono, e
       interromperla vorrebbe dire rifare (e ripagare) un passo gia' fatto. */
    async function api(azione, dati) {
        var ac = window.AbortController ? new AbortController() : null;
        var tid = ac ? setTimeout(function () { ac.abort(); }, 150000) : null;
        var r;
        try {
            r = await fetch('ai_job_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                signal: ac ? ac.signal : undefined,
                body: JSON.stringify(Object.assign({ azione: azione }, dati || {}))
            });
        } catch (e) {
            throw { codice: 'rete' };
        } finally {
            if (tid) clearTimeout(tid);
        }
        var j = null;
        try { j = await r.json(); } catch (e) { /* risposta non JSON: il server e' caduto a meta' */ }
        if (!j) throw { codice: 'rete', http: r.status };
        if (!j.ok) throw Object.assign({ codice: j.error || 'errore' }, j);
        return j;
    }

    /* Un identificativo per il clic su "Genera": se la richiesta di
       creazione arriva due volte (doppio clic, rete che ritenta) il server
       restituisce lo stesso job invece di crearne un secondo. */
    function nuovoIdRichiesta() {
        var b = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(b);
        return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    /** Il messaggio giusto per un errore, qualunque sia la strada da cui arriva. */
    function messaggio(e) {
        var c = (e && e.codice) || '';
        var m = {
            job_attivo:         'fc.ibfai_job_gia_attivo',
            mazzo_non_trovato:  'fc.ibf_serve_categoria',
            testo_mancante:     'fc.ibfai_vuoto',
            elenco_mancante:    'fc.ibfai_vuoto',
            argomento_mancante: 'fc.ibfai_vuoto',
            concetti_mancanti:  'fc.ibfai_vuoto',
            rete:               'fc.ibfai_err_rete',
            ia_quota:           'fc.ibfai_err_quota',
            ia_sovraccarico:    'fc.ibfai_err_sovraccarico',
            ia_lento:           'fc.ibfai_err_lento',
            ia_tempo:           'fc.ibfai_err_lento',
            ia_filtro:          'fc.ibfai_err_filtro',
            ia_troncata:        'fc.ibfai_err_troncata',
            ia_chiave:          'fc.ibfai_err_nostro',
            ia_richiesta:       'fc.ibfai_err_nostro'
        }[c];
        if (c === 'limite_piano') {
            return T('fc.ibfai_limite_dettaglio', {
                stima: num(e.stima), disponibili: (e.disponibili === null || e.disponibili === undefined ? '∞' : num(e.disponibili))
            });
        }
        return T(m || 'fc.ibfai_errore_ia');
    }

    /* ============================================================
       2. LA SCHERMATA
       ============================================================ */

    function apriSchermata() {
        ridotta = false;
        $('fcajModale').hidden = false;
        $('fcajPillola').hidden = true;
        if (window.FCAI && FCAI.lockScroll) FCAI.lockScroll();
    }

    function chiudiSchermata() {
        $('fcajBarra').classList.remove('is-viva');   // chiuso = fermo: via il luccichio
        if (!$('fcajModale').hidden && window.FCAI && FCAI.unlockScroll) FCAI.unlockScroll();
        $('fcajModale').hidden = true;
        $('fcajPillola').hidden = true;
        ridotta = false;
    }

    function riduci() {
        if (window.FCAI && FCAI.unlockScroll) FCAI.unlockScroll();
        $('fcajModale').hidden = true;
        $('fcajPillola').hidden = false;
        ridotta = true;
        disegnaPillola();
    }

    function bottoni(quali) {
        ['fcajRiduci', 'fcajAnnulla', 'fcajRiprendi', 'fcajRiprova', 'fcajChiudi'].forEach(function (id) {
            $(id).hidden = quali.indexOf(id) === -1;
        });
    }

    function percentuale() {
        if (!job || !job.passi_totali) return 0;
        return Math.min(100, Math.round(job.passi_fatti / job.passi_totali * 100));
    }

    /**
     * true quando non c'e' nessuna percentuale onesta da mostrare: il server
     * non ha ancora risposto, oppure nessun passo e' finito e quindi la barra
     * starebbe a zero.
     *
     * E' il caso normale di una generazione corta — otto concetti da un
     * elenco: il lavoro e' uno o due pezzi, e finche' il primo non torna
     * indietro non c'e' proprio niente di misurato. Mostrare "0%" e "0 di 8"
     * fermi per mezzo minuto fa sembrare che il programma sia bloccato,
     * mentre sta lavorando: al posto della percentuale va detto che sta
     * lavorando, e questo lo fa la barra che scorre.
     */
    function senzaPercentuale() {
        return !job || !job.passi_totali || job.passi_fatti === 0;
    }

    /* Stima del tempo dai passi misurati in questa pagina, non da una tabella
       fissa: dipende dal documento, dall'impegno scelto e da quanto e' carico
       Gemini in quel momento. Prima di due passi misurati non si dice niente —
       un numero inventato che poi raddoppia e' peggio di nessun numero. */
    function testoTempo() {
        if (!job || mediaPasso === null) return '';
        var restano = Math.max(0, job.passi_totali - job.passi_fatti);
        if (!restano) return '';
        var sec = Math.round(restano * mediaPasso / 1000);
        return sec < 60 ? T('fc.ibfai_job_tempo_poco') : T('fc.ibfai_job_tempo_min', { n: Math.ceil(sec / 60) });
    }

    function disegna(nota) {
        if (!job) return;
        /* Prima che il server abbia risposto il job non ha ancora passi: "parte
           0 di 0" sarebbe vero ma sembrerebbe un errore. Si tace finche' non
           c'e' un numero da dire. */
        /* Finche' non e' finito nessun passo, il conteggio "0 di 8" e' vero ma
           non dice niente: al suo posto si dice che cosa sta facendo l'IA in
           questo momento. Appena il primo pezzo torna, si passa ai numeri, che
           da li' in poi si muovono davvero. */
        var ignota = senzaPercentuale();
        var fase = !job.passi_totali
            ? ''   /* il server non ha ancora risposto: lo dice gia' la nota sotto */
            : job.fase === 'concetti'
            ? (ignota ? T('fc.ibfai_job_fase_concetti_avvio', { tot: job.passi_totali })
                      : T('fc.ibfai_job_fase_concetti', { fatti: job.passi_fatti, tot: job.passi_totali }))
            : (ignota ? T('fc.ibfai_job_fase_carte_avvio')
                      : T('fc.ibfai_job_fase_carte', { fatte: num(ricevute), tot: num(totaleCarte || ricevute) }));

        $('fcajTit').textContent = T('fc.ibfai_job_tit');
        $('fcajFase').textContent = fase;
        /* Il movimento della barra dipende dallo STATO DEL LAVORO, non dal
           fatto che ci sia una nota da leggere: "Preparo il lavoro…" e "la
           connessione e' instabile, sto riprovando" sono momenti in cui il
           lavoro sta andando avanti, e fermare la barra li' farebbe sembrare
           fermo anche lui. Si ferma quando e' fermo davvero: in pausa, finito
           o sospeso. */
        var avanza = job.stato === 'queued' || job.stato === 'running' || !job.id;
        $('fcajBarra').firstElementChild.style.width = ignota ? '' : percentuale() + '%';
        $('fcajBarra').classList.remove('is-over');
        // il segmento che scorre finche' non c'e' una percentuale da mostrare
        $('fcajBarra').classList.toggle('is-ignota', ignota && avanza);
        // luccica finche' il lavoro va avanti: ferma sembrerebbe bloccata
        $('fcajBarra').classList.toggle('is-viva', !ignota && avanza);
        $('fcajTempo').textContent = testoTempo();
        /* "0 crediti usati su circa 0" non e' un'informazione: e' il preventivo
           che non e' ancora arrivato. Finche' non c'e', la riga non c'e'. */
        $('fcajCrediti').textContent = job.crediti_stimati
            ? T('fc.ibfai_job_crediti', { usati: num(job.crediti_usati), stimati: num(job.crediti_stimati) })
            : '';

        var n = $('fcajNota');
        n.className = 'fcai-modale-conto';
        n.textContent = nota || T('fc.ibfai_job_nota_chiudi');
        bottoni(['fcajRiduci', 'fcajAnnulla']);
        disegnaPillola();
    }

    function disegnaPillola() {
        if (!job) return;
        var ignota = senzaPercentuale();
        $('fcajPillolaTesto').textContent = ignota
            ? T('fc.ibfai_job_pillola_avvio')
            : job.fase === 'concetti'
            ? T('fc.ibfai_job_pillola_concetti', { p: percentuale() })
            : T('fc.ibfai_job_pillola_carte', { fatte: num(ricevute), tot: num(totaleCarte || ricevute) });
        $('fcajPillola').classList.toggle('is-ignota', ignota);
        $('fcajPillola').querySelector('i').style.width = ignota ? '' : percentuale() + '%';
    }

    /** Una fermata che chiede all'utente di scegliere: il riquadro resta aperto. */
    function fermata(titolo, testo, quali, avviso) {
        apriSchermata();
        $('fcajTit').textContent = titolo;
        $('fcajFase').textContent = '';
        $('fcajTempo').textContent = '';
        $('fcajBarra').firstElementChild.style.width = percentuale() + '%';
        $('fcajBarra').classList.remove('is-viva');   // fermo: niente luccichio
        var n = $('fcajNota');
        n.className = 'fcai-modale-conto' + (avviso ? ' is-warn' : '');
        n.textContent = testo;
        bottoni(quali);
    }

    /* ============================================================
       3. IL CICLO
       ============================================================ */

    /** Riceve uno stato dal server e lo porta nella pagina. */
    function applica(nuovo) {
        job = nuovo;
        if (job.concetti && job.fase === 'carte') totaleCarte = job.concetti.length;

        if (job.carte && job.carte.length) {
            ricevute += job.carte.length;
            if (g.onCarte) g.onCarte(job.carte, { ripresa: ripresa, job: job });
        }
        if (job.mancanti) mancanti += job.mancanti.length;
        ripresa = false;
        if (typeof job.cursore === 'number') dopo = job.cursore;
    }

    async function ciclo() {
        if (inCorsa) return;
        inCorsa = true;
        blocca(true);
        var erroriRete = 0;

        while (job && (job.stato === 'queued' || job.stato === 'running')) {
            var prima = job.passi_fatti, t0 = Date.now();
            try {
                var risposta = await api('avanza', { job_id: job.id, dopo: dopo });
                erroriRete = 0;
                /* applica() tocca la pagina (inserisce le carte, aggiorna
                   l'elenco): un suo errore NON e' un errore di rete. Tenerlo
                   dentro il catch di sopra lo faceva sembrare tale, con il
                   messaggio "connessione instabile" e un ritentativo infinito
                   su una richiesta che invece era andata benissimo. Qui si
                   registra e si va avanti: il lavoro sul server continua. */
                try {
                    applica(risposta.job);
                } catch (errPagina) {
                    if (window.console) console.error('FCAIJ: errore nel mostrare il risultato', errPagina);
                    job = risposta.job;
                    if (typeof job.cursore === 'number') dopo = job.cursore;
                }
            } catch (e) {
                if (e && (e.codice === 'not_found' || e.codice === 'auth')) { job = null; break; }
                /* La rete cade, il server risponde a meta'. Non e' un errore del
                   job: il lavoro e' al sicuro sul server, qui si riprova con
                   attese sempre piu' lunghe. Dopo il secondo tentativo lo si
                   dice, perche' una barra ferma senza spiegazione fa pensare a
                   un blocco. */
                erroriRete++;
                if (erroriRete >= 2) disegna(T('fc.ibfai_job_rete_instabile'));
                await attendi(Math.min(30000, 1500 * Math.pow(2, erroriRete)));
                continue;
            }

            var lavorato = job.passi_fatti > prima;
            if (lavorato) {
                var d = Date.now() - t0;
                mediaPasso = mediaPasso === null ? d : mediaPasso * 0.7 + d * 0.3;
            }
            if (job.stato !== 'queued' && job.stato !== 'running') break;

            disegna(job.in_attesa ? T('fc.ibfai_job_in_attesa') : '');
            /* Passo fatto: subito il successivo, ogni secondo in meno si somma
               su cento passi. Nessun passo (server pieno, tempo finito):
               qualche secondo di respiro, per non martellare. */
            await attendi(lavorato ? 250 : (job.in_attesa ? 5000 : 2500));
        }

        inCorsa = false;
        esito();
    }

    /** Il job si e' fermato: finito, in pausa, annullato. */
    function esito() {
        if (!job) { chiudiSchermata(); blocca(false); return; }

        if (job.stato === 'paused' && job.motivo_pausa === 'review') {
            chiudiSchermata();
            blocca(false, true);
            if (g.onRevisione) g.onRevisione(job.concetti || [], job);
            return;
        }
        if (job.stato === 'paused' && job.motivo_pausa === 'credits') {
            fermata(T('fc.ibfai_job_crediti_finiti_tit'),
                T('fc.ibfai_job_crediti_finiti', { fatte: num(ricevute) }), ['fcajRiprendi', 'fcajChiudi'], true);
            blocca(true);
            return;
        }
        if (job.stato === 'paused') { // breaker, o un motivo che non conosco ancora
            fermata(T('fc.ibfai_job_sospeso_tit'), T('fc.ibfai_job_sospeso'), ['fcajRiprendi', 'fcajChiudi'], true);
            blocca(true);
            return;
        }

        blocca(false);
        if (job.stato === 'done') {
            var scoperti = job.passi_falliti > 0 || mancanti > 0;
            if (g.onFine) g.onFine(job, { ricevute: ricevute, mancanti: mancanti });
            if (scoperti) {
                fermata(T('fc.ibfai_job_finito_tit'),
                    T('fc.ibfai_job_finito_scoperti', { fatte: num(ricevute) }), ['fcajRiprova', 'fcajChiudi'], false);
            } else {
                chiudiSchermata();
                if (typeof showToast === 'function') showToast(T('fc.ibfai_carte_generate', { n: ricevute }));
            }
            return;
        }
        if (job.stato === 'failed') {
            fermata(T('fc.ibfai_job_fallito_tit'), T('fc.ibfai_job_fallito'), ['fcajRiprova', 'fcajChiudi'], true);
            return;
        }
        // cancelled
        chiudiSchermata();
    }

    /* Durante un job i pulsanti che ne farebbero partire un altro si spengono.
       In revisione no: il pulsante "Genera" serve proprio a confermare
       l'elenco e far ripartire lo stesso job. */
    function blocca(attivo, revisione) {
        if (g.onBlocco) g.onBlocco(!!attivo, !!revisione);
    }

    /* Chiudere la pagina a meta' non perde niente (il lavoro e' sul server),
       ma su Altervista lo ferma finche' non si torna: va detto. */
    window.addEventListener('beforeunload', function (ev) {
        if (job && (job.stato === 'queued' || job.stato === 'running')) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });

    function azzeraConteggi() {
        dopo = 0; ricevute = 0; mancanti = 0; totaleCarte = 0; mediaPasso = null;
    }

    /* ============================================================
       4. FUORI
       ============================================================ */

    /**
     * Crea un job e lo segue. $dati: context_id, opzioni, e secondo il caso
     * testo (documento), concetti (elenco rivisto), titolo/elenco, nota.
     * Torna true se e' partito.
     */
    async function avvia(dati) {
        if (inCorsa) return false;
        /* I pulsanti si spengono SUBITO, al clic, non quando il server ha
           risposto: in quella mezza frazione di secondo un doppio clic
           manderebbe una seconda richiesta, che il server rifiuterebbe come
           "job gia' attivo" — e quel rifiuto si disegnerebbe sopra un job
           partito benissimo. */
        blocca(true);
        azzeraConteggi();
        if (dati.concetti) totaleCarte = dati.concetti.length;
        job = { id: 0, stato: 'queued', fase: dati.concetti ? 'carte' : 'concetti',
                passi_fatti: 0, passi_totali: 0, crediti_usati: 0, crediti_stimati: 0 };
        apriSchermata();
        disegna(T('fc.ibfai_job_avvio'));
        try {
            var j = await api('crea', Object.assign({ request_id: nuovoIdRichiesta() }, dati));
            job = null;
            applica(j.job);
        } catch (e) {
            job = null;
            fermata(T('fc.ibfai_job_non_partito_tit'), messaggio(e), ['fcajChiudi'], true);
            blocca(false);
            return false;
        }
        ciclo();
        return true;
    }

    /** Conferma l'elenco rivisto: lo stesso job passa alle carte. */
    async function conferma(jobId, concetti) {
        blocca(true);
        azzeraConteggi();
        totaleCarte = concetti.length;
        apriSchermata();
        disegna(T('fc.ibfai_job_avvio'));
        try {
            var j = await api('conferma', { job_id: jobId, concetti: concetti });
            job = null;
            applica(j.job);
            if (j.job.avviso_crediti) disegna(T('fc.ibfai_job_avviso_crediti'));
        } catch (e) {
            fermata(T('fc.ibfai_job_non_partito_tit'), messaggio(e), ['fcajChiudi'], true);
            blocca(false, true); // si torna alla revisione: Genera deve poter riprovare
            return false;
        }
        ciclo();
        return true;
    }

    /**
     * All'apertura della pagina: c'e' un job vivo?
     *   - su QUESTO mazzo, in modifica: riparte qui, e le carte gia' salvate
     *     non vengono inserite una seconda volta (ci pensa il pannello);
     *   - altrove: una pastiglia con il collegamento al mazzo giusto. Non si
     *     riprende qui perche' in inserimento la pagina non vede le carte gia'
     *     salvate, e le raddoppierebbe al primo salvataggio.
     */
    async function riprendiAllAvvio() {
        var j;
        try { j = (await api('attivo', {})).job; } catch (e) { return; }
        if (!j) return;

        var qui = g.deck && g.deck() === j.contesto_id && g.modo === 'edit';
        if (!qui) {
            job = j;
            var p = $('fcajPillola');
            p.hidden = false;
            p.classList.add('is-altrove');
            p.querySelector('i').style.width = Math.round(j.passi_fatti / Math.max(1, j.passi_totali) * 100) + '%';
            $('fcajPillolaTesto').textContent = T('fc.ibfai_job_altrove', { nome: j.contesto_nome || '#' + j.contesto_id });
            p.onclick = function () {
                window.location.href = 'inserimento_big_fc.php?modo=edit&category_id=' + encodeURIComponent(j.contesto_id);
            };
            blocca(true);
            return;
        }

        azzeraConteggi();
        ripresa = true;
        job = null;
        applica(j);
        if (j.stato === 'queued' || j.stato === 'running') {
            apriSchermata();
            disegna(T('fc.ibfai_job_ripreso'));
            ciclo();
        } else {
            esito();
        }
    }

    function init(ganci) {
        g = ganci || {};
        $('fcajRiduci').addEventListener('click', riduci);
        $('fcajPillola').addEventListener('click', function () {
            if (this.classList.contains('is-altrove')) return; // ha il suo onclick
            apriSchermata();
            disegna();
        });
        $('fcajChiudi').addEventListener('click', chiudiSchermata);

        $('fcajAnnulla').addEventListener('click', async function () {
            if (!job) return;
            var ok = typeof showConfirm === 'function'
                ? await showConfirm(T('fc.ibfai_job_annulla_tit'), T('fc.ibfai_job_annulla_testo'), T('fc.ibfai_job_annulla_si'))
                : true;
            if (!ok) return;
            try { applica((await api('annulla', { job_id: job.id, dopo: dopo })).job); } catch (e) { /* gia' fermo */ }
            job = job || { stato: 'cancelled' };
            job.stato = 'cancelled';
            /* Con un ciclo in corsa ci pensa lui a chiudere al prossimo giro;
               da fermo (in pausa) non c'e' nessun giro, e si chiude qui. */
            if (!inCorsa) esito();
        });

        $('fcajRiprendi').addEventListener('click', async function () {
            try {
                applica((await api('riprendi', { job_id: job.id })).job);
                apriSchermata();
                disegna();
                ciclo();
            } catch (e) {
                $('fcajNota').className = 'fcai-modale-conto is-warn';
                $('fcajNota').textContent = messaggio(e);
            }
        });

        $('fcajRiprova').addEventListener('click', async function () {
            try {
                mancanti = 0;
                applica((await api('riprova', { job_id: job.id })).job);
                apriSchermata();
                disegna();
                ciclo();
            } catch (e) {
                $('fcajNota').className = 'fcai-modale-conto is-warn';
                $('fcajNota').textContent = messaggio(e);
            }
        });
    }

    return {
        init: init,
        avvia: avvia,
        conferma: conferma,
        riprendiAllAvvio: riprendiAllAvvio,
        messaggio: messaggio,
        attivo: function () { return !!job && ['queued', 'running', 'paused'].indexOf(job.stato) !== -1; }
    };
})();
