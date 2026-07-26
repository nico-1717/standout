<?php
/* ============================================================================
   VOTI — panoramica esami, media, obiettivo, simulatore, andamento, statistiche.
   Stessa impalcatura di progetti.php: config + auth_check + sidebar.html.
   Tutta la matematica "viva" (simulatore, grafici, proiezione) è lato client;
   il PHP fa da router AJAX per la persistenza e da bootstrap dei dati.
   ============================================================================ */
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }
mysqli_set_charset($conn, 'utf8mb4');
require_once 'auth_check.php';
$current_user_id = (int)$utente_corrente['id'];

/* -------------------------------------------------------------------------- */
/* Helper                                                                      */
/* -------------------------------------------------------------------------- */
function uni_json($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit(); }

/* intero in [min,max] oppure la stringa SQL "NULL" */
function uni_int_or_null($v, $min, $max) {
    if ($v === null || $v === '' || strtolower((string)$v) === 'null') return 'NULL';
    $n = (int)$v;
    if ($n < $min || $n > $max) return 'NULL';
    return (string)$n;
}
function uni_date_or_null($conn, $v) {
    if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return 'NULL';
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
}
function uni_str_or_null($conn, $v, $max = 200) {
    if ($v === null || $v === '' || strtolower((string)$v) === 'null') return 'NULL';
    $v = mb_substr((string)$v, 0, $max);
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
}
/* colore #RRGGBB validato, altrimenti fallback */
function uni_color($v, $fallback = '#0A84FF') {
    return (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) ? $v : $fallback;
}

/* riga esame dal DB → tipi puliti per il JS */
function uni_exam_out($r) {
    return [
        'id'                    => (int)$r['id'],
        'name'                  => $r['name'],
        'cfu'                   => (float)$r['cfu'],
        'status'                => $r['status'],
        'grade'                 => $r['grade'] === null ? null : (int)$r['grade'],
        'has_honors'            => (int)$r['has_honors'] === 1,
        'is_pass_fail'          => (int)$r['is_pass_fail'] === 1,
        'counts_for_graduation' => (int)$r['counts_for_graduation'] === 1,
        'exam_date'             => $r['exam_date'],
        'predicted_grade'       => $r['predicted_grade'] === null ? null : (int)$r['predicted_grade'],
        'predicted_honors'      => (int)$r['predicted_honors'] === 1,
        'catalog_code'          => $r['catalog_code'],
        'ssd'                   => $r['ssd'],
        'study_year'            => $r['study_year'] === null ? null : (int)$r['study_year'],
        'national_avg'          => $r['national_avg'] === null ? null : (float)$r['national_avg'],
        'group_id'              => (!isset($r['group_id']) || $r['group_id'] === null) ? null : (int)$r['group_id'],
        'sort_order'            => (int)$r['sort_order'],
    ];
}
function uni_bonus_out($r) {
    return [
        'id'              => (int)$r['id'],
        'name'            => $r['name'],
        'bonus_type'      => $r['bonus_type'],
        'points'          => (float)$r['points'],
        'threshold_value' => $r['threshold_value'] === null ? null : (int)$r['threshold_value'],
        'cap_points'      => (float)$r['cap_points'],
        'is_enabled'      => (int)$r['is_enabled'] === 1,
        'sort_order'      => (int)$r['sort_order'],
    ];
}
function uni_fetch_exams($conn, $uid) {
    $out = [];
    $res = mysqli_query($conn, "SELECT * FROM university_exams WHERE user_id = $uid ORDER BY sort_order ASC, id ASC");
    while ($res && ($row = mysqli_fetch_assoc($res))) $out[] = uni_exam_out($row);
    return $out;
}
function uni_fetch_bonuses($conn, $uid) {
    $out = [];
    $res = mysqli_query($conn, "SELECT * FROM university_bonuses WHERE user_id = $uid ORDER BY sort_order ASC, id ASC");
    while ($res && ($row = mysqli_fetch_assoc($res))) $out[] = uni_bonus_out($row);
    return $out;
}
function uni_ensure_settings($conn, $uid) {
    mysqli_query($conn, "INSERT IGNORE INTO university_settings (user_id) VALUES ($uid)");
    $res = mysqli_query($conn, "SELECT * FROM university_settings WHERE user_id = $uid");
    return $res ? mysqli_fetch_assoc($res) : null;
}
function uni_settings_out($s) {
    return [
        'program_key'       => $s['program_key'],
        'program_label'     => $s['program_label'],
        'faculty_key'       => $s['faculty_key'],
        'accent_color'      => uni_color($s['accent_color']),
        'total_cfu'         => (int)$s['total_cfu'],
        'grade_base'        => (int)$s['grade_base'],
        'honors_value'      => (float)$s['honors_value'],
        'target_average'    => $s['target_average'] === null ? null : (float)$s['target_average'],
        'target_source'     => $s['target_source'],
        'thesis_min_points' => (float)$s['thesis_min_points'],
        'thesis_max_points' => (float)$s['thesis_max_points'],
        'round_half_up'     => (int)$s['round_half_up'] === 1,
    ];
}

/* campi esame comuni (add/update) → frammento "col=val, ..." per la query */
function uni_exam_assignments($conn) {
    $name    = uni_str_or_null($conn, trim((string)($_POST['name'] ?? '')), 200);
    if ($name === 'NULL') $name = "'Nuovo esame'";
    $cfu     = max(0, min(90, (float)($_POST['cfu'] ?? 0)));
    $status  = ($_POST['status'] ?? 'todo') === 'done' ? 'done' : 'todo';
    $isPF    = !empty($_POST['is_pass_fail']) ? 1 : 0;
    $honors  = !empty($_POST['has_honors']) ? 1 : 0;
    $predHon = !empty($_POST['predicted_honors']) ? 1 : 0;
    $counts  = isset($_POST['counts_for_graduation']) && $_POST['counts_for_graduation'] === '0' ? 0 : 1;
    // se idoneità o lode, il voto numerico non ha senso / è forzato
    $grade   = $isPF ? 'NULL' : uni_int_or_null($_POST['grade'] ?? null, 18, 30);
    if ($honors) $grade = '30';
    $predG   = $isPF ? 'NULL' : uni_int_or_null($_POST['predicted_grade'] ?? null, 18, 30);
    if ($predHon) $predG = '30';
    $date    = $status === 'done' ? uni_date_or_null($conn, $_POST['exam_date'] ?? null) : 'NULL';
    $ssd     = uni_str_or_null($conn, $_POST['ssd'] ?? null, 20);
    $year    = uni_int_or_null($_POST['study_year'] ?? null, 1, 9);
    $catalog = uni_str_or_null($conn, $_POST['catalog_code'] ?? null, 60);
    $natRaw  = $_POST['national_avg'] ?? null;
    $nat     = ($natRaw === null || $natRaw === '' || strtolower((string)$natRaw) === 'null')
                 ? 'NULL' : (string)max(18, min(30, (float)$natRaw));
    $group   = uni_int_or_null($_POST['group_id'] ?? null, 1, 2147483647);
    return "name=$name, cfu=$cfu, status='$status', grade=$grade, has_honors=$honors, "
         . "is_pass_fail=$isPF, counts_for_graduation=$counts, exam_date=$date, "
         . "predicted_grade=$predG, predicted_honors=$predHon, ssd=$ssd, study_year=$year, "
         . "national_avg=$nat, catalog_code=$catalog, group_id=$group";
}

/* gruppi di esami: "choice" = esami a scelta (contano solo N cfu su quelli selezionati),
   "split" = esame suddiviso in più parti (i cfu si sommano, un voto complessivo li riassume) */
function uni_group_out($r) {
    return [
        'id'                     => (int)$r['id'],
        'name'                   => $r['name'],
        'group_type'             => (isset($r['group_type']) && $r['group_type'] === 'split') ? 'split' : 'choice',
        'cfu'                    => (float)$r['cfu'],
        'overall_grade'          => (!isset($r['overall_grade']) || $r['overall_grade'] === null) ? null : (int)$r['overall_grade'],
        'overall_honors'         => isset($r['overall_honors']) && (int)$r['overall_honors'] === 1,
        'overall_predicted_grade'=> (!isset($r['overall_predicted_grade']) || $r['overall_predicted_grade'] === null) ? null : (int)$r['overall_predicted_grade'],
        'overall_predicted_honors'=> isset($r['overall_predicted_honors']) && (int)$r['overall_predicted_honors'] === 1,
        'sort_order'             => (int)$r['sort_order'],
    ];
}
function uni_fetch_groups($conn, $uid) {
    $out = [];
    $res = mysqli_query($conn, "SELECT * FROM university_exam_groups WHERE user_id = $uid ORDER BY sort_order ASC, id ASC");
    if ($res) { while ($row = mysqli_fetch_assoc($res)) $out[] = uni_group_out($row); }
    return $out;
}

/* Riempie i campi mancanti in $_POST con i valori già presenti a database,
   così un aggiornamento parziale non azzera le altre colonne. */
function uni_merge_exam_post($conn, $id, $uid) {
    $res = mysqli_query($conn, "SELECT * FROM university_exams WHERE id = $id AND user_id = $uid");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) return null;
    $cols = ['name','cfu','status','grade','has_honors','is_pass_fail','counts_for_graduation',
             'exam_date','predicted_grade','predicted_honors','ssd','study_year',
             'national_avg','catalog_code','group_id'];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $_POST)) {
            $_POST[$c] = ($row[$c] === null) ? 'null' : $row[$c];
        }
    }
    return $row;
}

/* -------------------------------------------------------------------------- */
/* Router AJAX                                                                 */
/* -------------------------------------------------------------------------- */
if (isset($_GET['ajax'])) {
    $action = $_GET['ajax'];
    $uid = $current_user_id;

    if ($action === 'save_settings') {
        $programKey   = uni_str_or_null($conn, $_POST['program_key'] ?? null, 80);
        $programLabel = uni_str_or_null($conn, $_POST['program_label'] ?? null, 160);
        $facultyKey   = uni_str_or_null($conn, $_POST['faculty_key'] ?? null, 60);
        $accent       = "'" . uni_color($_POST['accent_color'] ?? null) . "'";
        $totalCfu     = max(1, min(600, (int)($_POST['total_cfu'] ?? 180)));
        $gradeBase    = max(30, min(200, (int)($_POST['grade_base'] ?? 110)));
        $honorsValue  = max(30, min(40, (float)($_POST['honors_value'] ?? 30)));
        $thMin        = max(0, min(30, (float)($_POST['thesis_min_points'] ?? 0)));
        $thMax        = max($thMin, min(30, (float)($_POST['thesis_max_points'] ?? 0)));
        $round        = (isset($_POST['round_half_up']) && $_POST['round_half_up'] === '0') ? 0 : 1;
        uni_ensure_settings($conn, $uid);
        mysqli_query($conn, "UPDATE university_settings SET
            program_key=$programKey, program_label=$programLabel, faculty_key=$facultyKey,
            accent_color=$accent, total_cfu=$totalCfu, grade_base=$gradeBase,
            honors_value=$honorsValue, thesis_min_points=$thMin, thesis_max_points=$thMax,
            round_half_up=$round WHERE user_id=$uid");
        $s = uni_ensure_settings($conn, $uid);
        uni_json(['success' => true, 'settings' => uni_settings_out($s)]);
    }

    if ($action === 'save_target') {
        $tgtRaw = $_POST['target_average'] ?? null;
        $tgt = ($tgtRaw === null || $tgtRaw === '' || strtolower((string)$tgtRaw) === 'null')
                 ? 'NULL' : (string)max(18, min(31, (float)$tgtRaw));
        $src = ($_POST['target_source'] ?? 'manual') === 'simulation' ? 'simulation' : 'manual';
        uni_ensure_settings($conn, $uid);
        mysqli_query($conn, "UPDATE university_settings SET target_average=$tgt, target_source='$src' WHERE user_id=$uid");
        uni_json(['success' => true]);
    }

    if ($action === 'add_exam') {
        $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_exams WHERE user_id=$uid");
        $ord = (int)(mysqli_fetch_assoc($ordRes)['n'] ?? 1);
        $assign = uni_exam_assignments($conn);
        $ok = mysqli_query($conn, "INSERT INTO university_exams SET user_id=$uid, sort_order=$ord, $assign");
        if (!$ok) uni_json(['success' => false, 'error' => 'db_error']);
        $newId = (int)mysqli_insert_id($conn);
        $res = mysqli_query($conn, "SELECT * FROM university_exams WHERE id=$newId AND user_id=$uid");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        uni_json($row ? ['success' => true, 'exam' => uni_exam_out($row)] : ['success' => false, 'error' => 'not_found']);
    }

    if ($action === 'update_exam') {
        $id = (int)($_POST['id'] ?? 0);
        // i campi non inviati mantengono il valore già a database
        if (uni_merge_exam_post($conn, $id, $uid) === null) uni_json(['success' => false, 'error' => 'not_found']);
        $assign = uni_exam_assignments($conn);
        // IMPORTANTE: se questa query fallisce (es. dato non valido), NON deve sembrare riuscita:
        // in passato si tornava comunque la riga esistente (invariata) con success:true, e il client
        // applicava comunque in locale lo stato "aggiornato" → sembrava funzionare ma non veniva salvato.
        $ok = mysqli_query($conn, "UPDATE university_exams SET $assign WHERE id=$id AND user_id=$uid");
        if (!$ok) uni_json(['success' => false, 'error' => 'db_error']);
        $res = mysqli_query($conn, "SELECT * FROM university_exams WHERE id=$id AND user_id=$uid");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        uni_json($row ? ['success' => true, 'exam' => uni_exam_out($row)] : ['success' => false, 'error' => 'not_found']);
    }

    if ($action === 'set_prediction') {
        $id = (int)($_POST['id'] ?? 0);
        $predHon = !empty($_POST['predicted_honors']) ? 1 : 0;
        $predG = uni_int_or_null($_POST['predicted_grade'] ?? null, 18, 30);
        if ($predHon) $predG = '30';
        mysqli_query($conn, "UPDATE university_exams SET predicted_grade=$predG, predicted_honors=$predHon WHERE id=$id AND user_id=$uid");
        uni_json(['success' => true]);
    }

    if ($action === 'delete_exam') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM university_exams WHERE id=$id AND user_id=$uid");
        uni_json(['success' => true]);
    }

    /* elimina più esami in un colpo solo */
    if ($action === 'delete_exams') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $clean = [];
        if (is_array($ids)) { foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $clean[] = $i; } }
        if (!empty($clean)) {
            $in = implode(',', $clean);
            mysqli_query($conn, "DELETE FROM university_exams WHERE id IN ($in) AND user_id=$uid");
        }
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    /* crea un gruppo (esami a scelta con quota di CFU, oppure esame suddiviso in più parti) */
    if ($action === 'create_group') {
        $name = uni_str_or_null($conn, trim((string)($_POST['name'] ?? '')), 120);
        if ($name === 'NULL') $name = "'Gruppo'";
        $type = ($_POST['group_type'] ?? 'choice') === 'split' ? 'split' : 'choice';
        $ids  = json_decode($_POST['ids'] ?? '[]', true);
        $clean = [];
        if (is_array($ids)) { foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $clean[] = $i; } }
        if (count($clean) < 2) uni_json(['success' => false, 'error' => 'need_two']);
        $in = implode(',', $clean);
        if ($type === 'split') {
            // "esame suddiviso": i CFU delle parti si sommano sempre
            $cRes = mysqli_query($conn, "SELECT COALESCE(SUM(cfu),0) AS c FROM university_exams WHERE id IN ($in) AND user_id=$uid");
            $cfu  = (float)(mysqli_fetch_assoc($cRes)['c'] ?? 0);
        } else {
            // "esami a scelta": la quota è scelta dallo studente (deve essere una somma raggiungibile
            // con i CFU delle parti selezionate); in mancanza, ripiega sul CFU massimo tra le parti
            $cRes = mysqli_query($conn, "SELECT MAX(cfu) AS mx, SUM(cfu) AS sm FROM university_exams WHERE id IN ($in) AND user_id=$uid");
            $row  = mysqli_fetch_assoc($cRes);
            $fallback = (float)($row['mx'] ?? 0);
            $quotaRaw = $_POST['cfu'] ?? null;
            $cfu = ($quotaRaw !== null && $quotaRaw !== '' && strtolower((string)$quotaRaw) !== 'null')
                     ? max(0, min((float)($row['sm'] ?? 0), (float)$quotaRaw)) : $fallback;
        }
        $oRes = mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_exam_groups WHERE user_id=$uid");
        $ord  = (int)(mysqli_fetch_assoc($oRes)['n'] ?? 1);
        mysqli_query($conn, "INSERT INTO university_exam_groups SET user_id=$uid, name=$name, group_type='$type', cfu=$cfu, sort_order=$ord");
        $gid = (int)mysqli_insert_id($conn);
        if ($gid > 0) mysqli_query($conn, "UPDATE university_exams SET group_id=$gid WHERE id IN ($in) AND user_id=$uid");
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    /* modifica un gruppo esistente: nome, quota CFU (esami a scelta), voto complessivo (esame suddiviso)
       e/o la composizione (aggiunta/rimozione di esami) */
    if ($action === 'update_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $gRes = mysqli_query($conn, "SELECT * FROM university_exam_groups WHERE id=$gid AND user_id=$uid");
        $grow = $gRes ? mysqli_fetch_assoc($gRes) : null;
        if (!$grow) uni_json(['success' => false, 'error' => 'not_found']);
        $type = isset($_POST['group_type']) ? (($_POST['group_type'] === 'split') ? 'split' : 'choice') : (($grow['group_type'] ?? 'choice') === 'split' ? 'split' : 'choice');

        // eventuale sostituzione completa della composizione del gruppo
        if (array_key_exists('ids', $_POST)) {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            $clean = [];
            if (is_array($ids)) { foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $clean[] = $i; } }
            if (count($clean) < 2) uni_json(['success' => false, 'error' => 'need_two']);
            $in = implode(',', $clean);
            mysqli_query($conn, "UPDATE university_exams SET group_id=NULL WHERE group_id=$gid AND user_id=$uid AND id NOT IN ($in)");
            mysqli_query($conn, "UPDATE university_exams SET group_id=$gid WHERE id IN ($in) AND user_id=$uid");
        }

        $name = array_key_exists('name', $_POST) ? uni_str_or_null($conn, trim((string)$_POST['name']), 120) : null;
        if ($name === 'NULL') $name = "'Gruppo'";

        // ricalcola i CFU coerentemente col tipo
        $memRes = mysqli_query($conn, "SELECT MAX(cfu) AS mx, SUM(cfu) AS sm FROM university_exams WHERE group_id=$gid AND user_id=$uid");
        $mem = mysqli_fetch_assoc($memRes);
        if ($type === 'split') {
            $cfu = (float)($mem['sm'] ?? 0);
        } else if (array_key_exists('cfu', $_POST) && $_POST['cfu'] !== '' && strtolower((string)$_POST['cfu']) !== 'null') {
            $cfu = max(0, min((float)($mem['sm'] ?? 0), (float)$_POST['cfu']));
        } else {
            $cfu = (float)$grow['cfu'];
        }

        $sets = ["group_type='$type'", "cfu=$cfu"];
        if ($name !== null) $sets[] = "name=$name";
        if (array_key_exists('overall_grade', $_POST)) $sets[] = "overall_grade=" . uni_int_or_null($_POST['overall_grade'], 18, 30);
        if (array_key_exists('overall_honors', $_POST)) $sets[] = "overall_honors=" . (!empty($_POST['overall_honors']) ? 1 : 0);
        if (array_key_exists('overall_predicted_grade', $_POST)) $sets[] = "overall_predicted_grade=" . uni_int_or_null($_POST['overall_predicted_grade'], 18, 30);
        if (array_key_exists('overall_predicted_honors', $_POST)) $sets[] = "overall_predicted_honors=" . (!empty($_POST['overall_predicted_honors']) ? 1 : 0);
        mysqli_query($conn, "UPDATE university_exam_groups SET " . implode(',', $sets) . " WHERE id=$gid AND user_id=$uid");
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    /* scioglie il gruppo: gli esami restano, senza più vincolo di CFU condivisi */
    if ($action === 'dissolve_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        mysqli_query($conn, "UPDATE university_exams SET group_id=NULL WHERE group_id=$gid AND user_id=$uid");
        mysqli_query($conn, "DELETE FROM university_exam_groups WHERE id=$gid AND user_id=$uid");
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    /* elimina l'intero gruppo: tutti gli esami che lo compongono, più il gruppo stesso */
    if ($action === 'delete_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM university_exams WHERE group_id=$gid AND user_id=$uid");
        mysqli_query($conn, "DELETE FROM university_exam_groups WHERE id=$gid AND user_id=$uid");
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    /* dopo aver superato un esame del gruppo: elimina gli altri ancora da sostenere */
    if ($action === 'clear_group_todo') {
        $gid  = (int)($_POST['group_id'] ?? 0);
        $keep = (int)($_POST['keep_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM university_exams WHERE group_id=$gid AND user_id=$uid AND status='todo' AND id<>$keep");
        mysqli_query($conn, "UPDATE university_exams SET group_id=NULL WHERE group_id=$gid AND user_id=$uid");
        mysqli_query($conn, "DELETE FROM university_exam_groups WHERE id=$gid AND user_id=$uid");
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid), 'groups' => uni_fetch_groups($conn, $uid)]);
    }

    if ($action === 'reorder_exams') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            $i = 1;
            foreach ($order as $eid) {
                $eid = (int)$eid;
                mysqli_query($conn, "UPDATE university_exams SET sort_order=$i WHERE id=$eid AND user_id=$uid");
                $i++;
            }
        }
        uni_json(['success' => true]);
    }

    if ($action === 'import_program') {
        $exams = json_decode($_POST['exams'] ?? '[]', true);
        $mode  = ($_POST['mode'] ?? 'replace') === 'append' ? 'append' : 'replace';
        if ($mode === 'replace') {
            mysqli_query($conn, "DELETE FROM university_exams WHERE user_id=$uid");
            mysqli_query($conn, "DELETE FROM university_exam_groups WHERE user_id=$uid");
        }
        // meta corso (facoltativi) → aggiorno anche le impostazioni
        uni_ensure_settings($conn, $uid);
        $sets = [];
        if (isset($_POST['program_key']))   $sets[] = "program_key="   . uni_str_or_null($conn, $_POST['program_key'], 80);
        if (isset($_POST['program_label'])) $sets[] = "program_label=" . uni_str_or_null($conn, $_POST['program_label'], 160);
        if (isset($_POST['faculty_key']))   $sets[] = "faculty_key="   . uni_str_or_null($conn, $_POST['faculty_key'], 60);
        if (isset($_POST['accent_color']))  $sets[] = "accent_color='" . uni_color($_POST['accent_color']) . "'";
        if (isset($_POST['total_cfu']))     $sets[] = "total_cfu="     . max(1, min(600, (int)$_POST['total_cfu']));
        if (isset($_POST['grade_base']))    $sets[] = "grade_base="    . max(30, min(200, (int)$_POST['grade_base']));
        if (isset($_POST['thesis_min_points'])) $sets[] = "thesis_min_points=" . max(0, min(30, (float)$_POST['thesis_min_points']));
        if (isset($_POST['thesis_max_points'])) $sets[] = "thesis_max_points=" . max(0, min(30, (float)$_POST['thesis_max_points']));
        if (isset($_POST['honors_value']))  $sets[] = "honors_value="  . max(30, min(40, (float)$_POST['honors_value']));
        if (!empty($sets)) mysqli_query($conn, "UPDATE university_settings SET " . implode(',', $sets) . " WHERE user_id=$uid");

        $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0) AS n FROM university_exams WHERE user_id=$uid");
        $ord = (int)(mysqli_fetch_assoc($ordRes)['n'] ?? 0);
        if (is_array($exams)) {
            foreach ($exams as $e) {
                $ord++;
                $name = uni_str_or_null($conn, trim((string)($e['name'] ?? 'Esame')), 200);
                if ($name === 'NULL') $name = "'Esame'";
                $cfu  = max(0, min(90, (float)($e['cfu'] ?? 0)));
                $isPF = !empty($e['pass_fail']) ? 1 : 0;
                $ssd  = uni_str_or_null($conn, $e['ssd'] ?? null, 20);
                $year = uni_int_or_null($e['year'] ?? null, 1, 9);
                $cat  = uni_str_or_null($conn, $e['code'] ?? null, 60);
                $natR = $e['national_avg'] ?? null;
                $nat  = ($natR === null || $natR === '') ? 'NULL' : (string)max(18, min(30, (float)$natR));
                mysqli_query($conn, "INSERT INTO university_exams SET user_id=$uid, sort_order=$ord,
                    name=$name, cfu=$cfu, status='todo', is_pass_fail=$isPF,
                    ssd=$ssd, study_year=$year, catalog_code=$cat, national_avg=$nat");
            }
        }
        $s = uni_ensure_settings($conn, $uid);
        uni_json(['success' => true, 'exams' => uni_fetch_exams($conn, $uid),
                  'groups' => uni_fetch_groups($conn, $uid), 'settings' => uni_settings_out($s)]);
    }

    if ($action === 'add_bonus' || $action === 'update_bonus') {
        $name  = uni_str_or_null($conn, trim((string)($_POST['name'] ?? '')), 120);
        if ($name === 'NULL') $name = "'Bonus'";
        $allowed = ['fixed','on_time','honors_each','honors_threshold','custom'];
        $type  = in_array($_POST['bonus_type'] ?? '', $allowed, true) ? $_POST['bonus_type'] : 'fixed';
        $pts   = max(-20, min(20, (float)($_POST['points'] ?? 0)));
        $cap   = max(0, min(40, (float)($_POST['cap_points'] ?? 0)));
        $thr   = uni_int_or_null($_POST['threshold_value'] ?? null, 0, 100);
        $en    = (isset($_POST['is_enabled']) && $_POST['is_enabled'] === '0') ? 0 : 1;
        if ($action === 'add_bonus') {
            $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM university_bonuses WHERE user_id=$uid");
            $ord = (int)(mysqli_fetch_assoc($ordRes)['n'] ?? 1);
            mysqli_query($conn, "INSERT INTO university_bonuses SET user_id=$uid, sort_order=$ord,
                name=$name, bonus_type='$type', points=$pts, cap_points=$cap, threshold_value=$thr, is_enabled=$en");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            mysqli_query($conn, "UPDATE university_bonuses SET name=$name, bonus_type='$type', points=$pts,
                cap_points=$cap, threshold_value=$thr, is_enabled=$en WHERE id=$id AND user_id=$uid");
        }
        uni_json(['success' => true, 'bonuses' => uni_fetch_bonuses($conn, $uid)]);
    }

    if ($action === 'toggle_bonus') {
        $id = (int)($_POST['id'] ?? 0);
        $en = !empty($_POST['is_enabled']) ? 1 : 0;
        mysqli_query($conn, "UPDATE university_bonuses SET is_enabled=$en WHERE id=$id AND user_id=$uid");
        uni_json(['success' => true]);
    }

    if ($action === 'delete_bonus') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM university_bonuses WHERE id=$id AND user_id=$uid");
        uni_json(['success' => true]);
    }

    uni_json(['success' => false, 'error' => 'unknown_action']);
}

/* -------------------------------------------------------------------------- */
/* Bootstrap dati per il render                                                */
/* -------------------------------------------------------------------------- */
$settingsRow = uni_ensure_settings($conn, $current_user_id);
$UNI_SETTINGS = uni_settings_out($settingsRow);
$UNI_EXAMS    = uni_fetch_exams($conn, $current_user_id);
$UNI_BONUSES  = uni_fetch_bonuses($conn, $current_user_id);
$UNI_GROUPS   = uni_fetch_groups($conn, $current_user_id);
$UNI_HAS_DATA = count($UNI_EXAMS) > 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no">
<title>Voti</title>
<style>
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#E7E7EC;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.2);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6; --yellow:#FFCC00; --gold:#E8A600; --silver:#B7BEC5;
    --green-soft:rgba(52,199,89,.14); --red-soft:rgba(255,59,48,.12); --orange-soft:rgba(255,149,0,.15);
    --blue-soft:rgba(0,122,255,.1); --purple-soft:rgba(175,82,222,.12);
    --header-glass:rgba(249,249,251,.8);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --shadow-hero:0 2px 4px rgba(0,0,0,.05), 0 20px 48px -12px rgba(0,0,0,.16);
    --shadow-sheet:0 -14px 60px rgba(0,0,0,.2);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.28), inset 0 -1px 0 rgba(0,0,0,.14);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px; --r-btn:15px; --r-chip:11px;
    --spring:cubic-bezier(.32,.72,0,1);
    --pop:cubic-bezier(.34,1.4,.64,1);
    --ease:cubic-bezier(.4,0,.2,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
    --uni-accent:#0A84FF; --uni-accent-2:#5E5CE6;
    --uni-grad:linear-gradient(135deg, var(--uni-accent), var(--uni-accent-2));
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#3A3A3C;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
        --teal:#40C8E0; --pink:#FF375F; --indigo:#5E5CE6; --yellow:#FFD60A; --gold:#FFCC33; --silver:#9AA3AA;
        --green-soft:rgba(48,209,88,.18); --red-soft:rgba(255,69,58,.18); --orange-soft:rgba(255,159,10,.2);
        --blue-soft:rgba(10,132,255,.18); --purple-soft:rgba(191,90,242,.2);
        --header-glass:rgba(16,16,18,.76);
        --shadow-card:0 1px 1px rgba(0,0,0,.4), 0 10px 30px rgba(0,0,0,.55);
        --shadow-hero:0 2px 6px rgba(0,0,0,.5), 0 24px 56px -12px rgba(0,0,0,.7);
        --shadow-sheet:0 -14px 60px rgba(0,0,0,.6);
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.16), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.5), 0 5px 12px -4px rgba(0,0,0,.6);
    }
}
*{
    box-sizing: border-box; 
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
html,body{margin:0; padding:0;}
body{
    font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display",system-ui,"Segoe UI",Roboto,sans-serif;
    background:var(--bg); color:var(--text); min-height:100vh;
    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
    overflow-x:hidden;
}
.tabular{font-variant-numeric:tabular-nums; font-feature-settings:"tnum" 1;}

/* ---------------- HEADER ---------------- */
.app-header{
    position:sticky; top:0; z-index:60;
    padding:calc(var(--safe-top) + 10px) 16px 10px;
    display:flex; align-items:center; gap:10px;
    background:var(--header-glass);
    -webkit-backdrop-filter:blur(26px) saturate(1.7); backdrop-filter:blur(26px) saturate(1.7);
    border-bottom:1px solid var(--hairline);
}
.hdr-title{font-size:1.32rem; font-weight:800; letter-spacing:-.02em; margin-right:auto; line-height:1;}
.hdr-degree{
    display:flex; align-items:center; gap:7px; max-width:52vw;
    padding:7px 12px; border-radius:999px; border:none; cursor:pointer;
    background:var(--surface); color:var(--text); font-family:inherit;
    font-size:.82rem; font-weight:700; box-shadow:var(--raise-sm);
    transition:transform .2s var(--spring);
}
.hdr-degree:active{transform:scale(.94);}
.hdr-degree .dot{width:9px; height:9px; border-radius:50%; background:var(--uni-grad); flex-shrink:0;}
.hdr-degree span{overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
.hdr-icon-btn{
    width:38px; height:38px; flex-shrink:0; border-radius:50%; border:none; cursor:pointer;
    background:var(--surface); color:var(--uni-accent); box-shadow:var(--raise-sm);
    display:flex; align-items:center; justify-content:center;
    transition:transform .2s var(--spring);
}
.hdr-icon-btn:active{transform:scale(.9) rotate(-25deg);}
.hdr-icon-btn svg{width:19px; height:19px;}

/* ---------------- PAGE / CARDS ---------------- */
.page{max-width:640px; margin:0 auto; padding:14px 14px calc(120px + var(--safe-bottom));}
.uni-card{
    background:var(--surface); border-radius:var(--r-card); padding:18px;
    box-shadow:var(--shadow-card); margin-bottom:14px; position:relative;
}
.pop{opacity:0; transform:translateY(16px) scale(.985); animation:pop-in .5s var(--spring) forwards;}
@keyframes pop-in{to{opacity:1; transform:none;}}
.sec-head{display:flex; align-items:center; gap:9px; margin:2px 2px 14px;}
.sec-head h2{font-size:1.02rem; font-weight:800; letter-spacing:-.01em; margin:0;}
.sec-head .sec-ic{width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:14px; flex-shrink:0;}
.sec-head .sec-sub{margin-left:auto; font-size:.78rem; color:var(--text-2); font-weight:600;}
.sec-head .sec-act{margin-left:auto; border:none; background:var(--uni-accent); color:#fff; font-family:inherit; font-weight:700; font-size:.78rem; padding:7px 13px; border-radius:999px; cursor:pointer; box-shadow:var(--btn-hl); transition:transform .18s var(--spring);}
.sec-head .sec-act:active{transform:scale(.92);}

/* ---------------- HERO ---------------- */
.hero{
    background:var(--surface); border-radius:26px; padding:22px 20px 20px;
    box-shadow:var(--shadow-hero); margin-bottom:14px; overflow:hidden; position:relative;
}
.hero::before{
    content:""; position:absolute; inset:0; opacity:.1; pointer-events:none;
    background:radial-gradient(120% 90% at 50% -10%, var(--uni-accent), transparent 60%);
}
.hero-top{display:flex; align-items:center; gap:20px; position:relative;}
.hero-ring{width:132px; height:132px; flex-shrink:0; position:relative;}
.hero-ring svg{width:100%; height:100%; transform:rotate(-90deg);}
.hero-ring .ring-center{
    position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1px;
}
.hero-ring .ring-frac{font-size:1.5rem; font-weight:800; letter-spacing:-.03em; line-height:1;}
.hero-ring .ring-frac small{font-size:.9rem; color:var(--text-2); font-weight:700;}
.hero-ring .ring-lbl{font-size:.6rem; font-weight:800; letter-spacing:.7px; text-transform:uppercase; color:var(--text-2);}
.hero-main{flex:1; min-width:0;}
.hero-main .kicker{font-size:.68rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); margin-bottom:2px;}
.hero-avg{font-size:3.5rem; font-weight:800; letter-spacing:-.045em; line-height:.94; display:flex; align-items:baseline; gap:2px;}
.hero-avg .dec{font-size:2.1rem; color:var(--text-2);}
.hero-delta{display:inline-flex; align-items:center; gap:4px; margin-top:8px; padding:4px 10px; border-radius:999px; font-size:.8rem; font-weight:800;}
.hero-delta.up{background:var(--green-soft); color:var(--green);}
.hero-delta.down{background:var(--red-soft); color:var(--red);}
.hero-delta.flat{background:var(--surface-3); color:var(--text-2);}
.hero-stats{display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:18px; position:relative; transition:grid-template-columns .25s var(--ease);}
.hero-stats.cols-2{grid-template-columns:repeat(2,1fr);}
.hero-stat{background:var(--surface-2); border-radius:15px; padding:11px 8px; text-align:center;}
.hero-stat .v{font-size:1.18rem; font-weight:800; letter-spacing:-.02em; line-height:1;}
.hero-stat .v small{font-size:.75rem; color:var(--text-2); font-weight:700;}
.hero-stat .l{font-size:.62rem; font-weight:700; letter-spacing:.3px; text-transform:uppercase; color:var(--text-2); margin-top:4px;}
.hero-range{margin-top:14px; background:var(--surface-2); border-radius:15px; padding:12px 14px; position:relative;}
.hero-range .rlbl{font-size:.68rem; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:var(--text-2); display:flex; justify-content:space-between; align-items:center;}
.hero-range .rval{font-size:1.3rem; font-weight:800; letter-spacing:-.02em; margin-top:2px; color:var(--uni-accent);}
.hero-range .rval .denom{color:var(--text-2); font-weight:700;}
.hero-range .thesis-vis{margin-top:8px !important;}
/* massimo raggiungibile (110 e lode) → oro luccicante */
.bar-gold{background:linear-gradient(120deg,#B8860B,#FFD700 45%,#E8A600 70%,#FFF1A8) !important;
    box-shadow:0 0 12px rgba(232,166,0,.55); overflow:hidden;}
.bar-gold::after{content:""; position:absolute; top:0; left:-60%; width:45%; height:100%;
    background:linear-gradient(105deg,transparent,rgba(255,255,255,.92),transparent); animation:shimmer 2.6s var(--ease) infinite;}

/* ---------------- CFU BAR ---------------- */
.cfu-wrap{margin-top:16px; position:relative;}
.cfu-legend{display:flex; gap:14px; font-size:.72rem; font-weight:600; color:var(--text-2); margin-bottom:7px;}
.cfu-legend b{color:var(--text); font-weight:800;}
.cfu-legend .k{display:inline-flex; align-items:center; gap:5px;}
.cfu-legend .k::before{content:""; width:10px; height:10px; border-radius:3px;}
.cfu-legend .k.done::before{background:var(--uni-accent);}
.cfu-legend .k.plan::before{background:var(--uni-accent); opacity:.34;}
.cfu-legend .k.left::before{background:var(--surface-3);}
.cfu-track{height:12px; border-radius:6px; background:var(--surface-3); overflow:hidden; display:flex;}
.cfu-track i{height:100%; transition:width 1s var(--spring);}
.cfu-track .f-done{background:var(--uni-grad);}
.cfu-track .f-plan{background:var(--uni-accent); opacity:.32;}

/* ---------------- GENERIC ROWS / CHIPS ---------------- */
.mini-row{display:flex; align-items:center; gap:10px; padding:11px 2px; border-top:1px solid var(--hairline);}
.mini-row:first-child{border-top:none;}
.pill-btn{border:none; background:var(--surface-2); color:var(--text); font-family:inherit; font-weight:700; font-size:.85rem; padding:10px 15px; border-radius:var(--r-btn); cursor:pointer; box-shadow:var(--raise-sm); transition:transform .18s var(--spring);}
.pill-btn:active{transform:scale(.94);}
.pill-btn.accent{background:var(--uni-accent); color:#fff; box-shadow:var(--btn-hl);}
.pill-btn.ghost{background:transparent; box-shadow:none; color:var(--uni-accent);}
.pill-btn.full{width:100%; text-align:center;}

/* voti color scale */
.grade-badge{min-width:44px; height:34px; padding:0 9px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; gap:2px; font-weight:800; font-size:.98rem; letter-spacing:-.01em; flex-shrink:0;}
.g-ido{background:var(--surface-3); color:var(--text-2); font-size:.74rem; letter-spacing:.3px;}
.g-todo{background:var(--surface-2); color:var(--text-3); border:1.5px dashed var(--hairline-2); font-size:.72rem;}
/* voti 29 e oltre: fissi, "decisi" */
.g-30{background:linear-gradient(120deg,#B8860B,#FFD700 45%,#E8A600 70%,#FFF1A8); color:#5a3d00; box-shadow:0 2px 9px rgba(232,166,0,.32);}
.g-29{background:#E8A600; color:#3a2700;}
.g-28{background:var(--silver); color:#2b3136;}
@media (prefers-color-scheme: dark){ .g-30{color:#2a1c00;} .g-29{background:#FFCC33; color:#3a2700;} .g-28{color:#14171a;} }
/* voti 18-27: colore calcolato in base alla distanza dalla media dello studente (vedi gradeDynamicStyle in JS) */
.g-dyn{transition:background .3s var(--ease);}
.g-lode{background:linear-gradient(120deg,#B8860B,#FFD700 45%,#E8A600 70%,#FFF1A8); color:#5a3d00; position:relative; overflow:hidden; box-shadow:0 2px 10px rgba(232,166,0,.4);}
@media (prefers-color-scheme: dark){ .g-lode{color:#2a1c00;} }
.g-lode::after{content:""; position:absolute; top:0; left:-60%; width:40%; height:100%; background:linear-gradient(105deg,transparent,rgba(255,255,255,.85),transparent); animation:shimmer 2.6s var(--ease) infinite;}
@keyframes shimmer{0%{left:-60%;} 55%,100%{left:130%;}}
.g-lode .lode-star{font-size:.7em; margin-left:1px;}

/* ---------------- EXAMS ---------------- */
.seg{display:flex; background:var(--surface-2); border-radius:12px; padding:3px; margin-bottom:12px; position:relative;}
.seg button{flex:1; border:none; background:transparent; font-family:inherit; font-weight:700; font-size:.85rem; color:var(--text-2); padding:9px 4px; border-radius:9px; cursor:pointer; position:relative; z-index:1; transition:color .25s var(--ease);}
.seg button.on{color:var(--text);}
.seg .seg-thumb{position:absolute; top:3px; bottom:3px; border-radius:9px; background:var(--surface); box-shadow:var(--raise-sm); transition:transform .34s var(--spring), width .34s var(--spring); z-index:0;}
.seg .cnt{display:inline-block; min-width:18px; margin-left:4px; font-size:.72rem; opacity:.7;}

.exam-list{list-style:none; margin:0; padding:0;}
.exam-item{display:flex; align-items:center; gap:12px; padding:12px 4px; border-top:1px solid var(--hairline); position:relative; transition:opacity .3s, transform .3s var(--spring);}
.exam-item:first-child{border-top:none;}
.exam-item.dragging{opacity:.55;}
.exam-drag{color:var(--text-3); cursor:grab; touch-action:none; flex-shrink:0; display:flex; padding:2px; margin-left:-2px;}
.exam-drag svg{width:16px; height:16px;}
.exam-body{flex:1; min-width:0; cursor:pointer;}
.exam-trail{display:flex; align-items:center; gap:10px; margin-left:auto; flex-shrink:0;}
.exam-name{font-size:.96rem; font-weight:600; line-height:1.2; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;}
.exam-meta{font-size:.75rem; color:var(--text-2); margin-top:3px; display:flex; flex-wrap:wrap; gap:4px 10px; align-items:center;}
.exam-meta .nat{color:var(--text-3);}
.exam-meta .cmp{font-weight:800;}
.exam-meta .cmp.up{color:var(--green);} .exam-meta .cmp.down{color:var(--red);}
.exam-check{width:32px; height:32px; border-radius:9px; border:2px solid var(--hairline-2); background:var(--surface-2); cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:transform .2s var(--spring), background .2s;}
.exam-check:active{transform:scale(.85);}
.exam-check svg{width:18px; height:18px; color:#fff; opacity:0; transform:scale(.4); transition:opacity .2s, transform .25s var(--pop);}
/* sostenuto: solo una spunta grossa, disegnata a mano, senza riquadro */
.exam-check.done{background:none; border:none; width:34px; height:34px;}
.exam-check.done svg{width:32px; height:32px; color:var(--green); opacity:1; transform:none;
    stroke-dasharray:44; stroke-dashoffset:0; animation:checkDraw .55s var(--spring) both;}
@keyframes checkDraw{from{stroke-dashoffset:44;} to{stroke-dashoffset:0;}}

/* swipe verso destra per rivelare "elimina" (solo Da sostenere): il bottone sta SOTTO al
   contenuto (z-index inferiore, coperto da uno sfondo opaco), non è mai visibile finché il
   contenuto non viene trascinato di lato — niente overflow/opacity che possa "intravedersi". */
.exam-item.swipeable{padding:0; position:relative; overflow:hidden;}
.exam-item.swipeable .swipe-content{display:flex; align-items:center; gap:12px; padding:12px 4px;
    background:var(--surface); position:relative; z-index:1; touch-action:pan-y; width: 100%;}
.exam-group .exam-item.swipeable .swipe-content{padding:10px 2px; background:var(--surface-2);}
.exam-item.swipeable .swipe-del{position:absolute; top:0; left:0; bottom:0; width:76px; border:none;
    background:var(--red); color:#fff; display:flex; align-items:center; justify-content:center;
    z-index:0; padding:0; cursor:pointer;}
.exam-item.swipeable .swipe-del svg{width:20px; height:20px;}

/* ---------------- SELEZIONE MULTIPLA + GRUPPI ---------------- */
.seg button{font-size:.8rem; padding:9px 2px;}
.seg .cnt{margin-left:3px;}
.exam-item.sel-on{cursor:pointer;}
.exam-item .sel-dot{width:24px; height:24px; border-radius:50%; border:2px solid var(--hairline-2); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; color:#fff; font-size:.8rem; font-weight:900;
    transition:background .18s, border-color .18s, transform .18s var(--spring);}
.exam-item.picked .sel-dot{background:var(--uni-accent); border-color:var(--uni-accent); transform:scale(1.08);}
.exam-item.picked{background:var(--blue-soft); border-radius:12px;}
.grp-tag{display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px;
    background:var(--purple-soft); color:var(--purple); font-weight:800; font-size:.68rem;}
.sel-bar{position:sticky; bottom:calc(10px + var(--safe-bottom)); z-index:20; display:flex; gap:8px; align-items:center;
    margin-top:12px; padding:10px; border-radius:16px; background:var(--surface-2); box-shadow:var(--raise-sm);}
.sel-bar .n{font-size:.82rem; font-weight:800; margin-right:auto; padding-left:4px;}
.sel-bar button{border:none; font-family:inherit; font-weight:800; font-size:.8rem; padding:10px 13px; border-radius:12px; cursor:pointer;
    transition:transform .16s var(--spring);}
.sel-bar button:active{transform:scale(.93);}
.sel-bar .b-grp{background:var(--purple); color:#fff;}
.sel-bar .b-ungrp{background:var(--surface); color:var(--text-2); box-shadow:var(--raise-sm);}
.sel-bar .b-del{background:var(--red-soft); color:var(--red);}
.sim-hint{font-size:.75rem; color:var(--text-3); margin:0 0 10px; line-height:1.35;}

.exam-add{width:100%; margin-top:12px; border:1.5px dashed var(--hairline-2); background:transparent; color:var(--uni-accent); font-family:inherit; font-weight:700; font-size:.9rem; padding:13px; border-radius:var(--r-btn); cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; transition:transform .18s var(--spring), background .2s;}
.exam-add:active{transform:scale(.97); background:var(--blue-soft);}

/* ---------------- GRUPPI (esami compattati) ---------------- */
.exam-group{display:block; padding:10px 4px 12px; border-top:1px solid var(--hairline); border-radius:14px;}
.exam-group:first-child{border-top:none;}
.exam-group-inner{background:var(--surface-2); border-radius:14px; padding:4px 10px; overflow:hidden;}
.exam-group-head{display:flex; align-items:center; gap:8px; padding:9px 2px; flex-wrap:wrap;}
.exam-group-head .gt-name{font-weight:800; font-size:.86rem; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:pointer;}
.gt-badge{display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:999px; background:var(--purple-soft); color:var(--purple); font-weight:800; font-size:.64rem; flex-shrink:0; white-space:nowrap;}
.gt-badge.split{background:var(--blue-soft); color:var(--uni-accent);}
.gt-quota{font-size:.68rem; color:var(--text-2); font-weight:700; flex-shrink:0; white-space:nowrap;}
.gt-edit{border:none; background:none; color:var(--text-3); font-size:1rem; cursor:pointer; padding:4px 6px; flex-shrink:0; line-height:1;}
.exam-group .exam-item{padding:10px 2px; border-top:1px solid var(--hairline-2);}
.exam-group .exam-item.swipeable{padding:0;}
.exam-group .exam-item:first-child{border-top:none;}
.exam-group .exam-name{font-size:.9rem;}
.grp-overall{display:flex; align-items:center; gap:10px; padding:10px 2px 4px; border-top:1.5px dashed var(--hairline-2); margin-top:2px; flex-wrap:nowrap;}
.grp-overall .go-lbl{font-size:.72rem; font-weight:800; color:var(--text-2); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
.grp-overall .stepper .val{min-width:44px;}
.grp-note{font-size:.68rem; color:var(--text-3); padding:2px 4px 6px; line-height:1.35;}
.sim-group{padding:8px 2px 10px; border-top:1px solid var(--hairline);}
.sim-group:first-child{border-top:none;}
.sim-group-inner{background:var(--surface-2); border-radius:14px; padding:2px 10px;}
.sim-group-head{display:flex; align-items:center; gap:8px; padding:9px 2px 2px; flex-wrap:wrap;}
.sim-group .sim-item{border-top:1px solid var(--hairline-2);}
.sim-group .sim-item:first-child{border-top:none;}

/* ---------------- POPUP GRUPPO ---------------- */
.grp-pick-list{list-style:none; margin:0 0 4px; padding:2px 10px; max-height:38vh; overflow-y:auto; -webkit-overflow-scrolling:touch; border:1.5px solid var(--hairline); border-radius:14px;}
.grp-pick-list .exam-item{padding:10px 2px;}
.grp-pick-empty{font-size:.8rem; color:var(--text-3); text-align:center; padding:18px 8px;}
.grp-type-hint{font-size:.74rem; color:var(--text-3); margin:-6px 0 12px; line-height:1.4;}

/* ---------------- CHART ---------------- */
.chart-legend{display:flex; flex-wrap:wrap; gap:14px; font-size:.74rem; font-weight:700; color:var(--text-2); margin-bottom:6px;}
.chart-legend .k{display:inline-flex; align-items:center; gap:6px;}
.chart-legend .k .ln{width:16px; height:3px; border-radius:2px; background:var(--uni-accent);}
.chart-legend .k .ln.obj{background:repeating-linear-gradient(90deg,var(--text-3) 0 5px,transparent 5px 9px); height:2px;}
.chart-legend .k .ln.proj{background:repeating-linear-gradient(90deg,var(--uni-accent) 0 5px,transparent 5px 9px);}
.chart-legend .k .dt{width:9px; height:9px; border-radius:50%; background:var(--uni-accent); border:2px solid var(--surface);}
.chart-box{width:100%; position:relative;}
.chart-box svg{width:100%; display:block; overflow:visible;}
.chart-empty{text-align:center; color:var(--text-2); font-size:.85rem; padding:26px 10px;}
.chart-toggle{display:flex; gap:8px; margin-top:10px; align-items:center; font-size:.8rem; color:var(--text-2); font-weight:600;}
.chart-tip{position:absolute; pointer-events:none; background:var(--text); color:var(--surface); font-size:.72rem; font-weight:700; padding:5px 8px; border-radius:8px; transform:translate(-50%,-115%); white-space:nowrap; opacity:0; transition:opacity .15s; z-index:5; box-shadow:0 6px 18px rgba(0,0,0,.25);}
.chart-tip.show{opacity:1;}

/* ---------------- SIMULATOR ---------------- */
.sim-result{display:flex; align-items:center; gap:14px; background:var(--surface-2); border-radius:16px; padding:14px 16px; margin-bottom:12px;}
.sim-result .now{text-align:center;} .sim-result .arrow{color:var(--text-3); font-size:1.3rem;}
.sim-result .lbl{font-size:.62rem; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:var(--text-2);}
.sim-result .num{font-size:1.7rem; font-weight:800; letter-spacing:-.03em; line-height:1;}
.sim-result .to .num{color:var(--uni-accent);}
.sim-result .diff{margin-left:auto; font-size:.82rem; font-weight:800;}
.sim-list{list-style:none; margin:0; padding:0;}
.sim-item{display:flex; align-items:center; gap:10px; padding:10px 2px; border-top:1px solid var(--hairline);}
.sim-item:first-child{border-top:none;}
.sim-item .nm{flex:1; min-width:0; font-size:.88rem; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
.sim-item .nm small{color:var(--text-2); font-weight:600;}

/* stepper */
.stepper{display:inline-flex; align-items:center; gap:0; background:var(--surface-2); border-radius:11px; overflow:hidden; flex-shrink:0;}
.stepper button{width:36px; height:36px; border:none; background:transparent; color:var(--uni-accent); font-size:1.3rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s;}
.stepper button:active{background:var(--hairline);}
.stepper button:disabled{color:var(--text-3); opacity:.5;}
.stepper .val{min-width:52px; text-align:center; font-weight:800; font-size:1rem; letter-spacing:-.01em;}
.stepper .val.lode{color:var(--gold);}

/* ---------------- STATS ---------------- */
.stat-block{margin-bottom:20px;}
.stat-block:last-child{margin-bottom:0;}
.stat-block > .t{font-size:.82rem; font-weight:800; color:var(--text-2); margin-bottom:10px; display:flex; justify-content:space-between; align-items:baseline;}
.stat-block > .t span{color:var(--text-3); font-weight:600; font-size:.75rem;}
.hist{display:flex; align-items:flex-end; gap:2px; height:110px;}
.hist .col{flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end;}
.hist .col .bar{width:100%; max-width:20px; border-radius:5px 5px 2px 2px; background:var(--uni-grad); min-height:3px; transition:height .8s var(--spring); position:relative;}
.hist .col .bar.lode{background:linear-gradient(180deg,#FFD700,#E8A600);}
.hist .col .bar .cnt{position:absolute; top:-16px; left:50%; transform:translateX(-50%); font-size:.66rem; font-weight:800; color:var(--text-2);}
.hist .col .xl{font-size:.55rem; color:var(--text-3); font-weight:700; letter-spacing:-.03em;}
.ybar{display:flex; align-items:center; gap:10px; margin-bottom:8px;}
.ybar .yl{width:56px; font-size:.76rem; font-weight:700; color:var(--text-2); flex-shrink:0;}
.ybar .yt{flex:1; height:22px; border-radius:7px; background:var(--surface-2); overflow:hidden; position:relative;}
.ybar .yt > i{position:absolute; left:0; top:0; bottom:0; border-radius:7px; background:var(--uni-grad); transition:width .9s var(--spring); min-width:2px;}
.ybar .yt > b{position:absolute; right:8px; top:50%; transform:translateY(-50%); font-size:.74rem; font-weight:800; color:var(--text);}
.ybar .yt.muted > i{opacity:.3;}
.donut-wrap{display:flex; align-items:center; gap:16px;}
.donut-wrap svg{width:96px; height:96px; flex-shrink:0;}
.donut-leg{flex:1; display:flex; flex-direction:column; gap:6px;}
.donut-leg .di{display:flex; align-items:center; gap:8px; font-size:.8rem; font-weight:600;}
.donut-leg .di .sw{width:11px; height:11px; border-radius:3px; flex-shrink:0;}
.donut-leg .di b{margin-left:auto; color:var(--text-2);}
.cmp-line{display:flex; align-items:center; gap:12px; background:var(--surface-2); border-radius:15px; padding:14px; }
.cmp-line .big{font-size:1.9rem; font-weight:800; letter-spacing:-.03em;}
.cmp-line .big.up{color:var(--green);} .cmp-line .big.down{color:var(--red);}
.cmp-line .txt{font-size:.82rem; color:var(--text-2); font-weight:600; line-height:1.35;}
.cmp-line .txt b{color:var(--text);}

/* badges / milestones */
.badge-row{display:flex; gap:8px; flex-wrap:wrap; margin-top:2px;}
.mbadge{display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; font-size:.78rem; font-weight:700; background:var(--surface-2);}
.mbadge.got{background:var(--uni-grad); color:#fff; box-shadow:var(--btn-hl);}
.mbadge .em{font-size:1rem;}

/* ---------------- BONUS / THESIS ---------------- */
.bonus-item{display:flex; align-items:center; gap:12px; padding:13px 2px; border-top:1px solid var(--hairline);}
.bonus-item:first-child{border-top:none;}
.bonus-item .bic{width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.05rem; background:var(--surface-2); flex-shrink:0;}
.bonus-item .bd{flex:1; min-width:0;}
.bonus-item .bd .bn{font-size:.92rem; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
.bonus-item .bd .bs{font-size:.74rem; color:var(--text-2); margin-top:1px;}
.bonus-item .bp{font-weight:800; font-size:.95rem; color:var(--green); flex-shrink:0;}
.bonus-item .bp.zero{color:var(--text-3);}

/* iOS switch */
.sw-toggle{position:relative; width:51px; height:31px; flex-shrink:0; cursor:pointer;}
.sw-toggle input{position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer;}
.sw-toggle .track{position:absolute; inset:0; border-radius:999px; background:var(--surface-3); transition:background .3s var(--ease);}
.sw-toggle .thumb{position:absolute; top:2px; left:2px; width:27px; height:27px; border-radius:50%; background:#fff; box-shadow:0 2px 5px rgba(0,0,0,.25); transition:transform .3s var(--spring);}
.sw-toggle input:checked ~ .track{background:var(--green);}
.sw-toggle input:checked ~ .thumb{transform:translateX(20px);}

.thesis-vis{margin-top:4px;}
.thesis-vis .scale{position:relative; height:8px; border-radius:5px; background:linear-gradient(90deg,var(--red),var(--orange),var(--green)); opacity:.28; margin:26px 4px 8px;}
.thesis-vis .fill{position:absolute; top:0; bottom:0; border-radius:5px; background:var(--uni-grad); opacity:1;}
.thesis-vis .marks{position:relative; height:15px; margin-top:7px;}
.thesis-vis .marks span{position:absolute; top:0; font-size:.66rem; color:var(--text-2); font-weight:700; white-space:nowrap;
    transform:translateX(calc(-1 * var(--fx, 50%)));}
.thesis-vis .marks span.lode{color:var(--gold); font-weight:800;}
.thesis-vis .tick{position:absolute; top:-3px; bottom:-3px; width:2px; border-radius:1px; background:var(--hairline-2); transform:translateX(-50%);}
.thesis-vis .flag{position:absolute; top:-24px; transform:translateX(calc(-1 * var(--fx, 50%))); background:var(--text); color:var(--surface); font-size:.68rem; font-weight:800; padding:3px 7px; border-radius:7px; white-space:nowrap;}
.thesis-vis .flag::after{content:""; position:absolute; left:var(--fx, 50%); bottom:-4px; transform:translateX(-50%); border:4px solid transparent; border-top-color:var(--text);}
.thesis-vis .flag.gold{background:linear-gradient(120deg,#B8860B,#FFD700,#E8A600); color:#3a2700;}
.thesis-vis .flag.gold::after{border-top-color:#E8A600;}

/* ---------------- EMPTY STATE ---------------- */
.empty{text-align:center; padding:34px 20px 40px;}
.empty .em-ic{width:76px; height:76px; margin:0 auto 16px; border-radius:22px; background:var(--uni-grad); display:flex; align-items:center; justify-content:center; font-size:34px; box-shadow:var(--shadow-hero); animation:floaty 3.4s var(--ease) infinite;}
@keyframes floaty{0%,100%{transform:translateY(0);}50%{transform:translateY(-7px);}}
.empty h2{font-size:1.35rem; font-weight:800; letter-spacing:-.02em; margin:0 0 8px;}
.empty p{color:var(--text-2); font-size:.92rem; line-height:1.45; margin:0 auto 20px; max-width:340px;}

/* ---------------- SHEETS / MODALS ---------------- */
.sheet-backdrop{position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,.42); -webkit-backdrop-filter:blur(3px); backdrop-filter:blur(3px); opacity:0; pointer-events:none; transition:opacity .3s var(--ease);}
.sheet-backdrop.open{opacity:1; pointer-events:auto;}
.sheet{position:fixed; left:0; right:0; bottom:0; z-index:9001; background:var(--surface); border-radius:26px 26px 0 0; box-shadow:var(--shadow-sheet); padding:8px 18px calc(20px + var(--safe-bottom)); transform:translateY(101%); transition:transform .42s var(--spring); max-height:92vh; overflow-y:auto; -webkit-overflow-scrolling:touch;}
.sheet.open{transform:translateY(0);}
.sheet .grab{width:38px; height:5px; border-radius:3px; background:var(--hairline-2); margin:6px auto 12px;}
.sheet h3{font-size:1.2rem; font-weight:800; letter-spacing:-.02em; margin:0 0 4px; text-align:center;}
.sheet .sub{text-align:center; color:var(--text-2); font-size:.84rem; margin:0 0 16px;}
.field{margin-bottom:14px;}
.field > label{display:block; font-size:.74rem; font-weight:800; letter-spacing:.4px; text-transform:uppercase; color:var(--text-2); margin-bottom:7px;}
.field input[type=text], .field input[type=number], .field input[type=date], .field select{
    width:100%; border:none; background:var(--surface-2); color:var(--text); font-family:inherit; font-size:1rem; font-weight:600;
    padding:13px 14px; border-radius:13px; outline:none; -webkit-appearance:none; appearance:none;
    border:2px solid transparent; transition:border-color .2s;
}
.field input:focus, .field select:focus{border-color:var(--uni-accent);}
.field select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='3' stroke-linecap='round'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px;}
.field .row2{display:flex; gap:10px;} .field .row2 > *{flex:1;}
.chip-row{display:flex; gap:8px; flex-wrap:wrap;}
.chip-tog{border:none; background:var(--surface-2); color:var(--text); font-family:inherit; font-weight:700; font-size:.85rem; padding:10px 14px; border-radius:12px; cursor:pointer; display:flex; align-items:center; gap:7px; transition:transform .16s var(--spring), background .2s;}
.chip-tog:active{transform:scale(.94);}
.chip-tog.on{background:var(--uni-accent); color:#fff;}
.chip-tog.on.gold{background:linear-gradient(120deg,#E8A600,#FFD700); color:#4a3200;}
.grade-picker{display:flex; align-items:center; justify-content:center; gap:16px; background:var(--surface-2); border-radius:16px; padding:14px; margin-bottom:6px;}
.grade-picker .gbig{font-size:2.6rem; font-weight:800; letter-spacing:-.04em; min-width:96px; text-align:center;}
.grade-picker .gbig.lode{background:linear-gradient(120deg,#B8860B,#FFD700,#E8A600); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;}
.sheet-actions{display:flex; gap:10px; margin-top:8px;}
.sheet-actions button{flex:1; border:none; font-family:inherit; font-weight:800; font-size:1rem; padding:15px; border-radius:15px; cursor:pointer; transition:transform .16s var(--spring);}
.sheet-actions button:active{transform:scale(.96);}
.btn-primary{background:var(--uni-accent); color:#fff; box-shadow:var(--btn-hl);}
.btn-secondary{background:var(--surface-2); color:var(--text);}
.btn-danger{background:var(--red-soft); color:var(--red); flex:0 0 auto !important; width:54px; font-size:1.2rem;}

.prog-list{list-style:none; margin:0; padding:0;}
.prog-opt{display:flex; align-items:center; gap:13px; padding:14px 12px; border-radius:15px; cursor:pointer; margin-bottom:8px; background:var(--surface-2); transition:transform .16s var(--spring);}
.prog-opt:active{transform:scale(.97);}
.prog-opt .pi{width:44px; height:44px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; color:#fff;}
.prog-opt .pt{flex:1; min-width:0;}
.prog-opt .pt .pn{font-weight:700; font-size:.95rem; line-height:1.2;}
.prog-opt .pt .pd{font-size:.76rem; color:var(--text-2); margin-top:2px;}
.prog-opt .chev{color:var(--text-3);}

.color-row{display:flex; gap:10px; flex-wrap:wrap; margin-top:2px;}
.color-dot{width:38px; height:38px; border-radius:50%; cursor:pointer; border:3px solid transparent; transition:transform .16s var(--spring); position:relative;}
.color-dot:active{transform:scale(.88);}
.color-dot.on{transform:scale(1.12);}
.color-dot.on::after{content:"✓"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:900; font-size:1rem; text-shadow:0 1px 3px rgba(0,0,0,.4);}

/* ---------------- TOAST + CONFETTI ---------------- */
#uniToast{position:fixed; left:50%; bottom:calc(26px + var(--safe-bottom)); transform:translate(-50%,20px); z-index:9500; background:var(--text); color:var(--surface); font-weight:700; font-size:.88rem; padding:12px 18px; border-radius:14px; box-shadow:0 12px 34px rgba(0,0,0,.35); opacity:0; pointer-events:none; transition:opacity .3s, transform .35s var(--spring); max-width:88vw; text-align:center;}
#uniToast.show{opacity:1; transform:translate(-50%,0);}
#uniConfetti{position:fixed; inset:0; z-index:9600; pointer-events:none;}

@media (prefers-reduced-motion: reduce){
    *{animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.001ms !important;}
    .pop{opacity:1; transform:none;}
}
@media (max-width:380px){
    .hero-avg{font-size:3rem;} .hero-top{gap:14px;} .hero-ring{width:112px; height:112px;}
    .hero-stat .v{font-size:1.05rem;}
}
</style>
</head>
<body style="--uni-accent:<?php echo $UNI_SETTINGS['accent_color']; ?>; --uni-accent-2:<?php echo $UNI_SETTINGS['accent_color']; ?>;">

<header class="app-header">
    <div class="hdr-title">Voti</div>
    <button class="hdr-degree" id="hdrDegree" onclick="uniOpenProgram()" style="<?php echo $UNI_HAS_DATA ? '' : 'display:none'; ?>">
        <span class="dot"></span><span id="hdrDegreeName"><?php echo htmlspecialchars($UNI_SETTINGS['program_label'] ?: 'Imposta corso', ENT_QUOTES); ?></span>
    </button>
    <button class="hdr-icon-btn" onclick="uniOpenSettings()" aria-label="Impostazioni">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
</header>

<?php include 'sidebar.html'; ?>

<main class="page">

    <!-- ============ EMPTY STATE ============ -->
    <div id="uniEmpty" style="<?php echo $UNI_HAS_DATA ? 'display:none' : ''; ?>">
        <div class="empty">
            <div class="em-ic">🎓</div>
            <h2>La tua carriera, in chiaro</h2>
            <p>Aggiungi i tuoi esami e vedi subito media, voto di laurea previsto, obiettivi e andamento. Parti dal tuo corso per precompilare tutto.</p>
            <button class="pill-btn accent" onclick="uniOpenProgram()">Parti dal tuo corso</button>
            <div class="mini-row" style="justify-content:center; border:none; margin-top:6px;">
                <button class="pill-btn ghost" onclick="uniOpenExam()">＋ Aggiungi un esame a mano</button>
            </div>
        </div>
    </div>

    <!-- ============ APP ============ -->
    <div id="uniApp" style="<?php echo $UNI_HAS_DATA ? '' : 'display:none'; ?>">

        <!-- HERO -->
        <section class="hero pop" id="secHero" style="animation-delay:.02s">
            <div class="hero-top">
                <div class="hero-ring">
                    <svg viewBox="0 0 132 132">
                        <circle cx="66" cy="66" r="58" fill="none" stroke="var(--surface-3)" stroke-width="12"/>
                        <circle id="ringProg" cx="66" cy="66" r="58" fill="none" stroke="var(--uni-accent)" stroke-width="12" stroke-linecap="round" stroke-dasharray="364" stroke-dashoffset="364" style="transition:stroke-dashoffset 1s var(--spring)"/>
                    </svg>
                    <div class="ring-center">
                        <div class="ring-frac"><span id="ringDone">0</span><small>/<span id="ringTot">0</span></small></div>
                        <div class="ring-lbl">Esami</div>
                    </div>
                </div>
                <div class="hero-main">
                    <div class="kicker">Media ponderata</div>
                    <div class="hero-avg tabular"><span id="avgInt">–</span><span class="dec" id="avgDec"></span></div>
                    <span class="hero-delta" id="avgDelta" style="display:none"></span>
                </div>
            </div>

            <div class="hero-stats" id="heroStats">
                <div class="hero-stat"><div class="v tabular"><span id="statBase">–</span><small>/<span id="statBaseMax">110</span></small></div><div class="l">Base laurea</div></div>
                <div class="hero-stat"><div class="v tabular" id="statCfu">0</div><div class="l">CFU dati</div></div>
                <div class="hero-stat" id="statLodiBox"><div class="v tabular" id="statLodi">0</div><div class="l">Lodi</div></div>
            </div>

            <div class="hero-range">
                <div class="rlbl"><span>Voto di laurea stimato</span><span id="rangeThesisNote" style="text-transform:none; font-weight:600;"></span></div>
                <div class="rval" id="rangeText">–</div>
                <div class="thesis-vis" id="rangeVis" style="margin-top:8px"></div>
            </div>

            <div class="cfu-wrap">
                <div class="cfu-legend">
                    <span class="k done">Registrati <b id="cfuDone">0</b></span>
                    <span class="k plan">Pianificati <b id="cfuPlan">0</b></span>
                    <span class="k left">Totale <b id="cfuTot">0</b></span>
                </div>
                <div class="cfu-track"><i class="f-done" id="cfuBarDone" style="width:0"></i><i class="f-plan" id="cfuBarPlan" style="width:0"></i></div>
            </div>
        </section>

        <!-- OBIETTIVO -->
        <section class="uni-card pop" style="animation-delay:.06s">
            <div class="sec-head"><span class="sec-ic" style="background:var(--uni-accent)">🎯</span><h2>Obiettivo</h2></div>
            <div id="targetBody"></div>
        </section>

        <!-- ANDAMENTO -->
        <section class="uni-card pop" style="animation-delay:.1s">
            <div class="sec-head"><span class="sec-ic" style="background:var(--teal)">📈</span><h2>Andamento</h2></div>
            <div class="chart-legend">
                <span class="k"><span class="ln"></span>Media</span>
                <span class="k"><span class="ln obj"></span>Obiettivo</span>
                <span class="k"><span class="ln proj"></span>Proiezione</span>
                <span class="k"><span class="dt"></span>Voto esame</span>
            </div>
            <div class="chart-box" id="chartBox"></div>
            <div class="chart-tip" id="chartTip"></div>
            <label class="chart-toggle"><span class="sw-toggle" style="transform:scale(.82)"><input type="checkbox" id="projToggle" checked onchange="uniToggleProjection(this.checked)"><span class="track"></span><span class="thumb"></span></span> Mostra proiezione sui voti previsti</label>
        </section>

        <!-- ESAMI (+ SIMULATORE come terza scheda) -->
        <section class="uni-card pop" id="secExams" style="animation-delay:.14s">
            <div class="sec-head"><span class="sec-ic" style="background:var(--blue)">📚</span><h2>Esami</h2>
                <button class="sec-act" id="examSelBtn" onclick="uniToggleSelect()" style="background:var(--surface-2); color:var(--uni-accent); box-shadow:none">Seleziona</button>
                <button class="sec-act" id="simSaveBtn" style="display:none" onclick="uniSaveSimAsTarget()">Salva come obiettivo</button>
            </div>
            <div class="seg" id="examSeg">
                <div class="seg-thumb" id="segThumb"></div>
                <button type="button" data-tab="done" onclick="uniSetTab('done')">Sostenuti<span class="cnt" id="cntDone">0</span></button>
                <button type="button" class="on" data-tab="todo" onclick="uniSetTab('todo')">Da sostenere<span class="cnt" id="cntTodo">0</span></button>
                <button type="button" data-tab="sim" onclick="uniSetTab('sim')">Simulatore</button>
            </div>

            <!-- pannello simulatore -->
            <div id="simPane" style="display:none">
                <div class="sim-result">
                    <div class="now"><div class="lbl">Attuale</div><div class="num tabular" id="simNow">–</div></div>
                    <div class="arrow">→</div>
                    <div class="to"><div class="lbl">Simulata</div><div class="num tabular" id="simSimulated">–</div></div>
                    <div class="diff tabular" id="simDiff"></div>
                </div>
                <p class="sim-hint" id="simHint"></p>
                <ul class="sim-list" id="simList"></ul>
            </div>

            <!-- lista esami -->
            <ul class="exam-list" id="examList"></ul>
            <div class="sel-bar" id="examSelBar" style="display:none">
                <span class="n" id="selCount">0 selezionati</span>
                <button type="button" class="b-grp" id="selCreateBtn" onclick="uniOpenGroupSheet(null)">Crea gruppo</button>
                <button type="button" class="b-ungrp" id="selUngroupBtn" onclick="uniDissolveSelectedGroups()" style="display:none">Sciogli gruppo</button>
                <button type="button" class="b-del" onclick="uniDeleteSelected()">Elimina</button>
            </div>
            <button class="exam-add" id="examAddBtn" onclick="uniOpenExam()">＋ Aggiungi esame</button>
        </section>

        <!-- STATISTICHE -->
        <section class="uni-card pop" style="animation-delay:.22s">
            <div class="sec-head"><span class="sec-ic" style="background:var(--orange)">📊</span><h2>Statistiche</h2></div>
            <div id="statsBody"></div>
        </section>

        <!-- TESI & BONUS -->
        <section class="uni-card pop" id="secBonus" style="animation-delay:.26s">
            <div class="sec-head"><span class="sec-ic" style="background:var(--gold)">🎓</span><h2>Tesi &amp; Bonus</h2>
                <button class="sec-act" onclick="uniOpenBonus()">＋ Bonus</button>
            </div>
            <div id="thesisBody"></div>
            <div style="height:8px"></div>
            <ul class="exam-list" id="bonusList" style="list-style:none;padding:0;margin:0"></ul>
        </section>

    </div><!-- /uniApp -->
</main>

<!-- ==================== SHEETS ==================== -->
<div class="sheet-backdrop" id="sheetBackdrop" onclick="uniCloseSheets()"></div>

<!-- Exam editor -->
<div class="sheet" id="examSheet">
    <div class="grab"></div>
    <h3 id="exSheetTitle">Esame</h3>
    <p class="sub">Nome, crediti e voto</p>
    <div class="field"><label>Nome esame</label><input type="text" id="exName" placeholder="es. Analisi Matematica"></div>
    <div class="field"><div class="row2">
        <div><label>CFU</label><input type="number" id="exCfu" min="0" max="90" step="0.5" value="6"></div>
        <div><label>Anno</label><select id="exYear"><option value="">—</option><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option><option>6</option></select></div>
    </div></div>
    <div class="field"><label>Stato</label><div class="chip-row">
        <button type="button" class="chip-tog on" id="chipTodo" onclick="uniExamSetStatus('todo')">Da sostenere</button>
        <button type="button" class="chip-tog" id="chipDone" onclick="uniExamSetStatus('done')">Sostenuto</button>
    </div></div>
    <div class="field"><label>Tipologia</label><div class="chip-row">
        <button type="button" class="chip-tog on" id="chipGraded" onclick="uniExamSetPF(false)">Con voto</button>
        <button type="button" class="chip-tog" id="chipIdo" onclick="uniExamSetPF(true)">Idoneità</button>
    </div></div>
    <div class="field" id="exGradeBlock">
        <label id="exGradeHint">Voto</label>
        <div class="grade-picker">
            <button type="button" onclick="uniExamGrade(-1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">−</button>
            <div class="gbig tabular" id="exGradeBig">26</div>
            <button type="button" onclick="uniExamGrade(1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">＋</button>
        </div>
        <div class="chip-row" style="justify-content:center;margin-top:8px">
            <button type="button" class="chip-tog gold" id="exLodeChip" style="display:none" onclick="uniExamToggleLode()">🌟 30 e Lode</button>
            <button type="button" class="chip-tog" id="exClearGradeChip" style="display:none" onclick="uniExamClearGrade()">✕ Pulisci voto previsto</button>
        </div>
    </div>
    <div class="field" id="exDateBlock" style="display:none"><label>Data</label><input type="date" id="exDate"></div>
    <div class="field"><label>Avanzate (facoltative)</label><div class="row2">
        <div><input type="text" id="exSsd" placeholder="SSD (es. MAT/05)"></div>
        <div><input type="number" id="exNat" min="18" max="30" step="0.01" placeholder="Media classe"></div>
    </div></div>
    <div class="field" id="exGroupWrap" style="display:none">
        <label>Gruppo</label>
        <div class="mini-row" style="border:none; padding:0">
            <span id="exGroupName" style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:700; font-size:.88rem"></span>
            <button type="button" class="pill-btn ghost" style="flex-shrink:0; padding:9px 12px" onclick="uniEditExamGroup()">Modifica</button>
            <button type="button" class="pill-btn ghost" style="flex-shrink:0; padding:9px 12px" onclick="uniDissolveGroup()">Sciogli</button>
        </div>
    </div>
    <div class="sheet-actions">
        <button type="button" class="btn-danger" id="exDeleteBtn" onclick="uniDeleteExam()" style="display:none">🗑</button>
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniSaveExam()">Salva</button>
    </div>
</div>

<!-- Gruppo di esami -->
<div class="sheet" id="groupSheet">
    <div class="grab"></div>
    <h3 id="grpSheetTitle">Crea gruppo</h3>
    <p class="sub">Compatta più esami insieme</p>
    <div class="field"><label>Nome gruppo</label><input type="text" id="grpName" placeholder="es. Esame a scelta"></div>
    <div class="field"><label>Tipologia gruppo</label><div class="chip-row">
        <button type="button" class="chip-tog on" id="grpTypeChoice" onclick="uniGroupSetType('choice')">1 · Esami a scelta</button>
        <button type="button" class="chip-tog" id="grpTypeSplit" onclick="uniGroupSetType('split')">2 · Esame suddiviso</button>
    </div></div>
    <p class="grp-type-hint" id="grpTypeHint"></p>
    <div class="field" id="grpQuotaWrap">
        <label>CFU totali gruppo</label>
        <div class="chip-row" id="grpQuotaRow"></div>
    </div>
    <div class="field">
        <label>Esami nel gruppo (<span id="grpPickCount">0</span> selezionati)</label>
        <ul class="grp-pick-list" id="grpPickList"></ul>
    </div>
    <div class="sheet-actions">
        <button type="button" class="btn-danger" id="grpDeleteBtn" onclick="uniDissolveGroupFromSheet()" style="display:none">🗑</button>
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniSaveGroup()">Salva</button>
    </div>
</div>

<!-- Quick grade -->
<div class="sheet" id="gradeSheet">
    <div class="grab"></div>
    <h3>Registra voto</h3>
    <p class="sub" id="gradeSheetName">Esame</p>
    <div class="grade-picker">
        <button type="button" onclick="uniQuickGrade(-1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">−</button>
        <div class="gbig tabular" id="qgBig">26</div>
        <button type="button" onclick="uniQuickGrade(1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">＋</button>
    </div>
    <div class="chip-row" style="justify-content:center;margin-top:10px">
        <button type="button" class="chip-tog gold" id="qgLode" style="display:none" onclick="uniQuickLode()">🌟 30 e Lode</button>
        <button type="button" class="chip-tog" id="qgIdo" onclick="uniQuickIdo()">Idoneità</button>
    </div>
    <div class="sheet-actions" style="margin-top:16px">
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniQuickSave()">Registra ✓</button>
    </div>
</div>

<!-- Target -->
<div class="sheet" id="targetSheet">
    <div class="grab"></div>
    <h3>Il tuo obiettivo</h3>
    <p class="sub">Quale media vuoi raggiungere?</p>
    <div class="grade-picker">
        <button type="button" onclick="uniTargetStep(-1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">−</button>
        <div class="gbig tabular" id="tgtBig">28.00</div>
        <button type="button" onclick="uniTargetStep(1)" style="width:48px;height:48px;border-radius:50%;border:none;background:var(--surface-3);color:var(--uni-accent);font-size:1.7rem;font-weight:700;cursor:pointer;line-height:1">＋</button>
    </div>
    <p class="sub" id="tgtHint" style="margin-top:12px"></p>

    <div id="tgtVis" style="opacity:0.65; margin-bottom:20px;"></div>
    
    <div class="sheet-actions">
        <button type="button" class="btn-danger" onclick="uniClearTarget()" style="width:auto;padding:15px 16px;font-size:.9rem">Rimuovi</button>
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniSaveTarget()">Salva</button>
    </div>
</div>

<!-- Bonus -->
<div class="sheet" id="bonusSheet">
    <div class="grab"></div>
    <h3>Bonus punti</h3>
    <p class="sub">Punti extra sul voto di laurea</p>
    <div class="field"><label>Nome</label><input type="text" id="bnName" placeholder="es. Laurea in corso"></div>
    <div class="field"><label>Tipo</label><select id="bnType" onchange="uniBonusTypeChange()">
        <option value="on_time">Laurea in corso</option>
        <option value="honors_each">Punti per ogni lode</option>
        <option value="honors_threshold">Soglia di lodi</option>
        <option value="fixed">Bonus fisso</option>
        <option value="custom">Personalizzato</option>
    </select></div>
    <div class="field"><label id="bnPointsLabel">Punti</label><input type="number" id="bnPoints" min="-20" max="20" step="0.5" value="1"></div>
    <div class="field" id="bnThrWrap" style="display:none"><label>Lodi necessarie</label><input type="number" id="bnThreshold" min="0" max="100" step="1" value="3"></div>
    <div class="field" id="bnCapWrap" style="display:none"><label>Massimo punti (0 = nessun limite)</label><input type="number" id="bnCap" min="0" max="40" step="0.5" placeholder="nessun limite"></div>
    <p class="sub" id="bnPreview" style="text-align:left;background:var(--surface-2);padding:12px;border-radius:12px"></p>
    <div class="sheet-actions">
        <button type="button" class="btn-danger" id="bnDeleteBtn" onclick="uniDeleteBonus()" style="display:none">🗑</button>
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniSaveBonus()">Salva</button>
    </div>
</div>

<!-- Settings -->
<div class="sheet" id="settingsSheet">
    <div class="grab"></div>
    <h3>Impostazioni</h3>
    <p class="sub">Corso, colori e regole di calcolo</p>
    <div class="field"><label>Corso di laurea</label>
        <div class="prog-opt" onclick="uniOpenProgram()">
            <div class="pi" id="setProgIcon" style="background:var(--uni-grad)">🎓</div>
            <div class="pt"><div class="pn" id="setProgName">Nessun corso</div><div class="pd" id="setProgDesc">Tocca per scegliere</div></div>
            <div class="chev">›</div>
        </div>
    </div>
    <div class="field"><label>Nome corso (personalizzato)</label><input type="text" id="setLabel" placeholder="es. Ingegneria Informatica"></div>
    <div class="field"><label>Colore (facoltà)</label><div class="color-row" id="setColors"></div></div>
    <div class="field"><div class="row2">
        <div><label>CFU totali</label><input type="number" id="setTotalCfu" min="1" max="600" step="1" value="180"></div>
        <div><label>Base (es. 110)</label><input type="number" id="setBase" min="30" max="200" step="1" value="110"></div>
    </div></div>
    <div class="field"><label>Valore della lode nella media</label><div class="chip-row" id="setLodeVal">
        <button type="button" class="chip-tog" data-val="30" onclick="uniSetLodeVal(30)">30</button>
        <button type="button" class="chip-tog" data-val="30.5" onclick="uniSetLodeVal(30.5)">30,5</button>
        <button type="button" class="chip-tog" data-val="31" onclick="uniSetLodeVal(31)">31</button>
        <button type="button" class="chip-tog" data-val="32" onclick="uniSetLodeVal(32)">32</button>
    </div></div>
    <div class="field"><div class="row2">
        <div><label>Tesi min (+)</label><input type="number" id="setThMin" min="0" max="30" step="0.5" value="0"></div>
        <div><label>Tesi max (+)</label><input type="number" id="setThMax" min="0" max="30" step="0.5" value="0"></div>
    </div></div>
    <div class="mini-row"><span style="flex:1;font-size:.9rem;font-weight:600">Arrotonda la base per eccesso</span>
        <label class="sw-toggle"><input type="checkbox" id="setRound"><span class="track"></span><span class="thumb"></span></label>
    </div>
    <div class="sheet-actions" style="margin-top:14px">
        <button type="button" class="btn-secondary" onclick="uniCloseSheets()">Annulla</button>
        <button type="button" class="btn-primary" onclick="uniSaveSettings()">Salva</button>
    </div>
</div>

<!-- Program picker -->
<div class="sheet" id="programSheet">
    <div class="grab"></div>
    <h3>Scegli il tuo corso</h3>
    <p class="sub">Precompiliamo esami, CFU e colori</p>
    <div class="field">
        <input type="text" id="progSearch" placeholder="Cerca corso o facoltà…" autocomplete="off" oninput="uniFilterPrograms()">
    </div>
    <ul class="prog-list" id="progList"></ul>
</div>

<div id="uniToast"></div>
<canvas id="uniConfetti"></canvas>

<script>
/* ============================================================
   VOTI — logica client. Funzioni globali (gli onclick le usano).
   ============================================================ */
var STATE = {
  settings: <?php echo json_encode($UNI_SETTINGS, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  exams:    <?php echo json_encode($UNI_EXAMS,    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  bonuses:  <?php echo json_encode($UNI_BONUSES,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  groups:   <?php echo json_encode($UNI_GROUPS,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  catalog: null, tab: 'todo', showProjection: true, _reached: false,
  selecting: false, selected: {}, selectedGroups: {}
};

/* ---- Utility ---- */
function $(id){ return document.getElementById(id); }
function setTxt(id,v){ var el=$(id); if(el) el.textContent=v; }
function setHTML(id,v){ var el=$(id); if(el) el.innerHTML=v; }
function show(id,on){ var el=$(id); if(el) el.style.display = on?'':'none'; }
function toBool(v){ return v===true||v===1||v==='1'||v==='true'; }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s){ return esc(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function fmt2(x){ return (Math.round(x*100)/100).toFixed(2); }
function round2(x){ return Math.round(x*100)/100; }
function trimNum(x){ x=Math.round(x*10)/10; return x%1===0?String(x):x.toFixed(1); }
function clampG(v){ v=Math.round(v); if(v<18)v=18; if(v>30)v=30; return v; }
function shortName(s,n){ n=n||16; s=String(s||''); return s.length>n?s.slice(0,n-1)+'…':s; }
function todayISO(){ var d=new Date(),m=d.getMonth()+1,g=d.getDate(); return d.getFullYear()+'-'+(m<10?'0':'')+m+'-'+(g<10?'0':'')+g; }
function fmtDate(iso){ if(!iso) return ''; var p=String(iso).split('-'); if(p.length<3) return iso;
  var m=['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic']; return parseInt(p[2],10)+' '+(m[parseInt(p[1],10)-1]||'')+' '+p[0]; }
function prefersReduced(){ return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
function buzz(ms){ try{ if(navigator.vibrate) navigator.vibrate(ms||10); }catch(e){} }
var _toastT=null;
function toast(msg){ var el=$('uniToast'); if(!el) return; el.textContent=msg; el.classList.add('show');
  if(_toastT) clearTimeout(_toastT); _toastT=setTimeout(function(){ el.classList.remove('show'); },2200); }
function findExam(id){ for(var i=0;i<STATE.exams.length;i++){ if(String(STATE.exams[i].id)===String(id)) return STATE.exams[i]; } return null; }
function replaceExam(e){ for(var i=0;i<STATE.exams.length;i++){ if(String(STATE.exams[i].id)===String(e.id)){ STATE.exams[i]=e; return; } } STATE.exams.push(e); }
function findGroup(id){ if(id==null) return null; for(var i=0;i<STATE.groups.length;i++){ if(String(STATE.groups[i].id)===String(id)) return STATE.groups[i]; } return null; }
function groupExams(gid){ return STATE.exams.filter(function(e){ return e.group_id!=null && String(e.group_id)===String(gid); }); }
function isSplitGroup(g){ return !!g && g.group_type==='split'; }
function isChoiceGroup(g){ return !!g && g.group_type!=='split'; }
function groupTotalCfu(gid){ var m=groupExams(gid),s=0; for(var i=0;i<m.length;i++) s+=parseFloat(m[i].cfu)||0; return s; }
function groupDoneCfu(gid){ var m=groupExams(gid),s=0; for(var i=0;i<m.length;i++){ if(m[i].status==='done') s+=parseFloat(m[i].cfu)||0; } return s; }
/* somma dei CFU delle parti "da sostenere" del gruppo che hanno già un voto previsto impostato
   (escludendo opzionalmente un esame, utile per verificare se AGGIUNGERNE un altro sfora la quota) */
function groupPredictedCfu(gid, excludeId){
  var m=groupExams(gid), s=0;
  for(var i=0;i<m.length;i++){ var x=m[i]; if(excludeId!=null && String(x.id)===String(excludeId)) continue;
    if(x.status==='todo' && x.predicted_grade!=null) s+=parseFloat(x.cfu)||0; }
  return s;
}
function groupQuotaProgress(gid){ var g=findGroup(gid); if(!g) return null;
  return { quota:parseFloat(g.cfu)||0, done:groupDoneCfu(gid), pred:groupPredictedCfu(gid,null) }; }
function groupIsComplete(gid){ var m=groupExams(gid); if(!m.length) return false; for(var i=0;i<m.length;i++){ if(m[i].status!=='done') return false; } return true; }
function groupAllPredicted(gid){ var m=groupExams(gid); if(!m.length) return false; for(var i=0;i<m.length;i++){ if(toBool(m[i].is_pass_fail)) continue; if(m[i].status!=='done' && m[i].predicted_grade==null) return false; } return true; }
/* dato un elenco di CFU, trova il conteggio massimo di esami la cui somma raggiunge esattamente
   "target" (se non è raggiungibile in modo esatto, usa la somma raggiungibile più vicina,
   preferendo quella subito sopra il target). Usato per stimare quanti esami "contano davvero"
   in un gruppo a scelta, invece del numero totale di alternative disponibili. */
function maxCountForExactSum(cfus,target){
  var scale=10, best={0:0};
  for(var i=0;i<cfus.length;i++){ var v=Math.round((parseFloat(cfus[i])||0)*scale);
    var keys=Object.keys(best); for(var k=0;k<keys.length;k++){ var s=parseInt(keys[k],10), ns=s+v, nc=best[s]+1;
      if(!(ns in best) || best[ns]<nc) best[ns]=nc; } }
  var tgt=Math.round((parseFloat(target)||0)*scale);
  if(tgt in best) return best[tgt];
  var allSums=Object.keys(best).map(Number), bestSum=null, bestDist=Infinity, above=false;
  for(var j=0;j<allSums.length;j++){ var s2=allSums[j], d=Math.abs(s2-tgt), isAbove=s2>=tgt;
    if(bestSum===null || d<bestDist || (d===bestDist && isAbove && !above)){ bestSum=s2; bestDist=d; above=isAbove; } }
  return bestSum!=null ? best[bestSum] : 0;
}
/* numero totale "reale" di esami per l'anello di progresso: esami singoli + ogni parte dei gruppi
   suddivisi + per ogni gruppo a scelta, il numero di esami della combinazione (già decisa dallo
   studente, se sostenuta/simulata per una somma esatta di CFU pari alla quota; altrimenti quella
   teorica con più esami possibile che raggiunge comunque la quota). */
function totalExamsCount(){
  var total=0, seen={};
  for(var i=0;i<STATE.exams.length;i++){ var e=STATE.exams[i];
    var g=e.group_id!=null?findGroup(e.group_id):null;
    if(!g){ total++; continue; }
    if(isSplitGroup(g)){ total++; continue; }
    var k=String(g.id); if(seen[k]) continue; seen[k]=true;
    var members=groupExams(g.id), quota=parseFloat(g.cfu)||0;
    var decided=members.filter(function(x){ return x.status==='done' || (x.status==='todo' && x.predicted_grade!=null); });
    var decidedSum=0; for(var d=0;d<decided.length;d++) decidedSum+=parseFloat(decided[d].cfu)||0;
    if(decided.length>0 && Math.abs(decidedSum-quota)<0.01) total+=decided.length;
    else total+=maxCountForExactSum(members.map(function(x){ return parseFloat(x.cfu)||0; }), quota);
  }
  return total;
}
/* media pesata (arrotondata) dei voti/previsioni delle parti di un gruppo "esame suddiviso" */
function proposedOverallGrade(members, useSim){
  var sum=0,cfu=0;
  for(var i=0;i<members.length;i++){ var e=members[i], c=parseFloat(e.cfu)||0, g=null;
    if(e.status==='done' && e.grade!=null) g=toBool(e.has_honors)?honorsValue():parseFloat(e.grade);
    else if(useSim && e.predicted_grade!=null) g=toBool(e.predicted_honors)?honorsValue():parseFloat(e.predicted_grade);
    if(g==null) continue; sum+=g*c; cfu+=c; }
  if(cfu<=0) return null; return clampG(Math.round(sum/cfu));
}
/* tutte le somme raggiungibili con sottoinsiemi non vuoti dei CFU indicati (per la quota del gruppo "a scelta") */
function possibleCfuSums(cfus){
  var sums={0:true}, list=[0];
  for(var i=0;i<cfus.length && i<18;i++){ var c=Math.round((parseFloat(cfus[i])||0)*10)/10, next=list.slice();
    for(var j=0;j<list.length;j++){ var s=Math.round((list[j]+c)*10)/10; if(!sums[s]){ sums[s]=true; next.push(s); } }
    list=next; }
  var out=[]; for(var k in sums){ if(sums.hasOwnProperty(k) && parseFloat(k)>0) out.push(parseFloat(k)); }
  out.sort(function(a,b){ return a-b; }); return out;
}
function findBonus(id){ for(var i=0;i<STATE.bonuses.length;i++){ if(String(STATE.bonuses[i].id)===String(id)) return STATE.bonuses[i]; } return null; }

function uniPost(action,data){
  var body=new URLSearchParams(); body.set('ajax',action); data=data||{};
  for(var k in data){ if(!data.hasOwnProperty(k)) continue; var v=data[k];
    if(v===null||v===undefined) body.set(k,'null');
    else if(v===true) body.set(k,'1');
    else if(v===false) body.set(k,'0');
    else if(typeof v==='object') body.set(k,JSON.stringify(v));
    else body.set(k,String(v)); }
  return fetch('voti.php?ajax='+encodeURIComponent(action),{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()})
    .then(function(r){ return r.json(); });
}

/* ---- Calcolo ---- */
function S(){ return STATE.settings||{}; }
function honorsValue(){ var v=parseFloat(S().honors_value); return isNaN(v)?30:v; }
function gradeBase(){ var v=parseFloat(S().grade_base); return isNaN(v)?110:v; }
function isGraded(e){ return e && e.status==='done' && !toBool(e.is_pass_fail) && e.grade!=null && e.grade!==''; }
function effGrade(e){ return toBool(e.has_honors)?honorsValue():parseFloat(e.grade); }
function capOf(b){ var c=b.cap_points!=null?parseFloat(b.cap_points):0; return (c&&c>0)?c:null; }
function weighted(list){ var sum=0,cfu=0,n=0; for(var i=0;i<list.length;i++){ var e=list[i];
    if(isGraded(e)){ var c=parseFloat(e.cfu)||0; sum+=effGrade(e)*c; cfu+=c; n++; } }
  return { avg:cfu>0?sum/cfu:0, sum:sum, cfu:cfu, n:n }; }
/* CFU sostenuti/pianificati. I gruppi "esame suddiviso" contano come normali esami singoli
   (ogni parte pesa per i propri CFU). I gruppi "esami a scelta" contano fino alla quota scelta:
   i CFU già sostenuti nel gruppo vanno in "sostenuti" (fino alla quota), il resto della quota
   ancora mancante va in "da sostenere" — indipendentemente dai CFU dei singoli esami del gruppo. */
function cfuStats(){
  var done=0, plan=0, choiceDone={}, seenChoice={};
  for(var i=0;i<STATE.exams.length;i++){ var e=STATE.exams[i];
    if(!toBool(e.counts_for_graduation)) continue; var c=parseFloat(e.cfu)||0;
    var g=e.group_id!=null?findGroup(e.group_id):null;
    if(isChoiceGroup(g)){ var k=String(g.id); choiceDone[k]=(choiceDone[k]||0)+(e.status==='done'?c:0); continue; }
    if(e.status==='done') done+=c; else plan+=c; }
  for(var j=0;j<STATE.exams.length;j++){ var e2=STATE.exams[j]; if(e2.group_id==null) continue;
    var g2=findGroup(e2.group_id); if(!isChoiceGroup(g2)) continue;
    var k2=String(g2.id); if(seenChoice[k2]) continue; seenChoice[k2]=true;
    var quota=parseFloat(g2.cfu)||0, doneSum=choiceDone[k2]||0;
    if(doneSum>0){ done+=Math.min(doneSum,quota); if(doneSum<quota) plan+=(quota-doneSum); }
    else plan+=quota; }
  var total=parseFloat(S().total_cfu)||0; if(total<done+plan) total=done+plan;
  return {done:done,plan:plan,total:total};
}

/* esami ancora da sostenere che pesano sui calcoli (media richiesta, simulazione):
   - gruppo "esame suddiviso": ogni parte conta singolarmente con i propri CFU (nessuna esclusività);
   - gruppo "esami a scelta" non ancora completato: ogni parte con una previsione impostata conta
     singolarmente (propri CFU/voto), fino ai CFU RESIDUI della quota del gruppo; l'eventuale quota
     ancora senza previsione resta come CFU "da definire" (senza voto) finché non viene assegnato. */
function todoForCalc(){
  var doneSum={}, seen={}, out=[];
  for(var i=0;i<STATE.exams.length;i++){ var e=STATE.exams[i];
    if(e.group_id!=null && e.status==='done'){ var kk=String(e.group_id); doneSum[kk]=(doneSum[kk]||0)+(parseFloat(e.cfu)||0); } }
  var todo=STATE.exams.filter(function(x){ return x.status==='todo' && toBool(x.counts_for_graduation); });
  todo.sort(function(a,b){ return (a.study_year||99)-(b.study_year||99) || (a.sort_order||0)-(b.sort_order||0); });
  for(var j=0;j<todo.length;j++){ var e2=todo[j];
    var g=e2.group_id!=null?findGroup(e2.group_id):null;
    if(isChoiceGroup(g)){ var k=String(g.id);
      if(seen[k]) continue; seen[k]=true;
      var quota=parseFloat(g.cfu)||0, ds=doneSum[k]||0, rem=Math.max(0,quota-ds);
      if(rem<=0.0005) continue; // quota già raggiunta (caso limite, il gruppo dovrebbe già essersi sciolto)
      var members=groupExams(g.id).filter(function(x){ return x.status==='todo'; });
      var predictedCfu=0;
      for(var mi=0;mi<members.length;mi++){ var mm=members[mi];
        if(mm.predicted_grade!=null){ out.push(mm); predictedCfu+=parseFloat(mm.cfu)||0; } }
      var leftover=rem-predictedCfu;
      if(leftover>0.0005){
        var template=members[0]||e2, clone={};
        for(var kk2 in template){ if(template.hasOwnProperty(kk2)) clone[kk2]=template[kk2]; }
        clone.cfu=leftover; clone.predicted_grade=null; clone.predicted_honors=false;
        out.push(clone);
      }
      continue; }
    out.push(e2); }
  return out;
}

/* posizione sulla scala 66 → base → base+lode (proporzionale davvero) */
function gradePct(v){ var gb=gradeBase(), lo=66;
  if(v<=lo) return 0; if(v>=gb+1) return 100;
  if(v<=gb) return (v-lo)/(gb-lo)*92;
  return 92+(v-gb)*8; }
function base110(avg){ var raw=avg/30*gradeBase(); return toBool(S().round_half_up)?Math.round(raw):Math.floor(raw); }
function dispFinal(v){ var gb=gradeBase(); if(v>gb) return gb+'L'; return String(Math.round(v)); }
function lodiCount(useSim){ var n=0; for(var i=0;i<STATE.exams.length;i++){ var e=STATE.exams[i];
    if(toBool(e.is_pass_fail)) continue;
    if(isGraded(e)&&toBool(e.has_honors)) n++;
    else if(useSim && e.status==='todo' && e.predicted_grade!=null && toBool(e.predicted_honors)) n++; }
  return n; }
function bonusTotal(useSim){ var total=0, lodi=lodiCount(useSim);
  for(var i=0;i<STATE.bonuses.length;i++){ var b=STATE.bonuses[i]; if(!toBool(b.is_enabled)) continue;
    total+=bonusApplied(b,lodi); } return total; }
function bonusApplied(b,lodi){ if(!toBool(b.is_enabled)) return 0; var pts=parseFloat(b.points)||0, cap=capOf(b);
  if(b.bonus_type==='honors_each'){ var v=pts*lodi; if(cap!=null) v=Math.min(v,cap); return v; }
  if(b.bonus_type==='honors_threshold'){ var thr=b.threshold_value!=null?parseFloat(b.threshold_value):0; return lodi>=thr?pts:0; }
  return pts; }
function finalRange(avg,useSim){ var base=avg>0?base110(avg):0, bonus=bonusTotal(useSim);
  var tmin=parseFloat(S().thesis_min_points)||0, tmax=parseFloat(S().thesis_max_points)||0;
  return { base:base, bonus:bonus, min:base+bonus+tmin, max:base+bonus+tmax }; }
function requiredFuture(target){ var w=weighted(STATE.exams), Cdone=w.cfu, Ssum=w.sum, R=0;
  var td=todoForCalc();
  for(var i=0;i<td.length;i++){ if(!toBool(td[i].is_pass_fail)) R+=parseFloat(td[i].cfu)||0; }
  if(R<=0) return null; var req=(target*(Cdone+R)-Ssum)/R; return { req:req, remainingCfu:R, feasible:req<=30.0001 }; }
function simulated(){ var sum=0,cfu=0,used=0;
  for(var i=0;i<STATE.exams.length;i++){ var e=STATE.exams[i];
    if(toBool(e.is_pass_fail)) continue;
    if(isGraded(e)){ var c=parseFloat(e.cfu)||0; sum+=effGrade(e)*c; cfu+=c; } }
  var td=todoForCalc();
  for(var j=0;j<td.length;j++){ var e2=td[j];
    if(toBool(e2.is_pass_fail) || e2.predicted_grade==null) continue;
    var c2=parseFloat(e2.cfu)||0, g=toBool(e2.predicted_honors)?honorsValue():parseFloat(e2.predicted_grade);
    sum+=g*c2; cfu+=c2; used++; }
  return { avg:cfu>0?sum/cfu:0, cfu:cfu, used:used }; }
function trendData(){
  var graded=STATE.exams.filter(isGraded).slice();
  graded.sort(function(a,b){ var da=a.exam_date||'',db=b.exam_date||'';
    if(da&&db){ if(da<db)return -1; if(da>db)return 1; } else if(da&&!db) return -1; else if(!da&&db) return 1;
    return (a.sort_order||0)-(b.sort_order||0); });
  var real=[],sum=0,cfu=0;
  for(var i=0;i<graded.length;i++){ var e=graded[i],c=parseFloat(e.cfu)||0; sum+=effGrade(e)*c; cfu+=c;
    real.push({ i:i+1, avg:cfu>0?sum/cfu:0, grade:effGrade(e), name:e.name, date:e.exam_date, lode:toBool(e.has_honors) }); }
  var proj=[];
  if(STATE.showProjection && real.length){
    var todo=todoForCalc().filter(function(e){ return !toBool(e.is_pass_fail) && e.predicted_grade!=null; });
    var psum=sum,pcfu=cfu,idx=real.length;
    proj.push({ i:real[real.length-1].i, avg:real[real.length-1].avg });
    for(var j=0;j<todo.length;j++){ var e2=todo[j],c2=parseFloat(e2.cfu)||0;
      var g2=toBool(e2.predicted_honors)?honorsValue():parseFloat(e2.predicted_grade); psum+=g2*c2; pcfu+=c2; idx++;
      proj.push({ i:idx, avg:pcfu>0?psum/pcfu:0, grade:g2, name:e2.name, predicted:true }); } }
  return { real:real, proj:proj };
}

/* ---- Animazioni ---- */
var _avgRAF=null;
function animateAvg(to){ var iE=$('avgInt'),dE=$('avgDec'); if(!iE) return;
  if(to<=0){ iE.textContent='–'; if(dE) dE.textContent=''; return; }
  var from=parseFloat((iE.textContent||'0')+(dE?dE.textContent:''))||0;
  if(prefersReduced()){ setAvgText(iE,dE,to); return; }
  var start=performance.now(),dur=650; if(_avgRAF) cancelAnimationFrame(_avgRAF);
  function step(now){ var t=Math.min(1,(now-start)/dur),e=1-Math.pow(1-t,3);
    setAvgText(iE,dE,from+(to-from)*e); if(t<1) _avgRAF=requestAnimationFrame(step); }
  _avgRAF=requestAnimationFrame(step); }
function setAvgText(iE,dE,val){ var v=Math.round(val*100)/100,ip=Math.floor(v),dp=Math.round((v-ip)*100);
  iE.textContent=String(ip); if(dE) dE.textContent='.'+(dp<10?'0':'')+dp; }

/* ---- Render ---- */
function renderAll(){
  var hasData=STATE.exams.length>0;
  show('uniEmpty',!hasData); show('uniApp',hasData);
  refreshHeaderDegree(); if(!hasData) return;
  try{ renderHero(); }catch(e){}
  try{ renderObjective(); }catch(e){}
  try{ renderChart(); }catch(e){}
  try{ renderSimulator(); }catch(e){}
  try{ renderExams(); }catch(e){}
  try{ renderStats(); }catch(e){}
  try{ renderThesisBonus(); }catch(e){}
}
function renderHero(){
  var w=weighted(STATE.exams), avg=w.avg, cs=cfuStats();
  animateAvg(avg);
  var exDone=STATE.exams.filter(function(e){ return e.status==='done'; }).length, exTot=totalExamsCount();
  var frac=exTot>0?exDone/exTot:0;
  var ring=$('ringProg');
  if(ring){ var R=parseFloat(ring.getAttribute('r'))||58, circ=2*Math.PI*R;
    ring.setAttribute('stroke-dasharray', circ.toFixed(2)); ring.style.strokeDashoffset=(circ*(1-frac)).toFixed(2); }
  setTxt('ringDone',exDone); setTxt('ringTot',exTot);
  setTxt('statBase',avg>0?base110(avg):'–'); setTxt('statBaseMax',gradeBase());
  setTxt('statCfu',trimNum(cs.done));
  var lodi=lodiCount(false); setTxt('statLodi',lodi);
  show('statLodiBox', lodi>0);
  var hs=$('heroStats'); if(hs) hs.classList.toggle('cols-2', lodi<=0);
  var delta=$('avgDelta'), tgt=S().target_average!=null?parseFloat(S().target_average):null;
  if(delta){ if(tgt!=null && avg>0){ var d=avg-tgt; delta.style.display='';
      if(Math.abs(d)<0.005){ delta.className='hero-delta flat'; delta.textContent='● obiettivo raggiunto'; }
      else { delta.className='hero-delta '+(d>0?'up':'down'); delta.textContent=(d>0?'▲ +':'▼ ')+fmt2(Math.abs(d))+' vs obiettivo'; }
    } else delta.style.display='none'; }
  var fr=finalRange(avg,false);
  var gb=gradeBase();
  if(avg>0){
    var rangeStr = fr.min===fr.max ? dispFinal(fr.min) : (dispFinal(fr.min)+'–'+dispFinal(fr.max));
    setHTML('rangeText', rangeStr+'<span class="denom">/'+gb+'</span>');
    setHTML('rangeVis', thesisVisHTML(fr.min, fr.max));
  } else { setTxt('rangeText','–'); setHTML('rangeVis',''); }
  setTxt('rangeThesisNote', (S().thesis_max_points>0?'tesi +'+trimNum(S().thesis_min_points||0)+' • +'+trimNum(S().thesis_max_points||0):''));
  setTxt('cfuDone',trimNum(cs.done)); setTxt('cfuPlan',trimNum(cs.plan)); setTxt('cfuTot',trimNum(cs.total));
  var bd=$('cfuBarDone'),bp=$('cfuBarPlan');
  if(bd&&bp){ bd.style.width=(cs.total>0?cs.done/cs.total*100:0)+'%'; bp.style.width=(cs.total>0?cs.plan/cs.total*100:0)+'%'; }
  if(tgt!=null && avg>0){ if(avg>=tgt && !STATE._reached){ STATE._reached=true;
      setTimeout(function(){ burst(); toast('🎯 Obiettivo raggiunto!'); },250); } else if(avg<tgt){ STATE._reached=false; } }
}
function renderObjective(){
  var body=$('targetBody'); if(!body) return;
  var tgt=S().target_average!=null?parseFloat(S().target_average):null, avg=weighted(STATE.exams).avg;
  if(tgt==null){ body.innerHTML='<div class="empty" style="padding:10px 10px 4px"><div class="em-ic" style="width:60px;height:60px;font-size:26px">🎯</div>'+
    '<p>Fissa un traguardo per la tua media e scopri cosa ti serve per raggiungerlo.</p>'+
    '<button class="pill-btn accent" onclick="uniOpenTarget()">Imposta obiettivo</button></div>'; return; }
  var d=avg-tgt, reached=avg>=tgt-0.0001, src=S().target_source==='simulation'?'da simulazione':'manuale';
  var h='<div style="display:flex;align-items:center;justify-content:space-between;gap:10px">'+
    '<div><div style="font-size:2.2rem;font-weight:800;letter-spacing:-.02em" class="tabular">'+fmt2(tgt)+'</div>'+
    '<div style="font-size:.78rem;color:var(--text-3)">obiettivo media · '+src+'</div></div>'+
    '<span class="hero-delta '+(reached?'up':'down')+'">'+(d>=0?'+':'')+fmt2(d)+'</span></div>';
  var pct=tgt>18?Math.max(0,Math.min(100,(avg-18)/(tgt-18)*100)):100;
  h+='<div style="height:9px;background:var(--surface-3);border-radius:5px;overflow:hidden;margin:12px 0 10px">'+
     '<div style="height:100%;width:'+pct+'%;background:var(--uni-grad);border-radius:5px;transition:width .6s var(--spring)"></div></div>';
  if(reached){ h+='<p style="margin:0;color:var(--text-2);font-size:.88rem">🎉 Sei già sopra il tuo obiettivo. Continua così per mantenerlo!</p>'; }
  else { var rf=requiredFuture(tgt);
    if(rf==null) h+='<p style="margin:0;color:var(--text-2);font-size:.88rem">Aggiungi altri esami con voto per calcolare cosa ti serve.</p>';
    else if(!rf.feasible) h+='<p style="margin:0;color:var(--text-2);font-size:.88rem">Per '+fmt2(tgt)+' servirebbe una media di '+fmt2(rf.req)+' nei restanti '+trimNum(rf.remainingCfu)+' CFU \u2014 oltre il 30. Prova ad abbassare un po\' l\'obiettivo.</p>';
    else h+='<p style="margin:0;color:var(--text-2);font-size:.88rem">Ti basta una media di <b style="color:var(--text)">'+fmt2(rf.req)+'</b> nei restanti <b style="color:var(--text)">'+trimNum(rf.remainingCfu)+' CFU</b> per centrarlo.</p>'; }

  var frTgt = finalRange(tgt, false);

  h += '<div style="margin-top:32px; opacity:0.60;">';
  h += '<div style="font-size:.65rem; font-weight:800; color:var(--text-2); text-transform:uppercase; margin-bottom:6px; letter-spacing:.5px;">Voto di laurea ambito</div>';
  h += '<div class="thesis-vis" style="margin-top:0">'+thesisVisHTML(frTgt.min, frTgt.max)+'</div>';
  h += '</div>';

  h+='<div class="mini-row" style="margin-top:6px;justify-content:flex-end"><button class="pill-btn ghost" onclick="uniOpenTarget()">Modifica</button></div>';
  body.innerHTML=h;
}
function renderChart(){
  var box=$('chartBox'); if(!box) return;
  var td=trendData(), real=td.real, proj=td.proj;
  if(!real.length){ box.innerHTML='<div class="chart-empty">Registra i primi voti per vedere l\'andamento della media.</div>'; return; }
  var W=340,H=196,padL=30,padR=10,padT=12,padB=24,innerW=W-padL-padR,innerH=H-padT-padB;
  var maxI=1; for(var i=0;i<real.length;i++) maxI=Math.max(maxI,real[i].i);
  for(var j=0;j<proj.length;j++) maxI=Math.max(maxI,proj[j].i);
  function X(i){ return padL+(maxI<=1?innerW/2:(i-1)/(maxI-1)*innerW); }
  function Y(v){ return padT+(1-(Math.max(18,Math.min(30,v))-18)/12)*innerH; }
  var s='<svg viewBox="0 0 '+W+' '+H+'" width="100%" preserveAspectRatio="none">';
  [18,24,30].forEach(function(g){ var gy=Y(g);
    s+='<line x1="'+padL+'" y1="'+gy.toFixed(1)+'" x2="'+(W-padR)+'" y2="'+gy.toFixed(1)+'" stroke="var(--hairline)"/>'+
       '<text x="'+(padL-6)+'" y="'+(gy+3).toFixed(1)+'" text-anchor="end" font-size="9" fill="var(--text-3)">'+g+'</text>'; });
  var tgt=S().target_average!=null?parseFloat(S().target_average):null;
  if(tgt!=null){ var ty=Y(tgt); s+='<line x1="'+padL+'" y1="'+ty.toFixed(1)+'" x2="'+(W-padR)+'" y2="'+ty.toFixed(1)+'" stroke="var(--text-3)" stroke-width="1.5" stroke-dasharray="5 4"/>'; }
  var pts=''; for(var i2=0;i2<real.length;i2++) pts+=(i2?' ':'')+X(real[i2].i).toFixed(1)+','+Y(real[i2].avg).toFixed(1);
  s+='<polyline points="'+pts+'" fill="none" stroke="var(--uni-accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
  if(proj.length>1){ var pp=''; for(var p2=0;p2<proj.length;p2++) pp+=(p2?' ':'')+X(proj[p2].i).toFixed(1)+','+Y(proj[p2].avg).toFixed(1);
    s+='<polyline points="'+pp+'" fill="none" stroke="var(--uni-accent)" stroke-width="2" stroke-dasharray="5 4" opacity=".55" stroke-linejoin="round"/>'; }
  for(var d=0;d<real.length;d++) s+=chartDot(X(real[d].i),Y(real[d].grade),real[d].lode?'var(--gold)':'var(--uni-accent)',false,real[d]);
  for(var dp=1;dp<proj.length;dp++){ if(proj[dp].grade!=null) s+=chartDot(X(proj[dp].i),Y(proj[dp].grade),'var(--uni-accent)',true,proj[dp]); }
  s+='</svg>'; box.innerHTML=s; bindChartTips(box);
}
function chartDot(cx,cy,color,hollow,p){
  var fx=cx/340,fy=cy/196;
  var lbl=(p.name?shortName(p.name,20):'')+(p.grade!=null?' \u00b7 '+(p.lode?'30L':Math.round(p.grade)):'')+(p.predicted?' (previsto)':'')+(p.date?' \u00b7 '+fmtDate(p.date):'');
  return '<circle cx="'+cx.toFixed(1)+'" cy="'+cy.toFixed(1)+'" r="3.5" fill="'+(hollow?'var(--surface)':color)+'" stroke="'+color+'" stroke-width="2"/>'+
    '<circle cx="'+cx.toFixed(1)+'" cy="'+cy.toFixed(1)+'" r="12" fill="transparent" class="chart-hit" data-fx="'+fx.toFixed(4)+'" data-fy="'+fy.toFixed(4)+'" data-lbl="'+escAttr(lbl.trim())+'"/>';
}
function bindChartTips(box){
  var tip=$('chartTip'); if(!tip) return;
  function showTip(el){ tip.textContent=el.getAttribute('data-lbl');
    tip.style.left=(parseFloat(el.getAttribute('data-fx'))*box.clientWidth)+'px';
    tip.style.top=(parseFloat(el.getAttribute('data-fy'))*box.clientHeight)+'px'; tip.classList.add('show'); }
  function hideTip(){ tip.classList.remove('show'); }
  var hits=box.querySelectorAll('.chart-hit');
  for(var i=0;i<hits.length;i++){ hits[i].addEventListener('pointerenter',function(e){ showTip(e.currentTarget); });
    hits[i].addEventListener('pointerdown',function(e){ showTip(e.currentTarget); }); hits[i].addEventListener('pointerleave',hideTip); }
  box.addEventListener('pointerleave',hideTip);
}
function uniToggleProjection(on){ STATE.showProjection=(on===undefined)?!STATE.showProjection:!!on; renderChart(); }

/* ---- Simulatore ---- */
/* raggruppa una lista di esami (già filtrata/ordinata) tenendo vicine le parti dello stesso gruppo,
   nell'ordine di prima comparsa: usato in tutte e 3 le sezioni (sostenuti/da sostenere/simulatore) */
function clusterByGroup(items){
  var order=[], blocks={};
  for(var i=0;i<items.length;i++){ var e=items[i];
    if(e.group_id!=null){ var k=String(e.group_id);
      if(!blocks[k]){ blocks[k]={ type:'group', gid:e.group_id, items:[] }; order.push(blocks[k]); }
      blocks[k].items.push(e);
    } else order.push({ type:'single', item:e }); }
  return order;
}
function renderSimulator(){
  var w=weighted(STATE.exams), sim=simulated();
  setTxt('simNow', w.avg>0?fmt2(w.avg):'–');
  setTxt('simSimulated', sim.cfu>0?fmt2(sim.avg):'–');
  var dE=$('simDiff');
  if(dE){ if(w.avg>0 && sim.cfu>0){ var d=sim.avg-w.avg; dE.textContent=(d>=0?'+':'')+fmt2(d);
      dE.style.color=Math.abs(d)<0.005?'var(--text-2)':(d>0?'var(--green)':'var(--red)'); } else { dE.textContent=''; } }
  show('simSaveBtn', STATE.tab==='sim' && sim.used>0);
  var list=$('simList'); if(!list) return;
  /* solo quelli che mancano davvero: appena un esame passa a "sostenuto" sparisce da qui */
  var todo=STATE.exams.filter(function(e){ return e.status==='todo' && (!toBool(e.is_pass_fail) || e.group_id != null); });
  todo.sort(function(a,b){ var ya=a.study_year||99, yb=b.study_year||99;
    if(ya!==yb) return ya-yb; return (a.sort_order||0)-(b.sort_order||0); });
  var hasChoice=false; for(var q=0;q<todo.length;q++){ if(isChoiceGroup(findGroup(todo[q].group_id))){ hasChoice=true; break; } }
  setTxt('simHint','Assegna un voto ipotetico agli esami che ti mancano: la media si aggiorna in tempo reale.'+
    (hasChoice?' Negli esami a scelta puoi simulare più voti insieme, fino a coprire i CFU della quota del gruppo.':''));
  if(!todo.length){ list.innerHTML='<div class="empty" style="padding:16px 10px"><p style="margin:0">Nessun esame da simulare: li hai sostenuti tutti. \ud83c\udf89</p></div>'; return; }
  var blocks=clusterByGroup(todo), h='';
  for(var i=0;i<blocks.length;i++){ var b=blocks[i]; h += b.type==='single' ? simRow(b.item) : simGroupBlock(b.gid,b.items); }
  list.innerHTML=h;
}
function simRow(e){
  var id=escAttr(e.id);
  
  // Se è un'idoneità, renderizza una riga statica senza stepper
  if(toBool(e.is_pass_fail)){
    return '<li class="sim-item" data-id="'+id+'">'+
      '<div class="nm">'+esc(shortName(e.name,22))+'<small style="display:block;font-size:.72rem;font-weight:600">'+trimNum(e.cfu)+' CFU</small></div>'+
      '<div style="margin-left:auto; font-size:.85rem; font-weight:700; color:var(--text-3); padding-right:8px; display:flex; align-items:center; height:36px;">Idoneità</div>'+
    '</li>';
  }
  
  // Altrimenti, renderizza lo stepper classico per simulare il voto
  var has=e.predicted_grade!=null;
  var val=has?(toBool(e.predicted_honors)?'30L':Math.round(e.predicted_grade)):'–';
  return '<li class="sim-item" data-id="'+id+'">'+
    '<div class="nm">'+esc(shortName(e.name,22))+'<small style="display:block;font-size:.72rem;font-weight:600">'+trimNum(e.cfu)+' CFU</small></div>'+
    '<div class="stepper"><button type="button" onclick="uniSimStep(\''+id+'\',-1)">\u2212</button>'+
    '<span class="val'+(toBool(e.predicted_honors)?' lode':'')+'">'+val+'</span>'+
    '<button type="button" onclick="uniSimStep(\''+id+'\',1)">+</button></div>'+
    '<button type="button" class="chip-tog'+(toBool(e.predicted_honors)?' gold on':'')+'" style="margin-left:6px;padding:9px 12px" onclick="uniSimLode(\''+id+'\')">L</button>'+
    (has?'<button type="button" style="margin-left:2px;background:none;border:none;color:var(--text-3);font-size:1.3rem;cursor:pointer;line-height:1" onclick="uniSimClear(\''+id+'\')">\u00d7</button>':'')+
  '</li>';
}
function simGroupBlock(gid,items){
  var g=findGroup(gid); if(!g){ var raw=''; for(var i0=0;i0<items.length;i0++) raw+=simRow(items[i0]); return raw; }
  var isSplit=isSplitGroup(g), gidA=escAttr(gid);
  var h='<li class="sim-group" data-id="'+gidA+'"><div class="sim-group-inner">'+
    '<div class="sim-group-head"><span class="gt-name" style="flex:1;font-weight:800;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" onclick="uniOpenGroupSheet(\''+gidA+'\')">'+esc(g.name)+'</span>'+
    '<span class="gt-badge'+(isSplit?' split':'')+'">'+(isSplit?'Suddiviso':'A scelta')+'</span></div>';
  if(!isSplit){
    var qp=groupQuotaProgress(gid);
    var lbl='Quota gruppo: '+trimNum(qp.done)+' sostenuti'+(qp.pred>0?(' + '+trimNum(qp.pred)+' previsti'):'')+' su '+trimNum(qp.quota)+' CFU.';
    if(qp.done+qp.pred >= qp.quota-0.001) lbl+=' Quota coperta.';
    h+='<div class="grp-note">'+lbl+'</div>'; }
  h+='<ul class="sim-list" style="margin:0;padding:0">';
  for(var i=0;i<items.length;i++) h+=simRow(items[i]);
  h+='</ul>';
  if(isSplit){
    var prop=groupAllPredicted(gid)?proposedOverallGrade(groupExams(gid),true):null;
    var hasOverride = g.overall_predicted_grade!=null;
    var cur=hasOverride?g.overall_predicted_grade:prop;
    var curHon=hasOverride?toBool(g.overall_predicted_honors):false;
    var val=cur!=null?(curHon?'30L':cur):'–';
    h+='<div class="grp-overall"><span class="go-lbl">Voto complessivo gruppo'+(prop!=null&&!hasOverride?' (proposto)':'')+'</span>'+
      '<div class="stepper"><button type="button" onclick="uniGroupPredStep(\''+gidA+'\',-1)">\u2212</button>'+
      '<span class="val'+(curHon?' lode':'')+'">'+val+'</span>'+
      '<button type="button" onclick="uniGroupPredStep(\''+gidA+'\',1)">+</button></div>'+
      '<button type="button" class="chip-tog'+(curHon?' gold on':'')+'" style="padding:9px 12px; flex-shrink:0;" onclick="uniGroupPredLode(\''+gidA+'\')">L</button>'+
      (hasOverride?'<button type="button" style="margin-left:2px;background:none;border:none;color:var(--text-3);font-size:1.3rem;cursor:pointer;line-height:1; flex-shrink:0; padding:0 4px;" onclick="uniGroupPredClear(\''+gidA+'\')">\u00d7</button>':'')+
    '</div>'; }
  h+='</div></li>';
  return h;
}
/* in un gruppo "a scelta" puoi simulare il voto di più parti insieme, ma la somma dei loro CFU
   non può superare i CFU ancora liberi della quota del gruppo (quota meno quanto già sostenuto).
   Negli "esami suddivisi" ogni parte è indipendente e concorre sempre al voto complessivo. */
function groupQuotaBlocksNewPrediction(e){
  var g=findGroup(e.group_id); if(!isChoiceGroup(g)) return null;
  var quota=parseFloat(g.cfu)||0, doneSum=groupDoneCfu(e.group_id), rem=quota-doneSum;
  var used=groupPredictedCfu(e.group_id, e.id), c=parseFloat(e.cfu)||0;
  if(used + c > rem + 0.001) return { used:Math.min(used,rem), rem:Math.max(0,rem) };
  return null;
}
function uniSimStep(id,d){ var e=findExam(id); if(!e) return;
  if(e.predicted_grade==null){
    var block=groupQuotaBlocksNewPrediction(e);
    if(block){ toast('Quota del gruppo già coperta ('+trimNum(block.used)+'/'+trimNum(block.rem)+' CFU): togli un altro voto prima di aggiungerne uno nuovo.'); return; }
    e.predicted_grade=26;
  } else e.predicted_grade=clampG(parseFloat(e.predicted_grade)+d);
  if(parseFloat(e.predicted_grade)<30) e.predicted_honors=false; persistPred(e); afterPred(); }
function uniSimLode(id){ var e=findExam(id); if(!e) return;
  if(e.predicted_grade==null || parseFloat(e.predicted_grade)<30){
    if(e.predicted_grade==null){
      var block=groupQuotaBlocksNewPrediction(e);
      if(block){ toast('Quota del gruppo già coperta ('+trimNum(block.used)+'/'+trimNum(block.rem)+' CFU): togli un altro voto prima di aggiungerne uno nuovo.'); return; }
    }
    e.predicted_grade=30; e.predicted_honors=true;
  } else e.predicted_honors=!toBool(e.predicted_honors); persistPred(e); afterPred(); }
function uniSimClear(id){ var e=findExam(id); if(!e) return; e.predicted_grade=null; e.predicted_honors=false; persistPred(e); afterPred(); }
function persistPred(e){ uniPost('set_prediction',{ id:e.id, predicted_grade:e.predicted_grade==null?null:Math.round(parseFloat(e.predicted_grade)), predicted_honors:!!e.predicted_honors }).catch(function(){}); }
function afterPred(){ renderSimulator(); renderChart(); renderExams(); }
/* voto complessivo previsto di un gruppo "esame suddiviso" (nel simulatore/da sostenere): proposto
   automaticamente dalla media delle parti, modificabile manualmente come nel simulatore normale */
function persistGroupOverall(g,predicted){
  var payload={ group_id:g.id };
  if(predicted){ payload.overall_predicted_grade=g.overall_predicted_grade; payload.overall_predicted_honors=!!g.overall_predicted_honors; }
  else { payload.overall_grade=g.overall_grade; payload.overall_honors=!!g.overall_honors; }
  uniPost('update_group',payload).then(function(res){ if(res&&res.groups) STATE.groups=res.groups; }).catch(function(){});
}
function uniGroupPredStep(gid,d){ var g=findGroup(gid); if(!g) return;
  var base=g.overall_predicted_grade!=null?parseFloat(g.overall_predicted_grade):null;
  if(base==null) g.overall_predicted_grade=26; else g.overall_predicted_grade=clampG(base+d);
  if(g.overall_predicted_grade<30) g.overall_predicted_honors=false;
  persistGroupOverall(g,true); afterPred(); }

function uniGroupPredLode(gid){ var g=findGroup(gid); if(!g) return;
  if(g.overall_predicted_grade==null || parseFloat(g.overall_predicted_grade)<30){ g.overall_predicted_grade=30; g.overall_predicted_honors=true; }
  else g.overall_predicted_honors=!toBool(g.overall_predicted_honors);
  persistGroupOverall(g,true); afterPred(); }

function uniGroupPredClear(gid){ 
  var g=findGroup(gid); if(!g) return;
  g.overall_predicted_grade=null; 
  g.overall_predicted_honors=false;
  persistGroupOverall(g,true); 
  afterPred(); 
}
function uniGroupPredLode(gid){ var g=findGroup(gid); if(!g) return;
  if(g.overall_predicted_grade==null || parseFloat(g.overall_predicted_grade)<30){ g.overall_predicted_grade=30; g.overall_predicted_honors=true; }
  else g.overall_predicted_honors=!toBool(g.overall_predicted_honors);
  persistGroupOverall(g,true); afterPred(); }
/* voto complessivo REGISTRATO di un gruppo "esame suddiviso" (in Sostenuti, una volta completato):
   proposto dalla media delle parti sostenute, modificabile manualmente */
function uniGroupFinalStep(gid,d){ var g=findGroup(gid); if(!g) return;
  var base=g.overall_grade!=null?parseFloat(g.overall_grade):proposedOverallGrade(groupExams(gid),false);
  if(base==null) base=26;
  g.overall_grade=clampG(base+d); if(g.overall_grade<30) g.overall_honors=false;
  persistGroupOverall(g,false); renderAll(); }
function uniGroupFinalLode(gid){ var g=findGroup(gid); if(!g) return;
  var base=g.overall_grade!=null?parseFloat(g.overall_grade):proposedOverallGrade(groupExams(gid),false);
  if(base==null || base<30){ g.overall_grade=30; g.overall_honors=true; } else g.overall_honors=!toBool(g.overall_honors);
  persistGroupOverall(g,false); renderAll(); }
/* dopo che l'ultima parte di un gruppo "esame suddiviso" viene sostenuta, propone in automatico
   il voto complessivo (media pesata delle parti); lo studente può poi modificarlo manualmente */
function maybeProposeSplitOverall(gid){
  if(gid==null) return; var g=findGroup(gid); if(!isSplitGroup(g)) return;
  if(!groupIsComplete(gid) || g.overall_grade!=null) return;
  var prop=proposedOverallGrade(groupExams(gid),false); if(prop==null) return;
  g.overall_grade=prop; g.overall_honors=false;
  uniPost('update_group',{ group_id:g.id, overall_grade:prop, overall_honors:0 }).then(function(res){
    if(res&&res.groups) STATE.groups=res.groups; renderAll(); }).catch(function(){});
}
function uniSaveSimAsTarget(){ var sim=simulated(); if(sim.cfu<=0){ toast('Assegna prima qualche voto simulato'); return; }
  var v=round2(sim.avg);
  uniPost('save_target',{ target_average:v, target_source:'simulation' }).then(function(){
    S().target_average=v; S().target_source='simulation'; STATE._reached=weighted(STATE.exams).avg>=v;
    renderHero(); renderObjective(); renderChart(); burst(); toast('🎯 Obiettivo da simulazione: '+fmt2(v)); }).catch(function(){ toast('Errore'); }); }

/* ---- Lista esami ---- */
var CHECK_SVG='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M3.6 13.1c1.7 1 3.4 2.7 4.8 4.7 2.4-5.9 6.3-10.8 11.9-14.6"/></svg>';
var DRAG_SVG='<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>';
var TRASH_SVG='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V4.6c0-.3.3-.6.6-.6h4.8c.3 0 .6.3.6.6V7"/><path d="M6 7l1 12.4c0 .9.7 1.6 1.6 1.6h6.8c.9 0 1.6-.7 1.6-1.6L18 7"/></svg>';
/* sostenuti: dal più recente al più vecchio · da sostenere: per anno di corso */
function sortedExams(tab){
  var items=STATE.exams.filter(function(e){ return e.status===tab; });
  if(tab==='done'){
    items.sort(function(a,b){ var da=a.exam_date||'', db=b.exam_date||'';
      if(da&&db){ if(da>db) return -1; if(da<db) return 1; }
      else if(da&&!db) return -1; else if(!da&&db) return 1;
      return (b.sort_order||0)-(a.sort_order||0); });
  } else {
    items.sort(function(a,b){ var ya=a.study_year||99, yb=b.study_year||99;
      if(ya!==yb) return ya-yb; return (a.sort_order||0)-(b.sort_order||0); });
  }
  return items;
}
function renderExams(){
  setTxt('cntTodo', STATE.exams.filter(function(e){ return e.status==='todo'; }).length);
  setTxt('cntDone', STATE.exams.filter(function(e){ return e.status==='done'; }).length);
  var isSim=STATE.tab==='sim', isTodo=STATE.tab==='todo';
  show('simPane', isSim); show('examList', !isSim); show('examAddBtn', !isSim);
  show('examSelBtn', isTodo);
  SWIPE_OPEN=null;
  if(isSim){ positionSeg(); renderSimulator(); show('examSelBar',false); return; }
  var selMode=STATE.selecting && isTodo;
  show('examSelBar', selMode); if(selMode) updateSelCount();
  var list=$('examList'); if(!list) return;
  var items=sortedExams(STATE.tab);
  if(!items.length){ list.innerHTML='<div class="empty" style="padding:20px 10px"><div class="em-ic" style="width:60px;height:60px;font-size:26px">'+(isTodo?'📚':'✅')+'</div><p style="margin:0">'+
    (isTodo?'Nessun esame da sostenere. Aggiungine uno o importa il tuo corso.':'Nessun esame ancora sostenuto. I voti che registri appariranno qui.')+'</p></div>';
    positionSeg(); return; }
  var h='';
  if(selMode){
    var selBlocks=clusterByGroup(items);
    for(var i=0;i<selBlocks.length;i++){ var sb=selBlocks[i];
      h += sb.type==='single' ? examRow(sb.item,true,false) : groupSelectRow(sb.gid,sb.items); }
  } else {
    /* fuori dalla selezione multipla, gli esami dello stesso gruppo vengono compattati insieme */
    var blocks=clusterByGroup(items);
    for(var j=0;j<blocks.length;j++){ var b=blocks[j];
      h += b.type==='single' ? examRow(b.item,false,false) : groupBlockHTML(b.gid,b.items); }
  }
  list.innerHTML=h; positionSeg();
  if(!selMode){ bindDrag(list); if(isTodo) bindSwipe(list); }
}
/* in selezione multipla un gruppo si comporta come un unico esame: si seleziona/deseleziona tutto insieme */
function groupSelectRow(gid,items){
  var g=findGroup(gid); if(!g){ var raw=''; for(var i0=0;i0<items.length;i0++) raw+=examRow(items[i0],true,false); return raw; }
  var isSplit=isSplitGroup(g), gidA=escAttr(gid), picked=!!STATE.selectedGroups[String(gid)];
  var allM=groupExams(gid);
  var meta='<span>'+trimNum(parseFloat(g.cfu)||0)+' CFU</span><span>\u00b7 '+allM.length+' esami</span>'+
    '<span class="gt-badge'+(isSplit?' split':'')+'" style="margin-left:2px">'+(isSplit?'Suddiviso':'A scelta')+'</span>';
  return '<li class="exam-item sel-on'+(picked?' picked':'')+'" data-id="grp-'+gidA+'" onclick="uniPickGroup(\''+gidA+'\')">'+
    '<span class="sel-dot">'+(picked?'\u2713':'')+'</span>'+
    '<div class="exam-body"><div class="exam-name">'+esc(g.name)+'</div><div class="exam-meta">'+meta+'</div></div>'+
  '</li>';
}
function examRow(e,selMode,inGroup){
  var id=escAttr(e.id), meta='<span>'+trimNum(e.cfu)+' CFU</span>';
  if(e.study_year) meta+='<span>\u00b7 '+e.study_year+'\u00b0 anno</span>';
  if(e.exam_date && e.status==='done') meta+='<span>\u00b7 '+esc(fmtDate(e.exam_date))+'</span>';
  if(!inGroup){ var g=findGroup(e.group_id); if(g) meta+='<span class="grp-tag">\u21c4 '+esc(g.name)+'</span>'; }
  if(e.national_avg!=null && isGraded(e)){ var d=effGrade(e)-parseFloat(e.national_avg);
    meta+='<span class="cmp '+(d>=0?'up':'down')+'">'+(d>=0?'\u25b2':'\u25bc')+' '+fmt2(Math.abs(d))+'</span>'; }
  else if(e.national_avg!=null){ meta+='<span class="nat">media '+fmt2(parseFloat(e.national_avg))+'</span>'; }
  if(selMode){
    var picked=!!STATE.selected[e.id];
    return '<li class="exam-item sel-on'+(picked?' picked':'')+'" data-id="'+id+'" onclick="uniPickRow(\''+id+'\')">'+
      '<span class="sel-dot">'+(picked?'\u2713':'')+'</span>'+
      '<div class="exam-body"><div class="exam-name">'+esc(e.name)+'</div><div class="exam-meta">'+meta+'</div></div>'+
      '<div class="exam-trail">'+gradeBadge(e)+'</div></li>';
  }
  var inner=(inGroup?'':'<span class="exam-drag" data-drag="1">'+DRAG_SVG+'</span>')+
    '<div class="exam-body" onclick="uniOpenExam(\''+id+'\')"><div class="exam-name">'+esc(e.name)+'</div><div class="exam-meta">'+meta+'</div></div>'+
    '<div class="exam-trail">'+gradeBadge(e)+
    '<div class="'+(e.status==='done'?'exam-check done':'exam-check')+'" onclick="uniCheckTap(\''+id+'\',event)">'+CHECK_SVG+'</div></div>';
  if(STATE.tab==='todo'){
    /* swipe verso destra per rivelare "elimina": il tasto sta sotto al contenuto, invisibile a riposo */
    return '<li class="exam-item swipeable" data-id="'+id+'">'+
      '<button type="button" class="swipe-del" onclick="uniSwipeDelete(\''+id+'\',event)">'+TRASH_SVG+'</button>'+
      '<div class="swipe-content">'+inner+'</div>'+
    '</li>';
  }
  return '<li class="exam-item" data-id="'+id+'">'+inner+'</li>';
}
/* card compatta per un gruppo (usata in Sostenuti e Da sostenere): intestazione con nome/tipo/CFU,
   le sue parti sotto (già filtrate alla tab corrente da clusterByGroup) ed eventuale voto complessivo */
function groupBlockHTML(gid,items){
  var g=findGroup(gid); if(!g){ var raw=''; for(var i0=0;i0<items.length;i0++) raw+=examRow(items[i0],false,false); return raw; }
  var isSplit=isSplitGroup(g), gidA=escAttr(gid);
  var quotaLabel;
  if(isSplit){ quotaLabel=trimNum(parseFloat(g.cfu)||0)+' CFU tot.'; }
  else { var qp=groupQuotaProgress(gid); quotaLabel=trimNum(qp.done)+(qp.pred>0?('+'+trimNum(qp.pred)):'')+'/'+trimNum(qp.quota)+' CFU'; }
  var h='<li class="exam-group" data-id="grp-'+gidA+'"><div class="exam-group-inner">'+
    '<div class="exam-group-head"><span class="exam-drag" data-drag="1">'+DRAG_SVG+'</span>'+
    '<span class="gt-name" onclick="uniOpenGroupSheet(\''+gidA+'\')">'+esc(g.name)+'</span>'+
    '<span class="gt-badge'+(isSplit?' split':'')+'">'+(isSplit?'Suddiviso':'A scelta')+'</span>'+
    '<span class="gt-quota">'+quotaLabel+'</span>'+
    '</div><ul class="exam-list" style="margin:0;padding:0">';
  for(var i=0;i<items.length;i++) h+=examRow(items[i],false,true);
  h+='</ul>';
  if(isSplit){
    if(groupIsComplete(gid)){
      var prop=g.overall_grade!=null?g.overall_grade:proposedOverallGrade(groupExams(gid),false);
      var curHon=toBool(g.overall_honors), val=prop!=null?(curHon?'30L':prop):'–';
      h+='<div class="grp-overall"><span class="go-lbl">Voto complessivo</span>'+
        '<div class="stepper"><button type="button" onclick="uniGroupFinalStep(\''+gidA+'\',-1)">\u2212</button>'+
        '<span class="val'+(curHon?' lode':'')+'">'+val+'</span>'+
        '<button type="button" onclick="uniGroupFinalStep(\''+gidA+'\',1)">+</button></div>'+
        '<button type="button" class="chip-tog'+(curHon?' gold on':'')+'" style="padding:9px 12px" onclick="uniGroupFinalLode(\''+gidA+'\')">L</button>'+
      '</div>';
    } else {
      var allM=groupExams(gid), doneN=allM.filter(function(x){ return x.status==='done'; }).length;
      if(doneN>0) h+='<div class="grp-note">'+doneN+'/'+allM.length+' parti sostenute \u00b7 mancano ancora '+(allM.length-doneN)+' per completare l\'esame.</div>';
    }
  }
  h+='</div></li>';
  return h;
}

/* Colore dei voti 18-27: basato sul colore impostato della facoltà.
   L'opacità del background scende (fino a diventare quasi trasparente sul 18)
   man mano che si scende sotto la media, mentre diventa piena e vivida per i voti sopra la media. */
function gradeDynamicStyle(g, avg) {
    if(!avg || avg <= 0) return 'background:var(--surface-2); color:var(--text-3); border:1.5px dashed var(--hairline-2);';

    var hex = String(S().accent_color || '#0A84FF').replace('#', '');
    if(hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    var r = parseInt(hex.substr(0, 2), 16),
        gg = parseInt(hex.substr(2, 2), 16),
        b = parseInt(hex.substr(4, 2), 16);

    var alpha;
    if (g <= 18) {
        alpha = 0.15;
    } else if (g >= avg + 1) {
        var maxG = Math.max(27, avg + 2);
        alpha = 0.85 + 0.15 * Math.min(1, (g - avg) / (maxG - avg));
    } else {
        var range = Math.max(1, avg - 18);
        alpha = 0.15 + (0.70 * ((g - 18) / range));
    }

    // Usa var(--text) per garantire la massima leggibilità quando lo sfondo è chiaro
    var textColor = alpha > 0.60 ? '#fff' : 'var(--text)';
    
    var style = 'background:rgba(' + r + ',' + gg + ',' + b + ',' + alpha.toFixed(2) + '); color:' + textColor + ';';

    if (g >= avg + 1) {
        var shadowAlpha = Math.min(0.4, (g - avg) * 0.08);
        style += 'box-shadow:0 3px 12px rgba(' + r + ',' + gg + ',' + b + ',' + shadowAlpha.toFixed(2) + ');';
    }

    return style;
}
function gradeBadge(e){
  if(toBool(e.is_pass_fail)) return '<span class="grade-badge g-ido">Idoneità</span>';
  if(e.status==='todo'){ if(e.predicted_grade!=null) return '<span class="grade-badge g-todo">~'+(toBool(e.predicted_honors)?'30L':Math.round(e.predicted_grade))+'</span>';
    return '<span class="grade-badge g-todo">\u2014</span>'; }
  if(e.grade==null) return '<span class="grade-badge g-todo">\u2014</span>';
  if(toBool(e.has_honors)) return '<span class="grade-badge g-lode">30<span class="lode-star">L</span></span>';
  var g=Math.round(parseFloat(e.grade));
  if(g>=30) return '<span class="grade-badge g-30">30</span>';
  if(g===29) return '<span class="grade-badge g-29">29</span>';
  if(g===28) return '<span class="grade-badge g-28">28</span>';
  var avg=weighted(STATE.exams).avg;
  return '<span class="grade-badge g-dyn" style="'+gradeDynamicStyle(g,avg)+'">'+g+'</span>';
}
function uniCheckTap(id,ev){ if(ev) ev.stopPropagation(); var e=findExam(id); if(!e) return;
  if(e.status==='done') uniOpenExam(id); else openQuick(e); }
function positionSeg(){ var seg=$('examSeg'),thumb=$('segThumb'); if(!seg||!thumb) return;
  var btns=seg.querySelectorAll('button'); if(!btns.length) return;
  var idx=STATE.tab==='done'?0:(STATE.tab==='todo'?1:2);
  var on=btns[idx]||btns[0], base=btns[0];
  for(var i=0;i<btns.length;i++) btns[i].classList.toggle('on', btns[i]===on);
  thumb.style.left=base.offsetLeft+'px'; thumb.style.width=on.offsetWidth+'px';
  thumb.style.transform='translateX('+(on.offsetLeft-base.offsetLeft)+'px)'; }
function uniSetTab(t){ if(t!=='todo' && STATE.selecting) exitSelect(true); STATE.tab=t; renderExams(); }

/* ---- Selezione multipla e gruppi di esami a scelta ---- */
function selectedIds(){ var out=[]; for(var k in STATE.selected){ if(STATE.selected.hasOwnProperty(k) && STATE.selected[k]) out.push(k); } return out; }
function selectedGroupIds(){ var out=[]; for(var k in STATE.selectedGroups){ if(STATE.selectedGroups.hasOwnProperty(k) && STATE.selectedGroups[k]) out.push(k); } return out; }
function updateSelCount(){
  var n=selectedIds().length, ng=selectedGroupIds().length, tot=n+ng;
  setTxt('selCount', tot===1?'1 selezionato':(tot+' selezionati'));
  /* "Crea gruppo" ha senso solo tra esami singoli non ancora raggruppati */
  show('selCreateBtn', ng===0); show('selUngroupBtn', ng>0);
}
function uniToggleSelect(){ if(STATE.selecting) exitSelect(); else { STATE.selecting=true; STATE.selected={}; STATE.selectedGroups={};
    var b=$('examSelBtn'); if(b) b.textContent='Annulla'; renderExams(); } }
function exitSelect(silent){ STATE.selecting=false; STATE.selected={}; STATE.selectedGroups={};
  var b=$('examSelBtn'); if(b) b.textContent='Seleziona'; if(!silent) renderExams(); }
function uniPickRow(id){ if(STATE.selected[id]) delete STATE.selected[id]; else STATE.selected[id]=true;
  buzz(6); renderExams(); }
function uniPickGroup(gid){ if(STATE.selectedGroups[gid]) delete STATE.selectedGroups[gid]; else STATE.selectedGroups[gid]=true;
  buzz(6); renderExams(); }
/* scioglie i gruppi selezionati: gli esami restano, i CFU tornano a contare singolarmente */
function uniDissolveSelectedGroups(){
  var gids=selectedGroupIds(); if(!gids.length) return;
  if(!confirm('Sciogliere '+gids.length+' grupp'+(gids.length===1?'o':'i')+'? Gli esami restano, ma i CFU torneranno a contare singolarmente.')) return;
  var chain=Promise.resolve();
  gids.forEach(function(gid){ chain=chain.then(function(){ return uniPost('dissolve_group',{ group_id:gid }); }); });
  chain.then(function(res){
    if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
    exitSelect(true); renderAll(); toast('Gruppi sciolti'); }).catch(function(){ toast('Errore'); });
}
/* elimina gli esami singoli selezionati e, per ogni gruppo selezionato, TUTTI i suoi esami */
function uniDeleteSelected(){
  var ids=selectedIds(), gids=selectedGroupIds();
  if(!ids.length && !gids.length){ toast('Nessun esame selezionato'); return; }
  var total=ids.length; for(var i=0;i<gids.length;i++) total+=groupExams(gids[i]).length;
  if(!confirm('Eliminare '+total+' esam'+(total===1?'e':'i')+'?')) return;
  var chain=Promise.resolve();
  gids.forEach(function(gid){ chain=chain.then(function(){ return uniPost('delete_group',{ group_id:gid }); }); });
  if(ids.length) chain=chain.then(function(){ return uniPost('delete_exams',{ ids:ids }); });
  chain.then(function(res){
    if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
    exitSelect(true); renderAll(); toast('Eliminati'); }).catch(function(){ toast('Errore'); });
}
/* ---- Popup gruppo (crea/modifica) ---- */
var GRP={ id:null, type:'choice', quota:null, picked:{} };
function uniOpenGroupSheet(existingGroupId){
  uniCloseSheets();
  var g=existingGroupId?findGroup(existingGroupId):null;
  var initialIds = g ? groupExams(g.id).map(function(e){ return String(e.id); }) : selectedIds();
  GRP = { id:g?g.id:null, type:g?g.group_type:'choice', quota:g?parseFloat(g.cfu):null, picked:{} };
  for(var i=0;i<initialIds.length;i++) GRP.picked[String(initialIds[i])]=true;
  setV('grpName', g?g.name:'');
  toggleChip('grpTypeChoice', GRP.type!=='split'); toggleChip('grpTypeSplit', GRP.type==='split');
  show('grpDeleteBtn', !!GRP.id);
  setTxt('grpSheetTitle', GRP.id?'Modifica gruppo':'Crea gruppo');
  syncGroupTypeHint(); renderGroupPicker(); renderQuotaOptions();
  openSheet('groupSheet');
}
function uniEditExamGroup(){ var e=EX.id?findExam(EX.id):null; if(!e||e.group_id==null) return; uniOpenGroupSheet(e.group_id); }
function syncGroupTypeHint(){
  setTxt('grpTypeHint', GRP.type==='split'
    ? 'Le parti selezionate formano UN esame: i CFU si sommano e, quando saranno tutte sostenute, avrai un voto complessivo (proposto in automatico dalla media delle parti, modificabile a mano).'
    : 'Sono esami alternativi tra loro: scegli quanti CFU vale il gruppo. Una volta sostenuta quella quota, gli altri esami del gruppo spariranno da "Da sostenere" e "Simulatore".');
  show('grpQuotaWrap', GRP.type!=='split');
}
function uniGroupSetType(type){ GRP.type = type==='split'?'split':'choice';
  toggleChip('grpTypeChoice', GRP.type!=='split'); toggleChip('grpTypeSplit', GRP.type==='split');
  syncGroupTypeHint(); renderQuotaOptions(); }
function renderGroupPicker(){
  var wrap=$('grpPickList'); if(!wrap) return;
  var pool = STATE.exams.filter(function(e){ return e.group_id==null || String(e.group_id)===String(GRP.id); });
  /* prima i selezionati, poi i "da sostenere", infine i "sostenuti" */
  pool.sort(function(a,b){
    var pa=GRP.picked[String(a.id)]?0:1, pb=GRP.picked[String(b.id)]?0:1; if(pa!==pb) return pa-pb;
    var sa=a.status==='todo'?0:1, sb=b.status==='todo'?0:1; if(sa!==sb) return sa-sb;
    var ya=a.study_year||99, yb=b.study_year||99; if(ya!==yb) return ya-yb;
    return (a.sort_order||0)-(b.sort_order||0); });
  if(!pool.length){ wrap.innerHTML='<div class="grp-pick-empty">Nessun esame disponibile (gli esami già in un altro gruppo non compaiono qui).</div>'; setTxt('grpPickCount','0'); return; }
  var h='', n=0;
  for(var i=0;i<pool.length;i++){ var e=pool[i], id=escAttr(e.id), picked=!!GRP.picked[String(e.id)]; if(picked) n++;
    var meta='<span>'+trimNum(e.cfu)+' CFU</span>'+(e.study_year?('<span>\u00b7 '+e.study_year+'\u00b0 anno</span>'):'')+(e.status==='done'?'<span>\u00b7 sostenuto</span>':'');
    h+='<li class="exam-item sel-on'+(picked?' picked':'')+'" data-id="'+id+'" onclick="uniGroupPickToggle(\''+id+'\')">'+
      '<span class="sel-dot">'+(picked?'\u2713':'')+'</span>'+
      '<div class="exam-body"><div class="exam-name">'+esc(e.name)+'</div><div class="exam-meta">'+meta+'</div></div>'+
      gradeBadge(e)+'</li>'; }
  wrap.innerHTML=h; setTxt('grpPickCount',String(n));
}
function uniGroupPickToggle(id){ if(GRP.picked[id]) delete GRP.picked[id]; else GRP.picked[id]=true;
  buzz(6); renderGroupPicker(); renderQuotaOptions(); }
function renderQuotaOptions(){
  var wrap=$('grpQuotaRow'); if(!wrap) return;
  if(GRP.type==='split'){ wrap.innerHTML=''; return; }
  var ids=Object.keys(GRP.picked), cfus=[];
  for(var i=0;i<ids.length;i++){ var e=findExam(ids[i]); if(e) cfus.push(parseFloat(e.cfu)||0); }
  var sums=possibleCfuSums(cfus);
  if(!sums.length){ wrap.innerHTML='<span class="grp-note">Seleziona almeno un esame per vedere le quote possibili.</span>'; return; }
  if(GRP.quota==null || sums.indexOf(GRP.quota)===-1) GRP.quota=sums[sums.length-1];
  var h=''; for(var j=0;j<sums.length;j++){ var v=sums[j];
    h+='<button type="button" class="chip-tog'+(Math.abs(v-GRP.quota)<0.001?' on':'')+'" onclick="uniGroupSetQuota('+v+')">'+trimNum(v)+' CFU</button>'; }
  wrap.innerHTML=h;
}
function uniGroupSetQuota(v){ GRP.quota=parseFloat(v); renderQuotaOptions(); }
function uniSaveGroup(){
  var name=(($('grpName')&&$('grpName').value)||'').trim();
  var ids=Object.keys(GRP.picked);
  if(ids.length<2){ toast('Seleziona almeno due esami'); return; }
  if(!name) name = GRP.type==='split' ? 'Esame suddiviso' : ('Gruppo '+(STATE.groups.length+1));
  var payload={ name:name, group_type:GRP.type, ids:ids };
  if(GRP.type!=='split'){ if(GRP.quota==null){ toast('Scegli i CFU totali del gruppo'); return; } payload.cfu=GRP.quota; }
  var wasNew = !GRP.id;
  var action='create_group';
  if(GRP.id){ action='update_group'; payload.group_id=GRP.id; }
  uniPost(action,payload).then(function(res){
    if(res&&res.success===false){ toast('Servono almeno due esami'); return; }
    if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
    exitSelect(true); uniCloseSheets(); renderAll();
    toast(wasNew ? (GRP.type==='split' ? '\ud83e\udde9 Esame suddiviso creato' : ('\u21c4 Gruppo creato: '+trimNum(GRP.quota)+' CFU contati una volta sola')) : 'Gruppo aggiornato');
  }).catch(function(){ toast('Errore'); });
}
function uniDissolveGroupFromSheet(){ if(!GRP.id) return;
  if(!confirm('Sciogliere il gruppo? Gli esami restano, ma i loro CFU torneranno a contare singolarmente.')) return;
  uniPost('dissolve_group',{ group_id:GRP.id }).then(function(res){
    if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
    uniCloseSheets(); renderAll(); toast('Gruppo sciolto'); }).catch(function(){ toast('Errore'); }); }
function uniDissolveGroup(){ var e=EX.id?findExam(EX.id):null; if(!e||e.group_id==null) return;
  if(!confirm('Sciogliere il gruppo? Gli esami restano, ma i loro CFU torneranno a contare singolarmente.')) return;
  uniPost('dissolve_group',{ group_id:e.group_id }).then(function(res){
    if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
    uniCloseSheets(); renderAll(); toast('Gruppo sciolto'); }).catch(function(){ toast('Errore'); }); }
/* superato un esame di un gruppo "a scelta" → se la quota di CFU è stata raggiunta, proponi di
   togliere gli altri esami del gruppo ancora da sostenere (i gruppi "esame suddiviso" non si sciolgono mai qui) */
function maybeClearGroup(examId, groupId){
  if(groupId==null) return;
  var g=findGroup(groupId); if(!isChoiceGroup(g)) return;
  var quota=parseFloat(g.cfu)||0, doneSum=groupDoneCfu(groupId);
  if(doneSum<quota) return;
  var others=STATE.exams.filter(function(e){ return e.group_id!=null && String(e.group_id)===String(groupId)
    && e.status==='todo' && String(e.id)!==String(examId); });
  if(!others.length) return;
  var gname=g.name||'gruppo a scelta';
  setTimeout(function(){
    if(confirm('"'+gname+'" \u00e8 un gruppo di esami a scelta e hai raggiunto i '+trimNum(quota)+' CFU della quota.\n\nVuoi eliminare gli altri '+others.length+' dagli esami da sostenere?')){
      uniPost('clear_group_todo',{ group_id:groupId, keep_id:examId }).then(function(res){
        if(res&&res.exams) STATE.exams=res.exams; if(res&&res.groups) STATE.groups=res.groups;
        renderAll(); toast('Gruppo completato'); }).catch(function(){ toast('Errore'); });
    } }, 500);
}

/* ---- Statistiche ---- */
function donutSVG(segs){
  var total=0; for(var i=0;i<segs.length;i++) total+=segs[i].val;
  var R=42,C=2*Math.PI*R,off=0;
  var s='<svg viewBox="0 0 104 104"><circle cx="52" cy="52" r="42" fill="none" stroke="var(--surface-3)" stroke-width="14"/>';
  if(total>0) for(var j=0;j<segs.length;j++){ var frac=segs[j].val/total; if(frac<=0) continue; var len=C*frac;
    s+='<circle cx="52" cy="52" r="42" fill="none" stroke="'+segs[j].color+'" stroke-width="14" stroke-dasharray="'+len.toFixed(2)+' '+(C-len).toFixed(2)+'" stroke-dashoffset="'+(-off).toFixed(2)+'" transform="rotate(-90 52 52)"/>'; off+=len; }
  s+='<text x="52" y="49" text-anchor="middle" font-size="15" font-weight="800" fill="var(--text)">'+trimNum(total)+'</text><text x="52" y="64" text-anchor="middle" font-size="9" fill="var(--text-3)">CFU</text></svg>';
  return s;
}
function renderStats(){
  var body=$('statsBody'); if(!body) return;
  var graded=STATE.exams.filter(isGraded);
  if(!graded.length){ body.innerHTML='<div class="empty" style="padding:16px 10px"><div class="em-ic" style="width:60px;height:60px;font-size:26px">📊</div><p style="margin:0">Le statistiche compariranno dopo i primi voti registrati.</p></div>'; return; }
  var h='';
  var counts={},lode30=0; for(var g=18;g<=30;g++) counts[g]=0;
  for(var i=0;i<graded.length;i++){ var e=graded[i]; var gv=toBool(e.has_honors)?30:Math.round(parseFloat(e.grade));
    if(gv<18)gv=18; if(gv>30)gv=30; counts[gv]++; if(gv===30&&toBool(e.has_honors)) lode30++; }
  var maxC=1; for(var g2=18;g2<=30;g2++) maxC=Math.max(maxC,counts[g2]);
  var histAvg=weighted(STATE.exams).avg;
  h+='<div class="stat-block"><div class="t">Distribuzione voti</div><div class="hist">';
  for(var g3=18;g3<=30;g3++){ var c=counts[g3],hp=Math.round(c/maxC*100),bg;
    if(g3===30){ if(c>0 && lode30>0){ var plainPct=Math.round((c-lode30)/c*100);
        bg='background:linear-gradient(to top,var(--gold) '+plainPct+'%,#FFD700 '+plainPct+'%);'; }
      else bg='background:var(--gold);'; }
    else if(g3===29) bg='background:var(--gold);';
    else if(g3===28) bg='background:var(--silver);';
    else { 
        if(c > 0){ 
            var hex = String(S().accent_color || '#0A84FF').replace('#', '');
            if(hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
            var r = parseInt(hex.substr(0, 2), 16), gg = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
            var alpha = 0.15;
            if (g3 >= histAvg + 1) {
                var maxG = Math.max(27, histAvg + 2);
                alpha = 0.85 + 0.15 * Math.min(1, (g3 - histAvg) / (maxG - histAvg));
            } else {
                var range = Math.max(1, histAvg - 18);
                alpha = 0.15 + (0.70 * ((g3 - 18) / range));
            }
            bg = 'background:rgba(' + r + ',' + gg + ',' + b + ',' + alpha.toFixed(2) + ');'; 
        } else { 
            bg = ''; 
        } 
    }
    var bar='<div class="bar" style="height:'+hp+'%;'+bg+'">'+(c>0?'<span class="cnt">'+c+'</span>':'')+'</div>';
    h+='<div class="col">'+bar+'<div class="xl">'+g3+'</div></div>'; }
  h+='</div>'+(lode30>0?'<div style="text-align:center;font-size:.75rem;color:var(--text-3);margin-top:6px">🌟 di cui '+lode30+' con lode</div>':'')+'</div>';
  var years={}; for(var i2=0;i2<graded.length;i2++){ var e2=graded[i2],y=e2.study_year||0; if(!years[y]) years[y]={sum:0,cfu:0};
    var cc=parseFloat(e2.cfu)||0; years[y].sum+=effGrade(e2)*cc; years[y].cfu+=cc; }
  var yk=Object.keys(years).filter(function(k){ return k!=='0'; }).sort();
  if(yk.length){ h+='<div class="stat-block"><div class="t">Media per anno</div>';
    for(var yi=0;yi<yk.length;yi++){ var k=yk[yi],ya=years[k].cfu>0?years[k].sum/years[k].cfu:0,wp=Math.round((ya-18)/12*100);
      h+='<div class="ybar"><div class="yl">'+k+'\u00b0 anno</div><div class="yt"><i style="width:'+Math.max(2,wp)+'%"></i><b>'+fmt2(ya)+'</b></div></div>'; }
    h+='</div>'; }
  var dg=0,di=0,tc=0, chG={}, chI={}, seenCh={};
  for(var i3=0;i3<STATE.exams.length;i3++){ 
    var e3=STATE.exams[i3]; if(!toBool(e3.counts_for_graduation)) continue;
    var c3=parseFloat(e3.cfu)||0, g3=e3.group_id!=null?findGroup(e3.group_id):null;
    // Se l'esame fa parte di un gruppo a scelta, accumula i CFU sostenuti per quel gruppo senza sommarli subito al totale
    if(isChoiceGroup(g3)){ 
      var k3=String(g3.id); 
      if(e3.status==='done'){ if(toBool(e3.is_pass_fail)) chI[k3]=(chI[k3]||0)+c3; else chG[k3]=(chG[k3]||0)+c3; } 
      continue; 
    }
    // Esami singoli o parti di gruppi "suddivisi" (che si sommano sempre)
    if(e3.status==='done'){ if(toBool(e3.is_pass_fail)) di+=c3; else dg+=c3; } else tc+=c3; 
  }
  // Calcola i CFU finali per i gruppi a scelta applicando il limite (quota) del gruppo
  for(var j3=0;j3<STATE.exams.length;j3++){ 
    var e4=STATE.exams[j3]; if(e4.group_id==null) continue;
    var g4=findGroup(e4.group_id); if(!isChoiceGroup(g4)) continue;
    var k4=String(g4.id); if(seenCh[k4]) continue; seenCh[k4]=true;
    var q4=parseFloat(g4.cfu)||0, dSum=(chG[k4]||0)+(chI[k4]||0);
    if(dSum>0){ 
      var cap=Math.min(dSum,q4), r=cap/dSum; 
      dg+=(chG[k4]||0)*r; di+=(chI[k4]||0)*r; 
      if(dSum<q4) tc+=(q4-dSum); 
    } else {
      tc+=q4; 
    } 
  }
  h+='<div class="stat-block"><div class="t">CFU per stato</div><div class="donut-wrap">'+donutSVG([
    {val:dg,color:'var(--uni-accent)'},{val:di,color:'var(--green)'},{val:tc,color:'var(--surface-3)'}])+
    '<div class="donut-leg"><div class="di"><span class="sw" style="background:var(--uni-accent)"></span>Voti registrati<b>'+trimNum(dg)+'</b></div>'+
    '<div class="di"><span class="sw" style="background:var(--green)"></span>Idoneità<b>'+trimNum(di)+'</b></div>'+
    '<div class="di"><span class="sw" style="background:var(--surface-3)"></span>Da sostenere<b>'+trimNum(tc)+'</b></div></div></div></div>';
  var mine=0,natl=0,cc4=0,n=0; for(var i4=0;i4<graded.length;i4++){ var e4=graded[i4]; if(e4.national_avg==null) continue;
    var c4=parseFloat(e4.cfu)||0; mine+=effGrade(e4)*c4; natl+=parseFloat(e4.national_avg)*c4; cc4+=c4; n++; }
  if(n>0 && cc4>0){ var mA=mine/cc4,nA=natl/cc4,diff=mA-nA;
    h+='<div class="stat-block"><div class="t">Confronto con la media di classe</div><div class="cmp-line"><span class="big '+(diff>=0?'up':'down')+'">'+(diff>=0?'+':'')+fmt2(diff)+'</span><span class="txt"><b>'+(diff>=0?'sopra':'sotto')+'</b> la media nazionale stimata ('+fmt2(nA)+') su '+n+' esami</span></div><div style="font-size:.72rem;color:var(--text-3);margin-top:6px">Le medie di classe sono stime indicative, modificabili per ogni esame.</div></div>'; }
  var dc=graded.length,lt=lodiCount(false),an=weighted(STATE.exams).avg;
  var tgt=S().target_average!=null?parseFloat(S().target_average):null, reached=tgt!=null && an>=tgt;
  var miles=[{em:'\u2b50',lbl:'Primo esame',got:dc>=1},{em:'\ud83d\udd25',lbl:'3 esami',got:dc>=3},{em:'\ud83d\udc51',lbl:'Prima lode',got:lt>=1},{em:'\ud83d\udc8e',lbl:'Media 28+',got:an>=28},{em:'\ud83c\udfaf',lbl:'Obiettivo',got:reached}];
  h+='<div class="stat-block"><div class="t">Traguardi</div><div class="badge-row">';
  for(var m=0;m<miles.length;m++) h+='<div class="mbadge'+(miles[m].got?' got':'')+'"><span class="em">'+miles[m].em+'</span>'+miles[m].lbl+'</div>';
  h+='</div></div>';
  body.innerHTML=h;
}

/* ---- Tesi & Bonus ---- */
/* --fx: quanto la targhetta va spostata rispetto al punto (0% bordo sx, 100% bordo dx) */
function anchorFx(p){ return p<7 ? '0%' : (p>93 ? '100%' : '50%'); }
function mark(p,label,gold){ return '<span'+(gold?' class="lode"':'')+' style="left:'+p.toFixed(2)+'%;--fx:'+anchorFx(p)+'">'+label+'</span>'; }
function thesisFlag(p,label,gold){ return '<div class="flag'+(gold?' gold':'')+'" style="left:'+p.toFixed(2)+'%;--fx:'+anchorFx(p)+'">'+label+'</div>'; }
/* asse non lineare: più spazio vicino ai voti stimati (i punti "focus"), più compresso lontano
   da essi, così le etichette dei voti stimati non si accavallano con quelle fisse della scala
   (66/88/base/lode) quando cadono vicine su schermi stretti. Estremi 0 e 100 restano fissi. */
function buildAxisWarp(focusPts){
  var N=100, SIGMA=6, BOOST=2.5, dens=[], i, k, d, dist;
  for(i=0;i<=N;i++){ d=1;
    for(k=0;k<focusPts.length;k++){ dist=Math.abs(i-focusPts[k]); d+=BOOST*Math.exp(-(dist*dist)/(2*SIGMA*SIGMA)); }
    dens.push(d); }
  var cum=[0]; for(i=1;i<=N;i++) cum.push(cum[i-1]+(dens[i-1]+dens[i])/2);
  var total=cum[N]||1;
  return function(pct){ pct=Math.max(0,Math.min(100,pct));
    var i0=Math.floor(pct), i1=Math.min(N,i0+1), frac=pct-i0;
    var c=cum[i0]+(cum[i1]-cum[i0])*frac; return c/total*100; };
}
/* HTML condiviso delle 3 barre "voto di laurea" (hero, obiettivo, tesi&bonus) */
function thesisVisHTML(minVal,maxVal){
  var gb=gradeBase(), x1=gradePct(minVal), x2=gradePct(maxVal), topReached=maxVal>gb+0.001;
  var focus=(Math.abs(x2-x1)>0.01)?[x1,x2]:[x1];
  var warp=buildAxisWarp(focus);
  var wx1=warp(x1), wx2=warp(x2);
  var h='<div class="scale"><div class="fill'+(topReached?' bar-gold':'')+'" style="left:'+wx1.toFixed(2)+'%;width:'+Math.max(1.5,(wx2-wx1)).toFixed(2)+'%"></div>';
  h+=thesisFlag(wx1, dispFinal(minVal), minVal>gb+0.001);
  if(Math.abs(x2-x1)>7) h+=thesisFlag(wx2, dispFinal(maxVal), topReached);
  h+='<div class="tick" style="left:'+warp(92).toFixed(2)+'%"></div></div><div class="marks">'+
      mark(warp(0),'66')+mark(warp(gradePct(88)),'88')+mark(warp(92),String(gb))+mark(warp(100),'L',true)+'</div>';
  return h;
}
function renderThesisBonus(){
  var tb=$('thesisBody'), gb=gradeBase();
  var tmin=parseFloat(S().thesis_min_points)||0, tmax=parseFloat(S().thesis_max_points)||0;
  var avg=weighted(STATE.exams).avg, base=avg>0?base110(avg):0, bonus=bonusTotal(false);
  if(tb){ var floorV=base+bonus+tmin, ceilV=base+bonus+tmax;
    var h='<p style="color:var(--text-2);font-size:.85rem;margin:0 0 6px">La tesi aggiunge da <b style="color:var(--text)">+'+trimNum(tmin)+'</b> a <b style="color:var(--text)">+'+trimNum(tmax)+'</b> punti. '+
      (avg>0?('Con la media attuale il voto di laurea andrebbe da <b style="color:var(--text)">'+dispFinal(floorV)+'</b> a <b style="color:var(--text)">'+dispFinal(ceilV)+'</b>/'+gb+'.'):'Registra dei voti per stimare il voto di laurea.')+'</p>';
    h+='<div class="thesis-vis">'+(avg>0?thesisVisHTML(floorV,ceilV):
      ('<div class="scale"><div class="tick" style="left:92%"></div></div><div class="marks">'+mark(0,'66')+mark(gradePct(88),'88')+mark(92,String(gb))+mark(100,'L',true)+'</div>'))+'</div>';
    tb.innerHTML=h; }
  var list=$('bonusList'); if(!list) return;
  var lodi=lodiCount(false);
  if(!STATE.bonuses.length){ list.innerHTML='<div class="empty" style="padding:14px 10px"><p style="margin:0">Nessun bonus. Aggiungi bonus come "laurea in corso" o punti per le lodi con il pulsante ＋ Bonus.</p></div>'; return; }
  var sorted=STATE.bonuses.slice().sort(function(a,b){ return (a.sort_order||0)-(b.sort_order||0); });
  var bh=''; for(var i=0;i<sorted.length;i++) bh+=bonusRow(sorted[i],lodi); list.innerHTML=bh;
}
function bonusIcon(t){ return t==='on_time'?'\u23f1\ufe0f':t==='honors_each'?'\ud83c\udf1f':t==='honors_threshold'?'\ud83c\udfc5':t==='fixed'?'\u2795':'\ud83c\udf81'; }
function bonusDesc(b){ var pts=parseFloat(b.points)||0;
  if(b.bonus_type==='on_time') return 'Laurea in corso \u00b7 +'+trimNum(pts);
  if(b.bonus_type==='honors_each'){ var cap=capOf(b); return '+'+trimNum(pts)+' per ogni lode'+(cap!=null?(' (max +'+trimNum(cap)+')'):''); }
  if(b.bonus_type==='honors_threshold') return '+'+trimNum(pts)+' con almeno '+trimNum(b.threshold_value||0)+' lodi';
  if(b.bonus_type==='fixed') return 'Bonus fisso \u00b7 +'+trimNum(pts);
  return 'Personalizzato \u00b7 +'+trimNum(pts); }
function bonusRow(b,lodi){
  var applied=bonusApplied(b,lodi), on=toBool(b.is_enabled), id=escAttr(b.id);
  return '<li class="bonus-item" data-id="'+id+'">'+
    '<div class="bic">'+bonusIcon(b.bonus_type)+'</div>'+
    '<div class="bd" onclick="uniOpenBonus(\''+id+'\')"><div class="bn">'+esc(b.name)+'</div><div class="bs">'+esc(bonusDesc(b))+'</div></div>'+
    '<div class="bp'+(applied>0?'':' zero')+'">'+(applied>0?'+'+trimNum(applied):'\u2014')+'</div>'+
    '<label class="sw-toggle"><input type="checkbox" '+(on?'checked':'')+' onchange="uniToggleBonus(\''+id+'\',this.checked)"><span class="track"></span><span class="thumb"></span></label>'+
  '</li>';
}
function uniToggleBonus(id,on){ var b=findBonus(id); if(!b) return; b.is_enabled=on;
  uniPost('toggle_bonus',{ id:id, is_enabled:on }).catch(function(){});
  renderHero(); renderObjective(); renderThesisBonus(); }

/* ---- Sheet base ---- */
function openSheet(id){ var bk=$('sheetBackdrop'); if(bk) bk.classList.add('open'); var sh=$(id); if(sh) sh.classList.add('open');
  document.documentElement.style.overflow='hidden'; }
function uniCloseSheets(){ var bk=$('sheetBackdrop'); if(bk) bk.classList.remove('open');
  var sheets=document.querySelectorAll('.sheet'); for(var i=0;i<sheets.length;i++) sheets[i].classList.remove('open');
  document.documentElement.style.overflow=''; }

/* ---- Editor esame ---- */
/* EX.grade è null finché lo studente non tocca davvero il voto (gradeSet=false):
   così modificare solo i CFU/anno di un esame "da sostenere" non gli appiccica più un 26 previsto. */
var EX={ id:null, status:'todo', grade:null, gradeSet:false, honors:false, pf:false };
function examGradeIsSet(){ return EX.status==='done' || EX.gradeSet; }
function uniOpenExam(id){
  var e=id?findExam(id):null;
  if(e){
    if(e.status==='done'){ EX={ id:e.id, status:'done', grade:e.grade!=null?parseInt(e.grade,10):26, gradeSet:true, honors:toBool(e.has_honors), pf:toBool(e.is_pass_fail) }; }
    else { var hasP=e.predicted_grade!=null; EX={ id:e.id, status:'todo', grade:hasP?parseInt(e.predicted_grade,10):null, gradeSet:hasP, honors:hasP?toBool(e.predicted_honors):false, pf:toBool(e.is_pass_fail) }; }
    setV('exName',e.name); setV('exCfu',parseFloat(e.cfu)||0); setV('exYear',e.study_year||'');
    setV('exSsd',e.ssd||''); setV('exNat',e.national_avg!=null?e.national_avg:''); setV('exDate',e.exam_date||'');
    setTxt('exSheetTitle','Modifica esame');
  } else { EX={ id:null, status:'todo', grade:null, gradeSet:false, honors:false, pf:false };
    setV('exName',''); setV('exCfu',6); setV('exYear',''); setV('exSsd',''); setV('exNat',''); setV('exDate','');
    setTxt('exSheetTitle','Nuovo esame'); }
  show('exDeleteBtn', !!EX.id);
  var eg=(e&&e.group_id!=null)?findGroup(e.group_id):null;
  show('exGroupWrap', !!eg); if(eg) setTxt('exGroupName', eg.name+' \u00b7 '+trimNum(eg.cfu)+' CFU'+(eg.group_type==='split'?' (suddiviso)':' (a scelta)'));
  examSyncChips(); openSheet('examSheet');
}
function setV(id,v){ var el=$(id); if(el) el.value=(v==null?'':v); }
function uniExamSetStatus(s){
  EX.status = (s==='done') ? 'done' : 'todo';
  if(EX.status==='done'){
    if(!EX.pf && EX.grade==null){ EX.grade=26; EX.gradeSet=true; }
    if(!$('exDate').value) setV('exDate',todayISO());
  }
  examSyncChips(); }
function uniExamSetPF(pf){ EX.pf=!!pf; if(EX.pf) EX.honors=false; examSyncChips(); }
function uniExamToggleLode(){ if(!examGradeIsSet()) return; if(EX.grade>=30) EX.honors=!EX.honors; examSyncChips(); }
function uniExamGrade(d){
  /* prima pressione: parte sempre da 26 (qualunque sia il verso), poi si muove di ±1 come nel simulatore */
  if(EX.grade==null) EX.grade=26; else EX.grade=clampG(EX.grade+d);
  EX.gradeSet=true; if(EX.grade<30) EX.honors=false; examSyncChips();
}
function uniExamClearGrade(){ if(EX.status==='done') return; EX.grade=null; EX.gradeSet=false; EX.honors=false; examSyncChips(); }
function examSyncChips(){
  toggleChip('chipTodo', EX.status==='todo');
  toggleChip('chipDone', EX.status==='done');
  toggleChip('chipGraded', !EX.pf);
  toggleChip('chipIdo', EX.pf);
  show('exGradeBlock', !EX.pf);
  var big=$('exGradeBig'); if(big){ big.textContent=EX.honors?'30L':(examGradeIsSet()?EX.grade:'\u2013'); big.classList.toggle('lode',EX.honors); }
  show('exLodeChip', !EX.pf && examGradeIsSet() && EX.grade>=30); toggleChip('exLodeChip', EX.honors);
  show('exClearGradeChip', !EX.pf && EX.status==='todo' && EX.gradeSet);
  show('exDateBlock', EX.status==='done');
  setTxt('exGradeHint', EX.status==='done' ? 'Voto conseguito' : 'Voto previsto (per la simulazione)');
}
function toggleChip(id,on){ var el=$(id); if(el) el.classList.toggle('on', !!on); }
function uniSaveExam(){
  var name=(($('exName')&&$('exName').value)||'').trim(); if(!name){ toast('Inserisci il nome dell\'esame'); return; }
  var cfu=parseFloat($('exCfu')?$('exCfu').value:6)||0;
  var year=$('exYear')&&$('exYear').value?parseInt($('exYear').value,10):null;
  var ssd=$('exSsd')?$('exSsd').value.trim():'';
  var natRaw=$('exNat')?$('exNat').value:''; var nat=natRaw!==''?parseFloat(natRaw):null;
  var date=$('exDate')?$('exDate').value:'';
  var payload={ name:name, cfu:cfu, study_year:year||null, status:EX.status, is_pass_fail:EX.pf,
    grade:(EX.pf||EX.status!=='done')?null:(EX.honors?30:EX.grade),
    has_honors:(!EX.pf && EX.status==='done')?EX.honors:false,
    exam_date:(EX.status==='done')?(date||todayISO()):null,
    ssd:ssd||null, national_avg:nat, counts_for_graduation:true };
  if(EX.status==='todo' && !EX.pf){
    /* solo se lo studente ha davvero impostato un voto previsto in questa sessione (o ce l'aveva già) */
    payload.predicted_grade = EX.gradeSet ? EX.grade : null;
    payload.predicted_honors = EX.gradeSet ? EX.honors : false;
  }
  var celebrate=(EX.status==='done' && !EX.pf && (EX.honors || EX.grade>=30));
  var prev=EX.id?findExam(EX.id):null;
  var wasTodoNowDone = !!(prev && prev.status==='todo' && EX.status==='done');
  var gid = wasTodoNowDone ? prev.group_id : null;
  var savedId=EX.id;
  var done=function(res){
    var ok = !!(res && res.success!==false);
    if(!ok){ toast('Salvataggio non riuscito: riprova'); return; }
    if(res.exam){ if(EX.id) replaceExam(res.exam); else STATE.exams.push(res.exam); }
    uniCloseSheets(); afterExamChange(celebrate);
    if(wasTodoNowDone) maybeClearGroup(savedId, gid);
    if(wasTodoNowDone) maybeProposeSplitOverall(gid);
  };
  var fail=function(){ toast('Errore di rete: il salvataggio potrebbe non essere andato a buon fine'); };
  if(EX.id){ payload.id=EX.id; uniPost('update_exam',payload).then(done).catch(fail); }
  else { uniPost('add_exam',payload).then(done).catch(fail); }
}
function applyLocalExam(id,p){ var e=findExam(id); if(!e) return; for(var k in p){ if(p.hasOwnProperty(k)) e[k]=p[k]; } }
function afterExamChange(celebrate){ renderAll(); if(celebrate){ setTimeout(function(){ burst(); toast('🎉 Ottimo voto!'); },200); buzz(20); } }
function uniDeleteExam(){ if(!EX.id){ uniCloseSheets(); return; } if(!confirm('Eliminare questo esame?')) return;
  uniPost('delete_exam',{ id:EX.id }).then(function(){ STATE.exams=STATE.exams.filter(function(e){ return String(e.id)!==String(EX.id); }); uniCloseSheets(); renderAll(); }).catch(function(){ toast('Errore'); }); }

/* ---- Voto rapido ---- */
var QG={ id:null, grade:26, honors:false, pf:false };
function openQuick(e){ QG={ id:e.id, grade:26, honors:false, pf:toBool(e.is_pass_fail) };
  setTxt('gradeSheetName',e.name); qgSync(); openSheet('gradeSheet'); }
function uniQuickGrade(d){ QG.grade=clampG(QG.grade+d); if(QG.grade<30) QG.honors=false; qgSync(); }
function uniQuickLode(){ if(QG.grade>=30) QG.honors=!QG.honors; else { QG.grade=30; QG.honors=true; } qgSync(); }
function uniQuickIdo(){ QG.pf=!QG.pf; if(QG.pf) QG.honors=false; qgSync(); }
function qgSync(){ var big=$('qgBig'); if(big){ big.textContent=QG.pf?'Idoneità':(QG.honors?'30L':QG.grade); big.classList.toggle('lode',QG.honors&&!QG.pf); }
  toggleChip('qgLode', QG.honors); show('qgLode', !QG.pf && QG.grade>=30); toggleChip('qgIdo', QG.pf); }
function uniQuickSave(){ var e=findExam(QG.id); if(!e){ uniCloseSheets(); return; }
  var gid=e.group_id;
  var payload={ id:QG.id, status:'done', is_pass_fail:QG.pf, grade:QG.pf?null:(QG.honors?30:QG.grade), has_honors:QG.pf?false:QG.honors, exam_date:todayISO() };
  var celebrate=!QG.pf && (QG.honors||QG.grade>=30);
  uniPost('update_exam',payload).then(function(res){
    var ok = !!(res && res.success!==false);
    if(!ok){ toast('Salvataggio non riuscito: riprova'); return; }
    if(res.exam) replaceExam(res.exam);
    uniCloseSheets(); afterExamChange(celebrate); maybeClearGroup(QG.id, gid); maybeProposeSplitOverall(gid);
  }).catch(function(){ toast('Errore di rete: il salvataggio potrebbe non essere andato a buon fine'); }); }

/* ---- Obiettivo ---- */
var TG={ val:28 };
function uniOpenTarget(){ var cur=weighted(STATE.exams).avg;
  var start=S().target_average!=null?parseFloat(S().target_average):(cur>0?Math.round((cur+0.3)/0.05)*0.05:28);
  if(start<18)start=18; if(start>30.5)start=30.5; TG.val=Math.round(start*100)/100; tgSync(); openSheet('targetSheet'); }
function uniTargetStep(d){ TG.val=Math.round((TG.val+d*0.05)*100)/100; if(TG.val<18)TG.val=18; if(TG.val>30.5)TG.val=30.5; tgSync(); }
function tgSync(){ var big=$('tgtBig'); if(big) big.textContent=fmt2(TG.val);
  var b=base110(TG.val>30?30:TG.val), hint='\u2248 '+b+'/'+gradeBase()+' base laurea';
  var rf=requiredFuture(TG.val);
  if(rf!=null){ if(rf.feasible) hint+=' \u00b7 ti basta una media di '+fmt2(rf.req)+' nei restanti '+trimNum(rf.remainingCfu)+' CFU';
    else hint+=' \u00b7 servirebbe pi\u00f9 di 30 nei restanti esami'; }
  setTxt('tgtHint',hint); 
  
  var vis = $('tgtVis');
  if(vis) {
      var gb = gradeBase();
      var frTgt = finalRange(TG.val, false);
      var x1 = gradePct(frTgt.min), x2 = gradePct(frTgt.max), topReached = frTgt.max > gb + 0.001;
      
      // margin-bottom:26px serve a dare spazio alle bandierine col voto sopra la barra
      var vh = '<div style="font-size:.65rem; font-weight:800; color:var(--text-2); text-transform:uppercase; margin-bottom:26px; text-align:center; letter-spacing:.5px">Voto di laurea stimato</div>';
      vh += '<div class="thesis-vis" style="margin-top:0"><div class="scale">';
      vh += '<div class="fill' + (topReached ? ' bar-gold' : '') + '" style="left:' + x1.toFixed(2) + '%;width:' + Math.max(1.5, (x2 - x1)).toFixed(2) + '%"></div>';
      vh += thesisFlag(x1, dispFinal(frTgt.min), frTgt.min > gb + 0.001);
      if (Math.abs(x2 - x1) > 7) vh += thesisFlag(x2, dispFinal(frTgt.max), topReached);
      vh += '<div class="tick" style="left:92%"></div></div><div class="marks">' +
            mark(0, '66') + mark(gradePct(88), '88') + mark(92, String(gb)) + mark(100, 'L', true) + '</div></div>';
      vis.innerHTML = vh;
  }  
}
function uniSaveTarget(){ var v=Math.round(TG.val*100)/100;
  uniPost('save_target',{ target_average:v, target_source:'manual' }).then(function(){
    S().target_average=v; S().target_source='manual'; STATE._reached=weighted(STATE.exams).avg>=v;
    uniCloseSheets(); renderHero(); renderObjective(); renderChart(); toast('🎯 Obiettivo impostato: '+fmt2(v)); }).catch(function(){ toast('Errore'); }); }
function uniClearTarget(){ uniPost('save_target',{ target_average:null, target_source:'manual' }).then(function(){
    S().target_average=null; STATE._reached=false; uniCloseSheets(); renderHero(); renderObjective(); renderChart(); toast('Obiettivo rimosso'); }).catch(function(){ toast('Errore'); }); }

/* ---- Bonus ---- */
var BN={ id:null };
function uniOpenBonus(id){ var b=id?findBonus(id):null; BN.id=b?b.id:null;
  setV('bnName',b?b.name:''); setV('bnType',b?b.bonus_type:'on_time'); setV('bnPoints',b?(parseFloat(b.points)||0):1);
  setV('bnThreshold', b&&b.threshold_value!=null?parseFloat(b.threshold_value):3);
  setV('bnCap', b&&capOf(b)!=null?capOf(b):''); show('bnDeleteBtn', !!BN.id); uniBonusTypeChange(); openSheet('bonusSheet'); }
function uniBonusTypeChange(){ var t=$('bnType')?$('bnType').value:'on_time';
  show('bnThrWrap', t==='honors_threshold'); show('bnCapWrap', t==='honors_each');
  setTxt('bnPointsLabel', t==='honors_each'?'Punti per ogni lode':(t==='honors_threshold'?'Punti bonus':'Punti')); bnPreview(); }
function bnPreview(){ var t=$('bnType')?$('bnType').value:'on_time';
  var pts=parseFloat($('bnPoints')?$('bnPoints').value:0)||0, thr=parseFloat($('bnThreshold')?$('bnThreshold').value:0)||0;
  var capRaw=$('bnCap')&&$('bnCap').value!==''?parseFloat($('bnCap').value):0, cap=(capRaw&&capRaw>0)?capRaw:null;
  var lodi=lodiCount(false), txt='';
  if(t==='on_time') txt='Aggiunge +'+trimNum(pts)+' punti alla laurea se ti laurei in corso.';
  else if(t==='fixed') txt='Aggiunge sempre +'+trimNum(pts)+' punti.';
  else if(t==='honors_each'){ var v=pts*lodi; if(cap!=null) v=Math.min(v,cap); txt='+'+trimNum(pts)+' per lode. Con le tue '+lodi+' lodi attuali: +'+trimNum(v)+(cap!=null?(' (max +'+trimNum(cap)+')'):'')+'.'; }
  else if(t==='honors_threshold') txt='+'+trimNum(pts)+' se raggiungi almeno '+trimNum(thr)+' lodi. Attuali: '+lodi+'.';
  else txt='Bonus personalizzato di +'+trimNum(pts)+' punti.';
  setTxt('bnPreview',txt); }
function uniSaveBonus(){ var name=(($('bnName')&&$('bnName').value)||'').trim(); if(!name){ toast('Dai un nome al bonus'); return; }
  var t=$('bnType').value;
  var payload={ name:name, bonus_type:t, points:parseFloat($('bnPoints').value)||0,
    threshold_value: t==='honors_threshold'?(parseFloat($('bnThreshold').value)||0):null,
    cap_points: (t==='honors_each' && $('bnCap').value!=='')?(parseFloat($('bnCap').value)||0):0, is_enabled:true };
  if(BN.id) payload.id=BN.id;
  uniPost(BN.id?'update_bonus':'add_bonus',payload).then(function(res){ if(res&&res.bonuses) STATE.bonuses=res.bonuses;
    uniCloseSheets(); renderHero(); renderObjective(); renderThesisBonus(); toast('Bonus salvato'); }).catch(function(){ toast('Errore'); }); }
function uniDeleteBonus(){ if(!BN.id){ uniCloseSheets(); return; } if(!confirm('Eliminare questo bonus?')) return;
  uniPost('delete_bonus',{ id:BN.id }).then(function(){ STATE.bonuses=STATE.bonuses.filter(function(b){ return String(b.id)!==String(BN.id); });
    uniCloseSheets(); renderHero(); renderObjective(); renderThesisBonus(); }).catch(function(){ toast('Errore'); }); }

/* ---- Impostazioni ---- */
var SET={ accent:null, lode:30 };
function uniOpenSettings(){ var s=S(); SET.accent=s.accent_color||'#0A84FF'; SET.lode=parseFloat(s.honors_value)||30;
  setV('setLabel',s.program_label||''); setV('setTotalCfu',parseFloat(s.total_cfu)||180); setV('setBase',parseFloat(s.grade_base)||110);
  setV('setThMin',parseFloat(s.thesis_min_points)||0); setV('setThMax',parseFloat(s.thesis_max_points)||0);
  var rc=$('setRound'); if(rc) rc.checked=toBool(s.round_half_up);
  setProgDisplay(); renderColorSwatches(); syncLodeChips(); openSheet('settingsSheet'); }
function setProgDisplay(){ var s=S();
  var cat=STATE.catalog&&STATE.catalog.programs?STATE.catalog.programs[s.program_key]:null;
  setTxt('setProgName', s.program_label || (cat?cat.label:'Nessun corso'));
  var fac=STATE.catalog&&STATE.catalog.faculties?STATE.catalog.faculties[s.faculty_key]:null;
  setTxt('setProgIcon', fac?fac.emoji:'🎓');
  setTxt('setProgDesc', cat?(trimNum(cat.total_cfu)+' CFU \u00b7 '+(cat.exams?cat.exams.length:0)+' esami'):'Tocca per scegliere'); }
function uniSetLodeVal(v){ SET.lode=v; syncLodeChips(); }
function syncLodeChips(){ var wrap=$('setLodeVal'); if(!wrap) return; var chips=wrap.querySelectorAll('.chip-tog');
  for(var i=0;i<chips.length;i++){ var val=parseFloat(chips[i].getAttribute('data-val')); chips[i].classList.toggle('on', Math.abs(val-SET.lode)<0.01); } }
function renderColorSwatches(){ var wrap=$('setColors'); if(!wrap) return; var palette=[];
  if(STATE.catalog&&STATE.catalog.faculties){ for(var k in STATE.catalog.faculties){ if(STATE.catalog.faculties.hasOwnProperty(k)){ palette.push({color:STATE.catalog.faculties[k].color,label:STATE.catalog.faculties[k].label}); } } }
  if(!palette.length) palette=[{color:'#0A84FF'},{color:'#34C759'},{color:'#FF375F'},{color:'#FF9F0A'},{color:'#BF5AF2'},{color:'#5AC8FA'},{color:'#FF2D55'},{color:'#5E5CE6'}];
  var h=''; for(var i=0;i<palette.length;i++){ var c=palette[i].color, on=String(c).toLowerCase()===String(SET.accent).toLowerCase();
    h+='<div class="color-dot'+(on?' on':'')+'" style="background:'+c+'" title="'+escAttr(palette[i].label||'')+'" data-color="'+escAttr(c)+'" onclick="uniPickColor(this)"></div>'; }
  wrap.innerHTML=h; }
function uniPickColor(el){ var c=el.getAttribute('data-color'); SET.accent=c;
  var sib=el.parentNode.querySelectorAll('.color-dot'); for(var i=0;i<sib.length;i++) sib[i].classList.remove('on'); el.classList.add('on');
  applyAccent(c, deriveAccent2(c)); }
function uniSaveSettings(){ var rc=$('setRound');
  var payload={ program_label:$('setLabel')?$('setLabel').value.trim():'', total_cfu:parseFloat($('setTotalCfu')?$('setTotalCfu').value:180)||180,
    grade_base:parseFloat($('setBase')?$('setBase').value:110)||110, honors_value:SET.lode,
    thesis_min_points:parseFloat($('setThMin')?$('setThMin').value:0)||0, thesis_max_points:parseFloat($('setThMax')?$('setThMax').value:0)||0,
    round_half_up: rc?rc.checked:false, accent_color:SET.accent };
  uniPost('save_settings',payload).then(function(res){ if(res&&res.settings) STATE.settings=res.settings;
    else { for(var k in payload){ if(payload.hasOwnProperty(k)) STATE.settings[k]=payload[k]; } }
    uniCloseSheets(); applyAccentFromSettings(); refreshHeaderDegree(); renderAll(); toast('Impostazioni salvate'); }).catch(function(){ toast('Errore'); }); }

/* ---- Corso ---- */
var PROG_CACHE=null;
function buildProgList(){
  if(!STATE.catalog || !STATE.catalog.programs) return [];
  var progs=STATE.catalog.programs, facs=STATE.catalog.faculties||{}, out=[];
  for(var key in progs){ if(!progs.hasOwnProperty(key)) continue; var p=progs[key], fac=facs[p.faculty]||{};
    out.push({ key:key, label:p.label, facLabel:fac.label||'', facColor:fac.color||'var(--uni-accent)',
      facEmoji:fac.emoji||'🎓', cfu:p.total_cfu, examCount:p.exams?p.exams.length:0 }); }
  out.sort(function(a,b){ return a.label.localeCompare(b.label,'it',{sensitivity:'base'}); });
  return out;
}
function renderProgList(filter){
  var list=$('progList'); if(!list) return;
  var items=PROG_CACHE||[];
  if(filter){ var f=filter.trim().toLowerCase();
    items=items.filter(function(p){ return p.label.toLowerCase().indexOf(f)>-1 || p.facLabel.toLowerCase().indexOf(f)>-1; }); }
  if(!items.length){ list.innerHTML='<div class="empty" style="padding:22px 10px"><p style="margin:0">Nessun corso trovato.</p></div>'; return; }
  var h=''; for(var i=0;i<items.length;i++){ var p=items[i];
    h+='<li class="prog-opt" onclick="uniPickProgram(\''+escAttr(p.key)+'\')">'+
       '<div class="pi" style="background:'+p.facColor+'">'+p.facEmoji+'</div>'+
       '<div class="pt"><div class="pn">'+esc(p.label)+'</div><div class="pd">'+(p.facLabel?esc(p.facLabel)+' \u00b7 ':'')+trimNum(p.cfu)+' CFU \u00b7 '+p.examCount+' esami</div></div>'+
       '<div class="chev">\u203a</div></li>'; }
  list.innerHTML=h;
}
function uniFilterPrograms(){ renderProgList($('progSearch')?$('progSearch').value:''); }
function uniOpenProgram(){
  var list=$('progList');
  if(!STATE.catalog || !STATE.catalog.programs){ if(list) list.innerHTML='<div class="empty" style="padding:16px"><p style="margin:0">Catalogo non ancora caricato. Riprova tra un istante.</p></div>'; openSheet('programSheet'); return; }
  PROG_CACHE=buildProgList();
  var se=$('progSearch'); if(se) se.value='';
  renderProgList('');
  openSheet('programSheet');
}
function uniPickProgram(key){ var p=STATE.catalog.programs[key]; if(!p) return; var facs=STATE.catalog.faculties||{}, fac=facs[p.faculty]||{};
  var mode='replace';
  if(STATE.exams.length) mode=confirm('Vuoi SOSTITUIRE gli esami attuali con quelli di "'+p.label+'"?\n\nOK = sostituisci \u00b7 Annulla = aggiungi in coda')?'replace':'append';
  var payload={ mode:mode, program_key:key, program_label:p.label, faculty_key:p.faculty,
    total_cfu:p.total_cfu, grade_base:p.grade_base||110, accent_color:fac.color||S().accent_color||'#0A84FF',
    honors_value:(p.defaults&&p.defaults.honors_value)||30, thesis_min_points:(p.thesis&&p.thesis.min)||0, thesis_max_points:(p.thesis&&p.thesis.max)||0,
    exams: p.exams||[] };
  uniPost('import_program',payload).then(function(res){ if(res){ if(res.exams) STATE.exams=res.exams; if(res.groups) STATE.groups=res.groups; if(res.settings) STATE.settings=res.settings; }
    uniCloseSheets(); applyAccentFromSettings(); refreshHeaderDegree(); maybeCreateDefaultBonus(p);
    STATE._reached=(S().target_average!=null)&&(weighted(STATE.exams).avg>=parseFloat(S().target_average)); renderAll(); toast('📚 Corso caricato: '+p.label); }).catch(function(){ toast('Errore nel caricamento del corso'); }); }
function maybeCreateDefaultBonus(p){ if(STATE.bonuses.length) return; var pts=(p&&p.defaults&&p.defaults.on_time_points)?p.defaults.on_time_points:null; if(pts==null) return;
  uniPost('add_bonus',{ name:'Laurea in corso', bonus_type:'on_time', points:pts, is_enabled:false }).then(function(res){ if(res&&res.bonuses){ STATE.bonuses=res.bonuses; renderThesisBonus(); } }).catch(function(){}); }

/* ---- Accento ---- */
function deriveAccent2(c){ if(STATE.catalog&&STATE.catalog.faculties){ for(var k in STATE.catalog.faculties){ var f=STATE.catalog.faculties[k];
      if(f.color && String(f.color).toLowerCase()===String(c).toLowerCase() && f.gradient && f.gradient.length>1) return f.gradient[1]; } } return shade(c,-16); }
function shade(hex,pct){ hex=String(hex).replace('#',''); if(hex.length!==6) return '#5E5CE6';
  var r=parseInt(hex.substr(0,2),16),g=parseInt(hex.substr(2,2),16),b=parseInt(hex.substr(4,2),16);
  r=Math.max(0,Math.min(255,Math.round(r*(100+pct)/100))); g=Math.max(0,Math.min(255,Math.round(g*(100+pct)/100))); b=Math.max(0,Math.min(255,Math.round(b*(100+pct)/100)));
  return '#'+(r<16?'0':'')+r.toString(16)+(g<16?'0':'')+g.toString(16)+(b<16?'0':'')+b.toString(16); }
function applyAccent(c,c2){ var b=document.body; b.style.setProperty('--uni-accent',c); b.style.setProperty('--uni-accent-2',c2||c);
  b.style.setProperty('--uni-grad','linear-gradient(135deg,'+c+','+(c2||c)+')'); }
function applyAccentFromSettings(){ var c=S().accent_color||'#0A84FF'; applyAccent(c, deriveAccent2(c)); }
function refreshHeaderDegree(){ var s=S();
  var name=s.program_label || (STATE.catalog&&STATE.catalog.programs&&STATE.catalog.programs[s.program_key]?STATE.catalog.programs[s.program_key].label:'');
  setTxt('hdrDegreeName', name || 'Imposta corso'); var pill=$('hdrDegree'); if(pill) pill.style.display=STATE.exams.length?'':'none'; }

/* ---- Drag riordino ---- */
var DRAG={ el:null, id:null, startY:0, dy:0, items:[], list:null };
function bindDrag(list){ DRAG.list=list; var handles=list.querySelectorAll('[data-drag]'); for(var i=0;i<handles.length;i++) handles[i].addEventListener('pointerdown', startDrag); }
function startDrag(ev){ ev.preventDefault(); var item=ev.currentTarget.closest('#examList > li'); if(!item) return;
  DRAG.el=item; DRAG.id=item.getAttribute('data-id'); DRAG.startY=ev.clientY; DRAG.dy=0;
  DRAG.items=Array.prototype.slice.call(DRAG.list.children);
  item.classList.add('dragging'); item.style.transition='none'; item.style.zIndex='5';
  document.addEventListener('pointermove', moveDrag); document.addEventListener('pointerup', endDrag); buzz(8); }
function moveDrag(ev){ if(!DRAG.el) return; DRAG.dy=ev.clientY-DRAG.startY; DRAG.el.style.transform='translateY('+DRAG.dy+'px)'; }
function endDrag(){ document.removeEventListener('pointermove', moveDrag); document.removeEventListener('pointerup', endDrag);
  if(!DRAG.el) return; var el=DRAG.el; el.classList.remove('dragging'); el.style.transition=''; el.style.transform=''; el.style.zIndex='';
  var itemH=el.offsetHeight||56, moved=Math.round(DRAG.dy/itemH);
  var order=DRAG.items.map(function(n){ return n.getAttribute('data-id'); });
  var from=order.indexOf(DRAG.id), to=Math.max(0,Math.min(order.length-1, from+moved));
  if(from>-1 && to!==from){ order.splice(to,0,order.splice(from,1)[0]); applyReorder(order); }
  DRAG.el=null; }
/* displayedIds sono id "top-level": un esame singolo (id numerico) oppure un blocco di gruppo
   compattato ("grp-N"), che va riespanso nei suoi esami componenti mantenendo il loro ordine interno */
function applyReorder(displayedIds){
  var tabItems=STATE.exams.filter(function(e){ return e.status===STATE.tab; });
  var others=STATE.exams.filter(function(e){ return e.status!==STATE.tab; });
  var blocks=clusterByGroup(tabItems), byKey={};
  for(var i=0;i<blocks.length;i++){ var b=blocks[i];
    if(b.type==='single') byKey['e'+b.item.id]=[b.item]; else byKey['grp-'+b.gid]=b.items; }
  var reordered=[], usedKeys={}, usedIds={};
  for(var j=0;j<displayedIds.length;j++){ var raw=String(displayedIds[j]);
    var key = raw.indexOf('grp-')===0 ? raw : ('e'+raw);
    if(usedKeys[key]) continue;
    var arr=byKey[key]; if(arr){ usedKeys[key]=true; for(var a=0;a<arr.length;a++){ reordered.push(arr[a]); usedIds[String(arr[a].id)]=true; } } }
  for(var m=0;m<tabItems.length;m++){ if(!usedIds[String(tabItems[m].id)]) reordered.push(tabItems[m]); }
  var merged=reordered.concat(others); for(var n=0;n<merged.length;n++) merged[n].sort_order=n; STATE.exams=merged;
  renderExams(); uniPost('reorder_exams',{ order: merged.map(function(e){ return e.id; }) }).catch(function(){}); }

/* ---- Swipe verso destra per rivelare "elimina" (solo tab "Da sostenere") ---- */
var SWIPE_W=76, SWIPE={ el:null, id:null, startX:0, startY:0, dx:0, active:false, deciding:false, isH:false }, SWIPE_OPEN=null;
function bindSwipe(list){
  var contents=list.querySelectorAll('.swipe-content');
  for(var i=0;i<contents.length;i++) contents[i].addEventListener('pointerdown', swipeStart);
}
function swipeStart(ev){
  if(ev.pointerType==='mouse' && ev.button!==0) return;
  if(ev.target.closest('[data-drag]')) return; // il manico di riordino resta un gesto verticale a parte
  var content=ev.currentTarget, li=content.closest('.exam-item');
  SWIPE={ el:content, id:li?li.getAttribute('data-id'):null, startX:ev.clientX, startY:ev.clientY, dx:0, active:true, deciding:true, isH:false };
  content.style.transition='none';
  document.addEventListener('pointermove', swipeMove);
  document.addEventListener('pointerup', swipeEnd);
}
function swipeMove(ev){
  if(!SWIPE.active) return;
  var dx=ev.clientX-SWIPE.startX, dy=ev.clientY-SWIPE.startY;
  if(SWIPE.deciding){
    if(Math.abs(dx)<6 && Math.abs(dy)<6) return;
    SWIPE.isH = Math.abs(dx) > Math.abs(dy)*1.2; SWIPE.deciding=false;
    if(!SWIPE.isH){ SWIPE.active=false; return; } /* verticale: lascia scorrere la pagina normalmente */
  }
  if(!SWIPE.isH) return;
  ev.preventDefault();
  var base = (SWIPE_OPEN===SWIPE.id) ? SWIPE_W : 0;
  var x=Math.max(0, Math.min(SWIPE_W, base+dx));
  SWIPE.dx=x; SWIPE.el.style.transform='translateX('+x+'px)';
}
function swipeEnd(){
  document.removeEventListener('pointermove', swipeMove); document.removeEventListener('pointerup', swipeEnd);
  if(!SWIPE.active || !SWIPE.isH){ SWIPE.active=false; return; }
  SWIPE.el.style.transition='';
  var open = SWIPE.dx > SWIPE_W*0.5;
  SWIPE.el.style.transform = 'translateX('+(open?SWIPE_W:0)+'px)';
  closeOtherSwipes(open?SWIPE.id:null); SWIPE_OPEN = open?SWIPE.id:null; SWIPE.active=false;
}
function closeOtherSwipes(exceptId){
  var contents=document.querySelectorAll('.swipe-content');
  for(var i=0;i<contents.length;i++){ var li=contents[i].closest('.exam-item'); if(!li) continue;
    if(li.getAttribute('data-id')!==exceptId){ contents[i].style.transition=''; contents[i].style.transform='translateX(0)'; } }
  if(exceptId==null) SWIPE_OPEN=null;
}
/* la prima riga toccata mentre un'altra è aperta la richiude, senza attivare il tap sottostante */
function bindSwipeListGuard(list){
  list.addEventListener('click', function(ev){
    if(SWIPE_OPEN==null) return;
    if(ev.target.closest('.swipe-del')) return;
    ev.preventDefault(); ev.stopPropagation(); closeOtherSwipes(null);
  }, true);
}
function uniSwipeDelete(id,ev){ if(ev){ ev.preventDefault(); ev.stopPropagation(); }
  var e=findExam(id); if(!e) return;
  if(!confirm('Eliminare "'+e.name+'"?')) return;
  uniPost('delete_exam',{ id:id }).then(function(){
    STATE.exams=STATE.exams.filter(function(x){ return String(x.id)!==String(id); });
    SWIPE_OPEN=null; renderAll(); }).catch(function(){ toast('Errore'); });
}

/* ---- Confetti ---- */
function burst(){ if(prefersReduced()) return; var cv=$('uniConfetti'); if(!cv || !cv.getContext) return;
  var ctx=cv.getContext('2d'); if(!ctx) return; var W=cv.width=window.innerWidth, H=cv.height=window.innerHeight; cv.style.display='block';
  var colors=['#FF375F','#FF9F0A','#FFD60A','#34C759','#0A84FF','#BF5AF2','#5AC8FA']; var parts=[], cx=W/2, cy=H*0.28;
  for(var i=0;i<90;i++){ var ang=Math.random()*Math.PI*2, sp=4+Math.random()*7;
    parts.push({ x:cx, y:cy, vx:Math.cos(ang)*sp, vy:Math.sin(ang)*sp-4, g:0.18+Math.random()*0.1, c:colors[i%colors.length], s:5+Math.random()*6, rot:Math.random()*6.28, vr:(Math.random()-0.5)*0.4 }); }
  buzz(25); var start=performance.now();
  function frame(now){ var t=now-start; ctx.clearRect(0,0,W,H); var alive=false;
    for(var i=0;i<parts.length;i++){ var p=parts[i]; p.vy+=p.g; p.x+=p.vx; p.y+=p.vy; p.rot+=p.vr; if(p.y<H+40) alive=true;
      var alpha=Math.max(0,1-t/1800); ctx.save(); ctx.globalAlpha=alpha; ctx.translate(p.x,p.y); ctx.rotate(p.rot); ctx.fillStyle=p.c; ctx.fillRect(-p.s/2,-p.s/2,p.s,p.s*0.6); ctx.restore(); }
    if(alive && t<1900) requestAnimationFrame(frame); else { ctx.clearRect(0,0,W,H); cv.style.display='none'; } }
  requestAnimationFrame(frame); }

/* ---- Init ---- */
function init(){ STATE.tab='todo'; STATE.selecting=false; STATE.selected={}; STATE.selectedGroups={};
  var tgt=S().target_average!=null?parseFloat(S().target_average):null; STATE._reached=tgt!=null && weighted(STATE.exams).avg>=tgt;
  applyAccentFromSettings(); refreshHeaderDegree(); renderAll();
  var pj=$('projToggle'); if(pj) pj.checked=STATE.showProjection;
  fetch('exams_catalog.json',{cache:'no-store'}).then(function(r){ return r.json(); }).then(function(j){ STATE.catalog=j; applyAccentFromSettings(); refreshHeaderDegree(); setProgDisplay(); renderColorSwatches(); }).catch(function(){});
  ['bnPoints','bnThreshold','bnCap'].forEach(function(id){ var el=$(id); if(el) el.addEventListener('input', bnPreview); });
  window.addEventListener('resize', function(){ positionSeg(); renderChart(); });
  var el=$('examList'); if(el) bindSwipeListGuard(el);
  preventDoubleTapZoom();
}
/* il doppio tap per zoomare va disattivato: touch-action:manipulation e user-scalable=no non bastano
   in modo affidabile su tutte le versioni di iOS Safari, quindi si blocca anche via JS il secondo
   tap ravvicinato (stessa zona, entro ~350ms), che è ciò che il browser interpreta come "doppio tap". */
function preventDoubleTapZoom(){
  var lastT=0, lastX=0, lastY=0;
  document.addEventListener('touchend', function(ev){
    var now=Date.now(), t=ev.changedTouches && ev.changedTouches[0];
    var x=t?t.clientX:0, y=t?t.clientY:0;
    if(now-lastT<=350 && Math.abs(x-lastX)<40 && Math.abs(y-lastY)<40) ev.preventDefault();
    lastT=now; lastX=x; lastY=y;
  }, { passive:false });
}
if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init); else init();
</script>
</body>
</html>