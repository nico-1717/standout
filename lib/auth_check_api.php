<?php
/**
 * Includi questo file in cima a OGNI endpoint AJAX/JSON che richiede il
 * login, SUBITO dopo require_once __DIR__ . '/config.php':
 *
 *   require_once __DIR__ . '/config.php';
 *   require_once __DIR__ . '/auth_check_api.php';
 *
 * A differenza di auth_check.php, qui NON facciamo un redirect (avrebbe
 * poco senso per una chiamata fetch/AJAX): rispondiamo con HTTP 401 e un
 * JSON che il Javascript può intercettare per riaprire il popup di login.
 * Se autenticato, mette a disposizione $utente_corrente come sopra.
 */

require_once __DIR__ . '/auth_core.php';

$utente_corrente = standout_utente_corrente();

if (!$utente_corrente) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'not_authenticated', 'message' => t('api.devi_login')]);
    exit;
}
