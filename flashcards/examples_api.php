<?php
require_once 'config.php';

// Bufferizziamo l'output: senza, un qualsiasi Warning/Notice di PHP (indice non
// definito, connessione DB, deprecazioni mysqli, ecc.) viene stampato PRIMA del
// JSON e lo rompe. Il fetch() del frontend riceve allora una risposta non
// decodificabile e fallisce con un errore, anche se la query di fondo è andata
// a buon fine: era la causa più probabile del "carica e poi dà sempre errore".
ob_start();

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

function ex_api_json_out(array $payload): void {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$conn) {
    ex_api_json_out(['success' => false, 'error' => 'Errore di connessione al Database.']);
}

if (isset($_GET['action']) && $_GET['action'] == 'get') {

    $fc_id = isset($_GET['fc_id']) ? intval($_GET['fc_id']) : 0;

    $res = mysqli_query($conn, "SELECT * FROM fc_examples WHERE fc_image_id = $fc_id ORDER BY id ASC");

    if (!$res) {
        ex_api_json_out(['success' => false, 'error' => 'Errore SQL: ' . mysqli_error($conn)]);
    }

    $examples = [];
    while ($row = mysqli_fetch_assoc($res)) { $examples[] = $row; }

    ex_api_json_out(['success' => true, 'examples' => $examples]);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {

    $fc_id = isset($_POST['fc_id']) ? intval($_POST['fc_id']) : 0;
    $ex_id = isset($_POST['ex_id']) ? intval($_POST['ex_id']) : 0;
    $text = mysqli_real_escape_string($conn, trim(isset($_POST['text']) ? $_POST['text'] : ''));

    $img_update = "";
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        if (!is_dir('fc_images')) { @mkdir('fc_images', 0755, true); }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imgName = "ex_img_" . time() . "_" . mt_rand(1000, 9999) . ".$ext";
        if (move_uploaded_file($_FILES['image']['tmp_name'], 'fc_images/' . $imgName)) {
            $img_update = ", example_image = '" . mysqli_real_escape_string($conn, $imgName) . "'";
        }
    }

    $audio_update = "";
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] == 0) {
        if (!is_dir('fc_audio')) { @mkdir('fc_audio', 0755, true); }
        $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
        $audName = "ex_aud_" . time() . "_" . mt_rand(1000, 9999) . ".$ext";
        if (move_uploaded_file($_FILES['audio']['tmp_name'], 'fc_audio/' . $audName)) {
            $audio_update = ", example_audio = '" . mysqli_real_escape_string($conn, $audName) . "'";
        }
    }

    if ($ex_id > 0) {
        $ok = mysqli_query($conn, "UPDATE fc_examples SET example_text = '$text' $img_update $audio_update WHERE id = $ex_id");
    } else {
        $ok = mysqli_query($conn, "INSERT INTO fc_examples (fc_image_id, example_text) VALUES ($fc_id, '$text')");
        if ($ok) {
            $ex_id = mysqli_insert_id($conn);
            if ($img_update != "" || $audio_update != "") {
                mysqli_query($conn, "UPDATE fc_examples SET id = id $img_update $audio_update WHERE id = $ex_id");
            }
        }
    }

    if (!$ok) {
        ex_api_json_out(['success' => false, 'error' => 'Errore SQL: ' . mysqli_error($conn)]);
    }

    ex_api_json_out(['success' => true, 'ex_id' => $ex_id]);
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {

    $ex_id = isset($_GET['ex_id']) ? intval($_GET['ex_id']) : 0;

    $ok = mysqli_query($conn, "DELETE FROM fc_examples WHERE id = $ex_id");

    if (!$ok) {
        ex_api_json_out(['success' => false, 'error' => 'Errore SQL: ' . mysqli_error($conn)]);
    }

    ex_api_json_out(['success' => true]);
}

ex_api_json_out(['success' => false, 'error' => 'Azione non riconosciuta.']);