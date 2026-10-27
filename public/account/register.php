<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/password_policy.php';
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

/* Solo testo: se qualcuno manda un array o un numero al posto di una stringa,
   trim() lancerebbe un errore PHP (500) invece di una risposta pulita. */
$testo = function ($v): string { return is_string($v) ? $v : ''; };
$username  = trim($testo($input['username'] ?? ''));
$email     = trim($testo($input['email'] ?? ''));
$password  = $testo($input['password'] ?? '');
$password2 = $testo($input['password_confirm'] ?? '');

$errori = [];

if (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username)) {
    $errori['username'] = t('auth.err_username');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errori['email'] = t('auth.err_email');
}
/* Regola in lib/password_policy.php: 10+ caratteri, non fra le password piu'
   diffuse, non il proprio nome utente o la propria email. */
$esito_password = standout_password_errore($password, $username, $email);
if ($esito_password !== null) {
    $errori['password'] = t($esito_password === 'comune' ? 'auth.err_password_comune'
                          : ($esito_password === 'utente' ? 'auth.err_password_utente' : 'auth.err_password'));
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

/* Qui i dati sono validi e liberi: si sta per creare un account. Solo questo
   passaggio consuma il limite stretto (5 account all'ora per rete). */
sec_require_rate('register_ok', sec_ip(), 5, 3600);

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

/* Email di verifica (lib/email_verifica.php). Si manda DOPO aver risposto: mail()
   puo' essere lenta e chi si e' appena registrato non deve aspettarla. Se
   qualcosa va storto la registrazione resta valida: l'avviso nel pannello
   permette di richiederla di nuovo. */
while (ob_get_level() > 0) { @ob_end_flush(); }   // config.php apre un buffer: va svuotato perche' la risposta parta
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
else { @flush(); }
try {
    require_once __DIR__ . '/../../lib/email_verifica.php';
    ev_invia((int)$nuovo_id);
} catch (Throwable $e) {
    sec_log_error('registrazione', 'email di verifica: ' . $e->getMessage());
}
