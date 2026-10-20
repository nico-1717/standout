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
        $sim = fcv_lacuna_simile($testo, $parola);
        if ($sim) return ['da' => $sim['da'], 'a' => $sim['a'], 'tipo' => 'simile'];
    }
    return null;
}

/* 4. 'simile' — la parola con un'altra desinenza ("know" in "knows",
   "watched", "studied", "running"; "mangiare" in "mangiato"). Le regole sono
   spiegate in fcvLacunaSimile() (fc_examples_modal.js), che e' la gemella:
   se cambia qui, va cambiata anche li'. */

/** Le parole di un testo, minuscole, con la loro posizione in caratteri. */
function fcv_lacuna_parole(string $testo): array {
    $out = [];
    if (!preg_match_all("/[\\p{L}\\p{N}\\p{M}]+(?:['\\x{2019}][\\p{L}\\p{N}\\p{M}]+)*/u", $testo, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as [$w, $byte]) {
        $da = mb_strlen(substr($testo, 0, $byte));
        $out[] = ['t' => mb_strtolower(fcv_lacuna_norm($w)), 'da' => $da, 'a' => $da + mb_strlen($w)];
    }
    return $out;
}

function fcv_lacuna_punteggio(string $w, string $t): float {
    static $desinenze = ['s', 'es', 'ed', 'd', 'ing', 'n', 'en', 'ne', 'er'];
    if ($w === $t) return 3;
    /* forme contratte: "don't" ~ "doesn't" */
    if (preg_match("/n't$/u", $w) && preg_match("/n't$/u", $t)) return fcv_lacuna_punteggio(mb_substr($w, 0, -3), mb_substr($t, 0, -3)) ? 2 : 0;
    $lw = mb_strlen($w); $lt = mb_strlen($t);
    if ($lt > $lw && mb_substr($t, 0, $lw) === $w) {
        $suf = mb_substr($t, $lw);
        if ($lw <= 3) {
            $ult = mb_substr($w, -1);
            return (in_array($suf, $desinenze, true) || $suf === $ult . 'ing' || $suf === $ult . 'ed' || $suf === $ult . 'er') ? 2 : 0;
        }
        return mb_strlen($suf) <= 4 ? 2 : 0;
    }
    if ($lw < 4 || $lt < 3 || abs($lt - $lw) > 4) return 0;
    $cp = 0;
    while ($cp < $lw && $cp < $lt && mb_substr($w, $cp, 1) === mb_substr($t, $cp, 1)) $cp++;
    return $cp >= max(3, (int)ceil($lw * 0.7)) ? 1 : 0;
}

/** @return array{da:int,a:int}|null */
function fcv_lacuna_simile(string $testo, string $parola): ?array {
    static $paroline = ['to', 'a', 'an', 'the', 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'el', 'los', 'las', 'der', 'die', 'das', 'les', 'une', 'you', 'he', 'she', 'it', 'we', 'they', 'io', 'tu', 'lui', 'lei', 'noi', 'voi', 'loro', 'yo', 'ich', 'du', 'er', 'sie', 'wir', 'je', 'elle', 'nous', 'vous', 'ils', 'elles'];
    $frase = fcv_lacuna_parole($testo);
    if (!$frase) return null;
    $meglio = null;
    foreach (preg_split('~[,;/]~u', $parola) as $alt) {
        $pz = array_column(fcv_lacuna_parole($alt), 't');
        if (!$pz) continue;
        $varianti = [$pz];
        if (count($pz) > 1 && in_array($pz[0], $paroline, true)) $varianti[] = array_slice($pz, 1);
        foreach ($varianti as $v) {
            $nf = count($frase);
            for ($i = 0; $i < $nf; $i++) {
                $s0 = fcv_lacuna_punteggio($v[0], $frase[$i]['t']);
                if (!$s0) continue;
                $tot = $s0; $j = $i; $ok = true;
                for ($k = 1; $k < count($v) && $ok; $k++) {
                    $ok = false;
                    for ($salto = 1; $salto <= 3 && $j + $salto < $nf; $salto++) {
                        $sk = fcv_lacuna_punteggio($v[$k], $frase[$j + $salto]['t']);
                        if ($sk) { $tot += $sk - ($salto - 1) * 0.5; $j += $salto; $ok = true; break; }
                    }
                }
                if (!$ok) continue;
                $punti = $tot / count($v);
                if ($meglio === null || $punti > $meglio['punti']) $meglio = ['da' => $frase[$i]['da'], 'a' => $frase[$j]['a'], 'punti' => $punti];
            }
        }
    }
    return $meglio;
}

/**
 * La frase del gioco in HTML: la parte nascosta diventa uno spazio vuoto che
 * contiene gia' la parola, invisibile. Quando si vede la risposta il CSS
 * (fc_game.css, .fcv-gap) toglie il vuoto e fa comparire la parola al suo
 * posto: la frase si completa sotto gli occhi, senza ridisegnare niente.
 * La larghezza del vuoto dice a grandi linee quanto e' lunga la parola
 * (corta / media / lunga), non le lettere esatte.
 * Una vecchia fila di _ rimasta altrove nella frase diventa uno spazio uguale,
 * riempito con la parola della carta.
 */
function fcv_lacuna_html(string $testo, string $parola, ?string $scelta): string {
    $buco = function (string $dentro): string {
        $n = mb_strlen($dentro);
        $taglia = $n <= 4 ? 's' : ($n <= 9 ? 'm' : 'l');
        return '<span class="fcv-gap fcv-gap-' . $taglia . '">'
             . '<span class="fcv-gap-vuoto" aria-hidden="true"></span>'
             . '<span class="fcv-gap-parola">' . sec_h($dentro) . '</span></span>';
    };
    $riempi = $parola !== '' ? $parola : '…';
    $resto = function (string $s) use ($buco, $riempi): string {
        return preg_replace_callback('/_+/', function () use ($buco, $riempi) { return $buco($riempi); }, sec_h($s)) ?? sec_h($s);
    };
    $l = fcv_lacuna($testo, $parola, $scelta);
    if ($l === null) return $resto($testo);
    $prima  = mb_substr($testo, 0, $l['da']);
    $dentro = mb_substr($testo, $l['da'], $l['a'] - $l['da']);
    $dopo   = mb_substr($testo, $l['a']);
    return $resto($prima) . $buco($l['tipo'] === 'trattini' ? $riempi : $dentro) . $resto($dopo);
}

}
