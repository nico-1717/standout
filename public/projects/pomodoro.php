<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/pomodoro_stato.php';   // il tetto delle 10 ore, pomo_color() e pomo_leggi_stato()
$current_user_id = $utente_corrente['id'];

/* La barra del pomodoro serve sulle ALTRE pagine: qui ci sarebbe il doppione
   di quello che si sta gia' guardando. La costante la legge config.php quando
   decide se innestarla. */
define('NISAMA_NIENTE_BARRA', true);

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

    /* Lo stato, cosi' com'e' adesso. Lo chiede il controllo periodico del
       motore (per accorgersi di cosa e' stato fatto da un altro dispositivo)
       ed e' la stessa identica cosa che la pagina si porta dentro al
       caricamento: una sola funzione la costruisce, quindi non possono
       raccontarla in due modi diversi. E' una lettura, non una scrittura,
       percio' non passa dal token. */
    if ($ajax === 'stato') {
        pomo_json(['success' => true, 'stato' => pomo_leggi_stato((int)$current_user_id)]);
    }

    if ($ajax === 'start') {
        $mode = sec_int($_POST['mode'] ?? 0, 0, 1);
        /* 1..600: senza un tetto "durata 999999999" finiva nel database com'era */
        $durataMin = $mode === 0 ? sec_int($_POST['durata_minuti'] ?? 25, 1, 600, 25) : null;
        $progettoId = sec_int($_POST['project_id'] ?? 0, 0);

        /* il progetto dev'essere suo: stessa verifica di prima, detta con la
           funzione che il documento indica per questo scopo */
        if ($progettoId > 0 && !sec_owns('projects', $progettoId, (int)$current_user_id)) $progettoId = 0;
        /* null e non la stringa 'NULL': con un segnaposto il valore non viene piu'
           incollato nella query, quindi il NULL va passato come valore vero. */
        $progVal = $progettoId > 0 ? $progettoId : null;
        $durVal = $durataMin !== null ? $durataMin : null;

        /* Due dispositivi, due tasti avvia: senza questo controllo nascevano
           due sessioni aperte insieme, e da quel momento i totali per progetto
           contavano due volte lo stesso tempo. La verifica va fatta QUI e non
           sul telefono: solo il server vede tutti i dispositivi insieme, e fra
           il controllo del client e la sua scrittura ci passa la rete.
           Chi viene rifiutato riceve indietro lo stato vero, cosi' puo'
           mostrarlo invece della propria intenzione. */
        $giaAperta = sec_one("SELECT id FROM pomodoro_sessions
                              WHERE user_id=? AND end_time IS NULL ORDER BY id DESC LIMIT 1",
                             [(int)$current_user_id]);
        if ($giaAperta) {
            pomo_json([
                'success'   => false,
                'conflitto' => 'sessione_gia_attiva',
                'stato'     => pomo_leggi_stato((int)$current_user_id),
            ]);
        }

        sec_q("INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, project_id, planned_duration_min, duration_seconds)
               VALUES (?, ?, NOW(), ?, ?, 0)", [(int)$current_user_id, $mode, $progVal, $durVal]);
        $newId = sec_insert_id();

        // ricorda ultima modalità/durata scelta
        $durForSettings = $durataMin ?: 25;
        /* la parte finale c'e' solo in modalita' pomodoro: si aggiunge alla query E
           ai parametri insieme, cosi' il numero dei ? resta pari a quello dei valori */
        $sqlImp = "INSERT INTO pomodoro_settings (user_id, last_mode, last_pomodoro_duration_min)
                   VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_mode=?";
        $parImp = [(int)$current_user_id, $mode, $durForSettings, $mode];
        if ($mode === 0) { $sqlImp .= ", last_pomodoro_duration_min=?"; $parImp[] = $durForSettings; }
        sec_q($sqlImp, $parImp);

        $row = sec_one("SELECT id, start_time FROM pomodoro_sessions WHERE id=?", [$newId]);
        pomo_json(['success' => true, 'session_id' => (int)$newId, 'data_inizio' => $row['start_time']]);
    }

    if ($ajax === 'set_project') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        $newProjectId = sec_int($_POST['project_id'] ?? 0, 0);
        if ($newProjectId > 0) {
            $chkRow = sec_one("SELECT color FROM projects WHERE id=? AND user_id=?", [$newProjectId, (int)$current_user_id]);
            if (!$chkRow) { pomo_json(['success' => false]); }
            sec_q("UPDATE pomodoro_sessions SET project_id=? WHERE id=? AND user_id=? AND end_time IS NULL",
                  [$newProjectId, $sid, (int)$current_user_id]);
            pomo_json(['success' => true, 'color' => pomo_color($chkRow['color'])]);
        } else {
            /* era "proyecto_id", colonna che non esiste: la query falliva sempre in
               silenzio, quindi togliere il progetto da una sessione in corso non
               aveva alcun effetto. Il nome giusto e' project_id. */
            sec_q("UPDATE pomodoro_sessions SET project_id=NULL WHERE id=? AND user_id=? AND end_time IS NULL",
                  [$sid, (int)$current_user_id]);
            pomo_json(['success' => true, 'color' => '']);
        }
    }

    if ($ajax === 'pausa_task') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        /* 0..24 ore in secondi: oltre e' un errore del client, non un dato */
        $tempoEsatto = sec_float($_POST['tempo_esatto'] ?? 0, 0, 86400, 0);
        
        sec_q("UPDATE pomodoro_sessions
               SET duration_seconds = ?, status = 'pausa', resume_time = NULL
               WHERE id=? AND user_id=? AND end_time IS NULL",
              [$tempoEsatto, $sid, (int)$current_user_id]);

        $row = sec_one("SELECT duration_seconds FROM pomodoro_sessions WHERE id=?", [$sid]);
        pomo_json(['success' => true, 'durata_secondi' => $row ? (int)$row['duration_seconds'] : 0]);
    }

    if ($ajax === 'riprendi_task') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        sec_q("UPDATE pomodoro_sessions SET status=NULL, resume_time=NOW()
               WHERE id=? AND user_id=? AND end_time IS NULL AND status='pausa'", [$sid, (int)$current_user_id]);
        $row = sec_one("SELECT resume_time FROM pomodoro_sessions WHERE id=?", [$sid]);
        pomo_json(['success' => true, 'data_ripresa' => $row ? $row['resume_time'] : date('Y-m-d H:i:s')]);
    }

    if ($ajax === 'interrompi') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        $elimina = sec_bool($_POST['elimina'] ?? 0);
        if ($elimina) {
            sec_q("DELETE FROM pomodoro_sessions WHERE id=? AND user_id=?", [$sid, (int)$current_user_id]);
        } else {
            sec_q("UPDATE pomodoro_sessions
                   SET duration_seconds = COALESCE(duration_seconds,0) + CASE WHEN status='pausa' THEN 0 ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(resume_time, start_time), NOW())) END,
                       end_time=NOW(),
                       status='interrotto',
                       resume_time = NULL
                   WHERE id=? AND user_id=?", [$sid, (int)$current_user_id]);
        }
        pomo_json(['success' => true]);
    }

    if ($ajax === 'segna_completato') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        sec_q("UPDATE pomodoro_sessions SET end_time = DATE_ADD(COALESCE(resume_time, start_time), INTERVAL ((planned_duration_min * 60) - COALESCE(duration_seconds, 0)) SECOND), status='completato', duration_seconds = planned_duration_min * 60, resume_time = NULL WHERE id=? AND user_id=? AND end_time IS NULL",
              [$sid, (int)$current_user_id]);
        $row = sec_one("SELECT end_time FROM pomodoro_sessions WHERE id=?", [$sid]);
        pomo_json(['success' => true, 'data_fine' => $row ? $row['end_time'] : null]);
    }

    if ($ajax === 'estendi') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        sec_q("UPDATE pomodoro_sessions
               SET duration_seconds = COALESCE(duration_seconds,0) + GREATEST(0, TIMESTAMPDIFF(SECOND, end_time, NOW())), end_time=NOW()
               WHERE id=? AND user_id=? AND status='completato'", [$sid, (int)$current_user_id]);
        $row = sec_one("SELECT end_time, duration_seconds FROM pomodoro_sessions WHERE id=?", [$sid]);
        pomo_json(['success' => true, 'data_fine' => $row ? $row['end_time'] : null, 'durata_secondi' => $row ? (int)$row['duration_seconds'] : 0]);
    }

    if ($ajax === 'archivia_completato') {
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        sec_q("UPDATE pomodoro_sessions SET status='archiviato' WHERE id=? AND user_id=? AND status='completato'",
              [$sid, (int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'avvia_pausa') {
        $progettoId = sec_int($_POST['project_id'] ?? 0, 0);
        $durataMin = sec_int($_POST['durata_minuti'] ?? 5, 1, 600, 5);
        $progVal = $progettoId > 0 ? $progettoId : null;
        sec_q("INSERT INTO pomodoro_current_breaks (user_id, start_time, planned_duration_min, project_id)
               VALUES (?, NOW(), ?, ?)
               ON DUPLICATE KEY UPDATE start_time=NOW(), planned_duration_min=?, project_id=?",
              [(int)$current_user_id, $durataMin, $progVal, $durataMin, $progVal]);
        sec_q("UPDATE pomodoro_settings SET last_break_duration_min=? WHERE user_id=?",
              [$durataMin, (int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'fine_pausa') {
        sec_q("DELETE FROM pomodoro_current_breaks WHERE user_id=?", [(int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'salva_preset') {
        $tipo = sec_enum($_POST['tipo'] ?? null, ['pomodoro', 'pausa'], 'pomodoro');
        $minuti = sec_int($_POST['minuti'] ?? 0, 1, 600, 1);
        sec_q("INSERT IGNORE INTO pomodoro_custom_durations (user_id, type, minutes) VALUES (?, ?, ?)",
              [(int)$current_user_id, $tipo, $minuti]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'elimina_preset') {
        $tipo = sec_enum($_POST['tipo'] ?? null, ['pomodoro', 'pausa'], 'pomodoro');
        $minuti = sec_int($_POST['minuti'] ?? 0, 1, 600, 1);
        sec_q("DELETE FROM pomodoro_custom_durations WHERE user_id=? AND type=? AND minutes=?",
              [(int)$current_user_id, $tipo, $minuti]);

        // se la durata eliminata era quella "ultima usata" salvata per l'utente, la
        // riportiamo al default di fabbrica (25 pomodoro / 5 pausa): altrimenti al
        // prossimo caricamento della pagina ricompariva un valore ormai eliminato
        $defaultMin = ($tipo === 'pausa') ? 5 : 25;
        $col = ($tipo === 'pausa') ? 'last_break_duration_min' : 'last_pomodoro_duration_min';
        /* $col non puo' essere un segnaposto: i nomi di colonna non si legano, si
           scrivono. Qui e' sicuro perche' esce da un ternario con due valori fissi,
           non dall'esterno - ed e' l'unico punto della pagina in cui resta cosi'. */
        sec_q("UPDATE pomodoro_settings SET $col = ? WHERE user_id=? AND $col = ?",
              [$defaultMin, (int)$current_user_id, $minuti]);

        pomo_json(['success' => true]);
    }

    if ($ajax === 'set_last_duration') {
        // salva subito la durata scelta come preferenza, senza aspettare l'avvio di
        // una sessione: appena selezioni un valore nel popup, resta lì anche dopo un refresh
        $tipo = sec_enum($_POST['tipo'] ?? null, ['pomodoro', 'pausa'], 'pomodoro');
        $minuti = sec_int($_POST['minuti'] ?? 0, 1, 600, 1);
        $col = ($tipo === 'pausa') ? 'last_break_duration_min' : 'last_pomodoro_duration_min';
        // vale la stessa nota di sopra su $col
        sec_q("UPDATE pomodoro_settings SET $col = ? WHERE user_id=?", [$minuti, (int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'chiudi_avviso') {
        /* L'avviso "non e' stato terminato correttamente" deve sparire per
           sempre una volta letto, anche cambiando dispositivo: percio' il segno
           di lettura sta nel database e non in un cookie. 'abbandonato' vuol
           dire "chiuso d'ufficio e ancora da dire all'utente"; letto l'avviso
           diventa una normale sessione interrotta. */
        $sid = sec_int($_POST['session_id'] ?? 0, 1);
        if (!sec_owns('pomodoro_sessions', $sid, (int)$current_user_id)) pomo_json(['success' => false]);
        sec_q("UPDATE pomodoro_sessions SET status='interrotto' WHERE id=? AND user_id=? AND status='abbandonato'",
              [$sid, (int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    if ($ajax === 'set_display_style') {
        $stile = sec_enum($_POST['stile'] ?? null, ['cerchio', 'clessidra'], 'cerchio');
        sec_q("UPDATE pomodoro_settings SET last_display_style=? WHERE user_id=?", [$stile, (int)$current_user_id]);
        pomo_json(['success' => true]);
    }

    pomo_json(['success' => false, 'error' => 'azione sconosciuta']);
}

/* ==========================================================================
   IMPOSTAZIONI UTENTE (crea riga default se non esiste)
   ========================================================================== */
$imp = sec_one("SELECT * FROM pomodoro_settings WHERE user_id=?", [(int)$current_user_id]);
if (!$imp) {
    sec_q("INSERT INTO pomodoro_settings (user_id) VALUES (?)", [(int)$current_user_id]);
    $imp = ['last_mode' => 0, 'last_pomodoro_duration_min' => 25, 'last_break_duration_min' => 5, 'last_display_style' => 'cerchio'];
}

$presetsPomodoro = [25, 50];
$presetsPausa = [5, 10];
foreach (sec_all("SELECT type, minutes FROM pomodoro_custom_durations WHERE user_id=?", [(int)$current_user_id]) as $cpRow) {
    if ($cpRow['type'] === 'pomodoro' && !in_array((int)$cpRow['minutes'], $presetsPomodoro)) $presetsPomodoro[] = (int)$cpRow['minutes'];
    if ($cpRow['type'] === 'pausa' && !in_array((int)$cpRow['minutes'], $presetsPausa)) $presetsPausa[] = (int)$cpRow['minutes'];
}
sort($presetsPomodoro); sort($presetsPausa);

$statoCorrente = pomo_leggi_stato((int)$current_user_id);

$preselectProjectId = sec_int($_GET['project_id'] ?? 0, 0);
$preselectInfo = null;
if ($preselectProjectId > 0) {
    $preselectInfo = sec_one("SELECT id, name, color FROM projects WHERE id=? AND user_id=?",
                             [$preselectProjectId, (int)$current_user_id]);
}

/* richiesta 17: mappa id -> catena antenati (max 3: nonno, padre, progetto)
   per mostrare "nonno • padre • progetto" nel tag del progetto collegato */
$pomoProjMap = [];
foreach (sec_all("SELECT id, name, parent_id FROM projects WHERE user_id = ?", [(int)$current_user_id]) as $pmRow) {
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

/* dati js: stato iniziale.
   La parte che riguarda il pomodoro (sessione, pausa, ultima chiusa, avviso,
   impronta) arriva dalla stessa funzione che risponde a ?ajax=stato: la
   pagina appena aperta e il controllo periodico leggono la stessa cosa. */
$jsState = $statoCorrente + [
    /* 'settings' non si ripete qui: arriva gia' dentro $statoCorrente,
       che e' la stessa pomo_leggi_stato() usata dalla barra sulle altre
       pagine — un solo posto che legge le durate salvate, non due che
       rischiano di scostarsi. */
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
<script src="../_assets/js/nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/nisama_fetch.js') ?: time(); ?>"></script>
<!-- Il motore del pomodoro: stato, matematica del tempo e chiamate al server.
     Lo stesso file che usera' la barra sulle altre pagine, cosi' le due viste
     non possono divergere. -->
<script src="pomodoro_core.js?v=<?php echo @filemtime(__DIR__ . '/pomodoro_core.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>projects/pomodoro.css?v=<?php echo @filemtime(__DIR__ . '/pomodoro.css') ?: 1; ?>">
<?php
/* Le stringhe che servono al JavaScript di questa pagina. Solo i due gruppi
   che usa davvero: il dizionario intero sono migliaia di voci. */
/* Le stringhe che servono al JavaScript di questa pagina. Il gruppo 'todo'
   c'e' perche' il post-it monta todo_widget, che chiama T('todo.…'): senza,
   il foglietto mostrava <<todo.niente_da_fare>> al posto del testo. Non si
   passa tutto il dizionario perche' sono migliaia di voci. */
echo nisama_i18n_json(['comune', 'pomodoro', 'todo']);
?>
</head>
<body>

<div class="app-header">
    <div class="hdr-title">Pomodoro</div>
    <button type="button" class="hdr-history-btn" onclick="window.openPomodoroStorico({scope:'all'})">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="13" r="8"/></svg>
        <?php te('pomodoro.storico'); ?>
    </button>
</div>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<button type="button" class="display-switch" id="displaySwitchBtn" onclick="toggleDisplayStyle()" aria-label="<?php te('pomodoro.cambia_vista'); ?>">
    <svg id="displaySwitchIcon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M6 2v3.3c0 .6.24 1.2.66 1.6L12 12l5.34-5.1c.42-.4.66-1 .66-1.6V2"/><path d="M18 22v-3.3c0-.6-.24-1.2-.66-1.6L12 12l-5.34 5.1c-.42.4-.66 1-.66 1.6V22"/></svg>
</button>

<div class="page">
    <div class="mode-switch" id="modeSwitch">
        <button type="button" data-mode="0" onclick="setMode(0)"><?php te('pomodoro.timer'); ?></button>
        <button type="button" data-mode="1" onclick="setMode(1)"><?php te('pomodoro.cronometro'); ?></button>
    </div>

    <div class="pomo-project-row">
        <?php
            $ps_id = 'pomo'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
            $ps_placeholder = t('pomodoro.nessun_progetto_collegato'); $ps_title = t('pomodoro.collega_progetto');
            $ps_show_all_option = true; $ps_all_option_label = t('pomodoro.nessun_progetto'); $ps_allow_create = true;
            $ps_hidden_name = 'pomo_project_id';
            $ps_trigger_class = 'ps-trigger-pill';
            include __DIR__ . '/project_selector.php';
        ?>
    </div>

    <div class="pomo-status-label" id="statusLabel"><?php te('pomodoro.pronto'); ?></div>

    <div class="ring-wrap" id="ringWrap">
        <div class="ring-halo"></div>
        <div class="ring-face"></div>
        <!-- Il pallino sta in un GRUPPO ruotato attorno al centro (110,110): il
             JS scrive un angolo solo e non deve fare seno e coseno per posarlo.
             Le scintille invece stanno FUORI da quel gruppo, con coordinate
             assolute: dentro ruoterebbero insieme al pallino e gli
             resterebbero appiccicate, mentre devono essere lasciate indietro.
             Il gruppo delle scintille lo riempie il JS all'avvio. -->
        <svg viewBox="0 0 220 220"><circle class="ring-track" cx="110" cy="110" r="100"/><circle class="ring-progress" id="ringProgress" cx="110" cy="110" r="100" stroke-dasharray="628.3" stroke-dashoffset="628.3"/><g class="ring-sparks" id="ringSparks"></g><g class="ring-spark is-off" id="ringSpark" transform="rotate(0 110 110)"><circle class="ring-spark-halo" cx="210" cy="110" r="11"/><circle class="ring-spark-core" cx="210" cy="110" r="6.4"/></g></svg>
        <div class="ring-center" onclick="onDisplayClick()">
            <?php /* Scritto dal server e non lasciato a "25:00" fisso: se per
                     qualsiasi motivo l'idratazione non arriva in fondo, il
                     quadrante mostra comunque la durata vera invece di una
                     scritta di comodo che sembra un dato. */ ?>
            <div class="ring-time" id="ringTime"><?php echo ((int)$imp['last_mode'] === 1) ? '00:00' : ((int)$imp['last_pomodoro_duration_min'] . ':00'); ?></div>
            <div class="ring-sub" id="ringSub"></div>
        </div>
    </div>

    <div class="glass-wrap is-hidden" id="glassWrap" onclick="onDisplayClick()">
        <?php include __DIR__ . '/clessidra.php'; ?>
    </div>

    <?php /* La scena della pausa. Compare da sola quando il body prende la
             classe pausa-mode: non c'e' niente da accendere dal JavaScript,
             quindi non c'e' niente che possa restare acceso per sbaglio. */ ?>
    <?php include __DIR__ . '/pausa_scena.php'; ?>

    <div class="overtime-chip" id="overtimeChip" style="display:none;" onclick="onExtendClick()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span id="overtimeValue">00:00:00</span>
    </div>

    <!-- I tasti sono icone e non parole. "Stop", "Interrompi" e "Pausa" letti
         di fianco non si distinguevano: tre parole diverse per tre cose che a
         colpo d'occhio sembravano la stessa. Il vocabolario dei simboli e'
         quello di qualunque lettore musicale, che nessuno deve imparare:
           triangolo = parti / riprendi
           due barre  = metti in pausa questa sessione (si riprende dov'era)
           quadrato   = chiudi la sessione (finisce qui)
           spunta     = archivia il pomodoro completato
           tazza      = vai in pausa
         La parola resta nell'aria-label e nel title, per chi usa il lettore di
         schermo e per chi si ferma un attimo sopra al tasto. -->
    <div class="pomo-actions" id="actionsIdle">
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-start pomo-btn-xl" id="startBtn" onclick="onStartClick()"
                aria-label="<?php te('pomodoro.start'); ?>" title="<?php te('pomodoro.start'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>
        </button>
    </div>
    <div class="pomo-actions" id="actionsRunning" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-pausa" id="midPauseBtn" onclick="onMidPauseClick()"
                aria-label="<?php te('pomodoro.stop'); ?>" title="<?php te('pomodoro.stop'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6.5" y="5" width="4" height="14" rx="1.6"/><rect x="13.5" y="5" width="4" height="14" rx="1.6"/></svg>
        </button>
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-interrompi" onclick="onInterrompiClick()"
                aria-label="<?php te('pomodoro.interrompi'); ?>" title="<?php te('pomodoro.interrompi'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>
        </button>
    </div>
    <div class="pomo-actions" id="actionsCompleted" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-interrompi" onclick="onChiudiCompletato()"
                aria-label="<?php te('comune.chiudi'); ?>" title="<?php te('comune.chiudi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        </button>
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-teal" onclick="onAvviaPausaClick()"
                aria-label="<?php te('pomodoro.pausa'); ?>" title="<?php te('pomodoro.pausa'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 10.5h2.2a2.4 2.4 0 0 1 0 4.8H16"/><path d="M8 2.5v2.6M12 2.5v2.6"/></svg>
        </button>
    </div>
    <div class="pomo-actions" id="actionsPausaRunning" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-interrompi" onclick="onFinePausaClick('stop')"
                aria-label="<?php te('pomodoro.interrompi'); ?>" title="<?php te('pomodoro.interrompi'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>
        </button>
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-pomodoro" onclick="onFinePausaClick('nuovo_pomodoro')"
                aria-label="<?php te('pomodoro.nuovo_pomodoro'); ?>" title="<?php te('pomodoro.nuovo_pomodoro'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>
        </button>
    </div>
    <div class="pomo-actions" id="actionsPausaDone" style="display:none;">
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-interrompi" onclick="onFinePausaClick('stop')"
                aria-label="<?php te('pomodoro.interrompi'); ?>" title="<?php te('pomodoro.interrompi'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>
        </button>
        <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-pomodoro" onclick="onFinePausaClick('nuovo_pomodoro')"
                aria-label="<?php te('pomodoro.nuovo_pomodoro'); ?>" title="<?php te('pomodoro.nuovo_pomodoro'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>
        </button>
    </div>
    <div class="pomo-actions" id="actionsPausedTask" style="display:none; flex-direction:column; align-items:center;">
        <span class="paused-badge"><span class="dot"></span><?php te('pomodoro.sessione_in_pausa'); ?></span>
        <div style="display:flex; gap:12px;">
            <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-resume" onclick="onResumeTaskClick()"
                    aria-label="<?php te('pomodoro.riprendi'); ?>" title="<?php te('pomodoro.riprendi'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>
            </button>
            <button type="button" class="pomo-btn pomo-btn-icon pomo-btn-interrompi" onclick="onInterrompiClick()"
                    aria-label="<?php te('pomodoro.interrompi'); ?>" title="<?php te('pomodoro.interrompi'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>
            </button>
        </div>
    </div>
</div>

<!-- ================= POPUP DURATA (pomodoro + pausa) ================= -->
<div class="dur-overlay" id="durOverlay" onclick="if(event.target===this) closeDurPopup();">
    <div class="dur-sheet">
        <div class="dur-grabber"></div>
        <div class="dur-head"><b><?php te('pomodoro.durata'); ?></b>
            <button type="button" class="sheet-close" onclick="closeDurPopup()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="dur-half" id="durPomoHalf">
            <div class="dur-half-title dur-pomo-title"><span class="dot"></span>Pomodoro</div>
            <div class="dur-pills" id="durPomoPills"></div>
        </div>
        <div class="dur-divider"></div>
        <div class="dur-half" id="durPausaHalf">
            <div class="dur-half-title dur-pausa-title"><span class="dot"></span><?php te('pomodoro.pausa'); ?></div>
            <div class="dur-pills" id="durPausaPills"></div>
        </div>
        <button type="button" class="dur-confirm" onclick="confirmDurPopup()"><?php te('comune.conferma'); ?></button>
    </div>
</div>

<!-- ================= POPUP GESTIONE DURATE ================= -->
<div class="dur-overlay" id="customDurOverlay" style="z-index:9710;" onclick="if(event.target===this) closeCustomDur();">
    <div class="dur-sheet">
        <div class="dur-grabber"></div>
        <div class="dur-head"><b><?php te('pomodoro.gestisci'); ?> <span id="cdurTipoTit"></span></b>
            <button type="button" class="sheet-close" onclick="closeCustomDur()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="cdur-body">
            <div class="dur-half-title"><?php te('pomodoro.aggiungi_durata'); ?></div>
            <div class="cdur-add-row">
                <input type="time" class="cdur-time-input" id="cdurTime" onfocus="if(!this.value) this.value='00:00';" value="00:00">
                <button type="button" class="cdur-add-btn" onclick="saveCustomDur()"><?php te('comune.aggiungi'); ?></button>
            </div>

            <div class="dur-half-title" style="margin-top:24px;"><?php te('pomodoro.create_da_te'); ?></div>
            <div class="cdur-list" id="cdurList">
                <!-- Qui verranno caricate le durate -->
            </div>
        </div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <div class="confirm-title" id="confirmTitle"><?php te('pomodoro.salvare_sessione'); ?></div>
        <div class="confirm-msg" id="confirmMsg"></div>
        <div class="confirm-actions">
            <button type="button" class="confirm-btn" onclick="closeConfirm(false)"><?php te('comune.no'); ?></button>
            <button type="button" class="confirm-btn confirm-btn-primary" onclick="closeConfirm(true)"><?php te('pomodoro.si_salva'); ?></button>
        </div>
    </div>
</div>

<!-- Popup di scelta: stesso riquadro del "salvare la sessione?" ma con un
     numero variabile di vie d'uscita, costruite dal JS. Serve a fine tempo
     (chiudi / pausa / continua) e all'interruzione anticipata (pausa /
     chiudi). Non ha una X: chiudere senza scegliere lascerebbe il pomodoro
     in uno stato che l'utente non ha deciso. -->
<div class="confirm-overlay" id="choiceOverlay">
    <div class="confirm-box">
        <div class="confirm-title" id="choiceTitle"></div>
        <div class="confirm-msg" id="choiceMsg"></div>
        <div class="choice-actions" id="choiceActions"></div>
    </div>
</div>

<!-- Avviso del pomodoro chiuso d'ufficio. Sta in basso e non blocca niente:
     la pagina e' gia' pronta per un pomodoro nuovo, questo racconta soltanto
     cos'e' successo all'ultimo. Toccandolo si va a correggerlo. -->
<div class="pomo-avviso" id="pomoAvviso" style="display:none;">
    <button type="button" class="pomo-avviso-body" id="pomoAvvisoBody">
        <span class="pomo-avviso-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 8v5"/><path d="M12 16.5v.01"/><circle cx="12" cy="12" r="9"/></svg>
        </span>
        <span class="pomo-avviso-testo" id="pomoAvvisoTesto"></span>
    </button>
    <button type="button" class="pomo-avviso-x" id="pomoAvvisoX" aria-label="<?php te('comune.chiudi'); ?>">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
</div>

<!-- Attività: un post-it con il timer in piccolo e l'elenco di cosa fare
     durante questo pomodoro. Si apre dal tastino qui sotto. -->
<button type="button" class="pomo-todo-btn" id="pomoTodoBtn" aria-label="<?php te('pomodoro.attivita'); ?>">
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
            <button type="button" class="pomo-postit-close" id="pomoTodoClose"><?php te('comune.chiudi'); ?></button>
        </div>
        <div id="pomoTodoList"></div>
    </div>
</div>

<div class="pomo-toast" id="pomoToast"></div>

<?php
    $ph_id = 'pomo';
    include __DIR__ . '/pomodoro_storico.php';
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
/* Lo stesso tetto che applica il server quando trova una sessione abbandonata.
   Arriva da PHP e non e' scritto a mano due volte: se un giorno diventano 8
   ore, si cambia la costante e i due lati restano d'accordo. */
var POMO_LIMITE_SEC = <?php echo (int)POMO_LIMITE_SECONDI; ?>;

/* applica:false perche' questa pagina ha gia' il suo bootstrap: il motore
   riceve i valori da sincronizzaMotore() invece di ricostruirseli, cosi' la
   strada che porta allo stato resta una sola. */
PomoCore.init({ stato: STATE0, endpoint: 'pomodoro.php', limite: POMO_LIMITE_SEC, applica: false });

/* L'unico punto di travaso fra le variabili della pagina e il motore. Sta in
   una funzione sola e viene chiamata a ogni cambio di fase (updateLockUI) e a
   ogni battito: venti chiamate sparse sarebbero venti occasioni di
   dimenticarne una, e il motore si troverebbe a fare i conti su dati vecchi. */
function sincronizzaMotore(){
    PomoCore.imposta({
        fase: phase,
        // a riposo comanda la modalita' scelta nell'interruttore, non quella
        // dell'ultima sessione: e' quella che decide cosa mostrare sul quadrante
        modo: (phase === 'idle') ? mode : activeMode,
        sessioneId: activeSessionId,
        durataMin: activeDurataMin,
        accumulati: accumulatedSec,
        inizioSegmentoMs: segmentStartMs,
        fineCompletatoMs: completedEndMs,
        pausaInizioMs: pausaStartMs,
        pausaDurataMin: pausaDurataMin,
        durataSceltaMin: selDurataPomo,
        durataPausaMin: selDurataPausa,
        progettoId: projectId, progettoNome: projectName, progettoColore: projectColor,
        stile: displayStyle
    });
}

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }
function toast(msg){
    var t = document.getElementById('pomoToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._tm); t._tm = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}
/* Formato e chiamate: gusci sul motore. I nomi restano questi perche' la
   pagina li usa in cinquanta punti, ma il codice vero sta in un file solo. */
function pad(n){ return PomoCore.pad(n); }
function fmtHMS(totalSec){ return PomoCore.fmtHMS(totalSec); }
function post(url, params){
    /* 'pomodoro.php?ajax=pausa_task' -> azione('pausa_task'). Passando tutte
       da qui, il motore sa sempre cosa e' appena stato scritto e non scambia
       una nostra azione per quella di un altro dispositivo. */
    var nome = String(url).split('ajax=')[1] || '';
    return PomoCore.azione(nome, params);
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
    // ogni cambio di fase passa da qui: e' il posto giusto per allineare il motore
    sincronizzaMotore();
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
    var riga = document.querySelector('.pomo-project-row');
    if (!el) return;
    if (!projectId) {
        // nessun progetto: lascia il placeholder gestito dal selettore, puntino
        // compreso — a toglierlo ci pensa il CSS, ma solo con la classe attiva
        if (riga) riga.classList.remove('pomo-ha-progetto');
        return;
    }
    var path = (STATE0.project_paths && STATE0.project_paths[projectId]) ? STATE0.project_paths[projectId] : [projectName];
    if (!path || !path.length) path = [projectName];

    /* Due righe invece di una sola catena "nonno • padre • progetto": il nome
       che conta e' quello del progetto, e in fila con gli altri si perdeva.
       Sopra il titolo, sotto in piccolo da dove viene — e se non ha antenati
       la seconda riga non c'e' proprio, cosi' la pillola non resta con uno
       spazio vuoto sotto. */
    var foglia = path[path.length - 1];
    var antenati = path.slice(0, -1);
    var html = '<span class="pomo-pill-leaf">' + pomoEsc(foglia) + '</span>';
    if (antenati.length) {
        var dentro = '';
        for (var i = 0; i < antenati.length; i++) {
            if (i > 0) dentro += '<span class="pomo-pill-sep"> • </span>';
            dentro += pomoEsc(antenati[i]);
        }
        html += '<span class="pomo-pill-anc">' + dentro + '</span>';
    }
    el.innerHTML = html;
    if (riga) riga.classList.add('pomo-ha-progetto');
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
        container.innerHTML = '<div class="cdur-empty">' + T('pomodoro.nessuna_durata') + '</div>';
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
                        '<button type="button" class="cdur-row-del" onclick="deleteCustomDur('+m+')" aria-label="' + T('comune.elimina') + '">' +
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
    document.getElementById('ringSub').textContent = T('pomodoro.pausa_di', {minuti: selDurataPausa});
    
    sincronizzaMotore();
    document.getElementById('ringTime').textContent = PomoCore.tempi().testo;
    setRingFraction(0);
    lastGlassFlipped = false;
    if (window.Clessidra) window.Clessidra.idle();
}
function setRingFraction(frac){
    frac = Math.max(0, Math.min(1, frac));
    document.getElementById('ringProgress').style.strokeDashoffset = (RING_CIRC * (1 - frac));

    /* A zero la scintilla si nasconde: un pallino fermo in cima senza barra
       dietro sembrerebbe un difetto di disegno. La visibilita' la decide solo
       questa funzione — setSparkFraction sposta e basta, cosi' in fuoritempo
       il giro nuovo puo' ripartire da zero senza far sparire il pallino.
       A zero il pallino ci va di netto e non insegue: e' un azzeramento, non
       un movimento, e vederlo strisciare all'indietro sarebbe sbagliato. */
    scintillaVisibile(frac > 0);
    setSparkFraction(frac, frac <= 0);
}

/* ==========================================================================
   LA SCINTILLA IN PUNTA ALLA BARRA
   Perche' un motore a fotogrammi e non una animazione CSS come prima:
   1. il pallino andava a scatti. Il tick della pagina gira 4 volte al secondo
      e a ogni giro riscriveva l'angolo: quattro posizioni al secondo si
      vedono tutte. La barra non aveva il problema perche' il suo movimento e'
      una transizione CSS, che riempie i buchi da sola. Qui il pallino insegue
      il bersaglio a ogni fotogramma, quindi ~60 posizioni al secondo.
   2. le scintille CSS si vedevano solo all'inizio, e devono partire anche in
      avanti per poi essere trascinate indietro — una ruota che frena butta
      scintille davanti a se'. Con i keyframes ogni pallino ha una traiettoria
      sola, sempre uguale; qui ognuna nasce con una sua spinta e una sua vita.
   Le scintille stanno FUORI dal gruppo che ruota, con coordinate assolute:
   dentro, ruoterebbero insieme al pallino e resterebbero appiccicate a lui
   invece di essere lasciate indietro.
   ========================================================================== */
var SP_CX = 110, SP_CY = 110, SP_R = 100;   // centro e raggio, in unita' del viewBox
var spBersaglio = 0;        // dove deve stare il pallino (lo scrive il tick)
var spAdesso = 0;           // dove sta davvero (si avvicina a ogni fotogramma)
var spVisibile = false;
var spRaf = null, spUltimoMs = 0, spDaEmettere = 0;
var spPezzi = [];           // riserva fissa di cerchietti: nessuno viene creato o distrutto durante il movimento

/* Chi ha chiesto meno animazioni non deve vedere venticinque cerchietti
   schizzare: il pallino continua a muoversi, le scintille no. */
var spNienteMoto = false;
try { spNienteMoto = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

/* Le scintille sono attrito, quindi hanno senso solo se qualcosa si muove
   davvero. Con la sessione congelata il pallino resta fermo dov'era, e vederlo
   comunque sfrigolare direbbe il contrario di quello che sta succedendo. */
function faseInMovimento(){
    return phase === 'running' || phase === 'completed' || phase === 'pausa_running';
}

(function preparaScintille(){
    var g = document.getElementById('ringSparks');
    if (!g) return;
    for (var i = 0; i < 26; i++) {
        var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        c.setAttribute('class', 'rs-p');
        c.setAttribute('r', '0');
        c.style.opacity = '0';
        g.appendChild(c);
        spPezzi.push({ el: c, vivo: false, x: 0, y: 0, vt: 0, vr: 0, eta: 0, vita: 1, r: 1 });
    }
})();

function scintillaVisibile(mostra){
    spVisibile = !!mostra;
    var spark = document.getElementById('ringSpark');
    if (spark) spark.classList.toggle('is-off', !spVisibile);
    if (spVisibile) avviaScintille();
}

function setSparkFraction(frac, subito){
    spBersaglio = Math.max(0, Math.min(1, frac));
    if (subito) { spAdesso = spBersaglio; disegnaPallino(); }
    avviaScintille();
}

function disegnaPallino(){
    var spark = document.getElementById('ringSpark');
    if (!spark) return;
    /* L'attributo transform e non lo style: sullo style la transizione CSS
       farebbe interpolare l'angolo anche nel salto da 360 a 0 — a fine giro il
       pallino tornerebbe indietro girando invece di ricominciare da capo. */
    spark.setAttribute('transform', 'rotate(' + (spAdesso * 360).toFixed(2) + ' 110 110)');
}

function avviaScintille(){
    if (spRaf !== null) return;
    spUltimoMs = 0;
    spRaf = requestAnimationFrame(passoScintille);
}

function passoScintille(ts){
    spRaf = null;
    // il primo fotogramma non ha un "prima": si parte da un sedicesimo di secondo.
    // il tetto a 50 ms evita che, tornando su una scheda lasciata in secondo
    // piano, un dt enorme spari tutte le scintille fuori dallo schermo
    var dt = spUltimoMs ? Math.min(0.05, (ts - spUltimoMs) / 1000) : 0.016;
    spUltimoMs = ts;

    /* --- il pallino insegue il bersaglio --- */
    var d = spBersaglio - spAdesso;
    if (d < -0.5) d += 1;          // il bersaglio ha girato l'angolo: si va avanti, non indietro
    spAdesso += d * Math.min(1, dt * 6);
    if (spAdesso >= 1) spAdesso -= 1;
    disegnaPallino();

    var ang = spAdesso * Math.PI * 2;
    var cosA = Math.cos(ang), sinA = Math.sin(ang);
    var px = SP_CX + SP_R * cosA, py = SP_CY + SP_R * sinA;
    // versore tangente (verso di marcia) e versore radiale (verso l'esterno)
    var tx = -sinA, ty = cosA;

    /* --- nascono nuove scintille --- */
    if (spVisibile && !spNienteMoto && faseInMovimento()) {
        spDaEmettere += dt * 46;                 // scintille al secondo
        while (spDaEmettere >= 1) {
            spDaEmettere -= 1;
            accendiScintilla(px, py);
        }
    }

    /* --- ognuna vive la sua vita --- */
    var vive = 0;
    for (var i = 0; i < spPezzi.length; i++) {
        var p = spPezzi[i];
        if (!p.vivo) continue;
        p.eta += dt;
        if (p.eta >= p.vita) {
            p.vivo = false;
            p.el.style.opacity = '0';
            continue;
        }
        vive++;

        /* Il freno. La spinta all'indietro e' COSTANTE e vale per tutte: quelle
           nate in avanti rallentano, si fermano e tornano indietro, che e'
           l'arco delle scintille di una ruota che frena. L'attrito invece
           smorza solo lo scarto di lato, cosi' il ventaglio si chiude piano.
           I numeri sono tarati: piu' freno e le scintille davanti non fanno in
           tempo a vedersi, meno e la coda dietro fa il giro dell'anello e
           diventa una seconda barra di progresso. */
        p.vt -= 95 * dt;
        p.vr *= (1 - 3.2 * dt);
        p.x += (p.vt * tx + p.vr * cosA) * dt;
        p.y += (p.vt * ty + p.vr * sinA) * dt;

        var q = p.eta / p.vita;                  // 0 appena nata, 1 spenta
        p.el.setAttribute('cx', p.x.toFixed(2));
        p.el.setAttribute('cy', p.y.toFixed(2));
        p.el.setAttribute('r', (p.r * (1 - q * 0.85)).toFixed(2));
        // si spegne in fretta sul finale invece che in modo piatto
        p.el.style.opacity = (1 - q * q).toFixed(3);
    }

    // si continua finche' c'e' qualcosa da muovere: a pagina ferma il ciclo
    // si spegne da solo e non consuma batteria
    if (spVisibile || vive > 0) spRaf = requestAnimationFrame(passoScintille);
}

function accendiScintilla(px, py){
    var p = null;
    for (var i = 0; i < spPezzi.length; i++) { if (!spPezzi[i].vivo) { p = spPezzi[i]; break; } }
    if (!p) return;                              // riserva esaurita: si salta questa

    p.vivo = true; p.eta = 0;
    p.x = px; p.y = py;
    /* Quasi due su tre nascono in AVANTI e si aprono di lato: è lì che sta il
       grosso dell'effetto, come le scintille che schizzano davanti a una ruota
       che frena. Il freno le riporta indietro mentre sono ancora accese — per
       questo hanno una vita piu' lunga delle altre, altrimenti si
       spegnerebbero prima di aver invertito la marcia e l'arco non si
       vedrebbe. Quelle nate all'indietro sono poche e con poca spinta: la coda
       dietro deve restare un accenno, non una seconda barra di progresso. */
    if (Math.random() < 0.62) {
        p.vt = 14 + Math.random() * 30;
        p.vr = (Math.random() - 0.5) * 64;       // ventaglio largo
        p.vita = 0.50 + Math.random() * 0.30;
    } else {
        p.vt = -(6 + Math.random() * 20);
        p.vr = (Math.random() - 0.5) * 26;
        p.vita = 0.26 + Math.random() * 0.26;
    }
    p.r = 0.85 + Math.random() * 0.95;
}

/* Con la scheda in secondo piano requestAnimationFrame si ferma da solo, ma
   spUltimoMs resta vecchio: azzerandolo si evita il salto al ritorno. */
document.addEventListener('visibilitychange', function(){
    if (document.hidden) { spUltimoMs = 0; }
    else if (spVisibile) avviaScintille();
});
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
        if (!data.success) {
            // il conflitto lo racconta gia' l'ascoltatore piu' in basso, che nel
            // frattempo ha anche riallineato la pagina allo stato vero
            if (!data.conflitto) toast(T('comune.errore_generico'));
            return;
        }
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
        document.getElementById('statusLabel').textContent = mode === 0 ? T('pomodoro.concentrazione') : T('pomodoro.cronometro_in_corso');
        document.getElementById('ringTime').style.color = ''; 
        document.getElementById('ringSub').textContent = ''; 
        updateLockUI();
        if (window.Clessidra) window.Clessidra.playStartFlip();
        setTimeout(updateGrainFlow, 850); // avvia la pioggia di granelli solo a capovolgimento concluso
        startTicking();
    }).catch(function(){ toast(T('comune.errore_rete')); });
}

/* la pausa CONGELA la sessione (stessa riga sul server, si accumula durata_secondi) e mostra
   subito il tempo mancante fermo, con sotto solo "Riprendi"/"Interrompi": vedi bug #6 */
window.onMidPauseClick = function(){
    sincronizzaMotore();
    var elapsedSec = PomoCore.tempoDaCongelare();
    
    post('pomodoro.php?ajax=pausa_task', { 
        session_id: activeSessionId,
        tempo_esatto: elapsedSec // Inviamo il tempo già normalizzato
    }).then(function(data){
        stopTicking(); stopGrainFlow();
        accumulatedSec = data.durata_secondi || 0;
        phase = 'paused_task';
        switchActionsPanel('actionsPausedTask');
        document.getElementById('statusLabel').textContent = T('pomodoro.sessione_in_pausa');
        document.getElementById('overtimeChip').style.display = 'none';
        renderFrozenPausedDisplay();
        updateLockUI();
    });
};
function renderFrozenPausedDisplay(){
    // congela il quadrante sul tempo mancante (timer) o trascorso (cronometro): non
    // viene più aggiornato finché non si preme "Riprendi"
    sincronizzaMotore();
    var t = PomoCore.tempi();
    document.getElementById('ringTime').textContent = t.testo;
    setRingFraction(t.frazione);
    // col timer la clessidra resta come l'ha lasciata la sessione: rigirarla
    // qui vorrebbe dire animare un capovolgimento che non e' successo
    setGlassFraction(t.frazione, false, activeMode === 0 ? lastGlassFlipped : t.capovolta);
}
// DOPO:
    window.onResumeTaskClick = function(){
        post('pomodoro.php?ajax=riprendi_task', { session_id: activeSessionId }).then(function(data){
            segmentStartMs = Date.now();
            phase = 'running';
            switchActionsPanel('actionsRunning');
            document.getElementById('statusLabel').textContent = activeMode === 0 ? T('pomodoro.concentrazione') : T('pomodoro.cronometro_in_corso');
            updateLockUI(); updateGrainFlow();
            startTicking();
        });
    };

window.onInterrompiClick = function(){
    sincronizzaMotore();
    var elapsedSec = PomoCore.tempi().trascorso;
    // sotto il minuto non c'e' niente da salvare e niente da chiedere: si butta
    if (elapsedSec < 60) { doInterrompi(true); return; }

    /* Interrompere non e' una cosa sola: c'e' chi si ferma per fare la pausa e
       chi ha finito. Prima l'unica uscita era chiudere, e per fare la pausa
       bisognava aspettare che il tempo finisse da solo.

       La terza via, scartare, e' arrivata dopo e non e' un doppione di
       "chiudi": chiudere salva quello che si e' fatto, scartare cancella la
       riga dal database — quel tempo smette di esistere nei totali del
       progetto e nello storico. Prima l'unico modo di buttare via una sessione
       era che durasse meno di cinque minuti e rispondere "no" alla domanda che
       veniva da sola; per tutto il resto bisognava andare nello storico a
       cancellarla a mano. */
    showChoice(T('pomodoro.scelta_stop_titolo'), T('pomodoro.scelta_stop_testo'), [
        { chiave: 'pausa',  testo: T('pomodoro.scelta_pausa'),  icona: IC_PAUSA, primaria: true },
        { chiave: 'chiudi', testo: T('pomodoro.scelta_chiudi'), icona: IC_STOP },
        { chiave: 'scarta', testo: Tf('pomodoro.scelta_scarta', 'Scarta la sessione'), icona: IC_CESTINO }
    ], function(scelta){
        if (!scelta) return;   // popup chiuso senza decidere: il pomodoro resta com'e'

        /* Scartare non si puo' disfare, quindi si chiede una volta sola ma si
           chiede. Non si riusa showConfirm: i suoi due tasti sono scritti
           nell'HTML e dicono "No" e "Si', salva" — l'esatto contrario di
           quello che si sta per fare. */
        if (scelta === 'scarta') {
            showChoice(
                Tf('pomodoro.scarta_titolo', 'Scartare questa sessione?'),
                Tf('pomodoro.scarta_testo', 'Sparisce dallo storico e dai totali del progetto. Non si torna indietro.'),
                [
                    { chiave: 'annulla', testo: Tf('comune.annulla', 'Annulla'), icona: IC_INDIETRO, primaria: true },
                    { chiave: 'si',      testo: Tf('pomodoro.scarta_si', 'Sì, scarta'), icona: IC_CESTINO }
                ],
                function(conferma){ if (conferma === 'si') doInterrompi(true); }
            );
            return;
        }

        // la pausa parte DOPO che la sessione e' stata chiusa sul server, non prima
        var poi = (scelta === 'pausa') ? function(){ window.onAvviaPausaClick(); } : null;

        // la domanda "e' durata pochissimo, la salvo?" resta, ma viene dopo:
        // riguarda cosa farne, non se fermarsi
        if (elapsedSec < 300) {
            showConfirm(T('pomodoro.molto_breve'), T('pomodoro.molto_breve_testo'), function(keep){ doInterrompi(!keep, poi); });
        } else {
            doInterrompi(false, poi);
        }
    });
};
function doInterrompi(elimina, poi){
    post('pomodoro.php?ajax=interrompi', { session_id: activeSessionId, elimina: elimina ? 1 : 0 }).then(function(){
        stopTicking(); stopGrainFlow();
        phase = 'idle';
        accumulatedSec = 0; segmentStartMs = null;
        switchActionsPanel('actionsIdle');
        document.getElementById('overtimeChip').style.display = 'none';
        document.getElementById('statusLabel').textContent = T('pomodoro.pronto');
        renderIdleDisplay();
        updateLockUI();
        toast(elimina ? T('pomodoro.sessione_scartata') : T('pomodoro.sessione_salvata'));
        if (poi) poi();
    });
}

function onNaturalComplete(){
    if (notifiedEnd) return;
    notifiedEnd = true;
    buzz([80,40,80]);
    notify(T('pomodoro.concluso'), projectId ? T('pomodoro.hai_completato', {progetto: projectName}) : T('pomodoro.ottimo_lavoro'));
    stopGrainFlow();
    if (window.Clessidra) window.Clessidra.finish();
    post('pomodoro.php?ajax=segna_completato', { session_id: activeSessionId }).then(function(data){
        completedEndMs = PomoCore.msDa(data.data_fine) || Date.now();
        phase = 'completed';
        accumulatedSec = (activeDurataMin || 0) * 60; // Salva il totale dei secondi
        switchActionsPanel('actionsCompleted');
        document.getElementById('statusLabel').textContent = T('pomodoro.completato');
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

        /* Prima il tempo veniva aggiunto e il conteggio ripartiva da zero senza
           chiedere niente: l'unico modo di fermarsi era accorgersene e premere
           interrompi. Ora la decisione e' esplicita. "Continua" non fa nulla di
           speciale ed e' voluto: e' esattamente il comportamento di prima, cioe'
           il tag riparte e si puo' aggiungere altro tempo ancora. */
        showChoice(T('pomodoro.scelta_tempo_titolo'), T('pomodoro.scelta_tempo_testo'), [
            { chiave: 'continua', testo: T('pomodoro.scelta_continua'), icona: IC_PLAY, primaria: true },
            { chiave: 'pausa',    testo: T('pomodoro.scelta_pausa'),    icona: IC_PAUSA },
            { chiave: 'chiudi',   testo: T('pomodoro.scelta_chiudi'),   icona: IC_STOP }
        ], function(scelta){
            if (scelta === 'pausa')  { window.onAvviaPausaClick(); return; }
            if (scelta === 'chiudi') { window.onChiudiCompletato(); return; }
            // 'continua' (o popup chiuso senza scegliere): si va avanti come prima
        });
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
        document.getElementById('statusLabel').textContent = T('pomodoro.pausa_in_corso');
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
        document.getElementById('statusLabel').textContent = T('pomodoro.pronto');
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
            document.getElementById('statusLabel').textContent = T('pomodoro.pronto_altro');
            updateLockUI();
            
            // Fai partire immediatamente il nuovo Pomodoro senza mostrare il popup
            doStart();
        } else {
            phase = 'idle';
            switchActionsPanel('actionsIdle');
            document.getElementById('statusLabel').textContent = T('pomodoro.pronto');
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
    /* Nessun conto qui dentro: si chiede al motore che ore sono e si disegna.
       E' il motivo per cui la barra sulle altre pagine potra' mostrare lo
       stesso avanzamento senza rifare una riga di questa matematica. */
    sincronizzaMotore();
    var t = PomoCore.tempi();

    if (phase === 'running') {
        if (t.finito)        { onNaturalComplete(); return; }
        if (t.oltreIlLimite) { toast(T('pomodoro.limite_raggiunto')); doInterrompi(false); return; }
        document.getElementById('ringTime').textContent = t.testo;
        setRingFraction(t.frazione);
        setGlassFraction(t.frazione, false, t.capovolta);

    } else if (phase === 'completed') {
        document.getElementById('overtimeValue').textContent = fmtHMS(t.fuoritempo);
        setRingFraction(1);
        if (t.frazioneScintilla !== null) setSparkFraction(t.frazioneScintilla);

    } else if (phase === 'pausa_running') {
        // il testo verde: la pausa non deve poter essere scambiata per lavoro
        document.getElementById('ringTime').style.color = 'var(--pausa-accent)';
        if (t.finito && !notifiedPausaEnd) {
            notifiedPausaEnd = true; buzz([60,30,60]);
            notify(T('pomodoro.pausa_finita'), T('pomodoro.torna_al_lavoro'));
            phase = 'pausa_done';
            switchActionsPanel('actionsPausaDone');
            document.getElementById('statusLabel').textContent = T('pomodoro.pausa_conclusa');
            stopGrainFlow(); updateLockUI();
        }
        document.getElementById('ringTime').textContent = t.testo;
        setRingFraction(t.frazione);
        setGlassFraction(t.frazione, true, false);

    } else if (phase === 'pausa_done') {
        document.getElementById('ringTime').style.color = 'var(--pausa-accent)';
        document.getElementById('ringTime').textContent = t.testo;
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

/* ---------------- scelta a piu' vie ----------------
   showConfirm risponde si'/no; qui le uscite sono tre. Le voci arrivano come
   elenco di {chiave, testo, icona, primaria} e la richiamata riceve la chiave
   scelta: chi chiama decide cosa farne, questo pezzo non sa niente di
   pomodori. Le vie si disegnano ogni volta da capo perche' cambiano fra un
   caso e l'altro. */
var choiceCallback = null;
function showChoice(title, msg, voci, cb){
    document.getElementById('choiceTitle').textContent = title;
    document.getElementById('choiceMsg').textContent = msg || '';
    var box = document.getElementById('choiceActions');
    box.innerHTML = '';
    choiceCallback = cb;
    voci.forEach(function(v){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'choice-btn' + (v.primaria ? ' choice-btn-primary' : '');
        b.innerHTML = '<span class="choice-ic">' + v.icona + '</span><span>' + pomoEsc(v.testo) + '</span>';
        b.addEventListener('click', function(){ closeChoice(v.chiave); });
        box.appendChild(b);
    });
    document.getElementById('choiceOverlay').classList.add('show');
}
function closeChoice(scelta){
    document.getElementById('choiceOverlay').classList.remove('show');
    if (choiceCallback) { var cb = choiceCallback; choiceCallback = null; cb(scelta); }
}

/* Le stesse icone dei tasti grandi: se il triangolo sul tasto vuol dire
   "parti", nel popup deve voler dire la stessa cosa. */
var IC_PLAY  = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>';
var IC_STOP  = '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>';
var IC_PAUSA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 10.5h2.2a2.4 2.4 0 0 1 0 4.8H16"/><path d="M8 2.5v2.6M12 2.5v2.6"/></svg>';
/* Il cestino: nel popup di interruzione e' l'unica via che cancella davvero.
   Lo stop dice "fermati e salva", questo dice "come se non fosse successo" —
   due gesti diversi non possono avere lo stesso disegno. */
var IC_CESTINO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6.5h16"/><path d="M9.5 6.5V5a1.5 1.5 0 0 1 1.5-1.5h2A1.5 1.5 0 0 1 14.5 5v1.5"/><path d="M6.5 6.5l.9 12a2 2 0 0 0 2 1.9h5.2a2 2 0 0 0 2-1.9l.9-12"/><path d="M10.5 10.5v6M13.5 10.5v6"/></svg>';
var IC_INDIETRO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m14 6-6 6 6 6"/></svg>';

/* T() con una via d'uscita. Una chiave che il dizionario non ha ancora stampa
   «<<pomodoro.qualcosa>>» dentro l'interfaccia — gia' successo col post-it e
   le stringhe del gruppo todo. Finche' le voci nuove non sono state aggiunte
   al dizionario, qui esce la parola giusta in italiano; il giorno in cui ci
   sono, questo ripiego smette da solo di servire e non va tolto niente. */
function Tf(chiave, ripiego){
    var s = '';
    try { s = T(chiave); } catch (e) {}
    return (!s || /^<<.*>>$/.test(s)) ? ripiego : s;
}

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
            document.getElementById('statusLabel').textContent = T('pomodoro.sessione_in_pausa');
            renderFrozenPausedDisplay();
        } else {
            /* msDa() invece di new Date(...).replace(): la stessa lettura del
               motore, e soprattutto non esplode se la data manca. Qui una
               riga che esplode non si vede — l'idratazione si ferma a meta' e
               la pagina resta a sembrare a riposo. */
            segmentStartMs = PomoCore.msDa(a.data_ripresa) || PomoCore.msDa(a.data_inizio) || Date.now();
            phase = 'running';
            switchActionsPanel('actionsRunning');
            document.getElementById('statusLabel').textContent = mode === 0 ? T('pomodoro.concentrazione') : T('pomodoro.cronometro_in_corso');
            startTicking();
        }
        updateLockUI(); updateGrainFlow();
    } else if (STATE0.pausa) {
        var p = STATE0.pausa;
        /* Era p.start_time, ma il server ha sempre mandato data_inizio: con una
           pausa aperta l'idratazione moriva qui (undefined.replace) e il
           quadrante restava sul valore scritto nell'HTML. */
        pausaStartMs = PomoCore.msDa(p.data_inizio) || Date.now();
        pausaDurataMin = p.durata_prevista_minuti;
        if (p.project_id) { projectId = p.project_id; projectName = p.progetto_nome; projectColor = p.progetto_colore; }
        document.body.classList.add('pausa-mode');
        if ((Date.now() - pausaStartMs) / 1000 >= pausaDurataMin * 60) {
            /* gia' scaduta mentre la pagina era chiusa: si apre nella fase
               giusta, senza rifare suonare la notifica di fine pausa */
            phase = 'pausa_done'; notifiedPausaEnd = true;
            switchActionsPanel('actionsPausaDone');
            document.getElementById('statusLabel').textContent = T('pomodoro.pausa_conclusa');
        } else {
            phase = 'pausa_running';
            switchActionsPanel('actionsPausaRunning');
            document.getElementById('statusLabel').textContent = T('pomodoro.pausa_in_corso');
        }
        updateLockUI(); updateGrainFlow();
        startTicking();
    } else if (STATE0.last && STATE0.last.esito === 'completato') {
        var c = STATE0.last;
        activeSessionId = c.id; activeMode = c.mode; mode = c.mode;
        /* Serve al giro della scintilla in fuoritempo: senza, riaprendo la
           pagina su un pomodoro gia' completato il pallino resterebbe fermo. */
        activeDurataMin = c.durata_prevista_minuti;
        completedEndMs = PomoCore.msDa(c.data_fine) || Date.now();
        accumulatedSec = c.durata_secondi || (c.durata_prevista_minuti * 60) || 0; // Recupera il totale!
        if (c.project_id) { projectId = c.project_id; projectName = c.progetto_nome; projectColor = c.progetto_colore; if (window.psSetValue) window.psSetValue('ps-modal-pomo', projectId, projectName, projectColor); }
        phase = 'completed';
        switchActionsPanel('actionsCompleted');
        document.getElementById('overtimeChip').style.display = 'flex';
        document.getElementById('statusLabel').textContent = T('pomodoro.completato');
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

/* ---------------- lo stesso pomodoro su piu' dispositivi ----------------
   Il pomodoro si mette in pausa dal telefono e si continua a guardarlo dal
   computer: senza questo, la seconda pagina resta a raccontare una sessione
   che non esiste piu' e la prima azione che le si chiede finisce nel vuoto.
   Non si ricarica la pagina apposta — si perderebbe il post-it aperto e la
   posizione nello storico: si sostituisce lo stato di partenza e si rifa'
   l'idratazione, che e' la stessa strada del primo caricamento. */
PomoCore.on('cambiato', function(st){
    stopTicking(); stopGrainFlow();
    STATE0.active = st.active; STATE0.pausa = st.pausa; STATE0.last = st.last;
    phase = 'idle';
    activeSessionId = null; activeDurataMin = null; accumulatedSec = 0;
    segmentStartMs = null; completedEndMs = null;
    pausaStartMs = null; pausaDurataMin = null;
    notifiedEnd = false; notifiedPausaEnd = false;
    document.body.classList.remove('pausa-mode');
    document.getElementById('overtimeChip').style.display = 'none';
    document.getElementById('ringTime').style.color = '';
    document.getElementById('statusLabel').textContent = T('pomodoro.pronto');
    switchActionsPanel('actionsIdle');
    bootstrap();
    toast(T('pomodoro.altro_dispositivo'));
});

/* Il rifiuto arriva dal server, che e' l'unico a vedere tutti i dispositivi
   insieme: qui si dice soltanto cos'e' successo. */
PomoCore.on('conflitto', function(){ toast(T('pomodoro.gia_attivo_altrove')); });

PomoCore.avviaControllo(60000);

/* Prima si prova ad aprire direttamente la correzione; se lo storico non e'
   disponibile (o non c'e' niente da correggere) resta l'avviso in basso, che
   e' quello che c'era prima e continua a fare il suo mestiere. */
if (!apriModificaUltimoNonChiuso()) mostraAvvisoAbbandono();
proponiArgomentoDiOggi();
pomoUpdateProjectPill(); // richiesta 17: mostra la catena nonno•padre•progetto dopo l'idratazione

/* ---------------- l'argomento che stai studiando oggi ----------------
   Se nella pagina «da studiare» oggi c'è qualcosa in programma, il progetto
   giusto si seleziona da solo. È il pezzo che tiene in piedi tutto il resto:
   la curva della fatica, il confronto fra stima e consuntivo, il semaforo
   dell'esame vivono sui pomodori attaccati all'argomento, e quel collegamento
   è esattamente quello che salta quando si ha fretta — si preme avvia e si
   comincia, e a fine giornata i dati non ci sono.
   Non si sceglie mai al posto dell'utente se c'è più di un candidato: si
   propone, e proporre due argomenti significa fargli fare un tocco, non
   indovinare per lui. */
function proponiArgomentoDiOggi(){
    // con un pomodoro già in corso il progetto è quello della sessione: non si tocca
    if (activeSessionId || projectId) return;

    fetch('../planner/study_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'st_ajax=oggi'
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok || !d.argomenti || !d.argomenti.length) return;
        var lista = d.argomenti.filter(function (a) { return a.materia_id; });
        if (!lista.length) return;

        if (lista.length === 1) { scegliArgomento(lista[0]); return; }

        /* Più di uno: si chiede. Le voci portano la materia sotto al nome,
           perché «Integrali tripli» da solo non dice di quale esame è quando
           se ne stanno preparando tre. */
        var voci = lista.slice(0, 4).map(function (a) {
            return {
                chiave: String(a.id),
                testo: a.name + (a.materia ? ' · ' + a.materia : ''),
                icona: IC_PLAY
            };
        });
        voci.push({ chiave: '', testo: T('comune.no'), icona: IC_STOP });
        showChoice(T('pomodoro.studi_oggi'), T('pomodoro.studi_oggi_testo'), voci, function (scelta) {
            if (!scelta) return;
            for (var i = 0; i < lista.length; i++) {
                if (String(lista[i].id) === scelta) { scegliArgomento(lista[i]); return; }
            }
        });
    }).catch(function () { /* il to study può non esserci: la pagina resta com'è */ });
}

function scegliArgomento(a){
    projectId = a.id;
    projectName = a.name;
    projectColor = a.color || '';
    if (window.psSetValue) window.psSetValue('ps-modal-pomo', projectId, projectName, projectColor);
    applyProjectTheme(projectColor);
    pomoUpdateProjectPill();
    /* Lo si dice, invece di farlo di nascosto: una scelta fatta al posto
       dell'utente che lui non vede è una scelta che non può correggere. */
    toast(T('pomodoro.scelto_da_studiare', { nome: a.name }));
}

/* ---------------- l'ultimo pomodoro che non ha chiuso l'utente ----------------
   STATE0.avviso c'e' solo per le sessioni che abbiamo chiuso noi d'ufficio
   (status 'abbandonato', is_manual=2): pagina lasciata aperta, telefono
   bloccato, scheda persa. Sono le uniche in cui gli orari salvati sono una
   NOSTRA stima e non un fatto — un pomodoro finito a tempo scaduto, o
   interrotto a mano, ha gia' i suoi numeri giusti e non c'e' niente da
   correggere.

   Prima la correzione era a due tocchi: l'avviso in basso, e poi toccarlo per
   arrivare allo storico gia' in modifica. Il primo tocco pero' non aggiungeva
   niente — diceva solo che c'era qualcosa da sistemare — e la finestra che si
   apre e' gia' compilata con la nostra stima, quindi si apre e basta.

   Il segno di lettura si scrive subito: la finestra di modifica E' l'avviso,
   e riproporla a ogni apertura della pagina finche' non la si usa vorrebbe
   dire mettersi di traverso a chi quella stima l'ha gia' vista e gli va bene.
   Chiuderla senza salvare non perde niente: la sessione resta esattamente com'e'
   adesso, con la durata stimata, che e' quello che succedeva anche prima.
   ---------------------------------------------------------------------------- */
function apriModificaUltimoNonChiuso(){
    var a = STATE0.avviso;
    if (!a) return false;
    // con qualcosa in corso non e' il momento: si sta guardando quello, non l'altro
    if (STATE0.active || STATE0.pausa) return false;
    if (!window.openPomodoroStorico) return false;

    post('pomodoro.php?ajax=chiudi_avviso', { session_id: a.id });
    STATE0.avviso = null;

    /* Un attimo dopo l'idratazione: la pagina finisce di sistemarsi (il
       quadrante, il tema del progetto, l'animazione d'entrata) e poi arriva
       la finestra, invece di aprirsi in mezzo a tutto che si muove. */
    setTimeout(function(){
        try { window.openPomodoroStorico({ scope: 'all', editSessionId: a.id }); }
        catch (e) { if (window.console) console.error('[pomo]', e); }
    }, 350);
    return true;
}

/* ---------------- avviso del pomodoro chiuso d'ufficio ----------------
   Non e' un errore da sbattere in faccia: la pagina e' gia' pronta per un
   pomodoro nuovo e questo racconta soltanto com'e' finito il precedente.
   Toccandolo si apre lo storico su quella riga, gia' in modifica, perche' la
   durata salvata e' una stima e l'unico che sa quella vera e' l'utente. */
function mostraAvvisoAbbandono(){
    var a = STATE0.avviso;
    if (!a) return;
    var box = document.getElementById('pomoAvviso');
    if (!box) return;

    var d = new Date(String(a.data_inizio).replace(' ', 'T'));
    var quando = isNaN(d.getTime()) ? '' : d.toLocaleDateString(T.lingua || 'it', { day:'numeric', month:'long' })
                 + ' ' + T('pomodoro.avviso_alle') + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());

    document.getElementById('pomoAvvisoTesto').textContent =
        T('pomodoro.avviso_non_terminato', { quando: quando, durata: fmtDurataBreve(a.durata_secondi) });
    box.style.display = 'flex';
    // un fotogramma dopo, altrimenti la transizione non parte: l'elemento
    // passerebbe da display:none allo stato finale senza stati intermedi
    requestAnimationFrame(function(){ box.classList.add('show'); });

    function spegni(){
        box.classList.remove('show');
        setTimeout(function(){ box.style.display = 'none'; }, 300);
        post('pomodoro.php?ajax=chiudi_avviso', { session_id: a.id });
    }
    document.getElementById('pomoAvvisoX').addEventListener('click', spegni);
    document.getElementById('pomoAvvisoBody').addEventListener('click', function(){
        spegni();
        if (window.openPomodoroStorico) window.openPomodoroStorico({ scope: 'all', editSessionId: a.id });
    });
}
function fmtDurataBreve(sec){
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
    return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
}

})();
</script>
<?php include __DIR__ . '/../planner/todo_widget.php'; ?>
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
        /* Il titolo era in Georgia: il post-it di progetti e' passato al
           carattere di sistema perche' quello con le grazie e' faticoso da
           leggere su schermo, e questi due foglietti devono essere lo stesso
           oggetto. Le classi fanno il resto, niente stile scritto qui dentro. */
        box.innerHTML = '<div class="pomo-postit" style="max-width:320px">'
            + '<span class="pomo-postit-tape"></span>'
            + '<div class="pomo-postit-titolo">' + T('pomodoro.attivita_di_questo') + '</div>'
            + '<div class="pomo-postit-sub">'
            + doneCount + ' su ' + ids.length + ' spuntate. Cosa ne facciamo?</div>'
            + '<div class="pt-acts"></div></div>';
        document.body.appendChild(box);

        var acts = box.querySelector('.pt-acts');
        function act(label, mode, primary){
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = label;
            b.className = 'pomo-postit-act' + (primary ? ' is-primary' : '');
            b.addEventListener('click', function(){
                window.nisamaTodo.api('pomodoro_close', { mode: mode, ids: ids }).then(function(){
                    box.remove();
                    if (inst) inst.load();
                });
            });
            acts.appendChild(b);
        }
        act(T('pomodoro.elimina_spuntate'), 'done', true);
        act(T('pomodoro.elimina_tutte'), 'all', false);
        act('Tienile tutte', 'keep', false);
    };
})();
</script>
</body>
</html>