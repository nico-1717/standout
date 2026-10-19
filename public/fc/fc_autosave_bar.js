/* fc_autosave_bar.js — il motore dietro la barra di salvataggio flottante.
 *
 * PERCHE' ESISTE
 * Nelle pagine "big" si costruiscono decine di righe a mano. Finche' il
 * salvataggio era una cosa sola alla fine, bastava un ricaricamento, una
 * connessione caduta o un tocco sbagliato per buttare via mezz'ora di lavoro.
 * Qui il salvataggio diventa una cosa che si puo' fare in qualsiasi momento,
 * e - se l'interruttore e' acceso - che si fa da sola.
 *
 * COME SI USA
 *   StandoutSaveBar.init({ key: 'big_edit_fc', save: saveAll });
 * dove save() e' la funzione della pagina che manda i dati al server e
 * restituisce (anche come Promise) true se e' andata bene, false altrimenti.
 * La pagina chiama StandoutSaveBar.touch() ogni volta che qualcosa cambia.
 *
 * IMPORTANTE: save() non deve ricaricare la pagina. Se lo facesse, il
 * salvataggio automatico diventerebbe un ricaricamento automatico, cioe'
 * esattamente il problema che questo file esiste per togliere.
 *
 * PIU' BARRE SULLA STESSA PAGINA
 * Una pagina "big" puo' includere anche un modale con un salvataggio
 * indipendente (gli esempi di una flashcard, per dire): stesso meccanismo,
 * ma un dirty/pulito tutto suo, separato da quello della pagina principale.
 * Per questo ogni funzione accetta un nome di istanza facoltativo:
 *   StandoutSaveBar.init({ instance: 'esempi', key: 'esempi', save: salvaEsempio });
 *   StandoutSaveBar.touch('esempi');
 * Senza nome di istanza si usa quella "default" — che e' esattamente il
 * comportamento di sempre, per chi non sa che le istanze esistono. Ogni
 * istanza cerca i suoi elementi con l'id giusto: nsSaveBar per la default,
 * nsSaveBar-esempi per l'istanza "esempi" (e cosi' per gli altri tre id).
 */
(function () {
    'use strict';

    var ISTANZE = {};   // nome istanza -> il suo stato, isolato dalle altre

    function nuovoStato() {
        return {
            save: null,
            chiave: 'standout_autosave',
            attesa: 1800,      // ms di quiete prima di un salvataggio automatico
            controllo: 25000,  // rete di sicurezza: ogni 25s, se e' rimasto sporco
            sporco: false,
            salvando: false,
            inCoda: false,
            auto: false,
            ultimo: null,
            timer: null,
            pronto: false,
            suffisso: '',       // '' per la default, '-nome' per le altre
            testoMaiSalvato: '' // cosa mostrare sotto l'interruttore prima del primo salvataggio
        };
    }

    /* Ogni istanza si crea al primo utilizzo, cosi' touch()/markSaved() non
       falliscono se chiamate prima di init() (puo' capitare con il salvataggio
       automatico agganciato a un evento che scatta subito). */
    function statoDi(nome) {
        nome = nome || 'default';
        if (!ISTANZE[nome]) ISTANZE[nome] = nuovoStato();
        return ISTANZE[nome];
    }

    function el(id) { return document.getElementById(id); }

    function dueCifre(n) { return n < 10 ? '0' + n : '' + n; }

    function oraDi(d) {
        return dueCifre(d.getHours()) + ':' + dueCifre(d.getMinutes()) + ':' + dueCifre(d.getSeconds());
    }

    /* Ridisegna la barra a partire dallo stato. Un punto solo: cosi' non
       esistono combinazioni di classi che nessuno ha previsto. */
    function disegna(S) {
        var barra = el('nsSaveBar' + S.suffisso);
        if (!barra) return;
        barra.classList.toggle('ns-dirty', S.sporco);
        barra.classList.toggle('ns-saving', S.salvando);

        var btn = el('nsSaveNow' + S.suffisso);
        if (btn) btn.disabled = S.salvando;

        var etichetta = el('nsLastSaved' + S.suffisso);
        if (etichetta) {
            if (S.salvando) {
                etichetta.textContent = 'salvo…';
                etichetta.classList.remove('ns-mai');
            } else if (S.ultimo) {
                etichetta.textContent = oraDi(S.ultimo);
                etichetta.classList.remove('ns-mai');
            } else if (S.testoMaiSalvato) {
                /* Alcune istanze preferiscono dire qualcosa (es. "Auto save")
                   invece di lasciare lo spazio vuoto: la pagina lo decide
                   passando testoMaiSalvato a init(), il comportamento di
                   sempre (vuoto) resta il default per chi non lo passa. */
                etichetta.textContent = S.testoMaiSalvato;
                etichetta.classList.add('ns-mai');
            } else {
                /* Vuoto invece di "mai": prima di un qualunque salvataggio
                   non c'e' niente di utile da dire li', e "mai" suonava piu'
                   allarmante di quanto la situazione richieda — e' solo che
                   non si e' ancora scritto niente. */
                etichetta.textContent = '';
                etichetta.classList.add('ns-mai');
            }
        }
    }

    function programma(S) {
        clearTimeout(S.timer);
        S.timer = setTimeout(function () { esegui(S, false); }, S.attesa);
    }

    /* manuale = true quando l'ha chiesto l'utente: in quel caso si salva anche
       se non risulta niente di sporco (male non fa, e toglie il dubbio). */
    function esegui(S, manuale) {
        if (!S.save) return Promise.resolve(false);
        if (S.salvando) { S.inCoda = true; return Promise.resolve(false); }
        if (!manuale && !S.sporco) return Promise.resolve(false);

        clearTimeout(S.timer);
        S.salvando = true;
        disegna(S);

        return Promise.resolve()
            .then(function () { return S.save(manuale); })
            .then(function (esito) {
                if (esito !== false) {
                    S.sporco = false;
                    S.ultimo = new Date();
                }
                return esito !== false;
            })
            .catch(function (err) {
                console.error('[autosave] salvataggio fallito', err);
                return false;
            })
            .then(function (ok) {
                S.salvando = false;
                disegna(S);
                /* Se qualcuno ha scritto mentre salvavamo, quella modifica non
                   e' compresa nel giro appena finito: se ne rifa' uno. */
                if (S.inCoda) {
                    S.inCoda = false;
                    if (S.auto && S.sporco) programma(S);
                }
                return ok;
            });
    }

    function accendiAuto(S, acceso, silenzioso) {
        S.auto = !!acceso;
        try { localStorage.setItem(S.chiave, S.auto ? '1' : '0'); } catch (e) { /* modalita' privata: pazienza */ }
        var toggle = el('nsAutoToggle' + S.suffisso);
        if (toggle) toggle.checked = S.auto;
        if (S.auto && S.sporco) programma(S); else clearTimeout(S.timer);
        if (!silenzioso && typeof showToast === 'function') {
            showToast(S.auto ? 'Salvataggio automatico attivo' : 'Salvataggio automatico spento');
        }
    }

    function init(opzioni) {
        opzioni = opzioni || {};
        var nome = opzioni.instance || 'default';
        var S = statoDi(nome);
        S.suffisso = nome === 'default' ? '' : ('-' + nome);
        S.save = opzioni.save || null;
        if (opzioni.key) S.chiave = 'standout_autosave_' + opzioni.key;
        if (opzioni.delay) S.attesa = opzioni.delay;
        if (opzioni.testoMaiSalvato) S.testoMaiSalvato = opzioni.testoMaiSalvato;

        var memorizzato = null;
        try { memorizzato = localStorage.getItem(S.chiave); } catch (e) { /* niente */ }
        accendiAuto(S, memorizzato === '1', true);

        var btn = el('nsSaveNow' + S.suffisso);
        if (btn) btn.addEventListener('click', function () { esegui(S, true); });

        var toggle = el('nsAutoToggle' + S.suffisso);
        if (toggle) toggle.addEventListener('change', function () { accendiAuto(S, toggle.checked, false); });

        /* Rete di sicurezza: se per qualche motivo il timer di quiete non e'
           scattato (un errore, una scheda in background), ogni 25 secondi si
           riprova finche' c'e' qualcosa da salvare. */
        setInterval(function () {
            if (S.auto && S.sporco && !S.salvando) esegui(S, false);
        }, S.controllo);

        /* Uscendo dalla pagina con l'automatico acceso si tenta un ultimo
           salvataggio. Non e' garantito che il browser lo porti a termine:
           serve a recuperare il caso comune, non a sostituire il pulsante. */
        window.addEventListener('pagehide', function () {
            if (S.auto && S.sporco && !S.salvando) esegui(S, false);
        });

        S.pronto = true;
        disegna(S);
    }

    window.StandoutSaveBar = {
        init: init,
        /* qualcosa e' cambiato in pagina (o nell'istanza indicata) */
        touch: function (istanza) {
            var S = statoDi(istanza);
            if (S.sporco && S.auto) { programma(S); return; }
            S.sporco = true;
            disegna(S);
            if (S.auto) programma(S);
        },
        /* la pagina ha salvato per conto suo (es. il pulsante in fondo) */
        markSaved: function (istanza) {
            var S = statoDi(istanza);
            S.sporco = false;
            S.ultimo = new Date();
            disegna(S);
        },
        /* Si e' deciso di NON salvare le modifiche (es. si chiude un modale
           scegliendo di scartarle): pulito com'era prima, ma senza far finta
           che sia successo un salvataggio — "ultimo" non si tocca. */
        discard: function (istanza) {
            var S = statoDi(istanza);
            clearTimeout(S.timer);
            S.sporco = false;
            S.inCoda = false;
            disegna(S);
        },
        /* Come discard(), ma azzera anche "ultimo": serve quando la stessa
           istanza viene riusata per un elemento diverso (un esempio dopo
           l'altro, nello stesso modale) e l'orario del salvataggio
           precedente non deve comparire per quello nuovo, che non e' mai
           stato toccato. */
        reset: function (istanza) {
            var S = statoDi(istanza);
            clearTimeout(S.timer);
            S.sporco = false;
            S.inCoda = false;
            S.salvando = false;
            S.ultimo = null;
            disegna(S);
        },
        saveNow: function (istanza) { return esegui(statoDi(istanza), true); },
        isDirty: function (istanza) { return statoDi(istanza).sporco; },
        isAuto: function (istanza) { return statoDi(istanza).auto; },
        /* Forza l'interruttore a uno stato preciso, scavalcando quello che
           localStorage ricordava. Serve a chi vuole ripartire sempre da uno
           stato fisso a ogni apertura (es. il modale degli esempi, che deve
           iniziare sempre spento) invece di ricordare la preferenza tra una
           sessione e l'altra come fa normalmente questa barra. */
        setAuto: function (acceso, istanza, silenzioso) {
            accendiAuto(statoDi(istanza), acceso, silenzioso !== false);
        },
        /* la barra si toglie di mezzo mentre si trascina una riga */
        hide: function (nascondi, istanza) {
            var S = statoDi(istanza);
            var barra = el('nsSaveBar' + S.suffisso);
            if (barra) barra.classList.toggle('ns-hidden', nascondi !== false);
        }
    };
})();
