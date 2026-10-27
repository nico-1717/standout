<?php
/* Standout - i meta della PWA.
 *
 * Perche' una funzione che RESTITUISCE il testo invece di stamparlo:
 * viene chiamata da dentro il callback di ob_start in config.php, e PHP vieta
 * di usare l'output buffering dentro un callback di output buffering. Con una
 * stringa il problema non si pone, e la stessa funzione resta utilizzabile a
 * mano dove servisse:  <?php echo pwa_head_html(); ?>  dentro <head>.
 *
 * Perche' i percorsi sono assoluti (PWA_BASE, definita in config.php):
 * il service worker deve stare nella radice del sito per avere raggio d'azione
 * su tutte le pagine, e le pagine oggi sono piatte ma un giorno saranno in
 * sottocartelle. Con i percorsi relativi funzionerebbe oggi e si romperebbe in
 * silenzio dopo il trasloco - il tipo di guasto che non da' errori: l'app
 * semplicemente smette di essere installabile e non se ne accorge nessuno.
 */

if (!function_exists('pwa_head_html')) {

    function pwa_head_html(): string
    {
        // se qualcuno include questo file senza config.php, meglio un percorso
        // sbagliato ma prevedibile che un errore fatale a meta' pagina
        $base = defined('PWA_BASE') ? PWA_BASE : '/';

        /* Il nonce della CSP, per gli script/stili che una pagina crea da JavaScript
           (es. fc_parser.js). I template PHP usano sec_nonce() direttamente.
           standout_events.js fa funzionare i data-on-click="..." che hanno preso il
           posto degli onclick="..." (vedi quel file): sta qui, in ogni <head>,
           perche' ogni pagina passa da questa funzione. Senza defer: deve essere
           pronto prima che la pagina si costruisca, cosi' lega i pulsanti man mano. */
        $nonce = function_exists('sec_nonce') ? sec_nonce() : '';
        $ver = @filemtime(__DIR__ . '/../_assets/js/standout_events.js') ?: 1;

        return ''
            . ($nonce !== '' ? '<meta name="csp-nonce" content="' . $nonce . '">' . "\n" : '')
            . '<script src="' . $base . '_assets/js/standout_events.js?v=' . $ver . '"></script>' . "\n"
            . '<link rel="manifest" href="' . $base . 'manifest.webmanifest">' . "\n"
            . '<meta name="theme-color" content="#EFE0AE">' . "\n"

            /* iOS non legge il manifest per tutto: nome sotto l'icona, icona e
               barra di stato li vuole da questi meta, altrimenti mette una
               miniatura della pagina e il testo del <title>. */
            . '<link rel="apple-touch-icon" href="' . $base . '_assets/icons/apple-touch-icon-180.png">' . "\n"
            /* apple-mobile-web-app-capable e' deprecato ma serve ancora ai Safari
               piu' vecchi; mobile-web-app-capable e' quello standard di oggi.
               Si tengono tutti e due finche' i vecchi iOS sono in giro. */
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="Standout">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"

            /* Serve solo alla fascetta "installa" su iOS: e' minuscolo, e
               tenerlo qui evita stili scritti dentro pwa.js. */
            . '<link rel="stylesheet" href="' . $base . '_assets/pwa.css">' . "\n"

            /* defer: la registrazione non deve rallentare il disegno della
               pagina. L'indirizzo di sw.js viaggia in un attributo invece che
               scritto dentro pwa.js, cosi' PWA_BASE resta l'unico posto da
               cambiare il giorno del trasloco. */
            . '<script src="' . $base . '_assets/pwa.js" data-sw="' . $base . 'sw.js" defer></script>' . "\n";
    }
}
