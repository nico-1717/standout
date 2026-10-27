<?php
/* ==========================================================================
   POMODORO STORICO — popup riutilizzabile per lo storico delle sessioni
   (include in progetti.php e pomodoro.php). Tutta l'interazione avviene via
   fetch/JSON su questo stesso file, per non dover ricaricare la pagina ogni
   volta che si filtra, ordina, o si carica un'altra pagina di risultati.

   Uso:
     include __DIR__ . '/pomodoro_storico.php';               // renderizza il popup (vuoto)
     window.openPomodoroStorico({ scope: 'all' });  // apre in JS, storico globale
     window.openPomodoroStorico({ scope: 'project', projectId: 5, projectName: 'Tesi' });
   ========================================================================== */

if (!isset($conn) || !$conn) {
    require_once __DIR__ . '/../../lib/config.php';
    /* auth_check PRIMA di sec_db(): e' quello a caricare security_core.php. */
    require_once __DIR__ . '/../../lib/auth_check.php';
    $current_user_id = (int)$utente_corrente['id'];
    $conn = sec_db();
}

/* Tutte le azioni di questo file arrivano come POST su ?ph_ajax=...: qui il
   token CSRF vale davvero. Prima non c'era nulla, e sono endpoint che
   cancellano sessioni e le spostano fra progetti. */
if (isset($_GET['ph_ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write((int)$current_user_id, 'pomodoro_storico', 0, 300, 60);
}
/* Il client chiama sempre con POST: con GET non passa nessuna azione. */
if (isset($_GET['ph_ajax'])) sec_solo_post_se_scrive(sec_str($_GET['ph_ajax'], 30), []);

if (!function_exists('ph_project_ids_in_subtree')) {
        function ph_project_ids_in_subtree($userId, $rootId) {
        $childrenOf = [];
        foreach (sec_all("SELECT id, parent_id FROM projects WHERE user_id = ?", [(int)$userId]) as $row) {
            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $childrenOf[$pid][] = (int)$row['id'];
        }
        $all = []; $stack = [intval($rootId)];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $all[] = $cur;
            foreach (($childrenOf[$cur] ?? []) as $c) $stack[] = $c;
        }
        return $all;
    }
}

if (!function_exists('ph_fmt_duration')) {
    function ph_fmt_duration($seconds) {
        $seconds = (int)$seconds;
        $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60);
        if ($h > 0) return $h . 'h ' . $m . 'm';
        return $m . 'm';
    }
}

if (!function_exists('ph_project_map')) {
    // id -> ['name'=>, 'parent'=>] per l'utente, caricato una sola volta
        function ph_project_map($userId) {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        foreach (sec_all("SELECT id, name, parent_id FROM projects WHERE user_id = ?", [(int)$userId]) as $row) {
            $cache[(int)$row['id']] = ['name' => $row['name'], 'parent' => $row['parent_id'] === null ? 0 : (int)$row['parent_id']];
        }
        return $cache;
    }
}
if (!function_exists('ph_project_path')) {
    /* Catena di antenati (dal più lontano al progetto stesso), max 3 voci:
       nonno, padre, progetto — per il tag "nonno • padre • progetto" (richiesta 17). */
    function ph_project_path($userId, $projectId) {
        if (!$projectId) return [];
        $map = ph_project_map($userId);
        $chain = []; $cur = (int)$projectId; $guard = 0;
        while ($cur > 0 && isset($map[$cur]) && $guard < 40) {
            array_unshift($chain, $map[$cur]['name']);
            $cur = $map[$cur]['parent'];
            $guard++;
        }
        return array_slice($chain, -3); // al massimo nonno, padre, progetto
    }
}

if (!function_exists('ph_session_to_array')) {
    function ph_session_to_array($row, $conn = null, $userId = 0) {
        return [
            'id' => (int)$row['id'],
            'mode' => (int)$row['mode'],
            'data_inizio' => $row['data_inizio'],
            'data_fine' => $row['data_fine'],
            'durata_secondi' => (int)$row['durata_secondi'],
            'durata_fmt' => ph_fmt_duration($row['durata_secondi']),
            'project_id' => $row['project_id'] !== null ? (int)$row['project_id'] : 0,
            'progetto_nome' => $row['progetto_nome'],
            'progetto_colore' => $row['progetto_colore'] ?: '#8A8A8E',
            'progetto_path' => ($conn && $row['project_id']) ? ph_project_path($userId, (int)$row['project_id']) : [],
            'manuale' => (int)$row['manual'],
        ];
    }
}

/* -------------------------------------------------------------------------
   AJAX: elenco sessioni (filtri + ordinamento + paginazione)
   ------------------------------------------------------------------------- */
if (!function_exists('ph_build_where')) {
    /* Stessi filtri della lista (scope/progetto/date/durata minima), usati sia
       per la pagina di sessioni che per "seleziona tutti": cosi' il tasto
       seleziona esattamente cio' che il filtro attivo mostrerebbe, pagine
       comprese, non solo le righe gia' caricate a schermo. Torna null se lo
       scope e' "project" ma non ci sono id (nessun risultato possibile). */
        function ph_build_where($uid) {
        $scope = sec_enum($_POST['scope'] ?? null, ['all', 'project'], 'all');
        $where = ["s.user_id = ?", "s.end_time IS NOT NULL"];
        $par   = [(int)$uid];

        if ($scope === 'project') {
            $projectIds = array_values(array_filter(array_map('intval', explode(',', sec_str($_POST['project_ids'] ?? '', 4000)))));
            if (empty($projectIds)) return null;
            $where[] = "s.project_id IN (" . implode(',', array_fill(0, count($projectIds), '?')) . ")";
            $par = array_merge($par, $projectIds);
        }

        // 0 = tutti, -1 = nessun progetto, N = un progetto preciso
        $filterProject = sec_int($_POST['filter_project'] ?? 0, -1);
        if ($filterProject > 0)   { $where[] = "s.project_id = ?"; $par[] = $filterProject; }
        if ($filterProject === -1) $where[] = "s.project_id IS NULL";

        /* Le date erano l'unico ingresso non numerico, ed erano incollate nella
           query dopo real_escape_string. sec_date() le valida davvero: quello
           che non e' una data viene scartato. */
        $dateFrom = sec_date($_POST['date_from'] ?? null, '');
        $dateTo   = sec_date($_POST['date_to'] ?? null, '');
        if ($dateFrom !== '') { $where[] = "s.start_time >= ?"; $par[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where[] = "s.start_time <= ?"; $par[] = $dateTo . ' 23:59:59'; }

        $minMinutes = sec_int($_POST['min_minutes'] ?? 0, 0);
        if ($minMinutes > 0) { $where[] = "s.duration_seconds >= ?"; $par[] = $minMinutes * 60; }

        /* Condizioni e valori tornano INSIEME: separati, prima o poi si
           sfasano e la query filtra per la cosa sbagliata senza dare errore. */
        return [$where, $par];
    }
}

if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'list') {
    header('Content-Type: application/json');
        $sort = sec_enum($_POST['sort'] ?? null, ['recent', 'oldest', 'duration'], 'recent');
    $offset = sec_int($_POST['offset'] ?? 0, 0);
    $limit = 20;
    $filtri = ph_build_where($current_user_id);
    if ($filtri === null) { echo json_encode(['success' => true, 'sessions' => [], 'has_more' => false, 'day_totals' => []]); exit(); }
    [$where, $par] = $filtri;

    /* $orderBy non e' un valore ma un pezzo di SQL, e non puo' essere un
       segnaposto: nasce dall'elenco chiuso qui sopra. */
    $orderBy = "s.start_time DESC";
    if ($sort === 'oldest') $orderBy = "s.start_time ASC";
    if ($sort === 'duration') $orderBy = "s.duration_seconds DESC";

    $sessions = [];
    foreach (sec_all("SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual,
                   p.name AS progetto_nome, p.color AS progetto_colore
             FROM pomodoro_sessions s
             LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY $orderBy
             LIMIT ? OFFSET ?", array_merge($par, [$limit, $offset])) as $row) {
        $sessions[] = ph_session_to_array($row, $current_user_id);
    }

    /* Il totale per giorno: non piu' quanti pomodori, ma quanto tempo — ore
       e minuti studiati quel GIORNO, INTESO COME QUELLO DI INIZIO. Mai
       s.end_time, altrimenti una sessione iniziata alle 23:50 e chiusa il
       giorno dopo conterebbe sul giorno sbagliato: e' lo stesso
       raggruppamento che il JavaScript usa gia' per decidere sotto quale
       intestazione mettere una riga (dayKey legge data_inizio, non
       data_fine) — qui si aggiunge solo la somma, non si cambia la regola.
       Va calcolato sull'INTERO risultato filtrato, non sulle 20 righe di
       questa pagina: altrimenti il totale di un giorno gia' mostrato
       cambierebbe (o peggio, resterebbe sbagliato) quando "carica altri" ne
       porta altre dello stesso giorno. Per questo gira una volta sola, al
       primo caricamento, e non ad ogni pagina. */
    $giorniTotali = [];
    if ($offset === 0) {
        foreach (sec_all("SELECT DATE(s.start_time) AS giorno, SUM(s.duration_seconds) AS secondi
                           FROM pomodoro_sessions s
                           WHERE " . implode(' AND ', $where) . "
                           GROUP BY DATE(s.start_time)", $par) as $row) {
            $giorniTotali[$row['giorno']] = (int)$row['secondi'];
        }
    }

    echo json_encode(['success' => true, 'sessions' => $sessions, 'has_more' => count($sessions) === $limit, 'day_totals' => $giorniTotali], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: tutti gli id che rispettano il filtro attivo (per "Seleziona tutti":
   non solo le righe gia' caricate a schermo, ma l'intero risultato filtrato)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'all_ids') {
    header('Content-Type: application/json');
        $filtri = ph_build_where($current_user_id);
    if ($filtri === null) { echo json_encode(['success' => true, 'ids' => []]); exit(); }
    [$where, $par] = $filtri;
    $ids = [];
    foreach (sec_all("SELECT s.id FROM pomodoro_sessions s WHERE " . implode(' AND ', $where) . " LIMIT 20000", $par) as $row) {
        $ids[] = (int)$row['id'];
    }

    echo json_encode(['success' => true, 'ids' => $ids], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: azioni di massa (elimina / scollega / collega a progetto)
   ------------------------------------------------------------------------- */

if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'bulk_action') {
    header('Content-Type: application/json');
        $ids = array_values(array_filter(array_map('intval', explode(',', sec_str($_POST['ids'] ?? '', 200000)))));
    $op = sec_enum($_POST['bulk_op'] ?? null, ['delete', 'unlink', 'set_project'], '');
    if (empty($ids) || $op === '') {
        echo json_encode(['success' => false]); exit();
    }
    /* "AND user_id = ?" resta in ogni query: gli id arrivano dal browser, e
       questa e' la riga che impedisce di toccare le sessioni di un altro. */
    $ph = implode(',', array_fill(0, count($ids), '?'));
    if ($op === 'delete') {
        sec_q("DELETE FROM pomodoro_sessions WHERE id IN ($ph) AND user_id = ?",
              array_merge($ids, [(int)$current_user_id]));
    } elseif ($op === 'unlink') {
        sec_q("UPDATE pomodoro_sessions SET project_id = NULL WHERE id IN ($ph) AND user_id = ?",
              array_merge($ids, [(int)$current_user_id]));
    } elseif ($op === 'set_project') {
        $newProjectId = sec_int($_POST['new_project_id'] ?? 0, 1);
        if ($newProjectId > 0 && sec_owns('projects', $newProjectId, (int)$current_user_id)) {
            sec_q("UPDATE pomodoro_sessions SET project_id = ? WHERE id IN ($ph) AND user_id = ?",
                  array_merge([$newProjectId], $ids, [(int)$current_user_id]));
        }
    }
    echo json_encode(['success' => true]);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: collega/scollega il progetto di UNA sessione (dal tap sul progetto)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'set_project') {
    header('Content-Type: application/json');
        $id = sec_int($_POST['id'] ?? 0, 1);
    $newProjectId = sec_int($_POST['project_id'] ?? 0, 0);
    if ($newProjectId > 0) {
        $chkRow = sec_one("SELECT name, color FROM projects WHERE id = ? AND user_id = ?",
                          [$newProjectId, (int)$current_user_id]);
        if (!$chkRow) { echo json_encode(['success' => false]); exit(); }
        sec_q("UPDATE pomodoro_sessions SET project_id = ? WHERE id = ? AND user_id = ?",
              [$newProjectId, $id, (int)$current_user_id]);
        echo json_encode(['success' => true, 'name' => $chkRow['name'], 'color' => $chkRow['color'],
                          'path' => ph_project_path($current_user_id, $newProjectId)]);
    } else {
        sec_q("UPDATE pomodoro_sessions SET project_id = NULL WHERE id = ? AND user_id = ?",
              [$id, (int)$current_user_id]);
        echo json_encode(['success' => true, 'name' => '', 'color' => '']);
    }
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: eliminazione singola (swipe)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'single_delete') {
    header('Content-Type: application/json');
        $id = sec_int($_POST['id'] ?? 0, 1);
    sec_q("DELETE FROM pomodoro_sessions WHERE id = ? AND user_id = ?", [$id, (int)$current_user_id]);
    echo json_encode(['success' => true]);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: inserimento manuale
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'manual_insert') {
    header('Content-Type: application/json');
        $data = sec_date($_POST['data'] ?? null, '');
    $oraInizio = sec_str($_POST['ora_inizio'] ?? '', 5);
    $oraFine   = sec_str($_POST['ora_fine'] ?? '', 5);
    $progettoId = sec_int($_POST['project_id'] ?? 0, 0);
    $modeVal = (sec_int($_POST['mode'] ?? 0, 0, 1) === 1) ? 1 : 0;

    /* Le ore devono avere la forma hh:mm: senza questo controllo finivano
       dentro una data costruita per concatenazione. */
    $formaOra = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    if ($data === '' || !preg_match($formaOra, $oraInizio) || !preg_match($formaOra, $oraFine)) {
        echo json_encode(['success' => false, 'error' => t('pomodoro.storico_compila')]); exit();
    }

    $startDt = $data . ' ' . $oraInizio . ':00';
    $endDt = $data . ' ' . $oraFine . ':00';
    if (strtotime($endDt) <= strtotime($startDt)) {
        $endDt = date('Y-m-d H:i:s', strtotime($endDt . ' +1 day'));
    }
    $durataSecondi = max(0, strtotime($endDt) - strtotime($startDt));

    // il progetto scelto dev'essere suo, altrimenti la sessione resta senza
    $progId = ($progettoId > 0 && sec_owns('projects', $progettoId, (int)$current_user_id)) ? $progettoId : null;

    sec_q("INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, end_time, duration_seconds, planned_duration_min, status, project_id, is_manual)
           VALUES (?, ?, ?, ?, ?, ?, 'archiviato', ?, 1)",
          [(int)$current_user_id, $modeVal, $startDt, $endDt, $durataSecondi,
           (int)round($durataSecondi / 60), $progId]);
    $newId = sec_insert_id();
    if (!$newId) {
        /* Il messaggio del database non torna al client: e' una mappa dello
           schema. Finisce nel log. */
        sec_log_error('pomodoro_storico', 'inserimento manuale non riuscito');
        echo json_encode(['success' => false, 'error' => t('pomodoro.storico_errore_salva')]);
        exit();
    }
    $row = sec_one("SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual, p.name AS progetto_nome, p.color AS progetto_colore
                     FROM pomodoro_sessions s LEFT JOIN projects p ON p.id = s.project_id
                     WHERE s.id = ? AND s.user_id = ?", [$newId, (int)$current_user_id]);
    echo json_encode(['success' => true, 'session' => ph_session_to_array($row, $current_user_id)], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: una sola sessione (serve ad aprire lo storico gia' in modifica su
   una riga precisa, anche se non e' nella prima pagina di risultati)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'one') {
    header('Content-Type: application/json');
    $id = sec_int($_POST['id'] ?? 0, 1);
    $row = sec_one("SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual,
                           p.name AS progetto_nome, p.color AS progetto_colore
                    FROM pomodoro_sessions s
                    LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                    WHERE s.id = ? AND s.user_id = ?", [$id, (int)$current_user_id]);
    if (!$row) { echo json_encode(['success' => false]); exit(); }
    echo json_encode(['success' => true, 'session' => ph_session_to_array($row, $current_user_id)], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: modifica di una sessione inserita a mano
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'manual_update') {
    header('Content-Type: application/json');
    $id = sec_int($_POST['id'] ?? 0, 1);

    /* Qualunque sessione sua si puo' correggere, misurata dal cronometro
       compresa: is_manual serve solo a raccontare la provenienza (badge
       "manuale"/"automatica"), non a bloccare la modifica. sec_owns() resta
       l'unico cancello, e non solo nel browser: il tasto si puo' far
       comparire con gli strumenti da sviluppatore, la query no. */
    if (!sec_owns('pomodoro_sessions', $id, (int)$current_user_id)) {
        echo json_encode(['success' => false]); exit();
    }

    $data      = sec_date($_POST['data'] ?? null, '');
    $oraInizio = sec_str($_POST['ora_inizio'] ?? '', 5);
    $oraFine   = sec_str($_POST['ora_fine'] ?? '', 5);
    $progettoId = sec_int($_POST['project_id'] ?? 0, 0);

    // stessa forma hh:mm dell'inserimento: le due strade devono validare uguale
    $formaOra = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    if ($data === '' || !preg_match($formaOra, $oraInizio) || !preg_match($formaOra, $oraFine)) {
        echo json_encode(['success' => false, 'error' => t('pomodoro.storico_compila')]); exit();
    }

    $startDt = $data . ' ' . $oraInizio . ':00';
    $endDt   = $data . ' ' . $oraFine . ':00';
    // fine prima dell'inizio = sessione a cavallo della mezzanotte
    if (strtotime($endDt) <= strtotime($startDt)) {
        $endDt = date('Y-m-d H:i:s', strtotime($endDt . ' +1 day'));
    }
    $durataSecondi = max(0, strtotime($endDt) - strtotime($startDt));

    $progId = ($progettoId > 0 && sec_owns('projects', $progettoId, (int)$current_user_id)) ? $progettoId : null;

    /* is_manual = 1 e status = 'archiviato' per qualunque sessione corretta
       qui, misurata dal cronometro compresa: da adesso gli orari li ha decisi
       l'utente. Vale anche per una chiusura d'ufficio (2, 'abbandonato') — il
       caso non e' piu' in sospeso, l'avviso non deve tornare a galla e il tag
       non deve piu' dire "automatica". */
    sec_q("UPDATE pomodoro_sessions
           SET start_time = ?, end_time = ?, duration_seconds = ?, planned_duration_min = ?,
               project_id = ?, status = 'archiviato', is_manual = 1
           WHERE id = ? AND user_id = ?",
          [$startDt, $endDt, $durataSecondi, (int)round($durataSecondi / 60), $progId,
           $id, (int)$current_user_id]);

    $row = sec_one("SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual,
                           p.name AS progetto_nome, p.color AS progetto_colore
                    FROM pomodoro_sessions s
                    LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                    WHERE s.id = ? AND s.user_id = ?", [$id, (int)$current_user_id]);
    echo json_encode(['success' => true, 'session' => ph_session_to_array($row, $current_user_id)], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: il pannello di panoramica in cima al popup — giorno/settimana/mese,
   il confronto con ieri, l'ora in cui ci si concentra di piu', la serie di
   giorni consecutivi, il progetto della settimana.

   Sempre sull'intera storia dell'utente, MAI sul filtro attivo nell'elenco
   sotto: "quanti pomodori oggi" o "il progetto di questa settimana" non
   hanno senso ristretti a un progetto scelto a caso nel filtro, sono per
   definizione un quadro d'insieme. Se un domani servisse anche filtrato,
   e' un parametro in piu' da aggiungere qui — non e' questo il momento di
   deciderlo da soli.
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'insights') {
    header('Content-Type: application/json');
    $uid  = (int)$current_user_id;
    $oggi = date('Y-m-d');
    $ieri = date('Y-m-d', strtotime('-1 day'));

    /* ---- oggi, diviso per progetto: il grafico a torta ---- */
    $oggiProgetti = [];
    foreach (sec_all("SELECT s.project_id, p.name AS nome, p.color AS colore, SUM(s.duration_seconds) AS secondi
                       FROM pomodoro_sessions s
                       LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND DATE(s.start_time) = ?
                       GROUP BY s.project_id
                       ORDER BY secondi DESC", [$uid, $oggi]) as $row) {
        $oggiProgetti[] = ['nome' => $row['nome'] ?: null, 'colore' => $row['colore'] ?: '#8A8A8E', 'secondi' => (int)$row['secondi']];
    }

    /* ---- gli ultimi sette giorni, per progetto: il grafico a barre.
       Sette voci sempre, anche per i giorni senza niente: una barra mancante
       si leggerebbe come un errore di caricamento, non come "zero quel
       giorno". Il giorno e' quello di INIZIO — mai s.end_time, altrimenti un
       pomodoro cominciato alle 23:50 conterebbe sul giorno sbagliato: e' la
       stessa regola che il JavaScript usa gia' per raggruppare l'elenco. */
    $settimana = [];
    $chiaviSettimana = [];
    for ($i = 6; $i >= 0; $i--) {
        $k = date('Y-m-d', strtotime("-$i day"));
        $chiaviSettimana[] = $k;
        $settimana[$k] = ['giorno' => $k, 'progetti' => [], 'secondi' => 0];
    }
    $inizioSettimana = $chiaviSettimana[0] . ' 00:00:00';
    foreach (sec_all("SELECT DATE(s.start_time) AS giorno, s.project_id, p.color AS colore, SUM(s.duration_seconds) AS secondi
                       FROM pomodoro_sessions s
                       LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND s.start_time >= ?
                       GROUP BY DATE(s.start_time), s.project_id", [$uid, $inizioSettimana]) as $row) {
        $g = $row['giorno'];
        if (!isset($settimana[$g])) continue; // fuori dai sette giorni attesi: si scarta, non dovrebbe capitare
        $sec = (int)$row['secondi'];
        $settimana[$g]['progetti'][] = ['colore' => $row['colore'] ?: '#8A8A8E', 'secondi' => $sec];
        $settimana[$g]['secondi'] += $sec;
    }
    $settimana = array_values($settimana);

    /* ---- il progetto che ha avuto piu' tempo negli ultimi sette giorni:
       stessa finestra del grafico sopra, ma raggruppata per progetto invece
       che per giorno — una query separata, piu' chiara di riaggregare qui in
       PHP i risultati di quella di sopra. */
    $progettoSettimana = null;
    $rigaPs = sec_one("SELECT s.project_id, p.name AS nome, p.color AS colore, SUM(s.duration_seconds) AS secondi
                        FROM pomodoro_sessions s
                        LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                        WHERE s.user_id = ? AND s.end_time IS NOT NULL AND s.start_time >= ? AND s.project_id IS NOT NULL
                        GROUP BY s.project_id
                        ORDER BY secondi DESC
                        LIMIT 1", [$uid, $inizioSettimana]);
    if ($rigaPs) $progettoSettimana = ['nome' => $rigaPs['nome'], 'colore' => $rigaPs['colore'] ?: '#8A8A8E', 'secondi' => (int)$rigaPs['secondi']];

    /* ---- il mese in corso, giorno per giorno: la heatmap ---- */
    $primoDelMese    = date('Y-m-01');
    $primoMesePross  = date('Y-m-01', strtotime('+1 month'));
    $giorniMese = [];
    foreach (sec_all("SELECT DATE(s.start_time) AS giorno, SUM(s.duration_seconds) AS secondi, COUNT(*) AS n
                       FROM pomodoro_sessions s
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND s.start_time >= ? AND s.start_time < ?
                       GROUP BY DATE(s.start_time)", [$uid, $primoDelMese . ' 00:00:00', $primoMesePross . ' 00:00:00']) as $row) {
        $giorniMese[$row['giorno']] = ['secondi' => (int)$row['secondi'], 'n' => (int)$row['n']];
    }

    /* ---- oggi contro ieri: il confronto ---- */
    $confronto = ['oggi' => ['n' => 0, 'secondi' => 0], 'ieri' => ['n' => 0, 'secondi' => 0]];
    foreach (sec_all("SELECT DATE(s.start_time) AS giorno, COUNT(*) AS n, SUM(s.duration_seconds) AS secondi
                       FROM pomodoro_sessions s
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND DATE(s.start_time) IN (?, ?)
                       GROUP BY DATE(s.start_time)", [$uid, $oggi, $ieri]) as $row) {
        $confronto[$row['giorno'] === $oggi ? 'oggi' : 'ieri'] = ['n' => (int)$row['n'], 'secondi' => (int)$row['secondi']];
    }

    /* ---- come si distribuisce il tempo nelle ventiquattro ore del giorno:
       ultimi 90 giorni, non tutta la storia — racconta come si lavora ADESSO,
       non anni fa. Le ventiquattro fasce sempre tutte presenti (anche a
       zero), cosi' il grafico non deve indovinare quali ore mancano. */
    $oreDistribuzione = array_fill(0, 24, 0);
    foreach (sec_all("SELECT HOUR(s.start_time) AS ora, SUM(s.duration_seconds) AS secondi
                       FROM pomodoro_sessions s
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND s.start_time >= ?
                       GROUP BY HOUR(s.start_time)", [$uid, date('Y-m-d 00:00:00', strtotime('-90 day'))]) as $row) {
        $oreDistribuzione[(int)$row['ora']] = (int)$row['secondi'];
    }
    $oraTop = null;
    $picco = max($oreDistribuzione);
    if ($picco > 0) $oraTop = array_search($picco, $oreDistribuzione, true);

    /* ---- la serie di giorni consecutivi: si cammina all'indietro dai giorni
       con almeno un pomodoro. Da oggi, se oggi ne ha gia' uno; da ieri
       altrimenti — un giorno non "rompe" la serie finche' non e' passato per
       intero. E' un ciclo, non un'aggregazione: si fa in PHP, non in SQL, che
       su un hosting condiviso resta piu' semplice e piu' robusto. */
    $giorniConSessioni = [];
    foreach (sec_all("SELECT DISTINCT DATE(s.start_time) AS giorno
                       FROM pomodoro_sessions s
                       WHERE s.user_id = ? AND s.end_time IS NOT NULL AND s.start_time >= ?
                       ORDER BY giorno DESC", [$uid, date('Y-m-d 00:00:00', strtotime('-400 day'))]) as $row) {
        $giorniConSessioni[$row['giorno']] = true;
    }
    $serieGiorni = 0;
    $cursore = isset($giorniConSessioni[$oggi]) ? strtotime($oggi) : strtotime($ieri);
    while (isset($giorniConSessioni[date('Y-m-d', $cursore)])) {
        $serieGiorni++;
        $cursore = strtotime('-1 day', $cursore);
    }

    echo json_encode([
        'success'           => true,
        'oggi_progetti'     => $oggiProgetti,
        'settimana'         => $settimana,
        'progetto_settimana'=> $progettoSettimana,
        'mese'              => ['primo_giorno' => $primoDelMese, 'giorni_nel_mese' => (int)date('t'), 'oggi' => $oggi, 'valori' => $giorniMese],
        'confronto'         => $confronto,
        'ore_distribuzione' => $oreDistribuzione,
        'ora_top'           => $oraTop,
        'serie_giorni'      => $serieGiorni,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ==========================================================================
   MARKUP DEL POPUP (vuoto: viene riempito via fetch quando si apre)
   ========================================================================== */
if (!isset($ph_id)) $ph_id = 'default';
$ph_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$ph_id);
?>
<?php if (!defined('PH_ASSETS_EMITTED')): define('PH_ASSETS_EMITTED', true); ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>projects/pomodoro_storico.css?v=<?php echo @filemtime(__DIR__ . '/pomodoro_storico.css') ?: 1; ?>">
<?php endif; ?>

<div class="ph-overlay" id="phOverlay">
    <div class="ph-sheet">
        <div class="ph-head">
            <div class="ph-title" id="phTitle"><?php te('progetti.pomodori'); ?></div>
            <div class="ph-head-btns">
                <button type="button" class="ph-icon-btn" id="phSelectBtn" onclick="phToggleSelectMode()" aria-label="<?php te('pomodoro.ph_seleziona'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="phToggleManual()" aria-label="<?php te('pomodoro.ph_aggiungi_manuale'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="openPomodoroImport()" aria-label="<?php te('pomodoro.ph_importa'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="exportPomodoroFile()" aria-label="<?php te('pomodoro.ph_esporta'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 9l5-5 5 5"/><path d="M12 4v12"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="closePomodoroStorico()" aria-label="<?php te('comune.chiudi'); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <div class="ph-filters">
            <button type="button" class="ph-filter-chip active" data-sort="recent" onclick="phSetSort('recent')"><?php te('pomodoro.storico_recenti'); ?></button>
            <button type="button" class="ph-filter-chip" data-sort="oldest" onclick="phSetSort('oldest')"><?php te('pomodoro.storico_vecchi'); ?></button>
            <button type="button" class="ph-filter-chip" data-sort="duration" onclick="phSetSort('duration')"><?php te('pomodoro.durata'); ?></button>
            <button type="button" class="ph-filter-chip" id="phDateFilterChip" onclick="phToggleDateFilter()"><?php te('pomodoro.storico_periodo'); ?></button>
            <button type="button" class="ph-filter-chip" id="phProjectFilterChip" onclick="phOpenProjectFilter()"><?php te('pomodoro.ph_progetto_tutti'); ?></button>
        </div>
        <div class="ph-date-range" id="phDateRange">
            <div class="ph-date-field"><label><?php te('pomodoro.storico_dal'); ?></label><input type="date" id="phDateFrom" onchange="phApplyDateFilter()"></div>
            <div class="ph-date-field"><label>Al</label><input type="date" id="phDateTo" onchange="phApplyDateFilter()"></div>
            <button type="button" class="ph-date-clear" onclick="phClearDateFilter()" aria-label="<?php te('pomodoro.ph_azzera_periodo'); ?>"><?php te('pomodoro.storico_azzera'); ?></button>
        </div>

        <div class="ph-bulkbar" id="phBulkbar">
            <span id="phBulkCount"><?php te('pomodoro.storico_n_selezionati', ['n' => 0]); ?></span>
            <button type="button" class="ph-bulk-btn accent" id="phSelectAllBtn" onclick="phSelectAll()"><?php te('pomodoro.storico_seleziona_tutti'); ?></button>
            <button type="button" class="ph-bulk-btn" onclick="phBulkAction('unlink')"><?php te('pomodoro.storico_scollega'); ?></button>
            <button type="button" class="ph-bulk-btn" onclick="phOpenBulkProjectPicker()"><?php te('pomodoro.storico_assegna'); ?></button>
            <!-- Era scritto con la sintassi dei template JavaScript dentro
                 all'HTML generato da PHP: li' nessuno la interpreta, quindi
                 sul tasto compariva la riga di codice. Qui il testo lo mette
                 PHP, come su tutti gli altri tasti di questa barra. -->
            <button type="button" class="ph-bulk-btn danger" onclick="phBulkAction('delete')"><?php te('comune.elimina'); ?></button>
        </div>

        <!-- Lo stesso pannello serve per inserire e per modificare: sono gli
             stessi campi, e due pannelli gemelli si sarebbero disallineati alla
             prima aggiunta. Cambiano solo il titolo e i tasti in fondo. -->
        <div class="ph-manual-panel" id="phManualPanel">
            <div class="ph-manual-title" id="phManualTitle" style="display:none;"><?php te('pomodoro.storico_modifica_titolo'); ?></div>
            <div class="ph-manual-row">
                <div><label><?php te('comune.data'); ?></label><input type="date" id="phManualData"></div>
            </div>
            <div class="ph-manual-row">
                <div><label><?php te('pomodoro.storico_inizio'); ?></label><input type="time" id="phManualInizio" oninput="phManualCalc('inizio')"></div>
                <div><label><?php te('pomodoro.storico_fine'); ?></label><input type="time" id="phManualFine" oninput="phManualCalc('fine')"></div>
            </div>
            <div class="ph-manual-row ph-manual-row-durata">
                <div><label><?php te('pomodoro.durata'); ?></label><input type="time" id="phManualDurata" onfocus="if(!this.value) this.value='00:00';" onblur="window.phDurataTarget=null;" oninput="phManualCalc('durata')"></div>
            </div>
            <div><label><?php te('pomodoro.storico_progetto_opz'); ?></label>
                <?php
                    $ps_id = 'manual-' . $ph_uid; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
                    $ps_placeholder = T('pomodoro.storico_nessun_progetto'); $ps_title = T('pomodoro.storico_collega');
                    $ps_show_all_option = true; $ps_all_option_label = T('pomodoro.storico_nessun_progetto'); $ps_allow_create = false;
                    $ps_hidden_name = 'manual_project_id';
                    $ps_trigger_class = 'ps-trigger-pill';
                    include __DIR__ . '/project_selector.php';
                ?>
            </div>
            <div class="ph-manual-btns">
                <button type="button" class="ph-manual-cancel" id="phManualCancel" style="display:none;" onclick="phCancelEdit()"><?php te('comune.annulla'); ?></button>
                <button type="button" class="ph-manual-save" id="phManualSave" onclick="phSubmitManual()"><?php te('pomodoro.storico_salva_sessione'); ?></button>
            </div>
        </div>

        <div class="ph-list-scroll" id="phListScroll">
            <?php /* Il pannello di panoramica vive QUI dentro, non sopra ai
                     filtri: deve scorrere via insieme all'elenco, non restare
                     fisso in sovraimpressione. Vuoto apposta — lo riempie il
                     JavaScript quando il popup si apre, con dati sempre
                     freschi, come tutto il resto di questo file. */ ?>
            <div class="ph-insights" id="phInsights">
                <div class="ph-insights-track" id="phInsightsTrack"></div>
                <div class="ph-insights-dots" id="phInsightsDots"></div>
            </div>
            <div class="ph-empty-msg" id="phEmptyMsg" style="display:none;"><?php te('pomodoro.storico_nessuna'); ?></div>
        </div>
    </div>
</div>

<div class="ph-toast" id="phToast"></div>

<?php
    /* Popup di import/export: si apre dai due tasti in alto (openPomodoroImport
       ed exportPomodoroFile). Va incluso qui, dopo il selettore del pannello
       manuale, perche' porta con se' un secondo project_selector tutto suo. */
    include __DIR__ . '/pomodoro_import.php';
?>

<?php if (!defined('PH_SCRIPT_EMITTED')): define('PH_SCRIPT_EMITTED', true); ?>
<script>
(function () {
    'use strict';

    var state = { scope: 'all', projectIds: [], sort: 'recent', filterProject: 0, dateFrom: '', dateTo: '', offset: 0, hasMore: true, loading: false, selecting: false, selectedIds: {}, lastDay: null, dayTotals: {} };

    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }
    function toast(msg){
        var t = document.getElementById('phToast');
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._timer); t._timer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
    }
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    // costruisce il contenuto del tag progetto: "nonno • padre • progetto" (ultimo in grassetto)
    function phProjectTagInner(s){
        if (!s.project_id) return '<span style="text-decoration:underline;">' + T('pomodoro.storico_collega_breve') + '</span>';
        var path = (s.progetto_path && s.progetto_path.length) ? s.progetto_path : [s.progetto_nome];
        var html = '<span class="dot"></span>';
        for (var i = 0; i < path.length; i++) {
            var isLeaf = (i === path.length - 1);
            if (i > 0) html += '<span class="ph-pt-sep">•</span>';
            html += '<span class="' + (isLeaf ? 'ph-pt-leaf' : 'ph-pt-anc') + '">' + esc(path[i]) + '</span>';
        }
        return html;
    }
    // stesso contenuto ma a partire da nome+path grezzi (usato dopo aver ri-collegato una sessione)
    function phProjectTagInnerRaw(name, path){
        return phProjectTagInner({ project_id: name ? 1 : 0, progetto_nome: name, progetto_path: path || [] });
    }

    function fmtTime(dt){ var d = new Date(dt.replace(' ', 'T')); return d.toLocaleTimeString((document.documentElement.lang || 'it'), {hour:'2-digit', minute:'2-digit'}); }
    // il totale accanto al nome del giorno: letto da state.dayTotals, mai
    // ricalcolato dalle righe a schermo (che sono solo una pagina alla volta)
    function phGiornoTotaleHtml(dk){
        var sec = state.dayTotals ? state.dayTotals[dk] : null;
        if (!sec) return '';
        return ' <span class="ph-day-total">' + esc(fmtDurataBreve(sec)) + '</span>';
    }

    function fmtDayLabel(dt){
        var d = new Date(dt.replace(' ', 'T'));
        return d.toLocaleDateString((document.documentElement.lang || 'it'), { day:'numeric', month:'long', year: (d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined) });
    }
    function dayKey(dt){ return dt.slice(0,10); }

    /* Da dove vengono gli orari di quella riga. Niente tag per le sessioni
       misurate dal timer: sono la normalita' e un tag su ogni riga sarebbe
       rumore. "Automatica" e' quella che il server ha chiuso d'ufficio dopo il
       limite: dice all'utente che quegli orari sono una stima, ed e' il motivo
       per cui la si puo' correggere. */
    function phBadgeOrigine(manuale){
        manuale = parseInt(manuale, 10) || 0;
        if (manuale === 1) return '<span class="ph-manual-badge">' + T('pomodoro.storico_badge_manuale') + '</span>';
        if (manuale === 2) return '<span class="ph-manual-badge ph-auto-badge">' + T('pomodoro.storico_badge_automatica') + '</span>';
        return '';
    }

    /* ==========================================================================
       IL PANNELLO DI PANORAMICA — giorno/settimana/mese, oggi e la serie
       insieme, l'ora in cui ci si concentra di piu', il progetto della
       settimana. Sei schede, costruite qui in JavaScript da un solo giro
       sul server (ph_ajax=insights): niente di tutto questo dipende dal
       filtro dell'elenco sotto, e' sempre il quadro intero.

       Vive dentro l'elenco che scorre (vedi il markup): scorrendo la lista
       verso il basso se ne va, non resta in sovraimpressione.
       ========================================================================== */
    function fmtDurataBreve(sec){
        sec = Math.max(0, parseInt(sec, 10) || 0);
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
    }
    // per il righello della settimana: piu' compatto di fmtDurataBreve, non
    // deve mai avere piu' di quattro caratteri o rompe la colonna stretta
    function phOreCompatte(sec){
        var h = sec / 3600;
        if (h >= 10) return Math.round(h) + 'h';
        if (h >= 1) return (Math.round(h * 10) / 10) + 'h';
        return Math.round(sec / 60) + 'm';
    }
    // un colore che arriva dal database, ma nel dubbio non fidarsi: finisce
    // in uno style attribute e in un attributo SVG, non in testo
    function phColoreSicuro(c){
        return (typeof c === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(c)) ? c : '#8A8A8E';
    }

    // ---- giorno: la torta di oggi per progetto, col progetto principale a fianco ----
    function phCardGiorno(righe){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_giorno')) + '</div>';
        righe = righe || [];
        var totale = 0;
        righe.forEach(function(r){ totale += r.secondi; });
        if (!totale) {
            card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-insight-empty">' + esc(T('pomodoro.storico_oggi_vuoto')) + '</div></div>';
            return card;
        }
        var C = 2 * Math.PI * 34, cum = 0, archi = '';
        righe.forEach(function(r){
            var len = C * (r.secondi / totale);
            var colore = phColoreSicuro(r.colore);
            archi += '<circle class="ph-pie-seg" cx="40" cy="40" r="34" stroke="' + colore + '" '
                + 'stroke-dasharray="' + len.toFixed(2) + ' ' + (C - len).toFixed(2) + '" '
                + 'stroke-dashoffset="' + (-cum).toFixed(2) + '"></circle>';
            cum += len;
        });
        var primo = righe[0];
        var nomePrimo = primo.nome || T('pomodoro.storico_nessun_progetto');
        var sottotitolo = righe.length > 1 ? T('pomodoro.storico_torta_e_altri', { progetto: nomePrimo, n: righe.length - 1 }) : nomePrimo;
        card.innerHTML = label + '<div class="ph-insight-body">'
            + '<div class="ph-pie-wrap"><svg viewBox="0 0 80 80"><circle class="ph-pie-track" cx="40" cy="40" r="34"></circle>' + archi + '</svg>'
            + '<div class="ph-pie-center"><b>' + esc(fmtDurataBreve(totale)) + '</b></div></div>'
            + '<div class="ph-pie-caption">' + esc(sottotitolo) + '</div>'
            + '</div>';
        return card;
    }

    // ---- settimana: le barre impilate per progetto, col righello delle ore ----
    function phCardSettimana(giorni){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_settimana')) + '</div>';
        giorni = giorni || [];
        var totale = 0, max = 0;
        giorni.forEach(function(g){ totale += g.secondi; if (g.secondi > max) max = g.secondi; });
        if (!totale) {
            card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-insight-empty">' + esc(T('pomodoro.storico_settimana_vuota')) + '</div></div>';
            return card;
        }
        var oggi = phTodayStr(), cols = '';
        giorni.forEach(function(g){
            /* Altezze in PERCENTUALE, non in pixel: la scheda ora e' alta
               meta' del popup e cambia da schermo a schermo, quindi un
               numero fisso lascerebbe il grafico schiacciato in alto con
               tutto lo spazio sotto vuoto — che e' esattamente il difetto
               che si vedeva. Cosi' invece riempie sempre quello che c'e'. */
            var altColonna = max > 0 ? (g.secondi / max) * 100 : 0;
            var segs = '';
            (g.progetti || []).forEach(function(p){
                // ogni segmento e' una quota della SUA colonna, non del massimo
                var quota = g.secondi > 0 ? (p.secondi / g.secondi) * 100 : 0;
                segs += '<span class="ph-bar-seg" style="height:' + quota.toFixed(2) + '%;background:' + phColoreSicuro(p.colore) + '"></span>';
            });
            var d = new Date(g.giorno + 'T00:00:00');
            var wd = d.toLocaleDateString(T.lingua === 'en' ? 'en-US' : 'it-IT', { weekday: 'short' }).replace('.', '');
            var isToday = g.giorno === oggi;
            cols += '<div class="ph-bar-col">'
                + '<div class="ph-bar-track"><div class="ph-bar-stack" style="height:' + Math.max(1.5, altColonna).toFixed(2) + '%">' + segs + '</div></div>'
                + '<div class="ph-bar-day' + (isToday ? ' is-today' : '') + '">' + esc(wd) + '</div></div>';
        });
        card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-week-wrap">'
            + '<div class="ph-week-axis"><span>' + esc(phOreCompatte(max)) + '</span><span>' + esc(phOreCompatte(max / 2)) + '</span><span>0</span></div>'
            + '<div class="ph-bars">' + cols + '</div>'
            + '</div></div>';
        return card;
    }

    // ---- mese: la heatmap ----
    function phCardMese(mese){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_mese')) + '</div>';
        mese = mese || {};
        var valori = mese.valori || {};
        var chiavi = Object.keys(valori);
        if (!chiavi.length) {
            card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-insight-empty">' + esc(T('pomodoro.storico_mese_vuoto')) + '</div></div>';
            return card;
        }
        var max = 0;
        chiavi.forEach(function(k){ if (valori[k].secondi > max) max = valori[k].secondi; });
        var primo = new Date(mese.primo_giorno + 'T00:00:00');
        var offset = (primo.getDay() + 6) % 7; // lunedi=0 ... domenica=6, per allineare la griglia
        var celle = '';
        for (var i = 0; i < offset; i++) celle += '<div class="ph-heat-cell is-empty"></div>';
        for (var g = 1; g <= (mese.giorni_nel_mese || 30); g++) {
            var k = mese.primo_giorno.slice(0, 8) + (g < 10 ? '0' : '') + g;
            var v = valori[k];
            var isToday = k === mese.oggi;
            var stile = '';
            if (v && max > 0) {
                var alpha = Math.max(.16, v.secondi / max);
                stile = ' style="background:color-mix(in srgb, var(--blue,#007AFF) ' + Math.round(alpha * 100) + '%, var(--hairline,#e4e4e6))"';
            }
            celle += '<div class="ph-heat-cell' + (isToday ? ' is-today' : '') + '"' + stile + '></div>';
        }
        card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-heat">' + celle + '</div></div>';
        return card;
    }

    // ---- oggi e la serie insieme: due meta' della stessa scheda ----
    // ---- oggi: quanti pomodori, il confronto con ieri scritto in parole (non
    // un numero colorato da solo, che non direbbe a cosa si riferisce) e in
    // fondo la serie di giorni consecutivi — senza etichetta, la fiamma e le
    // parole "giorni di fila" dicono gia' tutto
    function phCardOggi(confronto, serieGiorni){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_oggi')) + '</div>';
        confronto = confronto || {};
        var oggi = confronto.oggi || { n: 0, secondi: 0 };
        var ieri = confronto.ieri || { n: 0, secondi: 0 };
        var n = serieGiorni || 0;

        var body;
        if (!oggi.n) {
            body = '<div class="ph-insight-empty">' + esc(T('pomodoro.storico_oggi_vuoto')) + '</div>';
        } else {
            var diff = oggi.n - ieri.n, testo, classe;
            if (diff > 0) { testo = T('pomodoro.storico_oggi_in_piu', { n: diff }); classe = 'is-piu'; }
            else if (diff < 0) { testo = T('pomodoro.storico_oggi_in_meno', { n: Math.abs(diff) }); classe = 'is-meno'; }
            else { testo = T('pomodoro.storico_uguale_a_ieri'); classe = 'is-pari'; }
            body = '<div class="ph-oggi-num">' + oggi.n + '</div>'
                + '<div class="ph-insight-caption">' + esc(fmtDurataBreve(oggi.secondi)) + '</div>'
                + '<div class="ph-oggi-confronto ' + classe + '">' + esc(testo) + '</div>';
        }
        // la serie compare solo se c'e' davvero: uno zero con la fiamma
        // spenta sarebbe un rimprovero, non un'informazione
        var serie = n > 0
            ? '<div class="ph-oggi-serie"><span class="ph-oggi-fiamma">🔥</span>'
              + '<b>' + n + '</b> ' + esc(T(n === 1 ? 'pomodoro.storico_serie_unita_uno' : 'pomodoro.storico_serie_unita_altri')) + '</div>'
            : '';

        card.innerHTML = label + '<div class="ph-insight-body">' + body + '</div>' + serie;
        return card;
    }

    // ---- l'ora in cui ci si concentra di piu': ventiquattro fasce, non solo il picco ----
    function phCardOraTop(distribuzione, oraTop){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_ora_top')) + '</div>';
        distribuzione = distribuzione || [];
        var max = 0;
        for (var i = 0; i < distribuzione.length; i++) if (distribuzione[i] > max) max = distribuzione[i];
        if (!max) {
            card.innerHTML = label + '<div class="ph-insight-body"><div class="ph-insight-empty">' + esc(T('pomodoro.storico_ora_top_vuoto')) + '</div></div>';
            return card;
        }
        var barre = '';
        for (var h = 0; h < 24; h++) {
            var v = distribuzione[h] || 0;
            // percentuale, non pixel: vale lo stesso discorso delle barre
            // della settimana — la scheda cambia altezza da schermo a schermo
            var alt = Math.max(1.5, (v / max) * 100);
            var picco = (oraTop !== null && oraTop !== undefined && h === oraTop);
            barre += '<span class="ph-hour-bar' + (picco ? ' is-top' : '') + '" style="height:' + alt.toFixed(2) + '%"></span>';
        }
        var due = function(hh){ return (hh < 10 ? '0' : '') + hh + ':00'; };
        var didascalia = (oraTop !== null && oraTop !== undefined) ? (due(oraTop) + '–' + due((oraTop + 1) % 24)) : '';
        /* Tre riferimenti sotto l'asse invece di niente: ventiquattro barre
           senza nessun appiglio non si sa dove cadono nella giornata. */
        card.innerHTML = label + '<div class="ph-insight-body">'
            + '<div class="ph-hours">' + barre + '</div>'
            + '<div class="ph-hours-axis"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></div>'
            + '<div class="ph-insight-caption">' + esc(didascalia) + '</div>'
            + '</div>';
        return card;
    }

    // ---- il progetto che ha avuto piu' tempo questa settimana ----
    function phCardProgettoSettimana(p){
        var card = document.createElement('div'); card.className = 'ph-insight-card';
        var label = '<div class="ph-insight-label">' + esc(T('pomodoro.storico_panoramica_progetto_settimana')) + '</div>';
        var body;
        if (!p) {
            body = '<div class="ph-insight-empty">' + esc(T('pomodoro.storico_progetto_settimana_vuoto')) + '</div>';
        } else {
            var colore = phColoreSicuro(p.colore);
            body = '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + colore + ';margin-bottom:8px;"></span>'
                + '<div class="ph-insight-value" style="font-size:18px;">' + esc(p.nome || '') + '</div>'
                + '<div class="ph-insight-caption">' + esc(fmtDurataBreve(p.secondi)) + '</div>';
        }
        card.innerHTML = label + '<div class="ph-insight-body">' + body + '</div>';
        return card;
    }

    /* ---- lo scorrimento: nativo, non intercettato a mano. Per farlo sembrare
       senza inizio ne' fine si mette un clone dell'ultima scheda PRIMA della
       prima e un clone della prima scheda DOPO l'ultima: si scorre normalmente
       (a mano o da soli), e quando lo scorrimento si ferma su uno dei due
       cloni si salta senza animazione alla scheda vera corrispondente — un
       balzo invisibile perche' il clone e' identico a quello a cui si salta.
       Intercettare i gesti a mano sarebbe piu' fragile: qui il browser fa
       tutto il lavoro di scorrimento, noi guardiamo solo dove si e' fermato. ---- */
    var phInsightsTimer = null, phInsightsIdle = null;
    function phInsightsStep(track){
        var first = track.children[0];
        if (!first) return track.clientWidth || 1;
        var gap = parseFloat(getComputedStyle(track).columnGap || '12') || 12;
        return first.getBoundingClientRect().width + gap;
    }
    function phInsightsAvviaAuto(){
        phInsightsFermaAuto();
        phInsightsTimer = setInterval(function(){
            var track = document.getElementById('phInsightsTrack');
            if (!track || track.children.length < 3) return;   // meno di due schede vere: niente da scorrere
            var step = phInsightsStep(track);
            var next = Math.round(track.scrollLeft / step) + 1;
            track.scrollTo({ left: next * step, behavior: 'smooth' });
        }, 5000);
    }
    function phInsightsFermaAuto(){
        if (phInsightsTimer) { clearInterval(phInsightsTimer); phInsightsTimer = null; }
    }

    function phBuildInsights(data){
        var track = document.getElementById('phInsightsTrack');
        var dots = document.getElementById('phInsightsDots');
        if (!track || !dots) return;
        track.innerHTML = ''; dots.innerHTML = '';
        var cards = [
            phCardGiorno(data.oggi_progetti),
            phCardSettimana(data.settimana),
            phCardMese(data.mese),
            phCardOggi(data.confronto, data.serie_giorni),
            phCardOraTop(data.ore_distribuzione, data.ora_top),
            phCardProgettoSettimana(data.progetto_settimana)
        ];
        cards.forEach(function(c, i){
            track.appendChild(c);
            var d = document.createElement('span');
            d.className = 'ph-insights-dot' + (i === 0 ? ' active' : '');
            dots.appendChild(d);
        });
        if (cards.length > 1) {
            // i due cloni, uno per estremo: aria-hidden perche' raccontano la
            // stessa cosa della scheda vera, uno screen reader non deve leggerli due volte
            var primaClone = cards[0].cloneNode(true);
            var ultimaClone = cards[cards.length - 1].cloneNode(true);
            primaClone.setAttribute('aria-hidden', 'true');
            ultimaClone.setAttribute('aria-hidden', 'true');
            track.insertBefore(ultimaClone, track.firstChild);
            track.appendChild(primaClone);
            // si parte dalla prima vera: l'indice 0 in questo momento e' il
            // clone dell'ultima, la prima vera e' all'indice 1
            requestAnimationFrame(function(){ track.scrollLeft = phInsightsStep(track); });
        }
        phInsightsAvviaAuto();
    }

    function phLoadInsights(){
        fetch('pomodoro_storico.php?ph_ajax=insights', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: '' })
        .then(function(r){ return r.json(); })
        .then(function(data){ if (data && data.success) phBuildInsights(data); })
        .catch(function(){ /* il pannello resta vuoto: non e' critico quanto l'elenco sotto */ });
    }

    (function(){
        var track = document.getElementById('phInsightsTrack');
        if (!track) return;
        var settleTimer = null;

        function pallini(idxReale){
            document.querySelectorAll('.ph-insights-dot').forEach(function(d, i){ d.classList.toggle('active', i === idxReale); });
        }
        // a scorrimento fermo: se siamo su un clone, si salta senza
        // animazione alla scheda vera — invisibile perche' sono identiche
        function assestati(){
            var step = phInsightsStep(track);
            var totaliVeri = track.children.length - 2;
            if (!step || totaliVeri < 1) return;
            var pos = Math.round(track.scrollLeft / step);
            if (pos <= 0) { track.scrollLeft = totaliVeri * step; pos = totaliVeri; }
            else if (pos >= totaliVeri + 1) { track.scrollLeft = step; pos = 1; }
            pallini(pos - 1);
        }

        track.addEventListener('pointerdown', function(){ phInsightsFermaAuto(); clearTimeout(phInsightsIdle); });
        track.addEventListener('scroll', function(){
            var step = phInsightsStep(track);
            var totaliVeri = track.children.length - 2;
            if (step && totaliVeri > 0) {
                var pos = Math.max(0, Math.min(totaliVeri + 1, Math.round(track.scrollLeft / step)));
                pallini(Math.max(0, Math.min(totaliVeri - 1, pos - 1)));
            }
            // il salto vero e proprio solo a scorrimento fermo (90ms di
            // quiete): farlo mentre si sta ancora scorrendo litigherebbe
            // con il gesto in corso, a mano o con l'inerzia del tocco
            clearTimeout(settleTimer);
            settleTimer = setTimeout(assestati, 90);
            clearTimeout(phInsightsIdle);
            phInsightsIdle = setTimeout(phInsightsAvviaAuto, 6000);
        });
    })();

    window.openPomodoroStorico = function(config){
        config = config || {};
        state.scope = config.scope || 'all';
        state.projectIds = config.projectIds || [];
        // richiesta 11: ricorda il progetto di contesto per pre-selezionarlo nell'inserimento manuale
        state.contextProjectId = (config.scope === 'project' && config.projectId && !config.projectIds) ? config.projectId : 0;
        state.contextProjectName = config.projectName || '';
        state.contextProjectColor = config.projectColor || '';
        state.sort = 'recent'; state.filterProject = 0; state.dateFrom = ''; state.dateTo = ''; state.offset = 0; state.hasMore = true; state.selecting = false; state.selectedIds = {}; state.lastDay = null;
        /* Chi arriva dall'avviso "pomodoro non terminato" vuole andare dritto a
           correggere quella riga. Si segna qui e si esegue quando la prima
           pagina e' arrivata: prima non ci sarebbe niente su cui aprirsi. */
        state.pendingEdit = config.editSessionId || 0;
        /* Il tasto «avvia» esiste solo se sappiamo su cosa: con lo storico
           aperto su tutte le sessioni non c'è un progetto a cui legare il
           pomodoro nuovo. */
        state.goProject = (config.scope === 'project' && config.projectId) ? config.projectId : 0;
        document.getElementById('phTitle').textContent = config.scope === 'project' ? (T('pomodoro.storico_pomodori_sep') + (config.projectName || '')) : T('pomodoro.storico_tutti');
        document.querySelectorAll('.ph-filter-chip[data-sort]').forEach(function(c){ c.classList.toggle('active', c.getAttribute('data-sort') === 'recent'); });
        
        var projectFilterChip = document.getElementById('phProjectFilterChip');
        projectFilterChip.textContent = T('pomodoro.ph_progetto_tutti');
        projectFilterChip.style.display = config.scope === 'project' ? 'none' : ''; // Nasconde il tasto se siamo nel dettaglio progetto

        // richiesta 14: azzera la UI del filtro periodo ad ogni apertura
        var dr = document.getElementById('phDateRange'); if (dr) dr.classList.remove('show');
        var dchip = document.getElementById('phDateFilterChip'); if (dchip) { dchip.classList.remove('active'); dchip.textContent = T('pomodoro.storico_periodo'); }
        var dfrom = document.getElementById('phDateFrom'); if (dfrom) dfrom.value = '';
        var dto = document.getElementById('phDateTo'); if (dto) dto.value = '';
        
        document.getElementById('phBulkbar').classList.remove('show');
        document.getElementById('phManualPanel').classList.remove('show');
        phEditId = null; phSetPanelMode(); phAggiornaBloccoElenco();   // il pannello riparte sempre in inserimento
        document.getElementById('phSelectBtn').classList.remove('active');
        document.getElementById('phListScroll').classList.remove('ph-selecting'); // altrimenti le select box restano visibili alla riapertura
        document.getElementById('phListScroll').querySelectorAll('.ph-row-wrap, .ph-day-header, .ph-loadmore').forEach(function(el){ el.remove(); });

        if (state.scope === 'project' && config.projectId && !config.projectIds) {
            // se il chiamante non ha già passato l'elenco id, lo richiediamo (subtree)
            fetch('progetti.php?ajax=project_ids_subtree&id=' + config.projectId)
                .then(function(r){ return r.json(); })
                .then(function(data){ state.projectIds = data.ids || [config.projectId]; phLoadPage(true); })
                .catch(function(){ state.projectIds = [config.projectId]; phLoadPage(true); });
        } else {
            phLoadPage(true);
        }
        // il pannello di panoramica e' sempre lo stesso, indipendente dallo
        // scope: si carica una volta per apertura, non ad ogni cambio filtro
        phLoadInsights();
        document.getElementById('phOverlay').classList.add('show');
        document.documentElement.classList.add('sb-locked');
    };
    window.closePomodoroStorico = function(){
        document.getElementById('phOverlay').classList.remove('show');
        document.documentElement.classList.remove('sb-locked');
        if (state.selecting) window.phToggleSelectMode();
        // niente scorrimento automatico su un pannello che non si vede piu'
        phInsightsFermaAuto();
    };

    function phLoadPage(reset){
        if (state.loading) return;
        state.loading = true;
        if (reset) state.offset = 0;
        var body = 'scope=' + encodeURIComponent(state.scope) +
            '&project_ids=' + encodeURIComponent(state.projectIds.join(',')) +
            '&sort=' + encodeURIComponent(state.sort) +
            '&filter_project=' + encodeURIComponent(state.filterProject) +
            '&date_from=' + encodeURIComponent(state.dateFrom) +
            '&date_to=' + encodeURIComponent(state.dateTo) +
            '&offset=' + state.offset;
        fetch('pomodoro_storico.php?ph_ajax=list', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
        .then(function(r){ return r.json(); })
        .then(function(data){
            state.loading = false;
            if (!data.success) { toast(T('pomodoro.storico_errore_caricamento')); return; }
            var scroll = document.getElementById('phListScroll');
            if (reset) {
                // fondamentale: senza questo, ogni cambio di ordinamento/filtro RI-APPENDEVA le
                // stesse righe sopra quelle già presenti invece di sostituirle (bug #7)
                state.lastDay = null;
                // arriva solo con la prima pagina (vedi PHP): sui giorni non
                // dipende da quale pagina si sta guardando, si legge una volta
                state.dayTotals = data.day_totals || {};
                scroll.querySelectorAll('.ph-row-wrap, .ph-day-header, .ph-loadmore, .ph-go-riga').forEach(function(el){ el.remove(); });
                phMettiTastoAvvia(scroll);
            }
            var oldLoadMore = scroll.querySelector('.ph-loadmore'); if (oldLoadMore) oldLoadMore.remove();
            data.sessions.forEach(function(s){ phAppendRow(s); });
            state.offset += data.sessions.length;
            state.hasMore = data.has_more;
            document.getElementById('phEmptyMsg').style.display = (reset && data.sessions.length === 0) ? 'block' : 'none';
            if (state.hasMore) {
                var btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'ph-loadmore'; btn.textContent = T('pomodoro.storico_carica_altri');
                btn.onclick = function(){ phLoadPage(false); };
                scroll.appendChild(btn);
            }
            if (state.pendingEdit) { var pe = state.pendingEdit; state.pendingEdit = 0; phOpenEdit(pe); }
            /* Le righe appena disegnate non sanno di una modifica gia' in corso:
               "carica altri" a pannello aperto le farebbe tornare interattive. */
            if (phEditId !== null) phAggiornaBloccoElenco();
        })
        .catch(function(){ state.loading = false; toast(T('pomodoro.storico_errore_rete')); });
    }

    function phRemoveRows(ids){
        ids.forEach(function(id){
            var w = document.querySelector('.ph-row-wrap[data-id="'+id+'"]');
            if (w) w.remove();
        });
        phCleanupEmptyDayHeaders();
    }
    function phCleanupEmptyDayHeaders(){
        // se elimino l'unico pomodoro di un giorno resta l'intestazione di quel
        // giorno vuota: la togliamo controllando cosa la segue nella lista.
        var scroll = document.getElementById('phListScroll');
        var children = Array.prototype.slice.call(scroll.children);
        var lastDay = null;
        children.forEach(function(el, i){
            if (el.classList.contains('ph-day-header')) {
                var next = children[i + 1];
                if (!next || !next.classList.contains('ph-row-wrap')) el.remove();
                else lastDay = el.getAttribute('data-day');
            }
        });
        state.lastDay = lastDay; // cosi' "carica altri" non salta l'intestazione del prossimo giorno
        if (!scroll.querySelector('.ph-row-wrap') && !state.hasMore) {
            document.getElementById('phEmptyMsg').style.display = 'block';
        }
    }

    function phAppendRow(s){
        var scroll = document.getElementById('phListScroll');
        var dk = dayKey(s.data_inizio);
        if (dk !== state.lastDay) {
            state.lastDay = dk;
            var head = document.createElement('div');
            head.className = 'ph-day-header';
            head.setAttribute('data-day', dk);
            head.innerHTML = esc(fmtDayLabel(s.data_inizio)) + phGiornoTotaleHtml(dk);
            scroll.appendChild(head);
        }
        var wrap = document.createElement('div');
        wrap.className = 'ph-row-wrap' + (state.selecting && state.selectedIds[s.id] ? ' selected' : '');
        wrap.setAttribute('data-id', s.id);
        /* Gli attributi qui sotto sono quello che serve al pannello di modifica.
           Si scrivono ora, che i dati ci sono gia': altrimenti al tocco del
           tasto bisognerebbe rileggere gli orari dal testo della riga, che e'
           formattato per essere letto da un umano e non per tornare indietro. */
        wrap.setAttribute('data-manual', s.manuale || 0);
        wrap.setAttribute('data-inizio', s.data_inizio || '');
        wrap.setAttribute('data-fine', s.data_fine || '');
        wrap.setAttribute('data-project-id', s.project_id || 0);
        wrap.setAttribute('data-project-name', s.progetto_nome || '');
        wrap.setAttribute('data-project-color', s.progetto_colore || '');
        wrap.style.setProperty('--row-color', s.progetto_colore || '#8A8A8E');

        /* Il tasto modifica c'e' per le sessioni scritte a mano (1) e per
           quelle chiuse d'ufficio (2). Su una misurata dal cronometro (0) no:
           correggerne gli orari vorrebbe dire riscrivere una misura. Il server
           ricontrolla comunque. */
        // Ogni sessione si puo' correggere adesso, misurata dal cronometro
        // compresa: non solo quelle scritte a mano.
        var azioni = '<button type="button" class="ph-swipe-btn ph-swipe-edit" data-edit-id="' + s.id + '">'
            + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>'
            + '<span>' + T('comune.modifica') + '</span></button>';
        azioni += '<button type="button" class="ph-swipe-btn" data-delete-id="' + s.id + '">'
            + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/></svg>'
            + '<span>' + T('comune.elimina') + '</span></button>';

        wrap.innerHTML =
            '<div class="ph-row">' +
                '<div class="ph-row-content">' +
                    '<span class="ph-select-chk">✓</span>' +
                    '<span class="ph-mode-icon">' + (s.mode === 1
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 3"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="13" r="8"/><path d="M9 2h6"/></svg>') +
                    '</span>' +
                    '<div class="ph-info">' +
                        '<div class="ph-time-range">' + fmtTime(s.data_inizio) + ' – ' + fmtTime(s.data_fine) + phBadgeOrigine(s.manuale) + '</div>' +
                        '<div class="ph-project-tag" data-project-tag>' + phProjectTagInner(s) + '</div>' +
                    '</div>' +
                    '<span class="ph-duration">' + s.durata_fmt + '</span>' +
                '</div>' +
                '<div class="ph-swipe-actions">' + azioni + '</div>' +
            '</div>';
        scroll.appendChild(wrap);
        return wrap;
    }

    /* ---------------- ordinamento / filtro progetto ---------------- */
    window.phSetSort = function(sort){
        state.sort = sort;
        document.querySelectorAll('.ph-filter-chip[data-sort]').forEach(function(c){ c.classList.toggle('active', c.getAttribute('data-sort') === sort); });
        phLoadPage(true);
    };
    window.phToggleDateFilter = function(){
        var dr = document.getElementById('phDateRange');
        if (dr) dr.classList.toggle('show');
    };
    window.phApplyDateFilter = function(){
        state.dateFrom = document.getElementById('phDateFrom').value || '';
        state.dateTo = document.getElementById('phDateTo').value || '';
        var chip = document.getElementById('phDateFilterChip');
        var active = !!(state.dateFrom || state.dateTo);
        if (chip) {
            chip.classList.toggle('active', active);
            chip.textContent = active ? phDateChipLabel() : T('pomodoro.storico_periodo');
        }
        phLoadPage(true);
    };
    function phDateChipLabel(){
        function sh(d){ if (!d) return '…'; var p = d.split('-'); return p[2] + '/' + p[1]; }
        return 'Periodo: ' + sh(state.dateFrom) + '–' + sh(state.dateTo);
    }
    window.phClearDateFilter = function(){
        document.getElementById('phDateFrom').value = '';
        document.getElementById('phDateTo').value = '';
        state.dateFrom = ''; state.dateTo = '';
        var chip = document.getElementById('phDateFilterChip');
        if (chip) { chip.classList.remove('active'); chip.textContent = T('pomodoro.storico_periodo'); }
        phLoadPage(true);
    };
    window.phOpenProjectFilter = function(){
        // riusa lo stesso project_selector incluso per l'inserimento manuale (evita di
        // duplicare l'intero componente): gli cambiamo solo etichetta/titolo al volo,
        // dato che qui serve "tutti i progetti" invece di "nessun progetto".
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo sec_h($ph_uid); ?>');
        if (!manualOverlay) return;
        var titleEl = manualOverlay.querySelector('.ps-sheet-title');
        var allBtn = manualOverlay.querySelector('.ps-all-option');
        if (titleEl) titleEl.textContent = T('pomodoro.storico_filtra_progetto');
        if (allBtn) { allBtn.textContent = T('pomodoro.storico_tutti_progetti'); allBtn.setAttribute('data-ps-name', T('pomodoro.storico_tutti_progetti')); }
        // richiesta 10: aggiungi (una sola volta) l'opzione "Nessun progetto collegato"
        if (allBtn && !manualOverlay.querySelector('.ph-filter-none-option')) {
            var noneBtn = document.createElement('button');
            noneBtn.type = 'button';
            noneBtn.className = 'ps-all-option ph-filter-none-option';
            noneBtn.textContent = T('pomodoro.storico_senza_progetto');
            noneBtn.addEventListener('click', function(){
                state.filterProject = -1;
                document.getElementById('phProjectFilterChip').textContent = T('pomodoro.ph_progetto_nessuno');
                phFilterPickMode = false;
                if (window.psClose) window.psClose(manualOverlay);
                phLoadPage(true);
            });
            allBtn.parentNode.insertBefore(noneBtn, allBtn.nextSibling);
        }
        // mostra l'opzione "nessuno" solo in modalità filtro (non durante l'inserimento manuale)
        var noneOpt = manualOverlay.querySelector('.ph-filter-none-option');
        if (noneOpt) noneOpt.style.display = '';
        window.psOpen(manualOverlay);
        phFilterPickMode = true;
    };
    var phFilterPickMode = false;
    document.addEventListener('projectselected', function(e){
        if (e.detail.uid !== 'manual-<?php echo sec_h($ph_uid); ?>') return; // solo dal selettore di questo popup
        if (!phFilterPickMode) return;
        phFilterPickMode = false;
        state.filterProject = e.detail.id; // 0 = "tutti" (nessun filtro), N = progetto specifico
        document.getElementById('phProjectFilterChip').textContent = e.detail.id > 0 ? T('pomodoro.ph_progetto_nome', { nome: e.detail.name }) : T('pomodoro.ph_progetto_tutti');
        phLoadPage(true);
    });

    /* ---------------- selezione multipla ---------------- */
    window.phToggleSelectMode = function(){
        state.selecting = !state.selecting;
        state.selectedIds = {};
        document.getElementById('phListScroll').classList.toggle('ph-selecting', state.selecting);
        document.getElementById('phSelectBtn').classList.toggle('active', state.selecting);
        document.getElementById('phBulkbar').classList.toggle('show', state.selecting);
        phUpdateBulkCount();
        document.querySelectorAll('.ph-row-wrap.selected').forEach(function(w){ w.classList.remove('selected'); });
    };
    function phUpdateBulkCount(){
        var n = Object.keys(state.selectedIds).length;
        document.getElementById('phBulkCount').textContent = T(n === 1 ? 'pomodoro.storico_n_selezionato' : 'pomodoro.storico_n_selezionati', {n: n});
        var btn = document.getElementById('phSelectAllBtn');
        if (btn) btn.textContent = n > 0 ? T('pomodoro.storico_deseleziona') : T('pomodoro.storico_seleziona_tutti');
    }
    window.phSelectAll = function(){
        // e' un tasto a due stati: se c'e' gia' qualcosa di selezionato (tutto o
        // solo in parte) il secondo tap pulisce, non ri-seleziona da capo.
        if (Object.keys(state.selectedIds).length > 0) {
            state.selectedIds = {};
            document.querySelectorAll('.ph-row-wrap.selected').forEach(function(w){ w.classList.remove('selected'); });
            phUpdateBulkCount();
            buzz(6);
            return;
        }
        // seleziona TUTTO cio' che rispetta il filtro attivo, non solo le righe
        // gia' caricate a schermo: serve apposta per riassegnare in blocco anche
        // liste lunghe senza dover premere "carica altri" decine di volte.
        var body = 'scope=' + encodeURIComponent(state.scope) +
            '&project_ids=' + encodeURIComponent(state.projectIds.join(',')) +
            '&filter_project=' + encodeURIComponent(state.filterProject) +
            '&date_from=' + encodeURIComponent(state.dateFrom) +
            '&date_to=' + encodeURIComponent(state.dateTo);
        fetch('pomodoro_storico.php?ph_ajax=all_ids', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success) { toast(T('pomodoro.storico_errore_selezione')); return; }
            state.selectedIds = {};
            data.ids.forEach(function(id){ state.selectedIds[id] = true; });
            document.querySelectorAll('.ph-row-wrap').forEach(function(w){
                w.classList.toggle('selected', !!state.selectedIds[w.getAttribute('data-id')]);
            });
            phUpdateBulkCount();
            buzz(10);
        })
        .catch(function(){ toast(T('pomodoro.storico_errore_rete')); });
    };
    window.phBulkAction = function(op){
        var ids = Object.keys(state.selectedIds);
        if (!ids.length) { toast(T('pomodoro.storico_serve_sessione')); return; }
        fetch('pomodoro_storico.php?ph_ajax=bulk_action', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'ids=' + ids.join(',') + '&bulk_op=' + op })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success) { toast(T('pomodoro.storico_errore')); return; }
            if (op === 'delete') phRemoveRows(ids);
            if (op === 'unlink') { ids.forEach(function(id){ var tag = document.querySelector('.ph-row-wrap[data-id="'+id+'"] [data-project-tag]'); if (tag) tag.innerHTML = '<span style="text-decoration:underline;">' + T('pomodoro.storico_collega_breve') + '</span>'; }); }
            toast(T('pomodoro.storico_fatto'));
            window.phToggleSelectMode();
        }).catch(function(){ toast(T('pomodoro.storico_errore_rete')); });
    };
    function phResetPickerLabels(){
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo sec_h($ph_uid); ?>');
        if (!manualOverlay) return;
        var titleEl = manualOverlay.querySelector('.ps-sheet-title');
        var allBtn = manualOverlay.querySelector('.ps-all-option');
        if (titleEl) titleEl.textContent = T('pomodoro.storico_collega');
        if (allBtn) { allBtn.textContent = T('pomodoro.storico_nessun_progetto'); allBtn.setAttribute('data-ps-name', T('pomodoro.storico_nessun_progetto')); }
        var noneOpt = manualOverlay.querySelector('.ph-filter-none-option');
        if (noneOpt) noneOpt.style.display = 'none'; // solo per il filtro, non per l'inserimento manuale
    }
    window.phOpenBulkProjectPicker = function(){
        var ids = Object.keys(state.selectedIds);
        if (!ids.length) { toast(T('pomodoro.storico_serve_sessione')); return; }
        phResetPickerLabels();
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo sec_h($ph_uid); ?>');
        if (manualOverlay) { window.psOpen(manualOverlay); phBulkAssignMode = true; }
    };
    var phBulkAssignMode = false;
    document.addEventListener('projectselected', function(e){
        if (e.detail.uid !== 'manual-<?php echo sec_h($ph_uid); ?>') return; // solo dal selettore di questo popup
        if (!phBulkAssignMode) return;
        phBulkAssignMode = false;
        var ids = Object.keys(state.selectedIds);
        fetch('pomodoro_storico.php?ph_ajax=bulk_action', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'ids=' + ids.join(',') + '&bulk_op=set_project&new_project_id=' + e.detail.id })
        .then(function(r){ return r.json(); })
        .then(function(){ toast(T('pomodoro.storico_progetto_assegnato')); window.phToggleSelectMode(); phLoadPage(true); });
    });

    /* ---------------- click sulle righe: selezione, collega progetto, swipe-delete ---------------- */
    document.addEventListener('click', function(e){
        /* Con il pannello aperto in modifica l'elenco e' solo da guardare: si
           scorre (lo scorrimento non passa da qui) ma non risponde ai tocchi. */
        if (phEditId !== null && e.target.closest('#phListScroll')) return;

        var editBtn = e.target.closest('[data-edit-id]');
        if (editBtn) {
            phOpenEdit(editBtn.getAttribute('data-edit-id'));
            return;
        }
        var delBtn = e.target.closest('[data-delete-id]');
        if (delBtn) {
            var id = delBtn.getAttribute('data-delete-id');
            fetch('pomodoro_storico.php?ph_ajax=single_delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id=' + id })
            .then(function(r){ return r.json(); }).then(function(data){
                if (data.success) phRemoveRows([id]);
            });
            return;
        }
        var tag = e.target.closest('[data-project-tag]');
        if (tag && !state.selecting) {
            var wrap = tag.closest('.ph-row-wrap');
            phEditingSessionId = wrap.getAttribute('data-id');
            phResetPickerLabels();
            var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo sec_h($ph_uid); ?>');
            if (manualOverlay) { window.psOpen(manualOverlay); phSingleAssignMode = true; }
            return;
        }
        var wrap2 = e.target.closest('.ph-row-wrap');
        if (wrap2 && state.selecting && e.target.closest('.ph-row-content')) {
            var id2 = wrap2.getAttribute('data-id');
            if (state.selectedIds[id2]) { delete state.selectedIds[id2]; wrap2.classList.remove('selected'); }
            else { state.selectedIds[id2] = true; wrap2.classList.add('selected'); }
            phUpdateBulkCount();
        }
    });
    var phEditingSessionId = null, phSingleAssignMode = false;
    document.addEventListener('projectselected', function(e){
        if (e.detail.uid !== 'manual-<?php echo sec_h($ph_uid); ?>') return; // solo dal selettore di questo popup
        if (!phSingleAssignMode || !phEditingSessionId) return;
        phSingleAssignMode = false;
        var id = phEditingSessionId; phEditingSessionId = null;
        fetch('pomodoro_storico.php?ph_ajax=set_project', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'id=' + id + '&project_id=' + e.detail.id })
        .then(function(r){ return r.json(); }).then(function(data){
            if (!data.success) return;
            var wrap = document.querySelector('.ph-row-wrap[data-id="'+id+'"]');
            if (!wrap) return;
            wrap.style.setProperty('--row-color', data.color || '#8A8A8E');
            var tag2 = wrap.querySelector('[data-project-tag]');
            if (tag2) tag2.innerHTML = phProjectTagInnerRaw(data.name, data.path);
        });
    });

    /* ---------------- swipe (destra = elimina) ---------------- */
    (function(){
        var pend = null;
        document.addEventListener('pointerdown', function(e){
            var content = e.target.closest('.ph-row-content');
            if (!content || state.selecting) return;
            // in modifica lo swipe non parte: i tasti di lato non devono aprirsi
            if (phEditId !== null) return;
            var row = content.closest('.ph-row');
            // bug: le altre righe aperte restavano aperte quando se ne apriva una nuova.
            // prima di iniziare questo swipe, richiudiamo tutte le altre.
            document.querySelectorAll('.ph-row').forEach(function(otherRow){
                if (otherRow === row || !otherRow._sx) return;
                var otherContent = otherRow.querySelector('.ph-row-content');
                if (otherContent) {
                    otherContent.style.transition = 'transform .25s ease';
                    otherContent.style.transform = 'translateX(0)';
                    setTimeout(function(){ otherContent.style.transition = ''; }, 260);
                }
                otherRow._sx = 0;
            });
            var actions = row.querySelector('.ph-swipe-actions');
            pend = { row: row, content: content, pid: e.pointerId, startX: e.clientX, startTranslate: row._sx || 0, max: actions ? actions.offsetWidth : 70, moved:false };
        });
        document.addEventListener('pointermove', function(e){
            if (!pend || e.pointerId !== pend.pid) return;
            var dx = e.clientX - pend.startX;
            if (!pend.moved && Math.abs(dx) < 6) return;
            pend.moved = true;
            var x = pend.startTranslate + dx;
            if (x < 0) x = x * 0.15;
            if (x > pend.max) x = pend.max;
            pend.content.style.transform = 'translateX(' + x + 'px)';
            pend.currentX = x;
        });
        function end(){
            if (!pend) return;
            var p = pend; pend = null;
            p.content.style.transition = 'transform .25s ease';
            var open = p.moved && p.currentX > p.max * 0.45;
            p.content.style.transform = 'translateX(' + (open ? p.max : 0) + 'px)';
            p.row._sx = open ? p.max : 0;
            setTimeout(function(){ p.content.style.transition = ''; }, 260);
        }
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', end);
    })();

    /* ---------------- inserimento manuale ---------------- */
    /* null = il pannello sta inserendo; un id = sta modificando quella riga.
       E' l'unica differenza fra i due modi, e sta in una variabile sola apposta:
       due percorsi separati si sarebbero sfasati alla prima aggiunta di campo. */
    var phEditId = null;

    /* Il tasto che avvia un pomodoro sta in cima all'elenco, non fra le
       iconcine dell'intestazione: là era un tondo da 30px accanto ad altri due
       identici, e l'unica azione che porta via da questa schermata si perdeva
       fra due che la modificano soltanto. Come prima riga è larga quanto le
       sessioni e si legge prima di tutte — che è l'ordine giusto, perché
       guardare quanto si è fatto e decidere di farne ancora sono due momenti
       dello stesso gesto. */
    function phMettiTastoAvvia(scroll) {
        if (!state.goProject) return;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ph-go-riga';
        b.innerHTML = '<span class="ph-go-ic"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">'
            + '<path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg></span>';
        b.appendChild(document.createTextNode(T('pomodoro.avvia_su_questo')));
        b.addEventListener('click', phAvviaPomodoro);
        // subito dopo il pannello di panoramica, non davanti: quello resta
        // sempre la primissima cosa, cosi' e' la prima a scorrere via
        var insights = document.getElementById('phInsights');
        scroll.insertBefore(b, insights ? insights.nextSibling : scroll.firstChild);
    }

    window.phAvviaPomodoro = function () {
        if (!state.goProject) return;
        window.location.href = 'pomodoro.php?project_id=' + encodeURIComponent(state.goProject);
    };

    window.phToggleManual = function(){
        var panel = document.getElementById('phManualPanel');
        var opening = !panel.classList.contains('show');
        panel.classList.toggle('show');
        if (opening) { phEditId = null; phSetPanelMode(); phAggiornaBloccoElenco(); phResetManualFields(); }
    };

    function phSetPanelMode(){
        var modifica = phEditId !== null;
        document.getElementById('phManualTitle').style.display  = modifica ? '' : 'none';
        document.getElementById('phManualCancel').style.display = modifica ? '' : 'none';
        document.getElementById('phManualSave').textContent =
            modifica ? T('pomodoro.storico_salva_modifica') : T('pomodoro.storico_salva_sessione');
    }

    /* Mentre il pannello e' aperto in modifica, l'elenco sotto si puo' solo
       scorrere: niente swipe, niente elimina, niente cambio progetto. Con due
       cose modificabili insieme si finisce per correggere una riga guardandone
       un'altra — e il tasto elimina a un dito di distanza rende l'errore
       costoso. La riga in lavorazione si riconosce dallo sfondo, non dai tasti
       aperti di lato: quelli sono l'invito a premerli, cioe' l'opposto. */
    function phAggiornaBloccoElenco(){
        var modifica = phEditId !== null;
        var scroll = document.getElementById('phListScroll');
        scroll.classList.toggle('ph-locked', modifica);

        // richiudi ogni riga rimasta aperta dallo swipe, compresa quella scelta
        document.querySelectorAll('.ph-row').forEach(function(row){
            var content = row.querySelector('.ph-row-content');
            if (content) { content.style.transition = 'transform .25s ease'; content.style.transform = 'translateX(0)'; }
            row._sx = 0;
        });
        document.querySelectorAll('.ph-row-wrap.is-editing').forEach(function(w){ w.classList.remove('is-editing'); });
        if (modifica) {
            var w = document.querySelector('.ph-row-wrap[data-id="' + phEditId + '"]');
            if (w) w.classList.add('is-editing');
        }
    }

    window.phCancelEdit = function(){
        phEditId = null;
        phSetPanelMode();
        phAggiornaBloccoElenco();
        document.getElementById('phManualPanel').classList.remove('show');
    };

    /* Apre il pannello sui dati di una riga. Se la riga e' gia' a schermo si
       leggono i suoi attributi; se non c'e' (l'avviso del pomodoro non chiuso
       punta a una sessione che puo' stare a pagina cinque) si chiedono al
       server con ph_ajax=one. */
    function phOpenEdit(id){
        var wrap = document.querySelector('.ph-row-wrap[data-id="' + id + '"]');
        if (wrap) {
            // Qualunque origine si corregge adesso: 0 (timer), 1 (manuale), 2 (automatica).
            phRiempiEdit(id, wrap.getAttribute('data-inizio'), wrap.getAttribute('data-fine'),
                         parseInt(wrap.getAttribute('data-project-id'), 10) || 0,
                         wrap.getAttribute('data-project-name'), wrap.getAttribute('data-project-color'));
            wrap.scrollIntoView({ block: 'nearest' });
            return;
        }
        fetch('pomodoro_storico.php?ph_ajax=one', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id=' + encodeURIComponent(id) })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            if (!resp.success) { toast(T('pomodoro.storico_errore')); return; }
            var s = resp.session;
            phRiempiEdit(s.id, s.data_inizio, s.data_fine, s.project_id, s.progetto_nome, s.progetto_colore);
        })
        .catch(function(){ toast(T('pomodoro.storico_errore_rete')); });
    }

    function phRiempiEdit(id, inizio, fine, projId, projName, projColor){
        phEditId = id;
        phSetPanelMode();
        document.getElementById('phManualPanel').classList.add('show');
        phAggiornaBloccoElenco();

        // "2026-08-22 14:30:00" -> data e ore separate, senza passare da Date():
        // il fuso orario non c'entra niente, sono gia' orari locali scritti dal server
        document.getElementById('phManualData').value   = String(inizio || '').slice(0, 10);
        document.getElementById('phManualInizio').value = String(inizio || '').slice(11, 16);
        document.getElementById('phManualFine').value   = String(fine || '').slice(11, 16);
        document.getElementById('phManualDurata').value = '';
        window.phDurataTarget = null;
        phManualCalc('inizio');   // ricalcola la durata dai due estremi

        if (window.psSetValue) {
            window.psSetValue('ps-modal-manual-<?php echo sec_h($ph_uid); ?>',
                projId || 0,
                projId ? (projName || T('pomodoro.storico_progetto')) : T('pomodoro.storico_nessun_progetto'),
                projColor || '');
        }
    }

    function phResetManualFields(){
        document.getElementById('phManualData').value = phTodayStr();
        document.getElementById('phManualInizio').value = '';
        document.getElementById('phManualFine').value = '';
        document.getElementById('phManualDurata').value = '';
        // richiesta 11: se stiamo guardando un progetto specifico, precompila il selettore su quello
        if (window.psSetValue) {
            if (state.contextProjectId > 0) {
                window.psSetValue('ps-modal-manual-<?php echo sec_h($ph_uid); ?>', state.contextProjectId, state.contextProjectName || T('pomodoro.storico_progetto'), state.contextProjectColor || '');
            } else {
                window.psSetValue('ps-modal-manual-<?php echo sec_h($ph_uid); ?>', 0, T('pomodoro.storico_nessun_progetto'), '');
            }
        }
    }
    function phTodayStr(){
        var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }
    function phTimeToMin(t){ if (!t) return null; var p = t.split(':'); var n = parseInt(p[0],10)*60 + parseInt(p[1],10); return isNaN(n) ? null : n; }
    function phMinToTime(m){ m = ((m % 1440) + 1440) % 1440; return String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m % 60).padStart(2,'0'); }

    /* Campo durata: editabile finché inizio e fine non sono ENTRAMBI presenti (a quel
       punto diventa un valore calcolato, sola lettura — bug #8 della lista sistemazioni).
       - se compili durata e c'è già l'inizio -> calcola la fine (inizio + durata)
       - se compili durata e c'è già la fine -> calcola l'inizio (fine - durata)
       - se compili durata per primo -> aspetta che tu compili inizio o fine
       - se compili inizio/fine e l'altro dei due è già presente -> la durata si
         ricalcola da sola e si blocca (sono inizio e fine a comandare, a quel punto) */
    window.phDurataTarget = null; // Variabile per ricordare il campo bersaglio

    window.phManualCalc = function(changed){
        var elS = document.getElementById('phManualInizio'), 
            elE = document.getElementById('phManualFine'), 
            elD = document.getElementById('phManualDurata');
        
        var sMin = phTimeToMin(elS.value), 
            eMin = phTimeToMin(elE.value), 
            dMin = phTimeToMin(elD.value);

        if (changed === 'durata') {
            if (dMin !== null) {
                // Se non abbiamo ancora bloccato un bersaglio, lo scegliamo ora
                if (!window.phDurataTarget) {
                    if (sMin === null && eMin !== null) {
                        window.phDurataTarget = 'inizio';
                    } else {
                        window.phDurataTarget = 'fine'; // Comportamento di default
                    }
                }

                // Applichiamo il calcolo sul bersaglio bloccato
                if (window.phDurataTarget === 'inizio') {
                    if (eMin !== null) elS.value = phMinToTime(eMin - dMin);
                } else {
                    if (sMin !== null) elE.value = phMinToTime(sMin + dMin);
                }
            }
        } else if (changed === 'inizio') {
            window.phDurataTarget = null; // Sblocca il bersaglio se l'utente agisce qui
            if (sMin !== null) {
                if (eMin !== null) {
                    elD.value = phMinToTime(eMin - sMin);
                } else if (dMin !== null) {
                    elE.value = phMinToTime(sMin + dMin);
                }
            }
        } else if (changed === 'fine') {
            window.phDurataTarget = null; // Sblocca il bersaglio se l'utente agisce qui
            if (eMin !== null) {
                if (sMin !== null) {
                    elD.value = phMinToTime(eMin - sMin);
                } else if (dMin !== null) {
                    elS.value = phMinToTime(eMin - dMin);
                }
            }
        }
    };

    window.phSubmitManual = function(){
        var data = document.getElementById('phManualData').value;
        var inizio = document.getElementById('phManualInizio').value;
        var fine = document.getElementById('phManualFine').value;
        var projInput = document.getElementById('ps-value-manual-<?php echo sec_h($ph_uid); ?>');
        var progettoId = projInput ? projInput.value : 0;
        if (!data || !inizio || !fine) { toast(T('pomodoro.storico_compila_lungo')); return; }

        /* Un solo invio per i due modi: cambia l'indirizzo e, in modifica, si
           aggiunge l'id. Il resto del corpo e' identico perche' i campi sono
           gli stessi — e devono restare gli stessi. */
        var modifica = phEditId !== null;
        var body = 'data=' + encodeURIComponent(data)
            + '&ora_inizio=' + encodeURIComponent(inizio)
            + '&ora_fine=' + encodeURIComponent(fine)
            + '&project_id=' + encodeURIComponent(progettoId)
            + (modifica ? ('&id=' + encodeURIComponent(phEditId)) : '&mode=0');

        fetch('pomodoro_storico.php?ph_ajax=' + (modifica ? 'manual_update' : 'manual_insert'),
              { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            if (!resp.success) { toast(resp.error || T('pomodoro.storico_errore')); return; }
            toast(modifica ? T('pomodoro.storico_sessione_modificata') : T('pomodoro.storico_sessione_aggiunta'));
            phEditId = null;
            phSetPanelMode();
            phAggiornaBloccoElenco();
            document.getElementById('phManualPanel').classList.remove('show');
            phResetManualFields();
            /* Ricarico invece di ritoccare la riga: cambiando la data o l'ora la
               sessione cambia posto nell'elenco e puo' passare sotto un altro
               giorno, intestazione compresa. */
            phLoadPage(true);
        }).catch(function(){ toast(T('pomodoro.storico_errore_rete')); });
    };

    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { var o = document.getElementById('phOverlay'); if (o.classList.contains('show')) window.closePomodoroStorico(); } });
})();
</script>
<?php endif; ?>