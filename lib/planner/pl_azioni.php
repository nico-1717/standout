<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_azioni.php
   Funzioni usate dagli endpoint che scrivono: ricorrenze materializzate, coda verso Google, attivita' e abitudini collegate.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

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
        /* Le occorrenze copiano anche luogo e lucchetto: un corso settimanale
           che si tiene sempre nello stesso posto lo perdeva dalla seconda
           volta in poi. */
        /* ogni occorrenza dura quanto il capostipite */
        $giorniOcc = pl_giorni_evento($m);
        $fineOcc = $giorniOcc > 1
            ? date('Y-m-d', strtotime($date . ' +' . ($giorniOcc - 1) . ' day'))
            : null;
        sec_q("INSERT INTO planner_events
               (user_id, title, notes, event_date, end_date, start_time, end_time, is_all_day, category_id, project_id,
                habit_id, importance, color, item_type, notify_type, notify_minutes,
                repeat_type, repeat_interval, repeat_unit, repeat_until, series_id,
                location, place_name, place_lat, place_lon, place_ref, duration_locked)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
               [$userId, $m['title'], $m['notes'], $date, $fineOcc, $m['start_time'], $m['end_time'], (int)$m['is_all_day'],
                $m['category_id'], $m['project_id'], $m['habit_id'], $m['importance'], $m['color'], $m['item_type'],
                $m['notify_type'], (int)$m['notify_minutes'], $m['repeat_type'], (int)$m['repeat_interval'],
                $m['repeat_unit'], $m['repeat_until'], $masterId,
                $m['location'], $m['place_name'], $m['place_lat'], $m['place_lon'], $m['place_ref'],
                (int)($m['duration_locked'] ?? 1)]);
        $count++;
    }
}

/**
 * Mette in coda le cancellazioni da riportare su Google Calendar.
 * Se la tabella non esiste (migrazione non eseguita) non fa nulla:
 * il planner continua a funzionare comunque.
 */
function pl_queue_google_delete(int $userId, string $where, array $params): void {
    /* Si annota anche IN QUALE calendario Google viveva l'evento. Senza, chi
       smaltisce la coda poteva solo tentare il calendario dedicato: per un
       evento che stava altrove — uno importato dai compleanni, o mandato in
       un calendario suo dal verso di ritorno — Google rispondeva 404, la
       riga veniva presa per fatta e l'evento restava là per sempre.
       Con più calendari in gioco quel caso non è più l'eccezione: è la
       norma. */
    $rows = sec_all("SELECT google_event_id, google_calendar_id FROM planner_events
                     WHERE " . $where . " AND google_event_id IS NOT NULL", $params);
    if (empty($rows)) return;
    foreach ($rows as $r) {
        @sec_q("INSERT INTO google_sync_queue (user_id, google_event_id, google_calendar_id, action)
                VALUES (?,?,?,'delete')",
               [$userId, $r['google_event_id'], $r['google_calendar_id']], 'iss');
    }
}

/** Spunta/despunta un'abitudine nelle tabelle di habits (fonte unica). */
/* ----------------------------------------------------------------
   DOVE STA LA VERITÀ: in `todos`.

   Un evento con item_type='task' non è una cosa a sé, è la faccia in
   agenda di una riga di `todos`; il filo che li tiene insieme è la
   colonna `todos.planner_event_id`. Il verso attività → evento esisteva
   già (td_sync_planner, in todo_api.php); questo è il verso che mancava,
   per i task creati direttamente dal planner.

   È questa scelta a togliere di mezzo i doppioni: l'attività nasce GIÀ
   collegata all'evento che l'ha generata, quindi quando verrà risalvata
   dalla pagina attività td_sync_planner troverà il collegamento e
   aggiornerà quell'evento invece di crearne un secondo. Chi scrive
   codice nuovo si regoli così: si scrive nell'attività e l'evento
   segue, oppure si scrive nell'evento e si chiama questa. Mai le due
   cose insieme sullo stesso dato. */
function pl_task_to_todo(int $uid, int $eventId): void {
    $ev = sec_one("SELECT * FROM planner_events WHERE id = ? AND user_id = ?", [$eventId, $uid], 'ii');
    if (!$ev) return;

    /* Abitudini e routine restano fuori. Sono spunte generate da altro,
       non cose scritte a mano: farne attività vorrebbe dire riempire gli
       elenchi di una riga al giorno per ogni abitudine. */
    if (!empty($ev['habit_id']) || !empty($ev['routine_id']) || !empty($ev['routine_group'])) return;

    $collegata = sec_one("SELECT id FROM todos WHERE user_id = ? AND planner_event_id = ? LIMIT 1",
                         [$uid, $eventId], 'ii');

    if (($ev['item_type'] ?? 'event') !== 'task') {
        /* declassato a evento normale: l'attività collegata non si
           cancella — è roba scritta dall'utente — ma smette di essere
           l'ombra di questo evento, se no resterebbero legate per sempre */
        if ($collegata) {
            sec_q("UPDATE todos SET planner_event_id = NULL WHERE id = ? AND user_id = ?",
                  [(int)$collegata['id'], $uid], 'ii');
        }
        return;
    }

    $allDay = (int)$ev['is_all_day'] === 1;
    $ora    = $allDay ? null : $ev['start_time'];
    $durata = max(5, min(1440, pl_minutes($ev['end_time']) - pl_minutes($ev['start_time'])));
    $fatta  = (int)$ev['is_completed'];
    $prog   = $ev['project_id'] !== null ? (int)$ev['project_id'] : null;

    if ($collegata) {
        sec_q("UPDATE todos SET title=?, due_date=?, due_time=?, duration_minutes=?, project_id=?,
                      is_done=?, done_at=" . ($fatta ? 'NOW()' : 'NULL') . "
               WHERE id=? AND user_id=?",
              [$ev['title'], $ev['event_date'], $ora, $durata, $prog, $fatta,
               (int)$collegata['id'], $uid]);
        return;
    }

    $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
    sec_q("INSERT INTO todos (user_id, list_type, title, due_date, due_time, duration_minutes,
                              project_id, is_done, done_at, sort_order, source, planner_event_id)
           VALUES (?, 'todo', ?, ?, ?, ?, ?, ?, " . ($fatta ? 'NOW()' : 'NULL') . ", ?, 'page', ?)",
          [$uid, $ev['title'], $ev['event_date'], $ora, $durata, $prog, $fatta,
           (int)($next['n'] ?? 1), $eventId]);
}

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
