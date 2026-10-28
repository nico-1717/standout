<?php
/* =========================================================================
   STANDOUT · «PASSWORD DIMENTICATA» (STANDOUT_PROGETTO.md §7.4)

   Chi non ricorda la password chiede un link; il link arriva all'email
   dell'account e permette di sceglierne una nuova. Stesse regole di
   lib/email_verifica.php, che e' il modello:
   - il token e' casuale (32 byte); nel database c'e' solo il suo sha256
   - vale 60 minuti ed e' monouso; ne esiste uno solo vivo per utente: un link
     nuovo cancella i vecchi
   - vale solo per l'indirizzo a cui e' stato mandato (se cambia, non vale piu')
   - RISPOSTA SEMPRE UGUALE: chi chiede il link non scopre se un nome utente o
     un'email esistono (ne' dal testo ne', per quanto possibile, dal tempo: la
     risposta parte PRIMA del lavoro, vedi password_dimenticata.php)
   - freni: 10 richieste all'ora per indirizzo IP, 3 per nome, 1 ogni 2 minuti e 5
     al giorno per utente; i freni che scattano non cambiano la risposta

   Con la nuova password si chiudono TUTTE le sessioni dell'utente: app, «ricordami»
   e browser aperti (api_sessioni_revoca_tutte). Chi ha avuto la password prima
   non resta dentro. Non si fa entrare l'utente da solo: sceglie la password, poi
   accede come sempre.

   Funziona anche PRIMA della migrazione 2026-10-28_password_resets.sql: senza la
   tabella non parte nessuna email e la pagina risponde come sempre.
   ========================================================================= */

require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/email_verifica.php';
require_once __DIR__ . '/password_policy.php';
require_once __DIR__ . '/api_sessioni.php';

const PR_SCADENZA_MINUTI = 60;

/** La migrazione e' stata eseguita? */
function pr_attiva(): bool {
    static $r = null;
    if ($r !== null) return $r;
    return $r = (@mysqli_query(sec_db(), "SELECT id FROM password_resets LIMIT 0") !== false);
}

/**
 * Manda il link a chi l'ha chiesto, SE l'account esiste e i freni lo permettono.
 * Non ritorna niente e non dice mai che cosa e' successo: chi chiama risponde
 * sempre allo stesso modo.
 */
function pr_richiedi(string $identifier): void {
    if (!pr_attiva()) return;
    $identifier = mb_substr(trim($identifier), 0, 255);
    if ($identifier === '') return;

    /* Per nome: pochi link all'ora per lo stesso nome, esista o no (cosi' il freno non
       rivela nulla). Si conserva l'impronta del nome, non il nome. */
    $chiave = hash('sha256', standout_login_normalizza($identifier));
    if (!sec_rate_limit('pw_reset_nome', $chiave, 3, 3600)) return;

    $u = sec_one("SELECT id, username, email FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1",
                 [$identifier, $identifier], 'ss');
    if (!$u || empty($u['email'])) return;
    $uid = (int)$u['id'];
    if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $u['email'])) return;

    $ultimi = sec_one("SELECT COUNT(*) AS g, SUM(created_at > (NOW() - INTERVAL 2 MINUTE)) AS recenti
                         FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)", [$uid], 'i');
    if ((int)($ultimi['recenti'] ?? 0) > 0 || (int)($ultimi['g'] ?? 0) >= 5) return;

    $sito = ev_sito();
    if ($sito === '') { sec_log_error('password_reset', 'nome del sito non disponibile'); return; }

    /* Un link solo: quelli mandati prima non valgono piu'. */
    sec_q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$uid], 'i');

    $token = bin2hex(random_bytes(32));
    sec_q("INSERT INTO password_resets (user_id, email, token_hash, expires_at)
           VALUES (?, ?, ?, NOW() + INTERVAL " . PR_SCADENZA_MINUTI . " MINUTE)",
          [$uid, $u['email'], hash('sha256', $token)], 'iss');

    /* Pulizia senza cron (su Altervista gratuito non c'e'): ogni tanto via i link vecchi di una settimana. */
    if (random_int(1, 50) === 1) {
        sec_q("DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 7 DAY)");
    }

    $link = 'https://' . $sito . '/account/password_nuova.php?t=' . $token;
    $corpo = t('password_reset.mail_corpo', ['nome' => $u['username'], 'link' => $link, 'minuti' => PR_SCADENZA_MINUTI]);
    $oggetto = t('password_reset.mail_oggetto');
    /* Intestazioni come in ev_invia(): nessun valore dell'utente ci finisce dentro
       (l'indirizzo e' quello gia' validato, senza a capo). */
    $intestazioni = [
        'From: Standout <noreply@' . $sito . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Standout',
    ];
    $ok = @mail($u['email'], mb_encode_mimeheader($oggetto, 'UTF-8', 'B'), $corpo, implode("\r\n", $intestazioni));
    /* Nel registro mai il token ne' il link. */
    if (!$ok) { sec_log_error('password_reset', 'mail() ha rifiutato l\'invio per l\'utente ' . $uid); return; }
    api_audit($uid, 'password_reset_richiesta', 'ok', null);
}

/** Il link e' buono (esiste, non scaduto, non usato, indirizzo invariato, account attivo)? Non lo consuma. */
function pr_token_valido(string $token): ?array {
    if (!pr_attiva() || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return sec_one("SELECT r.id, r.user_id, u.username, u.email
                      FROM password_resets r JOIN users u ON u.id = r.user_id
                     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
                       AND u.email = r.email AND u.is_active = 1", [hash('sha256', $token)]);
}

/**
 * Sceglie la password nuova con il link. Ritorna:
 *   ['ok' => true]
 *   ['ok' => false, 'codice' => 'invalido']                      link non buono
 *   ['ok' => false, 'codice' => 'validazione', 'errori' => [...]]  password non accettata (il link resta buono)
 */
function pr_conferma(string $token, string $nuova, string $conferma): array {
    $r = pr_token_valido($token);
    if (!$r) return ['ok' => false, 'codice' => 'invalido'];
    $uid = (int)$r['user_id'];

    /* Le stesse regole della registrazione. Un errore non consuma il link: chi
       sceglie una password troppo comune deve poter riprovare. */
    $errori = [];
    $esito = standout_password_errore($nuova, (string)$r['username'], (string)$r['email']);
    if ($esito !== null) {
        $errori['nuova'] = t($esito === 'comune' ? 'auth.err_password_comune'
                           : ($esito === 'utente' ? 'auth.err_password_utente' : 'auth.err_password'));
    }
    if ($nuova !== $conferma) $errori['conferma'] = t('auth.err_password_diverse');
    if ($errori) return ['ok' => false, 'codice' => 'validazione', 'errori' => $errori];

    /* Monouso davvero: l'UPDATE vale solo se used_at e' ancora NULL, cosi' due
       richieste insieme non lo consumano due volte. */
    sec_q("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND user_id = ? AND used_at IS NULL", [(int)$r['id'], $uid], 'ii');
    if (sec_affected() !== 1) return ['ok' => false, 'codice' => 'invalido'];

    sec_q("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($nuova, PASSWORD_DEFAULT), $uid], 'si');
    sec_q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$uid], 'i');

    /* Chi aveva la vecchia password non resta dentro: app, «ricordami» e browser. */
    $chiusi = api_sessioni_revoca_tutte($uid, true);

    /* Ha letto la posta di quell'indirizzo: e' la prova che la verifica email chiede. */
    if (ev_attiva()) {
        sec_q("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?", [$uid], 'i');
    }
    /* Se era bloccato dal troppo errori di login, adesso puo' entrare. */
    sec_q("DELETE FROM login_attempts WHERE identifier IN (?, ?)",
          [standout_login_normalizza((string)$r['username']), standout_login_normalizza((string)$r['email'])], 'ss');

    /* Mai la password, ne' il link, nel registro. */
    api_audit($uid, 'password_reset', 'ok', $chiusi . ' dispositivi dell\'app chiusi');
    return ['ok' => true];
}

/**
 * Risponde subito con questo JSON e prosegue il lavoro DOPO aver chiuso la
 * connessione. Serve a «password dimenticata»: se l'account esiste il lavoro
 * (database + invio email) dura piu' di quando non esiste, e chi misura il tempo
 * scoprirebbe quali nomi esistono. Con la risposta gia' partita non c'e' niente
 * da misurare. Dove il server non permette di chiudere prima (nessun
 * fastcgi_finish_request), il flush fa del suo meglio.
 */
function pr_rispondi_poi_lavora(array $payload): void {
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // non tenere bloccata la sessione
    $lunghezza = strlen(json_encode($payload, JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Content-Length: ' . $lunghezza);
        header('Connection: close');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    while (ob_get_level() > 0) { @ob_end_flush(); }
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

/**
 * Lo stile delle pagine «password dimenticata» e «scegli la nuova»: lo stesso di
 * public/account/verifica_email.php (pagine pubbliche a una colonna) piu' i campi
 * del modulo. Qui e non in un file di public/ perche' un file li' si aprirebbe da
 * solo dall'indirizzo; con il nonce, come la CSP richiede.
 */
function pr_stile(): void { ?>
<style nonce="<?php echo sec_nonce(); ?>">
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#FBF9F6;color:#4C463F;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px;text-align:center}
main{max-width:420px;width:100%}
h1{font-weight:500;font-size:24px;margin:0 0 10px}
p{color:#8A8177;margin:0 0 24px;line-height:1.5}
form{text-align:left;margin:0 0 20px}
label{display:block;margin:0 0 14px}
label span{display:block;font-size:13px;color:#8A8177;margin:0 0 6px}
input{box-sizing:border-box;width:100%;padding:12px 14px;border:1px solid #D9D2C8;border-radius:12px;background:#FFFDFA;color:#4C463F;font-size:16px}
button,a.bottone{display:inline-block;padding:12px 24px;border:0;border-radius:14px;background:#A9C3AE;color:#FFFDFA;text-decoration:none;font-size:15px;cursor:pointer}
button:disabled{opacity:.5;cursor:default}
.msg{margin:0 0 16px;font-size:14px;color:#B4574A}
.msg.ok{color:#4F7A5A}
.piccolo{font-size:14px;margin:0}
.piccolo a{color:#8A8177}
</style>
<?php }
