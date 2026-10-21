/* planner/js/11_google_calendar.js — Google Calendar e la ripartizione dei calendari.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Google Calendar                                                     */
/* ------------------------------------------------------------------ */
function gcall(action, write, dati) {
    var opt = { method: write ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.STANDOUT_CSRF || S.csrf } };
    if (write) opt.headers['Content-Type'] = 'application/json';
    if (write) opt.body = JSON.stringify(dati || {});
    return fetch('google_calendar.php?ajax=' + action, opt)
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, message: T('planner.connessione_non_riuscita') }; });
}

/* `onStato` (facoltativo) riceve lo stato appena arriva: la sezione delle
   impostazioni lo scrive nella sua intestazione. Resta attaccato al riquadro,
   cosi' anche i ridisegni dopo Sincronizza o Scollega lo aggiornano. */
function drawGoogle(box, onStato) {
    if (onStato) box._onStato = onStato;
    gcall('status').then(function (st) {
        box.innerHTML = '';
        if (box._onStato) box._onStato(st);
        if (!st.ok) {
            box.appendChild(el('div', 'pl-row-sub', T('planner.stato_non_disponibile')));
            return;
        }
        if (!st.configured) {
            box.appendChild(el('div', 'pl-row-sub',
                T('planner.non_ancora_configurato_su_questo')));
            return;
        }
        if (!st.connected) {
            box.appendChild(el('div', 'pl-row-sub',
                T('planner.collegando_il_tuo_account_gli')));
            var connect = el('button', 'pl-btn pl-btn-primary', T('planner.collega_google_calendar'));
            connect.type = 'button';
            connect.style.marginTop = '10px';
            connect.addEventListener('click', function () {
                window.location.href = 'google_calendar.php?action=connect';
            });
            box.appendChild(connect);
            return;
        }

        var info = el('div', 'pl-row-sb');
        var left = el('div');
        left.appendChild(el('div', 'pl-row-lab', st.email || T('planner.account_collegato')));
        var when = st.last_sync ? new Date(st.last_sync.replace(' ', 'T')) : null;
        left.appendChild(el('div', 'pl-row-sub', when
            ? T('planner.ultima_sync') + when.getDate() + ' ' + minuscolo(MESI[when.getMonth()]) +
              ', ' + pad(when.getHours()) + ':' + pad(when.getMinutes())
            : T('planner.mai_sincronizzato')));
        info.appendChild(left);

        /* piccoli e accanto all'account, non grandi in fondo alla pagina:
           presi per sbaglio potevano sembrare il pulsante di salvataggio */
        var actsSmall = el('div', 'pl-row');
        actsSmall.style.flexShrink = '0';
        var sync = el('button', 'pl-mini-btn pl-primary', T('planner.sincronizza'));
        sync.type = 'button';
        sync.addEventListener('click', function () {
            sync.textContent = '…';
            sync.disabled = true;
            runSync().then(function () { drawGoogle(box); });
        });
        var off = el('button', 'pl-mini-btn', T('planner.scollega'));
        off.type = 'button';
        off.addEventListener('click', function () {
            alertBox(T('planner.scollego_google_calendar'), T('planner.gli_eventi_gia_scritti_restano'), [
                { label: T('planner.annulla') },
                { label: T('planner.scollega'), kind: 'danger', onClick: function () {
                    gcall('disconnect', true).then(function () { drawGoogle(box); toast(T('planner.google_calendar_scollegato')); });
                } }
            ]);
        });
        actsSmall.appendChild(off); actsSmall.appendChild(sync);
        info.appendChild(actsSmall);
        box.appendChild(info);

        box.appendChild(el('div', 'pl-row-sub',
            (st.calendar
                ? T('planner.calendario_dedicato', {nome: st.calendar})
                : '')
            + T('planner.si_allinea_da_a', {prima: st.window.back, dopo: st.window.forward})));

        /* Cosa mandare, e dove. Il calendario principale ha la precedenza:
           se scegli di mandare là gli importanti, quelli non vanno nel
           dedicato ma solo nel principale. */
        var modes = st.modes || { dedicated: 'all', primary: 'none' };

        var f1 = el('div', 'pl-field');
        f1.style.marginTop = '16px';
        f1.appendChild(el('label', 'pl-label', T('planner.nel_calendario_dedicato')));
        f1.appendChild(select([
            ['all', T('planner.tutti_gli_eventi')],
            ['high', T('planner.solo_importanti_e_importantissimi')],
            ['highest', T('planner.solo_importantissimi')],
            ['none', T('planner.nessuno')]
        ], modes.dedicated, function (v) {
            S.settings.gc_dedicated = v;
            api('settings_save', { gc_dedicated: v }).then(function () {
                toast(T('planner.scelta_salvata_si_allinea_al'));
                drawGoogle(box);
            });
        }));
        box.appendChild(f1);

        var f2 = el('div', 'pl-field');
        f2.appendChild(el('label', 'pl-label', T('planner.nel_calendario_principale_di_google')));
        f2.appendChild(select([
            ['none', T('planner.nessuno')],
            ['high', T('planner.importanti_e_importantissimi')],
            ['highest', T('planner.solo_importantissimi')]
        ], modes.primary, function (v) {
            S.settings.gc_primary = v;
            api('settings_save', { gc_primary: v }).then(function () {
                toast(T('planner.scelta_salvata_si_allinea_al'));
                drawGoogle(box);
            });
        }));
        f2.appendChild(el('div', 'pl-row-sub',
            T('planner.quello_che_mandi_qui_non')));
        box.appendChild(f2);

        /* i due elenchi dei calendari, che si caricano da soli */
        var ponte = el('div', 'pl-gc-ponte');
        box.appendChild(ponte);
        drawCalendarBridge(ponte);

        /* Riparazione, in fondo e con l'aria di quello che è: un attrezzo, non
           una funzione di tutti i giorni. Serve dopo un pasticcio — di solito
           uno scollegamento e ricollegamento — e toglie da Google le copie in
           più che abbiamo creato noi. */
        var fr = el('div', 'pl-field');
        fr.style.marginTop = '22px';
        fr.appendChild(el('label', 'pl-label', T('planner.se_qualcosa_e_andato_storto')));
        var rip = el('button', 'pl-btn', T('planner.cerca_e_togli_i_doppioni'));
        rip.type = 'button';
        rip.style.width = '100%';
        rip.addEventListener('click', function () {
            alertBox(T('planner.cercare_i_doppioni'),
                T('planner.spiega_doppioni'), [
                { label: T('planner.annulla') },
                { label: T('planner.cerca'), kind: 'primary', onClick: function () {
                    rip.disabled = true;
                    rip.textContent = T('planner.sto_guardando');
                    gcall('repair', true).then(function (r) {
                        rip.disabled = false;
                        rip.textContent = T('planner.cerca_e_togli_i_doppioni');
                        if (!r || !r.ok) { toast((r && r.message) || T('planner.non_riuscito')); return; }
                        toast(r.removed ? r.removed + T('planner.copie_tolte') : T('planner.nessun_doppione_trovato'));
                    });
                } }
            ]);
        });
        fr.appendChild(rip);
        /* Il marcatore di versione: senza, non c'è modo di sapere da fuori se
           il file che risponde è quello caricato per ultimo — ed è una
           domanda che ci si fa spesso quando qualcosa non cambia. */
        if (st.build) fr.appendChild(el('div', 'pl-row-sub', T('planner.versione', {v: st.build})));
        box.appendChild(fr);
    });
}

/* ------------------------------------------------------------------ */
/* I calendari di Google e i miei: chi va dove                         */
/* ------------------------------------------------------------------ */
/* Due elenchi e non uno, perché sono due domande diverse e vanno tenute
   separate: «gli eventi di questo calendario di Google dove li metto?» e
   «gli eventi di questo mio calendario dove li mando?». Metterle sulla
   stessa riga sembrava più compatto ma faceva credere che fossero la stessa
   cosa al contrario, e non lo sono — si può leggere un calendario senza
   scriverci, e viceversa. */
function drawCalendarBridge(host) {
    host.innerHTML = '';
    host.appendChild(el('div', 'pl-row-sub', T('planner.cerco_i_tuoi_calendari_su')));

    gcall('calendars').then(function (r) {
        host.innerHTML = '';
        if (!r || !r.ok) {
            host.appendChild(el('div', 'pl-row-sub', r && r.message ? r.message : T('planner.elenco_non_disponibile')));
            return;
        }
        var miei = r.mine || [];

        /* --- da Google a qui --- */
        var s1 = el('div', 'pl-field');
        s1.style.marginTop = '20px';
        s1.appendChild(el('label', 'pl-label', T('planner.dai_calendari_di_google')));
        s1.appendChild(el('div', 'pl-row-sub', T('planner.spiega_da_google')));

        (r.google || []).forEach(function (g) {
            var riga = el('div', 'pl-gc-riga');
            var testa = el('div', 'pl-gc-nome');
            var pallino = el('span', 'pl-gc-dot');
            pallino.style.background = g.color || 'var(--hair-2)';
            testa.appendChild(pallino);
            testa.appendChild(el('span', null, g.name || g.ref));
            /* il calendario che scriviamo noi si segnala: cambiarne la
               destinazione è legittimo, ma bisogna sapere che quelli sono i
               propri eventi che tornano indietro */
            if (g.is_ours) testa.appendChild(el('span', 'pl-gc-tag', T('planner.nostro')));
            else if (g.is_primary) testa.appendChild(el('span', 'pl-gc-tag', T('planner.principale')));
            /* Quanti ne sono già entrati da lì. È la risposta alla domanda che
               uno si fa guardando l'elenco — «e allora questi da dove sono
               arrivati?» — e dice anche cosa si sta per buttare scegliendo
               «nessuno». */
            if (g.entrati) testa.appendChild(el('span', 'pl-gc-conta', T('planner.n_qui', {n: g.entrati})));
            riga.appendChild(testa);

            var opzioni = [['0', T('planner.nessuno')]];
            miei.forEach(function (m) { opzioni.push([String(m.id), m.name]); });
            riga.appendChild(select(opzioni, String(g.calendar_id || 0), function (v) {
                var dest = parseInt(v, 10) || 0;
                if (dest === 0 && (g.calendar_id || g.entrati)) {
                    /* si chiede conferma solo quando c'è davvero qualcosa da
                       perdere: è l'unica scelta di questa pagina che cancella
                       roba già entrata, e non si torna indietro */
                    alertBox(T('planner.togliere_calendario', {nome: (g.name || T('planner.questo_calendario'))}),
                (g.entrati ? T('planner.n_eventi_spariranno', {n: g.entrati}) : T('planner.eventi_spariranno')) + T('planner.anche_passati'), [
                        { label: T('planner.annulla'), onClick: function () { drawCalendarBridge(host); } },
                        { label: T('planner.togli_2'), kind: 'danger', onClick: function () { mapSet(g, 0, host); } }
                    ]);
                    return;
                }
                mapSet(g, dest, host);
            }));
            s1.appendChild(riga);
        });
        host.appendChild(s1);

        /* --- da qui a Google --- */
        var s2 = el('div', 'pl-field');
        s2.appendChild(el('label', 'pl-label', T('planner.dai_tuoi_calendari_a_google')));
        s2.appendChild(el('div', 'pl-row-sub', T('planner.spiega_verso_google')));

        var refs = [['', T('planner.come_impostato_sopra')]];
        /* i calendari di sola lettura non compaiono: Google rifiuta sempre chi
           prova a scriverci, e una scelta che fallirà comunque è peggio che
           una scelta in meno */
        (r.google || []).forEach(function (g) {
            if (g.can_write) refs.push([g.ref, g.name || g.ref]);
        });

        miei.forEach(function (m) {
            var riga = el('div', 'pl-gc-riga');
            var testa = el('div', 'pl-gc-nome');
            var p2 = el('span', 'pl-gc-dot');
            /* il principale non ha colore: si mostra un cerchietto vuoto
               invece di inventargliene uno */
            if (m.color) p2.style.background = m.color;
            else p2.classList.add('pl-gc-dot-vuoto');
            testa.appendChild(p2);
            testa.appendChild(el('span', null, m.name));
            riga.appendChild(testa);
            riga.appendChild(select(refs, m.push_ref || '', function (v) {
                gcall('push_set', true, { calendar_id: m.id, ref: v }).then(function (rr) {
                    if (!rr || !rr.ok) { toast((rr && rr.message) || T('planner.scelta_non_salvata')); return; }
                    toast(T('planner.scelta_salvata_si_allinea_al'));
                });
            }));
            s2.appendChild(riga);
        });
        host.appendChild(s2);
    });
}

function mapSet(g, dest, host) {
    gcall('map_set', true, { ref: g.ref, calendar_id: dest }).then(function (rr) {
        if (!rr || !rr.ok) { toast((rr && rr.message) || T('planner.scelta_non_salvata')); drawCalendarBridge(host); return; }
        if (rr.removed) toast(T('planner.n_eventi_tolti', {n: rr.removed}));
                    else if (rr.moved) toast(T('planner.n_eventi_spostati', {n: rr.moved}));
        else toast(T('planner.scelta_salvata'));
        /* la lettura riparte da capo per quel calendario, quindi conviene
           allineare subito invece di far aspettare il giro automatico */
        S.weekCache = {};
        runSync(true).then(function () { drawCalendarBridge(host); });
    });
}

/* Sincronizzazione automatica.
   Prima partiva solo premendo il tasto nelle impostazioni: gli eventi creati
   dopo restavano qui e su Google non comparivano mai. Ora ogni modifica ne
   programma una silenziosa poco dopo, e una parte anche all'apertura della
   pagina se l'ultima è vecchia. */
var syncTimer = null;
function scheduleSync() {
    if (!S.settings.google_sync) return;
    if (syncTimer) clearTimeout(syncTimer);
    /* si aspetta qualche secondo: modificando più eventi di fila
       parte una sola sincronizzazione invece di una per ciascuno */
    syncTimer = setTimeout(function () { syncTimer = null; runSync(true); }, 6000);
}

function runSync(quiet) {
    return gcall('sync', true).then(function (r) {
        if (!r.ok) {
            if (quiet && r.error !== 'reauth') return r;   // in silenzio: riproverà
            if (r.error === 'reauth') {
                alertBox(T('planner.serve_ricollegare_google'), T('planner.il_permesso_e_scaduto_o'), [
                    { label: T('planner.piu_tardi') },
                    { label: T('planner.ricollega'), kind: 'primary', onClick: function () {
                        window.location.href = 'google_calendar.php?action=connect';
                    } }
                ]);
            } else {
                toast(r.message || T('planner.sincronizzazione_non_riuscita'));
            }
            return r;
        }
        var arrived = r.pulled + r.removed_here;
        var sent = r.created + r.updated + r.removed_there;
        if (!quiet) {
            if (!arrived && !sent) toast(T('planner.gia_tutto_allineato'));
            else toast(T('planner.novita_da_google', {ricevuti: arrived, mandati: sent}));
            if (r.failed) toast(T('planner.eventi_non_accettati', {n: r.failed}));
        }
        S.weekCache = {};
        loadWeek(monday(S.focusDate), true).then(function () { if (S.view === 'month') renderMonth(); });
        return r;
    });
}

/* messaggio al ritorno dal consenso Google */
(function googleReturn() {
    var m = /[?&]google=([a-z]+)/.exec(window.location.search);
    if (!m) return;
    if (history.replaceState) history.replaceState(null, '', 'planner.php');
    if (m[1] === 'collegato') {
        toast(T('planner.google_calendar_collegato_sto_allineando'));
        setTimeout(runSync, 600);
    } else if (m[1] === 'annullato') {
        toast(T('planner.collegamento_annullato'));
    } else {
        toast(T('planner.collegamento_non_riuscito_riprova_dalle'));
    }
})();
