<?php
/* =====================================================================
   NISAMA — TO STUDY
   Due sezioni in verticale: sopra il calendario del mese, sotto l'elenco
   giorno per giorno di cosa c'è da studiare. L'elenco non è a scomparsa —
   è lì che si vive, il calendario serve a orientarsi.

   Gli eventi qui NON hanno una tabella loro: sono righe di planner_events
   nel calendario 'study'. È la decisione presa a monte, e il motivo per cui
   spostare un blocco qui lo sposta anche nel planner senza nessuna
   sincronizzazione da mantenere.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();   // la vogliono sidebar.php e project_selector.php
date_default_timezone_set('Europe/Rome');
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h(nisama_lingua()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title><?php te('todo.to_study'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>to_study.css?v=<?php echo @filemtime(__DIR__ . '/to_study.css') ?: 1; ?>">
</head>
<body>

<header class="ts-head">
    <!-- La barra resta minima: mese, anno e i comandi che servono davvero.
         Le frecce esistono perché da computer non c'è nessuno scorrimento
         orizzontale da fare — il gesto è per il telefono, i tasti per il
         mouse, e tutti e due portano allo stesso posto. -->
    <button type="button" class="ts-nav" id="tsPrev" aria-label="<?php te('planner.mese_prec'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
    </button>
    <button type="button" class="ts-nav" id="tsNext" aria-label="<?php te('planner.mese_succ'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9.5 5L16 12l-6.5 7"/></svg>
    </button>
    <div class="ts-mese"><span id="tsMese"></span> <span class="ts-anno" id="tsAnno"></span></div>
    <!-- «Oggi» è solo il numero del giorno: sta in una barra dove ogni parola
         ruba spazio al mese, e il numero da solo si riconosce subito. -->
    <button type="button" class="ts-oggi-btn" id="tsOggi" aria-label="<?php te('comune.oggi'); ?>" title="<?php te('comune.oggi'); ?>"></button>
    <!-- Non più una casella con due stati, ma la porta di un pannello: cosa
         mostrare oltre allo studio sono sei domande diverse, e una spunta sola
         le rispondeva tutte insieme o nessuna. -->
    <!-- La spunta accende e spegne tutto il planner con un gesto; il nome
         accanto apre il pannello per scegliere cosa. Due comandi vicini perché
         sono due domande diverse: «lo voglio vedere?» e «cosa di preciso?». -->
    <!-- La spunta sta DENTRO al riquadro, non di fianco: sono la stessa cosa —
         il planner — vista da due lati, «lo mostro?» e «cosa di preciso?».
         Fuori sembravano due comandi indipendenti messi vicini per caso. -->
    <div class="ts-planner-box">
        <input type="checkbox" id="tsPlannerOn" aria-label="<?php te('todo.study_planner'); ?>">
        <button type="button" class="ts-planner-btn" id="tsPlanner"><?php te('todo.study_planner'); ?></button>
    </div>
</header>

<!-- Il calendario. Scorrendo in orizzontale si cambia mese: qui non c'è la
     vista settimana né quella giorno, quindi il gesto è libero — nel planner
     invece serve già a passare da un giorno all'altro. -->
<div class="ts-cal" id="tsCal">
    <div class="ts-wd" id="tsWd"></div>
    <div class="ts-grid" id="tsGrid"></div>
</div>

<!-- Come sei messo. Sta sopra all'elenco perché è la domanda che ci si fa
     aprendo la pagina — «ce la faccio?» viene prima di «cosa faccio oggi». -->
<div class="ts-stato" id="tsStato"></div>

<div class="ts-lista-head">
    <span><?php te('todo.study_da_fare'); ?></span>
    <!-- Il "+" in cima serve per un giorno che non ha ancora niente: dagli
         altri si aggiunge dal "+" della loro riga, ma un giorno vuoto una riga
         non ce l'ha. -->
    <button type="button" class="ts-add-top" id="tsAddTop" aria-label="<?php te('todo.study_nuovo_blocco'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
    <!-- Due filtri sull'elenco, non due viste diverse: cambiano cosa si guarda
         restando dove si è. Il primo stringe gli eventi lunghi al loro primo e
         ultimo giorno invece di ripeterli su ognuno; il secondo lascia solo le
         date importanti, che è come si guarda un mese quando si vuole sapere
         «quando sono gli esami» e nient'altro. -->
    <button type="button" class="ts-filtro ts-filtro-sep" id="tsCompatta" aria-label="<?php te('todo.study_compatta_lunghi'); ?>" title="<?php te('todo.study_compatta_lunghi'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 8h16M8 12h8M4 16h16"/></svg>
    </button>
    <button type="button" class="ts-filtro" id="tsSoloKey" aria-label="<?php te('todo.study_solo_importanti'); ?>" title="<?php te('todo.study_solo_importanti'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.6 5.7 6.4.7-4.8 4.3 1.4 6.3L12 16.9 6.4 20l1.4-6.3L3 9.4l6.4-.7z"/></svg>
    </button>
    <!-- La stella con il più dentro, di fianco al più normale: sono lo stesso
         gesto — aggiungere — su due cose diverse, e la parola accanto diceva
         quello che la stella rossa dice già. -->
    <button type="button" class="ts-add-imp" id="tsAddImp" aria-label="<?php te('todo.study_data_importante'); ?>" title="<?php te('todo.study_data_importante'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path d="M12 2.6l2.7 5.9 6.5.7-4.9 4.4 1.4 6.4L12 16.7 6.3 20l1.4-6.4L2.8 9.2l6.5-.7z"/></svg>
        <svg class="ts-add-imp-piu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M12 8.5v6M9 11.5h6"/></svg>
    </button>
</div>
<div class="ts-lista" id="tsLista"></div>

<!-- Foglio di modifica, riempito dal JS -->
<div class="ts-ov" id="tsOv">
    <div class="ts-sheet">
        <div class="ts-sheet-head">
            <span class="ts-sheet-tit" id="tsSheetTit"></span>
            <button type="button" class="ts-x" id="tsClose" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="ts-sheet-body" id="tsSheetBody"></div>
    </div>
</div>

<!-- Popup di scelta: uno solo per tutta la pagina. Le domande sono diverse
     (cosa fare degli argomenti, cancellare o scollegare, le date nuove) ma la
     forma è sempre la stessa, e vederla comparire uguale aiuta a riconoscerla
     invece di doverla rileggere ogni volta. -->
<div class="ts-scelta-ov" id="tsScelta">
    <div class="ts-scelta">
        <div class="ts-scelta-t"></div>
        <div class="ts-scelta-m"></div>
        <div class="ts-scelta-b-box"></div>
    </div>
</div>

<!-- Pannello dei filtri del planner. Calendari e categorie stanno chiusi:
     chi ne ha venti si troverebbe un elenco più lungo dello schermo prima
     ancora di aver letto le prime quattro voci, che sono quelle che si
     cambiano davvero. -->
<div class="ts-scelta-ov" id="tsFiltriOv">
    <div class="ts-scelta ts-filtri">
        <div class="ts-scelta-t"><?php te('todo.study_cosa_mostrare'); ?></div>
        <div class="ts-filtri-box" id="tsFiltriBox"></div>
        <button type="button" class="ts-scelta-b ts-scelta-primo" id="tsFiltriOk"><?php te('comune.conferma'); ?></button>
    </div>
</div>

<div class="ts-toast" id="tsToast"></div>

<?php
/* Le traduzioni e lo script della pagina vengono PRIMA degli include, non
   dopo. Un errore dentro sidebar.php o project_selector.php interrompe la
   stampa della pagina, e tutto quello che sta sotto non viene mai scritto:
   con lo script in fondo, un inciampo in un componente lasciava il calendario
   completamente vuoto senza dire niente. Cosi' invece la pagina resta in
   piedi da sola. */
echo nisama_i18n_json(['comune', 'todo', 'planner']);
?>
<script src="<?php echo PWA_BASE; ?>to_study.js?v=<?php echo @filemtime(__DIR__ . '/to_study.js') ?: 1; ?>" defer></script>
<script>
/* Rete di sicurezza: se to_study.js non parte (non caricato sul server, un
   404, la pagina troncata da un errore piu' su) il calendario resta vuoto e
   basta, senza che nulla lo spieghi. Dopo un secondo, se nessuna casella e'
   stata disegnata, si dice cosa manca invece di lasciare il vuoto. */
setTimeout(function () {
    if (document.querySelector('.ts-cella')) return;
    var g = document.getElementById('tsGrid');
    if (!g) return;
    g.innerHTML = '<div style="padding:26px 16px;text-align:center;font-size:13.5px;line-height:1.5;opacity:.75">'
        + 'to_study.js non e\' partito. Controlla che il file sia sul server e guarda la console del browser.'
        + '</div>';
}, 1000);
</script>

<?php
/* Il selettore progetti: è da qui che un blocco prende il colore e, con lui,
   il diritto di entrare nelle statistiche dei pomodori. */
$ps_id = 'study'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_selected_color = null; $ps_exclude_id = 0;
$ps_placeholder = t('todo.nessun_progetto'); $ps_title = t('todo.collega_progetto');
$ps_show_all_option = true; $ps_all_option_label = t('todo.nessun_progetto'); $ps_allow_create = true;
$ps_render_trigger = false;   // serve solo il popup: il pulsante lo apre il foglio
$ps_hidden_name = 'study_project_id';
include 'project_selector.php';

include 'sidebar.php';
?>
</body>
</html>
