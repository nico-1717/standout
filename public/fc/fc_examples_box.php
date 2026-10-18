<?php
/* fc_examples_box.php — il riquadro "N esempi" (solo il markup PHP).
 *
 * Separata da fc_examples_modal.php apposta: quel file stampa anche il markup
 * dei due popup (lista e form) e la sua parte JS di apertura/salvataggio, e
 * va incluso una volta sola per pagina, di solito in fondo. render_examples_
 * section() invece serve PRIMA, nel punto esatto in cui una carta deve
 * mostrare il suo riquadro — includerla da fc_examples_modal.php avrebbe
 * significato portarsi dietro anche i popup solo per una funzione.
 *
 * Usata da fc_view.php e fc_card_edit.php: prima
 * viveva definita due volte, una per pagina.
 */

if (!function_exists('render_examples_section')) {

/**
 * Stampa il riquadro se la carta ha almeno un esempio, altrimenti non stampa
 * nulla. Il riquadro nasce vuoto: fc_examples_modal.js lo riempie via
 * fc_examples_api.php, leggendo data-fc-id/data-fc-type.
 *
 *   $realId           id vero della carta (per data-fc-id e la richiesta API)
 *   $type             'parent' o 'child'
 *   $lookupId         chiave con cui cercare il conteggio in $examples_counts
 *                      (nelle pagine che tengono i figli con chiave negativa)
 *   $examples_counts  mappa lookupId -> quanti esempi ha
 */
function render_examples_section($realId, $type, $lookupId, $examples_counts) {
    $count = isset($examples_counts[$lookupId]) ? $examples_counts[$lookupId] : 0;
    if ($count <= 0) return;
    ?>
    <div class="qa-examples">
        <div class="qa-label"><?php te('fc.vis_n_esempi', ['n' => (int)$count]); ?></div>
        <div class="qa-examples-box" data-fc-id="<?= (int)$realId ?>" data-fc-type="<?= sec_h($type) ?>"></div>
    </div>
    <?php
}

}
