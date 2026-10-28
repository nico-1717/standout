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

- **T8.** Le copie del database sono manuali: farne una a scadenza fissa (per esempio ogni domenica) da phpMyAdmin → Esporta, e tenerla fuori dal repository e in un posto privato. Una copia mai provata e' solo una speranza: una volta va importata in un database di prova. La routine completa (cosa, quando, dove, quanto tenere, come provare e ripristinare) e' in `docs/BACKUP.md`.
- **T9. Provato (28/10/2026).** Il blocco del login (6 errori in 15 minuti → 15 minuti di attesa) funziona in locale e dal vivo: sul sito vero `login_attempts.ip_address` e' l'indirizzo del visitatore (un IP pubblico, non un proxy né un indirizzo interno), quindi i tetti per IP non bloccano tutti insieme. Prova dal vivo fatta dal proprietario con un account di prova; per sbloccare subito: `DELETE FROM login_attempts WHERE identifier = '…';`.
- **T1.** Frantuma ha mostrato «Gemini API error»: ora ritenta una volta e scrive nell'errore il codice di Google (es. «503 UNAVAILABLE»). Se compare ancora, quel codice dice se è Google sovraccarico, la chiave, o il testo bloccato. Da provare anche **l'audio del tutor** (A4).
- **T2.** Provare di aprire un'immagine e un audio da `media/`: devono funzionare come prima (il nuovo `.htaccess` non tocca immagini/audio).
- **T3.** Ricordarsi la migrazione `2026-10-27_piano_chiavi.sql` (non c'entra con la sicurezza del codice, ma è in sospeso).
- **T4.** ~~Eseguire `sql/migrations/2026-10-27_verifica_email.sql`~~ **fatto**: la verifica email funziona (e-mail arrivata). Gli utenti già esistenti risultano già verificati.
- **T5.** (fatto, la mail e' arrivata) Provare una registrazione vera con un'email tua: deve arrivare la mail «Conferma la tua email su Standout» (controlla anche lo spam: `mail()` di Altervista non è garantito). Se non arriva, il sito funziona lo stesso; il motivo sta nel log errori del server. Se dovesse servire, in `config.php` si può fissare il nome del sito con `define('STANDOUT_HOST', 'nisama.altervista.org');`.
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
- **«Nessun controllo di accesso» su `legal/privacy.php` e `legal/termini.php`, e «Output senza escape» su `lib/legale.php` (`$h(...)`):** le due pagine sono pubbliche per legge (l'informativa si deve poter leggere prima di registrarsi); `$h` è `htmlspecialchars` dentro una closure, che lo scanner non riconosce.
- **Esporta / elimina account (27/10/2026):** `esporta_dati.php` e `elimina_account.php` passano da `sec_guard_write` (CSRF, origine, limite 5/ora); l'eliminazione chiede anche la password e la conferma esplicita; l'esportazione non include colonne con nome da segreto (`ACCOUNT_COLONNE_SEGRETE`).
- **«Scrive senza CSRF» (5):** gli stessi frammenti/librerie, più `friends_api.php` (intestazione `X-Standout`, vedi M12).
- **«XSS riflesso» (4):** `echo isset($_GET[…]) ? 1 : 0` — stampa un numero, non l'ingresso.
- **«UPDATE/DELETE senza user_id» (116):** controllati i gruppi senza una verifica evidente a poca distanza (`ai_shatter_save`, `fc_card_edit`, `fc_view`, `fc_insert_save`, `fc_examples_api`, `fc_game`): la proprietà passa da JOIN sul mazzo, da `fc_solo_mie`/`fc_figli_miei`, da `AND category_id = ?` con categoria verificata o dalla partita verificata in cima al file. Le altre sono lavori interni (`ai_jobs`, cache, pulizie).
- **«Ingresso usato senza validazione» (31):** letti uno per uno gli ingressi che finiscono in HTML o in query: tutti passano da `sec_*`, da `preg_match`, da un elenco chiuso o da `htmlspecialchars`.
- **«Upload» (`pomodoro_import`, `habits_import`):** il file caricato viene solo letto da `tmp_name` e mai salvato né servito; il tipo si riconosce dal contenuto («PK» per xlsx, CSV altrimenti). L'upload dell'immagine in `todo_api` è ben protetto (finfo, nome casuale, `.htaccess` con motore PHP spento).
- **«Output senza `sec_h()`» (185) e «echo di variabile» (474):** a campione sono funzioni che fanno l'escape al loro interno (`n()`, `soldi()`, `te()`), numeri castati o JSON. Non c'è un giro completo su ognuna: se ne cambia una, lo si ricontrolla.

**Non coperto da questo giro:** un test dal vivo contro il sito (sarebbe un passo a parte, con un account di prova) e la lettura riga per riga di tutte le 474 `echo`. Lo scanner resta a disposizione per ripeterlo dopo ogni modifica.
