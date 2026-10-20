<?php
/* fc_vocab_gap.php — LA PARTE NASCOSTA DI UNA FRASE D'ESEMPIO (solo lingue)
 *
 * Nel gioco, quando la parola da dire sta dietro, nella frase d'esempio
 * diventa lo spazio da indovinare. Quale pezzo di frase sparisce si decide qui,
 * in quest'ordine:
 *
 *   1. 'scelta'   — il pezzo scelto a mano nel modulo (fc_vocab_examples.gap_text),
 *                   se nella frase c'e' ancora;
 *   2. 'trattini' — la vecchia fila di _ degli esempi importati, dove la parola
 *                   era gia' stata tolta dal testo;
 *   3. 'parola'   — la parola della carta, se nella frase compare scritta
 *                   uguale (maiuscole a parte) e come parola intera: "know" in
 *                   "I don't know" si', in "knowledge" no.
 *
 * Altrimenti niente: la frase si mostra intera e nel modulo compare il tasto
 * per scegliere a mano. Lo stesso identico ordine sta in fcvLacuna()
 * (fc_examples_modal.js): se cambia qui, va cambiato anche li'.
 *
 * Le posizioni sono in CARATTERI (mb_*), non in byte.
 */

if (!function_exists('fcv_lacuna')) {

/** Apostrofi tipografici come quello dritto: "don’t" e "don't" sono la stessa parola. */
function fcv_lacuna_norm(string $s): string {
    return str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $s);
}

/** Vero se il carattere e' una lettera, una cifra o un accento combinato. */
function fcv_lacuna_lettera(string $ch): bool {
    return $ch !== '' && preg_match('/^[\p{L}\p{N}\p{M}]$/u', $ch) === 1;
}

/**
 * La prima volta che $ago compare in $testo come pezzo intero (senza lettere
 * attaccate prima o dopo), senza badare alle maiuscole. Se non c'e' cosi',
 * con $anche_dentro si accetta anche dentro una parola.
 * @return int|null posizione in caratteri
 */
function fcv_lacuna_trova(string $testo, string $ago, bool $anche_dentro = false): ?int {
    if ($ago === '') return null;
    $t = fcv_lacuna_norm($testo);
    $a = fcv_lacuna_norm($ago);
    $tl = mb_strtolower($t);
    $al = mb_strtolower($a);
    /* mb_strtolower puo' cambiare la lunghezza di qualche carattere raro: in
       quel caso le posizioni non tornerebbero, e si cerca senza abbassare. */
    if (mb_strlen($tl) !== mb_strlen($t) || mb_strlen($al) !== mb_strlen($a)) { $tl = $t; $al = $a; }
    $len = mb_strlen($al);
    $primo = null;
    $da = 0;
    while (($p = mb_strpos($tl, $al, $da)) !== false) {
        if ($primo === null) $primo = $p;
        $prima = $p > 0 ? mb_substr($tl, $p - 1, 1) : '';
        $dopo  = mb_substr($tl, $p + $len, 1);
        $bordoA = !fcv_lacuna_lettera(mb_substr($al, 0, 1)) || !fcv_lacuna_lettera($prima);
        $bordoB = !fcv_lacuna_lettera(mb_substr($al, -1)) || !fcv_lacuna_lettera($dopo);
        if ($bordoA && $bordoB) return $p;
        $da = $p + 1;
    }
    return $anche_dentro ? $primo : null;
}

/**
 * @return array{da:int,a:int,tipo:string}|null  da incluso, a escluso
 */
function fcv_lacuna(string $testo, string $parola, ?string $scelta = null): ?array {
    if (trim($testo) === '') return null;
    $scelta = trim((string)$scelta);
    if ($scelta !== '') {
        $p = fcv_lacuna_trova($testo, $scelta, true);
        if ($p !== null) return ['da' => $p, 'a' => $p + mb_strlen($scelta), 'tipo' => 'scelta'];
    }
    if (preg_match('/_+/u', $testo, $m, PREG_OFFSET_CAPTURE)) {
        $p = mb_strlen(substr($testo, 0, $m[0][1]));
        return ['da' => $p, 'a' => $p + mb_strlen($m[0][0]), 'tipo' => 'trattini'];
    }
    $parola = trim($parola);
    if ($parola !== '') {
        $p = fcv_lacuna_trova($testo, $parola, false);
        if ($p !== null) return ['da' => $p, 'a' => $p + mb_strlen($parola), 'tipo' => 'parola'];
    }
    return null;
}

/**
 * La frase in HTML con la parte nascosta trattata come serve:
 *   $nascondi = true  → diventa lo spazio da indovinare (la parola e' dietro)
 *   $nascondi = false → resta, in grassetto (la parola e' davanti)
 * Una vecchia fila di _ rimasta altrove nella frase diventa comunque uno spazio.
 */
function fcv_lacuna_html(string $testo, string $parola, ?string $scelta, bool $nascondi): string {
    $buco = '<span class="fcv-gap">_____</span>';
    $resto = function (string $s) use ($buco): string {
        return preg_replace('/_+/', $buco, sec_h($s)) ?? sec_h($s);
    };
    $l = fcv_lacuna($testo, $parola, $scelta);
    if ($l === null) return $resto($testo);
    $prima  = mb_substr($testo, 0, $l['da']);
    $dentro = mb_substr($testo, $l['da'], $l['a'] - $l['da']);
    $dopo   = mb_substr($testo, $l['a']);
    if ($nascondi) {
        $mezzo = $buco;
    } else {
        /* una fila di _ non dice niente: davanti si vede la parola */
        $mezzo = '<b>' . sec_h($l['tipo'] === 'trattini' ? ($parola !== '' ? $parola : $dentro) : $dentro) . '</b>';
    }
    return $resto($prima) . $mezzo . $resto($dopo);
}

}
