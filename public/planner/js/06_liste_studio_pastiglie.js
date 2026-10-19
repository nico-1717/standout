/* planner/js/06_liste_studio_pastiglie.js — liste della settimana, da studiare, pastiglie, anello delle abitudini.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Liste della settimana: to do, to buy, to study                      */
/* ------------------------------------------------------------------ */
/* Il pannello non tiene più liste sue. Mostra gli stessi dati della pagina
   attività, filtrati sulla settimana che si sta guardando: prima esistevano
   due elenchi con lo stesso nome che non sapevano l'uno dell'altro, e una
   cosa scritta di qua non compariva di là.

   Le vecchie tabelle `planner_week_lists` restano com'erano, insieme ai loro
   endpoint: non si cancella niente finché non si è sicuri di non averne più
   bisogno, e quello che c'è dentro è comunque recuperabile. */

/* Le attività non passano da planner.php: todo_api.php è già l'unico punto
   di scrittura di quei dati, e duplicare qui la validazione vorrebbe dire
   avere due posti in cui sbagliarla. */
function todoApi(action, data, query) {
    var url = 'todo_api.php?ajax=' + encodeURIComponent(action);
    if (query) { for (var k in query) if (query.hasOwnProperty(k)) url += '&' + k + '=' + encodeURIComponent(query[k]); }
    var req = {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        /* La lettura degli elenchi è una GET su un indirizzo che non cambia
           mai: subito dopo un salvataggio il browser rispondeva con la copia
           di prima. È per questo che la priorità del to buy cambiava per un
           attimo e poi tornava indietro — non era il salvataggio a fallire,
           era la rilettura a non arrivare mai al server. */
        cache: 'no-store',
        headers: { 'X-CSRF-Token': window.STANDOUT_CSRF || S.csrf }
    };
    if (data) { req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(data); }
    return fetch(url, req)
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .catch(function () { return { ok: false }; });
}

/* Una lettura per linguetta, tenuta in memoria: il pannello si ridisegna a
   ogni render della pagina e non può chiedere l'elenco al server ogni volta. */
function loadWeekTodos(type, force) {
    S.todos = S.todos || {};
    if (S.todos[type] && !force) return Promise.resolve();
    return todoApi('list', null, { scope: 'all', list_type: type }).then(function (r) {
        if (!r.ok) return;
        S.todos[type] = r.todos || [];
        S.todosToday = r.today;
        renderWeekPanel();
        updateWeekPanelBtn();
    });
}

function weekTodoRange() {
    var from = monday(S.focusDate);
    return { from: from, to: addDays(from, 6) };
}

function renderWeekPanel() {
    var wrap = $('plWeekPanelBody');
    if (!wrap) return;
    /* finché il pannello è chiuso non c'è niente da disegnare: render() passa
       di qui a ogni ridisegno della pagina, anche mentre si trascina */
    var ov = $('plWeekPanelOv');
    if (!ov || !ov.classList.contains('pl-open')) return;

    var rg = weekTodoRange();
    wrap.innerHTML = '';

    var d1 = parseIso(rg.from), d2 = parseIso(rg.to);
    var range = d1.getDate() + ' ' + minuscolo(MESI[d1.getMonth()]) + ' – ' + d2.getDate() + ' ' + minuscolo(MESI[d2.getMonth()]);
    wrap.appendChild(el('div', 'pl-label', range));

    /* Le linguette: i tre elenchi più le pastiglie. Nella pagina attività ci
       sono tutte e quattro, e qui ne mancava una sola — che è poi quella che
       si guarda più spesso durante la giornata. */
    var tabs = el('div', 'pl-wl-tabs');
    PANEL_TABS.forEach(function (t) {
        var b = el('button', 'pl-wl-tab' + (t === S.todoTab ? ' pl-on' : ''), LIST_LABEL[t]);
        b.type = 'button';
        b.addEventListener('click', function () {
            if (S.todoTab === t) return;
            S.todoTab = t;
            S.todoOthers = false;
            renderWeekPanel();
            if (t === 'pills') loadWeekPills(S.focusDate);
            else if (t === 'study') loadStudy(S.focusDate);
            else loadWeekTodos(t);
        });
        tabs.appendChild(b);
    });
    wrap.appendChild(tabs);

    var host = el('div', 'pl-lists');
    wrap.appendChild(host);
    host.appendChild(S.todoTab === 'pills' ? weekPillsCard()
                   : (S.todoTab === 'study' ? weekStudyCard() : weekTodoList()));

    var learned = el('div', 'pl-learned');
    learned.appendChild(el('div', 'pl-label', T('planner.cosa_ho_imparato')));
    var ta = el('textarea');
    ta.placeholder = T('planner.questa_settimana_mi_ha_insegnato');
    ta.value = S.week.learned || '';
    ta.maxLength = 4000;
    ta.addEventListener('input', function () {
        S.week.learned = ta.value;
        debounceSave('learned', 900, function () {
            api('week_save', { week_start: rg.from, learned: ta.value });
        });
    });
    learned.appendChild(ta);
    host.appendChild(learned);
}

/* ------------------------------------------------------------------ */
/* Da studiare: quello che c'è in programma nel giorno a fuoco          */
/* ------------------------------------------------------------------ */
/* La linguetta legge da study_api.php, non da `todos`: il to study non è più
   una lista di attività da quando è diventato un calendario con dentro blocchi
   e argomenti, e la vecchia lista è stata svuotata. Rifare qui il conto di
   quale argomento tocca oggi vorrebbe dire avere due pagine che possono non
   essere d'accordo su cosa c'è da studiare — lo stesso errore già fatto una
   volta con le prese delle pastiglie.
   Un giorno solo, quello a fuoco, come le pastiglie: un piano di studio di
   sette giorni in un pannello laterale sono trenta righe di cui ventotto non
   riguardano oggi. */
function loadStudy(date, force) {
    date = date || S.focusDate;
    if (S.study && S.study.date === date && !force) return Promise.resolve();
    return fetch('study_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'st_ajax=oggi'
    }).then(function (r) { return r.json(); }).then(function (d) {
        S.study = { date: date, ok: !!(d && d.ok), blocchi: (d && d.blocchi) || [] };
        renderWeekPanel();
    }).catch(function () {
        S.study = { date: date, ok: false, blocchi: [] };
        renderWeekPanel();
    });
}

function weekStudyCard() {
    var card = el('div', 'pl-list-card');
    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', LIST_LABEL['study'] || 'study'));
    var link = el('a', 'pl-wl-link', T('planner.apri_2'));
    link.href = 'to_study.php';
    head.appendChild(link);
    card.appendChild(head);

    if (!S.study || S.study.date !== S.focusDate) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.un_momento')));
        loadStudy(S.focusDate);
        return card;
    }
    if (!S.study.ok) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.study_non_disponibile')));
        return card;
    }
    if (!S.study.blocchi.length) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.study_niente_oggi')));
        return card;
    }

    /* Si mostra il blocco, e sotto i suoi argomenti di oggi se ne ha.
       Un blocco senza argomenti non è un errore né una riga vuota: è la forma
       normale di un blocco appena creato, ed è comunque quello che c'è da
       studiare oggi. Prima si leggevano solo gli argomenti, e in quel caso il
       pannello diceva «niente da studiare» con mezzo mese di blocchi attivi. */
    var box = el('div', 'pl-list-items');
    S.study.blocchi.forEach(function (b) {
        var titolo = el('div', 'pl-study-blocco' + (b.done ? ' pl-done' : ''));
        titolo.style.setProperty('--ev', b.color || '#B7AEA3');
        titolo.appendChild(el('span', 'pl-study-nome', b.titolo));
        if (b.materia && b.materia !== b.titolo) {
            titolo.appendChild(el('span', 'pl-study-mat', b.materia));
        }
        box.appendChild(titolo);
        (b.argomenti || []).forEach(function (a) { box.appendChild(studyRow(a)); });
    });
    card.appendChild(box);
    return card;
}

function studyRow(a) {
    var row = el('div', 'pl-li pl-study-li');
    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    row.appendChild(box);

    row.appendChild(el('span', 'pl-li-txt', a.name || T('planner.senza_titolo_l')));

    /* I pomodori fatti su quell'argomento, e la stima se c'è: è il numero che
       dice se si è dentro o fuori, e averlo qui evita di aprire un'altra
       pagina per saperlo. */
    if (a.fatti || a.stimati) {
        row.appendChild(el('span', 'pl-li-when',
            (a.fatti || 0) + (a.stimati ? '/' + a.stimati : '') + '\uD83C\uDF45'));
    }

    box.addEventListener('click', function (e) {
        e.stopPropagation();
        row.classList.add('pl-done');
        haptic(8);
        fetch('study_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'st_ajax=toggle&id=' + encodeURIComponent(a.id) + '&done=1'
        }).then(function () { loadStudy(S.focusDate, true); });
    });
    return row;
}

function weekTodoList() {
    var rg = weekTodoRange();
    var tipo = S.todoTab;
    var card = el('div', 'pl-list-card');

    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', LIST_LABEL[tipo] || tipo));
    var link = el('a', 'pl-wl-link', T('planner.apri_2'));
    /* la linguetta viaggia con il collegamento: si sta guardando questo
       elenco, e aprirne un altro sarebbe un salto che non ha chiesto nessuno */
    link.href = 'todo.php?tab=' + encodeURIComponent(tipo);
    head.appendChild(link);
    card.appendChild(head);

    var tutti = (S.todos && S.todos[tipo]) || null;
    if (tutti === null) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.un_momento')));
        return card;
    }
    /* le sotto-attività seguono la principale nella pagina attività: qui
       sarebbero righe orfane, senza il loro contesto */
    var roots = tutti.filter(function (t) { return !t.parent_id; });

    var dentro = roots.filter(function (t) { return t.date && t.date >= rg.from && t.date <= rg.to; });
    dentro.sort(function (a, b) {
        /* Nel "to buy" comanda la priorità: la spesa non si fa in ordine di
           data, si fa cominciando da quello che serve davvero. Negli altri
           elenchi la data resta la chiave, perché lì è una scadenza. */
        if (tipo === 'buy') {
            var pa = a.priority || 3, pb = b.priority || 3;
            if (pa !== pb) return pa - pb;
        }
        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
        return (a.time || '99:99') < (b.time || '99:99') ? -1 : 1;
    });

    var box = el('div', 'pl-list-items');
    if (!dentro.length) {
        box.appendChild(el('div', 'pl-wl-empty', T('planner.niente_in_questa_settimana')));
    } else {
        dentro.forEach(function (t) { box.appendChild(weekTodoRow(t, false)); });
    }
    card.appendChild(box);

    /* aggiungerne una qui dà per scontata la data mostrata: è il motivo per
       cui si sta guardando questa settimana e non un'altra */
    var add = el('button', 'pl-wl-add');
    add.type = 'button';
    add.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    add.appendChild(el('span', null, T('planner.aggiungi_al', {giorno: parseIso(S.focusDate).getDate(), mese: minuscolo(MESI[parseIso(S.focusDate).getMonth()])})));
    add.addEventListener('click', function () {
        todoApi('save', {
            id: 0, title: ' ', list_type: tipo, parent_id: 0, project_id: 0,
            date: S.focusDate, time: '', minutes: 30, source: 'page'
        }).then(function (r) {
            if (!r.ok) return;
            loadWeekTodos(tipo, true).then(function () {
                /* il campo nuovo è quello rimasto vuoto: ci si scrive dentro
                   senza doverlo cercare */
                var ins = $('plWeekPanelBody').querySelectorAll('.pl-li-in');
                for (var i = 0; i < ins.length; i++) {
                    if (!ins[i].value.trim()) { ins[i].focus(); break; }
                }
            });
            loadWeek(monday(S.focusDate), true);
        });
    });

    /* il resto sta sotto: prima quelle rimaste indietro, che si vedono
       comunque, poi il tasto per aggiungerne una e infine le due che si
       chiedono — senza data e più avanti */
    var senza  = roots.filter(function (t) { return !t.date; });
    var dopo   = roots.filter(function (t) { return t.date && t.date > rg.to; });
    var prima  = roots.filter(function (t) { return t.date && t.date < rg.from; });
    dopo.sort(function (a, b) { return a.date < b.date ? -1 : 1; });
    prima.sort(function (a, b) { return a.date < b.date ? -1 : 1; });

    /* Le rimaste indietro non stanno più sotto «mostra anche gli altri»: sono
       l'unica cosa dell'elenco su cui bisogna decidere qualcosa oggi, e
       tenerle dietro a un tasto voleva dire non vederle mai. Le altre due —
       senza data e più avanti — restano nascoste: quelle si vanno a cercare
       quando si ha voglia di tirarle dentro la settimana, non prima. */
    if (prima.length) {
        card.appendChild(el('div', 'pl-label pl-wl-lasect',
            prima.length === 1 ? T('planner.rimasta_indietro') : T('planner.rimaste_indietro')));
        var vecchie = el('div', 'pl-list-items');
        prima.forEach(function (t) { vecchie.appendChild(weekTodoRow(t, true)); });
        card.appendChild(vecchie);
    }
    card.appendChild(add);

    if (senza.length || dopo.length) {
        var more = el('button', 'pl-wl-more', S.todoOthers ? T('planner.nascondi_altri') : T('planner.mostra_anche_gli_altri'));
        more.type = 'button';
        more.addEventListener('click', function () {
            S.todoOthers = !S.todoOthers;
            renderWeekPanel();
        });
        card.appendChild(more);

        if (S.todoOthers) {
            var altri = el('div', 'pl-wl-others');
            function gruppo(label, list) {
                if (!list.length) return;
                altri.appendChild(el('div', 'pl-wl-glab', label));
                list.forEach(function (t) { altri.appendChild(weekTodoRow(t, true)); });
            }
            gruppo(T('planner.senza_data'), senza);
            gruppo(T('planner.piu_avanti'), dopo);
            card.appendChild(altri);
        }
    }
    return card;
}

/* ------------------------------------------------------------------ */
/* Pastiglie: le prese del giorno                                      */
/* ------------------------------------------------------------------ */
/* Le prese si leggono e si spuntano da todo_api.php, che è già l'unico posto
   che le scrive e l'unico che sa quando una terapia tocca un giorno (giorni
   della settimana, giorni del mese, ogni N giorni, orari e quante volte per
   orario). Rifare quel conto qui vorrebbe dire avere due calendari delle
   prese che possono non essere d'accordo, ed è esattamente l'errore che il
   pannello settimana ha già fatto una volta con gli elenchi.

   A differenza delle altre tre linguette qui si mostra UN GIORNO, quello a
   fuoco, e non la settimana: una presa la si spunta il giorno stesso, e sette
   giorni di prese sarebbero venti righe di cui diciotto non toccabili.
   L'endpoint stesso è fatto per un giorno solo — è lui che crea le prese
   mancanti di quella data — e chiedergliene sette vorrebbe dire scrivere in
   archivio una settimana di prese solo per aver aperto un pannello. */
function loadWeekPills(date, force, zitta) {
    date = date || S.focusDate;
    if (S.pills && S.pills.date === date && !force) return Promise.resolve();
    return todoApi('pills', null, { date: date }).then(function (r) {
        if (!r || !r.ok) return;
        S.pills = {
            date: date,
            installed: r.installed !== false,
            doses: r.doses || [],
            /* le arretrate non appartengono al giorno chiesto: sono di ieri
               comunque, e il server le manda sempre le stesse */
            late: r.late || [],
            stats: r.stats || null
        };
        /* `zitta` = aggiorna i dati ma non ridisegnare. Serve dopo che una
           riga è già uscita da sola con la sua animazione: ridisegnare lì
           vorrebbe dire rifare da capo l'elenco che l'utente sta guardando,
           cioè annullare l'animazione appena finita. */
        if (zitta) return;
        renderWeekPanel();
    });
}

function weekPillsCard() {
    var date = S.focusDate;
    var card = el('div', 'pl-list-card');

    var head = el('div', 'pl-list-head');
    head.appendChild(el('div', 'pl-list-title pl-serif', T('planner.pastiglie')));
    var link = el('a', 'pl-wl-link', T('planner.apri_2'));
    link.href = 'todo.php?tab=pills';
    head.appendChild(link);
    card.appendChild(head);

    /* Il giorno scritto per esteso: le altre linguette parlano di tutta la
       settimana, e senza dirlo qui si crederebbe di vedere le prese di
       tutt'e sette. */
    var d = parseIso(date);
    var rigaGiorno = el('div', 'pl-pill-dayrow');
    rigaGiorno.appendChild(el('div', 'pl-pill-day', T('planner.prese_di', {quando: (date === S.today ? T('comune.oggi').toLowerCase() : minuscolo(GIORNI[dowIndex(date)])), giorno: d.getDate(), mese: minuscolo(MESI[d.getMonth()])})));
    card.appendChild(rigaGiorno);

    if (!S.pills || S.pills.date !== date) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.un_momento')));
        /* il giorno a fuoco può essere cambiato mentre il pannello era
           aperto: si chiede quello giusto invece di mostrare l'altro */
        loadWeekPills(date);
        return card;
    }
    if (!S.pills.installed) {
        card.appendChild(el('div', 'pl-wl-empty', T('planner.le_pastiglie_non_sono_installate')));
        return card;
    }

    /* --- quelle rimaste indietro ---
       Stanno anche qui e non solo nella pagina attività: se il planner mostra
       le pastiglie, deve mostrare per prima l'unica su cui si può ancora fare
       qualcosa. Non dipendono dal giorno che si sta guardando — sono di ieri
       comunque — quindi compaiono anche sfogliando la settimana. */
    var late = S.pills.late || [];
    if (late.length) {
        var testaLate = el('div', 'pl-pill-sectrow');
        testaLate.appendChild(el('div', 'pl-label pl-pill-lasect',
            late.length === 1 ? T('planner.rimasta_indietro') : T('planner.rimaste_indietro')));
        if (late.length > 1) testaLate.appendChild(tastoPreseTutte(late, true));
        card.appendChild(testaLate);
        var lbox = el('div', 'pl-list-items');
        late.forEach(function (p) { lbox.appendChild(pillRow(p, T('planner.arretrata'))); });
        card.appendChild(lbox);
    }

    var box = el('div', 'pl-list-items');
    if (!S.pills.doses.length) {
        box.appendChild(el('div', 'pl-wl-empty', T('planner.nessuna_presa_prevista')));
    } else {
        /* Le prese si spuntano il giorno stesso: su un giorno futuro le
           caselle restano da guardare e non da toccare. Segnare oggi una
           pastiglia di dopodomani non vuol dire niente, e l'ora della presa
           che ne uscirebbe sarebbe una data inventata. */
        var futuro = date > S.today;
        S.pills.doses.forEach(function (p) { box.appendChild(pillRow(p, futuro ? T('planner.futura') : null)); });
        /* «prese tutte» quando ce n'è più di una da spuntare; su un giorno
           passato chiede a che ora, su oggi vale adesso */
        var daPrendere = S.pills.doses.filter(function (p) { return p.status !== 'taken'; });
        if (!futuro && daPrendere.length > 1) rigaGiorno.appendChild(tastoPreseTutte(daPrendere, date < S.today));
        if (futuro) {
            box.appendChild(el('div', 'pl-row-sub', T('planner.si_spuntano_il_giorno_stesso')));
        }
    }
    card.appendChild(box);

    /* Quante ne sono state saltate o dimenticate negli ultimi trenta giorni:
       è il numero per cui vale la pena tenere le prese una per una, e in
       fondo all'elenco non ruba spazio a quelle di oggi. */
    var st = S.pills.stats;
    if (st && (st.taken || st.skipped || st.missed)) {
        card.appendChild(el('div', 'pl-pill-stats',
            T('planner.ultimi30_prese', {n: st.taken})
            + (st.skipped ? T('planner.ultimi30_saltate', {n: st.skipped}) : '')
            + (st.missed ? T('planner.ultimi30_dimenticate', {n: st.missed}) : '')));
    }
    return card;
}

/* Una presa. Tre casi, e il modo che è che cambia:

   - OGGI: solo la casella. La crocetta che c'era prima non serviva a niente
     — quello che segnava lo si toglieva già togliendo il flag — e in una
     riga stretta un tastino che non aggiunge niente è solo un modo in più
     per sbagliare.
   - GIORNO FUTURO: niente da toccare. Spuntare oggi la pastiglia di
     dopodomani non vuol dire nulla, e l'ora della presa che ne verrebbe
     fuori sarebbe inventata.
   - ARRETRATA: casella *e* crocetta, e qui la crocetta serve davvero. La
     casella dice «l'ho presa» e chiede quando, perché su una presa di ieri
     l'orologio di adesso sbaglierebbe di sicuro; la crocetta dice «non l'ho
     presa» e la toglie dall'elenco, se no resterebbe lì per sempre. */
/* Fa uscire una riga di lato e richiude il buco che lascia.
   Due tempi e non uno: prima scivola via — l'altezza resta, così quelle sotto
   stanno ferme e si vede DOVE se ne sta andando — e solo dopo l'altezza va a
   zero e le altre scorrono a chiudere. Facendo le due cose insieme la riga
   sembrava risucchiata invece che tolta, e non si capiva quale fosse.
   `verso` è +1 o -1: fatta esce a destra, saltata a sinistra. Sono due
   risposte diverse e vanno in due direzioni diverse. */
function sfilaRiga(row, verso, poi) {
    var h = row.offsetHeight;
    row.style.height = h + 'px';
    row.style.overflow = 'hidden';
    row.style.pointerEvents = 'none';
    // il fotogramma dopo, o il browser non ha ancora un'altezza da cui partire
    requestAnimationFrame(function () {
        row.classList.add('pl-pill-via');
        row.style.transform = 'translateX(' + (verso * 108) + '%)';
        row.style.opacity = '0';
        setTimeout(function () {
            row.style.height = '0px';
            row.style.marginTop = '0px';
            row.style.marginBottom = '0px';
            row.style.paddingTop = '0px';
            row.style.paddingBottom = '0px';
            setTimeout(function () {
                if (row.parentNode) row.parentNode.removeChild(row);
                if (poi) poi();
            }, 260);
        }, 230);
    });
}

function pillRow(p, modo) {
    var arretrata = modo === T('planner.arretrata');
    var futura = modo === T('planner.futura');
    var row = el('div', 'pl-li pl-pill'
        + (p.status === 'taken' ? ' pl-done' : '')
        + (p.status === 'skipped' ? ' pl-pill-skip' : '')
        + (arretrata ? ' pl-pill-late' : '')
        + (futura ? ' pl-pill-futura' : ''));

    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    row.appendChild(box);

    row.appendChild(el('span', 'pl-pill-h', p.time || ''));

    var pallino = el('span', 'pl-pill-dot');
    pallino.style.setProperty('--ev', p.color || 'var(--accent)');
    row.appendChild(pallino);

    var txt = el('div', 'pl-pill-txt');
    /* di che giorno parliamo: tre righe uguali di tre giorni diversi, senza,
       non si distinguono */
    if (arretrata && p.date) txt.appendChild(el('div', 'pl-pill-quando', pillGiornoBreve(p.date)));
    txt.appendChild(el('div', 'pl-pill-name', p.name || T('planner.senza_nome')));
    /* dose e avvertenze sulla stessa riga: sono la cosa che si dimentica
       ("mezza compressa", "a stomaco pieno") e sta bene sotto il nome */
    var sotto = [p.dose, p.notes].filter(Boolean).join(' · ');
    if (sotto) txt.appendChild(el('div', 'pl-pill-dose', sotto));
    row.appendChild(txt);

    if (futura) return row;   // da guardare, non da toccare

    function segna(stato, quando) {
        p.status = stato;
        row.classList.toggle('pl-done', stato === 'taken');
        row.classList.toggle('pl-pill-skip', stato === 'skipped');
        haptic(8);
        var dati = { id: p.id, status: stato };
        if (stato === 'taken' && quando) {
            dati.at_date = quando.slice(0, 10);
            dati.at_time = quando.slice(11, 16);
        }
        /* si segna subito e si avvisa il server dopo: la spunta di una
           pastiglia deve sembrare immediata come tutte le altre */
        todoApi('pill_mark', dati);
        /* Un'arretrata sistemata non è più arretrata: se ne va scorrendo di
           lato mentre le altre si richiudono al suo posto.
           Prima si aspettavano 400ms e si ridisegnava tutto l'elenco: la riga
           spariva di colpo insieme a tutte le altre, che ricomparivano
           ricostruite da capo — da fuori era uno sfarfallio, e su un elenco
           lungo si perdeva anche il punto in cui si stava guardando.
           I dati si aggiornano qui in memoria e la rilettura dal server si fa
           dopo, in silenzio: serve solo a rimettere in riga i conti in fondo,
           non a ridisegnare quello che l'utente sta già vedendo. */
        if (arretrata) {
            if (S.pills && S.pills.late) {
                S.pills.late = S.pills.late.filter(function (x) { return x.id !== p.id; });
            }
            sfilaRiga(row, stato === 'skipped' ? -1 : 1, function () {
                loadWeekPills(S.focusDate, true, true);
            });
        }
    }

    box.addEventListener('click', function () {
        if (p.status === 'taken') { segna('pending'); return; }
        if (arretrata) { pillQuandoPresa(p, function (q) { segna('taken', q); }); return; }
        segna('taken');
    });

    if (arretrata) {
        /* La parola al posto della crocetta. Una x si legge come «togli
           questa riga», e non è quello che succede: qui si sta dicendo una
           cosa precisa — quella pastiglia non è stata presa e non lo sarà —
           che il conto degli ultimi trenta giorni tiene da parte, saltate da
           una parte e dimenticate dall'altra. Con la crocetta bisognava
           saperlo; con la parola no. */
        var salta = el('button', 'pl-pill-salta' + (p.status === 'skipped' ? ' pl-on' : ''), T('planner.saltata'));
        salta.type = 'button';
        salta.setAttribute('aria-label', T('planner.segna_come_saltata'));
        salta.title = T('planner.non_l_ho_presa_e');
        salta.addEventListener('click', function (e) {
            e.stopPropagation();
            var ora = p.status === 'skipped' ? 'pending' : 'skipped';
            salta.classList.toggle('pl-on', ora === 'skipped');
            segna(ora);
        });
        row.appendChild(salta);
    }
    return row;
}

/* Il tastino «prese tutte».
   Sulle prese dei giorni scorsi chiede a che ora scriverle: quasi sempre le
   si è prese all'ora giusta e ci si è scordati di spuntarle, e allora
   l'orario previsto è quello vero; «tutte adesso» resta per chi le prende
   in quel momento. Su oggi non chiede niente: vale l'orologio, come per la
   spunta di una sola. */
function tastoPreseTutte(lista, passate) {
    var b = el('button', 'pl-pill-tutte', T('planner.prese_tutte'));
    b.type = 'button';
    b.setAttribute('aria-label', T('planner.prese_tutte_aria'));
    b.addEventListener('click', function (e) {
        e.stopPropagation();
        var ids = lista.filter(function (p) { return p.status !== 'taken'; }).map(function (p) { return p.id; });
        if (!ids.length) return;
        if (!passate) { preseTutte(ids, 'now'); return; }
        alertBox(T('planner.quando_le_hai_prese'),
            ids.length === 1 ? T('planner.prese_passate_1') : T('planner.prese_passate_n', { n: ids.length }), [
            { label: T('planner.all_orario_previsto'), kind: 'primary', onClick: function () { preseTutte(ids, 'on_time'); } },
            { label: T('planner.tutte_adesso'), onClick: function () { preseTutte(ids, 'now'); } },
            { label: T('planner.annulla') }
        ]);
    });
    return b;
}

/* Si spuntano subito sullo schermo, poi parte la chiamata; la rilettura in
   silenzio rimette in riga i conti in fondo. */
function preseTutte(ids, mode) {
    var set = {};
    ids.forEach(function (id) { set[id] = true; });
    if (S.pills) {
        (S.pills.doses || []).forEach(function (p) { if (set[p.id]) p.status = 'taken'; });
        S.pills.late = (S.pills.late || []).filter(function (p) { return !set[p.id]; });
    }
    haptic(14);
    renderWeekPanel();
    toast(T('planner.segnate_prese_n', { n: ids.length }));
    todoApi('pill_mark_all', { ids: ids.join(','), mode: mode }).then(function () {
        loadWeekPills(S.focusDate, true);
        S.pillsDue = (S.pillsDue || []).filter(function (x) { return !set[x.id]; });
        renderAvvisi();
    });
}

/* «ieri» quando è ieri, se no «lun 3». Il nome del giorno da solo, su una
   cosa passata, si legge come il giorno che deve venire. */
function pillGiornoBreve(iso) {
    if (iso === addDays(S.today, -1)) return T('comune.ieri').toLowerCase();
    var d = parseIso(iso);
    return GIORNI[dowIndex(iso)].slice(0, 3) + ' ' + d.getDate();
}

/* Quando l'hai presa davvero. Si chiede solo sulle arretrate: su quelle di
   oggi l'orologio va già bene, e una domanda in più a ogni pastiglia sarebbe
   un pedaggio su un gesto che si fa tre volte al giorno.
   Si parte dall'ora prevista e non da adesso: quasi sempre la pastiglia è
   stata presa lì o poco dopo, e così spesso basta confermare. */
function pillQuandoPresa(p, poi) {
    /* Nasce da dentro il pannello della settimana, che sta più in alto di
       tutti i fogli normali: senza la classe in più finiva sotto la barra
       delle linguette, e si vedeva spuntare a metà. */
    var ov = el('div', 'pl-ov pl-ov-sopra');
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));
    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', T('planner.quando_l_hai_presa')));
    var no = el('button', 'pl-txt-btn', T('planner.annulla'));
    no.type = 'button';
    no.addEventListener('click', function () { ov.remove(); });
    head.appendChild(no);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');
    body.appendChild(el('div', 'pl-row-sub',
        T('planner.pastiglia_di', {nome: (p.name || T('planner.la_pastiglia_l')), quando: pillGiornoBreve(p.date || S.today)})));

    /* Il giorno c'è perché una presa di lunedì spuntata mercoledì appartiene
       a lunedì: scriverla su mercoledì sposterebbe il buco invece di
       chiuderlo. */
    var iData = el('input', 'pl-pick');
    iData.type = 'date';
    iData.value = p.date || S.today;
    body.appendChild(rowSB(T('planner.giorno_t'), iData));

    var iOra = el('input', 'pl-pick');
    iOra.type = 'time'; iOra.step = '60';
    iOra.value = p.time || '08:00';
    body.appendChild(rowSB(T('planner.ora_t'), iOra));

    var ok = el('button', 'pl-btn pl-btn-primary pl-pill-ok', T('planner.segna_come_presa'));
    ok.type = 'button';
    ok.addEventListener('click', function () {
        var g = iData.value || p.date || S.today;
        var o = (iOra.value || p.time || '08:00').slice(0, 5);
        ov.remove();
        poi(g + ' ' + o + ':00');
    });
    body.appendChild(ok);

    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
}

/* Che cosa scrivere nel tastino «quando» di una voce.

   L'ora, da sola, vale solo per oggi: su una cosa di giovedì un «18:30»
   secco fa credere che sia per stasera, ed è l'equivoco che costa di più
   perché ci si accorge solo dopo. Quindi: oggi l'ora, gli altri giorni il
   giorno.

   Entro una settimana il nome del giorno dice tutto e si legge di colpo
   («mer» è più immediato di «6 ago»); oltre, il nome torna ambiguo — fra
   sei settimane è comunque un mercoledì — e allora ci vuole il numero.
   All'indietro il numero sempre: «mar» su una cosa scaduta si leggerebbe
   come il martedì che deve venire. */
function todoQuandoTesto(t) {
    if (!t.date) return t.time ? t.time : T('planner.quando_l');
    var avanti = diffDays(S.today, t.date);
    if (avanti === 0) return t.time ? t.time : T('comune.oggi').toLowerCase();
    if (avanti > 0 && avanti <= 7) return GIORNI[dowIndex(t.date)].slice(0, 3);
    var d = parseIso(t.date);
    return d.getDate() + ' ' + MESI[d.getMonth()].slice(0, 3).toLowerCase();
}

/* Una riga. Dentro la settimana si spunta e si modifica; fuori si tocca e
   basta, perché lì il gesto ha un altro significato: prendere la voce e
   portarla al giorno che si sta guardando. */
function weekTodoRow(t, isOther) {
    /* Rosso su quelle dei giorni passati e non ancora spuntate: sono le
       stesse che il planner tira su in priorità, e qui dentro sembravano
       righe come tutte le altre — ci si passava sopra senza vederle. Solo il
       bordo e la data, come sulle pastiglie arretrate: un riquadro rosso
       pieno tutte le mattine diventa allarme di sottofondo e dopo tre giorni
       non lo si guarda più. */
    var tardi = !t.done && !!t.date && t.date < S.today;
    var row = el('div', 'pl-li' + (t.done ? ' pl-done' : '') + (isOther ? ' pl-li-other' : '')
        + (tardi ? ' pl-li-late' : ''));

    var box = el('span', 'pl-li-box');
    box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    row.appendChild(box);

    if (isOther) {
        row.appendChild(el('span', 'pl-li-txt', t.title || T('planner.senza_titolo_l')));
        if (t.date) row.appendChild(el('span', 'pl-li-when', todoQuandoTesto(t)));

        /* La casella spunta, il resto della riga propone di spostarla.
           Prima il tocco su tutta la riga — casella compresa — apriva la
           domanda «lascio o sposto», e una cosa rimasta indietro non si poteva
           dichiarare fatta in nessun modo: si finiva sempre a spostarla a un
           altro giorno per poi spuntarla lì. Ma il caso più frequente è
           l'opposto — l'hai già fatta, era solo segnata al giorno sbagliato. */
        box.addEventListener('click', function (e) {
            e.stopPropagation();
            t.done = !t.done;
            row.classList.toggle('pl-done', t.done);
            haptic(8);
            todoApi('toggle', { id: t.id }).then(function () {
                loadWeekTodos(S.todoTab, true);
                loadWeek(monday(S.focusDate), true);
            });
        });
        row.addEventListener('click', function () { takeIntoWeek(t); });
        return row;
    }

    var inp = el('input', 'pl-li-in');
    inp.type = 'text';
    inp.value = t.title || '';
    inp.placeholder = T('planner.aggiungi');
    inp.maxLength = 300;
    inp.addEventListener('change', function () { salvaTitolo(); });
    inp.addEventListener('blur', function () { salvaTitolo(); });
    function salvaTitolo() {
        if (inp.value === t.title) return;
        t.title = inp.value;
        todoApi('save', {
            id: t.id, title: inp.value, list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: t.date || '', time: t.time || '', minutes: t.minutes || 30, source: 'page'
        }).then(function () {
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    }
    row.appendChild(inp);

    /* La priorità nel "to buy": si legge e si cambia da qui, senza passare
       dalla pagina attività. Gira 1 → 2 → 3 → 1, come là: con tre soli valori
       un menù sarebbe più lavoro del giro. */
    if (S.todoTab === 'buy') {
        var prio = el('button', 'pl-li-prio pl-prio-' + (t.priority || 3));
        prio.type = 'button';
        prio.textContent = String(t.priority || 3);
        prio.setAttribute('aria-label', T('planner.priorita_n', {n: (t.priority || 3)}));
        prio.addEventListener('click', function (e) {
            e.stopPropagation();
            var next = (t.priority || 3) % 3 + 1;
            t.priority = next;
            prio.className = 'pl-li-prio pl-prio-' + next;
            prio.textContent = String(next);
            haptic(6);
            todoApi('save', {
                id: t.id, title: t.title || '', list_type: t.list_type || S.todoTab,
                parent_id: t.parent_id || 0, project_id: t.project_id || 0,
                date: t.date || '', time: t.time || '', minutes: t.minutes || 30,
                source: 'page', buy_set: ['priority'], priority: next
            }).then(function () { loadWeekTodos(S.todoTab, true); });
        });
        row.appendChild(prio);
    }

    /* Data e ora si toccano e si cambiano da qui: prima erano una scritta
       morta, e per spostare una voce di un giorno bisognava andare nella
       pagina attività. */
    var when = el('button', 'pl-li-when');
    when.type = 'button';
    when.textContent = todoQuandoTesto(t);
    when.addEventListener('click', function (e) { e.stopPropagation(); todoQuando(t); });
    row.appendChild(when);

    if (S.todoTab === 'buy' && t.cents !== null && t.cents !== undefined) {
        row.appendChild(el('span', 'pl-li-price', (t.cents / 100).toFixed(2).replace('.', ',') + ' €'));
    }

    box.addEventListener('click', function () {
        t.done = !t.done;
        row.classList.toggle('pl-done', t.done);
        haptic(8);
        /* Il blocco sul calendario si sbarra subito, senza aspettare la
           rilettura: la stessa cosa vive in due posti, e vederla spuntata di
           qua e intatta di là per mezzo secondo fa dubitare che il tocco sia
           arrivato. La rilettura poi conferma. */
        if (t.event_id) {
            Object.keys(S.events).forEach(function (g) {
                (S.events[g] || []).forEach(function (x) {
                    if (x.id === t.event_id) x.done = !t.done;
                });
            });
            renderDays();
        }
        todoApi('toggle', { id: t.id }).then(function () {
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    });

    /* Il cestino sta sotto la riga e si scopre scorrendola, come nella pagina
       attività: stesso gesto nei due posti, così non c'è niente da imparare
       due volte. Su computer lo scorrimento con il mouse funziona lo stesso,
       ma i campi di testo restano esclusi — lì trascinare vuol dire
       selezionare, non spostare. */
    var wrap = el('div', 'pl-li-wrap');
    var trash = el('button', 'pl-li-trash');
    trash.type = 'button';
    trash.setAttribute('aria-label', T('planner.elimina'));
    trash.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    trash.addEventListener('click', function () {
        alertBox(T('planner.elimino'), '«' + (t.title || T('planner.senza_titolo_l')) + T('planner.sparisce_elenchi'), [
            { label: T('planner.lascia_stare') },
            { label: T('planner.elimina'), kind: 'danger', onClick: function () {
                todoApi('delete', { id: t.id }).then(function () {
                    loadWeekTodos(S.todoTab, true);
                    loadWeek(monday(S.focusDate), true);
                });
            } }
        ]);
    });
    wrap.appendChild(trash);
    wrap.appendChild(row);
    abilitaSwipeLi(wrap, row);
    return wrap;
}

/* Scorrendo la riga verso destra spunta il cestino; scorrendo indietro, o
   toccando un'altra riga, si richiude. */
function abilitaSwipeLi(wrap, row) {
    var x0 = null, y0 = 0, pid = null, daCampo = false;
    function chiudiAltre() {
        var aperte = document.querySelectorAll('.pl-li-wrap.pl-swiped');
        for (var i = 0; i < aperte.length; i++) if (aperte[i] !== wrap) aperte[i].classList.remove('pl-swiped');
    }
    row.addEventListener('pointerdown', function (e) {
        daCampo = !!(e.target.closest && e.target.closest('input, textarea'));
        if (daCampo && e.pointerType === 'mouse') { x0 = null; return; }
        x0 = e.clientX; y0 = e.clientY; pid = e.pointerId;
    });
    row.addEventListener('pointermove', function (e) {
        if (x0 === null || e.pointerId !== pid) return;
        var dx = e.clientX - x0, dy = e.clientY - y0;
        /* orizzontale dichiarato: scorrendo il pannello in verticale con il
           dito appoggiato su una riga, il cestino non deve spuntare */
        if (Math.abs(dx) < Math.abs(dy) * 1.3) return;
        var soglia = daCampo ? 26 : 14;
        if (dx > soglia) { chiudiAltre(); wrap.classList.add('pl-swiped'); }
        if (dx < -soglia) wrap.classList.remove('pl-swiped');
    });
    function stop() { x0 = null; pid = null; }
    row.addEventListener('pointerup', stop);
    row.addEventListener('pointercancel', stop);
    row.addEventListener('pointerleave', stop);
}

/* Data, ora e durata di un'attività, cambiate da dove la si sta guardando.
   L'ora vuota vuol dire "in giornata": è la stessa convenzione del
   database (due_time NULL) e della pagina attività. */
function todoQuando(t) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = t.title || T('planner.senza_titolo');
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');

    function riga(lab, campo) {
        var r = el('div', 'pl-row-sb');
        r.appendChild(el('span', 'pl-row-lab', lab));
        r.appendChild(campo);
        wrap.appendChild(r);
    }
    var dIn = el('input', 'pl-pick'); dIn.type = 'date'; dIn.value = t.date || S.focusDate;
    var oIn = el('input', 'pl-pick'); oIn.type = 'time'; oIn.step = '300'; oIn.value = t.time || '';
    var mIn = el('input', 'pl-pick');
    mIn.type = 'number'; mIn.min = '5'; mIn.max = '1440'; mIn.step = '5';
    mIn.value = String(t.minutes || 30);
    riga(T('planner.data_t'), dIn);
    riga(T('planner.ora_t'), oIn);
    riga(T('planner.durata_minuti'), mIn);
    /* senza orario l'attività vale per tutto il giorno: serve un modo per
       tornare indietro, perché un campo orario svuotato a mano non sempre
       si lascia svuotare */
    var senza = el('button', 'pl-linkish', T('planner.togli_l_orario'));
    senza.type = 'button';
    senza.addEventListener('click', function () { oIn.value = ''; });
    wrap.appendChild(senza);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', T('planner.annulla')); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var si = el('button', 'pl-btn pl-btn-primary', T('planner.salva')); si.type = 'button';
    si.addEventListener('click', function () {
        ov.classList.remove('pl-open');
        t.date = dIn.value || t.date;
        t.time = oIn.value || null;
        t.minutes = Math.max(5, Math.min(1440, parseInt(mIn.value, 10) || 30));
        todoApi('save', {
            id: t.id, title: t.title || '', list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: t.date || '', time: t.time || '', minutes: t.minutes, source: 'page'
        }).then(function (r) {
            if (!r.ok) { toast(T('planner.non_e_stato_possibile_salvare')); return; }
            loadWeekTodos(S.todoTab, true);
            /* la stessa attività è anche un evento in agenda: il planner va
               riletto, se no la riga si sposta e il blocco resta dov'era */
            loadWeek(monday(S.focusDate), true);
        });
    });
    acts.appendChild(no); acts.appendChild(si);
    ov.classList.add('pl-open');
}

/* Portare una voce nella settimana. Se una data ce l'aveva già si chiede
   conferma: spostarla può voler dire mancare una scadenza, e un tocco preso
   per sbaglio non deve poterlo fare in silenzio. */
function takeIntoWeek(t) {
    function applica() {
        todoApi('save', {
            id: t.id, title: t.title, list_type: t.list_type || S.todoTab,
            parent_id: t.parent_id || 0, project_id: t.project_id || 0,
            date: S.focusDate, time: t.time || '', minutes: t.minutes || 30, source: 'page'
        }).then(function (r) {
            if (!r.ok) return;
            loadWeekTodos(S.todoTab, true);
            loadWeek(monday(S.focusDate), true);
        });
    }
    if (!t.date) { applica(); return; }
    var d = parseIso(t.date), n = parseIso(S.focusDate);
    alertBox(T('planner.cambio_la_data'), T('planner.e_al_passa_al', {titolo: (t.title || T('planner.senza_titolo')), g1: d.getDate(), m1: minuscolo(MESI[d.getMonth()]), g2: n.getDate(), m2: minuscolo(MESI[n.getMonth()])}), [
        { label: T('planner.lascia_stare') },
        { label: T('planner.sposta'), kind: 'primary', onClick: applica }
    ]);
}

/* il pulsantino a sinistra di "Priorità" si accende se la settimana è compilata */
function updateWeekPanelBtn() {
    var btn = $('plWeekPanelBtn');
    if (!btn) return;
    var filled = !!(S.week.learned && S.week.learned.trim());
    if (!filled && S.todos) {
        var rg = weekTodoRange();
        for (var k in S.todos) if (S.todos.hasOwnProperty(k)) {
            var hit = (S.todos[k] || []).some(function (t) {
                return !t.parent_id && t.date && t.date >= rg.from && t.date <= rg.to;
            });
            if (hit) { filled = true; break; }
        }
    }
    btn.classList.toggle('pl-filled', filled);
}

function openWeekPanel() {
    /* la classe prima del disegno: renderWeekPanel non lavora a pannello
       chiuso, e verrebbe chiamato a vuoto */
    $('plWeekPanelOv').classList.add('pl-open');
    document.documentElement.classList.add('pl-locked');
    renderWeekPanel();
    if (S.todoTab === 'pills') loadWeekPills(S.focusDate, true);
    else loadWeekTodos(S.todoTab, true);
}
function closeWeekPanel() {
    $('plWeekPanelOv').classList.remove('pl-open');
    if (!document.querySelector('.pl-ov.pl-open')) document.documentElement.classList.remove('pl-locked');
    /* il tocco che l'ha chiuso finisce qui e non prosegue verso la griglia */
    assorbiDopoChiusura();
}

/* ------------------------------------------------------------------ */
/* Anello delle abitudini                                              */
/* ------------------------------------------------------------------ */
/* Gli anelli concentrici delle abitudini.

   Uno per famiglia di frequenza, e solo per le famiglie che hanno almeno
   un'abitudine: il più interno è il giornaliero, poi andando verso l'esterno
   settimanale, mensile, personalizzate. Il conto arriva già fatto dal server
   (pl_rings): da qui non si potrebbe sapere quante volte una mensile è stata
   fatta nel mese, perché al client arrivano solo le abitudini del giorno
   mostrato.

   Gli anelli sono attaccati: ognuno occupa una corsia dello stesso spessore,
   separata dalla successiva da un filo sottilissimo. Il numero di anelli
   cambia da utente a utente, quindi il disegno si costruisce qui invece di
   stare fisso nel markup. */
var RING_LARGHEZZA = 3.4;    // spessore di ogni corsia
var RING_STACCO    = 0.9;    // il filo che divide una corsia dall'altra
var RING_ESTERNO   = 21;     // raggio della corsia più esterna

function renderRing() {
    var ring = $('plHabitRing');
    if (!ring) return;
    var date = S.focusDate;
    /* Il giorno chiesto viene appuntato qui: se nel frattempo se ne sfoglia un
       altro, la risposta che arriva tardi non ridisegna più l'anello del
       giorno sbagliato. */
    ring.dataset.want = date;

    /* L'animazione degli anelli racconta «è cambiato il giorno». Zoomando il
       giorno è lo stesso, e vederli ripartire da zero a ogni pizzico faceva
       sembrare che fosse successo qualcosa che non era successo. Se il giorno
       disegnato è già questo e i dati sono gli stessi, non si ridisegna. */
    var pronti = S.rings[date];
    if (pronti && ring.dataset.drawn === date && ring.querySelector('.pl-ring-fill')) return;

    if (pronti) { ring.dataset.drawn = date; disegnaAnelli(ring, pronti); return; }
    ring.dataset.drawn = '';

    /* Non si svuota più mentre si aspetta. Svuotare e ridisegnare era corretto
       — meglio niente che i dati di ieri — ma sfogliando i giorni produceva un
       lampo a ogni cambio: anelli via, mezzo secondo di buco, anelli di
       ritorno. Adesso restano quelli di prima, smorzati, così si vede che sono
       vecchi senza che sparisca tutto; e appena arrivano i nuovi tornano
       pieni. Se non c'era proprio niente, si parte da vuoto come prima. */
    if (!ring.querySelector('.pl-ring-fill')) disegnaAnelli(ring, null);
    ring.classList.add('pl-ring-attesa');
    loadRings(date).then(function (dati) {
        if (ring.dataset.want !== date) return;
        ring.dataset.drawn = date;
        ring.classList.remove('pl-ring-attesa');
        disegnaAnelli(ring, dati);
    });

    /* I due giorni accanto si preparano in anticipo. Sfogliare è il gesto più
       frequente del planner, e chiedere gli anelli solo quando il giorno è già
       a schermo vuol dire che il buco si vede sempre. Si chiedono a bassa
       priorità, quando il browser non ha altro da fare: se si sfoglia in
       fretta, la richiesta è già partita e la condivisione delle letture in
       volo evita di rifarla. */
    var vicini = [addDays(date, 1), addDays(date, -1)];
    var chiedi = function () {
        vicini.forEach(function (g) { if (!S.rings[g]) loadRings(g); });
    };
    if (window.requestIdleCallback) requestIdleCallback(chiedi, { timeout: 1200 });
    else setTimeout(chiedi, 400);
}

function loadRings(date) {
    if (S.rings[date]) return Promise.resolve(S.rings[date]);
    return api('habits_day', null, { date: date }).then(function (r) {
        S.habits[date] = r.ok ? r.habits : [];
        if (r.ok && r.weekly) S.weekly[monday(date)] = r.weekly;
        S.rings[date] = r.ok && r.rings ? r.rings : [];
        return S.rings[date];
    });
}

function disegnaAnelli(ring, dati) {
    var svg = ring.querySelector('svg');
    if (!svg) return;
    dati = dati || [];

    var pezzi = '';
    var offsetFinali = []; // Memorizziamo qui i valori finali

    for (var i = 0; i < dati.length; i++) {
        /* i = 0 è il più interno, quindi si parte da dentro e si esce */
        var r = RING_ESTERNO - (dati.length - 1 - i) * (RING_LARGHEZZA + RING_STACCO);
        if (r < 3) break;                       // troppe famiglie: non ci sta più
        var C = 2 * Math.PI * r;
        var frac = dati[i].total > 0 ? Math.min(1, dati[i].done / dati[i].total) : 0;
        
        // Salviamo l'offset che l'anello dovrà raggiungere alla fine
        offsetFinali.push((C * (1 - frac)).toFixed(1));

        // INSERIAMO L'ANELLO VUOTO: stroke-dashoffset uguale all'intera circonferenza (C)
        pezzi += '<circle class="pl-ring-track" cx="23" cy="23" r="' + r.toFixed(2)
              + '" stroke-width="' + RING_LARGHEZZA + '"/>'
              + '<circle class="pl-ring-fill pl-ring-' + dati[i].key + '" cx="23" cy="23" r="' + r.toFixed(2)
              + '" stroke-width="' + RING_LARGHEZZA + '"'
              + ' stroke-dasharray="' + C.toFixed(1) + '"'
              + ' stroke-dashoffset="' + C.toFixed(1) + '"/>'; 
    }
    
    svg.innerHTML = pezzi;

    /* Diamo il tempo al browser di disegnare gli anelli vuoti, poi applichiamo i valori 
       reali. Questo fa scattare la transizione CSS morbida e li vedi riempirsi. */
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            var cerchiAnimati = svg.querySelectorAll('.pl-ring-fill');
            for (var j = 0; j < cerchiAnimati.length; j++) {
                cerchiAnimati[j].style.strokeDashoffset = offsetFinali[j];
            }
        });
    });

    /* Quante corsie ci sono decide quanto spazio resta al centro: con quattro
       famiglie il foro interno scende a circa 13px, e la spunta da 17px ci
       finirebbe sopra. Il numero passa al CSS, che rimpicciolisce l'icona. */
    ring.dataset.n = dati.length;

    /* tutto completo: la spunta al centro si accende */
    var tutto = dati.length > 0 && dati.every(function (d) { return d.total > 0 && d.done >= d.total; });
    ring.classList.toggle('pl-ring-full', tutto);

    var descr = dati.map(function (d) { return T('planner.etichetta_n_su_n', {label: d.label, fatti: d.done, totale: d.total}); }).join(', ');
    ring.setAttribute('aria-label', descr || T('planner.nessuna_abitudine'));
}
