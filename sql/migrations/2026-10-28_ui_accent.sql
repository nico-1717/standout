-- =====================================================================
-- Il colore del sito scelto dall'utente (lib/accent.php)
--
-- users.ui_accent : il colore scelto dal pannello del profilo (avatar > Stile).
-- NULL = non ha mai scelto: vale il blu. E' la COPIA sul profilo: la scelta
-- vive nel cookie nisama_accento, questa serve a un altro dispositivo o a chi
-- ha cancellato i cookie.
--
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL).
-- Il sito funziona anche PRIMA di eseguirla: senza la colonna il colore vale
-- solo sul browser dove e' stato scelto.
-- =====================================================================

SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ui_accent');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `users` ADD COLUMN `ui_accent` VARCHAR(10) NULL DEFAULT NULL COMMENT ''Colore del sito: indigo | violet | teal | coral | rose (NULL = blu)'' AFTER `ui_style`',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
