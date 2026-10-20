-- =====================================================================
-- STANDOUT — migrazione del 20/10/2026
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- La parte della frase d'esempio che nel gioco diventa lo spazio da
-- indovinare, scelta a mano nel modulo della parola.
-- NULL = non scelta: vale la parola della carta, se nella frase c'e' scritta
-- uguale (o la vecchia fila di _ , per gli esempi importati prima).
-- Si salva il TESTO e non la posizione: se la frase si corregge e quel pezzo
-- c'e' ancora, la scelta resta valida.
ALTER TABLE fc_vocab_examples
  ADD COLUMN gap_text VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'parte della frase nascosta nel gioco (NULL = la parola della carta)'
  AFTER example_text;
