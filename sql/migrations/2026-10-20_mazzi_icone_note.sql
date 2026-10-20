-- =====================================================================
-- STANDOUT — migrazione del 20/10/2026 (quarta)
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- L'icona di un mazzo (una parola chiave: "libro", "atomo"... vedi
-- FC_ICONE_MAZZO in lib/fc/fc_icone.php; NULL = la cartella di sempre) e le
-- sue note, per studio e lingue.
ALTER TABLE fc_study_decks
  ADD COLUMN icon  VARCHAR(24) NULL DEFAULT NULL COMMENT 'icona del mazzo (chiave di fc_icone.php)',
  ADD COLUMN notes TEXT NULL COMMENT 'note del mazzo, scritte dall utente';
ALTER TABLE fc_vocab_decks
  ADD COLUMN icon  VARCHAR(24) NULL DEFAULT NULL COMMENT 'icona del mazzo (chiave di fc_icone.php)',
  ADD COLUMN notes TEXT NULL COMMENT 'note del mazzo, scritte dall utente';

-- Le note di una parola (carta e sottocarta di lingua): un appunto libero,
-- che si vede nel mazzo e sul retro della carta in gioco.
ALTER TABLE fc_vocab_cards
  ADD COLUMN notes TEXT NULL COMMENT 'note della parola, scritte dall utente';
ALTER TABLE fc_vocab_subcards
  ADD COLUMN notes TEXT NULL COMMENT 'note della parola, scritte dall utente';
