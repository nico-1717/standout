<?php
/* fc_migliora_carta.php — "Genera/Migliora con l'IA" per le pagine a carta
 * singola (inserimento_fc.php, modifica_immagine_fc.php).
 *
 * COM'E' FATTO, E PERCHE'
 * Chiuso e' solo una pastiglia piccola; toccandola si apre un riquadro
 * grigio neutro nel flusso della pagina, non un popup a schermo intero.
 * E' lo stesso schema del blocco IA del pannello esempi (.exm-ai-wrap /
 * .exm-ai-fab in examples_modal.css): finche' non lo si tocca non chiede
 * attenzione, e quando si apre non copre il resto del modulo — cosa che
 * conta parecchio qui, dove serve poter guardare la carta mentre si scrive
 * cosa cambiarne.
 *
 * NON riusa fc_ai_pannello.js: quello e' costruito per le pagine "big",
 * dove ogni carta e' una riga fra tante. Qui c'e' una carta sola con due
 * campi fissi.
 *
 * COME SI USA
 *   <?php fc_migliora_carta_blocco(); ?>   -- una volta, dove serve
 * Poi in JS: fcMigliora.inizializza({ campoQ: 'quest', campoA: 'answ' });
 */

if (!function_exists('fc_migliora_carta_blocco')) {

function fc_migliora_carta_blocco() {
    ?>
    <?php /* Il riquadro non e' piu' una sezione che compare nella pagina: e' il
         contenuto di un popup suo. Cosi' il pulsante puo' stare sempre nello
         stesso posto (in basso a destra) senza che il modulo sotto si
         riorganizzi ogni volta che lo si apre, e il contenuto e' esattamente
         lo stesso di prima — solo dentro un pannello invece che in linea. */ ?>
    <div class="fcm-wrap" id="fcMiglioraWrap">

        <?php /* Il pulsante non sta piu' nel flusso della pagina: e' un cerchio
                 in sovraimpressione (fc_ai_fab.php), uguale in tutti i popup
                 che offrono l'IA. Il riquadro qui sotto resta dov'e'. */
           require_once 'fc_ai_fab.php';
           fc_ai_fab('fcMiglioraFab', 'fcMigliora.apri()'); ?>

        <div class="fcm-overlay" id="fcMiglioraOverlay" hidden>
        <div class="exm-ai-box fcm-box" id="fcMiglioraBox">
            <button type="button" class="fcm-collapse" onclick="fcMigliora.chiudi()" aria-label="<?php echo sec_h(t('fc.ibfai_annulla')); ?>">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>

            <div class="fcm-title" id="fcMiglioraTit"><?php te('fc.ibfai_migliora_titolo'); ?></div>
            <div class="fcm-sub" id="fcMiglioraSub"><?php te('fc.ibfai_migliora_sub_default'); ?></div>

            <div class="fcm-chips" id="fcMiglioraChips" hidden>
                <button type="button" class="fcm-chip" data-nota="<?php echo sec_h(t('fc.ibfai_nota_precisa')); ?>"><?php te('fc.ibfai_chip_precisa'); ?></button>
                <button type="button" class="fcm-chip" data-nota="<?php echo sec_h(t('fc.ibfai_nota_semplice')); ?>"><?php te('fc.ibfai_chip_semplice'); ?></button>
                <button type="button" class="fcm-chip" data-nota="<?php echo sec_h(t('fc.ibfai_nota_breve')); ?>"><?php te('fc.ibfai_chip_breve'); ?></button>
                <button type="button" class="fcm-chip" data-nota="<?php echo sec_h(t('fc.ibfai_nota_dettagliata')); ?>"><?php te('fc.ibfai_chip_dettagliata'); ?></button>
            </div>

            <textarea id="fcMiglioraNota" class="fcm-textarea" rows="2" placeholder="<?php echo sec_h(t('fc.ibfai_migliora_nota_ph_default')); ?>"></textarea>

            <label class="fcm-opz">
                <span><?php te('fc.ibfai_migliora_esempio'); ?></span>
                <input type="checkbox" id="fcMiglioraEsempio">
                <span class="fcm-lev"></span>
            </label>

            <div class="fcm-errore" id="fcMiglioraErrore" hidden></div>

            <button type="button" class="fcm-genera" id="fcMiglioraSi"><?php te('fc.ibfai_migliora_bottone'); ?></button>
        </div><!-- .fcm-box -->
        </div><!-- .fcm-overlay -->

    </div><!-- .fcm-wrap -->
    <?php
}

}
