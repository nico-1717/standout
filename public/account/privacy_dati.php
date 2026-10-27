<?php
/* "Privacy e dati": dove l'utente legge le informative, scarica i suoi dati
   ed elimina l'account. Raggiungibile dal menu dell'account (avatar). */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/legale.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$v = fn($f) => @filemtime(__DIR__ . '/' . $f) ?: 1;
$css = PWA_BASE . 'legal/legale.css?v=' . (@filemtime(__DIR__ . '/../legal/legale.css') ?: 1);
?><!DOCTYPE html>
<html lang="<?php echo $h(standout_lingua()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?php echo $h(sec_csrf_token()); ?>">
<title><?php te('privacydati.titolo'); ?> · Standout</title>
<link rel="stylesheet" href="<?php echo $h($css); ?>">
<script src="<?php echo PWA_BASE; ?>_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>
</head>
<body>
<main class="lg">
  <nav class="lg-nav">
    <a href="<?php echo $h(PWA_BASE); ?>"><?php te('legale.torna'); ?></a>
  </nav>
  <h1><?php te('privacydati.titolo'); ?></h1>
  <p class="lg-ver"><?php te('privacydati.intro'); ?></p>

  <p>
    <a class="lg-link" href="<?php echo $h(PWA_BASE); ?>legal/privacy.php"><?php te('legale.titolo_privacy'); ?></a> ·
    <a class="lg-link" href="<?php echo $h(PWA_BASE); ?>legal/termini.php"><?php te('legale.titolo_termini'); ?></a>
  </p>

  <section class="lg-box">
    <h2><?php te('privacydati.scarica_t'); ?></h2>
    <p><?php te('privacydati.scarica_p'); ?></p>
    <form method="post" action="<?php echo $h(PWA_BASE); ?>account/esporta_dati.php" id="pdExport">
      <input type="hidden" name="csrf_token" value="<?php echo $h(sec_csrf_token()); ?>">
      <button type="submit" class="lg-btn"><?php te('privacydati.scarica_btn'); ?></button>
    </form>
  </section>

  <section class="lg-box lg-danger">
    <h2><?php te('privacydati.elimina_t'); ?></h2>
    <p><?php te('privacydati.elimina_p'); ?></p>
    <form id="pdDelete" autocomplete="off">
      <label class="lg-check"><input type="checkbox" id="pdAck"> <span><?php te('privacydati.elimina_ack'); ?></span></label>
      <label class="lg-field">
        <span><?php te('privacydati.elimina_pw'); ?></span>
        <input type="password" id="pdPw" autocomplete="current-password" maxlength="128">
      </label>
      <p class="lg-msg" id="pdMsg" role="alert" hidden></p>
      <button type="submit" class="lg-btn lg-btn-danger" id="pdBtn" disabled><?php te('privacydati.elimina_btn'); ?></button>
    </form>
  </section>
</main>
<?php
$pd_txt = ['attesa' => t('privacydati.elimina_attesa'), 'btn' => t('privacydati.elimina_btn'), 'ok' => t('privacydati.elimina_ok'),
           'err_pw' => t('privacydati.err_password'), 'err_troppe' => t('privacydati.err_troppe'), 'err' => t('privacydati.err_generico')];
?>
<script type="application/json" id="pdTxt"><?php echo json_encode($pd_txt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="<?php echo PWA_BASE; ?>account/privacy_dati.js?v=<?php echo $v('privacy_dati.js'); ?>" defer></script>
</body>
</html>
