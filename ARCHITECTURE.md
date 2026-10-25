# STANDOUT — Architecture map (for AI sessions)

Read this first; open `docs/STANDOUT_PROGETTO.md` only for conventions detail, `docs/STANDOUT_PROGETTO_flashcard.md` for flashcards.

## 1. Tech stack & conventions
- PHP 8 procedural, MySQL/MariaDB (mysqli), vanilla JS + CSS. **No build step, no framework, no npm.**
- Every query goes through `lib/security_core.php` helpers: `sec_q`, `sec_one`, `sec_all`, `sec_in`, `sec_int`, `sec_enum`, `sec_h`; writes call `sec_guard_write` (rate limit); JSON via `sec_json`; CSRF via `sec_csrf_token()` + `X-CSRF-Token` header. Never concatenate SQL.
- Every row is scoped by `user_id`; check ownership in every query.
- CSP (set in `security_core.php`) blocks external script/style hosts: load nothing from CDNs; vendor files live in `public/_assets/`.
- i18n: all UI text in `lang/it.json` and `lang/en.json`, used via `t()`, `te()` (echo), `T()` (JS). Keep both files in sync.
- Comments are in Italian and explain *why*, not what. Do not rewrite working code; make minimal, targeted changes.
- Inside `function_exists` blocks use `define()`, not `const`.
- Schema changes: edit `sql/schema.sql` AND add `sql/migrations/YYYY-MM-DD_name.sql`.
- Offline-capable pages use an action queue (provisional ids, replay on reconnect): see planner and Pomodoro.
- Checks before commit: `php -l`, `node --check`, balanced CSS braces, `tools/controlla_colonne.py sql/schema.sql <php files>`, `tools/controlla_csp.py lib/security_core.php <php files>`.

## 2. Database schema (`sql/schema.sql`, primary columns only)
- **Accounts**: `users(id, username, email, lang, ui_style)`, `roles(id, level)`, `user_roles(user_id, role_level)`, `remember_tokens(id, user_id)`, `login_attempts(id)`, `rate_limits(id)`, `security_audit_log(id)`.
- **Billing/AI**: `plans(id)`, `user_plans(user_id, piano_id)`, `ai_credit_ledger(id, user_id, delta)`, `ai_job_steps(id, job_id, seq)`.
- **Planner**: `planner_events(id, user_id, title)`, `planner_calendars`, `planner_categories`, `planner_places`, `planner_priorities(id, user_id, priority_date)`, `planner_weeks(id, user_id, week_start)`, `planner_week_lists`/`_items`, `planner_routines`/`_items`, `planner_settings(user_id)`, `event_guests`, `contacts`.
- **Google sync**: `google_calendar_accounts(user_id)`, `google_calendar_map(id)`, `google_sync_queue(id)`, `google_sync_log(id)`.
- **To do**: `todos(id, user_id, list_type, parent_id, project_id, buy_category_id)`, `todo_buy_categories(id, user_id)`.
- **Diary/habits**: `diary_list_items(id, user_id, entry_date)`, `diary_settings(user_id)`, `habits(id, user_id)`, `habit_logs(id, habit_id, log_date)`, `habit_categories`, `habit_achievements`, `pill_plans(id)`, `pill_doses(id, plan_id)`.
- **Projects/Pomodoro**: `projects(id, user_id, parent_id)`, `pomodoro_sessions(id, user_id, start_time)`, `pomodoro_current_breaks(user_id)`, `pomodoro_settings(user_id)`.
- **Flashcards (study)**: `fc_study_decks(id, user_id)`, `fc_study_cards(id, category_id)`, `fc_study_subcards(id_child, parent_id)`, `fc_study_learnt(user_id, fc_image_id)`, `fc_study_plays`, `fc_study_records(user_id, kind)`.
- **Vocabulary**: same shape with `fc_vocab_*` plus `fc_vocab_courses(id, user_id, known_lang, learn_lang)`; `fc_vocab_records(user_id, course_id, kind)`.
- **Shared flashcards**: `fc_games(id, kind, course_id)`, `fc_game_durations`, `fc_settings(user_id)`, `fc_goals(user_id)`.
- **Other**: `university_exams(id, user_id)`, `university_settings(user_id)`, `friends`, `friends_notifications(id)`, `study_templates(id)`, `push_subscriptions(id)`, `weather_cache`, `place_cache`.

## 3. File map
- `lib/` — shared PHP: `config.php` (DB, secrets), `security_core.php` (sec_* helpers), `auth_*.php` (session/login guard; `_api` variant returns JSON), `i18n.php`, `stile.php` (UI style).
- `lib/fc/` — flashcard domain: `fc_type.php` (study vs vocabulary switch, URLs), `fc_impostazioni.php` (per-user game settings), `fc_vocab_gap.php` (example gap selection), `fc_ai_*.php` (AI prompts and formatting).
- `lib/planner/` — planner domain logic split by topic (events, places, categories, day view).
- `lang/` — Italian and English dictionaries.
- `sql/` — `schema.sql` plus dated `migrations/`.
- `tools/` — Python checkers (CSP, columns, smoke test).
- `public/planner/` — planner, diary, habits, **to do / to buy** (`todo_widget.php` component; `todo_api.php` backend; `todo_barra.js`/`todo_buy.js` quick-add bars; `todo_nota.js` + `todo_nota_ai.php` quick note and AI sorting), Google Calendar sync (`google_calendar.php`), `js/` planner scripts (`04_barra_e_priorita.js` holds the smart bar parser).
- `public/fc/` — flashcards: `fc_game.php` (the game page), `fc_game_start.php` (creates a game), `fc_voice_mode.php/.css` (hands-free voice mode), `fc_speak.js` (text-to-speech helper), `fc_settings_api.php` (game/voice settings).
- `public/projects/` — projects tree and Pomodoro (`pomodoro_core.js` engine, `pomodoro_stato.php` state, `pomodoro_barra.*` global bar).
- `public/ai/` — AI endpoints and the credit/job system (`ai_job_api.php`, `ai_worker.php`, `credits.php`).
- `public/home/`, `public/account/`, `public/social/`, `public/exams/` — dashboard/landing, login, friends, university grades.
- `public/partials/` — sidebar and shared widgets; `public/sw.js` — service worker (offline); `public/index.php` — entry.
