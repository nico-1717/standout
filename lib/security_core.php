<?php
/**
 * ============================================================
 *  NISAMA — NUCLEO DI SICUREZZA
 * ============================================================
 * Da includere in cima a OGNI pagina e a OGNI endpoint, subito
 * dopo config.php:
 *
 *   require_once 'config.php';
 *   require_once 'security_core.php';   // header + utility
 *   require_once 'auth_check.php';      // oppure auth_check_api.php
 *
 * Cosa offre:
 *  - header HTTP di sicurezza + redirect a HTTPS
 *  - una connessione mysqli unica e una funzione per le query
 *    SEMPRE con prepared statement (sec_q / sec_one / sec_all)
 *  - validatori/sanificatori per ogni tipo di input
 *  - verifica CSRF per tutte le richieste che scrivono
 *  - controllo dei ruoli (RBAC) con livelli numerici
 *  - rate limiting per azione
 *  - registro delle azioni sensibili
 *
 * Nessuna funzione qui dentro si fida MAI di un controllo fatto
 * dal client.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

/* ============================================================
   1. HEADER DI SICUREZZA  +  HTTPS OBBLIGATORIO
   ============================================================ */

function sec_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') return true;
    return false;
}

function sec_is_local(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'localhost' || strpos($host, 'localhost:') === 0
        || strpos($host, '127.0.0.1') === 0 || strpos($host, '192.168.') === 0;
}

/** Chiamata automaticamente al primo include: forza https e manda gli header. */
function sec_boot_headers(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // se la pagina ha già iniziato a stampare (include a metà file) non
    // possiamo più toccare gli header: usciamo senza fare danni
    if (headers_sent()) return;

    // HTTPS su ogni pagina (in locale non forziamo, altrimenti non si sviluppa)
    if (!sec_is_https() && !sec_is_local()) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $url, true, 301);
        exit;
    }

    header_remove('X-Powered-By');
    if (sec_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    // Content Security Policy.
    // 'unsafe-inline' su style e script resta necessario finche' le pagine
    // hanno <style>/<script> inline: e' documentato qui per non dimenticarlo.
    $csp = "default-src 'self'; "
         . "base-uri 'self'; "
         . "object-src 'none'; "
         . "frame-ancestors 'self'; "
         . "form-action 'self'; "
         /* Riquadri: la mappa incorporata di Google (nel popup dell'evento del
            planner) e le nostre stesse pagine. Senza questa riga valeva il
            default-src 'self' e il browser rifiutava la mappa mostrando un
            riquadro grigio ("This content is blocked"). L'elenco resta corto
            apposta: ogni voce di terze parti qui e' un sito a cui si permette
            di disegnare dentro le nostre pagine.

            'self' serve perche' una direttiva frame-src SOSTITUISCE il
            default-src, non lo integra: elencando solo google.com si era tolto
            al sito il permesso di incorniciare SE STESSO, e il popup di
            modifica della flashcard (fc_edit_popup.js, che apre
            modifica_immagine_fc.php in un iframe) finiva nello stesso riquadro
            grigio descritto qui sopra. Non e' un allentamento: frame-ancestors
            'self' qui sotto continua a impedire che siano ALTRI a incorniciare
            noi, che e' la difesa dal clickjacking. */
         . "frame-src 'self' https://www.google.com; "
         . "img-src 'self' data: blob: https:; "
         . "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; "         
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "connect-src 'self' https://www.googleapis.com https://oauth2.googleapis.com";
    header('Content-Security-Policy: ' . $csp);
}
sec_boot_headers();

/* ============================================================
   2. DATABASE: connessione unica + prepared statement sempre
   ============================================================ */

function sec_db(): mysqli {
    static $conn = null;
    if ($conn instanceof mysqli) return $conn;

    // se la pagina ha gia' aperto la sua connessione, la riusiamo
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $conn = $GLOBALS['conn'];
        mysqli_set_charset($conn, 'utf8mb4');
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    } catch (Throwable $e) {
        sec_log_error('db_connect', $e->getMessage());
        sec_die_json('Servizio momentaneamente non disponibile.', 503);
    }
    mysqli_set_charset($conn, 'utf8mb4');
    $GLOBALS['conn'] = $conn;
    return $conn;
}

/**
 * Esegue una query con prepared statement.
 * $types e' la stringa mysqli ('i','d','s','b'); se omessa viene dedotta.
 * Ritorna mysqli_result per le SELECT, true/false altrimenti.
 */
function sec_q(string $sql, array $params = [], ?string $types = null) {
    $conn = sec_db();
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        sec_log_error('sql_prepare', mysqli_error($conn) . ' | ' . substr($sql, 0, 120));
        sec_die_json('Errore nella richiesta al database.', 500);
    }
    if (!empty($params)) {
        if ($types === null) {
            $types = '';
            foreach ($params as $p) {
                if (is_int($p))         $types .= 'i';
                elseif (is_float($p))   $types .= 'd';
                else                    $types .= 's';
            }
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    try {
        mysqli_stmt_execute($stmt);
    } catch (Throwable $e) {
        sec_log_error('sql_execute', $e->getMessage() . ' | ' . substr($sql, 0, 120));
        mysqli_stmt_close($stmt);
        sec_die_json('Errore nella richiesta al database.', 500);
    }
    $isSelect = stripos(ltrim($sql), 'SELECT') === 0 || stripos(ltrim($sql), 'SHOW') === 0;
    if ($isSelect) {
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        if ($res) { while ($r = mysqli_fetch_assoc($res)) $rows[] = $r; }
        mysqli_stmt_close($stmt);
        return $rows;
    }
    $GLOBALS['SEC_LAST_INSERT_ID'] = mysqli_insert_id($conn);
    $GLOBALS['SEC_LAST_AFFECTED']  = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return true;
}

/** Prima riga di una SELECT, oppure null. */
function sec_one(string $sql, array $params = [], ?string $types = null): ?array {
    $rows = sec_q($sql, $params, $types);
    return is_array($rows) && !empty($rows) ? $rows[0] : null;
}

/** Tutte le righe di una SELECT. */
function sec_all(string $sql, array $params = [], ?string $types = null): array {
    $rows = sec_q($sql, $params, $types);
    return is_array($rows) ? $rows : [];
}

function sec_insert_id(): int   { return (int)($GLOBALS['SEC_LAST_INSERT_ID'] ?? 0); }
function sec_affected(): int    { return (int)($GLOBALS['SEC_LAST_AFFECTED'] ?? 0); }

/* ============================================================
   3. RISPOSTE JSON
   ============================================================ */

function sec_json($payload, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sec_die_json(string $message, int $code = 400, string $error = 'error'): void {
    sec_json(['ok' => false, 'error' => $error, 'message' => $message], $code);
}

/* ============================================================
   4. LETTURA DELL'INPUT
   ============================================================ */

/** Corpo della richiesta: JSON oppure form. Ritorna sempre un array. */
function sec_body(): array {
    static $body = null;
    if ($body !== null) return $body;

    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    if (empty($body) && !empty($_POST)) $body = $_POST;
    return $body;
}

function sec_in(string $key, $default = null) {
    $body = sec_body();
    if (array_key_exists($key, $body)) return $body[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

/* ============================================================
   5. VALIDATORI  (mai fidarsi del client)
   ============================================================ */

/** Testo su una riga: toglie tag, caratteri di controllo, taglia la lunghezza. */
function sec_str($value, int $max = 255, string $fallback = ''): string {
    if (is_array($value) || is_object($value)) return $fallback;
    $s = (string)$value;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = str_replace("\n", ' ', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    /* Stesso difetto corretto poco sopra in sec_text(), e per lo stesso
       motivo: strip_tags() su un '<' senza '>' di chiusura non toglie quel
       carattere, elimina tutto cio' che segue fino alla fine della
       stringa. Qui il rischio e' lo stesso su nomi e titoli brevi — una
       materia chiamata "Grafi (n<m)" sarebbe sparita a meta'. sec_h() in
       uscita scappa gia' correttamente '<'/'>', quindi qui non serviva. */
    $s = trim($s);
    if ($s === '') return $fallback;
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** Testo multiriga (note, diario): conserva gli a capo, niente tag. */
function sec_text($value, int $max = 8000, string $fallback = ''): string {
    if (is_array($value) || is_object($value)) return $fallback;
    $s = (string)$value;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    /* CORREZIONE — bug grave, dati persi in silenzio.
       C'era qui strip_tags($s). Il suo scopo era ragionevole (un livello in
       piu' di difesa, oltre a sec_h() usato ovunque in uscita), ma
       strip_tags() ha un difetto notissimo di PHP: se trova un '<' senza
       una '>' di chiusura corrispondente, non toglie solo quel carattere —
       tratta TUTTO cio' che segue come se fosse ancora dentro un tag
       aperto, e lo elimina fino alla FINE della stringa. Un testo con
       "n<m" (una disuguaglianza matematica, non un tag) perdeva cosi'
       tutto quello che veniva dopo, senza nessun errore: pareva un
       salvataggio riuscito, e in un campo lungo il taglio poteva passare
       inosservato per righe intere.
       Il contenuto che passa da sec_text() — testo semplice, o il
       documento JSON delle flashcard — non viene mai stampato cosi' com'e':
       ogni punto di uscita di questo progetto usa sec_h() per l'HTML o
       fcdEscape() (in fc_parser.js) per il documento delle carte, ed
       entrambi scappano '<'/'>'/'&'/'"' correttamente. strip_tags() qui non
       aggiungeva percio' nessuna protezione reale — solo il rischio di
       perdere dati per un carattere del tutto legittimo. */
    $s = preg_replace("/\n{4,}/", "\n\n\n", $s);
    return mb_substr(trim($s), 0, $max, 'UTF-8');
}

function sec_int($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, int $fallback = 0): int {
    if (is_array($value) || is_object($value)) return $fallback;
    if (!is_numeric($value)) return $fallback;
    $n = (int)$value;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

function sec_float($value, float $min, float $max, float $fallback = 0.0): float {
    if (!is_numeric($value)) return $fallback;
    $n = (float)$value;
    return $n < $min ? $min : ($n > $max ? $max : $n);
}

function sec_bool($value): bool {
    if (is_bool($value)) return $value;
    $s = strtolower(trim((string)$value));
    return in_array($s, ['1', 'true', 'yes', 'on', 'si'], true);
}

function sec_enum($value, array $allowed, string $fallback): string {
    $s = is_string($value) || is_numeric($value) ? trim((string)$value) : '';
    return in_array($s, $allowed, true) ? $s : $fallback;
}

/** Data YYYY-MM-DD realmente esistente. */
function sec_date($value, ?string $fallback = null): ?string {
    $s = trim((string)$value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
    }
    return $fallback;
}

/** Orario HH:MM (o HH:MM:SS) normalizzato a HH:MM:SS. */
function sec_time($value, ?string $fallback = null): ?string {
    $s = trim((string)$value);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $s, $m)) {
        return $m[1] . ':' . $m[2] . ':' . (isset($m[3]) && $m[3] !== '' ? $m[3] : '00');
    }
    return $fallback;
}

/** Colore esadecimale #RRGGBB. */
function sec_color($value, string $fallback = '#C8DAD3'): string {
    $s = strtoupper(trim((string)$value));
    if (preg_match('/^#[0-9A-F]{3}$/', $s)) {
        return '#' . $s[1] . $s[1] . $s[2] . $s[2] . $s[3] . $s[3];
    }
    return preg_match('/^#[0-9A-F]{6}$/', $s) ? $s : $fallback;
}

/** Email valida e normalizzata, oppure null. */
function sec_email($value): ?string {
    $s = trim((string)$value);
    $s = filter_var($s, FILTER_VALIDATE_EMAIL);
    return $s === false ? null : mb_strtolower($s, 'UTF-8');
}

/** Escape per l'output HTML. Da usare SEMPRE quando si stampa un dato utente. */
function sec_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Chiave di ricerca normalizzata (minuscola, accenti tenuti, spazi compressi). */
function sec_key($value, int $max = 60): string {
    $s = sec_str($value, $max);
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/\s+/u', ' ', $s);
}

/* ------------------------------------------------------------
   Upload di file: nessuna fiducia nell'estensione dichiarata
   ------------------------------------------------------------ */
/**
 * Valida un file caricato. $allowed e' una mappa mime => estensione.
 * Ritorna ['ok'=>true,'ext'=>..,'mime'=>..,'tmp'=>..] oppure ['ok'=>false,'message'=>..].
 */
function sec_file(array $file, array $allowed = null, int $maxBytes = 5242880): array {
    if ($allowed === null) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    }
    if (!isset($file['tmp_name'], $file['error'])) return ['ok' => false, 'message' => 'File non ricevuto.'];
    if ($file['error'] !== UPLOAD_ERR_OK)          return ['ok' => false, 'message' => 'Caricamento non riuscito.'];
    if (!is_uploaded_file($file['tmp_name']))      return ['ok' => false, 'message' => 'File non valido.'];
    if (($file['size'] ?? 0) <= 0)                 return ['ok' => false, 'message' => 'Il file e vuoto.'];
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'message' => 'Il file supera ' . round($maxBytes / 1048576, 1) . ' MB.'];
    }
    $mime = null;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    }
    if ($mime === null || !isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Formato non consentito.'];
    }
    if (strpos($mime, 'image/') === 0 && @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'message' => 'Immagine non leggibile.'];
    }
    return ['ok' => true, 'ext' => $allowed[$mime], 'mime' => $mime, 'tmp' => $file['tmp_name']];
}

/** Nome file sicuro, generato dal server (mai quello del client). */
function sec_safe_filename(string $ext): string {
    return date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
}

/* ============================================================
   6. CSRF
   ============================================================ */

function sec_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        // auth_core.php avvia la sessione; qui e' solo una rete di sicurezza
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sec_csrf_ok(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) return false;
    return !empty($_SESSION['csrf_token']) && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Da chiamare in cima a OGNI endpoint che scrive.
 * Cerca il token nell'header X-CSRF-Token, poi nel corpo.
 * Controlla anche che l'origine della richiesta sia il nostro dominio.
 */
function sec_require_csrf(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return;

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '') {
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $hostOnly   = preg_replace('/:\d+$/', '', $host);
        if ($originHost !== '' && $originHost !== $hostOnly) {
            sec_audit('csrf_origin', 'denied', $originHost);
            sec_die_json('Richiesta bloccata: origine non riconosciuta.', 403, 'origin');
        }
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$token) {
        $body = sec_body();
        $token = $body['csrf_token'] ?? ($_POST['csrf_token'] ?? null);
    }
    if (!sec_csrf_ok(is_string($token) ? $token : null)) {
        sec_audit('csrf_token', 'denied', null);
        /* Se è una normale navigazione (un modulo inviato, non una chiamata in
           background) l'utente si ritroverebbe JSON grezzo a schermo intero:
           meglio una paginetta leggibile con il tasto per tornare indietro. */
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $isAjax = !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
               || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               || strpos($accept, 'application/json') !== false
               || strpos($accept, 'text/html') === false;
        if (!$isAjax) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Sessione scaduta</title><style>'
               . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#FBF9F6;'
               . 'color:#4C463F;font-family:system-ui,-apple-system,sans-serif;padding:28px;text-align:center}'
               . 'h1{font-weight:500;font-size:22px;margin:0 0 10px}p{color:#8A8177;margin:0 0 22px;line-height:1.5}'
               . 'a{display:inline-block;padding:12px 22px;border-radius:14px;background:#A9C3AE;color:#FFFDFA;'
               . 'text-decoration:none;font-size:15px}</style></head><body><div>'
               . '<h1>Sessione scaduta</h1>'
               . '<p>Per sicurezza la richiesta è stata annullata.<br>Ricarica la pagina e riprova: '
               . 'quello che stavi facendo non è andato perso.</p>'
               . '<a href="javascript:history.back()">Torna indietro</a>'
               . '</div></body></html>';
            exit;
        }
        sec_die_json('Sessione scaduta: ricarica la pagina e riprova.', 403, 'csrf');
    }
}

/* ============================================================
   7. RUOLI (RBAC)
   ============================================================ */

function sec_user_level(?int $userId = null): int {
    static $cache = [];
    if ($userId === null) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }
    if ($userId <= 0) return -1;
    if (isset($cache[$userId])) return $cache[$userId];

    $row = sec_one("SELECT role_level FROM user_roles WHERE user_id = ?", [$userId], 'i');
    if (!$row) {
        sec_q("INSERT IGNORE INTO user_roles (user_id, role_level) VALUES (?, 0)", [$userId], 'i');
        $cache[$userId] = 0;
        return 0;
    }
    $cache[$userId] = (int)$row['role_level'];
    return $cache[$userId];
}

function sec_can(int $minLevel, ?int $userId = null): bool {
    return sec_user_level($userId) >= $minLevel;
}

/** Blocca la richiesta se l'utente non ha il livello richiesto. */
function sec_require_level(int $minLevel, bool $isApi = true): void {
    if (sec_can($minLevel)) return;
    sec_audit('rbac', 'denied', 'richiesto livello ' . $minLevel);
    if ($isApi) sec_die_json('Non hai i permessi per questa operazione.', 403, 'forbidden');
    http_response_code(403);
    echo 'Accesso non consentito.';
    exit;
}

/**
 * Controllo di proprieta: la riga appartiene davvero all'utente?
 * Da usare SEMPRE prima di modificare o cancellare qualcosa.
 */
function sec_owns(string $table, int $id, int $userId, string $idColumn = 'id'): bool {
    if ($id <= 0 || $userId <= 0) return false;
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/', $idColumn)) return false;
    $row = sec_one("SELECT 1 AS ok FROM `$table` WHERE `$idColumn` = ? AND user_id = ? LIMIT 1", [$id, $userId], 'ii');
    return $row !== null;
}

/* ============================================================
   8. RATE LIMITING
   ============================================================ */

function sec_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * true se la richiesta puo' proseguire, false se ha superato il limite.
 * Esempio: sec_rate_limit('planner_write', $userId, 240, 60)
 */
function sec_rate_limit(string $action, $subject, int $max, int $seconds): bool {
    $bucket = mb_substr($action . ':' . $subject, 0, 80);
    $ip = sec_ip();

    // pulizia occasionale delle righe vecchie
    if (random_int(1, 40) === 1) {
        sec_q("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
    $row = sec_one(
        "SELECT COUNT(*) AS c FROM rate_limits WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
        [$bucket, $seconds], 'si'
    );
    if ($row && (int)$row['c'] >= $max) return false;

    sec_q("INSERT INTO rate_limits (bucket, ip_address) VALUES (?, ?)", [$bucket, $ip], 'ss');
    return true;
}

/** Come sopra, ma interrompe subito la richiesta se il limite e' superato. */
function sec_require_rate(string $action, $subject, int $max, int $seconds): void {
    if (sec_rate_limit($action, $subject, $max, $seconds)) return;
    sec_audit($action, 'denied', 'rate limit');
    sec_die_json('Troppe richieste in poco tempo. Riprova tra qualche istante.', 429, 'rate_limit');
}

/* ============================================================
   9. REGISTRO
   ============================================================ */

function sec_audit(string $action, string $outcome = 'ok', ?string $detail = null): void {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    @sec_q(
        "INSERT INTO security_audit_log (user_id, action, outcome, detail, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, mb_substr($action, 0, 60), $outcome, $detail !== null ? mb_substr($detail, 0, 255) : null, sec_ip(), $ua]
    );
}

/** Errore tecnico: nel log del server, mai a schermo. */
function sec_log_error(string $where, string $message): void {
    error_log('[nisama:' . $where . '] ' . $message);
}

/* ============================================================
   10. Scorciatoia per gli endpoint che scrivono
   ============================================================ */

/**
 * Una riga sola in cima a ogni endpoint di scrittura:
 *   sec_guard_write($current_user_id, 'planner');
 * Verifica CSRF, origine, livello minimo e rate limit.
 */
function sec_guard_write(int $userId, string $action, int $minLevel = 0, int $max = 300, int $seconds = 60): void {
    sec_require_csrf();
    if ($minLevel > 0) sec_require_level($minLevel);
    sec_require_rate($action . '_write', $userId, $max, $seconds);
}