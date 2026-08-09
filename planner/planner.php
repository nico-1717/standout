<?php
/* =====================================================================
   NISAMA — PLANNER (vista giorno/settimana + vista mese)
   Struttura come habits.php / progetti.php: endpoint JSON sotto
   ?ajax=..., poi la pagina. Ogni query passa da security_core.php,
   quindi è sempre un prepared statement e ogni riga toccata viene
   prima verificata come appartenente all'utente.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();   // resa disponibile anche ai file inclusi (project_selector.php)
date_default_timezone_set('Europe/Rome');

define('PL_PHRASES_FILE', __DIR__ . '/planner_phrases.json');
define('PL_MAX_OCCURRENCES', 400);

/* =====================================================================
   UTILITY
   ===================================================================== */

function pl_monday(string $date): string {
    $ts = strtotime($date);
    $dow = (int)date('N', $ts);           // 1 = lunedì
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}
function pl_add_days(string $date, int $n): string {
    return date('Y-m-d', strtotime($date . ' ' . ($n >= 0 ? '+' : '-') . abs($n) . ' days'));
}
function pl_minutes(string $time): int {
    $p = explode(':', $time);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}
function pl_time(int $minutes): string {
    $minutes = max(0, min(1439, $minutes));
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

/* --- impostazioni: create al primo accesso ------------------------- */
function pl_settings(int $userId): array {
    $row = sec_one("SELECT * FROM planner_settings WHERE user_id = ?", [$userId], 'i');
    if (!$row) {
        sec_q("INSERT INTO planner_settings (user_id, first_week_start) VALUES (?, ?)",
              [$userId, pl_monday(date('Y-m-d'))], 'is');
        pl_seed_defaults($userId);
        $row = sec_one("SELECT * FROM planner_settings WHERE user_id = ?", [$userId], 'i');
    }
    if (!$row) return [];
    return [
        'palette'            => $row['palette_key'],
        'zoom'               => $row['default_zoom'],
        'day_start_hour'     => (int)$row['day_start_hour'],
        'show_motivational'  => (int)$row['show_motivational'] === 1,
        'show_affirmations'  => (int)$row['show_affirmations'] === 1,
        'default_view'       => $row['default_view'],
        'first_week_start'   => $row['first_week_start'] ?: pl_monday(date('Y-m-d')),
        'first_week_number'  => (int)$row['first_week_number'],
        'ambient_enabled'    => (int)$row['ambient_enabled'] === 1,
        /* Quanto si vede la scena del mese: 0 invisibile, 10 piena.
           Il ?? 9 non è pigrizia: finché la migrazione non è stata
           eseguita la colonna non c'è, e senza questo la pagina si
           spegnerebbe invece di comportarsi come prima. */
        'ambient_opacity'    => (int)($row['ambient_opacity'] ?? 9),
        /* colonna dei tastini a destra aperta o richiusa sotto le abitudini */
        'fabs_open'          => (int)($row['fabs_open'] ?? 1) === 1,
        'contrast_level'     => (int)($row['contrast_level'] ?? 3),
        'days_visible'       => (int)($row['days_visible'] ?? 3),
        'google_sync'        => (int)$row['google_sync_enabled'] === 1,
        'gc_dedicated'       => $row['gc_dedicated_mode'] ?? 'all',
        'gc_primary'         => $row['gc_primary_mode'] ?? 'none',
    ];
}

/** Categorie e routine di partenza per un utente nuovo. */
function pl_seed_defaults(int $userId): void {
    $exists = sec_one("SELECT 1 AS ok FROM planner_categories WHERE user_id = ? LIMIT 1", [$userId], 'i');
    if (!$exists) {
        /* Ogni categoria parte con qualche parola ovvia: scrivendo "treno" in
           un evento, la categoria Spostamenti si assegna da sé. */
        $defaults = [
            ['Spostamenti',  '#CFDDEA', ['treno', 'auto', 'macchina', 'bus', 'aereo', 'viaggio', 'metro', 'volo', 'partenza']],
            ['Svaghi',       '#F3E4C0', ['cinema', 'film', 'serie', 'gioco', 'videogioco', 'concerto', 'uscita', 'aperitivo']],
            ['Eventi',       '#F0D3D6', ['festa', 'compleanno', 'matrimonio', 'cena', 'evento', 'invito']],
            ['Lavoro',       '#D8D3E8', ['lavoro', 'riunione', 'call', 'ufficio', 'progetto', 'consegna', 'cliente']],
            ['Appuntamenti', '#E8D5C7', ['dottore', 'medico', 'dentista', 'visita', 'appuntamento', 'parrucchiere', 'banca']],
            ['Relax',        '#C7DED6', ['relax', 'riposo', 'pausa', 'yoga', 'meditazione', 'lettura', 'passeggiata']],
            ['Vacanze',      '#DCE7C8', ['vacanza', 'ferie', 'mare', 'montagna', 'hotel', 'weekend']],
        ];
        $i = 1;
        foreach ($defaults as $d) {
            sec_q("INSERT INTO planner_categories (user_id, name, color, sort_order) VALUES (?,?,?,?)",
                  [$userId, $d[0], $d[1], $i++], 'issi');
            $catId = sec_insert_id();
            foreach ($d[2] as $kw) {
                @sec_q("INSERT IGNORE INTO planner_category_keywords (user_id, category_id, keyword) VALUES (?,?,?)",
                       [$userId, $catId, $kw], 'iis');
            }
        }
    }
    $hasRoutine = sec_one("SELECT 1 AS ok FROM planner_routines WHERE user_id = ? LIMIT 1", [$userId], 'i');
    if (!$hasRoutine) {
        sec_q("INSERT INTO planner_routines (user_id, name, color, sort_order) VALUES (?,?,?,1)",
              [$userId, 'Morning routine', '#F6EBD2'], 'iss');
        $rid = sec_insert_id();
        $items = [['Alzarsi dal letto', 5], ['Skincare', 20], ['Vestirsi', 5], ['Colazione', 10], ['Prendere il sole', 5], ['Denti', 5]];
        $o = 1;
        foreach ($items as $it) {
            sec_q("INSERT INTO planner_routine_items (routine_id, user_id, name, duration_minutes, sort_order) VALUES (?,?,?,?,?)",
                  [$rid, $userId, $it[0], $it[1], $o++], 'iisii');
        }
    }
}

/* --- frasi dal json ------------------------------------------------- */
function pl_phrases(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['quotes' => [], 'affirmations' => []];
    if (is_readable(PL_PHRASES_FILE)) {
        $data = json_decode((string)file_get_contents(PL_PHRASES_FILE), true);
        if (is_array($data)) {
            $cache['quotes']       = is_array($data['quotes'] ?? null) ? $data['quotes'] : [];
            $cache['affirmations'] = is_array($data['affirmations'] ?? null) ? $data['affirmations'] : [];
        }
    }
    return $cache;
}

/** Sceglie in modo stabile (stessa settimana = stessa frase) senza ripetere subito. */
function pl_pick_phrase(string $kind, string $weekStart, int $userId): ?array {
    $list = pl_phrases()[$kind] ?? [];
    if (empty($list)) return null;
    $seed = crc32($kind . '|' . $weekStart . '|' . $userId);
    return $list[$seed % count($list)];
}

/* --- numero progressivo della settimana ----------------------------- */
function pl_week_number(int $userId, string $weekStart, array $settings): int {
    // l'ultima settimana con numero impostato a mano prima o uguale a questa
    $row = sec_one(
        "SELECT week_start, week_number FROM planner_weeks
         WHERE user_id = ? AND number_edited = 1 AND week_start <= ?
         ORDER BY week_start DESC LIMIT 1",
        [$userId, $weekStart], 'is'
    );
    if ($row) {
        $diff = (int)round((strtotime($weekStart) - strtotime($row['week_start'])) / 604800);
        return max(1, (int)$row['week_number'] + $diff);
    }
    $base = $settings['first_week_start'] ?: $weekStart;
    $diff = (int)round((strtotime($weekStart) - strtotime($base)) / 604800);
    return max(1, ($settings['first_week_number'] ?: 1) + $diff);
}

function pl_week_row(int $userId, string $weekStart, array $settings): array {
    $row = sec_one("SELECT * FROM planner_weeks WHERE user_id = ? AND week_start = ?", [$userId, $weekStart], 'is');
    $number = pl_week_number($userId, $weekStart, $settings);
    $quote   = pl_pick_phrase('quotes', $weekStart, $userId);
    $affirm  = pl_pick_phrase('affirmations', $weekStart, $userId);
    return [
        'week_start'  => $weekStart,
        'number'      => $row && (int)$row['number_edited'] === 1 ? (int)$row['week_number'] : $number,
        'completed'   => $row ? (int)$row['is_completed'] === 1 : false,
        'learned'     => $row['learned_note'] ?? '',
        'quote'       => $quote['text'] ?? '',
        'affirmation' => $affirm['text'] ?? '',
    ];
}

/* --- categorie / routine ------------------------------------------- */
function pl_categories(int $userId): array {
    $rows = sec_all("SELECT id, name, color, sort_order, is_hidden FROM planner_categories
                     WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $kw = sec_all("SELECT category_id, keyword FROM planner_category_keywords WHERE user_id = ? ORDER BY keyword", [$userId], 'i');
    $byCat = [];
    foreach ($kw as $k) { $byCat[(int)$k['category_id']][] = $k['keyword']; }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id' => $id, 'name' => $r['name'], 'color' => $r['color'],
            'hidden' => (int)$r['is_hidden'] === 1,
            'keywords' => $byCat[$id] ?? [],
        ];
    }
    return $out;
}

/** I calendari dell'utente, in ordine alfabetico. */
function pl_calendars(int $userId): array {
    $rows = sec_all("SELECT id, name, color, is_visible, is_default, google_ref, keywords, def_repeat, def_notify
                     FROM planner_calendars WHERE user_id = ? ORDER BY name", [$userId], 'i');
    if (empty($rows)) {
        sec_q("INSERT INTO planner_calendars (user_id, name, color, is_default, sort_order)
               VALUES (?, 'Principale', NULL, 1, 0)", [$userId], 'i');
        $rows = sec_all("SELECT id, name, color, is_visible, is_default, google_ref, keywords, def_repeat, def_notify
                         FROM planner_calendars WHERE user_id = ?", [$userId], 'i');
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'name' => $r['name'],
            'color' => ($r['color'] !== null && $r['color'] !== '') ? $r['color'] : null,
            'visible' => (int)$r['is_visible'] === 1,
            'is_default' => (int)$r['is_default'] === 1,
            'from_google' => !empty($r['google_ref']),
            'keywords' => (string)($r['keywords'] ?? ''),
            'def_repeat' => (string)($r['def_repeat'] ?? 'none'),
            'def_notify' => (string)($r['def_notify'] ?? 'none')
        ];
    }
    return $out;
}

/** L'id del calendario predefinito: ci finisce chi non ne sceglie uno. */
function pl_default_calendar(int $userId): ?int {
    $r = sec_one("SELECT id FROM planner_calendars WHERE user_id = ? ORDER BY is_default DESC, id LIMIT 1", [$userId], 'i');
    return $r ? (int)$r['id'] : null;
}

function pl_routines(int $userId): array {
    $rows = sec_all("SELECT id, name, color FROM planner_routines WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $items = sec_all("SELECT id, routine_id, name, duration_minutes FROM planner_routine_items
                      WHERE user_id = ? ORDER BY routine_id, sort_order, id", [$userId], 'i');
    $byR = [];
    foreach ($items as $it) {
        $byR[(int)$it['routine_id']][] = [
            'id' => (int)$it['id'], 'name' => $it['name'], 'minutes' => (int)$it['duration_minutes'],
        ];
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $list = $byR[$id] ?? [];
        $total = 0;
        foreach ($list as $x) $total += $x['minutes'];
        $out[] = ['id' => $id, 'name' => $r['name'], 'color' => $r['color'], 'items' => $list, 'total' => $total];
    }
    return $out;
}

/** I progetti segnati come preferiti, in ordine alfabetico. */
function pl_favorites(int $userId): array {
    $rows = sec_all("SELECT id, name, color FROM projects
                     WHERE user_id = ? AND is_favorite = 1
                     ORDER BY name", [$userId], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
    }
    return $out;
}

/** Categoria dedotta dal titolo: la prima parola chiave contenuta vince. */
function pl_auto_category(int $userId, string $title): ?int {
    $t = mb_strtolower($title, 'UTF-8');
    if ($t === '') return null;
    $rows = sec_all("SELECT category_id, keyword FROM planner_category_keywords
                     WHERE user_id = ? ORDER BY CHAR_LENGTH(keyword) DESC", [$userId], 'i');
    foreach ($rows as $r) {
        if ($r['keyword'] !== '' && mb_strpos($t, $r['keyword']) !== false) return (int)$r['category_id'];
    }
    return null;
}

/** Calendario dedotto dal titolo tramite le parole chiave e i default delle Regole Speciali */
function pl_auto_calendar(int $userId, string $title): ?array {
    $t = mb_strtolower($title, 'UTF-8');
    if ($t === '') return null;
    $cals = sec_all("SELECT id, keywords, def_repeat, def_notify FROM planner_calendars WHERE user_id = ? AND keywords != ''", [$userId], 'i');
    foreach ($cals as $c) {
        $kws = explode(',', $c['keywords']);
        foreach ($kws as $kw) {
            $kw = trim(mb_strtolower($kw, 'UTF-8'));
            if ($kw !== '' && mb_strpos($t, $kw) !== false) {
                return ['id' => (int)$c['id'], 'def_repeat' => $c['def_repeat'], 'def_notify' => $c['def_notify']];
            }
        }
    }
    return null;
}

/**
 * Il campo è arrivato davvero nella richiesta, oppure non è stato spedito?
 *
 * Serve perché non tutti i salvataggi mandano l'evento intero: la modifica
 * rapida della durata di un passaggio di routine, per esempio, manda solo gli
 * orari. Senza questa distinzione un campo mancante viene letto come stringa
 * vuota e cancella quello che c'era (era già così per il luogo).
 * Il corpo della richiesta è JSON, quindi non basta guardare $_POST.
 */
function pl_inviato(string $k): bool {
    static $body = null;
    if ($body === null) {
        $raw = (string)file_get_contents('php://input');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        $body = is_array($j) ? $j : [];
    }
    return array_key_exists($k, $body) || array_key_exists($k, $_POST) || array_key_exists($k, $_GET);
}

/* --- eventi --------------------------------------------------------- */
function pl_event_out(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'notes'       => $r['notes'] ?? '',
        'location'    => $r['location'] ?? '',
        /* Il luogo scelto dalla ricerca sulle mappe viaggia separato dal testo
           libero: `location` resta quello che l'utente vede e può correggere a
           mano, le coordinate servono per aprire la mappa nel punto esatto
           anche quando il nome è ambiguo (quante "Via Roma" ci sono). */
        'place_name'  => $r['place_name'] ?? null,
        'place_lat'   => isset($r['place_lat']) && $r['place_lat'] !== null ? (float)$r['place_lat'] : null,
        'place_lon'   => isset($r['place_lon']) && $r['place_lon'] !== null ? (float)$r['place_lon'] : null,
        'place_ref'   => $r['place_ref'] ?? null,
        'duration_locked' => (int)($r['duration_locked'] ?? 1) === 1,
        'guests'      => [],   // riempito da pl_attach_guests
        'date'        => $r['event_date'],
        'start'       => substr($r['start_time'], 0, 5),
        'end'         => substr($r['end_time'], 0, 5),
        'all_day'     => (int)$r['is_all_day'] === 1,
        'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
        'calendar_id' => $r['calendar_id'] !== null ? (int)$r['calendar_id'] : null,
        'project_id'  => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name'=> $r['project_name'] ?? null,
        'project_color'=> $r['project_color'] ?? null,
        'habit_id'    => $r['habit_id'] !== null ? (int)$r['habit_id'] : null,
        'habit_icon'  => $r['habit_icon'] ?? null,
        'habit_color' => $r['habit_color'] ?? null,
        'routine_id'  => $r['routine_id'] !== null ? (int)$r['routine_id'] : null,
        'routine_name'=> $r['routine_name'] ?? null,
        'routine_group'     => $r['routine_group'],
        'routine_collapsed' => (int)$r['routine_collapsed'] === 1,
        'importance'  => $r['importance'],
        'color'       => $r['color'],
        'type'        => $r['item_type'],
        'done'        => (int)$r['is_completed'] === 1,
        'notify'      => $r['notify_type'],
        'notify_min'  => (int)$r['notify_minutes'],
        'repeat'      => $r['repeat_type'],
        'repeat_interval' => (int)$r['repeat_interval'],
        'repeat_unit' => $r['repeat_unit'],
        'repeat_until'=> $r['repeat_until'],
        'series_id'   => $r['series_id'] !== null ? (int)$r['series_id'] : null,
        /* Le occorrenze di una serie non esistono nel database: sono cloni
           calcolati in lettura e portano tutte lo stesso `id`, quello del
           master. Da sole non si distinguono. `occ_date` dice di quale
           ripetizione si tratta, `master_id` a chi appartiene. */
        'occ_date'    => $r['occ_date'] ?? $r['event_date'],
        'master_id'   => isset($r['master_id']) ? (int)$r['master_id'] : (int)$r['id'],
        'is_occurrence' => !empty($r['rrule']) && isset($r['occ_date']),
        'uid'         => (isset($r['master_id']) ? (int)$r['master_id'] : (int)$r['id'])
                         . '@' . ($r['occ_date'] ?? $r['event_date']),
    ];
}

/** Attacca agli eventi la lista degli invitati, con una sola query. */
function pl_attach_guests(int $userId, array $events): array {
    if (empty($events)) return $events;
    $ids = [];
    foreach ($events as $e) { $ids[] = (int)$e['id']; }
    $in = implode(',', array_map('intval', $ids));
    $rows = sec_all("SELECT event_id, email, name FROM event_guests
                     WHERE user_id = ? AND event_id IN ($in)", [$userId], 'i');
    if (empty($rows)) return $events;
    $byEvent = [];
    foreach ($rows as $g) {
        $byEvent[(int)$g['event_id']][] = ['email' => $g['email'], 'name' => $g['name']];
    }
    foreach ($events as $k => $e) {
        if (isset($byEvent[(int)$e['id']])) $events[$k]['guests'] = $byEvent[(int)$e['id']];
    }
    return $events;
}

/* Una data che arriva dal frontend può essere una stringa vuota, un null o
   una sciocchezza. PHP 8 non perdona più: passare null dove è dichiarato
   `string` è un TypeError, e strtotime('') torna false, che date() poi
   interpreta come il 1970. Facciamo passare tutto di qui, così a valle
   esiste una sola forma valida: 'Y-m-d' oppure null. */
function pl_date_or_null(mixed $v): ?string {
    if (is_array($v) || is_object($v) || is_bool($v)) return null;
    $v = trim((string)($v ?? ''));
    if ($v === '' || $v === '0000-00-00' || strcasecmp($v, 'null') === 0) return null;
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/* La RRULE salvata sul master è la verità sulla ripetizione: le colonne
   repeat_* restano per compatibilità con gli eventi vecchi e con Google,
   ma se c'è la regola scritta è quella a comandare. */
function pl_rrule_parse(?string $rrule): ?array {
    if ($rrule === null || trim($rrule) === '') return null;
    $out = ['unit' => null, 'interval' => 1, 'until' => null, 'count' => null];
    foreach (explode(';', str_ireplace('RRULE:', '', trim($rrule))) as $pezzo) {
        $pezzo = trim($pezzo);
        if ($pezzo === '' || !str_contains($pezzo, '=')) continue;
        [$k, $v] = explode('=', $pezzo, 2);
        switch (strtoupper(trim($k))) {
            case 'FREQ':
                $mappa = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'];
                $out['unit'] = $mappa[strtoupper(trim($v))] ?? null;
                break;
            case 'INTERVAL':
                $out['interval'] = max(1, (int)$v);
                break;
            case 'UNTIL':
                $solaData = substr(preg_replace('/[^0-9]/', '', $v), 0, 8);
                if (strlen($solaData) === 8) $out['until'] = substr($solaData, 0, 4) . '-' . substr($solaData, 4, 2) . '-' . substr($solaData, 6, 2);
                break;
            case 'COUNT':
                $out['count'] = max(1, (int)$v);
                break;
        }
    }
    return $out['unit'] === null ? null : $out;
}

/**
 * Le date generate da un master dentro un periodo.
 *
 * Il punto delicato: NON si parte dall'inizio della serie avanzando di un
 * passo per volta. Un evento quotidiano nato tre anni fa richiederebbe mille
 * giri prima di arrivare al mese che stiamo guardando, e con un tetto di
 * sicurezza sul ciclo quel mese resterebbe vuoto senza dire perché. Si
 * calcola quanti passi servono per atterrare dentro il periodo e si salta lì.
 */
function pl_occurrences(array $r, string $from, string $to): array {
    $regola = pl_rrule_parse($r['rrule'] ?? null);

    if ($regola === null) {
        // evento vecchio senza RRULE: si ricostruisce la regola dalle colonne
        $tipo = (string)($r['repeat_type'] ?? 'none');
        if ($tipo === 'none' || $tipo === '') return [];
        $unita = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
        if ($tipo === 'custom') {
            $u = (string)($r['repeat_unit'] ?? 'week');
            $regola = ['unit' => in_array($u, ['day', 'week', 'month', 'year'], true) ? $u : 'week',
                       'interval' => max(1, (int)($r['repeat_interval'] ?? 1)), 'until' => null, 'count' => null];
        } elseif (isset($unita[$tipo])) {
            $regola = ['unit' => $unita[$tipo], 'interval' => 1, 'until' => null, 'count' => null];
        } else {
            return [];
        }
    }

    $inizio = pl_date_or_null($r['event_date'] ?? null);
    if ($inizio === null) return [];

    /* Il limite può stare sulla colonna, dentro la RRULE o in nessuno dei
       due: vince il più stretto fra quelli che esistono. */
    $fine = pl_date_or_null($r['repeat_until'] ?? null);
    if ($regola['until'] !== null) $fine = ($fine === null || $regola['until'] < $fine) ? $regola['until'] : $fine;
    $limite = ($fine !== null && $fine < $to) ? $fine : $to;
    if ($limite < $inizio || $limite < $from) return [];

    $passo = max(1, (int)$regola['interval']);
    $date  = [];
    $tetto = defined('PL_MAX_OCCURRENCES') ? PL_MAX_OCCURRENCES : 400;

    if ($regola['unit'] === 'day' || $regola['unit'] === 'week') {
        $giorni = $regola['unit'] === 'week' ? $passo * 7 : $passo;
        $dInizio = new DateTimeImmutable($inizio);
        $dFrom   = new DateTimeImmutable($from);
        // salto diretto: quanti passi interi mancano per entrare nel periodo
        $distanza = (int)$dInizio->diff($dFrom)->format('%r%a');
        $salti = $distanza > 0 ? (int)ceil($distanza / $giorni) : 0;
        $cur = $dInizio->modify('+' . ($salti * $giorni) . ' days');
        $n = 0;
        while ($cur->format('Y-m-d') <= $limite && $n < $tetto) {
            $g = $cur->format('Y-m-d');
            if ($g >= $from && $g >= $inizio) $date[] = $g;
            $cur = $cur->modify('+' . $giorni . ' days');
            $n++;
        }
    } else {
        $mesi = $regola['unit'] === 'year' ? $passo * 12 : $passo;
        $aI = (int)substr($inizio, 0, 4); $mI = (int)substr($inizio, 5, 2); $gI = (int)substr($inizio, 8, 2);
        $aF = (int)substr($from, 0, 4);   $mF = (int)substr($from, 5, 2);
        $distanza = (($aF - $aI) * 12) + ($mF - $mI);
        $salti = $distanza > 0 ? (int)ceil($distanza / $mesi) : 0;
        /* Un passo indietro di sicurezza: il giorno di partenza può cadere
           dopo la fine del mese di arrivo e il conto per mesi non lo sa. */
        $salti = max(0, $salti - 1);
        $n = 0;
        while ($n < $tetto) {
            $off = ($salti + $n) * $mesi;
            $anno = $aI + intdiv($mI - 1 + $off, 12);
            $mese = (($mI - 1 + $off) % 12) + 1;
            $n++;
            /* Il 31 di un mese che non ce l'ha si salta, non si arrotonda: è
               quello che fa anche Google. Arrotondare al 1º del mese dopo
               farebbe scivolare l'evento in avanti a ogni febbraio. */
            if (!checkdate($mese, $gI, $anno)) {
                if (sprintf('%04d-%02d-01', $anno, $mese) > $limite) break;
                continue;
            }
            $g = sprintf('%04d-%02d-%02d', $anno, $mese, $gI);
            if ($g > $limite) break;
            if ($g >= $from && $g >= $inizio) $date[] = $g;
        }
    }

    if ($regola['count'] !== null && count($date) > $regola['count']) {
        $date = array_slice($date, 0, $regola['count']);
    }
    return $date;
}

function pl_events_range(int $userId, string $from, string $to): array {
    // Estraiamo sia gli eventi normali/eccezioni, sia i "Master" delle serie ripetute
    $rows = sec_all(
        "SELECT e.*, p.name AS project_name, p.color AS project_color,
                h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
         FROM planner_events e
         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
         LEFT JOIN habits h   ON h.id = e.habit_id   AND h.user_id = e.user_id
         LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
         WHERE e.user_id = ?
           AND (
               (e.rrule IS NULL AND e.original_date IS NULL AND e.event_date BETWEEN ? AND ?)
               OR
               (e.rrule IS NOT NULL AND e.event_date <= ? AND (e.repeat_until IS NULL OR e.repeat_until >= ?))
               OR
               (e.original_date IS NOT NULL AND (e.event_date BETWEEN ? AND ? OR e.original_date BETWEEN ? AND ?))
           )",
        [$userId, $from, $to, $to, $from, $from, $to, $from, $to], 'issssssss'
    );

    $instances = [];
    $exceptions = [];

    /* Le eccezioni si leggono anche quando la data SPOSTATA è fuori periodo:
       basta che lo sia la data d'origine. Senza questa seconda condizione un
       evento trascinato al mese successivo tornava a comparire anche nella
       casella di partenza, perché il master non sapeva di essere stato
       scavalcato e ricreava l'occorrenza. */
    foreach ($rows as $r) {
        if ($r['original_date'] !== null && $r['original_date'] !== '' && !empty($r['series_id'])) {
            $exceptions[(int)$r['series_id']][(string)$r['original_date']] = $r;
        }
    }

    foreach ($rows as $r) {
        // Evento normale o eccezione: si mostra così com'è, se cade nel periodo
        if (empty($r['rrule'])) {
            // il marker di cancellazione è solo un segnaposto: non si disegna
            if ((int)($r['is_cancelled'] ?? 0) === 1) continue;
            if ($r['event_date'] < $from || $r['event_date'] > $to) continue;
            $r['occ_date']  = $r['event_date'];
            $r['master_id'] = !empty($r['series_id']) ? (int)$r['series_id'] : (int)$r['id'];
            $instances[] = $r;
            continue;
        }

        // Master con RRULE: i cloni virtuali nascono qui, uno per data generata
        foreach (pl_occurrences($r, $from, $to) as $data) {
            // se per quella data esiste una riga vera (spostata o cancellata) parla lei
            if (isset($exceptions[(int)$r['id']][$data])) continue;
            $clone = $r;
            $clone['event_date'] = $data;
            $clone['occ_date']   = $data;   // quale ripetizione stiamo guardando
            $clone['master_id']  = (int)$r['id'];
            $clone['series_id']  = (int)$r['id'];
            $instances[] = $clone;
        }
    }

    // Riordiniamo l'array finale generato al volo per rispettare il rendering della griglia
    usort($instances, function($a, $b) {
        if ($a['event_date'] !== $b['event_date']) return strcmp($a['event_date'], $b['event_date']);
        if ((int)$a['is_all_day'] !== (int)$b['is_all_day']) return (int)$b['is_all_day'] <=> (int)$a['is_all_day'];
        if ($a['start_time'] !== $b['start_time']) return strcmp($a['start_time'], $b['start_time']);
        if ((int)$a['sort_order'] !== (int)$b['sort_order']) return (int)$a['sort_order'] <=> (int)$b['sort_order'];
        return (int)$a['id'] <=> (int)$b['id'];
    });

    return pl_attach_guests($userId, array_map('pl_event_out', $instances));
}

/* --- priorità ------------------------------------------------------- */
/** Le tabelle delle pastiglie possono non esserci: la sezione è opzionale. */
function pl_pills_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'pill_plans'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/**
 * Le prese la cui ora è già passata e su cui nessuno ha ancora detto niente.
 *
 * «Niente» vuol dire status vuoto o 'pending': 'taken' e 'skipped' sono
 * entrambe risposte, e chi ha detto «saltata» non se la deve ritrovare
 * davanti al ricaricamento successivo — è la ragione per cui il tasto
 * «saltato» scrive nel database invece di limitarsi a chiudere il riquadro.
 *
 * Si guardano anche i giorni prima: una pastiglia dimenticata martedì va
 * chiesta oggi, non sepolta in un giorno che non si guarda più.
 */
function pl_pills_due(int $userId, string $oggi, string $oraOra): array {
    if (!pl_pills_c_e()) return [];
    $rows = sec_all(
        "SELECT d.id, d.due_date, d.due_time, p.name, p.dose, p.color
           FROM pill_doses d
           JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
          WHERE d.user_id = ?
            AND (d.status IS NULL OR d.status = '' OR d.status = 'pending')
            AND (d.due_date < ? OR (d.due_date = ? AND d.due_time <= ?))
            AND d.due_date >= DATE_SUB(?, INTERVAL 7 DAY)
          ORDER BY d.due_date ASC, d.due_time ASC
          LIMIT 6",
        [$userId, $oggi, $oggi, $oraOra, $oggi], 'issss'
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'    => (int)$r['id'],
            'name'  => $r['name'],
            'dose'  => (string)($r['dose'] ?? ''),
            'color' => $r['color'],
            'date'  => (string)$r['due_date'],
            'time'  => substr((string)$r['due_time'], 0, 5),
            'oggi'  => (string)$r['due_date'] === $oggi,
        ];
    }
    return $out;
}

function pl_priorities_range(int $userId, string $from, string $to): array {
    $rows = sec_all("SELECT priority_date, position, content, is_done FROM planner_priorities
                     WHERE user_id = ? AND priority_date BETWEEN ? AND ? ORDER BY priority_date, position",
                     [$userId, $from, $to], 'iss');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['priority_date']][] = [
            'position' => (int)$r['position'], 'content' => $r['content'], 'done' => (int)$r['is_done'] === 1,
        ];
    }
    /* Priorità automatiche dai progetti con obiettivo o scadenza nel periodo.
       Oltre al testo si manda anche di quale progetto si tratta e le poche
       cose che servono a riassumerlo: quella riga è l'unico punto del planner
       in cui un progetto si affaccia, e senza l'identificativo l'unica cosa
       che si poteva farci era leggerla. */
    $proj = sec_all("SELECT id, name, notes, status, color, target_date, due_date FROM projects
                     WHERE user_id = ? AND ((target_date BETWEEN ? AND ?) OR (due_date BETWEEN ? AND ?))",
                     [$userId, $from, $to, $from, $to], 'issss');
    foreach ($proj as $p) {
        $info = [
            'project_id'  => (int)$p['id'],
            'project'     => $p['name'],
            'notes'       => $p['notes'],
            'stato'       => $p['status'],
            'color'       => $p['color'],
            'target_date' => $p['target_date'],
            'due_date'    => $p['due_date'],
        ];
        if (!empty($p['target_date']) && $p['target_date'] >= $from && $p['target_date'] <= $to) {
            $out[$p['target_date']][] = array_merge($info, [
                'position' => 0, 'content' => 'Obiettivo: ' . $p['name'], 'done' => false, 'auto' => true,
            ]);
        }
        if (!empty($p['due_date']) && $p['due_date'] >= $from && $p['due_date'] <= $to) {
            $out[$p['due_date']][] = array_merge($info, [
                'position' => 0, 'content' => 'SCADENZA: ' . $p['name'], 'done' => false, 'auto' => true,
            ]);
        }
    }
    return $out;
}

/* --- liste della settimana ------------------------------------------ */
function pl_week_lists(int $userId, string $weekStart): array {
    $lists = sec_all("SELECT id, list_type, sort_order FROM planner_week_lists
                      WHERE user_id = ? AND week_start = ? ORDER BY sort_order, id", [$userId, $weekStart], 'is');
    $out = [];
    foreach ($lists as $l) {
        $items = sec_all("SELECT id, content, is_done FROM planner_week_list_items
                          WHERE list_id = ? AND user_id = ? ORDER BY sort_order, id", [(int)$l['id'], $userId], 'ii');
        $out[] = [
            'id' => (int)$l['id'], 'type' => $l['list_type'],
            'items' => array_map(function ($i) {
                return ['id' => (int)$i['id'], 'content' => $i['content'], 'done' => (int)$i['is_done'] === 1];
            }, $items),
        ];
    }
    return $out;
}

/* --- habit del giorno (letti dalle tabelle di habits) --------------- */
function pl_habit_due(array $h, string $date): bool {
    if ((int)$h['is_archived'] === 1) return false;
    if ($h['start_date'] > $date) return false;
    if (!empty($h['end_date']) && $h['end_date'] < $date) return false;
    if ($h['frequency_type'] === 'daily') {
        $dow = (int)date('N', strtotime($date)); // 1..7
        $mask = (string)$h['weekdays'];
        return isset($mask[$dow - 1]) ? $mask[$dow - 1] === '1' : true;
    }
    if ($h['frequency_type'] === 'interval') {
        $days = max(1, (int)$h['interval_days']);
        $delta = (int)round((strtotime($date) - strtotime($h['start_date'])) / 86400);
        return $delta >= 0 && $delta % $days === 0;
    }
    return true; // weekly/monthly: proponibile ogni giorno
}

/**
 * Le abitudini settimanali: quelle con un obiettivo di volte a settimana,
 * come "palestra 4 volte". Non contano per il giorno singolo ma per la
 * settimana intera, e non c'è un tetto: se vai in palestra 5 volte su 4,
 * il conto dice 5 su 4 e va benissimo.
 */
function pl_habits_weekly(int $userId, string $date): array {
    $weekStart = pl_monday($date);
    $weekEnd   = pl_add_days($weekStart, 6);

    $rows = sec_all("SELECT id, name, icon, color, times_per_week, planner_minutes,
                            start_date, end_date, is_archived
                     FROM habits
                     WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
                       AND frequency_type = 'weekly'
                     ORDER BY sort_order, id", [$userId], 'i');
    if (empty($rows)) return [];

    $fatti = sec_all("SELECT habit_id, COUNT(*) AS c FROM habit_logs
                      WHERE user_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
                      GROUP BY habit_id", [$userId, $weekStart, $weekEnd], 'iss');
    $conta = [];
    foreach ($fatti as $f) { $conta[(int)$f['habit_id']] = (int)$f['c']; }

    $out = [];
    foreach ($rows as $h) {
        if ($h['start_date'] > $weekEnd) continue;
        if (!empty($h['end_date']) && $h['end_date'] < $weekStart) continue;
        $obiettivo = max(1, (int)$h['times_per_week']);
        $done = $conta[(int)$h['id']] ?? 0;
        $out[] = [
            'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'],
            'color' => $h['color'],
            'done' => $done, 'target' => $obiettivo,
            'complete' => $done >= $obiettivo,
            'minutes' => $h['planner_minutes'] !== null ? (int)$h['planner_minutes'] : null,
        ];
    }
    return $out;
}

function pl_habits_for_date(int $userId, string $date): array {
    $rows = sec_all("SELECT id, name, icon, color, habit_kind, goal_type, target_amount, frequency_type,
                            weekdays, interval_days, start_date, end_date, is_archived, planner_minutes
                     FROM habits WHERE user_id = ? AND habit_kind = 'build'
                       AND frequency_type <> 'weekly' ORDER BY sort_order, id", [$userId], 'i');
    $logs = sec_all("SELECT habit_id, status, amount, target_amount FROM habit_logs
                     WHERE user_id = ? AND log_date = ?", [$userId, $date], 'is');
    $byId = [];
    foreach ($logs as $l) { $byId[(int)$l['habit_id']] = $l; }

    $out = [];
    foreach ($rows as $h) {
        if (!pl_habit_due($h, $date)) continue;
        $log = $byId[(int)$h['id']] ?? null;
        $done = $log && ($log['status'] === 'done' || (float)$log['amount'] >= (float)$log['target_amount']);
        $out[] = [
            'id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'],
            'color' => $h['color'], 'done' => (bool)$done,
            // durata stimata scritta in habits: se manca, il planner usa lo scatto
            'minutes' => $h['planner_minutes'] !== null ? (int)$h['planner_minutes'] : null,
            /* A completamento = si spunta e basta, non si conta quanto se n'è
               fatto. Serve al planner per dire, con il fondo del chip, che
               quella di oggi sarebbe una ripetizione: su un'abitudine a
               quantità (tre bicchieri, dieci pagine) rimetterla in agenda è
               invece del tutto normale, e segnalarlo sarebbe un falso allarme. */
            'a_completamento' => ($h['goal_type'] ?? 'completion') === 'completion',
        ];
    }
    return $out;
}

/* Gli anelli concentrici: un cerchio per famiglia di frequenza, e solo per le
   famiglie che hanno almeno un'abitudine.

   Il conto lo fa il server perché è l'unico che ha tutti i registri sotto
   mano: al client arrivano solo le abitudini previste per il giorno mostrato,
   quindi da lì non si potrebbe sapere quante volte una mensile è stata fatta
   nel mese. Restituisce l'ordine giusto, dal ritmo più stretto al più largo:
   il primo dell'elenco è quello che va disegnato più all'interno. */
function pl_rings(int $userId, string $date): array {
    $lunedi   = date('Y-m-d', strtotime($date . ' monday this week'));
    $domenica = date('Y-m-d', strtotime($lunedi . ' +6 days'));
    $mese     = substr($date, 0, 7);

    $habits = sec_all("SELECT id, frequency_type, weekdays, interval_days, start_date, end_date,
                              times_per_week, times_per_month
                       FROM habits
                       WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0", [$userId], 'i');
    if (empty($habits)) return [];

    /* Un solo giro di registri per tutto: giorno, settimana e mese stanno
       dentro l'intervallo del mese allargato alla settimana che lo attraversa. */
    $da = min($lunedi, $mese . '-01');
    $a  = max($domenica, date('Y-m-t', strtotime($date)));
    $logs = sec_all("SELECT habit_id, log_date, status, amount, target_amount
                     FROM habit_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?",
                    [$userId, $da, $a], 'iss');

    $fatto = [];      // habit_id => [data => true]
    foreach ($logs as $l) {
        $ok = $l['status'] === 'done' || (float)$l['amount'] >= (float)$l['target_amount'];
        if ($ok) $fatto[(int)$l['habit_id']][$l['log_date']] = true;
    }

    $conto = [
        'daily'    => ['done' => 0, 'total' => 0, 'label' => 'Oggi'],
        'weekly'   => ['done' => 0, 'total' => 0, 'label' => 'Settimana'],
        'monthly'  => ['done' => 0, 'total' => 0, 'label' => 'Mese'],
        'interval' => ['done' => 0, 'total' => 0, 'label' => 'Personalizzate'],
    ];

    foreach ($habits as $h) {
        $id   = (int)$h['id'];
        $tipo = $h['frequency_type'];
        $suoi = $fatto[$id] ?? [];

        if ($tipo === 'daily' || $tipo === 'interval') {
            /* contano solo nei giorni in cui sono previste */
            if (!pl_habit_due($h, $date)) continue;
            $conto[$tipo]['total']++;
            if (!empty($suoi[$date])) $conto[$tipo]['done']++;
            continue;
        }

        if ($date < $h['start_date']) continue;
        if (!empty($h['end_date']) && $date > $h['end_date']) continue;

        if ($tipo === 'weekly') {
            $n = 0;
            foreach ($suoi as $d => $_) if ($d >= $lunedi && $d <= $domenica) $n++;
            $obiettivo = max(1, (int)$h['times_per_week']);
            $conto['weekly']['total'] += $obiettivo;
            $conto['weekly']['done']  += min($n, $obiettivo);   // oltre l'obiettivo non gonfia l'anello
        } elseif ($tipo === 'monthly') {
            $n = 0;
            foreach ($suoi as $d => $_) if (substr($d, 0, 7) === $mese) $n++;
            $obiettivo = max(1, (int)$h['times_per_month']);
            $conto['monthly']['total'] += $obiettivo;
            $conto['monthly']['done']  += min($n, $obiettivo);
        }
    }

    /* Dall'interno verso l'esterno, e solo chi ha davvero qualcosa. */
    $out = [];
    foreach (['daily', 'weekly', 'monthly', 'interval'] as $k) {
        if ($conto[$k]['total'] <= 0) continue;
        $out[] = [
            'key'   => $k,
            'label' => $conto[$k]['label'],
            'done'  => (int)$conto[$k]['done'],
            'total' => (int)$conto[$k]['total'],
        ];
    }
    return $out;
}

/* --- diario condiviso ---------------------------------------------- */
function pl_diary_dates(int $userId, string $from, string $to): array {
    $rows = sec_all("SELECT entry_date, mood, energy, note FROM habit_daily_journal
                     WHERE user_id = ? AND entry_date BETWEEN ? AND ?", [$userId, $from, $to], 'iss');
    $out = [];
    foreach ($rows as $r) {
        $has = trim((string)$r['note']) !== '';
        $out[$r['entry_date']] = [
            'mood' => $r['mood'] !== null ? (int)$r['mood'] : null,
            'energy' => $r['energy'] !== null ? (int)$r['energy'] : null,
            'has_text' => $has,
        ];
    }
    $lists = sec_all("SELECT entry_date, COUNT(*) AS c FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> '' GROUP BY entry_date",
                      [$userId, $from, $to], 'iss');
    foreach ($lists as $l) {
        if (!isset($out[$l['entry_date']])) $out[$l['entry_date']] = ['mood' => null, 'energy' => null, 'has_text' => false];
        $out[$l['entry_date']]['has_lists'] = (int)$l['c'] > 0;
    }
    return $out;
}

/* =====================================================================
   LUOGHI — ricerca su Google Places, con OpenStreetMap come rete di scorta

   PERCHÉ SI È CAMBIATO SERVIZIO. Nominatim è un geocodificatore di indirizzi:
   sa dov'è una via, non sa che esiste un ristorante che si chiama in un certo
   modo, e sui civici copre solo quello che gli volontari di OSM hanno mappato.
   Cercando i posti per nome non si trovava quasi niente.

   COME FUNZIONA ADESSO. Due passaggi, come li fa Google Maps:
   1. mentre si scrive, `places:autocomplete` propone i posti (solo nomi e
      identificativi, nessuna coordinata: è la chiamata a buon mercato);
   2. quando se ne sceglie uno, `places/{id}` restituisce le coordinate.
   Il nome da mostrare lo abbiamo già dal primo passaggio, quindi al secondo
   chiediamo solo indirizzo e posizione: sono campi della fascia "Essentials",
   la meno cara, mentre il nome commerciale starebbe in quella sopra.

   Le chiamate partono dal server e non dal browser, come per il meteo: la
   chiave resta qui dentro e l'indirizzo IP di chi cerca non arriva a Google.
   ===================================================================== */

/**
 * La cache dei luoghi è facoltativa: se la tabella non c'è (migrazione non
 * eseguita) la ricerca funziona lo stesso, solo senza risparmiare chiamate.
 * Meglio una funzione più lenta che una pagina che smette di funzionare
 * perché manca una ALTER TABLE.
 */
function pl_place_cache_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'place_cache'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/** Chiave della cache: la stessa ricerca scritta con maiuscole o spazi
    diversi non deve contare come una ricerca nuova. */
function pl_place_key(string $q): string {
    return sha1(mb_strtolower(trim($q), 'UTF-8'));
}

/** Legge dalla cache, se la tabella c'è e la risposta non è troppo vecchia. */
function pl_place_cache_get(string $key, int $giorni): ?array {
    if (!pl_place_cache_c_e()) return null;
    /* I giorni si scrivono dentro la query e non come segnaposto: non è un
       dato che arriva dal browser (lo decide il codice qui sopra), e alcuni
       driver storcono il naso su un segnaposto dentro INTERVAL. */
    $giorni = max(1, min(365, $giorni));
    $row = sec_one("SELECT payload FROM place_cache
                    WHERE q_key = ? AND fetched_at > (NOW() - INTERVAL " . (int)$giorni . " DAY)", [$key], 's');
    if (!$row) return null;
    $c = json_decode($row['payload'], true);
    return is_array($c) ? $c : null;
}

function pl_place_cache_put(string $key, array $dati): void {
    if (!pl_place_cache_c_e()) return;
    sec_q("INSERT INTO place_cache (q_key, payload) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
          [$key, json_encode($dati, JSON_UNESCAPED_UNICODE)], 'ss');
    /* pulizia saltuaria: le ricerche vecchie non servono più a nessuno, e le
       condizioni di Google chiedono di non conservare a lungo i dati dei posti
       (fa eccezione l'identificativo, che infatti finisce sull'evento). */
    if (random_int(1, 50) === 1) {
        sec_q("DELETE FROM place_cache WHERE fetched_at < (NOW() - INTERVAL 30 DAY)");
    }
}

/** Una chiamata sola a Google, in JSON. Restituisce null se qualcosa va storto:
    chi chiama decide cosa fare, e in ogni caso la pagina non deve rompersi. */
function pl_google_call(string $url, ?array $body, array $headers): ?array {
    $raw = null;
    $headers[] = 'Accept: application/json';
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('luoghi', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => $body !== null ? 'POST' : 'GET',
            'timeout' => 8, 'ignore_errors' => true,
            'header'  => implode("\r\n", $headers) . "\r\n",
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return null;
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return null;
    /* Google dice cosa non gli è piaciuto: finisce nel registro degli errori,
       se no una chiave sbagliata sembra semplicemente "nessun risultato". */
    if (isset($data['error'])) {
        sec_log_error('luoghi', 'Google: ' . ($data['error']['message'] ?? 'errore'));
        return null;
    }
    return $data;
}

/**
 * I suggerimenti mentre si scrive. Niente coordinate: quelle costano una
 * chiamata a parte e si chiedono solo per il posto che viene davvero scelto.
 */
function pl_place_google(string $q): array {
    $key = pl_place_key('g:' . $q);
    /* Cache corta: è una scorciatoia per chi torna sulla stessa ricerca dopo
       un attimo, non un archivio. */
    $c = pl_place_cache_get($key, 1);
    if ($c !== null) return $c;

    $data = pl_google_call(
        'https://places.googleapis.com/v1/places:autocomplete',
        ['input' => $q, 'languageCode' => 'it', 'regionCode' => 'it'],
        ['X-Goog-Api-Key: ' . GOOGLE_PLACES_KEY]
    );
    if ($data === null) return [];

    $out = [];
    foreach (($data['suggestions'] ?? []) as $s) {
        $p = $s['placePrediction'] ?? null;
        if (!$p || empty($p['placeId'])) continue;
        $main = $p['structuredFormat']['mainText']['text'] ?? ($p['text']['text'] ?? '');
        $sec  = $p['structuredFormat']['secondaryText']['text'] ?? '';
        if ($main === '') continue;
        $out[] = [
            'name'   => mb_substr($main, 0, 120, 'UTF-8'),
            'detail' => mb_substr($sec, 0, 200, 'UTF-8'),
            'full'   => mb_substr(trim($main . ($sec !== '' ? ', ' . $sec : '')), 0, 255, 'UTF-8'),
            /* le coordinate arrivano dopo, con pl_place_punto() */
            'lat'    => null,
            'lon'    => null,
            'ref'    => (string)$p['placeId'],
        ];
    }
    pl_place_cache_put($key, $out);
    return $out;
}

/**
 * Le coordinate del posto scelto. Si chiedono solo i campi della fascia
 * economica (posizione e indirizzo): il nome ce l'ha già la pagina, presa dal
 * suggerimento, e chiederlo qui farebbe scivolare la chiamata in una fascia
 * più cara per un dato che abbiamo già.
 */
function pl_place_punto(string $placeId): ?array {
    $key = pl_place_key('p:' . $placeId);
    $c = pl_place_cache_get($key, 30);
    if ($c !== null) return $c;

    $data = pl_google_call(
        'https://places.googleapis.com/v1/places/' . rawurlencode($placeId) . '?languageCode=it',
        null,
        ['X-Goog-Api-Key: ' . GOOGLE_PLACES_KEY,
         'X-Goog-FieldMask: id,location,formattedAddress']
    );
    if ($data === null || !isset($data['location']['latitude'])) return null;

    $punto = [
        'lat'  => round((float)$data['location']['latitude'], 7),
        'lon'  => round((float)$data['location']['longitude'], 7),
        'full' => mb_substr((string)($data['formattedAddress'] ?? ''), 0, 255, 'UTF-8'),
        'ref'  => (string)($data['id'] ?? $placeId),
    ];
    pl_place_cache_put($key, $punto);
    return $punto;
}

/** Con la chiave si va su Google; senza, si continua con OpenStreetMap come
    prima. Serve il giorno in cui la chiave scade o viene tolta: meglio una
    ricerca meno brava che un campo che non risponde più. */
function pl_place_lookup(string $q): array {
    if (defined('GOOGLE_PLACES_KEY') && GOOGLE_PLACES_KEY !== '') return pl_place_google($q);
    return pl_place_osm($q);
}

function pl_place_osm(string $q): array {
    $key = pl_place_key($q);

    $c = pl_place_cache_get($key, 30);
    if ($c !== null) return $c;

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'              => $q,
        'format'         => 'jsonv2',
        'addressdetails' => 1,
        'limit'          => 6,
        'accept-language'=> 'it',
    ]);

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            /* Nominatim rifiuta chi non si presenta: è nelle sue condizioni
               d'uso, non un dettaglio facoltativo. */
            CURLOPT_USERAGENT      => 'Nisama planner (uso personale)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('luoghi', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 8, 'ignore_errors' => true,
            'header'  => "User-Agent: Nisama planner (uso personale)\r\nAccept: application/json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return [];

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return [];

    $out = [];
    foreach ($data as $p) {
        if (!isset($p['lat'], $p['lon'])) continue;
        $completo = (string)($p['display_name'] ?? '');
        /* Nominatim restituisce il nome per esteso, con nazione e CAP: come
           titolo del suggerimento tengo solo la prima parte, il resto va
           sotto in piccolo. Così l'elenco resta leggibile sul telefono. */
        $pezzi  = array_map('trim', explode(',', $completo));
        $titolo = ($p['name'] ?? '') !== '' ? (string)$p['name'] : (string)array_shift($pezzi);
        if (($p['name'] ?? '') !== '' && count($pezzi) && $pezzi[0] === $p['name']) array_shift($pezzi);
        $out[] = [
            'name'   => mb_substr($titolo, 0, 120, 'UTF-8'),
            'detail' => mb_substr(implode(', ', $pezzi), 0, 200, 'UTF-8'),
            'full'   => mb_substr($completo, 0, 255, 'UTF-8'),
            'lat'    => round((float)$p['lat'], 7),
            'lon'    => round((float)$p['lon'], 7),
            /* riferimento stabile del luogo: serve a ritrovarlo domani anche
               se il nome cambia (osm_type + osm_id sono l'identificativo) */
            'ref'    => isset($p['osm_type'], $p['osm_id'])
                        ? substr((string)$p['osm_type'], 0, 1) . (int)$p['osm_id'] : null,
        ];
    }

    pl_place_cache_put($key, $out);
    return $out;
}

/* ---------------------------------------------------------------------
   RUBRICA DEI LUOGHI

   I posti di tutti i giorni (casa, lavoro, palestra) non si cercano ogni
   volta: si salvano una volta e si riusano. Le parole d'aggancio servono a
   compilarli da soli quando compaiono nel titolo dell'evento.

   Come per la cache, la tabella è facoltativa: se la migrazione non è stata
   eseguita la rubrica resta vuota e il resto del planner non se ne accorge.
   --------------------------------------------------------------------- */
function pl_places_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'planner_places'");
        $c_e = !empty($r);
    }
    return $c_e;
}

function pl_places(int $userId): array {
    if (!pl_places_c_e()) return [];
    $rows = sec_all("SELECT id, label, keywords, place_name, address, place_lat, place_lon, place_ref
                     FROM planner_places WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int)$r['id'],
            'label'    => $r['label'],
            'keywords' => $r['keywords'],
            'name'     => $r['place_name'],
            'address'  => $r['address'],
            'lat'      => $r['place_lat'] !== null ? (float)$r['place_lat'] : null,
            'lon'      => $r['place_lon'] !== null ? (float)$r['place_lon'] : null,
            'ref'      => $r['place_ref'],
        ];
    }
    return $out;
}

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
            sec_die_json('Routine non trovata.', 404, 'not_found');
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
        if ($ref === '') sec_die_json('Posto non indicato.', 422, 'empty');
        $punto = pl_place_punto($ref);
        if (!$punto) sec_die_json('Posizione non trovata.', 404, 'not_found');
        sec_json(['ok' => true, 'place' => $punto]);
    }

    /* ---------------- rubrica: salva un posto ----------------
       Le coordinate arrivano già validate dal campo del luogo, ma qui si
       rivalidano lo stesso: quello che entra dal browser non è mai buono per
       partito preso, nemmeno quando l'abbiamo mandato noi un attimo prima. */
    if ($ajax === 'place_book_save') {
        if (!pl_places_c_e()) sec_die_json('Rubrica dei luoghi non installata.', 400, 'no_table');
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
        if ($label === '') sec_die_json('Manca il nome del posto.', 422, 'empty');

        if ($id > 0) {
            if (!sec_owns('planner_places', $id, $current_user_id)) {
                sec_die_json('Posto non trovato.', 404, 'not_found');
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
        if (!pl_places_c_e()) sec_die_json('Rubrica dei luoghi non installata.', 400, 'no_table');
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_places', $id, $current_user_id)) {
            sec_die_json('Posto non trovato.', 404, 'not_found');
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
        if (pl_minutes($end) <= pl_minutes($start) && !$allDay) {
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
        
        if ($title === '') $title = $habitId > 0 ? 'Abitudine' : 'Senza titolo';

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
            $title, $notes, $luogo, $date, $start, $end, $allDay ? 1 : 0,
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
                sec_die_json('Evento non trovato.', 404, 'not_found');
            }
            
            // SE STIAMO CREANDO UN'ECCEZIONE SINGOLA
            if ($editMode === 'one' && $origDate) {
                $params_exc = [
                    $title, $notes, $luogo, $date, $start, $end, $allDay ? 1 : 0,
                    $catId > 0 ? $catId : null, $calId > 0 ? $calId : null,
                    $projId > 0 ? $projId : null, $habitId > 0 ? $habitId : null,
                    $imp, $color, $type, $notify, $nMin, 
                    'none', 1, 'week', null, // L'eccezione isolata non si ripete più a sua volta
                    $placeName !== '' ? $placeName : null, $placeLat, $placeLon, $placeRef !== '' ? $placeRef : null,
                    $durLock, null, $current_user_id, $id, $origDate
                ];
                
                sec_q("INSERT INTO planner_events
                       (title, notes, location, event_date, start_time, end_time, is_all_day, category_id, calendar_id,
                        project_id, habit_id, importance, color, item_type, notify_type, notify_minutes,
                        repeat_type, repeat_interval, repeat_unit, repeat_until,
                        place_name, place_lat, place_lon, place_ref, duration_locked, rrule, user_id, series_id, original_date)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params_exc);
                $newId = sec_insert_id();
            } else {
                // MODIFICA NORMALE O "TUTTA LA SERIE"
                $params[] = $id; $params[] = $current_user_id;
                sec_q("UPDATE planner_events SET title=?, notes=?, location=?, event_date=?, start_time=?, end_time=?, is_all_day=?,
                              category_id=?, calendar_id=?, project_id=?, habit_id=?, importance=?, color=?, item_type=?,
                              notify_type=?, notify_minutes=?, repeat_type=?, repeat_interval=?, repeat_unit=?, repeat_until=?,
                              place_name=?, place_lat=?, place_lon=?, place_ref=?, duration_locked=?, rrule=?
                       WHERE id=? AND user_id=?", $params);
                $newId = $id;
            }
        } else {
            $params[] = $current_user_id;
            sec_q("INSERT INTO planner_events
                   (title, notes, location, event_date, start_time, end_time, is_all_day, category_id, calendar_id,
                    project_id, habit_id, importance, color, item_type, notify_type, notify_minutes,
                    repeat_type, repeat_interval, repeat_unit, repeat_until,
                    place_name, place_lat, place_lon, place_ref, duration_locked, rrule, user_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $params);
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
            sec_die_json('Evento non trovato.', 404, 'not_found');
        }
        $ev = sec_one("SELECT start_time, end_time, event_date FROM planner_events WHERE id = ? AND user_id = ?", [$id, $current_user_id], 'ii');
        $dur = pl_minutes($ev['end_time']) - pl_minutes($ev['start_time']);
        $newStart = pl_minutes($start);
        if ($newStart + $dur > 1439) $newStart = max(0, 1439 - $dur);
        sec_q("UPDATE planner_events SET start_time=?, end_time=?, event_date=? WHERE id=? AND user_id=?",
              [pl_time($newStart), pl_time($newStart + $dur), $date ?: $ev['event_date'], $id, $current_user_id]);
        /* trascinare un task lo sposta anche negli elenchi: data e ora sono
           la stessa cosa vista da due parti */
        pl_task_to_todo($current_user_id, $id);
        sec_json(['ok' => true, 'start' => substr(pl_time($newStart), 0, 5), 'end' => substr(pl_time($newStart + $dur), 0, 5)]);
    }

    /* ---------------- spunta task / habit ---------------- */
    if ($ajax === 'event_toggle') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json('Evento non trovato.', 404, 'not_found');
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

        if (!sec_owns('planner_events', $id, $current_user_id)) sec_die_json('Evento non trovato.', 404, 'not_found');
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
        if (!is_array($ids) || empty($ids) || $minutes === 0) sec_die_json('Niente da spostare.', 422, 'empty');
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
        if (!$from || !$to || $from === $to) sec_die_json('Giorni non validi.', 422, 'invalid');
        $rows = sec_all("SELECT * FROM planner_events WHERE user_id=? AND event_date=?", [$current_user_id, $from], 'is');
        $n = 0;
        foreach ($rows as $r) {
            sec_q("INSERT INTO planner_events
                   (user_id, title, notes, event_date, start_time, end_time, is_all_day, category_id, project_id,
                    habit_id, routine_id, routine_group, routine_collapsed, importance, color, item_type,
                    notify_type, notify_minutes, sort_order)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   [$current_user_id, $r['title'], $r['notes'], $to, $r['start_time'], $r['end_time'],
                    (int)$r['is_all_day'], $r['category_id'], $r['project_id'], $r['habit_id'], $r['routine_id'],
                    $r['routine_group'], (int)$r['routine_collapsed'], $r['importance'], $r['color'],
                    $r['item_type'], $r['notify_type'], (int)$r['notify_minutes'], (int)$r['sort_order']]);
            $n++;
        }
        sec_json(['ok' => true, 'copied' => $n, 'events' => pl_events_range($current_user_id, $to, $to)]);
    }

    /* ---------------- inserimento di una routine ---------------- */
    if ($ajax === 'routine_insert') {
        $rid   = sec_int(sec_in('routine_id'), 1, PHP_INT_MAX, 0);
        $date  = sec_date(sec_in('date'), date('Y-m-d'));
        $start = sec_time(sec_in('start'), '07:00:00');
        if (!sec_owns('planner_routines', $rid, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
        $r = sec_one("SELECT name, color FROM planner_routines WHERE id=? AND user_id=?", [$rid, $current_user_id], 'ii');
        $items = sec_all("SELECT name, duration_minutes FROM planner_routine_items
                          WHERE routine_id=? AND user_id=? ORDER BY sort_order, id", [$rid, $current_user_id], 'ii');
        if (empty($items)) sec_die_json('Questa routine non ha ancora eventi.', 422, 'empty');
        $group = bin2hex(random_bytes(16));
        $cursor = pl_minutes($start);
        foreach ($items as $it) {
            $dur = max(1, (int)$it['duration_minutes']);
            $end = min(1439, $cursor + $dur);
            sec_q("INSERT INTO planner_events
                   (user_id, title, event_date, start_time, end_time, routine_id, routine_group, routine_collapsed, color, notify_type, notify_minutes)
                   VALUES (?,?,?,?,?,?,?,1,?,'none',0)",
                   [$current_user_id, $it['name'], $date, pl_time($cursor), pl_time($end), $rid, $group, $r['color']]);
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
        if (!sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json('Lista non trovata.', 404, 'not_found');
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
            if (!sec_owns('planner_week_list_items', $itemId, $current_user_id)) sec_die_json('Voce non trovata.', 404, 'not_found');
            if ($text === '') {
                sec_q("DELETE FROM planner_week_list_items WHERE id=? AND user_id=?", [$itemId, $current_user_id], 'ii');
                sec_json(['ok' => true, 'deleted' => true]);
            }
            sec_q("UPDATE planner_week_list_items SET content=?, is_done=? WHERE id=? AND user_id=?",
                  [$text, $done, $itemId, $current_user_id], 'siii');
            sec_json(['ok' => true, 'id' => $itemId]);
        }
        if ($listId === 0 || !sec_owns('planner_week_lists', $listId, $current_user_id)) sec_die_json('Lista non trovata.', 404, 'not_found');
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
        if ($name === '') sec_die_json('Serve un nome per la categoria.', 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
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
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        sec_q("UPDATE planner_events SET category_id=NULL WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_category_keywords WHERE category_id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_q("DELETE FROM planner_categories WHERE id=? AND user_id=?", [$id, $current_user_id], 'ii');
        sec_json(['ok' => true, 'categories' => pl_categories($current_user_id)]);
    }
    if ($ajax === 'category_hide') {
        $id     = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        $hidden = sec_bool(sec_in('hidden')) ? 1 : 0;
        if (!sec_owns('planner_categories', $id, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        sec_q("UPDATE planner_categories SET is_hidden=? WHERE id=? AND user_id=?", [$hidden, $id, $current_user_id], 'iii');
        sec_json(['ok' => true]);
    }
    /* parola chiave: rifiutata se già presente in un'altra categoria */
    if ($ajax === 'keyword_add') {
        $catId   = sec_int(sec_in('category_id'), 1, PHP_INT_MAX, 0);
        $keyword = sec_key(sec_in('keyword'), 60);
        if (!sec_owns('planner_categories', $catId, $current_user_id)) sec_die_json('Categoria non trovata.', 404, 'not_found');
        if ($keyword === '') sec_die_json('Scrivi una parola.', 422, 'invalid');
        $clash = sec_one("SELECT k.category_id, c.name FROM planner_category_keywords k
                          JOIN planner_categories c ON c.id = k.category_id
                          WHERE k.user_id = ? AND k.keyword = ? LIMIT 1", [$current_user_id, $keyword], 'is');
        if ($clash) {
            sec_json([
                'ok' => false, 'error' => 'duplicate',
                'category_id' => (int)$clash['category_id'], 'category_name' => $clash['name'],
                'message' => '"' . $keyword . '" è già in ' . $clash['name'] . '.',
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
        if ($name === '') sec_die_json('Serve un nome per la routine.', 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
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
        if (!sec_owns('planner_routines', $id, $current_user_id)) sec_die_json('Routine non trovata.', 404, 'not_found');
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
        $noCat = ['id' => 0, 'name' => 'Senza categoria', 'color' => '#E4DDD3', 'minutes' => 0, 'count' => 0];

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
        if ($name === '') sec_die_json('Serve un nome per il calendario.', 422, 'invalid');
        if ($id > 0) {
            if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json('Calendario non trovato.', 404, 'not_found');
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
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json('Calendario non trovato.', 404, 'not_found');
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
            if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json('Calendario non trovato.', 404, 'not_found');
            sec_q("UPDATE planner_calendars SET is_visible = (id = ?) WHERE user_id = ?", [$id, $current_user_id], 'ii');
        }
        sec_json(['ok' => true, 'calendars' => pl_calendars($current_user_id)]);
    }

    if ($ajax === 'calendar_delete') {
        $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json('Calendario non trovato.', 404, 'not_found');
        /* Il principale non si elimina: è il fondo del secchio, il posto in
           cui finisce quello che non ne ha scelto un altro. Senza, gli eventi
           che ci stanno dentro non avrebbero dove andare. */
        $chi = sec_one("SELECT is_default FROM planner_calendars WHERE id = ? AND user_id = ?",
                       [$id, $current_user_id], 'ii');
        if ($chi && (int)$chi['is_default'] === 1) {
            sec_die_json('Il calendario principale non si può eliminare.', 422, 'is_default');
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
        
        if (!sec_owns('planner_calendars', $id, $current_user_id)) sec_die_json('Calendario non trovato.', 404, 'not_found');
        
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
        if (!$mail) sec_die_json('Indirizzo non valido.', 422, 'invalid');
        if ($nome === '') $nome = explode('@', $mail)[0];
        if ($id > 0) {
            if (!sec_owns('contacts', $id, $current_user_id)) sec_die_json('Contatto non trovato.', 404, 'not_found');
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
        if (!sec_owns('contacts', $id, $current_user_id)) sec_die_json('Contatto non trovato.', 404, 'not_found');
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

    sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');
}

/* =====================================================================
   FUNZIONI DI SUPPORTO USATE DAGLI ENDPOINT
   ===================================================================== */

/** Crea le occorrenze future di un evento ripetuto (max PL_MAX_OCCURRENCES). */
function pl_materialize_repeats(int $userId, int $masterId): void {
    $m = sec_one("SELECT * FROM planner_events WHERE id=? AND user_id=?", [$masterId, $userId], 'ii');
    if (!$m || $m['repeat_type'] === 'none') return;

    $unitMap = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
    $unit = $m['repeat_type'] === 'custom' ? $m['repeat_unit'] : ($unitMap[$m['repeat_type']] ?? 'week');
    $step = $m['repeat_type'] === 'custom' ? max(1, (int)$m['repeat_interval']) : 1;
    $until = $m['repeat_until'] ?: date('Y-m-d', strtotime('+1 year'));

    $date = $m['event_date'];
    $count = 0;
    while ($count < PL_MAX_OCCURRENCES) {
        $date = date('Y-m-d', strtotime($date . ' +' . $step . ' ' . $unit));
        if ($date > $until) break;
        /* Le occorrenze copiano anche luogo e lucchetto: un corso settimanale
           che si tiene sempre nello stesso posto lo perdeva dalla seconda
           volta in poi. */
        sec_q("INSERT INTO planner_events
               (user_id, title, notes, event_date, start_time, end_time, is_all_day, category_id, project_id,
                habit_id, importance, color, item_type, notify_type, notify_minutes,
                repeat_type, repeat_interval, repeat_unit, repeat_until, series_id,
                location, place_name, place_lat, place_lon, place_ref, duration_locked)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
               [$userId, $m['title'], $m['notes'], $date, $m['start_time'], $m['end_time'], (int)$m['is_all_day'],
                $m['category_id'], $m['project_id'], $m['habit_id'], $m['importance'], $m['color'], $m['item_type'],
                $m['notify_type'], (int)$m['notify_minutes'], $m['repeat_type'], (int)$m['repeat_interval'],
                $m['repeat_unit'], $m['repeat_until'], $masterId,
                $m['location'], $m['place_name'], $m['place_lat'], $m['place_lon'], $m['place_ref'],
                (int)($m['duration_locked'] ?? 1)]);
        $count++;
    }
}

/**
 * Mette in coda le cancellazioni da riportare su Google Calendar.
 * Se la tabella non esiste (migrazione non eseguita) non fa nulla:
 * il planner continua a funzionare comunque.
 */
function pl_queue_google_delete(int $userId, string $where, array $params): void {
    /* Si annota anche IN QUALE calendario Google viveva l'evento. Senza, chi
       smaltisce la coda poteva solo tentare il calendario dedicato: per un
       evento che stava altrove — uno importato dai compleanni, o mandato in
       un calendario suo dal verso di ritorno — Google rispondeva 404, la
       riga veniva presa per fatta e l'evento restava là per sempre.
       Con più calendari in gioco quel caso non è più l'eccezione: è la
       norma. */
    $rows = sec_all("SELECT google_event_id, google_calendar_id FROM planner_events
                     WHERE " . $where . " AND google_event_id IS NOT NULL", $params);
    if (empty($rows)) return;
    foreach ($rows as $r) {
        @sec_q("INSERT INTO google_sync_queue (user_id, google_event_id, google_calendar_id, action)
                VALUES (?,?,?,'delete')",
               [$userId, $r['google_event_id'], $r['google_calendar_id']], 'iss');
    }
}

/** Spunta/despunta un'abitudine nelle tabelle di habits (fonte unica). */
/* ----------------------------------------------------------------
   DOVE STA LA VERITÀ: in `todos`.

   Un evento con item_type='task' non è una cosa a sé, è la faccia in
   agenda di una riga di `todos`; il filo che li tiene insieme è la
   colonna `todos.planner_event_id`. Il verso attività → evento esisteva
   già (td_sync_planner, in todo_api.php); questo è il verso che mancava,
   per i task creati direttamente dal planner.

   È questa scelta a togliere di mezzo i doppioni: l'attività nasce GIÀ
   collegata all'evento che l'ha generata, quindi quando verrà risalvata
   dalla pagina attività td_sync_planner troverà il collegamento e
   aggiornerà quell'evento invece di crearne un secondo. Chi scrive
   codice nuovo si regoli così: si scrive nell'attività e l'evento
   segue, oppure si scrive nell'evento e si chiama questa. Mai le due
   cose insieme sullo stesso dato. */
function pl_task_to_todo(int $uid, int $eventId): void {
    $ev = sec_one("SELECT * FROM planner_events WHERE id = ? AND user_id = ?", [$eventId, $uid], 'ii');
    if (!$ev) return;

    /* Abitudini e routine restano fuori. Sono spunte generate da altro,
       non cose scritte a mano: farne attività vorrebbe dire riempire gli
       elenchi di una riga al giorno per ogni abitudine. */
    if (!empty($ev['habit_id']) || !empty($ev['routine_id']) || !empty($ev['routine_group'])) return;

    $collegata = sec_one("SELECT id FROM todos WHERE user_id = ? AND planner_event_id = ? LIMIT 1",
                         [$uid, $eventId], 'ii');

    if (($ev['item_type'] ?? 'event') !== 'task') {
        /* declassato a evento normale: l'attività collegata non si
           cancella — è roba scritta dall'utente — ma smette di essere
           l'ombra di questo evento, se no resterebbero legate per sempre */
        if ($collegata) {
            sec_q("UPDATE todos SET planner_event_id = NULL WHERE id = ? AND user_id = ?",
                  [(int)$collegata['id'], $uid], 'ii');
        }
        return;
    }

    $allDay = (int)$ev['is_all_day'] === 1;
    $ora    = $allDay ? null : $ev['start_time'];
    $durata = max(5, min(1440, pl_minutes($ev['end_time']) - pl_minutes($ev['start_time'])));
    $fatta  = (int)$ev['is_completed'];
    $prog   = $ev['project_id'] !== null ? (int)$ev['project_id'] : null;

    if ($collegata) {
        sec_q("UPDATE todos SET title=?, due_date=?, due_time=?, duration_minutes=?, project_id=?,
                      is_done=?, done_at=" . ($fatta ? 'NOW()' : 'NULL') . "
               WHERE id=? AND user_id=?",
              [$ev['title'], $ev['event_date'], $ora, $durata, $prog, $fatta,
               (int)$collegata['id'], $uid]);
        return;
    }

    $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
    sec_q("INSERT INTO todos (user_id, list_type, title, due_date, due_time, duration_minutes,
                              project_id, is_done, done_at, sort_order, source, planner_event_id)
           VALUES (?, 'todo', ?, ?, ?, ?, ?, ?, " . ($fatta ? 'NOW()' : 'NULL') . ", ?, 'page', ?)",
          [$uid, $ev['title'], $ev['event_date'], $ora, $durata, $prog, $fatta,
           (int)($next['n'] ?? 1), $eventId]);
}

function pl_sync_habit_log(int $userId, int $habitId, string $date, bool $done): void {
    $h = sec_one("SELECT target_amount, step_amount FROM habits WHERE id=? AND user_id=?", [$habitId, $userId], 'ii');
    if (!$h) return;
    $target = (float)$h['target_amount'];
    if ($done) {
        sec_q("INSERT INTO habit_logs (habit_id, user_id, log_date, status, amount, target_amount, completions)
               VALUES (?,?,?,'done',?,?,1)
               ON DUPLICATE KEY UPDATE status='done', amount=GREATEST(amount, VALUES(amount)),
                                       target_amount=VALUES(target_amount), completions=completions+1",
               [$habitId, $userId, $date, $target, $target], 'iisdd');
        sec_q("INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source) VALUES (?,?,?,?, 'quick_add')",
              [$habitId, $userId, $date, $target], 'iisd');
    } else {
        sec_q("UPDATE habit_logs SET status='partial', amount=0, completions=0
               WHERE habit_id=? AND user_id=? AND log_date=?", [$habitId, $userId, $date], 'iis');
        sec_q("INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source) VALUES (?,?,?,?, 'undo')",
              [$habitId, $userId, $date, -$target], 'iisd');
    }
    // riflette la spunta anche sugli eventi collegati dello stesso giorno
    sec_q("UPDATE planner_events SET is_completed=?, completed_at=" . ($done ? 'NOW()' : 'NULL') . "
           WHERE user_id=? AND habit_id=? AND event_date=?",
          [$done ? 1 : 0, $userId, $habitId, $date], 'iiis');
}

/* =====================================================================
   DATI INIZIALI DELLA PAGINA
   ===================================================================== */
$settings  = pl_settings($current_user_id);
$today     = date('Y-m-d');
$weekStart = pl_monday($today);
$rangeFrom = pl_add_days($weekStart, -7);
$rangeTo   = pl_add_days($weekStart, 20);

$boot = [
    'csrf'       => sec_csrf_token(),
    'today'      => $today,
    'user'       => ['id' => $current_user_id, 'name' => $utente_corrente['username'] ?? ''],
    'settings'   => $settings,
    'categories' => pl_categories($current_user_id),
    'calendars'  => pl_calendars($current_user_id),
    'routines'   => pl_routines($current_user_id),
    'favorites'  => pl_favorites($current_user_id),
    'week'       => pl_week_row($current_user_id, $weekStart, $settings),
    'events'     => pl_events_range($current_user_id, $rangeFrom, $rangeTo),
    'priorities' => pl_priorities_range($current_user_id, $rangeFrom, $rangeTo),
    'pills_due'  => pl_pills_due($current_user_id, date('Y-m-d'), date('H:i:s')),
    'lists'      => pl_week_lists($current_user_id, $weekStart),
    'diary'      => pl_diary_dates($current_user_id, $rangeFrom, $rangeTo),
    'habits'     => pl_habits_for_date($current_user_id, $today),
    'weekly'     => pl_habits_weekly($current_user_id, $today),
    'rings'      => pl_rings($current_user_id, $today),
    'range'      => ['from' => $rangeFrom, 'to' => $rangeTo],
    /* Chiave della sola mappa incorporata: è l'unica che può stare in pagina,
       perché è legata al dominio e al servizio Embed, che non si paga. Quella
       delle ricerche resta nel server e non compare mai qui. Se non è stata
       messa in config.php, la mappa semplicemente non si mostra. */
    'map_key'    => defined('GOOGLE_EMBED_KEY') ? GOOGLE_EMBED_KEY : '',
    'places'     => pl_places($current_user_id),
];
?>
<!DOCTYPE html>
<html lang="it" class="pl-palette-<?php echo sec_h($settings['palette']); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FBF9F6">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>Planner</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="planner.css?v=<?php echo @filemtime(__DIR__ . '/planner.css') ?: time(); ?>">
</head>
<body class="pl-body">

<!-- ============ BARRA IN ALTO ============
     riga 1: settimana + tutti i pulsanti
     riga 2: giorno a sinistra, mese al centro, calendarietto a destra
     riga 3: affermazione positiva (se attiva) -->
<header class="pl-topbar" id="plTopbar">
    <div class="pl-ambient" id="plAmbient" aria-hidden="true"></div>

    <div class="pl-bar-tools">
        <button type="button" class="pl-week" id="plWeekBtn">
            <span class="pl-week-n pl-serif" id="plWeekN">1</span>
            <span class="pl-week-k">Setti&shy;mana</span>
            <span class="pl-check" id="plWeekDone" role="checkbox" aria-checked="false" aria-label="Settimana completata">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5.2 5.2L20 6.6"/></svg>
            </span>
        </button>
        <span class="pl-tools-sp"></span>
        <button type="button" class="pl-tool" id="plViewBtn" aria-label="Passa alla vista mese">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><rect x="4" y="5" width="16" height="15" rx="2.4"/><path d="M4 10h16M9 5V3M15 5V3"/></svg>
        </button>
        <!-- Su telefono in verticale il pulsante non fa più da interruttore fra
             due viste: le viste sono tre, e un interruttore non le dice. -->
        <div class="pl-viewmenu" id="plViewMenu" role="menu" aria-hidden="true">
            <button type="button" role="menuitem" data-v="month">Mese</button>
            <button type="button" role="menuitem" data-v="week">Settimana</button>
            <button type="button" role="menuitem" data-v="day">Giorno</button>
        </div>
        <button type="button" class="pl-tool pl-tool-today" id="plTodayBtn" aria-label="Vai a oggi">1</button>
        <button type="button" class="pl-tool" id="plSearchBtn" aria-label="Cerca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
        </button>
        <button type="button" class="pl-tool" id="plFilterBtn" aria-label="Filtra per categoria">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M7 12h10M10 17h4"/></svg>
        </button>
        <button type="button" class="pl-tool" id="plWeatherBtn" aria-label="Meteo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="3.2"/><path d="M9 3.4v1.2M9 13.4v1.2M3.4 9h1.2M13.4 9h1.2M5 5l.9.9M12.1 12.1l.9.9M5 13l.9-.9M12.1 5.9l.9-.9"/><path d="M13 19.5h5.5a2.75 2.75 0 100-5.5 3.6 3.6 0 00-6.9-1 2.5 2.5 0 00.4 6.5z"/></svg>
        </button>
        <button type="button" class="pl-tool" id="plSettingsBtn" aria-label="Impostazioni">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M4.8 7.9l1.9 1.1M17.3 15l1.9 1.1M4.8 16.1l1.9-1.1M17.3 9l1.9-1.1"/></svg>
        </button>
    </div>

    <div class="pl-affirm pl-serif" id="plAffirm"></div>

    <div class="pl-bar-2">
        <button type="button" class="pl-day-btn" id="plDayBtn">
            <span class="pl-day-num pl-serif" id="plDayNum">1</span>
            <span class="pl-day-wd" id="plDayWd">Lunedì</span>
        </button>
        <button type="button" class="pl-month-btn" id="plMonthBtn">
            <span class="pl-month-name pl-serif" id="plMonthName">Luglio</span>
            <span class="pl-month-year" id="plMonthYear">2026</span>
        </button>
        <button type="button" class="pl-mini-static" id="plMiniStatic" aria-label="Calendario del mese"></button>
    </div>

    <div class="pl-months-bar" id="plMonthsBar"></div>
</header>

<!-- Il calendario a comparsa vive FUORI dall'header, apposta: così qualunque
     cosa succeda al suo contenuto non può più far crescere la barra in alto
     e spingere via orari e priorità (il bug che è successo due volte). È
     un pannello a posizione fissa, agganciato sotto la barra, che si apre
     sopra il resto della pagina senza spostare nient'altro. -->
<div class="pl-mini" id="plMini"></div>

<!-- ============ GRIGLIA GIORNI + ORARI ============ -->
<main class="pl-body-scroll" id="plScroller">
    <div class="pl-row">
        <div class="pl-gutter"><div class="pl-gutter-canvas" id="plGutter"></div></div>
        <div class="pl-hclip">
            <!-- la striscia dei 21 giorni: si sposta con una trasformazione,
                 mai con lo scroll nativo, cosi' l'orizzontale non tocca mai
                 il verticale (e viceversa). La costruisce planner.js. -->
            <div class="pl-track" id="plTrack"></div>
        </div>
    </div>
</main>

<!-- ============ VISTA MESE ============ -->
<!-- `data-sb-not-overlay`: la vista mese è a schermo intero (position:fixed;
     inset:0), quindi il rilevatore della sidebar la scambiava per una finestra
     aperta e nascondeva il pulsante del menu. Con questo attributo la sidebar
     sa che è una pagina e non si toglie di mezzo. Il pulsante sta comunque
     sopra (z-index 10000 contro 50), quindi non serve altro. -->
<!-- Vista settimanale a tabella: sette giorni in colonna, le ore in riga.
     Vive solo su telefono in verticale, dove la griglia a orari di tre giorni
     è troppo stretta per leggersi e quella del mese troppo grossa per dire
     a che ora sono le cose. Intestazione e colonna delle ore restano ferme
     con `position:sticky` dentro un riquadro solo che scorre nei due sensi:
     due scorrimenti annidati sarebbero stati una lotta continua. -->
<section class="pl-wkview" id="plWeekView" data-sb-not-overlay>
    <!-- Intestazione e colonna delle ore stanno FUORI dal riquadro che
         scorre. Dentro non potevano restare ferme: un elemento appiccicato
         dentro una griglia può muoversi solo entro la sua cella, quindi la
         colonna delle ore se ne andava appena si scorreva oltre la sua
         larghezza. Qui le si trascina a mano seguendo lo scorrimento. -->
    <div class="pl-wk-top">
        <div class="pl-wk-corner"></div>
        <div class="pl-wk-topclip"><div class="pl-wk-heads" id="plWeekHeads"></div></div>
    </div>
    <div class="pl-wk-main">
        <div class="pl-wk-gutclip"><div class="pl-wk-gut" id="plWeekGut"></div></div>
        <div class="pl-wk-scroll" id="plWeekScroll">
            <div class="pl-wk-grid" id="plWeekGrid"></div>
        </div>
    </div>
</section>

<section class="pl-month" id="plMonthView" data-sb-not-overlay>
    <div class="pl-mgrid" id="plMonthGrid"></div>
    <!-- La maniglia che tira su l'elenco degli eventi. Prima l'elenco si
         apriva toccando un giorno, ma quel tocco serve per entrare nel
         giorno: due significati sullo stesso gesto non stanno in piedi. -->
    <button type="button" class="pl-mpull" id="plMonthPull" aria-label="Mostra l'elenco degli eventi">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>
    </button>
</section>

<!-- Elenco degli eventi dal giorno scelto in avanti: sale dal basso con la
     maniglia sotto il calendario, e si richiude toccando fuori. -->
<section class="pl-magenda" id="plMonthAgenda" aria-hidden="true">
    <button type="button" class="pl-magenda-grab" id="plMagendaClose" aria-label="Chiudi l'elenco">
        <!-- la stessa freccetta della maniglia sotto il calendario, girata:
             è lo stesso comando, e cambia solo il verso -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div class="pl-magenda-body" id="plMagendaBody"></div>
</section>

<!-- ============ PULSANTI FLOTTANTI ============ -->
<div class="pl-fabs" id="plFabs">
    <button type="button" class="pl-ring" id="plHabitRing" aria-label="Abitudini">
        <?php /* I cerchi li disegna planner.js: quanti sono dipende da quali
                 famiglie di frequenza hai davvero (giornaliera, settimanale,
                 mensile, personalizzata), quindi non possono stare fissi qui. */ ?>
        <svg viewBox="0 0 46 46"></svg>
        <span class="pl-ring-face">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 12.5l4 4L19 7"/></svg>
        </span>
    </button>
    <div class="pl-fab-menu" id="plMenuHabit"></div>

    <!-- Tutto quello che si può richiudere sta qui dentro. Le abitudini no:
         sono l'unica cosa che si guarda senza doverla cercare, e restano. -->
    <div class="pl-fab-group" id="plFabGroup">

    <button type="button" class="pl-fab" id="plFabRoutine" aria-label="Routine">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h10M4 12h13M4 18h8"/><circle cx="19" cy="6" r="1.6"/><circle cx="21" cy="12" r="1.6"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuRoutine"></div>

    <button type="button" class="pl-fab pl-fab-cat" id="plFabCat" aria-label="Categoria predefinita">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6.5 4h7.7L20 10.3 13.3 17 6 9.7z"/><circle cx="10" cy="8.5" r="1.2"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuCat"></div>

    <button type="button" class="pl-fab pl-hidden" id="plFabFav" aria-label="Progetti preferiti">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
    </button>
    <div class="pl-fab-menu" id="plMenuFav"></div>

    <button type="button" class="pl-fab" id="plFabShift" aria-label="Anticipa o ritarda">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.4l3 1.8"/></svg>
    </button>

    <button type="button" class="pl-fab" id="plFabDiary" aria-label="Diario del giorno">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 4h10a2 2 0 012 2v14H8a2 2 0 01-2-2z"/><path d="M6 4v16"/><path d="M10 9h5M10 13h5"/></svg>
    </button>
    </div>

    <!-- La freccetta chiude e riapre la colonna. Sta in fondo al gruppo, non
         in cima: da chiusa si trova subito sotto le abitudini, da aperta
         resta l'ultima della fila, dove il pollice la ritrova. -->
    <button type="button" class="pl-fab pl-fab-toggle" id="plFabToggle"
            aria-label="Mostra o nascondi i tastini" aria-expanded="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>
</div>

<!-- rotellina degli scatti: la costruisce planner.js -->
<div class="pl-zoom" id="plZoom" aria-label="Scatti degli orari"></div>

<!-- da computer non si può scorrere: due frecce discrete per cambiare giorno -->
<button type="button" class="pl-arrow pl-arrow-prev" id="plPrevDay" aria-label="Giorno precedente">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
</button>
<button type="button" class="pl-arrow pl-arrow-next" id="plNextDay" aria-label="Giorno successivo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M9.5 5L16 12l-6.5 7"/></svg>
</button>

<button type="button" class="pl-plus" id="plPlus" aria-label="Nuovo evento">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- frase motivazionale della settimana: barra in fondo -->
<div class="pl-quotebar pl-hidden" id="plQuoteBar">
    <span class="pl-serif" id="plQuoteText"></span>
</div>

<!-- pannello della settimana (to do / to buy / to study + cosa ho imparato):
     entra da sinistra, sopra la pagina -->
<div class="pl-wp-ov" id="plWeekPanelOv">
    <aside class="pl-wp" id="plWeekPanel">
        <div class="pl-wp-head">
            <span class="pl-sheet-title pl-serif">La settimana</span>
            <button type="button" class="pl-txt-btn" id="plWeekPanelClose">Chiudi</button>
        </div>
        <div class="pl-wp-body" id="plWeekPanelBody"></div>
    </aside>
</div>

<!-- striscia invisibile sul bordo sinistro: dal lunedì apre il pannello -->
<div class="pl-edge" id="plEdge" aria-hidden="true"></div>

<!-- barra della modalità anticipi/ritardi -->
<div class="pl-shift-bar" id="plShiftBar">
    <button type="button" class="pl-txt-btn" id="plShiftCancel">Annulla</button>
    <div class="pl-switch" id="plShiftSwitch">
        <button type="button" data-dir="early">Anticipo</button>
        <button type="button" data-dir="late" class="pl-on">Ritardo</button>
    </div>
    <!-- Orario vero e proprio, non testo: così si tocca e si apre l'orologio
         del telefono invece di dover scrivere "00:10" con i due punti al posto
         giusto. Il valore letto resta della stessa forma (hh:mm), quindi il
         conto dei minuti in planner.js non cambia di una riga. -->
    <input type="time" class="pl-time-in" id="plShiftTime" value="00:10" step="60" aria-label="Di quanto spostarli (ore e minuti)">
    <button type="button" class="pl-txt-btn pl-strong" id="plShiftSave">Salva</button>
</div>

<!-- ============ POPUP: EVENTO ============ -->
<div class="pl-ov" id="plEventOv">
    <div class="pl-sheet pl-sheet-tinted" id="plEventSheet">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-head-left">
                <button type="button" class="pl-txt-btn" id="plEvCancel">Annulla</button>
                <button type="button" class="pl-trash pl-hidden" id="plEvTrash" aria-label="Elimina evento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M9 7V5h6v2M7 7l1 13h8l1-13"/><path d="M10.5 11v6M13.5 11v6"/></svg>
                </button>
            </span>
            <span class="pl-label" id="plEvKicker">Nuovo evento</span>
            <button type="button" class="pl-txt-btn pl-strong" id="plEvSave">Salva</button>
        </div>
        <div class="pl-sheet-body" id="plEvBody"></div>
    </div>
</div>

<!-- ============ POPUP: IMPOSTAZIONI / RICERCA / FILTRO / COPIA ============ -->
<div class="pl-ov" id="plSheetOv">
    <div class="pl-sheet pl-sheet-tall">
        <div class="pl-grab"></div>
        <div class="pl-sheet-head">
            <span class="pl-sheet-title pl-serif" id="plSheetTitle">Impostazioni</span>
            <button type="button" class="pl-txt-btn" data-pl-close="plSheetOv">Chiudi</button>
        </div>
        <div class="pl-sheet-body" id="plSheetBody"></div>
    </div>
</div>

<!-- ============ AVVISO ============ -->
<div class="pl-alert-ov" id="plAlertOv">
    <div class="pl-alert">
        <div class="pl-alert-t pl-serif" id="plAlertTitle"></div>
        <div class="pl-alert-m" id="plAlertMsg"></div>
        <div class="pl-alert-acts" id="plAlertActs"></div>
    </div>
</div>

<div class="pl-toast" id="plToast"></div>

<?php
/* Selettore progetti riusato dal popup evento (stesso componente di
   pomodoro.php e progetti.php). Il planner ascolta l'evento
   "projectselected" per collegare il progetto all'evento. */
$ps_id = 'plev'; $ps_selected_id = 0; $ps_selected_name = '';
$ps_placeholder = 'Nessun progetto'; $ps_title = 'Collega a un progetto';
$ps_show_all_option = true; $ps_all_option_label = 'Nessun progetto'; $ps_allow_create = true;
$ps_hidden_name = 'plev_project_id';
include 'project_selector.php';
/* nel planner il progetto si sceglie dal popup dell'evento: il pulsante
   inline del selettore non deve comparire in fondo alla pagina */
echo '<style>#ps-widget-plev{ display:none; }</style>';
?>

<?php
/* Diario del giorno + foglio scritto a mano: componente condiviso con
   habits.php e con la pagina diario.php (stessi dati, stesse tabelle). */
include 'diario_giorno.php';
?>

<script>window.PL_BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<?php include 'sidebar.php'; ?>
<script src="planner.js?v=<?php echo @filemtime(__DIR__ . '/planner.js') ?: time(); ?>"></script>
</body>
</html>