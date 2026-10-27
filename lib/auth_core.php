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
    /* Strict mode: PHP rifiuta un id di sessione che non ha creato lui. Senza,
       chi riesce a far impostare un id scelto da lui nel browser di qualcuno
       (session fixation) lo vedrebbe diventare la sessione dopo il login.
       Solo cookie: l'id non deve mai viaggiare nell'indirizzo. */
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
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
            die(json_encode(['success' => false, 'error' => t('api.connessione_db')]));
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

/** Lo stesso identificativo scritto in modo diverso (Mario / mario) e' lo stesso conto. */
function standout_login_normalizza(string $identifier): string {
    return mb_strtolower(mb_substr(trim($identifier), 0, 255));
}

/**
 * Chi e' bloccato, e per quanto.
 *
 * Prima il blocco scattava per identificativo OPPURE per IP: bastavano 6
 * password sbagliate scritte da chiunque sul tuo nome per chiuderti fuori
 * dal tuo account (blocco usato come arma). Ora il conto principale e' la
 * COPPIA identificativo+IP: chi sbaglia 6 volte viene fermato lui, da
 * quell'indirizzo, e il vero proprietario da un altro indirizzo entra lo
 * stesso. Restano due tetti alti, contro chi cambia strada:
 *   - un solo IP che prova tanti conti diversi      (30 errori in 15 minuti)
 *   - un solo conto attaccato da tanti IP insieme    (30 errori in 15 minuti)
 * E l'attesa cresce per la coppia che insiste: 15 minuti dopo 6 errori,
 * 1 ora dopo 12 errori in un giorno, 24 ore dopo 24.
 */
function standout_login_bloccato(string $identifier): bool {
    $conn = standout_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sconosciuto';
    $id = standout_login_normalizza($identifier);
    $minuti = LOGIN_BLOCCO_MINUTI;

    $stmt = mysqli_prepare($conn, "SELECT
            SUM(identifier = ? AND ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)) AS coppia_15,
            SUM(identifier = ? AND ip_address = ? AND attempted_at > (NOW() - INTERVAL 1 DAY))   AS coppia_24h,
            TIMESTAMPDIFF(SECOND, MAX(CASE WHEN identifier = ? AND ip_address = ? THEN attempted_at END), NOW()) AS coppia_fa,
            SUM(ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE))                   AS solo_ip,
            SUM(identifier = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE))                   AS solo_conto
        FROM login_attempts
        WHERE success = 0 AND attempted_at > (NOW() - INTERVAL 1 DAY)
          AND (identifier = ? OR ip_address = ?)");
    mysqli_stmt_bind_param($stmt, 'ssisssssisiss',
        $id, $ip, $minuti,
        $id, $ip,
        $id, $ip,
        $ip, $minuti,
        $id, $minuti,
        $id, $ip);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $r = $res ? mysqli_fetch_assoc($res) : [];
    mysqli_stmt_close($stmt);

    $c15  = (int)($r['coppia_15'] ?? 0);
    $c24  = (int)($r['coppia_24h'] ?? 0);
    /* I secondi dall'ultimo errore li calcola il database, con il SUO orologio: farlo
       in PHP confronterebbe due fusi orari che possono non coincidere. */
    $fa   = isset($r['coppia_fa']) && $r['coppia_fa'] !== null ? (int)$r['coppia_fa'] : PHP_INT_MAX;

    if ($c15 >= LOGIN_MAX_TENTATIVI) return true;
    if ($c24 >= 12 && $fa < 3600)    return true;           // 1 ora
    if ($c24 >= 24 && $fa < 86400)   return true;           // 24 ore
    if ((int)($r['solo_ip'] ?? 0)    >= 30) return true;
    if ((int)($r['solo_conto'] ?? 0) >= 30) return true;
    return false;
}

function standout_registra_tentativo(string $identifier, bool $success): void {
    $conn = standout_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sconosciuto';
    $identifier = standout_login_normalizza($identifier);
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
    $stmt = mysqli_prepare($conn, "SELECT rt.user_id, rt.token_hash, u.username, u.email, u.is_active
        FROM remember_tokens rt JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ? AND rt.expires_at > NOW()");
    mysqli_stmt_bind_param($stmt, 's', $selector);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) return null;

    /* Un account disattivato non rientra col «ricordami»: il controllo c'era
       solo sulla sessione normale, e il cookie vale 60 giorni. Si butta anche
       il cookie, così non viene riprovato a ogni richiesta. */
    if ((int)$row['is_active'] !== 1) {
        standout_elimina_remember_cookie();
        return null;
    }

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
