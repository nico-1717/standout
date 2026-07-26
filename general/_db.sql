-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Lug 23, 2026 alle 11:53
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
  `question_desc` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `answer_desc` varchar(3000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `question_audio` varchar(255) DEFAULT NULL,
  `answer_audio` varchar(255) DEFAULT NULL,
  `is_father` tinyint(1) NOT NULL DEFAULT '0',
  `custom_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  `progetto_id` int DEFAULT NULL
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
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#007AFF',
  `color_edited` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `custom_order` int NOT NULL DEFAULT '0'
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
  `cfu` decimal(4,1) NOT NULL DEFAULT '0.0' COMMENT 'CFU conteggiati una sola volta per tutto il gruppo',
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
-- Indici per le tabelle `english_child_words`
--
ALTER TABLE `english_child_words`
  ADD PRIMARY KEY (`id_child`),
  ADD KEY `fk_english_child_parent` (`parent_id`);

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
-- Indici per le tabelle `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `pomodoro_current_breaks`
--
ALTER TABLE `pomodoro_current_breaks`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_pausa_progetto` (`progetto_id`);

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
  ADD KEY `idx_progetti_parent_order` (`parent_id`,`custom_order`);

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
-- Indici per le tabelle `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_selector` (`selector`),
  ADD KEY `idx_remember_tokens_user_id` (`user_id`);

--
-- Indici per le tabelle `session_durations`
--
ALTER TABLE `session_durations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_durata_sessione_game` (`game_id`);

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
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `english_child_words`
--
ALTER TABLE `english_child_words`
  MODIFY `id_child` int NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT per la tabella `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `session_durations`
--
ALTER TABLE `session_durations`
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
  ADD CONSTRAINT `fk_pausa_progetto` FOREIGN KEY (`progetto_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
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
