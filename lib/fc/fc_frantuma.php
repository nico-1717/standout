<?php
/* fc_frantuma.php — funzioni pure di "Frantuma" (niente database).
 *
 * Stanno qui, e non dentro gli endpoint, per due motivi: le usano due file
 * (ai_shatter.php e ai_shatter_save.php) e cosi' si possono provare da sole.
 */

if (!function_exists('fc_frantuma_in_testo')) {

/**
 * Il testo di una carta come lo leggerebbe una persona.
 * Nel database una carta puo' essere testo con i tag /bold{} oppure un
 * DOCUMENTO di fc_parser ({"v":2,"blocks":[{"t":"p","runs":[...]}]}). All'IA
 * non serve il JSON: ci si perdono le frasi fra una "chiave" e l'altra, e la
 * sua citazione "parola per parola" non si ritrovava mai nell'originale. Qui
 * il documento diventa testo semplice (formule fra $ $); se non e' un
 * documento, si restituisce com'e'. Si perde il grassetto: pazienza, la
 * fedelta' al contenuto conta piu' della forma.
 */
function fc_frantuma_in_testo(string $s): string {
    $t = ltrim($s);
    if ($t === '' || $t[0] !== '{') return $s;
    $d = json_decode($t, true);
    if (!is_array($d) || !isset($d['blocks']) || !is_array($d['blocks'])) return $s;
    $righe = [];
    foreach ($d['blocks'] as $b) {
        if (is_array($b)) array_push($righe, ...fc_frantuma_nodo($b));
    }
    return trim(implode("\n", $righe));
}

/** Le righe di testo di un blocco del documento (anche annidato: elenchi, ecc.). */
function fc_frantuma_nodo(array $n): array {
    if (($n['t'] ?? '') === 'fx') return ['$$' . (string)($n['tex'] ?? '') . '$$'];
    $righe = [];
    if (isset($n['runs']) && is_array($n['runs'])) {
        $r = '';
        foreach ($n['runs'] as $run) {
            if (is_string($run)) { $r .= $run; continue; }
            if (!is_array($run)) continue;
            $r .= isset($run['x']) ? '$' . (string)$run['x'] . '$' : (string)($run['s'] ?? '');
        }
        $righe[] = $r;
    }
    foreach ($n as $k => $v) {
        if ($k === 'runs' || !is_array($v)) continue;
        if (isset($v['t']) || isset($v['runs'])) {
            array_push($righe, ...fc_frantuma_nodo($v));
        } else {
            foreach ($v as $sub) {
                if (is_array($sub)) array_push($righe, ...fc_frantuma_nodo($sub));
            }
        }
    }
    return $righe;
}

/** Le quattro cose che una carta puo' avere attaccate, e la colonna di ciascuna. */
function fc_frantuma_colonne_allegati(): array {
    return ['qimg' => 'question_image', 'qaud' => 'question_audio', 'aimg' => 'answer_image', 'aaud' => 'answer_audio'];
}

/**
 * Controlla le scelte dell'utente su dove mettere gli allegati della carta
 * originale quando la si SOSTITUISCE.
 *
 *   $presenti  le chiavi degli allegati che la carta ha davvero: 'qimg', 'qaud',
 *              'aimg', 'aaud' e 'ex:<id>' per ogni esempio
 *   $scelte    chiave => destinazione, come arriva dalla pagina
 *   $n         quante mini carte ci sono
 *
 * Destinazioni valide: 'drop' (non tenerlo), un numero da 0 a n-1 (la mini
 * carta), 'title' (la carta titolo: solo per domanda-immagine e domanda-audio,
 * perche' una carta titolo con una risposta, anche solo un audio, smette di
 * essere un titolo; e gli esempi su un titolo non si vedrebbero mai).
 * Ogni allegato presente DEVE avere una scelta: niente scelta, niente
 * sostituzione - e' proprio cio' che l'utente ha chiesto, non decidiamo noi.
 *
 * Restituisce ['ok' => true, 'map' => chiave => 'drop'|'title'|int]
 * oppure ['ok' => false, 'chiave' => la prima chiave con problemi].
 */
function fc_frantuma_assegnazioni(array $presenti, $scelte, int $n): array {
    $scelte = is_array($scelte) ? $scelte : [];
    $solo_domanda = ['qimg', 'qaud'];
    $mappa = [];
    foreach ($presenti as $chiave) {
        if (!array_key_exists($chiave, $scelte)) return ['ok' => false, 'chiave' => $chiave];
        $v = $scelte[$chiave];
        if ($v === 'drop') { $mappa[$chiave] = 'drop'; continue; }
        if ($v === 'title') {
            if (!in_array($chiave, $solo_domanda, true)) return ['ok' => false, 'chiave' => $chiave];
            $mappa[$chiave] = 'title';
            continue;
        }
        if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int)$v >= 0 && (int)$v < $n) {
            $mappa[$chiave] = (int)$v;
            continue;
        }
        return ['ok' => false, 'chiave' => $chiave];
    }
    return ['ok' => true, 'map' => $mappa];
}

}
