<?php
/* fc_ai_saved.php — spiegazione e metafora SALVATE su una carta di studio.
 *
 * Il testo che l'IA scrive per "Spiega" e "Metafora" si salva da solo la prima
 * volta (public/ai/ai_generate_text.php) e da li' in poi si riapre dal
 * database: niente nuova chiamata, niente token. Questo file serve a due cose:
 *   1. dire al gioco, prima ancora che si tocchi il pulsante, QUALI carte
 *      hanno gia' il testo salvato, cosi' il pulsante lo dice (fc_game.php);
 *   2. tenere in un posto solo dove si trova il testo, perche' un padre e un
 *      figlio NON sono nella stessa tabella.
 *
 * Perche' due tabelle: un figlio vive in fc_study_subcards (chiave id_child),
 * un padre in fc_study_cards (chiave id), e le due numerazioni partono
 * entrambe da 1. fc_study_ai ha una sola chiave, card_id, e per un figlio la
 * stessa spiegazione si sarebbe attaccata a un padre qualunque con quel
 * numero (o il controllo di proprieta' l'avrebbe rifiutata). Per i figli ora
 * c'e' fc_study_ai_child, identica, con la sua chiave child_id.
 *
 * fc_study_ai_child si crea da sola alla prima richiesta, come le tabelle del
 * piano e della home: la pubblicazione e' solo FTP e non lancia nessuna
 * migrazione. Resta comunque in sql/schema.sql e in
 * sql/migrations/2026-10-26_spiega_figli.sql.
 */

if (!function_exists('fc_ai_tabelle')) {

/** Crea la tabella dei figli se manca. Una volta sola per richiesta. */
function fc_ai_tabelle(): void {
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;
    sec_q("CREATE TABLE IF NOT EXISTS fc_study_ai_child (
             child_id INT NOT NULL,
             ai_explanation TEXT NULL,
             ai_metaphor TEXT NULL,
             PRIMARY KEY (child_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
}

/** [tabella, colonna chiave] per un padre o per un figlio. */
function fc_ai_dove(bool $figlio): array {
    fc_ai_tabelle();
    return $figlio ? ['fc_study_ai_child', 'child_id'] : ['fc_study_ai', 'card_id'];
}

/**
 * La carta e' dell'utente? Un padre si controlla dal suo mazzo, un figlio dal
 * mazzo del suo padre. Senza questo si potevano far generare (e pagare) testi
 * sulle carte di un altro, e soprattutto rileggerli dal salvataggio.
 */
function fc_ai_carta_mia(int $id, bool $figlio, int $userId): bool {
    if ($id <= 0 || $userId <= 0) return false;
    if ($figlio) {
        return sec_one("SELECT 1 AS ok FROM fc_study_subcards s
                         JOIN fc_study_cards p ON p.id = s.parent_id
                         JOIN fc_study_decks d ON d.id = p.category_id
                        WHERE s.id_child = ? AND d.user_id = ? LIMIT 1", [$id, $userId]) !== null;
    }
    return sec_one("SELECT 1 AS ok FROM fc_study_cards i
                     JOIN fc_study_decks d ON d.id = i.category_id
                    WHERE i.id = ? AND d.user_id = ? LIMIT 1", [$id, $userId]) !== null;
}

/**
 * Cosa c'e' di salvato per una carta: ['explain' => bool, 'metaphor' => bool].
 * Solo una lettura, nessun controllo di proprieta': il gioco la chiama su
 * carte che ha appena letto dal mazzo dell'utente.
 */
function fc_ai_salvati(int $id, bool $figlio): array {
    [$tab, $col] = fc_ai_dove($figlio);
    $r = sec_one("SELECT (ai_explanation IS NOT NULL AND ai_explanation <> '') AS e,
                         (ai_metaphor IS NOT NULL AND ai_metaphor <> '') AS m
                    FROM $tab WHERE $col = ?", [$id]);
    return ['explain' => !empty($r['e']), 'metaphor' => !empty($r['m'])];
}

}
