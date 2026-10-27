<?php
/* Segna una scheda come imparata o non imparata.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso. Il file includeva solo config.php: chiunque,
 *    anche senza essere connesso, poteva chiamarlo.
 *
 * 2. $user_id arrivava dal POST, con 1 come ripiego. Bastava mandare un
 *    user_id qualsiasi per scrivere nello storico di un altro utente - e con
 *    il ripiego a 1, una chiamata senza parametri finiva sull'utente numero 1.
 *    Ora l'utente e' quello della sessione e basta: non e' un dato che il
 *    client possa fornire.
 *
 * 3. Nessuna verifica che la scheda fosse sua, e query per concatenazione.
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

header('Content-Type: application/json');

$current_user_id = (int)$utente_corrente['id'];

/* Token di sessione, origine e limite di frequenza: si spuntano molte schede di
   fila, quindi il limite sta largo. */
sec_guard_write($current_user_id, 'fc_learnt', 0, 300, 60);

/* Studio o lingua lo dice l'indirizzo (?course=N), come in ogni pagina delle
   flashcard. Il vecchio parametro "type" (en/fc) non serve piu': le due
   strade sono diventate la stessa, su tabelle diverse. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($current_user_id, true);

$id     = sec_int($_POST['id'] ?? 0, 1);
$status = sec_bool($_POST['status'] ?? 0) ? 1 : 0;
/* Padre e figlio sono due carte con due stati: is_child=1 vuol dire che $id e'
   un id_child (fc_*_subcards), altrimenti un id di fc_*_cards. */
$figlio = sec_bool($_POST['is_child'] ?? 0) ? 1 : 0;

if ($id <= 0) { echo json_encode(['success' => false]); exit; }

/* La scheda dev'essere sua: si passa dalla categoria (per un figlio, da quella
   del suo padre). Senza questo controllo si poteva riempire il proprio storico
   con le schede altrui. */
if ($figlio) {
    $mia = sec_one("SELECT s.id_child FROM " . fc_t('subcards') . " s
                    JOIN " . fc_t('cards') . " i ON i.id = s.parent_id
                    JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                    WHERE s.id_child = ? AND c.user_id = ?", [$id, $current_user_id]);
} else {
    $mia = sec_one("SELECT i.id FROM " . fc_t('cards') . " i
                    JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                    WHERE i.id = ? AND c.user_id = ?", [$id, $current_user_id]);
}
if (!$mia) { echo json_encode(['success' => false]); exit; }

if ($status === 1) {
    sec_q("INSERT IGNORE INTO " . fc_t('learnt') . " (user_id, fc_image_id, is_child, learnt_date) VALUES (?, ?, ?, CURDATE())",
          [$current_user_id, $id, $figlio]);
} else {
    sec_q("DELETE FROM " . fc_t('learnt') . " WHERE user_id = ? AND fc_image_id = ? AND is_child = ?", [$current_user_id, $id, $figlio]);
}

echo json_encode(['success' => true]);
