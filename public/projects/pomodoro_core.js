/* =====================================================================
   STANDOUT — POMODORO_CORE.JS
   Il motore del pomodoro: il modello dello stato, la matematica del
   tempo e tutte le chiamate al server.

   Qui dentro non c'e' un solo getElementById, ed e' la regola che tiene
   in piedi tutto il resto: chi disegna (il cerchio grande di
   pomodoro.php, la barra in cima alle altre pagine, domani l'app) si
   iscrive agli eventi e chiede i numeri a tempi(). Nessuno rifa' i
   conti per conto suo, quindi una correzione alla formula del tempo
   vale per tutte le viste insieme e non puo' andare a metà.

   Uso minimo:
       PomoCore.init({ stato: STATE0, endpoint: 'pomodoro.php' });
       PomoCore.on('cambiato', function (st) { ...ridisegna... });
       PomoCore.avviaControllo();          // sincronia fra dispositivi
       var t = PomoCore.tempi();           // {testo, frazione, fase, ...}
   ===================================================================== */
(function () {
'use strict';

var ENDPOINT   = 'pomodoro.php';
var LIMITE_SEC = 36000;          // dieci ore: lo stesso tetto del server

/* ------------------------------------------------------------------
   IL MODELLO
   Un oggetto solo, piatto, con dentro tutto quello che serve a sapere
   che ore sono nel pomodoro. Le viste lo leggono, non lo copiano.
   ------------------------------------------------------------------ */
var M = {
    fase: 'idle',            // idle | running | paused_task | completed | pausa_running | pausa_done
    modo: 0,                 // 0 timer, 1 cronometro
    sessioneId: null,
    durataMin: null,         // durata prevista della sessione in corso
    accumulati: 0,           // secondi gia' messi in cascina nei segmenti chiusi
    inizioSegmentoMs: null,  // quando e' partito il segmento attivo corrente
    fineCompletatoMs: null,  // quando il pomodoro e' arrivato a zero
    pausaInizioMs: null,
    pausaDurataMin: null,
    durataSceltaMin: 25,     // la durata scelta a riposo (quella del quadrante fermo)
    durataPausaMin: 5,
    progettoId: 0, progettoNome: '', progettoColore: '',
    stile: 'cerchio'         // cerchio | clessidra
};

var statoServer = null;      // l'ultimo stato grezzo arrivato dal server
var revisione   = null;      // impronta di quello stato: cambia se cambia qualcosa
var ascoltatori = {};
var timerControllo = null;
var letturaInVolo  = null;

/* ------------------------------------------------------------------
   EVENTI
   'cambiato'  — il server dice qualcosa di diverso da quello che
                 sapevamo: quasi sempre vuol dire "e' stato un altro
                 dispositivo". Arriva lo stato nuovo, ridisegnare tocca
                 a chi ascolta.
   'conflitto' — un'azione e' stata rifiutata perche' da un'altra parte
                 era gia' successo qualcosa (due pomodori insieme).
   'modello'   — il modello locale e' stato aggiornato da una vista.
   ------------------------------------------------------------------ */
function on(nome, fn) {
    (ascoltatori[nome] = ascoltatori[nome] || []).push(fn);
    return fn;
}
function off(nome, fn) {
    var l = ascoltatori[nome]; if (!l) return;
    var i = l.indexOf(fn); if (i >= 0) l.splice(i, 1);
}
function emetti(nome, dato) {
    var l = ascoltatori[nome]; if (!l) return;
    for (var i = 0; i < l.length; i++) {
        // un ascoltatore che esplode non deve zittire gli altri ne' fermare il controllo periodico
        try { l[i](dato); } catch (e) { if (window.console) console.error('[pomo]', nome, e); }
    }
}

/* ------------------------------------------------------------------
   FORMATO
   ------------------------------------------------------------------ */
function pad(n) { return (n < 10 ? '0' : '') + n; }

function fmtHMS(totSec) {
    totSec = Math.max(0, Math.floor(totSec));
    var h = Math.floor(totSec / 3600), m = Math.floor((totSec % 3600) / 60), s = totSec % 60;
    return h > 0 ? (h + ':' + pad(m) + ':' + pad(s)) : (pad(m) + ':' + pad(s));
}

/* Le date del database arrivano come "2026-08-30 09:14:00". Lo scambio
   dello spazio con la T serve a Safari, che senza si rifiuta di leggerle
   e restituisce NaN — ed e' gia' costato una pagina bianca. */
function msDa(testo) {
    if (!testo) return null;
    var ms = new Date(String(testo).replace(' ', 'T')).getTime();
    return isNaN(ms) ? null : ms;
}

/* ------------------------------------------------------------------
   IL TEMPO — l'unico posto dove si fanno questi conti

   I 500 ms di scarto non sono un errore: all'avvio il numero resta
   fermo mezzo secondo (cosi' non si vede sfarfallare mentre il tasto
   si trasforma) mentre la barra e la sabbia partono subito. Percio' i
   tempi sono due: quello del TESTO e quello REALE, che comanda la
   grafica e la fine della sessione.
   ------------------------------------------------------------------ */
function tempi(oraMs) {
    var now = oraMs || Date.now();
    var t = {
        fase: M.fase, modo: M.modo,
        testo: '00:00',          // cosa scrivere sul quadrante
        frazione: 0,             // 0..1, quanto e' passato (per barra, anello, sabbia)
        frazioneScintilla: null, // giro extra dopo il completamento
        trascorso: 0, reale: 0, mancante: 0, fuoritempo: 0,
        finito: false,           // il tempo previsto e' esaurito adesso
        oltreIlLimite: false,    // il cronometro ha sfondato le dieci ore
        capovolta: false,        // la clessidra va rigirata (cronometro, ogni ora)
        inPausa: (M.fase === 'pausa_running' || M.fase === 'pausa_done'),
        colore: M.progettoColore
    };

    if (M.fase === 'running') {
        var isTimer = (M.modo === 0);
        var testoTrascorso = M.accumulati + Math.max(0, (now - M.inizioSegmentoMs) / 1000);
        var reale = isTimer
            ? M.accumulati + Math.max(0, (now - (M.inizioSegmentoMs - 500)) / 1000)
            : testoTrascorso;
        t.trascorso = testoTrascorso;
        t.reale = reale;

        if (isTimer) {
            var totale = (M.durataMin || 0) * 60;
            t.finito   = (totale - reale) <= 0;
            t.mancante = totale - testoTrascorso;
            t.testo    = fmtHMS(t.mancante);
            t.frazione = totale > 0 ? (reale / totale) : 0;
        } else {
            /* Il tetto delle dieci ore vale anche a pagina aperta, non solo
               alla riapertura: senza, chi lascia il cronometro acceso tutta
               la notte lo ritrova a quattordici ore invece che fermo al
               limite, e i due percorsi darebbero risultati diversi. */
            t.oltreIlLimite = reale >= LIMITE_SEC;
            t.testo     = fmtHMS(testoTrascorso);
            t.frazione  = (reale % 3600) / 3600;
            t.capovolta = Math.floor(reale / 3600) % 2 === 1;
        }

    } else if (M.fase === 'paused_task') {
        // congelata: il segmento corrente non scorre, conta solo l'accumulato
        t.trascorso = t.reale = M.accumulati;
        if (M.modo === 0) {
            var tot = (M.durataMin || 0) * 60;
            t.mancante = Math.max(0, tot - M.accumulati);
            t.testo    = fmtHMS(t.mancante);
            t.frazione = tot > 0 ? Math.min(1, M.accumulati / tot) : 0;
        } else {
            t.testo     = fmtHMS(M.accumulati);
            t.frazione  = (M.accumulati % 3600) / 3600;
            t.capovolta = Math.floor(M.accumulati / 3600) % 2 === 1;
        }

    } else if (M.fase === 'completed') {
        /* Il pomodoro e' finito ma si continua a studiare: la barra resta
           piena, perche' quel pomodoro e' completo e mostrarlo a meta'
           sarebbe una bugia. La scintilla invece fa un giro nuovo lungo
           quanto il pomodoro: da' la misura del tempo in piu' senza
           toccare il risultato gia' raggiunto. */
        t.frazione   = 1;
        t.trascorso  = t.reale = M.accumulati;
        t.fuoritempo = M.fineCompletatoMs ? Math.max(0, (now - M.fineCompletatoMs) / 1000) : 0;
        t.testo      = fmtHMS(M.accumulati);
        var giro = (M.durataMin || 0) * 60;
        if (giro > 0) t.frazioneScintilla = (t.fuoritempo % giro) / giro;

    } else if (M.fase === 'pausa_running' || M.fase === 'pausa_done') {
        var pTot   = (M.pausaDurataMin || 0) * 60;
        var pTesto = Math.max(0, (now - M.pausaInizioMs) / 1000);
        var pReale = Math.max(0, (now - (M.pausaInizioMs - 500)) / 1000);
        t.trascorso = pTesto; t.reale = pReale;
        t.finito = (pTot - pReale) <= 0;
        if (pTot - pTesto > 0) {
            t.mancante = pTot - pTesto;
            t.testo    = fmtHMS(t.mancante);
            t.frazione = pTot > 0 ? (pReale / pTot) : 0;
        } else {
            t.fuoritempo = pTesto - pTot;
            t.testo      = '+' + fmtHMS(t.fuoritempo);
            t.frazione   = 1;
        }

    } else {
        // a riposo il quadrante mostra la durata scelta, non un conteggio
        t.testo = (M.modo === 0) ? (M.durataSceltaMin + ':00') : '00:00';
    }

    return t;
}

/* Il numero di secondi da scrivere nel database quando si congela la
   sessione. L'arrotondamento non e' un dettaglio estetico: per eccesso col
   timer e per difetto col cronometro, altrimenti il secondo mostrato "salta"
   di uno nell'istante in cui la sessione si ferma. Sta qui perche' lo devono
   fare uguale tutte le viste, altrimenti congelare dalla barra e congelare
   dalla pagina scriverebbero due durate diverse. */
function tempoDaCongelare() {
    var t = tempi();
    return (M.modo === 0) ? Math.ceil(t.trascorso) : Math.floor(t.trascorso);
}

/* ------------------------------------------------------------------
   AGGIORNARE IL MODELLO
   imposta()   — la vista dice al motore cosa e' appena cambiato
   dalloStato()— si riempie il modello da uno stato del server, che e'
                 quello che serve alla barra e dopo ogni sincronia
   ------------------------------------------------------------------ */
function imposta(obj) {
    if (!obj) return M;
    for (var k in obj) if (Object.prototype.hasOwnProperty.call(obj, k)) M[k] = obj[k];
    emetti('modello', M);
    return M;
}

function dalloStato(st) {
    st = st || {};
    statoServer = st;
    if (st.revisione) revisione = st.revisione;

    if (st.settings) {
        M.durataSceltaMin = st.settings.ultima_durata_pomodoro_min || 25;
        M.durataPausaMin  = st.settings.ultima_durata_pausa_min || 5;
        M.stile           = st.settings.stile || 'cerchio';
        M.modo            = st.settings.ultima_modalita || 0;
    }

    if (st.active) {
        var a = st.active;
        M.sessioneId = a.id;
        M.modo       = a.mode;
        M.durataMin  = a.durata_prevista_minuti;
        M.accumulati = a.durata_secondi || 0;
        M.progettoId = a.project_id || 0;
        M.progettoNome   = a.progetto_nome || '';
        M.progettoColore = a.progetto_colore || '';
        M.pausaInizioMs = null;
        if (a.esito === 'pausa') {
            M.fase = 'paused_task';
            M.inizioSegmentoMs = null;
        } else {
            M.fase = 'running';
            M.inizioSegmentoMs = msDa(a.data_ripresa) || msDa(a.data_inizio) || Date.now();
        }

    } else if (st.pausa) {
        var p = st.pausa;
        M.fase = 'pausa_running';
        M.sessioneId = null;
        M.pausaInizioMs   = msDa(p.data_inizio) || Date.now();
        M.pausaDurataMin  = p.durata_prevista_minuti || M.durataPausaMin;
        M.progettoId      = p.project_id || 0;
        M.progettoNome    = p.progetto_nome || '';
        M.progettoColore  = p.progetto_colore || '';
        // la pausa scaduta ma non ancora chiusa e' un'altra fase: il tempo va in positivo
        if ((Date.now() - M.pausaInizioMs) / 1000 >= M.pausaDurataMin * 60) M.fase = 'pausa_done';

    } else if (st.last && st.last.esito === 'completato') {
        var c = st.last;
        M.fase = 'completed';
        M.sessioneId = c.id;
        M.modo       = c.mode;
        M.durataMin  = c.durata_prevista_minuti;
        M.accumulati = c.durata_secondi || ((c.durata_prevista_minuti || 0) * 60);
        M.fineCompletatoMs = msDa(c.data_fine) || Date.now();
        M.progettoId     = c.project_id || 0;
        M.progettoNome   = c.progetto_nome || '';
        M.progettoColore = c.progetto_colore || '';

    } else {
        M.fase = 'idle';
        M.sessioneId = null;
        M.durataMin = null;
        M.accumulati = 0;
        M.inizioSegmentoMs = null;
        M.pausaInizioMs = null;
        M.fineCompletatoMs = null;
    }

    emetti('modello', M);
    return M;
}

/* ------------------------------------------------------------------
   IL SERVER — un solo punto di uscita

   Ogni scrittura passa da qui, quindi il motore sa sempre cos'e'
   appena successo e puo' rileggere l'impronta dello stato senza
   scambiare la propria azione per quella di un altro dispositivo.
   ------------------------------------------------------------------ */
var scrittureInCorso = 0;   // quante nostre azioni sono ancora per aria

/* La lettura nuda: nessun evento, nessuna furbizia. La usano sia le azioni
   sia il controllo periodico, che ci mettono sopra le loro regole.

   no-store non e' un vezzo: questa e' una GET su un indirizzo fisso, e il
   browser servirebbe volentieri la copia vecchia — lo stesso motivo per cui
   la priorita' dei to buy tornava indietro da sola. */
function leggiStato() {
    return fetch(ENDPOINT + '?ajax=stato', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { return (d && d.success && d.stato) ? d.stato : null; })
        .catch(function () { return null; });
}

/* ------------------------------------------------------------------
   SENZA RETE — LA CODA
   Come nel planner: un tocco su Avvia, Pausa o Stop vale SUBITO, anche in
   metro o in aereo. Il quadrante parte, si ferma, si chiude; l'azione
   aspetta in una coda salvata nel telefono e parte da sola quando la rete
   torna, nello stesso ordine in cui e' stata fatta.

   IL TEMPO NON SI SPOSTA. Il server segna gli orari con il suo NOW(), e
   un "Avvia" toccato alle 10:00 e arrivato alle 10:20 avrebbe fatto
   cominciare la sessione alle 10:20. Ogni azione in coda si porta dietro
   quanto ha aspettato (ritardo_ms, misurato con l'orologio del telefono
   fra il tocco e l'invio): il server lo toglie dal suo NOW(). Si usa la
   DIFFERENZA e non l'ora del telefono, cosi' un orologio avanti o indietro
   di qualche minuto non sposta niente.

   L'ID DELLA SESSIONE. Una sessione avviata senza rete non ha ancora il
   suo numero: riceve un numero provvisorio negativo, e le azioni che la
   riguardano (pausa, stop…) partono con quello. Quando il server risponde
   all'avvio, il provvisorio si sostituisce col vero ovunque: nelle azioni
   ancora in coda, nel modello, e nella pagina che ascolta 'id'.

   Mentre la coda non e' vuota il controllo periodico non tocca niente:
   quello che dice il server e' vecchio per definizione.
   ------------------------------------------------------------------ */
var CHIAVE_CODA = 'standout_pomo_coda_v1';
var CODA = { ops: [], mappa: {}, seq: 1, provv: -1, inVolo: false, tentativi: 0, timer: null, riletta: false };

function codaSalva() {
    try { localStorage.setItem(CHIAVE_CODA, JSON.stringify({ ops: CODA.ops, mappa: CODA.mappa, seq: CODA.seq, provv: CODA.provv })); }
    catch (e) {}
}
function codaCarica() {
    try {
        var c = JSON.parse(localStorage.getItem(CHIAVE_CODA) || 'null');
        if (c && c.ops && c.ops.length) {
            CODA.ops = c.ops; CODA.mappa = c.mappa || {}; CODA.seq = c.seq || 1; CODA.provv = c.provv || -1;
            CODA.riletta = true;   // la pagina e' nata da uno stato che non sa niente di queste
        } else if (c && c.mappa) {
            CODA.mappa = c.mappa; CODA.provv = c.provv || -1;
        }
    } catch (e) {}
}
function senzaRete() { return typeof navigator !== 'undefined' && navigator.onLine === false; }

/* La spia per chi disegna: quante azioni aspettano e se manca la rete. */
function codaAvvisa() {
    emetti('coda', { n: CODA.ops.length, offline: senzaRete() || CODA.tentativi > 0 });
}

/* L'id vero al posto di quello provvisorio. null = la sessione provvisoria
   non e' mai nata (l'avvio e' stato rifiutato): l'azione non ha piu' senso. */
function idVero(v) {
    var n = +v;
    if (!(n < 0)) return v;
    return CODA.mappa[n] !== undefined ? CODA.mappa[n] : null;
}
function datiVeri(dati) {
    var out = {};
    for (var k in dati) if (Object.prototype.hasOwnProperty.call(dati, k)) out[k] = dati[k];
    if (out.session_id !== undefined) {
        var v = idVero(out.session_id);
        if (v === null) return null;
        out.session_id = v;
    }
    return out;
}

/* Quello che l'azione fa al modello, fatto qui: la barra, a differenza
   della pagina, non ha un suo stato e lo legge tutto dal motore. */
function applicaLocale(nome, dati) {
    var ora = Date.now();
    if (nome === 'start') {
        M.fase = 'running'; M.modo = +dati.mode || 0;
        M.durataMin = M.modo === 0 ? (+dati.durata_minuti || M.durataSceltaMin) : null;
        M.accumulati = 0; M.inizioSegmentoMs = ora; M.fineCompletatoMs = null; M.pausaInizioMs = null;
        M.sessioneId = dati.session_id;
        if ((+dati.project_id || 0) !== (+M.progettoId || 0)) { M.progettoId = +dati.project_id || 0; M.progettoNome = ''; M.progettoColore = ''; }
    } else if (nome === 'pausa_task') {
        M.fase = 'paused_task'; M.accumulati = +dati.tempo_esatto || M.accumulati; M.inizioSegmentoMs = null;
    } else if (nome === 'riprendi_task') {
        M.fase = 'running'; M.inizioSegmentoMs = ora;
    } else if (nome === 'segna_completato') {
        M.fase = 'completed'; M.accumulati = (M.durataMin || 0) * 60; M.fineCompletatoMs = ora;
    } else if (nome === 'estendi') {
        if (M.fineCompletatoMs) M.accumulati += Math.max(0, Math.round((ora - M.fineCompletatoMs) / 1000));
        M.fineCompletatoMs = ora;
    } else if (nome === 'interrompi' || nome === 'archivia_completato' || nome === 'fine_pausa') {
        M.fase = 'idle'; M.sessioneId = null; M.durataMin = null; M.accumulati = 0;
        M.inizioSegmentoMs = null; M.pausaInizioMs = null; M.fineCompletatoMs = null;
    } else if (nome === 'avvia_pausa') {
        M.fase = 'pausa_running'; M.sessioneId = null; M.pausaInizioMs = ora;
        M.pausaDurataMin = +dati.durata_minuti || M.durataPausaMin;
    } else {
        return;
    }
    emetti('modello', M);
}

/* La risposta che il server avrebbe dato, inventata con quello che si sa:
   le pagine leggono questi campi per andare avanti come se fosse arrivata. */
function rispostaFinta(nome, dati) {
    var r = { success: true, inCoda: true };
    if (nome === 'start') r.session_id = dati.session_id;
    if (nome === 'pausa_task') r.durata_secondi = Math.round(+dati.tempo_esatto || 0);
    if (nome === 'estendi') r.durata_secondi = M.accumulati;
    return r;
}

function accoda(nome, dati) {
    dati = dati || {};
    var copia = {};
    for (var k in dati) if (Object.prototype.hasOwnProperty.call(dati, k)) copia[k] = dati[k];
    if (nome === 'start') copia.session_id = CODA.provv--;
    CODA.ops.push({ n: CODA.seq++, nome: nome, dati: copia, t: Date.now() });
    applicaLocale(nome, copia);
    codaSalva();
    codaAvvisa();
    /* subito un tentativo: se la rete c'e' (la richiesta diretta e' caduta
       per un attimo) parte adesso, se no aspetta 'online' */
    setTimeout(codaGira, 0);
    return Promise.resolve(rispostaFinta(nome, copia));
}

/* La sessione provvisoria non e' nata: via tutte le azioni che la
   riguardano, che altrimenti finirebbero sulla sessione di un altro. */
function scartaLegateA(provv) {
    CODA.ops = CODA.ops.filter(function (o) { return +o.dati.session_id !== provv; });
}

function codaGira() {
    if (CODA.timer) { clearTimeout(CODA.timer); CODA.timer = null; }
    if (CODA.inVolo) return;
    if (!CODA.ops.length) { codaAvvisa(); return; }
    if (senzaRete()) { codaAvvisa(); return; }   // riparte con l'evento 'online'

    var op = CODA.ops[0];
    /* l'avvio porta il SUO numero provvisorio, non quello di una sessione
       da cercare: non si traduce, si toglie e basta */
    var dati;
    if (op.nome === 'start') {
        dati = {};
        for (var k in op.dati) if (Object.prototype.hasOwnProperty.call(op.dati, k) && k !== 'session_id') dati[k] = op.dati[k];
    } else {
        dati = datiVeri(op.dati);
    }
    if (dati === null) {                          // sessione mai nata: niente da mandare
        CODA.ops.shift(); codaSalva(); return codaGira();
    }
    dati.ritardo_ms = Math.max(0, Date.now() - op.t);

    CODA.inVolo = true;
    fetch(ENDPOINT + '?ajax=' + encodeURIComponent(op.nome), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams(dati).toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        CODA.inVolo = false;
        CODA.tentativi = 0;
        CODA.ops.shift();
        if (op.nome === 'start') {
            if (d && d.success && d.session_id) {
                CODA.mappa[op.dati.session_id] = d.session_id;
                if (M.sessioneId === op.dati.session_id) M.sessioneId = d.session_id;
                emetti('id', { provvisorio: op.dati.session_id, vero: d.session_id });
            } else {
                scartaLegateA(op.dati.session_id);
            }
        }
        if (d && d.conflitto) {
            /* Mentre eravamo senza rete, da un altro dispositivo e' partito
               un altro pomodoro: vince quello, che e' gia' vero. */
            CODA.conflitto = d;
        }
        codaSalva();
        if (CODA.ops.length) return codaGira();
        return codaFinita();
    })
    .catch(function () {
        /* Senza risposta. Si riprova con calma crescente; l'azione resta in
           testa, cosi' l'ordine non cambia mai. */
        CODA.inVolo = false;
        CODA.tentativi++;
        codaAvvisa();
        var attesa = Math.min(30000, 1500 * Math.pow(2, Math.min(5, CODA.tentativi - 1)));
        CODA.timer = setTimeout(codaGira, attesa);
    });
}

/* Coda vuota: si confronta quello che dice il server con quello che si
   vede. Se coincidono si prende solo l'impronta, in silenzio — la nostra
   stessa azione non e' "un altro dispositivo". Se no, vince il server. */
function faseDi(st) {
    if (!st) return null;
    if (st.active) return st.active.esito === 'pausa' ? 'paused_task' : 'running';
    if (st.pausa) return 'pausa';
    if (st.last && st.last.esito === 'completato') return 'completed';
    return 'idle';
}
function codaFinita() {
    codaAvvisa();
    return leggiStato().then(function (st) {
        if (!st || CODA.ops.length) return;
        var conflitto = CODA.conflitto; CODA.conflitto = null;
        var nostra = (M.fase === 'pausa_running' || M.fase === 'pausa_done') ? 'pausa' : M.fase;
        var riletta = CODA.riletta;
        var diversa = faseDi(st) !== nostra
            || (st.active && +st.active.id !== +M.sessioneId);
        CODA.riletta = false;
        revisione = st.revisione; statoServer = st;
        if (diversa || conflitto || riletta) {
            /* dallaCoda: la pagina si riallinea senza dire «e' stato un altro
               dispositivo», che qui non sarebbe vero — sono le nostre azioni
               di una visita precedente, appena arrivate */
            st.dallaCoda = riletta && !conflitto;
            dalloStato(st);
            emetti('cambiato', st);
        }
        if (conflitto) emetti('conflitto', conflitto);
        emetti('allineato', st);
    });
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', function () { CODA.tentativi = 0; codaGira(); });
    window.addEventListener('offline', codaAvvisa);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) codaGira(); });
}

/* LE AZIONI CHE VALGONO SUBITO.
   Prima Avvia/Stop/Pausa aspettavano il server DUE volte di fila (la scrittura
   e poi la rilettura dello stato) prima di cambiare qualcosa a schermo: su una
   rete lenta o un hosting condiviso erano da mezzo secondo a un secondo di
   quadrante fermo dopo il tocco. La strada della coda (nata per la metro) fa
   gia' quello che serve: il modello cambia al tocco, la richiesta parte subito
   dietro le quinte, l'ordine e' garantito e a coda vuota si riallinea al
   server (con il suo conflitto, se un altro dispositivo era arrivato prima).
   Quindi la si usa SEMPRE per queste, non solo senza rete.
   Restano fuori, di proposito, le azioni di cui la pagina legge la risposta
   vera del server (segna_completato porta l'ora di fine, estendi la durata)
   e le impostazioni, che non cambiano il quadrante. */
var OTTIMISTE = {
    start: 1, pausa_task: 1, riprendi_task: 1, interrompi: 1,
    archivia_completato: 1, avvia_pausa: 1, fine_pausa: 1
};

function azione(nome, dati) {
    dati = dati || {};
    /* Con qualcosa gia' in coda si accoda anche questa: passarle davanti
       vorrebbe dire, per esempio, fermare una sessione che il server non ha
       ancora visto partire. Lo stesso senza rete. */
    if (CODA.ops.length || senzaRete() || OTTIMISTE[nome] === 1) return accoda(nome, dati);
    var veri = datiVeri(dati);
    if (veri === null) return Promise.resolve({ success: false });
    dati = veri;

    scrittureInCorso++;
    var chiudi = function () { if (scrittureInCorso > 0) scrittureInCorso--; };

    var reteCaduta = false;
    return fetch(ENDPOINT + '?ajax=' + encodeURIComponent(nome), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams(dati || {}).toString()
    })
    .catch(function (e) { reteCaduta = true; throw e; })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d && d.conflitto) {
            /* Rifiutata perche' altrove era gia' successo qualcosa. Lo stato
               vero arriva insieme al rifiuto: si adotta subito, cosi' chi
               guarda vede la verita' invece della propria intenzione. */
            chiudi();
            if (d.stato) { dalloStato(d.stato); emetti('cambiato', d.stato); }
            emetti('conflitto', d);
            return d;
        }
        /* Andata a buon fine: si rilegge l'impronta e basta, senza toccare il
           modello — le viste hanno gia' aggiornato il loro, e sovrascriverlo
           qui vorrebbe dire far saltare il quadrante di mezzo secondo a ogni
           tasto premuto. La rilettura e' forzata e non condivisa con quella
           del controllo periodico: una risposta partita PRIMA della scrittura
           ci riporterebbe l'impronta vecchia, e al giro dopo scambieremmo la
           nostra stessa azione per quella di un altro dispositivo. */
        /* La rilettura parte ma NON si aspetta: chi ha toccato il tasto vede
           l'esito appena il server ha risposto alla scrittura, senza un
           secondo giro. scrittureInCorso resta alzato finche' la rilettura non
           e' tornata, cosi' il controllo periodico continua a non scambiare
           questa azione per quella di un altro dispositivo. */
        leggiStato().then(function (st) {
            if (st) { revisione = st.revisione; statoServer = st; }
            chiudi();
        }, chiudi);
        return d;
    })
    .catch(function (e) {
        chiudi();
        /* La rete e' caduta proprio adesso (il telefono la crede ancora
           accesa): invece dell'errore, l'azione va in coda come se fossimo
           gia' senza rete. Se invece il server ha risposto male, l'errore
           resta un errore. */
        if (reteCaduta) return accoda(nome, dati);
        throw e;
    });
}

/* Legge lo stato e lo confronta con quello che sapevamo.
     applica — riempie anche il modello locale
   Se e' cambiato qualcosa lo dice con 'cambiato'; se non e' cambiato niente
   il giro e' costato una query e nient'altro. */
function aggiorna(opzioni) {
    opzioni = opzioni || {};
    if (CODA.ops.length && senzaRete()) return Promise.resolve(statoServer);
    if (letturaInVolo) return letturaInVolo;

    letturaInVolo = leggiStato().then(function (st) {
        letturaInVolo = null;
        if (!st) return null;

        /* Con una nostra scrittura ancora per aria non si puo' dire niente:
           questa risposta puo' essere partita prima o dopo, e nel dubbio
           annunciare "e' stato un altro dispositivo" sarebbe una bugia. */
        if (scrittureInCorso > 0) return st;
        /* Lo stesso con azioni in coda: il server non le ha ancora viste,
           e adottare il suo stato rimetterebbe in moto un pomodoro appena
           fermato senza rete. */
        if (CODA.ops.length || CODA.inVolo) return st;

        var diverso = (revisione !== null && st.revisione !== revisione);
        revisione = st.revisione;
        statoServer = st;
        if (opzioni.applica || diverso) dalloStato(st);
        if (diverso) emetti('cambiato', st);
        return st;
    });

    return letturaInVolo;
}

/* ------------------------------------------------------------------
   IL CONTROLLO PERIODICO
   Un giro al minuto: abbastanza per accorgersi che il pomodoro e'
   stato messo in pausa dal telefono mentre si guardava il computer,
   abbastanza poco da non pesare. A scheda nascosta non si controlla
   affatto e si riprende appena torna in primo piano, che e' anche il
   momento in cui serve davvero.
   ------------------------------------------------------------------ */
function avviaControllo(cfg) {
    fermaControllo();
    cfg = cfg || {};
    var attivo = cfg.attivo || 30000;    // qualcosa sta girando: mezzo minuto
    var riposo = cfg.riposo || 180000;   // tutto fermo: non c'e' fretta

    function inMoto() {
        return M.fase !== 'idle';
    }
    function giro() {
        timerControllo = setTimeout(function () {
            /* A scheda nascosta non si controlla affatto: nessuno sta
               guardando, e al ritorno in primo piano c'e' comunque la lettura
               immediata qui sotto. */
            if (document.hidden) { giro(); return; }
            aggiorna().then(giro, giro);
        }, inMoto() ? attivo : riposo);
    }
    giro();

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) aggiorna();
    });
}
function fermaControllo() {
    if (timerControllo) { clearTimeout(timerControllo); timerControllo = null; }
}

/* ------------------------------------------------------------------
   AVVIO
   ------------------------------------------------------------------ */
function init(cfg) {
    cfg = cfg || {};
    codaCarica();
    if (cfg.endpoint) ENDPOINT = cfg.endpoint;
    if (cfg.limite)   LIMITE_SEC = cfg.limite;
    if (cfg.stato) {
        revisione = cfg.stato.revisione || null;
        statoServer = cfg.stato;
        /* applica:false serve alla pagina pomodoro, che ha gia' il suo
           bootstrap e spinge i valori nel motore con imposta(): applicare
           anche qui vorrebbe dire fare due volte lo stesso lavoro con il
           rischio che le due strade si scostino. */
        if (cfg.applica !== false) {
            dalloStato(cfg.stato);
            /* azioni rimaste in coda da una visita precedente: il modello le
               rifa' sopra lo stato del server, che non le conosce ancora */
            CODA.ops.forEach(function (o) { applicaLocale(o.nome, o.dati); });
        }
        else if (cfg.stato.settings) {
            M.durataSceltaMin = cfg.stato.settings.ultima_durata_pomodoro_min || 25;
            M.durataPausaMin  = cfg.stato.settings.ultima_durata_pausa_min || 5;
            M.stile           = cfg.stato.settings.stile || 'cerchio';
            M.modo            = cfg.stato.settings.ultima_modalita || 0;
        }
    }
    if (CODA.ops.length) setTimeout(function () { codaAvvisa(); codaGira(); }, 0);
    return API;
}

var API = {
    init: init,
    on: on, off: off,
    modello: function () { return M; },
    stato: function () { return statoServer; },
    fase: function () { return M.fase; },
    imposta: imposta,
    dalloStato: dalloStato,
    tempi: tempi,
    tempoDaCongelare: tempoDaCongelare,
    pad: pad, fmtHMS: fmtHMS, msDa: msDa,
    azione: azione,
    inCoda: function () { return CODA.ops.length; },
    senzaRete: function () { return senzaRete() || CODA.tentativi > 0; },
    aggiorna: aggiorna,
    avviaControllo: avviaControllo,
    fermaControllo: fermaControllo,
    get LIMITE_SEC() { return LIMITE_SEC; }
};

window.PomoCore = API;
})();
