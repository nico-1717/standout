<?php
/* fc_image_sizes_backfill.php — recupero delle misure delle immagini gia' in archivio.
 *
 * Da qui in avanti le misure le registra chi salva il file (vedi
 * fc_image_sizes.php). Ma le immagini caricate PRIMA della migrazione non
 * hanno nessuna riga, e senza quella riga la carta continua a comportarsi come
 * prima: si tiene il posto solo per quelle che si conoscono.
 * Questo script passa una volta sui file gia' presenti e le misura.
 *
 * SI USA UNA VOLTA SOLA, dal browser, dopo aver eseguito
 * migrazione_misure_immagini.sql. Poi si puo' cancellare.
 *
 * PERCHE' A SCAGLIONI E NON TUTTO IN UNA VOLTA
 * getimagesize() apre un file per volta. Con migliaia di immagini su un
 * hosting condiviso si finisce contro il tempo massimo di esecuzione, e a
 * quel punto non si sa nemmeno a che punto si era arrivati. Facendo blocchi
 * da 200 e ricaricandosi da solo, ogni giro e' breve e il lavoro riprende da
 * dove si era interrotto: le righe gia' scritte vengono saltate, quindi anche
 * rilanciarlo dall'inizio non fa danni.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once 'auth_check.php';

@set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

const FCB_CARTELLE = ['fc_Images', 'fc_images'];  // maiuscole diverse a seconda del sistema
const FCB_BLOCCO   = 200;
const FCB_ESTENSIONI = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

/* Nomi gia' misurati: si chiedono in una volta e si tengono in memoria, cosi'
   il ciclo non fa una query per file. */
$gia = [];
foreach (sec_all("SELECT filename FROM fc_image_meta", []) as $r) {
    $gia[$r['filename']] = true;
}

/* L'elenco dei file. Le due cartelle possibili si fondono: su alcuni sistemi
   il nome e' 'fc_Images', su altri 'fc_images', e leggerle tutte e due evita
   di dover indovinare. */
$tutti = [];
foreach (FCB_CARTELLE as $cartella) {
    $percorso = dirname(__FILE__) . '/' . $cartella;
    if (!is_dir($percorso)) continue;
    foreach (scandir($percorso) ?: [] as $nome) {
        if ($nome === '.' || $nome === '..') continue;
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, FCB_ESTENSIONI, true)) continue;
        if (isset($gia[$nome])) continue;
        $tutti[$nome] = $percorso . '/' . $nome;   // chiave = nome: niente doppioni fra le due cartelle
    }
}

$daFare = count($tutti);
$fatti = 0;
$saltati = 0;

foreach ($tutti as $nome => $percorsoCompleto) {
    if ($fatti + $saltati >= FCB_BLOCCO) break;
    $m = @getimagesize($percorsoCompleto);
    if (!$m || empty($m[0]) || empty($m[1])) { $saltati++; continue; }
    sec_q("REPLACE INTO fc_image_meta (filename, w, h) VALUES (?, ?, ?)",
          [$nome, min(65535, (int)$m[0]), min(65535, (int)$m[1])]);
    $fatti++;
}

$restano = $daFare - $fatti - $saltati;

echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width, initial-scale=1">'
   . '<title>Misure immagini</title>'
   . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;line-height:1.6}'
   . 'code{background:#f2f2f7;padding:2px 6px;border-radius:5px}</style></head><body>';

echo '<h1>Misure delle immagini</h1>';
echo '<p>Misurate in questo giro: <b>' . (int)$fatti . '</b>';
if ($saltati) echo ' — non leggibili come immagine: <b>' . (int)$saltati . '</b>';
echo '</p>';

if ($restano > 0) {
    echo '<p>Ne restano <b>' . (int)$restano . '</b>. La pagina si ricarica da sola fra un attimo.</p>';
    /* Il ricaricamento lo fa un meta refresh e non JavaScript: cosi' funziona
       anche a scheda in secondo piano, dove i timer del browser rallentano. */
    echo '<meta http-equiv="refresh" content="1">';
} else {
    echo '<p><b>Finito.</b> Da qui in avanti le misure le registra chi salva l\'immagine: '
       . 'questo file non serve piu\' e si puo\' cancellare.</p>';
}

echo '</body></html>';
