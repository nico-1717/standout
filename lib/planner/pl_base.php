<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_base.php
   Utilita' di date e ore, impostazioni (create al primo accesso), frasi del giorno, numero della settimana.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

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
            [t('planner.pre_spostamenti'),  '#CFDDEA', ['treno', 'auto', 'macchina', 'bus', 'aereo', 'viaggio', 'metro', 'volo', 'partenza']],
            [t('planner.pre_svaghi'),       '#F3E4C0', ['cinema', 'film', 'serie', 'gioco', 'videogioco', 'concerto', 'uscita', 'aperitivo']],
            [t('planner.pre_eventi'),       '#F0D3D6', ['festa', 'compleanno', 'matrimonio', 'cena', 'evento', 'invito']],
            [t('planner.pre_lavoro'),       '#D8D3E8', ['lavoro', 'riunione', 'call', 'ufficio', 'progetto', 'consegna', 'cliente']],
            [t('planner.pre_appuntamenti'), '#E8D5C7', ['dottore', 'medico', 'dentista', 'visita', 'appuntamento', 'parrucchiere', 'banca']],
            [t('planner.pre_relax'),        '#C7DED6', ['relax', 'riposo', 'pausa', 'yoga', 'meditazione', 'lettura', 'passeggiata']],
            [t('planner.pre_vacanze'),      '#DCE7C8', ['vacanza', 'ferie', 'mare', 'montagna', 'hotel', 'weekend']],
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
              [$userId, t('planner.pre_morning_routine'), '#F6EBD2'], 'iss');
        $rid = sec_insert_id();
        $items = [[t('planner.pre_alzarsi'), 5], [t('planner.pre_skincare'), 20], [t('planner.pre_vestirsi'), 5],
                  [t('planner.pre_colazione'), 10], [t('planner.pre_sole'), 5], [t('planner.pre_denti'), 5]];
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
    $frase = $list[$seed % count($list)];
    /* Il file resta in italiano (e' l'elenco delle frasi, come per i preset
       delle abitudini): la traduzione si cerca dall'id, che e' stabile. */
    if (isset($frase['id'], $frase['text'])) {
        $tradotta = t('planner.fr_' . strtolower((string)$frase['id']));
        if (strpos($tradotta, '<<') !== 0) $frase['text'] = $tradotta;
    }
    return $frase;
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
