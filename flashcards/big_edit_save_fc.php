<?php
require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = $utente_corrente['id'];
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die(json_encode(['success' => false, 'error' => 'Connessione fallita'])); }

header('Content-Type: application/json');

if (!isset($_POST['category_id'])) {
    echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
    exit;
}

$categoryId = intval($_POST['category_id']);

// --- INIZIO SICUREZZA ---
$check_cat = mysqli_query($conn, "SELECT user_id FROM fc_categories WHERE id = $categoryId");
$row_cat = mysqli_fetch_assoc($check_cat);
if (!$row_cat || (int)$row_cat['user_id'] !== $current_user_id) {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato a modificare questa categoria']);
    exit;
}
// --- FINE SICUREZZA ---

$cards = isset($_POST['cards']) ? $_POST['cards'] : [];
$reordered = isset($_POST['reordered']) ? intval($_POST['reordered']) : 1;

// ==========================================
// PERCORSO VELOCE: NESSUN CAMBIO STRUTTURALE
// ==========================================
if ($reordered === 0) {
    foreach ($cards as $i => $c) {
        $status = $c['status'];
        if ($status === 'clean' || $status === 'deleted') continue; 
        
        $id = intval($c['id']);
        $orig_type = $c['orig_type'];
        $q = mysqli_real_escape_string($conn, trim($c['q'] ?? ''));
        $a = mysqli_real_escape_string($conn, trim($c['a'] ?? ''));
        
        $q_img_update = ""; $a_img_update = ""; $q_aud_update = ""; $a_aud_update = "";
        if (isset($_FILES['row_q_img']['name'][$i]) && $_FILES['row_q_img']['error'][$i] == 0) {
            $final = time() . "_q_edit_$i." . pathinfo($_FILES['row_q_img']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['row_q_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final);
            $q_img_update = ", question_image = '$final'";
        }
        if (isset($_FILES['row_a_img']['name'][$i]) && $_FILES['row_a_img']['error'][$i] == 0) {
            $final = time() . "_a_edit_$i." . pathinfo($_FILES['row_a_img']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['row_a_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final);
            $a_img_update = ", answer_image = '$final'";
        }
        if (isset($_FILES['audio_q']['name'][$i]) && $_FILES['audio_q']['error'][$i] == 0) {
            $final = "audio_q_" . time() . "_edit_$i." . pathinfo($_FILES['audio_q']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['audio_q']['tmp_name'][$i], 'fc_audio/' . $final);
            $q_aud_update = ", question_audio = '$final'";
        }
        if (isset($_FILES['audio_a']['name'][$i]) && $_FILES['audio_a']['error'][$i] == 0) {
            $final = "audio_a_" . time() . "_edit_$i." . pathinfo($_FILES['audio_a']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['audio_a']['tmp_name'][$i], 'fc_audio/' . $final);
            $a_aud_update = ", answer_audio = '$final'";
        }

        if ($orig_type == 'parent') {
            mysqli_query($conn, "UPDATE fc_images SET question_desc = '$q', answer_desc = '$a' $q_img_update $a_img_update $q_aud_update $a_aud_update WHERE id = $id AND category_id = $categoryId");
        } else {
            mysqli_query($conn, "UPDATE fc_child_cards SET question_desc = '$q', answer_desc = '$a' $q_img_update $a_img_update $q_aud_update $a_aud_update WHERE id = $id AND parent_id IN (SELECT id FROM fc_images WHERE category_id = $categoryId)");
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// PERCORSO COMPLETO: RIORDINO E STRUTTURA
// ==========================================
$parent_real_ids = []; 
$custom_order_parent = 1; 

foreach ($cards as $i => $c) {
    if ($c['is_child'] == 1) continue;

    $status = $c['status'];
    $id = intval($c['id']);
    $orig_type = $c['orig_type'];
    
    if ($status == 'deleted' && $id > 0) {
        if ($orig_type == 'parent') mysqli_query($conn, "DELETE FROM fc_images WHERE id = $id AND category_id = $categoryId");
        if ($orig_type == 'child')  mysqli_query($conn, "DELETE FROM fc_child_cards WHERE id = $id AND parent_id IN (SELECT id FROM fc_images WHERE category_id = $categoryId)");
        continue;
    }

    $q = mysqli_real_escape_string($conn, trim($c['q'] ?? ''));
    $a = mysqli_real_escape_string($conn, trim($c['a'] ?? ''));
    
    $q_img_update = ""; $a_img_update = ""; $q_aud_update = ""; $a_aud_update = "";
    $final_q_img = ""; $final_a_img = ""; $final_q_aud = ""; $final_a_aud = "";

    if (isset($_FILES['row_q_img']['name'][$i]) && $_FILES['row_q_img']['error'][$i] == 0) {
        $final_q_img = time() . "_q_edit_$i." . pathinfo($_FILES['row_q_img']['name'][$i], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['row_q_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_q_img);
        $q_img_update = ", question_image = '$final_q_img'";
    }
    if (isset($_FILES['row_a_img']['name'][$i]) && $_FILES['row_a_img']['error'][$i] == 0) {
        $final_a_img = time() . "_a_edit_$i." . pathinfo($_FILES['row_a_img']['name'][$i], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['row_a_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_a_img);
        $a_img_update = ", answer_image = '$final_a_img'";
    }
    if (isset($_FILES['audio_q']['name'][$i]) && $_FILES['audio_q']['error'][$i] == 0) {
        $final_q_aud = "audio_q_" . time() . "_edit_$i." . pathinfo($_FILES['audio_q']['name'][$i], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['audio_q']['tmp_name'][$i], 'fc_audio/' . $final_q_aud);
        $q_aud_update = ", question_audio = '$final_q_aud'";
    }
    if (isset($_FILES['audio_a']['name'][$i]) && $_FILES['audio_a']['error'][$i] == 0) {
        $final_a_aud = "audio_a_" . time() . "_edit_$i." . pathinfo($_FILES['audio_a']['name'][$i], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['audio_a']['tmp_name'][$i], 'fc_audio/' . $final_a_aud);
        $a_aud_update = ", answer_audio = '$final_a_aud'";
    }

    $real_parent_id = 0;

    if ($status == 'new') {
        mysqli_query($conn, "INSERT INTO fc_images (category_id, question_desc, answer_desc, question_image, answer_image, question_audio, answer_audio, is_father, custom_order) VALUES ($categoryId, '$q', '$a', '$final_q_img', '$final_a_img', '$final_q_aud', '$final_a_aud', 0, $custom_order_parent)");
        $real_parent_id = mysqli_insert_id($conn);
    } else {
        if ($orig_type == 'child') {
            mysqli_query($conn, "DELETE FROM fc_child_cards WHERE id = $id AND parent_id IN (SELECT id FROM fc_images WHERE category_id = $categoryId)");
            mysqli_query($conn, "INSERT INTO fc_images (category_id, question_desc, answer_desc, question_image, answer_image, question_audio, answer_audio, is_father, custom_order) VALUES ($categoryId, '$q', '$a', '$final_q_img', '$final_a_img', '$final_q_aud', '$final_a_aud', 0, $custom_order_parent)");
            $real_parent_id = mysqli_insert_id($conn);
        } else {
            mysqli_query($conn, "UPDATE fc_images SET custom_order = $custom_order_parent, question_desc = '$q', answer_desc = '$a' $q_img_update $a_img_update $q_aud_update $a_aud_update WHERE id = $id AND category_id = $categoryId");
            $real_parent_id = $id;
        }
    }
    $parent_real_ids[$i] = $real_parent_id;
    $custom_order_parent++;
}

$custom_order_child = []; 
foreach ($cards as $i => $c) {
    if ($c['is_child'] == 0) continue; 

    $status = $c['status'];
    $id = intval($c['id']);
    $orig_type = $c['orig_type'];
    
    $frontend_parent_idx = intval($c['parent_idx']);
    $real_parent_id = isset($parent_real_ids[$frontend_parent_idx]) ? $parent_real_ids[$frontend_parent_idx] : 0;
    
    if (!isset($custom_order_child[$real_parent_id])) $custom_order_child[$real_parent_id] = 1;

    if ($status == 'deleted' && $id > 0) {
        if ($orig_type == 'parent') mysqli_query($conn, "DELETE FROM fc_images WHERE id = $id AND category_id = $categoryId");
        if ($orig_type == 'child')  mysqli_query($conn, "DELETE FROM fc_child_cards WHERE id = $id AND parent_id IN (SELECT id FROM fc_images WHERE category_id = $categoryId)");
        continue;
    }

    if ($real_parent_id > 0) {
        $q = mysqli_real_escape_string($conn, trim($c['q'] ?? ''));
        $a = mysqli_real_escape_string($conn, trim($c['a'] ?? ''));
        
        $q_img_update = ""; $a_img_update = ""; $q_aud_update = ""; $a_aud_update = "";
        $final_q_img = ""; $final_a_img = ""; $final_q_aud = ""; $final_a_aud = "";

        if (isset($_FILES['row_q_img']['name'][$i]) && $_FILES['row_q_img']['error'][$i] == 0) {
            $final_q_img = time() . "_q_edit_$i." . pathinfo($_FILES['row_q_img']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['row_q_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_q_img);
            $q_img_update = ", question_image = '$final_q_img'";
        }
        if (isset($_FILES['row_a_img']['name'][$i]) && $_FILES['row_a_img']['error'][$i] == 0) {
            $final_a_img = time() . "_a_edit_$i." . pathinfo($_FILES['row_a_img']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['row_a_img']['tmp_name'][$i], FC_IMAGE_PATH_INSERT . $final_a_img);
            $a_img_update = ", answer_image = '$final_a_img'";
        }
        if (isset($_FILES['audio_q']['name'][$i]) && $_FILES['audio_q']['error'][$i] == 0) {
            $final_q_aud = "audio_q_" . time() . "_edit_$i." . pathinfo($_FILES['audio_q']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['audio_q']['tmp_name'][$i], 'fc_audio/' . $final_q_aud);
            $q_aud_update = ", question_audio = '$final_q_aud'";
        }
        if (isset($_FILES['audio_a']['name'][$i]) && $_FILES['audio_a']['error'][$i] == 0) {
            $final_a_aud = "audio_a_" . time() . "_edit_$i." . pathinfo($_FILES['audio_a']['name'][$i], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['audio_a']['tmp_name'][$i], 'fc_audio/' . $final_a_aud);
            $a_aud_update = ", answer_audio = '$final_a_aud'";
        }

        if ($status == 'new') {
            mysqli_query($conn, "INSERT INTO fc_child_cards (parent_id, question_desc, answer_desc, question_image, answer_image, question_audio, answer_audio, custom_order) VALUES ($real_parent_id, '$q', '$a', '$final_q_img', '$final_a_img', '$final_q_aud', '$final_a_aud', {$custom_order_child[$real_parent_id]})");
        } else {
            if ($orig_type == 'parent') {
                mysqli_query($conn, "DELETE FROM fc_images WHERE id = $id AND category_id = $categoryId");
                mysqli_query($conn, "INSERT INTO fc_child_cards (parent_id, question_desc, answer_desc, question_image, answer_image, question_audio, answer_audio, custom_order) VALUES ($real_parent_id, '$q', '$a', '$final_q_img', '$final_a_img', '$final_q_aud', '$final_a_aud', {$custom_order_child[$real_parent_id]})");
            } else {
                mysqli_query($conn, "UPDATE fc_child_cards SET parent_id = $real_parent_id, custom_order = {$custom_order_child[$real_parent_id]}, question_desc = '$q', answer_desc = '$a' $q_img_update $a_img_update $q_aud_update $a_aud_update WHERE id = $id AND parent_id IN (SELECT id FROM fc_images WHERE category_id = $categoryId)");
            }
        }
        mysqli_query($conn, "UPDATE fc_images SET is_father = 1 WHERE id = $real_parent_id");
        $custom_order_child[$real_parent_id]++;
    }
}

echo json_encode(['success' => true]);
?>