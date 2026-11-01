<?php
/* ============================================================================
   fc_nuove_carte.php — CARTE AGGIUNTE AL MAZZO MENTRE UNA PARTITA E' A META'

   La sequenza di una partita e' scritta una volta sola all'avvio, quindi una
   carta creata dopo non entrerebbe mai. Per non indovinare chi e' «nuova» (non
   c'e' una data di creazione sulle carte) si ricorda, a partenza, l'id piu'
   alto esistente: fc_games.max_card_id. Gli id crescono sempre, quindi nuova =
   id piu' alto, nei mazzi della partita, e non ancora in sequenza.

   Se l'utente dice «no» il riferimento si sposta all'id attuale: la domanda
   non si ripete per le stesse carte. Se dice «si» le carte vanno in coda.

   Senza la colonna (migrazione non ancora eseguita) tutto e' un no-op.
   ============================================================================ */

require_once __DIR__ . '/fc_type.php';

function fc_nuove_carte_attiva(): bool {
    static $ok = null;
    if ($ok === null) {
        $r = sec_one("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_games' AND COLUMN_NAME = 'max_card_id'");
        $ok = $r && (int)$r['n'] > 0;
    }
    return $ok;
}

/* L'id piu' alto delle carte del tipo corrente (fc_t('cards') dipende dalla richiesta). */
function fc_nuove_carte_id_massimo(): int {
    $r = sec_one("SELECT MAX(id) AS m FROM " . fc_t('cards'));
    return (int)($r['m'] ?? 0);
}

function fc_nuove_carte_segna_base(int $gameId): void {
    if (!fc_nuove_carte_attiva()) return;
    sec_q("UPDATE fc_games SET max_card_id = ? WHERE id = ?", [fc_nuove_carte_id_massimo(), $gameId]);
}

/**
 * Id delle carte nate dopo l'avvio nei mazzi della partita. Vuoto per i ripassi
 * («rigioca gli errori»: l'elenco e' fisso per costruzione) e per le partite
 * senza riferimento.
 * @return int[]
 */
function fc_nuove_carte_trova(array $game, int $userId): array {
    if (!fc_nuove_carte_attiva()) return [];
    if ((int)($game['parent_id'] ?? 0) > 0) return [];
    $base = sec_one("SELECT max_card_id FROM fc_games WHERE id = ? AND user_id = ?", [(int)$game['id'], $userId]);
    if (!$base || $base['max_card_id'] === null) return [];
    $cat = [];
    foreach (sec_all("SELECT fc_category_id FROM " . fc_t('game_decks') . " WHERE game_id = ?", [(int)$game['id']]) as $r) {
        if ((int)$r['fc_category_id'] > 0) $cat[] = (int)$r['fc_category_id'];
    }
    if (!$cat) return [];
    $ph = implode(',', array_fill(0, count($cat), '?'));
    /* La JOIN sui mazzi con user_id: solo carte proprie. Lo stato (nuove/da
       imparare) si rispetta come all'avvio. */
    $where_stato = fc_sql_stato_imparata('i', (string)($game['card_status'] ?? '0'), $userId);
    $righe = sec_all("SELECT i.id FROM " . fc_t('cards') . " i
                        JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                       WHERE i.id > ? AND i.category_id IN ($ph) AND c.user_id = ? AND $where_stato
                         AND NOT EXISTS (SELECT 1 FROM " . fc_t('sequence') . " s WHERE s.game_id = ? AND s.fc_image_id = i.id)
                       ORDER BY i.category_id, i.custom_order, i.id",
                     array_merge([(int)$base['max_card_id']], $cat, [$userId, (int)$game['id']]));
    return array_map(fn($r) => (int)$r['id'], $righe);
}

/* Risposta «no»: il riferimento passa all'id attuale e non si chiede piu' per queste. */
function fc_nuove_carte_rifiuta(int $gameId, int $userId): void {
    if (!fc_nuove_carte_attiva()) return;
    sec_q("UPDATE fc_games SET max_card_id = ? WHERE id = ? AND user_id = ?", [fc_nuove_carte_id_massimo(), $gameId, $userId]);
}

/* Risposta «si»: in coda alla sequenza, e i totali della partita crescono. */
function fc_nuove_carte_accetta(array $game, int $userId): int {
    $ids = fc_nuove_carte_trova($game, $userId);
    $gid = (int)$game['id'];
    if (!$ids) { fc_nuove_carte_rifiuta($gid, $userId); return 0; }
    $r = sec_one("SELECT COALESCE(MAX(position), 0) AS m FROM " . fc_t('sequence') . " WHERE game_id = ?", [$gid]);
    $pos = (int)($r['m'] ?? 0);
    $ph_ids = implode(',', array_fill(0, count($ids), '?'));
    $figli = (int)(sec_one("SELECT COUNT(*) AS n FROM " . fc_t('subcards') . " WHERE parent_id IN ($ph_ids)", $ids)['n'] ?? 0);
    foreach (array_chunk($ids, 300) as $blocco) {
        $valori = [];
        foreach ($blocco as $id) { $pos++; array_push($valori, $gid, $pos, $id); }
        sec_q("INSERT INTO " . fc_t('sequence') . " (game_id, position, fc_image_id) VALUES "
              . implode(',', array_fill(0, count($blocco), '(?, ?, ?)')), $valori);
    }
    sec_q("UPDATE fc_games SET total_father_words = total_father_words + ?, total_words_with_children = total_words_with_children + ?
            WHERE id = ? AND user_id = ?", [count($ids), count($ids) + $figli, $gid, $userId]);
    fc_nuove_carte_rifiuta($gid, $userId);
    return count($ids);
}
