<?php
/* =====================================================================
   NISAMA — LA BARRA DEL POMODORO (componente)

   Non stampa niente: RESTITUISCE due pezzi di HTML, uno per il <head> e
   uno per la fine del <body>. Il motivo e' tecnico e va detto, perche'
   altrimenti la prima modifica lo rompe: questo file viene richiamato
   da dentro il gestore di output di config.php, e li' dentro PHP non
   permette di aprire un altro buffer. Quindi niente include che
   stampano, niente echo: solo stringhe.

   Perche' non e' un include normale nelle pagine: sono ventisette, e
   sarebbero ventisette modifiche identiche da rifare a ogni ritocco —
   la stessa ragione per cui i meta della PWA si innestano da config.

   Costo: se non c'e' nessun pomodoro e nessuna pausa in corso, non
   restituisce niente e la pagina non si accorge di questo file. Il JS
   non viene caricato, il controllo periodico non parte, non c'e' nessun
   giro a vuoto sulle pagine di chi non sta usando il pomodoro.
   ===================================================================== */

function pomo_barra_html(): array
{
    $vuoto = ['head' => '', 'body' => ''];

    /* Pagine senza login (accesso, registrazione, la landing) e pagine che
       non hanno caricato le difese: qui non c'e' niente da mostrare. */
    if (empty($_SESSION['user_id']) || !function_exists('sec_one')) return $vuoto;

    $file = dirname(__FILE__) . '/pomodoro_stato.php';
    if (!is_readable($file)) return $vuoto;
    require_once $file;

    $uid = (int)$_SESSION['user_id'];
    $stato = pomo_leggi_stato($uid);

    /* Un pomodoro finito da solo (il tempo previsto e' scaduto) resta
       "completato" finche' l'utente non lo chiude: end_time e' gia'
       scritto, quindi non e' piu' in $stato['active'], ma il fuoritempo
       continua a scorrere e la pagina pomodoro lo mostra ancora. E'
       esattamente lo stesso stato che dalloStato() riconosce come
       fase 'completed' — la barra deve accorgersene allo stesso modo,
       non solo quando c'e' una sessione con end_time NULL. */
    $completatoRecente = !empty($stato['last']) && ($stato['last']['esito'] ?? null) === 'completato';

    // niente in corso: nessuna barra, nessuno script, nessun controllo
    if (empty($stato['active']) && empty($stato['pausa']) && !$completatoRecente) return $vuoto;

    $base = defined('PWA_BASE') ? PWA_BASE : '/';
    $ver  = static function (string $nome) use ($base): string {
        $p = dirname(__FILE__) . '/' . $nome;
        return $base . $nome . '?v=' . (@filemtime($p) ?: 1);
    };

    /* Le stringhe viaggiano nei dati e non passano da nisama_i18n_json:
       ogni pagina dichiara i propri gruppi di traduzioni, e la barra non
       puo' pretendere che ventisette pagine si ricordino di aggiungere il
       gruppo 'pomodoro'. Sono sei parole, se le porta dietro. */
    $dati = [
        'stato'     => $stato,
        'endpoint'  => $base . 'pomodoro.php',
        /* Il selettore dei progetti e' lo stesso di tutte le altre pagine, con
           la sua resa autonoma: se lo carica il popup al primo tocco, invece di
           finire incluso in ventisette pagine dove nessuno lo aprira' mai. */
        'selettore' => $base . 'project_selector.php?' . http_build_query([
            'ps_render'           => 1,
            'ps_id'               => 'pomobarra',
            'ps_title'            => t('pomodoro.collega_progetto'),
            'ps_placeholder'      => t('pomodoro.nessun_progetto_collegato'),
            'ps_show_all_option'  => 1,
            'ps_all_option_label' => t('pomodoro.nessun_progetto'),
        ]),
        'modale'    => 'ps-modal-pomobarra',
        'limite'    => POMO_LIMITE_SECONDI,
        'testi'     => [
            'concentrazione'    => t('pomodoro.concentrazione'),
            'sessione_in_pausa' => t('pomodoro.sessione_in_pausa'),
            'completato'        => t('pomodoro.completato'),
            'pausa_in_corso'    => t('pomodoro.pausa_in_corso'),
            'pausa_conclusa'    => t('pomodoro.pausa_conclusa'),
            'concluso'          => t('pomodoro.concluso'),
            'ottimo_lavoro'     => t('pomodoro.ottimo_lavoro'),
            'pausa_finita'      => t('pomodoro.pausa_finita'),
            'torna_al_lavoro'   => t('pomodoro.torna_al_lavoro'),
            'nessun_progetto'   => t('pomodoro.nessun_progetto_collegato'),
        ],
    ];

    $head = "\n" . '<link rel="stylesheet" href="' . htmlspecialchars($ver('pomodoro_barra.css'), ENT_QUOTES, 'UTF-8') . '">'
          . "\n" . '<script defer src="' . htmlspecialchars($ver('pomodoro_core.js'), ENT_QUOTES, 'UTF-8') . '"></script>'
          . "\n" . '<script defer src="' . htmlspecialchars($ver('pomodoro_barra.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    /* defer su tutt'e due, e in quest'ordine: partono a pagina costruita e
       nella sequenza in cui sono scritti, quindi il motore esiste gia'
       quando la barra lo cerca, e il markup esiste gia' quando lo cerca il
       JavaScript. */

    $json = json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    /* Le etichette si preparano prima: dentro un heredoc si possono
       inserire variabili, non chiamate a funzione. */
    $lApri       = htmlspecialchars(t('pomodoro.apri_comandi'), ENT_QUOTES, 'UTF-8');
    $lVista      = htmlspecialchars(t('pomodoro.cambia_vista'), ENT_QUOTES, 'UTF-8');
    $lCongela    = htmlspecialchars(t('pomodoro.stop'), ENT_QUOTES, 'UTF-8');
    $lRiprendi   = htmlspecialchars(t('pomodoro.riprendi'), ENT_QUOTES, 'UTF-8');
    $lNuovo      = htmlspecialchars(t('pomodoro.nuovo_pomodoro'), ENT_QUOTES, 'UTF-8');
    $lPausa      = htmlspecialchars(t('pomodoro.pausa'), ENT_QUOTES, 'UTF-8');
    $lContinua   = htmlspecialchars(t('pomodoro.scelta_continua'), ENT_QUOTES, 'UTF-8');
    $lInterrompi = htmlspecialchars(t('pomodoro.interrompi'), ENT_QUOTES, 'UTF-8');

    /* Le icone sono le stesse della pagina pomodoro. Non e' una questione
       di gusto: se il triangolo li' vuol dire "parti", qui non puo' voler
       dire nient'altro, altrimenti sono due vocabolari da imparare. */
    $icPlay   = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.4 5.2a1 1 0 0 1 1.53-.85l8.2 5.15a1 1 0 0 1 0 1.7l-8.2 5.15a1 1 0 0 1-1.53-.85z" transform="translate(-1.2 1)"/></svg>';
    $icPausa  = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6.5" y="5" width="4" height="14" rx="1.6"/><rect x="13.5" y="5" width="4" height="14" rx="1.6"/></svg>';
    $icStop   = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.4"/></svg>';
    $icTazza  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 10.5h2.2a2.4 2.4 0 0 1 0 4.8H16"/><path d="M8 2.5v2.6M12 2.5v2.6"/></svg>';
    $icPiu    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    $icVetro  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M6 2v3.3c0 .6.24 1.2.66 1.6L12 12l5.34-5.1c.42-.4.66-1 .66-1.6V2"/><path d="M18 22v-3.3c0-.6-.24-1.2-.66-1.6L12 12l-5.34 5.1c-.42.4-.66 1-.66 1.6V22"/></svg>';
    $icGiu    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9.5l6 6 6-6"/></svg>';

    $body = <<<HTML

<!-- ============ BARRA DEL POMODORO (innestata da config.php) ============ -->
<div class="pomo-bar" id="pomoBarra" role="button" tabindex="0" aria-label="{$lApri}">
    <span class="pomo-bar-velo"  id="pomoBarraVelo"  aria-hidden="true"></span>
    <span class="pomo-bar-linea" id="pomoBarraLinea" aria-hidden="true"></span>
    <span class="pomo-bar-punto" aria-hidden="true"></span>
    <span class="pomo-bar-testo" id="pomoBarraTesto"></span>
    <span class="pomo-bar-freccia" aria-hidden="true">{$icGiu}</span>
</div>

<div class="pomo-pop-velo" id="pomoPopVelo"></div>

<div class="pomo-pop" id="pomoPop" role="dialog" aria-label="Pomodoro">
    <button type="button" class="pomo-pop-vista" id="pomoPopVista" aria-label="{$lVista}">{$icVetro}</button>

    <div class="pomo-pop-disco">
        <svg viewBox="0 0 96 96" aria-hidden="true">
            <circle class="pomo-pop-pista" cx="48" cy="48" r="44"/>
            <circle class="pomo-pop-arco" id="pomoPopArco" cx="48" cy="48" r="44"
                    stroke-dasharray="276.5" stroke-dashoffset="276.5"/>
        </svg>
        <div class="pomo-pop-ora" id="pomoPopOra">--:--</div>
    </div>

    <!-- il progetto e' un tasto, non un'etichetta: da qui lo si cambia -->
    <button type="button" class="pomo-pop-prog" id="pomoPopProg">
        <span class="pomo-pop-prog-punto" aria-hidden="true"></span>
        <span class="pomo-pop-prog-testo" id="pomoPopProgTesto"></span>
    </button>
    <div class="pomo-pop-extra" id="pomoPopExtra"></div>

    <div class="pomo-pop-tasti">
        <button type="button" class="pomo-pop-tasto" id="pomoPopCongela"  hidden aria-label="{$lCongela}"      title="{$lCongela}">{$icPausa}</button>
        <button type="button" class="pomo-pop-tasto is-primario" id="pomoPopRiprendi" hidden aria-label="{$lRiprendi}" title="{$lRiprendi}">{$icPlay}</button>
        <button type="button" class="pomo-pop-tasto is-primario" id="pomoPopNuovo"    hidden aria-label="{$lNuovo}" title="{$lNuovo}">{$icPlay}</button>
        <button type="button" class="pomo-pop-tasto" id="pomoPopTisana"   hidden aria-label="{$lPausa}"     title="{$lPausa}">{$icTazza}</button>
        <button type="button" class="pomo-pop-tasto" id="pomoPopExtraBtn" hidden aria-label="{$lContinua}" title="{$lContinua}">{$icPiu}</button>
        <button type="button" class="pomo-pop-tasto" id="pomoPopChiudi"   hidden aria-label="{$lInterrompi}" title="{$lInterrompi}">{$icStop}</button>
    </div>
</div>

<script type="application/json" id="pomo-barra-dati">{$json}</script>

HTML;

    return ['head' => $head, 'body' => $body];
}
