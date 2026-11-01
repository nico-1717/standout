<?php
/* =====================================================================
   tools/controlla_sync.php — il registro dei cambiamenti capisce TUTTE le scritture?

   Il registro per l'app (lib/sync.php) legge ogni INSERT/UPDATE/DELETE che passa da
   sec_q() su una tabella sincronizzata. Se una query ha una forma che l'analisi non
   capisce, il registro si spegne per quella richiesta e l'app non vede la modifica.
   Questo controllo trova nel codice TUTTE le sec_q() che scrivono nelle tabelle del
   manifest e, per ognuna, prova che l'analisi produca una SELECT valida.

   Uso:
     php tools/controlla_sync.php            controllo statico (nessun database)
     php tools/controlla_sync.php --db       in piu', fa fare EXPLAIN a ogni SELECT sul database
                                             locale (serve lib/config.php con un database di prova)

   Come legge il codice: con i token di PHP, non con le espressioni regolari, cosi' le
   stringhe spezzate in piu' pezzi (". implode(...) .") si ricompongono; le parti
   dinamiche diventano «c1 = ?» (un'assegnazione) o 1 (un valore).
   Esce con codice 1 se qualcosa non torna.
   ===================================================================== */

require __DIR__ . '/../lib/sync_manifest.php';
require __DIR__ . '/../lib/sync_chiavi.php';
require __DIR__ . '/../lib/sync.php';

$radice = realpath(__DIR__ . '/..');
$conDb = in_array('--db', $argv, true);
$db = null;
if ($conDb) {
    require_once $radice . '/lib/bootstrap.php';
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    mysqli_report(MYSQLI_REPORT_OFF);
}

$tabelle = array_keys(SYNC_MANIFESTO);
$rx = '/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(' . implode('|', $tabelle) . ')`?\b/i';

function file_php(string $dir): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
    sort($out);
    return $out;
}

$totale = 0; $problemi = [];
foreach (array_merge(file_php($radice . '/lib'), file_php($radice . '/public')) as $f) {
    $src = file_get_contents($f);
    if (stripos($src, 'sec_q') === false) continue;
    $tok = token_get_all($src);
    $n = count($tok);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tok[$i]) || $tok[$i][0] !== T_STRING || !in_array($tok[$i][1], ['sec_q', 'sec_one', 'sec_all'], true)) continue;
        $k = $i + 1; while ($k < $n && is_array($tok[$k]) && $tok[$k][0] === T_WHITESPACE) $k++;
        if (($tok[$k] ?? '') !== '(') continue;
        $riga = $tok[$i][2];
        // la stringa: si ricompone passando sopra ai pezzi dinamici
        $sql = primo_argomento_completo($tok, $k);
        if (!preg_match($rx, $sql)) continue;
        $totale++;
        $rel = substr($f, strlen($radice) + 1) . ':' . $riga;
        $sqlPulito = preg_replace('/\s+/', ' ', $sql);
        $nq = substr_count($sqlPulito, '?');
        $esito = null;
        try { $esito = sync_analizza($sqlPulito, array_fill(0, $nq, 1)); }
        catch (Throwable $e) { $problemi[] = "$rel  eccezione: " . $e->getMessage(); continue; }
        if ($esito === null) { $problemi[] = "$rel  non riconosciuta: " . substr($sqlPulito, 0, 100); continue; }
        if ($esito['verbo'] !== 'ins') {
            if ($esito['select'] === null) { $problemi[] = "$rel  nessuna SELECT per gli uuid"; continue; }
            if ($db) {
                $r = $db->query('EXPLAIN ' . str_replace('?', '1', $esito['select']));
                if (!$r) $problemi[] = "$rel  SELECT non valida: " . $db->error . ' | ' . $esito['select'];
            }
        }
    }
}

/** Come primo_argomento, ma segue anche le concatenazioni «. espressione .» fino alla virgola dei parametri. */
function primo_argomento_completo(array $tok, int $i): string {
    $sql = ''; $prof = 0; $n = count($tok); $inStringa = false;
    for ($j = $i; $j < $n; $j++) {
        $t = $tok[$j];
        if ($t === '(') { $prof++; if ($prof > 1) $sql .= ($prof === 2 ? '' : ''); continue; }
        if ($t === ')') { if ($prof === 1) break; $prof--; if ($prof === 1) $sql .= '1'; continue; }
        if ($prof === 1) {
            if ($t === ',') break;
            if ($t === '"') { $inStringa = !$inStringa; continue; }
            if (is_array($t)) {
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) { $sql .= stripcslashes(substr($t[1], 1, -1)); }
                elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) { $sql .= $t[1]; }
                elseif ($t[0] === T_VARIABLE && $inStringa) { $sql .= '1'; }
                elseif ($t[0] === T_VARIABLE) { $sql .= ($sql !== '' && preg_match('/SET\s*$/i', $sql) ? ' c1 = ? ' : ' 1 '); }
                elseif ($t[0] === T_STRING && $j + 1 < $n && ($tok[$j + 1] === '(')) { $sql .= ' c1 = ? '; }   // implode(...) e simili
            }
        }
    }
    return $sql;
}

echo "Scritture sulle tabelle sincronizzate trovate nel codice: $totale\n";
if ($problemi) {
    echo "PROBLEMI (" . count($problemi) . "):\n  " . implode("\n  ", $problemi) . "\n";
    exit(1);
}
echo "OK: il registro capisce tutte" . ($conDb ? " (SELECT provate con EXPLAIN sul database locale)" : '') . "\n";
