/* planner/js/09_popup_evento.js — il popup dell'evento.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Popup dell'evento                                                   */
/* ------------------------------------------------------------------ */
function openEventSheet(existing, seed) {
    var d = existing ? {
        id: existing.id, title: existing.title, notes: existing.notes || '',
        location: existing.location || '', guests: (existing.guests || []).slice(),
        place_name: existing.place_name || null,
        place_lat: (existing.place_lat === 0 || existing.place_lat) ? existing.place_lat : null,
        place_lon: (existing.place_lon === 0 || existing.place_lon) ? existing.place_lon : null,
        place_ref: existing.place_ref || null,
        /* il lucchetto arriva dal server; per gli eventi salvati prima della
           colonna il valore manca, e allora vale chiuso come per i nuovi */
        duration_locked: existing.duration_locked !== false,
        calendar_id: existing.calendar_id || null,
        date: existing.date,
        /* La fine dal server arriva sempre risolta (mai nulla): un evento di
           un giorno solo ha end_date uguale a date. Il popup ragiona su questo
           e non deve mai chiedersi se il valore esiste. */
        end_date: existing.end_date || existing.date,
        start: existing.start, end: existing.end, all_day: existing.all_day,
        category_id: existing.category_id, project_id: existing.project_id,
        project_name: existing.project_name, project_color: existing.project_color,
        importance: existing.importance, color: existing.color, type: existing.type,
        notify: existing.notify, notify_min: existing.notify_min,
        repeat: existing.repeat, repeat_interval: existing.repeat_interval,
        repeat_unit: existing.repeat_unit, repeat_until: existing.repeat_until,
        habit_id: existing.habit_id
    } : {
        id: 0, title: '', notes: '', location: '', guests: [], calendar_id: null,
        place_name: null, place_lat: null, place_lon: null, place_ref: null,
        duration_locked: false,  // si parte da 'auto': la durata segue l'inizio, non la fine
        /* end_date e all_day li puo' dare chi apre (il mese, tenendo premuto
           e trascinando su piu' giorni): se mancano, un giorno solo con orario */
        date: seed.date, end_date: seed.end_date || seed.date, start: seed.start, end: seed.end,
        all_day: !!seed.all_day, category_id: S.defaultCat, project_id: null, importance: 'normal',
        color: null, type: 'event', notify: 'h1', notify_min: 60,
        repeat: 'none', repeat_interval: 1, repeat_unit: 'week', repeat_until: null
    };
    S.draft = { data: d, dirty: false, isNew: !existing, origDate: existing ? existing.date : null };
    // Salviamo la regola originale per capire se viene modificata
    S.draft.origRepeat = existing ? {
        repeat: existing.repeat,
        repeat_interval: existing.repeat_interval,
        repeat_unit: existing.repeat_unit,
        repeat_until: existing.repeat_until
    } : null;

    $('plEvKicker').textContent = existing ? T('planner.modifica') : T('planner.nuovo_evento');
    $('plEvTrash').classList.toggle('pl-hidden', !existing);
    renderEventSheet();
    openOv('plEventOv');
    /* nuovo evento: il titolo è già pronto con la tastiera aperta, così si
       scrive subito senza un tocco in più */
    if (!existing) {
        var focusTitle = function () {
            var i = $('plEvTitle');
            if (i) { i.focus(); try { i.setSelectionRange(0, 0); } catch (e) {} }
        };
        focusTitle();
        setTimeout(focusTitle, 60);
        setTimeout(focusTitle, 420);
    }
}

function tintSheet() {
    var d = S.draft.data;
    var color = d.color || (d.category_id ? (catById(d.category_id) || {}).color : null) || d.project_color || NEUTRAL;
    $('plEventSheet').style.setProperty('--ev', color);
}

function renderEventSheet() {
    var d = S.draft.data;
    var body = $('plEvBody');
    body.innerHTML = '';
    tintSheet();

    /* titolo + progetto */
    var f1 = el('div', 'pl-field');
    var trow = el('div', 'pl-title-row');
    var title = el('input', 'pl-in');
    title.type = 'text'; title.id = 'plEvTitle'; title.placeholder = T('planner.titolo'); title.maxLength = 180;
    title.value = d.title;
    title.addEventListener('input', function () {
        d.title = title.value;
        S.draft.dirty = true;
        /* il luogo può arrivare da solo, se il titolo nomina un posto della
           rubrica (la funzione sta più in basso, nella parte del luogo) */
        agganciaLuogo();
    });
    trow.appendChild(title);
    var pbtn = el('button', 'pl-icon-btn');
    pbtn.type = 'button';
    pbtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7.5A1.5 1.5 0 015.5 6h4l1.6 2H18a1.5 1.5 0 011.5 1.5v7A1.5 1.5 0 0118 18H5.5A1.5 1.5 0 014 16.5z"/></svg>';
    pbtn.addEventListener('click', function () {
        var modal = $('ps-modal-plev');
        if (modal && window.psOpen) window.psOpen(modal);
        else toast(T('planner.selettore_progetti_non_disponibile'));
    });
    trow.appendChild(pbtn);
    f1.appendChild(trow);
    if (d.project_name) {
        var pn = el('div', 'pl-row-sub', T('planner.progetto_due') + d.project_name);
        pn.style.margin = '6px 2px 0';
        f1.appendChild(pn);
    }
    /* Evento o task, subito sotto il titolo e su una riga sola.
       Stava in fondo, in un campo con l'etichetta sopra e il selettore a capo:
       occupava l'altezza di un campo intero per una scelta fra due parole, e
       si trovava dopo aver scorso tutto il popup. Qui è la seconda cosa che si
       decide di un evento, ed è giusto che stia dove la si decide. */
    var trow2 = el('div', 'pl-tipo-riga');
    trow2.appendChild(el('span', 'pl-tipo-lab', T('planner.tipo')));
    trow2.appendChild(seg([['event', T('planner.evento')], ['task', T('planner.task')]], d.type, function (v) {
        d.type = v; S.draft.dirty = true; renderEventSheet();
    }));
    f1.appendChild(trow2);
    body.appendChild(f1);

    /* data, orari, durata */
    var fd = el('div', 'pl-field');
    fd.appendChild(el('label', 'pl-label', T('planner.quando')));
    var rDate = rowSB(T('planner.data_t'), dateInput(d.date, function (v) {
        /* Spostando il giorno di inizio, la fine lo segue conservando la
           durata: un evento dal 3 al 5 spostato al 10 va dal 10 al 12. Senza,
           diventerebbe "dal 10 al 5", cioe' niente — ed e' la stessa regola che
           applica il server quando l'evento viene trascinato. */
        var giorni = giorniFra(d.date, d.end_date);
        d.date = v;
        d.end_date = giorni > 1 ? piuGiorni(v, giorni - 1) : v;
        S.draft.dirty = true;
        aggiornaFine();
        tintSheet();
    }));
    fd.appendChild(rDate);

    /* LA DATA DI FINE, accanto all'orario di fine e non su una riga sua.
       Sono la stessa informazione — quando finisce — e separarle avrebbe
       voluto dire far cercare in due punti la risposta a una domanda sola.
       Finché coincide con il giorno di inizio resta sbiadita: c'è, si legge
       se la si cerca, ma non pretende attenzione — per la stragrande
       maggioranza degli eventi quel valore non si tocca mai. Appena diventa
       un giorno diverso si accende come tutto il resto, perché a quel punto
       è un dato che cambia la sostanza dell'evento. */
    var fineData = dateInput(d.end_date || d.date, function (v) {
        if (!v) { fineData.value = d.date; return; }
        // prima dell'inizio non ha senso: si riporta al giorno di inizio
        if (v < d.date) v = d.date;
        d.end_date = v;
        S.draft.dirty = true;
        aggiornaFine();
        tintSheet();
    });
    fineData.classList.add('pl-pick-data-fine');

    function aggiornaFine() {
        if (!d.end_date) d.end_date = d.date;
        fineData.value = d.end_date;
        fineData.classList.toggle('pl-spenta', d.end_date === d.date);
    }
    aggiornaFine();

    var allRow = rowSB(T('planner.tutto_il_giorno'), toggle(d.all_day, function (v) {
        d.all_day = v;
        /* Togliendo la spunta restavano 00:00–23:59, cioè un evento di tutto
           il giorno con un altro nome. Gli si dà un'ora vera: la prima libera
           a partire da quella che si sta guardando. Il controllo sugli orari
           serve a non buttare via un orario già scelto e poi ripreso. */
        if (!v && (!d.start || d.start === '00:00') && (!d.end || d.end === '23:59')) {
            var inizio = firstFreeMinute(60);
            d.start = toHM(inizio);
            d.end = toHM(Math.min(1439, inizio + 60));
        }
        S.draft.dirty = true; renderEventSheet();
    }));
    fd.appendChild(allRow);

    if (!d.all_day) {
        /* Gli orari si aggiornano fra loro senza ridisegnare il popup:
           ricostruirlo a ogni cambio faceva perdere il fuoco al campo e
           rimetteva il valore di partenza appena si staccava il dito. */
        var sIn, eIn, durIn;
        /* TRE STATI, non due.
           - 'auto' (leggerissimo, chiuso): spostando l'inizio la durata resta
             e la fine segue; spostando la fine l'inizio non si muove e la
             durata cambia. È il comportamento che ci si aspetta senza
             pensarci, ed è quello di partenza.
           - 'chiuso' (deciso): la durata è ferma sempre, quindi spostando la
             fine si trascina anche l'inizio.
           - 'aperto' (chiaro): la durata non è mai ferma, quindi anche
             spostando l'inizio la fine resta dov'è.
           Aprendo un evento si riparte sempre da 'auto', anche se l'ultima
           volta era stato chiuso: il lucchetto dice come si comporteranno i
           campi *adesso*, non che aria tirava l'altra volta, e ritrovarselo
           chiuso senza averlo toccato spostava orari che non si volevano
           spostare. Nel database resta il sì/no `duration_locked`, che ormai
           serve solo a chi legge l'evento da fuori. */
        var stato = 'auto';
        d.duration_locked = false;
        function refreshTimes(skip) {
            if (sIn && skip !== 'start') sIn.value = d.start;
            if (eIn && skip !== 'end') eIn.value = d.end;
            if (durIn && skip !== 'dur') durIn.value = toHM(Math.min(1439, toMin(d.end) - toMin(d.start)));
            tintSheet();
        }
        sIn = timeInput(d.start, function (v) {
            var dur = Math.max(5, toMin(d.end) - toMin(d.start));
            d.start = v;
            /* con il lucchetto aperto la fine resta dov'è; negli altri due
               stati spostare l'inizio porta con sé la fine */
            if (stato !== 'aperto') {
                d.end = toHM(Math.min(1439, toMin(v) + dur));
            } else if (toMin(d.end) <= toMin(v)) {
                /* aperto: la fine resta dov'è, ma non può cadere prima
                   dell'inizio, altrimenti l'evento avrebbe durata negativa */
                d.end = toHM(Math.min(1439, toMin(v) + 5));
            }
            S.draft.dirty = true;
            refreshTimes('start');
        });
        eIn = timeInput(d.end, function (v) {
            /* solo il lucchetto chiuso trascina l'inizio: negli altri due
               stati la fine si sposta da sola e la durata cambia */
            if (stato === 'chiuso') {
                /* chiuso: spostando la fine si trascina anche l'inizio */
                var dur2 = Math.max(5, toMin(d.end) - toMin(d.start));
                var fine = Math.min(1439, toMin(v));
                var inizio = fine - dur2;
                if (inizio < 0) { inizio = 0; fine = Math.min(1439, dur2); }
                d.start = toHM(inizio);
                d.end = toHM(fine);
            } else {
                if (toMin(v) <= toMin(d.start)) v = toHM(toMin(d.start) + 5);
                d.end = v;
            }
            S.draft.dirty = true;
            refreshTimes('end');
        });
        /* Durata in mezzo, fra inizio e fine: è quello che sta fra i due,
           e vederla altrove costringeva a ricostruire l'ordine a mente. La
           scritta \"Durata\" sta a destra insieme al campo, in corsivo, per
           non farsi scambiare per una terza voce alla pari delle altre. */
        var dur = toMin(d.end) - toMin(d.start);
        durIn = el('input', 'pl-pick');
        durIn.type = 'time'; durIn.step = '300';
        durIn.style.width = '104px'; durIn.style.textAlign = 'center';
        durIn.value = toHM(Math.min(1439, dur));
        durIn.addEventListener('change', function () {
            var mins = toMin(durIn.value);
            if (!mins) { durIn.value = toHM(toMin(d.end) - toMin(d.start)); return; }
            /* cambiare la durata a mano muove sempre la fine: è l'inizio il
               riferimento che l'utente ha già fissato */
            d.end = toHM(Math.min(1439, toMin(d.start) + mins));
            S.draft.dirty = true;
            refreshTimes('dur');
        });

        var lock = el('button', 'pl-lock');
        lock.type = 'button';
        function drawLock() {
            lock.className = 'pl-lock pl-lock-' + stato;
            lock.title = stato === 'chiuso'
                ? T('planner.durata_bloccata_spostando_un_orario')
                : (stato === 'aperto'
                    ? T('planner.durata_libera_spostando_un_orario')
                    : T('planner.spostando_linizio_la_durata_resta'));
            lock.setAttribute('aria-label', lock.title);
            lock.setAttribute('aria-pressed', stato === 'chiuso' ? 'true' : 'false');
            /* Tre stati, tre disegni. Il corpo del lucchetto è sempre lo
               stesso: cambia l'archetto (chiuso o scostato) e, sul terzo
               stato, una riga che lo sbarra.
               La sbarra sta sullo stato leggerissimo perché è l'unico in cui
               la durata non è né sempre ferma né mai ferma: dipende da quale
               dei due orari si tocca. Un lucchetto e basta, per quanto
               pallido, sembrava una via di mezzo fra gli altri due; sbarrato
               si legge come «non deciso», che è quello che è. */
            var arco = stato === 'aperto'
                ? '<path d="M8.5 11V8a3.5 3.5 0 016.9-1.2"/>'
                : '<path d="M8.5 11V8a3.5 3.5 0 017 0v3"/>';
            var sbarra = stato === 'auto' ? '<path d="M4.6 19.4L19.4 4.6"/>' : '';
            lock.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                + 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
                + '<rect x="5" y="11" width="14" height="9" rx="2.2"/>' + arco + sbarra + '</svg>';
        }
        drawLock();
        lock.addEventListener('click', function () {
            /* Il giro: leggerissimo → deciso → chiaro → leggerissimo. */
            stato = stato === 'auto' ? 'chiuso' : (stato === 'chiuso' ? 'aperto' : 'auto');
            d.duration_locked = (stato === 'chiuso');
            S.draft.dirty = true;
            drawLock();
            haptic(8);
        });

        fd.appendChild(rowSB(T('planner.inizio_t'), sIn));

        var durRow = el('div', 'pl-row-sb');
        durRow.appendChild(el('span', 'pl-row-lab'));   // vuota: la scritta sta a destra
        var durBox = el('div', 'pl-durbox');
        durBox.appendChild(el('span', 'pl-dur-lab', T('planner.durata')));
        durBox.appendChild(lock);
        durBox.appendChild(durIn);
        durRow.appendChild(durBox);
        fd.appendChild(durRow);

        var boxFine = el('div', 'pl-finebox');
        boxFine.appendChild(fineData);
        boxFine.appendChild(eIn);
        fd.appendChild(rowSB(T('planner.fine_t'), boxFine));
    } else {
        /* Tutto il giorno: l'orario non c'è, la data di fine sì — anzi, è
           proprio qui che serve di più, perché un evento di più giorni senza
           orari (una vacanza, un blocco di studio) è tutto-il-giorno per
           definizione. */
        fd.appendChild(rowSB(T('planner.fine_t'), fineData));
    }
    body.appendChild(fd);

    /* ripetizione */
    var fr = el('div', 'pl-field');
    fr.appendChild(el('label', 'pl-label', T('planner.ripeti')));
    var repSel = select([
        ['none', T('planner.mai_t')], ['daily', T('planner.ogni_giorno')], ['weekly', T('planner.ogni_settimana')],
        ['monthly', T('planner.ogni_mese')], ['yearly', T('planner.ogni_anno')], ['custom', T('planner.personalizzato')]
    ], d.repeat, function (v) { d.repeat = v; S.draft.dirty = true; renderEventSheet(); });
    fr.appendChild(rowSB(T('planner.frequenza_t'), repSel));
    if (d.repeat === 'custom') {
        var wrap = el('div', 'pl-row');
        var n = el('input', 'pl-pick');
        n.type = 'number'; n.min = '1'; n.max = '365'; n.value = d.repeat_interval || 1; n.style.width = '76px';
        n.addEventListener('change', function () { d.repeat_interval = Math.max(1, parseInt(n.value, 10) || 1); S.draft.dirty = true; });
        wrap.appendChild(el('span', 'pl-row-sub', T('planner.ogni')));
        wrap.appendChild(n);
        wrap.appendChild(select([['day', T('planner.u_giorni')], ['week', T('planner.u_settimane')], ['month', T('planner.u_mesi')], ['year', T('planner.u_anni')]], d.repeat_unit,
            function (v) { d.repeat_unit = v; S.draft.dirty = true; }));
        fr.appendChild(rowSB(T('planner.intervallo'), wrap));
    }
    if (d.repeat !== 'none') {
        var untilWrap = el('div');
        if (!d.repeat_until && !S.draft.editing_until) {
            var infBtn = el('button', 'pl-pick');
            infBtn.type = 'button';
            infBtn.textContent = T('planner.infinito');
            infBtn.addEventListener('click', function() {
                S.draft.editing_until = true;
                renderEventSheet();
            });
            untilWrap.appendChild(infBtn);
        } else {
            var dInp = el('input', 'pl-pick');
            dInp.type = 'date';
            dInp.value = d.repeat_until || '';
            dInp.addEventListener('change', function() {
                d.repeat_until = dInp.value;
                S.draft.dirty = true;
                S.draft.editing_until = false;
                renderEventSheet();
            });
            dInp.addEventListener('blur', function() {
                // Se l'utente clicca fuori e ha lasciato il campo vuoto, torna a infinito
                if (!dInp.value) {
                    S.draft.editing_until = false;
                    renderEventSheet();
                }
            });
            untilWrap.appendChild(dInp);

            // Auto-focus e apertura del calendario nativo se è stato appena cliccato
            if (S.draft.editing_until) {
                setTimeout(function() {
                    dInp.focus();
                    try { dInp.showPicker(); } catch(e) {}
                }, 50);
            }
        }
        fr.appendChild(rowSB(T('planner.fino_al'), untilWrap));
    }
    body.appendChild(fr);

    /* calendario */
    /* Il calendario dello studio non compare fra le scelte: là dentro ci
       finiscono i blocchi creati dalla pagina «da studiare», che hanno degli
       argomenti appesi e delle date che quella pagina tiene in ordine.
       Trascinandoci dentro un evento qualsiasi da qui si otterrebbe una riga
       che il to study mostra ma non sa gestire. Ci si sposta da lì, non da qui.
       Se l'evento che si sta modificando ci sta già dentro (lo si è aperto dal
       planner) la sua voce resta, o cambiando qualunque altro campo lo si
       sposterebbe via senza volerlo. */
    var calScegliibili = S.calendars.filter(function (c) {
        return c.role !== 'study' || d.calendar_id === c.id;
    });
    if (calScegliibili.length > 1) {
        var fcal = el('div', 'pl-field');
        fcal.appendChild(el('label', 'pl-label', T('planner.calendario')));

        var calOptions = [];
        var activeCalStr = '';

        alpha(calScegliibili, 'name').forEach(function (c) {
            calOptions.push([String(c.id), c.name]);
            if (d.calendar_id ? d.calendar_id === c.id : c.is_default) {
                activeCalStr = String(c.id);
            }
        });

        var calSel = select(calOptions, activeCalStr, function (v) {
            d.calendar_id = parseInt(v, 10);
            S.draft.dirty = true;
            renderEventSheet();
        });
        calSel.style.width = '100%';

        fcal.appendChild(calSel);
        body.appendChild(fcal);
    }

    /* categoria */
    var fc = el('div', 'pl-field');
    fc.appendChild(el('label', 'pl-label', T('planner.categoria')));
    var tags = el('div', 'pl-tags');
    if (d.project_id && d.project_name) {
        tags.appendChild(tag(d.project_name, d.project_color || NEUTRAL, d.category_id === null, function () {
            d.category_id = null; S.draft.dirty = true; renderEventSheet();
        }));
    }
    alpha(S.categories, 'name').forEach(function (c) {
        tags.appendChild(tag(c.name, c.color, d.category_id === c.id, function () {
            d.category_id = d.category_id === c.id ? null : c.id;
            d.color = null;
            S.draft.dirty = true;
            renderEventSheet();
        }));
    });
    fc.appendChild(tags);
    body.appendChild(fc);

    /* importanza */
    var fi = el('div', 'pl-field');
    fi.appendChild(el('label', 'pl-label', T('planner.importanza')));
    fi.appendChild(seg([['normal', T('planner.normale')], ['high', T('planner.importante')], ['highest', T('planner.importantissimo')]], d.importance, function (v) {
        d.importance = v; S.draft.dirty = true; renderEventSheet();
    }));
    body.appendChild(fi);

    /* notifica */
    var fn = el('div', 'pl-field');
    fn.appendChild(el('label', 'pl-label', T('planner.notifica')));
    fn.appendChild(rowSB(T('planner.avvisami'), select([
        ['none', T('planner.nessuna')], ['at_time', T('planner.nel_momento')], ['m30', T('planner.30_minuti_prima')],
        ['h1', T('planner.un_ora_prima')], ['d1', T('planner.un_giorno_prima')], ['custom', T('planner.personalizzata')]
    ], d.notify, function (v) {
        d.notify = v; S.draft.dirty = true;
        /* Scegliere un avviso è il momento buono per chiedere il permesso:
           qui la richiesta si spiega da sé, all'apertura della pagina no. */
        if (v !== 'none') PL_NOTIFY.chiediPermesso();
        renderEventSheet();
    })));
    if (d.notify !== 'none' && 'Notification' in window && Notification.permission !== 'granted') {
        var avviso = el('div', 'pl-row-sub');
        avviso.style.margin = '4px 2px 0';
        avviso.textContent = Notification.permission === 'denied'
            ? T('planner.gli_avvisi_sono_bloccati_per')
            : T('planner.per_ricevere_gli_avvisi_serve');
        fn.appendChild(avviso);
    }
    if (d.notify === 'custom') {
        var nm = el('input', 'pl-pick');
        nm.type = 'number'; nm.min = '0'; nm.max = '40320'; nm.value = d.notify_min || 60; nm.style.width = '96px';
        nm.addEventListener('change', function () { d.notify_min = Math.max(0, parseInt(nm.value, 10) || 0); S.draft.dirty = true; });
        fn.appendChild(rowSB(T('planner.minuti_prima'), nm));
    }
    body.appendChild(fn);

    /* colore */
    var fcol = el('div', 'pl-field');
    fcol.appendChild(el('label', 'pl-label', T('planner.colore')));
    var sw = el('div', 'pl-swatches');
    var base = d.category_id ? (catById(d.category_id) || {}).color : (d.project_color || NEUTRAL);
    [base, '#C7DED6', '#F0D3D6', '#D8D3E8', '#F3E4C0', '#CFDDEA', '#E8D5C7', '#DCE7C8'].forEach(function (c, i) {
        var b = el('button', 'pl-sw' + ((d.color === c || (i === 0 && !d.color)) ? ' pl-on' : ''));
        b.type = 'button';
        b.style.setProperty('--ev', c);
        b.addEventListener('click', function () {
            d.color = i === 0 ? null : c;
            S.draft.dirty = true;
            renderEventSheet();
        });
        sw.appendChild(b);
    });
    fcol.appendChild(sw);
    body.appendChild(fcol);

    /* luogo */
    var floc = el('div', 'pl-field');
    floc.appendChild(el('label', 'pl-label', T('planner.luogo')));
    var locRow = el('div', 'pl-title-row');
    var locIn = el('input', 'pl-in');
    locIn.type = 'text';
    locIn.placeholder = T('planner.cerca_un_indirizzo_o_un');
    locIn.maxLength = 255;
    locIn.autocomplete = 'off';
    locIn.value = d.location || '';
    locRow.appendChild(locIn);
    var locBtn = el('button', 'pl-icon-btn');
    locBtn.type = 'button';
    locBtn.setAttribute('aria-label', T('planner.apri_nella_mappa'));
    locBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6.5-5.6-6.5-10a6.5 6.5 0 1113 0c0 4.4-6.5 10-6.5 10z"/><circle cx="12" cy="11" r="2.2"/></svg>';
    locBtn.addEventListener('click', function () {
        /* con le coordinate si apre il punto esatto; senza, resta la ricerca
           per testo, che è quello che si poteva fare prima */
        var q = (d.place_lat !== null && d.place_lat !== undefined && d.place_lon !== null)
            ? (d.place_lat + ',' + d.place_lon)
            : ((locIn.value || '').trim() || T('planner.vicino_a_me'));
        window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q),
                    '_blank', 'noopener');
    });
    locRow.appendChild(locBtn);

    /* Rubrica: i posti di tutti i giorni non si cercano ogni volta. Da qui si
       sceglie uno di quelli salvati e si salva quello agganciato adesso. */
    var rubBtn = el('button', 'pl-icon-btn');
    rubBtn.type = 'button';
    rubBtn.setAttribute('aria-label', T('planner.luoghi_salvati'));
    rubBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10a1 1 0 011 1v15l-6-3.5L6 20V5a1 1 0 011-1z"/></svg>';
    rubBtn.addEventListener('click', apriRubrica);
    locRow.appendChild(rubBtn);
    floc.appendChild(locRow);

    /* Riga sotto il campo: dice se il posto è agganciato a una posizione vera
       o è solo testo scritto a mano. Senza questa riga non si capisce se il
       pulsante della mappa aprirà il punto giusto. */
    var locStato = el('div', 'pl-row-sub pl-loc-stato');
    var locSugg = el('div', 'pl-sugg pl-hidden');
    var locMappa = el('div', 'pl-loc-mappa pl-hidden');
    var richiesta = 0;   // le risposte lente non devono coprire quelle nuove
    var timerLuogo = null;
    var inAttesa = false;   // coordinate del posto scelto: chiamata in corso

    /* La mappa vera del posto, quella che si vede sui siti dei ristoranti.
       È un riquadro di Google (servizio Embed): l'unico che può stare in
       pagina, perché la sua chiave è legata al dominio e non si paga. Se la
       chiave non c'è in config.php il riquadro non compare e basta.
       Attenzione: questo riquadro lo carica il browser, quindi qui Google
       vede chi sta guardando — la ricerca, invece, continua a passare dal
       server. Si mostra solo a posto agganciato, mai mentre si scrive. */
    function drawMappa() {
        var chiave = B.map_key || '';
        var ok = chiave && d.place_lat !== null && d.place_lat !== undefined && d.place_lon !== null;
        locMappa.classList.toggle('pl-hidden', !ok);
        if (!ok) { locMappa.innerHTML = ''; locMappa.dataset.src = ''; return; }
        /* con l'identificativo del posto si vede la scheda giusta (nome e
           recensioni); con le sole coordinate, il punto sulla mappa */
        var q = d.place_ref ? ('place_id:' + d.place_ref) : (d.place_lat + ',' + d.place_lon);
        var src = 'https://www.google.com/maps/embed/v1/place?key=' + encodeURIComponent(chiave)
                + '&q=' + encodeURIComponent(q) + '&language=it&zoom=16';
        if (locMappa.dataset.src === src) return;   // è già quella giusta: non ricaricarla
        locMappa.dataset.src = src;
        locMappa.innerHTML = '';
        var f = document.createElement('iframe');
        f.src = src;
        f.loading = 'lazy';
        f.title = T('planner.mappa_del_luogo');
        f.setAttribute('allowfullscreen', '');
        /* la chiave è ristretta al dominio: se il browser non lo dichiara,
           Google rifiuta il riquadro */
        f.referrerPolicy = 'no-referrer-when-downgrade';
        locMappa.appendChild(f);
    }

    function drawStato() {
        locStato.innerHTML = '';
        if (inAttesa) {
            locStato.textContent = T('planner.cerco_la_posizione');
            drawMappa();
            return;
        }
        if (d.place_lat === null || d.place_lat === undefined || d.place_lon === null) {
            locStato.textContent = locIn.value.trim() ? T('planner.posto_a_mano') : '';
            drawMappa();
            return;
        }
        var pin = el('span', null, '📍 ' + (d.place_name || T('planner.posizione_salvata')));
        var via = el('button', 'pl-linkish', T('planner.togli'));
        via.type = 'button';
        via.addEventListener('click', function () {
            /* il testo resta: si toglie solo l'aggancio alla mappa */
            d.place_lat = null; d.place_lon = null; d.place_ref = null; d.place_name = null;
            S.draft.dirty = true;
            drawStato();
        });
        locStato.appendChild(pin);
        locStato.appendChild(via);
        drawMappa();
    }

    function chiudiSugg() { locSugg.classList.add('pl-hidden'); locSugg.innerHTML = ''; }

    function cercaLuoghi(q) {
        var mia = ++richiesta;
        api('place_search', null, { q: q }).then(function (r) {
            if (mia !== richiesta || !S.draft) return;      // risposta sorpassata
            locSugg.innerHTML = '';
            if (!r.ok || !r.places || !r.places.length) {
                locSugg.appendChild(el('div', 'pl-sugg-vuoto', T('planner.nessun_posto_trovato')));
                locSugg.classList.remove('pl-hidden');
                return;
            }
            r.places.forEach(function (p) {
                var b = el('button', 'pl-sugg-row');
                b.type = 'button';
                b.appendChild(el('div', 'pl-row-lab', p.name));
                if (p.detail) b.appendChild(el('div', 'pl-row-sub', p.detail));
                /* pointerdown e non click: il campo perde il fuoco appena si
                   tocca, e con il click l'elenco sarebbe già sparito */
                b.addEventListener('pointerdown', function (e) {
                    e.preventDefault();
                    d.location = p.full || p.name;
                    d.place_name = p.name;
                    d.place_ref = p.ref || null;
                    locIn.value = d.location;
                    S.draft.dirty = true;
                    chiudiSugg();
                    haptic(8);

                    if (p.lat !== null && p.lat !== undefined) {
                        /* la scorta (OpenStreetMap) manda già le coordinate
                           insieme ai suggerimenti: niente seconda chiamata */
                        d.place_lat = p.lat;
                        d.place_lon = p.lon;
                        drawStato();
                        return;
                    }

                    /* Google le dà solo per il posto che si sceglie davvero:
                       è questa la chiamata che si paga (quando si pagherà), e
                       parte una volta sola, non per tutti i suggerimenti. */
                    d.place_lat = null; d.place_lon = null;
                    inAttesa = true;
                    drawStato();
                    var quale = d.place_ref;
                    api('place_details', null, { ref: quale }).then(function (r) {
                        inAttesa = false;
                        /* nel frattempo si può aver scelto un altro posto o
                           chiuso la scheda: la risposta vecchia non comanda */
                        if (!S.draft || d.place_ref !== quale) return;
                        if (r.ok && r.place) {
                            d.place_lat = r.place.lat;
                            d.place_lon = r.place.lon;
                            S.draft.dirty = true;
                        } else {
                            toast(T('planner.posizione_non_trovata_resta_il'));
                        }
                        drawStato();
                    });
                });
                locSugg.appendChild(b);
            });
            locSugg.classList.remove('pl-hidden');
        });
    }

    locIn.addEventListener('input', function () {
        d.location = locIn.value;
        /* Scrivendo a mano si perde l'aggancio: tenere le vecchie coordinate
           accanto a un indirizzo diverso sarebbe peggio che non averle. */
        if (d.place_lat !== null && d.place_lat !== undefined) {
            d.place_lat = null; d.place_lon = null; d.place_ref = null; d.place_name = null;
        }
        /* se una ricerca di coordinate era in volo, quando torna non troverà
           più il suo posto e si fermerà da sola: qui si toglie solo l'attesa */
        inAttesa = false;
        S.draft.dirty = true;
        drawStato();
        var q = locIn.value.trim();
        clearTimeout(timerLuogo);
        if (q.length < 3) { chiudiSugg(); return; }
        /* mezzo secondo di attesa: si cerca a frase finita, non a ogni lettera,
           per non tempestare il servizio delle mappe. Timer suo e non la coda
           dei salvataggi: alla chiusura della pagina una ricerca non ha senso
           che parta lo stesso. */
        timerLuogo = setTimeout(function () { cercaLuoghi(q); }, 500);
    });
    locIn.addEventListener('blur', function () { setTimeout(chiudiSugg, 150); });

    /* Mette nell'evento un posto della rubrica: testo, nome e coordinate in
       un colpo solo, come se lo avessi appena scelto dalla ricerca. */
    function applicaPosto(p) {
        d.location   = p.address || p.name || p.label;
        d.place_name = p.name || p.label;
        d.place_lat  = (p.lat === 0 || p.lat) ? p.lat : null;
        d.place_lon  = (p.lon === 0 || p.lon) ? p.lon : null;
        d.place_ref  = p.ref || null;
        locIn.value  = d.location;
        inAttesa = false;
        S.draft.dirty = true;
        chiudiSugg();
        drawStato();
    }

    /* Aggancio automatico dal titolo: "palestra alle 18" si porta dietro la
       palestra. Vale solo a campo del luogo ancora vuoto — correggere una
       scelta già fatta sarebbe peggio che non aiutare — e con parole di almeno
       tre lettere, perché "a" o "in" starebbero dentro qualunque titolo. */
    function agganciaLuogo() {
        if ((locIn.value || '').trim() !== '') return;
        var t = ' ' + (d.title || '').toLowerCase() + ' ';
        var trovato = null;
        (B.places || []).forEach(function (p) {
            (p.keywords || '').split(',').forEach(function (k) {
                k = k.trim().toLowerCase();
                if (!trovato && k.length >= 3 && t.indexOf(k) >= 0) trovato = p;
            });
        });
        if (trovato) applicaPosto(trovato);
    }

    function apriRubrica() {
        openSheet(T('planner.luoghi_salvati'), function (bd) {
            var lista = el('div', 'pl-places');

            function draw() {
                lista.innerHTML = '';
                var ps = B.places || [];
                if (!ps.length) {
                    lista.appendChild(el('div', 'pl-row-sub',
                        T('planner.ancora_nessun_posto_scegline_uno')));
                }
                ps.forEach(function (p) {
                    var r = el('div', 'pl-place-row');
                    var pick = el('button', 'pl-place-pick');
                    pick.type = 'button';
                    pick.appendChild(el('div', 'pl-row-lab', p.label));
                    var sotto = (p.address || p.name || '');
                    if (p.keywords) sotto += (sotto ? '  ·  ' : '') + p.keywords;
                    if (sotto) pick.appendChild(el('div', 'pl-row-sub', sotto));
                    pick.addEventListener('click', function () {
                        applicaPosto(p);
                        closeOv('plSheetOv');
                        haptic(8);
                    });
                    var x = el('button', 'pl-icon-btn');
                    x.type = 'button';
                    x.setAttribute('aria-label', T('planner.togli_dalla_rubrica'));
                    x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                    x.addEventListener('click', function () {
                        api('place_book_delete', { id: p.id }).then(function (rr) {
                            if (!rr.ok) return;
                            B.places = rr.places || [];
                            draw();
                        });
                    });
                    r.appendChild(pick); r.appendChild(x);
                    lista.appendChild(r);
                });
            }
            draw();
            bd.appendChild(lista);

            /* Si salva solo un posto agganciato a una posizione vera: senza
               coordinate la rubrica servirebbe a poco, e il pulsante della
               mappa tornerebbe a cercare per testo. */
            if (d.place_lat === null || d.place_lat === undefined) {
                bd.appendChild(el('div', 'pl-row-sub',
                    T('planner.per_salvarne_uno_nuovo_scegli')));
                return;
            }
            bd.appendChild(el('div', 'pl-label', T('planner.salva_questo_posto')));
            var nome = el('input', 'pl-in');
            nome.type = 'text'; nome.maxLength = 60;
            nome.placeholder = T('planner.come_lo_chiami_casa_lavoro');
            nome.value = d.place_name || '';
            var kw = el('input', 'pl-in');
            kw.type = 'text'; kw.maxLength = 255;
            kw.placeholder = T('planner.parole_che_lo_agganciano_separate');
            var salva = el('button', 'pl-btn pl-btn-primary', T('planner.salva_nella_rubrica'));
            salva.type = 'button';
            salva.addEventListener('click', function () {
                var lab = nome.value.trim();
                if (!lab) { nome.focus(); return; }
                salva.disabled = true;
                api('place_book_save', {
                    id: 0, label: lab, keywords: kw.value,
                    name: d.place_name || '', address: locIn.value || '',
                    lat: d.place_lat, lon: d.place_lon, ref: d.place_ref || ''
                }).then(function (rr) {
                    salva.disabled = false;
                    if (!rr.ok) { toast(T('planner.non_sono_riuscito_a_salvarlo')); return; }
                    B.places = rr.places || [];
                    kw.value = '';
                    draw();
                    haptic(10);
                });
            });
            bd.appendChild(nome);
            bd.appendChild(kw);
            bd.appendChild(salva);
        });
    }

    floc.appendChild(locSugg);
    floc.appendChild(locStato);
    floc.appendChild(locMappa);
    drawStato();
    body.appendChild(floc);

    /* invitati */
    var fg = el('div', 'pl-field');
    fg.appendChild(el('label', 'pl-label', T('planner.invitati')));
    var gTags = el('div', 'pl-tags');
    function drawGuests() {
        gTags.innerHTML = '';
        (d.guests || []).forEach(function (g) {
            var t = el('button', 'pl-tag pl-on');
            t.type = 'button';
            t.style.setProperty('--ev', '#CFDDEA');
            t.appendChild(el('span', null, g.name || g.email));
            var x = el('span', 'pl-chip-x');
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            t.appendChild(x);
            t.addEventListener('click', function () {
                d.guests = d.guests.filter(function (y) { return y.email !== g.email; });
                S.draft.dirty = true;
                drawGuests();
            });
            gTags.appendChild(t);
        });
    }
    drawGuests();
    fg.appendChild(gTags);

    var gRow = el('div', 'pl-row');
    gRow.style.marginTop = '8px';
    var gIn = el('input', 'pl-in');
    gIn.type = 'email';
    gIn.placeholder = T('planner.ph_email');
    gIn.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        addGuest(d, gIn.value, null, drawGuests);
        gIn.value = '';
    });
    var gBook = el('button', 'pl-icon-btn');
    gBook.type = 'button';
    gBook.setAttribute('aria-label', T('planner.apri_la_rubrica'));
    gBook.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h11a1 1 0 011 1v14a1 1 0 01-1 1H7z"/><path d="M4 8h3M4 12h3M4 16h3"/><circle cx="12.5" cy="10" r="2"/><path d="M9.5 16c.4-1.6 1.6-2.5 3-2.5s2.6.9 3 2.5"/></svg>';
    gBook.addEventListener('click', function () { openRubrica(d, drawGuests); });
    gRow.appendChild(gIn); gRow.appendChild(gBook);
    fg.appendChild(gRow);

    /* Code degli indirizzi più comuni: compaiono solo mentre si scrive nel
       campo, e toccandone una completa l'indirizzo. Spariscono appena si
       esce, per non tenere occupata la schermata. */
    var domini = el('div', 'pl-tags pl-hidden');
    domini.style.marginTop = '7px';
    ['@gmail.com', '@icloud.com', '@outlook.it', '@libero.it', '@hotmail.it', '@yahoo.it', '@virgilio.it']
        .forEach(function (dom) {
            var t = el('button', 'pl-tag');
            t.type = 'button';
            t.appendChild(el('span', null, dom));
            t.addEventListener('pointerdown', function (e) {
                /* pointerdown e non click: altrimenti il campo perde il fuoco
                   e le code spariscono prima che il tocco arrivi */
                e.preventDefault();
                var v = (gIn.value || '').split('@')[0];
                gIn.value = v + dom;
                gIn.focus();
            });
            domini.appendChild(t);
        });
    fg.appendChild(domini);
    gIn.addEventListener('focus', function () { domini.classList.remove('pl-hidden'); });
    gIn.addEventListener('blur', function () {
        setTimeout(function () { domini.classList.add('pl-hidden'); }, 120);
    });

    body.appendChild(fg);

    /* note */
    var fnote = el('div', 'pl-field');
    fnote.appendChild(el('label', 'pl-label', T('planner.note')));
    var ta = el('textarea', 'pl-in pl-note');
    ta.placeholder = T('planner.qualcosa_da_ricordare');
    ta.value = d.notes || '';
    ta.maxLength = 4000;
    /* Il campo si allarga con quello che ci si scrive, fino al tetto messo dal
       CSS: da lì in poi scorre. Così una nota lunga non si legge più da una
       fessura di tre righe. */
    function growNote() {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight + 2, Math.round(window.innerHeight * 0.52)) + 'px';
    }
    ta.addEventListener('input', function () {
        d.notes = ta.value; S.draft.dirty = true; growNote();
    });
    fnote.appendChild(ta);
    body.appendChild(fnote);
    requestAnimationFrame(growNote);

    if (d.id) {
        var del = el('button', 'pl-btn pl-btn-danger', T('planner.elimina_evento'));
        del.type = 'button';
        del.style.marginTop = '4px';
        del.addEventListener('click', function () { confirmDeleteEvent(d); });
        body.appendChild(del);
    }
}

/* Aggiunge un invitato alla bozza, evitando i doppioni. */
function addGuest(d, email, name, redraw) {
    var mail = String(email || '').trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { toast(T('planner.indirizzo_non_valido')); return; }
    d.guests = d.guests || [];
    if (d.guests.some(function (g) { return g.email === mail; })) return;
    d.guests.push({ email: mail, name: name || null });
    S.draft.dirty = true;
    if (redraw) redraw();
}

/* La rubrica: in ordine alfabetico, con la ricerca che guarda sia il nome
   sia l'indirizzo. Da qui si invita, si aggiunge e si cancella. */
function openRubrica(draft, redraw) {
    openSheet(T('planner.rubrica'), function (body) {
        var cerca = el('input', 'pl-in');
        cerca.type = 'search';
        cerca.placeholder = T('planner.cerca_per_nome_o_indirizzo');
        body.appendChild(cerca);

        var rows = el('div', 'pl-rows');
        rows.style.marginTop = '12px';
        body.appendChild(rows);

        function carica(q) {
            api('contacts', null, q ? { q: q } : null).then(function (r) {
                rows.innerHTML = '';
                if (!r.ok || !r.contacts.length) {
                    rows.appendChild(el('div', 'pl-quote pl-serif',
                        q ? T('planner.nessun_contatto') : T('planner.la_rubrica_e_ancora_vuota')));
                    return;
                }
                r.contacts.forEach(function (c) {
                    var row = el('div', 'pl-srow');
                    var inner = el('div', 'pl-srow-in');
                    var txt = el('div');
                    txt.style.flex = '1';
                    txt.appendChild(el('div', 'pl-row-lab', c.name));
                    txt.appendChild(el('div', 'pl-row-sub', c.email));
                    inner.appendChild(txt);
                    inner.addEventListener('click', function () {
                        if (row.classList.contains('pl-swiped')) return;
                        if (draft) { addGuest(draft, c.email, c.name, redraw); closeOv('plSheetOv'); }
                    });
                    var acts = el('div', 'pl-srow-acts');
                    var edit = el('button', 'pl-srow-act');
                    edit.type = 'button';
                    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
                    edit.addEventListener('click', function () { editContact(c, function () { carica(cerca.value.trim()); }); });
                    var del = el('button', 'pl-srow-act pl-del');
                    del.type = 'button';
                    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
                    del.addEventListener('click', function () {
                        api('contact_delete', { id: c.id }).then(function () { carica(cerca.value.trim()); });
                    });
                    acts.appendChild(edit); acts.appendChild(del);
                    row.appendChild(inner); row.appendChild(acts);
                    enableSwipe(row, inner);
                    rows.appendChild(row);
                });
            });
        }
        carica('');
        cerca.addEventListener('input', function () {
            debounceSave('rubrica', 250, function () { carica(cerca.value.trim()); });
        });

        /* aggiungere un contatto nuovo */
        var f = el('div', 'pl-field');
        f.style.marginTop = '18px';
        f.appendChild(el('label', 'pl-label', T('planner.nuovo_contatto')));
        var nome = el('input', 'pl-in');
        nome.type = 'text'; nome.placeholder = T('planner.come_lo_chiami_luchino'); nome.maxLength = 80;
        var mail = el('input', 'pl-in');
        mail.type = 'email'; mail.placeholder = T('planner.ph_email_contatto');
        mail.style.marginTop = '8px';
        f.appendChild(nome); f.appendChild(mail);
        var add = el('button', 'pl-btn pl-btn-primary', T('planner.salva_in_rubrica'));
        add.type = 'button';
        add.style.marginTop = '10px';
        add.addEventListener('click', function () {
            api('contact_save', { name: nome.value, email: mail.value }).then(function (r) {
                if (!r.ok) { toast(r.message || T('planner.non_salvato')); return; }
                nome.value = ''; mail.value = '';
                carica(cerca.value.trim());
                loadRubrica();   // così la barra intelligente lo riconosce subito
                toast(T('planner.contatto_salvato'));
            });
        });
        f.appendChild(add);
        body.appendChild(f);
    });
}

/* Modifica di un contatto: nome e indirizzo, in un avviso semplice. */
function editContact(c, done) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = T('planner.modifica_contatto');
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');
    var nome = el('input', 'pl-in');
    nome.type = 'text'; nome.value = c.name; nome.maxLength = 80;
    nome.placeholder = T('planner.nome');
    var mail = el('input', 'pl-in');
    mail.type = 'email'; mail.value = c.email; mail.style.marginTop = '8px';
    wrap.appendChild(nome); wrap.appendChild(mail);
    $('plAlertMsg').appendChild(wrap);

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', T('planner.annulla')); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var si = el('button', 'pl-btn pl-btn-primary', T('planner.salva')); si.type = 'button';
    si.addEventListener('click', function () {
        api('contact_save', { id: c.id, name: nome.value, email: mail.value }).then(function (r) {
            ov.classList.remove('pl-open');
            if (!r.ok) { toast(r.message || T('planner.non_salvato')); return; }
            loadRubrica();
            if (done) done();
        });
    });
    acts.appendChild(no); acts.appendChild(si);
    ov.classList.add('pl-open');
}

/* pezzi riusabili del popup */
function rowSB(label, control) {
    var r = el('div', 'pl-row-sb');
    r.appendChild(el('span', 'pl-row-lab', label));
    r.appendChild(control);
    return r;
}
function toggle(on, onChange) {
    var t = el('span', 'pl-toggle' + (on ? ' pl-on' : ''));
    t.addEventListener('click', function () {
        var next = !t.classList.contains('pl-on');
        t.classList.toggle('pl-on', next);
        onChange(next);
    });
    return t;
}
function dateInput(value, onChange) {
    var i = el('input', 'pl-pick');
    i.type = 'date'; i.value = value || '';
    i.addEventListener('change', function () { onChange(i.value); });
    return i;
}

/* Quanti giorni copre un evento, estremi compresi: dal 10 al 12 sono tre.
   Si lavora sulle stringhe "AAAA-MM-GG" passando da Date solo per il conto,
   e a mezzogiorno UTC: a mezzanotte, l'ora legale fa sballare la differenza di
   un giorno due volte l'anno. */
function giorniFra(inizio, fine) {
    if (!inizio || !fine) return 1;
    var a = Date.parse(inizio + 'T12:00:00Z'), b = Date.parse(fine + 'T12:00:00Z');
    if (isNaN(a) || isNaN(b) || b < a) return 1;
    return Math.round((b - a) / 86400000) + 1;
}
function piuGiorni(data, n) {
    var t = Date.parse(data + 'T12:00:00Z');
    if (isNaN(t)) return data;
    return new Date(t + n * 86400000).toISOString().slice(0, 10);
}
function timeInput(value, onChange) {
    var i = el('input', 'pl-pick');
    i.type = 'time'; i.step = '300'; i.value = value || '';
    i.addEventListener('change', function () { onChange(i.value); });
    return i;
}
function select(options, value, onChange) {
    var s = el('select', 'pl-pick');
    options.forEach(function (o) {
        var op = el('option', null, o[1]);
        op.value = o[0];
        if (o[0] === value) op.selected = true;
        s.appendChild(op);
    });
    s.addEventListener('change', function () { onChange(s.value); });
    return s;
}
function seg(options, value, onChange) {
    var box = el('div', 'pl-seg');
    options.forEach(function (o) {
        var b = el('button', o[0] === value ? 'pl-on' : '', o[1]);
        b.type = 'button';
        b.addEventListener('click', function () { onChange(o[0]); });
        box.appendChild(b);
    });
    return box;
}
/* cursore a scatti: mostra il valore e lo cambia trascinando */
function slider(min, max, value, onChange) {
    var wrap = el('div', 'pl-row');
    var input = el('input');
    input.type = 'range';
    input.min = String(min); input.max = String(max); input.step = '1';
    input.value = String(value);
    input.style.width = '120px';
    input.style.accentColor = 'var(--accent)';
    var out = el('span', 'pl-row-sub', String(value));
    out.style.minWidth = '14px';
    input.addEventListener('input', function () {
        var v = parseInt(input.value, 10);
        out.textContent = String(v);
        onChange(v);
    });
    wrap.appendChild(input);
    wrap.appendChild(out);
    return wrap;
}

/* quanto marcati sono linee e testi: 1 tenue, 5 deciso */
function applyContrast() {
    var k = Math.max(1, Math.min(5, S.settings.contrast_level || 3));
    document.documentElement.style.setProperty('--k', k);
}

/* quanti giorni affiancati sugli schermi larghi */
function applyDaysVisible() {
    var n = Math.max(1, Math.min(7, S.settings.days_visible || 3));
    document.documentElement.style.setProperty('--days-visible', n);
    buildTrack();
    syncScrollerTop();
}

function tag(label, color, on, onClick) {
    var t = el('button', 'pl-tag' + (on ? ' pl-on' : ''));
    t.type = 'button';
    t.style.setProperty('--ev', color);
    t.appendChild(el('span', 'pl-dot'));
    t.appendChild(el('span', null, label));
    t.addEventListener('click', onClick);
    return t;
}
function durLabel(min) {
    if (min < 60) return T('planner.n_min', {n: min});
    var h = Math.floor(min / 60), m = min % 60;
    return h + 'h' + (m ? ' ' + pad(m) : '');
}
function parseDur(raw) {
    var s = String(raw).toLowerCase().replace(',', '.').trim();
    var m = s.match(/^(\d+)\s*h(?:\s*(\d+))?/);
    if (m) return (+m[1]) * 60 + (+(m[2] || 0));
    var n = parseInt(s, 10);
    return isNaN(n) ? 0 : Math.max(5, Math.min(1439, n));
}

/* progetto scelto dal selettore condiviso */
document.addEventListener('projectselected', function (e) {
    if (!S.draft || !e.detail || e.detail.uid !== 'plev') return;
    var d = S.draft.data;
    d.project_id = e.detail.id > 0 ? e.detail.id : null;
    d.project_name = e.detail.id > 0 ? e.detail.name : null;
    d.project_color = e.detail.color || null;
    if (d.project_id && !d.title.trim()) d.title = e.detail.name;
    S.draft.dirty = true;
    renderEventSheet();
});

/* salvataggio, annullamento, chiusura toccando fuori */
function saveEvent() {
    var d = S.draft.data;
    var payload = {
        id: d.id, title: d.title, notes: d.notes, date: d.date, end_date: d.end_date || d.date,
        start: d.start, end: d.end,
        all_day: d.all_day, category_id: d.category_id || 0, project_id: d.project_id || 0,
        habit_id: d.habit_id || 0, importance: d.importance, color: d.color || '',
        type: d.type, notify: d.notify, notify_min: d.notify_min,
        repeat: d.repeat, repeat_interval: d.repeat_interval, repeat_unit: d.repeat_unit,
        repeat_until: d.repeat_until || '',
        location: d.location || '', guests: d.guests || [],
        place_name: d.place_name || '',
        place_lat: (d.place_lat === 0 || d.place_lat) ? d.place_lat : '',
        place_lon: (d.place_lon === 0 || d.place_lon) ? d.place_lon : '',
        place_ref: d.place_ref || '',
        duration_locked: d.duration_locked ? 1 : 0,
        calendar_id: d.calendar_id || 0
    };

    // Controlliamo se la frequenza è stata alterata
    var repeatChanged = false;
    if (S.draft.origRepeat) {
        if (d.repeat !== S.draft.origRepeat.repeat ||
            d.repeat_interval !== S.draft.origRepeat.repeat_interval ||
            d.repeat_unit !== S.draft.origRepeat.repeat_unit ||
            d.repeat_until !== S.draft.origRepeat.repeat_until) {
            repeatChanged = true;
        }
    }

    /* Il salvataggio passa dalla coda: l'evento cambia SUBITO sullo
       schermo, sbiadito finché il server non conferma. */
    function eseguiSalvataggio(dati) {
        var oldDate = S.draft.origDate;
        closeOv('plEventOv');
        S.draft = null;

        var vecchio = dati.id ? trovaEvento(dati.id, oldDate) : null;
        var ripetuto = (dati.repeat && dati.repeat !== 'none') || !!dati.edit_mode
                       || !!(vecchio && vecchio.is_occurrence);
        haptic(10);

        if (!dati.id) {
            creaSubito(dati, function (r) {
                if (ripetuto) { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }
                if (r.event && r.event.type === 'task' && S.todos) loadWeekTodos(S.todoTab, true);
            });
            return;
        }

        var chiave = dati.id;
        if (ripetuto && vecchio) {
            /* Una ripetizione: tutte le occorrenze hanno lo stesso id, quindi
               si cambia solo quella toccata e la si segna con la sua chiave.
               Il resto della serie lo ridisegna la rilettura dopo la conferma. */
            chiave = vecchio.uid || dati.id;
            Object.assign(vecchio, { title: dati.title, start: dati.start, end: dati.end,
                                     all_day: !!dati.all_day, importance: dati.importance });
        } else {
            mostraLocale(dati.id, eventoLocale(dati, vecchio, dati.id));
        }
        renderDays();
        ridisegnaVistaAperta();
        accoda('event_save', dati, {
            ev: [chiave],
            ok: function (r) {
                if (r.event) { togliDaGiorni(r.event.id); indexEvents([r.event]); }
                if (ripetuto) { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }
                if (r.event && r.event.type === 'task' && S.todos) loadWeekTodos(S.todoTab, true);
            }
        });
    }

    if (d.id > 0 && d.repeat && d.repeat !== 'none') {
        if (repeatChanged) {
            // Se ha toccato le regole di frequenza, è ovvio che voglia modificare l'intera serie
            payload.edit_mode = 'all';
            eseguiSalvataggio(payload);
            return;
        }
        alertBox(T('planner.modifica_evento_ripetuto'), T('planner.vuoi_modificare_solo_questa_data'), [
            { label: T('planner.annulla') },
            { label: T('planner.tutta_la_serie'), onClick: function() {
                payload.edit_mode = 'all';
                eseguiSalvataggio(payload);
            }},
            { label: T('planner.solo_questo'), kind: 'primary', onClick: function() {
                payload.edit_mode = 'one';
                payload.original_date = d.date;
                eseguiSalvataggio(payload);
            }}
        ]);
        return;
    }

    eseguiSalvataggio(payload);
}
/* chiede conferma prima di eliminare: un tocco sbagliato sul cestino non
   deve far sparire un evento senza appello */
function confirmDeleteEvent(d) {
    if (d.repeat && d.repeat !== 'none') {
        alertBox(T('planner.elimina_evento'), T('planner.vuoi_eliminare_solo_questa_data'), [
            { label: T('planner.annulla') },
            { label: T('planner.tutta_la_serie'), kind: 'danger', onClick: function() { deleteEvent(d, 'series'); } },
            { label: T('planner.solo_questo'), kind: 'danger', onClick: function() { deleteEvent(d, 'one'); } }
        ]);
        return;
    }
    alertBox(T('planner.elimino_questo_evento'), d.title ? T('planner.titolo_rimosso', {titolo: d.title}) : T('planner.sara_rimosso'), [
        { label: T('planner.annulla') },
        { label: T('planner.elimina'), kind: 'danger', onClick: function () { deleteEvent(d, 'one'); } }
    ]);
}

function deleteEvent(d, scopeParam) {
    closeOv('plEventOv');
    S.draft = null;
    var scope = scopeParam || 'one';
    var serie = scope === 'series' || (d.repeat && d.repeat !== 'none');
    /* sparisce subito; se il server dice di no, la rilettura lo rimette */
    if (serie) {
        var chiave = scope === 'series' ? d.id : (d.uid || d.id);
        for (var g in S.events) if (S.events.hasOwnProperty(g)) {
            S.events[g] = (S.events[g] || []).filter(function (x) {
                return scope === 'series' ? (x.id !== d.id && x.master_id !== d.id) : !(x.id === d.id && (x.occ_date || x.date) === d.date);
            });
        }
        renderDays();
        ridisegnaVistaAperta();
        accoda('event_delete', { id: d.id, scope: scope, original_date: d.date }, {
            ev: [chiave],
            ok: function () { S.weekCache = {}; loadWeek(monday(S.focusDate), true); }
        });
        return;
    }
    mostraLocale(d.id, null);
    renderDays();
    ridisegnaVistaAperta();
    haptic(10);
    accoda('event_delete', { id: d.id, scope: scope, original_date: d.date }, {
        ev: [d.id],
        ok: function () { if (d.type === 'task' && S.todos) loadWeekTodos(S.todoTab, true); }
    });
}
$('plEvSave').addEventListener('click', function () { if (S.draft) saveEvent(); });
$('plEvTrash').addEventListener('click', function () {
    if (!S.draft || !S.draft.data.id) return;
    confirmDeleteEvent(S.draft.data);
});
$('plEvCancel').addEventListener('click', function () { S.draft = null; closeOv('plEventOv'); });
$('plEventOv').addEventListener('click', function (e) {
    if (e.target !== this || !S.draft) return;
    // toccando fuori: se l'utente ha scritto qualcosa vale come "salva", altrimenti come "cancella"
    if (S.draft.dirty && (S.draft.data.title.trim() || S.draft.data.category_id || S.draft.data.project_id || S.draft.data.notes)) saveEvent();
    else { S.draft = null; closeOv('plEventOv'); }
});

/* il + in basso a destra */
$('plPlus').addEventListener('click', function () {
    var slot = zoomCfg(S.zoom).slot || 30;
    var start = firstFreeMinute(slot);   // il primo buco libero, non il primo orario a schermo
    openEventSheet(null, { date: S.focusDate, start: toHM(start), end: toHM(Math.min(1439, start + slot)) });
});
