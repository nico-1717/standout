<?php



require_once 'config.php';



require_once 'auth_check.php';



$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);







/* =========================================================================



   ENDPOINT AJAX: eliminazione partita (usato dal tasto rivelato dallo swipe)



   Sicurezza: si può cancellare SOLO una partita che appartiene all'utente



   loggato, mai una di qualcun altro (prima non c'era nessun controllo qui).



   ========================================================================= */



if (isset($_GET['ajax_delete'])) {

    header('Content-Type: application/json');

    $del_id = intval($_GET['ajax_delete']);

    $ok = false;

    if ($del_id > 0) {

        $check = mysqli_query($conn, "SELECT user_id FROM games WHERE id = $del_id");

        $checkRow = $check ? mysqli_fetch_assoc($check) : null;

        if ($checkRow && intval($checkRow['user_id']) === $utente_corrente['id']) {

            mysqli_query($conn, "DELETE FROM game_fc_categories WHERE game_id = $del_id OR game_id IN (SELECT id FROM games WHERE parent_id = $del_id)");

            mysqli_query($conn, "DELETE FROM fc_gameplays WHERE game_id = $del_id OR game_id IN (SELECT id FROM games WHERE parent_id = $del_id)");

            mysqli_query($conn, "DELETE FROM games WHERE id = $del_id OR parent_id = $del_id");

            $ok = true;

        }

    }

    echo json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE);

    exit();

}



/* Fallback senza JavaScript (link diretto, compatibilità) — stesso controllo di proprietà */

if (isset($_GET['delete_id'])) {

    $del_id = intval($_GET['delete_id']);

    $check = mysqli_query($conn, "SELECT user_id FROM games WHERE id = $del_id");

    $checkRow = $check ? mysqli_fetch_assoc($check) : null;

    if ($checkRow && intval($checkRow['user_id']) === $utente_corrente['id']) {

        mysqli_query($conn, "DELETE FROM game_fc_categories WHERE game_id = $del_id OR game_id IN (SELECT id FROM games WHERE parent_id = $del_id)");

        mysqli_query($conn, "DELETE FROM fc_gameplays WHERE game_id = $del_id OR game_id IN (SELECT id FROM games WHERE parent_id = $del_id)");

        mysqli_query($conn, "DELETE FROM games WHERE id = $del_id OR parent_id = $del_id");

    }

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



function fc_build_rows($conn, $uid) {

    $uid = intval($uid);

    $games = [];

    if ($uid > 0) {

        // Eseguiamo una LEFT JOIN e raggruppiamo per id partita unendo i nomi delle materie con GROUP_CONCAT
        $sql = "SELECT g.*, GROUP_CONCAT(fc.name SEPARATOR ', ') AS dynamic_category_name 
                FROM games g 
                LEFT JOIN game_fc_categories gfc ON g.id = gfc.game_id 
                LEFT JOIN fc_categories fc ON gfc.fc_category_id = fc.id 
                WHERE g.user_id = $uid AND g.type LIKE '%fc%' 
                GROUP BY g.id
                ORDER BY g.id DESC";
        $q = mysqli_query($conn, $sql);

        if ($q) {

            while ($g = mysqli_fetch_assoc($q)) { $games[] = $g; }

        }

    }

    if (empty($games)) return [];



    $ids = [];

    foreach ($games as $g) { $ids[] = intval($g['id']); }

    $ids_list = implode(',', $ids);



    $played_map = [];

    // Aggiornato con la tabella fc_gameplays e la colonna game_id
    $q_played = mysqli_query($conn, "SELECT game_id, COUNT(DISTINCT position) as c FROM fc_gameplays WHERE game_id IN ($ids_list) GROUP BY game_id");

    if ($q_played) {

        while ($r = mysqli_fetch_assoc($q_played)) { $played_map[intval($r['game_id'])] = intval($r['c']); }

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
        $cat = (isset($g['dynamic_category_name']) && $g['dynamic_category_name'] !== null && $g['dynamic_category_name'] !== '') ? (string)$g['dynamic_category_name'] : 'Materia';

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



    echo json_encode(['rows' => fc_build_rows($conn, $ajax_uid), 'user_id' => $ajax_uid], JSON_UNESCAPED_UNICODE);



    exit();



}







$rows = fc_build_rows($conn, $selected_user_id);



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



<title>Storico Materie - Flashcards</title>



<style>



/* ================================================================



   DESIGN SYSTEM — stessi token di game_fc.php (iOS nativo, light+dark)



   ================================================================ */



:root{



    color-scheme: light dark;



    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;



    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;



    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);



    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;



    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);



    --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12);



    --header-glass:rgba(249,249,251,.82);



    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);



    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);



    --pill-hl:inset 0 0 0 .5px var(--hairline);



    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);



    --r-chip:100px;



    --spring:cubic-bezier(.32,.72,0,1);



    --safe-top:env(safe-area-inset-top, 0px);



    --safe-bottom:env(safe-area-inset-bottom, 0px);



}



@media (prefers-color-scheme: dark){



    :root{



        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;



        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;



        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);



        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;



        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);



        --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18);



        --header-glass:rgba(16,16,18,.78);



        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);



        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);



        --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);



        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);



    }



}



*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }



html{ -webkit-text-size-adjust:100%; }



body{



    margin:0; background:var(--bg); color:var(--text);



    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;



    font-size:16px; line-height:1.4;



    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;



}



button, input, select{ font-family:inherit; }



:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }







/* ================================================================



   HEADER



   ================================================================ */



.fc-history{ max-width:640px; margin:0 auto; padding-bottom:calc(50px + var(--safe-bottom)); }



.fh-header{



    position:sticky; top:0; z-index:40;



    padding:calc(var(--safe-top) + 16px) 16px 12px;



    background:var(--header-glass);



    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);



    border-bottom:.5px solid var(--hairline);



}



.fh-title{ font-size:30px; font-weight:800; letter-spacing:-.03em; margin:0 0 14px; }



.fh-toolbar{ display:flex; align-items:center; gap:10px; }







.fh-user-pill{



    flex:1; min-width:0; display:flex; align-items:center; gap:9px;



    padding:7px 14px 7px 7px; border:none; border-radius:var(--r-chip); cursor:pointer;



    background:var(--surface); color:var(--text);



    box-shadow:var(--pill-hl), var(--raise-sm);



    transition:transform .15s var(--spring), box-shadow .2s var(--spring);



}



.fh-user-pill:active{ transform:scale(.97); box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08); }



.fh-user-avatar{



    flex-shrink:0; width:32px; height:32px; border-radius:50%;



    display:flex; align-items:center; justify-content:center;



    font-size:13px; font-weight:800; color:#fff;



    background:linear-gradient(160deg, var(--blue), color-mix(in srgb, var(--blue), #000 25%));



}



.fh-user-name{ flex:1; min-width:0; text-align:left; font-size:15px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }



.fh-user-count{ flex-shrink:0; font-size:12px; font-weight:600; color:var(--text-2); }



.fh-user-pill .chev{ flex-shrink:0; width:12px; height:12px; color:var(--text-3); }







.fh-icon-btn{



    position:relative; flex-shrink:0; width:42px; height:42px; border-radius:50%; border:none; cursor:pointer; padding:0;



    background:var(--surface); color:var(--text);



    display:flex; align-items:center; justify-content:center;



    box-shadow:var(--pill-hl), var(--raise-sm);



    transition:transform .15s var(--spring), box-shadow .2s var(--spring), color .2s;



}



.fh-icon-btn:active{ transform:scale(.88); box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }



.fh-icon-btn svg{ width:19px; height:19px; }



.fh-icon-btn.has-filters{ color:var(--blue); }



.fh-badge{



    position:absolute; top:-2px; right:-2px; min-width:16px; height:16px; padding:0 4px; border-radius:99px;



    background:var(--red); color:#fff; font-size:10px; font-weight:800;



    display:flex; align-items:center; justify-content:center;



    box-shadow:0 0 0 2px var(--surface);



}







.fh-chips{ display:flex; flex-wrap:wrap; gap:7px; padding-top:12px; }



.fh-chip{



    display:inline-flex; align-items:center; gap:6px;



    padding:6px 6px 6px 12px; border-radius:99px;



    background:var(--blue-soft); color:var(--blue);



    font-size:12.5px; font-weight:700;



    animation:chipIn .3s var(--spring) both;



}



.fh-chip button{ display:flex; align-items:center; justify-content:center; width:17px; height:17px; border-radius:50%; border:none; background:rgba(10,132,255,.18); color:inherit; cursor:pointer; padding:0; }



.fh-chip button svg{ width:9px; height:9px; }



@keyframes chipIn{ from{ opacity:0; transform:scale(.8); } }







/* ================================================================



   NUOVA PARTITA — bottone hero



   ================================================================ */



.fh-new-btn{



    display:flex; align-items:center; justify-content:center; gap:9px;



    margin:18px 16px 4px; padding:16px; border:none; border-radius:18px; cursor:pointer;



    font-size:16.5px; font-weight:800; letter-spacing:-.01em; color:#fff; text-decoration:none;



    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 18%), var(--blue) 55%, color-mix(in srgb, var(--blue), #000 8%));



    box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 14px 30px -10px rgba(10,132,255,.5);



    transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s;



    animation:riseIn .5s var(--spring) both;



}



.fh-new-btn:active{ transform:translateY(1.5px) scale(.985); filter:brightness(.95); }



.fh-new-btn svg{ width:19px; height:19px; }



@keyframes riseIn{ from{ opacity:0; transform:translateY(10px) scale(.97); } }







/* ================================================================



   LISTA PARTITE



   ================================================================ */



.fh-list{ padding:10px 16px 8px; }



.fh-item{ margin-bottom:12px; animation:cardIn .45s var(--spring) both; }



@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.98); } }







.fh-swipe{ position:relative; border-radius:20px; overflow:hidden; }



.fh-swipe-del{



    position:absolute; inset:0 auto 0 0; width:88px; border:none; cursor:pointer; padding:0;



    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px;



    background:linear-gradient(160deg, var(--red), color-mix(in srgb, var(--red), #000 22%));



    color:#fff;



}



.fh-swipe-del svg{ width:20px; height:20px; transition:transform .25s var(--spring); }



.fh-swipe-del.armed svg{ transform:scale(1.18); }



.fh-swipe-del span{ font-size:11px; font-weight:800; letter-spacing:.01em; }







.fh-card{



    position:relative; z-index:2; background:var(--surface); border-radius:20px;



    padding:15px 16px 14px; border:.5px solid var(--hairline); box-shadow:var(--shadow-card);



    touch-action:pan-y; -webkit-user-select:none; user-select:none; cursor:pointer;



}



.fh-card-top{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:9px; }



.fh-date{ display:flex; align-items:baseline; gap:6px; }



.fh-date .d-day{ font-size:15px; font-weight:800; color:var(--text); }



.fh-date .d-time{ font-size:12.5px; font-weight:600; color:var(--text-2); }







.fh-status{



    display:inline-flex; align-items:center; padding:4px 10px; border-radius:var(--r-chip);



    font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; flex-shrink:0;



}



.fh-status.ok{ background:var(--orange-soft); color:var(--orange); }



.fh-status.new{ background:var(--green-soft); color:var(--green); }







.fh-category{



    font-size:17px; font-weight:700; letter-spacing:-.01em; color:var(--text); margin-bottom:13px;



    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;



}







.fh-card-bottom{ display:flex; align-items:center; gap:12px; }



.fh-progress{ flex:1; display:flex; align-items:center; gap:9px; min-width:0; }



.fh-track{ flex:1; height:5px; border-radius:99px; background:var(--hairline); overflow:hidden; }



.fh-fill{ height:100%; border-radius:99px; background:var(--blue); transition:width .6s var(--spring); }



.fh-progress-label{ flex-shrink:0; font-size:12px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; }



.fh-score{



    flex-shrink:0; font-size:13.5px; font-weight:800; color:var(--text); font-variant-numeric:tabular-nums;



    padding:5px 10px; border-radius:10px; background:var(--surface-2);



}







.fh-retry-toggle{



    display:flex; align-items:center; gap:6px; width:100%; margin-top:4px;



    padding:9px 4px 2px; border:none; background:none; cursor:pointer;



    font-size:12.5px; font-weight:700; color:var(--blue);



}



.fh-retry-toggle .chev{ width:12px; height:12px; transition:transform .3s var(--spring); }



.fh-retry-toggle.expanded .chev{ transform:rotate(90deg); }







.fh-retry-list{ max-height:0; overflow:hidden; transition:max-height .38s var(--spring); }



.fh-retry-row{



    display:flex; align-items:center; gap:10px; margin:8px 2px 0 2px; padding:10px 12px;



    background:var(--surface-2); border-radius:13px; cursor:pointer;



    transition:transform .15s var(--spring), background .2s;



}



.fh-retry-row:active{ transform:scale(.98); background:var(--hairline); }



.fh-retry-row .r-badge{ flex-shrink:0; font-size:10px; font-weight:800; color:var(--purple); background:var(--purple-soft); padding:3px 7px; border-radius:8px; }



.fh-retry-row .r-date{ flex:1; min-width:0; font-size:12.5px; font-weight:600; color:var(--text-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }



.fh-retry-row .r-progress{ flex-shrink:0; font-size:12px; font-weight:700; color:var(--text-2); }



.fh-retry-row .r-score{ flex-shrink:0; font-size:12.5px; font-weight:800; color:var(--text); }







/* Skeleton di caricamento */



.fh-skel{ height:106px; border-radius:20px; margin-bottom:12px; border:.5px solid var(--hairline);



    background:linear-gradient(100deg, var(--surface) 25%, var(--surface-2) 45%, var(--surface) 65%);



    background-size:250% 100%; animation:skelShine 1.3s ease-in-out infinite; }



@keyframes skelShine{ 0%{ background-position:180% 0; } 100%{ background-position:-80% 0; } }







/* Stato vuoto */



.fh-empty{ text-align:center; padding:64px 30px 40px; color:var(--text-2); }



.fh-empty svg{ width:52px; height:52px; color:var(--text-3); margin-bottom:14px; }



.fh-empty h3{ font-size:17px; font-weight:700; color:var(--text); margin:0 0 6px; }



.fh-empty p{ font-size:14px; margin:0 0 18px; line-height:1.4; }



.fh-empty button{ border:none; background:var(--blue-soft); color:var(--blue); font-weight:700; font-size:14px; padding:10px 18px; border-radius:99px; cursor:pointer; }







/* ================================================================



   SHEET (menu utente / filtri) — foglio iOS dal basso



   ================================================================ */



.fh-sheet-overlay{



    position:fixed; inset:0; z-index:2000; display:flex; align-items:flex-end; justify-content:center;



    background:rgba(0,0,0,0); pointer-events:none; transition:background .3s var(--spring);



}



.fh-sheet-overlay.open{ background:rgba(0,0,0,.42); pointer-events:auto; -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px); }



.fh-sheet{



    width:100%; max-width:600px; max-height:82vh; overflow-y:auto; -webkit-overflow-scrolling:touch;



    background:var(--surface); color:var(--text); border-radius:24px 24px 0 0;



    padding:10px 20px calc(24px + var(--safe-bottom));



    box-shadow:0 -12px 50px rgba(0,0,0,.3);



    transform:translateY(100%); transition:transform .42s var(--spring);



}



.fh-sheet-overlay.open .fh-sheet{ transform:translateY(0); }



.fh-sheet-handle{ width:36px; height:5px; border-radius:99px; background:var(--hairline-2); margin:6px auto 14px; }



.fh-sheet-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }



.fh-sheet-head h3{ font-size:18px; font-weight:800; margin:0; }



.fh-sheet-done{ border:none; background:none; color:var(--blue); font-size:15.5px; font-weight:700; cursor:pointer; padding:4px 6px; }







.fh-user-search{



    width:100%; padding:11px 14px; margin-bottom:8px; border:none; border-radius:13px;



    background:var(--surface-2); color:var(--text); font-size:15px;



    box-shadow:inset 0 0 0 .5px var(--hairline);



}



.fh-user-row{



    display:flex; align-items:center; gap:12px; padding:11px 6px; border-bottom:.5px solid var(--hairline);



    cursor:pointer; transition:background .15s;



}



.fh-user-row:last-child{ border-bottom:none; }



.fh-user-row:active{ background:var(--surface-2); }



.fh-user-row .u-avatar{



    width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center;



    font-size:14px; font-weight:800; color:#fff; flex-shrink:0;



    background:linear-gradient(160deg, var(--purple), color-mix(in srgb, var(--purple), #000 25%));



}



.fh-user-row .u-name{ flex:1; font-size:15.5px; font-weight:600; }



.fh-user-row .u-check{ width:20px; height:20px; color:var(--blue); flex-shrink:0; opacity:0; transform:scale(.6); transition:all .25s var(--spring); }



.fh-user-row.selected .u-check{ opacity:1; transform:scale(1); }







.fh-filter-field{ margin-bottom:20px; }



.fh-filter-field label{ display:block; font-size:12.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:var(--text-2); margin-bottom:9px; }



.fh-segmented{ display:flex; background:var(--surface-2); border-radius:12px; padding:3px; gap:2px; }



.fh-segmented button{ flex:1; border:none; background:none; padding:9px 4px; border-radius:9px; font-size:13px; font-weight:700; color:var(--text-2); cursor:pointer; transition:all .25s var(--spring); }



.fh-segmented button.active{ background:var(--surface); color:var(--text); box-shadow:0 1px 3px rgba(0,0,0,.12); }



.fh-select{



    width:100%; padding:12px 36px 12px 14px; border:none; border-radius:13px; appearance:none; -webkit-appearance:none;



    background-color:var(--surface-2); color:var(--text); font-size:15px; font-weight:600;



    background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");



    background-repeat:no-repeat; background-position:right 14px center;



}



.fh-date-range{ display:flex; align-items:center; gap:10px; }



.fh-date-range input[type="date"]{ flex:1; padding:11px 12px; border:none; border-radius:13px; background:var(--surface-2); color:var(--text); font-size:14.5px; }



.fh-date-range span{ color:var(--text-3); font-weight:700; }



.fh-range-row{ display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }



.fh-range-row label{ margin:0; }



.fh-range-val{ font-size:14px; font-weight:800; color:var(--blue); }



input[type="range"]{ width:100%; -webkit-appearance:none; appearance:none; height:5px; border-radius:99px; background:var(--hairline); }



input[type="range"]::-webkit-slider-thumb{ -webkit-appearance:none; width:24px; height:24px; border-radius:50%; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.3), 0 0 0 1px var(--hairline); cursor:pointer; }



input[type="range"]::-moz-range-thumb{ width:24px; height:24px; border:none; border-radius:50%; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.3), 0 0 0 1px var(--hairline); cursor:pointer; }







.fh-sheet-reset{ display:block; width:100%; text-align:center; margin-top:4px; padding:12px; border:none; background:none; color:var(--red); font-size:14.5px; font-weight:700; cursor:pointer; }



.fh-sheet-reset:active{ opacity:.6; }







/* ================================================================



   ALERT — conferma eliminazione, stile iOS



   ================================================================ */



.fh-alert-overlay{



    position:fixed; inset:0; z-index:3000; display:flex; align-items:center; justify-content:center; padding:32px;



    background:rgba(0,0,0,0); pointer-events:none; transition:background .3s;



}



.fh-alert-overlay.open{ background:rgba(0,0,0,.4); pointer-events:auto; -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); }



.fh-alert{



    width:100%; max-width:290px; background:var(--surface); border-radius:20px; overflow:hidden; text-align:center;



    transform:scale(.82); opacity:0; transition:transform .32s var(--spring), opacity .25s;



    box-shadow:0 20px 60px rgba(0,0,0,.35);



}



.fh-alert-overlay.open .fh-alert{ transform:scale(1); opacity:1; }



.fh-alert-icon{ width:44px; height:44px; margin:22px auto 8px; border-radius:50%; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; }



.fh-alert-icon svg{ width:22px; height:22px; }



.fh-alert h4{ margin:0 18px 6px; font-size:16.5px; font-weight:800; color:var(--text); }



.fh-alert p{ margin:0 18px 18px; font-size:13px; color:var(--text-2); line-height:1.42; }



.fh-alert-actions{ display:flex; border-top:.5px solid var(--hairline); }



.fh-alert-btn{ flex:1; padding:14px; border:none; background:none; font-size:16px; font-weight:600; color:var(--blue); cursor:pointer; }



.fh-alert-actions .fh-alert-btn + .fh-alert-btn{ border-left:.5px solid var(--hairline); }



.fh-alert-btn.destructive{ color:var(--red); font-weight:800; }



.fh-alert-btn:active{ background:var(--surface-2); }







/* ================================================================



   TOAST



   ================================================================ */



.fh-toast{



    position:fixed; left:50%; bottom:calc(26px + var(--safe-bottom)); transform:translateX(-50%) translateY(24px);



    display:flex; align-items:center; gap:8px;



    background:rgba(28,28,30,.94); color:#fff; padding:12px 18px; border-radius:14px;



    font-size:13.5px; font-weight:700; box-shadow:0 12px 30px rgba(0,0,0,.35);



    opacity:0; pointer-events:none; z-index:4000; transition:opacity .3s var(--spring), transform .3s var(--spring);



    max-width:86vw;



}



.fh-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }



.fh-toast svg{ width:16px; height:16px; flex-shrink:0; color:#30D158; }







@media (prefers-reduced-motion: reduce){



    *{ animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.001ms !important; }



}



</style>



</head>



<body>



<?php include 'sidebar.html'; ?>







<div class="fc-history">



    <header class="fh-header">



        <h1 class="fh-title">Storico Materie</h1>



        <div class="fh-toolbar">



            <button type="button" class="fh-user-pill" id="userPillBtn" disabled style="opacity:1;cursor:default;">



                <span class="fh-user-avatar" id="userAvatar">?</span>



                <span class="fh-user-name" id="userPillName">Seleziona utente</span>



                <span class="fh-user-count" id="userCount"></span>



            </button>



            <button type="button" class="fh-icon-btn" id="filterBtn" aria-label="Filtri">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>



                <span class="fh-badge" id="filterBadge" hidden>0</span>



            </button>



        </div>



        <div class="fh-chips" id="chipsRow"></div>



    </header>







    <a href="categories_game_fc.php" class="fh-new-btn">



        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>



        Nuova Partita Materie



    </a>







    <div class="fh-list" id="listContainer"></div>



    <div class="fh-empty" id="emptyState" hidden></div>



</div>







<!-- SHEET: selezione utente -->



<div class="fh-sheet-overlay" id="userSheetOverlay">



    <div class="fh-sheet">



        <div class="fh-sheet-handle"></div>



        <div class="fh-sheet-head"><h3>Seleziona utente</h3><button type="button" class="fh-sheet-done" id="userSheetDone">Fine</button></div>



        <input type="text" class="fh-user-search" id="userSearch" placeholder="Cerca utente...">



        <div id="userList"></div>



    </div>



</div>







<!-- SHEET: filtri -->



<div class="fh-sheet-overlay" id="filterSheetOverlay">



    <div class="fh-sheet">



        <div class="fh-sheet-handle"></div>



        <div class="fh-sheet-head"><h3>Filtri</h3><button type="button" class="fh-sheet-done" id="filterSheetDone">Fine</button></div>







        <div class="fh-filter-field">



            <label>Materia</label>



            <?php



                $cs_id = 'historyFilter';



                $cs_placeholder = 'Tutte le materie';



                $cs_show_all_option = true;



                $cs_all_option_label = 'Tutte le materie';



                $cs_allow_create = false;

                include 'category_selector.php';



            ?>



        </div>







        <div class="fh-filter-field">



            <label>Stato</label>



            <div class="fh-segmented" id="filterStatus">



                <button type="button" data-v="all" class="active">Tutti</button>



                <button type="button" data-v="new">Da imparare</button>



                <button type="button" data-v="learnt">Imparato</button>



            </div>



        </div>







        <div class="fh-filter-field">



            <label>Completamento</label>



            <div class="fh-segmented" id="filterProgress">



                <button type="button" data-v="all" class="active">Tutti</button>



                <button type="button" data-v="done">Completate</button>



                <button type="button" data-v="inprogress">In corso</button>



            </div>



        </div>







        <div class="fh-filter-field">



            <label>Periodo</label>



            <div class="fh-date-range">



                <input type="date" id="filterDateFrom">



                <span>–</span>



                <input type="date" id="filterDateTo">



            </div>



        </div>







        <div class="fh-filter-field">



            <div class="fh-range-row"><label>Punteggio minimo</label><span class="fh-range-val" id="scoreVal">0%</span></div>



            <input type="range" id="filterScore" min="0" max="100" step="5" value="0">



        </div>







        <button type="button" class="fh-sheet-reset" id="resetFiltersBtn">Reimposta tutti i filtri</button>



    </div>



</div>







<!-- ALERT: conferma eliminazione -->



<div class="fh-alert-overlay" id="deleteAlertOverlay">



    <div class="fh-alert">



        <div class="fh-alert-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></div>



        <h4>Eliminare la partita?</h4>



        <p>Verranno eliminati anche gli eventuali tentativi di retry collegati. L'azione non pu&ograve; essere annullata.</p>



        <div class="fh-alert-actions">



            <button type="button" class="fh-alert-btn" id="cancelDeleteBtn">Annulla</button>



            <button type="button" class="fh-alert-btn destructive" id="confirmDeleteBtn">Elimina</button>



        </div>



    </div>



</div>







<div class="fh-toast" id="toast"></div>







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



            el.userList.innerHTML = '<p style="text-align:center;color:var(--text-2);font-size:14px;padding:20px 0;">Nessun utente trovato</p>';



            return;



        }



        el.userList.innerHTML = list.map(function (u) {



            var sel = (u.id === state.userId);



            return '<div class="fh-user-row' + (sel ? ' selected' : '') + '" data-id="' + u.id + '">' +



                '<span class="u-avatar">' + escapeHtml(initials(u.username)) + '</span>' +



                '<span class="u-name">' + escapeHtml(u.username || ('Utente #' + u.id)) + '</span>' +



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



        var name = u ? (u.username || ('Utente #' + u.id)) : 'Seleziona utente';



        el.userPillName.textContent = name;



        el.userAvatar.textContent = initials(name);



        el.userCount.textContent = state.rows.length ? (state.rows.length + (state.rows.length === 1 ? ' partita' : ' partite')) : '';



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



                showToast('Errore di caricamento', false);



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



        if (window.csSetValue) window.csSetValue(CS_HISTORY_MODAL_ID, 0, 'Tutte le materie');



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



        if (f.status !== 'all') chips.push({ k: 'status', label: f.status === 'learnt' ? 'Imparato' : 'Da imparare' });



        if (f.progress !== 'all') chips.push({ k: 'progress', label: f.progress === 'done' ? 'Completate' : 'In corso' });



        if (f.dateFrom || f.dateTo) chips.push({ k: 'date', label: (f.dateFrom || '...') + ' \u2013 ' + (f.dateTo || '...') });



        if (f.minScore > 0) chips.push({ k: 'score', label: 'Min ' + f.minScore + '%' });







        el.chipsRow.innerHTML = chips.map(function (c) {



            return '<span class="fh-chip" data-k="' + c.k + '">' + escapeHtml(c.label) +



                '<button type="button" aria-label="Rimuovi filtro"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></span>';



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



               '<h3>Nessun risultato</h3><p>Nessuna partita corrisponde ai filtri selezionati.</p>' +



               '<button type="button" id="emptyResetBtn">Reimposta filtri</button>')



            : ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 4v4M16 4v4"/></svg>' +



               '<h3>Nessuna partita</h3><p>Questo utente non ha ancora giocato a Materie.</p>');



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



        var statusLabel = row.learnt ? 'Imparato' : 'Da imparare';



        var pct = row.total > 0 ? Math.max(0, Math.min(100, Math.round(row.played / row.total * 100))) : 0;



        var retryHtml = '';



        if (row.children && row.children.length) {



            retryHtml += '<button type="button" class="fh-retry-toggle" data-target="fh-retry-' + row.id + '">' +



                '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>' +



                '<span>' + row.children.length + (row.children.length === 1 ? ' tentativo di retry' : ' tentativi di retry') + '</span>' +



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



                    '<span>Elimina</span>' +



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



                    showToast('Partita eliminata');



                } else {



                    showToast('Impossibile eliminare la partita', false);



                    if (item) closeItem(item, true);



                }



            })



            .catch(function () {



                showToast('Errore di rete', false);



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

