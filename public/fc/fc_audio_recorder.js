/* fc_audio_recorder.js — motore di registrazione audio condiviso.
 *
 * PERCHE' ESISTE
 * La stessa registrazione (avvia, ferma, timer, cambio icona, blob pronto)
 * viveva identica o quasi in tre file diversi: fc_insert_card.php,
 * fc_card_edit.php ed fc_examples_modal.php. Una correzione in uno
 * restava indietro negli altri due — esattamente il problema che l'unione di
 * inserimento_big_fc e big_edit_fc ha gia' risolto per le pagine "big"; qui
 * la stessa cura, ma per motori condivisi fra pagine che restano separate.
 *
 * COME SI USA
 *   <button id="btn_rec_q" onclick="toggleFcAudioRecord('q', 'btn_rec_q',
 *       'rec_status_q', function(blob, ext){ ...anteprima... })">
 * "chiave" e' un nome a piacere ('q', 'a', 'esempio'...): tiene separato lo
 * stato di registrazioni diverse sulla stessa pagina. btnId/statusId sono gli
 * id degli elementi da aggiornare (il bottone deve avere dentro due <svg>
 * con classe ic-mic e ic-stop, come li genera gia' fc_new_audio_block() e
 * affini). onStop(blob, ext) arriva a registrazione finita: cosa farne del
 * blob (anteprima, stato "da salvare"...) resta alla pagina, il motore non
 * lo decide.
 *
 * fcAudioBlobDi(chiave) / fcAudioExtDi(chiave) leggono l'ultimo blob
 * registrato per quella chiave, per quando la pagina deve aggiungerlo alla
 * FormData del salvataggio. fcAudioResetta(chiave) lo dimentica (dopo un
 * salvataggio riuscito, o aprendo un modulo pulito per un'altra carta).
 */
(function () {
    'use strict';

    var stati = {};

    function statoDi(chiave) {
        if (!stati[chiave]) stati[chiave] = { blob: null, ext: 'webm', recorder: null, chunks: [], timer: null, sec: 0 };
        return stati[chiave];
    }

    function fmtFcAudioTime(s) {
        var m = Math.floor(s / 60), ss = s % 60;
        return m + ':' + String(ss).padStart(2, '0');
    }

    async function toggleFcAudioRecord(chiave, btnId, statusId, onStop) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        var statusEl = statusId ? document.getElementById(statusId) : null;
        var S = statoDi(chiave);
        var registrando = btn.classList.contains('recording');

        if (!registrando) {
            var stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (err) {
                alert(T('fc.ifc_no_microfono'));
                return;
            }

            var rec = new MediaRecorder(stream);
            S.recorder = rec;
            S.chunks = [];
            rec.ondataavailable = function (e) { S.chunks.push(e.data); };
            rec.onstop = function () {
                /* mp4/m4a e' il caso di Safari; ogni altro browser registra in
                   webm. Non c'e' un terzo caso da gestire oggi. */
                var ext = rec.mimeType.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                var blob = new Blob(S.chunks, { type: rec.mimeType });
                S.blob = blob;
                S.ext = ext;
                if (typeof onStop === 'function') onStop(blob, ext);
            };
            rec.start();

            btn.classList.add('recording');
            var micIcon = btn.querySelector('.ic-mic');
            var stopIcon = btn.querySelector('.ic-stop');
            if (micIcon) micIcon.style.display = 'none';
            if (stopIcon) stopIcon.style.display = '';

            S.sec = 0;
            if (statusEl) statusEl.textContent = '0:00';
            S.timer = setInterval(function () {
                S.sec++;
                if (statusEl) statusEl.textContent = fmtFcAudioTime(S.sec);
            }, 1000);
        } else {
            if (S.recorder) {
                S.recorder.stop();
                S.recorder.stream.getTracks().forEach(function (t) { t.stop(); });
            }
            clearInterval(S.timer);

            btn.classList.remove('recording');
            var micIcon2 = btn.querySelector('.ic-mic');
            var stopIcon2 = btn.querySelector('.ic-stop');
            if (micIcon2) micIcon2.style.display = '';
            if (stopIcon2) stopIcon2.style.display = 'none';
            if (statusEl) statusEl.textContent = T('fc.ifc_audio_pronto');
        }
    }

    function fcAudioBlobDi(chiave) { return statoDi(chiave).blob; }
    function fcAudioExtDi(chiave) { return statoDi(chiave).ext; }

    /* Dimentica il blob registrato — e, se una registrazione era rimasta a
       meta' (il modulo si riapre pulito per un'altra carta/esempio prima di
       averla fermata), la ferma anche lei: senza, il timer del conteggio
       avrebbe continuato ad aggiornare uno stato che nessuno guarda piu'. */
    function fcAudioResetta(chiave) {
        var S = statoDi(chiave);
        if (S.recorder && S.recorder.state !== 'inactive') {
            try {
                S.recorder.stop();
                S.recorder.stream.getTracks().forEach(function (t) { t.stop(); });
            } catch (e) { /* gia' fermo per conto suo: pazienza */ }
        }
        clearInterval(S.timer);
        S.blob = null;
        S.chunks = [];
    }

    window.toggleFcAudioRecord = toggleFcAudioRecord;
    window.fcAudioBlobDi = fcAudioBlobDi;
    window.fcAudioExtDi = fcAudioExtDi;
    window.fcAudioResetta = fcAudioResetta;
})();
