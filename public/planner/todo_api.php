<?php
/* =====================================================================
   STANDOUT — API DELLE ATTIVITÀ (TO DO / TO BUY / TO STUDY)
   Un solo punto di scrittura per la pagina to-do, per la sezione dentro
   un progetto, per il post-it del pomodoro e per il pannello settimana
   del planner.

     todo_api.php?ajax=list      (GET)   scope=all|project|pomodoro
     todo_api.php?ajax=save      (POST)  crea o modifica
     todo_api.php?ajax=toggle    (POST)  spunta / despunta
     todo_api.php?ajax=delete    (POST)
     todo_api.php?ajax=bulk      (POST)  azioni su più attività insieme
     todo_api.php?ajax=reorder   (POST)
     todo_api.php?ajax=cat_save  (POST)  categorie del "to buy"
     todo_api.php?ajax=cat_delete(POST)
     todo_api.php?ajax=image     (POST)  immagine di un articolo (multipart)
     todo_api.php?ajax=image_del (POST)
     todo_api.php?ajax=pills     (GET)   prese di un giorno + arretrate
     todo_api.php?ajax=pill_mark (POST)  presa / saltata, con l'ora a mano
     todo_api.php?ajax=pill_mark_all (POST) più prese insieme: all'orario previsto o adesso
     todo_api.php?ajax=pill_day_set (POST) dal calendario: quante prese di un giorno
     todo_api.php?ajax=pill_history (GET) come è andata una terapia

   Un'attività con una data finisce anche nel planner come task: con
   l'ora diventa un blocco della sua durata, senza ora diventa un evento
   di tutto il giorno. Spuntandola qui si spunta anche là. Vale per tutti
   e tre gli elenchi: anche un acquisto o uno studio con una data devono
   comparire in agenda, altrimenti la data non servirebbe a niente.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'todo', 0, 400, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';

/* I tre elenchi sono la stessa tabella con una colonna in più: così una
   voce può passare da un elenco all'altro senza spostare righe, e tutto
   quello che già funzionava (sotto-attività, planner, pomodoro) continua
   a funzionare senza duplicare il codice. */
const TD_TIPI = ['todo', 'buy', 'study'];

/* Cartella delle immagini degli articoli. Sta fuori dal database perché un
   file grande dentro una riga rallenta ogni lettura dell'elenco. */
function td_img_dir(): string { return STANDOUT_DATA_DIR . 'uploads/tobuy'; }

/* ---------------------------------------------------------------------
   Pulizia: le attività spuntate ieri non servono più.
   L'immagine dell'articolo va tolta insieme alla riga, altrimenti la
   cartella cresce per sempre con file che non è più possibile collegare
   a niente.
   --------------------------------------------------------------------- */
function td_cleanup(int $uid): void {
    $old = sec_all("SELECT id, planner_event_id, buy_image FROM todos
                    WHERE user_id = ? AND is_done = 1 AND done_at IS NOT NULL AND DATE(done_at) < CURDATE()",
                    [$uid], 'i');
    foreach ($old as $r) {
        if (!empty($r['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id = ? AND user_id = ?", [(int)$r['planner_event_id'], $uid], 'ii');
        }
        td_drop_image($r['buy_image'] ?? null);
    }
    sec_q("DELETE FROM todos WHERE user_id = ? AND is_done = 1 AND done_at IS NOT NULL AND DATE(done_at) < CURDATE()",
          [$uid], 'i');
}

/* Cancella il file di un'immagine. Il nome arriva dal database, ma viene
   comunque ripulito: se per qualsiasi motivo contenesse un percorso, non
   deve poter cancellare niente fuori dalla cartella delle immagini. */
function td_drop_image(?string $name): void {
    if ($name === null || $name === '') return;
    $base = basename($name);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) return;
    $path = td_img_dir() . '/' . $base;
    if (is_file($path)) @unlink($path);
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
    $title  = $t['title'] !== '' ? $t['title'] : t('todo.attivita_senza_titolo');

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
        'list_type'  => $r['list_type'] ?? 'todo',
        'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'project_id' => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name'  => $r['project_name'] ?? null,
        'project_color' => $r['project_color'] ?? null,
        'title'      => $r['title'],
        'done'       => (int)$r['is_done'] === 1,
        /* Il blocco che questa attività ha sul calendario. Serve al planner
           per sbarrarne il titolo appena si spunta, senza aspettare il giro
           di rilettura: senza questo riferimento non sapeva quale blocco
           toccare e restava scritto normale finché non si ricaricava. */
        'event_id'   => !empty($r['planner_event_id']) ? (int)$r['planner_event_id'] : null,
        'date'       => $r['due_date'],
        'time'       => $r['due_time'] !== null ? substr($r['due_time'], 0, 5) : null,
        'minutes'    => (int)$r['duration_minutes'],
        'source'     => $r['source'],
        'order'      => (int)$r['sort_order'],
        /* campi del "to buy": arrivano sempre, anche negli altri elenchi,
           perché una voce può essere spostata da un elenco all'altro e il
           client non deve andare a rileggerla per sapere cosa contiene */
        'cat_id'     => $r['buy_category_id'] !== null ? (int)$r['buy_category_id'] : null,
        'priority'   => (int)$r['buy_priority'],
        'cents'      => $r['buy_price_cents'] !== null ? (int)$r['buy_price_cents'] : null,
        'url'        => $r['buy_url'],
        'image'      => $r['buy_image'],
    ];
}

/* ---------------------------------------------------------------------
   Categorie del "to buy".

   Le tre di partenza si creano al primo accesso alla scheda, non con una
   migrazione: chi le cancella non se le deve ritrovare al prossimo
   aggiornamento. Per capire se è davvero il primo accesso non basta
   "non ha categorie" — chi le cancella tutte si ritroverebbe da capo —
   quindi si guarda anche se ha già degli articoli: se ne ha, la scheda
   l'ha già usata e la sua scelta va rispettata.
   --------------------------------------------------------------------- */
function td_seed_categories(int $uid): void {
    $n = sec_one("SELECT COUNT(*) AS n FROM todo_buy_categories WHERE user_id = ?", [$uid], 'i');
    if ((int)($n['n'] ?? 0) > 0) return;
    $b = sec_one("SELECT COUNT(*) AS n FROM todos WHERE user_id = ? AND list_type = 'buy'", [$uid], 'i');
    if ((int)($b['n'] ?? 0) > 0) return;

    $base = [['vestiti', '#C6B6A6'], ['casa', '#A9C3AE'], ['beauty', '#D9B7C0']];
    $i = 1;
    foreach ($base as $c) {
        sec_q("INSERT IGNORE INTO todo_buy_categories (user_id, name, color, sort_order) VALUES (?,?,?,?)",
              [$uid, $c[0], $c[1], $i++], 'issi');
    }
}

function td_categories(int $uid): array {
    $rows = sec_all("SELECT id, name, color, sort_order FROM todo_buy_categories
                     WHERE user_id = ? ORDER BY sort_order, id", [$uid], 'i');
    return array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color'], 'order' => (int)$r['sort_order']];
    }, $rows);
}

/* Il prezzo si scrive come viene comodo — "12,90", "12.9", "€ 12" — e
   diventa un intero di centesimi: i totali per categoria devono tornare al
   centesimo, e con i decimali si accumulano errori di arrotondamento. */
function td_price_cents($raw): ?int {
    $s = trim((string)sec_str($raw, 20));
    if ($s === '') return null;
    $s = str_replace([' ', "\xc2\xa0", '€'], '', $s);
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,7}(\.\d{0,2})?$/', $s)) return null;
    return (int)round(((float)$s) * 100);
}

/* L'indirizzo dell'articolo. Si accettano solo http e https: un `javascript:`
   messo qui diventerebbe codice eseguito al tocco del link. */
function td_url($raw): ?string {
    $u = trim((string)sec_str($raw, 2048));
    if ($u === '') return null;
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    if (!filter_var($u, FILTER_VALIDATE_URL)) return null;
    return mb_substr($u, 0, 2048);
}

/* ===================== ENDPOINT ===================== */

if ($ajax === 'list') {
    td_cleanup($uid);
    $scope   = sec_enum(sec_in('scope'), ['all', 'project', 'pomodoro'], 'all');
    $projId  = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
    /* dentro un progetto e nel pomodoro esiste solo il to do: gli acquisti
       non hanno senso in quei contesti e mescolarli confonderebbe l'elenco */
    $type    = ($scope === 'all') ? sec_enum(sec_in('list_type'), TD_TIPI, 'todo') : 'todo';

    $sql = "SELECT t.*, p.name AS project_name, p.color AS project_color
            FROM todos t
            LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
            WHERE t.user_id = ? AND t.list_type = ?";
    $params = [$uid, $type];
    if ($scope === 'project') {
        if ($projId > 0 && !sec_owns('projects', $projId, $uid)) sec_die_json(t('api.progetto_non_trovato'), 404, 'not_found');
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

    if ($type === 'buy') {
        /* Nel "to buy" l'ordine che conta è categoria, poi priorità: è così
           che la pagina raggruppa, e farlo ordinare al database evita di
           rimescolare tutto nel browser a ogni lettura. */
        $sql .= " ORDER BY t.is_done ASC,
                           CASE WHEN t.buy_category_id IS NULL THEN 1 ELSE 0 END,
                           t.buy_category_id ASC,
                           t.buy_priority ASC,
                           t.sort_order ASC, t.id ASC";
    } else {
        /* Ordine: prima quelle da fare prima. Le attività senza data restano in
           fondo, ma non spariscono: si ordinano tra loro come le hai messe. */
        $sql .= " ORDER BY t.is_done ASC,
                           CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
                           t.due_date ASC,
                           CASE WHEN t.due_time IS NULL THEN 1 ELSE 0 END,
                           t.due_time ASC,
                           t.sort_order ASC, t.id ASC";
    }
    $rows = sec_all($sql, $params);

    $out = ['ok' => true, 'list_type' => $type, 'todos' => array_map('td_out', $rows),
            'today' => date('Y-m-d'), 'now' => date('H:i')];
    if ($type === 'buy' && $scope === 'all') {
        td_seed_categories($uid);
        $out['categories'] = td_categories($uid);
    }
    sec_json($out);
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
    $type     = sec_enum(sec_in('list_type'), TD_TIPI, 'todo');

    if ($parentId > 0 && !sec_owns('todos', $parentId, $uid)) $parentId = 0;
    if ($projId > 0 && !sec_owns('projects', $projId, $uid)) $projId = 0;

    /* I campi del "to buy" si toccano solo se il client li nomina in
       `buy_set`: un salvataggio del titolo non deve azzerare prezzo e
       categoria. L'elenco esplicito serve perché un campo assente e un campo
       svuotato arrivano uguali — e svuotare il prezzo è un'operazione vera. */
    $setti    = sec_in('buy_set');
    if (!is_array($setti)) $setti = [];
    $hasCat   = in_array('cat_id', $setti, true);
    $hasPrio  = in_array('priority', $setti, true);
    $hasPrice = in_array('price', $setti, true);
    $hasUrl   = in_array('url', $setti, true);

    $catId = sec_int(sec_in('cat_id'), 0, PHP_INT_MAX, 0);
    if ($catId > 0 && !sec_owns('todo_buy_categories', $catId, $uid)) $catId = 0;
    $prio  = sec_int(sec_in('priority'), 1, 3, 3);   /* di partenza l'ultima: si alza a mano quello che conta */
    $cents = td_price_cents(sec_in('price'));
    $url   = td_url(sec_in('url'));

    if ($id > 0) {
        if (!sec_owns('todos', $id, $uid)) sec_die_json(t('api.attivita_non_trovata'), 404, 'not_found');
        if ($title === '') {   // svuotare il titolo equivale a cancellarla
            $t = sec_one("SELECT planner_event_id, buy_image FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
            if ($t && !empty($t['planner_event_id'])) {
                sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
            }
            if ($t) td_drop_image($t['buy_image'] ?? null);
            sec_q("DELETE FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
            sec_json(['ok' => true, 'deleted' => true, 'id' => $id]);
        }
        sec_q("UPDATE todos SET title=?, project_id=?, due_date=?, due_time=?, duration_minutes=?, list_type=?
               WHERE id=? AND user_id=?",
              [$title, $projId > 0 ? $projId : null, $date, $time, $minutes, $type, $id, $uid]);
        if ($hasCat)   sec_q("UPDATE todos SET buy_category_id=? WHERE id=? AND user_id=?", [$catId > 0 ? $catId : null, $id, $uid], 'iii');
        if ($hasPrio)  sec_q("UPDATE todos SET buy_priority=? WHERE id=? AND user_id=?", [$prio, $id, $uid], 'iii');
        if ($hasPrice) sec_q("UPDATE todos SET buy_price_cents=? WHERE id=? AND user_id=?", [$cents, $id, $uid]);
        if ($hasUrl)   sec_q("UPDATE todos SET buy_url=? WHERE id=? AND user_id=?", [$url, $id, $uid]);
        td_sync_planner($uid, $id);
    } else {
        /* la nuova attività può nascere senza titolo: lo scrive l'utente
           subito dopo, e se lascia il campo vuoto viene tolta da sola */
        $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
        sec_q("INSERT INTO todos (user_id, list_type, parent_id, project_id, title, due_date, due_time,
                                  duration_minutes, sort_order, source,
                                  buy_category_id, buy_priority, buy_price_cents, buy_url)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$uid, $type, $parentId > 0 ? $parentId : null, $projId > 0 ? $projId : null, $title,
               $date, $time, $minutes, (int)($next['n'] ?? 1), $source,
               $catId > 0 ? $catId : null, $prio, $cents, $url]);
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
    if (!sec_owns('todos', $id, $uid)) sec_die_json(t('api.attivita_non_trovata'), 404, 'not_found');
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
    if (!sec_owns('todos', $id, $uid)) sec_die_json(t('api.attivita_non_trovata'), 404, 'not_found');
    $kids = sec_all("SELECT id, planner_event_id, buy_image FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    foreach ($kids as $k) {
        if (!empty($k['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$k['planner_event_id'], $uid], 'ii');
        }
        td_drop_image($k['buy_image'] ?? null);
    }
    sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    sec_json(['ok' => true]);
}

/* Azioni su più attività insieme: stessa data e ora, stesso progetto,
   stessa categoria e priorità, oppure eliminazione. */
if ($ajax === 'bulk') {
    $ids    = sec_in('ids');
    $action = sec_enum(sec_in('action'), ['schedule', 'project', 'buy', 'move', 'delete'], 'schedule');
    if (!is_array($ids) || empty($ids)) sec_die_json(t('api.nessuna_attivita_scelta'), 422, 'empty');

    $clean = [];
    foreach (array_slice($ids, 0, 200) as $raw) {
        $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
        if ($tid > 0 && sec_owns('todos', $tid, $uid)) $clean[] = $tid;
    }
    if (empty($clean)) sec_die_json(t('api.nessuna_attivita_valida'), 422, 'empty');

    if ($action === 'delete') {
        foreach ($clean as $tid) {
            $t = sec_one("SELECT planner_event_id, buy_image FROM todos WHERE id=? AND user_id=?", [$tid, $uid], 'ii');
            if ($t && !empty($t['planner_event_id'])) {
                sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
            }
            if ($t) td_drop_image($t['buy_image'] ?? null);
            sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $tid, $tid], 'iii');
        }
        sec_json(['ok' => true, 'count' => count($clean)]);
    }

    if ($action === 'project') {
        $projId = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        if ($projId > 0 && !sec_owns('projects', $projId, $uid)) sec_die_json(t('api.progetto_non_trovato'), 404, 'not_found');
        foreach ($clean as $tid) {
            sec_q("UPDATE todos SET project_id=? WHERE id=? AND user_id=?",
                  [$projId > 0 ? $projId : null, $tid, $uid]);
            td_sync_planner($uid, $tid);
        }
        sec_json(['ok' => true, 'count' => count($clean)]);
    }

    /* categoria e priorità a tutti gli articoli scelti, in un colpo solo:
       è il motivo per cui esiste la modalità seleziona nel "to buy" */
    if ($action === 'buy') {
        $setti = sec_in('buy_set');
        if (!is_array($setti)) $setti = [];
        $hasCat  = in_array('cat_id', $setti, true);
        $hasPrio = in_array('priority', $setti, true);
        $catId = sec_int(sec_in('cat_id'), 0, PHP_INT_MAX, 0);
        if ($catId > 0 && !sec_owns('todo_buy_categories', $catId, $uid)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
        $prio  = sec_int(sec_in('priority'), 1, 3, 3);   /* di partenza l'ultima: si alza a mano quello che conta */
        foreach ($clean as $tid) {
            if ($hasCat)  sec_q("UPDATE todos SET buy_category_id=? WHERE id=? AND user_id=?", [$catId > 0 ? $catId : null, $tid, $uid], 'iii');
            if ($hasPrio) sec_q("UPDATE todos SET buy_priority=? WHERE id=? AND user_id=?", [$prio, $tid, $uid], 'iii');
        }
        sec_json(['ok' => true, 'count' => count($clean)]);
    }

    /* spostare voci da un elenco all'altro */
    if ($action === 'move') {
        $type = sec_enum(sec_in('list_type'), TD_TIPI, 'todo');
        foreach ($clean as $tid) {
            sec_q("UPDATE todos SET list_type=? WHERE user_id=? AND (id=? OR parent_id=?)", [$type, $uid, $tid, $tid], 'siii');
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
    if (!is_array($ids)) sec_die_json(t('api.ordine_non_valido'), 422, 'invalid');
    $n = 1;
    foreach (array_slice($ids, 0, 300) as $raw) {
        $tid = sec_int($raw, 1, PHP_INT_MAX, 0);
        if ($tid > 0 && sec_owns('todos', $tid, $uid)) {
            sec_q("UPDATE todos SET sort_order=? WHERE id=? AND user_id=?", [$n++, $tid, $uid], 'iii');
        }
    }
    sec_json(['ok' => true]);
}

/* ---------------- categorie del "to buy" ---------------- */

if ($ajax === 'cat_save') {
    $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
    $name  = sec_str(sec_in('name'), 60);
    $color = sec_color(sec_in('color'));
    if ($name === '') sec_die_json(t('api.serve_nome_categoria'), 422, 'invalid');
    if ($id > 0) {
        if (!sec_owns('todo_buy_categories', $id, $uid)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
        sec_q("UPDATE todo_buy_categories SET name=?, color=? WHERE id=? AND user_id=?", [$name, $color, $id, $uid], 'ssii');
    } else {
        /* il nome è unico per utente: se esiste già si tiene quella, invece
           di rispondere con un errore che l'utente non saprebbe risolvere */
        $ex = sec_one("SELECT id FROM todo_buy_categories WHERE user_id=? AND name=?", [$uid, $name], 'is');
        if ($ex) {
            $id = (int)$ex['id'];
            sec_q("UPDATE todo_buy_categories SET color=? WHERE id=? AND user_id=?", [$color, $id, $uid], 'sii');
        } else {
            sec_q("INSERT INTO todo_buy_categories (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM todo_buy_categories WHERE user_id=?) x))",
                  [$uid, $name, $color, $uid], 'issi');
            $id = sec_insert_id();
        }
    }
    sec_json(['ok' => true, 'id' => $id, 'categories' => td_categories($uid)]);
}

if ($ajax === 'cat_delete') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('todo_buy_categories', $id, $uid)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
    /* gli articoli non si cancellano insieme alla categoria: restano, senza
       categoria, nel gruppo "senza categoria". Cancellare la spesa di uno
       perché si è cambiata idea sulle etichette sarebbe una sorpresa. */
    sec_q("UPDATE todos SET buy_category_id=NULL WHERE user_id=? AND buy_category_id=?", [$uid, $id], 'ii');
    sec_q("DELETE FROM todo_buy_categories WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'categories' => td_categories($uid)]);
}

/* ---------------- immagine di un articolo ----------------
   Il tipo si legge dal contenuto con finfo, non dall'estensione dichiarata,
   che è banale da falsificare; il nome del file lo genera il server, così
   non si possono costruire percorsi come ../../qualcosa.php. */

if ($ajax === 'image') {
    $id = sec_int($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if (!sec_owns('todos', $id, $uid)) sec_die_json(t('api.attivita_non_trovata'), 404, 'not_found');
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        sec_die_json(t('api.immagine_non_ricevuta'), 422, 'invalid');
    }
    $tmp = $_FILES['file']['tmp_name'];
    if (!is_uploaded_file($tmp)) sec_die_json(t('api.immagine_non_valida'), 422, 'invalid');
    if (filesize($tmp) > 4 * 1024 * 1024) sec_die_json(t('api.immagine_troppo_grande_4'), 422, 'too_big');

    $ammessi = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $tmp);
        finfo_close($fi);
    }
    if (!isset($ammessi[$mime])) sec_die_json(t('api.servono_immagini'), 422, 'bad_type');
    /* doppia verifica: un file può dichiarare un tipo immagine e non essere
       leggibile come tale */
    $dim = @getimagesize($tmp);
    if ($dim === false) sec_die_json(t('api.immagine_non_leggibile'), 422, 'bad_type');

    $dir = td_img_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) sec_die_json(t('api.cartella_non_scrivibile'), 500, 'server');
    /* la cartella non deve poter eseguire niente: se un giorno ci finisse
       dentro un file interpretabile, resta un file e basta */
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "php_flag engine off\nOptions -ExecCGI\nAddType text/plain .php .phtml .phar\n");
    }

    $nome = 'b' . $uid . '_' . bin2hex(random_bytes(10)) . '.' . $ammessi[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $nome)) sec_die_json(t('api.immagine_non_salvata'), 500, 'server');
    @chmod($dir . '/' . $nome, 0644);

    // la vecchia immagine non serve più: una riga, una immagine
    $old = sec_one("SELECT buy_image FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    if ($old) td_drop_image($old['buy_image'] ?? null);

    sec_q("UPDATE todos SET buy_image=? WHERE id=? AND user_id=?", [$nome, $id, $uid], 'sii');
    sec_json(['ok' => true, 'image' => $nome]);
}

if ($ajax === 'image_del') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('todos', $id, $uid)) sec_die_json(t('api.attivita_non_trovata'), 404, 'not_found');
    $t = sec_one("SELECT buy_image FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    if ($t) td_drop_image($t['buy_image'] ?? null);
    sec_q("UPDATE todos SET buy_image=NULL WHERE id=? AND user_id=?", [$id, $uid], 'ii');
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

/* =====================================================================
   PASTIGLIE E INTEGRATORI

   Una *terapia* dice cosa prendere e con che ritmo; una *presa* è il singolo
   appuntamento (quello delle 10:00 di oggi) con la sua spunta. Le prese
   nascono il giorno in cui servono, non un anno prima: così cambiare il
   ritmo non riscrive il passato e non lascia in giro appuntamenti di una
   terapia che nel frattempo è finita.

   La tabella è facoltativa come le altre aggiunte recenti: se la migrazione
   non è stata eseguita, la sezione risponde vuota e il resto delle attività
   continua a funzionare.
   ===================================================================== */
function td_pills_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'pill_plans'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/** "10:00x2,20:00x1" → [['08:00', 2], ...]. Scritto male, si torna a una
    presa alle 08:00: meglio una terapia addosso che una sezione vuota. */
function td_pill_times(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $pezzo) {
        $pezzo = trim($pezzo);
        if ($pezzo === '') continue;
        $p = explode('x', $pezzo);
        $ora = sec_time($p[0] ?? '', '');
        if ($ora === '') continue;
        $n = isset($p[1]) ? (int)$p[1] : 1;
        $out[] = [substr($ora, 0, 5) . ':00', max(1, min(9, $n))];
    }
    if (!$out) $out[] = ['08:00:00', 1];
    /* in ordine di orologio: l'elenco del giorno si legge dall'alto in basso */
    usort($out, function ($a, $b) { return strcmp($a[0], $b[0]); });
    return $out;
}

/** La terapia tocca quel giorno? */
function td_pill_due(array $p, string $date): bool {
    if ($date < $p['start_date']) return false;
    if (!empty($p['end_date']) && $date > $p['end_date']) return false;

    if ($p['freq_type'] === 'monthly') {
        $g = (int)substr($date, 8, 2);
        foreach (explode(',', (string)$p['month_days']) as $d) {
            if ((int)trim($d) === $g) return true;
        }
        /* il 31 in un mese di 30 giorni non esiste: la presa slitta all'ultimo
           giorno, invece di saltare il mese senza dire niente */
        $ultimo = (int)date('t', strtotime($date));
        if ($g === $ultimo) {
            foreach (explode(',', (string)$p['month_days']) as $d) {
                if ((int)trim($d) > $ultimo) return true;
            }
        }
        return false;
    }

    if ($p['freq_type'] === 'interval') {
        $n = max(1, (int)$p['interval_days']);
        $giorni = (int)floor((strtotime($date) - strtotime($p['start_date'])) / 86400);
        return $giorni >= 0 && ($giorni % $n) === 0;
    }

    /* daily: la maschera dei giorni della settimana, lunedì in prima
       posizione come nelle abitudini (date('N') dà 1 per lunedì) */
    $w = str_pad((string)$p['weekdays'], 7, '1');
    $i = (int)date('N', strtotime($date)) - 1;
    return substr($w, $i, 1) === '1';
}

/** Scrive le prese mancanti di uno o più giorni. Ripetibile senza danni: la
    chiave doppia della tabella fa cadere i doppioni invece di crearli.

    Le terapie si leggono una volta sola anche quando i giorni sono sette:
    sono le stesse per tutti, e rileggerle a ogni giro sarebbe sette volte la
    stessa domanda al database. */
function td_pills_ensure_giorni(int $uid, array $dates): void {
    if (!td_pills_c_e() || !$dates) return;
    $plans = sec_all("SELECT * FROM pill_plans WHERE user_id = ? AND is_active = 1", [$uid], 'i');
    if (!$plans) return;
    foreach ($plans as $p) {
        $ore = td_pill_times((string)$p['times']);
        /* IL BUG DELLE PRESE CHE RITORNAVANO.
           Cambiando l'orario di una terapia da 10:00 a 12:00, tutte le prese
           già spuntate tornavano a galla come "rimaste indietro". Il motivo:
           qui si rigenerano i sette giorni scorsi, e li si rigenerava con gli
           orari di ADESSO. La presa delle 10:00 di ieri restava spuntata dov'era,
           ma ne nasceva una nuova alle 12:00, in sospeso, per un giorno in cui
           quell'orario non è mai esistito.
           Da qui in poi ogni terapia porta la data da cui vale l'orario
           corrente: prima di quella, le prese non si toccano più, perché
           appartengono a un ritmo che non c'è più. */
        $valeDa = !empty($p['schedule_from']) ? (string)$p['schedule_from'] : (string)$p['start_date'];
        foreach ($dates as $date) {
            if ($valeDa !== '' && $date < $valeDa) continue;
            if (!td_pill_due($p, $date)) continue;
            foreach ($ore as $t) {
                for ($seq = 1; $seq <= $t[1]; $seq++) {
                    sec_q("INSERT IGNORE INTO pill_doses (user_id, plan_id, due_date, due_time, seq)
                           VALUES (?, ?, ?, ?, ?)",
                          [$uid, (int)$p['id'], $date, $t[0], $seq], 'iissi');
                }
            }
        }
    }
}

function td_pills_ensure(int $uid, string $date): void {
    td_pills_ensure_giorni($uid, [$date]);
}

/** Le prese rimaste in sospeso nei giorni scorsi.

    Prima sparivano insieme al loro giorno, ed è esattamente il contrario di
    quello che serve: una pastiglia dimenticata è la cosa che deve tornare a
    galla, non quella che deve sparire. Quattordici giorni indietro e non di
    più — oltre non ci si ricorda davvero se la si è presa, e spuntarla a
    caso è peggio che lasciarla in bianco.

    Si generano anche le prese dei giorni scorsi che nessuno ha ancora
    scritto: chi non ha aperto l'app per una settimana, se no, non avrebbe
    niente da recuperare proprio nel caso in cui serve. */
function td_pills_arretrate(int $uid): array {
    if (!td_pills_c_e()) return [];
    $oggi = date('Y-m-d');
    $giorni = [];
    for ($i = 1; $i <= 7; $i++) $giorni[] = date('Y-m-d', strtotime($oggi . ' -' . $i . ' day'));
    td_pills_ensure_giorni($uid, $giorni);

    $da = date('Y-m-d', strtotime($oggi . ' -14 day'));
    $rows = sec_all("SELECT d.id, d.plan_id, d.due_date, d.due_time, d.seq, d.status,
                            p.name, p.dose, p.color, p.notes
                     FROM pill_doses d
                     JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
                     WHERE d.user_id = ? AND d.status = 'pending'
                       AND d.due_date < ? AND d.due_date >= ?
                     ORDER BY d.due_date DESC, d.due_time", [$uid, $oggi, $da], 'iss');
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'id'      => (int)$d['id'],
            'plan_id' => (int)$d['plan_id'],
            'date'    => $d['due_date'],
            'time'    => substr((string)$d['due_time'], 0, 5),
            'seq'     => (int)$d['seq'],
            'status'  => 'pending',
            'name'    => $d['name'],
            'dose'    => $d['dose'],
            'notes'   => $d['notes'],
            'color'   => $d['color'],
        ];
    }
    return $out;
}

function td_pill_plans(int $uid): array {
    if (!td_pills_c_e()) return [];
    $rows = sec_all("SELECT * FROM pill_plans WHERE user_id = ? ORDER BY is_active DESC, sort_order, id", [$uid], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'dose'      => $r['dose'],
            'notes'     => $r['notes'],
            'color'     => $r['color'],
            'freq_type' => $r['freq_type'],
            'weekdays'  => $r['weekdays'],
            'month_days'=> $r['month_days'],
            'interval_days' => (int)$r['interval_days'],
            'times'     => $r['times'],
            'start_date'=> $r['start_date'],
            'end_date'  => $r['end_date'],
            'active'    => (int)$r['is_active'] === 1,
        ];
    }
    return $out;
}

/* ---------------- elenco del giorno ---------------- */
if ($ajax === 'pills') {
    if (!td_pills_c_e()) sec_json(['ok' => true, 'plans' => [], 'doses' => [], 'installed' => false]);
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    td_pills_ensure($uid, $date);
    $doses = sec_all("SELECT d.id, d.plan_id, d.due_time, d.seq, d.status, d.taken_at,
                             p.name, p.dose, p.color, p.notes
                      FROM pill_doses d
                      JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
                      WHERE d.user_id = ? AND d.due_date = ?
                      ORDER BY d.due_time, d.seq", [$uid, $date], 'is');
    $out = [];
    foreach ($doses as $d) {
        $out[] = [
            'id'     => (int)$d['id'],
            'plan_id'=> (int)$d['plan_id'],
            'time'   => substr((string)$d['due_time'], 0, 5),
            'seq'    => (int)$d['seq'],
            'status' => $d['status'],
            'taken_at' => $d['taken_at'],
            'name'   => $d['name'],
            'dose'   => $d['dose'],
            'notes'  => $d['notes'],
            'color'  => $d['color'],
        ];
    }
    /* Quante ne sono state saltate negli ultimi trenta giorni: è il numero per
       cui vale la pena tenere le prese una per una. */
    $st = sec_one("SELECT SUM(status='taken') AS prese, SUM(status='skipped') AS saltate,
                          SUM(status='pending' AND due_date < CURDATE()) AS dimenticate
                   FROM pill_doses WHERE user_id = ? AND due_date > (CURDATE() - INTERVAL 30 DAY)", [$uid], 'i');
    sec_json(['ok' => true, 'installed' => true, 'date' => $date,
              'plans' => td_pill_plans($uid), 'doses' => $out,
              'late' => td_pills_arretrate($uid),
              'stats' => ['taken' => (int)($st['prese'] ?? 0),
                          'skipped' => (int)($st['saltate'] ?? 0),
                          'missed' => (int)($st['dimenticate'] ?? 0)]]);
}

/* ---------------- spunta di una presa ---------------- */
if ($ajax === 'pill_mark') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('pill_doses', $id, $uid)) sec_die_json(t('api.presa_non_trovata'), 404, 'not_found');
    $st = sec_enum(sec_in('status'), ['pending', 'taken', 'skipped'], 'taken');

    /* L'ora vera della spunta, non quella prevista: è la differenza fra le due
       che dice se la terapia sta reggendo davvero.

       Ora si può anche dettarla. Il caso che conta è quello di tutti i
       giorni: la pastiglia la si prende la mattina e la si spunta il
       pomeriggio, e senza poterla correggere il registro direbbe che è stata
       presa con sei ore di ritardo. Sulle arretrate serve anche il giorno,
       perché quella presa appartiene a ieri.
       Se non arriva niente vale l'orologio, come prima. */
    $atD = sec_date(sec_in('at_date'), '');
    $atT = sec_time(sec_in('at_time'), '');
    $quando = null;
    if ($st === 'taken') {
        $quando = ($atD !== '' && $atT !== '')
            ? $atD . ' ' . substr($atT, 0, 5) . ':00'
            : date('Y-m-d H:i:s');
    }
    sec_q("UPDATE pill_doses SET status = ?, taken_at = ? WHERE id = ? AND user_id = ?",
          [$st, $quando, $id, $uid], 'ssii');
    sec_json(['ok' => true, 'taken_at' => $quando]);
}

/* ---------------- il calendario: quante prese in un giorno ----------------
   Toccando un giorno del calendario di una terapia si dice quante se ne sono
   prese (0 = nessuna, cioè togliere). Le prese del giorno si spuntano in
   ordine di orario: le prime n prese, le altre tornano in sospeso.
   Una presa già spuntata tiene la sua ora vera; una nuova prende l'ora in
   cui era prevista (mai nel futuro). Una saltata resta saltata, a meno che
   non serva per arrivare a n. */
if ($ajax === 'pill_day_set') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $pid = sec_int(sec_in('plan_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('pill_plans', $pid, $uid)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    $giorno = sec_date(sec_in('date'), '');
    if ($giorno === '') sec_die_json(t('api.data_non_valida'), 400, 'bad_date');
    if ($giorno > date('Y-m-d')) sec_die_json(t('api.data_futura'), 400, 'future');
    $n = sec_int(sec_in('taken'), 0, 50, 0);

    td_pills_ensure_giorni($uid, [$giorno]);
    $prese = sec_all("SELECT id, status, due_time FROM pill_doses
                      WHERE user_id = ? AND plan_id = ? AND due_date = ?
                      ORDER BY due_time, seq", [$uid, $pid, $giorno], 'iis');
    if (!$prese) sec_die_json(t('api.nessuna_presa_quel_giorno'), 400, 'no_doses');

    $n = min($n, count($prese));
    $adesso = date('Y-m-d H:i:s');
    /* quelle già prese contano per prime: se ne erano state spuntate due e
       se ne chiedono due, non cambia niente */
    $gia = array_values(array_filter($prese, function ($d) { return $d['status'] === 'taken'; }));
    $altre = array_values(array_filter($prese, function ($d) { return $d['status'] !== 'taken'; }));
    usort($altre, function ($a, $b) { return ($a['status'] === 'skipped') <=> ($b['status'] === 'skipped'); });
    $togliere = array_slice($gia, $n);
    $aggiungere = array_slice($altre, 0, max(0, $n - count($gia)));
    foreach ($togliere as $d) {
        sec_q("UPDATE pill_doses SET status = 'pending', taken_at = NULL WHERE id = ? AND user_id = ?", [(int)$d['id'], $uid], 'ii');
    }
    foreach ($aggiungere as $d) {
        $quando = $giorno . ' ' . substr((string)$d['due_time'], 0, 5) . ':00';
        if ($quando > $adesso) $quando = $adesso;
        sec_q("UPDATE pill_doses SET status = 'taken', taken_at = ? WHERE id = ? AND user_id = ?", [$quando, (int)$d['id'], $uid], 'sii');
    }
    sec_json(['ok' => true, 'taken' => $n, 'tot' => count($prese)]);
}

/* ---------------- tutte prese, in un colpo ----------------
   Il tastino «prese tutte». `ids` è l'elenco delle prese, separate da
   virgole; `mode` dice che ora scrivere:
     - on_time: ognuna all'orario in cui era prevista (il caso normale per
       quelle dei giorni scorsi: le si è prese, ci si è scordati di spuntarle);
     - now: tutte adesso.
   Si toccano solo le prese ancora in sospeso: una già spuntata ha già la
   sua ora vera, e riscriverla la cancellerebbe. */
if ($ajax === 'pill_mark_all') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $mode = sec_enum(sec_in('mode'), ['on_time', 'now'], 'now');
    $ids = [];
    foreach (explode(',', (string)sec_str(sec_in('ids'), 4000)) as $x) {
        $n = (int)trim($x);
        if ($n > 0) $ids[$n] = true;
    }
    $ids = array_slice(array_keys($ids), 0, 200);
    if (!$ids) sec_json(['ok' => true, 'n' => 0, 'taken' => new stdClass()]);

    $segni = implode(',', array_fill(0, count($ids), '?'));
    $rows = sec_all("SELECT id, due_date, due_time FROM pill_doses
                     WHERE user_id = ? AND status <> 'taken' AND id IN ($segni)",
                    array_merge([$uid], $ids), 'i' . str_repeat('i', count($ids)));
    $adesso = date('Y-m-d H:i:s');
    $fatte = [];
    foreach ($rows as $r) {
        $quando = $mode === 'on_time'
            ? $r['due_date'] . ' ' . substr((string)$r['due_time'], 0, 5) . ':00'
            : $adesso;
        /* una presa prevista più avanti di adesso non si scrive nel futuro */
        if ($quando > $adesso) $quando = $adesso;
        sec_q("UPDATE pill_doses SET status = 'taken', taken_at = ? WHERE id = ? AND user_id = ?",
              [$quando, (int)$r['id'], $uid], 'sii');
        $fatte[(int)$r['id']] = $quando;
    }
    sec_json(['ok' => true, 'n' => count($fatte), 'taken' => $fatte ?: new stdClass()]);
}

/* ---------------- come è andata, giorno per giorno ----------------
   Serve al grafico che si apre toccando una terapia: non "quante ne ho
   prese", che è un numero e non dice niente, ma *quando* non l'ho presa.
   Si torna un giorno per riga con quante prese erano previste e come sono
   finite; i giorni in cui la terapia non toccava non compaiono affatto,
   perché un buco lì non è una dimenticanza. */
if ($ajax === 'pill_history') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('plan_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('pill_plans', $id, $uid)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    /* Un mese per volta quando il calendario lo chiede, altrimenti gli ultimi
       N giorni. Il mese si valida con l'espressione e non con sec_date,
       perché qui manca il giorno e sec_date rifiuterebbe la stringa. */
    $mese = sec_str(sec_in('month'), 7);
    if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $mese)) {
        $da = $mese . '-01';
        $a  = date('Y-m-t', strtotime($da));
    } else {
        $gg = sec_int(sec_in('days'), 7, 180, 60);
        /* la data si calcola qui e si passa come valore: un INTERVAL con un
           segnaposto dentro non è portabile quanto un confronto fra date */
        $da = date('Y-m-d', strtotime(date('Y-m-d') . ' -' . ($gg - 1) . ' day'));
        $a  = date('Y-m-d');
    }

    $rows = sec_all("SELECT due_date, status, COUNT(*) AS n
                     FROM pill_doses
                     WHERE user_id = ? AND plan_id = ? AND due_date >= ? AND due_date <= ?
                     GROUP BY due_date, status
                     ORDER BY due_date", [$uid, $id, $da, $a], 'iiss');
    $per = [];
    foreach ($rows as $r) {
        $d = $r['due_date'];
        if (!isset($per[$d])) $per[$d] = ['date' => $d, 'taken' => 0, 'skipped' => 0, 'pending' => 0];
        $per[$d][$r['status']] = (int)$r['n'];
    }
    sec_json(['ok' => true, 'from' => $da, 'to' => $a, 'oggi' => date('Y-m-d'),
              'days' => array_values($per)]);
}

/* ---------------- terapia: crea o modifica ---------------- */
if ($ajax === 'pill_save') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
    $name  = sec_str(sec_in('name'), 120);
    if ($name === '') sec_die_json(t('api.manca_il_nome'), 422, 'empty');
    $dose  = sec_str(sec_in('dose'), 60);
    $notes = sec_str(sec_in('notes'), 255);
    $color = sec_color(sec_in('color'), '#B7A6D6');
    $freq  = sec_enum(sec_in('freq_type'), ['daily', 'monthly', 'interval'], 'daily');
    $wd    = preg_replace('/[^01]/', '', sec_str(sec_in('weekdays'), 7));
    $wd    = str_pad($wd === '' ? '1111111' : $wd, 7, '1');
    $md    = preg_replace('/[^0-9,]/', '', sec_str(sec_in('month_days'), 100));
    $int   = sec_int(sec_in('interval_days'), 1, 365, 1);
    /* gli orari si rimettono in forma qui e non si fidano di quello che
       arriva: è l'unico campo con una sintassi tutta sua */
    $times = [];
    foreach (td_pill_times(sec_str(sec_in('times'), 255)) as $t) {
        $times[] = substr($t[0], 0, 5) . 'x' . $t[1];
    }
    $times = implode(',', $times);
    $start = sec_date(sec_in('start_date'), date('Y-m-d'));
    $endIn = sec_str(sec_in('end_date'), 10);
    $end   = $endIn === '' ? null : sec_date($endIn, date('Y-m-d'));
    $att   = sec_bool(sec_in('active')) ? 1 : 0;

    if ($id > 0) {
        if (!sec_owns('pill_plans', $id, $uid)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');

        /* Il ritmo è cambiato? Si guarda PRIMA di scrivere. Solo se cambia
           davvero si sposta schedule_from a oggi: correggere il nome o il
           colore non deve buttare via la storia delle prese passate, mentre
           cambiare gli orari sì — da oggi in poi vale il ritmo nuovo, prima
           valeva quello vecchio e le prese di allora restano com'erano. */
        $prima = sec_one("SELECT times, freq_type, weekdays, month_days, interval_days, schedule_from, start_date
                          FROM pill_plans WHERE id=? AND user_id=?", [$id, $uid], 'ii');
        $ritmoCambiato = !$prima
            || (string)$prima['times']         !== $times
            || (string)$prima['freq_type']     !== $freq
            || (string)$prima['weekdays']      !== $wd
            || (string)$prima['month_days']    !== $md
            || (int)$prima['interval_days']    !== $int;

        if ($ritmoCambiato) {
            /* Da quando vale il ritmo nuovo: di norma da oggi, ma da DOMANI se
               oggi una presa è già stata segnata.
               Il caso: pastiglia delle 12:15 presa a mezzogiorno, e alle 14 si
               sposta l'orario alle 12:00. Con "da oggi" nascerebbe una presa
               delle 12:00 in sospeso accanto a quella delle 12:15 già spuntata:
               due righe per una pastiglia sola, che è il doppione di prima
               spostato dai giorni scorsi a oggi.
               La giornata di oggi è già stata vissuta con il ritmo vecchio, e
               l'orario nuovo riguarda domani — che poi è il motivo per cui lo
               si sta cambiando: per la prossima volta. */
            $giaSegnataOggi = sec_one("SELECT id FROM pill_doses
                                       WHERE plan_id=? AND user_id=? AND due_date=CURDATE()
                                         AND status <> 'pending' LIMIT 1", [$id, $uid], 'ii');
            $valeDa = $giaSegnataOggi ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
        } else {
            $valeDa = !empty($prima['schedule_from']) ? (string)$prima['schedule_from'] : (string)$prima['start_date'];
        }

        sec_q("UPDATE pill_plans SET name=?, dose=?, notes=?, color=?, freq_type=?, weekdays=?,
                      month_days=?, interval_days=?, times=?, start_date=?, end_date=?, is_active=?,
                      schedule_from=?
               WHERE id=? AND user_id=?",
              [$name, $dose, $notes, $color, $freq, $wd, $md, $int, $times, $start, $end, $att, $valeDa, $id, $uid],
              'sssssssisssisii');
        /* Le prese in sospeso si rifanno solo DA QUANDO vale il ritmo nuovo, non
           da oggi: se il ritmo nuovo parte domani, quella di oggi ancora in
           sospeso è dovuta davvero con l'orario vecchio e cancellarla vorrebbe
           dire far sparire una pastiglia che va ancora presa. Quelle già
           spuntate non si toccano mai: sono un fatto avvenuto. */
        sec_q("DELETE FROM pill_doses WHERE plan_id=? AND user_id=? AND due_date >= ? AND status='pending'",
              [$id, $uid, $valeDa], 'iis');
    } else {
        sec_q("INSERT INTO pill_plans (user_id, name, dose, notes, color, freq_type, weekdays,
                                       month_days, interval_days, times, start_date, end_date, is_active, schedule_from)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$uid, $name, $dose, $notes, $color, $freq, $wd, $md, $int, $times, $start, $end, $att, $start],
              'isssssssisssis');
    }
    td_pills_ensure($uid, date('Y-m-d'));
    sec_json(['ok' => true, 'plans' => td_pill_plans($uid)]);
}

/* ---------------- terapia: elimina ---------------- */
if ($ajax === 'pill_del') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('pill_plans', $id, $uid)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    /* via anche le prese: senza la terapia non vogliono dire più niente, e
       tenerle farebbe un archivio di righe orfane */
    sec_q("DELETE FROM pill_doses WHERE plan_id=? AND user_id=?", [$id, $uid], 'ii');
    sec_q("DELETE FROM pill_plans WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'plans' => td_pill_plans($uid)]);
}

sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');