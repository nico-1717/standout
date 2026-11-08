-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 5: progetti, Pomodoro, piano, voti
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

-- ---------- projects (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `projects` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `projects` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `projects` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `projects` ADD UNIQUE KEY `uq_projects_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `projects` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `projects` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id`, `parent_id` ORDER BY `custom_order`, id) AS r FROM `projects`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- pomodoro_sessions (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_sessions' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_sessions` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `pomodoro_sessions` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `pomodoro_sessions` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_sessions' AND INDEX_NAME = 'uq_pomodoro_sessions_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_sessions` ADD UNIQUE KEY `uq_pomodoro_sessions_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_sessions' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_sessions` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- pomodoro_custom_durations (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_custom_durations' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_custom_durations` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `pomodoro_custom_durations` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `pomodoro_custom_durations` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_custom_durations' AND INDEX_NAME = 'uq_pomodoro_custom_durations_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_custom_durations` ADD UNIQUE KEY `uq_pomodoro_custom_durations_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_custom_durations' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_custom_durations` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- pomodoro_settings (singola)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_settings' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_settings` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- pomodoro_current_breaks (singola)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pomodoro_current_breaks' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `pomodoro_current_breaks` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- plan_templates (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_templates' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_templates` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `plan_templates` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `plan_templates` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_templates' AND INDEX_NAME = 'uq_plan_templates_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_templates` ADD UNIQUE KEY `uq_plan_templates_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_templates' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_templates` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- study_templates (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'study_templates' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `study_templates` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `study_templates` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `study_templates` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'study_templates' AND INDEX_NAME = 'uq_study_templates_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `study_templates` ADD UNIQUE KEY `uq_study_templates_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'study_templates' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `study_templates` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- university_exams (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exams' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exams` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_exams` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `university_exams` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exams' AND INDEX_NAME = 'uq_university_exams_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exams` ADD UNIQUE KEY `uq_university_exams_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exams' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exams` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_exams` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `university_exams`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- university_exam_groups (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exam_groups' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exam_groups` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_exam_groups` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `university_exam_groups` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exam_groups' AND INDEX_NAME = 'uq_university_exam_groups_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exam_groups` ADD UNIQUE KEY `uq_university_exam_groups_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exam_groups' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exam_groups` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_exam_groups' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_exam_groups` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_exam_groups` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `university_exam_groups`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- ---------- university_bonuses (riga)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_bonuses' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_bonuses` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_bonuses` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `university_bonuses` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_bonuses' AND INDEX_NAME = 'uq_university_bonuses_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_bonuses` ADD UNIQUE KEY `uq_university_bonuses_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_bonuses' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_bonuses` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'university_bonuses' AND COLUMN_NAME = 'sort_key');
SET @sql := IF(@ok = 0, 'ALTER TABLE `university_bonuses` ADD COLUMN `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `university_bonuses` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `sort_order`, id) AS r FROM `university_bonuses`) k ON k.rid = x.id SET x.`sort_key` = CASE WHEN (k.r - 1) < 62 THEN CONCAT('a', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (k.r - 1) + 1, 1)) WHEN (k.r - 1) < 3906 THEN CONCAT('b', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 62) / 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 62), 62) + 1, 1)) WHEN (k.r - 1) < 242234 THEN CONCAT('c', SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', FLOOR(((k.r - 1) - 3906) / 3844) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(FLOOR(((k.r - 1) - 3906) / 62), 62) + 1, 1), SUBSTRING('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', MOD(((k.r - 1) - 3906), 62) + 1, 1)) ELSE NULL END WHERE x.`sort_key` IS NULL;

-- university_settings (singola): niente da cambiare

