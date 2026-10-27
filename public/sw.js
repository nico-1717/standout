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
 *     UNICA ECCEZIONE: la home di chi e' connesso. Il suo HTML e' solo uno
 *     scheletro (nome, nomi delle pagine, token): i dati veri li disegna il
 *     JavaScript dalla copia salvata nel browser. La pagina avverte con
 *     l'intestazione X-Standout-Shell (la mette index.php), cosi' senza rete la
 *     home si apre invece di mostrare offline.html. La copia si butta quando
 *     si esce (logout.php) o quando la home risponde da disconnessi.
 *  2. tutto cio' che ha una query string non si tocca: gli endpoint del sito
 *     rispondono a ?ajax=..., sono dati vivi. Eccezione: i file statici con la
 *     sola versione (?v=123), che il sito cambia a ogni modifica del file.
 *  3. si salvano solo i file statici (css, js, svg, immagini, font), e solo
 *     dopo averli chiesti alla rete.
 */

/* Cambiare questo numero a ogni rilascio: al primo avvio la vecchia cache
   viene cancellata per intero, quindi non restano avanzi di versioni passate. */
const VERSIONE = 'standout-v4';

/* La copia della home: vive a parte e sopravvive al cambio di versione sopra
   (se ne occupa il fetch, non l'activate). */
const SHELL = 'standout-shell-v1';
const CHIAVE_HOME = new URL('__home_shell', self.registration.scope).href;

const OFFLINE = './offline.html';

/* Il minimo indispensabile per avere qualcosa da mostrare senza rete. */
const PRECARICATI = [
  OFFLINE,
  './_assets/icons/icon-192.png'
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
        chiavi.filter((k) => k !== VERSIONE && k !== SHELL).map((k) => caches.delete(k))
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
  // (tranne i file statici con la sola versione, ?v=123)
  const versionato = /^\?v=\d+$/.test(url.search) && STATICI.test(url.pathname);
  if (url.search !== '' && !versionato) return;

  // regola 1: le pagine non si salvano, si sostituiscono solo quando manca la rete
  if (richiesta.mode === 'navigate') {
    const eHome = url.search === '' && /(?:^|\/)(?:index\.php)?$/.test(url.pathname);
    // uscendo, la copia della home di chi esce non deve restare
    if (/logout\.php$/.test(url.pathname)) evento.waitUntil(caches.delete(SHELL));
    evento.respondWith(
      fetch(richiesta)
        .then((risposta) => {
          if (eHome && risposta && risposta.ok) {
            if (risposta.headers.get('X-Standout-Shell') === 'home') {
              const copia = risposta.clone();
              evento.waitUntil(caches.open(SHELL).then((c) => c.put(CHIAVE_HOME, copia)));
            } else {
              // la home di chi non e' connesso: nessuna copia da tenere
              evento.waitUntil(caches.delete(SHELL));
            }
          }
          return risposta;
        })
        .catch(() => (eHome
          ? caches.open(SHELL).then((c) => c.match(CHIAVE_HOME)).then((r) => r || caches.match(OFFLINE))
          : caches.match(OFFLINE)))
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
          caches.open(VERSIONE).then(async (cache) => {
            // di un file versionato resta solo l'ultima versione: le vecchie
            // (?v=...) non servirebbero piu' e si accumulerebbero a ogni rilascio
            if (versionato) {
              for (const k of await cache.keys()) {
                const u = new URL(k.url);
                if (u.pathname === url.pathname && u.search !== url.search) await cache.delete(k);
              }
            }
            await cache.put(richiesta, copia);
          });
        }
        return risposta;
      })
      .catch(() => caches.match(richiesta))
  );
});
