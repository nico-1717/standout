<?php
/* =====================================================================
   STANDOUT — PLANNER: IL JAVASCRIPT, IN UN FILE SOLO
   Il motore del planner e' una funzione unica (tutte le parti condividono
   lo stesso stato, S, e le stesse funzioni interne), ma 12.000 righe in un
   file non si leggono. Cosi' il sorgente sta in planner/js/, diviso per
   argomento in 13 parti numerate, e questo file le unisce nell'ordine dei
   numeri e le manda al browser come un file solo:

     01_stato_e_server        apre la funzione: stato, utility, server
     02_fascia_alta           cielo, palette, barra in alto
     03_meteo                 meteo, grafico, cambio del luogo
     04_barra_e_priorita      barra intelligente, priorita'
     05_griglia_ed_eventi     griglia degli orari, eventi
     06_liste_studio_pastiglie liste, da studiare, pastiglie, abitudini
     07_navigazione           scroll, giorno a fuoco, zoom
     08_creazione             creare eventi col tocco
     09_popup_evento          il popup dell'evento
     10_tastini_ricerca_impostazioni
     11_google_calendar
     12_viste                 mese, settimana a tabella, elenco
     13_avvio                 avvio e notifiche: chiude la funzione

   Nessun passaggio di compilazione: si modifica la parte giusta e basta.
   Le singole parti NON sono JavaScript valido da sole (si apre in 01 e si
   chiude in 13): per controllare la sintassi c'e' tools/controlla_planner_js.py.

   Cache: planner.php chiama questo indirizzo con ?v=<ultima modifica>,
   quindi il browser puo' tenerlo per un anno e lo riscarica solo quando
   una parte cambia.
   ===================================================================== */
$parti = glob(__DIR__ . '/js/[0-9][0-9]_*.js') ?: [];
sort($parti, SORT_STRING);

$ultima = 0;
foreach ($parti as $p) $ultima = max($ultima, (int)filemtime($p));
$etag = '"pl-' . $ultima . '-' . count($parti) . '"';

header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $ultima) . ' GMT');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

foreach ($parti as $p) {
    readfile($p);
    echo "\n";
}
