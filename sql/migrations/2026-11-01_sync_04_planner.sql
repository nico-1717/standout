-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 4: planner
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

-- ---------- planner_calendars (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_calendars' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_calendars` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_calendars` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_calendars` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_calendars' AND INDEX_NAME = 'uq_planner_calendars_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_calendars` ADD UNIQUE KEY `uq_planner_calendars_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_calendars' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_calendars` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_calendars' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_calendars` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_calendars` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `planner_calendars`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_categories (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_categories' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_categories` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_categories` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_categories` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_categories' AND INDEX_NAME = 'uq_planner_categories_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_categories` ADD UNIQUE KEY `uq_planner_categories_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_categories' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_categories` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_categories' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_categories` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_categories` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `planner_categories`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_category_keywords (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_category_keywords' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_category_keywords` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_category_keywords` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_category_keywords` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_category_keywords' AND INDEX_NAME = 'uq_planner_category_keywords_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_category_keywords` ADD UNIQUE KEY `uq_planner_category_keywords_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_category_keywords' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_category_keywords` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- planner_places (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_places' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_places` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_places` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_places` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_places' AND INDEX_NAME = 'uq_planner_places_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_places` ADD UNIQUE KEY `uq_planner_places_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_places' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_places` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_places' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_places` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_places` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `planner_places`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_priorities (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_priorities' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_priorities` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_priorities` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_priorities` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_priorities' AND INDEX_NAME = 'uq_planner_priorities_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_priorities` ADD UNIQUE KEY `uq_planner_priorities_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_priorities' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_priorities` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_priorities` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id`, `priority_date` ORDER BY `position`, id) AS r FROM `planner_priorities`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_routines (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routines' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routines` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_routines` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_routines` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routines' AND INDEX_NAME = 'uq_planner_routines_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routines` ADD UNIQUE KEY `uq_planner_routines_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routines' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routines` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routines' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routines` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_routines` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `planner_routines`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_routine_items (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routine_items' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routine_items` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_routine_items` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_routine_items` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routine_items' AND INDEX_NAME = 'uq_planner_routine_items_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routine_items` ADD UNIQUE KEY `uq_planner_routine_items_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routine_items' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routine_items` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_routine_items' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_routine_items` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_routine_items` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `routine_id` ORDER BY `sort_order`, id) AS r FROM `planner_routine_items`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_events (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_events' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_events` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_events` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_events` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_events' AND INDEX_NAME = 'uq_planner_events_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_events` ADD UNIQUE KEY `uq_planner_events_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_events' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_events` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_events` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `planner_events`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_weeks (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_weeks' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_weeks` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_weeks` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_weeks` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_weeks' AND INDEX_NAME = 'uq_planner_weeks_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_weeks` ADD UNIQUE KEY `uq_planner_weeks_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- planner_week_lists (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_lists' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_lists` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_week_lists` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_week_lists` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_lists' AND INDEX_NAME = 'uq_planner_week_lists_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_lists` ADD UNIQUE KEY `uq_planner_week_lists_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_lists' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_lists` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_lists' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_lists` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_week_lists` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id`, `week_start`, `list_type` ORDER BY `sort_order`, id) AS r FROM `planner_week_lists`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- planner_week_list_items (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_list_items' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_list_items` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_week_list_items` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `planner_week_list_items` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_list_items' AND INDEX_NAME = 'uq_planner_week_list_items_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_list_items` ADD UNIQUE KEY `uq_planner_week_list_items_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_list_items' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_list_items` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_week_list_items' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `planner_week_list_items` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `planner_week_list_items` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `list_id` ORDER BY `sort_order`, id) AS r FROM `planner_week_list_items`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- event_guests (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_guests' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `event_guests` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `event_guests` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `event_guests` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_guests' AND INDEX_NAME = 'uq_event_guests_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `event_guests` ADD UNIQUE KEY `uq_event_guests_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_guests' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `event_guests` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- contacts (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `contacts` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `contacts` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `contacts` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'uq_contacts_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `contacts` ADD UNIQUE KEY `uq_contacts_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `contacts` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- planner_settings (singola): niente da cambiare

