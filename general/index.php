<?php
require_once 'config.php';
require_once 'auth_core.php';

$utente_corrente = nisama_utente_corrente();
$pagina_titolo = $utente_corrente ? 'Nisama — La tua home' : 'Nisama — Impara ricordando davvero';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($pagina_titolo, ENT_QUOTES); ?></title>
<meta name="description" content="Nisama: flashcard con tutor IA, audio e statistiche, per studiare l'inglese e qualunque materia con lo studio attivo invece che passivo.">
<meta name="theme-color" content="#12131A">
<style>
  html, body { margin: 0; padding: 0; background: #12131A; }
  * { -webkit-tap-highlight-color: transparent; }
</style>
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
