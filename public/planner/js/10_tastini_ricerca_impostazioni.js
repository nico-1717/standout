/* planner/js/10_tastini_ricerca_impostazioni.js — pulsanti laterali, ricerca, filtro, impostazioni.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Pulsanti laterali: routine, categoria, abitudini, anticipi          */
/* ------------------------------------------------------------------ */
function closeMenus(except) {
    ['plMenuRoutine', 'plMenuCat', 'plMenuHabit'].forEach(function (id) {
        if (id !== except) { $(id).classList.remove('pl-open'); $(id).style.maxHeight = ''; }
    });
    ['plMenuFav'].forEach(function (id) {
        if (id !== except) { $(id).classList.remove('pl-open'); $(id).style.maxHeight = ''; }
    });
    var map = { plFabRoutine: 'plMenuRoutine', plFabCat: 'plMenuCat', plHabitRing: 'plMenuHabit', plFabFav: 'plMenuFav' };
    Object.keys(map).forEach(function (id) {
        if (map[id] !== except) $(id).classList.remove('pl-on');
    });
    /* con un tastino a destra aperto la rotellina si nasconde: coprirebbe
       la vista, o starebbe lì ferma senza motivo. Torna quando si chiude. */
    if (!except) refreshZoomDial();
    else { var b = $('plZoom'); if (b) b.classList.remove('pl-show'); }
}

/* il menù non deve finire sotto il + : gli diamo l'altezza che resta */
function sizeMenu(menu) {
    var top = menu.getBoundingClientRect().top;
    var qh = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--quotebar-h'), 10) || 0;
    menu.style.maxHeight = Math.max(120, window.innerHeight - top - qh - 96) + 'px';
}

$('plFabRoutine').addEventListener('click', function () {
    var menu = $('plMenuRoutine');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuRoutine' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.routines, 'name').forEach(function (r) {
        var c = el('button', 'pl-chip');
        c.type = 'button';
        c.style.setProperty('--ev', r.color);
        c.appendChild(el('span', 'pl-dot'));
        c.appendChild(el('span', null, r.name));
        c.appendChild(el('span', 'pl-chip-sub', durLabel(r.total)));
        c.addEventListener('click', function () {
            closeMenus();
            $('plFabRoutine').classList.remove('pl-on');
            confirmRoutineTime(r, toHM(firstFreeMinute(r.total)));
        });
        menu.appendChild(c);
    });
    var manage = el('button', 'pl-chip');
    manage.type = 'button';
    manage.appendChild(el('span', null, T('planner.gestisci_routine')));
    manage.addEventListener('click', function () { closeMenus(); openSettings('routines'); });
    menu.appendChild(manage);
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

$('plFabCat').addEventListener('click', function () {
    var btn = this;
    if (S.defaultCat) { posaCategoria(); return; }
    var menu = $('plMenuCat');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuCat' : null);
    btn.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.categories, 'name').forEach(function (c) {
        var chip = el('button', 'pl-chip');
        chip.type = 'button';
        chip.style.setProperty('--ev', c.color);
        chip.appendChild(el('span', 'pl-dot'));
        chip.appendChild(el('span', null, c.name));
        chip.addEventListener('click', function () {
            armaAttrezzo(null, null);   // l'abitudine o il progetto armati si posano
            S.defaultCat = c.id;
            btn.classList.add('pl-picked');
            btn.classList.remove('pl-on');
            btn.style.setProperty('--ev', c.color);
            btn.innerHTML = '';
            btn.appendChild(el('span', 'pl-dot'));
            btn.appendChild(el('span', null, c.name));
            var x = el('span', 'pl-chip-x');
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            btn.appendChild(x);
            menu.classList.remove('pl-open');
            menu.style.maxHeight = '';
        });
        menu.appendChild(chip);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

$('plHabitRing').addEventListener('click', function () {
    /* Ritoccando il tastino con l'abitudine già armata la si posa, come si fa
       con la categoria: se no non ci sarebbe modo di cambiare idea senza
       inserirla da qualche parte. */
    if (S.armato && S.armato.kind === 'habit') { armaAttrezzo(null, null); return; }
    var menu = $('plMenuHabit');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuHabit' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    loadHabits(S.focusDate).then(function (habits) {
        var sett = weeklyOf(S.focusDate);
        if (!habits.length && !sett.length) {
            var none = el('div', 'pl-chip');
            none.appendChild(el('span', null, T('planner.nessuna_abitudine_per_oggi')));
            menu.appendChild(none);
            sizeMenu(menu);
            return;
        }

        /* prima quelle di oggi */
        habits.forEach(function (h) { menu.appendChild(habitChip(h, false)); });

        /* poi una linea sottile, e sotto le settimanali */
        if (sett.length) {
            if (habits.length) menu.appendChild(el('div', 'pl-chip-sep'));
            menu.appendChild(el('div', 'pl-chip-lab', T('planner.questa_settimana')));
            sett.forEach(function (h) { menu.appendChild(habitChip(h, true)); });
        }
        sizeMenu(menu);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* progetti preferiti: il tastino compare solo se ce n'è almeno uno */
$('plFabFav').addEventListener('click', function () {
    if (S.armato && S.armato.kind === 'project') { armaAttrezzo(null, null); return; }
    var menu = $('plMenuFav');
    var open = !menu.classList.contains('pl-open');
    closeMenus(open ? 'plMenuFav' : null);
    this.classList.toggle('pl-on', open);
    if (!open) { menu.classList.remove('pl-open'); menu.style.maxHeight = ''; return; }
    menu.innerHTML = '';
    alpha(S.favorites, 'name').forEach(function (p) {
        var chip = el('button', 'pl-chip');
        chip.type = 'button';
        chip.style.setProperty('--ev', p.color || NEUTRAL);
        chip.appendChild(el('span', 'pl-dot'));
        chip.appendChild(el('span', null, p.name));
        chip.addEventListener('click', function () {
            closeMenus();
            armaAttrezzo('project', p, 0);
            toast(T('planner.tocca_il_calendario_dove_metterlo'));
        });
        menu.appendChild(chip);
    });
    menu.classList.add('pl-open');
    sizeMenu(menu);
});

/* Conferma di orario e durata prima di mettere un progetto in agenda.
   Il titolo è il nome del progetto e il colore è il suo. */
/* NON PIÙ IN USO: le progetti preferiti ora si armano dal tastino e si posano toccando
   il calendario, come le categorie. Resta qui perché è il popup di conferma
   già scritto e collaudato, nel caso si voglia tornare a chiedere l'orario. */
function confirmProjectTime(p, defaultStart, defaultDur) {
    var ov = $('plAlertOv');
    $('plAlertTitle').textContent = p.name;
    $('plAlertMsg').innerHTML = '';
    var wrap = el('div');

    var r1 = el('div', 'pl-row-sb');
    r1.appendChild(el('span', 'pl-row-lab', T('planner.inizio')));
    var startIn = el('input', 'pl-pick');
    startIn.type = 'time'; startIn.step = '300'; startIn.value = defaultStart;
    r1.appendChild(startIn);
    wrap.appendChild(r1);

    /* Qui c'era una "Durata" scritta in un campo orario: l'orologio di
       sistema mostra un'ora del giorno, e chi lo apriva scriveva l'orario di
       fine. Con inizio 15:00 e "16:00" letto come durata l'evento finiva a
       31:00, che il limite del giorno riportava a 23:59 — l'ora di fine
       sembrava ignorata. Adesso il campo è quello che tutti ci leggevano. */
    var r2 = el('div', 'pl-row-sb');
    r2.appendChild(el('span', 'pl-row-lab', T('planner.fine')));
    var endIn = el('input', 'pl-pick');
    endIn.type = 'time'; endIn.step = '300';
    endIn.value = toHM(Math.min(1439, toMin(defaultStart) + defaultDur));
    r2.appendChild(endIn);
    wrap.appendChild(r2);
    $('plAlertMsg').appendChild(wrap);

    /* Spostando l'inizio la fine lo segue, così la durata resta quella
       proposta: solo finché la fine non viene toccata a mano, se no si
       cancellerebbe una scelta appena fatta. */
    var fineToccata = false;
    endIn.addEventListener('input', function () { fineToccata = true; });
    startIn.addEventListener('input', function () {
        if (fineToccata) return;
        endIn.value = toHM(Math.min(1439, toMin(startIn.value || defaultStart) + defaultDur));
    });

    var acts = $('plAlertActs');
    acts.innerHTML = '';
    var no = el('button', 'pl-btn', T('planner.annulla')); no.type = 'button';
    no.addEventListener('click', function () { ov.classList.remove('pl-open'); });
    var yes = el('button', 'pl-btn pl-btn-primary', T('planner.inserisci')); yes.type = 'button';
    yes.addEventListener('click', function () {
        var start = toMin(startIn.value || defaultStart);
        var end = toMin(endIn.value || toHM(Math.min(1439, start + defaultDur)));
        /* fine prima dell'inizio: non si indovina cosa intendesse, si dice
           che non va e si lascia il popup aperto per correggere */
        if (end <= start) { toast(T('planner.la_fine_deve_venire_dopo')); return; }
        ov.classList.remove('pl-open');
        api('event_save', {
            title: p.name, date: S.focusDate, start: toHM(start),
            end: toHM(Math.min(1439, end)),
            project_id: p.id, type: 'event', notify: 'none'
        }).then(function (r) {
            if (!r.ok) { toast(T('planner.non_e_stato_possibile_inserire_2')); return; }
            indexEvents([r.event]);
            renderDays();
            haptic(12);
        });
    });
    acts.appendChild(no); acts.appendChild(yes);
    ov.classList.add('pl-open');
}

/* Un'abitudine nel menù. Quelle settimanali portano il loro anellino con
   "1/4" dentro: si vede a colpo d'occhio quanto manca all'obiettivo. */
function habitChip(h, settimanale) {
    var chip = el('button', 'pl-chip');
    chip.type = 'button';
    chip.style.setProperty('--ev', h.color);

    /* Se l'abitudine è già in agenda nel giorno mostrato, il chip si spegne su
       un fondo grigio: dice "questa sarebbe la seconda volta di oggi" senza
       impedire niente, perché a volte è proprio quello che si vuole.
       Solo per quelle a completamento: su un'abitudine a quantità mettere più
       passaggi nella giornata è il modo normale di usarla, e avvisare
       sarebbe un falso allarme. */
    var giaInserita = eventsOf(S.focusDate).some(function (e) { return e.habit_id === h.id; });
    if (giaInserita && h.a_completamento) chip.classList.add('pl-chip-messa');

    if (settimanale) {
        var C = 62.8, frac = h.target ? Math.min(1, h.done / h.target) : 0;
        var r = el('span', 'pl-chip-ring');
        r.innerHTML = '<svg viewBox="0 0 22 22">'
            + '<circle class="bg" cx="11" cy="11" r="10"/>'
            + '<circle class="fg" cx="11" cy="11" r="10" stroke-dasharray="' + C + '" stroke-dashoffset="'
            + (C * (1 - frac)).toFixed(1) + '"/></svg>';
        r.appendChild(el('span', null, h.done + '/' + h.target));
        chip.appendChild(r);
    } else {
        chip.appendChild(el('span', 'pl-dot'));
    }
    if (h.icon) chip.appendChild(el('span', 'pl-ev-icon', h.icon));
    chip.appendChild(el('span', null, h.name));
    if (settimanale && h.done > h.target) {
        /* oltre l'obiettivo non è un errore: è un merito */
        chip.appendChild(el('span', 'pl-chip-sub', T('planner.oltre')));
    }

    chip.addEventListener('click', function () {
        /* Niente più popup che chiede l'ora a scatola chiusa: si arma
           l'abitudine e poi si tocca il calendario nel punto voluto, che è
           il modo in cui si sceglie un orario guardandolo. */
        closeMenus();
        armaAttrezzo('habit', h, h.minutes && h.minutes > 0 ? h.minutes : (zoomCfg(S.zoom).slot || 30));
        toast(T('planner.tocca_il_calendario_dove_metterla'));
    });
    return chip;
}

/* modalità anticipi / ritardi */
$('plFabShift').addEventListener('click', function () { setShift(!S.shift.on); });
$('plShiftCancel').addEventListener('click', function () { setShift(false); });
$('plShiftSwitch').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    S.shift.dir = b.dataset.dir;
    Array.prototype.forEach.call(this.querySelectorAll('button'), function (x) { x.classList.toggle('pl-on', x === b); });
});
/* Il tasto che applica anticipi e ritardi.

   PERCHÉ SBAGLIAVA. Spostare molti eventi richiede tempo, e il tasto restava
   vivo e identico per tutta l'attesa: sembrava non aver fatto niente, quindi
   lo si premeva ancora. Ogni pressione mandava una richiesta completa, e il
   server le eseguiva tutte: tre pressioni = tre ritardi sommati.
   Ora alla prima pressione il tasto si spegne e lo dice. */
$('plShiftSave').addEventListener('click', function () {
    var btn = this;
    if (btn.disabled) return;                       // già in corso: si ignora

    var ids = Object.keys(S.shift.picked).map(Number);
    if (!ids.length) { toast(T('planner.scegli_almeno_un_evento')); return; }
    var parts = String($('plShiftTime').value || '00:10').split(':');
    var minutes = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    if (!minutes) { toast(T('planner.indica_di_quanto_spostarli')); return; }

    var testo = btn.textContent;
    btn.disabled = true;
    btn.classList.add('pl-busy');
    btn.textContent = T('planner.caricamento');

    function libera() {
        btn.disabled = false;
        btn.classList.remove('pl-busy');
        btn.textContent = testo;
    }

    /* niente ripetizione automatica: spostare due volte gli stessi eventi è
       proprio il danno che stiamo evitando */
    api('events_shift', { ids: ids, minutes: minutes, direction: S.shift.dir }, null, { noRetry: true })
        .then(function (r) {
            if (!r.ok) { libera(); toast(r.message || T('planner.spostamento_non_riuscito')); return; }
            setShift(false);
            libera();
            S.weekCache = {};
            loadWeek(monday(S.focusDate), true);
            toast(T(r.moved === 1 ? 'planner.n_evento_spostato' : 'planner.n_eventi_spostati', {n: r.moved}));
        });
});
function setShift(on) {
    S.shift.on = on;
    S.shift.picked = {};
    document.body.classList.toggle('pl-shift-on', on);
    closeMenus();
    renderDays();
}

/* diario del giorno */
$('plFabDiary').addEventListener('click', function () {
    if (window.standoutOpenDiary) { window.standoutOpenDiary(S.focusDate, 'planner'); return; }
    toast(T('planner.manca_il_file_diario_giorno'));
});

/* quando il diario cambia, i tag della vista mese si aggiornano */
document.addEventListener('diaryupdated', function () {
    S.weekCache = {};
    loadWeek(monday(S.focusDate), true).then(function () { if (S.view === 'month') renderMonth(); });
});

$('plWeekPanelClose').addEventListener('click', closeWeekPanel);
$('plWeekPanelOv').addEventListener('click', function (e) { if (e.target === this) closeWeekPanel(); });

/* dal lunedì, un trascinamento verso destra sul bordo apre il pannello */
(function edgePull() {
    var edge = $('plEdge');
    if (!edge) return;
    var x0 = null, y0 = 0;
    edge.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
    }, { passive: true });
    edge.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0;
        var dy = e.touches[0].clientY - y0;
        if (dx > 44 && Math.abs(dy) < 40) {
            x0 = null;
            if (dowIndex(S.focusDate) === 0) openWeekPanel();
            else toast(T('planner.le_liste_della_settimana_si'));
        }
    }, { passive: true });
    edge.addEventListener('touchend', function () { x0 = null; }, { passive: true });
})();

/* ------------------------------------------------------------------ */
/* Ricerca, filtro, impostazioni                                       */
/* ------------------------------------------------------------------ */
function openSheet(title, build) {
    $('plSheetTitle').textContent = title;
    var body = $('plSheetBody');
    body.innerHTML = '';
    build(body);
    openOv('plSheetOv');
}

$('plSearchBtn').addEventListener('click', function () {
    openSheet(T('planner.cerca'), function (body) {
        var inp = el('input', 'pl-in');
        inp.type = 'search'; inp.placeholder = T('planner.titolo_dell_evento'); inp.maxLength = 60;
        body.appendChild(inp);
        var res = el('div', 'pl-rows');
        res.style.marginTop = '12px';
        body.appendChild(res);
        inp.addEventListener('input', function () {
            debounceSave('search', 300, function () {
                if (inp.value.trim().length < 2) { res.innerHTML = ''; return; }
                api('search', null, { q: inp.value.trim() }).then(function (r) {
                    res.innerHTML = '';
                    if (!r.ok || !r.results.length) {
                        res.appendChild(el('div', 'pl-quote pl-serif', T('planner.nessun_evento_con_questa_parola')));
                        return;
                    }
                    r.results.forEach(function (x) {
                        var row = el('button', 'pl-srow');
                        row.type = 'button';
                        var inner = el('div', 'pl-srow-in');
                        var dot = el('span', 'pl-dot');
                        dot.style.background = x.color || (x.category_id ? (catById(x.category_id) || {}).color : NEUTRAL) || NEUTRAL;
                        dot.style.width = '9px'; dot.style.height = '9px'; dot.style.borderRadius = '50%';
                        inner.appendChild(dot);
                        var txt = el('div');
                        txt.style.flex = '1';
                        txt.appendChild(el('div', 'pl-row-lab', x.title));
                        var dd = parseIso(x.date);
                        txt.appendChild(el('div', 'pl-row-sub',
                            dd.getDate() + ' ' + MESI[dd.getMonth()].toLowerCase() + (x.all_day ? '' : '  ·  ' + x.start)));
                        inner.appendChild(txt);
                        row.appendChild(inner);
                        row.addEventListener('click', function () {
                            closeOv('plSheetOv');
                            goToDate(x.date, false);
                            if (!x.all_day) setTimeout(function () { scrollToMinute(Math.max(0, toMin(x.start) - 60), true); }, 320);
                        });
                        res.appendChild(row);
                    });
                });
            });
        });
        setTimeout(function () { inp.focus(); }, 380);
    });
});

/* Una riga di importanza, fatta come quelle dei calendari: stesso aspetto e
   stesso gesto, perché fa la stessa cosa — accende e spegne una fetta di
   quello che si vede. */
function rigaImportanza(livello, nome, colore) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');

    /* Stessa spunta dei calendari, stessa posizione, stesso gesto: se si
       comporta uguale deve anche sembrare uguale, o si prende per un'altra
       cosa e non la si tocca. */
    var sw = el('span', 'pl-cal-dot' + (S.hiddenImp[livello] ? '' : ' pl-on'));
    sw.style.setProperty('--ev', colore);
    sw.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    sw.addEventListener('click', function (e) {
        e.stopPropagation();
        if (S.hiddenImp[livello]) delete S.hiddenImp[livello]; else S.hiddenImp[livello] = true;
        sw.classList.toggle('pl-on', !S.hiddenImp[livello]);
        renderDays();
        if (S.view === 'month') renderMonth();
        $('plFilterBtn').classList.toggle('pl-on',
            Object.keys(S.hiddenCats).length > 0 || Object.keys(S.hiddenImp).length > 0);
    });
    inner.appendChild(sw);

    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', nome));
    txt.appendChild(el('div', 'pl-row-sub', T('planner.per_importanza')));
    inner.appendChild(txt);

    row.appendChild(inner);
    return row;
}

$('plFilterBtn').addEventListener('click', function () {
    openSheet(T('planner.cosa_mostrare'), function (body) {
        /* ---- calendari ---- */
        var fc = el('div', 'pl-field');
        var head = el('div', 'pl-row-sb');
        head.appendChild(el('span', 'pl-label', T('planner.calendari')));
        var tutti = el('button', 'pl-mini-btn', T('planner.mostra_tutti'));
        tutti.type = 'button';
        tutti.addEventListener('click', function () {
            api('calendar_only', { id: 0 }).then(function (r) {
                if (!r.ok) return;
                S.calendars = r.calendars;
                renderDays();
                if (S.view === 'month') renderMonth();
                closeOv('plSheetOv');
            });
        });
        head.appendChild(tutti);
        fc.appendChild(head);

        var rows = el('div', 'pl-rows');

        /* L'ordine non è alfabetico e basta: il principale in cima perché è
           quello che si cerca per primo, poi le due importanze — che non sono
           calendari ma si spengono allo stesso modo, e stare in fondo a un
           elenco di quindici voci vorrebbe dire non trovarle mai — e solo
           dopo tutti gli altri in ordine alfabetico. */
        var principale = null, altri = [];
        S.calendars.forEach(function (c) {
            if (c.is_default && !principale) principale = c; else altri.push(c);
        });
        if (principale) rows.appendChild(calendarRow(principale));

        rows.appendChild(rigaImportanza('highest', T('planner.importantissimi'), '#C6553F'));
        rows.appendChild(rigaImportanza('high', T('planner.importanti'), '#D98F3F'));

        alpha(altri, 'name').forEach(function (c) { rows.appendChild(calendarRow(c)); });
        fc.appendChild(rows);

        var addCal = el('button', 'pl-addlist');
        addCal.type = 'button';
        addCal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addCal.appendChild(el('span', null, T('planner.nuovo_calendario')));
        addCal.style.marginTop = '8px';
        addCal.addEventListener('click', function () { editCalendar(null); });
        fc.appendChild(addCal);
        body.appendChild(fc);

        /* ---- categorie ---- */
        var lab = el('div', 'pl-label', T('planner.categorie'));
        lab.style.marginTop = '20px';
        body.appendChild(lab);
        body.appendChild(el('div', 'pl-row-sub', T('planner.tocca_una_categoria_per_nasconderne')));
        var tags = el('div', 'pl-tags');
        tags.style.marginTop = '12px';
        alpha(S.categories, 'name').forEach(function (c) {
            var t = tag(c.name, c.color, !S.hiddenCats[c.id], function () {
                var hidden = !S.hiddenCats[c.id];
                if (hidden) S.hiddenCats[c.id] = true; else delete S.hiddenCats[c.id];
                t.classList.toggle('pl-on', !hidden);
                t.classList.toggle('pl-off', hidden);
                api('category_hide', { id: c.id, hidden: hidden });
                renderDays();
                if (S.view === 'month') renderMonth();
                $('plFilterBtn').classList.toggle('pl-on', Object.keys(S.hiddenCats).length > 0);
            });
            if (S.hiddenCats[c.id]) t.classList.add('pl-off');
            tags.appendChild(t);
        });
        body.appendChild(tags);
    });
});

$('plSettingsBtn').addEventListener('click', function () { openSettings(); });

/* Una sezione delle impostazioni che si apre e si chiude. Categorie e routine
   aperte riempivano da sole tutto il popup: le voci sotto c'erano, ma per
   arrivarci bisognava scorrere oltre elenchi che quasi sempre non si stava
   cercando. Chiuse restano una riga, con quante ce ne sono. */
function sezionePieghevole(titolo, quante) {
    var field = el('div', 'pl-field');
    var head = el('button', 'pl-fold-head');
    head.type = 'button';
    head.appendChild(el('span', 'pl-label', titolo));
    head.appendChild(el('span', 'pl-fold-n', quante ? String(quante) : ''));
    var arrow = el('span', 'pl-fold-arrow');
    arrow.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>';
    head.appendChild(arrow);

    var corpo = el('div', 'pl-fold-body pl-hidden');
    field.appendChild(head);
    field.appendChild(corpo);

    function apri(on) {
        corpo.classList.toggle('pl-hidden', !on);
        head.classList.toggle('pl-open', on);
        head.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    apri(false);
    head.addEventListener('click', function () { apri(corpo.classList.contains('pl-hidden')); });
    return { field: field, corpo: corpo, apri: apri };
}

function openSettings(focus) {
    openSheet(T('planner.impostazioni'), function (body) {
        /* palette */
        /* pl-solo-carta: righe che valgono solo con lo stile "carta". Con lo
           stile standard il colore della pagina e gli alberi non si vedono,
           quindi le righe si nascondono (stile_standard.css). */
        var fp = el('div', 'pl-field pl-solo-carta');
        fp.appendChild(el('label', 'pl-label', T('planner.colore_della_pagina')));
        var sw = el('div', 'pl-swatches');
        [['sage', '#A9C3AE'], ['mint', '#A5CCC4'], ['blush', '#E7B9C0'], ['lavender', '#C2B8DC'],
         ['butter', '#E8D4A2'], ['sky', '#B0C7DD'], ['clay', '#DDBCA6'], ['rose', '#DFAFB4'],
         ['sea', '#9FC2C6'], ['olive', '#BFC49B'], ['plum', '#C3A9BE'], ['sand', '#E0CDA9']].forEach(function (p) {
            var b = el('button', 'pl-sw' + (S.settings.palette === p[0] ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', p[1]);
            b.addEventListener('click', function () {
                S.settings.palette = p[0];
                applyPalette(p[0]);
                applySky();
                api('settings_save', { palette: p[0] });
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fp.appendChild(sw);
        body.appendChild(fp);

        /* categorie */
        var sc2 = sezionePieghevole(T('planner.categorie'), (S.categories || []).length);
        var rows = el('div', 'pl-rows');
        alpha(S.categories, 'name').forEach(function (c) { rows.appendChild(categoryRow(c)); });
        sc2.corpo.appendChild(rows);
        var addCat = el('button', 'pl-addlist');
        addCat.type = 'button';
        addCat.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addCat.appendChild(el('span', null, T('planner.nuova_categoria')));
        addCat.style.marginTop = '8px';
        addCat.addEventListener('click', function () { editCategory(null); });
        sc2.corpo.appendChild(addCat);
        body.appendChild(sc2.field);

        /* routine */
        var sr2 = sezionePieghevole(T('planner.routine_t'), (S.routines || []).length);
        sr2.field.id = 'plSetRoutines';
        var rr = el('div', 'pl-rows');
        alpha(S.routines, 'name').forEach(function (r) { rr.appendChild(routineRow(r)); });
        sr2.corpo.appendChild(rr);
        var addR = el('button', 'pl-addlist');
        addR.type = 'button';
        addR.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addR.appendChild(el('span', null, T('planner.nuova_routine')));
        addR.style.marginTop = '8px';
        addR.addEventListener('click', function () { editRoutine(null); });
        sr2.corpo.appendChild(addR);
        body.appendChild(sr2.field);

        /* frasi + zoom + ambiente */
        var fo = el('div', 'pl-field');
        fo.appendChild(el('label', 'pl-label', T('planner.sulla_pagina')));
        fo.appendChild(rowSB(T('planner.frasi_motivazionali'), toggle(S.settings.show_motivational !== false, function (v) {
            S.settings.show_motivational = v; api('settings_save', { show_motivational: v }); renderWeekBits();
        })));
        fo.appendChild(rowSB(T('planner.affermazioni_positive'), toggle(S.settings.show_affirmations !== false, function (v) {
            S.settings.show_affirmations = v; api('settings_save', { show_affirmations: v }); renderWeekBits();
        })));
        /* La barra vive sotto la spunta e sparisce con lei: regolare quanto si
           vede una cosa spenta non vuol dire niente. */
        var rigaAmb = rowSB(T('planner.quanto_si_vedono'), slider(0, 10, ambOpacita(), function (v) {
            S.settings.ambient_opacity = v;
            applyAmbientOpacity();
            debounceSave('ambop', 500, function () { api('settings_save', { ambient_opacity: v }); });
        }));
        var rigaAlberi = rowSB(T('planner.alberi_e_animali'), toggle(S.settings.ambient_enabled !== false, function (v) {
            S.settings.ambient_enabled = v; api('settings_save', { ambient_enabled: v });
            rigaAmb.classList.toggle('pl-hidden', !v);
            applyAmbient(true); applySky();
        }));
        rigaAlberi.classList.add('pl-solo-carta');
        rigaAmb.classList.add('pl-solo-carta');
        fo.appendChild(rigaAlberi);
        rigaAmb.classList.toggle('pl-hidden', S.settings.ambient_enabled === false);
        fo.appendChild(rigaAmb);
        fo.appendChild(rowSB(T('planner.scatti_degli_orari'), select(ZOOM.map(function (z) { return [z.key, z.label]; }), S.zoom, function (v) {
            setZoom(v);
        })));
        fo.appendChild(rowSB(T('planner.quanto_marcato'), slider(1, 5, S.settings.contrast_level || 3, function (v) {
            S.settings.contrast_level = v;
            applyContrast();
            debounceSave('contrast', 500, function () { api('settings_save', { contrast_level: v }); });
        })));
        if (window.matchMedia('(min-width:760px)').matches) {
            fo.appendChild(rowSB(T('planner.giorni_affiancati'), slider(1, 7, S.settings.days_visible || 3, function (v) {
                S.settings.days_visible = v;
                applyDaysVisible();
                debounceSave('daysv', 500, function () { api('settings_save', { days_visible: v }); });
            })));
        }
        body.appendChild(fo);

        /* ---- Google Calendar ---- */
        var fg = el('div', 'pl-field');
        fg.appendChild(el('label', 'pl-label', T('planner.google_calendar')));
        var gBox = el('div');
        gBox.appendChild(el('div', 'pl-row-sub', T('planner.controllo_del_collegamento')));
        fg.appendChild(gBox);
        body.appendChild(fg);
        drawGoogle(gBox);

        /* chi arriva qui per le routine le sta cercando: chiuse gli
           mostrerebbero una riga sola con dentro niente */
        if (focus === 'routines') {
            sr2.apri(true);
            setTimeout(function () { $('plSetRoutines').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300);
        }
    });
}

/* Una riga della lista calendari: si accende e si spegne, si tocca il nome
   per vedere solo quello, e scorrendo compaiono modifica ed elimina. */
function calendarRow(c) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');

    var sw = el('span', 'pl-cal-dot' + (c.visible ? ' pl-on' : ''));
    sw.style.setProperty('--ev', c.color || '#B7AEA3'); // FIX checkbox invisibile
    sw.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>';
    sw.addEventListener('click', function (e) {
        e.stopPropagation();
        var next = !c.visible;
        c.visible = next;
        sw.classList.toggle('pl-on', next);
        api('calendar_visible', { id: c.id, visible: next });
        renderDays();
        if (S.view === 'month') renderMonth();
    });
    inner.appendChild(sw);

    var txt = el('div');
    txt.style.flex = '1';
    
    // Riga dedicata a Nome del calendario + Bottone delle Regole
    var tRow = el('div', 'pl-row-sb');
    tRow.style.padding = '0'; tRow.style.borderBottom = 'none';
    tRow.appendChild(el('div', 'pl-row-lab', c.name));
    
    var rulesBtn = el('button', 'pl-icon-btn');
    rulesBtn.type = 'button';
    rulesBtn.style.width = '24px'; rulesBtn.style.height = '24px'; rulesBtn.style.background = 'none'; rulesBtn.style.boxShadow = 'none';
    rulesBtn.setAttribute('aria-label', T('planner.regole_speciali'));
    rulesBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>';
    rulesBtn.addEventListener('click', function(e) { e.stopPropagation(); openCalendarRules(c); });
    tRow.appendChild(rulesBtn);
    
    txt.appendChild(tRow);

    var note = [];
    if (c.is_default) note.push(T('planner.predefinito'));
    if (c.from_google) note.push(T('planner.da_google'));
    if (note.length) txt.appendChild(el('div', 'pl-row-sub', note.join(' · ')));
    inner.appendChild(txt);

    var solo = el('button', 'pl-mini-btn', T('planner.solo_questo'));
    solo.type = 'button';
    solo.addEventListener('click', function (e) {
        e.stopPropagation();
        api('calendar_only', { id: c.id }).then(function (r) {
            if (!r.ok) return;
            S.calendars = r.calendars;
            renderDays();
            if (S.view === 'month') renderMonth();
            closeOv('plSheetOv');
        });
    });
    inner.appendChild(solo);

    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editCalendar(c); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox(T('planner.elimino_nome', {nome: c.name}), T('planner.gli_eventi_non_si_perdono'), [
            { label: T('planner.annulla') },
            { label: T('planner.elimina'), kind: 'danger', onClick: function () {
                api('calendar_delete', { id: c.id }).then(function (r) {
                    if (!r.ok) { toast(r.message || T('planner.non_eliminato')); return; }
                    S.calendars = r.calendars;
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true);
                    closeOv('plSheetOv');
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    return row;
}

function editCalendar(c) {
    var isNew = !c;
    var data = c ? { id: c.id, name: c.name, color: c.color }
                 : { id: 0, name: '', color: '#A9C3AE' };
    openSheet(isNew ? T('planner.nuovo_calendario_l') : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = T('planner.compleanni_vacanze_lavoro');
        name.value = data.name; name.maxLength = 80;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', T('planner.colore')));
        var sw = el('div', 'pl-swatches');
        ['#A9C3AE', '#E7B9C0', '#C2B8DC', '#E8D4A2', '#B0C7DD', '#DDBCA6',
         '#9FC2C6', '#BFC49B', '#C3A9BE', '#DFAFB4'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        var acts = el('div', 'pl-alert-acts');
        var no = el('button', 'pl-btn', T('planner.annulla'));
        no.type = 'button';
        no.addEventListener('click', function () { closeOv('plSheetOv'); });
        var yes = el('button', 'pl-btn pl-btn-primary', isNew ? T('planner.crea') : T('comune.salva'));
        yes.type = 'button';
        yes.addEventListener('click', function () {
            api('calendar_save', { id: data.id, name: name.value, color: data.color }).then(function (r) {
                if (!r.ok) { toast(r.message || T('planner.non_salvato')); return; }
                S.calendars = r.calendars;
                closeOv('plSheetOv');
                renderDays();
            });
        });
        acts.appendChild(no); acts.appendChild(yes);
        body.appendChild(acts);
    });
}

function openCalendarRules(c) {
    openSheet(T('planner.regole_di', {nome: c.name}), function(body) {
        var f1 = el('div', 'pl-field');
        f1.appendChild(el('label', 'pl-label', 'Auto-assegnazione'));
        f1.appendChild(el('div', 'pl-row-sub', T('planner.se_il_titolo_contiene_una')));
        var kwIn = el('input', 'pl-in');
        kwIn.type = 'text'; kwIn.value = c.keywords || ''; kwIn.placeholder = T('planner.parole_separate_da_virgola');
        kwIn.style.marginTop = '6px';
        f1.appendChild(kwIn);
        body.appendChild(f1);
        
        var f2 = el('div', 'pl-field');
        f2.style.marginTop = '20px';
        f2.appendChild(el('label', 'pl-label', T('planner.proprieta_di_default')));
        f2.appendChild(el('div', 'pl-row-sub', T('planner.i_nuovi_eventi_in_questo')));
        
        var repSel = select([
            ['none', T('planner.nessuna')], ['daily', T('planner.ogni_giorno')], ['weekly', T('planner.ogni_settimana')],
            ['monthly', T('planner.ogni_mese')], ['yearly', T('planner.ogni_anno')]
        ], c.def_repeat || 'none', function() {});
        repSel.style.maxWidth = '55%';
        f2.appendChild(rowSB(T('planner.ripetizione'), repSel));
        
        var notSel = select([
            ['none', T('planner.nessuna')], ['at_time', T('planner.nel_momento')], ['m30', T('planner.30_minuti_prima')],
            ['h1', T('planner.un_ora_prima')], ['d1', T('planner.un_giorno_prima')]
        ], c.def_notify || 'none', function() {});
        notSel.style.maxWidth = '55%';
        f2.appendChild(rowSB(T('planner.notifica'), notSel));
        body.appendChild(f2);
        
        var f3 = el('div', 'pl-field');
        f3.style.marginTop = '20px';
        var retroLabel = el('label', 'pl-row');
        var retroCheck = el('input');
        retroCheck.type = 'checkbox';
        retroCheck.style.width = '18px'; retroCheck.style.height = '18px';
        retroLabel.appendChild(retroCheck);
        var spanLab = el('span', null, T('planner.applica_retroattivamente_agli_eventi_gia'));
        spanLab.style.fontSize = '14px'; spanLab.style.color = 'var(--ink)';
        retroLabel.appendChild(spanLab);
        f3.appendChild(retroLabel);
        body.appendChild(f3);
        
        var acts = el('div', 'pl-alert-acts');
        acts.style.marginTop = '20px';
        var cancel = el('button', 'pl-btn', T('planner.annulla'));
        cancel.type = 'button';
        cancel.addEventListener('click', function() { closeOv('plSheetOv'); });
        var save = el('button', 'pl-btn pl-btn-primary', T('planner.salva_regole'));
        save.type = 'button';
        save.addEventListener('click', function() {
            save.disabled = true;
            save.textContent = T('planner.salvataggio');
            api('calendar_rules_save', {
                id: c.id,
                keywords: kwIn.value.trim(),
                def_repeat: repSel.value,
                def_notify: notSel.value,
                apply_retro: retroCheck.checked
            }).then(function(r) {
                save.disabled = false;
                save.textContent = T('planner.salva_regole');
                if (!r.ok) { toast(T('planner.errore_nel_salvataggio')); return; }
                S.calendars = r.calendars;
                closeOv('plSheetOv');
                toast(T('planner.regole_speciali_salvate'));
                if (retroCheck.checked) {
                    S.weekCache = {};
                    loadWeek(monday(S.focusDate), true);
                }
            });
        });
        acts.appendChild(cancel); acts.appendChild(save);
        body.appendChild(acts);
    });
}

function categoryRow(c) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');
    var dot = el('span', 'pl-dot');
    dot.style.cssText = 'width:12px;height:12px;border-radius:50%;flex-shrink:0;background:' + c.color;
    inner.appendChild(dot);
    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', c.name));
    txt.appendChild(el('div', 'pl-row-sub', c.keywords.length ? c.keywords.join(' · ') : T('planner.nessuna_parola_l')));
    inner.appendChild(txt);
    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editCategory(c); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox(T('planner.elimino_nome', {nome: c.name}), T('planner.gli_eventi_resteranno_ma_senza'), [
            { label: T('planner.annulla') },
            { label: T('planner.elimina'), kind: 'danger', onClick: function () {
                api('category_delete', { id: c.id }).then(function (r) {
                    if (!r.ok) return;
                    S.categories = r.categories;
                    renderDays(); openSettings();
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    inner.addEventListener('click', function () { if (!row.classList.contains('pl-swiped')) editCategory(c); });
    return row;
}

function enableSwipe(row, inner) {
    var x0 = null;
    inner.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    inner.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0;
        if (dx < -24) row.classList.add('pl-swiped');
        if (dx > 24) row.classList.remove('pl-swiped');
    }, { passive: true });
    inner.addEventListener('touchend', function () { x0 = null; }, { passive: true });
}

function editCategory(c) {
    var isNew = !c;
    var data = c ? { id: c.id, name: c.name, color: c.color, keywords: c.keywords.slice() } : { id: 0, name: '', color: '#C7DED6', keywords: [] };
    openSheet(isNew ? T('planner.nuova_categoria_l') : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = T('planner.nome'); name.value = data.name; name.maxLength = 60;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', T('planner.colore')));
        var sw = el('div', 'pl-swatches');
        ['#C7DED6', '#F0D3D6', '#D8D3E8', '#F3E4C0', '#CFDDEA', '#E8D5C7', '#DCE7C8', '#E4DDD3',
         '#E9A7A0', '#A9C8B4', '#B3A8D8', '#F2CE8A', '#8FB6CE', '#D9A8C6', '#AEC98A', '#C9A88E',
         '#7FB0AB', '#E7B7CF', '#9AA0D2', '#D8C48E'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        /* due tasti affiancati: si può uscire senza salvare, prima non c'era
           modo di annullare una modifica iniziata per sbaglio */
        var acts = el('div', 'pl-alert-acts');
        acts.style.marginTop = '4px';
        var cancel = el('button', 'pl-btn', T('planner.annulla'));
        cancel.type = 'button';
        cancel.addEventListener('click', function () { openSettings(); });
        var save = el('button', 'pl-btn pl-btn-primary', isNew ? T('planner.crea_categoria') : T('comune.salva'));
        save.type = 'button';
        save.addEventListener('click', function () {
            api('category_save', { id: data.id, name: name.value, color: data.color }).then(function (r) {
                if (!r.ok) { toast(r.message || T('planner.non_salvato')); return; }
                S.categories = r.categories;
                renderDays();
                openSettings();
            });
        });
        acts.appendChild(cancel); acts.appendChild(save);
        body.appendChild(acts);

        if (!isNew) {
            var fk = el('div', 'pl-field');
            fk.style.marginTop = '18px';
            fk.appendChild(el('label', 'pl-label', T('planner.parole_che_assegnano_questa_categoria')));
            var tags = el('div', 'pl-tags');
            function drawTags() {
                tags.innerHTML = '';
                data.keywords.forEach(function (k) {
                    var t = el('button', 'pl-tag pl-on');
                    t.type = 'button';
                    t.style.setProperty('--ev', data.color);
                    t.appendChild(el('span', null, k));
                    var x = el('span', 'pl-chip-x');
                    x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                    t.appendChild(x);
                    t.addEventListener('click', function () {
                        api('keyword_delete', { category_id: data.id, keyword: k }).then(function (r) {
                            if (!r.ok) return;
                            S.categories = r.categories;
                            data.keywords = data.keywords.filter(function (y) { return y !== k; });
                            drawTags();
                        });
                    });
                    tags.appendChild(t);
                });
            }
            drawTags();
            fk.appendChild(tags);
            var kwRow = el('div', 'pl-row');
            kwRow.style.marginTop = '10px';
            var kw = el('input', 'pl-in');
            kw.type = 'text'; kw.placeholder = T('planner.es_palestra'); kw.maxLength = 60;
            var addKw = el('button', 'pl-btn pl-btn-primary', T('planner.aggiungi_2'));
            addKw.type = 'button';
            addKw.style.flex = '0 0 auto';
            addKw.style.padding = '12px 18px';
            addKw.addEventListener('click', function () {
                var word = kw.value.trim().toLowerCase();
                if (!word) return;
                api('keyword_add', { category_id: data.id, keyword: word }).then(function (r) {
                    if (r.ok) {
                        S.categories = r.categories;
                        data.keywords.push(word);
                        kw.value = '';
                        drawTags();
                        return;
                    }
                    if (r.error === 'duplicate') {
                        alertBox(T('planner.parola_gia_usata'), T('planner.parola_gia_assegnata', {parola: word, categoria: r.category_name}), [{ label: T('planner.ho_capito') }]);
                    } else {
                        toast(r.message || T('planner.non_e_stato_possibile_aggiungere_2'));
                    }
                });
            });
            kwRow.appendChild(kw); kwRow.appendChild(addKw);
            fk.appendChild(kwRow);
            body.appendChild(fk);
        }
    });
}

function routineRow(r) {
    var row = el('div', 'pl-srow');
    var inner = el('div', 'pl-srow-in');
    var dot = el('span');
    dot.style.cssText = 'width:12px;height:12px;border-radius:50%;flex-shrink:0;background:' + r.color;
    inner.appendChild(dot);
    var txt = el('div');
    txt.style.flex = '1';
    txt.appendChild(el('div', 'pl-row-lab', r.name));
    txt.appendChild(el('div', 'pl-row-sub', durLabel(r.total) + ' · ' + T('planner.n_passaggi', {n: r.items.length})));
    inner.appendChild(txt);
    var acts = el('div', 'pl-srow-acts');
    var edit = el('button', 'pl-srow-act');
    edit.type = 'button';
    edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 19h3l9.5-9.5-3-3L5 16z"/></svg>';
    edit.addEventListener('click', function () { editRoutine(r); });
    var del = el('button', 'pl-srow-act pl-del');
    del.type = 'button';
    del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>';
    del.addEventListener('click', function () {
        alertBox(T('planner.elimino_nome', {nome: r.name}), T('planner.gli_eventi_gia_inseriti_restano'), [
            { label: T('planner.annulla') },
            { label: T('planner.elimina'), kind: 'danger', onClick: function () {
                api('routine_delete', { id: r.id }).then(function (res) {
                    if (!res.ok) return;
                    S.routines = res.routines;
                    openSettings('routines');
                });
            } }
        ]);
    });
    acts.appendChild(edit); acts.appendChild(del);
    row.appendChild(inner); row.appendChild(acts);
    enableSwipe(row, inner);
    inner.addEventListener('click', function () { if (!row.classList.contains('pl-swiped')) editRoutine(r); });
    return row;
}

function editRoutine(r) {
    var isNew = !r;
    var data = r ? { id: r.id, name: r.name, color: r.color, items: r.items.map(function (i) { return { name: i.name, minutes: i.minutes }; }) }
                 : { id: 0, name: '', color: '#F6EBD2', items: [{ name: '', minutes: 5 }] };
    openSheet(isNew ? T('planner.nuova_routine_l') : data.name, function (body) {
        var f = el('div', 'pl-field');
        var name = el('input', 'pl-in');
        name.type = 'text'; name.placeholder = T('planner.nome_della_routine'); name.value = data.name; name.maxLength = 80;
        f.appendChild(name);
        body.appendChild(f);

        var fc = el('div', 'pl-field');
        fc.appendChild(el('label', 'pl-label', T('planner.colore')));
        var sw = el('div', 'pl-swatches');
        ['#F6EBD2', '#E6E1F1', '#D7EAE5', '#F6E1E4', '#DCE7F0', '#F1E0D3',
         '#EFE1C4', '#DDD6EE', '#C9E2DC', '#F2D6DA', '#CFDEEC', '#E9D6C6',
         '#E4EBC9', '#D6E7E9', '#EFD9E9', '#F0E6CE'].forEach(function (col) {
            var b = el('button', 'pl-sw' + (data.color === col ? ' pl-on' : ''));
            b.type = 'button';
            b.style.setProperty('--ev', col);
            b.addEventListener('click', function () {
                data.color = col;
                Array.prototype.forEach.call(sw.children, function (x) { x.classList.remove('pl-on'); });
                b.classList.add('pl-on');
            });
            sw.appendChild(b);
        });
        fc.appendChild(sw);
        body.appendChild(fc);

        var fi = el('div', 'pl-field');
        var head = el('div', 'pl-row-sb');
        head.appendChild(el('span', 'pl-label', T('planner.passaggi')));
        var totalLab = el('span', 'pl-row-sub', '');
        head.appendChild(totalLab);
        fi.appendChild(head);
        var itemsBox = el('div');
        fi.appendChild(itemsBox);

        function total() {
            var t = 0;
            data.items.forEach(function (i) { t += (parseInt(i.minutes, 10) || 0); });
            totalLab.textContent = T('planner.durata_in_tutto', {durata: durLabel(t)});
        }
        function drawItems() {
            itemsBox.innerHTML = '';
            data.items.forEach(function (it, idx) {
                var row = el('div', 'pl-row');
                row.style.margin = '7px 0';
                var n = el('input', 'pl-in');
                n.type = 'text'; n.placeholder = T('planner.passaggio'); n.value = it.name; n.maxLength = 120;
                n.addEventListener('input', function () { it.name = n.value; });
                var m = el('input', 'pl-pick');
                m.type = 'number'; m.min = '1'; m.max = '600'; m.value = it.minutes; m.style.width = '78px';
                m.addEventListener('input', function () { it.minutes = Math.max(1, parseInt(m.value, 10) || 1); total(); });
                var x = el('button', 'pl-icon-btn');
                x.type = 'button';
                x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                x.addEventListener('click', function () { data.items.splice(idx, 1); drawItems(); total(); });
                row.appendChild(n); row.appendChild(m); row.appendChild(x);
                itemsBox.appendChild(row);
            });
        }
        drawItems(); total();

        var addIt = el('button', 'pl-addlist');
        addIt.type = 'button';
        addIt.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        addIt.appendChild(el('span', null, T('planner.aggiungi_passaggio')));
        addIt.addEventListener('click', function () { data.items.push({ name: '', minutes: 5 }); drawItems(); total(); });
        fi.appendChild(addIt);
        body.appendChild(fi);

        var save = el('button', 'pl-btn pl-btn-primary', isNew ? T('planner.crea_routine') : T('comune.salva'));
        save.type = 'button';
        save.style.marginTop = '14px';
        save.addEventListener('click', function () {
            api('routine_save', {
                id: data.id, name: name.value, color: data.color,
                items: data.items.filter(function (i) { return i.name.trim(); })
            }).then(function (res) {
                if (!res.ok) { toast(res.message || T('planner.non_salvata')); return; }
                S.routines = res.routines;
                openSettings('routines');
            });
        });
        body.appendChild(save);
    });
}
