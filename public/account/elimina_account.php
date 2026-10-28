<?php
/* "Elimina l'account": cancella l'utente e tutti i suoi dati, per sempre.
   Si risponde con JSON. Serve la password: una sessione lasciata aperta su un
   computer non basta a distruggere un account. */
require_once __DIR__ . '/../../lib/config.php';
/* deve funzionare anche prima di aver accettato i testi (vedi auth_check.php) */
define('STANDOUT_SENZA_TERMINI', true);
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/account_dati.php';

header('Content-Type: application/json');
$uid = (int)$utente_corrente['id'];

/* Token + origine + 5 richieste all'ora: e' anche il freno a chi prova
   password a caso da una sessione rubata. */
sec_guard_write($uid, 'account_elimina', 0, 5, 3600);

$input = sec_body();
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
if (($input['conferma'] ?? false) !== true) {
    sec_die_json(t('privacydati.err_generico'), 422, 'invalid');
}

$riga = sec_one("SELECT password_hash FROM users WHERE id = ?", [$uid]);
$hash = (string)($riga['password_hash'] ?? '');
if ($hash === '' || $password === '' || !password_verify($password, $hash)) {
    sec_audit('account_elimina', 'denied', 'password errata');
    sec_die_json(t('privacydati.err_password'), 403, 'bad_password');
}

try {
    $esito = account_elimina($uid);
} catch (Throwable $e) {
    sec_log_error('account_elimina', $e->getMessage());
    sec_die_json(t('privacydati.err_generico'), 500, 'server');
}

/* Una riga di registro senza legame con l'utente (non c'e' piu'): serve solo
   a sapere che e' successo. Non porta ne' nome ne' email. */
@sec_q("INSERT INTO security_audit_log (user_id, action, outcome, detail, ip_address) VALUES (NULL, 'account_elimina', 'ok', ?, ?)",
       ['utente ' . $uid . ': ' . $esito['righe'] . ' righe, ' . $esito['file'] . ' file', sec_ip()]);

/* la sessione e il cookie «ricordami» non valgono piu' */
standout_effettua_logout();
echo json_encode(['success' => true]);
