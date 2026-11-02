<?php
/* =====================================================================
   STANDOUT — sync: pastiglie (terapie e prese)

   Come per le abitudini (lib/sync_abitudini.php), NIENTE regole riscritte: ogni modifica
   dell'app passa dalle STESSE funzioni dell'API normale (pl_pillole_crea / modifica /
   spunta, td_pill_elimina), cosi' il ritmo, «da quando vale l'orario» (schedule_from) e le
   prese che non devono tornare restano quelli del sito.

   LE PRESE NASCONO DA UNA REGOLA. Il sito le scrive il giorno in cui servono
   (td_pills_ensure_giorni); il telefono le calcola da solo, anche offline, con la stessa
   regola. Per questo l'identita' di una presa e' un UUIDv5 di (terapia, giorno, orario, n.)
   (decisione D4): sito e telefono parlano della stessa riga invece di crearne due. Il
   telefono manda una presa SOLO quando l'utente la tocca (presa / saltata / di nuovo da
   prendere): sul filo viaggiano lo stato e l'ora vera della spunta.

   Una presa non si cancella da sola: sparisce con la sua terapia (o quando la terapia cambia
   ritmo: lo fa td_pill_scrivi e il registro lo annota, il telefono riceve le cancellazioni).
   Fuso orario: quello del sito (Europe/Rome), come la pagina.
   ===================================================================== */

/* ---- terapie ---- */
function sync_pplans_applica(int $uid, string $uuid, string $op, array $patch): array {
    date_default_timezone_set('Europe/Rome');
    $id = sync_id_da_uuid('pill_plans', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        td_pill_elimina($uid, $id);   // via anche le prese (il registro annota le cancellazioni)
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('pill_plans', $patch, $id === null)) return $er;

    // dal nome sul filo al nome che pl_pillole_* capisce (e ritorno, per il campo degli errori)
    $ritorno = ['active' => 'is_active'];
    $api = [];
    foreach (['name', 'dose', 'notes', 'color', 'freq_type', 'weekdays', 'month_days', 'interval_days', 'times', 'start_date', 'end_date'] as $k) {
        if (array_key_exists($k, $patch)) $api[$k] = $patch[$k];
    }
    if (array_key_exists('is_active', $patch)) {
        // sul filo i booleani sono true/false; si accetta anche 0/1 (come li scrive il database)
        $v = $patch['is_active'];
        if ($v === 0 || $v === 1) $v = ($v === 1);
        $api['active'] = $v;
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM pill_plans WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'pill_plans', 'uuid' => $uuid];
        try {
            $r = pl_pillole_crea($uid, $api);
        } finally {
            unset($GLOBALS['SYNC_UUID_NUOVO']);
        }
        if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
    } elseif ($api) {
        $r = pl_pillole_modifica($uid, $id, $api);
        if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
    }
    return ['status' => 'applied'];
}

/* ---- prese ---- */
function sync_pdoses_applica(int $uid, string $uuid, string $op, array $patch): array {
    date_default_timezone_set('Europe/Rome');
    $leggi = "SELECT d.id, d.plan_id, d.due_date, d.due_time, d.seq, d.status, p.uuid AS plan_uuid
              FROM pill_doses d JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id";
    $riga = sec_one("$leggi WHERE d.user_id = ? AND d.uuid = ?", [$uid, $uuid]);

    if ($op === 'delete') {
        if (!$riga) return ['status' => 'applied'];
        return sync_rifiuto('validation', 'op', 'A dose cannot be deleted on its own');
    }
    if ($er = sync_controlla_chiavi('pill_doses', $patch, $riga === null)) return $er;

    if ($riga) {
        // la chiave non cambia: terapia, giorno, orario e numero sono l'identita' della presa
        foreach (['plan_uuid' => 'plan_uuid', 'due_date' => 'due_date', 'seq' => 'seq'] as $k => $col) {
            if (array_key_exists($k, $patch) && (string)$patch[$k] !== (string)$riga[$col]) return sync_rifiuto('validation', $k, "$k cannot change");
        }
        if (array_key_exists('due_time', $patch) && substr((string)$patch['due_time'], 0, 5) !== substr((string)$riga['due_time'], 0, 5)) {
            return sync_rifiuto('validation', 'due_time', 'due_time cannot change');
        }
    } else {
        $pu = $patch['plan_uuid'] ?? null;
        if (!sync_uuid_valido($pu)) return sync_rifiuto('validation', 'plan_uuid', 'plan_uuid is required');
        $giorno = is_string($patch['due_date'] ?? null) ? sec_date($patch['due_date'], null) : null;
        if ($giorno === null) return sync_rifiuto('validation', 'due_date', 'due_date must be YYYY-MM-DD');
        $ora = is_string($patch['due_time'] ?? null) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $patch['due_time']) ? $patch['due_time'] : null;
        if ($ora === null) return sync_rifiuto('validation', 'due_time', 'due_time must be HH:MM');
        $seq = $patch['seq'] ?? null;
        if (!is_int($seq) || $seq < 1 || $seq > 9) return sync_rifiuto('validation', 'seq', 'seq must be a number from 1 to 9');
        if ($giorno > date('Y-m-d')) return sync_rifiuto('validation', 'due_date', 'due_date cannot be in the future');
        if ($uuid !== sync_uuid_presa($pu, $giorno, $ora, $seq)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the therapy, day, time and number');
        $p = sec_one("SELECT id FROM pill_plans WHERE user_id = ? AND uuid = ?", [$uid, $pu]);
        if (!$p) return sync_rifiuto('not_found', 'plan_uuid', 'Therapy not found');
        $pid = (int)$p['id'];
        // c'e' gia' quella presa (la ha scritta il sito): e' LA STESSA riga
        $riga = sec_one("$leggi WHERE d.user_id = ? AND d.plan_id = ? AND d.due_date = ? AND d.due_time = ? AND d.seq = ?", [$uid, $pid, $giorno, $ora . ':00', $seq]);
        if (!$riga) {
            // la presa nasce qui: la stessa INSERT del sito (il registro le da' l'uuid atteso)
            sec_q("INSERT IGNORE INTO pill_doses (user_id, plan_id, due_date, due_time, seq) VALUES (?, ?, ?, ?, ?)",
                  [$uid, $pid, $giorno, $ora . ':00', $seq], 'iissi');
            $riga = sec_one("$leggi WHERE d.user_id = ? AND d.plan_id = ? AND d.due_date = ? AND d.due_time = ? AND d.seq = ?", [$uid, $pid, $giorno, $ora . ':00', $seq]);
            if (!$riga) return sync_rifiuto('server_error', 'uuid', 'Dose could not be created');
        }
    }

    // ---- stato e ora vera ----
    $stato = array_key_exists('status', $patch) ? $patch['status'] : (string)$riga['status'];
    $quando = null;
    if (array_key_exists('taken_at', $patch) && $patch['taken_at'] !== null) {
        if (!is_string($patch['taken_at']) || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $patch['taken_at'], $m)) {
            return sync_rifiuto('validation', 'taken_at', 'taken_at must be YYYY-MM-DD HH:MM');
        }
        $quando = $m[1] . ' ' . $m[2];
        // l'orologio del telefono puo' andare avanti di qualche minuto: l'ora vera non finisce nel futuro, la si porta a «adesso»
        $adesso = date('Y-m-d H:i');
        if ($quando > $adesso) $quando = $adesso;
    }
    if (!is_string($stato)) return sync_rifiuto('validation', 'status', 'status must be pending, taken or skipped');
    if ($stato !== 'taken') $quando = null;   // «presa» e' l'unico stato con un'ora vera
    $c = ['status' => $stato];
    if ($quando !== null) $c['taken_at'] = $quando;
    $r = pl_pillole_spunta($uid, (int)$riga['id'], $c);
    if (isset($r['errore'])) return sync_da_errore($r, ['status' => 'status', 'taken_at' => 'taken_at', 'id' => 'uuid']);
    return ['status' => 'applied'];
}
