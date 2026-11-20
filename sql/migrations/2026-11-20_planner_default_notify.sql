-- Avviso predefinito degli eventi nuovi (impostazioni del planner).
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL).
-- Chi non la sceglie continua ad avere «un'ora prima», come prima.
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_settings' AND COLUMN_NAME = 'default_notify');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_settings` ADD COLUMN `default_notify` VARCHAR(10) NOT NULL DEFAULT ''h1'' COMMENT ''avviso con cui parte un evento nuovo: none | at_time | m30 | h1 | d1''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
