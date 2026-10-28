<?php
/* =====================================================================
   STANDOUT — la logica delle attivita' (to do) condivisa tra la pagina e l'API

   Prima stava tutta dentro public/planner/todo_api.php, che risponde alla
   pagina to-do con le sue azioni ?ajax=…. Le funzioni qui sotto, fino alla
   riga «Per l'API /api/v1/tasks», sono SPOSTATE da li' senza cambiarne una
   riga (pulizia, immagini, collegamento al planner, forma della risposta):
   la pagina e l'app devono comportarsi allo stesso modo, e l'unico modo di
   garantirlo e' avere un solo codice. La parte nuova serve a /api/v1/tasks.

   Serve security_core.php (sec_*) e la lingua (t()); chi include questo file
   li ha gia' caricati con lib/bootstrap.php.
   ===================================================================== */

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


/* =====================================================================
   Per l'API /api/v1/tasks (PROGETTO §15, passo 9, prima fetta)

   Qui c'e' solo il to do: gli acquisti («to buy») hanno immagini, categorie
   e «memoria», e arrivano in una fetta a parte. Una riga di un altro elenco
   si comporta come se non esistesse (404), cosi' l'app non puo' toccarla
   senza passare da quel codice.

   Ogni funzione prende l'utente come PRIMO argomento e filtra ogni riga per
   user_id, come il resto del sito. Gli errori tornano come
   ['errore' => ['codice' => 'validation'|'not_found', 'campo' => ..., 'testo' => ...]]:
   li trasforma in risposta il gestore in public/api/v1/index.php.
   ===================================================================== */

/** Come l'app vede un'attivita': come td_out() ma senza i campi del «to buy». */
function td_out_api(array $r): array {
    $o = td_out($r);
    foreach (['list_type', 'cat_id', 'priority', 'cents', 'url', 'image'] as $k) unset($o[$k]);
    return $o;
}

/** Una riga del to do dell'utente (con nome e colore del progetto), o null. */
function td_api_riga(int $uid, int $id): ?array {
    return sec_one("SELECT t.*, p.name AS project_name, p.color AS project_color
                    FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
                    WHERE t.id = ? AND t.user_id = ? AND t.list_type = 'todo'", [$id, $uid], 'ii');
}

function td_api_errore(string $codice, string $campo, string $testo): array {
    return ['errore' => ['codice' => $codice, 'campo' => $campo, 'testo' => $testo]];
}

/**
 * L'elenco del to do, nell'ordine della pagina: prima le non fatte, prima
 * quelle con data (le piu' vicine per prime), poi come le ha messe l'utente.
 * $progetto: solo le attivita' di quel progetto. Ritorna null se il progetto
 * non e' dell'utente.
 * Come la pagina, toglie prima le attivita' spuntate nei giorni scorsi
 * (td_cleanup): sono le stesse regole per chi guarda dal sito e dall'app.
 */
function td_api_elenco(int $uid, ?int $progetto): ?array {
    if ($progetto !== null && !sec_owns('projects', $progetto, $uid)) return null;
    td_cleanup($uid);
    $sql = "SELECT t.*, p.name AS project_name, p.color AS project_color
            FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
            WHERE t.user_id = ? AND t.list_type = 'todo'";
    $par = [$uid];
    $tipi = 'i';
    if ($progetto !== null) { $sql .= " AND t.project_id = ?"; $par[] = $progetto; $tipi .= 'i'; }
    $sql .= " ORDER BY t.is_done ASC,
                       CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC,
                       CASE WHEN t.due_time IS NULL THEN 1 ELSE 0 END, t.due_time ASC,
                       t.sort_order ASC, t.id ASC
             LIMIT 1000";
    return array_map('td_out_api', sec_all($sql, $par, $tipi));
}

/**
 * Legge e controlla i campi che il client manda. Si guarda se la CHIAVE c'e'
 * (array_key_exists), non se il valore e' pieno: «date: null» vuol dire «togli
 * la data», e un campo assente vuol dire «non toccarlo». Un valore del tipo
 * sbagliato e' un errore, non viene corretto in silenzio come fa la pagina.
 * Ritorna ['campi' => [colonna => valore], 'fatto' => bool|null] oppure un errore.
 */
function td_api_valida(int $uid, array $c, bool $creazione): array {
    $campi = [];
    $fatto = null;

    if (array_key_exists('title', $c)) {
        if (!is_string($c['title'])) return td_api_errore('validation', 'title', 'title must be text');
        $titolo = sec_str($c['title'], 300);
        // la pagina tratta un titolo vuoto come «cancella»; per l'app e' un errore, la cancellazione ha il suo DELETE
        if ($titolo === '') return td_api_errore('validation', 'title', 'title cannot be empty');
        $campi['title'] = $titolo;
    } elseif ($creazione) {
        return td_api_errore('validation', 'title', 'title is required');
    }

    if (array_key_exists('project_id', $c)) {
        $p = $c['project_id'];
        if ($p === null || $p === 0) $campi['project_id'] = null;
        elseif (!is_int($p) || $p < 0) return td_api_errore('validation', 'project_id', 'project_id must be a number or null');
        elseif (!sec_owns('projects', $p, $uid)) return td_api_errore('not_found', 'project_id', 'Project not found');
        else $campi['project_id'] = $p;
    }

    if ($creazione && array_key_exists('parent_id', $c) && $c['parent_id'] !== null) {
        $p = $c['parent_id'];
        if (!is_int($p) || $p <= 0) return td_api_errore('validation', 'parent_id', 'parent_id must be a number or null');
        // la sotto-attivita' ha un solo livello (la pagina e la spunta a cascata lo danno per scontato)
        $madre = sec_one("SELECT parent_id FROM todos WHERE id = ? AND user_id = ? AND list_type = 'todo'", [$p, $uid], 'ii');
        if (!$madre) return td_api_errore('not_found', 'parent_id', 'Parent task not found');
        if ($madre['parent_id'] !== null) return td_api_errore('validation', 'parent_id', 'A sub-task cannot have its own sub-tasks');
        $campi['parent_id'] = $p;
    }

    if (array_key_exists('date', $c)) {
        if ($c['date'] === null) $campi['due_date'] = null;
        else {
            $d = is_string($c['date']) ? sec_date($c['date'], null) : null;
            if ($d === null) return td_api_errore('validation', 'date', 'date must be YYYY-MM-DD or null');
            $campi['due_date'] = $d;
        }
    }

    if (array_key_exists('time', $c)) {
        if ($c['time'] === null) $campi['due_time'] = null;
        else {
            $o = is_string($c['time']) ? sec_time($c['time'], null) : null;
            if ($o === null) return td_api_errore('validation', 'time', 'time must be HH:MM or null');
            $campi['due_time'] = $o;
        }
    }

    if (array_key_exists('minutes', $c)) {
        if (!is_int($c['minutes']) || $c['minutes'] < 1 || $c['minutes'] > 1440) {
            return td_api_errore('validation', 'minutes', 'minutes must be a number from 1 to 1440');
        }
        $campi['duration_minutes'] = $c['minutes'];
    }

    if (array_key_exists('done', $c)) {
        if (!is_bool($c['done'])) return td_api_errore('validation', 'done', 'done must be true or false');
        $fatto = $c['done'];
    }

    return ['campi' => $campi, 'fatto' => $fatto];
}

/** Spunta o despunta, e con lei le sue sotto-attivita' (come la pagina). */
function td_api_spunta(int $uid, int $id, bool $fatto): void {
    $f = $fatto ? 1 : 0;
    $quando = $fatto ? 'NOW()' : 'NULL';
    sec_q("UPDATE todos SET is_done=?, done_at=$quando WHERE id=? AND user_id=?", [$f, $id, $uid], 'iii');
    sec_q("UPDATE todos SET is_done=?, done_at=$quando WHERE parent_id=? AND user_id=?", [$f, $id, $uid], 'iii');
    td_sync_planner($uid, $id);
}

/** Crea un'attivita'. Ritorna ['task' => ...] oppure un errore. */
function td_api_crea(int $uid, array $c): array {
    $v = td_api_valida($uid, $c, true);
    if (isset($v['errore'])) return $v;
    $f = $v['campi'];

    $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
    sec_q("INSERT INTO todos (user_id, list_type, parent_id, project_id, title, due_date, due_time,
                              duration_minutes, sort_order, source)
           VALUES (?, 'todo', ?, ?, ?, ?, ?, ?, ?, 'page')",
          [$uid, $f['parent_id'] ?? null, $f['project_id'] ?? null, $f['title'],
           $f['due_date'] ?? null, $f['due_time'] ?? null, $f['duration_minutes'] ?? 30, (int)($next['n'] ?? 1)]);
    $id = sec_insert_id();
    if ($v['fatto'] === true) td_api_spunta($uid, $id, true); else td_sync_planner($uid, $id);
    return ['task' => td_out_api(td_api_riga($uid, $id) ?? [])];
}

/** Cambia SOLO i campi nominati. Ritorna ['task' => ...] oppure un errore. */
function td_api_modifica(int $uid, int $id, array $c): array {
    if (!td_api_riga($uid, $id)) return td_api_errore('not_found', 'id', 'Task not found');
    unset($c['parent_id']);   // non si sposta una attivita' sotto un'altra: parent_id vale solo alla creazione
    $v = td_api_valida($uid, $c, false);
    if (isset($v['errore'])) return $v;
    if (!$v['campi'] && $v['fatto'] === null) return td_api_errore('validation', 'body', 'Nothing to update');

    /* I nomi delle colonne vengono SOLO da td_api_valida (elenco fisso), mai dal client. */
    if ($v['campi']) {
        $set = []; $par = [];
        foreach ($v['campi'] as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
        $par[] = $id; $par[] = $uid;
        sec_q("UPDATE todos SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($v['fatto'] !== null) td_api_spunta($uid, $id, $v['fatto']); else td_sync_planner($uid, $id);
    return ['task' => td_out_api(td_api_riga($uid, $id) ?? [])];
}

/** Toglie un'attivita', le sue sotto-attivita' e i blocchi che hanno sul planner. */
function td_api_elimina(int $uid, int $id): bool {
    if (!td_api_riga($uid, $id)) return false;
    $righe = sec_all("SELECT id, planner_event_id, buy_image FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    foreach ($righe as $k) {
        if (!empty($k['planner_event_id'])) {
            sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$k['planner_event_id'], $uid], 'ii');
        }
        td_drop_image($k['buy_image'] ?? null);
    }
    sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
    return true;
}
