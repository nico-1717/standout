<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_scrivi.php
   Salvare, spuntare e cancellare un evento: la logica che prima stava dentro
   public/planner/planner_api.php (azioni event_save, event_toggle,
   event_delete) e che ora serve a DUE porte: la pagina del planner e l'API
   /api/v1/events. Il corpo di pl_evento_salva() e pl_evento_elimina() e'
   SPOSTATO da li' senza cambiarne la logica: ricorrenze, eccezioni, invitati,
   collegamento ai task, coda di Google. L'unica differenza e' che l'ingresso
   non arriva piu' da sec_in() ma da un array ($in), cosi' lo stesso codice
   si puo' chiamare da dove si vuole.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

/**
 * Crea o modifica un evento. $in ha le stesse chiavi che manda la pagina
 * (title, date, start, end, repeat, edit_mode, original_date, guests...).
 * Ritorna ['event' => forma per il client] oppure ['non_trovato' => true]
 * se l'id non e' di questo utente.
 */
function pl_evento_salva(int $uid, array $in): array {
    $id      = sec_int(($in['id'] ?? null), 0, PHP_INT_MAX, 0);
    $title   = sec_str(($in['title'] ?? null), 180);
    $notes   = sec_text(($in['notes'] ?? null), 4000);
    $luogo   = sec_str(($in['location'] ?? null), 255);
    $date    = sec_date(($in['date'] ?? null), date('Y-m-d'));
    $allDay  = sec_bool(($in['all_day'] ?? null));
    $start   = sec_time(($in['start'] ?? null), '09:00:00');
    $end     = sec_time(($in['end'] ?? null), '10:00:00');

    /* La data di fine. Tre regole, tutte pensate perché un client
       sbagliato (o modificato a mano) non possa lasciare righe assurde:
         - prima dell'inizio non ha senso: si riporta all'inizio;
         - uguale all'inizio si salva come NULL, che è il modo in cui il
           database dice «un giorno solo» — così le righe di sempre e
           quelle nuove hanno la stessa forma;
         - più lunga del massimo si tronca: un evento entra in tutte le
           caselle che attraversa, e uno da diecimila giorni comparirebbe
           ovunque per trent'anni. */
    $endDate = sec_date(($in['end_date'] ?? null), null);
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
    $catId   = sec_int(($in['category_id'] ?? null), 0, PHP_INT_MAX, 0);
    $calId   = sec_int(($in['calendar_id'] ?? null), 0, PHP_INT_MAX, 0);
    if ($calId > 0 && !sec_owns('planner_calendars', $calId, $uid)) $calId = 0;
    /* Chi non sceglie finisce nel principale, e ci finisce *scritto*: un
       calendario nullo vorrebbe dire un evento che il filtro non può né
       accendere né spegnere, cioè fuori da ogni calendario. */
    if ($calId === 0) $calId = (int)(pl_default_calendar($uid) ?: 0);
    $projId  = sec_int(($in['project_id'] ?? null), 0, PHP_INT_MAX, 0);
    $habitId = sec_int(($in['habit_id'] ?? null), 0, PHP_INT_MAX, 0);
    $imp     = sec_enum(($in['importance'] ?? null), ['normal', 'high', 'highest'], 'normal');
    $color   = ($in['color'] ?? null) ? sec_color(($in['color'] ?? null)) : null;
    $type    = sec_enum(($in['type'] ?? null), ['event', 'task'], 'event');
    $notify  = sec_enum(($in['notify'] ?? null), ['none', 'at_time', 'm30', 'h1', 'd1', 'custom'], 'h1');
    $notifyMin = ['none' => 0, 'at_time' => 0, 'm30' => 30, 'h1' => 60, 'd1' => 1440];
    $nMin    = $notify === 'custom' ? sec_int(($in['notify_min'] ?? null), 0, 40320, 60) : ($notifyMin[$notify] ?? 60);
    $rep     = sec_enum(($in['repeat'] ?? null), ['none', 'daily', 'weekly', 'monthly', 'yearly', 'custom'], 'none');
    $repInt  = sec_int(($in['repeat_interval'] ?? null), 1, 365, 1);
    $repUnit = sec_enum(($in['repeat_unit'] ?? null), ['day', 'week', 'month', 'year'], 'week');

    /* Le due date facoltative passano dal normalizzatore: arrivano vuote
       tutte le volte che l'evento non si ripete, e su PHP 8 la stringa
       vuota dentro strtotime era il Fatal Error del salvataggio. */
    $repUntil = pl_date_or_null(($in['repeat_until'] ?? null));
    $origDate = pl_date_or_null(($in['original_date'] ?? null));

    $editMode = sec_str((string)(($in['edit_mode'] ?? null) ?? ''), 10);
    if ($editMode !== 'one' && $editMode !== 'all') $editMode = '';

    /* Luogo e lucchetto: la loro lettura era andata persa nel refactoring
       e le variabili finivano nei parametri della query senza esistere.
       È questo che faceva rispondere «Errore nella richiesta al database»
       a ogni salvataggio. */
    $placeName = sec_str(($in['place_name'] ?? null), 255);
    $placeRef  = sec_str(($in['place_ref'] ?? null), 255);
    $placeLat  = sec_float(($in['place_lat'] ?? null), -90, 90, 999);
    $placeLon  = sec_float(($in['place_lon'] ?? null), -180, 180, 999);
    // le coordinate hanno senso solo in coppia: mezza posizione non è una posizione
    if ($placeLat === 999.0 || $placeLon === 999.0) { $placeLat = null; $placeLon = null; }
    $durLock   = sec_bool(($in['duration_locked'] ?? null)) ? 1 : 0;

    // proprietà delle chiavi esterne
    if ($catId > 0 && !sec_owns('planner_categories', $catId, $uid)) $catId = 0;
    if ($projId > 0 && !sec_owns('projects', $projId, $uid)) $projId = 0;
    if ($habitId > 0 && !sec_owns('habits', $habitId, $uid)) $habitId = 0;

    // categoria dedotta dal titolo, se l'utente non ne ha scelta una
    if ($catId === 0 && $projId === 0 && $title !== '') {
        $auto = pl_auto_category($uid, $title);
        if ($auto !== null) $catId = $auto;
    }
    
    // calendario dedotto dal titolo e impostazione dei default
    if ($calId === 0 && $title !== '') {
        $autoCal = pl_auto_calendar($uid, $title);
        if ($autoCal !== null) {
            $calId = $autoCal['id'];
            if ($id === 0) {
                if (!array_key_exists('repeat', $in) && $autoCal['def_repeat'] !== 'none') $rep = $autoCal['def_repeat'];
                if (!array_key_exists('notify', $in) && $autoCal['def_notify'] !== 'none') {
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
        if (!sec_owns('planner_events', $id, $uid)) {
            return ['non_trovato' => true];
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
                $durLock, null, $uid, $id, $origDate
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
            $params[] = $id; $params[] = $uid;
            sec_q("UPDATE planner_events SET title=?, notes=?, location=?, event_date=?, end_date=?, start_time=?, end_time=?, is_all_day=?,
                          category_id=?, calendar_id=?, project_id=?, habit_id=?, importance=?, color=?, item_type=?,
                          notify_type=?, notify_minutes=?, repeat_type=?, repeat_interval=?, repeat_unit=?, repeat_until=?,
                          place_name=?, place_lat=?, place_lon=?, place_ref=?, duration_locked=?, rrule=?
                   WHERE id=? AND user_id=?", $params);
            $newId = $id;
        }
    } else {
        $params[] = $uid;
        sec_q("INSERT INTO planner_events
               (title, notes, location, event_date, end_date, start_time, end_time, is_all_day, category_id, calendar_id,
                project_id, habit_id, importance, color, item_type, notify_type, notify_minutes,
                repeat_type, repeat_interval, repeat_unit, repeat_until,
                place_name, place_lat, place_lon, place_ref, duration_locked, rrule, user_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params);
        $newId = sec_insert_id();
        if ($rep !== 'none') {
            sec_q("UPDATE planner_events SET series_id = ? WHERE id = ? AND user_id = ?",
                  [$newId, $newId, $uid], 'iii');
            /* La chiamata a pl_materialize_repeats è stata volutamente rimossa da qui */
        }
    }

    /* Qui sotto c'era un SECONDO blocco di salvataggio, il vecchio, rimasto
       in piedi accanto a quello nuovo. Girava comunque, e riaggiungeva
       $id e $uid a un $params a cui erano già stati aggiunti:
       ventinove parametri contro ventisei segnaposto, cioè «Errore nella
       richiesta al database» a ogni singolo salvataggio. Rimosso. */
    /* Invitati: si riscrive l'elenco da capo. Il nome viene copiato adesso,
       così un evento passato ricorda come si chiamava il contatto allora. */
    $guests = ($in['guests'] ?? null);
    if (is_array($guests)) {
        sec_q("DELETE FROM event_guests WHERE event_id = ? AND user_id = ?", [$newId, $uid], 'ii');
        foreach (array_slice($guests, 0, 30) as $g) {
            $mail = sec_email(is_array($g) ? ($g['email'] ?? '') : $g);
            if (!$mail) continue;
            $nome = is_array($g) ? sec_str($g['name'] ?? '', 80) : '';
            if ($nome === '') {
                $c = sec_one("SELECT name FROM contacts WHERE user_id = ? AND email = ?", [$uid, $mail], 'is');
                $nome = $c['name'] ?? null;
            }
            @sec_q("INSERT IGNORE INTO event_guests (event_id, user_id, email, name) VALUES (?,?,?,?)",
                   [$newId, $uid, $mail, $nome !== '' ? $nome : null], 'iiss');
        }
    }

    /* un task appena salvato è un'attività: se non ce l'ha ancora, adesso
       ha la sua riga in `todos`, già collegata (vedi pl_task_to_todo) */
    pl_task_to_todo($uid, $newId);

    $row = sec_one("SELECT e.*, p.name AS project_name, p.color AS project_color,
                           h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
                    FROM planner_events e
                    LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
                    LEFT JOIN habits h ON h.id = e.habit_id AND h.user_id = e.user_id
                    LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
                    WHERE e.id = ? AND e.user_id = ?", [$newId, $uid], 'ii');
    $uscita = $row ? pl_event_out($row) : null;
    if ($uscita) { $tmp = pl_attach_guests($uid, [$uscita]); $uscita = $tmp[0]; }
    return ['event' => $uscita];
}

/**
 * Spunta o despunta un evento (e l'abitudine o l'attivita' collegata).
 * $stato null = inverte (e' quello che fa la pagina); true/false = lo porta
 * a quello stato (e' quello che fa l'app: ripetere la richiesta non la
 * annulla). Ritorna il nuovo stato, o null se l'evento non e' dell'utente.
 */
function pl_evento_spunta(int $uid, int $id, ?bool $stato = null): ?bool {
    if (!sec_owns('planner_events', $id, $uid)) return null;
    $ev = sec_one("SELECT habit_id, is_completed, event_date FROM planner_events WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    /* Con uno stato esplicito (l'app) chiedere quello che gia' c'e' non fa niente:
       altrimenti ripetere una richiesta (rete lenta, secondo tentativo) farebbe
       contare una seconda volta l'abitudine nel suo log. */
    if ($stato !== null && ((int)$ev['is_completed'] === 1) === $stato) return $stato;
    $newState = $stato === null ? ((int)$ev['is_completed'] === 1 ? 0 : 1) : ($stato ? 1 : 0);
    sec_q("UPDATE planner_events SET is_completed=?, completed_at=" . ($newState ? 'NOW()' : 'NULL') . " WHERE id=? AND user_id=?",
          [$newState, $id, $uid]);
    // se l'evento viene da un habit, aggiorna anche il log dell'abitudine
    if (!empty($ev['habit_id'])) {
        pl_sync_habit_log($uid, (int)$ev['habit_id'], $ev['event_date'], $newState === 1);
    }
    /* Se l'evento nasce da un'attivita' del to do, la spunta vale anche la':
       il collegamento finora funzionava solo nell'altro verso. */
    @sec_q("UPDATE todos SET is_done = ?, done_at = " . ($newState ? 'NOW()' : 'NULL') . "
            WHERE planner_event_id = ? AND user_id = ?",
           [$newState, $id, $uid], 'iii');
    return $newState === 1;
}

/**
 * Cancella un evento. $scope: 'one' (una occorrenza o un evento solo),
 * 'series' (tutta la serie), 'routine' (tutti i pezzi della routine).
 * Ritorna false se l'evento non e' dell'utente.
 */
function pl_evento_elimina(int $uid, int $id, string $scope, ?string $origDate): bool {
    if (!sec_owns('planner_events', $id, $uid)) return false;
    $ev = sec_one("SELECT series_id, routine_group, rrule, original_date FROM planner_events WHERE id=? AND user_id=?", [$id, $uid], 'ii');

    if ($scope === 'series') {
        /* La serie si cancella dal capo: se l'utente ha aperto un'eccezione,
           `$id` è la riga dell'eccezione e non il master, e cancellare solo
           lei lascerebbe in piedi tutta la ripetizione. */
        $capo = !empty($ev['series_id']) ? (int)$ev['series_id'] : $id;
        $where = 'user_id = ? AND (id = ? OR series_id = ?)';
        $params = [$uid, $capo, $capo];
    } elseif ($scope === 'one' && !empty($ev['rrule']) && $origDate !== null) {
        /* Una sola occorrenza di un master: non esiste una riga da
           cancellare, quindi se ne scrive una che dice «qui no». */
        $capo = !empty($ev['series_id']) ? (int)$ev['series_id'] : $id;
        $gia = sec_one("SELECT id FROM planner_events
                        WHERE user_id=? AND series_id=? AND original_date=? LIMIT 1",
                       [$uid, $capo, $origDate], 'iis');
        if ($gia) {
            // c'era già un'eccezione per quel giorno: la si annulla, non si duplica
            sec_q("UPDATE planner_events SET is_cancelled = 1 WHERE id=? AND user_id=?",
                  [(int)$gia['id'], $uid], 'ii');
        } else {
            sec_q("INSERT INTO planner_events
                   (user_id, title, event_date, start_time, end_time, series_id, original_date, is_cancelled)
                   VALUES (?, 'Cancellato', ?, '00:00:00', '00:00:00', ?, ?, 1)",
                  [$uid, $origDate, $capo, $origDate], 'isis');
        }
        return true;
    } elseif ($scope === 'one' && !empty($ev['original_date'])) {
        // L'utente sta eliminando un'eccezione preesistente. La annulliamo.
        sec_q("UPDATE planner_events SET is_cancelled = 1 WHERE id=? AND user_id=?", [$id, $uid], 'ii');
        return true;
    } elseif ($scope === 'routine' && !empty($ev['routine_group'])) {
        $where = 'user_id = ? AND routine_group = ?';
        $params = [$uid, $ev['routine_group']];
    } else {
        $where = 'id = ? AND user_id = ?';
        $params = [$id, $uid];
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
              array_merge([$uid], $ids));

        /* Il blocco di una routine su Google appartiene a un pezzo solo.
           Cancellando quel pezzo e non gli altri, il blocco non deve
           sparire: passa a un pezzo che resta, che diventa "da mandare"
           e al giro dopo aggiorna orari e note del blocco. */
        $capi = sec_all("SELECT id, routine_group, event_date, google_event_id, google_calendar_id
                           FROM planner_events
                          WHERE user_id = ? AND id IN ($segn) AND routine_group IS NOT NULL
                            AND google_event_id IS NOT NULL AND google_origin = 0",
                        array_merge([$uid], $ids));
        foreach ($capi as $capo) {
            $erede = sec_one("SELECT id FROM planner_events
                               WHERE user_id = ? AND routine_group = ? AND event_date = ?
                                 AND id NOT IN ($segn)
                               ORDER BY start_time, id LIMIT 1",
                             array_merge([$uid, $capo['routine_group'], $capo['event_date']], $ids));
            if (!$erede) continue;
            sec_q("UPDATE planner_events SET google_event_id = NULL, google_calendar_id = NULL WHERE id = ? AND user_id = ?",
                  [(int)$capo['id'], $uid], 'ii');
            sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ?, google_synced_at = NULL
                    WHERE id = ? AND user_id = ?",
                  [$capo['google_event_id'], $capo['google_calendar_id'], (int)$erede['id'], $uid], 'ssii');
        }
    }
    pl_queue_google_delete($uid, $where, $params);
    sec_q("DELETE FROM planner_events WHERE " . $where, $params);
    return true;
}
