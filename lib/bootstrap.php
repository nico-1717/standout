<?php
/* =====================================================================
   STANDOUT — bootstrap: l'unico punto d'ingresso comune (PROGETTO §4, §7.1).

   Ogni pagina e ogni endpoint comincia con
       require_once __DIR__ . '/../../lib/bootstrap.php';
   (il numero di ../ dipende dalla cartella) e NON include piu' config.php per
   conto suo. Il prossimo spostamento di cartelle costera' una riga qui invece di
   cento righe sparse. tools/controlla_bootstrap.py fallisce se una pagina
   include ancora config.php direttamente.

   COSA FA, in ordine:
     1. definisce STANDOUT_LIB (la cartella lib/);
     2. carica config.php: i valori che cambiano da un ambiente all'altro
        (database, chiavi, PWA_BASE) e che NON vanno su GitHub;
     3. carica la lingua, le misure delle immagini e i meta della PWA, e
        registra il ritocco finale della pagina (meta PWA, barra del pomodoro).

   WHY il passo 3 sta qui e non in config.php: config.php non e' nel
   repository, quindi ogni modifica a quel codice comune richiedeva di
   ricordarsi di ricopiarla a mano sul server. Cosi' viaggia con il deploy.

   COMPATIBILITA': il config.php che sta oggi sul server e' quello vecchio, che
   il passo 3 lo fa da solo. Se lo ha gia' fatto (esiste pwa_head_html) lo
   si salta, altrimenti il ritocco verrebbe applicato due volte. Il giorno che
   si ricopia config.example.php sul server la parte vecchia sparisce da sola.
   ===================================================================== */

if (!defined('STANDOUT_LIB')) define('STANDOUT_LIB', __DIR__);

require_once __DIR__ . '/config.php';

if (function_exists('pwa_head_html')) {
    return; // config.php vecchio: ha gia' fatto tutto il passo 3
}

require_once __DIR__ . '/i18n.php';

/* Le misure delle immagini delle carte. Sta qui, e non nelle singole pagine,
   per lo stesso motivo per cui ci sta i18n: una funzione che serve al momento
   del salvataggio E al momento del disegno, in file diversi, se va inclusa a
   mano prima o poi viene dimenticata proprio dove serve. */
require_once __DIR__ . '/../public/fc/fc_image_sizes.php';


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