<?php
/* =========================================================================
   STANDOUT · «PASSWORD DIMENTICATA» — chiedere il link
   GET: la pagina con il modulo. POST (JSON): chiede il link.
   La logica sta in lib/password_reset.php. La risposta e' SEMPRE la stessa, sia
   che l'account esista o no, e parte prima del lavoro.
   ========================================================================= */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/password_reset.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* CSRF + 10 richieste all'ora per indirizzo IP (si dice 429: e' il freno
       sull'indirizzo, non rivela niente sull'account). */
    sec_require_csrf();
    sec_require_rate('pw_reset_ip', sec_ip(), 10, 3600);
    $input = sec_body();
    $identifier = is_string($input['identifier'] ?? null) ? $input['identifier'] : '';
    if (trim($identifier) === '') {
        sec_die_json(t('password_reset.err_vuoto'), 422, 'validazione');
    }
    pr_rispondi_poi_lavora(['success' => true, 'message' => t('password_reset.inviata')]);
    pr_richiedi($identifier);
    exit;
}

header('Cache-Control: no-store');
$home = defined('PWA_BASE') ? PWA_BASE : '/';
$txt = ['attesa' => t('password_reset.attesa'), 'btn' => t('password_reset.invia'), 'err' => t('password_reset.err_generico'),
        'err_troppe' => t('password_reset.err_troppe'), 'err_vuoto' => t('password_reset.err_vuoto')];
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h(standout_lingua()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<title><?php echo sec_h(t('password_reset.titolo_richiesta')); ?> · Standout</title>
<?php pr_stile(); ?>
</head>
<body>
<main>
  <h1><?php te('password_reset.titolo_richiesta'); ?></h1>
  <p><?php te('password_reset.richiesta_p'); ?></p>
  <form id="prRichiesta" novalidate>
    <label>
      <span><?php te('password_reset.campo'); ?></span>
      <input type="text" id="prIdentifier" name="identifier" autocomplete="username" maxlength="255" required>
    </label>
    <p class="msg" id="prMsg" role="alert" hidden></p>
    <button type="submit" id="prBtn"><?php te('password_reset.invia'); ?></button>
  </form>
  <p class="piccolo"><a href="<?php echo sec_h($home); ?>"><?php te('password_reset.vai'); ?></a></p>
</main>
<script type="application/json" id="prTxt"><?php echo json_encode($txt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="<?php echo sec_h($home); ?>account/password_reset.js?v=<?php echo @filemtime(__DIR__ . '/password_reset.js') ?: 1; ?>" defer></script>
</body>
</html>
