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

function azione(nome, dati) {
    scrittureInCorso++;
    var chiudi = function () { if (scrittureInCorso > 0) scrittureInCorso--; };

    return fetch(ENDPOINT + '?ajax=' + encodeURIComponent(nome), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams(dati || {}).toString()
    })
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
        return leggiStato().then(function (st) {
            if (st) { revisione = st.revisione; statoServer = st; }
            chiudi();
            return d;
        });
    })
    .catch(function (e) { chiudi(); throw e; });
}

/* Legge lo stato e lo confronta con quello che sapevamo.
     applica — riempie anche il modello locale
   Se e' cambiato qualcosa lo dice con 'cambiato'; se non e' cambiato niente
   il giro e' costato una query e nient'altro. */
function aggiorna(opzioni) {
    opzioni = opzioni || {};
    if (letturaInVolo) return letturaInVolo;

    letturaInVolo = leggiStato().then(function (st) {
        letturaInVolo = null;
        if (!st) return null;

        /* Con una nostra scrittura ancora per aria non si puo' dire niente:
           questa risposta puo' essere partita prima o dopo, e nel dubbio
           annunciare "e' stato un altro dispositivo" sarebbe una bugia. */
        if (scrittureInCorso > 0) return st;

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
    if (cfg.endpoint) ENDPOINT = cfg.endpoint;
    if (cfg.limite)   LIMITE_SEC = cfg.limite;
    if (cfg.stato) {
        revisione = cfg.stato.revisione || null;
        statoServer = cfg.stato;
        /* applica:false serve alla pagina pomodoro, che ha gia' il suo
           bootstrap e spinge i valori nel motore con imposta(): applicare
           anche qui vorrebbe dire fare due volte lo stesso lavoro con il
           rischio che le due strade si scostino. */
        if (cfg.applica !== false) dalloStato(cfg.stato);
        else if (cfg.stato.settings) {
            M.durataSceltaMin = cfg.stato.settings.ultima_durata_pomodoro_min || 25;
            M.durataPausaMin  = cfg.stato.settings.ultima_durata_pausa_min || 5;
            M.stile           = cfg.stato.settings.stile || 'cerchio';
            M.modo            = cfg.stato.settings.ultima_modalita || 0;
        }
    }
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
    aggiorna: aggiorna,
    avviaControllo: avviaControllo,
    fermaControllo: fermaControllo,
    get LIMITE_SEC() { return LIMITE_SEC; }
};

window.PomoCore = API;
})();
