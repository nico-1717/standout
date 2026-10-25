<?php
/* fc_impostazioni.php — LE SCELTE DI GIOCO DI UN UTENTE, SALVATE SUL SERVER
 *
 * Per ora: come si vedono il significato e gli esempi nel gioco delle lingue,
 * prima della risposta:
 *   aperto  sulla carta da subito
 *   tocco   coperti da un'etichetta, si toccano per vederli
 *   dopo    nascosti finche' non si vede la risposta
 * La prima volta entrambi aperti.
 *
 * Prima stavano nel localStorage del browser: cambiando telefono, o dal
 * computer, si ricominciava da capo. Ora seguono l'utente. La pagina li
 * scrive come classi su <html> (fcv-sig-*, fcv-es-*) gia' nel primo HTML,
 * cosi' non si vedono coperti e poi aprirsi.
 *
 * La tabella (fc_settings) arriva con la migrazione del 25/10/2026: finche'
 * non c'e', valgono i valori di partenza e il salvataggio non fa niente.
 */

if (!function_exists('fc_impostazioni')) {

define('FC_INDIZI_MODI', ['aperto', 'tocco', 'dopo']);

function fc_impostazioni_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) $c_e = !empty(sec_one("SHOW TABLES LIKE 'fc_settings'"));
    return $c_e;
}

/** ['significato' => modo, 'esempi' => modo] */
function fc_impostazioni(int $uid): array {
    $out = ['significato' => 'aperto', 'esempi' => 'aperto'];
    if ($uid <= 0 || !fc_impostazioni_c_e()) return $out;
    $r = sec_one("SELECT vocab_meaning, vocab_examples FROM fc_settings WHERE user_id = ?", [$uid]);
    if ($r) {
        $out['significato'] = in_array($r['vocab_meaning'], FC_INDIZI_MODI, true) ? $r['vocab_meaning'] : 'aperto';
        $out['esempi'] = in_array($r['vocab_examples'], FC_INDIZI_MODI, true) ? $r['vocab_examples'] : 'aperto';
    }
    return $out;
}

/** Salva solo le chiavi che arrivano; le altre restano come sono. */
function fc_impostazioni_salva(int $uid, array $nuove): array {
    $att = fc_impostazioni($uid);
    foreach (['significato', 'esempi'] as $k) {
        if (isset($nuove[$k]) && in_array($nuove[$k], FC_INDIZI_MODI, true)) $att[$k] = $nuove[$k];
    }
    if ($uid > 0 && fc_impostazioni_c_e()) {
        sec_q("INSERT INTO fc_settings (user_id, vocab_meaning, vocab_examples) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE vocab_meaning = VALUES(vocab_meaning), vocab_examples = VALUES(vocab_examples)",
              [$uid, $att['significato'], $att['esempi']]);
    }
    return $att;
}

}
