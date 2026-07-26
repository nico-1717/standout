<?php



// connessione al database

require_once 'config.php';

require_once 'auth_check.php'; // 1. Protezione con login



$current_user_id = $utente_corrente['id']; // 2. ID utente sicuro



$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);



if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }


// --- CARICAMENTO DATI ---



$catID = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;



// --- INIZIO SICUREZZA: Verifica proprietario pagina GET ---

$check_get = mysqli_query($conn, "SELECT user_id FROM fc_categories WHERE id = $catID");

$row_get = mysqli_fetch_assoc($check_get);

if (!$row_get || (int)$row_get['user_id'] !== $current_user_id) {

    die("Errore di sicurezza: Non sei il proprietario di questa categoria.");

}

// --- FINE SICUREZZA ---



$cards = [];



// Prendiamo le carte principali (padri o indipendenti)

$res = mysqli_query($conn, "SELECT i.*, (SELECT COUNT(*) FROM fc_examples e WHERE e.fc_image_id = i.id) as ex_count, 0 as is_child FROM fc_images i WHERE i.category_id = $catID ORDER BY i.custom_order ASC");



while($row = mysqli_fetch_assoc($res)) { 

    // AGGIUNTA: mappatura fittizia anche per i padri

    $row['id_fcimg'] = $row['id'];

    $cards[] = $row; 

    

    $parentId = $row['id'];



    // MODIFICA: usa c.id_child as id

    $resChild = mysqli_query($conn, "SELECT c.*, c.id_child as id, 1 as is_child, 0 as ex_count FROM fc_child_cards c WHERE c.parent_id = $parentId ORDER BY c.custom_order ASC");



    // AGGIUNTA: controllo di sicurezza sulla query

    if ($resChild) {

        while($child = mysqli_fetch_assoc($resChild)) {

            $child['id_fcimg'] = $child['id'];

            $cards[] = $child;

        }

    }

}

?>



<!DOCTYPE html>







<html lang="it">







<head>







    <meta charset="UTF-8">







    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">







    <meta name="mobile-web-app-capable" content="yes">







    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">







    <meta name="format-detection" content="telephone=no">







    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">







    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">







    <title>Big Edit · Flashcards Universitarie</title>















    <script>







        window.MathJax = {







        tex: {







            inlineMath: [['$', '$'], ['\\(', '\\)']],







            displayMath: [['$$', '$$'], ['\\[', '\\]']]







        }







        };







    </script>







    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>















    <style>







        /* ================================================================







           DESIGN SYSTEM â€” iOS nativo Â· light + dark automatico







           (coerente con game_fc.php)







           ================================================================ */







        :root{







            color-scheme: light dark;







            --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EDEDF2;







            --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;







            --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);







            --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --pink:#FF2D55; --gray:#8E8E93;







            --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);







            --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12); --pink-soft:rgba(255,45,85,.12); --gray-soft:rgba(142,142,147,.14);







            --header-glass:rgba(249,249,251,.82);







            --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);







            --chip-bg:#FFFFFF;







            --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);







            --raise:0 1px 2px rgba(0,0,0,.07), 0 10px 22px -6px rgba(0,0,0,.16);







            --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);







            --pill-hl:inset 0 0 0 .5px var(--hairline);







            --r-card:22px; --r-btn:15px; --r-chip:100px; --r-tile:16px;







            --spring:cubic-bezier(.32,.72,0,1);







            --snap:cubic-bezier(.24,1.3,.35,1);







            --header-h:64px;







            --safe-top:env(safe-area-inset-top, 0px);







            --safe-bottom:env(safe-area-inset-bottom, 0px);







        }







        @media (prefers-color-scheme: dark){







            :root{







                --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#333336;







                --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;







                --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);







                --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --pink:#FF375F; --gray:#98989F;







                --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);







                --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18); --pink-soft:rgba(255,55,95,.18); --gray-soft:rgba(152,152,159,.18);







                --header-glass:rgba(16,16,18,.78);







                --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);







                --chip-bg:#3A3A3C;







                --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);







                --raise:0 1px 2px rgba(0,0,0,.5), 0 12px 26px -6px rgba(0,0,0,.65);







                --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);







                --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);







            }







        }















        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }







        html{ -webkit-text-size-adjust:100%; }







        html, body{ min-height:100%; overflow-x:hidden; }







        body{







            margin:0; background:var(--bg); color:var(--text);







            font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;







            font-size:16px; line-height:1.45;







            -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;







            padding-bottom: calc(60px + var(--safe-bottom));







        }







        body.no-select, body.no-select *{ -webkit-user-select:none !important; user-select:none !important; }







        :focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }







        a{ color:var(--blue); }















        /* ================================================================







           HEADER







           ================================================================ */







        .app-header{

            position:fixed; top:0; left:0; right:0; z-index:200; /* ← Fissato in modo assoluto */

            padding:calc(var(--safe-top) + 12px) 16px 12px;

            background:var(--header-glass);

            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);

            border-bottom:.5px solid var(--hairline);

            display:flex; align-items:center; justify-content:space-between; gap:12px;

        }

        .app-header .hdr-text{ min-width:0; }

        .app-header h1{

            margin:0; font-size:19px; font-weight:800; letter-spacing:-.02em; color:var(--text);

            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;

        }

        .app-header .hdr-sub{ margin-top:1px; font-size:12px; font-weight:600; color:var(--text-2); }



        .hdr-chip{







            flex-shrink:0; display:inline-flex; align-items:center; gap:6px;







            padding:7px 13px; border-radius:var(--r-chip); font-size:12.5px; font-weight:800;







            background:var(--blue-soft); color:var(--blue); font-variant-numeric:tabular-nums;







        }

        

        /* Aumentato il padding-top per far spazio all'header fisso */

        .page-wrap{ max-width:760px; margin:0 auto; padding:calc(var(--safe-top) + 84px) 14px calc(24px + var(--safe-bottom)); }















        /* ================================================================







           BOTTONI â€” sistema condiviso (coerente con game_fc)







           ================================================================ */







        .btn{







            display:flex; align-items:center; justify-content:center; gap:8px;







            width:100%; padding:15px 16px; margin:0;







            border:none; border-radius:var(--r-btn); cursor:pointer;







            font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em;







            color:#fff; background:var(--text);







            text-decoration:none; text-align:center;







            -webkit-user-select:none; user-select:none;







            --glow:rgba(0,0,0,.3);







            box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px var(--glow);







            transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s, opacity .2s;







        }







        .btn:active{







            transform:translateY(1.5px) scale(.985);







            filter:brightness(.95);







            box-shadow:inset 0 1px 0 rgba(255,255,255,.1), inset 0 3px 8px rgba(0,0,0,.18), 0 1px 2px rgba(0,0,0,.12), 0 4px 10px -5px var(--glow);







        }







        .btn:disabled{ opacity:.55; cursor:not-allowed; transform:none !important; filter:none !important; }







        .btn-primary{







            --glow:rgba(10,132,255,.5);







            background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));







        }







        .btn-success{







            --glow:rgba(52,199,89,.5);







            background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 18%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%));







        }







        .btn-warn{







            --glow:rgba(255,149,0,.5);







            background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 16%), var(--orange) 52%, color-mix(in srgb, var(--orange), #000 10%));







        }







        .btn-flat-warn{ color:var(--gray); background:var(--gray-soft); box-shadow:inset 0 0 0 1.5px var(--hairline-2); --glow:transparent; }







        .btn-ghost{







            color:var(--text); background:var(--surface);







            box-shadow:var(--pill-hl), var(--raise);







        }







        .btn-ghost:active{ filter:none; box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.08); }







        .btn-sm{ padding:11px 14px; font-size:14.5px; }







        .btn svg{ width:18px; height:18px; flex-shrink:0; }















        .spinner{ width:17px; height:17px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; animation:spin .7s linear infinite; }







        @keyframes spin{ to{ transform:rotate(360deg); } }















        /* ================================================================







           CARD / SETUP SECTIONS







           ================================================================ */







        .card-surface{







            background:var(--surface); border-radius:var(--r-card);







            border:.5px solid var(--hairline); box-shadow:var(--shadow-card);







            padding:18px 16px;







        }







        .setup-grid{ display:grid; grid-template-columns:1fr; gap:12px; margin-bottom:14px; }







        @media (min-width:680px){ .setup-grid{ grid-template-columns:1fr 1fr; } .setup-grid .full{ grid-column:1/-1; } }















        .setup-card{ padding:16px; }







        .setup-card .sc-label{







            display:flex; align-items:center; gap:8px;







            font-size:11px; font-weight:800; letter-spacing:.09em; text-transform:uppercase;







            color:var(--blue); margin-bottom:12px;







        }







        .setup-card .sc-label .ic{ width:20px; height:20px; border-radius:7px; background:var(--blue-soft); display:flex; align-items:center; justify-content:center; flex-shrink:0; }







        .setup-card .sc-label .ic svg{ width:12.5px; height:12.5px; color:var(--blue); }







        .mini-label{ font-size:12.5px; font-weight:700; color:var(--text-2); margin:0 0 6px; display:block; }















        input[type="file"]{ font-size:13px; color:var(--text-2); }







        input[type="file"]::file-selector-button{







            border:none; padding:8px 12px; margin-right:8px; border-radius:10px;







            background:var(--blue-soft); color:var(--blue); font-weight:700; font-size:12.5px; cursor:pointer;







        }















        textarea, select, input[type="text"]{







            width:100%; box-sizing:border-box; font-family:inherit; color:var(--text);







            background:var(--surface-2); border:1px solid var(--hairline-2); border-radius:12px;







            padding:11px 12px; font-size:16px; resize:vertical;







        }







        select{ -webkit-appearance:none; appearance:none; padding-right:34px;







            background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");







            background-repeat:no-repeat; background-position:right 11px center;







        }







        textarea:focus, select:focus, input:focus{ outline:none; border-color:var(--blue); }















        .category-btn{







            width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px;







            padding:15px 16px; border-radius:14px; border:1.5px dashed var(--hairline-2);







            background:var(--surface-2); color:var(--text-3); font-weight:700; font-size:15.5px;







            cursor:pointer; font-family:inherit; text-align:left; transition:border-color .2s, background .2s, transform .15s var(--spring);







        }







        .category-btn.is-set{ color:var(--text); border-style:solid; border-color:var(--blue); background:var(--blue-soft); }







        .category-btn:active{ transform:scale(.99); }







        .category-btn .cb-ic{ width:34px; height:34px; border-radius:10px; background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); display:flex; align-items:center; justify-content:center; flex-shrink:0; }







        .category-btn .cb-ic svg{ width:17px; height:17px; color:var(--blue); }







        .category-btn .cb-chev{ color:var(--text-3); flex-shrink:0; }







        .category-btn .cb-chev svg{ width:16px; height:16px; }















        .import-row{ display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; }







        .import-row > div{ flex:1; min-width:130px; }















        .ai-prompt-box{







            background:var(--surface-2); border:1.5px dashed var(--hairline-2); border-radius:14px;







            margin-top:10px; overflow:hidden; position:relative;







        }







        .ai-prompt-box textarea{







            width:100%; height:150px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12.5px; line-height:1.55;







            border:none; background:transparent; resize:vertical; outline:none; cursor:copy; padding:14px;







        }







        .ai-copy-hint{







            position:absolute; top:8px; right:8px; font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase;







            color:var(--purple); background:var(--purple-soft); padding:4px 9px; border-radius:99px; pointer-events:none;







        }







        .format-help{







            background:var(--purple-soft); border-radius:var(--r-card); padding:16px; margin-bottom:14px;







            border:.5px solid transparent;







        }







        .format-help strong{ color:var(--purple); font-size:14.5px; }







        .format-help p{ margin:6px 0 0; font-size:13px; color:var(--text-2); line-height:1.5; }















        #success-msg{







            display:none; background:var(--green-soft); color:var(--green); padding:22px 18px; text-align:center;







            border-radius:var(--r-card); margin:0 0 16px; font-size:16px; font-weight:700; line-height:1.5;







            animation:popIn .4s var(--spring) both;







        }







        #success-msg small{ display:block; margin-top:6px; font-weight:600; color:var(--text-2); font-size:13px; }







        @keyframes popIn{ from{ opacity:0; transform:translateY(-8px) scale(.97); } }















        /* ================================================================







           BANNER SELEZIONE PADRE







           ================================================================ */







        #parent-selection-banner{







            position:fixed; top:0; left:0; right:0; z-index:9000;







            display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; text-align:center;







            padding:calc(var(--safe-top) + 12px) 16px 12px;







            background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 10%), var(--orange));







            color:#fff; font-weight:700; font-size:14.5px;







            box-shadow:0 6px 24px rgba(255,149,0,.35);







            transform:translateY(-100%); transition:transform .4s var(--snap);







        }







        #parent-selection-banner.show{ transform:translateY(0); }







        #parent-selection-banner button{







            padding:8px 16px; font-weight:800; font-size:13px; border:none; border-radius:99px; cursor:pointer;







            background:rgba(255,255,255,.22); color:#fff; backdrop-filter:blur(4px);







        }







        body.selecting-mode{ padding-top:0; }







        body.selecting-mode .fc-row{ transition:box-shadow .2s, opacity .2s; }







        body.selecting-mode .fc-row.eligible-target .fc-row-content{ box-shadow:0 0 0 2px var(--orange), var(--shadow-card); }







        body.selecting-mode .fc-row.is-child:not(.pending-child) .fc-row-content{ opacity:.45; }







        body.selecting-mode .fc-row.pending-child .fc-row-content{ box-shadow:0 0 0 2px var(--blue), var(--shadow-card); }







        /* durante la selezione del padre, i figli non devono poter essere eliminati o sganciati */







        body.selecting-mode .fc-row.is-child .swipe-btn-delete,







        body.selecting-mode .fc-row.is-child .swipe-btn-unjoin{ visibility:hidden; }















        /* ================================================================







           TOAST







           ================================================================ */







        .app-toast{







            position:fixed; left:50%; bottom:calc(24px + var(--safe-bottom)); z-index:11000;







            transform:translateX(-50%) translateY(20px); opacity:0;







            max-width:88%; padding:13px 20px; border-radius:16px;







            background:var(--text); color:var(--bg); font-size:14px; font-weight:600; text-align:center;







            box-shadow:0 14px 40px rgba(0,0,0,.3); transition:opacity .3s var(--spring), transform .3s var(--spring);







        }







        .app-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }







        .app-toast.toast-error{ background:var(--red); color:#fff; }















        /* ================================================================







           MODALE CONFERMA







           ================================================================ */







        .confirm-overlay{







            position:fixed; inset:0; z-index:10500; display:none;







            align-items:flex-end; justify-content:center;







            background:rgba(0,0,0,.4); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);







        }







        .confirm-overlay.show{ display:flex; }







        .confirm-panel{







            width:100%; max-width:440px; background:var(--surface); color:var(--text);







            border-radius:24px 24px 0 0; padding:26px 20px calc(20px + var(--safe-bottom));







            box-shadow:0 -10px 50px rgba(0,0,0,.3); animation:sheetUp .4s var(--snap) both; position:relative;







        }







        @keyframes sheetUp{ from{ transform:translateY(40%); opacity:.3; } to{ transform:none; opacity:1; } }







        .confirm-panel::before{ content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }







        .confirm-panel h3{ margin:4px 0 8px; font-size:18.5px; font-weight:800; letter-spacing:-.01em; }







        .confirm-panel p{ margin:0 0 18px; font-size:14.5px; color:var(--text-2); line-height:1.5; }







        .confirm-actions{ display:flex; gap:10px; }







        .confirm-actions .btn{ flex:1; }







        @media (min-width:480px){ .confirm-overlay{ align-items:center; } .confirm-panel{ border-radius:24px; } }







    </style>







    <style>







        /* ================================================================







           LISTA RIGHE (ex-tabella) â€” card eleganti, gesture iOS







           ================================================================ */







        .fc-rows{ display:flex; flex-direction:column; gap:14px; margin:16px 0; }







        .fc-rows:empty{ display:none; }















        .fc-row{







            position:relative; border-radius:var(--r-card); overflow:hidden;







            box-shadow:var(--shadow-card);







        }







        .fc-row.is-dragging-row{ box-shadow:none; }







        .fc-row.is-child{ margin-left:26px; }















        /* --- livello azioni swipe (sotto, rivelato scorrendo verso destra) ---







           IMPORTANTE: niente inset:0/right:0 qui. Deve restringersi al contenuto







           (i 2 pulsanti, ~172px) altrimenti offsetWidth = larghezza di tutta la riga







           e lo swipe potrebbe trascinare l'intera card fuori dallo schermo. */







        .swipe-actions{







            position:absolute; top:0; left:0; bottom:0; display:flex; align-items:stretch; justify-content:flex-start;







        }







        .swipe-btn{







            width:86px; flex-shrink:0; border:none; cursor:pointer;







            display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px;







            color:#fff; font-family:inherit; font-size:11.5px; font-weight:800; letter-spacing:.01em;







        }







        .swipe-btn svg{ width:21px; height:21px; }







        .swipe-btn-delete{ background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 12%), var(--red)); }







        .swipe-btn-join{ background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 12%), var(--orange)); }







        .swipe-btn-unjoin{ background:linear-gradient(180deg, color-mix(in srgb, var(--gray), #fff 12%), var(--gray)); }







        .swipe-btn:active{ filter:brightness(.92); }







        .fc-row:not(.is-child) .swipe-btn-unjoin{ display:none; }







        .fc-row.is-child .swipe-btn-join{ display:none; }







        .fc-drag-ghost{ cursor:grabbing; }















        /* --- contenuto principale della riga (si sposta orizzontalmente) --- */







        .fc-row-content{







            position:relative; z-index:2; background:var(--surface);







            border-radius:var(--r-card); border:.5px solid var(--hairline);







            will-change:transform; touch-action:pan-y;







        }







        /* Nota: background sempre 100% opaco (mix di due colori pieni) â€” mai da colori







           -soft con alpha, altrimenti si intravedono i pulsanti dello swipe sotto. */







        .fc-row.is-child .fc-row-content{







            background: color-mix(in srgb, var(--surface), var(--orange) 14%);







            border-left:3px solid var(--orange);







        }















        .fc-row-strip{







            display:flex; align-items:center; gap:8px; padding:12px 14px 8px;







            cursor:grab; touch-action:pan-y; flex-wrap:wrap; row-gap:6px;







        }







        .fc-row-strip .grip{







            display:flex; flex-direction:column; gap:3px; padding:2px; flex-shrink:0; color:var(--text-3);







        }







        .fc-row-strip .grip svg{ width:18px; height:18px; }







        .row-badge{







            display:inline-flex; align-items:center; gap:6px;







            font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text-2);







            background:var(--surface-3); padding:4px 10px; border-radius:99px;







        }







        .row-badge::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }







        .fc-row.is-child .row-badge{ background:var(--orange); color:#fff; }







        .fc-row.is-child .row-badge::before{ display:none; }







        .strip-spacer{ flex:1; }







        .strip-hint{ font-size:11px; color:var(--text-3); font-weight:600; display:flex; align-items:center; gap:4px; opacity:.85; }







        .strip-hint svg{ width:13px; height:13px; }















        .fc-row-grid{ display:grid; grid-template-columns:1fr; gap:2px; padding:0 10px 12px; }







        @media (min-width:720px){ .fc-row-grid{ grid-template-columns:1fr 1fr; gap:14px; padding:0 14px 14px; } }















        .fc-field{ padding:8px 6px 4px; }







        @media (min-width:720px){ .fc-field{ padding:6px 0; } .fc-field + .fc-field{ border-left:.5px solid var(--hairline); padding-left:14px; } }















        .field-label{







            display:flex; align-items:center; gap:7px; font-size:10.5px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;







            color:var(--text-3); margin-bottom:7px;







        }







        .field-label.is-answer{ color:var(--blue); }















        .editor-wrap{ border-radius:14px; overflow:hidden; border:1px solid var(--hairline-2); background:var(--surface-2); }







        textarea.cell-input{







            display:block; width:100%; border:none; border-radius:0; background:transparent;







            padding:11px 12px 8px; font-size:16px; line-height:1.5; min-height:64px; box-shadow:none;







            overscroll-behavior:contain;







        }







        textarea.cell-input:focus{ border:none; outline:none; background:var(--surface); }















        .toolbar{







            display:flex; gap:4px; flex-wrap:wrap; padding:5px 6px; border-top:.5px solid var(--hairline);







            background:var(--surface-3);







        }







        .tbtn{







            width:27px; height:27px; border-radius:8px; border:none; cursor:pointer;







            display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:800;







            background:var(--surface); color:var(--text-2); box-shadow:var(--pill-hl);







            transition:transform .12s var(--spring);







        }







        .tbtn:active{ transform:scale(.88); }







        .tbtn.tc-red{ color:var(--red); } .tbtn.tc-blue{ color:var(--blue); } .tbtn.tc-green{ color:var(--green); }







        .tbtn.tc-purple{ color:var(--purple); } .tbtn.tc-orange{ color:var(--orange); }







        .tbtn.tc-yellow{ background:#FFE066; color:#7a5b00; }







        .tsep{ width:1px; align-self:stretch; background:var(--hairline-2); margin:2px 2px; }















        .live-preview{







            margin-top:7px; padding:10px 11px; background:var(--purple-soft); border-radius:12px;







            min-height:18px; font-size:13.5px; color:var(--text); text-align:left; white-space:pre-wrap; word-wrap:break-word;







            max-height:180px; overflow-y:auto; line-height:1.5; overscroll-behavior:contain;







        }







        .live-preview:empty{ display:none; }







        .live-preview mjx-container{ max-width:100%; overflow-x:auto; overflow-y:hidden; }















        .media-row{ display:flex; gap:8px; margin-top:9px; flex-wrap:wrap; align-items:center; }







        .media-chip{







            display:flex; align-items:center; gap:7px; padding:8px 12px 8px 8px; border-radius:99px;







            background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); cursor:pointer;







            font-size:12.5px; font-weight:700; color:var(--text-2); border:none; font-family:inherit;







            transition:transform .15s var(--spring);







        }







        .media-chip:active{ transform:scale(.95); }







        .media-chip input[type=file]{ display:none; }







        .chip-thumb{







            width:26px; height:26px; border-radius:50%; overflow:hidden; flex-shrink:0;







            background:var(--surface-3); display:flex; align-items:center; justify-content:center; color:var(--text-3);







        }







        .chip-thumb svg{ width:13px; height:13px; }







        .chip-thumb img{ width:100%; height:100%; object-fit:cover; }







        .img-chip.has-img{ color:var(--text); }







        .img-name-tag{ max-width:90px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }















        .btn-rec{







            color:var(--pink); background:var(--pink-soft);







            box-shadow:inset 0 0 0 1.5px rgba(255,45,85,.35), var(--pill-hl);







        }







        .btn-rec .chip-thumb{ background:transparent; color:var(--pink); }







        .btn-rec.recording{







            color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--pink), #fff 15%), var(--pink));







            box-shadow:0 0 0 4px var(--pink-soft), 0 6px 16px -6px rgba(255,45,85,.6);







            animation:recPulse 1.4s ease-in-out infinite;







        }







        .btn-rec.recording .chip-thumb{ color:#fff; }







        @keyframes recPulse{







            0%,100%{ box-shadow:0 0 0 4px var(--pink-soft), 0 6px 16px -6px rgba(255,45,85,.6); }







            50%{ box-shadow:0 0 0 8px var(--pink-soft), 0 6px 20px -4px rgba(255,45,85,.75); }







        }







        .rec-dot{ width:8px; height:8px; border-radius:50%; background:#fff; flex-shrink:0; }







        .btn-rec:not(.recording) .rec-dot{ background:var(--pink); }







        .btn-rec.recording .rec-dot{ animation:recBlink 1s step-start infinite; }







        @keyframes recBlink{ 50%{ opacity:.25; } }















        .audio-player{ display:none; width:100%; height:36px; margin-top:8px; border-radius:99px; }







        .audio-player.has-audio{ display:block; }















        /* --- placeholder & drag ghost (riordino) --- */







        .fc-row-placeholder{







            border-radius:var(--r-card); border:2px dashed var(--hairline-2);







            background:var(--surface-2); opacity:.7;







        }







        .fc-drag-ghost{ pointer-events:none; }







        .fc-drag-ghost .fc-row{ box-shadow:0 22px 44px -12px rgba(0,0,0,.35), 0 4px 14px rgba(0,0,0,.14); }







        .fc-drag-ghost .fc-row-content{ box-shadow:0 0 0 2px var(--blue); }















        /* ================================================================







           BOTTONE AGGIUNGI / GENERA / SALVA







           ================================================================ */







        .action-stack{ display:flex; flex-direction:column; gap:10px; margin-top:6px; }







        .empty-hint{







            text-align:center; padding:26px 18px; color:var(--text-3); font-size:13.5px; font-weight:600;







            border:1.5px dashed var(--hairline-2); border-radius:var(--r-card);







        }















        @media (prefers-reduced-motion: reduce){







            *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }







        }







    </style>







    <style>







        /* ================================================================







           AGGIUNTE SPECIFICHE DI BIG EDIT







           ================================================================ */















        /* --- header: pulsante indietro + salva rapido --- */







        .hdr-back{







            flex-shrink:0; width:38px; height:38px; border-radius:12px; border:none; cursor:pointer;







            background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); color:var(--text);







            display:flex; align-items:center; justify-content:center; transition:transform .15s var(--spring);







        }







        .hdr-back:active{ transform:scale(.9); }







        .hdr-back svg{ width:18px; height:18px; }







        .hdr-dirty-dot{







            width:8px; height:8px; border-radius:50%; background:var(--orange); flex-shrink:0;







            box-shadow:0 0 0 3px var(--orange-soft); display:none;







        }







        body.is-dirty .hdr-dirty-dot{ display:block; }















        /* --- badge stato riga (nuova / modificata) accanto al badge Concetto/Figlio --- */







        .status-badge{







            display:none; align-items:center; gap:5px; font-size:10px; font-weight:800;







            letter-spacing:.08em; text-transform:uppercase; padding:4px 10px; border-radius:99px;







        }







        .fc-row.status-new .status-badge.sb-new{ display:inline-flex; background:var(--green); color:#fff; }







        .fc-row.status-edited .status-badge.sb-edited{ display:inline-flex; background:var(--orange-soft); color:var(--orange); }















        /* --- chip Esempi (si aggancia a examples_modal.php: quello script imposta







           direttamente style.background/color/borderColor sul bottone, quindi qui







           definiamo solo forma/spaziatura/animazione, mai i colori) --- */







        .ex-chip{







            display:inline-flex; align-items:center; gap:6px; flex-shrink:0;







            padding:6px 12px; border-radius:99px; font-family:inherit; font-size:11.5px; font-weight:800;







            cursor:pointer; transition:transform .15s var(--spring); white-space:nowrap;







        }







        .ex-chip:active{ transform:scale(.94); }







        .ex-chip svg{ width:13px; height:13px; }







        .ex-chip.ex-disabled{ cursor:not-allowed; opacity:.65; }















        /* --- riga eliminata (temporanea, finchÃ© non si salva) --- */







        .fc-row.is-deleted .fc-row-strip,







        .fc-row.is-deleted .fc-row-grid{ display:none; }







        .fc-row .deleted-stub{ display:none; }







        .fc-row.is-deleted .deleted-stub{ display:flex; }







        .fc-row.is-deleted .fc-row-content{







            background: color-mix(in srgb, var(--surface), var(--red) 11%);







            border-color: color-mix(in srgb, var(--hairline), var(--red) 30%);







        }







        .deleted-stub{







            align-items:center; gap:12px; padding:16px 14px;







        }







        .deleted-stub .stub-icon{







            width:34px; height:34px; border-radius:10px; background:var(--red-soft); color:var(--red);







            display:flex; align-items:center; justify-content:center; flex-shrink:0;







        }







        .deleted-stub .stub-icon svg{ width:16px; height:16px; }







        .deleted-stub .stub-text{







            flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;







            text-decoration:line-through; color:var(--text-3); font-size:13.5px; font-weight:600;







        }







        .btn-restore{







            flex-shrink:0; display:flex; align-items:center; gap:6px; padding:9px 14px; border:none; border-radius:99px;







            background:var(--blue); color:#fff; font-family:inherit; font-size:12.5px; font-weight:800; cursor:pointer;







            box-shadow:0 4px 12px -4px rgba(0,122,255,.5); transition:transform .15s var(--spring);







        }







        .btn-restore:active{ transform:scale(.93); }







        .btn-restore svg{ width:14px; height:14px; }















        /* --- barra di salvataggio fissa in basso --- */







        .save-bar{







            position:sticky; bottom:0; left:0; right:0; z-index:150; margin-top:22px;







            padding:12px 14px calc(12px + var(--safe-bottom));







            background:var(--header-glass);







            -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);







            border-top:.5px solid var(--hairline);







        }







        .save-bar .btn{ max-width:760px; margin:0 auto; }















        /* niente altro sotto: il bottone "aggiungi riga" resta nel flusso normale */















        /* la striscia della riga ora ospita piÃ¹ elementi (badge stato + chip esempi):







           su schermi stretti nascondiamo il suggerimento testuale, resta l'icona */







        @media (max-width:520px){







            .strip-hint span{ display:none; }







        }







    </style>







    <script src="fc_parser.js?v=<?php echo time(); ?>"></script>







</head>







<body>















<div id="parent-selection-banner">







    <span>ðŸ‘‡ Tocca il concetto padre a cui agganciare questo figlio</span>







    <button type="button" onclick="cancelChildSelection()">âœ• Annulla</button>







</div>















<header class="app-header">

    <button type="button" class="hdr-back" onclick="goBack()" aria-label="Torna indietro">

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>

    </button>

    <div class="hdr-text" style="flex:1;">

        <h1>Big Edit</h1>

        <div class="hdr-sub">Aggiornamento rapido flashcards</div>

    </div>

    

    <div style="display:flex; align-items:center; gap:10px;">

        <span class="hdr-dirty-dot" title="Modifiche non salvate"></span>

        

        <button type="button" id="hdrSaveBtn" class="btn btn-success btn-sm" style="width:auto; padding:7px 12px; font-size:13px; margin:0; box-shadow:var(--raise-sm);" onclick="saveAll()">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg>

            Salva

        </button>

        

        <div class="hdr-chip" id="row-count-chip">0 righe</div>

    </div>

</header>



<div style="display: none;" id="editor-template-container">

    <?php 

        $ft_field = '%%FIELD_ID%%';

        $ft_raw = '%%RAW_TEXT%%';

        $ft_placeholder = '%%PLACEHOLDER%%';

        include 'format_toolbar.php'; 

    ?>

</div>

<div class="page-wrap">
    <div class="fc-rows" id="fcRows"></div>
    <div class="empty-hint" id="emptyHint">Nessuna flashcard in questa categoria - tocca "Aggiungi Nuova Riga" per iniziare.</div>
    <div id="loadMoreContainer" style="display:none; text-align:center; padding: 10px 14px 20px;">
        <button type="button" class="btn btn-ghost" style="width:auto; display:inline-flex; border:1.5px solid var(--hairline-2); font-size:14px; padding:10px 20px; box-shadow:var(--raise-sm);" onclick="renderNextBatch(20)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;margin-right:6px;"><path d="M6 9l6 6 6-6"/></svg>
            Caricane altre 20
        </button>
    </div>
    <div class="action-stack">
        <button type="button" class="btn btn-primary" onclick="addRow()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Aggiungi Nuova Riga
        </button>
    </div>
</div>

<div class="save-bar">
    <button type="button" id="saveBtn" class="btn btn-success" onclick="saveAll()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
        Salva Tutte le Modifiche
    </button>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-panel">
        <h3 id="confirmTitle">Sei sicuro?</h3>
        <p id="confirmMessage"></p>
        <div class="confirm-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn">Annulla</button>
            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn">Conferma</button>
        </div>
    </div>
</div>















<script>







    const CAT_ID = <?php echo (int)$catID; ?>;







    const INITIAL_CARDS = <?php echo json_encode($cards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;







</script>







<script>







    /* ============================================================







       ICONE INLINE







       ============================================================ */







    const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';







    const ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 14.5 14.5 9.5M8 17l-2 2a3.5 3.5 0 0 1-5-5l3-3a3.5 3.5 0 0 1 5 0M16 7l2-2a3.5 3.5 0 0 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></svg>';







    const ICON_UNLINK= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17H8a4 4 0 0 1 0-8h1M11 7h5a4 4 0 0 1 0 8h-1M4 4l16 16"/></svg>';







    const ICON_GRIP  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"/><circle cx="16" cy="6" r="1.6"/><circle cx="8" cy="12" r="1.6"/><circle cx="16" cy="12" r="1.6"/><circle cx="8" cy="18" r="1.6"/><circle cx="16" cy="18" r="1.6"/></svg>';







    const ICON_REORDER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m8 9 4-4 4 4M8 15l4 4 4-4"/></svg>';







    const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';







    const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';







    const ICON_TRASH_SM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';







    const ICON_UNDO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h11a5 5 0 0 1 0 10H9M4 10l5-5M4 10l5 5"/></svg>';















    function escAttr(s){







        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');







    }















    /* ============================================================







       STATO GLOBALE







       ============================================================ */







    let isSelectingParent = false;







    let childRowPending = null;







    let gestureBlock = false;







    let activeDrag = null;







    let autoScrollRAF = null;







    let lastPointerY = 0;







    let suppressNextRowClick = false;







    let isDirty = false;















    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }







    function isChildRow(row){ return row.classList.contains('is-child'); }







    function getBlockStart(row){ let r = row; while (r && isChildRow(r)) r = r.previousElementSibling; return r; }







    function getBlockRows(parentRow){







        const rows = [parentRow];







        let cur = parentRow.nextElementSibling;







        while (cur && isChildRow(cur)) { rows.push(cur); cur = cur.nextElementSibling; }







        return rows;







    }















    function updateDirtyUI(){ document.body.classList.toggle('is-dirty', isDirty); }















    /* ============================================================







       TOAST & MODALE CONFERMA







       ============================================================ */







    function showToast(msg, kind){







        const t = document.createElement('div');







        t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');







        t.textContent = msg;







        document.body.appendChild(t);







        requestAnimationFrame(()=> t.classList.add('show'));







        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=> t.remove(), 320); }, 3200);







    }















    function showConfirm(title, message, okLabel){







        return new Promise(resolve=>{







            const overlay = document.getElementById('confirmOverlay');







            document.getElementById('confirmTitle').textContent = title;







            document.getElementById('confirmMessage').textContent = message;







            const okBtn = document.getElementById('confirmOkBtn');







            const cancelBtn = document.getElementById('confirmCancelBtn');







            okBtn.textContent = okLabel || 'Conferma';







            overlay.classList.add('show');







            function cleanup(result){







                overlay.classList.remove('show');







                okBtn.removeEventListener('click', onOk);







                cancelBtn.removeEventListener('click', onCancel);







                resolve(result);







            }







            function onOk(){ cleanup(true); }







            function onCancel(){ cleanup(false); }







            okBtn.addEventListener('click', onOk);







            cancelBtn.addEventListener('click', onCancel);







        });







    }















    /* ============================================================







       FLIP â€” animazioni fluide su ogni riordino/mutazione del DOM







       ============================================================ */







    function flip(container, mutate){







        const rows = Array.from(container.children);







        const first = new Map();







        rows.forEach(r=> first.set(r, r.getBoundingClientRect()));







        mutate();







        Array.from(container.children).forEach(r=>{







            const f = first.get(r);







            if (!f) return;







            const l = r.getBoundingClientRect();







            const dx = f.left - l.left, dy = f.top - l.top;







            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5){







                r.style.transition = 'none';







                r.style.transform = `translate(${dx}px, ${dy}px)`;







                requestAnimationFrame(()=>{







                    r.style.transition = 'transform .38s var(--spring)';







                    r.style.transform = '';







                });







            }







        });







    }















    /* ============================================================







       CONTATORI / STATO RIGA







       ============================================================ */







    function updateRowCountChip(){







        const n = document.querySelectorAll('#fcRows .fc-row').length;







        const chip = document.getElementById('row-count-chip');







        if (chip) chip.textContent = n + (n === 1 ? ' riga' : ' righe');







    }







    function toggleEmptyHint(){







        const hint = document.getElementById('emptyHint');







        if (hint) hint.style.display = document.getElementById('fcRows').children.length ? 'none' : 'block';







    }







    function updateStatusVisual(row){







        row.classList.remove('status-new', 'status-edited');







        if (row.dataset.status === 'new') row.classList.add('status-new');







        else if (row.dataset.status === 'edited') row.classList.add('status-edited');







    }

    function markEdited(el){
        const row = el.classList && el.classList.contains('fc-row') ? el : el.closest('.fc-row');
        if (!row) return;
        if (row.dataset.status === 'clean') row.dataset.status = 'edited';
        updateStatusVisual(row);
        isDirty = true;
        updateDirtyUI();
    }

    // Intercetta qualsiasi digitazione o cambio nei campi di testo
    document.getElementById('fcRows').addEventListener('input', function(e) {
        markEdited(e.target);
    });
    
    document.getElementById('fcRows').addEventListener('change', function(e) {
        markEdited(e.target);
    });

    /* ============================================================
       MOTORE PREVIEW / AUTOGROW
       (nomi dedicati per non entrare in conflitto con examples_modal.php,
       che definisce le sue proprie applyFormat/updatePreview globali)
       ============================================================ */

    let mathJaxTimeouts = {};







    







    















    















    /* ============================================================







       CHIP ESEMPI â€” si aggancia a examples_modal.php







       IMPORTANTE: examples_modal.php, dopo un salvataggio/eliminazione di un







       esempio, sovrascrive DIRETTAMENTE innerText + style.background/color/







       borderColor del bottone #btn-ex-{id}. Per restare sempre coerenti anche







       dopo quell'aggiornamento, qui usiamo lo STESSO identico testo/colori







       che usa quello script: la classe .ex-chip gestisce solo forma e







       animazione, mai i colori.







       ============================================================ */







    function exChipHTML(domId, dbId, isNew, exCount){

        if (isNew){

            return `<button type="button" class="ex-chip ex-disabled" style="background:var(--surface-3); color:var(--text-3); border:1.5px solid transparent;"

                onclick="showToast('Salva la tabella per generare un ID a questa carta e poter aggiungere esempi.')">🔒 Esempi</button>`;

        }

        const hasEx = exCount > 0;

        const bg = hasEx ? '#8e24aa' : '#f1f3f5';

        const color = hasEx ? '#fff' : '#555';

        const border = hasEx ? '#8e24aa' : '#ccc';

        const label = hasEx ? `💡 Esempi (${exCount})` : '➕ Aggiungi Esempio';

        

        // Il suffisso _hidden è stato rimosso per puntare ai campi corretti della toolbar

        return `<button type="button" id="btn-ex-${dbId}" class="ex-chip" style="background:${bg}; color:${color}; border:1.5px solid ${border};"

            onclick="openExamplesModal(${dbId}, document.getElementById('q-desc-${domId}').value, document.getElementById('a-desc-${domId}').value)">${label}</button>`;

    }







</script>







<script>







    function fieldTemplate(prefix, id, text, label, imgUrl, imgName, audioUrl, isChild){

        const taId = `${prefix}-desc-${id}`;

        const imgPrevId = `${prefix}-img-preview-${id}`;

        const placeholder = prefix === 'q'

            ? (isChild ? '↳ Sottodomanda (Figlio)…' : 'Domanda / LaTeX…')

            : 'Risposta / LaTeX…';

        const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

        const audioClass = audioUrl ? 'audio-player has-audio' : 'audio-player';

        const audioSrc = audioUrl ? ` src="${escAttr(audioUrl)}"` : '';



        // 1. Preleviamo l'HTML base generato dal tuo format_toolbar.php

        let editorHtml = document.getElementById('editor-template-container').innerHTML;

        

        // 2. Sostituiamo i segnaposto con i dati veri di questa specifica flashcard.

        // Nota: usiamo () => escAttr(text) per evitare che i simboli '$' (usati 

        // in LaTeX) distruggano le regex di JavaScript.

        editorHtml = editorHtml

            .replace(/%%FIELD_ID%%/g, () => taId)

            .replace(/%%RAW_TEXT%%/g, () => escAttr(text))

            .replace(/%%PLACEHOLDER%%/g, () => placeholder);



        // 3. Ritorniamo il blocco pulito

        return `

        <div class="fc-field">

            <div class="field-label ${prefix === 'a' ? 'is-answer' : ''}">${label}</div>

            

            ${editorHtml}



            <div class="media-row">

                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="${prefix}-chip-${id}">

                    <input type="file" accept="image/*" onchange="markEdited(this); previewRowImage(this, '${prefix}', '${id}')">

                    <span class="chip-thumb" id="${imgPrevId}">${thumb}</span>

                    <span class="img-name-tag">${imgName ? escAttr(imgName) : 'Immagine'}</span>

                </label>

                <button type="button" class="media-chip btn-rec" onclick="toggleRowRecord(this, '${prefix}')">

                    <span class="chip-thumb"><span class="rec-dot"></span></span>

                    <span class="rec-label">Registra</span>

                </button>

                <audio class="${audioClass} audio-player-${prefix}" controls${audioSrc}></audio>

            </div>

            <input type="hidden" class="${prefix}-img-name" value="${imgName ? escAttr(imgName) : ''}">

        </div>`;

    }















    function appendRowHTML(card, referenceNode){







        const list = document.getElementById('fcRows');







        const isChild = !!card.isChild;







        const wrap = document.createElement('div');







        wrap.className = 'fc-row data-row' + (isChild ? ' is-child' : '');







        wrap.dataset.rid = card.domId;







        wrap.dataset.id = card.dbId;







        wrap.dataset.status = card.status;







        wrap.dataset.origType = card.origType;







        wrap.dataset.cascade = '';







        wrap.innerHTML = `







            <div class="swipe-actions">







                <button type="button" class="swipe-btn swipe-btn-delete" onclick="swipeDeleteRow(this)">${ICON_TRASH}<span>Elimina</span></button>







                <button type="button" class="swipe-btn swipe-btn-join" onclick="swipeJoinRow(this)">${ICON_LINK}<span>Figlio</span></button>







                <button type="button" class="swipe-btn swipe-btn-unjoin" onclick="swipeUnjoinRow(this)">${ICON_UNLINK}<span>Sgancia</span></button>







            </div>







            <div class="fc-row-content">







                <div class="deleted-stub">







                    <span class="stub-icon">${ICON_TRASH_SM}</span>







                    <span class="stub-text"></span>







                    <button type="button" class="btn-restore" onclick="onRestoreClick(this)">${ICON_UNDO}<span>Ripristina</span></button>







                </div>







                <div class="fc-row-strip">







                    <span class="grip">${ICON_GRIP}</span>







                    <span class="row-badge">${isChild ? 'â†³ Figlio' : 'Concetto'}</span>







                    <span class="status-badge sb-new">Nuova</span>







                    <span class="status-badge sb-edited">Modificata</span>







                    <span class="strip-spacer"></span>







                    ${exChipHTML(card.domId, card.dbId, card.status === 'new', card.exCount || 0)}







                    <span class="strip-hint">${ICON_REORDER}<span>Tieni premuto</span></span>







                </div>







                <div class="fc-row-grid">







                    ${fieldTemplate('q', card.domId, card.q, 'Domanda', card.qImgUrl, card.qImgName, card.qAudioUrl, isChild)}







                    ${fieldTemplate('a', card.domId, card.a, 'Risposta', card.aImgUrl, card.aImgName, card.aAudioUrl, isChild)}







                </div>







            </div>`;







        if (referenceNode) list.insertBefore(wrap, referenceNode); else list.appendChild(wrap);







        if (referenceNode) list.insertBefore(wrap, referenceNode); else list.appendChild(wrap);







        // INIZIALIZZA I NUOVI EDITOR DELLA RIGA



        wrap.querySelectorAll('.fmt-editor-wrap').forEach(function(editorWrap) {



            if (typeof fcInitEditorWrap === 'function') fcInitEditorWrap(editorWrap);



        });







        updateStatusVisual(wrap);







        updateRowCountChip();







        toggleEmptyHint();







        return wrap;







    }















    















    function previewRowImage(input, type, id){







        const file = input.files[0];







        const thumb = document.getElementById(`${type}-img-preview-${id}`);







        const chip = document.getElementById(`${type}-chip-${id}`);







        const nameTag = chip.querySelector('.img-name-tag');







        const row = input.closest('.fc-row');















        if (file){







            const url = URL.createObjectURL(file);







            thumb.innerHTML = `<img src="${url}">`;







            chip.classList.add('has-img');







            if (nameTag) nameTag.textContent = file.name;







            if (type === 'q') row.qNewImgFile = file; else row.aNewImgFile = file;







        } else {







            thumb.innerHTML = ICON_IMG;







            chip.classList.remove('has-img');







            if (nameTag) nameTag.textContent = 'Immagine';







            if (type === 'q') delete row.qNewImgFile; else delete row.aNewImgFile;







        }







    }















    /* ============================================================







       REGISTRAZIONE AUDIO







       ============================================================ */







    let currentRecorder = null;







    let currentBtn = null;







    let currentChunks = [];







    let recTimerInterval = null;







    let recStartTime = 0;















    function startRecTimer(btn){







        recStartTime = Date.now();







        clearInterval(recTimerInterval);







        recTimerInterval = setInterval(()=>{







            const s = Math.floor((Date.now() - recStartTime) / 1000);







            const mm = Math.floor(s / 60), ss = String(s % 60).padStart(2, '0');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = mm + ':' + ss;







        }, 500);







    }







    function stopRecTimer(){ clearInterval(recTimerInterval); recTimerInterval = null; }















    async function toggleRowRecord(btn, type){







        if (btn.classList.contains('recording')) {







            currentRecorder.stop();







            stopRecTimer();







            btn.classList.remove('recording');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = 'Registra';







            return;







        }







        if (currentRecorder) { showToast('Ferma prima la registrazione in corso.', 'error'); return; }















        try {







            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });







            currentRecorder = new MediaRecorder(stream);







            currentChunks = [];







            currentBtn = btn;















            currentRecorder.ondataavailable = e=>{ if (e.data.size > 0) currentChunks.push(e.data); };







            currentRecorder.onstop = () => {







                const row = currentBtn.closest('.fc-row');







                const mimeType = currentRecorder.mimeType || 'audio/webm';







                const blob = new Blob(currentChunks, { type: mimeType });







                const ext = mimeType.includes('mp4') ? 'm4a' : 'webm';







                const player = row.querySelector('.audio-player-' + type);















                if (player) { player.src = URL.createObjectURL(blob); player.classList.add('has-audio'); }







                if (type === 'q') { row.qAudioBlob = blob; row.qAudioExt = ext; }







                else { row.aAudioBlob = blob; row.aAudioExt = ext; }















                markEdited(row);







                stream.getTracks().forEach(t=> t.stop());







                currentRecorder = null;







            };















            currentRecorder.start();







            btn.classList.add('recording');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = '0:00';







            startRecTimer(btn);







            buzz(10);







        } catch (err) {







            showToast('Impossibile accedere al microfono.', 'error');







        }







    }







</script>







<script>







    /* ============================================================







       BADGE / STATO FIGLIO / SWIPE ACTIONS







       ============================================================ */







    function updateRowBadgeText(row){







        const badge = row.querySelector('.row-badge');







        if (badge) badge.textContent = isChildRow(row) ? 'â†³ Figlio' : 'Concetto';







    }















    function closeSwipe(row){







        if (!row) return;







        const content = row.querySelector('.fc-row-content');







        content.style.transition = 'transform .32s var(--snap)';







        content.style.transform = 'translateX(0px)';







        row._swipeX = 0;







    }







    function closeAllSwipes(except){







        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







            if (r !== except && r._swipeX) closeSwipe(r);







        });







    }















    /* ============================================================







       ELIMINAZIONE TEMPORANEA (con cascata) / RIPRISTINO







       Le righe "nuove" (mai salvate) vengono rimosse subito per davvero.







       Le righe esistenti diventano uno "stub" compatto finchÃ© non si salva.







       ============================================================ */







    function collapseToStub(row){







        row.classList.add('is-deleted');







        closeSwipe(row);







        const stub = row.querySelector('.stub-text');







        const qVal = (row.querySelector('.q-desc') || {}).value || '';







        if (stub) stub.textContent = qVal.trim() ? qVal.trim() : '(vuota)';







    }















    function hardRemoveRow(row){







        row.style.transition = 'transform .22s var(--spring), opacity .22s var(--spring)';







        row.style.transform = 'translateX(105%) scale(.95)';







        row.style.opacity = '0';







        row.style.pointerEvents = 'none';







        setTimeout(()=>{ row.remove(); updateRowCountChip(); toggleEmptyHint(); }, 220);







    }















    function deleteRowSoft(row, cascade){







        const wasParent = !isChildRow(row);







        let children = [];







        if (cascade && wasParent) {







            children = getBlockRows(row).slice(1).filter(c => c.dataset.status !== 'deleted');







        }







        if (row.dataset.status === 'new') {







            hardRemoveRow(row);







        } else {







            row.dataset.status = 'deleted';







            updateStatusVisual(row);







            collapseToStub(row);







        }







        children.forEach(child=>{







            child.dataset.cascade = '1';







            deleteRowSoft(child, false);







        });







        isDirty = true;







        updateDirtyUI();







    }















    function restoreRow(row){







        row.dataset.status = 'edited';







        row.classList.remove('is-deleted');







        updateStatusVisual(row);







        if (!isChildRow(row)) {







            let cur = row.nextElementSibling;







            while (cur && isChildRow(cur)) {







                const next = cur.nextElementSibling;







                if (cur.dataset.cascade === '1' && cur.dataset.status === 'deleted') {







                    cur.dataset.cascade = '';







                    restoreRow(cur);







                }







                cur = next;







            }







        }







        isDirty = true;







        updateDirtyUI();







    }















    function swipeDeleteRow(btn){







        const row = btn.closest('.fc-row');







        flip(document.getElementById('fcRows'), ()=> deleteRowSoft(row, true));







        updateRowCountChip();







    }







    function onRestoreClick(btn){







        const row = btn.closest('.fc-row');







        flip(document.getElementById('fcRows'), ()=> restoreRow(row));







    }















    function swipeJoinRow(btn){







        const row = btn.closest('.fc-row');







        closeSwipe(row);







        startChildSelection(row);







    }







    function swipeUnjoinRow(btn){







        const row = btn.closest('.fc-row');







        unjoinRow(row);







    }















    function unjoinRow(row){







        row.classList.remove('is-child');







        updateRowBadgeText(row);







        const qArea = row.querySelector('.q-desc');







        if (qArea && qArea.placeholder.indexOf('Sottodomanda') !== -1) qArea.placeholder = 'Domanda / LaTeXâ€¦';















        // Sposta la riga fisicamente alla fine del blocco dei figli attuali,







        // altrimenti "catturerebbe" come propri figli i suoi ex fratelli minori.







        let lastChild = row;







        while (lastChild.nextElementSibling && lastChild.nextElementSibling.classList.contains('is-child')) {







            lastChild = lastChild.nextElementSibling;







        }







        if (lastChild !== row) {







            flip(document.getElementById('fcRows'), ()=> lastChild.after(row));







        }















        closeSwipe(row);







        markEdited(row);







        showToast('Concetto sganciato â€” ora Ã¨ di nuovo un padre indipendente.');







    }















    function markEligibleTargets(){







        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







            r.classList.remove('eligible-target');







            if (r !== childRowPending && !isChildRow(r) && !r.classList.contains('is-deleted')) r.classList.add('eligible-target');







        });







    }















    function startChildSelection(row){







        childRowPending = row;







        isSelectingParent = true;







        closeAllSwipes();







        row.classList.add('pending-child');







        document.getElementById('parent-selection-banner').classList.add('show');







        document.body.classList.add('selecting-mode');







        markEligibleTargets();







    }















    function cancelChildSelection(){







        isSelectingParent = false;







        if (childRowPending) childRowPending.classList.remove('pending-child');







        childRowPending = null;







        document.getElementById('parent-selection-banner').classList.remove('show');







        document.body.classList.remove('selecting-mode');







        document.querySelectorAll('.eligible-target').forEach(r=> r.classList.remove('eligible-target'));







    }















    document.getElementById('fcRows').addEventListener('click', function(e){







        if (!isSelectingParent) return;







        if (suppressNextRowClick) { suppressNextRowClick = false; return; }







        if (e.target.closest('button, input, textarea, a, select')) return;















        const clickedRow = e.target.closest('.fc-row');







        if (!clickedRow || clickedRow === childRowPending) return;















        if (clickedRow.classList.contains('is-deleted')) {







            showToast('Questa riga Ã¨ stata eliminata â€” non puÃ² diventare un padre.', 'error');







            return;







        }







        if (isChildRow(clickedRow)) {







            showToast('Non puoi agganciare un figlio a un altro figlio. Scegli un concetto padre.', 'error');







            return;







        }















        const list = document.getElementById('fcRows');







        const block = getBlockRows(clickedRow);







        const insertAfter = block[block.length - 1];







        const pending = childRowPending;















        flip(list, ()=>{







            insertAfter.after(pending);







            pending.classList.add('is-child');







        });















        updateRowBadgeText(pending);







        const qArea = pending.querySelector('.q-desc');







        if (qArea) qArea.placeholder = 'â†³ Sottodomanda (Figlio)â€¦';







        closeSwipe(pending);







        buzz(14);







        cancelChildSelection();







        markEdited(pending);







        showToast('Concetto agganciato come figlio âœ“');







    });







</script>







<script>















    /* ============================================================







       MOTORE GESTURE â€” swipe orizzontale + riordino a pressione prolungata







       ============================================================ */







    (function(){







        const TAP_SLOP = 9;







        const SWIPE_SLOP = 15;







        const STILL_SLOP = 6;







        const LONG_PRESS_MS = 420;







        let pend = null;















        function eligibleRow(e){







            if (!e.target || !e.target.closest) return null;







            if (e.target.closest('textarea, input, select, button, audio, a')) return null;







            const row = e.target.closest('.fc-row');







            if (!row || !row.closest('#fcRows')) return null;







            if (row.classList.contains('is-deleted')) return null;







            return row;







        }















        document.addEventListener('pointerdown', function(e){







            if (activeDrag || isSelectingParent) return;







            const insideRow = e.target.closest && e.target.closest('.fc-row');







            document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







                if (r._swipeX && r !== insideRow) closeSwipe(r);







            });







        }, true);















        document.addEventListener('pointerdown', function(e){







            if (isSelectingParent) return;







            if (pend || activeDrag) return;







            if (e.pointerType === 'mouse' && e.button !== 0) return;







            const row = eligibleRow(e);







            if (!row) return;







            const content = row.querySelector('.fc-row-content');







            pend = {







                row, content, pointerId: e.pointerId,







                startX: e.clientX, startY: e.clientY,







                lastX: e.clientX, lastY: e.clientY,







                swiping: false, reordering: false,







                longPressTimer: setTimeout(()=>{







                    // Attiva il riordino SOLO se il dito Ã¨ rimasto davvero fermo:







                    // se nel frattempo si Ã¨ mosso (anche solo per iniziare uno scroll







                    // lento), non dobbiamo rubare il gesto alla pagina.







                    if (!pend || pend.swiping) return;







                    const stillDx = Math.abs(pend.lastX - pend.startX);







                    const stillDy = Math.abs(pend.lastY - pend.startY);







                    if (stillDx < STILL_SLOP && stillDy < STILL_SLOP) activateReorder(pend);







                }, LONG_PRESS_MS)







            };







        });















        document.addEventListener('pointermove', function(e){







            if (!pend || e.pointerId !== pend.pointerId) return;







            pend.lastX = e.clientX; pend.lastY = e.clientY;







            if (pend.reordering) { doReorderMove(e); return; }







            if (pend.swiping) { doSwipeMove(e); return; }















            const dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;







            const adx = Math.abs(dx), ady = Math.abs(dy);















            // PrioritÃ  allo scroll nativo: appena il movimento non Ã¨ NETTAMENTE







            // orizzontale, molliamo subito il gesto e lasciamo scorrere la pagina.







            // Un dito vero non Ã¨ mai perfettamente dritto: meglio richiedere uno







            // swipe deciso piuttosto che rubare scroll verticali per errore.







            if (ady > TAP_SLOP && adx < ady * 1.8) {







                clearTimeout(pend.longPressTimer);







                pend = null; // lascia proseguire lo scroll nativo della pagina







                return;







            }







            if (adx > SWIPE_SLOP && adx > ady * 1.8) {







                clearTimeout(pend.longPressTimer);







                pend.swiping = true;







                activateSwipeGesture(pend);







                doSwipeMove(e);







            }







        }, {passive:true});















        document.addEventListener('pointerup', function(e){ finishGesture(e, true); });







        document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });















        function finishGesture(e, committed){







            if (!pend || e.pointerId !== pend.pointerId) return;







            const p = pend; pend = null;







            clearTimeout(p.longPressTimer);







            if (p.reordering) finalizeReorder(p);







            else if (p.swiping) finalizeSwipe(p, committed);







        }















        /* ---- SWIPE ORIZZONTALE ---- */







        function activateSwipeGesture(ctx){







            gestureBlock = true;







            closeAllSwipes(ctx.row);







            ctx.content.style.transition = 'none';







            ctx.maxReveal = ctx.row.querySelector('.swipe-actions').offsetWidth || 172;







            ctx.startTranslate = ctx.row._swipeX || 0;







            document.body.classList.add('no-select');







        }







        function doSwipeMove(e){







            const ctx = pend;







            let x = ctx.startTranslate + (e.clientX - ctx.startX);







            if (x < 0) x = x * 0.15;







            if (x > ctx.maxReveal) x = ctx.maxReveal; // stop netto: mai oltre i pulsanti rivelati







            ctx.content.style.transform = `translateX(${x}px)`;







            ctx.currentX = x;







        }







        function finalizeSwipe(ctx, committed){







            document.body.classList.remove('no-select');







            gestureBlock = false;







            ctx.content.style.transition = 'transform .34s var(--snap)';







            let target = 0;







            if (committed && ctx.currentX !== undefined) target = ctx.currentX > ctx.maxReveal * 0.4 ? ctx.maxReveal : 0;







            ctx.content.style.transform = `translateX(${target}px)`;







            ctx.row._swipeX = target;







            if (target > 0) { suppressNextRowClick = true; setTimeout(()=> suppressNextRowClick = false, 260); buzz(8); }







        }















        /* ---- RIORDINO A PRESSIONE PROLUNGATA ---- */







        function activateReorder(ctx){







            ctx.reordering = true;







            gestureBlock = true;







            buzz(12);







            document.body.classList.add('no-select');















            const row = ctx.row;







            const child = isChildRow(row);







            ctx.blockRows = child ? [row] : getBlockRows(row);







            ctx.scopeParent = child ? getBlockStart(row) : null;















            const list = document.getElementById('fcRows');







            const firstRect = ctx.blockRows[0].getBoundingClientRect();







            const lastRect = ctx.blockRows[ctx.blockRows.length - 1].getBoundingClientRect();







            const totalHeight = lastRect.bottom - firstRect.top;







            const referenceNode = ctx.blockRows[ctx.blockRows.length - 1].nextElementSibling;















            const placeholder = document.createElement('div');







            placeholder.className = 'fc-row-placeholder';







            placeholder.style.height = totalHeight + 'px';







            list.insertBefore(placeholder, referenceNode);







            ctx.placeholder = placeholder;















            const ghost = document.createElement('div');







            ghost.className = 'fc-drag-ghost';







            ghost.style.position = 'fixed';







            ghost.style.left = firstRect.left + 'px';







            ghost.style.top = firstRect.top + 'px';







            ghost.style.width = firstRect.width + 'px';







            ghost.style.zIndex = 9999;







            ghost.style.display = 'flex';







            ghost.style.flexDirection = 'column';







            ghost.style.gap = '14px';







            ctx.blockRows.forEach(r=>{ r.classList.add('is-dragging-row'); ghost.appendChild(r); });







            document.body.appendChild(ghost);







            ctx.ghost = ghost;







            activeDrag = ctx;







        }















        function doReorderMove(e){







            const ctx = pend;







            const dy = e.clientY - ctx.startY;







            ctx.ghost.style.transform = `translateY(${dy}px) scale(1.015)`;







            lastPointerY = e.clientY;







            ensureAutoScroll();







            updateReorderPlacement(ctx, e.clientY);







        }















        function scopeListFor(ctx){







            const list = document.getElementById('fcRows');







            if (ctx.scopeParent){







                const out = [];







                let cur = ctx.scopeParent.nextElementSibling;







                while (cur && (cur.classList.contains('is-child') || cur === ctx.placeholder)) {







                    out.push(cur);







                    cur = cur.nextElementSibling;







                }







                return out;







            }







            return Array.from(list.children).filter(el=> el === ctx.placeholder || !el.classList.contains('is-child'));







        }















        function nodeRect(el, ctx){







            if (el === ctx.placeholder) return el.getBoundingClientRect();







            if (!ctx.scopeParent && !el.classList.contains('is-child')){







                const rows = getBlockRows(el);







                const a = rows[0].getBoundingClientRect();







                const b = rows[rows.length - 1].getBoundingClientRect();







                return { top: a.top, bottom: b.bottom };







            }







            return el.getBoundingClientRect();







        }















        function updateReorderPlacement(ctx, pointerY){







            const list = document.getElementById('fcRows');







            const scope = scopeListFor(ctx);







            const idx = scope.indexOf(ctx.placeholder);







            if (idx < 0) return;















            if (idx > 0){







                const prev = scope[idx - 1];







                const r = nodeRect(prev, ctx);







                const mid = r.top + (r.bottom - r.top) / 2;







                if (pointerY < mid){







                    flip(list, ()=> list.insertBefore(ctx.placeholder, prev));







                    buzz(4);







                    return;







                }







            }







            if (idx < scope.length - 1){







                const next = scope[idx + 1];







                const r = nodeRect(next, ctx);







                const mid = r.top + (r.bottom - r.top) / 2;







                if (pointerY > mid){







                    const afterEl = (!ctx.scopeParent && !next.classList.contains('is-child'))







                        ? getBlockRows(next)[getBlockRows(next).length - 1].nextElementSibling







                        : next.nextElementSibling;







                    flip(list, ()=> list.insertBefore(ctx.placeholder, afterEl));







                    buzz(4);







                    return;







                }







            }







        }















        function finalizeReorder(ctx){







            stopAutoScroll();







            document.body.classList.remove('no-select');







            gestureBlock = false;







            activeDrag = null;















            const list = document.getElementById('fcRows');







            const placeholder = ctx.placeholder;







            flip(list, ()=>{







                ctx.blockRows.forEach(r=>{ r.classList.remove('is-dragging-row'); list.insertBefore(r, placeholder); });







                placeholder.remove();







            });







            ctx.ghost.remove();







            ctx.blockRows.forEach(r=> markEdited(r));







            buzz(10);







        }















        /* ---- AUTO-SCROLL AI BORDI ---- */







        function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }







        function autoScrollLoop(){







            const margin = 110, maxSpeed = 16;







            const vh = window.innerHeight;







            let dy = 0;







            if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);







            else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);







            if (dy && activeDrag) window.scrollBy(0, dy);







            autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;







        }







        function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }















        document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, {passive:false});







    })();







</script>







<script>

    /* ============================================================
       AGGIUNGI RIGA
       ============================================================ */

    function addRow(){
        const domId = Date.now();
        const card = {
            domId, dbId: 0, status: 'new', origType: 'parent', isChild: false,
            q: '', a: '', qImgUrl: '', qImgName: '', aImgUrl: '', aImgName: '',
            qAudioUrl: '', aAudioUrl: '', exCount: 0
        };
        const row = appendRowHTML(card);
        isDirty = true;
        updateDirtyUI();
        setTimeout(()=>{
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const editorDiv = document.getElementById('q-desc-' + domId + '_editor');
            if (editorDiv) editorDiv.focus({ preventScroll: true });
        }, 80);
    }

/* ============================================================
   IDRATAZIONE DATI INIZIALI (con caricamento a blocchi sicuri)
   ============================================================ */
let currentRenderIndex = 0;

function renderNextBatch(amount) {
    let count = 0;
    
    while (currentRenderIndex < INITIAL_CARDS.length) {
        const c = INITIAL_CARDS[currentRenderIndex];
        const id = c.id_fcimg;
        const isChild = !!parseInt(c.is_child);
        
        const card = {
            domId: id, dbId: id, status: 'clean',
            origType: isChild ? 'child' : 'parent',
            isChild,
            q: c.question_desc || '',
            a: c.answer_desc || '',
            qImgUrl: c.question_image ? ('fc_Images/' + c.question_image) : '',
            qImgName: c.question_image || '',
            aImgUrl: c.answer_image ? ('fc_Images/' + c.answer_image) : '',
            aImgName: c.answer_image || '',
            qAudioUrl: c.question_audio ? ('fc_audio/' + c.question_audio) : '',
            aAudioUrl: c.answer_audio ? ('fc_audio/' + c.answer_audio) : '',
            exCount: parseInt(c.ex_count) || 0
        };
        
        appendRowHTML(card);
        
        currentRenderIndex++;
        count++;
        
        // Condizione di stop sicura per non separare padre dai figli
        if (count >= amount) {
            if (currentRenderIndex < INITIAL_CARDS.length) {
                const nextCard = INITIAL_CARDS[currentRenderIndex];
                if (parseInt(nextCard.is_child) !== 1) {
                    break;
                }
            }
        }
    }
    
    const btnContainer = document.getElementById('loadMoreContainer');
    if (btnContainer) {
        btnContainer.style.display = (currentRenderIndex < INITIAL_CARDS.length) ? 'block' : 'none';
    }
}

function hydrateInitialCards(){
    renderNextBatch(50);
}

    /* ============================================================
       USCITA / SALVATAGGIO
       ============================================================ */

    async function goBack(){
        if (isDirty) {
            const ok = await showConfirm(
                'Modifiche non salvate',
                'Hai delle modifiche che non sono ancora state salvate. Se esci ora andranno perse.',
                'Esci senza salvare'
            );
            if (!ok) return;
        }
        window.location.href = 'visualizzazione_fc.php?category_id=' + CAT_ID;
    }

    async function saveAll(){
        try {
            if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();

            // Catturiamo SOLO il bottone in alto
            const hdrBtn = document.getElementById('hdrSaveBtn');

            // ==========================================
            // AUTO-RILEVAMENTO CAMBIAMENTI STRUTTURALI
            // ==========================================
            let haCambiatoOrdine = false;
            const rows = document.querySelectorAll('#fcRows .fc-row.data-row');
            
            // Modifica: usiamo 'currentRenderIndex' per capire se sono state aggiunte/tolte righe
            if (rows.length !== currentRenderIndex) {
                haCambiatoOrdine = true;
            } else {
                rows.forEach((row, i) => {
                    const orig = INITIAL_CARDS[i];
                    const isChildNow = row.classList.contains('is-child') ? 1 : 0;
                    
                    if (!orig || row.dataset.id != orig.id_fcimg || isChildNow != orig.is_child || row.dataset.status === 'deleted') {
                        haCambiatoOrdine = true;
                    }
                });
            }

            const fd = new FormData();
            fd.append('action', 'save_big_edit');
            fd.append('category_id', CAT_ID);
            fd.append('reordered', haCambiatoOrdine ? '1' : '0');

            let currentParentIndex = -1;
            let elementiModificati = 0;
            let indiceInvio = 0;

            rows.forEach((row) => {
                const status = row.dataset.status;
                
                // Saltiamo le righe non toccate se non c'è stato riordino strutturale
                if (!haCambiatoOrdine && status === 'clean') return;

                if (status !== 'clean') elementiModificati++;

                const isChild = row.classList.contains('is-child') ? 1 : 0;
                if (!isChild) currentParentIndex = indiceInvio; 
                
                // RECUPERO CORRETTO DEI CAMPI DI TESTO TRAMITE ID
                const rid = row.dataset.rid;
                const qInput = document.getElementById('q-desc-' + rid);
                const aInput = document.getElementById('a-desc-' + rid);

                fd.append(`cards[${indiceInvio}][id]`, row.dataset.id);
                fd.append(`cards[${indiceInvio}][status]`, status);
                fd.append(`cards[${indiceInvio}][orig_type]`, row.dataset.origType);
                fd.append(`cards[${indiceInvio}][is_child]`, isChild);
                fd.append(`cards[${indiceInvio}][parent_idx]`, currentParentIndex);

                // Se li trova prende il valore, altrimenti stringa vuota per non crashare
                fd.append(`cards[${indiceInvio}][q]`, qInput ? qInput.value : '');
                fd.append(`cards[${indiceInvio}][a]`, aInput ? aInput.value : '');

                if (row.qNewImgFile) fd.append(`row_q_img[${indiceInvio}]`, row.qNewImgFile);
                if (row.aNewImgFile) fd.append(`row_a_img[${indiceInvio}]`, row.aNewImgFile);
                if (row.qAudioBlob) fd.append(`audio_q[${indiceInvio}]`, row.qAudioBlob, `q_${indiceInvio}.${row.qAudioExt}`);
                if (row.aAudioBlob) fd.append(`audio_a[${indiceInvio}]`, row.aAudioBlob, `a_${indiceInvio}.${row.aAudioExt}`);
                
                indiceInvio++;
            });

            if (!haCambiatoOrdine && elementiModificati === 0) {
                showToast('Nessuna modifica da salvare.');
                return;
            }

            // Mostriamo lo spinner SOLO sul bottone in alto
            if (hdrBtn) {
                hdrBtn.disabled = true;
                hdrBtn.innerHTML = '<span class="spinner" style="width:13px; height:13px; border-width:2px; display:inline-block; vertical-align:middle; margin-right:5px;"></span> Salvataggio...';
            }

            const resetButtons = () => {
                if (hdrBtn) {
                    hdrBtn.disabled = false;
                    hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
                }
            };

            const res = await fetch('big_edit_save_fc.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                isDirty = false;
                
                // Fermiamo lo spinner e mostriamo che è finito
                if (hdrBtn) {
                    hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salvato!';
                }
                
                showToast('Salvataggio completato ✔');
                setTimeout(()=> window.location.reload(), 700);
            } else {
                showToast('Errore durante il salvataggio: ' + (data.error || ''), 'error');
                resetButtons();
            }

        } catch (err) {
            // Se c'è un qualsiasi errore JS, ora lo intercettiamo e lo mostriamo
            console.error(err);
            showToast('Errore di sistema: ' + err.message, 'error');
            
            const hdrBtn = document.getElementById('hdrSaveBtn');
            if (hdrBtn) {
                hdrBtn.disabled = false;
                hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
            }
        }
    }

    window.addEventListener('beforeunload', function(e){

        if (isDirty) { e.preventDefault(); e.returnValue = ''; }

    });

    hydrateInitialCards();

    updateRowCountChip();

    toggleEmptyHint();

</script>

<?php include 'examples_modal.php'; ?>

</body>

</html>