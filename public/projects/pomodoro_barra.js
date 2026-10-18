/* =====================================================================
   NISAMA — LA BARRA DEL POMODORO (comportamento)

   Qui non c'e' un solo calcolo sul tempo: tutto quello che riguarda
   "a che punto siamo" lo chiede a PomoCore, lo stesso motore che
   alimenta la pagina pomodoro. Questo file sa solo disegnare e
   ascoltare i tocchi.

   Il pezzo forse meno ovvio e' che la barra NON ha un battito. La
   linea che si riempie e' una transizione CSS lunga quanto il tempo
   che manca: la si imposta una volta e ci pensa il browser, con un
   movimento continuo invece dei quattro scatti al secondo che darebbe
   un setInterval. Il JavaScript si risveglia solo quando qualcosa
   cambia davvero.
   ===================================================================== */
(function () {
'use strict';

var nodo = document.getElementById('pomo-barra-dati');
if (!nodo || !window.PomoCore) return;

var D;
try { D = JSON.parse(nodo.textContent); } catch (e) { return; }
if (!D || !D.stato) return;

var T = D.testi || {};
var COLORE_BASE = '#E2725B';

var barra   = document.getElementById('pomoBarra');
var popup   = document.getElementById('pomoPop');
var velo    = document.getElementById('pomoPopVelo');
if (!barra || !popup) return;

var elTesto  = document.getElementById('pomoBarraTesto');
var elOra    = document.getElementById('pomoPopOra');
var elArco   = document.getElementById('pomoPopArco');
var elProg      = document.getElementById('pomoPopProg');
var elProgTesto = document.getElementById('pomoPopProgTesto');
var elExtra  = document.getElementById('pomoPopExtra');
var elNota   = document.getElementById('pomoPopNota');
var tasti    = {
    pausaSessione: document.getElementById('pomoPopCongela'),
    riprendi:      document.getElementById('pomoPopRiprendi'),
    chiudi:        document.getElementById('pomoPopChiudi'),
    tisana:        document.getElementById('pomoPopTisana'),
    extra:         document.getElementById('pomoPopExtraBtn'),
    nuovo:         document.getElementById('pomoPopNuovo'),
    scarta:        document.getElementById('pomoPopScarta')
};

var CIRC = 2 * Math.PI * 44;     // il raggio dell'arco nel markup
var timerFine = null;            // l'unico timer: scatta a fine tempo previsto
var battito   = null;            // gira solo a popup aperto
var selettoreChiesto = false;
var azioneInCorso = false;       // un tasto per volta: vedi agisci()
var scartaArmato  = null;        // il primo tocco del cestino arma, il secondo cancella

function disarmaScarta(){
    if (scartaArmato) { clearTimeout(scartaArmato); scartaArmato = null; }
    if (tasti && tasti.scarta) tasti.scarta.classList.remove('is-armato');
}

/* ------------------------------------------------------------------
   IL TOKEN DELLE SCRITTURE
   Ogni POST del pomodoro passa da sec_guard_write(), che pretende il
   token della sessione. Ad aggiungerlo alle richieste e' nisama_fetch.js,
   che la pagina pomodoro si carica da sola in testa — ma questa barra
   finisce su ventisette pagine, e non tutte se lo caricano.

   Dove manca succedeva questo: il tasto si premeva, il server rifiutava,
   il .catch della promessa ingoiava l'errore e la barra restava identica.
   Un tasto che non risponde e un tasto che non c'e' si assomigliano
   troppo — sembrava semplicemente rotto.

   Si carica solo se non c'e' gia': avvolgere fetch due volte sulla stessa
   pagina e' un guaio peggiore di quello che si sta risolvendo. Il
   controllo e' sull'indirizzo dello script, che e' l'unica traccia certa
   lasciata da un file che potrebbe non esporre niente di suo.
   ------------------------------------------------------------------ */
(function assicuraFetch(){
    if (!D.fetchjs) return;
    if (document.querySelector('script[src*="nisama_fetch"]')) return;
    var s = document.createElement('script');
    s.src = D.fetchjs;
    document.head.appendChild(s);
})();

/* ------------------------------------------------------------------
   LO SPAZIO IN CIMA
   Solo il padding del body: e' l'unica cosa prevedibile. Un giro
   automatico a cercare "le altre intestazioni fisse" fra i figli del
   body l'avevamo provato, ed e' stato proprio quello a rompere le
   pagine — ha agganciato un elemento a caso (probabilmente della
   sidebar) e gli ha spostato la posizione senza che ci fosse davvero
   un'intestazione da abbassare. Un'euristica che tocca elementi che
   non le appartengono e' un rischio che non vale il comodo: se una
   pagina avra' davvero un'intestazione fissa da abbassare, si fara'
   apposta su quella pagina, non alla cieca su tutte.
   ------------------------------------------------------------------ */
function faiPosto(){
    var html = document.documentElement;
    if (html.dataset.pomoPosto) return;   // rifarlo raddoppierebbe il padding
    html.dataset.pomoPosto = '1';

    /* La classe adesso puo' esserci gia': la scrive il server nel <head>
       (pomodoro_barra.php), perche' lo spazio va fatto PRIMA che la pagina si
       disegni, non dopo. Ma il padding del body va misurato com'era SENZA di
       noi: con la classe attiva leggeremmo il nostro stesso padding e lo
       sommeremmo a se' stesso a ogni giro. Si toglie, si misura, si rimette —
       tutto dentro la stessa esecuzione, quindi il browser non disegna niente
       nel mezzo e non si vede nessun tremolio. */
    var eraGiaAttiva = html.classList.contains('pomo-bar-attiva');
    if (eraGiaAttiva) html.classList.remove('pomo-bar-attiva');

    var padPrima = window.getComputedStyle(document.body).paddingTop;
    html.style.setProperty('--pomo-bar-pad', padPrima && padPrima !== 'auto' ? padPrima : '0px');

    html.classList.add('pomo-bar-attiva');
    barra.style.display = '';

    /* L'animazione di discesa serve SOLO quando la barra compare a pagina gia'
       aperta — si e' fatto partire un pomodoro e la pagina si sposta: li' il
       movimento spiega lo spostamento. All'apertura di una pagina non spiega
       niente, perche' non c'e' nessuno spostamento da spiegare: c'e' solo una
       barra che scende addosso a un contenuto gia' fermo, ed e' proprio quella
       la cosa che si vedeva scattare. */
    if (!html.dataset.pomoPreacceso) barra.classList.add('is-entrata');
}

function togliPosto(){
    /* Anche i segnalini, se no una barra che riparte piu' tardi nella stessa
       pagina troverebbe "gia' fatto" e non si prenderebbe piu' il suo spazio. */
    delete document.documentElement.dataset.pomoPosto;
    delete document.documentElement.dataset.pomoPreacceso;
    barra.classList.remove('is-entrata');
    document.documentElement.classList.remove('pomo-bar-attiva');
    barra.style.display = 'none';
    chiudiPopup();
}

/* ------------------------------------------------------------------
   IL DISEGNO
   ------------------------------------------------------------------ */
function colore(){
    return PomoCore.modello().progettoColore || COLORE_BASE;
}

function nomeProgetto(){
    return PomoCore.modello().progettoNome || '';
}

function disegna(){
    var fase = PomoCore.fase();
    if (fase === 'idle') { togliPosto(); return; }

    /* Il pomodoro puo' anche COMPARIRE: se lo si avvia dal telefono, il
       controllo periodico se ne accorge e la barra deve tornare su una
       pagina che si era gia' liberata. faiPosto() non fa niente se lo
       spazio c'e' gia'. */
    faiPosto();

    var t = PomoCore.tempi();
    var inPausa = t.inPausa;

    barra.classList.toggle('is-pausa', inPausa);
    popup.classList.toggle('is-pausa', inPausa);
    // in pausa il colore lo decide il CSS: la pausa non e' del progetto
    if (!inPausa) {
        barra.style.setProperty('--pomo-bar-col', colore());
        popup.style.setProperty('--pomo-bar-col', colore());
    } else {
        barra.style.removeProperty('--pomo-bar-col');
        popup.style.removeProperty('--pomo-bar-col');
    }

    elTesto.textContent = etichetta(fase, inPausa);
    barra.classList.toggle('is-finita', fase === 'completed' || fase === 'pausa_done');
    /* La modalita' non e' un vezzo della barra: e' la stessa preferenza
       "clessidra" che gia' esisteva, salvata per l'utente e uguale su ogni
       dispositivo. Qui decide se la barra avanza o si limita a segnalare —
       PRIMA di chiamare riempiLinea, che la legge. */
    barra.classList.toggle('is-indicatore', PomoCore.modello().stile === 'clessidra');

    riempiLinea(fase, t);
    programmaFine(fase, t);
    aggiornaTasti(fase);
    if (popup.classList.contains('is-aperto')) battitoPopup();
}

function etichetta(fase, inPausa){
    /* Il nome del progetto e' tornato: "Focusing" da solo non diceva
       niente di utile, il progetto si'. Resta un ripiego per le sessioni
       senza progetto collegato. */
    if (inPausa) return fase === 'pausa_done' ? (T.pausa_conclusa || '') : (T.pausa_in_corso || '');
    if (fase === 'completed')   return T.completato || '';
    if (fase === 'paused_task') return T.sessione_in_pausa || '';
    return nomeProgetto() || T.concentrazione || '';
}

/* La linea: si scrive la larghezza di adesso senza transizione, si
   costringe il browser a prenderne atto, poi gli si dice di arrivare al
   fondo nel tempo che manca. Da li' in avanti il JavaScript non tocca
   piu' niente e il movimento e' continuo invece che a scatti. */
var pezzi = [document.getElementById('pomoBarraLinea'),
             document.getElementById('pomoBarraVelo')];

function larghezza(percento, secondi){
    for (var i = 0; i < pezzi.length; i++) {
        if (!pezzi[i]) continue;
        pezzi[i].style.transition = secondi ? ('width ' + secondi.toFixed(1) + 's linear') : 'none';
        pezzi[i].style.width = percento.toFixed(2) + '%';
    }
}

var timerQuasi = null;

function riempiLinea(fase, t){
    barra.classList.remove('is-quasi');
    if (timerQuasi) { clearTimeout(timerQuasi); timerQuasi = null; }

    /* Modalita' indicatore: niente avanzamento, niente battito prima della
       fine. L'unico segnale resta is-finita, gia' impostato da disegna()
       PRIMA di questa chiamata — e' cosi' che l'utente "vede solo quando
       termina" senza che la barra lo distragga mentre il tempo passa. */
    if (barra.classList.contains('is-indicatore')) return;

    larghezza(Math.max(0, Math.min(1, t.frazione)) * 100, 0);
    void barra.offsetWidth;                       // senza questo il browser fonde le due scritture

    var restanti = secondiAllaFine(fase, t);
    if (restanti === null) {
        if (fase === 'completed' || fase === 'pausa_done') larghezza(100, 0);
        return;
    }

    larghezza(100, restanti);

    /* l'ultimo decimo pulsa piano: e' l'unico avviso prima della fine, e
       dice "sta per finire" senza far guardare l'orologio */
    var totale = durataDellaFase(fase);
    var anticipo = totale * 0.1;
    if (restanti <= anticipo) barra.classList.add('is-quasi');
    else timerQuasi = setTimeout(function(){
        if (PomoCore.fase() === fase) barra.classList.add('is-quasi');
    }, (restanti - anticipo) * 1000);
}

function durataDellaFase(fase){
    var M = PomoCore.modello();
    if (fase === 'pausa_running') return (M.pausaDurataMin || 5) * 60;
    if (fase === 'running' && M.modo === 1) return 3600;
    return (M.durataMin || 25) * 60;
}

function secondiAllaFine(fase, t){
    var M = PomoCore.modello();
    if (fase === 'running') {
        if (M.modo === 0) return Math.max(0, t.mancante);
        return Math.max(0, 3600 - (t.reale % 3600));   // il cronometro chiude un'ora per volta
    }
    if (fase === 'pausa_running') return Math.max(0, t.mancante);
    return null;                                        // fermo: niente da animare
}

/* ------------------------------------------------------------------
   LA FINE DEL TEMPO
   Un solo timeout, non un battito. Quando scade si fa la stessa cosa
   che farebbe la pagina pomodoro, chiamando lo stesso endpoint: se
   sono aperte tutt'e due, la seconda chiamata non trova piu' niente da
   chiudere e non fa danni.
   ------------------------------------------------------------------ */
function programmaFine(fase, t){
    if (timerFine) { clearTimeout(timerFine); timerFine = null; }
    var restanti = secondiAllaFine(fase, t);
    if (restanti === null) return;

    timerFine = setTimeout(function () {
        timerFine = null;
        var M = PomoCore.modello();

        if (fase === 'running' && M.modo === 0) {
            avvisa(T.concluso, nomeProgetto() ? nomeProgetto() : (T.ottimo_lavoro || ''));
            PomoCore.azione('segna_completato', { session_id: M.sessioneId })
                .then(function () { return PomoCore.aggiorna({ applica: true }); })
                .then(disegna);

        } else if (fase === 'pausa_running') {
            avvisa(T.pausa_finita, T.torna_al_lavoro || '');
            PomoCore.imposta({ fase: 'pausa_done' });
            disegna();

        } else {
            // cronometro: e' passata un'ora, si ricomincia il giro
            disegna();
        }
    }, restanti * 1000 + 400);   // un filo dopo, cosi' il server e' d'accordo con noi
}

function avvisa(titolo, testo){
    try {
        if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(titolo || '', { body: testo || '' });
        }
    } catch (e) {}
}

/* ------------------------------------------------------------------
   IL POPUP
   ------------------------------------------------------------------ */
barra.addEventListener('click', function () {
    if (popup.classList.contains('is-aperto')) chiudiPopup(); else apriPopup();
});
velo.addEventListener('click', chiudiPopup);
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') chiudiPopup(); });

function apriPopup(){
    popup.classList.add('is-aperto');
    velo.classList.add('is-aperto');
    barra.classList.add('is-aperta');
    // il tasto qui sotto mostra gia' se la modalita' e' attiva, il quadrante non cambia mai
    document.getElementById('pomoPopVista').classList.toggle('is-attiva', PomoCore.modello().stile === 'clessidra');
    battitoPopup();
    // qui il secondo conta: un battito al secondo, e solo mentre si guarda
    battito = setInterval(battitoPopup, 1000);
}

function chiudiPopup(){
    popup.classList.remove('is-aperto');
    velo.classList.remove('is-aperto');
    barra.classList.remove('is-aperta');
    if (battito) { clearInterval(battito); battito = null; }
    /* Chiudere e' un ripensamento: il cestino armato e l'avviso di un'azione
       non riuscita non devono ritrovarsi li' alla riapertura. */
    disarmaScarta();
    nota('');
}

function battitoPopup(){
    // il popup mostra sempre il quadrante: la clessidra non ci compare piu'
    var t = PomoCore.tempi();
    elOra.textContent = t.testo;
    elArco.style.strokeDashoffset = (CIRC * (1 - Math.max(0, Math.min(1, t.frazione)))).toFixed(1);
    elProgTesto.textContent = nomeProgetto() || (T.nessun_progetto || '');
    /* Il progetto si cambia solo su una sessione aperta: in pausa non c'e'
       niente a cui attaccarlo, e a pomodoro concluso la riga e' gia' chiusa. */
    var f = PomoCore.fase();
    elProg.hidden = !(f === 'running' || f === 'paused_task');
    popup.classList.toggle('is-finita', t.fuoritempo > 0 && PomoCore.fase() === 'completed');
    elExtra.textContent = '+' + PomoCore.fmtHMS(t.fuoritempo);
}

/* Quali tastini hanno senso adesso. Le azioni sono le stesse della
   pagina pomodoro e passano dagli stessi endpoint: qui non c'e' una
   seconda versione della stessa regola, solo una seconda pulsantiera. */
function aggiornaTasti(fase){
    var mostra = {
        pausaSessione: fase === 'running',
        riprendi:      fase === 'paused_task',
        chiudi:        fase === 'running' || fase === 'paused_task' || fase === 'completed'
                       || fase === 'pausa_running' || fase === 'pausa_done',
        tisana:        fase === 'running' || fase === 'completed',
        extra:         fase === 'completed',
        nuovo:         fase === 'pausa_running' || fase === 'pausa_done',
        /* Scartare vale solo su una sessione ancora aperta — in corso o
           congelata. A pomodoro completato la riga e' gia' chiusa e conta nei
           totali: buttarla via da qui, di nascosto, sarebbe un modo perfetto
           per perdere un'ora di lavoro con un tocco. In pausa non c'e' proprio
           niente da scartare, la pausa non si salva. */
        scarta:        fase === 'running' || fase === 'paused_task'
    };
    for (var k in tasti) {
        if (tasti[k]) tasti[k].hidden = !mostra[k];
    }
}

/* ------------------------------------------------------------------
   L'IDENTIFICATIVO DELLA SESSIONE
   Tutte le azioni che chiudono, estendono o archiviano ne hanno bisogno,
   e senza il server risponde success:false — cioe' il tasto sembra non
   fare niente. Il modello ce l'ha quasi sempre, ma "quasi" non basta per
   una cosa da cui dipendono tre tasti: se manca lo si ripesca dallo stato
   grezzo del server, che e' la stessa fonte da cui il modello e' stato
   riempito.
   ------------------------------------------------------------------ */
function idSessione(){
    var M = PomoCore.modello();
    if (M.sessioneId) return M.sessioneId;
    var st = PomoCore.stato() || {};
    if (st.active && st.active.id) return st.active.id;
    if (st.last && st.last.esito === 'completato' && st.last.id) return st.last.id;
    return 0;
}

/* Una riga sotto ai tasti, e sparisce da sola. Serve nell'unico caso in cui
   prima non succedeva niente di niente: azione rifiutata dal server o rete
   caduta. */
var timerNota = null;
function nota(testo){
    if (!elNota) return;
    elNota.textContent = testo || '';
    elNota.classList.toggle('is-visibile', !!testo);
    if (timerNota) { clearTimeout(timerNota); timerNota = null; }
    if (testo) timerNota = setTimeout(function(){ nota(''); }, 3200);
}

/* ------------------------------------------------------------------
   AGIRE
   Tre cose che prima non c'erano, e sono tutte e tre lo stesso problema
   visto da tre lati — un'azione che non riesce restava invisibile:

   1. un tasto per volta. Due tocchi ravvicinati mandavano due scritture,
      e la seconda arrivava su uno stato che la prima aveva gia' cambiato.
   2. l'esito si guarda. PomoCore.azione() risolve anche quando il server
      dice success:false: qui lo si legge davvero e lo si dice.
   3. c'e' sempre un .catch, e comunque vada si rilegge lo stato e si
      ridisegna. Una promessa che si rompe a meta' lasciava la barra ferma
      su una realta' che non esisteva piu'.
   ------------------------------------------------------------------ */
function agisci(nome, dati, poi){
    if (azioneInCorso) return Promise.resolve(null);
    azioneInCorso = true;
    popup.classList.add('is-occupato');

    return PomoCore.azione(nome, dati || {})
        .then(function (d) {
            /* Il conflitto ha gia' la sua strada: il motore adotta lo stato
               vero e avvisa. Qui non c'e' niente da aggiungere. */
            if (d && d.conflitto) return d;
            if (d && d.success === false) { nota(T.non_riuscito || ''); return d; }
            return PomoCore.aggiorna({ applica: true })
                .then(function () { if (poi) return poi(); })
                .then(function () { return d; });
        })
        .catch(function (e) {
            nota(T.non_riuscito || '');
            if (window.console) console.error('[pomo-barra]', nome, e);
            // lo stato vero e' quello del server, non quello che speravamo
            return PomoCore.aggiorna({ applica: true }).catch(function(){ return null; });
        })
        .then(function (d) {
            azioneInCorso = false;
            popup.classList.remove('is-occupato');
            disegna();
            return d;
        });
}

tasti.pausaSessione.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('pausa_task', {
        session_id: idSessione(),
        // l'arrotondamento lo decide il motore: congelare dalla barra e
        // congelare dalla pagina devono scrivere lo stesso numero
        tempo_esatto: PomoCore.tempoDaCongelare()
    });
});

tasti.riprendi.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('riprendi_task', { session_id: idSessione() });
});

tasti.chiudi.addEventListener('click', function (e) {
    e.stopPropagation();
    var fase = PomoCore.fase();
    if (fase === 'pausa_running' || fase === 'pausa_done') { agisci('fine_pausa'); return; }
    if (fase === 'completed') { agisci('archivia_completato', { session_id: idSessione() }); return; }
    agisci('interrompi', { session_id: idSessione(), elimina: 0 });
});

tasti.tisana.addEventListener('click', function (e) {
    e.stopPropagation();
    var M = PomoCore.modello();
    var fase = PomoCore.fase();
    var sid  = idSessione();
    /* Prima si chiude la sessione, poi parte la pausa: mai il contrario,
       altrimenti per un istante esistono tutt'e due e il tempo della pausa
       finirebbe dentro al pomodoro. La seconda azione sta nel "poi" di
       agisci(), cosi' anche questa catena passa dal controllo dell'esito e
       dal .catch invece di correre per conto suo. */
    agisci(fase === 'completed' ? 'archivia_completato' : 'interrompi',
           fase === 'completed' ? { session_id: sid } : { session_id: sid, elimina: 0 },
           function () {
               return PomoCore.azione('avvia_pausa', {
                   project_id: M.progettoId || 0,
                   durata_minuti: M.durataPausaMin || 5
               }).then(function () { return PomoCore.aggiorna({ applica: true }); });
           });
});

tasti.extra.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('estendi', { session_id: idSessione() });
});

tasti.nuovo.addEventListener('click', function (e) {
    e.stopPropagation();
    var M = PomoCore.modello();
    agisci('fine_pausa', {}, function () {
        return PomoCore.azione('start', {
            mode: 0,
            durata_minuti: M.durataSceltaMin || 25,
            project_id: M.progettoId || 0
        }).then(function () { return PomoCore.aggiorna({ applica: true }); });
    });
});

/* ------------------------------------------------------------------
   SCARTARE
   L'unica azione del popup che non si puo' disfare: la riga sparisce dal
   database, quindi quel tempo non conta piu' da nessuna parte — ne' nei
   totali del progetto ne' nello storico. Percio' il primo tocco non
   cancella, arma: il tasto diventa rosso pieno e chiede di ripetere. E'
   il minimo indispensabile per non perdere un'ora di lavoro con un tocco
   sbagliato dentro un popup piccolo, e costa meno di una finestra di
   conferma che qui dentro non ci starebbe.
   ------------------------------------------------------------------ */
if (tasti.scarta) {
    tasti.scarta.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!scartaArmato) {
            tasti.scarta.classList.add('is-armato');
            nota(T.scarta_conferma || '');
            // cinque secondi: passati quelli l'intenzione non c'e' piu'
            scartaArmato = setTimeout(disarmaScarta, 5000);
            return;
        }
        disarmaScarta();
        agisci('interrompi', { session_id: idSessione(), elimina: 1 }, function () {
            nota(T.scartata || '');
        });
    });
}

/* ------------------------------------------------------------------
   CARICARE UN PEZZO DI PAGINA AL VOLO
   Lo usa il selettore dei progetti: il file gia' esistente, preso cosi'
   com'e' invece che riscritto in piccolo. Resta una funzione a parte,
   e non dentro il click che la chiama, perche' un domani potrebbe
   servire ad altro allo stesso modo.
   Gli <script> arrivati dentro innerHTML non partono — e' una difesa del
   browser, non un difetto — quindi si ricreano uno per uno, che e' lo
   stesso mestiere che sidebar.php fa lato server.
   ------------------------------------------------------------------ */
function caricaFrammento(url, dove){
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var culla = document.createElement('div');
            culla.innerHTML = html;
            var script = culla.querySelectorAll('script');
            for (var i = 0; i < script.length; i++) script[i].parentNode.removeChild(script[i]);
            dove.insertBefore(culla, dove.firstChild);
            for (var k = 0; k < script.length; k++) {
                var s = document.createElement('script');
                s.textContent = script[k].textContent;
                document.body.appendChild(s);
            }
        });
}

/* ------------------------------------------------------------------
   CAMBIARE PROGETTO
   Il selettore e' lo stesso di tutte le altre pagine: non una versione
   ridotta, che poi andrebbe tenuta allineata a mano ogni volta che
   quello vero cambia.
   ------------------------------------------------------------------ */
var cullaSelettore = document.createElement('div');
cullaSelettore.style.display = 'none';
document.body.appendChild(cullaSelettore);

elProg.addEventListener('click', function (e) {
    e.stopPropagation();
    if (selettoreChiesto) { apriSelettore(); return; }
    selettoreChiesto = true;
    caricaFrammento(D.selettore, cullaSelettore)
        .then(apriSelettore)
        .catch(function () { selettoreChiesto = false; });
});

function apriSelettore(){
    var modale = document.getElementById(D.modale);
    if (!modale || !window.psOpen) return;
    /* il selettore si apre gia' aperto sul progetto di adesso, cosi' non
       tocca ritrovarlo nell'albero ogni volta */
    var M = PomoCore.modello();
    if (window.psSetValue && M.progettoId) {
        window.psSetValue(D.modale, M.progettoId, M.progettoNome, M.progettoColore);
    }
    // il popup si toglie di mezzo: due finestre sovrapposte sono una di troppo
    chiudiPopup();
    window.psOpen(modale);
}

/* L'annuncio del selettore vale per tutta la pagina, quindi si controlla da
   quale arriva: su una pagina che ne ha gia' uno suo, la nostra scelta e la
   sua finirebbero mescolate. */
document.addEventListener('projectselected', function (e) {
    if (!e.detail || e.detail.uid !== 'pomobarra') return;
    var M = PomoCore.modello();
    PomoCore.imposta({
        progettoId: e.detail.id || 0,
        progettoNome: e.detail.name || '',
        progettoColore: e.detail.color || ''
    });
    disegna();
    agisci('set_project', { session_id: M.sessioneId, project_id: e.detail.id || 0 });
});

/* ------------------------------------------------------------------
   IL TASTO VISTA — non piu' un cambio d'aspetto del popup
   Il popup mostra sempre lo stesso quadrante: quello che questo tasto
   cambia adesso e' la BARRA, non se' stesso. E' la stessa preferenza di
   sempre — "cerchio" o "clessidra", salvata per l'utente e uguale su
   ogni dispositivo — solo che invece di scegliere una forma sceglie se
   la barra avanza (progress bar) o si limita a segnalare che qualcosa
   sta girando (indicatore), per non tenere sott'occhio il tempo che
   passa. Vedi is-indicatore in riempiLinea().
   ------------------------------------------------------------------ */
var btnVista = document.getElementById('pomoPopVista');
btnVista.addEventListener('click', function (e) {
    e.stopPropagation();
    var verso = PomoCore.modello().stile === 'clessidra' ? 'cerchio' : 'clessidra';
    PomoCore.azione('set_display_style', { stile: verso });
    PomoCore.imposta({ stile: verso });
    btnVista.classList.toggle('is-attiva', verso === 'clessidra');
    disegna();
});

/* ------------------------------------------------------------------
   AVVIO
   Sta in fondo al file e non e' un dettaglio di stile: e' quello che
   evita per sempre questa intera classe di errore. Una var dichiarata
   piu' in basso esiste gia' come nome quando lo script parte (var viene
   issata), ma vale undefined finche' l'esecuzione non arriva alla sua
   riga di assegnazione. Chiamare qui una funzione che legge una di
   quelle var — come e' successo due volte, con spostati e poi con
   pezzi — vuol dire leggerla prima che sia stata popolata, e lo script
   si ferma li' con un errore, portandosi via anche tutto quello scritto
   dopo: gli ascoltatori dei tasti, il popup, il selettore progetto.
   Da qui in fondo, invece, ogni var e ogni funzione del file sopra
   e' gia' a posto: non c'e' piu' un "dopo" in cui può nascondersi lo
   stesso problema. */
PomoCore.init({
    stato: D.stato,
    endpoint: D.endpoint,
    limite: D.limite,
    applica: true          // qui il modello lo costruisce il motore: non c'e' altra fonte
});

/* Lo spazio in cima va misurato PRIMA di prendersela: il padding che il
   body aveva se lo tiene, il nostro si aggiunge. */
faiPosto();

PomoCore.on('cambiato', function () { disegna(); });
PomoCore.avviaControllo({ attivo: 30000, riposo: 180000 });

disegna();

})();
