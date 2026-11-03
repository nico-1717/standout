<?php
/* =====================================================================
   STANDOUT — le note dei progetti: quando una nota e' vuota e come si pulisce.
   Stava dentro public/projects/progetti.php; sta qui perche' la regola e' una sola e la usano
   la pagina e la sincronizzazione con l'app (lib/sync_progetti.php).
   ===================================================================== */

function prog_nota_vuota($s): bool {
    /* Il formato dell'editor e' un documento JSON ({"v":2,"blocks":[…]}): un
       documento senza testo ne' formule ha comunque dei caratteri (le parentesi,
       i nomi dei campi), quindi per le regole sotto sembrava una nota piena.
       Risultato: una nota svuotata restava «piena» e la pagina mostrava un
       riquadro vuoto, che si correggeva solo dopo che il JavaScript l'aveva
       disegnata. Qui si guarda cosa c'e' DENTRO il documento. */
    if (is_string($s) && isset($s[0]) && $s[0] === '{') {
        $j = json_decode($s, true);
        if (is_array($j) && isset($j['blocks']) && is_array($j['blocks'])) {
            $invisibili = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+/u';
            foreach ($j['blocks'] as $b) {
                if (!is_array($b)) continue;
                if (($b['t'] ?? '') === 'fx' && trim((string)($b['tex'] ?? '')) !== '') return false;
                foreach (($b['runs'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    foreach (['s', 'x'] as $k) {
                        if (isset($r[$k]) && is_string($r[$k]) && preg_replace($invisibili, '', $r[$k]) !== '') return false;
                    }
                }
            }
            return true;
        }
    }
    $t = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+/u', '', $t);
    if ($t === null) return false;
    $t = preg_replace('/[*_~`#>|=\-\[\]{}$\\\\]+/u', '', $t);
    if ($t === null) return false;
    // se una regex fallisse (null), meglio mostrare la nota che nasconderla
    return $t === '';
}
function prog_nota_pulita($grezza, int $max): string {
    $t = (string)$grezza;

    // i nomi di elemento veri: l'elenco è lungo ma è l'unico modo di
    // distinguere <b> da <ciao> senza buttare via il secondo
    $tagHtml = 'a|abbr|address|area|article|aside|audio|b|base|bdi|bdo|big|blockquote|body|br|button|canvas|caption|center|cite|code|col|colgroup|data|datalist|dd|del|details|dfn|dialog|div|dl|dt|em|embed|fieldset|figcaption|figure|font|footer|form|h1|h2|h3|h4|h5|h6|head|header|hr|html|i|iframe|img|input|ins|kbd|label|legend|li|link|main|map|mark|menu|meta|meter|nav|noscript|object|ol|optgroup|option|output|p|param|picture|pre|progress|q|rp|rt|ruby|s|samp|script|section|select|slot|small|source|span|strike|strong|style|sub|summary|sup|svg|table|tbody|td|template|textarea|tfoot|th|thead|time|title|tr|track|u|ul|var|video|wbr';

    // 1. i tag HTML veri, aperti o chiusi
    $t = preg_replace('#</?(?:' . $tagHtml . ')\b[^>]*>#i', '', $t);
    /* 2. qualunque cosa che COMINCI come un tag e abbia degli attributi:
          <x onerror=…>, <qualsiasi src="">. Due vincoli, non uno: deve
          cominciare con una lettera E contenere un `=`. Il primo salva
          «5 < 7 e 9 > 3» e «a <- b -> c», il secondo salva «x<y e y>z» — che
          senza l'uguale è una disuguaglianza, non un tag con attributi.
          Resta un caso perso, «a < b = c > d»: si può scrivere solo così o
          come un tag, e fra le due ho scelto di non far passare il tag. */
    $t = preg_replace('#</?[A-Za-z][^<>]*=[^<>]*>#', '', $t);
    // e la forma che si chiude da sola: <qualcosa />
    $t = preg_replace('#<[A-Za-z][^<>]*/>#', '', $t);
    // 3. i commenti HTML, che non hanno un nome di elemento
    $t = preg_replace('#<!--.*?-->#s', '', $t);

    // caratteri di controllo via, a capo e tabulazioni no: servono
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    $t = trim($t);
    // una nota fatta solo di spazi, a capo o caratteri invisibili è una nota vuota
    if (prog_nota_vuota($t)) return '';
    if (function_exists('mb_substr')) {
        if (mb_strlen($t, 'UTF-8') > $max) $t = mb_substr($t, 0, $max, 'UTF-8');
    } elseif (strlen($t) > $max) {
        $t = substr($t, 0, $max);
    }
    return $t;
}
