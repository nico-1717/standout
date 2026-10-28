<?php
/* =====================================================================
   HABIT TRACKER — pagina principale + API AJAX
   Stessa impostazione di progetti.php: config -> connessione -> auth_check,
   endpoint JSON sotto ?ajax=..., HTML/CSS/JS mobile first.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
/* sec_db() riusa la connessione gia' aperta e la configura (utf8mb4, errori
   come eccezioni) invece di aprirne una seconda. */
$conn = sec_db();
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];

if (function_exists('date_default_timezone_set')) { date_default_timezone_set('Europe/Rome'); }

/* ---------------------------------------------------------------------
   La logica (lettura, serie, traguardi, check-in, salvataggio) sta in
   lib/habits_core.php: la usa anche l'app (/api/v1/habits). Qui restano solo
   le tre funzioni che parlano con questa pagina: leggere $_POST e rispondere.
   --------------------------------------------------------------------- */
require_once __DIR__ . '/../../lib/habits_core.php';

function hb_json($payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function hb_fail($message, $code = 400) {
    http_response_code($code);
    hb_json(['ok' => false, 'error' => $message]);
}

function hb_post($key, $default = '') {
    if (!isset($_POST[$key])) return $default;
    return sec_str($_POST[$key], 2000);
}

/* =====================================================================
   API AJAX
   ===================================================================== */
$ajax = isset($_GET['ajax']) ? $_GET['ajax'] : '';

/* Ogni chiamata che scrive deve portare il token della sessione e non può
   arrivare da un altro sito: lo verifica security_core.php. Il token lo
   aggiunge da sé standout_fetch.js, incluso nel <head>. */
if ($ajax !== '' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'habits', 0, 600, 60);
}
/* Il client chiama sempre con POST, anche per leggere. */
if ($ajax !== '') sec_solo_post_se_scrive($ajax, []);

if ($ajax === 'bootstrap') {
    hb_json([
        'ok'           => true,
        'today'        => date('Y-m-d'),
        'habits'       => hb_load_habits($conn, $current_user_id),
        'future'       => hb_load_habits($conn, $current_user_id, true),
        'logs'         => hb_load_logs($conn, $current_user_id),
        'journal'      => hb_load_journal($conn, $current_user_id),
        'categories'   => hb_load_categories($conn, $current_user_id),
        'achievements' => hb_load_achievements($conn, $current_user_id),
        'settings'     => hb_settings($conn, $current_user_id),
    ]);
}

/* --- check-in: incrementa, decrementa, salta, imposta un valore ------- */
if ($ajax === 'log_set') {
    $habitId = sec_int($_POST['habit_id'] ?? 0, 1);
    $date    = hb_date(hb_post('date'), date('Y-m-d'));
    $mode    = hb_enum(hb_post('mode'), ['increment','decrement','set','skip','fail','clear','toggle'], 'toggle');
    $value   = (float)hb_post('value', '0');
    $source  = hb_enum(hb_post('source'), ['swipe','tap','table','detail','quick_add','undo','auto'], 'tap');
    $note    = hb_post('note', '');
    $r = hb_log_imposta($current_user_id, $habitId, $date, $mode, $value, $source, $note);
    if (($r['errore'] ?? '') === 'non_trovata') hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    if (($r['errore'] ?? '') === 'futuro') hb_fail(t('abitudini.no_giorno_futuro'));
    hb_json(['ok' => true] + $r);
}

/* --- nota / umore di un check-in ------------------------------------- */
if ($ajax === 'log_note') {
    $habitId = sec_int($_POST['habit_id'] ?? 0, 1);
    $date    = hb_date(hb_post('date'), date('Y-m-d'));
    $note    = mb_substr(hb_post('note'), 0, 500);
    $mood    = hb_post('mood') === '' ? null : (int)hb_clamp(hb_post('mood'), 1, 5);
    $r = hb_nota_salva($current_user_id, $habitId, $date, $note, $mood);
    if (($r['errore'] ?? '') === 'non_trovata') hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    hb_json(['ok' => true] + $r);
}

/* --- creazione / modifica abitudine ---------------------------------- */
if ($ajax === 'habit_save') {
    $r = hb_abitudine_salva($current_user_id, $_POST);
    if (($r['errore'] ?? '') === 'nome') hb_fail(t('abitudini.serve_un_nome'));
    if (($r['errore'] ?? '') === 'non_trovata') hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    hb_json(['ok' => true] + $r);
}

/* --- archivia / ripristina ------------------------------------------- */
if ($ajax === 'habit_archive') {
    $id      = sec_int($_POST['id'] ?? 0, 1);
    $archive = sec_bool($_POST['archive'] ?? '1') ? 1 : 0;
    if (!hb_abitudine_archivia($current_user_id, $id, $archive === 1)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    hb_json(['ok' => true, 'id' => $id, 'archived' => $archive === 1]);
}

/* --- eliminazione definitiva ----------------------------------------- */
if ($ajax === 'habit_delete') {
    $id = sec_int($_POST['id'] ?? 0, 1);
    if (!hb_abitudine_elimina($current_user_id, $id)) hb_fail(t('abitudini.abitudine_non_trovata'), 404);
    hb_json(['ok' => true, 'id' => $id]);
}

/* --- riordino elenco -------------------------------------------------- */
if ($ajax === 'habit_reorder') {
    $ids = json_decode(hb_post('ids', '[]'), true);
    if (!is_array($ids)) hb_fail(t('abitudini.ordine_non_valido'));
    $order = 10;
    foreach ($ids as $rawId) {
        $hid = sec_int($rawId, 1);
        if ($hid <= 0) continue;
        sec_q("UPDATE habits SET sort_order = ? WHERE id = ? AND user_id = ?", [$order, $hid, $current_user_id]);
        $order += 10;
    }
    hb_json(['ok' => true]);
}

/* --- categoria personalizzata ---------------------------------------- */
if ($ajax === 'category_save') {
    $name = mb_substr(hb_post('name'), 0, 60);
    if ($name === '') hb_fail(t('abitudini.serve_un_nome'));
    $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) . '_' . $current_user_id;
    $icon  = mb_substr(hb_post('icon', '🏷️'), 0, 8);
    $color = hb_color(hb_post('color'), '#8E8E93');
    $order = (int)hb_scalar("SELECT COALESCE(MAX(sort_order),0)+10 FROM habit_categories WHERE user_id = ?", [$current_user_id]);
    sec_q("INSERT INTO habit_categories (user_id, slug, name, icon, color, sort_order) VALUES (?,?,?,?,?,?)
                                   ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), color=VALUES(color)", [$current_user_id, $slug, $name, $icon, $color, $order]);
    hb_json(['ok' => true, 'categories' => hb_load_categories($conn, $current_user_id)]);
}

/* --- preferenze ------------------------------------------------------- */
if ($ajax === 'settings_save') {
    $fields = [
        'week_start'          => ['i', (int)hb_clamp(hb_post('week_start', '1'), 1, 7)],
        'default_view'        => ['s', hb_enum(hb_post('default_view'), ['list','table','stats'], 'list')],
        'visible_days'        => ['i', (int)hb_clamp(hb_post('visible_days', '11'), 5, 31)],
        'accent_color'        => ['s', hb_color(hb_post('accent_color'))],
        'theme_mode'          => ['s', hb_enum(hb_post('theme_mode'), ['auto','light','dark'], 'auto')],
        'show_archived'       => ['i', hb_post('show_archived') === '1' ? 1 : 0],
        'group_by_day_part'   => ['i', hb_post('group_by_day_part') === '1' ? 1 : 0],
        'haptics_enabled'     => ['i', hb_post('haptics_enabled') === '1' ? 1 : 0],
        'sound_enabled'       => ['i', hb_post('sound_enabled') === '1' ? 1 : 0],
        'confetti_enabled'    => ['i', hb_post('confetti_enabled') === '1' ? 1 : 0],
        'carousel_autoplay'   => ['i', hb_post('carousel_autoplay') === '1' ? 1 : 0],
        'carousel_interval_s' => ['i', (int)hb_clamp(hb_post('carousel_interval_s', '6'), 3, 30)],
        'chart_range_days'    => ['i', (int)hb_clamp(hb_post('chart_range_days', '30'), 7, 365)],
    ];
    $sets = []; $types = ''; $values = [];
    foreach ($fields as $col => $meta) {
        if (!isset($_POST[$col])) continue;
        $sets[] = "`$col` = ?"; $types .= $meta[0]; $values[] = $meta[1];
    }
    if (!empty($sets)) {
        $values[] = $current_user_id;
        /* $sets contiene "campo = ?": segnaposto, non valori. I tipi non si
           dichiarano piu': li deduce sec_q dal valore. */
        sec_q("UPDATE habit_settings SET " . implode(', ', $sets) . " WHERE user_id = ?", $values);
    }
    hb_json(['ok' => true, 'settings' => hb_settings($conn, $current_user_id)]);
}

/* --- diario del giorno ------------------------------------------------ */
if ($ajax === 'journal_save') {
    $date   = hb_date(hb_post('date'), date('Y-m-d'));
    $note   = mb_substr(hb_post('note'), 0, 4000);
    $mood   = hb_post('mood') === '' ? null : (int)hb_clamp(hb_post('mood'), 1, 5);
    $energy = hb_post('energy') === '' ? null : (int)hb_clamp(hb_post('energy'), 1, 5);
    $sql = "INSERT INTO habit_daily_journal (user_id, entry_date, mood, energy, note) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE mood=VALUES(mood), energy=VALUES(energy), note=VALUES(note)";
    sec_q($sql, [$current_user_id, $date, $mood, $energy, $note]);
    hb_json(['ok' => true]);
}

/* --- traguardi visti -------------------------------------------------- */
if ($ajax === 'achievements_seen') {
    sec_q("UPDATE habit_user_achievements SET is_seen = 1 WHERE user_id = ?", [$current_user_id]);
    hb_json(['ok' => true]);
}

/* --- orari dei check-in (grafico "a che ora") ------------------------- */
if ($ajax === 'hour_distribution') {
    $sql = "SELECT HOUR(created_at) AS h, COUNT(*) AS c
            FROM habit_log_events
            WHERE user_id = ? AND delta > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY h ORDER BY h";
    $res = sec_all($sql, [$current_user_id]);
    $buckets = array_fill(0, 24, 0);
    foreach ($res as $row) { $buckets[(int)$row['h']] = (int)$row['c']; }
    hb_json(['ok' => true, 'hours' => $buckets]);
}

/* =====================================================================
   PAYLOAD INIZIALE (niente fetch al primo caricamento: la pagina è già viva)
   ===================================================================== */
$bootstrap = [
    'today'        => date('Y-m-d'),
    'user'         => ['id' => $current_user_id, 'name' => isset($utente_corrente['username']) ? $utente_corrente['username'] : ''],
    'habits'       => hb_load_habits($conn, $current_user_id),
    'future'       => hb_load_habits($conn, $current_user_id, true),
    'logs'         => hb_load_logs($conn, $current_user_id),
    'journal'      => hb_load_journal($conn, $current_user_id),
    'categories'   => hb_load_categories($conn, $current_user_id),
    'achievements' => hb_load_achievements($conn, $current_user_id),
    'settings'     => hb_settings($conn, $current_user_id),
    'catalog'      => hb_presets(),
];
$accent = hb_color($bootstrap['settings']['accent_color'] ?? '#7C5CFF');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<meta name="theme-color" content="#000000">
<title><?php te('abitudini.titolo'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="habits.css?v=<?php echo @filemtime(__DIR__ . '/habits.css') ?: time(); ?>">
</head>
<body class="hb-body" style="--accent:<?php echo sec_h($accent); ?>;">

<!-- ============================ HEADER ============================ -->
<header class="hb-header">
    <div class="hb-header-row">
        <div class="hb-header-titles">
            <div class="hb-eyebrow" id="hbEyebrow"><?php te('comune.oggi'); ?></div>
            <h1 class="hb-title"><?php te('abitudini.abitudini'); ?></h1>
        </div>
        <div class="hb-header-actions">
            <button type="button" class="hb-icon-btn" id="hbBtnTable" aria-label="<?php te('abitudini.vista_tabella'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5h18M3 12h18M3 19h18M9 5v14M15 5v14"/></svg>
            </button>
            <button type="button" class="hb-icon-btn" id="hbBtnStats" aria-label="<?php te('abitudini.statistiche'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 20V10M12 20V4M19 20v-6"/></svg>
            </button>
            <button type="button" class="hb-icon-btn" id="hbBtnSettings" aria-label="<?php te('comune.impostazioni'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 8.9 19a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 5 8.9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
            </button>
            <?php /* pallino del profilo, in fila con i pulsanti dell'intestazione */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
        </div>
    </div>

    <!-- barra giorni scorrevole -->
    <div class="hb-daystrip" id="hbDayStrip"></div>
</header>

<?php if (is_readable(__DIR__ . '/../partials/sidebar.html')) { include __DIR__ . '/../partials/sidebar.html'; } ?>

<main class="hb-page">
    <!-- carosello statistiche: scorre da solo e si aggiorna in tempo reale -->
    <section class="hb-carousel-wrap">
        <div class="hb-carousel" id="hbCarousel"></div>
        <div class="hb-dots" id="hbCarouselDots"></div>
    </section>

    <!-- anello riepilogo del giorno selezionato -->
    <section class="hb-dayhero" id="hbDayHero"></section>

    <!-- elenco abitudini -->
    <section class="hb-list-wrap">
        <div class="hb-list-head">
            <div class="hb-list-title" id="hbListTitle"><?php te('abitudini.le_tue_abitudini'); ?></div>
            <div class="hb-filters" id="hbFilters"></div>
        </div>
        <div class="hb-list" id="hbList"></div>
        <?php /* Le abitudini che iniziano piu' avanti non stanno nell'elenco: si aprono da qui. */ ?>
        <button type="button" class="hb-future-btn" id="hbFutureBtn" hidden></button>
        <div class="hb-empty" id="hbEmpty" hidden>
            <div class="hb-empty-emoji">🌱</div>
            <div class="hb-empty-title"><?php te('abitudini.qui_non_c_e_ancora'); ?></div>
            <p class="hb-empty-text"><?php te('abitudini.aggiungi_la_prima_abitudine_e'); ?></p>
            <button type="button" class="hb-btn hb-btn-primary" id="hbEmptyAdd"><?php te('abitudini.aggiungi_abitudine'); ?></button>
        </div>
    </section>
</main>

<button type="button" class="hb-fab" id="hbFab" aria-label="<?php te('abitudini.aggiungi_abitudine'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- effetti: particelle di completamento -->
<canvas id="hbConfetti" class="hb-confetti"></canvas>
<div class="hb-toast-stack" id="hbToasts"></div>

<!-- ============================ SHEETS ============================ -->
<div class="hb-sheet-overlay" id="hbSheetEditor">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbEditorTitle"><?php te('abitudini.nuova_abitudine'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetEditor" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbEditorBody"></div>
        <div class="hb-sheet-actions" id="hbEditorActions"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetFuture">
    <div class="hb-sheet">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.future_titolo'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetFuture" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbFutureBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetDetail">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbDetailTitle"><?php te('abitudini.dettaglio'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetDetail" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbDetailBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetStats">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.statistiche'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetStats" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbStatsBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetTable">
    <div class="hb-sheet hb-sheet-full">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.tabella'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetTable" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbTableBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetPicker">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbPickerTitle"><?php te('abitudini.scegli'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetPicker" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbPickerBody"></div>
    </div>
</div>

<div class="hb-sheet-overlay" id="hbSheetSettings">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('comune.impostazioni'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetSettings" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body" id="hbSettingsBody"></div>
    </div>
</div>

<!-- nota del check-in / diario della giornata -->
<div class="hb-sheet-overlay" id="hbSheetNote">
    <div class="hb-sheet">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title" id="hbNoteTitle"><?php te('abitudini.nota'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetNote" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body">
            <div class="hb-note-day" id="hbNoteDay"></div>
            <div class="hb-field">
                <label><?php te('abitudini.com_e_andata'); ?></label>
                <div class="hb-mood-row" id="hbNoteMood"></div>
            </div>
            <div class="hb-field" id="hbNoteEnergyField" hidden>
                <label><?php te('abitudini.energia'); ?></label>
                <div class="hb-mood-row" id="hbNoteEnergy"></div>
            </div>
            <div class="hb-field">
                <label for="hbNoteText"><?php te('abitudini.il_tuo_pensiero'); ?></label>
                <textarea class="hb-textarea" id="hbNoteText" rows="5" maxlength="1000"
                          placeholder="<?php te('abitudini.cosa_ti_ha_aiutato_oggi'); ?>"></textarea>
                <div class="hb-help" id="hbNoteCount"><?php te('abitudini.0_caratteri'); ?></div>
            </div>
        </div>
        <div class="hb-sheet-actions">
            <button type="button" class="hb-btn hb-btn-quiet" id="hbNoteClear"><?php te('abitudini.cancella'); ?></button>
            <button type="button" class="hb-btn hb-btn-primary" id="hbNoteSave"><?php te('abitudini.salva_nota'); ?></button>
        </div>
    </div>
</div>

<!-- riordino abitudini -->
<div class="hb-sheet-overlay" id="hbSheetReorder">
    <div class="hb-sheet hb-sheet-tall">
        <div class="hb-grabber"></div>
        <div class="hb-sheet-head">
            <div class="hb-sheet-title"><?php te('abitudini.riordina'); ?></div>
            <button type="button" class="hb-sheet-close" data-close="hbSheetReorder" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="hb-sheet-body">
            <div class="hb-help hb-reorder-hint"><?php te('abitudini.trascina_dalla_maniglia_per_cambiare'); ?></div>
            <div class="hb-reorder-list" id="hbReorderList"></div>
        </div>
    </div>
</div>

<!-- conferma generica -->
<div class="hb-confirm-overlay" id="hbConfirm">
    <div class="hb-confirm-box">
        <div class="hb-confirm-title" id="hbConfirmTitle"><?php te('abitudini.sicuro'); ?></div>
        <div class="hb-confirm-msg" id="hbConfirmMsg"></div>
        <div class="hb-confirm-actions">
            <button type="button" class="hb-btn hb-btn-quiet" id="hbConfirmNo"><?php te('comune.annulla'); ?></button>
            <button type="button" class="hb-btn hb-btn-danger" id="hbConfirmYes"><?php te('comune.elimina'); ?></button>
        </div>
    </div>
</div>

<!-- traguardo sbloccato -->
<div class="hb-award-overlay" id="hbAward">
    <div class="hb-award-card">
        <div class="hb-award-glow"></div>
        <div class="hb-award-icon" id="hbAwardIcon">🏆</div>
        <div class="hb-award-kicker"><?php te('abitudini.traguardo_sbloccato'); ?></div>
        <div class="hb-award-name" id="hbAwardName"></div>
        <div class="hb-award-desc" id="hbAwardDesc"></div>
        <button type="button" class="hb-btn hb-btn-primary" id="hbAwardClose"><?php te('abitudini.continua'); ?></button>
    </div>
</div>

<?php
/* Popup di import/export: si apre dal tasto "Importa"/"Esporta" nelle
   Impostazioni (vedi renderSettings() in habits.js). Va incluso qui, dopo
   tutti i pannelli della pagina, cosi' l'overlay sta sopra a tutto. */
include __DIR__ . '/habits_import.php';
?>

<?php
/* Le stringhe che servono a habits.js: vanno prima del file, T() deve
   esistere quando parte. */
/* 'import' serve al pannello di importazione, incluso qui sotto. */
echo standout_i18n_json(['comune', 'abitudini', 'import']);
?>
<script nonce="<?php echo sec_nonce(); ?>">window.HB_BOOT = <?php echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<?php
/* Diario del giorno: componente condiviso con planner.php e diario.php,
   con in più gli elenchi "cose belle" e "cosa ho imparato". */
include __DIR__ . '/diario_giorno.php';
?>
<script src="habits.js?v=<?php echo @filemtime(__DIR__ . '/habits.js') ?: time(); ?>"></script>
</body>
</html>