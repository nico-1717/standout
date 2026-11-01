<?php
/* =====================================================================
   STANDOUT — il registro dei cambiamenti per l'app (sync_changes)

   COSA FA: ogni volta che QUALUNQUE parte del server (il sito, l'API, l'app)
   scrive in una tabella sincronizzata, qui si annota «la riga X e' cambiata /
   e' stata tolta». I telefoni leggono questo registro (GET /sync/pull) per
   sapere cosa e' successo mentre erano spenti.

   PERCHE' UN PUNTO SOLO (decisione D8): il sito scrive nelle tabelle da piu'
   di cinquanta punti diversi solo per il to do, e ne mancherebbero centinaia
   per il resto. Una chiamata a mano in ognuno vuol dire che prima o poi se ne
   dimentica una, e una modifica non annotata e' una modifica che l'app NON vede
   mai. Tutto il SQL del sito passa da sec_q(): li' c'e' un gancio solo
   (sync_osserva_prima / sync_osserva_dopo). Niente trigger nel database: il
   codice resta leggibile e sul server di prova si vede cosa fa.

   COME RICONOSCE LE RIGHE:
     INSERT  dopo la scrittura: gli id nuovi sono insert_id ... insert_id+righe-1.
     UPDATE  PRIMA della scrittura si leggono gli uuid con la stessa FROM e la
             stessa WHERE (i parametri della WHERE sono gli ultimi), perche' dopo
             la modifica la WHERE potrebbe non valere piu'.
     DELETE  PRIMA della scrittura si leggono gli uuid (dopo non ci sono piu').
   Si annota solo se la scrittura riesce. Se l'annotazione fallisce NON si rompe
   la scrittura del sito: l'errore va nel log e basta (sec_log_error).

   COSA NON FA: non guarda le colonne. Un UPDATE che non cambia nulla annota lo
   stesso (l'app riceve la riga uguale: innocuo). I campi di servizio che il
   sito scrive senza voler «cambiare la riga» (es. google_synced_at del
   planner) si gestiranno quando quella tabella entrera' nel manifest.

   SICUREZZA: nessun valore dell'utente finisce nel SQL del registro (solo
   id e uuid letti dal database). I nomi di tabella vengono dal manifest.
   ===================================================================== */

require_once __DIR__ . '/sync_manifest.php';
require_once __DIR__ . '/sync_chiavi.php';

/** Il registro e' pronto? (tabelle create dalla migrazione 01). Una volta per richiesta. */
function sync_attivo(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $r = mysqli_query(sec_db(), "SHOW TABLES LIKE 'sync_changes'");
        $ok = $r && mysqli_num_rows($r) > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Spegne il registro per il resto della richiesta (colonne mancanti: migrazione non fatta). */
function sync_spegni(string $perche): void {
    sec_log_error('sync', $perche);
    // nelle prove (STANDOUT_SYNC_STRICT=1) un'analisi fallita e' un errore vero: una modifica non annotata non deve passare in silenzio
    if (getenv('STANDOUT_SYNC_STRICT') === '1') throw new RuntimeException('sync: ' . $perche);
    $GLOBALS['SYNC_SPENTO'] = true;
}

/* ---------- analisi del SQL (sono tutte query scritte da noi, non dall'utente) ---------- */

/** Posizione della prima occorrenza di $parola (maiuscola, con spazi intorno) fuori da parentesi e apici. -1 = non c'e'. */
function sync_trova_parola(string $sql, string $parola): int {
    $n = strlen($sql); $prof = 0; $apice = '';
    $len = strlen($parola);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($apice !== '') {
            if ($c === '\\') { $i++; continue; }
            if ($c === $apice) $apice = '';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $apice = $c; continue; }
        if ($c === '(') { $prof++; continue; }
        if ($c === ')') { $prof--; continue; }
        if ($prof === 0 && ($c === ' ' || $c === "\n" || $c === "\t")
            && strncasecmp(substr($sql, $i + 1, $len), $parola, $len) === 0
            && preg_match('/\s/', $sql[$i + 1 + $len] ?? ' ')) {
            return $i + 1;
        }
    }
    return -1;
}

/** Quanti segnaposto «?» ci sono nel testo (le query nostre non hanno «?» dentro gli apici). */
function sync_segnaposto(string $testo): int { return substr_count($testo, '?'); }

/**
 * Legge una query di scrittura e dice cosa serve per annotarla: il verbo (ins/upd/del),
 * la tabella e, per UPDATE e DELETE, la SELECT che legge gli uuid PRIMA della scrittura
 * (con i suoi parametri). null = la query non riguarda una tabella sincronizzata.
 * Separata da sync_osserva_prima() perche' tools/controlla_sync.php la prova su
 * tutte le query vere del sito, senza eseguirle.
 */
function sync_analizza(string $sql, array $params): ?array {
    $s = ltrim($sql);
    $p = $s[0] ?? '';
    if ($p !== 'I' && $p !== 'U' && $p !== 'D' && $p !== 'R' && $p !== 'i' && $p !== 'u' && $p !== 'd' && $p !== 'r') return null;

    if (preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?`?(\w+)`?/i', $s, $m)) { $verbo = 'ins'; }
    elseif (preg_match('/^UPDATE\s+(?:IGNORE\s+)?`?(\w+)`?/i', $s, $m))                    { $verbo = 'upd'; }
    elseif (preg_match('/^DELETE\s+FROM\s+`?(\w+)`?/i', $s, $m))                           { $verbo = 'del'; }
    else return null;

    $tbl = strtolower($m[1]);
    if (!isset(SYNC_MANIFESTO[$tbl])) return null;
    $pro = ['verbo' => $verbo, 'tbl' => $tbl, 'select' => null, 'par' => [], 'set' => ''];
    if ($verbo === 'ins') return $pro;

    if ($verbo === 'del') {
        // DELETE FROM tabella [alias] WHERE ...
        $w = sync_trova_parola($s, 'WHERE');
        $dopoTab = strlen($m[0]);
        $testa = $w >= 0 ? substr($s, $dopoTab, $w - $dopoTab) : substr($s, $dopoTab);
        $alias = $tbl;
        if (preg_match('/(\w+)\s*$/', trim($testa), $am2)) $alias = $am2[1];
        $where = $w >= 0 ? substr($s, $w + 5) : '1=1';
        $par = $w >= 0 ? $params : [];
        $da = "`$tbl`" . (trim($testa) !== '' ? ' ' . trim($testa) : '');
    } else {
        // UPDATE <da> SET <set> WHERE <where>   (<da> puo' avere alias e JOIN)
        $set = sync_trova_parola($s, 'SET');
        if ($set < 0) return null;
        $w = sync_trova_parola($s, 'WHERE');
        preg_match('/^UPDATE\s+(?:IGNORE\s+)?/i', $s, $pre);
        $da = trim(substr($s, strlen($pre[0]), $set - strlen($pre[0])));   // tutto fra UPDATE e SET (con i suoi apici)
        $alias = $tbl;
        if (preg_match('/^`?' . preg_quote($m[1], '/') . '`?(?:\s+(?:AS\s+)?(?!JOIN\b|LEFT\b|INNER\b|RIGHT\b|CROSS\b)`?(\w+)`?)?/i', $da, $am) && !empty($am[1])) $alias = $am[1];
        $setTesto = $w >= 0 ? substr($s, $set + 3, $w - $set - 3) : substr($s, $set + 3);
        $where = $w >= 0 ? substr($s, $w + 5) : '1=1';
        // i segnaposto stanno nell'ordine FROM/JOIN, SET, WHERE: quelli della WHERE sono gli ultimi
        $par = $w >= 0 ? array_slice($params, sync_segnaposto($da) + sync_segnaposto($setTesto)) : [];
        $pro['set'] = $setTesto;
    }
    $col = SYNC_MANIFESTO[$tbl]['proprietario'];
    // se la query ha gia' un suo LIMIT (es. UPDATE ... LIMIT 1) vale quello; altrimenti un tetto di sicurezza
    $coda = preg_match('/\sLIMIT\s/i', $where) ? '' : ' LIMIT 20000';
    $pro['select'] = "SELECT `$alias`.`$col` AS u, `$alias`.uuid AS g FROM " . $da . " WHERE " . $where . $coda;
    $pro['par'] = $par;
    return $pro;
}

/**
 * Prima della scrittura. Ritorna null se la query non ci riguarda, altrimenti
 * un «promemoria» da passare a sync_osserva_dopo().
 */
function sync_osserva_prima(string $sql, array $params): ?array {
    if (!empty($GLOBALS['SYNC_SPENTO'])) return null;
    try {
        $pro = sync_analizza($sql, $params);
        if ($pro === null || !sync_attivo()) return null;
        $pro['righe'] = [];
        if ($pro['select'] !== null) $pro['righe'] = sec_all($pro['select'], $pro['par']);
        return $pro;
    } catch (Throwable $e) {
        sync_spegni('analisi SQL fallita: ' . $e->getMessage() . ' | ' . substr($sql, 0, 120));
        return null;
    }
}

/** Dopo la scrittura riuscita. */
function sync_osserva_dopo(array $pro, int $righeToccate, int $idInserito): void {
    try {
        $tbl = $pro['tbl'];
        $voci = [];
        if ($pro['verbo'] === 'ins') {
            if ($righeToccate <= 0 || $idInserito <= 0) return;
            $a = $idInserito; $b = $idInserito + $righeToccate - 1;
            $col = SYNC_MANIFESTO[$tbl]['proprietario'];
            $nuove = sec_all("SELECT `$col` AS u, uuid AS g, id FROM `$tbl` WHERE id BETWEEN ? AND ?", [$a, $b]);
            // l'app ha scelto lei l'uuid di una riga nuova: lo si applica PRIMA di annotare
            $voluto = $GLOBALS['SYNC_UUID_NUOVO'] ?? null;
            if ($voluto && $voluto['tbl'] === $tbl && count($nuove) === 1) {
                mysqli_query(sec_db(), "UPDATE `$tbl` SET uuid = '" . mysqli_real_escape_string(sec_db(), $voluto['uuid']) . "' WHERE id = " . (int)$nuove[0]['id']);
                $nuove[0]['g'] = $voluto['uuid'];
                unset($GLOBALS['SYNC_UUID_NUOVO']);
            }
            foreach ($nuove as $r) $voci[] = [(int)$r['u'], $tbl, $r['g'], 'upsert'];
            sync_ordine_da_riallineare($tbl, $nuove);   // voce nuova: le serve una sort_key
        } else {
            $op = $pro['verbo'] === 'del' ? 'delete' : 'upsert';
            foreach ($pro['righe'] as $r) $voci[] = [(int)$r['u'], $tbl, $r['g'], $op];
            if ($pro['verbo'] === 'upd' && preg_match('/\bsort_order\b/i', $pro['set'] ?? '')) {
                sync_ordine_da_riallineare($tbl, $pro['righe']);
            }
        }
        sync_scrivi_registro($voci);
    } catch (Throwable $e) {
        sec_log_error('sync', 'annotazione fallita: ' . $e->getMessage());
    }
}

/** Scrive le voci nel registro, a blocchi. $voci: [[user_id, tabella, uuid, op], ...] */
function sync_scrivi_registro(array $voci): void {
    if (!$voci) return;
    $conn = sec_db();
    $dev = isset($GLOBALS['SYNC_DEVICE_ID']) ? (int)$GLOBALS['SYNC_DEVICE_ID'] : 0;
    $devSql = $dev > 0 ? (string)$dev : 'NULL';
    foreach (array_chunk($voci, 400) as $blocco) {
        $v = [];
        foreach ($blocco as [$uid, $tbl, $uuid, $op]) {
            if (!sync_uuid_valido($uuid)) continue;
            $v[] = '(' . (int)$uid . ",'" . $tbl . "','" . $uuid . "','" . ($op === 'delete' ? 'delete' : 'upsert') . "'," . $devSql . ')';
        }
        if ($v) mysqli_query($conn, "INSERT INTO sync_changes (user_id, tbl, row_uuid, op, device_id) VALUES " . implode(',', $v));
    }
}

/* ---------- ordine delle liste: sort_order (sito) e sort_key (app) vanno d'accordo ---------- */

/**
 * Il sito ordina con un numero (sort_order), l'app con una stringa (sort_key).
 * Regola unica: ORDINARE PER sort_key o per (sort_order, id) deve dare lo stesso
 * risultato. Chi scrive sort_order (il sito) fa riallineare le chiavi; chi scrive
 * sort_key (l'app, via sync/push) fa riallineare sort_order. Il riallineamento
 * si fa UNA volta alla fine della richiesta: il riordino del sito manda una
 * UPDATE per voce, e rifarlo a ogni voce sarebbe quadratico.
 */
function sync_ordine_da_riallineare(string $tbl, array $righe): void {
    if (!$righe) return;
    $man = SYNC_MANIFESTO[$tbl];
    if (empty($man['campi']['sort_key'])) return;
    static $registrato = false;
    foreach ($righe as $r) {
        $uuid = $r['g'] ?? null;
        if (!is_string($uuid)) continue;
        $GLOBALS['SYNC_ORDINE'][$tbl][(int)$r['u']][$uuid] = true;
    }
    if (!$registrato) {
        $registrato = true;
        register_shutdown_function('sync_riallinea_ordine');
    }
}

/** I gruppi (utente, lista) di cui riallineare le sort_key a partire da sort_order. */
function sync_riallinea_ordine(): void {
    if (empty($GLOBALS['SYNC_ORDINE'])) return;
    $lavoro = $GLOBALS['SYNC_ORDINE'];
    $GLOBALS['SYNC_ORDINE'] = [];
    try {
        foreach ($lavoro as $tbl => $perUtente) {
            $man = SYNC_MANIFESTO[$tbl];
            foreach ($perUtente as $uid => $uuids) {
                // i gruppi toccati: per todos = lista (todo/buy) delle righe nominate
                $gruppi = [''];
                if (in_array('list_type', $man['gruppo'], true)) {
                    $ph = implode(',', array_fill(0, count($uuids), '?'));
                    $gruppi = array_column(sec_all("SELECT DISTINCT list_type AS l FROM `$tbl` WHERE user_id = ? AND uuid IN ($ph)",
                                                   array_merge([$uid], array_keys($uuids))), 'l');
                }
                foreach ($gruppi as $g) sync_riallinea_gruppo($tbl, (int)$uid, $g);
            }
        }
    } catch (Throwable $e) {
        sec_log_error('sync', 'riallineamento ordine fallito: ' . $e->getMessage());
    }
}

/** Dentro un gruppo: se l'ordine per sort_key non e' quello per sort_order, si rifanno le chiavi (e si annota). */
function sync_riallinea_gruppo(string $tbl, int $uid, string $lista): void {
    $dove = 'user_id = ?'; $par = [$uid];
    if ($lista !== '') { $dove .= ' AND list_type = ?'; $par[] = $lista; }
    if (!empty(SYNC_MANIFESTO[$tbl]['filtro'])) $dove .= ' AND ' . SYNC_MANIFESTO[$tbl]['filtro'];
    $righe = sec_all("SELECT id, uuid, sort_key FROM `$tbl` WHERE $dove ORDER BY sort_order, id", $par);
    $voci = []; $ultima = null; $nuove = [];
    // 1) le voci senza chiave (create dal sito) e quelle fuori ordine
    $ok = true;
    foreach ($righe as $r) {
        if ($r['sort_key'] === null || ($ultima !== null && strcmp($r['sort_key'], $ultima) <= 0)) { $ok = false; break; }
        $ultima = $r['sort_key'];
    }
    if ($ok) return;
    // 2) conviene rifarle tutte in posizione: semplice e prevedibile
    $k = null; $conn = sec_db();
    foreach ($righe as $r) {
        $k = sync_chiave_dopo($k);
        if ($r['sort_key'] !== $k) {
            mysqli_query($conn, "UPDATE `$tbl` SET sort_key = '" . mysqli_real_escape_string($conn, $k) . "' WHERE id = " . (int)$r['id']);
            $voci[] = [$uid, $tbl, $r['uuid'], 'upsert'];
        }
    }
    sync_scrivi_registro($voci);
}

/**
 * Chi scrive sort_key (l'app) deve aggiornare anche sort_order, perche' il sito
 * ordina ancora con quello: sort_order = posizione per (sort_key, id) nel gruppo.
 * Si annotano solo le voci il cui sort_order cambia davvero.
 */
function sync_ordine_dal_sort_key(string $tbl, int $uid, string $lista): void {
    $dove = 'user_id = ?'; $par = [$uid];
    if ($lista !== '') { $dove .= ' AND list_type = ?'; $par[] = $lista; }
    $righe = sec_all("SELECT id, uuid, sort_order FROM `$tbl` WHERE $dove ORDER BY sort_key, id", $par);
    $conn = sec_db(); $voci = []; $n = 1;
    foreach ($righe as $r) {
        if ((int)$r['sort_order'] !== $n) {
            mysqli_query($conn, "UPDATE `$tbl` SET sort_order = " . $n . " WHERE id = " . (int)$r['id']);
            $voci[] = [$uid, $tbl, $r['uuid'], 'upsert'];
        }
        $n++;
    }
    sync_scrivi_registro($voci);
}

/* ---------- pulizia (a caso dentro push: l'hosting condiviso non e' detto abbia il cron) ---------- */

/** Toglie dal registro le voci piu' vecchie di 90 giorni e ricorda fino a dove (sync_meta.purged_up_to_seq). */
function sync_pota(): void {
    try {
        $conn = sec_db();
        $r = mysqli_query($conn, "SELECT MAX(seq) AS s FROM sync_changes WHERE changed_at < (NOW() - INTERVAL 90 DAY)");
        $riga = $r ? mysqli_fetch_assoc($r) : null;
        $max = (int)($riga['s'] ?? 0);
        if ($max > 0) {
            mysqli_query($conn, "UPDATE sync_meta SET v = GREATEST(v, $max) WHERE k = 'purged_up_to_seq'");
            mysqli_query($conn, "DELETE FROM sync_changes WHERE seq <= $max LIMIT 50000");
        }
        mysqli_query($conn, "DELETE FROM sync_mutations WHERE created_at < (NOW() - INTERVAL 30 DAY) LIMIT 50000");
    } catch (Throwable $e) {
        sec_log_error('sync', 'pota fallita: ' . $e->getMessage());
    }
}
