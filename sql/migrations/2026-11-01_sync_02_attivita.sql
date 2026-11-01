-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 2: to do e to buy
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

-- ---------- todos (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todos' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todos` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `todos` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `todos` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todos' AND INDEX_NAME = 'uq_todos_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todos` ADD UNIQUE KEY `uq_todos_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todos' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todos` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `todos` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id`, `list_type` ORDER BY `sort_order`, id) AS r FROM `todos`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- todo_buy_categories (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_buy_categories' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todo_buy_categories` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `todo_buy_categories` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `todo_buy_categories` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_buy_categories' AND INDEX_NAME = 'uq_todo_buy_categories_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todo_buy_categories` ADD UNIQUE KEY `uq_todo_buy_categories_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_buy_categories' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todo_buy_categories` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_buy_categories' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `todo_buy_categories` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `todo_buy_categories` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `todo_buy_categories`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- todo_notes (singola): niente da cambiare

-- todo_buy_memory (naturale): niente da cambiare

