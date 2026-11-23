<?php
/* =====================================================================
   STANDOUT — sync: il Tracker dei progetti (tabella tracker_blocks)

   Un tracker e' l'insieme dei blocchi di un progetto (griglia con somme, contatore, barra, spunte, nota,
   ripasso a intervalli, collegamento a un sito). Ogni blocco e' una riga: tipo, titolo, `config` (come e'
   fatto) e `data` (cosa c'e' dentro), gli ultimi due JSON in forma di testo. Vince l'ultima modifica di
   ogni blocco. Il sito e il telefono scrivono la stessa riga: l'identita' e' l'uuid scelto da chi la crea.
   Qui si controllano solo forma e limiti; il significato di config e data lo conoscono i due client.
   ===================================================================== */

const SYNC_TRK_KINDS = ['table', 'counter', 'progress', 'chart', 'checklist', 'note', 'review', 'connection'];
const SYNC_TRK_MAX_BLOCCHI = 20;
const SYNC_TRK_MAX_BYTE = 1048576;   // un blocco: al massimo 1 MB di JSON (config e data presi da soli)

/** config / data del patch: testo JSON che e' un oggetto, non piu' grande del limite. Ritorna [testo|null, errore|null]. */
function sync_trk_json(array $patch, string $campo): array {
    if (!array_key_exists($campo, $patch)) return [null, null];
    $v = $patch[$campo];
    if (!is_string($v) || strlen($v) > SYNC_TRK_MAX_BYTE) return [null, sync_rifiuto('validation', $campo, "$campo must be a JSON object text up to 1 MB")];
    $d = json_decode($v, true);
    if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE || ($d !== [] && array_is_list($d))) {
        return [null, sync_rifiuto('validation', $campo, "$campo must be a JSON object")];
    }
    return [$v, null];
}

function sync_trk_applica(int $uid, string $uuid, string $op, array $patch): array {
    $id = sync_id_da_uuid('tracker_blocks', $uid, $uuid);
    if ($op === 'delete') {
        if ($id !== null) sec_q("DELETE FROM tracker_blocks WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('tracker_blocks', $patch, $id === null)) return $er;

    $titolo = null;
    if (array_key_exists('title', $patch)) {
        $titolo = is_string($patch['title']) ? trim($patch['title']) : null;
        if ($titolo === null || mb_strlen($titolo) > 120) return sync_rifiuto('validation', 'title', 'title must be up to 120 characters');
    }
    $sort = null;
    if (isset($patch['sort_key'])) {
        if (!sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');
        $sort = $patch['sort_key'];
    }
    [$config, $er] = sync_trk_json($patch, 'config'); if ($er) return $er;
    [$dati, $er]   = sync_trk_json($patch, 'data');   if ($er) return $er;

    if ($id !== null) {
        if ($titolo !== null) sec_q("UPDATE tracker_blocks SET title = ? WHERE id = ? AND user_id = ?", [$titolo, $id, $uid], 'sii');
        if ($config !== null) sec_q("UPDATE tracker_blocks SET config = ? WHERE id = ? AND user_id = ?", [$config, $id, $uid], 'sii');
        if ($dati !== null)   sec_q("UPDATE tracker_blocks SET data = ? WHERE id = ? AND user_id = ?", [$dati, $id, $uid], 'sii');
        if ($sort !== null)   sec_q("UPDATE tracker_blocks SET sort_key = ? WHERE id = ? AND user_id = ?", [$sort, $id, $uid], 'sii');
        return ['status' => 'applied'];
    }

    // nascita: servono progetto, tipo, config e data
    $kind = $patch['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, SYNC_TRK_KINDS, true)) return sync_rifiuto('validation', 'kind', 'kind must be one of: ' . implode(', ', SYNC_TRK_KINDS));
    if ($config === null || $dati === null) return sync_rifiuto('validation', 'config', 'config and data are required');
    [$pid, $er] = sync_fc_risolvi('projects', $uid, $patch['project_uuid'] ?? null, 'project_uuid', 'Project');
    if ($er) return $er;
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM tracker_blocks WHERE user_id = ? AND project_id = ?", [$uid, $pid])['n'] ?? 0);
    if ($n >= SYNC_TRK_MAX_BLOCCHI) return sync_rifiuto('limit', 'project_uuid', 'A tracker has at most ' . SYNC_TRK_MAX_BLOCCHI . ' blocks');
    if ($kind === 'connection' && sec_one("SELECT 1 AS x FROM tracker_blocks WHERE user_id = ? AND project_id = ? AND kind = 'connection'", [$uid, $pid])) {
        return sync_rifiuto('validation', 'kind', 'A tracker has only one connection');
    }
    if ($er = sync_fc2_inserisci('tracker_blocks', $uuid,
        "INSERT INTO tracker_blocks (user_id, project_id, kind, title, config, data, sort_key) VALUES (?,?,?,?,?,?,?)",
        [$uid, $pid, $kind, $titolo ?? '', $config, $dati, $sort])) return $er;
    return ['status' => 'applied'];
}
