<?php
/* =====================================================================
   lib/api_settings.php — le impostazioni d'aspetto per l'app (GET/PUT /api/v1/settings)

   Cosa: lo stile (standard | paper), il colore del sito e la lingua. Sul sito stanno in
   users.ui_style, users.ui_accent e users.lang e si cambiano con la sessione; l'app ha il
   token e nessuna sessione, quindi qui si leggono e si scrivono a mano, sempre per l'utente
   del token (user_id nella WHERE, mai preso dal corpo della richiesta).

   Perche' un'unica rotta e non il registro dei cambiamenti: sono tre valori per utente, non
   righe da far convergere. L'app li scarica a ogni sincronizzazione; se ne ha cambiato uno
   da offline lo invia (vince l'ultimo che scrive: e' una preferenza, non un testo lungo).

   Valori mai scelti: ui_style e ui_accent NULL = standard e blu (come sul sito).
   ===================================================================== */

require_once __DIR__ . '/stile.php';
require_once __DIR__ . '/accent.php';
require_once __DIR__ . '/i18n.php';

function api_settings_leggi(int $uid): array {
    $r = sec_one("SELECT ui_style, ui_accent, lang FROM users WHERE id = ? AND is_active = 1", [$uid], 'i');
    $stile  = ($r && standout_stile_valido($r['ui_style'] ?? null)) ? $r['ui_style'] : 'standard';
    $accent = ($r && standout_accento_valido($r['ui_accent'] ?? null)) ? $r['ui_accent'] : STANDOUT_ACCENTO_BASE;
    $lang   = ($r && standout_lingua_valida($r['lang'] ?? null)) ? $r['lang'] : null;
    return ['ui_style' => $stile, 'accent' => $accent, 'lang' => $lang];
}

function api_h_settings_leggi(array $ctx): void {
    api_ok(['settings' => api_settings_leggi((int)$ctx['utente']['id'])]);
}

function api_h_settings_scrivi(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    api_h_limite_scritture($ctx, 'api_settings_write', 60);
    $c = api_corpo();

    // Ogni valore si controlla con lo stesso elenco del sito; un valore sconosciuto e' un errore, non si ignora in silenzio.
    // Una UPDATE per campo, con il testo scritto per intero: niente nomi di colonna costruiti a mano.
    // «users» e' la tabella dell'utente stesso: la sua chiave e' id, e vale quello del token.
    $ok = [];
    if (array_key_exists('ui_style', $c)) {
        if (!standout_stile_valido($c['ui_style'])) api_errore('validation', 'ui_style must be "standard" or "paper"', null, ['field' => 'ui_style']);
        $ok['ui_style'] = $c['ui_style'];
    }
    if (array_key_exists('accent', $c)) {
        if (!standout_accento_valido($c['accent'])) api_errore('validation', 'Unknown accent colour', null, ['field' => 'accent']);
        $ok['accent'] = $c['accent'];
    }
    if (array_key_exists('lang', $c)) {
        if (!standout_lingua_valida($c['lang'])) api_errore('validation', 'Unsupported language', null, ['field' => 'lang']);
        $ok['lang'] = $c['lang'];
    }
    if (isset($ok['ui_style'])) sec_q("UPDATE users SET ui_style = ? WHERE id = ?", [$ok['ui_style'], $uid], 'si');
    // il blu e' «mai scelto»: si salva NULL, come fa il sito
    if (isset($ok['accent']))   sec_q("UPDATE users SET ui_accent = ? WHERE id = ?", [$ok['accent'] === STANDOUT_ACCENTO_BASE ? null : $ok['accent'], $uid], 'si');
    if (isset($ok['lang']))     sec_q("UPDATE users SET lang = ? WHERE id = ?", [$ok['lang'], $uid], 'si');
    api_ok(['settings' => api_settings_leggi($uid)]);
}
