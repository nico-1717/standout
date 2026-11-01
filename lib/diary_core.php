<?php
/* =====================================================================
   STANDOUT — IL DIARIO: la logica condivisa tra la pagina e l'API

   Prima stava tutta dentro public/planner/diary_api.php (le azioni ?ajax=…).
   Le funzioni dg_* fino a dg_due() sono SPOSTATE da li' senza cambiarne una riga;
   dg_numeri() e dg_mese() sono i corpi delle azioni «insights» e «month», con i
   valori che prima venivano da sec_in() passati come argomenti. La pagina e l'app
   devono dire le stesse cose (anelli, progressi, medie): un solo codice.

   Serve security_core.php (sec_*) e la lingua (t()). Caricato da diary_api.php e
   da lib/diary_api.php.
   ===================================================================== */
if (defined('STANDOUT_DIARY_CORE')) return;
define('STANDOUT_DIARY_CORE', true);

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

/* ===================== Le letture lunghe (corpo delle azioni della pagina) ===================== */

/**
 * Il diario in numeri: un anno giorno per giorno, gli anni a confronto, i trimestri con il
 * loro resoconto e il libro (diario_anno.php). $anno gia' controllato (2000-2100).
 */
function dg_numeri(int $uid, int $anno): array {
    require_once __DIR__ . '/planner/diario_numeri.php';
    $giorni = dn_giorni($uid, $anno . '-01-01', $anno . '-12-31');
    $oggi = date('Y-m-d');
    $compatti = [];
    foreach ($giorni as $d => $g) {
        if ($d > $oggi) break;
        $compatti[$d] = [$g['m'], $g['e'], $g['w'], $g['l'], $g['hd'], $g['hu'], $g['p'], $g['f']];
    }

    /* gli anni a confronto: dal primo in cui c'è qualcosa a oggi */
    $primo = sec_one("SELECT MIN(entry_date) AS d FROM habit_daily_journal WHERE user_id = ?", [$uid], 'i');
    $da = !empty($primo['d']) ? (int)substr($primo['d'], 0, 4) : (int)date('Y');
    $anni = [];
    for ($y = max($da, (int)date('Y') - 9); $y <= (int)date('Y'); $y++) {
        $fine = $y === (int)date('Y') ? $oggi : $y . '-12-31';
        $anni[] = ['anno' => $y] + dn_riassunto(dn_giorni($uid, $y . '-01-01', $fine));
    }

    /* i trimestri dell'anno: quanto è stato scritto, e cosa c'è già */
    $resoconti = []; $libri = [];
    if (dn_tabelle_ai()) {
        foreach (sec_all("SELECT id, trimestre FROM diary_ai_reports WHERE user_id = ? AND anno = ?", [$uid, $anno], 'ii') as $r) {
            $resoconti[(int)$r['trimestre']] = (int)$r['id'];
        }
        foreach (sec_all("SELECT id, titolo, periodo_da, periodo_a, stato, unito_a, created_at FROM diary_ai_books
                          WHERE user_id = ? ORDER BY created_at DESC LIMIT 30", [$uid], 'i') as $b) {
            $libri[] = ['id' => (int)$b['id'], 'titolo' => $b['titolo'], 'da' => $b['periodo_da'], 'a' => $b['periodo_a'],
                        'stato' => $b['stato'], 'unito_a' => $b['unito_a'] !== null ? (int)$b['unito_a'] : null];
        }
    }
    $trimestri = [];
    for ($q = 1; $q <= 4; $q++) {
        [$qda, $qa] = dn_trimestre($anno, $q);
        if ($qda > $oggi) break;
        $scritti = 0; $parole = 0;
        foreach ($giorni as $d => $g) {
            if ($d < $qda || $d > $qa) continue;
            if ($g['w'] >= 10) $scritti++;
            $parole += $g['w'];
        }
        $trimestri[] = ['q' => $q, 'da' => $qda, 'a' => $qa, 'in_corso' => $qa >= $oggi,
                        'scritti' => $scritti, 'parole' => $parole,
                        'min_giorni' => DN_MIN_GIORNI, 'min_parole' => DN_MIN_PAROLE,
                        'idoneo' => $scritti >= DN_MIN_GIORNI && $parole >= DN_MIN_PAROLE,
                        'resoconto' => $resoconti[$q] ?? null];
    }

    return ['year' => $anno, 'today' => $oggi, 'days' => $compatti,
            'anni' => $anni, 'trimestri' => $trimestri, 'libri' => $libri];
}

/** Un mese (month = AAAA-MM gia' controllato): i giorni scritti, le medie, tutto quello che ho imparato. */
function dg_mese(int $uid, string $month): array {
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

    return [
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
    ];
}
