-- =====================================================================
-- Consenso a privacy e condizioni d'uso, registrato alla registrazione
--
-- terms_accepted_at: quando l'utente ha accettato (NULL = account nato prima
--                    che la richiesta esistesse).
-- terms_version:     quale versione dei testi ha accettato (la data della
--                    versione, vedi STANDOUT_LEGALE_VERSIONE in lib/legale.php).
--
-- ORDINE: eseguirla PRIMA di pubblicare il nuovo codice (la registrazione
-- scrive queste colonne). Si puo' rilanciare senza danni.
-- Da eseguire in phpMyAdmin (scheda SQL), tutto insieme.
-- =====================================================================
SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'terms_accepted_at');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `users`
        ADD COLUMN `terms_accepted_at` DATETIME NULL COMMENT ''quando ha accettato privacy e condizioni (NULL = account precedente alla richiesta)'',
        ADD COLUMN `terms_version` VARCHAR(20) NULL COMMENT ''versione dei testi accettata (data, es. 2026-10-27)''',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
