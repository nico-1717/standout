<?php
/* =====================================================================
   STANDOUT — privacy e condizioni d'uso: chi, quale versione, quali testi.

   PERCHE' UN FILE A PARTE. I testi legali devono dire la verita' su quello
   che il sito fa DAVVERO (quali servizi esterni riceve, quanto si conserva
   un dato). Tenerli qui, vicino al codice, e non in una pagina qualsiasi,
   serve a ricordarsi di aggiornarli quando il codice cambia: se si aggiunge un
   servizio esterno o una tabella con dati personali, va cambiato anche
   lib/legale/privacy_*.php (e STANDOUT_LEGALE_VERSIONE).

   Il titolare e il contatto stanno qui (non in config.php) perche' sono scritti
   in chiaro nella pagina pubblica: non sono segreti.
   ===================================================================== */

/* La data della versione dei testi. Si salva accanto all'accettazione
   (users.terms_version): cosi' si sa QUALE testo ha accettato ognuno. Si cambia
   solo quando il contenuto cambia in modo che conta. */
const STANDOUT_LEGALE_VERSIONE = '2026-10-27';

/* Chi risponde dei dati e come lo si contatta. DA COMPLETARE prima di aprire
   la registrazione al pubblico: un indirizzo vero, letto da una persona. */
const STANDOUT_LEGALE_TITOLARE = 'DA COMPLETARE';
const STANDOUT_LEGALE_EMAIL    = 'da-completare@example.invalid';

/** Vero se titolare e contatto sono ancora quelli segnaposto. */
function standout_legale_incompleto(): bool {
    return STANDOUT_LEGALE_TITOLARE === 'DA COMPLETARE' || substr(STANDOUT_LEGALE_EMAIL, -8) === '.invalid';
}

/**
 * Le sezioni di un testo, nella lingua data (ripiego: italiano).
 * Ogni sezione: ['h' => titolo, 'b' => [blocchi]]; un blocco e' una stringa
 * (paragrafo) o un array di stringhe (elenco puntato). I segnaposto
 * {TITOLARE}, {EMAIL}, {VERSIONE} si riempiono qui.
 *
 * @param string $doc  'privacy' | 'termini'
 */
function standout_legale_sezioni(string $doc, string $lingua): array {
    $doc = $doc === 'termini' ? 'termini' : 'privacy';
    $lingua = $lingua === 'en' ? 'en' : 'it';
    $sezioni = require __DIR__ . '/legale/' . $doc . '_' . $lingua . '.php';
    $sost = [
        '{TITOLARE}' => STANDOUT_LEGALE_TITOLARE,
        '{EMAIL}'    => STANDOUT_LEGALE_EMAIL,
        '{VERSIONE}' => STANDOUT_LEGALE_VERSIONE,
    ];
    $riempi = function ($v) use (&$riempi, $sost) {
        if (is_array($v)) return array_map($riempi, $v);
        return strtr((string)$v, $sost);
    };
    return $riempi($sezioni);
}

/**
 * Scrive una pagina pubblica completa (privacy o condizioni). Non richiede
 * l'accesso: le deve poter leggere chi sta per registrarsi.
 */
function standout_legale_pagina(string $doc): void {
    $doc = $doc === 'termini' ? 'termini' : 'privacy';
    $lingua = standout_lingua();
    $sezioni = standout_legale_sezioni($doc, $lingua);
    $titolo = t('legale.' . ($doc === 'privacy' ? 'titolo_privacy' : 'titolo_termini'));
    $css = PWA_BASE . 'legal/legale.css?v=' . (@filemtime(__DIR__ . '/../public/legal/legale.css') ?: 1);
    $altro = $doc === 'privacy' ? 'termini' : 'privacy';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="<?php echo $h($lingua); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $h($titolo); ?> · Standout</title>
<link rel="stylesheet" href="<?php echo $h($css); ?>">
</head>
<body>
<main class="lg">
  <nav class="lg-nav">
    <a href="<?php echo $h(PWA_BASE); ?>"><?php echo $h(t('legale.torna')); ?></a>
    <span class="lg-nav-r">
      <a href="<?php echo $h($altro . '.php'); ?>"><?php echo $h(t('legale.titolo_' . $altro)); ?></a>
      <a href="?lang=it"<?php echo $lingua === 'it' ? ' aria-current="true"' : ''; ?>>Italiano</a>
      <a href="?lang=en"<?php echo $lingua === 'en' ? ' aria-current="true"' : ''; ?>>English</a>
    </span>
  </nav>
  <h1><?php echo $h($titolo); ?></h1>
  <p class="lg-ver"><?php echo $h(t('legale.versione', ['v' => STANDOUT_LEGALE_VERSIONE])); ?></p>
<?php foreach ($sezioni as $i => $s): ?>
  <section>
    <h2><?php echo ($i + 1) . '. ' . $h($s['h']); ?></h2>
<?php foreach ($s['b'] as $blocco): ?>
<?php if (is_array($blocco)): ?>
    <ul>
<?php foreach ($blocco as $voce): ?>
      <li><?php echo $h($voce); ?></li>
<?php endforeach; ?>
    </ul>
<?php else: ?>
    <p><?php echo $h($blocco); ?></p>
<?php endif; ?>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>
</main>
</body>
</html>
<?php
}
