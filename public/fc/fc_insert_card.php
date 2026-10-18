<?php
/* fc_insert_card.php — resta come indirizzo, ma il modulo vive altrove.
 *
 * Inserimento e modifica erano due file con gli stessi campi, la stessa
 * toolbar, lo stesso audio, la stessa immagine, la stessa IA: due copie da
 * correggere ogni volta, ed e' esattamente cosi' che ci si e' ritrovati con
 * la toolbar rotta e il token CSRF mancante da una parte sola. Ora sono un
 * file solo, fc_card_edit.php, con ?modo=insert.
 *
 * Questo file non viene cancellato perche' l'indirizzo e' linkato da altre
 * pagine e potrebbe essere nei preferiti di qualcuno: rimanda, conservando
 * la categoria se c'era.
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';

$cat = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
/* Il corso (flashcard di lingua) viaggia con il resto: senza, la parola
   finirebbe nel modulo delle domande di studio. fc_card_edit controlla poi
   che il corso sia davvero dell'utente. */
$corso = isset($_GET['course']) ? (int)$_GET['course'] : 0;
header('Location: fc_card_edit.php?modo=insert' . ($corso > 0 ? '&course=' . $corso : '') . ($cat > 0 ? '&category_id=' . $cat : ''));
exit;
