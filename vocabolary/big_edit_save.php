<?php
require_once 'config.php';
require_once 'auth_check.php'; // 1. Protezione con login obbligatorio

$current_user_id = $utente_corrente['id']; // 2. ID utente sicuro dal server

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

header('Content-Type: application/json');

$catID = intval($_POST['category_id'] ?? 0);

// --- INIZIO SICUREZZA: verifica proprietario della categoria ---
$check_cat = mysqli_query($conn, "SELECT user_id FROM categories WHERE id = $catID");
$row_cat = $check_cat ? mysqli_fetch_assoc($check_cat) : null;
if (!$row_cat || (int)$row_cat['user_id'] !== $current_user_id) {
    echo json_encode(['success' => false, 'error' => 'Non sei autorizzato a modificare questa categoria.']);
    mysqli_close($conn);
    exit;
}
// --- FINE SICUREZZA ---

if (!is_dir(IMAGE_PATH_INSERT)) { mkdir(IMAGE_PATH_INSERT, 0777, true); }

$cards = isset($_POST['cards']) ? $_POST['cards'] : [];

// Mappa indice-riga -> id reale del padre, serve per agganciare correttamente i figli
$parent_real_ids = [];

// ============================================================
// FASE 1 — PADRI (righe non-figlio)
// ============================================================
foreach ($cards as $i => $c) {
    if ((int)($c['is_child'] ?? 0) === 1) continue;

    $status = $c['status'] ?? 'clean';
    $id = intval($c['id'] ?? 0);

    // Eliminazione (limitata alla categoria verificata) — elimina anche gli eventuali figli
    if ($status === 'deleted' && $id > 0) {
        $q_old = mysqli_query($conn, "SELECT image FROM images WHERE id = $id AND category_id = $catID");
        $old = $q_old ? mysqli_fetch_assoc($q_old) : null;
        if ($old && !empty($old['image']) && file_exists(IMAGE_PATH_INSERT . $old['image'])) {
            unlink(IMAGE_PATH_INSERT . $old['image']);
        }
        mysqli_query($conn, "DELETE FROM english_child_words WHERE parent_id IN (SELECT id FROM images WHERE id = $id AND category_id = $catID)");
        mysqli_query($conn, "DELETE FROM images WHERE id = $id AND category_id = $catID");
        continue;
    }

    $en      = ucfirst(str_replace("'", "’", trim($c['en'] ?? '')));
    $it      = ucfirst(str_replace("'", "’", trim($c['it'] ?? '')));
    $meaning = ucfirst(str_replace("'", "’", trim($c['meaning'] ?? '')));
    $example = ucfirst(str_replace("'", "’", trim($c['example'] ?? '')));

    // Riga completamente vuota senza id (nuova, non compilata): saltala
    if ($status === 'new' && $en === '' && $it === '') { continue; }

    $enEsc   = mysqli_real_escape_string($conn, $en);
    $itEsc   = mysqli_real_escape_string($conn, $it);
    $meanEsc = mysqli_real_escape_string($conn, $meaning);
    $exEsc   = mysqli_real_escape_string($conn, $example);

    // Gestione immagine (upload diretto su questa riga, indicizzato come row_img[i])
    $imgUpdate = "";
    if (isset($_FILES['row_img']['name'][$i]) && $_FILES['row_img']['error'][$i] == 0) {
        $q_old2 = ($id > 0) ? mysqli_query($conn, "SELECT image FROM images WHERE id = $id AND category_id = $catID") : null;
        $old2 = $q_old2 ? mysqli_fetch_assoc($q_old2) : null;
        if ($old2 && !empty($old2['image']) && file_exists(IMAGE_PATH_INSERT . $old2['image'])) {
            unlink(IMAGE_PATH_INSERT . $old2['image']);
        }
        $ext = pathinfo($_FILES['row_img']['name'][$i], PATHINFO_EXTENSION);
        $newImg = time() . "_edit_$i." . $ext;
        move_uploaded_file($_FILES['row_img']['tmp_name'][$i], IMAGE_PATH_INSERT . $newImg);
        $imgEsc = mysqli_real_escape_string($conn, $newImg);
        $imgUpdate = ", image = '$imgEsc'";
    }

    $real_id = 0;
    if ($status === 'new') {
        mysqli_query($conn, "INSERT INTO images (category_id, description_en, description_it, meaning, example, is_father)
                             VALUES ($catID, '$enEsc', '$itEsc', '$meanEsc', '$exEsc', 0)");
        $real_id = mysqli_insert_id($conn);
        if ($imgUpdate !== '' && $real_id > 0) {
            // L'immagine è stata salvata su disco sopra; ora che conosciamo il vero id
            // del nuovo record la colleghiamo con un UPDATE mirato.
            mysqli_query($conn, "UPDATE images SET " . ltrim($imgUpdate, ', ') . " WHERE id = $real_id");
        }
    } else if ($id > 0) {
        mysqli_query($conn, "UPDATE images SET description_en = '$enEsc', description_it = '$itEsc', meaning = '$meanEsc', example = '$exEsc' $imgUpdate WHERE id = $id AND category_id = $catID");
        $real_id = $id;
    }
    $parent_real_ids[$i] = $real_id;
}

// ============================================================
// FASE 2 — FIGLI (english_child_words)
// ============================================================
foreach ($cards as $i => $c) {
    if ((int)($c['is_child'] ?? 0) !== 1) continue;

    $status = $c['status'] ?? 'clean';
    $id = intval($c['id'] ?? 0);

    if ($status === 'deleted' && $id > 0) {
        // Verifica che il figlio appartenga davvero a questa categoria prima di eliminarlo
        mysqli_query($conn, "DELETE FROM english_child_words WHERE id_child = $id AND parent_id IN (SELECT id FROM images WHERE category_id = $catID)");
        continue;
    }

    if ($id <= 0) { continue; } // niente creazione di nuovi figli in questa vista, solo modifica/eliminazione

    $en      = ucfirst(str_replace("'", "’", trim($c['en'] ?? '')));
    $it      = ucfirst(str_replace("'", "’", trim($c['it'] ?? '')));
    $meaning = ucfirst(str_replace("'", "’", trim($c['meaning'] ?? '')));
    $example = ucfirst(str_replace("'", "’", trim($c['example'] ?? '')));

    $enEsc   = mysqli_real_escape_string($conn, $en);
    $itEsc   = mysqli_real_escape_string($conn, $it);
    $meanEsc = mysqli_real_escape_string($conn, $meaning);
    $exEsc   = mysqli_real_escape_string($conn, $example);

    $imgUpdate = "";
    if (isset($_FILES['row_img']['name'][$i]) && $_FILES['row_img']['error'][$i] == 0) {
        $q_old3 = mysqli_query($conn, "SELECT image FROM english_child_words WHERE id_child = $id");
        $old3 = $q_old3 ? mysqli_fetch_assoc($q_old3) : null;
        if ($old3 && !empty($old3['image']) && file_exists(IMAGE_PATH_INSERT . $old3['image'])) {
            unlink(IMAGE_PATH_INSERT . $old3['image']);
        }
        $ext = pathinfo($_FILES['row_img']['name'][$i], PATHINFO_EXTENSION);
        $newImg = time() . "_edit_$i." . $ext;
        move_uploaded_file($_FILES['row_img']['tmp_name'][$i], IMAGE_PATH_INSERT . $newImg);
        $imgEsc = mysqli_real_escape_string($conn, $newImg);
        $imgUpdate = ", image = '$imgEsc'";
    }

    // Sicurezza: aggiorna solo se il figlio appartiene a un padre di questa categoria
    mysqli_query($conn, "UPDATE english_child_words SET description_en = '$enEsc', description_it = '$itEsc', meaning = '$meanEsc', example = '$exEsc' $imgUpdate
                         WHERE id_child = $id AND parent_id IN (SELECT id FROM images WHERE category_id = $catID)");
}

echo json_encode(['success' => true]);
mysqli_close($conn);