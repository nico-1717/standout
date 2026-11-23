-- =====================================================================
-- Tracker dei progetti (la quarta scheda di un progetto: Elenco / Calendario / Piano / Tracker)
--
-- Un tracker e' fatto da BLOCCHI che l'utente aggiunge a mano (inizialmente e' vuoto):
--   table       griglia come un foglio Excel, con somme e formule
--   counter     un numero che si alza e si abbassa
--   progress    barra di avanzamento (a mano o calcolata da un altro blocco)
--   chart       grafico di un altro blocco
--   checklist   elenco con spunte
--   note        testo libero
--   review      ripasso a intervalli (il modello di NeetCode: 150 problemi, fatto / non fatto)
--   connection  il sito da cui arrivano i dati (collegato dal vivo, oppure importato una volta)
-- Un solo tracker per progetto: sono i blocchi di quel progetto. Massimo 20 blocchi (lo controlla la sincronizzazione).
-- `config` e `data` sono JSON: la forma dipende da `kind`. Si sincronizza una riga alla volta
-- (vince l'ultima modifica di ogni blocco).
--
-- Il sito e l'app non la usano ancora: il codice arriva dopo che questo file e' stato eseguito.
-- Si puo' rilanciare senza danni (CREATE TABLE IF NOT EXISTS). Da eseguire in phpMyAdmin (scheda SQL).
-- Richiede MySQL >= 8.0 (o MariaDB >= 10.2.1) per il tipo JSON.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `tracker_blocks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Solo per scorrere la tabella a pagine (l''identita'' e'' uuid)',
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (UUID()) COMMENT 'Identita'' della riga per l''app (UUID)',
  `user_id` INT NOT NULL,
  `project_id` INT NOT NULL,
  `kind` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'table | counter | progress | chart | checklist | note | review | connection',
  `title` VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `config` JSON NOT NULL COMMENT 'Come e'' fatto il blocco: colonne, intervalli, formule, sito collegato (forma secondo kind)',
  `data` JSON NOT NULL COMMENT 'Quello che c''e'' dentro: celle, valore, spunte, problemi fatti (forma secondo kind)',
  `sort_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT 'Posizione nella scheda: stringa di fractional-indexing (si ordina come testo binario)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracker_blocks_uuid` (`uuid`),
  KEY `idx_tracker_blocks_progetto` (`user_id`, `project_id`),
  CONSTRAINT `fk_tracker_blocks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tracker_blocks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
