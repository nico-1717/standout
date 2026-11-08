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
 * Da «INSERT INTO t (a, b, c) VALUES (?, 'x', ?)» ricava i valori delle colonne $colonne (col => valore).
 * Solo segnaposto «?» (il valore e' nei parametri); se una colonna non c'e' o e' scritta in chiaro la query
 * e' di una forma che non sappiamo seguire: si lancia un errore (nelle prove diventa un errore vero,
 * in produzione spegne il registro per la richiesta e lo scrive nel log, come ogni analisi fallita).
 */
function sync_valori_insert(string $sql, array $params, array $colonne): array {
    if (!preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?`?\w+`?\s*\(([^)]*)\)\s*VALUES\s*\(/i', $sql, $m)) {
        throw new RuntimeException('INSERT senza elenco di colonne: non so ricavare la chiave');
    }
    $nomi = array_map(fn($c) => strtolower(trim($c, " `\n\t")), explode(',', $m[1]));
    // gli elementi della prima serie di VALUES, spezzati sulle virgole fuori da apici e parentesi
    $resto = substr($sql, strlen($m[0]));
    $voci = []; $corr = ''; $prof = 0; $apice = ''; $n = strlen($resto);
    for ($i = 0; $i < $n; $i++) {
        $c = $resto[$i];
        if ($apice !== '') { $corr .= $c; if ($c === '\\') { $corr .= $resto[++$i] ?? ''; } elseif ($c === $apice) $apice = ''; continue; }
        if ($c === "'" || $c === '"') { $apice = $c; $corr .= $c; continue; }
        if ($c === '(') { $prof++; $corr .= $c; continue; }
        if ($c === ')') { if ($prof === 0) { $voci[] = trim($corr); break; } $prof--; $corr .= $c; continue; }
        if ($c === ',' && $prof === 0) { $voci[] = trim($corr); $corr = ''; continue; }
        $corr .= $c;
    }
    if (count($voci) !== count($nomi)) throw new RuntimeException('INSERT: colonne e valori non coincidono');
    $posParam = []; $k = 0;
    foreach ($voci as $i => $v) { if ($v === '?') $posParam[$nomi[$i]] = $k++; else $k += sync_segnaposto($v); }
    $out = [];
    foreach ($colonne as $col) {
        if (!isset($posParam[$col]) || !array_key_exists($posParam[$col], $params)) throw new RuntimeException("INSERT: manca il valore di $col");
        $out[$col] = $params[$posParam[$col]];
    }
    return $out;
}

/**
 * La colonna chiave primaria numerica di una tabella sincronizzata («id», salvo diversa indicazione nel manifest:
 * le sotto-carte delle flashcard usano id_child).
 */
function sync_pk(string $tbl): string {
    return SYNC_MANIFESTO[$tbl]['pk'] ?? 'id';
}

/**
 * L'espressione SQL che da' l'utente proprietario di una riga della tabella, con l'alias dato.
 * Di solito e' la colonna user_id; le tabelle che appartengono a un utente solo tramite il padre (le carte
 * di un mazzo) la ricavano con 'proprietario_sql', dove {t} e' l'alias della riga.
 */
function sync_prop(string $tbl, string $alias): string {
    $m = SYNC_MANIFESTO[$tbl];
    if (!empty($m['proprietario_sql'])) return '(' . str_replace('{t}', "`$alias`", $m['proprietario_sql']) . ')';
    return "`$alias`.`" . $m['proprietario'] . '`';
}

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
    if ($verbo === 'ins') {
        // tabelle a chiave naturale (es. un check-in): spesso scritte con «ON DUPLICATE KEY UPDATE», dove insert_id
        // non dice quale riga e' stata toccata. Si ricava la riga dalla chiave, letta dai valori della query.
        if (!empty(SYNC_MANIFESTO[$tbl]['chiave_naturale'])) $pro['nat'] = sync_valori_insert($s, $params, SYNC_MANIFESTO[$tbl]['chiave_naturale']);
        return $pro;
    }

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
    // se la query ha gia' un suo LIMIT (es. UPDATE ... LIMIT 1) vale quello; altrimenti un tetto di sicurezza
    $coda = preg_match('/\sLIMIT\s/i', $where) ? '' : ' LIMIT 20000';
    $pro['select'] = "SELECT " . sync_prop($tbl, $alias) . " AS u, `$alias`.uuid AS g FROM " . $da . " WHERE " . $where . $coda;
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
        if ($pro['verbo'] === 'ins' && isset($pro['nat'])) {
            // riga a chiave naturale: nulla e' cambiato (ON DUPLICATE con gli stessi valori) = niente da annotare
            if ($righeToccate <= 0) return;
            $voci = sync_dopo_insert_naturale($tbl, $pro['nat']);
        } elseif ($pro['verbo'] === 'ins') {
            if ($righeToccate <= 0 || $idInserito <= 0) return;
            $a = $idInserito; $b = $idInserito + $righeToccate - 1;
            $pk = sync_pk($tbl);
            $nuove = sec_all("SELECT " . sync_prop($tbl, 't') . " AS u, t.uuid AS g, t.`$pk` AS id FROM `$tbl` t WHERE t.`$pk` BETWEEN ? AND ?", [$a, $b]);
            // l'app ha scelto lei l'uuid di una riga nuova: lo si applica PRIMA di annotare
            $voluto = $GLOBALS['SYNC_UUID_NUOVO'] ?? null;
            if ($voluto && $voluto['tbl'] === $tbl && count($nuove) === 1) {
                mysqli_query(sec_db(), "UPDATE `$tbl` SET uuid = '" . mysqli_real_escape_string(sec_db(), $voluto['uuid']) . "' WHERE `$pk` = " . (int)$nuove[0]['id']);
                $nuove[0]['g'] = $voluto['uuid'];
                unset($GLOBALS['SYNC_UUID_NUOVO']);
            }
            foreach ($nuove as $r) $voci[] = [(int)$r['u'], $tbl, $r['g'], 'upsert'];
            sync_ordine_da_riallineare($tbl, $nuove);   // voce nuova: le serve una sort_key
        } else {
            $op = $pro['verbo'] === 'del' ? 'delete' : 'upsert';
            foreach ($pro['righe'] as $r) $voci[] = [(int)$r['u'], $tbl, $r['g'], $op];
            if ($pro['verbo'] === 'upd' && preg_match('/\b' . sync_col_ordine($tbl) . '\b/i', $pro['set'] ?? '')) {
                sync_ordine_da_riallineare($tbl, $pro['righe']);
            }
        }
        sync_scrivi_registro($voci);
    } catch (Throwable $e) {
        sec_log_error('sync', 'annotazione fallita: ' . $e->getMessage());
    }
}

/**
 * L'uuid che una riga a chiave naturale DEVE avere (UUIDv5 della sua chiave), o null se la riga non c'e'.
 * Lo stesso calcolo lo fa l'app: cosi' sito e telefono, anche offline, parlano della stessa riga.
 */
function sync_uuid_atteso(string $tbl, int $id): ?string {
    if ($tbl === 'habit_logs') {
        $r = sec_one("SELECT l.log_date, h.uuid AS hu FROM habit_logs l JOIN habits h ON h.id = l.habit_id WHERE l.id = ?", [$id]);
        return $r ? sync_uuid_check_in((string)$r['hu'], (string)$r['log_date']) : null;
    }
    if ($tbl === 'pill_doses') {
        $r = sec_one("SELECT d.due_date, d.due_time, d.seq, p.uuid AS pu FROM pill_doses d JOIN pill_plans p ON p.id = d.plan_id WHERE d.id = ?", [$id]);
        return $r ? sync_uuid_presa((string)$r['pu'], (string)$r['due_date'], (string)$r['due_time'], (int)$r['seq']) : null;
    }
    if ($tbl === 'planner_weeks') {
        $r = sec_one("SELECT user_id, week_start FROM planner_weeks WHERE id = ?", [$id]);
        return $r ? sync_uuid_settimana((int)$r['user_id'], (string)$r['week_start']) : null;
    }
    if ($tbl === 'planner_week_lists') {
        $r = sec_one("SELECT user_id, week_start, list_type FROM planner_week_lists WHERE id = ?", [$id]);
        return $r ? sync_uuid_lista_sett((int)$r['user_id'], (string)$r['week_start'], (string)$r['list_type']) : null;
    }
    if ($tbl === 'planner_priorities') {
        $r = sec_one("SELECT user_id, priority_date, position FROM planner_priorities WHERE id = ?", [$id]);
        return $r ? sync_uuid_priorita((int)$r['user_id'], (string)$r['priority_date'], (int)$r['position']) : null;
    }
    if ($tbl === 'pomodoro_custom_durations') {
        $r = sec_one("SELECT user_id, type, minutes FROM pomodoro_custom_durations WHERE id = ?", [$id]);
        return $r ? sync_uuid_preset((int)$r['user_id'], (string)$r['type'], (int)$r['minutes']) : null;
    }
    if ($tbl === 'habit_daily_journal') {
        $r = sec_one("SELECT user_id, entry_date FROM habit_daily_journal WHERE id = ?", [$id]);
        return $r ? sync_uuid_diario((int)$r['user_id'], (string)$r['entry_date']) : null;
    }
    if ($tbl === 'fc_study_learnt') {
        // una imparata di una carta cancellata non ha un uuid da calcolare (e non viaggia)
        $r = sec_one("SELECT l.user_id, l.is_child, COALESCE(c.uuid, s.uuid) AS tu FROM fc_study_learnt l
                      LEFT JOIN fc_study_cards c ON l.is_child = 0 AND c.id = l.fc_image_id
                      LEFT JOIN fc_study_subcards s ON l.is_child = 1 AND s.id_child = l.fc_image_id WHERE l.id = ?", [$id]);
        return ($r && $r['tu'] !== null) ? sync_uuid_imparata((int)$r['user_id'], (string)$r['tu'], (int)$r['is_child']) : null;
    }
    if ($tbl === 'fc_vocab_learnt') {
        $r = sec_one("SELECT l.user_id, l.is_child, COALESCE(c.uuid, s.uuid) AS tu FROM fc_vocab_learnt l
                      LEFT JOIN fc_vocab_cards c ON l.is_child = 0 AND c.id = l.fc_image_id
                      LEFT JOIN fc_vocab_subcards s ON l.is_child = 1 AND s.id_child = l.fc_image_id WHERE l.id = ?", [$id]);
        return ($r && $r['tu'] !== null) ? sync_uuid_imparata((int)$r['user_id'], (string)$r['tu'], (int)$r['is_child']) : null;
    }
    if ($tbl === 'fc_study_records') {
        $r = sec_one("SELECT user_id, kind FROM fc_study_records WHERE id = ?", [$id]);
        return $r ? sync_uuid_record((int)$r['user_id'], (string)$r['kind']) : null;
    }
    if ($tbl === 'fc_vocab_records') {
        $r = sec_one("SELECT r.user_id, r.kind, c.uuid AS cu FROM fc_vocab_records r JOIN fc_vocab_courses c ON c.id = r.course_id AND c.user_id = r.user_id WHERE r.id = ?", [$id]);
        return $r ? sync_uuid_record_lingua((int)$r['user_id'], (string)$r['cu'], (string)$r['kind']) : null;
    }
    if ($tbl === 'fc_settings' || $tbl === 'fc_goals') {
        // la chiave primaria e' l'utente stesso
        return sync_uuid_per_utente($tbl, $id);
    }
    return null;
}

/**
 * Dopo un INSERT (anche «ON DUPLICATE KEY UPDATE») su una tabella a chiave naturale: trova la riga dalla
 * chiave, le da' l'uuid atteso se ne ha un altro (righe nate prima della migrazione, o col default casuale)
 * e ritorna la voce per il registro. $nat = [colonna => valore] letto dalla query.
 */
function sync_dopo_insert_naturale(string $tbl, array $nat): array {
    $col = SYNC_MANIFESTO[$tbl]['proprietario'];
    $dove = implode(' AND ', array_map(fn($c) => "`$c` = ?", array_keys($nat)));
    $pk = sync_pk($tbl);
    $r = sec_one("SELECT `$pk` AS id, `$col` AS u, uuid AS g FROM `$tbl` WHERE $dove", array_values($nat));
    if (!$r) return [];
    $atteso = sync_uuid_atteso($tbl, (int)$r['id']);
    if ($atteso !== null && $atteso !== $r['g']) {
        mysqli_query(sec_db(), "UPDATE `$tbl` SET uuid = '" . mysqli_real_escape_string(sec_db(), $atteso) . "' WHERE `$pk` = " . (int)$r['id']);
        $r['g'] = $atteso;
    }
    return [[(int)$r['u'], $tbl, $r['g'], 'upsert']];
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
                // i gruppi toccati: per todos = lista (todo/buy) delle righe nominate; per i progetti = il padre
                $gruppi = sync_gruppi_toccati($tbl, (int)$uid, array_keys($uuids));
                foreach ($gruppi as $g) sync_riallinea_gruppo($tbl, (int)$uid, $g);
            }
        }
    } catch (Throwable $e) {
        sec_log_error('sync', 'riallineamento ordine fallito: ' . $e->getMessage());
    }
}

/** Dentro un gruppo: se l'ordine per sort_key non e' quello per sort_order, si rifanno le chiavi (e si annota). */
function sync_riallinea_gruppo(string $tbl, int $uid, string $lista): void {
    $par = [];
    $dove = sync_gruppo_dove($tbl, $uid, $lista, $par);
    if (!empty(SYNC_MANIFESTO[$tbl]['filtro'])) $dove .= ' AND ' . SYNC_MANIFESTO[$tbl]['filtro'];
    $oc = sync_col_ordine($tbl);
    $righe = sec_all("SELECT id, uuid, sort_key FROM `$tbl` WHERE $dove ORDER BY `$oc`, id", $par);
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
function sync_ordine_dal_sort_key(string $tbl, int $uid, string $lista, ?int $idRiga = null): void {
    $par = [];
    $dove = sync_gruppo_dove($tbl, $uid, $lista, $par);
    // le voci che si ordinano dentro un padre (i passaggi di una routine, le voci di una lista): solo quel padre
    if (empty(SYNC_MANIFESTO[$tbl]['gruppo_padre'])) {
        foreach (SYNC_MANIFESTO[$tbl]['gruppo'] as $c) {
            if ($c === 'user_id' || $c === 'list_type' || $idRiga === null) continue;
            $r = sec_one("SELECT `$c` AS v FROM `$tbl` WHERE id = ?", [$idRiga]);
            if ($r) { $dove .= " AND `$c` = ?"; $par[] = $r['v']; }
        }
    }
    $oc = sync_col_ordine($tbl);
    $righe = sec_all("SELECT id, uuid, `$oc` AS ord FROM `$tbl` WHERE $dove ORDER BY sort_key, id", $par);
    $conn = sec_db(); $voci = []; $n = 1;
    foreach ($righe as $r) {
        if ((int)$r['ord'] !== $n) {
            mysqli_query($conn, "UPDATE `$tbl` SET `$oc` = " . $n . " WHERE id = " . (int)$r['id']);
            $voci[] = [$uid, $tbl, $r['uuid'], 'upsert'];
        }
        $n++;
    }
    sync_scrivi_registro($voci);
}

/** La colonna con cui il SITO ordina questa tabella (sort_order, salvo diversa indicazione nel manifest). */
function sync_col_ordine(string $tbl): string {
    return SYNC_MANIFESTO[$tbl]['colonna_ordine'] ?? 'sort_order';
}

/**
 * Il WHERE del gruppo che si ordina insieme. $lista: '' = tutto l'utente; per le tabelle con le liste (todo/buy) il
 * list_type; per le tabelle ad albero ('gruppo_padre') «p:ID» = i figli di quel padre e «p:» = le radici.
 */
function sync_gruppo_dove(string $tbl, int $uid, string $lista, array &$par): string {
    $dove = 'user_id = ?'; $par = [$uid];
    $padre = SYNC_MANIFESTO[$tbl]['gruppo_padre'] ?? null;
    if ($padre !== null) {
        if ($lista === 'p:') $dove .= " AND `$padre` IS NULL";
        elseif (strncmp($lista, 'p:', 2) === 0) { $dove .= " AND `$padre` = ?"; $par[] = (int)substr($lista, 2); }
    } elseif ($lista !== '') { $dove .= ' AND list_type = ?'; $par[] = $lista; }
    return $dove;
}

/** I gruppi (liste o padri) a cui appartengono le righe nominate, o tutti se $uuids e' null. */
function sync_gruppi_toccati(string $tbl, int $uid, ?array $uuids): array {
    $man = SYNC_MANIFESTO[$tbl];
    $filtro = '';
    $par = [$uid];
    if ($uuids !== null) {
        if (!$uuids) return [];
        $filtro = ' AND uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')';
        $par = array_merge($par, array_values($uuids));
    }
    if (!empty($man['gruppo_padre'])) {
        $c = $man['gruppo_padre'];
        $out = [];
        foreach (sec_all("SELECT DISTINCT `$c` AS g FROM `$tbl` WHERE user_id = ?$filtro", $par) as $r) $out[] = 'p:' . ($r['g'] ?? '');
        return $out ?: ['p:'];
    }
    if (in_array('list_type', $man['gruppo'], true)) {
        return array_column(sec_all("SELECT DISTINCT list_type AS l FROM `$tbl` WHERE user_id = ?$filtro", $par), 'l');
    }
    return [''];
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
