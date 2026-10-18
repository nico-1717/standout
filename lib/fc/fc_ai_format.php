<?php
/**
 * fc_ai_format.php — controllo e RIPARAZIONE del formato scritto dall'IA.
 *
 * Condiviso fra fc_ai.php e ai_example.php (e qualunque altro endpoint futuro
 * che genera testo con l'IA per le flashcard): prima li usava solo fc_ai.php,
 * con le funzioni definite al suo interno. ai_example.php genera gli esempi
 * dalla pagina di modifica di una carta gia' salvata — un endpoint piu'
 * vecchio e più piccolo, che non aveva alcun controllo di formato: il testo
 * di Gemini arrivava dritto in pagina, formule rotte comprese.
 *
 * LA REGOLA CHE GOVERNA QUESTO FILE
 *
 * Riparare costa zero — e' testo che rigiriamo noi, in locale, senza
 * richiamare l'IA. Rifiutare e far rigenerare costa un'altra chiamata, cioe'
 * altri token. Per questo la riparazione va SEMPRE tentata per prima, e il
 * rifiuto (fc_ai_valida_formato) resta come ultima rete di sicurezza per cio'
 * che la riparazione non e' riuscita a sistemare — non come primo passo.
 */

/** Tag ammessi dal parser (vedi fcdFromLegacy in fc_parser.js). */
function fc_ai_tag_ammessi(): array {
    return ['bold', 'italic', 'underline', 'yellow',
            'red', 'blue', 'green', 'purple', 'orange', 'gray', 'list'];
}

/**
 * Vero se un pezzo di testo che si trova "dentro" una coppia di $ sembra in
 * realta' prosa normale finita li' per sbaglio — un $ dimenticato prima ha
 * fatto scambiare per formula tutto il testo fino al $ successivo che ha
 * trovato — invece di essere davvero matematica.
 *
 * Una formula vera e' corta e piena di simboli, lettere singole o pedici
 * brevi (x, u, F_x, \cap). Una frase vera ha tante parole scritte per esteso
 * e spazi frequenti. La soglia e' intenzionalmente prudente: nel dubbio si
 * preferisce lasciare una formula vera un po' insolita cosi' com'e', piuttosto
 * che spogliare dei $ una formula legittima solo perche' contiene un
 * \text{...} con qualche parola dentro.
 */
function fc_ai_sembra_prosa(string $dentro): bool {
    $dentro = trim($dentro);
    if ($dentro === '' || mb_strlen($dentro) < 60) return false;

    $parole = preg_split('/\s+/u', $dentro);
    $parole_lunghe = 0;
    foreach ($parole as $p) {
        if (preg_match('/^[A-Za-zÀ-ù]{3,}$/u', $p)) $parole_lunghe++;
    }
    return $parole_lunghe >= 5 && $parole_lunghe >= count($parole) / 3;
}

/**
 * Ripara gli errori di formattazione piu' comuni, SENZA richiamare l'IA.
 * Non garantisce un risultato perfetto in ogni caso limite immaginabile, ma
 * sistema i due pattern che si sono visti ripetersi davvero:
 *
 *   1. \( \) \[ \] al posto di $ $$ — si convertono ai delimitatori giusti.
 *   2. formattazione dentro una formula ($= /red{0.5}$) — si toglie il tag,
 *      restando col solo contenuto: la formula non supporta i colori, ma il
 *      numero o la lettera dentro vanno tenuti.
 *   3. un $ dimenticato che ha "inghiottito" un pezzo di prosa vera fino al
 *      $ successivo trovato per caso — si riconosce perche' il contenuto
 *      "dentro" sembra una frase (fc_ai_sembra_prosa), e in quel caso si
 *      toglie il trattamento da formula: il testo resta, senza i $.
 */
function fc_ai_ripara_formato(string $s): string {
    // 1) delimitatori LaTeX non gestiti -> quelli giusti
    $s = str_replace(['\(', '\)'], '$', $s);
    $s = str_replace(['\[', '\]'], '$$', $s);

    // 2) e 3) dentro ogni formula: tolti i tag, e se il risultato sembra
    // prosa si toglie anche il delimitatore
    $s = preg_replace_callback('/\$\$(.*?)\$\$|\$(.*?)\$/s', function ($m) {
        $doppio = strpos($m[0], '$$') === 0;
        $dentro = $doppio ? ($m[1] ?? '') : ($m[2] ?? '');
        $pulito = preg_replace('/\/[a-zA-Z]+(-[a-zA-Z]+)?\{(.*?)\}/s', '$2', $dentro);
        if (fc_ai_sembra_prosa($pulito)) return $pulito;
        $delim = $doppio ? '$$' : '$';
        return $delim . $pulito . $delim;
    }, $s);

    return $s;
}

/**
 * Verifica che il testo rispetti il formato del sito.
 * Restituisce '' se va bene, altrimenti la descrizione del problema.
 * Va chiamata DOPO fc_ai_ripara_formato(), come ultima rete di sicurezza per
 * cio' che la riparazione non e' riuscita a sistemare — non come primo passo:
 * la descrizione del problema serve a chi deve decidere cosa fare di un
 * testo che resta rotto anche dopo il tentativo di riparazione (fc_ai.php la
 * usa per chiedere una correzione mirata all'IA; un endpoint piu' semplice
 * puo' anche solo scegliere di mostrare il testo cosi' com'e', piuttosto che
 * spendere un'altra chiamata).
 */
function fc_ai_valida_formato(string $s): string {
    if (trim($s) === '') return 'testo vuoto';

    // 1) Nomi dei tag
    if (preg_match_all('/\/([a-zA-Z]+)(-[a-zA-Z]+)?\{/', $s, $m)) {
        foreach ($m[1] as $nome) {
            if (!in_array(strtolower($nome), fc_ai_tag_ammessi(), true)) {
                return 'tag /' . $nome . '{ non esiste';
            }
        }
    }

    // 2) Graffe bilanciate
    $apte = substr_count($s, '{');
    $chiuse = substr_count($s, '}');
    if ($apte !== $chiuse) return 'graffe non bilanciate (' . $apte . ' aperte, ' . $chiuse . ' chiuse)';

    /* 3) Delimitatori di formula in coppia. I $$ si contano prima e si tolgono,
       altrimenti ogni $$ verrebbe contato come due $ e il conto tornerebbe
       sempre pari anche quando non lo e'. */
    $doppi = substr_count($s, '$$');
    if ($doppi % 2 !== 0) return 'delimitatori $$ dispari';
    $singoli = substr_count(str_replace('$$', '', $s), '$');
    if ($singoli % 2 !== 0) return 'delimitatori $ dispari';

    // 4) Markdown, che il parser mostrerebbe cosi' com'e'
    if (preg_match('/\*\*|^#{1,6}\s|`/m', $s)) return 'contiene Markdown (**, #, backtick)';

    /* 5) Tag di formattazione dentro una formula, rimasti anche dopo il
       tentativo di riparazione (es. perche' fc_ai_sembra_prosa ha deciso, a
       ragione o no, che quel pezzo era matematica vera). */
    if (preg_match_all('/\$\$(.*?)\$\$|\$(.*?)\$/s', $s, $mf, PREG_SET_ORDER)) {
        foreach ($mf as $blocco) {
            $dentro = $blocco[1] !== '' ? $blocco[1] : ($blocco[2] ?? '');
            if (preg_match('/\/[a-zA-Z]+(-[a-zA-Z]+)?\{/', $dentro)) {
                return 'formattazione dentro una formula';
            }
        }
    }

    return '';
}
