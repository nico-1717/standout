<?php
/* =====================================================================
   STANDOUT — sync: flashcard di studio (mazzi, carte, carte figlie)

   Le regole sono quelle del sito (public/fc/fc_decks.php, fc_insert_save.php):
     - un mazzo ha un nome (max 120), un padre che e' un altro suo mazzo (mai un suo discendente), un colore #RRGGBB,
       un'icona della lista di fc_icone.php, una data d'esame valida; il colore «ereditato» lo calcola l'app
       (come fa il sito: riscrive il mazzo e i figli che ereditano), qui si controlla soltanto
     - una carta sta in un mazzo e ha domanda e risposta (documenti: JSON del sito o vecchi tag, il testo viene
       sempre protetto in uscita); le carte nuove vanno in fondo al mazzo (custom_order), i file (immagini e audio)
       non passano di qui
     - una carta figlia sta sotto una carta padre; il padre ha is_father = 1 finche' ha figli
   Una riga nasce e cambia con una sola scrittura (riga di prima + modifiche), cosi' il risultato non dipende
   dall'ordine dei campi. Cancellare un mazzo toglie il ramo intero (mazzi figli, carte, carte figlie): il sito lascia
   le carte orfane, qui no; ogni riga tolta e' annotata nel registro, dal basso verso l'alto (le carte si leggono
   col proprietario del mazzo, che deve esserci ancora quando si annota).
   ===================================================================== */

require_once __DIR__ . '/fc/fc_icone.php';

const SYNC_FC_DOC_MAX = 60000;   // byte: la colonna e' TEXT (65535)

/** Un testo semplice: tolti tag e caratteri di controllo, tagliato. null ammesso se $nullo; false se non valido. */
function sync_fc_testo($v, int $max, bool $nullo) {
    if ($v === null) return $nullo ? null : false;
    if (!is_string($v)) return false;
    $s = sec_str($v, $max);
    return ($s === '' && $nullo) ? null : $s;
}

/** Un documento di carta (JSON del sito o testo): non si tocca, ma ha un tetto di dimensione. */
function sync_fc_documento($v) {
    if ($v === null || $v === '') return null;
    if (!is_string($v) || strlen($v) > SYNC_FC_DOC_MAX) return false;
    if (strpos($v, "\0") !== false) return false;
    return $v;
}

/** Rifiuta i campi di sola lettura e quelli sconosciuti. Ritorna un esito di errore o null. */
function sync_fc_controlla(string $tbl, array $patch, bool $creazione): ?array {
    if ($er = sync_controlla_chiavi($tbl, $patch, $creazione)) return $er;
    foreach ($patch as $k => $v) {
        $def = SYNC_MANIFESTO[$tbl]['campi'][$k];
        if (!empty($def['solo_lettura'])) return sync_rifiuto('validation', $k, "$k is read-only");
        if (!$creazione && !empty($def['solo_creazione'])) return sync_rifiuto('validation', $k, "$k cannot change");
    }
    return null;
}

/** Un uuid del patch -> id della riga di quell'utente in $tbl. Ritorna [id|null, errore|null]. */
function sync_fc_risolvi(string $tbl, int $uid, $uuid, string $campo, string $nome): array {
    if (!sync_uuid_valido($uuid)) return [null, sync_rifiuto('validation', $campo, 'Invalid uuid')];
    $id = sync_id_da_uuid($tbl, $uid, $uuid);
    if ($id === null) return [null, sync_rifiuto('not_found', $campo, "$nome not found")];
    return [$id, null];
}

/* ---------------- mazzi ---------------- */

/** Gli id del mazzo e di tutti i suoi discendenti (il mazzo per primo). */
function sync_fc_ramo(int $uid, int $radice): array {
    $ids = [$radice]; $da = [$radice]; $giri = 0;
    while ($da && $giri++ < 60) {
        $segn = implode(',', array_fill(0, count($da), '?'));
        $figli = sec_all("SELECT id FROM fc_study_decks WHERE user_id = ? AND parent_id IN ($segn)", array_merge([$uid], $da));
        $da = [];
        foreach ($figli as $f) if (!in_array((int)$f['id'], $ids, true)) { $ids[] = (int)$f['id']; $da[] = (int)$f['id']; }
    }
    return $ids;
}

/** Toglie le carte (e le loro carte figlie) di un mazzo, annotando ogni riga. */
function sync_fc_svuota_mazzo(int $idMazzo): void {
    sec_q("DELETE FROM fc_study_subcards WHERE parent_id IN (SELECT id FROM fc_study_cards WHERE category_id = ?)", [$idMazzo]);
    sec_q("DELETE FROM fc_study_cards WHERE category_id = ?", [$idMazzo]);
}

function sync_fc_mazzi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_study_decks WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) {
            // dal basso: prima i mazzi piu' in fondo al ramo
            foreach (array_reverse(sync_fc_ramo($uid, (int)$riga['id'])) as $id) {
                sync_fc_svuota_mazzo($id);
                sec_q("DELETE FROM fc_study_decks WHERE id = ? AND user_id = ?", [$id, $uid]);
            }
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_decks', $patch, $riga === null)) return $er;

    $r = $riga ?: ['name' => '', 'parent_id' => 0, 'color' => '#A0A0A0', 'color_edited' => 0, 'archived' => 0, 'icon' => null,
                   'notes' => null, 'pinned' => 0, 'exam_date' => null];
    if (array_key_exists('name', $patch)) {
        $n = sync_fc_testo($patch['name'], 120, false);
        if ($n === false || $n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $r['name'] = $n;
    } elseif ($riga === null) {
        return sync_rifiuto('validation', 'name', 'name is required');
    }
    if (array_key_exists('parent_uuid', $patch)) {
        if ($patch['parent_uuid'] === null) $r['parent_id'] = 0;
        else {
            [$pid, $er] = sync_fc_risolvi('fc_study_decks', $uid, $patch['parent_uuid'], 'parent_uuid', 'Parent deck');
            if ($er) return $er;
            // il padre non puo' essere il mazzo stesso ne' un suo discendente: l'albero si spezzerebbe
            if ($riga && in_array($pid, sync_fc_ramo($uid, (int)$riga['id']), true)) return sync_rifiuto('validation', 'parent_uuid', 'A deck cannot go inside itself');
            $r['parent_id'] = $pid;
        }
    }
    if (array_key_exists('color', $patch)) {
        if (!is_string($patch['color']) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $patch['color'])) return sync_rifiuto('validation', 'color', 'color must be #RRGGBB');
        $r['color'] = strtoupper($patch['color']);
    }
    foreach (['color_edited', 'archived', 'pinned'] as $b) {
        if (!array_key_exists($b, $patch)) continue;
        if (!is_bool($patch[$b])) return sync_rifiuto('validation', $b, "$b must be true or false");
        $r[$b] = $patch[$b] ? 1 : 0;
    }
    if (array_key_exists('icon', $patch)) {
        if ($patch['icon'] === null || $patch['icon'] === '') $r['icon'] = null;
        elseif (!is_string($patch['icon']) || !fc_icona_valida($patch['icon'])) return sync_rifiuto('validation', 'icon', 'Unknown icon');
        else $r['icon'] = $patch['icon'];
    }
    if (array_key_exists('notes', $patch)) {
        $v = sync_fc_testo($patch['notes'], 20000, true);
        if ($v === false) return sync_rifiuto('validation', 'notes', 'notes must be text');
        $r['notes'] = $v;
    }
    if (array_key_exists('exam_date', $patch)) {
        if ($patch['exam_date'] === null || $patch['exam_date'] === '') $r['exam_date'] = null;
        else {
            $v = is_string($patch['exam_date']) ? sec_date($patch['exam_date'], null) : null;
            if ($v === null) return sync_rifiuto('validation', 'exam_date', 'exam_date must be YYYY-MM-DD');
            $r['exam_date'] = $v;
        }
    }

    if ($riga === null) {
        if (sec_one("SELECT id FROM fc_study_decks WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'fc_study_decks', 'uuid' => $uuid];
        sec_q("INSERT INTO fc_study_decks (name, parent_id, color, color_edited, archived, icon, notes, pinned, exam_date, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$r['name'], $r['parent_id'], $r['color'], $r['color_edited'], $r['archived'], $r['icon'], $r['notes'], $r['pinned'], $r['exam_date'], $uid]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
    } else {
        sec_q("UPDATE fc_study_decks SET name = ?, parent_id = ?, color = ?, color_edited = ?, archived = ?, icon = ?, notes = ?, pinned = ?, exam_date = ? WHERE id = ? AND user_id = ?",
              [$r['name'], $r['parent_id'], $r['color'], $r['color_edited'], $r['archived'], $r['icon'], $r['notes'], $r['pinned'], $r['exam_date'], (int)$riga['id'], $uid]);
    }
    return ['status' => 'applied'];
}

/* ---------------- carte padre ---------------- */

function sync_fc_carte_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_study_cards WHERE uuid = ? AND category_id IN (SELECT id FROM fc_study_decks WHERE user_id = ?)", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) {
            sec_q("DELETE FROM fc_study_subcards WHERE parent_id = ?", [(int)$riga['id']]);
            sec_q("DELETE FROM fc_study_cards WHERE id = ?", [(int)$riga['id']]);
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_cards', $patch, $riga === null)) return $er;

    $r = $riga ?: ['category_id' => 0, 'question_desc' => null, 'answer_desc' => null, 'is_father' => 0, 'custom_order' => 0];
    $spostata = false;
    if (array_key_exists('category_uuid', $patch)) {
        [$cid, $er] = sync_fc_risolvi('fc_study_decks', $uid, $patch['category_uuid'], 'category_uuid', 'Deck');
        if ($er) return $er;
        if ($riga && (int)$riga['category_id'] !== $cid) $spostata = true;
        $r['category_id'] = $cid;
    } elseif ($riga === null) {
        return sync_rifiuto('validation', 'category_uuid', 'category_uuid is required');
    }
    foreach (['question_desc', 'answer_desc'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        $v = sync_fc_documento($patch[$c]);
        if ($v === false) return sync_rifiuto('validation', $c, "$c is not valid");
        $r[$c] = $v;
    }
    if ($riga === null || $spostata) {
        $m = sec_one("SELECT COALESCE(MAX(custom_order), 0) + 1 AS n FROM fc_study_cards WHERE category_id = ?", [$r['category_id']]);
        $r['custom_order'] = (int)($m['n'] ?? 1);
    }

    if ($riga === null) {
        if (sec_one("SELECT id FROM fc_study_cards WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'fc_study_cards', 'uuid' => $uuid];
        sec_q("INSERT INTO fc_study_cards (category_id, question_desc, answer_desc, is_father, custom_order) VALUES (?,?,?,0,?)",
              [$r['category_id'], $r['question_desc'], $r['answer_desc'], $r['custom_order']]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
    } else {
        sec_q("UPDATE fc_study_cards SET category_id = ?, question_desc = ?, answer_desc = ?, custom_order = ? WHERE id = ?",
              [$r['category_id'], $r['question_desc'], $r['answer_desc'], $r['custom_order'], (int)$riga['id']]);
    }
    return ['status' => 'applied'];
}

/* ---------------- carte figlie ---------------- */

/** is_father di un padre: 1 se ha figli. Annota la carta solo se cambia. */
function sync_fc_ricalcola_padre(int $idPadre): void {
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_study_subcards WHERE parent_id = ?", [$idPadre])['n'] ?? 0);
    $cur = sec_one("SELECT is_father FROM fc_study_cards WHERE id = ?", [$idPadre]);
    if ($cur && (int)$cur['is_father'] !== ($n > 0 ? 1 : 0)) {
        sec_q("UPDATE fc_study_cards SET is_father = ? WHERE id = ?", [$n > 0 ? 1 : 0, $idPadre]);
    }
}

function sync_fc_figlie_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT s.* FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id
                     JOIN fc_study_decks d ON d.id = c.category_id WHERE s.uuid = ? AND d.user_id = ?", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) {
            sec_q("DELETE FROM fc_study_subcards WHERE id_child = ?", [(int)$riga['id_child']]);
            sync_fc_ricalcola_padre((int)$riga['parent_id']);
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_study_subcards', $patch, $riga === null)) return $er;

    $r = $riga ?: ['parent_id' => 0, 'question_desc' => null, 'answer_desc' => null, 'custom_order' => 0];
    if ($riga === null) {
        if (!array_key_exists('parent_uuid', $patch)) return sync_rifiuto('validation', 'parent_uuid', 'parent_uuid is required');
        [$pid, $er] = sync_fc_risolvi('fc_study_cards', $uid, $patch['parent_uuid'], 'parent_uuid', 'Parent card');
        if ($er) return $er;
        $r['parent_id'] = $pid;
    }
    foreach (['question_desc', 'answer_desc'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        $v = sync_fc_documento($patch[$c]);
        if ($v === false) return sync_rifiuto('validation', $c, "$c is not valid");
        $r[$c] = $v;
    }
    if ($riga === null) {
        if (sec_one("SELECT id_child FROM fc_study_subcards WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $m = sec_one("SELECT COALESCE(MAX(custom_order), 0) + 1 AS n FROM fc_study_subcards WHERE parent_id = ?", [$r['parent_id']]);
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'fc_study_subcards', 'uuid' => $uuid];
        sec_q("INSERT INTO fc_study_subcards (parent_id, question_desc, answer_desc, custom_order) VALUES (?,?,?,?)",
              [$r['parent_id'], $r['question_desc'], $r['answer_desc'], (int)($m['n'] ?? 1)]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        sync_fc_ricalcola_padre((int)$r['parent_id']);
    } else {
        sec_q("UPDATE fc_study_subcards SET question_desc = ?, answer_desc = ? WHERE id_child = ?",
              [$r['question_desc'], $r['answer_desc'], (int)$riga['id_child']]);
    }
    return ['status' => 'applied'];
}
