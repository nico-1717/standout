<?php
/* =====================================================================
   STANDOUT — PLANNER (vista giorno/settimana + vista mese)
   Questo file e' solo la pagina: i dati iniziali e l'HTML.
     la logica  -> lib/planner/ (planner_lib.php e i suoi moduli)
     l'API      -> planner_api.php
     lo stile   -> planner.css (+ _assets/ui/stile_standard.css)
     il JS      -> js/ (13 parti, unite da planner_js.php)
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';

/* Le schede aperte prima della divisione chiamano ancora planner.php?ajax=:
   le si manda all'API, che risponde uguale. */
if (isset($_GET['ajax'])) { require __DIR__ . '/planner_api.php'; exit; }

require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/stile.php';   // standard | paper (Diario, Planner, To do, To study)
require_once __DIR__ . '/../../lib/planner/planner_lib.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();   // resa disponibile anche ai file inclusi (project_selector.php)

/* =====================================================================
   DATI INIZIALI DELLA PAGINA
   ===================================================================== */
$settings  = pl_settings($current_user_id);
$today     = date('Y-m-d');
$weekStart = pl_monday($today);
$rangeFrom = pl_add_days($weekStart, -7);
$rangeTo   = pl_add_days($weekStart, 20);

$boot = [
    'csrf'       => sec_csrf_token(),
    'today'      => $today,
    'user'       => ['id' => $current_user_id, 'name' => $utente_corrente['username'] ?? ''],
    'settings'   => $settings,
    'categories' => pl_categories($current_user_id),
    'calendars'  => pl_calendars($current_user_id),
    'routines'   => pl_routines($current_user_id),
    'favorites'  => pl_favorites($current_user_id),
    'week'       => pl_week_row($current_user_id, $weekStart, $settings),
    'events'     => pl_events_range($current_user_id, $rangeFrom, $rangeTo),
    'priorities' => pl_priorities_range($current_user_id, $rangeFrom, $rangeTo),
    'pills_due'  => pl_pills_due($current_user_id, date('Y-m-d'), date('H:i:s')),
    'lists'      => pl_week_lists($current_user_id, $weekStart),
    'diary'      => pl_diary_dates($current_user_id, $rangeFrom, $rangeTo),
    'habits'     => pl_habits_for_date($current_user_id, $today),
    'weekly'     => pl_habits_weekly($current_user_id, $today),
    'rings'      => pl_rings($current_user_id, $today),
    'range'      => ['from' => $rangeFrom, 'to' => $rangeTo],
    /* Chiave della sola mappa incorporata: è l'unica che può stare in pagina,
       perché è legata al dominio e al servizio Embed, che non si paga. Quella
       delle ricerche resta nel server e non compare mai qui. Se non è stata
       messa in config.php, la mappa semplicemente non si mostra. */
    'map_key'    => defined('GOOGLE_EMBED_KEY') ? GOOGLE_EMBED_KEY : '',
    'places'     => pl_places($current_user_id),
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>" class="pl-palette-<?php echo sec_h($settings['palette']); ?>" <?php echo standout_stile_attr(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FBF9F6">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title><?php te('planner.titolo'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: time(); ?>"></script>
<?php /* il foglio del planner, in sei parti: l'ordine conta (vedi css/planner_base.css) */
foreach (['base', 'griglia', 'liste', 'fogli', 'extra', 'responsive'] as $plCss) {
    $plFile = __DIR__ . '/css/planner_' . $plCss . '.css';
    echo '<link rel="stylesheet" href="css/planner_' . $plCss . '.css?v=' . (@filemtime($plFile) ?: 1) . '">' . "\n";
} ?>
<?php echo standout_stile_css(); ?>
</head>
<body class="pl-body">

<!-- ============ BARRA IN ALTO ============
     riga 1: settimana, oggi, vista; a destra impostazioni e profilo
     riga 2: cerca/filtro sopra il giorno a sinistra, mese e calendarietto a destra
     riga 3: affermazione positiva (se attiva) -->
<header class="pl-topbar" id="plTopbar">
    <div class="pl-ambient" id="plAmbient" aria-hidden="true"></div>

    <div class="pl-bar-tools">
        <?php /* La spunta "settimana completata" dentro la pastiglia non c'e'
                 piu': non la si usava, e rubava spazio e un tocco sbagliato. */ ?>
        <button type="button" class="pl-week" id="plWeekBtn">
            <span class="pl-week-n pl-serif" id="plWeekN">1</span>
            <span class="pl-week-k"><?php te('planner.settimana'); ?></span>
        </button>
        <?php /* "Vai a oggi" come un foglietto di calendario (mese in alto, numero
                 sotto): il numero da solo non diceva cosa faceva il tasto. */ ?>
        <button type="button" class="pl-today" id="plTodayBtn" aria-label="<?php te('planner.vai_a_oggi'); ?>">
            <span class="pl-today-m" id="plTodayM"></span>
            <span class="pl-today-d" id="plTodayD">1</span>
        </button>
        <?php /* Il selettore delle viste, accanto a "oggi": tutti e due dicono
                 quale pezzo di calendario si sta guardando. Piu' grande di un
                 tastino, con scritto la vista in cui si e'. */ ?>
        <button type="button" class="pl-tool pl-viewbtn" id="plViewBtn" aria-label="<?php te('planner.passa_alla_vista_mese'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>
            <span class="pl-viewbtn-lab" id="plViewLab"><?php te('planner.giorno'); ?></span>
            <svg class="pl-viewbtn-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10l5 5 5-5"/></svg>
        </button>
        <!-- Su telefono in verticale il pulsante non fa più da interruttore fra
             due viste: le viste sono tre, e un interruttore non le dice. -->
        <div class="pl-viewmenu" id="plViewMenu" role="menu" aria-hidden="true">
            <button type="button" role="menuitem" data-v="month"><?php te('planner.mese'); ?></button>
            <button type="button" role="menuitem" data-v="week"><?php te('planner.settimana_2'); ?></button>
            <button type="button" role="menuitem" data-v="day"><?php te('planner.giorno'); ?></button>
        </div>
        <span class="pl-tools-sp"></span>
        <?php /* Il meteo non ha piu' un tasto suo: sta in cima alle impostazioni,
                 bene in vista. L'ingranaggio e' l'icona che tutti leggono come
                 "impostazioni" (quella di prima sembrava un sole). */ ?>
        <button type="button" class="pl-tool" id="plSettingsBtn" aria-label="<?php te('comune.impostazioni'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        </button>
            <?php /* pallino del profilo, in fila con i pulsanti dell'intestazione */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
    </div>

    <div class="pl-affirm pl-serif" id="plAffirm"></div>

    <div class="pl-bar-2">
        <!-- vista, oggi e cerca: sotto la settimana e sopra il numero del
             giorno. Nella prima riga non ci stavano più tutti, e su un
             telefono stretto il pallino del profilo usciva dallo schermo. -->
        <div class="pl-tools-2" id="plTools2">
            <button type="button" class="pl-tool" id="plSearchBtn" aria-label="<?php te('comune.cerca'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
            </button>
            <?php /* il filtro dei calendari, accanto alla ricerca: tutte e due
                     decidono cosa si vede */ ?>
            <button type="button" class="pl-tool" id="plFilterBtn" aria-label="<?php te('planner.filtra_per_categoria'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M7 12h10M10 17h4"/></svg>
            </button>
        </div>
        <button type="button" class="pl-day-btn" id="plDayBtn">
            <span class="pl-day-num pl-serif" id="plDayNum">1</span>
            <span class="pl-day-wd" id="plDayWd"><?php te('planner.lunedi'); ?></span>
        </button>
        <button type="button" class="pl-month-btn" id="plMonthBtn">
            <span class="pl-month-name pl-serif" id="plMonthName"><?php te('planner.luglio'); ?></span>
            <span class="pl-month-year" id="plMonthYear">2026</span>
        </button>
        <button type="button" class="pl-mini-static" id="plMiniStatic" aria-label="<?php te('planner.calendario_del_mese'); ?>"></button>
    </div>

    <div class="pl-months-bar" id="plMonthsBar"></div>
</header>

<!-- Il calendario a comparsa vive FUORI dall'header, apposta: così qualunque
     cosa succeda al suo contenuto non può più far crescere la barra in alto
     e spingere via orari e priorità (il bug che è successo due volte). È
     un pannello a posizione fissa, agganciato sotto la barra, che si apre
     sopra il resto della pagina senza spostare nient'altro. -->
<div class="pl-mini" id="plMini"></div>

<!-- ============ GRIGLIA GIORNI + ORARI ============ -->
<main class="pl-body-scroll" id="plScroller">
    <div class="pl-row">
        <div class="pl-gutter"><div class="pl-gutter-canvas" id="plGutter"></div></div>
        <div class="pl-hclip">
            <!-- la striscia dei 21 giorni: si sposta con una trasformazione,
                 mai con lo scroll nativo, cosi' l'orizzontale non tocca mai
                 il verticale (e viceversa). La costruisce planner.js. -->
            <div class="pl-track" id="plTrack"></div>
        </div>
    </div>
</main>

<!-- ============ VISTA MESE ============ -->
<!-- `data-sb-not-overlay`: la vista mese è a schermo intero (position:fixed;
     inset:0), quindi il rilevatore della sidebar la scambiava per una finestra
     aperta e nascondeva il pulsante del menu. Con questo attributo la sidebar
     sa che è una pagina e non si toglie di mezzo. Il pulsante sta comunque
     sopra (z-index 10000 contro 50), quindi non serve altro. -->
<!-- Vista settimanale a tabella: sette giorni in colonna, le ore in riga.
     Vive solo su telefono in verticale, dove la griglia a orari di tre giorni
     è troppo stretta per leggersi e quella del mese troppo grossa per dire
     a che ora sono le cose. Intestazione e colonna delle ore restano ferme
     con `position:sticky` dentro un riquadro solo che scorre nei due sensi:
     due scorrimenti annidati sarebbero stati una lotta continua. -->
<section class="pl-wkview" id="plWeekView" data-sb-not-overlay>
    <!-- Intestazione e colonna delle ore stanno FUORI dal riquadro che
         scorre. Dentro non potevano restare ferme: un elemento appiccicato
         dentro una griglia può muoversi solo entro la sua cella, quindi la
         colonna delle ore se ne andava appena si scorreva oltre la sua
         larghezza. Qui le si trascina a mano seguendo lo scorrimento. -->
    <div class="pl-wk-top">
        <div class="pl-wk-corner"></div>
        <div class="pl-wk-topclip"><div class="pl-wk-heads" id="plWeekHeads"></div></div>
    </div>
    <div class="pl-wk-main">
        <div class="pl-wk-gutclip"><div class="pl-wk-gut" id="plWeekGut"></div></div>
        <div class="pl-wk-scroll" id="plWeekScroll">
            <div class="pl-wk-grid" id="plWeekGrid"></div>
        </div>
    </div>
</section>

<section class="pl-month" id="plMonthView" data-sb-not-overlay>
    <div class="pl-mgrid" id="plMonthGrid"></div>
    <!-- La maniglia che tira su l'elenco degli eventi. Prima l'elenco si
         apriva toccando un giorno, ma quel tocco serve per entrare nel
         giorno: due significati sullo stesso gesto non stanno in piedi. -->
    <button type="button" class="pl-mpull" id="plMonthPull" aria-label="<?php te('planner.mostra_l_elenco_degli_eventi'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>
    </button>
</section>

<!-- Elenco degli eventi dal giorno scelto in avanti: sale dal basso con la
     maniglia sotto il calendario, e si richiude toccando fuori. -->
<section class="pl-magenda" id="plMonthAgenda" aria-hidden="true">
    <button type="button" class="pl-magenda-grab" id="plMagendaClose" aria-label="<?php te('planner.chiudi_l_elenco'); ?>">
        <!-- la stessa freccetta della maniglia sotto il calendario, girata:
             è lo stesso comando, e cambia solo il verso -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div class="pl-magenda-body" id="plMagendaBody"></div>
</section>

<!-- ============ PULSANTI FLOTTANTI ============ -->
<div class="pl-fabs" id="plFabs">
    <button type="button" class="pl-ring" id="plHabitRing" aria-label="<?php te('planner.abitudini'); ?>">
        <?php /* I cerchi li disegna planner.js: quanti sono dipende da quali
                 famiglie di frequenza hai davvero (giornaliera, settimanale,
                 mensile, personalizzata), quindi non possono stare fissi qui. */ ?>
        <svg viewBox="0 0 46 46"></svg>
        <span class="pl-ring-face">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 12.5l4 4L19 7"/></svg>
        </span>
    </button>
    <div class="pl-fab-menu" id="plMenuHabit"></div>

    <!-- Tutto quello che si può richiudere sta qui dentro. Le abitudini no:
         sono l'unica cosa che si guarda senza doverla cercare, e restano. -->
    <div class="pl-fab-group" id="plFabGroup">

    <button type="button" class="pl-fab" id="plFabRoutine" aria-label="<?php te('planner.routine'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h10M4 12h13M4 18h8"/><circle cx="19" cy="6" r="1.6"/><circle cx="21" cy="12" r="1.6"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuRoutine"></div>

    <button type="button" class="pl-fab pl-fab-cat" id="plFabCat" aria-label="<?php te('planner.categoria_predefinita'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 4h7.7L20 10.3 13.3 17 6 9.7z"/><circle cx="10" cy="8.5" r="1.2"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuCat"></div>

    <button type="button" class="pl-fab pl-hidden" id="plFabFav" aria-label="<?php te('planner.progetti_preferiti'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuFav"></div>

    <button type="button" class="pl-fab" id="plFabShift" aria-label="<?php te('planner.anticipa_o_ritarda'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>
    </button>

    <button type="button" class="pl-fab" id="plFabDiary" aria-label="<?php te('planner.diario_del_giorno'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 4h10a2 2 0 012 2v14H8a2 2 0 01-2-2z"/><path d="M6 4v16"/><path d="M10 9h5M10 13h5"/></svg>
    </button>
    </div>

    <!-- La freccetta chiude e riapre la colonna. Sta in fondo al gruppo, non
         in cima: da chiusa si trova subito sotto le abitudini, da aperta
         resta l'ultima della fila, dove il pollice la ritrova. -->
    <button type="button" class="pl-fab pl-fab-toggle" id="plFabToggle"
            aria-label="<?php te('planner.mostra_o_nascondi_i_tastini'); ?>" aria-expanded="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>
</div>

<!-- rotellina degli scatti: la costruisce planner.js -->
<div class="pl-zoom" id="plZoom" aria-label="<?php te('planner.scatti_degli_orari'); ?>"></div>

<!-- da computer non si può scorrere: due frecce discrete per cambiare giorno -->
<button type="button" class="pl-arrow pl-arrow-prev" id="plPrevDay" aria-label="<?php te('planner.giorno_precedente'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
</button>
<button type="button" class="pl-arrow pl-arrow-next" id="plNextDay" aria-label="<?php te('planner.giorno_successivo'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M9.5 5L16 12l-6.5 7"/></svg>
</button>

<button type="button" class="pl-plus" id="plPlus" aria-label="<?php te('planner.nuovo_evento'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- frase motivazionale della settimana: barra in fondo -->
<div class="pl-quotebar pl-hidden" id="plQuoteBar">
    <span class="pl-serif" id="plQuoteText"></span>
</div>

<!-- pannello della settimana (to do / to buy / to study + cosa ho imparato):
     entra da sinistra, sopra la pagina -->
<div class="pl-wp-ov" id="plWeekPanelOv">
    <aside class="pl-wp" id="plWeekPanel">
        <div class="pl-wp-head">
            <span class="pl-sheet-title pl-serif"><?php te('planner.la_settimana'); ?></span>
            <button type="button" class="pl-txt-btn" id="plWeekPanelClose"><?php te('comune.chiudi'); ?></button>
        </div>
        <div class="pl-wp-body" id="plWeekPanelBody"></div>
    </aside>
</div>

<!-- striscia invisibile sul bordo sinistro: dal lunedì apre il pannello -->
<div class="pl-edge" id="plEdge" aria-hidden="true"></div>

<!-- barra della modalità anticipi/ritardi -->
<div class="pl-shift-bar" id="plShiftBar">
    <button type="button" class="pl-txt-btn" id="plShiftCancel"><?php te('comune.annulla'); ?></button>
    <div class="pl-switch" id="plShiftSwitch">
        <button type="button" data-dir="early"><?php te('planner.anticipo'); ?></button>
        <button type="button" data-dir="late" class="pl-on"><?php te('planner.ritardo'); ?></button>
    </div>
    <!-- Orario vero e proprio, non testo: così si tocca e si apre l'orologio
         del telefono invece di dover scrivere "00:10" con i due punti al posto
         giusto. Il valore letto resta della stessa forma (hh:mm), quindi il
         conto dei minuti in planner.js non cambia di una riga. -->
    <input type="time" class="pl-time-in" id="plShiftTime" value="00:10" step="60" aria-label="<?php te('planner.di_quanto_spostarli_ore_e'); ?>">
    <button type="button" class="pl-txt-btn pl-strong" id="plShiftSave"><?php te('comune.salva'); ?></button>
</div>

<!-- ============ POPUP: EVENTO ============ -->
<div class="pl-ov" id="plEventOv">
    <div class="pl-sheet pl-sheet-tinted" id="plEventSheet">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-head-left">
                <button type="button" class="pl-txt-btn" id="plEvCancel"><?php te('comune.annulla'); ?></button>
                <button type="button" class="pl-trash pl-hidden" id="plEvTrash" aria-label="<?php te('planner.elimina_evento'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/><path d="M10.5 11v6M13.5 11v6"/></svg>
                </button>
            </span>
            <span class="pl-label" id="plEvKicker"><?php te('planner.nuovo_evento'); ?></span>
            <button type="button" class="pl-txt-btn pl-strong" id="plEvSave"><?php te('comune.salva'); ?></button>
        </div>
        <div class="pl-sheet-body" id="plEvBody"></div>
    </div>
</div>

<!-- ============ POPUP: IMPOSTAZIONI / RICERCA / FILTRO / COPIA ============ -->
<div class="pl-ov" id="plSheetOv">
    <div class="pl-sheet pl-sheet-tall">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-sheet-title pl-serif" id="plSheetTitle"><?php te('comune.impostazioni'); ?></span>
            <button type="button" class="pl-txt-btn" data-pl-close="plSheetOv"><?php te('comune.chiudi'); ?></button>
        </div>
        <div class="pl-sheet-body" id="plSheetBody"></div>
    </div>
</div>

<!-- ============ AVVISO ============ -->
<div class="pl-alert-ov" id="plAlertOv">
    <div class="pl-alert">
        <div class="pl-alert-t pl-serif" id="plAlertTitle"></div>
        <div class="pl-alert-m" id="plAlertMsg"></div>
        <div class="pl-alert-acts" id="plAlertActs"></div>
    </div>
</div>

<div class="pl-toast" id="plToast"></div>

<?php
/* Selettore progetti riusato dal popup evento (stesso componente di
   pomodoro.php e progetti.php). Il planner ascolta l'evento
   "projectselected" per collegare il progetto all'evento. */
$ps_id = 'plev'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_placeholder = t('todo.nessun_progetto'); $ps_title = t('todo.collega_progetto');
$ps_show_all_option = true; $ps_all_option_label = t('todo.nessun_progetto'); $ps_allow_create = true;
$ps_hidden_name = 'plev_project_id';
include __DIR__ . '/../projects/project_selector.php';
/* nel planner il progetto si sceglie dal popup dell'evento: il pulsante
   inline del selettore non deve comparire in fondo alla pagina */
/* La regola sta in planner.css. Prima era un echo di un blocco di stile: anche
   una riga sola scritta nella pagina impedisce di togliere 'unsafe-inline'
   dalla CSP, quindi e' stata spostata nel foglio esterno. */
?>

<?php
/* Diario del giorno + foglio scritto a mano: componente condiviso con
   habits.php e con la pagina diario.php (stessi dati, stesse tabelle). */
include __DIR__ . '/diario_giorno.php';
?>

<?php
/* Le stringhe che servono a planner.js. Va prima di PL_BOOT e prima dello
   motore del planner (planner_js.php): T() deve esistere quando parte. */
echo standout_i18n_json(['comune', 'planner']);
?>
<script>window.PL_BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<?php /* il motore del planner: le parti in js/, unite da planner_js.php */
$plJsV = 0; foreach (glob(__DIR__ . '/js/[0-9][0-9]_*.js') ?: [] as $plJs) $plJsV = max($plJsV, (int)filemtime($plJs)); ?>
<script src="planner_js.php?v=<?php echo $plJsV; ?>"></script>
</body>
</html>