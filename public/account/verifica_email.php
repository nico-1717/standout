<?php
/* =========================================================================
   STANDOUT · APERTURA DEL LINK DI VERIFICA EMAIL
   Si arriva da qui dall'email: /account/verifica_email.php?t=<token>
   Non serve essere collegati: il token, che solo chi legge quella posta ha,
   e' la prova. Cosa succede si decide in lib/email_verifica.php.
   ========================================================================= */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/email_verifica.php';

/* Contro chi prova token a caso: pochi tentativi all'ora per indirizzo IP. */
sec_require_rate('verifica_email', sec_ip(), 30, 3600);

$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
$utente = ev_conferma($token);
$ok = $utente !== null;
if ($ok) sec_audit('email_verificata', 'ok', null);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // il token e' nell'indirizzo: non deve finire altrove
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h(standout_lingua()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo sec_h(t($ok ? 'verifica_email.titolo_ok' : 'verifica_email.titolo_ko')); ?></title>
<style nonce="<?php echo sec_nonce(); ?>">
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#FBF9F6;color:#4C463F;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px;text-align:center}
main{max-width:420px}
h1{font-weight:500;font-size:24px;margin:0 0 10px}
p{color:#8A8177;margin:0 0 24px;line-height:1.5}
a{display:inline-block;padding:12px 24px;border-radius:14px;background:#A9C3AE;color:#FFFDFA;text-decoration:none;font-size:15px}
</style>
</head>
<body>
<main>
  <h1><?php echo sec_h(t($ok ? 'verifica_email.titolo_ok' : 'verifica_email.titolo_ko')); ?></h1>
  <p><?php echo sec_h(t($ok ? 'verifica_email.testo_ok' : 'verifica_email.testo_ko')); ?></p>
  <a href="<?php echo sec_h(defined('PWA_BASE') ? PWA_BASE : '/'); ?>"><?php echo sec_h(t('verifica_email.vai_alla_home')); ?></a>
</main>
</body>
</html>
