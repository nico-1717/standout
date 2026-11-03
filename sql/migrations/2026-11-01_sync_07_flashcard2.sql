-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 7: flashcard: imparate, record, sequenze delle partite, preferenze, obiettivi (le tabelle senza id)
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

-- ---------- fc_study_learnt (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_learnt' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_learnt` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_study_learnt_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_learnt' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_learnt` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_learnt` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_learnt` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_learnt' AND INDEX_NAME = 'uq_fc_study_learnt_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_learnt` ADD UNIQUE KEY `uq_fc_study_learnt_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_learnt' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_learnt` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_records (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_records' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_records` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_study_records_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_records' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_records` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_records` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_records` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_records' AND INDEX_NAME = 'uq_fc_study_records_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_records` ADD UNIQUE KEY `uq_fc_study_records_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_records' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_records` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_deck_projects (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_deck_projects' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_deck_projects` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_study_deck_projects_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_deck_projects' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_deck_projects` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_deck_projects` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_deck_projects` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_deck_projects' AND INDEX_NAME = 'uq_fc_study_deck_projects_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_deck_projects` ADD UNIQUE KEY `uq_fc_study_deck_projects_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_ai (uuid_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_ai` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_ai` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai' AND INDEX_NAME = 'uq_fc_study_ai_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai` ADD UNIQUE KEY `uq_fc_study_ai_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_ai_child (uuid_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai_child' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai_child` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_ai_child` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_ai_child` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai_child' AND INDEX_NAME = 'uq_fc_study_ai_child_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai_child` ADD UNIQUE KEY `uq_fc_study_ai_child_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_ai_child' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_ai_child` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_game_decks (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_game_decks' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_game_decks` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_study_game_decks_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_game_decks' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_game_decks` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_game_decks` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_game_decks` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_game_decks' AND INDEX_NAME = 'uq_fc_study_game_decks_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_game_decks` ADD UNIQUE KEY `uq_fc_study_game_decks_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_study_sequence (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_sequence' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_sequence` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_study_sequence_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_sequence' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_sequence` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_study_sequence` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_study_sequence` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_study_sequence' AND INDEX_NAME = 'uq_fc_study_sequence_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_study_sequence` ADD UNIQUE KEY `uq_fc_study_sequence_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_learnt (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_learnt' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_learnt` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_vocab_learnt_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_learnt' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_learnt` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_learnt` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_learnt` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_learnt' AND INDEX_NAME = 'uq_fc_vocab_learnt_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_learnt` ADD UNIQUE KEY `uq_fc_vocab_learnt_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_learnt' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_learnt` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_records (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_records' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_records` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_vocab_records_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_records' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_records` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_records` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_records` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_records' AND INDEX_NAME = 'uq_fc_vocab_records_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_records` ADD UNIQUE KEY `uq_fc_vocab_records_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_records' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_records` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_game_decks (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_game_decks' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_game_decks` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_vocab_game_decks_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_game_decks' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_game_decks` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_game_decks` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_game_decks` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_game_decks' AND INDEX_NAME = 'uq_fc_vocab_game_decks_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_game_decks` ADD UNIQUE KEY `uq_fc_vocab_game_decks_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_vocab_sequence (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_sequence' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_sequence` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_fc_vocab_sequence_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_sequence' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_sequence` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_vocab_sequence` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_vocab_sequence` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_vocab_sequence' AND INDEX_NAME = 'uq_fc_vocab_sequence_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_vocab_sequence` ADD UNIQUE KEY `uq_fc_vocab_sequence_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_settings (uuid_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_settings' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_settings` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_settings` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_settings` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_settings' AND INDEX_NAME = 'uq_fc_settings_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_settings` ADD UNIQUE KEY `uq_fc_settings_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- fc_goals (uuid_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_goals' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_goals` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `fc_goals` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `fc_goals` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_goals' AND INDEX_NAME = 'uq_fc_goals_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `fc_goals` ADD UNIQUE KEY `uq_fc_goals_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

