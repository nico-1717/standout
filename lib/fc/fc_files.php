<?php
/* =========================================================================
   STANDOUT · FILE DELLE CARTE (immagini e audio): chi li usa, e come si tolgono

   Perche' esiste. Fino a ieri ogni file di media era di UNA carta sola, e
   cancellare la carta cancellava il file. Il mercato dei mazzi (STANDOUT_PROGETTO
   §14.4.10) cambia la regola: un mazzo copiato punta agli STESSI file
   dell'originale, senza duplicarli. Se uno dei due cancella una carta e con lei
   il file, l'altro mazzo si ritrova un'immagine rotta. Quindi: nessun file si
   cancella senza prima chiedere a tutte le tabelle che possono nominarlo se
   qualcun altro lo usa ancora (di QUALSIASI utente, per questo la query non
   filtra per user_id).

   Regole:
   - fc_file_in_uso($nome): vero se una riga di una qualunque tabella lo nomina.
   - fc_file_togli($cartella, $nome): lo cancella solo se NESSUNO lo usa.
   - fc_file_togli_dopo(): come sopra ma a fine richiesta. Serve quando la riga
     che nomina il file e' ancora nel database nel momento in cui si decide di
     cancellare (la carta si elimina subito dopo): controllarlo prima farebbe
     risultare il file "in uso" proprio dalla carta che sta per sparire.
   ========================================================================= */

/** Tutte le colonne che possono contenere il nome di un file di carta. Elenco chiuso. */
function fc_file_colonne(): array {
    return [
        'fc_study_cards'     => ['question_image', 'answer_image', 'question_audio', 'answer_audio'],
        'fc_study_subcards'  => ['question_image', 'answer_image', 'question_audio', 'answer_audio'],
        'fc_study_examples'  => ['example_image', 'example_audio'],
        'fc_vocab_cards'     => ['image'],
        'fc_vocab_subcards'  => ['image'],
        'fc_vocab_examples'  => ['example_image', 'example_audio'],
    ];
}

/** Un file e' ancora nominato da qualche carta o esempio, di qualunque utente? */
function fc_file_in_uso(string $nome): bool {
    $nome = basename($nome);
    if ($nome === '') return false;
    foreach (fc_file_colonne() as $tabella => $colonne) {
        $cond = implode(' OR ', array_map(function ($c) { return "`$c` = ?"; }, $colonne));
        if (sec_one("SELECT 1 AS ok FROM `$tabella` WHERE $cond LIMIT 1", array_fill(0, count($colonne), $nome)) !== null) return true;
    }
    return false;
}

/**
 * Cancella il file dal disco SOLO se nessuna riga lo nomina piu'.
 * basename(): il nome viene dal database o dal modulo, ma non deve poter uscire
 * dalla cartella. Ritorna vero se il file e' stato tolto.
 */
function fc_file_togli(string $cartella, ?string $nome): bool {
    $nome = basename((string)$nome);
    if ($nome === '' || $nome === '.' || $nome === '..') return false;
    $f = rtrim($cartella, '/') . '/' . $nome;
    if (!is_file($f)) return false;
    if (fc_file_in_uso($nome)) return false;
    return @unlink($f);
}

/**
 * Come fc_file_togli(), ma a fine richiesta, quando la carta e' gia' stata
 * eliminata o aggiornata. Si puo' chiamare quante volte si vuole.
 */
function fc_file_togli_dopo(string $cartella, ?string $nome): void {
    static $coda = [], $registrato = false;
    if ($nome === null || $nome === '') return;
    $coda[] = [$cartella, $nome];
    if ($registrato) return;
    $registrato = true;
    register_shutdown_function(function () use (&$coda) {
        foreach ($coda as [$cartella, $nome]) {
            try { fc_file_togli($cartella, $nome); } catch (Throwable $e) { sec_log_error('fc_file', $e->getMessage()); }
        }
    });
}
