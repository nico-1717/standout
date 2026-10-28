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

/* ---------------- L'elenco delle rotte ---------------- */

$tokenECookie = ['token', 'cookie'];

api_v1_esegui([
    ['POST',   'auth/login',        'api_h_login',            []],
    ['POST',   'auth/refresh',      'api_h_rinnova',          []],
    ['POST',   'auth/logout',       'api_h_logout',           ['accetta' => ['token'], 'termini' => false]],
    ['POST',   'auth/logout_all',   'api_h_logout_tutti',     ['accetta' => $tokenECookie, 'termini' => false]],
    ['POST',   'auth/accept_terms', 'api_h_accetta_termini',  ['accetta' => $tokenECookie, 'termini' => false]],
    ['GET',    'auth/devices',      'api_h_dispositivi',      ['accetta' => $tokenECookie]],
    ['DELETE', 'auth/devices/{id}', 'api_h_dispositivo_togli', ['accetta' => $tokenECookie]],
    ['GET',    'me',                'api_h_me',               ['accetta' => $tokenECookie]],
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
]);
