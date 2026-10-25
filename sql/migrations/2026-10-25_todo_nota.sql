-- =====================================================================
-- STANDOUT — migrazione del 25/10/2026 (nota veloce della pagina To do)
-- Da eseguire una volta. Il codice la crea comunque da solo al primo uso
-- (CREATE TABLE IF NOT EXISTS): questo file serve a tenere schema e
-- database allineati.
-- =====================================================================

-- Una nota sola per utente: gli appunti presi di fretta, da smistare dopo
-- in to do, to buy, to study, planner o progetti.
CREATE TABLE IF NOT EXISTS todo_notes (
  user_id INT NOT NULL,
  body MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
