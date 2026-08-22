<?php
require_once 'config.php';
require_once 'auth_core.php';

$utente_corrente = nisama_utente_corrente();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php te($utente_corrente ? 'index.titolo_home' : 'index.titolo_landing'); ?></title>
<meta name="description" content="<?php te('index.descrizione'); ?>">
<meta name="theme-color" content="#12131A">
<?php
/* I fogli stanno qui e non dentro i file inclusi: quelli finiscono nel <body>,
   e un <link> nel corpo funziona ma fa sfarfallare il disegno mentre il foglio
   arriva. Si carica solo quello che serve: chi non e' connesso non deve
   scaricare lo stile della dashboard, e viceversa. */
?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>index.css?v=<?php echo @filemtime(dirname(__FILE__) . '/index.css') ?: 1; ?>">
<?php if ($utente_corrente): ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>dashboard.css?v=<?php echo @filemtime(dirname(__FILE__) . '/dashboard.css') ?: 1; ?>">
<?php else: ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>landing.css?v=<?php echo @filemtime(dirname(__FILE__) . '/landing.css') ?: 1; ?>">
<?php endif; ?>
</head>
<body>

<?php if ($utente_corrente): ?>
    <?php include __DIR__ . '/dashboard_content.php'; ?>
<?php else: ?>
    <?php include __DIR__ . '/landing_content.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/auth_modal.php'; ?>

</body>
</html>
