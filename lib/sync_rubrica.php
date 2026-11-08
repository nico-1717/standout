<?php
/* =====================================================================
   STANDOUT — sync: rubrica, invitati degli eventi e luoghi salvati

   Le regole sono quelle del sito (planner_api.php: contact_save, place_book_save; pl_scrivi.php: invitati):
     - un contatto ha un indirizzo e-mail valido (uno solo per utente, in minuscolo) e un nome fino a 80; senza nome
       il nome e' la parte prima della chiocciola
     - un invitato e' un indirizzo di un evento: al massimo 30 per evento, uno solo per indirizzo; il nome viene
       copiato dalla rubrica se non c'e', cosi' un evento passato ricorda come si chiamava il contatto allora
     - un luogo ha un nome (label) fino a 60, parole d'aggancio in minuscolo fino a 255, nome del posto, indirizzo e
       riferimento fino a 255; le coordinate valgono insieme (entrambe valide o nessuna)
   ===================================================================== */

const SYNC_INVITATI_MAX = 30;

function sync_rub_campi(string $tbl, array $patch, bool $creazione): ?array {
    if ($er = sync_controlla_chiavi($tbl, $patch, $creazione)) return $er;
    foreach ($patch as $k => $v) {
        $def = SYNC_MANIFESTO[$tbl]['campi'][$k];
        if (!empty($def['solo_lettura'])) return sync_rifiuto('validation', $k, "$k is read-only");
        if (!$creazione && !empty($def['solo_creazione'])) return sync_rifiuto('validation', $k, "$k cannot change");
    }
    return null;
}

/* ---------------- contatti ---------------- */

function sync_contatti_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM contacts WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM contacts WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        // gli invitati che lo ricordavano restano, scritti a mano (come sul sito, dove non c'e' nessuna chiave esterna)
        if ($riga) sec_q("UPDATE event_guests SET contact_id = NULL WHERE contact_id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_rub_campi('contacts', $patch, $riga === null)) return $er;

    $mail = null; $nome = null;
    if (array_key_exists('email', $patch)) {
        $mail = is_string($patch['email']) ? sec_email($patch['email']) : null;
        if (!$mail || strlen($mail) > 255) return sync_rifiuto('validation', 'email', 'email must be a valid address');
    }
    if (array_key_exists('name', $patch)) {
        if ($patch['name'] !== null && !is_string($patch['name'])) return sync_rifiuto('validation', 'name', 'name must be text');
        $nome = sec_str((string)$patch['name'], 80);
    }
    if ($riga) {
        $set = [];
        if ($mail !== null && $mail !== $riga['email']) {
            if (sec_one("SELECT 1 AS x FROM contacts WHERE user_id = ? AND email = ? AND id != ?", [$uid, $mail, (int)$riga['id']]))
                return sync_rifiuto('forbidden', 'email', 'This address is already in your contacts');
            $set['email'] = $mail;
        }
        if ($nome !== null) $set['name'] = $nome !== '' ? $nome : explode('@', $mail ?? $riga['email'])[0];
        if ($set) {
            $par = array_values($set); $par[] = (int)$riga['id']; $par[] = $uid;
            sec_q("UPDATE contacts SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
        }
        return ['status' => 'applied'];
    }
    if ($mail === null) return sync_rifiuto('validation', 'email', 'email is required');
    if (sec_one("SELECT 1 AS x FROM contacts WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
    if (sec_one("SELECT 1 AS x FROM contacts WHERE user_id = ? AND email = ?", [$uid, $mail])) return sync_rifiuto('forbidden', 'email', 'This address is already in your contacts');
    if ($nome === null || $nome === '') $nome = explode('@', $mail)[0];
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'contacts', 'uuid' => $uuid];
    try { sec_q("INSERT INTO contacts (user_id, name, email) VALUES (?,?,?)", [$uid, $nome, $mail]); } finally { unset($GLOBALS['SYNC_UUID_NUOVO']); }
    return ['status' => 'applied'];
}

/* ---------------- invitati ---------------- */

function sync_invitati_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM event_guests WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM event_guests WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_rub_campi('event_guests', $patch, $riga === null)) return $er;

    $set = [];
    if (array_key_exists('name', $patch)) {
        if ($patch['name'] !== null && !is_string($patch['name'])) return sync_rifiuto('validation', 'name', 'name must be text');
        $n = $patch['name'] === null ? '' : sec_str($patch['name'], 80);
        $set['name'] = $n !== '' ? $n : null;
    }
    if (array_key_exists('contact_uuid', $patch)) {
        if ($patch['contact_uuid'] === null) $set['contact_id'] = null;
        else {
            if (!sync_uuid_valido($patch['contact_uuid'])) return sync_rifiuto('validation', 'contact_uuid', 'Invalid uuid');
            $cid = sync_id_da_uuid('contacts', $uid, $patch['contact_uuid']);
            if ($cid === null) return sync_rifiuto('not_found', 'contact_uuid', 'Contact not found');
            $set['contact_id'] = $cid;
        }
    }
    if ($riga) {
        if ($set) {
            $par = array_values($set); $par[] = (int)$riga['id']; $par[] = $uid;
            sec_q("UPDATE event_guests SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
        }
        return ['status' => 'applied'];
    }
    if (!sync_uuid_valido($patch['event_uuid'] ?? null)) return sync_rifiuto('validation', 'event_uuid', 'Invalid uuid');
    $eid = sync_id_da_uuid('planner_events', $uid, $patch['event_uuid']);
    if ($eid === null) return sync_rifiuto('not_found', 'event_uuid', 'Event not found');
    $mail = is_string($patch['email'] ?? null) ? sec_email($patch['email']) : null;
    if (!$mail || strlen($mail) > 255) return sync_rifiuto('validation', 'email', 'email must be a valid address');
    if (sec_one("SELECT 1 AS x FROM event_guests WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
    if (sec_one("SELECT 1 AS x FROM event_guests WHERE event_id = ? AND email = ?", [$eid, $mail])) return sync_rifiuto('forbidden', 'email', 'This guest is already invited');
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM event_guests WHERE event_id = ? AND user_id = ?", [$eid, $uid])['n'] ?? 0);
    if ($n >= SYNC_INVITATI_MAX) return sync_rifiuto('forbidden', 'email', 'Too many guests');
    $nome = $set['name'] ?? null;
    if ($nome === null) {
        $c = sec_one("SELECT name FROM contacts WHERE user_id = ? AND email = ?", [$uid, $mail]);
        $nome = ($c['name'] ?? '') !== '' ? $c['name'] : null;
    }
    $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'event_guests', 'uuid' => $uuid];
    try { sec_q("INSERT INTO event_guests (event_id, user_id, contact_id, email, name) VALUES (?,?,?,?,?)", [$eid, $uid, $set['contact_id'] ?? null, $mail, $nome]); } finally { unset($GLOBALS['SYNC_UUID_NUOVO']); }
    return ['status' => 'applied'];
}

/* ---------------- luoghi ---------------- */

function sync_luoghi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('planner_places', $uid, $uuid);
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM planner_places WHERE id = ? AND user_id = ?", [$id, $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_rub_campi('planner_places', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $set = [];
    if (array_key_exists('label', $patch)) {
        $l = is_string($patch['label']) ? sec_str($patch['label'], 60) : '';
        if ($l === '') return sync_rifiuto('validation', 'label', 'label cannot be empty');
        $set['label'] = $l;
    }
    if (array_key_exists('keywords', $patch)) {
        if ($patch['keywords'] !== null && !is_string($patch['keywords'])) return sync_rifiuto('validation', 'keywords', 'keywords must be text');
        $set['keywords'] = mb_strtolower(sec_str((string)$patch['keywords'], 255), 'UTF-8');
    }
    foreach (['place_name' => 255, 'address' => 255, 'place_ref' => 255] as $k => $max) {
        if (!array_key_exists($k, $patch)) continue;
        if ($patch[$k] !== null && !is_string($patch[$k])) return sync_rifiuto('validation', $k, "$k must be text");
        $v = sec_str((string)$patch[$k], $max);
        $set[$k] = ($v === '' && $k !== 'address') ? null : $v;
    }
    foreach (['place_lat' => 90, 'place_lon' => 180] as $k => $lim) {
        if (!array_key_exists($k, $patch)) continue;
        $v = $patch[$k];
        if ($v === null) { $set[$k] = null; continue; }
        if (!is_int($v) && !is_float($v)) return sync_rifiuto('validation', $k, "$k must be a number");
        if ($v < -$lim || $v > $lim) return sync_rifiuto('validation', $k, "$k is out of range");
        $set[$k] = round((float)$v, 7);
    }
    // le coordinate vanno in coppia: o tutte e due o nessuna (come sul sito)
    if ($id !== null) {
        $ora = sec_one("SELECT place_lat, place_lon FROM planner_places WHERE id = ?", [$id]) ?: [];
        $lat = array_key_exists('place_lat', $set) ? $set['place_lat'] : ($ora['place_lat'] ?? null);
        $lon = array_key_exists('place_lon', $set) ? $set['place_lon'] : ($ora['place_lon'] ?? null);
    } else {
        $lat = $set['place_lat'] ?? null; $lon = $set['place_lon'] ?? null;
    }
    if (($lat === null) !== ($lon === null)) return sync_rifiuto('validation', 'place_lat', 'place_lat and place_lon go together');

    if ($id === null) {
        if (sec_one("SELECT 1 AS x FROM planner_places WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!isset($set['label'])) return sync_rifiuto('validation', 'label', 'label is required');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'planner_places', 'uuid' => $uuid];
        try {
            sec_q("INSERT INTO planner_places (user_id, label, keywords, place_name, address, place_lat, place_lon, place_ref, sort_order)
                   VALUES (?,?,?,?,?,?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM planner_places WHERE user_id=?) x))",
                  [$uid, $set['label'], $set['keywords'] ?? '', $set['place_name'] ?? null, $set['address'] ?? '', $set['place_lat'] ?? null, $set['place_lon'] ?? null, $set['place_ref'] ?? null, $uid]);
        } finally { unset($GLOBALS['SYNC_UUID_NUOVO']); }
        $id = sync_id_da_uuid('planner_places', $uid, $uuid);
    } elseif ($set) {
        $par = array_values($set); $par[] = $id; $par[] = $uid;
        sec_q("UPDATE planner_places SET " . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . " WHERE id = ? AND user_id = ?", $par);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('planner_places', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}
