-- =====================================================================
-- "Imparata" separata per padre e figlio (flashcard di studio E di lingua)
--
-- Prima: nella tabella degli "imparati" c'era una riga per carta PADRE, e il
-- pulsante "imparata" di un figlio segnava il padre. Padre e figli non
-- potevano avere uno stato diverso.
--
-- Ora: ogni carta ha il suo stato. La colonna is_child dice a quale tabella
-- si riferisce fc_image_id (0 = carta padre, 1 = carta figlia, con l'id di
-- fc_*_subcards.id_child). La chiave primaria diventa
-- (user_id, fc_image_id, is_child): un padre e un figlio possono avere lo
-- stesso numero senza confondersi.
--
-- Compatibilita' con i dati di oggi: finora "padre imparato" voleva dire
-- "blocco imparato" (i figli compresi), quindi per ogni padre gia' imparato
-- si segnano imparati anche tutti i suoi figli. Cosi' nessun blocco
-- "imparato" torna da imparare per effetto della migrazione.
--
-- ORDINE: eseguirla PRIMA di pubblicare il nuovo codice. Il codice vecchio
-- funziona anche con la colonna nuova (is_child ha default 0).
-- Si puo' rilanciare senza danni (controlla se la colonna esiste gia').
-- Da eseguire in phpMyAdmin (scheda SQL), tutto insieme.
-- =====================================================================

-- ---------- studio ----------
SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_learnt' AND COLUMN_NAME = 'is_child');

-- DROP e ADD della chiave primaria nello STESSO comando: la chiave serve alla
-- chiave esterna su user_id e non puo' sparire nemmeno per un istante.
SET @sql := IF(@esiste = 0,
    'ALTER TABLE `fc_study_learnt`
        ADD COLUMN `is_child` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''0 = carta padre, 1 = carta figlia (id_child)'' AFTER `fc_image_id`,
        DROP PRIMARY KEY,
        ADD PRIMARY KEY (`user_id`, `fc_image_id`, `is_child`)',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@esiste = 0,
    'INSERT IGNORE INTO `fc_study_learnt` (`user_id`, `fc_image_id`, `is_child`, `learnt_date`)
        SELECT l.`user_id`, s.`id_child`, 1, l.`learnt_date`
          FROM `fc_study_learnt` l
          JOIN `fc_study_subcards` s ON s.`parent_id` = l.`fc_image_id`
         WHERE l.`is_child` = 0',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- lingue ----------
SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_learnt' AND COLUMN_NAME = 'is_child');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `fc_vocab_learnt`
        ADD COLUMN `is_child` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''0 = carta padre, 1 = carta figlia (id_child)'' AFTER `fc_image_id`,
        DROP PRIMARY KEY,
        ADD PRIMARY KEY (`user_id`, `fc_image_id`, `is_child`)',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@esiste = 0,
    'INSERT IGNORE INTO `fc_vocab_learnt` (`user_id`, `fc_image_id`, `is_child`, `learnt_date`)
        SELECT l.`user_id`, s.`id_child`, 1, l.`learnt_date`
          FROM `fc_vocab_learnt` l
          JOIN `fc_vocab_subcards` s ON s.`parent_id` = l.`fc_image_id`
         WHERE l.`is_child` = 0',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
