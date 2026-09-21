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
 *
 * 4. Il totale del ripasso contava le RIGHE sbagliate (un padre con tre figli
 *    sbagliati valeva tre), mentre la sequenza ha una posizione per padre: la
 *    barra non arrivava mai in fondo. Ora per le flashcard il totale e' la
 *    sequenza, e un ripasso senza errori non viene creato.
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
    header('Location: history_fc.php');
    exit();
}

$type = (string)$parent['type'];

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

/* Il ripasso eredita lo stato delle carte della partita di origine: non
   cambia quali carte escono (sono quelle sbagliate), ma lo storico deve
   poterlo mostrare e game_fc.php lo usa al posto dell'indirizzo. */
$card_status = in_array($parent['card_status'] ?? null, ['all', '1', '0'], true) ? $parent['card_status'] : null;

$ids_sbagliati = [];
if ($pagina === 'game_fc.php') {
    /* Un padre per ogni posizione con almeno una carta sbagliata, anche se
       l'errore era su un figlio: la posizione si rigioca intera. */
    $sql_sbagliati = "SELECT DISTINCT fc_image_id FROM fc_gameplays
                      WHERE game_id = ? AND fc_image_id IS NOT NULL
                        AND position IN (SELECT DISTINCT position FROM fc_gameplays WHERE game_id = ? AND user_answer = 'Wrong')";
    $ids_sbagliati = array_map('intval', array_column(sec_all($sql_sbagliati, [$parent_id, $parent_id]), 'fc_image_id'));

    if (empty($ids_sbagliati)) {
        header('Location: result_fc.php?game_id=' . (int)$parent_id);
        exit();
    }

    $tot_padri = count($ids_sbagliati);
    $figli = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_child_cards ch
                            JOIN fc_images i ON i.id = ch.parent_id AND i.is_father = 1
                            WHERE ch.parent_id IN ($sql_sbagliati)",
                           [$parent_id, $parent_id])['n'] ?? 0);
    $tot_carte = $tot_padri + $figli;
} else {
    /* Gli altri giochi non hanno sequenza: resta il conteggio di prima. */
    $tot_padri = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_gameplays
                                WHERE game_id = ? AND user_answer = 'Wrong'", [$parent_id])['c'] ?? 0);
    if ($tot_padri === 0) $tot_padri = 1;
    $tot_carte = $tot_padri;
}

/* Il ripasso si mescola sempre (shuffle sotto): game_order lo dice. */
sec_q("INSERT INTO games (game_date, score, type, card_status, game_order, user_id, parent_id,
                          total_father_words, total_words_with_children)
       VALUES (NOW(), 0, ?, ?, 'random', ?, ?, ?, ?)",
      [$type, $card_status, $current_user_id, $parent_id, $tot_padri, $tot_carte]);
$new_game_id = sec_insert_id();

sec_q("INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at)
       VALUES (?, NOW(), 0, NOW())", [$new_game_id]);

/* Le categorie si copiano dalla partita di origine. */
sec_q("INSERT INTO game_fc_categories (game_id, fc_category_id)
       SELECT ?, fc_category_id FROM game_fc_categories WHERE game_id = ?", [$new_game_id, $parent_id]);

if ($pagina === 'game_fc.php') {
    shuffle($ids_sbagliati);
    $righe = [];
    foreach ($ids_sbagliati as $i => $pid) {
        $righe[] = [$new_game_id, $i + 1, $pid];
    }
    foreach (array_chunk($righe, 300) as $blocco) {
        $valori = [];
        $ph = implode(',', array_fill(0, count($blocco), '(?, ?, ?)'));
        foreach ($blocco as $r) { $valori = array_merge($valori, $r); }
        sec_q("INSERT INTO fc_game_sequence (game_id, position, fc_image_id) VALUES $ph", $valori);
    }
}

$status = $card_status ?? ((strpos($type, '_L') !== false) ? '1' : '0');

$qs = "?game_id=" . (int)$new_game_id . "&position=1";
if ($pagina !== 'game_start.php') $qs .= "&status=" . urlencode($status);

header("Location: $pagina$qs");
exit();
