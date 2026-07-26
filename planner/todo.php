<?php
/* =====================================================================
   NISAMA — ATTIVITÀ (pagina principale)
   Lo stesso componente che compare dentro i progetti e nel pomodoro,
   qui a tutta pagina e con la modalità seleziona attiva.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
date_default_timezone_set('Europe/Rome');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FDFCF9">
<title>Attività</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Jost:wght@300;400;500&display=swap');
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; text-size-adjust:100%; background:#F4EFE9; }
body{
    margin:0; min-height:100dvh; background:#F4EFE9; color:#4A443D;
    font-family:'Jost', -apple-system, system-ui, sans-serif; font-size:15px; line-height:1.45;
    -webkit-font-smoothing:antialiased;
    padding:calc(env(safe-area-inset-top, 0px) + 18px) 16px calc(env(safe-area-inset-bottom, 0px) + 40px);
}
.tp-sheet{
    max-width:680px; margin:0 auto; background:#FDFCF9; border-radius:26px;
    padding:22px 18px 26px;
    box-shadow:0 2px 4px rgba(74,68,61,.05), 0 22px 50px -26px rgba(74,68,61,.35);
}
.tp-head{ display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:4px; }
.tp-title{ font-family:'Cormorant Garamond', Georgia, serif; font-size:34px; font-weight:400; line-height:1; }
.tp-sub{
    font-size:9.5px; letter-spacing:.2em; text-transform:uppercase; color:#B7AEA3;
}
.tp-back{
    display:inline-flex; align-items:center; gap:6px; margin-bottom:16px;
    color:#8A8177; text-decoration:none; font-size:13px;
}
.tp-back svg{ width:15px; height:15px; }
.tp-note{
    font-family:'Cormorant Garamond', serif; font-style:italic; font-size:15px;
    color:#8A8177; margin:2px 0 16px; line-height:1.5;
}
</style>
</head>
<body>

<a class="tp-back" href="planner.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
    <span>Planner</span>
</a>

<main class="tp-sheet">
    <div class="tp-head">
        <span class="tp-title">Attività</span>
        <span class="tp-sub">to do</span>
    </div>
    <p class="tp-note">
        Le attività con una data finiscono anche nel planner.
        Quelle spuntate spariscono da sole il giorno dopo.
    </p>
    <div id="tpList"></div>
</main>

<?php
/* selettore progetti condiviso, usato dal componente attività */
$ps_id = 'todo'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_placeholder = 'Nessun progetto'; $ps_title = 'Collega a un progetto';
$ps_show_all_option = true; $ps_all_option_label = 'Nessun progetto'; $ps_allow_create = false;
$ps_hidden_name = 'todo_project_id';
include 'project_selector.php';
/* del selettore ci serve solo il popup: il pulsante inline qui non ha senso,
   il progetto si sceglie dalla riga dell'attività */
echo '<style>#ps-widget-todo{ display:none; }</style>';

include 'todo_widget.php';
?>
<script>
nisamaTodo.mount(document.getElementById('tpList'), {
    scope: 'all',
    selectable: true
});
</script>
</body>
</html>