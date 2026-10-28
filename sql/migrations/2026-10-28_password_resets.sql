-- =====================================================================
-- «Password dimenticata»: i link per sceglierne una nuova (STANDOUT_PROGETTO.md §7.4)
--
-- password_resets: UNA RIGA PER LINK mandato per email. Il link e' il token; nel
-- database c'e' solo il suo sha256 (come per email_verifications e api_sessions):
-- chi legge il database non puo' usare niente di quello che c'e' scritto.
--   - dura 60 minuti (expires_at) ed e' monouso (used_at);
--   - vale solo per l'indirizzo a cui e' stato mandato (email): se l'utente lo
--     cambia nel frattempo, il link non vale piu';
--   - nessun indirizzo IP: serve solo a controllare il link.
-- Il nome della colonna token_hash fa si' che lib/account_dati.php non la metta mai
-- in «scarica i miei dati».
--
-- Si puo' rilanciare senza danni (CREATE TABLE IF NOT EXISTS).
-- Da eseguire in phpMyAdmin (scheda SQL). Il sito funziona anche PRIMA di eseguirla:
-- senza la tabella la pagina «password dimenticata» non manda nessuna email.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email` varchar(255) NOT NULL COMMENT 'L''indirizzo a cui e'' stato mandato: se l''utente lo cambia, il link non vale piu''',
  `token_hash` char(64) NOT NULL COMMENT 'sha256 del token: il token vero sta solo nel link dell''email',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL COMMENT 'Quando e'' stato usato, o sostituito da un link piu'' nuovo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_unico` (`token_hash`),
  KEY `utente_data` (`user_id`, `created_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
