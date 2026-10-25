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
 * Anche cosa LEGGE la modalita' vocale nelle parole (voice_read, 4 cifre):
 *   domanda 0/1 · risposta 1/2 volte (la seconda piu' lenta) ·
 *   significato 0/1 · esempi 0 nessuno / 1 uno / 2 tutti.
 *
 * La tabella (fc_settings) arriva con la migrazione del 25/10/2026 e la
 * colonna voice_read con quella della modalita' vocale: finche' non ci sono,
 * valgono i valori di partenza e il salvataggio non fa niente.
 */

if (!function_exists('fc_impostazioni')) {

define('FC_INDIZI_MODI', ['aperto', 'tocco', 'dopo']);

function fc_impostazioni_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) $c_e = !empty(sec_one("SHOW TABLES LIKE 'fc_settings'"));
    return $c_e;
}

define('FC_LETTURA_PREDEFINITA', '1100');   // domanda si', risposta una volta, niente significato ne' esempi

function fc_lettura_valida($v): bool {
    return is_string($v) && preg_match('/^[01][12][01][012]$/', $v) === 1;
}

/** La colonna voice_read c'e'? (la migrazione puo' non essere ancora passata) */
function fc_lettura_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) $c_e = fc_impostazioni_c_e() && !empty(sec_one("SHOW COLUMNS FROM fc_settings LIKE 'voice_read'"));
    return $c_e;
}

/** ['significato' => modo, 'esempi' => modo, 'lettura' => '1100'] */
function fc_impostazioni(int $uid): array {
    $out = ['significato' => 'aperto', 'esempi' => 'aperto', 'lettura' => FC_LETTURA_PREDEFINITA];
    if ($uid <= 0 || !fc_impostazioni_c_e()) return $out;
    $voce = fc_lettura_c_e() ? ', voice_read' : ", NULL AS voice_read";
    $r = sec_one("SELECT vocab_meaning, vocab_examples$voce FROM fc_settings WHERE user_id = ?", [$uid]);
    if ($r) {
        $out['significato'] = in_array($r['vocab_meaning'], FC_INDIZI_MODI, true) ? $r['vocab_meaning'] : 'aperto';
        $out['esempi'] = in_array($r['vocab_examples'], FC_INDIZI_MODI, true) ? $r['vocab_examples'] : 'aperto';
        if (fc_lettura_valida($r['voice_read'])) $out['lettura'] = $r['voice_read'];
    }
    return $out;
}

/** Salva solo le chiavi che arrivano; le altre restano come sono. */
function fc_impostazioni_salva(int $uid, array $nuove): array {
    $att = fc_impostazioni($uid);
    foreach (['significato', 'esempi'] as $k) {
        if (isset($nuove[$k]) && in_array($nuove[$k], FC_INDIZI_MODI, true)) $att[$k] = $nuove[$k];
    }
    if (isset($nuove['lettura']) && fc_lettura_valida($nuove['lettura'])) $att['lettura'] = $nuove['lettura'];
    if ($uid > 0 && fc_impostazioni_c_e()) {
        sec_q("INSERT INTO fc_settings (user_id, vocab_meaning, vocab_examples) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE vocab_meaning = VALUES(vocab_meaning), vocab_examples = VALUES(vocab_examples)",
              [$uid, $att['significato'], $att['esempi']]);
        if (fc_lettura_c_e()) {
            sec_q("UPDATE fc_settings SET voice_read = ? WHERE user_id = ?", [$att['lettura'], $uid]);
        }
    }
    return $att;
}

}
