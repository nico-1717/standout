-- =====================================================================
-- Verifica dell'email (STANDOUT_PROGETTO.md §7.4)
--
-- 1. users.email_verified_at : quando l'utente ha confermato la sua email.
-- 2. email_verifications     : i link mandati per email. Si salva solo l'HASH
--    del token (come per «ricordami»): chi legge il database non puo' usarlo.
--
-- Gli account che ESISTONO GIA' al momento della migrazione vengono segnati
-- come verificati: l'avviso «verifica la tua email» e' per chi si registra da
-- ora in poi. L'UPDATE parte solo se la colonna viene creata adesso, cosi'
-- rilanciare il file non segna come verificato chi non lo e'.
--
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL).
-- Il sito funziona anche PRIMA di eseguirla: senza la colonna nessuno vede
-- l'avviso e nessuna email parte.
-- =====================================================================

SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL COMMENT ''Quando ha confermato l''''email dal link ricevuto (NULL = non ancora)'' AFTER `email`',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@esiste = 0,
    'UPDATE `users` SET `email_verified_at` = NOW() WHERE `email_verified_at` IS NULL',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email` varchar(255) NOT NULL COMMENT 'L''indirizzo a cui e'' stato mandato: se l''utente lo cambia, il link non vale piu''',
  `token_hash` char(64) NOT NULL COMMENT 'sha256 del token: il token vero sta solo nel link dell''email',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_unico` (`token_hash`),
  KEY `utente_data` (`user_id`, `created_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
