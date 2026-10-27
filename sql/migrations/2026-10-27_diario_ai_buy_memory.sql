-- Tre tabelle che in produzione esistono già ma non avevano né una migrazione
-- né una riga in schema.sql (le vedeva solo chi guardava il database):
--   diary_ai_books   i libri del diario scritti dall'IA (piano e capitoli)
--   diary_ai_reports il resoconto trimestrale del diario, uno per trimestre
--   todo_buy_memory  la memoria della lista della spesa (cosa si compra spesso)
-- Si può eseguire più volte: se le tabelle ci sono già non succede niente.
-- Confrontato con il dump di produzione del 27/10/2026: stessi campi, stesse chiavi.

CREATE TABLE IF NOT EXISTS `diary_ai_books` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `periodo_da` date NOT NULL,
  `periodo_a` date NOT NULL,
  `unito_a` int UNSIGNED DEFAULT NULL COMMENT 'il libro precedente che questo continua e riordina',
  `stato` enum('scrittura','fatto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scrittura',
  `piano` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'il piano dei capitoli deciso dall IA',
  `capitoli` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'i capitoli scritti finora',
  `crediti` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'crediti IA spesi per questo libro',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_utente` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diary_ai_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `anno` smallint UNSIGNED NOT NULL,
  `trimestre` tinyint UNSIGNED NOT NULL,
  `periodo_da` date NOT NULL,
  `periodo_a` date NOT NULL,
  `contenuto` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'il resoconto scritto dall IA',
  `numeri` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON: i numeri del trimestre su cui si basa',
  `crediti` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'crediti IA spesi per questo resoconto',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trimestre` (`user_id`,`anno`,`trimestre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `todo_buy_memory` (
  `user_id` int NOT NULL,
  `chiave` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'il titolo normalizzato, per riconoscere la stessa cosa',
  `title` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_id` int UNSIGNED DEFAULT NULL COMMENT 'la categoria della spesa usata l ultima volta',
  `cents` int UNSIGNED DEFAULT NULL COMMENT 'l ultimo prezzo, in centesimi',
  `uses` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'quante volte è stata messa in lista',
  `last_used` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`chiave`),
  KEY `idx_uso` (`user_id`,`uses`,`last_used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
