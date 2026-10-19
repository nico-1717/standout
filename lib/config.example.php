<?php
/* =====================================================================
   STANDOUT — EXAMPLE CONFIGURATION
   Copy this file to lib/config.php and fill in your own values.
   lib/config.php is in .gitignore: real keys and passwords never go to git.
   ===================================================================== */
// Configurazione del database
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASSWORD', 'your_db_password');
define('DB_NAME', 'your_db_name');

// Percorso delle immagini
define('IMAGE_PATH_VIEW', 'https://your-domain.example/media/vocab_images');
/* Cartella dei file caricati dagli utenti: media/ (vocab_images,
   flashcard_images, example_images, flashcard_audio, tutor_audio, uploads).
   Sta nella radice del progetto, accanto a lib/ e public/. Gli indirizzi
   sono /media/...: li fa arrivare qui il .htaccess della radice. */
define('STANDOUT_DATA_DIR', dirname(__DIR__) . '/media/');
define('IMAGE_PATH_INSERT', STANDOUT_DATA_DIR . 'vocab_images/');
define('FC_IMAGE_PATH_VIEW', 'https://your-domain.example/media/flashcard_images');
define('FC_IMAGE_PATH_INSERT', STANDOUT_DATA_DIR . 'flashcard_images/');

// API Keys
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY);

define('GOOGLE_TTS_API_KEY', 'YOUR_GOOGLE_TTS_API_KEY');

define('HUGGINGFACE_API_KEY', 'YOUR_HUGGINGFACE_API_KEY');

define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://your-domain.example/google_calendar.php?action=callback');
define('GC_ENC_KEY',           'YOUR_GC_ENC_KEY');

define('GOOGLE_PLACES_KEY', 'YOUR_GOOGLE_PLACES_KEY');
define('GOOGLE_EMBED_KEY', 'YOUR_GOOGLE_EMBED_KEY');

require_once __DIR__ . '/i18n.php';

/* Le misure delle immagini delle carte. Sta qui, e non nelle singole pagine,
   per lo stesso motivo per cui ci sta i18n: una funzione che serve al momento
   del salvataggio E al momento del disegno, in file diversi, se va inclusa a
   mano prima o poi viene dimenticata proprio dove serve. */
require_once __DIR__ . '/../public/fc/fc_image_sizes.php';

/* ---- PWA ------------------------------------------------------------
   Indirizzo pubblico della cartella del sito. Serve a manifest, icone e
   service worker: sono percorsi assoluti apposta, cosi' valgono uguali da
   qualunque pagina e non si rompono se un giorno le pagine finiranno in
   sottocartelle. Se il sito trasloca, si cambia qui e basta. */
define('PWA_BASE', '/');

require_once __DIR__ . '/../public/partials/pwa_head.php';

/* I meta della PWA vanno dentro <head>, ma config.php gira prima che la
   pagina stampi qualsiasi cosa. Quindi non si stampa niente adesso: si
   raccoglie l'HTML e lo si ritocca alla fine, infilando i meta appena prima
   di </head>. Cosi' le 27 pagine non vanno toccate una per una, comprese
   quelle vecchie che non conviene riaprire.
   Se la risposta non e' una pagina (il JSON degli endpoint, le immagini
   generate) il segnaposto non c'e' e il testo torna indietro intatto. */
ob_start(function ($html) {
    $pos = stripos($html, '</head>');
    if ($pos === false) return $html;

   /* Le pagine hanno <html lang="it"> scritto a mano. Correggerlo qui evita
       27 modifiche identiche, e serve davvero: da quell'attributo dipendono il
       correttore ortografico, la sillabazione e cosa legge un lettore di schermo. */
    $html = preg_replace('/(<html[^>]*\slang=")[^"]*"/i', '${1}' . standout_lingua() . '"', $html, 1);

    /* LA BARRA DEL POMODORO — stessa idea dei meta qui sopra: una striscia in
       cima a ogni pagina mentre un pomodoro sta girando, innestata da qui
       invece che aggiunta a mano a ventisette file.

       pomo_barra_html() restituisce due stringhe e non stampa niente,
       apposta: dentro un gestore di output PHP non permette di aprire un
       altro buffer, quindi un include che stampa qui non funzionerebbe.
       Se non c'e' nessun pomodoro in corso torna vuoto e la pagina non si
       accorge nemmeno che questo pezzo esiste: niente CSS, niente
       JavaScript, nessun controllo periodico. */
    $barra = ['head' => '', 'body' => ''];
    $fileBarra = __DIR__ . '/../public/projects/pomodoro_barra.php';
    if (!defined('STANDOUT_NIENTE_BARRA') && is_readable($fileBarra)) {
        require_once $fileBarra;
        if (function_exists('pomo_barra_html')) $barra = pomo_barra_html();
    }

    $html = substr_replace($html, pwa_head_html() . $barra['head'] . "\n", $pos, 0);

    /* Il markup va in fondo al body: infilarlo subito dopo l'apertura si
       metterebbe in mezzo a quello che le pagine si aspettano di trovarci.
       In alto ci finisce lo stesso, ce lo porta il CSS. */
    if ($barra['body'] !== '') {
        $posBody = strripos($html, '</body>');
        if ($posBody !== false) $html = substr_replace($html, $barra['body'], $posBody, 0);
    }

    return $html;
});