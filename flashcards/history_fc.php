<?php



require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = (int)$utente_corrente['id'];
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* Cancella una partita e tutto quello che le appartiene. Una funzione sola per
   l'endpoint AJAX e per il ripiego senza JavaScript: il codice era duplicato, e
   due copie divergono al primo ritocco.
   Ogni DELETE porta "user_id = ?" - anche nelle sottoquery, dove il controllo a
   monte lo renderebbe superfluo: se un giorno quel controllo cambia, queste
   restano al loro posto. */
function fc_cancella_partita(int $delId, int $userId): bool {
    if ($delId <= 0) return false;
    if (!sec_one("SELECT id FROM games WHERE id = ? AND user_id = ?", [$delId, $userId])) return false;

    sec_q("DELETE FROM game_fc_categories
            WHERE game_id = ?
               OR game_id IN (SELECT id FROM games WHERE parent_id = ? AND user_id = ?)",
          [$delId, $delId, $userId]);
    sec_q("DELETE FROM fc_gameplays
            WHERE game_id = ?
               OR game_id IN (SELECT id FROM games WHERE parent_id = ? AND user_id = ?)",
          [$delId, $delId, $userId]);
    sec_q("DELETE FROM games WHERE (id = ? OR parent_id = ?) AND user_id = ?",
          [$delId, $delId, $userId]);
    return true;
}







/* =========================================================================



   ENDPOINT AJAX: eliminazione partita (usato dal tasto rivelato dallo swipe)



   Sicurezza: si può cancellare SOLO una partita che appartiene all'utente



   loggato, mai una di qualcun altro (prima non c'era nessun controllo qui).



   ========================================================================= */



if (isset($_GET['ajax_delete'])) {
    header('Content-Type: application/json');
    /* Token di sessione, origine e limite di frequenza: prima non c'erano, e
       questo endpoint cancella. Con un GET bastava un tag immagine su un altro
       sito per far sparire una partita dal browser di chi e' connesso. */
    sec_guard_write($current_user_id, 'fc_history', 0, 60, 60);

    $ok = fc_cancella_partita(sec_int($_GET['ajax_delete'] ?? 0, 1), $current_user_id);
    echo json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE);
    exit();
}



/* Fallback senza JavaScript (link diretto, compatibilità) — stesso controllo di proprietà */

if (isset($_GET['delete_id'])) {
    sec_guard_write($current_user_id, 'fc_history', 0, 60, 60);
    fc_cancella_partita(sec_int($_GET['delete_id'] ?? 0, 1), $current_user_id);
    header("Location: history_fc.php");
    exit();
}







/* =========================================================================



   Utente (prima qui c'era l'elenco di TUTTI gli utenti per il menu a



   tendina: ora l'utente è sempre e solo quello loggato, niente più scelta)



   ========================================================================= */



$users = [['id' => $utente_corrente['id'], 'username' => $utente_corrente['username']]];



$selected_user_id = $utente_corrente['id'];







/* =========================================================================



   Recupero partite di un utente (partite madri + retry), con conteggio



   "pagine giocate" calcolato in una singola query per evitare N+1



   ========================================================================= */



function fc_build_rows($uid) {
    $uid = intval($uid);
    $games = [];
    if ($uid > 0) {
        // LEFT JOIN e GROUP_CONCAT per unire i nomi delle materie in una riga sola
        $games = sec_all("SELECT g.*, GROUP_CONCAT(fc.name SEPARATOR ', ') AS dynamic_category_name
                FROM games g
                LEFT JOIN game_fc_categories gfc ON g.id = gfc.game_id
                LEFT JOIN fc_categories fc ON gfc.fc_category_id = fc.id
                WHERE g.user_id = ? AND g.type LIKE '%fc%'
                GROUP BY g.id
                ORDER BY g.id DESC", [$uid]);
    }

    if (empty($games)) return [];



    $ids = [];

    foreach ($games as $g) { $ids[] = intval($g['id']); }

        $ph = implode(',', array_fill(0, count($ids), '?'));
    $played_map = [];
    foreach (sec_all("SELECT game_id, COUNT(DISTINCT position) AS c FROM fc_gameplays
                      WHERE game_id IN ($ph) GROUP BY game_id", $ids) as $r) {
        $played_map[(int)$r['game_id']] = (int)$r['c'];
    }



    $parents = [];

    $children_by_parent = [];

    foreach ($games as $g) {

        $pid = intval($g['parent_id'] ?? 0);

        if ($pid > 0) { $children_by_parent[$pid][] = $g; }

        else { $parents[] = $g; }

    }

    foreach ($children_by_parent as &$arr) {

        usort($arr, function ($a, $b) { return intval($a['id']) <=> intval($b['id']); });

    }

    unset($arr);



    $to_row = function ($g) use ($played_map) {

        $id = intval($g['id']);

        $ts = strtotime((string)$g['game_date']);

        $played = isset($played_map[$id]) ? $played_map[$id] : 0;

        $total = intval($g['total_father_words']) > 0 ? intval($g['total_father_words']) : max($played, 1);

        $type = (string)$g['type'];

        // Utilizziamo l'alias estratto dinamicamente dalla JOIN
        $cat = (isset($g['dynamic_category_name']) && $g['dynamic_category_name'] !== null && $g['dynamic_category_name'] !== '') ? (string)$g['dynamic_category_name'] : T('fc.materia');

        return [

            'id'       => $id,

            'date'     => $ts ? date('d/m/Y', $ts) : '--',

            'time'     => $ts ? date('H:i', $ts) : '',

            'dateISO'  => $ts ? date('Y-m-d', $ts) : '',

            'category' => $cat,

            'learnt'   => (strpos($type, '_L') !== false),

            'played'   => $played,

            'total'    => $total,

            'score'    => round(floatval($g['score']), 2),

        ];

    };







    $rows = [];

    foreach ($parents as $p) {

        $row = $to_row($p);

        $pid = intval($p['id']);

        $kids = [];

        if (isset($children_by_parent[$pid])) {

            foreach ($children_by_parent[$pid] as $c) { $kids[] = $to_row($c); }

        }

        $row['children'] = $kids;

        $rows[] = $row;

    }

    return $rows;



}







/* =========================================================================



   ENDPOINT AJAX: ricarica le partite quando si cambia utente dal menu



   ========================================================================= */



if (isset($_GET['ajax_list'])) {



    header('Content-Type: application/json');



    $ajax_uid = $utente_corrente['id']; // sicurezza: mai fidarsi dello user_id passato dal client



    echo json_encode(['rows' => fc_build_rows($ajax_uid), 'user_id' => $ajax_uid], JSON_UNESCAPED_UNICODE);



    exit();



}







$rows = fc_build_rows($selected_user_id);



?>



<!DOCTYPE html>



<html lang="it">



<head>



<meta charset="UTF-8">



<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">



<meta name="apple-mobile-web-app-capable" content="yes">



<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">



<meta name="format-detection" content="telephone=no">



<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">



<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">



<title><?php te('fc.his_titolo'); ?></title>



<link rel="stylesheet" href="<?php echo PWA_BASE; ?>history_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/history_fc.css') ?: 1; ?>">



</head>



<body>



<?php include 'sidebar.html'; ?>







<div class="fc-history">



    <header class="fh-header">



        <h1 class="fh-title"><?php te('fc.his_storico'); ?></h1>



        <div class="fh-toolbar">



            <button type="button" class="fh-user-pill" id="userPillBtn" disabled style="opacity:1;cursor:default;">



                <span class="fh-user-avatar" id="userAvatar">?</span>



                <span class="fh-user-name" id="userPillName"><?php te('fc.his_seleziona_utente'); ?></span>



                <span class="fh-user-count" id="userCount"></span>



            </button>



            <button type="button" class="fh-icon-btn" id="filterBtn" aria-label="<?php te('fc.his_filtri'); ?>">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>



                <span class="fh-badge" id="filterBadge" hidden>0</span>



            </button>



        </div>



        <div class="fh-chips" id="chipsRow"></div>



    </header>







    <a href="categories_game_fc.php" class="fh-new-btn">



        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>



        <?php te('fc.his_nuova_partita'); ?>



    </a>







    <div class="fh-list" id="listContainer"></div>



    <div class="fh-empty" id="emptyState" hidden></div>



</div>







<!-- SHEET: selezione utente -->



<div class="fh-sheet-overlay" id="userSheetOverlay">



    <div class="fh-sheet">



        <div class="fh-sheet-handle"></div>



        <div class="fh-sheet-head"><h3><?php te('fc.his_seleziona_utente'); ?></h3><button type="button" class="fh-sheet-done" id="userSheetDone"><?php te('fc.his_fine'); ?></button></div>



        <input type="text" class="fh-user-search" id="userSearch" placeholder="<?php te('fc.his_cerca_utente'); ?>">



        <div id="userList"></div>



    </div>



</div>







<!-- SHEET: filtri -->



<div class="fh-sheet-overlay" id="filterSheetOverlay">



    <div class="fh-sheet">



        <div class="fh-sheet-handle"></div>



        <div class="fh-sheet-head"><h3><?php te('fc.his_filtri'); ?></h3><button type="button" class="fh-sheet-done" id="filterSheetDone"><?php te('fc.his_fine'); ?></button></div>







        <div class="fh-filter-field">



            <label><?php te('fc.his_materia_def'); ?></label>



            <?php



                $cs_id = 'historyFilter';



                $cs_placeholder = T('fc.cs_tutte_materie');



                $cs_show_all_option = true;



                $cs_all_option_label = T('fc.cs_tutte_materie');



                $cs_allow_create = false;

                include 'category_selector.php';



            ?>



        </div>







        <div class="fh-filter-field">



            <label><?php te('fc.his_stato'); ?></label>



            <div class="fh-segmented" id="filterStatus">



                <button type="button" data-v="all" class="active"><?php te('fc.his_tutti'); ?></button>



                <button type="button" data-v="new">Da imparare</button>



                <button type="button" data-v="learnt"><?php te('fc.his_imparato'); ?></button>



            </div>



        </div>







        <div class="fh-filter-field">



            <label><?php te('fc.his_completamento'); ?></label>



            <div class="fh-segmented" id="filterProgress">



                <button type="button" data-v="all" class="active"><?php te('fc.his_tutti'); ?></button>



                <button type="button" data-v="done"><?php te('fc.his_completate'); ?></button>



                <button type="button" data-v="inprogress"><?php te('fc.his_in_corso'); ?></button>



            </div>



        </div>







        <div class="fh-filter-field">



            <label><?php te('fc.his_periodo'); ?></label>



            <div class="fh-date-range">



                <input type="date" id="filterDateFrom">



                <span>–</span>



                <input type="date" id="filterDateTo">



            </div>



        </div>







        <div class="fh-filter-field">



            <div class="fh-range-row"><label><?php te('fc.his_punteggio_min'); ?></label><span class="fh-range-val" id="scoreVal">0%</span></div>



            <input type="range" id="filterScore" min="0" max="100" step="5" value="0">



        </div>







        <button type="button" class="fh-sheet-reset" id="resetFiltersBtn"><?php te('fc.his_reimposta'); ?></button>



    </div>



</div>







<!-- ALERT: conferma eliminazione -->



<div class="fh-alert-overlay" id="deleteAlertOverlay">



    <div class="fh-alert">



        <div class="fh-alert-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></div>



        <h4><?php te('fc.his_eliminare'); ?></h4>



        <p><?php te('fc.his_eliminare_txt'); ?></p>



        <div class="fh-alert-actions">



            <button type="button" class="fh-alert-btn" id="cancelDeleteBtn"><?php te('comune.annulla'); ?></button>



            <button type="button" class="fh-alert-btn destructive" id="confirmDeleteBtn"><?php te('comune.elimina'); ?></button>



        </div>



    </div>



</div>







<div class="fh-toast" id="toast"></div>







<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>



const FC_USERS = <?php echo json_encode($users, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;



const FC_INITIAL_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;



const FC_SELECTED_USER = <?php echo json_encode($selected_user_id); ?>;



</script>



<script>



(function(){



    'use strict';



    var SPRING = 'cubic-bezier(.32,.72,0,1)';



    var SNAP = 'cubic-bezier(.24,1.3,.35,1)';



    var OPEN_X = 88;







    var state = {



        users: FC_USERS || [],



        userId: FC_SELECTED_USER || 0,



        rows: FC_INITIAL_ROWS || [],



        filters: { category: 'all', status: 'all', progress: 'all', dateFrom: '', dateTo: '', minScore: 0 }



    };







    var el = {};



    ['userPillBtn', 'userAvatar', 'userPillName', 'userCount', 'filterBtn', 'filterBadge', 'chipsRow',



     'listContainer', 'emptyState', 'userSheetOverlay', 'userSheetDone', 'userSearch', 'userList',



     'filterSheetOverlay', 'filterSheetDone', 'filterStatus', 'filterProgress',



     'filterDateFrom', 'filterDateTo', 'filterScore', 'scoreVal', 'resetFiltersBtn',



     'deleteAlertOverlay', 'cancelDeleteBtn', 'confirmDeleteBtn', 'toast'



    ].forEach(function (id) { el[id] = document.getElementById(id); });







    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }



    function escapeHtml(s) {



        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {



            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];



        });



    }



    function fmtPct(n) { return (Math.round(n * 100) / 100).toFixed(2) + '%'; }



    function initials(name) {



        name = (name || '').trim();



        if (!name) return '?';



        var parts = name.split(/\s+/);



        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();



        return (parts[0][0] + parts[1][0]).toUpperCase();



    }







    /* ============================== TOAST ============================== */



    var toastTimer = null;



    function showToast(msg, ok) {



        el.toast.innerHTML = (ok !== false



            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'



            : '') + '<span>' + escapeHtml(msg) + '</span>';



        el.toast.classList.add('show');



        clearTimeout(toastTimer);



        toastTimer = setTimeout(function () { el.toast.classList.remove('show'); }, 2600);



    }







    /* ============================== SHEETS ============================== */



    function openSheet(overlay) { overlay.classList.add('open'); }



    function closeSheet(overlay) { overlay.classList.remove('open'); }







    el.userPillBtn.addEventListener('click', function () {



        renderUserList();



        openSheet(el.userSheetOverlay);



    });



    el.userSheetDone.addEventListener('click', function () { closeSheet(el.userSheetOverlay); });



    el.userSheetOverlay.addEventListener('click', function (e) { if (e.target === el.userSheetOverlay) closeSheet(el.userSheetOverlay); });







    el.filterBtn.addEventListener('click', function () { openSheet(el.filterSheetOverlay); });



    el.filterSheetDone.addEventListener('click', function () { closeSheet(el.filterSheetOverlay); });



    el.filterSheetOverlay.addEventListener('click', function (e) { if (e.target === el.filterSheetOverlay) closeSheet(el.filterSheetOverlay); });







    el.userSearch.addEventListener('input', function () { renderUserList(el.userSearch.value); });







    /* ============================== SELETTORE UTENTE ============================== */



    function renderUserList(filterText) {



        var q = (filterText || '').trim().toLowerCase();



        var list = state.users.filter(function (u) { return !q || (u.username || '').toLowerCase().indexOf(q) > -1; });



        if (!list.length) {



            el.userList.innerHTML = '<p style="text-align:center;color:var(--text-2);font-size:14px;padding:20px 0;">' + T('fc.his_nessun_utente') + '</p>';



            return;



        }



        el.userList.innerHTML = list.map(function (u) {



            var sel = (u.id === state.userId);



            return '<div class="fh-user-row' + (sel ? ' selected' : '') + '" data-id="' + u.id + '">' +



                '<span class="u-avatar">' + escapeHtml(initials(u.username)) + '</span>' +



                '<span class="u-name">' + escapeHtml(u.username || (T('fc.his_utente_n') + u.id)) + '</span>' +



                '<svg class="u-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +



            '</div>';



        }).join('');



    }



    el.userList.addEventListener('click', function (e) {



        var row = e.target.closest('.fh-user-row');



        if (!row) return;



        var id = parseInt(row.getAttribute('data-id'), 10);



        closeSheet(el.userSheetOverlay);



        if (id === state.userId) return;



        selectUser(id);



    });







    function updateUserPillUI() {



        var u = null;



        for (var i = 0; i < state.users.length; i++) { if (state.users[i].id === state.userId) { u = state.users[i]; break; } }



        var name = u ? (u.username || (T('fc.his_utente_n') + u.id)) : 'Seleziona utente';



        el.userPillName.textContent = name;



        el.userAvatar.textContent = initials(name);



        el.userCount.textContent = state.rows.length ? T(state.rows.length === 1 ? 'fc.his_n_partita' : 'fc.his_n_partite', {n: state.rows.length}) : '';



    }







    function selectUser(id) {



        state.userId = id;



        updateUserPillUI();



        showSkeleton();



        fetch('history_fc.php?ajax_list=1&user_id=' + encodeURIComponent(id))



            .then(function (r) { return r.json(); })



            .then(function (data) {



                state.rows = data.rows || [];



                updateUserPillUI();



                applyFiltersAndRender();



                try { history.replaceState(null, '', 'history_fc.php?user_id=' + id); } catch (e) {}



            })



            .catch(function () {



                showToast(T('fc.his_errore_caricamento'), false);



                renderRows([]);



            });



    }







    /* ============================== FILTRI ============================== */



    var CS_HISTORY_MODAL_ID = 'cs-modal-historyFilter';







    document.addEventListener('categoryselected', function (e) {



        if (!e.detail || e.detail.uid !== 'historyFilter') return;



        state.filters.category = (String(e.detail.id) === '0') ? 'all' : e.detail.name;



        applyFiltersAndRender();



    });







    function setSegmented(container, value) {



        Array.prototype.forEach.call(container.querySelectorAll('button'), function (b) {



            b.classList.toggle('active', b.getAttribute('data-v') === value);



        });



    }



    el.filterStatus.addEventListener('click', function (e) {



        var b = e.target.closest('button'); if (!b) return;



        state.filters.status = b.getAttribute('data-v');



        setSegmented(el.filterStatus, state.filters.status);



        applyFiltersAndRender();



    });



    el.filterProgress.addEventListener('click', function (e) {



        var b = e.target.closest('button'); if (!b) return;



        state.filters.progress = b.getAttribute('data-v');



        setSegmented(el.filterProgress, state.filters.progress);



        applyFiltersAndRender();



    });



    el.filterDateFrom.addEventListener('change', function () {



        state.filters.dateFrom = el.filterDateFrom.value;



        applyFiltersAndRender();



    });



    el.filterDateTo.addEventListener('change', function () {



        state.filters.dateTo = el.filterDateTo.value;



        applyFiltersAndRender();



    });



    el.filterScore.addEventListener('input', function () {



        state.filters.minScore = parseInt(el.filterScore.value, 10) || 0;



        el.scoreVal.textContent = state.filters.minScore + '%';



        applyFiltersAndRender();



    });



    el.resetFiltersBtn.addEventListener('click', function () {



        state.filters = { category: 'all', status: 'all', progress: 'all', dateFrom: '', dateTo: '', minScore: 0 };



        resetCategorySelector();



        setSegmented(el.filterStatus, 'all');



        setSegmented(el.filterProgress, 'all');



        el.filterDateFrom.value = '';



        el.filterDateTo.value = '';



        el.filterScore.value = 0;



        el.scoreVal.textContent = '0%';



        applyFiltersAndRender();



    });







    function resetCategorySelector() {



        if (window.csSetValue) window.csSetValue(CS_HISTORY_MODAL_ID, 0, T('fc.cs_tutte_materie'));



    }







    function isDefaultFilters() {



        var f = state.filters;



        return f.category === 'all' && f.status === 'all' && f.progress === 'all' && !f.dateFrom && !f.dateTo && f.minScore === 0;



    }







    function matchFilters(row) {



        var f = state.filters;



        if (f.category !== 'all' && row.category !== f.category) return false;



        if (f.status === 'learnt' && !row.learnt) return false;



        if (f.status === 'new' && row.learnt) return false;



        if (f.progress === 'done' && row.played < row.total) return false;



        if (f.progress === 'inprogress' && row.played >= row.total) return false;



        if (f.dateFrom && row.dateISO && row.dateISO < f.dateFrom) return false;



        if (f.dateTo && row.dateISO && row.dateISO > f.dateTo) return false;



        if (row.score < f.minScore) return false;



        return true;



    }







    function updateFilterBadge() {



        var f = state.filters;



        var n = 0;



        if (f.category !== 'all') n++;



        if (f.status !== 'all') n++;



        if (f.progress !== 'all') n++;



        if (f.dateFrom || f.dateTo) n++;



        if (f.minScore > 0) n++;



        if (n > 0) { el.filterBadge.hidden = false; el.filterBadge.textContent = n; el.filterBtn.classList.add('has-filters'); }



        else { el.filterBadge.hidden = true; el.filterBtn.classList.remove('has-filters'); }



        renderChips();



    }







    function renderChips() {



        var f = state.filters;



        var chips = [];



        if (f.category !== 'all') chips.push({ k: 'category', label: f.category });



        if (f.status !== 'all') chips.push({ k: 'status', label: f.status === 'learnt' ? T('fc.his_imparato') : 'Da imparare' });



        if (f.progress !== 'all') chips.push({ k: 'progress', label: f.progress === 'done' ? T('fc.his_completate') : T('fc.js_in_corso') });



        if (f.dateFrom || f.dateTo) chips.push({ k: 'date', label: (f.dateFrom || '...') + ' \u2013 ' + (f.dateTo || '...') });



        if (f.minScore > 0) chips.push({ k: 'score', label: T('fc.his_min') + f.minScore + '%' });







        el.chipsRow.innerHTML = chips.map(function (c) {



            return '<span class="fh-chip" data-k="' + c.k + '">' + escapeHtml(c.label) +



                '<button type="button" aria-label="<?php te('fc.his_rimuovi_filtro'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></span>';



        }).join('');



    }



    el.chipsRow.addEventListener('click', function (e) {



        var btn = e.target.closest('button'); if (!btn) return;



        var chip = btn.closest('.fh-chip');



        var k = chip.getAttribute('data-k');



        if (k === 'category') { state.filters.category = 'all'; resetCategorySelector(); }



        if (k === 'status') { state.filters.status = 'all'; setSegmented(el.filterStatus, 'all'); }



        if (k === 'progress') { state.filters.progress = 'all'; setSegmented(el.filterProgress, 'all'); }



        if (k === 'date') { state.filters.dateFrom = ''; state.filters.dateTo = ''; el.filterDateFrom.value = ''; el.filterDateTo.value = ''; }



        if (k === 'score') { state.filters.minScore = 0; el.filterScore.value = 0; el.scoreVal.textContent = '0%'; }



        applyFiltersAndRender();



    });







    function applyFiltersAndRender() {



        var filtered = state.rows.filter(matchFilters);



        renderRows(filtered);



        updateFilterBadge();



    }







    /* ============================== RENDER LISTA ============================== */



    function showSkeleton() {



        var html = '';



        for (var i = 0; i < 4; i++) html += '<div class="fh-skel" style="animation-delay:' + (i * 90) + 'ms"></div>';



        el.listContainer.innerHTML = html;



        el.emptyState.hidden = true;



    }







    function renderEmpty() {



        var filtersActive = !isDefaultFilters();



        el.listContainer.innerHTML = '';



        el.emptyState.hidden = false;



        el.emptyState.innerHTML = filtersActive



            ? ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>' +



               '<h3>' + T('comune.nessun_risultato') + '</h3><p>' + T('fc.his_nessun_match') + '</p>' +



               '<button type="button" id="emptyResetBtn">' + T('fc.his_reimposta_filtri') + '</button>')



            : ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 4v4M16 4v4"/></svg>' +



               '<h3>' + T('fc.his_nessuna_partita') + '</h3><p>' + T('fc.his_mai_giocato') + '</p>');



        var btn = document.getElementById('emptyResetBtn');



        if (btn) btn.addEventListener('click', function () { el.resetFiltersBtn.click(); });



    }







    function renderRows(rows) {



        if (!rows.length) { renderEmpty(); return; }



        el.emptyState.hidden = true;



        el.listContainer.innerHTML = rows.map(renderItem).join('');



        Array.prototype.forEach.call(el.listContainer.querySelectorAll('.fh-item'), function (item, i) {



            item.style.animationDelay = (Math.min(i, 8) * 40) + 'ms';



        });



    }







    function renderItem(row) {



        var statusClass = row.learnt ? 'ok' : 'new';



        var statusLabel = row.learnt ? T('fc.his_imparato') : 'Da imparare';



        var pct = row.total > 0 ? Math.max(0, Math.min(100, Math.round(row.played / row.total * 100))) : 0;



        var retryHtml = '';



        if (row.children && row.children.length) {



            retryHtml += '<button type="button" class="fh-retry-toggle" data-target="fh-retry-' + row.id + '">' +



                '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>' +



                '<span>' + T(row.children.length === 1 ? 'fc.his_n_retry' : 'fc.his_n_retry_plur', {n: row.children.length}) + '</span>' +



            '</button>' +



            '<div class="fh-retry-list" id="fh-retry-' + row.id + '">' +



                row.children.map(function (c, i) {



                    return '<div class="fh-retry-row" data-id="' + c.id + '">' +



                        '<span class="r-badge">R' + (i + 1) + '</span>' +



                        '<span class="r-date">' + escapeHtml(c.date) + ' &middot; ' + escapeHtml(c.time) + '</span>' +



                        '<span class="r-progress">' + c.played + '/' + c.total + '</span>' +



                        '<span class="r-score">' + fmtPct(c.score) + '</span>' +



                    '</div>';



                }).join('') +



            '</div>';



        }







        return '<div class="fh-item" data-id="' + row.id + '">' +



            '<div class="fh-swipe">' +



                '<button type="button" class="fh-swipe-del" data-id="' + row.id + '" aria-label="Elimina partita">' +



                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>' +



                    '<span><?php te('comune.elimina'); ?></span>' +



                '</button>' +



                '<div class="fh-card" data-id="' + row.id + '">' +



                    '<div class="fh-card-top">' +



                        '<span class="fh-date"><span class="d-day">' + escapeHtml(row.date) + '</span><span class="d-time">' + escapeHtml(row.time) + '</span></span>' +



                        '<span class="fh-status ' + statusClass + '">' + statusLabel + '</span>' +



                    '</div>' +



                    '<div class="fh-category">' + escapeHtml(row.category) + '</div>' +



                    '<div class="fh-card-bottom">' +



                        '<div class="fh-progress"><div class="fh-track"><div class="fh-fill" style="width:' + pct + '%"></div></div><span class="fh-progress-label">' + row.played + '/' + row.total + '</span></div>' +



                        '<span class="fh-score">' + fmtPct(row.score) + '</span>' +



                    '</div>' +



                '</div>' +



            '</div>' +



            retryHtml +



        '</div>';



    }







    /* ============================== SWIPE-TO-DELETE ============================== */



    var sw = null;



    var openItem = null;







    function closeItem(item, animate) {



        var card = item.querySelector('.fh-card');



        card.style.transition = animate ? ('transform .4s ' + SNAP) : 'none';



        card.style.transform = 'translateX(0)';



        item.classList.remove('open');



        var delBtn = item.querySelector('.fh-swipe-del');



        if (delBtn) delBtn.classList.remove('armed');



        if (openItem === item) openItem = null;



    }



    function openItemFn(item) {



        if (openItem && openItem !== item) closeItem(openItem, true);



        var card = item.querySelector('.fh-card');



        card.style.transition = 'transform .4s ' + SNAP;



        card.style.transform = 'translateX(' + OPEN_X + 'px)';



        item.classList.add('open');



        openItem = item;



    }







    el.listContainer.addEventListener('pointerdown', function (e) {



        if (e.pointerType === 'mouse' && e.button !== 0) return;



        var card = e.target.closest('.fh-card');



        if (!card) return;



        var item = card.closest('.fh-item');



        var isOpen = item.classList.contains('open');



        sw = { item: item, card: card, sx: e.clientX, sy: e.clientY, base: isOpen ? OPEN_X : 0, mode: null, moved: false, pos: isOpen ? OPEN_X : 0, pid: e.pointerId, armed: isOpen };



        card.style.transition = 'none';



    });







    el.listContainer.addEventListener('pointermove', function (e) {



        if (!sw || e.pointerId !== sw.pid) return;



        var dx = e.clientX - sw.sx;



        var dy = e.clientY - sw.sy;



        if (sw.mode === null) {



            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;



            sw.mode = (Math.abs(dx) > Math.abs(dy) * 1.15) ? 'h' : 'v';



            if (sw.mode === 'h') {



                try { sw.card.setPointerCapture(sw.pid); } catch (err) {}



                if (openItem && openItem !== sw.item) closeItem(openItem, true);



            } else {



                return;



            }



        }



        if (sw.mode !== 'h') return;



        e.preventDefault();



        sw.moved = true;



        var pos = sw.base + dx;



        if (pos < 0) pos = pos * 0.15;



        else if (pos > OPEN_X) pos = OPEN_X + (pos - OPEN_X) * 0.22;



        sw.pos = pos;



        sw.card.style.transform = 'translateX(' + pos + 'px)';



        var armed = pos >= OPEN_X * 0.55;



        if (armed !== sw.armed) {



            sw.armed = armed;



            buzz(armed ? 10 : 6);



            var delBtn = sw.item.querySelector('.fh-swipe-del');



            if (delBtn) delBtn.classList.toggle('armed', armed);



        }



    }, { passive: false });







    function endSwipe(e) {



        if (!sw || (e.pointerId !== undefined && e.pointerId !== sw.pid)) return;



        var s = sw; sw = null;



        try { s.card.releasePointerCapture(s.pid); } catch (err) {}



        s.card.style.transition = 'transform .4s ' + SNAP;



        if (s.mode === 'h') {



            if (s.pos >= OPEN_X * 0.5) { openItemFn(s.item); }



            else { closeItem(s.item, true); }



        } else if (s.mode === null && !s.moved) {



            if (s.item.classList.contains('open')) closeItem(s.item, true);



            else navigateToGame(s.item.getAttribute('data-id'));



        } else {



            s.card.style.transform = 'translateX(' + (s.item.classList.contains('open') ? OPEN_X : 0) + 'px)';



        }



    }



    el.listContainer.addEventListener('pointerup', endSwipe);



    el.listContainer.addEventListener('pointercancel', endSwipe);







    document.addEventListener('pointerdown', function (e) {



        if (openItem && !openItem.contains(e.target)) closeItem(openItem, true);



    }, true);







    function navigateToGame(id) {



        window.location.href = 'result_fc.php?game_id=' + encodeURIComponent(id);



    }







    /* ============================== CLICK (retry + elimina) ============================== */



    var pendingDeleteId = null;



    el.listContainer.addEventListener('click', function (e) {



        var rr = e.target.closest('.fh-retry-row');



        if (rr) { navigateToGame(rr.getAttribute('data-id')); return; }



        var rt = e.target.closest('.fh-retry-toggle');



        if (rt) { toggleRetry(rt); return; }



        var del = e.target.closest('.fh-swipe-del');



        if (del) {



            pendingDeleteId = del.getAttribute('data-id');



            el.deleteAlertOverlay.classList.add('open');



            return;



        }



    });







    function toggleRetry(btn) {



        var panel = document.getElementById(btn.getAttribute('data-target'));



        if (!panel) return;



        var opening = !btn.classList.contains('expanded');



        btn.classList.toggle('expanded', opening);



        panel.style.maxHeight = opening ? (panel.scrollHeight + 'px') : '0px';



    }







    el.cancelDeleteBtn.addEventListener('click', function () {



        el.deleteAlertOverlay.classList.remove('open');



        if (openItem) closeItem(openItem, true);



        pendingDeleteId = null;



    });



    el.deleteAlertOverlay.addEventListener('click', function (e) {



        if (e.target === el.deleteAlertOverlay) el.cancelDeleteBtn.click();



    });



    el.confirmDeleteBtn.addEventListener('click', function () {



        var id = pendingDeleteId;



        el.deleteAlertOverlay.classList.remove('open');



        pendingDeleteId = null;



        if (id) performDelete(id);



    });







    function performDelete(id) {



        var item = el.listContainer.querySelector('.fh-item[data-id="' + id + '"]');



        fetch('history_fc.php?ajax_delete=' + encodeURIComponent(id))



            .then(function (r) { return r.json(); })



            .then(function (data) {



                if (data && data.success) {



                    removeItemAnimated(item, id);



                    showToast(T('fc.his_partita_eliminata'));



                } else {



                    showToast(T('fc.his_no_elimina'), false);



                    if (item) closeItem(item, true);



                }



            })



            .catch(function () {



                showToast(T('vocab.vi_errore_rete'), false);



                if (item) closeItem(item, true);



            });



    }







    function removeItemAnimated(item, id) {



        state.rows = state.rows.filter(function (r) { return String(r.id) !== String(id); });



        state.rows.forEach(function (r) { if (r.children) r.children = r.children.filter(function (c) { return String(c.id) !== String(id); }); });



        updateUserPillUI();



        updateFilterBadge();







        if (!item) { applyFiltersAndRender(); return; }



        if (openItem === item) openItem = null;



        var h = item.offsetHeight;



        item.style.overflow = 'hidden';



        item.style.height = h + 'px';



        item.style.transition = 'none';



        void item.offsetHeight;



        requestAnimationFrame(function () {



            item.style.transition = 'height .34s ' + SPRING + ', opacity .3s, transform .34s ' + SPRING + ', margin .34s ' + SPRING;



            item.style.opacity = '0';



            item.style.transform = 'translateX(24px)';



            item.style.height = '0px';



            item.style.marginBottom = '0px';



        });



        setTimeout(function () {



            item.remove();



            if (!el.listContainer.children.length) renderEmpty();



        }, 360);



    }







    /* ============================== INIT ============================== */



    function init() {



        updateUserPillUI();



        renderRows(state.rows.filter(matchFilters));



        updateFilterBadge();



    }



    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);



    else init();



})();



</script>



</body>



</html>

