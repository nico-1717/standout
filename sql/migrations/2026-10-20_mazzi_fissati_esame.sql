-- =====================================================================
-- STANDOUT — migrazione del 20/10/2026 (quinta)
-- Da eseguire una volta, DOPO 2026-10-20_mazzi_icone_note.sql.
-- =====================================================================

-- Un mazzo fissato in cima all'elenco (fra i suoi fratelli) e la data
-- dell'esame, che nell'elenco e nel mazzo diventa un conto alla rovescia.
ALTER TABLE fc_study_decks
  ADD COLUMN pinned    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fissato in cima all elenco',
  ADD COLUMN exam_date DATE NULL DEFAULT NULL COMMENT 'data dell esame, per il conto alla rovescia';
ALTER TABLE fc_vocab_decks
  ADD COLUMN pinned    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fissato in cima all elenco',
  ADD COLUMN exam_date DATE NULL DEFAULT NULL COMMENT 'data dell esame, per il conto alla rovescia';
