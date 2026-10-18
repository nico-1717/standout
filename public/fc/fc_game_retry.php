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
 *
 * 5. Studio e lingua passano entrambi da fc_game.php: le pagine del vecchio
 *    vocabolario (game_flashcards*.php, game_start.php) non esistono piu', e
 *    anche il ripasso di una vecchia partita di parole ora si gioca qui. Il
 *    tipo (studio o lingua, e il corso) lo eredita dalla partita di origine.
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

/* Limite di frequenza: crea una partita nuova. Raggiunta con GET, quindi il
   token CSRF non si applica (vedi note). */
sec_guard_write($current_user_id, 'fc_retry', 0, 60, 60);

$parent_id = sec_int($_GET['parent_id'] ?? 0, 1);

/* La partita di origine dev'essere sua. */
$parent = $parent_id > 0
    ? sec_one("SELECT * FROM fc_games WHERE id = ? AND user_id = ?", [$parent_id, $current_user_id])
    : null;

if (!$parent) {
    header('Location: fc_history.php');
    exit();
}

require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia_da_gioco($parent);

$type = (string)$parent['type'];
/* Una vecchia partita di parole ("en_it", "quiz"...) diventa una partita di
   adesso con lo stesso verso: fc_game.php capisce solo i nomi nuovi. */
if (fc_is_vocab()) {
    $type = fc_verso_da_tipo($type) . (strpos($type, '_L') !== false ? '_L' : '');
}

/* Il ripasso eredita lo stato delle carte della partita di origine: non
   cambia quali carte escono (sono quelle sbagliate), ma lo storico deve
   poterlo mostrare e fc_game.php lo usa al posto dell'indirizzo. */
$card_status = in_array($parent['card_status'] ?? null, ['all', '1', '0'], true) ? $parent['card_status'] : null;

/* Un padre per ogni posizione con almeno una carta sbagliata, anche se
   l'errore era su un figlio: la posizione si rigioca intera. */
$sql_sbagliati = "SELECT DISTINCT fc_image_id FROM " . fc_t('plays') . "
                  WHERE game_id = ? AND fc_image_id IS NOT NULL
                    AND position IN (SELECT DISTINCT position FROM " . fc_t('plays') . " WHERE game_id = ? AND user_answer = 'Wrong')";
$ids_sbagliati = array_map('intval', array_column(sec_all($sql_sbagliati, [$parent_id, $parent_id]), 'fc_image_id'));

if (empty($ids_sbagliati)) {
    header('Location: fc_result.php?game_id=' . (int)$parent_id);
    exit();
}

$tot_padri = count($ids_sbagliati);
$figli = (int)(sec_one("SELECT COUNT(*) AS n FROM " . fc_t('subcards') . " ch
                        JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id AND i.is_father = 1
                        WHERE ch.parent_id IN ($sql_sbagliati)",
                       [$parent_id, $parent_id])['n'] ?? 0);
$tot_carte = $tot_padri + $figli;

/* Il ripasso si mescola sempre (shuffle sotto): game_order lo dice. */
sec_q("INSERT INTO fc_games (game_date, score, type, kind, course_id, card_status, game_order, user_id, parent_id,
                          total_father_words, total_words_with_children)
       VALUES (NOW(), 0, ?, ?, ?, ?, 'random', ?, ?, ?, ?)",
      [$type, fc_tipo(), fc_is_vocab() ? fc_corso_id() : null, $card_status, $current_user_id, $parent_id, $tot_padri, $tot_carte]);
$new_game_id = sec_insert_id();

sec_q("INSERT INTO fc_game_durations (game_id, start_time, duration_seconds, updated_at)
       VALUES (?, NOW(), 0, NOW())", [$new_game_id]);

/* Le categorie si copiano dalla partita di origine. */
sec_q("INSERT INTO " . fc_t('game_decks') . " (game_id, fc_category_id)
       SELECT ?, fc_category_id FROM " . fc_t('game_decks') . " WHERE game_id = ?", [$new_game_id, $parent_id]);

shuffle($ids_sbagliati);
$righe = [];
foreach ($ids_sbagliati as $i => $pid) {
    $righe[] = [$new_game_id, $i + 1, $pid];
}
foreach (array_chunk($righe, 300) as $blocco) {
    $valori = [];
    $ph = implode(',', array_fill(0, count($blocco), '(?, ?, ?)'));
    foreach ($blocco as $r) { $valori = array_merge($valori, $r); }
    sec_q("INSERT INTO " . fc_t('sequence') . " (game_id, position, fc_image_id) VALUES $ph", $valori);
}

$status = $card_status ?? ((strpos($type, '_L') !== false) ? '1' : '0');

header("Location: fc_game.php?game_id=" . (int)$new_game_id . "&position=1&status=" . urlencode($status));
exit();
