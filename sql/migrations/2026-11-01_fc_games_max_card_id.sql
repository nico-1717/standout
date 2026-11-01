-- =====================================================================
-- Carte aggiunte a un mazzo mentre una partita e' a meta'
--
-- fc_games.max_card_id : l'id piu' alto tra le carte al momento dell'avvio.
-- Le carte del mazzo con id maggiore (e non in sequenza) sono nate dopo: alla
-- ripresa la partita chiede se giocare anche quelle (lib/fc/fc_nuove_carte.php).
-- NULL = partita vecchia o ripasso: nessuna domanda.
--
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL).
-- Il sito funziona anche PRIMA di eseguirla: senza la colonna la domanda
-- semplicemente non compare.
-- =====================================================================

SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_games' AND COLUMN_NAME = 'max_card_id');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `fc_games` ADD COLUMN `max_card_id` BIGINT NULL DEFAULT NULL COMMENT ''Id piu alto tra le carte all avvio: le carte con id maggiore sono nuove'' AFTER `total_words_with_children`',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
