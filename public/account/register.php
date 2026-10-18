<?php
require_once 'config.php';
require_once 'auth_core.php';
header('Content-Type: application/json');
sec_require_rate('register', sec_ip(), 2, 3600);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'dati_mancanti', 'message' => 'Nessun dato ricevuto.']));
}

if (!nisama_csrf_verifica($input['csrf_token'] ?? null)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'csrf', 'message' => 'Sessione scaduta, ricarica la pagina e riprova.']));
}

$username  = trim($input['username'] ?? '');
$email     = trim($input['email'] ?? '');
$password  = (string)($input['password'] ?? '');
$password2 = (string)($input['password_confirm'] ?? '');

$errori = [];

if (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username)) {
    $errori['username'] = 'Il nome utente deve avere 3-30 caratteri (lettere, numeri, punto o underscore, senza spazi).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errori['email'] = 'Inserisci un indirizzo email valido.';
}
if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $errori['password'] = 'La password deve avere almeno 8 caratteri, con lettere e numeri.';
}
if ($password !== $password2) {
    $errori['password_confirm'] = 'Le due password non coincidono.';
}

if (!empty($errori)) {
    http_response_code(422);
    die(json_encode(['success' => false, 'error' => 'validazione', 'errori' => $errori]));
}

$conn = nisama_db();

// Controlliamo separatamente unicità di username ed email, per poter dire
// con precisione all'utente quale dei due è già in uso (in fase di
// registrazione è normale essere specifici, a differenza del login).
$stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$usernameEsiste = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$emailEsiste = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

if ($usernameEsiste || $emailEsiste) {
    http_response_code(409);
    $errori = [];
    if ($usernameEsiste) $errori['username'] = 'Questo nome utente è già in uso.';
    if ($emailEsiste)     $errori['email'] = 'Questa email è già registrata.';
    die(json_encode(['success' => false, 'error' => 'utente_esistente', 'errori' => $errori]));
}

$hash = password_hash($password, PASSWORD_DEFAULT);

mysqli_begin_transaction($conn);
$ok = true;

$stmt = mysqli_prepare($conn, "INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, NOW())");
if (!$stmt) { $ok = false; }
if ($ok) {
    mysqli_stmt_bind_param($stmt, 'sss', $username, $email, $hash);
    $ok = mysqli_stmt_execute($stmt);
}
$nuovo_id = $ok ? mysqli_insert_id($conn) : 0;
if ($stmt) mysqli_stmt_close($stmt);

if ($ok) {
    $primoGiornoMese = date('Y-m-01');
    $stmt2 = mysqli_prepare($conn, "INSERT INTO user_plans (user_id, piano_id, subscribed_at, free_trial_used, reference_month) VALUES (?, 1, NOW(), 0, ?)");
    if (!$stmt2) { $ok = false; }
    if ($ok) {
        mysqli_stmt_bind_param($stmt2, 'is', $nuovo_id, $primoGiornoMese);
        $ok = mysqli_stmt_execute($stmt2);
    }
    if ($stmt2) mysqli_stmt_close($stmt2);
}

if ($ok) {
    mysqli_commit($conn);
} else {
    mysqli_rollback($conn);
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'db', 'message' => 'Errore durante la registrazione. Riprova.']));
}

nisama_registra_tentativo($email, true);
nisama_effettua_login((int)$nuovo_id, true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$nuovo_id, 'username' => $username, 'email' => $email]]);
