# Standout — elenco dei punti deboli (passo 1) e stato delle correzioni (passo 2)

Giro di controllo del **27/10/2026** su tutto `public/` e `lib/`, fatto con due
strumenti e con lettura del codice:

- `python3 tools/audit_sicurezza.py` — lo scanner del passo 1. Cerca query
  incollate, ingressi non validati, scritture senza token, upload, funzioni
  pericolose, errori a schermo, segreti nel codice. Ordina per gravità
  (CRITICA / ALTA / MEDIA / BASSA) e con `--md` scrive la tabella. Esce con
  codice 1 se trova qualcosa di CRITICA o ALTA. **È un aiuto, non un giudice:**
  segnala "da guardare", e molte segnalazioni sono falsi allarmi (vedi in fondo).
- `python3 tools/verifica_sicurezza.py file.php …` — la lista di controllo per
  singolo file (§3–§6 di SICUREZZA.md). Aveva un difetto: si fermava al primo file
  che non andava e senza argomenti diceva "Tutte a norma". Corretto.

Dopo ogni modifica: `php -l`, `controlla_colonne.py`, `controlla_csp.py`, scanner.

Legenda stato: **corretto** (in questo giro) · **aperto** (da fare) · **deciso**
(scelta consapevole) · **tuo** (serve un'azione fuori dal codice).

---

## Esito in una riga

Nessuna SQL injection, nessun accesso ai dati di un altro utente trovato: le query
sono a segnaposto o con valori da elenchi chiusi, e dove un id arriva dal browser la
proprietà è verificata (`sec_owns`, `fc_solo_mie`, `fc_mazzo_mio`, JOIN su
`user_id`). I difetti veri erano altrove: **scritture che passavano senza token
CSRF via GET**, **un account disattivato che rientrava col "ricordami"**,
**certificato TLS non verificato** nelle chiamate a Google, e **messaggi tecnici
mostrati all'utente**.

**Aggiornamento 27/10/2026 (sera):** chiusi anche M10, M13 e B1–B5 (password a 10
caratteri + verifica email, cancellazione file sicura per il negozio, blocco login che
non si lascia sfruttare, input a prova di array, ultime query ex-mysqli, errori curl
generici). **M11 (la CSP con `'unsafe-inline'`) è chiuso lo stesso giorno**: nonce per gli
script e gli stili, `data-on-click` al posto degli `onclick`; dettagli nella sezione C.

---

## ALTA

| # | Dove | Problema | Stato |
|---|------|----------|-------|
| A1 | `lib/auth_core.php` — `standout_verifica_remember_cookie()` | L'utente disattivato con un cookie "ricordami" valido (60 giorni) rientrava: il controllo `is_active` c'era solo sulla sessione normale. | **corretto** — la query legge `is_active`, se non è 1 si butta il cookie e non si entra |
| A2 | `todo_api`, `diary_api`, `meteo_api`, `home_api`, `piano_api`, `planner_api`, `study_api`, `habits`, `pomodoro`, `pomodoro_storico`, `progetti`, `google_calendar`, `voti` (`public/planner`, `public/home`, `public/projects`, `public/exams`) | `sec_require_csrf()` lascia passare GET senza token (giusto per le letture), e queste API leggono i parametri con `sec_in()`, che guarda anche la query string. Un link come `todo_api.php?ajax=delete&id=5` aperto da un altro sito si portava dietro il cookie (SameSite=Lax lo permette per le navigazioni) e **cancellava senza controllare il token**. Stessa cosa per scollegare Google Calendar, sincronizzare, riparare. | **corretto** — nuova `sec_solo_post_se_scrive($azione, [letture])` in `security_core.php`: con GET passano solo le azioni **di lettura** elencate; tutto il resto, anche un'azione aggiunta domani, è POST. Chiusa in 13 file. |
| A3 | `public/fc/fc_examples_api.php` — azione `delete` | Cancellava un esempio anche con GET (`?action=delete&ex_id=N`), senza token. | **corretto** — solo POST |
| A4 | `public/ai/ai_shatter.php`, `public/ai/ai_tutor.php` | `CURLOPT_SSL_VERIFYPEER = false` (e `VERIFYHOST = false`): nella richiesta viaggia la chiave API di Google, su una rete ostile si poteva leggere o sostituire la risposta. Le altre chiamate sullo stesso server (meteo, Google Calendar, `fc_ai_gemini`) verificano già il certificato e funzionano. | **corretto** — verifica accesa. **Da provare in produzione:** se "Frantuma" o l'audio del tutor smettessero di funzionare, il motivo è qui e va detto subito (non si rispegne). |

## MEDIA

| # | Dove | Problema | Stato |
|---|------|----------|-------|
| M1 | `pomodoro_import.php` (parse, save), `habits_import.php` (parse, remap, save) | Importazioni che scrivono nel database senza token CSRF, senza limite di frequenza, e accettate anche con GET. | **corretto** — solo POST + `sec_guard_write` (il token lo porta già `standout_fetch.js`) |
| M2 | `habits_import.php`, `pomodoro_import.php` | Se il salvataggio falliva, al browser arrivava il testo di MySQL (`mysqli_error`). Una mappa del database regalata a chi la chiede. | **corretto** — motivo nel log (`sec_log_error`), a schermo «Qualcosa è andato storto» |
| M3 | `ai_generate_text.php`, `ai_shatter.php`, `ai_tutor.php`, `fc_card_edit.php` | Il "rete di sicurezza" per gli errori fatali mandava al browser messaggio PHP e numero di riga. | **corretto** — nel log, a schermo messaggio generico |
| M4 | `ai_generate_text.php`, `ai_tutor.php` | `display_errors = 1` forzato: ogni avviso PHP finiva in pagina, con i percorsi delle cartelle. | **corretto** — rimosso; in più `security_core.php` ora spegne `display_errors` ovunque, a meno che in `config.php` si scriva `define('STANDOUT_DEBUG', true);` |
| M5 | `pomodoro.php`, `progetti.php`, `voti.php` | Se il database non si apriva: `mysqli_connect_error()` a schermo. | **corretto** — nel log, a schermo messaggio generico |
| M6 | `ai_generate_text.php`, `ai_shatter.php`, `ai_tutor.php` | La chiave API di Google stava nell'indirizzo (`?key=…`): gli indirizzi finiscono nei log e nei messaggi d'errore. | **corretto** — ora nell'intestazione `x-goog-api-key`. Anche `GEMINI_API_URL` di `lib/config.php` (che ha ancora `?key=…`) non porta più la chiave in rete nell'indirizzo: `fc_ai_lib.php` e `fc_ai_immagine.php` la tolgono (`sec_gemini_url`) e la mandano nell'intestazione, senza toccare `config.php`. |
| M7 | `lib/auth_core.php` | `session.use_strict_mode` non impostato (lo chiede SICUREZZA.md §7.1): un id di sessione scelto da un attaccante veniva accettato. | **corretto** — strict mode + solo cookie |
| M8 | `media/` (file caricati dagli utenti) | Nessuna difesa a livello server contro un `.php` finito lì dentro; il codice controlla tipo ed estensione, ma è una difesa sola. | **corretto** — nuovo `media/.htaccess` nel repository (nega `.php`, `.phtml`, `.phar` ecc., nasconde i file `.`, `nosniff` e CSP chiusa se un file si apre da solo). Provato con Apache 2.4: `.php` e `.PHP5` → 403, immagini e audio → 200. Parte da solo col prossimo deploy. |
| M9 | `.github/workflows/deploy.yml` | Deploy in FTP semplice: password e codice viaggiano in chiaro. | **corretto (27/10/2026)** — `protocol: ftps`; la prima esecuzione è andata a buon fine. |
| M10 | `register.php`, `lib/auth_core.php` | La regola password è più debole di §7.1: 8 caratteri con lettera e cifra (la guida chiede 10, elenco di password comuni, verifica email). | **corretto (27/10/2026)** — minimo 10 e massimo 128 caratteri, elenco di password comuni (anche con cifra o simbolo in coda), niente password uguale a username/email (`lib/password_policy.php`). **Verifica email** morbida (`lib/email_verifica.php`): mail con link a 24 ore (nel database c'è solo l'hash del token), reinvio limitato, avviso nel widget finché non è confermata; senza la migrazione tutto resta com'era. Provata contro un database vero (token scaduto, riusato, email cambiata, limite giornaliero). **Da fare da te: T4 e T5.** |
| M11 | tutte le pagine | La CSP aveva `'unsafe-inline'` per script e stile: ~500 gestori `onclick=…`, ~110 blocchi `<script>`/`<style>`. | **corretto (27/10/2026)** — nonce + `data-on-click`, vedi sezione C. Gli `style=""` negli attributi restano ammessi di proposito. |
| M12 | `public/social/friends_api.php` | La ricerca utenti non ha limite di frequenza: si possono raccogliere gli username a tappeto. | **chiuso (27/10/2026)** — la parte social è spenta sul sito (`lib/social_gate.php`, 404). Il limite di frequenza va aggiunto quando le pagine diventeranno l'API dell'app. |
| M13 | `public/fc/fc_card_edit.php` — `mfc_togli()` | Cancella il file dal disco senza controllare se un'altra carta lo usa. Oggi ogni file è di una carta sola; con il negozio no. | **corretto (27/10/2026)** — nuova `lib/fc/fc_files.php` (`fc_file_in_uso`, `fc_file_togli`, `fc_file_togli_dopo`): un file si cancella solo se nessun'altra carta lo usa, e la cancellazione avviene dopo il commit. Usata da `fc_card_edit.php` e da `ai_shatter_save.php`. Provata con carte che condividono lo stesso file. Prerequisito del negozio: **soddisfatto**. |

## BASSA

| # | Dove | Problema | Stato |
|---|------|----------|-------|
| B1 | `lib/security_core.php` — redirect a HTTPS | L'indirizzo si costruisce da `HTTP_HOST` (header scelto dal client). | **corretto** — usa `STANDOUT_HOST` (opzionale in `config.php`) o `SERVER_NAME`, mai `HTTP_HOST`; il nome è validato |
| B2 | `lib/auth_core.php` — blocco login | Il blocco per identificativo permette di bloccare l'account altrui a forza di tentativi (6 in 15 min). | **corretto** — soglie per coppia identificativo+IP, per IP e (più alta) per account, con attese crescenti: chi sbaglia da un altro IP non blocca il proprietario. Provato 10/10. |
| B3 | `login.php`, `register.php` | `trim($input['x'])` con un array al posto di una stringa lancia un errore PHP (500, nessun danno). | **corretto** — solo testo accettato, un array dà «dati mancanti» invece di un 500 |
| B4 | `friends_api.php`, `friends_core.php`, `project_selector.php`, `pomodoro_import.php`, `lib/piano_helpers.php` | Usano ancora `mysqli_query` con valori già castati a intero o con `real_escape_string`: oggi non iniettabili, ma da portare a `sec_*` perché un domani nessuno si ricordi come. | **corretto** per `project_selector.php`, `pomodoro_import.php` (provato con nomi ostili tipo `x'); DROP TABLE users; --`, progetto di un altro utente, 454 sessioni a blocchi) e `piano_helpers.php`. `friends_*` lasciati com'erano: il social è spento sul sito e verranno riscritti per l'app. |
| B5 | `ai_generate_text.php`, `ai_shatter.php`, `ai_tutor.php` | Per gli errori di rete mostrano il messaggio di curl (es. «Could not resolve host»). Senza più la chiave nell'indirizzo non rivela segreti. | **corretto** — il testo di curl va nel log, al browser solo il codice (`curl errno N`). Sistemato anche un difetto in `ai_tutor.php`: il codice si leggeva dopo `curl_close`. |

---

## Cosa va fatto da te (fuori dal codice)

- **T1.** Frantuma ha mostrato «Gemini API error»: ora ritenta una volta e scrive nell'errore il codice di Google (es. «503 UNAVAILABLE»). Se compare ancora, quel codice dice se è Google sovraccarico, la chiave, o il testo bloccato. Da provare anche **l'audio del tutor** (A4).
- **T2.** Provare di aprire un'immagine e un audio da `media/`: devono funzionare come prima (il nuovo `.htaccess` non tocca immagini/audio).
- **T3.** Ricordarsi la migrazione `2026-10-27_piano_chiavi.sql` (non c'entra con la sicurezza del codice, ma è in sospeso).
- **T4.** Eseguire `sql/migrations/2026-10-27_verifica_email.sql` su MySQL (phpMyAdmin di Altervista). Finché non lo fai la verifica email è spenta e nessuno vede l'avviso. Gli utenti già esistenti risultano già verificati.
- **T5.** Provare una registrazione vera con un'email tua: deve arrivare la mail «Conferma la tua email su Standout» (controlla anche lo spam: `mail()` di Altervista non è garantito). Se non arriva, il sito funziona lo stesso; il motivo sta nel log errori del server. Se dovesse servire, in `config.php` si può fissare il nome del sito con `define('STANDOUT_HOST', 'nisama.altervista.org');`.
- **T6.** Frantuma non funziona per **credito/quota Gemini esaurita** (errore 402 `RESOURCE_EXHAUSTED`), non per il codice: va ricaricato in Google AI Studio / Cloud.
- **T7.** Costanti opzionali di `lib/config.php`: `STANDOUT_HOST` (nome del sito per link e redirect), `STANDOUT_SOCIAL_SITO` (`true` riaccende il social), `STANDOUT_DEBUG` (`true` mostra gli errori PHP: solo in sviluppo).

---

## C. CSP senza `'unsafe-inline'` — come funziona e cosa resta

Fatto il 27/10/2026 (era M11). La regola ora è: script e stili inline **solo con il nonce**
della richiesta (`sec_nonce()`), `script-src-attr 'none'` (nessun `onclick=` può partire),
`style-src-attr 'unsafe-inline'` (gli `style=""` restano, non eseguono codice).

- ~500 gestori `onclick=…` → `data-on-click=…`, eseguiti da `public/_assets/js/standout_events.js`
  (interprete minimo, niente `eval`).
- ~90 blocchi `<script>`/`<style>` → `nonce="<?php echo sec_nonce(); ?>"` (compresi quelli
  dentro stringhe PHP, `sidebar.html`, `i18n.php`, `fc_type.php`, `pomodoro_barra.php`).
- `javascript:history.back()` della pagina d'errore CSRF → `data-on-click`.
- `onerror` della miniatura in `fc_stats.php` → ascoltatore in JS; `onload` dei font → `data-on-load`.
- Gli `<style>` creati da JavaScript (MathJax ne crea uno suo) ricevono il nonce da
  `standout_events.js`. Provato con MathJax 3.2.2 vero sotto la CSP: nessuna violazione.

**Come è stato provato:** un browser vero (Chromium) con la CSP in vigore su 23 pagine
(zero violazioni), e una prova *differenziale*: ogni elemento con un gestore è stato
attivato sia nella versione vecchia (`onclick`) sia nella nuova (`data-on-click`), con le
scritture bloccate, e si sono confrontati errori, finestre di dialogo, richieste e stato
della pagina. Vedi il riepilogo nel messaggio di commit.

**Limite onesto:** se un giorno manca un `sec_h()` e qualcuno fa stampare del suo HTML, può
scrivere un `data-on-click` che chiama una funzione GIÀ esistente del sito. Non può eseguire
codice suo, caricare script da altri siti né usare `eval`, ma l'escape resta la prima
difesa: la CSP è la seconda. Chi aggiunge una pagina deve usare `data-on-click` e il nonce
(lo scanner lo segnala).

---

## Cosa lo scanner segnala e NON è un problema (verificato a mano)

- **«SQL: valore incollato» (CRITICA, 3):** `study_api.php:133` (`$t` viene da due valori fissi), `progetti.php:303` (`$campo` da un elenco chiuso), `friends_api.php:48` (valori castati a intero / `real_escape_string`).
- **«Nessun controllo di accesso» (24):** `verifica_email.php` è pubblica per scelta (si apre dal link nella mail: l'accesso è il token a 64 caratteri, usabile una volta sola, valido 24 ore); gli altri sono frammenti inclusi da pagine che l'accesso lo controllano, o librerie di sole funzioni. `ai_worker.php` risponde 404 se non è chiamato da riga di comando.
- **«Scrive senza CSRF» (5):** gli stessi frammenti/librerie, più `friends_api.php` (intestazione `X-Standout`, vedi M12).
- **«XSS riflesso» (4):** `echo isset($_GET[…]) ? 1 : 0` — stampa un numero, non l'ingresso.
- **«UPDATE/DELETE senza user_id» (116):** controllati i gruppi senza una verifica evidente a poca distanza (`ai_shatter_save`, `fc_card_edit`, `fc_view`, `fc_insert_save`, `fc_examples_api`, `fc_game`): la proprietà passa da JOIN sul mazzo, da `fc_solo_mie`/`fc_figli_miei`, da `AND category_id = ?` con categoria verificata o dalla partita verificata in cima al file. Le altre sono lavori interni (`ai_jobs`, cache, pulizie).
- **«Ingresso usato senza validazione» (31):** letti uno per uno gli ingressi che finiscono in HTML o in query: tutti passano da `sec_*`, da `preg_match`, da un elenco chiuso o da `htmlspecialchars`.
- **«Upload» (`pomodoro_import`, `habits_import`):** il file caricato viene solo letto da `tmp_name` e mai salvato né servito; il tipo si riconosce dal contenuto («PK» per xlsx, CSV altrimenti). L'upload dell'immagine in `todo_api` è ben protetto (finfo, nome casuale, `.htaccess` con motore PHP spento).
- **«Output senza `sec_h()`» (185) e «echo di variabile» (474):** a campione sono funzioni che fanno l'escape al loro interno (`n()`, `soldi()`, `te()`), numeri castati o JSON. Non c'è un giro completo su ognuna: se ne cambia una, lo si ricontrolla.

**Non coperto da questo giro:** un test dal vivo contro il sito (sarebbe un passo a parte, con un account di prova) e la lettura riga per riga di tutte le 474 `echo`. Lo scanner resta a disposizione per ripeterlo dopo ogni modifica.
