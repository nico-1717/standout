<?php
/* Riprova una partita: ne crea una nuova con le sole schede sbagliate.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso, e nessuna verifica che la partita fosse
 *    dell'utente: bastava cambiare parent_id nell'indirizzo per creare una
 *    partita a partire da quella di un altro, e leggerne le schede.
 *
 * 2. $user_id veniva copiato dalla partita di origine, quindi la nuova nasceva
 *    intestata a quell'utente. Adesso e' sempre quello della sessione, che con
 *    il controllo di proprieta' qui sopra e' la stessa persona.
 *
 * 3. Query costruite per concatenazione, incluso il tipo di partita che veniva
 *    riletto dal database e reincollato in un'altra query.
 */
require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

/* Limite di frequenza: crea una partita nuova. Raggiunta con GET, quindi il
   token CSRF non si applica (vedi note). */
sec_guard_write($current_user_id, 'fc_retry', 0, 60, 60);

$parent_id = sec_int($_GET['parent_id'] ?? 0, 1);

/* La partita di origine dev'essere sua. */
$parent = $parent_id > 0
    ? sec_one("SELECT * FROM games WHERE id = ? AND user_id = ?", [$parent_id, $current_user_id])
    : null;

if (!$parent) {
    header('Location: history_flashcards.php');
    exit();
}

$type = (string)$parent['type'];

/* Quante schede erano sbagliate: e' il totale della partita nuova. */
$total_words_retry = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_gameplays
                                    WHERE game_id = ? AND user_answer = 'Wrong'", [$parent_id])['c'] ?? 0);
if ($total_words_retry === 0) $total_words_retry = 1;

sec_q("INSERT INTO games (game_date, score, type, user_id, parent_id, total_father_words, total_words_with_children)
       VALUES (NOW(), 0, ?, ?, ?, ?, ?)",
      [$type, $current_user_id, $parent_id, $total_words_retry, $total_words_retry]);
$new_game_id = sec_insert_id();

sec_q("INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at)
       VALUES (?, NOW(), 0, NOW())", [$new_game_id]);

/* Le categorie si copiano dalla partita di origine. */
sec_q("INSERT INTO game_fc_categories (game_id, fc_category_id)
       SELECT ?, fc_category_id FROM game_fc_categories WHERE game_id = ?", [$new_game_id, $parent_id]);

$status = (strpos($type, '_L') !== false) ? 1 : 0;

/* Dove mandare l'utente, in base al tipo di partita. */
if (strpos($type, 'fc') !== false) {
    $pagina = 'game_fc.php';
} elseif ($type === 'quiz') {
    $pagina = 'game_start.php';
} elseif (strpos($type, 'en_it') !== false) {
    $pagina = 'game_flashcards_en_it.php';
} else {
    $pagina = 'game_flashcards.php';
}

$qs = "?game_id=" . (int)$new_game_id . "&position=1";
if ($pagina !== 'game_start.php') $qs .= "&status=" . (int)$status;

header("Location: $pagina$qs");
exit();
