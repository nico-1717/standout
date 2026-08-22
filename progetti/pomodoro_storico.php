<?php
/* ==========================================================================
   POMODORO STORICO — popup riutilizzabile per lo storico delle sessioni
   (include in progetti.php e pomodoro.php). Tutta l'interazione avviene via
   fetch/JSON su questo stesso file, per non dover ricaricare la pagina ogni
   volta che si filtra, ordina, o si carica un'altra pagina di risultati.

   Uso:
     include 'pomodoro_storico.php';               // renderizza il popup (vuoto)
     window.openPomodoroStorico({ scope: 'all' });  // apre in JS, storico globale
     window.openPomodoroStorico({ scope: 'project', projectId: 5, projectName: 'Tesi' });
   ========================================================================== */

if (!isset($conn) || !$conn) {
    require_once 'config.php';
    /* auth_check PRIMA di sec_db(): e' quello a caricare security_core.php. */
    require_once 'auth_check.php';
    $current_user_id = (int)$utente_corrente['id'];
    $conn = sec_db();
}

/* Tutte le azioni di questo file arrivano come POST su ?ph_ajax=...: qui il
   token CSRF vale davvero. Prima non c'era nulla, e sono endpoint che
   cancellano sessioni e le spostano fra progetti. */
if (isset($_GET['ph_ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write((int)$current_user_id, 'pomodoro_storico', 0, 300, 60);
}

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
    if ($filtri === null) { echo json_encode(['success' => true, 'sessions' => [], 'has_more' => false]); exit(); }
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

    echo json_encode(['success' => true, 'sessions' => $sessions, 'has_more' => count($sessions) === $limit], JSON_UNESCAPED_UNICODE);
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

    /* Due condizioni, non una. sec_owns() dice che la riga e' sua; il secondo
       controllo dice che e' una riga che si PUO' toccare.
         is_manual = 1  scritta a mano
         is_manual = 2  chiusa d'ufficio dalla pagina pomodoro (stima nostra)
       Tutte e due si correggono; la 0, misurata dal cronometro, no: cambiarne
       gli orari vorrebbe dire riscrivere una misura, e i totali per progetto
       non varrebbero piu' niente. Il controllo sta qui e non solo nel browser:
       il tasto si puo' far comparire con gli strumenti da sviluppatore. */
    if (!sec_owns('pomodoro_sessions', $id, (int)$current_user_id)) {
        echo json_encode(['success' => false]); exit();
    }
    $vecchia = sec_one("SELECT is_manual FROM pomodoro_sessions WHERE id = ? AND user_id = ?",
                       [$id, (int)$current_user_id]);
    if (!$vecchia || (int)$vecchia['is_manual'] === 0) {
        echo json_encode(['success' => false, 'error' => t('pomodoro.storico_solo_manuali')]); exit();
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

    /* is_manual = 1 e status = 'archiviato': se la riga era una chiusura
       d'ufficio (2, 'abbandonato'), da adesso gli orari li ha decisi l'utente
       e il caso non e' piu' in sospeso — l'avviso non deve tornare a galla e
       il tag non deve piu' dire "automatica". */
    sec_q("UPDATE pomodoro_sessions
           SET start_time = ?, end_time = ?, duration_seconds = ?, planned_duration_min = ?,
               project_id = ?, status = 'archiviato', is_manual = 1
           WHERE id = ? AND user_id = ? AND is_manual <> 0",
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

/* ==========================================================================
   MARKUP DEL POPUP (vuoto: viene riempito via fetch quando si apre)
   ========================================================================== */
if (!isset($ph_id)) $ph_id = 'default';
$ph_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$ph_id);
?>
<?php if (!defined('PH_ASSETS_EMITTED')): define('PH_ASSETS_EMITTED', true); ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>pomodoro_storico.css?v=<?php echo @filemtime(dirname(__FILE__) . '/pomodoro_storico.css') ?: 1; ?>">
<?php endif; ?>

<div class="ph-overlay" id="phOverlay">
    <div class="ph-sheet">
        <div class="ph-head">
            <div class="ph-title" id="phTitle"><?php te('progetti.pomodori'); ?></div>
            <div class="ph-head-btns">
                <button type="button" class="ph-icon-btn" id="phSelectBtn" onclick="phToggleSelectMode()" aria-label="Seleziona">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="phToggleManual()" aria-label="Aggiungi manualmente">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="openPomodoroImport()" aria-label="Importa da file">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                </button>
                <button type="button" class="ph-icon-btn" onclick="exportPomodoroFile()" aria-label="Esporta tutto">
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
            <button type="button" class="ph-filter-chip" id="phProjectFilterChip" onclick="phOpenProjectFilter()">Progetto: tutti</button>
        </div>
        <div class="ph-date-range" id="phDateRange">
            <div class="ph-date-field"><label><?php te('pomodoro.storico_dal'); ?></label><input type="date" id="phDateFrom" onchange="phApplyDateFilter()"></div>
            <div class="ph-date-field"><label>Al</label><input type="date" id="phDateTo" onchange="phApplyDateFilter()"></div>
            <button type="button" class="ph-date-clear" onclick="phClearDateFilter()" aria-label="Azzera periodo"><?php te('pomodoro.storico_azzera'); ?></button>
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
                    include 'project_selector.php';
                ?>
            </div>
            <div class="ph-manual-btns">
                <button type="button" class="ph-manual-cancel" id="phManualCancel" style="display:none;" onclick="phCancelEdit()"><?php te('comune.annulla'); ?></button>
                <button type="button" class="ph-manual-save" id="phManualSave" onclick="phSubmitManual()"><?php te('pomodoro.storico_salva_sessione'); ?></button>
            </div>
        </div>

        <div class="ph-list-scroll" id="phListScroll">
            <div class="ph-empty-msg" id="phEmptyMsg" style="display:none;"><?php te('pomodoro.storico_nessuna'); ?></div>
        </div>
    </div>
</div>

<div class="ph-toast" id="phToast"></div>

<?php
    /* Popup di import/export: si apre dai due tasti in alto (openPomodoroImport
       ed exportPomodoroFile). Va incluso qui, dopo il selettore del pannello
       manuale, perche' porta con se' un secondo project_selector tutto suo. */
    include 'pomodoro_import.php';
?>

<?php if (!defined('PH_SCRIPT_EMITTED')): define('PH_SCRIPT_EMITTED', true); ?>
<script>
(function () {
    'use strict';

    var state = { scope: 'all', projectIds: [], sort: 'recent', filterProject: 0, dateFrom: '', dateTo: '', offset: 0, hasMore: true, loading: false, selecting: false, selectedIds: {}, lastDay: null };

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

    function fmtTime(dt){ var d = new Date(dt.replace(' ', 'T')); return d.toLocaleTimeString('it-IT', {hour:'2-digit', minute:'2-digit'}); }
    function fmtDayLabel(dt){
        var d = new Date(dt.replace(' ', 'T'));
        return d.toLocaleDateString('it-IT', { day:'numeric', month:'long', year: (d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined) });
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
        document.getElementById('phTitle').textContent = config.scope === 'project' ? (T('pomodoro.storico_pomodori_sep') + (config.projectName || '')) : T('pomodoro.storico_tutti');
        document.querySelectorAll('.ph-filter-chip[data-sort]').forEach(function(c){ c.classList.toggle('active', c.getAttribute('data-sort') === 'recent'); });
        
        var projectFilterChip = document.getElementById('phProjectFilterChip');
        projectFilterChip.textContent = 'Progetto: tutti';
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
        document.getElementById('phOverlay').classList.add('show');
        document.documentElement.classList.add('sb-locked');
    };
    window.closePomodoroStorico = function(){
        document.getElementById('phOverlay').classList.remove('show');
        document.documentElement.classList.remove('sb-locked');
        if (state.selecting) window.phToggleSelectMode();
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
                scroll.querySelectorAll('.ph-row-wrap, .ph-day-header, .ph-loadmore').forEach(function(el){ el.remove(); });
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
            head.textContent = fmtDayLabel(s.data_inizio);
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
        var azioni = '';
        if (s.manuale) {
            azioni += '<button type="button" class="ph-swipe-btn ph-swipe-edit" data-edit-id="' + s.id + '">'
                + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>'
                + '<span>' + T('comune.modifica') + '</span></button>';
        }
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
                document.getElementById('phProjectFilterChip').textContent = 'Progetto: nessuno';
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
        document.getElementById('phProjectFilterChip').textContent = 'Progetto: ' + (e.detail.id > 0 ? e.detail.name : 'tutti');
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
            // 0 = misurata dal timer: non si tocca. 1 e 2 si correggono entrambe.
            if (wrap.getAttribute('data-manual') === '0') { toast(T('pomodoro.storico_solo_manuali')); return; }
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
            if (!s.manuale) { toast(T('pomodoro.storico_solo_manuali')); return; }
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