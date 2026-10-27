# STANDOUT — Masterplan della parte social

**Versione 2.0 — agosto 2026.** Sostituisce il documento "Project Masterplan —
L'Ecosistema Definitivo della Produttività Sociale" (v1.0), che conteneva scelte
tecniche poi cambiate.

**Rapporto con gli altri documenti:**

- **`STANDOUT_PROGETTO.md` comanda sulle scelte tecniche** (struttura delle
  cartelle, sicurezza, database, hosting, costi, ordine dei lavori). Se questo
  documento e quello dicono cose diverse su come si costruisce, vale quello.
- **Questo documento comanda sul prodotto**: cosa fa la parte social, con quali
  regole, e perché.

**Regola di lettura:** dove c'è scritto *deciso*, non si riapre. Dove c'è scritto
*da decidere*, non si inventa: si chiede al proprietario.

---

## 1. La missione

I social di oggi (Instagram, TikTok, Facebook) premiano l'estetica e la quantità.
LinkedIn premia la facciata professionale. Manca il posto dove **si viene
riconosciuti per come si ragiona**.

> Il luogo dove una persona ambiziosa apre l'app per studiare e, nel frattempo,
> trova i collaboratori giusti — o una persona con cui parlare — in base a quello
> che ha davvero nella testa.

Tre bisogni concreti, raccontati da chi ha ideato il progetto e da cui nasce
tutto il resto:

1. *"Vorrei lavorare a un progetto che mi appassiona con persone in gamba, ma
   trovarle è impossibile."* Chi ha tempo libero e voglia di fare qualcosa —
   raccogliere rifiuti in città, un'app, un progetto educativo — non trova né i
   progetti né le persone. Facebook non aiuta, i gruppi sono dispersivi.
2. *"Studio da casa e non riesco a costruirmi una rete."* Chi non frequenta in
   presenza perde tutto il networking che nasce dai corridoi.
3. *"Le persone che incontro non hanno la mia stessa testa."* Vale per le
   amicizie quanto per le relazioni: cercare ambizione su Instagram o in
   discoteca non funziona, e su LinkedIn non conosci nessuno davvero.

**La produttività è il cavallo di Troia.** Una persona arriva per il planner, le
flashcard, il libretto universitario — strumenti che funzionano benissimo anche
con un utente solo — e trova il social già popolato di gente che sta facendo le
sue stesse cose.

---

## 2. Dove vive cosa

**Deciso e non negoziabile:**

| | Sito (web) | App (React Native) |
|---|---|---|
| Produttività | ✅ tutta | ✅ tutta |
| Parte social | ❌ **nessuna** | ✅ tutta |
| Scelta del tema grafico | ❌ uno stile solo | ✅ tre temi |
| Notifiche | solo PWA, minime | ✅ complete |

> **Stato sul sito (27/10/2026):** la parte social è **spenta** sul sito. Le pagine
> `public/social/` (`friends.php`, `friends_api.php`, `friends_core.php`) restano nel
> repository come base per il social dell'app, ma rispondono 404, e sono sparite la
> voce della barra laterale, la scorciatoia della home e le richieste di amicizia nel
> pallino delle notifiche. L'interruttore è `lib/social_gate.php`: si riaccende solo
> con `define('STANDOUT_SOCIAL_SITO', true);` in `lib/config.php`.

Motivo: il sito è *"la produttività, ovunque, senza installare niente"* — deve
funzionare da un computer qualsiasi, senza registrazione a uno store. Il social è
esperienza, notifiche, tempo reale: vive nell'app.

Conseguenza tecnica (dettagli in `STANDOUT_PROGETTO.md` §5): **l'app non usa le
pagine PHP, usa l'API**. Quindi ogni funzione social nasce come endpoint JSON
sotto `/api/v1/`, mai come blocco dentro una pagina.

> **Correzione rispetto alla v1.0:** il documento precedente diceva "Frontend:
> Vanilla JS, No React" e collocava il social nella web app. Non è più così.
> Vanilla JS resta la regola **del sito**; l'app è React Native.

---

## 3. Decisioni già prese

Elenco secco, per chi arriva adesso:

1. Social **solo nell'app**, in React Native. **Unica eccezione (26/10/2026):** il
   mercato dei mazzi esiste anche sul sito, gratuito e con link condivisibile
   (`STANDOUT_PROGETTO.md` §14.4.10).
2. **Il matchmaking è l'ultima cosa** che si costruisce, quando ci sarà abbastanza
   gente. Prima non ha senso: con pochi utenti l'algoritmo propone sempre le
   stesse persone e nessuno matcha.
3. **Niente videochiamate costruite in casa.** Un pulsante apre una stanza su un
   servizio esterno (Jitsi o simili), generata al volo.
4. **Niente scorciatoia WhatsApp/Telegram.** Tecnicamente non esiste (§8.1): la
   chat si fa in casa.
5. **Verifica della foto rimandata** al giorno del match, e solo se avrà senso
   dal lato dei costi e degli obblighi di legge. Possibile forma: **verifica su
   richiesta a pagamento** (l'utente paga il badge).
6. **Pensieri:** 1 post al giorno, minimo **80 parole**; risposte separate dal
   post, minimo **30 parole**.
7. **Notizie:** all'inizio le pubblicano solo persone fidate, nominate a mano.
8. **Pochi pensieri è un pregio, non un difetto:** l'obiettivo è che una persona
   riesca a leggerli quasi tutti.
9. Esiste un **like "il migliore"**, uno per persona per ogni topic (§6.4).
10. **Mercato dei mazzi di flashcard** dentro la parte social — specifiche
    tecniche in `STANDOUT_PROGETTO.md` §14.4.

---

## 4. Navigazione

**Barra in basso** (modello Instagram), che azzera la curva di apprendimento:

| Posizione | Sezione |
|---|---|
| 1 (sinistra) | **Menu** — apre la sidebar; è il pulsante che oggi sta in basso a sinistra e va spostato qui |
| 2 | **Home** — la produttività, il cruscotto personale |
| 3 | **Esplora** — ricerca persone e progetti |
| 4 | **Pensieri / Notizie** — il feed |
| 5 | **Chat** |
| 6 | **Profilo** |

Su schermo grande la barra diventa una colonna laterale fissa.

La **sidebar** contiene impostazioni, filtri avanzati, opzioni di sistema — tutto
ciò che non è navigazione primaria.

---

## 5. Profilo e relazioni

### 5.1 Il profilo

Una vetrina delle competenze e dei valori. Aprendolo si vede **l'anteprima
pubblica**, com'è per gli altri; un pulsante porta alla modifica.

Contiene:

- **Identità:** foto, nome, cognome, data di nascita.
- **Percorso:** scuole, università, corsi, premi, esperienze lavorative, CV
  allegato, link a LinkedIn e GitHub.
- **Dati di produttività, a scelta:** pomodori dell'ultimo periodo, media dei
  voti, abitudini (e *quali* abitudini mostrare). Vengono dalle tabelle della
  parte produttiva.
- **Stile di vita e valori:** passioni, sport, alimentazione, fumo, volontariato,
  cose in cui si crede.
- **Numeri sociali:** livello, like ricevuti per tipo, quante persone segue,
  quante lo seguono, con quante è amico (e la lista, se non nascosta).

### 5.2 Privacy per blocco — è metà del lavoro

Ogni blocco del profilo ha il suo interruttore: pubblico / solo amici / nascosto.
Compresi i conteggi di follower, seguiti e amici.

**Non è una funzione accessoria.** Il profilo raccoglie dati che la legge europea
tratta come particolari (§12.2): senza un pannello di privacy vero, la sezione
non si può aprire al pubblico.

### 5.3 Follow e amicizia sono due cose diverse

- **Follow** — asimmetrico. A segue B, B non deve accettare. Dà accesso a quello
  che B rende pubblico.
- **Amicizia** — simmetrico, con richiesta e accettazione. Sblocca la **chat
  privata** e i blocchi del profilo riservati agli amici.

### 5.4 Ricerca

Una barra sola che cerca **sia negli username sia nei nomi reali**. Toccando un
risultato si apre il profilo, con i due pulsanti (segui / chiedi amicizia)
sempre visibili.

---

## 6. Pensieri — il cuore del progetto

È la sezione che distingue Standout da tutto il resto, ed è quella da tarare con
più cura. Si ispira a Reddit, correggendone il difetto: là chiunque apre un
argomento e chiunque commenta con due parole, quindi trovare persone di qualità è
impossibile.

### 6.1 Come funziona

Ogni giorno vengono proposti **uno o più topic**, con categoria. Le persone
scrivono cosa ne pensano. Non commenti: **pensieri**.

Chi apre un topic vede pochi contributi, lunghi, e può leggerli quasi tutti. È un
obiettivo di progetto, non un limite da superare: **la scarsità è la funzione.**

### 6.2 I limiti — decisi

| Cosa | Limite |
|---|---|
| Pensieri nuovi sotto un topic | **1 al giorno** per persona |
| Lunghezza minima di un pensiero | **80 parole** |
| Risposte ad altri pensieri | **2 al giorno** (contatore separato) |
| Lunghezza minima di una risposta | **30 parole** |
| Chi può creare un topic | solo i ruoli alti (§6.5) |

**Perché le risposte hanno un contatore separato:** nella prima versione una
risposta consumava il pensiero del giorno. Ma allora io scrivo, tu mi rispondi
domani, io ti ribatto dopodomani — e dopo tre giorni ce ne siamo dimenticati
entrambi. La scarsità deve colpire chi vuole occupare spazio, non chi vuole
discutere.

**Nota sull'interfaccia:** il minimo di parole non va mostrato come un traguardo
da riempire ("mancano 12 parole"), perché invita a riempire. Il controllo si fa
prima dell'invio, lato app, per non caricare il server; il server lo **rifà
comunque**, perché un controllo che sta solo sul client non esiste.

### 6.3 L'economia dei like

| Tipo | Quanti | Chi può darlo |
|---|---|---|
| **Base** | 3 al giorno | tutti |
| **Speciale** | 1 a settimana | almeno 5 pensieri scritti e un livello intermedio |
| **Super** | 1 al mese | livello più alto |
| **Il migliore** | 1 per topic | vedi §6.4 |

Le soglie su speciale e super servono a **impedire il trucco degli account
falsi**: uno che si crea cinque profili per lanciare il proprio pensiero non ha,
su quei profili, né i pensieri scritti né il livello.

### 6.4 Il like "il migliore" — nuovo

Sotto ogni topic, **ogni persona può indicare un solo pensiero come il migliore**.
Non consuma i like normali, non si può dare a sé stessi, e si può cambiare idea
finché il topic è aperto.

A cosa serve: dare risalto. Con pochi contributi lunghi, il rischio è che il
migliore stia in fondo perché è arrivato tardi. Il pensiero più votato come
migliore **sale in cima al topic** con un contrassegno, e vale molti punti.

### 6.5 Livello e ruolo — sono due cose diverse

Vanno tenuti distinti nello schema, o si fa confusione per sempre:

- **Livello** — un numero che cresce con i punti. È la reputazione, si mostra sul
  profilo.
- **Ruolo** — un permesso. Decide *cosa puoi fare*.

| Ruolo | Cosa può fare |
|---|---|
| Base | leggere, scrivere pensieri e risposte, like base |
| Attivo | + like speciale |
| Fidato | + pubblicare notizie, like super |
| Supremo | + creare topic, moderare |

Il ruolo si guadagna col livello, **ma le prime persone di ruolo alto le nomina a
mano il proprietario**: al lancio non esiste nessun "supremo", e senza almeno tre
o quattro persone nominate il primo giorno la sezione notizie resta vuota e non
esiste nemmeno un topic.

### 6.6 I punti

- I punti arrivano dai like ricevuti, **pesati per tipo** (base < speciale <
  super < il migliore).
- **Nessuna penalità** per un pensiero che non riceve like: punire il silenzio
  spinge a scrivere solo quello che piace, che è l'opposto dello scopo.
- Il livello guarda **la media dei like per pensiero**, non il totale: così
  scrivere ogni giorno tanto per fare non conviene, e scrivere quando si ha
  davvero qualcosa da dire sì.

---

## 7. Notizie

Un giornalino curato, non un feed aperto. Contenuti: **borse di studio, bandi,
programmi per studenti, hackathon, classifiche universitarie, scadenze** — cioè
proprio le cose che si fa sempre fatica a trovare.

- **Pubblica solo chi ha il ruolo adatto**, e all'inizio solo persone fidate
  nominate a mano.
- L'elenco mostra anteprima, titolo, descrizione breve, categorie e **scadenza**.
- Filtri per categoria, facoltà, età, scadenza; e **filtri personalizzati
  precompilati** in base al profilo, così aprendo la sezione si vede subito
  quello che riguarda te.
- Aprendo una notizia: testo completo e, in fondo, **commenti e like in stile
  forum**.

> **Il vero costo di questa sezione non è il codice: è chi scrive.** Il codice
> sono due settimane. Trovare e riscrivere le notizie è un lavoro continuo. Se
> non c'è una persona che lo fa ogni settimana, la sezione va rimandata: una
> pagina notizie ferma da un mese fa più danno che non averla.

---

## 8. Chat

### 8.1 Perché si fa in casa

**Non esiste la scorciatoia WhatsApp o Telegram.** La WhatsApp Business API serve
a mandare messaggi da un numero aziendale con modelli approvati: non permette di
creare gruppi né di fare da client. Telegram permette bot e mini-app, ma i
messaggi resterebbero su Telegram, fuori dalla piattaforma, e la crittografia
end-to-end lì esiste solo nelle chat segrete uno-a-uno.

Restano due strade: **farla** (scelta) o comprare un servizio di messaggistica
(Stream, Sendbird), che costa qualche centinaio di euro al mese quando si cresce.

### 8.2 Chat privata

- Solo **testo**, per cominciare.
- Si sblocca con l'**amicizia**.
- Aggiornamento a intervalli nella prima versione; tempo reale dopo.

### 8.3 Cifratura: cosa si può promettere e cosa no

I messaggi si conservano **cifrati a riposo** (AES-256) con la chiave fuori dalla
cartella pubblica, meglio ancora in un servizio di gestione chiavi.

Ma va detto chiaro, dentro l'app: **non è crittografia end-to-end.** Chi ha
accesso al server ha, tecnicamente, sia i dati sia la chiave. Promettere di più
sarebbe falso e, in caso di problemi, indifendibile.

> Il **disclaimer chiaro protegge più della cifratura stessa.** Deve essere
> visibile all'utente, non sepolto nelle condizioni.

### 8.4 Chat di gruppo (progetti)

Arriva dopo, con il modulo progetti maturo: testo, immagini, file, sondaggi;
amministratori; aggiunta e rimozione di membri; link di invito condivisibile;
**gli admin decidono da quale data un nuovo entrato può leggere lo storico**.

Le **chiamate** non si costruiscono: un pulsante apre una stanza su un servizio
esterno.

---

## 9. Hub progetti — "il garage"

Risolve il bisogno numero uno della missione.

### 9.1 Creare un progetto

Chi lo crea indica: **nome, descrizione, categoria** (informatica, psicologia,
ambiente…), **facoltà utili**, **competenze cercate**, **luogo** e **distanza
massima**.

### 9.2 Cercare un progetto

Ricerca con filtri veri: posizione e raggio, categoria, facoltà, competenze,
parole chiave, progetto aperto o chiuso.

### 9.3 Lo spazio di lavoro

Approvato l'ingresso, il progetto diventa un ambiente di lavoro: chat di gruppo
(§8.4), file, sondaggi, **collegamento a un repository GitHub**, link d'invito da
condividere fuori.

### 9.4 Come lo farei nascere

**La prima versione non ha la chat di gruppo.** Ha: la scheda del progetto, la
ricerca con i filtri, la richiesta di adesione, e la chat privata con
l'amministratore. È un decimo del lavoro e verifica subito la cosa che conta —
*le persone si trovano davvero?* Se sì, la chat di gruppo arriva dopo.

---

## 10. Mercato dei mazzi

Si pubblicano e si vendono mazzi di flashcard già fatti; chi li prende può
modificarne le carte.

Le regole sono decise e stanno in **`STANDOUT_PROGETTO.md` §14.4**: copia con
genealogia, si vende un'edizione congelata, i file non si duplicano mai e i
contenuti pubblicati vivono in un prefisso che sopravvive alla cancellazione
dell'account. **I prezzi esistono nello schema da subito, ma partono tutti a 0 €.**

Si comincia dalla **condivisione gratuita**: stesso meccanismo, senza pagamenti,
senza IVA, senza rimborsi.

**Aggiornamento del 26/10/2026:** la condivisione gratuita si fa **subito sul
sito**, non solo nell'app, con ricerca, filtri, suggerimenti e link pubblico. Il
prezzo resta disattivato a 0 € con un avviso («in futuro potrai fissare un
prezzo»), ma il meccanismo è già pronto per i pagamenti. Sul sito **non ci sono
profili pubblici**: l'autore si mostra con un nome a scelta o in forma anonima
(da decidere, `STANDOUT_PROGETTO.md` §16 #10). Piano, rischi e ordine di
costruzione stanno in `STANDOUT_PROGETTO.md` §14.4.10.

---

## 11. Matchmaking valoriale — per ultimo

**Deciso: si costruisce alla fine**, quando ci saranno abbastanza persone. Con
pochi utenti l'algoritmo proporrebbe sempre gli stessi profili e non
matcherebbe nessuno — bruciando la prima impressione proprio sulla funzione più
delicata.

Le specifiche restano queste.

### 11.1 Adesione e chiusura giornaliera

Chi vuole partecipare lo dichiara **entro le 19:00**. Chi non lo dichiara non
partecipa **e non viene proposto agli altri**. Alle 19:00 un lavoro automatico
chiude la lista e calcola.

### 11.2 Il punteggio di correlazione

Pesa: tipo di relazione cercata (amicizia o sentimentale), ambizione, cosa si
studia, passioni incrociate, abitudini di vita, età, distanza. Con esclusioni
nette dove servono (per esempio fumo).

Due correzioni volute rispetto alle app tradizionali:

- **Chi ha avuto pochi match viene proposto di più**, chi ne ha avuti molti di
  meno. Serve a non lasciare indietro nessuno.
- **Se le persone adatte non ci sono, se ne propongono meno di tre — o
  nessuna.** Mai riempire con profili a caso.

### 11.3 Le carte cieche

Le tre carte **non mostrano né nome né foto**. Mostrano: macro-zona (la
provincia, mai il paese o la via), età indicativa, cosa studia, obiettivi,
abitudini, passioni, e la **percentuale di compatibilità**.

> Questa è la parte migliore del progetto, e va difesa: prima o poi qualcuno dirà
> "mettici le foto, funziona meglio". Funzionerebbe meglio nei numeri e peggio
> nel motivo per cui esiste.

### 11.4 Doppio consenso

Si sceglie **una sola** carta (o nessuna), entro il giorno dopo. Dall'altra
parte, chi viene scelto vede lo stesso profilo cieco e può accettare o rifiutare
— **una accettazione al giorno**. Il match esiste solo se entrambi si scelgono:
**al massimo due match veri al giorno** (il tuo e quello di chi ti ha scelto).

Chi ha già fatto match in passato è escluso dalle proposte future; chi è stato
proposto ma non scelto può ricomparire.

### 11.5 Sblocco progressivo

Fatto il match si apre una chat testuale anonima. La **foto si sblocca solo dopo
un certo numero di messaggi o giorni**, e solo se entrambi vogliono. La posizione
precisa **non si mostra mai**: solo la provincia.

### 11.6 Verifica dell'identità

Rimandata a questo momento. Se e quando si farà, due strade:

- servizio esterno (tipo Stripe Identity), **circa 1,50 $ a verifica** — con mille
  utenti sono 1.500 €, quindi va valutato con i conti in mano;
- **verifica su richiesta, a pagamento**: chi vuole il badge lo paga.

Alternativa leggera da fare **subito**, che costa zero e funziona meglio di
quanto sembri: **verifica con l'email universitaria** (`@unibg.it`, `@polimi.it`).
Dà un badge credibile e taglia fuori gran parte dei profili falsi.

Attenzione: i dati biometrici sono dati particolari (§12.2), con obblighi
pesanti. È un altro motivo per rimandare.

---

## 12. Sicurezza, privacy, obblighi

### 12.1 Le basi

Valgono le regole del sito (`STANDOUT_PROGETTO.md` §6-7): prepared statement
ovunque, validazione con le `sec_*`, controllo di proprietà prima di ogni
modifica, password con `password_hash()`, accesso a token per l'app.

### 12.2 Dati particolari — la mina più grande

Il profilo raccoglie **salute, alimentazione, fumo, convinzioni, volontariato**, e
la sezione match implica **l'orientamento sessuale** (si cerca un uomo o una
donna). In Europa questi sono **dati particolari, articolo 9 del GDPR**:
categoria a parte, **consenso esplicito e separato**, protezioni rinforzate,
sanzioni pesanti se trattati come dati normali.

Anche i **dati biometrici** della verifica con selfie rientrano qui.

Conseguenze pratiche:

- consenso separato per ogni categoria, non una spunta unica;
- il pannello di privacy per blocco (§5.2) non è un lusso: è la condizione per
  raccoglierli;
- cancellazione dell'account che cancella davvero, ed esportazione dei propri
  dati;
- **prima di aprire il social a estranei serve un'ora con un avvocato.** Costa
  molto meno adesso che dopo.

### 12.3 Contenuti degli utenti

Da piattaforma con contenuti caricati dagli utenti derivano obblighi (in Europa,
Digital Services Act): **segnalazione, rimozione, ricorso**. Servono dal primo
giorno in cui qualcuno può pubblicare qualcosa:

- pulsante di segnalazione su pensieri, commenti, progetti, profili, annunci;
- possibilità di **bloccare** un utente;
- strumenti di moderazione per il ruolo Supremo;
- verifica dell'età e regole sui minori;
- condizioni d'uso che dicano chi risponde di cosa.

---

## 13. Il rischio numero uno: la massa critica

**Non è un rischio tecnico. È il rischio.**

Le regole di qualità — un pensiero al giorno, tre carte, doppio consenso, like
scarsi — sono la parte migliore del progetto e allo stesso tempo quella che
**riduce di proposito il volume**. Un social che parte lento e per scelta produce
poco, all'inizio produce zero: chi apre e trova silenzio non torna.

**Strategia di lancio: non aprire al pubblico. Aprire a un gruppo chiuso.** Cento
studenti della stessa facoltà, o di un'associazione, o di un corso. Cento persone
che si conoscono di striscio fanno funzionare i pensieri e i progetti; diecimila
sconosciuti sparsi in Italia no.

Nel frattempo la parte produttiva funziona da sola, anche con un utente solo, e
continua ad attrarre gente.

---

## 14. Ordine di rilascio e tempi

Le stime sono per una persona part time che lavora con l'aiuto dell'IA. Non sono
ore di scrittura del codice: sono **decisioni, prove e correzioni**, che è il vero
collo di bottiglia.

| Fase | Cosa | Tempo |
|---|---|---|
| **0** | Fondamenta: sicurezza, cartelle, trasloco, API, app React Native con la produttività | vedi `STANDOUT_PROGETTO.md` §15 |
| **1** | Barra in basso + navigazione | 3-5 giorni |
| **1** | Profilo + privacy per blocco | 3-4 settimane |
| **1** | Follow, amicizia, ricerca persone | 1-2 settimane |
| **2** | **Pensieri**, like, livelli e ruoli | 4-6 settimane |
| **2** | Segnalazioni, blocchi, moderazione | 2-3 settimane |
| **3** | Chat privata testuale | 2-3 settimane |
| **4** | Hub progetti *senza* chat di gruppo | 3-4 settimane |
| **4** | Condivisione gratuita dei mazzi | 2-3 settimane |
| **5** | Notizie (solo con chi le scrive) | 2 settimane |
| **5** | Chat di gruppo con file e sondaggi | 6-8 settimane |
| **5** | GitHub | 1 settimana |
| **6** | Mercato con prezzi accesi | 3-4 settimane |
| **7** | **Matchmaking** + lavoro automatico delle 19 | 4-6 settimane |
| **7** | Verifica identità (se avrà senso) | 1-2 settimane |

**Totale onesto: 12-18 mesi part time**, oltre alle fondamenta. Non è pessimismo:
è il tempo che ci vuole.

**Il minimo che rende il posto vivo** (fasi 1-3): profilo, follow e amicizia,
pensieri, chat privata. Nient'altro. Tutto il resto viene dopo, e nell'ordine
sopra.

---

## 15. Cosa NON si fa

- Non si costruiscono videochiamate: link a un servizio esterno.
- Non si passa da WhatsApp o Telegram per la chat.
- Non si promette crittografia end-to-end.
- Non si fa il matchmaking prima di avere gente.
- Non si apre la pubblicazione (pensieri, progetti, notizie, mazzi) prima che
  esista il pulsante di segnalazione.
- Non si raccolgono dati particolari senza consenso separato e pannello privacy.
- Non si mostra la posizione precisa di nessuno, mai: solo la provincia.
- Non si riempiono le tre carte del match con profili a caso.
- Non si mostra il contatore delle parole mancanti.
- Non si mette il social nel sito. *(Unica eccezione: il mercato dei mazzi,
  gratuito, senza profili pubblici — §10.)*

---

## 16. Da decidere

1. **Il gruppo chiuso del lancio**: quale? (facoltà, associazione, corso)
2. **Chi scrive le notizie** ogni settimana, e chi sono le prime persone di ruolo
   alto da nominare a mano.
3. **Quanti punti** vale ciascun tipo di like, e la soglia di ogni livello.
   Numeri da tarare sui primi utenti veri, non da decidere a tavolino.
4. **Quanti messaggi o giorni** servono per sbloccare la foto dopo un match.
5. **Chi incassa** nel mercato dei mazzi (§10 e `STANDOUT_PROGETTO.md` §14.4.6).
   Sul mercato del sito restano aperte anche le domande di
   `STANDOUT_PROGETTO.md` §16 dal 10 al 13 (autore, vocabolario, moderazione,
   anteprima pubblica).
6. **Verifica identità**: servizio esterno per tutti, o badge a pagamento su
   richiesta?
7. Nome pubblico e identità visiva.

---

*Fine documento. Le scelte tecniche stanno in `STANDOUT_PROGETTO.md`.*
