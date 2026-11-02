<?php
/* =========================================================================
   STANDOUT · REGISTRAZIONE (la parte che vale per il sito e per l'app)
   PERCHE' qui: prima stava tutta dentro public/account/register.php. L'app ha
   bisogno degli stessi controlli (consenso, nome, email, password, doppioni,
   limite di account per rete) e due copie dello stesso codice, col tempo,
   diventano due regole diverse. Ora c'e' una funzione sola; il sito e
   /api/v1/auth/register la chiamano e poi fanno ciascuno il proprio login.
   ========================================================================= */
require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/password_policy.php';

/**
 * Controlla e crea l'account.
 * Ritorna ['ok' => true, 'uid' => N, 'username' => ..., 'email' => ...]
 * oppure   ['ok' => false, 'http' => 422|409|500, 'error' => codice, 'errori' => [campo => testo]]
 * (le chiamate che non rispondono entro i limiti di frequenza si fermano da sole con 429).
 */
function reg_esegui(array $input): array {
    /* Solo testo: se qualcuno manda un array o un numero al posto di una stringa,
       trim() lancerebbe un errore PHP (500) invece di una risposta pulita. */
    $testo = function ($v): string { return is_string($v) ? $v : ''; };
    $username  = trim($testo($input['username'] ?? ''));
    $email     = trim($testo($input['email'] ?? ''));
    $password  = $testo($input['password'] ?? '');
    $password2 = $testo($input['password_confirm'] ?? '');

    $errori = [];

    /* PERCHE': il consenso a privacy e condizioni (e il «ho almeno 14 anni») deve
       essere vero sul SERVER, non solo nel modulo: chi chiama l'indirizzo a mano
       non vedrebbe mai la casella. Si accetta solo con true, non con "1" o con un
       testo qualunque. */
    if (($input['accetto'] ?? false) !== true) {
        $errori['accetto'] = t('auth.err_accetta');
    }
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
        return ['ok' => false, 'http' => 422, 'error' => 'validazione', 'errori' => $errori];
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
        $errori = [];
        if ($usernameEsiste) $errori['username'] = t('auth.err_username_usato');
        if ($emailEsiste)     $errori['email'] = t('auth.err_email_usata');
        return ['ok' => false, 'http' => 409, 'error' => 'utente_esistente', 'errori' => $errori];
    }

    /* Qui i dati sono validi e liberi: si sta per creare un account. Solo questo
       passaggio consuma il limite stretto (5 account all'ora per rete). */
    sec_require_rate('register_ok', sec_ip(), 5, 3600);

    $hash = password_hash($password, PASSWORD_DEFAULT);

    mysqli_begin_transaction($conn);
    $ok = true;

    /* con il consenso: quando e quale versione dei testi (lib/legale.php) */
    require_once __DIR__ . '/legale.php';
    $versione_testi = STANDOUT_LEGALE_VERSIONE;
    $stmt = mysqli_prepare($conn, "INSERT INTO users (username, email, password_hash, created_at, terms_accepted_at, terms_version) VALUES (?, ?, ?, NOW(), NOW(), ?)");
    if (!$stmt) { $ok = false; }
    if ($ok) {
        mysqli_stmt_bind_param($stmt, 'ssss', $username, $email, $hash, $versione_testi);
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
        return ['ok' => false, 'http' => 500, 'error' => 'db', 'message' => t('auth.err_registrazione')];
    }

    standout_registra_tentativo($email, true);
    return ['ok' => true, 'uid' => (int)$nuovo_id, 'username' => $username, 'email' => $email];
}

/**
 * L'email di verifica si manda DOPO aver risposto: mail() puo' essere lenta e chi
 * si e' appena registrato non deve aspettarla. Se qualcosa va storto la
 * registrazione resta valida: l'avviso nel pannello permette di richiederla di nuovo.
 */
function reg_invia_verifica_poi(int $uid): void {
    while (ob_get_level() > 0) { @ob_end_flush(); }   // config.php apre un buffer: va svuotato perche' la risposta parta
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    else { @flush(); }
    try {
        require_once __DIR__ . '/email_verifica.php';
        ev_invia($uid);
    } catch (Throwable $e) {
        sec_log_error('registrazione', 'email di verifica: ' . $e->getMessage());
    }
}
