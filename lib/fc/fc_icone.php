<?php
/* fc_icone.php — LE ICONE DEI MAZZI
 *
 * Ogni mazzo puo' avere un'icona al posto della cartella di sempre: in
 * fc_study_decks / fc_vocab_decks.icon c'e' la chiave (NULL = cartella). Qui
 * l'elenco chiuso delle chiavi, con il disegno di ciascuna (24x24, a tratto,
 * come le altre icone del sito), e la funzione che lo stampa.
 *
 * L'elenco e' chiuso apposta: una chiave che non c'e' qui non si salva
 * (fc_icona_valida) e non si disegna (torna la cartella), quindi dal campo
 * non puo' arrivare niente di diverso da un disegno nostro.
 *
 * Il nome di ogni icona sta nei file di lingua, come fc.ico_<chiave>.
 */

if (!function_exists('fc_icone_mazzo')) {

function fc_icone_mazzo(): array {
    static $icone = [
        'cartella'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        'libro'       => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
        'laurea'      => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12.2V17c3 2.7 9 2.7 12 0v-4.8"/><path d="M22 10v5"/>',
        'fumetto'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5A8 8 0 1 1 21 12Z"/>',
        'globo'       => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20Z"/>',
        'penna'       => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'sigma'       => '<path d="M18 7V4H6l6 8-6 8h12v-3"/>',
        'calcolatrice'=> '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8"/><path d="M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 18.5h.01M12 18.5h.01M16 18.5h.01"/>',
        'grafico'     => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        'atomo'       => '<circle cx="12" cy="12" r="1.2"/><ellipse cx="12" cy="12" rx="10" ry="4"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(120 12 12)"/>',
        'provetta'    => '<path d="M9 3h6"/><path d="M10 3v6.5L4.6 18.4A2 2 0 0 0 6.3 21.5h11.4a2 2 0 0 0 1.7-3.1L14 9.5V3"/><path d="M7.2 15h9.6"/>',
        'dna'         => '<path d="M7 3c0 4.5 10 4.5 10 9s-10 4.5-10 9"/><path d="M17 3c0 4.5-10 4.5-10 9s10 4.5 10 9"/><path d="M9 6.5h6M9 17.5h6"/>',
        'foglia'      => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10Z"/><path d="M2 21c0-3 1.9-5.4 5.2-6.1C9.5 14.4 12 13 13 12"/>',
        'cuore'       => '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7Z"/><path d="M3.5 11.5h4l1.5-2.5 2.5 5 1.5-2.5h7.5"/>',
        'persone'     => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-1a6 6 0 0 1 12 0v1"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M22 21v-1a6 6 0 0 0-4-5.7"/>',
        'bilancia'    => '<path d="M12 3v18M7 21h10M4 7h16"/><path d="m7 7-3 7a3 3 0 0 0 6 0Z"/><path d="m17 7-3 7a3 3 0 0 0 6 0Z"/>',
        'colonne'     => '<path d="M3 21h18M5 21v-9M9.7 21v-9M14.3 21v-9M19 21v-9"/><path d="M2.5 9.5 12 3l9.5 6.5Z"/>',
        'mappa'       => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2Z"/><path d="M9 4v14M15 6v14"/>',
        'pianeta'     => '<circle cx="12" cy="12" r="6"/><ellipse cx="12" cy="12" rx="11" ry="3.5" transform="rotate(-20 12 12)"/>',
        'codice'      => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'ingranaggio' => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'musica'      => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'tavolozza'   => '<path d="M12 22a10 10 0 1 1 10-10c0 2.8-2.2 4-4 4h-2a2 2 0 0 0-1.5 3.3A2 2 0 0 1 12 22Z"/><path d="M7.5 11h.01M10 7h.01M15 7.5h.01M17.5 11.5h.01"/>',
        'lampadina'   => '<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2Z"/>',
        'bersaglio'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'stella'      => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8-6.2-3.2-6.2 3.2 1.2-6.8-5-4.9 6.9-1Z"/>',
        'fuoco'       => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3-1-2.1-.2-4.1 2-6 .5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.2.4-2.3 1-3.3a2.5 2.5 0 0 0 2.5 2.8Z"/>',
        'bandiera'    => '<path d="M4 22V4"/><path d="M4 4h13l-2.5 4.5L17 13H4"/>',
        /* 20/10/2026 — le icone delle lingue (e di tutti i giorni) */
        'etichetta'   => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'zampa'       => '<circle cx="5" cy="10.5" r="2"/><circle cx="9.3" cy="5.5" r="2"/><circle cx="14.7" cy="5.5" r="2"/><circle cx="19" cy="10.5" r="2"/><path d="M12 11.5c-2.8 0-6 3.9-6 6.6a2.4 2.4 0 0 0 3.5 2.1c.9-.5 1.6-.7 2.5-.7s1.6.2 2.5.7a2.4 2.4 0 0 0 3.5-2.1c0-2.7-3.2-6.6-6-6.6Z"/>',
        'omino'       => '<circle cx="12" cy="4.5" r="2.5"/><path d="M4.5 9.5h15M12 9.5v5.5M12 15l-3.5 6.5M12 15l3.5 6.5"/>',
        'maglietta'   => '<path d="M8.5 3 3 5.8 2 10.5l3.5 1V21h13v-9.5l3.5-1-1-4.7L15.5 3a3.5 3.5 0 0 1-7 0Z"/>',
        'virgolette'  => '<path d="M10 11H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v4c0 3-1.5 5-4 6"/><path d="M19 11h-4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v4c0 3-1.5 5-4 6"/>',
        'catena'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'faccina'     => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>',
        'posate'      => '<path d="M5 2v6a3 3 0 0 0 6 0V2M8 2v20"/><path d="M19 22V2c-2.2 0-4 2.5-4 7v5h4"/>',
        'mela'        => '<path d="M12 7.5c-1.5-1.3-3.5-1.8-5.2-1C4.3 7.6 3.5 11 4.6 14.4 5.8 18 8.3 21 10.3 21c.7 0 1.2-.4 1.7-.4s1 .4 1.7.4c2 0 4.5-3 5.7-6.6 1.1-3.4.3-6.8-2.2-7.9-1.7-.8-3.7-.3-5.2 1Z"/><path d="M12 7.5c0-2 .8-4 2.8-5"/>',
        'casa'        => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1Z"/>',
        'valigetta'   => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 13h20"/>',
        'mattoni'     => '<rect x="2" y="4" width="20" height="16" rx="1.5"/><path d="M2 9.3h20M2 14.7h20M8 4v5.3M16 4v5.3M12 9.3v5.4M8 14.7V20M16 14.7V20"/>',
        'flacone'     => '<path d="M9 2h5l2 2"/><path d="M11.5 2v5"/><rect x="7" y="7" width="9" height="15" rx="2.5"/><path d="M7 12h9"/>',
        'puzzle'      => '<path d="M4 7h4a2 2 0 1 1 4 0h4v4a2 2 0 1 1 0 4v4h-4a2 2 0 1 0-4 0H4v-4a2 2 0 1 0 0-4Z"/>',
        'entra'       => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/>',
        'chiacchiere' => '<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2Z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>',
        'festa'       => '<path d="M5.8 11.3 2 22l10.7-3.8"/><path d="M5.8 11.3a10 10 0 0 0 6.9 6.9"/><path d="M4 3h.01M22 8h.01M15 2h.01M22 20h.01"/><path d="m22 2-2.2.8a2.9 2.9 0 0 0-1.9 3.2c.1.9-.6 1.6-1.4 1.6h-.4c-.9 0-1.6.8-1.4 1.7L15 11"/><path d="m11 2 .3 1.3c.2 1-.4 2-1.4 2.3L9 6"/>',
        'clessidra'   => '<path d="M5 22h14M5 2h14"/><path d="M17 22v-4.2a2 2 0 0 0-.6-1.4L12 12l-4.4 4.4a2 2 0 0 0-.6 1.4V22"/><path d="M7 2v4.2a2 2 0 0 0 .6 1.4L12 12l4.4-4.4a2 2 0 0 0 .6-1.4V2"/>',
        'chiave'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9Z"/>',
        'libro_aperto'=> '<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2Z"/><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7Z"/>',
        'meteo'       => '<path d="M12 2v2M4.9 4.9l1.4 1.4M20 12h2M17.7 6.3l1.4-1.4"/><path d="M16 12.6A4.5 4.5 0 0 0 8 8.7"/><path d="M13 22H7a5 5 0 1 1 4.9-6H13a3 3 0 0 1 0 6Z"/>',
        'aereo'       => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2Z"/>',
        'auto'        => '<path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M9.5 17h5M3 12h18"/>',
        'palla'       => '<circle cx="12" cy="12" r="10"/><path d="M12 2v20M2 12h20"/><path d="M5 5c3 2.5 3 11.5 0 14M19 5c-3 2.5-3 11.5 0 14"/>',
        'schermo'     => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'moneta'      => '<circle cx="12" cy="12" r="10"/><path d="M15 9.5a3 3 0 0 0-3-1.5c-1.7 0-3 .9-3 2s1.3 1.7 3 2 3 .9 3 2-1.3 2-3 2a3 3 0 0 1-3-1.5M12 6v2M12 16v2"/>',
        'carrello'    => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h2.6l2.6 12.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L22 7H5.1"/>',
        'calendario'  => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'albero'      => '<path d="M12 22v-6"/><path d="M12 2 5 11h3l-4 5h16l-4-5h3Z"/>',
    ];
    return $icone;
}

/**
 * L'ICONA INDOVINATA DAL NOME (20/10/2026)
 * Un mazzo che non ha mai scelto un'icona (icon NULL) ne prende una dal nome,
 * in italiano o in inglese: "Animals" la zampa, "Frutta e verdura" la mela.
 * Basta sceglierne una nel foglio di modifica (anche la cartella) perche' il
 * nome non conti piu'. Le regole vanno dalla piu' precisa alla piu' generica:
 * "phrasal verbs" deve diventare un puzzle prima che "verb" lo trascini via.
 */
function fc_icona_da_nome(string $nome): string {
    static $regole = [
        ['/phrasal/', 'puzzle'],
        ['/\b(personal care|igiene|cura (della |di )?(s[eé]|persona|corpo)|beauty|bellezza|cosmetic)/', 'flacone'],
        ['/\b(fruit|frutt|vegetab|verdur|ortagg)/', 'mela'],
        ['/\b(adjective|aggettiv)/', 'etichetta'],
        ['/\b(animal|pets?\b)/', 'zampa'],
        ['/\b(body|corpo|anatom)/', 'omino'],
        ['/\b(cloth|vestit|abbigliam|indument|fashion|moda)/', 'maglietta'],
        ['/\b(phrase|frasi|espression|idiom|modi di dire)/', 'virgolette'],
        ['/\b(connect|connettiv|congiunz|linking)/', 'catena'],
        ['/\b(emotion|emozion|feeling|sentiment|stati d.animo|mood)/', 'faccina'],
        ['/\b(food|cibo|cucin|cook|mangiar|meal|pasti|drink|bevand|ristorant|restaurant)/', 'posate'],
        ['/\b(house|home|casa|abitazion|furniture|mobili|arredam)/', 'casa'],
        ['/\b(jobs?|lavor|profession|work\b|mestier|career|office|uffici|business)/', 'valigetta'],
        ['/\b(material|tessut)/', 'mattoni'],
        ['/\b(preposit)/', 'entra'],
        ['/\b(slang|informal|colloquial|gergo)/', 'chiacchiere'],
        ['/\b(social|hobb|free time|tempo libero|leisure|party|feste|festa|event)/', 'festa'],
        ['/^\s*(temp|tmp|prova|test)\s*$/', 'clessidra'],
        ['/\b(tool|attrezz|utensil|strument|diy|fai da te)/', 'chiave'],
        ['/\b(vocabul|lessico|dizionari|dictionary|words|parole)/', 'libro_aperto'],
        ['/\b(weather|meteo|clima|climate|season|stagion)/', 'meteo'],
        ['/\b(travel|viagg|vacanz|holiday|trip|airport|aeroport|transport|trasport)/', 'aereo'],
        ['/\b(cars?|auto|automobil\w*|driving|traffic)\b/', 'auto'],
        ['/\b(sport|gym|palestr|fitness|football|calcio|workout|allenam)/', 'palla'],
        ['/\b(comput|technolog|tecnolog|internet|digital|informatica|it)\b/', 'schermo'],
        ['/\b(money|soldi|denaro|financ|finanz|bank|banca|econom)/', 'moneta'],
        ['/\b(shop|spesa|negozi|acquist|buy)/', 'carrello'],
        ['/\b(time|tempo|dates?\b|giorn|days?\b|month|mesi|calendar)/', 'calendario'],
        ['/\b(nature|natura|plant|piant|garden|giardin|environment|ambiente)/', 'albero'],
        ['/\b(verb|verbi|grammar|grammatic|tense|tempi verbali)/', 'penna'],
        ['/\b(math|matemat|analisi|calculus|algebra|geometr|statistic|limit|integral|derivat|funzion|equazion)/', 'sigma'],
        ['/\b(physic|fisica)/', 'atomo'],
        ['/\b(chemi|chimic)/', 'provetta'],
        ['/\b(biolog|genetic|genetica)/', 'dna'],
        ['/\b(histor|storia|storic)/', 'colonne'],
        ['/\b(geograph|geograf|countr|paesi|city|citt)/', 'mappa'],
        ['/\b(law|legal|diritto|legge|giurisprud)/', 'bilancia'],
        ['/\b(programm|coding|code|codice|software|algoritm)/', 'codice'],
        ['/\b(music|musica|canzon|song)/', 'musica'],
        ['/\b(arts?\b|arte\b|disegn|paint|pittur)/', 'tavolozza'],
        ['/\b(literat|letterat|poes|poetry|romanz|novel|reading|lettur)/', 'libro'],
        ['/\b(medic|health|salute|malatt|disease|farmac)/', 'cuore'],
        ['/\b(psicolog|psycholog|sociolog|people|persone|famil|family|relation)/', 'persone'],
        ['/\b(astronom|space|spazio|planet|pianet)/', 'pianeta'],
        ['/\b(engineer|ingegner|meccanic|mechanic|elettron)/', 'ingranaggio'],
        ['/\b(philosoph|filosof|idea)/', 'lampadina'],
        ['/\b(english|inglese|language|lingu|speaking|conversation|conversaz)/', 'fumetto'],
    ];
    $n = mb_strtolower(trim($nome));
    foreach ($regole as [$re, $icona]) if (preg_match($re . 'u', $n)) return $icona;
    return 'cartella';
}

/** L'icona da disegnare per un mazzo: quella scelta, o quella del nome. */
function fc_icona_mazzo_di(array $mazzo): string {
    $ic = $mazzo['icon'] ?? null;
    return fc_icona_valida($ic) ? (string)$ic : fc_icona_da_nome((string)($mazzo['name'] ?? ''));
}

/** Vero se la chiave e' un'icona che esiste (la cartella compresa). */
function fc_icona_valida(?string $chiave): bool {
    return $chiave !== null && $chiave !== '' && isset(fc_icone_mazzo()[$chiave]);
}

/** Il disegno completo di un'icona; una chiave vuota o sconosciuta da' la cartella. */
function fc_icona_svg(?string $chiave, float $tratto = 2): string {
    $icone = fc_icone_mazzo();
    $corpo = $icone[fc_icona_valida($chiave) ? $chiave : 'cartella'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $tratto
         . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $corpo . '</svg>';
}

}
