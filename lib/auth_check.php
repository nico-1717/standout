<?php
/**
 * Includi questo file in cima a OGNI pagina HTML che richiede il login,
 * SUBITO dopo require_once __DIR__ . '/bootstrap.php':
 *
 *   require_once __DIR__ . '/bootstrap.php';
 *   require_once __DIR__ . '/auth_check.php';
 *
 * Se l'utente non è autenticato, viene rimandato alla home, che aprirà
 * automaticamente il popup di login. Se invece è autenticato, questo
 * file mette a disposizione la variabile $utente_corrente con
 * ['id' => ..., 'username' => ..., 'email' => ...].
 */

require_once __DIR__ . '/auth_core.php';

$utente_corrente = standout_utente_corrente();

if (!$utente_corrente) {
    header('Location: ' . (defined('PWA_BASE') ? PWA_BASE : '../') . 'index.php?auth=richiesto');
    exit;
}

/* Chi non ha ancora accettato i testi attuali (utenti registrati prima delle
   condizioni d'uso, o dopo un loro cambiamento) li vede prima di tutto il resto.
   Le pagine che devono funzionare lo stesso (accettare, scaricare i dati,
   eliminare l'account) definiscono STANDOUT_SENZA_TERMINI prima di includere
   questo file: serve per non chiudere fuori chi vuole andarsene. */
if (!defined('STANDOUT_SENZA_TERMINI')) {
    require_once __DIR__ . '/legale.php';
    if (standout_termini_da_accettare((int)$utente_corrente['id'])) {
        standout_termini_vai((string)($_SERVER['REQUEST_URI'] ?? ''));
    }
}
