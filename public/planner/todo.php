<?php
/* =====================================================================
   STANDOUT — ATTIVITÀ (pagina principale)
   Lo stesso componente che compare dentro i progetti e nel pomodoro,
   qui a tutta pagina e con la modalità seleziona attiva.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/stile.php';   // standard | paper (Diario, Planner, To do, To study)

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
date_default_timezone_set('Europe/Rome');

/* Su quale elenco aprire. Arriva da fuori — dal pannello settimana del
   planner, che manda la linguetta che si stava guardando quando si è toccato
   «apri»: ritrovarsi sul to do dopo aver chiesto di aprire le pastiglie è un
   salto che non ha chiesto nessuno. Vale l'ultima usata se non arriva niente,
   e il valore si rivalida qui come qualunque cosa venga dal browser. */
$tab = sec_enum(sec_in('tab'), ['todo', 'buy', 'study', 'pills'], '');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>" <?php echo standout_stile_attr(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FDFCF9">
<title><?php te('todo.titolo'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: time(); ?>"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Jost:wght@300;400;500&display=swap">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>planner/todo.css?v=<?php echo @filemtime(__DIR__ . '/todo.css') ?: 1; ?>">
<?php echo standout_stile_css(); ?>
</head>
<body class="tp-body">

<div class="tp-top">
<a class="tp-back" href="planner.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
    <span><?php te('todo.planner'); ?></span>
</a>
<?php /* pallino del profilo, in fila con il ritorno al planner */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
</div>

<main class="tp-sheet">
    <div class="tp-head">
        <span class="tp-title"><?php te('todo.titolo'); ?></span>
        <span class="tp-sub"><?php te('todo.sottotitolo'); ?></span>
    </div>
    <p class="tp-note">
        <?php te('todo.nota'); ?>
    </p>
    <div id="tpList"></div>
</main>

<?php
/* selettore progetti condiviso, usato dal componente attività */
$ps_id = 'todo'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_placeholder = t('todo.nessun_progetto'); $ps_title = t('todo.collega_progetto');
$ps_show_all_option = true; $ps_all_option_label = t('todo.nessun_progetto'); $ps_allow_create = false;
$ps_hidden_name = 'todo_project_id';
include __DIR__ . '/../projects/project_selector.php';
/* del selettore ci serve solo il popup: il pulsante inline qui non ha senso,
   il progetto si sceglie dalla riga dell'attività */
/* la regola sta in todo.css: uno <style> stampato da PHP e' comunque uno stile
   inline, quindi bloccherebbe la CSP esattamente come gli altri */

include __DIR__ . '/todo_widget.php';
?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<?php echo standout_i18n_json(['comune', 'todo']); ?>
<script>
standoutTodo.mount(document.getElementById('tpList'), {
    scope: 'all',
    selectable: true,
    tabs: true,         // to do / to buy / to study / pastiglie
    tab: <?php echo json_encode($tab); ?>   // vuoto = l'ultima usata
});
</script>
</body>
</html>