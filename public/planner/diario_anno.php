<?php
/* =====================================================================
   STANDOUT — IL DIARIO IN NUMERI
   Si apre dal diario. Un anno intero a colpo d'occhio: ogni giorno scritto è
   un pallino colorato dall'umore (o dall'energia, o dalle abitudini fatte);
   sotto, le medie per settimana, mese e anno; in fondo, ogni trimestre, il
   resoconto scritto dall'IA e il libro dei propri pensieri.
   I dati arrivano da diary_api.php?ajax=insights; l'IA sta in diario_ai.php.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/stile.php';

$anno = (int)($_GET['anno'] ?? 0);
if ($anno < 2000 || $anno > 2100) $anno = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>" <?php echo standout_stile_attr(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FDFCF9">
<title><?php te('diario.dn_titolo'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>
<?php echo standout_i18n_json(['comune', 'diario']); ?>
<link rel="stylesheet" href="diario.css?v=<?php echo @filemtime(__DIR__ . '/diario.css') ?: 1; ?>">
<link rel="stylesheet" href="diario_anno.css?v=<?php echo @filemtime(__DIR__ . '/diario_anno.css') ?: 1; ?>">
<?php echo standout_stile_css(); ?>
</head>
<body class="dn-body">

<header class="dn-head">
    <a class="dn-back" href="diario.php" aria-label="<?php te('diario.torna_al_diario'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
    </a>
    <h1 class="dn-h1"><?php te('diario.dn_titolo'); ?></h1>
    <div class="dn-year">
        <button type="button" id="dnPrev" aria-label="<?php te('diario.dn_anno_prima'); ?>">‹</button>
        <span id="dnYear"><?php echo (int)$anno; ?></span>
        <button type="button" id="dnNext" aria-label="<?php te('diario.dn_anno_dopo'); ?>">›</button>
    </div>
</header>

<main class="dn-main" id="dnMain">
    <section class="dn-tiles" id="dnTiles" aria-live="polite"></section>

    <section class="dn-card">
        <div class="dn-card-h">
            <h2><?php te('diario.dn_calendario'); ?></h2>
            <div class="dn-seg" id="dnColore" role="tablist">
                <button type="button" class="dn-on" data-v="m"><?php te('diario.dn_umore'); ?></button>
                <button type="button" data-v="e"><?php te('diario.dn_energia'); ?></button>
                <button type="button" data-v="h"><?php te('diario.dn_abitudini'); ?></button>
            </div>
        </div>
        <div class="dn-months" id="dnMonths"></div>
        <div class="dn-legend" id="dnLegend"></div>
    </section>

    <section class="dn-card">
        <div class="dn-card-h">
            <h2><?php te('diario.dn_andamento'); ?></h2>
            <div class="dn-seg" id="dnPeriodo" role="tablist">
                <button type="button" class="dn-on" data-v="w"><?php te('diario.dn_settimane'); ?></button>
                <button type="button" data-v="m"><?php te('diario.dn_mesi'); ?></button>
                <button type="button" data-v="y"><?php te('diario.dn_anni'); ?></button>
            </div>
        </div>
        <div class="dn-rows" id="dnRows"></div>
    </section>

    <section class="dn-card dn-ai">
        <div class="dn-card-h"><h2><?php te('diario.dn_ai_titolo'); ?></h2></div>
        <p class="dn-p"><?php te('diario.dn_ai_intro'); ?></p>
        <div class="dn-quarters" id="dnQuarters"></div>
        <div class="dn-books" id="dnBooks"></div>
    </section>
</main>

<div class="dn-modal" id="dnModal" hidden>
    <div class="dn-modal-card" role="dialog" aria-modal="true">
        <div class="dn-modal-t" id="dnModalT"></div>
        <div class="dn-modal-b" id="dnModalB"></div>
        <div class="dn-modal-acts" id="dnModalActs"></div>
    </div>
</div>
<div class="dy-toast" id="dyToast"></div>

<?php include __DIR__ . '/diario_giorno.php'; /* porta window.standoutFace */ ?>
<script nonce="<?php echo sec_nonce(); ?>">window.DN_BOOT = <?php echo json_encode(['anno' => $anno, 'oggi' => date('Y-m-d')]); ?>;</script>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<script src="diario_anno.js?v=<?php echo @filemtime(__DIR__ . '/diario_anno.js') ?: 1; ?>"></script>
</body>
</html>
