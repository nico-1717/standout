<?php
/* =====================================================================
   NISAMA — API DELLE ATTIVITÀ (TO DO)
   Un solo punto di scrittura per la pagina to-do, per la sezione dentro
   un progetto e per il post-it del pomodoro.

     todo_api.php?ajax=list      (GET)   scope=all|project|pomodoro
     todo_api.php?ajax=save      (POST)  crea o modifica
     todo_api.php?ajax=toggle    (POST)  spunta / despunta
     todo_api.php?ajax=delete    (POST)
     todo_api.php?ajax=bulk      (POST)  azioni su più attività insieme
     todo_api.php?ajax=reorder   (POST)

   Un'attività con una data finisce anche nel planner come task: con
   l'ora diventa un blocco della sua durata, senza ora diventa un evento
   di tutto il giorno. Spuntandola qui si spunta anche là.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'todo', 0, 400, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';

/* ---------------------------------------------------------------------
   Pulizia: le attività spuntate ieri non servono più.
   --------------------------------------------------------------------- */
function td_cleanup(int $uid): void {
    $old = sec_all("SELECT id, planner_event_id FROM todos
                    WHERE user_id = ? AND is_done = 1 AND done_at IS NOT NULL AND DATE(done_at) < CURDATE()",
                    [$uid], 'i');
    foreach ($old as $r) {
        if (!empty($r['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id = ? AND user_id = ?", [(int)$r['planner_event_id'], $uid], 'ii');
        }
    }
    sec_q("DELETE FROM todos WHERE user_id = ? AND is_done = 1 AND done_at IS NOT NULL AND DATE(done_at) < CURDATE()",
          [$uid], 'i');
}

/* ---------------------------------------------------------------------
   L'attività nel planner: creata, aggiornata o tolta secondo la data.
   --------------------------------------------------------------------- */
function td_sync_planner(int $uid, int $todoId): void {
    $t = sec_one("SELECT * FROM todos WHERE id = ? AND user_id = ?", [$todoId, $uid], 'ii');
    if (!$t) return;

    // senza data non c'è niente da mettere in agenda
    if (empty($t['due_date'])) {
        if (!empty($t['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id = ? AND user_id = ?", [(int)$t['planner_event_id'], $uid], 'ii');
            sec_q("UPDATE todos SET planner_event_id = NULL WHERE id = ? AND user_id = ?", [$todoId, $uid], 'ii');
        }
        return;
    }

    $allDay = empty($t['due_time']) ? 1 : 0;
    $start  = $allDay ? '00:00:00' : $t['due_time'];
    $endMin = $allDay ? 1439 : min(1439, (int)substr($t['due_time'], 0, 2) * 60
                                        + (int)substr($t['due_time'], 3, 2)
                                        + max(5, (int)$t['duration_minutes']));
    $end    = $allDay ? '23:59:00' : sprintf('%02d:%02d:00', intdiv($endMin, 60), $endMin % 60);
    $title  = $t['title'] !== '' ? $t['title'] : 'Attività';

    if (!empty($t['planner_event_id'])) {
        $exists = sec_one("SELECT id FROM planner_events WHERE id = ? AND user_id = ?", [(int)$t['planner_event_id'], $uid], 'ii');
        if ($exists) {
            sec_q("UPDATE planner_events SET title=?, event_date=?, start_time=?, end_time=?, is_all_day=?,
                          project_id=?, item_type='task', is_completed=?, completed_at=" . ((int)$t['is_done'] === 1 ? 'NOW()' : 'NULL') . "
                   WHERE id=? AND user_id=?",
                  [$title, $t['due_date'], $start, $end, $allDay,
                   $t['project_id'] !== null ? (int)$t['project_id'] : null,
                   (int)$t['is_done'], (int)$t['planner_event_id'], $uid]);
            return;
        }
    }
    sec_q("INSERT INTO planner_events
           (user_id, title, event_date, start_time, end_time, is_all_day, project_id,
            item_type, is_completed, notify_type, notify_minutes)
           VALUES (?,?,?,?,?,?,?, 'task', ?, 'none', 0)",
          [$uid, $title, $t['due_date'], $start, $end, $allDay,
           $t['project_id'] !== null ? (int)$t['project_id'] : null, (int)$t['is_done']]);
    sec_q("UPDATE todos SET planner_event_id = ? WHERE id = ? AND user_id = ?", [sec_insert_id(), $todoId, $uid], 'iii');
}

function td_out(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'project_id' => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name'  => $r['project_name'] ?? null,
        'project_color' => $r['project_color'] ?? null,
        'title'      => $r['title'],
        'done'       => (int)$r['is_done'] === 1,
        'date'       => $r['due_date'],
        'time'       => $r['due_time'] !== null ? substr($r['due_time'], 0, 5) : null,
        'minutes'    => (int)$r['duration_minutes'],
        'source'     => $r['source'],
        'order'      => (int)$r['sort_order'],
    ];
}

/* ===================== ENDPOINT ===================== */

if ($ajax === 'list') {
    td_cleanup($uid);
    $scope   = sec_enum(sec_in('scope'), ['all', 'project', 'pomodoro'], 'all');
    $projId  = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);

    $sql = "SELECT t.*, p.name AS project_name, p.color AS project_color
            FROM todos t
            LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
            WHERE t.user_id = ?";
    $params = [$uid];
    if ($scope === 'project') {
        if ($projId > 0 && !sec_owns('projects', $projId, $uid)) sec_die_json('Progetto non trovato.', 404, 'not_found');
        $sql .= " AND t.project_id = ?";
        $params[] = $projId;
    } elseif ($scope === 'pomodoro') {
        /* Senza un progetto selezionato il post-it parte davvero vuoto: si
           vedono solo le attività create in questa sessione, non quelle
           rimaste senza progetto da altre volte. Il client passa l'elenco
           degli identificativi creati adesso. */
        $only = sec_in('ids');
        $keep = [];
        if (is_array($only)) {
            foreach (array_slice($only, 0, 100) as $raw) {
                $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
                if ($tid > 0) $keep[] = $tid;
            }
        }
        if (empty($keep)) sec_json(['ok' => true, 'todos' => [], 'today' => date('Y-m-d'), 'now' => date('H:i')]);
        $sql .= " AND t.id IN (" . implode(',', array_map('intval', $keep)) . ")";
    }
    /* Ordine: prima quelle da fare prima. Le attività senza data restano in
       fondo, ma non spariscono: si ordinano tra loro come le hai messe. */
    $sql .= " ORDER BY t.is_done ASC,
                       CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
                       t.due_date ASC,
                       CASE WHEN t.due_time IS NULL THEN 1 ELSE 0 END,
                       t.due_time ASC,
                       t.sort_order ASC, t.id ASC";
    $rows = sec_all($sql, $params);
    sec_json(['ok' => true, 'todos' => array_map('td_out', $rows), 'today' => date('Y-m-d'), 'now' => date('H:i')]);
}

if ($ajax === 'save') {
    $id       = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
    $title    = sec_str(sec_in('title'), 300);
    $parentId = sec_int(sec_in('parent_id'), 0, PHP_INT_MAX, 0);
    $projId   = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
    $date     = sec_date(sec_in('date'), null);
    $time     = sec_in('time') ? sec_time(sec_in('time'), null) : null;
    $minutes  = sec_int(sec_in('minutes'), 1, 1440, 30);
    $source   = sec_enum(sec_in('source'), ['page', 'project', 'pomodoro'], 'page');

    if ($parentId > 0 && !sec_owns('todos', $parentId, $uid)) $parentId = 0;
    if ($projId > 0 && !sec_owns('projects', $projId, $uid)) $projId = 0;

    if ($id > 0) {
        if (!sec_owns('todos', $id, $uid)) sec_die_json('Attività non trovata.', 404, 'not_found');
        if ($title === '') {   // svuotare il titolo equivale a cancellarla
            $t = sec_one("SELECT planner_event_id FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
            if ($t && !empty($t['planner_event_id'])) {
                sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
            }
            sec_q("DELETE FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
            sec_json(['ok' => true, 'deleted' => true, 'id' => $id]);
        }
        sec_q("UPDATE todos SET title=?, project_id=?, due_date=?, due_time=?, duration_minutes=?
               WHERE id=? AND user_id=?",
              [$title, $projId > 0 ? $projId : null, $date, $time, $minutes, $id, $uid]);
        td_sync_planner($uid, $id);
    } else {
        /* la nuova attività può nascere senza titolo: lo scrive l'utente
           subito dopo, e se lascia il campo vuoto viene tolta da sola */
        $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
        sec_q("INSERT INTO todos (user_id, parent_id, project_id, title, due_date, due_time,
                                  duration_minutes, sort_order, source)
               VALUES (?,?,?,?,?,?,?,?,?)",
              [$uid, $parentId > 0 ? $parentId : null, $projId > 0 ? $projId : null, $title,
               $date, $time, $minutes, (int)($next['n'] ?? 1), $source]);
        $id = sec_insert_id();
        td_sync_planner($uid, $id);
    }
    $row = sec_one("SELECT t.*, p.name AS project_name, p.color AS project_color
                    FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
                    WHERE t.id = ? AND t.user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'todo' => $row ? td_out($row) : null]);
}

if ($ajax === 'toggle') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('todos', $id, $uid)) sec_die_json('Attività non trovata.', 404, 'not_found');
    $t = sec_one("SELECT is_done FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    $next = (int)$t['is_done'] === 1 ? 0 : 1;
    sec_q("UPDATE todos SET is_done=?, done_at=" . ($next ? 'NOW()' : 'NULL') . " WHERE id=? AND user_id=?",
          [$next, $id, $uid]);
    /* spuntando una attività si spuntano anche le sue sotto-attività */
    sec_q("UPDATE todos SET is_done=?, done_at=" . ($next ? 'NOW()' : 'NULL') . " WHERE parent_id=? AND user_id=?",
          [$next, $id, $uid]);
    td_sync_planner($uid, $id);
    sec_json(['ok' => true, 'done' => $next === 1]);
}

if ($ajax === 'delete') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('todos', $id, $uid)) sec_die_json('Attività non trovata.', 404, 'not_found');
    $kids = sec_all("SELECT id, planner_event_id FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    foreach ($kids as $k) {
        if (!empty($k['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$k['planner_event_id'], $uid], 'ii');
        }
    }
    sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    sec_json(['ok' => true]);
}

/* Azioni su più attività insieme: stessa data e ora, stesso progetto,
   oppure eliminazione. */
if ($ajax === 'bulk') {
    $ids    = sec_in('ids');
    $action = sec_enum(sec_in('action'), ['schedule', 'project', 'delete'], 'schedule');
    if (!is_array($ids) || empty($ids)) sec_die_json('Nessuna attività scelta.', 422, 'empty');

    $clean = [];
    foreach (array_slice($ids, 0, 200) as $raw) {
        $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
        if ($tid > 0 && sec_owns('todos', $tid, $uid)) $clean[] = $tid;
    }
    if (empty($clean)) sec_die_json('Nessuna attività valida.', 422, 'empty');

    if ($action === 'delete') {
        foreach ($clean as $tid) {
            $t = sec_one("SELECT planner_event_id FROM todos WHERE id=? AND user_id=?", [$tid, $uid], 'ii');
            if ($t && !empty($t['planner_event_id'])) {
                sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
            }
            sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $tid, $tid], 'iii');
        }
        sec_json(['ok' => true, 'count' => count($clean)]);
    }

    if ($action === 'project') {
        $projId = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        if ($projId > 0 && !sec_owns('projects', $projId, $uid)) sec_die_json('Progetto non trovato.', 404, 'not_found');
        foreach ($clean as $tid) {
            sec_q("UPDATE todos SET project_id=? WHERE id=? AND user_id=?",
                  [$projId > 0 ? $projId : null, $tid, $uid]);
            td_sync_planner($uid, $tid);
        }
        sec_json(['ok' => true, 'count' => count($clean)]);
    }

    $date    = sec_date(sec_in('date'), null);
    $time    = sec_in('time') ? sec_time(sec_in('time'), null) : null;
    $minutes = sec_int(sec_in('minutes'), 1, 1440, 30);
    foreach ($clean as $tid) {
        sec_q("UPDATE todos SET due_date=?, due_time=?, duration_minutes=? WHERE id=? AND user_id=?",
              [$date, $time, $minutes, $tid, $uid]);
        td_sync_planner($uid, $tid);
    }
    sec_json(['ok' => true, 'count' => count($clean)]);
}

if ($ajax === 'reorder') {
    $ids = sec_in('ids');
    if (!is_array($ids)) sec_die_json('Ordine non valido.', 422, 'invalid');
    $n = 1;
    foreach (array_slice($ids, 0, 300) as $raw) {
        $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
        if ($tid > 0 && sec_owns('todos', $tid, $uid)) {
            sec_q("UPDATE todos SET sort_order=? WHERE id=? AND user_id=?", [$n++, $tid, $uid], 'iii');
        }
    }
    sec_json(['ok' => true]);
}

/* Il pomodoro, alla fine, chiede cosa fare delle attività del post-it. */
if ($ajax === 'pomodoro_close') {
    $mode = sec_enum(sec_in('mode'), ['done', 'all', 'keep'], 'done');
    $ids  = sec_in('ids');
    if (!is_array($ids)) $ids = [];
    $clean = [];
    foreach (array_slice($ids, 0, 200) as $raw) {
        $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
        if ($tid > 0 && sec_owns('todos', $tid, $uid)) $clean[] = $tid;
    }
    if ($mode === 'keep' || empty($clean)) sec_json(['ok' => true, 'removed' => 0]);

    $removed = 0;
    foreach ($clean as $tid) {
        $t = sec_one("SELECT is_done, planner_event_id FROM todos WHERE id=? AND user_id=?", [$tid, $uid], 'ii');
        if (!$t) continue;
        if ($mode === 'done' && (int)$t['is_done'] !== 1) continue;   // le non spuntate restano
        if (!empty($t['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
        }
        sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $tid, $tid], 'iii');
        $removed++;
    }
    sec_json(['ok' => true, 'removed' => $removed]);
}

sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');