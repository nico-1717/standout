<?php
session_start();
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if (isset($_GET['category_id'])) {
    $category_ids_string = mysqli_real_escape_string($conn, $_GET['category_id']);
    $category_name = isset($_GET['category_name']) ? mysqli_real_escape_string($conn, $_GET['category_name']) : 'All';
    
    // Parametri
    $status = isset($_GET['status']) ? $_GET['status'] : '0'; 
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 1; 
    
    // NUOVO PARAMETRO: Ordine
    $order = isset($_GET['order']) && $_GET['order'] == 'in_order' ? 'in_order' : 'random'; 
    
    $currentDate = date('Y-m-d H:i:s');

    $suffix = ($status === '1') ? '_L' : '';
    $game_type = "fc" . $suffix;

    // CALCOLO TOTAL_WORDS PER EVITARE BUG NEL GIOCO (Adattato per fc_images con chiave standardizzata)
    if ($status === 'all') { 
        $where_learnt = "1=1"; 
    } elseif ($status === '1') { 
        $where_learnt = "id IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)"; 
    } else { 
        $where_learnt = "id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)"; 
    }

    $cat_ids = explode(',', $category_ids_string);
    $valid_cats = [];
    foreach($cat_ids as $c) { if(intval($c)>0) $valid_cats[] = intval($c); }
    $cat_filter = count($valid_cats) > 0 ? "AND category_id IN (" . implode(',', $valid_cats) . ")" : "";

    $q_tot_start = mysqli_query($conn, "SELECT COUNT(*) as t FROM fc_images WHERE $where_learnt $cat_filter");
    $total_words_start = mysqli_fetch_assoc($q_tot_start)['t'];
    if ($total_words_start == 0) $total_words_start = 1;

    // Registra la partita - Rimosso 'category_name' da games e aggiornati i campi in game_date e total_father_words
    $query = "INSERT INTO games (game_date, score, type, user_id, total_father_words) 
              VALUES ('$currentDate', 0, '$game_type', $user_id, $total_words_start)";
    mysqli_query($conn, $query);
    $new_game_id = mysqli_insert_id($conn);

    // CRONOMETRO: Aggiornato alla tabella session_durations con colonne start_time e updated_at
    mysqli_query($conn, "INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at) VALUES ($new_game_id, NOW(), 0, NOW())");

    // Registra i collegamenti multipli nella nuova tabella ponte game_fc_categories
    if (count($valid_cats) > 0) {
        foreach ($valid_cats as $cid) {
            mysqli_query($conn, "INSERT INTO game_fc_categories (game_id, fc_category_id) VALUES ($new_game_id, $cid)");
        }
    }

    mysqli_close($conn);

    // Reindirizza al gioco delle materie passando il parametro "order"
    header("Location: game_fc.php?game_id=$new_game_id&position=1&status=$status&order=$order");
    exit();
}

header("Location: categories_game_fc.php");
exit();
?>
