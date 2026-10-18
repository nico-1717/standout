<?php
/* fc_ai_fab.php — il pulsante circolare dell'IA (solo markup).
 *
 * Lo usano i popup di inserimento/modifica carta e di inserimento/modifica
 * esempio. Sta in sovraimpressione in basso a destra rispetto al pannello che
 * lo contiene: quel pannello deve avere position:relative, altrimenti il
 * pulsante si ancora al primo antenato posizionato che trova.
 *
 *   fc_ai_fab('fcMiglioraFab', 'fcMigliora.apri()');
 *
 * $id      id dell'elemento (serve a chi lo deve nascondere quando il
 *          riquadro dell'IA si apre)
 * $onclick codice da eseguire al tocco
 */
if (!function_exists('fc_ai_fab')) {

function fc_ai_fab(string $id, string $onclick): void {
    ?>
    <button type="button" class="fc-aifab" id="<?php echo sec_h($id); ?>"
            onclick="<?php echo sec_h($onclick); ?>"
            aria-label="<?php te('fc.exm_genera_ia'); ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path class="fcaf-s1" d="M12 3.2l1.5 4.3 4.3 1.5-4.3 1.5L12 14.8l-1.5-4.3L6.2 9l4.3-1.5L12 3.2z"/>
            <path class="fcaf-s2" d="M19 14l.85 2.35L22.2 17.2l-2.35.85L19 20.4l-.85-2.35L15.8 17.2l2.35-.85L19 14z"/>
            <path class="fcaf-s3" d="M6 14.4l.7 1.9 1.9.7-1.9.7-.7 1.9-.7-1.9-1.9-.7 1.9-.7.7-1.9z"/>
        </svg>
    </button>
    <?php
}

}
