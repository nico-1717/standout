<?php
require_once __DIR__ . '/../../lib/config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $username = mysqli_real_escape_string($conn, $username);
    
    if ($username !== '') {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '$username'");
        if (mysqli_num_rows($check) == 0) {
            // Aggiornato per inserire la data di creazione normalizzata (created_at) e lo stato attivo (is_active)
            mysqli_query($conn, "INSERT INTO users (username, created_at, is_active) VALUES ('$username', NOW(), 1)");
            $new_id = mysqli_insert_id($conn);
            echo json_encode(['success' => true, 'id' => $new_id, 'username' => $username]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User already exists.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Name cannot be empty.']);
    }
}
mysqli_close($conn);
?>