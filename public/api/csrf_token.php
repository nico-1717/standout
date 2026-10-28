<?php
/* =====================================================================
   STANDOUT — TOKEN DI SESSIONE
   Restituisce il token CSRF valido in questo momento.

   Serve perché su hosting condiviso le sessioni PHP possono essere
   ripulite dal server prima del previsto: la pagina resta aperta con un
   token ormai vecchio e la prima scrittura viene rifiutata con
   "sessione scaduta", anche se l'utente è ancora perfettamente loggato
   (il cookie "ricordami" lo rimette dentro con una sessione nuova).
   standout_fetch.js chiama questo indirizzo, prende il token buono e
   rimanda la richiesta da sé, senza che l'utente si accorga di niente.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

sec_json(['ok' => true, 'token' => sec_csrf_token()]);
