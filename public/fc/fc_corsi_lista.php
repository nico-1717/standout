<?php
/* fc_corsi_lista.php — I CORSI DI LINGUA DELL'UTENTE, IN JSON
 *
 * Lo chiede la scorciatoia "Inserisci" della sidebar (partials/sidebar.html):
 * scelto "Vocabolario", se le lingue sono piu' d'una bisogna dire in quale
 * inserire. Si legge solo al tocco, non a ogni pagina: la sidebar c'e' su
 * tutto il sito, e la lista serve di rado.
 *
 * Solo lettura, solo i corsi dell'utente connesso.
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/fc/fc_type.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int)$utente_corrente['id'];
$corsi = [];
foreach (sec_all("SELECT id, known_lang, learn_lang
                    FROM fc_vocab_courses WHERE user_id = ?
                   ORDER BY custom_order ASC, created_at ASC, id ASC", [$uid], 'i') as $r) {
    $corsi[] = [
        'id'       => (int)$r['id'],
        'nome'     => fc_lingua_nome((string)$r['learn_lang']),
        'da'       => fc_lingua_nome((string)$r['known_lang'], false),
        'bandiera' => fc_lingua_bandiera((string)$r['learn_lang']),
    ];
}
echo json_encode(['ok' => true, 'corsi' => $corsi], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
