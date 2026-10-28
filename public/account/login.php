<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'dati_mancanti', 'message' => t('auth.err_nessun_dato')]));
}

if (!standout_csrf_verifica($input['csrf_token'] ?? null)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'csrf', 'message' => t('auth.err_sessione')]));
}

/* Solo testo: un array al posto di una stringa farebbe lanciare un errore a trim(). */
$identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
$password   = is_string($input['password'] ?? null) ? $input['password'] : '';

if ($identifier === '' || $password === '') {
    http_response_code(422);
    die(json_encode(['success' => false, 'error' => 'validazione', 'message' => t('auth.err_campi_login')]));
}

/* Blocco dopo troppi errori, ricerca, password_verify, registro dei tentativi e
   aggiornamento dell'hash: stesso codice dell'accesso dell'app (auth_core.php). */
$v = standout_verifica_credenziali($identifier, $password);
if (!$v['ok'] && $v['codice'] === 'bloccato') {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'troppi_tentativi', 'message' => t('auth.err_troppi_tentativi')]));
}
// Messaggio volutamente generico: non riveliamo se è l'email/username o la password ad essere sbagliati
if (!$v['ok']) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'credenziali_invalide', 'message' => t('auth.err_credenziali')]));
}
$utente = $v['utente'];

standout_effettua_login((int)$utente['id'], true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$utente['id'], 'username' => $utente['username'], 'email' => $utente['email']]]);
