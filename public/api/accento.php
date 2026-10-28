<?php
/* =====================================================================
   STANDOUT — CAMBIO DEL COLORE DELL'ACCOUNT
   POST { accento: "teal" } con il token CSRF (X-CSRF-Token o csrf_token).
   Solo per chi e' connesso. Risponde con il CSS gia' pronto: il pannello lo
   mette al posto del vecchio, cosi' il colore cambia subito, senza ricaricare
   la pagina (che potrebbe essere a meta' di una partita o di una modifica).
   Vedi lib/accent.php.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/accent.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function accento_esci(int $codice, array $dati): void {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') accento_esci(405, ['ok' => false]);

$dati = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($dati)) $dati = $_POST;

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($dati['csrf_token'] ?? '');
if (!standout_csrf_verifica(is_string($token) ? $token : '')) accento_esci(403, ['ok' => false, 'errore' => 'csrf']);

if (!standout_utente_corrente()) accento_esci(401, ['ok' => false, 'errore' => 'login']);

$accento = is_string($dati['accento'] ?? null) ? $dati['accento'] : '';
if (!standout_accento_valido($accento)) accento_esci(422, ['ok' => false, 'errore' => 'accento']);

[$ok, $copia] = standout_imposta_accento($accento);
if (!$ok) accento_esci(500, ['ok' => false, 'errore' => 'salvataggio']);

accento_esci(200, [
    'ok'      => true,
    'accento' => $accento,
    'copia'   => $copia,                      // false = vale su questo browser, non ancora sugli altri
    'css'     => standout_accento_css($accento),
]);
