<?php
/* =====================================================================
   STANDOUT — sync: diario (il giorno e i due elenchi)

   Come per le abitudini (lib/sync_abitudini.php): NIENTE regole riscritte. Ogni modifica
   dell'app passa dalle funzioni della rotta normale (dg_api_giorno_salva, dg_api_voce_*),
   quindi limiti e controlli sono quelli di sempre.

   IL GIORNO E' UNA RIGA PER (UTENTE, GIORNO): identita' = UUIDv5 (sync_uuid_diario). Il
   sito e un telefono offline, il 5 novembre, scrivono LA STESSA riga.

   IL TESTO NON SI PERDE. Il telefono manda il testo insieme a `note_rev`, l'impronta del
   testo sul server che aveva visto. Se nel frattempo il testo sul server e' cambiato
   (sito, altro telefono) e quello del telefono e' diverso, NON si sovrascrive: si tengono
   TUTTE E DUE le versioni, quella del server prima e quella del telefono dopo, divise da una
   riga. Il telefono le riceve al giro successivo. Umore ed energia sono numeri: vince
   l'ultimo che scrive.
   ===================================================================== */

const SYNC_DIARIO_DIVISORE = "\n\n———\n\n";
const SYNC_DIARIO_NOTA_MAX = 20000;

/** Le due versioni del testo, una dopo l'altra. Uguali, vuote o gia' contenute: una sola. */
function sync_diario_unisci(string $server, string $telefono): string {
    if ($server === $telefono || trim($telefono) === '') return $server;
    if (trim($server) === '') return $telefono;
    if (mb_strpos($server, $telefono) !== false) return $server;
    // il telefono ha continuato a scrivere sopra il testo che il server ha gia' (scritto durante l'invio): vale il suo
    if (mb_strpos($telefono, $server) === 0) return $telefono;
    return mb_substr($server . SYNC_DIARIO_DIVISORE . $telefono, 0, SYNC_DIARIO_NOTA_MAX);
}

function sync_hjournal_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, entry_date, note, mood, energy FROM habit_daily_journal WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);

    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM habit_daily_journal WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('habit_daily_journal', $patch, $riga === null)) return $er;

    // ---- a quale giorno si riferisce ----
    if ($riga) {
        $giorno = (string)$riga['entry_date'];
        if (isset($patch['entry_date']) && $patch['entry_date'] !== $giorno) return sync_rifiuto('validation', 'entry_date', 'entry_date cannot change');
    } else {
        $giorno = is_string($patch['entry_date'] ?? null) ? sec_date($patch['entry_date'], null) : null;
        if ($giorno === null) return sync_rifiuto('validation', 'entry_date', 'entry_date must be YYYY-MM-DD');
        // il giorno puo' esistere gia' (scritto dal sito): e' LA STESSA riga, si aggiorna
        $riga = sec_one("SELECT id, entry_date, note, mood, energy FROM habit_daily_journal WHERE user_id = ? AND entry_date = ?", [$uid, $giorno]);
    }
    if ($uuid !== sync_uuid_diario($uid, $giorno)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the user and the day');

    // ---- i campi da scrivere (solo quelli nominati) ----
    $c = ['date' => $giorno];
    foreach (['mood', 'energy'] as $k) if (array_key_exists($k, $patch)) $c[$k] = $patch[$k];
    if (array_key_exists('note', $patch)) {
        $nuovo = $patch['note'] === null ? '' : $patch['note'];
        if (!is_string($nuovo)) return sync_rifiuto('validation', 'note', 'note must be text or null');
        if (mb_strlen($nuovo) > SYNC_DIARIO_NOTA_MAX) return sync_rifiuto('validation', 'note', 'note can be at most ' . SYNC_DIARIO_NOTA_MAX . ' characters');
        $attuale = $riga ? (string)($riga['note'] ?? '') : '';
        $base = $patch['note_rev'] ?? null;
        // il testo sul server e' cambiato da quando il telefono l'ha visto, e non e' lo stesso: si tengono tutte e due
        if ($riga && is_string($base) && $base !== sha1($attuale)) $nuovo = sync_diario_unisci($attuale, $nuovo);
        $c['note'] = $nuovo;
    }
    if (count($c) > 1) {
        $r = dg_api_giorno_salva($uid, $c);
        if (isset($r['errore'])) return sync_da_errore($r, []);
    }

    // una riga rimasta vuota non serve a nessuno
    $adesso = sec_one("SELECT id, note, mood, energy FROM habit_daily_journal WHERE user_id = ? AND entry_date = ?", [$uid, $giorno]);
    if ($adesso && trim((string)($adesso['note'] ?? '')) === '' && $adesso['mood'] === null && $adesso['energy'] === null) {
        sec_q("DELETE FROM habit_daily_journal WHERE id = ? AND user_id = ?", [(int)$adesso['id'], $uid]);
    }
    return ['status' => 'applied'];
}

function sync_dlist_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('diary_list_items', $uid, $uuid);
    if ($op === 'delete') {
        if ($id !== null) dg_api_voce_elimina($uid, $id);
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('diary_list_items', $patch, $id === null)) return $er;

    if ($id === null) {
        if (sec_one("SELECT id FROM diary_list_items WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if (!is_string($patch['entry_date'] ?? null)) return sync_rifiuto('validation', 'entry_date', 'entry_date must be YYYY-MM-DD');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'diary_list_items', 'uuid' => $uuid];
        $r = dg_api_voce_crea($uid, [
            'date'      => $patch['entry_date'],
            'list_type' => $patch['list_type'] ?? null,
            'content'   => $patch['content'] ?? null,
        ]);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        if (isset($r['errore'])) return sync_da_errore($r, ['date' => 'entry_date']);
        return ['status' => 'applied'];
    }
    // una voce esistente: cambia solo il testo (giorno ed elenco non si spostano)
    $voce = dg_api_voce($uid, $id);
    if (isset($patch['entry_date']) && $patch['entry_date'] !== $voce['date']) return sync_rifiuto('validation', 'entry_date', 'entry_date cannot change');
    if (isset($patch['list_type']) && $patch['list_type'] !== $voce['list_type']) return sync_rifiuto('validation', 'list_type', 'list_type cannot change');
    if (array_key_exists('content', $patch)) {
        $r = dg_api_voce_modifica($uid, $id, ['content' => $patch['content']]);
        if (isset($r['errore'])) return sync_da_errore($r, []);
    }
    return ['status' => 'applied'];
}
