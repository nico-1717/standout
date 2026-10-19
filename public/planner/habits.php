<?php
/* =====================================================================
   HABIT TRACKER — pagina principale + API AJAX
   Stessa impostazione di progetti.php: config -> connessione -> auth_check,
   endpoint JSON sotto ?ajax=..., HTML/CSS/JS mobile first.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
/* sec_db() riusa la connessione gia' aperta e la configura (utf8mb4, errori
   come eccezioni) invece di aprirne una seconda. */
$conn = sec_db();
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];

if (function_exists('date_default_timezone_set')) { date_default_timezone_set('Europe/Rome'); }

/* ---------------------------------------------------------------------
   Costanti e piccole utility
   --------------------------------------------------------------------- */
define('HB_HISTORY_DAYS', 420);   // quanti giorni di log mandiamo al client (heatmap annuale + margine)
define('HB_PRESETS_FILE', __DIR__ . '/../../lib/data/habits_presets.json');

function hb_json($payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}
function hb_fail($message, $code = 400) {
    http_response_code($code);
    hb_json(['ok' => false, 'error' => $message]);
}
/* Questi quattro facevano gia' la cosa giusta, ma a modo loro. Passandoli alle
   sec_* del nucleo si guadagna quello che avevano in piu': sec_str toglie i tag
   e i caratteri di controllo, non solo gli spazi ai bordi. */
function hb_post($key, $default = '') {
    if (!isset($_POST[$key])) return $default;
    return sec_str($_POST[$key], 2000);
}
function hb_color($value, $fallback = '#7C5CFF') {
    return strtoupper(sec_color($value, $fallback));
}
function hb_date($value, $fallback = null) {
    return sec_date($value, $fallback);
}
function hb_enum($value, array $allowed, $fallback) {
    return sec_enum($value, $allowed, $fallback);
}
function hb_weekdays($value) {
    $value = trim((string)$value);
    if (!preg_match('/^[01]{7}$/', $value) || strpos($value, '1') === false) return '1111111';
    return $value;
}
function hb_clamp($n, $min, $max) { $n = (float)$n; return $n < $min ? $min : ($n > $max ? $max : $n); }

/* ---------------------------------------------------------------------
   Impostazioni utente: creata al volo se manca
   --------------------------------------------------------------------- */
function hb_settings($conn, $userId) {
    $row = sec_one("SELECT * FROM habit_settings WHERE user_id = ?", [$userId]);
    if (!$row) {
        sec_q("INSERT IGNORE INTO habit_settings (user_id) VALUES (?)", [$userId]);
        $row = sec_one("SELECT * FROM habit_settings WHERE user_id = ?", [$userId]);
    }
    if (!$row) {
        return [
            'week_start' => 1, 'default_view' => 'list', 'visible_days' => 11,
            'accent_color' => '#7C5CFF', 'theme_mode' => 'auto', 'show_archived' => false,
            'group_by_day_part' => true, 'haptics_enabled' => true, 'sound_enabled' => true,
            'confetti_enabled' => true, 'carousel_autoplay' => true, 'carousel_interval_s' => 6,
            'chart_range_days' => 30, 'streak_freezes' => 3,
        ];
    }
    return [
        'week_start'          => (int)$row['week_start'],
        'default_view'        => $row['default_view'],
        'visible_days'        => (int)$row['visible_days'],
        'accent_color'        => $row['accent_color'],
        'theme_mode'          => $row['theme_mode'],
        'show_archived'       => (int)$row['show_archived'] === 1,
        'group_by_day_part'   => (int)$row['group_by_day_part'] === 1,
        'haptics_enabled'     => (int)$row['haptics_enabled'] === 1,
        'sound_enabled'       => (int)$row['sound_enabled'] === 1,
        'confetti_enabled'    => (int)$row['confetti_enabled'] === 1,
        'carousel_autoplay'   => (int)$row['carousel_autoplay'] === 1,
        'carousel_interval_s' => (int)$row['carousel_interval_s'],
        'chart_range_days'    => (int)$row['chart_range_days'],
        'streak_freezes'      => (int)$row['streak_freezes'],
    ];
}

/* ---------------------------------------------------------------------
   Lettura abitudini / log / categorie
   --------------------------------------------------------------------- */
function hb_habit_out($row) {
    return [
        'id'              => (int)$row['id'],
        'category_id'     => $row['category_id'] !== null ? (int)$row['category_id'] : null,
        'preset_key'      => $row['preset_key'],
        'name'            => $row['name'],
        'icon'            => $row['icon'],
        'color'           => $row['color'],
        'motivation'      => $row['motivation'],
        'kind'            => $row['habit_kind'],
        'goal_type'       => $row['goal_type'],
        'target_amount'   => (float)$row['target_amount'],
        'step_amount'     => (float)$row['step_amount'],
        'unit'            => $row['unit'],
        'frequency_type'  => $row['frequency_type'],
        'weekdays'        => $row['weekdays'],
        'times_per_week'  => (int)$row['times_per_week'],
        'times_per_month' => (int)$row['times_per_month'],
        'interval_days'   => (int)$row['interval_days'],
        'day_part'        => $row['day_part'],
        'duration_type'   => $row['duration_type'],
        'duration_days'   => $row['duration_days'] !== null ? (int)$row['duration_days'] : null,
        'start_date'      => $row['start_date'],
        'end_date'        => $row['end_date'],
        // durata stimata usata dal planner (null = usa lo scatto corrente)
        'planner_minutes' => $row['planner_minutes'] !== null ? (int)$row['planner_minutes'] : null,
        'sort_order'      => (int)$row['sort_order'],
        'is_archived'     => (int)$row['is_archived'] === 1,
        'created_at'      => $row['created_at'],
        'best_streak'     => isset($row['best_streak']) ? (int)$row['best_streak'] : 0,
    ];
}

function hb_load_habits($conn, $userId) {
    $sql = "SELECT h.*, r.best_streak
            FROM habits h
            LEFT JOIN habit_streak_records r ON r.habit_id = h.id
            WHERE h.user_id = ?
            ORDER BY h.is_archived ASC, h.sort_order ASC, h.id ASC";
    $res = sec_all($sql, [$userId]);
    $out = [];
    foreach ($res as $row) { $out[] = hb_habit_out($row); }
    return $out;
}

function hb_load_logs($conn, $userId, $days = HB_HISTORY_DAYS) {
    $from = date('Y-m-d', strtotime('-' . (int)$days . ' days'));
    $sql = "SELECT habit_id, log_date, status, amount, target_amount, completions, note, mood
            FROM habit_logs
            WHERE user_id = ? AND log_date >= ?
            ORDER BY log_date ASC";
    $res = sec_all($sql, [$userId, $from]);
    $out = [];
    foreach ($res as $row) {
        $out[] = [
            'habit_id'    => (int)$row['habit_id'],
            'date'        => $row['log_date'],
            'status'      => $row['status'],
            'amount'      => (float)$row['amount'],
            'target'      => (float)$row['target_amount'],
            'completions' => (int)$row['completions'],
            'note'        => $row['note'],
            'mood'        => $row['mood'] !== null ? (int)$row['mood'] : null,
        ];
    }
    return $out;
}

/* Diario della giornata: nota libera, umore ed energia, indicizzati per data. */
function hb_load_journal($conn, $userId, $days = HB_HISTORY_DAYS) {
    $from = date('Y-m-d', strtotime('-' . (int)$days . ' days'));
    $sql = "SELECT entry_date, mood, energy, note
            FROM habit_daily_journal
            WHERE user_id = ? AND entry_date >= ?
            ORDER BY entry_date DESC";
    $res = sec_all($sql, [$userId, $from]);
    $out = [];
    foreach ($res as $row) {
        $out[$row['entry_date']] = [
            'mood'   => $row['mood']   !== null ? (int)$row['mood']   : null,
            'energy' => $row['energy'] !== null ? (int)$row['energy'] : null,
            'note'   => $row['note'] !== null ? $row['note'] : '',
        ];
    }
    return $out ? $out : new stdClass();   // oggetto vuoto, non array, per il JSON
}

/* Le categorie predefinite (user_id = 0) hanno uno slug stabile: si traducono
   da quello, senza indovinare dal nome. Quelle create dall'utente non hanno una
   chiave nel dizionario e restano com'e' le ha scritte lui - che e' giusto. */
function hb_nome_categoria(array $row): string {
    if ((int)$row['user_id'] !== 0) return $row['name'];
    $tradotto = t('abitudini.cat_' . $row['slug']);
    return (strpos($tradotto, '<<') === 0) ? $row['name'] : $tradotto;
}

function hb_load_categories($conn, $userId) {
    $sql = "SELECT id, user_id, slug, name, icon, color, sort_order
            FROM habit_categories
            WHERE user_id = 0 OR user_id = ?
            ORDER BY sort_order ASC, id ASC";
    $res = sec_all($sql, [$userId]);
    $out = [];
    foreach ($res as $row) {
        $out[] = [
            'id'    => (int)$row['id'],
            'slug'  => $row['slug'],
            'name'  => hb_nome_categoria($row),
            'icon'  => $row['icon'],
            'color' => $row['color'],
            'own'   => (int)$row['user_id'] !== 0,
        ];
    }
    return $out;
}

function hb_load_achievements($conn, $userId) {
    $sql = "SELECT a.id, a.code, a.name, a.description, a.icon, a.tier, a.metric, a.threshold, a.scope,
                   u.habit_id, u.unlocked_at, u.value_reached, u.is_seen
            FROM habit_achievements a
            LEFT JOIN habit_user_achievements u ON u.achievement_id = a.id AND u.user_id = ?
            ORDER BY a.sort_order ASC";
    $res = sec_all($sql, [$userId]);
    $out = [];
    foreach ($res as $row) {
        $out[] = [
            'id'          => (int)$row['id'],
            'code'        => $row['code'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'icon'        => $row['icon'],
            'tier'        => $row['tier'],
            'metric'      => $row['metric'],
            'threshold'   => (int)$row['threshold'],
            'scope'       => $row['scope'],
            'unlocked'    => $row['unlocked_at'] !== null,
            'unlocked_at' => $row['unlocked_at'],
            'habit_id'    => $row['habit_id'] !== null ? (int)$row['habit_id'] : null,
            'is_seen'     => $row['is_seen'] !== null ? ((int)$row['is_seen'] === 1) : true,
        ];
    }
    return $out;
}

function hb_presets() {
    if (!is_readable(HB_PRESETS_FILE)) return ['palette' => [], 'icons' => [], 'units' => [], 'presets' => [], 'day_parts' => []];
    $data = json_decode(file_get_contents(HB_PRESETS_FILE), true);
    if (!is_array($data)) return ['palette' => [], 'icons' => [], 'units' => [], 'presets' => [], 'day_parts' => []];
    return hb_presets_tradotti($data);
}

/* Traduce i nomi che stanno in habits_presets.json.

   Il file resta UNO e in italiano: e' l'elenco dei suggerimenti, non un file di
   traduzioni. Se lo duplicassi in due lingue, la prossima abitudine aggiunta
   esisterebbe solo in una delle due e nessuno se ne accorgerebbe finche' un
   utente inglese non la trova scritta in italiano.

   Ogni voce ha una chiave stabile - 'drink_water', 'violet', 'morning' - e la
   traduzione si cerca da quella. Se manca, resta il nome italiano del file:
   una voce nuova si vede subito ma non rompe niente. */
function hb_presets_tradotti(array $d): array {
    $trad = function (string $chiave, string $ripiego): string {
        $t = t('abitudini.' . $chiave);
        return (strpos($t, '<<') === 0) ? $ripiego : $t;
    };
    /* Le unita' non hanno una chiave nel file: si ricava dal testo, sempre allo
       stesso modo qui e nello script che ha generato il dizionario. */
    $chiaveUnita = function (string $u): string {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT', $u);
        $x = preg_replace('/[^a-zA-Z0-9]+/', '_', $x !== false ? $x : $u);
        $x = strtolower(trim($x, '_'));
        return $x === '' ? 'x' : $x;
    };

    foreach ($d['palette'] ?? [] as $i => $p) {
        if (isset($p['key'], $p['name'])) $d['palette'][$i]['name'] = $trad('pal_' . $p['key'], $p['name']);
    }
    foreach ($d['day_parts'] ?? [] as $i => $p) {
        if (isset($p['key'], $p['name'])) $d['day_parts'][$i]['name'] = $trad('part_' . $p['key'], $p['name']);
    }
    foreach ($d['units'] ?? [] as $i => $u) {
        $d['units'][$i] = $trad('unit_' . $chiaveUnita((string)$u), (string)$u);
    }
    if (isset($d['icons']) && is_array($d['icons'])) {
        $icone = [];
        foreach ($d['icons'] as $gruppo => $lista) {
            $icone[$trad('icone_' . $chiaveUnita((string)$gruppo), (string)$gruppo)] = $lista;
        }
        $d['icons'] = $icone;
    }
    foreach ($d['presets'] ?? [] as $i => $p) {
        if (!isset($p['key'])) continue;
        if (isset($p['name']))       $d['presets'][$i]['name']       = $trad('pre_' . $p['key'], $p['name']);
        if (isset($p['motivation'])) $d['presets'][$i]['motivation'] = $trad('pre_' . $p['key'] . '_m', $p['motivation']);
        /* anche l'unita' del preset, con la stessa regola */
        if (isset($p['unit']) && $p['unit'] !== '') {
            $d['presets'][$i]['unit'] = $trad('unit_' . $chiaveUnita((string)$p['unit']), (string)$p['unit']);
        }
    }
    return $d;
}

/* ---------------------------------------------------------------------
   Calendario: l'abitudine è prevista in quel giorno?
   weekly / monthly sono "flessibili": ticchettabili ogni giorno, con un
   obiettivo per periodo. daily e interval hanno giorni fissi.
   --------------------------------------------------------------------- */
function hb_is_due($habit, $dateStr) {
    if ($dateStr < $habit['start_date']) return false;
    if (!empty($habit['end_date']) && $dateStr > $habit['end_date']) return false;
    switch ($habit['frequency_type']) {
        case 'daily':
            $n = (int)date('N', strtotime($dateStr));      // 1 = lunedì
            return substr($habit['weekdays'], $n - 1, 1) === '1';
        case 'interval':
            $step = max(1, (int)$habit['interval_days']);
            $diff = (int)floor((strtotime($dateStr) - strtotime($habit['start_date'])) / 86400);
            return $diff >= 0 && ($diff % $step) === 0;
        case 'weekly':
        case 'monthly':
        default:
            return true;
    }
}

/* Lunedì della settimana a cui appartiene la data (stesso criterio di
   hb_is_due, che usa date('N') = 1 per lunedì). */
function hb_week_start($dateStr) {
    $n = (int)date('N', strtotime($dateStr));
    return date('Y-m-d', strtotime($dateStr . ' -' . ($n - 1) . ' day'));
}
function hb_month_start($dateStr) { return substr($dateStr, 0, 7) . '-01'; }
/* Primo giorno del mese precedente a un giorno-1 (togliere un giorno e
   riallineare, non un mese intero: coi mesi corti strtotime('-1 month')
   su un 31 salta indietro in modo irregolare). Serve solo all'indietro:
   lo streak corrente lato server scorre solo verso il passato. */
function hb_prev_month_start($monthStart) { return date('Y-m-01', strtotime($monthStart . ' -1 day')); }

/* Quante volte fatta ('done') in quel periodo, dalla mappa data->stato già
   in memoria (evita una query per periodo). */
function hb_period_done_count($byDate, $periodStart, $isWeekly) {
    $periodEnd = $isWeekly ? date('Y-m-d', strtotime($periodStart . ' +6 day')) : date('Y-m-t', strtotime($periodStart));
    $n = 0;
    foreach ($byDate as $d => $status) {
        if ($status === 'done' && $d >= $periodStart && $d <= $periodEnd) $n++;
    }
    return $n;
}

/* Streak corrente per weekly/monthly: l'unità è il periodo (settimana o
   mese), non il giorno — hb_is_due le dà dovute ogni giorno perché sono
   "flessibili", ma lo streak deve guardare se il periodo ha raggiunto
   times_per_week/times_per_month, non se OGNI giorno è stato fatto.
   Il periodo che contiene oggi non rompe la serie se non ancora
   completato (è ancora in corso), come "oggi non ancora fatto" nelle
   daily; un periodo già chiuso senza obiettivo raggiunto la rompe. */
function hb_current_period_streak($habit, $byDate) {
    $isWeekly = $habit['frequency_type'] === 'weekly';
    $target = $isWeekly ? max(1, (int)$habit['times_per_week']) : max(1, (int)$habit['times_per_month']);
    $startPeriod = $isWeekly ? hb_week_start($habit['start_date']) : hb_month_start($habit['start_date']);
    $todayPeriod = $isWeekly ? hb_week_start(date('Y-m-d')) : hb_month_start(date('Y-m-d'));

    $streak = 0;
    $periodStart = $todayPeriod;
    $guard = 0;
    while ($periodStart >= $startPeriod && $guard++ < 600) {
        $done = hb_period_done_count($byDate, $periodStart, $isWeekly);
        if ($done >= $target) {
            $streak++;
        } elseif ($periodStart === $todayPeriod) {
            // periodo in corso: non rompe
        } else {
            break;
        }
        $periodStart = $isWeekly ? date('Y-m-d', strtotime($periodStart . ' -7 day')) : hb_prev_month_start($periodStart);
    }
    return $streak;
}

/* Streak corrente calcolata a ritroso dai log (skipped non spezza). */
function hb_current_streak($conn, $habit, $userId) {
    $res = sec_all("SELECT log_date, status FROM habit_logs WHERE habit_id = ? AND user_id = ? ORDER BY log_date DESC LIMIT 1000",
                   [(int)$habit['id'], $userId]);
    $byDate = [];
    foreach ($res as $row) { $byDate[$row['log_date']] = $row['status']; }

    $isQuit = $habit['kind'] === 'quit';
    if (!$isQuit && ($habit['frequency_type'] === 'weekly' || $habit['frequency_type'] === 'monthly')) {
        return hb_current_period_streak($habit, $byDate);
    }

    $streak = 0;
    /* Si scorre per date, non per secondi: togliere 86400 secondi sbaglia nei
       due giorni del cambio d'ora, quando un giorno dura 23 o 25 ore - la
       streak saltava un giorno a marzo e ne contava due volte lo stesso a
       ottobre. strtotime('-N day') ragiona in giorni di calendario. */
    $oggi = date('Y-m-d');
    for ($i = 0; $i < 1000; $i++) {
        $d = date('Y-m-d', strtotime($oggi . ' -' . $i . ' day'));
        if ($d < $habit['start_date']) break;
        if (!hb_is_due($habit, $d)) continue;
        $status = isset($byDate[$d]) ? $byDate[$d] : null;
        if ($isQuit) {
            // le "quit" partono completate: solo un 'failed' rompe la catena
            if ($status === 'failed') break;
            $streak++;
            continue;
        }
        if ($status === 'done') { $streak++; continue; }
        if ($status === 'skipped') continue;                 // giorno neutro
        if ($i === 0) continue;                              // oggi non ancora fatto: non rompe nulla
        break;
    }
    return $streak;
}

/* ---------------------------------------------------------------------
   Traguardi: valutati dopo ogni check-in
   --------------------------------------------------------------------- */
/* $types non serve piu': sec_q deduce il tipo dal valore invece di farselo
   dichiarare. Una lettera sbagliata in 'iissddis' spostava tutti i valori di un
   posto, in silenzio. */
function hb_scalar($sql, $params) {
    $row = sec_one($sql, $params);
    return $row ? (float)reset($row) : 0;
}

function hb_unlock($conn, $userId, $achId, $habitId, $value) {
    /* habit_id NULL sui traguardi globali: la UNIQUE KEY non basta perché in
       MySQL i NULL non collidono, quindi controlliamo prima con <=>. */
    $exists = sec_one("SELECT id FROM habit_user_achievements WHERE user_id = ? AND achievement_id = ? AND habit_id <=> ?",
                      [$userId, $achId, $habitId]);
    if ($exists) return false;

    sec_q("INSERT IGNORE INTO habit_user_achievements (user_id, achievement_id, habit_id, value_reached) VALUES (?, ?, ?, ?)",
          [$userId, $achId, $habitId, $value]);
    return sec_affected() > 0;
}

function hb_evaluate_achievements($conn, $userId, $habit, $currentStreak) {
    $unlocked = [];
    $catalog = sec_all("SELECT id, code, name, description, icon, tier, metric, threshold, scope FROM habit_achievements");
    if (empty($catalog)) return $unlocked;

    $totalCheckins = (int)hb_scalar("SELECT COUNT(*) FROM habit_logs WHERE user_id = ? AND status = 'done'", [$userId]);
    $habitCount    = (int)hb_scalar("SELECT COUNT(*) FROM habits WHERE user_id = ? AND is_archived = 0", [$userId]);

    // giornate perfette: giorni in cui tutti i log del giorno sono 'done'
    $perfectDays = (int)hb_scalar("SELECT COUNT(*) FROM (
            SELECT log_date FROM habit_logs WHERE user_id = ?
            GROUP BY log_date
            HAVING SUM(status <> 'done') = 0 AND COUNT(*) > 0
         ) t", [$userId]);

    // percentuale di completamento ultimi 30 giorni
    $done30 = (float)hb_scalar("SELECT COUNT(*) FROM habit_logs WHERE user_id = ? AND status = 'done' AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", [$userId]);
    $all30  = (float)hb_scalar("SELECT COUNT(*) FROM habit_logs WHERE user_id = ? AND status <> 'skipped' AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", [$userId]);
    $rate30 = $all30 > 0 ? round($done30 * 100 / $all30) : 0;

    foreach ($catalog as $a) {
        $threshold = (int)$a['threshold'];
        $value = 0; $habitId = null;
        switch ($a['metric']) {
            case 'streak':          $value = $currentStreak; $habitId = (int)$habit['id']; break;
            case 'total_checkins':  $value = $totalCheckins; break;
            case 'perfect_days':    $value = $perfectDays;   break;
            case 'completion_rate': $value = (int)$rate30;   break;
            case 'habit_count':     $value = $habitCount;    break;
            default: continue 2;
        }
        if ($value < $threshold) continue;
        if (hb_unlock($conn, $userId, (int)$a['id'], $habitId, $value)) {
            $unlocked[] = [
                'code' => $a['code'], 'name' => $a['name'], 'description' => $a['description'],
                'icon' => $a['icon'], 'tier' => $a['tier'], 'value' => $value,
            ];
        }
    }
    return $unlocked;
}

function hb_touch_records($conn, $habitId, $userId, $currentStreak) {
    $total = (int)hb_scalar("SELECT COUNT(*) FROM habit_logs WHERE habit_id = ? AND status = 'done'", [$habitId]);
    $sql = "INSERT INTO habit_streak_records (habit_id, user_id, best_streak, best_streak_end, total_checkins)
            VALUES (?, ?, ?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE
              best_streak_end = IF(VALUES(best_streak) > best_streak, CURDATE(), best_streak_end),
              best_streak     = GREATEST(best_streak, VALUES(best_streak)),
              total_checkins  = VALUES(total_checkins)";
    sec_q($sql, [$habitId, $userId, $currentStreak, $total]);
    return (int)hb_scalar("SELECT best_streak FROM habit_streak_records WHERE habit_id = ?", [$habitId]);
}

function hb_fetch_habit($conn, $habitId, $userId) {
    $row = sec_one("SELECT * FROM habits WHERE id = ? AND user_id = ?", [$habitId, $userId]);
    return $row ? hb_habit_out($row) : null;
}


/* =====================================================================
   API AJAX
   ===================================================================== */
$ajax = isset($_GET['ajax']) ? $_GET['ajax'] : '';

/* Ogni chiamata che scrive deve portare il token della sessione e non può
   arrivare da un altro sito: lo verifica security_core.php. Il token lo
   aggiunge da sé standout_fetch.js, incluso nel <head>. */
if ($ajax !== '' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'habits', 0, 600, 60);
}

if ($ajax === 'bootstrap') {
    hb_json([
        'ok'           => true,
        'today'        => date('Y-m-d'),
        'habits'       => hb_load_habits($conn, $current_user_id),
        'logs'         => hb_load_logs($conn, $current_user_id),
        'journal'      => hb_load_journal($conn, $current_user_id),
        'categories'   => hb_load_categories($conn, $current_user_id),
        'achievements' => hb_load_achievements($conn, $current_user_id),
        'settings'     => hb_settings($conn, $current_user_id),
    ]);
}

/* --- check-in: incrementa, decrementa, salta, imposta un valore ------- */
if ($ajax === 'log_set') {
    $habitId = sec_int($_POST['habit_id'] ?? 0, 1);
    /* La riga dev'essere sua. Le query hanno gia' "AND user_id = ?", ma cosi'
       chi prova a toccare l'abitudine di un altro riceve "non trovata" invece
       di un silenzioso "fatto" che non ha fatto niente. */
    if (!sec_owns('habits', $habitId, $current_user_id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    $date    = hb_date(hb_post('date'), date('Y-m-d'));
    $mode    = hb_enum(hb_post('mode'), ['increment','decrement','set','skip','fail','clear','toggle'], 'toggle');
    $value   = (float)hb_post('value', '0');
    $source  = hb_enum(hb_post('source'), ['swipe','tap','table','detail','quick_add','undo','auto'], 'tap');
    $note    = hb_post('note', '');

    $habit = hb_fetch_habit($conn, $habitId, $current_user_id);
    if (!$habit) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    if ($date > date('Y-m-d')) hb_fail(t('abitudini.no_giorno_futuro'));

    // log esistente per quel giorno
    $log = sec_one("SELECT * FROM habit_logs WHERE habit_id = ? AND log_date = ?", [$habitId, $date]);

    $target = (float)$habit['target_amount'];
    $step   = max(0.01, (float)$habit['step_amount']);
    $amount = $log ? (float)$log['amount'] : 0.0;
    $completions = $log ? (int)$log['completions'] : 0;
    $status = $log ? $log['status'] : 'partial';
    $isQuit = $habit['kind'] === 'quit';

    switch ($mode) {
        case 'increment':
            $amount += ($habit['goal_type'] === 'quantity') ? $step : $target;
            $completions++;
            break;
        case 'decrement':
            $amount -= ($habit['goal_type'] === 'quantity') ? $step : $target;
            $completions--;
            break;
        case 'set':
            $amount = $value;
            $completions = $step > 0 ? (int)ceil($amount / $step) : 1;
            break;
        case 'toggle':
            if ($isQuit) {
                // per le "da evitare" il confronto va fatto sulla quantità presente,
                // non sulla stringa di stato (che per le quit non vale mai 'done')
                if ($amount > 0) { $amount = 0; $completions = 0; }
                else { $amount = $target; $completions = max(1, $completions); }
            } else {
                if ($status === 'done') { $amount = 0; $completions = 0; }
                else { $amount = $target; $completions = max(1, $completions); }
            }
            break;
        case 'skip':
            $status = ($status === 'skipped') ? 'partial' : 'skipped';
            break;
        case 'fail':   // per le "quit": ci sono cascato
            $status = ($status === 'failed') ? 'partial' : 'failed';
            $amount = ($status === 'failed') ? 1 : 0;
            break;
        case 'clear':
            $amount = 0; $completions = 0; $status = 'partial';
            break;
    }
    if ($amount < 0) $amount = 0;
    if ($completions < 0) $completions = 0;

    if ($mode !== 'skip' && $mode !== 'fail') {
        if ($isQuit) {
            if ($habit['goal_type'] === 'quantity' && $target > 0) {
                // "da evitare" a quantità: resta "partial" (sotto la soglia) finché
                // non si raggiunge/supera l'obiettivo, che qui rappresenta il limite massimo
                $status = ($amount >= $target) ? 'failed' : ($amount > 0 ? 'partial' : 'partial');
            } else {
                $status = $amount > 0 ? 'failed' : 'partial';
            }
        } else {
            $status = ($amount >= $target && $target > 0) ? 'done' : ($amount > 0 ? 'partial' : 'partial');
        }
    }

    $emptyRow = ($amount <= 0 && $status === 'partial' && $note === '');
    if ($emptyRow && $log) {
        sec_q("DELETE FROM habit_logs WHERE id = ?", [(int)$log['id']]);
        $log = null;
    } elseif (!$emptyRow) {
        $sql = "INSERT INTO habit_logs (habit_id, user_id, log_date, status, amount, target_amount, completions, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), amount = VALUES(amount),
                    target_amount = VALUES(target_amount), completions = VALUES(completions),
                    note = IF(VALUES(note) = '', note, VALUES(note))";
        sec_q($sql, [$habitId, $current_user_id, $date, $status, $amount, $target, $completions, $note]);
    }

    // evento granulare (serve al grafico "a che ora completo")
    if (in_array($mode, ['increment','decrement','toggle','set'], true)) {
        $delta = ($mode === 'decrement') ? -$step : $step;
        sec_q("INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source) VALUES (?, ?, ?, ?, ?)", [$habitId, $current_user_id, $date, $delta, $source]);
    }

    $streak     = hb_current_streak($conn, $habit, $current_user_id);
    $bestStreak = hb_touch_records($conn, $habitId, $current_user_id, $streak);
    $unlocked   = ($status === 'done' || $isQuit) ? hb_evaluate_achievements($conn, $current_user_id, $habit, $streak) : [];

    hb_json([
        'ok'          => true,
        'habit_id'    => $habitId,
        'date'        => $date,
        'status'      => $status,
        'amount'      => $amount,
        'target'      => $target,
        'completions' => $completions,
        'removed'     => $emptyRow,
        'streak'      => $streak,
        'best_streak' => $bestStreak,
        'unlocked'    => $unlocked,
    ]);
}

/* --- nota / umore di un check-in ------------------------------------- */
if ($ajax === 'log_note') {
    $habitId = sec_int($_POST['habit_id'] ?? 0, 1);
    if (!sec_owns('habits', $habitId, $current_user_id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    $date    = hb_date(hb_post('date'), date('Y-m-d'));
    $note    = mb_substr(hb_post('note'), 0, 500);
    $mood    = hb_post('mood') === '' ? null : (int)hb_clamp(hb_post('mood'), 1, 5);
    if (!hb_fetch_habit($conn, $habitId, $current_user_id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);

    $sql = "INSERT INTO habit_logs (habit_id, user_id, log_date, status, amount, target_amount, note, mood)
            VALUES (?, ?, ?, 'partial', 0, 1, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note), mood = VALUES(mood)";
    sec_q($sql, [$habitId, $current_user_id, $date, $note, $mood]);
    hb_json(['ok' => true, 'note' => $note, 'mood' => $mood]);
}

/* --- creazione / modifica abitudine ---------------------------------- */
if ($ajax === 'habit_save') {
    $id   = sec_int($_POST['id'] ?? 0, 0);
    $name = mb_substr(hb_post('name'), 0, 120);
    if ($name === '') hb_fail(t('abitudini.serve_un_nome'));

    $icon        = mb_substr(hb_post('icon', '⭐️'), 0, 8);
    $color       = hb_color(hb_post('color'));
    $motivation  = mb_substr(hb_post('motivation'), 0, 255);
    $presetKey   = mb_substr(hb_post('preset_key'), 0, 60);
    $categoryId  = sec_int($_POST['category_id'] ?? 0, 0);
    $categoryId  = $categoryId > 0 ? $categoryId : null;
    $kind        = hb_enum(hb_post('kind'), ['build','quit'], 'build');
    $goalType    = hb_enum(hb_post('goal_type'), ['completion','quantity'], 'completion');
    $target      = $goalType === 'quantity' ? max(0.01, (float)hb_post('target_amount', '1')) : 1.0;
    $step        = $goalType === 'quantity' ? max(0.01, (float)hb_post('step_amount', '1')) : 1.0;
    $unit        = $goalType === 'quantity' ? mb_substr(hb_post('unit'), 0, 24) : '';
    $freqType    = hb_enum(hb_post('frequency_type'), ['daily','weekly','monthly','interval'], 'daily');
    $weekdays    = hb_weekdays(hb_post('weekdays', '1111111'));
    $perWeek     = (int)hb_clamp(hb_post('times_per_week', '7'), 1, 7);
    $perMonth    = (int)hb_clamp(hb_post('times_per_month', '30'), 1, 31);
    $interval    = (int)hb_clamp(hb_post('interval_days', '1'), 1, 365);
    $dayPart     = hb_enum(hb_post('day_part'), ['any','morning','afternoon','evening','night'], 'any');
    $durType     = hb_enum(hb_post('duration_type'), ['forever','days','end_date'], 'forever');
    $durDays     = $durType === 'days' ? (int)hb_clamp(hb_post('duration_days', '30'), 1, 3650) : null;
    $startDate   = hb_date(hb_post('start_date'), date('Y-m-d'));
    /* durata stimata per il planner: facoltativa. Vuoto o 0 = il planner
       userà la durata dello scatto che ha visualizzato in quel momento. */
    $plannerMin  = (int)hb_clamp(hb_post('planner_minutes', '0'), 0, 1440);
    $plannerMin  = $plannerMin > 0 ? $plannerMin : null;

    $endDate = null;
    if ($durType === 'days')      $endDate = date('Y-m-d', strtotime($startDate . ' +' . ($durDays - 1) . ' days'));
    elseif ($durType === 'end_date') $endDate = hb_date(hb_post('end_date'), null);

    if ($id > 0) {
        $existing = hb_fetch_habit($conn, $id, $current_user_id);
        if (!$existing) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
        $sql = "UPDATE habits SET category_id=?, preset_key=?, name=?, icon=?, color=?, motivation=?, habit_kind=?,
                    goal_type=?, target_amount=?, step_amount=?, unit=?, frequency_type=?, weekdays=?, times_per_week=?,
                    times_per_month=?, interval_days=?, day_part=?, duration_type=?, duration_days=?, start_date=?, end_date=?,
                    planner_minutes=?
                WHERE id=? AND user_id=?";
        /* 22 campi + id + user_id = 24 segnaposto. Non c'e' piu' la stringa dei
           tipi da tenere allineata: bastava una lettera in piu' e tutti i valori
           finivano nel campo sbagliato, senza nessun errore. */
        sec_q($sql, [
            $categoryId, $presetKey, $name, $icon, $color, $motivation, $kind,
            $goalType, $target, $step, $unit, $freqType, $weekdays, $perWeek,
            $perMonth, $interval, $dayPart, $durType, $durDays, $startDate, $endDate,
            $plannerMin, $id, $current_user_id]);
    } else {
        $order = (int)hb_scalar("SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM habits WHERE user_id = ?", [$current_user_id]);
        $sql = "INSERT INTO habits (user_id, category_id, preset_key, name, icon, color, motivation, habit_kind,
                    goal_type, target_amount, step_amount, unit, frequency_type, weekdays, times_per_week,
                    times_per_month, interval_days, day_part, duration_type, duration_days, start_date, end_date, sort_order,
                    planner_minutes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        /* 23 campi + planner_minutes = 24 segnaposto */
        sec_q($sql, [
            $current_user_id, $categoryId, $presetKey, $name, $icon, $color, $motivation, $kind,
            $goalType, $target, $step, $unit, $freqType, $weekdays, $perWeek,
            $perMonth, $interval, $dayPart, $durType, $durDays, $startDate, $endDate, $order,
            $plannerMin]);
        $id = sec_insert_id();
    }

    hb_json(['ok' => true, 'habit' => hb_fetch_habit($conn, $id, $current_user_id)]);
}

/* --- archivia / ripristina ------------------------------------------- */
if ($ajax === 'habit_archive') {
    $id      = sec_int($_POST['id'] ?? 0, 1);
    $archive = sec_bool($_POST['archive'] ?? '1') ? 1 : 0;
    if (!sec_owns('habits', $id, $current_user_id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    sec_q("UPDATE habits SET is_archived = ?, archived_at = IF(? = 1, NOW(), NULL) WHERE id = ? AND user_id = ?", [$archive, $archive, $id, $current_user_id]);
    hb_json(['ok' => true, 'id' => $id, 'archived' => $archive === 1]);
}

/* --- eliminazione definitiva ----------------------------------------- */
if ($ajax === 'habit_delete') {
    $id = sec_int($_POST['id'] ?? 0, 1);
    if (!sec_owns('habits', $id, $current_user_id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    sec_q("DELETE FROM habits WHERE id = ? AND user_id = ?", [$id, $current_user_id]);
    hb_json(['ok' => true, 'id' => $id]);
}

/* --- riordino elenco -------------------------------------------------- */
if ($ajax === 'habit_reorder') {
    $ids = json_decode(hb_post('ids', '[]'), true);
    if (!is_array($ids)) hb_fail(t('abitudini.ordine_non_valido'));
    $order = 10;
    foreach ($ids as $rawId) {
        $hid = sec_int($rawId, 1);
        if ($hid <= 0) continue;
        sec_q("UPDATE habits SET sort_order = ? WHERE id = ? AND user_id = ?", [$order, $hid, $current_user_id]);
        $order += 10;
    }
    hb_json(['ok' => true]);
}

/* --- categoria personalizzata ---------------------------------------- */
if ($ajax === 'category_save') {
    $name = mb_substr(hb_post('name'), 0, 60);
    if ($name === '') hb_fail(t('abitudini.serve_un_nome'));
    $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) . '_' . $current_user_id;
    $icon  = mb_substr(hb_post('icon', '🏷️'), 0, 8);
    $color = hb_color(hb_post('color'), '#8E8E93');
    $order = (int)hb_scalar("SELECT COALESCE(MAX(sort_order),0)+10 FROM habit_categories WHERE user_id = ?", [$current_user_id]);
    sec_q("INSERT INTO habit_categories (user_id, slug, name, icon, color, sort_order) VALUES (?,?,?,?,?,?)
                                   ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), color=VALUES(color)", [$current_user_id, $slug, $name, $icon, $color, $order]);
    hb_json(['ok' => true, 'categories' => hb_load_categories($conn, $current_user_id)]);
}

/* --- preferenze ------------------------------------------------------- */
if ($ajax === 'settings_save') {
    $fields = [
        'week_start'          => ['i', (int)hb_clamp(hb_post('week_start', '1'), 1, 7)],
        'default_view'        => ['s', hb_enum(hb_post('default_view'), ['list','table','stats'], 'list')],
        'visible_days'        => ['i', (int)hb_clamp(hb_post('visible_days', '11'), 5, 31)],
        'accent_color'        => ['s', hb_color(hb_post('accent_color'))],
        'theme_mode'          => ['s', hb_enum(hb_post('theme_mode'), ['auto','light','dark'], 'auto')],
        'show_archived'       => ['i', hb_post('show_archived') === '1' ? 1 : 0],
        'group_by_day_part'   => ['i', hb_post('group_by_day_part') === '1' ? 1 : 0],
        'haptics_enabled'     => ['i', hb_post('haptics_enabled') === '1' ? 1 : 0],
        'sound_enabled'       => ['i', hb_post('sound_enabled') === '1' ? 1 : 0],
        'confetti_enabled'    => ['i', hb_post('confetti_enabled') === '1' ? 1 : 0],
        'carousel_autoplay'   => ['i', hb_post('carousel_autoplay') === '1' ? 1 : 0],
        'carousel_interval_s' => ['i', (int)hb_clamp(hb_post('carousel_interval_s', '6'), 3, 30)],
        'chart_range_days'    => ['i', (int)hb_clamp(hb_post('chart_range_days', '30'), 7, 365)],
    ];
    $sets = []; $types = ''; $values = [];
    foreach ($fields as $col => $meta) {
        if (!isset($_POST[$col])) continue;
        $sets[] = "`$col` = ?"; $types .= $meta[0]; $values[] = $meta[1];
    }
    if (!empty($sets)) {
        $values[] = $current_user_id;
        /* $sets contiene "campo = ?": segnaposto, non valori. I tipi non si
           dichiarano piu': li deduce sec_q dal valore. */
        sec_q("UPDATE habit_settings SET " . implode(', ', $sets) . " WHERE user_id = ?", $values);
    }
    hb_json(['ok' => true, 'settings' => hb_settings($conn, $current_user_id)]);
}

/* --- diario del giorno ------------------------------------------------ */
if ($ajax === 'journal_save') {
    $date   = hb_date(hb_post('date'), date('Y-m-d'));
    $note   = mb_substr(hb_post('note'), 0, 4000);
    $mood   = hb_post('mood') === '' ? null : (int)hb_clamp(hb_post('mood'), 1, 5);
    $energy = hb_post('energy') === '' ? null : (int)hb_clamp(hb_post('energy'), 1, 5);
    $sql = "INSERT INTO habit_daily_journal (user_id, entry_date, mood, energy, note) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE mood=VALUES(mood), energy=VALUES(energy), note=VALUES(note)";
    sec_q($sql, [$current_user_id, $date, $mood, $energy, $note]);
    hb_json(['ok' => true]);
}

/* --- traguardi visti -------------------------------------------------- */
if ($ajax === 'achievements_seen') {
    sec_q("UPDATE habit_user_achievements SET is_seen = 1 WHERE user_id = ?", [$current_user_id]);
    hb_json(['ok' => true]);
}

/* --- orari dei check-in (grafico "a che ora") ------------------------- */
if ($ajax === 'hour_distribution') {
    $sql = "SELECT HOUR(created_at) AS h, COUNT(*) AS c
            FROM habit_log_events
            WHERE user_id = ? AND delta > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY h ORDER BY h";
    $res = sec_all($sql, [$current_user_id]);
    $buckets = array_fill(0, 24, 0);
    foreach ($res as $row) { $buckets[(int)$row['h']] = (int)$row['c']; }
    hb_json(['ok' => true, 'hours' => $buckets]);
}

/* =====================================================================
   PAYLOAD INIZIALE (niente fetch al primo caricamento: la pagina è già viva)
   ===================================================================== */
$bootstrap = [
    'today'        => date('Y-m-d'),
    'user'         => ['id' => $current_user_id, 'name' => isset($utente_corrente['username']) ? $utente_corrente['username'] : ''],
    'habits'       => hb_load_habits($conn, $current_user_id),
    'logs'         => hb_load_logs($conn, $current_user_id),
    'journal'      => hb_load_journal($conn, $current_user_id),
    'categories'   => hb_load_categories($conn, $current_user_id),
    'achievements' => hb_load_achievements($conn, $current_user_id),
    'settings'     => hb_settings($conn, $current_user_id),
    'catalog'      => hb_presets(),
];
$accent = hb_color($bootstrap['settings']['accent_color'] ?? '#7C5CFF');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<meta name="theme-color" content="#000000">
<title><?php te('abitudini.titolo'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="habits.css?v=<?php echo @filemtime(__DIR__ . '/habits.css') ?: time(); ?>">
</head>
<body class="hb-body" style="--accent:<?php echo sec_h($accent); ?>;">

<!-- ============================ HEADER ============================ -->
<header class="hb-header">
    <div class="hb-header-row">
        <div class="hb-header-titles">
            <div class="hb-eyebrow" id="hbEyebrow"><?php te('comune.oggi'); ?></div>
            <h1 class="hb-title"><?php te('abitudini.abitudini'); ?></h1>
        </div>
        <div class="hb-header-actions">
            <button type="button" class="hb-icon-btn" id="hbBtnTable" aria-label="<?php te('abitudini.vista_tabella'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5h18M3 12h18M3 19h18M9 5v14M15 5v14"/></svg>
            </button>
            <button type="button" class="hb-icon-btn" id="hbBtnStats" aria-label="<?php te('abitudini.statistiche'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 20V10M12 20V4M19 20v-6"/></svg>
            </button>
            <button type="button" class="hb-icon-btn" id="hbBtnSettings" aria-label="<?php te('comune.impostazioni'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 8.9 19a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 5 8.9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
            </button>
            <?php /* pallino del profilo, in fila con i pulsanti dell'intestazione */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
        </div>
    </div>

    <!-- barra giorni scorrevole -->
    <div class="hb-daystrip" id="hbDayStrip"></div>
</header>

<?php if (is_readable(__DIR__ . '/../partials/sidebar.html')) { include __DIR__ . '/../partials/sidebar.html'; } ?>

<main class="hb-page">
    <!-- carosello statistiche: scorre da solo e si aggiorna in tempo reale -->
    <section class="hb-carousel-wrap">
        <div class="hb-carousel" id="hbCarousel"></div>
        <div class="hb-dots" id="hbCarouselDots"></div>
    </section>

    <!-- anello riepilogo del giorno selezionato -->
    <section class="hb-dayhero" id="hbDayHero"></section>

    <!-- elenco abitudini -->
    <section class="hb-list-wrap">
        <div class="hb-list-head">
            <div class="hb-list-title" id="hbListTitle"><?php te('abitudini.le_tue_abitudini'); ?></div>
            <div class="hb-filters" id="hbFilters"></div>
        </div>
        <div class="hb-list" id="hbList"></div>
        <div class="hb-empty" id="hbEmpty" hidden>
            <div class="hb-empty-emoji">🌱</div>
            <div class="hb-empty-title"><?php te('abitudini.qui_non_c_e_ancora'); ?></div>
            <p class="hb-empty-text"><?php te('abitudini.aggiungi_la_prima_abitudine_e'); ?></p>
            <button type="button" class="hb-btn hb-btn-primary" id="hbEmptyAdd"><?php te('abitudini.aggiungi_abitudine'); ?></button>
        </div>
    </section>
</main>

<button type="button" class="hb-fab" id="hbFab" aria-label="<?php te('abitudini.aggiungi_abitudine'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- effetti: particelle di completamento -->
<canvas id="hbConfetti" class="hb-confetti"></canvas>
<div class="hb-toast-stack" id="hbToasts"></div>

<!-- ============================ SHEETS ============================ -->
<div class="hb-sheet-overlay" id="hbSheetEditor">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbEditorTitle"><?php te('abitudini.nuova_abitudine'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetEditor" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbEditorBody"></div>
        <div class="hb-sheet-actions" id="hbEditorActions"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetDetail">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbDetailTitle"><?php te('abitudini.dettaglio'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetDetail" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbDetailBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetStats">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.statistiche'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetStats" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbStatsBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetTable">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.tabella'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetTable" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbTableBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetPicker">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbPickerTitle"><?php te('abitudini.scegli'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetPicker" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbPickerBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetSettings">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('comune.impostazioni'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetSettings" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbSettingsBody"></div>
    </div>
</div>

<!-- nota del check-in / diario della giornata -->
<div class="hb-sheet-overlay" id="hbSheetNote">
    <div class="hb-sheet">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbNoteTitle"><?php te('abitudini.nota'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetNote" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body">
            <div class="hb-note-day" id="hbNoteDay"></div>
            <div class="hb-field">
                <label><?php te('abitudini.com_e_andata'); ?></label>
                <div class="hb-mood-row" id="hbNoteMood"></div>
            </div>
            <div class="hb-field" id="hbNoteEnergyField" hidden>
                <label><?php te('abitudini.energia'); ?></label>
                <div class="hb-mood-row" id="hbNoteEnergy"></div>
            </div>
            <div class="hb-field">
                <label for="hbNoteText"><?php te('abitudini.il_tuo_pensiero'); ?></label>
                <textarea class="hb-textarea" id="hbNoteText" rows="5" maxlength="1000"
                          placeholder="<?php te('abitudini.cosa_ti_ha_aiutato_oggi'); ?>"></textarea>
                <div class="hb-help" id="hbNoteCount"><?php te('abitudini.0_caratteri'); ?></div>
            </div>
        </div>
        <div class="hb-sheet-actions">
            <button type="button" class="hb-btn hb-btn-quiet" id="hbNoteClear"><?php te('abitudini.cancella'); ?></button>
            <button type="button" class="hb-btn hb-btn-primary" id="hbNoteSave"><?php te('abitudini.salva_nota'); ?></button>
        </div>
    </div>
</div>

<!-- riordino abitudini -->
<div class="hb-sheet-overlay" id="hbSheetReorder">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.riordina'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetReorder" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body">
            <div class="hb-help hb-reorder-hint"><?php te('abitudini.trascina_dalla_maniglia_per_cambiare'); ?></div>
            <div class="hb-reorder-list" id="hbReorderList"></div>
        </div>
    </div>
</div>

<!-- conferma generica -->
<div class="hb-confirm-overlay" id="hbConfirm">
    <div class="hb-confirm-box">
        <div class="hb-confirm-title" id="hbConfirmTitle"><?php te('abitudini.sicuro'); ?></div>
        <div class="hb-confirm-msg" id="hbConfirmMsg"></div>
        <div class="hb-confirm-actions">
            <button type="button" class="hb-btn hb-btn-quiet" id="hbConfirmNo"><?php te('comune.annulla'); ?></button>
            <button type="button" class="hb-btn hb-btn-danger" id="hbConfirmYes"><?php te('comune.elimina'); ?></button>
        </div>
    </div>
</div>

<!-- traguardo sbloccato -->
<div class="hb-award-overlay" id="hbAward">
    <div class="hb-award-card">
        <div class="hb-award-glow"></div>
        <div class="hb-award-icon" id="hbAwardIcon">🏆</div>
        <div class="hb-award-kicker"><?php te('abitudini.traguardo_sbloccato'); ?></div>
        <div class="hb-award-name" id="hbAwardName"></div>
        <div class="hb-award-desc" id="hbAwardDesc"></div>
        <button type="button" class="hb-btn hb-btn-primary" id="hbAwardClose"><?php te('abitudini.continua'); ?></button>
    </div>
</div>

<?php
/* Popup di import/export: si apre dal tasto "Importa"/"Esporta" nelle
   Impostazioni (vedi renderSettings() in habits.js). Va incluso qui, dopo
   tutti i pannelli della pagina, cosi' l'overlay sta sopra a tutto. */
include __DIR__ . '/habits_import.php';
?>

<?php
/* Le stringhe che servono a habits.js: vanno prima del file, T() deve
   esistere quando parte. */
/* 'import' serve al pannello di importazione, incluso qui sotto. */
echo standout_i18n_json(['comune', 'abitudini', 'import']);
?>
<script>window.HB_BOOT = <?php echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<?php
/* Diario del giorno: componente condiviso con planner.php e diario.php,
   con in più gli elenchi "cose belle" e "cosa ho imparato". */
include __DIR__ . '/diario_giorno.php';
?>
<script src="habits.js?v=<?php echo @filemtime(__DIR__ . '/habits.js') ?: time(); ?>"></script>
</body>
</html>