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
    foreach (['category_uuid' => ['category_id', 'planner_categories'], 'calendar_uuid' => ['calendar_id', 'planner_calendars'], 'habit_uuid' => ['habit_id', 'habits'], 'project_uuid' => ['project_id', 'projects']] as $filo => [$nome, $tab]) {
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
             'calendar_id' => 'calendar_uuid', 'habit_id' => 'habit_uuid', 'project_id' => 'project_uuid', 'done' => 'is_completed'];
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
        return sync_pev_routine($uid, $uuid, $patch) ?? ['status' => 'applied'];
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
    return sync_pev_routine($uid, $uuid, $patch) ?? ['status' => 'applied'];
}

/** Un pezzo di routine: il gruppo (lo stesso per tutti i pezzi inseriti insieme) e la routine d'origine. Ritorna un rifiuto, o null. */
function sync_pev_routine(int $uid, string $uuid, array $patch): ?array {
    $id = sync_id_da_uuid('planner_events', $uid, $uuid);
    if ($id === null) return null;
    if (array_key_exists('routine_group', $patch) || array_key_exists('routine_uuid', $patch)) {
        $att = sec_one("SELECT routine_group, routine_id FROM planner_events WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        if ($att && ($att['routine_group'] !== null || $att['routine_id'] !== null)) return null;   // si scrive solo alla nascita
        $g = $patch['routine_group'] ?? null;
        if ($g !== null && (!is_string($g) || !preg_match('/^[0-9a-f]{32}$/', $g))) return sync_rifiuto('validation', 'routine_group', 'routine_group must be 32 hex characters');
        $rid = null;
        if (($patch['routine_uuid'] ?? null) !== null) {
            if (!sync_uuid_valido($patch['routine_uuid'])) return sync_rifiuto('validation', 'routine_uuid', 'Invalid uuid');
            $rid = sync_id_da_uuid('planner_routines', $uid, $patch['routine_uuid']);
            if ($rid === null) return sync_rifiuto('not_found', 'routine_uuid', 'Routine not found');
        }
        sec_q("UPDATE planner_events SET routine_group = ?, routine_id = ? WHERE id = ? AND user_id = ?", [$g, $rid, $id, $uid], 'sii' . 'i');
    }
    if (array_key_exists('routine_collapsed', $patch)) {
        if (!is_bool($patch['routine_collapsed'])) return sync_rifiuto('validation', 'routine_collapsed', 'routine_collapsed must be true or false');
        sec_q("UPDATE planner_events SET routine_collapsed = ? WHERE id = ? AND user_id = ?", [$patch['routine_collapsed'] ? 1 : 0, $id, $uid], 'iii');
    }
    return null;
}


/* ---- priorita' del giorno ---- */
function sync_pprio_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, priority_date, position, content, is_done FROM planner_priorities WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM planner_priorities WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_priorities', $patch, $riga === null)) return $er;

    // ---- il posto: giorno + numero (1..6) ----
    if ($riga) {
        $giorno = (string)$riga['priority_date']; $posto = (int)$riga['position'];
        if (isset($patch['priority_date']) && $patch['priority_date'] !== $giorno) return sync_rifiuto('validation', 'priority_date', 'priority_date cannot change');
        if (isset($patch['position']) && (int)$patch['position'] !== $posto) return sync_rifiuto('validation', 'position', 'position cannot change');
    } else {
        $giorno = is_string($patch['priority_date'] ?? null) ? sec_date($patch['priority_date'], null) : null;
        if ($giorno === null) return sync_rifiuto('validation', 'priority_date', 'priority_date must be YYYY-MM-DD');
        $posto = $patch['position'] ?? null;
        if (!is_int($posto) || $posto < 1 || $posto > 6) return sync_rifiuto('validation', 'position', 'position must be a number from 1 to 6');
        // quel posto puo' esistere gia' (scritto dal sito): e' LA STESSA riga
        $riga = sec_one("SELECT id, priority_date, position, content, is_done FROM planner_priorities WHERE user_id = ? AND priority_date = ? AND position = ?", [$uid, $giorno, $posto]);
    }
    if ($uuid !== sync_uuid_priorita($uid, $giorno, $posto)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the user, the day and the position');

    $testo = $riga ? (string)$riga['content'] : '';
    $fatto = $riga ? (int)$riga['is_done'] : 0;
    if (array_key_exists('content', $patch)) {
        if (!is_string($patch['content'])) return sync_rifiuto('validation', 'content', 'content must be text');
        $testo = sec_str($patch['content'], 200);
    }
    if (array_key_exists('is_done', $patch)) {
        if (!is_bool($patch['is_done'])) return sync_rifiuto('validation', 'is_done', 'is_done must be true or false');
        $fatto = $patch['is_done'] ? 1 : 0;
    }
    // come sul sito: una riga vuota e non spuntata non esiste
    if ($testo === '' && $fatto === 0) {
        if ($riga) sec_q("DELETE FROM planner_priorities WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
        return ['status' => 'applied'];
    }
    sec_q("INSERT INTO planner_priorities (user_id, priority_date, position, content, is_done)
           VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), is_done=VALUES(is_done)",
          [$uid, $giorno, $posto, $testo, $fatto], 'isisi');
    return ['status' => 'applied'];
}

/* ---- routine ---- */
function sync_prout_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('planner_routines', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        sec_q("DELETE FROM planner_routine_items WHERE routine_id = ? AND user_id = ?", [$id, $uid], 'ii');
        sec_q("DELETE FROM planner_routines WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_routines', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');
    $set = [];
    if (array_key_exists('name', $patch)) {
        $n = is_string($patch['name']) ? sec_str($patch['name'], 80) : '';
        if ($n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $set['name'] = $n;
    }
    if (array_key_exists('color', $patch)) $set['color'] = sec_color($patch['color'], '#E3D9EE');

    if ($id === null) {
        if (sec_one("SELECT id FROM planner_routines WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['name'])) return sync_rifiuto('validation', 'name', 'name is required');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_routines', 'uuid' => $uuid];
        sec_q("INSERT INTO planner_routines (user_id, name, color, sort_order)
               VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_routines WHERE user_id=?) x))",
              [$uid, $set['name'], $set['color'] ?? '#E3D9EE', $uid], 'issi');
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('planner_routines', $uid, $uuid);
        unset($set['name'], $set['color']);
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_routines SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_routines', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- i passaggi di una routine ---- */
function sync_pritem_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT i.id, r.uuid AS ru FROM planner_routine_items i JOIN planner_routines r ON r.id = i.routine_id WHERE i.user_id = ? AND i.uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM planner_routine_items WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_routine_items', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');
    if ($id !== null && array_key_exists('routine_uuid', $patch) && $patch['routine_uuid'] !== $riga['ru']) return sync_rifiuto('validation', 'routine_uuid', 'routine_uuid cannot change');

    $set = [];
    if (array_key_exists('name', $patch)) {
        $n = is_string($patch['name']) ? sec_str($patch['name'], 120) : '';
        if ($n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $set['name'] = $n;
    }
    if (array_key_exists('duration_minutes', $patch)) {
        $m = $patch['duration_minutes'];
        if (!is_int($m) || $m < 1 || $m > 1440) return sync_rifiuto('validation', 'duration_minutes', 'duration_minutes must be a number from 1 to 1440');
        $set['duration_minutes'] = $m;
    }
    if ($id === null) {
        if (sec_one("SELECT id FROM planner_routine_items WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $ru = $patch['routine_uuid'] ?? null;
        if (!sync_uuid_valido($ru)) return sync_rifiuto('validation', 'routine_uuid', 'routine_uuid is required');
        $rid = sync_id_da_uuid('planner_routines', $uid, $ru);
        if ($rid === null) return sync_rifiuto('not_found', 'routine_uuid', 'Routine not found');
        if (!isset($set['name'])) return sync_rifiuto('validation', 'name', 'name is required');
        if ((int)sec_one("SELECT COUNT(*) AS n FROM planner_routine_items WHERE routine_id = ? AND user_id = ?", [$rid, $uid], 'ii')['n'] >= 40) {
            return sync_rifiuto('validation', 'routine_uuid', 'A routine can have at most 40 steps');
        }
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_routine_items', 'uuid' => $uuid];
        sec_q("INSERT INTO planner_routine_items (routine_id, user_id, name, duration_minutes, sort_order)
               VALUES (?,?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_routine_items WHERE routine_id=?) x))",
              [$rid, $uid, $set['name'], $set['duration_minutes'] ?? 5, $rid], 'iisii');
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('planner_routine_items', $uid, $uuid);
        unset($set['name'], $set['duration_minutes']);
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_routine_items SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_routine_items', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}


/* ---- la settimana del planner ---- */
function sync_pweek_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, week_start, week_number, number_edited, is_completed, learned_note FROM planner_weeks WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM planner_weeks WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_weeks', $patch, $riga === null)) return $er;

    if ($riga) {
        $lunedi = (string)$riga['week_start'];
        if (isset($patch['week_start']) && $patch['week_start'] !== $lunedi) return sync_rifiuto('validation', 'week_start', 'week_start cannot change');
    } else {
        $lunedi = is_string($patch['week_start'] ?? null) ? sec_date($patch['week_start'], null) : null;
        if ($lunedi === null) return sync_rifiuto('validation', 'week_start', 'week_start must be YYYY-MM-DD');
        if (pl_monday($lunedi) !== $lunedi) return sync_rifiuto('validation', 'week_start', 'week_start must be a Monday');
        // la settimana puo' esistere gia' (scritta dal sito): e' LA STESSA riga
        $riga = sec_one("SELECT id, week_start, week_number, number_edited, is_completed, learned_note FROM planner_weeks WHERE user_id = ? AND week_start = ?", [$uid, $lunedi]);
    }
    if ($uuid !== sync_uuid_settimana($uid, $lunedi)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the user and the Monday');

    $settings = pl_settings($uid);
    $numero = $riga ? (int)$riga['week_number'] : pl_week_number($uid, $lunedi, $settings);
    $mano = $riga ? (int)$riga['number_edited'] : 0;
    $fatta = $riga ? (int)$riga['is_completed'] : 0;
    $nota = $riga ? (string)($riga['learned_note'] ?? '') : '';
    if (array_key_exists('week_number', $patch)) {
        if (!is_int($patch['week_number']) || $patch['week_number'] < 1 || $patch['week_number'] > 9999) return sync_rifiuto('validation', 'week_number', 'week_number must be a number from 1 to 9999');
        $numero = $patch['week_number']; $mano = 1;
    }
    if (array_key_exists('is_completed', $patch)) {
        if (!is_bool($patch['is_completed'])) return sync_rifiuto('validation', 'is_completed', 'is_completed must be true or false');
        $fatta = $patch['is_completed'] ? 1 : 0;
    }
    if (array_key_exists('learned_note', $patch)) {
        if ($patch['learned_note'] !== null && !is_string($patch['learned_note'])) return sync_rifiuto('validation', 'learned_note', 'learned_note must be text');
        $nota = sec_text((string)$patch['learned_note'], 4000);
    }
    sec_q("INSERT INTO planner_weeks (user_id, week_start, week_number, number_edited, is_completed, learned_note)
           VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE week_number=VALUES(week_number), number_edited=VALUES(number_edited),
                                   is_completed=VALUES(is_completed), learned_note=VALUES(learned_note)",
          [$uid, $lunedi, $numero, $mano, $fatta, $nota], 'isiiis');
    return ['status' => 'applied'];
}

/* ---- le liste della settimana (una per tipo) ---- */
function sync_pwlist_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, week_start, list_type FROM planner_week_lists WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) {
            sec_q("DELETE FROM planner_week_list_items WHERE list_id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
            sec_q("DELETE FROM planner_week_lists WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid], 'ii');
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_week_lists', $patch, $riga === null)) return $er;
    if ($riga) {
        foreach (['week_start', 'list_type'] as $k) if (isset($patch[$k]) && $patch[$k] !== (string)$riga[$k]) return sync_rifiuto('validation', $k, "$k cannot change");
        return ['status' => 'applied'];
    }
    $lunedi = is_string($patch['week_start'] ?? null) ? sec_date($patch['week_start'], null) : null;
    if ($lunedi === null) return sync_rifiuto('validation', 'week_start', 'week_start must be YYYY-MM-DD');
    if (pl_monday($lunedi) !== $lunedi) return sync_rifiuto('validation', 'week_start', 'week_start must be a Monday');
    $tipo = $patch['list_type'] ?? null;
    if (!is_string($tipo) || !in_array($tipo, ['todo', 'buy', 'study'], true)) return sync_rifiuto('validation', 'list_type', 'list_type must be one of: todo, buy, study');
    if ($uuid !== sync_uuid_lista_sett($uid, $lunedi, $tipo)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the user, the Monday and the type');
    // come sul sito: INSERT IGNORE (c'e' gia' = e' la stessa riga)
    sec_q("INSERT IGNORE INTO planner_week_lists (user_id, week_start, list_type, sort_order)
           VALUES (?,?,?,(SELECT COALESCE(MAX(x.sort_order),0)+1 FROM (SELECT sort_order FROM planner_week_lists WHERE user_id=? AND week_start=?) x))",
          [$uid, $lunedi, $tipo, $uid, $lunedi], 'issis');
    return ['status' => 'applied'];
}

/* ---- le voci di una lista della settimana ---- */
function sync_pwitem_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT i.id, l.uuid AS lu FROM planner_week_list_items i JOIN planner_week_lists l ON l.id = i.list_id WHERE i.user_id = ? AND i.uuid = ?", [$uid, $uuid]);
    $id = $riga ? (int)$riga['id'] : null;
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM planner_week_list_items WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('planner_week_list_items', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');
    if ($id !== null && array_key_exists('list_uuid', $patch) && $patch['list_uuid'] !== $riga['lu']) return sync_rifiuto('validation', 'list_uuid', 'list_uuid cannot change');

    $set = [];
    if (array_key_exists('content', $patch)) {
        if (!is_string($patch['content'])) return sync_rifiuto('validation', 'content', 'content must be text');
        $set['content'] = sec_str($patch['content'], 200);
    }
    if (array_key_exists('is_done', $patch)) {
        if (!is_bool($patch['is_done'])) return sync_rifiuto('validation', 'is_done', 'is_done must be true or false');
        $set['is_done'] = $patch['is_done'] ? 1 : 0;
    }
    // come sul sito: una voce senza testo non esiste
    if (isset($set['content']) && $set['content'] === '') {
        if ($id !== null) sec_q("DELETE FROM planner_week_list_items WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($id === null) {
        if (sec_one("SELECT id FROM planner_week_list_items WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $lu = $patch['list_uuid'] ?? null;
        if (!sync_uuid_valido($lu)) return sync_rifiuto('validation', 'list_uuid', 'list_uuid is required');
        $lid = sync_id_da_uuid('planner_week_lists', $uid, $lu);
        if ($lid === null) return sync_rifiuto('not_found', 'list_uuid', 'List not found');
        if (!isset($set['content'])) return sync_rifiuto('validation', 'content', 'content is required');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_week_list_items', 'uuid' => $uuid];
        sec_q("INSERT INTO planner_week_list_items (list_id, user_id, content, is_done, sort_order)
               VALUES (?,?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_week_list_items WHERE list_id=?) x))",
              [$lid, $uid, $set['content'], $set['is_done'] ?? 0, $lid], 'iisii');
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        $id = sync_id_da_uuid('planner_week_list_items', $uid, $uuid);
        unset($set['content'], $set['is_done']);
    }
    if ($set && $id !== null) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_week_list_items SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_week_list_items', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}
