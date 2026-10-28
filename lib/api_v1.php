<?php
/* =====================================================================
   STANDOUT — /api/v1: la base comune degli endpoint (PROGETTO §5.2, §5.3)

   Questo file non risponde a nessun indirizzo: lo usa public/api/v1/index.php,
   l'unica porta d'ingresso dell'API. Qui stanno le regole uguali per tutti gli
   endpoint, cosi' i prossimi (planner, attivita', abitudini: passo 9) le
   ereditano invece di ricopiarle:

     - risposta SEMPRE JSON con ok:true|false; in caso di errore
       {ok:false, error:"descrizione per chi sviluppa", code:"not_found"} e il
       codice HTTP giusto. Niente HTML, niente redirect, niente testo in
       italiano: il testo per l'utente lo sceglie il client dal `code`;
     - ogni rotta dichiara se accetta il TOKEN, il COOKIE o entrambi (§5.3);
     - chi e' l'utente lo dice SOLO standout_utente_corrente() (auth_core.php);
     - le scritture autenticate dal cookie passano dal CSRF di sempre
       (sec_require_csrf); quelle autenticate da un token no (vedi li' il perche').

   NOTA SUL CSRF: se una chiamata autenticata dal cookie non porta il token CSRF,
   la risposta e' quella standard del sito ({ok:false, error:"csrf", message:…},
   403), con il testo nella lingua dell'utente. Capita solo a chi usa l'API dal
   browser; l'app, con il token, non la vede mai.
   ===================================================================== */

require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/legale.php';
require_once __DIR__ . '/api_sessioni.php';

/* I codici d'errore: [stato HTTP, descrizione breve in inglese per chi sviluppa].
   Sono un contratto con l'app: si aggiungono, non si rinominano. */
const API_ERRORI = [
    'invalid_request'     => [400, 'Invalid request'],
    'validation'          => [422, 'Validation failed'],
    'invalid_credentials' => [401, 'Invalid credentials'],
    'unauthenticated'     => [401, 'Authentication required'],
    'token_required'      => [401, 'This endpoint requires a Bearer token'],
    'invalid_token'       => [401, 'Invalid or revoked token'],
    'token_expired'       => [401, 'Access token expired: use the refresh token'],
    'token_reused'        => [401, 'Refresh token already used: this device session has been closed'],
    'terms_required'      => [403, 'The current terms and privacy policy must be accepted'],
    'forbidden'           => [403, 'Forbidden'],
    'not_found'           => [404, 'Not found'],
    'method_not_allowed'  => [405, 'Method not allowed'],
    'too_many_attempts'   => [429, 'Too many failed attempts: try again later'],
    'rate_limited'        => [429, 'Too many requests'],
    'server_error'        => [500, 'Server error'],
];

/* ================= Risposte ================= */

function api_ok(array $dati = [], int $http = 200): void {
    sec_json(['ok' => true] + $dati, $http);
}

/** Errore. Stato HTTP e descrizione di norma vengono dal codice (API_ERRORI). */
function api_errore(string $codice, ?string $descrizione = null, ?int $http = null, array $extra = []): void {
    [$h, $d] = API_ERRORI[$codice] ?? [500, 'Error'];
    $http = $http ?? $h;
    if ($http === 401 && !headers_sent()) header('WWW-Authenticate: Bearer');
    sec_json(['ok' => false, 'error' => $descrizione ?? $d, 'code' => $codice] + $extra, $http);
}

/* ================= Ingresso ================= */

/** Il corpo della richiesta come array (JSON). Vuoto se non c'e'; 400 se non e' JSON. */
function api_corpo(): array {
    static $corpo = null;
    if ($corpo !== null) return $corpo;
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $corpo = [];
    $json = json_decode($raw, true);
    if (!is_array($json)) api_errore('invalid_request', 'The request body must be a JSON object');
    return $corpo = $json;
}

/** Solo testo: un array o un numero al posto di una stringa darebbe un errore PHP (500). */
function api_testo($valore): string {
    return is_string($valore) ? $valore : '';
}

/**
 * La parte dell'indirizzo dopo /api/v1/, per esempio «auth/login».
 * Si legge da REQUEST_URI (non da PATH_INFO) perche' e' uguale con la
 * riscrittura di Apache e con il server di prova di PHP. null = forma strana.
 */
function api_percorso(): ?string {
    $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $pos = strpos($p, '/api/v1');
    if ($pos === false) return null;
    $resto = substr($p, $pos + strlen('/api/v1'));
    $resto = preg_replace('#^/?index\.php#', '', $resto);   // anche /api/v1/index.php/auth/login
    $resto = trim($resto, '/');
    if ($resto !== '' && !preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $resto)) return null;
    return $resto;
}

/** Che cosa deve sapere l'app per far accettare i testi (i percorsi sono relativi al sito). */
function api_info_termini(): array {
    $base = defined('PWA_BASE') ? (string)PWA_BASE : '/';
    return [
        'terms_version' => STANDOUT_LEGALE_VERSIONE,
        'terms_path'    => $base . 'legal/termini.php',
        'privacy_path'  => $base . 'legal/privacy.php',
    ];
}

/**
 * Il pacchetto di risposta di login e rinnovo: i token, l'utente, e se deve
 * ancora accettare i testi. L'app lo sa subito, senza aspettare un 403.
 */
function api_pacchetto_accesso(int $uid, array $tokens): array {
    $daAccettare = standout_termini_da_accettare($uid);
    return $tokens + [
        'user'           => api_utente_pubblico($uid),
        'terms_required' => $daAccettare,
    ] + ($daAccettare ? api_info_termini() : []);
}

/* ================= Il router ================= */

/**
 * Cerca la rotta, controlla chi chiama e passa la mano al gestore.
 *
 * $rotte: elenco di [metodo, schema, gestore, opzioni]. Lo schema e' l'indirizzo
 * dopo /api/v1/, con {id} per un numero (auth/devices/{id}). Opzioni:
 *   'accetta' => ['token'] | ['cookie'] | ['token','cookie']
 *                (assente = rotta pubblica, nessun controllo di chi sei)
 *   'termini' => false     la rotta funziona anche se i testi non sono accettati
 *                (assente = true: e' il caso normale, 403 terms_required)
 * Il gestore riceve ['utente' => [...], 'sessione_id' => N, 'id' => N|null].
 */
function api_v1_esegui(array $rotte): void {
    set_exception_handler(function (Throwable $e) {
        // nel log del server (mai a schermo), senza il corpo della richiesta: puo' contenere password o token
        sec_log_error('api_v1', get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        api_errore('server_error');
    });

    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo === 'HEAD') $metodo = 'GET';
    $percorso = api_percorso();
    if ($percorso === null) api_errore('not_found');

    $metodiPossibili = [];
    foreach ($rotte as $r) {
        [$m, $schema, $gestore, $opz] = $r + [3 => []];
        $re = '#^' . str_replace('{id}', '(?<id>[0-9]{1,10})', $schema) . '$#';
        if (!preg_match($re, $percorso, $trovato)) continue;
        $metodiPossibili[] = $m;
        if ($m !== $metodo) continue;

        $ctx = ['utente' => null, 'sessione_id' => 0, 'id' => isset($trovato['id']) ? (int)$trovato['id'] : null];
        $accetta = $opz['accetta'] ?? [];

        if ($accetta) {
            /* L'UNICO punto in cui si decide chi sei: il token se c'e', altrimenti il cookie. */
            $utente = standout_utente_corrente();
            if (!$utente) api_errore(standout_errore_token() ?? 'unauthenticated');
            $viaToken = standout_accesso_via_token();
            if (!$viaToken && !in_array('cookie', $accetta, true)) api_errore('token_required');
            $ctx['utente'] = $utente;
            $ctx['sessione_id'] = standout_sessione_api_corrente();

            /* Scritture: il CSRF di sempre. Con un token valido la funzione esce da
               sola (vedi sec_require_csrf); con il cookie il token CSRF e' obbligatorio. */
            if ($metodo !== 'GET') sec_require_csrf();

            if (($opz['termini'] ?? true) && standout_termini_da_accettare((int)$utente['id'])) {
                api_errore('terms_required', null, null, api_info_termini());
            }
        }

        $gestore($ctx);
        api_errore('server_error', 'The handler returned nothing');   // un gestore risponde sempre e termina
    }

    if ($metodiPossibili) {
        if (!headers_sent()) header('Allow: ' . implode(', ', array_unique($metodiPossibili)));
        api_errore('method_not_allowed');
    }
    api_errore('not_found');
}
