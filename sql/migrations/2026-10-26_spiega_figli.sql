-- Spiegazione e metafora salvate anche per le carte FIGLIE di studio.
-- Prima fc_study_ai (chiave card_id) serviva anche ai figli, ma un figlio e un
-- padre stanno in tabelle diverse con numerazioni che si sovrappongono: la
-- spiegazione di un figlio finiva su un padre con lo stesso numero.
-- La tabella si crea da sola alla prima richiesta (lib/fc/fc_ai_saved.php);
-- questo file serve solo a tenerla allineata a mano.
CREATE TABLE IF NOT EXISTS `fc_study_ai_child` (
  `child_id` int NOT NULL,
  `ai_explanation` text,
  `ai_metaphor` text,
  PRIMARY KEY (`child_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Spiegazione e metafora salvate per una carta figlia di studio (fc_study_subcards.id_child)';
