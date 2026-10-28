<?php
/* =====================================================================
   STANDOUT — IL COLORE DELLA PAGINA VOTI
   L'utente lo sceglie nella pagina Voti (university_settings.accent_color).
   Qui lo si legge per usarlo anche altrove (scorciatoia Voti della home, voce
   Voti della barra laterale), cosi' "Voti" ha lo stesso colore dappertutto.

   #0A84FF e' il valore di partenza della colonna, cioe' "mai scelto": in quel
   caso torna '' e chi chiama tiene il suo colore di sempre.
   Una query per chiave primaria, una volta per richiesta (static): non serve
   una cache di sessione, che resterebbe vecchia dopo un cambio di colore.
   ===================================================================== */
if (!function_exists('standout_colore_voti')) {
    function standout_colore_voti(): string
    {
        static $colore = null;
        if ($colore !== null) return $colore;
        $colore = '';
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0 || !function_exists('sec_one') || !function_exists('sec_color')) return $colore;
        try {
            $r = sec_one("SELECT accent_color FROM university_settings WHERE user_id = ?", [$uid], 'i');
            $c = $r ? sec_color($r['accent_color'] ?? '', '') : '';
            if ($c !== '' && $c !== '#0A84FF') $colore = $c;
        } catch (Throwable $e) { /* senza la tabella impostazioni: nessun colore */ }
        return $colore;
    }
}

/* Il colore con cui la pagina Voti si disegna. Se l'utente ne ha scelto uno nella
   pagina (diverso dal #0A84FF di partenza) vale quello; altrimenti la pagina
   segue il colore del sito (lib/accent.php, scelto dal pannello del profilo),
   cosi' chi sceglie un colore non si ritrova Voti ancora tutta blu.
   Si usa il valore "scuro" della tavolozza (come il #0A84FF di prima, uguale in
   chiaro e scuro). Per il blu del sito torna #0A84FF: nessun cambiamento. */
if (!function_exists('standout_colore_voti_effettivo')) {
    function standout_colore_voti_effettivo($salvato): string
    {
        $c = function_exists('sec_color') ? sec_color($salvato, '#0A84FF') : '#0A84FF';
        if ($c !== '#0A84FF') return $c;
        $f = __DIR__ . '/accent.php';
        if (is_readable($f)) {
            require_once $f;
            $a = standout_accento();
            if ($a !== STANDOUT_ACCENTO_BASE && isset(STANDOUT_ACCENTI[$a])) return STANDOUT_ACCENTI[$a]['d'];
        }
        return '#0A84FF';
    }
}
