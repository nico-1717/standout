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
    $sue = [];
    foreach (sec_all("SELECT id FROM fc_categories WHERE id IN ($ph) AND user_id = ?",
                     array_merge($chieste, [$current_user_id])) as $r) {
        $sue[(int)$r['id']] = true;
    }
    /* Si rispetta l'ordine CHIESTO, non quello in cui il database restituisce
       le righe: e' l'ordine che l'utente ha scelto nella lista, ed e' quello
       che decide in che sequenza usciranno le carte. */
    foreach ($chieste as $n) {
        if (isset($sue[$n])) $valid_cats[] = $n;
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

foreach ($valid_cats as $i => $cid) {
    sec_q("INSERT INTO game_fc_categories (game_id, fc_category_id, category_order) VALUES (?, ?, ?)",
          [$new_game_id, $cid, $i]);
}

/* ---------------------------------------------------------------------
   La sequenza della partita si decide QUI, una volta sola, e si scrive
   tutta insieme in fc_gameplays.

   Prima ogni posizione sceglieva la sua carta al momento della prima
   visita: visitare una posizione era anche l'atto che la creava. Cosi'
   era impossibile precaricare la carta successiva senza crearla in
   anticipo, e due richieste vicine (il precaricamento e il voto che vi
   rimanda) finivano per generarla due volte - la stessa domanda mostrata
   due volte. Fissando la sequenza adesso, visitare una posizione diventa
   una pura lettura, e "random" viene estratto una volta sola invece che
   ripescato a ogni pagina.

   Le partite di ripasso (quelle con parent_id, create dai risultati) non
   passano di qui: continuano a generare le carte man mano, come prima.
   --------------------------------------------------------------------- */
$par_sel = [];
if ($status === '1') {
    $where_sel = 'i.id IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = ?)';
    $par_sel[] = $current_user_id;
} elseif ($status === '0') {
    $where_sel = 'i.id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = ?)';
    $par_sel[] = $current_user_id;
} else {
    $where_sel = '1=1';
}

$sql_sel = "SELECT i.id, i.answer_desc, i.is_father, i.category_id, i.custom_order
            FROM fc_images i
            JOIN fc_categories c ON c.id = i.category_id
            WHERE $where_sel AND c.user_id = ?";
$par_sel[] = $current_user_id;

if (!empty($valid_cats)) {
    $ph_sel = implode(',', array_fill(0, count($valid_cats), '?'));
    $sql_sel .= " AND i.category_id IN ($ph_sel)";
    $par_sel = array_merge($par_sel, $valid_cats);
}

$schede = sec_all($sql_sel, $par_sel);

if (!empty($schede)) {
    if ($order === 'random') {
        shuffle($schede);
    } else {
        /* In ordine: prima la categoria (secondo la scelta dell'utente),
           poi l'ordine personalizzato della scheda dentro la categoria. */
        $pos_cat = array_flip($valid_cats);
        usort($schede, function ($a, $b) use ($pos_cat) {
            $ca = $pos_cat[(int)$a['category_id']] ?? PHP_INT_MAX;
            $cb = $pos_cat[(int)$b['category_id']] ?? PHP_INT_MAX;
            if ($ca !== $cb) return $ca <=> $cb;
            return ((int)$a['custom_order']) <=> ((int)$b['custom_order']);
        });
    }

    /* I figli di tutti i padri in una volta sola: una query, non una per
       scheda. Stanno nella stessa posizione del loro padre. */
    $figli_per_padre = [];
    $ids_padri = array_map(function ($s) { return (int)$s['id']; }, $schede);
    $ph_f = implode(',', array_fill(0, count($ids_padri), '?'));
    foreach (sec_all("SELECT id_child, parent_id, answer_desc FROM fc_child_cards
                      WHERE parent_id IN ($ph_f) ORDER BY custom_order ASC", $ids_padri) as $f) {
        $figli_per_padre[(int)$f['parent_id']][] = $f;
    }

    /* Scritte a blocchi: una INSERT per riga su una partita lunga sarebbe
       lentissima proprio nel momento in cui l'utente sta aspettando. */
    $righe = [];
    $posizione = 0;
    foreach ($schede as $s) {
        $posizione++;
        $pid = (int)$s['id'];
        $righe[] = [$new_game_id, $pid, null, mb_substr((string)$s['answer_desc'], 0, 100, 'UTF-8'), '', $posizione];

        if ((int)$s['is_father'] === 1 && !empty($figli_per_padre[$pid])) {
            foreach ($figli_per_padre[$pid] as $f) {
                $righe[] = [$new_game_id, null, (int)$f['id_child'], mb_substr((string)$f['answer_desc'], 0, 100, 'UTF-8'), '', $posizione];
            }
        }
    }

    foreach (array_chunk($righe, 100) as $blocco) {
        $valori = [];
        $ph_riga = implode(',', array_fill(0, count($blocco), '(?, ?, ?, ?, ?, ?)'));
        foreach ($blocco as $r) { $valori = array_merge($valori, $r); }
        sec_q("INSERT INTO fc_gameplays (game_id, fc_image_id, fc_child_id, correct_answer, user_answer, position)
               VALUES $ph_riga", $valori);
    }
}

header("Location: game_fc.php?game_id=" . (int)$new_game_id
       . "&position=1&status=" . urlencode($status) . "&order=" . urlencode($order));
exit();
