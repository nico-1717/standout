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
var tasti    = {
    pausaSessione: document.getElementById('pomoPopCongela'),
    riprendi:      document.getElementById('pomoPopRiprendi'),
    chiudi:        document.getElementById('pomoPopChiudi'),
    tisana:        document.getElementById('pomoPopTisana'),
    extra:         document.getElementById('pomoPopExtraBtn'),
    nuovo:         document.getElementById('pomoPopNuovo')
};

var CIRC = 2 * Math.PI * 44;     // il raggio dell'arco nel markup
var timerFine = null;            // l'unico timer: scatta a fine tempo previsto
var battito   = null;            // gira solo a popup aperto
var selettoreChiesto = false;

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
    if (html.classList.contains('pomo-bar-attiva')) return;   // gia' fatto: rifarlo raddoppierebbe il padding

    var padPrima = window.getComputedStyle(document.body).paddingTop;
    html.style.setProperty('--pomo-bar-pad', padPrima && padPrima !== 'auto' ? padPrima : '0px');
    html.classList.add('pomo-bar-attiva');
    barra.style.display = '';
}

function togliPosto(){
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
        nuovo:         fase === 'pausa_running' || fase === 'pausa_done'
    };
    for (var k in tasti) {
        if (tasti[k]) tasti[k].hidden = !mostra[k];
    }
}

function agisci(nome, dati, poi){
    return PomoCore.azione(nome, dati || {})
        .then(function () { return PomoCore.aggiorna({ applica: true }); })
        .then(function () { if (poi) return poi(); })
        .then(disegna);
}

tasti.pausaSessione.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('pausa_task', {
        session_id: PomoCore.modello().sessioneId,
        // l'arrotondamento lo decide il motore: congelare dalla barra e
        // congelare dalla pagina devono scrivere lo stesso numero
        tempo_esatto: PomoCore.tempoDaCongelare()
    });
});

tasti.riprendi.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('riprendi_task', { session_id: PomoCore.modello().sessioneId });
});

tasti.chiudi.addEventListener('click', function (e) {
    e.stopPropagation();
    var fase = PomoCore.fase(), M = PomoCore.modello();
    if (fase === 'pausa_running' || fase === 'pausa_done') { agisci('fine_pausa'); return; }
    if (fase === 'completed') { agisci('archivia_completato', { session_id: M.sessioneId }); return; }
    agisci('interrompi', { session_id: M.sessioneId, elimina: 0 });
});

tasti.tisana.addEventListener('click', function (e) {
    e.stopPropagation();
    var M = PomoCore.modello();
    var fase = PomoCore.fase();
    /* Prima si chiude la sessione, poi parte la pausa: mai il contrario,
       altrimenti per un istante esistono tutt'e due e il tempo della pausa
       finirebbe dentro al pomodoro. */
    var chiusura = (fase === 'completed')
        ? PomoCore.azione('archivia_completato', { session_id: M.sessioneId })
        : PomoCore.azione('interrompi', { session_id: M.sessioneId, elimina: 0 });

    chiusura
        .then(function () {
            return PomoCore.azione('avvia_pausa', {
                project_id: M.progettoId || 0,
                durata_minuti: M.durataPausaMin || 5
            });
        })
        .then(function () { return PomoCore.aggiorna({ applica: true }); })
        .then(disegna);
});

tasti.extra.addEventListener('click', function (e) {
    e.stopPropagation();
    agisci('estendi', { session_id: PomoCore.modello().sessioneId });
});

tasti.nuovo.addEventListener('click', function (e) {
    e.stopPropagation();
    var M = PomoCore.modello();
    PomoCore.azione('fine_pausa')
        .then(function () {
            return PomoCore.azione('start', {
                mode: 0,
                durata_minuti: M.durataSceltaMin || 25,
                project_id: M.progettoId || 0
            });
        })
        .then(function () { return PomoCore.aggiorna({ applica: true }); })
        .then(disegna);
});

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
