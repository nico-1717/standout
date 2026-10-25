-- Il piano dei progetti (calendario, ore, evidenziatore, pianifica per me).
-- Si può eseguire più volte: se le tabelle ci sono già (le crea da sola la
-- prima richiesta a piano_api.php) non succede niente.

CREATE TABLE IF NOT EXISTS `plan_goals` (
  `user_id` int NOT NULL,
  `project_id` int NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `minutes` int NOT NULL DEFAULT '0',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `kind` varchar(10) NOT NULL DEFAULT 'plain',
  `priority` varchar(10) NOT NULL DEFAULT 'should',
  `start_date` date DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `since` date DEFAULT NULL,
  `items` int DEFAULT NULL,
  `unit_minutes` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_paint` (
  `user_id` int NOT NULL,
  `project_id` int NOT NULL,
  `day` date NOT NULL,
  PRIMARY KEY (`user_id`,`project_id`,`day`),
  KEY `idx_day` (`user_id`,`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_days` (
  `user_id` int NOT NULL,
  `day` date NOT NULL,
  `project_id` int NOT NULL,
  `kind` varchar(8) NOT NULL DEFAULT 'study',
  `minutes` int NOT NULL DEFAULT '0',
  `source` varchar(8) NOT NULL DEFAULT 'auto',
  PRIMARY KEY (`user_id`,`day`,`project_id`,`kind`),
  KEY `idx_proj` (`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_settings` (
  `user_id` int NOT NULL,
  `weekday_min` int DEFAULT NULL,
  `weekend_min` int DEFAULT NULL,
  `rest_dow` tinyint NOT NULL DEFAULT '0',
  `buffer_days` tinyint NOT NULL DEFAULT '0',
  `floor_min` smallint NOT NULL DEFAULT '15',
  `max_subjects` tinyint NOT NULL DEFAULT '3',
  `reviews` tinyint(1) NOT NULL DEFAULT '0',
  `overrides` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_templates` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(80) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
