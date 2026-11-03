-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 6: flashcard di studio e di vocabolario
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

-- ---------- fc_study_decks (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_decks' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_decks` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_decks` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_decks` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_decks' AND INDEX_NAME = 'uq_fc_study_decks_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_decks` ADD UNIQUE KEY `uq_fc_study_decks_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_decks' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_decks` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_cards (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_cards' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_cards` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_cards` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_cards` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_cards' AND INDEX_NAME = 'uq_fc_study_cards_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_cards` ADD UNIQUE KEY `uq_fc_study_cards_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_cards' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_cards` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_cards' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_cards` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_cards` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `category_id` ORDER BY `custom_order`, id) AS r FROM `fc_study_cards`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- fc_study_subcards (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_subcards' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_subcards` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_subcards` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_subcards` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_subcards' AND INDEX_NAME = 'uq_fc_study_subcards_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_subcards` ADD UNIQUE KEY `uq_fc_study_subcards_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_subcards' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_subcards` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_subcards' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_subcards` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_subcards` x JOIN (SELECT id_child AS rid, ROW_NUMBER() OVER (PARTITION BY `parent_id` ORDER BY `custom_order`, id_child) AS r FROM `fc_study_subcards`) k ON k.rid = x.id_child SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- fc_study_examples (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_examples' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_examples` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_examples` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_examples` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_examples' AND INDEX_NAME = 'uq_fc_study_examples_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_examples` ADD UNIQUE KEY `uq_fc_study_examples_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_examples' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_examples` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_favorites (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_favorites' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_favorites` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_favorites` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_favorites` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_favorites' AND INDEX_NAME = 'uq_fc_study_favorites_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_favorites` ADD UNIQUE KEY `uq_fc_study_favorites_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_favorites' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_favorites` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_games (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_games' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_games` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_games` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_games` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_games' AND INDEX_NAME = 'uq_fc_games_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_games` ADD UNIQUE KEY `uq_fc_games_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_games' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_games` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_game_durations (append)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_game_durations' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_game_durations` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_game_durations` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_game_durations` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_game_durations' AND INDEX_NAME = 'uq_fc_game_durations_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_game_durations` ADD UNIQUE KEY `uq_fc_game_durations_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_plays (append)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_plays' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_plays` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_plays` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_plays` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_plays' AND INDEX_NAME = 'uq_fc_study_plays_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_plays` ADD UNIQUE KEY `uq_fc_study_plays_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_courses (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_courses' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_courses` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_courses` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_courses` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_courses' AND INDEX_NAME = 'uq_fc_vocab_courses_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_courses` ADD UNIQUE KEY `uq_fc_vocab_courses_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_courses' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_courses` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_courses' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_courses` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_courses` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `custom_order`, id) AS r FROM `fc_vocab_courses`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- fc_vocab_decks (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_decks' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_decks` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_decks` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_decks` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_decks' AND INDEX_NAME = 'uq_fc_vocab_decks_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_decks` ADD UNIQUE KEY `uq_fc_vocab_decks_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_decks' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_decks` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_cards (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_cards' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_cards` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_cards` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_cards` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_cards' AND INDEX_NAME = 'uq_fc_vocab_cards_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_cards` ADD UNIQUE KEY `uq_fc_vocab_cards_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_cards' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_cards` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_cards' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_cards` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_cards` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `category_id` ORDER BY `custom_order`, id) AS r FROM `fc_vocab_cards`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- fc_vocab_subcards (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_subcards' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_subcards` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_subcards` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_subcards` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_subcards' AND INDEX_NAME = 'uq_fc_vocab_subcards_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_subcards` ADD UNIQUE KEY `uq_fc_vocab_subcards_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_subcards' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_subcards` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_subcards' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_subcards` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_subcards` x JOIN (SELECT id_child AS rid, ROW_NUMBER() OVER (PARTITION BY `parent_id` ORDER BY `custom_order`, id_child) AS r FROM `fc_vocab_subcards`) k ON k.rid = x.id_child SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- fc_vocab_examples (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_examples' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_examples` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_examples` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_examples` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_examples' AND INDEX_NAME = 'uq_fc_vocab_examples_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_examples` ADD UNIQUE KEY `uq_fc_vocab_examples_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_examples' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_examples` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_favorites (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_favorites' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_favorites` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_favorites` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_favorites` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_favorites' AND INDEX_NAME = 'uq_fc_vocab_favorites_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_favorites` ADD UNIQUE KEY `uq_fc_vocab_favorites_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_favorites' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_favorites` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_plays (append)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_plays' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_plays` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_plays` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_plays` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_plays' AND INDEX_NAME = 'uq_fc_vocab_plays_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_plays` ADD UNIQUE KEY `uq_fc_vocab_plays_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

