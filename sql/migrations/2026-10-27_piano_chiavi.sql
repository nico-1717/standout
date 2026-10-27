-- Le chiavi esterne delle cinque tabelle del piano (plan_*).
-- Le tabelle le crea da sola la prima richiesta a piano_api.php, senza chiavi:
-- così in produzione, cancellando un utente o un progetto, restavano righe
-- orfane. schema.sql le aveva già, il database no (dump del 27/10/2026).
--
-- 1) si tolgono le righe orfane (l'utente o il progetto non esistono più):
--    senza questo passo la chiave non si può creare, e quelle righe non
--    servono a nessuno perché nessuno può più vederle;
-- 2) si aggiungono le chiavi, ognuna solo se manca: si può eseguire più volte.
--
-- Prima di eseguire, per vedere quante righe verrebbero tolte:
--   SELECT COUNT(*) FROM plan_days  WHERE user_id NOT IN (SELECT id FROM users);
--   SELECT COUNT(*) FROM plan_days  WHERE project_id NOT IN (SELECT id FROM projects);
--   (e lo stesso per plan_goals, plan_paint, plan_settings, plan_templates)

DELETE FROM plan_days      WHERE user_id    NOT IN (SELECT id FROM users);
DELETE FROM plan_days      WHERE project_id NOT IN (SELECT id FROM projects);
DELETE FROM plan_goals     WHERE user_id    NOT IN (SELECT id FROM users);
DELETE FROM plan_goals     WHERE project_id NOT IN (SELECT id FROM projects);
DELETE FROM plan_paint     WHERE user_id    NOT IN (SELECT id FROM users);
DELETE FROM plan_paint     WHERE project_id NOT IN (SELECT id FROM projects);
DELETE FROM plan_settings  WHERE user_id    NOT IN (SELECT id FROM users);
DELETE FROM plan_templates WHERE user_id    NOT IN (SELECT id FROM users);

-- plan_days
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_days` ADD CONSTRAINT `fk_plan_days_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND CONSTRAINT_NAME = 'fk_plan_days_user');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_days` ADD CONSTRAINT `fk_plan_days_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days' AND CONSTRAINT_NAME = 'fk_plan_days_proj');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- plan_goals
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_goals` ADD CONSTRAINT `fk_plan_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_goals' AND CONSTRAINT_NAME = 'fk_plan_goals_user');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_goals` ADD CONSTRAINT `fk_plan_goals_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_goals' AND CONSTRAINT_NAME = 'fk_plan_goals_proj');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- plan_paint
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_paint` ADD CONSTRAINT `fk_plan_paint_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_paint' AND CONSTRAINT_NAME = 'fk_plan_paint_user');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_paint` ADD CONSTRAINT `fk_plan_paint_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_paint' AND CONSTRAINT_NAME = 'fk_plan_paint_proj');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- plan_settings
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_settings` ADD CONSTRAINT `fk_plan_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_settings' AND CONSTRAINT_NAME = 'fk_plan_settings_user');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- plan_templates
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `plan_templates` ADD CONSTRAINT `fk_plan_templates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_templates' AND CONSTRAINT_NAME = 'fk_plan_templates_user');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
