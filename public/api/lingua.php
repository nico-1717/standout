<?php
/* =====================================================================
   STANDOUT — CAMBIO DI LINGUA
   POST { lingua: "en", csrf_token: "..." }  (o il token in X-CSRF-Token)

   - Connesso: la lingua si salva su users.lang, in sessione e nel cookie,
     quindi vale da subito su ogni pagina e su ogni dispositivo.
   - Non connesso (pagina d'ingresso): sessione e cookie. Al login, se il
     profilo non ha ancora una lingua, prende questa
     (standout_lingua_dopo_login in i18n.php).

   POST e token apposta: e' una scrittura sul profilo, e una GET la
   potrebbe far partire un collegamento preparato da qualcun altro.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lingua_esci(int $codice, array $dati): void {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lingua_esci(405, ['ok' => false]);
}

$dati = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($dati)) $dati = $_POST;

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($dati['csrf_token'] ?? '');
if (!standout_csrf_verifica(is_string($token) ? $token : '')) {
    lingua_esci(403, ['ok' => false, 'errore' => 'csrf']);
}

$lingua = is_string($dati['lingua'] ?? null) ? $dati['lingua'] : '';
if (!standout_lingua_valida($lingua)) {
    lingua_esci(422, ['ok' => false, 'errore' => 'lingua']);
}

$utente = standout_utente_corrente();
standout_imposta_lingua($lingua, $utente !== null);

lingua_esci(200, ['ok' => true, 'lingua' => $lingua]);
