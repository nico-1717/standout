-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 8: piano dei progetti: obiettivi, giorni evidenziati, blocchi del giorno, impostazioni (le tabelle senza id)
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

-- ---------- plan_goals (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_goals' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_goals` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_plan_goals_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_goals' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_goals` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `plan_goals` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `plan_goals` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_goals' AND INDEX_NAME = 'uq_plan_goals_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_goals` ADD UNIQUE KEY `uq_plan_goals_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- plan_paint (nat_id)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_paint' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_paint` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_plan_paint_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_paint' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_paint` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `plan_paint` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `plan_paint` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_paint' AND INDEX_NAME = 'uq_plan_paint_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_paint` ADD UNIQUE KEY `uq_plan_paint_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- plan_days (nat_id_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND COLUMN_NAME = 'id');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_days` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Solo per scorrere la tabella a pagine (l''''identita'''' e'''' uuid)'', ADD UNIQUE KEY `uq_plan_days_id` (`id`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_days` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `plan_days` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `plan_days` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND INDEX_NAME = 'uq_plan_days_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_days` ADD UNIQUE KEY `uq_plan_days_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_days` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- plan_settings (uuid_u)
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_settings' AND COLUMN_NAME = 'uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_settings` ADD COLUMN `uuid` CHAR(36) NULL COMMENT ''Identita'''' della riga per l''''app (UUID); l''''id numerico resta quello del sito''', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE `plan_settings` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `plan_settings` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());
SET @ok := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_settings' AND INDEX_NAME = 'uq_plan_settings_uuid');
SET @sql := IF(@ok = 0, 'ALTER TABLE `plan_settings` ADD UNIQUE KEY `uq_plan_settings_uuid` (`uuid`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

