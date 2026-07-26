<?php
/* =====================================================================
   NISAMA — PLANNER (vista giorno/settimana + vista mese)
   Struttura come habits.php / progetti.php: endpoint JSON sotto
   ?ajax=..., poi la pagina. Ogni query passa da security_core.php,
   quindi è sempre un prepared statement e ogni riga toccata viene
   prima verificata come appartenente all'utente.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();   // resa disponibile anche ai file inclusi (project_selector.php)
date_default_timezone_set('Europe/Rome');

define('PL_PHRASES_FILE', __DIR__ . '/planner_phrases.json');
define('PL_MAX_OCCURRENCES', 400);

/* =====================================================================
   UTILITY
   ===================================================================== */

function pl_monday(string $date): string {
    $ts = strtotime($date);
    $dow = (int)date('N', $ts);           // 1 = lunedì
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}
function pl_add_days(string $date, int $n): string {
    return date('Y-m-d', strtotime($date . ' ' . ($n >= 0 ? '+' : '-') . abs($n) . ' days'));
}
function pl_minutes(string $time): int {
    $p = explode(':', $time);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}
function pl_time(int $minutes): string {
    $minutes = max(0, min(1439, $minutes));
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

/* --- impostazioni: create al primo accesso ------------------------- */
function pl_settings(int $userId): array {
    $row = sec_one("SELECT * FROM planner_settings WHERE user_id = ?", [$userId], 'i');
    if (!$row) {
        sec_q("INSERT INTO planner_settings (user_id, first_week_start) VALUES (?, ?)",
              [$userId, pl_monday(date('Y-m-d'))], 'is');
        pl_seed_defaults($userId);
        $row = sec_one("SELECT * FROM planner_settings WHERE user_id = ?", [$userId], 'i');
    }
    if (!$row) return [];
    return [
        'palette'            => $row['palette_key'],
        'zoom'               => $row['default_zoom'],
        'day_start_hour'     => (int)$row['day_start_hour'],
        'show_motivational'  => (int)$row['show_motivational'] === 1,
        'show_affirmations'  => (int)$row['show_affirmations'] === 1,
        'default_view'       => $row['default_view'],
        'first_week_start'   => $row['first_week_start'] ?: pl_monday(date('Y-m-d')),
        'first_week_number'  => (int)$row['first_week_number'],
        'ambient_enabled'    => (int)$row['ambient_enabled'] === 1,
        'contrast_level'     => (int)($row['contrast_level'] ?? 3),
        'days_visible'       => (int)($row['days_visible'] ?? 3),
        'google_sync'        => (int)$row['google_sync_enabled'] === 1,
        'gc_dedicated'       => $row['gc_dedicated_mode'] ?? 'all',
        'gc_primary'         => $row['gc_primary_mode'] ?? 'none',
    ];
}

/** Categorie e routine di partenza per un utente nuovo. */
function pl_seed_defaults(int $userId): void {
    $exists = sec_one("SELECT 1 AS ok FROM planner_categories WHERE user_id = ? LIMIT 1", [$userId], 'i');
    if (!$exists) {
        /* Ogni categoria parte con qualche parola ovvia: scrivendo "treno" in
           un evento, la categoria Spostamenti si assegna da sé. */
        $defaults = [
            ['Spostamenti',  '#CFDDEA', ['treno', 'auto', 'macchina', 'bus', 'aereo', 'viaggio', 'metro', 'volo', 'partenza']],
            ['Svaghi',       '#F3E4C0', ['cinema', 'film', 'serie', 'gioco', 'videogioco', 'concerto', 'uscita', 'aperitivo']],
            ['Eventi',       '#F0D3D6', ['festa', 'compleanno', 'matrimonio', 'cena', 'evento', 'invito']],
            ['Lavoro',       '#D8D3E8', ['lavoro', 'riunione', 'call', 'ufficio', 'progetto', 'consegna', 'cliente']],
            ['Appuntamenti', '#E8D5C7', ['dottore', 'medico', 'dentista', 'visita', 'appuntamento', 'parrucchiere', 'banca']],
            ['Relax',        '#C7DED6', ['relax', 'riposo', 'pausa', 'yoga', 'meditazione', 'lettura', 'passeggiata']],
            ['Vacanze',      '#DCE7C8', ['vacanza', 'ferie', 'mare', 'montagna', 'hotel', 'weekend']],
        ];
        $i = 1;
        foreach ($defaults as $d) {
            sec_q("INSERT INTO planner_categories (user_id, name, color, sort_order) VALUES (?,?,?,?)",
                  [$userId, $d[0], $d[1], $i++], 'issi');
            $catId = sec_insert_id();
            foreach ($d[2] as $kw) {
                @sec_q("INSERT IGNORE INTO planner_category_keywords (user_id, category_id, keyword) VALUES (?,?,?)",
                       [$userId, $catId, $kw], 'iis');
            }
        }
    }
    $hasRoutine = sec_one("SELECT 1 AS ok FROM planner_routines WHERE user_id = ? LIMIT 1", [$userId], 'i');
    if (!$hasRoutine) {
        sec_q("INSERT INTO planner_routines (user_id, name, color, sort_order) VALUES (?,?,?,1)",
              [$userId, 'Morning routine', '#F6EBD2'], 'iss');
        $rid = sec_insert_id();
        $items = [['Alzarsi dal letto', 5], ['Skincare', 20], ['Vestirsi', 5], ['Colazione', 10], ['Prendere il sole', 5], ['Denti', 5]];
        $o = 1;
        foreach ($items as $it) {
            sec_q("INSERT INTO planner_routine_items (routine_id, user_id, name, duration_minutes, sort_order) VALUES (?,?,?,?,?)",
                  [$rid, $userId, $it[0], $it[1], $o++], 'iisii');
        }
    }
}

/* --- frasi dal json ------------------------------------------------- */
function pl_phrases(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['quotes' => [], 'affirmations' => []];
    if (is_readable(PL_PHRASES_FILE)) {
        $data = json_decode((string)file_get_contents(PL_PHRASES_FILE), true);
        if (is_array($data)) {
            $cache['quotes']       = is_array($data['quotes'] ?? null) ? $data['quotes'] : [];
            $cache['affirmations'] = is_array($data['affirmations'] ?? null) ? $data['affirmations'] : [];
        }
    }
    return $cache;
}

/** Sceglie in modo stabile (stessa settimana = stessa frase) senza ripetere subito. */
function pl_pick_phrase(string $kind, string $weekStart, int $userId): ?array {
    $list = pl_phrases()[$kind] ?? [];
    if (empty($list)) return null;
    $seed = crc32($kind . '|' . $weekStart . '|' . $userId);
    return $list[$seed % count($list)];
}

/* --- numero progressivo della settimana ----------------------------- */
function pl_week_number(int $userId, string $weekStart, array $settings): int {
    // l'ultima settimana con numero impostato a mano prima o uguale a questa
    $row = sec_one(
        "SELECT week_start, week_number FROM planner_weeks
         WHERE user_id = ? AND number_edited = 1 AND week_start <= ?
         ORDER BY week_start DESC LIMIT 1",
        [$userId, $weekStart], 'is'
    );
    if ($row) {
        $diff = (int)round((strtotime($weekStart) - strtotime($row['week_start'])) / 604800);
        return max(1, (int)$row['week_number'] + $diff);
    }
    $base = $settings['first_week_start'] ?: $weekStart;
    $diff = (int)round((strtotime($weekStart) - strtotime($base)) / 604800);
    return max(1, ($settings['first_week_number'] ?: 1) + $diff);
}

function pl_week_row(int $userId, string $weekStart, array $settings): array {
    $row = sec_one("SELECT * FROM planner_weeks WHERE user_id = ? AND week_start = ?", [$userId, $weekStart], 'is');
    $number = pl_week_number($userId, $weekStart, $settings);
    $quote   = pl_pick_phrase('quotes', $weekStart, $userId);
    $affirm  = pl_pick_phrase('affirmations', $weekStart, $userId);
    return [
        'week_start'  => $weekStart,
        'number'      => $row && (int)$row['number_edited'] === 1 ? (int)$row['week_number'] : $number,
        'completed'   => $row ? (int)$row['is_completed'] === 1 : false,
        'learned'     => $row['learned_note'] ?? '',
        'quote'       => $quote['text'] ?? '',
        'affirmation' => $affirm['text'] ?? '',
    ];
}

/* --- categorie / routine ------------------------------------------- */
function pl_categories(int $userId): array {
    $rows = sec_all("SELECT id, name, color, sort_order, is_hidden FROM planner_categories
                     WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $kw = sec_all("SELECT category_id, keyword FROM planner_category_keywords WHERE user_id = ? ORDER BY keyword", [$userId], 'i');
    $byCat = [];
    foreach ($kw as $k) { $byCat[(int)$k['category_id']][] = $k['keyword']; }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id' => $id, 'name' => $r['name'], 'color' => $r['color'],
            'hidden' => (int)$r['is_hidden'] === 1,
            'keywords' => $byCat[$id] ?? [],
        ];
    }
    return $out;
}

function pl_routines(int $userId): array {
    $rows = sec_all("SELECT id, name, color FROM planner_routines WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $items = sec_all("SELECT id, routine_id, name, duration_minutes FROM planner_routine_items
                      WHERE user_id = ? ORDER BY routine_id, sort_order, id", [$userId], 'i');
    $byR = [];
    foreach ($items as $it) {
        $byR[(int)$it['routine_id']][] = [
            'id' => (int)$it['id'], 'name' => $it['name'], 'minutes' => (int)$it['duration_minutes'],
        ];
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $list = $byR[$id] ?? [];
        $total = 0;
        foreach ($list as $x) $total += $x['minutes'];
        $out[] = ['id' => $id, 'name' => $r['name'], 'color' => $r['color'], 'items' => $list, 'total' => $total];
    }
    return $out;
}

/** I progetti segnati come preferiti, in ordine alfabetico. */
function pl_favorites(int $userId): array {
    $rows = sec_all("SELECT id, name, color FROM projects
                     WHERE user_id = ? AND is_favorite = 1
                     ORDER BY name", [$userId], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
    }
    return $out;
}

/** Categoria dedotta dal titolo: la prima parola chiave contenuta vince. */
function pl_auto_category(int $userId, string $title): ?int {
    $t = mb_strtolower($title, 'UTF-8');
    if ($t === '') return null;
    $rows = sec_all("SELECT category_id, keyword FROM planner_category_keywords
                     WHERE user_id = ? ORDER BY CHAR_LENGTH(keyword) DESC", [$userId], 'i');
    foreach ($rows as $r) {
        if ($r['keyword'] !== '' && mb_strpos($t, $r['keyword']) !== false) return (int)$r['category_id'];
    }
    return null;
}

/* --- eventi --------------------------------------------------------- */
function pl_event_out(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'notes'       => $r['notes'] ?? '',
        'date'        => $r['event_date'],
        'start'       => substr($r['start_time'], 0, 5),
        'end'         => substr($r['end_time'], 0, 5),
        'all_day'     => (int)$r['is_all_day'] === 1,
        'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
        'project_id'  => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name'=> $r['project_name'] ?? null,
        'project_color'=> $r['project_color'] ?? null,
        'habit_id'    => $r['habit_id'] !== null ? (int)$r['habit_id'] : null,
        'habit_icon'  => $r['habit_icon'] ?? null,
        'habit_color' => $r['habit_color'] ?? null,
        'routine_id'  => $r['routine_id'] !== null ? (int)$r['routine_id'] : null,
        'routine_name'=> $r['routine_name'] ?? null,
        'routine_group'     => $r['routine_group'],
        'routine_collapsed' => (int)$r['routine_collapsed'] === 1,
        'importance'  => $r['importance'],
        'color'       => $r['color'],
        'type'        => $r['item_type'],
        'done'        => (int)$r['is_completed'] === 1,
        'notify'      => $r['notify_type'],
        'notify_min'  => (int)$r['notify_minutes'],
        'repeat'      => $r['repeat_type'],
        'repeat_interval' => (int)$r['repeat_interval'],
        'repeat_unit' => $r['repeat_unit'],
        'repeat_until'=> $r['repeat_until'],
        'series_id'   => $r['series_id'] !== null ? (int)$r['series_id'] : null,
    ];
}

function pl_events_range(int $userId, string $from, string $to): array {
    $rows = sec_all(
        "SELECT e.*, p.name AS project_name, p.color AS project_color,
                h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
         FROM planner_events e
         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
         LEFT JOIN habits h   ON h.id = e.habit_id   AND h.user_id = e.user_id
         LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
         WHERE e.user_id = ? AND e.event_date BETWEEN ? AND ?
         ORDER BY e.event_date, e.is_all_day DESC, e.start_time, e.sort_order, e.id",
        [$userId, $from, $to], 'iss'
    );
    return array_map('pl_event_out', $rows);
}

/* --- priorità ------------------------------------------------------- */
function pl_priorities_range(int $userId, string $from, string $to): array {
    $rows = sec_all("SELECT priority_date, position, content, is_done FROM planner_priorities
                     WHERE user_id = ? AND priority_date BETWEEN ? AND ? ORDER BY priority_date, position",
                     [$userId, $from, $to], 'iss');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['priority_date']][] = [
            'position' => (int)$r['position'], 'content' => $r['content'], 'done' => (int)$r['is_done'] === 1,
        ];
    }
    // priorità automatiche dai progetti con obiettivo/scadenza nel periodo
    $proj = sec_all("SELECT name, target_date, due_date FROM projects
                     WHERE user_id = ? AND ((target_date BETWEEN ? AND ?) OR (due_date BETWEEN ? AND ?))",
                     [$userId, $from, $to, $from, $to], 'issss');
    foreach ($proj as $p) {
        if (!empty($p['target_date']) && $p['target_date'] >= $from && $p['target_date'] <= $to) {
            $out[$p['target_date']][] = ['position' => 0, 'content' => 'Obiettivo: ' . $p['name'], 'done' => false, 'auto' => true];
        }
        if (!empty($p['due_date']) && $p['due_date'] >= $from && $p['due_date'] <= $to) {
            $out[$p['due_date']][] = ['position' => 0, 'content' => 'SCADENZA: ' . $p['name'], 'done' => false, 'auto' => true];
        }
    }
    return $out;
}

/* --- liste della settimana ------------------------------------------ */
function pl_week_lists(int $userId, string $weekStart): array {
    $lists = sec_all("SELECT id, list_type, sort_order FROM planner_week_lists
                      WHERE user_id = ? AND week_start = ? ORDER BY sort_order, id", [$userId, $weekStart], 'is');
    $out = [];
    foreach ($lists as $l) {
        $items = sec_all("SELECT id, content, is_done FROM planner_week_list_items
                          WHERE list_id = ? AND user_id = ? ORDER BY sort_order, id", [(int)$l['id'], $userId], 'ii');
        $out[] = [
            'id' => (int)$l['id'], 'type' => $l['list_type'],
            'items' => array_map(function ($i) {
                return ['id' => (int)$i['id'], 'content' => $i['content'], 'done' => (int)$i['is_done'] === 1];
            }, $items),
        ];
    }
    return $out;
}

/* --- habit del giorno (letti dalle tabelle di habits) --------------- */
function pl_habit_due(array $h, string $date): bool {
    if ((int)$h['is_archived'] === 1) return false;
    if ($h['start_date'] > $date) return false;
    if (!empty($h['end_date']) && $h['end_date'] < $date) return false;
    if ($h['frequency_type'] === 'daily') {
        $dow = (int)date('N', strtotime($date)); // 1..7
        $mask = (string)$h['weekdays'];
        return isset($mask[$dow - 1]) ? $mask[$dow - 1] === '1' : true;
    }
    if ($h['frequency_type'] === 'interval') {
        $days = max(1, (int)$h['interval_days']);
        $delta = (int)round((strtotime($date) - strtotime($h['start_date'])) / 86400);
        return $delta >= 0 && $delta % $days === 0;
    }
    return true; // weekly/monthly: proponibile ogni giorno
}

function pl_habits_for_date(int $userId, string $date): array {
    $rows = sec_all("SELECT id, name, icon, color, habit_kind, target_amount, frequency_type,
                            weekdays, interval_days, start_date, end_date, is_archived, planner_minutes
                     FROM habits WHERE user_id = ? AND habit_kind = 'build' ORDER BY sort_order, id", [$userId], 'i');
    $logs = sec_all("SELECT habit_id, status, amount, target_amount FROM habit_logs
                     WHERE user_id = ? AND log_date = ?", [$userId, $date], 'is');
    $byId = [];
    foreach ($logs as $l) { $byId[(int)$l['habit_id']] = $l; }

    $out = [];
    foreach ($rows as $h) {
        if (!pl_habit_due($h, $date)) continue;
        $log = $byId[(int)$h['id']] ?? null;
        $done = $log && ($log['status'] === 'done' || (float)$log['amount'] >= (float)$log['target_amount']);
        $out[] = [
            'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'],
            'color' => $h['color'], 'done' => (bool)$done,
            // durata stimata scritta in habits: se manca, il planner usa lo scatto
            'minutes' => $h['planner_minutes'] !== null ? (int)$h['planner_minutes'] : null,
        ];
    }
    return $out;
}

/* --- diario condiviso ---------------------------------------------- */
function pl_diary_dates(int $userId, string $from, string $to): array {
    $rows = sec_all("SELECT entry_date, mood, energy, note FROM habit_daily_journal
                     WHERE user_id = ? AND entry_date BETWEEN ? AND ?", [$userId, $from, $to], 'iss');
    $out = [];
    foreach ($rows as $r) {
        $has = trim((string)$r['note']) !== '';
        $out[$r['entry_date']] = [
            'mood' => $r['mood'] !== null ? (int)$r['mood'] : null,
            'energy' => $r['energy'] !== null ? (int)$r['energy'] : null,
            'has_text' => $has,
        ];
    }
    $lists = sec_all("SELECT entry_date, COUNT(*) AS c FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> '' GROUP BY entry_date",
                      [$userId, $from, $to], 'iss');
    foreach ($lists as $l) {
        if (!isset($out[$l['entry_date']])) $out[$l['entry_date']] = ['mood' => null, 'energy' => null, 'has_text' => false];
        $out[$l['entry_date']]['has_lists'] = (int)$l['c'] > 0;
    }
    return $out;
}

/* =====================================================================
   API
   ===================================================================== */
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 40) : '';

if ($ajax !== '') {
    $isWrite = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isWrite) sec_guard_write($current_user_id, 'planner', 0, 400, 60);
    $settings = pl_settings($current_user_id);

    /* ---------------- lettura di un intervallo ---------------- */
    if ($ajax === 'range') {
        $from = sec_date(sec_in('from'), pl_monday(date('Y-m-d')));
        $to   = sec_date(sec_in('to'), pl_add_days($from, 6));
        if (strtotime($to) < strtotime($from)) $to = $from;
        if ((strtotime($to) - strtotime($from)) > 86400 * 70) $to = pl_add_days($from, 70);

        $weekStart = pl_monday($from);
        sec_json([
            'ok'         => true,
            'from'       => $from, 'to' => $to,
            'events'     => pl_events_range($current_user_id, $from, $to),
            'priorities' => pl_priorities_range($current_user_id, $from, $to),
            'week'       => pl_week_row($current_user_id, $weekStart, $settings),
            'lists'      => pl_week_lists($current_user_id, $weekStart),
            'diary'      => pl_diary_dates($current_user_id, $from, $to),
        ]);
    }

    /* ---------------- habit di un giorno ---------------- */
    if ($ajax === 'habits_day') {
        $date = sec_date(sec_in('date'), date('Y-m-d'));
        sec_json(['ok' => true, 'date' => $date, 'habits' => pl_habits_for_date($current_user_id, $date)]);
    }

    /* ---------------- salvataggio evento ---------------- */
    if ($ajax === 'event_save') {
        $id      = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $title   = sec_str(sec_in('title'), 180);
        $notes   = sec_text(sec_in('notes'), 4000);
        $date    = sec_date(sec_in('date'), date('Y-m-d'));
        $allDay  = sec_bool(sec_in('all_day'));
        $start   = sec_time(sec_in('start'), '09:00:00');
        $end     = sec_time(sec_in('end'), '10:00:00');
        if (pl_minutes($end) <= pl_minutes($start) && !$allDay) {
            $end = pl_time(min(1439, pl_minutes($start) + 30));
        }
        $catId   = sec_int(sec_in('category_id'), 0, PHP_INT_MAX, 0);
        $projId  = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        $habitId = sec_int(sec_in('habit_id'), 0, PHP_INT_MAX, 0);
        $imp     = sec_enum(sec_in('importance'), ['normal', 'high', 'highest'], 'normal');
        $color   = sec_in('color') ? sec_color(sec_in('color')) : null;
        $type    = sec_enum(sec_in('type'), ['event', 'task'], 'event');
        $notify  = sec_enum(sec_in('notify'), ['none', 'at_time', 'm30', 'h1', 'd1', 'custom'], 'h1');
        $notifyMin = ['none' => 0, 'at_time' => 0, 'm30' => 30, 'h1' => 60, 'd1' => 1440];
        $nMin    = $notify === 'custom' ? sec_int(sec_in('notify_min'), 0, 40320, 60) : ($notifyMin[$notify] ?? 60);
        $rep     = sec_enum(sec_in('repeat'), ['none', 'daily', 'weekly', 'monthly', 'yearly', 'custom'], 'none');
        $repInt  = sec_int(sec_in('repeat_interval'), 1, 365, 1);
        $repUnit = sec_enum(sec_in('repeat_unit'), ['day', 'week', 'month', 'year'], 'week');
        $repUntil= sec_date(sec_in('repeat_until'), null);

        // proprietà delle chiavi esterne
        if ($catId > 0 && !sec_owns('planner_categories', $catId, $current_user_id)) $catId = 0;
        if ($projId > 0 && !sec_owns('projects', $projId, $current_user_id)) $projId = 0;
        if ($habitId > 0 && !sec_owns('habits', $habitId, $current_user_id)) $habitId = 0;

        // categoria dedotta dal titolo, se l'utente non ne ha scelta una
        if ($catId === 0 && $projId === 0 && $title !== '') {
            $auto = pl_auto_category($current_user_id, $title);
            if ($auto !== null) $catId = $auto;
        }
        if ($title === '') $title = $habitId > 0 ? 'Abitudine' : 'Senza titolo';

        $params = [
            $title, $notes, $date, $start, $end, $allDay ? 1 : 0,
            $catId > 0 ? $catId : null, $projId > 0 ? $projId : null, $habitId > 0 ? $habitId : null,
            $imp, $color, $type, $notify, $nMin, $rep, $repInt, $repUnit, $repUntil,
        ];

        if ($id > 0) {
            if (!sec_owns('planner_events', $id, $current_user_id)) {
                sec_die_json('Evento non trovato.', 404, 'not_found');
            }
            $params[] = $id; $params[] = $current_user_id;
            sec_q("UPDATE planner_events SET title=?, notes=?, event_date=?, start_time=?, end_time=?, is_all_day=?,
                          category_id=?, project_id=?, habit_id=?, importance=?, color=?, item_type=?,
                          notify_type=?, notify_minutes=?, repeat_type=?, repeat_interval=?, repeat_unit=?, repeat_until=?
                   WHERE id=? AND user_id=?", $params);
            $newId = $id;
        } else {
            $params[] = $current_user_id;
            sec_q("INSERT INTO planner_events
                   (title, notes, event_date, start_time, end_time, is_all_day, category_id, project_id, habit_id,
                    importance, color, item_type, notify_type, notify_minutes, repeat_type, repeat_interval,
                    repeat_unit, repeat_until, user_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params);
            $newId = sec_insert_id();
            if ($rep !== 'none') {
                sec_q("UPDATE planner_events SET series_id = ? WHERE id = ? AND user_id = ?",
                      [$newId, $newId, $current_user_id], 'iii');
                pl_materialize_repeats($current_user_id, $newId);
            }
        }
        $row = sec_one("SELECT e.*, p.name AS project_name, p.color AS project_color,
                               h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
                        FROM planner_events e
                        LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
                        LEFT JOIN habits h ON h.id = e.habit_id AND h.user_id = e.user_id
                        LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
                        WHERE e.id = ? AND e.user_id = ?", [$newId, $current_user_id], 'ii');
        sec_json(['ok' => true, 'event' => $row ? pl_event_out($row) : null]);
    }

    /* ---------------- spostamento rapido (drag) ---------------- */
    if ($ajax === 'event_move') {
        $id    = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $date  = sec_date(sec_in('date'), null);
        $start = sec_time(sec_in('start'), null);
        if ($id === 0 || $start === null || !sec_owns('planner_events', $id, $current_user_id)) {
            sec_die_json('Evento non trovato.', 404, 'not_found');
        }
        $ev = sec_one("SELECT start_time, end_time, event_date FROM planner_events WHERE id = ? AND user_id = ?", [$id, $current_user_id], 'ii');
        $dur = pl_minutes($ev['end_time']) - pl_minutes($ev['start_time']);
        $newStart = pl_minutes($start);
        if ($newStart + $dur > 1439) $newStart = max(0, 1439 - $dur);
        sec_q("UPDATE planner_events SET start_time=?, end_time=?, event_date=? WHERE id=? AND user_id=?",
              [pl_time($newStart), pl_time($newStart + $dur), $date ?: $ev['event_date'], $id, $current_user_id]);
        sec_json(['ok' => true, 'start' => substr(pl_time($newStart), 0, 5), 'end' => substr(pl_time($newStart + $dur), 0, 5)]);
    }

    /* ---------------- spunta task / habit ---------------- */
    if ($ajax === 'event_toggle') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json('Evento non trovato.', 404, 'not_found');
        $ev = sec_one("SELECT habit_id, is_completed, event_date FROM planner_events WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        $newState = (int)$ev['is_completed'] === 1 ? 0 : 1;
        sec_q("UPDATE planner_events SET is_completed=?, completed_at=" . ($newState ? 'NOW()' : 'NULL') . " WHERE id=? AND user_id=?",
              [$newState, $id, $current_user_id]);
        // se l'evento viene da un habit, aggiorna anche il log dell'abitudine
        if (!empty($ev['habit_id'])) {
            pl_sync_habit_log($current_user_id, (int)$ev['habit_id'], $ev['event_date'], $newState === 1);
        }
        sec_json(['ok' => true, 'done' => $newState === 1]);
    }

    /* ---------------- cancellazione ---------------- */
    if ($ajax === 'event_delete') {
        $id    = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $scope = sec_enum(sec_in('scope'), ['one', 'series', 'routine'], 'one');
        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json('Evento non trovato.', 404, 'not_found');
        $ev = sec_one("SELECT series_id, routine_group FROM planner_events WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');

        if ($scope === 'series' && !empty($ev['series_id'])) {
            $where = 'user_id = ? AND series_id = ?';
            $params = [$current_user_id, (int)$ev['series_id']];
        } elseif ($scope === 'routine' && !empty($ev['routine_group'])) {
            $where = 'user_id = ? AND routine_group = ?';
            $params = [$current_user_id, $ev['routine_group']];
        } else {
            $where = 'id = ? AND user_id = ?';
            $params = [$id, $current_user_id];
        }
        // prima di cancellare, mettiamo in coda gli eventi che vivono anche
        // su Google: alla sincronizzazione successiva spariranno anche là
        pl_queue_google_delete($current_user_id, $where, $params);
        sec_q("DELETE FROM planner_events WHERE " . $where, $params);
        sec_json(['ok' => true]);
    }

    /* ---------------- anticipi / ritardi ---------------- */
    if ($ajax === 'events_shift') {
        $ids       = sec_in('ids');
        $minutes   = sec_int(sec_in('minutes'), 0, 1439, 0);
        $direction = sec_enum(sec_in('direction'), ['late', 'early'], 'late');
        if (!is_array($ids) || empty($ids) || $minutes === 0) sec_die_json('Niente da spostare.', 422, 'empty');
        $delta = $direction === 'late' ? $minutes : -$minutes;

        /* Prima leggiamo tutti gli eventi scelti, poi limitiamo lo spostamento
           UNA volta sola guardando il più estremo. Limitando ciascuno per conto
           suo, una routine a ridosso della mezzanotte si sarebbe schiacciata:
           alcuni pezzi si fermavano al limite e altri no, perdendo le distanze
           che avevano fra loro. */
        $chosen = [];
        foreach (array_slice($ids, 0, 200) as $raw) {
            $eid = sec_int($raw, 1, PHP_INT_MAX, 0);
            if ($eid === 0 || !sec_owns('planner_events', $eid, $current_user_id)) continue;
            $ev = sec_one("SELECT start_time, end_time FROM planner_events WHERE id=? AND user_id=?", [$eid, $current_user_id], 'ii');
            if (!$ev) continue;
            $chosen[] = ['id' => $eid, 's' => pl_minutes($ev['start_time']), 'e' => pl_minutes($ev['end_time'])];
        }
        if (empty($chosen)) sec_json(['ok' => true, 'moved' => 0]);

        $minStart = 1440; $maxEnd = 0;
        foreach ($chosen as $c) { $minStart = min($minStart, $c['s']); $maxEnd = max($maxEnd, $c['e']); }
        if ($delta < 0) $delta = max($delta, -$minStart);          // non si va prima di mezzanotte
        if ($delta > 0) $delta = min($delta, 1439 - $maxEnd);      // né oltre la fine del giorno

        $moved = 0;
        foreach ($chosen as $c) {
            sec_q("UPDATE planner_events SET start_time=?, end_time=? WHERE id=? AND user_id=?",
                  [pl_time($c['s'] + $delta), pl_time($c['e'] + $delta), $c['id'], $current_user_id]);
            $moved++;
        }
        sec_json(['ok' => true, 'moved' => $moved]);
    }

    /* ---------------- copia un altro giorno ---------------- */
    if ($ajax === 'copy_day') {
        $from = sec_date(sec_in('from'), null);
        $to   = sec_date(sec_in('to'), null);
        if (!$from || !$to || $from === $to) sec_die_json('Giorni non validi.', 422, 'invalid');
        $rows = sec_all("SELECT * FROM planner_events WHERE user_id=? AND event_date=?", [$current_user_id, $from], 'is');
        $n = 0;
        foreach ($rows as $r) {
            sec_q("INSERT INTO planner_events
                   (user_id, title, notes, event_date, start_time, end_time, is_all_day, category_id, project_id,
                    habit_id, routine_id, routine_group, routine_collapsed, importance, color, item_type,
                    notify_type, notify_minutes, sort_order)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   [$current_user_id, $r['title'], $r['notes'], $to, $r['start_time'], $r['end_time'],
                    (int)$r['is_all_day'], $r['category_id'], $r['project_id'], $r['habit_id'], $r['routine_id'],
                    $r['routine_group'], (int)$r['routine_collapsed'], $r['importance'], $r['color'],
                    $r['item_type'], $r['notify_type'], (int)$r['notify_minutes'], (int)$r['sort_order']]);
            $n++;
        }
        sec_json(['ok' => true, 'copied' => $n, 'events' => pl_events_range($current_user_id, $to, $to)]);
    }

    /* ---------------- inserimento di una routine ---------------- */
    if ($ajax === 'routine_insert') {
        $rid   = sec_int(sec_in('routine_id'), 1, PHP_INT_MAX, 0);
        $date  = sec_date(sec_in('date'), date('Y-m-d'));
        $start = sec_time(sec_in('start'), '07:00:00');
        if (!sec_owns('planner_routines', $rid, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
        $r = sec_one("SELECT name, color FROM planner_routines WHERE id=? AND user_id=?", [$rid, $current_user_id], 'ii');
        $items = sec_all("SELECT name, duration_minutes FROM planner_routine_items
                          WHERE routine_id=? AND user_id=? ORDER BY sort_order, id", [$rid, $current_user_id], 'ii');
        if (empty($items)) sec_die_json('Questa routine non ha ancora eventi.', 422, 'empty');
        $group = bin2hex(random_bytes(16));
        $cursor = pl_minutes($start);
        foreach ($items as $it) {
            $dur = max(1, (int)$it['duration_minutes']);
            $end = min(1439, $cursor + $dur);
            sec_q("INSERT INTO planner_events
                   (user_id, title, event_date, start_time, end_time, routine_id, routine_group, routine_collapsed, color, notify_type, notify_minutes)
                   VALUES (?,?,?,?,?,?,?,1,?,'none',0)",
                   [$current_user_id, $it['name'], $date, pl_time($cursor), pl_time($end), $rid, $group, $r['color']]);
            $cursor = $end;
            if ($cursor >= 1439) break;
        }
        sec_json(['ok' => true, 'events' => pl_events_range($current_user_id, $date, $date)]);
    }

    if ($ajax === 'routine_fold') {
        $group     = sec_str(sec_in('routine_group'), 32);
        $collapsed = sec_bool(sec_in('collapsed')) ? 1 : 0;
        sec_q("UPDATE planner_events SET routine_collapsed=? WHERE user_id=? AND routine_group=?",
              [$collapsed, $current_user_id, $group]);
        sec_json(['ok' => true]);
    }

    /* ---------------- priorità ---------------- */
    if ($ajax === 'priority_save') {
        $date = sec_date(sec_in('date'), date('Y-m-d'));
        $pos  = sec_int(sec_in('position'), 1, 6, 1);
        $text = sec_str(sec_in('content'), 200);
        $done = sec_bool(sec_in('done')) ? 1 : 0;
        if ($text === '' && $done === 0) {
            sec_q("DELETE FROM planner_priorities WHERE user_id=? AND priority_date=? AND position=?",
                  [$current_user_id, $date, $pos], 'isi');
        } else {
            sec_q("INSERT INTO planner_priorities (user_id, priority_date, position, content, is_done)
                   VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), is_done=VALUES(is_done)",
                   [$current_user_id, $date, $pos, $text, $done], 'isisi');
        }
        sec_json(['ok' => true]);
    }

    /* ---------------- settimana: numero, spunta, cosa ho imparato ---------------- */
    if ($ajax === 'week_save') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $row = sec_one("SELECT id, week_number, number_edited, is_completed, learned_note FROM planner_weeks
                        WHERE user_id=? AND week_start=?", [$current_user_id, $weekStart], 'is');
        $number   = sec_in('number') !== null ? sec_int(sec_in('number'), 1, 9999, 1) : null;
        $completed= sec_in('completed') !== null ? (sec_bool(sec_in('completed')) ? 1 : 0) : null;
        $learned  = sec_in('learned') !== null ? sec_text(sec_in('learned'), 4000) : null;

        $curNumber = $row ? (int)$row['week_number'] : pl_week_number($current_user_id, $weekStart, $settings);
        $curEdited = $row ? (int)$row['number_edited'] : 0;
        $curDone   = $row ? (int)$row['is_completed'] : 0;
        $curLearn  = $row ? (string)$row['learned_note'] : '';

        $newNumber = $number !== null ? $number : $curNumber;
        $newEdited = $number !== null ? 1 : $curEdited;
        $newDone   = $completed !== null ? $completed : $curDone;
        $newLearn  = $learned !== null ? $learned : $curLearn;

        sec_q("INSERT INTO planner_weeks (user_id, week_start, week_number, number_edited, is_completed, learned_note)
               VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE week_number=VALUES(week_number), number_edited=VALUES(number_edited),
                                       is_completed=VALUES(is_completed), learned_note=VALUES(learned_note)",
               [$current_user_id, $weekStart, $newNumber, $newEdited, $newDone, $newLearn], 'isiiis');
        sec_json(['ok' => true, 'week' => pl_week_row($current_user_id, $weekStart, $settings)]);
    }

    /* ---------------- liste della settimana ---------------- */
    if ($ajax === 'list_add') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $type = sec_enum(sec_in('type'), ['todo', 'buy', 'study'], 'todo');
        sec_q("INSERT IGNORE INTO planner_week_lists (user_id, week_start, list_type, sort_order)
               VALUES (?,?,?,(SELECT COALESCE(MAX(x.sort_order),0)+1 FROM (SELECT sort_order FROM planner_week_lists WHERE user_id=? AND week_start=?) x))",
               [$current_user_id, $weekStart, $type, $current_user_id, $weekStart], 'issis');
        sec_json(['ok' => true, 'lists' => pl_week_lists($current_user_id, $weekStart)]);
    }
    if ($ajax === 'list_remove') {
        $listId = sec_int(sec_in('list_id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json('Lista non trovata.', 404, 'not_found');
        sec_q("DELETE FROM planner_week_list_items WHERE list_id=? AND user_id=?", [$listId, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_week_lists WHERE id=? AND user_id=?", [$listId, $current_user_id], 'ii');
        sec_json(['ok' => true]);
    }
    if ($ajax === 'list_item_save') {
        $itemId = sec_int(sec_in('item_id'), 0, PHP_INT_MAX, 0);
        $listId = sec_int(sec_in('list_id'), 0, PHP_INT_MAX, 0);
        $text   = sec_str(sec_in('content'), 200);
        $done   = sec_bool(sec_in('done')) ? 1 : 0;
        if ($itemId > 0) {
            if (!sec_owns('planner_week_list_items', $itemId, $current_user_id)) sec_die_json('Voce non trovata.', 404, 'not_found');
            if ($text === '') {
                sec_q("DELETE FROM planner_week_list_items WHERE id=? AND user_id=?", [$itemId, $current_user_id], 'ii');
                sec_json(['ok' => true, 'deleted' => true]);
            }
            sec_q("UPDATE planner_week_list_items SET content=?, is_done=? WHERE id=? AND user_id=?",
                  [$text, $done, $itemId, $current_user_id], 'siii');
            sec_json(['ok' => true, 'id' => $itemId]);
        }
        if ($listId === 0 || !sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json('Lista non trovata.', 404, 'not_found');
        if ($text === '') sec_json(['ok' => true, 'id' => 0]);
        sec_q("INSERT INTO planner_week_list_items (list_id, user_id, content, is_done, sort_order)
               VALUES (?,?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_week_list_items WHERE list_id=?) x))",
               [$listId, $current_user_id, $text, $done, $listId], 'iisii');
        sec_json(['ok' => true, 'id' => sec_insert_id()]);
    }

    /* ---------------- categorie ---------------- */
    if ($ajax === 'category_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $name  = sec_str(sec_in('name'), 60);
        $color = sec_color(sec_in('color'));
        if ($name === '') sec_die_json('Serve un nome per la categoria.', 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
            sec_q("UPDATE planner_categories SET name=?, color=? WHERE id=? AND user_id=?", [$name, $color, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO planner_categories (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_categories WHERE user_id=?) x))",
                   [$current_user_id, $name, $color, $current_user_id], 'issi');
        }
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'category_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        sec_q("UPDATE planner_events SET category_id=NULL WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_category_keywords WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_categories WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'category_hide') {
        $id     = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $hidden = sec_bool(sec_in('hidden')) ? 1 : 0;
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        sec_q("UPDATE planner_categories SET is_hidden=? WHERE id=? AND user_id=?", [$hidden, $id, $current_user_id], 'iii');
        sec_json(['ok' => true]);
    }
    /* parola chiave: rifiutata se già presente in un'altra categoria */
    if ($ajax === 'keyword_add') {
        $catId   = sec_int(sec_in('category_id'), 1, PHP_INT_MAX, 0);
        $keyword = sec_key(sec_in('keyword'), 60);
        if (!sec_owns('planner_categories', $catId, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        if ($keyword === '') sec_die_json('Scrivi una parola.', 422, 'invalid');
        $clash = sec_one("SELECT k.category_id, c.name FROM planner_category_keywords k
                          JOIN planner_categories c ON c.id = k.category_id
                          WHERE k.user_id = ? AND k.keyword = ? LIMIT 1", [$current_user_id, $keyword], 'is');
        if ($clash) {
            sec_json([
                'ok' => false, 'error' => 'duplicate',
                'category_id' => (int)$clash['category_id'], 'category_name' => $clash['name'],
                'message' => '"' . $keyword . '" è già in ' . $clash['name'] . '.',
            ], 409);
        }
        sec_q("INSERT INTO planner_category_keywords (user_id, category_id, keyword) VALUES (?,?,?)",
              [$current_user_id, $catId, $keyword], 'iis');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'keyword_delete') {
        $catId   = sec_int(sec_in('category_id'), 1, PHP_INT_MAX, 0);
        $keyword = sec_key(sec_in('keyword'), 60);
        sec_q("DELETE FROM planner_category_keywords WHERE user_id=? AND category_id=? AND keyword=?",
              [$current_user_id, $catId, $keyword], 'iis');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }

    /* ---------------- routine: creazione e modifica ---------------- */
    if ($ajax === 'routine_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $name  = sec_str(sec_in('name'), 80);
        $color = sec_color(sec_in('color'), '#E3D9EE');
        $items = sec_in('items');
        if ($name === '') sec_die_json('Serve un nome per la routine.', 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
            sec_q("UPDATE planner_routines SET name=?, color=? WHERE id=? AND user_id=?", [$name, $color, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO planner_routines (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_routines WHERE user_id=?) x))",
                   [$current_user_id, $name, $color, $current_user_id], 'issi');
            $id = sec_insert_id();
        }
        if (is_array($items)) {
            sec_q("DELETE FROM planner_routine_items WHERE routine_id=? AND user_id=?", [$id, $current_user_id], 'ii');
            $o = 1;
            foreach (array_slice($items, 0, 40) as $it) {
                $iname = sec_str($it['name'] ?? '', 120);
                $imin  = sec_int($it['minutes'] ?? 5, 1, 1440, 5);
                if ($iname === '') continue;
                sec_q("INSERT INTO planner_routine_items (routine_id, user_id, name, duration_minutes, sort_order)
                       VALUES (?,?,?,?,?)", [$id, $current_user_id, $iname, $imin, $o++], 'iisii');
            }
        }
        sec_json(['ok' => true, 'routines' => pl_routines($current_user_id)]);
    }
    if ($ajax === 'routine_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
        sec_q("DELETE FROM planner_routine_items WHERE routine_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_routines WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'routines' => pl_routines($current_user_id)]);
    }

    /* ---------------- impostazioni ---------------- */
    if ($ajax === 'settings_save') {
        $fields = [];
        $params = [];
        $map = [
            'palette'           => ['palette_key', 'enum', ['sage','mint','blush','lavender','butter','sky','clay','rose','sea','olive','plum','sand'], 'sage'],
            'zoom'              => ['default_zoom', 'enum', ['agenda','h3','h1','m30','m15','m5'], 'm30'],
            'day_start_hour'    => ['day_start_hour', 'int', 0, 23],
            'show_motivational' => ['show_motivational', 'bool'],
            'show_affirmations' => ['show_affirmations', 'bool'],
            'default_view'      => ['default_view', 'enum', ['day','month'], 'day'],
            'ambient_enabled'   => ['ambient_enabled', 'bool'],
            'contrast_level'    => ['contrast_level', 'int', 1, 5],
            'days_visible'      => ['days_visible', 'int', 1, 7],
            'gc_dedicated'      => ['gc_dedicated_mode', 'enum', ['none','all','high','highest'], 'all'],
            'gc_primary'        => ['gc_primary_mode', 'enum', ['none','high','highest'], 'none'],
            'first_week_number' => ['first_week_number', 'int', 1, 9999],
        ];
        foreach ($map as $key => $cfg) {
            $raw = sec_in($key);
            if ($raw === null) continue;
            $fields[] = '`' . $cfg[0] . '` = ?';
            if ($cfg[1] === 'enum')      $params[] = sec_enum($raw, $cfg[2], $cfg[3]);
            elseif ($cfg[1] === 'int')   $params[] = sec_int($raw, $cfg[2], $cfg[3], $cfg[2]);
            else                         $params[] = sec_bool($raw) ? 1 : 0;
        }
        if (!empty($fields)) {
            $params[] = $current_user_id;
            sec_q("UPDATE planner_settings SET " . implode(', ', $fields) . " WHERE user_id = ?", $params);
        }
        /* Cambiando le scelte su Google, gli eventi già inviati vanno
           rivalutati: senza questo, uno che non è più da sincronizzare
           resterebbe su Google per sempre, perché l'invio guarda solo quelli
           modificati di recente. Azzerando la marca, al prossimo giro
           vengono riesaminati tutti. */
        if (sec_in('gc_dedicated') !== null || sec_in('gc_primary') !== null) {
            sec_q("UPDATE planner_events SET google_synced_at = NULL
                   WHERE user_id = ? AND event_date BETWEEN (CURDATE() - INTERVAL 30 DAY) AND (CURDATE() + INTERVAL 180 DAY)",
                  [$current_user_id], 'i');
        }
        sec_json(['ok' => true, 'settings' => pl_settings($current_user_id)]);
    }

    /* ---------------- riepilogo della settimana ---------------- */
    if ($ajax === 'week_summary') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $weekEnd   = pl_add_days($weekStart, 6);

        $events = sec_all(
            "SELECT e.habit_id, e.category_id, e.routine_id, e.routine_group,
                    e.start_time, e.end_time, e.is_all_day, e.is_completed,
                    TIMESTAMPDIFF(MINUTE, e.start_time, e.end_time) AS minutes
             FROM planner_events e
             WHERE e.user_id = ? AND e.event_date BETWEEN ? AND ?",
            [$current_user_id, $weekStart, $weekEnd], 'iss'
        );

        /* --- abitudini: quante volte le hai messe in agenda, quale obiettivo
               ti eri dato in habits, e quanto tempo ci stai dedicando --- */
        $habitRows = sec_all(
            "SELECT id, name, icon, color, frequency_type, weekdays, times_per_week,
                    times_per_month, interval_days, planner_minutes
             FROM habits WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
             ORDER BY sort_order, id", [$current_user_id], 'i'
        );
        $habitStats = [];
        foreach ($habitRows as $h) {
            $goal = null;
            if ($h['frequency_type'] === 'daily')       $goal = substr_count((string)$h['weekdays'], '1');
            elseif ($h['frequency_type'] === 'weekly')  $goal = (int)$h['times_per_week'];
            elseif ($h['frequency_type'] === 'interval') $goal = (int)floor(7 / max(1, (int)$h['interval_days']));
            $habitStats[(int)$h['id']] = [
                'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'], 'color' => $h['color'],
                'planned' => 0, 'done' => 0, 'goal' => $goal, 'minutes' => 0,
            ];
        }
        $habitMinutes = 0;

        /* --- categorie e routine --- */
        $cats = [];
        foreach (pl_categories($current_user_id) as $c) {
            $cats[$c['id']] = ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'minutes' => 0, 'count' => 0];
        }
        $noCat = ['id' => 0, 'name' => 'Senza categoria', 'color' => '#E4DDD3', 'minutes' => 0, 'count' => 0];

        $routines = [];
        foreach (pl_routines($current_user_id) as $r) {
            $routines[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'],
                                   'times' => 0, 'minutes' => 0, 'groups' => []];
        }

        foreach ($events as $e) {
            $min = (int)$e['is_all_day'] === 1 ? 0 : max(0, (int)$e['minutes']);
            if (!empty($e['habit_id']) && isset($habitStats[(int)$e['habit_id']])) {
                $habitStats[(int)$e['habit_id']]['planned']++;
                if ((int)$e['is_completed'] === 1) $habitStats[(int)$e['habit_id']]['done']++;
                $habitStats[(int)$e['habit_id']]['minutes'] += $min;
                $habitMinutes += $min;
            }
            if (!empty($e['category_id']) && isset($cats[(int)$e['category_id']])) {
                $cats[(int)$e['category_id']]['minutes'] += $min;
                $cats[(int)$e['category_id']]['count']++;
            } elseif (empty($e['category_id'])) {
                $noCat['minutes'] += $min;
                $noCat['count']++;
            }
            if (!empty($e['routine_id']) && isset($routines[(int)$e['routine_id']])) {
                $routines[(int)$e['routine_id']]['minutes'] += $min;
                // una routine "fatta" = un blocco inserito, non i suoi singoli passaggi
                if (!empty($e['routine_group'])) {
                    $routines[(int)$e['routine_id']]['groups'][$e['routine_group']] = true;
                }
            }
        }
        foreach ($routines as $rid => $r) {
            $routines[$rid]['times'] = count($r['groups']);
            unset($routines[$rid]['groups']);
        }

        /* --- diario: solo da martedì in avanti, o a settimana finita --- */
        $today = date('Y-m-d');
        $showDiary = $today > $weekEnd || $today >= pl_add_days($weekStart, 1);
        $diary = null;
        if ($showDiary) {
            $rows = sec_all("SELECT mood, energy, note FROM habit_daily_journal
                             WHERE user_id = ? AND entry_date BETWEEN ? AND ?",
                             [$current_user_id, $weekStart, $weekEnd], 'iss');
            $lists = sec_one("SELECT COUNT(DISTINCT entry_date) AS c FROM diary_list_items
                              WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> ''",
                              [$current_user_id, $weekStart, $weekEnd], 'iss');
            $moods = []; $energies = []; $filled = 0;
            foreach ($rows as $r) {
                if (trim((string)$r['note']) !== '') $filled++;
                if ($r['mood'] !== null)   $moods[] = (int)$r['mood'];
                if ($r['energy'] !== null) $energies[] = (int)$r['energy'];
            }
            $filled = max($filled, (int)($lists['c'] ?? 0));
            $diary = [
                'days'       => $filled,
                'mood_avg'   => empty($moods) ? null : round(array_sum($moods) / count($moods), 1),
                'energy_avg' => empty($energies) ? null : round(array_sum($energies) / count($energies), 1),
            ];
        }

        $catList = array_values($cats);
        if ($noCat['count'] > 0) $catList[] = $noCat;
        usort($catList, function ($a, $b) { return $b['minutes'] <=> $a['minutes']; });

        sec_json([
            'ok'         => true,
            'week'       => pl_week_row($current_user_id, $weekStart, $settings),
            'habits'     => array_values($habitStats),
            'habit_minutes' => $habitMinutes,
            'categories' => $catList,
            'routines'   => array_values($routines),
            'diary'      => $diary,
            'show_diary' => $showDiary,
        ]);
    }

    /* ---------------- ricerca per titolo ---------------- */
    if ($ajax === 'search') {
        $q = sec_str(sec_in('q'), 60);
        if (mb_strlen($q) < 2) sec_json(['ok' => true, 'results' => []]);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = sec_all("SELECT id, title, event_date, start_time, end_time, is_all_day, color, category_id
                         FROM planner_events WHERE user_id = ? AND title LIKE ?
                         ORDER BY ABS(DATEDIFF(event_date, CURDATE())) LIMIT 60", [$current_user_id, $like], 'is');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'], 'title' => $r['title'], 'date' => $r['event_date'],
                'start' => substr($r['start_time'], 0, 5), 'end' => substr($r['end_time'], 0, 5),
                'all_day' => (int)$r['is_all_day'] === 1, 'color' => $r['color'],
                'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
            ];
        }
        sec_json(['ok' => true, 'results' => $out]);
    }

    sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');
}

/* =====================================================================
   FUNZIONI DI SUPPORTO USATE DAGLI ENDPOINT
   ===================================================================== */

/** Crea le occorrenze future di un evento ripetuto (max PL_MAX_OCCURRENCES). */
function pl_materialize_repeats(int $userId, int $masterId): void {
    $m = sec_one("SELECT * FROM planner_events WHERE id=? AND user_id=?", [$masterId, $userId], 'ii');
    if (!$m || $m['repeat_type'] === 'none') return;

    $unitMap = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
    $unit = $m['repeat_type'] === 'custom' ? $m['repeat_unit'] : ($unitMap[$m['repeat_type']] ?? 'week');
    $step = $m['repeat_type'] === 'custom' ? max(1, (int)$m['repeat_interval']) : 1;
    $until = $m['repeat_until'] ?: date('Y-m-d', strtotime('+1 year'));

    $date = $m['event_date'];
    $count = 0;
    while ($count < PL_MAX_OCCURRENCES) {
        $date = date('Y-m-d', strtotime($date . ' +' . $step . ' ' . $unit));
        if ($date > $until) break;
        sec_q("INSERT INTO planner_events
               (user_id, title, notes, event_date, start_time, end_time, is_all_day, category_id, project_id,
                habit_id, importance, color, item_type, notify_type, notify_minutes,
                repeat_type, repeat_interval, repeat_unit, repeat_until, series_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
               [$userId, $m['title'], $m['notes'], $date, $m['start_time'], $m['end_time'], (int)$m['is_all_day'],
                $m['category_id'], $m['project_id'], $m['habit_id'], $m['importance'], $m['color'], $m['item_type'],
                $m['notify_type'], (int)$m['notify_minutes'], $m['repeat_type'], (int)$m['repeat_interval'],
                $m['repeat_unit'], $m['repeat_until'], $masterId]);
        $count++;
    }
}

/**
 * Mette in coda le cancellazioni da riportare su Google Calendar.
 * Se la tabella non esiste (migrazione non eseguita) non fa nulla:
 * il planner continua a funzionare comunque.
 */
function pl_queue_google_delete(int $userId, string $where, array $params): void {
    $rows = sec_all("SELECT google_event_id FROM planner_events WHERE " . $where . " AND google_event_id IS NOT NULL", $params);
    if (empty($rows)) return;
    foreach ($rows as $r) {
        @sec_q("INSERT INTO google_sync_queue (user_id, google_event_id, action) VALUES (?,?,'delete')",
               [$userId, $r['google_event_id']], 'is');
    }
}

/** Spunta/despunta un'abitudine nelle tabelle di habits (fonte unica). */
function pl_sync_habit_log(int $userId, int $habitId, string $date, bool $done): void {
    $h = sec_one("SELECT target_amount, step_amount FROM habits WHERE id=? AND user_id=?", [$habitId, $userId], 'ii');
    if (!$h) return;
    $target = (float)$h['target_amount'];
    if ($done) {
        sec_q("INSERT INTO habit_logs (habit_id, user_id, log_date, status, amount, target_amount, completions)
               VALUES (?,?,?,'done',?,?,1)
               ON DUPLICATE KEY UPDATE status='done', amount=GREATEST(amount, VALUES(amount)),
                                       target_amount=VALUES(target_amount), completions=completions+1",
               [$habitId, $userId, $date, $target, $target], 'iisdd');
        sec_q("INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source) VALUES (?,?,?,?, 'quick_add')",
              [$habitId, $userId, $date, $target], 'iisd');
    } else {
        sec_q("UPDATE habit_logs SET status='partial', amount=0, completions=0
               WHERE habit_id=? AND user_id=? AND log_date=?", [$habitId, $userId, $date], 'iis');
        sec_q("INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source) VALUES (?,?,?,?, 'undo')",
              [$habitId, $userId, $date, -$target], 'iisd');
    }
    // riflette la spunta anche sugli eventi collegati dello stesso giorno
    sec_q("UPDATE planner_events SET is_completed=?, completed_at=" . ($done ? 'NOW()' : 'NULL') . "
           WHERE user_id=? AND habit_id=? AND event_date=?",
          [$done ? 1 : 0, $userId, $habitId, $date], 'iiis');
}

/* =====================================================================
   DATI INIZIALI DELLA PAGINA
   ===================================================================== */
$settings  = pl_settings($current_user_id);
$today     = date('Y-m-d');
$weekStart = pl_monday($today);
$rangeFrom = pl_add_days($weekStart, -7);
$rangeTo   = pl_add_days($weekStart, 20);

$boot = [
    'csrf'       => sec_csrf_token(),
    'today'      => $today,
    'user'       => ['id' => $current_user_id, 'name' => $utente_corrente['username'] ?? ''],
    'settings'   => $settings,
    'categories' => pl_categories($current_user_id),
    'routines'   => pl_routines($current_user_id),
    'favorites'  => pl_favorites($current_user_id),
    'week'       => pl_week_row($current_user_id, $weekStart, $settings),
    'events'     => pl_events_range($current_user_id, $rangeFrom, $rangeTo),
    'priorities' => pl_priorities_range($current_user_id, $rangeFrom, $rangeTo),
    'lists'      => pl_week_lists($current_user_id, $weekStart),
    'diary'      => pl_diary_dates($current_user_id, $rangeFrom, $rangeTo),
    'habits'     => pl_habits_for_date($current_user_id, $today),
    'range'      => ['from' => $rangeFrom, 'to' => $rangeTo],
];
?>
<!DOCTYPE html>
<html lang="it" class="pl-palette-<?php echo sec_h($settings['palette']); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FBF9F6">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>Planner</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="planner.css?v=<?php echo @filemtime(__DIR__ . '/planner.css') ?: time(); ?>">
</head>
<body class="pl-body">

<!-- ============ BARRA IN ALTO ============
     riga 1: settimana + tutti i pulsanti
     riga 2: giorno a sinistra, mese al centro, calendarietto a destra
     riga 3: affermazione positiva (se attiva) -->
<header class="pl-topbar" id="plTopbar">
    <div class="pl-ambient" id="plAmbient" aria-hidden="true"></div>

    <div class="pl-bar-tools">
        <button type="button" class="pl-week" id="plWeekBtn">
            <span class="pl-week-n pl-serif" id="plWeekN">1</span>
            <span class="pl-week-k">Setti&shy;mana</span>
            <span class="pl-check" id="plWeekDone" role="checkbox" aria-checked="false" aria-label="Settimana completata">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>
            </span>
        </button>
        <span class="pl-tools-sp"></span>
        <button type="button" class="pl-tool" id="plViewBtn" aria-label="Passa alla vista mese">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>
        </button>
        <button type="button" class="pl-tool pl-tool-today" id="plTodayBtn" aria-label="Vai a oggi">1</button>
        <button type="button" class="pl-tool" id="plSearchBtn" aria-label="Cerca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
        </button>
        <button type="button" class="pl-tool" id="plFilterBtn" aria-label="Filtra per categoria">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M7 12h10M10 17h4"/></svg>
        </button>
        <button type="button" class="pl-tool" id="plSettingsBtn" aria-label="Impostazioni">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M4.8 7.9l1.9 1.1M17.3 15l1.9 1.1M4.8 16.1l1.9-1.1M17.3 9l1.9-1.1"/></svg>
        </button>
    </div>

    <div class="pl-affirm pl-serif" id="plAffirm"></div>

    <div class="pl-bar-2">
        <button type="button" class="pl-day-btn" id="plDayBtn">
            <span class="pl-day-num pl-serif" id="plDayNum">1</span>
            <span class="pl-day-wd" id="plDayWd">Lunedì</span>
        </button>
        <button type="button" class="pl-month-btn" id="plMonthBtn">
            <span class="pl-month-name pl-serif" id="plMonthName">Luglio</span>
            <span class="pl-month-year" id="plMonthYear">2026</span>
        </button>
        <button type="button" class="pl-mini-static" id="plMiniStatic" aria-label="Calendario del mese"></button>
    </div>

    <div class="pl-months-bar" id="plMonthsBar"></div>
</header>

<!-- Il calendario a comparsa vive FUORI dall'header, apposta: così qualunque
     cosa succeda al suo contenuto non può più far crescere la barra in alto
     e spingere via orari e priorità (il bug che è successo due volte). È
     un pannello a posizione fissa, agganciato sotto la barra, che si apre
     sopra il resto della pagina senza spostare nient'altro. -->
<div class="pl-mini" id="plMini"></div>

<!-- ============ GRIGLIA GIORNI + ORARI ============ -->
<main class="pl-body-scroll" id="plScroller">
    <div class="pl-row">
        <div class="pl-gutter"><div class="pl-gutter-canvas" id="plGutter"></div></div>
        <div class="pl-hclip">
            <!-- la striscia dei 21 giorni: si sposta con una trasformazione,
                 mai con lo scroll nativo, cosi' l'orizzontale non tocca mai
                 il verticale (e viceversa). La costruisce planner.js. -->
            <div class="pl-track" id="plTrack"></div>
        </div>
    </div>
</main>

<!-- ============ VISTA MESE ============ -->
<section class="pl-month" id="plMonthView">
    <div class="pl-mgrid" id="plMonthGrid"></div>
</section>

<!-- ============ PULSANTI FLOTTANTI ============ -->
<div class="pl-fabs" id="plFabs">
    <button type="button" class="pl-ring" id="plHabitRing" aria-label="Abitudini del giorno">
        <svg viewBox="0 0 46 46">
            <circle class="pl-ring-bg"   cx="23" cy="23" r="20"/>
            <circle class="pl-ring-plan" cx="23" cy="23" r="20" stroke-dasharray="125.6" stroke-dashoffset="125.6"/>
            <circle class="pl-ring-done" cx="23" cy="23" r="20" stroke-dasharray="125.6" stroke-dashoffset="125.6"/>
        </svg>
        <span class="pl-ring-face">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 12.5l4 4L19 7"/></svg>
        </span>
    </button>
    <div class="pl-fab-menu" id="plMenuHabit"></div>

    <button type="button" class="pl-fab" id="plFabRoutine" aria-label="Routine">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h10M4 12h13M4 18h8"/><circle cx="19" cy="6" r="1.6"/><circle cx="21" cy="12" r="1.6"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuRoutine"></div>

    <button type="button" class="pl-fab pl-fab-cat" id="plFabCat" aria-label="Categoria predefinita">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 4h7.7L20 10.3 13.3 17 6 9.7z"/><circle cx="10" cy="8.5" r="1.2"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuCat"></div>

    <button type="button" class="pl-fab pl-hidden" id="plFabFav" aria-label="Progetti preferiti">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuFav"></div>

    <button type="button" class="pl-fab" id="plFabShift" aria-label="Anticipa o ritarda">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>
    </button>

    <button type="button" class="pl-fab" id="plFabDiary" aria-label="Diario del giorno">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 4h10a2 2 0 012 2v14H8a2 2 0 01-2-2z"/><path d="M6 4v16"/><path d="M10 9h5M10 13h5"/></svg>
    </button>
</div>

<!-- rotellina degli scatti: la costruisce planner.js -->
<div class="pl-zoom" id="plZoom" aria-label="Scatti degli orari"></div>

<button type="button" class="pl-plus" id="plPlus" aria-label="Nuovo evento">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- frase motivazionale della settimana: barra in fondo -->
<div class="pl-quotebar pl-hidden" id="plQuoteBar">
    <span class="pl-serif" id="plQuoteText"></span>
</div>

<!-- pannello della settimana (to do / to buy / to study + cosa ho imparato):
     entra da sinistra, sopra la pagina -->
<div class="pl-wp-ov" id="plWeekPanelOv">
    <aside class="pl-wp" id="plWeekPanel">
        <div class="pl-wp-head">
            <span class="pl-sheet-title pl-serif">La settimana</span>
            <button type="button" class="pl-txt-btn" id="plWeekPanelClose">Chiudi</button>
        </div>
        <div class="pl-wp-body" id="plWeekPanelBody"></div>
    </aside>
</div>

<!-- striscia invisibile sul bordo sinistro: dal lunedì apre il pannello -->
<div class="pl-edge" id="plEdge" aria-hidden="true"></div>

<!-- barra della modalità anticipi/ritardi -->
<div class="pl-shift-bar" id="plShiftBar">
    <button type="button" class="pl-txt-btn" id="plShiftCancel">Annulla</button>
    <div class="pl-switch" id="plShiftSwitch">
        <button type="button" data-dir="early">Anticipo</button>
        <button type="button" data-dir="late" class="pl-on">Ritardo</button>
    </div>
    <input type="text" class="pl-time-in" id="plShiftTime" value="00:10" inputmode="numeric" maxlength="5" aria-label="Ore e minuti">
    <button type="button" class="pl-txt-btn pl-strong" id="plShiftSave">Salva</button>
</div>

<!-- ============ POPUP: EVENTO ============ -->
<div class="pl-ov" id="plEventOv">
    <div class="pl-sheet pl-sheet-tinted" id="plEventSheet">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-head-left">
                <button type="button" class="pl-txt-btn" id="plEvCancel">Annulla</button>
                <button type="button" class="pl-trash pl-hidden" id="plEvTrash" aria-label="Elimina evento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/><path d="M10.5 11v6M13.5 11v6"/></svg>
                </button>
            </span>
            <span class="pl-label" id="plEvKicker">Nuovo evento</span>
            <button type="button" class="pl-txt-btn pl-strong" id="plEvSave">Salva</button>
        </div>
        <div class="pl-sheet-body" id="plEvBody"></div>
    </div>
</div>

<!-- ============ POPUP: IMPOSTAZIONI / RICERCA / FILTRO / COPIA ============ -->
<div class="pl-ov" id="plSheetOv">
    <div class="pl-sheet pl-sheet-tall">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-sheet-title pl-serif" id="plSheetTitle">Impostazioni</span>
            <button type="button" class="pl-txt-btn" data-pl-close="plSheetOv">Chiudi</button>
        </div>
        <div class="pl-sheet-body" id="plSheetBody"></div>
    </div>
</div>

<!-- ============ AVVISO ============ -->
<div class="pl-alert-ov" id="plAlertOv">
    <div class="pl-alert">
        <div class="pl-alert-t pl-serif" id="plAlertTitle"></div>
        <div class="pl-alert-m" id="plAlertMsg"></div>
        <div class="pl-alert-acts" id="plAlertActs"></div>
    </div>
</div>

<div class="pl-toast" id="plToast"></div>

<?php
/* Selettore progetti riusato dal popup evento (stesso componente di
   pomodoro.php e progetti.php). Il planner ascolta l'evento
   "projectselected" per collegare il progetto all'evento. */
$ps_id = 'plev'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_placeholder = 'Nessun progetto'; $ps_title = 'Collega a un progetto';
$ps_show_all_option = true; $ps_all_option_label = 'Nessun progetto'; $ps_allow_create = true;
$ps_hidden_name = 'plev_project_id';
include 'project_selector.php';
/* nel planner il progetto si sceglie dal popup dell'evento: il pulsante
   inline del selettore non deve comparire in fondo alla pagina */
echo '<style>#ps-widget-plev{ display:none; }</style>';
?>

<?php
/* Diario del giorno + foglio scritto a mano: componente condiviso con
   habits.php e con la pagina diario.php (stessi dati, stesse tabelle). */
include 'diario_giorno.php';
?>

<script>window.PL_BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="planner.js?v=<?php echo @filemtime(__DIR__ . '/planner.js') ?: time(); ?>"></script>
</body>
</html>