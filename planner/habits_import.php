<?php
/* ==========================================================================
   HABITS IMPORT / EXPORT — popup riutilizzabile per importare abitudini e
   check-in da un file esterno (.xlsx o .csv) e per esportare tutto lo
   storico nello stesso formato: un FOGLIO per abitudine, con dentro le
   date e gli orari dei check-in.

   Include in habits.php, esattamente come pomodoro_storico.php include
   pomodoro_import.php. Tutta l'interazione avviene via fetch/JSON su
   questo stesso file.

   Uso:
     include 'habits_import.php';   // renderizza il popup (vuoto)
     window.openHabitsImport();     // apre in JS
     window.exportHabitsFile();     // scarica l'export

   FORMATO ATTESO DEL FILE: un foglio per abitudine (il nome del foglio e'
   il nome dell'abitudine), con colonne Date, Time, Status, Total check-ins,
   Mood, Habit Log. Riconosciute per NOME di colonna, con fallback sulle
   posizioni. Una prima cella "Basic Information / Habit Name: ..." e'
   opzionale: se c'e', il nome per intero (il nome del foglio in Excel e'
   troncato a 31 caratteri) viene letto da li'.

   Nessuna dipendenza esterna: .xlsx letto/scritto a mano con ZipArchive +
   SimpleXML, .csv con fgetcsv.
   ========================================================================== */

/* Gli endpoint qui sotto devono rispondere SOLO quando il file viene chiamato
   direttamente: senza questo controllo un banale habits.php?hi_export=1
   scaricherebbe i byte dell'xlsx in mezzo all'HTML della pagina, perche' il
   file e' incluso in fondo a habits.php. */
$hi_files = get_included_files();
$hi_direct = (!empty($hi_files) && realpath($hi_files[0]) === realpath(__FILE__));

if (!isset($conn) || !$conn) {
    require_once 'config.php';
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn) { mysqli_set_charset($conn, 'utf8mb4'); }
    if ($hi_direct && isset($_GET['hi_ajax'])) { require_once 'auth_check_api.php'; }
    else { require_once 'auth_check.php'; }
}
if (!isset($current_user_id)) {
    $current_user_id = isset($utente_corrente['id']) ? $utente_corrente['id'] : 0;
}
if (function_exists('date_default_timezone_set')) { date_default_timezone_set('Europe/Rome'); }

/* ==========================================================================
   HELPER GENERICI
   ========================================================================== */

if (!function_exists('hi_norm')) {
    /* "Total check-ins" -> "totalcheckins" : per confrontare le intestazioni
       senza impazzire con spazi, maiuscole, trattini e accenti. */
    function hi_norm($s) {
        $s = (string)$s;
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = strtr($s, ['à'=>'a','á'=>'a','è'=>'e','é'=>'e','ì'=>'i','í'=>'i','ò'=>'o','ó'=>'o','ù'=>'u','ú'=>'u']);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return $s === null ? '' : $s;
    }
}

if (!function_exists('hi_col_index')) {
    function hi_col_index($ref) {
        if (!preg_match('/^([A-Za-z]+)/', (string)$ref, $m)) return -1;
        $letters = strtoupper($m[1]);
        $n = 0; $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) { $n = $n * 26 + (ord($letters[$i]) - 64); }
        return $n - 1;
    }
}

if (!function_exists('hi_col_letter')) {
    function hi_col_letter($i) {
        $i = (int)$i; $s = '';
        while ($i >= 0) { $s = chr(65 + ($i % 26)) . $s; $i = intdiv($i, 26) - 1; }
        return $s;
    }
}

if (!function_exists('hi_excel_epoch')) {
    function hi_excel_epoch() {
        static $base = null;
        if ($base === null) $base = strtotime('1970-01-01 00:00:00');
        return $base;
    }
}

if (!function_exists('hi_serial_to_datetime')) {
    function hi_serial_to_datetime($serial) {
        $ts = (int)round(((float)$serial - 25569) * 86400) + hi_excel_epoch();
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('hi_xml_escape')) {
    function hi_xml_escape($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
}

/* ==========================================================================
   LETTURA .XLSX — TUTTI I FOGLI (ZipArchive + SimpleXML, zero dipendenze)
   A differenza di un import a foglio singolo, qui ogni foglio e' una
   abitudine diversa: bisogna leggere workbook.xml per l'elenco dei fogli
   nell'ordine giusto e i relationships per risalire al file xml di ognuno.
   ========================================================================== */

if (!function_exists('hi_xlsx_shared_strings')) {
    function hi_xlsx_shared_strings($zip) {
        $out = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return $out;
        $sx = @simplexml_load_string($xml);
        if (!$sx) return $out;
        foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) { $text = (string)$si->t; }
            else { foreach ($si->r as $r) { $text .= (string)$r->t; } }
            $out[] = $text;
        }
        return $out;
    }
}

if (!function_exists('hi_xlsx_date_styles')) {
    function hi_xlsx_date_styles($zip) {
        $isDate = [];
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) return $isDate;
        $sx = @simplexml_load_string($xml);
        if (!$sx) return $isDate;

        $customFmts = [];
        if (isset($sx->numFmts)) {
            foreach ($sx->numFmts->numFmt as $nf) { $customFmts[(int)$nf['numFmtId']] = (string)$nf['formatCode']; }
        }
        $builtinDate = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];

        $i = 0;
        if (isset($sx->cellXfs)) {
            foreach ($sx->cellXfs->xf as $xf) {
                $fmtId = isset($xf['numFmtId']) ? (int)$xf['numFmtId'] : 0;
                $date = in_array($fmtId, $builtinDate, true);
                if (!$date && isset($customFmts[$fmtId])) {
                    $code = $customFmts[$fmtId];
                    $code = preg_replace('/\[[^\]]*\]/', '', $code);
                    $code = preg_replace('/"[^"]*"/', '', $code);
                    if ($code !== null && preg_match('/[ymdhs]/i', $code)) $date = true;
                }
                if ($date) $isDate[$i] = true;
                $i++;
            }
        }
        return $isDate;
    }
}

if (!function_exists('hi_xlsx_sheet_list')) {
    /* Elenco dei fogli nell'ordine in cui compaiono nel workbook, con il
       percorso xml di ognuno risolto tramite i relationships. */
    function hi_xlsx_sheet_list($zip) {
        $out = [];
        $xml = $zip->getFromName('xl/workbook.xml');
        if ($xml === false) return $out;
        $sx = @simplexml_load_string($xml);
        if (!$sx || !isset($sx->sheets)) return $out;

        $rels = [];
        $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relXml !== false) {
            $rsx = @simplexml_load_string($relXml);
            if ($rsx) { foreach ($rsx->Relationship as $rel) { $rels[(string)$rel['Id']] = (string)$rel['Target']; } }
        }

        foreach ($sx->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            $name = (string)$sheet['name'];
            $target = isset($rels[$rid]) ? $rels[$rid] : '';
            if ($target === '') continue;
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') !== 0) { $target = 'xl/' . preg_replace('#^\.?/?#', '', $target); }
            $out[] = ['name' => $name, 'path' => $target];
        }
        return $out;
    }
}

if (!function_exists('hi_parse_sheet_xml')) {
    function hi_parse_sheet_xml($sheetXml, $shared, $dateStyles) {
        $sx = @simplexml_load_string($sheetXml);
        if (!$sx || !isset($sx->sheetData)) return [];
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = []; $maxCol = -1;
            foreach ($row->c as $c) {
                $col = hi_col_index((string)$c['r']);
                if ($col < 0) { $col = $maxCol + 1; }
                $t = isset($c['t']) ? (string)$c['t'] : '';
                $s = isset($c['s']) ? (int)$c['s'] : 0;
                $raw = isset($c->v) ? (string)$c->v : '';
                $val = ''; $isDate = false;

                if ($t === 's') {
                    $idx = (int)$raw;
                    $val = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($t === 'inlineStr') {
                    if (isset($c->is->t)) { $val = (string)$c->is->t; }
                    elseif (isset($c->is->r)) { foreach ($c->is->r as $rr) { $val .= (string)$rr->t; } }
                    $raw = $val;
                } elseif ($t === 'str') {
                    $val = $raw;
                } elseif ($t === 'b') {
                    $val = ($raw === '1') ? 'TRUE' : 'FALSE';
                } elseif ($t === 'e') {
                    $val = '';
                } else {
                    if ($raw !== '' && is_numeric($raw) && isset($dateStyles[$s])) { $isDate = true; $val = hi_serial_to_datetime($raw); }
                    else { $val = $raw; }
                }
                $cells[$col] = ['v' => $val, 'raw' => $raw, 'date' => $isDate];
                if ($col > $maxCol) $maxCol = $col;
            }
            $flat = [];
            for ($i = 0; $i <= $maxCol; $i++) { $flat[] = isset($cells[$i]) ? $cells[$i] : ['v' => '', 'raw' => '', 'date' => false]; }
            $rows[] = $flat;
        }
        return $rows;
    }
}

if (!function_exists('hi_read_xlsx_all')) {
    function hi_read_xlsx_all($path, &$error) {
        if (!class_exists('ZipArchive')) {
            $error = 'Estensione ZipArchive non disponibile sul server: converti il file in CSV e riprova.';
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $error = 'Il file non e\' un .xlsx valido (potrebbe essere un vecchio .xls: aprilo e risalvalo come .xlsx o .csv).';
            return null;
        }
        $shared = hi_xlsx_shared_strings($zip);
        $dateStyles = hi_xlsx_date_styles($zip);
        $sheetList = hi_xlsx_sheet_list($zip);

        if (empty($sheetList)) {
            // relationships scritti male da qualche esportatore: recupero i fogli
            // nell'ordine in cui compaiono fisicamente nello zip.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                    $sheetList[] = ['name' => 'Foglio ' . (count($sheetList) + 1), 'path' => $name];
                }
            }
        }

        $out = [];
        foreach ($sheetList as $s) {
            $xml = $zip->getFromName($s['path']);
            if ($xml === false) continue;
            $out[] = ['name' => $s['name'], 'rows' => hi_parse_sheet_xml($xml, $shared, $dateStyles)];
        }
        $zip->close();

        if (empty($out)) { $error = 'Nessun foglio trovato nel file.'; return null; }
        return $out;
    }
}

/* ==========================================================================
   LETTURA .CSV — un file .csv e' sempre una sola "abitudine": il nome si
   prende dal nome del file.
   ========================================================================== */

if (!function_exists('hi_read_csv')) {
    function hi_read_csv($path, &$error) {
        $raw = file_get_contents($path);
        if ($raw === false) { $error = 'Impossibile leggere il file.'; return null; }
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
        if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $raw = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')
                : utf8_encode($raw);
        }

        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $seen = 0;
        foreach (preg_split("/\r\n|\n|\r/", $raw, 12) as $line) {
            if (trim($line) === '') continue;
            foreach ($counts as $d => $_) { $counts[$d] += substr_count($line, $d); }
            if (++$seen >= 6) break;
        }
        if ($seen === 0) { $error = 'File vuoto.'; return null; }
        arsort($counts);
        $delim = key($counts);
        if ($counts[$delim] === 0) $delim = ',';

        $tmp = fopen('php://memory', 'r+');
        fwrite($tmp, $raw);
        rewind($tmp);

        $rows = [];
        while (($data = fgetcsv($tmp, 0, $delim)) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string)$data[0]) === '')) continue;
            $cells = [];
            foreach ($data as $v) {
                $v = trim((string)$v);
                $cells[] = ['v' => $v, 'raw' => $v, 'date' => false];
            }
            $rows[] = $cells;
        }
        fclose($tmp);
        return $rows;
    }
}

/* ==========================================================================
   RICONOSCIMENTO COLONNE
   ========================================================================== */

if (!function_exists('hi_header_aliases')) {
    function hi_header_aliases() {
        return [
            'date'   => ['date', 'data', 'giorno', 'giornata', 'day'],
            'time'   => ['time', 'ora', 'orario'],
            'status' => ['status', 'stato'],
            'count'  => ['totalcheckins', 'checkins', 'checkin', 'count', 'completions', 'completamenti', 'times', 'volte', 'quantity', 'quantita', 'amount', 'valore', 'reps'],
            'mood'   => ['mood', 'umore'],
            'note'   => ['habitlog', 'note', 'nota', 'notes', 'commento', 'comment', 'log', 'appunti'],
        ];
    }
}

if (!function_exists('hi_positional_cols')) {
    /* Posizioni standard (0-based) del formato: Date, Time, Status,
       Total check-ins, Mood, Habit Log — nell'ordine dell'esempio. */
    function hi_positional_cols() {
        return ['date' => 0, 'time' => 1, 'status' => 2, 'count' => 3, 'mood' => 4, 'note' => 5];
    }
}

if (!function_exists('hi_detect_columns')) {
    function hi_detect_columns($headerRow, &$matched) {
        $aliases = hi_header_aliases();
        $cols = ['date' => -1, 'time' => -1, 'status' => -1, 'count' => -1, 'mood' => -1, 'note' => -1];
        $norms = [];
        foreach ($headerRow as $i => $cell) { $norms[$i] = hi_norm($cell['v']); }

        $matched = 0;
        foreach ($aliases as $key => $list) {
            foreach ($list as $a) {
                $found = -1;
                foreach ($norms as $i => $n) {
                    if ($n === '' || in_array($i, $cols, true)) continue;
                    if ($n === $a) { $found = $i; break; }
                }
                if ($found !== -1) { $cols[$key] = $found; $matched++; break; }
            }
        }
        foreach ($aliases as $key => $list) {
            if ($cols[$key] !== -1) continue;
            foreach ($list as $a) {
                if (strlen($a) < 3) continue;
                $found = -1;
                foreach ($norms as $i => $n) {
                    if ($n === '' || in_array($i, $cols, true)) continue;
                    if (strpos($n, $a) === 0) { $found = $i; break; }
                }
                if ($found !== -1) { $cols[$key] = $found; $matched++; break; }
            }
        }
        foreach (hi_positional_cols() as $key => $pos) {
            if ($cols[$key] !== -1) continue;
            if (in_array($pos, $cols, true)) continue;
            $cols[$key] = $pos;
        }
        return $cols;
    }
}

if (!function_exists('hi_find_header')) {
    function hi_find_header($rows, &$cols, &$score) {
        $limit = min(count($rows), 20);
        $bestIdx = -1; $bestScore = 0; $bestCols = null;
        for ($i = 0; $i < $limit; $i++) {
            $sc = 0;
            $c = hi_detect_columns($rows[$i], $sc);
            if ($sc > $bestScore) { $bestScore = $sc; $bestIdx = $i; $bestCols = $c; }
            if ($sc >= 4) break;
        }
        if ($bestScore >= 2) { $cols = $bestCols; $score = $bestScore; return $bestIdx; }
        $cols = hi_positional_cols(); $score = 0;
        return -1;
    }
}

if (!function_exists('hi_row_empty')) {
    function hi_row_empty($r) {
        foreach ($r as $c) { if (trim((string)$c['v']) !== '') return false; }
        return true;
    }
}

if (!function_exists('hi_clean_name')) {
    function hi_clean_name($v) {
        $s = trim((string)$v);
        if ($s === '/' || $s === '\\') return '';
        if (strtolower($s) === 'null') return '';
        return $s;
    }
}

if (!function_exists('hi_extract_habit_name')) {
    /* Il nome del foglio Excel e' troncato a 31 caratteri: se c'e' un blocco
       "Basic Information / Habit Name: ..." lo preferiamo perche' e' per
       intero. */
    function hi_extract_habit_name($rows) {
        $limit = min(count($rows), 6);
        for ($i = 0; $i < $limit; $i++) {
            foreach ($rows[$i] as $c) {
                $text = (string)$c['v'];
                if ($text === '' || stripos($text, 'habit name') === false) continue;
                if (preg_match('/habit\s*name\s*:\s*([^\r\n]+)/i', $text, $m)) {
                    $name = trim($m[1]);
                    if ($name !== '') return mb_substr($name, 0, 120);
                }
            }
        }
        return null;
    }
}

/* ==========================================================================
   PARSING DEI VALORI
   ========================================================================== */

if (!function_exists('hi_detect_date_order')) {
    function hi_detect_date_order($samples) {
        $firstOver12 = false; $secondOver12 = false;
        foreach ($samples as $s) {
            if (!preg_match('#^\s*(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{1,4})#', $s, $m)) continue;
            if (strlen($m[1]) === 4) return 'ymd';
            if ((int)$m[1] > 12) $firstOver12 = true;
            if ((int)$m[2] > 12) $secondOver12 = true;
        }
        if ($firstOver12 && !$secondOver12) return 'dmy';
        if ($secondOver12 && !$firstOver12) return 'mdy';
        return null;
    }
}

if (!function_exists('hi_parse_date')) {
    function hi_parse_date($cell, $order) {
        if (!empty($cell['date'])) return substr((string)$cell['v'], 0, 10);
        $s = trim((string)$cell['v']);
        if ($s === '') return null;

        if (is_numeric($s) && (float)$s > 20000 && (float)$s < 90000) {
            return substr(hi_serial_to_datetime($s), 0, 10);
        }
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $s, $m)) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('#^(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $s, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; $c = (int)$m[3];
            if (strlen($m[1]) === 4) { $y = $a; $mo = $b; $d = $c; }
            else {
                $y = $c < 100 ? 2000 + $c : $c;
                $use = $order;
                if ($use === null || $use === 'ymd') { $use = ($a > 12) ? 'dmy' : (($b > 12) ? 'mdy' : 'dmy'); }
                if ($use === 'mdy') { $mo = $a; $d = $b; } else { $d = $a; $mo = $b; }
            }
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || !checkdate($mo, $d, $y)) return null;
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}

if (!function_exists('hi_parse_time')) {
    function hi_parse_time($cell) {
        if (!empty($cell['date']) && is_numeric($cell['raw'])) {
            $frac = (float)$cell['raw'];
            $fracOfDay = $frac - floor($frac);
            $secs = (int)round($fracOfDay * 86400);
            return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
        }
        $s = trim((string)$cell['v']);
        if ($s === '') return null;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm|AM|PM)?$/', $s, $m)) {
            $h = (int)$m[1]; $mi = (int)$m[2]; $se = isset($m[3]) ? (int)$m[3] : 0;
            $ap = isset($m[4]) ? strtolower($m[4]) : '';
            if ($ap === 'pm' && $h < 12) $h += 12;
            if ($ap === 'am' && $h === 12) $h = 0;
            if ($h > 23 || $mi > 59 || $se > 59) return null;
            return sprintf('%02d:%02d:%02d', $h, $mi, $se);
        }
        return null;
    }
}

if (!function_exists('hi_status_excluded')) {
    /* Il foglio dovrebbe contenere solo i giorni fatti: escludiamo solo se lo
       stato dice esplicitamente il contrario (saltato / fallito / mancato). */
    function hi_status_excluded($raw) {
        $n = hi_norm($raw);
        if ($n === '') return false;
        foreach (['skip', 'salt', 'miss', 'fail', 'fallit', 'annullat', 'cancel'] as $k) {
            if (strpos($n, $k) !== false) return true;
        }
        return false;
    }
}

/* ==========================================================================
   AJAX: analisi del file caricato
   ========================================================================== */
if ($hi_direct && isset($_GET['hi_ajax']) && $_GET['hi_ajax'] === 'parse') {
    header('Content-Type: application/json');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE => 'Il file supera il limite di upload del server.',
            UPLOAD_ERR_FORM_SIZE => 'Il file e\' troppo grande.',
            UPLOAD_ERR_PARTIAL => 'Caricamento interrotto, riprova.',
            UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
        ];
        $e = isset($_FILES['file']) ? $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'error' => isset($codes[$e]) ? $codes[$e] : 'Errore nel caricamento del file.']);
        exit();
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $error = '';

    $head = file_get_contents($tmpPath, false, null, 0, 2);
    $isZip = ($head === 'PK');

    if ($ext === 'xls' && !$isZip) {
        echo json_encode(['success' => false, 'error' => 'I vecchi file .xls non sono supportati: aprilo e risalvalo come .xlsx o .csv.']);
        exit();
    }

    $sheets = [];
    if ($isZip) {
        $sheets = hi_read_xlsx_all($tmpPath, $error);
        if ($sheets === null) { echo json_encode(['success' => false, 'error' => $error ?: 'File non leggibile.']); exit(); }
    } else {
        $rows = hi_read_csv($tmpPath, $error);
        if ($rows === null) { echo json_encode(['success' => false, 'error' => $error ?: 'File non leggibile.']); exit(); }
        if (count($rows) === 0) { echo json_encode(['success' => false, 'error' => 'Il file e\' vuoto.']); exit(); }
        $baseName = trim((string)pathinfo($origName, PATHINFO_FILENAME));
        $sheets = [['name' => $baseName !== '' ? $baseName : 'Abitudine importata', 'rows' => $rows]];
    }

    $outHabits = []; $outLogs = []; $warnings = []; $skippedSheets = []; $totalSkippedRows = 0;
    $hKeySeq = 0;

    foreach ($sheets as $sheet) {
        $rows = $sheet['rows'];
        if (!is_array($rows) || count($rows) === 0) { $skippedSheets[] = $sheet['name']; continue; }

        $cols = []; $score = 0;
        $headerIdx = hi_find_header($rows, $cols, $score);
        $isHeaderRow = ($headerIdx >= 0);
        $dataStart = $isHeaderRow ? $headerIdx + 1 : 0;

        $dataRows = [];
        foreach (array_slice($rows, $dataStart) as $r) { if (!hi_row_empty($r)) $dataRows[] = $r; }
        if (count($dataRows) === 0) { $skippedSheets[] = $sheet['name']; continue; }

        $extracted = hi_extract_habit_name($rows);
        $habitName = ($extracted !== null && $extracted !== '') ? $extracted : trim((string)$sheet['name']);
        if ($habitName === '') $habitName = 'Abitudine importata';

        $samples = [];
        foreach ($dataRows as $r) {
            if (isset($r[$cols['date']]) && empty($r[$cols['date']]['date'])) $samples[] = (string)$r[$cols['date']]['v'];
        }
        $dateOrder = hi_detect_date_order($samples);
        $ambiguous = ($dateOrder === null && count($samples) > 0);
        if ($dateOrder === null) $dateOrder = 'dmy';

        $hKey = 'H' . $hKeySeq; $hKeySeq++;
        $sheetLogs = []; $skipped = 0;

        foreach ($dataRows as $r) {
            $get = function ($k) use ($r, $cols) {
                $i = $cols[$k];
                return ($i >= 0 && isset($r[$i])) ? $r[$i] : ['v' => '', 'raw' => '', 'date' => false];
            };

            $date = hi_parse_date($get('date'), $dateOrder);
            if ($date === null) { $skipped++; continue; }

            $statusRaw = trim((string)$get('status')['v']);
            if (hi_status_excluded($statusRaw)) { $skipped++; continue; }

            $time = hi_parse_time($get('time'));
            $countRaw = trim((string)$get('count')['v']);
            $count = is_numeric($countRaw) ? (float)$countRaw : 1.0;
            if ($count <= 0) $count = 1.0;
            $mood = hi_clean_name($get('mood')['v']);
            $note = hi_clean_name($get('note')['v']);

            $sheetLogs[] = ['habitKey' => $hKey, 'date' => $date, 'time' => $time, 'count' => $count, 'mood' => $mood, 'note' => $note];
        }

        if (count($sheetLogs) === 0) { $skippedSheets[] = $habitName; continue; }

        usort($sheetLogs, function ($a, $b) {
            return strcmp($a['date'] . ' ' . ($a['time'] ?: '00:00:00'), $b['date'] . ' ' . ($b['time'] ?: '00:00:00'));
        });

        $outHabits[] = ['key' => $hKey, 'name' => $habitName];
        $outLogs = array_merge($outLogs, $sheetLogs);
        $totalSkippedRows += $skipped;
        if (!$isHeaderRow) $warnings[] = 'Foglio "' . $habitName . '": intestazioni non riconosciute, ho usato le posizioni standard (Data, Ora, Stato, Check-in, Umore, Nota).';
        if ($ambiguous) $warnings[] = 'Foglio "' . $habitName . '": date ambigue, lette come giorno/mese/anno.';
    }

    if (count($outHabits) === 0) {
        echo json_encode(['success' => false, 'error' => 'Nessuna abitudine valida trovata: ogni foglio deve avere una colonna con la data del check-in.']);
        exit();
    }
    if (!empty($skippedSheets)) {
        $names = array_slice($skippedSheets, 0, 5);
        $warnings[] = count($skippedSheets) . ' fogli ignorati perche\' senza righe valide: ' . implode(', ', $names) . (count($skippedSheets) > 5 ? '...' : '');
    }
    if ($totalSkippedRows > 0) {
        $warnings[] = $totalSkippedRows . ' rig' . ($totalSkippedRows === 1 ? 'a ignorata' : 'he ignorate') . ' perche\' senza una data valida.';
    }

    echo json_encode([
        'success' => true,
        'habits' => $outHabits,
        'logs' => $outLogs,
        'warnings' => $warnings,
        'stats' => [
            'habits' => count($outHabits),
            'logs' => count($outLogs),
            'skipped' => $totalSkippedRows,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ==========================================================================
   AJAX: salvataggio
   Le abitudini non collegate a una gia' esistente vengono create nuove con
   impostazioni standard (a completamento, ogni giorno): l'utente puo' poi
   affinarle dalla scheda di modifica come per qualunque altra abitudine.
   ========================================================================== */
if ($hi_direct && isset($_GET['hi_ajax']) && $_GET['hi_ajax'] === 'save') {
    header('Content-Type: application/json');

    $payload = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($payload) || empty($payload['logs']) || empty($payload['habits'])) {
        echo json_encode(['success' => false, 'error' => 'Dati non validi.']); exit();
    }

    $uid = intval($current_user_id);

    $ownIds = [];
    $res = mysqli_query($conn, "SELECT id FROM habits WHERE user_id = $uid");
    while ($res && ($row = mysqli_fetch_assoc($res))) { $ownIds[(int)$row['id']] = true; }

    if (!function_exists('hi_scalar')) {
        function hi_scalar($conn, $sql) {
            $r = mysqli_query($conn, $sql);
            if (!$r) return 0;
            $row = mysqli_fetch_row($r);
            return $row ? $row[0] : 0;
        }
    }
    if (!function_exists('hi_valid_date')) {
        function hi_valid_date($s) {
            $s = trim((string)$s);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
            $p = explode('-', $s);
            return checkdate((int)$p[1], (int)$p[2], (int)$p[0]) ? $s : null;
        }
    }
    if (!function_exists('hi_create_habit')) {
        /* Abitudine nuova con impostazioni standard: a completamento, ogni
           giorno, icona di default. L'utente la personalizza dopo, dalla
           scheda di modifica, come qualunque altra abitudine. */
        function hi_create_habit($conn, $uid, $name, $color, $order, $startDate) {
            $nameEsc = mysqli_real_escape_string($conn, $name);
            $colorEsc = mysqli_real_escape_string($conn, $color);
            $startEsc = mysqli_real_escape_string($conn, $startDate);
            $sql = "INSERT INTO habits (user_id, category_id, preset_key, name, icon, color, motivation, habit_kind,
                        goal_type, target_amount, step_amount, unit, frequency_type, weekdays, times_per_week,
                        times_per_month, interval_days, day_part, duration_type, duration_days, start_date, end_date, sort_order)
                    VALUES (" . intval($uid) . ", NULL, '', '$nameEsc', '⭐️', '$colorEsc', '', 'build',
                        'completion', 1, 1, '', 'daily', '1111111', 7, 30, 1, 'any', 'forever', NULL, '$startEsc', NULL, " . intval($order) . ")";
            return mysqli_query($conn, $sql) ? mysqli_insert_id($conn) : 0;
        }
    }

    $palette = ['#7C5CFF', '#0A84FF', '#00C7BE', '#FF2D8F', '#FF9500', '#34C759', '#AF52DE', '#FF3B30'];

    mysqli_begin_transaction($conn);
    $createdHabits = 0; $daysImported = 0; $eventsImported = 0;

    try {
        /* 1) abitudini: quelle mappate riusano l'id esistente, le altre sono
           create ex novo con impostazioni standard */
        $order = (float)hi_scalar($conn, "SELECT COALESCE(MAX(sort_order), 0) FROM habits WHERE user_id = $uid");
        $habitIds = []; $paletteIdx = 0;

        // prima data di check-in per abitudine, per usarla come start_date se creata nuova
        $firstDateByKey = [];
        foreach ($payload['logs'] as $l) {
            $k = (string)($l['habitKey'] ?? ''); $d = hi_valid_date($l['date'] ?? '');
            if ($k === '' || $d === null) continue;
            if (!isset($firstDateByKey[$k]) || $d < $firstDateByKey[$k]) $firstDateByKey[$k] = $d;
        }

        foreach ($payload['habits'] as $h) {
            $key = (string)($h['key'] ?? '');
            $mapped = intval($h['mapped'] ?? 0);
            if ($mapped > 0 && isset($ownIds[$mapped])) { $habitIds[$key] = $mapped; continue; }

            $name = mb_substr(trim((string)($h['name'] ?? '')), 0, 120);
            if ($name === '') continue;
            $color = $palette[$paletteIdx % count($palette)]; $paletteIdx++;
            $order += 10;
            $startDate = isset($firstDateByKey[$key]) ? $firstDateByKey[$key] : date('Y-m-d');

            $newId = hi_create_habit($conn, $uid, $name, $color, (int)$order, $startDate);
            if (!$newId) throw new Exception('Errore creando l\'abitudine "' . $name . '"');
            $habitIds[$key] = $newId;
            $ownIds[$newId] = true;
            $createdHabits++;
        }

        /* obiettivo reale di ogni abitudine coinvolta (nuova o gia' esistente):
           serve per calcolare correttamente quantita' raggiunta e stato */
        $involvedIds = array_values(array_unique(array_values($habitIds)));
        $habitMeta = [];
        if (!empty($involvedIds)) {
            $idList = implode(',', array_map('intval', $involvedIds));
            $mres = mysqli_query($conn, "SELECT id, goal_type, target_amount, step_amount FROM habits WHERE id IN ($idList) AND user_id = $uid");
            while ($mres && ($row = mysqli_fetch_assoc($mres))) {
                $habitMeta[(int)$row['id']] = ['goal_type' => $row['goal_type'], 'target' => (float)$row['target_amount'], 'step' => (float)$row['step_amount']];
            }
        }

        /* 2) log raggruppati per abitudine+giorno, ed eventi singoli (un
           evento per ogni riga del file: preserva l'orario esatto di ogni
           check-in, e alimenta il grafico "a che ora lo fai") */
        $byDay = [];
        $eventsToInsert = [];
        $today = date('Y-m-d');

        foreach ($payload['logs'] as $l) {
            $key = (string)($l['habitKey'] ?? '');
            if (!isset($habitIds[$key])) continue;
            $habitId = $habitIds[$key];
            $date = hi_valid_date($l['date'] ?? '');
            if ($date === null || $date > $today) continue;

            $meta = isset($habitMeta[$habitId]) ? $habitMeta[$habitId] : ['goal_type' => 'completion', 'target' => 1.0, 'step' => 1.0];
            $rowCount = isset($l['count']) ? (float)$l['count'] : 1.0;
            if ($rowCount <= 0) $rowCount = 1.0;
            $perRow = ($meta['goal_type'] === 'quantity') ? ($meta['step'] * $rowCount) : $meta['target'];

            $mk = $habitId . '|' . $date;
            if (!isset($byDay[$mk])) $byDay[$mk] = ['habit_id' => $habitId, 'date' => $date, 'amount' => 0.0, 'completions' => 0, 'target' => $meta['target'], 'mood' => null, 'note' => ''];
            $byDay[$mk]['amount'] += $perRow;
            $byDay[$mk]['completions'] += 1;
            if (!empty($l['mood']) && is_numeric($l['mood'])) {
                $m = (int)$l['mood']; if ($m < 1) $m = 1; if ($m > 5) $m = 5;
                $byDay[$mk]['mood'] = $m;
            }
            if (!empty($l['note']) && $byDay[$mk]['note'] === '') $byDay[$mk]['note'] = mb_substr((string)$l['note'], 0, 500);

            $evTime = !empty($l['time']) ? $l['time'] : '00:00:00';
            $eventsToInsert[] = [$habitId, $date, $perRow, $date . ' ' . $evTime];
        }

        /* 3) upsert giornaliero: se il giorno esisteva gia' (import ripetuto,
           o un check-in reale fatto dall'app) teniamo il massimo raggiunto,
           cosi' non si perde ne' l'uno ne' l'altro */
        $values = [];
        foreach ($byDay as $d) {
            $dateEsc = mysqli_real_escape_string($conn, $d['date']);
            $noteEsc = mysqli_real_escape_string($conn, $d['note']);
            $moodSql = $d['mood'] !== null ? intval($d['mood']) : 'NULL';
            $values[] = "(" . intval($d['habit_id']) . ", $uid, '$dateEsc', 'done', " . floatval($d['amount']) . ", " . floatval($d['target']) . ", " . intval($d['completions']) . ", '$noteEsc', $moodSql)";
        }
        foreach (array_chunk($values, 200) as $chunk) {
            $sql = "INSERT INTO habit_logs (habit_id, user_id, log_date, status, amount, target_amount, completions, note, mood) VALUES " . implode(',', $chunk) . "
                    ON DUPLICATE KEY UPDATE
                        amount = GREATEST(amount, VALUES(amount)),
                        target_amount = VALUES(target_amount),
                        completions = GREATEST(completions, VALUES(completions)),
                        status = IF(GREATEST(amount, VALUES(amount)) >= VALUES(target_amount), 'done', status),
                        note = IF(note = '', VALUES(note), note),
                        mood = IF(mood IS NULL, VALUES(mood), mood)";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
            $daysImported += count($chunk);
        }

        /* 4) eventi singoli: un insert a blocchi, un file da migliaia di
           check-in non deve fare migliaia di query */
        $evValues = [];
        foreach ($eventsToInsert as $ev) {
            list($habitId, $date, $delta, $created) = $ev;
            $dateEsc = mysqli_real_escape_string($conn, $date);
            $createdEsc = mysqli_real_escape_string($conn, $created);
            $evValues[] = "(" . intval($habitId) . ", $uid, '$dateEsc', " . floatval($delta) . ", 'auto', '$createdEsc')";
        }
        foreach (array_chunk($evValues, 200) as $chunk) {
            $sql = "INSERT INTO habit_log_events (habit_id, user_id, log_date, delta, source, created_at) VALUES " . implode(',', $chunk);
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
            $eventsImported += count($chunk);
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'habits' => $createdHabits, 'days' => $daysImported, 'events' => $eventsImported], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'error' => 'Salvataggio annullato: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

/* ==========================================================================
   EXPORT — un foglio per abitudine, stesso formato del file di import,
   cosi' il giro si chiude: quello che esporti lo puoi ri-importare senza
   perdere niente.
   ========================================================================== */

if (!function_exists('hi_status_label')) {
    function hi_status_label($status) {
        switch ($status) {
            case 'done': return 'Completed';
            case 'skipped': return 'Skipped';
            case 'failed': return 'Failed';
            default: return 'Partial';
        }
    }
}

if (!function_exists('hi_num_text')) {
    function hi_num_text($n) {
        $n = (float)$n;
        return (fmod($n, 1.0) === 0.0) ? number_format($n, 0, '.', '') : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('hi_goal_text')) {
    function hi_goal_text($h) {
        if ($h['goal_type'] === 'quantity') {
            $unit = trim((string)$h['unit']);
            return hi_num_text($h['target_amount']) . ' ' . ($unit !== '' ? $unit : 'Units') . '/day';
        }
        return '1 Count/day';
    }
}

if (!function_exists('hi_freq_text')) {
    function hi_freq_text($h) {
        switch ($h['frequency_type']) {
            case 'daily':
                if ((string)$h['weekdays'] === '1111111') return 'every day';
                $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $on = [];
                for ($i = 0; $i < 7; $i++) { if (substr((string)$h['weekdays'], $i, 1) === '1') $on[] = $days[$i]; }
                return empty($on) ? 'every day' : ('every week on ' . implode(', ', $on));
            case 'weekly':
                return 'every week ' . intval($h['times_per_week']) . ' days';
            case 'monthly':
                return 'every month ' . intval($h['times_per_month']) . ' days';
            case 'interval':
                return 'every ' . intval($h['interval_days']) . ' days';
            default:
                return 'every day';
        }
    }
}

if (!function_exists('hi_basic_info_text')) {
    function hi_basic_info_text($h) {
        $status = ((int)$h['is_archived'] === 1) ? 'ARCHIVED' : 'NORMAL';
        $quote = trim((string)$h['motivation']);
        $section = trim((string)($h['category_name'] ?? ''));
        $lines = [
            'Basic Information',
            'Habit Name: ' . $h['name'],
            'Quote: ' . ($quote !== '' ? $quote : 'null'),
            'Habit Status: ' . $status,
            'Goal: ' . hi_goal_text($h),
            'Section: ' . ($section !== '' ? $section : 'Others'),
            'Reminder: ',
            'Frequency: ' . hi_freq_text($h),
        ];
        return implode("\n", $lines);
    }
}

if (!function_exists('hi_export_habit_rows')) {
    /* Solo i giorni segnati come fatti: il foglio, per spec, contiene le
       date e gli orari in cui l'abitudine e' stata fatta. */
    function hi_export_habit_rows($conn, $uid, $habitId) {
        $sql = "SELECT l.log_date, l.status, l.completions, l.mood, l.note,
                       (SELECT MIN(e.created_at) FROM habit_log_events e
                         WHERE e.habit_id = l.habit_id AND e.user_id = l.user_id AND e.log_date = l.log_date) AS ev_time
                  FROM habit_logs l
                 WHERE l.habit_id = " . intval($habitId) . " AND l.user_id = " . intval($uid) . " AND l.status = 'done'
                 ORDER BY l.log_date ASC";
        $res = mysqli_query($conn, $sql);
        $rows = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $time = '';
            if (!empty($r['ev_time'])) {
                $ts = strtotime((string)$r['ev_time']);
                if ($ts !== false) $time = date('H:i', $ts);
            }
            $rows[] = [
                (string)$r['log_date'],
                $time,
                hi_status_label($r['status']),
                (string)max(1, (int)$r['completions']),
                $r['mood'] !== null ? (string)$r['mood'] : '',
                ($r['note'] !== null && $r['note'] !== '') ? $r['note'] : '',
            ];
        }
        return $rows;
    }
}

if (!function_exists('hi_safe_sheet_name')) {
    function hi_safe_sheet_name($name, &$used) {
        $name = trim((string)$name);
        if ($name === '') $name = 'Abitudine';
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $name);
        $name = trim(mb_substr($name, 0, 31));
        if ($name === '') $name = 'Abitudine';
        $base = $name; $i = 2;
        while (isset($used[$name])) {
            $suffix = ' (' . $i . ')';
            $name = mb_substr($base, 0, max(1, 31 - mb_strlen($suffix))) . $suffix;
            $i++;
        }
        $used[$name] = true;
        return $name;
    }
}

if (!function_exists('hi_build_xlsx_multi')) {
    /* $sheets: [ ['name'=>.., 'title'=>.., 'range'=>.., 'headers'=>[...], 'rows'=>[[...]] ], ... ] */
    function hi_build_xlsx_multi($sheets) {
        $tmp = tempnam(sys_get_temp_dir(), 'hiexp');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;

        $n = count($sheets);
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $contentTypes .= '</Types>';

        $workbookSheets = ''; $wbRels = ''; $used = [];
        for ($i = 0; $i < $n; $i++) {
            $rid = 'rId' . ($i + 1);
            $safe = hi_safe_sheet_name($sheets[$i]['name'], $used);
            $workbookSheets .= '<sheet name="' . hi_xml_escape($safe) . '" sheetId="' . ($i + 1) . '" r:id="' . $rid . '"/>';
            $wbRels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $wbRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        for ($i = 0; $i < $n; $i++) {
            $s = $sheets[$i];
            $rows = $s['rows']; $headers = $s['headers'];
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="A1:F' . (3 + max(1, count($rows))) . '"/>'
                . '<cols><col min="1" max="1" width="13" customWidth="1"/><col min="2" max="2" width="9" customWidth="1"/>'
                . '<col min="3" max="3" width="13" customWidth="1"/><col min="4" max="4" width="17" customWidth="1"/>'
                . '<col min="5" max="5" width="9" customWidth="1"/><col min="6" max="6" width="36" customWidth="1"/></cols>'
                . '<sheetData>';

            $sheetXml .= '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t xml:space="preserve">' . hi_xml_escape($s['title']) . '</t></is></c></row>';
            $sheetXml .= '<row r="2"><c r="A2" t="inlineStr"><is><t>' . hi_xml_escape($s['range']) . '</t></is></c></row>';
            $sheetXml .= '<row r="3">';
            foreach ($headers as $ci => $h) {
                $sheetXml .= '<c r="' . hi_col_letter($ci) . '3" t="inlineStr" s="1"><is><t>' . hi_xml_escape($h) . '</t></is></c>';
            }
            $sheetXml .= '</row>';

            $r = 4;
            foreach ($rows as $row) {
                $sheetXml .= '<row r="' . $r . '">';
                foreach ($row as $ci => $v) {
                    $ref = hi_col_letter($ci) . $r;
                    if ($v === '' || $v === null) { $sheetXml .= '<c r="' . $ref . '"/>'; }
                    else { $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . hi_xml_escape($v) . '</t></is></c>'; }
                }
                $sheetXml .= '</row>';
                $r++;
            }
            $sheetXml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheetXml);
        }

        $zip->close();
        return $tmp;
    }
}

if ($hi_direct && isset($_GET['hi_export'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $headers = ['Date', 'Time', 'Status', 'Total check-ins', 'Mood', 'Habit Log'];
    $uid = intval($current_user_id);

    $sql = "SELECT h.*, c.name AS category_name FROM habits h LEFT JOIN habit_categories c ON c.id = h.category_id
             WHERE h.user_id = $uid ORDER BY h.is_archived ASC, h.sort_order ASC, h.id ASC";
    $res = mysqli_query($conn, $sql);
    $habits = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) { $habits[] = $row; }

    $stamp = date('Ymd');
    $wantCsv = (($_GET['fmt'] ?? '') === 'csv') || !class_exists('ZipArchive');

    if (!$wantCsv) {
        $sheets = [];
        foreach ($habits as $h) {
            $rows = hi_export_habit_rows($conn, $uid, (int)$h['id']);
            if (count($rows) === 0) continue;
            $first = $rows[0][0]; $last = $rows[count($rows) - 1][0];
            $sheets[] = [
                'name' => $h['name'],
                'title' => hi_basic_info_text($h),
                'range' => 'Date:' . $first . '~' . $last,
                'headers' => $headers,
                'rows' => $rows,
            ];
        }
        if (count($sheets) > 0) {
            $file = hi_build_xlsx_multi($sheets);
            if ($file !== null) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="Habits_' . $stamp . '.xlsx"');
                header('Content-Length: ' . filesize($file));
                header('Cache-Control: no-store');
                readfile($file);
                unlink($file);
                exit();
            }
        }
        $wantCsv = true;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Habits_' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Habit', 'Date', 'Time', 'Status', 'Total check-ins', 'Mood', 'Habit Log']);
    foreach ($habits as $h) {
        $rows = hi_export_habit_rows($conn, $uid, (int)$h['id']);
        foreach ($rows as $row) { fputcsv($out, array_merge([$h['name']], $row)); }
    }
    fclose($out);
    exit();
}

/* ==========================================================================
   MARKUP DEL POPUP (vuoto: si riempie via JS dopo l'analisi del file)
   ========================================================================== */
if (defined('HI_RENDERED')) return;
define('HI_RENDERED', true);
?>
<style>
/* --- il foglio ---------------------------------------------------------- */
.hi-overlay{ position:fixed; inset:0; z-index:9750; display:flex; align-items:flex-end; justify-content:center;
    background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
    opacity:0; pointer-events:none; transition:opacity .3s; }
.hi-overlay.show{ opacity:1; pointer-events:auto; }
.hi-sheet{ width:100%; max-width:660px; height:94vh; height:94dvh; background:var(--surface,#fff); border-radius:24px 24px 0 0;
    display:flex; flex-direction:column; transform:translateY(100%); transition:transform .4s var(--spring,ease);
    box-shadow:var(--shadow-sheet, 0 -14px 60px rgba(0,0,0,.18)); }
.hi-overlay.show .hi-sheet{ transform:translateY(0); }

.hi-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 16px 10px; flex-shrink:0;
    border-bottom:.5px solid var(--hairline,#eee); }
.hi-title{ font-size:17px; font-weight:800; letter-spacing:-.01em; }
.hi-sub{ font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:2px; }
.hi-icon-btn{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2,#eee); color:var(--text-2,#777);
    display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:transform .15s var(--spring,ease); }
.hi-icon-btn:active{ transform:scale(.88); }

.hi-body{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:14px 14px 8px; overscroll-behavior:contain; }

/* --- schermata di scelta file ------------------------------------------- */
.hi-drop{ border:2px dashed var(--hairline-2,#ccc); border-radius:var(--r-card,22px); padding:32px 20px; text-align:center;
    background:var(--surface-2,#f7f7f9); transition:border-color .25s, background-color .25s, transform .2s var(--spring,ease); }
.hi-drop.dragover{ border-color:var(--accent,#7C5CFF); background:color-mix(in srgb, var(--accent,#7C5CFF) 9%, transparent); transform:scale(1.01); }
.hi-drop-icon{ width:52px; height:52px; margin:0 auto 12px; border-radius:16px; background:color-mix(in srgb, var(--accent,#7C5CFF) 12%, transparent);
    color:var(--accent,#7C5CFF); display:flex; align-items:center; justify-content:center; }
.hi-drop-title{ font-size:16px; font-weight:800; margin-bottom:5px; }
.hi-drop-text{ font-size:13px; color:var(--text-2,#8A8A8E); line-height:1.5; }
.hi-drop-btn{ margin-top:16px; padding:12px 22px; border-radius:14px; border:none; background:var(--accent,#7C5CFF); color:#fff;
    font-size:14.5px; font-weight:700; box-shadow:var(--btn-hl), 0 6px 16px -6px color-mix(in srgb, var(--accent,#7C5CFF) 70%, transparent); transition:transform .15s var(--spring,ease); }
.hi-drop-btn:active{ transform:scale(.96); }
.hi-hint{ margin-top:16px; font-size:12px; color:var(--text-3,#AEAEB2); line-height:1.6; text-align:center; }
.hi-hint b{ color:var(--text-2,#8A8A8E); font-weight:700; }

/* --- spinner ------------------------------------------------------------ */
.hi-loading{ display:flex; flex-direction:column; align-items:center; gap:14px; padding:60px 20px; color:var(--text-2,#8A8A8E); font-size:13.5px; font-weight:600; }
.hi-spinner{ width:30px; height:30px; border-radius:50%; border:3px solid var(--hairline,#eee); border-top-color:var(--accent,#7C5CFF); animation:hiSpin .8s linear infinite; }
@keyframes hiSpin{ to{ transform:rotate(360deg); } }

/* --- riepilogo ---------------------------------------------------------- */
.hi-stats{ display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; scrollbar-width:none; }
.hi-stats::-webkit-scrollbar{ display:none; }
.hi-stat{ flex-shrink:0; background:var(--surface-2,#f2f2f7); border-radius:14px; padding:9px 13px; min-width:74px; }
.hi-stat-n{ font-size:18px; font-weight:800; letter-spacing:-.02em; line-height:1.1; }
.hi-stat-l{ font-size:10.5px; font-weight:700; color:var(--text-2,#8A8A8E); text-transform:uppercase; letter-spacing:.3px; margin-top:1px; }
.hi-warn{ display:flex; gap:8px; background:color-mix(in srgb, var(--orange,#FF9500) 12%, transparent); color:var(--orange,#FF9500);
    border-radius:12px; padding:10px 12px; font-size:12px; font-weight:600; line-height:1.45; margin-bottom:8px; }
.hi-warn svg{ flex-shrink:0; margin-top:1px; }

/* --- sezioni ------------------------------------------------------------ */
.hi-section{ margin-bottom:18px; }
.hi-sec-head{ position:sticky; top:-14px; z-index:3; display:flex; align-items:center; gap:8px; padding:10px 4px;
    background:var(--surface,#fff); cursor:pointer; }
.hi-sec-num{ width:19px; height:19px; border-radius:50%; background:var(--text,#1C1C1E); color:var(--surface,#fff);
    font-size:10.5px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.hi-sec-title{ font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; flex:1; }
.hi-sec-count{ font-size:11.5px; font-weight:700; color:var(--text-3,#AEAEB2); }
.hi-sec-chev{ color:var(--text-3,#AEAEB2); transition:transform .3s var(--spring,ease); }
.hi-section.collapsed .hi-sec-chev{ transform:rotate(-90deg); }
.hi-sec-body{ transition:opacity .25s; }
.hi-section.collapsed .hi-sec-body{ display:none; }

/* --- righe abitudine ------------------------------------------------------ */
@keyframes hiIn{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:none; } }
.hi-node{ animation:hiIn .3s var(--spring,ease) both; }
.hi-row{ display:flex; align-items:center; gap:9px; padding:10px 11px; border-radius:13px; background:var(--surface-2,#f4f4f6);
    border-left:4px solid var(--row-color,#8A8A8E); margin-bottom:5px; cursor:pointer;
    transition:transform .14s var(--spring,ease), background-color .2s; }
.hi-row:active{ transform:scale(.985); }
.hi-row.hi-row-flash{ animation:hiFlash .7s var(--spring,ease); }
@keyframes hiFlash{ 0%,100%{ background:var(--surface-2,#f4f4f6); } 30%{ background:color-mix(in srgb, var(--row-color,#8A8A8E) 22%, var(--surface-2,#f4f4f6)); } }
.hi-dot{ width:22px; height:22px; border-radius:50%; background:var(--row-color,#8A8A8E); flex-shrink:0; font-size:12px;
    display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 3px color-mix(in srgb, var(--row-color,#8A8A8E) 18%, transparent); }
.hi-names{ flex:1; min-width:0; }
.hi-name{ font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hi-from{ font-size:11px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:1px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hi-tag{ flex-shrink:0; font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; padding:3px 7px; border-radius:7px;
    background:color-mix(in srgb, var(--green,#34C759) 16%, transparent); color:var(--green,#34C759); }
.hi-restore{ flex-shrink:0; width:27px; height:27px; border-radius:50%; border:none; background:var(--surface,#fff); color:var(--text-2,#8A8A8E);
    display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.1); transition:transform .15s var(--spring,ease); }
.hi-restore:active{ transform:scale(.85) rotate(-140deg); }

/* --- righe check-in ------------------------------------------------------- */
.hi-day{ font-size:11px; font-weight:800; color:var(--text-2,#8A8A8E); text-transform:uppercase; letter-spacing:.4px; padding:12px 6px 5px; }
.hi-sess{ display:flex; align-items:center; gap:10px; padding:9px 11px; border-radius:12px; background:var(--surface-2,#f4f4f6);
    border-left:4px solid var(--row-color,#8A8A8E); margin-bottom:5px; animation:hiIn .3s var(--spring,ease) both; }
.hi-sess-icon{ width:25px; height:25px; border-radius:50%; background:var(--row-color,#8A8A8E); color:#fff; flex-shrink:0; font-size:12px;
    display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px -1px color-mix(in srgb, var(--row-color,#8A8A8E) 55%, transparent); }
.hi-sess-mid{ flex:1; min-width:0; }
.hi-sess-proj{ font-size:13.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hi-sess-proj.hi-sess-empty{ color:var(--text-3,#AEAEB2); font-weight:600; font-style:italic; }
.hi-sess-meta{ font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:1px; }
.hi-more{ width:100%; padding:11px; border-radius:12px; border:none; background:var(--surface-2,#f2f2f7); color:var(--accent,#7C5CFF);
    font-size:13px; font-weight:700; margin-top:6px; transition:transform .15s var(--spring,ease); }
.hi-more:active{ transform:scale(.97); }

/* --- barra di salvataggio ------------------------------------------------- */
.hi-foot{ flex-shrink:0; padding:12px 14px calc(12px + var(--safe-bottom,0px)); border-top:.5px solid var(--hairline,#eee);
    background:var(--surface,#fff); display:none; gap:9px; align-items:center; }
.hi-foot.show{ display:flex; }
.hi-foot-info{ flex:1; min-width:0; font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; line-height:1.35; }
.hi-btn-ghost{ padding:12px 15px; border-radius:14px; border:none; background:var(--surface-2,#f2f2f7); color:var(--text,#1C1C1E);
    font-size:14px; font-weight:700; flex-shrink:0; transition:transform .15s var(--spring,ease); }
.hi-btn-ghost:active{ transform:scale(.96); }
.hi-btn-save{ padding:12px 20px; border-radius:14px; border:none; background:var(--accent,#7C5CFF); color:#fff; font-size:14.5px; font-weight:800;
    flex-shrink:0; box-shadow:var(--btn-hl), 0 6px 16px -6px color-mix(in srgb, var(--accent,#7C5CFF) 70%, transparent); transition:transform .15s var(--spring,ease), opacity .2s; }
.hi-btn-save:active{ transform:scale(.96); }
.hi-btn-save[disabled]{ opacity:.55; }

.hi-error{ text-align:center; padding:44px 24px; }
.hi-error-icon{ width:48px; height:48px; margin:0 auto 12px; border-radius:50%; background:var(--red-soft,rgba(255,59,48,.12));
    color:var(--red,#FF3B30); display:flex; align-items:center; justify-content:center; }
.hi-error-text{ font-size:14px; color:var(--text-2,#8A8A8E); line-height:1.5; margin-bottom:16px; }

.hi-toast{ position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom,0px)); transform:translate(-50%,12px); background:rgba(28,28,30,.94); color:#fff;
    padding:11px 18px; border-radius:14px; font-size:13.5px; font-weight:600; z-index:9970; opacity:0; pointer-events:none;
    max-width:calc(100% - 40px); text-align:center; transition:opacity .25s, transform .25s; }
.hi-toast.show{ opacity:1; transform:translate(-50%,0); }

/* --- mini modale: collega ad abitudine esistente -------------------------- */
.hi-pick-overlay{ position:fixed; inset:0; z-index:9760; display:flex; align-items:flex-end; justify-content:center;
    background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
    opacity:0; pointer-events:none; transition:opacity .3s; }
.hi-pick-overlay.show{ opacity:1; pointer-events:auto; }
.hi-pick-sheet{ width:100%; max-width:520px; max-height:78vh; background:var(--surface,#fff); border-radius:24px 24px 0 0;
    display:flex; flex-direction:column; transform:translateY(100%); transition:transform .35s var(--spring,ease);
    box-shadow:var(--shadow-sheet, 0 -14px 60px rgba(0,0,0,.18)); }
.hi-pick-overlay.show .hi-pick-sheet{ transform:translateY(0); }
.hi-pick-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 16px 10px; flex-shrink:0;
    border-bottom:.5px solid var(--hairline,#eee); }
.hi-pick-title{ font-size:15.5px; font-weight:800; letter-spacing:-.01em; }
.hi-pick-search{ margin:12px 14px 8px; padding:10px 13px; border-radius:12px; border:none; background:var(--surface-2,#f2f2f7);
    color:var(--text,#1C1C1E); font-size:14px; }
.hi-pick-search:focus{ outline:none; box-shadow:0 0 0 2px color-mix(in srgb, var(--accent,#7C5CFF) 45%, transparent); }
.hi-pick-list{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:4px 14px 18px; }
.hi-pick-row{ display:flex; align-items:center; gap:11px; padding:10px 8px; border-radius:13px; cursor:pointer;
    transition:background-color .15s, transform .14s var(--spring,ease); }
.hi-pick-row:active{ transform:scale(.98); background:var(--surface-2,#f2f2f7); }
.hi-pick-icon{ width:34px; height:34px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center;
    font-size:16px; background:color-mix(in srgb, var(--pick-color,#8A8A8E) 16%, transparent); }
.hi-pick-name{ flex:1; min-width:0; font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hi-pick-arch{ flex-shrink:0; font-size:10.5px; font-weight:700; color:var(--text-3,#AEAEB2); text-transform:uppercase; letter-spacing:.3px; }
.hi-pick-empty{ text-align:center; padding:40px 20px; font-size:13px; color:var(--text-3,#AEAEB2); font-weight:600; }

@media (max-width:400px){
    .hi-foot-info{ display:none; }
    .hi-btn-save{ flex:1; }
}
@media (prefers-reduced-motion: reduce){
    .hi-overlay *, .hi-overlay, .hi-pick-overlay *, .hi-pick-overlay{ animation-duration:.01ms !important; transition-duration:.01ms !important; }
}
</style>

<div class="hi-overlay" id="hiOverlay">
    <div class="hi-sheet">
        <div class="hi-head">
            <div>
                <div class="hi-title">Importa</div>
                <div class="hi-sub" id="hiHeadSub">Abitudini e check-in da un altro file</div>
            </div>
            <button type="button" class="hi-icon-btn" onclick="closeHabitsImport()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="hi-body" id="hiBody"></div>

        <div class="hi-foot" id="hiFoot">
            <div class="hi-foot-info" id="hiFootInfo"></div>
            <button type="button" class="hi-btn-ghost" onclick="hiReset()">Cambia file</button>
            <button type="button" class="hi-btn-save" id="hiSaveBtn" onclick="hiSave()">Salva</button>
        </div>
    </div>
</div>

<input type="file" id="hiFileInput" accept=".xlsx,.xlsm,.csv,.txt,.xls" style="display:none;">

<!-- mini modale per collegare un'abitudine del file a una gia' esistente -->
<div class="hi-pick-overlay" id="hiPickOverlay">
    <div class="hi-pick-sheet">
        <div class="hi-pick-head">
            <div class="hi-pick-title">Collega ad abitudine esistente</div>
            <button type="button" class="hi-icon-btn" onclick="hiClosePicker()" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <input type="text" class="hi-pick-search" id="hiPickSearch" placeholder="Cerca un'abitudine..." oninput="hiFilterPicker()">
        <div class="hi-pick-list" id="hiPickList"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var GREY = '#8A8A8E';
    var PAGE = 80;

    var HI = {
        data: { habits: [], logs: [] },
        map: { habits: {} },     // key -> { id, name, icon, color }
        target: null,            // key dell'abitudine che ha aperto il selettore
        shown: PAGE,
        busy: false
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }
    function toast(msg) {
        var t = document.getElementById('hiToast');
        if (!t) { t = document.createElement('div'); t.id = 'hiToast'; t.className = 'hi-toast'; document.body.appendChild(t); }
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._timer); t._timer = setTimeout(function () { t.classList.remove('show'); }, 2400);
    }
    function byId(id) { return document.getElementById(id); }

    function fmtTime(dateStr, timeStr) {
        if (!timeStr) return null;
        var d = new Date(dateStr + 'T' + timeStr);
        if (isNaN(d.getTime())) return null;
        return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    }
    function fmtDay(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
    }
    function existingHabits() { return (window.HB_BOOT && window.HB_BOOT.habits) || []; }
    function findHabit(key) {
        for (var i = 0; i < HI.data.habits.length; i++) if (HI.data.habits[i].key === key) return HI.data.habits[i];
        return null;
    }

    /* ======================================================================
       APERTURA / CHIUSURA
       ====================================================================== */
    window.openHabitsImport = function () {
        var ov = byId('hiOverlay');
        if (!HI.data.habits.length) hiRenderDrop();
        ov.classList.add('show');
        buzz(6);
    };
    window.closeHabitsImport = function () {
        byId('hiOverlay').classList.remove('show');
    };

    window.exportHabitsFile = function () {
        var a = document.createElement('a');
        a.href = 'habits_import.php?hi_export=1';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        toast('Export in corso...');
        buzz(8);
    };

    /* ======================================================================
       SCHERMATE
       ====================================================================== */
    function hiRenderDrop() {
        byId('hiFoot').classList.remove('show');
        byId('hiHeadSub').textContent = 'Abitudini e check-in da un altro file';
        byId('hiBody').innerHTML =
            '<div class="hi-drop" id="hiDrop">' +
                '<div class="hi-drop-icon"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5-5 5 5"/><path d="M12 5v13"/></svg></div>' +
                '<div class="hi-drop-title">Scegli il file da importare</div>' +
                '<div class="hi-drop-text">Trascinalo qui oppure sfoglia.<br>Formati: .xlsx e .csv</div>' +
                '<button type="button" class="hi-drop-btn" onclick="hiBrowse()">Sfoglia file</button>' +
            '</div>' +
            '<div class="hi-hint">Ogni <b>foglio</b> e\' un\'abitudine (il nome del foglio ne diventa il nome). Dentro servono le <b>date</b> dei check-in, e possibilmente l\'<b>ora</b>.<br>Le colonne le riconosco dal nome; se mancano uso l\'ordine standard: Data, Ora, Stato, Check-in, Umore, Nota.</div>';
        hiBindDrop();
    }

    function hiRenderLoading(msg) {
        byId('hiFoot').classList.remove('show');
        byId('hiBody').innerHTML = '<div class="hi-loading"><div class="hi-spinner"></div><div>' + esc(msg || 'Leggo il file...') + '</div></div>';
    }

    function hiRenderError(msg) {
        byId('hiFoot').classList.remove('show');
        byId('hiBody').innerHTML =
            '<div class="hi-error">' +
                '<div class="hi-error-icon"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg></div>' +
                '<div class="hi-error-text">' + esc(msg) + '</div>' +
                '<button type="button" class="hi-drop-btn" onclick="hiReset()">Riprova</button>' +
            '</div>';
    }

    window.hiBrowse = function () { byId('hiFileInput').click(); };
    window.hiReset = function () {
        HI.data = { habits: [], logs: [] };
        HI.map = { habits: {} };
        HI.shown = PAGE;
        byId('hiFileInput').value = '';
        hiRenderDrop();
    };

    function hiBindDrop() {
        var d = byId('hiDrop');
        if (!d) return;
        ['dragenter', 'dragover'].forEach(function (ev) {
            d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.remove('dragover'); });
        });
        d.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) hiUpload(e.dataTransfer.files[0]);
        });
    }

    /* ======================================================================
       CARICAMENTO E ANALISI
       ====================================================================== */
    function hiUpload(file) {
        hiRenderLoading('Leggo ' + file.name + '...');
        var fd = new FormData();
        fd.append('file', file);
        fetch('habits_import.php?hi_ajax=parse', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { hiRenderError((d && d.error) || 'File non riconosciuto.'); return; }
                HI.data = { habits: d.habits || [], logs: d.logs || [] };
                HI.map = { habits: {} };
                HI.shown = PAGE;
                hiRenderResult(d);
                buzz(10);
            })
            .catch(function () { hiRenderError('Errore di rete durante il caricamento.'); });
    }

    function hiRenderResult(d) {
        var s = d.stats || {};
        var warn = '';
        (d.warnings || []).forEach(function (w) {
            warn += '<div class="hi-warn"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><span>' + esc(w) + '</span></div>';
        });

        byId('hiHeadSub').textContent = (s.habits || 0) + ' abitudini, ' + (s.logs || 0) + ' check-in trovati';
        byId('hiBody').innerHTML =
            '<div class="hi-stats">' +
                hiStat(s.habits || 0, 'Abitudini') +
                hiStat(s.logs || 0, 'Check-in') +
                (s.skipped ? hiStat(s.skipped, 'Ignorati') : '') +
            '</div>' + warn +
            '<div class="hi-section" id="hiSec1">' +
                '<div class="hi-sec-head" onclick="hiToggleSec(\'hiSec1\')">' +
                    '<span class="hi-sec-num">1</span><span class="hi-sec-title">Abitudini</span>' +
                    '<span class="hi-sec-count" id="hiSec1Count"></span>' +
                    '<svg class="hi-sec-chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>' +
                '</div>' +
                '<div class="hi-sec-body" id="hiHabits"></div>' +
            '</div>' +
            '<div class="hi-section" id="hiSec2">' +
                '<div class="hi-sec-head" onclick="hiToggleSec(\'hiSec2\')">' +
                    '<span class="hi-sec-num">2</span><span class="hi-sec-title">Check-in</span>' +
                    '<span class="hi-sec-count" id="hiSec2Count"></span>' +
                    '<svg class="hi-sec-chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>' +
                '</div>' +
                '<div class="hi-sec-body" id="hiLogs"></div>' +
            '</div>';

        byId('hiFoot').classList.add('show');
        byId('hiSaveBtn').disabled = false;
        byId('hiSaveBtn').textContent = 'Salva';
        hiRenderHabits();
        hiRenderLogs();
    }

    function hiStat(n, label) {
        return '<div class="hi-stat"><div class="hi-stat-n">' + n + '</div><div class="hi-stat-l">' + label + '</div></div>';
    }
    window.hiToggleSec = function (id) { byId(id).classList.toggle('collapsed'); };

    /* ======================================================================
       SEZIONE 1 — abitudini
       ====================================================================== */
    function hiRowHTML(h) {
        var m = HI.map.habits[h.key];
        var color = m ? m.color : GREY;
        var icon = m ? (m.icon || '⭐️') : '＋';
        var name = m ? m.name : h.name;
        var row = '<div class="hi-row" style="--row-color:' + esc(color) + ';" data-row="' + h.key + '" onclick="hiPick(\'' + h.key + '\')">' +
            '<span class="hi-dot">' + esc(icon) + '</span>' +
            '<div class="hi-names">' +
                '<div class="hi-name">' + esc(name) + '</div>' +
                (m ? '<div class="hi-from">dal file: ' + esc(h.name) + '</div>' : '') +
            '</div>';
        if (!m) row += '<span class="hi-tag">nuova</span>';
        else row += '<button type="button" class="hi-restore" aria-label="Ripristina" onclick="event.stopPropagation();hiRestore(\'' + h.key + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>';
        return row + '</div>';
    }

    function hiRenderHabits() {
        var html = '';
        HI.data.habits.forEach(function (h) { html += '<div class="hi-node">' + hiRowHTML(h) + '</div>'; });
        if (!html) html = '<div class="hi-pick-empty">Nessuna abitudine nel file.</div>';
        byId('hiHabits').innerHTML = html;

        var tot = HI.data.habits.length;
        var nuove = 0;
        HI.data.habits.forEach(function (h) { if (!HI.map.habits[h.key]) nuove++; });
        byId('hiSec1Count').textContent = nuove + ' nuove su ' + tot;
        hiUpdateFoot();
    }

    /* ======================================================================
       SEZIONE 2 — check-in
       ====================================================================== */
    function hiLogHabit(l) {
        var m = HI.map.habits[l.habitKey], h = findHabit(l.habitKey);
        return { name: m ? m.name : (h ? h.name : ''), color: m ? m.color : GREY, icon: m ? (m.icon || '⭐️') : '⭐️', isNew: !m };
    }

    function hiRenderLogs() {
        var list = HI.data.logs;
        var n = Math.min(HI.shown, list.length);
        var html = ''; var lastDay = null;

        for (var i = 0; i < n; i++) {
            var l = list[i];
            if (l.date !== lastDay) { lastDay = l.date; html += '<div class="hi-day">' + esc(fmtDay(l.date)) + '</div>'; }
            var h = hiLogHabit(l);
            var time = fmtTime(l.date, l.time);
            html += '<div class="hi-sess" style="--row-color:' + esc(h.color) + ';">' +
                '<span class="hi-sess-icon">' + esc(h.icon) + '</span>' +
                '<div class="hi-sess-mid">' +
                    '<div class="hi-sess-proj">' + esc(h.name) + '</div>' +
                    '<div class="hi-sess-meta">' + (time ? esc(time) : 'ora non indicata') + (l.note ? ' · ' + esc(l.note) : '') + '</div>' +
                '</div>' +
                (h.isNew ? '<span class="hi-tag">nuova</span>' : '') +
            '</div>';
        }
        if (n < list.length) {
            html += '<button type="button" class="hi-more" onclick="hiMore()">Mostra altri ' + Math.min(PAGE, list.length - n) + ' di ' + (list.length - n) + '</button>';
        }
        byId('hiLogs').innerHTML = html;
        byId('hiSec2Count').textContent = list.length + (list.length === 1 ? ' check-in' : ' check-in');
    }
    window.hiMore = function () { HI.shown += PAGE; hiRenderLogs(); };

    function hiUpdateFoot() {
        var nuove = 0;
        HI.data.habits.forEach(function (h) { if (!HI.map.habits[h.key]) nuove++; });
        byId('hiFootInfo').innerHTML = '<b>' + nuove + '</b> abitudin' + (nuove === 1 ? 'e nuova' : 'i nuove') + '<br>' + HI.data.logs.length + ' check-in da salvare';
    }

    /* ======================================================================
       COLLEGA A UN'ABITUDINE ESISTENTE — mini modale indipendente
       ====================================================================== */
    window.hiPick = function (key) {
        HI.target = key;
        var list = existingHabits();
        byId('hiPickSearch').value = '';
        hiRenderPickerList(list);
        byId('hiPickOverlay').classList.add('show');
        buzz(6);
    };
    window.hiClosePicker = function () {
        byId('hiPickOverlay').classList.remove('show');
        HI.target = null;
    };
    window.hiFilterPicker = function () {
        var q = (byId('hiPickSearch').value || '').toLowerCase().trim();
        var list = existingHabits().filter(function (h) { return !q || (h.name || '').toLowerCase().indexOf(q) !== -1; });
        hiRenderPickerList(list);
    };
    function hiRenderPickerList(list) {
        if (!list.length) { byId('hiPickList').innerHTML = '<div class="hi-pick-empty">Nessuna abitudine trovata.</div>'; return; }
        var html = '';
        list.forEach(function (h) {
            html += '<div class="hi-pick-row" style="--pick-color:' + esc(h.color || GREY) + ';" onclick="hiChoose(' + h.id + ')">' +
                '<span class="hi-pick-icon" style="color:' + esc(h.color || GREY) + ';">' + esc(h.icon || '⭐️') + '</span>' +
                '<span class="hi-pick-name">' + esc(h.name) + '</span>' +
                (h.is_archived ? '<span class="hi-pick-arch">archiviata</span>' : '') +
            '</div>';
        });
        byId('hiPickList').innerHTML = html;
    }
    window.hiChoose = function (id) {
        var key = HI.target;
        if (!key) return;
        var h = existingHabits().filter(function (x) { return x.id === id; })[0];
        if (!h) return;
        HI.map.habits[key] = { id: id, name: h.name, icon: h.icon, color: h.color || GREY };
        HI.target = null;
        window.hiClosePicker();
        buzz(10);
        hiRenderHabits();
        hiRenderLogs();
        hiFlash(key);
    };
    window.hiRestore = function (key) {
        delete HI.map.habits[key];
        buzz(6);
        hiRenderHabits();
        hiRenderLogs();
        hiFlash(key);
    };
    function hiFlash(key) {
        var el = document.querySelector('.hi-row[data-row="' + key + '"]');
        if (!el) return;
        el.classList.add('hi-row-flash');
        setTimeout(function () { el.classList.remove('hi-row-flash'); }, 700);
    }

    /* ======================================================================
       SALVATAGGIO
       ====================================================================== */
    window.hiSave = function () {
        if (HI.busy || !HI.data.logs.length) return;
        HI.busy = true;
        var btn = byId('hiSaveBtn');
        btn.disabled = true; btn.textContent = 'Salvo...';

        var payload = {
            habits: HI.data.habits.map(function (h) {
                return { key: h.key, name: h.name, mapped: HI.map.habits[h.key] ? HI.map.habits[h.key].id : 0 };
            }),
            logs: HI.data.logs.map(function (l) {
                return { habitKey: l.habitKey, date: l.date, time: l.time, count: l.count, mood: l.mood, note: l.note };
            })
        };

        fetch('habits_import.php?hi_ajax=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'payload=' + encodeURIComponent(JSON.stringify(payload))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            HI.busy = false;
            if (!d || !d.success) {
                btn.disabled = false; btn.textContent = 'Salva';
                toast((d && d.error) || 'Errore durante il salvataggio');
                return;
            }
            buzz(20);
            byId('hiFoot').classList.remove('show');
            byId('hiHeadSub').textContent = 'Import completato';
            byId('hiBody').innerHTML =
                '<div class="hi-error">' +
                    '<div class="hi-error-icon" style="background:var(--green-soft,rgba(52,199,89,.13));color:var(--green,#34C759);">' +
                        '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>' +
                    '<div class="hi-drop-title">Fatto!</div>' +
                    '<div class="hi-error-text">' + d.habits + ' abitudini create<br>' + d.days + ' giorni importati<br>' + d.events + ' check-in registrati</div>' +
                    '<button type="button" class="hi-drop-btn" onclick="location.reload()">Chiudi e aggiorna</button>' +
                '</div>';
            setTimeout(function () { location.reload(); }, 1800);
        })
        .catch(function () {
            HI.busy = false;
            btn.disabled = false; btn.textContent = 'Salva';
            toast('Errore di rete durante il salvataggio');
        });
    };

    /* ====================================================================== */
    byId('hiFileInput').addEventListener('change', function () {
        if (this.files && this.files[0]) hiUpload(this.files[0]);
    });
    byId('hiOverlay').addEventListener('click', function (e) {
        if (e.target === this) window.closeHabitsImport();
    });
    byId('hiPickOverlay').addEventListener('click', function (e) {
        if (e.target === this) window.hiClosePicker();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var po = byId('hiPickOverlay');
        if (po.classList.contains('show')) { window.hiClosePicker(); return; }
        var ov = byId('hiOverlay');
        if (ov.classList.contains('show')) window.closeHabitsImport();
    });
})();
</script>
