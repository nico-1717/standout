<?php
/* =====================================================================
   STANDOUT — sync: il gioco delle lingue
   (risposte, mazzi scelti, sequenza delle parole, record per corso)

   Le partite stanno in fc_games (kind = 'vocab', course_id) e le sessioni di tempo in
   fc_game_durations: le gestisce sync_flashcard2.php. Qui ci sono le tabelle che sono
   solo delle partite di lingua. Le regole sono quelle del sito (public/fc/fc_vocab_game_api.php,
   lib/fc/fc_vocab_gioco.php) e sono le stesse dello studio, con parole e mazzi del corso:
     - una risposta per parola (o parola figlia) e posizione; '' = non ancora data
     - un mazzo una sola volta per partita; una posizione una sola volta nella sequenza
     - un record per (utente, corso, tipo): l'uuid e' calcolato (UUIDv5) e deve coincidere
   Tutto e' vincolato all'utente: ogni uuid del patch deve puntare a una riga SUA.
   ===================================================================== */

const SYNC_LG_BERSAGLI = ['fc_vocab_cards', 'fc_vocab_subcards'];

function sync_lg_risposte_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT p.* FROM fc_vocab_plays p JOIN fc_games g ON g.id = p.game_id WHERE p.uuid = ? AND g.user_id = ? AND g.kind = 'vocab'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_vocab_plays WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_plays', $patch, $riga === null)) return $er;

    $r = $riga ?: ['correct_answer' => null, 'user_answer' => '', 'play_date' => null];
    if (array_key_exists('correct_answer', $patch)) {
        $v = sync_fc_testo($patch['correct_answer'], 255, true);
        if ($v === false) return sync_rifiuto('validation', 'correct_answer', 'correct_answer must be text');
        $r['correct_answer'] = $v;
    }
    if (array_key_exists('user_answer', $patch)) {
        // '' = domanda non ancora risposta
        if (!in_array($patch['user_answer'], ['', 'Correct', 'Wrong'], true)) return sync_rifiuto('validation', 'user_answer', 'user_answer is not valid');
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
        sec_q("UPDATE fc_vocab_plays SET correct_answer = ?, user_answer = ?, play_date = ? WHERE id = ?",
              [$r['correct_answer'], $r['user_answer'], $r['play_date'], (int)$riga['id']]);
        return ['status' => 'applied'];
    }
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid', 'vocab');
    if ($er) return $er;
    [$cid, $fid, $er] = sync_fc2_bersaglio($uid, $patch, 'card_uuid', 'child_uuid', SYNC_LG_BERSAGLI[0], SYNC_LG_BERSAGLI[1]);
    if ($er) return $er;
    $pos = sync_fc2_intero($patch['position'] ?? null, 0, 1000000);
    if ($pos === null) return sync_rifiuto('validation', 'position', 'position must be a whole number');
    // una sola risposta per parola e posizione: un'altra riga nello stesso posto e' un errore del telefono, non un riprovo
    $occupato = $cid !== null
        ? sec_one("SELECT 1 AS x FROM fc_vocab_plays WHERE game_id = ? AND position = ? AND fc_image_id = ?", [$gid, $pos, $cid])
        : sec_one("SELECT 1 AS x FROM fc_vocab_plays WHERE game_id = ? AND position = ? AND fc_child_id = ?", [$gid, $pos, $fid]);
    if ($occupato) return sync_rifiuto('forbidden', 'position', 'This word already has an answer at this position');
    if ($er = sync_fc2_inserisci('fc_vocab_plays', $uuid,
        "INSERT INTO fc_vocab_plays (game_id, fc_image_id, fc_child_id, correct_answer, user_answer, position, play_date) VALUES (?,?,?,?,?,?,?)",
        [$gid, $cid, $fid, $r['correct_answer'], $r['user_answer'], $pos, $r['play_date']])) return $er;
    return ['status' => 'applied'];
}

function sync_lg_mazzi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT d.* FROM fc_vocab_game_decks d JOIN fc_games g ON g.id = d.game_id WHERE d.uuid = ? AND g.user_id = ? AND g.kind = 'vocab'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_vocab_game_decks WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_game_decks', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid', 'vocab');
    if ($er) return $er;
    [$mid, $er] = sync_fc_risolvi('fc_vocab_decks', $uid, $patch['deck_uuid'] ?? null, 'deck_uuid', 'Deck');
    if ($er) return $er;
    // il mazzo deve stare nel corso della partita
    $ok = sec_one("SELECT 1 AS x FROM fc_games g JOIN fc_vocab_decks d ON d.course_id = g.course_id WHERE g.id = ? AND d.id = ? AND d.user_id = ?", [$gid, $mid, $uid]);
    if (!$ok) return sync_rifiuto('validation', 'deck_uuid', 'This deck is not in the course of the game');
    $ord = array_key_exists('category_order', $patch) ? sync_fc2_intero($patch['category_order'], 0, 100000) : 0;
    if ($ord === null) return sync_rifiuto('validation', 'category_order', 'category_order must be a whole number');
    if (sec_one("SELECT 1 AS x FROM fc_vocab_game_decks WHERE game_id = ? AND fc_category_id = ?", [$gid, $mid])) return sync_rifiuto('forbidden', 'deck_uuid', 'This deck is already in the game');
    if ($er = sync_fc2_inserisci('fc_vocab_game_decks', $uuid,
        "INSERT INTO fc_vocab_game_decks (game_id, fc_category_id, category_order) VALUES (?,?,?)", [$gid, $mid, $ord])) return $er;
    return ['status' => 'applied'];
}

function sync_lg_sequenza_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT s.* FROM fc_vocab_sequence s JOIN fc_games g ON g.id = s.game_id WHERE s.uuid = ? AND g.user_id = ? AND g.kind = 'vocab'", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_vocab_sequence WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_sequence', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];
    [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'] ?? null, 'game_uuid', 'vocab');
    if ($er) return $er;
    [$cid, $er] = sync_fc_risolvi('fc_vocab_cards', $uid, $patch['card_uuid'] ?? null, 'card_uuid', 'Card');
    if ($er) return $er;
    $pos = sync_fc2_intero($patch['position'] ?? null, 0, 1000000);
    if ($pos === null) return sync_rifiuto('validation', 'position', 'position must be a whole number');
    if (sec_one("SELECT 1 AS x FROM fc_vocab_sequence WHERE game_id = ? AND position = ?", [$gid, $pos])) return sync_rifiuto('forbidden', 'position', 'This position is already used');
    if ($er = sync_fc2_inserisci('fc_vocab_sequence', $uuid,
        "INSERT INTO fc_vocab_sequence (game_id, position, fc_image_id) VALUES (?,?,?)", [$gid, $pos, $cid])) return $er;
    return ['status' => 'applied'];
}

/** I record di un corso: una riga per (utente, corso, tipo), come fc_study_records ma dentro il corso. */
function sync_lg_record_applica(int $uid, string $uuid, string $op, array $patch): array {
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_vocab_records WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_vocab_records WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($er = sync_fc_controlla('fc_vocab_records', $patch, $riga === null)) return $er;

    $r = $riga ?: ['course_id' => 0, 'kind' => '', 'value' => 0, 'game_id' => null, 'achieved_at' => date('Y-m-d H:i:s')];
    if ($riga === null) {
        $k = $patch['kind'] ?? null;
        if (!is_string($k) || !preg_match('/^[a-z_]{1,20}$/', $k)) return sync_rifiuto('validation', 'kind', 'kind is not valid');
        [$corso, $er] = sync_fc_risolvi('fc_vocab_courses', $uid, $patch['course_uuid'] ?? null, 'course_uuid', 'Course');
        if ($er) return $er;
        if (sync_uuid_record_lingua($uid, $patch['course_uuid'], $k) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid does not match the course and kind');
        $r['kind'] = $k; $r['course_id'] = $corso;
    }
    if (array_key_exists('value', $patch)) {
        if (is_bool($patch['value']) || !is_numeric($patch['value']) || abs($patch['value']) > 99999999) return sync_rifiuto('validation', 'value', 'value is not valid');
        $r['value'] = round((float)$patch['value'], 2);
    }
    if (array_key_exists('game_uuid', $patch)) {
        if ($patch['game_uuid'] === null) $r['game_id'] = null;
        else {
            [$gid, $er] = sync_fc2_partita($uid, $patch['game_uuid'], 'game_uuid', 'vocab');
            if ($er) return $er;
            $r['game_id'] = $gid;
        }
    }
    if (array_key_exists('achieved_at', $patch)) {
        $v = sync_fc2_datetime($patch['achieved_at']);
        if ($v === null) return sync_rifiuto('validation', 'achieved_at', 'achieved_at must be YYYY-MM-DD HH:MM:SS');
        $r['achieved_at'] = $v;
    }
    sec_q("INSERT INTO fc_vocab_records (user_id, course_id, kind, value, game_id, achieved_at) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE value = VALUES(value), game_id = VALUES(game_id), achieved_at = VALUES(achieved_at)",
          [$uid, (int)$r['course_id'], $r['kind'], $r['value'], $r['game_id'], $r['achieved_at']]);
    return ['status' => 'applied'];
}
