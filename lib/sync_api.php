<?php
/* =====================================================================
   STANDOUT — /api/v1/sync/*: l'invio e la ricezione dell'app

   POST /sync/push   il telefono manda le sue modifiche (max 200), in ordine.
   GET  /sync/pull   il telefono chiede cosa e' cambiato dopo il suo cursore.
   GET  /sync/state  la testa del registro, il pavimento e la lista delle tabelle.

   REGOLE (docs/ARCHITETTURA_APP.md §3):
   - sul filo ci sono solo uuid, mai gli id numerici del server;
   - ogni modifica ha un mutation_id: rimandarla non la applica due volte
     (sync_mutations); la risposta e' la stessa di allora;
   - NIENTE regole riscritte: ogni modifica passa dalle STESSE funzioni delle
     rotte normali (td_api_*, sp_*, sp_cat_*), cosi' i controlli, il planner
     collegato e la memoria del to buy sono quelli di sempre;
   - chi e' l'utente lo dice il token (mai il corpo della richiesta): ogni
     lettura e scrittura ha user_id = quello dell'utente del token.
   ===================================================================== */

require_once __DIR__ . '/api_v1.php';
require_once __DIR__ . '/todo_core.php';
require_once __DIR__ . '/shopping_api.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/habits_api.php';
require_once __DIR__ . '/pills_api.php';
require_once __DIR__ . '/planner/planner_lib.php';

const SYNC_PUSH_MAX = 200;
const SYNC_PULL_MAX = 1000;

/* ---------------------------------------------------------------------
   PULL: dalla riga del database alla riga sul filo
   --------------------------------------------------------------------- */

/**
 * La SELECT di lettura di una tabella, costruita dal manifest: i campi sul filo, e per ogni chiave esterna
 * l'uuid della riga puntata (mai il suo id numerico). Una sola fonte: aggiungere una tabella = il manifest.
 */
function sync_select_righe(string $tbl): string {
    $sel = ['t.`' . sync_pk($tbl) . '` AS _id', 't.uuid']; $join = []; $i = 0;
    foreach (SYNC_MANIFESTO[$tbl]['campi'] as $nome => $def) {
        if (isset($def['sql'])) { $sel[] = $def['sql'] . " AS `$nome`"; continue; }
        $col = $def['col'] ?? $nome;
        if ($def['tipo'] === 'fk') {
            $a = 'j' . (++$i);
            // la riga puntata dev'essere dello stesso utente (o, se ammesso, di sistema): niente uuid di righe altrui
            $pa = sync_prop($def['tabella'], $a); $pt = sync_prop($tbl, 't');
            $chi = !empty($def['sistema']) ? "($pa = $pt OR $pa = 0)" : "$pa = $pt";
            $join[] = "LEFT JOIN `{$def['tabella']}` $a ON $a.`" . sync_pk($def['tabella']) . "` = t.`$col` AND $chi";
            $sel[] = "$a.uuid AS `$nome`";
        } else {
            $sel[] = "t.`$col` AS `$nome`";
        }
    }
    return 'SELECT ' . implode(', ', $sel) . ' FROM `' . $tbl . '` t ' . implode(' ', $join);
}

/** Le righe di una tabella, gia' nella forma del filo. $uuids = solo queste; $dopoId/$limite = pagina per id (copia completa). */
function sync_leggi_righe(string $tbl, int $uid, ?array $uuids, int $dopoId = 0, int $limite = 0): array {
    if ($uuids !== null && !$uuids) return [];
    $man = SYNC_MANIFESTO[$tbl];
    $pt = sync_prop($tbl, 't');
    $dove = !empty($man['sistema']) ? "$pt IN (0, ?)" : "$pt = ?"; $par = [$uid];
    if (!empty($man['filtro'])) $dove .= ' AND t.' . $man['filtro'];
    if ($uuids !== null) { $dove .= ' AND t.uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')'; $par = array_merge($par, $uuids); }
    $pk = sync_pk($tbl);
    if ($dopoId > 0) { $dove .= " AND t.`$pk` > ?"; $par[] = $dopoId; }
    $coda = ($limite > 0 ? " ORDER BY t.`$pk` LIMIT " . (int)$limite : '');

    $righe = sec_all(sync_select_righe($tbl) . " WHERE $dove$coda", $par);

    // una voce senza sort_key (appena creata dal sito: il riallineamento parte a fine richiesta) la sistemiamo ora
    static $riprovato = false;
    if (!empty($man['campi']['sort_key'])) {
        foreach ($righe as $r) {
            if ($r['sort_key'] === null && !$riprovato) {
                $riprovato = true;
                foreach (sync_gruppi_toccati($tbl, $uid, null) as $l) sync_riallinea_gruppo($tbl, $uid, (string)$l);
                return sync_leggi_righe($tbl, $uid, $uuids, $dopoId, $limite);
            }
        }
    }

    // righe a chiave naturale nate col default casuale (prima della migrazione, o dal sito): l'uuid vero e' quello calcolato
    if (!empty($man['chiave_naturale'])) {
        foreach ($righe as &$r) {
            // l'uuid atteso si ricava dalla riga stessa (niente query per riga: una copia completa ha migliaia di check-in)
            $atteso = $tbl === 'habit_logs' ? sync_uuid_check_in((string)$r['habit_uuid'], (string)$r['log_date'])
                : ($tbl === 'planner_weeks' ? sync_uuid_settimana($uid, (string)$r['week_start'])
                : ($tbl === 'planner_week_lists' ? sync_uuid_lista_sett($uid, (string)$r['week_start'], (string)$r['list_type'])
                : ($tbl === 'pomodoro_custom_durations' ? sync_uuid_preset($uid, (string)$r['type'], (int)$r['minutes'])
                : ($tbl === 'planner_priorities' ? sync_uuid_priorita($uid, (string)$r['priority_date'], (int)$r['position'])
                : ($tbl === 'habit_daily_journal' ? sync_uuid_diario($uid, (string)$r['entry_date'])
                : ($tbl === 'pill_doses' && $r['plan_uuid'] !== null ? sync_uuid_presa((string)$r['plan_uuid'], (string)$r['due_date'], (string)$r['due_time'], (int)$r['seq']) : null))))));
            if ($atteso !== null && $atteso !== $r['uuid']) {
                mysqli_query(sec_db(), "UPDATE `$tbl` SET uuid = '" . mysqli_real_escape_string(sec_db(), $atteso) . "' WHERE id = " . (int)$r['_id']);
                $r['uuid'] = $atteso;
            }
        }
        unset($r);
    }

    $fuori = [];
    foreach ($righe as $r) {
        $o = ['_id' => (int)$r['_id']];
        foreach ($man['campi'] as $nome => $def) {
            $v = $r[$nome] ?? null;
            if ($v !== null) {
                switch ($def['tipo']) {
                    case 'i': $v = (int)$v; break;
                    case 'n': $v = (float)$v; break;
                    case 'b': $v = ((int)$v) === 1; break;
                    case 'o': $v = substr((string)$v, 0, 5); break;
                    default:  $v = (string)$v;
                }
            }
            $o[$nome] = $v;
        }
        $o['uuid'] = $r['uuid'];
        $fuori[] = $o;
    }
    return $fuori;
}

/** GET /sync/pull?cursor=...&limit=... */
function api_h_sync_pull(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    sec_require_rate('sync_pull', $uid, 600, 60);
    if (!sync_attivo()) api_errore('server_error', 'Sync is not set up on this server (run the sync migrations)', 503);

    $limite = max(1, min(SYNC_PULL_MAX, (int)($_GET['limit'] ?? 500)));
    // «snapshot» (o niente) = copia completa; un numero = «dopo questa voce del registro» (0 e' un cursore valido: registro ancora vuoto)
    $cursore = isset($_GET['cursor']) ? trim((string)$_GET['cursor']) : 'snapshot';
    $tabelle = sync_tabelle();
    $cambi = [];

    // ---- copia completa: telefono nuovo (cursore 0) o rimasto indietro (resync_required) ----
    if ($cursore === '' || $cursore === 'snapshot' || strncmp($cursore, 's:', 2) === 0) {
        if ($cursore === '' || $cursore === 'snapshot') {
            // la testa va letta PRIMA delle righe: tutto cio' che cambia mentre si scarica lo recupera il registro dopo la testa
            $testa = (int)(sec_one("SELECT COALESCE(MAX(seq), 0) AS s FROM sync_changes")['s'] ?? 0);
            $iTab = 0; $ultimoId = 0;
        } elseif (preg_match('/^s:(\d{1,18}):(\d{1,3}):(\d{1,18})$/', $cursore, $m)) {
            $testa = (int)$m[1]; $iTab = (int)$m[2]; $ultimoId = (int)$m[3];
        } else {
            api_errore('invalid_request', 'Invalid cursor');
        }
        $restano = $limite;
        while ($iTab < count($tabelle) && $restano > 0) {
            $righe = sync_leggi_righe($tabelle[$iTab], $uid, null, $ultimoId, $restano);
            foreach ($righe as $r) {
                $id = $r['_id']; unset($r['_id']);
                $cambi[] = ['table' => $tabelle[$iTab], 'op' => 'upsert', 'uuid' => $r['uuid'], 'row' => $r];
                $ultimoId = $id;
            }
            $chiesti = $restano;
            $restano -= count($righe);
            if (count($righe) < $chiesti) {
                // la tabella e' finita (ne sono arrivate meno di quante ne servivano): si passa alla prossima
                $iTab++; $ultimoId = 0;
            }
        }
        $finita = $iTab >= count($tabelle);
        api_ok(['changes' => $cambi, 'cursor' => $finita ? $testa : "s:$testa:$iTab:$ultimoId", 'more' => !$finita,
                'snapshot' => true, 'server_time' => date('Y-m-d H:i:s')]);
    }

    // ---- registro: solo le voci nuove ----
    if (!ctype_digit($cursore) || strlen($cursore) > 18) api_errore('invalid_request', 'Invalid cursor');
    $n = (int)$cursore;
    $pavimento = (int)(sec_one("SELECT v FROM sync_meta WHERE k = 'purged_up_to_seq'")['v'] ?? 0);
    if ($n < $pavimento) {
        // il registro e' stato potato oltre il punto in cui era il telefono: manca qualcosa, si riparte da zero
        api_ok(['resync_required' => true, 'changes' => [], 'cursor' => 'snapshot', 'more' => false]);
    }
    $voci = sec_all("SELECT seq, tbl, row_uuid, op FROM sync_changes WHERE user_id = ? AND seq > ? ORDER BY seq LIMIT " . ($limite + 1),
                    [$uid, $n], 'ii');
    $piu = count($voci) > $limite;
    if ($piu) array_pop($voci);
    $nuovoCursore = $voci ? (int)end($voci)['seq'] : $n;

    // una riga cambiata dieci volte conta una volta: vale l'ultima voce
    $ultime = [];
    foreach ($voci as $v) $ultime[$v['tbl'] . '|' . $v['row_uuid']] = $v;
    $perTabella = [];
    foreach ($ultime as $v) $perTabella[$v['tbl']][] = $v;

    foreach ($tabelle as $tbl) {
        $lista = $perTabella[$tbl] ?? [];
        $daLeggere = [];
        foreach ($lista as $v) if ($v['op'] === 'upsert') $daLeggere[] = $v['row_uuid'];
        $lette = [];
        foreach (array_chunk($daLeggere, 400) as $blocco) {
            foreach (sync_leggi_righe($tbl, $uid, $blocco) as $r) { unset($r['_id']); $lette[$r['uuid']] = $r; }
        }
        foreach ($lista as $v) {
            if ($v['op'] === 'delete') {
                $cambi[] = ['table' => $tbl, 'op' => 'delete', 'uuid' => $v['row_uuid']];
            } elseif (isset($lette[$v['row_uuid']])) {
                $cambi[] = ['table' => $tbl, 'op' => 'upsert', 'uuid' => $v['row_uuid'], 'row' => $lette[$v['row_uuid']]];
            }
            // upsert di una riga che non c'e' piu' (o fuori filtro): niente. Se e' stata tolta, la sua voce «delete» e' piu' avanti.
        }
    }
    api_ok(['changes' => $cambi, 'cursor' => $nuovoCursore, 'more' => $piu, 'server_time' => date('Y-m-d H:i:s')]);
}

/** GET /sync/state */
function api_h_sync_stato(array $ctx): void {
    if (!sync_attivo()) api_errore('server_error', 'Sync is not set up on this server (run the sync migrations)', 503);
    $testa = (int)(sec_one("SELECT COALESCE(MAX(seq), 0) AS s FROM sync_changes")['s'] ?? 0);
    $pav = (int)(sec_one("SELECT v FROM sync_meta WHERE k = 'purged_up_to_seq'")['v'] ?? 0);
    api_ok(['head' => $testa, 'purged_up_to_seq' => $pav, 'tables' => sync_tabelle(),
            'push_max' => SYNC_PUSH_MAX, 'pull_max' => SYNC_PULL_MAX, 'server_time' => date('Y-m-d H:i:s')]);
}

/* ---------------------------------------------------------------------
   PUSH: dal filo alle funzioni di sempre
   --------------------------------------------------------------------- */

function sync_rifiuto(string $codice, string $campo = '', string $testo = ''): array {
    return ['status' => 'rejected', 'code' => $codice, 'field' => $campo, 'message' => $testo];
}

/** Un errore delle funzioni td_api_* / sp_*: il campo torna col nome del filo. */
function sync_da_errore(array $esito, array $nomi): array {
    $e = $esito['errore'];
    $campo = $nomi[$e['campo']] ?? (string)$e['campo'];
    return sync_rifiuto((string)$e['codice'], $campo, (string)$e['testo']);
}

/** L'id numerico di una riga dell'utente a partire dall'uuid, o null. */
function sync_id_da_uuid(string $tbl, int $uid, string $uuid): ?int {
    $r = sec_one("SELECT t.`" . sync_pk($tbl) . "` AS id FROM `$tbl` t WHERE " . sync_prop($tbl, 't') . " = ? AND t.uuid = ?", [$uid, $uuid]);
    return $r ? (int)$r['id'] : null;
}

/** sort_key del patch: controlla e salva (e allinea sort_order, che il sito usa ancora). */
function sync_applica_sort_key(string $tbl, int $uid, int $id, string $chiave): void {
    sec_q("UPDATE `$tbl` SET sort_key = ? WHERE id = ? AND user_id = ?", [$chiave, $id, $uid]);
    $lista = '';
    if (!empty(SYNC_MANIFESTO[$tbl]['gruppo_padre'])) {
        $c = SYNC_MANIFESTO[$tbl]['gruppo_padre'];
        $r = sec_one("SELECT `$c` AS g FROM `$tbl` WHERE id = ?", [$id]) ?: [];
        $lista = 'p:' . ($r['g'] ?? '');
    } elseif (in_array('list_type', SYNC_MANIFESTO[$tbl]['gruppo'], true)) {
        $r = sec_one("SELECT list_type FROM `$tbl` WHERE id = ?", [$id]) ?: [];
        $lista = (string)($r['list_type'] ?? '');
    }
    sync_ordine_dal_sort_key($tbl, $uid, $lista, $id);
}

/** Un patch controllato contro il manifest: ritorna [campi validi, errore o null]. */
function sync_controlla_chiavi(string $tbl, array $patch, bool $creazione): ?array {
    $campi = SYNC_MANIFESTO[$tbl]['campi'];
    foreach ($patch as $k => $v) {
        if (!is_string($k) || !isset($campi[$k])) return sync_rifiuto('validation', (string)$k, 'Unknown field');
    }
    return null;
}

/* ---- to buy: categorie ---- */
function sync_cat_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('todo_buy_categories', $uid, $uuid);
    if ($op === 'delete') {
        if ($id === null) return ['status' => 'applied'];   // gia' tolta: rimandare non fa danni
        sp_cat_elimina($uid, $id);
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('todo_buy_categories', $patch, $id === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');
    $api = [];
    foreach (['name' => 'name', 'color' => 'color'] as $filo => $apiNome) if (array_key_exists($filo, $patch)) $api[$apiNome] = $patch[$filo];
    $nomi = ['name' => 'name', 'color' => 'color', 'body' => 'body'];

    if ($id === null) {
        if (sec_one("SELECT id FROM todo_buy_categories WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'todo_buy_categories', 'uuid' => $uuid];
        $r = sp_cat_crea($uid, $api);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        if (isset($r['errore'])) return sync_da_errore($r, $nomi);
        $id = sync_id_da_uuid('todo_buy_categories', $uid, $uuid);
    } elseif ($api) {
        $r = sp_cat_modifica($uid, $id, $api);
        if (isset($r['errore'])) return sync_da_errore($r, $nomi);
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('todo_buy_categories', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---- to do e to buy ---- */
function sync_todos_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT id, list_type FROM todos WHERE user_id = ? AND uuid = ? AND list_type IN ('todo','buy')", [$uid, $uuid]);
    if ($op === 'delete') {
        if (!$riga) return ['status' => 'applied'];
        if ($riga['list_type'] === 'buy') sp_elimina($uid, (int)$riga['id']); else td_api_elimina($uid, (int)$riga['id']);
        return ['status' => 'applied'];
    }
    if ($er = sync_controlla_chiavi('todos', $patch, $riga === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    // dal nome sul filo al nome che le funzioni di sempre capiscono (e ritorno, per gli errori)
    $ritorno = ['title' => 'title', 'date' => 'due_date', 'time' => 'due_time', 'minutes' => 'duration_minutes', 'done' => 'is_done',
                'parent_id' => 'parent_uuid', 'category_id' => 'buy_category_uuid', 'priority' => 'buy_priority',
                'price_cents' => 'buy_price_cents', 'url' => 'buy_url'];
    $api = [];
    foreach (['title' => 'title', 'due_date' => 'date', 'due_time' => 'time', 'duration_minutes' => 'minutes', 'is_done' => 'done',
              'buy_priority' => 'priority', 'buy_price_cents' => 'price_cents', 'buy_url' => 'url'] as $filo => $nome) {
        if (array_key_exists($filo, $patch)) $api[$nome] = $patch[$filo];
    }
    if (array_key_exists('buy_category_uuid', $patch)) {
        $cu = $patch['buy_category_uuid'];
        if ($cu === null) $api['category_id'] = null;
        elseif (!sync_uuid_valido($cu)) return sync_rifiuto('validation', 'buy_category_uuid', 'Invalid uuid');
        else {
            $cid = sync_id_da_uuid('todo_buy_categories', $uid, $cu);
            if ($cid === null) return sync_rifiuto('not_found', 'buy_category_uuid', 'Category not found');
            $api['category_id'] = $cid;
        }
    }
    $lista = $riga ? $riga['list_type'] : (($patch['list_type'] ?? 'todo'));
    if (!in_array($lista, ['todo', 'buy'], true)) return sync_rifiuto('validation', 'list_type', 'list_type must be todo or buy');
    if ($riga && isset($patch['list_type']) && $patch['list_type'] !== $riga['list_type']) {
        return sync_rifiuto('validation', 'list_type', 'list_type cannot change');
    }

    if (!$riga) {
        if (sec_one("SELECT id FROM todos WHERE uuid = ?", [$uuid])) return sync_rifiuto('forbidden', 'uuid', 'This id is already used');
        if ($lista === 'todo' && !empty($patch['parent_uuid'])) {
            if (!sync_uuid_valido($patch['parent_uuid'])) return sync_rifiuto('validation', 'parent_uuid', 'Invalid uuid');
            $pid = sync_id_da_uuid('todos', $uid, $patch['parent_uuid']);
            if ($pid === null) return sync_rifiuto('not_found', 'parent_uuid', 'Parent task not found');
            $api['parent_id'] = $pid;
        }
        $GLOBALS['SYNC_UUID_NUOVO'] = ['tbl' => 'todos', 'uuid' => $uuid];
        $r = $lista === 'buy' ? sp_crea($uid, $api) : td_api_crea($uid, $api);
        unset($GLOBALS['SYNC_UUID_NUOVO']);
        if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
        $id = sync_id_da_uuid('todos', $uid, $uuid);
    } else {
        $id = (int)$riga['id'];
        if ($api) {
            $r = $lista === 'buy' ? sp_modifica($uid, $id, $api) : td_api_modifica($uid, $id, $api);
            if (isset($r['errore'])) return sync_da_errore($r, $ritorno);
        }
    }
    if ($id !== null && isset($patch['sort_key'])) sync_applica_sort_key('todos', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/** Una modifica: la smista alla tabella giusta, dentro una transazione. */
function sync_applica(int $uid, array $m): array {
    $tbl = $m['table'];
    $conn = sec_db();
    mysqli_begin_transaction($conn);
    try {
        switch ($tbl) {
            case 'todos':             $esito = sync_todos_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'todo_buy_categories': $esito = sync_cat_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'habit_categories':  $esito = sync_hcat_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'habits':            $esito = sync_habits_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'habit_logs':        $esito = sync_hlogs_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'pill_plans':        $esito = sync_pplans_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'pill_doses':        $esito = sync_pdoses_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'habit_daily_journal': $esito = sync_hjournal_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'diary_list_items':  $esito = sync_dlist_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_calendars': $esito = sync_pcal_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_categories': $esito = sync_pcat_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_category_keywords': $esito = sync_pkey_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_weeks':     $esito = sync_pweek_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_week_lists': $esito = sync_pwlist_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_week_list_items': $esito = sync_pwitem_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_priorities': $esito = sync_pprio_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_routines':  $esito = sync_prout_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_routine_items': $esito = sync_pritem_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'planner_events':    $esito = sync_pevents_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'projects':          $esito = sync_prog_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'pomodoro_sessions': $esito = sync_pomo_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'university_exam_groups': $esito = sync_uni_gruppi_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'university_exams':  $esito = sync_uni_esami_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'university_bonuses': $esito = sync_uni_bonus_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'fc_study_decks':    $esito = sync_fc_mazzi_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'fc_study_cards':    $esito = sync_fc_carte_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'fc_study_subcards': $esito = sync_fc_figlie_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            case 'pomodoro_custom_durations': $esito = sync_pomo_preset_applica($uid, $m['uuid'], $m['op'], $m['patch']); break;
            default:                  $esito = sync_rifiuto('validation', 'table', 'Unknown table');
        }
        // una modifica rifiutata non deve lasciare mezze scritture
        if ($esito['status'] === 'applied') mysqli_commit($conn); else mysqli_rollback($conn);
        return $esito;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

/** POST /sync/push {mutations:[{mutation_id, table, op, uuid, patch, base_updated_at?}]} */
function api_h_sync_push(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    sec_require_rate('sync_push', $uid, 300, 60);
    if (!sync_attivo()) api_errore('server_error', 'Sync is not set up on this server (run the sync migrations)', 503);
    $corpo = api_corpo();
    $mut = $corpo['mutations'] ?? null;
    if (!is_array($mut) || !array_is_list($mut)) api_errore('validation', 'mutations must be a list', null, ['field' => 'mutations']);
    if (count($mut) > SYNC_PUSH_MAX) api_errore('validation', 'At most ' . SYNC_PUSH_MAX . ' mutations per push', null, ['field' => 'mutations']);
    $GLOBALS['SYNC_DEVICE_ID'] = (int)$ctx['sessione_id'];

    $esiti = [];
    foreach ($mut as $m) {
        $mid = is_array($m) ? ($m['mutation_id'] ?? null) : null;
        if (!sync_uuid_valido($mid)) { $esiti[] = ['mutation_id' => is_string($mid) ? substr($mid, 0, 36) : null] + sync_rifiuto('validation', 'mutation_id', 'Invalid mutation_id'); continue; }

        // già vista: la stessa risposta di allora, niente di nuovo
        $vista = sec_one("SELECT result FROM sync_mutations WHERE user_id = ? AND mutation_id = ?", [$uid, $mid]);
        if ($vista) {
            $ricordato = json_decode($vista['result'], true) ?: ['status' => 'applied'];
            $tb = $m['table'] ?? null; $ug = $m['uuid'] ?? null;
            if (($ricordato['status'] ?? '') === 'rejected' && ($m['op'] ?? '') === 'upsert' && is_string($tb) && isset(SYNC_MANIFESTO[$tb]) && sync_uuid_valido($ug)) {
                $att = sync_leggi_righe($tb, $uid, [$ug]);
                if ($att) { unset($att[0]['_id']); $ricordato['current'] = $att[0]; } else { $ricordato['current'] = null; }
            }
            $esiti[] = ['mutation_id' => $mid, 'replayed' => true] + $ricordato;
            continue;
        }

        $tbl = $m['table'] ?? null; $op = $m['op'] ?? null; $uuid = $m['uuid'] ?? null; $patch = $m['patch'] ?? [];
        if (!is_string($tbl) || !isset(SYNC_MANIFESTO[$tbl])) $esito = sync_rifiuto('validation', 'table', 'Unknown table');
        elseif ($op !== 'upsert' && $op !== 'delete') $esito = sync_rifiuto('validation', 'op', 'op must be upsert or delete');
        elseif (!sync_uuid_valido($uuid)) $esito = sync_rifiuto('validation', 'uuid', 'Invalid uuid');
        elseif (!is_array($patch) || ($patch && array_is_list($patch))) $esito = sync_rifiuto('validation', 'patch', 'patch must be an object');
        else {
            try {
                $esito = sync_applica($uid, ['table' => $tbl, 'op' => $op, 'uuid' => $uuid, 'patch' => $patch]);
            } catch (Throwable $e) {
                // errore nostro, non del telefono: NON si ricorda, cosi' il telefono riprova
                sec_log_error('sync_push', get_class($e) . ' ' . $e->getMessage());
                $esiti[] = ['mutation_id' => $mid, 'status' => 'error', 'code' => 'server_error'];
                continue;
            }
        }
        sec_q("INSERT IGNORE INTO sync_mutations (user_id, mutation_id, result) VALUES (?, ?, ?)", [$uid, $mid, substr(json_encode($esito), 0, 255)]);
        // una modifica rifiutata dice al telefono com'e' la riga SUL SERVER (null = non esiste): il telefono si rimette a posto.
        // Non si salva in sync_mutations (potrebbe cambiare): se la modifica viene rimandata, il telefono riceve lo stato di allora.
        if ($esito['status'] === 'rejected' && ($op === 'upsert') && is_string($tbl) && isset(SYNC_MANIFESTO[$tbl]) && sync_uuid_valido($uuid)) {
            $att = sync_leggi_righe($tbl, $uid, [$uuid]);
            if ($att) { unset($att[0]['_id']); $esito['current'] = $att[0]; } else { $esito['current'] = null; }
        }
        $esiti[] = ['mutation_id' => $mid] + $esito;
    }

    if (mt_rand(1, 100) === 1) sync_pota();   // pulizia a caso: l'hosting condiviso potrebbe non avere il cron
    $testa = (int)(sec_one("SELECT COALESCE(MAX(seq), 0) AS s FROM sync_changes")['s'] ?? 0);
    api_ok(['results' => $esiti, 'head' => $testa]);
}

require_once __DIR__ . '/sync_abitudini.php';
require_once __DIR__ . '/sync_diario.php';
require_once __DIR__ . '/sync_pastiglie.php';   // dopo le funzioni di base che usa (sync_rifiuto, sync_id_da_uuid...)
require_once __DIR__ . '/sync_planner.php';
require_once __DIR__ . '/sync_progetti.php';
require_once __DIR__ . '/sync_voti.php';
require_once __DIR__ . '/sync_flashcard.php';
