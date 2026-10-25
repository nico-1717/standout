<?php
/* =====================================================================
   STANDOUT — LA BARRA DEL POMODORO (componente)

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

    /* Dentro una cornice la barra non ci va. Chi apre l'iframe e' una pagina
       del sito che la barra ce l'ha gia': qui dentro sarebbe la stessa barra
       due volte, a un centimetro di distanza, con due orologi che possono
       anche non dire lo stesso secondo. E non e' solo una ripetizione: la
       barra scrive padding-top sul <body> del documento incorniciato
       (html.pomo-bar-attiva in pomodoro_barra.css), e in un popup a tutta
       altezza come quello della modifica carta (fc_edit_popup.js) quel
       padding stacca il modulo dal bordo alto del pannello — si vede subito,
       e' la fascia verde sopra la barra "Edit" nello screenshot.

       Sec-Fetch-Dest lo manda il browser da solo su ogni richiesta e vale
       'iframe' quando la pagina viene caricata dentro una cornice. Chi non
       lo manda (browser vecchi) e' coperto da fc_popup=1, che fc_edit_popup.js
       aggiunge all'indirizzo; STANDOUT_SENZA_BARRA_POMODORO resta come
       interruttore per una pagina che voglia dire di no per conto proprio.

       Uscendo di qui prima di leggere lo stato si risparmia anche la lettura
       dal database: dentro un iframe non serve a nessuno. */
    $dove = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    if ($dove === 'iframe' || $dove === 'frame'
        || !empty($_GET['fc_popup'])
        || defined('STANDOUT_SENZA_BARRA_POMODORO')) return $vuoto;

    $file = __DIR__ . '/pomodoro_stato.php';
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
        return $base . 'projects/' . $nome . '?v=' . (@filemtime($p) ?: 1);
    };

    /* Le stringhe viaggiano nei dati e non passano da standout_i18n_json:
       ogni pagina dichiara i propri gruppi di traduzioni, e la barra non
       puo' pretendere che ventisette pagine si ricordino di aggiungere il
       gruppo 'pomodoro'. Sono sei parole, se le porta dietro. */
    /* Una chiave che il dizionario potrebbe non avere ancora: meglio la parola
       giusta in italiano che «<<pomodoro.scarta>>» stampato su un tasto. Appena
       la voce esiste nel dizionario, questo ripiego smette da solo di servire. */
    $tt = static function (string $chiave, string $ripiego): string {
        $v = function_exists('t') ? (string)t($chiave) : '';
        return ($v === '' || strpos($v, '<<') === 0) ? $ripiego : $v;
    };

    $dati = [
        'stato'     => $stato,
        'endpoint'  => $base . 'projects/pomodoro.php',
        /* Dove si va toccando il quadrante del popup. E' lo stesso indirizzo
           dell'endpoint, ma scritto a parte: se un domani le azioni ajax si
           spostassero altrove, il posto dove si va a mano non deve seguirle. */
        'pagina'    => $base . 'projects/pomodoro.php',
        /* L'involucro di fetch che aggiunge il token alle scritture. La pagina
           pomodoro se lo carica da sola; le altre ventisette non e' detto, e
           senza token ogni tasto del popup riceve un rifiuto dal server e
           sembra semplicemente non funzionare. Il JS lo aggiunge solo se non
           c'e' gia', cosi' dove c'e' non viene avvolto due volte. */
        'fetchjs'   => $base . '_assets/js/standout_fetch.js?v=' . (@filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1),
        /* Il selettore dei progetti e' lo stesso di tutte le altre pagine, con
           la sua resa autonoma: se lo carica il popup al primo tocco, invece di
           finire incluso in ventisette pagine dove nessuno lo aprira' mai. */
        'selettore' => $base . 'projects/project_selector.php?' . http_build_query([
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
            /* Quello che il popup dice quando un tasto non ha avuto effetto.
               Prima non diceva niente: il tasto si premeva, il server rifiutava
               e la barra restava identica — indistinguibile da un tasto morto. */
            'non_riuscito'      => $tt('pomodoro.non_riuscito', 'Non ha funzionato, riprova'),
            'scartata'          => $tt('pomodoro.sessione_scartata', 'Sessione scartata'),
            'scarta_conferma'   => $tt('pomodoro.scarta_conferma', 'Tocca di nuovo per scartare'),
            /* la spia delle azioni fatte senza rete (pomodoro_core.js, la coda) */
            'coda_offline'      => $tt('pomodoro.coda_offline_breve', 'Senza rete'),
            'coda_invio'        => $tt('pomodoro.coda_invio', 'Sto salvando…'),
        ],
    ];

    /* LO SPAZIO SI FA PRIMA CHE LA PAGINA SI VEDA.
       La classe la metteva il JavaScript, che parte a pagina costruita: il
       primo disegno avveniva senza, poi arrivava il padding e TUTTA la pagina
       scendeva di venti pixel sotto gli occhi. E' lo scatto che si vede a ogni
       apertura — non e' la barra che entra male, e' la pagina che si sposta
       dopo essere gia' comparsa.
       Qui la classe c'e' gia' nell'HTML che arriva dal server, e questo file
       viene richiamato solo quando la barra ci sara' davvero: quando dice di
       si', la barra c'e', quindi lo spazio si puo' prenotare senza il rischio
       di lasciarne venti pixer vuoti in cima a una pagina che non ne ha
       bisogno.
       In cima al <head>, prima del foglio di stile: la classe dev'esserci
       quando il browser calcola la prima impaginazione, non dopo. */
    $head = "\n" . '<script>document.documentElement.classList.add("pomo-bar-attiva");'
                  . 'document.documentElement.dataset.pomoPreacceso="1";</script>'
          . "\n" . '<link rel="stylesheet" href="' . htmlspecialchars($ver('pomodoro_barra.css'), ENT_QUOTES, 'UTF-8') . '">'
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
    $lScarta     = htmlspecialchars($tt('pomodoro.scarta', 'Scarta la sessione'), ENT_QUOTES, 'UTF-8');
    $lApriPagina = htmlspecialchars($tt('pomodoro.apri_pagina', 'Apri la pagina del pomodoro'), ENT_QUOTES, 'UTF-8');
    $dPagina     = htmlspecialchars($dati['pagina'], ENT_QUOTES, 'UTF-8');

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
    /* Il cestino: e' l'unico tasto del popup che cancella qualcosa per sempre,
       e si vede — il quadrato dello stop vuol dire "fermati e salva", questo
       vuol dire "come se non fosse mai successo". Due gesti diversi, due
       disegni diversi. */
    $icCestino = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6.5h16"/><path d="M9.5 6.5V5a1.5 1.5 0 0 1 1.5-1.5h2A1.5 1.5 0 0 1 14.5 5v1.5"/><path d="M6.5 6.5l.9 12a2 2 0 0 0 2 1.9h5.2a2 2 0 0 0 2-1.9l.9-12"/><path d="M10.5 10.5v6M13.5 10.5v6"/></svg>';

    $body = <<<HTML

<!-- ============ BARRA DEL POMODORO (innestata da config.php) ============ -->
<div class="pomo-bar" id="pomoBarra" role="button" tabindex="0" aria-label="{$lApri}">
    <span class="pomo-bar-velo"  id="pomoBarraVelo"  aria-hidden="true"></span>
    <span class="pomo-bar-linea" id="pomoBarraLinea" aria-hidden="true"></span>
    <span class="pomo-bar-punto" aria-hidden="true"></span>
    <span class="pomo-bar-testo" id="pomoBarraTesto"></span>
    <span class="pomo-bar-coda" id="pomoBarraCoda" role="status" hidden><span class="pomo-bar-giro" aria-hidden="true"></span><span class="pomo-bar-coda-t"></span></span>
    <span class="pomo-bar-freccia" aria-hidden="true">{$icGiu}</span>
</div>

<div class="pomo-pop-velo" id="pomoPopVelo"></div>

<div class="pomo-pop" id="pomoPop" role="dialog" aria-label="Pomodoro">
    <button type="button" class="pomo-pop-vista" id="pomoPopVista" aria-label="{$lVista}">{$icVetro}</button>

    <!-- Il quadrante e' un collegamento, non un disegno: toccando il tempo si
         va sulla pagina del pomodoro. E' un <a> con un indirizzo vero e non un
         div che ascolta i tocchi, cosi' funziona anche il tocco lungo per
         aprirlo in un'altra scheda, e chi usa il lettore di schermo sente che
         e' un collegamento invece di trovare un numero muto. -->
    <a class="pomo-pop-disco" id="pomoPopDisco" href="{$dPagina}" aria-label="{$lApriPagina}" title="{$lApriPagina}">
        <svg viewBox="0 0 96 96" aria-hidden="true">
            <circle class="pomo-pop-pista" cx="48" cy="48" r="44"/>
            <circle class="pomo-pop-arco" id="pomoPopArco" cx="48" cy="48" r="44"
                    stroke-dasharray="276.5" stroke-dashoffset="276.5"/>
        </svg>
        <div class="pomo-pop-ora" id="pomoPopOra">--:--</div>
    </a>

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
        <!-- Scartare non e' interrompere: la riga sparisce dal database e non
             conta piu' da nessuna parte. Sta in fondo, in rosso, e chiede
             conferma: e' l'unica cosa qui dentro che non si puo' disfare. -->
        <button type="button" class="pomo-pop-tasto is-pericolo" id="pomoPopScarta" hidden aria-label="{$lScarta}" title="{$lScarta}">{$icCestino}</button>
    </div>

    <!-- Quando un'azione non riesce lo si scrive qui. Il silenzio e' peggio:
         un tasto che non risponde e un tasto che non c'e' si assomigliano
         troppo, e non si capisce se rifare o smettere. -->
    <div class="pomo-pop-nota" id="pomoPopNota" role="status"></div>
</div>

<script type="application/json" id="pomo-barra-dati">{$json}</script>

HTML;

    return ['head' => $head, 'body' => $body];
}
