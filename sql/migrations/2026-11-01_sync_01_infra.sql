-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 1: le tabelle del registro dei cambiamenti
--
-- sync_changes    il registro: ogni scrittura (del sito O dell'app) di una tabella
--                 sincronizzata scrive qui una riga. Il «cursore» di un telefono e'
--                 l'ultimo `seq` che ha visto. Una cancellazione e' una riga con
--                 op='delete': il registro STESSO e' la lapide, quindi le righe vere
--                 si cancellano davvero come oggi (vedi docs/ARCHITETTURA_APP.md §3.2).
-- sync_mutations  le modifiche gia' applicate, per non applicarle due volte se la
--                 risposta si perde e il telefono riprova (idempotenza). Pulita a 30 giorni.
-- sync_conflicts  l'altra versione di un testo lungo (diario, note) quando due
--                 dispositivi lo hanno cambiato insieme: non si perde mai una parola.
-- sync_meta       il «pavimento» di pulizia: un telefono col cursore sotto il pavimento
--                 deve riscaricare tutto (resync_required).
--
-- Si puo' rilanciare senza danni (CREATE TABLE IF NOT EXISTS). Il sito non le usa
-- ancora: eseguirla prima del codice non cambia nulla di visibile.
-- Da eseguire in phpMyAdmin (scheda SQL).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `sync_changes` (
  `seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `tbl` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Nome della tabella sincronizzata',
  `row_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `op` ENUM('upsert','delete') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `changed_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `device_id` INT NULL DEFAULT NULL COMMENT 'api_sessions.id di chi ha scritto (NULL = il sito): quel telefono non riscarica il proprio cambio',
  PRIMARY KEY (`seq`),
  KEY `idx_sync_utente_seq` (`user_id`, `seq`),
  KEY `idx_sync_riga` (`user_id`, `tbl`, `row_uuid`, `seq`),
  KEY `idx_sync_data` (`changed_at`),
  CONSTRAINT `fk_sync_changes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_mutations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `mutation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result` VARCHAR(255) NOT NULL COMMENT 'Esito salvato: applied | conflict_resolved | rejected:<codice>',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_mutazione` (`user_id`, `mutation_id`),
  KEY `idx_sync_mut_data` (`created_at`),
  CONSTRAINT `fk_sync_mutations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_conflicts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `tbl` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `row_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `field` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `other_value` MEDIUMTEXT NOT NULL COMMENT 'La versione che NON e'' rimasta come testo principale',
  `device_id` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL DEFAULT NULL COMMENT 'L''utente l''ha guardata (o l''ha tenuta)',
  PRIMARY KEY (`id`),
  KEY `idx_sync_conf_utente` (`user_id`, `resolved_at`),
  CONSTRAINT `fk_sync_conflicts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_meta` (
  `k` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `v` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- il pavimento parte da 0: nessun telefono e' ancora fuori sincrono
INSERT IGNORE INTO `sync_meta` (`k`, `v`) VALUES ('purged_up_to_seq', 0);
