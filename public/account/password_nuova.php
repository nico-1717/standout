<?php
/* =========================================================================
   STANDOUT · «PASSWORD DIMENTICATA» — scegliere la password nuova
   Si arriva dal link dell'email: /account/password_nuova.php?t=<token>
   GET: il modulo (o «link non valido»). POST (JSON): salva la password.
   Non serve essere collegati: il token, che solo chi legge quella posta ha, e'
   la prova. La logica sta in lib/password_reset.php.
   ========================================================================= */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/password_reset.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // il token e' nell'indirizzo: non deve finire altrove

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_require_csrf();
    /* Contro chi prova token a caso o password a raffica: 20 richieste all'ora per indirizzo IP. */
    sec_require_rate('pw_reset_nuova', sec_ip(), 20, 3600);
    $input = sec_body();
    $token = is_string($input['t'] ?? null) ? $input['t'] : '';
    $nuova = is_string($input['nuova'] ?? null) ? $input['nuova'] : '';
    $conferma = is_string($input['conferma'] ?? null) ? $input['conferma'] : '';
    try {
        $esito = pr_conferma($token, $nuova, $conferma);
    } catch (Throwable $e) {
        sec_log_error('password_reset', get_class($e));
        sec_die_json(t('password_reset.err_generico'), 500, 'server');
    }
    header('Content-Type: application/json');
    if ($esito['ok']) { echo json_encode(['success' => true]); exit; }
    if ($esito['codice'] === 'validazione') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'validazione', 'errori' => $esito['errori']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(410);   // il link non e' piu' buono
    echo json_encode(['success' => false, 'error' => 'invalido', 'message' => t('password_reset.ko_p')], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
sec_require_rate('pw_reset_apri', sec_ip(), 60, 3600);
$valido = pr_token_valido($token) !== null;

$home = defined('PWA_BASE') ? PWA_BASE : '/';
$txt = ['attesa' => t('password_reset.attesa'), 'btn' => t('password_reset.salva'), 'err' => t('password_reset.err_generico'),
        'err_troppe' => t('password_reset.err_troppe'), 'ok_t' => t('password_reset.salvata_t'), 'ok_p' => t('password_reset.salvata_p'),
        'ko_t' => t('password_reset.ko_t'), 'ko_p' => t('password_reset.ko_p'), 'vai' => t('password_reset.vai'),
        'richiedi' => t('password_reset.ko_btn'), 'home' => $home, 'richiesta' => $home . 'account/password_dimenticata.php'];
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h(standout_lingua()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<title><?php echo sec_h(t($valido ? 'password_reset.titolo_nuova' : 'password_reset.ko_t')); ?> · Standout</title>
<?php pr_stile(); ?>
</head>
<body>
<main id="prPagina">
<?php if ($valido): ?>
  <h1><?php te('password_reset.titolo_nuova'); ?></h1>
  <p><?php te('password_reset.nuova_p'); ?></p>
  <form id="prNuova" novalidate>
    <input type="hidden" id="prToken" value="<?php echo sec_h($token); ?>">
    <label>
      <span><?php te('password_reset.nuova'); ?></span>
      <input type="password" id="prPw1" autocomplete="new-password" maxlength="128" required>
    </label>
    <label>
      <span><?php te('password_reset.conferma'); ?></span>
      <input type="password" id="prPw2" autocomplete="new-password" maxlength="128" required>
    </label>
    <p class="msg" id="prMsg" role="alert" hidden></p>
    <button type="submit" id="prBtn"><?php te('password_reset.salva'); ?></button>
  </form>
<?php else: ?>
  <h1><?php te('password_reset.ko_t'); ?></h1>
  <p><?php te('password_reset.ko_p'); ?></p>
  <a class="bottone" href="<?php echo sec_h($home); ?>account/password_dimenticata.php"><?php te('password_reset.ko_btn'); ?></a>
<?php endif; ?>
</main>
<script type="application/json" id="prTxt"><?php echo json_encode($txt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="<?php echo sec_h($home); ?>account/password_reset.js?v=<?php echo @filemtime(__DIR__ . '/password_reset.js') ?: 1; ?>" defer></script>
</body>
</html>
