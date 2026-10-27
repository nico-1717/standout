<?php
/* ==========================================================================
   POMODORO IMPORT / EXPORT — popup riutilizzabile per importare pomodori e
   progetti da altre app (file .xlsx o .csv) e per esportare tutto lo storico
   nello stesso formato.

   Include in pomodoro_storico.php (che a sua volta e' incluso da progetti.php
   e pomodoro.php). Tutta l'interazione avviene via fetch/JSON su questo
   stesso file, esattamente come fa pomodoro_storico.php.

   Uso:
     include __DIR__ . '/pomodoro_import.php';   // renderizza il popup (vuoto)
     window.openPomodoroImport();     // apre in JS
     window.exportPomodoroFile();     // scarica l'export

   FORMATO ATTESO DEL FILE (riconosciuto per NOME di colonna, con fallback
   sulle posizioni): StartTime (col. 2), Type (pomodoro/cronometro),
   Duration, Title (col. 5 = sotto-progetto), List (col. 8 = progetto padre).

   Nessuna dipendenza esterna: .xlsx letto/scritto a mano con ZipArchive +
   SimpleXML, .csv con fgetcsv.
   ========================================================================== */

/* Gli endpoint qui sotto devono rispondere SOLO quando il file viene chiamato
   direttamente. Senza questo controllo un banale progetti.php?ie_export=1
   scaricherebbe i byte dell'xlsx in mezzo all'HTML della pagina, perche' il
   file e' incluso in fondo a progetti.php e pomodoro.php. */
$ie_files = get_included_files();
$ie_direct = (!empty($ie_files) && realpath($ie_files[0]) === realpath(__FILE__));

if (!isset($conn) || !$conn) {
    require_once __DIR__ . '/../../lib/config.php';
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($ie_direct && isset($_GET['ie_ajax'])) { require_once __DIR__ . '/../../lib/auth_check_api.php'; }
    else { require_once __DIR__ . '/../../lib/auth_check.php'; }
}
if (!isset($current_user_id)) {
    $current_user_id = isset($utente_corrente['id']) ? $utente_corrente['id'] : 0;
}

/* ==========================================================================
   HELPER GENERICI
   ========================================================================== */

if (!function_exists('ie_norm')) {
    /* "Duration (min)" -> "durationmin" : per confrontare le intestazioni
       senza impazzire con spazi, maiuscole, parentesi e accenti. */
    function ie_norm($s) {
        $s = (string)$s;
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = strtr($s, ['à'=>'a','á'=>'a','è'=>'e','é'=>'e','ì'=>'i','í'=>'i','ò'=>'o','ó'=>'o','ù'=>'u','ú'=>'u']);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return $s === null ? '' : $s;
    }
}

if (!function_exists('ie_col_index')) {
    /* "BC12" -> 54 (indice 0-based della colonna) */
    function ie_col_index($ref) {
        if (!preg_match('/^([A-Za-z]+)/', (string)$ref, $m)) return -1;
        $letters = strtoupper($m[1]);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) { $n = $n * 26 + (ord($letters[$i]) - 64); }
        return $n - 1;
    }
}

if (!function_exists('ie_col_letter')) {
    /* 0 -> "A", 26 -> "AA" */
    function ie_col_letter($i) {
        $i = (int)$i; $s = '';
        while ($i >= 0) { $s = chr(65 + ($i % 26)) . $s; $i = intdiv($i, 26) - 1; }
        return $s;
    }
}

if (!function_exists('ie_excel_epoch')) {
    /* Base del calendario Excel espressa nel fuso locale del server: usando la
       stessa base sia in lettura che in scrittura gli offset si annullano e le
       date restano identiche a quelle scritte nel foglio. */
    function ie_excel_epoch() {
        static $base = null;
        if ($base === null) $base = strtotime('1970-01-01 00:00:00');
        return $base;
    }
}

if (!function_exists('ie_serial_to_datetime')) {
    /* Numero seriale Excel -> "Y-m-d H:i:s" */
    function ie_serial_to_datetime($serial) {
        $ts = (int)round(((float)$serial - 25569) * 86400) + ie_excel_epoch();
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('ie_datetime_to_serial')) {
    /* "Y-m-d H:i:s" -> numero seriale Excel */
    function ie_datetime_to_serial($dt) {
        $ts = strtotime($dt);
        if ($ts === false) return 0;
        return 25569 + ($ts - ie_excel_epoch()) / 86400;
    }
}

/* ==========================================================================
   LETTURA .XLSX (ZipArchive + SimpleXML, zero dipendenze)
   Ogni cella viene restituita come ['v' => valore leggibile, 'raw' => valore
   grezzo, 'date' => true se la cella e' formattata come data/ora]. Serve
   distinguere: lo stesso 0,017361 e' "25 minuti" in Duration e "00:25 del
   31/12/1899" se lo interpretassimo come data.
   ========================================================================== */

if (!function_exists('ie_xlsx_shared_strings')) {
    function ie_xlsx_shared_strings($zip) {
        $out = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return $out;
        $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$sx) return $out;
        foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } else {
                foreach ($si->r as $r) { $text .= (string)$r->t; }
            }
            $out[] = $text;
        }
        return $out;
    }
}

if (!function_exists('ie_xlsx_date_styles')) {
    /* Restituisce l'elenco degli indici di stile (attributo s="") che
       corrispondono a un formato data/ora. */
    function ie_xlsx_date_styles($zip) {
        $isDate = [];
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) return $isDate;
        $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$sx) return $isDate;

        $customFmts = [];
        if (isset($sx->numFmts)) {
            foreach ($sx->numFmts->numFmt as $nf) {
                $customFmts[(int)$nf['numFmtId']] = (string)$nf['formatCode'];
            }
        }
        $builtinDate = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];

        $i = 0;
        if (isset($sx->cellXfs)) {
            foreach ($sx->cellXfs->xf as $xf) {
                $fmtId = isset($xf['numFmtId']) ? (int)$xf['numFmtId'] : 0;
                $date = in_array($fmtId, $builtinDate, true);
                if (!$date && isset($customFmts[$fmtId])) {
                    $code = $customFmts[$fmtId];
                    $code = preg_replace('/\[[^\]]*\]/', '', $code); // [Red], [h]...
                    $code = preg_replace('/"[^"]*"/', '', $code);    // testi letterali
                    if ($code !== null && preg_match('/[ymdhs]/i', $code)) $date = true;
                }
                if ($date) $isDate[$i] = true;
                $i++;
            }
        }
        return $isDate;
    }
}

if (!function_exists('ie_xlsx_first_sheet')) {
    function ie_xlsx_first_sheet($zip) {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($xml !== false) return $xml;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                return $zip->getFromName($name);
            }
        }
        return false;
    }
}

if (!function_exists('ie_read_xlsx')) {
    function ie_read_xlsx($path, &$error) {
        if (!class_exists('ZipArchive')) {
            $error = t('import.zip_mancante');
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $error = t('import.xlsx_non_valido');
            return null;
        }
        /* PERCHE': un .xlsx e' uno zip, e uno zip di pochi KB puo' "gonfiarsi" in
           centinaia di MB (zip bomb) e far esaurire la memoria. Si guarda la
           dimensione DOPO la decompressione dei tre file che leggiamo e, se
           e' esagerata, ci si ferma prima di caricarli. */
        $tot = 0;
        foreach (['xl/sharedStrings.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $nomeInterno) {
            $st = $zip->statName($nomeInterno);
            if ($st !== false) $tot += (int)$st['size'];
        }
        if ($tot > 40 * 1024 * 1024) {
            $zip->close();
            $error = t('import.troppo_grande');
            return null;
        }
        $shared = ie_xlsx_shared_strings($zip);
        $dateStyles = ie_xlsx_date_styles($zip);
        $sheetXml = ie_xlsx_first_sheet($zip);
        $zip->close();

        if ($sheetXml === false) { $error = t('import.nessun_foglio'); return null; }
        $sx = @simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$sx || !isset($sx->sheetData)) { $error = t('import.illeggibile'); return null; }

        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $col = ie_col_index((string)$c['r']);
                if ($col < 0) { $col = $maxCol + 1; }
                $t = isset($c['t']) ? (string)$c['t'] : '';
                $s = isset($c['s']) ? (int)$c['s'] : 0;
                $raw = isset($c->v) ? (string)$c->v : '';
                $val = '';
                $isDate = false;

                if ($t === 's') {
                    $idx = (int)$raw;
                    $val = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($t === 'inlineStr') {
                    if (isset($c->is->t)) {
                        $val = (string)$c->is->t;
                    } elseif (isset($c->is->r)) {
                        foreach ($c->is->r as $r) { $val .= (string)$r->t; }
                    }
                    $raw = $val;
                } elseif ($t === 'str') {
                    $val = $raw;
                } elseif ($t === 'b') {
                    $val = ($raw === '1') ? 'TRUE' : 'FALSE';
                } elseif ($t === 'e') {
                    $val = '';
                } else {
                    if ($raw !== '' && is_numeric($raw) && isset($dateStyles[$s])) {
                        $isDate = true;
                        $val = ie_serial_to_datetime($raw);
                    } else {
                        $val = $raw;
                    }
                }
                $cells[$col] = ['v' => $val, 'raw' => $raw, 'date' => $isDate];
                if ($col > $maxCol) $maxCol = $col;
            }
            // riempie i buchi cosi' gli indici di colonna restano allineati
            $flat = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $flat[] = isset($cells[$i]) ? $cells[$i] : ['v' => '', 'raw' => '', 'date' => false];
            }
            $rows[] = $flat;
        }
        return $rows;
    }
}

/* ==========================================================================
   LETTURA .CSV (delimitatore e codifica riconosciuti da soli)
   ========================================================================== */

if (!function_exists('ie_read_csv')) {
    function ie_read_csv($path, &$error) {
        $raw = file_get_contents($path);
        if ($raw === false) { $error = t('import.illeggibile'); return null; }
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
        if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $raw = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')
                : utf8_encode($raw);
        }

        /* Il delimitatore si conta su piu' righe: la prima spesso e' solo un
           titolo ("Date:... ~ ...") e non contiene separatori. */
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $seen = 0;
        foreach (preg_split("/\r\n|\n|\r/", $raw, 12) as $line) {
            if (trim($line) === '') continue;
            foreach ($counts as $d => $_) { $counts[$d] += substr_count($line, $d); }
            if (++$seen >= 6) break;
        }
        if ($seen === 0) { $error = t('import.file_vuoto'); return null; }
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

if (!function_exists('ie_header_aliases')) {
    /* L'ordine dentro ogni elenco e' una scala di priorita': con le colonne
       "Date" e "StartTime" affiancate vince StartTime, che ha anche l'ora. */
    function ie_header_aliases() {
        return [
            'start' => ['starttime','startdatetime','startdate','start','datetimestart','inizio','datainizio','oradiinizio','begin','datetime','when','createdat','date','data'],
            'end'   => ['endtime','enddatetime','enddate','end','fine','datafine','finish','completedat'],
            /* La colonna data a se' stante, per i file scritti a mano: una
               colonna "data" e due colonne "inizio"/"fine" con la sola ora.
               Viene DOPO start ed end apposta: se il file ha una sola colonna
               con data e ora insieme, quella se la prende start (che ha 'date'
               e 'data' in fondo ai suoi alias) e qui non resta niente da
               prendere — che e' il comportamento giusto. */
            'date'  => ['data','date','giorno','day','datasessione','dataseduta'],
            'type'  => ['type','tipo','kind','sessiontype','tiposessione','modalita','mode'],
            'dur'   => ['duration','durata','durationmin','durationmins','durationminute','durationminutes','durationsec','durationseconds','minutes','minuti','mins','min','length','lunghezza','elapsed','tempo','time'],
            'title' => ['title','titolo','task','taskname','name','nome','activity','attivita','subject','item','todo'],
            'list'  => ['list','lista','project','progetto','folder','cartella','group','gruppo','parent','padre','category','categoria'],
        ];
    }
}

if (!function_exists('ie_positional_cols')) {
    /* Posizioni standard del formato (1-based): 2=StartTime, 3=EndTime,
       4=Type, 5=Title, 6=Duration, 8=List. */
    function ie_positional_cols() {
        return ['start' => 1, 'end' => 2, 'type' => 3, 'dur' => 5, 'title' => 4, 'list' => 7];
    }
}

if (!function_exists('ie_detect_columns')) {
    /* Prima passata: confronto esatto, alias per alias (cosi' "StartTime"
       batte "Date"). Seconda: confronto per prefisso ("Duration (min)").
       Terza: posizioni standard per i campi rimasti scoperti. */
    function ie_detect_columns($headerRow, &$matched) {
        $aliases = ie_header_aliases();
        /* 'date' non compare in ie_positional_cols(): non ha una posizione
           standard nel formato esportato, esiste solo se il file la nomina. */
        $cols = ['start' => -1, 'end' => -1, 'date' => -1, 'type' => -1, 'dur' => -1, 'title' => -1, 'list' => -1];
        $norms = [];
        foreach ($headerRow as $i => $cell) { $norms[$i] = ie_norm($cell['v']); }

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

        foreach (ie_positional_cols() as $key => $pos) {
            if ($cols[$key] !== -1) continue;
            if (in_array($pos, $cols, true)) continue;
            $cols[$key] = $pos;
        }

        return $cols;
    }
}

if (!function_exists('ie_find_header')) {
    /* L'intestazione non e' per forza la prima riga: questi export spesso
       iniziano con un titolo ("Date:2024-02-22 ~ 2026-06-29") e una riga
       vuota. Guardiamo le prime righe e teniamo quella che riconosce piu'
       colonne. Torna l'indice della riga di intestazione, -1 se non c'e'. */
    function ie_find_header($rows, &$cols, &$score) {
        $limit = min(count($rows), 20);
        $bestIdx = -1; $bestScore = 0; $bestCols = null;
        for ($i = 0; $i < $limit; $i++) {
            $sc = 0;
            $c = ie_detect_columns($rows[$i], $sc);
            if ($sc > $bestScore) { $bestScore = $sc; $bestIdx = $i; $bestCols = $c; }
            if ($sc >= 5) break;
        }
        if ($bestScore >= 2) { $cols = $bestCols; $score = $bestScore; return $bestIdx; }
        $cols = ie_positional_cols(); $score = 0;
        return -1;
    }
}

if (!function_exists('ie_clean_name')) {
    /* Questi export scrivono "/" al posto delle celle vuote. */
    function ie_clean_name($v) {
        $s = trim((string)$v);
        return ($s === '/' || $s === '\\') ? '' : $s;
    }
}

if (!function_exists('ie_row_empty')) {
    function ie_row_empty($r) {
        foreach ($r as $c) { if (trim((string)$c['v']) !== '') return false; }
        return true;
    }
}

/* ==========================================================================
   PARSING DEI VALORI
   ========================================================================== */

if (!function_exists('ie_detect_date_order')) {
    /* Con date tipo 03/07/2025 non si puo' sapere se e' 3 luglio o 7 marzo.
       Guardando TUTTA la colonna pero' basta un solo valore > 12 in una delle
       due posizioni per sciogliere il dubbio per tutte le righe. */
    function ie_detect_date_order($samples) {
        $firstOver12 = false; $secondOver12 = false;
        foreach ($samples as $s) {
            if (!preg_match('#^\s*(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{1,4})#', $s, $m)) continue;
            if (strlen($m[1]) === 4) return 'ymd';
            if ((int)$m[1] > 12) $firstOver12 = true;
            if ((int)$m[2] > 12) $secondOver12 = true;
        }
        if ($firstOver12 && !$secondOver12) return 'dmy';
        if ($secondOver12 && !$firstOver12) return 'mdy';
        return null; // ambiguo
    }
}

if (!function_exists('ie_parse_datetime')) {
    function ie_parse_datetime($cell, $order) {
        if (!empty($cell['date'])) return $cell['v']; // cella data vera di Excel
        $s = trim((string)$cell['v']);
        if ($s === '') return null;

        // seriale Excel nudo (CSV esportato male)
        if (is_numeric($s) && (float)$s > 20000 && (float)$s < 90000) {
            return ie_serial_to_datetime($s);
        }
        // ISO: 2025-07-03 10:30(:00) oppure 2025-07-03T10:30
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})[T ]?(\d{1,2})?:?(\d{2})?:?(\d{2})?#', $s, $m)) {
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3],
                isset($m[4]) ? (int)$m[4] : 0, isset($m[5]) ? (int)$m[5] : 0, isset($m[6]) ? (int)$m[6] : 0);
        }
        // con separatori / . -
        if (preg_match('#^(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{2,4})[T ]*(\d{1,2})?:?(\d{2})?:?(\d{2})?\s*(am|pm|AM|PM)?#', $s, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; $c = (int)$m[3];
            if (strlen($m[1]) === 4) { $y = $a; $mo = $b; $d = $c; }
            else {
                $y = $c < 100 ? 2000 + $c : $c;
                $use = $order;
                if ($use === null || $use === 'ymd') { $use = ($a > 12) ? 'dmy' : (($b > 12) ? 'mdy' : 'dmy'); }
                if ($use === 'mdy') { $mo = $a; $d = $b; } else { $d = $a; $mo = $b; }
            }
            $h = isset($m[4]) ? (int)$m[4] : 0;
            $mi = isset($m[5]) ? (int)$m[5] : 0;
            $se = isset($m[6]) ? (int)$m[6] : 0;
            $ap = isset($m[7]) ? strtolower($m[7]) : '';
            if ($ap === 'pm' && $h < 12) $h += 12;
            if ($ap === 'am' && $h === 12) $h = 0;
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $se);
        }
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('ie_parse_ora_sola')) {
    /* Secondi dalla mezzanotte se la cella contiene SOLO un'ora, altrimenti
       null.

       Serve perche' una cella "10:38" letta come data diventa il 30 dicembre
       1899: e' l'origine del calendario di Excel, cioe' il giorno zero a cui
       si somma la frazione di giornata. Era quello che si vedeva nell'anteprima
       dell'import. Con la data in una colonna a parte, il pezzo utile di questa
       cella e' solo l'ora, e va ricongiunto alla sua data.

       Due strade perche' due sono i modi in cui arriva: il foglio Excel manda
       un numero sotto l'uno (0,4430 = le 10:38), il CSV manda il testo. */
    function ie_parse_ora_sola($cell) {
        if (!empty($cell['date']) && is_numeric($cell['raw'])) {
            $f = (float)$cell['raw'];
            if ($f >= 0 && $f < 1) return (int)round($f * 86400);
            return null;   // ha una parte intera: e' una data vera, non un'ora
        }
        $s = trim((string)$cell['v']);
        if ($s === '') return null;
        if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?\s*(am|pm)?$/i', $s, $m)) return null;
        $h = (int)$m[1];
        $ap = isset($m[4]) ? strtolower($m[4]) : '';
        if ($ap === 'pm' && $h < 12) $h += 12;
        if ($ap === 'am' && $h === 12) $h = 0;
        if ($h > 23) return null;
        return $h * 3600 + (int)$m[2] * 60 + (isset($m[3]) ? (int)$m[3] : 0);
    }
}

if (!function_exists('ie_unisci_data_ora')) {
    /* "2026-08-18 00:00:00" + 38280 secondi -> "2026-08-18 10:38:00" */
    function ie_unisci_data_ora($giorno, $secondi) {
        $ts = strtotime($giorno);
        if ($ts === false) return null;
        return date('Y-m-d', $ts) . ' ' . gmdate('H:i:s', $secondi);
    }
}

if (!function_exists('ie_parse_duration')) {
    /* Restituisce SECONDI. Gestisce: celle ora di Excel (0,0173 = 25 min),
       "25", "25 min", "1:30:00", "1h 30m", "90s". L'unita' dei numeri nudi
       viene dedotta dall'intestazione ("Duration (sec)" -> secondi), con i
       minuti come default (e' quello che usano gli export di queste app). */
    function ie_parse_duration($cell, $headerNorm, $twoPart = 'ms') {
        if (!empty($cell['date']) && is_numeric($cell['raw'])) {
            $frac = (float)$cell['raw'];
            if ($frac < 1) return (int)round($frac * 86400);
            return (int)round(($frac - floor($frac)) * 86400);
        }
        $s = trim((string)$cell['v']);
        if ($s === '') return 0;
        $s = str_replace(',', '.', $s);

        if (strpos($s, ':') !== false) {
            $p = array_map('floatval', explode(':', $s));
            if (count($p) === 3) return (int)round($p[0] * 3600 + $p[1] * 60 + $p[2]);
            if (count($p) === 2) {
                return ($twoPart === 'hm')
                    ? (int)round($p[0] * 3600 + $p[1] * 60)   // 1:30 = un'ora e mezza
                    : (int)round($p[0] * 60 + $p[1]);         // 50:00 = cinquanta minuti
            }
        }
        if (preg_match('/[hms]/i', $s) && !is_numeric($s)) {
            $sec = 0; $found = false;
            if (preg_match('/([\d.]+)\s*h/i', $s, $m)) { $sec += (float)$m[1] * 3600; $found = true; }
            if (preg_match('/([\d.]+)\s*m(?!s)/i', $s, $m)) { $sec += (float)$m[1] * 60; $found = true; }
            if (preg_match('/([\d.]+)\s*s/i', $s, $m)) { $sec += (float)$m[1]; $found = true; }
            if ($found) return (int)round($sec);
        }
        if (preg_match('/-?[\d.]+/', $s, $m)) {
            $n = (float)$m[0];
            if (strpos($headerNorm, 'sec') !== false) return (int)round($n);
            if (strpos($headerNorm, 'hour') !== false || strpos($headerNorm, 'ore') !== false) return (int)round($n * 3600);
            return (int)round($n * 60);
        }
        return 0;
    }
}

if (!function_exists('ie_detect_dur_unit')) {
    /* "50:00" sono cinquanta minuti o cinquanta ore? Chi esporta in MM:SS e chi
       in H:MM. Se c'e' anche l'ora di fine il dubbio si toglie da solo:
       confrontiamo le due letture con la distanza inizio-fine su un campione di
       righe e teniamo quella che ci azzecca. */
    function ie_detect_dur_unit($dataRows, $cols, $dateOrder) {
        if ($cols['dur'] < 0 || $cols['end'] < 0 || $cols['start'] < 0) return 'ms';
        $ms = 0; $hm = 0; $seen = 0;
        foreach ($dataRows as $r) {
            if ($seen >= 300) break;
            if (!isset($r[$cols['dur']], $r[$cols['end']], $r[$cols['start']])) continue;
            $s = trim((string)$r[$cols['dur']]['v']);
            if (substr_count($s, ':') !== 1) continue;
            $p = explode(':', $s);
            if (!is_numeric($p[0]) || !is_numeric($p[1])) continue;
            $st = ie_parse_datetime($r[$cols['start']], $dateOrder);
            $en = ie_parse_datetime($r[$cols['end']], $dateOrder);
            if ($st === null || $en === null) continue;
            $span = strtotime($en) - strtotime($st);
            if ($span <= 0) continue;
            $seen++;
            $tol = max(60, $span * 0.1);
            if (abs(((float)$p[0] * 60 + (float)$p[1]) - $span) <= $tol) $ms++;
            if (abs(((float)$p[0] * 3600 + (float)$p[1] * 60) - $span) <= $tol) $hm++;
        }
        return ($hm > 0 && $hm > $ms * 2) ? 'hm' : 'ms';
    }
}

if (!function_exists('ie_parse_mode')) {
    /* 0 = pomodoro/timer, 1 = cronometro (come la colonna mode del DB) */
    function ie_parse_mode($raw) {
        $n = ie_norm($raw);
        if ($n === '') return 0;
        $stopwatch = ['stopwatch','cronometro','crono','chrono','countup','freetimer','manual'];
        foreach ($stopwatch as $k) { if (strpos($n, $k) !== false) return 1; }
        return 0;
    }
}

/* ==========================================================================
   AJAX: analisi del file caricato
   ========================================================================== */
if ($ie_direct && isset($_GET['ie_ajax']) && $_GET['ie_ajax'] === 'parse') {
    header('Content-Type: application/json');
    /* Scrive nel database: solo POST, con token di sessione, origine nostra e limite
       di frequenza (prima qui non c'era niente e un altro sito poteva far partire
       l'importazione dal browser dell'utente). Il token lo porta standout_fetch.js. */
    sec_solo_post_se_scrive('parse', []);
    sec_guard_write((int)$current_user_id, 'pomodoro_import', 0, 30, 60);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE => t('import.troppo_grande_server'),
            UPLOAD_ERR_FORM_SIZE => t('import.troppo_grande'),
            UPLOAD_ERR_PARTIAL => t('import.caricamento_interrotto'),
            UPLOAD_ERR_NO_FILE => t('import.nessun_file'),
        ];
        $e = isset($_FILES['file']) ? $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'error' => isset($codes[$e]) ? $codes[$e] : t('import.errore_caricamento')]);
        exit();
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $error = '';

    $head = file_get_contents($tmpPath, false, null, 0, 2);
    $isZip = ($head === 'PK');

    if ($ext === 'xls' && !$isZip) {
        echo json_encode(['success' => false, 'error' => t('import.xls_vecchio')]);
        exit();
    }
    $rows = $isZip ? ie_read_xlsx($tmpPath, $error) : ie_read_csv($tmpPath, $error);

    if ($rows === null) { echo json_encode(['success' => false, 'error' => $error ?: t('import.non_leggibile')]); exit(); }
    if (count($rows) === 0) { echo json_encode(['success' => false, 'error' => t('import.e_vuoto')]); exit(); }

    $cols = []; $score = 0;
    $headerIdx = ie_find_header($rows, $cols, $score);
    $isHeaderRow = ($headerIdx >= 0);

    $headerNames = [];
    if ($isHeaderRow) { foreach ($rows[$headerIdx] as $c) { $headerNames[] = $c['v']; } }
    $durHeaderNorm = ($cols['dur'] >= 0 && isset($headerNames[$cols['dur']])) ? ie_norm($headerNames[$cols['dur']]) : '';

    $dataRows = [];
    foreach (array_slice($rows, $headerIdx + 1) as $r) {
        if (!ie_row_empty($r)) $dataRows[] = $r;   // le righe vuote non sono righe scartate
    }

    /* formato data: deciso guardando tutta la colonna, non riga per riga.
       Si guarda la colonna della data quando c'e': se le date stanno li',
       nella colonna di inizio ci sono solo orari e non si capirebbe niente
       (e comparirebbe l'avviso "date ambigue" a sproposito). */
    $colDate = ($cols['date'] >= 0) ? $cols['date'] : $cols['start'];
    $samples = [];
    foreach ($dataRows as $r) {
        if (isset($r[$colDate]) && empty($r[$colDate]['date'])) $samples[] = (string)$r[$colDate]['v'];
    }
    $dateOrder = ie_detect_date_order($samples);
    $ambiguousDates = ($dateOrder === null && count($samples) > 0);
    if ($dateOrder === null) $dateOrder = 'dmy';

    $durUnit = ie_detect_dur_unit($dataRows, $cols, $dateOrder);

    $lists = [];      // nome -> key
    $titles = [];     // listKey|nome -> key
    $outLists = [];
    $outTitles = [];
    $sessions = [];
    $skipped = 0;
    $nPomo = 0; $nCrono = 0;

    foreach ($dataRows as $r) {
        $get = function ($k) use ($r, $cols) {
            $i = $cols[$k];
            return ($i >= 0 && isset($r[$i])) ? $r[$i] : ['v' => '', 'raw' => '', 'date' => false];
        };

        $start = ie_parse_datetime($get('start'), $dateOrder);

        /* Quando la data sta in una colonna a parte, l'ora da sola non basta:
           senza questo passaggio ogni riga finiva al 30 dicembre 1899. */
        $giorno = null;
        $oraInizio = null;
        if ($cols['date'] >= 0) {
            $giorno = ie_parse_datetime($get('date'), $dateOrder);
            $oraInizio = ie_parse_ora_sola($get('start'));
            if ($giorno !== null && $oraInizio !== null) {
                $start = ie_unisci_data_ora($giorno, $oraInizio);
            }
        }
        if ($start === null) { $skipped++; continue; }

        $dur = ie_parse_duration($get('dur'), $durHeaderNorm, $durUnit);
        if ($dur <= 0 && $cols['end'] >= 0) {
            $end = null;
            $oraFine = ($giorno !== null) ? ie_parse_ora_sola($get('end')) : null;
            if ($oraFine !== null) {
                $end = ie_unisci_data_ora($giorno, $oraFine);
                /* 23:30 -> 00:10 non e' una riga sbagliata, e' una sessione a
                   cavallo della mezzanotte: la fine sta sul giorno dopo. Prima
                   veniva scartata perche' la durata usciva negativa. Vale solo
                   quando la fine e' una sola ora appesa alla stessa data: con
                   due date scritte per esteso, una fine prima dell'inizio e'
                   un dato sbagliato e resta tale. */
                if ($end !== null && strtotime($end) <= strtotime($start)) {
                    $end = date('Y-m-d H:i:s', strtotime($end) + 86400);
                }
            } else {
                $end = ie_parse_datetime($get('end'), $dateOrder);
            }
            if ($end !== null) $dur = max(0, strtotime($end) - strtotime($start));
        }
        if ($dur <= 0) { $skipped++; continue; }

        $mode = ie_parse_mode($get('type')['v']);
        if ($mode === 1) $nCrono++; else $nPomo++;

        $listName = ie_clean_name($get('list')['v']);
        $titleName = ie_clean_name($get('title')['v']);

        $listKey = '';
        if ($listName !== '') {
            if (!isset($lists[$listName])) {
                $lists[$listName] = 'L' . count($lists);
                $outLists[] = ['key' => $lists[$listName], 'name' => $listName];
            }
            $listKey = $lists[$listName];
        }

        $titleKey = '';
        if ($titleName !== '') {
            $tk = $listKey . '|' . $titleName;
            if (!isset($titles[$tk])) {
                $titles[$tk] = 'T' . count($titles);
                $outTitles[] = ['key' => $titles[$tk], 'listKey' => $listKey, 'name' => $titleName];
            }
            $titleKey = $titles[$tk];
        }

        $sessions[] = [
            'start' => $start,
            'end' => date('Y-m-d H:i:s', strtotime($start) + $dur),
            'dur' => $dur,
            'mode' => $mode,
            'type' => trim((string)$get('type')['v']),
            'listKey' => $listKey,
            'titleKey' => $titleKey,
        ];
    }

    if (count($sessions) === 0) {
        echo json_encode(['success' => false, 'error' => t('import.pi_nessuna_sessione')]);
        exit();
    }

    usort($sessions, function ($a, $b) { return strcmp($a['start'], $b['start']); });

    $warnings = [];
    if (!$isHeaderRow) $warnings[] = t('import.pi_warn_header');
    if ($ambiguousDates) $warnings[] = t('import.pi_warn_date');
    if ($skipped > 0) $warnings[] = t($skipped === 1 ? 'import.pi_riga_ignorata' : 'import.pi_righe_ignorate', ['n' => $skipped]);

    echo json_encode([
        'success' => true,
        'lists' => $outLists,
        'titles' => $outTitles,
        'sessions' => $sessions,
        'warnings' => $warnings,
        'stats' => [
            'rows' => count($dataRows),
            'sessions' => count($sessions),
            'pomodori' => $nPomo,
            'cronometri' => $nCrono,
            'lists' => count($outLists),
            'titles' => count($outTitles),
            'skipped' => $skipped,
        ],
        'columns' => [
            'start' => $cols['start'] >= 0 && isset($headerNames[$cols['start']]) ? $headerNames[$cols['start']] : ('colonna ' . ($cols['start'] + 1)),
            // stringa vuota = colonna assente: il JS in quel caso non scrive la voce
            'date' => $cols['date'] >= 0 && isset($headerNames[$cols['date']]) ? $headerNames[$cols['date']] : '',
            'type' => $cols['type'] >= 0 && isset($headerNames[$cols['type']]) ? $headerNames[$cols['type']] : ('colonna ' . ($cols['type'] + 1)),
            'dur' => $cols['dur'] >= 0 && isset($headerNames[$cols['dur']]) ? $headerNames[$cols['dur']] : ('colonna ' . ($cols['dur'] + 1)),
            'title' => $cols['title'] >= 0 && isset($headerNames[$cols['title']]) ? $headerNames[$cols['title']] : ('colonna ' . ($cols['title'] + 1)),
            'list' => $cols['list'] >= 0 && isset($headerNames[$cols['list']]) ? $headerNames[$cols['list']] : ('colonna ' . ($cols['list'] + 1)),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ==========================================================================
   AJAX: salvataggio
   Crea i progetti nuovi (i sotto-progetti agganciati al padre giusto) e poi
   inserisce le sessioni. Tutto dentro una transazione: se salta qualcosa non
   restano progetti orfani a meta'.
   ========================================================================== */
if ($ie_direct && isset($_GET['ie_ajax']) && $_GET['ie_ajax'] === 'save') {
    header('Content-Type: application/json');
    /* Scrive nel database: solo POST, con token di sessione, origine nostra e limite
       di frequenza (prima qui non c'era niente e un altro sito poteva far partire
       l'importazione dal browser dell'utente). Il token lo porta standout_fetch.js. */
    sec_solo_post_se_scrive('save', []);
    sec_guard_write((int)$current_user_id, 'pomodoro_import', 0, 30, 60);

    $payload = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($payload) || empty($payload['sessions'])) {
        echo json_encode(['success' => false, 'error' => t('import.dati_non_validi')]); exit();
    }

    $uid = intval($current_user_id);

    /* id dei progetti dell'utente: serve a validare le mappature che arrivano
       dal client, che non e' mai da credere sulla parola */
    $ownIds = [];
    foreach (sec_all("SELECT id FROM projects WHERE user_id = ?", [$uid], 'i') as $row) { $ownIds[(int)$row['id']] = true; }

    if (!function_exists('ie_next_order')) {
        function ie_next_order($conn, $uid, $parentId) {
            $r = sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM projects WHERE user_id = ? AND " . ($parentId > 0 ? "parent_id = ?" : "parent_id IS NULL"),
                $parentId > 0 ? [(int)$uid, (int)$parentId] : [(int)$uid], $parentId > 0 ? 'ii' : 'i');
            return $r ? (int)$r['n'] : 0;
        }
    }
    if (!function_exists('ie_create_project')) {
        function ie_create_project($conn, $uid, $name, $parentId) {
            $color = ps_resolve_color($conn, $parentId, $uid);
            $ord = ie_next_order($conn, $uid, $parentId);
            /* Query preparata: il nome viene dal file importato e non entra mai nel testo SQL. */
            sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
                   VALUES (?, ?, ?, ?, 0, 'in_progress', ?, NOW())",
                [(int)$uid, $parentId > 0 ? (int)$parentId : null, $name, $color, $ord], 'iissi');
            return sec_insert_id();
        }
    }
    if (!function_exists('ps_resolve_color')) {
        function ps_resolve_color($conn, $parentId, $userId) {
            $parentId = intval($parentId); $guard = 0;
            while ($parentId > 0 && $guard < 50) {
                $guard++;
                $row = sec_one("SELECT parent_id, color, color_edited FROM projects WHERE id = ? AND user_id = ?", [(int)$parentId, (int)$userId], 'ii');
                if (!$row) break;
                if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
                $parentId = intval($row['parent_id']);
            }
            return '#8E8E93';
        }
    }

    /* La transazione deve stare sulla stessa connessione che usano le query preparate (sec_db). */
    $conn = sec_db();
    mysqli_begin_transaction($conn);
    $createdProjects = 0;
    $insertedSessions = 0;

    try {
        /* 1) progetti principali (colonna List) */
        $listIds = [];
        foreach (($payload['lists'] ?? []) as $l) {
            $key = (string)($l['key'] ?? '');
            $mapped = intval($l['mapped'] ?? 0);
            if ($mapped > 0 && isset($ownIds[$mapped])) { $listIds[$key] = $mapped; continue; }
            $name = trim((string)($l['name'] ?? ''));
            if ($name === '') continue;
            $newId = ie_create_project($conn, $uid, $name, 0);
            if (!$newId) throw new Exception(t('import.pi_errore_progetto', ['nome' => $name]));
            $listIds[$key] = $newId;
            $ownIds[$newId] = true;
            $createdProjects++;
        }

        /* 2) sotto-progetti (colonna Title) */
        $titleIds = [];
        foreach (($payload['titles'] ?? []) as $t) {
            $key = (string)($t['key'] ?? '');
            $mapped = intval($t['mapped'] ?? 0);
            if ($mapped > 0 && isset($ownIds[$mapped])) { $titleIds[$key] = $mapped; continue; }
            $name = trim((string)($t['name'] ?? ''));
            if ($name === '') continue;
            $lk = (string)($t['listKey'] ?? '');
            $parentId = ($lk !== '' && isset($listIds[$lk])) ? $listIds[$lk] : 0;
            $newId = ie_create_project($conn, $uid, $name, $parentId);
            if (!$newId) throw new Exception(t('import.pi_errore_sotto', ['nome' => $name]));
            $titleIds[$key] = $newId;
            $ownIds[$newId] = true;
            $createdProjects++;
        }

        /* 3) sessioni */
        $values = [];
        foreach ($payload['sessions'] as $s) {
            $start = (string)($s['start'] ?? '');
            $dur = intval($s['dur'] ?? 0);
            if ($start === '' || $dur <= 0) continue;
            $startTs = strtotime($start);
            if ($startTs === false) continue;

            $mode = (intval($s['mode'] ?? 0) === 1) ? 1 : 0;
            $tk = (string)($s['titleKey'] ?? '');
            $lk = (string)($s['listKey'] ?? '');
            $projId = 0;
            if ($tk !== '' && isset($titleIds[$tk])) $projId = $titleIds[$tk];
            elseif ($lk !== '' && isset($listIds[$lk])) $projId = $listIds[$lk];

            $values[] = [$uid, $mode, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $startTs + $dur), $dur, (int)round($dur / 60), $projId > 0 ? $projId : null];
        }

        /* insert a blocchi: un file da 3000 pomodori non deve fare 3000 query */
        foreach (array_chunk($values, 200) as $chunk) {
            $segnaposto = implode(',', array_fill(0, count($chunk), "(?, ?, ?, ?, ?, ?, 'archiviato', ?, 1)"));
            $params = [];
            foreach ($chunk as $riga) { foreach ($riga as $v) $params[] = $v; }
            sec_q("INSERT INTO pomodoro_sessions (user_id, `mode`, start_time, end_time, duration_seconds, planned_duration_min, status, project_id, is_manual) VALUES " . $segnaposto,
                $params, str_repeat('iissiii', count($chunk)));
            $insertedSessions += count($chunk);
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'projects' => $createdProjects, 'sessions' => $insertedSessions], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        /* Il motivo tecnico (anche il testo di MySQL) va nel log, non al browser. */
        sec_log_error('import', $e->getMessage());
        echo json_encode(['success' => false, 'error' => t('import.salvataggio_annullato', ['motivo' => t('comune.errore_generico')])], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

/* ==========================================================================
   EXPORT — stesso formato del file di import, cosi' il giro si chiude:
   quello che esporti lo puoi ri-importare senza perdere niente.
   Title = progetto collegato alla sessione, List = suo progetto padre.
   ========================================================================== */

if (!function_exists('ie_fmt_hms')) {
    /* 3000 -> "50:00", 6061 -> "01:41:01" (lo stesso stile del file di import) */
    function ie_fmt_hms($sec) {
        $sec = max(0, (int)$sec);
        $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
        return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}

if (!function_exists('ie_export_rows')) {
    function ie_export_rows($conn, $uid) {
        $sql = "SELECT s.start_time, s.end_time, s.duration_seconds, s.mode,
                       p.name AS proj_name, pp.name AS parent_name
                  FROM pomodoro_sessions s
                  LEFT JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                  LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = s.user_id
                 WHERE s.user_id = ?
                 ORDER BY s.start_time ASC";
        $rows = [];
        foreach (sec_all($sql, [(int)$uid], 'i') as $r) {
            $startTs = strtotime((string)$r['start_time']);
            if ($startTs === false || empty($r['start_time'])) continue;

            $sec = (int)$r['duration_seconds'];
            $endTs = empty($r['end_time']) ? false : strtotime((string)$r['end_time']);
            if ($sec <= 0 && $endTs !== false) $sec = max(0, $endTs - $startTs);
            if ($sec <= 0) continue;   // sessione ancora in corso: non ha niente da esportare
            if ($endTs === false || $endTs < $startTs) $endTs = $startTs + $sec;

            $rows[] = [
                'date'    => date('Y-m-d', $startTs),
                'start'   => date('Y-m-d H:i', $startTs),
                'end'     => date('Y-m-d H:i', $endTs),
                'type'    => ((int)$r['mode'] === 1) ? 'Stopwatch' : 'Pomo',
                'title'   => ($r['proj_name'] === null || $r['proj_name'] === '') ? '/' : $r['proj_name'],
                'dur'     => ie_fmt_hms($sec),
                'content' => ($r['proj_name'] === null) ? '/' : 'Task',
                'list'    => ($r['parent_name'] === null || $r['parent_name'] === '') ? '/' : $r['parent_name'],
                'tags'    => '/',
                'note'    => '/',
            ];
        }
        return $rows;
    }
}

if (!function_exists('ie_export_values')) {
    function ie_export_values($row) {
        return [$row['date'], $row['start'], $row['end'], $row['type'], $row['title'],
                $row['dur'], $row['content'], $row['list'], $row['tags'], $row['note']];
    }
}

if (!function_exists('ie_export_title')) {
    /* La prima riga del formato: "Date:2024-02-22 ~ 2026-06-29" */
    function ie_export_title($rows) {
        if (empty($rows)) return 'Date:' . date('Y-m-d') . ' ~ ' . date('Y-m-d');
        return 'Date:' . $rows[0]['date'] . ' ~ ' . $rows[count($rows) - 1]['date'];
    }
}

if (!function_exists('ie_xml_escape')) {
    function ie_xml_escape($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
}

if (!function_exists('ie_build_xlsx')) {
    function ie_build_xlsx($rows, $headers, $title) {
        $tmp = tempnam(sys_get_temp_dir(), 'ieexp');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Pomodori" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        /* stile 0 = normale, 1 = intestazione in grassetto, 2 = data/ora */
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        /* Struttura identica al file di import: riga 1 titolo, riga 2 vuota,
           riga 3 intestazioni, dati dalla 4. Tutto testo, come l'originale. */
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:J' . (3 + max(1, count($rows))) . '"/>'
            . '<cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="3" width="32" customWidth="1"/>'
            . '<col min="4" max="4" width="14" customWidth="1"/><col min="5" max="5" width="42" customWidth="1"/>'
            . '<col min="6" max="6" width="14" customWidth="1"/><col min="7" max="7" width="18" customWidth="1"/>'
            . '<col min="8" max="8" width="28" customWidth="1"/><col min="9" max="9" width="14" customWidth="1"/>'
            . '<col min="10" max="10" width="28" customWidth="1"/></cols><sheetData>';

        $sheet .= '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t>' . ie_xml_escape($title) . '</t></is></c></row>';
        $sheet .= '<row r="2"/>';

        $sheet .= '<row r="3">';
        foreach ($headers as $i => $h) {
            $sheet .= '<c r="' . ie_col_letter($i) . '3" t="inlineStr" s="1"><is><t>' . ie_xml_escape($h) . '</t></is></c>';
        }
        $sheet .= '</row>';

        $r = 4;
        foreach ($rows as $row) {
            $sheet .= '<row r="' . $r . '">';
            foreach (ie_export_values($row) as $i => $v) {
                $ref = ie_col_letter($i) . $r;
                if ($v === '' || $v === null) {
                    $sheet .= '<c r="' . $ref . '"/>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . ie_xml_escape($v) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
            $r++;
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return $tmp;
    }
}

if ($ie_direct && isset($_GET['ie_export'])) {
    while (ob_get_level() > 0) { ob_end_clean(); } // niente spazi vaganti dentro il file
    $headers = ['Date', 'StartTime', 'EndTime', 'Type', 'Title', 'Duration', 'Type of Content', 'List', 'Tags', 'Note'];
    $rows = ie_export_rows($conn, $current_user_id);
    $title = ie_export_title($rows);
    $stamp = date('Ymd');
    $wantCsv = (($_GET['fmt'] ?? '') === 'csv') || !class_exists('ZipArchive');

    if (!$wantCsv) {
        $file = ie_build_xlsx($rows, $headers, $title);
        if ($file !== null) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="Pomodoro_record_' . $stamp . '.xlsx"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-store');
            readfile($file);
            unlink($file);
            exit();
        }
        $wantCsv = true;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Pomodoro_record_' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: senza, Excel massacra gli accenti
    fputcsv($out, [$title]);
    fwrite($out, "\r\n");
    fputcsv($out, $headers);
    foreach ($rows as $row) { fputcsv($out, ie_export_values($row)); }
    fclose($out);
    exit();
}

/* ==========================================================================
   MARKUP DEL POPUP (vuoto: si riempie via JS dopo l'analisi del file)
   ========================================================================== */
if (defined('IE_RENDERED')) return;
define('IE_RENDERED', true);
?>
<style nonce="<?php echo sec_nonce(); ?>">
/* --- il foglio ---------------------------------------------------------- */
.ie-overlay{ position:fixed; inset:0; z-index:9600; display:flex; align-items:flex-end; justify-content:center;
    background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
    opacity:0; pointer-events:none; transition:opacity .3s; }
.ie-overlay.show{ opacity:1; pointer-events:auto; }
.ie-sheet{ width:100%; max-width:660px; height:94vh; height:94dvh; background:var(--surface,#fff); border-radius:24px 24px 0 0;
    display:flex; flex-direction:column; transform:translateY(100%); transition:transform .4s var(--spring,ease);
    box-shadow:var(--shadow-sheet, 0 -14px 60px rgba(0,0,0,.18)); }
.ie-overlay.show .ie-sheet{ transform:translateY(0); }

.ie-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 16px 10px; flex-shrink:0;
    border-bottom:.5px solid var(--hairline,#eee); }
.ie-title{ font-size:17px; font-weight:800; letter-spacing:-.01em; }
.ie-sub{ font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:2px; }
.ie-icon-btn{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2,#eee); color:var(--text-2,#777);
    display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:transform .15s var(--spring,ease); }
.ie-icon-btn:active{ transform:scale(.88); }

.ie-body{ flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:14px 14px 8px; overscroll-behavior:contain; }

/* --- schermata di scelta file ------------------------------------------- */
.ie-drop{ border:2px dashed var(--hairline-2,#ccc); border-radius:var(--r-card,22px); padding:32px 20px; text-align:center;
    background:var(--surface-2,#f7f7f9); transition:border-color .25s, background-color .25s, transform .2s var(--spring,ease); }
.ie-drop.dragover{ border-color:var(--blue,#007AFF); background:var(--blue-soft,rgba(0,122,255,.09)); transform:scale(1.01); }
.ie-drop-icon{ width:52px; height:52px; margin:0 auto 12px; border-radius:16px; background:var(--blue-soft,rgba(0,122,255,.09));
    color:var(--blue,#007AFF); display:flex; align-items:center; justify-content:center; }
.ie-drop-title{ font-size:16px; font-weight:800; margin-bottom:5px; }
.ie-drop-text{ font-size:13px; color:var(--text-2,#8A8A8E); line-height:1.5; }
.ie-drop-btn{ margin-top:16px; padding:12px 22px; border-radius:14px; border:none; background:var(--blue,#007AFF); color:#fff;
    font-size:14.5px; font-weight:700; box-shadow:var(--btn-hl), 0 6px 16px -6px rgba(0,122,255,.7); transition:transform .15s var(--spring,ease); }
.ie-drop-btn:active{ transform:scale(.96); }
.ie-hint{ margin-top:16px; font-size:12px; color:var(--text-3,#AEAEB2); line-height:1.6; text-align:center; }
.ie-hint b{ color:var(--text-2,#8A8A8E); font-weight:700; }

/* --- spinner ------------------------------------------------------------ */
.ie-loading{ display:flex; flex-direction:column; align-items:center; gap:14px; padding:60px 20px; color:var(--text-2,#8A8A8E); font-size:13.5px; font-weight:600; }
.ie-spinner{ width:30px; height:30px; border-radius:50%; border:3px solid var(--hairline,#eee); border-top-color:var(--blue,#007AFF); animation:ieSpin .8s linear infinite; }
@keyframes ieSpin{ to{ transform:rotate(360deg); } }

/* --- riepilogo ---------------------------------------------------------- */
.ie-stats{ display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; scrollbar-width:none; }
.ie-stats::-webkit-scrollbar{ display:none; }
.ie-stat{ flex-shrink:0; background:var(--surface-2,#f2f2f7); border-radius:14px; padding:9px 13px; min-width:74px; }
.ie-stat-n{ font-size:18px; font-weight:800; letter-spacing:-.02em; line-height:1.1; }
.ie-stat-l{ font-size:10.5px; font-weight:700; color:var(--text-2,#8A8A8E); text-transform:uppercase; letter-spacing:.3px; margin-top:1px; }
.ie-cols{ font-size:11.5px; color:var(--text-3,#AEAEB2); line-height:1.7; padding:0 2px 12px; }
.ie-cols b{ color:var(--text-2,#8A8A8E); font-weight:700; }
.ie-warn{ display:flex; gap:8px; background:color-mix(in srgb, var(--orange,#FF9500) 12%, transparent); color:var(--orange,#FF9500);
    border-radius:12px; padding:10px 12px; font-size:12px; font-weight:600; line-height:1.45; margin-bottom:8px; }
.ie-warn svg{ flex-shrink:0; margin-top:1px; }

/* --- sezioni ------------------------------------------------------------ */
.ie-section{ margin-bottom:18px; }
.ie-sec-head{ position:sticky; top:-14px; z-index:3; display:flex; align-items:center; gap:8px; padding:10px 4px;
    background:var(--surface,#fff); cursor:pointer; }
.ie-sec-num{ width:19px; height:19px; border-radius:50%; background:var(--text,#1C1C1E); color:var(--surface,#fff);
    font-size:10.5px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ie-sec-title{ font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; flex:1; }
.ie-sec-count{ font-size:11.5px; font-weight:700; color:var(--text-3,#AEAEB2); }
.ie-sec-chev{ color:var(--text-3,#AEAEB2); transition:transform .3s var(--spring,ease); }
.ie-section.collapsed .ie-sec-chev{ transform:rotate(-90deg); }
.ie-sec-body{ transition:opacity .25s; }
.ie-section.collapsed .ie-sec-body{ display:none; }

/* --- righe progetto ----------------------------------------------------- */
@keyframes ieIn{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:none; } }
.ie-node{ animation:ieIn .3s var(--spring,ease) both; }
.ie-row{ display:flex; align-items:center; gap:9px; padding:10px 11px; border-radius:13px; background:var(--surface-2,#f4f4f6);
    border-left:4px solid var(--row-color,#8A8A8E); margin-bottom:5px; cursor:pointer;
    transition:transform .14s var(--spring,ease), background-color .2s; }
.ie-row:active{ transform:scale(.985); }
.ie-row.ie-row-flash{ animation:ieFlash .7s var(--spring,ease); }
@keyframes ieFlash{ 0%,100%{ background:var(--surface-2,#f4f4f6); } 30%{ background:color-mix(in srgb, var(--row-color,#8A8A8E) 22%, var(--surface-2,#f4f4f6)); } }
.ie-dot{ width:9px; height:9px; border-radius:50%; background:var(--row-color,#8A8A8E); flex-shrink:0;
    box-shadow:0 0 0 3px color-mix(in srgb, var(--row-color,#8A8A8E) 18%, transparent); }
.ie-names{ flex:1; min-width:0; }
.ie-name{ font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ie-name-sub{ font-weight:600; font-size:14px; }
.ie-from{ font-size:11px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:1px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ie-tag{ flex-shrink:0; font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; padding:3px 7px; border-radius:7px;
    background:color-mix(in srgb, var(--green,#34C759) 16%, transparent); color:var(--green,#34C759); }
.ie-restore{ flex-shrink:0; width:27px; height:27px; border-radius:50%; border:none; background:var(--surface,#fff); color:var(--text-2,#8A8A8E);
    display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.1); transition:transform .15s var(--spring,ease); }
.ie-restore:active{ transform:scale(.85) rotate(-140deg); }
.ie-children{ margin-left:16px; padding-left:8px; border-left:1.5px solid var(--hairline,#eee); }
.ie-none-title{ font-size:12px; font-weight:700; color:var(--text-3,#AEAEB2); padding:4px 4px 6px; font-style:italic; }

/* --- righe sessione ----------------------------------------------------- */
.ie-day{ font-size:11px; font-weight:800; color:var(--text-2,#8A8A8E); text-transform:uppercase; letter-spacing:.4px; padding:12px 6px 5px; }
.ie-sess{ display:flex; align-items:center; gap:10px; padding:9px 11px; border-radius:12px; background:var(--surface-2,#f4f4f6);
    border-left:4px solid var(--row-color,#8A8A8E); margin-bottom:5px; animation:ieIn .3s var(--spring,ease) both; }
.ie-sess-icon{ width:25px; height:25px; border-radius:50%; background:var(--row-color,#8A8A8E); color:#fff; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px -1px color-mix(in srgb, var(--row-color,#8A8A8E) 55%, transparent); }
.ie-sess-icon svg{ width:13px; height:13px; }
.ie-sess-mid{ flex:1; min-width:0; }
.ie-sess-proj{ font-size:13.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ie-sess-proj.ie-sess-empty{ color:var(--text-3,#AEAEB2); font-weight:600; font-style:italic; }
.ie-sess-meta{ font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; margin-top:1px; }
.ie-sess-dur{ flex-shrink:0; font-size:12.5px; font-weight:800; color:var(--text,#1C1C1E); font-variant-numeric:tabular-nums; }
.ie-more{ width:100%; padding:11px; border-radius:12px; border:none; background:var(--surface-2,#f2f2f7); color:var(--blue,#007AFF);
    font-size:13px; font-weight:700; margin-top:6px; transition:transform .15s var(--spring,ease); }
.ie-more:active{ transform:scale(.97); }

/* --- barra di salvataggio ----------------------------------------------- */
.ie-foot{ flex-shrink:0; padding:12px 14px calc(12px + var(--safe-bottom,0px)); border-top:.5px solid var(--hairline,#eee);
    background:var(--surface,#fff); display:none; gap:9px; align-items:center; }
.ie-foot.show{ display:flex; }
.ie-foot-info{ flex:1; min-width:0; font-size:11.5px; color:var(--text-2,#8A8A8E); font-weight:600; line-height:1.35; }
.ie-btn-ghost{ padding:12px 15px; border-radius:14px; border:none; background:var(--surface-2,#f2f2f7); color:var(--text,#1C1C1E);
    font-size:14px; font-weight:700; flex-shrink:0; transition:transform .15s var(--spring,ease); }
.ie-btn-ghost:active{ transform:scale(.96); }
.ie-btn-save{ padding:12px 20px; border-radius:14px; border:none; background:var(--blue,#007AFF); color:#fff; font-size:14.5px; font-weight:800;
    flex-shrink:0; box-shadow:var(--btn-hl), 0 6px 16px -6px rgba(0,122,255,.7); transition:transform .15s var(--spring,ease), opacity .2s; }
.ie-btn-save:active{ transform:scale(.96); }
.ie-btn-save[disabled]{ opacity:.55; }

.ie-error{ text-align:center; padding:44px 24px; }
.ie-error-icon{ width:48px; height:48px; margin:0 auto 12px; border-radius:50%; background:var(--red-soft,rgba(255,59,48,.12));
    color:var(--red,#FF3B30); display:flex; align-items:center; justify-content:center; }
.ie-error-text{ font-size:14px; color:var(--text-2,#8A8A8E); line-height:1.5; margin-bottom:16px; }

.ie-toast{ position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom,0px)); transform:translate(-50%,12px); background:rgba(28,28,30,.94); color:#fff;
    padding:11px 18px; border-radius:14px; font-size:13.5px; font-weight:600; z-index:9960; opacity:0; pointer-events:none;
    max-width:calc(100% - 40px); text-align:center; transition:opacity .25s, transform .25s; }
.ie-toast.show{ opacity:1; transform:translate(-50%,0); }

/* rami del project selector che l'import ha reso non pertinenti: !important
   perche' la ricerca interna del selettore lavora su style.display e non deve
   poterli far riemergere */
.ps-row-wrap.ie-ps-off{ display:none !important; }

@media (max-width:400px){
    .ie-foot-info{ display:none; }
    .ie-btn-save{ flex:1; }
}
@media (prefers-reduced-motion: reduce){
    .ie-overlay *, .ie-overlay{ animation-duration:.01ms !important; transition-duration:.01ms !important; }
}
</style>

<?php echo standout_i18n_json(['comune', 'import']); ?>
<div class="ie-overlay" id="ieOverlay">
    <div class="ie-sheet">
        <div class="ie-head">
            <div>
                <div class="ie-title"><?php te('import.importa'); ?></div>
                <div class="ie-sub" id="ieHeadSub"><?php te('import.pi_sottotitolo'); ?></div>
            </div>
            <button type="button" class="ie-icon-btn" data-on-click="closePomodoroImport()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="ie-body" id="ieBody"></div>

        <div class="ie-foot" id="ieFoot">
            <div class="ie-foot-info" id="ieFootInfo"></div>
            <button type="button" class="ie-btn-ghost" data-on-click="ieReset()"><?php te('import.cambia_file'); ?></button>
            <button type="button" class="ie-btn-save" id="ieSaveBtn" data-on-click="ieSave()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>

<input type="file" id="ieFileInput" accept=".xlsx,.xlsm,.csv,.txt,.xls" style="display:none;">

<?php
    /* Il selettore usato per rimappare progetti e sotto-progetti: nessun tasto
       (lo apriamo noi da JS), nessuna opzione "nessun progetto" e nessuna
       creazione al volo (i progetti nuovi li crea il salvataggio). */
    $ps_id = 'ieimport';
    $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
    $ps_placeholder = t('progetti.ps_seleziona');
    $ps_title = t('import.pi_collega');
    $ps_show_all_option = false;
    $ps_allow_create = false;
    $ps_render_trigger = false;
    $ps_hidden_name = 'ie_project_id';
    $ps_exclude_id = 0;
    include __DIR__ . '/project_selector.php';

    /* project_selector legge le sue variabili con isset(): se le lasciassimo
       sporche, il prossimo include se le ritroverebbe addosso. */
    unset($ps_id, $ps_selected_id, $ps_selected_name, $ps_selected_color, $ps_placeholder,
          $ps_title, $ps_show_all_option, $ps_all_option_label, $ps_allow_create,
          $ps_render_trigger, $ps_hidden_name, $ps_exclude_id, $ps_trigger_class);
?>

<script nonce="<?php echo sec_nonce(); ?>">
(function () {
    'use strict';

    var GREY = '#8A8A8E';
    var PAGE = 80;

    var IE = {
        data: { lists: [], titles: [], sessions: [] },
        map: { lists: {}, titles: {} },   // key -> { id, name, color }
        target: null,                     // riga che ha aperto il project selector
        shown: PAGE,
        tagged: false,
        busy: false
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }
    function toast(msg) {
        var t = document.getElementById('ieToast');
        if (!t) { t = document.createElement('div'); t.id = 'ieToast'; t.className = 'ie-toast'; document.body.appendChild(t); }
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._timer); t._timer = setTimeout(function () { t.classList.remove('show'); }, 2400);
    }
    function byId(id) { return document.getElementById(id); }
    function psModal() { return byId('ps-modal-ieimport'); }

    function fmtDur(sec) {
        var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
        if (h > 0) return h + 'h ' + m + 'm';
        if (sec < 60) return sec + 's';
        return m + 'm';
    }
    function fmtTime(dt) {
        var d = new Date(String(dt).replace(' ', 'T'));
        return d.toLocaleTimeString((document.documentElement.lang || 'it'), { hour: '2-digit', minute: '2-digit' });
    }
    function fmtDay(dt) {
        var d = new Date(String(dt).replace(' ', 'T'));
        return d.toLocaleDateString((document.documentElement.lang || 'it'), { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
    }

    function findList(key) {
        for (var i = 0; i < IE.data.lists.length; i++) if (IE.data.lists[i].key === key) return IE.data.lists[i];
        return null;
    }
    function findTitle(key) {
        for (var i = 0; i < IE.data.titles.length; i++) if (IE.data.titles[i].key === key) return IE.data.titles[i];
        return null;
    }

    /* ======================================================================
       APERTURA / CHIUSURA
       ====================================================================== */
    window.openPomodoroImport = function () {
        var ov = byId('ieOverlay');
        if (!IE.data.sessions.length) ieRenderDrop();
        ov.classList.add('show');
        document.documentElement.classList.add('sb-locked');
        ieTagTree();
    };
    window.closePomodoroImport = function () {
        byId('ieOverlay').classList.remove('show');
        setTimeout(function () {
            var ph = byId('phOverlay');
            var phOpen = ph && ph.classList.contains('show');
            if (!phOpen && !document.querySelector('.ps-modal-overlay.ps-open')) {
                document.documentElement.classList.remove('sb-locked');
            }
        }, 320);
    };

    window.exportPomodoroFile = function () {
        var a = document.createElement('a');
        a.href = 'pomodoro_import.php?ie_export=1';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        toast(T('import.export_in_corso'));
        buzz(8);
    };

    /* ======================================================================
       SCHERMATE
       ====================================================================== */
    function ieRenderDrop() {
        byId('ieFoot').classList.remove('show');
        byId('ieHeadSub').textContent = T('import.pi_sottotitolo');
        byId('ieBody').innerHTML =
            '<div class="ie-drop" id="ieDrop">' +
                '<div class="ie-drop-icon"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5-5 5 5"/><path d="M12 5v13"/></svg></div>' +
                '<div class="ie-drop-title">' + esc(T('import.scegli_file')) + '</div>' +
                '<div class="ie-drop-text">' + esc(T('import.trascina')) + '<br>' + esc(T('import.formati')) + '</div>' +
                '<button type="button" class="ie-drop-btn" data-on-click="ieBrowse()">' + esc(T('import.sfoglia')) + '</button>' +
            '</div>' +
            '<div class="ie-hint">' + T('import.pi_hint') + '</div>';
        ieBindDrop();
    }

    function ieRenderLoading(msg) {
        byId('ieFoot').classList.remove('show');
        byId('ieBody').innerHTML = '<div class="ie-loading"><div class="ie-spinner"></div><div>' + esc(msg || T('import.leggo_il_file')) + '</div></div>';
    }

    function ieRenderError(msg) {
        byId('ieFoot').classList.remove('show');
        byId('ieBody').innerHTML =
            '<div class="ie-error">' +
                '<div class="ie-error-icon"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg></div>' +
                '<div class="ie-error-text">' + esc(msg) + '</div>' +
                '<button type="button" class="ie-drop-btn" data-on-click="ieReset()">' + esc(T('import.riprova')) + '</button>' +
            '</div>';
    }

    window.ieBrowse = function () { byId('ieFileInput').click(); };
    window.ieReset = function () {
        IE.data = { lists: [], titles: [], sessions: [] };
        IE.map = { lists: {}, titles: {} };
        IE.shown = PAGE;
        byId('ieFileInput').value = '';
        ieRenderDrop();
    };

    function ieBindDrop() {
        var d = byId('ieDrop');
        if (!d) return;
        ['dragenter', 'dragover'].forEach(function (ev) {
            d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.remove('dragover'); });
        });
        d.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) ieUpload(e.dataTransfer.files[0]);
        });
    }

    /* ======================================================================
       CARICAMENTO E ANALISI
       ====================================================================== */
    function ieUpload(file) {
        ieRenderLoading(T('import.leggo_nome', { nome: file.name }));
        var fd = new FormData();
        fd.append('file', file);
        fetch('pomodoro_import.php?ie_ajax=parse', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { ieRenderError((d && d.error) || T('import.file_non_riconosciuto')); return; }
                IE.data = { lists: d.lists || [], titles: d.titles || [], sessions: d.sessions || [] };
                IE.map = { lists: {}, titles: {} };
                IE.shown = PAGE;
                ieRenderResult(d);
                buzz(10);
            })
            .catch(function () { ieRenderError(T('import.rete_caricamento')); });
    }

    function ieRenderResult(d) {
        var s = d.stats || {};
        var warn = '';
        (d.warnings || []).forEach(function (w) {
            warn += '<div class="ie-warn"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><span>' + esc(w) + '</span></div>';
        });

        var c = d.columns || {};
        var cols = '<div class="ie-cols">' + esc(T('import.pi_colonne_lette')) + ' '
            + (c.date ? '<b>' + esc(c.date) + '</b> = ' + T('import.pi_c_data') + ' · ' : '')
            + '<b>' + esc(c.start) + '</b> = ' + T('import.pi_c_inizio') + ' · <b>' + esc(c.dur) +
            '</b> = ' + T('import.pi_c_durata') + ' · <b>' + esc(c.type) + '</b> = ' + T('import.pi_c_tipo') + ' · <b>' + esc(c.title) + '</b> = ' + T('import.pi_c_sotto') + ' · <b>' +
            esc(c.list) + '</b> = ' + T('import.pi_c_progetto') + '</div>';

        byId('ieHeadSub').textContent = T('import.pi_sessioni_trovate', { n: s.sessions || 0 });
        byId('ieBody').innerHTML =
            '<div class="ie-stats">' +
                ieStat(s.pomodori || 0, T('import.pi_pomodori')) +
                ieStat(s.cronometri || 0, T('import.pi_cronometri')) +
                ieStat(s.lists || 0, T('import.pi_progetti')) +
                ieStat(s.titles || 0, T('import.pi_sotto_breve')) +
                (s.skipped ? ieStat(s.skipped, T('import.pi_ignorate')) : '') +
            '</div>' + cols + warn +
            '<div class="ie-section" id="ieSec1">' +
                '<div class="ie-sec-head" data-on-click="ieToggleSec(\'ieSec1\')">' +
                    '<span class="ie-sec-num">1</span><span class="ie-sec-title">' + T('import.pi_progetti') + '</span>' +
                    '<span class="ie-sec-count" id="ieSec1Count"></span>' +
                    '<svg class="ie-sec-chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>' +
                '</div>' +
                '<div class="ie-sec-body" id="ieProjects"></div>' +
            '</div>' +
            '<div class="ie-section" id="ieSec2">' +
                '<div class="ie-sec-head" data-on-click="ieToggleSec(\'ieSec2\')">' +
                    '<span class="ie-sec-num">2</span><span class="ie-sec-title">' + T('import.pi_sessioni') + '</span>' +
                    '<span class="ie-sec-count" id="ieSec2Count"></span>' +
                    '<svg class="ie-sec-chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>' +
                '</div>' +
                '<div class="ie-sec-body" id="ieSessions"></div>' +
            '</div>';

        byId('ieFoot').classList.add('show');
        byId('ieSaveBtn').disabled = false;
        byId('ieSaveBtn').textContent = T('comune.salva');
        ieRenderProjects();
        ieRenderSessions();
    }

    function ieStat(n, label) {
        return '<div class="ie-stat"><div class="ie-stat-n">' + n + '</div><div class="ie-stat-l">' + label + '</div></div>';
    }
    window.ieToggleSec = function (id) { byId(id).classList.toggle('collapsed'); };

    /* ======================================================================
       SEZIONE 1 — progetti e sotto-progetti
       ====================================================================== */
    function ieRowHTML(kind, key, origName, isSub) {
        var m = IE.map[kind === 'list' ? 'lists' : 'titles'][key];
        var color = m ? m.color : GREY;
        var name = m ? m.name : origName;
        var h = '<div class="ie-row" style="--row-color:' + esc(color) + ';" data-row="' + kind + ':' + key + '" data-on-click="iePick(\'' + kind + '\',\'' + key + '\')">' +
            '<span class="ie-dot"></span>' +
            '<div class="ie-names">' +
                '<div class="ie-name' + (isSub ? ' ie-name-sub' : '') + '">' + esc(name) + '</div>' +
                (m ? '<div class="ie-from">' + esc(T('import.pi_dal_file', { nome: origName })) + '</div>' : '') +
            '</div>';
        if (!m) h += '<span class="ie-tag">' + T('import.pi_nuovo') + '</span>';
        else h += '<button type="button" class="ie-restore" aria-label="' + esc(T('import.pi_ripristina')) + '" data-on-click="event.stopPropagation();ieRestore(\'' + kind + '\',\'' + key + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>';
        return h + '</div>';
    }

    function ieRenderProjects() {
        var byList = {};
        IE.data.titles.forEach(function (t) { (byList[t.listKey] = byList[t.listKey] || []).push(t); });

        var html = '';
        IE.data.lists.forEach(function (l) {
            html += '<div class="ie-node">' + ieRowHTML('list', l.key, l.name, false);
            var kids = byList[l.key] || [];
            if (kids.length) {
                html += '<div class="ie-children">';
                kids.forEach(function (t) { html += ieRowHTML('title', t.key, t.name, true); });
                html += '</div>';
            }
            html += '</div>';
        });

        var orphans = byList[''] || [];
        if (orphans.length) {
            html += '<div class="ie-none-title">' + esc(T('import.pi_senza_principale')) + '</div>';
            orphans.forEach(function (t) { html += '<div class="ie-node">' + ieRowHTML('title', t.key, t.name, false) + '</div>'; });
        }
        if (!html) html = '<div class="ie-none-title">' + esc(T('import.pi_nessun_progetto_file')) + '</div>';

        byId('ieProjects').innerHTML = html;

        var tot = IE.data.lists.length + IE.data.titles.length;
        var nuovi = 0;
        IE.data.lists.forEach(function (l) { if (!IE.map.lists[l.key]) nuovi++; });
        IE.data.titles.forEach(function (t) { if (!IE.map.titles[t.key]) nuovi++; });
        byId('ieSec1Count').textContent = T('import.pi_nuovi_su', { nuovi: nuovi, tot: tot });
        ieUpdateFoot();
    }

    /* ======================================================================
       SEZIONE 2 — sessioni
       ====================================================================== */
    function ieSessProject(s) {
        if (s.titleKey) {
            var m = IE.map.titles[s.titleKey], t = findTitle(s.titleKey);
            return { name: m ? m.name : (t ? t.name : ''), color: m ? m.color : GREY, isNew: !m };
        }
        if (s.listKey) {
            var ml = IE.map.lists[s.listKey], l = findList(s.listKey);
            return { name: ml ? ml.name : (l ? l.name : ''), color: ml ? ml.color : GREY, isNew: !ml };
        }
        return { name: T('import.pi_nessun_progetto'), color: GREY, isNew: false, none: true };
    }

    function ieRenderSessions() {
        var list = IE.data.sessions;
        var n = Math.min(IE.shown, list.length);
        var html = '';
        var lastDay = null;

        for (var i = 0; i < n; i++) {
            var s = list[i];
            var day = s.start.slice(0, 10);
            if (day !== lastDay) { lastDay = day; html += '<div class="ie-day">' + esc(fmtDay(s.start)) + '</div>'; }
            var p = ieSessProject(s);
            html += '<div class="ie-sess" style="--row-color:' + esc(p.color) + ';">' +
                '<span class="ie-sess-icon">' + (s.mode === 1
                    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 3"/></svg>'
                    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="13" r="8"/><path d="M9 2h6"/></svg>') + '</span>' +
                '<div class="ie-sess-mid">' +
                    '<div class="ie-sess-proj' + (p.none ? ' ie-sess-empty' : '') + '">' + esc(p.name) + '</div>' +
                    '<div class="ie-sess-meta">' + esc(fmtTime(s.start)) + ' – ' + esc(fmtTime(s.end)) + ' · ' + (s.mode === 1 ? T('import.pi_cronometro') : T('import.pi_pomodoro')) + '</div>' +
                '</div>' +
                (p.isNew ? '<span class="ie-tag">' + T('import.pi_nuovo') + '</span>' : '') +
                '<span class="ie-sess-dur">' + fmtDur(s.dur) + '</span>' +
            '</div>';
        }
        if (n < list.length) {
            html += '<button type="button" class="ie-more" data-on-click="ieMore()">' + T('import.pi_mostra_altre', { n: Math.min(PAGE, list.length - n), di: list.length - n }) + '</button>';
        }
        byId('ieSessions').innerHTML = html;
        byId('ieSec2Count').textContent = T(list.length === 1 ? 'import.pi_n_sessione' : 'import.pi_n_sessioni', { n: list.length });
    }
    window.ieMore = function () { IE.shown += PAGE; ieRenderSessions(); };

    function ieUpdateFoot() {
        var nuovi = 0;
        IE.data.lists.forEach(function (l) { if (!IE.map.lists[l.key]) nuovi++; });
        IE.data.titles.forEach(function (t) { if (!IE.map.titles[t.key]) nuovi++; });
        byId('ieFootInfo').innerHTML = T(nuovi === 1 ? 'import.pi_progetto_nuovo' : 'import.pi_progetti_nuovi', { n: nuovi }) + '<br>' + esc(T('import.pi_da_salvare', { n: IE.data.sessions.length }));
    }

    /* ======================================================================
       PROJECT SELECTOR — vincoli sull'albero
       'any'     = progetti e sotto-progetti
       'subonly' = solo sotto-progetti (mai un progetto di primo livello)
       'under'   = solo i discendenti di un progetto preciso
       ====================================================================== */
    function ieTagTree() {
        if (IE.tagged) return;
        var modal = psModal();
        if (!modal) return;
        modal.querySelectorAll('.ps-row-wrap').forEach(function (w) {
            var nameEl = w.querySelector(':scope > .ps-row .ps-name');
            if (!nameEl) return;
            var id = nameEl.getAttribute('data-ps-select') || '0';
            nameEl.setAttribute('data-ie-id', id);
            w.setAttribute('data-ie-id', id);
        });
        IE.tagged = true;
    }

    function ieNodeInfo(id) {
        var modal = psModal();
        if (!modal) return null;
        var w = modal.querySelector('.ps-row-wrap[data-ie-id="' + id + '"]');
        if (!w) return null;
        var nameEl = w.querySelector(':scope > .ps-row .ps-name');
        return {
            wrap: w,
            id: parseInt(id, 10),
            name: nameEl ? nameEl.getAttribute('data-ps-name') : '',
            color: (nameEl && nameEl.getAttribute('data-ps-color')) || '#8E8E93'
        };
    }
    function ieParentInfo(id) {
        var n = ieNodeInfo(id);
        if (!n) return null;
        var pw = n.wrap.parentElement ? n.wrap.parentElement.closest('.ps-row-wrap') : null;
        if (!pw) return null;
        return ieNodeInfo(pw.getAttribute('data-ie-id'));
    }
    function ieIsUnder(id, rootId) {
        var n = ieNodeInfo(id);
        if (!n || !n.wrap.parentElement) return false;
        return n.wrap.parentElement.closest('.ps-row-wrap[data-ie-id="' + rootId + '"]') !== null;
    }

    function ieRestrictTree(mode, rootId) {
        var modal = psModal();
        if (!modal) return 0;
        ieTagTree();
        var scroll = modal.querySelector('.ps-tree-scroll');
        var search = modal.querySelector('.ps-search-box input');
        if (search) { search.value = ''; window.psFilterTree(modal, ''); }

        var selectable = 0;
        modal.querySelectorAll('.ps-row-wrap').forEach(function (w) {
            var nameEl = w.querySelector(':scope > .ps-row .ps-name');
            if (!nameEl) return;
            var id = parseInt(w.getAttribute('data-ie-id'), 10);
            var isRoot = (w.parentElement === scroll);
            var ok, show = true;

            if (mode === 'any') {
                ok = true;
            } else if (mode === 'subonly') {
                ok = !isRoot;
            } else { // under
                var isSelf = (id === rootId);
                var isDesc = ieIsUnder(id, rootId);
                var isAnc = w.querySelector('.ps-row-wrap[data-ie-id="' + rootId + '"]') !== null;
                ok = isDesc;
                show = isSelf || isDesc || isAnc;
            }

            w.classList.toggle('ie-ps-off', !show);
            if (ok) {
                nameEl.setAttribute('data-ps-select', id);
                nameEl.classList.remove('ps-name-disabled');
                selectable++;
            } else {
                nameEl.removeAttribute('data-ps-select');
                nameEl.classList.add('ps-name-disabled');
            }
        });

        // in modalita' vincolata apriamo l'albero: le voci scelibili sono
        // spesso in fondo a un ramo e non ha senso farle cercare a mano
        if (mode !== 'any') {
            modal.querySelectorAll('.ps-children').forEach(function (c) { c.classList.remove('collapsed'); });
            modal.querySelectorAll('.ps-arrow').forEach(function (a) { a.classList.add('open'); });
        }
        var title = modal.querySelector('.ps-sheet-title');
        if (title) {
            title.textContent = mode === 'any' ? T('import.pi_collega') : T('import.pi_scegli_sotto');
        }
        return selectable;
    }

    window.iePick = function (kind, key) {
        var modal = psModal();
        if (!modal) return;
        var mode = 'any', rootId = 0;

        if (kind === 'title') {
            var t = findTitle(key);
            if (t && t.listKey) {
                var lm = IE.map.lists[t.listKey];
                if (lm) { mode = 'under'; rootId = lm.id; }
                else { mode = 'subonly'; }
            }
        }
        var n = ieRestrictTree(mode, rootId);
        if (n === 0) {
            toast(mode === 'under'
                ? T('import.pi_niente_sotto_qui')
                : T('import.pi_niente_sotto'));
            return;
        }
        IE.target = { kind: kind, key: key };
        buzz(6);
        window.psOpen(modal);
    };

    window.ieRestore = function (kind, key) {
        if (kind === 'list') delete IE.map.lists[key];
        else delete IE.map.titles[key];
        buzz(6);
        ieRenderProjects();
        ieRenderSessions();
        ieFlash(kind + ':' + key);
    };

    function ieFlash(rowKey) {
        var el = document.querySelector('.ie-row[data-row="' + rowKey + '"]');
        if (!el) return;
        el.classList.add('ie-row-flash');
        setTimeout(function () { el.classList.remove('ie-row-flash'); }, 700);
    }

    document.addEventListener('projectselected', function (e) {
        if (!e.detail || e.detail.uid !== 'ieimport') return;
        var t = IE.target;
        IE.target = null;
        if (!t) return;
        var id = parseInt(e.detail.id, 10);
        if (!id) return;
        var sel = { id: id, name: e.detail.name, color: e.detail.color || '#8E8E93' };

        if (t.kind === 'list') {
            IE.map.lists[t.key] = sel;
            /* i sotto-progetti gia' collegati fuori dal nuovo ramo non avrebbero
               piu' senso: li rimettiamo "nuovi" */
            IE.data.titles.forEach(function (ti) {
                if (ti.listKey !== t.key) return;
                var m = IE.map.titles[ti.key];
                if (m && !ieIsUnder(m.id, id)) delete IE.map.titles[ti.key];
            });
        } else {
            IE.map.titles[t.key] = sel;
            /* il progetto padre lo deduciamo dal sotto-progetto scelto */
            var ti2 = findTitle(t.key);
            if (ti2 && ti2.listKey && !IE.map.lists[ti2.listKey]) {
                var par = ieParentInfo(id);
                if (par) IE.map.lists[ti2.listKey] = { id: par.id, name: par.name, color: par.color };
            }
        }
        buzz(10);
        ieRenderProjects();
        ieRenderSessions();
        ieFlash(t.kind + ':' + t.key);
    });

    /* ======================================================================
       SALVATAGGIO
       ====================================================================== */
    window.ieSave = function () {
        if (IE.busy || !IE.data.sessions.length) return;
        IE.busy = true;
        var btn = byId('ieSaveBtn');
        btn.disabled = true; btn.textContent = T('import.salvo');

        var payload = {
            lists: IE.data.lists.map(function (l) {
                return { key: l.key, name: l.name, mapped: IE.map.lists[l.key] ? IE.map.lists[l.key].id : 0 };
            }),
            titles: IE.data.titles.map(function (t) {
                return { key: t.key, listKey: t.listKey, name: t.name, mapped: IE.map.titles[t.key] ? IE.map.titles[t.key].id : 0 };
            }),
            sessions: IE.data.sessions.map(function (s) {
                return { start: s.start, dur: s.dur, mode: s.mode, listKey: s.listKey, titleKey: s.titleKey };
            })
        };

        fetch('pomodoro_import.php?ie_ajax=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'payload=' + encodeURIComponent(JSON.stringify(payload))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            IE.busy = false;
            if (!d || !d.success) {
                btn.disabled = false; btn.textContent = T('comune.salva');
                toast((d && d.error) || T('import.errore_salvataggio'));
                return;
            }
            buzz(20);
            byId('ieFoot').classList.remove('show');
            byId('ieHeadSub').textContent = T('import.import_completato');
            byId('ieBody').innerHTML =
                '<div class="ie-error">' +
                    '<div class="ie-error-icon" style="background:var(--green-soft,rgba(52,199,89,.13));color:var(--green,#34C759);">' +
                        '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>' +
                    '<div class="ie-drop-title">' + esc(T('import.fatto')) + '</div>' +
                    '<div class="ie-error-text">' + esc(T('import.pi_esito_sessioni', { n: d.sessions })) + '<br>' + esc(T('import.pi_esito_progetti', { n: d.projects })) + '</div>' +
                    '<button type="button" class="ie-drop-btn" data-on-click="location.reload()">' + esc(T('import.chiudi_aggiorna')) + '</button>' +
                '</div>';
            setTimeout(function () { location.reload(); }, 1800);
        })
        .catch(function () {
            IE.busy = false;
            btn.disabled = false; btn.textContent = T('comune.salva');
            toast(T('import.rete_salvataggio'));
        });
    };

    /* ====================================================================== */
    byId('ieFileInput').addEventListener('change', function () {
        if (this.files && this.files[0]) ieUpload(this.files[0]);
    });
    byId('ieOverlay').addEventListener('click', function (e) {
        if (e.target === this) window.closePomodoroImport();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var ov = byId('ieOverlay');
        if (ov.classList.contains('show') && !document.querySelector('.ps-modal-overlay.ps-open')) window.closePomodoroImport();
    });
})();
</script>