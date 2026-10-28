<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/auth_core.php';

$utente_corrente = standout_utente_corrente();
/* Chi non ha ancora accettato le condizioni d'uso le vede prima della home. */
if ($utente_corrente) {
    require_once __DIR__ . '/../lib/legale.php';
    if (standout_termini_da_accettare((int)$utente_corrente['id'])) standout_termini_vai();
}
/* Dice al service worker che questa e' la home di chi e' connesso (uno scheletro
   senza dati): senza rete la puo' riaprire dalla copia. Vedi sw.js, regola 1. */
if ($utente_corrente) header('X-Standout-Shell: home');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php te($utente_corrente ? 'index.titolo_home' : 'index.titolo_landing'); ?></title>
<meta name="description" content="<?php te('index.descrizione'); ?>">
<meta name="theme-color" content="<?php echo $utente_corrente ? '#12131A' : '#0E0F14'; ?>">
<?php
/* I fogli stanno qui e non dentro i file inclusi: quelli finiscono nel <body>,
   e un <link> nel corpo funziona ma fa sfarfallare il disegno mentre il foglio
   arriva. Si carica solo quello che serve: chi non e' connesso non deve
   scaricare lo stile della dashboard, e viceversa. */
?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>home/index.css?v=<?php echo @filemtime(__DIR__ . '/home/index.css') ?: 1; ?>">
<?php if ($utente_corrente): ?>
<?php
/* Il token delle scritture: la home spunta attivita', abitudini e pastiglie e
   salva la nota, e il server rifiuta ogni scrittura senza token. Lo aggiunge
   da solo standout_fetch.js a ogni fetch(). */
?>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="<?php echo PWA_BASE; ?>_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/_assets/js/standout_fetch.js') ?: 1; ?>"></script>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>home/dashboard.css?v=<?php echo @filemtime(__DIR__ . '/home/dashboard.css') ?: 1; ?>">
<?php else: ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>home/landing.css?v=<?php echo @filemtime(__DIR__ . '/home/landing.css') ?: 1; ?>">
<?php
/* I caratteri della pagina senza bloccare il disegno: con media="print" il
   foglio si scarica in disparte e diventa "all" quando e' arrivato. Intanto
   si legge con il carattere di sistema. Prima era un <link> normale in mezzo
   al body, e il pulsante Accedi aspettava lui. */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700;800&display=swap" media="print" data-on-load="this.media='all'">
<script nonce="<?php echo sec_nonce(); ?>">document.documentElement.classList.add('lp-js');</script>
<?php endif; ?>
</head>
<body>

<?php if ($utente_corrente): ?>
    <?php include __DIR__ . '/home/dashboard_content.php'; ?>
    <?php include __DIR__ . '/../lib/auth_modal.php'; ?>
<?php else: ?>
    <?php
    /* Il popup di accesso PRIMA della pagina: cosi' il pulsante «Accedi» e il
       suo script arrivano con il primo pezzo di HTML, invece di aspettare che
       tutto il resto sia stato letto. */
    include __DIR__ . '/../lib/auth_modal.php'; ?>
    <?php include __DIR__ . '/home/landing_content.php'; ?>
<?php endif; ?>

</body>
</html>
