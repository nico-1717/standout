/* =====================================================================
   standout_ui.js — i comportamenti che vanno uguali in tutto il sito.
   =====================================================================
   Va incluso dopo standout_fetch.js e prima degli script di pagina:

     <link rel="stylesheet" href="standout_ui.css">
     <script src="standout_ui.js"></script>

   Non fa niente da solo: non aggancia eventi globali, non cambia pagine che
   non lo chiedono. E' una cassetta degli attrezzi, non un'impalcatura — cosi'
   si puo' adottare una pagina alla volta senza rischiare di rompere le altre
   ventisei.

   Tutto rispetta "riduci animazioni" del sistema: NS.poco() lo legge una
   volta e le funzioni si comportano di conseguenza (niente attese finte,
   niente ritardi a scalare).
   ===================================================================== */

var NS = (function () {
    'use strict';

    var pocoMovimento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (window.matchMedia) {
        try {
            window.matchMedia('(prefers-reduced-motion: reduce)')
                .addEventListener('change', function (e) { pocoMovimento = e.matches; });
        } catch (e) { /* browser vecchi: resta il valore iniziale */ }
    }

    /* ============================================================
       ATTESA — la forma di cio' che sta arrivando
       ============================================================ */

    /**
     * Riempie $el con N righe finte, della forma che avranno quelle vere.
     * Torna una funzione da chiamare quando i dati sono arrivati.
     *
     *   var finito = NS.scheletro(elenco, { righe: 6 });
     *   ... arrivano i dati ...
     *   finito();
     *
     * aria-busy e aria-hidden: a un lettore di schermo non si leggono sei
     * rettangoli grigi, si dice "sto caricando".
     */
    function scheletro(el, opz) {
        if (!el) return function () {};
        opz = opz || {};
        var righe = opz.righe || 5;
        var classe = opz.classe || 'ns-scheletro-riga';
        var precedente = el.innerHTML;

        var box = document.createElement('div');
        box.className = 'ns-scheletro-area';
        box.setAttribute('aria-hidden', 'true');
        var larghezze = ['', ' media', ' corta'];
        for (var i = 0; i < righe; i++) {
            var r = document.createElement('div');
            r.className = 'ns-scheletro ' + classe + larghezze[i % 3];
            box.appendChild(r);
        }
        el.innerHTML = '';
        el.appendChild(box);
        el.setAttribute('aria-busy', 'true');

        var chiuso = false;
        return function (ripristina) {
            if (chiuso) return;
            chiuso = true;
            el.removeAttribute('aria-busy');
            if (ripristina) el.innerHTML = precedente;
            else if (box.parentNode === el) el.removeChild(box);
        };
    }

    /**
     * Fa entrare gli elementi appena aggiunti, uno dopo l'altro. La classe si
     * toglie a fine animazione: se restasse, un riordino successivo della
     * lista li farebbe rientrare tutti da capo.
     */
    function entra(elementi) {
        if (pocoMovimento || !elementi) return;
        var lista = elementi.length !== undefined ? elementi : [elementi];
        for (var i = 0; i < lista.length; i++) {
            (function (el) {
                if (!el || !el.classList) return;
                el.classList.add('ns-entra');
                setTimeout(function () { el.classList.remove('ns-entra'); }, 700);
            })(lista[i]);
        }
    }

    /* ============================================================
       PRESSIONE — l'interfaccia risponde al dito
       ============================================================
       :active da solo, su iOS, non scatta finche' il browser non ha deciso
       che non e' uno scorrimento: il tasto sembra rispondere in ritardo.
       Con pointerdown la risposta e' immediata, e si annulla se il dito
       scivola via (allora era uno scorrimento, non un tocco). */

    function preme(radice) {
        var root = radice || document;
        root.querySelectorAll('.ns-preme').forEach(function (el) {
            if (el.__nsPreme) return;
            el.__nsPreme = true;
            var giu = function () { el.classList.add('is-premuto'); };
            var su = function () { el.classList.remove('is-premuto'); };
            el.addEventListener('pointerdown', giu);
            el.addEventListener('pointerup', su);
            el.addEventListener('pointercancel', su);
            el.addEventListener('pointerleave', su);
        });
    }

    /* ============================================================
       RICERCA — filtro istantaneo su cio' che e' gia' in pagina
       ============================================================
       Su una lista che il browser ha gia' (concetti, mazzi, carte) non si
       chiede niente al server: si filtra mentre si scrive. La pausa e' corta
       (90ms) perche' non c'e' rete di mezzo: serve solo a non rifare il
       lavoro a ogni singolo tasto su liste lunghe.

       Esc svuota il campo e torna alla lista intera: e' cio' che fa una
       tastiera, ed e' l'unico modo di uscire senza cancellare a mano. */

    function ricerca(input, opz) {
        if (!input) return { aggiorna: function () {}, svuota: function () {} };
        opz = opz || {};
        var pausa = opz.pausa === undefined ? 90 : opz.pausa;
        var attesa = null;

        function lancia() {
            clearTimeout(attesa);
            attesa = setTimeout(function () { opz.onCerca(input.value.trim()); }, pausa);
        }
        input.addEventListener('input', lancia);
        input.addEventListener('search', lancia);   // il tasto "x" dei campi search
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();                 // Esc svuota il campo, non chiude il popup intorno
                input.value = '';
                opz.onCerca('');
            }
        });
        return {
            aggiorna: lancia,
            svuota: function () { input.value = ''; opz.onCerca(''); }
        };
    }

    /**
     * Filtra una lista gia' in pagina. $voci: elementi; $testo: cerca;
     * $leggi: da ogni elemento ricava il testo su cui cercare.
     * Torna quante ne restano.
     *
     * Il confronto ignora accenti e maiuscole: chi cerca "perche" deve
     * trovare "perché", altrimenti la ricerca sembra rotta.
     */
    function filtra(voci, testo, leggi) {
        var q = piatto(testo);
        var visibili = 0;
        for (var i = 0; i < voci.length; i++) {
            var el = voci[i];
            var dentro = q === '' || piatto(leggi(el)).indexOf(q) !== -1;
            el.classList.toggle('ns-nascosto', !dentro);
            if (dentro) visibili++;
        }
        return visibili;
    }

    function piatto(s) {
        s = String(s || '').toLowerCase();
        if (s.normalize) s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return s.trim();
    }

    /* ============================================================
       TENDINA — scelta fra poche voci che vanno lette
       ============================================================
       Il markup lo scrive il server (le voci sono tradotte, e devono esserci
       anche con JavaScript spento):

         <div class="ns-tendina" id="xxx">
           <button type="button" class="ns-tendina-btn" aria-haspopup="listbox"
                   aria-expanded="false"><span class="ns-tendina-valore"></span>
             <svg class="ns-tendina-frec">...</svg></button>
           <div class="ns-tendina-menu" role="listbox" hidden>
             <button type="button" class="ns-tendina-voce" role="option" data-valore="medio">
               <span class="ns-tendina-voce-t"><b>Equilibrato</b><small>...</small></span>
               <svg class="ns-tendina-spunta">...</svg></button>
             ...
           </div>
         </div>

       Qui si aggiunge solo il comportamento:

         var t = NS.tendina(el, { valore:'medio', onCambia:function (v) {...} });
         t.valore();  t.imposta('alto');  t.imposta('alto', true) // senza onCambia

       Mentre e' aperto il menu sta in fondo al <body>: dentro un contenitore
       che scorre verrebbe tagliato. Se il contenitore scorre, il menu segue
       il bottone, e se il bottone esce di vista il menu si chiude — inseguire
       un bottone che non c'e' piu' lascerebbe un riquadro sospeso nel vuoto. */

    function tendina(el, opz) {
        opz = opz || {};
        if (!el || el.__nsTendina) return el ? el.__nsTendina : null;

        var btn  = el.querySelector('.ns-tendina-btn');
        var menu = el.querySelector('.ns-tendina-menu');
        var etichetta = el.querySelector('.ns-tendina-valore');
        if (!btn || !menu) return null;

        var voci = Array.prototype.slice.call(menu.querySelectorAll('[data-valore]'));
        if (!voci.length) return null;

        var valore = null;
        var aperta = false;
        var attiva = -1;
        var casa = menu.parentNode;      // dove torna il menu quando si chiude

        function testoBreve(v) {
            var b = v.getAttribute('data-breve');
            if (b !== null) return b;
            var t = v.querySelector('b');
            return t ? t.textContent : v.textContent.trim();
        }

        function imposta(v, silenzioso) {
            var trovata = null;
            voci.forEach(function (voce) {
                var su = voce.getAttribute('data-valore') === v;
                if (su) trovata = voce;
                voce.classList.toggle('is-on', su);
                voce.setAttribute('aria-selected', su ? 'true' : 'false');
            });
            if (!trovata) return;        // valore inesistente: si tiene quello di prima
            valore = v;
            if (etichetta) etichetta.textContent = testoBreve(trovata);
            if (!silenzioso && opz.onCambia) opz.onCambia(v);
        }

        function segnaAttiva(i) {
            if (i < 0) i = voci.length - 1;
            if (i >= voci.length) i = 0;
            attiva = i;
            voci.forEach(function (voce, k) { voce.classList.toggle('is-attiva', k === i); });
            if (voci[i].scrollIntoView) voci[i].scrollIntoView({ block: 'nearest' });
        }

        /* Posizione: sotto il bottone, largo quanto lui. Se sotto non ci sta
           e sopra si', va sopra — e' quello che fa ogni menu di sistema. */
        function posiziona() {
            var r = btn.getBoundingClientRect();
            var h = window.innerHeight || document.documentElement.clientHeight;
            if (r.bottom < -4 || r.top > h + 4) { chiudi(); return; }

            menu.style.left  = Math.round(r.left) + 'px';
            menu.style.width = Math.round(r.width) + 'px';
            menu.style.top = menu.style.bottom = 'auto';

            var alto = menu.offsetHeight;
            var sotto = h - r.bottom - 8;
            var sopra = r.top - 8;
            if (alto > sotto && sopra > sotto) {
                menu.classList.add('is-su');
                menu.style.top = Math.max(8, Math.round(r.top - 6 - Math.min(alto, sopra))) + 'px';
            } else {
                menu.classList.remove('is-su');
                menu.style.top = Math.round(r.bottom + 6) + 'px';
            }
        }

        function apri() {
            if (aperta) return;
            aperta = true;
            document.body.appendChild(menu);
            menu.hidden = false;
            el.classList.add('is-aperta');
            btn.setAttribute('aria-expanded', 'true');
            posiziona();
            segnaAttiva(Math.max(0, voci.map(function (v) { return v.getAttribute('data-valore'); }).indexOf(valore)));
            document.addEventListener('pointerdown', fuori, true);
            document.addEventListener('keydown', tasti, true);
            window.addEventListener('resize', posiziona);
            window.addEventListener('scroll', posiziona, true);
        }

        function chiudi(tornaAlBottone) {
            if (!aperta) return;
            aperta = false;
            menu.hidden = true;
            menu.classList.remove('is-su');
            if (casa) casa.appendChild(menu);
            el.classList.remove('is-aperta');
            btn.setAttribute('aria-expanded', 'false');
            voci.forEach(function (v) { v.classList.remove('is-attiva'); });
            document.removeEventListener('pointerdown', fuori, true);
            document.removeEventListener('keydown', tasti, true);
            window.removeEventListener('resize', posiziona);
            window.removeEventListener('scroll', posiziona, true);
            if (tornaAlBottone) btn.focus();
        }

        function fuori(e) {
            if (menu.contains(e.target) || btn.contains(e.target)) return;
            chiudi();
        }

        function tasti(e) {
            if (!aperta) return;
            if (e.key === 'Escape')      { e.stopPropagation(); e.preventDefault(); chiudi(true); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); segnaAttiva(attiva + 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); segnaAttiva(attiva - 1); }
            else if (e.key === 'Home')      { e.preventDefault(); segnaAttiva(0); }
            else if (e.key === 'End')       { e.preventDefault(); segnaAttiva(voci.length - 1); }
            else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (attiva >= 0) { imposta(voci[attiva].getAttribute('data-valore')); chiudi(true); }
            } else if (e.key === 'Tab') { chiudi(); }
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (aperta) chiudi(); else apri();
        });
        btn.addEventListener('keydown', function (e) {
            if (!aperta && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); apri(); }
        });
        voci.forEach(function (voce) {
            voce.addEventListener('click', function (e) {
                e.preventDefault();
                imposta(voce.getAttribute('data-valore'));
                chiudi(true);
            });
            voce.addEventListener('mousemove', function () { segnaAttiva(voci.indexOf(voce)); });
        });

        var iniziale = opz.valore || el.getAttribute('data-valore') || voci[0].getAttribute('data-valore');
        imposta(iniziale, true);

        var api = {
            valore:  function () { return valore; },
            imposta: imposta,
            chiudi:  function () { chiudi(); },
            elemento: el
        };
        el.__nsTendina = api;
        return api;
    }

    /* ============================================================
       VUOTO — un elenco senza risultati lo dice
       ============================================================ */
    function vuoto(el, testo) {
        if (!el) return;
        var box = el.querySelector(':scope > .ns-vuoto');
        if (testo === null || testo === false) { if (box) box.remove(); return; }
        if (!box) {
            box = document.createElement('div');
            box.className = 'ns-vuoto';
            el.appendChild(box);
        }
        box.textContent = testo;
    }

    return {
        poco: function () { return pocoMovimento; },
        scheletro: scheletro,
        entra: entra,
        preme: preme,
        ricerca: ricerca,
        filtra: filtra,
        piatto: piatto,
        vuoto: vuoto,
        tendina: tendina
    };
})();
