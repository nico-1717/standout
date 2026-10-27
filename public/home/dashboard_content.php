<?php
/* =====================================================================
   STANDOUT — LA HOME (utente connesso)
   Questa pagina e' solo lo scheletro: intestazione, la griglia vuota e i
   dati che solo PHP conosce (nomi gia' tradotti delle pagine, notifiche).
   I riquadri li disegna home_core.js insieme ai file home_w_*.js, nell'ordine
   che l'utente ha scelto con «Personalizza»; i dati arrivano da home_api.php?ajax=summary
   in due tempi (prima i due riquadri in vista, poi gli altri in sottofondo).

   Perche' non scriverli qui in PHP: i riquadri si riordinano, si
   nascondono, si aggiornano dopo ogni spunta e devono funzionare anche con
   l'ultima fotografia dei dati quando manca la rete. Tutto questo e' piu'
   semplice se il disegno sta in un posto solo.
   ===================================================================== */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/social_gate.php';
require_once __DIR__ . '/../social/friends_core.php';   // pallino delle richieste di amicizia (solo se il social e' acceso)

$standout_utente = standout_utente_corrente();
if (!$standout_utente) { return; } // index.php dovrebbe già garantirlo, ma per sicurezza usciamo comunque

$nd_notifiche = standout_social_attivo() ? fr_conta_notifiche(fr_conn(), (int)$standout_utente['id']) : 0;

/* Le pagine del sito a un tocco. Etichette dal dizionario della barra
   laterale: un nome solo per pagina in tutta l'app. */
$ho_scorciatoie = [
    ['href' => 'planner/planner.php',   'label' => t('sidebar.planner'),    'icon' => 'calendar', 'color' => '#FF453A'],
    ['href' => 'planner/todo.php',      'label' => t('sidebar.todo'),       'icon' => 'check',    'color' => '#30D158'],
    ['href' => 'projects/progetti.php', 'label' => t('sidebar.progetti'),   'icon' => 'folder',   'color' => '#5E5CE6'],
    ['href' => 'projects/pomodoro.php', 'label' => t('sidebar.pomodoro'),   'icon' => 'timer',    'color' => '#FF6B3D'],
    ['href' => 'fc/fc_home.php',        'label' => t('sidebar.flashcards'), 'icon' => 'cards',    'color' => '#BF5AF2'],
    ['href' => 'exams/voti.php',        'label' => t('sidebar.voti'),       'icon' => 'cap',      'color' => '#0FB5AE'],
    ['href' => 'planner/habits.php',    'label' => t('sidebar.abitudini'),  'icon' => 'heart',    'color' => '#FF375F'],
    ['href' => 'planner/diario.php',    'label' => t('sidebar.diario'),     'icon' => 'book',     'color' => '#AC8E68'],
];
/* Gli amici solo se il social e' acceso sul sito (oggi no: vive nell'app). */
if (standout_social_attivo()) {
    $ho_scorciatoie[] = ['href' => 'social/friends.php', 'label' => t('sidebar.amici'), 'icon' => 'users', 'color' => '#5AC8FA', 'badge' => (int)$nd_notifiche];
}

$ho_v = function (string $f): int { return @filemtime(__DIR__ . '/' . $f) ?: 1; };
?>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<div class="standout-dash">
  <div class="ho-wrap" id="hoWrap">

    <header class="ho-head">
      <div class="ho-head-txt">
        <div class="ho-date" id="hoDate">&nbsp;</div>
        <h1 class="ho-hello" id="hoHello"><?php te('dashboard.saluto', ['nome' => $standout_utente['username']]); ?></h1>
      </div>
      <div class="ho-head-act">
        <span class="ho-streak" id="hoStreak" hidden></span>
        <button type="button" class="ho-ibtn" id="hoEditBtn" aria-pressed="false"
                aria-label="<?php te('home.personalizza'); ?>" title="<?php te('home.personalizza'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h8M18 6h2M4 12h2M12 12h8M4 18h10M20 18h0"/><circle cx="15" cy="6" r="2.2"/><circle cx="9" cy="12" r="2.2"/><circle cx="17" cy="18" r="2.2"/></svg>
        </button>
      </div>
    </header>

    <div class="ho-offline" id="hoOffline" role="status">
      <span><?php te('home.offline'); ?></span>
      <button type="button" class="ho-btn ho-btn--sm ho-btn--grey" id="hoRetry" style="margin-left:auto"><?php te('home.riprova'); ?></button>
    </div>

    <div class="ho-edit-bar">
      <span><?php te('home.personalizza_aiuto'); ?></span>
      <button type="button" class="ho-btn ho-btn--sm ho-btn--grey" id="hoEditReset"><?php te('home.ripristina'); ?></button>
      <button type="button" class="ho-btn ho-btn--sm" id="hoEditDone"><?php te('home.fine'); ?></button>
    </div>

    <?php /* barra di avanzamento della prima apertura: lo spazio c'e' sempre (3px), cosi' mostrarla non sposta niente */ ?>
    <div class="ho-load" id="hoLoad" aria-hidden="true"><i></i></div>

    <main class="ho-grid" id="hoGrid">
      <?php /* scheletro: si vede per il tempo di una richiesta, poi lo sostituiscono i riquadri */ ?>
      <section class="ho-w ho-w--wide"><div class="ho-skel" style="width:40%"></div><div class="ho-skel" style="height:84px"></div><div class="ho-skel"></div><div class="ho-skel" style="width:70%"></div></section>
      <section class="ho-w"><div class="ho-skel" style="width:50%"></div><div class="ho-skel" style="height:60px"></div><div class="ho-skel"></div></section>
    </main>

  </div>
</div>

<?php /* data-uid: la copia salvata nel browser si mostra subito solo se e' di questa persona */ ?>
<div id="hoRoot" data-base="<?php echo sec_h(PWA_BASE); ?>" data-uid="<?php echo (int)$standout_utente['id']; ?>" hidden></div>
<script type="application/json" id="hoShortcuts"><?php echo json_encode($ho_scorciatoie, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>

<?php include __DIR__ . '/../partials/notifiche_widget.php'; ?>

<?php echo standout_i18n_json(['comune', 'dashboard', 'home', 'sidebar']); ?>
<script src="<?php echo PWA_BASE; ?>home/home_core.js?v=<?php echo $ho_v('home_core.js'); ?>"></script>
<script src="<?php echo PWA_BASE; ?>home/home_w_plan.js?v=<?php echo $ho_v('home_w_plan.js'); ?>"></script>
<script src="<?php echo PWA_BASE; ?>home/home_w_tasks.js?v=<?php echo $ho_v('home_w_tasks.js'); ?>"></script>
<script src="<?php echo PWA_BASE; ?>home/home_w_study.js?v=<?php echo $ho_v('home_w_study.js'); ?>"></script>
<script nonce="<?php echo sec_nonce(); ?>">Home.start();</script>
