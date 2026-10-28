<?php
/* =====================================================================
   STANDOUT — ABITUDINI per l'API /api/v1/habits (PROGETTO §15, passo 9, fetta 3)

   Come lib/todo_core.php e lib/planner/pl_api.php: qui c'e' solo il controllo
   dei tipi e la modifica «a campi» (PATCH). Il lavoro vero lo fanno le
   funzioni della pagina, in lib/habits_core.php: hb_load_habits() per
   leggere, hb_abitudine_salva() per creare e modificare, hb_log_imposta()
   per il check-in (con serie e traguardi), hb_nota_salva() per nota e umore.

   La differenza dalla pagina e' una sola, voluta: la pagina corregge in
   silenzio quello che non capisce; l'app riceve un errore col nome del campo.
   Errori: ['errore' => ['codice', 'campo', 'testo']].
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/habits_core.php';

/** Una abitudine come la vede l'elenco, con anche la serie migliore (hb_fetch_habit
    non la unisce: la pagina non ne ha bisogno). Cosi' GET /habits e GET /habits/{id} coincidono. */
function hb_api_una(int $uid, int $id): ?array {
    $r = sec_one("SELECT h.*, s.best_streak FROM habits h
                  LEFT JOIN habit_streak_records s ON s.habit_id = h.id
                  WHERE h.id = ? AND h.user_id = ?", [$id, $uid], 'ii');
    return $r ? hb_habit_out($r) : null;
}

function hb_api_errore(string $codice, string $campo, string $testo): array {
    return ['errore' => ['codice' => $codice, 'campo' => $campo, 'testo' => $testo]];
}

/** Oggi nel fuso del sito: lo stesso «oggi» con cui la pagina decide cos'e' futuro. */
function hb_api_oggi(): string {
    date_default_timezone_set('Europe/Rome');
    return date('Y-m-d');
}

/** Le abitudini gia' iniziate e quelle con inizio futuro, come la pagina. */
function hb_api_elenco(int $uid): array {
    $conn = sec_db();
    return ['today' => hb_api_oggi(), 'habits' => hb_load_habits($conn, $uid), 'future' => hb_load_habits($conn, $uid, true)];
}

/** I check-in degli ultimi $giorni giorni (1..420, come la pagina). */
function hb_api_log(int $uid, int $giorni): array {
    return ['logs' => hb_load_logs(sec_db(), $uid, max(1, min(HB_HISTORY_DAYS, $giorni)))];
}

/**
 * Controlla le chiavi PRESENTI (una chiave assente = non toccarla) e le rimette
 * nella forma che si aspetta hb_abitudine_salva() (le stesse chiavi della pagina).
 * Ritorna ['in' => [...], 'archiviata' => bool|null] oppure un errore.
 */
function hb_api_leggi(int $uid, array $c, bool $creazione): array {
    $in = [];
    $e = fn(string $campo, string $testo, string $cod = 'validation') => hb_api_errore($cod, $campo, $testo);

    foreach (['name' => 120, 'icon' => 8, 'motivation' => 255, 'preset_key' => 60, 'unit' => 24] as $k => $max) {
        if (!array_key_exists($k, $c)) continue;
        if ($c[$k] === null && $k !== 'name' && $k !== 'icon') { $in[$k] = ''; continue; }
        if (!is_string($c[$k])) return $e($k, "$k must be text");
        if (mb_strlen($c[$k]) > $max) return $e($k, "$k is too long (max $max characters)");
        if ($k === 'name' && trim($c[$k]) === '') return $e($k, 'name cannot be empty');
        $in[$k] = $c[$k];
    }
    if ($creazione && !isset($in['name'])) return $e('name', 'name is required');

    if (array_key_exists('color', $c)) {
        if (!is_string($c['color']) || !preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $c['color'])) return $e('color', 'color must be #RRGGBB');
        $in['color'] = $c['color'];
    }

    $scelte = [
        'kind'           => ['build', 'quit'],
        'goal_type'      => ['completion', 'quantity'],
        'frequency_type' => ['daily', 'weekly', 'monthly', 'interval'],
        'day_part'       => ['any', 'morning', 'afternoon', 'evening', 'night'],
        'duration_type'  => ['forever', 'days', 'end_date'],
    ];
    foreach ($scelte as $k => $ammessi) {
        if (!array_key_exists($k, $c)) continue;
        if (!is_string($c[$k]) || !in_array($c[$k], $ammessi, true)) return $e($k, "$k must be one of: " . implode(', ', $ammessi));
        $in[$k] = $c[$k];
    }

    foreach (['target_amount', 'step_amount'] as $k) {
        if (!array_key_exists($k, $c)) continue;
        if ((!is_int($c[$k]) && !is_float($c[$k])) || $c[$k] <= 0 || $c[$k] > 1000000) return $e($k, "$k must be a number greater than 0");
        $in[$k] = (string)$c[$k];
    }
    foreach (['times_per_week' => [1, 7], 'times_per_month' => [1, 31], 'interval_days' => [1, 365], 'duration_days' => [1, 3650], 'planner_minutes' => [0, 1440]] as $k => [$min, $max]) {
        if (!array_key_exists($k, $c)) continue;
        if ($k === 'planner_minutes' && $c[$k] === null) { $in[$k] = '0'; continue; }
        if (!is_int($c[$k]) || $c[$k] < $min || $c[$k] > $max) return $e($k, "$k must be a number from $min to $max");
        $in[$k] = (string)$c[$k];
    }

    if (array_key_exists('weekdays', $c)) {
        // sette cifre, lunedi'..domenica, almeno un giorno attivo (es. "1111100")
        if (!is_string($c['weekdays']) || !preg_match('/^[01]{7}$/', $c['weekdays']) || strpos($c['weekdays'], '1') === false) {
            return $e('weekdays', 'weekdays must be 7 digits of 0/1 (Monday first) with at least one 1');
        }
        $in['weekdays'] = $c['weekdays'];
    }

    foreach (['start_date' => false, 'end_date' => true] as $k => $nullable) {
        if (!array_key_exists($k, $c)) continue;
        if ($c[$k] === null && $nullable) { $in[$k] = ''; continue; }
        $d = is_string($c[$k]) ? sec_date($c[$k], null) : null;
        if ($d === null) return $e($k, "$k must be YYYY-MM-DD" . ($nullable ? ' or null' : ''));
        $in[$k] = $d;
    }

    if (array_key_exists('category_id', $c)) {
        $v = $c['category_id'];
        if ($v === null || $v === 0) $in['category_id'] = 0;
        elseif (!is_int($v) || $v < 0) return $e('category_id', 'category_id must be a number or null');
        else {
            // le categorie di partenza hanno user_id 0 e sono di tutti; le altre solo di chi le ha create
            $cat = sec_one("SELECT id FROM habit_categories WHERE id = ? AND (user_id = 0 OR user_id = ?)", [$v, $uid], 'ii');
            if (!$cat) return $e('category_id', 'Category not found', 'not_found');
            $in['category_id'] = $v;
        }
    }

    $arch = null;
    if (array_key_exists('archived', $c)) {
        if (!is_bool($c['archived'])) return $e('archived', 'archived must be true or false');
        $arch = $c['archived'];
    }
    return ['in' => $in, 'archiviata' => $arch];
}

/** I campi di un'abitudine salvata, nella forma che legge hb_abitudine_salva(). */
function hb_api_riga_come_input(array $h): array {
    $in = [
        'name' => $h['name'], 'icon' => $h['icon'], 'color' => $h['color'], 'motivation' => (string)($h['motivation'] ?? ''),
        'preset_key' => (string)($h['preset_key'] ?? ''), 'kind' => $h['kind'], 'goal_type' => $h['goal_type'],
        'target_amount' => (string)$h['target_amount'], 'step_amount' => (string)$h['step_amount'], 'unit' => (string)$h['unit'],
        'frequency_type' => $h['frequency_type'], 'weekdays' => $h['weekdays'], 'times_per_week' => (string)$h['times_per_week'],
        'times_per_month' => (string)$h['times_per_month'], 'interval_days' => (string)$h['interval_days'], 'day_part' => $h['day_part'],
        'duration_type' => $h['duration_type'], 'start_date' => $h['start_date'],
        'planner_minutes' => (string)($h['planner_minutes'] ?? 0),
        'category_id' => (int)($h['category_id'] ?? 0),
    ];
    if ($h['duration_days'] !== null) $in['duration_days'] = (string)$h['duration_days'];
    if (!empty($h['end_date'])) $in['end_date'] = $h['end_date'];
    return $in;
}

/** Controlli sui valori messi insieme. */
function hb_api_coerenza(array $in): ?array {
    $tipo = $in['duration_type'] ?? 'forever';
    if ($tipo === 'end_date') {
        if (empty($in['end_date'])) return hb_api_errore('validation', 'end_date', 'end_date is required when duration_type is end_date');
        if (!empty($in['start_date']) && $in['end_date'] < $in['start_date']) return hb_api_errore('validation', 'end_date', 'end_date cannot be before start_date');
    }
    if ($tipo === 'days' && empty($in['duration_days'])) return hb_api_errore('validation', 'duration_days', 'duration_days is required when duration_type is days');
    return null;
}

/** Crea un'abitudine. Ritorna ['habit' => ...] oppure un errore. */
function hb_api_crea(int $uid, array $c): array {
    $v = hb_api_leggi($uid, $c, true);
    if (isset($v['errore'])) return $v;
    $in = $v['in'];
    if ($err = hb_api_coerenza($in)) return $err;
    $r = hb_abitudine_salva($uid, $in);
    if (empty($r['habit'])) return hb_api_errore('server_error', 'body', 'The habit could not be saved');
    if ($v['archiviata'] === true) hb_abitudine_archivia($uid, (int)$r['habit']['id'], true);
    return ['habit' => hb_api_una($uid, (int)$r['habit']['id'])];
}

/** Cambia SOLO i campi nominati (anche archived). Ritorna ['habit' => ...] oppure un errore. */
function hb_api_modifica(int $uid, int $id, array $c): array {
    $conn = sec_db();
    $h = hb_fetch_habit($conn, $id, $uid);
    if (!$h) return hb_api_errore('not_found', 'id', 'Habit not found');
    $v = hb_api_leggi($uid, $c, false);
    if (isset($v['errore'])) return $v;
    if (!$v['in'] && $v['archiviata'] === null) return hb_api_errore('validation', 'body', 'Nothing to update');
    if ($v['in']) {
        $in = $v['in'] + hb_api_riga_come_input($h);
        // cambiare il tipo di durata senza dire il valore: i campi vecchi non valgono piu'
        if (($in['duration_type'] ?? '') !== 'days') unset($in['duration_days']);
        if (($in['duration_type'] ?? '') !== 'end_date') unset($in['end_date']);
        if ($err = hb_api_coerenza($in)) return $err;
        $in['id'] = $id;
        $r = hb_abitudine_salva($uid, $in);
        if (isset($r['errore'])) return hb_api_errore('not_found', 'id', 'Habit not found');
    }
    if ($v['archiviata'] !== null) hb_abitudine_archivia($uid, $id, $v['archiviata']);
    return ['habit' => hb_api_una($uid, $id)];
}

/**
 * Un check-in. Corpo: {date?, mode?, value?, source?, note?}.
 *   mode: toggle (di partenza) | increment | decrement | set | skip | fail | clear
 *   Per «fatto» in modo che ripeterlo non cambi niente: set con value = target;
 *   per annullare: clear. toggle e increment invece cambiano a ogni chiamata.
 */
function hb_api_checkin(int $uid, int $id, array $c): array {
    $date = hb_api_oggi();
    if (array_key_exists('date', $c)) {
        $d = is_string($c['date']) ? sec_date($c['date'], null) : null;
        if ($d === null) return hb_api_errore('validation', 'date', 'date must be YYYY-MM-DD');
        $date = $d;
    }
    $mode = 'toggle';
    if (array_key_exists('mode', $c)) {
        $ammessi = ['increment', 'decrement', 'set', 'skip', 'fail', 'clear', 'toggle'];
        if (!is_string($c['mode']) || !in_array($c['mode'], $ammessi, true)) return hb_api_errore('validation', 'mode', 'mode must be one of: ' . implode(', ', $ammessi));
        $mode = $c['mode'];
    }
    $valore = 0.0;
    if (array_key_exists('value', $c)) {
        if ((!is_int($c['value']) && !is_float($c['value'])) || $c['value'] < 0 || $c['value'] > 1000000) return hb_api_errore('validation', 'value', 'value must be a number from 0 to 1000000');
        $valore = (float)$c['value'];
    }
    if ($mode === 'set' && !array_key_exists('value', $c)) return hb_api_errore('validation', 'value', 'value is required with mode "set"');
    $source = 'tap';
    if (array_key_exists('source', $c)) {
        $ammessi = ['swipe', 'tap', 'table', 'detail', 'quick_add', 'undo', 'auto'];
        if (!is_string($c['source']) || !in_array($c['source'], $ammessi, true)) return hb_api_errore('validation', 'source', 'source must be one of: ' . implode(', ', $ammessi));
        $source = $c['source'];
    }
    $nota = '';
    if (array_key_exists('note', $c) && $c['note'] !== null) {
        if (!is_string($c['note']) || mb_strlen($c['note']) > 500) return hb_api_errore('validation', 'note', 'note must be text of at most 500 characters');
        $nota = sec_str($c['note'], 500);
    }
    $r = hb_log_imposta($uid, $id, $date, $mode, $valore, $source, $nota);
    if (($r['errore'] ?? '') === 'non_trovata') return hb_api_errore('not_found', 'id', 'Habit not found');
    if (($r['errore'] ?? '') === 'futuro') return hb_api_errore('validation', 'date', 'date cannot be in the future');
    return ['checkin' => $r];
}

/** Nota e umore di un giorno. Corpo: {date?, note?, mood?} (mood 1..5 o null). */
function hb_api_nota(int $uid, int $id, array $c): array {
    $date = hb_api_oggi();
    if (array_key_exists('date', $c)) {
        $d = is_string($c['date']) ? sec_date($c['date'], null) : null;
        if ($d === null) return hb_api_errore('validation', 'date', 'date must be YYYY-MM-DD');
        $date = $d;
    }
    if ($date > hb_api_oggi()) return hb_api_errore('validation', 'date', 'date cannot be in the future');
    $nota = '';
    if (array_key_exists('note', $c) && $c['note'] !== null) {
        if (!is_string($c['note']) || mb_strlen($c['note']) > 500) return hb_api_errore('validation', 'note', 'note must be text of at most 500 characters');
        $nota = sec_str($c['note'], 500);
    }
    $umore = null;
    if (array_key_exists('mood', $c) && $c['mood'] !== null) {
        if (!is_int($c['mood']) || $c['mood'] < 1 || $c['mood'] > 5) return hb_api_errore('validation', 'mood', 'mood must be a number from 1 to 5, or null');
        $umore = $c['mood'];
    }
    if (!array_key_exists('note', $c) && !array_key_exists('mood', $c)) return hb_api_errore('validation', 'body', 'Send note and/or mood');
    if (!hb_fetch_habit(sec_db(), $id, $uid)) return hb_api_errore('not_found', 'id', 'Habit not found');
    /* La pagina riscrive sempre nota E umore insieme. All'app si puo' mandare
       uno solo dei due: quello non nominato resta com'era. */
    $prima = sec_one("SELECT note, mood FROM habit_logs WHERE habit_id = ? AND user_id = ? AND log_date = ?", [$id, $uid, $date], 'iis');
    if ($prima) {
        if (!array_key_exists('note', $c)) $nota = (string)($prima['note'] ?? '');
        if (!array_key_exists('mood', $c)) $umore = $prima['mood'] !== null ? (int)$prima['mood'] : null;
    }
    $r = hb_nota_salva($uid, $id, $date, $nota, $umore);
    if (($r['errore'] ?? '') === 'non_trovata') return hb_api_errore('not_found', 'id', 'Habit not found');
    return ['entry' => ['date' => $date] + $r];
}

/* ===================== Riordino e categorie (fetta 5) ===================== */

/**
 * Il nuovo ordine delle abitudini: gli id nell'ordine in cui devono comparire (come la
 * pagina: 10, 20, 30...). Ritorna ['count' => n, 'skipped' => [...]] oppure un errore.
 */
function hb_api_riordina(int $uid, array $c): array {
    $ids = $c['ids'] ?? null;
    if (!is_array($ids) || !$ids || !array_is_list($ids)) return hb_api_errore('validation', 'ids', 'ids must be a non-empty list of habit ids');
    if (count($ids) > 300) return hb_api_errore('validation', 'ids', 'ids can have at most 300 entries');
    foreach ($ids as $i) if (!is_int($i) || $i < 1) return hb_api_errore('validation', 'ids', 'ids must be whole numbers');
    $ids = array_values(array_unique($ids));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $righe = sec_all("SELECT id FROM habits WHERE user_id = ? AND id IN ($ph)", array_merge([$uid], $ids));
    $mie = array_map(fn($r) => (int)$r['id'], $righe);
    $ordine = 10;
    foreach ($ids as $id) {
        if (!in_array($id, $mie, true)) continue;
        sec_q("UPDATE habits SET sort_order = ? WHERE id = ? AND user_id = ?", [$ordine, $id, $uid]);
        $ordine += 10;
    }
    return ['count' => count($mie), 'skipped' => array_values(array_diff($ids, $mie))];
}

/** Una categoria dell'utente (solo le sue: quelle di sistema non si toccano), o null. */
function hb_api_cat_propria(int $uid, int $id): ?array {
    return sec_one("SELECT * FROM habit_categories WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
}

/** Una categoria come la vede l'app (stessa forma di hb_load_categories). */
function hb_api_cat_out(int $uid, int $id): ?array {
    foreach (hb_load_categories(sec_db(), $uid) as $c) if ($c['id'] === $id) return $c;
    return null;
}

/** Nome (1-60), icona (fino a 8 caratteri) e colore (#RRGGBB). Chiavi presenti = si cambiano. */
function hb_api_cat_leggi(int $uid, array $c, bool $creazione, ?int $id): array {
    $campi = [];
    if (array_key_exists('name', $c)) {
        if (!is_string($c['name'])) return hb_api_errore('validation', 'name', 'name must be text');
        $n = trim($c['name']);
        if ($n === '' || mb_strlen($n) > 60) return hb_api_errore('validation', 'name', 'name must be 1 to 60 characters');
        $n = sec_str($n, 60);
        // la collazione del database non distingue le maiuscole: «Studio» e «studio» sono la stessa categoria
        $doppia = sec_one("SELECT id FROM habit_categories WHERE (user_id = 0 OR user_id = ?) AND name = ? AND id <> ?", [$uid, $n, (int)$id], 'isi');
        if ($doppia) return hb_api_errore('validation', 'name', 'A category with this name already exists');
        $campi['name'] = $n;
    } elseif ($creazione) {
        return hb_api_errore('validation', 'name', 'name is required');
    }
    if (array_key_exists('icon', $c)) {
        if (!is_string($c['icon']) || mb_strlen($c['icon']) > 8) return hb_api_errore('validation', 'icon', 'icon must be text of at most 8 characters');
        $campi['icon'] = $c['icon'];
    }
    if (array_key_exists('color', $c)) {
        if (!is_string($c['color']) || !preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $c['color'])) return hb_api_errore('validation', 'color', 'color must look like #RRGGBB');
        $campi['color'] = hb_color($c['color'], '#8E8E93');
    }
    return ['campi' => $campi];
}

function hb_api_cat_crea(int $uid, array $c): array {
    $v = hb_api_cat_leggi($uid, $c, true, null);
    if (isset($v['errore'])) return $v;
    $f = $v['campi'];
    $n = sec_one("SELECT COUNT(*) AS n FROM habit_categories WHERE user_id = ?", [$uid], 'i');
    if ((int)($n['n'] ?? 0) >= 100) return hb_api_errore('validation', 'name', 'Too many categories (at most 100)');
    /* La chiave stabile (slug) la fa la pagina dal nome; qui un nome senza lettere latine
       darebbe sempre «_<id>» e due categorie si pesterebbero: in quel caso si usa un'impronta del nome.
       Lo slug sta in 40 caratteri. */
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $f['name']), '_'));
    if ($base === '') $base = 'c' . substr(md5($f['name']), 0, 8);
    $slug = mb_substr($base, 0, 28) . '_' . $uid;
    if (sec_one("SELECT id FROM habit_categories WHERE user_id = ? AND slug = ?", [$uid, $slug], 'is')) {
        $slug = mb_substr($base, 0, 18) . '_' . substr(md5($f['name'] . microtime()), 0, 6) . '_' . $uid;
    }
    $ordine = (int)sec_one("SELECT COALESCE(MAX(sort_order),0)+10 AS o FROM habit_categories WHERE user_id = ?", [$uid], 'i')['o'];
    sec_q("INSERT INTO habit_categories (user_id, slug, name, icon, color, sort_order) VALUES (?,?,?,?,?,?)",
          [$uid, mb_substr($slug, 0, 40), $f['name'], $f['icon'] ?? '🏷️', $f['color'] ?? '#8E8E93', $ordine]);
    return ['category' => hb_api_cat_out($uid, sec_insert_id())];
}

function hb_api_cat_modifica(int $uid, int $id, array $c): array {
    if (!hb_api_cat_propria($uid, $id)) return hb_api_errore('not_found', 'id', 'Category not found');
    $v = hb_api_cat_leggi($uid, $c, false, $id);
    if (isset($v['errore'])) return $v;
    if (!$v['campi']) return hb_api_errore('validation', 'body', 'Nothing to update');
    /* I nomi delle colonne vengono SOLO da hb_api_cat_leggi (elenco fisso), mai dal client. */
    $set = []; $par = [];
    foreach ($v['campi'] as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
    $par[] = $id; $par[] = $uid;
    sec_q("UPDATE habit_categories SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?", $par);
    return ['category' => hb_api_cat_out($uid, $id)];
}

/** Le abitudini di quella categoria restano, senza categoria (la chiave esterna lo fa da sola). */
function hb_api_cat_elimina(int $uid, int $id): bool {
    if (!hb_api_cat_propria($uid, $id)) return false;
    sec_q("UPDATE habits SET category_id = NULL WHERE user_id = ? AND category_id = ?", [$uid, $id], 'ii');
    sec_q("DELETE FROM habit_categories WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    return true;
}
