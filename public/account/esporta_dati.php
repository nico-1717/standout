<?php
/* "Scarica i tuoi dati": un file ZIP con tutto quello che l'utente ha inserito.
   Si arriva da un modulo (POST con token) e si risponde con il file. */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/account_dati.php';

$uid = (int)$utente_corrente['id'];

/* Un POST con token (come ogni scrittura) e un limite stretto: preparare lo
   ZIP e' un lavoro pesante, e nessuno ne ha bisogno piu' di qualche volta. */
sec_guard_write($uid, 'account_esporta', 0, 5, 3600);

try {
    $zip = account_esporta($uid);
} catch (Throwable $e) {
    sec_log_error('account_esporta', $e->getMessage());
    sec_die_json(t('privacydati.err_generico'), 500, 'server');
}
sec_audit('account_esporta', 'ok', null);

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="standout-i-miei-dati-' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zip));
header('Cache-Control: no-store');
readfile($zip);
@unlink($zip);
