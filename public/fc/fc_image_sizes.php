<?php
/* fc_image_sizes.php — le misure delle immagini delle carte.
 *
 * IL PROBLEMA
 * Un <img> senza larghezza e altezza dichiarate occupa zero finche' il file
 * non e' arrivato: il testo sotto sta piu' in su, poi l'immagine atterra e
 * spinge tutto giu'. Con le carte a immagine sola (.qa-content.qa-solo, dove
 * il CSS lascia width e height automatiche) succede a ogni apertura, e con
 * "carte intere" acceso il salto e' anche piu' grosso perche' non c'e' il
 * tetto dei 230px.
 * Dichiarando i due attributi il browser conosce la proporzione e tiene il
 * posto giusto fin dal primo disegno, anche se l'immagine arrivera' fra un
 * secondo.
 *
 * PERCHE' NON SI LEGGONO AL VOLO
 * getimagesize() apre il file su disco. Su una pagina con centinaia di carte
 * sarebbero centinaia di aperture per ogni visita: si spenderebbe piu' di
 * quanto si risparmia. Si leggono UNA volta, quando l'immagine viene salvata,
 * e da li' in poi si legge il database.
 *
 * Questo file e' incluso da config.php, quindi le funzioni ci sono ovunque e
 * nessuna pagina puo' restare indietro per dimenticanza.
 */

/* Memoria di questa richiesta: la stessa immagine puo' comparire piu' volte
   nella stessa pagina (una carta e il suo esempio, o due carte che usano lo
   stesso file), e non ha senso richiederla due volte. */
$GLOBALS['__fc_misure'] = [];

/* Da chiamare SUBITO DOPO aver scritto il file, con il percorso completo:
   e' l'unico momento in cui il contenuto e' li' sotto mano e leggerlo costa
   una apertura sola.
   REPLACE e non INSERT: sec_safe_filename genera nomi nuovi ogni volta, ma se
   per qualsiasi motivo un nome venisse riusato la riga vecchia sarebbe
   sbagliata, ed e' meglio sovrascriverla che tenerla. */
if (!function_exists('fc_misura_registra')) {
    function fc_misura_registra(string $nome, string $percorsoCompleto): void {
        if ($nome === '' || !is_readable($percorsoCompleto)) return;
        $m = @getimagesize($percorsoCompleto);
        /* Non e' un'immagine (un audio, un file rotto): non e' un errore, qui
           semplicemente non c'e' niente da misurare. */
        if (!$m || empty($m[0]) || empty($m[1])) return;
        $w = min(65535, (int)$m[0]);
        $h = min(65535, (int)$m[1]);
        try {
            sec_q("REPLACE INTO fc_image_meta (filename, w, h) VALUES (?, ?, ?)", [$nome, $w, $h]);
        } catch (Throwable $e) {
            /* Se la tabella non c'e' ancora (migrazione non eseguita) il
               salvataggio della carta non deve fallire per questo: senza
               misure la pagina funziona esattamente come prima. */
        }
        $GLOBALS['__fc_misure'][$nome] = ['w' => $w, 'h' => $h];
    }
}

/* Carica in un colpo solo le misure dei nomi che servono a questa pagina.
   Una query per pagina, non una per immagine. */
if (!function_exists('fc_misure_carica')) {
    function fc_misure_carica(array $nomi): void {
        $mancanti = [];
        foreach ($nomi as $n) {
            $n = (string)$n;
            if ($n !== '' && !array_key_exists($n, $GLOBALS['__fc_misure'])) $mancanti[$n] = true;
        }
        if (empty($mancanti)) return;
        $lista = array_keys($mancanti);

        /* Si segnano subito come "cercate": cosi' un nome che nel database non
           c'e' (immagine caricata prima della migrazione, e non ancora
           recuperata dallo script) non viene richiesto un'altra volta. */
        foreach ($lista as $n) $GLOBALS['__fc_misure'][$n] = null;

        $segna = implode(',', array_fill(0, count($lista), '?'));
        try {
            foreach (sec_all("SELECT filename, w, h FROM fc_image_meta WHERE filename IN ($segna)", $lista) as $r) {
                $GLOBALS['__fc_misure'][$r['filename']] = ['w' => (int)$r['w'], 'h' => (int)$r['h']];
            }
        } catch (Throwable $e) {
            // tabella assente: si prosegue senza misure
        }
    }
}

/* Gli attributi da infilare nel tag, o stringa vuota se la misura non si sa.
   Vuota e' un ripiego onesto: si torna al comportamento di prima per quella
   immagine, non si inventa una proporzione sbagliata — che sarebbe peggio del
   salto, perche' il posto tenuto sarebbe quello di un'altra figura. */
if (!function_exists('fc_misura_attr')) {
    function fc_misura_attr(string $nome): string {
        if ($nome === '') return '';
        if (!array_key_exists($nome, $GLOBALS['__fc_misure'])) fc_misure_carica([$nome]);
        $m = $GLOBALS['__fc_misure'][$nome] ?? null;
        if (!$m) return '';
        return ' width="' . (int)$m['w'] . '" height="' . (int)$m['h'] . '"';
    }
}
