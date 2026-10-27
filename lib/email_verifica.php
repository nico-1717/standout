<?php
/* =========================================================================
   STANDOUT · VERIFICA DELL'EMAIL (STANDOUT_PROGETTO.md §7.4)

   Chi si registra riceve un link a scadenza (24 ore). Aprirlo scrive
   users.email_verified_at. Finche' non lo fa, l'account funziona lo stesso:
   la verifica serve per le cose che coinvolgono altre persone (pubblicare un
   mazzo nel mercato, il social dell'app) e non deve mai chiudere fuori
   qualcuno perche' la posta e' lenta o finita fra la posta indesiderata.

   Sicurezza:
   - il token e' casuale (32 byte); nel database c'e' solo il suo sha256
   - monouso, scade, e vale solo per l'indirizzo a cui e' stato mandato
   - il link non rivela se l'indirizzo esiste: l'unico messaggio e' "fatto" o
     "non valido o scaduto"
   - reinvio: al massimo 1 ogni 2 minuti e 5 al giorno per utente

   Funziona anche PRIMA della migrazione 2026-10-27_verifica_email.sql: senza
   la colonna o la tabella tutte le funzioni dicono "non applicabile" e non
   fanno niente (nessuna email, nessun avviso, nessun errore).
   ========================================================================= */

const EV_SCADENZA_ORE = 24;

/** La migrazione e' stata eseguita? (colonna e tabella esistono) */
function ev_attiva(): bool {
    static $r = null;
    if ($r !== null) return $r;
    $conn = sec_db();
    $c = @mysqli_query($conn, "SELECT email_verified_at FROM users LIMIT 0");
    $t = @mysqli_query($conn, "SELECT id FROM email_verifications LIMIT 0");
    return $r = ($c !== false && $t !== false);
}

/** L'email dell'utente e' verificata? Senza la migrazione: vero (niente avviso). */
function ev_verificata(int $userId): bool {
    if (!ev_attiva()) return true;
    $r = sec_one("SELECT email_verified_at FROM users WHERE id = ?", [$userId], 'i');
    return $r !== null && $r['email_verified_at'] !== null;
}

/** Nome del sito per i link: mai da HTTP_HOST (lo sceglie chi chiama). */
function ev_sito(): string {
    $nome = defined('STANDOUT_HOST') ? (string)STANDOUT_HOST : (string)($_SERVER['SERVER_NAME'] ?? '');
    return preg_match('/^[A-Za-z0-9.-]+$/', $nome) ? $nome : '';
}

/**
 * Crea il token e manda l'email. Ritorna:
 *   'inviata' | 'gia_verificata' | 'attendi' | 'errore' | 'non_attiva'
 */
function ev_invia(int $userId): string {
    if (!ev_attiva()) return 'non_attiva';
    $u = sec_one("SELECT username, email, email_verified_at FROM users WHERE id = ? AND is_active = 1", [$userId], 'i');
    if (!$u || empty($u['email'])) return 'errore';
    if ($u['email_verified_at'] !== null) return 'gia_verificata';

    $ultimi = sec_one("SELECT COUNT(*) AS g, SUM(created_at > (NOW() - INTERVAL 2 MINUTE)) AS recenti
                         FROM email_verifications WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)", [$userId], 'i');
    if ((int)($ultimi['recenti'] ?? 0) > 0 || (int)($ultimi['g'] ?? 0) >= 5) return 'attendi';

    $sito = ev_sito();
    if ($sito === '') { sec_log_error('email_verifica', 'nome del sito non disponibile'); return 'errore'; }

    $token = bin2hex(random_bytes(32));
    sec_q("INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
           VALUES (?, ?, ?, NOW() + INTERVAL " . EV_SCADENZA_ORE . " HOUR)",
          [$userId, $u['email'], hash('sha256', $token)], 'iss');

    $link = 'https://' . $sito . '/account/verifica_email.php?t=' . $token;
    $corpo = t('verifica_email.mail_corpo', ['nome' => $u['username'], 'link' => $link, 'ore' => EV_SCADENZA_ORE]);
    $oggetto = t('verifica_email.mail_oggetto');

    /* Intestazioni: nessun valore che arrivi dall'utente finisce qui dentro
       (l'indirizzo di destinazione e' quello gia' validato con FILTER_VALIDATE_EMAIL
       alla registrazione; il Reply-To non c'e'). */
    if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $u['email'])) return 'errore';
    $intestazioni = [
        'From: Standout <noreply@' . $sito . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Standout',
    ];
    $ok = @mail($u['email'], mb_encode_mimeheader($oggetto, 'UTF-8', 'B'), $corpo, implode("\r\n", $intestazioni));
    if (!$ok) { sec_log_error('email_verifica', 'mail() ha rifiutato l\'invio per l\'utente ' . $userId); return 'errore'; }
    return 'inviata';
}

/** Consuma il token. Ritorna l'id dell'utente verificato, o null se non valido/scaduto/gia' usato. */
function ev_conferma(string $token): ?int {
    if (!ev_attiva() || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $r = sec_one("SELECT v.id, v.user_id FROM email_verifications v JOIN users u ON u.id = v.user_id
                   WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW()
                     AND u.email = v.email AND u.is_active = 1", [hash('sha256', $token)]);
    if (!$r) return null;
    /* Monouso davvero: l'UPDATE vale solo se used_at e' ancora NULL, cosi' due
       aperture insieme non lo consumano due volte. */
    sec_q("UPDATE email_verifications SET used_at = NOW() WHERE id = ? AND used_at IS NULL", [(int)$r['id']], 'i');
    if (sec_affected() !== 1) return null;
    sec_q("UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL", [(int)$r['user_id']], 'i');
    return (int)$r['user_id'];
}
