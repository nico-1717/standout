<?php
/* "Cambia password": l'utente connesso sceglie una password nuova scrivendo
   quella attuale. Si risponde con JSON, come elimina_account.php. */
require_once __DIR__ . '/../../lib/bootstrap.php';
/* deve funzionare anche prima di aver accettato i testi (vedi auth_check_api.php) */
define('STANDOUT_SENZA_TERMINI', true);
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/password_policy.php';
require_once __DIR__ . '/../../lib/api_sessioni.php';

header('Content-Type: application/json');
$uid = (int)$utente_corrente['id'];

/* Token CSRF + origine + 10 richieste all'ora: e' anche il freno a chi prova
   password a caso da una sessione rubata (serve quella attuale per cambiarla).
   10 e non 5 come per l'eliminazione: chi sceglie una password nuova puo'
   sbagliare piu' volte (troppo comune, conferma diversa) senza essere un attacco. */
sec_guard_write($uid, 'password_cambia', 0, 10, 3600);

$input = sec_body();
$attuale = is_string($input['attuale'] ?? null) ? $input['attuale'] : '';
$nuova   = is_string($input['nuova'] ?? null) ? $input['nuova'] : '';
$conferma = is_string($input['conferma'] ?? null) ? $input['conferma'] : '';

/* Una sessione lasciata aperta non basta per cambiare la password: serve quella
   attuale, come per eliminare l'account. Il messaggio e' lo stesso di li'. */
$riga = sec_one("SELECT username, email, password_hash FROM users WHERE id = ?", [$uid], 'i');
$hash = (string)($riga['password_hash'] ?? '');
if ($hash === '' || $attuale === '' || !password_verify($attuale, $hash)) {
    sec_audit('password_cambia', 'denied', 'password attuale errata');
    sec_die_json(t('privacydati.err_password'), 403, 'bad_password');
}

/* Le stesse regole della registrazione (lib/password_policy.php). Una password
   gia' accettata qui sotto potrebbe essere corta se e' un account vecchio: la
   regola vale per quella NUOVA. */
$errori = [];
$esito = standout_password_errore($nuova, (string)$riga['username'], (string)$riga['email']);
if ($esito !== null) {
    $errori['nuova'] = t($esito === 'comune' ? 'auth.err_password_comune'
                       : ($esito === 'utente' ? 'auth.err_password_utente' : 'auth.err_password'));
} elseif (hash_equals($attuale, $nuova)) {
    $errori['nuova'] = t('privacydati.cambia_uguale');
}
if ($nuova !== $conferma) {
    $errori['conferma'] = t('auth.err_password_diverse');
}
if ($errori) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'validazione', 'errori' => $errori], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    sec_q("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($nuova, PASSWORD_DEFAULT), $uid], 'si');
    /* Chi aveva la vecchia password (un telefono perso, un computer prestato) non
       deve restare dentro: si chiudono tutti i dispositivi dell'app e tutti i
       «ricordami» del sito (PROGETTO §7.3). Questo browser resta connesso: la sua
       sessione PHP non dipende dai «ricordami». */
    $chiusi = api_sessioni_revoca_tutte($uid, true);
} catch (Throwable $e) {
    sec_log_error('password_cambia', get_class($e));
    sec_die_json(t('privacydati.err_generico'), 500, 'server');
}

/* Nuovo id di sessione: quello vecchio potrebbe essere stato visto da altri. */
if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);

/* Mai la password, ne' vecchia ne' nuova, nel registro. */
sec_audit('password_cambia', 'ok', $chiusi . ' dispositivi dell\'app chiusi');
echo json_encode(['success' => true]);
