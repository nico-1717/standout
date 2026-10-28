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
   ===================================================================== */

/* Dice a auth_core.php che questa e' l'API: niente sessione PHP (e niente cookie
   in risposta) per chi non ne porta gia' uno. Va PRIMA degli include. */
define('STANDOUT_API', true);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/auth_core.php';
require_once __DIR__ . '/../../../lib/api_v1.php';

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
]);
