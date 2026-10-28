<?php
/* =====================================================================
   STANDOUT — API DELLE ATTIVITÀ (TO DO / TO BUY)
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
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'todo', 0, 400, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';
/* Solo le letture possono arrivare con GET: il resto è POST, dove il token CSRF
   viene davvero controllato (vedi sec_solo_post_se_scrive in security_core.php). */
sec_solo_post_se_scrive($ajax, ['buy_memory', 'progetti', 'nota_leggi', 'list', 'pills', 'pill_history']);

/* I tre elenchi sono la stessa tabella con una colonna in più: così una
   voce può passare da un elenco all'altro senza spostare righe, e tutto
   quello che già funzionava (sotto-attività, planner, pomodoro) continua
   a funzionare senza duplicare il codice. */
const TD_TIPI = ['todo', 'buy'];

/* Pulizia, immagini, collegamento al planner e forma della risposta stanno in
   lib/todo_core.php: le usa anche l'app (/api/v1/tasks) e devono restare uguali. */
require_once __DIR__ . '/../../lib/todo_core.php';

/* Categorie, prezzo, indirizzo e «memoria» del to buy (td_seed_categories, td_categories,
   td_price_cents, td_url, td_mem_*) stanno in lib/todo_core.php: li usa anche l'app. */

/* ===================== ENDPOINT ===================== */

/* I ricordi del "to buy": per i suggerimenti mentre si scrive e per i «da
   ricomprare». I più comprati e i più recenti per primi. */
if ($ajax === 'buy_memory') {
    if (!td_mem_c_e()) sec_json(['ok' => true, 'items' => []]);
    $rows = sec_all("SELECT chiave, title, cat_id, cents, uses, last_used FROM todo_buy_memory
                     WHERE user_id = ? ORDER BY uses DESC, last_used DESC LIMIT 300", [$uid], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['k' => $r['chiave'], 'title' => $r['title'],
                  'cat_id' => $r['cat_id'] !== null ? (int)$r['cat_id'] : null,
                  'cents' => $r['cents'] !== null ? (int)$r['cents'] : null,
                  'uses' => (int)$r['uses'], 'last' => $r['last_used']];
    }
    sec_json(['ok' => true, 'items' => $out]);
}

/* Dimentica un suggerimento (tocco lungo sul suggerimento). */
if ($ajax === 'buy_forget') {
    if (td_mem_c_e()) {
        sec_q("DELETE FROM todo_buy_memory WHERE user_id = ? AND chiave = ?", [$uid, td_mem_chiave((string)sec_str(sec_in('k'), 300))], 'is');
    }
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   LA NOTA VELOCE

   Una nota sola per utente, in cima alla pagina: per segnare qualcosa in
   dieci secondi, senza decidere dove va. Poi, con calma, si smista (a mano o
   con l'IA) in to do, to buy, planner o progetti.

   La tabella si crea da sola al primo uso, come todo_buy_memory: la
   migrazione 2026-10-25_todo_nota.sql esiste solo per tenere schema e
   database allineati.

   «nota_applica» e' il punto in cui le voci SMISTATE diventano vere. Arriva
   gia' controllata dall'utente (schermata di conferma); qui si ricontrolla
   tutto lo stesso, perche' il client non e' mai una garanzia.
   --------------------------------------------------------------------- */
function td_nota_c_e(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        sec_q("CREATE TABLE IF NOT EXISTS todo_notes (
                 user_id INT NOT NULL,
                 body MEDIUMTEXT NOT NULL,
                 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                 PRIMARY KEY (user_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/* Colori dei progetti nuovi: a rotazione, cosi' due progetti creati di fila
   non nascono dello stesso colore. */
const TD_COLORI_PROGETTO = ['#A9C3AE', '#E8B86B', '#D9B7C0', '#9FB7D1', '#C6B6A6', '#B7A6D6'];

/* I progetti dell'utente, per riconoscere «#nome» nella barra: nome e colore,
   i preferiti per primi. Corto apposta, si legge una volta per pagina. */
if ($ajax === 'progetti') {
    $rows = sec_all("SELECT id, name, color FROM projects WHERE user_id = ? AND study_event_id IS NULL
                     ORDER BY is_favorite DESC, name ASC LIMIT 150", [$uid], 'i');
    sec_json(['ok' => true, 'projects' => array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
    }, $rows), 'categories' => td_categories($uid)]);
}

if ($ajax === 'nota_leggi') {
    $r = td_nota_c_e() ? sec_one("SELECT body, updated_at FROM todo_notes WHERE user_id = ?", [$uid], 'i') : null;
    sec_json(['ok' => true, 'body' => $r ? (string)$r['body'] : '', 'updated' => $r['updated_at'] ?? null]);
}

if ($ajax === 'nota_salva') {
    if (!td_nota_c_e()) sec_die_json(t('todo.nota_errore'), 500, 'db');
    $body = sec_text(sec_in('body'), 20000);
    sec_q("INSERT INTO todo_notes (user_id, body) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE body = VALUES(body)", [$uid, $body], 'is');
    sec_json(['ok' => true]);
}

if ($ajax === 'nota_applica') {
    $voci = sec_in('items');
    if (!is_array($voci) || !$voci) sec_json(['ok' => true, 'results' => [], 'categories' => td_categories($uid)]);
    $voci = array_slice($voci, 0, 80);
    $oggi = date('Y-m-d');
    $risultati = [];

    foreach ($voci as $i => $v) {
        if (!is_array($v)) { $risultati[] = ['i' => $i, 'ok' => false]; continue; }
        $dest  = sec_enum($v['dest'] ?? '', ['todo', 'buy', 'event', 'project'], '');
        $title = sec_str($v['title'] ?? '', $dest === 'event' ? 180 : 300);
        if ($dest === '' || $title === '') { $risultati[] = ['i' => $i, 'ok' => false]; continue; }

        $data = sec_date($v['date'] ?? '', null);
        $ora  = !empty($v['time']) ? sec_time($v['time'], null) : null;
        $min  = sec_int($v['minutes'] ?? 0, 1, 1440, $dest === 'event' ? 60 : 30);
        $proj = sec_int($v['project_id'] ?? 0, 0, PHP_INT_MAX, 0);
        if ($proj > 0 && !sec_owns('projects', $proj, $uid)) $proj = 0;

        try {
            if ($dest === 'project') {
                /* un progetto che esiste gia' con quel nome non si duplica */
                $ex = sec_one("SELECT id FROM projects WHERE user_id = ? AND LOWER(name) = LOWER(?) LIMIT 1", [$uid, $title], 'is');
                if ($ex) { $risultati[] = ['i' => $i, 'ok' => true, 'dest' => 'project', 'id' => (int)$ex['id'], 'esisteva' => true]; continue; }
                $n  = sec_one("SELECT COUNT(*) AS n, COALESCE(MAX(custom_order), -1) + 1 AS o FROM projects WHERE user_id = ? AND parent_id IS NULL", [$uid], 'i');
                $col = TD_COLORI_PROGETTO[((int)($n['n'] ?? 0)) % count(TD_COLORI_PROGETTO)];
                sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
                       VALUES (?, NULL, ?, ?, 0, 'in_progress', ?, NOW())",
                      [$uid, $title, $col, (int)($n['o'] ?? 0)], 'issi');
                $risultati[] = ['i' => $i, 'ok' => true, 'dest' => 'project', 'id' => sec_insert_id()];
                continue;
            }

            if ($dest === 'event') {
                $giorno = $data ?: $oggi;
                $tutto  = $ora === null ? 1 : 0;
                $inizio = $tutto ? '00:00:00' : $ora;
                $fineMin = $tutto ? 1439 : min(1439, (int)substr($inizio, 0, 2) * 60 + (int)substr($inizio, 3, 2) + max(5, $min));
                $fine   = $tutto ? '23:59:00' : sprintf('%02d:%02d:00', intdiv($fineMin, 60), $fineMin % 60);
                sec_q("INSERT INTO planner_events
                       (user_id, title, event_date, start_time, end_time, is_all_day, project_id,
                        item_type, is_completed, notify_type, notify_minutes)
                       VALUES (?,?,?,?,?,?,?, 'event', 0, 'none', 0)",
                      [$uid, $title, $giorno, $inizio, $fine, $tutto, $proj > 0 ? $proj : null]);
                $risultati[] = ['i' => $i, 'ok' => true, 'dest' => 'event', 'id' => sec_insert_id()];
                continue;
            }

            /* to do e to buy: la stessa riga di «save», una volta sola */
            $catId = 0; $cents = null; $prio = 3;
            if ($dest === 'buy') {
                $catId = sec_int($v['cat_id'] ?? 0, 0, PHP_INT_MAX, 0);
                if ($catId > 0 && !sec_owns('todo_buy_categories', $catId, $uid)) $catId = 0;
                $catNome = sec_str($v['cat_name'] ?? '', 60);
                if ($catId === 0 && $catNome !== '') {
                    $ex = sec_one("SELECT id FROM todo_buy_categories WHERE user_id = ? AND LOWER(name) = LOWER(?) LIMIT 1", [$uid, $catNome], 'is');
                    if ($ex) $catId = (int)$ex['id'];
                    else {
                        sec_q("INSERT INTO todo_buy_categories (user_id, name, color, sort_order)
                               VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM todo_buy_categories WHERE user_id=?) x))",
                              [$uid, $catNome, '#B7AEA3', $uid], 'issi');
                        $catId = sec_insert_id();
                    }
                }
                $cents = td_price_cents($v['price'] ?? '');
                $prio  = sec_int($v['priority'] ?? 3, 1, 3, 3);
            }
            $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
            sec_q("INSERT INTO todos (user_id, list_type, parent_id, project_id, title, due_date, due_time,
                                      duration_minutes, sort_order, source,
                                      buy_category_id, buy_priority, buy_price_cents, buy_url)
                   VALUES (?,?,NULL,?,?,?,?,?,?,'page',?,?,?,NULL)",
                  [$uid, $dest, $proj > 0 ? $proj : null, $title, $data, $ora, $min, (int)($next['n'] ?? 1),
                   $catId > 0 ? $catId : null, $prio, $cents]);
            $nuovo = sec_insert_id();
            td_sync_planner($uid, $nuovo);
            if ($dest === 'buy') td_mem_ricorda($uid, $nuovo, false);
            $risultati[] = ['i' => $i, 'ok' => true, 'dest' => $dest, 'id' => $nuovo];
        } catch (Throwable $e) {
            $risultati[] = ['i' => $i, 'ok' => false];
        }
    }
    sec_json(['ok' => true, 'results' => $risultati, 'categories' => td_categories($uid)]);
}

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
        if ($type === 'buy' && ($hasCat || $hasPrice)) td_mem_ricorda($uid, $id, false);
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
    if ($next === 1) td_mem_ricorda($uid, $id, true);
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
   appuntamento (quello delle 10:00 di oggi) con la sua spunta. La logica sta in
   lib/pills_core.php (la usa anche l'app, /api/v1/pills): qui si leggono i campi
   della richiesta, si ripuliscono e si risponde.

   La tabella è facoltativa come le altre aggiunte recenti: se la migrazione
   non è stata eseguita, la sezione risponde vuota e il resto delle attività
   continua a funzionare.
   ===================================================================== */
require_once __DIR__ . '/../../lib/pills_core.php';

/* ---------------- elenco del giorno ---------------- */
if ($ajax === 'pills') {
    if (!td_pills_c_e()) sec_json(['ok' => true, 'plans' => [], 'doses' => [], 'installed' => false]);
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    sec_json(['ok' => true] + td_pill_giorno($uid, $date));
}

/* ---------------- spunta di una presa ---------------- */
if ($ajax === 'pill_mark') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    $st = sec_enum(sec_in('status'), ['pending', 'taken', 'skipped'], 'taken');
    $r = td_pill_segna($uid, $id, $st, sec_date(sec_in('at_date'), ''), sec_time(sec_in('at_time'), ''));
    if ($r === null) sec_die_json(t('api.presa_non_trovata'), 404, 'not_found');
    sec_json(['ok' => true] + $r);
}

/* ---------------- il calendario: quante prese in un giorno ---------------- */
if ($ajax === 'pill_day_set') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $pid = sec_int(sec_in('plan_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('pill_plans', $pid, $uid)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    $giorno = sec_date(sec_in('date'), '');
    if ($giorno === '') sec_die_json(t('api.data_non_valida'), 400, 'bad_date');
    if ($giorno > date('Y-m-d')) sec_die_json(t('api.data_futura'), 400, 'future');
    $n = sec_int(sec_in('taken'), 0, 50, 0);
    sec_json(['ok' => true] + td_pill_giorno_imposta($uid, $pid, $giorno, $n));
}

/* ---------------- tutte prese, in un colpo ----------------
   `ids` è l'elenco delle prese, separate da virgole; `mode` è on_time (ognuna
   all'orario previsto) oppure now (tutte adesso). */
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
    $r = td_pill_segna_tutte($uid, $ids, $mode);
    sec_json(['ok' => true, 'n' => $r['n'], 'taken' => $r['taken'] ?: new stdClass()]);
}

/* ---------------- come è andata, giorno per giorno ---------------- */
if ($ajax === 'pill_history') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('plan_id'), 1, PHP_INT_MAX, 0);
    /* Un mese per volta quando il calendario lo chiede, altrimenti gli ultimi
       N giorni. Il mese si valida con l'espressione e non con sec_date,
       perché qui manca il giorno e sec_date rifiuterebbe la stringa. */
    $r = td_pill_storia($uid, $id, sec_str(sec_in('month'), 7), sec_int(sec_in('days'), 7, 180, 60));
    if ($r === null) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    sec_json(['ok' => true] + $r);
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

    $r = td_pill_scrivi($uid, $id, ['name' => $name, 'dose' => $dose, 'notes' => $notes, 'color' => $color, 'freq' => $freq,
                                    'wd' => $wd, 'md' => $md, 'int' => $int, 'times' => $times, 'start' => $start, 'end' => $end, 'att' => $att]);
    if ($r === null) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    sec_json(['ok' => true, 'plans' => td_pill_plans($uid)]);
}

/* ---------------- terapia: elimina ---------------- */
if ($ajax === 'pill_del') {
    if (!td_pills_c_e()) sec_die_json(t('api.sezione_non_installata'), 400, 'no_table');
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!td_pill_elimina($uid, $id)) sec_die_json(t('api.terapia_non_trovata'), 404, 'not_found');
    sec_json(['ok' => true, 'plans' => td_pill_plans($uid)]);
}

sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');