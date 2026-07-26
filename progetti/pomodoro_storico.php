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
    require_once 'auth_check.php';
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $current_user_id = $utente_corrente['id'];
}

if (!function_exists('ph_project_ids_in_subtree')) {
    function ph_project_ids_in_subtree($conn, $userId, $rootId) {
        $childrenOf = [];
        $res = mysqli_query($conn, "SELECT id, parent_id FROM projects WHERE user_id = " . intval($userId));
        while ($res && ($row = mysqli_fetch_assoc($res))) {
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
    function ph_project_map($conn, $userId) {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $res = mysqli_query($conn, "SELECT id, name, parent_id FROM projects WHERE user_id = " . intval($userId));
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $cache[(int)$row['id']] = ['name' => $row['name'], 'parent' => $row['parent_id'] === null ? 0 : (int)$row['parent_id']];
        }
        return $cache;
    }
}
if (!function_exists('ph_project_path')) {
    /* Catena di antenati (dal più lontano al progetto stesso), max 3 voci:
       nonno, padre, progetto — per il tag "nonno • padre • progetto" (richiesta 17). */
    function ph_project_path($conn, $userId, $projectId) {
        if (!$projectId) return [];
        $map = ph_project_map($conn, $userId);
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
            'progetto_path' => ($conn && $row['project_id']) ? ph_project_path($conn, $userId, (int)$row['project_id']) : [],
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
    function ph_build_where($conn, $uid) {
        $scope = $_POST['scope'] ?? 'all';
        $where = ["s.user_id = " . intval($uid), "s.end_time IS NOT NULL"];
        if ($scope === 'project') {
            $projectIds = array_filter(array_map('intval', explode(',', $_POST['project_ids'] ?? '')));
            if (empty($projectIds)) return null;
            $where[] = "s.project_id IN (" . implode(',', $projectIds) . ")";
        }
        $filterProject = intval($_POST['filter_project'] ?? 0); // 0=tutti, -1=nessuno, N=specifico
        if ($filterProject > 0) $where[] = "s.project_id = " . $filterProject;
        if ($filterProject === -1) $where[] = "s.project_id IS NULL";
        $dateFrom = trim($_POST['date_from'] ?? '');
        $dateTo = trim($_POST['date_to'] ?? '');
        if ($dateFrom !== '') $where[] = "s.start_time >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        if ($dateTo !== '') $where[] = "s.start_time <= '" . mysqli_real_escape_string($conn, $dateTo) . " 23:59:59'";
        $minMinutes = intval($_POST['min_minutes'] ?? 0);
        if ($minMinutes > 0) $where[] = "s.duration_seconds >= " . ($minMinutes * 60);
        return $where;
    }
}

if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'list') {
    header('Content-Type: application/json');
    $sort = $_POST['sort'] ?? 'recent';
    $offset = max(0, intval($_POST['offset'] ?? 0));
    $limit = 20;

    $where = ph_build_where($conn, $current_user_id);
    if ($where === null) { echo json_encode(['success' => true, 'sessions' => [], 'has_more' => false]); exit(); }

    $orderBy = "s.start_time DESC";
    if ($sort === 'oldest') $orderBy = "s.start_time ASC";
    if ($sort === 'duration') $orderBy = "s.duration_seconds DESC";

    $sql = "SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual,
                   p.name AS progetto_nome, p.color AS progetto_colore
             FROM pomodoro_sessions s
             LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY $orderBy
             LIMIT $limit OFFSET $offset";
    $res = mysqli_query($conn, $sql);
    $sessions = [];
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $sessions[] = ph_session_to_array($row, $conn, $current_user_id); } }

    echo json_encode(['success' => true, 'sessions' => $sessions, 'has_more' => count($sessions) === $limit], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: tutti gli id che rispettano il filtro attivo (per "Seleziona tutti":
   non solo le righe gia' caricate a schermo, ma l'intero risultato filtrato)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'all_ids') {
    header('Content-Type: application/json');
    $where = ph_build_where($conn, $current_user_id);
    if ($where === null) { echo json_encode(['success' => true, 'ids' => []]); exit(); }

    $sql = "SELECT s.id FROM pomodoro_sessions s WHERE " . implode(' AND ', $where) . " LIMIT 20000";
    $res = mysqli_query($conn, $sql);
    $ids = [];
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $ids[] = (int)$row['id']; } }

    echo json_encode(['success' => true, 'ids' => $ids], JSON_UNESCAPED_UNICODE);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: azioni di massa (elimina / scollega / collega a progetto)
   ------------------------------------------------------------------------- */

if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'bulk_action') {
    header('Content-Type: application/json');
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $op = $_POST['bulk_op'] ?? '';
    if (empty($ids) || !in_array($op, ['delete', 'unlink', 'set_project'], true)) {
        echo json_encode(['success' => false]); exit();
    }
    $idList = implode(',', $ids);
    if ($op === 'delete') {
        mysqli_query($conn, "DELETE FROM pomodoro_sessions WHERE id IN ($idList) AND user_id = " . intval($current_user_id));
    } elseif ($op === 'unlink') {
        mysqli_query($conn, "UPDATE pomodoro_sessions SET project_id = NULL WHERE id IN ($idList) AND user_id = " . intval($current_user_id));
    } elseif ($op === 'set_project') {
        $newProjectId = intval($_POST['new_project_id'] ?? 0);
        if ($newProjectId > 0) {
            $chk = mysqli_query($conn, "SELECT id FROM projects WHERE id=$newProjectId AND user_id=" . intval($current_user_id));
            if ($chk && mysqli_num_rows($chk) > 0) {
                mysqli_query($conn, "UPDATE pomodoro_sessions SET project_id = $newProjectId WHERE id IN ($idList) AND user_id = " . intval($current_user_id));
            }
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
    $id = intval($_POST['id'] ?? 0);
    $newProjectId = intval($_POST['project_id'] ?? 0);
    if ($newProjectId > 0) {
        $chk = mysqli_query($conn, "SELECT name, color FROM projects WHERE id=$newProjectId AND user_id=" . intval($current_user_id));
        $chkRow = $chk ? mysqli_fetch_assoc($chk) : null;
        if (!$chkRow) { echo json_encode(['success' => false]); exit(); }
        mysqli_query($conn, "UPDATE pomodoro_sessions SET project_id = $newProjectId WHERE id=$id AND user_id=" . intval($current_user_id));
        echo json_encode(['success' => true, 'name' => $chkRow['name'], 'color' => $chkRow['color'], 'path' => ph_project_path($conn, $current_user_id, $newProjectId)]);
    } else {
        mysqli_query($conn, "UPDATE pomodoro_sessions SET project_id = NULL WHERE id=$id AND user_id=" . intval($current_user_id));
        echo json_encode(['success' => true, 'name' => '', 'color' => '']);
    }
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: eliminazione singola (swipe)
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'single_delete') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    mysqli_query($conn, "DELETE FROM pomodoro_sessions WHERE id=$id AND user_id=" . intval($current_user_id));
    echo json_encode(['success' => true]);
    exit();
}

/* -------------------------------------------------------------------------
   AJAX: inserimento manuale
   ------------------------------------------------------------------------- */
if (isset($_GET['ph_ajax']) && $_GET['ph_ajax'] === 'manual_insert') {
    header('Content-Type: application/json');
    $data = trim($_POST['data'] ?? '');
    $oraInizio = trim($_POST['ora_inizio'] ?? '');
    $oraFine = trim($_POST['ora_fine'] ?? '');
    $progettoId = intval($_POST['project_id'] ?? 0);
    $modeVal = (intval($_POST['mode'] ?? 0) === 1) ? 1 : 0;

    if ($data === '' || $oraInizio === '' || $oraFine === '') {
        echo json_encode(['success' => false, 'error' => 'Compila data, inizio e fine']); exit();
    }
    $startDt = $data . ' ' . $oraInizio . ':00';
    $endDt = $data . ' ' . $oraFine . ':00';
    if (strtotime($endDt) <= strtotime($startDt)) {
        $endDt = date('Y-m-d H:i:s', strtotime($endDt . ' +1 day'));
    }
    $startEsc = mysqli_real_escape_string($conn, $startDt);
    $endEsc = mysqli_real_escape_string($conn, $endDt);
    $durataSecondi = max(0, strtotime($endDt) - strtotime($startDt));
    $progSql = $progettoId > 0 ? $progettoId : 'NULL';
    if ($progettoId > 0) {
        $chk = mysqli_query($conn, "SELECT id FROM projects WHERE id=$progettoId AND user_id=" . intval($current_user_id));
        if (!$chk || mysqli_num_rows($chk) === 0) $progSql = 'NULL';
    }

    mysqli_query($conn, "INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, end_time, duration_seconds, planned_duration_min, status, project_id, is_manual)
                          VALUES (" . intval($current_user_id) . ", $modeVal, '$startEsc', '$endEsc', $durataSecondi, " . intval(round($durataSecondi / 60)) . ", 'archiviato', $progSql, 1)");
    $newId = mysqli_insert_id($conn);
    if (!$newId) { 
        $errore_db = mysqli_error($conn);
        echo json_encode(['success' => false, 'error' => 'Errore SQL: ' . $errore_db]); 
        exit(); 
    }

    $res = mysqli_query($conn, "SELECT s.id, s.mode, s.start_time AS data_inizio, s.end_time AS data_fine, s.duration_seconds AS durata_secondi, s.project_id, s.is_manual AS manual, p.name AS progetto_nome, p.color AS progetto_colore
                                 FROM pomodoro_sessions s LEFT JOIN projects p ON p.id = s.project_id
                                 WHERE s.id = $newId");
    $row = mysqli_fetch_assoc($res);
    echo json_encode(['success' => true, 'session' => ph_session_to_array($row, $conn, $current_user_id)], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ==========================================================================
   MARKUP DEL POPUP (vuoto: viene riempito via fetch quando si apre)
   ========================================================================== */
if (!isset($ph_id)) $ph_id = 'default';
$ph_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$ph_id);
?>
<?php if (!defined('PH_ASSETS_EMITTED')): define('PH_ASSETS_EMITTED', true); ?>
<style>
.ph-overlay{ position:fixed; inset:0; z-index:9500; display:flex; align-items:flex-end; justify-content:center; background:rgba(0,0,0,.42);
    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); opacity:0; pointer-events:none; transition:opacity .3s; }
.ph-overlay.show{ opacity:1; pointer-events:auto; }
.ph-sheet{ width:100%; max-width:660px; height:90vh; height:90dvh; background:var(--surface, #fff); border-radius:24px 24px 0 0;
    display:flex; flex-direction:column; transform:translateY(100%); transition:transform .4s var(--spring, ease); padding-bottom:var(--safe-bottom,0px); }
.ph-overlay.show .ph-sheet{ transform:translateY(0); }
.ph-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 16px 8px; flex-shrink:0; }
.ph-title{ font-size:17px; font-weight:800; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ph-head-btns{ display:flex; gap:8px; flex-shrink:0; }
.ph-icon-btn{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2,#eee); color:var(--text-2,#777); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:transform .15s var(--spring,ease), background-color .2s, color .2s; }
.ph-icon-btn:active{ transform:scale(.88); }
.ph-icon-btn.active{ background:var(--blue,#007AFF); color:#fff; }

.ph-filters{ display:flex; gap:8px; padding:6px 16px 12px; overflow-x:auto; flex-shrink:0; -webkit-overflow-scrolling:touch; }
.ph-filter-chip{ flex-shrink:0; display:flex; align-items:center; gap:5px; padding:8px 13px; border-radius:20px; border:none; background:var(--surface-2,#eee); color:var(--text,#111); font-size:12.5px; font-weight:700; white-space:nowrap; transition:background-color .25s var(--spring,ease), color .25s, transform .15s var(--spring,ease); }
.ph-filter-chip:active{ transform:scale(.94); }
.ph-filter-chip.active{ background:var(--blue,#007AFF); color:#fff; }
.ph-date-range{ display:none; gap:10px; align-items:flex-end; padding:0 16px 12px; flex-shrink:0; }
.ph-date-range.show{ display:flex; }
.ph-date-field{ display:flex; flex-direction:column; gap:4px; }
.ph-date-field label{ font-size:10.5px; font-weight:700; color:var(--text-2,#888); text-transform:uppercase; letter-spacing:.5px; padding-left:2px; }
.ph-date-field input{ padding:9px 11px; border-radius:11px; border:none; background:var(--surface-2,#eee); color:var(--text,#111); font-size:14px; font-family:inherit; box-shadow:inset 0 0 0 .5px var(--hairline,#0002); }
.ph-date-clear{ padding:9px 13px; border-radius:11px; border:none; background:var(--surface-2,#eee); color:var(--text-2,#888); font-size:12.5px; font-weight:700; transition:transform .15s var(--spring,ease); }
.ph-date-clear:active{ transform:scale(.94); }

.ph-bulkbar{ display:none; align-items:center; gap:8px; padding:8px 16px; border-top:.5px solid var(--hairline,#eee); flex-shrink:0; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.ph-bulkbar::-webkit-scrollbar{ display:none; }
.ph-bulkbar.show{ display:flex; }
.ph-bulkbar span{ font-size:12.5px; color:var(--text-2,#888); font-weight:700; flex:1 0 auto; white-space:nowrap; }
.ph-bulk-btn{ flex-shrink:0; padding:8px 12px; border-radius:11px; border:none; font-size:12px; font-weight:700; white-space:nowrap; background:var(--surface-2,#eee); transition:transform .15s var(--spring,ease); }
.ph-bulk-btn:active{ transform:scale(.94); }
.ph-bulk-btn.danger{ background:var(--red-soft, #fde); color:var(--red,#FF3B30); }
.ph-bulk-btn.accent{ background:var(--blue-soft, rgba(0,122,255,.12)); color:var(--blue,#007AFF); }

.ph-list-scroll{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:4px 12px calc(16px + var(--safe-bottom,0px)); }
@keyframes phRowIn{ from{ opacity:0; transform:translateY(10px) scale(.98); } to{ opacity:1; transform:none; } }
.ph-day-header{ font-size:12px; font-weight:800; color:var(--text-2,#888); text-transform:uppercase; letter-spacing:.4px; padding:14px 8px 6px; animation:phRowIn .35s var(--spring,ease) both; }
.ph-row-wrap{ position:relative; margin-bottom:6px; animation:phRowIn .35s var(--spring,ease) both; }
.ph-row{ position:relative; overflow:hidden; border-radius:14px; }
.ph-row-content{ position:relative; z-index:2; background:var(--surface-2,#f4f4f6); display:flex; align-items:center; gap:10px; padding:11px 12px; border-left:4px solid var(--row-color,#8A8A8E); transition:transform .1s; }
.ph-select-chk{ width:20px; height:20px; border-radius:50%; border:2px solid var(--hairline-2,#ccc); flex-shrink:0; display:none; align-items:center; justify-content:center; color:transparent; transition:background-color .2s, border-color .2s, color .2s; }
.ph-selecting .ph-select-chk{ display:flex; }
.ph-row-wrap.selected .ph-select-chk{ background:var(--blue,#007AFF); border-color:var(--blue,#007AFF); color:#fff; }
.ph-mode-icon{ width:26px; height:26px; border-radius:50%; background:var(--row-color,#8A8A8E); color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 6px -1px color-mix(in srgb, var(--row-color,#8A8A8E) 55%, transparent); }
.ph-mode-icon svg{ width:14px; height:14px; }
.ph-info{ flex:1; min-width:0; }
.ph-time-range{ font-size:13.5px; font-weight:700; }
.ph-project-tag{ font-size:11.5px; color:var(--text-2,#888); font-weight:600; display:flex; align-items:center; gap:4px; margin-top:2px; flex-wrap:wrap; }
.ph-project-tag .dot{ width:7px; height:7px; border-radius:50%; background:var(--row-color,#8A8A8E); flex-shrink:0; }
.ph-project-tag .ph-pt-anc{ color:var(--text-3,#AEAEB2); font-weight:600; }
.ph-project-tag .ph-pt-sep{ color:var(--text-3,#AEAEB2); margin:0 1px; }
.ph-project-tag .ph-pt-leaf{ color:var(--text,#111); font-weight:800; }
.ph-manual-badge{ font-size:9.5px; background:var(--surface,#fff); color:var(--text-2,#888); padding:1px 6px; border-radius:6px; font-weight:700; margin-left:5px; }
.ph-duration{ font-size:13.5px; font-weight:800; font-variant-numeric:tabular-nums; flex-shrink:0; }
.ph-swipe-actions{ position:absolute; top:0; left:0; bottom:0; display:flex; z-index:1; }
.ph-swipe-btn{ width:70px; border:none; background:linear-gradient(180deg, color-mix(in srgb, var(--red,#FF3B30), #fff 12%), var(--red,#FF3B30)); color:#fff; font-size:11px; font-weight:700; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; }

.ph-loadmore{ display:block; width:100%; padding:12px; margin-top:6px; border-radius:13px; border:none; background:var(--surface-2,#eee); color:var(--blue,#007AFF); font-weight:700; font-size:13.5px; transition:transform .15s var(--spring,ease); }
.ph-loadmore:active{ transform:scale(.97); }
.ph-empty-msg{ text-align:center; padding:40px 16px; color:var(--text-2,#888); font-size:14px; }

.ph-manual-panel{ display:flex; flex-direction:column; gap:10px; padding:0 16px; border-top:0 solid var(--hairline,#eee); flex-shrink:0; max-height:0; overflow:hidden; opacity:0; transition:max-height .35s var(--spring,ease), opacity .3s, padding .35s var(--spring,ease); }
.ph-manual-panel.show{ display:flex; max-height:50vh; overflow-y:auto; opacity:1; padding:14px 16px; border-top-width:.5px; }
.ph-manual-row{ display:flex; gap:8px; }
.ph-manual-row > div{ flex:1; }
.ph-manual-row-durata{ justify-content:center; }
.ph-manual-row-durata > div{ flex:0 0 auto; width:150px; }
.ph-manual-panel label{ font-size:11px; font-weight:700; color:var(--text-2,#888); text-transform:uppercase; display:block; margin-bottom:4px; }
.ph-manual-panel input{ width:100%; padding:10px; border-radius:11px; border:none; background:var(--surface-2,#eee); font-size:14.5px; font-family:inherit; box-sizing:border-box; transition:opacity .2s; }
.ph-manual-panel input[readonly]{ opacity:.62; }
.ph-manual-save{ padding:12px; border-radius:12px; border:none; background:var(--blue,#007AFF); color:#fff; font-weight:700; transition:transform .15s var(--spring,ease); }
.ph-manual-save:active{ transform:scale(.97); }

.ph-toast{ position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom,0px)); transform:translate(-50%,12px); background:rgba(28,28,30,.92); color:#fff;
    padding:11px 18px; border-radius:14px; font-size:13.5px; font-weight:600; z-index:9950; opacity:0; pointer-events:none; transition:opacity .25s, transform .25s; }
.ph-toast.show{ opacity:1; transform:translate(-50%,0); }

@media (prefers-reduced-motion: reduce){
    *{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; }
}
</style>
<?php endif; ?>

<div class="ph-overlay" id="phOverlay">
    <div class="ph-sheet">
        <div class="ph-head">
            <div class="ph-title" id="phTitle">Pomodori</div>
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
                <button type="button" class="ph-icon-btn" onclick="closePomodoroStorico()" aria-label="Chiudi">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <div class="ph-filters">
            <button type="button" class="ph-filter-chip active" data-sort="recent" onclick="phSetSort('recent')">Più recenti</button>
            <button type="button" class="ph-filter-chip" data-sort="oldest" onclick="phSetSort('oldest')">Più vecchi</button>
            <button type="button" class="ph-filter-chip" data-sort="duration" onclick="phSetSort('duration')">Durata</button>
            <button type="button" class="ph-filter-chip" id="phDateFilterChip" onclick="phToggleDateFilter()">Periodo</button>
            <button type="button" class="ph-filter-chip" id="phProjectFilterChip" onclick="phOpenProjectFilter()">Progetto: tutti</button>
        </div>
        <div class="ph-date-range" id="phDateRange">
            <div class="ph-date-field"><label>Dal</label><input type="date" id="phDateFrom" onchange="phApplyDateFilter()"></div>
            <div class="ph-date-field"><label>Al</label><input type="date" id="phDateTo" onchange="phApplyDateFilter()"></div>
            <button type="button" class="ph-date-clear" onclick="phClearDateFilter()" aria-label="Azzera periodo">Azzera</button>
        </div>

        <div class="ph-bulkbar" id="phBulkbar">
            <span id="phBulkCount">0 selezionati</span>
            <button type="button" class="ph-bulk-btn accent" id="phSelectAllBtn" onclick="phSelectAll()">Seleziona tutti</button>
            <button type="button" class="ph-bulk-btn" onclick="phBulkAction('unlink')">Scollega</button>
            <button type="button" class="ph-bulk-btn" onclick="phOpenBulkProjectPicker()">Assegna a...</button>
            <button type="button" class="ph-bulk-btn danger" onclick="phBulkAction('delete')">Elimina</button>
        </div>

        <div class="ph-manual-panel" id="phManualPanel">
            <div class="ph-manual-row">
                <div><label>Data</label><input type="date" id="phManualData"></div>
            </div>
            <div class="ph-manual-row">
                <div><label>Inizio</label><input type="time" id="phManualInizio" oninput="phManualCalc('inizio')"></div>
                <div><label>Fine</label><input type="time" id="phManualFine" oninput="phManualCalc('fine')"></div>
            </div>
            <div class="ph-manual-row ph-manual-row-durata">
                <div><label>Durata</label><input type="time" id="phManualDurata" onfocus="if(!this.value) this.value='00:00';" onblur="window.phDurataTarget=null;" oninput="phManualCalc('durata')"></div>
            </div>
            <div><label>Progetto (opzionale)</label>
                <?php
                    $ps_id = 'manual-' . $ph_uid; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
                    $ps_placeholder = 'Nessun progetto'; $ps_title = 'Collega a progetto';
                    $ps_show_all_option = true; $ps_all_option_label = 'Nessun progetto'; $ps_allow_create = false;
                    $ps_hidden_name = 'manual_project_id';
                    $ps_trigger_class = 'ps-trigger-pill';
                    include 'project_selector.php';
                ?>
            </div>
            <button type="button" class="ph-manual-save" onclick="phSubmitManual()">Salva sessione</button>
        </div>

        <div class="ph-list-scroll" id="phListScroll">
            <div class="ph-empty-msg" id="phEmptyMsg" style="display:none;">Nessuna sessione trovata.</div>
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
        if (!s.project_id) return '<span style="text-decoration:underline;">+ collega progetto</span>';
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

    window.openPomodoroStorico = function(config){
        config = config || {};
        state.scope = config.scope || 'all';
        state.projectIds = config.projectIds || [];
        // richiesta 11: ricorda il progetto di contesto per pre-selezionarlo nell'inserimento manuale
        state.contextProjectId = (config.scope === 'project' && config.projectId && !config.projectIds) ? config.projectId : 0;
        state.contextProjectName = config.projectName || '';
        state.contextProjectColor = config.projectColor || '';
        state.sort = 'recent'; state.filterProject = 0; state.dateFrom = ''; state.dateTo = ''; state.offset = 0; state.hasMore = true; state.selecting = false; state.selectedIds = {}; state.lastDay = null;
        document.getElementById('phTitle').textContent = config.scope === 'project' ? ('Pomodori · ' + (config.projectName || '')) : 'Tutti i pomodori';
        document.querySelectorAll('.ph-filter-chip[data-sort]').forEach(function(c){ c.classList.toggle('active', c.getAttribute('data-sort') === 'recent'); });
        
        var projectFilterChip = document.getElementById('phProjectFilterChip');
        projectFilterChip.textContent = 'Progetto: tutti';
        projectFilterChip.style.display = config.scope === 'project' ? 'none' : ''; // Nasconde il tasto se siamo nel dettaglio progetto

        // richiesta 14: azzera la UI del filtro periodo ad ogni apertura
        var dr = document.getElementById('phDateRange'); if (dr) dr.classList.remove('show');
        var dchip = document.getElementById('phDateFilterChip'); if (dchip) { dchip.classList.remove('active'); dchip.textContent = 'Periodo'; }
        var dfrom = document.getElementById('phDateFrom'); if (dfrom) dfrom.value = '';
        var dto = document.getElementById('phDateTo'); if (dto) dto.value = '';
        
        document.getElementById('phBulkbar').classList.remove('show');
        document.getElementById('phManualPanel').classList.remove('show');
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
            if (!data.success) { toast('Errore nel caricamento'); return; }
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
                btn.type = 'button'; btn.className = 'ph-loadmore'; btn.textContent = 'Carica altri';
                btn.onclick = function(){ phLoadPage(false); };
                scroll.appendChild(btn);
            }
        })
        .catch(function(){ state.loading = false; toast('Errore di rete'); });
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
        wrap.style.setProperty('--row-color', s.progetto_colore || '#8A8A8E');
        wrap.innerHTML =
            '<div class="ph-row">' +
                '<div class="ph-row-content">' +
                    '<span class="ph-select-chk">✓</span>' +
                    '<span class="ph-mode-icon">' + (s.mode === 1
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 3"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="13" r="8"/><path d="M9 2h6"/></svg>') +
                    '</span>' +
                    '<div class="ph-info">' +
                        '<div class="ph-time-range">' + fmtTime(s.data_inizio) + ' – ' + fmtTime(s.data_fine) + (s.manuale ? '<span class="ph-manual-badge">manuale</span>' : '') + '</div>' +
                        '<div class="ph-project-tag" data-project-tag>' + phProjectTagInner(s) + '</div>' +
                    '</div>' +
                    '<span class="ph-duration">' + s.durata_fmt + '</span>' +
                '</div>' +
                '<div class="ph-swipe-actions"><button type="button" class="ph-swipe-btn" data-delete-id="' + s.id + '">🗑<span>Elimina</span></button></div>' +
            '</div>';
        scroll.appendChild(wrap);
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
            chip.textContent = active ? phDateChipLabel() : 'Periodo';
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
        if (chip) { chip.classList.remove('active'); chip.textContent = 'Periodo'; }
        phLoadPage(true);
    };
    window.phOpenProjectFilter = function(){
        // riusa lo stesso project_selector incluso per l'inserimento manuale (evita di
        // duplicare l'intero componente): gli cambiamo solo etichetta/titolo al volo,
        // dato che qui serve "tutti i progetti" invece di "nessun progetto".
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo $ph_uid; ?>');
        if (!manualOverlay) return;
        var titleEl = manualOverlay.querySelector('.ps-sheet-title');
        var allBtn = manualOverlay.querySelector('.ps-all-option');
        if (titleEl) titleEl.textContent = 'Filtra per progetto';
        if (allBtn) { allBtn.textContent = 'Tutti i progetti'; allBtn.setAttribute('data-ps-name', 'Tutti i progetti'); }
        // richiesta 10: aggiungi (una sola volta) l'opzione "Nessun progetto collegato"
        if (allBtn && !manualOverlay.querySelector('.ph-filter-none-option')) {
            var noneBtn = document.createElement('button');
            noneBtn.type = 'button';
            noneBtn.className = 'ps-all-option ph-filter-none-option';
            noneBtn.textContent = 'Nessun progetto collegato';
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
        if (e.detail.uid !== 'manual-<?php echo $ph_uid; ?>') return; // solo dal selettore di questo popup
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
        document.getElementById('phBulkCount').textContent = n + ' selezionat' + (n === 1 ? 'o' : 'i');
        var btn = document.getElementById('phSelectAllBtn');
        if (btn) btn.textContent = n > 0 ? 'Deseleziona tutti' : 'Seleziona tutti';
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
            if (!data.success) { toast('Errore nella selezione'); return; }
            state.selectedIds = {};
            data.ids.forEach(function(id){ state.selectedIds[id] = true; });
            document.querySelectorAll('.ph-row-wrap').forEach(function(w){
                w.classList.toggle('selected', !!state.selectedIds[w.getAttribute('data-id')]);
            });
            phUpdateBulkCount();
            buzz(10);
        })
        .catch(function(){ toast('Errore di rete'); });
    };
    window.phBulkAction = function(op){
        var ids = Object.keys(state.selectedIds);
        if (!ids.length) { toast('Seleziona almeno una sessione'); return; }
        fetch('pomodoro_storico.php?ph_ajax=bulk_action', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'ids=' + ids.join(',') + '&bulk_op=' + op })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success) { toast('Errore'); return; }
            if (op === 'delete') phRemoveRows(ids);
            if (op === 'unlink') { ids.forEach(function(id){ var tag = document.querySelector('.ph-row-wrap[data-id="'+id+'"] [data-project-tag]'); if (tag) tag.innerHTML = '<span style="text-decoration:underline;">+ collega progetto</span>'; }); }
            toast('Fatto');
            window.phToggleSelectMode();
        }).catch(function(){ toast('Errore di rete'); });
    };
    function phResetPickerLabels(){
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo $ph_uid; ?>');
        if (!manualOverlay) return;
        var titleEl = manualOverlay.querySelector('.ps-sheet-title');
        var allBtn = manualOverlay.querySelector('.ps-all-option');
        if (titleEl) titleEl.textContent = 'Collega a progetto';
        if (allBtn) { allBtn.textContent = 'Nessun progetto'; allBtn.setAttribute('data-ps-name', 'Nessun progetto'); }
        var noneOpt = manualOverlay.querySelector('.ph-filter-none-option');
        if (noneOpt) noneOpt.style.display = 'none'; // solo per il filtro, non per l'inserimento manuale
    }
    window.phOpenBulkProjectPicker = function(){
        var ids = Object.keys(state.selectedIds);
        if (!ids.length) { toast('Seleziona almeno una sessione'); return; }
        phResetPickerLabels();
        var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo $ph_uid; ?>');
        if (manualOverlay) { window.psOpen(manualOverlay); phBulkAssignMode = true; }
    };
    var phBulkAssignMode = false;
    document.addEventListener('projectselected', function(e){
        if (e.detail.uid !== 'manual-<?php echo $ph_uid; ?>') return; // solo dal selettore di questo popup
        if (!phBulkAssignMode) return;
        phBulkAssignMode = false;
        var ids = Object.keys(state.selectedIds);
        fetch('pomodoro_storico.php?ph_ajax=bulk_action', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'ids=' + ids.join(',') + '&bulk_op=set_project&new_project_id=' + e.detail.id })
        .then(function(r){ return r.json(); })
        .then(function(){ toast('Progetto assegnato'); window.phToggleSelectMode(); phLoadPage(true); });
    });

    /* ---------------- click sulle righe: selezione, collega progetto, swipe-delete ---------------- */
    document.addEventListener('click', function(e){
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
            var manualOverlay = document.getElementById('ps-modal-manual-' + '<?php echo $ph_uid; ?>');
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
        if (e.detail.uid !== 'manual-<?php echo $ph_uid; ?>') return; // solo dal selettore di questo popup
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
    window.phToggleManual = function(){
        var panel = document.getElementById('phManualPanel');
        var opening = !panel.classList.contains('show');
        panel.classList.toggle('show');
        if (opening) phResetManualFields();
    };
    function phResetManualFields(){
        document.getElementById('phManualData').value = phTodayStr();
        document.getElementById('phManualInizio').value = '';
        document.getElementById('phManualFine').value = '';
        document.getElementById('phManualDurata').value = '';
        // richiesta 11: se stiamo guardando un progetto specifico, precompila il selettore su quello
        if (window.psSetValue) {
            if (state.contextProjectId > 0) {
                window.psSetValue('ps-modal-manual-<?php echo $ph_uid; ?>', state.contextProjectId, state.contextProjectName || 'Progetto', state.contextProjectColor || '');
            } else {
                window.psSetValue('ps-modal-manual-<?php echo $ph_uid; ?>', 0, 'Nessun progetto', '');
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
        var projInput = document.getElementById('ps-value-manual-<?php echo $ph_uid; ?>');
        var progettoId = projInput ? projInput.value : 0;
        if (!data || !inizio || !fine) { toast('Compila data, inizio e fine (o la durata per farli calcolare)'); return; }
        fetch('pomodoro_storico.php?ph_ajax=manual_insert', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'data=' + encodeURIComponent(data) + '&ora_inizio=' + encodeURIComponent(inizio) + '&ora_fine=' + encodeURIComponent(fine) + '&project_id=' + encodeURIComponent(progettoId) + '&mode=0' })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            if (!resp.success) { toast(resp.error || 'Errore'); return; }
            toast('Sessione aggiunta');
            document.getElementById('phManualPanel').classList.remove('show');
            phResetManualFields();
            phLoadPage(true);
        }).catch(function(){ toast('Errore di rete'); });
    };

    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { var o = document.getElementById('phOverlay'); if (o.classList.contains('show')) window.closePomodoroStorico(); } });
})();
</script>
<?php endif; ?>