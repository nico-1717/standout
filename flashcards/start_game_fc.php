<?php
/* Avvia una partita di flashcard sulle categorie scelte.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso: il file includeva solo config.php, quindi
 *    chiunque poteva chiamarlo anche senza essere connesso.
 *
 * 2. $user_id arrivava dalla querystring, con 1 come ripiego: bastava cambiare
 *    un numero nell'indirizzo per creare partite a nome di un altro utente.
 *    Adesso l'utente e' quello della sessione, e basta.
 *
 * 3. Le categorie arrivavano dalla querystring e finivano nella query senza
 *    verificare che fossero dell'utente: si poteva giocare - e quindi leggere -
 *    le schede di un altro. Ora si tengono solo quelle sue.
 */
require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

/* Limite di frequenza: crea partite, quindi righe nel database. La pagina si
   raggiunge con un GET, quindi il token CSRF non si applica (vedi note). */
sec_guard_write($current_user_id, 'fc_start', 0, 60, 60);

if (!isset($_GET['category_id'])) {
    header('Location: categories_game_fc.php');
    exit();
}

/* 'all' = tutte, '1' = solo imparate, altro = solo da imparare. */
$status = sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0');
$order  = sec_enum($_GET['order'] ?? null, ['in_order', 'random'], 'random');

/* Le categorie chieste, tenute solo se sono davvero sue. */
$chieste = [];
foreach (explode(',', (string)$_GET['category_id']) as $c) {
    $n = sec_int($c, 1);
    if ($n > 0) $chieste[] = $n;
}
$chieste = array_values(array_unique($chieste));

$valid_cats = [];
if (!empty($chieste)) {
    $ph = implode(',', array_fill(0, count($chieste), '?'));
    foreach (sec_all("SELECT id FROM fc_categories WHERE id IN ($ph) AND user_id = ?",
                     array_merge($chieste, [$current_user_id])) as $r) {
        $valid_cats[] = (int)$r['id'];
    }
}

$game_type = 'fc' . ($status === '1' ? '_L' : '');

/* Quante schede entrano nella partita. Le tre forme della clausola sono scritte
   per esteso: "$where_learnt" incollato nella query era il punto in cui prima
   entravano valori costruiti a mano. */
$par = [];
if ($status === 'all') {
    $where_learnt = '1=1';
} elseif ($status === '1') {
    $where_learnt = 'i.id IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = ?)';
    $par[] = $current_user_id;
} else {
    $where_learnt = 'i.id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = ?)';
    $par[] = $current_user_id;
}

/* La JOIN su fc_categories con user_id e' la garanzia che si contino solo le
   proprie schede, anche se l'elenco delle categorie fosse vuoto. */
$sql = "SELECT COUNT(*) AS t FROM fc_images i
        JOIN fc_categories c ON c.id = i.category_id
        WHERE $where_learnt AND c.user_id = ?";
$par[] = $current_user_id;
if (!empty($valid_cats)) {
    $ph = implode(',', array_fill(0, count($valid_cats), '?'));
    $sql .= " AND i.category_id IN ($ph)";
    $par = array_merge($par, $valid_cats);
}
$total_words_start = (int)(sec_one($sql, $par)['t'] ?? 0);
if ($total_words_start === 0) $total_words_start = 1;

sec_q("INSERT INTO games (game_date, score, type, user_id, total_father_words)
       VALUES (NOW(), 0, ?, ?, ?)", [$game_type, $current_user_id, $total_words_start]);
$new_game_id = sec_insert_id();

sec_q("INSERT INTO session_durations (game_id, start_time, duration_seconds, updated_at)
       VALUES (?, NOW(), 0, NOW())", [$new_game_id]);

foreach ($valid_cats as $cid) {
    sec_q("INSERT INTO game_fc_categories (game_id, fc_category_id) VALUES (?, ?)", [$new_game_id, $cid]);
}

header("Location: game_fc.php?game_id=" . (int)$new_game_id
       . "&position=1&status=" . urlencode($status) . "&order=" . urlencode($order));
exit();
