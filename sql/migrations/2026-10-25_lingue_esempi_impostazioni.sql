-- =====================================================================
-- STANDOUT — migrazione del 25/10/2026
-- Da eseguire una volta, in phpMyAdmin (scheda SQL) o da riga di comando.
-- =====================================================================

-- 1. La parte nascosta di un esempio puo' essere A PEZZI:
--    "I want you to" -> si nascondono "I want" e "to", "you" resta.
--    Un pezzo solo resta scritto come prima ('I want'); piu' pezzi si
--    scrivono come [[posizione,"testo"],...] (lib/fc/fc_vocab_gap.php).
--    255 caratteri bastavano per un pezzo di frase, non per l'elenco.
ALTER TABLE fc_vocab_examples
  MODIFY COLUMN gap_text VARCHAR(1000) NULL DEFAULT NULL
  COMMENT 'parti della frase nascoste nel gioco: testo, o [[pos,"testo"],...] (NULL = automatica)';

-- 2. Le scelte di gioco dell'utente, sul server e non piu' nel browser:
--    come si vedono significato ed esempi nel gioco delle lingue prima della
--    risposta (aperto / tocco / dopo). La prima volta entrambi aperti.
CREATE TABLE IF NOT EXISTS fc_settings (
  user_id        INT NOT NULL,
  vocab_meaning  VARCHAR(8) NOT NULL DEFAULT 'aperto' COMMENT 'aperto | tocco | dopo',
  vocab_examples VARCHAR(8) NOT NULL DEFAULT 'aperto' COMMENT 'aperto | tocco | dopo',
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_fc_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Scelte di gioco delle flashcard, una riga per utente';
