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

if (standout_login_bloccato($identifier)) {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'troppi_tentativi', 'message' => t('auth.err_troppi_tentativi')]));
}

$conn = standout_db();
$stmt = mysqli_prepare($conn, "SELECT id, username, email, password_hash FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$utente = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

// Messaggio volutamente generico: non riveliamo se è l'email/username o la password ad essere sbagliati
if (!$utente || empty($utente['password_hash']) || !password_verify($password, $utente['password_hash'])) {
    standout_registra_tentativo($identifier, false);
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'credenziali_invalide', 'message' => t('auth.err_credenziali')]));
}

// Se in futuro cambi l'algoritmo di hashing di default di PHP, qui lo aggiorniamo in automatico
if (password_needs_rehash($utente['password_hash'], PASSWORD_DEFAULT)) {
    $nuovoHash = password_hash($password, PASSWORD_DEFAULT);
    $stmtU = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmtU, 'si', $nuovoHash, $utente['id']);
    mysqli_stmt_execute($stmtU);
    mysqli_stmt_close($stmtU);
}

standout_registra_tentativo($identifier, true);
standout_effettua_login((int)$utente['id'], true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$utente['id'], 'username' => $utente['username'], 'email' => $utente['email']]]);
