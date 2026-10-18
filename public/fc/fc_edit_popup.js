/* fc_edit_popup.js — apre fc_card_edit.php come popup.
 *
 * PERCHE' UN IFRAME E NON innerHTML
 * Il modulo di modifica ha la sua toolbar di formattazione (una per campo),
 * il suo registratore audio, il selettore categoria, il riquadro esempi:
 * tutta roba che si inizializza da sola nei propri <script>, contando sul
 * fatto che la pagina si sta caricando normalmente. Incollare l'HTML con
 * innerHTML non fa PARTIRE quegli <script> — e' un limite del browser, non
 * un dettaglio che si possa aggirare senza ricostruire a mano
 * l'inizializzazione di ognuno di quei pezzi, con tutto il rischio di
 * lasciarne indietro uno. Un iframe carica fc_card_edit.php come se
 * fosse una scheda a se': tutto parte esattamente come parte oggi, perche'
 * e' la stessa identica pagina, solo dentro una cornice invece che a tutto
 * schermo.
 *
 * COME COMUNICA CON LA PAGINA CHE L'HA APERTO
 * fc_card_edit.php, quando si accorge di essere dentro un iframe
 * (window.parent !== window), non fa piu' il redirect che farebbe da sola:
 * manda un postMessage a chi l'ha aperta ({source:'fc-modifica-popup',
 * type:'salvato'|'eliminato'|'annulla'}) e lascia a lei la scelta di cosa
 * fare — chiudere il popup, e nel caso avvertire chi ha chiesto di sapere
 * quando succede (onSalvato).
 *
 * COME SI USA
 *   apriModificaPopup('fc_card_edit.php?fc_id=123&previous_catid=5',
 *                      function(dati){ location.reload(); });
 */
(function () {
    'use strict';

    var overlay = null, iframe = null;

    function assicuraOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'fc-edit-popup-overlay';
        /* Niente "x" qui: la pagina dentro l'iframe ne ha gia' uno nella sua
           barra in alto, e due pulsanti identici a un centimetro di distanza
           erano solo confusione — oltre a fare cose diverse, perche' questo
           chiudeva senza passare dalla conferma sulle modifiche non salvate. */
        overlay.innerHTML =
            '<div class="fc-edit-popup-panel">' +
                '<div class="fc-edit-popup-spin" aria-hidden="true">' +
                    '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>' +
                '</div>' +
                '<iframe class="fc-edit-popup-iframe"></iframe>' +
            '</div>';
        document.body.appendChild(overlay);
        iframe = overlay.querySelector('.fc-edit-popup-iframe');
        iframe.addEventListener('load', function () { overlay.classList.add('loaded'); });
        /* Toccare fuori dal pannello non chiude di forza: lo si CHIEDE alla
           pagina dentro, che sa se ci sono modifiche non salvate e nel caso
           mostra la conferma. Se per qualche motivo non risponde (pagina non
           ancora caricata), si chiude comunque: meglio che restare in trappola. */
        overlay.addEventListener('click', function (ev) {
            if (ev.target !== overlay) return;
            var dentro = iframe && iframe.contentWindow;
            if (dentro && overlay.classList.contains('loaded')) {
                dentro.postMessage({ source: 'fc-popup-host', type: 'chiedi-chiusura' }, window.location.origin);
            } else {
                chiudiModificaPopup();
            }
        });
        return overlay;
    }

    /* Segna l'indirizzo come "caricato dentro un popup". Serve al server, che
       altrimenti non ha modo di saperlo: la barra del pomodoro si innesta da
       config.php in OGNI pagina del sito, e qui dentro sarebbe la stessa barra
       della pagina sotto, piu' il padding che si porta dietro (vedi
       pomodoro_barra.php). I browser recenti lo dicono da soli con
       Sec-Fetch-Dest: iframe; questo parametro e' il ripiego per gli altri, e
       costa una riga.
       Il frammento (#...) resta in coda dov'era: un parametro infilato dopo
       il cancelletto non arriverebbe mai al server. */
    function conParametroPopup(url) {
        if (!url || url.indexOf('fc_popup=') !== -1) return url;
        var taglio = url.indexOf('#');
        var base = taglio === -1 ? url : url.slice(0, taglio);
        var frammento = taglio === -1 ? '' : url.slice(taglio);
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'fc_popup=1' + frammento;
    }

    /* La stessa funzione la usa anche il precaricamento: perche' il browser
       possa riusare quello che ha gia' scaricato, l'indirizzo chiesto in
       anticipo dev'essere IDENTICO a quello che finira' nell'iframe — un
       parametro di differenza e sono due pagine diverse. */
    window.fcUrlPopup = conParametroPopup;

    function apriModificaPopup(url, onSalvato) {
        assicuraOverlay();
        overlay.classList.remove('loaded');
        iframe.src = conParametroPopup(url);
        overlay.classList.add('on');
        document.body.classList.add('fc-edit-popup-open');
        overlay._onSalvato = onSalvato || null;
        overlay._qualcosaCreato = false;
        overlay._ultimaCarta = null;
    }

    function chiudiModificaPopup() {
        if (!overlay) return;
        overlay.classList.remove('on');
        document.body.classList.remove('fc-edit-popup-open');
        /* Il src si svuota dopo l'animazione di chiusura, non subito: farlo
           subito interromperebbe la transizione, che sull'iframe si vedrebbe
           come uno scatto. */
        setTimeout(function () { if (iframe) iframe.src = 'about:blank'; }, 300);
    }

    window.addEventListener('message', function (e) {
        if (!e.data || e.data.source !== 'fc-modifica-popup') return;
        if (e.data.type === 'annulla') {
            var creato = overlay && overlay._qualcosaCreato;
            var cb = overlay && overlay._onSalvato;
            chiudiModificaPopup();
            /* Si e' usciti annullando, ma nel frattempo una carta era stata
               creata davvero: l'elenco sotto e' comunque da aggiornare. */
            if (creato && typeof cb === 'function') cb({ type: 'creato', carta: overlay ? overlay._ultimaCarta : null });
            return;
        }
        /* 'creato' arriva dalla modalita' inserimento, che a differenza della
           modifica NON chiude il popup: la carta e' stata salvata ma si resta
           dentro per agganciarle un esempio o inserirne un'altra. Si prende
           nota che qualcosa e' cambiato, e si ricarichera' alla chiusura. */
        /* 'cambiato' e 'creato' non chiudono niente: dicono solo che il
           database e' cambiato. Con il salvataggio automatico arrivano spesso,
           ed e' proprio per questo che non devono chiudere il popup. */
        if (e.data.type === 'cambiato' || e.data.type === 'creato') {
            if (overlay) {
                overlay._qualcosaCreato = true;
                /* Si tiene da parte QUALE carta e' stata toccata: alla
                   chiusura la pagina sotto deve ritrovarsi proprio su quella,
                   com'era prima che la modifica avvenisse in un popup invece
                   che cambiando pagina. */
                if (e.data.fc_id) {
                    overlay._ultimaCarta = { id: e.data.fc_id, tipo: e.data.fc_tipo || 'parent' };
                }
            }
            return;
        }
        if (e.data.type === 'salvato' || e.data.type === 'eliminato') {
            var cb2 = overlay && overlay._onSalvato;
            var carta = overlay && overlay._ultimaCarta;
            chiudiModificaPopup();
            if (typeof cb2 === 'function') cb2(Object.assign({}, e.data, { carta: carta }));
        }
    });

    window.apriModificaPopup = apriModificaPopup;
    window.chiudiModificaPopup = chiudiModificaPopup;
})();
