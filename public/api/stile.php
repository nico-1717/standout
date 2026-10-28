<?php
/* =====================================================================
   STANDOUT — CAMBIO DI STILE (standard | paper)
   POST { stile: "paper" } con il token CSRF (X-CSRF-Token o csrf_token).
   Solo per chi e' connesso: lo stile vive sul profilo. Vedi lib/stile.php.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/stile.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stile_esci(int $codice, array $dati): void {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') stile_esci(405, ['ok' => false]);

$dati = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($dati)) $dati = $_POST;

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($dati['csrf_token'] ?? '');
if (!standout_csrf_verifica(is_string($token) ? $token : '')) stile_esci(403, ['ok' => false, 'errore' => 'csrf']);

if (!standout_utente_corrente()) stile_esci(401, ['ok' => false, 'errore' => 'login']);

$stile = is_string($dati['stile'] ?? null) ? $dati['stile'] : '';
if (!standout_stile_valido($stile)) stile_esci(422, ['ok' => false, 'errore' => 'stile']);

if (!standout_imposta_stile($stile)) stile_esci(500, ['ok' => false, 'errore' => 'salvataggio']);

stile_esci(200, ['ok' => true, 'stile' => $stile]);
