<?php
/* fc_vocab_gap.php — LA PARTE NASCOSTA DI UNA FRASE D'ESEMPIO (solo lingue)
 *
 * Nel gioco, quando la parola da dire sta dietro, nella frase d'esempio
 * diventa lo spazio da indovinare. Quali pezzi di frase spariscono si decide
 * qui, in quest'ordine:
 *
 *   1. 'scelta'   — i pezzi scelti a mano (fc_vocab_examples.gap_text), se
 *                   nella frase ci sono ancora;
 *   2. 'trattini' — le vecchie file di _ degli esempi importati prima, dove
 *                   la parola era gia' stata tolta dal testo;
 *   3. 'parola'   — la parola della carta, se nella frase compare scritta
 *                   uguale (maiuscole a parte) e come parola intera: "know" in
 *                   "I don't know" si', in "knowledge" no;
 *   4. 'simile'   — una sua forma: un'altra desinenza ("knows", "studied"),
 *                   una contrazione ("I'm" per "I am"), o le sue parole con
 *                   altre in mezzo ("give it up" per "give up": spariscono
 *                   "give" e "up", "it" resta).
 *
 * Altrimenti niente: la frase si mostra intera e nel modulo compare il tasto
 * per scegliere a mano. Lo stesso identico calcolo sta in fc_vocab_gap.js:
 * se cambia qui, va cambiato anche li'.
 *
 * gap_text:
 *   NULL / ''                  automatica
 *   'I want'                   un pezzo solo (il formato di prima, vale ancora)
 *   '[[0,"I want"],[11,"to"]]' piu' pezzi: [posizione, testo]; la posizione
 *                              serve solo a scegliere QUALE "to" se ce ne sono due
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

/** Minuscolo, se non cambia la lunghezza (altrimenti le posizioni non tornerebbero). */
function fcv_lacuna_minuscolo(string $t): string {
    $l = mb_strtolower($t);
    return mb_strlen($l) === mb_strlen($t) ? $l : $t;
}

/**
 * Tutte le volte che $ago compare in $testo, senza badare alle maiuscole,
 * con un segno per quelle intere (senza lettere attaccate prima o dopo).
 * @return array<int, array{p:int, intera:bool}>
 */
function fcv_lacuna_occorrenze(string $testo, string $ago): array {
    $out = [];
    if ($ago === '') return $out;
    $tl = fcv_lacuna_minuscolo(fcv_lacuna_norm($testo));
    $al = fcv_lacuna_minuscolo(fcv_lacuna_norm($ago));
    $len = mb_strlen($al);
    $da = 0;
    while (($p = mb_strpos($tl, $al, $da)) !== false) {
        $prima = $p > 0 ? mb_substr($tl, $p - 1, 1) : '';
        $dopo  = mb_substr($tl, $p + $len, 1);
        $bordoA = !fcv_lacuna_lettera(mb_substr($al, 0, 1)) || !fcv_lacuna_lettera($prima);
        $bordoB = !fcv_lacuna_lettera(mb_substr($al, -1)) || !fcv_lacuna_lettera($dopo);
        $out[] = ['p' => $p, 'intera' => $bordoA && $bordoB];
        $da = $p + 1;
    }
    return $out;
}

/**
 * La prima volta che $ago compare intero; con $anche_dentro anche dentro una parola.
 * @return int|null posizione in caratteri
 */
function fcv_lacuna_trova(string $testo, string $ago, bool $anche_dentro = false): ?int {
    $occ = fcv_lacuna_occorrenze($testo, $ago);
    foreach ($occ as $o) if ($o['intera']) return $o['p'];
    return ($anche_dentro && $occ) ? $occ[0]['p'] : null;
}

/**
 * La scelta salvata in gap_text: null (automatica) o i pezzi [['da'=>int,'t'=>string]].
 * Il vecchio formato (un pezzo di testo) ha posizione -1: si prende il primo.
 */
function fcv_gap_leggi(?string $gap): ?array {
    $s = trim((string)$gap);
    if ($s === '') return null;
    if ($s[0] === '[') {
        $j = json_decode($s, true);
        if (is_array($j)) {
            $out = [];
            foreach ($j as $x) {
                if (is_array($x) && isset($x[1]) && trim((string)$x[1]) !== '') {
                    $out[] = ['da' => (int)($x[0] ?? 0), 't' => (string)$x[1]];
                }
            }
            if ($out) return $out;
        }
    }
    return [['da' => -1, 't' => $s]];
}

/**
 * Un gap_text arrivato dal browser, ripulito: pezzi corti, al massimo 20,
 * nel formato giusto. Stringa vuota se non c'e' niente di valido.
 */
function fcv_gap_pulisci(string $gap): string {
    $gap = trim($gap);
    if ($gap === '') return '';
    if ($gap[0] !== '[') return mb_substr($gap, 0, 255);
    $pz = fcv_gap_leggi($gap);
    if (!$pz) return '';
    $out = [];
    foreach (array_slice($pz, 0, 20) as $p) $out[] = [max(0, (int)$p['da']), mb_substr($p['t'], 0, 200)];
    $j = json_encode($out, JSON_UNESCAPED_UNICODE);
    return mb_strlen($j) <= 1000 ? $j : '';
}

/**
 * I pezzi scelti ritrovati nella frase com'e' adesso, in ordine. null se
 * anche uno solo non c'e' piu': meglio la scelta automatica che una a meta'.
 */
function fcv_gap_risolvi(string $testo, array $scelta): ?array {
    $out = [];
    $fine = 0;
    foreach ($scelta as $s) {
        $occ = array_values(array_filter(fcv_lacuna_occorrenze($testo, $s['t']), function ($o) use ($fine) { return $o['p'] >= $fine; }));
        if (!$occ) return null;
        $intere = array_values(array_filter($occ, function ($o) { return $o['intera']; }));
        $cand = $intere ?: $occ;
        $meglio = $cand[0];
        if ($s['da'] >= 0) {
            foreach ($cand as $o) if (abs($o['p'] - $s['da']) < abs($meglio['p'] - $s['da'])) $meglio = $o;
        }
        $len = mb_strlen($s['t']);
        $out[] = ['da' => $meglio['p'], 'a' => $meglio['p'] + $len, 'tipo' => 'scelta'];
        $fine = $meglio['p'] + $len;
    }
    return $out;
}

/**
 * Le parti nascoste di una frase, in ordine.
 * @return array<int, array{da:int,a:int,tipo:string}>  da incluso, a escluso
 */
function fcv_lacune(string $testo, string $parola, ?string $scelta = null): array {
    if (trim($testo) === '') return [];
    $sc = fcv_gap_leggi($scelta);
    if ($sc) {
        $r = fcv_gap_risolvi($testo, $sc);
        if ($r) return $r;
    }
    if (preg_match_all('/_+/u', $testo, $m, PREG_OFFSET_CAPTURE)) {
        $out = [];
        foreach ($m[0] as [$t, $byte]) {
            $p = mb_strlen(substr($testo, 0, $byte));
            $out[] = ['da' => $p, 'a' => $p + mb_strlen($t), 'tipo' => 'trattini'];
        }
        if ($out) return $out;
    }
    $parola = trim($parola);
    if ($parola !== '') {
        $p = fcv_lacuna_trova($testo, $parola, false);
        if ($p !== null) return [['da' => $p, 'a' => $p + mb_strlen($parola), 'tipo' => 'parola']];
        $sim = fcv_lacuna_simile($testo, $parola);
        if ($sim) return $sim;
    }
    return [];
}

/**
 * Compatibilita': un pezzo solo, dall'inizio del primo alla fine dell'ultimo.
 * @return array{da:int,a:int,tipo:string}|null
 */
function fcv_lacuna(string $testo, string $parola, ?string $scelta = null): ?array {
    $l = fcv_lacune($testo, $parola, $scelta);
    if (!$l) return null;
    return ['da' => $l[0]['da'], 'a' => $l[count($l) - 1]['a'], 'tipo' => $l[0]['tipo']];
}

/* Le parole di un testo per il confronto, minuscole, con la loro posizione.
   Le contrazioni si aprono: "I'm" diventa "i" + "am", "don't" resta intera. */
function fcv_lacuna_parole(string $testo): array {
    static $contr = ['m' => 'am', 're' => 'are', 's' => 'is', 've' => 'have', 'll' => 'will', 'd' => 'would'];
    $out = [];
    if (!preg_match_all("/[\\p{L}\\p{N}\\p{M}]+(?:['\\x{2019}][\\p{L}\\p{N}\\p{M}]+)*/u", $testo, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as [$w, $byte]) {
        $da = mb_strlen(substr($testo, 0, $byte));
        $a = $da + mb_strlen($w);
        $t = fcv_lacuna_minuscolo(fcv_lacuna_norm($w));
        if (preg_match("/^(.+)'(m|re|s|ve|ll|d)$/u", $t, $c) && !preg_match('/n$/u', $c[1])) {
            $taglio = $da + mb_strlen($c[1]);
            $out[] = ['t' => $c[1], 'da' => $da, 'a' => $taglio];
            $out[] = ['t' => $contr[$c[2]], 'da' => $taglio, 'a' => $a];
        } else {
            $out[] = ['t' => $t, 'da' => $da, 'a' => $a];
        }
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

/**
 * La forma della parola nella frase, a pezzi: le parole della carta che si
 * trovano vicine si uniscono, quelle in mezzo che non sono della carta
 * restano visibili. Le regole sono spiegate in simile() (fc_vocab_gap.js).
 * @return array<int, array{da:int,a:int,tipo:string}>|null
 */
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
                $tot = $s0; $j = $i; $presi = [$i]; $ok = true;
                for ($k = 1; $k < count($v) && $ok; $k++) {
                    $ok = false;
                    for ($salto = 1; $salto <= 3 && $j + $salto < $nf; $salto++) {
                        $sk = fcv_lacuna_punteggio($v[$k], $frase[$j + $salto]['t']);
                        if ($sk) { $tot += $sk - ($salto - 1) * 0.5; $j += $salto; $presi[] = $j; $ok = true; break; }
                    }
                }
                if (!$ok) continue;
                $punti = $tot / count($v);
                if ($meglio === null || $punti > $meglio['punti']) $meglio = ['punti' => $punti, 'presi' => $presi];
            }
        }
    }
    if ($meglio === null) return null;
    $out = [];
    foreach ($meglio['presi'] as $n => $idx) {
        $w = $frase[$idx];
        if ($n > 0 && $meglio['presi'][$n - 1] === $idx - 1) $out[count($out) - 1]['a'] = $w['a'];
        else $out[] = ['da' => $w['da'], 'a' => $w['a'], 'tipo' => 'simile'];
    }
    return $out;
}

/**
 * La frase del gioco in HTML: ogni parte nascosta diventa uno spazio vuoto
 * che contiene gia' la parola, invisibile. Quando si vede la risposta il CSS
 * (fc_game.css, .fcv-gap) toglie il vuoto e fa comparire la parola al suo
 * posto: la frase si completa sotto gli occhi, senza ridisegnare niente.
 * La larghezza del vuoto dice a grandi linee quanto e' lunga la parte
 * nascosta (corta / media / lunga), non le lettere esatte.
 * Una vecchia fila di _ si riempie con la parola della carta.
 */
function fcv_lacuna_html(string $testo, string $parola, ?string $scelta): string {
    $n = 0;
    $buco = function (string $dentro) use (&$n): string {
        $len = mb_strlen($dentro);
        $taglia = $len <= 4 ? 's' : ($len <= 9 ? 'm' : 'l');
        $i = $n++;
        return '<span class="fcv-gap fcv-gap-' . $taglia . '" style="--fcv-gap-i:' . $i . '">'
             . '<span class="fcv-gap-vuoto" aria-hidden="true"></span>'
             . '<span class="fcv-gap-parola">' . sec_h($dentro) . '</span></span>';
    };
    $riempi = trim($parola) !== '' ? trim($parola) : '…';
    $out = '';
    $da = 0;
    foreach (fcv_lacune($testo, $parola, $scelta) as $l) {
        $out .= sec_h(mb_substr($testo, $da, $l['da'] - $da));
        $out .= $buco($l['tipo'] === 'trattini' ? $riempi : mb_substr($testo, $l['da'], $l['a'] - $l['da']));
        $da = $l['a'];
    }
    return $out . sec_h(mb_substr($testo, $da));
}

}
