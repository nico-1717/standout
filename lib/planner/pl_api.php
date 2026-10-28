<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_api.php
   Gli eventi per l'API /api/v1/events (PROGETTO §15, passo 9, fetta 2).

   Qui c'e' SOLO quello che serve all'app in piu' rispetto alla pagina:
   controllare i tipi di quello che arriva e fare la modifica «a campi»
   (PATCH). Il lavoro vero lo fanno le funzioni della pagina, non copie:
     - lettura:   pl_events_range()   (ricorrenze comprese)
     - scrittura: pl_evento_salva()   (pl_scrivi.php)
     - spunta:    pl_evento_spunta()  (pl_scrivi.php)
     - cancella:  pl_evento_elimina() (pl_scrivi.php)
   Le chiavi del JSON sono le stesse che manda la pagina (title, date,
   start, end, all_day, repeat, ...), e quelle di risposta sono quelle di
   pl_event_out().

   La differenza dalla pagina e' una sola, voluta: la pagina corregge in
   silenzio quello che non capisce (un id non suo diventa «nessuno», una
   data sbagliata diventa oggi); l'app invece riceve un errore 422/404 col
   nome del campo, perche' un client che sbaglia deve saperlo.

   Gli errori tornano come ['errore' => ['codice', 'campo', 'testo']], come
   in lib/todo_core.php. Caricato da lib/planner/planner_lib.php.
   ===================================================================== */

function pl_api_errore(string $codice, string $campo, string $testo): array {
    return ['errore' => ['codice' => $codice, 'campo' => $campo, 'testo' => $testo]];
}

/** Un evento dell'utente nella forma per il client (con invitati), o null. Mai i segnaposto «cancellato». */
function pl_api_evento(int $uid, int $id): ?array {
    $row = sec_one("SELECT e.*, p.name AS project_name, p.color AS project_color,
                           h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
                    FROM planner_events e
                    LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
                    LEFT JOIN habits h ON h.id = e.habit_id AND h.user_id = e.user_id
                    LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
                    WHERE e.id = ? AND e.user_id = ? AND e.is_cancelled = 0", [$id, $uid], 'ii');
    if (!$row) return null;
    $fuori = pl_attach_guests($uid, [pl_event_out($row)]);
    return $fuori[0];
}

/**
 * L'elenco di un intervallo di date, con le occorrenze delle serie gia' calcolate.
 * Come la pagina: al massimo 70 giorni per volta (un intervallo piu' lungo si
 * accorcia, e la risposta dice quale intervallo e' stato davvero letto).
 */
function pl_api_elenco(int $uid, ?string $da, ?string $a): array {
    if ($da !== null && sec_date($da, null) === null) return pl_api_errore('validation', 'from', 'from must be YYYY-MM-DD');
    if ($a !== null && sec_date($a, null) === null) return pl_api_errore('validation', 'to', 'to must be YYYY-MM-DD');
    $da = $da ?? pl_monday(date('Y-m-d'));
    $a  = $a ?? pl_add_days($da, 6);
    if (strtotime($a) < strtotime($da)) return pl_api_errore('validation', 'to', 'to cannot be before from');
    if ((strtotime($a) - strtotime($da)) > 86400 * 70) $a = pl_add_days($da, 70);
    return ['from' => $da, 'to' => $a, 'events' => pl_events_range($uid, $da, $a)];
}

/**
 * Controlla le chiavi PRESENTI nel corpo (una chiave assente = non toccarla)
 * e le rimette in un array pronto per pl_evento_salva().
 * Ritorna ['in' => [...], 'fatto' => bool|null, 'modo' => ''|'one'|'all', 'orig' => data|null]
 * oppure un errore.
 */
function pl_api_leggi(int $uid, array $c, bool $creazione): array {
    $in = [];
    $e = fn(string $campo, string $testo, string $cod = 'validation') => pl_api_errore($cod, $campo, $testo);

    // testi: title obbligatorio e non vuoto; gli altri possono essere svuotati con null o ""
    foreach (['title' => 180, 'notes' => 4000, 'location' => 255, 'place_name' => 255, 'place_ref' => 255] as $k => $max) {
        if (!array_key_exists($k, $c)) continue;
        if ($c[$k] === null && $k !== 'title') { $in[$k] = ''; continue; }
        if (!is_string($c[$k])) return $e($k, "$k must be text");
        if (mb_strlen($c[$k]) > $max) return $e($k, "$k is too long (max $max characters)");
        if ($k === 'title' && trim($c[$k]) === '') return $e($k, 'title cannot be empty');
        $in[$k] = $c[$k];
    }
    if ($creazione && !isset($in['title'])) return $e('title', 'title is required');
    if ($creazione && !array_key_exists('date', $c)) return $e('date', 'date is required');

    // date e orari
    foreach (['date' => false, 'end_date' => true, 'repeat_until' => true] as $k => $nullable) {
        if (!array_key_exists($k, $c)) continue;
        if ($c[$k] === null && $nullable) { $in[$k] = null; continue; }
        $d = is_string($c[$k]) ? sec_date($c[$k], null) : null;
        if ($d === null) return $e($k, "$k must be YYYY-MM-DD" . ($nullable ? ' or null' : ''));
        $in[$k] = $d;
    }
    foreach (['start', 'end'] as $k) {
        if (!array_key_exists($k, $c)) continue;
        $o = is_string($c[$k]) ? sec_time($c[$k], null) : null;
        if ($o === null) return $e($k, "$k must be HH:MM");
        $in[$k] = $o;
    }

    // vero/falso
    foreach (['all_day', 'duration_locked'] as $k) {
        if (!array_key_exists($k, $c)) continue;
        if (!is_bool($c[$k])) return $e($k, "$k must be true or false");
        $in[$k] = $c[$k];
    }

    // scelte chiuse
    $scelte = [
        'importance'  => ['normal', 'high', 'highest'],
        'type'        => ['event', 'task'],
        'notify'      => ['none', 'at_time', 'm30', 'h1', 'd1', 'custom'],
        'repeat'      => ['none', 'daily', 'weekly', 'monthly', 'yearly', 'custom'],
        'repeat_unit' => ['day', 'week', 'month', 'year'],
    ];
    foreach ($scelte as $k => $ammessi) {
        if (!array_key_exists($k, $c)) continue;
        if (!is_string($c[$k]) || !in_array($c[$k], $ammessi, true)) return $e($k, "$k must be one of: " . implode(', ', $ammessi));
        $in[$k] = $c[$k];
    }

    // numeri
    foreach (['notify_min' => [0, 40320], 'repeat_interval' => [1, 365]] as $k => [$min, $max]) {
        if (!array_key_exists($k, $c)) continue;
        if (!is_int($c[$k]) || $c[$k] < $min || $c[$k] > $max) return $e($k, "$k must be a number from $min to $max");
        $in[$k] = $c[$k];
    }

    // colore: #RGB o #RRGGBB, oppure null per toglierlo
    if (array_key_exists('color', $c)) {
        if ($c['color'] === null) $in['color'] = null;
        elseif (is_string($c['color']) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $c['color'])) $in['color'] = $c['color'];
        else return $e('color', 'color must be #RRGGBB or null');
    }

    // posizione: tutte e due le coordinate o nessuna
    if (array_key_exists('place_lat', $c) || array_key_exists('place_lon', $c)) {
        $lat = $c['place_lat'] ?? null; $lon = $c['place_lon'] ?? null;
        if ($lat === null && $lon === null) { $in['place_lat'] = null; $in['place_lon'] = null; }
        elseif ((!is_int($lat) && !is_float($lat)) || (!is_int($lon) && !is_float($lon))) return $e('place_lat', 'place_lat and place_lon must both be numbers, or both null');
        elseif ($lat < -90 || $lat > 90) return $e('place_lat', 'place_lat must be between -90 and 90');
        elseif ($lon < -180 || $lon > 180) return $e('place_lon', 'place_lon must be between -180 and 180');
        else { $in['place_lat'] = (float)$lat; $in['place_lon'] = (float)$lon; }
    }

    // collegamenti: un id che non e' dell'utente e' «non trovato» (non si rivela cosa esiste)
    foreach (['category_id' => 'planner_categories', 'calendar_id' => 'planner_calendars', 'project_id' => 'projects', 'habit_id' => 'habits'] as $k => $tab) {
        if (!array_key_exists($k, $c)) continue;
        $v = $c[$k];
        if ($v === null || $v === 0) { $in[$k] = 0; continue; }
        if (!is_int($v) || $v < 0) return $e($k, "$k must be a number or null");
        if (!sec_owns($tab, $v, $uid)) return $e($k, ucfirst(str_replace('_id', '', $k)) . ' not found', 'not_found');
        $in[$k] = $v;
    }

    // invitati: al massimo 30, ognuno un indirizzo email valido (testo o {email, name})
    if (array_key_exists('guests', $c)) {
        if (!is_array($c['guests']) || count($c['guests']) > 30) return $e('guests', 'guests must be a list of at most 30 items');
        $g = [];
        foreach ($c['guests'] as $x) {
            $mail = is_array($x) ? ($x['email'] ?? '') : $x;
            if (!is_string($mail) || sec_email($mail) === null) return $e('guests', 'every guest needs a valid email');
            $nome = is_array($x) && isset($x['name']) ? (is_string($x['name']) ? $x['name'] : '') : '';
            $g[] = ['email' => $mail, 'name' => $nome];
        }
        $in['guests'] = $g;
    }

    // solo per le modifiche di una serie
    $modo = '';
    if (array_key_exists('edit_mode', $c)) {
        if (!in_array($c['edit_mode'], ['one', 'all'], true)) return $e('edit_mode', 'edit_mode must be "one" or "all"');
        $modo = $c['edit_mode'];
    }
    $orig = null;
    if (array_key_exists('original_date', $c) && $c['original_date'] !== null) {
        $orig = is_string($c['original_date']) ? sec_date($c['original_date'], null) : null;
        if ($orig === null) return $e('original_date', 'original_date must be YYYY-MM-DD or null');
    }

    $fatto = null;
    if (array_key_exists('done', $c)) {
        if (!is_bool($c['done'])) return $e('done', 'done must be true or false');
        $fatto = $c['done'];
    }
    return ['in' => $in, 'fatto' => $fatto, 'modo' => $modo, 'orig' => $orig];
}

/** Controlli che servono i valori INSIEME (dopo l'unione con quelli gia' salvati). */
function pl_api_coerenza(array $in): ?array {
    $data = $in['date'];
    if (!empty($in['end_date']) && $in['end_date'] < $data) {
        return pl_api_errore('validation', 'end_date', 'end_date cannot be before date');
    }
    $tuttoGiorno = !empty($in['all_day']);
    if (empty($in['end_date']) && !$tuttoGiorno && isset($in['start'], $in['end']) && pl_minutes($in['end']) <= pl_minutes($in['start'])) {
        // la pagina qui ripiega in silenzio su +30 minuti; all'app si dice che l'orario non torna
        return pl_api_errore('validation', 'end', 'end must be after start (for an event that crosses midnight set end_date)');
    }
    return null;
}

/**
 * Le cose che la pagina del planner crea alla prima apertura: impostazioni,
 * categorie di partenza e il calendario «Principale». Chi usa solo l'app non
 * ha mai aperto la pagina, e un evento senza calendario non lo puo' accendere
 * ne' spegnere nessun filtro (vedi event_save): si preparano qui, una volta.
 */
function pl_api_prepara(int $uid): void {
    pl_settings($uid);
    pl_calendars($uid);
}

/** Crea un evento. Ritorna ['event' => ...] oppure un errore. */
function pl_api_crea(int $uid, array $c): array {
    $v = pl_api_leggi($uid, $c, true);
    if (isset($v['errore'])) return $v;
    $in = $v['in'];
    pl_api_prepara($uid);
    /* Gli orari si confrontano solo se arrivano tutti e due: se ne manca uno la
       pagina sceglie lei (09:00 e +30 minuti) e non e' un errore. */
    if ($err = pl_api_coerenza($in)) return $err;
    $r = pl_evento_salva($uid, $in);
    if (empty($r['event'])) return pl_api_errore('server_error', 'body', 'The event could not be saved');
    return ['event' => $r['event']];
}

/** Un evento dell'utente come l'ha scritto (per riempire i campi non nominati). */
function pl_api_riga_come_input(array $r): array {
    return [
        'title' => (string)$r['title'], 'notes' => (string)($r['notes'] ?? ''), 'location' => (string)($r['location'] ?? ''),
        'date' => (string)$r['event_date'], 'end_date' => $r['end_date'] !== null ? (string)$r['end_date'] : null,
        'start' => (string)$r['start_time'], 'end' => (string)$r['end_time'],
        'all_day' => (int)$r['is_all_day'] === 1,
        'category_id' => (int)($r['category_id'] ?? 0), 'calendar_id' => (int)($r['calendar_id'] ?? 0),
        'project_id' => (int)($r['project_id'] ?? 0), 'habit_id' => (int)($r['habit_id'] ?? 0),
        'importance' => (string)$r['importance'], 'color' => $r['color'], 'type' => (string)$r['item_type'],
        'notify' => (string)$r['notify_type'], 'notify_min' => (int)$r['notify_minutes'],
        'repeat' => (string)$r['repeat_type'], 'repeat_interval' => (int)$r['repeat_interval'],
        'repeat_unit' => (string)$r['repeat_unit'], 'repeat_until' => $r['repeat_until'],
        'place_name' => (string)($r['place_name'] ?? ''), 'place_lat' => $r['place_lat'] !== null ? (float)$r['place_lat'] : null,
        'place_lon' => $r['place_lon'] !== null ? (float)$r['place_lon'] : null, 'place_ref' => (string)($r['place_ref'] ?? ''),
        'duration_locked' => (int)$r['duration_locked'] === 1,
    ];
}

/**
 * Cambia SOLO i campi nominati (e/o spunta con done). Per cambiare una sola
 * occorrenza di una serie: edit_mode "one" + original_date (la data della
 * ripetizione); senza, si cambia tutta la serie, come fa la pagina.
 */
function pl_api_modifica(int $uid, int $id, array $c): array {
    $riga = sec_one("SELECT * FROM planner_events WHERE id = ? AND user_id = ? AND is_cancelled = 0", [$id, $uid], 'ii');
    if (!$riga) return pl_api_errore('not_found', 'id', 'Event not found');
    $v = pl_api_leggi($uid, $c, false);
    if (isset($v['errore'])) return $v;
    if (!$v['in'] && $v['fatto'] === null) return pl_api_errore('validation', 'body', 'Nothing to update');

    if ($v['in']) {
        pl_api_prepara($uid);
        $in = $v['in'] + pl_api_riga_come_input($riga);
        $modo = $v['modo'];
        if ($modo === 'one') {
            if (empty($riga['rrule'])) return pl_api_errore('validation', 'edit_mode', 'edit_mode "one" is only for events that repeat');
            if ($v['orig'] === null) return pl_api_errore('validation', 'original_date', 'original_date is required with edit_mode "one"');
            /* l'eccezione nasce dalla data di quella ripetizione, non da quella del capostipite */
            if (!array_key_exists('date', $c)) $in['date'] = $v['orig'];
            /* e un'occorrenza singola non si ripete: la pagina la scrive sempre senza ripetizione */
        }
        if ($err = pl_api_coerenza($in)) return $err;
        $in['edit_mode'] = $modo;
        $in['original_date'] = $v['orig'];
        $in['id'] = $id;
        $r = pl_evento_salva($uid, $in);
        if (!empty($r['non_trovato'])) return pl_api_errore('not_found', 'id', 'Event not found');
        $nuovoId = (int)$r['event']['id'];

        /* pl_evento_salva ricalcola la regola di ripetizione dai campi semplici
           (repeat, repeat_interval...). Un evento arrivato da Google puo' avere una
           regola piu' ricca (giorni della settimana, conteggio): se chi chiama non
           ha toccato la ripetizione, la regola di prima si rimette com'era. */
        $tocca = array_intersect(['repeat', 'repeat_interval', 'repeat_unit', 'repeat_until'], array_keys($v['in']));
        if (!empty($riga['rrule']) && !$tocca && $modo !== 'one') {
            sec_q("UPDATE planner_events SET rrule = ? WHERE id = ? AND user_id = ?", [$riga['rrule'], $id, $uid], 'sii');
        }
        $id = $nuovoId;
    }
    if ($v['fatto'] !== null) pl_evento_spunta($uid, $id, $v['fatto']);
    return ['event' => pl_api_evento($uid, $id)];
}

/**
 * Cancella (scope: one | series | routine). Ritorna [] se e' fatto oppure un errore.
 * Per UNA occorrenza di una serie serve original_date: senza, la pagina toglierebbe la
 * sola riga del capostipite e lascerebbe le eccezioni senza padre.
 */
function pl_api_elimina(int $uid, int $id, string $scope, ?string $orig): array {
    $riga = sec_one("SELECT rrule FROM planner_events WHERE id = ? AND user_id = ? AND is_cancelled = 0", [$id, $uid], 'ii');
    if (!$riga) return pl_api_errore('not_found', 'id', 'Event not found');
    if ($scope === 'one' && !empty($riga['rrule']) && $orig === null) {
        return pl_api_errore('validation', 'original_date', 'original_date is required to delete one occurrence of a repeating event (or use scope=series)');
    }
    pl_evento_elimina($uid, $id, $scope, $orig);
    return [];
}
