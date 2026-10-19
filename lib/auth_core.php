<?php
/**
 * ============================================================
 *  STANDOUT — NUCLEO DI AUTENTICAZIONE
 * ============================================================
 * Questo file NON va aperto direttamente dal browser: viene
 * incluso da altri file (auth_check.php, auth_check_api.php,
 * login.php, register.php, logout.php, auth_modal.php, ...).
 *
 * Cosa fa:
 *  - Avvia una sessione PHP con cookie sicuri
 *  - Gestisce il cookie "ricordami" (login persistente)
 *  - Genera/verifica il token CSRF
 *  - Applica un blocco temporaneo dopo troppi tentativi di login
 *  - Espone standout_utente_corrente() per sapere chi è loggato
 *
 * Non tocca in alcun modo config.php: usa le stesse costanti
 * DB_HOST / DB_USER / DB_PASSWORD / DB_NAME che già usi altrove.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_core.php';

// ---- Parametri modificabili ----
if (!defined('REMEMBER_ME_DURATA_GIORNI')) define('REMEMBER_ME_DURATA_GIORNI', 60);
if (!defined('LOGIN_MAX_TENTATIVI'))       define('LOGIN_MAX_TENTATIVI', 6);
if (!defined('LOGIN_BLOCCO_MINUTI'))       define('LOGIN_BLOCCO_MINUTI', 15);

// ---- Sessione sicura (una sola volta per richiesta) ----
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('nisama_sess');
    session_start();
}

/** Connessione al DB condivisa per tutte le funzioni di questo file (stessa modalità mysqli già in uso nel sito) */
function standout_db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$conn) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Connessione al database non riuscita.']));
        }
        mysqli_set_charset($conn, 'utf8mb4');
    }
    return $conn;
}

/* ================= CSRF ================= */

function standout_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function standout_csrf_verifica(?string $token): bool {
    return isset($_SESSION['csrf_token']) && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* ============ Rate limiting sui login ============ */

function standout_login_bloccato(string $identifier): bool {
    $conn = standout_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sconosciuto';
    $minuti = LOGIN_BLOCCO_MINUTI;

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM login_attempts
        WHERE (identifier = ? OR ip_address = ?) AND success = 0
        AND attempted_at > (NOW() - INTERVAL ? MINUTE)");
    mysqli_stmt_bind_param($stmt, 'ssi', $identifier, $ip, $minuti);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : ['c' => 0];
    mysqli_stmt_close($stmt);

    return intval($row['c']) >= LOGIN_MAX_TENTATIVI;
}

function standout_registra_tentativo(string $identifier, bool $success): void {
    $conn = standout_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sconosciuto';
    $s = $success ? 1 : 0;

    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssi', $identifier, $ip, $s);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* ============ "Ricordami" — pattern selector/validator ============ */

function standout_imposta_remember_cookie(int $user_id): void {
    $conn = standout_db();
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $hash      = hash('sha256', $validator);
    $scadenza  = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * REMEMBER_ME_DURATA_GIORNI);
    $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = mysqli_prepare($conn, "INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, user_agent) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'issss', $user_id, $selector, $hash, $scadenza, $ua);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('nisama_remember', $selector . ':' . $validator, [
        'expires'  => time() + 60 * 60 * 24 * REMEMBER_ME_DURATA_GIORNI,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function standout_elimina_remember_cookie(): void {
    if (!empty($_COOKIE['nisama_remember'])) {
        $parti = explode(':', $_COOKIE['nisama_remember']);
        if (!empty($parti[0])) {
            $conn = standout_db();
            $stmt = mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE selector = ?");
            mysqli_stmt_bind_param($stmt, 's', $parti[0]);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        setcookie('nisama_remember', '', ['expires' => time() - 3600, 'path' => '/']);
    }
}

function standout_verifica_remember_cookie(): ?array {
    if (empty($_COOKIE['nisama_remember'])) return null;
    $parti = explode(':', $_COOKIE['nisama_remember']);
    if (count($parti) !== 2) return null;
    [$selector, $validator] = $parti;
    if ($selector === '' || $validator === '') return null;

    $conn = standout_db();
    $stmt = mysqli_prepare($conn, "SELECT rt.user_id, rt.token_hash, u.username, u.email
        FROM remember_tokens rt JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ? AND rt.expires_at > NOW()");
    mysqli_stmt_bind_param($stmt, 's', $selector);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) return null;

    if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
        // Il validator non corrisponde al selector: possibile furto del cookie.
        // Per precauzione invalidiamo TUTTI i "ricordami" di questo utente.
        $stmtDel = mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtDel, 'i', $row['user_id']);
        mysqli_stmt_execute($stmtDel);
        mysqli_stmt_close($stmtDel);
        standout_elimina_remember_cookie();
        return null;
    }

    // Rotazione: ad ogni uso il token viene rigenerato, così un cookie
    // "vecchio" copiato da qualcun altro smette subito di funzionare.
    $stmtDel2 = mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE selector = ?");
    mysqli_stmt_bind_param($stmtDel2, 's', $selector);
    mysqli_stmt_execute($stmtDel2);
    mysqli_stmt_close($stmtDel2);

    standout_imposta_remember_cookie((int)$row['user_id']);

    return ['id' => (int)$row['user_id'], 'username' => $row['username'], 'email' => $row['email']];
}

/* ============ Login / Logout ============ */

function standout_effettua_login(int $user_id, bool $ricordami = true): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    unset($_SESSION['csrf_token']); // un nuovo login = nuovo token CSRF

    $conn = standout_db();
    $stmt = mysqli_prepare($conn, "UPDATE users SET last_login_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($ricordami) {
        standout_imposta_remember_cookie($user_id);
    }

    /* la lingua del profilo (o, se non c'e', quella scelta prima di entrare) */
    if (function_exists('standout_lingua_dopo_login')) standout_lingua_dopo_login($user_id);
}

function standout_effettua_logout(): void {
    standout_elimina_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ============ Utente corrente ============ */

/**
 * Ritorna ['id' => ..., 'username' => ..., 'email' => ...] se loggato,
 * altrimenti null. Prova prima la sessione, poi il cookie "ricordami".
 */
function standout_utente_corrente(): ?array {
    static $cache = null;
    static $verificato = false;
    if ($verificato) return $cache;
    $verificato = true;

    $conn = standout_db();

    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $stmt = mysqli_prepare($conn, "SELECT id, username, email, is_active FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if ($row && (int)$row['is_active'] === 1) {
            $cache = ['id' => (int)$row['id'], 'username' => $row['username'], 'email' => $row['email']];
            return $cache;
        }
        unset($_SESSION['user_id']); // utente non più valido: puliamo la sessione
    }

    $utente = standout_verifica_remember_cookie();
    if ($utente) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $utente['id'];
        $cache = $utente;
        return $cache;
    }

    $cache = null;
    return null;
}
