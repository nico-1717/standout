/* fc_image_editor.js — interazione del blocco immagine condiviso.
 *
 * Usato da fc_insert_card.php (un riquadro solo) e fc_card_edit.php
 * (due riquadri, Attuale/Nuova): la stessa anteprima, la stessa logica di
 * rimozione, scritte una volta sola invece che duplicate fra le due pagine.
 *
 * L'interazione e' cambiata rispetto a prima: non c'e' piu' un pulsante
 * "Scegli foto" separato dal riquadro. Il riquadro STESSO e' l'etichetta
 * collegata all'input file (vedi fc_image_editor.php): un tocco qualunque
 * sul riquadro apre gia' il selettore, per costruzione dell'HTML — qui serve
 * solo gestire cosa succede DOPO che un file e' stato scelto.
 */

/**
 * Aggancia l'anteprima al cambio dell'input file. Va chiamata una volta per
 * ogni riquadro presente in pagina, all'avvio.
 *
 *   inputId     id dell'<input type="file">
 *   previewId   id dell'<img> dove disegnare l'anteprima
 *   wrapId      id del riquadro (label) — riceve la classe .has-img
 *   onScelta    richiamata quando un file viene scelto, prima di leggerlo:
 *               usata per lo stato "sporco" della pagina e per la mutua
 *               esclusione con "rimuovi" in modalita' modifica
 */
function fcImgSetupPreview(inputId, previewId, wrapId, onScelta) {
    var input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('change', function () {
        if (!this.files || !this.files[0]) return;

        if (typeof onScelta === 'function') onScelta();

        var preview = document.getElementById(previewId);
        var wrap = document.getElementById(wrapId);
        var reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (wrap) wrap.classList.add('has-img');
        };
        reader.readAsDataURL(this.files[0]);
    });
}

/**
 * Modalita' inserimento: toglie il file scelto e torna al riquadro vuoto.
 * Il tocco che porta qui e' gia' protetto da preventDefault() nel markup
 * (vedi fc_img_box_nuova): altrimenti, essendo il riquadro un'etichetta
 * collegata all'input, il tocco sulla "x" riaprirebbe anche il selettore
 * file appena chiuso quello vecchio.
 */
function fcImgRimuovi(p) {
    var input = document.getElementById(p + '_image');
    var preview = document.getElementById(p + '_preview');
    var wrap = document.getElementById(p + '_img_wrap');
    var removeBtn = document.getElementById(p + '_remove');
    if (input) input.value = '';
    if (preview) { preview.style.display = 'none'; preview.src = ''; }
    if (wrap) wrap.classList.remove('has-img');
    if (removeBtn) removeBtn.style.display = 'none';
}

/**
 * Modalita' modifica: la casella "Rimuovi immagine" e la scelta di un file
 * nuovo si escludono a vicenda — non ha senso rimuovere e sostituire nello
 * stesso salvataggio, e tenerle indipendenti e' esattamente il modo in cui
 * potevano finire fuori sincrono nella versione precedente. */
function fcImgToggleRimuovi(p) {
    var checkbox = document.getElementById('remove_' + p + '_img');
    var currentWrap = document.getElementById(p + '_current_wrap');
    if (checkbox.checked) {
        if (currentWrap) currentWrap.classList.add('img-marked-removed');
        // rimuovere ha la precedenza: un file eventualmente gia' scelto si scarta
        var input = document.getElementById(p + 'New_image');
        var preview = document.getElementById(p + '_preview');
        var newWrap = document.getElementById(p + '_img_wrap');
        if (input) input.value = '';
        if (preview) { preview.style.display = 'none'; preview.src = ''; }
        if (newWrap) newWrap.classList.remove('has-img');
    } else {
        if (currentWrap) currentWrap.classList.remove('img-marked-removed');
    }
}

/* Scegliere un file nuovo annulla una richiesta di rimozione fatta prima:
   sostituire E rimuovere insieme non avrebbe senso, e sostituire e' quello
   che l'utente ha appena chiesto col tocco piu' recente. */
function fcImgAnnullaRimozione(p) {
    var checkbox = document.getElementById('remove_' + p + '_img');
    var currentWrap = document.getElementById(p + '_current_wrap');
    if (checkbox && checkbox.checked) {
        checkbox.checked = false;
        if (currentWrap) currentWrap.classList.remove('img-marked-removed');
    }
}
