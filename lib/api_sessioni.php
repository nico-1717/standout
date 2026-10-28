<?php
/* =====================================================================
   STANDOUT — SESSIONI A TOKEN PER L'APP (PROGETTO §7.3, passo 7)

   Il sito sa chi sei grazie a un cookie di sessione. L'app React Native non
   puo' usare i cookie come un browser: entra con due token.
     - token di ACCESSO  (sta.…): vive 30 minuti, va in ogni chiamata
                                  (intestazione  Authorization: Bearer …);
     - token di RINNOVO  (str.…): vive 60 giorni, serve solo a ottenere una
                                  coppia nuova, e a ogni uso viene SOSTITUITO.
   Una riga di `api_sessions` = un dispositivo. Uscire da un dispositivo vuol
   dire chiudere quella riga; le altre restano.

   COME E' FATTO UN TOKEN:  <tipo>.<selector>.<validator>
     selector   24 caratteri esadecimali, NON segreto: trova la riga senza
                confronti sul segreto (stesso schema di «ricordami»);
     validator  64 caratteri (32 byte casuali): il segreto. Nel database c'e'
                solo il suo sha256, mai il token. Chi legge la tabella non
                puo' entrare. sha256 semplice basta (e non bcrypt) perche' il
                validator e' casuale e lungo: non si "indovina" e non e' una
                password scelta da una persona.
     tipo       sta / str: un token di rinnovo mandato al posto di quello di
                accesso (o viceversa) viene rifiutato subito, senza guardare
                il database.

   REGOLE CHE QUI NON SI TOCCANO
     - Nessun token, nemmeno in parte, finisce in security_audit_log o negli
       errori. Nel registro vanno id di utente e di dispositivo.
     - Le scadenze si confrontano con NOW() del database, non con time() di
       PHP: i due orologi possono avere fusi orari diversi (stessa ragione per
       cui lo fa standout_login_bloccato()).
     - Una richiesta con token non apre la sessione PHP e non imposta cookie
       (lo decide lib/auth_core.php, che e' l'UNICO posto che risponde alla
       domanda «chi e' l'utente di questa richiesta»).
     - Il cambio password (public/account/cambia_password.php) chiama
       api_sessioni_revoca_tutte($uid, true); lo stesso DEVE fare il futuro
       ripristino della password (quello «dimenticata»).
   ===================================================================== */

require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/auth_core.php';       // lockout del login e standout_db()
require_once __DIR__ . '/email_verifica.php';  // ev_verificata(), per «email_verified»

/* ---- Parametri (si possono ridefinire in config.php, per esempio per i test) ---- */
if (!defined('API_ACCESS_DURATA_SECONDI'))    define('API_ACCESS_DURATA_SECONDI', 1800);   // 30 minuti
if (!defined('API_REFRESH_DURATA_GIORNI'))    define('API_REFRESH_DURATA_GIORNI', 60);
if (!defined('API_DISPOSITIVI_MAX'))          define('API_DISPOSITIVI_MAX', 10);           // 0 = nessun tetto
/* L'ultimo uso si scrive al massimo una volta al minuto: senza questo limite
   ogni chiamata dell'app farebbe anche una scrittura nel database. */
if (!defined('API_ULTIMO_USO_OGNI_SECONDI'))  define('API_ULTIMO_USO_OGNI_SECONDI', 60);
/* Pulizia a caso, una richiesta ogni N: non c'e' cron su questo hosting (stessa
   idea di sec_rate_limit()). */
if (!defined('API_POTATURA_UNA_SU'))          define('API_POTATURA_UNA_SU', 150);

const API_TIPO_ACCESSO = 'sta';
const API_TIPO_RINNOVO = 'str';

/* ================= Token ================= */

/** Un token nuovo: ['token' => da mandare al client, 'selector' => , 'hash' => da salvare]. */
function api_token_nuovo(string $tipo): array {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    return [
        'token'    => $tipo . '.' . $selector . '.' . $validator,
        'selector' => $selector,
        'hash'     => hash('sha256', $validator),
    ];
}

/**
 * Spezza un token ricevuto in ['selector' => , 'validator' => ], oppure null se
 * non ha la forma giusta o e' del tipo sbagliato. La forma si controlla PRIMA di
 * qualsiasi query: niente di strano arriva al database.
 */
function api_token_scomponi($raw, string $tipo): ?array {
    if (!is_string($raw) || strlen($raw) > 200) return null;
    if (!preg_match('/^(sta|str)\.([0-9a-f]{24})\.([0-9a-f]{64})$/', $raw, $m)) return null;
    if ($m[1] !== $tipo) return null;
    return ['selector' => $m[2], 'validator' => $m[3]];
}

/* ================= Registro ================= */

/**
 * Una riga nel registro di sicurezza con l'utente scritto a mano: sec_audit()
 * lo prende dalla sessione, e per esempio in un rinnovo (chiamata senza token di
 * accesso) la sessione non c'e'. Il dettaglio non contiene mai un token.
 */
function api_audit(?int $uid, string $azione, string $esito, ?string $dettaglio = null): void {
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    @sec_q(
        "INSERT INTO security_audit_log (user_id, action, outcome, detail, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)",
        [$uid, mb_substr($azione, 0, 60), $esito, $dettaglio !== null ? mb_substr($dettaglio, 0, 255) : null, sec_ip(), $ua]
    );
}

/* ================= Creazione ================= */

/**
 * Apre la sessione di un dispositivo e restituisce i due token.
 * Se l'utente ha gia' API_DISPOSITIVI_MAX dispositivi, esce quello usato meno
 * di recente (il nuovo prende il suo posto): meglio perdere un telefono
 * dimenticato che permettere a uno script di riempire la tabella.
 */
function api_sessione_crea(int $uid, string $nomeDispositivo, string $userAgent): array {
    $max = (int)API_DISPOSITIVI_MAX;
    if ($max > 0) {
        $attive = sec_all(
            "SELECT id FROM api_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
              ORDER BY last_used_at DESC, id DESC", [$uid], 'i');
        if (count($attive) >= $max) {
            // si tengono i (max-1) piu' recenti; l'ultimo posto e' del dispositivo nuovo
            foreach (array_slice($attive, $max - 1) as $r) {
                api_sessione_revoca((int)$r['id'], $uid);
                api_audit($uid, 'api_dispositivo_tolto', 'ok', 'dispositivo ' . (int)$r['id'] . ': tetto di ' . $max);
            }
        }
    }

    $acc = api_token_nuovo(API_TIPO_ACCESSO);
    $rin = api_token_nuovo(API_TIPO_RINNOVO);
    sec_q(
        "INSERT INTO api_sessions (user_id, device_name, user_agent, access_selector, access_hash, access_expires_at,
                                   refresh_selector, refresh_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND, ?, ?, NOW() + INTERVAL ? DAY)",
        [$uid, sec_str($nomeDispositivo, 80), $userAgent !== '' ? sec_str($userAgent, 255) : null,
         $acc['selector'], $acc['hash'], (int)API_ACCESS_DURATA_SECONDI,
         $rin['selector'], $rin['hash'], (int)API_REFRESH_DURATA_GIORNI],
        'issssissi'
    );
    $id = sec_insert_id();

    return api_risposta_token($id, $acc['token'], $rin['token']);
}

/** Il pacchetto che il client riceve dopo il login e dopo ogni rinnovo. */
function api_risposta_token(int $sessioneId, string $access, string $refresh): array {
    return [
        'device_id'          => $sessioneId,
        'token_type'         => 'Bearer',
        'access_token'       => $access,
        'expires_in'         => (int)API_ACCESS_DURATA_SECONDI,
        'refresh_token'      => $refresh,
        'refresh_expires_in' => (int)API_REFRESH_DURATA_GIORNI * 86400,
    ];
}

/* ================= Verifica del token di accesso ================= */

/**
 * Chi e' il proprietario di questo token di accesso?
 * Ritorna ['ok' => true, 'utente' => [id, username, email], 'sessione_id' => N]
 * oppure ['ok' => false, 'codice' => 'invalid_token' | 'token_expired'].
 *
 * 'token_expired' (solo l'accesso e' scaduto: il client deve rinnovare) si dice
 * SOLO dopo aver verificato il segreto: a chi non lo conosce si risponde
 * sempre 'invalid_token', cosi' non si scopre quali selector esistono.
 */
function api_sessione_da_access_token(string $raw): array {
    $p = api_token_scomponi($raw, API_TIPO_ACCESSO);
    if ($p === null) return ['ok' => false, 'codice' => 'invalid_token'];

    $r = sec_one(
        "SELECT s.id, s.user_id, s.access_hash,
                (s.access_expires_at > NOW()) AS access_valido,
                (s.expires_at > NOW())        AS sessione_valida,
                TIMESTAMPDIFF(SECOND, s.last_used_at, NOW()) AS inattivo,
                u.username, u.email, u.is_active
           FROM api_sessions s JOIN users u ON u.id = s.user_id
          WHERE s.access_selector = ? AND s.revoked_at IS NULL",
        [$p['selector']], 's'
    );
    if (!$r || !hash_equals((string)$r['access_hash'], hash('sha256', $p['validator']))) {
        return ['ok' => false, 'codice' => 'invalid_token'];
    }
    /* Un account disattivato smette di entrare subito, anche con una sessione di
       60 giorni ancora valida (come fa il «ricordami», PROGETTO SICUREZZA A1). */
    if ((int)$r['is_active'] !== 1 || (int)$r['sessione_valida'] !== 1) {
        return ['ok' => false, 'codice' => 'invalid_token'];
    }
    if ((int)$r['access_valido'] !== 1) {
        return ['ok' => false, 'codice' => 'token_expired'];
    }

    if ((int)$r['inattivo'] >= (int)API_ULTIMO_USO_OGNI_SECONDI) {
        sec_q("UPDATE api_sessions SET last_used_at = NOW() WHERE id = ? AND user_id = ?",
              [(int)$r['id'], (int)$r['user_id']], 'ii');
    }
    api_sessioni_pota_a_caso();

    return [
        'ok'          => true,
        'utente'      => ['id' => (int)$r['user_id'], 'username' => $r['username'], 'email' => $r['email']],
        'sessione_id' => (int)$r['id'],
    ];
}

/* ================= Rinnovo (rotazione + riuso) ================= */

/**
 * Cambia un token di rinnovo con una coppia nuova.
 * Ritorna ['ok' => true, 'tokens' => [...], 'user_id' => N]
 * oppure ['ok' => false, 'codice' => 'invalid_token' | 'token_reused'].
 *
 * ROTAZIONE: il token presentato diventa «precedente» (prev_refresh_*) e non
 * vale piu' come rinnovo. RIUSO: se qualcuno presenta proprio quello
 * «precedente», vuol dire che esistono DUE copie dello stesso token (una e' stata
 * copiata): non si sa quale sia l'intruso, quindi si chiude tutta la sessione del
 * dispositivo e si scrive una riga nel registro.
 *
 * TRANSAZIONE + FOR UPDATE: due rinnovi simultanei con lo stesso token si
 * mettono in fila. Il secondo trova il token gia' sostituito e viene trattato
 * come riuso. E' voluto: un'app che rinnova in parallelo con lo stesso token va
 * corretta (un solo rinnovo alla volta), non assecondata.
 */
function api_sessione_rinnova(string $raw): array {
    $p = api_token_scomponi($raw, API_TIPO_RINNOVO);
    if ($p === null) return ['ok' => false, 'codice' => 'invalid_token'];

    $conn = sec_db();
    mysqli_begin_transaction($conn);

    $r = sec_one(
        "SELECT id, user_id, refresh_hash, (expires_at > NOW()) AS valida
           FROM api_sessions WHERE refresh_selector = ? AND revoked_at IS NULL FOR UPDATE",
        [$p['selector']], 's'
    );

    if ($r) {
        $uid = (int)$r['user_id'];
        $attivo = sec_one("SELECT is_active FROM users WHERE id = ?", [$uid], 'i');

        if (!hash_equals((string)$r['refresh_hash'], hash('sha256', $p['validator']))) {
            /* Il selector esiste ma il segreto no: non e' una prova di furto (il
               selector non e' segreto) e chiudere la sessione qui lascerebbe a
               chiunque il modo di buttare fuori un dispositivo altrui. Si rifiuta e basta. */
            mysqli_rollback($conn);
            api_audit($uid, 'api_rinnovo', 'denied', 'dispositivo ' . (int)$r['id'] . ': segreto errato');
            return ['ok' => false, 'codice' => 'invalid_token'];
        }
        if ((int)$r['valida'] !== 1 || !$attivo || (int)$attivo['is_active'] !== 1) {
            mysqli_rollback($conn);
            return ['ok' => false, 'codice' => 'invalid_token'];
        }

        $acc = api_token_nuovo(API_TIPO_ACCESSO);
        $rin = api_token_nuovo(API_TIPO_RINNOVO);
        /* Il token che sta per essere sostituito si passa da PHP e non si copia in
           SQL con «prev = refresh»: l'ordine di valutazione delle colonne in un
           UPDATE cambia fra database, qui non si vuole dipendere da quello.
           La scadenza riparte da oggi: un dispositivo usato resta collegato. */
        sec_q(
            "UPDATE api_sessions
                SET prev_refresh_selector = ?, prev_refresh_hash = ?,
                    refresh_selector = ?, refresh_hash = ?,
                    access_selector = ?, access_hash = ?, access_expires_at = NOW() + INTERVAL ? SECOND,
                    last_used_at = NOW(), expires_at = NOW() + INTERVAL ? DAY
              WHERE id = ? AND user_id = ?",
            [$p['selector'], (string)$r['refresh_hash'],
             $rin['selector'], $rin['hash'],
             $acc['selector'], $acc['hash'], (int)API_ACCESS_DURATA_SECONDI,
             (int)API_REFRESH_DURATA_GIORNI,
             (int)$r['id'], $uid],
            'ssssssiiii'
        );
        mysqli_commit($conn);
        api_sessioni_pota_a_caso();
        return ['ok' => true, 'user_id' => $uid, 'tokens' => api_risposta_token((int)$r['id'], $acc['token'], $rin['token'])];
    }

    /* Non e' il token di rinnovo di nessuna sessione attiva: e' uno vecchio? */
    $v = sec_one(
        "SELECT id, user_id, prev_refresh_hash FROM api_sessions
          WHERE prev_refresh_selector = ? AND revoked_at IS NULL FOR UPDATE",
        [$p['selector']], 's'
    );
    if ($v && $v['prev_refresh_hash'] !== null
           && hash_equals((string)$v['prev_refresh_hash'], hash('sha256', $p['validator']))) {
        sec_q("UPDATE api_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ?",
              [(int)$v['id'], (int)$v['user_id']], 'ii');
        mysqli_commit($conn);
        api_audit((int)$v['user_id'], 'api_rinnovo_riuso', 'denied',
                  'dispositivo ' . (int)$v['id'] . ': token di rinnovo gia\' usato, sessione chiusa');
        return ['ok' => false, 'codice' => 'token_reused'];
    }

    mysqli_rollback($conn);
    return ['ok' => false, 'codice' => 'invalid_token'];
}

/* ================= Uscita ================= */

/** Esce da UN dispositivo. false se non esiste, non e' dell'utente o era gia' chiuso. */
function api_sessione_revoca(int $sessioneId, int $uid): bool {
    if ($sessioneId <= 0 || $uid <= 0) return false;
    sec_q("UPDATE api_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL",
          [$sessioneId, $uid], 'ii');
    return sec_affected() > 0;
}

/**
 * Esce da TUTTI i dispositivi dell'utente (PROGETTO §7.3). Con $ancheRicordami
 * si cancellano anche i «ricordami» del sito e si chiudono le sessioni aperte nei
 * browser: «tutti i dispositivi» comprende anche loro.
 * Ritorna quante sessioni a token erano aperte.
 */
function api_sessioni_revoca_tutte(int $uid, bool $ancheRicordami = false): int {
    if ($uid <= 0) return 0;
    sec_q("UPDATE api_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL", [$uid], 'i');
    $n = sec_affected();
    if ($ancheRicordami) {
        sec_q("DELETE FROM remember_tokens WHERE user_id = ?", [$uid], 'i');
        /* Anche le sessioni PHP gia' aperte nei browser: il contatore sale e quelle
           che portano il numero vecchio escono (lib/auth_core.php). Chi vuole restare
           connesso, come chi cambia la password, rilegge il numero nuovo
           (standout_sessione_versione). */
        sec_q("UPDATE users SET session_version = session_version + 1 WHERE id = ?", [$uid], 'i');
    }
    return $n;
}

/* ================= Elenco dei dispositivi ================= */

/**
 * I dispositivi collegati di un utente, il piu' recente per primo.
 * Le date si calcolano come «secondi fa» dall'orologio del database e poi si
 * trasformano in ora UTC: cosi' non dipendono dal fuso orario del server.
 * «last_used_at» ha la precisione di API_ULTIMO_USO_OGNI_SECONDI.
 */
function api_sessioni_elenco(int $uid, int $correnteId): array {
    $righe = sec_all(
        "SELECT id, device_name,
                TIMESTAMPDIFF(SECOND, created_at, NOW())   AS creata_fa,
                TIMESTAMPDIFF(SECOND, last_used_at, NOW()) AS usata_fa
           FROM api_sessions
          WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
          ORDER BY last_used_at DESC, id DESC",
        [$uid], 'i'
    );
    $out = [];
    foreach ($righe as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['device_name'],
            'created_at'   => api_ora_utc((int)$r['creata_fa']),
            'last_used_at' => api_ora_utc((int)$r['usata_fa']),
            'current'      => ((int)$r['id'] === $correnteId),
        ];
    }
    return $out;
}

/** «N secondi fa» -> ora UTC in formato ISO 8601. */
function api_ora_utc(int $secondiFa): string {
    return gmdate('Y-m-d\TH:i:s\Z', time() - max(0, $secondiFa));
}

/* ================= Pulizia ================= */

/** Toglie le sessioni chiuse e quelle scadute: non servono a niente e sono dati personali. */
function api_sessioni_pota(): void {
    sec_q("DELETE FROM api_sessions WHERE revoked_at IS NOT NULL OR expires_at < NOW()");
}

function api_sessioni_pota_a_caso(): void {
    if ((int)API_POTATURA_UNA_SU > 0 && random_int(1, (int)API_POTATURA_UNA_SU) === 1) {
        api_sessioni_pota();
    }
}

/* ================= Login ================= */

/**
 * Entra con nome utente (o email) e password e apre la sessione di un dispositivo.
 *
 * Passa dagli STESSI controlli del login del sito (public/account/login.php),
 * perche' usa lo stesso codice: standout_verifica_credenziali() in auth_core.php
 * (blocco dopo troppi errori, «account attivo», password_verify anche se
 * l'utente non esiste). Nessun altro limite inventato: un tetto per IP
 * chiuderebbe fuori una scuola o un operatore telefonico intero. L'email non
 * verificata NON blocca, come sul sito: serve solo per le cose che coinvolgono
 * altre persone.
 *
 * Ritorna ['ok' => true, 'tokens' => [...], 'user_id' => N]
 * oppure ['ok' => false, 'codice' => 'validation' | 'too_many_attempts' | 'invalid_credentials'].
 */
function api_login(string $identifier, string $password, string $nomeDispositivo): array {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') return ['ok' => false, 'codice' => 'validation'];

    // Blocco, ricerca, password_verify e registro dei tentativi: stesso codice del sito
    $v = standout_verifica_credenziali($identifier, $password);
    if (!$v['ok']) {
        return ['ok' => false, 'codice' => $v['codice'] === 'bloccato' ? 'too_many_attempts' : 'invalid_credentials'];
    }

    $uid = (int)$v['utente']['id'];
    sec_q("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$uid], 'i');

    api_sessioni_pota_a_caso();
    $tokens = api_sessione_crea($uid, $nomeDispositivo, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return ['ok' => true, 'user_id' => $uid, 'tokens' => $tokens];
}

/* ================= Dati dell'utente per il client ================= */

/** Quello che il client puo' sapere di se' (mai password, hash o token). */
function api_utente_pubblico(int $uid): ?array {
    $r = sec_one("SELECT id, username, email, lang, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS creato_fa
                    FROM users WHERE id = ? AND is_active = 1", [$uid], 'i');
    if (!$r) return null;
    return [
        'id'             => (int)$r['id'],
        'username'       => (string)$r['username'],
        'email'          => $r['email'] !== null ? (string)$r['email'] : null,
        'email_verified' => ev_verificata($uid),
        'lang'           => $r['lang'] !== null ? (string)$r['lang'] : null,
        'created_at'     => api_ora_utc((int)$r['creato_fa']),
    ];
}
