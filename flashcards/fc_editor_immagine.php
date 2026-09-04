<?php
/* fc_editor_immagine.php — markup del blocco immagine, condiviso fra
 * inserimento_fc.php e modifica_immagine_fc.php.
 *
 * PERCHE' ESISTE
 * I due blocchi vivevano come funzioni quasi identiche in due file diversi
 * (fc_new_image_block in inserimento, fc_image_block in modifica): stesso
 * scopo, markup leggermente diverso, nessun modo di correggere l'uno senza
 * ricordarsi dell'altro.
 *
 * L'INTERAZIONE E' CAMBIATA
 * Prima, per scegliere un'immagine, si premeva un pulsante "Scegli foto"
 * separato dal riquadro dell'anteprima. Ora si preme DIRETTAMENTE il
 * riquadro dove l'immagine comparira': e' il riquadro stesso a essere
 * un'etichetta legata all'input file nascosto, non serve piu' un pulsante a
 * parte. Il markup lo prevede gia' per entrambe le modalita'.
 *
 * COME SI USA
 *   fc_img_box_nuova($p, $label)                 — modalita' inserimento
 *   fc_img_box_modifica($p, $label, $imgAttuale)  — modalita' modifica
 * $p e' il prefisso del campo ('question'/'answer' in inserimento,
 * 'q'/'a' in modifica — ciascuna pagina mantiene i propri nomi di campo
 * esistenti, per non dover toccare anche il salvataggio).
 */

if (!function_exists('fc_img_box_nuova')) {

/**
 * Modalita' inserimento: un riquadro solo. Vuoto mostra un'icona e l'invito
 * a scegliere; con un'immagine scelta mostra l'anteprima con una "x" per
 * toglierla, sempre senza lasciare il riquadro.
 */
function fc_img_box_nuova($p, $label) {
    ?>
    <div class="up-label"><?php echo sec_h($label); ?></div>
    <label for="<?php echo sec_h($p); ?>_image" class="img-pick-box" id="<?php echo sec_h($p); ?>_img_wrap">
        <input type="file" name="<?php echo sec_h($p); ?>_image" id="<?php echo sec_h($p); ?>_image" accept="image/*" style="display:none;">
        <span class="img-pick-empty">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
            <span><?php te('vocab.ins_scegli_foto'); ?></span>
        </span>
        <img class="img-pick-preview" id="<?php echo sec_h($p); ?>_preview" style="display:none;">
        <button type="button" class="img-pick-remove" id="<?php echo sec_h($p); ?>_remove" style="display:none;" onclick="event.preventDefault(); fcImgRimuovi('<?php echo sec_h($p); ?>');" aria-label="<?php te('vocab.ins_rimuovi'); ?>">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </label>
    <?php
}

}

if (!function_exists('fc_img_box_modifica')) {

/**
 * Modalita' modifica: due riquadri affiancati. "Attuale" e' di sola lettura
 * (mostra quello che c'e' gia' salvato, o il vuoto). "Nuova" e' cliccabile —
 * vuota invita a scegliere, con un'anteprima scelta mostra quella e resta
 * comunque cliccabile per cambiarla di nuovo. Il segnale "sostituisci" non
 * serve piu' come casella a parte: basta che un file sia stato scelto.
 * "Rimuovi immagine" resta una scelta a se', per il caso in cui si vuole
 * semplicemente togliere l'immagine senza metterne una nuova.
 */
function fc_img_box_modifica($p, $label, $imgAttuale) {
    /* $label non si stampa piu': "Immagine domanda (opzionale)" ripeteva
       un'informazione che i due riquadri danno gia' da soli — "Attuale" e
       "Nuova", dentro la sezione che si chiama gia' Domanda o Risposta. Il
       parametro resta nella firma per non toccare i punti che la chiamano. */
    ?>
    <?php /* In inserimento il riquadro "Attuale" non ha senso: non c'e' nessuna
             immagine precedente da confrontare, e mostrarne uno vuoto con
             scritto "Nessuna immagine" occupava mezza riga per non dire nulla.
             Si riconosce dal fatto che non e' stata passata un'immagine e la
             carta non esiste ancora. */
           $mostra_attuale = !empty($imgAttuale); ?>
    <div class="img-compare<?php echo $mostra_attuale ? '' : ' img-compare-solo'; ?>">
        <?php if ($mostra_attuale): ?>
        <div class="img-box img-box-current" id="<?php echo sec_h($p); ?>_current_wrap">
            <span class="img-tag"><?php te('fc.attuale'); ?></span>
            <?php /* Dentro questo blocco l'immagine c'e' per costruzione: il
                     ramo "nessuna immagine" non serve piu', perche' senza
                     immagine il riquadro non viene proprio disegnato. */ ?>
            <img src="fc_Images/<?php echo sec_h($imgAttuale); ?>" id="<?php echo sec_h($p); ?>_current_img">
        </div>
        <?php endif; ?>
        <label for="<?php echo sec_h($p); ?>New_image" class="img-box img-pick-box" id="<?php echo sec_h($p); ?>_img_wrap">
            <input type="file" name="<?php echo sec_h($p); ?>New_image" id="<?php echo sec_h($p); ?>New_image" accept="image/*" style="display:none;">
            <span class="img-tag img-tag-new"><?php te('vocab.mi_nuova'); ?></span>
            <span class="img-pick-empty img-pick-empty-sm">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                <span><?php te('vocab.mi_tocca_per_scegliere'); ?></span>
            </span>
            <img class="img-pick-preview" id="<?php echo sec_h($p); ?>_preview" style="display:none;">
        </label>
    </div>
    <label class="chk-row chk-red img-remove-row" id="<?php echo sec_h($p); ?>_remove_row"<?php echo empty($imgAttuale) ? ' style="display:none;"' : ''; ?>>
        <input type="checkbox" name="remove_<?php echo sec_h($p); ?>_img" id="remove_<?php echo sec_h($p); ?>_img" value="1" onchange="fcImgToggleRimuovi('<?php echo sec_h($p); ?>')">
        <?php te('vocab.mi_rimuovi_immagine'); ?>
    </label>
    <?php
}

}
