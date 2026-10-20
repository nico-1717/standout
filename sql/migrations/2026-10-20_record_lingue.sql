-- =====================================================================
-- STANDOUT — migrazione del 20/10/2026 (seconda)
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- I record personali del gioco delle lingue, uno per tipo e per corso:
--   combo          la serie piu' lunga di risposte giuste di fila
--   veloce         il blocco fra due checkpoint piu' rapido (secondi per carta,
--                  piu' basso e' meglio; contano solo i blocchi giusti all'80%)
--   padroneggiate  quante parole del corso sono arrivate a "padroneggiata"
-- Una riga sola per tipo: si riscrive solo quando il record migliora.
CREATE TABLE fc_vocab_records (
  user_id     INT NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  kind        VARCHAR(20) NOT NULL,
  value       DECIMAL(10,2) NOT NULL,
  game_id     INT UNSIGNED NULL DEFAULT NULL,
  achieved_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, course_id, kind),
  CONSTRAINT fk_vocab_records_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_vocab_records_course FOREIGN KEY (course_id) REFERENCES fc_vocab_courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Record personali del gioco delle lingue, uno per tipo e per corso';
