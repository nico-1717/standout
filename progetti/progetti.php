<?php
require_once 'config.php';
require_once 'security_core.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* ==========================================================================
   EREDITARIETÀ COLORE — identica a categories_fc.php, tabella progetti
   ========================================================================== */
/* ==========================================================================
   NOTA SCHEMA: collegamento 1 a 1 tra un progetto e una materia di
   categories_fc, se non l'hai ancora creata:

   CREATE TABLE progetto_categoria_fc (
       project_id INT NOT NULL,
       fc_category_id INT NOT NULL,
       creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (project_id),
       UNIQUE KEY uniq_fc_category (fc_category_id),
       CONSTRAINT fk_pcf_progetto FOREIGN KEY (project_id) REFERENCES progetti(id) ON DELETE CASCADE,
       CONSTRAINT fk_pcf_categoria FOREIGN KEY (fc_category_id) REFERENCES fc_categories(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   project_id come PRIMARY KEY + fc_category_id UNIQUE = un progetto ha al
   massimo una materia collegata, e una materia è collegata al massimo a un
   progetto: è un vero rapporto 1 a 1 in entrambe le direzioni.
   ========================================================================== */
if (!function_exists('prog_resolve_color')) {
    function prog_resolve_color($conn, $parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
        while ($parentId > 0 && $guard < 50) {
            $guard++;
            $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM projects WHERE id = $parentId AND user_id = " . intval($userId));
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if (!$row) break;
            if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
            $parentId = intval($row['parent_id']);
        }
        return '#8E8E93';
    }
}
if (!function_exists('prog_cascade_color')) {
    function prog_cascade_color($conn, $projectId, $color, $userId, $depth = 0) {
        if ($depth > 40) return;
        $colorEsc = mysqli_real_escape_string($conn, $color);
        $res = mysqli_query($conn, "SELECT id FROM projects WHERE parent_id = " . intval($projectId) . " AND user_id = " . intval($userId) . " AND color_edited = 0");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            mysqli_query($conn, "UPDATE projects SET color='$colorEsc' WHERE id=" . intval($row['id']));
            prog_cascade_color($conn, $row['id'], $color, $userId, $depth + 1);
        }
    }
}
if (!function_exists('prog_children_map')) {
    // id -> parent_id (0 se radice) per tutti i progetti dell'utente: la base per
    // qualunque calcolo di sotto-albero (usata da delete, bulk_delete, bulk_reparent).
    function prog_children_map($conn, $userId) {
        $childrenOf = [];
        $res = mysqli_query($conn, "SELECT id, parent_id FROM projects WHERE user_id = " . intval($userId));
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $childrenOf[$pid][] = (int)$row['id'];
        }
        return $childrenOf;
    }
}
if (!function_exists('prog_subtree_ids')) {
    // $rootId e tutti i suoi discendenti, appiattiti in un unico array.
    function prog_subtree_ids($rootId, &$childrenOf) {
        $all = []; $stack = [(int)$rootId];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $all[] = $cur;
            foreach (($childrenOf[$cur] ?? []) as $c) { $stack[] = $c; }
        }
        return $all;
    }
}

/* ==========================================================================
   AJAX leggero: toggle stato checkbox (niente reload, per una UI reattiva)
   ========================================================================== */
/* Nessuna scrittura senza il token della sessione e senza origine nostra. */
if (isset($_GET['ajax']) && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'progetti', 0, 400, 60);
}

/* Preferiti: la stellina nella modifica progetto. I preferiti compaiono in
   cima al selettore progetti e nel tastino a stella del planner. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_todo') {
    header('Content-Type: application/json');
    $pid = intval($_POST['project_id'] ?? 0);
    $val = intval($_POST['value'] ?? 0) === 1 ? 1 : 0;
    if ($pid <= 0 || !sec_owns('projects', $pid, (int)$utente_corrente['id'])) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'not_found']));
    }
    sec_q("UPDATE projects SET todo_enabled = ? WHERE id = ? AND user_id = ?",
          [$val, $pid, (int)$utente_corrente['id']], 'iii');
    die(json_encode(['ok' => true, 'enabled' => $val === 1]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_favorite') {
    header('Content-Type: application/json');
    $pid = intval($_POST['project_id'] ?? 0);
    $val = intval($_POST['value'] ?? 0) === 1 ? 1 : 0;
    if ($pid <= 0 || !sec_owns('projects', $pid, (int)$utente_corrente['id'])) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'not_found']));
    }
    sec_q("UPDATE projects SET is_favorite = ? WHERE id = ? AND user_id = ?",
          [$val, $pid, (int)$utente_corrente['id']], 'iii');
    die(json_encode(['ok' => true, 'favorite' => $val === 1]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_stato') {
    header('Content-Type: application/json');
    $pid = intval($_POST['project_id'] ?? 0);
    $res = mysqli_query($conn, "SELECT status FROM projects WHERE id=$pid AND user_id=" . intval($current_user_id));
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) { echo json_encode(['success' => false]); exit(); }
    $prevStatus = $row['status'];
    $order = ['in_progress', 'completed', 'planned'];
    $cur = array_search($prevStatus, $order);
    $next = $order[($cur === false ? 0 : $cur + 1) % 3];
    $nextEsc = mysqli_real_escape_string($conn, $next);
    mysqli_query($conn, "UPDATE projects SET status='$nextEsc' WHERE id=$pid AND user_id=" . intval($current_user_id));

    $cascaded = [];   // id resi completati a cascata (per aggiornare la UI)
    $snapshot = [];   // stato PRECEDENTE dei discendenti, per poterli ripristinare
    $restored = [];   // id ripristinati (id => nuovo stato)

    if ($next === 'completed') {
        // entriamo in "completato": completa tutti i discendenti, ma prima
        // salviamo il loro stato attuale così, se l'utente si accorge dell'errore
        // e cambia subito stato, possiamo rimetterli com'erano (richiesta cache).
        $childrenOf = prog_children_map($conn, $current_user_id);
        $subtree = prog_subtree_ids($pid, $childrenOf);
        $others = array_values(array_filter($subtree, function ($x) use ($pid) { return $x !== $pid; }));
        if (!empty($others)) {
            $idsStr = implode(',', $others);
            $sres = mysqli_query($conn, "SELECT id, status FROM projects WHERE user_id=" . intval($current_user_id) . " AND id IN ($idsStr)");
            while ($sres && ($srow = mysqli_fetch_assoc($sres))) {
                $snapshot[] = ['id' => (int)$srow['id'], 'status' => $srow['status']];
            }
            mysqli_query($conn, "UPDATE projects SET status='completed' WHERE user_id=" . intval($current_user_id) . " AND id IN ($idsStr)");
            $cascaded = $others;
        }
    } elseif ($prevStatus === 'completed') {
        // stiamo USCENDO da "completato": se il client ci ha passato lo snapshot
        // salvato al momento del completamento, rimettiamo i discendenti com'erano.
        $restoreRaw = $_POST['restore'] ?? '';
        if ($restoreRaw !== '') {
            $decoded = json_decode($restoreRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $rid = intval($item['id'] ?? 0);
                    $rst = $item['status'] ?? '';
                    if ($rid > 0 && in_array($rst, $order, true)) {
                        $rstEsc = mysqli_real_escape_string($conn, $rst);
                        mysqli_query($conn, "UPDATE projects SET status='$rstEsc' WHERE id=$rid AND user_id=" . intval($current_user_id));
                        $restored[$rid] = $rst;
                    }
                }
            }
        }
    }
    echo json_encode(['success' => true, 'stato' => $next, 'cascaded' => $cascaded, 'snapshot' => $snapshot, 'restored' => $restored]);
    exit();
}

/* ==========================================================================
   AJAX leggero: totale secondi pomodoro per un progetto + discendenti
   ========================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'project_ids_subtree') {
    header('Content-Type: application/json');
    $rootId = intval($_GET['id'] ?? 0);
    $all = [];
    $res = mysqli_query($conn, "SELECT id, parent_id FROM projects WHERE user_id = " . intval($current_user_id));
    $childrenOf = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        $childrenOf[$pid][] = (int)$row['id'];
    }
    $stack = [$rootId];
    while (!empty($stack)) {
        $cur = array_pop($stack);
        $all[] = $cur;
        foreach (($childrenOf[$cur] ?? []) as $c) $stack[] = $c;
    }
    echo json_encode(['ids' => $all]);
    exit();
}

if (!function_exists('prog_category_link_blocked')) {
    // true se $categoryId, un suo discendente o un suo antenato sono già
    // collegati a un progetto DIVERSO da $excludeProjectId (controllo di
    // sicurezza lato server: la UI disabilita già queste materie, ma un
    // salvataggio non deve mai fidarsi solo del client)
    function prog_category_link_blocked($conn, $userId, $categoryId, $excludeProjectId) {
        $categoryId = intval($categoryId);
        if ($categoryId <= 0) return false;
        $parentOf = []; $childrenOf = [];
        $res = mysqli_query($conn, "SELECT id, parent_id FROM fc_categories WHERE user_id=" . intval($userId));
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $id = (int)$row['id'];
            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $parentOf[$id] = $pid;
            $childrenOf[$pid][] = $id;
        }
        $chain = [$categoryId];
        $walk = $parentOf[$categoryId] ?? 0; $guard = 0;
        while ($walk > 0 && $guard < 100) { $chain[] = $walk; $walk = $parentOf[$walk] ?? 0; $guard++; }
        $stack = $childrenOf[$categoryId] ?? []; $guard2 = 0;
        while (!empty($stack) && $guard2 < 5000) {
            $guard2++;
            $cur = array_pop($stack);
            $chain[] = $cur;
            foreach (($childrenOf[$cur] ?? []) as $c) { $stack[] = $c; }
        }
        $idsStr = implode(',', array_unique($chain));
        $lres = mysqli_query($conn, "SELECT project_id FROM project_fc_categories WHERE fc_category_id IN ($idsStr) AND project_id != " . intval($excludeProjectId) . " LIMIT 1");
        return ($lres && mysqli_num_rows($lres) > 0);
    }
}

/* ==========================================================================
   AZIONI POST classiche (form nascosti + reload), come in categories_fc.php
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    /* Anche i moduli classici (salva, elimina, riordina) sono scritture:
       il token arriva nel body come csrf_token, aggiunto da nisama_fetch.js,
       oppure nell'header X-CSRF-Token. Senza, la richiesta si ferma qui. */
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'progetti_form', 0, 200, 60);

    $action = $_POST['action'];
    $redirectId = intval($_POST['redirect_id'] ?? 0);

    if ($action === 'save') {
        $progId = intval($_POST['project_id'] ?? 0);
        $name = trim($_POST['progetto_name'] ?? '');
        $note = $_POST['progetto_note'] ?? '';
        $parentId = intval($_POST['parent_id'] ?? 0);
        $colorMode = (($_POST['color_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
        $manualColor = trim($_POST['progetto_color'] ?? '');
        $dataObiettivo = trim($_POST['data_obiettivo'] ?? '');
        $dataScadenza = trim($_POST['data_scadenza'] ?? '');

        if ($parentId === $progId) $parentId = 0; 

        if ($progId > 0 && $parentId > 0) {
            $walk = $parentId; $guard = 0; $cycle = false;
            while ($walk > 0 && $guard < 60) {
                $guard++;
                if ($walk === $progId) { $cycle = true; break; }
                $ares = mysqli_query($conn, "SELECT parent_id FROM projects WHERE id=$walk AND user_id=" . intval($current_user_id));
                $arow = $ares ? mysqli_fetch_assoc($ares) : null;
                if (!$arow) break;
                $walk = $arow['parent_id'] === null ? 0 : (int)$arow['parent_id'];
            }
            if ($cycle) {
                $curRes = mysqli_query($conn, "SELECT parent_id FROM projects WHERE id=$progId AND user_id=" . intval($current_user_id));
                $curRow = $curRes ? mysqli_fetch_assoc($curRes) : null;
                $parentId = ($curRow && $curRow['parent_id'] !== null) ? (int)$curRow['parent_id'] : 0;
            }
        }
        $parentSql = $parentId > 0 ? $parentId : 'NULL';
        $dataObSql = ($dataObiettivo !== '') ? ("'" . mysqli_real_escape_string($conn, $dataObiettivo) . "'") : 'NULL';
        $dataScSql = ($dataScadenza !== '') ? ("'" . mysqli_real_escape_string($conn, $dataScadenza) . "'") : 'NULL';

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor;
            $colorEditedFlag = 1;
        } else {
            $finalColor = prog_resolve_color($conn, $parentId, $current_user_id);
            $colorEditedFlag = 0;
        }
        $nameEsc = mysqli_real_escape_string($conn, $name);
        $noteEsc = mysqli_real_escape_string($conn, $note);
        $colorEsc = mysqli_real_escape_string($conn, $finalColor);

        if ($progId === 0 && $name !== '') {
            $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(custom_order),-1)+1 AS n FROM projects WHERE user_id=" . intval($current_user_id) . " AND " . ($parentId > 0 ? "parent_id=$parentId" : "parent_id IS NULL"));
            $nextOrder = $ordRes ? intval(mysqli_fetch_assoc($ordRes)['n']) : 0;
            mysqli_query($conn, "INSERT INTO projects (user_id, parent_id, name, notes, color, color_edited, status, custom_order, created_at, target_date, due_date)
                                  VALUES (" . intval($current_user_id) . ", $parentSql, '$nameEsc', '$noteEsc', '$colorEsc', $colorEditedFlag, 'in_progress', $nextOrder, NOW(), $dataObSql, $dataScSql)");
            $redirectId = mysqli_insert_id($conn);
            $progId = (int)$redirectId;
        } elseif ($progId > 0 && $name !== '') {
            mysqli_query($conn, "UPDATE projects SET name='$nameEsc', notes='$noteEsc', parent_id=$parentSql, color='$colorEsc', color_edited=$colorEditedFlag,
                                  target_date=$dataObSql, due_date=$dataScSql WHERE id=$progId AND user_id=" . intval($current_user_id));
            // cascata sempre: sia che il colore sia stato scelto a mano, sia che sia
            // automatico (in quel caso $finalColor è già quello del NUOVO padre, così
            // spostando un progetto a colore automatico anche i figli auto lo seguono)
            prog_cascade_color($conn, $progId, $finalColor, $current_user_id);
            $redirectId = $redirectId ?: $progId;
        }

        if ($progId > 0 && $name !== '') {
            $fcCategoryId = intval($_POST['fc_category_id'] ?? 0);
            if ($fcCategoryId > 0) {
                $catOwnRes = mysqli_query($conn, "SELECT id FROM fc_categories WHERE id=$fcCategoryId AND user_id=" . intval($current_user_id));
                if ($catOwnRes && mysqli_num_rows($catOwnRes) > 0 && !prog_category_link_blocked($conn, $current_user_id, $fcCategoryId, $progId)) {
                    // materia libera (lei, i suoi figli e i suoi padri non sono di un altro progetto): ok collegare
                    mysqli_query($conn, "DELETE FROM project_fc_categories WHERE project_id=$progId");
                    mysqli_query($conn, "INSERT INTO project_fc_categories (project_id, fc_category_id) VALUES ($progId, $fcCategoryId)");
                }
                // se bloccata, ignoriamo silenziosamente la richiesta: la UI non avrebbe dovuto permetterla
            } else {
                mysqli_query($conn, "DELETE FROM project_fc_categories WHERE project_id=$progId");
            }
        }
    } elseif ($action === 'delete') {
        $progId = intval($_POST['project_id'] ?? 0);
        $deleteSessions = !empty($_POST['delete_sessions']);
        // dove tornare dopo (richiesta 3: restare sul progetto che si stava guardando).
        // se stai eliminando proprio quel progetto, ripiega sul suo padre.
        $parentOfDeleted = 0;
        $pRes = ($progId > 0) ? mysqli_query($conn, "SELECT parent_id FROM projects WHERE id=$progId AND user_id=" . intval($current_user_id)) : null;
        $pRow = $pRes ? mysqli_fetch_assoc($pRes) : null;
        $own = ($pRow !== null);
        if ($pRow) $parentOfDeleted = $pRow['parent_id'] === null ? 0 : (int)$pRow['parent_id'];
        if ($own) {
            if ($deleteSessions) {
                $childrenOf = prog_children_map($conn, $current_user_id);
                $subtree = prog_subtree_ids($progId, $childrenOf);
                mysqli_query($conn, "DELETE FROM pomodoro_sessions WHERE user_id = " . intval($current_user_id) . " AND project_id IN (" . implode(',', $subtree) . ")");
            }
            mysqli_query($conn, "DELETE FROM projects WHERE id=$progId AND user_id=" . intval($current_user_id));
        }
        if ($redirectId === $progId || $redirectId === 0) $redirectId = $parentOfDeleted;
    } elseif ($action === 'bulk_delete') {
        // stessa logica del delete singolo, ma su piu' progetti fratelli in un colpo
        // solo: essendo fratelli i loro sotto-alberi non si sovrappongono mai.
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['project_ids'] ?? '')))));
        $deleteSessions = !empty($_POST['delete_sessions']);
        if (!empty($ids)) {
            $ownRes = mysqli_query($conn, "SELECT id FROM projects WHERE user_id = " . intval($current_user_id) . " AND id IN (" . implode(',', $ids) . ")");
            $ownIds = [];
            while ($ownRes && ($row = mysqli_fetch_assoc($ownRes))) { $ownIds[] = (int)$row['id']; }
            if (!empty($ownIds)) {
                if ($deleteSessions) {
                    $childrenOf = prog_children_map($conn, $current_user_id);
                    $subtree = [];
                    foreach ($ownIds as $rootId) { $subtree = array_merge($subtree, prog_subtree_ids($rootId, $childrenOf)); }
                    mysqli_query($conn, "DELETE FROM pomodoro_sessions WHERE user_id = " . intval($current_user_id) . " AND project_id IN (" . implode(',', $subtree) . ")");
                }
                mysqli_query($conn, "DELETE FROM projects WHERE user_id = " . intval($current_user_id) . " AND id IN (" . implode(',', $ownIds) . ")");
            }
        }
    } elseif ($action === 'bulk_set_color') {
        // "auto" azzera color_edited e ricalcola dal padre ATTUALE di ciascun progetto
        // (letto dal DB, mai dal client); un colore vero e proprio lo marca manuale.
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['project_ids'] ?? '')))));
        $colorInput = trim($_POST['color'] ?? '');
        $isAuto = ($colorInput === 'auto');
        $isValidHex = (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $colorInput);
        if ($isAuto || $isValidHex) {
            foreach ($ids as $pid) {
                $ownRes = mysqli_query($conn, "SELECT parent_id FROM projects WHERE id=$pid AND user_id=" . intval($current_user_id));
                $ownRow = $ownRes ? mysqli_fetch_assoc($ownRes) : null;
                if (!$ownRow) continue;
                if ($isAuto) {
                    $pidParent = $ownRow['parent_id'] === null ? 0 : (int)$ownRow['parent_id'];
                    $finalColor = prog_resolve_color($conn, $pidParent, $current_user_id);
                    $flag = 0;
                } else {
                    $finalColor = $colorInput;
                    $flag = 1;
                }
                $colorEsc = mysqli_real_escape_string($conn, $finalColor);
                mysqli_query($conn, "UPDATE projects SET color='$colorEsc', color_edited=$flag WHERE id=$pid AND user_id=" . intval($current_user_id));
                prog_cascade_color($conn, $pid, $finalColor, $current_user_id);
            }
        }
    } elseif ($action === 'bulk_reparent') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['project_ids'] ?? '')))));
        $newParentId = intval($_POST['new_parent_id'] ?? 0);

        // vietato spostare un progetto sotto se stesso o un suo discendente
        // (anche uno degli ALTRI selezionati, dato che si spostano tutti insieme):
        // stesso identico controllo che l'utente vede gia' disabilitato nell'albero.
        $childrenOf = prog_children_map($conn, $current_user_id);
        $forbidden = [];
        foreach ($ids as $rootId) { foreach (prog_subtree_ids($rootId, $childrenOf) as $fid) { $forbidden[$fid] = true; } }

        $valid = ($newParentId === 0);
        if ($newParentId > 0 && !isset($forbidden[$newParentId])) {
            $parentOwn = mysqli_query($conn, "SELECT id FROM projects WHERE id=$newParentId AND user_id=" . intval($current_user_id));
            $valid = ($parentOwn && mysqli_num_rows($parentOwn) > 0);
        }

        if ($valid) {
            $parentSql = $newParentId > 0 ? $newParentId : 'NULL';
            foreach ($ids as $pid) {
                $ownRes = mysqli_query($conn, "SELECT color, color_edited FROM projects WHERE id=$pid AND user_id=" . intval($current_user_id));
                $ownRow = $ownRes ? mysqli_fetch_assoc($ownRes) : null;
                if (!$ownRow) continue;
                if ((int)$ownRow['color_edited'] === 1) {
                    $finalColor = $ownRow['color'];
                } else {
                    $finalColor = prog_resolve_color($conn, $newParentId, $current_user_id);
                }
                $colorEsc = mysqli_real_escape_string($conn, $finalColor);
                mysqli_query($conn, "UPDATE projects SET parent_id=$parentSql, color='$colorEsc' WHERE id=$pid AND user_id=" . intval($current_user_id));
                prog_cascade_color($conn, $pid, $finalColor, $current_user_id);
            }
        }
    } elseif ($action === 'reorder') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['project_ids'] ?? '')));
        $ord = 0;
        foreach ($ids as $id) {
            mysqli_query($conn, "UPDATE projects SET custom_order=$ord WHERE id=$id AND user_id=" . intval($current_user_id));
            $ord++;
        }
    }

    header('Location: progetti.php' . ($redirectId > 0 ? ('?id=' . $redirectId) : ''));
    exit();
}

/* ==========================================================================
   LETTURA DATI per il rendering
   ========================================================================== */
if (!function_exists('prog_build_branch')) {
    function prog_build_branch($id, &$byId, &$childrenOf) {
        if (!isset($byId[$id])) return null;
        $node = $byId[$id];
        if (!empty($childrenOf[$id])) {
            foreach ($childrenOf[$id] as $cid) {
                $c = prog_build_branch($cid, $byId, $childrenOf);
                if ($c) $node['children'][] = $c;
            }
        }
        return $node;
    }
}

$byId = [];
$childrenOf = [];
$res = mysqli_query($conn, "SELECT id, parent_id, name, notes, color, color_edited, status, custom_order, created_at, is_favorite, todo_enabled, target_date AS data_obiettivo, due_date AS data_scadenza FROM projects WHERE user_id = " . intval($current_user_id) . " ORDER BY custom_order ASC, id ASC");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $row['children'] = [];
    $row['stato'] = $row['status']; // Mappa retrocompatibile per le funzioni interne
    $row['note'] = $row['notes'];   // la colonna è `notes`, ma tutto il codice legge `note` (bug: le note non comparivano in modifica)
    $byId[(int)$row['id']] = $row;
    $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    $childrenOf[$pid][] = (int)$row['id'];
}

$secondsOwn = [];
$sres = mysqli_query($conn, "SELECT project_id, SUM(duration_seconds) AS tot FROM pomodoro_sessions WHERE user_id = " . intval($current_user_id) . " AND project_id IS NOT NULL GROUP BY project_id");
while ($sres && ($srow = mysqli_fetch_assoc($sres))) { $secondsOwn[(int)$srow['project_id']] = (int)$srow['tot']; }

if (!function_exists('prog_total_seconds')) {
    function prog_total_seconds($id, &$childrenOf, &$secondsOwn, &$memo) {
        if (isset($memo[$id])) return $memo[$id];
        $total = $secondsOwn[$id] ?? 0;
        foreach (($childrenOf[$id] ?? []) as $cid) { $total += prog_total_seconds($cid, $childrenOf, $secondsOwn, $memo); }
        $memo[$id] = $total;
        return $total;
    }
}
$totalMemo = [];
foreach ($byId as $pid => $p) { prog_total_seconds($pid, $childrenOf, $secondsOwn, $totalMemo); }

$countOwn = [];
$cntRes = mysqli_query($conn, "SELECT project_id, COUNT(*) AS n FROM pomodoro_sessions WHERE user_id = " . intval($current_user_id) . " AND project_id IS NOT NULL GROUP BY project_id");
while ($cntRes && ($cntRow = mysqli_fetch_assoc($cntRes))) { $countOwn[(int)$cntRow['project_id']] = (int)$cntRow['n']; }
if (!function_exists('prog_total_count')) {
    // stessa forma di prog_total_seconds ma per il numero di pomodori: serve a
    // sapere se un progetto (o un suo discendente) ne ha almeno uno collegato,
    // per decidere se chiedere all'utente cosa farne prima di eliminarlo.
    function prog_total_count($id, &$childrenOf, &$countOwn, &$memo) {
        if (isset($memo[$id])) return $memo[$id];
        $total = $countOwn[$id] ?? 0;
        foreach (($childrenOf[$id] ?? []) as $cid) { $total += prog_total_count($cid, $childrenOf, $countOwn, $memo); }
        $memo[$id] = $total;
        return $total;
    }
}
$countMemo = [];
foreach ($byId as $pid => $p) { prog_total_count($pid, $childrenOf, $countOwn, $countMemo); }

$jsProjLookup = [];
$catLinks = []; 
$clRes = mysqli_query($conn, "SELECT pcf.project_id, pcf.fc_category_id, c.name, c.color
                               FROM project_fc_categories pcf
                               INNER JOIN projects p ON p.id = pcf.project_id AND p.user_id = " . intval($current_user_id) . "
                               INNER JOIN fc_categories c ON c.id = pcf.fc_category_id AND c.user_id = " . intval($current_user_id));
if ($clRes) {
    while ($clRow = mysqli_fetch_assoc($clRes)) {
        $ccolor = (!empty($clRow['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $clRow['color'])) ? $clRow['color'] : '#A0A0A0';
        $catLinks[(int)$clRow['project_id']] = ['id' => (int)$clRow['fc_category_id'], 'name' => $clRow['name'], 'color' => $ccolor];
    }
}

/* ==========================================================================
   MATERIE (fc_categories): mappa padre/nome, serve lato client per calcolare
   quali materie vanno bloccate nel selettore del popup di modifica progetto
   (una materia non è selezionabile se lei stessa, un suo figlio o un suo
   padre sono già collegati a un progetto DIVERSO da quello in modifica).
   ========================================================================== */
$catParentOf = [];
$catNameById = [];
$fcRes = mysqli_query($conn, "SELECT id, parent_id, name FROM fc_categories WHERE user_id = " . intval($current_user_id));
while ($fcRes && ($fcRow = mysqli_fetch_assoc($fcRes))) {
    $fcId = (int)$fcRow['id'];
    $catParentOf[$fcId] = $fcRow['parent_id'] === null ? 0 : (int)$fcRow['parent_id'];
    $catNameById[$fcId] = $fcRow['name'];
}

// tutti i collegamenti progetto<->materia (non solo quelli del progetto che si
// sta aprendo: il client sceglie quali ignorare in base al progetto in modifica)
$jsCatLinks = [];
foreach ($catLinks as $clPid => $clInfo) {
    if (isset($byId[$clPid])) {
        $jsCatLinks[] = ['project_id' => $clPid, 'project_name' => $byId[$clPid]['name'], 'category_id' => $clInfo['id']];
    }
}
foreach ($byId as $pidKey => $p) {
    $jsProjLookup[$pidKey] = [
        'name' => $p['name'],
        'color' => (!empty($p['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $p['color'])) ? $p['color'] : '#8E8E93',
        'color_edited' => (int)$p['color_edited'],
        'is_favorite'  => (int)($p['is_favorite'] ?? 0),
        'parent_id' => $p['parent_id'] === null ? 0 : (int)$p['parent_id'],
        'data_obiettivo' => $p['data_obiettivo'],
        'data_scadenza' => $p['data_scadenza'],
        'fc_category' => $catLinks[$pidKey] ?? null,
        'session_count' => $countMemo[$pidKey] ?? 0,
        'note' => $p['note'] ?? '',
        'has_note' => (trim((string)($p['note'] ?? '')) !== '') ? 1 : 0,
    ];
}

$rootIds = $childrenOf[0] ?? [];
// i progetti principali (senza padre) vanno in ordine alfabetico, sia nell'elenco
// (barra laterale) sia nei chip in alto
usort($rootIds, function ($a, $b) use ($byId) {
    $na = isset($byId[$a]) ? $byId[$a]['name'] : '';
    $nb = isset($byId[$b]) ? $byId[$b]['name'] : '';
    return strcasecmp($na, $nb);
});
$rootProjects = [];
foreach ($rootIds as $rid) { if (isset($byId[$rid])) $rootProjects[] = $byId[$rid]; }

/* La vista "Preferiti": non è un progetto vero, è un elenco costruito al
   volo con i progetti segnati con la stellina. I loro sottoprogetti si
   aprono come sempre, così si arriva in fondo senza cambiare pagina. */
$viewFavorites = isset($_GET['view']) && $_GET['view'] === 'fav';
$favProjects = [];
foreach ($byId as $fid => $fp) {
    if ((int)($fp['is_favorite'] ?? 0) === 1) $favProjects[] = (int)$fid;
}
usort($favProjects, function ($a, $b) use ($byId) {
    return strcasecmp($byId[$a]['name'] ?? '', $byId[$b]['name'] ?? '');
});

$selectedId = intval($_GET['id'] ?? 0);
if ($selectedId === 0 && !$viewFavorites && !empty($rootIds)) { $selectedId = $rootIds[0]; }
$selectedProject = $viewFavorites ? null : ($byId[$selectedId] ?? null);
$selectedTree = $selectedProject ? prog_build_branch($selectedId, $byId, $childrenOf) : null;

/* i rami dei preferiti, ciascuno completo dei suoi figli */
$favTrees = [];
if ($viewFavorites) {
    foreach ($favProjects as $fid) $favTrees[] = prog_build_branch($fid, $byId, $childrenOf);
}

$ancestorChain = [];
if ($selectedProject) {
    $walk = $selectedProject['parent_id'] === null ? 0 : (int)$selectedProject['parent_id'];
    $guard = 0;
    while ($walk > 0 && isset($byId[$walk]) && $guard < 50) {
        $guard++;
        array_unshift($ancestorChain, $byId[$walk]);
        $walk = $byId[$walk]['parent_id'] === null ? 0 : (int)$byId[$walk]['parent_id'];
    }
}

// radice del progetto attualmente visualizzato: serve per tenere "acceso" (anche
// se in sotto-progetto) il chip del progetto principale in alto (richiesta 18)
$selectedRootId = $selectedId;
if (!empty($ancestorChain)) { $selectedRootId = (int)$ancestorChain[0]['id']; }

$prColorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];

$pomoInCorso = false;
$pomoChk = mysqli_query($conn, "SELECT id FROM pomodoro_sessions WHERE user_id=" . intval($current_user_id) . " AND end_time IS NULL LIMIT 1");
if ($pomoChk && mysqli_num_rows($pomoChk) > 0) { $pomoInCorso = true; }
if (!$pomoInCorso) {
    $pausaChk = mysqli_query($conn, "SELECT user_id FROM pomodoro_current_breaks WHERE user_id=" . intval($current_user_id) . " LIMIT 1");
    if ($pausaChk && mysqli_num_rows($pausaChk) > 0) { $pomoInCorso = true; }
}

/* La stella in due versioni: piena quando è fra i preferiti, tratteggiata e
   spenta quando no. Sta in una funzione perché la stessa forma serve al PHP
   che disegna la pagina e al JavaScript che la cambia al volo. */
function prog_star_svg(bool $piena): string {
    $d = 'M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z';
    if ($piena) {
        return '<svg class="pr-star pr-star-hero" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
    }
    return '<svg class="pr-star pr-star-hero pr-star-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-dasharray="3 2.2" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function prog_fmt_duration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '';
    $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
    if ($m > 0) return $m . 'm';
    return '<1m';
}

if (!function_exists('prog_date_tags_info')) {
    // Tag data per i sottoprogetti: uno per l'obiettivo e uno per la scadenza,
    // se presenti entrambi vengono mostrati entrambi. Ogni tag ha uno 'status'
    // ('past' = rossa, già passata; 'today' = arancio, è oggi; 'future' = blu,
    // deve ancora arrivare) calcolato confrontando solo il giorno, non l'orario.
    function prog_date_tags_info($node) {
        $out = [];
        $defs = [
            ['field' => 'data_obiettivo', 'icon' => '🎯'],
            ['field' => 'data_scadenza', 'icon' => '⏰'],
        ];
        foreach ($defs as $def) {
            $raw = $node[$def['field']] ?? null;
            if (empty($raw)) continue;
            $ts = strtotime($raw);
            if ($ts === false) continue;
            $todayYmd = date('Y-m-d');
            $dateYmd = date('Y-m-d', $ts);
            if ($dateYmd < $todayYmd) $status = 'past';
            elseif ($dateYmd === $todayYmd) $status = 'today';
            else $status = 'future';
            $out[] = ['label' => date('d M', $ts), 'icon' => $def['icon'], 'status' => $status];
        }
        return $out;
    }
}
function prog_render_row($node, &$childrenOf, &$totalMemo, $depth = 0, $pomoInCorso = false) {
    global $catLinks;
    $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#8E8E93';
    $hasChildren = !empty($node['children']);
    $nameEsc = htmlspecialchars($node['name'], ENT_QUOTES);
    $noteEsc = htmlspecialchars($node['note'] ?? '', ENT_QUOTES);
    $stato = $node['stato'];
    $durata = prog_fmt_duration($totalMemo[$node['id']] ?? 0);
    $cat = $catLinks[$node['id']] ?? null;
    $dateTags = prog_date_tags_info($node);
    ?>
    <div class="pr-row-wrap" data-id="<?php echo (int)$node['id']; ?>" data-parent="<?php echo (int)($node['parent_id'] ?? 0); ?>" style="--row-color:<?php echo $color; ?>;">
        <div class="pr-row" data-id="<?php echo (int)$node['id']; ?>">
            <div class="pr-row-content">
                <?php if ($hasChildren): ?>
                <button type="button" class="pr-arrow<?php echo $depth < 1 ? ' open' : ''; ?>" onclick="event.stopPropagation(); prToggleChildren(<?php echo (int)$node['id']; ?>, this)" aria-label="Apri/chiudi sotto-progetti">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                <?php else: ?>
                <span class="pr-arrow-spacer"></span>
                <?php endif; ?>
                <span class="pr-grip" aria-label="Trascina per riordinare">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                </span>
                <button type="button" class="pr-check pr-check-<?php echo $stato; ?>" data-id="<?php echo (int)$node['id']; ?>" aria-label="Cambia stato">
                    <svg class="pr-ic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                    <svg class="pr-ic-done" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    <svg class="pr-ic-planned" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3" stroke-linecap="round"/></svg>
                </button>
                <span class="pr-select-chk" aria-hidden="true">✓</span>
                <div class="pr-main">
                    <div class="pr-row-line1">
                        <div class="pr-name-container">
                            <span class="pr-name<?php echo $stato === 'completed' ? ' pr-name-done' : ''; ?>" onclick="prTapRow(<?php echo (int)$node['id']; ?>)"><span class="pr-name-track"><?php
                                /* stellina davanti al nome: si vede a colpo d'occhio quali sono tra i preferiti */
                                if ((int)($node['is_favorite'] ?? 0) === 1) {
                                    echo '<svg class="pr-star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>';
                                }
                                echo $nameEsc;
                            ?></span></span>
                        </div>
                    </div>
                    <?php /* La riga dei tag c'è sempre, perché ospita il tasto
                             per il sottoprogetto: prima stava sulla riga del
                             titolo, accanto al pomodoro, dove rubava larghezza
                             al nome e stonava con gli altri comandi. */ ?>
                    <div class="pr-tags-row">
                        <?php /* sempre per primo a sinistra, prima di nota, categoria e date */ ?>
                        <button type="button" class="pr-inline-add-btn" onclick="prShowInlineInput(event, <?php echo (int)$node['id']; ?>, this)" aria-label="Aggiungi sottoprogetto rapido">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 5v10a2 2 0 0 0 2 2h8"/>
                                <path d="m16 14 3 3-3 3"/>
                            </svg>
                        </button>
                        <?php if ($noteEsc !== ''): ?>
                        <button type="button" class="pr-note-tag" data-note-raw="<?php echo $noteEsc; ?>" data-note-id="<?php echo (int)$node['id']; ?>"
                                onclick="event.stopPropagation(); prOpenNotePeek(<?php echo (int)$node['id']; ?>, this)" aria-label="Mostra nota">
                            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/></svg>
                        </button>
                        <?php endif; ?>
                        <?php if ($cat): ?>
                        <button type="button" class="pr-cat-tag" style="--cat-color:<?php echo htmlspecialchars($cat['color'], ENT_QUOTES); ?>;"
                                onclick="event.stopPropagation(); window.location.href='visualizzazione_fc.php?category_id=<?php echo (int)$cat['id']; ?>'">
                            <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                            <?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>
                        </button>
                        <?php endif; ?>
                        <?php foreach ($dateTags as $dt): ?>
                        <span class="pr-date-tag pr-date-tag-<?php echo $dt['status']; ?>">
                            <?php echo $dt['icon']; ?> <?php echo htmlspecialchars($dt['label'], ENT_QUOTES); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="pr-pomo-wrap">
                <button type="button" class="pr-pomo-btn"<?php echo $pomoInCorso ? ' disabled aria-label="Hai già un pomodoro in corso"' : ' onclick="window.location.href=\'pomodoro.php?project_id=' . (int)$node['id'] . '\'" aria-label="Avvia pomodoro"'; ?>>
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/></svg>
                </button>
                <?php /* le ore stanno sotto al pulsante, non più accanto al nome */ ?>
                <?php if ($durata): ?><span class="pr-duration"><?php echo $durata; ?></span><?php endif; ?>
                </div>
            </div>
            <div class="swipe-actions">
                <button type="button" class="swipe-btn swipe-btn-edit" onclick="prOpenEdit(<?php echo (int)$node['id']; ?>)">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    <span>Modifica</span>
                </button>
                <button type="button" class="swipe-btn swipe-btn-delete" onclick="prConfirmDelete(<?php echo (int)$node['id']; ?>, <?php echo $hasChildren ? 'true' : 'false'; ?>)">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    <span>Elimina</span>
                </button>
            </div>
        </div>
        <?php if ($hasChildren): ?>
        <div class="pr-children<?php echo $depth >= 1 ? ' collapsed' : ''; ?>">
            <?php foreach ($node['children'] as $child) { prog_render_row($child, $childrenOf, $totalMemo, $depth + 1, $pomoInCorso); } ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<title>Progetti</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<style>
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --surface-3:#E7E7EC;
    --shadow-sheet:0 -14px 60px rgba(0,0,0,.18);
    --shadow-pop:0 30px 70px -22px rgba(0,0,0,.45);
    --r-card:22px; --r-btn:15px;
    --spring:cubic-bezier(.32,.72,0,1);
    --pop:cubic-bezier(.34,1.4,.64,1);
    --ease-out:cubic-bezier(.16,1,.3,1);
    --safe-top:env(safe-area-inset-top, 0px); --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){ :root{
    --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
    --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
    --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
    --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
    --header-glass:rgba(16,16,18,.78);
    --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
    --surface-3:#3A3A3C;
    --shadow-sheet:0 -14px 60px rgba(0,0,0,.6);
}}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
/* Safari su iPhone ingrandisce da sé il testo dei blocchi che considera
   "contenuto principale", e lo fa solo su alcune righe: era questo a rendere
   il nome di certi sottoprogetti più grande degli altri, senza che nel CSS
   ci fosse alcuna differenza. */
html{ -webkit-text-size-adjust:100%; text-size-adjust:100%; }
body{ margin:0; min-height:100vh; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", system-ui, Roboto, Arial, sans-serif;
    font-size:16px; line-height:1.4; -webkit-font-smoothing:antialiased; padding-bottom:calc(90px + var(--safe-bottom)); overscroll-behavior-y:none; }
button{ font-family:inherit; }

.app-header{ position:sticky; top:0; z-index:100; padding:calc(var(--safe-top) + 12px) 16px 12px; background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px); border-bottom:.5px solid var(--hairline);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.hdr-title{ font-size:24px; font-weight:800; letter-spacing:-.02em; }
.hdr-drawer-btn{ display:flex; align-items:center; gap:6px; background:var(--surface-2); border:none; border-radius:12px; padding:9px 13px; color:var(--text); font-weight:700; font-size:13.5px; flex-shrink:0; }
.hdr-drawer-btn:active{ transform:scale(.95); }
.hdr-title{ flex-shrink:0; }
.hdr-root-pills{ flex:1; min-width:0; display:flex; gap:7px; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; justify-content:flex-start; padding:2px; }
.hdr-root-pills::-webkit-scrollbar{ display:none; }
.hdr-root-pill{ flex-shrink:0; display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer; padding:7px 13px; border-radius:999px;
    background:var(--surface-2); color:var(--text-2); font-weight:700; font-size:12.5px; white-space:nowrap;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring), background-color .2s, color .2s; }
.hdr-root-pill-dot{ width:8px; height:8px; border-radius:50%; background:var(--pill-color, var(--blue)); flex-shrink:0; }
.hdr-root-pill.active{ background:var(--pill-color, var(--blue)); color:#fff; box-shadow:0 6px 14px -5px color-mix(in srgb, var(--pill-color, var(--blue)) 60%, transparent); }
.hdr-root-pill.active .hdr-root-pill-dot{ background:rgba(255,255,255,.9); }
/* radice del sotto-progetto che stai guardando: colorata ma più tenue, comunque ben visibile (richiesta 18) */
.hdr-root-pill.active-faded{ background:color-mix(in srgb, var(--pill-color, var(--blue)) 22%, var(--surface)); color:color-mix(in srgb, var(--pill-color, var(--blue)) 70%, var(--text)); box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--pill-color, var(--blue)) 40%, transparent); font-weight:800; }
.hdr-root-pill.active-faded .hdr-root-pill-dot{ background:var(--pill-color, var(--blue)); }
.hdr-root-pill:active{ transform:scale(.94); }

.page{ max-width:640px; margin:0 auto; padding:14px 14px calc(40px + var(--safe-bottom)); }

/* ---------- briciole di pane (percorso antenati) ---------- */
.pr-crumbs{ display:flex; align-items:center; gap:4px; overflow-x:auto; white-space:nowrap; padding:2px 2px 10px; scrollbar-width:none; animation:prIn .35s var(--spring) both; }
.pr-crumbs::-webkit-scrollbar{ display:none; }
.pr-crumb{ display:flex; align-items:center; gap:4px; border:none; background:none; padding:5px 8px; border-radius:9px; color:var(--text-2); font-size:12.5px; font-weight:700; flex-shrink:0; }
.pr-crumb:active{ background:var(--surface-2); }
.pr-crumb-dot{ width:7px; height:7px; border-radius:50%; background:var(--crumb-color, var(--blue)); flex-shrink:0; }
.pr-crumb-sep{ color:var(--text-3); flex-shrink:0; font-size:12px; }

/* ---------- header progetto selezionato ---------- */
.pr-hero{ background:var(--surface); border-radius:var(--r-card); padding:18px; box-shadow:var(--shadow-card); margin-bottom:16px;
    border-left:5px solid var(--accent, var(--blue)); animation:prIn .45s var(--spring) both; }
@keyframes prIn{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:translateY(0); } }
.pr-hero-top{ display:flex; align-items:flex-start; gap:12px; }
.pr-hero-title-wrap{ flex:1; min-width:0; display:inline-flex; align-items:center; gap:4px; flex-wrap:wrap; }
.pr-hero-title{ font-size:21px; font-weight:800; }
.pr-hero-title.pr-name-done{ text-decoration:line-through; color:var(--text-2); }
.pr-hero-dates{ display:flex; gap:14px; margin-top:8px; flex-wrap:wrap; }
.pr-hero-date{ font-size:12.5px; color:var(--text-2); font-weight:600; display:flex; align-items:center; gap:4px; }
.pr-fav-head{
    display:flex; align-items:center; gap:9px; padding:16px 4px 10px;
    color:var(--text);
}
.pr-fav-head svg{ width:20px; height:20px; color:#E0B84A; flex-shrink:0; }
.pr-fav-head span{ font-size:21px; font-weight:800; }
.pr-fav-head small{
    margin-left:auto; font-size:12px; color:var(--text-2);
    background:var(--surface-2); padding:3px 10px; border-radius:999px;
}
.hdr-pill-fav{ display:inline-flex; align-items:center; gap:5px; }
.hdr-pill-fav svg{ color:#E0B84A; }

.pr-fav-btn{
    display:flex; align-items:center; gap:8px; width:100%;
    padding:11px 13px; border-radius:14px; border:none; cursor:pointer;
    background:var(--surface-2); color:var(--text-2); font:inherit; font-size:14px;
    transition:background-color .22s, color .22s;
}
.pr-fav-btn svg{ width:18px; height:18px; }
.pr-fav-btn.is-fav{ background:#F5E7C0; color:#7A6127; }
.pr-fav-btn.is-fav svg{ fill:currentColor; }

.pr-hero-todo{ margin-top:14px; }
.pr-hero-todo.pr-hidden{ display:none; }
.pr-hero-btn-todo{ background:var(--surface-2); color:var(--text-2); }
.pr-hero-btn-todo.is-on{ background:var(--accent); color:#fff; }

.pr-hero-note{
    margin-top:10px; font-size:14px; color:var(--text-2); line-height:1.5;
    /* una nota lunga non deve allungare la pagina all'infinito: oltre una
       certa altezza scorre dentro il suo riquadro, così i sottoprogetti
       restano raggiungibili senza scorrere per un pezzo */
    max-height:32vh; overflow-y:auto; -webkit-overflow-scrolling:touch;
    scrollbar-width:none; padding-right:2px;
    overscroll-behavior:contain;
}
.pr-hero-note::-webkit-scrollbar{ width:0; height:0; }
.pr-hero-cat-chip{ display:inline-flex; align-items:center; gap:6px; margin-top:10px; border:none; padding:6px 12px 6px 10px; border-radius:999px;
    background:color-mix(in srgb, var(--cat-color) 14%, var(--surface)); color:var(--cat-color); font-size:12px; font-weight:800;
    box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--cat-color) 30%, transparent); transition:transform .15s var(--spring); }
.pr-hero-cat-chip:active{ transform:scale(.95); }
/* Con il tondo in più i pulsanti non ci stavano più in riga e spingevano fuori
   il bordo, facendo scorrere l'intera pagina in orizzontale. Più piccoli, meno
   distanti, e se ancora non bastasse vanno a capo invece di uscire. */
.pr-hero-actions{ display:flex; flex-wrap:wrap; gap:8px; row-gap:8px; margin-top:14px; max-width:100%; }
.pr-hero-quickadd{ width:26px; height:26px; border-radius:8px; flex-shrink:0; align-self:center; }
.pr-hero-quickadd svg{ width:14px; height:14px; }
.pr-hero-btn{ display:flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:none; flex-shrink:0; }
.pr-hero-btn svg{ width:17px; height:17px; }
.pr-hero-btn-pomo{ background:var(--accent); color:#fff; box-shadow:var(--btn-hl), 0 6px 16px -6px color-mix(in srgb, var(--accent) 70%, transparent); transition:transform .15s var(--spring), opacity .2s; }
.pr-hero-btn-pomo[disabled]{ background:var(--surface-2); color:var(--text-3); box-shadow:none; opacity:.7; }
.pr-hero-btn-stats{ background:var(--surface-2); color:var(--text); transition:background-color .2s, color .2s; }
.pr-hero-btn-stats.active{ background:var(--blue); color:#fff; }
.pr-hero-btn-add{ background:var(--blue-soft); color:var(--blue); }
.pr-hero-btn-quick{ background:var(--surface-2); color:var(--text-2); }
.pr-hero-btn:active{ transform:scale(.9); }

/* ---------- righe progetto ---------- */
.pr-view-bar{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:10px; }
.pr-view-switch{ display:flex; background:var(--surface); box-shadow:var(--shadow-card); border-radius:12px; padding:3px; gap:2px; }
.pr-view-switch button{ border:none; background:none; padding:7px 13px; border-radius:9px; font-weight:700; font-size:12.5px; color:var(--text-2); display:flex; align-items:center; gap:5px; transition:background-color .25s var(--spring), color .25s; }
.pr-view-switch button.active{ background:var(--blue); color:#fff; }
.pr-view-switch button svg{ flex-shrink:0; }
.pr-view-dates-btn{ width:36px; height:36px; flex-shrink:0; border:none; border-radius:50%; background:var(--surface); box-shadow:var(--shadow-card);
    color:var(--text-2); display:flex; align-items:center; justify-content:center; transition:transform .15s var(--spring), color .2s; }
.pr-view-dates-btn:active{ transform:scale(.9); color:var(--blue); }

.pr-list{ background:var(--surface); border-radius:var(--r-card); box-shadow:var(--shadow-card); padding:6px 8px; overflow:hidden; }

.pr-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(148px, 1fr)); gap:10px; }
.pr-grid-card{ position:relative; background:var(--surface); color:var(--text); border-radius:16px; box-shadow:var(--shadow-card); padding:14px 14px 12px; text-align:left; border:none; overflow:hidden; animation:prIn .4s var(--spring) both; }
.pr-grid-card:nth-child(1){ animation-delay:.02s; } .pr-grid-card:nth-child(2){ animation-delay:.05s; }
.pr-grid-card:nth-child(3){ animation-delay:.08s; } .pr-grid-card:nth-child(4){ animation-delay:.11s; }
.pr-grid-card:nth-child(5){ animation-delay:.14s; } .pr-grid-card:nth-child(n+6){ animation-delay:.16s; }
.pr-grid-card::before{ content:""; position:absolute; top:0; left:0; right:0; height:4px; background:var(--row-color); }
.pr-grid-card:active{ transform:scale(.96); }
.pr-grid-dot{ width:30px; height:30px; border-radius:10px; background:color-mix(in srgb, var(--row-color) 16%, var(--surface)); color:var(--row-color); display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
.pr-grid-name{ font-size:14px; font-weight:700; line-height:1.3; color:var(--text); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:8px; min-height:36px; }
.pr-grid-name.pr-name-done{ color:var(--text-2); text-decoration:line-through; }
.pr-grid-meta{ display:flex; align-items:center; justify-content:space-between; font-size:11px; color:var(--text-2); font-weight:700; }

/* ---------- tag materia + post-it nota (sotto il nome, in elenco e in griglia) ---------- */
.pr-tags-row{ display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-top:5px; }
.pr-cat-tag{ display:inline-flex; align-items:center; gap:4px; border:none; padding:3px 9px 3px 7px; border-radius:999px; cursor:pointer;
    background:color-mix(in srgb, var(--cat-color, var(--blue)) 15%, var(--surface)); color:var(--cat-color, var(--blue));
    font-size:11px; font-weight:800; letter-spacing:-.1px; line-height:1.3; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    transition:transform .15s var(--spring), filter .2s; }
.pr-cat-tag svg{ flex-shrink:0; }
.pr-cat-tag:active{ transform:scale(.93); filter:brightness(.97); }
.pr-note-tag{ display:inline-flex; align-items:center; justify-content:center; border:none; width:24px; height:22px; border-radius:7px; cursor:pointer;
    background:color-mix(in srgb, var(--orange) 20%, var(--surface)); color:var(--orange);
    transition:transform .15s var(--spring), filter .2s; }
.pr-note-tag:active{ transform:scale(.9); filter:brightness(.97); }
.pr-date-tag{ display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:999px;
    font-size:11px; font-weight:800; letter-spacing:-.1px; line-height:1.3; white-space:nowrap; }
.pr-date-tag-past{ background:color-mix(in srgb, var(--red) 15%, var(--surface)); color:var(--red); }
.pr-date-tag-today{ background:color-mix(in srgb, var(--orange) 18%, var(--surface)); color:var(--orange); }
.pr-date-tag-future{ background:color-mix(in srgb, var(--blue) 14%, var(--surface)); color:var(--blue); }

/* ---------- pannello "Scadenze e obiettivi" ---------- */
.pr-dates-row{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; }
.pr-dates-sort-btn{ display:flex; align-items:center; gap:5px; border:none; background:var(--surface-2); color:var(--text); font-weight:700; font-size:12px; padding:8px 12px; border-radius:11px; flex-shrink:0; transition:transform .15s var(--spring); }
.pr-dates-sort-btn:active{ transform:scale(.95); }
.pr-dates-filters{ display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
.pr-dates-chip{ border:none; background:var(--surface-2); color:var(--text-2); font-weight:700; font-size:12px; padding:6px 13px; border-radius:999px; transition:background-color .2s, color .2s; }
.pr-dates-chip.active{ background:var(--blue); color:#fff; }
.pr-dates-list{ display:flex; flex-direction:column; gap:8px; }
.pr-dates-item{ display:flex; align-items:center; gap:10px; padding:11px 12px; border-radius:14px; background:var(--surface-2); border:none; width:100%; text-align:left; cursor:pointer; transition:transform .15s var(--spring); }
.pr-dates-item:active{ transform:scale(.98); }
.pr-dates-item-dot{ width:10px; height:10px; border-radius:50%; background:var(--item-color, var(--blue)); flex-shrink:0; }
.pr-dates-item-main{ flex:1; min-width:0; }
.pr-dates-item-name{ font-size:14px; font-weight:700; color:var(--text); white-space:nowrap; max-width:100%;
    overflow:hidden; position:relative; touch-action:pan-y; }
.pr-dates-item-path{ font-size:11.5px; color:var(--text-3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.pr-dates-item .pr-date-tag{ flex-shrink:0; }
.pr-dates-empty{ text-align:center; padding:30px 16px; color:var(--text-2); font-size:13.5px; }

/* post-it che mostra la nota, con tastino di modifica */
.pr-note-peek-overlay{ position:fixed; inset:0; z-index:9760; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.4);
    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); opacity:0; pointer-events:none; transition:opacity .24s var(--ease-out); padding:24px; }
.pr-note-peek-overlay.show{ opacity:1; pointer-events:auto; }
/* Il post-it era giallo squillante con il carattere a mano: bello da vedere di
   sfuggita, faticoso da leggere quando la nota è lunga — ed è per leggerla che
   lo si apre. Fondo neutro, carattere di sistema, e più largo su schermo
   grande. Resta la piega dell'angolo e la leggera inclinazione: quel tanto che
   basta a farlo sembrare un foglietto. */
.pr-note-peek{ position:relative; width:100%; max-width:340px; background:#FBF9F4;
    color:var(--text, #262523); border-radius:4px 4px 14px 14px; padding:16px 18px 16px; box-shadow:0 24px 54px -20px rgba(0,0,0,.45), inset 0 0 0 .5px rgba(0,0,0,.06);
    transform:scale(.85) rotate(-1deg); opacity:0; transition:transform .4s var(--pop), opacity .24s; }
@media (min-width:700px){ .pr-note-peek{ max-width:460px; padding:20px 22px; } }
.pr-note-peek-overlay.show .pr-note-peek{ transform:scale(1) rotate(-1deg); opacity:1; }
.pr-note-peek::before{ content:""; position:absolute; top:-8px; left:50%; transform:translateX(-50%) rotate(-2deg); width:70px; height:16px;
    background:rgba(255,255,255,.65); box-shadow:0 2px 5px rgba(0,0,0,.10); border-radius:2px; }
.pr-note-peek-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
.pr-note-peek-title{ font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.4px; color:var(--text-3, #9A958C); }
.pr-note-peek-actions{ display:flex; gap:6px; }
.pr-note-peek-btn{ width:28px; height:28px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center;
    background:rgba(0,0,0,.06); color:var(--text-2, #5C574F); transition:transform .15s var(--spring), background-color .2s; }
.pr-note-peek-btn:active{ transform:scale(.9); }
.pr-note-peek-body{ font-size:15px; line-height:1.6; white-space:pre-wrap; word-break:break-word; max-height:60vh; overflow-y:auto;
    font-family:-apple-system, system-ui, "Segoe UI", Roboto, sans-serif; }
/* Su computer la barra di scorrimento resta sempre in vista e taglia il
   foglietto in due. Qui diventa sottile e incolore, e si accende solo quando
   il dito o il mouse stanno lavorando lì dentro. */
.pr-note-peek-body{ scrollbar-width:thin; scrollbar-color:transparent transparent; }
.pr-note-peek-body:hover, .pr-note-peek-body:focus-within{ scrollbar-color:rgba(0,0,0,.22) transparent; }
.pr-note-peek-body::-webkit-scrollbar{ width:6px; }
.pr-note-peek-body::-webkit-scrollbar-track{ background:transparent; }
.pr-note-peek-body::-webkit-scrollbar-thumb{ background:transparent; border-radius:99px; }
.pr-note-peek-body:hover::-webkit-scrollbar-thumb{ background:rgba(0,0,0,.20); }
@media (prefers-color-scheme: dark){
    .pr-note-peek{ background:#2A2925; color:#EAE6DE; box-shadow:0 24px 54px -20px rgba(0,0,0,.75), inset 0 0 0 .5px rgba(255,255,255,.06); }
    .pr-note-peek::before{ background:rgba(255,255,255,.18); }
    .pr-note-peek-title{ color:#9A958C; }
    .pr-note-peek-btn{ background:rgba(255,255,255,.10); color:#EAE6DE; }
    .pr-note-peek-body:hover::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.22); }
}
.pr-grid-empty{ grid-column:1/-1; text-align:center; padding:30px 16px; color:var(--text-2); font-size:13.5px; }
.pr-row-wrap{ position:relative; animation:prIn .4s var(--spring) both; }
.pr-row-wrap:nth-child(1){ animation-delay:.02s; } .pr-row-wrap:nth-child(2){ animation-delay:.05s; }
.pr-row-wrap:nth-child(3){ animation-delay:.08s; } .pr-row-wrap:nth-child(4){ animation-delay:.11s; }
.pr-row-wrap:nth-child(5){ animation-delay:.14s; } .pr-row-wrap:nth-child(n+6){ animation-delay:.16s; }
.pr-row{ position:relative; overflow:hidden; border-radius:13px; }
.pr-row-content{ position:relative; background:var(--surface); display:flex; align-items:center; gap:6px; padding:9px 6px; z-index:2; transition:transform .05s linear; }
/* Freccetta, maniglia e checkbox stanno insieme in testa alla riga: fra loro
   lo spazio è quasi nullo, così si leggono come un gruppo solo e lasciano più
   respiro al nome del progetto. Il distacco vero comincia dopo la checkbox. */
.pr-arrow + .pr-grip, .pr-arrow-spacer + .pr-grip{ margin-left:-5px; }
.pr-grip + .pr-check{ margin-left:-5px; }
.pr-check{ margin-right:1px; }
.pr-grip{ color:var(--text-3); display:flex; touch-action:none; cursor:grab; padding:4px 2px; flex-shrink:0; }
.pr-check{ width:26px; height:26px; border:none; background:none; padding:0; flex-shrink:0; position:relative; color:var(--text-3); }
.pr-check svg{ position:absolute; inset:0; width:100%; height:100%; opacity:0; transition:opacity .2s, transform .3s var(--spring); }
.pr-check .pr-ic-empty{ opacity:1; }
.pr-check-completed .pr-ic-empty{ opacity:0; } .pr-check-completed .pr-ic-done{ opacity:1; color:var(--green); transform:scale(1.05); }
.pr-check-planned .pr-ic-empty{ opacity:0; } .pr-check-planned .pr-ic-planned{ opacity:1; color:var(--purple); }
.pr-check.pr-just-tapped{ animation:prCheckPop .35s var(--spring); }
@keyframes prCheckPop{ 0%{ transform:scale(1); } 45%{ transform:scale(1.35); } 100%{ transform:scale(1); } }

/* ---------- checkbox di selezione multipla (sotto-progetti, tutti i livelli) ---------- */
.pr-select-chk{ display:none; width:22px; height:22px; border-radius:50%; border:2px solid var(--hairline-2,#ccc); flex-shrink:0;
    align-items:center; justify-content:center; color:transparent; font-size:13px; font-weight:800; transition:background-color .2s, border-color .2s, color .2s; }
/* richiesta 1: in modalità selezione TUTTE le righe (anche annidate) mostrano la
   checkbox al posto della spunta di completamento */
#prList.pr-sub-selecting .pr-check{ display:none; }
#prList.pr-sub-selecting .pr-select-chk{ display:flex; }
#prList.pr-sub-selecting .pr-grip{ display:none; }
#prList.pr-sub-selecting .pr-pomo-btn{ display:none; }
#prList.pr-sub-selecting .pr-inline-add-btn{ display:none; }
#prList.pr-sub-selecting .swipe-actions{ display:none; }
#prList.pr-sub-selecting .pr-duration{ display:none; }
.pr-row-wrap.pr-sub-selected > .pr-row > .pr-row-content{ background:color-mix(in srgb, var(--blue) 7%, var(--surface)); }
.pr-row-wrap.pr-sub-selected > .pr-row > .pr-row-content > .pr-select-chk{ background:var(--blue); border-color:var(--blue); color:#fff; }
/* discendenti auto-selezionati perché è selezionato un loro antenato: bloccati
   (richiesta 2), mostrati con un segno di spunta più tenue e non deselezionabili */
.pr-row-wrap.pr-sub-locked > .pr-row > .pr-row-content > .pr-select-chk{ background:color-mix(in srgb, var(--blue) 45%, var(--surface)); border-color:color-mix(in srgb, var(--blue) 45%, var(--surface)); color:#fff; }
.pr-row-wrap.pr-sub-locked > .pr-row > .pr-row-content{ opacity:.85; }

.pr-sub-bulkbar{ display:none; align-items:center; gap:8px; padding:9px 4px; margin-bottom:8px; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.pr-sub-bulkbar::-webkit-scrollbar{ display:none; }
.pr-sub-bulkbar.show{ display:flex; }
.pr-sub-bulkbar span{ font-size:12.5px; color:var(--text-2); font-weight:700; flex:1 0 auto; white-space:nowrap; }
.pr-sub-bulk-btn{ flex-shrink:0; padding:9px 13px; border-radius:12px; border:none; font-size:12.5px; font-weight:700; white-space:nowrap; background:var(--surface-2); color:var(--text); transition:transform .15s var(--spring); }
.pr-sub-bulk-btn:active{ transform:scale(.94); }
.pr-sub-bulk-btn.accent{ background:var(--blue-soft, rgba(0,122,255,.12)); color:var(--blue); }
.pr-sub-bulk-btn.danger{ background:var(--red-soft, rgba(255,59,48,.12)); color:var(--red); }
.pr-main {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    flex: 1;
    min-width: 0;
    gap: 3px;
}
.pr-row-line1 {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    min-width: 0;
}
.pr-star{
    width:16px; height:16px; vertical-align:-2px; margin-right:6px;
    color:#E0B84A; flex-shrink:0;
}
.pr-star-hero{ width:19px; height:19px; vertical-align:-3px; margin-right:7px; }
/* non preferito: contorno tratteggiato e colore spento — si vede che il posto
   della stella c'è, e che è vuoto */
.pr-star-off{ color:var(--text-3); opacity:.75; }
/* il pulsante non deve sembrare un pulsante: è la stella stessa a essere
   toccabile, con un'area un po' più larga del disegno per il dito */
.pr-star-btn{ border:none; background:none; padding:0 2px 0 0; margin:0; cursor:pointer; line-height:0;
    color:#E0B84A; vertical-align:baseline; transition:transform .18s var(--spring); }
.pr-star-btn:not(.is-fav){ color:var(--text-3); }
.pr-star-btn:active{ transform:scale(.82); }
.pr-name-container {
    display: inline-flex;
    align-items: center;
    flex: 1;
    min-width: 0;
    gap: 2px;
}
/* la durata non è più in questa riga: il nome si prende tutto lo spazio */
.pr-name {
    font-size: 15px;
    font-weight: 600;
    cursor: pointer; /* mostra la manina da PC sul nome del sottoprogetto */
    white-space: nowrap;
    min-width: 0;
    max-width: 100%;
    flex: 0 1 auto;
    overflow: hidden;
    position: relative;
    touch-action: pan-y; /* il trascinamento orizzontale lo gestiamo noi via JS */
}
.pr-name-track{ display:inline-block; white-space:nowrap; will-change:transform; }
.pr-name-done{ color:var(--text-2); text-decoration:line-through; }
.pr-duration{ font-size:11.5px; color:var(--text-2); font-weight:700; font-variant-numeric:tabular-nums; flex-shrink:0; }
/* stretta: 26px per una freccia da 14 erano dieci pixel di aria per parte, e
   in un elenco annidato quello spazio moltiplica a ogni livello */
.pr-arrow{ width:18px; height:26px; padding:0; border:none; background:none; color:var(--text-3); flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:transform .28s var(--spring); }
.pr-arrow svg{ width:14px; height:14px; flex-shrink:0; }
.pr-arrow.open{ transform:rotate(90deg); }
.pr-arrow-spacer{ width:18px; flex-shrink:0; }
/* pulsante pomodoro con le ore sotto: incolonnati e centrati */
.pr-pomo-wrap{
    display:flex; flex-direction:column; align-items:center; gap:3px;
    flex-shrink:0; min-width:38px;
}
.pr-pomo-wrap .pr-duration{
    font-size:9.5px; line-height:1.1; color:var(--text-2); text-align:center;
    white-space:nowrap; margin:0; padding:0;
}
/* il tasto per il sottoprogetto, sotto il nome e discreto */
.pr-inline-add-btn{ margin-top:0; order:-1; flex-shrink:0; align-self:center; }

.pr-pomo-btn{ width:30px; height:30px; border-radius:50%; border:none; background:var(--row-color); color:#fff; flex-shrink:0; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px -1px color-mix(in srgb, var(--row-color) 60%, transparent); transition:transform .15s var(--spring), opacity .2s; }
.pr-pomo-btn:active{ transform:scale(.88); }
.pr-pomo-btn[disabled]{ background:var(--text-3); box-shadow:none; opacity:.5; }

.swipe-actions{ position:absolute; top:0; left:0; bottom:0; display:flex; z-index:1; }
.swipe-btn{ width:78px; border:none; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; color:#fff; font-size:11px; font-weight:700; }
.swipe-btn-edit{ background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 12%), var(--blue)); }
.swipe-btn-delete{ background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 12%), var(--red)); }
.swipe-btn:active{ filter:brightness(.92); }

.pr-note-preview{ font-size:12.5px; color:var(--text-2); padding:0 10px 8px; line-height:1.4; max-height:38px; overflow:hidden; }
/* Indentazione ridotta: i sottoprogetti profondi finivano schiacciati a
   destra con pochissimo spazio per il nome. La linea verticale prende il
   colore del progetto padre, così si capisce comunque di chi sono figli, e
   parte sotto la freccetta di espansione invece che più a destra. */
.pr-children{
    /* La freccetta è larga 26px: il suo centro cade a 13px, ed è lì che
       nasce la linea. Prima partiva più a destra e rubava spazio. */
    margin-left:12px; padding-left:9px; overflow:hidden;
    border-left:2px solid color-mix(in srgb, var(--row-color, var(--hairline)) 55%, transparent);
    max-height:4000px; transition:max-height .35s var(--spring), opacity .28s;
}
.pr-children.collapsed{ max-height:0; opacity:0; pointer-events:none; }
/* IL PROBLEMA: la riga trascinata si svuotava di continuo. La causa non era il
   trascinamento ma l'animazione d'ingresso qui sopra: ogni volta che la riga
   viene rimessa fra le altre (e succede a ogni scambio, decine di volte in un
   gesto) il browser la considera appena inserita e **fa ripartire l'animazione
   dall'inizio** — che con `both` e un ritardo significa restare invisibile per
   qualche decimo di secondo. Da qui il lampeggio.
   Durante il trascinamento l'animazione si spegne per tutte le righe. */
body.pr-dragging-now .pr-row-wrap{ animation:none !important; }

/* e la riga in mano si vede bene: sollevata, non sbiadita — prima con opacità
   .5 sembrava già sparita per conto suo */
.pr-row-wrap.dragging{
    opacity:1; z-index:5;
    transform:scale(1.015);
    box-shadow:0 12px 28px -12px rgba(0,0,0,.35);
    border-radius:14px;
}
/* Mentre si trascina, niente selezione del testo e niente lente di
   ingrandimento: tenendo premuto, il telefono cercava di selezionare il nome
   sotto il dito invece di far partire lo spostamento. */
body.pr-dragging-now{
    -webkit-user-select:none; -moz-user-select:none; user-select:none;
    -webkit-touch-callout:none;
}
body.pr-dragging-now *{ -webkit-user-select:none; user-select:none; }
/* La maniglia non è mai selezionabile, nemmeno prima che il gesto scatti:
   è lì che si tiene premuto, ed è lì che partiva la selezione. */
.pr-grip, .pr-check, .pr-arrow{ -webkit-user-select:none; user-select:none; -webkit-touch-callout:none; }
.pr-row-wrap.drag-ghost{ position:fixed; z-index:500; pointer-events:none; box-shadow:0 12px 34px rgba(0,0,0,.28); border-radius:13px; opacity:.96; }

.pr-empty{ text-align:center; padding:60px 20px; color:var(--text-2); }
.pr-empty-icon{ width:56px; height:56px; margin:0 auto 14px; color:var(--text-3); }
.pr-empty-title{ font-size:17px; font-weight:800; color:var(--text); margin-bottom:6px; }
.pr-btn-create-first{ margin-top:16px; display:inline-flex; align-items:center; gap:8px; padding:12px 20px; border-radius:14px; border:none; background:var(--blue); color:#fff; font-weight:700; }

/* ---------- drawer progetti radice ---------- */
.pd-scrim{ position:fixed; inset:0; z-index:9600; background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px); opacity:0; pointer-events:none; transition:opacity .3s; }
.pd-scrim.show{ opacity:1; pointer-events:auto; }
.pd-panel{ position:fixed; top:0; right:0; z-index:9601; width:min(300px,84vw); height:100vh; height:100dvh; background:var(--surface);
    transform:translateX(105%); transition:transform .42s var(--spring); display:flex; flex-direction:column; box-shadow:-14px 0 40px rgba(0,0,0,.25); }
.pd-panel.show{ transform:translateX(0); }
.pd-head{ padding:calc(var(--safe-top) + 18px) 18px 12px; font-size:19px; font-weight:800; border-bottom:.5px solid var(--hairline); display:flex; justify-content:space-between; align-items:center; }
.pd-close{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text-2); display:flex; align-items:center; justify-content:center; }
.pd-list{ flex:1; overflow-y:auto; padding:10px 10px calc(20px + var(--safe-bottom)); }
.pd-fav-star{ width:17px; height:17px; color:#E0B84A; flex-shrink:0; }
.pd-item-fav.active .pd-fav-star{ color:#fff; }
.pd-item{ display:flex; align-items:center; gap:10px; padding:12px 12px; border-radius:13px; margin-bottom:2px; cursor:pointer; }
.pd-item:active{ background:var(--surface-2); }
.pd-item.active{ background:var(--row-color, var(--blue)); }
.pd-item.active .pd-item-name, .pd-item.active .pd-item-count{ color:#fff; }
.pd-dot{ width:10px; height:10px; border-radius:50%; background:var(--row-color); flex-shrink:0; }
.pd-item-name{ flex:1; font-weight:700; font-size:14.5px; }
.pd-item-count{ font-size:12px; color:var(--text-2); font-weight:700; }
.pd-add{ display:flex; align-items:center; gap:8px; padding:12px; color:var(--blue); font-weight:700; font-size:14px; border:1.5px dashed var(--hairline-2); border-radius:13px; margin:6px 10px 0; }

/* ---------- edit sheet (iOS) ---------- */
.pr-sheet-overlay{ position:fixed; inset:0; z-index:9700; display:flex; align-items:flex-end; justify-content:center; background:rgba(0,0,0,.42);
    -webkit-backdrop-filter:blur(10px) saturate(140%); backdrop-filter:blur(10px) saturate(140%);
    opacity:0; pointer-events:none; transition:opacity .34s var(--ease-out); }
.pr-sheet-overlay.show{ opacity:1; pointer-events:auto; }
/* niente overflow:hidden: .pr-sheet ha un transform ed e' quindi il blocco contenitore
   dei popup position:fixed dei selettori (progetto/materia). Li ritaglierebbe. */
.pr-sheet{ width:100%; max-width:640px; max-height:90vh; background:var(--surface); border-radius:26px 26px 0 0; display:flex; flex-direction:column;
    transform:translateY(101%); transition:transform .5s var(--spring); box-shadow:var(--shadow-sheet); }
.pr-sheet-overlay.show .pr-sheet{ transform:translateY(0); }
.pr-sheet-grabber{ width:38px; height:5px; border-radius:3px; background:var(--hairline-2); margin:8px auto 0; flex-shrink:0; }
.pr-sheet-head{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; border-bottom:.5px solid var(--hairline); flex-shrink:0; }
.pr-sheet-title{ font-size:19px; font-weight:800; letter-spacing:-.4px; }
.pr-sheet-close{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text-2);
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    transition:transform .2s var(--spring), background-color .2s; }
.pr-sheet-close:active{ transform:scale(.88); background:var(--surface-3); }
.pr-sheet-body{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:18px; }

.pr-field{ margin-bottom:18px; }
.pr-field label{ display:block; font-size:11.5px; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:.7px; margin-bottom:8px; padding-left:2px; }
.pr-field input[type="text"], .pr-field input[type="date"]{ width:100%; padding:13px 14px; border-radius:14px; border:none; background:var(--surface-2); color:var(--text);
    font-size:15.5px; font-family:inherit; box-shadow:inset 0 0 0 .5px var(--hairline);
    transition:box-shadow .25s var(--ease-out), background-color .25s; }
.pr-field input[type="text"]:focus, .pr-field input[type="date"]:focus{ outline:none; background:var(--surface);
    box-shadow:inset 0 0 0 1.5px var(--blue), 0 0 0 4px color-mix(in srgb, var(--blue) 12%, transparent); }
.pr-field input[type="text"]::placeholder{ color:var(--text-3); }
.pr-field-row{ display:flex; gap:12px; }
.pr-field-row .pr-field{ flex:1; min-width:0; }

/* ---------- selettore colore (logica invariata: auto dal padre / manuale) ---------- */
.pr-color-card{ background:var(--surface-2); border-radius:18px; padding:14px; box-shadow:inset 0 0 0 .5px var(--hairline); }
.pr-color-preview-row{ display:flex; align-items:center; gap:11px; margin-bottom:14px; padding:10px 12px; background:var(--surface); border-radius:13px;
    box-shadow:0 2px 8px -4px rgba(0,0,0,.2), inset 0 0 0 .5px var(--hairline); }
.pr-color-preview-icon{ width:32px; height:32px; border-radius:10px; background:var(--pr-preview-color, var(--blue)); flex-shrink:0;
    box-shadow:0 5px 12px -3px color-mix(in srgb, var(--pr-preview-color, var(--blue)) 60%, transparent), inset 0 -1px 2px rgba(0,0,0,.2);
    transition:background-color .3s var(--ease-out), box-shadow .3s var(--ease-out); }
.pr-color-preview-name{ font-size:13.5px; font-weight:700; color:var(--text-2); letter-spacing:-.1px; }
.pr-color-grid{ display:flex; flex-wrap:wrap; gap:11px; }
.pr-color-swatch{ width:34px; height:34px; border-radius:50%; border:none; padding:0; display:flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0;
    box-shadow:inset 0 -1.5px 3px rgba(0,0,0,.18), 0 2px 5px -2px rgba(0,0,0,.35);
    transition:transform .34s var(--pop), box-shadow .28s var(--ease-out); }
.pr-color-swatch:active{ transform:scale(.86); }
.pr-color-swatch.sel{ transform:scale(1.1);
    box-shadow:inset 0 -1.5px 3px rgba(0,0,0,.18), 0 0 0 2.5px var(--surface-2), 0 0 0 4.5px var(--sw, var(--text-2)),
               0 5px 12px -3px color-mix(in srgb, var(--sw, var(--text-2)) 55%, transparent); }
.pr-color-swatch svg{ opacity:0; transform:scale(.5); transition:opacity .2s, transform .3s var(--pop); }
.pr-color-swatch.sel svg{ opacity:1; transform:none; }
.pr-color-swatch-auto{ background:var(--surface); box-shadow:inset 0 0 0 1.5px var(--hairline-2); color:var(--text-2); font-size:14px; font-weight:800; }
.pr-color-swatch-auto.sel{ box-shadow:inset 0 0 0 1.5px var(--hairline-2), 0 0 0 2.5px var(--surface-2), 0 0 0 4.5px var(--text-3); }
.pr-custom-color-row{ display:flex; align-items:center; gap:11px; margin-top:14px; padding-top:14px; border-top:.5px solid var(--hairline); }
.pr-custom-color-input{ -webkit-appearance:none; appearance:none; width:34px; height:34px; border-radius:50%; border:2px solid var(--hairline-2); padding:0; background:none;
    cursor:pointer; flex-shrink:0; transition:transform .25s var(--spring); }
.pr-custom-color-input:active{ transform:scale(.88); }
.pr-custom-color-input::-webkit-color-swatch-wrapper{ padding:0; border-radius:50%; overflow:hidden; }
.pr-custom-color-input::-webkit-color-swatch{ border:none; border-radius:50%; }
.pr-custom-color-label{ font-size:13.5px; font-weight:700; color:var(--text); letter-spacing:-.1px; }
.pr-custom-color-hex{ margin-left:auto; font-size:12px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; letter-spacing:.3px;
    background:var(--surface); padding:4px 9px; border-radius:8px; box-shadow:inset 0 0 0 .5px var(--hairline); }
.pr-custom-color-hex:empty{ display:none; }

/* ---------- footer fisso del foglio ---------- */
.pr-sheet-actions{ display:flex; gap:10px; flex-shrink:0; padding:12px 18px calc(14px + var(--safe-bottom));
    border-top:.5px solid var(--hairline); background:color-mix(in srgb, var(--surface) 86%, transparent);
    -webkit-backdrop-filter:blur(20px) saturate(180%); backdrop-filter:blur(20px) saturate(180%); }
.pr-btn{ flex:1; padding:14px; border-radius:15px; border:none; font-size:15.5px; font-weight:700; letter-spacing:-.2px;
    transition:transform .2s var(--spring), filter .2s, background-color .25s; }
.pr-btn:active{ transform:scale(.965); filter:brightness(.96); }
.pr-btn-cancel{ background:var(--surface-2); color:var(--text); box-shadow:inset 0 0 0 .5px var(--hairline); }
.pr-btn-save{ background:var(--blue); color:#fff; box-shadow:var(--btn-hl), 0 10px 24px -10px color-mix(in srgb, var(--blue) 90%, transparent); }
.pr-btn-danger{ background:var(--red); color:#fff; box-shadow:var(--btn-hl), 0 10px 24px -10px color-mix(in srgb, var(--red) 90%, transparent); }
.pr-btn-icon-danger{ flex-shrink:0; width:48px; border-radius:15px; border:none; background:color-mix(in srgb, var(--red) 12%, transparent); color:var(--red);
    display:flex; align-items:center; justify-content:center; transition:transform .2s var(--spring), filter .2s, background-color .25s; }
.pr-btn-icon-danger:active{ transform:scale(.94); filter:brightness(.96); }

.pr-confirm-overlay{ position:fixed; inset:0; z-index:9750; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.45);
    -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
    opacity:0; pointer-events:none; transition:opacity .26s var(--ease-out); padding:20px; }
.pr-confirm-overlay.show{ opacity:1; pointer-events:auto; }
.pr-confirm-box{ background:color-mix(in srgb, var(--surface) 88%, transparent);
    -webkit-backdrop-filter:blur(30px) saturate(180%); backdrop-filter:blur(30px) saturate(180%);
    border-radius:22px; padding:22px 20px 16px; max-width:320px; width:100%; text-align:center; box-shadow:var(--shadow-pop);
    transform:scale(.88); opacity:0; transition:transform .42s var(--pop), opacity .26s; }
.pr-confirm-overlay.show .pr-confirm-box{ transform:scale(1); opacity:1; }
.pr-confirm-title{ font-weight:800; font-size:17px; letter-spacing:-.3px; margin-bottom:7px; }
.pr-confirm-msg{ font-size:13.5px; color:var(--text-2); margin-bottom:18px; line-height:1.45; }
.pr-confirm-actions{ display:flex; gap:10px; }
.pr-confirm-actions.stack{ flex-direction:column; }
.pr-btn-neutral{ background:var(--blue-soft, rgba(0,122,255,.12)); color:var(--blue); box-shadow:inset 0 0 0 .5px color-mix(in srgb, var(--blue) 30%, transparent); }

.pr-toast{ position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom)); transform:translate(-50%,12px); background:rgba(28,28,30,.92); color:#fff;
    padding:11px 18px; border-radius:14px; font-size:13.5px; font-weight:600; z-index:9900; opacity:0; pointer-events:none; transition:opacity .25s, transform .25s var(--spring); }
.pr-toast.show{ opacity:1; transform:translate(-50%,0); }

/* Tasto in linea grigio per aggiungere sottoprogetti */
.pr-inline-add-btn {
    background: var(--surface-2);
    color: var(--text-2);
    border: none;
    border-radius: 6px;
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    cursor: pointer;
    flex-shrink: 0;
    transition: background-color .15s, color .15s;
}
.pr-inline-add-btn:active {
    transform: scale(0.92);
}

/* Riga di input rapido modificata con i tasti a destra */
.pr-inline-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px 8px 34px;
    background: var(--surface);
    border-top: .5px solid var(--hairline);
    animation: prIn .22s var(--spring) both;
}
.pr-inline-input-row input {
    flex: 1;
    padding: 9px 12px;
    border-radius: 11px;
    border: none;
    background: var(--surface-2);
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    box-shadow: inset 0 0 0 .5px var(--hairline);
    outline: none;
    -webkit-appearance: none;
}
/* Pulsante Salva minimale con il colore del progetto */
.pr-inline-save-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: color-mix(in srgb, var(--row-color) 12%, var(--surface));
    color: var(--row-color);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform .1s;
}
/* Pulsante Annulla minimale grigio chiaro */
.pr-inline-cancel-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--surface-2);
    color: var(--text-2);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform .1s;
}
.pr-inline-save-btn:active, .pr-inline-cancel-btn:active {
    transform: scale(0.9);
}


@media (prefers-reduced-motion: reduce){
    *{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; }
}
</style>
<script src="fc_parser.js?v=<?php echo time(); ?>"></script>
</head>
<body>

<div class="app-header">
    <div class="hdr-title">Progetti</div>
    <?php if (!empty($rootProjects)): ?>
    <div class="hdr-root-pills" id="hdrRootPills">
        <?php if (!empty($favProjects)): ?>
        <!-- Preferiti sempre in testa: la barra scorre in orizzontale e con
             molti progetti finiva fuori vista, proprio la voce che si cerca
             più spesso. -->
        <button type="button" class="hdr-root-pill hdr-pill-fav<?php echo $viewFavorites ? ' active' : ''; ?>"
                style="--pill-color:#E0B84A;" onclick="window.location.href='progetti.php?view=fav'">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
            Preferiti
        </button>
        <?php endif; ?>
        <?php foreach ($rootProjects as $rp):
            $rpColor = (!empty($rp['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rp['color'])) ? $rp['color'] : '#8E8E93';
        ?>
        <button type="button" class="hdr-root-pill<?php echo ((int)$rp['id'] === $selectedId) ? ' active' : (((int)$rp['id'] === $selectedRootId) ? ' active-faded' : ''); ?>" style="--pill-color:<?php echo $rpColor; ?>;"
                onclick="window.location.href='progetti.php?id=<?php echo (int)$rp['id']; ?>'">
            <span class="hdr-root-pill-dot"></span><?php echo htmlspecialchars($rp['name'], ENT_QUOTES); ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php elseif (!empty($favProjects)): ?>
    <div class="hdr-root-pills">
        <button type="button" class="hdr-root-pill hdr-pill-fav<?php echo $viewFavorites ? ' active' : ''; ?>"
                style="--pill-color:#E0B84A;" onclick="window.location.href='progetti.php?view=fav'">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
            Preferiti
        </button>
    </div>
    <?php endif; ?>
    <button type="button" class="hdr-drawer-btn" onclick="prOpenDrawer()">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
</div>

<?php include 'sidebar.html'; ?>

<div class="page">
<?php if ($viewFavorites): ?>
    <?php /* I preferiti: ognuno con il suo ramo, apribile come sempre. */ ?>
    <div class="pr-fav-head">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
        <span>Preferiti</span>
        <small><?php echo count($favTrees); ?></small>
    </div>
    <?php if (empty($favTrees)): ?>
        <div class="pr-empty">
            <div class="pr-empty-title">Nessun preferito</div>
            <div>Apri un progetto, tocca modifica e premi la stellina: comparirà qui.</div>
        </div>
    <?php else: ?>
        <div class="pr-list" id="prList">
            <?php foreach ($favTrees as $ft) { prog_render_row($ft, $childrenOf, $totalMemo, 0, $pomoInCorso); } ?>
        </div>
    <?php endif; ?>
<?php elseif (!$selectedProject): ?>
    <div class="pr-empty">
        <svg class="pr-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M8 3v4"/></svg>
        <div class="pr-empty-title">Nessun progetto ancora</div>
        <div>Crea il tuo primo progetto per iniziare a organizzarti.</div>
        <button type="button" class="pr-btn-create-first" onclick="prOpenEdit(0)">+ Crea progetto</button>
    </div>
<?php else:
    $accent = (!empty($selectedProject['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $selectedProject['color'])) ? $selectedProject['color'] : '#8E8E93';
    $heroNote = htmlspecialchars($selectedProject['note'] ?? '', ENT_QUOTES);
?>
    <?php if (!empty($ancestorChain)): ?>
    <div class="pr-crumbs">
        <?php foreach ($ancestorChain as $anc):
            $ancColor = (!empty($anc['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $anc['color'])) ? $anc['color'] : '#8E8E93';
        ?>
        <button type="button" class="pr-crumb" style="--crumb-color:<?php echo $ancColor; ?>;" onclick="window.location.href='progetti.php?id=<?php echo (int)$anc['id']; ?>'">
            <span class="pr-crumb-dot"></span><?php echo htmlspecialchars($anc['name'], ENT_QUOTES); ?>
        </button>
        <span class="pr-crumb-sep">›</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="pr-hero" style="--accent:<?php echo $accent; ?>;">
        <div class="pr-hero-top">
            <button type="button" class="pr-check pr-check-<?php echo $selectedProject['stato']; ?>" data-id="<?php echo (int)$selectedProject['id']; ?>" style="width:28px;height:28px;">
                <svg class="pr-ic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                <svg class="pr-ic-done" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                <svg class="pr-ic-planned" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3" stroke-linecap="round"/></svg>
            </button>
            <div class="pr-hero-title-wrap">
                <span class="pr-hero-title<?php echo $selectedProject['stato'] === 'completed' ? ' pr-name-done' : ''; ?>"><?php
                    /* La stella c'è sempre, così la sua assenza non si confonde
                       con "questo progetto non ne ha una": piena e colorata se è
                       fra i preferiti, vuota e tratteggiata se no. */
                    /* La stella si tocca: prima era solo un disegno e restava
                       lì a far finta di essere un comando. Il verso lo decide
                       il JavaScript, che riusa l'endpoint toggle_favorite già
                       esistente (quello della scheda di modifica). */
                    $stellaPiena = (int)($selectedProject['is_favorite'] ?? 0) === 1;
                    echo '<button type="button" class="pr-star-btn' . ($stellaPiena ? ' is-fav' : '') . '"'
                       . ' data-id="' . (int)$selectedProject['id'] . '"'
                       . ' onclick="prToggleHeroFav(event, this)"'
                       . ' aria-pressed="' . ($stellaPiena ? 'true' : 'false') . '"'
                       . ' title="' . ($stellaPiena ? 'Togli dai preferiti' : 'Metti tra i preferiti') . '"'
                       . ' aria-label="Preferito">' . prog_star_svg($stellaPiena) . '</button>';
                    echo htmlspecialchars($selectedProject['name'], ENT_QUOTES);
                ?></span>
            </div>
        </div>
        <?php $linkedCat = $catLinks[$selectedProject['id']] ?? null; if ($linkedCat): ?>
        <button type="button" class="pr-hero-cat-chip" style="--cat-color:<?php echo $linkedCat['color']; ?>;" onclick="window.location.href='visualizzazione_fc.php?category_id=<?php echo (int)$linkedCat['id']; ?>'">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            <?php echo htmlspecialchars($linkedCat['name'], ENT_QUOTES); ?>
        </button>
        <?php endif; ?>
        <div class="pr-hero-dates">
            <?php if (!empty($selectedProject['data_obiettivo'])): ?><span class="pr-hero-date">🎯 <?php echo date('d M', strtotime($selectedProject['data_obiettivo'])); ?></span><?php endif; ?>
            <?php if (!empty($selectedProject['data_scadenza'])): ?><span class="pr-hero-date">⏰ <?php echo date('d M', strtotime($selectedProject['data_scadenza'])); ?></span><?php endif; ?>
            <?php $totDur = prog_fmt_duration($totalMemo[$selectedProject['id']] ?? 0); if ($totDur): ?><span class="pr-hero-date">⏱ <?php echo $totDur; ?> totali</span><?php endif; ?>
        </div>
        <?php
        /* Sezione attività del progetto: compare se è stata attivata dal suo
           tastino. Sta sopra la nota, che scivola sotto quando ci sono
           entrambe. Le attività sono le stesse della pagina to-do. */
        $heroTodo = (int)($selectedProject['todo_enabled'] ?? 0) === 1;
        ?>
        <div class="pr-hero-todo<?php echo $heroTodo ? '' : ' pr-hidden'; ?>" id="prHeroTodo"></div>
        <?php if ($heroNote !== ''): ?><div class="pr-hero-note raw-text-content" data-raw="<?php echo $heroNote; ?>"></div><?php endif; ?>
        <div class="pr-hero-actions">
            <!-- Il sotto-progetto rapido stava attaccato al titolo, dove sembrava
                 parte del nome e schiacciava la riga. Qui è un tondo come gli
                 altri, primo della fila: è l'azione che si usa più spesso. -->
            <button type="button" class="pr-hero-btn pr-hero-btn-quick" onclick="prHeroQuickAdd(event, this)" aria-label="Aggiungi sotto-progetto rapido" title="Aggiungi sotto-progetto rapido">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5v10a2 2 0 0 0 2 2h8"/><path d="m16 14 3 3-3 3"/></svg>
            </button>
            <button type="button" class="pr-hero-btn pr-hero-btn-pomo"<?php echo $pomoInCorso ? ' disabled aria-label="Hai già un pomodoro in corso" title="Hai già un pomodoro in corso"' : (' onclick="window.location.href=\'pomodoro.php?project_id=' . (int)$selectedProject['id'] . '\'" aria-label="Avvia pomodoro" title="Avvia pomodoro"'); ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/></svg>
            </button>
            <button type="button" class="pr-hero-btn pr-hero-btn-todo<?php echo $heroTodo ? ' is-on' : ''; ?>" id="prTodoBtn"
                    data-project="<?php echo (int)$selectedProject['id']; ?>"
                    aria-label="Attività" title="Attività">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l2.2 2.2L10 5.5"/><path d="M4 17l2.2 2.2L10 15.5"/><path d="M13 7.5h7M13 17.5h7"/></svg>
            </button>
            <button type="button" class="pr-hero-btn pr-hero-btn-stats" onclick="prOpenStats(<?php echo (int)$selectedProject['id']; ?>, '<?php echo htmlspecialchars(addslashes($selectedProject['name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars($accent, ENT_QUOTES); ?>')" aria-label="Pomodori" title="Pomodori">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 20V10M12 20V4M18 20v-7"/></svg>
            </button>
            <button type="button" class="pr-hero-btn pr-hero-btn-add" onclick="prOpenEdit(0, <?php echo (int)$selectedProject['id']; ?>)" aria-label="Aggiungi sotto-progetto" title="Aggiungi sotto-progetto">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <button type="button" class="pr-hero-btn pr-hero-btn-stats" onclick="prOpenEdit(<?php echo (int)$selectedProject['id']; ?>)" aria-label="Modifica progetto" title="Modifica progetto">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
            <?php if (count($selectedTree['children'] ?? []) >= 2): ?>
            <button type="button" class="pr-hero-btn pr-hero-btn-stats" id="prSelectSubBtn" onclick="prToggleSubSelecting()" aria-label="Seleziona sotto-progetti" title="Seleziona sotto-progetti">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11M9 12h11M9 18h11"/><path d="m3 5.5 1.4 1.4L7 4.3"/><path d="m3 11.5 1.4 1.4 2.6-2.6"/><path d="m3 17.5 1.4 1.4 2.6-2.6"/></svg>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($selectedTree['children'])): ?>
    <div class="pr-view-bar">
        <div class="pr-view-switch" id="prViewSwitch">
            <button type="button" data-view="list" onclick="prSetViewMode('list')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>Elenco
            </button>
            <button type="button" data-view="grid" onclick="prSetViewMode('grid')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>Griglia
            </button>
        </div>
        <button type="button" class="pr-view-dates-btn" onclick="prOpenDatesView()" aria-label="Scadenze e obiettivi" title="Scadenze e obiettivi">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18"/></svg>
        </button>
    </div>
    <div id="prHeroQuickAddHost"></div>
    <div class="pr-sub-bulkbar" id="prSubBulkbar">
        <span id="prSubBulkCount">0 selezionati</span>
        <button type="button" class="pr-sub-bulk-btn" id="prSubSelectAllBtn" onclick="prSubSelectAll()">Seleziona tutti</button>
        <button type="button" class="pr-sub-bulk-btn accent" onclick="prSubBulkColorOpen()">Colore</button>
        <button type="button" class="pr-sub-bulk-btn accent" onclick="prSubBulkReparentOpen()">Progetto padre</button>
        <button type="button" class="pr-sub-bulk-btn danger" onclick="prSubBulkDeleteOpen()">Elimina</button>
    </div>
    <div class="pr-list" id="prList" data-parent="<?php echo (int)$selectedProject['id']; ?>">
        <?php foreach ($selectedTree['children'] as $child) { prog_render_row($child, $childrenOf, $totalMemo, 0, $pomoInCorso); } ?>
    </div>
    <div class="pr-grid" id="prGrid" style="display:none;">
        <?php foreach ($selectedTree['children'] as $child):
            $gcolor = (!empty($child['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $child['color'])) ? $child['color'] : '#8E8E93';
            $gdur = prog_fmt_duration($totalMemo[$child['id']] ?? 0);
            $gKidsCount = count($child['children'] ?? []);
            $gCat = $catLinks[$child['id']] ?? null;
            $gNote = htmlspecialchars($child['note'] ?? '', ENT_QUOTES);
            $gDateTags = prog_date_tags_info($child);
        ?>
        <button type="button" class="pr-grid-card" style="--row-color:<?php echo $gcolor; ?>;" onclick="window.location.href='progetti.php?id=<?php echo (int)$child['id']; ?>'">
            <span class="pr-grid-dot">
                <?php if ($child['stato'] === 'completed'): ?>
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                <?php elseif ($child['stato'] === 'planned'): ?>
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M8 3v4"/></svg>
                <?php endif; ?>
            </span>
            <div class="pr-grid-name<?php echo $child['stato'] === 'completed' ? ' pr-name-done' : ''; ?>"><?php echo htmlspecialchars($child['name'], ENT_QUOTES); ?></div>
            <?php if ($gCat || $gNote !== '' || $gDateTags): ?>
            <div class="pr-tags-row">
                <?php if ($gCat): ?>
                <span class="pr-cat-tag" style="--cat-color:<?php echo htmlspecialchars($gCat['color'], ENT_QUOTES); ?>;"
                      onclick="event.stopPropagation(); window.location.href='visualizzazione_fc.php?category_id=<?php echo (int)$gCat['id']; ?>'">
                    <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                    <?php echo htmlspecialchars($gCat['name'], ENT_QUOTES); ?>
                </span>
                <?php endif; ?>
                <?php if ($gNote !== ''): ?>
                <span class="pr-note-tag" data-note-raw="<?php echo $gNote; ?>" data-note-id="<?php echo (int)$child['id']; ?>"
                      onclick="event.stopPropagation(); prOpenNotePeek(<?php echo (int)$child['id']; ?>, this)" aria-label="Mostra nota">
                    <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/></svg>
                </span>
                <?php endif; ?>
                <?php foreach ($gDateTags as $gdt): ?>
                <span class="pr-date-tag pr-date-tag-<?php echo $gdt['status']; ?>">
                    <?php echo $gdt['icon']; ?> <?php echo htmlspecialchars($gdt['label'], ENT_QUOTES); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="pr-grid-meta">
                <span><?php echo $gdur ?: '—'; ?></span>
                <?php if ($gKidsCount > 0): ?><span><?php echo $gKidsCount; ?> sotto</span><?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div id="prHeroQuickAddHost"></div>
    <div class="pr-empty" style="padding:36px 16px;">
        <div>Nessun sotto-progetto. Aggiungine uno con "+ Sotto-progetto" qui sopra.</div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- ================= DRAWER PROGETTI RADICE ================= -->
<div class="pd-scrim" id="pdScrim" onclick="prCloseDrawer()"></div>
<div class="pd-panel" id="pdPanel">
    <div class="pd-head">I tuoi progetti <button type="button" class="pd-close" onclick="prCloseDrawer()">✕</button></div>
    <div class="pd-list">
        <?php if (!empty($favProjects)): ?>
        <?php /* i preferiti in cima all'elenco, prima dei progetti principali */ ?>
        <div class="pd-item pd-item-fav<?php echo $viewFavorites ? ' active' : ''; ?>" style="--row-color:#E0B84A;"
             onclick="window.location.href='progetti.php?view=fav'">
            <svg class="pd-fav-star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
            <span class="pd-item-name">Preferiti</span>
            <span class="pd-item-count"><?php echo count($favProjects); ?></span>
        </div>
        <?php endif; ?>
        <?php foreach ($rootProjects as $rp):
            $rcolor = (!empty($rp['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rp['color'])) ? $rp['color'] : '#8E8E93';
            $rdur = prog_fmt_duration($totalMemo[$rp['id']] ?? 0);
        ?>
        <div class="pd-item<?php echo ((int)$rp['id'] === $selectedId) ? ' active' : ''; ?>" style="--row-color:<?php echo $rcolor; ?>;" onclick="window.location.href='progetti.php?id=<?php echo (int)$rp['id']; ?>'">
            <span class="pd-dot"></span>
            <span class="pd-item-name"><?php echo htmlspecialchars($rp['name'], ENT_QUOTES); ?></span>
            <?php if ($rdur): ?><span class="pd-item-count"><?php echo $rdur; ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="pd-add" onclick="prCloseDrawer(); prOpenEdit(0);">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Nuovo progetto
        </div>
    </div>
</div>

<!-- ================= EDIT SHEET ================= -->
<div class="pr-sheet-overlay" id="prSheetOverlay" onclick="if(event.target===this) prCloseEdit();">
    <div class="pr-sheet">
        <div class="pr-sheet-grabber"></div>
        <div class="pr-sheet-head">
            <div class="pr-sheet-title" id="prSheetTitle">Nuovo progetto</div>
            <button type="button" class="pr-sheet-close" onclick="prCloseEdit()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="pr-sheet-body">
            <div class="pr-field">
                <label>Nome</label>
                <input type="text" id="prFieldName" placeholder="Es. Tesi di laurea">
            </div>
            <div class="pr-field">
                <label>Progetto padre</label>
                <?php
                    $ps_id = 'parent';
                    $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
                    $ps_placeholder = 'Nessuno (progetto radice)';
                    $ps_title = 'Scegli il progetto padre';
                    $ps_show_all_option = true; $ps_all_option_label = 'Nessuno (progetto radice)';
                    $ps_allow_create = false; $ps_hidden_name = 'parent_id';
                    include 'project_selector.php';
                ?>
            </div>
            <div class="pr-field">
                <label>Materia collegata</label>
                <?php
                    $cs_id = 'category';
                    $cs_selected_id = 0; $cs_selected_name = ''; $cs_selected_color = null;
                    $cs_placeholder = 'Nessuna materia collegata';
                    $cs_title = 'Collega a una materia';
                    $cs_show_all_option = true; $cs_all_option_label = 'Nessuna materia collegata';
                    $cs_allow_create = false; $cs_hidden_name = 'fc_category_id';
                    include 'category_selector.php';
                ?>
            </div>
            <div class="pr-field-row">
                <div class="pr-field">
                    <label>Preferito</label>
                    <button type="button" class="pr-fav-btn" id="prFieldFav" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
                        <span>Metti tra i preferiti</span>
                    </button>
                </div>
                <div class="pr-field"><label>Data obiettivo</label><input type="date" id="prFieldObiettivo"></div>
                <div class="pr-field"><label>Scadenza</label><input type="date" id="prFieldScadenza"></div>
            </div>
            <div class="pr-field" style="margin-bottom:4px;">
                <label>Colore <span id="prColorModeLabel" style="text-transform:none; font-weight:500; letter-spacing:0;">(automatico dal padre)</span></label>
                <div class="pr-color-card">
                    <div class="pr-color-preview-row">
                        <span class="pr-color-preview-icon" id="prColorPreviewIcon"></span>
                        <span class="pr-color-preview-name" id="prColorPreviewName">Colore automatico</span>
                    </div>
                    <div class="pr-color-grid" id="prColorGrid">
                        <button type="button" class="pr-color-swatch pr-color-swatch-auto" id="prColorAutoBtn" onclick="prSetColorAuto()" aria-label="Automatico dal padre">A</button>
                        <?php foreach ($prColorPalette as $hex): ?>
                        <button type="button" class="pr-color-swatch" style="background:<?php echo $hex; ?>; --sw:<?php echo $hex; ?>;" data-hex="<?php echo $hex; ?>" onclick="prSetColorManual('<?php echo $hex; ?>')">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="pr-custom-color-row">
                        <input type="color" class="pr-custom-color-input" id="prCustomColorInput" value="#8E8E93" oninput="prSetColorManual(this.value)">
                        <span class="pr-custom-color-label">Colore personalizzato</span>
                        <span class="pr-custom-color-hex" id="prCustomColorHex"></span>
                    </div>
                </div>
            </div>
            <div class="pr-field" style="margin-bottom:0;">
                <label>Note</label>
                <?php
                    $ft_field = 'prFieldNote';
                    $ft_raw = '';
                    $ft_placeholder = "Scrivi qui le note del progetto…";
                    include 'format_toolbar.php';
                ?>
            </div>
        </div>
        <div class="pr-sheet-actions">
            <button type="button" class="pr-btn-icon-danger" id="prDeleteBtn" onclick="prDeleteCurrentProject()" aria-label="Elimina progetto" style="display:none;">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
            </button>
            <button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseEdit()">Annulla</button>
            <button type="button" class="pr-btn pr-btn-save" onclick="prFlushAndRefresh()">Fatto</button>
        </div>
    </div>
</div>

<!-- ================= SCADENZE E OBIETTIVI ================= -->
<div class="pr-sheet-overlay" id="prDatesOverlay" onclick="if(event.target===this) prCloseDatesView();">
    <div class="pr-sheet">
        <div class="pr-sheet-grabber"></div>
        <div class="pr-sheet-head">
            <div class="pr-sheet-title">Scadenze e obiettivi</div>
            <button type="button" class="pr-sheet-close" onclick="prCloseDatesView()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="pr-sheet-body">
            <div class="pr-dates-row">
                <div class="pr-view-switch" id="prDatesFieldSwitch">
                    <button type="button" data-field="scadenza" onclick="prDatesSetField('scadenza')">⏰ Scadenza</button>
                    <button type="button" data-field="obiettivo" onclick="prDatesSetField('obiettivo')">🎯 Obiettivo</button>
                </div>
                <button type="button" class="pr-dates-sort-btn" id="prDatesSortBtn" onclick="prDatesToggleSort()">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M6 12h12M10 17h4"/></svg>
                    <span id="prDatesSortLabel">Più vicine</span>
                </button>
            </div>
            <div class="pr-dates-filters" id="prDatesFilters">
                <button type="button" class="pr-dates-chip" data-filter="all" onclick="prDatesSetFilter('all')">Tutte</button>
                <button type="button" class="pr-dates-chip" data-filter="past" onclick="prDatesSetFilter('past')">Passate</button>
                <button type="button" class="pr-dates-chip" data-filter="today" onclick="prDatesSetFilter('today')">Oggi</button>
                <button type="button" class="pr-dates-chip" data-filter="future" onclick="prDatesSetFilter('future')">Future</button>
            </div>
            <div class="pr-dates-list" id="prDatesList"></div>
        </div>
    </div>
</div>

<!-- ================= CONFERMA ELIMINAZIONE ================= -->
<div class="pr-confirm-overlay" id="prConfirmOverlay">
    <div class="pr-confirm-box">
        <div class="pr-confirm-title" id="prConfirmTitle">Eliminare questo progetto?</div>
        <div class="pr-confirm-msg" id="prConfirmMsg">Questa azione non può essere annullata.</div>
        <div class="pr-confirm-actions stack" id="prConfirmActions"></div>
    </div>
</div>

<!-- ================= CAMBIA COLORE (selezione multipla) ================= -->
<div class="pr-confirm-overlay" id="prBulkColorOverlay">
    <div class="pr-confirm-box">
        <div class="pr-confirm-title">Cambia colore</div>
        <div class="pr-confirm-msg">Si applica a tutti i sotto-progetti selezionati.</div>
        <div class="pr-color-grid" id="prBulkColorGrid">
            <button type="button" class="pr-color-swatch pr-color-swatch-auto" onclick="prSubBulkColorPick('auto')" aria-label="Automatico dal padre" title="Automatico dal padre">A</button>
            <?php foreach ($prColorPalette as $hex): ?>
            <button type="button" class="pr-color-swatch" style="background:<?php echo $hex; ?>; --sw:<?php echo $hex; ?>;" onclick="prSubBulkColorPick('<?php echo $hex; ?>')">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="pr-custom-color-row">
            <input type="color" class="pr-custom-color-input" id="prBulkCustomColorInput" value="#8E8E93" oninput="prSubBulkColorPick(this.value)">
            <span class="pr-custom-color-label">Colore personalizzato</span>
        </div>
        <div class="pr-confirm-actions" style="margin-top:16px;">
            <button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseBulkColor()">Annulla</button>
        </div>
    </div>
</div>

<div class="pr-toast" id="prToast"></div>

<!-- ================= POST-IT NOTA ================= -->
<div class="pr-note-peek-overlay" id="prNotePeekOverlay" onclick="if(event.target===this) prCloseNotePeek();">
    <div class="pr-note-peek">
        <div class="pr-note-peek-head">
            <span class="pr-note-peek-title">Nota</span>
            <div class="pr-note-peek-actions">
                <button type="button" class="pr-note-peek-btn" id="prNotePeekEdit" aria-label="Modifica progetto">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button type="button" class="pr-note-peek-btn" onclick="prCloseNotePeek()" aria-label="Chiudi">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>
        <div class="pr-note-peek-body raw-text-content" id="prNotePeekBody"></div>
    </div>
</div>

<?php
    /* Selettore dedicato a "cambia progetto padre" per la selezione multipla dei
       sotto-progetti: nessun tasto proprio (lo apriamo noi da JS), e i vincoli
       (selezionati + loro discendenti non scelgibili) li applichiamo via JS,
       esattamente come fa pomodoro_import.php col proprio selettore. */
    $ps_id = 'bulkreparent'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
    $ps_placeholder = 'Seleziona progetto'; $ps_title = 'Sposta sotto...';
    $ps_show_all_option = true; $ps_all_option_label = 'Nessuno (progetto radice)';
    $ps_allow_create = false; $ps_render_trigger = false; $ps_hidden_name = 'bulk_reparent_project_id';
    $ps_exclude_id = 0;
    include 'project_selector.php';
    unset($ps_id, $ps_selected_id, $ps_selected_name, $ps_selected_color, $ps_placeholder, $ps_title,
          $ps_show_all_option, $ps_all_option_label, $ps_allow_create, $ps_render_trigger, $ps_hidden_name, $ps_exclude_id);
?>

<?php include 'pomodoro_storico.php'; ?> 

<!-- ================= FORM NASCOSTI ================= -->
<form id="formSave" method="POST" style="display:none;">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="project_id" id="fProgettoId">
    <input type="hidden" name="progetto_name" id="fProgettoName">
    <input type="hidden" name="progetto_note" id="fProgettoNote">
    <input type="hidden" name="parent_id" id="fParentId">
    <input type="hidden" name="fc_category_id" id="fFcCategoryId">
    <input type="hidden" name="color_mode" id="fColorMode" value="auto">
    <input type="hidden" name="progetto_color" id="fProgettoColor">
    <input type="hidden" name="data_obiettivo" id="fDataObiettivo">
    <input type="hidden" name="data_scadenza" id="fDataScadenza">
    <input type="hidden" name="redirect_id" id="fRedirectId">
</form>
<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="project_id" id="fDelId">
    <input type="hidden" name="delete_sessions" id="fDelSessions" value="0">
    <input type="hidden" name="redirect_id" id="fDelRedirect">
</form>
<form id="formReorder" method="POST" style="display:none;">
    <input type="hidden" name="action" value="reorder">
    <input type="hidden" name="project_ids" id="fReorderIds">
</form>
<form id="formBulkDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="project_ids" id="fBulkDelIds">
    <input type="hidden" name="delete_sessions" id="fBulkDelSessions" value="0">
    <input type="hidden" name="redirect_id" id="fBulkDelRedirect">
</form>
<form id="formBulkColor" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_set_color">
    <input type="hidden" name="project_ids" id="fBulkColorIds">
    <input type="hidden" name="color" id="fBulkColorHex">
    <input type="hidden" name="redirect_id" id="fBulkColorRedirect">
</form>
<form id="formBulkReparent" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_reparent">
    <input type="hidden" name="project_ids" id="fBulkReparentIds">
    <input type="hidden" name="new_parent_id" id="fBulkReparentPid">
    <input type="hidden" name="redirect_id" id="fBulkReparentRedirect">
</form>

<script>
    const PROJ_LOOKUP = <?php echo json_encode($jsProjLookup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    // padre di ogni materia (0 = radice) e nome, per risalire/scendere l'albero lato client
    const CAT_PARENT = <?php echo json_encode($catParentOf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const CAT_NAME = <?php echo json_encode($catNameById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    // ogni collegamento progetto<->materia esistente (di TUTTI i progetti)
    const CAT_LINKS = <?php echo json_encode($jsCatLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
(function () {
'use strict';

/* richiesta 18: ricorda l'ultimo progetto aperto e, se si arriva su progetti.php
   senza un id esplicito, salta subito lì (prima di disegnare troppo) */
(function(){
    try {
        var params = new URLSearchParams(window.location.search);
        var cur = <?php echo (int)($selectedProject['id'] ?? 0); ?>;
        /* I preferiti non hanno un id nell'indirizzo: senza questa guardia
           "?view=fav" veniva scambiato per "nessun progetto scelto" e la
           pagina rimbalzava subito sull'ultimo progetto aperto. Era il motivo
           per cui i preferiti non si aprivano mai. */
        var vista = params.get('view');
        if (!params.has('id') && !vista) {
            var last = localStorage.getItem('pr_last_project');
            if (last && parseInt(last, 10) > 0 && parseInt(last, 10) !== cur) {
                window.location.replace('progetti.php?id=' + parseInt(last, 10));
                return;
            }
        }
        /* stando nei preferiti non si sovrascrive l'ultimo progetto aperto:
           uscendo di lì si torna dov'eri davvero */
        if (cur > 0 && !vista) localStorage.setItem('pr_last_project', String(cur));
    } catch (e) {}
})();

/* richiesta 18: porta la pillola del progetto attivo nella vista */
(function(){
    var active = document.querySelector('#hdrRootPills .hdr-root-pill.active, #hdrRootPills .hdr-root-pill.active-faded');
    if (active && active.scrollIntoView) { try { active.scrollIntoView({ inline:'center', block:'nearest' }); } catch(e){} }
})();

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }
function toast(msg){
    var t = document.getElementById('prToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}

/* ---------------- drawer ---------------- */
window.prOpenDrawer = function(){ document.getElementById('pdScrim').classList.add('show'); document.getElementById('pdPanel').classList.add('show'); };
window.prCloseDrawer = function(){ document.getElementById('pdScrim').classList.remove('show'); document.getElementById('pdPanel').classList.remove('show'); };

/* ---------------- checkbox stato (AJAX, niente reload) ---------------- */
// cache degli stati dei sotto-progetti PRIMA del completamento a cascata:
// permette di ripristinarli se l'utente cambia subito idea (chiave = id progetto)
var prCascadeSnapshots = {};
document.addEventListener('click', function(e){
    var chk = e.target.closest('.pr-check');
    if (!chk) return;
    var id = chk.getAttribute('data-id');
    if (!id) return;
    buzz(12);
    chk.classList.remove('pr-just-tapped'); void chk.offsetWidth; chk.classList.add('pr-just-tapped');
    var states = ['pr-check-in_progress','pr-check-completed','pr-check-planned'];
    var cur = states.findIndex(function(s){ return chk.classList.contains(s); });
    var wasCompleted = (states[cur] === 'pr-check-completed');
    var next = states[((cur < 0 ? 0 : cur) + 1) % 3];
    states.forEach(function(s){ chk.classList.remove(s); });
    chk.classList.add(next);
    // se è la checkbox dell'hero, aggiorna anche lo strikethrough del titolo
    var heroTitle = document.querySelector('.pr-hero-title');
    if (chk.closest('.pr-hero-top') && heroTitle) heroTitle.classList.toggle('pr-name-done', next === 'pr-check-completed');
    var rowName = chk.closest('.pr-row-content') ? chk.closest('.pr-row-content').querySelector('.pr-name') : null;
    if (rowName) rowName.classList.toggle('pr-name-done', next === 'pr-check-completed');

    var body = 'project_id=' + encodeURIComponent(id);
    // se stiamo uscendo da "completato" e abbiamo uno snapshot in cache, mandalo
    // al server per ripristinare i sotto-progetti com'erano prima del completamento
    if (wasCompleted && prCascadeSnapshots[id]) {
        body += '&restore=' + encodeURIComponent(JSON.stringify(prCascadeSnapshots[id]));
    }

    fetch('progetti.php?ajax=toggle_stato', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body
    }).then(function(r){ return r.json(); }).then(function(data){
        if (!data.success) { toast('Errore, riprova'); return; }
        // completamento a cascata: aggiorna le checkbox dei sotto-progetti e
        // memorizza il loro stato precedente per un eventuale ripristino
        if (data.cascaded && data.cascaded.length) {
            if (data.snapshot) prCascadeSnapshots[id] = data.snapshot;
            data.cascaded.forEach(function(cid){
                prApplyStateToDom(cid, 'pr-check-completed');
            });
        }
        // ripristino: il server ha rimesso i sotto-progetti com'erano
        if (data.restored && Object.keys(data.restored).length) {
            Object.keys(data.restored).forEach(function(cid){
                prApplyStateToDom(cid, 'pr-check-' + data.restored[cid]);
            });
            delete prCascadeSnapshots[id]; // snapshot consumato
        }
    }).catch(function(){ toast('Errore di rete'); });
});
// applica una classe di stato a tutte le checkbox di un progetto + strikethrough
function prApplyStateToDom(cid, stateClass){
    document.querySelectorAll('.pr-check[data-id="' + cid + '"]').forEach(function(c){
        ['pr-check-in_progress','pr-check-completed','pr-check-planned'].forEach(function(s){ c.classList.remove(s); });
        c.classList.add(stateClass);
        var rn = c.closest('.pr-row-content') ? c.closest('.pr-row-content').querySelector('.pr-name') : null;
        if (rn) rn.classList.toggle('pr-name-done', stateClass === 'pr-check-completed');
    });
}

/* ---------------- espandi/collassa figli ---------------- */
window.prToggleChildren = function(id, btn){
    var wrap = btn.closest('.pr-row-wrap');
    var kids = wrap ? wrap.querySelector(':scope > .pr-children') : null;
    if (kids) { kids.classList.toggle('collapsed'); btn.classList.toggle('open'); }
};
window.prTapRow = function(id){
    if (prSubSelecting) return; // in modalita' selezione ci pensa il listener delegato qui sotto
    window.location.href = 'progetti.php?id=' + id;
};

/* ---------------- post-it nota (richiesta 13) ---------------- */
window.prOpenNotePeek = function(id, el){
    var raw = el ? (el.getAttribute('data-note-raw') || '') : '';
    if (!raw && PROJ_LOOKUP[id]) raw = PROJ_LOOKUP[id].note || '';
    var body = document.getElementById('prNotePeekBody');
    body.setAttribute('data-raw', raw);
    // prova i vari renderer di fc_parser.js; se nessuno c'è, testo semplice
    var rendered = false;
    try {
        if (window.fcRenderInto) { window.fcRenderInto(body, raw); rendered = true; }
        else if (window.fcRenderElement) { window.fcRenderElement(body); rendered = true; }
        else if (window.fcHydrate) { window.fcHydrate(body); rendered = true; }
        else if (window.fcRenderRaw) { body.innerHTML = window.fcRenderRaw(raw); rendered = true; }
    } catch (e) { rendered = false; }
    if (!rendered) body.textContent = raw;
    document.getElementById('prNotePeekEdit').onclick = function(){
        prCloseNotePeek();
        window.prOpenEdit(id);
    };
    document.getElementById('prNotePeekOverlay').classList.add('show');
};
window.prCloseNotePeek = function(){ document.getElementById('prNotePeekOverlay').classList.remove('show'); };

/* ---------------- stella dei preferiti nell'intestazione ---------------- */
/* Cambia subito e salva dopo: aspettare la risposta per far vedere che la
   stella si è accesa farebbe sembrare il tocco andato a vuoto. Se il salvataggio
   non riesce, la stella torna com'era e lo dice. */
window.prToggleHeroFav = function(e, btn){
    e.stopPropagation();
    var D = 'M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z';
    function disegna(on){
        btn.classList.toggle('is-fav', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.setAttribute('title', on ? 'Togli dai preferiti' : 'Metti tra i preferiti');
        btn.innerHTML = on
            ? '<svg class="pr-star pr-star-hero" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="' + D + '"/></svg>'
            : '<svg class="pr-star pr-star-hero pr-star-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-dasharray="3 2.2" aria-hidden="true"><path d="' + D + '"/></svg>';
    }
    var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
    if (!id) return;
    var next = !btn.classList.contains('is-fav');
    disegna(next);
    if (typeof buzz === 'function') buzz(8);
    fetch('progetti.php?ajax=toggle_favorite', {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ project_id: id, value: next ? 1 : 0 }).toString()
    }).then(function(r){ return r.json(); }).then(function(r){
        if (!r || !r.ok) { disegna(!next); toast('Non salvato'); return; }
        /* la scheda di modifica, se è aperta o si aprirà, deve concordare */
        if (PROJ_LOOKUP[id]) PROJ_LOOKUP[id].is_favorite = next ? 1 : 0;
    }).catch(function(){ disegna(!next); toast('Errore di rete'); });
};

/* ---------------- inserimento rapido dall'hero (richiesta 15) ---------------- */
window.prHeroQuickAdd = function(e, btn){
    e.stopPropagation();
    var host = document.getElementById('prHeroQuickAddHost');
    if (!host) return;
    if (host.querySelector('.pr-inline-input-row')) { host.innerHTML = ''; return; }

    var inputRow = document.createElement('div');
    inputRow.className = 'pr-inline-input-row';
    inputRow.style.borderRadius = '14px';
    inputRow.style.background = 'var(--surface)';
    inputRow.style.boxShadow = 'var(--shadow-card)';
    inputRow.style.marginBottom = '12px';
    inputRow.style.paddingLeft = '14px';

    var input = document.createElement('input');
    input.type = 'text'; input.placeholder = 'Nome nuovo sotto-progetto...'; input.autocomplete = 'off';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button'; cancelBtn.className = 'pr-inline-cancel-btn'; cancelBtn.setAttribute('aria-label','Annulla');
    cancelBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button'; saveBtn.className = 'pr-inline-save-btn'; saveBtn.setAttribute('aria-label','Salva');
    saveBtn.style.background = 'color-mix(in srgb, var(--accent, var(--blue)) 14%, var(--surface))';
    saveBtn.style.color = 'var(--accent, var(--blue))';
    saveBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

    inputRow.appendChild(input); inputRow.appendChild(cancelBtn); inputRow.appendChild(saveBtn);
    host.appendChild(inputRow);
    input.focus();

    function submit(){
        var val = input.value.trim();
        if (!val) return;
        input.disabled = saveBtn.disabled = cancelBtn.disabled = true; saveBtn.style.opacity = '.5';
        document.getElementById('fProgettoId').value = 0;
        document.getElementById('fProgettoName').value = val;
        document.getElementById('fProgettoNote').value = '';
        document.getElementById('fParentId').value = CURRENT_PROJECT_ID;
        document.getElementById('fFcCategoryId').value = 0;
        document.getElementById('fDataObiettivo').value = '';
        document.getElementById('fDataScadenza').value = '';
        document.getElementById('fColorMode').value = 'auto';
        document.getElementById('fRedirectId').value = CURRENT_PROJECT_ID;
        var form = document.getElementById('formSave');
        fetch(window.location.href, { method:'POST', body:new FormData(form) })
            .then(function(r){ if (r.ok) window.location.reload(); else { toast('Errore'); reset(); } })
            .catch(function(){ toast('Errore di rete'); reset(); });
    }
    function reset(){ input.disabled = saveBtn.disabled = cancelBtn.disabled = false; saveBtn.style.opacity = '1'; input.focus(); }
    saveBtn.addEventListener('click', submit);
    cancelBtn.addEventListener('click', function(){ host.innerHTML = ''; });
    input.addEventListener('keydown', function(evt){ if (evt.key === 'Enter') { evt.preventDefault(); submit(); } else if (evt.key === 'Escape') host.innerHTML = ''; });
};

/* ---------------- selezione multipla dei sotto-progetti ---------------- */
var prSubSelecting = false;
var prSubSelectedIds = {};
var CURRENT_PROJECT_ID = <?php echo (int)($selectedProject['id'] ?? 0); ?>;

window.prToggleSubSelecting = function(){
    prSubSelecting = !prSubSelecting;
    prSubSelectedIds = {};
    var list = document.getElementById('prList');
    if (list) list.classList.toggle('pr-sub-selecting', prSubSelecting);
    var btn = document.getElementById('prSelectSubBtn');
    if (btn) btn.classList.toggle('active', prSubSelecting);
    var bar = document.getElementById('prSubBulkbar');
    if (bar) bar.classList.toggle('show', prSubSelecting);
    if (list) list.querySelectorAll('.pr-row-wrap.pr-sub-selected, .pr-row-wrap.pr-sub-locked').forEach(function(w){ w.classList.remove('pr-sub-selected', 'pr-sub-locked'); });
    if (prSubSelecting && window.prSetViewMode) window.prSetViewMode('list'); // le checkbox esistono solo nell'elenco
    prUpdateSubBulkCount();
};
function prUpdateSubBulkCount(){
    var n = Object.keys(prSubSelectedIds).length;
    var el = document.getElementById('prSubBulkCount');
    if (el) el.textContent = n + ' selezionat' + (n === 1 ? 'o' : 'i');
    var allBtn = document.getElementById('prSubSelectAllBtn');
    if (allBtn) allBtn.textContent = n > 0 ? 'Deseleziona tutti' : 'Seleziona tutti';
}
// tutte le righe-discendenti (nel DOM) di una data riga di #prList
function prDomDescendantWraps(wrap){
    return Array.prototype.slice.call(wrap.querySelectorAll('.pr-row-wrap'));
}
// ridisegna lock/selezione di tutte le righe in base agli id "primari" scelti
function prRepaintSubSelection(){
    var list = document.getElementById('prList');
    if (!list) return;
    // prima pulizia
    list.querySelectorAll('.pr-row-wrap').forEach(function(w){
        w.classList.remove('pr-sub-selected', 'pr-sub-locked');
    });
    // per ogni id primario: marca lui (selected) e i suoi discendenti (locked)
    Object.keys(prSubSelectedIds).forEach(function(id){
        var wrap = list.querySelector('.pr-row-wrap[data-id="' + id + '"]');
        if (!wrap) return;
        wrap.classList.add('pr-sub-selected');
        prDomDescendantWraps(wrap).forEach(function(d){ d.classList.add('pr-sub-locked'); });
    });
}
window.prToggleSubSelect = function(id){
    id = String(id);
    var list = document.getElementById('prList');
    var wrap = list ? list.querySelector('.pr-row-wrap[data-id="' + id + '"]') : null;
    if (!wrap) return;
    // se questa riga è bloccata perché è già selezionato un suo antenato, il tap non fa nulla (richiesta 2)
    if (wrap.classList.contains('pr-sub-locked')) { buzz(4); return; }
    if (prSubSelectedIds[id]) {
        delete prSubSelectedIds[id];
    } else {
        // selezionandola, togliamo eventuali suoi discendenti già selezionati come "primari"
        // (verrebbero comunque inclusi tramite l'antenato) per non contarli due volte
        prDomDescendantWraps(wrap).forEach(function(d){
            var did = d.getAttribute('data-id');
            if (prSubSelectedIds[did]) delete prSubSelectedIds[did];
        });
        prSubSelectedIds[id] = true;
    }
    prRepaintSubSelection();
    prUpdateSubBulkCount();
    buzz(8);
};
window.prSubSelectAll = function(){
    var list = document.getElementById('prList');
    if (!list) return;
    if (Object.keys(prSubSelectedIds).length > 0) {
        prSubSelectedIds = {};
    } else {
        // "tutti" = tutte le righe di PRIMO livello; i loro discendenti si bloccano da soli
        list.querySelectorAll(':scope > .pr-row-wrap').forEach(function(w){
            prSubSelectedIds[w.getAttribute('data-id')] = true;
        });
    }
    prRepaintSubSelection();
    prUpdateSubBulkCount();
    buzz(8);
};
// tap ovunque sulla riga (non solo sul nome) in modalità selezione: ora vale per
// TUTTI i livelli (richiesta 1), non solo per i figli diretti
document.addEventListener('click', function(e){
    if (!prSubSelecting) return;
    if (e.target.closest('.pr-arrow, .pr-grip, .pr-pomo-btn, .pr-check, .pr-inline-add-btn, .pr-cat-tag, .pr-note-tag')) return;
    var wrap = e.target.closest('.pr-row-wrap');
    var list = document.getElementById('prList');
    if (!wrap || !list || !list.contains(wrap)) return;
    window.prToggleSubSelect(wrap.getAttribute('data-id'));
});

// tutti i discendenti (id compresi) di un elenco di progetti radice: serve sia
// per "vai ai pomodori" sia per disabilitare il ramo giusto nel selettore padre
function prCollectDescendants(rootIds){
    var set = {};
    rootIds.forEach(function(id){ set[String(id)] = true; });
    var changed = true;
    while (changed) {
        changed = false;
        for (var k in PROJ_LOOKUP) {
            if (set[k]) continue;
            if (set[String(PROJ_LOOKUP[k].parent_id)]) { set[k] = true; changed = true; }
        }
    }
    return Object.keys(set);
}

/* -------- elimina (in blocco): stesso flusso in due passi del singolo -------- */
var prBulkDeleteIds = [];
window.prSubBulkDeleteOpen = function(){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) { toast('Seleziona almeno un sotto-progetto'); return; }
    prBulkDeleteIds = ids;
    var totalSessions = 0, anyChildren = false;
    ids.forEach(function(id){
        var info = PROJ_LOOKUP[id];
        if (info) totalSessions += (info.session_count || 0);
        if (prHasChildren(parseInt(id, 10))) anyChildren = true;
    });
    var n = ids.length;
    document.getElementById('prConfirmTitle').textContent = 'Eliminare ' + n + ' sotto-progett' + (n === 1 ? 'o' : 'i') + '?';

    if (totalSessions === 0) {
        var plainMsg = anyChildren ? 'Alcuni hanno a loro volta dei sotto-progetti: verranno eliminati anche loro. ' : '';
        plainMsg += 'Questa azione non può essere annullata.';
        document.getElementById('prConfirmMsg').textContent = plainMsg;
        document.getElementById('prConfirmActions').innerHTML =
            '<button type="button" class="pr-btn pr-btn-danger" onclick="prSubBulkDeleteDo(false)">Elimina</button>' +
            '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
        document.getElementById('prConfirmOverlay').classList.add('show');
        return;
    }

    var msg = anyChildren ? 'Alcuni hanno a loro volta dei sotto-progetti: verranno eliminati anche loro. ' : '';
    msg += 'Vuoi eliminare anche i pomodori collegati?';
    document.getElementById('prConfirmMsg').textContent = msg;
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-danger" onclick="prSubBulkDeleteDo(true)">Elimina anche i pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-neutral" onclick="prSubBulkKeepSessions()">Mantieni i pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
    document.getElementById('prConfirmOverlay').classList.add('show');
};
window.prSubBulkKeepSessions = function(){
    document.getElementById('prConfirmTitle').textContent = 'I pomodori resteranno senza progetto';
    document.getElementById('prConfirmMsg').textContent =
        'Se preferisci, vai prima ai pomodori dei sotto-progetti selezionati e riassegnali a un altro progetto. Altrimenti, continuando, resteranno tutti senza progetto.';
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-neutral" onclick="prSubBulkGoToPomodori()">Vai ai pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-danger" onclick="prSubBulkDeleteDo(false)">Elimina comunque</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
};
window.prSubBulkGoToPomodori = function(){
    var ids = prCollectDescendants(prBulkDeleteIds).map(function(x){ return parseInt(x, 10); });
    prCloseConfirm();
    if (!window.openPomodoroStorico) return;
    window.openPomodoroStorico({ scope: 'project', projectIds: ids, projectName: 'sotto-progetti selezionati' });
    if (window.phToggleSelectMode) window.phToggleSelectMode();
};
window.prSubBulkDeleteDo = function(deleteSessions){
    document.getElementById('fBulkDelIds').value = prBulkDeleteIds.join(',');
    document.getElementById('fBulkDelSessions').value = deleteSessions ? '1' : '0';
    document.getElementById('fBulkDelRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkDelete').submit();
};

/* -------- cambia colore (in blocco) -------- */
window.prSubBulkColorOpen = function(){
    if (!Object.keys(prSubSelectedIds).length) { toast('Seleziona almeno un sotto-progetto'); return; }
    document.getElementById('prBulkColorOverlay').classList.add('show');
};
window.prCloseBulkColor = function(){ document.getElementById('prBulkColorOverlay').classList.remove('show'); };
window.prSubBulkColorPick = function(value){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) return;
    document.getElementById('fBulkColorIds').value = ids.join(',');
    document.getElementById('fBulkColorHex').value = value;
    document.getElementById('fBulkColorRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkColor').submit();
};

/* -------- cambia progetto padre (in blocco) -------- */
var prBulkReparentIds = [];
var prBulkTreeTagged = false;
function prTagBulkTree(){
    if (prBulkTreeTagged) return;
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (!modal) return;
    modal.querySelectorAll('.ps-row-wrap').forEach(function(w){
        var nameEl = w.querySelector(':scope > .ps-row .ps-name');
        w.setAttribute('data-pr-id', nameEl ? (nameEl.getAttribute('data-ps-select') || '0') : '0');
    });
    prBulkTreeTagged = true;
}
function prBulkReparentRestrict(excludeIds){
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (!modal) return;
    prTagBulkTree();
    var excludeSet = {};
    excludeIds.forEach(function(id){ excludeSet[String(id)] = true; });
    modal.querySelectorAll('.ps-row-wrap').forEach(function(w){
        var nameEl = w.querySelector(':scope > .ps-row .ps-name');
        if (!nameEl) return;
        var id = w.getAttribute('data-pr-id');
        if (excludeSet[id]) {
            nameEl.removeAttribute('data-ps-select');
            nameEl.classList.add('ps-name-disabled');
        } else {
            nameEl.setAttribute('data-ps-select', id);
            nameEl.classList.remove('ps-name-disabled');
        }
    });
}
window.prSubBulkReparentOpen = function(){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) { toast('Seleziona almeno un sotto-progetto'); return; }
    prBulkReparentIds = ids;
    prBulkReparentRestrict(prCollectDescendants(ids));
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (modal && window.psOpen) window.psOpen(modal);
};
document.addEventListener('projectselected', function(e){
    if (!e.detail || e.detail.uid !== 'bulkreparent') return;
    document.getElementById('fBulkReparentIds').value = prBulkReparentIds.join(',');
    document.getElementById('fBulkReparentPid').value = e.detail.id;
    document.getElementById('fBulkReparentRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkReparent').submit();
});

/* ---------------- modalità di visualizzazione elenco/griglia (bug #3) ---------------- */
window.prSetViewMode = function(mode){
    var list = document.getElementById('prList'), grid = document.getElementById('prGrid');
    if (!list || !grid) return;
    list.style.display = mode === 'grid' ? 'none' : '';
    grid.style.display = mode === 'grid' ? '' : 'none';
    document.querySelectorAll('#prViewSwitch button').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-view') === mode); });
    try { localStorage.setItem('pr_view_mode', mode); } catch (e) {}
};
(function(){
    if (!document.getElementById('prViewSwitch')) return;
    var saved = 'list';
    try { saved = localStorage.getItem('pr_view_mode') || 'list'; } catch (e) {}
    window.prSetViewMode(saved);
})();

/* ---------------- edit sheet ---------------- */

/* mappa figlio -> [figli] ricavata invertendo CAT_PARENT, calcolata una sola volta */
var CAT_CHILDREN = {};
(function(){
    for (var idStr in CAT_PARENT) {
        if (!CAT_PARENT.hasOwnProperty(idStr)) continue;
        var p = CAT_PARENT[idStr];
        if (!CAT_CHILDREN[p]) CAT_CHILDREN[p] = [];
        CAT_CHILDREN[p].push(parseInt(idStr, 10));
    }
})();

/* Per il progetto in modifica (editingId), calcola l'insieme delle materie NON
   selezionabili: una materia è bloccata se lei stessa, un suo discendente o un
   suo antenato risultano già collegati a un progetto diverso da editingId.
   Ritorna una mappa id -> { reason: testo da mostrare } */
function prComputeBlockedCategories(editingId){
    var blocked = {};
    CAT_LINKS.forEach(function(link){
        if (link.project_id === editingId) return; // il proprio collegamento non blocca nulla
        var c = link.category_id;
        var cName = CAT_NAME[c] || '?';

        if (!blocked[c]) {
            blocked[c] = 'La materia "' + cName + '" è già collegata al progetto "' + link.project_name + '".';
        }

        // risale verso la radice: ogni padre viene bloccato perché un suo figlio (c) è collegato
        var p = CAT_PARENT[c];
        var guard = 0;
        while (p && p !== 0 && guard < 100) {
            guard++;
            if (!blocked[p]) {
                blocked[p] = 'La sotto-materia "' + cName + '" è già collegata al progetto "' + link.project_name + '".';
            }
            p = CAT_PARENT[p];
        }

        // scende verso le foglie: ogni figlio/nipote viene bloccato perché un suo padre (c) è collegato
        var stack = (CAT_CHILDREN[c] || []).slice();
        var guard2 = 0;
        while (stack.length && guard2 < 5000) {
            guard2++;
            var cur = stack.pop();
            if (!blocked[cur]) {
                blocked[cur] = 'La materia padre "' + cName + '" è già collegata al progetto "' + link.project_name + '".';
            }
            (CAT_CHILDREN[cur] || []).forEach(function(x){ stack.push(x); });
        }
    });
    return blocked;
}

/* Applica il blocco calcolato al DOM del selettore materie del popup progetto */
function prApplyCategoryLock(editingId){
    var modal = document.getElementById('cs-modal-category');
    if (!modal) return;
    var blocked = prComputeBlockedCategories(editingId);
    modal.querySelectorAll('[data-cs-select]').forEach(function(el){
        var id = parseInt(el.getAttribute('data-cs-select'), 10);
        var wrap = el.closest('.cs-row-wrap');
        if (id > 0 && blocked[id]) {
            el.classList.add('cs-row-disabled');
            el.setAttribute('data-cs-disabled-reason', blocked[id]);
            if (wrap) wrap.classList.add('cs-row-wrap-disabled');
        } else {
            el.classList.remove('cs-row-disabled');
            el.removeAttribute('data-cs-disabled-reason');
            if (wrap) wrap.classList.remove('cs-row-wrap-disabled');
        }
    });
}

var editingId = 0;
var prColorManual = false, prColorValue = '#8E8E93', prParentColor = '#8E8E93';
window.prOpenEdit = function(id, forceParentId){
    editingId = id || 0;
    document.getElementById('prSheetTitle').textContent = editingId ? 'Modifica progetto' : 'Nuovo progetto';
    document.getElementById('prDeleteBtn').style.display = editingId > 0 ? '' : 'none';
    document.getElementById('prFieldName').value = '';
    var favBtn = document.getElementById('prFieldFav');
    if (favBtn) { favBtn.classList.remove('is-fav'); favBtn.setAttribute('aria-pressed','false'); }
    document.getElementById('prFieldObiettivo').value = '';
    document.getElementById('prFieldScadenza').value = '';
    var noteWrap = document.querySelector('.fmt-editor-wrap[data-field="prFieldNote"]');
    if (noteWrap && window.fcHydrateWrap) window.fcHydrateWrap(noteWrap, '');

    var parentToShow = 0;
    prColorManual = false; prColorValue = '#8E8E93';
    var linkedCatId = 0, linkedCatName = '', linkedCatColor = '';

    if (editingId > 0 && PROJ_LOOKUP[editingId]) {
        var info = PROJ_LOOKUP[editingId];
        document.getElementById('prFieldName').value = info.name;
        document.getElementById('prFieldObiettivo').value = info.data_obiettivo || '';
        document.getElementById('prFieldScadenza').value = info.data_scadenza || '';
        /* la stellina mostra subito se il progetto è già tra i preferiti */
        if (window.prSetFavourite) window.prSetFavourite(info.is_favorite === 1);
        parentToShow = info.parent_id || 0;
        prColorManual = info.color_edited === 1;
        prColorValue = info.color || '#8E8E93';
        if (info.fc_category) { linkedCatId = info.fc_category.id; linkedCatName = info.fc_category.name; linkedCatColor = info.fc_category.color; }

        // le note ora arrivano direttamente dai dati del progetto (prima si
        // leggevano da un elemento nel DOM che non c'è più / non c'era in griglia)
        if (noteWrap && window.fcHydrateWrap) {
            window.fcHydrateWrap(noteWrap, info.note || '');
        }
    } else if (forceParentId) {
        parentToShow = forceParentId;
    }

    // 1. Resetta lo stato di tutti i progetti (utile se apri/chiudi il popup più volte)
    document.querySelectorAll('[data-ps-select]').forEach(function(node) {
        var wrapper = node.closest('li, .ps-item') || node.parentElement;
        if (wrapper) {
            wrapper.style.pointerEvents = ''; // Riattiva i click
            wrapper.style.opacity = '';       // Ripristina l'opacità originale
            wrapper.style.filter = '';        // Rimuove la scala di grigi
        }
    });

    // 2. Disabilita il progetto corrente (e l'intero ramo dei suoi figli)
    if (editingId > 0) {
        var currentNode = document.querySelector('[data-ps-select="' + editingId + '"]');
        if (currentNode) {
            var wrapperToDisable = currentNode.closest('li, .ps-item') || currentNode.parentElement;
            if (wrapperToDisable) {
                // Rende l'intero blocco (padre + figli) non cliccabile e non reattivo al passaggio del mouse
                wrapperToDisable.style.pointerEvents = 'none'; 
                
                // Lo sbiadisce per farlo sembrare inattivo (grayed out)
                wrapperToDisable.style.opacity = '0.4'; 
                
                // Applica un filtro per ingrigire anche il pallino colorato del progetto
                wrapperToDisable.style.filter = 'grayscale(100%)'; 
            }
        }
    }

    prParentColor = (parentToShow > 0 && PROJ_LOOKUP[parentToShow]) ? PROJ_LOOKUP[parentToShow].color : '#8E8E93';
    if (!prColorManual) prColorValue = prParentColor;
    prUpdateColorUI();

    if (window.psSetValue) {
        if (parentToShow > 0 && PROJ_LOOKUP[parentToShow]) {
            window.psSetValue('ps-modal-parent', parentToShow, PROJ_LOOKUP[parentToShow].name, PROJ_LOOKUP[parentToShow].color);
        } else {
            window.psSetValue('ps-modal-parent', 0, 'Nessuno (progetto radice)', '');
        }
    }
    if (window.csSetValue) {
        if (linkedCatId > 0) window.csSetValue('cs-modal-category', linkedCatId, linkedCatName, linkedCatColor);
        else window.csSetValue('cs-modal-category', 0, 'Nessuna materia collegata', '');
    }
    prApplyCategoryLock(editingId);
    document.getElementById('prSheetOverlay').classList.add('show');
};
window.prCloseEdit = function(){ document.getElementById('prSheetOverlay').classList.remove('show'); };

/* ---------------- pannello "Scadenze e obiettivi" ---------------- */
var prDatesState = { field: 'scadenza', filter: 'all', sortDir: 'asc' };

function prDatesDayStatus(dateStr){
    var d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    var today = new Date();
    today.setHours(0,0,0,0);
    var dOnly = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    if (dOnly.getTime() < today.getTime()) return 'past';
    if (dOnly.getTime() === today.getTime()) return 'today';
    return 'future';
}

function prDatesPathFor(pid){
    var parts = [];
    var guard = 0;
    var cur = PROJ_LOOKUP[pid] ? PROJ_LOOKUP[pid].parent_id : 0;
    while (cur && cur !== 0 && PROJ_LOOKUP[cur] && guard < 50) {
        guard++;
        parts.unshift(PROJ_LOOKUP[cur].name);
        cur = PROJ_LOOKUP[cur].parent_id;
    }
    return parts.join(' › ');
}

function prDatesRender(){
    var switchEl = document.getElementById('prDatesFieldSwitch');
    Array.prototype.forEach.call(switchEl.querySelectorAll('button'), function(b){
        b.classList.toggle('active', b.getAttribute('data-field') === prDatesState.field);
    });
    var filtersEl = document.getElementById('prDatesFilters');
    Array.prototype.forEach.call(filtersEl.querySelectorAll('button'), function(b){
        b.classList.toggle('active', b.getAttribute('data-filter') === prDatesState.filter);
    });
    document.getElementById('prDatesSortLabel').textContent = (prDatesState.sortDir === 'asc') ? 'Più vicine' : 'Più lontane';

    var key = (prDatesState.field === 'scadenza') ? 'data_scadenza' : 'data_obiettivo';
    var icon = (prDatesState.field === 'scadenza') ? '⏰' : '🎯';
    var items = [];
    Object.keys(PROJ_LOOKUP).forEach(function(pid){
        var p = PROJ_LOOKUP[pid];
        var raw = p[key];
        if (!raw) return;
        var status = prDatesDayStatus(raw);
        if (!status) return;
        if (prDatesState.filter !== 'all' && prDatesState.filter !== status) return;
        items.push({ id: pid, name: p.name, color: p.color || '#8E8E93', raw: raw, status: status });
    });

    items.sort(function(a, b){
        var t1 = new Date(a.raw + 'T00:00:00').getTime();
        var t2 = new Date(b.raw + 'T00:00:00').getTime();
        return prDatesState.sortDir === 'asc' ? (t1 - t2) : (t2 - t1);
    });

    var listEl = document.getElementById('prDatesList');
    listEl.innerHTML = '';
    if (items.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'pr-dates-empty';
        empty.textContent = 'Nessun progetto con questa data.';
        listEl.appendChild(empty);
        return;
    }
    items.forEach(function(it){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pr-dates-item';
        btn.style.setProperty('--item-color', it.color);
        btn.onclick = function(){ window.location.href = 'progetti.php?id=' + it.id; };

        var dot = document.createElement('span');
        dot.className = 'pr-dates-item-dot';
        btn.appendChild(dot);

        var main = document.createElement('div');
        main.className = 'pr-dates-item-main';
        var nameEl = document.createElement('div');
        nameEl.className = 'pr-dates-item-name';
        var nameTrack = document.createElement('span');
        nameTrack.className = 'pr-name-track';
        nameTrack.textContent = it.name;
        nameEl.appendChild(nameTrack);
        main.appendChild(nameEl);
        var path = prDatesPathFor(it.id);
        if (path) {
            var pathEl = document.createElement('div');
            pathEl.className = 'pr-dates-item-path';
            pathEl.textContent = path;
            main.appendChild(pathEl);
        }
        btn.appendChild(main);

        var tag = document.createElement('span');
        tag.className = 'pr-date-tag pr-date-tag-' + it.status;
        var d = new Date(it.raw + 'T00:00:00');
        var label = d.toLocaleDateString('it-IT', { day: '2-digit', month: 'short' });
        tag.textContent = icon + ' ' + label;
        btn.appendChild(tag);

        listEl.appendChild(btn);
    });

    if (window.prInitNameMarquees) window.prInitNameMarquees(listEl);
}

window.prOpenDatesView = function(){
    document.getElementById('prDatesOverlay').classList.add('show');
    prDatesRender();
};
window.prCloseDatesView = function(){
    document.getElementById('prDatesOverlay').classList.remove('show');
};
window.prDatesSetField = function(field){
    prDatesState.field = field;
    prDatesRender();
};
window.prDatesSetFilter = function(filter){
    prDatesState.filter = filter;
    prDatesRender();
};
window.prDatesToggleSort = function(){
    prDatesState.sortDir = (prDatesState.sortDir === 'asc') ? 'desc' : 'asc';
    prDatesRender();
};

/* ---------------- colore: automatico dal padre oppure scelto a mano (bug #4) ---------------- */
window.prSetColorAuto = function(){
    prColorManual = false;
    prColorValue = prParentColor;
    prUpdateColorUI();
};
window.prSetColorManual = function(hex){
    prColorManual = true;
    prColorValue = hex;
    prUpdateColorUI();
};
function prUpdateColorUI(){
    document.getElementById('fColorMode').value = prColorManual ? 'manual' : 'auto';
    document.getElementById('fProgettoColor').value = prColorValue;
    document.getElementById('prColorModeLabel').textContent = prColorManual ? '(scelto manualmente)' : '(automatico dal padre)';
    document.getElementById('prColorPreviewIcon').style.setProperty('--pr-preview-color', prColorValue);
    document.getElementById('prColorPreviewName').textContent = prColorManual ? 'Colore personalizzato' : 'Colore automatico dal padre';
    document.getElementById('prColorAutoBtn').classList.toggle('sel', !prColorManual);
    document.querySelectorAll('#prColorGrid .pr-color-swatch:not(.pr-color-swatch-auto)').forEach(function(s){
        s.classList.toggle('sel', prColorManual && s.getAttribute('data-hex').toLowerCase() === prColorValue.toLowerCase());
    });
    document.getElementById('prCustomColorInput').value = prColorValue;
    document.getElementById('prCustomColorHex').textContent = prColorManual ? prColorValue.toUpperCase() : '';
}
// se il padre scelto cambia mentre siamo in automatico, il colore anteprima segue subito
document.addEventListener('projectselected', function(e){
    if (e.detail.uid !== 'parent') return;
    prParentColor = e.detail.color || '#8E8E93';
    if (!prColorManual) { prColorValue = prParentColor; prUpdateColorUI(); }
});

/* la stellina si salva da sé, senza passare dal modulo */
(function prFavourite(){
    var btn = document.getElementById('prFieldFav');
    if (!btn) return;
    btn.addEventListener('click', function(){
        if (!editingId) { toast('Salva prima il progetto'); return; }
        var next = !btn.classList.contains('is-fav');
        btn.classList.toggle('is-fav', next);
        btn.setAttribute('aria-pressed', next ? 'true' : 'false');
        btn.querySelector('span').textContent = next ? 'Tra i preferiti' : 'Metti tra i preferiti';
        var body = new URLSearchParams({ project_id: editingId, value: next ? 1 : 0 });
        fetch('progetti.php?ajax=toggle_favorite', {
            method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function(r){ return r.json(); }).then(function(r){
            if (!r || !r.ok) { btn.classList.toggle('is-fav', !next); toast('Non salvato'); }
        }).catch(function(){ btn.classList.toggle('is-fav', !next); toast('Errore di rete'); });
    });
    /* quando si apre un progetto, la stellina mostra il suo stato */
    window.prSetFavourite = function(on){
        btn.classList.toggle('is-fav', !!on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.querySelector('span').textContent = on ? 'Tra i preferiti' : 'Metti tra i preferiti';
    };
})();

window.prSubmitEdit = function(){
    var name = document.getElementById('prFieldName').value.trim();
    if (!name) { document.getElementById('prFieldName').focus(); return; }
    if (window.fcSyncAllEditors) window.fcSyncAllEditors();
    document.getElementById('fProgettoId').value = editingId;
    document.getElementById('fProgettoName').value = name;
    document.getElementById('fProgettoNote').value = document.getElementById('prFieldNote').value;
    document.getElementById('fParentId').value = document.getElementById('ps-value-parent') ? document.getElementById('ps-value-parent').value : '0';
    document.getElementById('fFcCategoryId').value = document.getElementById('cs-value-category') ? document.getElementById('cs-value-category').value : '0';
    document.getElementById('fDataObiettivo').value = document.getElementById('prFieldObiettivo').value;
    document.getElementById('fDataScadenza').value = document.getElementById('prFieldScadenza').value;
    document.getElementById('fRedirectId').value = editingId || '';
    document.getElementById('formSave').submit();
};

/* ---------------- salvataggio automatico del popup progetto ----------------
   Come nel diario: appena si scrive o si cambia qualcosa, si salva da sé in
   background. Niente tasto Salva da premere e niente ricaricamenti: la pagina
   si aggiorna solo quando si chiude il popup, se qualcosa è cambiato. */
(function prAutoSave(){
    var timer = null, pending = false, dirty = false;

    function collect(){
        var name = document.getElementById('prFieldName');
        if (!name || !name.value.trim()) return null;   // senza nome non si salva
        if (window.fcSyncAllEditors) window.fcSyncAllEditors();
        document.getElementById('fProgettoId').value = editingId;
        document.getElementById('fProgettoName').value = name.value.trim();
        document.getElementById('fProgettoNote').value = document.getElementById('prFieldNote').value;
        document.getElementById('fParentId').value = document.getElementById('ps-value-parent') ? document.getElementById('ps-value-parent').value : '0';
        document.getElementById('fFcCategoryId').value = document.getElementById('cs-value-category') ? document.getElementById('cs-value-category').value : '0';
        document.getElementById('fDataObiettivo').value = document.getElementById('prFieldObiettivo').value;
        document.getElementById('fDataScadenza').value = document.getElementById('prFieldScadenza').value;
        document.getElementById('fRedirectId').value = editingId || '';
        return document.getElementById('formSave');
    }

    window.prSaveNow = function(){
        var form = collect();
        if (!form) return Promise.resolve(false);
        pending = true;
        return fetch(window.location.href, { method:'POST', body:new FormData(form), credentials:'same-origin' })
            .then(function(){ pending = false; dirty = true; return true; })
            .catch(function(){ pending = false; toast('Modifica non salvata'); return false; });
    };

    function schedule(){
        if (timer) clearTimeout(timer);
        timer = setTimeout(function(){ timer = null; window.prSaveNow(); }, 800);
    }

    /* Solo il popup di modifica progetto: la classe .pr-sheet la usano anche
       altri pannelli, e salvare da lì scriverebbe sul progetto sbagliato.
       Il riconoscimento è il campo del nome dentro lo stesso pannello. */
    function fromEditSheet(e){
        if (!e.target || !e.target.closest) return false;
        /* Deve esserci un progetto davvero in modifica e il pannello davvero
           aperto: senza questi due controlli bastava un evento "change"
           generato da altro codice al caricamento della pagina per far
           partire un salvataggio, che a sua volta rimandava alla pagina del
           progetto — ed era questo a far "tornare indietro" la vista
           preferiti appena aperta. */
        if (!editingId) return false;
        var overlay = e.target.closest('.pr-sheet-overlay');
        if (overlay && !overlay.classList.contains('show')) return false;
        var sheet = e.target.closest('.pr-sheet, #prEditModal, .pr-edit-modal');
        return !!(sheet && sheet.querySelector('#prFieldName'));
    }
    document.addEventListener('input', function(e){ if (fromEditSheet(e)) schedule(); }, true);
    document.addEventListener('change', function(e){ if (fromEditSheet(e)) schedule(); }, true);
    /* progetto padre e categoria si scelgono da componenti esterni */
    document.addEventListener('projectselected', schedule);
    document.addEventListener('categoryselected', schedule);

    /* chiudendo il popup: ultimo salvataggio e, se serve, aggiorno la pagina */
    window.prFlushAndRefresh = function(){
        if (timer) { clearTimeout(timer); timer = null; }
        window.prSaveNow().then(function(){
            if (dirty) window.location.reload();
        });
    };
})();

/* ---------------- popup pomodori (esternalizzato) ---------------- */
window.prOpenStats = function(projectId, projectName, projectColor){
    if (window.openPomodoroStorico) {
        window.openPomodoroStorico({ scope: 'project', projectId: projectId, projectName: projectName, projectColor: projectColor || '' });
    } else {
        toast('Popup storico non ancora incluso in questa pagina');
    }
};

/* ---------------- conferma eliminazione (in due passi: prima il progetto,
   poi cosa fare dei pomodori collegati) ---------------- */
function prHasChildren(id){
    // stesso controllo che il PHP fa lato server per la riga nell'elenco:
    // qui serve per mostrare il messaggio giusto anche dal popup di modifica.
    for (var k in PROJ_LOOKUP) { if (PROJ_LOOKUP[k].parent_id === id) return true; }
    return false;
}
// l'onclick del tasto nel popup e' un attributo HTML: gira nello scope globale,
// non dentro questa IIFE, quindi non vedrebbe ne' editingId ne' prHasChildren
// se le chiamasse direttamente. Serve un ponte esposto su window.
window.prDeleteCurrentProject = function(){
    prConfirmDelete(editingId, prHasChildren(editingId));
};
var deletingId = 0, deletingHasChildren = false;

window.prConfirmDelete = function(id, hasChildren){
    deletingId = id;
    deletingHasChildren = !!hasChildren;
    var info = PROJ_LOOKUP[id];
    var sessionCount = info ? (info.session_count || 0) : 0;
    document.getElementById('prConfirmTitle').textContent = 'Eliminare questo progetto?';

    if (sessionCount === 0) {
        // nessun pomodoro collegato (nemmeno nei sotto-progetti): niente da
        // chiedere, resta la semplice conferma di sempre.
        var plainMsg = hasChildren ? 'Questo progetto ha dei sotto-progetti: verranno eliminati anche loro. ' : '';
        plainMsg += 'Questa azione non può essere annullata.';
        document.getElementById('prConfirmMsg').textContent = plainMsg;
        document.getElementById('prConfirmActions').innerHTML =
            '<button type="button" class="pr-btn pr-btn-danger" onclick="prDoDelete(false)">Elimina</button>' +
            '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
        document.getElementById('prConfirmOverlay').classList.add('show');
        return;
    }

    var msg = hasChildren ? 'Questo progetto ha dei sotto-progetti: verranno eliminati anche loro. ' : '';
    msg += 'Vuoi eliminare anche i pomodori collegati' + (hasChildren ? ' a questo progetto e ai suoi sotto-progetti' : '') + '?';
    document.getElementById('prConfirmMsg').textContent = msg;
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-danger" onclick="prDoDelete(true)">Elimina anche i pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-neutral" onclick="prConfirmKeepSessions()">Mantieni i pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
    document.getElementById('prConfirmOverlay').classList.add('show');
};
// scelto "mantieni i pomodori": prima di far confermare per davvero, suggeriamo
// di andare a riassegnarli, cosi' non restano tutti orfani senza accorgersene.
window.prConfirmKeepSessions = function(){
    var info = PROJ_LOOKUP[deletingId];
    var name = info ? info.name : 'questo progetto';
    document.getElementById('prConfirmTitle').textContent = 'I pomodori resteranno senza progetto';
    document.getElementById('prConfirmMsg').textContent =
        'Se preferisci, vai prima su "' + name + '" → Pomodori, selezionali tutti e assegnali a un altro progetto. ' +
        'Altrimenti, continuando, i pomodori collegati' + (deletingHasChildren ? ' a questo progetto e ai suoi sotto-progetti' : '') + ' resteranno senza progetto.';
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-neutral" onclick="prGoToPomodoriBeforeDelete()">Vai ai pomodori</button>' +
        '<button type="button" class="pr-btn pr-btn-danger" onclick="prDoDelete(false)">Elimina comunque</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" onclick="prCloseConfirm()">Annulla</button>';
};
window.prGoToPomodoriBeforeDelete = function(){
    var info = PROJ_LOOKUP[deletingId];
    prCloseConfirm();
    prCloseEdit();
    if (!window.openPomodoroStorico) return;
    window.openPomodoroStorico({ scope: 'project', projectId: deletingId, projectName: info ? info.name : '' });
    if (window.phToggleSelectMode) window.phToggleSelectMode(); // pronto per "Seleziona tutti" + "Assegna a..."
};
window.prCloseConfirm = function(){ document.getElementById('prConfirmOverlay').classList.remove('show'); };
window.prDoDelete = function(deleteSessions){
    document.getElementById('fDelId').value = deletingId;
    document.getElementById('fDelSessions').value = deleteSessions ? '1' : '0';
    // resta sul progetto attualmente aperto dopo l'eliminazione (richiesta 3)
    document.getElementById('fDelRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formDelete').submit();
};

/* ================================================================
   SWIPE (destra = rivela Modifica/Elimina) — gesto singolo, semplice
   ================================================================ */
(function(){
    var pend = null;
    document.addEventListener('pointerdown', function(e){
        var content = e.target.closest('.pr-row-content');
        if (!content || prSubSelecting) return;
        /* Resta escluso solo il pomodoro, perché porta via dalla pagina.
           Freccia, maniglia e spunta invece lasciano passare il gesto: sono
           tre bersagli larghi in mezzo alla riga, e partendo da lì lo
           scorrimento sembrava rotto a caso. Il tocco secco su di loro
           continua a funzionare: lo scorrimento si accorge di esistere solo
           dopo sei pixel di movimento, e il trascinamento dalla maniglia si
           annulla da solo appena il dito si sposta. */
        if (e.target.closest('.pr-pomo-btn')) return;
        /* Il titolo NON è più escluso in blocco. Prima lo era, e siccome il
           titolo occupa quasi tutta la riga, lo scorrimento non partiva quasi
           mai: sembrava rotto. Ora si esclude solo il titolo che è davvero
           più lungo dello spazio disponibile, perché su quello il gesto
           orizzontale serve a leggere il resto del nome. Un titolo corto non
           ha niente da scorrere, quindi lascia passare lo scorrimento della
           riga come tutto il resto. */
        var nome = e.target.closest('.pr-name');
        if (nome && nome._prMarquee) return;
        /* un trascinamento in corso ha la precedenza su tutto */
        if (window.prDragging) return;
        closeAllSwipes(content.closest('.pr-row'));
        var row = content.closest('.pr-row');
        var actions = row.querySelector('.swipe-actions');
        pend = { row: row, content: content, pid: e.pointerId, startX: e.clientX, startTranslate: row._swipeX || 0, maxReveal: actions ? actions.offsetWidth : 156, moved:false };
        content.style.transition = 'none';
    });
    /* Chiamata dal trascinamento quando scatta: lo scorrimento in corso si
       annulla e la riga torna dov'era, senza animazione da inseguire mentre il
       dito la sta già portando altrove. */
    window.prCancelSwipe = function(){
        if (!pend) return;
        var p = pend; pend = null;
        p.content.style.transition = '';
        p.content.style.transform = 'translateX(' + (p.startTranslate || 0) + 'px)';
    };

    document.addEventListener('pointermove', function(e){
        if (window.prDragging) { pend = null; return; }
        if (!pend || e.pointerId !== pend.pid) return;
        var dx = e.clientX - pend.startX;
        if (!pend.moved && Math.abs(dx) < 6) return;
        pend.moved = true;
        var x = pend.startTranslate + dx;
        if (x < 0) x = x * 0.15;
        if (x > pend.maxReveal) x = pend.maxReveal;
        pend.content.style.transform = 'translateX(' + x + 'px)';
        pend.currentX = x;
    });
    function endSwipe(){
        if (!pend) return;
        var p = pend; pend = null;
        p.content.style.transition = 'transform .28s var(--spring)';
        var openIt = p.moved && p.currentX > p.maxReveal * 0.45;
        var final = openIt ? p.maxReveal : 0;
        p.content.style.transform = 'translateX(' + final + 'px)';
        p.row._swipeX = final;
        if (openIt) { window.prNoteSwipeOpen(p.row); buzz(10); }
        /* Ora che il gesto può partire dalla freccia, dalla maniglia e dalla
           spunta, al rilascio arriverebbe anche il clic su quel pulsante: si
           aprirebbero i sotto-progetti, o peggio cambierebbe lo stato del
           progetto, senza che nessuno l'abbia chiesto. Dopo uno scorrimento
           vero il primo clic si ferma qui. */
        if (p.moved) {
            var fermaClic = function(ev){ ev.stopPropagation(); ev.preventDefault(); };
            document.addEventListener('click', fermaClic, true);
            /* si toglie da solo: se il clic non arriva mai (col mouse capita),
               l'ascoltatore non deve restare lì a mangiarsi i tocchi dopo */
            setTimeout(function(){ document.removeEventListener('click', fermaClic, true); }, 320);
        }
    }
    document.addEventListener('pointerup', endSwipe);
    document.addEventListener('pointercancel', endSwipe);

    /* Le righe aperte si contano sulle dita: tenerne l'elenco costa nulla ed
       evita di passare in rassegna tutte le righe della pagina a ogni tocco e
       a ogni click (era una scansione dell'intero elenco per ogni gesto). */
    var aperte = [];
    window.prNoteSwipeOpen = function(row){ if (aperte.indexOf(row) === -1) aperte.push(row); };

    window.closeAllSwipes = function(except){
        if (!aperte.length) return;
        var restano = [];
        aperte.forEach(function(row){
            if (row === except) { restano.push(row); return; }
            var content = row.querySelector('.pr-row-content');
            if (content && row._swipeX) {
                content.style.transition = 'transform .25s var(--spring)';
                content.style.transform = 'translateX(0)';
            }
            row._swipeX = 0;
        });
        aperte = restano;
    };
    document.addEventListener('click', function(e){
        if (!e.target.closest('.pr-row')) closeAllSwipes(null);
    });
})();

/* ================================================================
   DRAG REORDER (tramite maniglia ☰) — tra fratelli dello stesso padre
   ================================================================ */
(function(){
    var drag = null;
    /* Il trascinamento parte solo dopo mezzo secondo di pressione: prima
       scattava al primo tocco e rendeva impossibile scorrere l'elenco
       partendo dalla maniglia. Se nel frattempo il dito si muove, vince
       lo scorrimento e il trascinamento non parte. */
    var dragArm = null;
    document.addEventListener('pointerdown', function(e){
        var grip = e.target.closest('.pr-grip');
        if (!grip) return;
        var wrap = grip.closest('.pr-row-wrap');
        var list = wrap.parentElement;
        var startY = e.clientY, startX = e.clientX, pid = e.pointerId;

        dragArm = {
            pid: pid, startY: startY, startX: startX,
            timer: setTimeout(function(){
                dragArm = null;
                drag = { wrap: wrap, list: list, pid: pid, startY: startY, moved:false, rects:null, rectsAt:0 };
                wrap.classList.add('dragging');
                /* da qui il dito muove la riga, non la pagina */
                document.body.style.touchAction = 'none';
                /* Tenendo premuto, il telefono cercava di selezionare il testo
                   sotto il dito (e faceva comparire la lente e il menù
                   copia/incolla). Con la pagina in stato di trascinamento la
                   selezione è disattivata via CSS, e qui si cancella anche
                   quella eventualmente già iniziata. */
                document.body.classList.add('pr-dragging-now');
                /* Il gesto è cominciato come uno scorrimento orizzontale (lo
                   swipe si arma sullo stesso tocco): da qui in poi comanda il
                   trascinamento, quindi la riga torna al suo posto e i tasti
                   modifica/elimina non devono più comparire. */
                window.prDragging = true;
                if (window.prCancelSwipe) window.prCancelSwipe();
                /* Il titolo della riga che si sta spostando torna al suo
                   inizio. Lo scorrimento automatico del nome lo lasciava
                   spesso a metà corsa, cioè tradotto fuori dalla riga: nel
                   momento in cui si prendeva la riga, il testo spariva.
                   Qui l'animazione si ferma e il nome torna in vista. */
                try {
                    var titolo = wrap.querySelector('.pr-name');
                    if (titolo && titolo._prMarquee) {
                        var m = titolo._prMarquee;
                        if (m.anim) { m.anim.cancel(); m.anim = null; }
                        if (m.resumeTimer) { clearTimeout(m.resumeTimer); m.resumeTimer = null; }
                        m.offset = 0;
                        m.track.style.transform = 'translateX(0)';
                    }
                } catch (err) {}
                try {
                    var sel = window.getSelection();
                    if (sel && sel.removeAllRanges) sel.removeAllRanges();
                } catch (err) {}
                buzz(15);
            }, 500)
        };
    }, { passive:true });

    /* se il dito scorre prima che scatti, il trascinamento si annulla */
    document.addEventListener('pointermove', function(e){
        if (!dragArm || e.pointerId !== dragArm.pid) return;
        if (Math.abs(e.clientY - dragArm.startY) > 8 || Math.abs(e.clientX - dragArm.startX) > 8) {
            clearTimeout(dragArm.timer);
            dragArm = null;
        }
    }, { passive:true });
    document.addEventListener('pointerup', function(){
        if (dragArm) { clearTimeout(dragArm.timer); dragArm = null; }
    }, { passive:true });
    document.addEventListener('pointercancel', function(){
        if (dragArm) { clearTimeout(dragArm.timer); dragArm = null; }
    }, { passive:true });

    /* Le posizioni delle righe si misurano una volta e si tengono da parte per
       un attimo. Prima ogni singolo movimento del dito rimisurava tutte le
       righe dell'elenco una per una: con molti sottoprogetti il trascinamento
       diventava una melassa. Le misure si rifanno solo dopo uno scambio (che
       le rende vecchie) o dopo 250ms. */
    function misureRighe(){
        var ora = Date.now();
        if (drag.rects && ora - drag.rectsAt < 250) return drag.rects;
        var out = [];
        var figli = drag.list.children;
        for (var i = 0; i < figli.length; i++) {
            var el = figli[i];
            if (!el.classList.contains('pr-row-wrap') || el === drag.wrap) continue;
            var r = el.getBoundingClientRect();
            out.push({ el: el, mid: r.top + r.height / 2 });
        }
        drag.rects = out; drag.rectsAt = ora;
        return out;
    }

    document.addEventListener('pointermove', function(e){
        if (!drag || e.pointerId !== drag.pid) return;
        e.preventDefault();
        drag.moved = true;
        /* un solo riposizionamento per fotogramma: gli eventi del puntatore
           arrivano più fitti di quanto lo schermo sappia disegnare */
        if (drag.raf) return;
        var y = e.clientY;
        drag.raf = requestAnimationFrame(function(){
            if (!drag) return;
            drag.raf = null;
            var righe = misureRighe();
            for (var i = 0; i < righe.length; i++) {
                if (y < righe[i].mid) {
                    drag.list.insertBefore(drag.wrap, righe[i].el);
                    drag.rects = null;              // spostando, le misure non valgono più
                    return;
                }
            }
            drag.list.appendChild(drag.wrap);
            drag.rects = null;
        });
    });

    function endDrag(){
        document.body.style.touchAction = '';
        document.body.classList.remove('pr-dragging-now');
        window.prDragging = false;
        if (!drag) return;
        if (drag.raf) cancelAnimationFrame(drag.raf);
        var d = drag; drag = null;
        d.wrap.classList.remove('dragging');
        if (!d.moved) return;
        var ids = Array.prototype.filter.call(d.list.children, function(el){ return el.classList.contains('pr-row-wrap'); })
                        .map(function(el){ return el.getAttribute('data-id'); });
        document.getElementById('fReorderIds').value = ids.join(',');
        /* Il nuovo ordine si salva in background: la pagina non si ricarica
           più, così l'elenco resta dov'è e non si perde la posizione dello
           scorrimento dopo ogni spostamento. */
        var form = document.getElementById('formReorder');
        fetch(window.location.href, { method:'POST', body:new FormData(form), credentials:'same-origin' })
            .then(function(){ toast('Ordine aggiornato'); })
            .catch(function(){
                toast('Ordine non salvato, ricarico');
                window.location.reload();
            });
    }
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);
})();

/* Mostra la riga di input, salva in background e ricarica la pagina corrente per mostrare le novità */
window.prShowInlineInput = function(e, parentId, btn) {
    e.stopPropagation(); 
    
    var rowWrap = btn.closest('.pr-row-wrap');
    if (rowWrap.querySelector('.pr-inline-input-row')) {
        rowWrap.querySelector('.pr-inline-input-row').remove();
        return;
    }

    var inputRow = document.createElement('div');
    inputRow.className = 'pr-inline-input-row';
    
    var input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'Nome nuovo sottoprogetto...';
    input.autocomplete = 'off';
    
    // Pulsante Annulla (Grigio chiaro con icona X)
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'pr-inline-cancel-btn';
    cancelBtn.setAttribute('aria-label', 'Annulla');
    cancelBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    
    // Pulsante Salva (Colore del progetto con icona Check)
    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'pr-inline-save-btn';
    saveBtn.setAttribute('aria-label', 'Salva');
    saveBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    
    inputRow.appendChild(input);
    inputRow.appendChild(cancelBtn);
    inputRow.appendChild(saveBtn);
    
    var prRow = rowWrap.querySelector('.pr-row');
    prRow.insertAdjacentElement('afterend', inputRow);
    
    input.focus();
    
    function submitInlineData() {
        var val = input.value.trim();
        if (!val) return;
        
        // Disabilitiamo temporaneamente i controlli per evitare click multipli
        input.disabled = true;
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.style.opacity = '0.5';

        // Popoliamo i campi del form nascosto esistente nella pagina
        document.getElementById('fProgettoId').value = 0;
        document.getElementById('fProgettoName').value = val;
        document.getElementById('fProgettoNote').value = '';
        document.getElementById('fParentId').value = parentId;
        document.getElementById('fFcCategoryId').value = 0;
        document.getElementById('fDataObiettivo').value = '';
        document.getElementById('fDataScadenza').value = '';
        document.getElementById('fColorMode').value = 'auto'; 
        
        // Assicuriamo che al ricaricamento la destinazione rimanga la pagina attuale ($selectedId)
        document.getElementById('fRedirectId').value = "<?php echo $selectedId; ?>";
        
        // Recuperiamo il form e i suoi dati
        var form = document.getElementById('formSave');
        var formData = new FormData(form);
        
        // Invio asincrono AJAX al file corrente
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            if (response.ok) {
                // Ricarica la pagina corrente per mostrare il nuovo elemento creato
                window.location.reload();
            } else {
                alert('Errore durante il salvataggio.');
                resetUI();
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Errore di rete durante il salvataggio.');
            resetUI();
        });
    }

    function resetUI() {
        input.disabled = false;
        saveBtn.disabled = false;
        cancelBtn.disabled = false;
        saveBtn.style.opacity = '1';
        input.focus();
    }
    
    saveBtn.addEventListener('click', submitInlineData);
    cancelBtn.addEventListener('click', function() { inputRow.remove(); });
    
    input.addEventListener('keydown', function(evt) {
        if (evt.key === 'Enter') {
            evt.preventDefault();
            submitInlineData();
        } else if (evt.key === 'Escape') {
            inputRow.remove();
        }
    });
};

/* ================================================================
   SCORRIMENTO AUTOMATICO DEI TITOLI (marquee) — per i nomi troncati
   su schermi stretti. Ogni titolo resta fermo qualche secondo,
   scorre lentamente fino a mostrarsi per intero, resta fermo alla
   fine, poi torna dolcemente all'inizio e ricomincia in loop.
   L'utente può sempre trascinare il titolo a mano (senza che questo
   valga come swipe della riga): mentre lo tocca l'animazione
   automatica si ferma e riparte da sola poco dopo.

   Nota tecnica: l'animazione è affidata alla Web Animations API su
   una proprietà "transform" (thread di composizione del browser),
   non a un nostro loop che sposta scrollLeft ad ogni frame: così
   resta fluida anche con moltissime righe in pagina.
   ================================================================ */
(function(){
    var PAUSE_START = 1800;   // ms fermo all'inizio
    var PAUSE_END = 1800;     // ms fermo alla fine
    var RESET_MS = 700;       // ms per il ritorno morbido all'inizio
    var SPEED = 34;           // px al secondo durante lo scorrimento
    var MIN_SCROLL_MS = 2200;
    var MAX_SCROLL_MS = 14000;
    var RESUME_DELAY = 1800;  // ms di inattività prima che l'auto-scroll riparta

    function currentTranslateX(el){
        var tr = getComputedStyle(el).transform;
        if (!tr || tr === 'none') return 0;
        var m = tr.match(/matrix3d\(([^)]+)\)/);
        if (m) { var p = m[1].split(',').map(Number); return p[12] || 0; }
        m = tr.match(/matrix\(([^)]+)\)/);
        if (m) { var q = m[1].split(',').map(Number); return q[4] || 0; }
        return 0;
    }

    /* (la vecchia buildEntry è sparita: misurava una riga alla volta, ed è
       proprio quello che si voleva smettere di fare. Ora misura in blocco
       misuraInBlocco, più sotto.) */

    function startAutoAnim(entry){
        var total = entry.total, max = entry.max;
        var p1 = PAUSE_START / total;
        var p2 = (PAUSE_START + entry.scrollMs) / total;
        var p3 = (PAUSE_START + entry.scrollMs + PAUSE_END) / total;
        entry.track.style.transform = '';
        entry.anim = entry.track.animate([
            { transform: 'translateX(0px)',        offset: 0,  easing: 'linear' },
            { transform: 'translateX(0px)',        offset: p1, easing: 'ease-in-out' },
            { transform: 'translateX(' + (-max) + 'px)', offset: p2, easing: 'linear' },
            { transform: 'translateX(' + (-max) + 'px)', offset: p3, easing: 'ease-in-out' },
            { transform: 'translateX(0px)',        offset: 1 }
        ], { duration: total, iterations: Infinity, delay: Math.random() * 400 });
    }

    function pauseForUser(entry){
        if (entry.resumeTimer) clearTimeout(entry.resumeTimer);
        var curX = currentTranslateX(entry.track);
        if (entry.anim) { entry.anim.cancel(); entry.anim = null; }
        entry.track.style.transform = 'translateX(' + curX + 'px)';
        entry.offset = curX;
    }

    function scheduleResume(entry){
        if (entry.resumeTimer) clearTimeout(entry.resumeTimer);
        entry.resumeTimer = setTimeout(function(){
            var from = entry.offset;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            if (Math.abs(from) < 1) { startAutoAnim(entry); return; }
            var back = entry.track.animate(
                [{ transform: 'translateX(' + from + 'px)' }, { transform: 'translateX(0px)' }],
                { duration: RESET_MS, easing: 'ease-in-out', fill: 'forwards' }
            );
            back.onfinish = function(){
                entry.track.style.transform = 'translateX(0px)';
                entry.offset = 0;
                startAutoAnim(entry);
            };
        }, RESUME_DELAY);
    }

    function attachDrag(entry){
        var el = entry.el, track = entry.track;
        var dragging = false, pid = null, startX = 0, startOffset = 0;

        el.addEventListener('pointerdown', function(e){
            pauseForUser(entry);
            dragging = true;
            pid = e.pointerId;
            startX = e.clientX;
            startOffset = entry.offset;
            entry.justDragged = false;
            if (el.setPointerCapture) { try { el.setPointerCapture(pid); } catch(_){} }
        });
        el.addEventListener('pointermove', function(e){
            if (!dragging || e.pointerId !== pid) return;
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 4) entry.justDragged = true;
            var next = startOffset + dx;
            if (next > 0) next = 0;
            if (next < -entry.max) next = -entry.max;
            entry.offset = next;
            track.style.transform = 'translateX(' + next + 'px)';
        });
        function stopDrag(e){
            if (!dragging || (e && e.pointerId !== pid)) return;
            dragging = false;
            scheduleResume(entry);
        }
        el.addEventListener('pointerup', stopDrag);
        el.addEventListener('pointercancel', stopDrag);
        // evita che il rilascio dopo un trascinamento venga letto anche come "tap" di apertura progetto
        el.addEventListener('click', function(e){
            if (entry.justDragged) { e.stopImmediatePropagation(); e.preventDefault(); entry.justDragged = false; }
        }, true);
    }

    /* ------------------------------------------------------------------
       PERCHÉ QUESTA PARTE È FATTA COSÌ (era la causa della lentezza)

       Prima, al caricamento, si passava su OGNI titolo della pagina e per
       ognuno si leggevano offsetWidth e clientWidth: ogni lettura obbliga il
       browser a ricalcolare il layout lì per lì, e con un elenco lungo sono
       centinaia di ricalcoli in fila prima ancora che la pagina si veda.
       Poi ogni titolo troncato si portava dietro un'animazione infinita e
       cinque ascoltatori, anche a chilometri sotto lo schermo: decine di
       animazioni sempre in moto, per niente.

       Ora: nessuno tocca un titolo finché non entra davvero in vista. Chi
       entra viene misurato insieme agli altri in un colpo solo (prima tutte
       le letture, poi tutte le scritture: nessun ricalcolo a catena), e chi
       esce dallo schermo mette in pausa la sua animazione invece di
       continuare a girare al buio.
       ------------------------------------------------------------------ */

    var ridotto = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var osservatore = null;
    var inAttesa = [];      // entrati in vista, ancora da misurare
    var misuraProgrammata = false;

    function misuraInBlocco(){
        misuraProgrammata = false;
        var lotto = inAttesa;
        inAttesa = [];
        if (!lotto.length) return;

        /* 1) SOLO letture: il layout si calcola una volta per tutto il lotto */
        var misure = lotto.map(function(el){
            var track = el.querySelector('.pr-name-track');
            if (!track) return null;
            return { el: el, track: track, max: track.offsetWidth - el.clientWidth };
        });

        /* 2) SOLO scritture: da qui in poi nessuno rilegge più niente */
        misure.forEach(function(m){
            if (!m || m.max <= 2) {
                /* non serve scorrere: si smette di guardarlo del tutto,
                   altrimenti l'osservatore continuerebbe a riferire di una
                   riga su cui non c'è più niente da fare */
                if (m) {
                    m.el._prMarqueeSkip = true;
                    if (osservatore) osservatore.unobserve(m.el);
                }
                return;
            }
            var scrollMs = Math.min(MAX_SCROLL_MS, Math.max(MIN_SCROLL_MS, (m.max / SPEED) * 1000));
            var entry = { el: m.el, track: m.track, max: m.max, scrollMs: scrollMs,
                          total: PAUSE_START + scrollMs + PAUSE_END + RESET_MS,
                          anim: null, resumeTimer: null, offset: 0, justDragged: false };
            m.el._prMarquee = entry;
            attachDrag(entry);
            if (!ridotto) startAutoAnim(entry);
        });
    }

    function programmaMisura(el){
        if (el._prMarqueeSkip || el._prMarquee) return;
        inAttesa.push(el);
        if (misuraProgrammata) return;
        misuraProgrammata = true;
        requestAnimationFrame(misuraInBlocco);
    }

    function creaOsservatore(){
        if (osservatore || !('IntersectionObserver' in window)) return osservatore;
        osservatore = new IntersectionObserver(function(voci){
            voci.forEach(function(v){
                var el = v.target;
                if (v.isIntersecting) {
                    if (el._prMarquee) {
                        /* già misurato: riparte da dov'era */
                        if (el._prMarquee.anim && el._prMarquee.anim.playState === 'paused') el._prMarquee.anim.play();
                    } else {
                        programmaMisura(el);
                    }
                } else if (el._prMarquee && el._prMarquee.anim) {
                    /* fuori dallo schermo non ha senso animare: si mette in
                       pausa, non si annulla, così tornando riprende il filo */
                    el._prMarquee.anim.pause();
                }
            });
        }, { rootMargin: '200px 0px' });   // un po' di anticipo: entra già in moto
        return osservatore;
    }

    window.prInitNameMarquees = function(root){
        var scope = root || document;
        var els = scope.querySelectorAll('.pr-name, .pr-dates-item-name');
        var io = creaOsservatore();
        Array.prototype.forEach.call(els, function(el){
            if (el._prMarqueeInit) return;
            el._prMarqueeInit = true;
            if (io) { io.observe(el); return; }
            /* senza IntersectionObserver (browser vecchi) si torna al modo di
               prima, ma solo per i titoli già nello schermo */
            var r = el.getBoundingClientRect();
            if (r.top < window.innerHeight + 200 && r.bottom > -200) programmaMisura(el);
        });
    };

    /* il ripasso serve solo a chi non ha l'osservatore */
    if (!('IntersectionObserver' in window)) {
        var ripassoTimer = null;
        window.addEventListener('scroll', function(){
            if (ripassoTimer) return;
            ripassoTimer = setTimeout(function(){
                ripassoTimer = null;
                var els = document.querySelectorAll('.pr-name, .pr-dates-item-name');
                Array.prototype.forEach.call(els, function(el){
                    if (el._prMarquee || el._prMarqueeSkip) return;
                    var r = el.getBoundingClientRect();
                    if (r.top < window.innerHeight + 200 && r.bottom > -200) programmaMisura(el);
                });
            }, 260);
        }, { passive: true });
    }

    window.prInitNameMarquees();
})();

})();
</script>

<?php include 'todo_widget.php'; ?>
<script>
/* La sezione attività del progetto: si accende dal tastino e mostra le
   attività collegate a questo progetto, le stesse della pagina to-do. */
(function prTodoSection(){
    var btn = document.getElementById('prTodoBtn');
    var box = document.getElementById('prHeroTodo');
    if (!btn || !box) return;
    var pid = parseInt(btn.dataset.project, 10) || 0;
    var mounted = false;

    function mount(){
        if (mounted || !window.nisamaTodo) return;
        window.nisamaTodo.mount(box, { scope:'project', projectId: pid });
        mounted = true;
    }
    if (btn.classList.contains('is-on')) mount();

    btn.addEventListener('click', function(){
        var next = !btn.classList.contains('is-on');
        btn.classList.toggle('is-on', next);
        box.classList.toggle('pr-hidden', !next);
        if (next) mount();
        var body = new URLSearchParams({ project_id: pid, value: next ? 1 : 0 });
        fetch('progetti.php?ajax=toggle_todo', {
            method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(function(){ toast('Non salvato'); });
    });
})();
</script>
</body>
</html>