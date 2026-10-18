/* fc_speak.js — pronuncia delle parole (flashcard di lingua).
 *
 * Usa la sintesi vocale del browser, non un servizio esterno: costa zero,
 * funziona anche senza rete sui telefoni, e la voce e' quella che l'utente
 * conosce gia' dal suo sistema. La scelta della voce e' la stessa che faceva
 * la vecchia pagina del vocabolario (visualizzazione.php), che era stata
 * tarata a mano: prima la voce predefinita del telefono, poi la lingua
 * esatta, poi le voci di qualita' (Google, Premium, Natural); mai le voci
 * robotiche di Eloquence.
 *
 * La parola si legge due volte, la seconda piu' lenta: la prima per sentirla
 * come suona davvero, la seconda per coglierne i suoni.
 *
 *   fcSpeak(bottone, 'dog', 'en-GB')
 *   fcSpeak(bottone, 'cane', 'it-IT', true)   // una volta sola (frasi, traduzioni)
 *   fcSpeak(null, 'dog', 'en-GB', false, fine) // fine() quando ha finito (o se non puo' parlare)
 *   fcSpeakLearn(bottone, testo)   // nella lingua che si impara (window.FC)
 *   fcSpeakKnown(bottone, testo)   // nella lingua che si conosce
 */
(function () {
    'use strict';
    if (window.fcSpeak) return;

    var voci = [];
    function caricaVoci() {
        if ('speechSynthesis' in window) voci = window.speechSynthesis.getVoices() || [];
    }
    caricaVoci();
    if ('speechSynthesis' in window && window.speechSynthesis.onvoiceschanged !== undefined) {
        window.speechSynthesis.addEventListener
            ? window.speechSynthesis.addEventListener('voiceschanged', caricaVoci)
            : (window.speechSynthesis.onvoiceschanged = caricaVoci);
    }

    var BRUTTE = ['reed', 'shelley', 'sandy', 'rocko', 'flo', 'eddy', 'grandma', 'grandpa'];

    function scegliVoce(lingua) {
        if (!voci.length) caricaVoci();
        if (!voci.length) return null;
        var esatta = String(lingua || '').replace('_', '-').toLowerCase();
        var prefisso = esatta.split('-')[0];
        var valide = voci.filter(function (v) {
            if (!v.lang) return false;
            var vl = v.lang.replace('_', '-').toLowerCase();
            var nome = v.name.toLowerCase(), uri = (v.voiceURI || '').toLowerCase();
            if (vl.indexOf(prefisso) !== 0) return false;
            if (nome.indexOf('eloquence') !== -1 || uri.indexOf('eloquence') !== -1) return false;
            return BRUTTE.indexOf(nome) === -1;
        });
        if (!valide.length) return null;
        function punti(v) {
            var n = v.name.toLowerCase(), p = 0;
            /* "predefinita" vale molto sui telefoni, poco su Windows, dove la
               predefinita e' spesso la voce peggiore */
            if (v.default) p += n.indexOf('microsoft') !== -1 ? 10 : 100;
            if (v.lang.replace('_', '-').toLowerCase() === esatta) p += 50;
            if (n.indexOf('google') !== -1) p += 40;
            if (/premium|enhanced|natural|online/.test(n)) p += 30;
            return p;
        }
        valide.sort(function (a, b) { return punti(b) - punti(a); });
        return valide[0];
    }

    window.fcSpeak = function (btn, testo, lingua, unaVolta, fine) {
        testo = String(testo || '').trim();
        if (!testo || !('speechSynthesis' in window)) { if (fine) fine(); return; }
        try { window.speechSynthesis.cancel(); } catch (e) {}
        var voce = scegliVoce(lingua);
        var u1 = new SpeechSynthesisUtterance(testo);
        var u2 = new SpeechSynthesisUtterance(testo);
        [u1, u2].forEach(function (u) { u.lang = lingua; if (voce) u.voice = voce; });
        u1.rate = 1; u2.rate = 0.8;
        if (btn) btn.classList.add('speaking');
        var ultima = unaVolta ? u1 : u2;
        ultima.onend = function () { if (btn) btn.classList.remove('speaking'); if (fine) { var f = fine; fine = null; f(); } };
        ultima.onerror = ultima.onend;
        window.speechSynthesis.speak(u1);
        if (!unaVolta) window.speechSynthesis.speak(u2);
    };

    window.fcSpeakLearn = function (btn, testo) {
        var l = (window.FC && FC.impara) ? FC.impara.voce : 'en-GB';
        window.fcSpeak(btn, testo, l);
    };
    /* La lingua che si conosce si sente una volta: e' la traduzione, non una
       parola da imparare a pronunciare. */
    window.fcSpeakKnown = function (btn, testo) {
        var l = (window.FC && FC.conosce) ? FC.conosce.voce : 'it-IT';
        window.fcSpeak(btn, testo, l, true);
    };

    /* Un solo ascoltatore per tutti i bottoni con data-speak: le carte
       arrivano anche dopo (pagine successive, ricerca), e cosi' funzionano
       senza doverle ricollegare una per una. */
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-speak]') : null;
        if (!b) return;
        e.preventDefault(); e.stopPropagation();
        /* data-from: il testo sta in un campo che si sta ancora scrivendo
           (inserimento di parole), quindi si legge al momento del tocco. */
        var da = b.getAttribute('data-from');
        var campo = da ? document.getElementById(da) : null;
        var testo = b.getAttribute('data-text') || (campo ? campo.value : '') || '';
        if (b.getAttribute('data-speak') === 'known') window.fcSpeakKnown(b, testo);
        else window.fcSpeakLearn(b, testo);
    }, true);
})();
