<?php
/* =========================================================================
   STANDOUT · REINVIO DELL'EMAIL DI VERIFICA (POST, serve essere collegati)
   Risposta: {ok, stato} con stato = inviata | gia_verificata | attendi | errore
   ========================================================================= */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/email_verifica.php';

$uid = (int)$utente_corrente['id'];
sec_solo_post_se_scrive('invia', []);
sec_guard_write($uid, 'invia_verifica', 0, 10, 3600);

$stato = ev_invia($uid);
sec_json(['ok' => in_array($stato, ['inviata', 'gia_verificata'], true), 'stato' => $stato]);
