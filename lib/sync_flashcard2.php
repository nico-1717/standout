<?php
/* =====================================================================
   STANDOUT — sync: flashcard di studio, seconda parte
   (preferiti, carte imparate, esempi, partite, risposte, record, impostazioni, obiettivo)

   Le regole sono quelle del sito (public/fc/fc_game_start.php, fc_game.php, fc_game_retry.php, fc_vocab_gioco.php):
     - una partita ha data, punteggio, tipo (fc | fc_L), stato delle carte (all | 1 | 0), ordine (in_order | random),
       i totali di carte, una partita madre se e' un ripasso, e la carta piu' nuova di quando e' partita (max_card_uuid)
     - ogni partita ha le sue sessioni di tempo, le risposte (una per carta e posizione; risposta '' = non ancora data),
       i mazzi scelti e la sequenza delle carte; tolta la partita si toglie tutto, riga per riga (il registro le annota)
     - preferiti: uno per carta o carta figlia; se sito e telefono preferiscono la stessa carta restano due uuid
       diversi: vince quello del telefono (la riga del sito viene tolta e annotata)
     - imparate, record, impostazioni, obiettivo: una riga per chiave naturale, l'uuid e' calcolato (UUIDv5) e deve coincidere
   Tutto e' vincolato all'utente: ogni uuid del patch deve puntare a una riga SUA.
   ===================================================================== */

require_once __DIR__ . '/fc/fc_impostazioni.php';

/** Una data (YYYY-MM-DD) del filo; null = non valida. */
function sync_fc2_data($v): ?string {
    return is_string($v) ? sec_date($v, null) : null;
}

/** Una data con ora (YYYY-MM-DD HH:MM[:SS], anche con la T) del filo, nella forma del database; null = non valida. */
function sync_fc2_datetime($v): ?string {
    if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($v), $m)) return null;
    if (sec_date($m[1], null) === null || (int)$m[2] > 23 || (int)$m[3] > 59 || (int)($m[4] ?? 0) > 59) return null;
    return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
}

/** Un intero del filo fra $min e $max; null = non valido. */
function sync_fc2_intero($v, int $min, int $max): ?int {
    if (is_bool($v) || !is_int($v) || $v < $min || $v > $max) return null;
    return $v;
}

/** Fa nascere una riga con l'uuid scelto dal telefono (l'uuid non deve essere gia' di nessuno). Ritorna un errore o null. */
function sync_fc2_inserisci(string $tbl, string $uuid, string $sql, array $par): ?array {
    if (sec_one("SELECT 1 AS x FROM `$tbl` WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => $tbl, 'uuid' => $uuid];
    try { sec_q($sql, $par); } finally { unset($GLOBALS['SYNC_UUID_NUOVO']); }
    return null;
}

/** Una carta o una carta figlia, indicate da due uuid (esattamente uno dei due). Ritorna [id carta|null, id figlia|null, errore|null]. */
function sync_fc2_bersaglio(int $uid, array $patch, string $campoCarta, string $campoFiglia): array {
    $c = $patch[$campoCarta] ?? null; $f = $patch[$campoFiglia] ?? null;
    if (($c === null) === ($f === null)) return [null, null, sync_rifiuto('validation', $campoCarta, "Exactly one of $campoCarta and $campoFiglia is required")];
    if ($c !== null) {
        [$id, $er] = sync_fc_risolvi('fc_study_cards', $uid, $c, $campoCarta, 'Card');
        return [$id, null, $er];
    }
    [$id, $er] = sync_fc_risolvi('fc_study_subcards', $uid, $f, $campoFiglia, 'Child card');
    return [null, $id, $er];
}

/** Una partita (di studio) dell'utente da un uuid del patch. Ritorna [id|null, errore|null]. */
function sync_fc2_partita(int $uid, $uuid, string $campo): array {
    if (!sync_uuid_valido($uuid)) return [null, sync_rifiuto('validation', $campo, 'Invalid uuid')];
    $r = sec_one("SELECT id FROM fc_games WHERE user_id = ? AND uuid = ? AND kind = 'study'", [$uid, $uuid]);
    return $r ? [(int)$r['id'], null] : [null, sync_rifiuto('not_found', $campo, 'Game not found')];
}

/* ---------------- preferiti ---------------- */

function sync_fc_preferiti_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_study_favorites WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_study_favorites WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_favorites', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];   // una riga gia' c'e' e non ha campi modificabili

    [$cid, $fid, $er] = sync_fc2_bersaglio($uid, $patch, 'card_uuid', 'child_uuid');
    if ($er) return $er;
    $data = date('Y-m-d');
    if (array_key_exists('favorited_date', $patch)) {
        $data = sync_fc2_data($patch['favorited_date']);
        if ($data === null) return sync_rifiuto('validation', 'favorited_date', 'favorited_date must be YYYY-MM-DD');
    }
    // la stessa carta gia' preferita con un altro uuid (di solito dal sito): la riga di prima lascia il posto a quella del telefono
    $vecchia = $cid !== null
        ? sec_one("SELECT id FROM fc_study_favorites WHERE user_id = ? AND fc_image_id = ?", [$uid, $cid])
        : sec_one("SELECT id FROM fc_study_favorites WHERE user_id = ? AND fc_child_id = ?", [$uid, $fid]);
    if ($vecchia) sec_q("DELETE FROM fc_study_favorites WHERE id = ? AND user_id = ?", [(int)$vecchia['id'], $uid]);
    if ($er = sync_fc2_inserisci('fc_study_favorites', $uuid,
        "INSERT INTO fc_study_favorites (user_id, fc_image_id, fc_child_id, favorited_date) VALUES (?,?,?,?)", [$uid, $cid, $fid, $data])) return $er;
    return ['status' => 'applied'];
}

/* ---------------- imparate ---------------- */

function sync_fc_imparate_applica(int $uid, string $uuid, string $op, array $patch): array {
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_study_learnt WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_study_learnt WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($er = sync_fc_controlla('fc_study_learnt', $patch, $riga === null)) return $er;

    $data = null;
    if (array_key_exists('learnt_date', $patch)) {
        $data = sync_fc2_data($patch['learnt_date']);
        if ($data === null) return sync_rifiuto('validation', 'learnt_date', 'learnt_date must be YYYY-MM-DD');
    }
    if ($riga) {
        if ($data !== null) sec_q("UPDATE fc_study_learnt SET learnt_date = ? WHERE id = ? AND user_id = ?", [$data, (int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if (!array_key_exists('target_uuid', $patch) || !array_key_exists('is_child', $patch)) return sync_rifiuto('validation', 'target_uuid', 'target_uuid and is_child are required');
    if (!is_bool($patch['is_child'])) return sync_rifiuto('validation', 'is_child', 'is_child must be true or false');
    $figlia = $patch['is_child'] ? 1 : 0;
    [$id, $er] = $figlia
        ? sync_fc_risolvi('fc_study_subcards', $uid, $patch['target_uuid'], 'target_uuid', 'Child card')
        : sync_fc_risolvi('fc_study_cards', $uid, $patch['target_uuid'], 'target_uuid', 'Card');
    if ($er) return $er;
    // l'identita' di una riga a chiave naturale la calcolano entrambi, telefono e server: se non coincide e' un errore
    if (sync_uuid_imparata($uid, $patch['target_uuid'], $figlia) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid does not match the card');
    // se c'e' gia' (imparata dal sito), la data piu' vecchia resta: «imparata il» non si sposta in avanti
    sec_q("INSERT INTO fc_study_learnt (user_id, fc_image_id, is_child, learnt_date) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE learnt_date = LEAST(learnt_date, VALUES(learnt_date))", [$uid, $id, $figlia, $data ?? date('Y-m-d')]);
    return ['status' => 'applied'];
}

/* ---------------- esempi ---------------- */

function sync_fc_esempi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT e.* FROM fc_study_examples e WHERE e.uuid = ? AND " . sync_prop('fc_study_examples', 'e') . " = ?", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_study_examples WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_examples', $patch, $riga === null)) return $er;
    $testo = $riga ? $riga['example_text'] : null;
    if (array_key_exists('example_text', $patch)) {
        $testo = sync_fc_testo($patch['example_text'], 2000, true);
        if ($testo === false) return sync_rifiuto('validation', 'example_text', 'example_text must be text');
    }
    if ($riga) {
        sec_q("UPDATE fc_study_examples SET example_text = ? WHERE id = ?", [$testo, (int)$riga['id']]);
        return ['status' => 'applied'];
    }
    [$cid, $fid, $er] = sync_fc2_bersaglio($uid, $patch, 'card_uuid', 'child_uuid');
    if ($er) return $er;
    if ($er = sync_fc2_inserisci('fc_study_examples', $uuid,
        "INSERT INTO fc_study_examples (parent_id, child_id, example_text) VALUES (?,?,?)", [$cid, $fid, $testo])) return $er;
    return ['status' => 'applied'];
}

/* ---------------- partite ---------------- */

function sync_fc_partite_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_games WHERE user_id = ? AND uuid = ? AND kind = 'study'", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) {
            $g = (int)$riga['id'];
            // dal basso: ogni riga che va via viene annotata (le chiavi esterne la toglierebbero in silenzio)
            foreach (['fc_study_plays', 'fc_game_durations', 'fc_study_game_decks', 'fc_study_sequence'] as $t) {
                sec_q("DELETE FROM `$t` WHERE game_id = ?", [$g]);
            }
            // i ripassi di questa partita restano: perdono solo il legame
            sec_q("UPDATE fc_games SET parent_id = NULL WHERE parent_id = ? AND user_id = ?", [$g, $uid]);
            sec_q("DELETE FROM fc_games WHERE id = ? AND user_id = ?", [$g, $uid]);
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_games', $patch, $riga === null)) return $er;

    $r = $riga ?: ['game_date' => date('Y-m-d H:i:s'), 'score' => 0, 'type' => 'fc', 'card_status' => 'all', 'parent_id' => null,
                   'total_father_words' => 0, 'game_order' => 'random', 'total_words_with_children' => 1, 'max_card_id' => null];
    if (array_key_exists('game_date', $patch)) {
        $v = sync_fc2_datetime($patch['game_date']);
        if ($v === null) return sync_rifiuto('validation', 'game_date', 'game_date must be YYYY-MM-DD HH:MM:SS');
        $r['game_date'] = $v;
    }
    if (array_key_exists('score', $patch)) {
        if (is_bool($patch['score']) || !is_numeric($patch['score']) || $patch['score'] < 0 || $patch['score'] > 100) return sync_rifiuto('validation', 'score', 'score must be 0 to 100');
        $r['score'] = round((float)$patch['score'], 2);
    }
    if (array_key_exists('type', $patch)) {
        if (!in_array($patch['type'], ['fc', 'fc_L'], true)) return sync_rifiuto('validation', 'type', 'type must be fc or fc_L');
        $r['type'] = $patch['type'];
    }
    if (array_key_exists('card_status', $patch)) {
        if (!in_array($patch['card_status'], ['all', '1', '0'], true)) return sync_rifiuto('validation', 'card_status', 'card_status must be all, 1 or 0');
        $r['card_status'] = $patch['card_status'];
    }
    if (array_key_exists('game_order', $patch)) {
        if (!in_array($patch['game_order'], ['in_order', 'random'], true)) return sync_rifiuto('validation', 'game_order', 'game_order must be in_order or random');
        $r['game_order'] = $patch['game_order'];
    }
    foreach (['total_father_words', 'total_words_with_children'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        $v = sync_fc2_intero($patch[$c], 0, 1000000);
        if ($v === null) return sync_rifiuto('validation', $c, "$c must be a whole number");
        $r[$c] = $v;
    }
    if (array_key_exists('parent_uuid', $patch) && $patch['parent_uuid'] !== null) {
        [$pid, $er] = sync_fc2_partita($uid, $patch['parent_uuid'], 'parent_uuid');
        if ($er) return $er;
        $r['parent_id'] = $pid;
    }
    if (array_key_exists('max_card_uuid', $patch)) {
        if ($patch['max_card_uuid'] === null) $r['max_card_id'] = null;
        else {
            [$mid, $er] = sync_fc_risolvi('fc_study_cards', $uid, $patch['max_card_uuid'], 'max_card_uuid', 'Card');
            if ($er) return $er;
            $r['max_card_id'] = $mid;
        }
    }

    if ($riga === null) {
        if ($er = sync_fc2_inserisci('fc_games', $uuid,
            "INSERT INTO fc_games (game_date, score, type, kind, course_id, card_status, game_order, user_id, parent_id, total_father_words, total_words_with_children, max_card_id)
             VALUES (?,?,?,'study',NULL,?,?,?,?,?,?,?)",
            [$r['game_date'], $r['score'], $r['type'], $r['card_status'], $r['game_order'], $uid, $r['parent_id'], $r['total_father_words'], $r['total_words_with_children'], $r['max_card_id']])) return $er;
    } else {
        sec_q("UPDATE fc_games SET game_date = ?, score = ?, game_order = ?, total_father_words = ?, total_words_with_children = ?, max_card_id = ? WHERE id = ? AND user_id = ?",
              [$r['game_date'], $r['score'], $r['game_order'], $r['total_father_words'], $r['total_words_with_children'], $r['max_card_id'], (int)$riga['id'], $uid]);
    }
    return ['status' => 'applied'];
}

/* ---------------- sessioni di tempo ---------------- */

function sync_fc_durate_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT d.* FROM fc_game_durations d JOIN fc_games g ON g.id = d.game_id WHERE d.uuid = ? AND g.user_id = ? AND g.kind = 'study'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_game_durations WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_game_durations', $patch, $riga === null)) return $er;
    $sec = $riga ? (int)$riga['duration_seconds'] : 0;
    if (array_key_exists('duration_seconds', $patch)) {
        $v = sync_fc2_intero($patch['duration_seconds'], 0, 86400 * 7);
        if ($v === null) return sync_rifiuto('validation', 'duration_seconds', 'duration_seconds must be a whole number of seconds');
        $sec = $v;
    }
    if ($riga) {
        sec_q("UPDATE fc_game_durations SET duration_seconds = ?, updated_at = NOW() WHERE id = ?", [$sec, (int)$riga['id']]);
        return ['status' => 'applied'];
    }
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid');
    if ($er) return $er;
    $inizio = sync_fc2_datetime($patch['start_time'] ?? null);
    if ($inizio === null) return sync_rifiuto('validation', 'start_time', 'start_time must be YYYY-MM-DD HH:MM:SS');
    if ($er = sync_fc2_inserisci('fc_game_durations', $uuid,
        "INSERT INTO fc_game_durations (game_id, start_time, duration_seconds, updated_at) VALUES (?,?,?,NOW())", [$gid, $inizio, $sec])) return $er;
    return ['status' => 'applied'];
}

/* ---------------- risposte ---------------- */

function sync_fc_risposte_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT p.* FROM fc_study_plays p JOIN fc_games g ON g.id = p.game_id WHERE p.uuid = ? AND g.user_id = ? AND g.kind = 'study'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_study_plays WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_plays', $patch, $riga === null)) return $er;

    $r = $riga ?: ['correct_answer' => null, 'user_answer' => '', 'play_date' => null];
    if (array_key_exists('correct_answer', $patch)) {
        $v = sync_fc_testo($patch['correct_answer'], 255, true);
        if ($v === false) return sync_rifiuto('validation', 'correct_answer', 'correct_answer must be text');
        $r['correct_answer'] = $v;
    }
    if (array_key_exists('user_answer', $patch)) {
        // '' = domanda non ancora risposta; Titolo / Visione = carte da guardare soltanto
        if (!in_array($patch['user_answer'], ['', 'Correct', 'Wrong', 'Titolo', 'Visione'], true)) return sync_rifiuto('validation', 'user_answer', 'user_answer is not valid');
        $r['user_answer'] = $patch['user_answer'];
    }
    if (array_key_exists('play_date', $patch)) {
        if ($patch['play_date'] === null) $r['play_date'] = null;
        else {
            $v = sync_fc2_datetime($patch['play_date']);
            if ($v === null) return sync_rifiuto('validation', 'play_date', 'play_date must be YYYY-MM-DD HH:MM:SS');
            $r['play_date'] = $v;
        }
    }
    if ($riga) {
        sec_q("UPDATE fc_study_plays SET correct_answer = ?, user_answer = ?, play_date = ? WHERE id = ?",
              [$r['correct_answer'], $r['user_answer'], $r['play_date'], (int)$riga['id']]);
        return ['status' => 'applied'];
    }
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid');
    if ($er) return $er;
    [$cid, $fid, $er] = sync_fc2_bersaglio($uid, $patch, 'card_uuid', 'child_uuid');
    if ($er) return $er;
    $pos = sync_fc2_intero($patch['position'] ?? null, 0, 1000000);
    if ($pos === null) return sync_rifiuto('validation', 'position', 'position must be a whole number');
    // una sola risposta per carta e posizione: un'altra riga nello stesso posto e' un errore del telefono, non un riprovo
    $occupato = $cid !== null
        ? sec_one("SELECT 1 AS x FROM fc_study_plays WHERE game_id = ? AND position = ? AND fc_image_id = ?", [$gid, $pos, $cid])
        : sec_one("SELECT 1 AS x FROM fc_study_plays WHERE game_id = ? AND position = ? AND fc_child_id = ?", [$gid, $pos, $fid]);
    if ($occupato) return sync_rifiuto('forbidden', 'position', 'This card already has an answer at this position');
    if ($er = sync_fc2_inserisci('fc_study_plays', $uuid,
        "INSERT INTO fc_study_plays (game_id, fc_image_id, fc_child_id, correct_answer, user_answer, position, play_date) VALUES (?,?,?,?,?,?,?)",
        [$gid, $cid, $fid, $r['correct_answer'], $r['user_answer'], $pos, $r['play_date']])) return $er;
    return ['status' => 'applied'];
}

/* ---------------- mazzi della partita e sequenza ---------------- */

function sync_fc_mazzi_partita_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT d.* FROM fc_study_game_decks d JOIN fc_games g ON g.id = d.game_id WHERE d.uuid = ? AND g.user_id = ? AND g.kind = 'study'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_study_game_decks WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_game_decks', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid');
    if ($er) return $er;
    [$mid, $er] = sync_fc_risolvi('fc_study_decks', $uid, $patch['deck_uuid'] ?? null, 'deck_uuid', 'Deck');
    if ($er) return $er;
    $ord = array_key_exists('category_order', $patch) ? sync_fc2_intero($patch['category_order'], 0, 100000) : 0;
    if ($ord === null) return sync_rifiuto('validation', 'category_order', 'category_order must be a whole number');
    if (sec_one("SELECT 1 AS x FROM fc_study_game_decks WHERE game_id = ? AND fc_category_id = ?", [$gid, $mid])) return sync_rifiuto('forbidden', 'deck_uuid', 'This deck is already in the game');
    if ($er = sync_fc2_inserisci('fc_study_game_decks', $uuid,
        "INSERT INTO fc_study_game_decks (game_id, fc_category_id, category_order) VALUES (?,?,?)", [$gid, $mid, $ord])) return $er;
    return ['status' => 'applied'];
}

function sync_fc_sequenza_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT s.* FROM fc_study_sequence s JOIN fc_games g ON g.id = s.game_id WHERE s.uuid = ? AND g.user_id = ? AND g.kind = 'study'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_study_sequence WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_sequence', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid');
    if ($er) return $er;
    [$cid, $er] = sync_fc_risolvi('fc_study_cards', $uid, $patch['card_uuid'] ?? null, 'card_uuid', 'Card');
    if ($er) return $er;
    $pos = sync_fc2_intero($patch['position'] ?? null, 0, 1000000);
    if ($pos === null) return sync_rifiuto('validation', 'position', 'position must be a whole number');
    if (sec_one("SELECT 1 AS x FROM fc_study_sequence WHERE game_id = ? AND position = ?", [$gid, $pos])) return sync_rifiuto('forbidden', 'position', 'This position is already used');
    if ($er = sync_fc2_inserisci('fc_study_sequence', $uuid,
        "INSERT INTO fc_study_sequence (game_id, position, fc_image_id) VALUES (?,?,?)", [$gid, $pos, $cid])) return $er;
    return ['status' => 'applied'];
}

/* ---------------- record, impostazioni, obiettivo (chiave naturale) ---------------- */

function sync_fc_record_applica(int $uid, string $uuid, string $op, array $patch): array {
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_study_records WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_study_records WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($er = sync_fc_controlla('fc_study_records', $patch, $riga === null)) return $er;

    $r = $riga ?: ['kind' => '', 'value' => 0, 'game_id' => null, 'achieved_at' => date('Y-m-d H:i:s')];
    if ($riga === null) {
        $k = $patch['kind'] ?? null;
        if (!is_string($k) || !preg_match('/^[a-z_]{1,20}$/', $k)) return sync_rifiuto('validation', 'kind', 'kind is not valid');
        if (sync_uuid_record($uid, $k) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid does not match the kind');
        $r['kind'] = $k;
    }
    if (array_key_exists('value', $patch)) {
        if (is_bool($patch['value']) || !is_numeric($patch['value']) || abs($patch['value']) > 99999999) return sync_rifiuto('validation', 'value', 'value is not valid');
        $r['value'] = round((float)$patch['value'], 2);
    }
    if (array_key_exists('game_uuid', $patch)) {
        if ($patch['game_uuid'] === null) $r['game_id'] = null;
        else {
            [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'], 'game_uuid');
            if ($er) return $er;
            $r['game_id'] = $gid;
        }
    }
    if (array_key_exists('achieved_at', $patch)) {
        $v = sync_fc2_datetime($patch['achieved_at']);
        if ($v === null) return sync_rifiuto('validation', 'achieved_at', 'achieved_at must be YYYY-MM-DD HH:MM:SS');
        $r['achieved_at'] = $v;
    }
    sec_q("INSERT INTO fc_study_records (user_id, kind, value, game_id, achieved_at) VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE value = VALUES(value), game_id = VALUES(game_id), achieved_at = VALUES(achieved_at)",
          [$uid, $r['kind'], $r['value'], $r['game_id'], $r['achieved_at']]);
    return ['status' => 'applied'];
}

function sync_fc_impostazioni_applica(int $uid, string $uuid, string $op, array $patch): array {
    if (sync_uuid_per_utente('fc_settings', $uid) !== $uuid) return sync_rifiuto('forbidden', 'uuid', 'Not your settings');
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_settings WHERE user_id = ?", [$uid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_settings WHERE user_id = ?", [$uid]);
    if ($er = sync_fc_controlla('fc_settings', $patch, $riga === null)) return $er;
    $r = $riga ?: ['vocab_meaning' => 'aperto', 'vocab_examples' => 'aperto', 'voice_read' => null];
    foreach (['vocab_meaning', 'vocab_examples'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        if (!in_array($patch[$c], FC_INDIZI_MODI, true)) return sync_rifiuto('validation', $c, "$c is not valid");
        $r[$c] = $patch[$c];
    }
    if (array_key_exists('voice_read', $patch)) {
        if ($patch['voice_read'] !== null && !fc_lettura_valida($patch['voice_read'])) return sync_rifiuto('validation', 'voice_read', 'voice_read is not valid');
        $r['voice_read'] = $patch['voice_read'];
    }
    sec_q("INSERT INTO fc_settings (user_id, vocab_meaning, vocab_examples, voice_read) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE vocab_meaning = VALUES(vocab_meaning), vocab_examples = VALUES(vocab_examples), voice_read = VALUES(voice_read)",
          [$uid, $r['vocab_meaning'], $r['vocab_examples'], $r['voice_read']]);
    return ['status' => 'applied'];
}

function sync_fc_obiettivo_applica(int $uid, string $uuid, string $op, array $patch): array {
    if (sync_uuid_per_utente('fc_goals', $uid) !== $uuid) return sync_rifiuto('forbidden', 'uuid', 'Not your goal');
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_goals WHERE user_id = ?", [$uid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_goals WHERE user_id = ?", [$uid]);
    if ($er = sync_fc_controlla('fc_goals', $patch, $riga === null)) return $er;
    $meta = $riga ? (int)$riga['daily_goal'] : 20;
    if (array_key_exists('daily_goal', $patch)) {
        $v = sync_fc2_intero($patch['daily_goal'], 1, 1000);
        if ($v === null) return sync_rifiuto('validation', 'daily_goal', 'daily_goal must be 1 to 1000');
        $meta = $v;
    }
    sec_q("INSERT INTO fc_goals (user_id, daily_goal) VALUES (?, ?) ON DUPLICATE KEY UPDATE daily_goal = ?", [$uid, $meta, $meta]);
    return ['status' => 'applied'];
}
