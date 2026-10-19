-- =====================================================================
-- STANDOUT — migrazione del 19/10/2026
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- 1. Lo stile scelto dall'utente per Diario, Planner, To do e To study.
--    NULL = non ha mai scelto: vale lo stile standard.
ALTER TABLE users
  ADD COLUMN ui_style VARCHAR(10) NULL DEFAULT NULL
  COMMENT 'Stile delle pagine planner/diario/to do/to study: standard | paper (NULL = standard)'
  AFTER lang;

-- 2. To study legge "progetti di studio completati questa settimana" da
--    projects.updated_at, che non esisteva: la richiesta andava in errore.
ALTER TABLE projects
  ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  COMMENT 'Ultima modifica (si aggiorna da sola)';
