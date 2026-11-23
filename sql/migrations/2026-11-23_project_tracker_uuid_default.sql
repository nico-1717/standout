-- =====================================================================
-- Tracker: la colonna uuid ha bisogno del valore automatico (come tutte le altre tabelle sincronizzate).
-- Senza, ogni nuovo blocco del Tracker (dal sito o dal telefono) fallisce con
-- "Field 'uuid' doesn't have a default value".
-- Da lanciare UNA volta in phpMyAdmin (database my_nisama). Rilanciarla non fa danni.
-- =====================================================================

ALTER TABLE `tracker_blocks`
  MODIFY COLUMN `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (UUID())
  COMMENT 'Identita'' della riga per l''app (UUID)';
