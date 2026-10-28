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
/* Senza la chiave in coda: viaggia nell'intestazione (vedi sec_gemini_url). Se la
   riga ha ancora "?key=..." funziona lo stesso, la chiave viene tolta. */
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');

define('GOOGLE_TTS_API_KEY', 'YOUR_GOOGLE_TTS_API_KEY');

define('HUGGINGFACE_API_KEY', 'YOUR_HUGGINGFACE_API_KEY');

define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://your-domain.example/google_calendar.php?action=callback');
define('GC_ENC_KEY',           'YOUR_GC_ENC_KEY');

define('GOOGLE_PLACES_KEY', 'YOUR_GOOGLE_PLACES_KEY');
define('GOOGLE_EMBED_KEY', 'YOUR_GOOGLE_EMBED_KEY');

/* ---- PWA ------------------------------------------------------------
   Indirizzo pubblico della cartella del sito. Serve a manifest, icone e
   service worker: sono percorsi assoluti apposta, cosi' valgono uguali da
   qualunque pagina e non si rompono se un giorno le pagine finiranno in
   sottocartelle. Se il sito trasloca, si cambia qui e basta. */
define('PWA_BASE', '/');
