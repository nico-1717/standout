<?php
/* PROVVISORIO: serve a capire quale indirizzo IP vede il server dietro il proxy
   di Altervista. Mostra solo gli header della richiesta di chi lo apre (niente
   cookie, niente dati altrui). Va tolto subito dopo la prova. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
$chiavi = ['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
           'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTPS', 'REQUEST_SCHEME', 'SERVER_ADDR', 'HTTP_VIA'];
$out = [];
foreach ($chiavi as $k) { $out[$k] = $_SERVER[$k] ?? null; }
echo json_encode($out);
