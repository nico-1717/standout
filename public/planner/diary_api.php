<?php
/* =====================================================================
   STANDOUT — API DEL DIARIO
   Un solo punto di scrittura per: popup "diario del giorno" (habits e
   planner) e pagina diario.php. Dove si compila non importa: umore,
   energia e testo stanno in habit_daily_journal, gli elenchi in
   diary_list_items. Tutto passa da security_core.php.

   Chiamate:  diary_api.php?ajax=day&date=YYYY-MM-DD          (GET)
              diary_api.php?ajax=month&month=YYYY-MM          (GET)
              diary_api.php?ajax=save_text  ...               (POST)
              diary_api.php?ajax=save_mood  ...               (POST)
              diary_api.php?ajax=list_save  ...               (POST)
              diary_api.php?ajax=list_delete ...              (POST)
              diary_api.php?ajax=settings_save ...            (POST)
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 30) : '';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'diary', 0, 400, 60);
}

/* --------------------------------------------------------------------- */
/* Impostazioni della pagina diario                                      */
/* --------------------------------------------------------------------- */
function dg_settings(int $uid): array {
    $row = sec_one("SELECT font_key, paper_key, font_size FROM diary_settings WHERE user_id = ?", [$uid], 'i');
    if (!$row) {
        sec_q("INSERT IGNORE INTO diary_settings (user_id) VALUES (?)", [$uid], 'i');
        return ['font' => 'caveat', 'paper' => 'lined', 'size' => 18];
    }
    return ['font' => $row['font_key'], 'paper' => $row['paper_key'], 'size' => (int)$row['font_size']];
}

/* --------------------------------------------------------------------- */
/* Un giorno: umore, energia, testo, i due elenchi, gli habit            */
/* --------------------------------------------------------------------- */
function dg_day(int $uid, string $date): array {
    $j = sec_one("SELECT mood, energy, note FROM habit_daily_journal WHERE user_id = ? AND entry_date = ?", [$uid, $date], 'is');
    $items = sec_all("SELECT id, list_type, content, sort_order FROM diary_list_items
                      WHERE user_id = ? AND entry_date = ? ORDER BY list_type, sort_order, id", [$uid, $date], 'is');
    $good = []; $learned = [];
    foreach ($items as $it) {
        $row = ['id' => (int)$it['id'], 'content' => $it['content'], 'order' => (int)$it['sort_order']];
        if ($it['list_type'] === 'good') $good[] = $row; else $learned[] = $row;
    }
    return [
        'date'    => $date,
        'mood'    => $j && $j['mood'] !== null ? (int)$j['mood'] : null,
        'energy'  => $j && $j['energy'] !== null ? (int)$j['energy'] : null,
        'note'    => $j ? (string)$j['note'] : '',
        'good'    => $good,
        'learned' => $learned,
        'habits'  => dg_habit_progress($uid, $date),
        'weekly'  => dg_weekly_progress($uid, $date),
        'rings'   => dg_rings($uid, $date),
        'habits_list' => dg_habits_today($uid, $date),
    ];
}

/**
 * Le abitudini settimanali: quelle con un obiettivo di volte a settimana.
 * Non hanno un tetto: cinque palestre su quattro contano cinque.
 */
function dg_weekly_progress(int $uid, string $date): array {
    $d = new DateTime($date);
    $dow = (int)$d->format('N');
    $start = (clone $d)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    $end   = (new DateTime($start))->modify('+6 days')->format('Y-m-d');

    $rows = sec_all("SELECT id, name, icon, color, times_per_week, start_date, end_date
                     FROM habits
                     WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
                       AND frequency_type = 'weekly'
                     ORDER BY sort_order, id", [$uid], 'i');
    if (empty($rows)) return [];

    $fatti = sec_all("SELECT habit_id, COUNT(*) AS c FROM habit_logs
                      WHERE user_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
                      GROUP BY habit_id", [$uid, $start, $end], 'iss');
    $conta = [];
    foreach ($fatti as $f) { $conta[(int)$f['habit_id']] = (int)$f['c']; }

    $out = [];
    foreach ($rows as $h) {
        if ($h['start_date'] > $end) continue;
        if (!empty($h['end_date']) && $h['end_date'] < $start) continue;
        $target = max(1, (int)$h['times_per_week']);
        $done = $conta[(int)$h['id']] ?? 0;
        $out[] = [
            'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'],
            'color' => $h['color'], 'done' => $done, 'target' => $target,
        ];
    }
    return $out;
}

/** Abitudini "da fare" del giorno e quante sono già spuntate. */
/** Elenco delle abitudini di oggi con nome e stato, per il riepilogo. */
function dg_habits_today(int $uid, string $date): array {
    $rows = sec_all("SELECT id, name, icon, color, frequency_type, weekdays, interval_days,
                            start_date, end_date, is_archived
                     FROM habits WHERE user_id = ? AND habit_kind = 'build'
                       AND frequency_type <> 'weekly' AND is_archived = 0
                     ORDER BY sort_order, id", [$uid], 'i');
    $dow = (int)date('N', strtotime($date));
    $due = [];
    foreach ($rows as $h) {
        if ($h['start_date'] > $date) continue;
        if (!empty($h['end_date']) && $h['end_date'] < $date) continue;
        if ($h['frequency_type'] === 'daily') {
            $mask = (string)$h['weekdays'];
            if (isset($mask[$dow - 1]) && $mask[$dow - 1] !== '1') continue;
        } elseif ($h['frequency_type'] === 'interval') {
            $step = max(1, (int)$h['interval_days']);
            $delta = (int)round((strtotime($date) - strtotime($h['start_date'])) / 86400);
            if ($delta < 0 || $delta % $step !== 0) continue;
        }
        $due[(int)$h['id']] = ['id' => (int)$h['id'], 'name' => $h['name'],
                               'icon' => $h['icon'], 'color' => $h['color'], 'done' => false];
    }
    if (empty($due)) return [];
    $logs = sec_all("SELECT habit_id, status, amount, target_amount FROM habit_logs
                     WHERE user_id = ? AND log_date = ?", [$uid, $date], 'is');
    foreach ($logs as $l) {
        $hid = (int)$l['habit_id'];
        if (!isset($due[$hid])) continue;
        if ($l['status'] === 'done' || (float)$l['amount'] >= (float)$l['target_amount']) $due[$hid]['done'] = true;
    }
    return array_values($due);
}

function dg_habit_progress(int $uid, string $date): array {
    /* Settimanali e mensili hanno un conto tutto loro: qui risulterebbero
       mancate ogni giorno tranne quelli in cui le si fa. Le settimanali erano
       già escluse; le mensili no, ed era un errore — la query le lasciava
       passare e abbassavano la percentuale del giorno tutti i giorni. */
    $rows = sec_all("SELECT id, frequency_type, weekdays, interval_days, start_date, end_date, is_archived
                     FROM habits WHERE user_id = ? AND habit_kind = 'build'
                       AND frequency_type IN ('daily','interval')", [$uid], 'i');
    $due = [];
    foreach ($rows as $h) {
        if ((int)$h['is_archived'] === 1) continue;
        if ($h['start_date'] > $date) continue;
        if (!empty($h['end_date']) && $h['end_date'] < $date) continue;
        if (!dg_due($h, $date)) continue;
        $due[(int)$h['id']] = true;
    }
    $total = count($due);
    if ($total === 0) return ['done' => 0, 'total' => 0];

    $logs = sec_all("SELECT habit_id, status, amount, target_amount FROM habit_logs
                     WHERE user_id = ? AND log_date = ?", [$uid, $date], 'is');
    $done = 0;
    foreach ($logs as $l) {
        if (!isset($due[(int)$l['habit_id']])) continue;
        if ($l['status'] === 'done' || (float)$l['amount'] >= (float)$l['target_amount']) $done++;
    }
    return ['done' => $done, 'total' => $total];
}

/* Gli anelli concentrici: un cerchio per famiglia di frequenza, e solo per le
   famiglie che hanno almeno un'abitudine. Stessa logica di pl_rings nel
   planner, perché i due devono dire la stessa cosa nello stesso giorno.
   L'ordine è dal ritmo più stretto al più largo: il primo dell'elenco va
   disegnato più all'interno. */
function dg_rings(int $uid, string $date): array {
    $d      = new DateTime($date);
    $dow    = (int)$d->format('N');
    $lunedi = (clone $d)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    $domenica = (new DateTime($lunedi))->modify('+6 days')->format('Y-m-d');
    $mese   = substr($date, 0, 7);

    $habits = sec_all("SELECT id, frequency_type, weekdays, interval_days, start_date, end_date,
                              times_per_week, times_per_month
                       FROM habits
                       WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0", [$uid], 'i');
    if (empty($habits)) return [];

    /* un solo giro di registri per giorno, settimana e mese */
    $da = min($lunedi, $mese . '-01');
    $a  = max($domenica, date('Y-m-t', strtotime($date)));
    $logs = sec_all("SELECT habit_id, log_date, status, amount, target_amount
                     FROM habit_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?",
                    [$uid, $da, $a], 'iss');
    $fatto = [];
    foreach ($logs as $l) {
        $ok = $l['status'] === 'done' || (float)$l['amount'] >= (float)$l['target_amount'];
        if ($ok) $fatto[(int)$l['habit_id']][$l['log_date']] = true;
    }

    $conto = [
        'daily'    => ['done' => 0, 'total' => 0, 'label' => t('diario.abit_oggi')],
        'weekly'   => ['done' => 0, 'total' => 0, 'label' => t('diario.abit_settimana')],
        'monthly'  => ['done' => 0, 'total' => 0, 'label' => t('diario.abit_mese')],
        'interval' => ['done' => 0, 'total' => 0, 'label' => t('diario.abit_personalizzate')],
    ];

    foreach ($habits as $h) {
        $tipo = $h['frequency_type'];
        $suoi = $fatto[(int)$h['id']] ?? [];
        if ($date < $h['start_date']) continue;
        if (!empty($h['end_date']) && $date > $h['end_date']) continue;

        if ($tipo === 'daily' || $tipo === 'interval') {
            if (!dg_due($h, $date)) continue;
            $conto[$tipo]['total']++;
            if (!empty($suoi[$date])) $conto[$tipo]['done']++;
        } elseif ($tipo === 'weekly') {
            $n = 0;
            foreach ($suoi as $g => $_) if ($g >= $lunedi && $g <= $domenica) $n++;
            $obiettivo = max(1, (int)$h['times_per_week']);
            $conto['weekly']['total'] += $obiettivo;
            $conto['weekly']['done']  += min($n, $obiettivo);   // oltre l'obiettivo non gonfia l'anello
        } elseif ($tipo === 'monthly') {
            $n = 0;
            foreach ($suoi as $g => $_) if (substr($g, 0, 7) === $mese) $n++;
            $obiettivo = max(1, (int)$h['times_per_month']);
            $conto['monthly']['total'] += $obiettivo;
            $conto['monthly']['done']  += min($n, $obiettivo);
        }
    }

    $out = [];
    foreach (['daily', 'weekly', 'monthly', 'interval'] as $k) {
        if ($conto[$k]['total'] <= 0) continue;
        $out[] = ['key' => $k, 'label' => $conto[$k]['label'],
                  'done' => (int)$conto[$k]['done'], 'total' => (int)$conto[$k]['total']];
    }
    return $out;
}

/* L'abitudine è prevista in quel giorno? Vale per giornaliere e a intervallo;
   settimanali e mensili sono libere e non passano di qui. Prima questa
   verifica era ricopiata in tre funzioni. */
function dg_due(array $h, string $date): bool {
    if ($h['frequency_type'] === 'daily') {
        $dow  = (int)date('N', strtotime($date));
        $mask = (string)$h['weekdays'];
        return !isset($mask[$dow - 1]) || $mask[$dow - 1] === '1';
    }
    if ($h['frequency_type'] === 'interval') {
        $step  = max(1, (int)$h['interval_days']);
        $delta = (int)round((strtotime($date) - strtotime($h['start_date'])) / 86400);
        return $delta >= 0 && $delta % $step === 0;
    }
    return true;
}

/* ===================== ENDPOINT ===================== */

if ($ajax === 'day') {
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    sec_json(['ok' => true, 'day' => dg_day($uid, $date), 'settings' => dg_settings($uid)]);
}

if ($ajax === 'save_text') {
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    $note = sec_text(sec_in('note'), 20000);
    sec_q("INSERT INTO habit_daily_journal (user_id, entry_date, note) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE note = VALUES(note)", [$uid, $date, $note], 'iss');
    sec_json(['ok' => true]);
}

if ($ajax === 'save_mood') {
    $date   = sec_date(sec_in('date'), date('Y-m-d'));
    $which  = sec_enum(sec_in('which'), ['mood', 'energy'], 'mood');
    $raw    = sec_in('value');
    $value  = ($raw === null || $raw === '' || $raw === 0) ? null : sec_int($raw, 1, 5, 3);
    $column = $which === 'mood' ? 'mood' : 'energy';
    sec_q("INSERT INTO habit_daily_journal (user_id, entry_date, `$column`) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE `$column` = VALUES(`$column`)", [$uid, $date, $value]);
    sec_json(['ok' => true, 'which' => $which, 'value' => $value]);
}

if ($ajax === 'list_save') {
    $date   = sec_date(sec_in('date'), date('Y-m-d'));
    $type   = sec_enum(sec_in('list_type'), ['good', 'learned'], 'good');
    $itemId = sec_int(sec_in('item_id'), 0, PHP_INT_MAX, 0);
    $text   = sec_str(sec_in('content'), 300);

    if ($itemId > 0) {
        if (!sec_owns('diary_list_items', $itemId, $uid)) sec_die_json(t('api.voce_non_trovata'), 404, 'not_found');
        if ($text === '') {
            sec_q("DELETE FROM diary_list_items WHERE id = ? AND user_id = ?", [$itemId, $uid], 'ii');
            sec_json(['ok' => true, 'deleted' => true, 'id' => $itemId]);
        }
        sec_q("UPDATE diary_list_items SET content = ? WHERE id = ? AND user_id = ?", [$text, $itemId, $uid], 'sii');
        sec_json(['ok' => true, 'id' => $itemId]);
    }
    if ($text === '') sec_json(['ok' => true, 'id' => 0]);

    $next = sec_one("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM diary_list_items
                     WHERE user_id = ? AND entry_date = ? AND list_type = ?", [$uid, $date, $type], 'iss');
    sec_q("INSERT INTO diary_list_items (user_id, entry_date, list_type, content, sort_order) VALUES (?,?,?,?,?)",
          [$uid, $date, $type, $text, (int)($next['n'] ?? 1)], 'isssi');
    sec_json(['ok' => true, 'id' => sec_insert_id()]);
}

if ($ajax === 'list_delete') {
    $itemId = sec_int(sec_in('item_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('diary_list_items', $itemId, $uid)) sec_die_json(t('api.voce_non_trovata'), 404, 'not_found');
    sec_q("DELETE FROM diary_list_items WHERE id = ? AND user_id = ?", [$itemId, $uid], 'ii');
    sec_json(['ok' => true]);
}

if ($ajax === 'settings_save') {
    $fields = []; $params = [];
    $font = sec_in('font');
    if ($font !== null) {
        $fields[] = 'font_key = ?';
        $params[] = sec_enum($font, ['caveat', 'kalam', 'patrick', 'cormorant', 'jost'], 'caveat');
    }
    $size = sec_in('size');
    if ($size !== null) { $fields[] = 'font_size = ?'; $params[] = sec_int($size, 14, 26, 18); }
    $paper = sec_in('paper');
    if ($paper !== null) { $fields[] = 'paper_key = ?'; $params[] = sec_enum($paper, ['lined', 'dotted', 'plain'], 'lined'); }
    if (!empty($fields)) {
        sec_q("INSERT IGNORE INTO diary_settings (user_id) VALUES (?)", [$uid], 'i');
        $params[] = $uid;
        sec_q("UPDATE diary_settings SET " . implode(', ', $fields) . " WHERE user_id = ?", $params);
    }
    sec_json(['ok' => true, 'settings' => dg_settings($uid)]);
}

/* --------------------------------------------------------------------- */
/* Un mese: i giorni scritti, le medie, tutto quello che ho imparato     */
/* --------------------------------------------------------------------- */
if ($ajax === 'month') {
    $month = sec_str(sec_in('month'), 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));

    $rows = sec_all("SELECT entry_date, mood, energy, note FROM habit_daily_journal
                     WHERE user_id = ? AND entry_date BETWEEN ? AND ?", [$uid, $from, $to], 'iss');
    $days = [];
    $moods = []; $energies = [];
    foreach ($rows as $r) {
        $hasText = trim((string)$r['note']) !== '';
        $days[$r['entry_date']] = [
            'mood'      => $r['mood'] !== null ? (int)$r['mood'] : null,
            'energy'    => $r['energy'] !== null ? (int)$r['energy'] : null,
            'has_text'  => $hasText,
            'has_lists' => false,
        ];
        if ($r['mood'] !== null)   $moods[] = (int)$r['mood'];
        if ($r['energy'] !== null) $energies[] = (int)$r['energy'];
    }

    $lists = sec_all("SELECT entry_date, list_type, content FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> ''
                      ORDER BY entry_date, sort_order, id", [$uid, $from, $to], 'iss');
    $learned = [];
    foreach ($lists as $l) {
        if (!isset($days[$l['entry_date']])) {
            $days[$l['entry_date']] = ['mood' => null, 'energy' => null, 'has_text' => false, 'has_lists' => true];
        }
        $days[$l['entry_date']]['has_lists'] = true;
        if ($l['list_type'] === 'learned') {
            $learned[] = ['date' => $l['entry_date'], 'content' => $l['content']];
        }
    }

    $filled = 0;
    foreach ($days as $d) { if ($d['has_text'] || $d['has_lists']) $filled++; }

    $modeMood = null;
    if (!empty($moods)) {
        $counts = array_count_values($moods);
        arsort($counts);
        $modeMood = (int)array_key_first($counts);
    }
    $avg = function (array $a) { return empty($a) ? null : round(array_sum($a) / count($a), 2); };

    sec_json([
        'ok'    => true,
        'month' => $month,
        'days'  => $days,
        'stats' => [
            'mood_mode'  => $modeMood,
            'mood_avg'   => $avg($moods),
            'energy_avg' => $avg($energies),
            'filled'     => $filled,
            'total'      => (int)date('t', strtotime($from)),
        ],
        'learned' => $learned,
    ]);
}

sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');