<?php
/* =====================================================================
   STANDOUT — sync: planner (calendari, categorie, parole chiave, eventi)

   Come per le abitudini e il diario: NIENTE regole riscritte. Gli eventi passano dalle
   funzioni della rotta /api/v1/events (pl_api_crea / pl_api_modifica / pl_evento_elimina),
   che a loro volta sono quelle della pagina del planner: ricorrenze, eccezioni, calendario
   principale, collegamento ai task, coda di Google.

   UNA SERIE E LE SUE ECCEZIONI. Il capostipite ha `rrule`. «Solo questa volta» e' una riga
   nuova con series_uuid + original_date (come fa la pagina con edit_mode "one"); «questa
   volta no» e' la stessa cosa con is_cancelled. Cancellare una eccezione NON toglie la riga:
   la annulla (cosi' la volta resta tolta). Cancellare il capostipite toglie tutta la serie.
   Le occorrenze non sono righe: le calcola chi legge.
   ===================================================================== */

/** Un colore o null: stessa pulizia della pagina. */
function sync_pl_colore($v): ?string {
    if ($v === null) return null;
    return is_string($v) ? sec_color($v) : null;
}

/* ---- calendari ---- */
function sync_pcal_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('planner_calendars', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        $chi = sec_one("SELECT is_default FROM planner_calendars WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        if ($chi && (int)$chi['is_default'] === 1) return sync_rifiuto('validation', 'is_default', 'The main calendar cannot be deleted');
        // gli eventi non si perdono: passano al principale
        $princ = pl_default_calendar($uid);
        sec_q("UPDATE planner_events SET calendar_id = ? WHERE calendar_id = ? AND user_id = ?", [$princ, $id, $uid], 'iii');
        sec_q("DELETE FROM planner_calendars WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_calendars', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $set = []; $par = [];
    if (array_key_exists('name', $patch)) {
        $n = is_string($patch['name']) ? sec_str($patch['name'], 80) : '';
        if ($n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $set['name'] = $n;
    }
    if (array_key_exists('color', $patch)) $set['color'] = sync_pl_colore($patch['color']);
    if (array_key_exists('is_visible', $patch)) {
        if (!is_bool($patch['is_visible'])) return sync_rifiuto('validation', 'is_visible', 'is_visible must be true or false');
        $set['is_visible'] = $patch['is_visible'] ? 1 : 0;
    }
    if (array_key_exists('keywords', $patch)) {
        if ($patch['keywords'] !== null && !is_string($patch['keywords'])) return sync_rifiuto('validation', 'keywords', 'keywords must be text');
        $set['keywords'] = sec_str((string)$patch['keywords'], 255);
    }
    foreach (['def_repeat' => ['none', 'daily', 'weekly', 'monthly', 'yearly'], 'def_notify' => ['none', 'at_time', 'm30', 'h1', 'd1']] as $k => $ammessi) {
        if (!array_key_exists($k, $patch)) continue;
        if (!is_string($patch[$k]) || !in_array($patch[$k], $ammessi, true)) return sync_rifiuto('validation', $k, "$k must be one of: " . implode(', ', $ammessi));
        $set[$k] = $patch[$k];
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM planner_calendars WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['name'])) return sync_rifiuto('validation', 'name', 'name is required');
        pl_api_prepara($uid);   // il «principale» deve esistere prima degli altri
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_calendars', 'uuid' => $uuid];
        sec_q("INSERT INTO planner_calendars (user_id, name, color, sort_order)
               VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_calendars WHERE user_id=?) x))",
              [$uid, $set['name'], $set['color'] ?? null, $uid], 'issi');
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('planner_calendars', $uid, $uuid);
        unset($set['name'], $set['color']);
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_calendars SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_calendars', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- categorie ---- */
function sync_pcat_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('planner_categories', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        sec_q("UPDATE planner_events SET category_id = NULL WHERE category_id = ? AND user_id = ?", [$id, $uid], 'ii');
        sec_q("DELETE FROM planner_category_keywords WHERE category_id = ? AND user_id = ?", [$id, $uid], 'ii');
        sec_q("DELETE FROM planner_categories WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_categories', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $set = [];
    if (array_key_exists('name', $patch)) {
        $n = is_string($patch['name']) ? sec_str($patch['name'], 60) : '';
        if ($n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $set['name'] = $n;
    }
    if (array_key_exists('color', $patch)) $set['color'] = sync_pl_colore($patch['color']) ?? '#C8DAD3';
    if (array_key_exists('is_hidden', $patch)) {
        if (!is_bool($patch['is_hidden'])) return sync_rifiuto('validation', 'is_hidden', 'is_hidden must be true or false');
        $set['is_hidden'] = $patch['is_hidden'] ? 1 : 0;
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM planner_categories WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['name'])) return sync_rifiuto('validation', 'name', 'name is required');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_categories', 'uuid' => $uuid];
        sec_q("INSERT INTO planner_categories (user_id, name, color, sort_order)
               VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_categories WHERE user_id=?) x))",
              [$uid, $set['name'], $set['color'] ?? '#C8DAD3', $uid], 'issi');
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('planner_categories', $uid, $uuid);
        unset($set['name'], $set['color']);
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_categories SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_categories', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- parole chiave delle categorie ---- */
function sync_pkey_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('planner_category_keywords', $uid, $uuid);
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM planner_category_keywords WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_category_keywords', $patch, $id === null)) return $er;
    if ($id !== null) {
        // una parola non si cambia: si toglie e se ne scrive un'altra
        foreach (['category_uuid', 'keyword'] as $k) {
            if (!array_key_exists($k, $patch)) continue;
            $att = sec_one("SELECT k.keyword, c.uuid AS cu FROM planner_category_keywords k JOIN planner_categories c ON c.id = k.category_id WHERE k.id = ?", [$id]);
            if (($k === 'keyword' ? $att['keyword'] : $att['cu']) !== $patch[$k]) return sync_rifiuto('validation', $k, "$k cannot change");
        }
        return ['status' => 'applied'];
    }
    if (sec_one("SELECT id FROM planner_category_keywords WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
    if (!sync_uuid_valido($patch['category_uuid'] ?? null)) return sync_rifiuto('validation', 'category_uuid', 'Invalid uuid');
    $cat = sync_id_da_uuid('planner_categories', $uid, $patch['category_uuid']);
    if ($cat === null) return sync_rifiuto('not_found', 'category_uuid', 'Category not found');
    $kw = is_string($patch['keyword'] ?? null) ? sec_key($patch['keyword'], 60) : '';
    if ($kw === '') return sync_rifiuto('validation', 'keyword', 'Write a word');
    // una parola e' di una categoria sola
    if (sec_one("SELECT id FROM planner_category_keywords WHERE user_id = ? AND keyword = ? LIMIT 1", [$uid, $kw], 'is')) {
        return sync_rifiuto('validation', 'keyword', 'This word is already used by a category');
    }
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_category_keywords', 'uuid' => $uuid];
    sec_q("INSERT INTO planner_category_keywords (user_id, category_id, keyword) VALUES (?,?,?)", [$uid, $cat, $kw], 'iis');
    unset($GLOBALS['SYNC_UUID_NUOVO']);
    return ['status' => 'applied'];
}

/* ---- eventi ---- */

/** Dal filo (nomi della tabella) ai nomi che capisce pl_api_*; ritorna [campi, errore]. */
function sync_pev_campi(int $uid, array $patch): array {
    $c = [];
    $semplici = ['title' => 'title', 'notes' => 'notes', 'location' => 'location', 'place_name' => 'place_name', 'place_ref' => 'place_ref',
                 'duration_locked' => 'duration_locked', 'event_date' => 'date', 'end_date' => 'end_date', 'start_time' => 'start',
                 'end_time' => 'end', 'is_all_day' => 'all_day', 'importance' => 'importance', 'color' => 'color', 'item_type' => 'type',
                 'notify_type' => 'notify', 'notify_minutes' => 'notify_min', 'repeat_type' => 'repeat', 'repeat_interval' => 'repeat_interval',
                 'repeat_unit' => 'repeat_unit', 'repeat_until' => 'repeat_until'];
    foreach ($semplici as $filo => $nome) if (array_key_exists($filo, $patch)) $c[$nome] = $patch[$filo];
    foreach (['place_lat', 'place_lon'] as $k) if (array_key_exists($k, $patch)) $c[$k] = $patch[$k];
    if (array_key_exists('is_completed', $patch)) {
        if (!is_bool($patch['is_completed'])) return [null, sync_rifiuto('validation', 'is_completed', 'is_completed must be true or false')];
        $c['done'] = $patch['is_completed'];
    }
    foreach (['category_uuid' => ['category_id', 'planner_categories'], 'calendar_uuid' => ['calendar_id', 'planner_calendars'], 'habit_uuid' => ['habit_id', 'habits']] as $filo => [$nome, $tab]) {
        if (!array_key_exists($filo, $patch)) continue;
        if ($patch[$filo] === null) { $c[$nome] = null; continue; }
        if (!sync_uuid_valido($patch[$filo])) return [null, sync_rifiuto('validation', $filo, 'Invalid uuid')];
        $i = sync_id_da_uuid($tab, $uid, $patch[$filo]);
        if ($i === null) return [null, sync_rifiuto('not_found', $filo, 'Not found')];
        $c[$nome] = $i;
    }
    return [$c, null];
}

/** Gli errori di pl_api_* con i nomi dei campi del filo. */
function sync_pev_errore(array $r): array {
    $nomi = ['date' => 'event_date', 'start' => 'start_time', 'end' => 'end_time', 'all_day' => 'is_all_day', 'type' => 'item_type',
             'notify' => 'notify_type', 'notify_min' => 'notify_minutes', 'repeat' => 'repeat_type', 'category_id' => 'category_uuid',
             'calendar_id' => 'calendar_uuid', 'habit_id' => 'habit_uuid', 'done' => 'is_completed'];
    return sync_da_errore($r, $nomi);
}

function sync_pevents_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM planner_events WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);

    if ($op === 'delete') {
        if (!$riga) return ['status' => 'applied'];
        $id = (int)$riga['id'];
        if (!empty($riga['original_date']) && !empty($riga['series_id']) && (int)$riga['series_id'] !== $id) {
            // un'eccezione non si toglie: si annulla, cosi' quella volta resta tolta
            if ((int)$riga['is_cancelled'] !== 1) pl_evento_elimina($uid, $id, 'one', null);
        } elseif (!empty($riga['series_id']) && (int)$riga['series_id'] === $id) {
            pl_evento_elimina($uid, $id, 'series', null);
        } else {
            pl_evento_elimina($uid, $id, 'one', null);
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_events', $patch, $riga === null)) return $er;
    [$c, $er] = sync_pev_campi($uid, $patch);
    if ($er) return $er;

    // ---------- una riga che c'e' gia' ----------
    if ($riga) {
        $id = (int)$riga['id'];
        foreach (['series_uuid', 'original_date'] as $k) {
            if (!array_key_exists($k, $patch)) continue;
            if ($k === 'original_date') { if ((string)($riga['original_date'] ?? '') !== (string)($patch[$k] ?? '')) return sync_rifiuto('validation', $k, "$k cannot change"); continue; }
            $sc = !empty($riga['series_id']) ? sec_one("SELECT uuid FROM planner_events WHERE id = ?", [(int)$riga['series_id']]) : null;
            if (($sc['uuid'] ?? null) !== $patch[$k]) return sync_rifiuto('validation', $k, "$k cannot change");
        }
        if (array_key_exists('is_cancelled', $patch)) {
            if (!is_bool($patch['is_cancelled'])) return sync_rifiuto('validation', 'is_cancelled', 'is_cancelled must be true or false');
            if ($patch['is_cancelled'] && (int)$riga['is_cancelled'] !== 1) { pl_evento_elimina($uid, $id, 'one', null); return ['status' => 'applied']; }
            if (!$patch['is_cancelled'] && (int)$riga['is_cancelled'] === 1) sec_q("UPDATE planner_events SET is_cancelled = 0 WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        }
        if ((int)$riga['is_cancelled'] === 1 && !array_key_exists('is_cancelled', $patch)) return ['status' => 'applied'];   // una volta tolta non si modifica
        if ($c) {
            $r = pl_api_modifica($uid, $id, $c);
            if (isset($r['errore'])) return sync_pev_errore($r);
        }
        return ['status' => 'applied'];
    }

    // ---------- una riga nuova ----------
    if (sec_one("SELECT id FROM planner_events WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_events', 'uuid' => $uuid];
    try {
        $serie = $patch['series_uuid'] ?? null;
        $orig = $patch['original_date'] ?? null;
        $fatto = $c['done'] ?? null; unset($c['done']);
        $annullata = !empty($patch['is_cancelled']);
        if ($serie !== null && $serie !== $uuid) {
            // un'eccezione di una serie
            if (!sync_uuid_valido($serie)) return sync_rifiuto('validation', 'series_uuid', 'Invalid uuid');
            $capo = sec_one("SELECT id, rrule FROM planner_events WHERE user_id = ? AND uuid = ? AND is_cancelled = 0", [$uid, $serie]);
            if (!$capo) return sync_rifiuto('not_found', 'series_uuid', 'Series not found');
            $o = is_string($orig) ? sec_date($orig, null) : null;
            if ($o === null) return sync_rifiuto('validation', 'original_date', 'original_date must be YYYY-MM-DD');
            if (empty($capo['rrule'])) return sync_rifiuto('validation', 'series_uuid', 'This event does not repeat');
            if (sec_one("SELECT id FROM planner_events WHERE user_id = ? AND series_id = ? AND original_date = ? LIMIT 1", [$uid, (int)$capo['id'], $o], 'iis')) {
                return sync_rifiuto('validation', 'original_date', 'This day already has its own version');
            }
            if ($annullata) {
                pl_evento_elimina($uid, (int)$capo['id'], 'one', $o);
            } else {
                $c['edit_mode'] = 'one'; $c['original_date'] = $o;
                // pl_api_modifica vuole almeno un campo da cambiare: un'eccezione senza campi ripete il titolo della serie
                if (!array_diff_key($c, ['edit_mode' => 1, 'original_date' => 1])) {
                    $c['title'] = (string)(sec_one("SELECT title FROM planner_events WHERE id = ?", [(int)$capo['id']])['title'] ?? '');
                }
                $r = pl_api_modifica($uid, (int)$capo['id'], $c);
                if (isset($r['errore'])) return sync_pev_errore($r);
            }
        } else {
            if ($annullata) return sync_rifiuto('validation', 'is_cancelled', 'Only a single day of a series can be cancelled');
            $r = pl_api_crea($uid, $c);
            if (isset($r['errore'])) return sync_pev_errore($r);
        }
    } finally {
        unset($GLOBALS['SYNC_UUID_NUOVO']);
    }
    if ($fatto === true) {
        $nuovo = sync_id_da_uuid('planner_events', $uid, $uuid);
        if ($nuovo !== null) pl_evento_spunta($uid, $nuovo, true);
    }
    return ['status' => 'applied'];
}
