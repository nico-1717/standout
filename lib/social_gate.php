<?php
/* =========================================================================
   STANDOUT · INTERRUTTORE DELLA PARTE SOCIAL SUL SITO

   Decisione (STANDOUT_SOCIAL.md §2): sul sito la parte social non c'e'; vive
   nell'app. Le pagine di public/social/ pero' restano nel repository perche'
   sono la base da cui nasce il social dell'app: qui sono solo SPENTE.

   - Spenta (come oggi): friends.php e friends_api.php rispondono 404, e nel
     sito sparisce ogni accesso: voce della barra laterale, scorciatoia della
     home, richieste di amicizia nel pallino delle notifiche, controllo
     periodico delle richieste.
   - Per riaccenderla, per prova o in locale: in lib/config.php
       define('STANDOUT_SOCIAL_SITO', true);
     Senza quella riga (o con false) resta spenta: e' il valore sicuro.

   Perche' un interruttore e non cancellare i file: cancellarli e ricrearli
   dopo costa tempo e rischia di perdere correzioni (come e' gia' successo con
   la CSP); spenti sono pero' irraggiungibili, e una pagina che non si apre non
   si attacca.
   ========================================================================= */

function standout_social_attivo(): bool {
    return defined('STANDOUT_SOCIAL_SITO') && STANDOUT_SOCIAL_SITO === true;
}

/** Da mettere in cima a ogni pagina o endpoint social: se e' spento, 404 e basta. */
function standout_social_chiudi(): void {
    if (standout_social_attivo()) return;
    http_response_code(404);
    $json = (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
         || !empty($_SERVER['HTTP_X_STANDOUT']);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"errore":"non_disponibile"}';
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404</title></head><body></body></html>';
    }
    exit;
}
