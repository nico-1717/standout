<?php
/* fc_sequence_backfill.php - ricostruisce la sequenza delle partite vecchie.
 *
 * PERCHE' SERVE
 * Le partite di flashcard nate prima di fc_study_sequence non hanno l'elenco
 * dei padri: fc_game.php non sa quali carte dare dopo l'ultima giocata, e
 * quindi manda ai risultati invece di continuare. Questo script ricostruisce
 * l'elenco e le rende di nuovo riprendibili.
 *
 * COME
 * Le posizioni gia' giocate si leggono da fc_study_plays: lo storico e'
 * completo, quindi restano in ordine esatto. Le posizioni mai aperte non
 * hanno lasciato traccia da nessuna parte: si riempiono con le carte delle
 * categorie della partita che non sono state ancora viste, prendendo lo
 * stato imparata/da imparare di ADESSO. Una carta imparata dopo quella
 * partita quindi non tornera' in una partita "da imparare" - e' il
 * comportamento sensato, ma va detto: non e' la sequenza originale, e'
 * la sequenza migliore che si puo' ricostruire.
 *
 * COME SI USA
 * Si apre da connessi: http://.../fc_sequence_backfill.php?prova=1 mostra
 * cosa farebbe senza scrivere niente. Senza ?prova=1 scrive davvero.
 * Si puo' rilanciare: le partite che hanno gia' una sequenza vengono
 * saltate, quindi una seconda esecuzione non duplica nulla.
 * A lavoro finito il file si puo' cancellare dal sito.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

$prova = isset($_GET['prova']);

header('Content-Type: text/plain; charset=utf-8');

/* Partite di flashcard dell'utente senza sequenza, ma con almeno una carta
   giocata: quelle senza nemmeno una risposta le elimina lo storico. */
$partite = sec_all(
    "SELECT g.id, g.type, g.card_status, g.game_order, g.parent_id,
            COUNT(DISTINCT gp.position) AS posizioni
       FROM fc_games g
       JOIN fc_study_plays gp ON gp.game_id = g.id
      WHERE g.user_id = ? AND g.kind = 'study'
        AND NOT EXISTS (SELECT 1 FROM fc_study_sequence s WHERE s.game_id = g.id)
      GROUP BY g.id
      ORDER BY g.game_date", [$current_user_id]);

echo ($prova ? "PROVA (non scrivo niente)\n" : "ESECUZIONE\n");
echo "Partite senza sequenza: " . count($partite) . "\n\n";

$fatte = 0;
$aggiunte_tot = 0;

foreach ($partite as $p) {
    $gid = (int)$p['id'];

    /* Le posizioni gia' giocate, con il loro padre. Il padre di una riga
       figlio si risale da fc_study_subcards, perche' la sequenza contiene solo
       padri. */
    $vecchie = [];
    foreach (sec_all(
        "SELECT gp.position, COALESCE(gp.fc_image_id, ch.parent_id) AS pid
           FROM fc_study_plays gp
           LEFT JOIN fc_study_subcards ch ON ch.id_child = gp.fc_child_id
          WHERE gp.game_id = ? AND COALESCE(gp.fc_image_id, ch.parent_id) IS NOT NULL
          ORDER BY gp.position", [$gid]) as $r) {
        $pos = (int)$r['position'];
        if (!isset($vecchie[$pos])) $vecchie[$pos] = (int)$r['pid'];
    }
    if (empty($vecchie)) continue;

    ksort($vecchie);
    $righe = [];
    $viste = [];
    $posizione = 0;
    foreach ($vecchie as $pid) {
        $posizione++;
        $righe[] = [$gid, $posizione, $pid];
        $viste[$pid] = true;
    }

    /* Il resto: le carte ancora da vedere delle categorie della partita.
       I ripassi non si allungano - le loro carte sono quelle sbagliate
       nella partita di origine, e non ha senso aggiungerne altre. */
    $aggiunte = 0;
    if ((int)$p['parent_id'] === 0) {
        $cats = array_map('intval', array_column(
            sec_all("SELECT fc_category_id FROM fc_study_game_decks WHERE game_id = ? ORDER BY category_order", [$gid]),
            'fc_category_id'));

        if (!empty($cats)) {
            $stato = in_array($p['card_status'], ['all', '0', '1'], true)
                ? $p['card_status']
                : (strpos((string)$p['type'], '_L') !== false ? '1' : '0');

            $par = [];
            if ($stato === '1') {
                $where = 'i.id IN (SELECT fc_image_id FROM fc_study_learnt WHERE user_id = ? AND is_child = 0)';
                $par[] = $current_user_id;
            } elseif ($stato === '0') {
                $where = 'i.id NOT IN (SELECT fc_image_id FROM fc_study_learnt WHERE user_id = ? AND is_child = 0)';
                $par[] = $current_user_id;
            } else {
                $where = '1=1';
            }

            $ph = implode(',', array_fill(0, count($cats), '?'));
            $sql = "SELECT i.id, i.category_id, i.custom_order
                      FROM fc_study_cards i
                      JOIN fc_study_decks c ON c.id = i.category_id
                     WHERE $where AND c.user_id = ? AND i.category_id IN ($ph)";
            $par[] = $current_user_id;
            $par = array_merge($par, $cats);

            $resto = [];
            foreach (sec_all($sql, $par) as $s) {
                if (!isset($viste[(int)$s['id']])) $resto[] = $s;
            }

            if ($p['game_order'] === 'in_order') {
                $pos_cat = array_flip($cats);
                usort($resto, function ($a, $b) use ($pos_cat) {
                    $ca = $pos_cat[(int)$a['category_id']] ?? PHP_INT_MAX;
                    $cb = $pos_cat[(int)$b['category_id']] ?? PHP_INT_MAX;
                    if ($ca !== $cb) return $ca <=> $cb;
                    return ((int)$a['custom_order']) <=> ((int)$b['custom_order']);
                });
            } else {
                shuffle($resto);
            }

            foreach ($resto as $s) {
                $posizione++;
                $righe[] = [$gid, $posizione, (int)$s['id']];
                $aggiunte++;
            }
        }
    }

    echo "Partita $gid: " . count($vecchie) . " giocate + $aggiunte da giocare = " . count($righe) . "\n";
    $fatte++;
    $aggiunte_tot += $aggiunte;

    if ($prova) continue;

    foreach (array_chunk($righe, 300) as $blocco) {
        $valori = [];
        $ph_riga = implode(',', array_fill(0, count($blocco), '(?, ?, ?)'));
        foreach ($blocco as $r) { $valori = array_merge($valori, $r); }
        sec_q("INSERT INTO fc_study_sequence (game_id, position, fc_image_id) VALUES $ph_riga", $valori);
    }

    /* Il totale della partita e' ora la sequenza, come per le partite nuove. */
    sec_q("UPDATE fc_games SET total_father_words = ? WHERE id = ? AND user_id = ?",
          [count($righe), $gid, $current_user_id]);
}

echo "\nPartite sistemate: $fatte\n";
echo "Carte rese di nuovo giocabili: $aggiunte_tot\n";
if ($prova) echo "\nNiente e' stato scritto. Togli ?prova=1 per applicare.\n";
