/* Standout — to study.
 *
 * Il calendario del mese, e sotto l'elenco giorno per giorno. Gli eventi sono
 * righe di planner_events: qui si leggono e si scrivono tramite study_api.php,
 * ma la tabella è quella del planner. Spostare un blocco da questa pagina lo
 * sposta anche là, e non c'è niente da sincronizzare — è tutto lo stesso dato.
 */
(function () {
    'use strict';

    var S = {
        mese: null,          // primo giorno del mese a fuoco, "AAAA-MM-01"
        eventi: [],
        filtri: null,        // cosa mostrare oltre allo studio, scelto e ricordato
        calendari: [], categorie: [],
        /* Acceso da subito: un evento lungo ripetuto su tutti i suoi giorni
           riempie l'elenco di righe identiche e sotterra le cose da fare
           davvero. Chi vuole vederlo giorno per giorno lo spegne. */
        compatta: true,      // eventi lunghi solo al primo e all'ultimo giorno
        soloKey: false,      // solo le date importanti
        aperto: null         // id dell'evento aperto nel foglio
    };

    /* ---------------- date ----------------
       Tutti i conti passano da mezzogiorno UTC e non da mezzanotte: due volte
       l'anno il cambio dell'ora legale sposta la differenza di un giorno
       intero, e un calendario che sbaglia un giorno due volte l'anno è un
       calendario che non ci si fida più. */
    function iso(d) { return d.toISOString().slice(0, 10); }
    function dat(s) { return new Date(Date.parse(s + 'T12:00:00Z')); }
    function piu(s, n) { return iso(new Date(dat(s).getTime() + n * 86400000)); }
    function giorniFra(a, b) {
        if (!a || !b) return 1;
        var n = Math.round((dat(b) - dat(a)) / 86400000);
        return n < 0 ? 1 : n + 1;
    }
    function lunedi(s) {
        var d = dat(s), g = (d.getUTCDay() + 6) % 7;   // 0 = lunedì
        return piu(s, -g);
    }
    function primoDelMese(s) { return s.slice(0, 8) + '01'; }
    function meseDi(s) { return s.slice(0, 7); }
    function spostaMese(s, n) {
        var d = dat(primoDelMese(s));
        d.setUTCMonth(d.getUTCMonth() + n);
        return iso(d).slice(0, 8) + '01';
    }

    var $ = function (id) { return document.getElementById(id); };

    /* Un titolo più lungo del suo spazio non si taglia con i puntini: scorre
       avanti e indietro, come nel planner. I puntini nascondono proprio la
       parte che distingue due blocchi che cominciano uguale — «Analisi 2 —
       integrali…» e «Analisi 2 — serie…» diventano la stessa riga.
       La misura si prende dopo che l'elemento è in pagina: prima larghezza e
       scorrimento valgono zero e nessun testo risulterebbe mai lungo. */
    function scorriSeLungo(fuori, dentro) {
        requestAnimationFrame(function () {
            var extra = dentro.scrollWidth - fuori.clientWidth;
            if (extra <= 2) return;
            fuori.classList.add('ts-scorre');
            // tempo proporzionale al troppo: un titolo appena lungo non deve
            // metterci quanto uno lunghissimo
            dentro.style.setProperty('--corsa', (-extra - 2) + 'px');
            dentro.style.setProperty('--durata', Math.max(4, extra / 14).toFixed(1) + 's');
        });
    }
    function testoScorrevole(cls, testo) {
        var fuori = el('span', cls);
        var dentro = el('span', 'ts-scorre-in', testo);
        fuori.appendChild(dentro);
        scorriSeLungo(fuori, dentro);
        return fuori;
    }
    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt !== undefined && txt !== null) e.textContent = txt;
        return e;
    }

    function toast(msg) {
        var t = $('tsToast');
        t.textContent = msg;
        t.classList.add('ts-on');
        clearTimeout(t._t);
        t._t = setTimeout(function () { t.classList.remove('ts-on'); }, 2200);
    }

    function api(azione, dati, ctrl) {
        var body = new URLSearchParams();
        body.set('st_ajax', azione);
        Object.keys(dati || {}).forEach(function (k) {
            var v = dati[k];
            if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
            else if (v !== undefined && v !== null) body.set(k, v);
        });
        return fetch('study_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            return r.text().then(function (txt) {
                try {
                    return JSON.parse(txt);
                } catch (e) {
                    /* Non è JSON: quasi sempre è PHP che ha stampato un errore
                       in HTML. Si mostra la prima riga utile invece di far
                       morire tutto dentro JSON.parse, dove nessuno la vedrebbe. */
                    if (window.console) console.error('to_study, risposta non JSON:', txt.slice(0, 400));
                    return { ok: false, message: txt.replace(/<[^>]*>/g, ' ').trim().slice(0, 160) };
                }
            });
        });
    }

    /* ---------------- caricamento ----------------
       Si chiede sempre la griglia intera, non il mese: le sei righe partono dal
       lunedì prima del primo e arrivano alla domenica dopo l'ultimo, e i
       blocchi che sbordano da quei giorni devono comunque comparire. */
    var inVolo = null;
    function carica() {
        var da = lunedi(S.mese);
        var a = piu(da, 41);
        /* Premendo la freccia cinque volte partivano cinque richieste, e le
           risposte tornavano in ordine sparso: si vedeva il mese sbagliato, e
           il server rispondeva 429 — che nel browser si legge «non riesco a
           raggiungere il server». Si tiene solo l'ultima. */
        if (inVolo && inVolo.abort) { try { inVolo.abort(); } catch (e) {} }
        var mio = (typeof AbortController === 'function') ? new AbortController() : null;
        inVolo = mio;
        var questoMese = S.mese;
        /* Il calendario si disegna SUBITO, con quello che si ha. Prima si
           aspettava la risposta del server per disegnare qualunque cosa, e se
           quella risposta non arrivava — una migrazione non ancora lanciata,
           una colonna che manca — la pagina restava bianca: nessuna griglia,
           nessun mese, nessun indizio su cosa fosse andato storto. Una pagina
           vuota e una pagina rotta si vedevano uguali. */
        disegna();
        return api('eventi', { from: da, to: a }, mio)
            .then(function (r) {
                // arrivata tardi, quando si guarda già un altro mese: si butta
                if (questoMese !== S.mese) return;
                if (!r.ok) {
                    /* Il messaggio del server e non uno generico: «Unknown
                       column 'role'» dice esattamente cosa manca, «qualcosa è
                       andato storto» non dice niente a nessuno. */
                    toast(r.message || r.error || T('comune.errore_generico'));
                    return;
                }
                S.eventi = r.eventi || [];
                disegna();
                caricaStato();
            })
            .catch(function (e) {
                // una richiesta annullata da noi non è un errore da mostrare
                if (e && e.name === 'AbortError') return;
                toast(T('comune.errore_rete'));
                if (window.console) console.error('to_study:', e);
            });
    }

    /* ---------------- calendario ---------------- */
    function disegna() {
        var d = dat(S.mese);
        $('tsMese').textContent = T('planner.mese_' + (d.getUTCMonth() + 1));
        $('tsAnno').textContent = d.getUTCFullYear();

        var wd = $('tsWd');
        wd.innerHTML = '';
        for (var i = 1; i <= 7; i++) wd.appendChild(el('span', null, T('planner.gs_' + i)));

        var grid = $('tsGrid');
        grid.innerHTML = '';
        var start = lunedi(S.mese);
        var oggi = iso(new Date(Date.now() - new Date().getTimezoneOffset() * 60000));

        for (var r = 0; r < 6; r++) {
            var riga = el('div', 'ts-riga');
            var inizioRiga = piu(start, r * 7);
            var sett = corsie(inizioRiga);
            for (var c = 0; c < 7; c++) {
                var ds = piu(start, r * 7 + c);
                riga.appendChild(cella(ds, meseDi(ds) === meseDi(S.mese), ds === oggi, sett.corsie.length));
            }
            if (sett.corsie.length) riga.appendChild(barre(sett.corsie));
            grid.appendChild(riga);
        }
        disegnaLista();
    }

    /* Le barre di una settimana: stessa geometria del planner. Un blocco lungo
       non può stare dentro una casella — le caselle tagliano ai bordi — quindi
       si disegna su uno strato sopra la riga, largo tutti e sette i giorni, e
       si ritaglia sui bordi della settimana. */
    function corsie(inizioRiga) {
        var fineRiga = piu(inizioRiga, 6);
        var pezzi = [];
        S.eventi.forEach(function (ev) {
            /* Solo gli eventi di più giorni diventano barre. Senza questo
               controllo un evento di un giorno solo finiva sia qui sia dentro
               la casella, e si vedeva due volte — la cosa saltava all'occhio
               sulle date importanti, che sono rosse e non passano inosservate. */
            if (giorniFra(ev.date, ev.end_date) < 2) return;
            if (ev.end_date < inizioRiga || ev.date > fineRiga) return;
            var da = ev.date > inizioRiga ? ev.date : inizioRiga;
            var a = ev.end_date < fineRiga ? ev.end_date : fineRiga;
            pezzi.push({
                ev: ev,
                col: giorniFra(inizioRiga, da) - 1,
                lung: giorniFra(da, a),
                apre: da === ev.date,
                chiude: a === ev.end_date
            });
        });
        if (!pezzi.length) return { corsie: [] };

        /* Gli importanti stanno SEMPRE in cima, prima di tutto il resto: sono
           le date su cui si appoggia il piano, e vederle sotto tre blocchi di
           studio vorrebbe dire cercarle. Poi chi comincia prima, poi il più
           lungo — così le barre lunghe fanno da base. */
        var peso = { highest: 0, high: 1, normal: 2 };
        pezzi.sort(function (a, b) {
            var pa = peso[a.ev.importance], pb = peso[b.ev.importance];
            if (pa !== pb) return pa - pb;
            if (a.col !== b.col) return a.col - b.col;
            if (a.lung !== b.lung) return b.lung - a.lung;
            return a.ev.id - b.ev.id;
        });

        var out = [];
        pezzi.forEach(function (p) {
            for (var k = 0; k < out.length; k++) {
                if (libera(out[k], p)) { out[k].push(p); return; }
            }
            if (out.length < 4) out.push([p]);
        });
        return { corsie: out };
    }
    function libera(corsia, p) {
        for (var i = 0; i < corsia.length; i++) {
            var q = corsia[i];
            if (p.col < q.col + q.lung && q.col < p.col + p.lung) return false;
        }
        return true;
    }

    function barre(corsie) {
        var strato = el('div', 'ts-barre');
        corsie.forEach(function (corsia, k) {
            corsia.forEach(function (p) {
                var b = el('div', 'ts-barra'
                    + (p.apre ? ' ts-b-apre' : '')
                    + (p.chiude ? ' ts-b-chiude' : '')
                    + (p.ev.importance !== 'normal' ? ' ts-b-imp' : '')
                    + (p.ev.done ? ' ts-b-fatto' : ''));
                b.style.gridColumn = (p.col + 1) + ' / span ' + p.lung;
                b.style.gridRow = (k + 1);
                b.style.setProperty('--c', p.ev.color || 'var(--ts-grigio)');
                var testo = el('span', 'ts-scorre-in', p.apre ? p.ev.title : ('… ' + p.ev.title));
                b.appendChild(testo);
                scorriSeLungo(b, testo);
                /* Trascinare una barra sposta il blocco. Il tocco breve invece
                   lo apre: come per le caselle, due gesti sullo stesso posto
                   distinti dal tempo. Qui bastano 320ms perché la barra è già
                   un oggetto, non uno spazio vuoto — il dito che ci resta
                   sopra sta chiaramente prendendola. */
                b.addEventListener('pointerdown', function (e) { prendiBarra(e, p.ev); });
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (trascinato) { trascinato = false; return; }
                    apriEvento(p.ev.id);
                });
                strato.appendChild(b);
            });
        });
        return strato;
    }

    /* Quanti pomodori sono pianificati in un giorno. La stima di un argomento
       si spalma sui giorni che copre: tre pomodori su due giorni fanno uno e
       mezzo al giorno, non tre in tutti e due. */
    function caricoDelGiorno(ds) {
        var tot = 0;
        S.eventi.forEach(function (ev) {
            (ev.tasks || []).forEach(function (t) {
                if (t.done || t.start > ds || t.end < ds) return;
                var giorni = giorniFra(t.start, t.end);
                tot += (t.stimati || 2) / giorni;
            });
        });
        return tot;
    }

    function cella(ds, nelMese, isOggi, nCorsie) {
        var c = el('div', 'ts-cella' + (nelMese ? '' : ' ts-fuori') + (isOggi ? ' ts-oggi' : ''));
        c.dataset.data = ds;
        /* Il carico tinge la casella. Un mese guardato per due secondi dice
           dove sei sotto e dove sei sopra, cosa che leggendo riga per riga non
           si vede mai. La scala si ferma a quattro livelli: con dieci sfumature
           nessuno distingue il terzo dal quarto, e la domanda vera è solo
           «questo giorno è troppo pieno?». */
        var carico = caricoDelGiorno(ds);
        var soglia = Math.max(2, S.ritmo || 4);
        if (carico > 0) {
            var liv = carico <= soglia * 0.4 ? 1
                    : carico <= soglia * 0.8 ? 2
                    : carico <= soglia * 1.2 ? 3 : 4;
            c.classList.add('ts-car-' + liv);
            c.title = Math.round(carico * 10) / 10 + '🍅';
        }
        c.appendChild(el('div', 'ts-num', String(dat(ds).getUTCDate())));
        if (nCorsie > 0) {
            var vuoto = el('div', 'ts-vuoto');
            vuoto.style.height = 'calc(' + nCorsie + ' * var(--ts-corsia))';
            c.appendChild(vuoto);
        }

        /* Gli eventi di un giorno solo stanno DENTRO la casella: le barre
           servono ad attraversare più giorni, e per uno solo sarebbero una
           complicazione senza motivo. */
        var singoli = S.eventi.filter(function (ev) {
            return ev.date === ds && ev.end_date === ds;
        });
        /* Tutte, non le prime tre: la casella ha un tetto d'altezza e scorre.
           Con il taglio a tre più un «+4» il contenuto non sforava mai, quindi
           non c'era niente da scorrere — e quel «+4» non si poteva aprire. */
        righeCasella(singoli).forEach(function (r) { c.appendChild(r); });
        return c;
    }

    /* Cosa si vede in una casella, e come.
       Con «tutto il planner» spuntato una giornata normale porta dentro venti
       fra routine e impegni da cinque minuti: messi uno sotto l'altro coprono
       il mese e non dicono niente. Quelli si stringono ora per ora — un blocco
       dalle 8 alle 9, uno dalle 9 alle 10 — con quanti ce n'è dentro. Lo
       studio e le date importanti restano invece riga per riga: sono il motivo
       per cui questa pagina esiste, e non si impastano con il resto. */
    function righeCasella(lista) {
        var out = [], perOra = {};
        lista.forEach(function (ev) {
            /* Studio e date importanti restano riga per riga: sono il motivo per
               cui la pagina esiste. Tutto il resto — routine, habit, gli
               eventi dei calendari che si è scelto di vedere — si stringe in
               fasce orarie: serve a sapere che quel giorno è già pieno, non a
               leggerlo voce per voce. */
            var suo = ev.di_studio || ev.importance !== 'normal';
            if (suo || ev.all_day) { out.push({ tipo: 'ev', ev: ev }); return; }
            var h = parseInt(ev.start_time, 10);
            if (isNaN(h)) { out.push({ tipo: 'ev', ev: ev }); return; }
            if (!perOra[h]) { perOra[h] = { tipo: 'ora', h: h, n: 0 }; out.push(perOra[h]); }
            perOra[h].n++;
        });
        // gli importanti in cima, poi lo studio, poi le ore in ordine
        var peso = function (r) {
            if (r.tipo === 'ora') return 3 + r.h / 24;
            return r.ev.importance !== 'normal' ? 0 : (r.ev.di_studio ? 1 : 2);
        };
        out.sort(function (a, b) { return peso(a) - peso(b); });

        return out.map(function (r) {
            if (r.tipo === 'ora') {
                /* Nessuna scritta: la fascia oraria è una barretta e basta.
                   Scriverci dentro «09–10 · 3» voleva dire mettere del testo al
                   posto di altro testo, quando il punto era togliere rumore.
                   Quanti ce n'è lo dice lo spessore: una riga per un evento,
                   piena per tre o più. */
                var o = el('div', 'ts-ora' + (r.n > 2 ? ' ts-ora-piena' : ''));
                o.title = r.h + ':00 – ' + (r.h + 1) + ':00 · ' + r.n;
                return o;
            }
            var ev = r.ev;
            var x = el('div', 'ts-mini'
                + (ev.importance !== 'normal' ? ' ts-mini-imp' : '')
                + (ev.done ? ' ts-mini-fatto' : ''));
            var dentro = el('span', 'ts-scorre-in', ev.title);
            x.appendChild(dentro);
            scorriSeLungo(x, dentro);
            x.style.setProperty('--c', ev.color || 'var(--ts-grigio)');
            x.addEventListener('click', function (e) {
                e.stopPropagation();
                apriEvento(ev.id);
            });
            return x;
        });
    }

    /* ---------------- creare tenendo premuto ----------------
       Tenendo premuto su un giorno nasce un blocco lì; tenendo premuto e
       trascinando su altri giorni, un blocco che va da dove si è partiti a dove
       si è lasciato. Il tocco breve invece apre il giorno nell'elenco: due
       gesti diversi sullo stesso posto, distinti dal tempo.
       500ms perché sotto si confonde con uno scorrimento partito male. */
    var press = null;
    function inizioPressione(e) {
        var cel = e.target.closest ? e.target.closest('.ts-cella') : null;
        if (!cel || e.target.closest('.ts-barra')) return;
        var punto = e.touches ? e.touches[0] : e;
        press = {
            da: cel.dataset.data, a: cel.dataset.data,
            x: punto.clientX, y: punto.clientY,
            attivo: false,
            t: setTimeout(function () {
                if (!press) return;
                press.attivo = true;
                /* Da qui in poi il dito sta disegnando un blocco, non
                   scorrendo: si spegne lo scorrimento fra i mesi. Prima i due
                   gesti convivevano, e trascinando su giorni contigui partiva
                   anche il cambio mese — si finiva a ottobre invece che con un
                   blocco di tre giorni. */
                sw = null;
                if (navigator.vibrate) navigator.vibrate(12);
                evidenzia();
            }, 500)
        };
    }
    function muoviPressione(e) {
        if (!press) return;
        var punto = e.touches ? e.touches[0] : e;

        /* Finché la pressione non è scattata, un movimento vero vuol dire che
           si sta scorrendo, non premendo: si annulla l'attesa. O una cosa o
           l'altra — un dito che si muove non sta tenendo premuto. */
        if (!press.attivo) {
            if (Math.abs(punto.clientX - press.x) > 9 || Math.abs(punto.clientY - press.y) > 9) {
                clearTimeout(press.t);
                press = null;
            }
            return;
        }

        var sotto = document.elementFromPoint(punto.clientX, punto.clientY);
        var cel = sotto && sotto.closest ? sotto.closest('.ts-cella') : null;
        if (!cel) return;
        press.a = cel.dataset.data;
        evidenzia();
        e.preventDefault();
    }
    function finePressione() {
        if (!press) return;
        clearTimeout(press.t);
        var p = press;
        press = null;
        pulisciEvidenza();
        if (!p.attivo) return;
        var da = p.da < p.a ? p.da : p.a;
        var a = p.da < p.a ? p.a : p.da;
        nuovoBlocco(da, a, 'normal');
    }
    function evidenzia() {
        pulisciEvidenza();
        if (!press) return;
        var da = press.da < press.a ? press.da : press.a;
        var a = press.da < press.a ? press.a : press.da;
        document.querySelectorAll('.ts-cella').forEach(function (c) {
            if (c.dataset.data >= da && c.dataset.data <= a) c.classList.add('ts-sel');
        });
    }
    function pulisciEvidenza() {
        document.querySelectorAll('.ts-cella.ts-sel').forEach(function (c) { c.classList.remove('ts-sel'); });
    }

    /* ---------------- trascinare una barra ----------------
       Il blocco si sposta tutto insieme conservando la durata: prendendolo dal
       terzo dei suoi cinque giorni e lasciandolo sul 20, il blocco comincia il
       18 — cioè si sposta di dove si è mosso il dito, non di dove è finito.
       Prenderlo e vederlo saltare con l'inizio sotto il dito sarebbe la cosa
       che nessun calendario fa. */
    var barraPresa = null, trascinato = false;
    function prendiBarra(e, ev) {
        var sotto = document.elementFromPoint(e.clientX, e.clientY);
        var partenza = sotto && sotto.closest ? sotto.closest('.ts-cella') : null;
        barraPresa = {
            ev: ev,
            daGiorno: partenza ? partenza.dataset.data : ev.date,
            attivo: false,
            t: setTimeout(function () {
                if (!barraPresa) return;
                barraPresa.attivo = true;
                if (navigator.vibrate) navigator.vibrate(10);
                document.body.classList.add('ts-trascino');
            }, 320)
        };
    }
    function muoviBarra(e) {
        if (!barraPresa || !barraPresa.attivo) return;
        var sotto = document.elementFromPoint(e.clientX, e.clientY);
        var cel = sotto && sotto.closest ? sotto.closest('.ts-cella') : null;
        if (!cel) return;
        barraPresa.aGiorno = cel.dataset.data;
        pulisciEvidenza();
        var scarto = giorniFra(barraPresa.daGiorno, barraPresa.aGiorno) - 1;
        if (barraPresa.aGiorno < barraPresa.daGiorno) scarto = -(giorniFra(barraPresa.aGiorno, barraPresa.daGiorno) - 1);
        var da = piu(barraPresa.ev.date, scarto);
        var a = piu(barraPresa.ev.end_date, scarto);
        document.querySelectorAll('.ts-cella').forEach(function (c) {
            if (c.dataset.data >= da && c.dataset.data <= a) c.classList.add('ts-sel');
        });
        e.preventDefault();
    }
    function lasciaBarra() {
        if (!barraPresa) return;
        clearTimeout(barraPresa.t);
        var b = barraPresa;
        barraPresa = null;
        document.body.classList.remove('ts-trascino');
        pulisciEvidenza();
        if (!b.attivo || !b.aGiorno || b.aGiorno === b.daGiorno) return;
        trascinato = true;   // il click che segue non deve aprire il foglio

        var scarto = b.aGiorno > b.daGiorno
            ? giorniFra(b.daGiorno, b.aGiorno) - 1
            : -(giorniFra(b.aGiorno, b.daGiorno) - 1);
        var da = piu(b.ev.date, scarto);
        var a = piu(b.ev.end_date, scarto);
        /* Trascinando la durata non cambia, quindi nessun argomento può
           restare fuori: si passa da 'segui' senza fare domande. La domanda
           serve quando il periodo si accorcia, e quello si fa dal foglio. */
        manda(b.ev, da, a, 'segui');
    }

    /* Il titolo si chiede PRIMA di creare, e senza titolo non si crea niente.
       Prima il blocco nasceva chiamandosi «Nuovo argomento» e si apriva il
       foglio: bastava sfiorare il calendario o cambiare idea per lasciarsi
       dietro un blocco vuoto, e il mese si riempiva di roba mai voluta.
       Un nome è la cosa minima che rende un blocco qualcosa: senza, non è un
       blocco a metà, è un blocco che non esiste. */
    /* Prima il progetto, poi il nome — e il nome quasi sempre non si scrive.
       Un blocco senza progetto è un blocco fuori da tutte le statistiche: i
       pomodori non si attaccano a niente, il semaforo non lo vede, la curva
       della fatica nemmeno. Chiedere prima il titolo e poi, forse, il progetto
       significava che nove volte su dieci il progetto non si collegava — non
       per pigrizia, ma perché a quel punto il blocco era già creato e la cosa
       sembrava fatta.
       Scegliendo il progetto il titolo si riempie da sé con il suo nome, che è
       quello che si sarebbe scritto comunque: resta modificabile per i casi in
       cui serve un nome diverso («Analisi 2 — recupero»). */
    function nuovoBlocco(da, a, imp) {
        var scelto = null;   // {id, name, color}
        var etichetta = da === a
            ? formattaGiorno(da)
            : formattaGiorno(da) + ' → ' + formattaGiorno(a);

        scelta(imp === 'normal' ? T('todo.study_nuovo_blocco') : T('todo.study_data_importante'),
               etichetta, [], null, function (box) {

            /* Il selettore progetti sta in cima, prima del titolo: l'ordine
               dice cosa conta di più, e qui conta di più il progetto. */
            var prog = el('button', 'ts-campo ts-progetto');
            prog.type = 'button';
            prog.textContent = T('todo.study_scegli_progetto');
            box.appendChild(prog);

            var campo = el('input', 'ts-campo');
            campo.type = 'text';
            campo.placeholder = T('todo.study_titolo_opzionale');
            box.appendChild(campo);

            var ok = el('button', 'ts-scelta-b ts-scelta-primo', T('comune.aggiungi'));
            ok.type = 'button';
            ok.disabled = true;
            function aggiorna() { ok.disabled = !campo.value.trim() && !scelto; }
            campo.addEventListener('input', aggiorna);
            campo.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !ok.disabled) { e.preventDefault(); ok.click(); }
            });

            prog.addEventListener('click', function () {
                var modale = $('ps-modal-study');
                if (!window.psOpen || !modale) { toast(T('comune.errore_generico')); return; }
                var ascolta = function (e) {
                    document.removeEventListener('projectselected', ascolta);
                    var d = e.detail || {};
                    if (!d.id) { scelto = null; prog.textContent = T('todo.study_scegli_progetto');
                                 prog.classList.remove('ts-ha'); aggiorna(); return; }
                    scelto = { id: d.id, name: d.name, color: d.color };
                    prog.textContent = d.name;
                    prog.classList.add('ts-ha');
                    prog.style.setProperty('--c', d.color || 'var(--ts-grigio)');
                    /* Il titolo si riempie solo se è vuoto o se conteneva il
                       nome del progetto di prima: quello che l'utente ha
                       scritto a mano non si sovrascrive mai. */
                    if (!campo.value.trim() || campo.dataset.daProgetto === '1') {
                        campo.value = d.name;
                        campo.dataset.daProgetto = '1';
                    }
                    aggiorna();
                };
                document.addEventListener('projectselected', ascolta);
                window.psOpen(modale);
            });
            campo.addEventListener('input', function () { campo.dataset.daProgetto = '0'; });

            ok.addEventListener('click', function () {
                var titolo = campo.value.trim() || (scelto ? scelto.name : '');
                if (!titolo) return;
                chiudiScelta();
                creaDavvero(da, a, imp, titolo, scelto ? scelto.id : 0);
            });
            box.appendChild(ok);

            var no = el('button', 'ts-scelta-b', T('comune.annulla'));
            no.type = 'button';
            no.addEventListener('click', function () { chiudiScelta(); });
            box.appendChild(no);

            setTimeout(function () { prog.focus(); }, 60);
        });
    }

    function creaDavvero(da, a, imp, titolo, projectId) {
        api('crea_evento', { start: da, end: a, importance: imp, title: titolo, project_id: projectId || 0 })
            .then(function (r) {
                if (!r.ok) { toast(r.message || T('comune.errore_generico')); return; }
                return carica().then(function () { apriEvento(r.id); });
            })
            .catch(function () { toast(T('comune.errore_rete')); });
    }

    /* ---------------- l'elenco giorno per giorno ----------------
       Solo i giorni che hanno qualcosa: un mese di righe vuote da scorrere per
       trovare le tre che contano non aiuta nessuno. */
    function disegnaLista() {
        var box = $('tsLista');
        box.innerHTML = '';
        var start = lunedi(S.mese);
        var vuoto = true;
        var oggiIso = iso(new Date(Date.now() - new Date().getTimezoneOffset() * 60000));

        /* I giorni già passati stanno chiusi dietro una freccia, come i
           calendari nel pannello dei filtri. Un mese aperto a metà ha davanti
           venti giorni andati e dieci da fare, e l'elenco si apriva sul
           passato: si scorreva ogni volta per arrivare a oggi. Aperti restano
           più smorti, perché sono un promemoria, non una cosa da fare. */
        var passati = el('div', 'ts-passati');
        var testaP = el('button', 'ts-passati-t');
        testaP.type = 'button';
        testaP.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
        testaP.appendChild(el('span', null, T('todo.study_giorni_passati')));
        var contaP = el('span', 'ts-passati-n');
        testaP.appendChild(contaP);
        var dentroP = el('div', 'ts-passati-in');
        passati.appendChild(testaP);
        passati.appendChild(dentroP);
        testaP.addEventListener('click', function () {
            var apre = !passati.classList.contains('ts-aperto');
            passati.classList.toggle('ts-aperto', apre);
            /* Chiudendo si riparte dall'altezza reale e non da «none», o la
               transizione non avrebbe un valore da cui scendere e la chiusura
               sarebbe uno scatto secco. */
            if (!apre) dentroP.style.maxHeight = dentroP.scrollHeight + 'px';
            requestAnimationFrame(function () {
                dentroP.style.maxHeight = apre ? (dentroP.scrollHeight + 'px') : '0px';
                seguiFinoAFine();
            });
        });

        /* Le linee si rimisurano a OGNI fotogramma finché l'apertura è in
           corso, non solo alla fine. Misurando una volta sola a transizione
           finita la linea restava sbagliata per tutto il terzo di secondo in
           cui l'elenco si apre — cioè proprio mentre la si guarda. Il ciclo si
           ferma da solo quando la transizione è finita, quindi a pagina ferma
           non consuma niente. */
        var seguiApertura = null;
        function seguiFinoAFine() {
            if (seguiApertura) cancelAnimationFrame(seguiApertura);
            var scaduta = Date.now() + 500;   // rete: se transitionend non arriva
            (function passo() {
                disegnaLegami();
                if (Date.now() < scaduta) seguiApertura = requestAnimationFrame(passo);
                else seguiApertura = null;
            })();
        }
        dentroP.addEventListener('transitionend', function (e) {
            if (e.propertyName !== 'max-height') return;
            if (seguiApertura) { cancelAnimationFrame(seguiApertura); seguiApertura = null; }
            if (passati.classList.contains('ts-aperto')) dentroP.style.maxHeight = 'none';
            disegnaLegami();
        });
        box.appendChild(passati);
        var nPassati = 0;

        for (var i = 0; i < 42; i++) {
            var ds = piu(start, i);
            if (meseDi(ds) !== meseDi(S.mese)) continue;

            var delGiorno = S.eventi.filter(function (ev) {
                if (ev.date > ds || ev.end_date < ds) return false;
                if (!inElenco(ev)) return false;
                /* Compattati: un evento lungo si vede solo il primo e l'ultimo
                   giorno, legati da una linea. Ripeterlo su tutti e dodici i
                   giorni riempiva l'elenco di righe identiche, e le cose da
                   fare davvero in quel giorno finivano sepolte. */
                if (S.compatta && giorniFra(ev.date, ev.end_date) > 2
                    && ds !== ev.date && ds !== ev.end_date) return false;
                return true;
            });
            if (!delGiorno.length) continue;
            vuoto = false;

            var g = el('div', 'ts-giorno');
            var testa = el('div', 'ts-giorno-t');
            testa.appendChild(el('span', 'ts-giorno-n', String(dat(ds).getUTCDate())));
            testa.appendChild(el('span', 'ts-giorno-w', T('planner.gs_' + (((dat(ds).getUTCDay() + 6) % 7) + 1))));
            var piu2 = el('button', 'ts-giorno-add');
            piu2.type = 'button';
            piu2.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
            piu2.addEventListener('click', (function (giorno) {
                return function () { nuovoBlocco(giorno, giorno, 'normal'); };
            })(ds));
            testa.appendChild(piu2);
            g.appendChild(testa);

            // gli importanti per primi anche qui, per lo stesso motivo
            var peso = { highest: 0, high: 1, normal: 2 };
            delGiorno.sort(function (a, b) { return peso[a.importance] - peso[b.importance]; });
            delGiorno.forEach(function (ev) { g.appendChild(rigaEvento(ev, ds)); });
            if (ds < oggiIso) { dentroP.appendChild(g); nPassati++; }
            else box.appendChild(g);
        }
        contaP.textContent = nPassati ? String(nPassati) : '';
        if (!nPassati) passati.style.display = 'none';
        if (vuoto) box.appendChild(el('div', 'ts-nulla', T('todo.study_niente_nel_mese')));
        disegnaLegami();
    }

    /* ---------------- le linee che legano i due capi ----------------
       Un evento compattato si vede solo il primo e l'ultimo giorno, e in mezzo
       può esserci di tutto o niente: giorni con altre cose, giorni saltati del
       tutto. Una linea attaccata alla riga non poteva arrivare fino all'altro
       capo, perché non sa quanto è lontano — infatti prima erano due monconi
       che finivano nel vuoto.
       Qui la si disegna DOPO, quando le righe sono in pagina e si possono
       misurare: parte dalla barretta colorata, esce a sinistra, scende fino
       all'altro capo e rientra. Due eventi che si accavallano prendono due
       corsie affiancate, così le linee non si sovrappongono e si vede quale
       porta a quale. */
    function disegnaLegami() {
        var lista = $('tsLista');
        lista.querySelectorAll('.ts-legame').forEach(function (x) { x.remove(); });
        if (!S.compatta) return;

        var base = lista.getBoundingClientRect();
        var passati = lista.querySelector('.ts-passati');
        var chiusi = passati && !passati.classList.contains('ts-aperto');
        var testaPassati = passati ? passati.querySelector('.ts-passati-t') : null;

        /* Dove attaccare un capo. Se sta dentro ai giorni passati e quelli sono
           chiusi, la sua riga è alta zero e misurarla darebbe una linea che
           parte dal nulla: si attacca alla riga «giorni passati», che è quello
           che al momento lo rappresenta. Un evento cominciato la settimana
           scorsa e che finisce domani deve comunque mostrare il collegamento —
           anzi, è proprio quello il caso in cui serve, perché dice «questa cosa
           viene da prima». */
        function ancora(elemento) {
            if (chiusi && testaPassati && elemento.closest('.ts-passati-in')) return testaPassati;
            return elemento;
        }

        var coppie = [];
        S.eventi.forEach(function (ev) {
            var a = lista.querySelector('.ts-ev[data-ev="' + ev.id + '"][data-capo="inizio"]');
            var b = lista.querySelector('.ts-ev[data-ev="' + ev.id + '"][data-capo="fine"]');
            if (!a || !b) return;
            var ea = ancora(a), eb = ancora(b);
            // tutti e due nel passato richiuso: non c'è niente da collegare
            if (ea === eb) return;
            var ra = ea.getBoundingClientRect(), rb = eb.getBoundingClientRect();
            // il capo che sta sulla riga «giorni passati» non porta l'uncino:
            // là non c'è nessuna barretta colorata da andare a riprendere, e
            // la curva puntava verso la scritta come se la indicasse
            var suNascosto = (ea !== a), giuNascosto = (eb !== b);
            /* Da centro a centro, non da fondo a cima. La linea deve sembrare
               che esca dalla barretta colorata e ci rientri: partendo dal bordo
               inferiore e fermandosi a quello superiore restava staccata da
               tutte e due, e si leggeva come un terzo oggetto messo lì in
               mezzo invece che come il collegamento fra i due. */
            coppie.push({
                ev: ev,
                da: ra.top - base.top + ra.height / 2,
                a:  rb.top - base.top + rb.height / 2,
                senzaSu: suNascosto, senzaGiu: giuNascosto
            });
        });
        if (!coppie.length) return;

        // chi comincia prima sta nella corsia più interna
        coppie.sort(function (x, y) { return x.da - y.da; });
        var corsie = [];
        coppie.forEach(function (c) {
            var k = 0;
            while (k < corsie.length && corsie[k] > c.da) k++;
            corsie[k] = c.a;
            c.corsia = k;
        });

        coppie.forEach(function (c) {
            var l = el('div', 'ts-legame'
                + (c.senzaSu ? ' ts-senza-su' : '')
                + (c.senzaGiu ? ' ts-senza-giu' : ''));
            l.style.top = c.da + 'px';
            l.style.height = Math.max(4, c.a - c.da) + 'px';
            // ogni corsia si scosta un po' più a sinistra della precedente
            l.style.left = (6 - c.corsia * 5) + 'px';
            l.style.setProperty('--c', c.ev.color || 'var(--ts-grigio)');
            lista.appendChild(l);
        });
    }

    /* Cosa merita una riga nell'elenco.
       Gli eventi che arrivano con «tutto il planner» servono al calendario, per
       vedere dove c'è già qualcosa: nell'elenco sotto sarebbero rumore, perché
       quello è l'elenco di cosa c'è da studiare. Ma un evento del planner
       legato a un progetto NON è rumore — è una lezione, un ricevimento, una
       consegna: riguarda quella materia e va letto insieme al resto. */
    function inElenco(ev) {
        if (S.soloKey) return ev.importance !== 'normal';
        /* Le date importanti NON stanno nell'elenco giorno per giorno: quello
           risponde a «cosa devo fare oggi», e una data d'esame non è una cosa
           da fare — è il muro contro cui si sta correndo. Sta nel calendario,
           grossa e rossa, e chi la vuole leggere per esteso accende il filtro
           apposta. */
        if (ev.importance !== 'normal') return false;
        return ev.di_studio || !!ev.project_id;
    }

    function rigaEvento(ev, giorno) {
        /* Nota per il CSS: quando l'evento è compattato la sua striscia
           colorata esce dai bordi per andare a cercare l'altro capo, quindi la
           riga NON può ritagliare quello che sborda. */
        var lungo = giorniFra(ev.date, ev.end_date) > 1;
        var legame = '';
        if (S.compatta && lungo) {
            // la linea scende dal primo giorno e risale all'ultimo: sono i due
            // capi della stessa cosa, e si vede che in mezzo continua
            if (giorno === ev.date) legame = ' ts-ev-giu';
            else if (giorno === ev.end_date) legame = ' ts-ev-su';
        }
        var r = el('div', 'ts-ev' + legame
            + (ev.importance !== 'normal' ? ' ts-ev-imp' : '')
            + (ev.done ? ' ts-ev-fatto' : ''));
        r.dataset.ev = ev.id;
        r.dataset.capo = legame ? (giorno === ev.date ? 'inizio' : 'fine') : '';
        r.style.setProperty('--c', ev.color || 'var(--ts-grigio)');

        var testa = el('div', 'ts-ev-t');
        var chk = el('button', 'ts-chk' + (ev.done ? ' ts-chk-on' : ''));
        chk.type = 'button';
        chk.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        chk.addEventListener('click', function (e) { e.stopPropagation(); spuntaEvento(ev); });
        testa.appendChild(chk);

        testa.appendChild(testoScorrevole('ts-ev-nome', ev.title));

        /* Sulle date importanti l'ora si scrive. Un blocco di studio dura un
           giorno intero e l'orario non vuol dire niente; un esame invece è
           alle 9 in punto, ed è metà dell'informazione. */
        if (ev.importance !== 'normal' && !ev.all_day && ev.start_time) {
            testa.appendChild(el('span', 'ts-ev-ora', ev.start_time));
        }
        var tot = giorniFra(ev.date, ev.end_date);
        if (tot > 1) {
            testa.appendChild(el('span', 'ts-ev-prog',
                '(' + giorniFra(ev.date, giorno) + '/' + tot + ')'));
        }
        /* Il grigio non basta a farsi notare: chi non collega il progetto non
           perde un colore, perde le statistiche, e va detto a parole. */
        if (!ev.project_id) {
            var av = el('span', 'ts-senza', T('todo.study_senza_progetto'));
            testa.appendChild(av);
        }
        /* Il tastino per aggiungere un argomento direttamente da qui, come in
           progetti: la freccia che scende e piega a destra dice «qui sotto,
           dentro a questo». Aprire il foglio solo per aggiungere una riga era
           un passaggio in mezzo che non serviva a niente. Non sulle date
           importanti: quelle argomenti non ne hanno. */
        if (ev.importance === 'normal') {
            var piuSotto = el('button', 'ts-ev-add');
            piuSotto.type = 'button';
            piuSotto.setAttribute('aria-label', T('todo.nuovo_argomento'));
            piuSotto.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
                + '<path d="M7 5v7a3 3 0 0 0 3 3h7"/><path d="m14 12 3 3-3 3"/></svg>';
            piuSotto.addEventListener('click', function (e) {
                e.stopPropagation();
                nuovoArgomento(ev, giorno);
            });
            testa.appendChild(piuSotto);
        }

        testa.addEventListener('click', function () { apriEvento(ev.id); });
        r.appendChild(testa);

        /* I sotto-task del giorno che si sta guardando, non tutti: aprendo il
           13 agosto interessa cosa c'è da fare il 13, non l'intero piano. */
        (ev.tasks || []).forEach(function (t) {
            if (t.start > giorno || t.end < giorno) return;
            r.appendChild(rigaTask(t));
        });
        return r;
    }

    /* Un argomento nasce con un nome, come i blocchi: senza, si accumulano
       righe vuote che nessuno cancella mai. Il giorno di partenza è quello da
       cui si è premuto, che è quasi sempre quello giusto. */
    function nuovoArgomento(ev, giorno) {
        scelta(T('todo.nuovo_argomento'), ev.title, [], null, function (box) {
            var campo = el('input', 'ts-campo');
            campo.type = 'text';
            campo.placeholder = T('todo.nuovo_argomento');
            box.appendChild(campo);

            var ok = el('button', 'ts-scelta-b ts-scelta-primo', T('comune.aggiungi'));
            ok.type = 'button';
            ok.disabled = true;
            campo.addEventListener('input', function () { ok.disabled = !campo.value.trim(); });
            campo.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && campo.value.trim()) { e.preventDefault(); ok.click(); }
            });
            ok.addEventListener('click', function () {
                var nome = campo.value.trim();
                if (!nome) return;
                chiudiScelta();
                api('add', { event_id: ev.id, name: nome, start: giorno, end: giorno })
                    .then(function (r) {
                        if (!r.ok) { toast(r.message || T('comune.errore_generico')); return; }
                        return carica();
                    })
                    .catch(function () { toast(T('comune.errore_rete')); });
            });
            box.appendChild(ok);

            var no = el('button', 'ts-scelta-b', T('comune.annulla'));
            no.type = 'button';
            no.addEventListener('click', function () { chiudiScelta(); });
            box.appendChild(no);
            setTimeout(function () { campo.focus(); }, 60);
        });
    }

    function rigaTask(t) {
        var x = el('div', 'ts-task' + (t.done ? ' ts-task-fatto' : ''));
        var chk = el('button', 'ts-chk ts-chk-s' + (t.done ? ' ts-chk-on' : ''));
        chk.type = 'button';
        chk.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        chk.addEventListener('click', function () {
            var nuovo = !t.done;
            t.done = nuovo;                     // subito a schermo, poi si salva
            chk.classList.toggle('ts-chk-on', nuovo);
            x.classList.toggle('ts-task-fatto', nuovo);
            api('toggle', { id: t.id, done: nuovo ? 1 : 0 }).then(function () {
                /* Spuntato un argomento, si propongono i tre ripassi a uno,
                   tre e sette giorni. Si chiede invece di farlo e basta: non
                   tutto va ripassato — un esercizio svolto no — e riempire il
                   calendario di cose che nessuno farà è il modo di far
                   smettere di guardarlo.
                   Solo per gli argomenti veri: un ripasso non genera i propri
                   ripassi, o non si finirebbe più. */
                if (!nuovo || t.ripasso) return;
                chiediRipassi(t);
            }).catch(function () { toast(T('comune.errore_rete')); });
        });
        x.appendChild(chk);
        x.appendChild(testoScorrevole('ts-task-nome', t.name));

        /* Stimati contro fatti. Compare solo quando c'è almeno uno dei due:
           una riga di zeri su ogni argomento sarebbe rumore. */
        if (t.stimati || t.fatti) {
            var q = el('span', 'ts-task-pom');
            q.textContent = (t.fatti || 0) + (t.stimati ? '/' + t.stimati : '') + '🍅';
            if (t.stimati && t.fatti > t.stimati) q.classList.add('ts-oltre');
            x.appendChild(q);
        }
        return x;
    }

    /* Il ripasso a intervalli crescenti. È la sola cosa in questa pagina che
       cambia davvero quanto rimane in testa: chi ripassa a distanza rende più
       del doppio di chi rilegge la sera prima. Ma è una proposta, non una
       regola — e i ripassi si fermano prima dell'esame, perché dopo non
       ripassano niente. */
    function chiediRipassi(t) {
        scelta(T('todo.study_ripassare'), T('todo.study_ripassare_testo', { nome: t.name }), [
            { chiave: 'si', k: 'si', txt: T('todo.study_si_ripassa') },
            { k: '', txt: T('todo.study_no_ripassa') }
        ], function (k) {
            if (k !== 'si') return;
            api('ripassi', { id: t.id }).then(function (r) {
                if (r && r.creati) toast(T('todo.study_ripassi_creati', { n: r.creati }));
                carica();
            }).catch(function () { toast(T('comune.errore_rete')); });
        });
    }

    /* ---------------- spunta del padre, con l'annullamento ----------------
       Spuntando un blocco si spuntano tutti i suoi argomenti. Disfare non li
       spegne tutti: il server restituisce chi era GIÀ spuntato prima, e
       l'annullamento glielo rimanda indietro. Senza, disfare per sbaglio
       cancellerebbe il lavoro di giorni. */
    function spuntaEvento(ev) {
        var nuovo = !ev.done;
        api('toggle_event', { event_id: ev.id, done: nuovo ? 1 : 0 }).then(function (r) {
            if (!r.ok) { toast(T('comune.errore_generico')); return; }
            var eraGia = r.era_gia || [];
            carica();
            if (nuovo) mostraAnnulla(ev, eraGia);
        }).catch(function () { toast(T('comune.errore_rete')); });
    }

    function mostraAnnulla(ev, eraGia) {
        var t = $('tsToast');
        t.textContent = '';
        t.appendChild(el('span', null, T('todo.study_fatto_tutto')));
        var b = el('button', 'ts-annulla', T('comune.annulla'));
        b.type = 'button';
        b.addEventListener('click', function () {
            api('toggle_event', { event_id: ev.id, done: 0, era_gia: eraGia })
                .then(carica)
                .catch(function () { toast(T('comune.errore_rete')); });
            t.classList.remove('ts-on');
        });
        t.appendChild(b);
        t.classList.add('ts-on');
        clearTimeout(t._t);
        t._t = setTimeout(function () { t.classList.remove('ts-on'); }, 6000);
    }

    /* ---------------- foglio di un blocco ---------------- */
    function apriEvento(id) {
        var ev = S.eventi.filter(function (x) { return x.id === id; })[0];
        if (!ev) return;
        S.aperto = id;
        $('tsSheetTit').textContent = ev.title;

        var b = $('tsSheetBody');
        b.innerHTML = '';

        /* Il titolo si cambia scrivendoci sopra, senza aprire un secondo
           foglio: è il campo che si tocca più spesso — un blocco nasce con un
           nome provvisorio e viene battezzato subito dopo. */
        var tit = el('input', 'ts-campo ts-campo-tit');
        tit.type = 'text';
        tit.value = ev.title;
        tit.placeholder = T('todo.nuovo_argomento');
        tit.addEventListener('change', function () {
            var v = tit.value.trim();
            if (!v || v === ev.title) return;
            ev.title = v;
            $('tsSheetTit').textContent = v;
            api('salva_evento', { event_id: ev.id, title: v }).then(carica);
        });
        b.appendChild(tit);

        /* Le date su una riga sola, toccabile. Non sono due campi qualsiasi:
           cambiarle muove anche gli argomenti, e quella è una domanda da fare
           prima — per questo passano da una funzione loro e non dal salvataggio
           del titolo. */
        var quando = el('button', 'ts-quando');
        quando.type = 'button';
        quando.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3.5" y="5" width="17" height="16" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg>';
        quando.appendChild(el('span', null, ev.date === ev.end_date
            ? formattaGiorno(ev.date)
            : formattaGiorno(ev.date) + ' → ' + formattaGiorno(ev.end_date)));
        quando.addEventListener('click', function () { cambiaDate(ev); });
        b.appendChild(quando);

        /* Il progetto. È da qui che arriva il colore e, con lui, il diritto di
           entrare nelle statistiche dei pomodori. */
        var prog = el('button', 'ts-campo ts-progetto' + (ev.project_id ? ' ts-ha' : ''));
        prog.type = 'button';
        prog.style.setProperty('--c', ev.color || 'var(--ts-grigio)');
        prog.textContent = ev.project_name || T('todo.nessun_progetto');
        prog.addEventListener('click', function () { scegliProgetto(ev); });
        b.appendChild(prog);

        if (!ev.project_id) b.appendChild(el('div', 'ts-nota', T('todo.study_collega_per_statistiche')));

        /* Importante o no. È il bivio che decide dove vive l'evento: normale
           nel calendario dello studio, importante in quello principale — e da
           lì su Google, perché una data d'esame la vuoi sul telefono. */
        var imp = el('button', 'ts-campo ts-imp-toggle' + (ev.importance !== 'normal' ? ' ts-on' : ''));
        imp.type = 'button';
        imp.textContent = ev.importance !== 'normal'
            ? T('todo.study_e_importante')
            : T('todo.study_rendi_importante');
        imp.addEventListener('click', function () {
            var nuova = ev.importance === 'normal' ? 'high' : 'normal';
            api('salva_evento', { event_id: ev.id, importance: nuova })
                .then(function () { return carica(); })
                .then(function () { apriEvento(ev.id); });
        });
        b.appendChild(imp);

        b.appendChild(el('div', 'ts-sub', T('todo.study_argomenti')));

        (ev.tasks || []).forEach(function (t) { b.appendChild(rigaTaskFoglio(t, ev)); });

        var add = el('button', 'ts-add-task');
        add.type = 'button';
        add.textContent = '+ ' + T('todo.nuovo_argomento');
        add.addEventListener('click', function () {
            api('add', { event_id: ev.id, start: ev.date, end: ev.date })
                .then(function (r) {
                    if (!r.ok) { toast(T('comune.errore_generico')); return; }
                    return carica().then(function () { apriEvento(ev.id); });
                });
        });
        b.appendChild(add);

        /* I modelli: salvare la forma di questo piano, o applicarne una già
           salvata. Sta in fondo al foglio perché è la cosa che si fa alla fine
           — quando il piano è scritto e vale la pena tenerlo — o all'inizio su
           un blocco ancora vuoto. */
        var mod = el('div', 'ts-modelli');
        var salva = el('button', 'ts-modello-b', T('todo.study_salva_modello'));
        salva.type = 'button';
        salva.addEventListener('click', function () { salvaModello(ev); });
        mod.appendChild(salva);
        var usa = el('button', 'ts-modello-b', T('todo.study_usa_modello'));
        usa.type = 'button';
        usa.addEventListener('click', function () { usaModello(ev); });
        mod.appendChild(usa);
        b.appendChild(mod);

        var via = el('button', 'ts-elimina', T('comune.elimina'));
        via.type = 'button';
        via.addEventListener('click', function () { eliminaEvento(ev); });
        b.appendChild(via);

        $('tsOv').classList.add('ts-on');
        bloccaSfondo(true);
    }

    /* Con un popup aperto la pagina sotto non si muove. Scorrere lo sfondo
       mentre si scrive dentro un foglio è una cosa che succede da sola sul
       telefono — il dito esce dal foglio e il mese scivola via — e chiudendo
       ci si ritrova da un'altra parte senza aver fatto niente. */
    function bloccaSfondo(si) {
        var aperti = si
            || $('tsOv').classList.contains('ts-on')
            || $('tsScelta').classList.contains('ts-on')
            || $('tsFiltriOv').classList.contains('ts-on');
        document.body.classList.toggle('ts-ferma', aperti);
    }

    /* Un argomento, nel foglio: qui si può anche rinominare e datare, mentre
       nell'elenco del giorno si spunta e basta. */
    function rigaTaskFoglio(t, ev) {
        var x = el('div', 'ts-task-full');
        var riga1 = el('div', 'ts-task-r');

        var chk = el('button', 'ts-chk ts-chk-s' + (t.done ? ' ts-chk-on' : ''));
        chk.type = 'button';
        chk.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
        chk.addEventListener('click', function () {
            t.done = !t.done;
            chk.classList.toggle('ts-chk-on', t.done);
            api('toggle', { id: t.id, done: t.done ? 1 : 0 }).catch(function () { toast(T('comune.errore_rete')); });
        });
        riga1.appendChild(chk);

        var nome = el('input', 'ts-campo ts-campo-task');
        nome.type = 'text';
        nome.value = t.name;
        nome.addEventListener('change', function () {
            var v = nome.value.trim();
            if (!v) { nome.value = t.name; return; }
            t.name = v;
            api('save', { id: t.id, name: v }).catch(function () { toast(T('comune.errore_rete')); });
        });
        riga1.appendChild(nome);

        var via = el('button', 'ts-task-x');
        via.type = 'button';
        via.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
        via.addEventListener('click', function () { eliminaTask(t, ev); });
        riga1.appendChild(via);
        x.appendChild(riga1);

        /* Le date dell'argomento, obbligate dentro quelle del blocco: il campo
           stesso lo impedisce con min e max, e il server ricontrolla comunque
           perché un campo si può cambiare con gli strumenti da sviluppatore. */
        var riga2 = el('div', 'ts-task-r ts-task-date');
        var d1 = el('input', 'ts-campo ts-campo-data');
        var d2 = el('input', 'ts-campo ts-campo-data');
        [d1, d2].forEach(function (i) { i.type = 'date'; i.min = ev.date; i.max = ev.end_date; });
        d1.value = t.start; d2.value = t.end;
        function salvaDate() {
            if (d2.value < d1.value) d2.value = d1.value;
            t.start = d1.value; t.end = d2.value;
            api('save', { id: t.id, start: d1.value, end: d2.value })
                .then(carica)
                .catch(function () { toast(T('comune.errore_rete')); });
        }
        d1.addEventListener('change', salvaDate);
        d2.addEventListener('change', salvaDate);
        riga2.appendChild(d1);
        riga2.appendChild(el('span', 'ts-freccia', '→'));
        riga2.appendChild(d2);

        var stima = el('input', 'ts-campo ts-campo-stima');
        stima.type = 'number'; stima.min = 0; stima.max = 200;
        stima.placeholder = '🍅';
        stima.value = t.stimati || '';
        stima.title = T('todo.study_pomodori_stimati');
        stima.addEventListener('change', function () {
            api('save', { id: t.id, stimati: parseInt(stima.value, 10) || 0 }).then(carica);
        });
        riga2.appendChild(stima);
        x.appendChild(riga2);
        return x;
    }

    function scegliProgetto(ev) {
        var modale = $('ps-modal-study');
        if (!window.psOpen || !modale) { toast(T('comune.errore_generico')); return; }
        /* Il selettore annuncia la scelta con 'projectselected', che sale fino
           a document. Si ascolta una volta sola e ci si stacca subito: senza il
           removeEventListener, al secondo blocco aperto arriverebbero due
           risposte per una domanda, e il primo blocco si prenderebbe il
           progetto scelto per il secondo. */
        var ascolta = function (e) {
            document.removeEventListener('projectselected', ascolta);
            var pid = (e.detail && e.detail.id) ? e.detail.id : 0;
            api('salva_evento', { event_id: ev.id, project_id: pid })
                .then(function () { return carica(); })
                .then(function () { apriEvento(ev.id); });
        };
        document.addEventListener('projectselected', ascolta);
        window.psOpen(modale);
    }

    function eliminaTask(t, ev) {
        /* Senza pomodori dentro non c'è niente da perdere: si cancella e
           basta. Chiedere «scollegare o cancellare?» per una riga scritta due
           minuti fa e mai usata è una domanda a cui l'utente risponde a caso,
           perché nessuna delle due strade gli costa qualcosa. La domanda serve
           quando c'è del lavoro dentro. */
        if (!t.fatti) {
            api('remove', { id: t.id, modo: 'cancella', conferma: 1 })
                .then(function () { return carica(); })
                .then(function () { apriEvento(ev.id); })
                .catch(function () { toast(T('comune.errore_rete')); });
            return;
        }
        /* Due strade, come deciso: scollegare lascia il sotto-progetto in
           progetti con i suoi pomodori, cancellare porta via tutto. La seconda
           passa da una conferma che dice QUANTO si sta buttando — «sei
           sicuro?» senza un numero davanti non lo legge nessuno. */
        scelta(T('todo.study_togli_argomento'), t.name, [
            { k: 'scollega', txt: T('todo.study_solo_scollega') },
            { k: 'cancella', txt: T('comune.elimina'), rosso: true },
            { k: '', txt: T('comune.annulla') }
        ], function (k) {
            if (!k) return;
            if (k === 'scollega') {
                api('remove', { id: t.id, modo: 'scollega' })
                    .then(function () { return carica(); })
                    .then(function () { apriEvento(ev.id); });
                return;
            }
            api('remove', { id: t.id, modo: 'cancella' }).then(function (r) {
                if (r.ok) { carica().then(function () { apriEvento(ev.id); }); return; }
                var ore = Math.round((r.secondi || 0) / 360) / 10;
                scelta(T('todo.study_cancella_davvero'),
                    T('todo.study_cancella_avviso', { n: r.pomodori || 0, ore: ore }), [
                        { k: 'si', txt: T('todo.study_cancella_tutto'), rosso: true },
                        { k: '', txt: T('comune.annulla') }
                    ], function (k2) {
                        if (k2 !== 'si') return;
                        api('remove', { id: t.id, modo: 'cancella', conferma: 1 })
                            .then(function () { return carica(); })
                            .then(function () { apriEvento(ev.id); });
                    });
            });
        });
    }

    function eliminaEvento(ev) {
        var n = (ev.tasks || []).length;
        scelta(T('todo.study_elimina_blocco'),
            n ? T('todo.study_elimina_blocco_note', { n: n }) : ev.title, [
                { k: 'si', txt: T('comune.elimina'), rosso: true },
                { k: '', txt: T('comune.annulla') }
            ], function (k) {
                if (k !== 'si') return;
                api('elimina_evento', { event_id: ev.id }).then(function () {
                    chiudiFoglio();
                    carica();
                });
            });
    }

    /* ---------------- spostare un blocco ----------------
       Cambiare le date del padre è la cosa che può fare più danni: gli
       argomenti stanno dentro quel periodo, e restringerlo può lasciarne
       qualcuno fuori. Quindi si chiede, sempre — e le tre risposte sono
       quelle che il server sa eseguire. */
    function cambiaDate(ev) {
        var b = $('tsSheetBody');
        var vecchio = { da: ev.date, a: ev.end_date };

        scelta(T('todo.study_sposta'), '', [], null, function (box) {
            var r = el('div', 'ts-task-r ts-task-date');
            var d1 = el('input', 'ts-campo ts-campo-data');
            var d2 = el('input', 'ts-campo ts-campo-data');
            d1.type = 'date'; d2.type = 'date';
            d1.value = ev.date; d2.value = ev.end_date;
            r.appendChild(d1);
            r.appendChild(el('span', 'ts-freccia', '→'));
            r.appendChild(d2);
            box.appendChild(r);

            var ok = el('button', 'ts-scelta-b ts-scelta-primo', T('comune.salva'));
            ok.type = 'button';
            ok.addEventListener('click', function () {
                var da = d1.value, a = d2.value;
                if (!da) return;
                if (!a || a < da) a = da;
                chiudiScelta();
                applicaSpostamento(ev, da, a, vecchio);
            });
            box.appendChild(ok);
        });
    }

    function applicaSpostamento(ev, da, a, vecchio) {
        var conFigli = (ev.tasks || []).length > 0;
        var scarto = Math.round((dat(da) - dat(vecchio.da)) / 86400000);

        // niente figli, o niente da decidere: si salva e basta
        if (!conFigli) { manda(ev, da, a, 'segui'); return; }

        var voci = [
            { k: 'segui', txt: T('todo.study_figli_seguono') },
            { k: 'stringi', txt: T('todo.study_figli_restano') },
            { k: 'stacca', txt: T('todo.study_figli_staccati') },
            { k: '', txt: T('comune.annulla') }
        ];
        /* Se il blocco trasla senza accorciarsi, «restano» e «staccati» sono la
           stessa cosa e nessun argomento finisce fuori: si chiede solo la
           domanda che ha davvero due risposte. */
        var siAccorcia = giorniFra(da, a) < giorniFra(vecchio.da, vecchio.a);
        if (!siAccorcia && scarto === 0) { manda(ev, da, a, 'segui'); return; }
        if (!siAccorcia) voci = voci.filter(function (v) { return v.k !== 'stacca'; });

        scelta(T('todo.study_e_gli_argomenti'), T('todo.study_e_gli_argomenti_testo'), voci, function (k) {
            if (!k) return;
            manda(ev, da, a, k);
        });
    }
    function manda(ev, da, a, modo) {
        api('sposta_evento', { event_id: ev.id, start: da, end: a, modo: modo })
            .then(function (r) {
                if (r && r.staccati) toast(T('todo.study_staccati_n', { n: r.staccati }));
                return carica();
            })
            .then(function () { apriEvento(ev.id); })
            .catch(function () { toast(T('comune.errore_rete')); });
    }

    /* ---------------- come sei messo ----------------
       Conto alla rovescia, avanzamento, semaforo e materia trascurata: quattro
       cose che rispondono alla stessa domanda, «ce la faccio?», e che quindi
       stanno insieme sopra all'elenco. Aprendo la pagina quella domanda viene
       prima di «cosa faccio oggi».
       I numeri li calcola il server: qui si disegnano e basta, perché il conto
       poggia su tutta la storia dei pomodori e mandarla al browser per farci
       una media sarebbe spedire un archivio per ottenere un numero. */
    function caricaStato() {
        /* Due richieste in parallelo e non una dietro l'altra: sono
           indipendenti, e metterle in fila raddoppierebbe l'attesa per niente. */
        return Promise.all([api('stato', {}), api('settimana', {})]).then(function (due) {
            var r = due[0];
            if (r && r.ok && due[1] && due[1].ok) r.settimana = due[1];
            if (!r || !r.ok) return;
            /* La scala del carico dipende dal ritmo, e il ritmo arriva dopo il
               calendario: si ridisegna solo se è davvero cambiato, o ogni
               apertura richiuderebbe i giorni passati che si erano aperti. */
            var prima = S.ritmo;
            S.ritmo = r.ritmo || 0;
            disegnaStato(r);
            if (prima !== S.ritmo) disegna();
            proponiRiporto();
        }).catch(function () { /* lo stato è un di più: se non arriva, pazienza */ });
    }

    function disegnaStato(d) {
        var box = $('tsStato');
        box.innerHTML = '';

        (d.materie || []).forEach(function (m) {
            if (!m.nome && !m.esame) return;
            var r = el('div', 'ts-mat' + (m.semaforo ? ' ts-sem-' + m.semaforo : ''));
            r.style.setProperty('--c', m.colore || 'var(--ts-grigio)');

            var alto = el('div', 'ts-mat-t');
            alto.appendChild(el('span', 'ts-mat-n', m.nome || (m.esame && m.esame.titolo) || '—'));
            if (m.giorni !== null && m.giorni !== undefined) {
                var quanto = m.giorni === 0 ? T('todo.study_oggi_esame')
                          : (m.giorni === 1 ? T('todo.study_domani_esame')
                          : T('todo.study_giorni_all_esame', { n: m.giorni }));
                alto.appendChild(el('span', 'ts-mat-g', quanto));
            }
            r.appendChild(alto);

            /* La barra dell'avanzamento è la cosa che si guarda per prima, e
               deve dire una verità sola: quanto è fatto di quello che c'era. */
            var barra = el('div', 'ts-mat-b');
            var dentro = el('div', 'ts-mat-b-in');
            dentro.style.width = (m.tot ? Math.round(m.fatti / m.tot * 100) : 0) + '%';
            barra.appendChild(dentro);
            r.appendChild(barra);

            var basso = el('div', 'ts-mat-s');
            basso.appendChild(el('span', null, T('todo.study_avanzamento', { fatti: m.fatti, tot: m.tot })));
            if (m.previsti > 0) basso.appendChild(el('span', null, T('todo.study_previsti', { n: m.previsti })));
            if (m.disponibili !== null && m.disponibili !== undefined && m.giorni) {
                basso.appendChild(el('span', 'ts-mat-sp', T('todo.study_disponibili', { n: m.disponibili })));
            }
            r.appendChild(basso);

            /* La materia trascurata. Dieci giorni sono la soglia sotto cui è
               normale non toccare una cosa; sopra, diventa una notizia. */
            if (m.da_giorni !== null && m.da_giorni >= 10) {
                r.appendChild(el('div', 'ts-mat-fermo', T('todo.study_trascurata', { n: m.da_giorni })));
            }

            /* Il tasto che rifà il piano all'indietro dall'esame. Compare solo
               se una data d'esame c'è: senza, non c'è nessun «indietro» da cui
               partire, e il tasto sarebbe un invito a un errore. */
            if (m.project_id && m.giorni !== null && m.rimasti > 0) {
                var pia = el('button', 'ts-mat-pia', T('todo.study_pianifica'));
                pia.type = 'button';
                pia.addEventListener('click', function () { pianificaMateria(m); });
                r.appendChild(pia);
            }
            box.appendChild(r);
        });

        /* Quanto ti conosco. Non è vanteria di numeri: è il patto — più
           pianifichi, più le previsioni qui sopra valgono qualcosa. */
        var note = [];
        if (d.ritmo > 0) note.push(T('todo.study_ritmo', { n: d.ritmo }));
        if (d.campioni >= 3) {
            var scarto = Math.round(Math.abs(d.errore - 1) * 100);
            if (scarto >= 8) note.push(d.errore > 1
                ? T('todo.study_sottostimi', { n: scarto })
                : T('todo.study_sovrastimi', { n: scarto }));
            if (d.tendenza >= 0.15) note.push(T('todo.study_rallenti', { n: Math.round(d.tendenza * 100) }));
        } else {
            note.push(T('todo.study_poche_stime'));
        }
        if (note.length) {
            var n = el('div', 'ts-conosco');
            note.forEach(function (x) { n.appendChild(el('div', null, x)); });
            box.appendChild(n);
        }

        /* Il consuntivo della settimana. Ha bisogno di un posto fisso in cui
           farsi guardare: le statistiche di sua iniziativa non le apre
           nessuno, si aprono quando qualcosa è già andato storto. */
        if (d.settimana) {
            var w = d.settimana, o = w.ora, pr = w.prima;
            var s7 = el('div', 'ts-sett');
            s7.appendChild(el('div', 'ts-sett-t', T('todo.study_settimana')));
            var riga = el('div', 'ts-sett-r');
            riga.appendChild(vociSettimana(T('todo.study_pomodori'), o.pomodori, pr.pomodori));
            riga.appendChild(vociSettimana(T('todo.study_ore'), o.ore, pr.ore));
            riga.appendChild(vociSettimana(T('todo.study_argomenti_fatti'), o.argomenti, pr.argomenti));
            s7.appendChild(riga);
            /* Meglio e peggio hanno senso solo se c'è un confronto: con una
               materia sola si confronterebbe una cosa con sé stessa. */
            if ((w.materie || []).length >= 2) {
                var m0 = w.materie[0], mN = w.materie[w.materie.length - 1];
                s7.appendChild(el('div', 'ts-sett-m',
                    T('todo.study_meglio_peggio', { su: m0.nome, giu: mN.nome })));
            }
            box.appendChild(s7);
        }

        /* I blocchi grigi. Non un rimprovero: si dice cosa ci si perde. */
        if (d.senza_progetto > 0) {
            box.appendChild(el('div', 'ts-grigi', T('todo.study_n_senza_progetto', { n: d.senza_progetto })));
        }
        if (!box.children.length) {
            box.appendChild(el('div', 'ts-conosco', T('todo.study_niente_stato')));
        }
    }

    /* ---------------- modelli di materia ---------------- */
    function salvaModello(ev) {
        if (!(ev.tasks || []).length) { toast(T('todo.study_modello_vuoto')); return; }
        scelta(T('todo.study_salva_modello'), T('todo.study_salva_modello_testo'), [], null, function (box) {
            var campo = el('input', 'ts-campo');
            campo.type = 'text';
            campo.value = ev.title;
            box.appendChild(campo);
            var ok = el('button', 'ts-scelta-b ts-scelta-primo', T('comune.salva'));
            ok.type = 'button';
            ok.addEventListener('click', function () {
                var nome = campo.value.trim() || ev.title;
                chiudiScelta();
                api('salva_modello', { event_id: ev.id, name: nome })
                    .then(function (r) {
                        if (!r.ok) { toast(r.message || T('comune.errore_generico')); return; }
                        toast(T('todo.study_modello_salvato', { n: r.voci }));
                    });
            });
            box.appendChild(ok);
            var no = el('button', 'ts-scelta-b', T('comune.annulla'));
            no.type = 'button';
            no.addEventListener('click', function () { chiudiScelta(); });
            box.appendChild(no);
        });
    }

    function usaModello(ev) {
        api('modelli', {}).then(function (r) {
            if (!r.ok) { toast(T('comune.errore_generico')); return; }
            if (!(r.modelli || []).length) { toast(T('todo.study_nessun_modello')); return; }
            /* Il modello si applica AGGIUNGENDO, non sostituendo: cancellare
               quello che c'è già per far posto a una ricetta sarebbe una
               perdita silenziosa, e chi non vuole i doppioni li toglie a mano
               vedendoli. */
            var voci = r.modelli.slice(0, 8).map(function (m) {
                var n = 0;
                try { n = (JSON.parse(m.voci) || []).length; } catch (e) {}
                return { k: String(m.id), txt: m.name + ' · ' + n };
            });
            voci.push({ k: '', txt: T('comune.annulla') });
            scelta(T('todo.study_usa_modello'), T('todo.study_usa_modello_testo'), voci, function (k) {
                if (!k) return;
                api('applica_modello', { modello_id: k, event_id: ev.id })
                    .then(function (rr) {
                        if (!rr.ok) { toast(rr.message || T('comune.errore_generico')); return; }
                        toast(T('todo.study_modello_usato', { n: rr.creati }));
                        return carica().then(function () { apriEvento(ev.id); });
                    });
            });
        }).catch(function () { toast(T('comune.errore_rete')); });
    }

    /* ---------------- il riporto di quello che non è stato fatto ----------------
       Un argomento la cui finestra è passata senza spunta non resta indietro in
       silenzio. Si propone di spostarlo, non lo si sposta: le date le ha messe
       l'utente, e un piano che si riscrive da solo mentre non guardi è un piano
       di cui smette di fidarsi. Si chiede una volta per apertura. */
    var riportoChiesto = false;
    function proponiRiporto() {
        if (riportoChiesto) return;
        riportoChiesto = true;
        api('riporta', { applica: 0 }).then(function (r) {
            if (!r.ok) return;
            var n = (r.spostati || []).length, k = (r.senza_posto || []).length;
            if (!n && !k) return;

            var testo = [];
            if (n) testo.push(T('todo.study_riporto_n', { n: n }));
            /* Quelli che non ci stanno più sono la notizia importante: il piano
               non regge, e va detto adesso — non il giorno dell'esame. */
            if (k) testo.push(T('todo.study_riporto_fuori', { n: k }));

            scelta(T('todo.study_riporto'), testo.join(' '), [
                { k: 'si', txt: T('todo.study_riporto_si') },
                { k: '', txt: T('todo.study_riporto_no') }
            ], function (scelto) {
                if (scelto !== 'si') return;
                api('riporta', { applica: 1 }).then(function () { carica(); });
            });
        }).catch(function () {});
    }

    /* ---------------- pianificazione a ritroso ----------------
       Si mostra prima e si applica dopo. Un piano che si scrive addosso alle
       date esistenti senza chiedere è una cosa che si fa una volta e poi non
       si tocca più il tasto per paura — soprattutto quando il piano dice che
       non ci stai dentro, che è l'informazione più utile delle due. */
    function pianificaMateria(m) {
        api('pianifica', { project_id: m.project_id, applica: 0 }).then(function (r) {
            if (!r.ok) { toast(r.message || T('comune.errore_generico')); return; }
            if (r.niente || !r.piano.length) { toast(T('todo.study_niente_da_piazzare')); return; }

            var ce_la_fai = r.fuori === 0 && r.servono <= r.spazio;
            scelta(T('todo.study_pianifica'), '', [], null, function (box) {
                /* Il verdetto per primo, prima dell'elenco: se il piano non ci
                   sta, guardare venti righe di date prima di scoprirlo è tempo
                   perso. */
                var v = el('div', 'ts-piano-v' + (ce_la_fai ? ' ts-ok' : ' ts-no'));
                v.textContent = ce_la_fai
                    ? T('todo.study_ci_stai', { serve: r.servono, spazio: r.spazio, giorni: r.giorni })
                    : T('todo.study_non_ci_stai', { serve: r.servono, spazio: r.spazio, fuori: r.fuori });
                box.appendChild(v);

                var el2 = el('div', 'ts-piano');
                r.piano.forEach(function (x) {
                    var riga = el('div', 'ts-piano-r');
                    riga.appendChild(el('span', 'ts-piano-n', x.nome));
                    riga.appendChild(el('span', 'ts-piano-d',
                        x.da === x.a ? formattaGiorno(x.da) : formattaGiorno(x.da) + '–' + formattaGiorno(x.a)));
                    riga.appendChild(el('span', 'ts-piano-p', x.pomodori + '🍅'));
                    el2.appendChild(riga);
                });
                box.appendChild(el2);

                var ok = el('button', 'ts-scelta-b ts-scelta-primo', T('todo.study_applica_piano'));
                ok.type = 'button';
                ok.addEventListener('click', function () {
                    chiudiScelta();
                    api('pianifica', { project_id: m.project_id, applica: 1 })
                        .then(function () { carica(); toast(T('todo.study_piano_fatto')); })
                        .catch(function () { toast(T('comune.errore_rete')); });
                });
                box.appendChild(ok);

                var no = el('button', 'ts-scelta-b', T('comune.annulla'));
                no.type = 'button';
                no.addEventListener('click', function () { chiudiScelta(); });
                box.appendChild(no);
            });
        }).catch(function () { toast(T('comune.errore_rete')); });
    }

    /* Un numero della settimana con accanto quanto è cambiato. «Dodici
       pomodori» non dice niente; «dodici, quattro più della scorsa» sì — ed è
       la sola forma in cui un numero da solo diventa un'informazione. */
    function vociSettimana(nome, ora, prima) {
        var v = el('div', 'ts-sett-v');
        v.appendChild(el('div', 'ts-sett-n', String(ora)));
        v.appendChild(el('div', 'ts-sett-l', nome));
        var diff = Math.round((ora - prima) * 10) / 10;
        if (diff !== 0) {
            v.appendChild(el('div', 'ts-sett-d' + (diff > 0 ? ' ts-su' : ' ts-giu'),
                (diff > 0 ? '+' : '') + diff));
        }
        return v;
    }

    /* ---------------- il pannello dei filtri ----------------
       Sei domande, non una. «Mostra tutto il planner» le rispondeva tutte
       insieme o nessuna, e il risultato era un calendario o vuoto o illeggibile.
       Calendari e categorie stanno chiusi dietro una freccetta: chi ne ha venti
       si troverebbe un elenco più lungo dello schermo prima ancora di leggere
       le quattro voci che si cambiano davvero. */
    function apriFiltri() {
        api('filtri', {}).then(function (r) {
            if (!r.ok) { toast(r.message || T('comune.errore_generico')); return; }
            S.filtri = r.filtri;
            S.calendari = r.calendari || [];
            S.categorie = r.categorie || [];
            // la spunta grande e la prima riga del pannello sono la stessa
            // cosa: aprendo il pannello si riallineano
            $('tsPlannerOn').checked = !!r.filtri.on;
            disegnaFiltri();
            $('tsFiltriOv').classList.add('ts-on');
            bloccaSfondo(true);
        }).catch(function () { toast(T('comune.errore_rete')); });
    }
    function chiudiFiltri() {
        $('tsFiltriOv').classList.remove('ts-on');
        bloccaSfondo(false);
    }

    function disegnaFiltri() {
        var box = $('tsFiltriBox');
        box.innerHTML = '';

        [['highest', 'study_f_highest'], ['high', 'study_f_high'],
         ['routine', 'study_f_routine'], ['habit', 'study_f_habit']].forEach(function (v) {
            box.appendChild(rigaFiltro(T('todo.' + v[1]), S.filtri[v[0]], function (on) {
                S.filtri[v[0]] = on ? 1 : 0;
            }));
        });

        box.appendChild(gruppoFiltro(T('todo.study_f_cal'), S.calendari, S.filtri.cal));
        box.appendChild(gruppoFiltro(T('todo.study_f_cat'), S.categorie, S.filtri.cat));
    }

    function rigaFiltro(nome, acceso, onCambia, colore) {
        var r = el('label', 'ts-fr');
        var c = el('input');
        c.type = 'checkbox';
        c.checked = !!acceso;
        c.addEventListener('change', function () { onCambia(c.checked); });
        r.appendChild(c);
        if (colore) {
            var pallino = el('span', 'ts-fr-c');
            pallino.style.background = colore;
            r.appendChild(pallino);
        }
        r.appendChild(el('span', 'ts-fr-n', nome));
        return r;
    }

    /* Un gruppo che si apre e si chiude. La freccia gira, e sotto scorrono le
       voci: l'elenco resta dov'è invece di comparire e sparire di colpo, così
       si capisce da dove sono uscite. */
    function gruppoFiltro(nome, voci, scelte) {
        var g = el('div', 'ts-fg');
        var testa = el('button', 'ts-fg-t');
        testa.type = 'button';
        testa.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
        testa.appendChild(el('span', null, nome));
        var quanti = el('span', 'ts-fg-n', scelte.length ? String(scelte.length) : '');
        testa.appendChild(quanti);
        g.appendChild(testa);

        var dentro = el('div', 'ts-fg-in');
        voci.forEach(function (v) {
            var id = parseInt(v.id, 10);
            dentro.appendChild(rigaFiltro(v.name, scelte.indexOf(id) >= 0, function (on) {
                var i = scelte.indexOf(id);
                if (on && i < 0) scelte.push(id);
                if (!on && i >= 0) scelte.splice(i, 1);
                quanti.textContent = scelte.length ? String(scelte.length) : '';
            }, v.color));
        });
        g.appendChild(dentro);

        testa.addEventListener('click', function () {
            g.classList.toggle('ts-aperto');
            // l'altezza vera al momento dell'apertura: un valore fisso
            // taglierebbe gli elenchi lunghi e lascerebbe un vuoto sui corti
            dentro.style.maxHeight = g.classList.contains('ts-aperto') ? (dentro.scrollHeight + 'px') : '0px';
        });
        return g;
    }

    function salvaFiltri() {
        chiudiFiltri();
        api('salva_filtri', { filtri: JSON.stringify(S.filtri) })
            .then(function (r) {
                if (r && r.filtri) S.filtri = r.filtri;
                $('tsPlannerOn').checked = !!(S.filtri && S.filtri.on);
                carica();
            })
            .catch(function () { toast(T('comune.errore_rete')); });
    }

    /* ---------------- popup di scelta ----------------
       Uno solo per tutta la pagina: le domande sono diverse ma la forma è
       sempre la stessa, e vederla comparire uguale aiuta a riconoscerla.
       `costruisci` serve ai casi in cui dentro non ci vanno solo pulsanti — il
       cambio date ci mette due campi. */
    var sceltaCb = null;
    function scelta(titolo, testo, voci, cb, costruisci) {
        var ov = $('tsScelta');
        ov.querySelector('.ts-scelta-t').textContent = titolo;
        var m = ov.querySelector('.ts-scelta-m');
        m.textContent = testo || '';
        m.style.display = testo ? '' : 'none';
        var box = ov.querySelector('.ts-scelta-b-box');
        box.innerHTML = '';
        sceltaCb = cb;
        (voci || []).forEach(function (v) {
            var b = el('button', 'ts-scelta-b' + (v.rosso ? ' ts-rosso' : ''), v.txt);
            b.type = 'button';
            b.addEventListener('click', function () { chiudiScelta(v.k); });
            box.appendChild(b);
        });
        if (costruisci) costruisci(box);
        ov.classList.add('ts-on');
        bloccaSfondo(true);
    }
    function chiudiScelta(k) {
        $('tsScelta').classList.remove('ts-on');
        bloccaSfondo(false);
        if (sceltaCb) { var cb = sceltaCb; sceltaCb = null; cb(k); }
    }

    function chiudiFoglio() {
        $('tsOv').classList.remove('ts-on');
        S.aperto = null;
        bloccaSfondo(false);
    }
    function formattaGiorno(s) {
        var d = dat(s);
        return d.getUTCDate() + ' ' + T('planner.mese_' + (d.getUTCMonth() + 1));
    }

    /* ---------------- scorrimento fra i mesi ----------------
       Qui il gesto orizzontale è libero: non c'è la vista settimana né quella
       giorno a contendersela, come succede nel planner. */
    var sw = null;
    function swInizio(e) {
        var p = e.touches ? e.touches[0] : e;
        sw = { x: p.clientX, y: p.clientY, preso: false };
    }
    function swMuovi(e) {
        // si sta disegnando un blocco: il mese non si muove
        if (!sw || (press && press.attivo) || barraPresa) return;
        var p = e.touches ? e.touches[0] : e;
        var dx = p.clientX - sw.x, dy = p.clientY - sw.y;
        /* Soglia alta e nettamente orizzontale: il gesto per cambiare mese deve
           essere una cosa che si fa apposta, non qualcosa in cui si inciampa
           mentre si scorre la pagina o si trascina su tre giorni. */
        if (!sw.preso && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 2) {
            sw.preso = true;
            vaiAlMese(dx < 0 ? 1 : -1);
        }
    }
    function swFine() { sw = null; }

    function vaiAlMese(n) {
        S.mese = spostaMese(S.mese, n);
        var cal = $('tsCal');
        cal.classList.add(n > 0 ? 'ts-da-destra' : 'ts-da-sinistra');
        setTimeout(function () { cal.classList.remove('ts-da-destra', 'ts-da-sinistra'); }, 320);
        carica();
    }

    /* ---------------- avvio ---------------- */
    function avvia() {
        var oggi = new Date();
        S.mese = iso(new Date(Date.UTC(oggi.getFullYear(), oggi.getMonth(), 1))).slice(0, 8) + '01';

        var cal = $('tsCal');
        cal.addEventListener('touchstart', function (e) { inizioPressione(e); swInizio(e); }, { passive: true });
        cal.addEventListener('touchmove', function (e) { muoviPressione(e); swMuovi(e); }, { passive: false });
        cal.addEventListener('touchend', function () { finePressione(); swFine(); });
        cal.addEventListener('mousedown', inizioPressione);
        cal.addEventListener('mousemove', muoviPressione);
        cal.addEventListener('mouseup', finePressione);
        cal.addEventListener('mouseleave', finePressione);

        /* Su document e non sulla barra: trascinando, il dito esce quasi
           subito da quei dieci pixel, e ascoltatori attaccati alla barra
           perderebbero il movimento appena comincia. */
        document.addEventListener('pointermove', muoviBarra, { passive: false });
        document.addEventListener('pointerup', lasciaBarra);
        document.addEventListener('pointercancel', lasciaBarra);

        $('tsPrev').addEventListener('click', function () { vaiAlMese(-1); });
        $('tsNext').addEventListener('click', function () { vaiAlMese(1); });
        $('tsOggi').addEventListener('click', function () {
            var o = new Date();
            var m = iso(new Date(Date.UTC(o.getFullYear(), o.getMonth(), 1))).slice(0, 8) + '01';
            if (m === S.mese) { disegna(); return; }   // già lì: si ridisegna e basta
            S.mese = m;
            carica();
        });
        $('tsPlanner').addEventListener('click', apriFiltri);
        /* L'interruttore generale. Spento, del planner non entra piu' niente
           qualunque cosa dica il pannello: restano solo lo studio e le date
           importanti. Le sei scelte dentro non si azzerano — riaccendendolo si
           ritrova quello che si era scelto invece di doverlo rifare. */
        $('tsPlannerOn').addEventListener('change', function () {
            var on = this.checked ? 1 : 0;
            if (!S.filtri) S.filtri = { on: on, highest: 1, high: 1, routine: 0, habit: 0, cal: [], cat: [] };
            S.filtri.on = on;
            api('salva_filtri', { filtri: JSON.stringify(S.filtri) })
                .then(function (r) { if (r && r.filtri) S.filtri = r.filtri; carica(); })
                .catch(function () { toast(T('comune.errore_rete')); });
        });
        /* Lo stato della spunta si sa solo dopo aver letto i filtri salvati:
           finche' non arrivano, la casella resta com'e' nel markup. */
        api('filtri', {}).then(function (r) {
            if (!r.ok) return;
            S.filtri = r.filtri;
            S.calendari = r.calendari || [];
            S.categorie = r.categorie || [];
            $('tsPlannerOn').checked = !!r.filtri.on;
        }).catch(function () {});
        $('tsFiltriOk').addEventListener('click', salvaFiltri);
        $('tsFiltriOv').addEventListener('click', function (e) {
            if (e.target === this) { chiudiFiltri(); }
        });
        $('tsCompatta').classList.toggle('ts-attivo', S.compatta);
        $('tsCompatta').addEventListener('click', function () {
            S.compatta = !S.compatta;
            this.classList.toggle('ts-attivo', S.compatta);
            disegnaLista();
        });
        $('tsSoloKey').addEventListener('click', function () {
            S.soloKey = !S.soloKey;
            this.classList.toggle('ts-attivo', S.soloKey);
            disegnaLista();
        });
        $('tsClose').addEventListener('click', chiudiFoglio);
        $('tsOv').addEventListener('click', function (e) { if (e.target === this) chiudiFoglio(); });
        $('tsAddTop').addEventListener('click', function () {
            var oggiIso = iso(new Date(Date.now() - new Date().getTimezoneOffset() * 60000));
            var g = meseDi(oggiIso) === meseDi(S.mese) ? oggiIso : S.mese;
            nuovoBlocco(g, g, 'normal');
        });
        $('tsAddImp').addEventListener('click', function () {
            var oggiIso = iso(new Date(Date.now() - new Date().getTimezoneOffset() * 60000));
            var g = meseDi(oggiIso) === meseDi(S.mese) ? oggiIso : S.mese;
            nuovoBlocco(g, g, 'high');
        });

        // il tasto porta il numero di oggi: si aggiorna una volta all'avvio,
        // che per una pagina che si tiene aperta un pomeriggio basta
        $('tsOggi').textContent = String(new Date().getDate());

        carica();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
    else avvia();
})();
