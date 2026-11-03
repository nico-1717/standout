<?php
/* =====================================================================
   STANDOUT — sync: Voti (esami, gruppi di esami, bonus) e impostazioni

   Le regole sono quelle di public/exams/voti.php (uni_exam_assignments e le azioni AJAX):
     - idoneita'  => niente voto, niente voto previsto
     - lode       => il voto e' 30 (anche il previsto, se c'e' la lode prevista)
     - la data dell'esame vale solo se l'esame e' superato
     - un voto fuori da 18..30 e' un errore, non un 30 (qui si rifiuta, sul sito diventava «vuoto»)
   Un esame, un gruppo o un bonus nascono e cambiano con una sola scrittura (la riga intera,
   dopo aver messo insieme la riga che c'e' gia' e le modifiche ricevute): cosi' il risultato non
   dipende dall'ordine in cui arrivano i campi.
   Sciogliere o togliere un gruppo lo fa l'app con piu' modifiche (una per esame); qui, per sicurezza,
   un gruppo tolto lascia comunque liberi gli esami che ne facevano parte.
   ===================================================================== */

const SYNC_UNI_BONUS_TIPI = ['fixed', 'on_time', 'honors_each', 'honors_threshold', 'custom'];

/** Intero in [min,max] oppure null; false se non e' un intero valido (null e' ammesso). */
function sync_uni_intero($v, int $min, int $max) {
    if ($v === null) return null;
    if (!is_int($v) || $v < $min || $v > $max) return false;
    return $v;
}

/** Numero in [min,max], arrotondato a $dec decimali; null ammesso se $nullo; false se non valido. */
function sync_uni_numero($v, float $min, float $max, int $dec, bool $nullo) {
    if ($v === null) return $nullo ? null : false;
    if (!is_int($v) && !is_float($v)) return false;
    if ($v < $min || $v > $max) return false;
    return round((float)$v, $dec);
}

/** Un campo booleano del patch (true/false veri), o false se non lo e'. */
function sync_uni_bool($v) { return is_bool($v) ? ($v ? 1 : 0) : false; }

/** Un testo: tolti tag e caratteri di controllo, tagliato; '' se vuoto. null resta null se $nullo. */
function sync_uni_testo($v, int $max, bool $nullo) {
    if ($v === null) return $nullo ? null : false;
    if (!is_string($v)) return false;
    $s = sec_str($v, $max);
    return ($s === '' && $nullo) ? null : $s;
}

/* ---- esami ---- */

function sync_uni_esami_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM university_exams WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM university_exams WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('university_exams', $patch, $id === null)) return $er;
    foreach (['created_at', 'updated_at'] as $ro) {
        if (array_key_exists($ro, $patch)) return sync_rifiuto('validation', $ro, "$ro is read-only");
    }
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    // la riga di partenza: quella che c'e', o i valori di una riga nuova
    $r = $riga ?: ['name' => '', 'cfu' => 0, 'status' => 'todo', 'grade' => null, 'has_honors' => 0, 'is_pass_fail' => 0,
                   'counts_for_graduation' => 1, 'exam_date' => null, 'predicted_grade' => null, 'predicted_honors' => 0,
                   'catalog_code' => null, 'ssd' => null, 'study_year' => null, 'national_avg' => null, 'group_id' => null];

    if (array_key_exists('name', $patch)) {
        $n = sync_uni_testo($patch['name'], 200, false);
        if ($n === false || $n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $r['name'] = $n;
    }
    if (array_key_exists('cfu', $patch)) {
        $v = sync_uni_numero($patch['cfu'], 0, 90, 1, false);
        if ($v === false) return sync_rifiuto('validation', 'cfu', 'cfu must be between 0 and 90');
        $r['cfu'] = $v;
    }
    if (array_key_exists('status', $patch)) {
        if (!in_array($patch['status'], ['todo', 'done'], true)) return sync_rifiuto('validation', 'status', 'status must be todo or done');
        $r['status'] = $patch['status'];
    }
    foreach (['grade', 'predicted_grade'] as $g) {
        if (!array_key_exists($g, $patch)) continue;
        $v = sync_uni_intero($patch[$g], 18, 30);
        if ($v === false) return sync_rifiuto('validation', $g, "$g must be between 18 and 30");
        $r[$g] = $v;
    }
    foreach (['has_honors', 'is_pass_fail', 'counts_for_graduation', 'predicted_honors'] as $b) {
        if (!array_key_exists($b, $patch)) continue;
        $v = sync_uni_bool($patch[$b]);
        if ($v === false) return sync_rifiuto('validation', $b, "$b must be true or false");
        $r[$b] = $v;
    }
    if (array_key_exists('exam_date', $patch)) {
        if ($patch['exam_date'] === null || $patch['exam_date'] === '') $r['exam_date'] = null;
        else {
            $v = is_string($patch['exam_date']) ? sec_date($patch['exam_date'], null) : null;
            if ($v === null) return sync_rifiuto('validation', 'exam_date', 'exam_date must be YYYY-MM-DD');
            $r['exam_date'] = $v;
        }
    }
    foreach (['catalog_code' => 60, 'ssd' => 20] as $c => $max) {
        if (!array_key_exists($c, $patch)) continue;
        $v = sync_uni_testo($patch[$c], $max, true);
        if ($v === false) return sync_rifiuto('validation', $c, "$c must be text");
        $r[$c] = $v;
    }
    if (array_key_exists('study_year', $patch)) {
        $v = sync_uni_intero($patch['study_year'], 1, 9);
        if ($v === false) return sync_rifiuto('validation', 'study_year', 'study_year must be between 1 and 9');
        $r['study_year'] = $v;
    }
    if (array_key_exists('national_avg', $patch)) {
        $v = sync_uni_numero($patch['national_avg'], 18, 30, 2, true);
        if ($v === false) return sync_rifiuto('validation', 'national_avg', 'national_avg must be between 18 and 30');
        $r['national_avg'] = $v;
    }
    if (array_key_exists('group_uuid', $patch)) {
        if ($patch['group_uuid'] === null) $r['group_id'] = null;
        else {
            if (!sync_uuid_valido($patch['group_uuid'])) return sync_rifiuto('validation', 'group_uuid', 'Invalid uuid');
            $g = sync_id_da_uuid('university_exam_groups', $uid, $patch['group_uuid']);
            if ($g === null) return sync_rifiuto('not_found', 'group_uuid', 'Group not found');
            $r['group_id'] = $g;
        }
    }

    // le regole del sito: idoneita', lode e data
    if ((int)$r['is_pass_fail'] === 1) { $r['grade'] = null; $r['predicted_grade'] = null; }
    if ((int)$r['has_honors'] === 1 && (int)$r['is_pass_fail'] === 0) $r['grade'] = 30;
    if ((int)$r['predicted_honors'] === 1 && (int)$r['is_pass_fail'] === 0) $r['predicted_grade'] = 30;
    if ($r['status'] !== 'done') $r['exam_date'] = null;

    if ($id === null) {
        if (sec_one("SELECT id FROM university_exams WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if ((string)$r['name'] === '') return sync_rifiuto('validation', 'name', 'name is required');
        $ord = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_exams WHERE user_id = ?", [$uid]);
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'university_exams', 'uuid' => $uuid];
        sec_q("INSERT INTO university_exams (user_id, sort_order, name, cfu, status, grade, has_honors, is_pass_fail, counts_for_graduation,
                                             exam_date, predicted_grade, predicted_honors, ssd, study_year, national_avg, catalog_code, group_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$uid, (int)$ord['n'], $r['name'], $r['cfu'], $r['status'], $r['grade'], $r['has_honors'], $r['is_pass_fail'],
               $r['counts_for_graduation'], $r['exam_date'], $r['predicted_grade'], $r['predicted_honors'], $r['ssd'],
               $r['study_year'], $r['national_avg'], $r['catalog_code'], $r['group_id']]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('university_exams', $uid, $uuid);
    } else {
        sec_q("UPDATE university_exams SET name=?, cfu=?, status=?, grade=?, has_honors=?, is_pass_fail=?, counts_for_graduation=?,
                      exam_date=?, predicted_grade=?, predicted_honors=?, ssd=?, study_year=?, national_avg=?, catalog_code=?, group_id=?
               WHERE id = ? AND user_id = ?",
              [$r['name'], $r['cfu'], $r['status'], $r['grade'], $r['has_honors'], $r['is_pass_fail'], $r['counts_for_graduation'],
               $r['exam_date'], $r['predicted_grade'], $r['predicted_honors'], $r['ssd'], $r['study_year'], $r['national_avg'],
               $r['catalog_code'], $r['group_id'], $id, $uid]);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('university_exams', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- gruppi di esami ---- */

function sync_uni_gruppi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM university_exam_groups WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) {
            sec_q("UPDATE university_exams SET group_id = NULL WHERE group_id = ? AND user_id = ?", [$id, $uid], 'ii');
            sec_q("DELETE FROM university_exam_groups WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('university_exam_groups', $patch, $id === null)) return $er;
    foreach (['created_at', 'updated_at'] as $ro) {
        if (array_key_exists($ro, $patch)) return sync_rifiuto('validation', $ro, "$ro is read-only");
    }
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $r = $riga ?: ['name' => 'Gruppo', 'group_type' => 'choice', 'cfu' => 0, 'overall_grade' => null, 'overall_honors' => 0,
                   'overall_predicted_grade' => null, 'overall_predicted_honors' => 0];
    if (array_key_exists('name', $patch)) {
        $n = sync_uni_testo($patch['name'], 120, false);
        if ($n === false) return sync_rifiuto('validation', 'name', 'name must be text');
        $r['name'] = $n === '' ? 'Gruppo' : $n;
    }
    if (array_key_exists('group_type', $patch)) {
        if (!in_array($patch['group_type'], ['choice', 'split'], true)) return sync_rifiuto('validation', 'group_type', 'group_type must be choice or split');
        $r['group_type'] = $patch['group_type'];
    }
    if (array_key_exists('cfu', $patch)) {
        $v = sync_uni_numero($patch['cfu'], 0, 600, 1, false);
        if ($v === false) return sync_rifiuto('validation', 'cfu', 'cfu must be between 0 and 600');
        $r['cfu'] = $v;
    }
    foreach (['overall_grade', 'overall_predicted_grade'] as $g) {
        if (!array_key_exists($g, $patch)) continue;
        $v = sync_uni_intero($patch[$g], 18, 30);
        if ($v === false) return sync_rifiuto('validation', $g, "$g must be between 18 and 30");
        $r[$g] = $v;
    }
    foreach (['overall_honors', 'overall_predicted_honors'] as $b) {
        if (!array_key_exists($b, $patch)) continue;
        $v = sync_uni_bool($patch[$b]);
        if ($v === false) return sync_rifiuto('validation', $b, "$b must be true or false");
        $r[$b] = $v;
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM university_exam_groups WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $ord = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_exam_groups WHERE user_id = ?", [$uid]);
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'university_exam_groups', 'uuid' => $uuid];
        sec_q("INSERT INTO university_exam_groups (user_id, sort_order, name, group_type, cfu, overall_grade, overall_honors, overall_predicted_grade, overall_predicted_honors)
               VALUES (?,?,?,?,?,?,?,?,?)",
              [$uid, (int)$ord['n'], $r['name'], $r['group_type'], $r['cfu'], $r['overall_grade'], $r['overall_honors'],
               $r['overall_predicted_grade'], $r['overall_predicted_honors']]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('university_exam_groups', $uid, $uuid);
    } else {
        sec_q("UPDATE university_exam_groups SET name=?, group_type=?, cfu=?, overall_grade=?, overall_honors=?, overall_predicted_grade=?, overall_predicted_honors=?
               WHERE id = ? AND user_id = ?",
              [$r['name'], $r['group_type'], $r['cfu'], $r['overall_grade'], $r['overall_honors'], $r['overall_predicted_grade'],
               $r['overall_predicted_honors'], $id, $uid]);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('university_exam_groups', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- bonus di laurea ---- */

function sync_uni_bonus_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM university_bonuses WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM university_bonuses WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('university_bonuses', $patch, $id === null)) return $er;
    foreach (['created_at', 'updated_at'] as $ro) {
        if (array_key_exists($ro, $patch)) return sync_rifiuto('validation', $ro, "$ro is read-only");
    }
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $r = $riga ?: ['name' => 'Bonus', 'bonus_type' => 'fixed', 'points' => 0, 'threshold_value' => null, 'cap_points' => 0, 'is_enabled' => 1];
    if (array_key_exists('name', $patch)) {
        $n = sync_uni_testo($patch['name'], 120, false);
        if ($n === false) return sync_rifiuto('validation', 'name', 'name must be text');
        $r['name'] = $n === '' ? 'Bonus' : $n;
    }
    if (array_key_exists('bonus_type', $patch)) {
        if (!in_array($patch['bonus_type'], SYNC_UNI_BONUS_TIPI, true)) return sync_rifiuto('validation', 'bonus_type', 'Invalid bonus_type');
        $r['bonus_type'] = $patch['bonus_type'];
    }
    if (array_key_exists('points', $patch)) {
        $v = sync_uni_numero($patch['points'], -20, 20, 2, false);
        if ($v === false) return sync_rifiuto('validation', 'points', 'points must be between -20 and 20');
        $r['points'] = $v;
    }
    if (array_key_exists('cap_points', $patch)) {
        $v = sync_uni_numero($patch['cap_points'], 0, 40, 2, false);
        if ($v === false) return sync_rifiuto('validation', 'cap_points', 'cap_points must be between 0 and 40');
        $r['cap_points'] = $v;
    }
    if (array_key_exists('threshold_value', $patch)) {
        $v = sync_uni_intero($patch['threshold_value'], 0, 100);
        if ($v === false) return sync_rifiuto('validation', 'threshold_value', 'threshold_value must be between 0 and 100');
        $r['threshold_value'] = $v;
    }
    if (array_key_exists('is_enabled', $patch)) {
        $v = sync_uni_bool($patch['is_enabled']);
        if ($v === false) return sync_rifiuto('validation', 'is_enabled', 'is_enabled must be true or false');
        $r['is_enabled'] = $v;
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM university_bonuses WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $ord = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_bonuses WHERE user_id = ?", [$uid]);
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'university_bonuses', 'uuid' => $uuid];
        sec_q("INSERT INTO university_bonuses (user_id, sort_order, name, bonus_type, points, cap_points, threshold_value, is_enabled)
               VALUES (?,?,?,?,?,?,?,?)",
              [$uid, (int)$ord['n'], $r['name'], $r['bonus_type'], $r['points'], $r['cap_points'], $r['threshold_value'], $r['is_enabled']]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('university_bonuses', $uid, $uuid);
    } else {
        sec_q("UPDATE university_bonuses SET name=?, bonus_type=?, points=?, cap_points=?, threshold_value=?, is_enabled=? WHERE id = ? AND user_id = ?",
              [$r['name'], $r['bonus_type'], $r['points'], $r['cap_points'], $r['threshold_value'], $r['is_enabled'], $id, $uid]);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('university_bonuses', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- impostazioni del corso (una riga per utente) ---- */

/** Le impostazioni come le legge l'app. accent_color e' quello salvato: #0A84FF vuol dire «mai scelto». */
function sync_uni_impostazioni(int $uid): array {
    sec_q("INSERT IGNORE INTO university_settings (user_id) VALUES (?)", [$uid]);
    $s = sec_one("SELECT * FROM university_settings WHERE user_id = ?", [$uid]);
    return [
        'program_key'       => $s['program_key'],
        'program_label'     => $s['program_label'],
        'faculty_key'       => $s['faculty_key'],
        'accent_color'      => $s['accent_color'],
        'total_cfu'         => (int)$s['total_cfu'],
        'grade_base'        => (int)$s['grade_base'],
        'honors_value'      => (float)$s['honors_value'],
        'target_average'    => $s['target_average'] === null ? null : (float)$s['target_average'],
        'target_source'     => $s['target_source'],
        'thesis_min_points' => (float)$s['thesis_min_points'],
        'thesis_max_points' => (float)$s['thesis_max_points'],
        'round_half_up'     => (int)$s['round_half_up'] === 1,
    ];
}

/** Salva i campi inviati. Ritorna null se va bene, [campo, testo] se no. */
function sync_uni_impostazioni_salva(int $uid, array $c): ?array {
    sync_uni_impostazioni($uid);
    $permessi = ['program_key', 'program_label', 'faculty_key', 'accent_color', 'total_cfu', 'grade_base', 'honors_value',
                 'target_average', 'target_source', 'thesis_min_points', 'thesis_max_points', 'round_half_up'];
    foreach ($c as $k => $_) if (!in_array($k, $permessi, true)) return [(string)$k, 'Unknown field'];
    $cur = sec_one("SELECT thesis_min_points, thesis_max_points FROM university_settings WHERE user_id = ?", [$uid]);
    $set = [];
    foreach (['program_key' => 80, 'program_label' => 160, 'faculty_key' => 60] as $k => $max) {
        if (!array_key_exists($k, $c)) continue;
        $v = sync_uni_testo($c[$k], $max, true);
        if ($v === false) return [$k, "$k must be text"];
        $set[$k] = $v;
    }
    if (array_key_exists('accent_color', $c)) {
        if (!is_string($c['accent_color']) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $c['accent_color'])) return ['accent_color', 'accent_color must be like #AABBCC'];
        $set['accent_color'] = strtoupper($c['accent_color']);
    }
    $int = ['total_cfu' => [1, 600], 'grade_base' => [30, 200]];
    foreach ($int as $k => [$mn, $mx]) {
        if (!array_key_exists($k, $c)) continue;
        $v = sync_uni_intero($c[$k], $mn, $mx);
        if ($v === false || $v === null) return [$k, "$k must be between $mn and $mx"];
        $set[$k] = $v;
    }
    if (array_key_exists('honors_value', $c)) {
        $v = sync_uni_numero($c['honors_value'], 30, 40, 2, false);
        if ($v === false) return ['honors_value', 'honors_value must be between 30 and 40'];
        $set['honors_value'] = $v;
    }
    if (array_key_exists('target_average', $c)) {
        $v = sync_uni_numero($c['target_average'], 18, 31, 2, true);
        if ($v === false) return ['target_average', 'target_average must be between 18 and 31'];
        $set['target_average'] = $v;
    }
    if (array_key_exists('target_source', $c)) {
        if (!in_array($c['target_source'], ['manual', 'simulation'], true)) return ['target_source', 'target_source must be manual or simulation'];
        $set['target_source'] = $c['target_source'];
    }
    $min = (float)$cur['thesis_min_points'];
    if (array_key_exists('thesis_min_points', $c)) {
        $v = sync_uni_numero($c['thesis_min_points'], 0, 30, 2, false);
        if ($v === false) return ['thesis_min_points', 'thesis_min_points must be between 0 and 30'];
        $set['thesis_min_points'] = $v; $min = $v;
    }
    if (array_key_exists('thesis_max_points', $c)) {
        $v = sync_uni_numero($c['thesis_max_points'], 0, 30, 2, false);
        if ($v === false) return ['thesis_max_points', 'thesis_max_points must be between 0 and 30'];
        $set['thesis_max_points'] = $v;
    }
    $max = isset($set['thesis_max_points']) ? $set['thesis_max_points'] : (float)$cur['thesis_max_points'];
    if ((isset($set['thesis_min_points']) || isset($set['thesis_max_points'])) && $max < $min) return ['thesis_max_points', 'thesis_max_points cannot be lower than thesis_min_points'];
    if (array_key_exists('round_half_up', $c)) {
        $v = sync_uni_bool($c['round_half_up']);
        if ($v === false) return ['round_half_up', 'round_half_up must be true or false'];
        $set['round_half_up'] = $v;
    }
    if ($set) {
        $par = array_values($set); $par[] = $uid;
        sec_q("UPDATE university_settings SET " . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set))) . " WHERE user_id = ?", $par);
    }
    return null;
}

function api_h_voti_impostazioni(array $ctx): void {
    api_ok(['settings' => sync_uni_impostazioni((int)$ctx['utente']['id'])]);
}
function api_h_voti_impostazioni_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_voti_write');
    $uid = (int)$ctx['utente']['id'];
    $e = sync_uni_impostazioni_salva($uid, api_corpo());
    if ($e) api_errore('validation', $e[1], 422, ['field' => $e[0]]);
    api_ok(['settings' => sync_uni_impostazioni($uid)]);
}
