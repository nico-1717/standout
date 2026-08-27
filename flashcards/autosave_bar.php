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
 * NOTA LINGUA: i testi sono ancora scritti a mano in italiano, come il resto
 * di queste quattro pagine. Quando toccheranno a loro nel giro delle traduzioni,
 * andranno spostati nel dizionario insieme agli altri.
 */
?>
<div class="ns-savebar" id="nsSaveBar">
    <button type="button" class="ns-save-btn" id="nsSaveNow" title="Salva ora" aria-label="Salva ora">
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
            <input type="checkbox" id="nsAutoToggle" aria-label="Salvataggio automatico">
            <span class="ns-track"><span class="ns-knob"></span></span>
        </label>
        <span class="ns-last ns-mai" id="nsLastSaved"></span>
    </div>
</div>
<script src="<?php echo PWA_BASE; ?>autosave_bar.js?v=<?php echo @filemtime(dirname(__FILE__) . '/autosave_bar.js') ?: 1; ?>"></script>
