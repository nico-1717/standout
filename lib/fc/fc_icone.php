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
    ];
    return $icone;
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
