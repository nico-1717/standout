<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
header('Content-Type: application/json');
/* PERCHE': prima il limite era 2 registrazioni all'ora per indirizzo IP e contava
   OGNI richiesta, anche quelle rifiutate (password troppo corta, email gia'
   usata, token scaduto...). Bastavano due tentativi sbagliati per bloccare chi
   stava usando la stessa rete: casa, scuola, telefono in hotspot. Ora ci sono
   due limiti: uno largo sui tentativi (ferma chi martella il modulo) e uno
   stretto sugli account davvero creati, piu' sotto. */
sec_require_rate('register', sec_ip(), 30, 3600);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'dati_mancanti', 'message' => t('auth.err_nessun_dato')]));
}

if (!standout_csrf_verifica($input['csrf_token'] ?? null)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'csrf', 'message' => t('auth.err_sessione')]));
}

require_once __DIR__ . '/../../lib/registrazione.php';
/* i controlli e la creazione dell'account stanno in lib/registrazione.php: li usa
   anche /api/v1/auth/register, cosi' le regole sono una sola */
$esito = reg_esegui($input);
if (!$esito['ok']) {
    http_response_code($esito['http']);
    if ($esito['error'] === 'db') die(json_encode(['success' => false, 'error' => 'db', 'message' => $esito['message']]));
    die(json_encode(['success' => false, 'error' => $esito['error'], 'errori' => $esito['errori']]));
}
$nuovo_id = $esito['uid'];
standout_effettua_login((int)$nuovo_id, true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$nuovo_id, 'username' => $esito['username'], 'email' => $esito['email']]]);

reg_invia_verifica_poi((int)$nuovo_id);
