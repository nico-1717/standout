<?php
/* fc_settings_api.php — LE SCELTE DI GIOCO DELL'UTENTE (lib/fc/fc_impostazioni.php)
 *
 * GET              le scelte attuali
 * POST significato=aperto|tocco|dopo, esempi=aperto|tocco|dopo (una o tutte e due)
 *
 * Le usa l'ingranaggio del gioco delle lingue (fc_game.php) e l'opzione
 * "indizi gia' aperti" nell'avvio della partita (fc_game_setup.php).
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/fc/fc_impostazioni.php';

$uid = (int)$utente_corrente['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write($uid, 'fc_settings', 0, 60, 60);
    $nuove = [];
    foreach (['significato', 'esempi'] as $k) {
        $v = sec_in($k);
        if ($v !== null && $v !== '') $nuove[$k] = sec_enum($v, FC_INDIZI_MODI, 'aperto');
    }
    sec_json(['ok' => true, 'impostazioni' => fc_impostazioni_salva($uid, $nuove)]);
}

sec_json(['ok' => true, 'impostazioni' => fc_impostazioni($uid)]);
