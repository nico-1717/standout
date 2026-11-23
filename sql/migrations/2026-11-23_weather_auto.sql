-- =====================================================================
-- Meteo: il luogo puo' essere «automatico» (segue la posizione viva del telefono / browser)
-- oppure «a mano» (resta il posto scelto). 0 = a mano (come funzionava finora), 1 = automatico.
-- Da lanciare UNA volta in phpMyAdmin (database my_nisama). Rilanciarla non fa danni.
-- Finche' non la lanci, il meteo funziona come prima (solo «a mano»), senza errori.
-- =====================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planner_settings' AND COLUMN_NAME = 'weather_auto');
SET @sql := IF(@c = 0,
  'ALTER TABLE `planner_settings` ADD COLUMN `weather_auto` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = il luogo del meteo segue la posizione viva; 0 = resta il posto scelto'' AFTER `weather_place`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
