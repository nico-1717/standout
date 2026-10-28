-- =====================================================================
-- Accesso con token per l'app (STANDOUT_PROGETTO.md §7.3, passo 7)
--
-- api_sessions: UNA RIGA PER DISPOSITIVO collegato con l'app. Il sito continua
-- con il cookie di sessione; questa tabella serve solo a chi entra con i token.
--
-- Nel database NON c'e' mai un token: solo il suo sha256. Un token e' fatto di
-- due parti, come il «ricordami» (remember_tokens):
--   - selector: parte NON segreta, serve a trovare la riga senza confronti lenti;
--   - validator: parte segreta (32 byte casuali), si salva solo il suo hash.
-- Chi legge il database non puo' quindi usare niente di quello che c'e' scritto.
--
-- Due token per riga:
--   - accesso (access_*): vive poco (30 minuti), va in ogni chiamata;
--   - rinnovo (refresh_*): vive a lungo (60 giorni), si usa solo per averne di
--     nuovi, e a ogni uso viene SOSTITUITO. Quello appena sostituito resta in
--     prev_refresh_*: se qualcuno lo presenta di nuovo, e' stato copiato, e la
--     sessione del dispositivo viene chiusa.
--
-- I nomi delle colonne segrete contengono selector / hash / refresh: cosi'
-- lib/account_dati.php (ACCOUNT_COLONNE_SEGRETE) non le mette mai in «scarica i
-- miei dati». Nome del dispositivo, user agent e date invece escono: sono dati
-- dell'utente e li deve poter vedere.
--
-- Si puo' rilanciare senza danni (CREATE TABLE IF NOT EXISTS).
-- Da eseguire in phpMyAdmin (scheda SQL) PRIMA di pubblicare il codice: senza la
-- tabella l'accesso con token da' errore (il sito normale funziona lo stesso).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `api_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_name` varchar(80) NOT NULL DEFAULT '' COMMENT 'Nome dato dall''app (es. iPhone di Nico), ripulito dal server',
  `user_agent` varchar(255) DEFAULT NULL,
  `access_selector` char(24) NOT NULL COMMENT 'Parte NON segreta del token di accesso: serve a trovare la riga',
  `access_hash` char(64) NOT NULL COMMENT 'sha256 della parte segreta del token di accesso',
  `access_expires_at` datetime NOT NULL COMMENT 'Scadenza del token di accesso (orologio del database)',
  `refresh_selector` char(24) NOT NULL COMMENT 'Parte NON segreta del token di rinnovo',
  `refresh_hash` char(64) NOT NULL COMMENT 'sha256 della parte segreta del token di rinnovo',
  `prev_refresh_selector` char(24) DEFAULT NULL COMMENT 'Il token di rinnovo appena sostituito: se qualcuno lo ripresenta e'' stato copiato',
  `prev_refresh_hash` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Ultima chiamata (aggiornata al massimo una volta al minuto); niente indirizzo IP',
  `expires_at` datetime NOT NULL COMMENT 'Fine della sessione del dispositivo: scadenza del token di rinnovo',
  `revoked_at` datetime DEFAULT NULL COMMENT 'Uscita da questo dispositivo, da tutti, o furto sospettato (NULL = attiva)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_access_selector` (`access_selector`),
  UNIQUE KEY `uq_api_refresh_selector` (`refresh_selector`),
  KEY `idx_api_prev_refresh_selector` (`prev_refresh_selector`),
  KEY `idx_api_utente` (`user_id`, `revoked_at`, `last_used_at`),
  CONSTRAINT `fk_api_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
