<?php
require_once 'config.php';
require_once 'security_core.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* ==========================================================================
   NOTA SCHEMA: questa pagina usa due colonne in più rispetto allo script SQL
   iniziale. Se non le hai ancora eseguite:

   ALTER TABLE pomodoro_sessioni ADD COLUMN esito VARCHAR(20) NULL DEFAULT NULL AFTER data_fine;
   -- valori usati: NULL (ancora attiva/in corso), 'completato' (finita da sola),
   -- 'interrotto' (fermata a mano), 'pausa' (in pausa a metà, congelata e riprendibile
   --  SENZA creare una nuova riga: la stessa sessione resta "aperta", data_fine resta
   --  NULL finché non viene davvero terminata)

   ALTER TABLE pomodoro_sessioni ADD COLUMN data_ripresa DATETIME NULL DEFAULT NULL AFTER esito;
   -- timestamp dell'ultima "Riprendi" dopo una pausa: se NULL, il segmento attivo
   -- corrente è iniziato a data_inizio; altrimenti è iniziato a data_ripresa.
   -- durata_secondi accumula i secondi di TUTTI i segmenti attivi già conclusi
   -- (aggiornato ad ogni pausa/stop/completamento), cioè il tempo di lavoro reale,
   -- al netto delle pause — è quello che si somma per i totali per progetto.
   ========================================================================== */

if (!function_exists('pomo_json')) { function pomo_json($arr) { header('Content-Type: application/json'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit(); } }

/* ==========================================================================
   AJAX ACTIONS
   ========================================================================== */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    /* Le sessioni si avviano, si fermano e si cancellano solo con il token
       della sessione corrente: senza, la richiesta viene rifiutata. */
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        sec_guard_write((int)($utente_corrente['id'] ?? 0), 'pomodoro', 0, 300, 60);
    }

    if ($ajax === 'start') {
        $mode = (intval($_POST['mode'] ?? 0) === 1) ? 1 : 0;
        $durataMin = $mode === 0 ? max(1, intval($_POST['durata_minuti'] ?? 25)) : null;
        $progettoId = intval($_POST['project_id'] ?? 0);

        if ($progettoId > 0) {
            $chk = mysqli_query($conn, "SELECT id FROM projects WHERE id=$progettoId AND user_id=" . intval($current_user_id));
            if (!$chk || mysqli_num_rows($chk) === 0) $progettoId = 0;
        }
        $progSql = $progettoId > 0 ? $progettoId : 'NULL';
        $durSql = $durataMin !== null ? $durataMin : 'NULL';

        mysqli_query($conn, "INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, project_id, planned_duration_min, duration_seconds)
                              VALUES (" . intval($current_user_id) . ", $mode, NOW(), $progSql, $durSql, 0)");
        $newId = mysqli_insert_id($conn);

        // ricorda ultima modalità/durata scelta
        $durForSettings = $durataMin ?: 25;
        mysqli_query($conn, "INSERT INTO pomodoro_settings (user_id, last_mode, last_pomodoro_duration_min)
                              VALUES (" . intval($current_user_id) . ", $mode, $durForSettings)
                              ON DUPLICATE KEY UPDATE last_mode=$mode" . ($mode === 0 ? ", last_pomodoro_duration_min=$durForSettings" : ""));

        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, start_time FROM pomodoro_sessions WHERE id=$newId"));
        pomo_json(['success' => true, 'session_id' => (int)$newId, 'data_inizio' => $row['start_time']]);
    }

    if ($ajax === 'set_project') {
        $sid = intval($_POST['session_id'] ?? 0);
        $newProjectId = intval($_POST['project_id'] ?? 0);
        if ($newProjectId > 0) {
            $chk = mysqli_query($conn, "SELECT color FROM projects WHERE id=$newProjectId AND user_id=" . intval($current_user_id));
            $chkRow = $chk ? mysqli_fetch_assoc($chk) : null;
            if (!$chkRow) { pomo_json(['success' => false]); }
            mysqli_query($conn, "UPDATE pomodoro_sessions SET project_id=$newProjectId WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND end_time IS NULL");
            pomo_json(['success' => true, 'color' => pomo_color($chkRow['color'])]);
        } else {
            mysqli_query($conn, "UPDATE pomodoro_sessions SET proyecto_id=NULL WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND end_time IS NULL");
            pomo_json(['success' => true, 'color' => '']);
        }
    }

    if ($ajax === 'pausa_task') {
        $sid = intval($_POST['session_id'] ?? 0);
        $tempoEsatto = floatval($_POST['tempo_esatto'] ?? 0);
        
        mysqli_query($conn, "UPDATE pomodoro_sessions
                              SET duration_seconds = $tempoEsatto,
                                  status = 'pausa', resume_time = NULL
                              WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND end_time IS NULL");
                              
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT duration_seconds FROM pomodoro_sessions WHERE id=$sid"));
        pomo_json(['success' => true, 'durata_secondi' => $row ? (int)$row['duration_seconds'] : 0]);
    }

    if ($ajax === 'riprendi_task') {
        $sid = intval($_POST['session_id'] ?? 0);
        mysqli_query($conn, "UPDATE pomodoro_sessions SET status=NULL, resume_time=NOW() WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND end_time IS NULL AND status='pausa'");
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT resume_time FROM pomodoro_sessions WHERE id=$sid"));
        pomo_json(['success' => true, 'data_ripresa' => $row ? $row['resume_time'] : date('Y-m-d H:i:s')]);
    }

    if ($ajax === 'interrompi') {
        $sid = intval($_POST['session_id'] ?? 0);
        $elimina = intval($_POST['elimina'] ?? 0) === 1;
        if ($elimina) {
            mysqli_query($conn, "DELETE FROM pomodoro_sessions WHERE id=$sid AND user_id=" . intval($current_user_id));
        } else {
            mysqli_query($conn, "UPDATE pomodoro_sessions
                                SET duration_seconds = COALESCE(duration_seconds,0) + CASE WHEN status='pausa' THEN 0 ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(resume_time, start_time), NOW())) END,
                                    end_time=NOW(), 
                                    status='interrotto',
                                    resume_time = NULL
                                WHERE id=$sid AND user_id=" . intval($current_user_id));
        }
        pomo_json(['success' => true]);
    }

    if ($ajax === 'segna_completato') {
        $sid = intval($_POST['session_id'] ?? 0);
        mysqli_query($conn, "UPDATE pomodoro_sessions SET end_time = DATE_ADD(COALESCE(resume_time, start_time), INTERVAL ((planned_duration_min * 60) - COALESCE(duration_seconds, 0)) SECOND), status='completato', duration_seconds = planned_duration_min * 60, resume_time = NULL WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND end_time IS NULL");
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT end_time FROM pomodoro_sessions WHERE id=$sid"));
        pomo_json(['success' => true, 'data_fine' => $row ? $row['end_time'] : null]);
    }

    if ($ajax === 'estendi') {
        $sid = intval($_POST['session_id'] ?? 0);
        mysqli_query($conn, "UPDATE pomodoro_sessions
                              SET duration_seconds = COALESCE(duration_seconds,0) + GREATEST(0, TIMESTAMPDIFF(SECOND, end_time, NOW())), end_time=NOW()
                              WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND status='completato'");
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT end_time, duration_seconds FROM pomodoro_sessions WHERE id=$sid"));
        pomo_json(['success' => true, 'data_fine' => $row ? $row['end_time'] : null, 'durata_secondi' => $row ? (int)$row['duration_seconds'] : 0]);
    }

    if ($ajax === 'archivia_completato') {
        $sid = intval($_POST['session_id'] ?? 0);
        mysqli_query($conn, "UPDATE pomodoro_sessions SET status='archiviato' WHERE id=$sid AND user_id=" . intval($current_user_id) . " AND status='completato'");
        pomo_json(['success' => true]);
    }

    if ($ajax === 'avvia_pausa') {
        $progettoId = intval($_POST['project_id'] ?? 0);
        $durataMin = max(1, intval($_POST['durata_minuti'] ?? 5));
        $progSql = $progettoId > 0 ? $progettoId : 'NULL';
        mysqli_query($conn, "INSERT INTO pomodoro_current_breaks (user_id, start_time, planned_duration_min, project_id)
                              VALUES (" . intval($current_user_id) . ", NOW(), $durataMin, $progSql)
                              ON DUPLICATE KEY UPDATE start_time=NOW(), planned_duration_min=$durataMin, project_id=$progSql");
        mysqli_query($conn, "UPDATE pomodoro_settings SET last_break_duration_min=$durataMin WHERE user_id=" . intval($current_user_id));
        pomo_json(['success' => true]);
    }

    if ($ajax === 'fine_pausa') {
        mysqli_query($conn, "DELETE FROM pomodoro_current_breaks WHERE user_id=" . intval($current_user_id));
        pomo_json(['success' => true]);
    }

    if ($ajax === 'salva_preset') {
        $tipo = ($_POST['tipo'] ?? '') === 'pausa' ? 'pausa' : 'pomodoro';
        $minuti = max(1, intval($_POST['minuti'] ?? 0));
        $tipoEsc = mysqli_real_escape_string($conn, $tipo);
        mysqli_query($conn, "INSERT IGNORE INTO pomodoro_custom_durations (user_id, type, minutes) VALUES (" . intval($current_user_id) . ", '$tipoEsc', $minuti)");
        pomo_json(['success' => true]);
    }

    if ($ajax === 'elimina_preset') {
        $tipo = ($_POST['tipo'] ?? '') === 'pausa' ? 'pausa' : 'pomodoro';
        $minuti = max(1, intval($_POST['minuti'] ?? 0));
        $tipoEsc = mysqli_real_escape_string($conn, $tipo);
        mysqli_query($conn, "DELETE FROM pomodoro_custom_durations WHERE user_id=" . intval($current_user_id) . " AND type='$tipoEsc' AND minutes=$minuti");

        // se la durata eliminata era quella "ultima usata" salvata per l'utente, la
        // riportiamo al default di fabbrica (25 pomodoro / 5 pausa): altrimenti al
        // prossimo caricamento della pagina ricompariva un valore ormai eliminato
        $defaultMin = ($tipo === 'pausa') ? 5 : 25;
        $col = ($tipo === 'pausa') ? 'last_break_duration_min' : 'last_pomodoro_duration_min';
        mysqli_query($conn, "UPDATE pomodoro_settings SET $col = $defaultMin WHERE user_id=" . intval($current_user_id) . " AND $col = $minuti");

        pomo_json(['success' => true]);
    }

    if ($ajax === 'set_last_duration') {
        // salva subito la durata scelta come preferenza, senza aspettare l'avvio di
        // una sessione: appena selezioni un valore nel popup, resta lì anche dopo un refresh
        $tipo = ($_POST['tipo'] ?? '') === 'pausa' ? 'pausa' : 'pomodoro';
        $minuti = max(1, intval($_POST['minuti'] ?? 0));
        $col = ($tipo === 'pausa') ? 'last_break_duration_min' : 'last_pomodoro_duration_min';
        mysqli_query($conn, "UPDATE pomodoro_settings SET $col = $minuti WHERE user_id=" . intval($current_user_id));
        pomo_json(['success' => true]);
    }

    if ($ajax === 'set_display_style') {
        $stile = ($_POST['stile'] ?? '') === 'clessidra' ? 'clessidra' : 'cerchio';
        $stileEsc = mysqli_real_escape_string($conn, $stile);
        mysqli_query($conn, "UPDATE pomodoro_settings SET last_display_style='$stileEsc' WHERE user_id=" . intval($current_user_id));
        pomo_json(['success' => true]);
    }

    pomo_json(['success' => false, 'error' => 'azione sconosciuta']);
}

/* ==========================================================================
   IMPOSTAZIONI UTENTE (crea riga default se non esiste)
   ========================================================================== */
$impRes = mysqli_query($conn, "SELECT * FROM pomodoro_settings WHERE user_id=" . intval($current_user_id));
$imp = $impRes ? mysqli_fetch_assoc($impRes) : null;
if (!$imp) {
    mysqli_query($conn, "INSERT INTO pomodoro_settings (user_id) VALUES (" . intval($current_user_id) . ")");
    $imp = ['last_mode' => 0, 'last_pomodoro_duration_min' => 25, 'last_break_duration_min' => 5, 'last_display_style' => 'cerchio'];
}

$presetsPomodoro = [25, 50];
$presetsPausa = [5, 10];
$cpRes = mysqli_query($conn, "SELECT type, minutes FROM pomodoro_custom_durations WHERE user_id=" . intval($current_user_id));
while ($cpRes && ($cpRow = mysqli_fetch_assoc($cpRes))) {
    if ($cpRow['type'] === 'pomodoro' && !in_array((int)$cpRow['minutes'], $presetsPomodoro)) $presetsPomodoro[] = (int)$cpRow['minutes'];
    if ($cpRow['type'] === 'pausa' && !in_array((int)$cpRow['minutes'], $presetsPausa)) $presetsPausa[] = (int)$cpRow['minutes'];
}
sort($presetsPomodoro); sort($presetsPausa);

/* ==========================================================================
   STATO ATTUALE
   ========================================================================== */
function pomo_color($hex) { 
    $hex = trim($hex ?? '');
    return (!empty($hex) && (preg_match('/^#([0-9a-fA-F]{3,8})$/', $hex) || strpos($hex, 'rgb') === 0)) ? $hex : ''; 
}

$activeRes = mysqli_query($conn, "SELECT s.*, p.name AS pn, p.color AS pcolor FROM pomodoro_sessions s LEFT JOIN projects p ON p.id=s.project_id AND p.user_id=s.user_id WHERE s.user_id=" . intval($current_user_id) . " AND s.end_time IS NULL ORDER BY s.id DESC LIMIT 1");
$activeSession = $activeRes ? mysqli_fetch_assoc($activeRes) : null;

$pausaRes = mysqli_query($conn, "SELECT pc.*, p.name AS pn, p.color AS pcolor FROM pomodoro_current_breaks pc LEFT JOIN projects p ON p.id=pc.project_id AND p.user_id=pc.user_id WHERE pc.user_id=" . intval($current_user_id));
$pausaAttiva = $pausaRes ? mysqli_fetch_assoc($pausaRes) : null;

$lastSession = null;
if (!$activeSession && !$pausaAttiva) {
    $lastRes = mysqli_query($conn, "SELECT s.*, p.name AS pn, p.color AS pcolor FROM pomodoro_sessions s LEFT JOIN projects p ON p.id=s.project_id AND p.user_id=s.user_id WHERE s.user_id=" . intval($current_user_id) . " ORDER BY s.id DESC LIMIT 1");
    $lastSession = $lastRes ? mysqli_fetch_assoc($lastRes) : null;
    if ($lastSession && $lastSession['status'] !== 'completato') { $lastSession = null; }
}

$preselectProjectId = intval($_GET['project_id'] ?? 0);
$preselectInfo = null;
if ($preselectProjectId > 0) {
    $ppr = mysqli_query($conn, "SELECT id, name, color FROM projects WHERE id=$preselectProjectId AND user_id=" . intval($current_user_id));
    $preselectInfo = $ppr ? mysqli_fetch_assoc($ppr) : null;
}

/* richiesta 17: mappa id -> catena antenati (max 3: nonno, padre, progetto)
   per mostrare "nonno • padre • progetto" nel tag del progetto collegato */
$pomoProjMap = [];
$pmRes = mysqli_query($conn, "SELECT id, name, parent_id FROM projects WHERE user_id = " . intval($current_user_id));
while ($pmRes && ($pmRow = mysqli_fetch_assoc($pmRes))) {
    $pomoProjMap[(int)$pmRow['id']] = ['name' => $pmRow['name'], 'parent' => $pmRow['parent_id'] === null ? 0 : (int)$pmRow['parent_id']];
}
$pomoProjPaths = [];
foreach ($pomoProjMap as $pid => $info) {
    $chain = []; $cur = $pid; $guard = 0;
    while ($cur > 0 && isset($pomoProjMap[$cur]) && $guard < 40) {
        array_unshift($chain, $pomoProjMap[$cur]['name']);
        $cur = $pomoProjMap[$cur]['parent'];
        $guard++;
    }
    $pomoProjPaths[$pid] = array_slice($chain, -3);
}

/* dati js: stato iniziale */
$jsState = [
    'active' => $activeSession ? [
        'id' => (int)$activeSession['id'], 'mode' => (int)$activeSession['mode'],
        'data_inizio' => $activeSession['start_time'], 'durata_prevista_minuti' => $activeSession['planned_duration_min'] !== null ? (int)$activeSession['planned_duration_min'] : null,
        'project_id' => $activeSession['project_id'] !== null ? (int)$activeSession['project_id'] : 0,
        'progetto_nome' => $activeSession['pn'], 'progetto_colore' => pomo_color($activeSession['pcolor']),
        'esito' => $activeSession['status'], 'durata_secondi' => (int)($activeSession['duration_seconds'] ?? 0),
        'data_ripresa' => $activeSession['resume_time'],
    ] : null,
    'pausa' => $pausaAttiva ? [
        'ora_inizio' => $pausaAttiva['start_time'], 'durata_prevista_minuti' => (int)$pausaAttiva['planned_duration_min'],
        'project_id' => $pausaAttiva['project_id'] !== null ? (int)$pausaAttiva['project_id'] : 0,
        'progetto_nome' => $pausaAttiva['pn'], 'progetto_colore' => pomo_color($pausaAttiva['pcolor']),
    ] : null,
    'last' => $lastSession ? [
        'id' => (int)$lastSession['id'], 'mode' => (int)$lastSession['mode'], 'esito' => $lastSession['status'],
        'data_inizio' => $lastSession['start_time'], 'data_fine' => $lastSession['end_time'],
        'durata_prevista_minuti' => $lastSession['planned_duration_min'] !== null ? (int)$lastSession['planned_duration_min'] : null,
        'project_id' => $lastSession['project_id'] !== null ? (int)$lastSession['project_id'] : 0,
        'progetto_nome' => $lastSession['pn'], 'progetto_colore' => pomo_color($lastSession['pcolor']),
        'durata_secondi' => (int)($lastSession['duration_seconds'] ?? 0)
    ] : null,
    'settings' => [
        'ultima_modalita' => (int)$imp['last_mode'], 'ultima_durata_pomodoro_min' => (int)$imp['last_pomodoro_duration_min'],
        'ultima_durata_pausa_min' => (int)$imp['last_break_duration_min'], 'stile' => $imp['last_display_style'],
    ],
    'presets_pomodoro' => $presetsPomodoro, 'presets_pausa' => $presetsPausa,
    'preselect' => $preselectInfo ? ['id' => (int)$preselectInfo['id'], 'name' => $preselectInfo['name'], 'color' => pomo_color($preselectInfo['color'])] : null,
    'project_paths' => $pomoProjPaths,
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<title>Pomodoro</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<style>
/* ==========================================================================
   POMODORO — UI iOS (restyling estetico)
   LOGICA COLORI INVARIATA:
     --pomo-accent   = colore del progetto (iniettato da JS su <body>) o arancione
     --pausa-accent  = teal
     --pomo-live        = quello "attivo", commuta da solo con body.pausa-mode
   ========================================================================== */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#E7E7EC;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#6E6E73; --purple:#AF52DE; --teal:#30B0C7;
    /* --orange è ora il grigio "neutro" di default (nessun progetto collegato): nome storico, valore cambiato su richiesta */
    --red-soft:rgba(255,59,48,.1);
    --header-glass:rgba(249,249,251,.72);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --shadow-sheet:0 -14px 60px rgba(0,0,0,.18);
    --shadow-pop:0 30px 70px -22px rgba(0,0,0,.45);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --track:rgba(120,120,128,.14);
    --r-card:22px; --r-btn:15px;
    --spring:cubic-bezier(.32,.72,0,1);
    --pop:cubic-bezier(.34,1.4,.64,1);
    --ease-out:cubic-bezier(.16,1,.3,1);
    --safe-top:env(safe-area-inset-top, 0px); --safe-bottom:env(safe-area-inset-bottom, 0px);
    --pomo-accent: var(--orange); --pausa-accent: var(--teal);
}
@media (prefers-color-scheme: dark){ :root{
    --bg:#000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#3A3A3C;
    --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
    --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
    --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#7A7A81; --purple:#BF5AF2; --teal:#40C8E0;
    --red-soft:rgba(255,69,58,.16);
    --header-glass:rgba(16,16,18,.7);
    --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
    --shadow-sheet:0 -14px 60px rgba(0,0,0,.6);
    --track:rgba(120,120,128,.26);
}}

*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
.is-hidden{ display:none !important; }
body{
    margin:0; min-height:100vh; background:var(--bg); color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",system-ui,Roboto,Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
    padding-bottom:calc(90px + var(--safe-bottom)); overscroll-behavior-y:none;
    transition:background-color .6s var(--ease-out);
    /* accento attivo: pomodoro o pausa. Tutto il resto legge var(--pomo-live) */
    --pomo-live: var(--pomo-accent);
}
body.pausa-mode{ --pomo-live: var(--pausa-accent); }
button{ font-family:inherit; cursor:pointer; }

/* ---- alone ambientale che segue il colore del progetto ---- */
body::before{
    content:""; position:fixed; inset:0; z-index:-1; pointer-events:none;
    background:
        radial-gradient(88% 52% at 50% -4%, color-mix(in srgb, var(--pomo-live) 22%, transparent) 0%, transparent 60%),
        radial-gradient(70% 44% at 50% 104%, color-mix(in srgb, var(--pomo-live) 11%, transparent) 0%, transparent 66%);
    opacity:.85;
}
@media (prefers-color-scheme: dark){ body::before{ opacity:.5; } }

/* ---- header ---- */
.app-header{ position:sticky; top:0; z-index:100; padding:calc(var(--safe-top) + 11px) 16px 11px; background:var(--header-glass);
    -webkit-backdrop-filter:saturate(180%) blur(24px); backdrop-filter:saturate(180%) blur(24px); border-bottom:.5px solid var(--hairline);
    display:flex; align-items:center; justify-content:space-between; }
.hdr-title{ font-size:19px; font-weight:800; letter-spacing:-.5px; }
.hdr-history-btn{ display:inline-flex; align-items:center; gap:6px; background:var(--surface-2); border:none; border-radius:999px; padding:8px 14px;
    color:var(--text); font-weight:700; font-size:13px; letter-spacing:-.1px; box-shadow:inset 0 0 0 .5px var(--hairline);
    transition:transform .2s var(--spring), background-color .2s; }
.hdr-history-btn:active{ transform:scale(.94); background:var(--surface-3); }

.page{ position:relative; z-index:1; max-width:520px; margin:0 auto; padding:6px 20px calc(30px + var(--safe-bottom)); display:flex; flex-direction:column; align-items:center; }

/* ---- mode switch (segmented control con thumb scorrevole) ---- */
.mode-switch{ position:relative; display:flex; background:var(--surface-2); border-radius:15px; padding:3px; margin:14px 0 8px;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:opacity .35s, filter .35s; }
.mode-switch button{ position:relative; z-index:1; flex:1; min-width:110px; border:none; background:none; padding:9px 18px; border-radius:12px;
    font-weight:600; font-size:14px; letter-spacing:-.2px; color:var(--text-2); transition:color .3s var(--spring); }
.mode-switch button.active{ color:#fff; font-weight:700; }
@supports selector(:has(*)){
    .mode-switch::before{ content:""; position:absolute; z-index:0; top:3px; bottom:3px; left:3px; width:calc(50% - 3px); border-radius:12px;
        background:var(--pomo-live); box-shadow:var(--btn-hl), 0 5px 14px -5px color-mix(in srgb, var(--pomo-live) 75%, transparent);
        transition:transform .48s var(--spring), background-color .35s var(--ease-out); }
    .mode-switch:has(button:nth-of-type(2).active)::before{ transform:translateX(100%); }
}
@supports not selector(:has(*)){
    .mode-switch button.active{ background:var(--pomo-live); box-shadow:var(--btn-hl); }
}
body.pomo-locked .mode-switch{ opacity:.4; filter:saturate(.55); pointer-events:none; }

/* ---- selettore progetto sopra al timer (pillola) ---- */
.pomo-project-row{ margin:6px 0 14px; width:100%; }
.pomo-project-row > div{ display:flex !important; justify-content:center !important; width:100% !important; }
/* l'aspetto a pillola (vetro, ombra, punto colorato, freccia nascosta) arriva
   ora direttamente da .ps-trigger-pill in project_selector.php: qui restringiamo
   solo la larghezza al contenuto e la centriamo, che è specifico di questa pagina */
.pomo-project-row button{ width:auto !important; max-width:100% !important; margin:0 auto !important; }
.pomo-project-row .pomo-pill-anc{ opacity:.6; font-weight:600; }
.pomo-project-row .pomo-pill-sep{ opacity:.45; }
.pomo-project-row b{ font-weight:800; }

/* ---- display switch (cerchio/clessidra) ---- */
.display-switch{ position:absolute; z-index: 10; left:18px; top:calc(var(--safe-top) + 66px); width:38px; height:38px; border-radius:50%;
    background:color-mix(in srgb, var(--surface) 80%, transparent);
    -webkit-backdrop-filter:blur(18px) saturate(180%); backdrop-filter:blur(18px) saturate(180%);
    border:none; color:var(--text-2); display:flex; align-items:center; justify-content:center;
    box-shadow:inset 0 0 0 .5px var(--hairline), 0 5px 16px -6px rgba(0,0,0,.22);
    transition:transform .45s var(--pop), color .25s, background-color .25s; }
.display-switch:active{ transform:scale(.84) rotate(-18deg); color:var(--pomo-live); }

/* ---- etichetta di stato ---- */
.pomo-status-label{ display:inline-flex; align-items:center; gap:7px; min-height:20px; margin-bottom:4px;
    font-size:11.5px; font-weight:700; letter-spacing:.7px; text-transform:uppercase; color:var(--text-2); text-align:center;
    transition:color .3s; }
.pomo-status-label::before{ content:""; width:6px; height:6px; border-radius:50%; background:var(--pomo-live); flex-shrink:0;
    opacity:0; transform:scale(.3); transition:opacity .4s, transform .4s var(--pop); }
body[data-phase="running"] .pomo-status-label::before,
body[data-phase="pausa_running"] .pomo-status-label::before{ opacity:1; transform:none; animation:statusDot 2.4s ease-in-out infinite; }
body[data-phase="paused_task"] .pomo-status-label::before,
body[data-phase="completed"] .pomo-status-label::before,
body[data-phase="pausa_done"] .pomo-status-label::before{ opacity:.55; transform:none; }
@keyframes statusDot{ 0%,100%{ box-shadow:0 0 0 0 color-mix(in srgb, var(--pomo-live) 55%, transparent); } 70%,100%{ box-shadow:0 0 0 7px transparent; } }

/* ---- cerchio ---- */
.ring-wrap{ position:relative; width:min(78vw, 300px); height:min(78vw, 300px); margin:14px 0 2px; display:flex; align-items:center; justify-content:center; }
.ring-halo{ position:absolute; inset:-16%; border-radius:50%; pointer-events:none; z-index:0;
    background:radial-gradient(closest-side, color-mix(in srgb, var(--pomo-live) 30%, transparent), color-mix(in srgb, var(--pomo-live) 7%, transparent) 58%, transparent 76%);
    opacity:0; transform:scale(.88); transition:opacity .8s var(--ease-out), transform .8s var(--ease-out); }
body[data-phase="running"] .ring-halo,
body[data-phase="completed"] .ring-halo,
body[data-phase="pausa_running"] .ring-halo,
body[data-phase="pausa_done"] .ring-halo{ animation:haloBreathe 5.4s ease-in-out infinite; }
body[data-phase="paused_task"] .ring-halo{ opacity:.4; transform:scale(.97); }
@keyframes haloBreathe{ 0%,100%{ opacity:.7; transform:scale(.95); } 50%{ opacity:1; transform:scale(1.04); } }

.ring-face{ position:absolute; inset:9.5%; border-radius:50%; z-index:0; pointer-events:none;
    background:radial-gradient(120% 120% at 50% 0%, color-mix(in srgb, var(--pomo-live) 9%, var(--surface)) 0%, var(--surface) 66%);
    box-shadow:0 22px 44px -24px rgba(0,0,0,.4), inset 0 .5px 0 rgba(255,255,255,.7), inset 0 0 0 .5px var(--hairline); }
@media (prefers-color-scheme: dark){
    .ring-face{ box-shadow:0 26px 54px -22px rgba(0,0,0,.9), inset 0 .5px 0 rgba(255,255,255,.06), inset 0 0 0 .5px rgba(255,255,255,.05); }
}
.ring-wrap svg{ position:absolute; inset:0; width:100%; height:100%; transform:rotate(-90deg); overflow:visible; z-index:1; }
.ring-track{ fill:none; stroke:var(--track); stroke-width:12; }
.ring-progress{ fill:none; stroke:var(--pomo-live); stroke-width:12; stroke-linecap:round;
    transition:stroke-dashoffset .3s linear, stroke .45s var(--ease-out);
    filter:drop-shadow(0 0 9px color-mix(in srgb, var(--pomo-live) 55%, transparent)); }

.ring-center{ position:relative; z-index:2; display:flex; flex-direction:column; align-items:center; gap:7px;
    padding:8px 10px; border-radius:26px; text-align:center; cursor:pointer;
    user-select:none; -webkit-user-select:none; transition:transform .3s var(--spring); }
body[data-phase="idle"] .ring-center:active{ transform:scale(.95); }
.ring-time{ font-size:clamp(40px, 14.5vw, 54px); font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-1.6px; line-height:1;
    transition:color .45s var(--ease-out); }
.ring-sub{ font-size:11px; font-weight:700; letter-spacing:.2px; color:var(--text-2);
    background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline); padding:4px 11px; border-radius:999px;
    transition:opacity .35s var(--ease-out), transform .35s var(--pop); }
.ring-sub:empty{ opacity:0; transform:scale(.8); }

/* ---- clessidra (il disegno vero e proprio vive in clessidra.php) ---- */
.glass-wrap{ display:flex; align-items:center; justify-content:center;
    position:relative; cursor:pointer; transition:transform .3s var(--spring); }
body[data-phase="idle"] .glass-wrap:active{ transform:scale(.955); }

/* ---- chip tempo extra ---- */
.overtime-chip{ margin-top:14px; display:flex; align-items:center; gap:7px; padding:9px 17px; border-radius:999px; cursor:pointer;
    background:color-mix(in srgb, var(--pomo-live) 12%, var(--surface)); color:var(--pomo-live);
    font-weight:800; font-size:14.5px; letter-spacing:-.2px; font-variant-numeric:tabular-nums;
    animation:overtimePulse 2.6s ease-in-out infinite;
    transition:transform .2s var(--spring); }
.overtime-chip:active{ transform:scale(.94); }
@keyframes overtimePulse{
    0%,100%{ box-shadow:inset 0 0 0 .5px color-mix(in srgb, var(--pomo-live) 30%, transparent), 0 6px 18px -10px color-mix(in srgb, var(--pomo-live) 80%, transparent); }
    50%{ box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--pomo-live) 45%, transparent), 0 8px 28px -8px color-mix(in srgb, var(--pomo-live) 90%, transparent); }
}

/* ---- pulsanti azione ---- */
.pomo-actions{ display:flex; gap:10px; margin-top:18px; width:100%; justify-content:center; animation:pomoActIn .5s var(--spring) both; }
@keyframes pomoActIn{ from{ opacity:0; transform:translateY(10px) scale(.97); } to{ opacity:1; transform:none; } }
.pomo-btn{ padding:15px 26px; border-radius:17px; border:none; font-size:15.5px; font-weight:700; letter-spacing:-.2px; white-space:nowrap; color:var(--text);
    box-shadow:var(--btn-hl); transition:transform .2s var(--spring), background-color .35s var(--ease-out), box-shadow .35s, filter .2s; }
.pomo-btn:active{ transform:scale(.955); filter:brightness(.95); }
.pomo-btn-start{ background:var(--pomo-live); color:#fff; padding:16px 52px; font-size:16.5px; font-weight:800; position:relative; overflow:hidden;
    box-shadow:var(--btn-hl), 0 12px 28px -10px color-mix(in srgb, var(--pomo-live) 90%, transparent); }
.pomo-btn-start::after{ content:""; position:absolute; top:-50%; left:-60%; width:35%; height:200%;
    background:linear-gradient(115deg, transparent, rgba(255,255,255,.42), transparent); transform:rotate(20deg); animation:pomoShine 5s ease-in-out infinite; }
@keyframes pomoShine{ 0%,80%,100%{ left:-60%; } 42%{ left:130%; } }
.pomo-btn-pausa{ background:var(--surface-2); color:var(--text); box-shadow:inset 0 0 0 .5px var(--hairline); }
.pomo-btn-teal{ background:var(--teal); color:#fff; box-shadow:var(--btn-hl), 0 10px 24px -10px color-mix(in srgb, var(--teal) 85%, transparent); }
.pomo-btn-interrompi{ background:var(--red-soft); color:var(--red); box-shadow:inset 0 0 0 .5px color-mix(in srgb, var(--red) 22%, transparent); }
/* resta l'accento "pomodoro" anche durante la pausa: è l'invito a ripartire */
.pomo-btn-pomodoro{ background:var(--pomo-accent) !important; color:#fff !important;
    box-shadow:var(--btn-hl), 0 10px 24px -10px color-mix(in srgb, var(--pomo-accent) 85%, transparent); }
.pomo-btn-resume{ background:var(--pomo-live); color:#fff; padding:16px 44px;
    box-shadow:var(--btn-hl), 0 10px 26px -10px color-mix(in srgb, var(--pomo-live) 85%, transparent); }

/* ---- badge "in pausa" ---- */
.paused-badge{ display:inline-flex; align-items:center; gap:7px; background:color-mix(in srgb, var(--pomo-live) 13%, var(--surface)); color:var(--pomo-live);
    padding:7px 14px; border-radius:999px; font-weight:700; font-size:12px; letter-spacing:.3px; text-transform:uppercase; margin-bottom:14px;
    box-shadow:inset 0 0 0 .5px color-mix(in srgb, var(--pomo-live) 26%, transparent); }
.paused-badge .dot{ width:7px; height:7px; border-radius:50%; background:currentColor; animation:pausedPulse 2s ease-in-out infinite; }
@keyframes pausedPulse{ 0%,100%{ opacity:1; transform:scale(1); } 50%{ opacity:.4; transform:scale(.8); } }

/* ==========================================================================
   BOTTOM SHEET (durata + gestione durate)
   ========================================================================== */
.dur-overlay{ position:fixed; inset:0; z-index:9700; display:flex; align-items:flex-end; justify-content:center; background:rgba(0,0,0,.4);
    -webkit-backdrop-filter:blur(10px) saturate(140%); backdrop-filter:blur(10px) saturate(140%);
    opacity:0; pointer-events:none; transition:opacity .36s var(--ease-out); }
.dur-overlay.show{ opacity:1; pointer-events:auto; }
.dur-sheet{ width:100%; max-width:520px; background:var(--surface); border-radius:26px 26px 0 0; overflow:hidden;
    transform:translateY(101%); transition:transform .5s var(--spring); padding-bottom:var(--safe-bottom); box-shadow:var(--shadow-sheet); }
.dur-overlay.show .dur-sheet{ transform:translateY(0); }
.dur-grabber{ width:38px; height:5px; border-radius:3px; background:var(--hairline-2); margin:8px auto 0; }
.dur-head{ display:flex; align-items:center; justify-content:space-between; padding:12px 18px 6px; }
.dur-head b{ font-size:19px; font-weight:800; letter-spacing:-.4px; }
.sheet-close{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text-2);
    display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:transform .2s var(--spring), background-color .2s; }
.sheet-close:active{ transform:scale(.88); background:var(--surface-3); }
.dur-half{ padding:12px 18px; }
.dur-half-title{ font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-2); margin-bottom:11px;
    display:flex; align-items:center; gap:7px; }
.dur-half-title .dot{ width:8px; height:8px; border-radius:50%; }
.dur-pomo-title .dot{ background:var(--pomo-accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--pomo-accent) 18%, transparent); }
.dur-pausa-title .dot{ background:var(--teal); box-shadow:0 0 0 3px color-mix(in srgb, var(--teal) 18%, transparent); }
.dur-pills{ display:flex; flex-wrap:wrap; gap:8px; }
.dur-pill{ padding:11px 18px; border-radius:14px; border:none; background:var(--surface-2); color:var(--text); font-weight:600; font-size:14.5px;
    letter-spacing:-.2px; box-shadow:inset 0 0 0 .5px var(--hairline);
    transition:transform .2s var(--spring), background-color .3s var(--ease-out), color .25s, box-shadow .3s; }
.dur-pill:active{ transform:scale(.94); }
.dur-pill.active{ background:var(--pomo-accent); color:#fff; font-weight:700;
    box-shadow:var(--btn-hl), 0 7px 18px -8px color-mix(in srgb, var(--pomo-accent) 85%, transparent); }
.dur-pausa-title ~ .dur-pills .dur-pill.active{ background:var(--teal);
    box-shadow:var(--btn-hl), 0 7px 18px -8px color-mix(in srgb, var(--teal) 85%, transparent); }
.dur-pill-add{ background:none; box-shadow:inset 0 0 0 1.5px var(--hairline-2); color:var(--text-2); font-weight:600; }
.dur-divider{ height:.5px; background:var(--hairline); margin:2px 18px; }
.dur-confirm{ display:block; width:calc(100% - 36px); margin:16px 18px calc(12px + var(--safe-bottom)); padding:16px; border-radius:16px; border:none;
    background:var(--pomo-live); color:#fff; font-weight:800; font-size:16px; letter-spacing:-.2px;
    box-shadow:var(--btn-hl), 0 12px 26px -12px color-mix(in srgb, var(--pomo-live) 90%, transparent);
    transition:transform .2s var(--spring), background-color .35s var(--ease-out), filter .2s; }
.dur-confirm:active{ transform:scale(.975); filter:brightness(.96); }
.dur-custom-input{ display:none; padding:11px 14px; border-radius:14px; border:none; background:var(--surface-2); font-size:15px; width:90px; font-family:inherit; }
.dur-custom-input.show{ display:inline-block; }

/* badge di eliminazione sulle durate personalizzate (long press) */
.pill-wrap{ position:relative; display:inline-block; }
.cdur-badge{
    position:absolute; top:-6px; left:-6px; width:22px; height:22px;
    background:var(--red); color:#fff; border:none; border-radius:50%;
    font-weight:800; font-size:16px; line-height:1;
    display:flex; align-items:center; justify-content:center;
    opacity:0; transform:scale(.4); pointer-events:none;
    transition:opacity .25s, transform .3s var(--pop); z-index:10;
    box-shadow:0 3px 8px -1px rgba(0,0,0,.28); cursor:pointer;
}
.cdur-badge.show{ opacity:1; transform:scale(1); pointer-events:auto; }

/* ---- popup "gestisci durate" ---- */
.cdur-body{ padding:14px 18px calc(22px + var(--safe-bottom)); }
.cdur-add-row{ display:flex; gap:10px; align-items:center; }
.cdur-time-input{ flex:1; padding:13px 14px; border-radius:14px; border:none; background:var(--surface-2); color:var(--text);
    font-size:18px; font-weight:700; font-family:inherit; font-variant-numeric:tabular-nums; box-shadow:inset 0 0 0 .5px var(--hairline); }
.cdur-add-btn{ padding:13px 20px; border-radius:14px; border:none; background:var(--pomo-live); color:#fff; font-weight:800; font-size:15px;
    box-shadow:var(--btn-hl), 0 8px 20px -10px color-mix(in srgb, var(--pomo-live) 90%, transparent);
    transition:transform .2s var(--spring), background-color .35s, filter .2s; }
.cdur-add-btn:active{ transform:scale(.95); filter:brightness(.96); }
.cdur-list{ display:flex; flex-direction:column; gap:8px; }
.cdur-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; background:var(--surface-2); padding:11px 8px 11px 15px;
    border-radius:14px; box-shadow:inset 0 0 0 .5px var(--hairline); animation:pomoActIn .35s var(--spring) both;
    transition:background-color .25s var(--ease-out), box-shadow .25s var(--ease-out); }
.cdur-row.active{ background:color-mix(in srgb, var(--cdur-mark, var(--pomo-live)) 14%, var(--surface-2));
    box-shadow:inset 0 0 0 1.5px color-mix(in srgb, var(--cdur-mark, var(--pomo-live)) 42%, transparent); }
.cdur-row-label{ font-weight:700; font-size:15.5px; letter-spacing:-.2px; display:flex; align-items:center; gap:8px; }
.cdur-row-check{ flex-shrink:0; color:var(--cdur-mark, var(--pomo-live)); opacity:0; transform:scale(.4);
    transition:opacity .2s, transform .28s var(--pop); }
.cdur-row.active .cdur-row-check{ opacity:1; transform:none; }
.cdur-row-del{ background:none; border:none; color:var(--red); padding:7px; border-radius:10px; display:flex; align-items:center; justify-content:center;
    transition:transform .2s var(--spring), background-color .2s; }
.cdur-row-del:active{ transform:scale(.86); background:var(--red-soft); }
.cdur-empty{ font-size:14px; color:var(--text-2); text-align:center; padding:18px 0; }

/* ---- toast ---- */
.pomo-toast{ position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom)); transform:translate(-50%,14px) scale(.96);
    background:rgba(28,28,30,.86); -webkit-backdrop-filter:blur(20px) saturate(180%); backdrop-filter:blur(20px) saturate(180%);
    color:#fff; padding:12px 20px; border-radius:16px; font-size:13.5px; font-weight:600; letter-spacing:-.1px; z-index:9900;
    box-shadow:0 14px 40px -12px rgba(0,0,0,.5); opacity:0; pointer-events:none;
    transition:opacity .3s var(--ease-out), transform .42s var(--pop); }
.pomo-toast.show{ opacity:1; transform:translate(-50%,0) scale(1); }

/* ---- alert di conferma ---- */
.confirm-overlay{ position:fixed; inset:0; z-index:9800; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.4);
    -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
    opacity:0; pointer-events:none; transition:opacity .28s var(--ease-out); padding:20px; }
.confirm-overlay.show{ opacity:1; pointer-events:auto; }
.confirm-box{ background:color-mix(in srgb, var(--surface) 88%, transparent);
    -webkit-backdrop-filter:blur(30px) saturate(180%); backdrop-filter:blur(30px) saturate(180%);
    border-radius:22px; padding:22px 20px 16px; max-width:300px; width:100%; text-align:center; box-shadow:var(--shadow-pop);
    transform:scale(.88); opacity:0; transition:transform .42s var(--pop), opacity .26s; }
.confirm-overlay.show .confirm-box{ transform:scale(1); opacity:1; }
.confirm-title{ font-weight:800; font-size:17px; letter-spacing:-.3px; margin-bottom:6px; }
.confirm-msg{ font-size:13.5px; line-height:1.45; color:var(--text-2); margin-bottom:18px; }
.confirm-actions{ display:flex; gap:10px; }
.confirm-btn{ flex:1; padding:13px; border-radius:14px; border:none; font-weight:700; font-size:15px; letter-spacing:-.2px;
    background:var(--surface-2); color:var(--text); box-shadow:inset 0 0 0 .5px var(--hairline);
    transition:transform .2s var(--spring), filter .2s; }
.confirm-btn:active{ transform:scale(.96); filter:brightness(.96); }
.confirm-btn-primary{ background:var(--blue); color:#fff; box-shadow:var(--btn-hl); }

@media (prefers-reduced-motion: reduce){
    *{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; }
}
</style>
</head>
<body>

<div class="app-header">
    <div class="hdr-title">Pomodoro</div>
    <button type="button" class="hdr-history-btn" onclick="window.openPomodoroStorico({scope:'all'})">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="13" r="8"/></svg>
        Storico
    </button>
</div>

<?php include 'sidebar.html'; ?>

<button type="button" class="display-switch" id="displaySwitchBtn" onclick="toggleDisplayStyle()" aria-label="Cambia visualizzazione">
    <svg id="displaySwitchIcon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M6 2v3.3c0 .6.24 1.2.66 1.6L12 12l5.34-5.1c.42-.4.66-1 .66-1.6V2"/><path d="M18 22v-3.3c0-.6-.24-1.2-.66-1.6L12 12l-5.34 5.1c-.42.4-.66 1-.66 1.6V22"/></svg>
</button>

<div class="page">
    <div class="mode-switch" id="modeSwitch">
        <button type="button" data-mode="0" onclick="setMode(0)">Timer</button>
        <button type="button" data-mode="1" onclick="setMode(1)">Cronometro</button>
    </div>

    <div class="pomo-project-row">
        <?php
            $ps_id = 'pomo'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
            $ps_placeholder = 'Nessun progetto collegato'; $ps_title = 'Collega a un progetto';
            $ps_show_all_option = true; $ps_all_option_label = 'Nessun progetto'; $ps_allow_create = true;
            $ps_hidden_name = 'pomo_project_id';
            $ps_trigger_class = 'ps-trigger-pill';
            include 'project_selector.php';
        ?>
    </div>

    <div class="pomo-status-label" id="statusLabel">Pronto quando vuoi</div>

    <div class="ring-wrap" id="ringWrap">
        <div class="ring-halo"></div>
        <div class="ring-face"></div>
        <svg viewBox="0 0 220 220"><circle class="ring-track" cx="110" cy="110" r="100"/><circle class="ring-progress" id="ringProgress" cx="110" cy="110" r="100" stroke-dasharray="628.3" stroke-dashoffset="628.3"/></svg>
        <div class="ring-center" onclick="onDisplayClick()">
            <div class="ring-time" id="ringTime">25:00</div>
            <div class="ring-sub" id="ringSub"></div>
        </div>
    </div>

    <div class="glass-wrap is-hidden" id="glassWrap" onclick="onDisplayClick()">
        <?php include 'clessidra.php'; ?>
    </div>

    <div class="overtime-chip" id="overtimeChip" style="display:none;" onclick="onExtendClick()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span id="overtimeValue">00:00:00</span>
    </div>

    <div class="pomo-actions" id="actionsIdle">
        <button type="button" class="pomo-btn pomo-btn-start" id="startBtn" onclick="onStartClick()">Start</button>
    </div>
    <div class="pomo-actions" id="actionsRunning" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-pausa" id="midPauseBtn" onclick="onMidPauseClick()">Stop</button>
        <button type="button" class="pomo-btn pomo-btn-interrompi" onclick="onInterrompiClick()">Interrompi</button>
    </div>
    <div class="pomo-actions" id="actionsCompleted" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-interrompi" onclick="onChiudiCompletato()">Interrompi</button>
        <button type="button" class="pomo-btn pomo-btn-teal" onclick="onAvviaPausaClick()">Pausa</button>
    </div>
    <div class="pomo-actions" id="actionsPausaRunning" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-interrompi" onclick="onFinePausaClick('stop')">Interrompi</button>
        <button type="button" class="pomo-btn pomo-btn-pomodoro" onclick="onFinePausaClick('nuovo_pomodoro')">Pomodoro</button>
    </div>
    <div class="pomo-actions" id="actionsPausaDone" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-interrompi" onclick="onFinePausaClick('stop')">Interrompi</button>
        <button type="button" class="pomo-btn pomo-btn-pomodoro" onclick="onFinePausaClick('nuovo_pomodoro')">Pomodoro</button>
    </div>
    <div class="pomo-actions" id="actionsPausedTask" style="display:none; flex-direction:column; align-items:center;">
        <span class="paused-badge"><span class="dot"></span>Sessione in pausa</span>
        <div style="display:flex; gap:12px;">
            <button type="button" class="pomo-btn pomo-btn-resume" onclick="onResumeTaskClick()">Riprendi</button>
            <button type="button" class="pomo-btn pomo-btn-interrompi" onclick="onInterrompiClick()">Interrompi</button>
        </div>
    </div>
</div>

<!-- ================= POPUP DURATA (pomodoro + pausa) ================= -->
<div class="dur-overlay" id="durOverlay" onclick="if(event.target===this) closeDurPopup();">
    <div class="dur-sheet">
        <div class="dur-grabber"></div>
        <div class="dur-head"><b>Durata</b>
            <button type="button" class="sheet-close" onclick="closeDurPopup()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="dur-half" id="durPomoHalf">
            <div class="dur-half-title dur-pomo-title"><span class="dot"></span>Pomodoro</div>
            <div class="dur-pills" id="durPomoPills"></div>
        </div>
        <div class="dur-divider"></div>
        <div class="dur-half" id="durPausaHalf">
            <div class="dur-half-title dur-pausa-title"><span class="dot"></span>Pausa</div>
            <div class="dur-pills" id="durPausaPills"></div>
        </div>
        <button type="button" class="dur-confirm" onclick="confirmDurPopup()">Conferma</button>
    </div>
</div>

<!-- ================= POPUP GESTIONE DURATE ================= -->
<div class="dur-overlay" id="customDurOverlay" style="z-index:9710;" onclick="if(event.target===this) closeCustomDur();">
    <div class="dur-sheet">
        <div class="dur-grabber"></div>
        <div class="dur-head"><b>Gestisci <span id="cdurTipoTit"></span></b>
            <button type="button" class="sheet-close" onclick="closeCustomDur()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="cdur-body">
            <div class="dur-half-title">Aggiungi durata</div>
            <div class="cdur-add-row">
                <input type="time" class="cdur-time-input" id="cdurTime" onfocus="if(!this.value) this.value='00:00';" value="00:00">
                <button type="button" class="cdur-add-btn" onclick="saveCustomDur()">Aggiungi</button>
            </div>

            <div class="dur-half-title" style="margin-top:24px;">Create da te</div>
            <div class="cdur-list" id="cdurList">
                <!-- Qui verranno caricate le durate -->
            </div>
        </div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <div class="confirm-title" id="confirmTitle">Salvare la sessione?</div>
        <div class="confirm-msg" id="confirmMsg"></div>
        <div class="confirm-actions">
            <button type="button" class="confirm-btn" onclick="closeConfirm(false)">No</button>
            <button type="button" class="confirm-btn confirm-btn-primary" onclick="closeConfirm(true)">Sì, salva</button>
        </div>
    </div>
</div>

<!-- Attività: un post-it con il timer in piccolo e l'elenco di cosa fare
     durante questo pomodoro. Si apre dal tastino qui sotto. -->
<button type="button" class="pomo-todo-btn" id="pomoTodoBtn" aria-label="Attività">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l2.2 2.2L10 5.5"/><path d="M4 17l2.2 2.2L10 15.5"/><path d="M13 7.5h7M13 17.5h7"/></svg>
</button>

<div class="pomo-todo-ov" id="pomoTodoOv">
    <div class="pomo-postit">
        <span class="pomo-postit-tape" aria-hidden="true"></span>
        <div class="pomo-postit-head">
            <span class="pomo-postit-ring">
                <svg viewBox="0 0 36 36"><circle class="pp-track" cx="18" cy="18" r="15"/><circle class="pp-prog" id="pomoTodoRing" cx="18" cy="18" r="15" stroke-dasharray="94.2" stroke-dashoffset="94.2"/></svg>
                <span id="pomoTodoTime">25:00</span>
            </span>
            <button type="button" class="pomo-postit-close" id="pomoTodoClose">Chiudi</button>
        </div>
        <div id="pomoTodoList"></div>
    </div>
</div>

<div class="pomo-toast" id="pomoToast"></div>
<style>
.pomo-todo-btn{
    position:fixed; right:16px; bottom:calc(96px + var(--safe-bottom, 0px)); z-index:60;
    width:46px; height:46px; border-radius:50%; border:none; cursor:pointer;
    background:var(--surface-2); color:var(--text-2);
    box-shadow:0 8px 22px -10px rgba(0,0,0,.5);
    display:grid; place-items:center; transition:transform .18s;
}
.pomo-todo-btn svg{ width:20px; height:20px; }
.pomo-todo-btn:active{ transform:scale(.9); }
.pomo-todo-btn.is-on{ background:var(--pomo-accent, var(--accent)); color:#fff; }

.pomo-todo-ov{
    position:fixed; inset:0; z-index:9600; display:grid; place-items:center; padding:22px;
    background:rgba(0,0,0,.46); -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
    opacity:0; pointer-events:none; transition:opacity .28s ease;
}
.pomo-todo-ov.show{ opacity:1; pointer-events:auto; }
.pomo-postit{
    position:relative; width:100%; max-width:340px; max-height:76vh; overflow-y:auto;
    background:#FDFCF9; color:#4A443D; border-radius:3px 3px 14px 14px;
    padding:22px 16px 16px; transform:rotate(-.8deg) scale(.94);
    box-shadow:0 26px 56px -22px rgba(0,0,0,.6); transition:transform .34s cubic-bezier(.34,1.3,.64,1);
    scrollbar-width:none;
}
.pomo-postit::-webkit-scrollbar{ width:0; }
.pomo-todo-ov.show .pomo-postit{ transform:rotate(-.8deg) scale(1); }
.pomo-postit-tape{
    position:absolute; top:-11px; left:50%; width:78px; height:21px;
    transform:translateX(-50%) rotate(-2.4deg); background:rgba(255,255,255,.7);
    box-shadow:0 2px 6px rgba(0,0,0,.16); border-radius:1px;
}
.pomo-postit-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.pomo-postit-ring{ position:relative; width:44px; height:44px; display:grid; place-items:center; }
.pomo-postit-ring svg{ position:absolute; inset:0; width:100%; height:100%; transform:rotate(-90deg); }
.pomo-postit-ring circle{ fill:none; stroke-width:2.6; stroke-linecap:round; }
.pp-track{ stroke:rgba(74,68,61,.14); }
.pp-prog{ stroke:#A9C3AE; transition:stroke-dashoffset .9s linear; }
.pomo-postit-ring span{ font-size:10px; color:#8A8177; font-variant-numeric:tabular-nums; }
.pomo-postit-close{ border:none; background:none; color:#8A8177; font:inherit; font-size:13px; cursor:pointer; }
</style>

<?php
    $ph_id = 'pomo';
    include 'pomodoro_storico.php';
?>

<script>
const STATE0 = <?php echo json_encode($jsState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
(function(){
'use strict';

var mode = STATE0.settings.ultima_modalita;               // 0 timer, 1 cronometro
var displayStyle = STATE0.settings.stile;                  // 'cerchio' | 'clessidra'
var selDurataPomo = STATE0.settings.ultima_durata_pomodoro_min;
var selDurataPausa = STATE0.settings.ultima_durata_pausa_min;
var projectId = 0, projectName = '', projectColor = '';
var phase = 'idle';        // idle | running | paused_task | completed | pausa_running | pausa_done
var activeSessionId = null, activeMode = 0, activeDurataMin = null;
var segmentStartMs = null;   // inizio del segmento attivo corrente (si aggiorna ad ogni "Riprendi")
var accumulatedSec = 0;      // secondi già accumulati nei segmenti attivi precedenti (dato dal server)
var completedEndMs = null;
var pausaStartMs = null, pausaDurataMin = null;
var tickTimer = null, notifiedEnd = false, notifiedPausaEnd = false;
var RING_CIRC = 2 * Math.PI * 100;

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }
function toast(msg){
    var t = document.getElementById('pomoToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._tm); t._tm = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}
function pad(n){ return String(n).padStart(2,'0'); }
function fmtHMS(totalSec){
    totalSec = Math.max(0, Math.floor(totalSec));
    var h = Math.floor(totalSec/3600), m = Math.floor((totalSec%3600)/60), s = totalSec%60;
    return h > 0 ? (h+':'+pad(m)+':'+pad(s)) : (pad(m)+':'+pad(s));
}
function post(url, params){
    return fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams(params).toString() }).then(function(r){ return r.json(); });
}
function notify(title, body){
    try{
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') { new Notification(title, {body: body}); }
    }catch(e){}
}
function requestNotifPermission(){ try{ if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); }catch(e){} }

/* ---------------- applicazione colore/tema dal progetto: sfondo + accento animazioni ---------------- */
function applyProjectTheme(color){
    if (color) {
        document.body.style.background = 'color-mix(in srgb, ' + color + ' 7%, var(--bg))';
        document.body.style.setProperty('--pomo-accent', color);
    } else {
        document.body.style.background = '';
        document.body.style.removeProperty('--pomo-accent');
    }
}

/* ---------------- blocco UI mentre una sessione è attiva o in pausa ---------------- */
function updateLockUI(){
    document.body.classList.toggle('pomo-locked', phase !== 'idle');
    // espone la fase al CSS: guida alone, puntino di stato e micro-animazioni
    document.body.setAttribute('data-phase', phase);
}

/* ---------------- mode switch ---------------- */
window.setMode = function(m){
    if (phase !== 'idle') return; // non si cambia modalità mentre gira o è in pausa qualcosa
    mode = m;
    document.querySelectorAll('#modeSwitch button').forEach(function(b){ b.classList.toggle('active', parseInt(b.getAttribute('data-mode'),10) === m); });
    renderIdleDisplay();
};

/* ---------------- selettore progetto ---------------- */
/* richiesta 17: mostra "nonno • padre • progetto" (ultimo in grassetto) nella
   pillola del progetto collegato, invece del solo nome */
function pomoEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function pomoUpdateProjectPill(){
    var el = document.getElementById('ps-trigger-text-pomo');
    if (!el) return;
    if (!projectId) return; // nessun progetto: lascia il placeholder gestito dal selettore
    var path = (STATE0.project_paths && STATE0.project_paths[projectId]) ? STATE0.project_paths[projectId] : [projectName];
    if (!path || !path.length) path = [projectName];
    var html = '';
    for (var i = 0; i < path.length; i++){
        if (i > 0) html += '<span class="pomo-pill-sep"> • </span>';
        html += (i === path.length - 1) ? ('<b>' + pomoEsc(path[i]) + '</b>') : ('<span class="pomo-pill-anc">' + pomoEsc(path[i]) + '</span>');
    }
    el.innerHTML = html;
}

document.addEventListener('projectselected', function(e){
    if (e.detail.uid !== 'pomo') return;
    projectId = e.detail.id; projectName = e.detail.name; projectColor = e.detail.color;
    pomoUpdateProjectPill();
    if (phase === 'idle') {
        applyProjectTheme(projectId ? projectColor : '');
    } else if (phase === 'running' || phase === 'paused_task') {
        // la sessione è già partita: salviamo subito il collegamento lato server, altrimenti
        // si perde tornando su questa pagina più tardi (bug #9 della lista sistemazioni)
        applyProjectTheme(projectId ? projectColor : '');
        post('pomodoro.php?ajax=set_project', { session_id: activeSessionId, project_id: projectId || 0 });
    }
});

/* ---------------- popup durata ---------------- */
function buildPills(container, presets, selected, kind){
    container.innerHTML = '';
    var defaults = kind === 'pomo' ? [25, 50] : [5, 10]; 

    presets.forEach(function(min){
        var isCustom = defaults.indexOf(min) === -1;
        var bWrap = document.createElement('div');
        bWrap.className = 'pill-wrap';

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'dur-pill' + (min === selected ? ' active' : '');
        
        b.style.cssText = "-webkit-user-select: none; user-select: none; -webkit-touch-callout: none;";
        b.oncontextmenu = function(e) { e.preventDefault(); }; 
                
        var h = Math.floor(min / 60); var m = min % 60;
        b.textContent = h > 0 ? (h + 'h ' + (m > 0 ? m + 'm' : '')) : (m + ' min');

        if (isCustom) {
            var delBadge = document.createElement('button');
            delBadge.innerHTML = '-';
            delBadge.className = 'cdur-badge';
            delBadge.onclick = function(e){
                e.stopPropagation();
                deleteCustomDur(min, kind);
            };
            bWrap.appendChild(delBadge);

            b.onpointerdown = function(e){
                if(e.target.closest('.cdur-badge')) return;
                
                b.style.transform = 'scale(0.94)';
                b._isLongPress = false; 
                
                b._pressTimer = setTimeout(function(){
                    b._isLongPress = true; 
                    buzz(15);
                    document.querySelectorAll('.cdur-badge').forEach(function(badge){ badge.classList.remove('show'); });
                    delBadge.classList.add('show');
                }, 500);
            };

            b.onpointermove = function(e){
                if (Math.abs(e.movementX) > 5 || Math.abs(e.movementY) > 5) {
                    clearTimeout(b._pressTimer);
                }
            };

            var cancelPress = function(e){ 
                clearTimeout(b._pressTimer); 
                b.style.transform = ''; 
            };
            b.onpointerup = cancelPress;
            b.onpointerleave = cancelPress;
            b.onpointercancel = cancelPress;
        } // <--- ECCO LA PARENTESI RISOLTA!

        b.onclick = function(e){
            if (b._isLongPress) {
                b._isLongPress = false; 
                return; 
            }

            var anyBadgeShowing = document.querySelector('.cdur-badge.show');
            if (anyBadgeShowing) {
                document.querySelectorAll('.cdur-badge').forEach(function(badge){ badge.classList.remove('show'); });
                return; 
            }

            container.querySelectorAll('.dur-pill').forEach(function(p){ p.classList.remove('active'); });
            b.classList.add('active');
            if (kind === 'pomo') selDurataPomo = min; else selDurataPausa = min;

            // salva subito la preferenza: non solo all'avvio di una sessione, così
            // dopo un refresh la durata scelta resta quella (bug segnalato)
            post('pomodoro.php?ajax=set_last_duration', { tipo: kind === 'pomo' ? 'pomodoro' : 'pausa', minuti: min });

            // Aggiorna il testo della pausa in tempo reale se il timer è a riposo!
            if (phase === 'idle') renderIdleDisplay();
        };
        
        bWrap.appendChild(b);
        container.appendChild(bWrap);
    });

    var add = document.createElement('button');
    add.type = 'button'; add.className = 'dur-pill dur-pill-add'; add.textContent = '+ personalizza';
    add.onclick = function(){ openCustomDur(kind); };
    container.appendChild(add);
}

var currentCustomKind = 'pomo';

window.openCustomDur = function(kind) {
    currentCustomKind = kind;
    document.getElementById('cdurTipoTit').textContent = kind === 'pomo' ? 'Pomodoro' : 'Pausa';
    document.getElementById('cdurTime').value = '00:00';
    document.getElementById('customDurOverlay').classList.add('show');
};

window.closeCustomDur = function() {
    document.getElementById('customDurOverlay').classList.remove('show');
};

window.saveCustomDur = function() {
    var t = document.getElementById('cdurTime').value;
    if(!t) return;
    var p = t.split(':');
    var n = parseInt(p[0],10)*60 + parseInt(p[1],10);
    if (!n || n <= 0) return;

    post('pomodoro.php?ajax=salva_preset', { tipo: currentCustomKind === 'pomo' ? 'pomodoro' : 'pausa', minuti: n }).then(function(){
        if (currentCustomKind === 'pomo') {
            if (STATE0.presets_pomodoro.indexOf(n) === -1) {
                STATE0.presets_pomodoro.push(n);
                STATE0.presets_pomodoro.sort(function(a,b){return a-b;});
            }
            selDurataPomo = n;
            buildPills(document.getElementById('durPomoPills'), STATE0.presets_pomodoro, n, 'pomo');
        } else {
            if (STATE0.presets_pausa.indexOf(n) === -1) {
                STATE0.presets_pausa.push(n);
                STATE0.presets_pausa.sort(function(a,b){return a-b;});
            }
            selDurataPausa = n;
            buildPills(document.getElementById('durPausaPills'), STATE0.presets_pausa, n, 'pausa');
        }
        closeCustomDur();
    });
};

window.deleteCustomDur = function(n, kind) {
    post('pomodoro.php?ajax=elimina_preset', { tipo: kind === 'pomo' ? 'pomodoro' : 'pausa', minuti: n }).then(function(){
        if (kind === 'pomo') {
            STATE0.presets_pomodoro = STATE0.presets_pomodoro.filter(function(x){ return x !== n; });
            if(selDurataPomo === n) selDurataPomo = STATE0.presets_pomodoro[0];
            buildPills(document.getElementById('durPomoPills'), STATE0.presets_pomodoro, selDurataPomo, 'pomo');
        } else {
            STATE0.presets_pausa = STATE0.presets_pausa.filter(function(x){ return x !== n; });
            if(selDurataPausa === n) selDurataPausa = STATE0.presets_pausa[0];
            buildPills(document.getElementById('durPausaPills'), STATE0.presets_pausa, selDurataPausa, 'pausa');
        }
    });
};

// Chiudi i bollini rossi se tocchi in qualsiasi punto vuoto del popup
document.addEventListener('pointerdown', function(e){
    if (!e.target.closest('.cdur-badge') && !e.target.closest('.dur-pill')) {
        document.querySelectorAll('.cdur-badge').forEach(function(badge){ badge.classList.remove('show'); });
    }
});

var currentCustomKind = 'pomo';

window.openCustomDur = function(kind) {
    currentCustomKind = kind;
    document.getElementById('cdurTipoTit').textContent = kind === 'pomo' ? 'Pomodoro' : 'Pausa';
    document.getElementById('cdurTime').value = '00:00';
    renderCustomList();
    document.getElementById('customDurOverlay').classList.add('show');
};

window.closeCustomDur = function() {
    document.getElementById('customDurOverlay').classList.remove('show');
};

window.renderCustomList = function() {
    var container = document.getElementById('cdurList');
    container.innerHTML = '';
    
    var presets = currentCustomKind === 'pomo' ? STATE0.presets_pomodoro : STATE0.presets_pausa;
    var defaults = currentCustomKind === 'pomo' ? [25, 50] : [5, 10]; // I preset di fabbrica
    var customs = presets.filter(function(m){ return defaults.indexOf(m) === -1; });
    // durata attualmente attiva, per evidenziarla già selezionata in questo elenco
    var selectedVal = currentCustomKind === 'pomo' ? selDurataPomo : selDurataPausa;
    var markColor = currentCustomKind === 'pomo' ? 'var(--pomo-accent)' : 'var(--teal)';

    if(customs.length === 0) {
        container.innerHTML = '<div class="cdur-empty">Nessuna durata personalizzata.</div>';
        return;
    }

    customs.forEach(function(m){
        var h = Math.floor(m / 60); var min = m % 60;
        var label = h > 0 ? (h + 'h ' + (min > 0 ? min + 'm' : '')) : (min + 'm');
        var isActive = (m === selectedVal);

        var row = document.createElement('div');
        row.className = 'cdur-row' + (isActive ? ' active' : '');
        row.style.setProperty('--cdur-mark', markColor);
        row.innerHTML = '<span class="cdur-row-label">' +
                        '<svg class="cdur-row-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>' +
                        '<span>' + label + '</span></span>' +
                        '<button type="button" class="cdur-row-del" onclick="deleteCustomDur('+m+')" aria-label="Elimina">' +
                        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>' +
                        '</button>';
        container.appendChild(row);
    });
};

window.saveCustomDur = function() {
    var t = document.getElementById('cdurTime').value;
    if(!t) return;
    var p = t.split(':');
    var n = parseInt(p[0],10)*60 + parseInt(p[1],10);
    if (!n || n <= 0) return;

    post('pomodoro.php?ajax=salva_preset', { tipo: currentCustomKind === 'pomo' ? 'pomodoro' : 'pausa', minuti: n }).then(function(){
        if (currentCustomKind === 'pomo') {
            if (STATE0.presets_pomodoro.indexOf(n) === -1) STATE0.presets_pomodoro.push(n);
            STATE0.presets_pomodoro.sort(function(a,b){return a-b;});
            selDurataPomo = n;
            buildPills(document.getElementById('durPomoPills'), STATE0.presets_pomodoro, n, 'pomo');
        } else {
            if (STATE0.presets_pausa.indexOf(n) === -1) STATE0.presets_pausa.push(n);
            STATE0.presets_pausa.sort(function(a,b){return a-b;});
            selDurataPausa = n;
            buildPills(document.getElementById('durPausaPills'), STATE0.presets_pausa, n, 'pausa');
        }
        // anche una durata personalizzata appena creata (e auto-selezionata) va salvata subito come preferenza
        post('pomodoro.php?ajax=set_last_duration', { tipo: currentCustomKind === 'pomo' ? 'pomodoro' : 'pausa', minuti: n });
        document.getElementById('cdurTime').value = '00:00';
        renderCustomList();
    });
};

window.deleteCustomDur = function(n, kind){
    // Usa il parametro "kind" se passato (per i bottoncini - nel popup principale),
    // altrimenti usa la variabile globale "currentCustomKind" (per l'elenco testuale)
    var activeKind = kind || currentCustomKind;

    post('pomodoro.php?ajax=elimina_preset', { tipo: activeKind === 'pomo' ? 'pomodoro' : 'pausa', minuti: n }).then(function(){
        if (activeKind === 'pomo') {
            STATE0.presets_pomodoro = STATE0.presets_pomodoro.filter(function(x){ return x !== n; });
            // se elimino quella attualmente selezionata, torno sempre al default di fabbrica (25), non al primo rimasto in lista
            if(selDurataPomo === n) selDurataPomo = 25;
            buildPills(document.getElementById('durPomoPills'), STATE0.presets_pomodoro, selDurataPomo, 'pomo');
        } else {
            STATE0.presets_pausa = STATE0.presets_pausa.filter(function(x){ return x !== n; });
            if(selDurataPausa === n) selDurataPausa = 5;
            buildPills(document.getElementById('durPausaPills'), STATE0.presets_pausa, selDurataPausa, 'pausa');
        }
        if (phase === 'idle') renderIdleDisplay();
        renderCustomList();
    });
};

window.openDurPopup = function(){
    buildPills(document.getElementById('durPomoPills'), STATE0.presets_pomodoro, selDurataPomo, 'pomo');
    document.getElementById('durPomoHalf').style.display = mode === 0 ? '' : 'none';
    buildPills(document.getElementById('durPausaPills'), STATE0.presets_pausa, selDurataPausa, 'pausa');
    document.getElementById('durOverlay').classList.add('show');
};
window.closeDurPopup = function(){ document.getElementById('durOverlay').classList.remove('show'); };
window.confirmDurPopup = function(){ 
    closeDurPopup(); 
    renderIdleDisplay(); 
    // doStart(); <-- RIMOSSO per evitare la partenza automatica
};
/* ---------------- rendering display (cerchio / clessidra) ---------------- */
function showRing(show){
    // doppia sicurezza: classe (per il CSS) + stile inline diretto (bypassa qualunque
    // altro CSS che dovesse involontariamente forzare display su questi elementi)
    var ring = document.getElementById('ringWrap'), glass = document.getElementById('glassWrap');
    ring.classList.toggle('is-hidden', !show);
    glass.classList.toggle('is-hidden', show);
    ring.style.display = show ? '' : 'none';
    glass.style.display = show ? 'none' : '';
}
window.toggleDisplayStyle = function(){
    displayStyle = displayStyle === 'cerchio' ? 'clessidra' : 'cerchio';
    showRing(displayStyle === 'cerchio');
    post('pomodoro.php?ajax=set_display_style', { stile: displayStyle });
    updateGrainFlow();
};
showRing(displayStyle === 'cerchio');

function renderIdleDisplay(){
    document.getElementById('ringTime').style.color = '';
    
    // Aggiungi questa riga per mostrare la durata della pausa
    document.getElementById('ringSub').textContent = 'Pausa: ' + selDurataPausa + ' min';
    
    if (mode === 0) {
        document.getElementById('ringTime').textContent = selDurataPomo + ':00';
    } else {
        document.getElementById('ringTime').textContent = '00:00';
    }
    setRingFraction(0);
    lastGlassFlipped = false;
    if (window.Clessidra) window.Clessidra.idle();
}
function setRingFraction(frac){
    frac = Math.max(0, Math.min(1, frac));
    document.getElementById('ringProgress').style.strokeDashoffset = (RING_CIRC * (1 - frac));
}
/* ---------------- clessidra: ora vive in clessidra.php, qui solo il collegamento ----------------
   Si tengono gli STESSI NOMI di funzione di prima (setGlassFraction/stopGrainFlow/updateGrainFlow)
   così il resto del codice (tick, resume, ecc.) non deve cambiare: cambia solo cosa fanno dentro. */
var lastGlassFlipped = false;
function setGlassFraction(frac, isPausaColor, flipped){
    if (!window.Clessidra) return;
    flipped = !!flipped;
    // il capovolgimento "silenzioso" serve solo al cronometro (si rigira ogni ora):
    // per l'inizio di una sessione vera e propria ci pensa playStartFlip(), chiamato altrove
    if (flipped !== lastGlassFlipped) { lastGlassFlipped = flipped; window.Clessidra.playStartFlip(); }
    window.Clessidra.setFraction(Math.max(0, Math.min(1, frac)));
}
function stopGrainFlow(){ if (window.Clessidra) window.Clessidra.setRunning(false); }
function updateGrainFlow(){
    var running = (phase === 'running' || phase === 'pausa_running') && displayStyle === 'clessidra';
    if (window.Clessidra) window.Clessidra.setRunning(running);
}

/* ---------------- azioni pulsanti principali ---------------- */
function switchActionsPanel(which){
    ['actionsIdle','actionsRunning','actionsCompleted','actionsPausaRunning','actionsPausaDone','actionsPausedTask'].forEach(function(id){
        document.getElementById(id).style.display = (id === which) ? '' : 'none';
    });
}

// Quando si clicca sul cerchio/clessidra, apri il popup di scelta durata
window.onDisplayClick = function() {
    // Permetti il cambio durata solo se il timer è fermo (idle)
    if (phase === 'idle') {
        openDurPopup();
    }
};

// Il tasto Start ora fa partire il timer DIRETTAMENTE
window.onStartClick = function(){
    requestNotifPermission();
    // Se siamo in modalità Timer (mode === 0), usa la durata già selezionata (selDurataPomo)
    // Se siamo in Cronometro, parte direttamente comunque
    doStart(); 
};

function doStart(){
    activeMode = mode;
    post('pomodoro.php?ajax=start', { mode: mode, durata_minuti: selDurataPomo, project_id: projectId || 0 })
    .then(function(data){
        if (!data.success) { toast('Errore, riprova'); return; }
        activeSessionId = data.session_id;
        
        // Portato a 500ms per un freeze più breve ed elegante
        segmentStartMs = Date.now() + 500; 
        
        accumulatedSec = 0;
        activeDurataMin = mode === 0 ? selDurataPomo : null;
        phase = 'running'; notifiedEnd = false;
        document.getElementById('overtimeChip').style.display = 'none';
        switchActionsPanel('actionsRunning');
        
        // RIMOSSO il blocco che nascondeva il tasto pausa se mode === 1 (cronometro)
        // Adesso il tasto pausa compare in entrambe le modalità!
        midPauseBtn.style.display = ''; 
        
        applyProjectTheme(projectId ? projectColor : '');
        document.getElementById('statusLabel').textContent = mode === 0 ? 'Concentrazione in corso' : 'Cronometro in corso';
        document.getElementById('ringTime').style.color = ''; 
        document.getElementById('ringSub').textContent = ''; 
        updateLockUI();
        if (window.Clessidra) window.Clessidra.playStartFlip();
        setTimeout(updateGrainFlow, 850); // avvia la pioggia di granelli solo a capovolgimento concluso
        startTicking();
    }).catch(function(){ toast('Errore di rete'); });
}

/* la pausa CONGELA la sessione (stessa riga sul server, si accumula durata_secondi) e mostra
   subito il tempo mancante fermo, con sotto solo "Riprendi"/"Interrompi": vedi bug #6 */
window.onMidPauseClick = function(){
    // Calcoliamo il tempo esatto, con l'arrotondamento corretto per non far "saltare" il secondo visivo
    var textElapsed = accumulatedSec + Math.max(0, (Date.now() - segmentStartMs) / 1000);
    // Usa ceil (per eccesso) per il Timer, floor (per difetto) per il Cronometro
    var elapsedSec = (activeMode === 0) ? Math.ceil(textElapsed) : Math.floor(textElapsed);
    
    post('pomodoro.php?ajax=pausa_task', { 
        session_id: activeSessionId,
        tempo_esatto: elapsedSec // Inviamo il tempo già normalizzato
    }).then(function(data){
        stopTicking(); stopGrainFlow();
        accumulatedSec = data.durata_secondi || 0;
        phase = 'paused_task';
        switchActionsPanel('actionsPausedTask');
        document.getElementById('statusLabel').textContent = 'Sessione in pausa';
        document.getElementById('overtimeChip').style.display = 'none';
        renderFrozenPausedDisplay();
        updateLockUI();
    });
};
function renderFrozenPausedDisplay(){
    // congela il quadrante sul tempo mancante (timer) o trascorso (cronometro): non
    // viene più aggiornato finché non si preme "Riprendi"
    if (activeMode === 0) {
        var total = (activeDurataMin || 0) * 60;
        var remaining = Math.max(0, total - accumulatedSec);
        var frac = total > 0 ? Math.min(1, accumulatedSec / total) : 0;
        document.getElementById('ringTime').textContent = fmtHMS(remaining);
        setRingFraction(frac); setGlassFraction(frac, false, lastGlassFlipped);
    } else {
        var hourFrac = (accumulatedSec % 3600) / 3600;
        var flipCount = Math.floor(accumulatedSec / 3600);
        var flipped = flipCount % 2 === 1;
        document.getElementById('ringTime').textContent = fmtHMS(accumulatedSec);
        setRingFraction(hourFrac);
        setGlassFraction(hourFrac, false, flipped);
    }
}
// DOPO:
    window.onResumeTaskClick = function(){
        post('pomodoro.php?ajax=riprendi_task', { session_id: activeSessionId }).then(function(data){
            segmentStartMs = Date.now();
            phase = 'running';
            switchActionsPanel('actionsRunning');
            document.getElementById('statusLabel').textContent = activeMode === 0 ? 'Concentrazione in corso' : 'Cronometro in corso';
            updateLockUI(); updateGrainFlow();
            startTicking();
        });
    };

window.onInterrompiClick = function(){
    var elapsedSec = phase === 'paused_task' ? accumulatedSec : (accumulatedSec + (Date.now() - segmentStartMs) / 1000);
    if (elapsedSec < 60) { doInterrompi(true); return; }
    if (elapsedSec < 300) {
        showConfirm('Sessione molto breve', 'Sono passati meno di 5 minuti: vuoi comunque salvarla?', function(keep){ doInterrompi(!keep); });
        return;
    }
    doInterrompi(false);
};
function doInterrompi(elimina){
    post('pomodoro.php?ajax=interrompi', { session_id: activeSessionId, elimina: elimina ? 1 : 0 }).then(function(){
        stopTicking(); stopGrainFlow();
        phase = 'idle';
        accumulatedSec = 0; segmentStartMs = null;
        switchActionsPanel('actionsIdle');
        document.getElementById('overtimeChip').style.display = 'none';
        document.getElementById('statusLabel').textContent = 'Pronto quando vuoi';
        renderIdleDisplay();
        updateLockUI();
        toast(elimina ? 'Sessione scartata' : 'Sessione salvata');
    });
}

function onNaturalComplete(){
    if (notifiedEnd) return;
    notifiedEnd = true;
    buzz([80,40,80]);
    notify('Pomodoro concluso 🍅', projectId ? ('Hai completato: ' + projectName) : 'Ottimo lavoro!');
    stopGrainFlow();
    if (window.Clessidra) window.Clessidra.finish();
    post('pomodoro.php?ajax=segna_completato', { session_id: activeSessionId }).then(function(data){
        completedEndMs = data.data_fine ? new Date(data.data_fine.replace(' ', 'T')).getTime() : Date.now();
        phase = 'completed';
        accumulatedSec = (activeDurataMin || 0) * 60; // Salva il totale dei secondi
        switchActionsPanel('actionsCompleted');
        document.getElementById('statusLabel').textContent = 'Pomodoro completato';
        /* a pomodoro finito si chiede cosa fare delle attività del post-it */
        if (window.pomoTodoAskOnFinish) window.pomoTodoAskOnFinish();
        document.getElementById('overtimeChip').style.display = 'flex';
        document.getElementById('ringTime').style.color = 'var(--pomo-accent)'; //colore completato
        document.getElementById('ringTime').textContent = fmtHMS(accumulatedSec); // Mostra il totale!
        updateLockUI();
    });
}
window.onExtendClick = function(){
    post('pomodoro.php?ajax=estendi', { session_id: activeSessionId }).then(function(data){
        completedEndMs = Date.now(); // <-- Usiamo esclusivamente l'orologio locale!
        if (data.durata_secondi) accumulatedSec = data.durata_secondi; 
        document.getElementById('ringTime').textContent = fmtHMS(accumulatedSec); 
        buzz(15);
        toast('Tempo esteso');
    });
};

window.onAvviaPausaClick = function(){
    if (phase === 'completed') { 
        post('pomodoro.php?ajax=archivia_completato', { session_id: activeSessionId }); 
    }
    post('pomodoro.php?ajax=avvia_pausa', { project_id: projectId || 0, durata_minuti: selDurataPausa }).then(function(){
        // Portato a 500ms anche per la pausa
        pausaStartMs = Date.now() + 500; 
        
        pausaDurataMin = selDurataPausa; notifiedPausaEnd = false;
        phase = 'pausa_running';
        document.body.classList.add('pausa-mode');
        switchActionsPanel('actionsPausaRunning');
        document.getElementById('overtimeChip').style.display = 'none';
        document.getElementById('statusLabel').textContent = 'Pausa in corso';
        updateLockUI();
        if (window.Clessidra) window.Clessidra.playStartFlip();
        setTimeout(updateGrainFlow, 850);
        startTicking();
    });
};

window.onChiudiCompletato = function(){
    post('pomodoro.php?ajax=archivia_completato', { session_id: activeSessionId }).then(function(){
        phase = 'idle';
        switchActionsPanel('actionsIdle');
        document.getElementById('statusLabel').textContent = 'Pronto quando vuoi';
        document.getElementById('overtimeChip').style.display = 'none';
        renderIdleDisplay();
        updateLockUI();
    });
};

window.onFinePausaClick = function(action){
    post('pomodoro.php?ajax=fine_pausa', {}).then(function(){
        document.body.classList.remove('pausa-mode');
        stopTicking(); stopGrainFlow();
        
        if (action === 'nuovo_pomodoro') {
            phase = 'idle'; 
            mode = 0; // Forza la modalità Timer
            
            // Aggiorna visivamente lo switch in alto
            document.querySelectorAll('#modeSwitch button').forEach(function(b){ 
                b.classList.toggle('active', b.getAttribute('data-mode') === '0'); 
            });
            
            renderIdleDisplay();
            switchActionsPanel('actionsIdle');
            document.getElementById('statusLabel').textContent = 'Pronto per un altro pomodoro';
            updateLockUI();
            
            // Fai partire immediatamente il nuovo Pomodoro senza mostrare il popup
            doStart();
        } else {
            phase = 'idle';
            switchActionsPanel('actionsIdle');
            document.getElementById('statusLabel').textContent = 'Pronto quando vuoi';
            renderIdleDisplay();
            updateLockUI();
        }
    });
};

/* ---------------- ticking ---------------- */
function startTicking(){
    stopTicking();
    tick();
    tickTimer = setInterval(tick, 250);
}
function stopTicking(){ if (tickTimer) { clearInterval(tickTimer); tickTimer = null; } }

function tick(){
    if (phase === 'running') {
        var now = Date.now();
        // Sottraiamo i 500ms di freeze direttamente alla sorgente
        var elapsed = accumulatedSec + Math.max(0, (now - segmentStartMs) / 1000);
        var isTimer = (activeMode === 0);

        // Calcolo del tempo per il TESTO NUMERICO (con freeze di 500ms)
        var textElapsed = accumulatedSec + Math.max(0, (now - segmentStartMs) / 1000);

        // Calcolo del tempo per la GRAFICA (barra e sabbia: partono istantaneamente)
        // Per il timer si calcola dallo start anticipato, per il cronometro coincide con il testo
        var realElapsed = isTimer 
            ? (accumulatedSec + Math.max(0, (now - (segmentStartMs - 500)) / 1000))
            : textElapsed;

        if (isTimer) {
            // --- MODALITÀ TIMER ---
            var total = (activeDurataMin || 0) * 60;
            var remainingText = total - textElapsed;
            if (total - realElapsed <= 0) { onNaturalComplete(); return; }
            
            document.getElementById('ringTime').textContent = fmtHMS(remainingText);
            setRingFraction(total > 0 ? realElapsed / total : 0);
            setGlassFraction(total > 0 ? realElapsed / total : 0, false, false);
        } else {
            // --- MODALITÀ CRONOMETRO ---
            // Il testo mostra i secondi reali passati (bloccati per i primi 500ms a 00:00)
            document.getElementById('ringTime').textContent = fmtHMS(textElapsed);
            
            var hourFrac = (realElapsed % 3600) / 3600;
            setRingFraction(hourFrac);
            var flipCount = Math.floor(realElapsed / 3600);
            var flipped = flipCount % 2 === 1;
            setGlassFraction(hourFrac, false, flipped);
        }
    } else if (phase === 'completed') {
        var overSec = (Date.now() - completedEndMs) / 1000;
        document.getElementById('overtimeValue').textContent = fmtHMS(overSec);
    } else if (phase === 'pausa_running') {
        var nowP = Date.now();
        var pRealElapsed = Math.max(0, (nowP - (pausaStartMs - 500)) / 1000);
        var pTextElapsed = Math.max(0, (nowP - pausaStartMs) / 1000);
        var pTotal = pausaDurataMin * 60;
        var pRemainingText = pTotal - pTextElapsed;

        // Applica il colore verde al testo del timer durante la pausa
        document.getElementById('ringTime').style.color = 'var(--pausa-accent)';

        if (pTotal - pRealElapsed <= 0 && !notifiedPausaEnd) {
            notifiedPausaEnd = true; buzz([60,30,60]);
            notify('Pausa finita 🌿', 'È ora di tornare al lavoro.');
            phase = 'pausa_done';
            switchActionsPanel('actionsPausaDone');
            document.getElementById('statusLabel').textContent = 'Pausa conclusa';
            stopGrainFlow(); updateLockUI();
        }
        if (pRemainingText > 0) {
            document.getElementById('ringTime').textContent = fmtHMS(pRemainingText);
            setRingFraction(pRealElapsed / pTotal); setGlassFraction(pRealElapsed / pTotal, true, false);
        } else {
            document.getElementById('ringTime').textContent = '+' + fmtHMS(Math.max(0, pTextElapsed - pTotal));
            setRingFraction(1); setGlassFraction(1, true, false);
        }
    } else if (phase === 'pausa_done') {
        var overP = (Date.now() - (pausaStartMs + pausaDurataMin*60*1000)) / 1000;
        document.getElementById('ringTime').style.color = 'var(--pausa-accent)';
        document.getElementById('ringTime').textContent = '+' + fmtHMS(overP);
    }
}

// Funzione helper di supporto interna per il calcolo pulito del testo fissa
function textSummary(val) { return Math.max(0, val); }

/* ---------------- conferma generica ---------------- */
var confirmCallback = null;
function showConfirm(title, msg, cb){
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent = msg;
    confirmCallback = cb;
    document.getElementById('confirmOverlay').classList.add('show');
}
window.closeConfirm = function(result){
    document.getElementById('confirmOverlay').classList.remove('show');
    if (confirmCallback) { var cb = confirmCallback; confirmCallback = null; cb(result); }
};

/* ---------------- bootstrap dallo stato iniziale (server-rendered) ---------------- */
function bootstrap(){
    document.querySelectorAll('#modeSwitch button').forEach(function(b){ b.classList.toggle('active', parseInt(b.getAttribute('data-mode'),10) === mode); });

    if (STATE0.preselect) {
        projectId = STATE0.preselect.id; projectName = STATE0.preselect.name; projectColor = STATE0.preselect.color;
        if (window.psSetValue) window.psSetValue('ps-modal-pomo', projectId, projectName, projectColor);
    }

    if (STATE0.active) {
        var a = STATE0.active;
        activeSessionId = a.id; activeMode = a.mode; mode = a.mode;
        activeDurataMin = a.durata_prevista_minuti;
        accumulatedSec = a.durata_secondi || 0;
        if (a.project_id) { projectId = a.project_id; projectName = a.progetto_nome; projectColor = a.progetto_colore; 
        if (window.psSetValue) window.psSetValue('ps-modal-pomo', projectId, projectName, projectColor); }
        document.querySelectorAll('#modeSwitch button').forEach(function(b){ b.classList.toggle('active', parseInt(b.getAttribute('data-mode'),10) === mode); });        
        document.getElementById('midPauseBtn').style.display = '';
        applyProjectTheme(projectId ? projectColor : '');

        if (a.esito === 'pausa') {
            // sessione congelata: mostriamo subito il tempo mancante fermo, niente ticking
            phase = 'paused_task';
            switchActionsPanel('actionsPausedTask');
            document.getElementById('statusLabel').textContent = 'Sessione in pausa';
            renderFrozenPausedDisplay();
        } else {
            segmentStartMs = a.data_ripresa ? new Date(a.data_ripresa.replace(' ', 'T')).getTime() : new Date(a.data_inizio.replace(' ', 'T')).getTime();
            if (isNaN(segmentStartMs)) segmentStartMs = Date.now();
            phase = 'running';
            switchActionsPanel('actionsRunning');
            document.getElementById('statusLabel').textContent = mode === 0 ? 'Concentrazione in corso' : 'Cronometro in corso';
            startTicking();
        }
        updateLockUI(); updateGrainFlow();
    } else if (STATE0.pausa) {
        var p = STATE0.pausa;
        pausaStartMs = new Date(p.start_time.replace(' ', 'T')).getTime();
        pausaDurataMin = p.durata_prevista_minuti;
        if (p.project_id) { projectId = p.project_id; projectName = p.progetto_nome; projectColor = p.progetto_colore; }
        phase = 'pausa_running';
        document.body.classList.add('pausa-mode');
        switchActionsPanel('actionsPausaRunning');
        document.getElementById('statusLabel').textContent = 'Pausa in corso';
        updateLockUI(); updateGrainFlow();
        startTicking();
    } else if (STATE0.last && STATE0.last.esito === 'completato') {
        var c = STATE0.last;
        activeSessionId = c.id; activeMode = c.mode; mode = c.mode;
        completedEndMs = new Date(c.data_fine.replace(' ', 'T')).getTime();
        accumulatedSec = c.durata_secondi || (c.durata_prevista_minuti * 60) || 0; // Recupera il totale!
        if (c.project_id) { projectId = c.project_id; projectName = c.progetto_nome; projectColor = c.progetto_colore; if (window.psSetValue) window.psSetValue('ps-modal-pomo', projectId, projectName, projectColor); }
        phase = 'completed';
        switchActionsPanel('actionsCompleted');
        document.getElementById('overtimeChip').style.display = 'flex';
        document.getElementById('statusLabel').textContent = 'Pomodoro completato';
        applyProjectTheme(projectId ? projectColor : '');
        document.getElementById('ringTime').style.color = 'var(--pomo-accent)';
        document.getElementById('ringTime').textContent = fmtHMS(accumulatedSec);
        setRingFraction(1);
        if (window.Clessidra) window.Clessidra.finish();
        updateLockUI();
        startTicking();
    } else {
        renderIdleDisplay();
        updateLockUI();
    }
}
bootstrap();
pomoUpdateProjectPill(); // richiesta 17: mostra la catena nonno•padre•progetto dopo l'idratazione

})();
</script>
<?php include 'todo_widget.php'; ?>
<script>
/* Attività del pomodoro: il post-it con il timer in alto e l'elenco di cosa
   fare. Le attività ereditano orario di inizio e progetto del pomodoro, quindi
   nel post-it non ci sono i campi data, ora, durata e progetto. */
(function pomoTodo(){
    var btn   = document.getElementById('pomoTodoBtn');
    var ov    = document.getElementById('pomoTodoOv');
    var list  = document.getElementById('pomoTodoList');
    var close = document.getElementById('pomoTodoClose');
    if (!btn || !ov || !list) return;

    var inst = null;

    function pad2(n){ return (n < 10 ? '0' : '') + n; }
    function today(){
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate());
    }
    function nowHM(){
        var d = new Date();
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function mount(){
        if (!window.nisamaTodo) return;
        /* se il pomodoro ha già un progetto, si vedono le sue attività */
        /* Il progetto del pomodoro: quello scelto adesso nel selettore, o
           quello arrivato nell'indirizzo. La variabile "projectId" non esiste
           in questa pagina: era quello l'errore all'apertura del post-it. */
        var pid = 0;
        /* la variabile della pagina, se è raggiungibile da qui */
        try { if (typeof projectId !== 'undefined' && projectId) pid = parseInt(projectId, 10) || 0; } catch (e) {}
        if (!pid) {
            var hidden = document.querySelector('input[name="project_id"], #ps-value-pomo');
            if (hidden) pid = parseInt(hidden.value, 10) || 0;
        }
        if (!pid) pid = <?php echo (int)($preselectProjectId ?? 0); ?>;

        var opts = {
            scope: pid ? 'project' : 'pomodoro',
            projectId: pid || 0,
            compact: true,
            defaultDate: today(),
            defaultTime: nowHM(),
            defaultProjectId: pid || 0
        };
        inst = window.nisamaTodo.mount(list, opts);
    }

    btn.addEventListener('click', function(){
        ov.classList.add('show');
        btn.classList.add('is-on');
        if (!inst) mount(); else inst.load();
        syncRing();
    });
    function hide(){ ov.classList.remove('show'); btn.classList.remove('is-on'); }
    close.addEventListener('click', hide);
    ov.addEventListener('click', function(e){ if (e.target === ov) hide(); });

    /* il timer in piccolo dentro il post-it segue quello grande */
    function syncRing(){
        var big = document.getElementById('ringTime');
        var small = document.getElementById('pomoTodoTime');
        if (big && small) small.textContent = big.textContent;
        var ring = document.getElementById('pomoTodoRing');
        var prog = document.getElementById('ringProgress');
        if (ring && prog) {
            var full = 628.3, mine = 94.2;
            var off = parseFloat(prog.getAttribute('stroke-dashoffset') || full);
            ring.setAttribute('stroke-dashoffset', (off / full * mine).toFixed(1));
        }
    }
    setInterval(function(){ if (ov.classList.contains('show')) syncRing(); }, 1000);

    /* A fine pomodoro: cosa fare delle attività del post-it. */
    window.pomoTodoAskOnFinish = function(){
        if (!inst || !inst.items || !inst.items.length) return;
        var ids = inst.items.map(function(t){ return t.id; });
        var doneCount = inst.items.filter(function(t){ return t.done; }).length;

        var box = document.createElement('div');
        box.className = 'pomo-todo-ov show';
        box.innerHTML = '<div class="pomo-postit" style="max-width:320px">'
            + '<span class="pomo-postit-tape"></span>'
            + '<div style="font-family:Georgia,serif;font-size:19px;margin-bottom:6px">Le attività di questo pomodoro</div>'
            + '<div style="font-size:13px;color:#8A8177;line-height:1.5;margin-bottom:16px">'
            + doneCount + ' su ' + ids.length + ' spuntate. Cosa ne facciamo?</div>'
            + '<div class="pt-acts" style="display:flex;flex-direction:column;gap:7px"></div></div>';
        document.body.appendChild(box);

        var acts = box.querySelector('.pt-acts');
        function act(label, mode, primary){
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = label;
            b.style.cssText = 'padding:11px;border-radius:13px;border:none;cursor:pointer;font:inherit;font-size:14px;'
                + (primary ? 'background:#A9C3AE;color:#FFFDFA' : 'background:#F5F1EA;color:#4A443D');
            b.addEventListener('click', function(){
                window.nisamaTodo.api('pomodoro_close', { mode: mode, ids: ids }).then(function(){
                    box.remove();
                    if (inst) inst.load();
                });
            });
            acts.appendChild(b);
        }
        act('Elimina quelle spuntate', 'done', true);
        act('Elimina tutte', 'all', false);
        act('Tienile tutte', 'keep', false);
    };
})();
</script>
</body>
</html>