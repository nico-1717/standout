# Nisama — riepilogo del lavoro svolto

Documento di consegna. Riassume cosa è stato fatto su sicurezza, CSS e lingua,
elenca le falle trovate, e raccoglie le cose da sapere per non rompere niente.

**Numeri:** 46 file PHP, 34 fogli CSS, 4 file JavaScript, 2584 chiavi di
traduzione in 14 gruppi, 8 strumenti di verifica.

---

## 1. Come caricare

I file vanno tutti in `/Vocabolary/`, piatti. Servono tre cartelle:

```
/Vocabolary/lang/     → it.json, en.json
/Vocabolary/vendor/   → gsap.min.js, ScrollTrigger.min.js, chart.umd.js
/Vocabolary/icons/    → le icone della PWA
```

**Due cose da fare sul server, indipendenti dal codice:**

1. Nella cartella delle immagini (`IMAGE_PATH_INSERT`, `fc_images/`, `fc_audio/`)
   metti un `.htaccess` con:
   ```apache
   php_flag engine off
   ```
   È una difesa che vale anche per il codice che non ho ancora aperto: se un file
   caricato riesce comunque a finire lì con estensione `.php`, non viene eseguito.

2. Il client FTP deve trasferire i `.php` in **binario**, non in ASCII. In
   `big_edit_fc.php` ho trovato sei stringhe con doppia codifica UTF-8
   (`â†³ Figlio` invece di `↳ Figlio`): nascono così.

**Da cancellare dal server, se c'è ancora:** `diagnostica_pomodoro.php`, il file
temporaneo scritto per il problema delle pastiglie. E `piper_voice.php`, che mi
hai detto è già eliminato.

**Due migrazioni SQL** sono nella consegna: `migrazione_lingua.sql` (la colonna
della lingua scelta) e `migrazione_progetto_id.sql`.

---

## 2. Le falle trovate, in ordine di gravità

### 2.1 Iniezione SQL — una sola, in `game_fc.php`

```php
$order_param = $_GET['order'];
mysqli_query($conn, "UPDATE games SET game_order = '$order_param' WHERE id = $game_id");
```

Il valore arriva dall'indirizzo e finisce dentro apici senza validazione né
escape. Un apice nella querystring chiude la stringa e da lì si scrive SQL a
piacere. **È l'unica iniezione vera in tutto il progetto.** Ora è un elenco
chiuso di due valori (`in_order`, `random`) passato come parametro.

### 2.2 Endpoint senza alcun controllo di accesso — quattro file

`examples_api.php`, `update_learnt.php`, `start_game_fc.php`,
`api_get_counts_fc.php` includevano solo `config.php`: rispondevano a
**chiunque**, anche senza login. Tre di questi prendevano l'id utente dal client:

```php
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 1;
```

Bastava mandare un numero diverso per scrivere nello storico di un altro utente o
leggere quante schede ha. E una chiamata senza parametri finiva sull'utente 1.
`examples_api.php` era il peggiore perché riguardava anche la **lettura**:
chiunque poteva leggere, modificare e cancellare gli esempi di qualsiasi scheda.

### 2.3 File caricati senza controlli — 28 punti in 8 file

Il difetto era sempre lo stesso:

```php
$ext = pathinfo($_FILES['x']['name'], PATHINFO_EXTENSION);
$nome = time() . "_" . $ext;
move_uploaded_file($_FILES['x']['tmp_name'], CARTELLA . $nome);
```

Estensione presa dal nome scelto dal client, contenuto mai guardato. Caricare
`x.php` significava piazzare un file eseguibile in una cartella raggiungibile dal
browser: esecuzione di codice sul server.

I due casi peggiori (`inserimento_big.php`, `inserimento_big_fc.php`) abbinavano
il file **per nome**: bastava caricare un `.php` e scrivere quel nome nella
colonna immagine di una riga.

Ora tutto passa da `sec_file()` (tipo vero letto con `finfo`) e
`sec_safe_filename()` (nome generato dal server). Per l'audio ho passato a
`sec_file()` un elenco di MIME suo, perché quello predefinito è per le immagini.

### 2.4 Azioni di massa senza controllo di proprietà

`visualizzazione.php` e `visualizzazione_fc.php`: elimina, sposta, unisci,
separa e il riordino prendevano gli id dal modulo e li usavano così:

```php
$ids_str = implode(',', array_map('intval', $_POST['selected_ids']));
mysqli_query($conn, "DELETE FROM images WHERE id IN ($ids_str)");
```

Non era iniezione — gli id erano interi — ma non serviva: bastava cambiare i
numeri per cancellare le parole di un altro utente. Ho aggiunto funzioni che
filtrano un elenco di id tenendo solo il proprio (`en_solo_miei`, `fc_solo_mie`,
`fc_figli_miei`), passando dalla categoria e per i figli dal padre.

**Nel riordino c'è una sottigliezza:** dopo il filtro l'ordine va ricostruito
rispettando la sequenza scelta dall'utente, non quella del database. Usando
direttamente la lista filtrata, trascinare una carta l'avrebbe rimessa dov'era.

### 2.5 Colore a cascata che scriveva senza filtro

`categories_fc.php`, in `cs_cascade_color()`:

```php
UPDATE fc_categories SET color = '$colorEsc' WHERE id = ...
```

Nessun `AND user_id`. Passando l'id di una materia altrui si cambiavano i colori
delle categorie di un altro. Le altre due funzioni del colore leggevano senza
filtro, quindi risalivano anche l'albero di un altro utente.

### 2.6 XSS — il nome dentro `onclick`, quattro pagine

```php
onclick="apriSposta(<?= $id ?>, '<?= addslashes($nameEsc) ?>')"
```

`addslashes` protegge dagli apici ma **non** dal carattere `<`. Un nome
contenente un tag di chiusura di script chiudeva il blocco e il resto della
pagina veniva interpretato come HTML. Trovato in `progetti.php`,
`categories.php`, `categories_fc.php`. Ora il dato sta negli attributi `data-`,
scritto con `sec_h()`, e il JavaScript lo legge da lì.

`modifica_immagine_fc.php` aveva la variante peggiore: costruiva JavaScript con
valori del POST senza alcun controllo, poi lo stampava in pagina. Ora il salto si
fa con `header('Location:')`.

### 2.7 Fughe di dati

- `stats_fc.php`: `SELECT ... FROM fc_categories ORDER BY id` senza filtro, cioè
  le categorie di **tutti** gli utenti del sito.
- `api_get_counts_fc.php`: i conteggi comprendevano le schede di tutti.
- `category_selector.php` e `visualizzazione_fc.php`: la funzione del colore
  leggeva qualunque riga.
- `ai_generate_text.php` e `examples_api.php`: rimandavano al browser
  `mysqli_error()`, cioè una mappa dello schema del database.

### 2.8 Protezione CSRF assente — quasi tutte le pagine

Nessuna delle pagine di vocabolario e flashcard aveva `sec_guard_write`. Un
altro sito poteva far cancellare una categoria, svuotare lo storico o inserire
parole dal browser di chi è connesso. Aggiunta ovunque.

---

## 3. Le azioni che scrivevano via GET — risolto

Il problema era questo: `sec_require_csrf()` esce subito sulle richieste GET.

```php
if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return;
```

Ed è corretto che faccia così — un token scritto nell'indirizzo finisce nella
cronologia del browser, nei log del server e nel campo `Referer` mandato ai siti
esterni. Ma finché l'azione viaggiava in GET, quel controllo non proteggeva
niente: bastava un tag immagine su un altro sito

```html
<img src="https://.../game_fc.php?game_id=5&action=concludi">
```

per far chiudere una partita dal browser di chi è connesso.

### Come l'ho risolto

Un file nuovo, **`azione_post.php`**, stampa una volta per pagina un modulo
nascosto con il token e la funzione `nisamaAzione()`, che lo riempie e lo invia
in POST. Per l'utente non cambia niente: la pagina cambia come prima. Per il
server sì: ora `sec_require_csrf()` confronta il token e rifiuta le richieste che
vengono da fuori.

In `game_fc.php` ho cambiato **un solo punto** invece di riscrivere venti pezzi
di markup: la funzione `navigateTo()`, da cui passano tutte le navigazioni del
gioco. Riconosce i parametri che sono un'azione (`action`, `result`, `img_id`) e
li sposta nel corpo di una richiesta POST, lasciando nell'indirizzo solo quelli
di navigazione (`game_id`, `position`, `status`), che non scrivono e devono
restare nella barra per far funzionare il tasto indietro.

Gli indirizzi scritti negli `href` e nei `data-url` restano quindi come erano:
dentro il nostro JavaScript sono solo un modo comodo di trasportare i parametri,
e non diventano mai una vera richiesta GET. **Effetto collaterale utile:** se il
JavaScript non parte, quei link portano alla pagina *senza compiere l'azione* —
il server ignora le azioni che non arrivano in POST. Fallisce nel verso giusto.

Lato server, in `game_fc.php`:

```php
$azione = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = sec_enum($_POST['action'] ?? null, ['grade','concludi','newsession','setorder'], '');
    if ($azione !== '') sec_guard_write($current_user_id, 'fc_game', 0, 300, 60);
}
```

Un dettaglio che ho dovuto cambiare di conseguenza: quando le carte finiscono, il
codice rimandava a `?action=concludi`; da un redirect non si può fare un POST,
quindi ora manda direttamente a `result_fc.php`.

`history_fc.php` conserva il ripiego senza JavaScript per la cancellazione, con
il modulo scritto direttamente nell'HTML: continua a funzionare anche a
JavaScript spento.

## 4. Lo stato dei file, per gruppo

Colonne: **S** sicurezza, **C** CSS estratto, **L** lingua. Un trattino significa
che non si applica (per esempio un endpoint non ha CSS).

### Pianificazione
| File | S | C | L | Note |
|---|:-:|:-:|:-:|---|
| `planner.php` | ✅ | ✅ | ✅ | 146 query, era già a norma |
| `habits.php` | ✅ | ✅ | ✅ | da 29 prepare manuali a `sec_q` |
| `habits_import.php` | ✅ | ✅ | ✅ | + mappatura colonne interattiva |
| `meteo_api.php` | ✅ | — | ✅ | descrizioni meteo, punti cardinali |
| `planner.js` | — | — | ✅ | 477 chiavi |
| `habits.js` | — | — | ✅ | 230 chiavi, corretto bug timezone |

### Produttività
| File | S | C | L |
|---|:-:|:-:|:-:|
| `pomodoro.php` | ✅ | ✅ | ✅ |
| `pomodoro_storico.php` | ✅ | ✅ | ✅ |
| `progetti.php` | ✅ | ✅ | ✅ |
| `voti.php` | ✅ | ✅ | ✅ |
| `todo.php`, `todo_widget.php` | ✅ | ✅ | ✅ |
| `diario.php`, `diario.js` | ✅ | ✅ | ✅ |

### Struttura
`index.php`, `dashboard_content.php`, `landing_content.php`, `pwa_head.php`,
`i18n.php` — tutti chiusi. Più il file nuovo **`azione_post.php`** (§3).

### Vocabolario (6 pagine + 1 endpoint)
| File | S | C | L | Falle trovate |
|---|:-:|:-:|:-:|---|
| `categories.php` | ✅ | ✅ | ✅ | CSRF, XSS onclick, padre non verificato |
| `visualizzazione.php` | ✅ | ✅ | ✅ | azioni di massa senza proprietà |
| `inserimento.php` | ✅ | ✅ | ✅ | upload |
| `inserimento_big.php` | ✅ | ✅ | ✅ | upload ×2 (abbinamento per nome) |
| `modifica_immagine.php` | ✅ | ✅ | ✅ | upload, `$table` in query |
| `big_edit.php` | ✅ | ✅ | ✅ | N+1 sui figli |
| `big_edit_save.php` | ✅ | — | ✅ | upload, CSRF, riscritto da zero |

### Flashcard (10 pagine + 4 componenti + 8 endpoint)
| File | S | C | L | Falle trovate |
|---|:-:|:-:|:-:|---|
| `game_fc.php` | ✅ | ✅ | ✅ | **iniezione SQL**; azioni portate in POST (§3) |
| `visualizzazione_fc.php` | ✅ | ✅ | ✅ | azioni di massa, riordino |
| `categories_fc.php` | ✅ | ✅ | ✅ | cascata colore che scriveva |
| `stats_fc.php` | ✅ | ✅ | ✅ | fuga categorie; bug `progetto_id` |
| `history_fc.php` | ✅ | ✅ | ✅ | CSRF su cancellazione |
| `result_fc.php` | ✅ | ✅ | ✅ | N+1 (50 query → 2) |
| `big_edit_fc.php` | ✅ | ✅ | ✅ | N+1; 6 stringhe corrotte |
| `inserimento_fc.php` | ✅ | ✅ | ✅ | upload ×4 (2 audio) |
| `inserimento_big_fc.php` | ✅ | ✅ | ✅ | upload ×6 |
| `modifica_immagine_fc.php` | ✅ | ✅ | ✅ | **XSS nel redirect**, upload ×4 |
| `categories_game_fc.php` | ✅ | ✅ | ✅ | nessuna falla |
| `category_selector.php` | ✅ | ✅ | ✅ | padre non verificato, colore |
| `examples_modal.php` | — | ✅ | ✅ | componente puro |
| `floating_player.php` | — | ✅ | ✅ | componente puro |
| `voice_game_mode.php` | — | ✅ | ✅ | comandi vocali riprogettati |
| `examples_api.php` | ✅ | — | ✅ | **nessun login**, upload |
| `update_learnt.php` | ✅ | — | — | **nessun login**, user_id dal client |
| `api_get_counts_fc.php` | ✅ | — | — | **nessun login**, fuga conteggi |
| `start_game_fc.php` | ✅ | — | — | **nessun login** |
| `start_retry.php` | ✅ | — | — | partita non verificata |
| `big_edit_save_fc.php` | ✅ | — | ✅ | upload ×12, riscritto da zero |
| `ai_generate_text.php` | ✅ | — | ✅ | scheda non verificata |

---

## 5. I due errori 500 sul sito, e cosa hanno insegnato

Dopo il primo caricamento sul server sono usciti due 500 e una pagina di
statistiche completamente vuota. Le cause erano tre, tutte diverse, e vale la
pena raccontarle perché sono tre classi di errore distinte.

### 5.1 `stats_fc.php` — una tabella che non è mai esistita

La tabella **`fc_user_goals` non esiste nel database**. Nel codice originale le
due query che la usano avevano una chiocciola davanti:

```php
$goalRes = @mysqli_query($conn, "SELECT daily_goal FROM fc_user_goals ...");
```

La chiocciola zittisce l'errore: la pagina funzionava, ma l'obiettivo giornaliero
**non si è mai salvato** e tornava sempre al valore predefinito. Convertendo a
`sec_q` l'errore non è più zittito, e in PHP 8 una query fallita solleva
un'eccezione: 500 secco.

Due cose fatte: la migrazione **`migrazione_fc_user_goals.sql`** che crea la
tabella (da lì in poi l'obiettivo si salva davvero), e le due query rese
tolleranti con un `try/catch`, così se la tabella manca la pagina usa il valore
predefinito invece di cadere.

**La lezione:** una chiocciola davanti a una query nasconde un difetto, non lo
risolve. Quando si toglie, salta fuori — e va bene così, ma bisogna aspettarselo.
Le altre due chiocciole del progetto erano in `stats_fc.php` (la query dei
progetti) e in `category_selector.php`: entrambe risolte.

### 5.2 Tre nomi di colonna sbagliati

Trovati con uno strumento nuovo, `controlla_colonne.py`, che confronta le query
con lo schema vero del dump SQL:

- `fc_child_cards` non ha la colonna `id` ma **`id_child`**: l'avevo sbagliata in
  `visualizzazione_fc.php` e `modifica_immagine_fc.php`. Errori miei.
- In `stats_fc.php` avevo corretto il nome nella query (`progetto_id` →
  `project_id`) ma **non nel codice che leggeva il risultato**.
- In `pomodoro.php` il caso opposto, e questo c'era già nell'originale:
  `pomodoro_current_breaks` usa davvero `progetto_id`, e la query cercava
  `project_id`. Falliva da sempre in silenzio perché è un `LEFT JOIN`.

### 5.3 Le statistiche tutte a zero — una dipendenza mancante

Questo è il più istruttivo. `stats_fc.php` non dava più errore ma mostrava
grafici bianchi e numeri a zero. Il PHP era a posto: montando un database vero e
rendendo la pagina, i blocchi JSON arrivavano al browser **pieni**.

A rompersi era il JavaScript, e per un motivo solo: **la funzione `T()` non
esisteva**. La pagina la chiama al primo rigo del suo script, per i nomi dei
mesi; il blocco muore con un `ReferenceError` e tutto quello che viene dopo non
gira. Nessun errore visibile a parte la console.

`T()` viene da `i18n.js`, caricato da `nisama_i18n_json()`. Se quel file non è
sul server, o è una versione vecchia, succede questo.

**La correzione non è "ricaricare il file".** Un file JavaScript che non si carica
non deve poter svuotare una pagina intera — tanto più che il dizionario è **già
dentro la pagina**, nel blocco JSON scritto dal server. Quindi `i18n.php` ora
stampa, prima di `i18n.js`, una `T()` minima che legge quello stesso dizionario.
Se `i18n.js` arriva, la sostituisce con quella completa; se non arriva, la pagina
funziona lo stesso. Verificato eseguendo il JavaScript della pagina resa, con e
senza `i18n.js`.

**La lezione generale:** una dipendenza mancante non deve mai poter azzerare una
pagina in silenzio, soprattutto quando i dati per farne a meno sono già lì.

### 5.4 I grafici bloccati dalla CSP

Dopo aver sistemato la `T()`, `stats_fc.php` mostrava ancora tutto vuoto, con
questo in console:

```
Loading the script 'https://cdnjs.cloudflare.com/.../chart.umd.min.js'
violates the following Content Security Policy directive:
"script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net"
Uncaught ReferenceError: Chart is not defined
```

La pagina caricava Chart.js da **cdnjs.cloudflare.com**, che la CSP del sito non
consente. Il browser blocca il file, la libreria non c'è, e il primo grafico
lancia un errore che ferma tutto il resto dello script — di nuovo pagina vuota e
numeri a zero, per una causa completamente diversa dalla precedente.

Ora punta a `vendor/chart.umd.js`, il file già ospitato localmente.

**Il difetto era però già stato corretto una volta, a inizio lavoro.** È tornato
perché a un certo punto sono ripartito dal file originale per rifare la
conversione da capo, e ho perso quella correzione. È il motivo per cui ho scritto
`controlla_csp.py`: una correzione a mano vale una volta, un controllo vale ogni
volta.

Lo strumento legge i domini consentiti dalla CSP **vera**, in
`security/security_core.php`, così se un giorno la si allarga lo strumento la
segue da solo:

```
python3 controlla_csp.py security_core.php *.php
```

### 5.5 Come li ho trovati: il banco di prova

Per questi problemi ho smesso di indovinare e ho montato un ambiente vero nel
contenitore: PHP 8.3 con mbstring e mysqli, MariaDB con il tuo schema caricato e
dati di prova, più un DOM finto in node per eseguire il JavaScript della pagina
resa. Da lì in poi le risposte sono arrivate in pochi minuti invece che per
tentativi.

Se ti serve rifarlo: `php -l` da solo prende gli errori di sintassi; per il resto
serve poter **eseguire**. Un `error_log` letto sul server vale quanto tutto
questo, ed è a portata di mano: Altervista lo scrive nella cartella del sito.

## 6. Bug funzionali corretti (non di sicurezza)

- **`progetto_id` invece di `project_id`** in `stats_fc.php`. La query che collega
  le flashcard ai progetti pomodoro falliva da sempre, zittita da una `@`. La
  sezione "Tempo investito" mostrava zero minuti di pomodoro anche quando ce
  n'erano.
- **Timezone in `habits.js`**: `weekStartOf()` usava `toISOString()`, che converte
  a UTC. In Italia la domenica finiva nella settimana successiva. Corretto con
  una funzione locale. Stesso bug in `intervalProgress()`, e lato server
  `hb_current_streak` usava `$cursor -= 86400`, che sbaglia sui cambi d'ora: ora
  usa `strtotime('-N day')`.
- **N+1 sui figli** in `big_edit.php`, `big_edit_fc.php`, `result_fc.php`: una
  query per ogni riga padre. Con cento schede erano centouno viaggi al database,
  uno dietro l'altro. Ora sono due.
- **`if ($res)` su un array**: `mysqli_query` restituiva una risorsa, `sec_all`
  restituisce un array — e un array **vuoto** è falso. In `category_selector.php`
  un utente con zero materie avrebbe saltato tutto il blocco. Ora `is_array()`.
  **Se converti altri file, questo è l'errore da cercare per primo.**
- **Sei stringhe con doppia codifica UTF-8** in `big_edit_fc.php`, corrette e
  scritte bene in dizionario.

---

## 7. Lingua: 2584 chiavi in 14 gruppi

`comune` 25 · `pomodoro` 79 · `voti` 222 · `progetti` 87 · `dashboard` 43 · `index` 3 · `todo` 70 · `diario` 77 · `landing` 70 · `planner` 577 · `abitudini` 421 · `import` 82 · `vocab` 251 · `fc` 577

### Regole imparate, da rispettare

**Le frasi non si spezzano mai.** `"Ancora " . $n . " giorni"` diventa una chiave
sola con segnaposto: `ancora_n_giorni = "Ancora {n} giorni"`. In inglese l'ordine
delle parole cambia, e con i pezzi separati non si può rimettere a posto.

**I plurali italiani hanno due chiavi complete**, non un suffisso: `volta`/`volte`
scelte da un ternario. Aggiungere una "e" funziona in italiano e non in inglese.

**Le chiavi si riusano solo a parità di contesto.** "Corrette" come intestazione
di statistica e "corrette" come stato di una carta sono due chiavi, anche se
l'italiano coincide: le due lingue divergono al primo cambio di contesto.

**Non si traduce quello che il codice rilegge.** Le intestazioni dell'export
(`Date`, `Status`, `Total check-ins`) restano in inglese anche con l'interfaccia
in italiano: sono il formato di scambio, e un file esportato in una lingua deve
riaprirsi nell'altra. Stesso motivo per i marcatori del prompt IA (`/red{testo}`,
`$ $`, le chiavi JSON `"q"` e `"a"`) e per i nomi delle voci di Google (Charon,
Puck, Fenrir), che sono identificatori.

**Si traduce invece il prompt per l'IA.** È testo che copi in ChatGPT: chi usa il
sito in inglese vuole un prompt in inglese, altrimenti l'IA risponde in italiano.

### I file JSON di dati

`habits_presets.json` contiene i suggerimenti delle abitudini: 50 preset con
nome e frase di motivazione, più colori, unità di misura, momenti della giornata
e gruppi di icone. In tutto 155 stringhe.

**Non l'ho duplicato in due lingue, e la ragione conta.** Se ci fossero
`habits_presets.it.json` e `habits_presets.en.json`, la prossima abitudine che
aggiungi esisterebbe solo in uno dei due, e nessuno se ne accorgerebbe finché un
utente inglese non la trova scritta in italiano. Il file resta **uno**, in
italiano: è l'elenco dei suggerimenti, non un file di traduzioni.

La traduzione avviene **alla lettura**, in `hb_presets_tradotti()`, partendo dalle
chiavi stabili che il file ha già — `drink_water`, `violet`, `morning`. Se una
chiave manca dal dizionario resta il nome italiano: una voce nuova si vede subito
ma non rompe niente. È lo stesso schema usato per le categorie del planner e per
quelle delle abitudini.

Le motivazioni sono tradotte come frasi di incoraggiamento, non parola per
parola: «Costanza batte intensità» è diventato *Consistency beats intensity*, non
una spiegazione letterale.

**Restano da fare gli altri JSON**, e vanno valutati uno per uno perché non sono
tutti uguali:

| File | Stringhe | Nota |
|---|---:|---|
| `trivia.json` | 417 | curiosità mostrate durante l'attesa: stesso schema dei preset |
| `planner_ambient.json` | 202 | descrizioni della scena meteo |
| `planner_phrases.json` | 145 | frasi del planner |
| `planner_smart.json` | 71 | riconoscimento del linguaggio naturale — **attenzione**: qui le parole non sono testo da mostrare ma parole da *riconoscere*, come i comandi vocali. Va progettato, non tradotto |
| `exams_catalog.json` | 2656 | corsi di laurea e esami italiani: probabilmente **non va tradotto affatto**, sono nomi propri di un ordinamento italiano |

### Il caso speciale: `voice_game_mode.php`

Qui la lingua non era traduzione ma progetto. I comandi vocali sono due cose
insieme: quello che si **legge** e quello che il microfono **ascolta**. Tradurre
solo le etichette avrebbe prodotto una modalità che in inglese mostra "Repeat" e
continua ad aspettare "ripeti" — inutilizzabile, e senza nessun errore visibile.

Tre cose cambiate: `recognition.lang` segue la lingua scelta (era fisso a
`it-IT`); i **sinonimi** di ogni comando vengono dal dizionario come elenco
separato da virgole; le **parole di cortesia** (`FILLERS`, quelle che il codice
toglie prima di riconoscere) sono anch'esse per lingua.

---

## 8. Gli strumenti di verifica

Sei script Python, ognuno nato da un errore vero. **Vanno lanciati prima di ogni
consegna, non dopo.**

```bash
python3 controlla_php.py    file.php   # tag PHP dentro stringhe, script mal chiusi
python3 controlla_php2.py   file.php   # parentesi, interpolazioni ${} sbagliate
python3 controlla_ordine.py file.php   # sec_* usate prima di caricare auth
python3 verifica_sicurezza.py file.php # le 7 voci dello standard
python3 audit_lingua.py     file.php   # tutte le stringhe non tradotte
python3 residui.py          file.php   # come sopra, filtrato dal rumore
python3 controlla_colonne.py _db.sql file.php   # colonne che non esistono
python3 controlla_csp.py  security_core.php file.php  # domini bloccati dalla CSP
php -l                      file.php   # il parser vero, se hai PHP in locale
```

### Cosa prende ognuno, e perché esiste

**`controlla_php.py`** — Nato dal 500 di `planner.php`: avevo scritto
`<?php te(...) ?>` dentro una stringa PHP. Prende anche la sequenza di apertura o
chiusura di uno script scritta in un commento, che il browser interpreta comunque:
**questo errore l'ho fatto due volte**, in `categories.php` e in
`modifica_immagine_fc.php`, e la seconda volta il mio stesso script ha inserito il
blocco delle traduzioni davanti al finto `<script>`, spezzando il file.

**`controlla_php2.py`** — Parentesi bilanciate, più il controllo delle
interpolazioni. `${T('chiave')}` funziona **solo** fra apici inversi: dentro
`'apici singoli'` viene stampato letteralmente e in pagina si legge il codice.
Nessun sintomo: la sintassi è valida, il file gira. Il controllo è un lettore a
stati, perché i template si annidano e le virgolette di un attributo HTML scritto
in un template non sono una stringa a sé.

**`controlla_ordine.py`** — Nato dal 500 di `visualizzazione.php`, l'unico errore
che ti è arrivato sul sito: avevo messo `sec_db()` **prima** di
`require_once 'auth_check.php'`, ma è quest'ultimo a caricare `security_core.php`.
Chiamare una funzione che non esiste è fatale, e PHP risponde 500 senza dire
altro. Lo stesso errore l'ho poi rifatto in `categories_fc.php`, dove però l'ha
preso lo strumento.

**`verifica_sicurezza.py`** — Sette voci: prepared statement, nessun valore
incollato, ingressi rivalidati con `sec_*`, proprietà verificata, output da
`sec_h()`, scritture protette, nessuno stile inline.

**`controlla_colonne.py`** — Nato dai tre nomi di colonna sbagliati del §5.2.
Legge il dump SQL, ricava tabella → colonne, e verifica ogni `alias.colonna`
delle query risalendo agli alias di `FROM`/`JOIN`. Va lanciato passandogli prima
il dump: `python3 controlla_colonne.py _db.sql *.php`. Trova anche le tabelle che
non esistono — è così che è saltata fuori `fc_user_goals`.

**`controlla_csp.py`** — Nato dal §5.4. Confronta i domini di `<script src>` e
`<link href>` con quelli che la CSP consente davvero. Distingue `script-src` da
`style-src`/`font-src`, che hanno elenchi diversi.

**`audit_lingua.py` / `residui.py`** — Il primo elenca tutto, il secondo filtra
il rumore. Su `planner.js` sono passate da 1216 righe illeggibili a 50 reali.

### I buchi che questi strumenti avevano, e come li ho chiusi

Vale la pena leggerli, perché sono il tipo di cosa che ti fa fidare di un
verificatore che non guarda dove serve:

1. **L'audit guardava solo dentro le stringhe.** Nei file PHP il testo HTML
   scritto direttamente in pagina gli sfuggiva: su `categories.php` erano venti
   voci ("Salva", "Annulla", "Categoria padre"). Ora scansiona anche le zone di
   puro HTML.
2. **Le frasi spezzate su più righe con `+`** sfuggivano: ogni pezzo isolato
   sembrava innocuo. Ora ricompone le catene.
3. **Una regola scartava le stringhe di parole minuscole** come nomi di classe
   CSS: `tutto il giorno`, `lista`, `arretrata` non comparivano nemmeno
   nell'elenco. Ora una classe deve avere il trattino.
4. **`verifica_sicurezza.py` cercava `$_POST` per capire se un file scrive.**
   `game_fc.php` fa dieci scritture con azioni passate via **GET**, e il controllo
   non le vedeva: passava "a norma" senza avere alcuna protezione. È il buco che
   ha portato alla scoperta del §3. Ora guarda le query, non l'ingresso.

5. **`controlla_php2.py` segnalava a torto le interpolazioni** dentro i template
   letterali annidati. La prima versione usava espressioni regolari e non
   reggeva l'annidamento; l'ho riscritta come lettore a stati che tiene traccia
   di dove si trova — dentro un template, dentro un `${}`, dentro una stringa
   normale.

Il punto generale: **un verificatore che non guarda dove serve è peggio di
nessun verificatore**, perché autorizza a non guardare. Quando uno di questi
segnala qualcosa che sembra un falso positivo, vale la pena capire perché prima
di aggiungere l'eccezione.

### Quello che gli strumenti non possono prendere

Se puoi installare PHP in locale, `php -l file.php` prima di ogni upload chiude
il buco più grande: i miei controlli sono euristici, quel comando è il parser
vero.

---

## 9. Cose da sapere se continui tu

**`auth` prima di `sec_db`.** Sempre. È l'errore che ha prodotto il 500.

**`sec_all` restituisce un array, non una risorsa.** Ogni `if ($res)` va
riletto: con zero risultati ora è falso.

**I nomi di tabella e di colonna non si possono legare a un segnaposto.** Dove
c'erano `$table` e `$id_col` composti a runtime ho scritto le forme per esteso:
più righe, ma ogni query si legge per intero. Se devi comporre un pezzo di SQL,
assicurati che nasca da un elenco chiuso nel codice — non da un valore esterno.

**Quando un pezzo di SQL riusato porta con sé dei segnaposto**, i suoi valori
vanno passati **nella posizione in cui il pezzo compare nella query**. In
`game_fc.php` `$where_learnt` e `$cat_filter` compaiono due volte nella stessa
query, dentro una UNION: i parametri vanno ripetuti. È l'unico punto del progetto
che non si verifica a colpo d'occhio, e c'è un commento accanto.

**`urlencode()` in un attributo è già sicuro**: aggiungere `sec_h` dopo
raddoppia le entità.

**Taglia il testo prima dell'escape, non dopo.** `substr(sec_h($x), 0, 1)` può
spezzare `&amp;` a metà e mandare in pagina `&am`.

**I componenti condivisi** (`category_selector.php`, `examples_modal.php`,
`floating_player.php`, `voice_game_mode.php`) non caricano il gruppo di
traduzioni: lo devono fare le pagine che li includono. Il `<link>` al loro CSS è
protetto da un `define` perché possono comparire più volte nella stessa pagina.

---

## 10. Cosa resta da fare

In ordine di priorità:

1. **`.htaccess` con `php_flag engine off`** nelle cartelle delle immagini e
   dell'audio. È la difesa che vale anche per il codice non ancora aperto.
2. **Applicare le migrazioni**: `migrazione_fc_user_goals.sql` (la tabella
   mancante), e verificare che `migrazione_lingua.sql` sia già stata applicata —
   senza la colonna `lang` in `users` le pagine cadono.
3. **Gli altri file JSON di dati** (vedi §7): `trivia.json` e i tre del planner
   con lo schema dei preset; `planner_smart.json` va progettato come i comandi
   vocali; `exams_catalog.json` probabilmente va lasciato in italiano.
4. **`base.css`**: ora che il CSS è estratto da tutte le pagine si può cercare
   quello che si ripete e accorparlo. 34 fogli con parecchio in comune.
5. **Estrazione del JavaScript** dai file PHP, dopo aver spostato i valori PHP in
   blocchi dati come il pattern `PL_BOOT` del planner.
6. **`social/friends.php`**: l'avevi messo da rifare, non l'ho toccato.
7. **Web Push per il pomodoro** e **hosting VPS**, che avevi rimandato.

### Da provare dopo il caricamento

Le cose che ho toccato e che vale la pena verificare a mano:

- **Upload**: carica un'immagine in ogni punto dove si può (vocabolario,
  flashcard, esempi, inserimento multiplo). Poi prova a caricare un file che
  **non** è un'immagine: deve rifiutarlo, non salvarlo.
- **Azioni di massa**: seleziona più carte, elimina, sposta, unisci, separa.
- **Riordino con trascinamento** in `visualizzazione_fc.php`: deve mantenere
  l'ordine che scegli.
- **Una partita completa** in `game_fc.php`: rispondi, sbaglia, torna indietro
  con i pallini, concludi, poi "Rigioca errori".
- **L'importazione delle abitudini** con un file di un'altra app (una colonna
  "Habit" e più abitudini dentro), e con intestazioni strane per provare la
  mappatura manuale.
- **La modalità vocale**, cambiando lingua: i comandi devono essere riconosciuti
  in inglese.
- **Le statistiche flashcard**: la sezione "Tempo investito" ora dovrebbe
  mostrare i minuti di pomodoro invece di zero.
