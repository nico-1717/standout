-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 3: abitudini, pastiglie, diario, home
--
-- Aggiunge alle tabelle di quest'area (vedi docs/ARCHITETTURA_APP.md §3.1):
--   uuid       l'identita' della riga per l'app (le righe esistenti ne ricevono uno)
--   updated_at dove mancava (si aggiorna da solo a ogni UPDATE: il codice esistente non cambia)
--   sort_key   la posizione nelle liste ordinabili (fractional-indexing)
-- Il sito funziona identico prima e dopo: nessuna query esistente nomina le colonne nuove.
--
-- FILE GENERATO da tools/genera_migrazioni_sync.py: non si modifica a mano.
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL), DOPO
-- 2026-11-01_sync_01_infra.sql. Richiede MySQL >= 8.0.13 (o MariaDB >= 10.2.1)
-- per `DEFAULT (UUID())` e le funzioni finestra: controlla con SELECT VERSION();
-- =====================================================================

-- ---------- habit_categories (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_categories' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_categories` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_categories` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_categories` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_categories' AND INDEX_NAME = 'uq_habit_categories_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_categories` ADD UNIQUE KEY `uq_habit_categories_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_categories' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_categories` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_categories' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_categories` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_categories` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `habit_categories`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- habits (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habits' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habits` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habits` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habits` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habits' AND INDEX_NAME = 'uq_habits_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habits` ADD UNIQUE KEY `uq_habits_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habits' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habits` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habits` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `habits`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- habit_logs (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_logs' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_logs` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_logs` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_logs` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_logs' AND INDEX_NAME = 'uq_habit_logs_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_logs` ADD UNIQUE KEY `uq_habit_logs_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- habit_log_events (append)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_log_events' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_log_events` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_log_events` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_log_events` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_log_events' AND INDEX_NAME = 'uq_habit_log_events_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_log_events` ADD UNIQUE KEY `uq_habit_log_events_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- habit_daily_journal (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_daily_journal' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_daily_journal` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_daily_journal` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_daily_journal` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_daily_journal' AND INDEX_NAME = 'uq_habit_daily_journal_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_daily_journal` ADD UNIQUE KEY `uq_habit_daily_journal_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- habit_user_achievements (append)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_user_achievements' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_user_achievements` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_user_achievements` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_user_achievements` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_user_achievements' AND INDEX_NAME = 'uq_habit_user_achievements_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_user_achievements` ADD UNIQUE KEY `uq_habit_user_achievements_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- habit_reminders (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_reminders' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_reminders` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `habit_reminders` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `habit_reminders` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_reminders' AND INDEX_NAME = 'uq_habit_reminders_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_reminders` ADD UNIQUE KEY `uq_habit_reminders_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_reminders' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `habit_reminders` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- habit_settings (singola): niente da cambiare

-- ---------- pill_plans (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_plans' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_plans` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `pill_plans` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `pill_plans` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_plans' AND INDEX_NAME = 'uq_pill_plans_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_plans` ADD UNIQUE KEY `uq_pill_plans_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_plans' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_plans` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `pill_plans` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `pill_plans`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- pill_doses (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_doses' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_doses` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `pill_doses` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `pill_doses` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_doses' AND INDEX_NAME = 'uq_pill_doses_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_doses` ADD UNIQUE KEY `uq_pill_doses_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pill_doses' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pill_doses` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- diary_list_items (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_list_items' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_list_items` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `diary_list_items` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `diary_list_items` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_list_items' AND INDEX_NAME = 'uq_diary_list_items_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_list_items` ADD UNIQUE KEY `uq_diary_list_items_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_list_items' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_list_items` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `diary_list_items` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id`, `entry_date`, `list_type` ORDER BY `sort_order`, id) AS r FROM `diary_list_items`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- diary_settings (singola): niente da cambiare

-- ---------- diary_ai_books (solo_server)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_ai_books' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_ai_books` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `diary_ai_books` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `diary_ai_books` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_ai_books' AND INDEX_NAME = 'uq_diary_ai_books_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_ai_books` ADD UNIQUE KEY `uq_diary_ai_books_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- diary_ai_reports (solo_server)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_ai_reports' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_ai_reports` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `diary_ai_reports` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `diary_ai_reports` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_ai_reports' AND INDEX_NAME = 'uq_diary_ai_reports_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_ai_reports` ADD UNIQUE KEY `uq_diary_ai_reports_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diary_ai_reports' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `diary_ai_reports` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- home_layout (singola): niente da cambiare

-- ---------- fc_quick_drafts (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_quick_drafts' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_quick_drafts` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_quick_drafts` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_quick_drafts` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_quick_drafts' AND INDEX_NAME = 'uq_fc_quick_drafts_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_quick_drafts` ADD UNIQUE KEY `uq_fc_quick_drafts_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_quick_drafts' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_quick_drafts` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

