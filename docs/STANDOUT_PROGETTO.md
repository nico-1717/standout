# STANDOUT — Documento di progetto

**Versione:** 1.0 — agosto 2026
**A chi serve:** a chiunque (persona o intelligenza artificiale) debba lavorare su
Standout senza aver seguito le conversazioni precedenti. Leggendo questo file si
deve sapere cos'è il progetto, com'è fatto oggi, dove deve arrivare, con quali
regole si scrive il codice e in che ordine si procede.

**Come usarlo:** i capitoli 1-6 descrivono *cosa esiste*. I capitoli 7-14
descrivono *cosa va fatto*. Il capitolo 15 è l'ordine dei lavori. Il capitolo 16
raccoglie le decisioni ancora aperte: se una risposta serve e non c'è, va chiesta
al proprietario invece di essere inventata.

**Regola di lettura:** dove il documento dice *va deciso* o *da confermare*,
nessuno decida al posto del proprietario. Dove dice *obbligatorio* o *mai*, non è
un'opinione.

---

## 0. Glossario

Termini usati in tutto il documento. Se un nome non è qui e non si capisce dal
contesto, va chiesto invece che immaginato.

| Termine | Significato in Standout |
|---|---|
| **carta** | l'unità di studio: una parola da imparare, o una domanda con la sua risposta |
| **carta padre / carta figlia** | una carta può raggruppare più sotto-carte (colonna `is_father` sulla padre, `parent_id` sulle figlie). **`child` nei nomi delle tabelle significa "figlia", non "per bambini"** |
| **mazzo** (*deck*) | il contenitore annidato delle carte: quello che oggi si chiama `categories` / `fc_categories` |
| **terapia** (pastiglie) | cosa prendere e con che ritmo — la regola |
| **presa** (pastiglie) | il singolo appuntamento con la sua spunta — il fatto |
| **routine** (planner) | blocco di eventi consecutivi trattati come uno solo |
| **occorrenza** | una singola ripetizione di un evento ricorrente, con riga propria |
| **endpoint** | indirizzo che risponde JSON; oggi spesso *dentro* una pagina, in un blocco `if ($ajax === '…')` |
| **archivio a oggetti** | il magazzino dei file (R2). Non ha cartelle: le "cartelle" sono solo parte del nome |
| **vista di compatibilità** | una `VIEW` che tiene in vita un nome vecchio dopo un rinomino, così il codice non si rompe subito |
| **sec_\*** | le funzioni di validazione in `security_core.php` |

---

## 1. Cos'è Standout

Standout è un'applicazione personale di produttività, scritta e usata da una
persona sola, che ora punta ad aprirsi ad altri utenti.

Contiene, oggi:

- **Planner** — calendario a giorni/settimana/mese, eventi, routine, priorità
  del giorno, meteo, luoghi con mappa, sincronizzazione con Google Calendar.
- **Attività** — due elenchi (*to do*, *to buy*) più la sezione
  **pastiglie** (terapie e prese con spunta).
- **Abitudini** — abitudini con obiettivo a completamento o a quantità, registro,
  traguardi, anelli concentrici di avanzamento.
- **Diario** — voci, elenchi, impostazioni.
- **Progetti** — progetti e sotto-progetti, post-it, collegamento al pomodoro.
- **Pomodoro** — sessioni, pause, durate personalizzate.
- **Flashcards, inglese per bambini, giochi, università** — sezioni più vecchie,
  meno curate, che vivono nello stesso database.

### 1.1 Obiettivo del sito (web)

Il sito è **la produttività, ovunque, senza installare niente.** Deve funzionare
bene da computer e da telefono, senza registrazione di un'app, senza store.
Contiene tutte le funzioni di lavoro: planner, attività, abitudini, diario,
progetti, pomodoro.

**Il sito non avrà la parte social.** Nessun profilo pubblico, nessun messaggio,
nessun contenuto condiviso.

### 1.2 Obiettivo dell'app (React Native)

L'app è **l'esperienza**. Rifatta da zero in React Native (iOS + Android),
riusando l'API del sito e non il suo HTML. Avrà in più:

- la scelta fra **tre temi grafici** (stile planner, stile abitudini, stile
  progetti), che sul sito *non* ci sarà;
- notifiche vere (pastiglie, promemoria, abitudini);
- funzionamento senza rete e sincronizzazione;
- **la parte social**: profili, messaggi, immagini, file — solo qui;
- **il mercato dei mazzi**: si pubblicano e si vendono mazzi di flashcard già
  fatti, e chi li prende può modificarne le carte (§14.4).

### 1.3 Conseguenza pratica su come si lavora da adesso

> Ogni ora spesa sull'interfaccia web è un'ora che l'app butterà.
> Ogni ora spesa su dati, logica e API è un'ora che l'app riuserà.

Da questo discende quasi tutto quello che segue: la logica va tolta dalle pagine
e messa in funzioni riusabili, gli endpoint diventano un'API JSON pulita,
l'autenticazione deve funzionare sia a cookie (sito) sia a token (app), e le
traduzioni si tengono in JSON leggibile da entrambi i mondi.

---

## 2. Stato attuale della tecnologia

| Cosa | Com'è oggi |
|---|---|
| Linguaggio server | PHP (procedurale, nessun framework) |
| Database | MySQL/MariaDB, 71 tabelle |
| Interfaccia | HTML + CSS + JavaScript puro, nessun framework, nessun passaggio di compilazione |
| Hosting | Altervista (gratuito/condiviso), database incluso |
| File caricati | cartella `uploads/` sul disco del sito |
| Struttura cartelle | **tutto in una cartella sola**, tranne `svg/` |
| CSS | ogni pagina ha il suo file completo (`planner.css` ~87 KB), con molto codice ripetuto |
| Stili | **tre stili diversi** nati in momenti diversi: planner/attività, abitudini, progetti |
| Sicurezza | livello alto **solo** sulle pagine rifatte di recente (planner, attività); le altre sono da verificare |
| Lingua | solo italiano, testi scritti dentro il codice |

### 2.1 Le tabelle esistenti (71)

Raggruppate per area, così chi arriva capisce dove guardare:

- **Utenti e accesso:** `users`, `roles`, `user_roles`, `plans`, `user_plans`,
  `remember_tokens`, `login_attempts`, `rate_limits`, `security_audit_log`,
  `push_subscriptions`, `friends`, `friends_notifications`, `contacts`.
- **Planner:** `planner_events`, `planner_calendars`, `planner_categories`,
  `planner_category_keywords`, `planner_priorities`, `planner_routines`,
  `planner_routine_items`, `planner_settings`, `planner_weeks`,
  `planner_week_lists`, `planner_week_list_items`, `event_guests`,
  `place_cache`, `weather_cache`,
  `google_calendar_accounts`, `google_sync_log`, `google_sync_queue`.
- **Attività:** `todos`, `todo_buy_categories`.
- **Pastiglie (da migrare):** `pill_plans`, `pill_doses`.
- **Rubrica luoghi (da migrare):** `planner_places`.
- **Abitudini:** `habits`, `habit_categories`, `habit_logs`, `habit_log_events`,
  `habit_daily_journal`, `habit_settings`, `habit_reminders`,
  `habit_achievements`, `habit_user_achievements`, `habit_streak_records`.
- **Diario:** `diary_list_items`, `diary_settings`.
- **Progetti e pomodoro:** `projects`, `project_fc_categories`,
  `pomodoro_sessions`, `pomodoro_settings`, `pomodoro_current_breaks`,
  `pomodoro_custom_durations`, `session_durations`.
- **Flashcards e apprendimento:** `fc_*` (6 tabelle), `images`, `categories`,
  `english_child_words`, `user_learnt_english`, `user_learnt_fc`.
- **Giochi:** `games`, `game_categories`, `game_fc_categories`, `gameplays`,
  `fc_gameplays`.
- **Università:** `university_exams`, `university_exam_groups`,
  `university_bonuses`, `university_settings`.
- **Varie:** `ai_perf_stats`.

Nota: esistono già `rate_limits`, `security_audit_log`, `push_subscriptions`,
`roles`/`user_roles`, `plans`/`user_plans`. Non vanno reinventati: vanno
**estesi a tutto il sito**, perché oggi sono usati solo da una parte.

### 2.2 Tabella `users` — attenzione

```
id, username, email, password_hash, created_at, last_login_at, is_active
```

Manca tutto quello che serve a un sito aperto al pubblico: verifica dell'email,
token di reimpostazione password, secondo fattore, lingua e tema preferiti,
accettazione delle condizioni, data di cancellazione richiesta. Vedi §7.4.

---

## 3. Convenzioni di codice (obbligatorie)

Chi scrive codice per Standout rispetta queste regole. Non sono preferenze
estetiche: sono il motivo per cui il codice esistente si può ancora leggere.

1. **Commenti in italiano, che spiegano *perché*, non *cosa*.**
   `// scorre l'array` è rumore. `// una lettura sola all'inizio del gesto:
   durante il trascinamento la striscia non si muove, quindi le misure restano
   valide e non serve rileggere il layout a ogni fotogramma` è un commento.
2. **Non riscrivere quello che già funziona.** Il codice è passato per molte
   correzioni; una riscrittura "più pulita" perde le correzioni.
3. **Blocchi piccoli e verificati.** Un blocco alla volta, con i file completi.
   Un file lasciato a metà è peggio di un file non toccato.
4. **Prima di consegnare si controlla la sintassi:** `node --check` sul
   JavaScript, bilanciamento delle graffe sul CSS, confronto del bilanciamento
   con l'originale sul PHP.
5. **Prestazioni:** mai letture di layout in ciclo (`offsetWidth`,
   `getBoundingClientRect`) su molti elementi, e mai dentro un ciclo di
   animazione. Si misura una volta e si riusa.
6. **Sicurezza:** vedi capitolo 7. Non si abbassa mai il livello esistente.

---

### 3.1 Anatomia di una pagina, oggi

Chi non ha mai aperto un file di Standout si aspetta un framework e non lo trova.
Le pagine grosse (planner, habits) sono fatte così, dall'alto in basso:

```php
<?php
require_once 'config.php';          // credenziali e chiavi
require_once 'security_core.php';   // le funzioni sec_*
require_once 'auth_check.php';      // chi sei; se non sei nessuno, ti caccia

$current_user_id = (int)$utente_corrente['id'];

/* --- funzioni di questa pagina (pl_*, td_*): leggono e scrivono sul DB --- */
function pl_events_range(...) { ... }

/* --- GLI ENDPOINT: qui dentro, non in un file a parte --- */
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 40) : '';
if ($ajax !== '') {
    if ($ajax === 'event_save')  { ...; sec_json([...]); }
    if ($ajax === 'place_search'){ ...; sec_json([...]); }
    sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');
}

/* --- i dati di partenza, passati al JavaScript in un colpo solo --- */
$boot = [ 'events' => ..., 'settings' => ..., 'map_key' => ... ];
?>
<!DOCTYPE html>
... HTML ...
<script>window.PL_BOOT = <?php echo json_encode($boot, ...); ?>;</script>
<script src="planner.js"></script>
```

Tre cose da sapere prima di toccarle:

1. **Gli endpoint stanno in fondo alla pagina stessa.** Non esistono
   `planner_api.php` né `habits_api.php`. `todo_api.php` invece esiste ed è già
   separato: è il modello verso cui vanno portati gli altri (§5).
2. **`PL_BOOT` è il ponte fra PHP e JavaScript.** Il JS non fa una chiamata per
   avere i dati iniziali: li trova già in pagina. Stesso meccanismo da usare per
   le traduzioni (§12.2).
3. **I file JS sono grandi** (`planner.js` ~300 KB). Vanno modificati per
   sostituzione mirata, mai riscritti, e verificati con `node --check`.

### 3.2 Com'è fatto un endpoint corretto

Questo è il modello da imitare. Ogni riga fa una cosa che, se manca, apre un buco:

```php
if ($ajax === 'place_book_save') {
    // 1. la tabella esiste? (le aggiunte recenti sono facoltative)
    if (!pl_places_c_e()) sec_die_json('Rubrica non installata.', 400, 'no_table');

    // 2. OGNI valore in arrivo passa da una sec_*: mai $_POST usato com'è
    $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
    $label = sec_str(sec_in('label'), 60);
    $lat   = sec_float(sec_in('lat'), -90, 90, 999);
    if ($label === '') sec_die_json('Manca il nome.', 422, 'empty');

    // 3. la riga è sua? prima di toccarla, sempre
    if ($id > 0 && !sec_owns('planner_places', $id, $current_user_id)) {
        sec_die_json('Non trovato.', 404, 'not_found');
    }

    // 4. prepared statement con segnaposto, mai concatenazione
    sec_q("UPDATE planner_places SET label=? WHERE id=? AND user_id=?",
          [$label, $id, $current_user_id], 'sii');
    //                                     ^^^ la stringa dei tipi deve avere
    //                                     tanti caratteri quanti i parametri

    // 5. risposta JSON, e si esce
    sec_json(['ok' => true, 'places' => pl_places($current_user_id)]);
}
```

Errori tipici da non ripetere: dimenticare `AND user_id = ?` nella `WHERE` anche
dopo aver fatto `sec_owns` (cintura e bretelle: costa nulla), sbagliare il numero
di caratteri nella stringa dei tipi di `sec_q`, e mandare al browser il messaggio
d'errore del database invece di uno generico.

### 3.3 Come si consegna un lavoro

1. Un blocco alla volta, con i **file completi**, non frammenti da incollare.
2. Prima di consegnare:
   - JavaScript: `node --check file.js`;
   - CSS: conteggio delle graffe aperte e chiuse, uguale;
   - PHP: conteggio di graffe e parentesi confrontato con l'originale (le
     differenze devono corrispondere solo a quello che si è aggiunto).
3. Nel messaggio di consegna si dice **cosa si è cambiato e perché**, non si
   incolla il codice.
4. Se una cosa non si è capita, si chiede. Un file lasciato a metà è peggio di un
   file non toccato.

---

## 4. Struttura delle cartelle — desiderata

Oggi è tutto in una cartella. Struttura di arrivo:

```
/                          (radice pubblica)
├── index.php              porta d'ingresso
├── manifest.webmanifest   PWA
├── sw.js                  service worker
├── .htaccess              regole, riscritture, intestazioni
│
├── app/                   LE PAGINE (una per sezione)
│   ├── planner.php  habits.php  diario.php  progetti.php
│   ├── todo.php     pomodoro.php
│   └── flashcards/  giochi/  universita/
│
├── api/                   GLI ENDPOINT (solo JSON, niente HTML)
│   └── v1/
│       ├── planner.php  todo.php  habits.php  diario.php
│       ├── meteo.php    luoghi.php  pastiglie.php
│       └── auth.php     (accesso, token, rinnovo)
│
├── inc/                   COMPONENTI riusabili lato server
│   ├── sidebar.php  todo_widget.php  project_selector.php
│
├── lib/                   LOGICA E FONDAMENTA (mai richiamabile dal browser)
│   ├── bootstrap.php      l'unico punto d'ingresso comune
│   ├── config.php         segreti e chiavi (fuori dal versionamento)
│   ├── security_core.php  le funzioni sec_*
│   ├── auth.php           sessione, token, permessi
│   ├── i18n.php           traduzioni
│   ├── storage.php        archivio a oggetti (R2)
│   └── dominio/           la logica vera: planner.php, todo.php, habits.php…
│
├── assets/
│   ├── css/   base.css + una per pagina
│   ├── js/
│   ├── svg/   (già esiste)
│   └── img/   icone, loghi, icone PWA
│
├── lang/      it.json  en.json   (poi fr.json…)
├── data/      json di configurazione (frasi, preset, ambient)
├── sql/       _db.sql, migrazioni numerate
└── uploads/   file caricati — temporaneo, sparisce col passaggio a R2
```

### 4.1 Regole della struttura

- **`lib/`, `sql/`, `data/` non devono essere raggiungibili dal browser.** Se
  l'hosting permette di metterli sopra la cartella pubblica, si mettono lì.
  Altrimenti `.htaccess` con `Require all denied`. Oggi `_db.sql` è scaricabile
  da chiunque ne indovini il nome: è la mappa del database.
- **In `uploads/` va vietata l'esecuzione di PHP** (`php_flag engine off` o
  equivalente). Se un giorno un controllo sul tipo di file scappa, questa riga è
  la differenza fra un fastidio e un sito compromesso.
- **Un solo `bootstrap.php`.** Ogni pagina comincia con
  `require __DIR__.'/../lib/bootstrap.php';` e da lì include per costante
  (`STANDOUT_LIB`, `STANDOUT_INC`), mai per percorso relativo: il prossimo
  spostamento costerà una riga.
- **Lo spostamento si fa a scaglioni**, lasciando al vecchio indirizzo un file
  di due righe che rimanda al nuovo (301), così segnalibri e PWA installata non
  si rompono.

---

## 5. Architettura: pagine, API, logica

### 5.1 Com'è oggi (da superare)

La logica sta **dentro** le pagine: in fondo a `planner.php` ci sono i blocchi
`if ($ajax === 'event_save')`, `if ($ajax === 'place_search')` e così via. Non
esistono `planner_api.php` né `habits_api.php`. `todo_api.php` invece è già un
endpoint separato.

Questo funziona per il sito ma **è inservibile per l'app**: React Native non vede
le pagine PHP, vede solo JSON, e non può usare le sessioni a cookie.

### 5.2 Dove si vuole arrivare

Tre strati:

1. **`lib/dominio/*.php` — la logica.** Funzioni pure che ricevono l'id utente e
   dei parametri già validati, e restituiscono array. Nessun `echo`, nessun
   `$_POST`, nessuna sessione. Esempi che già esistono e vanno spostati qui:
   `pl_events_range()`, `pl_places()`, `td_pills_ensure()`, `pl_place_punto()`.
2. **`api/v1/*.php` — gli endpoint.** Leggono l'ingresso, lo validano con le
   `sec_*`, chiamano la logica, rispondono JSON. Nient'altro.
3. **`app/*.php` — le pagine.** Chiamano la stessa logica e stampano HTML.

Non serve rifare tutto in una volta: **si sposta il pezzo che si tocca, quando lo
si tocca.** Ogni volta che si lavora su una funzione, la si porta in `lib/` e si
lascia la pagina a chiamarla.

### 5.3 Regole dell'API

- Radice `/api/v1/`. Il numero di versione serve a poter cambiare senza rompere
  un'app già installata sul telefono di qualcuno.
- Risposta sempre JSON, sempre con `ok: true|false`; in caso di errore
  `{ok:false, error:"messaggio", code:"not_found"}` e il codice HTTP giusto.
- Nessun HTML, nessun redirect, nessun messaggio in italiano *dentro* il codice
  di errore: il testo per l'utente lo sceglie il client, dalla traduzione.
- Paginazione a cursore (non a numero di pagina) per gli elenchi lunghi.
- Ogni endpoint dichiara se accetta cookie, token, o entrambi.

---

## 6. Sicurezza — cosa c'è già

Le pagine recenti (planner, attività) seguono già questo impianto, definito in
`security_core.php` e descritto in `SICUREZZA.md`:

- **Validazione in ingresso:** ogni valore che arriva dal browser passa da
  `sec_str`, `sec_int`, `sec_float`, `sec_enum`, `sec_date`, `sec_time`,
  `sec_color`, `sec_email`, `sec_bool`. Mai `$_POST['x']` usato direttamente.
- **Proprietà del dato:** `sec_owns('tabella', $id, $userId)` — oppure
  `AND user_id = ?` nella WHERE — **prima** di modificare o cancellare
  qualunque riga.
- **Uscita:** tutto quello che finisce in pagina passa da `sec_h()`.
- **Query:** sempre prepared statement con segnaposto `?`
  (`sec_q`, `sec_one`, `sec_all`). Mai concatenazione.
- **Scritture a raffica:** `sec_guard_write($uid, 'bucket', ...)` con la tabella
  `rate_limits`.
- **CSRF:** token in `<meta name="csrf-token">` e intestazione `X-CSRF-Token`
  su ogni scrittura.
- **Registro:** `security_audit_log` per gli eventi importanti.

**Il problema è che tutto questo copre solo una parte del sito.** Le pagine più
vecchie (flashcards, giochi, università, inglese) sono da verificare una per una.

---

## 7. Sicurezza — cosa va fatto

Elenco completo delle misure che il sito deve avere prima di aprirsi a utenti
esterni. Ordinate per gravità.

### 7.1 Rendere la strada sicura l'unica strada

`lib/bootstrap.php` fa, in quest'ordine, per **ogni** richiesta:

1. sessione configurata bene: `session.cookie_httponly=1`,
   `cookie_secure=1`, `cookie_samesite=Lax`, `use_strict_mode=1`,
   rigenerazione dell'id all'accesso;
2. intestazioni di sicurezza (§7.6);
3. connessione al database in modalità eccezione;
4. caricamento di `security_core.php` e `i18n.php`;
5. controllo di accesso (cookie o token, §7.3).

Una pagina che non include `bootstrap.php` **non deve funzionare affatto**,
invece di funzionare in modo insicuro.

### 7.2 La verifica pagina per pagina

Serve un elenco di cosa manca dove. Si ottiene meccanicamente, con uno script
che cerca in tutti i file PHP:

- `$_GET` / `$_POST` / `$_REQUEST` usati senza passare da una `sec_*`;
- query costruite per concatenazione (`"... WHERE id = $id"`);
- `echo` di variabili senza `sec_h()`;
- pagine senza controllo di accesso;
- `UPDATE`/`DELETE` senza `user_id` nella WHERE né `sec_owns()` prima;
- file caricati senza controllo del tipo reale;
- chiamate a `eval`, `system`, `exec`, `unserialize` su dati esterni.

Ne esce una lista file per file, ordinata per gravità, da smaltire a blocchi.
**Questo è il primo lavoro da fare in assoluto.**

### 7.3 Accesso: doppio binario cookie + token

Il sito continua con la sessione a cookie. L'app userà un token.

- **Token di accesso** a vita breve (15-60 minuti), inviato come
  `Authorization: Bearer …`.
- **Token di rinnovo** a vita lunga (30-90 giorni), conservato *cifrato* nel
  database, **ruotato a ogni uso**, revocabile per singolo dispositivo
  (la tabella `remember_tokens` è il punto di partenza).
- Un solo posto nel codice risponde alla domanda "chi è l'utente di questa
  richiesta": se raddoppia, prima o poi i due controlli divergono.
- Uscita da tutti i dispositivi: cancellazione di tutti i token dell'utente.

### 7.4 Password e identità

- `password_hash()` con l'algoritmo predefinito (oggi bcrypt/argon2) e
  `password_needs_rehash()` all'accesso. **Mai** md5, sha1, né sale fatto a mano.
- Lunghezza minima 10 caratteri, nessuna regola cervellotica su maiuscole e
  simboli; meglio un controllo contro le password più diffuse.
- **Verifica dell'email** alla registrazione, con token a scadenza.
- **Reimpostazione password** con token monouso a scadenza breve, invalidato
  all'uso e a ogni cambio password.
- Limite ai tentativi di accesso (`login_attempts` esiste già): per utente
  **e** per IP, con attesa crescente.
- Alla tabella `users` vanno aggiunti: `email_verified_at`, `lang`, `theme`,
  `terms_accepted_at`, `deleted_at`, `totp_secret` (facoltativo, secondo fattore).

### 7.5 File caricati

Questo è il punto più pericoloso di un sito con foto e allegati.

- Il tipo si controlla dal **contenuto** (`finfo`), non dall'estensione né dal
  `Content-Type` dichiarato dal browser.
- Le immagini vengono **rigenerate** (GD/Imagick) invece che salvate come sono:
  rigenerare elimina qualunque cosa sia nascosta dentro, metadati compresi.
- Nome del file **sempre generato da noi** (codice casuale), mai quello
  dell'utente.
- Peso massimo per file e per utente, controllato anche lato server.
- I file non stanno nella cartella del codice ma nell'archivio a oggetti (§9).
- Gli allegati privati escono solo con collegamento firmato a scadenza.

### 7.6 Intestazioni HTTP

Su tutte le risposte:

```
Content-Security-Policy: default-src 'self'; img-src 'self' data: https:;
    frame-src https://www.google.com; script-src 'self' 'nonce-…'
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(self), camera=(), microphone=()
X-Frame-Options: SAMEORIGIN
```

Nota: la CSP richiede di togliere il JavaScript scritto dentro gli attributi
`onclick` e di usare i *nonce* per i blocchi `<script>` in pagina. È un lavoro
di pulizia da fare gradualmente, ma è la difesa più efficace contro l'iniezione
di script.

### 7.7 Chiavi e segreti

- `config.php` **fuori dal versionamento** e fuori dalla cartella pubblica.
- Chiavi diverse fra sviluppo e produzione.
- La chiave di Google Places resta sul server; solo quella della mappa
  incorporata può stare in pagina, ed è ristretta al dominio.
- Nessuna chiave dentro l'app React Native: un'app si può aprire e leggere.

### 7.8 Dati personali (obbligo di legge in Europa)

Con utenti veri e contenuti loro:

- informativa privacy e condizioni d'uso, accettate alla registrazione;
- **cancellazione dell'account** che cancella davvero: righe e file
  (da qui la struttura delle chiavi in §9.2, pensata per cancellare per
  prefisso);
- esportazione dei propri dati;
- registro di chi tocca cosa (`security_audit_log`);
- backup cifrati e conservati per un tempo dichiarato;
- se arriva la parte social: segnalazione e blocco degli utenti, e moderazione.

### 7.9 Operatività

- Backup notturni **verificati** (un backup mai riprovato non è un backup).
- Aggiornamenti di sistema automatici per le patch di sicurezza.
- Firewall: solo 80, 443 e SSH; SSH solo a chiave, mai a password.
- Fail2ban su SSH.
- Certificato TLS automatico (Let's Encrypt) e rinnovo automatico.
- Un modo per accorgersi che il sito è giù senza che te lo dica un utente
  (una sonda gratuita di controllo basta).

---

## 8. Database

### 8.1 Regole

- **Le immagini e i file non vanno nel database.** Mai. Nel database va la riga
  che dice dove sta il file; i byte stanno nell'archivio a oggetti.
- Ogni tabella con dati di utenti ha `user_id` e un indice su di esso.
- Ogni interrogazione che comparirà in una pagina ha un indice che la copre.
  Il criterio: *nessuna query deve leggere più righe di quante ne mostra.*
- Le date in colonne `date`/`datetime`, mai in testo.
- Chiavi esterne dove ha senso, con cancellazione a cascata: è la rete di
  sicurezza contro le righe orfane.
- Migrazioni **numerate** in `sql/`, mai modifiche a mano in produzione senza
  che esista il file corrispondente.
- Passaggio a InnoDB e `utf8mb4` ovunque (una parte lo è già; le tabelle vecchie
  usano ancora collazioni diverse: `utf8mb4_0900_ai_ci` contro
  `utf8mb4_unicode_ci`. Vanno uniformate, o i confronti fra colonne di tabelle
  diverse diventano lenti).

### 8.2 Migrazioni in sospeso (già scritte, da eseguire)

Sono file SQL già scritti, non ancora eseguiti sul database:

- `migrazione_colonne_esempi.sql` — `fc_examples` passa da una colonna sola
  con il segno (`fc_image_id`: positivo per un padre, negativo per
  `-id_child` di un figlio) a due colonne esplicite, `parent_id` e
  `child_id`, ciascuna con la sua foreign key vera e un vincolo che ne
  impone esattamente una valorizzata per riga. Il segno era un'economia
  falsa: un intero costa uguale con o senza, ma il significato implicito
  ha già causato più di un bug (query che dimenticavano la convenzione,
  `sec_int()` che tagliava via i negativi, il chip che confondeva un padre
  e un figlio con lo stesso numero) e non permetteva una foreign key vera,
  quindi nessuna pulizia automatica quando una scheda viene cancellata.
  Da eseguire con calma, un blocco alla volta: lo script include le query
  di controllo per i casi ambigui (stesso numero usato sia da un padre sia
  da un figlio, per coincidenza fra le due sequenze indipendenti) prima di
  toccare qualunque riga.
- `planner_places` — rubrica dei luoghi (casa, lavoro) con parole d'aggancio.
- `pill_plans`, `pill_doses` — pastiglie.
- `todos.buy_priority` predefinito da 2 a 3.

Da scrivere, ed eseguire al trasloco (§8.3):

- `sql/rinomina.sql` — rinomino delle tabelle, con le viste di compatibilità e,
  in fondo commentati, i comandi per toglierle a lavoro finito.
- `sql/colonne.sql` — `word` e `translation` al posto di `description_en` e
  `description_it`, più `learn_lang` e `known_lang` sui mazzi.
- `sql/commenti.sql` — una descrizione per ognuna delle 71 tabelle.

### 8.3 Rinomino delle tabelle — deciso, da eseguire al trasloco

MySQL non ha le cartelle: l'elenco delle tabelle è piatto. Ma phpMyAdmin (e quasi
tutti gli strumenti) **raggruppa da solo le tabelle che condividono il prefisso**
prima del primo trattino basso. Quindi i prefissi *sono* le cartelle, e il
disordine attuale viene dalle tabelle che il prefisso non ce l'hanno.

**Regola d'ora in poi: ogni tabella nuova nasce con il prefisso della sua area.**

#### 8.3.1 Le due famiglie di studio

Sono state confuse a lungo perché i nomi non dicevano niente. Hanno la stessa
identica forma (carta padre + carte figlie + mazzi annidati + materiali
allegati), ma contenuto diverso:

- **`study_*` — flashcard per l'università.** Domanda e risposta, ciascuna con
  testo, immagine e audio.
- **`vocab_*` — vocaboli di una lingua.** Una parola da imparare con la sua
  traduzione, il significato, un esempio e un'immagine.

#### 8.3.2 Mappa dei rinomini

| Oggi | Diventa | Contiene |
|---|---|---|
| `fc_images` | `study_cards` | domanda e risposta con testo, immagine, audio |
| `fc_child_cards` | `study_subcards` | le carte figlie di una carta padre |
| `fc_categories` | `study_decks` | mazzi annidati (`parent_id`) |
| `fc_examples` | `study_card_examples` | esempi con testo, immagine, audio |
| `fc_ai_data` | `study_card_ai` | spiegazione e metafora generate |
| `user_learnt_fc` | `study_learnt` | cosa ha già imparato l'utente |
| `project_fc_categories` | `project_study_decks` | mazzo collegato a un progetto |
| `game_fc_categories` | `game_study_decks` | mazzo collegato a un gioco |
| `images` | `vocab_cards` | la parola da imparare e i suoi materiali |
| `english_child_words` | `vocab_subcards` | le parole figlie |
| `categories` | `vocab_decks` | mazzi annidati |
| `user_learnt_english` | `vocab_learnt` | |
| `fc_gameplays` / `gameplays` | `game_plays_study` / `game_plays_vocab` | **da confermare**: quale serve a quale gioco |
| `roles`, `user_roles` | `auth_roles`, `auth_user_roles` | |
| `login_attempts`, `remember_tokens`, `rate_limits`, `security_audit_log`, `push_subscriptions` | `auth_*` | tutto ciò che è identità e difesa sotto un tetto |
| `plans`, `user_plans` | `billing_plans`, `billing_user_plans` | `plans` accanto a `planner_*` è una trappola |
| `contacts` | `planner_contacts` | è la rubrica degli invitati del planner |

Restano come sono: `users` (nome già giusto, ed è la più citata di tutte),
`todos`, `friends`, `weather_cache`, `place_cache`, e tutte le famiglie che il
prefisso ce l'hanno già (`planner_*`, `habit_*`, `pomodoro_*`, `university_*`,
`diary_*`, `google_*`, `pill_*`).

#### 8.3.3 Le colonne delle carte di vocabolario

Le colonne attuali dicono la lingua invece del ruolo, e questo blocca ogni lingua
oltre all'inglese. Vanno rinominate così:

| Oggi | Diventa | Perché |
|---|---|---|
| `description_en` | `word` | è **il vocabolo da imparare**, che può essere inglese, francese, tedesco… |
| `description_it` | `translation` | è **la traduzione nella lingua che già si conosce**, che può essere italiano, inglese… |

`meaning` ed `example` restano, e per convenzione **sono scritti nella lingua da
imparare**. Se un giorno servirà l'esempio tradotto, la colonna si chiamerà
`example_translation`.

Struttura finale della carta di vocabolario:

| Colonna | Contenuto |
|---|---|
| `word` | il vocabolo, nella lingua da imparare |
| `translation` | la traduzione, nella lingua che si conosce |
| `meaning` | il significato, nella lingua da imparare |
| `example` | frase d'esempio, nella lingua da imparare |
| `image` | l'immagine |
| `deck_id` | il mazzo, che dichiara la coppia di lingue |
| `is_father` | se raggruppa delle sotto-carte |

Le stesse due colonne vanno rinominate anche in `vocab_subcards`
(`english_child_words`), che le ha uguali.

#### 8.3.4 Le lingue stanno sul mazzo

Con `word` e `translation` la carta non dice più *quali* sono le due lingue.
L'informazione va sul mazzo, che è anche il modo in cui si studia davvero (un
mazzo *è* "francese per italiani"):

```sql
ALTER TABLE vocab_decks
  ADD COLUMN learn_lang CHAR(2) NOT NULL DEFAULT 'en'
      COMMENT 'la lingua da imparare: en, fr, de…',
  ADD COLUMN known_lang CHAR(2) NOT NULL DEFAULT 'it'
      COMMENT 'la lingua che l utente già conosce';
```

I valori predefiniti sono già la verità di oggi, quindi la migrazione dei dati
esistenti è a costo zero. Aggiungere il tedesco diventa **creare un mazzo**, non
toccare lo schema.

> **Decisione ancora aperta che cambia questo pezzo:** se un giorno i mazzi
> diventeranno **condivisibili fra utenti**, la coppia sul mazzo non basta (a uno
> spagnolo che scarica il tuo mazzo, la `translation` in italiano non serve) e
> le traduzioni dovranno andare in una tabella a parte, una riga per lingua:
> `vocab_translations(card_id, lang, translation, meaning, example)`.
> Finché i mazzi restano personali, **non si fa**: passare dopo dall'una all'altra
> è una migrazione meccanica
> (`INSERT INTO vocab_translations SELECT id, known_lang, translation … FROM vocab_cards`),
> non un rifacimento. Vedi §16.

#### 8.3.5 Come si rinomina senza rompere niente

Il nome di una tabella, dentro il PHP, è una stringa dentro una query: se la
sbagli **nessuno se ne accorge finché un utente non apre quella pagina**. Quindi
non si rinomina mai a secco su un sito che gira.

**Quando:** durante il trasloco sul VPS (§15, passo 5). Lì il database si esporta
e si reimporta comunque, e il sito vecchio resta in piedi mentre si prova il
nuovo.

**Come:** si rinomina e al vecchio nome si lascia una vista.

```sql
RENAME TABLE `images` TO `vocab_cards`;
CREATE VIEW  `images` AS SELECT * FROM `vocab_cards`;
```

In MySQL una vista su una tabella sola è modificabile: ci si può anche scrivere
dentro. Il codice vecchio continua a funzionare **senza toccare una riga**; le
query si correggono area per area con calma; alla fine le viste si cancellano, e
se è rimasto un punto scoperto lo si scopre in un momento scelto, non a sorpresa.

Per le **colonne** vale lo stesso metodo, con una differenza importante:

```sql
ALTER TABLE vocab_cards CHANGE `description_en` `word` VARCHAR(255) NOT NULL;
ALTER TABLE vocab_cards CHANGE `description_it` `translation` VARCHAR(255) NOT NULL;
CREATE VIEW `images` AS
  SELECT *, word AS description_en, translation AS description_it FROM vocab_cards;
```

> **Attenzione:** attraverso una vista con colonne calcolate, la **lettura**
> funziona sempre, la **scrittura** no. Quindi, per le colonne, le pagine che
> *scrivono* su queste tabelle vanno corrette **per prime**, non per ultime.
> Per le sole tabelle rinominate (vista `SELECT *`) anche la scrittura passa.

**Tre regole per non farsi male:**

1. Le tabelle dal nome generico (`images`, `categories`) sono le più importanti
   da rinominare e le più difficili da correggere: quelle parole compaiono nel
   codice in cento punti che non c'entrano niente. Per loro la vista non è
   un'opzione, è obbligatoria.
2. **Tabelle e colonne non si rinominano nello stesso giro.** Prima le tabelle,
   si verifica, poi le colonne. Se si rompe qualcosa si deve sapere cosa.
3. Le colonne di collegamento restano indietro (`study_card_examples.parent_id`
   punterà a `study_cards`, `study_card_examples.child_id` a `study_subcards`):
   brutto ma innocuo, si sistema in un terzo giro. (Erano un'unica colonna,
   `fc_image_id`, col segno a distinguere padre e figlio: vedi la migrazione
   di §8.2, che va eseguita prima di questo rinomino.)

#### 8.3.6 Commenti sulle tabelle

MySQL permette di attaccare una descrizione a ogni tabella, e phpMyAdmin la
mostra accanto al nome nell'elenco. Sulle colonne il progetto li usa già; sulle
tabelle no, ed è dove servono di più.

```sql
ALTER TABLE planner_events COMMENT = 'Eventi del calendario, uno per occorrenza';
ALTER TABLE pill_doses     COMMENT = 'Una presa: appuntamento singolo con la spunta';
```

Va creato `sql/commenti.sql` con **una riga per ognuna delle 71 tabelle**, tenuto
aggiornato insieme alle migrazioni. Con 71 righe nell'elenco, la differenza fra
`study_card_ai` e `ai_perf_stats` la si ricorda oggi, non fra sei mesi.

#### 8.3.7 Cosa NON fare

- **Non dividere in più database** (`standout_planner`, `standout_habits`…).
  Tecnicamente si può, ma le query che attraversano due aree diventano goffe, i
  permessi si moltiplicano, i backup diventano N invece di uno e il giorno che il
  database va su una macchina sua bisogna rifare tutto. È organizzazione
  apparente pagata con complicazione vera.
- **Non rinominare `users`.**
- **Non rinominare in fretta e tutto insieme**, e mai su un sito in produzione
  senza le viste.

### 8.4 Quanto può crescere

Con le immagini fuori, il database resta piccolo per molto tempo:

| Utenti | Righe stimate | Peso del database |
|---|---|---|
| 1 (oggi) | ~10.000 | < 50 MB |
| 1.000 | ~10 milioni | 2-5 GB |
| 100.000 | ~1 miliardo | 200-500 GB |
| 1.000.000 (con social) | decine di miliardi | qualche TB, con partizionamento e repliche |

MySQL regge le prime tre righe senza architetture speciali. È la quarta che
richiede repliche di lettura, partizionamento e probabilmente un archivio dei
messaggi separato — ma è un problema che si affronta con dati veri in mano, non
adesso.

---

## 9. Archivio dei file (immagini, allegati)

### 9.1 Scelta

**Cloudflare R2**, compatibile con l'interfaccia S3, quindi usabile da PHP con la
libreria AWS standard e da React Native allo stesso modo.

Motivo: **il traffico in uscita è gratuito.** Per un'app che serve immagini, la
banda in uscita è la voce che ammazza i conti (su Amazon S3 costa circa 0,09 $
per GB: 5 TB serviti sono ~450 $ al mese, su R2 zero). Lo spazio costa
0,015 $ per GB al mese, con 10 GB al mese gratuiti per sempre.

Alternativa per gli archivi freddi e i backup: Backblaze B2, più economico come
spazio.

### 9.2 Come si nominano i file

Negli archivi a oggetti **le cartelle non esistono**: quella che sembra una
cartella è solo parte del nome. Prendere `u/128/post/2026/8f3a91.webp` costa
esattamente quanto prendere `8f3a91.webp`. Quindi la struttura non serve alla
velocità: serve alle operazioni.

```
u/{user_id}/avatar/{anno}/{codice}-256.webp
u/{user_id}/post/{anno}/{codice}-1600.webp
u/{user_id}/post/{anno}/{codice}-400.webp
u/{user_id}/msg/{anno}/{codice}.pdf
```

- **utente per primo**: cancellare un account = cancellare un prefisso, senza
  lasciare niente in giro (obbligo di legge, §7.8);
- **anno**: permette di spostare il vecchio su una tariffa più economica;
- **codice casuale**, mai il nome originale del file;
- **misura nel nome**: di ogni immagine si salvano 2-3 versioni e negli elenchi
  si serve la piccola. È questo che fa sembrare l'app veloce.

### 9.2.1 File dei contenuti pubblicati

I file di un mazzo pubblicato o venduto **non stanno sotto il prefisso di un
utente**, ma sotto:

```
pub/deck/{listing_id}/{versione}/{codice}-{misura}.webp
```

Motivo: sopravvivono alla cancellazione dell'account di chi li ha pubblicati,
perché nel frattempo altre persone li stanno usando (§14.4.5). Ogni riga di
`files` ha un **contatore dei riferimenti**: il file si cancella dall'archivio
solo quando arriva a zero.

Conseguenza sulla cancellazione di un account: via il prefisso `u/{id}/` e le
righe personali; **restano** gli annunci pubblicati e i loro file. Va detto nelle
condizioni d'uso.

### 9.3 Due contenitori, non uno

- **pubblico** — avatar, immagini dei post: servito attraverso la cache di
  Cloudflare, veloce e gratis;
- **privato** — allegati dei messaggi, documenti: escono solo con collegamento
  firmato che scade in pochi minuti.

Metterli tutti nel contenitore pubblico significa che chi indovina un indirizzo
legge i file degli altri.

### 9.4 Indicizzazione

L'indice sta **nel database**, non nell'archivio: una tabella `files` con
`id, user_id, chiave, mime, byte, larghezza, altezza, created_at`, e i
riferimenti da post/messaggi/attività.

> Regola: il codice non deve **mai** elencare il contenuto di una "cartella"
> dell'archivio per disegnare una pagina. Se serve farlo, vuol dire che
> quell'informazione mancava nel database.

---

## 10. Hosting

### 10.1 Scelta consigliata

| Pezzo | Cosa | Perché |
|---|---|---|
| Server | VPS Hetzner, 2 vCPU / 4 GB / 40 GB | ~4,50 €/mese, 20 TB di traffico inclusi; ci gira tutto lo stack LAMP senza cambiare una riga di codice |
| Davanti | Cloudflare, piano gratuito | certificato, cache, protezione dagli attacchi, zero euro |
| Archivio | Cloudflare R2 | traffico in uscita gratuito |
| Email | Brevo o Resend, piano gratuito | conferma registrazione e recupero password non si mandano dal server, finiscono nello spam |
| Backup | snapshot Hetzner + scarico notturno del database su R2 | il primo salva la macchina, il secondo salva i dati |
| Dominio | qualunque registrar | indipendente dall'hosting |

**Da abbandonare: Altervista.** Va bene per un progetto personale, non per un
sito con utenti veri: nessun controllo sulla configurazione, limiti sulle
connessioni contemporanee, nessun backup serio, nessun accesso a riga di
comando.

### 10.2 Il prezzo vero del VPS

Non sono i soldi: è che te lo amministri tu. Aggiornamenti, firewall,
certificati, backup. Se non si vuole, l'alternativa è un hosting gestito
italiano (3-8 €/mese) con però limiti veri sulle connessioni e sulla dimensione
del database, e un trasloco obbligato il giorno che si cresce.

### 10.3 Cosa si romperà per primo (non il database)

1. **Le interrogazioni senza indice.** Una lista che fa una query per riga sta
   benissimo con un utente e muore con cinquanta.
2. **Le immagini non ridimensionate.** Salvare la foto da 4 MB del telefono e
   mostrarla in un quadratino è banda e attesa buttate.
3. **Le sessioni PHP su disco.** Con molti utenti si spostano su Redis.

### 10.4 Da fare in Google Cloud Console (a ogni cambio di indirizzo)

Il sito passa da `nisama.altervista.org/Vocabolary/` alla radice
`nisama.altervista.org/` (e un giorno a un dominio proprio sul VPS). Google
ricorda gli indirizzi, quindi la console va aggiornata. Console:
https://console.cloud.google.com → progetto di Standout → *API e servizi*.

**Credenziali → ID client OAuth (Google Calendar)**

- *URI di reindirizzamento autorizzati*: aggiungere
  `https://nisama.altervista.org/google_calendar.php?action=callback`
  **senza togliere** quello vecchio (`.../Vocabolary/google_calendar.php?...`).
  Il vecchio resta finche' `GOOGLE_REDIRECT_URI` in `lib/config.php` lo usa.
- *Origini JavaScript autorizzate*: `https://nisama.altervista.org`
  (l'origine non contiene il percorso, quindi di solito non cambia).
- Poi si cambia `GOOGLE_REDIRECT_URI` in `lib/config.php` al nuovo indirizzo e
  si prova il collegamento a Google Calendar.
- Al trasloco sul dominio proprio: ripetere con il dominio nuovo.

**Credenziali → chiavi API (Places, mappa incorporata, TTS)**

- Se la chiave ha *restrizione per referrer HTTP*, il referrer era
  `nisama.altervista.org/Vocabolary/*`: va cambiato in
  `nisama.altervista.org/*` (e poi nel dominio nuovo). Se non si cambia, la
  mappa e la ricerca luoghi smettono di funzionare senza errori evidenti.
- La chiave usata dal server (Places, TTS) deve restare ristretta per
  *indirizzo IP* o per API, non per referrer: le richieste partono dal server.

**Schermata di consenso OAuth**

- Se vi compaiono indirizzi della home, dell'informativa o dei termini,
  aggiornarli al nuovo indirizzo.

**Dopo ogni modifica**: Google impiega da pochi minuti a qualche ora ad
applicarla. Provare in una finestra privata: collegare Google Calendar,
aprire la mappa dei luoghi, far partire una voce.

**Quando farlo**: subito dopo aver caricato la nuova struttura su Altervista
(sito nella radice, file caricati in `media/`), e di nuovo il giorno del VPS.

---

## 11. Costi, per fase

Prezzi di agosto 2026, IVA esclusa (senza partita IVA aggiungere ~22%).
**Da riverificare sui listini prima di decidere**: nel 2026 i prezzi dei server
sono già stati ritoccati due volte.

### 11.1 Fase 0 — nessun utente, solo tu

| Voce | Costo |
|---|---|
| VPS base | 4,50 € |
| Snapshot/backup del server | 0,90 € |
| R2 (sotto i 10 GB gratuiti) | 0 € |
| Cloudflare | 0 € |
| Email (piano gratuito) | 0 € |
| Dominio (~12 €/anno) | 1 € |
| **Totale** | **~7 €/mese** |

### 11.2 Fase 1 — qualche centinaio di utenti, senza social

Il carico cresce poco: sono dati testuali e qualche immagine.

| Voce | Costo |
|---|---|
| VPS 4 vCPU / 8 GB | ~7 € |
| Backup | ~1,40 € |
| R2, 50-200 GB | 1-3 $ |
| Email (oltre il gratuito) | 0-9 € |
| Dominio | 1 € |
| **Totale** | **~12-20 €/mese** |

### 11.3 Fase 2 — qualche migliaio di utenti attivi, social acceso

Qui cambia l'architettura: database su macchina sua, Redis per sessioni e cache.

| Voce | Costo |
|---|---|
| 2 VPS (applicazione + database) | ~20-35 € |
| Backup di entrambi | ~6 € |
| R2, 1-2 TB | 15-30 $ |
| Email transazionale | ~15-25 € |
| Sonde e registri | 0-10 € |
| **Totale** | **~70-110 €/mese** |

### 11.4 Fase 3 — decine di migliaia di utenti

| Voce | Costo |
|---|---|
| 2-3 server applicativi dietro un bilanciatore | ~60-100 € |
| Database dedicato con replica di lettura | ~60-120 € |
| Redis | ~15 € |
| R2, 10-20 TB | 150-300 $ |
| Email, sonde, servizi | ~50-100 € |
| **Totale** | **~400-700 €/mese** |

### 11.5 Fase 4 — un milione di utenti

Qui i numeri dipendono più da *come* si usa l'app che da quanti sono. Ipotesi:
un milione di iscritti, 150.000 attivi al giorno, 100 foto a testa conservate,
tre misure per foto, ~300 KB in media.

| Voce | Stima |
|---|---|
| Archivio (~30-60 TB) | 450-900 $ |
| Server applicativi (6-10 macchine) | 300-600 € |
| Database con repliche e partizionamento | 400-1.000 € |
| Redis / code / lavori di sfondo | 100-200 € |
| Email e notifiche push | 200-500 € |
| Sonde, registri, sicurezza | 100-300 € |
| **Totale** | **~1.800-4.000 €/mese** |

Il modo utile di leggere questa riga: **circa 0,002-0,004 € per utente al mese.**
A quella scala la domanda non è più "quanto costa" ma "da dove arrivano i soldi":
un milione di utenti senza un modo di guadagnare è un problema, non un traguardo.
Vanno previsti per tempo i piani a pagamento (le tabelle `plans` e `user_plans`
esistono già) o un altro modello.

Due voci che a quella scala pesano più di quanto sembri e che spesso vengono
dimenticate: la **moderazione dei contenuti** (persone, non server) e
l'**assistenza agli utenti**.

---

## 12. Lingua (internazionalizzazione)

### 12.1 Scelta

Non gettext (vuole un'estensione PHP e strumenti a parte, sovradimensionato).
**File JSON**, uno per lingua, in `lang/`:

```json
{
  "planner.oggi": "Oggi",
  "todo.rimasti": "{n} rimaste",
  "pastiglie.saltata": "saltata"
}
```

Motivo della scelta del JSON invece dell'array PHP: **gli stessi file li legge
React Native** con `i18next`. Una traduzione sola da mantenere per due mondi.

### 12.2 Regole

- Chiavi per area: `planner.topbar.oggi`, `todo.nuovo_articolo`.
- Funzione `t('chiave', ['n' => 3])` con sostituzione dei buchi `{n}`.
- **Ricaduta a catena:** lingua scelta → italiano → la chiave stessa. Una
  traduzione mancante si vede ma non rompe niente.
- Per il JavaScript, PHP stampa in pagina **solo le chiavi che quella pagina
  usa**, come già fa `PL_BOOT` nel planner.
- Date, ore e numeri passano da `IntlDateFormatter` / `NumberFormatter`. Gli
  array dei nomi di giorni e mesi scritti dentro `planner.js` devono sparire e
  venire da lì.
- Plurali: l'italiano e l'inglese hanno due forme, altre lingue di più. Se si
  vuole essere pronti, si usa la forma di `i18next` (`_one`, `_other`).
- La lingua si sceglie nelle impostazioni, si salva in `users.lang`, e finisce
  in `<html lang="…">`.

### 12.3 Lavoro vero

L'impianto è mezza giornata. La parte lunga è **tirare fuori le stringhe**
sparse fra PHP e JS: si fa mezzo automatico, con uno script che trova i testi in
italiano scritti dentro il codice e propone le chiavi.

Lingue: italiano ed inglese all'inizio. Aggiungere il francese sarà copiare un
file.

---

## 13. Stile e temi

### 13.1 Sul sito: uno stile solo

Il sistema a tre temi scegliibili **non si fa sul sito**. Oggi il sito ha tre
stili perché sono nati in momenti diversi, non perché siano stati scelti: chi
entra da progetti e poi va nel planner pensa di aver cambiato sito.

Quindi: si sceglie **uno** dei tre — quello di planner/attività, il più maturo —
e da lì si estrae:

- `assets/css/base.css` — l'impianto: pulsanti, pastiglie, elenchi, schede,
  finestre, campi. Scritto **solo** contro variabili CSS, mai con un colore
  dentro.
- `assets/css/<pagina>.css` — solo ciò che è davvero unico di quella pagina.
  (Oggi `planner.css` sono 87 KB, di cui buona metà starebbe nella base.)

Le altre pagine ci si allineano una alla volta, partendo dalla più piccola.
`planner.css` si tocca per ultimo, quando la base ha già retto altrove.

### 13.2 Nell'app: i tre temi

Il lavoro sopra non è sprecato: estraendo la base si ottengono i valori dello
stile (colori, caratteri, raggi, ombre, spaziature) raccolti in un posto solo. In
React Native quelli diventano un file di **token del tema**, e i tre temi
(planner, abitudini, progetti) si scelgono cambiando un oggetto. È lì che la
personalizzazione ha senso, non nel foglio di stile di un sito.

---

## 14. PWA, app e parte social

### 14.1 PWA — subito, ma minima

Serve a togliere la barra del browser e ad avere l'icona sulla schermata
iniziale, mentre l'app vera richiederà mesi.

- `manifest.webmanifest` con `display: standalone`, nome, icone (192, 512,
  maskable), colori.
- `sw.js` **conservativo**: rete per prima, copia di riserva solo per le pagine
  statiche. Non una cache aggressiva: con le pagine ancora in evoluzione
  farebbe vedere versioni vecchie e farebbe impazzire a capire perché una
  correzione "non arriva".
- Versione della cache in una costante, cambiata a ogni rilascio.

Mezza giornata di lavoro.

### 14.2 App React Native

- **Rifacimento dell'interfaccia da zero.** Il PHP+JS non si porta dietro.
- Riusa **l'API** (§5.3) e le **traduzioni** (§12).
- Accesso a token (§7.3), nessuna chiave segreta dentro l'app.
- In più rispetto al sito: tre temi, notifiche, funzionamento senza rete,
  e — solo qui — la parte social.
- Consiglio: partire da Expo, e valutare la distribuzione sugli store solo
  quando l'app fa già qualcosa di utile.

### 14.3 Parte social (futuro, solo nell'app)

> **Documento dedicato:** la parte social ha un masterplan suo,
> **`STANDOUT_SOCIAL.md`** (visione, sezioni, regole dei pensieri, matchmaking,
> ordine di rilascio). Quel documento comanda sul *prodotto*; questo comanda
> sulle scelte *tecniche*. Il vecchio PDF "Project Masterplan v1.0" è superato:
> diceva "Vanilla JS, no React" e metteva il social nel sito.

Non si progetta adesso nel dettaglio, ma tre cose vanno decise prima di scrivere
una riga: **chi vede cosa** (permessi), **come si segnala e si blocca**
(moderazione), **quanto spazio ha ogni utente** (quote). Sono le tre che, se
arrivano dopo, obbligano a rifare il resto.

### 14.4 Mercato dei mazzi

Dentro la parte social, quindi **solo nell'app**: le persone pubblicano mazzi di
flashcard già fatti e altri li prendono. Chi li prende può modificarne le carte.

Questa sezione è già decisa nelle sue scelte di fondo. Chi la implementa segua
quello che c'è scritto: ogni punto qui sotto risolve un problema che, se
affrontato dopo, obbliga a rifare lo schema.

#### 14.4.1 Principio: copia con genealogia

**All'acquisto il mazzo viene copiato nell'account di chi compra**, con id nuovi,
e da quel momento è suo: privato, modificabile come tutti gli altri.

La copia però ricorda da dove viene (`source_listing_id`, `source_version`).
Quando l'autore pubblica una versione nuova, a chi ha comprato compare un avviso
— *"l'autore ha aggiornato 12 carte, vuoi vederle?"* — **mai automatico, mai
forzato**.

Le due alternative scartate, e il perché:

- *Solo riferimento al mazzo dell'autore*: gli aggiornamenti arriverebbero da
  soli, ma il tuo studio resterebbe appeso a righe che non controlli, e non
  esisterebbe una risposta buona alla domanda "l'autore ha cancellato la carta
  che avevo modificato".
- *Copia senza genealogia*: semplice, ma una correzione dell'autore non ti
  raggiunge mai.

#### 14.4.2 Si vende un'edizione congelata

L'oggetto in vendita **non** è il mazzo vivo dell'autore, ma una **versione
pubblicata e immutabile**. L'autore continua a lavorare sul suo mazzo privato e,
quando è pronto, pubblica la versione successiva.

Senza questa regola un autore potrebbe vendere un mazzo pieno e poi svuotarlo.

#### 14.4.3 Le tabelle

```
market_listings      annuncio: deck di origine, autore, titolo, descrizione,
                     coppia di lingue, materia, prezzo_cent, valuta, stato
                     (bozza | pubblicato | ritirato), carte di anteprima, creato_il

market_versions      versione congelata di un annuncio: listing_id, numero,
                     note_versione, n_carte, pubblicata_il
                     (le carte della versione stanno in un mazzo bloccato,
                      di proprietà del sistema, non modificabile dall'autore)

market_purchases     acquisto: user_id, listing_id, version_id, prezzo_pagato_cent,
                     valuta, stato_pagamento, id_transazione_esterna,
                     deck_copia_id, consenso_immediato_il, creato_il

market_reviews       user_id, listing_id, voto, testo, creato_il

market_reports       segnalazioni: chi, cosa, motivo, stato (vedi 14.4.7)

market_payouts       (più avanti) incassi dovuti agli autori
```

Sui mazzi dell'utente si aggiungono tre colonne:

```
study_decks / vocab_decks:
  source_listing_id   INT NULL   da quale annuncio viene questa copia
  source_version      INT NULL   quale versione è stata copiata
  is_locked           TINYINT    1 = mazzo congelato di una versione pubblicata
```

#### 14.4.4 Cosa succede all'acquisto, passo per passo

1. Si verifica il pagamento (o, a prezzo 0, si salta).
2. Si crea `market_purchases` con la versione acquistata e la data del consenso
   alla consegna immediata (§14.4.6).
3. Si copiano **le righe**: mazzo, carte, sotto-carte, esempi. Id nuovi,
   proprietario il compratore, `source_listing_id` e `source_version` compilati.
4. **Non si copiano i file.** Le carte copiate puntano alle stesse righe di
   `files` dell'edizione, e il contatore dei riferimenti di ciascun file sale.
5. Il progresso di studio **riparte da zero**: `study_learnt` e `vocab_learnt`
   riferiscono le carte nuove. È corretto: sono carte che quella persona non ha
   ancora studiato.

#### 14.4.5 I file: mai duplicati, e non appartengono più all'autore

Questa è la regola che tiene in piedi i conti. Un mazzo con 300 immagini comprato
da mille persone, se si copiassero i byte, diventerebbe 300.000 file per un
contenuto che ne ha 300.

- La copia duplica **le righe**, non i byte: le carte copiate puntano allo stesso
  file, con un **contatore dei riferimenti** sulla riga di `files`.
- Quando il compratore **sostituisce** un'immagine, allora nasce un file nuovo
  sotto il suo prefisso, e il contatore del vecchio scende.
- Il file si cancella dall'archivio **solo quando il contatore arriva a zero**.

**Questo cambia la regola dell'archivio scritta in §9.2.** I file di un contenuto
pubblicato non possono stare sotto il prefisso dell'autore: il giorno che
l'autore cancella l'account sparirebbero le immagini di mille compratori. Quindi:

```
al momento della pubblicazione i file passano in:
    pub/deck/{listing_id}/{versione}/{codice}-{misura}.webp
```

Quel prefisso **non appartiene a nessun utente** e sopravvive alla cancellazione
dell'account. Va scritto nelle condizioni d'uso, in modo esplicito: *quello che
pubblichi e vendi non sparisce insieme al tuo account*.

La cancellazione di un account, quindi, diventa: via il prefisso `u/{id}/`, via
le righe personali, **restano** gli annunci pubblicati e i loro file (con
l'autore reso anonimo, se richiesto).

#### 14.4.6 Prezzi, pagamenti, rimborsi

**I prezzi si mettono da subito nello schema** (`prezzo_cent`, `valuta`) ma
**partono tutti a 0**: così il meccanismo esiste già e accendere i pagamenti non
richiederà una migrazione.

Quando si accenderanno davvero:

- **Chi incassa.** Due strade: Stripe, e allora gli obblighi fiscali sono del
  proprietario (regime OSS, IVA nel paese di ogni compratore europeo); oppure un
  intermediario tipo Paddle o Lemon Squeezy, che è lui il venditore verso il
  compratore e si prende in carico l'IVA, trattenendo una percentuale più alta.
  **Per partire: l'intermediario.** Da decidere prima di scrivere una riga sui
  pagamenti, perché cambia tutto lo schema degli incassi.
- **Rimborsi: non sono obbligatori**, a una condizione. Sui contenuti digitali il
  diritto di ripensamento di quattordici giorni **si perde** se il compratore
  acconsente espressamente alla consegna immediata e dichiara di sapere che così
  vi rinuncia. Serve quindi, all'acquisto, una **casella non pre-spuntata** con
  scritte chiaramente le due cose, e la data del consenso salvata sull'ordine
  (`consenso_immediato_il`). *Nota: va fatto verificare da un legale prima di
  incassare davvero.*
- **Ma con un intermediario la politica dei rimborsi la decide lui**, perché il
  venditore è lui. E i ripudi di pagamento esistono comunque.
- **Resa da accettare:** chi ottiene un rimborso ha già la copia e non gliela si
  può togliere. Finestra di rimborso stretta, e pace.
- A prezzo 0 **niente di tutto questo si applica**: nessun pagamento, nessun
  rimborso, nessuna IVA.

#### 14.4.7 Moderazione e diritto d'autore

Un mercato di flashcard universitarie **si riempirà di foto prese dai manuali**.
Non è un rischio ipotetico, è la normalità del settore.

Serve, dal primo giorno in cui si può pubblicare:

- un pulsante di **segnalazione** su ogni annuncio (`market_reports`);
- una procedura di **rimozione**, con l'annuncio ritirabile subito e i compratori
  avvisati;
- la possibilità di **bloccare un venditore**;
- condizioni d'uso che dicano chi risponde di cosa.

Non è una funzione da rimandare: è la condizione per non trovarsi una diffida.

#### 14.4.8 Da dove si comincia

**Prima la condivisione gratuita, senza soldi.** Pubblica un mazzo, un altro lo
prende. È esattamente lo stesso meccanismo — edizione congelata, copia con
genealogia, file nel prefisso pubblico, contatore dei riferimenti — senza
pagamenti, senza IVA, senza rimborsi.

Se funziona, il mercato è quella cosa lì più un prezzo e un incasso.

#### 14.4.9 Cosa NON fare

- Non far puntare le carte comprate a quelle dell'autore.
- Non copiare i byte dei file.
- Non lasciare i file di un contenuto pubblicato sotto il prefisso dell'autore.
- Non lasciare che l'autore modifichi una versione già venduta.
- Non accendere i pagamenti prima di aver deciso chi incassa.
- Non aprire la pubblicazione prima che esista il pulsante di segnalazione.

---

### Pagina To do (25/10/2026)

- **Nota veloce** in cima (`todo_nota.js`, tabella `todo_notes`, azioni `nota_leggi/nota_salva/nota_applica` in `todo_api.php`): si salva da sola (subito in `localStorage`, dopo 0,7 s sul server). **Smista a mano** (regole di `todo_smart.js`, gratis) o **con l'IA** (`todo_nota_ai.php`, crediti): in entrambi i casi si apre la schermata di conferma (modifica, togli, annulla, salva). Le righe non salvate restano nella nota.
- **Barra intelligente** per il to do (`todo_barra.js` + `todo_smart.js`): data, ora, durata e `#progetto` dalla frase, con le stesse parole di `planner_smart.json`. Gruppi per giorno, righe pulite a riposo, scorciatoie data a riga aperta. Stile in `todo_nuovo.css` (togliendolo si torna a prima). Il to buy ha la sua barra in `todo_buy.js`.
- **«To study» non esiste più nella pagina To do** (25/10/2026): niente linguetta, niente smistamento verso lo studio. Lo studio vive nel planner (blocchi di studio) e nei progetti. Migrazione `2026-10-25_todo_senza_study.sql`: le attività già in «to study» diventano «to do». Il pannello settimana del planner ha la sua linguetta studio, a parte, e non è stata toccata.
- **To buy in due vesti** (`todo_buy.js` disegna, `todo_buy.css` veste): **standard = «Corsie»** (una scheda per categoria tinta col colore della categoria via `color-mix`, quindi giusta anche in scuro; totale fisso in basso con tasto «Carrello»), **paper = «Scontrino»** (striscia di carta con bordo strappato, titoli in corsivo con linea tratteggiata e subtotale, righe puntinate, barra a mano in Caveat). Il codice produce sempre gli stessi pezzi (`.tb-paper`, `.tb-cap`, `.tb-lead`, `.tb-foot`); il CSS mostra quelli della veste attiva (`html[data-stile]`). Barra con microfono (una frase = un articolo).

## 15. Ordine dei lavori

Ogni passo ha un criterio di "fatto": senza, si va avanti a metà.

| # | Lavoro | Fatto quando |
|---|---|---|
| 1 | **Verifica di sicurezza automatica** (§7.2) | esiste l'elenco dei punti deboli per file, ordinato per gravità |
| 2 | **Correzione dei punti gravi** | nessuna query concatenata, nessun input non validato, nessuna scrittura senza controllo di proprietà |
| 3 | **`bootstrap.php` e riordino cartelle** (§4) | ogni pagina include il bootstrap; `lib/`, `sql/`, `data/` irraggiungibili dal browser |
| 4 | **Migrazioni in sospeso** (§8.2) | luoghi e pastiglie funzionanti in produzione |
| 5 | **Trasloco su VPS + Cloudflare + backup notturni** (§10) | il sito gira sul dominio nuovo, e un backup è stato **riprovato** con successo |
| 5b | **Rinomino delle tabelle** con le viste di compatibilità (§8.3.5) | tutte le tabelle hanno il prefisso della loro area; il sito funziona ancora *attraverso le viste* |
| 5c | **Correzione delle query** area per area, poi via le viste | nessuna vista di compatibilità è più necessaria |
| 5d | **Rinomino delle colonne** `word`/`translation` + lingue sul mazzo (§8.3.3-4) | un mazzo di francese si crea senza toccare lo schema; **le pagine che scrivono sono state corrette per prime** |
| 5e | **`sql/commenti.sql`** (§8.3.6) | ogni tabella ha una descrizione visibile in phpMyAdmin |
| 6 | **Archivio R2 e trasloco dei file** (§9) | nessun file utente più sul disco del server |
| 7 | **Accesso a doppio binario cookie/token** (§7.3) | un client di prova entra con il token e legge i propri dati |
| 8 | **PWA minima** (§14.1) | l'app si installa e si apre senza barra del browser |
| 9 | **Estrazione dell'API `/api/v1/`** (§5) | planner, attività e abitudini hanno endpoint JSON separati dalle pagine |
| 10 | **CSS unificato a uno stile solo** (§13.1) | `base.css` esiste e almeno tre pagine ci si appoggiano |
| 11 | **Lingua in JSON** (§12) | il sito si vede tutto in inglese cambiando un'impostazione |
| 12 | **App React Native** | l'app fa quello che fa il sito, riusando l'API |
| 13 | **Condivisione gratuita dei mazzi** (§14.4.8) | un mazzo pubblicato viene copiato da un altro utente, con genealogia e file non duplicati |
| 14 | **Mercato con i prezzi accesi** (§14.4.6) | deciso chi incassa, consenso alla consegna immediata registrato, segnalazioni funzionanti |
| 15 | **Parte social nell'app** | — |

I passi 1-2 vanno fatti **prima** di aprire il sito ad altre persone. Finché
l'unico utente sei tu, un buco è un problema tuo; con dentro dati di altri
diventa un guaio serio, e in Europa anche con regole precise da rispettare.

---

## 16. Decisioni ancora aperte

Chi lavora al progetto **non deve inventare risposte a queste domande**: vanno
chieste al proprietario.

1. **L'hosting permette di mettere cartelle sopra la radice pubblica?** Da
   questo dipende se `lib/` e `sql/` si nascondono davvero o si difendono con
   `.htaccess`.
2. **Dominio scelto** e registrar.
3. **Quante pagine PHP esistono in tutto** e se tutte includono `config.php`, o
   se qualcuna si collega al database per conto suo.
4. Le sezioni vecchie (flashcards, giochi, università, inglese) **restano,
   vanno rifatte, o vanno archiviate?** Cambia parecchio il lavoro dei passi
   1-3.
5. **Modello di guadagno** (piani a pagamento? quando?). Le tabelle `plans` e
   `user_plans` ci sono già.
6. Nome pubblico e identità visiva dell'app.
7. **Chi incassa nel mercato: Stripe o un intermediario** tipo Paddle/Lemon
   Squeezy? Cambia tutto lo schema degli incassi e chi risponde dell'IVA
   (§14.4.6). Non serve finché i mazzi sono a 0 €.
   Collegata: **quale percentuale trattiene la piattaforma** sugli autori.
8. **`gameplays` e `fc_gameplays`: quale serve a quale gioco?** Serve per dare
   loro i nomi giusti (§8.3.2).
9. `meaning` ed `example` delle carte di vocabolario sono scritti nella lingua da
   imparare — **da confermare**, perché nel documento è dichiarato come
   convenzione e da lì in poi il codice ci farà affidamento.

### 16.1 Decisioni già prese (non riaprirle senza motivo)

- I mazzi si chiamano **`decks`**, non `categories`.
- Il sito **non avrà** la parte social né la scelta del tema.
- L'app sarà **React Native**, non un guscio attorno al sito.
- Le traduzioni stanno in **JSON**, non in array PHP, perché servono a entrambi.
- Le immagini **non** vanno nel database.
- Si rinomina **al trasloco**, con le viste di compatibilità, non prima.
- **I mazzi restano privati** e le traduzioni restano sulla carta: il mercato
  funziona a copia, quindi la coppia di lingue sul mazzo basta (§14.4.1). La
  tabella `vocab_translations` **non si fa**.
- Il mercato usa la **copia con genealogia**, si vende un'**edizione congelata**,
  i **file non si duplicano** e i contenuti pubblicati vivono sotto `pub/`.
- I **prezzi esistono nello schema da subito, ma partono tutti a 0 €**.

---

## 17. Riferimenti rapidi

- **Convenzioni di codice:** §3 — commenti in italiano che spiegano il perché,
  blocchi piccoli, verifica della sintassi, niente riscritture.
- **Funzioni di sicurezza:** `sec_str`, `sec_int`, `sec_float`, `sec_enum`,
  `sec_date`, `sec_time`, `sec_color`, `sec_email`, `sec_bool`, `sec_owns`,
  `sec_h`, `sec_q`, `sec_one`, `sec_all`, `sec_json`, `sec_die_json`,
  `sec_guard_write`, `sec_csrf_token`.
- **Cose che esistono già e non vanno reinventate:** `rate_limits`,
  `security_audit_log`, `push_subscriptions`, `roles`/`user_roles`,
  `plans`/`user_plans`, `remember_tokens`, `login_attempts`, `place_cache`,
  `weather_cache`.
- **Trappole note sui nomi:** `child` nelle tabelle di studio significa
  *figlia*, non *per bambini*; `plans` è la fatturazione, non il planner;
  `images` sono le carte di vocabolario, `fc_images` le flashcard
  dell'università; `categories` e `fc_categories` sono mazzi annidati, non
  etichette piatte.
- **Trappole note nel codice:** gli endpoint del planner stanno *dentro* `planner.php`;
  `sidebar.php` innesta stili e script estratti da `sidebar.html` (una copia
  sola, non due); nel planner non vanno reintrodotte letture di layout in ciclo.

---

### Il piano dei progetti (26/10/2026)

Sostituisce il vecchio «to study» (il calendario dentro i Progetti) con uno strumento solo, in minuti. File: `public/projects/piano_api.php` (API e pianificatore), `piano.js` (le viste), `piano.css`; stringhe nel gruppo `piano` di `lang/*.json`; tabelle `plan_goals`, `plan_paint`, `plan_days`, `plan_settings`, `plan_templates` (si creano da sole alla prima richiesta; migrazione `sql/migrations/2026-10-26_piano.sql`).

- **Dentro un progetto**: tre viste, *Elenco · Calendario · Piano* (`#ppViews` in `progetti.php`, `?pp=cal|plan` nell'indirizzo). **Calendario**: si sceglie un sotto-progetto e si evidenziano i giorni passando il dito o il mouse come con un evidenziatore (il primo giorno toccato decide se si accende o si spegne; strumenti Evidenzia / Cancella / Guarda). Un giorno evidenziato per un progetto vuol dire «solo in questi giorni». Un tocco su «Guarda» apre il giorno: blocchi, +/− 15 minuti, tempo libero di quel giorno. **Piano**: le ore del progetto (ore o giorni), tipo (esame, costante, corso, a elementi, semplice), priorità (da fare, dovrei, se avanza), date, e la **divisione fra i figli**: un cursore per figlio, gli altri danno o prendono in proporzione, il lucchetto tiene fermo un valore, il totale resta sempre uguale; vale anche per i nipoti (cambiando un figlio, i suoi figli si ridimensionano).
- **«Progetti → Calendario»** (tutti i progetti): *Oggi* (blocchi del giorno con tasto per avviare il pomodoro, cosa è stato saltato ieri con «Spostalo / Lascia stare», la settimana, i giorni all'esame), *Calendario* (stesso evidenziatore, sugli obiettivi principali) e *Panoramica* («ci sta tutto?», ore per settimana contro il tempo libero, gli obiettivi con le ore).
- **«Pianifica per me»**: anteprima («ci sta / mancano 16 h», cosa non entra e perché), poi Applica. Non tocca i blocchi messi a mano. Il tempo libero lo misura da solo (media dei giorni con pomodori degli ultimi 60, feriali e weekend a parte), meno gli eventi con orario del planner; l'utente lo corregge in «Il tuo tempo» (giorno di riposo, giorni cuscinetto prima di un esame, blocco minimo, cose al giorno, ripassi a 1/3/7 giorni).
- **Dal vecchio piano**: «Porta le stime nel nuovo piano» trasforma i pomodori stimati in minuti; i modelli di piano si salvano per nome di percorso e si riusano.
- **Non ancora fatto**: i blocchi del piano non compaiono ancora come eventi nel planner; la revisione settimanale. I vecchi file (`planner/to_study*.php/js/css`, `study_api.php`) restano finché non si conferma che il nuovo strumento basta.

