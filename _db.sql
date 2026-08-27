-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Ago 25, 2026 alle 11:48
-- Versione del server: 8.0.45
-- Versione PHP: 8.0.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `my_nisama`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `ai_perf_stats`
--

CREATE TABLE `ai_perf_stats` (
  `mode` varchar(20) NOT NULL COMMENT 'explain, metaphor, example, shatter, trivia...',
  `avg_duration_ms` int NOT NULL DEFAULT '0' COMMENT 'Media storica (running average) della durata in ms',
  `samples` int NOT NULL DEFAULT '0' COMMENT 'Numero di misurazioni incluse nella media',
  `last_duration_ms` int DEFAULT NULL COMMENT 'Durata (ms) dell''ultima chiamata registrata',
  `last_measured_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `categories`
--

CREATE TABLE `categories` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `parent_id` int UNSIGNED NOT NULL DEFAULT '0',
  `color` varchar(20) NOT NULL DEFAULT '#007AFF',
  `color_edited` tinyint(1) NOT NULL DEFAULT '0',
  `user_id` int DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `contacts`
--

CREATE TABLE `contacts` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'come lo chiami tu: "luchino"',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `diary_list_items`
--

CREATE TABLE `diary_list_items` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `list_type` enum('good','learned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `diary_settings`
--

CREATE TABLE `diary_settings` (
  `user_id` int NOT NULL,
  `font_key` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'caveat' COMMENT 'caveat | kalam | patrick | cormorant | jost',
  `paper_key` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lined',
  `font_size` tinyint UNSIGNED NOT NULL DEFAULT '18',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `english_child_words`
--

CREATE TABLE `english_child_words` (
  `id_child` int NOT NULL,
  `parent_id` int NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `description_en` varchar(255) NOT NULL,
  `description_it` varchar(255) NOT NULL,
  `meaning` text,
  `example` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `event_guests`
--

CREATE TABLE `event_guests` (
  `id` bigint UNSIGNED NOT NULL,
  `event_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `contact_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL se l indirizzo è stato scritto a mano',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_ai_data`
--

CREATE TABLE `fc_ai_data` (
  `card_id` int NOT NULL,
  `ai_explanation` text,
  `ai_metaphor` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_categories`
--

CREATE TABLE `fc_categories` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `parent_id` int UNSIGNED NOT NULL DEFAULT '0',
  `color` varchar(20) NOT NULL DEFAULT '#007AFF',
  `color_edited` tinyint(1) NOT NULL DEFAULT '0',
  `user_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_child_cards`
--

CREATE TABLE `fc_child_cards` (
  `id_child` int NOT NULL,
  `parent_id` int NOT NULL,
  `question_desc` text,
  `question_image` varchar(255) DEFAULT NULL,
  `question_audio` varchar(255) DEFAULT NULL,
  `answer_desc` text,
  `answer_image` varchar(255) DEFAULT NULL,
  `answer_audio` varchar(255) DEFAULT NULL,
  `custom_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_examples`
--

CREATE TABLE `fc_examples` (
  `id` int NOT NULL,
  `fc_image_id` int NOT NULL,
  `example_text` text,
  `example_image` varchar(255) DEFAULT NULL,
  `example_audio` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_gameplays`
--

CREATE TABLE `fc_gameplays` (
  `id` int NOT NULL,
  `game_id` int UNSIGNED NOT NULL,
  `fc_image_id` bigint DEFAULT NULL,
  `fc_child_id` int DEFAULT NULL,
  `correct_answer` varchar(255) DEFAULT NULL,
  `user_answer` varchar(255) DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `play_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_images`
--

CREATE TABLE `fc_images` (
  `id` bigint NOT NULL,
  `question_image` varchar(300) DEFAULT NULL,
  `answer_image` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `question_desc` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `answer_desc` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `category_id` int UNSIGNED NOT NULL,
  `question_audio` varchar(255) DEFAULT NULL,
  `answer_audio` varchar(255) DEFAULT NULL,
  `is_father` tinyint(1) NOT NULL DEFAULT '0',
  `custom_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `fc_user_goals`
--

CREATE TABLE `fc_user_goals` (
  `user_id` int NOT NULL,
  `daily_goal` int NOT NULL DEFAULT '20',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `friends`
--

CREATE TABLE `friends` (
  `user_id_a` int NOT NULL COMMENT 'Always the lower user ID',
  `user_id_b` int NOT NULL COMMENT 'Always the higher user ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `friends_notifications`
--

CREATE TABLE `friends_notifications` (
  `id` int NOT NULL,
  `from_user_id` int NOT NULL COMMENT 'User who sends/triggers',
  `to_user_id` int NOT NULL COMMENT 'User who receives',
  `is_accepted` tinyint NOT NULL DEFAULT '0' COMMENT '0 = pending request, 1 = accepted notification',
  `is_read` tinyint NOT NULL DEFAULT '0' COMMENT '0 = unread, 1 = read',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `gameplays`
--

CREATE TABLE `gameplays` (
  `id` int NOT NULL,
  `image_id` int DEFAULT NULL,
  `child_id` int DEFAULT NULL,
  `correct_answer` varchar(255) DEFAULT NULL,
  `user_answer` varchar(255) DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `game_id` int UNSIGNED NOT NULL,
  `play_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `games`
--

CREATE TABLE `games` (
  `id` int UNSIGNED NOT NULL,
  `game_date` datetime DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `user_id` int DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `total_father_words` int DEFAULT '0',
  `game_order` varchar(20) DEFAULT 'random',
  `total_words_with_children` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `game_categories`
--

CREATE TABLE `game_categories` (
  `game_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `category_order` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `game_fc_categories`
--

CREATE TABLE `game_fc_categories` (
  `game_id` int UNSIGNED NOT NULL,
  `fc_category_id` int UNSIGNED NOT NULL,
  `category_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `google_calendar_accounts`
--

CREATE TABLE `google_calendar_accounts` (
  `user_id` int NOT NULL,
  `google_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'cifrato con GC_ENC_KEY',
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'cifrato con GC_ENC_KEY',
  `token_expires` datetime DEFAULT NULL,
  `calendar_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `sync_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `google_calendar_map`
--

CREATE TABLE `google_calendar_map` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `google_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'id del calendario su Google',
  `google_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `can_write` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'se si può anche scrivere su questo calendario (falso per compleanni/festività)',
  `calendar_id` int UNSIGNED DEFAULT NULL COMMENT 'mio calendario di destinazione; NULL = non importare',
  `decided` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = mai scelto, va chiesto',
  `sync_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `google_sync_log`
--

CREATE TABLE `google_sync_log` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `direction` enum('pull','push','delete','auth') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` enum('ok','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `items` int NOT NULL DEFAULT '0',
  `detail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `google_sync_queue`
--

CREATE TABLE `google_sync_queue` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `google_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `google_calendar_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` enum('delete') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delete',
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habits`
--

CREATE TABLE `habits` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int UNSIGNED DEFAULT NULL,
  `preset_key` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'chiave del preset in habits_presets.json, NULL se creata a mano',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '⭐',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#7C5CFF',
  `motivation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'frase motivazionale mostrata nel dettaglio',
  `habit_kind` enum('build','quit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'build',
  `goal_type` enum('completion','quantity') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completion',
  `target_amount` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT 'quanto serve per considerarla completata',
  `step_amount` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT 'incremento per ogni spunta',
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'bicchieri, min, km, pagine...',
  `frequency_type` enum('daily','weekly','monthly','interval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `weekdays` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1111111' COMMENT 'bitmask lun..dom, usata se frequency_type = daily',
  `times_per_week` tinyint UNSIGNED NOT NULL DEFAULT '7' COMMENT 'usato se frequency_type = weekly',
  `times_per_month` tinyint UNSIGNED NOT NULL DEFAULT '30' COMMENT 'usato se frequency_type = monthly',
  `interval_days` smallint UNSIGNED NOT NULL DEFAULT '1' COMMENT 'usato se frequency_type = interval',
  `day_part` enum('any','morning','afternoon','evening','night') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `duration_type` enum('forever','days','end_date') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'forever',
  `duration_days` smallint UNSIGNED DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'calcolata da duration_days oppure scelta a mano',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `planner_minutes` smallint UNSIGNED DEFAULT NULL COMMENT 'durata stimata in minuti, usata dal planner. NULL = usa lo scatto corrente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_achievements`
--

CREATE TABLE `habit_achievements` (
  `id` int UNSIGNED NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 0xF09F8F86,
  `tier` enum('bronze','silver','gold','platinum') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bronze',
  `metric` enum('streak','total_checkins','perfect_days','completion_rate','habit_count','comeback') COLLATE utf8mb4_unicode_ci NOT NULL,
  `threshold` int NOT NULL DEFAULT '1',
  `scope` enum('habit','global') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'habit',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_categories`
--

CREATE TABLE `habit_categories` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL DEFAULT '0' COMMENT '0 = categoria di sistema, altrimenti proprietario',
  `slug` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'chiave stabile usata da JSON/preset',
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0A84FF',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_daily_journal`
--

CREATE TABLE `habit_daily_journal` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `mood` tinyint DEFAULT NULL COMMENT '1..5',
  `energy` tinyint DEFAULT NULL COMMENT '1..5',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_logs`
--

CREATE TABLE `habit_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `habit_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `log_date` date NOT NULL,
  `status` enum('done','partial','skipped','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'partial',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'quantità accumulata nel giorno',
  `target_amount` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT 'snapshot del target del giorno',
  `completions` int NOT NULL DEFAULT '0' COMMENT 'numero di spunte ricevute',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'pensiero del check-in',
  `mood` tinyint DEFAULT NULL COMMENT '1..5, opzionale',
  `first_logged_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_log_events`
--

CREATE TABLE `habit_log_events` (
  `id` bigint UNSIGNED NOT NULL,
  `habit_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `log_date` date NOT NULL,
  `delta` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT 'positivo = spunta, negativo = rimozione',
  `source` enum('swipe','tap','table','detail','quick_add','undo','auto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tap',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_reminders`
--

CREATE TABLE `habit_reminders` (
  `id` int UNSIGNED NOT NULL,
  `habit_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `remind_at` time NOT NULL DEFAULT '09:00:00',
  `weekdays` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1111111',
  `message` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_sent_on` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_settings`
--

CREATE TABLE `habit_settings` (
  `user_id` int NOT NULL,
  `week_start` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 = lunedì, 7 = domenica',
  `default_view` enum('list','table','stats') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'list',
  `visible_days` tinyint UNSIGNED NOT NULL DEFAULT '11' COMMENT 'giorni mostrati nella barra in alto (oggi + ultimi N)',
  `accent_color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#7C5CFF',
  `theme_mode` enum('auto','light','dark') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `show_archived` tinyint(1) NOT NULL DEFAULT '0',
  `group_by_day_part` tinyint(1) NOT NULL DEFAULT '1',
  `haptics_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sound_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `confetti_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `carousel_autoplay` tinyint(1) NOT NULL DEFAULT '1',
  `carousel_interval_s` tinyint UNSIGNED NOT NULL DEFAULT '6',
  `chart_range_days` smallint UNSIGNED NOT NULL DEFAULT '30',
  `streak_freezes` tinyint UNSIGNED NOT NULL DEFAULT '3' COMMENT 'salvataggi streak disponibili',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_streak_records`
--

CREATE TABLE `habit_streak_records` (
  `habit_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `best_streak` int NOT NULL DEFAULT '0',
  `best_streak_end` date DEFAULT NULL,
  `total_checkins` int NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `habit_user_achievements`
--

CREATE TABLE `habit_user_achievements` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `achievement_id` int UNSIGNED NOT NULL,
  `habit_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL se il traguardo è globale',
  `value_reached` int NOT NULL DEFAULT '0',
  `unlocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_seen` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `images`
--

CREATE TABLE `images` (
  `id` int NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `description_it` text,
  `description_en` text,
  `meaning` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `example` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `category_id` int UNSIGNED DEFAULT NULL,
  `is_father` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int NOT NULL,
  `identifier` varchar(255) NOT NULL COMMENT 'email o username usato nel tentativo',
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pill_doses`
--

CREATE TABLE `pill_doses` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `due_time` time NOT NULL,
  `seq` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT 'quale delle prese previste per quell ora',
  `status` enum('pending','taken','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `taken_at` datetime DEFAULT NULL COMMENT 'l ora vera della spunta, che non è quella prevista',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pill_plans`
--

CREATE TABLE `pill_plans` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dose` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'mezza compressa, 10 gocce, 1 bustina',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'a stomaco pieno, lontano dal caffè...',
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#B7A6D6',
  `freq_type` enum('daily','monthly','interval') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily' COMMENT 'daily = certi giorni della settimana; monthly = certi giorni del mese; interval = ogni N giorni',
  `weekdays` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1111111' COMMENT 'lun..dom, come in habits',
  `month_days` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'giorni del mese separati da virgola: 13,27',
  `interval_days` smallint UNSIGNED NOT NULL DEFAULT '1' COMMENT 'ogni quanti giorni, contati dalla data di inizio',
  `times` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '08:00x1' COMMENT 'orari e quante volte per orario: 10:00x2,20:00x1',
  `schedule_from` date DEFAULT NULL COMMENT 'da quando vale l orario corrente: prima di questa data le prese non si rigenerano',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'NULL = a tempo indeterminato; una cura di sette giorni finisce da sola',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `place_cache`
--

CREATE TABLE `place_cache` (
  `id` int UNSIGNED NOT NULL,
  `q_key` char(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha1 della ricerca in minuscolo',
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'risultati già ripuliti, in JSON',
  `fetched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_calendars`
--

CREATE TABLE `planner_calendars` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL = nessun colore proprio (il principale)',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = ci finiscono gli eventi senza calendario scelto',
  `google_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'calendario Google da cui arrivano questi eventi',
  `google_push_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'id del calendario Google in cui mandare questi eventi; NULL = quello principale',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `keywords` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `def_repeat` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `def_notify` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ruolo speciale: study = ci vivono i blocchi del to study. NULL = calendario normale'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_categories`
--

CREATE TABLE `planner_categories` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#C8DAD3',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'filtro rapido: 1 = eventi nascosti',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ruolo speciale: study = categoria degli eventi di studio'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_category_keywords`
--

CREATE TABLE `planner_category_keywords` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `keyword` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'salvata minuscola, senza accenti doppi',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_events`
--

CREATE TABLE `planner_events` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `place_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place_lat` decimal(10,7) DEFAULT NULL,
  `place_lon` decimal(10,7) DEFAULT NULL,
  `place_ref` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'identificativo del luogo restituito dal servizio di mappe',
  `duration_locked` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'lucchetto: 1 = durata fissa, spostando un estremo si muove l altro',
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'indirizzo o nome del posto',
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'ultimo giorno dell evento; NULL = finisce nello stesso giorno in cui comincia',
  `start_time` time NOT NULL DEFAULT '09:00:00',
  `end_time` time NOT NULL DEFAULT '10:00:00',
  `is_all_day` tinyint(1) NOT NULL DEFAULT '0',
  `category_id` int UNSIGNED DEFAULT NULL,
  `calendar_id` int UNSIGNED DEFAULT NULL COMMENT 'calendario di appartenenza',
  `project_id` int DEFAULT NULL,
  `study_exam_id` int DEFAULT NULL COMMENT 'esame di university_exams a cui questo evento si riferisce',
  `habit_id` int UNSIGNED DEFAULT NULL COMMENT 'evento generato da un habit: la spunta si sincronizza con habit_logs',
  `routine_id` int UNSIGNED DEFAULT NULL,
  `routine_group` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'stesso valore per tutti i pezzi di un blocco routine inserito',
  `routine_collapsed` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = blocco unico, 0 = eventi separati',
  `importance` enum('normal','high','highest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL = eredita da categoria/progetto',
  `item_type` enum('event','task') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'event',
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  `completed_at` datetime DEFAULT NULL,
  `notify_type` enum('none','at_time','m30','h1','d1','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'h1',
  `notify_minutes` int NOT NULL DEFAULT '60' COMMENT 'minuti di anticipo effettivi, calcolati anche per i preset',
  `repeat_type` enum('none','daily','weekly','monthly','yearly','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `repeat_interval` smallint UNSIGNED NOT NULL DEFAULT '1',
  `repeat_unit` enum('day','week','month','year') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'week',
  `repeat_until` date DEFAULT NULL,
  `series_id` bigint UNSIGNED DEFAULT NULL COMMENT 'id del primo evento della serie ripetuta',
  `google_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_calendar_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'calendario Google in cui vive questo evento',
  `google_source_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'calendario Google da cui è stato importato',
  `google_synced_at` datetime DEFAULT NULL COMMENT 'ultimo allineamento con Google',
  `google_origin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = nato su Google, non nel planner',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'ordine tra eventi sovrapposti / eventi tutto il giorno',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rrule` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_date` date DEFAULT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_places`
--

CREATE TABLE `planner_places` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `label` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'come lo chiami tu: casa, lavoro, palestra',
  `keywords` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'parole separate da virgola: se una compare nel titolo, il luogo si aggancia da solo',
  `place_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'nome del posto come lo chiama la mappa',
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'testo da scrivere nel campo luogo dell evento',
  `place_lat` decimal(10,7) DEFAULT NULL,
  `place_lon` decimal(10,7) DEFAULT NULL,
  `place_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'identificativo del luogo sul servizio di mappe',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_priorities`
--

CREATE TABLE `planner_priorities` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `priority_date` date NOT NULL,
  `position` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1..6, due per riga',
  `content` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_routines`
--

CREATE TABLE `planner_routines` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#E3D9EE',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_routine_items`
--

CREATE TABLE `planner_routine_items` (
  `id` int UNSIGNED NOT NULL,
  `routine_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_minutes` smallint UNSIGNED NOT NULL DEFAULT '5',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_settings`
--

CREATE TABLE `planner_settings` (
  `user_id` int NOT NULL,
  `palette_key` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sage' COMMENT 'sage | mint | blush | lavender | butter | sky | clay',
  `default_zoom` enum('agenda','h3','h1','m30','m15','m5') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'm30',
  `day_start_hour` tinyint UNSIGNED NOT NULL DEFAULT '6' COMMENT 'primo orario mostrato allo scroll iniziale',
  `show_motivational` tinyint(1) NOT NULL DEFAULT '1',
  `show_affirmations` tinyint(1) NOT NULL DEFAULT '1',
  `default_view` enum('day','month') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'day',
  `first_week_start` date DEFAULT NULL COMMENT 'lunedi della settimana 1 del planner',
  `first_week_number` int NOT NULL DEFAULT '1',
  `ambient_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'insetti/alberi animati nella barra',
  `ambient_opacity` tinyint UNSIGNED NOT NULL DEFAULT '9' COMMENT 'quanto si vede la scena del mese: 0 invisibile, 10 piena',
  `contrast_level` tinyint UNSIGNED NOT NULL DEFAULT '3' COMMENT '1 = molto tenue ... 5 = molto marcato',
  `days_visible` tinyint UNSIGNED NOT NULL DEFAULT '3' COMMENT 'quanti giorni affiancati su schermi larghi (1..7)',
  `google_sync_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `gc_dedicated_mode` enum('none','all','high','highest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all' COMMENT 'quali eventi vanno nel calendario dedicato',
  `gc_primary_mode` enum('none','high','highest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'quali eventi vanno invece nel calendario principale di Google',
  `gc_push_high` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gc_push_highest` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weather_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = modalità meteo attiva',
  `weather_lat` decimal(8,5) DEFAULT NULL,
  `weather_lon` decimal(8,5) DEFAULT NULL,
  `weather_place` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'nome della località, se lo ricaviamo',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fabs_open` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'colonna dei tastini a destra: 1 aperta, 0 richiusa sotto le abitudini',
  `study_filtri` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON: cosa mostrare nel to study oltre ai blocchi di studio',
  `progetti_opzioni` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON: come mostrare la pagina progetti (completati, ordine, tag, rami)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_weeks`
--

CREATE TABLE `planner_weeks` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `week_start` date NOT NULL COMMENT 'sempre il lunedi',
  `week_number` int NOT NULL DEFAULT '1' COMMENT 'progressivo del planner, modificabile a mano',
  `number_edited` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = impostato a mano: da qui in avanti si ricalcola',
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  `learned_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'cosa ho imparato questa settimana',
  `quote_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'frase motivazionale estratta dal json',
  `affirmation_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_week_lists`
--

CREATE TABLE `planner_week_lists` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `week_start` date NOT NULL,
  `list_type` enum('todo','buy','study') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planner_week_list_items`
--

CREATE TABLE `planner_week_list_items` (
  `id` bigint UNSIGNED NOT NULL,
  `list_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `content` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `plans`
--

CREATE TABLE `plans` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL COMMENT 'es. free, premium, pro',
  `monthly_price` decimal(6,2) NOT NULL DEFAULT '0.00',
  `audio_chars_limit` int DEFAULT NULL COMMENT 'Caratteri TTS utilizzabili al mese. NULL = illimitato',
  `images_limit` int DEFAULT NULL COMMENT 'Immagini generabili con IA al mese. NULL = illimitato',
  `text_tokens_limit` int DEFAULT NULL COMMENT 'Token testuali IA (Gemini) al mese. NULL = illimitato',
  `free_trial_days` int NOT NULL DEFAULT '0',
  `description` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pomodoro_current_breaks`
--

CREATE TABLE `pomodoro_current_breaks` (
  `user_id` int NOT NULL,
  `start_time` datetime NOT NULL,
  `planned_duration_min` int NOT NULL,
  `project_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pomodoro_custom_durations`
--

CREATE TABLE `pomodoro_custom_durations` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `minutes` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pomodoro_sessions`
--

CREATE TABLE `pomodoro_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `mode` tinyint(1) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resume_time` datetime DEFAULT NULL,
  `duration_seconds` int DEFAULT NULL,
  `planned_duration_min` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `previous_session_id` int DEFAULT NULL,
  `root_session_id` int DEFAULT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pomodoro_settings`
--

CREATE TABLE `pomodoro_settings` (
  `user_id` int NOT NULL,
  `last_mode` tinyint(1) NOT NULL DEFAULT '0',
  `last_pomodoro_duration_min` int NOT NULL DEFAULT '25',
  `last_break_duration_min` int NOT NULL DEFAULT '5',
  `last_display_style` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cerchio'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `projects`
--

CREATE TABLE `projects` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `study_event_id` bigint UNSIGNED DEFAULT NULL COMMENT 'evento di studio di cui questo progetto e un sotto-task; NULL = progetto normale',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#007AFF',
  `color_edited` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `custom_order` int NOT NULL DEFAULT '0',
  `todo_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = mostra la sezione attività dentro il progetto',
  `is_favorite` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = preferito: compare nel selettore e nel planner',
  `study_start` date DEFAULT NULL COMMENT 'primo giorno del sotto-task, sempre dentro il periodo dell evento padre',
  `study_end` date DEFAULT NULL COMMENT 'ultimo giorno; NULL = finisce dove comincia, come su planner_events',
  `study_pomodori_stimati` smallint UNSIGNED DEFAULT NULL COMMENT 'quanti pomodori l utente pensa che servano: il confronto con quelli fatti misura quanto sa stimarsi',
  `study_ripasso_di` int DEFAULT NULL COMMENT 'argomento di cui questo e un ripasso; NULL = argomento normale',
  `study_ripasso_n` tinyint UNSIGNED DEFAULT NULL COMMENT 'quale ripasso e: 1 = a un giorno, 2 = a tre, 3 = a sette',
  `study_fc_categoria` int UNSIGNED DEFAULT NULL COMMENT 'mazzo di flashcard collegato: giocandolo bene il ripasso si spunta da se'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `project_fc_categories`
--

CREATE TABLE `project_fc_categories` (
  `project_id` int NOT NULL,
  `fc_category_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `p256dh_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` bigint UNSIGNED NOT NULL,
  `bucket` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'es. planner_write:42 oppure register:1.2.3.4',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `selector` varchar(24) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `roles`
--

CREATE TABLE `roles` (
  `id` tinyint UNSIGNED NOT NULL,
  `level` tinyint UNSIGNED NOT NULL COMMENT '0 = base, valori piu alti = piu permessi',
  `code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `security_audit_log`
--

CREATE TABLE `security_audit_log` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int DEFAULT NULL,
  `action` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` enum('ok','denied','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `detail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `session_durations`
--

CREATE TABLE `session_durations` (
  `id` int UNSIGNED NOT NULL,
  `game_id` int UNSIGNED NOT NULL,
  `start_time` datetime NOT NULL,
  `duration_seconds` int UNSIGNED NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `study_templates`
--

CREATE TABLE `study_templates` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `voci` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON: [{nome, giorni, pomodori}, ...] nell ordine di studio',
  `usato` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'quante volte e stato applicato',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `todos`
--

CREATE TABLE `todos` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `list_type` enum('todo','buy','study') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo',
  `parent_id` bigint UNSIGNED DEFAULT NULL COMMENT 'sotto-attività: punta a un altro todo',
  `project_id` int DEFAULT NULL COMMENT 'progetto collegato',
  `buy_category_id` int UNSIGNED DEFAULT NULL,
  `buy_priority` tinyint UNSIGNED NOT NULL DEFAULT '3' COMMENT '1 = più alta, 3 = più bassa',
  `buy_price_cents` int UNSIGNED DEFAULT NULL COMMENT 'in centesimi: i totali devono tornare al centesimo',
  `buy_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buy_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'nome del file caricato, non il percorso completo',
  `title` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `done_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL COMMENT 'quando va fatta. NULL = senza data',
  `due_time` time DEFAULT NULL COMMENT 'a che ora. NULL = tutto il giorno',
  `duration_minutes` smallint UNSIGNED NOT NULL DEFAULT '30',
  `sort_order` int NOT NULL DEFAULT '0',
  `source` enum('page','project','pomodoro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'page' COMMENT 'da dove è stata creata',
  `planner_event_id` bigint UNSIGNED DEFAULT NULL COMMENT 'evento del planner collegato, se ha una data',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `todo_buy_categories`
--

CREATE TABLE `todo_buy_categories` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#B7AEA3',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `university_bonuses`
--

CREATE TABLE `university_bonuses` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `bonus_type` varchar(20) NOT NULL DEFAULT 'fixed',
  `points` decimal(4,2) NOT NULL DEFAULT '0.00',
  `threshold_value` int DEFAULT NULL COMMENT 'Es. numero di lodi richiesto',
  `cap_points` decimal(4,2) NOT NULL DEFAULT '0.00' COMMENT 'Tetto massimo (0 = nessun tetto)',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `university_exams`
--

CREATE TABLE `university_exams` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(200) NOT NULL,
  `cfu` decimal(4,1) NOT NULL DEFAULT '0.0',
  `status` varchar(10) NOT NULL DEFAULT 'todo' COMMENT 'todo | done',
  `grade` tinyint DEFAULT NULL COMMENT '18..30, NULL se non valutato o idoneità',
  `has_honors` tinyint(1) NOT NULL DEFAULT '0' COMMENT '30 e lode',
  `is_pass_fail` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Idoneità / senza voto',
  `counts_for_graduation` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Se i CFU concorrono alla laurea',
  `exam_date` date DEFAULT NULL COMMENT 'Data superamento (per l''andamento)',
  `predicted_grade` tinyint DEFAULT NULL COMMENT 'Voto stimato per simulazione/proiezione',
  `predicted_honors` tinyint(1) NOT NULL DEFAULT '0',
  `catalog_code` varchar(60) DEFAULT NULL COMMENT 'Riferimento all''esame nel catalogo JSON',
  `ssd` varchar(20) DEFAULT NULL COMMENT 'Settore scientifico disciplinare',
  `study_year` tinyint DEFAULT NULL COMMENT 'Anno di corso (1/2/3...)',
  `national_avg` decimal(4,2) DEFAULT NULL COMMENT 'Media di corso indicativa (stile AlmaLaurea)',
  `group_id` int DEFAULT NULL COMMENT 'Gruppo esami a scelta: i CFU del gruppo contano una sola volta',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `university_exam_groups`
--

CREATE TABLE `university_exam_groups` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT 'Gruppo',
  `group_type` enum('choice','split') NOT NULL DEFAULT 'choice',
  `cfu` decimal(4,1) NOT NULL DEFAULT '0.0' COMMENT 'CFU conteggiati una sola volta per tutto il gruppo',
  `overall_grade` tinyint DEFAULT NULL,
  `overall_honors` tinyint(1) NOT NULL DEFAULT '0',
  `overall_predicted_grade` tinyint DEFAULT NULL,
  `overall_predicted_honors` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `university_settings`
--

CREATE TABLE `university_settings` (
  `user_id` int NOT NULL,
  `program_key` varchar(80) DEFAULT NULL COMMENT 'Chiave del corso in exams_catalog.json (NULL = personalizzato)',
  `program_label` varchar(160) DEFAULT NULL COMMENT 'Nome del corso di laurea mostrato',
  `faculty_key` varchar(60) DEFAULT NULL COMMENT 'Chiave facoltà per i colori (es. psychology, engineering)',
  `accent_color` varchar(20) NOT NULL DEFAULT '#0A84FF' COMMENT 'Colore accento della pagina (deriva dalla facoltà, personalizzabile)',
  `total_cfu` int NOT NULL DEFAULT '180' COMMENT 'CFU necessari alla laurea',
  `grade_base` int NOT NULL DEFAULT '110' COMMENT 'Base di laurea (di norma 110)',
  `honors_value` decimal(4,2) NOT NULL DEFAULT '30.00' COMMENT 'Quanto vale un 30 e lode nella media ponderata (30, 30.5, 31, 33...)',
  `target_average` decimal(5,2) DEFAULT NULL COMMENT 'Obiettivo espresso come media esami (/30)',
  `target_source` varchar(12) NOT NULL DEFAULT 'manual' COMMENT 'manual | simulation',
  `thesis_min_points` decimal(4,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti minimi della tesi',
  `thesis_max_points` decimal(4,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti massimi della tesi',
  `round_half_up` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Arrotondamento base/110: 1 = commerciale, 0 = tronca',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `lang` varchar(5) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `user_learnt_english`
--

CREATE TABLE `user_learnt_english` (
  `user_id` int NOT NULL,
  `image_id` int UNSIGNED NOT NULL,
  `learnt_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `user_learnt_fc`
--

CREATE TABLE `user_learnt_fc` (
  `user_id` int NOT NULL,
  `fc_image_id` int NOT NULL,
  `learnt_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `user_plans`
--

CREATE TABLE `user_plans` (
  `user_id` int NOT NULL,
  `piano_id` int NOT NULL DEFAULT '1',
  `subscribed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Da quando ha questo piano',
  `free_trial_used` tinyint(1) NOT NULL DEFAULT '0',
  `monthly_audio_chars` int NOT NULL DEFAULT '0' COMMENT 'Caratteri audio (TTS) usati nel mese di mese_riferimento',
  `monthly_audio_calls` int NOT NULL DEFAULT '0' COMMENT 'Numero di richieste TTS effettuate nel mese di reference_month',
  `monthly_generated_images` int NOT NULL DEFAULT '0' COMMENT 'Immagini IA generate nel mese di mese_riferimento',
  `monthly_generated_images_calls` int NOT NULL DEFAULT '0' COMMENT 'Numero di immagini IA generate (chiamate) nel mese di reference_month',
  `monthly_text_tokens` int NOT NULL DEFAULT '0' COMMENT 'Token testuali IA usati nel mese di mese_riferimento',
  `monthly_text_tokens_calls` int NOT NULL DEFAULT '0' COMMENT 'Numero di chiamate testuali IA (Gemini) effettuate nel mese di reference_month',
  `reference_month` date NOT NULL COMMENT 'Primo giorno del mese a cui si riferiscono i 3 contatori: permette di azzerarli automaticamente al cambio mese',
  `daily_goal` int NOT NULL DEFAULT '1',
  `historical_avg_tokens_per_call` decimal(10,2) DEFAULT NULL COMMENT 'Media storica (media delle medie mensili) di token spesi per chiamata IA testuale'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int NOT NULL,
  `role_level` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int DEFAULT NULL COMMENT 'user_id di chi ha assegnato il ruolo, NULL = automatico'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `weather_cache`
--

CREATE TABLE `weather_cache` (
  `id` int UNSIGNED NOT NULL,
  `grid_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'coordinate arrotondate: la cache è condivisa fra utenti vicini',
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'risposta del servizio, in JSON',
  `fetched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `ai_perf_stats`
--
ALTER TABLE `ai_perf_stats`
  ADD PRIMARY KEY (`mode`);

--
-- Indici per le tabelle `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contact_email` (`user_id`,`email`),
  ADD KEY `idx_contact_name` (`user_id`,`name`);

--
-- Indici per le tabelle `diary_list_items`
--
ALTER TABLE `diary_list_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dli_user_date` (`user_id`,`entry_date`,`list_type`,`sort_order`);

--
-- Indici per le tabelle `diary_settings`
--
ALTER TABLE `diary_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `english_child_words`
--
ALTER TABLE `english_child_words`
  ADD PRIMARY KEY (`id_child`),
  ADD KEY `fk_english_child_parent` (`parent_id`);

--
-- Indici per le tabelle `event_guests`
--
ALTER TABLE `event_guests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_guest` (`event_id`,`email`),
  ADD KEY `idx_guest_user` (`user_id`);

--
-- Indici per le tabelle `fc_ai_data`
--
ALTER TABLE `fc_ai_data`
  ADD PRIMARY KEY (`card_id`);

--
-- Indici per le tabelle `fc_categories`
--
ALTER TABLE `fc_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `fc_child_cards`
--
ALTER TABLE `fc_child_cards`
  ADD PRIMARY KEY (`id_child`);

--
-- Indici per le tabelle `fc_examples`
--
ALTER TABLE `fc_examples`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `fc_gameplays`
--
ALTER TABLE `fc_gameplays`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fc_gameplays_game` (`game_id`),
  ADD KEY `idx_fc_gameplays_fc_image` (`fc_image_id`),
  ADD KEY `fk_fc_gameplays_fc_child` (`fc_child_id`);

--
-- Indici per le tabelle `fc_images`
--
ALTER TABLE `fc_images`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `fc_user_goals`
--
ALTER TABLE `fc_user_goals`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `friends`
--
ALTER TABLE `friends`
  ADD PRIMARY KEY (`user_id_a`,`user_id_b`),
  ADD KEY `idx_friends_b` (`user_id_b`);

--
-- Indici per le tabelle `friends_notifications`
--
ALTER TABLE `friends_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_notification` (`from_user_id`,`to_user_id`,`is_accepted`),
  ADD KEY `idx_fn_to` (`to_user_id`,`is_read`),
  ADD KEY `idx_fn_from` (`from_user_id`);

--
-- Indici per le tabelle `gameplays`
--
ALTER TABLE `gameplays`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idimmagine` (`image_id`),
  ADD KEY `idpartita` (`game_id`),
  ADD KEY `fk_gameplays_child` (`child_id`);

--
-- Indici per le tabelle `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `game_categories`
--
ALTER TABLE `game_categories`
  ADD PRIMARY KEY (`game_id`,`category_id`),
  ADD KEY `fk_gc_category` (`category_id`);

--
-- Indici per le tabelle `game_fc_categories`
--
ALTER TABLE `game_fc_categories`
  ADD PRIMARY KEY (`game_id`,`fc_category_id`),
  ADD KEY `fk_gfc_category` (`fc_category_id`);

--
-- Indici per le tabelle `google_calendar_accounts`
--
ALTER TABLE `google_calendar_accounts`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `google_calendar_map`
--
ALTER TABLE `google_calendar_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gcm` (`user_id`,`google_ref`),
  ADD KEY `idx_gcm_cal` (`calendar_id`);

--
-- Indici per le tabelle `google_sync_log`
--
ALTER TABLE `google_sync_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gsl_user` (`user_id`,`created_at`);

--
-- Indici per le tabelle `google_sync_queue`
--
ALTER TABLE `google_sync_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gsq_user` (`user_id`,`created_at`);

--
-- Indici per le tabelle `habits`
--
ALTER TABLE `habits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_habits_user` (`user_id`,`is_archived`,`sort_order`),
  ADD KEY `idx_habits_category` (`category_id`);

--
-- Indici per le tabelle `habit_achievements`
--
ALTER TABLE `habit_achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_hach_code` (`code`);

--
-- Indici per le tabelle `habit_categories`
--
ALTER TABLE `habit_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_hcat_user_slug` (`user_id`,`slug`),
  ADD KEY `idx_hcat_user` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `habit_daily_journal`
--
ALTER TABLE `habit_daily_journal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_hjournal_user_date` (`user_id`,`entry_date`);

--
-- Indici per le tabelle `habit_logs`
--
ALTER TABLE `habit_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_hlog_habit_date` (`habit_id`,`log_date`),
  ADD KEY `idx_hlog_user_date` (`user_id`,`log_date`),
  ADD KEY `idx_hlog_date_status` (`log_date`,`status`);

--
-- Indici per le tabelle `habit_log_events`
--
ALTER TABLE `habit_log_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hev_habit_date` (`habit_id`,`log_date`),
  ADD KEY `idx_hev_user_time` (`user_id`,`created_at`);

--
-- Indici per le tabelle `habit_reminders`
--
ALTER TABLE `habit_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hrem_habit` (`habit_id`),
  ADD KEY `idx_hrem_due` (`is_enabled`,`remind_at`),
  ADD KEY `fk_hrem_user` (`user_id`);

--
-- Indici per le tabelle `habit_settings`
--
ALTER TABLE `habit_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `habit_streak_records`
--
ALTER TABLE `habit_streak_records`
  ADD PRIMARY KEY (`habit_id`),
  ADD KEY `idx_hsr_user` (`user_id`);

--
-- Indici per le tabelle `habit_user_achievements`
--
ALTER TABLE `habit_user_achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_hua` (`user_id`,`achievement_id`,`habit_id`),
  ADD KEY `idx_hua_user` (`user_id`,`unlocked_at`),
  ADD KEY `fk_hua_ach` (`achievement_id`),
  ADD KEY `fk_hua_habit` (`habit_id`);

--
-- Indici per le tabelle `images`
--
ALTER TABLE `images`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_identifier_time` (`identifier`,`attempted_at`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempted_at`);

--
-- Indici per le tabelle `pill_doses`
--
ALTER TABLE `pill_doses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `presa_unica` (`plan_id`,`due_date`,`due_time`,`seq`),
  ADD KEY `user_giorno` (`user_id`,`due_date`);

--
-- Indici per le tabelle `pill_plans`
--
ALTER TABLE `pill_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indici per le tabelle `place_cache`
--
ALTER TABLE `place_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ricerca` (`q_key`),
  ADD KEY `idx_eta` (`fetched_at`);

--
-- Indici per le tabelle `planner_calendars`
--
ALTER TABLE `planner_calendars`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cal_user` (`user_id`,`sort_order`),
  ADD KEY `idx_cal_google` (`user_id`,`google_ref`(64));

--
-- Indici per le tabelle `planner_categories`
--
ALTER TABLE `planner_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pcat_user` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `planner_category_keywords`
--
ALTER TABLE `planner_category_keywords`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pkw_user_keyword` (`user_id`,`keyword`) COMMENT 'una parola appartiene a una sola categoria',
  ADD KEY `idx_pkw_cat` (`category_id`);

--
-- Indici per le tabelle `planner_events`
--
ALTER TABLE `planner_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pev_user_date` (`user_id`,`event_date`),
  ADD KEY `idx_pev_user_title` (`user_id`,`title`(40)),
  ADD KEY `idx_pev_series` (`series_id`),
  ADD KEY `idx_pev_habit` (`habit_id`,`event_date`),
  ADD KEY `idx_pev_project` (`project_id`),
  ADD KEY `idx_pev_google` (`google_event_id`(64)),
  ADD KEY `idx_pev_gsync` (`user_id`,`google_synced_at`),
  ADD KEY `idx_pev_calendar` (`user_id`,`calendar_id`),
  ADD KEY `idx_gc_event` (`user_id`,`google_event_id`),
  ADD KEY `idx_pev_gsource` (`user_id`,`google_source_ref`),
  ADD KEY `idx_pe_user_periodo` (`user_id`,`event_date`,`end_date`),
  ADD KEY `idx_pe_esame` (`user_id`,`study_exam_id`);

--
-- Indici per le tabelle `planner_places`
--
ALTER TABLE `planner_places`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indici per le tabelle `planner_priorities`
--
ALTER TABLE `planner_priorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pprio_slot` (`user_id`,`priority_date`,`position`);

--
-- Indici per le tabelle `planner_routines`
--
ALTER TABLE `planner_routines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prout_user` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `planner_routine_items`
--
ALTER TABLE `planner_routine_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prit_routine` (`routine_id`,`sort_order`),
  ADD KEY `idx_prit_user` (`user_id`);

--
-- Indici per le tabelle `planner_settings`
--
ALTER TABLE `planner_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `planner_weeks`
--
ALTER TABLE `planner_weeks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pweek` (`user_id`,`week_start`);

--
-- Indici per le tabelle `planner_week_lists`
--
ALTER TABLE `planner_week_lists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pwlist` (`user_id`,`week_start`,`list_type`);

--
-- Indici per le tabelle `planner_week_list_items`
--
ALTER TABLE `planner_week_list_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pwli_list` (`list_id`,`sort_order`),
  ADD KEY `idx_pwli_user` (`user_id`);

--
-- Indici per le tabelle `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `pomodoro_current_breaks`
--
ALTER TABLE `pomodoro_current_breaks`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_pausa_progetto` (`project_id`);

--
-- Indici per le tabelle `pomodoro_custom_durations`
--
ALTER TABLE `pomodoro_custom_durations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_durata_utente` (`user_id`,`type`,`minutes`),
  ADD KEY `idx_durate_user` (`user_id`);

--
-- Indici per le tabelle `pomodoro_sessions`
--
ALTER TABLE `pomodoro_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessioni_user_inizio` (`user_id`,`start_time`),
  ADD KEY `idx_sessioni_progetto` (`project_id`),
  ADD KEY `idx_sessioni_durata` (`duration_seconds`),
  ADD KEY `idx_sessioni_precedente` (`previous_session_id`),
  ADD KEY `idx_sessioni_radice` (`root_session_id`);

--
-- Indici per le tabelle `pomodoro_settings`
--
ALTER TABLE `pomodoro_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_progetti_user` (`user_id`),
  ADD KEY `idx_progetti_parent_order` (`parent_id`,`custom_order`),
  ADD KEY `idx_proj_fav` (`user_id`,`is_favorite`),
  ADD KEY `idx_proj_study_evento` (`user_id`,`study_event_id`,`study_start`),
  ADD KEY `idx_proj_ripasso` (`user_id`,`study_ripasso_di`);

--
-- Indici per le tabelle `project_fc_categories`
--
ALTER TABLE `project_fc_categories`
  ADD PRIMARY KEY (`project_id`),
  ADD UNIQUE KEY `uniq_fc_category` (`fc_category_id`);

--
-- Indici per le tabelle `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_push_endpoint` (`endpoint`),
  ADD KEY `idx_push_user` (`user_id`);

--
-- Indici per le tabelle `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rate_bucket_time` (`bucket`,`created_at`);

--
-- Indici per le tabelle `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_selector` (`selector`),
  ADD KEY `idx_remember_tokens_user_id` (`user_id`);

--
-- Indici per le tabelle `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_level` (`level`),
  ADD UNIQUE KEY `uq_roles_code` (`code`);

--
-- Indici per le tabelle `security_audit_log`
--
ALTER TABLE `security_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_audit_action` (`action`,`created_at`);

--
-- Indici per le tabelle `session_durations`
--
ALTER TABLE `session_durations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_durata_sessione_game` (`game_id`);

--
-- Indici per le tabelle `study_templates`
--
ALTER TABLE `study_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_st_user` (`user_id`,`name`);

--
-- Indici per le tabelle `todos`
--
ALTER TABLE `todos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_todo_user_due` (`user_id`,`due_date`,`due_time`),
  ADD KEY `idx_todo_user_done` (`user_id`,`is_done`,`done_at`),
  ADD KEY `idx_todo_parent` (`parent_id`),
  ADD KEY `idx_todo_project` (`project_id`),
  ADD KEY `idx_todo_event` (`planner_event_id`),
  ADD KEY `idx_lista` (`user_id`,`list_type`,`is_done`),
  ADD KEY `idx_buy` (`user_id`,`list_type`,`buy_category_id`,`buy_priority`);

--
-- Indici per le tabelle `todo_buy_categories`
--
ALTER TABLE `todo_buy_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_utente_nome` (`user_id`,`name`),
  ADD KEY `idx_utente` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `university_bonuses`
--
ALTER TABLE `university_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_unibonus_user_order` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `university_exams`
--
ALTER TABLE `university_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_uniexams_user_order` (`user_id`,`sort_order`),
  ADD KEY `idx_uniexams_user_status` (`user_id`,`status`),
  ADD KEY `idx_uniexams_group` (`group_id`);

--
-- Indici per le tabelle `university_exam_groups`
--
ALTER TABLE `university_exam_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_unigroups_user` (`user_id`,`sort_order`);

--
-- Indici per le tabelle `university_settings`
--
ALTER TABLE `university_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uniq_email` (`email`);

--
-- Indici per le tabelle `user_learnt_english`
--
ALTER TABLE `user_learnt_english`
  ADD PRIMARY KEY (`user_id`,`image_id`),
  ADD KEY `image_id` (`image_id`);

--
-- Indici per le tabelle `user_learnt_fc`
--
ALTER TABLE `user_learnt_fc`
  ADD PRIMARY KEY (`user_id`,`fc_image_id`);

--
-- Indici per le tabelle `user_plans`
--
ALTER TABLE `user_plans`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_user_piano_piano_id` (`piano_id`);

--
-- Indici per le tabelle `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_user_roles_level` (`role_level`);

--
-- Indici per le tabelle `weather_cache`
--
ALTER TABLE `weather_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_weather_grid` (`grid_key`),
  ADD KEY `idx_weather_time` (`fetched_at`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `diary_list_items`
--
ALTER TABLE `diary_list_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `english_child_words`
--
ALTER TABLE `english_child_words`
  MODIFY `id_child` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `event_guests`
--
ALTER TABLE `event_guests`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `fc_categories`
--
ALTER TABLE `fc_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `fc_child_cards`
--
ALTER TABLE `fc_child_cards`
  MODIFY `id_child` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `fc_examples`
--
ALTER TABLE `fc_examples`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `fc_gameplays`
--
ALTER TABLE `fc_gameplays`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `fc_images`
--
ALTER TABLE `fc_images`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `friends_notifications`
--
ALTER TABLE `friends_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `gameplays`
--
ALTER TABLE `gameplays`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `games`
--
ALTER TABLE `games`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `google_calendar_map`
--
ALTER TABLE `google_calendar_map`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `google_sync_log`
--
ALTER TABLE `google_sync_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `google_sync_queue`
--
ALTER TABLE `google_sync_queue`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habits`
--
ALTER TABLE `habits`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_achievements`
--
ALTER TABLE `habit_achievements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_categories`
--
ALTER TABLE `habit_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_daily_journal`
--
ALTER TABLE `habit_daily_journal`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_logs`
--
ALTER TABLE `habit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_log_events`
--
ALTER TABLE `habit_log_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_reminders`
--
ALTER TABLE `habit_reminders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `habit_user_achievements`
--
ALTER TABLE `habit_user_achievements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `images`
--
ALTER TABLE `images`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pill_doses`
--
ALTER TABLE `pill_doses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pill_plans`
--
ALTER TABLE `pill_plans`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `place_cache`
--
ALTER TABLE `place_cache`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_calendars`
--
ALTER TABLE `planner_calendars`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_categories`
--
ALTER TABLE `planner_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_category_keywords`
--
ALTER TABLE `planner_category_keywords`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_events`
--
ALTER TABLE `planner_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_places`
--
ALTER TABLE `planner_places`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_priorities`
--
ALTER TABLE `planner_priorities`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_routines`
--
ALTER TABLE `planner_routines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_routine_items`
--
ALTER TABLE `planner_routine_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_weeks`
--
ALTER TABLE `planner_weeks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_week_lists`
--
ALTER TABLE `planner_week_lists`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planner_week_list_items`
--
ALTER TABLE `planner_week_list_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pomodoro_custom_durations`
--
ALTER TABLE `pomodoro_custom_durations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pomodoro_sessions`
--
ALTER TABLE `pomodoro_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `security_audit_log`
--
ALTER TABLE `security_audit_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `session_durations`
--
ALTER TABLE `session_durations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `study_templates`
--
ALTER TABLE `study_templates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `todos`
--
ALTER TABLE `todos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `todo_buy_categories`
--
ALTER TABLE `todo_buy_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `university_bonuses`
--
ALTER TABLE `university_bonuses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `university_exams`
--
ALTER TABLE `university_exams`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `university_exam_groups`
--
ALTER TABLE `university_exam_groups`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `weather_cache`
--
ALTER TABLE `weather_cache`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `english_child_words`
--
ALTER TABLE `english_child_words`
  ADD CONSTRAINT `fk_english_child_parent` FOREIGN KEY (`parent_id`) REFERENCES `images` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `fc_gameplays`
--
ALTER TABLE `fc_gameplays`
  ADD CONSTRAINT `fk_fc_gameplays_fc_child` FOREIGN KEY (`fc_child_id`) REFERENCES `fc_child_cards` (`id_child`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fc_gameplays_fc_image` FOREIGN KEY (`fc_image_id`) REFERENCES `fc_images` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fc_gameplays_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `friends`
--
ALTER TABLE `friends`
  ADD CONSTRAINT `fk_friends_user_a` FOREIGN KEY (`user_id_a`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_friends_user_b` FOREIGN KEY (`user_id_b`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `friends_notifications`
--
ALTER TABLE `friends_notifications`
  ADD CONSTRAINT `fk_fn_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fn_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `gameplays`
--
ALTER TABLE `gameplays`
  ADD CONSTRAINT `fk_gameplays_child` FOREIGN KEY (`child_id`) REFERENCES `english_child_words` (`id_child`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gameplays_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gameplays_image` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `game_fc_categories`
--
ALTER TABLE `game_fc_categories`
  ADD CONSTRAINT `fk_gfc_category` FOREIGN KEY (`fc_category_id`) REFERENCES `fc_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gfc_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habits`
--
ALTER TABLE `habits`
  ADD CONSTRAINT `fk_habits_category` FOREIGN KEY (`category_id`) REFERENCES `habit_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_habits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_daily_journal`
--
ALTER TABLE `habit_daily_journal`
  ADD CONSTRAINT `fk_hjournal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_logs`
--
ALTER TABLE `habit_logs`
  ADD CONSTRAINT `fk_hlog_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hlog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_log_events`
--
ALTER TABLE `habit_log_events`
  ADD CONSTRAINT `fk_hev_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_reminders`
--
ALTER TABLE `habit_reminders`
  ADD CONSTRAINT `fk_hrem_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hrem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_settings`
--
ALTER TABLE `habit_settings`
  ADD CONSTRAINT `fk_hset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_streak_records`
--
ALTER TABLE `habit_streak_records`
  ADD CONSTRAINT `fk_hsr_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hsr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `habit_user_achievements`
--
ALTER TABLE `habit_user_achievements`
  ADD CONSTRAINT `fk_hua_ach` FOREIGN KEY (`achievement_id`) REFERENCES `habit_achievements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hua_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `pomodoro_current_breaks`
--
ALTER TABLE `pomodoro_current_breaks`
  ADD CONSTRAINT `fk_pausa_progetto` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pausa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `pomodoro_custom_durations`
--
ALTER TABLE `pomodoro_custom_durations`
  ADD CONSTRAINT `fk_durate_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `pomodoro_sessions`
--
ALTER TABLE `pomodoro_sessions`
  ADD CONSTRAINT `fk_sessioni_precedente` FOREIGN KEY (`previous_session_id`) REFERENCES `pomodoro_sessions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessioni_progetto` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessioni_radice` FOREIGN KEY (`root_session_id`) REFERENCES `pomodoro_sessions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessioni_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `pomodoro_settings`
--
ALTER TABLE `pomodoro_settings`
  ADD CONSTRAINT `fk_impostazioni_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_progetti_parent` FOREIGN KEY (`parent_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_progetti_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `project_fc_categories`
--
ALTER TABLE `project_fc_categories`
  ADD CONSTRAINT `fk_pcf_categoria` FOREIGN KEY (`fc_category_id`) REFERENCES `fc_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pcf_progetto` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `fk_remember_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `session_durations`
--
ALTER TABLE `session_durations`
  ADD CONSTRAINT `fk_durata_sessione_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `university_bonuses`
--
ALTER TABLE `university_bonuses`
  ADD CONSTRAINT `fk_unibonus_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `university_exams`
--
ALTER TABLE `university_exams`
  ADD CONSTRAINT `fk_uniexams_group` FOREIGN KEY (`group_id`) REFERENCES `university_exam_groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_uniexams_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `university_exam_groups`
--
ALTER TABLE `university_exam_groups`
  ADD CONSTRAINT `fk_unigroups_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `university_settings`
--
ALTER TABLE `university_settings`
  ADD CONSTRAINT `fk_unisettings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `user_learnt_english`
--
ALTER TABLE `user_learnt_english`
  ADD CONSTRAINT `user_learnt_english_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `user_plans`
--
ALTER TABLE `user_plans`
  ADD CONSTRAINT `fk_user_piano_piano` FOREIGN KEY (`piano_id`) REFERENCES `plans` (`id`),
  ADD CONSTRAINT `fk_user_piano_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
