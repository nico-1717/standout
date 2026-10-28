<?php
/* Quante schede ci sono per ogni categoria, secondo il filtro imparate/da
 * imparare. Serve a mostrare i numeri accanto alle categorie.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso, e $user_id preso dalla querystring con 1 come
 *    ripiego: bastava cambiare un numero per leggere quante schede ha un altro
 *    utente, e in quali categorie.
 *
 * 2. I conteggi non erano filtrati per utente: "SELECT COUNT(*) FROM fc_study_cards"
 *    contava le schede di tutti, e l'elenco per categoria le esponeva una a una.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

header('Content-Type: application/json');

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($current_user_id, true);

$status = sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0');

/* Le tre forme della clausola sono scritte per esteso: prima "$where" veniva
   composto e incollato nella query. */
$par = [];
/* una carta conta se almeno una sua parte (padre o figlio) corrisponde al filtro */
$where = fc_sql_stato_imparata('i', $status, (int)$current_user_id);

/* La JOIN su fc_study_decks con user_id e' quello che mancava: senza, i
   conteggi comprendevano le schede di tutti gli utenti. */
$data = [];
[$fcW, $fcP] = fc_filtro_corso('c');

$data['all'] = (int)(sec_one(
    "SELECT COUNT(*) AS total FROM " . fc_t('cards') . " i
     JOIN " . fc_t('decks') . " c ON c.id = i.category_id
     WHERE $where AND c.user_id = ?" . $fcW, array_merge($par, [$current_user_id], $fcP))['total'] ?? 0);

foreach (sec_all(
    "SELECT i.category_id, COUNT(*) AS c FROM " . fc_t('cards') . " i
     JOIN " . fc_t('decks') . " c ON c.id = i.category_id
     WHERE $where AND c.user_id = ?" . $fcW . "
     GROUP BY i.category_id", array_merge($par, [$current_user_id], $fcP)) as $row) {
    if ($row['category_id'] !== null) {
        $data['cat_' . (int)$row['category_id']] = (int)$row['c'];
    }
}

echo json_encode($data);
