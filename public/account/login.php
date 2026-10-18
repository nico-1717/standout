<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'dati_mancanti', 'message' => 'Nessun dato ricevuto.']));
}

if (!nisama_csrf_verifica($input['csrf_token'] ?? null)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'csrf', 'message' => 'Sessione scaduta, ricarica la pagina e riprova.']));
}

$identifier = trim($input['identifier'] ?? '');
$password   = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    http_response_code(422);
    die(json_encode(['success' => false, 'error' => 'validazione', 'message' => 'Inserisci email/nome utente e password.']));
}

if (nisama_login_bloccato($identifier)) {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'troppi_tentativi', 'message' => 'Troppi tentativi non riusciti. Riprova tra qualche minuto.']));
}

$conn = nisama_db();
$stmt = mysqli_prepare($conn, "SELECT id, username, email, password_hash FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$utente = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

// Messaggio volutamente generico: non riveliamo se è l'email/username o la password ad essere sbagliati
if (!$utente || empty($utente['password_hash']) || !password_verify($password, $utente['password_hash'])) {
    nisama_registra_tentativo($identifier, false);
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'credenziali_invalide', 'message' => 'Email/nome utente o password non corretti.']));
}

// Se in futuro cambi l'algoritmo di hashing di default di PHP, qui lo aggiorniamo in automatico
if (password_needs_rehash($utente['password_hash'], PASSWORD_DEFAULT)) {
    $nuovoHash = password_hash($password, PASSWORD_DEFAULT);
    $stmtU = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmtU, 'si', $nuovoHash, $utente['id']);
    mysqli_stmt_execute($stmtU);
    mysqli_stmt_close($stmtU);
}

nisama_registra_tentativo($identifier, true);
nisama_effettua_login((int)$utente['id'], true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$utente['id'], 'username' => $utente['username'], 'email' => $utente['email']]]);
