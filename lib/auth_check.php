<?php
/**
 * Includi questo file in cima a OGNI pagina HTML che richiede il login,
 * SUBITO dopo require_once 'config.php':
 *
 *   require_once 'config.php';
 *   require_once 'auth_check.php';
 *
 * Se l'utente non è autenticato, viene rimandato alla home, che aprirà
 * automaticamente il popup di login. Se invece è autenticato, questo
 * file mette a disposizione la variabile $utente_corrente con
 * ['id' => ..., 'username' => ..., 'email' => ...].
 */

require_once __DIR__ . '/auth_core.php';

$utente_corrente = nisama_utente_corrente();

if (!$utente_corrente) {
    header('Location: index.php?auth=richiesto');
    exit;
}
