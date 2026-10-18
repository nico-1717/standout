/* Standout - service worker.
 *
 * Perche' e' scritto in modo cosi' prudente: le pagine sono ancora in
 * evoluzione. Una cache che risponde per prima trasforma ogni correzione in
 * mezz'ora persa a capire perche' "non arriva". Qui la rete vince sempre; la
 * copia salvata entra in scena solo quando la rete non risponde.
 *
 * Regole, in ordine di importanza:
 *  1. le pagine (.php) non si salvano MAI: contengono i tuoi dati e cambiano a
 *     ogni modifica. Senza rete si mostra offline.html e basta.
 *  2. tutto cio' che ha una query string non si tocca: gli endpoint del sito
 *     rispondono a ?ajax=..., sono dati vivi.
 *  3. si salvano solo i file statici (css, js, svg, immagini, font), e solo
 *     dopo averli chiesti alla rete.
 */

/* Cambiare questo numero a ogni rilascio: al primo avvio la vecchia cache
   viene cancellata per intero, quindi non restano avanzi di versioni passate. */
const VERSIONE = 'standout-v1';

const OFFLINE = './offline.html';

/* Il minimo indispensabile per avere qualcosa da mostrare senza rete. */
const PRECARICATI = [
  OFFLINE,
  './icons/icon-192.png'
];

/* Estensioni considerate "statiche": sono le uniche che finiscono in cache. */
const STATICI = /\.(?:css|js|svg|png|jpg|jpeg|gif|webp|ico|woff2?|ttf)$/i;

self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(VERSIONE)
      .then((cache) => cache.addAll(PRECARICATI))
      // senza skipWaiting la versione nuova resterebbe in attesa finche' non
      // chiudi tutte le schede: con un sito che cambia spesso e' solo un intralcio
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches.keys()
      .then((chiavi) => Promise.all(
        chiavi.filter((k) => k !== VERSIONE).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (evento) => {
  const richiesta = evento.request;

  // solo le letture: POST e compagnia devono sempre arrivare al server
  if (richiesta.method !== 'GET') return;

  let url;
  try {
    url = new URL(richiesta.url);
  } catch (e) {
    return;
  }

  // niente domini altrui (Google Maps, meteo): non e' compito nostro
  if (url.origin !== self.location.origin) return;

  // regola 2: query string = dato vivo, si lascia passare senza intromettersi
  if (url.search !== '') return;

  // regola 1: le pagine non si salvano, si sostituiscono solo quando manca la rete
  if (richiesta.mode === 'navigate') {
    evento.respondWith(
      fetch(richiesta).catch(() => caches.match(OFFLINE))
    );
    return;
  }

  // regola 3: file statici, rete per prima e copia di riserva
  if (!STATICI.test(url.pathname)) return;

  evento.respondWith(
    fetch(richiesta)
      .then((risposta) => {
        // si salva solo una risposta sana: una pagina di errore in cache
        // sarebbe peggio di non avere niente
        if (risposta && risposta.ok && risposta.type === 'basic') {
          const copia = risposta.clone();
          caches.open(VERSIONE).then((cache) => cache.put(richiesta, copia));
        }
        return risposta;
      })
      .catch(() => caches.match(richiesta))
  );
});
