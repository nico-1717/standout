/* Standout - registrazione del service worker e invito a installare.
 *
 * Perche' in un file a parte invece che in un <script> dentro la pagina:
 * la CSP del sito consente ancora 'unsafe-inline', ma l'obiettivo dichiarato e'
 * toglierlo. Ogni blocco inline nuovo e' un ostacolo in piu' quel giorno, e
 * questo si evita a costo zero. Per lo stesso motivo la fascetta qui sotto non
 * si porta dietro nessuno stile: sta tutto in pwa.css.
 */
(function () {

  /* ============================================================
     1. SERVICE WORKER
     ============================================================ */

  /* Va letto adesso, non dentro il gestore di 'load': document.currentScript
     vale solo mentre lo script viene eseguito, dopo torna null. */
  var tag = document.currentScript;
  var percorsoSw = (tag && tag.dataset && tag.dataset.sw) ? tag.dataset.sw : '/sw.js';

  if ('serviceWorker' in navigator) {
    /* Il raggio d'azione del service worker e' la cartella in cui si trova il
       file: sw.js nella radice del sito vede tutte le pagine, sw.js dentro una
       sottocartella vedrebbe solo quella. E' il motivo per cui questo percorso
       arriva da PWA_BASE e non e' scritto qui. */
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(percorsoSw).catch(function (errore) {
        // non si disturba l'utente: senza service worker il sito funziona uguale,
        // perde solo la pagina di cortesia quando manca la rete
        if (window.console) console.warn('Service worker non registrato:', errore);
      });
    });
  }

  /* ============================================================
     2. INVITO A INSTALLARE (solo iOS)
     ============================================================
     Su iOS non esiste nessun invito automatico: chi non sa del tasto Condividi
     usera' il sito dentro Safari per sempre. Su Android e PC invece il browser
     ci pensa da solo, quindi qui non serve fare niente e una fascetta sarebbe
     solo rumore. */

  var CHIAVE = 'standout_invito_pwa';   // ultima chiusura, in millisecondi
  var SILENZIO = 30 * 24 * 60 * 60 * 1000;  // 30 giorni prima di riproporlo
  var ATTESA = 2500;  // non appena aperta: prima si guarda la pagina, poi arriva

  function eIos() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua)) return true;
    // iPadOS 13+ si spaccia per Mac: si riconosce dal fatto che ha il tocco
    return navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
  }

  function giaInstallata() {
    // navigator.standalone e' la versione iOS, display-mode vale per tutti gli altri
    if (navigator.standalone === true) return true;
    return !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  }

  function browserAdatto() {
    /* Dentro i browser incorporati (Instagram, Facebook, Gmail) il menu
       Condividi non ha la voce "Aggiungi a Home": dare quell'istruzione li'
       manderebbe l'utente a cercare una cosa che non c'e'. */
    var ua = navigator.userAgent || '';
    return !/FBAN|FBAV|Instagram|Line|Twitter|LinkedInApp|MicroMessenger/.test(ua);
  }

  function silenziata() {
    try {
      var quando = parseInt(localStorage.getItem(CHIAVE) || '0', 10);
      return quando > 0 && (Date.now() - quando) < SILENZIO;
    } catch (e) {
      // navigazione privata: localStorage puo' lanciare. Meglio non mostrare
      // niente che mostrare una fascetta che non si riesce piu' a chiudere.
      return true;
    }
  }

  function ricorda() {
    try { localStorage.setItem(CHIAVE, String(Date.now())); } catch (e) {}
  }

  function mostraInvito() {
    var barra = document.createElement('div');
    barra.className = 'pwa-invito';
    barra.setAttribute('role', 'note');

    var icona = document.createElement('span');
    icona.className = 'pwa-invito-icona';
    icona.setAttribute('aria-hidden', 'true');
    icona.textContent = '\u2191';   // la freccia del tasto Condividi di iOS

    var testo = document.createElement('p');
    testo.className = 'pwa-invito-testo';
    var inglese = (document.documentElement.lang || '').slice(0, 2) === 'en';
    testo.textContent = inglese
      ? 'Get the full experience: tap Share, then Add to Home Screen.'
      : 'Migliora la tua esperienza: tocca Condividi, poi Aggiungi a Home.';

    var chiudi = document.createElement('button');
    chiudi.type = 'button';
    chiudi.className = 'pwa-invito-chiudi';
    chiudi.setAttribute('aria-label', inglese ? 'Close this tip' : 'Chiudi l\u2019avviso');
    chiudi.textContent = '\u00D7';
    chiudi.addEventListener('click', function () {
      ricorda();
      barra.remove();
    });

    barra.appendChild(icona);
    barra.appendChild(testo);
    barra.appendChild(chiudi);
    document.body.appendChild(barra);

    // la classe arriva dopo l'inserimento, altrimenti il browser non ha un
    // "prima" da cui far partire la transizione e la fascetta appare di scatto
    requestAnimationFrame(function () { barra.classList.add('pwa-invito-dentro'); });
  }

  if (eIos() && !giaInstallata() && browserAdatto() && !silenziata()) {
    window.addEventListener('load', function () {
      window.setTimeout(mostraInvito, ATTESA);
    });
  }
})();
