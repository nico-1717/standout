<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_categorie.php
   Categorie, calendari, routine, preferiti, e la scelta automatica di categoria e calendario dal titolo.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

/* --- categorie / routine ------------------------------------------- */
function pl_categories(int $userId): array {
    $rows = sec_all("SELECT id, name, color, sort_order, is_hidden, role FROM planner_categories
                     WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $kw = sec_all("SELECT category_id, keyword FROM planner_category_keywords WHERE user_id = ? ORDER BY keyword", [$userId], 'i');
    $byCat = [];
    foreach ($kw as $k) { $byCat[(int)$k['category_id']][] = $k['keyword']; }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id' => $id, 'name' => pl_nome_tradotto($r['name']), 'color' => $r['color'],
            'hidden' => (int)$r['is_hidden'] === 1,
            'keywords' => $byCat[$id] ?? [],
            // 'study' = la categoria in cui finiscono gli eventi di studio
            'role' => ($r['role'] !== null && $r['role'] !== '') ? (string)$r['role'] : null,
        ];
    }
    return $out;
}

/* I nomi predefiniti di categorie e calendari sono righe del database, scritte
   in italiano quando l'utente si e' registrato. Tradurli alla lettura e' l'unico
   modo di mostrarli nella lingua giusta senza toccare i dati - e se l'utente li
   rinomina, il nome suo non corrisponde piu' a nessun predefinito e resta com'e',
   che e' esattamente quello che deve succedere. */
const PL_NOMI_PREDEFINITI = [
    'Spostamenti', 'Svaghi', 'Eventi', 'Lavoro', 'Appuntamenti', 'Relax', 'Vacanze',
    'Principale', 'Senza categoria', 'Studio',
];

function pl_nome_tradotto(string $nome): string {
    if (!in_array($nome, PL_NOMI_PREDEFINITI, true)) return $nome;
    $chiave = 'planner.pre_' . strtolower(str_replace(' ', '_', $nome));
    $tradotto = t($chiave);
    /* t() restituisce <<chiave>> se manca: in quel caso si tiene l'originale
       invece di stampare una chiave in pagina. */
    return (strpos($tradotto, '<<') === 0) ? $nome : $tradotto;
}

/** I calendari dell'utente, in ordine alfabetico. */
function pl_calendars(int $userId): array {
    $rows = sec_all("SELECT id, name, color, is_visible, is_default, google_ref, keywords, def_repeat, def_notify, role
                     FROM planner_calendars WHERE user_id = ? ORDER BY name", [$userId], 'i');
    if (empty($rows)) {
        sec_q("INSERT INTO planner_calendars (user_id, name, color, is_default, sort_order)
               VALUES (?, 'Principale', NULL, 1, 0)", [$userId], 'i');
        $rows = sec_all("SELECT id, name, color, is_visible, is_default, google_ref, keywords, def_repeat, def_notify, role
                         FROM planner_calendars WHERE user_id = ?", [$userId], 'i');
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'name' => pl_nome_tradotto($r['name']),
            'color' => ($r['color'] !== null && $r['color'] !== '') ? $r['color'] : null,
            'visible' => (int)$r['is_visible'] === 1,
            'is_default' => (int)$r['is_default'] === 1,
            'from_google' => !empty($r['google_ref']),
            'keywords' => (string)($r['keywords'] ?? ''),
            'def_repeat' => (string)($r['def_repeat'] ?? 'none'),
            'def_notify' => (string)($r['def_notify'] ?? 'none'),
            /* 'study' = e' il calendario dei blocchi di studio. Serve al filtro
               per marcarlo, e alla pagina to study per sapere dove scrivere.
               Un ruolo e non il nome: il nome l'utente lo puo' cambiare, e in
               inglese e' un'altra parola. */
            'role' => ($r['role'] !== null && $r['role'] !== '') ? (string)$r['role'] : null
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
