<?php
/* autosave_bar.php — la barra di salvataggio.
 *
 * Va inclusa DENTRO <header class="app-header"> dalle pagine "big" (big_edit,
 * big_edit_fc, inserimento_big, inserimento_big_fc), al posto del vecchio
 * pulsante "Salva". Il comportamento sta tutto in autosave_bar.js: qui c'e'
 * solo il markup, cosi' e' uguale ovunque.
 *
 * Perche' in alto e non in basso: in queste pagine si scrive, e in basso c'e'
 * la tastiera. Un pulsante che si vede sempre e' utile solo se non e' proprio
 * li' dove il dito sta gia' lavorando.
 *
 * Il foglio di stile (autosave_bar.css) va richiamato nel <head> della pagina,
 * insieme al suo.
 *
 * PIU' ISTANZE SULLA STESSA PAGINA
 * Una pagina puo' avere una seconda barra indipendente (un modale con un
 * salvataggio tutto suo, per dire): si include questo stesso file impostando
 * $ns_instance PRIMA dell'include, cosi' gli id non si scontrano con quelli
 * della barra principale.
 *   <?php $ns_instance = 'esempi'; include 'autosave_bar.php'; unset($ns_instance); ?>
 * Senza $ns_instance si ottengono gli id di sempre (nsSaveBar, nsSaveNow...),
 * quindi le quattro pagine che gia' lo includono non cambiano comportamento.
 * Lo script autosave_bar.js si carica una volta sola qualunque sia il numero
 * di barre incluse nella pagina: sa gestire piu' istanze da solo.
 *
 * NOTA LINGUA: i testi sono ancora scritti a mano in italiano, come il resto
 * di queste quattro pagine. Quando toccheranno a loro nel giro delle traduzioni,
 * andranno spostati nel dizionario insieme agli altri.
 */
$ns_suffix = (isset($ns_instance) && $ns_instance !== '')
    ? '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ns_instance)
    : '';
?>
<div class="ns-savebar" id="nsSaveBar<?php echo $ns_suffix; ?>">
    <button type="button" class="ns-save-btn" id="nsSaveNow<?php echo $ns_suffix; ?>" title="Salva ora" aria-label="Salva ora">
        <span class="ns-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/>
                <path d="M17 21v-8H7v8"/>
                <path d="M7 3v5h8"/>
            </svg>
        </span>
        <span class="ns-spin" aria-hidden="true"></span>
    </button>
    <div class="ns-auto">
        <label class="ns-switch" title="Salvataggio automatico">
            <input type="checkbox" id="nsAutoToggle<?php echo $ns_suffix; ?>" aria-label="Salvataggio automatico">
            <span class="ns-track"><span class="ns-knob"></span></span>
        </label>
        <span class="ns-last ns-mai" id="nsLastSaved<?php echo $ns_suffix; ?>"></span>
    </div>
</div>
<?php if (!defined('NS_AUTOSAVE_JS_INCLUDED')): define('NS_AUTOSAVE_JS_INCLUDED', true); ?>
<script src="<?php echo PWA_BASE; ?>autosave_bar.js?v=<?php echo @filemtime(dirname(__FILE__) . '/autosave_bar.js') ?: 1; ?>"></script>
<?php endif; ?>
