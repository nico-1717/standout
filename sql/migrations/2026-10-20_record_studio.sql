-- =====================================================================
-- STANDOUT — migrazione del 20/10/2026 (terza)
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- I record personali del gioco delle flashcard di studio: gli stessi delle
-- lingue (combo, veloce, padroneggiate), ma uno per utente invece che per
-- corso.
CREATE TABLE fc_study_records (
  user_id     INT NOT NULL,
  kind        VARCHAR(20) NOT NULL,
  value       DECIMAL(10,2) NOT NULL,
  game_id     INT UNSIGNED NULL DEFAULT NULL,
  achieved_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind),
  CONSTRAINT fk_study_records_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Record personali del gioco delle flashcard di studio';
