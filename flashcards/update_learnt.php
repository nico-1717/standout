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
require_once 'config.php';
require_once 'auth_check_api.php';

header('Content-Type: application/json');

$current_user_id = (int)$utente_corrente['id'];

/* Token di sessione, origine e limite di frequenza: si spuntano molte schede di
   fila, quindi il limite sta largo. */
sec_guard_write($current_user_id, 'fc_learnt', 0, 300, 60);

$id     = sec_int($_POST['id'] ?? 0, 1);
$status = sec_bool($_POST['status'] ?? 0) ? 1 : 0;
$type   = sec_enum($_POST['type'] ?? null, ['en', 'fc'], 'en');

if ($id <= 0) { echo json_encode(['success' => false]); exit; }

if ($type === 'en') {
    /* La scheda dev'essere sua: si passa dalla categoria. Senza questo
       controllo si poteva riempire il proprio storico con le schede altrui. */
    $mia = sec_one("SELECT i.id FROM images i
                    JOIN categories c ON c.id = i.category_id
                    WHERE i.id = ? AND c.user_id = ?", [$id, $current_user_id]);
    if (!$mia) { echo json_encode(['success' => false]); exit; }

    if ($status === 1) {
        sec_q("INSERT IGNORE INTO user_learnt_english (user_id, image_id, learnt_date) VALUES (?, ?, CURDATE())",
              [$current_user_id, $id]);
    } else {
        sec_q("DELETE FROM user_learnt_english WHERE user_id = ? AND image_id = ?", [$current_user_id, $id]);
    }
} else {
    $mia = sec_one("SELECT i.id FROM fc_images i
                    JOIN fc_categories c ON c.id = i.category_id
                    WHERE i.id = ? AND c.user_id = ?", [$id, $current_user_id]);
    if (!$mia) { echo json_encode(['success' => false]); exit; }

    if ($status === 1) {
        sec_q("INSERT IGNORE INTO user_learnt_fc (user_id, fc_image_id, learnt_date) VALUES (?, ?, CURDATE())",
              [$current_user_id, $id]);
    } else {
        sec_q("DELETE FROM user_learnt_fc WHERE user_id = ? AND fc_image_id = ?", [$current_user_id, $id]);
    }
}

echo json_encode(['success' => true]);
