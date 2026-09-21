# Precaricamento delle carte e anteprima dello swipe — `game_fc.php`

Questo documento spiega due meccanismi collegati del gioco a flashcard: come vengono
precaricate le posizioni future (per rendere il gioco veloce) e come quel
precaricamento viene usato per mostrare un'anteprima della carta successiva già
durante l'animazione dello swipe (per rendere il gioco fluido).

---

## 1. Concetti di base

**Posizione.** L'intera sequenza della partita è decisa una volta sola all'avvio
(tabella `fc_game_sequence`, scritta da `start_game_fc.php` o `start_retry.php`).
Visitare una posizione qualunque, in qualunque momento, è quindi una **pura lettura**:
non altera la partita, non crea duplicati logici.

**Una posizione è una pagina intera**, non una singola carta: è un padre più tutti i
suoi eventuali figli, mostrati insieme. Precaricare "N posizioni avanti" significa
scaricare N pagine complete. Se un concetto ha in media 2-3 figli, precaricarne 5
vuol dire tenere pronte 10-15 carte.

**Effetto collaterale da ricordare.** Visitare una posizione — anche solo per
precaricarla — crea comunque la sua riga in `fc_gameplays` (`INSERT IGNORE`), con
risposta vuota o, per le carte titolo, il segnaposto `'Titolo'`. Qualunque logica
che debba capire "fin dove il giocatore è arrivato *davvero*" deve escludere
esplicitamente sia le righe senza risposta sia quelle con `'Titolo'`, altrimenti
conta come "raggiunto" anche ciò che è stato solo scaricato in anticipo dietro le
quinte. È un bug reale in cui siamo incappati e che è stato corretto: la barra di
progresso arrivava molto più avanti di quanto il giocatore avesse davvero risposto.

---

## 2. Il precaricamento: finestra e ondate

### La finestra

Si tengono sempre pronte le **5 posizioni successive** e le **3 precedenti** rispetto
a quella corrente:

```js
var PREFETCH_AVANTI = 5;
var PREFETCH_INDIETRO = 3;
```

Avanzando di una posizione, entra nella finestra solo quella più lontana in avanti,
ed esce quella più lontana indietro: non si accumula mai più di questo in memoria.

La pulizia (`pulisciCacheFuoriFinestra(posAttuale, totale)`) avviene **prima** di
richiedere qualunque cosa nuova — sia sulla cache sia sulla coda di richieste in
attesa — così non si spreca lavoro per qualcosa che sta comunque per essere buttato
via.

### Le tre ondate

Le richieste **non partono mai tutte insieme**: ogni posizione è una pagina pesante
(query al database, calcolo degli errori, delle categorie...), e chiederne molte in
parallelo su hosting condiviso causa rifiuti di connessione. La priorità decresce nel
tempo:

| Ondata | Quando | Cosa | Perché |
|---|---|---|---|
| 1 | Subito, da sola | posizione +1 | Serve entro un secondo: la usa anche l'anteprima dello swipe (§3) |
| 2 | Dopo 500ms | posizioni +2, +3 | Per chi sfoglia veloce |
| 3 | Dopo altri 1200ms (1700ms totali) | posizioni +4, +5, e le 3 indietro | Il "casomai": non deve rubare banda a niente di più urgente |

```js
if (!totale || pos + 1 <= totale) prefetchGet(urlPerPosizione(pos + 1, true));

function accodaIntervallo (da, a) {
    for (var i = da; i <= a; i++) {
        if (totale && pos + i > totale) return;
        accodaPrefetch(urlPerPosizione(pos + i, true));
    }
}

__ondataPrefetch2 = setTimeout(function () {
    accodaIntervallo(2, 3);
    __ondataPrefetch3 = setTimeout(function () {
        accodaIntervallo(4, PREFETCH_AVANTI);
        for (var j = 1; j <= PREFETCH_INDIETRO; j++) {
            if (pos - j < 1) break;
            accodaPrefetch(urlPerPosizione(pos - j, true));
        }
    }, 1200);
}, 500);
```

Se nel frattempo si cambia posizione, le ondate 2 e 3 ancora in attesa vengono
**annullate** (`clearTimeout`): altrimenti arriverebbero a chiedere pagine ormai
fuori dalla finestra, proprio mentre servono quelle nuove.

### La coda e il parallelismo

Tutte le richieste tranne la primissima passano da una coda (`__codaPrefetch`), con
al massimo **2 richieste contemporanee** (`PREFETCH_PARALLELE`). Quando una finisce
(successo o fallimento), la coda fa partire automaticamente la prossima.

### La cache

`__prefetchCache` è un oggetto indicizzato per URL. Ogni voce ha la forma:

```js
{ html: null | string, resUrl: string, promise: Promise }
```

- Una richiesta fallita viene rimossa dalla cache (si può ritentare più avanti).
- Se il server risponde con un redirect (fine partita → `result_fc.php`), la voce
  viene scartata senza seguirlo: seguirlo caricherebbe la pagina dei risultati —
  che fa pulizia dei dati — mentre si sta ancora giocando.

### Il parametro `&prefetch=1`

Ogni URL precaricato porta questo parametro in più. Il server ha una regola: se
chiedi una posizione più avanti della prima ancora da rispondere, ti rimanda
indietro a quella. Regola giusta per una visita vera, sbagliata per un
precaricamento — altrimenti la richiesta di background finirebbe rimandata alla
posizione corrente invece che a quella richiesta, e la cache conterrebbe la pagina
sbagliata. Con questo parametro il server salta il rimando.

### Quando parte

`schedulePrefetch()` viene chiamata al caricamento iniziale della pagina e dopo ogni
transizione completata, sempre basandosi su `data-pos` e `data-total-words` letti
dal contenitore corrente (`curC()`).

---

## 3. L'anteprima durante lo swipe

**Obiettivo**: quando si trascina l'ultima carta di una posizione (o un padre senza
figli) verso il bordo per votarla, la carta successiva deve comparire *da subito*
sotto quella che sta uscendo, invece di lasciare uno spazio bianco durante
l'animazione. Fatta bene, le due animazioni (carta che esce + pagina nuova che entra)
si fondono in una sola, e l'utente non percepisce nessun "cambio pagina".

Questa funzionalità è stata complessa da ottenere perché ogni versione tentata
inizialmente rompeva qualcos'altro: la carta finiva contata come "ancora da
rispondere" (bloccando l'avanzamento vero), oppure restava appesa sopra la pagina
nuova, oppure appariva più larga della carta reale con uno scatto finale, oppure
faceva ripartire una seconda animazione via da sotto. Le sezioni seguenti spiegano
come ciascuno di questi problemi è stato risolto.

### 3.1 Quando si crea

`creaAnteprima(cardTrascinata)` viene chiamata in due punti:

- `activateSwipe()`, cioè quando il gesto di trascinamento diventa "attivo" (dito
  spostato oltre una soglia minima) — copre lo swipe con il dito;
- `gradeFeedback()`, quando si preme il tasto Corretto/Sbagliato invece di
  trascinare — stessa animazione di uscita, stessa anteprima, aperta subito al
  massimo invece che gradualmente (con i tasti non c'è un trascinamento da seguire).

La funzione si ferma subito (senza fare nulla) se:

- l'anteprima è disattivata (`ANTEPRIMA_ATTIVA`, un interruttore unico);
- la posizione corrente ha ancora più di una carta non valutata (si sta per restare
  sul posto, non per cambiare pagina — coincide sia con "ultimo figlio di una lista"
  sia con "padre senza figli", perché in entrambi i casi resta *una sola* carta non
  valutata);
- la posizione è sconosciuta o non c'è un contenitore da cui prendere la carta di
  riferimento.

### 3.2 Come recupera il contenuto

Cerca in `__prefetchCache` l'indirizzo della posizione successiva (con
`&prefetch=1`, §2). Tre casi:

1. **Già in cache**: costruisce l'anteprima subito.
2. **Richiesta in corso** (esiste una `promise` ma non ancora l'`html`): si aggancia
   a quella stessa promise e costruisce l'anteprima appena arriva — anche a
   trascinamento già iniziato.
3. **Non richiesta affatto**: la chiede lei stessa (`prefetchGet(url)`) e si aggancia
   allo stesso modo.

Questo terzo caso è una rete di sicurezza: nella pratica, grazie al precaricamento
(§2), la posizione successiva è quasi sempre già in cache o in arrivo.

### 3.3 Come viene costruita (`costruisciAnteprima`)

```
html ricevuto
   │
   ├─ parsing con DOMParser (non si inserisce l'HTML grezzo in pagina)
   │
   ├─ si prende SOLO la prima carta (il padre)
   │     → se la posizione successiva ha molti figli, farli salire tutti
   │       insieme sarebbe confuso e pesante; arrivano con la pagina vera,
   │       subito dopo lo scambio
   │
   ├─ si clona quel nodo
   │     → marcato con la classe .anteprima-card
   │     → tolto ogni id (per non duplicarne in pagina)
   │     → tolto l'attributo data-swipe (non deve essere trascinabile)
   │
   ├─ si converte il testo grezzo in HTML leggibile
   │     → il testo delle carte non sta nell'HTML pronto: sta in data-raw,
   │       e viene normalmente convertito da renderAllRawTexts() quando il
   │       SERVER disegna la pagina vera. L'anteprima, essendo costruita
   │       lato client da HTML "crudo", deve rifare questo passaggio da sé
   │       con parseTextForPreview(), altrimenti si vede l'etichetta
   │       "QUESTION" ma senza la domanda dentro
   │
   ├─ si disegnano le formule (fcdDisegnaFormule), DOPO la conversione del
   │  testo — le formule vanno cercate nel testo già leggibile, non in
   │  quello ancora codificato
   │
   ├─ si posiziona:
   │     → left/width copiati dalla carta vera (misurati con offsetLeft/
   │       offsetWidth), MAI left:0/right:0: un elemento assoluto si
   │       allinea al bordo interno del contenitore ignorandone il rientro
   │       laterale, quindi un'anteprima a piena larghezza risultava più
   │       larga della carta reale — da cui uno scatto di adattamento visibile
   │       a fine animazione
   │     → top preso dallo scroll corrente della pagina (deve comparire
   │       dove comparirà la carta VERA, cioè in cima all'area visibile
   │       della pagina nuova — non all'altezza della carta trascinata, che
   │       con più figli può stare a metà pagina)
   │
   └─ si inserisce, ferma e già a piena opacità (non sale da sotto: deve
      sembrare che fosse lì da sempre, solo coperta dalla carta che esce)
```

### 3.4 Perché `.anteprima-card` è cruciale

Il clone è un `.card-box` a tutti gli effetti agli occhi del resto del codice. Senza
marcarlo, viene **contato** insieme alle carte vere da ogni selettore che verifica
"quante carte restano da valutare qui" — il conteggio passa da 1 a 2, e il gioco
conclude erroneamente "ci sono altri figli, resto qui" invece di navigare alla
posizione successiva. Era esattamente la causa del bug "mostra la carta giusta ma
il gioco non avanza davvero".

La correzione: la classe `.anteprima-card` viene esclusa esplicitamente in **ogni**
punto che conta o cerca carte (conteggio per la navigazione, ricerca del prossimo
figlio da rispondere, selezione da tastiera, aggancio dello swipe, lettura vocale
automatica) — dodici punti in tutto nel codice attuale.

### 3.5 Il contenitore giusto, non "quello corrente"

Il contenitore in cui inserire l'anteprima viene catturato **subito**, risalendo dal
nodo della carta trascinata (`cardTrascinata.closest('.container')`), e portato con
sé fino al momento dell'inserimento — non letto di nuovo con `curC()` in quel
momento. Se la risposta dal server arriva con un filo di ritardo, `curC()` potrebbe
nel frattempo puntare già alla pagina **nuova**: leggerlo tardi avrebbe fatto finire
l'anteprima dentro la pagina appena arrivata, restandoci sopra per sempre (le "carte
sovrapposte" viste durante lo sviluppo).

`costruisciAnteprima` verifica comunque, appena prima di inserire, che quel
contenitore sia ancora quello in vista (`cont === curC()`) e ancora collegato al
documento (`cont.isConnected`): se nel frattempo la pagina è già cambiata,
l'anteprima viene abbandonata invece di comparire nel posto sbagliato.

### 3.6 La fine: lo scambio muto

Quando il voto viene confermato dal server e la pagina vera arriva, `navigateTo`
controlla se un'anteprima era in corso (`window.anteprimaInCorso()`) **prima** di
rimuoverla:

```js
var scambioMuto = !!(window.anteprimaInCorso && window.anteprimaInCorso());
document.body.appendChild(newC);
if (window.togliAnteprima) window.togliAnteprima(true);   // solo ORA si toglie
if (scambioMuto && newFeed) newFeed.scrollTop = 0;
```

Se `scambioMuto` è vero, la pagina nuova mostra **esattamente la stessa cosa** che
l'anteprima stava già mostrando: farla entrare con la sua normale animazione (che
scorre dal basso) sarebbe un secondo movimento percepibile, ridondante col primo.
Invece:

- la durata dell'animazione di ingresso si azzera (`D = 1`);
- l'intestazione vecchia sparisce subito;
- lo scroll della pagina nuova viene forzato in cima (`scrollTop = 0`), perché deve
  coincidere esattamente con dove si trovava l'anteprima — altrimenti, ereditando lo
  scroll della pagina precedente, si vedrebbe un salto verso l'alto o verso il basso
  proprio nell'istante dello scambio.

L'ordine delle tre operazioni sopra è l'unico che funziona: se si controllasse
`anteprimaInCorso()` **dopo** averla già rimossa, il controllo troverebbe sempre
"nessuna anteprima" e lo scambio muto non scatterebbe mai — bug in cui si è
incappati realmente durante lo sviluppo.

### 3.7 Se il gesto viene annullato

Se il dito torna verso il centro invece di raggiungere la soglia di rilascio,
`togliAnteprima(subito = false)` fa sparire l'anteprima con una breve dissolvenza
(180ms) invece di lasciarla lì: il trascinamento non ha più intenzione di cambiare
pagina, quindi l'anteprima ha smesso di avere senso.

---

## 4. Riepilogo visivo del percorso completo

```
Pagina caricata
   │
   ├─ schedulePrefetch(): posizione+1 subito, poi due ondate ritardate (§2)
   │
   ▼
Utente inizia a trascinare l'ultima carta di una posizione verso un bordo
   │
   ├─ activateSwipe() → creaAnteprima(carta)
   │     ├─ trovata in cache? → mostra subito
   │     └─ non ancora? → si aggancia alla richiesta in corso, o la fa partire
   │
   ▼
Anteprima del padre della posizione successiva visibile sotto la carta,
ferma al suo posto esatto (stessa larghezza, stesso rientro, in cima)
   │
   ├─ dito torna al centro → togliAnteprima(): dissolvenza e via
   │
   └─ dito supera la soglia, si rilascia
         │
         ▼
      Voto mandato al server, si attende conferma
         │
         ▼
      navigateTo(): scambioMuto = anteprima ancora presente?
         │
         ├─ SÌ → pagina nuova sostituisce l'anteprima all'istante,
         │        nessuna seconda animazione, scroll forzato in cima
         │
         └─ NO → animazione normale di ingresso dal basso (caso raro:
                  anteprima non ancora arrivata in tempo)
```
