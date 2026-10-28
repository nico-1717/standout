<?php
/* "Accetta i testi": la vede chi ha un account ma non ha ancora accettato le
   condizioni d'uso e l'informativa (registrato prima che esistessero, o testi
   cambiati dopo). Finche' non accetta, auth_check.php lo rimanda qui. */
define('STANDOUT_SENZA_TERMINI', true); // questa pagina non puo' rimandare a se stessa
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/legale.php';

$uid = (int)$utente_corrente['id'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* Dove tornare dopo: solo un percorso interno (mai un indirizzo di un altro sito). */
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
$next_ok = ($next !== '' && $next[0] === '/' && strncmp($next, '//', 2) !== 0 && strpos($next, "\\") === false
            && strpos($next, 'accetta_termini.php') === false) ? $next : PWA_BASE;

$errore = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'termini_accetta', 0, 20, 3600);
    if (empty($_POST['accetto'])) {
        $errore = t('accettatermini.err');
    } else {
        sec_q("UPDATE users SET terms_accepted_at = NOW(), terms_version = ? WHERE id = ?", [STANDOUT_LEGALE_VERSIONE, $uid], 'si');
        sec_audit('termini_accettati', 'ok', STANDOUT_LEGALE_VERSIONE);
        header('Location: ' . $next_ok);
        exit;
    }
}

$css = PWA_BASE . 'legal/legale.css?v=' . (@filemtime(__DIR__ . '/../legal/legale.css') ?: 1);
?><!DOCTYPE html>
<html lang="<?php echo $h(standout_lingua()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php te('accettatermini.titolo'); ?> · Standout</title>
<link rel="stylesheet" href="<?php echo $h($css); ?>">
</head>
<body>
<main class="lg">
  <h1><?php te('accettatermini.titolo'); ?></h1>
  <p class="lg-ver"><?php te('accettatermini.intro'); ?></p>

  <section class="lg-box">
    <form method="post" action="<?php echo $h(PWA_BASE); ?>account/accetta_termini.php">
      <input type="hidden" name="csrf_token" value="<?php echo $h(sec_csrf_token()); ?>">
      <input type="hidden" name="next" value="<?php echo $h($next_ok); ?>">
      <?php if ($errore): ?><p class="lg-msg" role="alert"><?php echo $h($errore); ?></p><?php endif; ?>
      <label class="lg-check">
        <input type="checkbox" name="accetto" value="1" required>
        <span><?php th('auth.accetto', [
            'termini' => '<a class="lg-link" href="' . $h(PWA_BASE) . 'legal/termini.php" target="_blank" rel="noopener">' . $h(t('legale.titolo_termini')) . '</a>',
            'privacy' => '<a class="lg-link" href="' . $h(PWA_BASE) . 'legal/privacy.php" target="_blank" rel="noopener">' . $h(t('legale.titolo_privacy')) . '</a>',
        ]); ?></span>
      </label>
      <button type="submit" class="lg-btn"><?php te('accettatermini.btn'); ?></button>
    </form>
  </section>

  <p class="lg-ver">
    <a class="lg-link" href="<?php echo $h(PWA_BASE); ?>account/logout.php"><?php te('accettatermini.esci'); ?></a> ·
    <a class="lg-link" href="<?php echo $h(PWA_BASE); ?>account/privacy_dati.php"><?php te('accettatermini.dati'); ?></a>
  </p>
</main>
</body>
</html>
