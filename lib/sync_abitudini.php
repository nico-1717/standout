<?php
/* =====================================================================
   STANDOUT — sync: abitudini (categorie, abitudini, check-in)

   Come per to do e to buy (lib/sync_api.php), NIENTE regole riscritte: ogni modifica
   dell'app passa dalle STESSE funzioni delle rotte normali (hb_api_cat_*, hb_api_crea /
   hb_api_modifica, hb_log_imposta, hb_nota_salva), cosi' i controlli, la serie, i
   traguardi e il planner collegato sono quelli di sempre.

   IL CHECK-IN VIAGGIA COME STATO DEL GIORNO, non come «tap». Il telefono manda
   «il 5 novembre l'abitudine X e' a 3 bicchieri»; il server porta la riga a quel valore
   con hb_log_imposta(mode "set") e ricalcola da solo stato, serie e traguardi.
   Perche': i tap si sommano male (due telefoni che toccano lo stesso giorno non devono
   contare doppio) e rimandare lo stesso stato e' innocuo (idempotente).
   Lo stato «saltata» e' l'eccezione: passa da mode "skip".

   L'identita' di un check-in e' un UUIDv5 di (abitudine, giorno) (decisione D4): sito e
   telefono, anche offline, creano LA STESSA riga. Un uuid diverso da quello atteso e'
   rifiutato.

   Fuso orario: «oggi» e' quello del sito (Europe/Rome), come per la pagina. Un check-in
   con un giorno che per il server e' nel futuro viene rifiutato (validation / log_date).
   ===================================================================== */

/* ---- categorie delle abitudini ---- */
function sync_hcat_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('habit_categories', $uid, $uuid);
    if ($id === null && sec_one("SELECT id FROM habit_categories WHERE uuid = ?", [$uuid])) {
        // la riga esiste ma non e' sua: una categoria di sistema o di un altro utente. Non si tocca.
        return sync_rifiuto('forbidden', 'uuid', 'This category cannot be changed');
    }
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        hb_api_cat_elimina($uid, $id);   // le abitudini restano, senza categoria (le righe cambiate le annota il registro)
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('habit_categories', $patch, $id === null)) return $er;
    $api = [];
    foreach (['name', 'icon', 'color'] as $k) if (array_key_exists($k, $patch)) $api[$k] = $patch[$k];

    if ($id === null) {
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'habit_categories', 'uuid' => $uuid];
        $r = hb_api_cat_crea($uid, $api);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        if (isset($r['errore'])) return sync_da_errore($r, ['name' => 'name', 'icon' => 'icon', 'color' => 'color']);
    } elseif ($api) {
        $r = hb_api_cat_modifica($uid, $id, $api);
        if (isset($r['errore'])) return sync_da_errore($r, ['name' => 'name', 'icon' => 'icon', 'color' => 'color']);
    }
    return ['status' => 'applied'];
}

/* ---- abitudini ---- */
function sync_habits_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('habits', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];
        hb_abitudine_elimina($uid, $id);   // i check-in spariscono a cascata (chiave esterna): l'app li toglie da sola
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('habits', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    // dal nome sul filo al nome che hb_api_* capisce (e ritorno, per il campo degli errori)
    $ritorno = ['category_id' => 'category_uuid', 'archived' => 'is_archived'];
    $api = [];
    foreach (['name', 'icon', 'color', 'motivation', 'preset_key', 'kind', 'goal_type', 'target_amount', 'step_amount', 'unit',
              'frequency_type', 'weekdays', 'times_per_week', 'times_per_month', 'interval_days', 'day_part', 'duration_type',
              'duration_days', 'start_date', 'end_date', 'planner_minutes'] as $k) {
        if (array_key_exists($k, $patch)) $api[$k] = $patch[$k];
    }
    if (array_key_exists('is_archived', $patch)) $api['archived'] = $patch['is_archived'];
    if (array_key_exists('category_uuid', $patch)) {
        $cu = $patch['category_uuid'];
        if ($cu === null) $api['category_id'] = null;
        elseif (!sync_uuid_valido($cu)) return sync_rifiuto('validation', 'category_uuid', 'Invalid uuid');
        else {
            // le categorie di partenza (user_id 0) sono di tutti; le altre solo di chi le ha create
            $c = sec_one("SELECT id FROM habit_categories WHERE uuid = ? AND (user_id = 0 OR user_id = ?)", [$cu, $uid]);
            if (!$c) return sync_rifiuto('not_found', 'category_uuid', 'Category not found');
            $api['category_id'] = (int)$c['id'];
        }
    }

    if ($id === null) {
        if (sec_one("SELECT id FROM habits WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'habits', 'uuid' => $uuid];
        $r = hb_api_crea($uid, $api);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
        $id = sync_id_da_uuid('habits', $uid, $uuid);
    } elseif ($api) {
        $r = hb_api_modifica($uid, $id, $api);
        if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('habits', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- check-in ---- */
function sync_hlogs_applica(int $uid, string $uuid, string $op, array $patch): array {
    date_default_timezone_set('Europe/Rome');   // come hb_api_oggi(): lo stesso «oggi» della pagina
    $riga = sec_one("SELECT l.id, l.habit_id, l.log_date, l.status, l.amount, l.note, l.mood, h.uuid AS habit_uuid
                     FROM habit_logs l JOIN habits h ON h.id = l.habit_id
                     WHERE l.user_id = ? AND l.uuid = ?", [$uid, $uuid]);

    if ($op === 'delete') {
        if (!$riga) return ['status' => 'applied'];
        // «clear» toglie la riga e ricalcola serie e record, come il tasto «annulla» della pagina
        hb_log_imposta($uid, (int)$riga['habit_id'], (string)$riga['log_date'], 'clear', 0.0, 'auto', '');
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('habit_logs', $patch, $riga === null)) return $er;

    // ---- a quale abitudine e a quale giorno si riferisce ----
    if ($riga) {
        $habitId = (int)$riga['habit_id']; $giorno = (string)$riga['log_date']; $habitUuid = (string)$riga['habit_uuid'];
        if (isset($patch['habit_uuid']) && $patch['habit_uuid'] !== $habitUuid) return sync_rifiuto('validation', 'habit_uuid', 'habit_uuid cannot change');
        if (isset($patch['log_date']) && $patch['log_date'] !== $giorno) return sync_rifiuto('validation', 'log_date', 'log_date cannot change');
    } else {
        $habitUuid = $patch['habit_uuid'] ?? null;
        if (!sync_uuid_valido($habitUuid)) return sync_rifiuto('validation', 'habit_uuid', 'habit_uuid is required');
        $giorno = is_string($patch['log_date'] ?? null) ? sec_date($patch['log_date'], null) : null;
        if ($giorno === null) return sync_rifiuto('validation', 'log_date', 'log_date must be YYYY-MM-DD');
        $h = sec_one("SELECT id FROM habits WHERE user_id = ? AND uuid = ?", [$uid, $habitUuid]);
        if (!$h) return sync_rifiuto('not_found', 'habit_uuid', 'Habit not found');
        $habitId = (int)$h['id'];
        // c'e' gia' un check-in di quel giorno (fatto dal sito): e' LA STESSA riga, si aggiorna
        $riga = sec_one("SELECT id, habit_id, log_date, status, amount, note, mood FROM habit_logs WHERE user_id = ? AND habit_id = ? AND log_date = ?",
                        [$uid, $habitId, $giorno]);
    }
    if ($uuid !== sync_uuid_check_in($habitUuid, $giorno)) return sync_rifiuto('validation', 'uuid', 'uuid must be the UUIDv5 of the habit and the day');
    if ($giorno > date('Y-m-d')) return sync_rifiuto('validation', 'log_date', 'log_date cannot be in the future');

    // ---- i valori ----
    $statiOk = ['done', 'partial', 'skipped', 'failed'];
    if (array_key_exists('status', $patch) && (!is_string($patch['status']) || !in_array($patch['status'], $statiOk, true))) {
        return sync_rifiuto('validation', 'status', 'status must be one of: ' . implode(', ', $statiOk));
    }
    if (array_key_exists('amount', $patch) && ((!is_int($patch['amount']) && !is_float($patch['amount'])) || $patch['amount'] < 0 || $patch['amount'] > 1000000)) {
        return sync_rifiuto('validation', 'amount', 'amount must be a number from 0 to 1000000');
    }
    if (array_key_exists('note', $patch) && $patch['note'] !== null && (!is_string($patch['note']) || mb_strlen($patch['note']) > 500)) {
        return sync_rifiuto('validation', 'note', 'note must be text of at most 500 characters, or null');
    }
    if (array_key_exists('mood', $patch) && $patch['mood'] !== null && (!is_int($patch['mood']) || $patch['mood'] < 1 || $patch['mood'] > 5)) {
        return sync_rifiuto('validation', 'mood', 'mood must be a number from 1 to 5, or null');
    }

    $primaStato = $riga ? (string)$riga['status'] : 'partial';
    $primaQta   = $riga ? (float)$riga['amount'] : 0.0;
    $stato = array_key_exists('status', $patch) ? $patch['status'] : $primaStato;
    $qta   = array_key_exists('amount', $patch) ? (float)$patch['amount'] : $primaQta;
    $nota  = array_key_exists('note', $patch) ? (string)($patch['note'] ?? '') : ($riga ? (string)($riga['note'] ?? '') : '');
    $umore = array_key_exists('mood', $patch) ? $patch['mood'] : ($riga && $riga['mood'] !== null ? (int)$riga['mood'] : null);
    $nota  = sec_str($nota, 500);

    // ---- 1) quantita' e stato, con la funzione di sempre ----
    $esito = null;
    if ($stato === 'skipped') {
        if ($primaStato !== 'skipped') $esito = hb_log_imposta($uid, $habitId, $giorno, 'skip', 0.0, 'auto', '');
    } elseif ($qta != $primaQta || $primaStato === 'skipped' || (!$riga && $qta > 0)) {
        // «set» rifa lo stato dalla quantita' (anche per le abitudini da evitare) e toglie «saltata»
        $esito = hb_log_imposta($uid, $habitId, $giorno, 'set', $qta, 'auto', '');
    }
    // (uno stato diverso a parita' di quantita' non si forza: il server lo deriva sempre dalla quantita')
    if (is_array($esito) && isset($esito['errore'])) {
        return $esito['errore'] === 'futuro'
            ? sync_rifiuto('validation', 'log_date', 'log_date cannot be in the future')
            : sync_rifiuto('not_found', 'habit_uuid', 'Habit not found');
    }

    // ---- 2) nota e umore (la funzione di sempre li riscrive insieme: si passano entrambi) ----
    $adesso = sec_one("SELECT id, status, amount, note, mood FROM habit_logs WHERE habit_id = ? AND log_date = ? AND user_id = ?", [$habitId, $giorno, $uid]);
    $notaAttuale  = $adesso ? (string)($adesso['note'] ?? '') : '';
    $umoreAttuale = $adesso && $adesso['mood'] !== null ? (int)$adesso['mood'] : null;
    if ($nota !== $notaAttuale || $umore !== $umoreAttuale) {
        if ($nota !== '' || $umore !== null || $adesso) {
            $r = hb_nota_salva($uid, $habitId, $giorno, $nota, $umore);
            if (isset($r['errore'])) return sync_rifiuto('not_found', 'habit_uuid', 'Habit not found');
        }
    }
    // una riga rimasta vuota (nessuna quantita', nessuna nota, nessun umore, non saltata) non serve a nessuno
    $adesso = sec_one("SELECT id, status, amount, note, mood FROM habit_logs WHERE habit_id = ? AND log_date = ? AND user_id = ?", [$habitId, $giorno, $uid]);
    if ($adesso && (float)$adesso['amount'] <= 0 && $adesso['status'] === 'partial' && (string)($adesso['note'] ?? '') === '' && $adesso['mood'] === null) {
        sec_q("DELETE FROM habit_logs WHERE id = ? AND user_id = ?", [(int)$adesso['id'], $uid]);
    }
    return ['status' => 'applied'];
}
