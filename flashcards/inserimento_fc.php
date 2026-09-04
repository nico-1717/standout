<?php
/* inserimento_fc.php — resta come indirizzo, ma il modulo vive altrove.
 *
 * Inserimento e modifica erano due file con gli stessi campi, la stessa
 * toolbar, lo stesso audio, la stessa immagine, la stessa IA: due copie da
 * correggere ogni volta, ed e' esattamente cosi' che ci si e' ritrovati con
 * la toolbar rotta e il token CSRF mancante da una parte sola. Ora sono un
 * file solo, modifica_immagine_fc.php, con ?modo=insert.
 *
 * Questo file non viene cancellato perche' l'indirizzo e' linkato da altre
 * pagine e potrebbe essere nei preferiti di qualcuno: rimanda, conservando
 * la categoria se c'era.
 */
require_once 'config.php';
require_once 'auth_check.php';

$cat = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
header('Location: modifica_immagine_fc.php?modo=insert' . ($cat > 0 ? '&category_id=' . $cat : ''));
exit;
