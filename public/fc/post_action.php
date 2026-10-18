<?php
/* post_action.php — invia in POST le azioni che scrivono nel database.
 *
 * PERCHE' ESISTE
 *
 * Diverse azioni del gioco flashcard erano semplici link:
 *
 *     <a href="fc_game.php?game_id=5&action=concludi">Concludi</a>
 *
 * Il problema non e' il link in se', e' il metodo. sec_require_csrf() esce
 * subito sulle richieste GET:
 *
 *     if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return;
 *
 * ed e' giusto che faccia cosi': un token scritto nell'indirizzo finisce nella
 * cronologia del browser, nei log del server e nel campo Referer mandato ai siti
 * esterni. Ma finche' l'azione viaggia in GET, quel controllo non protegge
 * niente: bastava un tag immagine su un altro sito
 *
 *     <img src="https://.../fc_game.php?game_id=5&action=concludi">
 *
 * per far chiudere una partita dal browser di chi e' connesso.
 *
 * COSA FA
 *
 * Stampa una volta per pagina un modulo nascosto e la funzione JavaScript
 * nisamaAzione(), che lo riempie e lo invia in POST con il token. Il risultato,
 * per l'utente, e' identico a prima: la pagina cambia. Per il server no: ora
 * sec_require_csrf() confronta il token e rifiuta le richieste che vengono da
 * fuori.
 *
 * COME SI USA
 *
 *     <?php require_once 'post_action.php'; nisama_azione_form(); ?>
 *
 *     <button type="button" onclick="nisamaAzione('fc_game.php', {action:'concludi', game_id:5})">
 *
 * Per chi ha bisogno del solo token (le chiamate fatte con fetch):
 *
 *     fetch(url, { method:'POST', headers:{'X-CSRF-Token': NISAMA_CSRF}, body: fd })
 *
 * NOTA SUL FUNZIONAMENTO SENZA JAVASCRIPT
 *
 * Queste pagine richiedono comunque JavaScript per funzionare (il gioco e' tutto
 * animazioni e chiamate in background), quindi non si perde niente che prima
 * funzionasse. Dove invece esisteva un ripiego senza JavaScript - la
 * cancellazione in fc_history.php - il modulo e' scritto direttamente nell'HTML,
 * cosi' continua a funzionare anche a JavaScript spento.
 */

if (!function_exists('nisama_azione_form')) {

/**
 * Stampa il modulo nascosto e la funzione nisamaAzione(). Si puo' chiamare piu'
 * volte senza rischi: dalla seconda in poi non fa niente.
 */
function nisama_azione_form(): void {
    if (defined('NISAMA_AZIONE_FORM')) return;
    define('NISAMA_AZIONE_FORM', 1);

    $token = sec_h(sec_csrf_token());
    echo <<<HTML
<form id="nisamaAzioneForm" method="post" action="" style="display:none" aria-hidden="true">
    <input type="hidden" name="csrf_token" value="$token">
</form>
<script>
/* Il token serve anche alle chiamate fatte con fetch. */
var NISAMA_CSRF = "$token";

/* Invia un'azione in POST: crea i campi che servono nel modulo nascosto e lo
   manda. La pagina cambia come con un link normale. */
function nisamaAzione(pagina, campi) {
    var f = document.getElementById('nisamaAzioneForm');
    if (!f) return false;

    /* Si tolgono i campi del giro precedente, tranne il token: chiamate
       diverse hanno campi diversi, e un residuo finirebbe nella richiesta
       sbagliata. */
    Array.prototype.slice.call(f.querySelectorAll('input')).forEach(function (i) {
        if (i.name !== 'csrf_token') f.removeChild(i);
    });

    f.action = pagina;
    Object.keys(campi || {}).forEach(function (k) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = k;
        i.value = campi[k];
        f.appendChild(i);
    });
    f.submit();
    return false;
}
</script>

HTML;
}

}
