/* planner/js/04_barra_e_priorita.js — barra intelligente (da una frase a un evento), fascia delle priorita.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Barra intelligente: da una frase a un evento                        */
/* ------------------------------------------------------------------ */
var SMART = null;
var RUBRICA = [];

/* La rubrica serve alla barra intelligente per riconoscere "con luchino".
   È un elenco corto, quindi lo teniamo in memoria invece di interrogare il
   server a ogni frase scritta. */
function loadRubrica() {
    return api('contacts').then(function (r) {
        RUBRICA = (r && r.ok) ? r.contacts : [];
    }).catch(function () { RUBRICA = []; });
}

function loadSmart() {
    return fetch('planner_smart.json', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { SMART = d; })
        .catch(function () { /* senza il file la barra resta muta */ });
}

/* La Pasqua dell'anno chiesto (algoritmo di Meeus): serve per Pasquetta,
   Carnevale e le altre feste che si spostano ogni anno. */
function pasquaDi(anno) {
    var a = anno % 19, b = Math.floor(anno / 100), c = anno % 100;
    var dd = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
    var g = Math.floor((b - f + 1) / 3), h = (19 * a + b - dd - g + 15) % 30;
    var i = Math.floor(c / 4), k = c % 4;
    var l = (32 + 2 * e + 2 * i - h - k) % 7;
    var m = Math.floor((a + 11 * h + 22 * l) / 451);
    var mese = Math.floor((h + l - 7 * m + 114) / 31);
    var giorno = ((h + l - 7 * m + 114) % 31) + 1;
    return anno + '-' + pad(mese) + '-' + pad(giorno);
}

/* Quante correzioni servono per passare da una parola all'altra: serve per
   perdonare gli errori di battitura ("agsoto" invece di "agosto"). */
function distanzaParole(a, b) {
    a = String(a); b = String(b);
    if (Math.abs(a.length - b.length) > 3) return 99;
    var prev = [], cur = [];
    for (var j2 = 0; j2 <= b.length; j2++) prev[j2] = j2;
    for (var i2 = 1; i2 <= a.length; i2++) {
        cur[0] = i2;
        for (var k2 = 1; k2 <= b.length; k2++) {
            var costo = a.charAt(i2 - 1) === b.charAt(k2 - 1) ? 0 : 1;
            cur[k2] = Math.min(cur[k2 - 1] + 1, prev[k2] + 1, prev[k2 - 1] + costo);
        }
        prev = cur.slice();
    }
    return prev[b.length];
}

/* Cerca nel testo una delle chiavi date, perdonando qualche lettera sbagliata.
   Ritorna { key, match } oppure null. Le chiavi più lunghe hanno la
   precedenza, così "san valentino" vince su "valentino". */
function trovaChiave(text, chiavi, tolleranza) {
    var ordinate = chiavi.slice().sort(function (x, y) { return y.length - x.length; });
    /* prima le corrispondenze esatte */
    for (var i = 0; i < ordinate.length; i++) {
        if (text.indexOf(' ' + ordinate[i] + ' ') !== -1) return { key: ordinate[i], match: ordinate[i] };
    }
    if (!tolleranza) return null;
    /* poi quelle con qualche svista, parola per parola */
    var parole = text.split(/\s+/).filter(Boolean);
    for (var p = 0; p < parole.length; p++) {
        if (parole[p].length < 4) continue;          // troppo corta: rischio di scambi
        for (var c = 0; c < ordinate.length; c++) {
            if (ordinate[c].indexOf(' ') !== -1) continue;   // solo parole singole
            var limite = ordinate[c].length <= 5 ? 1 : tolleranza;
            if (distanzaParole(parole[p], ordinate[c]) <= limite) {
                return { key: ordinate[c], match: parole[p] };
            }
        }
    }
    return null;
}

/* Legge la frase e ne ricava data, ora e titolo. Le parti riconosciute
   vengono tolte dal testo: quello che avanza è il titolo dell'evento. */
function smartParse(raw) {
    if (!SMART) return null;
    var text = ' ' + String(raw).toLowerCase().trim() + ' ';
    var used = [];
    var date = null, time = null, mealFound = false;
    /* segna se l'ora è stata scritta davvero, invece di essere dedotta */
    var timeExplicit = false;

    function eat(re) {
        var m = text.match(re);
        if (m) { used.push(m[0].trim()); text = text.replace(m[0], ' '); }
        return m;
    }

    /* --- ora all'inglese: 3pm, 9.30am, "at 7 pm" --- */
    var mAmPm = eat(/\s(?:at\s+)?(1[0-2]|0?[1-9])(?:[:.]([0-5]\d))?\s*(am|pm)\s/);
    if (mAmPm) {
        var h12 = parseInt(mAmPm[1], 10) % 12 + (mAmPm[3] === 'pm' ? 12 : 0);
        time = pad(h12) + ':' + (mAmPm[2] || '00');
        timeExplicit = true;
    }

    /* --- ora: 12:30, 12.30, "alle 15", "ore 9", "at 15" --- */
    var mTime = mAmPm ? null : eat(/\s(?:alle|alla|ore|ora|verso|at)?\s*([01]?\d|2[0-3])[:.]([0-5]\d)\s/);
    if (mAmPm) {
        /* già fatto qui sopra */
    } else if (mTime) {
        time = pad(parseInt(mTime[1], 10)) + ':' + mTime[2];
        timeExplicit = true;
    } else {
        var mHour = eat(/\s(?:alle|ore|ora|verso|at|around)\s+([01]?\d|2[0-3])\s/);
        if (!mHour) mHour = eat(/\s([01]?\d|2[0-3])\s*$/);
        if (mHour) { time = pad(parseInt(mHour[1], 10)) + ':00'; timeExplicit = true; }
    }

    /* --- feste a data fissa: ferragosto, natale, capodanno… --- */
    var tol = SMART.tolleranza_errori_battitura || 0;
    if (!date && SMART.festivita) {
        var f = trovaChiave(text, Object.keys(SMART.festivita), tol);
        if (f) {
            var info = SMART.festivita[f.key];
            var y1 = parseIso(S.today).getFullYear();
            var cand1 = y1 + '-' + pad(info.mese) + '-' + pad(info.giorno);
            if (cand1 < S.today) cand1 = (y1 + 1) + '-' + pad(info.mese) + '-' + pad(info.giorno);
            date = cand1;
            used.push(f.key);
            text = text.replace(' ' + f.match + ' ', ' ');
        }
    }

    /* --- feste che si spostano: pasqua, pasquetta, carnevale --- */
    if (!date && SMART.festivita_mobili) {
        var fm = trovaChiave(text, Object.keys(SMART.festivita_mobili), tol);
        if (fm) {
            var y2 = parseIso(S.today).getFullYear();
            var cand2 = addDays(pasquaDi(y2), SMART.festivita_mobili[fm.key]);
            if (cand2 < S.today) cand2 = addDays(pasquaDi(y2 + 1), SMART.festivita_mobili[fm.key]);
            date = cand2;
            used.push(fm.key);
            text = text.replace(' ' + fm.match + ' ', ' ');
        }
    }

    /* --- data esplicita: 13/8, 13-08-2026 --- */
    var mNum = eat(/\s(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\s/);
    if (mNum) {
        var yy = mNum[3] ? parseInt(mNum[3], 10) : parseIso(S.today).getFullYear();
        if (yy < 100) yy += 2000;
        date = yy + '-' + pad(parseInt(mNum[2], 10)) + '-' + pad(parseInt(mNum[1], 10));
    }

    /* --- giorno e mese scritti: "13 agosto", anche con qualche svista --- */
    if (!date) {
        /* "13 agosto", "13th august" e, all'inglese, "august 13th" */
        var mm2 = text.match(/\s(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?([a-zàèéìòù]{3,12})\s/);
        var mm2b = mm2 ? null : text.match(/\s([a-z]{3,12})\s+(\d{1,2})(?:st|nd|rd|th)?\s/);
        if (mm2b && trovaChiave(' ' + mm2b[1] + ' ', Object.keys(SMART.mesi), 0)) mm2 = [mm2b[0], mm2b[2], mm2b[1]];
        if (mm2) {
            var mese = trovaChiave(' ' + mm2[2] + ' ', Object.keys(SMART.mesi), tol);
            if (mese) {
                var y3 = parseIso(S.today).getFullYear();
                var cand3 = y3 + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(parseInt(mm2[1], 10));
                if (cand3 < S.today) cand3 = (y3 + 1) + '-' + pad(SMART.mesi[mese.key]) + '-' + pad(parseInt(mm2[1], 10));
                date = cand3;
                used.push(mm2[1] + ' ' + mese.key);
                text = text.replace(mm2[0], ' ');
            }
        }
    }

    /* --- fasce del giorno: si guardano PRIMA di togliere le parole del
           giorno relativo. Era questo il difetto: "stasera" vale sia come
           giorno (oggi) sia come ora (20:00), ma veniva cancellata dal testo
           come giorno e poi non si trovava più come ora. Così "cena stasera"
           funzionava (l'ora arrivava da "cena") e "mangio bene stasera" no. --- */
    if (!time && SMART.fasce_orarie) {
        var fa = trovaChiave(text, Object.keys(SMART.fasce_orarie), 0);
        if (fa) time = SMART.fasce_orarie[fa.key];
    }
    /* I pasti danno un'ora solo se non è stata scritta: "domani pranzo alle
       18" deve finire alle 18, non alle 12:30. L'orario detto dall'utente
       vince sempre su quello dedotto da una parola. */
    if (SMART.pasti) {
        var pa = trovaChiave(text, Object.keys(SMART.pasti), tol);
        if (pa) {
            mealFound = true;                    // basta a non farlo diventare "tutto il giorno"
            if (!timeExplicit) time = SMART.pasti[pa.key];
        }
    }

    /* --- giorni relativi: oggi, domani, dopodomani --- */
    if (!date) {
        var rel = Object.keys(SMART.giorni_relativi).sort(function (a, b) { return b.length - a.length; });
        for (var i = 0; i < rel.length; i++) {
            if (text.indexOf(' ' + rel[i] + ' ') === -1) continue;
            date = addDays(S.today, SMART.giorni_relativi[rel[i]]);
            used.push(rel[i]);
            /* le parole che dicono anche l'ora restano nel titolo solo se non
               sono già state usate come tali */
            var conserva = (SMART.fasce_da_conservare || []).indexOf(rel[i]) !== -1;
            if (!conserva) text = text.replace(' ' + rel[i] + ' ', ' ');
            else text = text.replace(' ' + rel[i] + ' ', ' ');
            break;
        }
    }

    /* --- giorni della settimana: il prossimo che capita --- */
    if (!date) {
        var wdKeys = Object.keys(SMART.giorni_settimana);
        var wd = trovaChiave(text, wdKeys, tol);
        if (wd) {
            var target = SMART.giorni_settimana[wd.key];      // 1 = lunedì
            var oggiDow = dowIndex(S.today) + 1;
            var delta = (target - oggiDow + 7) % 7;
            if (delta === 0) delta = 7;                        // "martedì" detto di martedì = il prossimo
            var piu = false;
            (SMART.settimana_prossima || []).forEach(function (w) { if (text.indexOf(w) !== -1) piu = true; });
            if (piu) delta += 7;
            date = addDays(S.today, delta);
            used.push(wd.key);
            text = text.replace(' ' + wd.match + ' ', ' ');
        }
    }

    /* --- con chi: "con luchino" invita luchino, e il nome resta nel titolo
           perché l'evento si chiama davvero "pranzo con luchino" --- */
    var invitati = [];
    if (RUBRICA.length) {
        var tol2 = SMART.tolleranza_errori_battitura || 0;
        RUBRICA.forEach(function (c) {
            var nome = String(c.name || '').toLowerCase().trim();
            if (!nome) return;
            if (invitati.some(function (g) { return g.email === c.email; })) return;

            /* "con luchino" è il caso sicuro: la preposizione dice che si
               parla di una persona e non di una parola qualsiasi */
            if (text.indexOf(' con ' + nome + ' ') !== -1 || text.indexOf(' with ' + nome + ' ') !== -1) {
                invitati.push({ email: c.email, name: c.name });
                return;
            }
            /* il nome da solo vale solo se è abbastanza lungo da non
               confondersi con una parola comune */
            if (nome.length >= 4) {
                var trovato = trovaChiave(text, [nome], tol2);
                if (trovato) invitati.push({ email: c.email, name: c.name });
            }
        });
    }

    /* --- quello che resta è il titolo --- */
    var parole = text.split(/\s+/).filter(Boolean).filter(function (w) {
        return (SMART.parole_da_togliere || []).indexOf(w) === -1;
    });
    var titolo = parole.join(' ').trim();
    if (titolo) titolo = titolo.charAt(0).toUpperCase() + titolo.slice(1);

    return {
        title: titolo,
        guests: invitati,
        date: date || S.focusDate,
        time: time,
        /* senza un'ora indicata l'evento dura tutto il giorno, a meno che la
           frase parlasse di un pasto: "pranzo" senza ora va a mezzogiorno */
        allDay: !time && SMART.tutto_il_giorno_se_manca_ora !== false && !mealFound,
        dateGuessed: !date,
        timeGuessed: !time,
        used: used
    };
}

function smartCreate(input) {
    var raw = (input.value || '').trim();
    if (!raw) return;
    var p = smartParse(raw);
    if (!p || !p.title) { toast(T('planner.non_ho_capito_cosa_scrivere')); return; }

    var dur = (SMART && SMART.durata_predefinita_minuti) || 60;
    var start = p.allDay ? '00:00' : (p.time || toHM(firstFreeMinute(dur)));
    var end = p.allDay ? '23:59' : toHM(Math.min(1439, toMin(start) + dur));

    var payload = {
        title: p.title, date: p.date, start: start, end: end,
        all_day: p.allDay ? 1 : 0,
        guests: p.guests || [],
        type: 'event', notify: p.allDay ? 'none' : 'h1'
    };
    /* compare subito: la barra si svuota e si passa al giorno giusto senza
       aspettare il server */
    var tmp = creaSubito(payload);
    input.value = '';
    input.blur();
    if (p.date !== S.focusDate) goToDate(p.date, true); else renderDays();
    haptic(12);
    smartReport(p, start, end, S.locali[tmp] || eventoLocale(payload, null, tmp));
}

/* Dettatura della barra: si parla, e dopo un paio di secondi di silenzio
   l'evento viene creato da sé. Se non si dice niente, si spegne e basta. */
function setupSmartMic(btn, input) {
    var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Rec) return;                 // dove non c'è, il tasto resta nascosto
    btn.hidden = false;

    var rec = null, running = false, silence = null, spoken = false;

    function stop(andCreate) {
        running = false;
        btn.classList.remove('pl-rec');
        if (silence) { clearTimeout(silence); silence = null; }
        if (rec) { try { rec.stop(); } catch (e) {} }
        if (andCreate && input.value.trim()) smartCreate(input);
    }

    function armSilence() {
        if (silence) clearTimeout(silence);
        /* due secondi e mezzo di silenzio: se qualcosa è stato detto lo si
           inserisce, altrimenti si spegne senza fare nulla */
        silence = setTimeout(function () { stop(spoken); }, 2500);
    }

    btn.addEventListener('click', function () {
        if (running) { stop(false); return; }
        rec = new Rec();
        rec.lang = document.documentElement.lang === 'en' ? 'en-US' : 'it-IT';
        rec.continuous = true;
        rec.interimResults = true;
        spoken = false;

        rec.onresult = function (e) {
            var testo = '';
            for (var i = 0; i < e.results.length; i++) testo += e.results[i][0].transcript;
            testo = testo.trim();
            if (testo) { input.value = testo; spoken = true; }
            armSilence();
        };
        rec.onerror = function () { stop(false); };
        rec.onend = function () { if (running) { try { rec.start(); } catch (e) { stop(spoken); } } };

        try {
            rec.start();
            running = true;
            btn.classList.add('pl-rec');
            armSilence();
        } catch (e) { stop(false); }
    });
}

/* Il riepilogo di quello che ha capito: resta qualche secondo e se ne va. */
function smartReport(p, start, end, created) {
    var d = parseIso(p.date);
    var quando = GIORNI[dowIndex(p.date)] + ' ' + d.getDate() + ' ' + minuscolo(MESI[d.getMonth()]);
    var box = $('plSmartReport');
    if (!box) {
        box = el('div', 'pl-smart-report');
        box.id = 'plSmartReport';
        document.body.appendChild(box);
    }
    box.innerHTML = '';
    box.appendChild(el('div', 'pl-smart-title', '“' + p.title + '”'));
    box.appendChild(el('div', 'pl-smart-line', quando + (p.allDay ? T('planner.virgola_tutto_il_giorno') : T('planner.dalle_alle', {da: start, a: end}))));
    if (p.guests && p.guests.length) {
        box.appendChild(el('div', 'pl-smart-line',
            T('planner.con_invitati', {chi: p.guests.map(function (g) { return g.name || g.email; }).join(', ')}) + T('planner.invito_inviato')));
    }
    var note = [];
    if (p.dateGuessed) note.push(T('planner.giorno_non_indicato_ho_usato'));
    if (p.timeGuessed && p.allDay) note.push(T('planner.ora_non_indicata_messo_su'));
    else if (p.timeGuessed) note.push(T('planner.ora_dedotta'));
    note.push(T('planner.tocca_sistemarlo'));
    box.appendChild(el('div', 'pl-smart-note', note.join(' · ')));

    box.classList.add('pl-open');
    if (S._smartTimer) clearTimeout(S._smartTimer);
    /* qualche secondo in più: il tempo di leggere e decidere se ritoccarlo */
    S._smartTimer = setTimeout(function () { box.classList.remove('pl-open'); }, 9000);
    /* toccandolo si apre la modifica, per dargli una categoria o correggerlo */
    box.onclick = function () {
        box.classList.remove('pl-open');
        if (created) openEventSheet(created, null);
    };
}

/* ------------------------------------------------------------------ */
/* Fascia priorità (fissa, appartiene al giorno a fuoco)               */
/* ------------------------------------------------------------------ */
function prioOf(date) {
    var list = (S.prio[date] || []).slice();
    var manual = {};
    var autos = [];
    list.forEach(function (p) {
        if (p.auto) autos.push(p); else manual[p.position] = p;
    });
    return { manual: manual, autos: autos };
}

function renderPrio() {
    var host = $('plPrioRows');
    if (!host) return;
    renderPrioInto(host, S.focusDate);
    $('plPrio').classList.toggle('pl-slim', S.prioSlim);
    syncScrollerTop();
}

/* Il disegno vero e proprio sta qui, staccato dal nodo fisso della pagina:
   serve anche per la pagina del giorno vicino che entra con lo scorrimento,
   che ha le sue caselle e non può usare quelle in uso adesso. */
function renderPrioInto(host, giorno) {
    var data = prioOf(giorno);
    host.innerHTML = '';

    var cells = [];
    /* la cella automatica si porta dietro tutto quello che il server ha
       detto del progetto: è quello che poi il popup ha da raccontare */
    data.autos.forEach(function (a) {
        cells.push({
            auto: true, content: a.content, project_id: a.project_id, project: a.project,
            notes: a.notes, stato: a.stato, color: a.color,
            target_date: a.target_date, due_date: a.due_date,
            overdue: a.overdue,
            todo_id: a.todo_id, list_type: a.list_type // <-- AGGIUNGI QUESTA RIGA
        });
    });

    /* Obiettivi e scadenze dei progetti occupano una casella come le altre:
       vanno contati nel totale, altrimenti restano in giro due caselle vuote
       invece di una (era il bug che vedevi con un "obiettivo" in elenco). */
    var filled = 0;
    for (var p = 1; p <= 6; p++) if (data.manual[p] && data.manual[p].content) filled = p;
    var wanted = cells.length + filled + 1;               // una sola casella libera in coda
    var total = Math.min(6 + cells.length, Math.max(2, Math.ceil(wanted / 2) * 2));
    var slots = Math.max(1, total - cells.length);
    for (var i = 1; i <= slots; i++) {
        var m = data.manual[i];
        cells.push({ position: i, content: m ? m.content : '', done: m ? m.done : false });
    }

    /* da chiusa la sezione mostra solo ciò che è scritto: le caselle vuote
       compaiono quando la si tocca, altrimenti rubano spazio per niente */
    var shown = S.prioSlim
        ? cells.filter(function (x) { return x.auto || (x.content && x.content.trim()); })
        : cells;
    if (!shown.length && S.prioSlim) {
        host.appendChild(el('div', 'pl-row-sub', T('planner.nessuna_priorita_tocca_per_aggiungerne')));
    }
    /* Due per riga sempre, aperta o chiusa: tre affiancate su un telefono
       sarebbero tre parole tagliate. Da chiusa, però, di righe se ne vede una
       sola per volta, e le altre passano da sé. */
    var righe = [];
    for (var r = 0; r < shown.length; r += 2) {
        var row = el('div', 'pl-prio-row');
        for (var c = r; c < Math.min(r + 2, shown.length); c++) {
            row.appendChild(prioCell(shown[c], S.prioSlim));
        }
        host.appendChild(row);
        righe.push(row);
    }

    /* Da chiusa si vede una riga per volta e le righe girano da sé: con sei
       priorità e una riga sola in vista, le altre quattro sarebbero
       invisibili e tanto varrebbe non averle scritte. La colonnina di pallini
       dice quante righe ci sono e a che punto siamo: senza, il cambio
       sembrerebbe un difetto invece che un giro.
       Si parte sempre dalla prima riga — quello che si è scritto per primo è
       quello che conta di più, e deve essere la prima cosa che si vede. */
    var aGiro = S.prioSlim && righe.length > 1;
    righe.forEach(function (riga, i) { riga.classList.toggle('pl-on', !aGiro || i === 0); });

    var padre = host.parentNode;
    if (padre) {
        var vecchi = padre.querySelector('.pl-prio-pal');
        if (vecchi) vecchi.parentNode.removeChild(vecchi);
    }
    /* il giro e i pallini valgono per la fascia vera e non per la copia della
       pagina vicina che entra con lo scorrimento: due giri contemporanei si
       darebbero il cambio a caso */
    if (aGiro && padre && host.id === 'plPrioRows') {
        var pal = el('div', 'pl-prio-pal');
        righe.forEach(function (_, i) {
            pal.appendChild(el('span', 'pl-prio-pallino' + (i ? '' : ' pl-on')));
        });
        padre.appendChild(pal);
        avviaGiroPrio(righe, pal);
    } else if (host.id === 'plPrioRows') {
        fermaGiroPrio();
    }
}

/* Il riassunto di un progetto, aperto da una priorità automatica.

   Piccolo di proposito: quello che serve qui è capire in un colpo d'occhio
   di che progetto si tratta e se è ancora vivo, non gestirlo. Per gestirlo
   c'è la sua pagina, e il tasto in fondo ci porta già aperto su quello — che
   è poi la ragione per cui l'identificativo viaggia insieme alla priorità. */
function prioProgetto(data) {
    var ov = el('div', 'pl-ov');
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));

    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', data.project || T('planner.progetto')));
    var chiudi = el('button', 'pl-txt-btn', T('planner.chiudi'));
    chiudi.type = 'button';
    /* si chiude e basta: le priorità restano come stavano (ci pensa
       daPannello) e il tocco non prosegue verso la pagina sotto */
    chiudi.addEventListener('click', function () { ov.remove(); assorbiDopoChiusura(); });
    head.appendChild(chiudi);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');

    /* il pallino del colore accanto allo stato: è il colore con cui quel
       progetto si riconosce dappertutto, e ritrovarlo qui evita di dover
       leggere il nome per capire quale sia */
    var riga = el('div', 'pl-prj-stato');
    var pallino = el('span', 'pl-prj-dot');
    pallino.style.background = data.color || 'var(--accent)';
    riga.appendChild(pallino);
    riga.appendChild(el('span', null, STATI_PRJ[data.stato] || T('planner.prj_in_corso')));
    body.appendChild(riga);

    if (data.target_date) body.appendChild(rowInfo(T('planner.obiettivo_t'), dataLunga(data.target_date)));
    if (data.due_date)    body.appendChild(rowInfo(T('planner.scadenza'), dataLunga(data.due_date)));

    /* Le note per intero e non tagliate: sono poche righe e sono la ragione
       per cui uno apre il riassunto invece di andare direttamente alla
       pagina. Se non ci sono, non si scrive niente: una riga vuota che dice
       «nessuna nota» occupa lo stesso spazio e non aggiunge nulla. */
    if (data.notes) {
        var note = el('div', 'pl-prj-note');
        note.textContent = data.notes;
        body.appendChild(note);
    }

    var vai = el('a', 'pl-btn pl-btn-primary pl-prj-vai', T('planner.apri_il_progetto'));
    vai.href = '../projects/progetti.php?id=' + encodeURIComponent(data.project_id);
    body.appendChild(vai);

    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
    ov.addEventListener('click', function (e) {
        if (e.target !== ov) return;
        ov.remove();
        assorbiDopoChiusura();
    });
}

/* gli stessi tre stati che gira la pagina progetti, in parole */
var STATI_PRJ = { in_progress: T('planner.prj_in_corso'), completed: T('planner.prj_completato'), planned: T('planner.prj_da_iniziare') };

/* una riga «etichetta — valore», che qui serve due o tre volte */
function rowInfo(lab, val) {
    var r = el('div', 'pl-prj-riga');
    r.appendChild(el('span', 'pl-prj-lab', lab));
    r.appendChild(el('span', 'pl-prj-val', val));
    return r;
}

function dataLunga(iso) {
    var d = parseIso(iso);
    return GIORNI[dowIndex(iso)] + ' ' + d.getDate() + ' ' + minuscolo(MESI[d.getMonth()])
         + (d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : '');
}

/* Il giro delle righe da chiusa. Sei secondi: quattro bastavano a leggere
   due priorità corte, ma non una lunga che sta ancora scorrendo — la riga
   cambiava a metà frase e bisognava aspettare tutto il giro per finirla.
   Sei danno il tempo di leggere e anche di decidere se toccarla.
   Il timer si spegne da sé quando le righe non sono più attaccate alla
   pagina — succede a ogni ridisegno — così non se ne accumulano uno sopra
   l'altro senza che nessuno li fermi. */
var prioGiro = null;
function fermaGiroPrio() {
    if (prioGiro) { clearInterval(prioGiro); prioGiro = null; }
}
function avviaGiroPrio(righe, pal) {
    fermaGiroPrio();
    if (righe.length < 2) return;
    var i = 0;
    prioGiro = setInterval(function () {
        if (!S.prioSlim || !righe[0].isConnected) { fermaGiroPrio(); return; }
        i = (i + 1) % righe.length;
        righe.forEach(function (r, k) { r.classList.toggle('pl-on', k === i); });
        for (var k = 0; k < pal.children.length; k++) {
            pal.children[k].classList.toggle('pl-on', k === i);
        }
    }, 6000);
}

function prioCell(data, compatta) {
    var cell = el('div', 'pl-prio-cell' + (data.done ? ' pl-done' : '') + (data.auto ? ' pl-auto' : ''));
    var dot = el('span', 'pl-prio-dot' + (data.done ? ' pl-on' : ''));
    dot.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';

    // Stile soft: sfondo e testo restano standard, solo i bordi diventano rossi per richiamare l'attenzione
    if (data.overdue) {
        cell.style.boxShadow = 'inset 0 0 0 1.2px #C98F8F';
        dot.style.boxShadow = 'inset 0 0 0 1.2px #C98F8F';
    }

    /* Da chiusa non è un campo ma una scritta, e la scritta che non ci sta scorre */
    if (compatta) {
        var tx = el('span', 'pl-prio-tx');
        var dentro = el('span', null, data.content || '');
        tx.appendChild(dentro);
        cell.appendChild(dot);
        cell.appendChild(tx);
        misuraStriscia(tx, dentro);
        if (data.auto) {
            if (data.project_id) {
                tx.style.cursor = 'pointer';
                tx.addEventListener('click', function (e) {
                    e.stopPropagation();   // se no il tocco aprirebbe la sezione
                    prioProgetto(data);
                });
            } else if (data.todo_id) {
                tx.style.cursor = 'pointer';
                tx.addEventListener('click', function (e) {
                    e.stopPropagation();
                    S.todoTab = data.list_type; // Imposta la linguetta corretta (es. To Buy)
                    S.todoOthers = true;        // Assicurati di espandere le voci "rimaste indietro"
                    openWeekPanel();            // Apri il pannello
                });
            }
            return cell;
        }
        dot.addEventListener('click', function (e) {
            e.stopPropagation();     // se no il tocco aprirebbe anche la sezione
            var now = !cell.classList.contains('pl-done');
            cell.classList.toggle('pl-done', now);
            dot.classList.toggle('pl-on', now);
            haptic(8);
            savePrio(data.position, data.content || '', now);
        });
        return cell;
    }

    /* Da aperta le automatiche non sono più un campo ma una scritta, come da
       chiusa. Nessuno le può correggere — erano già di sola lettura — e da
       scritte possono scorrere quando non ci stanno, che è il caso normale:
       elenco, stato e titolo tutti insieme ("TO DO SCADUTO: …") non entrano
       mai in mezza riga, e aprendo la sezione si finiva per leggere sempre e
       solo le prime tre parole. */
    if (data.auto) {
        var txAp = el('span', 'pl-prio-tx');
        var dentroAp = el('span', null, data.content || '');
        txAp.appendChild(dentroAp);
        cell.appendChild(dot);
        cell.appendChild(txAp);
        misuraStriscia(txAp, dentroAp);
        if (data.project_id) {
            txAp.style.cursor = 'pointer';
            txAp.addEventListener('click', function (e) {
                e.stopPropagation();
                prioProgetto(data);
            });
        } else if (data.todo_id) {
            txAp.style.cursor = 'pointer';
            txAp.addEventListener('click', function (e) {
                e.stopPropagation();
                S.todoTab = data.list_type;
                S.todoOthers = true;
                openWeekPanel();
            });
        }
        return cell;
    }

    var inp = el('input', 'pl-prio-in');
    inp.type = 'text';
    inp.value = data.content || '';
    inp.placeholder = T('planner.priorita');
    cell.appendChild(dot);
    cell.appendChild(inp);

    /* Anche una priorità scritta a mano scorre se non ci sta. Qui però non
       c'è un figlio da spostare — dentro un campo di testo non ce ne sono —
       e allora si muove il rientro della prima riga, che trascina il testo
       dentro la finestra del campo. Mentre ci si scrive l'animazione si ferma
       (regola :focus nel CSS), se no il cursore scapperebbe di lato proprio
       sotto il dito. */
    misuraStriscia(inp, inp);

    dot.addEventListener('click', function () {
        var now = !cell.classList.contains('pl-done');
        cell.classList.toggle('pl-done', now);
        dot.classList.toggle('pl-on', now);
        haptic(8);
        savePrio(data.position, inp.value, now);
    });
    inp.addEventListener('input', function () {
        debounceSave('prio' + data.position, 650, function () {
            savePrio(data.position, inp.value, cell.classList.contains('pl-done'));
        });
    });
    inp.addEventListener('blur', function () {
        savePrio(data.position, inp.value, cell.classList.contains('pl-done'));
        renderPrio();
    });

    /* La crocetta c'è solo da aperta e solo su una casella scritta: svuotare
       il campo a mano cancellava già la priorità, ma bisognava saperlo, e su
       una riga lunga voleva dire selezionare tutto e cancellare. Su una
       casella vuota non ci sarebbe niente da togliere. */
    if (data.content) {
        var via = el('button', 'pl-prio-x');
        via.type = 'button';
        via.setAttribute('aria-label', T('planner.elimina_questa_priorita'));
        via.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        via.addEventListener('click', function (e) {
            e.stopPropagation();
            haptic(8);
            savePrio(data.position, '', false);
            renderPrio();
        });
        cell.appendChild(via);
    }
    return cell;
}

function savePrio(position, content, done) {
    var date = S.focusDate;
    var list = (S.prio[date] || []).filter(function (p) { return p.auto || p.position !== position; });
    if (content) list.push({ position: position, content: content, done: !!done });
    S.prio[date] = list;
    api('priority_save', { date: date, position: position, content: content, done: !!done });
}

function setPrioSlim(slim) {
    if (S.prioSlim === slim) return;
    S.prioSlim = slim;
    renderPrio();
}
