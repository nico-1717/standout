<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';
header('Content-Type: application/json');
sec_require_rate('register', sec_ip(), 2, 3600);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'dati_mancanti', 'message' => t('auth.err_nessun_dato')]));
}

if (!standout_csrf_verifica($input['csrf_token'] ?? null)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'csrf', 'message' => t('auth.err_sessione')]));
}

$username  = trim($input['username'] ?? '');
$email     = trim($input['email'] ?? '');
$password  = (string)($input['password'] ?? '');
$password2 = (string)($input['password_confirm'] ?? '');

$errori = [];

if (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username)) {
    $errori['username'] = t('auth.err_username');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errori['email'] = t('auth.err_email');
}
if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $errori['password'] = t('auth.err_password');
}
if ($password !== $password2) {
    $errori['password_confirm'] = t('auth.err_password_diverse');
}

if (!empty($errori)) {
    http_response_code(422);
    die(json_encode(['success' => false, 'error' => 'validazione', 'errori' => $errori]));
}

$conn = standout_db();

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
    if ($usernameEsiste) $errori['username'] = t('auth.err_username_usato');
    if ($emailEsiste)     $errori['email'] = t('auth.err_email_usata');
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
    die(json_encode(['success' => false, 'error' => 'db', 'message' => t('auth.err_registrazione')]));
}

standout_registra_tentativo($email, true);
standout_effettua_login((int)$nuovo_id, true);

echo json_encode(['success' => true, 'user' => ['id' => (int)$nuovo_id, 'username' => $username, 'email' => $email]]);
