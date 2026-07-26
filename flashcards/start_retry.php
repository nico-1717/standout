<?php
session_start();
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if (isset($_GET['parent_id'])) {
    $parent_id = intval($_GET['parent_id']);
    
    // Recupera i dati della partita originale inserendo la chiave corretta id
    $q_parent = mysqli_query($conn, "SELECT * FROM games WHERE id = $parent_id");
    $parent = mysqli_fetch_assoc($q_parent);
    
    if($parent) {
        $currentDate = date('Y-m-d H:i:s');
        $type = $parent['type'];
        
        $user_id = isset($parent['user_id']) ? intval($parent['user_id']) : 1; 
        
        // Il total_words della nuova partita sarà basato sulla nuova fc_gameplays
        $q_wrong = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_gameplays WHERE game_id = $parent_id AND user_answer = 'Wrong'");
        $total_words_retry = mysqli_fetch_assoc($q_wrong)['c'];
        if ($total_words_retry == 0) $total_words_retry = 1;

        // Aggiornate le colonne in game_date, score, total_father_words e total_words_with_children
        $query = "INSERT INTO games (game_date, score, type, user_id, parent_id, total_father_words, total_words_with_children) 
                  VALUES ('$currentDate', 0, '$type', $user_id, $parent_id, $total_words_retry, $total_words_retry)";
        
        if (!mysqli_query($conn, $query)) {
            die("Errore Database (Impossibile creare la partita): " . mysqli_error($conn));
        }

        $new_game_id = mysqli_insert_id($conn);

        // CRONOMETRO: Aggiornato a session_durations
        mysqli_query($conn, "INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at) VALUES ($new_game_id, NOW(), 0, NOW())");
        
        // Copiamo anche le categorie nella tabella ponte game_fc_categories
        mysqli_query($conn, "INSERT INTO game_fc_categories (game_id, fc_category_id) 
                             SELECT $new_game_id, fc_category_id FROM game_fc_categories WHERE game_id = $parent_id");
        
        $status = (strpos($type, '_L') !== false) ? 1 : 0;
        mysqli_close($conn);

        // SMISTAMENTO INTELLIGENTE
        if (strpos($type, 'fc') !== false) {
            header("Location: game_fc.php?game_id=$new_game_id&position=1&status=$status");
        } else if ($type == 'quiz') {
            header("Location: game_start.php?game_id=$new_game_id&position=1");
        } else if (strpos($type, 'en_it') !== false) {
            header("Location: game_flashcards_en_it.php?game_id=$new_game_id&position=1&status=$status");
        } else {
            header("Location: game_flashcards.php?game_id=$new_game_id&position=1&status=$status");
        }
        exit();
    }
}
header("Location: history_flashcards.php");
exit();
?>