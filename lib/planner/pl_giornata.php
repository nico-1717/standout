<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_giornata.php
   Quello che sta intorno agli eventi in un giorno: pastiglie, priorita', liste della settimana, abitudini, anelli, diario.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

/* --- priorità ------------------------------------------------------- */
/** Le tabelle delle pastiglie possono non esserci: la sezione è opzionale. */
function pl_pills_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'pill_plans'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/**
 * Le prese la cui ora è già passata e su cui nessuno ha ancora detto niente.
 *
 * «Niente» vuol dire status vuoto o 'pending': 'taken' e 'skipped' sono
 * entrambe risposte, e chi ha detto «saltata» non se la deve ritrovare
 * davanti al ricaricamento successivo — è la ragione per cui il tasto
 * «saltato» scrive nel database invece di limitarsi a chiudere il riquadro.
 *
 * Si guardano anche i giorni prima: una pastiglia dimenticata martedì va
 * chiesta oggi, non sepolta in un giorno che non si guarda più.
 */
function pl_pills_due(int $userId, string $oggi, string $oraOra): array {
    if (!pl_pills_c_e()) return [];
    $rows = sec_all(
        "SELECT d.id, d.due_date, d.due_time, p.name, p.dose, p.color
           FROM pill_doses d
           JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
          WHERE d.user_id = ?
            AND (d.status IS NULL OR d.status = '' OR d.status = 'pending')
            AND (d.due_date < ? OR (d.due_date = ? AND d.due_time <= ?))
            AND d.due_date >= DATE_SUB(?, INTERVAL 7 DAY)
          ORDER BY d.due_date ASC, d.due_time ASC
          LIMIT 6",
        [$userId, $oggi, $oggi, $oraOra, $oggi], 'issss'
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'    => (int)$r['id'],
            'name'  => $r['name'],
            'dose'  => (string)($r['dose'] ?? ''),
            'color' => $r['color'],
            'date'  => (string)$r['due_date'],
            'time'  => substr((string)$r['due_time'], 0, 5),
            'oggi'  => (string)$r['due_date'] === $oggi,
        ];
    }
    return $out;
}

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
    /* Priorità automatiche dai progetti con obiettivo o scadenza nel periodo.
       Oltre al testo si manda anche di quale progetto si tratta e le poche
       cose che servono a riassumerlo: quella riga è l'unico punto del planner
       in cui un progetto si affaccia, e senza l'identificativo l'unica cosa
       che si poteva farci era leggerla. */
    /* Priorità automatiche: i progetti con obiettivo o scadenza nel periodo, più
       quelli già scaduti e non completati — ma questi ultimi solo su OGGI.
       Un obiettivo scaduto a marzo non è una cosa da fare il 20 di giugno: è
       una cosa da recuperare adesso, e su ogni giorno futuro diventerebbe una
       riga fissa che sporca la vista di tutti i mesi a venire. */
    $today = date('Y-m-d');
    $proj = sec_all("SELECT id, name, notes, status, color, target_date, due_date FROM projects
                     WHERE user_id = ? AND (
                         (target_date BETWEEN ? AND ?) OR (due_date BETWEEN ? AND ?)
                         OR (status != 'completed' AND target_date < ?)
                         OR (status != 'completed' AND due_date < ?)
                     )",
                     [$userId, $from, $to, $from, $to, $today, $today], 'issssss');

    foreach ($proj as $p) {
        $info = [
            'project_id'  => (int)$p['id'],
            'project'     => $p['name'],
            'notes'       => $p['notes'],
            'stato'       => $p['status'],
            'color'       => $p['color'],
            'target_date' => $p['target_date'],
            'due_date'    => $p['due_date'],
        ];

        // Iteriamo su ogni giorno del range richiesto dal client
        $current = $from;
        while ($current <= $to) {
            $showTarget = false;
            $showDue = false;
            $isOverdueTarget = false;
            $isOverdueDue = false;

            // Logica per l'obiettivo
            if (!empty($p['target_date'])) {
                if ($p['target_date'] === $current) {
                    $showTarget = true;
                } elseif ($p['target_date'] < $current && $p['status'] !== 'completed' && $current === $today) {
                    $showTarget = true;
                    $isOverdueTarget = true;
                }
            }

            // Logica per la scadenza
            if (!empty($p['due_date'])) {
                if ($p['due_date'] === $current) {
                    $showDue = true;
                } elseif ($p['due_date'] < $current && $p['status'] !== 'completed' && $current === $today) {
                    $showDue = true;
                    $isOverdueDue = true;
                }
            }

            /* La scritta dice soltanto di che cosa si tratta: il nome del
               progetto da solo non si distinguerebbe da una priorità scritta
               a mano. Che sia scaduto invece non serve gridarlo, lo dice già
               il bordo rosso della casella — e "OBIETTIVO SCADUTO:" in
               maiuscolo mangiava mezza riga prima ancora di arrivare al nome. */
            if ($showTarget) {
                $out[$current][] = array_merge($info, [
                    'position' => 0, 
                    'content' => t('planner.etichetta_obiettivo', ['nome' => $p['name']]), 
                    'done' => false, 
                    'auto' => true,
                    'overdue' => $isOverdueTarget
                ]);
            }

            if ($showDue) {
                $out[$current][] = array_merge($info, [
                    'position' => 0, 
                    'content' => t('planner.etichetta_scadenza', ['nome' => $p['name']]), 
                    'done' => false, 
                    'auto' => true,
                    'overdue' => $isOverdueDue
                ]);
            }

            $current = pl_add_days($current, 1);
        }
    }
    /* Priorità automatiche: attività scadute da To Do, To Buy, To Study.
       Solo scadute: quelle del giorno che si sta guardando sono già nella
       griglia come eventi, e ripeterle qui sopra vorrebbe dire vedere due
       volte la stessa cosa in mezzo schermo. In priorità arriva solo quello
       che il giorno si è lasciato indietro. */
    $todos = sec_all("SELECT id, title, list_type, due_date FROM todos
                      WHERE user_id = ? AND is_done = 0 AND due_date IS NOT NULL
                        AND due_date < ? AND list_type IN ('todo', 'buy', 'study')",
                      [$userId, $today], 'is');

    foreach ($todos as $t) {
        $info = [
            'todo_id'   => (int)$t['id'],
            'list_type' => $t['list_type'],
        ];

        $current = $from;
        while ($current <= $to) {
            /* SOLO su oggi. Le cose in ritardo appartengono a oggi: è oggi che
               vanno recuperate. Portandosele avanti su tutti i giorni futuri
               si apriva il 15 del mese prossimo e ci si trovava in cima roba
               scaduta il mese prima — un elenco che non riguarda quel giorno,
               e che rende inutile guardare avanti per capire come sarà una
               giornata. Sui giorni passati, come prima, non si tocca niente:
               una cosa scaduta ieri non era scaduta l'altro ieri. */
            if ($current === $today && $t['due_date'] < $current) {
                $out[$current][] = array_merge($info, [
                    'position' => 0,
                    /* Niente "TO DO SCADUTO:" davanti: il rosso della casella
                       dice già che è in ritardo, e l'elenco di provenienza si
                       vede toccandola, che porta proprio su quella linguetta.
                       Prima del titolo restavano tre parole su cinque. */
                    'content' => $t['title'],
                    'done' => false,
                    'auto' => true,
                    'overdue' => true
                ]);
            }
            $current = pl_add_days($current, 1);
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

/**
 * Le abitudini settimanali: quelle con un obiettivo di volte a settimana,
 * come "palestra 4 volte". Non contano per il giorno singolo ma per la
 * settimana intera, e non c'è un tetto: se vai in palestra 5 volte su 4,
 * il conto dice 5 su 4 e va benissimo.
 */
function pl_habits_weekly(int $userId, string $date): array {
    $weekStart = pl_monday($date);
    $weekEnd   = pl_add_days($weekStart, 6);

    $rows = sec_all("SELECT id, name, icon, color, times_per_week, planner_minutes,
                            start_date, end_date, is_archived
                     FROM habits
                     WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
                       AND frequency_type = 'weekly'
                     ORDER BY sort_order, id", [$userId], 'i');
    if (empty($rows)) return [];

    $fatti = sec_all("SELECT habit_id, COUNT(*) AS c FROM habit_logs
                      WHERE user_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
                      GROUP BY habit_id", [$userId, $weekStart, $weekEnd], 'iss');
    $conta = [];
    foreach ($fatti as $f) { $conta[(int)$f['habit_id']] = (int)$f['c']; }

    $out = [];
    foreach ($rows as $h) {
        if ($h['start_date'] > $weekEnd) continue;
        if (!empty($h['end_date']) && $h['end_date'] < $weekStart) continue;
        $obiettivo = max(1, (int)$h['times_per_week']);
        $done = $conta[(int)$h['id']] ?? 0;
        $out[] = [
            'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'],
            'color' => $h['color'],
            'done' => $done, 'target' => $obiettivo,
            'complete' => $done >= $obiettivo,
            'minutes' => $h['planner_minutes'] !== null ? (int)$h['planner_minutes'] : null,
        ];
    }
    return $out;
}

function pl_habits_for_date(int $userId, string $date): array {
    $rows = sec_all("SELECT id, name, icon, color, habit_kind, goal_type, target_amount, frequency_type,
                            weekdays, interval_days, start_date, end_date, is_archived, planner_minutes
                     FROM habits WHERE user_id = ? AND habit_kind = 'build'
                       AND frequency_type <> 'weekly' ORDER BY sort_order, id", [$userId], 'i');
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
            /* A completamento = si spunta e basta, non si conta quanto se n'è
               fatto. Serve al planner per dire, con il fondo del chip, che
               quella di oggi sarebbe una ripetizione: su un'abitudine a
               quantità (tre bicchieri, dieci pagine) rimetterla in agenda è
               invece del tutto normale, e segnalarlo sarebbe un falso allarme. */
            'a_completamento' => ($h['goal_type'] ?? 'completion') === 'completion',
        ];
    }
    return $out;
}

/* Gli anelli concentrici: un cerchio per famiglia di frequenza, e solo per le
   famiglie che hanno almeno un'abitudine.

   Il conto lo fa il server perché è l'unico che ha tutti i registri sotto
   mano: al client arrivano solo le abitudini previste per il giorno mostrato,
   quindi da lì non si potrebbe sapere quante volte una mensile è stata fatta
   nel mese. Restituisce l'ordine giusto, dal ritmo più stretto al più largo:
   il primo dell'elenco è quello che va disegnato più all'interno. */
function pl_rings(int $userId, string $date): array {
    $lunedi   = date('Y-m-d', strtotime($date . ' monday this week'));
    $domenica = date('Y-m-d', strtotime($lunedi . ' +6 days'));
    $mese     = substr($date, 0, 7);

    $habits = sec_all("SELECT id, frequency_type, weekdays, interval_days, start_date, end_date,
                              times_per_week, times_per_month
                       FROM habits
                       WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0", [$userId], 'i');
    if (empty($habits)) return [];

    /* Un solo giro di registri per tutto: giorno, settimana e mese stanno
       dentro l'intervallo del mese allargato alla settimana che lo attraversa. */
    $da = min($lunedi, $mese . '-01');
    $a  = max($domenica, date('Y-m-t', strtotime($date)));
    $logs = sec_all("SELECT habit_id, log_date, status, amount, target_amount
                     FROM habit_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?",
                    [$userId, $da, $a], 'iss');

    $fatto = [];      // habit_id => [data => true]
    foreach ($logs as $l) {
        $ok = $l['status'] === 'done' || (float)$l['amount'] >= (float)$l['target_amount'];
        if ($ok) $fatto[(int)$l['habit_id']][$l['log_date']] = true;
    }

    $conto = [
        'daily'    => ['done' => 0, 'total' => 0, 'label' => t('comune.oggi')],
        'weekly'   => ['done' => 0, 'total' => 0, 'label' => t('planner.settimana')],
        'monthly'  => ['done' => 0, 'total' => 0, 'label' => t('planner.mese')],
        'interval' => ['done' => 0, 'total' => 0, 'label' => t('planner.personalizzate_r')],
    ];

    foreach ($habits as $h) {
        $id   = (int)$h['id'];
        $tipo = $h['frequency_type'];
        $suoi = $fatto[$id] ?? [];

        if ($tipo === 'daily' || $tipo === 'interval') {
            /* contano solo nei giorni in cui sono previste */
            if (!pl_habit_due($h, $date)) continue;
            $conto[$tipo]['total']++;
            if (!empty($suoi[$date])) $conto[$tipo]['done']++;
            continue;
        }

        if ($date < $h['start_date']) continue;
        if (!empty($h['end_date']) && $date > $h['end_date']) continue;

        if ($tipo === 'weekly') {
            $n = 0;
            foreach ($suoi as $d => $_) if ($d >= $lunedi && $d <= $domenica) $n++;
            $obiettivo = max(1, (int)$h['times_per_week']);
            $conto['weekly']['total'] += $obiettivo;
            $conto['weekly']['done']  += min($n, $obiettivo);   // oltre l'obiettivo non gonfia l'anello
        } elseif ($tipo === 'monthly') {
            $n = 0;
            foreach ($suoi as $d => $_) if (substr($d, 0, 7) === $mese) $n++;
            $obiettivo = max(1, (int)$h['times_per_month']);
            $conto['monthly']['total'] += $obiettivo;
            $conto['monthly']['done']  += min($n, $obiettivo);
        }
    }

    /* Dall'interno verso l'esterno, e solo chi ha davvero qualcosa. */
    $out = [];
    foreach (['daily', 'weekly', 'monthly', 'interval'] as $k) {
        if ($conto[$k]['total'] <= 0) continue;
        $out[] = [
            'key'   => $k,
            'label' => $conto[$k]['label'],
            'done'  => (int)$conto[$k]['done'],
            'total' => (int)$conto[$k]['total'],
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
