-- La home personalizzabile: layout dei riquadri e carte veloci da completare.
-- Si può eseguire più volte: se le tabelle ci sono già (le crea da sola la
-- prima richiesta a home_api.php) non succede niente.

CREATE TABLE IF NOT EXISTS `home_layout` (
  `user_id` int NOT NULL,
  `layout` text NOT NULL COMMENT 'JSON: [{id, on}] nell''ordine in cui compaiono i riquadri',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fc_quick_drafts` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `front` varchar(500) NOT NULL COMMENT 'il fronte scritto al volo; il retro e il mazzo si scelgono dopo',
  `back` varchar(2000) NOT NULL DEFAULT '',
  `deck_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
