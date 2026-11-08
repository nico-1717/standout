<?php
/* =====================================================================
   STANDOUT — /api/v1: l'unica porta d'ingresso dell'API (PROGETTO §5)

   Il .htaccess della radice manda qui OGNI indirizzo /api/v1/…; questo file
   sceglie la rotta in base a metodo e indirizzo. PERCHE' UN FILE SOLO e non
   uno per rotta: l'autenticazione, il formato degli errori, il CSRF e le
   intestazioni stanno in UN posto (lib/api_v1.php) e non si ricopiano sette
   volte; sull'hosting condiviso serve una sola riga di riscrittura; e le
   rotte del passo 9 (planner, attivita', abitudini) si aggiungono
   all'elenco qui sotto invece di diventare altre porte da difendere.

   I gestori sono volutamente sottili (§5.2): leggono l'ingresso, chiamano la
   logica che sta in lib/ e rispondono. Niente SQL qui dentro.

   Rotte di questo passo (accesso con token, §7.3):
     POST   /api/v1/auth/login            nome utente + password -> due token
     POST   /api/v1/auth/refresh          token di rinnovo -> coppia nuova
     POST   /api/v1/auth/register         crea l'account {username, email, password, password_confirm, accetto: true, device_name?} -> due token
     POST   /api/v1/auth/forgot           chiede il link per la password nuova {identifier}: la risposta e' sempre la stessa
     POST   /api/v1/auth/logout           esce da QUESTO dispositivo
     POST   /api/v1/auth/logout_all       esce da TUTTI i dispositivi
     POST   /api/v1/auth/accept_terms     accetta condizioni e privacy attuali
     GET    /api/v1/auth/devices          dispositivi collegati
     DELETE /api/v1/auth/devices/{id}     esce da un dispositivo
     GET    /api/v1/me                    i dati dell'utente (prova del token)

   Attivita' (to do), passo 9 fetta 1 — la logica sta in lib/todo_core.php:
     GET    /api/v1/tasks[?project_id=N]  l'elenco (come nella pagina)
     POST   /api/v1/tasks                 crea {title, project_id?, parent_id?, date?, time?, minutes?, done?}
     PATCH  /api/v1/tasks/{id}            cambia SOLO i campi inviati (anche done: true|false)
     DELETE /api/v1/tasks/{id}            toglie l'attivita' e le sue sotto-attivita'

   Planner (eventi), passo 9 fetta 2 — la logica sta in lib/planner/pl_api.php e pl_scrivi.php:
     GET    /api/v1/events?from=&to=      eventi di un intervallo (max 70 giorni), serie gia' espanse
     GET    /api/v1/events/{id}           un evento
     POST   /api/v1/events                crea (title e date obbligatori; ripetizione, invitati, luogo...)
     PATCH  /api/v1/events/{id}           cambia SOLO i campi inviati (anche done); edit_mode+original_date per una sola occorrenza
     DELETE /api/v1/events/{id}?scope=one|series|routine&original_date=

   Abitudini, passo 9 fetta 3 — la logica sta in lib/habits_core.php e lib/habits_api.php:
     GET    /api/v1/habits                le abitudini (iniziate e future), con la serie migliore
     GET    /api/v1/habits/logs?days=N    i check-in degli ultimi N giorni (max 420)
     GET    /api/v1/habits/{id}           un'abitudine
     POST   /api/v1/habits                crea (name obbligatorio)
     PATCH  /api/v1/habits/{id}           cambia SOLO i campi inviati (anche archived: true|false)
     DELETE /api/v1/habits/{id}           elimina l'abitudine e i suoi check-in
     POST   /api/v1/habits/{id}/log       check-in {date?, mode?, value?, note?} -> stato, serie, traguardi
     POST   /api/v1/habits/{id}/note      nota e umore di un giorno {date?, note?, mood?}

   To buy, passo 9 fetta 4 — la logica sta in lib/shopping_api.php (e nelle td_* di lib/todo_core.php):
     GET    /api/v1/shopping              gli articoli (ordine della pagina) e le categorie
     POST   /api/v1/shopping              crea {title, category_id?, priority? 1-3, price_cents?, url?, project_id?, date?, time?, minutes?, done?}
     PATCH  /api/v1/shopping/{id}         cambia SOLO i campi inviati (anche done: comprato)
     DELETE /api/v1/shopping/{id}
     GET    /api/v1/shopping/history      gli articoli comprati in passato (suggerimenti)
     GET    /api/v1/shopping/categories   le categorie
     POST   /api/v1/shopping/categories   crea {name, color?}
     PATCH  /api/v1/shopping/categories/{id}   {name?, color?}
     DELETE /api/v1/shopping/categories/{id}   gli articoli restano, senza categoria

   Sincronizzazione con l'app (lib/sync_api.php), solo con il token:
     GET    /api/v1/settings              stile, colore del sito e lingua (solo token)
     PUT    /api/v1/settings              li cambia: {ui_style?, accent?, lang?}
     GET    /api/v1/notes/quick           la nota veloce del To do: {note:{body, updated_at}}
     PUT    /api/v1/notes/quick           {body, base_updated_at}: 409 + la versione del server se e' cambiata nel frattempo
     POST   /api/v1/sync/push             {mutations:[{mutation_id, table, op, uuid, patch}]} (max 200)
     GET    /api/v1/sync/pull?cursor=&limit=   cosa e' cambiato dopo il cursore (cursore snapshot = copia completa)
     GET    /api/v1/sync/state            testa del registro e tabelle

   Impostazioni abitudini (fetta 6 dell'app):
     GET    /api/v1/habits/settings       le preferenze della pagina Abitudini
     PATCH  /api/v1/habits/settings       cambia SOLO i campi inviati (valori fuori scala = errore)

   Fetta 5 — riordino, azioni su piu' voci, categorie delle abitudini (lib/todo_bulk_api.php, habits_api.php):
     POST   /api/v1/tasks/bulk            {ids, action: delete|schedule|project|move, ...}  (move = nel to buy)
     POST   /api/v1/tasks/reorder         {ids} nell'ordine voluto
     POST   /api/v1/shopping/bulk         {ids, action: delete|schedule|project|set|move, ...}  (set = category_id/priority)
     POST   /api/v1/shopping/reorder      {ids}
     POST   /api/v1/habits/reorder        {ids}
     GET    /api/v1/habits/categories     le categorie (di sistema e proprie)
     POST   /api/v1/habits/categories     crea {name, icon?, color?}
     PATCH  /api/v1/habits/categories/{id}   solo le proprie
     DELETE /api/v1/habits/categories/{id}   le abitudini restano, senza categoria

   Pastiglie e integratori, fetta 6 — la logica sta in lib/pills_core.php e lib/pills_api.php:
     GET    /api/v1/pills[?date=]         il giorno: terapie, prese, arretrate, numeri dell'ultimo mese
     GET    /api/v1/pills/plans           le terapie
     POST   /api/v1/pills/plans           crea {name, dose?, notes?, color?, freq_type?, weekdays?, month_days?, interval_days?, times?, start_date?, end_date?, active?}
     PATCH  /api/v1/pills/plans/{id}      cambia SOLO i campi inviati (cambiare il ritmo non tocca le prese passate)
     DELETE /api/v1/pills/plans/{id}      toglie la terapia e le sue prese
     POST   /api/v1/pills/plans/{id}/day  il calendario {date, taken: quante prese quel giorno}
     GET    /api/v1/pills/plans/{id}/history?month=AAAA-MM | days=N
     PATCH  /api/v1/pills/doses/{id}      {status: pending|taken|skipped, taken_at?}
     POST   /api/v1/pills/doses/bulk      «prese tutte» {ids, mode: on_time|now}

   Diario, fetta 7 — la logica sta in lib/diary_core.php e lib/diary_api.php:
     GET    /api/v1/diary/day[?date=]     il giorno: umore, energia, testo, due elenchi, abitudini, anelli (+ impostazioni)
     PATCH  /api/v1/diary/day             {date?, note?, mood?, energy?} cambia SOLO i campi inviati (null = toglie)
     GET    /api/v1/diary/month[?month=AAAA-MM]   i giorni scritti, le medie, le cose imparate
     GET    /api/v1/diary/insights[?year=]        il diario in numeri
     POST   /api/v1/diary/items           aggiunge {date?, list_type: good|learned, content}
     PATCH  /api/v1/diary/items/{id}      {content}
     DELETE /api/v1/diary/items/{id}
     GET    /api/v1/diary/settings        carattere, grandezza, carta
     PATCH  /api/v1/diary/settings        {font?, size?, paper?}
     GET    /api/v1/planner/settings      palette, zoom, ora d'inizio, viste, numero della prima settimana...
     PATCH  /api/v1/planner/settings      solo i campi inviati (gli stessi del sito, con errore 422 se sbagliati)
     GET    /api/v1/pomodoro/settings     ultime durate scelte, stile del quadrante, pausa in corso
     PATCH  /api/v1/pomodoro/settings     solo i campi inviati (current_break: null = pausa finita)
     GET    /api/v1/home/layout           i riquadri della Home: [{id, on, order?}] nell'ordine scelto
     PUT    /api/v1/home/layout           {layout: [...]}: salva, scarta id sconosciuti, aggiunge in fondo i mancanti

   Immagine di un articolo del to buy, fetta 8 — lib/shopping_img_api.php:
     POST   /api/v1/shopping/{id}/image   carica o sostituisce: multipart, campo «file» (jpg, png, webp, gif; max 4 MB)
     GET    /api/v1/shopping/{id}/image   il file (serve la sessione: non c'e' un indirizzo pubblico)
     DELETE /api/v1/shopping/{id}/image   toglie l'immagine (va bene anche se non c'e')

   File delle righe sincronizzate (carte, parole, esempi, articoli del to buy), solo con il token — lib/media_api.php:
     POST   /api/v1/media/{tabella}/{uuid}/{campo}   carica o sostituisce: multipart, campo «file» (immagini 5 MB, audio 10 MB); risponde {name}
     DELETE /api/v1/media/{tabella}/{uuid}/{campo}   toglie il file (va bene anche se non c'e')
     GET    /api/v1/media/todos/{uuid}/buy_image     l'immagine privata di un articolo, dal suo uuid (solo con il token)
   ===================================================================== */

/* Dice a auth_core.php che questa e' l'API: niente sessione PHP (e niente cookie
   in risposta) per chi non ne porta gia' uno. Va PRIMA degli include. */
define('STANDOUT_API', true);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/auth_core.php';
require_once __DIR__ . '/../../../lib/api_v1.php';
require_once __DIR__ . '/../../../lib/todo_core.php';
require_once __DIR__ . '/../../../lib/planner/planner_lib.php';
require_once __DIR__ . '/../../../lib/habits_api.php';
require_once __DIR__ . '/../../../lib/shopping_api.php';
require_once __DIR__ . '/../../../lib/todo_bulk_api.php';
require_once __DIR__ . '/../../../lib/pills_api.php';
require_once __DIR__ . '/../../../lib/diary_api.php';
require_once __DIR__ . '/../../../lib/shopping_img_api.php';
require_once __DIR__ . '/../../../lib/media_api.php';
require_once __DIR__ . '/../../../lib/sync_api.php';
require_once __DIR__ . '/../../../lib/home_layout.php';
require_once __DIR__ . '/../../../lib/registrazione.php';
require_once __DIR__ . '/../../../lib/password_reset.php';
require_once __DIR__ . '/../../../lib/api_settings.php';
require_once __DIR__ . '/../../../lib/api_nota.php';

/* ---------------- Accesso ---------------- */

function api_h_login(array $ctx): void {
    $c = api_corpo();
    $esito = api_login(
        mb_substr(api_testo($c['identifier'] ?? ''), 0, 255),
        api_testo($c['password'] ?? ''),
        api_testo($c['device_name'] ?? '')
    );
    if (!$esito['ok']) api_errore($esito['codice']);
    api_ok(api_pacchetto_accesso($esito['user_id'], $esito['tokens']));
}

function api_h_rinnova(array $ctx): void {
    $c = api_corpo();
    $esito = api_sessione_rinnova(api_testo($c['refresh_token'] ?? ''));
    if (!$esito['ok']) api_errore($esito['codice']);
    api_ok(api_pacchetto_accesso($esito['user_id'], $esito['tokens']));
}

function api_h_logout(array $ctx): void {
    api_sessione_revoca($ctx['sessione_id'], (int)$ctx['utente']['id']);
    api_ok();
}

function api_h_logout_tutti(array $ctx): void {
    /* true: anche i «ricordami» del sito. «Tutti i dispositivi» comprende i
       browser rimasti collegati col cookie di 60 giorni (PROGETTO §7.3). */
    $n = api_sessioni_revoca_tutte((int)$ctx['utente']['id'], true);
    api_ok(['revoked' => $n]);
}


/* ---------------- Registrazione e password dimenticata (rotte pubbliche) ---------------- */

function api_h_registra(array $ctx): void {
    /* un limite largo sui tentativi (ferma chi martella) e uno stretto sugli account davvero creati:
       lo stesso schema del sito, il secondo sta dentro reg_esegui() */
    sec_require_rate('register', sec_ip(), 30, 3600);
    $c = api_corpo();
    $esito = reg_esegui($c);
    if (!$esito['ok']) {
        if ($esito['http'] === 500) api_errore('server_error');
        // 409 = nome o email gia' usati: si dice quale campo, come sul sito (in registrazione e' normale)
        api_errore('validation', null, $esito['http'], ['fields' => $esito['errori'] ?? [], 'reason' => $esito['error']]);
    }
    $uid = (int)$esito['uid'];
    $tokens = api_sessione_crea($uid, mb_substr(api_testo($c['device_name'] ?? ''), 0, 80), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    /* si risponde e POI si manda l'email di verifica: mail() puo' essere lenta */
    http_response_code(201);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['ok' => true] + api_pacchetto_accesso($uid, $tokens), JSON_UNESCAPED_UNICODE);
    reg_invia_verifica_poi($uid);
    exit;
}

function api_h_password_dimenticata(array $ctx): void {
    /* 10 richieste all'ora per indirizzo: e' il freno sulla rete, non rivela niente sull'account */
    sec_require_rate('pw_reset_ip', sec_ip(), 10, 3600);
    $c = api_corpo();
    $identifier = api_testo($c['identifier'] ?? '');
    if (trim($identifier) === '') api_errore('validation', null, null, ['field' => 'identifier']);
    /* la risposta e' SEMPRE la stessa, esista l'account o no, e parte prima del lavoro (come sul sito) */
    pr_rispondi_poi_lavora(['ok' => true]);
    pr_richiedi($identifier);
    exit;
}

/* ---------------- Condizioni d'uso ---------------- */

function api_h_accetta_termini(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    $c = api_corpo();
    /* Come sul sito (accetta_termini.php): si accetta solo con un true esplicito,
       non con "1" o con un testo qualunque. */
    if (($c['accept'] ?? false) !== true) api_errore('validation', 'Send {"accept": true} to accept');
    if (!sec_rate_limit('termini_accetta_write', $uid, 20, 3600)) api_errore('rate_limited');

    sec_q("UPDATE users SET terms_accepted_at = NOW(), terms_version = ? WHERE id = ?", [STANDOUT_LEGALE_VERSIONE, $uid], 'si');
    sec_audit('termini_accettati', 'ok', STANDOUT_LEGALE_VERSIONE);
    api_ok(['terms_required' => false, 'terms_version' => STANDOUT_LEGALE_VERSIONE]);
}

/* ---------------- Dispositivi ---------------- */

function api_h_dispositivi(array $ctx): void {
    api_ok(['devices' => api_sessioni_elenco((int)$ctx['utente']['id'], $ctx['sessione_id'])]);
}

function api_h_dispositivo_togli(array $ctx): void {
    // proprieta' controllata dentro: id di un altro utente = «non trovato», non «vietato» (non si rivela cosa esiste)
    if (!api_sessione_revoca((int)$ctx['id'], (int)$ctx['utente']['id'])) api_errore('not_found');
    api_ok();
}

/* ---------------- Utente ---------------- */

function api_h_me(array $ctx): void {
    $utente = api_utente_pubblico((int)$ctx['utente']['id']);
    if (!$utente) api_errore('unauthenticated');
    api_ok(['user' => $utente]);
}

/* ---------------- Attivita' (to do) ---------------- */

/* Stesso tetto della pagina (400 scritture al minuto, todo_api.php), contato per
   utente. Il token non sposta il limite: cambia solo come si prova chi sei. */
function api_h_tasks_limite(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_tasks_write');
}

/** Scritture al minuto per utente e per area: lo stesso tetto della pagina (400, le abitudini 600). */
function api_h_limite_scritture(array $ctx, string $secchio, int $massimo = 400): void {
    if (!sec_rate_limit($secchio, (int)$ctx['utente']['id'], $massimo, 60)) api_errore('rate_limited');
}

/** Un errore di td_api_* diventa la risposta dell'API (con il nome del campo sbagliato). */
function api_h_tasks_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    $e = $esito['errore'];
    api_errore($e['codice'], $e['testo'], null, ['field' => $e['campo']]);
}

function api_h_tasks_elenco(array $ctx): void {
    $p = isset($_GET['project_id']) ? sec_str($_GET['project_id'], 12) : null;   // sec_str: anche un array diventa testo vuoto
    if ($p !== null && !preg_match('/^[0-9]{1,10}$/', $p)) {
        api_errore('validation', 'project_id must be a number', null, ['field' => 'project_id']);
    }
    $uid = (int)$ctx['utente']['id'];
    $lista = td_api_elenco($uid, $p !== null ? (int)$p : null);
    if ($lista === null) api_errore('not_found', 'Project not found', null, ['field' => 'project_id']);
    // today/now: il «oggi» del sito (Europe/Rome), per sapere cosa e' in ritardo senza indovinare il fuso
    date_default_timezone_set('Europe/Rome');
    api_ok(['tasks' => $lista, 'today' => date('Y-m-d'), 'now' => date('H:i')]);
}

function api_h_tasks_crea(array $ctx): void {
    api_h_tasks_limite($ctx);
    $esito = td_api_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_tasks_errore($esito);
    api_ok(['task' => $esito['task']], 201);
}

function api_h_tasks_modifica(array $ctx): void {
    api_h_tasks_limite($ctx);
    $esito = td_api_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_tasks_errore($esito);
    api_ok(['task' => $esito['task']]);
}

function api_h_tasks_togli(array $ctx): void {
    api_h_tasks_limite($ctx);
    // di un altro utente = "non trovato", come per i dispositivi (non si rivela cosa esiste)
    if (!td_api_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Task not found');
    api_ok();
}

/* ---------------- Planner (eventi) ---------------- */

/** Un errore di pl_api_* diventa la risposta dell'API (con il nome del campo sbagliato). */
function api_h_eventi_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    $e = $esito['errore'];
    api_errore($e['codice'], $e['testo'], null, ['field' => $e['campo']]);
}

/** Un parametro dell'indirizzo come testo, mai array (?from[]=x darebbe un errore PHP). */
function api_h_param(string $nome): ?string {
    return isset($_GET[$nome]) ? sec_str($_GET[$nome], 40) : null;
}

function api_h_eventi_elenco(array $ctx): void {
    $esito = pl_api_elenco((int)$ctx['utente']['id'], api_h_param('from'), api_h_param('to'));
    api_h_eventi_errore($esito);
    api_ok($esito);
}

function api_h_evento_uno(array $ctx): void {
    $e = pl_api_evento((int)$ctx['utente']['id'], (int)$ctx['id']);
    if (!$e) api_errore('not_found', 'Event not found');
    api_ok(['event' => $e]);
}

function api_h_eventi_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_planner_write');
    $esito = pl_api_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_eventi_errore($esito);
    api_ok(['event' => $esito['event']], 201);
}

function api_h_eventi_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_planner_write');
    $esito = pl_api_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_eventi_errore($esito);
    api_ok(['event' => $esito['event']]);
}

function api_h_eventi_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_planner_write');
    $scope = api_h_param('scope') ?? 'one';
    if (!in_array($scope, ['one', 'series', 'routine'], true)) api_errore('validation', 'scope must be one, series or routine', null, ['field' => 'scope']);
    $orig = api_h_param('original_date');
    if ($orig !== null && sec_date($orig, null) === null) api_errore('validation', 'original_date must be YYYY-MM-DD', null, ['field' => 'original_date']);
    api_h_eventi_errore(pl_api_elimina((int)$ctx['utente']['id'], (int)$ctx['id'], $scope, $orig));
    api_ok();
}

/* ---------------- Abitudini ---------------- */

/** Un errore di hb_api_* diventa la risposta dell'API (con il nome del campo sbagliato). */
function api_h_abitudini_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    $e = $esito['errore'];
    api_errore($e['codice'], $e['testo'], null, ['field' => $e['campo']]);
}

function api_h_abitudini_elenco(array $ctx): void {
    api_ok(hb_api_elenco((int)$ctx['utente']['id']));
}

function api_h_abitudini_log(array $ctx): void {
    $g = isset($_GET['days']) ? sec_str($_GET['days'], 6) : '30';
    if (!preg_match('/^[0-9]{1,4}$/', $g) || (int)$g < 1) api_errore('validation', 'days must be a number from 1 to ' . HB_HISTORY_DAYS, null, ['field' => 'days']);
    api_ok(hb_api_log((int)$ctx['utente']['id'], (int)$g));
}

function api_h_abitudine_una(array $ctx): void {
    $h = hb_api_una((int)$ctx['utente']['id'], (int)$ctx['id']);
    if (!$h) api_errore('not_found', 'Habit not found');
    api_ok(['habit' => $h]);
}

function api_h_abitudini_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $esito = hb_api_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_abitudini_errore($esito);
    api_ok(['habit' => $esito['habit']], 201);
}

function api_h_abitudini_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $esito = hb_api_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_abitudini_errore($esito);
    api_ok(['habit' => $esito['habit']]);
}

function api_h_abitudini_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    if (!hb_abitudine_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Habit not found');
    api_ok();
}

function api_h_abitudini_checkin(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $esito = hb_api_checkin((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_abitudini_errore($esito);
    api_ok($esito);
}

function api_h_abitudini_nota(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $esito = hb_api_nota((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_abitudini_errore($esito);
    api_ok($esito);
}

/* ---------------- To buy ---------------- */

/** Un errore di sp_* diventa la risposta dell'API (con il nome del campo sbagliato). */
function api_h_shopping_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    $e = $esito['errore'];
    api_errore($e['codice'], $e['testo'], null, ['field' => $e['campo']]);
}

function api_h_shopping_elenco(array $ctx): void {
    api_ok(sp_elenco((int)$ctx['utente']['id']));
}

function api_h_shopping_storia(array $ctx): void {
    api_ok(['history' => sp_storia((int)$ctx['utente']['id'])]);
}

function api_h_shopping_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $esito = sp_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_shopping_errore($esito);
    api_ok(['item' => $esito['item']], 201);
}

function api_h_shopping_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $esito = sp_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_shopping_errore($esito);
    api_ok(['item' => $esito['item']]);
}

function api_h_shopping_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    if (!sp_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Item not found');
    api_ok();
}

function api_h_shopping_categorie(array $ctx): void {
    td_seed_categories((int)$ctx['utente']['id']);
    api_ok(['categories' => td_categories((int)$ctx['utente']['id'])]);
}

function api_h_shopping_cat_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $esito = sp_cat_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_shopping_errore($esito);
    api_ok(['category' => $esito['category']], 201);
}

function api_h_shopping_cat_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $esito = sp_cat_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_shopping_errore($esito);
    api_ok(['category' => $esito['category']]);
}

function api_h_shopping_cat_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    if (!sp_cat_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Category not found');
    api_ok();
}

/* ---------------- Fetta 5: azioni su piu' voci, riordino, categorie delle abitudini ---------------- */

/** Un errore (di td_*, sp_* o hb_api_*) diventa la risposta dell'API, con il nome del campo sbagliato. */
function api_h_fetta5_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    $e = $esito['errore'];
    api_errore($e['codice'], $e['testo'], null, ['field' => $e['campo']]);
}

function api_h_tasks_bulk(array $ctx): void {
    api_h_tasks_limite($ctx);
    $e = td_bulk((int)$ctx['utente']['id'], 'todo', api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_tasks_riordina(array $ctx): void {
    api_h_tasks_limite($ctx);
    $e = td_riordina((int)$ctx['utente']['id'], 'todo', api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_shopping_bulk(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $e = td_bulk((int)$ctx['utente']['id'], 'buy', api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_shopping_riordina(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $e = td_riordina((int)$ctx['utente']['id'], 'buy', api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_abitudini_riordina(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $e = hb_api_riordina((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_abitudini_categorie(array $ctx): void {
    api_ok(['categories' => hb_load_categories(sec_db(), (int)$ctx['utente']['id'])]);
}
function api_h_abitudini_impostazioni(array $ctx): void {
    api_ok(['settings' => hb_settings(sec_db(), (int)$ctx['utente']['id'])]);
}
function api_h_abitudini_impostazioni_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $e = hb_api_impostazioni_salva((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok(['settings' => $e['settings']]);
}
function api_h_abitudini_cat_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $e = hb_api_cat_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok(['category' => $e['category']], 201);
}
function api_h_abitudini_cat_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    $e = hb_api_cat_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok(['category' => $e['category']]);
}
function api_h_abitudini_cat_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_habits_write', 600);
    if (!hb_api_cat_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Category not found');
    api_ok();
}

/* ---------------- Pastiglie ---------------- */

/** Senza la tabella (migrazione non eseguita) le scritture dicono chiaro che la sezione manca. */
function api_h_pillole_serve(): void {
    if (!td_pills_c_e()) api_errore('not_found', 'Pills are not installed on this server');
}

function api_h_pillole_giorno(array $ctx): void {
    $d = api_h_param('date');
    if ($d !== null && sec_date($d, null) === null) api_errore('validation', 'date must be YYYY-MM-DD', null, ['field' => 'date']);
    api_ok(pl_pillole_giorno((int)$ctx['utente']['id'], $d));
}

function api_h_pillole_terapie(array $ctx): void {
    api_ok(['plans' => td_pill_plans((int)$ctx['utente']['id'])]);
}

function api_h_pillole_crea(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    $e = pl_pillole_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok(['plan' => $e['plan']], 201);
}

function api_h_pillole_modifica(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    $e = pl_pillole_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok(['plan' => $e['plan']]);
}

function api_h_pillole_togli(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    if (!td_pill_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Therapy not found');
    api_ok();
}

function api_h_pillole_calendario(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    $e = pl_pillole_calendario((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}

function api_h_pillole_storia(array $ctx): void {
    api_h_pillole_serve();
    $e = pl_pillole_storia((int)$ctx['utente']['id'], (int)$ctx['id'], api_h_param('month'), api_h_param('days'));
    api_h_fetta5_errore($e);
    api_ok($e);
}

function api_h_pillole_presa(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    $e = pl_pillole_spunta((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}

function api_h_pillole_tutte(array $ctx): void {
    api_h_pillole_serve();
    api_h_limite_scritture($ctx, 'api_pills_write');
    $e = pl_pillole_tutte((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}

/* ---------------- Diario ---------------- */

function api_h_diario_giorno(array $ctx): void {
    $e = dg_api_giorno((int)$ctx['utente']['id'], api_h_param('date'));
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_diario_giorno_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_diary_write');
    $e = dg_api_giorno_salva((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_diario_mese(array $ctx): void {
    $e = dg_api_mese((int)$ctx['utente']['id'], api_h_param('month'));
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_diario_numeri(array $ctx): void {
    $e = dg_api_numeri((int)$ctx['utente']['id'], api_h_param('year'));
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_diario_voce_crea(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_diary_write');
    $e = dg_api_voce_crea((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e, 201);
}
function api_h_diario_voce_modifica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_diary_write');
    $e = dg_api_voce_modifica((int)$ctx['utente']['id'], (int)$ctx['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_diario_voce_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_diary_write');
    if (!dg_api_voce_elimina((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Item not found');
    api_ok();
}
function api_h_diario_impostazioni(array $ctx): void {
    api_ok(['settings' => dg_settings((int)$ctx['utente']['id'])]);
}
function api_h_diario_impostazioni_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_diary_write');
    $e = dg_api_impostazioni_salva((int)$ctx['utente']['id'], api_corpo());
    api_h_fetta5_errore($e);
    api_ok($e);
}

/* ---------------- Planner: impostazioni ---------------- */

function api_h_planner_impostazioni(array $ctx): void {
    api_ok(['settings' => pl_settings((int)$ctx['utente']['id'])]);
}
function api_h_planner_impostazioni_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_planner_write');
    $uid = (int)$ctx['utente']['id'];
    pl_settings($uid);   // la riga deve esistere
    $r = pl_api_impostazioni_salva($uid, api_corpo());
    if (isset($r['errore'])) api_errore($r['errore']['codice'], $r['errore']['testo'], 422, ['field' => $r['errore']['campo']]);
    api_ok(['settings' => pl_settings($uid)]);
}

/* ---------------- Immagine di un articolo ---------------- */

function api_h_shopping_img_carica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    $e = sp_img_carica((int)$ctx['utente']['id'], (int)$ctx['id']);
    api_h_fetta5_errore($e);
    api_ok($e);
}
function api_h_shopping_img_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_shopping_write');
    if (!sp_img_togli((int)$ctx['utente']['id'], (int)$ctx['id'])) api_errore('not_found', 'Item not found');
    api_ok();
}
/* Qui la risposta non e' JSON ma il file. Il tipo lo decide il server dall'estensione che ha scelto
   lui stesso; «nosniff» impedisce al browser di indovinarne un altro. */
function api_h_shopping_img_leggi(array $ctx): void {
    $f = sp_img_file((int)$ctx['utente']['id'], (int)$ctx['id']);
    if (!$f) api_errore('not_found', 'Image not found');
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($f['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($f['path']);
    exit;
}

/* ---------------- Immagini e audio delle righe sincronizzate (lib/media_api.php) ---------------- */

function api_h_media_errore(array $esito): void {
    if (!isset($esito['errore'])) return;
    [$codice, $campo, $testo] = $esito['errore'];
    api_errore($codice, $testo, null, ['field' => $campo]);
}
function api_h_media_carica(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_media_write', 120);
    $e = media_carica((int)$ctx['utente']['id'], (string)$ctx['tbl'], (string)$ctx['uuid'], (string)$ctx['campo'], $_FILES['file'] ?? null);
    api_h_media_errore($e);
    api_ok($e);
}
function api_h_media_togli(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_media_write', 120);
    $r = media_togli((int)$ctx['utente']['id'], (string)$ctx['tbl'], (string)$ctx['uuid'], (string)$ctx['campo']);
    if ($r === null) api_errore('validation', 'Unknown table or field', null, ['field' => 'field']);
    if ($r === false) api_errore('not_found', 'Row not found');
    api_ok();
}

/* L'immagine di un articolo e' privata (uploads/tobuy): si legge solo qui, dal uuid della riga, con il token. */
function api_h_media_leggi(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    $id = media_e_articolo((string)$ctx['tbl'], (string)$ctx['campo']) ? media_id_articolo($uid, (string)$ctx['uuid']) : null;
    $f = $id !== null ? sp_img_file($uid, $id) : null;
    if (!$f) api_errore('not_found', 'Image not found');
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($f['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($f['path']);
    exit;
}

/* ---------------- L'elenco delle rotte ---------------- */

$tokenECookie = ['token', 'cookie'];

api_v1_esegui([
    ['POST',   'auth/login',        'api_h_login',            []],
    ['POST',   'auth/register',     'api_h_registra',         []],
    ['POST',   'auth/forgot',       'api_h_password_dimenticata', []],
    ['POST',   'auth/refresh',      'api_h_rinnova',          []],
    ['POST',   'auth/logout',       'api_h_logout',           ['accetta' => ['token'], 'termini' => false]],
    ['POST',   'auth/logout_all',   'api_h_logout_tutti',     ['accetta' => $tokenECookie, 'termini' => false]],
    ['POST',   'auth/accept_terms', 'api_h_accetta_termini',  ['accetta' => $tokenECookie, 'termini' => false]],
    ['GET',    'auth/devices',      'api_h_dispositivi',      ['accetta' => $tokenECookie]],
    ['DELETE', 'auth/devices/{id}', 'api_h_dispositivo_togli', ['accetta' => $tokenECookie]],
    ['GET',    'me',                'api_h_me',               ['accetta' => $tokenECookie]],
    ['GET',    'settings',          'api_h_settings_leggi',   ['accetta' => ['token']]],
    ['PUT',    'settings',          'api_h_settings_scrivi',  ['accetta' => ['token']]],
    ['GET',    'notes/quick',       'api_h_nota_leggi',       ['accetta' => $tokenECookie]],
    ['PUT',    'notes/quick',       'api_h_nota_scrivi',      ['accetta' => $tokenECookie]],
    ['GET',    'tasks',             'api_h_tasks_elenco',     ['accetta' => $tokenECookie]],
    ['POST',   'tasks',             'api_h_tasks_crea',       ['accetta' => $tokenECookie]],
    ['PATCH',  'tasks/{id}',        'api_h_tasks_modifica',   ['accetta' => $tokenECookie]],
    ['DELETE', 'tasks/{id}',        'api_h_tasks_togli',      ['accetta' => $tokenECookie]],
    ['GET',    'events',            'api_h_eventi_elenco',    ['accetta' => $tokenECookie]],
    ['GET',    'events/{id}',       'api_h_evento_uno',       ['accetta' => $tokenECookie]],
    ['POST',   'events',            'api_h_eventi_crea',      ['accetta' => $tokenECookie]],
    ['PATCH',  'events/{id}',       'api_h_eventi_modifica',  ['accetta' => $tokenECookie]],
    ['DELETE', 'events/{id}',       'api_h_eventi_togli',     ['accetta' => $tokenECookie]],
    ['GET',    'habits',            'api_h_abitudini_elenco', ['accetta' => $tokenECookie]],
    ['GET',    'habits/logs',       'api_h_abitudini_log',    ['accetta' => $tokenECookie]],
    ['GET',    'habits/{id}',       'api_h_abitudine_una',    ['accetta' => $tokenECookie]],
    ['POST',   'habits',            'api_h_abitudini_crea',   ['accetta' => $tokenECookie]],
    ['PATCH',  'habits/{id}',       'api_h_abitudini_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'habits/{id}',       'api_h_abitudini_togli',  ['accetta' => $tokenECookie]],
    ['POST',   'habits/{id}/log',   'api_h_abitudini_checkin', ['accetta' => $tokenECookie]],
    ['POST',   'habits/{id}/note',  'api_h_abitudini_nota',   ['accetta' => $tokenECookie]],
    ['GET',    'shopping',          'api_h_shopping_elenco',  ['accetta' => $tokenECookie]],
    ['POST',   'shopping',          'api_h_shopping_crea',    ['accetta' => $tokenECookie]],
    ['GET',    'shopping/history',  'api_h_shopping_storia',  ['accetta' => $tokenECookie]],
    ['GET',    'shopping/categories', 'api_h_shopping_categorie', ['accetta' => $tokenECookie]],
    ['POST',   'shopping/categories', 'api_h_shopping_cat_crea', ['accetta' => $tokenECookie]],
    ['PATCH',  'shopping/categories/{id}', 'api_h_shopping_cat_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'shopping/categories/{id}', 'api_h_shopping_cat_togli', ['accetta' => $tokenECookie]],
    ['PATCH',  'shopping/{id}',     'api_h_shopping_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'shopping/{id}',     'api_h_shopping_togli',   ['accetta' => $tokenECookie]],
    ['POST',   'shopping/{id}/image', 'api_h_shopping_img_carica', ['accetta' => $tokenECookie]],
    ['GET',    'shopping/{id}/image', 'api_h_shopping_img_leggi',  ['accetta' => $tokenECookie]],
    ['POST',   'media/{tbl}/{uuid}/{campo}', 'api_h_media_carica', ['accetta' => ['token']]],
    ['DELETE', 'media/{tbl}/{uuid}/{campo}', 'api_h_media_togli',  ['accetta' => ['token']]],
    ['GET',    'media/{tbl}/{uuid}/{campo}', 'api_h_media_leggi',  ['accetta' => ['token']]],
    ['DELETE', 'shopping/{id}/image', 'api_h_shopping_img_togli',  ['accetta' => $tokenECookie]],
    ['POST',   'tasks/bulk',        'api_h_tasks_bulk',       ['accetta' => $tokenECookie]],
    ['POST',   'tasks/reorder',     'api_h_tasks_riordina',   ['accetta' => $tokenECookie]],
    ['POST',   'shopping/bulk',     'api_h_shopping_bulk',    ['accetta' => $tokenECookie]],
    ['POST',   'shopping/reorder',  'api_h_shopping_riordina', ['accetta' => $tokenECookie]],
    ['POST',   'habits/reorder',    'api_h_abitudini_riordina', ['accetta' => $tokenECookie]],
    ['GET',    'habits/categories', 'api_h_abitudini_categorie', ['accetta' => $tokenECookie]],
    ['POST',   'habits/categories', 'api_h_abitudini_cat_crea', ['accetta' => $tokenECookie]],
    ['GET',    'habits/settings',   'api_h_abitudini_impostazioni', ['accetta' => $tokenECookie]],
    ['PATCH',  'habits/settings',   'api_h_abitudini_impostazioni_salva', ['accetta' => $tokenECookie]],
    ['PATCH',  'habits/categories/{id}', 'api_h_abitudini_cat_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'habits/categories/{id}', 'api_h_abitudini_cat_togli', ['accetta' => $tokenECookie]],
    ['GET',    'pills',             'api_h_pillole_giorno',   ['accetta' => $tokenECookie]],
    ['GET',    'pills/plans',       'api_h_pillole_terapie',  ['accetta' => $tokenECookie]],
    ['POST',   'pills/plans',       'api_h_pillole_crea',     ['accetta' => $tokenECookie]],
    ['PATCH',  'pills/plans/{id}',  'api_h_pillole_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'pills/plans/{id}',  'api_h_pillole_togli',    ['accetta' => $tokenECookie]],
    ['POST',   'pills/plans/{id}/day', 'api_h_pillole_calendario', ['accetta' => $tokenECookie]],
    ['GET',    'pills/plans/{id}/history', 'api_h_pillole_storia', ['accetta' => $tokenECookie]],
    ['PATCH',  'pills/doses/{id}',  'api_h_pillole_presa',    ['accetta' => $tokenECookie]],
    ['POST',   'pills/doses/bulk',  'api_h_pillole_tutte',    ['accetta' => $tokenECookie]],
    ['GET',    'diary/day',         'api_h_diario_giorno',    ['accetta' => $tokenECookie]],
    ['PATCH',  'diary/day',         'api_h_diario_giorno_salva', ['accetta' => $tokenECookie]],
    ['GET',    'diary/month',       'api_h_diario_mese',      ['accetta' => $tokenECookie]],
    ['GET',    'diary/insights',    'api_h_diario_numeri',    ['accetta' => $tokenECookie]],
    ['POST',   'diary/items',       'api_h_diario_voce_crea', ['accetta' => $tokenECookie]],
    ['PATCH',  'diary/items/{id}',  'api_h_diario_voce_modifica', ['accetta' => $tokenECookie]],
    ['DELETE', 'diary/items/{id}',  'api_h_diario_voce_togli', ['accetta' => $tokenECookie]],
    ['GET',    'diary/settings',    'api_h_diario_impostazioni', ['accetta' => $tokenECookie]],
    ['PATCH',  'diary/settings',    'api_h_diario_impostazioni_salva', ['accetta' => $tokenECookie]],
    ['GET',    'planner/settings',  'api_h_planner_impostazioni',       ['accetta' => $tokenECookie]],
    ['PATCH',  'planner/settings',  'api_h_planner_impostazioni_salva', ['accetta' => $tokenECookie]],
    ['GET',    'pomodoro/settings', 'api_h_pomodoro_impostazioni',       ['accetta' => $tokenECookie]],
    ['PATCH',  'pomodoro/settings', 'api_h_pomodoro_impostazioni_salva', ['accetta' => $tokenECookie]],
    ['GET',    'university/settings', 'api_h_voti_impostazioni',       ['accetta' => $tokenECookie]],
    ['PATCH',  'university/settings', 'api_h_voti_impostazioni_salva', ['accetta' => $tokenECookie]],
    ['GET',    'home/layout',       'api_h_home_layout',                 ['accetta' => $tokenECookie]],
    ['PUT',    'home/layout',       'api_h_home_layout_salva',           ['accetta' => $tokenECookie]],
    /* Sincronizzazione con l'app (docs/ARCHITETTURA_APP.md §3.3): SOLO con il token, sono le rotte dell'app */
    ['POST',   'sync/push',         'api_h_sync_push',        ['accetta' => ['token']]],
    ['GET',    'sync/pull',         'api_h_sync_pull',        ['accetta' => ['token']]],
    ['GET',    'sync/state',        'api_h_sync_stato',       ['accetta' => ['token']]],
]);
