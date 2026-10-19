<?php

// connessione al database

require_once __DIR__ . '/../../lib/config.php';

require_once __DIR__ . '/../../lib/auth_check.php'; // 1. Protezione con login obbligatorio



$current_user_id = (int)$utente_corrente['id']; // 2. ID utente sicuro dal server



/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */

$conn = sec_db();

/* Studio o lingua: con ?course=N questa pagina inserisce PAROLE (parola,
   traduzione, significato, esempio, immagine) invece di domande e risposte.
   Righe, figli, riordino, salvataggio automatico ed esempi restano gli
   stessi; cambiano solo i campi di ogni riga. Vedi fc_type.php. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($current_user_id);
$fc_vocab = fc_is_vocab();



/* ============================================================

   IL SALVATAGGIO NON STA PIU' QUI

   ============================================================

   C'era un ricevitore "insert_bulk" che inseriva tutto in un colpo solo, alla

   fine. Adesso questa pagina salva quando vuole l'utente (o da sola, se

   l'interruttore e' acceso) e deve quindi saper anche AGGIORNARE una carta gia'

   inserita, non solo crearla: e' esattamente quello che fa fc_insert_save.php,

   con "append=1" perche' le carte nuove si accodino a quelle gia' presenti

   invece di rinumerarle da 1.



   Due copie della stessa logica - una che inserisce e una che aggiorna - vuol

   dire correggere ogni difetto in due posti e dimenticarsene in uno.



   Nel passaggio si sistemano anche due cose che qui si perdevano per strada:

   la pagina non mandava mai le immagini scelte sulla singola riga

   (row_q_img / row_a_img) ne' gli audio registrati, che il vecchio ricevitore

   pure si aspettava. Ora viaggiano insieme al resto.

   ============================================================ */



/* $resultCategories non e' usata da nessuna parte in questo file: l'elenco lo

   costruisce fc_deck_selector.php, incluso piu' sotto. La query resta perche'

   togliere una variabile che qualcuno potrebbe leggere da un include e' un

   rischio inutile - ma se un giorno si conferma che non serve, va via. */

[$fcW, $fcP] = fc_filtro_corso();
$resultCategories = sec_all("SELECT id, name FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW . " ORDER BY name",
                            array_merge([$current_user_id], $fcP));



// Categoria pre-selezionata (es. arrivo da fc_insert_card.php con ?category_id=123)

$preselected_cat_id = 0;

$preselected_cat_name = '';

if (isset($_GET['category_id'])) {

    $preselected_cat_id = sec_int($_GET['category_id'] ?? 0, 0);

    if ($preselected_cat_id > 0) {

        // Pre-seleziona la categoria SOLO se appartiene all'utente

        $pcRow = sec_one("SELECT name FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?" . $fcW,
                         array_merge([$preselected_cat_id, $current_user_id], $fcP));

        if ($pcRow) { $preselected_cat_name = $pcRow['name']; }

        else { $preselected_cat_id = 0; } // id non valido o di un altro utente: si ignora

    }

}

/* ============================================================
   MODALITA': INSERIMENTO oppure MODIFICA
   ============================================================
   Questa pagina fa due lavori che per mesi sono stati due file quasi identici
   (inserimento_big_fc e big_edit_fc), con la stessa logica scritta due volte:
   ogni correzione andava fatta in due posti, e regolarmente ne restava
   indietro uno.

   Adesso e' un file solo, e la modalita' cambia quattro cose:
     - all'apertura: in modifica si caricano le carte gia' nella categoria,
       in inserimento si parte da zero;
     - al salvataggio: 'append' vale 1 in inserimento (le carte nuove si
       accodano a quelle esistenti) e 0 in modifica (la pagina ha tutto in
       mano e puo' rinumerare);
     - il titolo della pagina;
     - l'eliminazione, che in modifica lascia il moncone ripristinabile.

   La modalita' si dichiara con ?modo=edit. Il valore passa da sec_enum, quindi
   qualunque altra cosa arrivi diventa 'insert': la modalita' piu' prudente,
   quella che non tocca carte gia' esistenti. */
$modo = sec_enum($_GET['modo'] ?? 'insert', ['insert', 'edit'], 'insert');

/* In modifica la categoria non e' facoltativa: senza, non c'e' niente da
   modificare. Si torna all'elenco invece di mostrare una pagina vuota che
   sembra un errore. */
if ($modo === 'edit' && $preselected_cat_id <= 0) {
    header('Location: ' . fc_url('fc_decks.php'));
    exit;
}

/* Le carte gia' presenti, solo in modifica. In inserimento resta un elenco
   vuoto: la pagina parte senza righe e il JavaScript non ha niente da
   disegnare. */
$cards = [];
if ($modo === 'edit') {
    // Le carte principali (padri o indipendenti)
    $padri = sec_all("SELECT " . fc_colonne_carta('i') . ", (SELECT COUNT(*) FROM " . fc_t('examples') . " e WHERE e.parent_id = i.id) AS ex_count,
                             0 AS is_child
                      FROM " . fc_t('cards') . " i WHERE i.category_id = ? ORDER BY i.custom_order ASC",
                     [$preselected_cat_id]);

    /* I figli con UNA query invece di una per padre: con cento concetti erano
       centouno viaggi al database, uno dietro l'altro. */
    $figliPer = [];
    if (!empty($padri)) {
        $ids = array_map(function ($r) { return (int)$r['id']; }, $padri);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (sec_all("SELECT " . fc_colonne_carta('c') . ", c.id_child AS id, 1 AS is_child,
                                 (SELECT COUNT(*) FROM " . fc_t('examples') . " e WHERE e.child_id = c.id_child) AS ex_count
                          FROM " . fc_t('subcards') . " c WHERE c.parent_id IN ($ph)
                          ORDER BY c.custom_order ASC", $ids) as $child) {
            $figliPer[(int)$child['parent_id']][] = $child;
        }
    }

    foreach ($padri as $row) {
        $row['id_fcimg'] = $row['id'];
        $cards[] = $row;
        foreach ($figliPer[(int)$row['id']] ?? [] as $child) {
            $child['id_fcimg'] = $child['id'];
            $cards[] = $child;
        }
    }
}

?>



<!DOCTYPE html>







<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">







<head>



    <meta name="csrf-token" content="<?php echo sec_csrf_token(); ?>">

    <script src="../_assets/js/standout_fetch.js"></script>



    <meta charset="UTF-8">







    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">







    <meta name="mobile-web-app-capable" content="yes">







    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">







    <meta name="format-detection" content="telephone=no">







    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">







    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">







    <title><?php te('fc.ibfc_titolo'); ?></title>















    <?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>
    <?php echo fc_boot_json(); ?>

<?php if (!$fc_vocab): /* le parole sono testo semplice: niente formule, niente MathJax */ ?>
    <script>







        window.MathJax = {

        /* \cancel (e \bcancel, \xcancel) non fanno parte del pacchetto base
           di MathJax: senza caricare l'estensione che li definisce, MathJax
           li mostra in rosso come comando sconosciuto — ed e' esattamente
           quello che si vedeva. Il foglio di stile che allinea la barretta
           sul simbolo c'era gia', ma allineava una cosa che non veniva
           nemmeno disegnata. */
        loader: { load: ['[tex]/cancel'] },

        tex: {
            packages: { '[+]': ['cancel'] },








            inlineMath: [['$', '$'], ['\\(', '\\)']],







            displayMath: [['$$', '$$'], ['\\[', '\\]']]







        }







        };







    </script>







    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <?php endif; ?>















<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_insert.css?v=<?php echo @filemtime(__DIR__ . '/fc_insert.css') ?: 1; ?>">

    <link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_autosave_bar.css?v=<?php echo @filemtime(__DIR__ . '/fc_autosave_bar.css') ?: 1; ?>">













    <?php if ($fc_vocab): /* fc_parser e la barra di formattazione vivono solo nelle flashcard di studio */ ?>
    <script src="<?php echo PWA_BASE; ?>fc/fc_speak.js?v=<?php echo @filemtime(__DIR__ . '/fc_speak.js') ?: 1; ?>"></script>
    <?php else: ?>
    <script src="fc_parser.js"></script>
    <?php endif; ?>







</head>







<body class="<?php echo $fc_vocab ? 'fc-vocab' : 'fc-study'; ?>">

<?php /* La schermata di attesa. Sta subito dopo l'apertura del body, prima di
         qualunque altra cosa: cosi' c'e' gia' al primo disegno e copre il
         montaggio invece di arrivare dopo, quando il pasticcio si e' gia'
         visto. */ ?>
<div class="big-loading" id="bigLoading" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>
</div>
<script>
(function(){
    var velo = document.getElementById('bigLoading');
    if (!velo) return;
    var andato = false;
    function via(){
        if (andato) return;
        andato = true;
        velo.classList.add('via');
        setTimeout(function(){ if (velo.parentNode) velo.parentNode.removeChild(velo); }, 250);
    }

    /* setTimeout(0) DENTRO DOMContentLoaded, non subito: cosi' si finisce in
       coda a tutti gli altri ascoltatori dello stesso evento, che sono quelli
       che montano la pagina. Uscire prima vorrebbe dire togliere il velo
       giusto mentre il montaggio comincia. I due requestAnimationFrame
       aspettano che il primo disegno vero sia fatto. */
    document.addEventListener('DOMContentLoaded', function(){
        setTimeout(function(){
            requestAnimationFrame(function(){ requestAnimationFrame(via); });
        }, 0);
    });

    /* Rete di sicurezza doppia: se un errore in uno degli script fermasse il
       montaggio, il velo resterebbe li' per sempre e la pagina sarebbe
       inutilizzabile. Meglio una pagina a meta' che una pagina murata. */
    window.addEventListener('load', via);
    setTimeout(via, 6000);
})();
</script>







<?php include __DIR__ . '/../partials/sidebar.html'; ?>















<div id="parent-selection-banner">







    <span><?php te('fc.ibfc_tocca_padre'); ?></span>







    <button type="button" onclick="cancelChildSelection()"><?php te('fc.ibfc_annulla_x'); ?></button>







</div>















<header class="app-header">

    <?php /* Ritorno alla categoria su cui si sta lavorando. E' un link vero e
             non un window.history.back(): arrivando qui da un'altra pagina (o
             da un ricaricamento) la storia del browser porterebbe altrove,
             mentre la categoria e' sempre quella. L'id lo scrive il JS appena
             il selettore ne ha uno: prima di allora il link torna all'elenco
             delle categorie, che e' comunque il posto giusto. */ ?>
    <a class="hdr-back" id="hdrBack" href="<?php echo sec_h(fc_url('fc_view.php')); ?>" aria-label="<?php echo sec_h(t('fc.ibf_torna_categoria')); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>
    </a>

    <div class="hdr-text">







        <h1><?php te($modo === 'edit' ? 'vocab.vi_big_edit' : 'fc.ibfc_big_insert'); ?></h1>







        <?php /* In modifica il sottotitolo e' il nome della categoria: e' l'unico
                 modo di accorgersi al volo di essere finiti su quella sbagliata,
                 visto che il numero di righe non aiuta (qui i figli sono righe a
                 se' e sono sempre piu' di quelle che si contano altrove). */ ?>
        <div class="hdr-sub"><?php
            if ($modo === 'edit') echo sec_h($preselected_cat_name);
            elseif ($fc_vocab) echo '<img class="fcv-hdr-flag" src="' . sec_h(fc_lingua_bandiera(fc_corso()['learn_lang'])) . '" alt=""> '
                                  . sec_h(fc_lingua_nome(fc_corso()['learn_lang']));
            else te('fc.ibfc_flashcards_uni');
        ?></div>







    </div>







    <?php /* Modalita' compatta: riduce ogni carta a una riga sola (inizio
             domanda, inizio risposta) per riordinare piu' comodamente, sia
             trascinando sia con le freccette. E' un interruttore di sessione,
             non una preferenza salvata: e' una vista di lavoro per riordinare,
             non un modo diverso di vedere le carte in permanenza.
             Subito a sinistra del pulsante di salvataggio: sta con le altre
             azioni della pagina, non con la navigazione a sinistra. */ ?>
    <button type="button" class="hdr-icon-btn" id="btnModalitaCompatta"
            onclick="document.body.classList.toggle('is-compact'); this.classList.toggle('is-on', document.body.classList.contains('is-compact')); aggiornaAnteprimeCompatte();"
            aria-label="<?php echo sec_h(t('fc.ibf_modalita_compatta')); ?>"
            title="<?php echo sec_h(t('fc.ibf_modalita_compatta')); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 7h16M4 12h16M4 17h16"/>
        </svg>
    </button>

    <?php

    /* Salvataggio e interruttore dell'automatico: in alto, dove non ci finisce

       sopra la tastiera mentre si scrive. */

    include __DIR__ . '/fc_autosave_bar.php';

    ?>

    <?php /* fc.ibf_n_carte: chiave NUOVA, da aggiungere al dizionario insieme
             a fc.ibf_una_carta e fc.ibf_n_carte_plur (usate dal JS in
             updateRowCountChip). Sostituiscono le vecchie fc.ibf_n_righe /
             fc.ibf_una_riga / fc.ibf_n_righe_plur: quelle contavano ogni riga,
             figli compresi, e nella lingua inglese dicevano "rows" — un
             numero molto piu' alto di quante carte si stessero davvero
             scrivendo, e la parola sbagliata. Testo suggerito:
                it: 0 => "carte",       1 => "carta",  n>1 => "carte"
                en: 0 => "cards",       1 => "card",   n>1 => "cards" */ ?>
    <div class="hdr-chip" id="row-count-chip"><?php te('fc.ibf_n_carte', ['n' => 0]); ?></div>

</header>

<?php /* Popup "modifiche non salvate" per la freccia indietro. Riusa le
         classi .fcai-modale del pannello IA (gia' caricate su questa pagina)
         invece di scriverne di nuove: tre pulsanti invece di due, la stessa
         forma. */ ?>
<div class="fcai-modale" id="uscitaModale" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b><?php te('fc.ibf_uscire_titolo'); ?></b>
            <span><?php te('fc.ibf_uscire_testo'); ?></span>
        </div>
        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="uscitaAnnulla"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-ghost btn-sm" id="uscitaSenzaSalvare"><?php te('fc.ibf_esci_lo_stesso'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="uscitaSalva"><?php te('fc.ibf_salva_ed_esci'); ?></button>
        </div>
    </div>
</div>



<?php /* Visualizzatore immagine a tutto schermo. Si apre toccando la miniatura
         di un'immagine gia' scelta: prima quel tocco apriva subito il
         selettore file, proponendo di sostituirla senza nemmeno mostrarla
         grande. Ora la mostra, e da qui si decide se toglierla o cambiarla —
         invece che scoprirlo dopo aver gia' aperto la finestra di sistema. */ ?>
<div class="img-viewer" id="imgViewer" hidden>
    <div class="img-viewer-body">
        <button type="button" class="img-viewer-chiudi" id="imgViewerChiudi" aria-label="<?php echo sec_h(t('comune.chiudi')); ?>">×</button>
        <img id="imgViewerImg" src="" alt="<?php echo sec_h(t('fc.ibf_visualizza_immagine')); ?>">
        <div class="img-viewer-azioni">
            <button type="button" class="btn btn-ghost" id="imgViewerRimuovi"><?php te('comune.rimuovi'); ?></button>
            <button type="button" class="btn btn-primary" id="imgViewerSostituisci"><?php te('comune.sostituisci'); ?></button>
        </div>
    </div>
</div>

<?php if (!$fc_vocab): /* l'editor ricco serve solo alle domande e risposte di studio */ ?>
<div style="display: none;" id="editor-template-container">

    <?php 

        $ft_field = '%%FIELD_ID%%';

        $ft_raw = '%%RAW_TEXT%%';

        $ft_placeholder = '%%PLACEHOLDER%%';

        include __DIR__ . '/../partials/format_toolbar.php'; 

    ?>

</div>
<?php endif; ?>











<div class="page-wrap">















    <div id="success-msg">







        <?php th('fc.ibf_completato', ['n' => '<strong id="success-count">0</strong>']); ?>







        <small><?php te('vocab.ib_ritorno'); ?></small>







    </div>






























    <form id="imageForm">







        <div class="setup-grid">







            <div class="card-surface setup-card full">







                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span><?php te('vocab.ib_categoria_dest'); ?></div>







                <?php 







                    $cs_id = 'inserimento_big';







                    $cs_placeholder = T('vocab.ib_ph_seleziona');







                    $cs_selected_id = $preselected_cat_id;







                    $cs_selected_name = $preselected_cat_name;







                    include __DIR__ . '/fc_deck_selector.php'; 







                ?>







            </div>






















        </div>

        <?php /* Il pannello di inserimento (IA, immagini, importa) vive in un file suo:
           questa pagina e' gia' lunga 6900 righe, e ogni pezzo nuovo che nasce fuori
           e' un pezzo che si puo' cambiare senza riaprirla. */ ?>
        <?php include __DIR__ . '/fc_ai_panel.php'; ?>






























        <div class="fc-rows" id="fcRows"></div>







        <div class="empty-hint" id="emptyHint"><?php te('fc.ibfc_nessuna_riga'); ?></div>















        <?php /* Solo in modifica: le carte esistenti si disegnano a lotti, e da
                 qui si chiedono le successive. Nasce nascosto e lo accende
                 renderNextBatch() quando ne restano davvero. */ ?>
        <div id="loadMoreContainer" style="display:none; text-align:center; padding: 10px 14px 20px;">
            <button type="button" class="btn btn-ghost" style="width:auto; display:inline-flex; border:1.5px solid var(--hairline-2); font-size:14px; padding:10px 20px; box-shadow:var(--raise-sm);" onclick="renderNextBatch(20)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;margin-right:6px;"><path d="M6 9l6 6 6-6"/></svg>
                <?php te('fc.befc_carica_altre'); ?>
            </button>
        </div>

        <div class="action-stack">







            <button type="button" class="btn btn-primary" onclick="addSingleRow()">







                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>







                <?php te('fc.ibfc_aggiungi_riga'); ?>







            </button>







            <?php /* Il pulsante "salva tutto" che stava qui e' stato tolto: il
                     salvataggio vive nella barra in alto (fc_autosave_bar.php),
                     sempre visibile, mentre questo si raggiungeva solo dopo
                     aver scorso tutte le carte. Il form resta, perche' e' lui
                     a raccogliere i campi. */ ?>







        </div>







    </form>







</div>















<div class="confirm-overlay" id="confirmOverlay">







    <div class="confirm-panel">







        <h3 id="confirmTitle"><?php te('vocab.ib_sei_sicuro'); ?></h3>







        <p id="confirmMessage"></p>







        <div class="confirm-actions">







            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn"><?php te('comune.annulla'); ?></button>







            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn"><?php te('vocab.conferma_b'); ?></button>







        </div>







    </div>







</div>







<script>







    /* ============================================================







       ICONE INLINE







       ============================================================ */







    const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';







    const ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 14.5 14.5 9.5M8 17l-2 2a3.5 3.5 0 0 1-5-5l3-3a3.5 3.5 0 0 1 5 0M16 7l2-2a3.5 3.5 0 0 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></svg>';







    const ICON_UNLINK= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17H8a4 4 0 0 1 0-8h1M11 7h5a4 4 0 0 1 0 8h-1M4 4l16 16"/></svg>';







    const ICON_GRIP  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"/><circle cx="16" cy="6" r="1.6"/><circle cx="8" cy="12" r="1.6"/><circle cx="16" cy="12" r="1.6"/><circle cx="8" cy="18" r="1.6"/><circle cx="16" cy="18" r="1.6"/></svg>';







    const ICON_REORDER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m8 9 4-4 4 4M8 15l4 4 4-4"/></svg>';

    /* freccia che rientra: "figlio". Segno piu': "fratello". */

    const ICON_KID     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5v6a3 3 0 0 0 3 3h9"/><path d="m13 10 4 4-4 4"/></svg>';
    // Stessa forma usata nell'intestazione del pannello IA: e' il linguaggio visivo
    // che nel sito dice "questo pulsante chiama l'intelligenza artificiale".
    const ICON_TRASH_SM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';
    const ICON_MOVE_UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
    const ICON_MOVE_DOWN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    const ICON_UNDO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h11a5 5 0 0 1 0 10H9M4 10l5-5M4 10l5 5"/></svg>';
    const ICON_AUDIO_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    const ICON_MAGIC   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="vertical-align:-3px; margin-right:6px;"><path d="M9 2 11 7 16 9 11 11 9 16 7 11 2 9 7 7Z"/><path d="M18 3 19 5 21 6 19 7 18 9 17 7 15 6 17 5Z"/></svg>';

    const ICON_SIBLING = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';







    const ICON_SPEAK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.8 5.2a9.5 9.5 0 0 1 0 13.6"/></svg>';
    const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';







    const ICON_GEN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';







    const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';















    function escAttr(s){







        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');







    }















    /* ============================================================

       STATO GLOBALE

       ============================================================ */

    /* Il token di sessione viaggia insieme ai dati, cosi' il salvataggio non

       dipende da chi ha caricato standout_fetch.js. */

    const CSRF_TOKEN = <?php echo json_encode(sec_csrf_token()); ?>;

    /* La modalita' decide il comportamento della pagina: 'insert' parte
       vuota, 'edit' carica le carte gia' nella categoria. */
    const MODO = <?php echo json_encode($modo); ?>;

    /* Le carte gia' esistenti, vuote in inserimento. JSON_HEX_* le rende
       sicure da incollare dentro un blocco di script: senza, un titolo che
       contenesse un tag di chiusura lo interromperebbe a meta', e il resto
       della pagina finirebbe stampato come testo.
       (Nota per chi modifica questo commento: qui NON si deve scrivere per
       esteso quella sequenza di caratteri, nemmeno fra virgolette dentro un
       commento — il browser non legge i commenti, legge i tag, e chiuderebbe
       il blocco proprio qui. E' successo davvero.) */
    const INITIAL_CARDS = <?php echo json_encode($cards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    /* Flashcard di lingua: stesse righe, campi diversi (parola, traduzione,
       significato, esempio, un'immagine). FC lo scrive fc_boot_json(). */
    const FCV = !!(window.FC && window.FC.tipo === 'vocab');
    const FC_IMG_DIR = (window.FC && window.FC.immagini) || '../media/flashcard_images/';



    /* Questa pagina non e' piu' un modulo che si invia una volta sola: e' un

       elenco di carte che possono essere gia' nel database oppure no. Ogni riga

       porta con se' data-id (0 = mai salvata) e data-status. */

    let isDirty = false;

    /* Un riordino non modifica nessuna riga: cambia solo la loro posizione, e
       data-status resta 'clean' su tutte. Senza una spia a se', per la pagina
       non c'era niente da salvare — l'ordine nuovo restava a video e basta, e
       chiudendo la scheda spariva senza nemmeno l'avviso di beforeunload, che
       guarda la stessa isDirty. Si tiene separata invece di marcare 'edited'
       mezza pagina: quelle righe non sono cambiate davvero, e dirlo sarebbe
       falso anche per chi legge lo stato per altri motivi (la pastiglia degli
       esempi, il bordo colorato, le carte-titolo). */
    let ordineCambiato = false;

    /* Vero se la riga ha un segnale di cancellazione (audio o immagine) in
       sospeso — non e' contenuto da guardare, e' una richiesta al server che
       va comunque inviata: senza questo controllo, una riga il cui unico
       cambiamento e' "ho tolto l'audio gia' salvato" verrebbe considerata
       completamente vuota, e il segnale non arriverebbe mai al server —
       l'audio resterebbe li' per sempre nonostante l'interfaccia lo mostri
       rimosso. */
    function haSegnaleCancellazione(row){
        return row.dataset.clearAudioQ === '1' || row.dataset.clearAudioA === '1'
            || row.dataset.clearImageQ === '1' || row.dataset.clearImageA === '1';
    }

    /* Vero se in pagina c'e' davvero qualcosa da mandare al server: una riga
       nuova o modificata che non e' completamente vuota, una riga con un
       segnale di cancellazione in sospeso, oppure una riga eliminata che ha
       gia' un id vero (bisogna dirlo al server, altrimenti resta nel
       database). Una carta vuota di tutto — nessun testo, nessuna immagine,
       nessun audio — non conta MAI, anche se e' stata "toccata": non c'e'
       niente che il server debba fare per lei, e farla scattare vorrebbe dire
       un salvataggio automatico che parte per non salvare nulla. */
    function cSonoModificheDaSalvare(){
        /* L'ordine e' cambiato: c'e' da salvare — ma solo se in pagina c'e'
           almeno una carta vera. Riordinare due righe vuote non e' lavoro da
           conservare, ed e' la stessa regola che vale per il testo qui sotto:
           un salvataggio automatico non deve partire per non salvare nulla. */
        if (ordineCambiato && Array.from(righeVive()).some(function(row){ return !rigaCompletamenteVuota(row); })) return true;

        return Array.from(document.querySelectorAll('#fcRows .fc-row')).some(function(row){
            if (row.dataset.status === 'clean') return false;
            if (row.dataset.status === 'deleted') return (parseInt(row.dataset.id, 10) || 0) > 0;
            return !rigaCompletamenteVuota(row) || haSegnaleCancellazione(row);
        });
    }

    function markDirty(){

        isDirty = cSonoModificheDaSalvare();

        if (!window.StandoutSaveBar) return;

        if (isDirty) {
            StandoutSaveBar.touch();
        } else {
            /* Tutto e' tornato vuoto o pulito: se un salvataggio automatico
               era gia' in coda (si era scritto qualcosa e poi cancellato tutto
               prima che scattasse), va tolto di mezzo — altrimenti partirebbe
               comunque, per andare a salvare il nulla. discard() non fa danni
               nemmeno quando non c'era niente da togliere: e' gia' pensata
               per essere chiamata anche a vuoto. */
            StandoutSaveBar.discard();
        }

    }

    /* Una riga cancellata ma gia' presente nel database resta qui, nascosta,

       finche' il salvataggio non l'ha davvero eliminata: se sparisse subito il

       server non saprebbe mai che c'era qualcosa da togliere. */

    function righeVive(){ return document.querySelectorAll('#fcRows .fc-row:not([data-status="deleted"])'); }
    /* Una carta e' un CONCETTO: il figlio e' un esempio che le appartiene, non
       un'altra carta a se stante. Contare tutte le .fc-row insieme (come fa
       righeVive, giustamente, per "c'e' almeno qualcosa da salvare?") dava un
       numero molto piu' alto di quante carte si stessero davvero scrivendo,
       perche' ogni figlio si sommava al totale come se fosse una carta in
       piu'. Qui serve l'altro conto: solo le righe di primo livello. */
    function carteVive(){ return document.querySelectorAll('#fcRows .fc-row:not([data-status="deleted"]):not(.is-child)'); }

    function onRowChange(el){

        const row = (el && el.closest) ? el.closest('.fc-row') : null;

        if (row && row.dataset.status === 'clean') {

            row.dataset.status = 'edited';

            /* Solo quando lo stato CAMBIA davvero: chiamarla a ogni tasto
               premuto rifarebbe lo stesso lavoro centinaia di volte mentre si
               scrive, per un risultato gia' ottenuto al primo carattere. */

            updateStatusVisual(row);

        }

        markDirty();

        aggiornaRigheTitolo();

    }

    /* ============================================================
       CARTE TITOLO
       ============================================================
       Un padre con almeno due figli e nessuna risposta (ne' testo, ne'
       immagine, ne' audio) diventa automaticamente un titolo: in gioco non
       sara' una domanda a cui rispondere, solo un separatore che introduce i
       suoi figli. Non serve un interruttore da spuntare a mano ne' una
       colonna nuova nel database: e' calcolato ogni volta da quello che c'e'
       gia' in tabella, e basta scrivere una risposta per farla tornare una
       carta normale — e' per questo che si ricalcola a ogni tocco, non una
       volta sola quando la riga nasce. */

    /* Testo vero contenuto in un documento serializzato (JSON a blocchi o
       vecchi tag): un documento "vuoto" non e' quasi mai una stringa vuota,
       e' un JSON valido con dentro un paragrafo senza testo. Separata da
       testoRigaPer perche' serve anche PRIMA che la riga esista nel DOM — al
       momento di costruirne per la prima volta il markup, in appendRowHTML. */
    function fcdTestoVero(raw) {
        if (!raw) return '';
        if (typeof FCV !== 'undefined' && FCV) return String(raw).trim();
        try {
            const doc = fcdParse(raw);
            return (doc.blocks || []).map(fcdBlockText).join('').trim();
        } catch (e) {
            return String(raw).trim();
        }
    }

    /* Il valore cosi' com'e' salvato — JSON a blocchi o vecchi tag — senza
       ridurlo a solo testo. Separata da testoRigaPer perche' l'anteprima
       compatta ne ha bisogno intatto: le formule dentro vanno tipografate,
       non lette come stringa. */
    function valoreGrezzoRiga(row, prefix) {
        const el = document.getElementById(prefix + '-desc-' + row.dataset.rid);
        let v = el ? el.value : '';
        if (!v) {
            const ed = document.getElementById(prefix + '-desc-' + row.dataset.rid + '_editor');
            if (ed) v = ed.value || ed.innerText || '';
        }
        return v;
    }

    function testoRigaPer(row, prefix) {
        return fcdTestoVero(valoreGrezzoRiga(row, prefix));
    }

    function contaFigli(rigaPadre) {
        let n = 0, sib = rigaPadre.nextElementSibling;
        while (sib && sib.classList && sib.classList.contains('is-child')) { n++; sib = sib.nextElementSibling; }
        return n;
    }

    /* Vero se il lato "prefix" (q oppure a) di una riga non ha ne' testo, ne'
       immagine, ne' audio. rispostaDavveroVuota() e' rimasta col suo nome
       perche' e' gia' usata altrove (le carte titolo guardano solo la
       risposta); rigaCompletamenteVuota() controlla entrambi i lati, per
       decidere se la riga intera vale la pena di essere salvata. */
    function campoDavveroVuoto(row, prefix) {
        if (testoRigaPer(row, prefix) !== '') return false;
        if (row[prefix + 'NewImgFile']) return false;
        const nomeImg = row.querySelector('.' + prefix + '-img-name');
        if (nomeImg && nomeImg.value) return false;
        if (row[prefix + 'AudioBlob']) return false;
        const player = row.querySelector('.audio-player-' + prefix);
        if (player && (player.classList.contains('has-audio') || player.src)) return false;
        return true;
    }
    function rispostaDavveroVuota(row) { return campoDavveroVuoto(row, 'a'); }
    function rigaCompletamenteVuota(row) { return campoDavveroVuoto(row, 'q') && campoDavveroVuoto(row, 'a'); }

    /* Nonostante il nome, da qui in poi fa piu' cose correlate: lo stato
       titolo (solo sui padri) e il testo del pulsante IA di ogni riga.
       L'anteprima compatta (con le formule tipografate) e' separata in
       aggiornaAnteprimeCompatte(): costa di piu' — chiama MathJax — e non ha
       senso rifarla a ogni tasto premuto quando la modalita' compatta non e'
       nemmeno aperta; questa funzione qui gira invece a ogni cambiamento,
       anche a compatta spenta, perche' il resto che fa e' economico. */
    function aggiornaRigheTitolo() {
        document.querySelectorAll('#fcRows .fc-row').forEach(function (row) {
            const vuota = rispostaDavveroVuota(row);
            const btnIA = row.querySelector('.btn-ia-riga-testo');
            if (btnIA) btnIA.textContent = vuota ? T('fc.ibfai_sec_ia_titolo') : T('fc.ibfai_migliora_con_ia_btn');
        });

        document.querySelectorAll('#fcRows .fc-row:not(.is-child)').forEach(function (row) {
            const titolo = contaFigli(row) >= 2 && rispostaDavveroVuota(row);
            row.classList.toggle('is-title-row', titolo);

            const etichettaQ = row.querySelector('.fc-field .field-label:not(.is-answer)');
            if (!etichettaQ) return;
            let badge = etichettaQ.querySelector('.titolo-badge');
            if (titolo && !badge) {
                badge = document.createElement('span');
                badge.className = 'titolo-badge';
                badge.textContent = T('fc.ibf_titolo_badge');
                etichettaQ.appendChild(badge);
            } else if (!titolo && badge) {
                badge.remove();
            }
        });

        aggiornaAnteprimeCompatte();
    }

    /* Il solo INIZIO di un campo, come HTML — non testo tagliato a
       caratteri. Tagliare la stringa avrebbe potuto spezzare una formula a
       meta': si prende il primo blocco INTERO del documento (il primo
       paragrafo, o la prima formula se il campo comincia con una).

       Le formule qui NON si tipografano mai, nemmeno le piu' semplici: si
       era provato a farlo (prima solo scartando frazioni/sommatorie/
       integrali, poi anche quello si e' rivelato insufficiente), ma anche
       una formula senza niente di impilato — "cov(X, Y+Z)", niente
       frazioni, niente sommatorie — si spezzava lo stesso. Le parentesi del
       carattere matematico corsivo che MathJax usa sono da sole spesso piu'
       alte del testo attorno, e un'altezza fissata a priori per stare su
       una riga sola non ha margine per questo: prima o poi taglia qualcosa,
       qualunque soglia le si dia. Si mostra quindi sempre il TeX grezzo,
       leggibile per intero — la formula vera resta disegnata nella vista
       normale, dove lo spazio non e' vincolato (fieldTemplate, non questa
       funzione).
       Il resto — testo, grassetto, colori — passa da fcdRunHTML come
       sempre: solo le formule prendono questa strada diversa. Il CSS poi
       tronca visivamente cio' che comunque non ci sta su una riga sola. */
    function inizioCampoHTML(raw) {
        if (!raw) return '';
        if (FCV) return escAttr(String(raw).trim());
        try {
            const doc = fcdParse(raw);
            const blocchi = doc.blocks || [];
            const primo = blocchi.find(function (b) {
                return b.t === 'fx' ? String(b.tex || '').trim() !== '' : fcdBlockText(b).trim() !== '';
            });
            if (!primo) return '';

            if (primo.t === 'fx') return fcdEscape(String(primo.tex || ''));

            return (primo.runs || []).map(function (r) {
                return typeof r.x === 'string' ? fcdEscape(r.x) : fcdRunHTML(r);
            }).join('');
        } catch (e) {
            return '';
        }
    }

    /* Riempie l'anteprima compatta di ogni riga con l'inizio vero. Nessuna
       formula da tipografare qui (vedi il commento su inizioCampoHTML), e
       quindi nessuna chiamata a MathJax: e' la ragione per cui questa
       funzione puo' restare economica anche girando spesso.
       Non fa NIENTE se la modalita' compatta non e' aperta: e' l'unica
       parte di aggiornaRigheTitolo che tocca il DOM di ogni riga per
       intero, e non ha senso rifare quel lavoro quando non si vede. */
    function aggiornaAnteprimeCompatte() {
        if (!document.body.classList.contains('is-compact')) return;

        document.querySelectorAll('#fcRows .fc-row').forEach(function (row) {
            const compQ = row.querySelector('.compact-q');
            const compA = row.querySelector('.compact-a');
            if (compQ) {
                const htmlQ = inizioCampoHTML(valoreGrezzoRiga(row, 'q'));
                if (htmlQ) compQ.innerHTML = htmlQ;
                else compQ.textContent = T('fc.ibf_vuota_riga');
            }
            if (compA) {
                const htmlA = inizioCampoHTML(valoreGrezzoRiga(row, 'a'));
                compA.hidden = (htmlA === '');
                if (htmlA) compA.innerHTML = htmlA;
            }
        });
    }



    /* ---- CHIP ESEMPI ----

       Un esempio si aggancia a una carta tramite il suo id, e l'id lo assegna

       il database: finche' la carta non e' stata salvata il pulsante resta

       spento. Appena il salvataggio le da' un id diventa quello vero, ed e'

       per questo che il salvataggio incrementale serviva anche qui.

       Testo e colori sono gli stessi che usa fc_examples_modal.php quando

       riscrive il pulsante dopo aver aggiunto un esempio. */

    function exChipHTML(domId, dbId, isChild, isNew, exCount){

        if (isNew){

            return `<button type="button" class="ex-chip ex-disabled" style="background:var(--surface-3); color:var(--text-3); border:1.5px solid transparent;"

                onclick="showToast(T('fc.ifc_salva_prima'))"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> ${T('fc.befc_esempi')}</button>`;

        }

        const hasEx = exCount > 0;

        const bg = hasEx ? 'var(--purple, #AF52DE)' : '#f1f3f5';

        const color = hasEx ? '#fff' : '#555';

        const border = hasEx ? 'var(--purple, #AF52DE)' : '#ccc';

        const icon = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>';

        const label = hasEx ? `${icon} ${T('fc.exm_conteggio', {n: exCount})}` : T('fc.exm_aggiungi');

        const tipo = isChild ? 'child' : 'parent';

        // Padre e figlio hanno sequenze di id indipendenti: il prefisso p/c
        // nell'id del bottone li tiene distinti anche se condividono per
        // caso lo stesso numero (prima lo faceva il segno di dbId, che ora
        // e' sempre l'id vero e positivo della riga).
        return `<button type="button" id="btn-ex-${tipo[0]}${dbId}" class="ex-chip" style="background:${bg}; color:${color}; border:1.5px solid ${border};"

            onclick="openExamplesModal(${dbId}, '${tipo}', document.getElementById('q-desc-${domId}').value, document.getElementById('a-desc-${domId}').value)">${label}</button>`;

    }

    function aggiornaExChip(row, dbId, quanti){

        const vecchio = row.querySelector('.ex-chip');

        if (!vecchio) return;

        const contenitore = document.createElement('div');

        contenitore.innerHTML = exChipHTML(row.dataset.rid, dbId, isChildRow(row), false, quanti || 0);

        const nuovo = contenitore.firstElementChild;

        if (nuovo) vecchio.replaceWith(nuovo);

    }



    let isSelectingParent = false;







    let childRowPending = null;







    let gestureBlock = false;







    let activeDrag = null;







    let autoScrollRAF = null;







    let lastPointerY = 0;







    let suppressNextRowClick = false;















    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }







    function isChildRow(row){ return row.classList.contains('is-child'); }







    function getBlockStart(row){ let r = row; while (r && isChildRow(r)) r = r.previousElementSibling; return r; }







    /* ============================================================
       RIORDINO A FRECCETTE — un passo alla volta, oltre al trascinamento
       ============================================================
       Spostare una carta di un solo posto col dito su un elenco lungo
       richiede precisione che il trascinamento non da' facilmente: le
       freccette muovono di un passo netto, senza rischio di lasciarla nel
       posto sbagliato per un tremore della mano. Compaiono solo in modalita'
       compatta, che e' la vista pensata apposta per riordinare.

       Un padre si sposta insieme a tutto il suo blocco di figli: non ha
       senso spostare solo la domanda lasciando le sue sotto-carte indietro.
       Un figlio invece si scambia solo con un fratello dello stesso padre —
       mai con quelli di un altro padre, mai scavalcando il padre stesso. */

    /** Il padre del blocco immediatamente precedente, o null se questo e'
        gia' il primo. */
    function trovaPadrePrecedente(row){
        let cur = row.previousElementSibling;
        while (cur && isChildRow(cur)) cur = cur.previousElementSibling;
        return cur;
    }

    /** Il padre del blocco immediatamente successivo, o null se questo e'
        gia' l'ultimo. */
    function trovaProssimoPadre(row){
        const blocco = getBlockRows(row);
        const cur = blocco[blocco.length - 1].nextElementSibling;
        return (cur && !isChildRow(cur)) ? cur : null;
    }

    function spostaPadreSu(row){
        const padrePrec = trovaPadrePrecedente(row);
        if (!padrePrec) return;
        const blocco = getBlockRows(row);
        const list = document.getElementById('fcRows');
        flip(list, function(){ blocco.forEach(r=> list.insertBefore(r, padrePrec)); });
        dopoRiordino();
    }

    function spostaPadreGiu(row){
        const prossimoPadre = trovaProssimoPadre(row);
        if (!prossimoPadre) return;
        const bloccoProssimo = getBlockRows(prossimoPadre);
        // dopo l'INTERO blocco successivo, non solo dopo il suo padre: altrimenti
        // ci si infilerebbe fra il prossimo padre e i suoi stessi figli
        const riferimento = bloccoProssimo[bloccoProssimo.length - 1].nextElementSibling;
        const blocco = getBlockRows(row);
        const list = document.getElementById('fcRows');
        flip(list, function(){
            blocco.forEach(function(r){
                if (riferimento) list.insertBefore(r, riferimento); else list.appendChild(r);
            });
        });
        dopoRiordino();
    }

    function spostaFiglioSu(row){
        const prec = row.previousElementSibling;
        if (!prec || !isChildRow(prec)) return;   // il precedente e' il padre, o non c'e': gia' il primo figlio
        const list = document.getElementById('fcRows');
        flip(list, function(){ list.insertBefore(row, prec); });
        dopoRiordino();
    }

    function spostaFiglioGiu(row){
        const succ = row.nextElementSibling;
        if (!succ || !isChildRow(succ)) return;   // gia' l'ultimo figlio del blocco
        const list = document.getElementById('fcRows');
        flip(list, function(){ list.insertBefore(succ, row); });
        dopoRiordino();
    }

    /** Punto d'ingresso unico dal markup: direzione -1 su, +1 giu'. Sceglie da
        solo se la riga e' un padre o un figlio. */
    function spostaRiga(btn, direzione){
        const row = btn.closest('.fc-row');
        if (!row) return;
        if (isChildRow(row)) { if (direzione < 0) spostaFiglioSu(row); else spostaFiglioGiu(row); }
        else                 { if (direzione < 0) spostaPadreSu(row);  else spostaPadreGiu(row);  }
    }

    /** Mostra o nasconde le freccette secondo la posizione: prima riga senza
        su, ultima senza giu', e lo stesso per ogni famiglia di figli. Va
        ricalcolata dopo QUALUNQUE cosa possa aver cambiato l'ordine o la
        composizione delle righe — non solo dopo uno spostamento. */
    function aggiornaFrecceRiordino(){
        document.querySelectorAll('#fcRows .fc-row').forEach(function(row){
            const su = row.querySelector('.btn-move-up');
            const giu = row.querySelector('.btn-move-down');
            if (!su || !giu) return;
            if (isChildRow(row)) {
                su.hidden = !(row.previousElementSibling && isChildRow(row.previousElementSibling));
                giu.hidden = !(row.nextElementSibling && isChildRow(row.nextElementSibling));
            } else {
                su.hidden = !trovaPadrePrecedente(row);
                giu.hidden = !trovaProssimoPadre(row);
            }
        });
    }

    /** Tutto cio' che deve succedere dopo un riordino, che sia partito dalle
        freccette o dal trascinamento: le due strade non devono avere
        conseguenze diverse solo perche' una e' piu' vecchia dell'altra. */
    function dopoRiordino(){
        /* Prima di markDirty(), che legge questa spia per decidere se c'e'
           qualcosa da salvare. */
        ordineCambiato = true;
        markDirty();
        aggiornaFrecceRiordino();
        if (typeof aggiornaRigheTitolo === 'function') aggiornaRigheTitolo();
    }

    function getBlockRows(parentRow){







        const rows = [parentRow];







        let cur = parentRow.nextElementSibling;







        while (cur && isChildRow(cur)) { rows.push(cur); cur = cur.nextElementSibling; }







        return rows;







    }















    /* ============================================================







       TOAST & MODALE CONFERMA







       ============================================================ */







    function showToast(msg, kind){







        const t = document.createElement('div');







        t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');







        t.textContent = msg;







        document.body.appendChild(t);







        requestAnimationFrame(()=> t.classList.add('show'));







        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=> t.remove(), 320); }, 3200);







    }















    function showConfirm(title, message, okLabel){







        return new Promise(resolve=>{







            const overlay = document.getElementById('confirmOverlay');







            document.getElementById('confirmTitle').textContent = title;







            document.getElementById('confirmMessage').textContent = message;







            const okBtn = document.getElementById('confirmOkBtn');







            const cancelBtn = document.getElementById('confirmCancelBtn');







            okBtn.textContent = okLabel || T('vocab.conferma_b');







            overlay.classList.add('show');







            function cleanup(result){







                overlay.classList.remove('show');







                okBtn.removeEventListener('click', onOk);







                cancelBtn.removeEventListener('click', onCancel);







                resolve(result);







            }







            function onOk(){ cleanup(true); }







            function onCancel(){ cleanup(false); }







            okBtn.addEventListener('click', onOk);







            cancelBtn.addEventListener('click', onCancel);







        });







    }















    /* ============================================================







       FLIP — animazioni fluide su ogni riordino del DOM







       ============================================================ */







    function flip(container, mutate){







        const rows = Array.from(container.children);







        const first = new Map();







        rows.forEach(r=> first.set(r, r.getBoundingClientRect()));







        mutate();







        Array.from(container.children).forEach(r=>{







            const f = first.get(r);







            if (!f) return;







            const l = r.getBoundingClientRect();







            const dx = f.left - l.left, dy = f.top - l.top;







            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5){







                r.style.transition = 'none';







                r.style.transform = `translate(${dx}px, ${dy}px)`;







                requestAnimationFrame(()=>{







                    r.style.transition = 'transform .38s var(--spring)';







                    r.style.transform = '';







                });







            }







        });







    }















    /* ============================================================







       CONTATORI / STATO PULSANTI







       ============================================================ */







    function updateRowCountChip(){

        // Conta padri e figli insieme (righeVive), non solo i concetti di
        // primo livello (carteVive): "carte" qui vuol dire ogni scheda vera
        // e propria che verra' scritta nel database, figli compresi.
        const n = righeVive().length;

        const chip = document.getElementById('row-count-chip');

        /* fc.ibf_una_carta / fc.ibf_n_carte_plur: chiavi NUOVE, ancora da
           aggiungere al dizionario -- vedi la nota accanto al tag
           <div id="row-count-chip"> per il testo esatto da mettere in
           italiano e in inglese. Finche' non ci sono, T() restituisce la
           chiave stessa: il controllo sotto lo intercetta e mostra solo il
           numero, invece della chiave grezza o della vecchia parola
           "righe"/"rows" rimasta in mezzo al nuovo testo. */
        const chiave = n === 1 ? 'fc.ibf_una_carta' : 'fc.ibf_n_carte_plur';
        const etichetta = T(chiave, {n: n});
        /* Quando la chiave non c'e' ancora nel dizionario, T() non restituisce
           la chiave nuda: la incornicia, "<<fc.ibf_n_carte_plur>>". Il
           controllo di prima cercava l'uguaglianza esatta con la chiave e non
           la riconosceva, cosi' quella cornice finiva dritta nell'etichetta
           (era il "0<<fc.ibf_n_carte_plur>>" che si vedeva). Si riconosce
           dalle cornici, non dall'uguaglianza. */
        const mancante = !etichetta || etichetta === chiave || /^<<.*>>$/.test(etichetta);
        if (chip) chip.textContent = mancante ? String(n) : etichetta;








    }







    function toggleEmptyHint(){

        const hint = document.getElementById('emptyHint');

        if (hint) hint.style.display = righeVive().length ? 'none' : 'block';

    }

    function updateGenerateButtonState(){

        const btn = document.getElementById('btn-generate-table');

        /* Il pulsante "genera tabella" e' passato dentro fc_ai_panel.php e
           qui non c'e' piu'. La funzione pero' viene chiamata da una dozzina di
           punti, compreso l'avvio: senza questa uscita anticipata la pagina si
           fermerebbe alla prima riga aggiunta.

           NON e' un return secco: in fondo a questa funzione ci sono
           updateRowCountChip() e toggleEmptyHint(), che col pulsante non
           c'entrano nulla e devono girare comunque — saltarle lasciava acceso
           il messaggio "nessuna riga" anche con le righe in tabella. */
        if (!btn) { updateRowCountChip(); toggleEmptyHint(); aggiornaRigheTitolo(); aggiornaFrecceRiordino(); return; }

        if (righeVive().length > 0){







            btn.classList.remove('btn-warn'); btn.classList.add('btn-flat-warn');







            btn.innerHTML = T('vocab.ib_tabella_piena');







        } else {







            btn.classList.add('btn-warn'); btn.classList.remove('btn-flat-warn');







            btn.innerHTML = ICON_GEN + T('vocab.ib_genera_tabella');







        }







        updateRowCountChip();







        toggleEmptyHint();







    }















    /* ============================================================







       MOTORE PREVIEW 







       ============================================================ */







    let mathJaxTimeouts = {};















    /* ============================================================







       GENERAZIONE TABELLA & RIGHE







       ============================================================ */



    function updateAIPrompt() {

        const isCustom = document.getElementById('format_switch').checked;

        const rowSepVal = document.getElementById('import_row_separator').value;

        const colSepVal = document.getElementById('import_separator').value;

        

        let rule4 = "";

        let exampleOutput = "";

        

        // Regole di base sempre uguali

        const baseRules = T('fc.ibf_prompt_base');



        // Aggiunta dinamica della Regola 4 e dell'esempio

        // Aggiunta dinamica della Regola 4 e dell'esempio

    if (!isCustom) {

        rule4 = T('fc.ibf_regola_json');

                

                // Uso i doppi e quadrupli backslash per farli stampare correttamente nella textarea

                exampleOutput = `${T('vocab.ib_esempio_output')}

[

{"q": "${T('fc.ibf_es_domanda1')} /bold{${T('fc.ibf_es_importante')}}", "a": "${T('fc.ibf_es_risposta1')}"},

{"q": "${T('fc.ibf_es_formula_di')} $\\\\mu$?", "a": "${T('fc.ibf_es_la_risposta')}\\n$$\\\\frac{\\\\sigma}{\\\\sqrt{n}}$$\\n/red{${T('fc.ibf_es_attenzione')}}"}

]`;

            } else {

            const rowSepText = rowSepVal === '\\n' ? T('vocab.ib_un_invio') : `"${rowSepVal}"`;

            const colSepText = colSepVal === '\\t' ? T('vocab.ib_una_tab') : `"${colSepVal}"`;

            const actualRowSep = rowSepVal === '\\n' ? '\n' : rowSepVal;

            

            rule4 = T('fc.ibf_regola_separatori', {colSepText: colSepText, rowSepText: rowSepText});

            exampleOutput = `${T('vocab.ib_esempio_output')}

${T('fc.ibf_es_domanda1')} /bold{${T('fc.ibf_es_importante')}} ${colSepVal} ${T('fc.ibf_es_risposta1')}${actualRowSep}${T('fc.ibf_es_dimmi')} ${colSepVal} ${T('fc.ibf_es_risposta_formula')} /red{${T('fc.ibf_es_attenzione')}}`;

        }



        const finalPrompt = `${baseRules}\n${rule4}\n\n${exampleOutput}\n\n${T('fc.ibf_prompt_concetti')}`;

        

        document.getElementById('ai_prompt_textarea').value = finalPrompt;

    }



    function updatePlaceholder() {

        const isCustom = document.getElementById('format_switch').checked;

        const textArea = document.getElementById('import_text');



        if (isCustom) {

            let rowSep = document.getElementById('import_row_separator').value;

            let colSep = document.getElementById('import_separator').value;

            if (rowSep === '\\n') rowSep = T('fc.ibf_invio_a_capo');

            

            textArea.placeholder = T('fc.ibf_incolla_testo', { riga: rowSep, colonna: colSep });

        } else {

            textArea.placeholder = T('fc.ibf_incolla_json');

        }

        

        // Aggiorniamo anche il prompt

        updateAIPrompt();

    }



    function toggleImportFormat() {

        const isCustom = document.getElementById('format_switch').checked;

        const customRow = document.getElementById('custom_separators_row');



        customRow.style.display = isCustom ? 'flex' : 'none';

        

        updatePlaceholder();

    }



    // Inizializza i testi corretti appena si carica la pagina

    window.addEventListener('DOMContentLoaded', () => {

        updatePlaceholder();

    });



    async function generateTable(){

        const list = document.getElementById('fcRows');

        if (list.children.length > 0){

            const ok = await showConfirm(

                T('vocab.ib_ricreare'),

                T('fc.ibf_ricreare_txt'),

                T('vocab.ib_si_ricrea')

            );

            if (!ok) return;

        }

        list.innerHTML = '';



        const filesQ = document.getElementById('question_images').files;

        const filesA = document.getElementById('answer_images').files;

        const textContent = document.getElementById('import_text').value.trim();

        const isCustom = document.getElementById('format_switch').checked;



        let parsedData = []; // Salveremo qui le domande e risposte strutturate



        if (textContent !== '') {

            if (!isCustom) {

                // LOGICA DI PARSING JSON

                try {

                    // 1. Ripara gli a capo "fisici" inseriti per errore (es. nelle formule isolate $$...$$)

                    // Trova gli invii che non fanno parte della struttura del JSON e li trasforma in \n

                    let safeText = textContent.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"q"|"a"|\{))/g, '\\n');

                    

                    // 2. Raddoppia i backslash del LaTeX (\mu diventa \\mu, % diventa \\%), per evitare che

                    // il JSON li scambi per comandi di sistema non validi. Esclude \n, \t, " e / che sono già ok.

                    safeText = safeText.replace(/(?<!\\)\\(?!n|t|r|"|\\|\/)/g, '\\\\');

                    

                    const jsonArr = JSON.parse(safeText);

                    if (!Array.isArray(jsonArr)) throw new Error(T('vocab.ib_json_array'));

                    

                    parsedData = jsonArr.map(item => ({

                        // Estrae il testo e sostituisce tutti i "\%" con "%"

                        q: (item.q || item.domanda || item.question || '').replace(/\\%/g, '%'),

                        a: (item.a || item.risposta || item.answer || '').replace(/\\%/g, '%')

                    }));

                } catch (error) {

                    showToast(T('fc.ibf_json_non_valido'), 'error');

                    console.error("Dettaglio errore JSON:", error); // Così puoi vederlo in console se serve

                    return; 

                }

            } else {

                // VECCHIA LOGICA DI PARSING CON SEPARATORI

                let colSeparator = document.getElementById('import_separator').value;

                if (colSeparator === '\\t') colSeparator = '\t';

                let rowSeparator = document.getElementById('import_row_separator').value;

                if (rowSeparator === '\\n') rowSeparator = '\n';



                const parsedLines = textContent.split(rowSeparator).map(l=>l.trim()).filter(l=> l !== '');

                parsedData = parsedLines.map(line => {

                    const parts = line.split(colSeparator);

                    return {

                        // Estrae il testo e sostituisce tutti i "\%" con "%"

                        q: (parts[0] ? parts[0].trim() : '').replace(/\\%/g, '%'),

                        a: parts.slice(1).join(colSeparator).trim().replace(/\\%/g, '%')

                    };

                });

            }

        }



        const maxRows = Math.max(filesQ.length, filesA.length, parsedData.length);



        for (let i=0; i<maxRows; i++){

            let qText = '', aText = '';

            if (i < parsedData.length){

                qText = parsedData[i].q;

                aText = parsedData[i].a;

            }

            

            const qUrl = i < filesQ.length ? URL.createObjectURL(filesQ[i]) : '';

            const qName = i < filesQ.length ? filesQ[i].name : '';

            const aUrl = i < filesA.length ? URL.createObjectURL(filesA[i]) : '';

            const aName = i < filesA.length ? filesA[i].name : '';



            const riga = appendRowHTML({ rid: i, q: qText, a: aText,
                                         qImgUrl: qUrl, qImgName: qName,
                                         aImgUrl: aUrl, aImgName: aName });

            /* Il file vero si attacca alla riga. Prima si mandava solo il nome e

               il server ripescava il file dal gruppo: un giro in piu' che

               sbagliava bersaglio se due immagini si chiamavano uguale. */

            if (i < filesQ.length) riga.qNewImgFile = filesQ[i];

            if (i < filesA.length) riga.aNewImgFile = filesA[i];

        }

        markDirty();

        updateGenerateButtonState();

    }















    /* Dal padre nasce un figlio (in fondo ai suoi figli), da un figlio nasce un

       fratello (subito sotto, quindi con lo stesso padre). Chi sia il padre non

       viaggia come dato: lo dice la posizione nell'elenco, ed e' cosi' anche al

       momento del salvataggio. */

    function addKidFrom(btn){

        const row = btn.closest('.fc-row');

        const isChild = isChildRow(row);

        const dopo = isChild ? row : getBlockRows(row).pop();



        const uniqueId = Date.now();

        const nuova = appendRowHTML({ rid: uniqueId, isChild: true, dopo: dopo });

        markDirty();

        updateGenerateButtonState();

        buzz(10);

        setTimeout(()=>{

            nuova.scrollIntoView({behavior:'smooth', block:'center'});

            const editorDiv = document.getElementById('q-desc-' + uniqueId + '_editor') || (FCV ? document.getElementById('q-desc-' + uniqueId) : null);

            if (editorDiv) editorDiv.focus({preventScroll:true});

        }, 80);

        return nuova;

    }



    function addSingleRow(){

        const uniqueId = Date.now();

        appendRowHTML({ rid: uniqueId });

        markDirty();

        updateGenerateButtonState();



        setTimeout(()=>{



            const row = document.querySelector(`.fc-row[data-rid="${uniqueId}"]`);



            if (row) row.scrollIntoView({behavior:'smooth', block:'center'});



            // Punta all'editor nuovo



            const editorDiv = document.getElementById('q-desc-'+uniqueId+'_editor') || (FCV ? document.getElementById('q-desc-'+uniqueId) : null);



            if (editorDiv) editorDiv.focus({preventScroll:true});



        }, 80);



    }















    











    /* LA RIGA DI UNA PAROLA
       Campi semplici, niente editor ricco: la parola (con la sua bandiera e il
       tasto per sentirla), la traduzione, il significato e, finche' la carta
       non ha esempi, un campo per scriverne subito uno. Gli id seguono quelli
       dello studio (q-desc-/a-desc-), cosi' salvataggio, riga vuota, anteprima
       compatta e pulsante esempi funzionano senza saperne niente.
       Invio va avanti: dalla parola alla traduzione, e da li' alla parola
       della riga dopo (creandola se e' l'ultima). Si scrive un elenco intero
       senza toccare lo schermo. */
    function vocabGridHTML(rid, c){
        const imp = FC.impara || {}, con = FC.conosce || {};
        const imgUrl = c.qImgUrl || '', imgName = c.qImgName || '';
        const thumb = imgUrl ? `<img src="${escAttr(imgUrl)}">` : ICON_IMG;
        const conEsempio = !(c.exCount > 0);
        return `
        <div class="fc-field fcv-field">
            <div class="field-label"><img class="fcv-flag" src="${escAttr(imp.bandiera || '')}" alt="">${escAttr(imp.nome || '')}</div>
            <div class="fcv-line">
                <input type="text" class="fcv-in fcv-word" id="q-desc-${rid}" value="${escAttr(c.q || '')}"
                       placeholder="${escAttr(T('fcv.ph_parola'))}" lang="${escAttr(imp.codice || '')}"
                       autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="next" maxlength="255">
                <button type="button" class="fcv-speak" data-speak="learn" data-from="q-desc-${rid}"
                        aria-label="${escAttr(T('fcv.ascolta'))}" title="${escAttr(T('fcv.ascolta'))}">${ICON_SPEAK}</button>
            </div>
            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="q-chip-${rid}" onclick="return apriChipImmagine(event, this)">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, 'q', '${rid}')">
                    <span class="chip-thumb" id="q-img-preview-${rid}">${thumb}</span>
                    <span class="img-name-tag">${imgName ? escAttr(imgName) : T('vocab.immagine')}</span>
                </label>
            </div>
            <input type="hidden" class="q-img-name" value="${imgName ? escAttr(imgName) : ''}">
        </div>
        <div class="fc-field fcv-field">
            <div class="field-label is-answer"><img class="fcv-flag" src="${escAttr(con.bandiera || '')}" alt="">${escAttr(T('fcv.traduzione'))}</div>
            <input type="text" class="fcv-in" id="a-desc-${rid}" value="${escAttr(c.a || '')}"
                   placeholder="${escAttr(T('fcv.ph_traduzione'))}" lang="${escAttr(con.codice || '')}"
                   autocomplete="off" enterkeyhint="next" maxlength="500">
            <label class="fcv-sub" for="m-desc-${rid}">${escAttr(T('fcv.significato'))} <span>${escAttr(T('fcv.facoltativo'))}</span></label>
            <input type="text" class="fcv-in fcv-meaning" id="m-desc-${rid}" value="${escAttr(c.m || '')}"
                   placeholder="${escAttr(T('fcv.ph_significato'))}" lang="${escAttr(imp.codice || '')}"
                   autocomplete="off" enterkeyhint="next" maxlength="400">
            ${conEsempio ? `<div class="fcv-ex-wrap">
                <label class="fcv-sub" for="e-desc-${rid}">${escAttr(T('fcv.esempio'))} <span>${escAttr(T('fcv.facoltativo'))}</span></label>
                <input type="text" class="fcv-in fcv-example" id="e-desc-${rid}" value="${escAttr(c.ex || '')}"
                       placeholder="${escAttr(T('fcv.esempio_ph'))}" lang="${escAttr(imp.codice || '')}"
                       autocomplete="off" enterkeyhint="next" maxlength="1000">
            </div>` : ''}
        </div>`;
    }

    /* Invio nei campi di una parola: avanti, mai "invia il modulo" (che
       salverebbe e porterebbe via dalla pagina). */
    function vocabInvio(e){
        if (!FCV || e.key !== 'Enter' || e.isComposing || !e.target.classList || !e.target.classList.contains('fcv-in')) return;
        e.preventDefault();
        const row = e.target.closest('.fc-row');
        if (!row) return;
        if (e.target.classList.contains('fcv-word')) {
            const a = document.getElementById('a-desc-' + row.dataset.rid);
            if (a) { a.focus(); return; }
        }
        let dopo = row.nextElementSibling;
        while (dopo && dopo.dataset && dopo.dataset.status === 'deleted') dopo = dopo.nextElementSibling;
        const parola = dopo ? dopo.querySelector('.fcv-word') : null;
        if (parola) { parola.focus(); parola.scrollIntoView({behavior:'smooth', block:'center'}); return; }
        if (isChildRow(row)) addKidFrom(row.querySelector('.ns-add-kid'));
        else addSingleRow();
    }

    function fieldTemplate(prefix, id, text, label, imgUrl, imgName, audioUrl, isChild){

        const taId = `${prefix}-desc-${id}`;

        const imgPrevId = `${prefix}-img-preview-${id}`;

        const placeholder = prefix === 'q'

            ? (isChild ? T('fc.ibf_ph_sottodomanda') : T('fc.ibf_ph_domanda'))

            : T('fc.ibf_ph_risposta');

        const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;
        /* Un audio gia' registrato esiste solo aprendo una carta salvata: in
           inserimento le carte nascono vuote e questi due restano stringhe
           vuote, esattamente come prima. La classe has-audio e' la stessa che
           mette toggleRowRecord() dopo una registrazione, cosi' un audio
           caricato dal database e uno appena inciso si comportano uguale. */
        const audioClass = audioUrl ? 'audio-player has-audio' : 'audio-player';
        const audioSrc = audioUrl ? ` src="${escAttr(audioUrl)}"` : '';



        // 1. Preleviamo l'HTML base generato dal template nascosto

        let editorHtml = document.getElementById('editor-template-container').innerHTML;

        

        // 2. Sostituiamo i segnaposto con i dati veri

        editorHtml = editorHtml

            .replace(/%%FIELD_ID%%/g, () => taId)

            .replace(/%%RAW_TEXT%%/g, () => escAttr(text))

            .replace(/%%PLACEHOLDER%%/g, () => placeholder);



        return `

        <div class="fc-field">

            <div class="field-label ${prefix === 'a' ? 'is-answer' : ''}">${label}</div>

            

            ${editorHtml}



            <div class="media-row">

                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="${prefix}-chip-${id}" onclick="return apriChipImmagine(event, this)">

                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${prefix}', '${id}')">

                    <span class="chip-thumb" id="${imgPrevId}">${thumb}</span>

                    <span class="img-name-tag">${imgName ? escAttr(imgName) : T('vocab.immagine')}</span>

                </label>

                <button type="button" class="media-chip btn-rec" onclick="toggleRowRecord(this, '${prefix}')">

                    <span class="chip-thumb"><span class="rec-dot"></span></span>

                    <span class="rec-label">${T('fc.ibf_registra')}</span>

                </button>

                <audio class="${audioClass} audio-player-${prefix}" controls${audioSrc}></audio>
                <button type="button" class="media-chip btn-del-audio" data-tipo="${prefix}"
                        onclick="eliminaAudioRiga(this, '${prefix}')"
                        title="${escAttr(T('fc.ibf_elimina_audio'))}"
                        aria-label="${escAttr(T('fc.ibf_elimina_audio'))}"
                        ${audioUrl ? '' : 'hidden'}>${ICON_AUDIO_X}</button>

            </div>

            <input type="hidden" class="${prefix}-img-name" value="${imgName ? escAttr(imgName) : ''}">

        </div>`;

    }















    /* opzioni: { dopo, dbId, status } */

    /**
     * Crea una riga e la inserisce in tabella. Restituisce l'elemento creato.
     *
     * Prende UN OGGETTO, non una fila di argomenti posizionali: i campi di una
     * carta sono ormai tredici, e con la vecchia firma una chiamata diceva
     * appendRowHTML(id, '', '', '', '', '', '', true) -- sette valori vuoti di
     * fila in cui bastava sbagliare una posizione per attaccare l'immagine
     * della risposta alla domanda, senza nessun errore visibile. Con le
     * etichette si scrive solo cio' che serve, e aggiungere un campo domani
     * non obbliga a rileggere ogni chiamata.
     *
     * Campi (tutti facoltativi tranne rid):
     *   rid          identificativo della riga in pagina (obbligatorio)
     *   q, a         testo di domanda e risposta
     *   qImgUrl/Name immagine della domanda: indirizzo e nome del file
     *   aImgUrl/Name immagine della risposta
     *   qAudioUrl    audio gia' salvato della domanda (solo in modifica:
     *   aAudioUrl    una carta nuova non ne ha)
     *   isChild      true se e' una carta figlia
     *   dbId         id nel database, 0 se non ancora salvata
     *   status       'new' | 'clean' | 'edited' | 'deleted'
     *   exCount      quanti esempi ha gia' (solo in modifica)
     *   dopo         elemento dopo il quale inserirla, invece che in fondo
     */
    function appendRowHTML(carta){
        const c = carta || {};
        const rid      = c.rid;
        const qText    = c.q || '';
        const aText    = c.a || '';
        const isChild  = !!c.isChild;
        const exCount  = c.exCount || 0;
        const dbId     = c.dbId || 0;

        // Serve gia' qui, prima ancora che la riga esista nel DOM: decide se il
        // pulsante IA nasce con scritto "Genera con l'IA" o "Migliora con l'IA".
        const aVuotaAllaNascita = fcdTestoVero(aText) === '';

        const list = document.getElementById('fcRows');
        const wrap = document.createElement('div');
        wrap.className = 'fc-row data-row' + (isChild ? ' is-child' : '');
        wrap.dataset.rid = rid;
        wrap.dataset.id = dbId;                       // 0 = non e' ancora nel database
        wrap.dataset.status = c.status || 'new';
        wrap.dataset.origType = isChild ? 'child' : 'parent';
        wrap.innerHTML = `
            <div class="swipe-actions">
                <button type="button" class="swipe-btn swipe-btn-delete" onclick="swipeDeleteRow(this)">${ICON_TRASH}<span>${T('comune.elimina')}</span></button>
                <button type="button" class="swipe-btn swipe-btn-join" onclick="swipeJoinRow(this)">${ICON_LINK}<span>${T('fc.ibf_figlio')}</span></button>
                <button type="button" class="swipe-btn swipe-btn-unjoin" onclick="swipeUnjoinRow(this)">${ICON_UNLINK}<span>${T('fc.ibf_sgancia')}</span></button>
            </div>
            <div class="deleted-stub">
                <span class="stub-icon">${ICON_TRASH_SM}</span>
                <span class="stub-testi">
                    <span class="stub-text stub-q"></span>
                    <span class="stub-text stub-a" hidden></span>
                </span>
                <button type="button" class="btn-restore" onclick="onRestoreClick(this)">${ICON_UNDO}<span>${T('fc.befc_ripristina')}</span></button>
            </div>
            <div class="fc-row-content">
                <div class="fc-row-strip">
                    <span class="grip">${ICON_GRIP}</span>
                    <button type="button" class="btn-move-up" onclick="spostaRiga(this, -1)" aria-label="${escAttr(T('fc.ibf_sposta_su'))}" title="${escAttr(T('fc.ibf_sposta_su'))}">${ICON_MOVE_UP}</button>
                    <button type="button" class="btn-move-down" onclick="spostaRiga(this, 1)" aria-label="${escAttr(T('fc.ibf_sposta_giu'))}" title="${escAttr(T('fc.ibf_sposta_giu'))}">${ICON_MOVE_DOWN}</button>
                    <span class="row-badge">${isChild ? T('vocab.figlio') : T('fc.ibf_concetto')}</span>
                    <span class="status-badge sb-new">${T('vocab.mi_nuova')}</span>
                    <span class="status-badge sb-edited">${T('fc.befc_modificata')}</span>
                    <span class="compact-preview">
                        <span class="compact-q"></span>
                        <span class="compact-a"></span>
                    </span>
                    <span class="strip-spacer"></span>
                    ${exChipHTML(rid, dbId, isChild, dbId === 0, exCount)}
                    <span class="strip-hint">${ICON_REORDER} ${T('fc.ibf_tieni_premuto')}</span>
                </div>
                <div class="fc-row-grid">
                    ${FCV ? vocabGridHTML(rid, c) : `
                    ${fieldTemplate('q', rid, qText, T('fc.ifc_domanda'), c.qImgUrl || '', c.qImgName || '', c.qAudioUrl || '', isChild)}
                    ${fieldTemplate('a', rid, aText, T('fc.ifc_risposta'), c.aImgUrl || '', c.aImgName || '', c.aAudioUrl || '', isChild)}`}
                </div>
                <div class="ns-row-foot">
                    <button type="button" class="ns-add-kid" onclick="addKidFrom(this)">
                        ${isChild ? ICON_SIBLING + T('fc.ifc_aggiungi_fratello') : ICON_KID + T('fc.ifc_aggiungi_figlio')}
                    </button>
                    ${FCV ? '' : `<button type="button" class="ns-add-kid btn-ia-riga" onclick="if(window.FCAIP) FCAIP.apriMigliora(this)">
                        ${ICON_MAGIC}<span class="btn-ia-riga-testo">${aVuotaAllaNascita ? T('fc.ibfai_sec_ia_titolo') : T('fc.ibfai_migliora_con_ia_btn')}</span>
                    </button>`}
                </div>
            </div>`;
        if (c.dopo) c.dopo.after(wrap); else list.appendChild(wrap);
        /* fcInitEditorWrap non e' mai esistita in format_toolbar.php: la riga
           si inizializzava comunque, ma solo grazie all'osservatore generico
           che guarda nascere gli editor in tutta la pagina (con un giro di
           MutationObserver in mezzo, non subito). ftInitTutti e' il nome
           pubblico pensato apposta per questo caso -- una pagina che sa gia'
           il momento esatto in cui la riga nuova entra nel DOM e non ha
           bisogno di aspettare che qualcun altro se ne accorga. */
        if (typeof ftInitTutti === 'function') ftInitTutti(wrap);
        updateGenerateButtonState();
        return wrap;
    }



    /* Qualunque digitazione o cambio marca la riga: un ascoltatore solo sul

       contenitore invece di un attributo su ogni campo. */

    document.getElementById('fcRows').addEventListener('input',  e => onRowChange(e.target));

    document.getElementById('fcRows').addEventListener('change', e => onRowChange(e.target));

    document.getElementById('fcRows').addEventListener('keydown', vocabInvio);















    















    function previewRowImage(input, type, id){







        const file = input.files[0];







        const thumb = document.getElementById(`${type}-img-preview-${id}`);







        const chip = document.getElementById(`${type}-chip-${id}`);







        const nameTag = chip.querySelector('.img-name-tag');







        const row = input.closest('.fc-row');















        if (file){







            const url = URL.createObjectURL(file);







            thumb.innerHTML = `<img src="${url}">`;







            chip.classList.add('has-img');

            /* Scegliendo un'immagine si annulla una cancellazione chiesta
               prima: il campo torna pieno, e il server non deve piu'
               svuotarlo. Non e' strettamente necessario (il server ignora
               comunque clear_image se arriva anche un file nuovo — vedi
               fc_set_allegati in fc_insert_save.php), ma lasciare in giro
               un segnale che non serve piu' e' solo confusione futura. */
            delete row.dataset['clearImage' + (type === 'q' ? 'Q' : 'A')];







            if (nameTag) nameTag.textContent = file.name;







            if (type === 'q') { row.qNewImgFile = file; delete row.qImgLink; } else row.aNewImgFile = file;







        } else {







            thumb.innerHTML = ICON_IMG;







            chip.classList.remove('has-img');







            if (nameTag) nameTag.textContent = T('vocab.immagine');







            if (type === 'q') delete row.qNewImgFile; else delete row.aNewImgFile;

        }

        onRowChange(input);

    }

    /* ============================================================
       VISUALIZZATORE IMMAGINE A TUTTO SCHERMO
       ============================================================
       Il chip immagine e' un <label> che avvolge l'<input type="file">: per
       costruzione del browser, un tocco QUALUNQUE al suo interno apre subito
       il selettore di sistema. Va bene quando non c'e' ancora un'immagine —
       e' esattamente cosa serve fare — ma quando ce n'e' gia' una, proporre
       subito di cambiarla senza nemmeno mostrarla grande e' un salto troppo
       corto: prima si guarda, poi si decide se toglierla o sostituirla. */

    let imgViewerChip = null;   // il chip aperto al momento, per Rimuovi/Sostituisci

    /**
     * Intercetta il click sul chip. Restituisce true per lasciar proseguire
     * il comportamento nativo (apre il selettore file — il caso "non c'e'
     * ancora un'immagine"), false per fermarlo (il caso "c'e' gia', mostrala
     * invece di proporre subito di cambiarla").
     */
    function apriChipImmagine(ev, chip){
        if (!chip.classList.contains('has-img')) return true;
        ev.preventDefault();
        const img = chip.querySelector('.chip-thumb img');
        if (!img) return false;
        imgViewerChip = chip;
        document.getElementById('imgViewerImg').src = img.src;
        document.getElementById('imgViewer').hidden = false;
        if (typeof FCAI !== 'undefined') FCAI.lockScroll();
        return false;
    }

    function chiudiImgViewer(){
        document.getElementById('imgViewer').hidden = true;
        if (typeof FCAI !== 'undefined') FCAI.unlockScroll();
        imgViewerChip = null;
    }

    /**
     * Cancella l'immagine di un campo. Stessa distinzione dell'audio, sotto
     * lo stesso pulsante:
     *
     *  - immagine appena scelta e non ancora salvata: vive solo come
     *    proprieta' JS sulla riga (qNewImgFile/aNewImgFile), basta buttarla;
     *  - immagine gia' nel database: va segnata sulla riga
     *    (dataset.clearImageQ / clearImageA) e mandata al server come campo
     *    a parte, perche' il salvataggio manda i file solo quando ce ne sono
     *    di nuovi e non ha altrimenti modo di dire "questa colonna va
     *    svuotata".
     */
    function eliminaImmagineRiga(chip, type){
        const row = chip.closest('.fc-row');
        if (!row) return;

        const thumb = chip.querySelector('.chip-thumb');
        const img = thumb ? thumb.querySelector('img') : null;
        const eraSalvata = !!(img && img.src && !img.src.startsWith('blob:'));
        if (img && img.src && img.src.startsWith('blob:')) URL.revokeObjectURL(img.src);

        if (thumb) thumb.innerHTML = ICON_IMG;
        chip.classList.remove('has-img');
        const nameTag = chip.querySelector('.img-name-tag');
        if (nameTag) nameTag.textContent = T('vocab.immagine');
        const input = chip.querySelector('input[type=file]');
        if (input) input.value = '';
        const nomeImg = row.querySelector('.' + type + '-img-name');
        if (nomeImg) nomeImg.value = '';

        if (type === 'q') { delete row.qNewImgFile; delete row.qImgLink; } else delete row.aNewImgFile;
        if (eraSalvata) row.dataset['clearImage' + (type === 'q' ? 'Q' : 'A')] = '1';

        if (row.dataset.status === 'clean') row.dataset.status = 'edited';
        if (typeof updateStatusVisual === 'function') updateStatusVisual(row);
        markDirty();
        if (typeof aggiornaRigheTitolo === 'function') aggiornaRigheTitolo();
        showToast(T('fc.ibf_immagine_eliminata'));
    }








</script>







<script>







    /* ============================================================







       BADGE / STATO FIGLIO / SWIPE ACTIONS







       ============================================================ */







    /* Quando una riga cambia natura (padre <-> figlio) cambia anche cosa offre

       il pulsante in fondo: un figlio non puo' avere figli, puo' avere

       fratelli. */

    function aggiornaPiedeRiga(row){

        const btn = row.querySelector('.ns-add-kid');

        if (!btn) return;

        btn.innerHTML = isChildRow(row) ? ICON_SIBLING + T('fc.ifc_aggiungi_fratello') : ICON_KID + T('fc.ifc_aggiungi_figlio');

    }

    function updateRowBadgeText(row){

        aggiornaPiedeRiga(row);

        /* ATTENZIONE: qui NON si tocca data-orig-type.
           origType non dice "cosa e' questa riga adesso" — quello lo dice gia'
           la classe is-child — ma "cosa e' questa carta NEL DATABASE": e'
           l'unico modo che il server ha di accorgersi che una carta sta
           cambiando natura e va spostata fra fc_study_cards e fc_study_subcards.
           Aggiornarlo qui, cioe' nel momento esatto in cui la riga diventa
           figlia a video, cancellava proprio quell'informazione: al server
           arrivava orig_type='child' e is_child=1, cioe' "figlia da sempre,
           niente da spostare". Il server faceva allora un UPDATE su
           fc_study_subcards usando l'id della carta in fc_study_cards — una riga che
           in quella tabella non esiste: nessun errore, nessuna modifica, e la
           carta restava padre. Da li' in poi la pagina lavorava con un id
           sbagliato e ogni salvataggio successivo di quella carta cadeva nel
           vuoto. Lo aggiornano solo appendRowHTML (quando la riga nasce) e
           applicaMappa (quando il server ha detto com'e' finita davvero). */

        const badge = row.querySelector('.row-badge');







        if (badge) badge.textContent = isChildRow(row) ? T('vocab.figlio') : T('fc.ibf_concetto');







    }















    function closeSwipe(row){







        if (!row) return;







        const content = row.querySelector('.fc-row-content');







        content.style.transition = 'transform .32s var(--snap)';







        content.style.transform = 'translateX(0px)';







        row._swipeX = 0;







    }







    function closeAllSwipes(except){







        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







            if (r !== except && r._swipeX) closeSwipe(r);







        });







    }















    /* ============================================================
       CARICAMENTO DELLE CARTE ESISTENTI (solo in modifica)
       ============================================================
       Si disegnano a lotti invece che tutte insieme: una categoria con
       trecento carte bloccherebbe la pagina per secondi prima di mostrare
       qualsiasi cosa. Il primo lotto compare subito, il resto su richiesta. */

    let currentRenderIndex = 0;

    function renderNextBatch(amount){
        let count = 0;
        while (currentRenderIndex < INITIAL_CARDS.length) {
            const c = INITIAL_CARDS[currentRenderIndex];
            const id = c.id_fcimg;
            const isChild = !!parseInt(c.is_child, 10);

            appendRowHTML({
                rid: id,
                dbId: id,
                status: 'clean',
                isChild: isChild,
                q: c.question_desc || '',
                a: c.answer_desc || '',
                m: c.meaning || '',
                qImgUrl:  c.question_image ? (FC_IMG_DIR + c.question_image) : '',
                qImgName: c.question_image || '',
                aImgUrl:  c.answer_image ? (FC_IMG_DIR + c.answer_image) : '',
                aImgName: c.answer_image || '',
                qAudioUrl: c.question_audio ? ('../media/flashcard_audio/' + c.question_audio) : '',
                aAudioUrl: c.answer_audio ? ('../media/flashcard_audio/' + c.answer_audio) : '',
                exCount: parseInt(c.ex_count, 10) || 0
            });

            currentRenderIndex++;
            count++;

            /* Il lotto non si chiude mai in mezzo a una famiglia: se la carta
               dopo e' una figlia, si tira avanti finche' il blocco e' finito.
               Spezzare un padre dai suoi figli lascerebbe dei figli orfani in
               pagina, e al salvataggio il loro parent_idx punterebbe a una
               riga che non c'e'. */
            if (count >= amount) {
                if (currentRenderIndex < INITIAL_CARDS.length) {
                    const prossima = INITIAL_CARDS[currentRenderIndex];
                    if (parseInt(prossima.is_child, 10) !== 1) break;
                } else {
                    break;
                }
            }
        }

        const contenitore = document.getElementById('loadMoreContainer');
        if (contenitore) {
            contenitore.style.display = (currentRenderIndex < INITIAL_CARDS.length) ? 'block' : 'none';
        }

        /* Mostrare altre carte non e' una modifica: le righe appena disegnate
           nascono 'clean', e la pagina non deve risultare sporca solo perche'
           si e' scorso piu' in basso. */
        if (!isDirty && window.StandoutSaveBar) StandoutSaveBar.markSaved();
        updateRowCountChip();
        toggleEmptyHint();
        aggiornaRigheTitolo();
    }

    function hydrateInitialCards(){
        renderNextBatch(50);
    }

    /* Prima di salvare servono TUTTE le righe in pagina: il salvataggio manda
       quello che vede, e le carte non ancora disegnate risulterebbero
       cancellate. */
    function assicuraTutteLeRighe(){
        if (currentRenderIndex < INITIAL_CARDS.length) {
            renderNextBatch(INITIAL_CARDS.length);
        }
    }

    /* ============================================================
       ELIMINAZIONE CON RIPRISTINO
       ============================================================
       Una carta gia' nel database non sparisce dalla pagina quando la
       elimini: si riduce a un moncone compatto e sbarrato, con un tasto per
       rimetterla. Sparisce davvero solo al salvataggio, quando il server la
       cancella per davvero.

       Il motivo e' che l'eliminazione qui non e' immediata: e' una richiesta
       che parte al salvataggio successivo. Nascondere la riga (come si faceva
       prima con display:none) rendeva quella richiesta invisibile e
       irrevocabile insieme — nessun modo di accorgersi di aver sbagliato, e
       nessun modo di tornare indietro. Una carta nuova, invece, non e' mai
       esistita da nessun'altra parte: quella se ne va subito. */

    /* Accende sulla riga la classe dello stato, che il CSS traduce nel
       distintivo visibile. Non viene chiamata alla nascita di una riga: in
       inserimento sarebbero tutte 'new' e il distintivo comparirebbe su ogni
       carta senza distinguere niente. Serve dove lo stato CAMBIA — una carta
       esistente che si modifica, si elimina o si ripristina — cioe' in
       pratica solo in modalita' modifica. */
    function updateStatusVisual(row){
        row.classList.remove('status-new', 'status-edited');
        if (row.dataset.status === 'new') row.classList.add('status-new');
        else if (row.dataset.status === 'edited') row.classList.add('status-edited');
    }

    /* Riduce la riga al moncone. Il markup del moncone e' gia' nella riga da
       quando nasce: qui si riempie il testo e si accende la classe, cosi' il
       passaggio e' immediato e ripristinare non deve ricostruire nulla. */
    /* Taglia un testo alla lunghezza data, con i puntini se e' stato tagliato.
       Gli a capo e gli spazi doppi diventano spazi singoli: nel moncone il
       testo sta su una riga sola, e un a capo la' dentro sprecherebbe meta'
       dello spazio disponibile. */
    function troncaPerStub(testo, max){
        const t = String(testo || '').replace(/\s+/g, ' ').trim();
        return t.length > max ? t.slice(0, max) + '…' : t;
    }

    function collapseToStub(row){
        row.classList.add('is-deleted');
        closeSwipe(row);

        /* Domanda E risposta, tutte e due troncate: il solo inizio della
           domanda spesso non basta a riconoscere quale carta si sta per
           perdere — due carte sullo stesso argomento cominciano uguali, ed e'
           la risposta a distinguerle. */
        const stubQ = row.querySelector('.stub-q');
        const stubA = row.querySelector('.stub-a');
        const qVal = troncaPerStub(testoRigaPer(row, 'q'), 60);
        const aVal = troncaPerStub(testoRigaPer(row, 'a'), 60);

        if (stubQ) stubQ.textContent = qVal || T('fc.befc_vuota');
        if (stubA) {
            stubA.textContent = aVal;
            // senza risposta non si mostra un separatore che non separa niente
            stubA.hidden = (aVal === '');
        }

        // ripiego per il markup vecchio, se una riga fosse nata prima di questa modifica
        const stub = row.querySelector('.stub-text:not(.stub-q):not(.stub-a)');
        if (stub) stub.textContent = qVal || T('fc.befc_vuota');
        /* Il numero in alto deve seguire subito: una carta segnata per
           l'eliminazione sparisce dalla vista, e vederla ancora contata
           farebbe pensare che la cancellazione non abbia funzionato. */
        updateRowCountChip();
    }

    function hardRemoveRow(row){
        row.style.transition = 'transform .22s var(--spring), opacity .22s var(--spring)';
        row.style.transform = 'translateX(105%) scale(.95)';
        row.style.opacity = '0';
        row.style.pointerEvents = 'none';
        setTimeout(()=>{ row.remove(); updateRowCountChip(); toggleEmptyHint(); updateGenerateButtonState(); }, 220);
    }

    /* cascade=true: eliminando un padre se ne vanno anche i figli. I figli
       travolti si segnano con dataset.cascade, cosi' ripristinando il padre
       tornano anche loro — ma solo quelli, non un figlio che era gia' stato
       eliminato per conto suo prima. */
    function deleteRowSoft(row, cascade){
        const wasParent = !isChildRow(row);
        let children = [];
        if (cascade && wasParent) {
            children = getBlockRows(row).slice(1).filter(c => c.dataset.status !== 'deleted');
        }
        if (row.dataset.status === 'new') {
            hardRemoveRow(row);
        } else {
            row.dataset.status = 'deleted';
            updateStatusVisual(row);
            collapseToStub(row);
        }
        children.forEach(child=>{
            child.dataset.cascade = '1';
            deleteRowSoft(child, false);
        });
        markDirty();
        if (typeof aggiornaRigheTitolo === 'function') aggiornaRigheTitolo();
    }

    function restoreRow(row){
        row.dataset.status = 'edited';
        row.classList.remove('is-deleted');
        updateStatusVisual(row);
        if (!isChildRow(row)) {
            let cur = row.nextElementSibling;
            while (cur && isChildRow(cur)) {
                const next = cur.nextElementSibling;
                if (cur.dataset.cascade === '1' && cur.dataset.status === 'deleted') {
                    cur.dataset.cascade = '';
                    restoreRow(cur);
                }
                cur = next;
            }
        }
        markDirty();
        updateRowCountChip();   // ripristinata: torna a contare
        if (typeof aggiornaRigheTitolo === 'function') aggiornaRigheTitolo();
    }

    function onRestoreClick(btn){
        const row = btn.closest('.fc-row');
        flip(document.getElementById('fcRows'), ()=> restoreRow(row));
    }

    function swipeDeleteRow(btn){
        const row = btn.closest('.fc-row');
        /* Tutta la logica sta in deleteRowSoft: una carta gia' salvata
           diventa un moncone ripristinabile, una nuova se ne va subito. Qui
           resta solo l'animazione d'insieme del blocco, che deve avvenire in
           un colpo solo per padre e figli. */
        flip(document.getElementById('fcRows'), ()=> deleteRowSoft(row, true));
        updateGenerateButtonState();
    }















    function swipeJoinRow(btn){







        const row = btn.closest('.fc-row');







        closeSwipe(row);







        startChildSelection(row);







    }















    function swipeUnjoinRow(btn){







        const row = btn.closest('.fc-row');







        unjoinRow(row);







    }















    function unjoinRow(row){







        row.classList.remove('is-child');







        updateRowBadgeText(row);







        const qArea = row.querySelector('.q-desc');







        if (qArea && qArea.placeholder.indexOf(T('fc.ibf_sottodomanda')) !== -1) qArea.placeholder = T('fc.ibf_ph_domanda');















        // Sposta la riga fisicamente alla fine del blocco dei figli attuali,







        // altrimenti "catturerebbe" come propri figli i suoi ex fratelli minori







        // (getBlockRows considera figlia ogni is-child consecutiva dopo una non-figlia).







        let lastChild = row;







        while (lastChild.nextElementSibling && lastChild.nextElementSibling.classList.contains('is-child')) {







            lastChild = lastChild.nextElementSibling;







        }







        if (lastChild !== row) {







            flip(document.getElementById('fcRows'), ()=> lastChild.after(row));







        }















        closeSwipe(row);







        updateGenerateButtonState();







        /* Stessa ragione dell'aggancio: sganciare una carta la fa passare da
           fc_study_subcards a fc_study_cards, ma non tocca nessun campo di testo — se
           non lo si segna qui, per la pagina non e' successo niente da salvare
           e il cambiamento non arriva mai al server. Va in fondo, a riga gia'
           spostata: frecce e carte-titolo si calcolano su come sta l'elenco
           adesso, non su com'era un attimo fa. */
        if (row.dataset.status === 'clean') row.dataset.status = 'edited';
        if (typeof updateStatusVisual === 'function') updateStatusVisual(row);
        dopoRiordino();

        showToast(T('fc.ibf_sganciato'));







    }















    function markEligibleTargets(){







        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







            r.classList.remove('eligible-target');







            if (r !== childRowPending && !isChildRow(r)) r.classList.add('eligible-target');







        });







    }















    function startChildSelection(row){







        childRowPending = row;







        isSelectingParent = true;







        closeAllSwipes();







        row.classList.add('pending-child');







        document.getElementById('parent-selection-banner').classList.add('show');







        document.body.classList.add('selecting-mode');







        markEligibleTargets();







    }















    function cancelChildSelection(){







        isSelectingParent = false;







        if (childRowPending) childRowPending.classList.remove('pending-child');







        childRowPending = null;







        document.getElementById('parent-selection-banner').classList.remove('show');







        document.body.classList.remove('selecting-mode');







        document.querySelectorAll('.eligible-target').forEach(r=> r.classList.remove('eligible-target'));







    }















    document.getElementById('fcRows').addEventListener('click', function(e){







        if (!isSelectingParent) return;







        if (suppressNextRowClick) { suppressNextRowClick = false; return; }







        if (e.target.closest('button, input, textarea, a, select')) return;















        const clickedRow = e.target.closest('.fc-row');







        if (!clickedRow || clickedRow === childRowPending) return;















        if (isChildRow(clickedRow)) {







            showToast(T('fc.ibf_no_figlio_su_figlio'), 'error');







            return;







        }















        const list = document.getElementById('fcRows');







        const block = getBlockRows(clickedRow);







        const insertAfter = block[block.length - 1];







        const pending = childRowPending;

        /* Se la carta che sta scendendo di livello aveva dei figli suoi, i
           figli vengono con lei. Spostando la sola riga, i suoi ex figli
           restavano dov'erano: a video finivano sotto un padre a caso (il
           primo che li precede) e al salvataggio ci finivano davvero, perche'
           il server capisce di chi e' figlia una riga dalla posizione, non da
           un id. Si legge la famiglia ORA, prima di muovere qualcosa: dopo lo
           spostamento le righe non sono piu' consecutive e il conto non
           tornerebbe piu'. */
        const famiglia = getBlockRows(pending);















        flip(list, ()=>{







            /* In fila, uno dopo l'altro: la carta e poi i suoi ex figli, che
               diventano fratelli suoi sotto il nuovo padre (piu' di un livello
               di annidamento non esiste, ne' qui ne' nel database). */
            let ancora = insertAfter;
            famiglia.forEach(function(r){ ancora.after(r); ancora = r; });







            pending.classList.add('is-child');







        });















        updateRowBadgeText(pending);

        /* L'aggancio e' una modifica a tutti gli effetti, ma non passa da
           nessuna casella di testo: senza segnarlo a mano queste righe
           restavano "clean", e per la pagina non c'era niente da salvare —
           il salvataggio automatico non partiva, e quello a mano rimandava
           al server le stesse righe di prima. I figli venuti dietro cambiano
           padre, quindi sono modificati anche loro.
           dopoRiordino() e' la stessa cosa che si fa dopo uno spostamento con
           le freccette: segna il lavoro da salvare e rimette a posto frecce e
           carte-titolo, che dipendono da chi ha figli e da quanti. */
        famiglia.forEach(function(r){
            if (r.dataset.status === 'clean') r.dataset.status = 'edited';
            if (typeof updateStatusVisual === 'function') updateStatusVisual(r);
        });
        dopoRiordino();







        const qArea = pending.querySelector('.q-desc');







        if (qArea) qArea.placeholder = T('fc.ibf_ph_sottodomanda');







        closeSwipe(pending);







        buzz(14);







        cancelChildSelection();







        updateGenerateButtonState();







        showToast(T('fc.ibf_agganciato'));







    });















    /* ============================================================







       MOTORE GESTURE — swipe orizzontale + riordino a pressione prolungata







       ============================================================ */







    (function(){







        const TAP_SLOP = 9;







        const SWIPE_SLOP = 15;







        const STILL_SLOP = 6;







        const LONG_PRESS_MS = 420;







        let pend = null;















        /* C'e' del testo selezionato in pagina? Allora il dito sta lavorando

           sulla selezione, non sulla riga. Serve perche' i campi di questa

           pagina non sono <textarea> ma div modificabili: il filtro qui sotto

           non li vede, e senza questo controllo trascinare per selezionare

           faceva scorrere la carta di lato e comparire "elimina"/"figlio". */

        function selezioneAttiva(){

            const s = window.getSelection ? window.getSelection() : null;

            return !!(s && !s.isCollapsed && String(s).trim() !== '');

        }

        function dentroEditor(e){

            return !!(e.target && e.target.closest &&

                      e.target.closest('.fmt-editor-wrap, [contenteditable="true"], [contenteditable=""]'));

        }

        function eligibleRow(e){

            if (!e.target || !e.target.closest) return null;

            if (e.target.closest('textarea, input, select, button, audio, a')) return null;







            const row = e.target.closest('.fc-row');







            if (!row || !row.closest('#fcRows')) return null;







            return row;







        }















        document.addEventListener('pointerdown', function(e){







            if (activeDrag || isSelectingParent) return;







            const insideRow = e.target.closest && e.target.closest('.fc-row');







            document.querySelectorAll('#fcRows .fc-row').forEach(r=>{







                if (r._swipeX && r !== insideRow) closeSwipe(r);







            });







        }, true);















        document.addEventListener('pointerdown', function(e){







            if (isSelectingParent) return;







            if (pend || activeDrag) return;

            if (e.pointerType === 'mouse' && e.button !== 0) return;

            /* Col mouse lo SWIPE non esiste: trascinare orizzontalmente e'
               indistinguibile da selezionare del testo, e il gesto veniva
               scambiato per uno swipe -- quell'esclusione resta, piu' sotto,
               nel gestore del movimento (solo la parte che arma lo swipe).
               Il RIORDINO invece e' un gesto diverso -- pressione ferma, poi
               verticale -- e col mouse funziona benissimo, A PATTO di togliere
               di mezzo la selezione nativa PRIMA che possa scattare: e' quello
               che mancava, non un'esclusione voluta apposta per il riordino.
               Un mouse fermo su del testo per qualche centinaio di
               millisecondi lo seleziona SEMPRE, se nessuno lo impedisce
               prima -- ed e' esattamente cosa succedeva: la selezione nativa
               vinceva la corsa contro il timer della pressione prolungata,
               ogni volta, anche col dito. */

            const row = eligibleRow(e);

            if (!row) return;

            const suTesto = dentroEditor(e);

            /* Il dito (o il puntatore) si e' appoggiato su del testo gia'

               selezionato: sta per spostare una maniglia della selezione, o
               sta iniziando a selezionare con lo stesso mouse. Non e' un
               gesto nostro: dentro un campo di testo la selezione nativa deve
               continuare a funzionare come sempre. */

            if (suTesto && selezioneAttiva()) return;

            /* Fuori da un campo di testo, invece, la selezione nativa non
               serve a niente -- non c'e' testo li' che valga la pena
               selezionare, solo l'intestazione della carta -- e va tolta di
               mezzo SUBITO, non quando (o SE) il riordino si arma 400ms dopo.
               E' la correzione vera: prima veniva aggiunta solo dentro
               activateReorder/activateSwipeGesture, quindi durante l'attesa
               della pressione ferma non c'era nessuna protezione, e la
               selezione nativa del browser vinceva sempre la corsa contro il
               timer -- specialmente col mouse, dove selezionare e' immediato. */

            if (!suTesto) {
                e.preventDefault();
                document.body.classList.add('no-select');
            }

            const content = row.querySelector('.fc-row-content');

            pend = {

                row, content, pointerId: e.pointerId, suTesto,

                startX: e.clientX, startY: e.clientY,

                lastX: e.clientX, lastY: e.clientY,

                swiping: false, reordering: false,

                /* Dentro un campo di testo la pressione prolungata e' come si

                   seleziona una parola: li' il riordino non si arma proprio,

                   restano la maniglia e la fascia in alto. */

                longPressTimer: suTesto ? null : setTimeout(()=>{







                    // Attiva il riordino SOLO se il dito è rimasto davvero fermo:







                    // se nel frattempo si è mosso (anche solo per iniziare uno scroll







                    // lento), non dobbiamo rubare il gesto alla pagina.







                    if (!pend || pend.swiping) return;







                    const stillDx = Math.abs(pend.lastX - pend.startX);







                    const stillDy = Math.abs(pend.lastY - pend.startY);







                    if (stillDx < STILL_SLOP && stillDy < STILL_SLOP) activateReorder(pend);







                }, LONG_PRESS_MS)







            };







        });















        document.addEventListener('pointermove', function(e){







            if (!pend || e.pointerId !== pend.pointerId) return;







            pend.lastX = e.clientX; pend.lastY = e.clientY;







            if (pend.reordering) { doReorderMove(e); return; }







            if (pend.swiping) { doSwipeMove(e); return; }















            const dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;







            const adx = Math.abs(dx), ady = Math.abs(dy);















            // Priorità allo scroll nativo: appena il movimento non è NETTAMENTE







            // orizzontale, molliamo subito il gesto e lasciamo scorrere la pagina.







            // Un dito vero non è mai perfettamente dritto: meglio richiedere uno







            // swipe deciso piuttosto che rubare scroll verticali per errore.







            if (ady > TAP_SLOP && adx < ady * 1.8) {







                clearTimeout(pend.longPressTimer);







                pend = null; // lascia proseguire lo scroll nativo della pagina







                return;







            }







            /* e.pointerType !== 'mouse': lo swipe resta un gesto solo per il
               tocco, per il motivo di sempre -- trascinare orizzontalmente
               col mouse e' indistinguibile da selezionare del testo. Il
               riordino (sopra, nel timer della pressione prolungata) non ha
               questa esclusione: e' un gesto diverso, e col mouse funziona. */
            if (adx > SWIPE_SLOP && adx > ady * 1.8 && e.pointerType !== 'mouse') {

                /* Trascinare dentro un campo di testo mentre si sta formando

                   una selezione e' una selezione, non uno swipe. */

                if (pend.suTesto && selezioneAttiva()) {

                    clearTimeout(pend.longPressTimer);

                    pend = null;

                    return;

                }

                clearTimeout(pend.longPressTimer);

                pend.swiping = true;







                activateSwipeGesture(pend);







                doSwipeMove(e);







            }







        }, {passive:true});















        document.addEventListener('pointerup', function(e){ finishGesture(e, true); });







        document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });















        function finishGesture(e, committed){







            if (!pend || e.pointerId !== pend.pointerId) return;







            const p = pend; pend = null;







            clearTimeout(p.longPressTimer);







            if (p.reordering) finalizeReorder(p);







            else if (p.swiping) finalizeSwipe(p, committed);

            /* Ne' l'uno ne' l'altro: era solo un tap. finalizeReorder e
               finalizeSwipe tolgono da soli la protezione dalla selezione che
               avevano acceso loro -- ma qui, se il gesto non e' mai arrivato
               ad attivarsi, quella protezione l'ha accesa il pointerdown (per
               poter vincere la corsa contro la selezione nativa fin da
               subito) e nessuno dei due la spegnerebbe mai: senza questo, un
               tap qualunque fuori da un campo di testo avrebbe lasciato la
               pagina non selezionabile finche' non fosse arrivato un gesto
               vero a pulirla. */
            else document.body.classList.remove('no-select');







        }















        /* ---- SWIPE ORIZZONTALE ---- */







        function activateSwipeGesture(ctx){







            gestureBlock = true;







            closeAllSwipes(ctx.row);







            ctx.content.style.transition = 'none';







            ctx.maxReveal = ctx.row.querySelector('.swipe-actions').offsetWidth || 172;







            ctx.startTranslate = ctx.row._swipeX || 0;







            document.body.classList.add('no-select');







        }







        function doSwipeMove(e){







            const ctx = pend;







            let x = ctx.startTranslate + (e.clientX - ctx.startX);







            if (x < 0) x = x * 0.15;







            if (x > ctx.maxReveal) x = ctx.maxReveal; // stop netto: mai oltre i pulsanti rivelati







            ctx.content.style.transform = `translateX(${x}px)`;







            ctx.currentX = x;







        }







        function finalizeSwipe(ctx, committed){







            document.body.classList.remove('no-select');







            gestureBlock = false;







            ctx.content.style.transition = 'transform .34s var(--snap)';







            let target = 0;







            if (committed && ctx.currentX !== undefined) target = ctx.currentX > ctx.maxReveal * 0.4 ? ctx.maxReveal : 0;







            ctx.content.style.transform = `translateX(${target}px)`;







            ctx.row._swipeX = target;







            if (target > 0) { suppressNextRowClick = true; setTimeout(()=> suppressNextRowClick = false, 260); buzz(8); }







        }















        /* ---- RIORDINO A PRESSIONE PROLUNGATA ---- */







        function activateReorder(ctx){

            ctx.reordering = true;

            gestureBlock = true;

            if (window.StandoutSaveBar) StandoutSaveBar.hide(true);

            buzz(12);







            document.body.classList.add('no-select');















            const row = ctx.row;







            const child = isChildRow(row);







            ctx.blockRows = child ? [row] : getBlockRows(row);







            ctx.scopeParent = child ? getBlockStart(row) : null;















            const list = document.getElementById('fcRows');







            const firstRect = ctx.blockRows[0].getBoundingClientRect();







            const lastRect = ctx.blockRows[ctx.blockRows.length - 1].getBoundingClientRect();







            const totalHeight = lastRect.bottom - firstRect.top;







            const referenceNode = ctx.blockRows[ctx.blockRows.length - 1].nextElementSibling;















            const placeholder = document.createElement('div');







            placeholder.className = 'fc-row-placeholder';







            placeholder.style.height = totalHeight + 'px';







            list.insertBefore(placeholder, referenceNode);







            ctx.placeholder = placeholder;















            const ghost = document.createElement('div');







            ghost.className = 'fc-drag-ghost';







            ghost.style.position = 'fixed';







            ghost.style.left = firstRect.left + 'px';







            ghost.style.top = firstRect.top + 'px';







            ghost.style.width = firstRect.width + 'px';







            ghost.style.zIndex = 9999;







            ghost.style.display = 'flex';







            ghost.style.flexDirection = 'column';







            ghost.style.gap = '14px';







            ctx.blockRows.forEach(r=>{ r.classList.add('is-dragging-row'); ghost.appendChild(r); });







            document.body.appendChild(ghost);







            ctx.ghost = ghost;







            activeDrag = ctx;







        }















        function doReorderMove(e){







            const ctx = pend;







            const dy = e.clientY - ctx.startY;







            ctx.ghost.style.transform = `translateY(${dy}px) scale(1.015)`;







            lastPointerY = e.clientY;







            ensureAutoScroll();







            updateReorderPlacement(ctx, e.clientY);







        }















        function scopeListFor(ctx){







            const list = document.getElementById('fcRows');







            if (ctx.scopeParent){







                const out = [];







                let cur = ctx.scopeParent.nextElementSibling;







                while (cur && (cur.classList.contains('is-child') || cur === ctx.placeholder)) {







                    out.push(cur);







                    cur = cur.nextElementSibling;







                }







                return out;







            }







            return Array.from(list.children).filter(el=> el === ctx.placeholder || !el.classList.contains('is-child'));







        }















        function nodeRect(el, ctx){







            if (el === ctx.placeholder) return el.getBoundingClientRect();







            if (!ctx.scopeParent && !el.classList.contains('is-child')){







                const rows = getBlockRows(el);







                const a = rows[0].getBoundingClientRect();







                const b = rows[rows.length - 1].getBoundingClientRect();







                return { top: a.top, bottom: b.bottom };







            }







            return el.getBoundingClientRect();







        }















        function updateReorderPlacement(ctx, pointerY){







            const list = document.getElementById('fcRows');







            const scope = scopeListFor(ctx);







            const idx = scope.indexOf(ctx.placeholder);







            if (idx < 0) return;















            if (idx > 0){







                const prev = scope[idx - 1];







                const r = nodeRect(prev, ctx);







                const mid = r.top + (r.bottom - r.top) / 2;







                if (pointerY < mid){







                    flip(list, ()=> list.insertBefore(ctx.placeholder, prev));







                    buzz(4);







                    return;







                }







            }







            if (idx < scope.length - 1){







                const next = scope[idx + 1];







                const r = nodeRect(next, ctx);







                const mid = r.top + (r.bottom - r.top) / 2;







                if (pointerY > mid){







                    const afterEl = (!ctx.scopeParent && !next.classList.contains('is-child'))







                        ? getBlockRows(next)[getBlockRows(next).length - 1].nextElementSibling







                        : next.nextElementSibling;







                    flip(list, ()=> list.insertBefore(ctx.placeholder, afterEl));







                    buzz(4);







                    return;







                }







            }







        }















        function finalizeReorder(ctx){

            if (window.StandoutSaveBar) StandoutSaveBar.hide(false);

            stopAutoScroll();







            document.body.classList.remove('no-select');







            gestureBlock = false;







            activeDrag = null;















            const list = document.getElementById('fcRows');







            const placeholder = ctx.placeholder;







            flip(list, ()=>{







                ctx.blockRows.forEach(r=>{ r.classList.remove('is-dragging-row'); list.insertBefore(r, placeholder); });







                placeholder.remove();







            });







            ctx.ghost.remove();







            buzz(10);








            /* Mancava: il riordino cambiava l'ordine in pagina ma non segnava
               niente come sporco. Il salvataggio automatico non scattava mai
               per un semplice riordino, e chiudendo la scheda l'ordine nuovo
               si perdeva senza nessun avviso -- beforeunload dipende dalla
               stessa variabile isDirty che qui non veniva mai toccata. */
            dopoRiordino();

        }















        /* ---- AUTO-SCROLL AI BORDI ---- */







        function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }







        function autoScrollLoop(){







            const margin = 110, maxSpeed = 16;







            const vh = window.innerHeight;







            let dy = 0;







            if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);







            else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);







            if (dy && activeDrag) window.scrollBy(0, dy);







            autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;







        }







        function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }















        document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, {passive:false});







    })();















    /* ============================================================







       REGISTRAZIONE AUDIO (con timer live, tinta elegante)







       ============================================================ */







    let currentRecorder = null;







    let currentBtn = null;







    let currentChunks = [];







    let recTimerInterval = null;







    let recStartTime = 0;















    function startRecTimer(btn){







        recStartTime = Date.now();







        clearInterval(recTimerInterval);







        recTimerInterval = setInterval(()=>{







            const s = Math.floor((Date.now() - recStartTime) / 1000);







            const mm = Math.floor(s / 60), ss = String(s % 60).padStart(2, '0');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = mm + ':' + ss;







        }, 500);







    }







    function stopRecTimer(){ clearInterval(recTimerInterval); recTimerInterval = null; }















    /* Mostra o nasconde il tasto di cancellazione secondo che un audio ci sia
       o no. Un punto solo: cosi' il tasto non resta acceso su un campo vuoto
       ne' sparito su uno pieno, qualunque strada abbia portato li'. */
    function aggiornaTastoAudio(row, type){
        const player = row.querySelector('.audio-player-' + type);
        const tasto  = row.querySelector('.btn-del-audio[data-tipo="' + type + '"]');
        if (!tasto) return;
        const haAudio = !!(player && (player.getAttribute('src') || player.classList.contains('has-audio')));
        tasto.hidden = !haAudio;
    }

    /**
     * Cancella l'audio di un campo. Due casi molto diversi sotto lo stesso
     * pulsante:
     *
     *  - audio appena registrato e non ancora salvato: vive solo come
     *    proprieta' JS sulla riga, basta buttarlo e ripulire il lettore;
     *  - audio gia' nel database: toglierlo dal lettore non basta, perche' il
     *    salvataggio manda i file solo quando ce ne sono di nuovi e non ha
     *    modo di dire "questa colonna va svuotata". Serve segnarlo sulla riga
     *    (dataset.clearAudioQ / clearAudioA) e mandarlo al server come campo
     *    a parte: e' quello che legge poi fc_insert_save.php.
     */
    function eliminaAudioRiga(btn, type){
        const row = btn.closest('.fc-row');
        if (!row) return;

        const player = row.querySelector('.audio-player-' + type);
        const eraSalvato = !!(player && player.getAttribute('src') && !player.src.startsWith('blob:'));

        if (player) {
            /* L'oggetto URL di una registrazione va revocato: senza, il file
               resta in memoria del browser finche' la pagina non si ricarica. */
            if (player.src && player.src.startsWith('blob:')) URL.revokeObjectURL(player.src);
            player.removeAttribute('src');
            player.classList.remove('has-audio');
            player.load();
        }

        if (type === 'q') { delete row.qAudioBlob; delete row.qAudioExt; }
        else              { delete row.aAudioBlob; delete row.aAudioExt; }

        // solo se era gia' nel database c'e' qualcosa da far cancellare al server
        if (eraSalvato) row.dataset['clearAudio' + (type === 'q' ? 'Q' : 'A')] = '1';

        aggiornaTastoAudio(row, type);

        /* Non basta markDirty(): quello segna la PAGINA come modificata, non
           la riga. Il percorso veloce del salvataggio salta le righe rimaste
           'clean', quindi una carta il cui unico cambiamento e' l'audio
           cancellato non arriverebbe mai al server. */
        if (row.dataset.status === 'clean') row.dataset.status = 'edited';
        if (typeof updateStatusVisual === 'function') updateStatusVisual(row);

        markDirty();
        if (typeof aggiornaRigheTitolo === 'function') aggiornaRigheTitolo();
        showToast(T('fc.ibf_audio_eliminato'));
    }

    async function toggleRowRecord(btn, type){







        if (btn.classList.contains('recording')) {







            currentRecorder.stop();







            stopRecTimer();







            btn.classList.remove('recording');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = T('fc.ibf_registra');







            return;







        }















        if (currentRecorder) {







            showToast(T('fc.ibf_ferma_registrazione'), 'error');







            return;







        }















        try {







            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });







            currentRecorder = new MediaRecorder(stream);







            currentChunks = [];







            currentBtn = btn;















            currentRecorder.ondataavailable = e=>{ if (e.data.size > 0) currentChunks.push(e.data); };















            currentRecorder.onstop = () => {







                const row = currentBtn.closest('.fc-row');







                const mimeType = currentRecorder.mimeType || 'audio/webm';







                const blob = new Blob(currentChunks, { type: mimeType });







                const ext = mimeType.includes('mp4') ? 'm4a' : 'webm';







                const player = row.querySelector('.audio-player-' + type);















                if (player) {







                    player.src = URL.createObjectURL(blob);







                    player.classList.add('has-audio');







                }







                if (type === 'q') { row.qAudioBlob = blob; row.qAudioExt = ext; }







                else { row.aAudioBlob = blob; row.aAudioExt = ext; aggiornaRigheTitolo(); }

                /* Registrando si annulla una cancellazione chiesta prima: il
                   campo torna pieno, e il server non deve piu' svuotarlo. */

                delete row.dataset['clearAudio' + (type === 'q' ? 'Q' : 'A')];

                aggiornaTastoAudio(row, type);















                stream.getTracks().forEach(t=> t.stop());







                currentRecorder = null;







            };















            currentRecorder.start();







            btn.classList.add('recording');







            const label = btn.querySelector('.rec-label');







            if (label) label.textContent = '0:00';







            startRecTimer(btn);







            buzz(10);







        } catch (err) {







            showToast(T('fc.ifc_no_microfono'), 'error');







        }







    }















    /* ============================================================

       SALVATAGGIO

       ============================================================

       Una carta qui dentro puo' essere nuova (da inserire) oppure gia' salvata

       (da aggiornare): e' il lavoro di fc_insert_save.php, quindi si manda

       li'. La pagina non si ricarica mai: il server risponde con l'id vero di

       ogni riga inviata e la pagina si allinea da sola - ed e' cosi' che una

       carta appena creata ottiene l'id senza il quale non le si possono

       agganciare esempi.

       Restituisce true/false: e' quello che guarda la barra flottante. */

    async function salvaTutto(manuale){

        /* In modifica il salvataggio manda quello che vede: le carte non
           ancora disegnate (caricamento a lotti) risulterebbero cancellate. */

        if (MODO === 'edit') assicuraTutteLeRighe();

        const catInput = document.querySelector('input[name="category_id"]');

        const catId = catInput ? catInput.value : '';

        if (catId === '' || catId === '0') {

            /* Senza categoria non si puo' scrivere niente. In automatico si tace

               e si riprova piu' tardi: un avviso ogni venticinque secondi

               sarebbe solo fastidioso. */

            if (manuale) showToast(T('fc.ibf_serve_categoria'), 'error');

            return false;

        }



        // Riversa l'HTML dagli editor agli input nascosti prima di leggere i campi

        if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();



        const fd = new FormData();

        fd.append('action', 'save_big_edit');

        fd.append('category_id', catId);

        fd.append('csrf_token', CSRF_TOKEN);

        if (FCV) fd.append('course', FC.corso);

        fd.append('reordered', '1');   // qui la struttura conta sempre

        /* In inserimento le carte nuove si accodano a quelle gia' nella
           categoria (append=1). In modifica la pagina ha in mano tutte le
           carte e le puo' rinumerare da capo, quindi append=0. */
        fd.append('append', MODO === 'edit' ? '0' : '1');



        const righe = document.querySelectorAll('#fcRows .fc-row.data-row');

        const inviate = [];

        let indiceInvio = 0;

        let currentParentIndex = -1;



        righe.forEach((row)=>{

            const isChild = row.classList.contains('is-child') ? 1 : 0;

            /* Una riga completamente vuota — nessun testo, nessuna immagine,
               nessun audio, ne' in domanda ne' in risposta — non si manda:
               non ha senso creare o aggiornare una carta col nulla. Due
               eccezioni, entrambe casi in cui la riga sembra vuota ma in
               realta' il server deve comunque fare qualcosa:
                 - un padre che ha almeno un figlio subito sotto fra le righe
                   da inviare: saltarlo spezzerebbe il riferimento dei figli,
                   che puntano al padre per POSIZIONE nell'elenco inviato
                   (parent_idx), non per id;
                 - una riga con un segnale di cancellazione audio/immagine in
                   sospeso: quel segnale E' la richiesta da mandare, anche se
                   non e' rimasto altro contenuto a corredarla.
               Le righe gia' segnate per la cancellazione non vanno mai
               saltate qui: e' il loro status a dover arrivare al server,
               altrimenti la cancellazione non succede mai. */
            if (row.dataset.status !== 'deleted' && rigaCompletamenteVuota(row) && !haSegnaleCancellazione(row)) {
                const haFigli = !isChild && row.nextElementSibling && row.nextElementSibling.classList.contains('is-child');
                if (!haFigli) return;
            }

            if (!isChild) currentParentIndex = indiceInvio;



            const rid = row.dataset.rid;

            const qDescEl = document.getElementById('q-desc-' + rid);

            const aDescEl = document.getElementById('a-desc-' + rid);



            /* Se il campo nascosto e' vuoto si legge direttamente dall'editor

               visibile: succede quando la sincronizzazione non e' passata. */

            let qVal = qDescEl ? qDescEl.value : '';

            if (!qVal && document.getElementById('q-desc-' + rid + '_editor')) {

                const qEd = document.getElementById('q-desc-' + rid + '_editor');

                qVal = qEd.value || qEd.innerText || '';

            }

            let aVal = aDescEl ? aDescEl.value : '';

            if (!aVal && document.getElementById('a-desc-' + rid + '_editor')) {

                const aEd = document.getElementById('a-desc-' + rid + '_editor');

                aVal = aEd.value || aEd.innerText || '';

            }

            /* Spazi e a capo superflui in testa e in coda si tolgono qui, non
               prima: e' l'unico punto che entrambi i percorsi di salvataggio
               (quello rapido e quello completo) attraversano sempre, quindi
               una correzione sola vale per tutti e due. fcdTrimText lascia
               stare il testo se non lo riconosce, invece di romperlo. */
            qVal = FCV ? qVal.trim() : fcdTrimText(qVal);
            aVal = FCV ? aVal.trim() : fcdTrimText(aVal);



            fd.append(`cards[${indiceInvio}][id]`, row.dataset.id);

            fd.append(`cards[${indiceInvio}][status]`, row.dataset.status);

            fd.append(`cards[${indiceInvio}][orig_type]`, row.dataset.origType || (isChild ? 'child' : 'parent'));

            fd.append(`cards[${indiceInvio}][is_child]`, isChild);

            fd.append(`cards[${indiceInvio}][parent_idx]`, currentParentIndex);

            fd.append(`cards[${indiceInvio}][q]`, qVal);

            fd.append(`cards[${indiceInvio}][a]`, aVal);

            /* La parola ha due campi in piu': il significato, e l'esempio
               scritto al volo (il server lo aggiunge agli esempi della carta). */
            if (FCV) {
                const mEl = document.getElementById('m-desc-' + rid);
                const eEl = document.getElementById('e-desc-' + rid);
                fd.append(`cards[${indiceInvio}][meaning]`, mEl ? mEl.value.trim() : '');
                if (eEl && eEl.value.trim() !== '' && row.dataset.status !== 'deleted') fd.append(`cards[${indiceInvio}][example]`, eEl.value.trim());
            }



            /* Immagini e audio della riga: prima non venivano mandati affatto,

               e si perdevano al salvataggio. */

            if (row.qNewImgFile) fd.append(`row_q_img[${indiceInvio}]`, row.qNewImgFile);
            /* immagine da un link incollato (import da Excel/JSON): la scarica il server */
            else if (row.qImgLink) fd.append(`cards[${indiceInvio}][img_url]`, row.qImgLink);

            if (row.aNewImgFile) fd.append(`row_a_img[${indiceInvio}]`, row.aNewImgFile);

            if (row.qAudioBlob) fd.append(`audio_q[${indiceInvio}]`, row.qAudioBlob, `q_${indiceInvio}.${row.qAudioExt}`);

            if (row.aAudioBlob) fd.append(`audio_a[${indiceInvio}]`, row.aAudioBlob, `a_${indiceInvio}.${row.aAudioExt}`);

            /* Cancellazione di un audio gia' nel database: non e' un file da
               caricare, e' una colonna da svuotare — quindi viaggia come campo
               a se', non fra i file. */

            if (row.dataset.clearAudioQ === '1') fd.append(`cards[${indiceInvio}][clear_audio_q]`, '1');

            if (row.dataset.clearAudioA === '1') fd.append(`cards[${indiceInvio}][clear_audio_a]`, '1');

            /* Stessa cosa per le immagini: cancellarle non e' caricare un
               file nuovo, e' svuotare una colonna che ne aveva gia' uno. */

            if (row.dataset.clearImageQ === '1') fd.append(`cards[${indiceInvio}][clear_image_q]`, '1');

            if (row.dataset.clearImageA === '1') fd.append(`cards[${indiceInvio}][clear_image_a]`, '1');



            inviate.push({ row: row, idx: indiceInvio });

            indiceInvio++;

        });



        if (inviate.length === 0) {

            if (manuale) showToast(T('fc.ibf_serve_riga'), 'error');

            /* Non c'era nessuna riga da mandare: qualunque riordino riguardava
               righe vuote, che nel database non esistono. Lasciando la spia
               accesa la pagina sarebbe rimasta "da salvare" per sempre, con un
               salvataggio automatico che riparte ogni venticinque secondi per
               non trovare mai niente da fare. */
            ordineCambiato = false;

            isDirty = false;

            return false;

        }



        try {

            const res = await fetch('fc_insert_save.php', { method: 'POST', body: fd });

            const data = await res.json();

            if (data.success) {

                applicaMappa(inviate, data.map || {});

                /* Il percorso completo del server riscrive custom_order di
                   tutte le righe ricevute, quindi l'ordine a video e quello
                   nel database ora coincidono. */
                ordineCambiato = false;

                isDirty = false;

                if (manuale) showToast(T('fc.ifc_salvataggio_completato'));

                return true;

            }

            showToast(T('fc.ibf_errore_salvataggio') + (data.error || ''), 'error');

        } catch (err) {

            showToast(T('fc.ibf_errore_rete'), 'error');

        }

        return false;

    }



    /* Allinea la pagina a quello che il server ha davvero scritto: le carte

       nuove ricevono il loro id (e con l'id il pulsante degli esempi diventa

       vero), quelle eliminate spariscono, i file gia' caricati si staccano

       dalla riga per non essere rimandati identici al giro dopo. */

    function applicaMappa(inviate, mappa){

        inviate.forEach(function(v){

            const info = mappa[v.idx];

            if (!info) return;

            const row = v.row;

            if (info.type === 'deleted') { row.remove(); return; }



            /* L'esempio scritto nella riga e' diventato un esempio vero: il
               campo sparisce, da qui in poi si gestisce dal pulsante.
               Va tolto PRIMA di segnare la riga pulita: il campo ha appena
               ricevuto il testo, e il browser, togliendolo, spara un "change"
               che la rimarcherebbe come modificata. */
            if (info.ex) {
                const exW = row.querySelector('.fcv-ex-wrap');
                if (exW) { if (exW.contains(document.activeElement)) document.activeElement.blur(); exW.remove(); }
            }
            const eraNuova = row.dataset.status === 'new' || parseInt(row.dataset.id, 10) !== parseInt(info.id, 10);

            row.dataset.id = info.id;

            row.dataset.origType = info.type;

            row.dataset.status = 'clean';

            if ((eraNuova || info.ex) && !row.esempioIA) aggiornaExChip(row, info.id, info.ex || 0);


            /* L'esempio generato dall'IA aspettava attaccato alla riga: adesso
               la carta ha un id, quindi c'e' qualcosa a cui agganciarlo.

               L'ordine conta: se c'e' un esempio in sospeso, si chiama SOLO
               salvaEsempio() e non aggiornaExChip() sopra — salvaEsempio
               aggiorna gia' da sola la pastiglia con il conteggio giusto, e
               chiamarle entrambe la resetterebbe a zero un attimo prima,
               creando uno sfarfallio e una richiesta sprecata. */
            if (row.esempioIA && typeof FCAIP !== 'undefined') FCAIP.salvaEsempio(row, info.id, info.type);



            delete row.qNewImgFile;
            delete row.qImgLink;

            delete row.aNewImgFile;

            delete row.qAudioBlob;

            delete row.aAudioBlob;

            /* Il server ha appena svuotato la colonna: la richiesta e' stata
               eseguita e non va rimandata al salvataggio successivo. */

            delete row.dataset.clearAudioQ;

            delete row.dataset.clearAudioA;

            delete row.dataset.clearImageQ;

            delete row.dataset.clearImageA;

        });

        updateGenerateButtonState();

    }



    /* Il pulsante in fondo: salva e poi porta alla categoria. E' l'unica cosa

       che fa ancora "finire" la pagina; il salvataggio in se' non e' piu' un

       punto di non ritorno. */

    document.getElementById('imageForm').addEventListener('submit', async function(e){

        e.preventDefault();



        if (righeVive().length === 0) { showToast(T('fc.ibf_serve_riga'), 'error'); return; }



        /* Il pulsante non e' piu' in pagina, ma questo gestore puo' ancora
           scattare: in un form basta premere Invio in un campo. Quindi qui non
           si da' per scontato che esista. */
        const subBtn = document.getElementById('submitBtn');

        const originale = subBtn ? subBtn.innerHTML : '';

        if (subBtn) subBtn.disabled = true;

        if (subBtn) subBtn.innerHTML = '<span class="spinner"></span> ' + T('fc.ifc_salvataggio');



        const ok = await salvaTutto(true);

        if (!ok) {

            if (subBtn) subBtn.disabled = false;

            if (subBtn) subBtn.innerHTML = originale;

            return;

        }

        if (window.StandoutSaveBar) StandoutSaveBar.markSaved();



        const catInput = document.querySelector('input[name="category_id"]');

        document.getElementById('imageForm').style.display = 'none';

        document.getElementById('success-count').textContent = righeVive().length;

        document.getElementById('success-msg').style.display = 'block';

        window.scrollTo({ top: 0, behavior: 'smooth' });

        setTimeout(()=>{ window.location.href = fcUrl('fc_view.php?category_id=' + (catInput ? catInput.value : '')); }, 2000);

    });



    /* La freccia in alto a sinistra torna alla categoria su cui si sta
       lavorando. L'indirizzo si aggiorna a ogni cambio di selezione, non una
       volta sola all'avvio: la categoria si puo' cambiare a meta' lavoro. */
    function aggiornaLinkRitorno(){
        const back = document.getElementById('hdrBack');
        if (!back) return;
        const cat = document.querySelector('input[name="category_id"]');
        // Senza una categoria scelta, "torna alla categoria" non ha un posto
        // dove tornare: fc_view.php senza id non mostrerebbe
        // niente di utile. L'elenco delle categorie e' il posto giusto da
        // cui ripartire in quel caso.
        back.href = (cat && cat.value)
            ? fcUrl('fc_view.php?category_id=' + encodeURIComponent(cat.value))
            : fcUrl('fc_decks.php');
    }
    document.addEventListener('DOMContentLoaded', aggiornaLinkRitorno);
    document.addEventListener('change', aggiornaLinkRitorno);

    /* Con modifiche in sospeso la freccia non se ne va in silenzio: propone
       di salvare, di uscire comunque scartando le modifiche, o di restare.
       Il beforeunload sotto resta come rete per tutti gli altri modi di
       uscire (chiusura della scheda, tasto indietro del browser). */
    document.addEventListener('DOMContentLoaded', function(){
        const back = document.getElementById('hdrBack');
        const modale = document.getElementById('uscitaModale');
        if (!back || !modale) return;

        function chiudiUscitaModale() {
            modale.hidden = true;
            if (typeof FCAI !== 'undefined') FCAI.unlockScroll();
        }

        back.addEventListener('click', function(e){
            if (!isDirty) return;
            e.preventDefault();
            modale.hidden = false;
            if (typeof FCAI !== 'undefined') FCAI.lockScroll();
        });

        modale.addEventListener('click', function(ev){
            if (ev.target === modale) chiudiUscitaModale();
        });

        document.getElementById('uscitaAnnulla').addEventListener('click', chiudiUscitaModale);

        document.getElementById('uscitaSenzaSalvare').addEventListener('click', function(){
            chiudiUscitaModale();
            isDirty = false; // altrimenti beforeunload chiederebbe conferma un'altra volta, subito dopo
            window.location.href = back.href;
        });

        document.getElementById('uscitaSalva').addEventListener('click', async function(){
            const btn = this;
            btn.disabled = true;
            if (!await salvaTutto(true)) { btn.disabled = false; return; }
            chiudiUscitaModale();
            isDirty = false;
            window.location.href = back.href;
        });
    });

    document.addEventListener('DOMContentLoaded', function(){
        const viewer = document.getElementById('imgViewer');
        if (!viewer) return;

        document.getElementById('imgViewerChiudi').addEventListener('click', chiudiImgViewer);
        viewer.addEventListener('click', function(ev){
            if (ev.target === viewer) chiudiImgViewer();
        });

        document.getElementById('imgViewerRimuovi').addEventListener('click', function(){
            if (!imgViewerChip) return;
            const chip = imgViewerChip;
            const type = chip.id.startsWith('q-') ? 'q' : 'a';
            chiudiImgViewer();
            eliminaImmagineRiga(chip, type);
        });

        document.getElementById('imgViewerSostituisci').addEventListener('click', function(){
            if (!imgViewerChip) return;
            const input = imgViewerChip.querySelector('input[type=file]');
            chiudiImgViewer();
            /* Riusa l'input gia' esistente e il suo onchange=previewRowImage:
               nessuna logica di caricamento nuova, solo la stessa strada di
               sempre aperta da un altro punto. */
            if (input) input.click();
        });
    });


    /* Uscendo con delle modifiche non salvate il browser chiede conferma. */

    window.addEventListener('beforeunload', function(e){ if (isDirty) { e.preventDefault(); e.returnValue = ''; } });



    /* Barra flottante: pulsante sempre a portata di pollice e interruttore per

       il salvataggio automatico. L'aggancio aspetta il caricamento perche'

       fc_autosave_bar.js viene incluso in fondo alla pagina. */

    window.addEventListener('DOMContentLoaded', function(){

        if (window.StandoutSaveBar) StandoutSaveBar.init({ key: 'inserimento_big_fc', save: salvaTutto });

        /* In modifica la pagina nasce con le carte gia' nella categoria; in
           inserimento resta vuota, pronta per quelle nuove. */

        if (MODO === 'edit') hydrateInitialCards();

    });



    updateGenerateButtonState();

</script>

<?php include __DIR__ . '/fc_examples_modal.php'; ?>

</body>







</html>



