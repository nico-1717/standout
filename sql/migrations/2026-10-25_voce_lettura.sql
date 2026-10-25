-- =====================================================================
-- STANDOUT — migrazione del 25/10/2026 (modalita' vocale delle parole)
-- Da eseguire una volta, DOPO 2026-10-25_lingue_esempi_impostazioni.sql.
-- =====================================================================

-- Cosa legge la modalita' vocale nelle parole, 4 cifre:
--   domanda 0/1 · risposta 1 o 2 volte (la seconda piu' lenta) ·
--   significato 0/1 · esempi 0 nessuno / 1 uno / 2 tutti.
-- NULL = valori di partenza (1100).
ALTER TABLE fc_settings
  ADD COLUMN voice_read VARCHAR(4) NULL DEFAULT NULL
  COMMENT 'lettura vocale: domanda, risposta (1-2), significato, esempi (0-2)'
  AFTER vocab_examples;
