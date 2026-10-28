<?php
/* =====================================================================
   STANDOUT — AZIONI SU PIU' VOCI E RIORDINO, per /api/v1/tasks e /api/v1/shopping
   (PROGETTO §15, passo 9, fetta 5)

   Sono le azioni «bulk» e «reorder» della pagina to-do (todo_api.php), con una
   differenza voluta: la pagina scarta in silenzio quello che non capisce, l'app
   riceve un errore col nome del campo (422) e l'elenco degli id che non ha trovato.

   Come sempre: ogni riga si tocca solo con `user_id = ?` nella WHERE, e solo se e'
   dell'elenco giusto (to do per /tasks, to buy per /shopping): un id dell'altro
   elenco o di un altro utente si comporta come se non esistesse.
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/todo_core.php';
require_once __DIR__ . '/shopping_api.php';

const TD_BULK_MAX = 200;      // come la pagina
const TD_ORDINE_MAX = 300;    // come la pagina

/**
 * Gli id nel corpo: una lista non vuota di interi positivi, senza doppioni.
 * Ritorna ['ids' => [...]] oppure un errore.
 */
function td_bulk_ids(array $c, int $massimo): array {
    $ids = $c['ids'] ?? null;
    if (!is_array($ids) || !$ids || !array_is_list($ids)) return td_api_errore('validation', 'ids', 'ids must be a non-empty list of ids');
    if (count($ids) > $massimo) return td_api_errore('validation', 'ids', 'ids can have at most ' . $massimo . ' entries');
    foreach ($ids as $i) if (!is_int($i) || $i < 1) return td_api_errore('validation', 'ids', 'ids must be whole numbers');
    return ['ids' => array_values(array_unique($ids))];
}

/** Quali degli id sono righe dell'utente in quell'elenco ('todo' o 'buy'). Ritorna [trovati, mancanti]. */
function td_bulk_trovati(int $uid, string $tipo, array $ids): array {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $righe = sec_all("SELECT id FROM todos WHERE user_id = ? AND list_type = ? AND id IN ($ph)", array_merge([$uid, $tipo], $ids), 'is' . str_repeat('i', count($ids)));
    $ok = array_map(fn($r) => (int)$r['id'], $righe);
    return [$ok, array_values(array_diff($ids, $ok))];
}

/**
 * Un'azione su piu' voci. $tipo: 'todo' o 'buy'. Corpo: {ids, action, ...}.
 *   delete    toglie le voci (con sotto-attivita', blocco sul planner e immagine)
 *   schedule  {date?, time?, minutes?}: cambia SOLO le chiavi presenti (date: null = senza data)
 *   project   {project_id}: null toglie il progetto
 *   set       (solo to buy) {category_id?, priority?}
 *   move      sposta nell'altro elenco (to do -> to buy, to buy -> to do)
 * Ritorna ['count' => n, 'skipped' => [id non trovati]] oppure un errore.
 */
function td_bulk(int $uid, string $tipo, array $c): array {
    $v = td_bulk_ids($c, TD_BULK_MAX);
    if (isset($v['errore'])) return $v;
    $azioni = $tipo === 'buy' ? ['delete', 'schedule', 'project', 'set', 'move'] : ['delete', 'schedule', 'project', 'move'];
    $azione = $c['action'] ?? null;
    if (!is_string($azione) || !in_array($azione, $azioni, true)) {
        return td_api_errore('validation', 'action', 'action must be one of: ' . implode(', ', $azioni));
    }

    // si controllano i valori PRIMA di toccare qualsiasi riga: o va tutto o non cambia niente
    $campi = [];
    if ($azione === 'schedule') {
        $chiavi = array_intersect_key($c, array_flip(['date', 'time', 'minutes']));
        if (!$chiavi) return td_api_errore('validation', 'date', 'schedule needs at least one of date, time, minutes');
        $r = td_api_valida($uid, $chiavi, false);
        if (isset($r['errore'])) return $r;
        $campi = $r['campi'];
    } elseif ($azione === 'project') {
        if (!array_key_exists('project_id', $c)) return td_api_errore('validation', 'project_id', 'project_id is required (null removes the project)');
        $r = td_api_valida($uid, ['project_id' => $c['project_id']], false);
        if (isset($r['errore'])) return $r;
        $campi = $r['campi'];
    } elseif ($azione === 'set') {
        $chiavi = array_intersect_key($c, array_flip(['category_id', 'priority']));
        if (!$chiavi) return td_api_errore('validation', 'category_id', 'set needs at least one of category_id, priority');
        $r = sp_valida($uid, $chiavi, false);
        if (isset($r['errore'])) return $r;
        $campi = $r['campi'];
    }

    [$trovati, $mancanti] = td_bulk_trovati($uid, $tipo, $v['ids']);
    if (!$trovati) return td_api_errore('not_found', 'ids', 'None of the ids was found');

    if ($azione === 'delete') {
        foreach ($trovati as $id) {
            $t = sec_one("SELECT planner_event_id, buy_image FROM todos WHERE id=? AND user_id=?", [$id, $uid], 'ii');
            if ($t && !empty($t['planner_event_id'])) sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$t['planner_event_id'], $uid], 'ii');
            if ($t) td_drop_image($t['buy_image'] ?? null);
            // anche le sotto-attivita' (il to buy non ne ha: la condizione non trova niente)
            $figli = sec_all("SELECT id, planner_event_id, buy_image FROM todos WHERE user_id=? AND parent_id=?", [$uid, $id], 'ii');
            foreach ($figli as $f) {
                if (!empty($f['planner_event_id'])) sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$f['planner_event_id'], $uid], 'ii');
                td_drop_image($f['buy_image'] ?? null);
            }
            sec_q("DELETE FROM todos WHERE user_id=? AND (id=? OR parent_id=?)", [$uid, $id, $id], 'iii');
        }
    } elseif ($azione === 'move') {
        $nuovo = $tipo === 'buy' ? 'todo' : 'buy';
        foreach ($trovati as $id) {
            sec_q("UPDATE todos SET list_type=? WHERE user_id=? AND (id=? OR parent_id=?)", [$nuovo, $uid, $id, $id], 'siii');
        }
    } else {
        /* I nomi delle colonne vengono SOLO da td_api_valida / sp_valida (elenco fisso), mai dal client. */
        $set = []; $par = [];
        foreach ($campi as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
        foreach ($trovati as $id) {
            sec_q("UPDATE todos SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?", array_merge($par, [$id, $uid]));
            if ($azione !== 'set') td_sync_planner($uid, $id);
        }
        // categoria e prezzo cambiati: la memoria del to buy si aggiorna (come per una modifica singola)
        if ($azione === 'set' && array_key_exists('buy_category_id', $campi)) {
            foreach ($trovati as $id) td_mem_ricorda($uid, $id, false);
        }
    }
    return ['count' => count($trovati), 'skipped' => $mancanti];
}

/**
 * Il nuovo ordine di un elenco: gli id nell'ordine in cui devono comparire.
 * Come la pagina, le voci non nominate restano dove sono (si rinumera solo chi e' nella lista).
 * Ritorna ['count' => n, 'skipped' => [...]] oppure un errore.
 */
function td_riordina(int $uid, string $tipo, array $c): array {
    $v = td_bulk_ids($c, TD_ORDINE_MAX);
    if (isset($v['errore'])) return $v;
    [$trovati, $mancanti] = td_bulk_trovati($uid, $tipo, $v['ids']);
    $n = 1;
    foreach ($v['ids'] as $id) {
        if (!in_array($id, $trovati, true)) continue;
        sec_q("UPDATE todos SET sort_order=? WHERE id=? AND user_id=?", [$n++, $id, $uid], 'iii');
    }
    return ['count' => count($trovati), 'skipped' => $mancanti];
}
