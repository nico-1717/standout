<?php
/* =====================================================================
   STANDOUT — PLANNER: API JSON
   planner_api.php?ajax=<azione>   (GET per leggere, POST per scrivere)
   Gli stessi endpoint che prima rispondevano da planner.php?ajax=...: la
   pagina li chiama qui (planner.js, funzione api()). planner.php continua
   a girare le richieste ?ajax= a questo file, per le schede rimaste aperte
   con il JavaScript vecchio.
   Ogni query passa da security_core.php (prepared statement) e ogni riga
   toccata viene prima verificata come appartenente all'utente.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/planner/planner_lib.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

/* =====================================================================
   API
   ===================================================================== */
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 40) : '';

if ($ajax !== '') {
    $isWrite = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isWrite) sec_guard_write($current_user_id, 'planner', 0, 400, 60);
    $settings = pl_settings($current_user_id);

    /* ---------------- lettura di un intervallo ---------------- */
    if ($ajax === 'range') {
        $from = sec_date(sec_in('from'), pl_monday(date('Y-m-d')));
        $to   = sec_date(sec_in('to'), pl_add_days($from, 6));
        if (strtotime($to) < strtotime($from)) $to = $from;
        if ((strtotime($to) - strtotime($from)) > 86400 * 70) $to = pl_add_days($from, 70);

        $weekStart = pl_monday($from);
        sec_json([
            'ok'         => true,
            'from'       => $from, 'to' => $to,
            'events'     => pl_events_range($current_user_id, $from, $to),
            'priorities' => pl_priorities_range($current_user_id, $from, $to),
            'pills_due'  => pl_pills_due($current_user_id, date('Y-m-d'), date('H:i:s')),
            'week'       => pl_week_row($current_user_id, $weekStart, $settings),
            'lists'      => pl_week_lists($current_user_id, $weekStart),
            'diary'      => pl_diary_dates($current_user_id, $from, $to),
        ]);
    }

    /* ---------------- habit di un giorno ---------------- */
    if ($ajax === 'habits_day') {
        $date = sec_date(sec_in('date'), date('Y-m-d'));
        sec_json([
            'ok' => true, 'date' => $date,
            'habits' => pl_habits_for_date($current_user_id, $date),
            'weekly' => pl_habits_weekly($current_user_id, $date),
            'rings'  => pl_rings($current_user_id, $date),
        ]);
    }

    /* ---------------- routine: passaggi da riattaccare ----------------
       Togliendo o accorciando un passaggio, quelli dopo restavano dov'erano:
       il blocco continuava a durare quanto la routine originale, con un buco
       dentro. Qui i passaggi si rimettono in fila a partire dal primo, ognuno
       con la sua durata. Ogni UPDATE porta `user_id` nella WHERE, quindi non
       si può ricompattare la routine di un altro. */
    if ($ajax === 'routine_recompact') {
        $group = sec_str(sec_in('routine_group'), 32);
        if (!preg_match('/^[A-Za-z0-9]{1,32}$/', $group)) {
            sec_die_json(t('planner.msg_routine_non_trovata'), 404, 'not_found');
        }
        $date = sec_date(sec_in('date'), date('Y-m-d'));
        $passi = sec_all("SELECT id, start_time, end_time FROM planner_events
                          WHERE user_id = ? AND event_date = ? AND routine_group = ?
                          ORDER BY start_time, id", [$current_user_id, $date, $group], 'iss');
        if (empty($passi)) sec_json(['ok' => true, 'events' => []]);

        $cursore = pl_minutes($passi[0]['start_time']);
        foreach ($passi as $p) {
            $durata = max(1, pl_minutes($p['end_time']) - pl_minutes($p['start_time']));
            $inizio = min(1439, $cursore);
            $fine   = min(1439, $inizio + $durata);
            if ($inizio !== pl_minutes($p['start_time']) || $fine !== pl_minutes($p['end_time'])) {
                sec_q("UPDATE planner_events SET start_time = ?, end_time = ?
                       WHERE id = ? AND user_id = ?",
                      [pl_time($inizio), pl_time($fine), (int)$p['id'], $current_user_id], 'ssii');
            }
            $cursore = $fine;
        }

        $rows = sec_all("SELECT e.*, p.name AS project_name, p.color AS project_color,
                                h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
                         FROM planner_events e
                         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
                         LEFT JOIN habits h ON h.id = e.habit_id AND h.user_id = e.user_id
                         LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
                         WHERE e.user_id = ? AND e.event_date = ? AND e.routine_group = ?
                         ORDER BY e.start_time, e.id", [$current_user_id, $date, $group], 'iss');
        $out = [];
        foreach ($rows as $r) { $out[] = pl_event_out($r); }
        sec_json(['ok' => true, 'events' => pl_attach_guests($current_user_id, $out)]);
    }

    /* ---------------- ricerca del luogo (mappe) ----------------
       Passa dal server e non dal browser per gli stessi motivi del meteo:
       l'indirizzo IP dell'utente non arriva al servizio esterno, la chiave
       resta qui e le risposte si possono tenere in cache. Il limite per
       utente, la cache e il minimo di tre lettere servono a non consumare
       chiamate per niente: si cerca a frase quasi finita, non a ogni lettera. */
    if ($ajax === 'place_search') {
        sec_guard_write($current_user_id, 'geocode', 0, 40, 60);
        $q = sec_str(sec_in('q'), 120);
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q, 'UTF-8') < 3) sec_json(['ok' => true, 'places' => []]);
        sec_json(['ok' => true, 'places' => pl_place_lookup($q)]);
    }

    /* ---------------- coordinate del posto scelto ----------------
       Secondo passo della ricerca: parte una volta sola, sul posto che è stato
       davvero toccato, non su tutti quelli proposti. È qui che si consuma la
       chiamata "cara" delle due, e infatti chiede solo posizione e indirizzo. */
    if ($ajax === 'place_details') {
        sec_guard_write($current_user_id, 'geocode', 0, 40, 60);
        $ref = sec_str(sec_in('ref'), 300);
        if ($ref === '') sec_die_json(t('planner.msg_posto_non_indicato'), 422, 'empty');
        $punto = pl_place_punto($ref);
        if (!$punto) sec_die_json(t('planner.msg_posizione_non_trovata'), 404, 'not_found');
        sec_json(['ok' => true, 'place' => $punto]);
    }

    /* ---------------- rubrica: salva un posto ----------------
       Le coordinate arrivano già validate dal campo del luogo, ma qui si
       rivalidano lo stesso: quello che entra dal browser non è mai buono per
       partito preso, nemmeno quando l'abbiamo mandato noi un attimo prima. */
    if ($ajax === 'place_book_save') {
        if (!pl_places_c_e()) sec_die_json(t('planner.msg_rubrica_non_installata'), 400, 'no_table');
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $label = sec_str(sec_in('label'), 60);
        /* le parole d'aggancio si confrontano in minuscolo: chi le scrive non
           deve stare attento alle maiuscole */
        $keys  = mb_strtolower(sec_str(sec_in('keywords'), 255), 'UTF-8');
        $pname = sec_str(sec_in('name'), 255);
        $addr  = sec_str(sec_in('address'), 255);
        $ref   = sec_str(sec_in('ref'), 255);
        $lat   = sec_float(sec_in('lat'), -90, 90, 999);
        $lon   = sec_float(sec_in('lon'), -180, 180, 999);
        if ($lat === 999.0 || $lon === 999.0) { $lat = null; $lon = null; }
        if ($label === '') sec_die_json(t('planner.msg_manca_nome_posto'), 422, 'empty');

        if ($id > 0) {
            if (!sec_owns('planner_places', $id, $current_user_id)) {
                sec_die_json(t('planner.msg_posto_non_trovato'), 404, 'not_found');
            }
            sec_q("UPDATE planner_places
                      SET label=?, keywords=?, place_name=?, address=?, place_lat=?, place_lon=?, place_ref=?
                    WHERE id=? AND user_id=?",
                  [$label, $keys, $pname, $addr, $lat, $lon, $ref, $id, $current_user_id], 'ssssddsii');
        } else {
            sec_q("INSERT INTO planner_places
                      (user_id, label, keywords, place_name, address, place_lat, place_lon, place_ref)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                  [$current_user_id, $label, $keys, $pname, $addr, $lat, $lon, $ref], 'issssdds');
        }
        sec_json(['ok' => true, 'places' => pl_places($current_user_id)]);
    }

    /* ---------------- rubrica: togli un posto ---------------- */
    if ($ajax === 'place_book_delete') {
        if (!pl_places_c_e()) sec_die_json(t('planner.msg_rubrica_non_installata'), 400, 'no_table');
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_places', $id, $current_user_id)) {
            sec_die_json(t('planner.msg_posto_non_trovato'), 404, 'not_found');
        }
        sec_q("DELETE FROM planner_places WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'places' => pl_places($current_user_id)]);
    }

    /* ---------------- salvataggio evento ---------------- */
    if ($ajax === 'event_save') {
        $id      = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $title   = sec_str(sec_in('title'), 180);
        $notes   = sec_text(sec_in('notes'), 4000);
        $luogo   = sec_str(sec_in('location'), 255);
        $date    = sec_date(sec_in('date'), date('Y-m-d'));
        $allDay  = sec_bool(sec_in('all_day'));
        $start   = sec_time(sec_in('start'), '09:00:00');
        $end     = sec_time(sec_in('end'), '10:00:00');

        /* La data di fine. Tre regole, tutte pensate perché un client
           sbagliato (o modificato a mano) non possa lasciare righe assurde:
             - prima dell'inizio non ha senso: si riporta all'inizio;
             - uguale all'inizio si salva come NULL, che è il modo in cui il
               database dice «un giorno solo» — così le righe di sempre e
               quelle nuove hanno la stessa forma;
             - più lunga del massimo si tronca: un evento entra in tutte le
               caselle che attraversa, e uno da diecimila giorni comparirebbe
               ovunque per trent'anni. */
        $endDate = sec_date(sec_in('end_date'), null);
        if ($endDate !== null) {
            if ($endDate < $date) $endDate = $date;
            $limite = date('Y-m-d', strtotime($date . ' +' . (PL_MAX_GIORNI_EVENTO - 1) . ' day'));
            if ($endDate > $limite) $endDate = $limite;
            if ($endDate === $date) $endDate = null;
        }
        /* Il controllo «la fine viene prima dell'inizio» vale sugli ORARI solo
           dentro la stessa giornata: dal 3 alle 22:00 al 4 alle 02:00 è un
           evento che scavalca la mezzanotte, non un errore da correggere. */
        if ($endDate === null && pl_minutes($end) <= pl_minutes($start) && !$allDay) {
            $end = pl_time(min(1439, pl_minutes($start) + 30));
        }
        $catId   = sec_int(sec_in('category_id'), 0, PHP_INT_MAX, 0);
        $calId   = sec_int(sec_in('calendar_id'), 0, PHP_INT_MAX, 0);
        if ($calId > 0 && !sec_owns('planner_calendars', $calId, $current_user_id)) $calId = 0;
        /* Chi non sceglie finisce nel principale, e ci finisce *scritto*: un
           calendario nullo vorrebbe dire un evento che il filtro non può né
           accendere né spegnere, cioè fuori da ogni calendario. */
        if ($calId === 0) $calId = (int)(pl_default_calendar($current_user_id) ?: 0);
        $projId  = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        $habitId = sec_int(sec_in('habit_id'), 0, PHP_INT_MAX, 0);
        $imp     = sec_enum(sec_in('importance'), ['normal', 'high', 'highest'], 'normal');
        $color   = sec_in('color') ? sec_color(sec_in('color')) : null;
        $type    = sec_enum(sec_in('type'), ['event', 'task'], 'event');
        $notify  = sec_enum(sec_in('notify'), ['none', 'at_time', 'm30', 'h1', 'd1', 'custom'], 'h1');
        $notifyMin = ['none' => 0, 'at_time' => 0, 'm30' => 30, 'h1' => 60, 'd1' => 1440];
        $nMin    = $notify === 'custom' ? sec_int(sec_in('notify_min'), 0, 40320, 60) : ($notifyMin[$notify] ?? 60);
        $rep     = sec_enum(sec_in('repeat'), ['none', 'daily', 'weekly', 'monthly', 'yearly', 'custom'], 'none');
        $repInt  = sec_int(sec_in('repeat_interval'), 1, 365, 1);
        $repUnit = sec_enum(sec_in('repeat_unit'), ['day', 'week', 'month', 'year'], 'week');

        /* Le due date facoltative passano dal normalizzatore: arrivano vuote
           tutte le volte che l'evento non si ripete, e su PHP 8 la stringa
           vuota dentro strtotime era il Fatal Error del salvataggio. */
        $repUntil = pl_date_or_null(sec_in('repeat_until'));
        $origDate = pl_date_or_null(sec_in('original_date'));

        $editMode = sec_str((string)(sec_in('edit_mode') ?? ''), 10);
        if ($editMode !== 'one' && $editMode !== 'all') $editMode = '';

        /* Luogo e lucchetto: la loro lettura era andata persa nel refactoring
           e le variabili finivano nei parametri della query senza esistere.
           È questo che faceva rispondere «Errore nella richiesta al database»
           a ogni salvataggio. */
        $placeName = sec_str(sec_in('place_name'), 255);
        $placeRef  = sec_str(sec_in('place_ref'), 255);
        $placeLat  = sec_float(sec_in('place_lat'), -90, 90, 999);
        $placeLon  = sec_float(sec_in('place_lon'), -180, 180, 999);
        // le coordinate hanno senso solo in coppia: mezza posizione non è una posizione
        if ($placeLat === 999.0 || $placeLon === 999.0) { $placeLat = null; $placeLon = null; }
        $durLock   = sec_bool(sec_in('duration_locked')) ? 1 : 0;

        // proprietà delle chiavi esterne
        if ($catId > 0 && !sec_owns('planner_categories', $catId, $current_user_id)) $catId = 0;
        if ($projId > 0 && !sec_owns('projects', $projId, $current_user_id)) $projId = 0;
        if ($habitId > 0 && !sec_owns('habits', $habitId, $current_user_id)) $habitId = 0;

        // categoria dedotta dal titolo, se l'utente non ne ha scelta una
        if ($catId === 0 && $projId === 0 && $title !== '') {
            $auto = pl_auto_category($current_user_id, $title);
            if ($auto !== null) $catId = $auto;
        }
        
        // calendario dedotto dal titolo e impostazione dei default
        if ($calId === 0 && $title !== '') {
            $autoCal = pl_auto_calendar($current_user_id, $title);
            if ($autoCal !== null) {
                $calId = $autoCal['id'];
                if ($id === 0) {
                    if (!pl_inviato('repeat') && $autoCal['def_repeat'] !== 'none') $rep = $autoCal['def_repeat'];
                    if (!pl_inviato('notify') && $autoCal['def_notify'] !== 'none') {
                        $notify = $autoCal['def_notify'];
                        $nMin = $notifyMin[$notify] ?? 60;
                    }
                }
            }
        }
        
        if ($title === '') $title = $habitId > 0 ? t('planner.tit_abitudine') : t('planner.tit_senza_titolo');

        /* --- CALCOLO DELLA REGOLA RRULE --- */
        $rrule = null;
        if ($rep !== 'none') {
            $freqMap = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];
            $freq = $freqMap[$rep] ?? null;
            $interval = 1;
            if ($rep === 'custom') {
                $customMap = ['day' => 'DAILY', 'week' => 'WEEKLY', 'month' => 'MONTHLY', 'year' => 'YEARLY'];
                $freq = $customMap[$repUnit] ?? 'WEEKLY';
                $interval = max(1, $repInt);
            }
            if ($freq) {
                $rrule = "FREQ=$freq";
                if ($interval > 1) $rrule .= ";INTERVAL=$interval";
                // FIX 2: Calcola la data UNTIL solo se è validissima, prevenendo il Fatal Error
                if ($repUntil) {
                    $ts = strtotime($repUntil);
                    if ($ts) $rrule .= ";UNTIL=" . date('Ymd\T235959\Z', $ts);
                }
            }
        }

        $params = [
            /* end_date sta subito dopo event_date, nell'ordine in cui compare
               nelle colonne: i parametri di queste query si contano a occhio, e
               un ordine che segue quello delle colonne e' l'unico modo di
               riuscirci senza sbagliare. */
            $title, $notes, $luogo, $date, $endDate, $start, $end, $allDay ? 1 : 0,
            $catId > 0 ? $catId : null, $calId > 0 ? $calId : null,
            $projId > 0 ? $projId : null, $habitId > 0 ? $habitId : null,
            $imp, $color, $type, $notify, $nMin, $rep, $repInt, $repUnit, $repUntil,
            $placeName !== '' ? $placeName : null,
            $placeLat, $placeLon,
            $placeRef !== '' ? $placeRef : null,
            $durLock,
            $rrule
        ];

        if ($id > 0) {
            if (!sec_owns('planner_events', $id, $current_user_id)) {
                sec_die_json(t('api.evento_non_trovato'), 404, 'not_found');
            }
            
            // SE STIAMO CREANDO UN'ECCEZIONE SINGOLA
            if ($editMode === 'one' && $origDate) {
                $params_exc = [
                    $title, $notes, $luogo, $date, $endDate, $start, $end, $allDay ? 1 : 0,
                    $catId > 0 ? $catId : null, $calId > 0 ? $calId : null,
                    $projId > 0 ? $projId : null, $habitId > 0 ? $habitId : null,
                    $imp, $color, $type, $notify, $nMin, 
                    'none', 1, 'week', null, // L'eccezione isolata non si ripete più a sua volta
                    $placeName !== '' ? $placeName : null, $placeLat, $placeLon, $placeRef !== '' ? $placeRef : null,
                    $durLock, null, $current_user_id, $id, $origDate
                ];
                
                sec_q("INSERT INTO planner_events
                       (title, notes, location, event_date, end_date, start_time, end_time, is_all_day, category_id, calendar_id,
                        project_id, habit_id, importance, color, item_type, notify_type, notify_minutes,
                        repeat_type, repeat_interval, repeat_unit, repeat_until,
                        place_name, place_lat, place_lon, place_ref, duration_locked, rrule, user_id, series_id, original_date)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params_exc);
                $newId = sec_insert_id();
            } else {
                // MODIFICA NORMALE O "TUTTA LA SERIE"
                $params[] = $id; $params[] = $current_user_id;
                sec_q("UPDATE planner_events SET title=?, notes=?, location=?, event_date=?, end_date=?, start_time=?, end_time=?, is_all_day=?,
                              category_id=?, calendar_id=?, project_id=?, habit_id=?, importance=?, color=?, item_type=?,
                              notify_type=?, notify_minutes=?, repeat_type=?, repeat_interval=?, repeat_unit=?, repeat_until=?,
                              place_name=?, place_lat=?, place_lon=?, place_ref=?, duration_locked=?, rrule=?
                       WHERE id=? AND user_id=?", $params);
                $newId = $id;
            }
        } else {
            $params[] = $current_user_id;
            sec_q("INSERT INTO planner_events
                   (title, notes, location, event_date, end_date, start_time, end_time, is_all_day, category_id, calendar_id,
                    project_id, habit_id, importance, color, item_type, notify_type, notify_minutes,
                    repeat_type, repeat_interval, repeat_unit, repeat_until,
                    place_name, place_lat, place_lon, place_ref, duration_locked, rrule, user_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params);
            $newId = sec_insert_id();
            if ($rep !== 'none') {
                sec_q("UPDATE planner_events SET series_id = ? WHERE id = ? AND user_id = ?",
                      [$newId, $newId, $current_user_id], 'iii');
                /* La chiamata a pl_materialize_repeats è stata volutamente rimossa da qui */
            }
        }

        /* Qui sotto c'era un SECONDO blocco di salvataggio, il vecchio, rimasto
           in piedi accanto a quello nuovo. Girava comunque, e riaggiungeva
           $id e $current_user_id a un $params a cui erano già stati aggiunti:
           ventinove parametri contro ventisei segnaposto, cioè «Errore nella
           richiesta al database» a ogni singolo salvataggio. Rimosso. */
        /* Invitati: si riscrive l'elenco da capo. Il nome viene copiato adesso,
           così un evento passato ricorda come si chiamava il contatto allora. */
        $guests = sec_in('guests');
        if (is_array($guests)) {
            sec_q("DELETE FROM event_guests WHERE event_id = ? AND user_id = ?", [$newId, $current_user_id], 'ii');
            foreach (array_slice($guests, 0, 30) as $g) {
                $mail = sec_email(is_array($g) ? ($g['email'] ?? '') : $g);
                if (!$mail) continue;
                $nome = is_array($g) ? sec_str($g['name'] ?? '', 80) : '';
                if ($nome === '') {
                    $c = sec_one("SELECT name FROM contacts WHERE user_id = ? AND email = ?", [$current_user_id, $mail], 'is');
                    $nome = $c['name'] ?? null;
                }
                @sec_q("INSERT IGNORE INTO event_guests (event_id, user_id, email, name) VALUES (?,?,?,?)",
                       [$newId, $current_user_id, $mail, $nome !== '' ? $nome : null], 'iiss');
            }
        }

        /* un task appena salvato è un'attività: se non ce l'ha ancora, adesso
           ha la sua riga in `todos`, già collegata (vedi pl_task_to_todo) */
        pl_task_to_todo($current_user_id, $newId);

        $row = sec_one("SELECT e.*, p.name AS project_name, p.color AS project_color,
                               h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
                        FROM planner_events e
                        LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
                        LEFT JOIN habits h ON h.id = e.habit_id AND h.user_id = e.user_id
                        LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
                        WHERE e.id = ? AND e.user_id = ?", [$newId, $current_user_id], 'ii');
        $uscita = $row ? pl_event_out($row) : null;
        if ($uscita) { $tmp = pl_attach_guests($current_user_id, [$uscita]); $uscita = $tmp[0]; }
        sec_json(['ok' => true, 'event' => $uscita]);
    }

    /* ---------------- spostamento rapido (drag) ---------------- */
    if ($ajax === 'event_move') {
        $id    = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $date  = sec_date(sec_in('date'), null);
        $start = sec_time(sec_in('start'), null);
        if ($id === 0 || $start === null || !sec_owns('planner_events', $id, $current_user_id)) {
            sec_die_json(t('api.evento_non_trovato'), 404, 'not_found');
        }
        $ev = sec_one("SELECT start_time, end_time, event_date, end_date FROM planner_events WHERE id = ? AND user_id = ?", [$id, $current_user_id], 'ii');
        $dur = pl_minutes($ev['end_time']) - pl_minutes($ev['start_time']);
        $newStart = pl_minutes($start);
        if ($newStart + $dur > 1439) $newStart = max(0, 1439 - $dur);

        /* Trascinare un evento lungo lo sposta tutto: la fine segue l'inizio
           mantenendo la stessa durata in giorni. Lasciando end_date ferma, un
           evento dal 3 al 5 trascinato al 10 sarebbe diventato «dal 10 al 5»,
           cioè niente. */
        $nuovaData = $date ?: $ev['event_date'];
        $giorni    = pl_giorni_evento($ev);
        $nuovaFine = $giorni > 1
            ? date('Y-m-d', strtotime($nuovaData . ' +' . ($giorni - 1) . ' day'))
            : null;

        sec_q("UPDATE planner_events SET start_time=?, end_time=?, event_date=?, end_date=? WHERE id=? AND user_id=?",
              [pl_time($newStart), pl_time($newStart + $dur), $nuovaData, $nuovaFine, $id, $current_user_id]);
        /* trascinare un task lo sposta anche negli elenchi: data e ora sono
           la stessa cosa vista da due parti */
        pl_task_to_todo($current_user_id, $id);
        sec_json(['ok' => true, 'start' => substr(pl_time($newStart), 0, 5), 'end' => substr(pl_time($newStart + $dur), 0, 5)]);
    }

    /* ---------------- spunta task / habit ---------------- */
    if ($ajax === 'event_toggle') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json(t('api.evento_non_trovato'), 404, 'not_found');
        $ev = sec_one("SELECT habit_id, is_completed, event_date FROM planner_events WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        $newState = (int)$ev['is_completed'] === 1 ? 0 : 1;
        sec_q("UPDATE planner_events SET is_completed=?, completed_at=" . ($newState ? 'NOW()' : 'NULL') . " WHERE id=? AND user_id=?",
              [$newState, $id, $current_user_id]);
        // se l'evento viene da un habit, aggiorna anche il log dell'abitudine
        if (!empty($ev['habit_id'])) {
            pl_sync_habit_log($current_user_id, (int)$ev['habit_id'], $ev['event_date'], $newState === 1);
        }
        /* Se l'evento nasce da un'attività del to do, la spunta vale anche là:
           il collegamento finora funzionava solo nell'altro verso. */
        @sec_q("UPDATE todos SET is_done = ?, done_at = " . ($newState ? 'NOW()' : 'NULL') . "
                WHERE planner_event_id = ? AND user_id = ?",
               [$newState, $id, $current_user_id], 'iii');
        sec_json(['ok' => true, 'done' => $newState === 1]);
    }

    /* ---------------- cancellazione ---------------- */
    if ($ajax === 'event_delete') {
        $id    = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $scope = sec_enum(sec_in('scope'), ['one', 'series', 'routine'], 'one');
        // stesso normalizzatore del salvataggio: dal frontend arriva spesso vuota
        $origDate = pl_date_or_null(sec_in('original_date'));

        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json(t('api.evento_non_trovato'), 404, 'not_found');
        $ev = sec_one("SELECT series_id, routine_group, rrule, original_date FROM planner_events WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');

        if ($scope === 'series') {
            /* La serie si cancella dal capo: se l'utente ha aperto un'eccezione,
               `$id` è la riga dell'eccezione e non il master, e cancellare solo
               lei lascerebbe in piedi tutta la ripetizione. */
            $capo = !empty($ev['series_id']) ? (int)$ev['series_id'] : $id;
            $where = 'user_id = ? AND (id = ? OR series_id = ?)';
            $params = [$current_user_id, $capo, $capo];
        } elseif ($scope === 'one' && !empty($ev['rrule']) && $origDate !== null) {
            /* Una sola occorrenza di un master: non esiste una riga da
               cancellare, quindi se ne scrive una che dice «qui no». */
            $capo = !empty($ev['series_id']) ? (int)$ev['series_id'] : $id;
            $gia = sec_one("SELECT id FROM planner_events
                            WHERE user_id=? AND series_id=? AND original_date=? LIMIT 1",
                           [$current_user_id, $capo, $origDate], 'iis');
            if ($gia) {
                // c'era già un'eccezione per quel giorno: la si annulla, non si duplica
                sec_q("UPDATE planner_events SET is_cancelled = 1 WHERE id=? AND user_id=?",
                      [(int)$gia['id'], $current_user_id], 'ii');
            } else {
                sec_q("INSERT INTO planner_events
                       (user_id, title, event_date, start_time, end_time, series_id, original_date, is_cancelled)
                       VALUES (?, 'Cancellato', ?, '00:00:00', '00:00:00', ?, ?, 1)",
                      [$current_user_id, $origDate, $capo, $origDate], 'isis');
            }
            sec_json(['ok' => true]);
            return;
        } elseif ($scope === 'one' && !empty($ev['original_date'])) {
            // L'utente sta eliminando un'eccezione preesistente. La annulliamo.
            sec_q("UPDATE planner_events SET is_cancelled = 1 WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
            sec_json(['ok' => true]);
            return;
        } elseif ($scope === 'routine' && !empty($ev['routine_group'])) {
            $where = 'user_id = ? AND routine_group = ?';
            $params = [$current_user_id, $ev['routine_group']];
        } else {
            $where = 'id = ? AND user_id = ?';
            $params = [$id, $current_user_id];
        }
        // prima di cancellare, mettiamo in coda gli eventi che vivono anche
        // su Google: alla sincronizzazione successiva spariranno anche là
        /* Prima di cancellare: i task portano via anche l'attività di cui
           erano la faccia in agenda. Lasciarla vorrebbe dire ritrovarsi negli
           elenchi una riga che nel planner non c'è più, e senza modo di
           capire da dove sia sbucata. */
        $spariti = sec_all("SELECT id FROM planner_events WHERE " . $where, $params);
        if (!empty($spariti)) {
            $ids = [];
            foreach ($spariti as $sp) $ids[] = (int)$sp['id'];
            $segn = implode(',', array_fill(0, count($ids), '?'));
            sec_q("DELETE FROM todos WHERE user_id = ? AND planner_event_id IN ($segn)",
                  array_merge([$current_user_id], $ids));

            /* Il blocco di una routine su Google appartiene a un pezzo solo.
               Cancellando quel pezzo e non gli altri, il blocco non deve
               sparire: passa a un pezzo che resta, che diventa "da mandare"
               e al giro dopo aggiorna orari e note del blocco. */
            $capi = sec_all("SELECT id, routine_group, event_date, google_event_id, google_calendar_id
                               FROM planner_events
                              WHERE user_id = ? AND id IN ($segn) AND routine_group IS NOT NULL
                                AND google_event_id IS NOT NULL AND google_origin = 0",
                            array_merge([$current_user_id], $ids));
            foreach ($capi as $capo) {
                $erede = sec_one("SELECT id FROM planner_events
                                   WHERE user_id = ? AND routine_group = ? AND event_date = ?
                                     AND id NOT IN ($segn)
                                   ORDER BY start_time, id LIMIT 1",
                                 array_merge([$current_user_id, $capo['routine_group'], $capo['event_date']], $ids));
                if (!$erede) continue;
                sec_q("UPDATE planner_events SET google_event_id = NULL, google_calendar_id = NULL WHERE id = ? AND user_id = ?",
                      [(int)$capo['id'], $current_user_id], 'ii');
                sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ?, google_synced_at = NULL
                        WHERE id = ? AND user_id = ?",
                      [$capo['google_event_id'], $capo['google_calendar_id'], (int)$erede['id'], $current_user_id], 'ssii');
            }
        }
        pl_queue_google_delete($current_user_id, $where, $params);
        sec_q("DELETE FROM planner_events WHERE " . $where, $params);
        sec_json(['ok' => true]);
    }

    /* ---------------- anticipi / ritardi ---------------- */
    if ($ajax === 'events_shift') {
        $ids       = sec_in('ids');
        $minutes   = sec_int(sec_in('minutes'), 0, 1439, 0);
        $direction = sec_enum(sec_in('direction'), ['late', 'early'], 'late');
        if (!is_array($ids) || empty($ids) || $minutes === 0) sec_die_json(t('api.niente_da_spostare'), 422, 'empty');
        $delta = $direction === 'late' ? $minutes : -$minutes;

        /* Prima leggiamo tutti gli eventi scelti, poi limitiamo lo spostamento
           UNA volta sola guardando il più estremo. Limitando ciascuno per conto
           suo, una routine a ridosso della mezzanotte si sarebbe schiacciata:
           alcuni pezzi si fermavano al limite e altri no, perdendo le distanze
           che avevano fra loro.

           PERCHÉ ORA È VELOCE. Prima ogni evento costava una sec_owns() più una
           SELECT, e poi una UPDATE a testa: con cinquanta eventi erano centocinquanta
           query, e l'attesa era tale che si finiva per premere il tasto più volte.
           Ora sono due query in tutto: una lettura sola per l'intero gruppo (che
           fa già da controllo di proprietà, perché filtra per user_id) e una
           scrittura sola, dato che lo scarto è identico per tutti. */
        $puliti = [];
        foreach (array_slice($ids, 0, 200) as $raw) {
            $eid = sec_int($raw, 1, PHP_INT_MAX, 0);
            if ($eid > 0) $puliti[$eid] = true;      // le chiavi tolgono i doppioni
        }
        $puliti = array_keys($puliti);
        if (empty($puliti)) sec_json(['ok' => true, 'moved' => 0]);

        $segnaposto = implode(',', array_fill(0, count($puliti), '?'));

        /* Il filtro su user_id è il controllo di proprietà: un identificativo
           di un altro utente semplicemente non torna indietro. */
        $chosen = sec_all(
            "SELECT id, start_time, end_time FROM planner_events
              WHERE user_id = ? AND id IN ($segnaposto)",
            array_merge([$current_user_id], $puliti)
        );
        if (empty($chosen)) sec_json(['ok' => true, 'moved' => 0]);

        $minStart = 1440; $maxEnd = 0;
        $validi = [];
        foreach ($chosen as $c) {
            $s = pl_minutes($c['start_time']); $e = pl_minutes($c['end_time']);
            $minStart = min($minStart, $s); $maxEnd = max($maxEnd, $e);
            $validi[] = (int)$c['id'];
        }
        if ($delta < 0) $delta = max($delta, -$minStart);          // non si va prima di mezzanotte
        if ($delta > 0) $delta = min($delta, 1439 - $maxEnd);      // né oltre la fine del giorno
        if ($delta === 0) sec_json(['ok' => true, 'moved' => 0]);

        $segnaposto2 = implode(',', array_fill(0, count($validi), '?'));
        $secondi = $delta * 60;
        sec_q(
            "UPDATE planner_events
                SET start_time = ADDTIME(start_time, SEC_TO_TIME(?)),
                    end_time   = ADDTIME(end_time,   SEC_TO_TIME(?))
              WHERE user_id = ? AND id IN ($segnaposto2)",
            array_merge([$secondi, $secondi, $current_user_id], $validi)
        );
        /* Le attività collegate seguono, in una query sola: se restassero
           indietro la pagina attività direbbe un'ora e il planner un'altra,
           e non ci sarebbe modo di sapere quale delle due ha ragione. */
        sec_q(
            "UPDATE todos t
               JOIN planner_events e ON e.id = t.planner_event_id AND e.user_id = t.user_id
                SET t.due_date = e.event_date,
                    t.due_time = IF(e.is_all_day = 1, NULL, e.start_time)
              WHERE t.user_id = ? AND e.id IN ($segnaposto2)",
            array_merge([$current_user_id], $validi)
        );
        sec_json(['ok' => true, 'moved' => count($validi)]);
    }

    /* ---------------- copia un altro giorno ---------------- */
    if ($ajax === 'copy_day') {
        $from = sec_date(sec_in('from'), null);
        $to   = sec_date(sec_in('to'), null);
        if (!$from || !$to || $from === $to) sec_die_json(t('api.giorni_non_validi'), 422, 'invalid');
        $rows = sec_all("SELECT * FROM planner_events WHERE user_id=? AND event_date=?", [$current_user_id, $from], 'is');
        $n = 0;
        foreach ($rows as $r) {
            /* la copia conserva la durata in giorni: un evento dal 3 al 5
               copiato sul 10 va dal 10 al 12, non dal 10 al 5 */
            $giorniCopia = pl_giorni_evento($r);
            $fineCopia = $giorniCopia > 1
                ? date('Y-m-d', strtotime($to . ' +' . ($giorniCopia - 1) . ' day'))
                : null;
            sec_q("INSERT INTO planner_events
                   (user_id, title, notes, event_date, end_date, start_time, end_time, is_all_day, category_id, project_id,
                    habit_id, routine_id, routine_group, routine_collapsed, importance, color, item_type,
                    notify_type, notify_minutes, sort_order, calendar_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   [$current_user_id, $r['title'], $r['notes'], $to, $fineCopia, $r['start_time'], $r['end_time'],
                    (int)$r['is_all_day'], $r['category_id'], $r['project_id'], $r['habit_id'], $r['routine_id'],
                    $r['routine_group'], (int)$r['routine_collapsed'], $r['importance'], $r['color'],
                    $r['item_type'], $r['notify_type'], (int)$r['notify_minutes'], (int)$r['sort_order'],
                    $r['calendar_id'] ?? pl_default_calendar($current_user_id)]);
            $n++;
        }
        sec_json(['ok' => true, 'copied' => $n, 'events' => pl_events_range($current_user_id, $to, $to)]);
    }

    /* ---------------- inserimento di una routine ---------------- */
    if ($ajax === 'routine_insert') {
        $rid   = sec_int(sec_in('routine_id'), 1, PHP_INT_MAX, 0);
        $date  = sec_date(sec_in('date'), date('Y-m-d'));
        $start = sec_time(sec_in('start'), '07:00:00');
        if (!sec_owns('planner_routines', $rid, $current_user_id)) sec_die_json(t('planner.msg_routine_non_trovata'), 404, 'not_found');
        $r = sec_one("SELECT name, color FROM planner_routines WHERE id=? AND user_id=?", [$rid, $current_user_id], 'ii');
        $items = sec_all("SELECT name, duration_minutes FROM planner_routine_items
                          WHERE routine_id=? AND user_id=? ORDER BY sort_order, id", [$rid, $current_user_id], 'ii');
        if (empty($items)) sec_die_json(t('api.routine_senza_eventi'), 422, 'empty');
        $group = bin2hex(random_bytes(16));
        $cursor = pl_minutes($start);
        /* Nel calendario principale, come ogni evento a cui non si sceglie
           niente: senza calendario la routine restava fuori dai filtri e da
           Google Calendar. */
        $calRoutine = pl_default_calendar($current_user_id);
        foreach ($items as $it) {
            $dur = max(1, (int)$it['duration_minutes']);
            $end = min(1439, $cursor + $dur);
            sec_q("INSERT INTO planner_events
                   (user_id, title, event_date, start_time, end_time, routine_id, routine_group, routine_collapsed, color, notify_type, notify_minutes, calendar_id)
                   VALUES (?,?,?,?,?,?,?,1,?,'none',0,?)",
                   [$current_user_id, $it['name'], $date, pl_time($cursor), pl_time($end), $rid, $group, $r['color'], $calRoutine]);
            $cursor = $end;
            if ($cursor >= 1439) break;
        }
        sec_json(['ok' => true, 'events' => pl_events_range($current_user_id, $date, $date)]);
    }

    if ($ajax === 'routine_fold') {
        $group     = sec_str(sec_in('routine_group'), 32);
        $collapsed = sec_bool(sec_in('collapsed')) ? 1 : 0;
        sec_q("UPDATE planner_events SET routine_collapsed=? WHERE user_id=? AND routine_group=?",
              [$collapsed, $current_user_id, $group]);
        sec_json(['ok' => true]);
    }

    /* ---------------- priorità ---------------- */
    if ($ajax === 'priority_save') {
        $date = sec_date(sec_in('date'), date('Y-m-d'));
        $pos  = sec_int(sec_in('position'), 1, 6, 1);
        $text = sec_str(sec_in('content'), 200);
        $done = sec_bool(sec_in('done')) ? 1 : 0;
        if ($text === '' && $done === 0) {
            sec_q("DELETE FROM planner_priorities WHERE user_id=? AND priority_date=? AND position=?",
                  [$current_user_id, $date, $pos], 'isi');
        } else {
            sec_q("INSERT INTO planner_priorities (user_id, priority_date, position, content, is_done)
                   VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), is_done=VALUES(is_done)",
                   [$current_user_id, $date, $pos, $text, $done], 'isisi');
        }
        sec_json(['ok' => true]);
    }

    /* ---------------- settimana: numero, spunta, cosa ho imparato ---------------- */
    if ($ajax === 'week_save') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $row = sec_one("SELECT id, week_number, number_edited, is_completed, learned_note FROM planner_weeks
                        WHERE user_id=? AND week_start=?", [$current_user_id, $weekStart], 'is');
        $number   = sec_in('number') !== null ? sec_int(sec_in('number'), 1, 9999, 1) : null;
        $completed= sec_in('completed') !== null ? (sec_bool(sec_in('completed')) ? 1 : 0) : null;
        $learned  = sec_in('learned') !== null ? sec_text(sec_in('learned'), 4000) : null;

        $curNumber = $row ? (int)$row['week_number'] : pl_week_number($current_user_id, $weekStart, $settings);
        $curEdited = $row ? (int)$row['number_edited'] : 0;
        $curDone   = $row ? (int)$row['is_completed'] : 0;
        $curLearn  = $row ? (string)$row['learned_note'] : '';

        $newNumber = $number !== null ? $number : $curNumber;
        $newEdited = $number !== null ? 1 : $curEdited;
        $newDone   = $completed !== null ? $completed : $curDone;
        $newLearn  = $learned !== null ? $learned : $curLearn;

        sec_q("INSERT INTO planner_weeks (user_id, week_start, week_number, number_edited, is_completed, learned_note)
               VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE week_number=VALUES(week_number), number_edited=VALUES(number_edited),
                                       is_completed=VALUES(is_completed), learned_note=VALUES(learned_note)",
               [$current_user_id, $weekStart, $newNumber, $newEdited, $newDone, $newLearn], 'isiiis');
        sec_json(['ok' => true, 'week' => pl_week_row($current_user_id, $weekStart, $settings)]);
    }

    /* ---------------- liste della settimana ---------------- */
    if ($ajax === 'list_add') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $type = sec_enum(sec_in('type'), ['todo', 'buy', 'study'], 'todo');
        sec_q("INSERT IGNORE INTO planner_week_lists (user_id, week_start, list_type, sort_order)
               VALUES (?,?,?,(SELECT COALESCE(MAX(x.sort_order),0)+1 FROM (SELECT sort_order FROM planner_week_lists WHERE user_id=? AND week_start=?) x))",
               [$current_user_id, $weekStart, $type, $current_user_id, $weekStart], 'issis');
        sec_json(['ok' => true, 'lists' => pl_week_lists($current_user_id, $weekStart)]);
    }
    if ($ajax === 'list_remove') {
        $listId = sec_int(sec_in('list_id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json(t('api.lista_non_trovata'), 404, 'not_found');
        sec_q("DELETE FROM planner_week_list_items WHERE list_id=? AND user_id=?", [$listId, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_week_lists WHERE id=? AND user_id=?", [$listId, $current_user_id], 'ii');
        sec_json(['ok' => true]);
    }
    if ($ajax === 'list_item_save') {
        $itemId = sec_int(sec_in('item_id'), 0, PHP_INT_MAX, 0);
        $listId = sec_int(sec_in('list_id'), 0, PHP_INT_MAX, 0);
        $text   = sec_str(sec_in('content'), 200);
        $done   = sec_bool(sec_in('done')) ? 1 : 0;
        if ($itemId > 0) {
            if (!sec_owns('planner_week_list_items', $itemId, $current_user_id)) sec_die_json(t('api.voce_non_trovata'), 404, 'not_found');
            if ($text === '') {
                sec_q("DELETE FROM planner_week_list_items WHERE id=? AND user_id=?", [$itemId, $current_user_id], 'ii');
                sec_json(['ok' => true, 'deleted' => true]);
            }
            sec_q("UPDATE planner_week_list_items SET content=?, is_done=? WHERE id=? AND user_id=?",
                  [$text, $done, $itemId, $current_user_id], 'siii');
            sec_json(['ok' => true, 'id' => $itemId]);
        }
        if ($listId === 0 || !sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json(t('api.lista_non_trovata'), 404, 'not_found');
        if ($text === '') sec_json(['ok' => true, 'id' => 0]);
        sec_q("INSERT INTO planner_week_list_items (list_id, user_id, content, is_done, sort_order)
               VALUES (?,?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_week_list_items WHERE list_id=?) x))",
               [$listId, $current_user_id, $text, $done, $listId], 'iisii');
        sec_json(['ok' => true, 'id' => sec_insert_id()]);
    }

    /* ---------------- categorie ---------------- */
    if ($ajax === 'category_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $name  = sec_str(sec_in('name'), 60);
        $color = sec_color(sec_in('color'));
        if ($name === '') sec_die_json(t('api.serve_nome_categoria'), 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
            sec_q("UPDATE planner_categories SET name=?, color=? WHERE id=? AND user_id=?", [$name, $color, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO planner_categories (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_categories WHERE user_id=?) x))",
                   [$current_user_id, $name, $color, $current_user_id], 'issi');
        }
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'category_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
        sec_q("UPDATE planner_events SET category_id=NULL WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_category_keywords WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_categories WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'category_hide') {
        $id     = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $hidden = sec_bool(sec_in('hidden')) ? 1 : 0;
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
        sec_q("UPDATE planner_categories SET is_hidden=? WHERE id=? AND user_id=?", [$hidden, $id, $current_user_id], 'iii');
        sec_json(['ok' => true]);
    }
    /* parola chiave: rifiutata se già presente in un'altra categoria */
    if ($ajax === 'keyword_add') {
        $catId   = sec_int(sec_in('category_id'), 1, PHP_INT_MAX, 0);
        $keyword = sec_key(sec_in('keyword'), 60);
        if (!sec_owns('planner_categories', $catId, $current_user_id)) sec_die_json(t('api.categoria_non_trovata'), 404, 'not_found');
        if ($keyword === '') sec_die_json(t('api.scrivi_una_parola'), 422, 'invalid');
        $clash = sec_one("SELECT k.category_id, c.name FROM planner_category_keywords k
                          JOIN planner_categories c ON c.id = k.category_id
                          WHERE k.user_id = ? AND k.keyword = ? LIMIT 1", [$current_user_id, $keyword], 'is');
        if ($clash) {
            sec_json([
                'ok' => false, 'error' => 'duplicate',
                'category_id' => (int)$clash['category_id'], 'category_name' => $clash['name'],
                'message' => t('api.parola_gia_in', ['parola' => $keyword, 'categoria' => $clash['name']]),
            ], 409);
        }
        sec_q("INSERT INTO planner_category_keywords (user_id, category_id, keyword) VALUES (?,?,?)",
              [$current_user_id, $catId, $keyword], 'iis');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'keyword_delete') {
        $catId   = sec_int(sec_in('category_id'), 1, PHP_INT_MAX, 0);
        $keyword = sec_key(sec_in('keyword'), 60);
        sec_q("DELETE FROM planner_category_keywords WHERE user_id=? AND category_id=? AND keyword=?",
              [$current_user_id, $catId, $keyword], 'iis');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }

    /* ---------------- routine: creazione e modifica ---------------- */
    if ($ajax === 'routine_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $name  = sec_str(sec_in('name'), 80);
        $color = sec_color(sec_in('color'), '#E3D9EE');
        $items = sec_in('items');
        if ($name === '') sec_die_json(t('api.serve_nome_routine'), 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json(t('planner.msg_routine_non_trovata'), 404, 'not_found');
            sec_q("UPDATE planner_routines SET name=?, color=? WHERE id=? AND user_id=?", [$name, $color, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO planner_routines (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_routines WHERE user_id=?) x))",
                   [$current_user_id, $name, $color, $current_user_id], 'issi');
            $id = sec_insert_id();
        }
        if (is_array($items)) {
            sec_q("DELETE FROM planner_routine_items WHERE routine_id=? AND user_id=?", [$id, $current_user_id], 'ii');
            $o = 1;
            foreach (array_slice($items, 0, 40) as $it) {
                $iname = sec_str($it['name'] ?? '', 120);
                $imin  = sec_int($it['minutes'] ?? 5, 1, 1440, 5);
                if ($iname === '') continue;
                sec_q("INSERT INTO planner_routine_items (routine_id, user_id, name, duration_minutes, sort_order)
                       VALUES (?,?,?,?,?)", [$id, $current_user_id, $iname, $imin, $o++], 'iisii');
            }
        }
        sec_json(['ok' => true, 'routines' => pl_routines($current_user_id)]);
    }
    if ($ajax === 'routine_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json(t('planner.msg_routine_non_trovata'), 404, 'not_found');
        sec_q("DELETE FROM planner_routine_items WHERE routine_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_routines WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'routines' => pl_routines($current_user_id)]);
    }

    /* ---------------- impostazioni ---------------- */
    if ($ajax === 'settings_save') {
        $fields = [];
        $params = [];
        $map = [
            'palette'           => ['palette_key', 'enum', ['sage','mint','blush','lavender','butter','sky','clay','rose','sea','olive','plum','sand'], 'sage'],
            'zoom'              => ['default_zoom', 'enum', ['agenda','h3','h1','m30','m15','m5'], 'm30'],
            'day_start_hour'    => ['day_start_hour', 'int', 0, 23],
            'show_motivational' => ['show_motivational', 'bool'],
            'show_affirmations' => ['show_affirmations', 'bool'],
            'default_view'      => ['default_view', 'enum', ['day','month'], 'day'],
            'ambient_enabled'   => ['ambient_enabled', 'bool'],
            'ambient_opacity'   => ['ambient_opacity', 'int', 0, 10],
            'fabs_open'         => ['fabs_open', 'bool'],
            'contrast_level'    => ['contrast_level', 'int', 1, 5],
            'days_visible'      => ['days_visible', 'int', 1, 7],
            'gc_dedicated'      => ['gc_dedicated_mode', 'enum', ['none','all','high','highest'], 'all'],
            'gc_primary'        => ['gc_primary_mode', 'enum', ['none','high','highest'], 'none'],
            'first_week_number' => ['first_week_number', 'int', 1, 9999],
        ];
        foreach ($map as $key => $cfg) {
            $raw = sec_in($key);
            if ($raw === null) continue;
            $fields[] = '`' . $cfg[0] . '` = ?';
            if ($cfg[1] === 'enum')      $params[] = sec_enum($raw, $cfg[2], $cfg[3]);
            elseif ($cfg[1] === 'int')   $params[] = sec_int($raw, $cfg[2], $cfg[3], $cfg[2]);
            else                         $params[] = sec_bool($raw) ? 1 : 0;
        }
        if (!empty($fields)) {
            $params[] = $current_user_id;
            sec_q("UPDATE planner_settings SET " . implode(', ', $fields) . " WHERE user_id = ?", $params);
        }
        /* Cambiando le scelte su Google, gli eventi già inviati vanno
           rivalutati: senza questo, uno che non è più da sincronizzare
           resterebbe su Google per sempre, perché l'invio guarda solo quelli
           modificati di recente. Azzerando la marca, al prossimo giro
           vengono riesaminati tutti. */
        if (sec_in('gc_dedicated') !== null || sec_in('gc_primary') !== null) {
            sec_q("UPDATE planner_events SET google_synced_at = NULL
                   WHERE user_id = ? AND event_date BETWEEN (CURDATE() - INTERVAL 30 DAY) AND (CURDATE() + INTERVAL 180 DAY)",
                  [$current_user_id], 'i');
        }
        sec_json(['ok' => true, 'settings' => pl_settings($current_user_id)]);
    }

    /* ---------------- riepilogo della settimana ---------------- */
    if ($ajax === 'week_summary') {
        $weekStart = pl_monday(sec_date(sec_in('week_start'), date('Y-m-d')));
        $weekEnd   = pl_add_days($weekStart, 6);

        $events = sec_all(
            "SELECT e.habit_id, e.category_id, e.routine_id, e.routine_group,
                    e.start_time, e.end_time, e.is_all_day, e.is_completed,
                    TIMESTAMPDIFF(MINUTE, e.start_time, e.end_time) AS minutes
             FROM planner_events e
             WHERE e.user_id = ? AND e.event_date BETWEEN ? AND ?",
            [$current_user_id, $weekStart, $weekEnd], 'iss'
        );

        /* --- abitudini: quante volte le hai messe in agenda, quale obiettivo
               ti eri dato in habits, e quanto tempo ci stai dedicando --- */
        $habitRows = sec_all(
            "SELECT id, name, icon, color, frequency_type, weekdays, times_per_week,
                    times_per_month, interval_days, planner_minutes
             FROM habits WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
             ORDER BY sort_order, id", [$current_user_id], 'i'
        );
        $habitStats = [];
        foreach ($habitRows as $h) {
            $goal = null;
            if ($h['frequency_type'] === 'daily')       $goal = substr_count((string)$h['weekdays'], '1');
            elseif ($h['frequency_type'] === 'weekly')  $goal = (int)$h['times_per_week'];
            elseif ($h['frequency_type'] === 'interval') $goal = (int)floor(7 / max(1, (int)$h['interval_days']));
            $habitStats[(int)$h['id']] = [
                'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'], 'color' => $h['color'],
                'planned' => 0, 'done' => 0, 'goal' => $goal, 'minutes' => 0,
            ];
        }
        $habitMinutes = 0;

        /* --- categorie e routine --- */
        $cats = [];
        foreach (pl_categories($current_user_id) as $c) {
            $cats[$c['id']] = ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'minutes' => 0, 'count' => 0];
        }
        $noCat = ['id' => 0, 'name' => t('planner.pre_senza_categoria'), 'color' => '#E4DDD3', 'minutes' => 0, 'count' => 0];

        $routines = [];
        foreach (pl_routines($current_user_id) as $r) {
            $routines[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'],
                                   'times' => 0, 'minutes' => 0, 'groups' => []];
        }

        foreach ($events as $e) {
            $min = (int)$e['is_all_day'] === 1 ? 0 : max(0, (int)$e['minutes']);
            if (!empty($e['habit_id']) && isset($habitStats[(int)$e['habit_id']])) {
                $habitStats[(int)$e['habit_id']]['planned']++;
                if ((int)$e['is_completed'] === 1) $habitStats[(int)$e['habit_id']]['done']++;
                $habitStats[(int)$e['habit_id']]['minutes'] += $min;
                $habitMinutes += $min;
            }
            if (!empty($e['category_id']) && isset($cats[(int)$e['category_id']])) {
                $cats[(int)$e['category_id']]['minutes'] += $min;
                $cats[(int)$e['category_id']]['count']++;
            } elseif (empty($e['category_id'])) {
                $noCat['minutes'] += $min;
                $noCat['count']++;
            }
            if (!empty($e['routine_id']) && isset($routines[(int)$e['routine_id']])) {
                $routines[(int)$e['routine_id']]['minutes'] += $min;
                // una routine "fatta" = un blocco inserito, non i suoi singoli passaggi
                if (!empty($e['routine_group'])) {
                    $routines[(int)$e['routine_id']]['groups'][$e['routine_group']] = true;
                }
            }
        }
        foreach ($routines as $rid => $r) {
            $routines[$rid]['times'] = count($r['groups']);
            unset($routines[$rid]['groups']);
        }

        /* --- diario: solo da martedì in avanti, o a settimana finita --- */
        $today = date('Y-m-d');
        $showDiary = $today > $weekEnd || $today >= pl_add_days($weekStart, 1);
        $diary = null;
        if ($showDiary) {
            $rows = sec_all("SELECT mood, energy, note FROM habit_daily_journal
                             WHERE user_id = ? AND entry_date BETWEEN ? AND ?",
                             [$current_user_id, $weekStart, $weekEnd], 'iss');
            $lists = sec_one("SELECT COUNT(DISTINCT entry_date) AS c FROM diary_list_items
                              WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> ''",
                              [$current_user_id, $weekStart, $weekEnd], 'iss');
            $moods = []; $energies = []; $filled = 0;
            foreach ($rows as $r) {
                if (trim((string)$r['note']) !== '') $filled++;
                if ($r['mood'] !== null)   $moods[] = (int)$r['mood'];
                if ($r['energy'] !== null) $energies[] = (int)$r['energy'];
            }
            $filled = max($filled, (int)($lists['c'] ?? 0));
            $diary = [
                'days'       => $filled,
                'mood_avg'   => empty($moods) ? null : round(array_sum($moods) / count($moods), 1),
                'energy_avg' => empty($energies) ? null : round(array_sum($energies) / count($energies), 1),
            ];
        }

        $catList = array_values($cats);
        if ($noCat['count'] > 0) $catList[] = $noCat;
        usort($catList, function ($a, $b) { return $b['minutes'] <=> $a['minutes']; });

        sec_json([
            'ok'         => true,
            'week'       => pl_week_row($current_user_id, $weekStart, $settings),
            'habits'     => array_values($habitStats),
            'habit_minutes' => $habitMinutes,
            'categories' => $catList,
            'routines'   => array_values($routines),
            'diary'      => $diary,
            'show_diary' => $showDiary,
        ]);
    }

    /* ---------------- calendari ---------------- */
    if ($ajax === 'calendar_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $name  = sec_str(sec_in('name'), 80);
        $color = sec_color(sec_in('color'));
        if ($name === '') sec_die_json(t('api.serve_nome_calendario'), 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
            sec_q("UPDATE planner_calendars SET name = ?, color = ? WHERE id = ? AND user_id = ?",
                  [$name, $color, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO planner_calendars (user_id, name, color, sort_order)
                   VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_calendars WHERE user_id=?) x))",
                  [$current_user_id, $name, $color, $current_user_id], 'issi');
        }
        sec_json(['ok' => true, 'calendars' => pl_calendars($current_user_id)]);
    }

    if ($ajax === 'calendar_visible') {
        $id  = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $vis = sec_bool(sec_in('visible')) ? 1 : 0;
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
        sec_q("UPDATE planner_calendars SET is_visible = ? WHERE id = ? AND user_id = ?",
              [$vis, $id, $current_user_id], 'iii');
        sec_json(['ok' => true]);
    }

    /* mostra solo questo, oppure tutti */
    if ($ajax === 'calendar_only') {
        $id = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        if ($id === 0) {
            sec_q("UPDATE planner_calendars SET is_visible = 1 WHERE user_id = ?", [$current_user_id], 'i');
        } else {
            if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
            sec_q("UPDATE planner_calendars SET is_visible = (id = ?) WHERE user_id = ?", [$id, $current_user_id], 'ii');
        }
        sec_json(['ok' => true, 'calendars' => pl_calendars($current_user_id)]);
    }

    if ($ajax === 'calendar_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
        /* Il principale non si elimina: è il fondo del secchio, il posto in
           cui finisce quello che non ne ha scelto un altro. Senza, gli eventi
           che ci stanno dentro non avrebbero dove andare. */
        $chi = sec_one("SELECT is_default FROM planner_calendars WHERE id = ? AND user_id = ?",
                       [$id, $current_user_id], 'ii');
        if ($chi && (int)$chi['is_default'] === 1) {
            sec_die_json(t('api.calendario_principale'), 422, 'is_default');
        }
        /* gli eventi non si perdono: passano al principale */
        $princ = pl_default_calendar($current_user_id);
        sec_q("UPDATE planner_events SET calendar_id = ? WHERE calendar_id = ? AND user_id = ?",
              [$princ, $id, $current_user_id], 'iii');
        sec_q("DELETE FROM planner_calendars WHERE id = ? AND user_id = ?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'calendars' => pl_calendars($current_user_id)]);
    }

    /* ---------------- regole speciali calendario ---------------- */
    if ($ajax === 'calendar_rules_save') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $keywords = sec_str(sec_in('keywords'), 255);
        $def_repeat = sec_enum(sec_in('def_repeat'), ['none','daily','weekly','monthly','yearly'], 'none');
        $def_notify = sec_enum(sec_in('def_notify'), ['none','at_time','m30','h1','d1'], 'none');
        $apply_retro = sec_bool(sec_in('apply_retro'));
        
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
        
        sec_q("UPDATE planner_calendars SET keywords=?, def_repeat=?, def_notify=? WHERE id=? AND user_id=?",
              [$keywords, $def_repeat, $def_notify, $id, $current_user_id], 'sssii');
              
        if ($apply_retro) {
            if ($keywords !== '') {
                $kws = explode(',', $keywords);
                $likes = []; $params = [];
                foreach ($kws as $kw) {
                    $kw = trim($kw);
                    if ($kw !== '') {
                        $likes[] = "title LIKE ?";
                        $params[] = '%' . str_replace(['%','_'], ['\%','\_'], $kw) . '%';
                    }
                }
                if (!empty($likes)) {
                    $whereLikes = implode(' OR ', $likes);
                    sec_q("UPDATE planner_events SET calendar_id = ? WHERE user_id = ? AND ($whereLikes)", array_merge([$id, $current_user_id], $params));
                }
            }
            if ($def_repeat !== 'none') sec_q("UPDATE planner_events SET repeat_type = ? WHERE calendar_id = ? AND user_id = ?", [$def_repeat, $id, $current_user_id], 'sii');
            if ($def_notify !== 'none') {
                $nMins = ['none' => 0, 'at_time' => 0, 'm30' => 30, 'h1' => 60, 'd1' => 1440];
                $nMinRetro = $nMins[$def_notify] ?? 60;
                sec_q("UPDATE planner_events SET notify_type = ?, notify_minutes = ? WHERE calendar_id = ? AND user_id = ?", [$def_notify, $nMinRetro, $id, $current_user_id], 'siii');
            }
        }
        sec_json(['ok' => true, 'calendars' => pl_calendars($current_user_id)]);
    }

    /* ---------------- rubrica ---------------- */
    if ($ajax === 'contacts') {
        $q = sec_str(sec_in('q'), 60);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            /* si cerca sia per nome sia per indirizzo */
            $rows = sec_all("SELECT id, name, email FROM contacts
                             WHERE user_id = ? AND (name LIKE ? OR email LIKE ?)
                             ORDER BY name LIMIT 60", [$current_user_id, $like, $like], 'iss');
        } else {
            $rows = sec_all("SELECT id, name, email FROM contacts
                             WHERE user_id = ? ORDER BY name LIMIT 200", [$current_user_id], 'i');
        }
        $out = [];
        foreach ($rows as $r) $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email']];
        sec_json(['ok' => true, 'contacts' => $out]);
    }

    if ($ajax === 'contact_save') {
        $id    = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
        $nome  = sec_str(sec_in('name'), 80);
        $mail  = sec_email(sec_in('email'));
        if (!$mail) sec_die_json(t('api.indirizzo_non_valido'), 422, 'invalid');
        if ($nome === '') $nome = explode('@', $mail)[0];
        if ($id > 0) {
            if (!sec_owns('contacts', $id, $current_user_id)) sec_die_json(t('api.contatto_non_trovato'), 404, 'not_found');
            sec_q("UPDATE contacts SET name = ?, email = ? WHERE id = ? AND user_id = ?",
                  [$nome, $mail, $id, $current_user_id], 'ssii');
        } else {
            sec_q("INSERT INTO contacts (user_id, name, email) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE name = VALUES(name)",
                  [$current_user_id, $nome, $mail], 'iss');
        }
        sec_json(['ok' => true]);
    }

    if ($ajax === 'contact_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('contacts', $id, $current_user_id)) sec_die_json(t('api.contatto_non_trovato'), 404, 'not_found');
        sec_q("DELETE FROM contacts WHERE id = ? AND user_id = ?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true]);
    }

    /* ---------------- ricerca per titolo ---------------- */
    if ($ajax === 'search') {
        $q = sec_str(sec_in('q'), 60);
        if (mb_strlen($q) < 2) sec_json(['ok' => true, 'results' => []]);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = sec_all("SELECT id, title, event_date, start_time, end_time, is_all_day, color, category_id
                         FROM planner_events WHERE user_id = ? AND title LIKE ?
                         ORDER BY ABS(DATEDIFF(event_date, CURDATE())) LIMIT 60", [$current_user_id, $like], 'is');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'], 'title' => $r['title'], 'date' => $r['event_date'],
                'start' => substr($r['start_time'], 0, 5), 'end' => substr($r['end_time'], 0, 5),
                'all_day' => (int)$r['is_all_day'] === 1, 'color' => $r['color'],
                'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
            ];
        }
        sec_json(['ok' => true, 'results' => $out]);
    }

    sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');
}

/* senza ?ajax= questo indirizzo non serve a niente */
sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');
