<?php
/* =====================================================================
   STANDOUT — sync: il piano dei progetti
   (obiettivi, giorni evidenziati, blocchi del giorno, «il tuo tempo», modelli)

   Le regole sono quelle di public/projects/piano_api.php (goal_save, paint, day_set, settings_save, tpl_*):
     - un obiettivo e' una riga per (utente, progetto): tempo in minuti, tipo, priorita', date; la sua identita'
       e' un UUIDv5 del progetto, cosi' sito e telefono creano LA STESSA riga
     - un giorno evidenziato esiste o non esiste: UUIDv5 di progetto + giorno
     - un blocco e' (progetto, giorno, tipo): minuti e chi l'ha messo (auto = il pianificatore, manual = a mano)
     - «il tuo tempo» e' una riga per utente; i giorni con tempo speciale stanno in `overrides` (JSON data => minuti)
     - un modello e' un nome e una forma (JSON) con tempo, tipo e priorita' per percorso di nome
   Il telefono porta il piano GIA' calcolato (il pianificatore gira anche sul telefono, offline): qui si controllano
   solo forma e limiti di ogni riga, e che i progetti siano dell'utente.
   ===================================================================== */

const SYNC_PIANO_KINDS = ['plain', 'exam', 'steady', 'course', 'count', 'daily'];
const SYNC_PIANO_PRIO  = ['must', 'should', 'nice'];

/** Una data del filo o null (se $puoNull). Ritorna [valore, errore]. */
function sync_piano_data(array $patch, string $campo, bool $puoNull = true): array {
    if (!array_key_exists($campo, $patch)) return [null, null];
    $v = $patch[$campo];
    if ($v === null) return $puoNull ? [null, null] : [null, sync_rifiuto('validation', $campo, "$campo is required")];
    $d = sync_fc2_data($v);
    return $d === null ? [null, sync_rifiuto('validation', $campo, "$campo must be YYYY-MM-DD")] : [$d, null];
}

/** Un intero (o null) del filo fra $min e $max. Ritorna [valore, errore]. */
function sync_piano_intero(array $patch, string $campo, int $min, int $max, bool $puoNull = false): array {
    if (!array_key_exists($campo, $patch)) return [null, null];
    $v = $patch[$campo];
    if ($v === null && $puoNull) return [null, null];
    $n = sync_fc2_intero($v, $min, $max);
    return $n === null ? [null, sync_rifiuto('validation', $campo, "$campo must be a whole number from $min to $max")] : [$n, null];
}

/** Il progetto del patch, dell'utente. Ritorna [id|null, errore|null]. */
function sync_piano_progetto(int $uid, array $patch): array {
    return sync_fc_risolvi('projects', $uid, $patch['project_uuid'] ?? null, 'project_uuid', 'Project');
}

/* ---------------- obiettivi ---------------- */

function sync_pgoal_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM plan_goals WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM plan_goals WHERE user_id = ? AND project_id = ?", [$uid, (int)$riga['project_id']], 'ii');
        return ['status' => 'applied'];
    }
    // rimandare la creazione di una riga che c'e' gia' (risposta persa) non e' un errore: i campi di nascita uguali si ignorano
    if ($riga && isset($patch['project_uuid']) && is_string($patch['project_uuid']) && sync_uuid_piano_obiettivo($patch['project_uuid']) === $uuid) unset($patch['project_uuid']);
    if ($er = sync_fc_controlla('plan_goals', $patch, $riga === null)) return $er;
    $pid = $riga ? (int)$riga['project_id'] : 0;
    if ($riga === null) {
        [$pid, $er] = sync_piano_progetto($uid, $patch);
        if ($er) return $er;
        if (sync_uuid_piano_obiettivo($patch['project_uuid']) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the project');
        // l'obiettivo c'e' gia' (messo dal sito): e' LA STESSA riga, si aggiorna
        $riga = sec_one("SELECT * FROM plan_goals WHERE user_id = ? AND project_id = ?", [$uid, $pid]);
    }
    $v = $riga ?: ['active' => 1, 'minutes' => 0, 'locked' => 0, 'kind' => 'plain', 'priority' => 'should',
                   'start_date' => null, 'deadline' => null, 'since' => null, 'items' => null, 'unit_minutes' => null];
    foreach (['active', 'locked'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        if (!is_bool($patch[$c])) return sync_rifiuto('validation', $c, "$c must be true or false");
        $v[$c] = $patch[$c] ? 1 : 0;
    }
    foreach ([['minutes', 0, 600000, false], ['items', 0, 100000, true], ['unit_minutes', 0, 1440, true]] as [$c, $min, $max, $nul]) {
        [$n, $er] = sync_piano_intero($patch, $c, $min, $max, $nul);
        if ($er) return $er;
        if (array_key_exists($c, $patch)) $v[$c] = $n;
    }
    if (array_key_exists('kind', $patch)) {
        if (!in_array($patch['kind'], SYNC_PIANO_KINDS, true)) return sync_rifiuto('validation', 'kind', 'kind is not valid');
        $v['kind'] = $patch['kind'];
    }
    if (array_key_exists('priority', $patch)) {
        if (!in_array($patch['priority'], SYNC_PIANO_PRIO, true)) return sync_rifiuto('validation', 'priority', 'priority is not valid');
        $v['priority'] = $patch['priority'];
    }
    foreach (['start_date', 'deadline', 'since'] as $c) {
        [$d, $er] = sync_piano_data($patch, $c);
        if ($er) return $er;
        if (array_key_exists($c, $patch)) $v[$c] = $d;
    }
    sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, locked, kind, priority, start_date, deadline, since, items, unit_minutes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE active=VALUES(active), minutes=VALUES(minutes), locked=VALUES(locked), kind=VALUES(kind),
             priority=VALUES(priority), start_date=VALUES(start_date), deadline=VALUES(deadline), since=VALUES(since),
             items=VALUES(items), unit_minutes=VALUES(unit_minutes)",
          [$uid, $pid, (int)$v['active'], (int)$v['minutes'], (int)$v['locked'], $v['kind'], $v['priority'],
           $v['start_date'], $v['deadline'], $v['since'], $v['items'], $v['unit_minutes']],
          'iiiiisssssii');
    return ['status' => 'applied'];
}

/* ---------------- giorni evidenziati ---------------- */

function sync_ppaint_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM plan_paint WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM plan_paint WHERE user_id = ? AND project_id = ? AND day = ?", [$uid, (int)$riga['project_id'], (string)$riga['day']], 'iis');
        return ['status' => 'applied'];
    }
    if ($riga && isset($patch['project_uuid'], $patch['day']) && is_string($patch['project_uuid']) && is_string($patch['day'])
        && sync_uuid_piano_giorno_evid($patch['project_uuid'], $patch['day']) === $uuid) { unset($patch['project_uuid'], $patch['day']); }
    if ($er = sync_fc_controlla('plan_paint', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];
    [$pid, $er] = sync_piano_progetto($uid, $patch);
    if ($er) return $er;
    [$giorno, $er] = sync_piano_data($patch, 'day', false);
    if ($er) return $er;
    if ($giorno === null) return sync_rifiuto('validation', 'day', 'day is required');
    if (sync_uuid_piano_giorno_evid($patch['project_uuid'], $giorno) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the project and day');
    sec_q("INSERT IGNORE INTO plan_paint (user_id, project_id, day) VALUES (?,?,?)", [$uid, $pid, $giorno], 'iis');
    return ['status' => 'applied'];
}

/* ---------------- blocchi del piano ---------------- */

function sync_pdays_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM plan_days WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM plan_days WHERE user_id = ? AND day = ? AND project_id = ? AND kind = ?",
                         [$uid, (string)$riga['day'], (int)$riga['project_id'], (string)$riga['kind']], 'isis');
        return ['status' => 'applied'];
    }
    if ($riga && isset($patch['project_uuid'], $patch['day'], $patch['kind']) && is_string($patch['project_uuid']) && is_string($patch['day']) && is_string($patch['kind'])
        && sync_uuid_piano_blocco($patch['project_uuid'], $patch['day'], $patch['kind']) === $uuid) { unset($patch['project_uuid'], $patch['day'], $patch['kind']); }
    if ($er = sync_fc_controlla('plan_days', $patch, $riga === null)) return $er;
    $pid = $riga ? (int)$riga['project_id'] : 0;
    $giorno = $riga ? (string)$riga['day'] : null;
    $tipo = $riga ? (string)$riga['kind'] : null;
    if ($riga === null) {
        [$pid, $er] = sync_piano_progetto($uid, $patch);
        if ($er) return $er;
        [$giorno, $er] = sync_piano_data($patch, 'day', false);
        if ($er) return $er;
        if ($giorno === null) return sync_rifiuto('validation', 'day', 'day is required');
        $tipo = $patch['kind'] ?? null;
        if (!in_array($tipo, ['study', 'review'], true)) return sync_rifiuto('validation', 'kind', 'kind must be study or review');
        if (sync_uuid_piano_blocco($patch['project_uuid'], $giorno, $tipo) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the project, day and kind');
        $riga = sec_one("SELECT * FROM plan_days WHERE user_id = ? AND day = ? AND project_id = ? AND kind = ?", [$uid, $giorno, $pid, $tipo]);
    }
    $minuti = $riga ? (int)$riga['minutes'] : null;
    $fonte = $riga ? (string)$riga['source'] : 'auto';
    if (array_key_exists('minutes', $patch)) {
        [$minuti, $er] = sync_piano_intero($patch, 'minutes', 1, 1440);
        if ($er) return $er;
    }
    if ($minuti === null) return sync_rifiuto('validation', 'minutes', 'minutes is required');
    if (array_key_exists('source', $patch)) {
        if (!in_array($patch['source'], ['auto', 'manual'], true)) return sync_rifiuto('validation', 'source', 'source must be auto or manual');
        $fonte = $patch['source'];
    }
    sec_q("INSERT INTO plan_days (user_id, day, project_id, kind, minutes, source) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE minutes = VALUES(minutes), source = VALUES(source)",
          [$uid, $giorno, $pid, $tipo, $minuti, $fonte], 'isisis');
    return ['status' => 'applied'];
}

/* ---------------- il tuo tempo ---------------- */

/** I giorni con tempo speciale: JSON {"AAAA-MM-GG": minuti}; ritorna il testo pulito (o null se vuoto) oppure un errore. */
function sync_piano_overrides($v): array {
    if ($v === null || $v === '') return [null, null];
    if (!is_string($v) || strlen($v) > 20000) return [null, sync_rifiuto('validation', 'overrides', 'overrides must be a JSON text')];
    $j = json_decode($v, true);
    if (!is_array($j)) return [null, sync_rifiuto('validation', 'overrides', 'overrides must be a JSON object')];
    $pulito = [];
    foreach ($j as $d => $m) {
        if (!is_string($d) || sec_date($d, null) === null || !is_int($m) || $m < 0 || $m > 1440) return [null, sync_rifiuto('validation', 'overrides', 'overrides must map dates to minutes')];
        $pulito[$d] = $m;
    }
    return [$pulito ? json_encode($pulito) : null, null];
}

function sync_pset_applica(int $uid, string $uuid, string $op, array $patch): array {
    if (sync_uuid_per_utente('plan_settings', $uid) !== $uuid) return sync_rifiuto('forbidden', 'uuid', 'Not your settings');
    if ($op === 'delete') {
        sec_q("DELETE FROM plan_settings WHERE user_id = ?", [$uid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM plan_settings WHERE user_id = ?", [$uid]);
    if ($er = sync_fc_controlla('plan_settings', $patch, $riga === null)) return $er;
    $v = $riga ?: ['weekday_min' => null, 'weekend_min' => null, 'rest_dow' => 0, 'buffer_days' => 0, 'floor_min' => 15,
                   'max_subjects' => 3, 'reviews' => 0, 'overrides' => null];
    foreach ([['weekday_min', 0, 1440, true], ['weekend_min', 0, 1440, true], ['rest_dow', 0, 7, false], ['buffer_days', 0, 14, false],
              ['floor_min', 5, 120, false], ['max_subjects', 1, 8, false]] as [$c, $min, $max, $nul]) {
        [$n, $er] = sync_piano_intero($patch, $c, $min, $max, $nul);
        if ($er) return $er;
        if (array_key_exists($c, $patch)) $v[$c] = $n;
    }
    if (array_key_exists('reviews', $patch)) {
        if (!is_bool($patch['reviews'])) return sync_rifiuto('validation', 'reviews', 'reviews must be true or false');
        $v['reviews'] = $patch['reviews'] ? 1 : 0;
    }
    if (array_key_exists('overrides', $patch)) {
        [$ov, $er] = sync_piano_overrides($patch['overrides']);
        if ($er) return $er;
        $v['overrides'] = $ov;
    }
    sec_q("INSERT INTO plan_settings (user_id, weekday_min, weekend_min, rest_dow, buffer_days, floor_min, max_subjects, reviews, overrides)
           VALUES (?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE weekday_min=VALUES(weekday_min), weekend_min=VALUES(weekend_min), rest_dow=VALUES(rest_dow),
             buffer_days=VALUES(buffer_days), floor_min=VALUES(floor_min), max_subjects=VALUES(max_subjects),
             reviews=VALUES(reviews), overrides=VALUES(overrides)",
          [$uid, $v['weekday_min'], $v['weekend_min'], (int)$v['rest_dow'], (int)$v['buffer_days'], (int)$v['floor_min'],
           (int)$v['max_subjects'], (int)$v['reviews'], $v['overrides']],
          'iiiiiiiis');
    return ['status' => 'applied'];
}

/* ---------------- modelli ---------------- */

/** La forma di un modello: JSON con una lista di {path, minutes, kind, priority, locked}. */
function sync_piano_forma($v): ?array {
    if (!is_string($v) || strlen($v) > 60000) return sync_rifiuto('validation', 'body', 'body must be a JSON text');
    $j = json_decode($v, true);
    if (!is_array($j) || !array_is_list($j) || count($j) > 400) return sync_rifiuto('validation', 'body', 'body must be a list');
    foreach ($j as $voce) {
        if (!is_array($voce) || !isset($voce['path']) || !is_string($voce['path']) || strlen($voce['path']) > 2000) return sync_rifiuto('validation', 'body', 'each item needs a path');
        if (!isset($voce['minutes']) || !is_int($voce['minutes']) || $voce['minutes'] < 0 || $voce['minutes'] > 600000) return sync_rifiuto('validation', 'body', 'minutes is not valid');
        if (isset($voce['kind']) && !in_array($voce['kind'], SYNC_PIANO_KINDS, true)) return sync_rifiuto('validation', 'body', 'kind is not valid');
        if (isset($voce['priority']) && !in_array($voce['priority'], SYNC_PIANO_PRIO, true)) return sync_rifiuto('validation', 'body', 'priority is not valid');
    }
    return null;
}

function sync_ptpl_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('plan_templates', $uid, $uuid);
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM plan_templates WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('plan_templates', $patch, $id === null)) return $er;
    $nome = null; $corpo = null;
    if (array_key_exists('name', $patch)) {
        $nome = is_string($patch['name']) ? trim($patch['name']) : '';
        if ($nome === '' || mb_strlen($nome) > 80) return sync_rifiuto('validation', 'name', 'name must be 1 to 80 characters');
    }
    if (array_key_exists('body', $patch)) {
        if ($er = sync_piano_forma($patch['body'])) return $er;
        $corpo = $patch['body'];
    }
    if ($id !== null) {
        if ($nome !== null) sec_q("UPDATE plan_templates SET name = ? WHERE id = ? AND user_id = ?", [$nome, $id, $uid], 'sii');
        if ($corpo !== null) sec_q("UPDATE plan_templates SET body = ? WHERE id = ? AND user_id = ?", [$corpo, $id, $uid], 'sii');
        return ['status' => 'applied'];
    }
    if ($nome === null || $corpo === null) return sync_rifiuto('validation', 'name', 'name and body are required');
    if ($er = sync_fc2_inserisci('plan_templates', $uuid, "INSERT INTO plan_templates (user_id, name, body) VALUES (?,?,?)", [$uid, $nome, $corpo])) return $er;
    return ['status' => 'applied'];
}
