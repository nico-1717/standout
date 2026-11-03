<?php
/* =====================================================================
   STANDOUT — sync: progetti, sessioni del Pomodoro, durate salvate

   I progetti sono un albero (parent_id) con un colore che si eredita dal padre finche' non lo si
   sceglie a mano (color_edited). Le regole sono quelle di public/projects/progetti.php: colore
   ereditato, cascata del colore ai figli «automatici», niente cicli, nomi mai vuoti.
   Lo stato «completato» a cascata lo fa chi lo cambia (l'app manda una modifica per ogni figlio).

   Le sessioni del Pomodoro sono righe come sul sito (pomodoro_sessions): una sola puo' essere aperta
   (end_time vuoto) per utente, come controlla `start` di pomodoro.php.
   ===================================================================== */

require_once __DIR__ . '/progetti_note.php';

const SYNC_PROG_STATI = ['in_progress', 'completed', 'planned'];

/** Un colore #RRGGBB o null. */
function sync_prog_hex($v): ?string {
    return (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) ? $v : null;
}

/** Il colore che un progetto eredita da questo padre (come prog_resolve_color). */
function sync_prog_colore_ereditato(int $uid, int $padreId): string {
    $guard = 0;
    while ($padreId > 0 && $guard < 50) {
        $guard++;
        $r = sec_one("SELECT parent_id, color, color_edited FROM projects WHERE id = ? AND user_id = ?", [$padreId, $uid]);
        if (!$r) break;
        if ((int)$r['color_edited'] === 1 && !empty($r['color'])) return (string)$r['color'];
        $padreId = (int)$r['parent_id'];
    }
    return '#8E8E93';
}

/** Il colore passa ai figli che non l'hanno scelto a mano (come prog_cascade_color). */
function sync_prog_cascata(int $uid, int $id, string $colore, int $prof = 0): void {
    if ($prof > 40) return;
    foreach (sec_all("SELECT id FROM projects WHERE parent_id = ? AND user_id = ? AND color_edited = 0", [$id, $uid]) as $f) {
        sec_q("UPDATE projects SET color = ? WHERE id = ? AND user_id = ?", [$colore, (int)$f['id'], $uid]);
        sync_prog_cascata($uid, (int)$f['id'], $colore, $prof + 1);
    }
}

/** true se $candidato e' $id o un suo discendente: spostare $id sotto $candidato farebbe un ciclo. */
function sync_prog_e_discendente(int $uid, int $id, int $candidato): bool {
    $walk = $candidato; $guard = 0;
    while ($walk > 0 && $guard < 60) {
        $guard++;
        if ($walk === $id) return true;
        $r = sec_one("SELECT parent_id FROM projects WHERE id = ? AND user_id = ?", [$walk, $uid]);
        if (!$r) break;
        $walk = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    }
    return false;
}

function sync_prog_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, parent_id, color, color_edited FROM projects WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        // i figli se ne vanno con lui (chiave esterna a cascata); le sessioni restano, senza progetto
        if ($id !== null) sec_q("DELETE FROM projects WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('projects', $patch, $id === null)) return $er;
    foreach (['created_at', 'updated_at', 'study_event_uuid', 'study_start', 'study_end', 'study_ripasso_di_uuid', 'study_ripasso_n'] as $ro) {
        if (array_key_exists($ro, $patch)) return sync_rifiuto('validation', $ro, "$ro is read-only");
    }
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $set = [];
    if (array_key_exists('name', $patch)) {
        $n = is_string($patch['name']) ? sec_str($patch['name'], 200) : '';
        if ($n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $set['name'] = $n;
    }
    if (array_key_exists('notes', $patch)) {
        if ($patch['notes'] !== null && !is_string($patch['notes'])) return sync_rifiuto('validation', 'notes', 'notes must be text');
        $set['notes'] = prog_nota_pulita((string)$patch['notes'], 8000);
    }
    if (array_key_exists('status', $patch)) {
        if (!in_array($patch['status'], SYNC_PROG_STATI, true)) return sync_rifiuto('validation', 'status', 'status must be in_progress, completed or planned');
        $set['status'] = $patch['status'];
    }
    foreach (['target_date', 'due_date'] as $d) {
        if (!array_key_exists($d, $patch)) continue;
        if ($patch[$d] === null || $patch[$d] === '') { $set[$d] = null; continue; }
        $v = is_string($patch[$d]) ? sec_date($patch[$d], null) : null;
        if ($v === null) return sync_rifiuto('validation', $d, "$d must be YYYY-MM-DD");
        $set[$d] = $v;
    }
    foreach (['todo_enabled', 'is_favorite', 'color_edited'] as $b) {
        if (!array_key_exists($b, $patch)) continue;
        if (!is_bool($patch[$b])) return sync_rifiuto('validation', $b, "$b must be true or false");
        $set[$b] = $patch[$b] ? 1 : 0;
    }
    if (array_key_exists('study_pomodori_stimati', $patch)) {
        $v = $patch['study_pomodori_stimati'];
        if ($v !== null && (!is_int($v) || $v < 0 || $v > 65535)) return sync_rifiuto('validation', 'study_pomodori_stimati', 'Invalid number');
        $set['study_pomodori_stimati'] = $v;
    }
    if (array_key_exists('color', $patch)) {
        $c = sync_prog_hex($patch['color']);
        if ($c === null) return sync_rifiuto('validation', 'color', 'color must be like #AABBCC');
        $set['color'] = $c;
    }

    // il padre: null = radice; uno dei suoi, mai se stesso o un discendente
    $padreNuovo = $riga ? ($riga['parent_id'] === null ? 0 : (int)$riga['parent_id']) : 0;
    $padreCambia = false;
    if (array_key_exists('parent_uuid', $patch)) {
        if ($patch['parent_uuid'] === null) $padreNuovo = 0;
        else {
            if (!sync_uuid_valido($patch['parent_uuid'])) return sync_rifiuto('validation', 'parent_uuid', 'Invalid uuid');
            $p = sync_id_da_uuid('projects', $uid, $patch['parent_uuid']);
            if ($p === null) return sync_rifiuto('not_found', 'parent_uuid', 'Parent project not found');
            if ($id !== null && sync_prog_e_discendente($uid, $id, $p)) return sync_rifiuto('validation', 'parent_uuid', 'A project cannot go inside itself');
            $padreNuovo = $p;
        }
        $padreCambia = $id === null || $padreNuovo !== ($riga['parent_id'] === null ? 0 : (int)$riga['parent_id']);
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM projects WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['name'])) return sync_rifiuto('validation', 'name', 'name is required');
        $edited = $set['color_edited'] ?? 0;
        $colore = ($edited === 1 && isset($set['color'])) ? $set['color'] : sync_prog_colore_ereditato($uid, $padreNuovo);
        $padreVal = $padreNuovo > 0 ? $padreNuovo : null;
        $ord = $padreNuovo > 0
            ? sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS o FROM projects WHERE user_id = ? AND parent_id = ?", [$uid, $padreNuovo])
            : sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS o FROM projects WHERE user_id = ? AND parent_id IS NULL", [$uid]);
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'projects', 'uuid' => $uuid];
        sec_q("INSERT INTO projects (user_id, parent_id, name, notes, color, color_edited, status, custom_order, created_at, target_date, due_date, todo_enabled, is_favorite)
               VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,?)",
              [$uid, $padreVal, $set['name'], $set['notes'] ?? '', $colore, $edited, $set['status'] ?? 'in_progress', (int)$ord['o'],
               $set['target_date'] ?? null, $set['due_date'] ?? null, $set['todo_enabled'] ?? 0, $set['is_favorite'] ?? 0]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('projects', $uid, $uuid);
        foreach (['name', 'notes', 'color', 'color_edited', 'status', 'target_date', 'due_date', 'todo_enabled', 'is_favorite'] as $k) unset($set[$k]);
    } else {
        // un colore automatico segue sempre il padre ATTUALE
        $editedOra = array_key_exists('color_edited', $set) ? $set['color_edited'] : (int)$riga['color_edited'];
        if ($editedOra === 0 && ($padreCambia || array_key_exists('color_edited', $set))) {
            $set['color'] = sync_prog_colore_ereditato($uid, $padreNuovo);
        } elseif ($editedOra === 0 && isset($set['color'])) {
            unset($set['color']);   // non puo' scegliere un colore e restare «automatico»
        }
        if ($padreCambia) {
            $set['parent_id'] = $padreNuovo > 0 ? $padreNuovo : null;
            // chi arriva in un padre nuovo va in fondo ai suoi fratelli
            $ord = $padreNuovo > 0
                ? sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS o FROM projects WHERE user_id = ? AND parent_id = ?", [$uid, $padreNuovo])
                : sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS o FROM projects WHERE user_id = ? AND parent_id IS NULL", [$uid]);
            $set['custom_order'] = (int)$ord['o'];
        }
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE projects SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && (isset($set['color']) || $padreCambia || array_key_exists('color', $patch))) {
        $ora = sec_one("SELECT color FROM projects WHERE id = ? AND user_id = ?", [$id, $uid]);
        if ($ora) sync_prog_cascata($uid, $id, (string)$ora['color']);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('projects', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- sessioni del Pomodoro ---- */

/** 'AAAA-MM-GG HH:MM:SS' scritto bene, o null. */
function sync_pomo_ora($v): ?string {
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $v, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) return null;
    return $v;
}

function sync_pomo_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, end_time FROM pomodoro_sessions WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM pomodoro_sessions WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('pomodoro_sessions', $patch, $id === null)) return $er;
    if (array_key_exists('updated_at', $patch)) return sync_rifiuto('validation', 'updated_at', 'updated_at is read-only');

    $set = [];
    if (array_key_exists('mode', $patch)) {
        if ($id !== null) return sync_rifiuto('validation', 'mode', 'mode cannot change');
        if ($patch['mode'] !== 0 && $patch['mode'] !== 1) return sync_rifiuto('validation', 'mode', 'mode must be 0 (pomodoro) or 1 (stopwatch)');
        $set['mode'] = $patch['mode'];
    }
    foreach (['start_time', 'end_time', 'resume_time'] as $c) {
        if (!array_key_exists($c, $patch)) continue;
        if ($patch[$c] === null) {
            if ($c === 'start_time') return sync_rifiuto('validation', $c, 'start_time is required');
            $set[$c] = null; continue;
        }
        $v = sync_pomo_ora($patch[$c]);
        if ($v === null) return sync_rifiuto('validation', $c, "$c must be YYYY-MM-DD HH:MM:SS");
        $set[$c] = $v;
    }
    if (array_key_exists('status', $patch)) {
        if ($patch['status'] !== null && !in_array($patch['status'], ['pausa', 'completato', 'interrotto', 'abbandonato', 'archiviato'], true)) return sync_rifiuto('validation', 'status', 'Invalid status');
        $set['status'] = $patch['status'];
    }
    if (array_key_exists('duration_seconds', $patch)) {
        $d = $patch['duration_seconds'];
        if (!is_int($d) || $d < 0 || $d > 86400 * 2) return sync_rifiuto('validation', 'duration_seconds', 'Invalid duration');
        $set['duration_seconds'] = $d;
    }
    if (array_key_exists('planned_duration_min', $patch)) {
        $d = $patch['planned_duration_min'];
        if ($d !== null && (!is_int($d) || $d < 1 || $d > 600)) return sync_rifiuto('validation', 'planned_duration_min', 'planned_duration_min must be 1 to 600');
        $set['planned_duration_min'] = $d;
    }
    if (array_key_exists('is_manual', $patch)) {
        if (!is_int($patch['is_manual']) || $patch['is_manual'] < 0 || $patch['is_manual'] > 2) return sync_rifiuto('validation', 'is_manual', 'Invalid is_manual');
        $set['is_manual'] = $patch['is_manual'];
    }
    if (array_key_exists('project_uuid', $patch)) {
        if ($patch['project_uuid'] === null) $set['project_id'] = null;
        else {
            if (!sync_uuid_valido($patch['project_uuid'])) return sync_rifiuto('validation', 'project_uuid', 'Invalid uuid');
            $p = sync_id_da_uuid('projects', $uid, $patch['project_uuid']);
            if ($p === null) return sync_rifiuto('not_found', 'project_uuid', 'Project not found');
            $set['project_id'] = $p;
        }
    }

    // una sola sessione aperta per volta: chi arriva per secondo riceve il no, con lo stato vero nella risposta
    $restaAperta = $id === null ? (!array_key_exists('end_time', $set) || $set['end_time'] === null)
                                : (array_key_exists('end_time', $set) ? $set['end_time'] === null : $riga['end_time'] === null);
    if ($restaAperta) {
        $altra = sec_one("SELECT id FROM pomodoro_sessions WHERE user_id = ? AND end_time IS NULL" . ($id !== null ? " AND id <> ?" : ""),
                         $id !== null ? [$uid, $id] : [$uid]);
        if ($altra) return sync_rifiuto('conflict', 'end_time', 'Another session is already open');
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM pomodoro_sessions WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['mode']) && !array_key_exists('mode', $set)) return sync_rifiuto('validation', 'mode', 'mode is required');
        if (empty($set['start_time'])) return sync_rifiuto('validation', 'start_time', 'start_time is required');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'pomodoro_sessions', 'uuid' => $uuid];
        sec_q("INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, end_time, status, resume_time, duration_seconds, planned_duration_min, project_id, is_manual)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$uid, $set['mode'], $set['start_time'], $set['end_time'] ?? null, $set['status'] ?? null, $set['resume_time'] ?? null,
               $set['duration_seconds'] ?? 0, $set['planned_duration_min'] ?? null, $set['project_id'] ?? null, $set['is_manual'] ?? 0]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        return ['status' => 'applied'];
    }
    if ($set) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE pomodoro_sessions SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    return ['status' => 'applied'];
}

/* ---- durate salvate (preset): una riga per (tipo, minuti) ---- */

function sync_pomo_preset_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, type, minutes FROM pomodoro_custom_durations WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM pomodoro_custom_durations WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('pomodoro_custom_durations', $patch, $riga === null)) return $er;
    if (array_key_exists('updated_at', $patch)) return sync_rifiuto('validation', 'updated_at', 'updated_at is read-only');
    if ($riga) {
        foreach (['type', 'minutes'] as $k) if (isset($patch[$k]) && $patch[$k] !== ($k === 'minutes' ? (int)$riga[$k] : $riga[$k])) return sync_rifiuto('validation', $k, "$k cannot change");
        return ['status' => 'applied'];
    }
    $tipo = $patch['type'] ?? null; $min = $patch['minutes'] ?? null;
    if (!in_array($tipo, ['pomodoro', 'pausa'], true)) return sync_rifiuto('validation', 'type', 'type must be pomodoro or pausa');
    if (!is_int($min) || $min < 1 || $min > 600) return sync_rifiuto('validation', 'minutes', 'minutes must be 1 to 600');
    if ($uuid !== sync_uuid_preset($uid, $tipo, $min)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the user, type and minutes');
    // la durata puo' esserci gia' (salvata dal sito): e' LA STESSA riga
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'pomodoro_custom_durations', 'uuid' => $uuid];
    sec_q("INSERT IGNORE INTO pomodoro_custom_durations (user_id, type, minutes) VALUES (?,?,?)", [$uid, $tipo, $min], 'isi');
    unset($GLOBALS['SYNC_UUID_NUOVO']);
    return ['status' => 'applied'];
}

/* ---- impostazioni del Pomodoro + pausa in corso (non sono righe da sincronizzare: una per utente) ---- */

function pomo_api_impostazioni(int $uid): array {
    $i = sec_one("SELECT last_mode, last_pomodoro_duration_min, last_break_duration_min, last_display_style FROM pomodoro_settings WHERE user_id = ?", [$uid]);
    $b = sec_one("SELECT pc.start_time, pc.planned_duration_min, p.uuid AS project_uuid
                  FROM pomodoro_current_breaks pc LEFT JOIN projects p ON p.id = pc.project_id AND p.user_id = pc.user_id
                  WHERE pc.user_id = ?", [$uid]);
    return [
        'last_mode'                  => $i ? (int)$i['last_mode'] : 0,
        'last_pomodoro_duration_min' => $i ? (int)$i['last_pomodoro_duration_min'] : 25,
        'last_break_duration_min'    => $i ? (int)$i['last_break_duration_min'] : 5,
        'last_display_style'         => $i ? (string)$i['last_display_style'] : 'cerchio',
        'current_break'              => $b ? [
            'start_time'           => (string)$b['start_time'],
            'planned_duration_min' => (int)$b['planned_duration_min'],
            'project_uuid'         => $b['project_uuid'],
        ] : null,
    ];
}

/** PATCH: solo i campi inviati. Ritorna null se va bene, altrimenti [campo, testo]. */
function pomo_api_impostazioni_salva(int $uid, array $c): ?array {
    $ok = ['last_mode', 'last_pomodoro_duration_min', 'last_break_duration_min', 'last_display_style', 'current_break'];
    foreach ($c as $k => $v) if (!in_array($k, $ok, true)) return [(string)$k, 'Unknown field'];
    $set = [];
    if (array_key_exists('last_mode', $c)) {
        if ($c['last_mode'] !== 0 && $c['last_mode'] !== 1) return ['last_mode', 'last_mode must be 0 or 1'];
        $set['last_mode'] = $c['last_mode'];
    }
    foreach (['last_pomodoro_duration_min', 'last_break_duration_min'] as $k) {
        if (!array_key_exists($k, $c)) continue;
        if (!is_int($c[$k]) || $c[$k] < 1 || $c[$k] > 600) return [$k, "$k must be 1 to 600"];
        $set[$k] = $c[$k];
    }
    if (array_key_exists('last_display_style', $c)) {
        if (!in_array($c['last_display_style'], ['cerchio', 'clessidra'], true)) return ['last_display_style', 'last_display_style must be cerchio or clessidra'];
        $set['last_display_style'] = $c['last_display_style'];
    }
    $pausa = null; $togli = false;
    if (array_key_exists('current_break', $c)) {
        if ($c['current_break'] === null) $togli = true;
        else {
            $b = $c['current_break'];
            if (!is_array($b)) return ['current_break', 'current_break must be an object or null'];
            $ora = sync_pomo_ora($b['start_time'] ?? null);
            if ($ora === null) return ['current_break', 'start_time must be YYYY-MM-DD HH:MM:SS'];
            $min = $b['planned_duration_min'] ?? null;
            if (!is_int($min) || $min < 1 || $min > 600) return ['current_break', 'planned_duration_min must be 1 to 600'];
            $pid = null;
            if (($b['project_uuid'] ?? null) !== null) {
                $pid = sync_uuid_valido($b['project_uuid']) ? sync_id_da_uuid('projects', $uid, $b['project_uuid']) : null;
                if ($pid === null) return ['current_break', 'Project not found'];
            }
            $pausa = [$ora, $min, $pid];
        }
    }
    if ($set) {
        $cols = array_keys($set);
        sec_q("INSERT INTO pomodoro_settings (user_id, " . implode(', ', array_map(fn($x) => "`$x`", $cols)) . ") VALUES (?" . str_repeat(',?', count($cols)) . ")
               ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($x) => "`$x` = VALUES(`$x`)", $cols)),
              array_merge([$uid], array_values($set)));
    }
    if ($togli) sec_q("DELETE FROM pomodoro_current_breaks WHERE user_id = ?", [$uid], 'i');
    if ($pausa) {
        sec_q("INSERT INTO pomodoro_current_breaks (user_id, start_time, planned_duration_min, project_id) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), planned_duration_min = VALUES(planned_duration_min), project_id = VALUES(project_id)",
              [$uid, $pausa[0], $pausa[1], $pausa[2]]);
    }
    return null;
}

function api_h_pomodoro_impostazioni(array $ctx): void {
    api_ok(['settings' => pomo_api_impostazioni((int)$ctx['utente']['id'])]);
}
function api_h_pomodoro_impostazioni_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_pomodoro_write');
    $uid = (int)$ctx['utente']['id'];
    $e = pomo_api_impostazioni_salva($uid, api_corpo());
    if ($e) api_errore('validation', $e[1], 422, ['field' => $e[0]]);
    api_ok(['settings' => pomo_api_impostazioni($uid)]);
}
