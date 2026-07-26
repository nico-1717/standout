<?php
require_once 'config.php';
require_once 'auth_check.php'; // 1. Protezione con login obbligatorio

$current_user_id = $utente_corrente['id']; // 2. ID Utente sicuro dal server

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita."); }

// Mappa riutilizzabile BLINDATA: incrocia i dati solo con le categorie possedute dall'utente
$CCMAP = "(
    SELECT i.id AS card_id, i.category_id 
    FROM fc_images i 
    JOIN fc_categories c ON i.category_id = c.id 
    WHERE c.user_id = $current_user_id
    UNION ALL
    SELECT -cc.id_child AS card_id, fi.category_id 
    FROM fc_child_cards cc 
    JOIN fc_images fi ON cc.parent_id = fi.id 
    JOIN fc_categories c2 ON fi.category_id = c2.id 
    WHERE c2.user_id = $current_user_id
) AS ccmap";

// ==========================================
// AJAX: SALVA OBIETTIVO GIORNALIERO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_goal') {
    header('Content-Type: application/json');
    $uid = $current_user_id; // 3. Sicurezza: ignoriamo l'input del client e forziamo l'utente loggato
    $goal = max(1, intval($_POST['daily_goal']));
    $ok = @mysqli_query($conn, "INSERT INTO fc_user_goals (user_id, daily_goal) VALUES ($uid, $goal) ON DUPLICATE KEY UPDATE daily_goal = $goal");
    echo json_encode(['ok' => (bool)$ok, 'daily_goal' => $goal]);
    exit;
}

function badgeIconSvg($type) {
    switch ($type) {
        case 'flame':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 17a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7.5 7.5 0 1 1-15 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>';
        case 'book':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        case 'trophy':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3a2 2 0 0 1-2 4M7 5H4a2 2 0 0 0 2 4"/></svg>';
        case 'star':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        default:
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/></svg>';
    }
}

// ==========================================
// ATTIVITA' GIORNALIERA (storia completa)
// ==========================================
$dailyData = [];
$dq = mysqli_query($conn, "SELECT DATE(gp.play_date) AS d, COUNT(*) AS played, SUM(gp.user_answer='Correct') AS correct
    FROM fc_gameplays gp JOIN games gm ON gp.game_id = gm.id
    WHERE gm.user_id = $current_user_id AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
    GROUP BY d ORDER BY d ASC");
while ($row = mysqli_fetch_assoc($dq)) {
    $dailyData[$row['d']] = ['played' => (int)$row['played'], 'correct' => (int)$row['correct']];
}
$totalPlayedAllTime = 0;
foreach ($dailyData as $dd) { $totalPlayedAllTime += $dd['played']; }

// ==========================================
// DISTRIBUZIONE ORARIA / GIORNO SETTIMANA (0=Lun)
// ==========================================
$hourData = array_fill(0, 24, 0);
$hq = mysqli_query($conn, "SELECT HOUR(gp.play_date) AS h, COUNT(*) AS c
    FROM fc_gameplays gp JOIN games gm ON gp.game_id = gm.id
    WHERE gm.user_id = $current_user_id AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
    GROUP BY h");
while ($row = mysqli_fetch_assoc($hq)) { $hourData[(int)$row['h']] = (int)$row['c']; }

$weekdayData = array_fill(0, 7, 0);
$wq = mysqli_query($conn, "SELECT WEEKDAY(gp.play_date) AS w, COUNT(*) AS c
    FROM fc_gameplays gp JOIN games gm ON gp.game_id = gm.id
    WHERE gm.user_id = $current_user_id AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
    GROUP BY w");
while ($row = mysqli_fetch_assoc($wq)) { $weekdayData[(int)$row['w']] = (int)$row['c']; }

// ==========================================
// CATEGORIE (albero completo)
// ==========================================
$categoriesFlat = [];
$cq = mysqli_query($conn, "SELECT id, name, parent_id, color FROM fc_categories WHERE user_id = $current_user_id ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($cq)) { $categoriesFlat[] = $row; }
$macro_cats = array_values(array_filter($categoriesFlat, function($c){ return $c['parent_id'] == 0; }));

// ==========================================
// GIOCATE RISOLTE PER CATEGORIA — fonte unica per tutte le statistiche di categoria
// Risoluzione a cascata per ogni singola carta giocata:
//   1) categoria reale della carta (fc_images/fc_child_cards) via ccmap
//   2) se la partita è mono-categoria, la categoria di quella partita (game_categories)
//   3) come ultima spiaggia, il nome categoria salvato sulla partita (compatibilità storica)
// Questo evita che carte "padre" o partite più vecchie spariscano dalle statistiche per
// categoria pur restando contate nei totali globali.
// ==========================================
$resolvedPlays = [];
$rpq = mysqli_query($conn, "SELECT gp.fc_image_id AS card, DATE(gp.play_date) AS d, gp.user_answer AS ans,
        COALESCE(
            ccmap.category_id,
            gc_single.category_id
        ) AS cat_id
    FROM fc_gameplays gp
    JOIN games gm ON gp.game_id = gm.id
    LEFT JOIN $CCMAP ON gp.fc_image_id = ccmap.card_id
    LEFT JOIN (
        SELECT game_id, MIN(category_id) AS category_id FROM game_categories GROUP BY game_id HAVING COUNT(*) = 1
    ) gc_single ON gc_single.game_id = gp.game_id
    WHERE gm.user_id = $current_user_id AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
    ORDER BY gp.fc_image_id ASC, gp.play_date ASC, gp.id ASC");
while ($row = mysqli_fetch_assoc($rpq)) {
    $resolvedPlays[] = [
        'card' => (int)$row['card'],
        'd' => $row['d'],
        'ans' => $row['ans'],
        'cat' => $row['cat_id'] !== null ? (int)$row['cat_id'] : null,
    ];
}

$catDailyData = [];
$catDailyMap = [];
foreach ($resolvedPlays as $p) {
    if ($p['cat'] === null) continue;
    $key = $p['cat'] . '|' . $p['d'];
    if (!isset($catDailyMap[$key])) { $catDailyMap[$key] = ['cat' => $p['cat'], 'd' => $p['d'], 'played' => 0, 'correct' => 0]; }
    $catDailyMap[$key]['played']++;
    if ($p['ans'] === 'Correct') { $catDailyMap[$key]['correct']++; }
}
$catDailyData = array_values($catDailyMap);

// ==========================================
// CARTE TOTALI / IMPARATE PER CATEGORIA
// ==========================================
$catCardTotals = [];
$ctq = mysqli_query($conn, "SELECT category_id, COUNT(*) AS total FROM $CCMAP GROUP BY category_id");
while ($row = mysqli_fetch_assoc($ctq)) { $catCardTotals[(int)$row['category_id']] = (int)$row['total']; }

$catLearnt = [];
$clq = mysqli_query($conn, "SELECT ccmap.category_id AS cat_id, COUNT(DISTINCT ulf.fc_image_id) AS learnt
    FROM user_learnt_fc ulf JOIN $CCMAP ON ulf.fc_image_id = ccmap.card_id
    WHERE ulf.user_id = $current_user_id GROUP BY ccmap.category_id");
while ($row = mysqli_fetch_assoc($clq)) { $catLearnt[(int)$row['cat_id']] = (int)$row['learnt']; }

// ==========================================
// STORICO APPRENDIMENTO GIORNALIERO PER CATEGORIA (per previsioni scoped al ramo)
// ==========================================
$catLearntDaily = [];
$cldq = mysqli_query($conn, "SELECT ccmap.category_id AS cat_id, ulf.learnt_date AS d, COUNT(*) AS c
    FROM user_learnt_fc ulf JOIN $CCMAP ON ulf.fc_image_id = ccmap.card_id
    WHERE ulf.user_id = $current_user_id GROUP BY ccmap.category_id, d");
while ($row = mysqli_fetch_assoc($cldq)) {
    $catLearntDaily[] = ['cat' => (int)$row['cat_id'], 'd' => $row['d'], 'count' => (int)$row['c']];
}

// ==========================================
// TEMPO DI GIOCO — integrazione durata_sessione (introdotta dopo la creazione iniziale
// di questa pagina, come le altre statistiche legate al tempo qui sotto).
// ==========================================

// 1) Risoluzione CATEGORIA per PARTITA (non per singola carta): stessa cascata già
//    collaudata in $resolvedPlays (carta diretta via ccmap > game_categories singola >
//    nome partita), ma aggregata per partita intera, perché durata_sessione registra il
//    tempo per l'intera partita e non per singola carta.
$catIdByName = [];
foreach ($categoriesFlat as $c) {
    $nm = $c['name'];
    if (!isset($catIdByName[$nm]) || (int)$c['id'] < $catIdByName[$nm]) { $catIdByName[$nm] = (int)$c['id']; }
}
$gamePartitaCat = [];
$gpcq = mysqli_query($conn, "SELECT gp.game_id,
        COUNT(DISTINCT ccmap.category_id) AS n_distinct_direct,
        MIN(ccmap.category_id) AS direct_cat,
        MIN(gc_single.category_id) AS single_cat
    FROM fc_gameplays gp
    JOIN games gm ON gp.game_id = gm.id
    LEFT JOIN $CCMAP ON gp.fc_image_id = ccmap.card_id
    LEFT JOIN (
        SELECT game_id, MIN(category_id) AS category_id FROM game_categories GROUP BY game_id HAVING COUNT(*) = 1
    ) gc_single ON gc_single.game_id = gp.game_id
    WHERE gm.user_id = $current_user_id
    GROUP BY gp.game_id");
while ($row = mysqli_fetch_assoc($gpcq)) {
    $pid = (int)$row['game_id'];
    $cat = null;
    if ((int)$row['n_distinct_direct'] === 1 && $row['direct_cat'] !== null) { $cat = (int)$row['direct_cat']; }
    elseif ($row['single_cat'] !== null) { $cat = (int)$row['single_cat']; }
    if ($cat !== null) { $gamePartitaCat[$pid] = $cat; }
}

// 2) Tempo grezzo per partita+giorno (durata_sessione può avere più righe per partita:
//    sessioni riprese più volte), e conteggio carte per partita, tenuti SEPARATI per non
//    gonfiare le somme con un JOIN diretto fra le due tabelle a granularità diversa.
$gameTimeByDate = []; // idpartita => [ 'YYYY-MM-DD' => secondi ]
$gameTimeTotal = [];  // idpartita => secondi totali
$dtq = mysqli_query($conn, "SELECT ds.game_id, DATE(ds.start_time) AS d, SUM(ds.duration_seconds) AS secs
    FROM session_durations ds JOIN games gm ON gm.id = ds.game_id
    WHERE gm.user_id = $current_user_id GROUP BY ds.game_id, d");
while ($row = mysqli_fetch_assoc($dtq)) {
    $gid = (int)$row['game_id']; $secs = (int)$row['secs'];
    if (!isset($gameTimeByDate[$gid])) $gameTimeByDate[$gid] = [];
    $gameTimeByDate[$gid][$row['d']] = ($gameTimeByDate[$gid][$row['d']] ?? 0) + $secs;
    $gameTimeTotal[$gid] = ($gameTimeTotal[$gid] ?? 0) + $secs;
}
$gameCardCount = []; // idpartita => numero di carte giocate
$gccq = mysqli_query($conn, "SELECT gp.game_id, COUNT(*) AS n FROM fc_gameplays gp JOIN games gm ON gp.game_id = gm.id WHERE gm.user_id = $current_user_id GROUP BY gp.game_id");
while ($row = mysqli_fetch_assoc($gccq)) { $gameCardCount[(int)$row['game_id']] = (int)$row['n']; }

// 3) Combiniamo tutto: tempo per categoria/giorno, tempo globale/giorno, e "punti di
//    velocità" (secondi per carta di ogni singola partita) per i trend di miglioramento.
//    I punti di velocità con meno di 2 carte o oltre 10 min/carta sono scartati: sono
//    quasi certamente partite lasciate aperte inattive, e distorcerebbero le previsioni.
$catTimeDailyMap = [];
$dailyTimeData = [];
$catSpeedPoints = [];
$totalTimeAllTime = 0;
$catTimeTotal = [];
foreach ($gameTimeByDate as $gid => $byDate) {
    $cat = $gamePartitaCat[$gid] ?? null;
    foreach ($byDate as $d => $secs) {
        $totalTimeAllTime += $secs;
        $dailyTimeData[$d] = ($dailyTimeData[$d] ?? 0) + $secs;
        if ($cat !== null) {
            $key = $cat . '|' . $d;
            if (!isset($catTimeDailyMap[$key])) { $catTimeDailyMap[$key] = ['cat' => $cat, 'd' => $d, 'seconds' => 0]; }
            $catTimeDailyMap[$key]['seconds'] += $secs;
            $catTimeTotal[$cat] = ($catTimeTotal[$cat] ?? 0) + $secs;
        }
    }
    if ($cat !== null && !empty($gameCardCount[$gid]) && !empty($gameTimeTotal[$gid])) {
        $cards = $gameCardCount[$gid];
        $secPerCard = $gameTimeTotal[$gid] / max(1, $cards);
        if ($cards >= 2 && $secPerCard > 0 && $secPerCard < 600) {
            $catSpeedPoints[] = ['cat' => $cat, 'd' => min(array_keys($byDate)), 'spc' => round($secPerCard, 1), 'cards' => $cards];
        }
    }
}
$catTimeDaily = array_values($catTimeDailyMap);
usort($catSpeedPoints, function($a, $b){ return strcmp($a['d'], $b['d']); });

// 4) Dimensione "tipica" di una partita per categoria (per stimare la durata di una
//    prossima partita futura a partire dalla velocità media/attuale).
$catGameSizes = [];
foreach ($gamePartitaCat as $gid => $cat) {
    if (!empty($gameCardCount[$gid])) { $catGameSizes[$cat][] = $gameCardCount[$gid]; }
}
$catAvgGameSize = [];
foreach ($catGameSizes as $cat => $sizes) { $catAvgGameSize[$cat] = round(array_sum($sizes) / count($sizes)); }

// 5) Tempo dei PROGETTI collegati (progetto_categoria_fc, 1 a 1) — se una materia è
//    collegata a un progetto pomodoro, integriamo anche quel tempo per un quadro
//    unificato studio-flashcard + focus-progetto. Avvolto in @ + controllo esistenza:
//    se la tabella non è stata ancora creata, la pagina degrada senza errori.
$catProjectLink = []; // cat => ['progetto_id'=>, 'progetto_nome'=>, 'pomodoro_secondi'=>]
$plq = @mysqli_query($conn, "SELECT pcf.fc_category_id, pcf.progetto_id, p.name,
        COALESCE((SELECT SUM(ps.duration_seconds) FROM pomodoro_sessions ps WHERE ps.progetto_id = pcf.progetto_id AND ps.user_id = $current_user_id), 0) AS pomo_secs
    FROM project_fc_categories pcf
    JOIN projects p ON p.id = pcf.progetto_id AND p.user_id = $current_user_id
    JOIN fc_categories c ON c.id = pcf.fc_category_id AND c.user_id = $current_user_id");
if ($plq) {
    while ($row = mysqli_fetch_assoc($plq)) {
        $catProjectLink[(int)$row['fc_category_id']] = [
            'progetto_id' => (int)$row['progetto_id'],
            'progetto_nome' => $row['name'],
            'pomodoro_secondi' => (int)$row['pomo_secs'],
        ];
    }
}

function fc_fmt_durata_stats($secondi) {
    // riservato per eventuale output PHP diretto in futuro — al momento tutta la
    // formattazione delle durate avviene lato client con fmtDuration(), come il resto
    // di questa pagina (i numeri dipendono dal periodo scelto, quindi si calcolano in JS)
    $secondi = max(0, (int)$secondi);
    $h = intdiv($secondi, 3600);
    $m = intdiv($secondi % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm';
    return '< 1m';
}

// ==========================================
// STATISTICHE PER SINGOLA CARTA (per "carte più sbagliate")
// ==========================================
$cardStatsMap = [];
foreach ($resolvedPlays as $p) {
    if ($p['cat'] === null) continue;
    $key = $p['cat'] . '|' . $p['card'];
    if (!isset($cardStatsMap[$key])) { $cardStatsMap[$key] = ['cat' => $p['cat'], 'card' => $p['card'], 'played' => 0, 'correct' => 0]; }
    $cardStatsMap[$key]['played']++;
    if ($p['ans'] === 'Correct') { $cardStatsMap[$key]['correct']++; }
}
$cardStats = array_values($cardStatsMap);

// Recupera testo/immagine solo delle carte effettivamente giocate da questo utente
$cardInfo = [];
$posIds = []; $negIds = [];
foreach ($cardStats as $cs) {
    if ($cs['card'] > 0) { $posIds[$cs['card']] = true; } else { $negIds[abs($cs['card'])] = true; }
}
if (!empty($posIds)) {
    $idsStr = implode(',', array_map('intval', array_keys($posIds)));
    $res = mysqli_query($conn, "SELECT id, question_desc, question_image FROM fc_images WHERE id IN ($idsStr)");
    while ($row = mysqli_fetch_assoc($res)) {
        $cardInfo[(int)$row['id']] = ['desc' => $row['question_desc'], 'img' => $row['question_image']];
    }
}
if (!empty($negIds)) {
    $absIdsStr = implode(',', array_map('intval', array_keys($negIds)));
    $res = mysqli_query($conn, "SELECT id_child, question_desc, question_image FROM fc_child_cards WHERE id_child IN ($absIdsStr)");
    while ($row = mysqli_fetch_assoc($res)) {
        $cardInfo[-1 * (int)$row['id_child']] = ['desc' => $row['question_desc'], 'img' => $row['question_image']];
    }
}

// ==========================================
// MIGLIORAMENTO DOPO UN ERRORE (per categoria esatta)
// Percentuale di volte in cui, dopo aver sbagliato una carta, il tentativo
// immediatamente successivo sulla STESSA carta è corretto. $resolvedPlays è già
// ordinato per carta e poi cronologicamente, quindi basta un'unica scansione.
// ==========================================
$retryStatsByCategory = [];
$lastAnswerByCard = [];
foreach ($resolvedPlays as $p) {
    if (isset($lastAnswerByCard[$p['card']]) && $lastAnswerByCard[$p['card']] === 'Wrong' && $p['cat'] !== null) {
        if (!isset($retryStatsByCategory[$p['cat']])) { $retryStatsByCategory[$p['cat']] = ['opportunities' => 0, 'fixed' => 0]; }
        $retryStatsByCategory[$p['cat']]['opportunities']++;
        if ($p['ans'] === 'Correct') { $retryStatsByCategory[$p['cat']]['fixed']++; }
    }
    $lastAnswerByCard[$p['card']] = $p['ans'];
}

// ==========================================
// MASTERY COMPLESSIVA
// ==========================================
$totalCardsRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM (
    SELECT i.id FROM fc_images i JOIN fc_categories c ON i.category_id = c.id WHERE i.is_father = 0 AND c.user_id = $current_user_id
    UNION ALL
    SELECT cc.id_child FROM fc_child_cards cc JOIN fc_images fi ON cc.parent_id = fi.id JOIN fc_categories c2 ON fi.category_id = c2.id WHERE c2.user_id = $current_user_id
) t");
$totalCards = (int)mysqli_fetch_assoc($totalCardsRes)['c'];

$learntCardsRes = mysqli_query($conn, "SELECT COUNT(DISTINCT fc_image_id) AS c FROM user_learnt_fc WHERE user_id = $current_user_id");
$learntCards = (int)mysqli_fetch_assoc($learntCardsRes)['c'];

$attemptedNotLearntRes = mysqli_query($conn, "SELECT COUNT(DISTINCT gp.fc_image_id) AS c
    FROM fc_gameplays gp JOIN games gm ON gp.game_id = gm.id
    WHERE gm.user_id = $current_user_id AND gp.user_answer IN ('Correct','Wrong')
    AND gp.fc_image_id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $current_user_id)");
$attemptedNotLearnt = (int)mysqli_fetch_assoc($attemptedNotLearntRes)['c'];
$neverTried = max(0, $totalCards - $learntCards - $attemptedNotLearnt);

// ==========================================
// ANDAMENTO PUNTEGGIO PARTITE
// ==========================================
$scoreTrend = [];
$sq = mysqli_query($conn, "SELECT game_date, score, type FROM games WHERE user_id = $current_user_id AND score IS NOT NULL ORDER BY game_date ASC");
while ($row = mysqli_fetch_assoc($sq)) {
    $scoreTrend[] = ['date' => $row['game_date'], 'score' => (float)$row['score'], 'type' => $row['type'], 'cat' => null];
}

// ==========================================
// STORICO APPRENDIMENTO CUMULATIVO
// ==========================================
$learntTrend = [];
$ltq = mysqli_query($conn, "SELECT learnt_date AS d, COUNT(*) AS c FROM user_learnt_fc WHERE user_id = $current_user_id GROUP BY d ORDER BY d ASC");
while ($row = mysqli_fetch_assoc($ltq)) { $learntTrend[] = ['date' => $row['d'], 'count' => (int)$row['c']]; }

// ==========================================
// STREAK
// ==========================================
$today = new DateTime('today');
$playedDates = array_keys(array_filter($dailyData, function($v){ return $v['played'] > 0; }));
sort($playedDates);
$bestStreak = 0; $run = 0; $prevDate = null;
foreach ($playedDates as $d) {
    $dateObj = new DateTime($d);
    if ($prevDate !== null && (int)$prevDate->diff($dateObj)->format('%a') == 1) {
        $run++;
    } else {
        $run = 1;
    }
    $bestStreak = max($bestStreak, $run);
    $prevDate = $dateObj;
}
$currentStreak = 0;
if (!empty($playedDates)) {
    $lastPlayed = new DateTime(end($playedDates));
    $diffToday = (int)$today->diff($lastPlayed)->format('%r%a');
    if ($diffToday === 0 || $diffToday === -1) {
        $currentStreak = $run;
    }
}
$nextMilestones = [3, 7, 14, 30, 60, 100, 200, 365];
$nextMilestone = null;
foreach ($nextMilestones as $m) { if ($m > $currentStreak) { $nextMilestone = $m; break; } }

// ==========================================
// PREVISIONE
// ==========================================
$forecastWeeks = null; $forecastDate = null;
if ($learntCards > 0 && count($learntTrend) >= 2 && $totalCards > $learntCards) {
    $firstDate = new DateTime($learntTrend[0]['date']);
    $lastDate = new DateTime(end($learntTrend)['date']);
    $daysSpan = max(1, (int)$firstDate->diff($lastDate)->format('%a'));
    $ratePerWeek = ($learntCards / $daysSpan) * 7;
    $remaining = $totalCards - $learntCards;
    if ($ratePerWeek > 0.05) {
        $weeksLeft = $remaining / $ratePerWeek;
        $forecastWeeks = round($weeksLeft, 1);
        $forecastDate = (clone $today)->modify('+' . round($weeksLeft * 7) . ' days')->format('d/m/Y');
    }
}

// ==========================================
// OBIETTIVO GIORNALIERO
// ==========================================
$dailyGoal = 20;
$goalRes = @mysqli_query($conn, "SELECT daily_goal FROM fc_user_goals WHERE user_id = $current_user_id");
if ($goalRes && mysqli_num_rows($goalRes) > 0) {
    $dailyGoal = (int)mysqli_fetch_assoc($goalRes)['daily_goal'];
}
$todayStr = $today->format('Y-m-d');
$todayPlayed = isset($dailyData[$todayStr]) ? $dailyData[$todayStr]['played'] : 0;


// ==========================================
// BADGE / TRAGUARDI
// ==========================================
$anyCategoryComplete = false;
foreach ($catCardTotals as $cid => $tot) {
    if ($tot > 0 && isset($catLearnt[$cid]) && $catLearnt[$cid] >= $tot) { $anyCategoryComplete = true; break; }
}
$badges = [
    ['type' => 'target', 'label' => '10 carte giocate', 'done' => $totalPlayedAllTime >= 10],
    ['type' => 'flame', 'label' => 'Streak di 3 giorni', 'done' => $bestStreak >= 3],
    ['type' => 'flame', 'label' => 'Streak di 7 giorni', 'done' => $bestStreak >= 7],
    ['type' => 'flame', 'label' => 'Streak di 30 giorni', 'done' => $bestStreak >= 30],
    ['type' => 'book', 'label' => '50 carte imparate', 'done' => $learntCards >= 50],
    ['type' => 'book', 'label' => '100 carte imparate', 'done' => $learntCards >= 100],
    ['type' => 'trophy', 'label' => 'Categoria completata', 'done' => $anyCategoryComplete],
    ['type' => 'target', 'label' => '500 carte giocate', 'done' => $totalPlayedAllTime >= 500],
    ['type' => 'star', 'label' => '1000 carte giocate', 'done' => $totalPlayedAllTime >= 1000],
];

// ==========================================
// DEBUG TEMPORANEO — vista diagnostica riga per riga
// Visitare la pagina aggiungendo &debug=1 all'URL per vedere, per ciascuna delle
// ultime giocate, esattamente come viene (o non viene) risolta la categoria e da
// quale fonte (carta diretta / partita mono-categoria / nome partita).
// Da rimuovere una volta individuata la causa del problema.
// ==========================================
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';
$debugRows = [];
$debugCats = [];
if ($debugMode) {
    $dbgQ = mysqli_query($conn, "SELECT
            gp.id AS idgioco, gp.game_id AS idpartita, gp.fc_image_id AS idimmagine,
            gp.play_date AS play_date, gp.user_answer AS user_answer,
            gm.type AS game_type, gm.parent_id AS game_parent_id,
            ccmap.category_id AS ccmap_cat,
            fcc.name AS ccmap_cat_name, fcc.parent_id AS ccmap_cat_parent,
            gc_single.category_id AS gc_single_cat,
            gc_all.cats AS gc_all_cats
        FROM fc_gameplays gp
        JOIN games gm ON gp.game_id = gm.id
        LEFT JOIN $CCMAP ON gp.fc_image_id = ccmap.card_id
        LEFT JOIN fc_categories fcc ON fcc.id = ccmap.category_id
        LEFT JOIN (
            SELECT game_id, MIN(category_id) AS category_id FROM game_categories GROUP BY game_id HAVING COUNT(*) = 1
        ) gc_single ON gc_single.game_id = gp.game_id
        LEFT JOIN (
            SELECT game_id, GROUP_CONCAT(category_id ORDER BY category_id) AS cats FROM game_categories GROUP BY game_id
        ) gc_all ON gc_all.game_id = gp.game_id
        WHERE gm.user_id = $current_user_id
        ORDER BY gp.play_date DESC, gp.id DESC
        LIMIT 30");
    while ($row = mysqli_fetch_assoc($dbgQ)) { $debugRows[] = $row; }

    // Dump completo dell'albero categorie così com'è ORA, per verificare a mano
    // se una categoria è davvero dentro il ramo atteso oppure è stata spostata.
    $dcq = mysqli_query($conn, "SELECT id, name, parent_id, color FROM fc_categories ORDER BY id ASC");
    while ($row = mysqli_fetch_assoc($dcq)) { $debugCats[] = $row; }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<title>Statistiche · Flashcards</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="fc_parser.js"></script>
<style>
/* ================================================================
   DESIGN SYSTEM — coerente con categories_fc.php / game_fc.php
   ================================================================ */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6; --yellow:#FFCC00;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);
    --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12);
    --teal-soft:rgba(48,176,199,.14); --indigo-soft:rgba(88,86,214,.12);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px; --r-btn:15px;
    --spring:cubic-bezier(.32,.72,0,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
        --teal:#40C8E0; --pink:#FF375F; --indigo:#5E5CE6; --yellow:#FFD60A;
        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);
        --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18);
        --teal-soft:rgba(64,200,224,.18); --indigo-soft:rgba(94,92,230,.18);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; }
body{
    margin:0; min-height:100vh;
    background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.4; -webkit-font-smoothing:antialiased;
    padding-bottom:calc(24px + var(--safe-bottom));
}
a{ color:inherit; } button{ font-family:inherit; }
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }

@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }
@keyframes rowIn{ from{ opacity:0; transform:translateY(-6px); } to{ opacity:1; transform:none; } }
@keyframes popIn{ from{ opacity:0; transform:scale(.7); } to{ opacity:1; transform:scale(1); } }

/* ---------- Header ---------- */
.app-header{
    position:sticky; top:0; z-index:100;
    padding:calc(var(--safe-top) + 12px) 16px 12px;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-bottom:.5px solid var(--hairline);
}
.hdr-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
.hdr-title{ font-size:28px; font-weight:800; letter-spacing:-.02em; }
.user-pills{ display:flex; gap:6px; }
.user-pill{
    width:38px; height:38px; border-radius:50%; border:none; cursor:pointer;
    background:var(--surface-2); color:var(--text-2); font-weight:800; font-size:15px;
    display:flex; align-items:center; justify-content:center;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .16s var(--spring), background .2s, color .2s;
}
.user-pill.active{ background:linear-gradient(135deg, var(--blue), var(--indigo)); color:#fff; transform:scale(1.08); box-shadow:0 6px 14px -4px rgba(10,132,255,.55); }
.user-pill:active{ transform:scale(.9); }

.page{ max-width:640px; margin:0 auto; padding:14px 14px calc(28px + var(--safe-bottom)); }

/* ---------- Segmented control (riutilizzato ovunque) ---------- */
.segmented{
    position:relative; display:flex; background:var(--surface-2); border-radius:12px; padding:3px;
    box-shadow:inset 0 0 0 .5px var(--hairline);
}
.segmented input{ position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.segmented label{
    position:relative; z-index:2; flex:1; text-align:center; padding:9px 4px;
    font-size:13.5px; font-weight:700; color:var(--text-2); border-radius:9px;
    cursor:pointer; transition:color .3s; user-select:none; white-space:nowrap;
}
.segmented input:checked + label{ color:var(--text); }
.segmented-thumb{
    position:absolute; top:3px; bottom:3px; left:3px; border-radius:9px;
    background:var(--surface); box-shadow:var(--raise-sm);
    transition:transform .4s var(--spring); z-index:1;
}
.seg-4 .segmented-thumb{ width:calc(25% - 3px); }
.seg-period:has(#per_30:checked) .segmented-thumb{ transform:translateX(100%); }
.seg-period:has(#per_90:checked) .segmented-thumb{ transform:translateX(200%); }
.seg-period:has(#per_all:checked) .segmented-thumb{ transform:translateX(300%); }
.seg-tabs:has(#tab_cat:checked) .segmented-thumb{ transform:translateX(100%); }
.seg-tabs:has(#tab_trend:checked) .segmented-thumb{ transform:translateX(200%); }
.seg-tabs:has(#tab_goals:checked) .segmented-thumb{ transform:translateX(300%); }
.segmented-tabs label{ font-size:12.5px; padding:10px 2px; }

/* ---------- Hero stat cards ---------- */
.hero-scroll{
    display:flex; gap:10px; overflow-x:auto; -webkit-overflow-scrolling:touch;
    scroll-snap-type:x mandatory; margin:14px -14px 16px; padding:2px 14px 6px;
}
.hero-scroll::-webkit-scrollbar{ display:none; }
.hero-stat{
    scroll-snap-align:start; flex:0 0 132px; background:var(--surface); border-radius:18px;
    padding:14px; box-shadow:var(--shadow-card); animation:cardIn .5s var(--spring) both;
    position:relative; overflow:hidden;
}
.hero-stat-icon{
    width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; margin-bottom:10px;
}
.hero-stat-icon svg{ width:16px; height:16px; }
.hero-stat-num{ font-size:22px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
.hero-stat-label{ font-size:11.5px; color:var(--text-2); font-weight:600; margin-top:1px; }
.hero-stat-sub{ font-size:10.5px; color:var(--text-3); font-weight:600; margin-top:4px; }
.flame-icon{ animation:flicker 1.8s ease-in-out infinite; transform-origin:50% 90%; }
@keyframes flicker{ 0%,100%{ transform:scale(1) rotate(0deg); } 25%{ transform:scale(1.06) rotate(-2deg); } 50%{ transform:scale(0.97) rotate(1deg); } 75%{ transform:scale(1.04) rotate(1deg); } }

/* ---------- Card generiche ---------- */
.card{ background:var(--surface); border-radius:var(--r-card); padding:18px; box-shadow:var(--shadow-card); margin-bottom:16px; animation:cardIn .5s var(--spring) both; }
.section-title{ font-size:17px; font-weight:800; letter-spacing:-.01em; margin:0 0 4px; }
.section-sub{ font-size:12.5px; color:var(--text-2); font-weight:500; margin:0 0 14px; }
.chart-box{ position:relative; height:230px; margin-top:6px; }
.chart-box.tall{ height:270px; }
.chart-box.short{ height:180px; }
.empty-hint{ text-align:center; padding:30px 10px; color:var(--text-2); font-size:14px; font-weight:500; }

/* ---------- Tab content ---------- */
.stats-tab-content{ display:none; }
.stats-tab-content.active{ display:block; animation:cardIn .35s var(--spring) both; }

/* ---------- Calendario GitHub-style ---------- */
.cal-header{ display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.cal-nav-btn{
    width:34px; height:34px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text);
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring);
}
.cal-nav-btn:active{ transform:scale(.85); }
.cal-nav-btn svg{ width:15px; height:15px; }
#cal-month-title{ font-size:16px; font-weight:800; margin:0; transition:opacity .2s; }
.cal-grid{ display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; }
.cal-day-label{ text-align:center; font-weight:700; color:var(--text-2); font-size:11px; padding-bottom:4px; }
.cal-cell{
    aspect-ratio:1; border-radius:8px; display:flex; justify-content:center; align-items:center;
    font-weight:700; font-size:12px; position:relative; cursor:pointer;
    transition:transform .15s var(--spring);
    animation:popIn .3s var(--spring) both;
}
.cal-cell:active{ transform:scale(.85); }
.cal-empty{ background:transparent; cursor:default; }
.cal-legend{ margin-top:16px; display:flex; align-items:center; justify-content:center; gap:6px; font-size:11px; color:var(--text-2); font-weight:600; flex-wrap:wrap; }
.cal-legend-box{ width:13px; height:13px; border-radius:4px; display:inline-block; }
#calSelectedInfo{
    margin-top:14px; text-align:center; font-size:13.5px; font-weight:700; color:var(--text);
    background:var(--surface-2); border-radius:12px; padding:10px; min-height:18px;
    transition:opacity .2s;
}

/* ---------- Filtro categorie ---------- */
.cat-pills-scroll{ display:flex; gap:8px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 4px; margin-bottom:12px; }
.cat-pills-scroll::-webkit-scrollbar{ display:none; }
.pill-cat{
    flex-shrink:0; padding:9px 16px; border-radius:99px; border:none; cursor:pointer;
    background:var(--surface-2); color:var(--text-2); font-weight:700; font-size:13.5px;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring), background .2s, color .2s;
}
.pill-cat.active{ background:var(--blue); color:#fff; box-shadow:0 6px 14px -4px rgba(10,132,255,.5); }
.pill-cat:active{ transform:scale(.94); }
.date-range-row{ display:flex; gap:10px; margin-bottom:14px; }
.date-field{ flex:1; }
.date-field label{ display:block; font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-2); margin-bottom:5px; }
.date-field input{
    width:100%; padding:10px 10px; font-family:inherit; font-size:14.5px; font-weight:700;
    color:var(--text); background:var(--surface-2); border:.5px solid var(--hairline-2); border-radius:12px;
}

/* ---------- Albero categorie (stesso linguaggio delle altre pagine) ---------- */
.materia-card{ position:relative; background:var(--surface); border-radius:18px; margin-bottom:12px; overflow:hidden; box-shadow:var(--shadow-card); border-left:3px solid var(--accent, var(--blue)); animation:cardIn .45s var(--spring) both; }
.cat-row{ display:block; }
.cat-row-main{ display:flex; align-items:center; gap:9px; padding:13px 12px 13px 0; }
.cat-row:not([data-level="0"]) .cat-row-main{ border-top:.5px solid var(--hairline); padding:10px 12px 10px 0; }
.cat-arrow, .cat-arrow-spacer{ width:20px; height:20px; flex-shrink:0; }
.cat-arrow{ display:flex; align-items:center; justify-content:center; border:none; background:transparent; color:var(--text-3); cursor:pointer; border-radius:50%; padding:0; transition:transform .3s var(--spring), color .2s; }
.cat-arrow svg{ width:14px; height:14px; }
.cat-arrow.open{ transform:rotate(90deg); color:var(--row-color, var(--blue)); }
.cat-icon{ width:30px; height:30px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.cat-icon svg{ width:15px; height:15px; }
.cat-icon.lvl0{ background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); width:34px; height:34px; border-radius:10px; }
.cat-icon.lvl0 svg{ width:16px; height:16px; }
.cat-icon.lvln{ background:color-mix(in srgb, var(--row-color, var(--text-2)) 16%, var(--surface)); color:var(--row-color, var(--text-2)); }
.cat-info{ flex:1; min-width:0; }
.cat-name{ font-size:14.5px; font-weight:600; letter-spacing:-.01em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cat-row[data-level="0"] .cat-name{ font-size:16px; font-weight:800; }
.cat-mastery-bar{ height:4px; border-radius:99px; background:var(--surface-2); margin-top:5px; overflow:hidden; }
.cat-mastery-fill{ height:100%; border-radius:99px; background:var(--row-color, var(--blue)); transition:width .6s var(--spring); }
.cat-stats-mini{ flex-shrink:0; display:flex; align-items:center; gap:6px; }
.cat-time-mini{ font-size:10.5px; font-weight:700; color:var(--teal); display:flex; align-items:center; gap:2px; white-space:nowrap; }
.cat-time-mini svg{ width:11px; height:11px; flex-shrink:0; }
.cat-played-num{ font-size:13px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; }
.cat-acc-pill{ font-size:11.5px; font-weight:800; padding:4px 8px; border-radius:99px; font-variant-numeric:tabular-nums; }
.acc-good{ background:var(--green-soft); color:var(--green); }
.acc-mid{ background:var(--orange-soft); color:var(--orange); }
.acc-bad{ background:var(--red-soft); color:var(--red); }
.acc-none{ background:var(--surface-2); color:var(--text-3); }
.cat-children{ max-height:0; overflow:hidden; transition:max-height .35s var(--spring); }
.cat-row-main.tappable{ cursor:pointer; }
.cat-row-main.tappable:active{ background:var(--surface-2); }

/* ---------- Deep-dive categoria ---------- */
.dd-back-btn{
    display:inline-flex; align-items:center; gap:6px; border:none; background:none; cursor:pointer;
    color:var(--blue); font-size:14.5px; font-weight:700; padding:6px 2px; margin-bottom:10px;
}
.dd-back-btn svg{ width:16px; height:16px; }
.dd-header{ display:flex; align-items:center; gap:12px; margin-bottom:14px; }
.dd-icon{ width:44px; height:44px; border-radius:13px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); }
.dd-icon svg{ width:21px; height:21px; }
.dd-breadcrumb{ font-size:11.5px; color:var(--text-2); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.dd-title{ font-size:21px; font-weight:800; letter-spacing:-.02em; }
.dd-period-scroll{ display:flex; gap:8px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 4px; margin-bottom:14px; }
.dd-period-scroll::-webkit-scrollbar{ display:none; }
.dd-hero-scroll{ display:flex; gap:10px; overflow-x:auto; -webkit-overflow-scrolling:touch; scroll-snap-type:x mandatory; margin:0 -14px 16px; padding:2px 14px 6px; }
.dd-hero-scroll::-webkit-scrollbar{ display:none; }
.dd-hero-stat{ scroll-snap-align:start; flex:0 0 124px; background:var(--surface); border-radius:16px; padding:12px; box-shadow:var(--shadow-card); }
.dd-hero-num{ font-size:19px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
.dd-hero-label{ font-size:11px; color:var(--text-2); font-weight:600; margin-top:2px; }
.dd-hero-sub{ font-size:10.5px; font-weight:800; margin-top:3px; display:flex; align-items:center; gap:2px; }
.dd-hero-sub.trend-up{ color:var(--green); }
.dd-hero-sub.trend-down{ color:var(--red); }
.dd-hero-sub.trend-flat{ color:var(--text-3); }
.dd-hero-bar{ height:4px; border-radius:99px; background:var(--surface-2); margin-top:8px; overflow:hidden; }
.dd-hero-bar-fill{ height:100%; border-radius:99px; background:var(--blue); transition:width .6s var(--spring); }
.subcat-row{
    display:flex; align-items:center; gap:10px; padding:10px 2px; cursor:pointer; border-radius:12px; transition:background .15s;
}
.subcat-row:active{ background:var(--surface-2); }
.subcat-row + .subcat-row{ border-top:.5px solid var(--hairline); }
.subcat-icon{ width:30px; height:30px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.subcat-icon svg{ width:15px; height:15px; }
.subcat-info{ flex:1; min-width:0; }
.subcat-name{ font-size:14.5px; font-weight:700; }
.subcat-chev{ flex-shrink:0; color:var(--text-3); }
.subcat-chev svg{ width:14px; height:14px; }
.retry-stat-row{ display:flex; align-items:center; gap:16px; }
.retry-ring-box{ position:relative; width:76px; height:76px; flex-shrink:0; }
.retry-ring-box svg{ width:100%; height:100%; transform:rotate(-90deg); }
.retry-ring-num{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:800; }
.retry-text{ font-size:14px; font-weight:600; color:var(--text-2); line-height:1.4; }
.retry-text b{ color:var(--text); font-weight:800; }
.worst-card-row{ display:flex; align-items:center; gap:11px; padding:9px 2px; }
.worst-card-row + .worst-card-row{ border-top:.5px solid var(--hairline); }
.worst-card-thumb{ width:40px; height:40px; border-radius:9px; object-fit:cover; flex-shrink:0; background:var(--surface-2); }
.worst-card-thumb-fallback{ width:40px; height:40px; border-radius:9px; flex-shrink:0; background:var(--surface-2); color:var(--text-3); display:flex; align-items:center; justify-content:center; }
.worst-card-thumb-fallback svg{ width:18px; height:18px; }
.worst-card-info{ flex:1; min-width:0; }
.worst-card-desc{ font-size:13.5px; font-weight:600; color:var(--text); overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; line-height:1.3; }
.worst-card-meta{ font-size:11px; color:var(--text-2); font-weight:600; margin-top:2px; }
.worst-card-wrong{ flex-shrink:0; font-size:13px; font-weight:800; color:var(--red); background:var(--red-soft); padding:5px 9px; border-radius:99px; }

/* ---------- Donut mastery ---------- */
.donut-box{ position:relative; height:200px; }
.donut-center{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; pointer-events:none; }
.donut-pct{ font-size:28px; font-weight:800; letter-spacing:-.02em; }
.donut-label{ font-size:12px; color:var(--text-2); font-weight:600; }
.mastery-legend{ display:flex; justify-content:center; gap:16px; margin-top:14px; flex-wrap:wrap; }
.mastery-legend-item{ display:flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; color:var(--text-2); }
.mastery-dot{ width:10px; height:10px; border-radius:50%; }

/* ---------- Forecast ---------- */
.forecast-card{ display:flex; align-items:center; gap:14px; padding:16px; border-radius:18px; background:linear-gradient(135deg, var(--indigo), var(--purple)); color:#fff; margin-bottom:16px; }

.dd-project-card{ background:linear-gradient(180deg, color-mix(in srgb, var(--orange) 6%, var(--surface)), var(--surface)); }
.dd-project-split{ display:flex; gap:18px; margin:14px 0 6px; }
.dd-project-half{ flex:1; text-align:center; }
.dd-project-bar-track{ height:8px; border-radius:99px; background:var(--surface-2); overflow:hidden; margin-bottom:8px; }
.dd-project-bar-fill{ height:100%; border-radius:99px; width:0%; transition:width 1s var(--spring); }
.dd-project-bar-fill.fc{ background:linear-gradient(90deg, var(--teal), var(--blue)); }
.dd-project-bar-fill.pomo{ background:linear-gradient(90deg, var(--orange), #FF6B35); }
.dd-project-num{ font-size:19px; font-weight:800; font-variant-numeric:tabular-nums; }
.dd-project-label{ font-size:11px; color:var(--text-2); font-weight:700; margin-top:1px; }
.dd-project-total{ text-align:center; font-size:12.5px; color:var(--text-2); padding-top:10px; border-top:.5px solid var(--hairline); }
.dd-project-total b{ color:var(--text); font-variant-numeric:tabular-nums; }
.forecast-icon{ width:44px; height:44px; border-radius:13px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.forecast-icon svg{ width:22px; height:22px; }
.forecast-text{ font-size:14px; font-weight:600; line-height:1.4; }
.forecast-text b{ font-weight:800; }

/* ---------- Obiettivo giornaliero ---------- */
.goal-row{ display:flex; align-items:center; gap:18px; }
.goal-ring-box{ position:relative; width:110px; height:110px; flex-shrink:0; }
.goal-ring-box svg{ width:100%; height:100%; transform:rotate(-90deg); }
.ring-bg{ fill:none; stroke:var(--surface-2); stroke-width:3; }
.ring-fill{ fill:none; stroke:var(--green); stroke-width:3; stroke-linecap:round; transition:stroke-dasharray .6s var(--spring), stroke .3s; }
.goal-ring-center{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.goal-ring-num{ font-size:22px; font-weight:800; }
.goal-ring-sub{ font-size:10.5px; color:var(--text-2); font-weight:700; }
.goal-controls{ flex:1; }
.goal-controls-label{ font-size:12px; font-weight:700; color:var(--text-2); margin-bottom:8px; }
.stepper{ display:flex; align-items:center; gap:14px; }
.stepper-btn{
    width:38px; height:38px; border-radius:50%; border:none; cursor:pointer;
    background:var(--surface-2); color:var(--blue); display:flex; align-items:center; justify-content:center;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring);
}
.stepper-btn:active{ transform:scale(.85); }
.stepper-btn svg{ width:16px; height:16px; }
.stepper-value{ font-size:22px; font-weight:800; min-width:40px; text-align:center; font-variant-numeric:tabular-nums; }
.goal-saved-note{ font-size:11px; color:var(--green); font-weight:700; margin-top:8px; height:14px; opacity:0; transition:opacity .3s; }
.goal-saved-note.show{ opacity:1; }

/* ---------- Streak dettaglio ---------- */
.streak-detail-row{ display:flex; gap:12px; }
.streak-box{ flex:1; text-align:center; padding:16px 8px; border-radius:16px; background:var(--surface-2); }
.streak-box-num{ font-size:26px; font-weight:800; letter-spacing:-.02em; }
.streak-box-label{ font-size:11.5px; color:var(--text-2); font-weight:700; margin-top:2px; }
.streak-next{ margin-top:14px; text-align:center; font-size:13px; color:var(--text-2); font-weight:600; }
.streak-next b{ color:var(--text); }

/* ---------- Badge / traguardi ---------- */
.badge-grid{ display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
.badge-chip{ display:flex; flex-direction:column; align-items:center; gap:6px; padding:12px 6px; border-radius:14px; background:var(--surface-2); text-align:center; transition:transform .2s var(--spring); }
.badge-chip.unlocked{ background:var(--blue-soft); animation:popIn .4s var(--spring) both; }
.badge-icon{ width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--surface); color:var(--text-3); }
.badge-chip.unlocked .badge-icon{ background:var(--blue); color:#fff; }
.badge-icon svg{ width:17px; height:17px; }
.badge-label{ font-size:10.5px; font-weight:700; color:var(--text-2); line-height:1.25; }
.badge-chip.unlocked .badge-label{ color:var(--text); }

/* ---------- Leaderboard famiglia ---------- */
.leaderboard-row{ display:flex; align-items:center; gap:12px; padding:10px 4px; }
.leaderboard-row + .leaderboard-row{ border-top:.5px solid var(--hairline); }
.lb-rank{ width:24px; height:24px; border-radius:50%; background:var(--surface-2); color:var(--text-2); font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.leaderboard-row.you .lb-rank{ background:var(--blue); color:#fff; }
.lb-name{ flex:1; min-width:0; font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.leaderboard-row.you .lb-name{ color:var(--blue); }
.lb-bar-track{ flex:1.4; height:8px; border-radius:99px; background:var(--surface-2); overflow:hidden; }
.lb-bar-fill{ height:100%; border-radius:99px; background:var(--blue); transition:width .6s var(--spring); }
.lb-count{ font-size:13px; font-weight:800; min-width:34px; text-align:right; font-variant-numeric:tabular-nums; }

@media (prefers-reduced-motion: reduce){
    *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }
}
</style>
</head>
<body>

<div class="app-header">
    <div class="hdr-row">
        <div>
            <div class="hdr-title">Statistiche</div>
        </div>
        <div class="user-pills">
            <div class="user-pill active"><?php echo strtoupper(substr(htmlspecialchars($utente_corrente['username'], ENT_QUOTES), 0, 1)); ?></div>
        </div>
    </div>
</div>

<?php include 'sidebar.html'; ?>

<?php if ($debugMode): ?>
<div style="max-width:100%; overflow-x:auto; background:#fff; margin:12px; padding:12px; border-radius:12px; font-size:11px; font-family:monospace; color:#111; border:2px solid #FF3B30;">
    <b>DEBUG — ultime 30 giocate (utente id <?php echo $current_user_id; ?>)</b>
    <table style="border-collapse:collapse; width:100%; margin-top:8px; white-space:nowrap;">
        <tr style="text-align:left; border-bottom:1px solid #999;">
            <th style="padding:4px 6px;">idgioco</th>
            <th style="padding:4px 6px;">idpartita</th>
            <th style="padding:4px 6px;">idimmagine</th>
            <th style="padding:4px 6px;">play_date</th>
            <th style="padding:4px 6px;">user_answer</th>
            <th style="padding:4px 6px;">game.category_name</th>
            <th style="padding:4px 6px;">game.type</th>
            <th style="padding:4px 6px;">ccmap_cat</th>
            <th style="padding:4px 6px;">ccmap_cat_name</th>
            <th style="padding:4px 6px;">ccmap_cat_parent</th>
            <th style="padding:4px 6px;">gc_single_cat</th>
            <th style="padding:4px 6px;">gc_all_cats</th>
            <th style="padding:4px 6px;">name_match_cat</th>
        </tr>
        <?php foreach ($debugRows as $r): ?>
        <tr style="border-bottom:1px solid #eee;">
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['idgioco'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['idpartita'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['idimmagine'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['play_date'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['user_answer'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars($r['game_category_name'] === null ? 'NULL' : (string)$r['game_category_name'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$r['game_type'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px; <?php echo $r['ccmap_cat'] === null ? 'color:#FF3B30; font-weight:bold;' : ''; ?>"><?php echo $r['ccmap_cat'] === null ? 'NULL' : htmlspecialchars((string)$r['ccmap_cat'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px; font-weight:bold;"><?php echo htmlspecialchars($r['ccmap_cat_name'] === null ? 'NULL' : (string)$r['ccmap_cat_name'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo $r['ccmap_cat_parent'] === null ? 'NULL' : htmlspecialchars((string)$r['ccmap_cat_parent'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo $r['gc_single_cat'] === null ? 'NULL' : htmlspecialchars((string)$r['gc_single_cat'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars($r['gc_all_cats'] === null ? 'NULL' : (string)$r['gc_all_cats'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo $r['name_match_cat'] === null ? 'NULL' : htmlspecialchars((string)$r['name_match_cat'], ENT_QUOTES); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (empty($debugRows)): ?>
    <p>Nessuna giocata trovata per questo utente.</p>
    <?php endif; ?>
</div>

<div style="max-width:100%; overflow-x:auto; background:#fff; margin:12px; padding:12px; border-radius:12px; font-size:11px; font-family:monospace; color:#111; border:2px solid #007AFF;">
    <b>DEBUG — albero completo fc_categories (<?php echo count($debugCats); ?> categorie)</b>
    <table style="border-collapse:collapse; width:100%; margin-top:8px;">
        <tr style="text-align:left; border-bottom:1px solid #999;">
            <th style="padding:4px 6px;">id</th>
            <th style="padding:4px 6px;">name</th>
            <th style="padding:4px 6px;">parent_id</th>
            <th style="padding:4px 6px;">parent_name</th>
        </tr>
        <?php
        $catsById = [];
        foreach ($debugCats as $dc) { $catsById[$dc['id']] = $dc; }
        foreach ($debugCats as $dc):
            $parentName = ($dc['parent_id'] != 0 && isset($catsById[$dc['parent_id']])) ? $catsById[$dc['parent_id']]['name'] : ($dc['parent_id'] == 0 ? '(radice)' : '??? mancante');
        ?>
        <tr style="border-bottom:1px solid #eee;">
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$dc['id'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$dc['name'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$dc['parent_id'], ENT_QUOTES); ?></td>
            <td style="padding:4px 6px;"><?php echo htmlspecialchars((string)$parentName, ENT_QUOTES); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>


<div class="page">

    <div class="segmented seg-4 seg-period">
        <input type="radio" id="per_7" name="period" value="7" onchange="setPeriod(7)">
        <label for="per_7">7 giorni</label>
        <input type="radio" id="per_30" name="period" value="30" checked onchange="setPeriod(30)">
        <label for="per_30">30 giorni</label>
        <input type="radio" id="per_90" name="period" value="90" onchange="setPeriod(90)">
        <label for="per_90">90 giorni</label>
        <input type="radio" id="per_all" name="period" value="0" onchange="setPeriod(0)">
        <label for="per_all">Tutto</label>
        <span class="segmented-thumb"></span>
    </div>

    <div class="hero-scroll">
        <div class="hero-stat">
            <div class="hero-stat-icon" style="background:var(--orange-soft); color:var(--orange);">
                <svg class="flame-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 17a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7.5 7.5 0 1 1-15 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
            </div>
            <div class="hero-stat-num"><?php echo $currentStreak; ?></div>
            <div class="hero-stat-label">Streak attuale</div>
            <div class="hero-stat-sub">Record: <?php echo $bestStreak; ?> giorni</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-icon" style="background:var(--blue-soft); color:var(--blue);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            </div>
            <div class="hero-stat-num" id="heroPlayed">0</div>
            <div class="hero-stat-label">Carte giocate</div>
            <div class="hero-stat-sub" id="heroPlayedSub">nel periodo</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-icon" style="background:var(--green-soft); color:var(--green);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <div class="hero-stat-num" id="heroAccuracy">—</div>
            <div class="hero-stat-label">Precisione</div>
            <div class="hero-stat-sub">nel periodo</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-icon" style="background:var(--purple-soft); color:var(--purple);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            </div>
            <div class="hero-stat-num"><?php echo $learntCards; ?><span style="font-size:13px; color:var(--text-3);">/<?php echo $totalCards; ?></span></div>
            <div class="hero-stat-label">Carte imparate</div>
            <div class="hero-stat-sub"><?php echo $totalCards > 0 ? round(($learntCards/$totalCards)*100) : 0; ?>% del totale</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-icon" style="background:var(--teal-soft); color:var(--teal);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
            </div>
            <div class="hero-stat-num" id="heroTime">0m</div>
            <div class="hero-stat-label">Tempo di studio</div>
            <div class="hero-stat-sub" id="heroTimeSub">nel periodo</div>
        </div>
    </div>

    <div class="segmented seg-4 seg-tabs segmented-tabs" style="margin-bottom:16px;">
        <input type="radio" id="tab_activity" name="stattab" value="activity" checked onchange="switchStatsTab('activity')">
        <label for="tab_activity">Attività</label>
        <input type="radio" id="tab_cat" name="stattab" value="cat" onchange="switchStatsTab('cat')">
        <label for="tab_cat">Categorie</label>
        <input type="radio" id="tab_trend" name="stattab" value="trend" onchange="switchStatsTab('trend')">
        <label for="tab_trend">Andamento</label>
        <input type="radio" id="tab_goals" name="stattab" value="goals" onchange="switchStatsTab('goals')">
        <label for="tab_goals">Obiettivi</label>
        <span class="segmented-thumb"></span>
    </div>

    <!-- ============ TAB ATTIVITA' ============ -->
    <div id="tabpanel-activity" class="stats-tab-content active">

        <div class="card">
            <div class="cal-header">
                <button type="button" class="cal-nav-btn" onclick="changeMonth(-1)" aria-label="Mese precedente">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <h3 id="cal-month-title">Mese Anno</h3>
                <button type="button" class="cal-nav-btn" onclick="changeMonth(1)" aria-label="Mese successivo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
            <div class="cal-grid" id="cal-grid"></div>
            <div class="cal-legend">
                Meno
                <span class="cal-legend-box" style="background:#ebedf0;"></span>
                <span class="cal-legend-box" style="background:#9be9a8;"></span>
                <span class="cal-legend-box" style="background:#40c463;"></span>
                <span class="cal-legend-box" style="background:#30a14e;"></span>
                <span class="cal-legend-box" style="background:#216e39;"></span>
                Più
            </div>
            <div id="calSelectedInfo">Tocca un giorno per i dettagli</div>
        </div>

        <div class="card">
            <div class="section-title">Andamento carte giocate</div>
            <div class="section-sub">Nel periodo selezionato sopra</div>
            <div class="chart-box"><canvas id="dailyTrendChart"></canvas></div>
        </div>

        <div class="card">
            <div class="section-title">Quando giochi di più</div>
            <div class="section-sub">Abitudini generali, su tutta la cronologia</div>
            <div class="chart-box short"><canvas id="weekdayChart"></canvas></div>
        </div>

        <div class="card">
            <div class="section-title">Fasce orarie preferite</div>
            <div class="chart-box short"><canvas id="hourChart"></canvas></div>
        </div>

    </div>

    <!-- ============ TAB CATEGORIE ============ -->
    <div id="tabpanel-cat" class="stats-tab-content">

      <div id="catOverviewSection">

        <div class="card">
            <div class="section-title">Filtra per materia</div>
            <div class="section-sub">Tocca una materia per l'analisi dettagliata, o una riga qualsiasi dell'albero</div>
            <div class="cat-pills-scroll" id="catRootPills">
                <button type="button" class="pill-cat active" data-id="all" onclick="selectCatPill(this)">Tutte</button>
                <?php foreach ($macro_cats as $mc): ?>
                <button type="button" class="pill-cat" data-id="<?php echo $mc['id']; ?>" onclick="selectCatPill(this)"><?php echo htmlspecialchars($mc['name'], ENT_QUOTES); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="date-range-row">
                <div class="date-field">
                    <label>Dal</label>
                    <input type="date" id="catStart" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" onchange="renderCategoryTab()">
                </div>
                <div class="date-field">
                    <label>Al</label>
                    <input type="date" id="catEnd" value="<?php echo date('Y-m-d'); ?>" onchange="renderCategoryTab()">
                </div>
            </div>
            <div id="catTreeContainer"></div>
        </div>

        <div class="card">
            <div class="section-title">Migliori e da ripassare</div>
            <div class="section-sub">Precisione per categoria nel periodo selezionato (minimo 3 carte giocate) · tocca una barra per approfondire</div>
            <div class="chart-box tall"><canvas id="bestWorstChart"></canvas></div>
            <div class="empty-hint" id="bestWorstEmpty" style="display:none;">Non ci sono ancora abbastanza dati in questo periodo.</div>
        </div>

        <div class="card">
            <div class="section-title"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--teal)" stroke-width="2.4" style="vertical-align:-2px; margin-right:3px;"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>Tempo per materia</div>
            <div class="section-sub">Minuti investiti per categoria nel periodo selezionato · tocca una barra per approfondire</div>
            <div class="chart-box tall"><canvas id="timeChart"></canvas></div>
            <div class="empty-hint" id="timeChartEmpty" style="display:none;">Non ci sono ancora sessioni cronometrate in questo periodo.</div>
        </div>

        <div class="card" id="speedChartCard" style="display:none;">
            <div class="section-title"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--indigo)" stroke-width="2.4" style="vertical-align:-2px; margin-right:3px;"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>Più veloci e da allenare</div>
            <div class="section-sub">Secondi medi per carta (tutta la cronologia, minimo 3 partite) · più basso è meglio</div>
            <div class="chart-box tall"><canvas id="speedChart"></canvas></div>
        </div>

      </div>

      <div id="catDeepDiveSection" style="display:none;">

        <button type="button" class="dd-back-btn" onclick="closeCatDeepDive()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            Panoramica categorie
        </button>

        <div class="card">
            <div class="dd-header">
                <span class="dd-icon" id="ddIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
                <div>
                    <div class="dd-breadcrumb" id="ddBreadcrumb">Materia</div>
                    <div class="dd-title" id="ddTitle">Nome categoria</div>
                </div>
            </div>
            <div class="dd-period-scroll" id="ddPeriodPills">
                <button type="button" class="pill-cat" data-days="1" onclick="setDeepDivePeriod(1, this)">Oggi</button>
                <button type="button" class="pill-cat" data-days="3" onclick="setDeepDivePeriod(3, this)">3 giorni</button>
                <button type="button" class="pill-cat" data-days="7" onclick="setDeepDivePeriod(7, this)">7 giorni</button>
                <button type="button" class="pill-cat" data-days="30" onclick="setDeepDivePeriod(30, this)">30 giorni</button>
                <button type="button" class="pill-cat" data-days="90" onclick="setDeepDivePeriod(90, this)">90 giorni</button>
                <button type="button" class="pill-cat active" data-days="0" onclick="setDeepDivePeriod(0, this)">Tutto</button>
            </div>

            <div class="dd-hero-scroll">
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddStreak">0</div>
                    <div class="dd-hero-label">Streak in questa materia</div>
                </div>
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddPlayed">0</div>
                    <div class="dd-hero-label">Carte testate</div>
                </div>
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddAccuracy">—</div>
                    <div class="dd-hero-label">Precisione</div>
                </div>
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddMastery">0/0</div>
                    <div class="dd-hero-label">Imparate</div>
                    <div class="dd-hero-bar"><div class="dd-hero-bar-fill" id="ddMasteryBar" style="width:0%;"></div></div>
                </div>
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddTime">0m</div>
                    <div class="dd-hero-label">Tempo totale</div>
                </div>
                <div class="dd-hero-stat">
                    <div class="dd-hero-num" id="ddSpeed">—</div>
                    <div class="dd-hero-label">Velocità media</div>
                    <div class="dd-hero-sub" id="ddSpeedTrend"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">Andamento nel periodo</div>
            <div class="section-sub" id="ddTrendSub">Carte testate al giorno</div>
            <div class="chart-box"><canvas id="ddTrendChart"></canvas></div>
            <div class="empty-hint" id="ddTrendEmpty" style="display:none;">Nessuna attività in questo periodo. Prova ad allargare l'intervallo con le pillole qui sopra.</div>
        </div>

        <div class="card" id="ddSpeedCard" style="display:none;">
            <div class="section-title"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--teal)" stroke-width="2.4" style="vertical-align:-2px; margin-right:3px;"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>Velocità di risposta</div>
            <div class="section-sub">Secondi medi per carta, partita dopo partita · in calo = miglioramento</div>
            <div class="chart-box"><canvas id="ddSpeedChart"></canvas></div>
        </div>

        <div class="card dd-project-card" id="ddProjectCard" style="display:none;">
            <div class="section-title"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--orange)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:3px;"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/></svg>Tempo investito in questa materia</div>
            <div class="section-sub" id="ddProjectSub">Flashcard + progetto pomodoro collegato</div>
            <div class="dd-project-split">
                <div class="dd-project-half">
                    <div class="dd-project-bar-track"><div class="dd-project-bar-fill fc" id="ddProjectFcBar" style="width:0%;"></div></div>
                    <div class="dd-project-num" id="ddProjectFcNum">0m</div>
                    <div class="dd-project-label">Flashcard</div>
                </div>
                <div class="dd-project-half">
                    <div class="dd-project-bar-track"><div class="dd-project-bar-fill pomo" id="ddProjectPomoBar" style="width:0%;"></div></div>
                    <div class="dd-project-num" id="ddProjectPomoNum">0m</div>
                    <div class="dd-project-label" id="ddProjectPomoLabel">Pomodoro</div>
                </div>
            </div>
            <div class="dd-project-total">Totale: <b id="ddProjectTotalNum">0m</b></div>
        </div>

        <div class="card" id="ddSubcatCard" style="display:none;">
            <div class="section-title">Sottocategorie a confronto</div>
            <div class="section-sub">Nel periodo selezionato · tocca per approfondire ulteriormente</div>
            <div id="ddSubcatList"></div>
        </div>

        <div class="card">
            <div class="section-title">Miglioramento dopo un errore</div>
            <div class="section-sub">Su tutta la cronologia in questa materia</div>
            <div class="retry-stat-row" id="ddRetryRow">
                <div class="retry-ring-box">
                    <svg viewBox="0 0 36 36">
                        <path class="ring-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path class="ring-fill" id="ddRetryRingFill" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    </svg>
                    <div class="retry-ring-num" id="ddRetryPct">—</div>
                </div>
                <div class="retry-text" id="ddRetryText">Non ci sono ancora abbastanza dati.</div>
            </div>
        </div>

        <div class="card" id="ddWorstCard" style="display:none;">
            <div class="section-title">Carte più sbagliate</div>
            <div class="section-sub">In questa materia, su tutta la cronologia</div>
            <div id="ddWorstList"></div>
        </div>

        <div class="forecast-card">
            <div class="forecast-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4 20-7z"/></svg>
            </div>
            <div class="forecast-text" id="ddForecastText">Continua a giocare per sbloccare una previsione.</div>
        </div>

        <div class="card">
            <div class="section-title">Proiezione apprendimento</div>
            <div class="section-sub">Storico e proiezione futura per questa materia</div>
            <div class="chart-box"><canvas id="ddForecastChart"></canvas></div>
            <div class="empty-hint" id="ddForecastEmpty" style="display:none;">Servono più dati storici per calcolare una proiezione.</div>
        </div>

        <div class="forecast-card" id="ddTimeForecastCard" style="display:none; background:linear-gradient(135deg, var(--teal), var(--blue));">
            <div class="forecast-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
            </div>
            <div class="forecast-text" id="ddTimeForecastText">—</div>
        </div>

      </div>

    </div>


    <!-- ============ TAB ANDAMENTO ============ -->
    <div id="tabpanel-trend" class="stats-tab-content">

        <div class="card">
            <div class="section-title">Precisione nel tempo</div>
            <div class="section-sub">Media settimanale di risposte corrette</div>
            <div class="chart-box"><canvas id="accuracyTrendChart"></canvas></div>
        </div>

        <div class="card" id="timeTrendCard" style="display:none;">
            <div class="section-title"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--teal)" stroke-width="2.4" style="vertical-align:-2px; margin-right:3px;"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>Tempo di studio nel tempo</div>
            <div class="section-sub">Minuti totali per settimana, tutte le materie</div>
            <div class="chart-box"><canvas id="timeTrendChart"></canvas></div>
        </div>

        <div class="card">
            <div class="section-title">Punteggio partite</div>
            <div class="section-sub">Ogni punto è una partita giocata</div>
            <div class="chart-box"><canvas id="scoreTrendChart"></canvas></div>
        </div>

        <div class="card">
            <div class="section-title">Padronanza carte</div>
            <div class="section-sub">Su tutto l'archivio flashcard disponibile</div>
            <div class="donut-box"><canvas id="masteryChart"></canvas>
                <div class="donut-center">
                    <div class="donut-pct"><?php echo $totalCards > 0 ? round(($learntCards/$totalCards)*100) : 0; ?>%</div>
                    <div class="donut-label">completato</div>
                </div>
            </div>
            <div class="mastery-legend">
                <div class="mastery-legend-item"><span class="mastery-dot" style="background:var(--green);"></span> Imparate (<?php echo $learntCards; ?>)</div>
                <div class="mastery-legend-item"><span class="mastery-dot" style="background:var(--orange);"></span> In corso (<?php echo $attemptedNotLearnt; ?>)</div>
                <div class="mastery-legend-item"><span class="mastery-dot" style="background:var(--hairline-2);"></span> Da provare (<?php echo $neverTried; ?>)</div>
            </div>
        </div>

        <div class="forecast-card">
            <div class="forecast-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4 20-7z"/></svg>
            </div>
            <div class="forecast-text">
                <?php if ($forecastWeeks !== null): ?>
                    Al ritmo attuale, finirai tutte le carte tra circa <b><?php echo $forecastWeeks; ?> settimane</b> (~<?php echo $forecastDate; ?>)
                <?php elseif ($totalCards > 0 && $learntCards >= $totalCards): ?>
                    Hai imparato tutte le carte disponibili! Straordinario lavoro.
                <?php else: ?>
                    Continua a giocare per sbloccare una previsione personalizzata sui tuoi progressi.
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="section-title">Proiezione apprendimento</div>
            <div class="section-sub">Storico (linea piena) e proiezione futura (tratteggiata)</div>
            <div class="chart-box"><canvas id="forecastChart"></canvas></div>
            <div class="empty-hint" id="forecastEmpty" style="display:none;">Servono più dati storici per calcolare una proiezione.</div>
        </div>

    </div>

    <!-- ============ TAB OBIETTIVI ============ -->
    <div id="tabpanel-goals" class="stats-tab-content">

        <div class="card">
            <div class="section-title">Obiettivo di oggi</div>
            <div class="goal-row">
                <div class="goal-ring-box">
                    <svg viewBox="0 0 36 36">
                        <path class="ring-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path class="ring-fill" id="goalRingFill" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    </svg>
                    <div class="goal-ring-center">
                        <div class="goal-ring-num" id="goalRingNum"><?php echo $todayPlayed; ?></div>
                        <div class="goal-ring-sub">/ <?php echo $dailyGoal; ?></div>
                    </div>
                </div>
                <div class="goal-controls">
                    <div class="goal-controls-label">Obiettivo carte al giorno</div>
                    <div class="stepper">
                        <button type="button" class="stepper-btn" onclick="adjustGoal(-5)" aria-label="Diminuisci">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 12h14"/></svg>
                        </button>
                        <div class="stepper-value" id="goalStepperValue"><?php echo $dailyGoal; ?></div>
                        <button type="button" class="stepper-btn" onclick="adjustGoal(5)" aria-label="Aumenta">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </div>
                    <div class="goal-saved-note" id="goalSavedNote">Obiettivo salvato ✓</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">Streak di studio</div>
            <div class="streak-detail-row">
                <div class="streak-box">
                    <div class="streak-box-num"><?php echo $currentStreak; ?></div>
                    <div class="streak-box-label">Attuale</div>
                </div>
                <div class="streak-box">
                    <div class="streak-box-num"><?php echo $bestStreak; ?></div>
                    <div class="streak-box-label">Record</div>
                </div>
            </div>
            <?php if ($nextMilestone !== null): ?>
            <div class="streak-next">Ancora <b><?php echo $nextMilestone - $currentStreak; ?> giorni</b> per il traguardo di <b><?php echo $nextMilestone; ?> giorni</b></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Traguardi</div>
            <div class="badge-grid">
                <?php foreach ($badges as $b): ?>
                <div class="badge-chip <?php echo $b['done'] ? 'unlocked' : 'locked'; ?>">
                    <span class="badge-icon"><?php echo badgeIconSvg($b['type']); ?></span>
                    <span class="badge-label"><?php echo htmlspecialchars($b['label'], ENT_QUOTES); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

</div>

<script>
// ==========================================
// DATI EMBEDDATI DAL SERVER
// ==========================================
const dailyData = <?php echo json_encode($dailyData); ?>;
const hourData = <?php echo json_encode($hourData); ?>;
const weekdayData = <?php echo json_encode($weekdayData); ?>;
const categoriesFlat = <?php echo json_encode($categoriesFlat); ?>;
const catDailyData = <?php echo json_encode($catDailyData); ?>;
const catCardTotals = <?php echo json_encode($catCardTotals); ?>;
const catLearnt = <?php echo json_encode($catLearnt); ?>;
const catLearntDaily = <?php echo json_encode($catLearntDaily); ?>;
const cardStats = <?php echo json_encode($cardStats); ?>;
const cardInfo = <?php echo json_encode($cardInfo); ?>;
const retryStatsByCategory = <?php echo json_encode($retryStatsByCategory); ?>;
const scoreTrend = <?php echo json_encode($scoreTrend); ?>;
const learntTrend = <?php echo json_encode($learntTrend); ?>;
const totalCards = <?php echo (int)$totalCards; ?>;
const learntCardsCount = <?php echo (int)$learntCards; ?>;
const attemptedNotLearntCount = <?php echo (int)$attemptedNotLearnt; ?>;
const neverTriedCount = <?php echo (int)$neverTried; ?>;
const currentUserId = <?php echo (int)$current_user_id; ?>;
let dailyGoalValue = <?php echo (int)$dailyGoal; ?>;
const todayPlayedValue = <?php echo (int)$todayPlayed; ?>;

// ---- Dati TEMPO (durata_sessione + pomodoro collegati) ----
const dailyTimeData = <?php echo json_encode($dailyTimeData); ?>;
const catTimeDaily = <?php echo json_encode($catTimeDaily); ?>;
const catSpeedPoints = <?php echo json_encode($catSpeedPoints); ?>;
const catAvgGameSize = <?php echo json_encode($catAvgGameSize); ?>;
const catProjectLink = <?php echo json_encode($catProjectLink); ?>;
const totalTimeAllTimeValue = <?php echo (int)$totalTimeAllTime; ?>;

// ==========================================
// HELPER COLORI (light/dark aware, per Chart.js)
// ==========================================
function isDarkMode(){ return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches; }
function cText(){ return isDarkMode() ? '#F5F5F7' : '#1C1C1E'; }
function cText2(){ return isDarkMode() ? '#98989F' : '#8A8A8E'; }
function cGrid(){ return isDarkMode() ? 'rgba(84,84,88,.35)' : 'rgba(60,60,67,.08)'; }
function cBlue(){ return isDarkMode() ? '#0A84FF' : '#007AFF'; }
function cGreen(){ return isDarkMode() ? '#30D158' : '#34C759'; }
function cRed(){ return isDarkMode() ? '#FF453A' : '#FF3B30'; }
function cOrange(){ return isDarkMode() ? '#FF9F0A' : '#FF9500'; }
function cIndigo(){ return isDarkMode() ? '#5E5CE6' : '#5856D6'; }
function cTeal(){ return isDarkMode() ? '#40C8E0' : '#30B0C7'; }
function cHairline2(){ return isDarkMode() ? 'rgba(84,84,88,.62)' : 'rgba(60,60,67,.22)'; }
function hexToRgba(hex, a){
    hex = hex.replace('#','');
    const r = parseInt(hex.substring(0,2),16), g = parseInt(hex.substring(2,4),16), b = parseInt(hex.substring(4,6),16);
    return `rgba(${r},${g},${b},${a})`;
}
function fmtDate(d){ return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
function fmtDuration(seconds){
    seconds = Math.max(0, Math.round(seconds || 0));
    const h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    if (m > 0) return m + 'm';
    return '< 1m';
}
function fmtSecPerCard(spc){
    if (spc == null) return '—';
    return (spc < 10 ? spc.toFixed(1) : Math.round(spc)) + 's/carta';
}
function escapeHtml(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

// ==========================================
// ANIMAZIONE "CONTATORE" per numeri/durate in evidenza
// ==========================================
const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function animateCountText(el, targetValue, formatter, duration){
    if (!el) return;
    formatter = formatter || (v => Math.round(v).toString());
    duration = duration || 650;
    if (prefersReducedMotion) { el.textContent = formatter(targetValue); return; }
    const startValue = el._animCurrent || 0;
    const startTime = performance.now();
    if (el._animFrame) cancelAnimationFrame(el._animFrame);
    function step(now){
        const t = Math.min(1, (now - startTime) / duration);
        const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
        const val = startValue + (targetValue - startValue) * eased;
        el.textContent = formatter(val);
        if (t < 1) { el._animFrame = requestAnimationFrame(step); }
        else { el._animCurrent = targetValue; el.textContent = formatter(targetValue); }
    }
    el._animFrame = requestAnimationFrame(step);
}

if (window.Chart) {
    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif";
    Chart.defaults.animation.duration = 700;
    Chart.defaults.animation.easing = 'easeOutQuart';
}

// ==========================================
// TAB SWITCHING
// ==========================================
const chartsInit = { activity:false, cat:false, trend:false, goals:false };

function switchStatsTab(tab){
    document.querySelectorAll('.stats-tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById('tabpanel-' + tab).classList.add('active');
    initTabCharts(tab);
}

function initTabCharts(tab){
    if (chartsInit[tab]) return;
    chartsInit[tab] = true;
    if (tab === 'activity') { renderDailyTrendChart(); renderWeekdayChart(); renderHourChart(); }
    if (tab === 'cat') { renderCategoryTab(); }
    if (tab === 'trend') { renderAccuracyTrendChart(); renderTimeTrendChart(); renderScoreTrendChart(); renderMasteryChart(); renderForecastChart(); }
    if (tab === 'goals') { animateLeaderboard(); }
}

// ==========================================
// PERIODO SELEZIONATO (7/30/90/tutto)
// ==========================================
let currentPeriod = 30;

function getDateRangeForPeriod(days){
    const end = new Date();
    let start;
    if (days === 0) { start = new Date('2000-01-01'); }
    else { start = new Date(); start.setDate(end.getDate() - (days - 1)); }
    return { start: fmtDate(start), end: fmtDate(end) };
}

function setPeriod(days){
    currentPeriod = days;
    updateHeroStats();
    if (chartsInit.activity) renderDailyTrendChart();
}

function updateHeroStats(){
    const { start, end } = getDateRangeForPeriod(currentPeriod);
    let played = 0, correct = 0, seconds = 0;
    Object.keys(dailyData).forEach(d => {
        if (d >= start && d <= end) { played += dailyData[d].played; correct += dailyData[d].correct; }
    });
    Object.keys(dailyTimeData).forEach(d => {
        if (d >= start && d <= end) { seconds += dailyTimeData[d]; }
    });
    document.getElementById('heroPlayed').textContent = played;
    document.getElementById('heroAccuracy').textContent = played > 0 ? Math.round((correct/played)*100) + '%' : '—';
    document.getElementById('heroPlayedSub').textContent = currentPeriod === 0 ? 'sempre' : 'ultimi ' + currentPeriod + ' giorni';
    animateCountText(document.getElementById('heroTime'), seconds, fmtDuration);
    document.getElementById('heroTimeSub').textContent = currentPeriod === 0 ? 'sempre' : 'ultimi ' + currentPeriod + ' giorni';
}

// ==========================================
// CALENDARIO ATTIVITA'
// ==========================================
let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();
const monthNames = ["Gennaio","Febbraio","Marzo","Aprile","Maggio","Giugno","Luglio","Agosto","Settembre","Ottobre","Novembre","Dicembre"];
const dayNames = ["Lun","Mar","Mer","Gio","Ven","Sab","Dom"];

function getColorForCount(count){
    if (!count || count === 0) return { bg:'#ebedf0', text:'#aaa' };
    if (count < 15) return { bg:'#9be9a8', text:'#111' };
    if (count < 40) return { bg:'#40c463', text:'#fff' };
    if (count < 80) return { bg:'#30a14e', text:'#fff' };
    return { bg:'#216e39', text:'#fff' };
}

function renderCalendar(){
    document.getElementById('cal-month-title').innerText = `${monthNames[currentMonth]} ${currentYear}`;
    const grid = document.getElementById('cal-grid');
    grid.innerHTML = '';

    dayNames.forEach(d => {
        const div = document.createElement('div');
        div.className = 'cal-day-label';
        div.innerText = d;
        grid.appendChild(div);
    });

    let firstDay = new Date(currentYear, currentMonth, 1).getDay();
    firstDay = firstDay === 0 ? 6 : firstDay - 1;
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);

    for (let i = 0; i < firstDay; i++) {
        const div = document.createElement('div');
        div.className = 'cal-cell cal-empty';
        grid.appendChild(div);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const info = dailyData[dateStr] || { played:0, correct:0 };
        const cellDate = new Date(currentYear, currentMonth, day);

        const div = document.createElement('div');
        div.className = 'cal-cell';
        div.innerText = day;
        div.style.animationDelay = (day * 6) + 'ms';

        if (cellDate > today) {
            div.style.background = 'transparent';
            div.style.color = 'var(--text-3)';
            div.style.boxShadow = 'inset 0 0 0 1px var(--hairline)';
        } else {
            const colors = getColorForCount(info.played);
            div.style.background = colors.bg;
            div.style.color = colors.text;
            if (cellDate.getTime() === today.getTime()) { div.style.boxShadow = '0 0 0 2px ' + cBlue(); }
        }

        div.onclick = () => {
            const acc = info.played > 0 ? Math.round((info.correct/info.played)*100) : 0;
            const el = document.getElementById('calSelectedInfo');
            el.style.opacity = 0;
            setTimeout(() => {
                el.textContent = info.played > 0
                    ? `${day} ${monthNames[currentMonth]}: ${info.played} carte, ${acc}% precisione`
                    : `${day} ${monthNames[currentMonth]}: nessuna attività`;
                el.style.opacity = 1;
            }, 120);
        };

        grid.appendChild(div);
    }
}

function changeMonth(step){
    document.getElementById('cal-month-title').style.opacity = 0;
    currentMonth += step;
    if (currentMonth > 11) { currentMonth = 0; currentYear++; }
    else if (currentMonth < 0) { currentMonth = 11; currentYear--; }
    setTimeout(() => {
        renderCalendar();
        document.getElementById('cal-month-title').style.opacity = 1;
    }, 120);
}

// ==========================================
// TREND GIORNALIERO (Attività)
// ==========================================
let dailyTrendChartInstance = null;
function renderDailyTrendChart(){
    const { start, end } = getDateRangeForPeriod(currentPeriod);
    const dates = Object.keys(dailyData).filter(d => d >= start && d <= end).sort();
    const labels = dates.map(d => d.slice(5));
    const played = dates.map(d => dailyData[d].played);
    const correct = dates.map(d => dailyData[d].correct);

    const ctx = document.getElementById('dailyTrendChart');
    if (dailyTrendChartInstance) dailyTrendChartInstance.destroy();
    dailyTrendChartInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets: [
            { label:'Giocate', data:played, borderColor:cBlue(), backgroundColor:hexToRgba(cBlue(),.15), fill:true, tension:.35, pointRadius:0, borderWidth:2.5 },
            { label:'Corrette', data:correct, borderColor:cGreen(), backgroundColor:'transparent', fill:false, tension:.35, pointRadius:0, borderWidth:2 }
        ]},
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ position:'bottom', labels:{ color:cText2(), boxWidth:10, font:{size:11} } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), maxTicksLimit:6, font:{size:10} } },
                y:{ beginAtZero:true, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// GIORNO DELLA SETTIMANA / FASCE ORARIE
// ==========================================
let weekdayChartInstance = null;
function renderWeekdayChart(){
    const ctx = document.getElementById('weekdayChart');
    if (weekdayChartInstance) weekdayChartInstance.destroy();
    weekdayChartInstance = new Chart(ctx, {
        type:'bar',
        data:{ labels:dayNames, datasets:[{ data:weekdayData, backgroundColor:cIndigo(), borderRadius:8, maxBarThickness:34 }] },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:11} } },
                y:{ beginAtZero:true, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

let hourChartInstance = null;
function renderHourChart(){
    const buckets = [0,0,0,0,0,0];
    const labels = ['00-04','04-08','08-12','12-16','16-20','20-24'];
    hourData.forEach((c, h) => { buckets[Math.floor(h/4)] += c; });

    const ctx = document.getElementById('hourChart');
    if (hourChartInstance) hourChartInstance.destroy();
    hourChartInstance = new Chart(ctx, {
        type:'bar',
        data:{ labels, datasets:[{ data:buckets, backgroundColor:cOrange(), borderRadius:8, maxBarThickness:34 }] },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:10} } },
                y:{ beginAtZero:true, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// TAB CATEGORIE (albero + filtro client-side)
// ==========================================
const catById = {};
const catChildren = {};
categoriesFlat.forEach(c => {
    const cid = Number(c.id);
    const pid = Number(c.parent_id) || 0;
    catById[cid] = c;
    if (!catChildren[pid]) catChildren[pid] = [];
    catChildren[pid].push(cid);
});

function selectCatPill(btn){
    document.querySelectorAll('#catRootPills .pill-cat').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const id = btn.dataset.id;
    if (id === 'all') {
        closeCatDeepDive();
    } else {
        openCatDeepDive(parseInt(id));
    }
}

function computeCatStats(startDate, endDate){
    const stats = {};
    catDailyData.forEach(row => {
        if (row.d >= startDate && row.d <= endDate) {
            if (!stats[row.cat]) stats[row.cat] = { played:0, correct:0 };
            stats[row.cat].played += row.played;
            stats[row.cat].correct += row.correct;
        }
    });
    return stats;
}

function computeCatTimeStats(startDate, endDate){
    const stats = {};
    catTimeDaily.forEach(row => {
        if (row.d >= startDate && row.d <= endDate) {
            stats[row.cat] = (stats[row.cat] || 0) + row.seconds;
        }
    });
    return stats;
}

// Confronta la velocità media (secondi/carta) delle partite più VECCHIE con quelle più
// RECENTI per una categoria: è il modo più semplice e leggibile per mostrare un
// "miglioramento", nello stesso spirito delle altre proiezioni di questa pagina (niente
// regressioni complesse, solo un confronto prima/dopo diretto e trasparente).
function computeCatSpeedStats(idSet){
    const pts = catSpeedPoints.filter(p => idSet.has(p.cat)).sort((a,b) => a.d < b.d ? -1 : (a.d > b.d ? 1 : 0));
    if (pts.length === 0) return null;
    const overallAvg = pts.reduce((s,p) => s + p.spc, 0) / pts.length;
    if (pts.length < 4) {
        return { points: pts, overallAvg, recentAvg: overallAvg, oldAvg: overallAvg, improvementPct: null, trend: 'flat' };
    }
    const chunk = Math.max(2, Math.floor(pts.length / 3));
    const oldPts = pts.slice(0, chunk);
    const recentPts = pts.slice(-chunk);
    const oldAvg = oldPts.reduce((s,p) => s + p.spc, 0) / oldPts.length;
    const recentAvg = recentPts.reduce((s,p) => s + p.spc, 0) / recentPts.length;
    const improvementPct = oldAvg > 0 ? Math.round(((oldAvg - recentAvg) / oldAvg) * 100) : 0;
    const trend = improvementPct > 4 ? 'up' : (improvementPct < -4 ? 'down' : 'flat');
    return { points: pts, overallAvg, recentAvg, oldAvg, improvementPct, trend };
}

function toggleStatBranch(btn){
    const row = btn.closest('.cat-row');
    const childrenBox = row.querySelector(':scope > .cat-children');
    if (!childrenBox) return;
    
    const willOpen = !childrenBox.classList.contains('open');
    btn.classList.toggle('open', willOpen);
    childrenBox.classList.toggle('open', willOpen);

    if (willOpen) {
        // Imposta l'altezza fissa temporaneamente per far partire l'animazione CSS
        childrenBox.style.maxHeight = childrenBox.scrollHeight + 'px';
        
        // Rimuove il limite quando l'animazione finisce (360ms)
        setTimeout(function() {
            if (childrenBox.classList.contains('open')) {
                childrenBox.style.maxHeight = 'none';
            }
        }, 360); 
    } else {
        // Se la cartella aveva max-height: none, dobbiamo rimettere i px esatti prima di chiudere
        childrenBox.style.maxHeight = childrenBox.scrollHeight + 'px';
        
        // Forza il ricalcolo del browser (reflow)
        void childrenBox.offsetHeight; 
        
        // Fai scattare l'animazione verso lo zero
        childrenBox.style.maxHeight = '0px';
    }
}

function renderCatNode(id, level, container, stats, timeStats){
    const cat = catById[id];
    if (!cat) return;

    // --- Calcoliamo i totali aggregando tutto il ramo ---
    let aggPlayed = 0;
    let aggCorrect = 0;
    let aggTotalCards = 0;
    let aggLearntCards = 0;
    let aggSeconds = 0;

    // Usiamo getSubtreeIds (già presente nel tuo codice) per prendere l'ID e tutti i figli
    const subtreeIds = getSubtreeIds(id);
    subtreeIds.forEach(subId => {
        if (stats[subId]) {
            aggPlayed += stats[subId].played;
            aggCorrect += stats[subId].correct;
        }
        aggTotalCards += (catCardTotals[subId] || 0);
        aggLearntCards += (catLearnt[subId] || 0);
        aggSeconds += (timeStats[subId] || 0);
    });

    // Calcoliamo percentuali e accuratezza basandoci sui dati aggregati
    const accuracy = aggPlayed > 0 ? Math.round((aggCorrect / aggPlayed) * 100) : null;
    const masteryPct = aggTotalCards > 0 ? Math.round((aggLearntCards / aggTotalCards) * 100) : 0;

    const children = catChildren[id] || [];
    const color = cat.color || '#007AFF';
    const pad = 12 + level * 22;

    const row = document.createElement('div');
    row.className = 'cat-row';
    row.dataset.level = level;
    row.style.setProperty('--row-color', color);

    const arrowHtml = children.length > 0
        ? `<button type="button" class="cat-arrow" onclick="event.stopPropagation(); toggleStatBranch(this)" aria-label="Espandi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></button>`
        : `<span class="cat-arrow-spacer"></span>`;

    let accHtml = `<span class="cat-acc-pill acc-none">—</span>`;
    if (accuracy !== null) {
        const accClass = accuracy >= 80 ? 'acc-good' : (accuracy >= 50 ? 'acc-mid' : 'acc-bad');
        accHtml = `<span class="cat-acc-pill ${accClass}">${accuracy}%</span>`;
    }
    const timeHtml = aggSeconds > 0
        ? `<span class="cat-time-mini"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>${fmtDuration(aggSeconds)}</span>`
        : '';

    const main = document.createElement('div');
    main.className = 'cat-row-main tappable';
    main.style.paddingLeft = pad + 'px';
    main.onclick = () => openCatDeepDive(id);
    
    // Inseriamo le nuove variabili (aggTotalCards, masteryPct, aggPlayed, aggSeconds) nell'HTML
    main.innerHTML = `
        ${arrowHtml}
        <span class="cat-icon ${level===0?'lvl0':'lvln'}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
        <div class="cat-info">
            <div class="cat-name">${escapeHtml(cat.name)}</div>
            ${aggTotalCards > 0 ? `<div class="cat-mastery-bar"><div class="cat-mastery-fill" style="width:${masteryPct}%;"></div></div>` : ''}
        </div>
        <div class="cat-stats-mini">
            ${timeHtml}
            <span class="cat-played-num">${aggPlayed}</span>
            ${accHtml}
        </div>
    `;
    row.appendChild(main);
    container.appendChild(row);

    if (children.length > 0) {
        const childrenBox = document.createElement('div');
        childrenBox.className = 'cat-children';
        row.appendChild(childrenBox);
        children.forEach(cid => renderCatNode(cid, level + 1, childrenBox, stats, timeStats));
    }
}

function renderCategoryTab(){
    const activePill = document.querySelector('#catRootPills .pill-cat.active');
    const rootVal = activePill ? activePill.dataset.id : 'all';
    const start = document.getElementById('catStart').value;
    const end = document.getElementById('catEnd').value;
    const stats = computeCatStats(start, end);
    const timeStats = computeCatTimeStats(start, end);
    const container = document.getElementById('catTreeContainer');
    container.innerHTML = '';

    const rootIds = rootVal === 'all' ? (catChildren[0] || []) : [parseInt(rootVal)];

    if (rootIds.length === 0) {
        container.innerHTML = '<div class="empty-hint">Nessuna categoria disponibile.</div>';
    } else {
        rootIds.forEach((rid, i) => {
            if (!catById[rid]) return;
            const card = document.createElement('div');
            card.className = 'materia-card';
            card.style.setProperty('--accent', catById[rid].color || '#007AFF');
            card.style.animationDelay = Math.min(i * 40, 240) + 'ms';
            container.appendChild(card);
            renderCatNode(rid, 0, card, stats, timeStats);
        });
    }

    renderBestWorstChart(stats);
    renderTimeChart(timeStats);
    renderSpeedLeaderboard();
}

let bestWorstChartInstance = null;
function renderBestWorstChart(stats){
    const entries = Object.keys(stats).map(id => {
        const s = stats[id];
        const cat = catById[id];
        if (!cat || s.played < 3) return null;
        return { id: parseInt(id), name: cat.name, color: cat.color || '#007AFF', accuracy: Math.round((s.correct/s.played)*100) };
    }).filter(Boolean);

    entries.sort((a,b) => b.accuracy - a.accuracy);
    const combinedMap = {};
    entries.slice(0,5).forEach(e => combinedMap[e.name] = e);
    entries.slice(-5).forEach(e => combinedMap[e.name] = e);
    const combined = Object.values(combinedMap).sort((a,b) => b.accuracy - a.accuracy);

    const ctx = document.getElementById('bestWorstChart');
    const emptyEl = document.getElementById('bestWorstEmpty');
    if (bestWorstChartInstance) bestWorstChartInstance.destroy();

    if (combined.length === 0) {
        emptyEl.style.display = 'block';
        ctx.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    ctx.style.display = 'block';

    bestWorstChartInstance = new Chart(ctx, {
        type:'bar',
        data:{
            labels: combined.map(e => e.name),
            datasets:[{ data: combined.map(e => e.accuracy), backgroundColor: combined.map(e => e.color), borderRadius:8, maxBarThickness:20 }]
        },
        options:{
            indexAxis:'y', responsive:true, maintainAspectRatio:false,
            onClick:(evt, elements) => { if (elements.length) { openCatDeepDive(combined[elements[0].index].id); } },
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => c.raw + '% precisione' } } },
            scales:{
                x:{ min:0, max:100, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } },
                y:{ grid:{ display:false }, ticks:{ color:cText(), font:{size:11} } }
            }
        }
    });
}

// ==========================================
// TEMPO PER MATERIA — quanto tempo hai investito in ciascuna categoria/ramo
// nell'intervallo selezionato (stesso stile del grafico precisione)
// ==========================================
let timeChartInstance = null;
function renderTimeChart(timeStats){
    const entries = Object.keys(timeStats).map(id => {
        const cat = catById[id];
        if (!cat || timeStats[id] < 60) return null; // sotto il minuto non è significativo in classifica
        return { id: parseInt(id), name: cat.name, color: cat.color || '#007AFF', seconds: timeStats[id] };
    }).filter(Boolean);

    entries.sort((a,b) => b.seconds - a.seconds);
    const top = entries.slice(0, 8);

    const ctx = document.getElementById('timeChart');
    const emptyEl = document.getElementById('timeChartEmpty');
    if (timeChartInstance) timeChartInstance.destroy();

    if (top.length === 0) {
        emptyEl.style.display = 'block';
        ctx.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    ctx.style.display = 'block';

    timeChartInstance = new Chart(ctx, {
        type:'bar',
        data:{
            labels: top.map(e => e.name),
            datasets:[{ data: top.map(e => Math.round(e.seconds/60)), backgroundColor: top.map(e => e.color), borderRadius:8, maxBarThickness:20 }]
        },
        options:{
            indexAxis:'y', responsive:true, maintainAspectRatio:false,
            onClick:(evt, elements) => { if (elements.length) { openCatDeepDive(top[elements[0].index].id); } },
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => fmtDuration(top[c.dataIndex].seconds) } } },
            scales:{
                x:{ min:0, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10}, callback:(v) => v + 'm' } },
                y:{ grid:{ display:false }, ticks:{ color:cText(), font:{size:11} } }
            }
        }
    });
}

// ==========================================
// VELOCITÀ PER MATERIA — categorie più veloci e più lente (secondi/carta)
// ==========================================
let speedChartInstance = null;
function renderSpeedLeaderboard(){
    const catIds = Object.keys(catById).filter(id => catById[id]);
    const entries = catIds.map(id => {
        const speed = computeCatSpeedStats(new Set([parseInt(id)]));
        if (!speed || speed.points.length < 3) return null;
        const cat = catById[id];
        return { id: parseInt(id), name: cat.name, color: cat.color || '#007AFF', spc: speed.overallAvg };
    }).filter(Boolean);

    const card = document.getElementById('speedChartCard');
    if (entries.length === 0) { card.style.display = 'none'; return; }
    card.style.display = '';

    entries.sort((a,b) => a.spc - b.spc);
    const combinedMap = {};
    entries.slice(0,5).forEach(e => combinedMap[e.name] = e);
    entries.slice(-5).forEach(e => combinedMap[e.name] = e);
    const combined = Object.values(combinedMap).sort((a,b) => a.spc - b.spc);

    const ctx = document.getElementById('speedChart');
    if (speedChartInstance) speedChartInstance.destroy();
    speedChartInstance = new Chart(ctx, {
        type:'bar',
        data:{
            labels: combined.map(e => e.name),
            datasets:[{ data: combined.map(e => e.spc), backgroundColor: combined.map(e => e.color), borderRadius:8, maxBarThickness:20 }]
        },
        options:{
            indexAxis:'y', responsive:true, maintainAspectRatio:false,
            onClick:(evt, elements) => { if (elements.length) { openCatDeepDive(combined[elements[0].index].id); } },
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => fmtSecPerCard(c.raw) } } },
            scales:{
                x:{ min:0, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10}, callback:(v) => v + 's' } },
                y:{ grid:{ display:false }, ticks:{ color:cText(), font:{size:11} } }
            }
        }
    });
}

// ==========================================
// ANDAMENTO: precisione settimanale
// ==========================================
let accuracyTrendChartInstance = null;
function renderAccuracyTrendChart(){
    const dates = Object.keys(dailyData).sort();
    const weekMap = {};
    dates.forEach(d => {
        const dateObj = new Date(d);
        const onejan = new Date(dateObj.getFullYear(),0,1);
        const week = Math.ceil((((dateObj - onejan) / 86400000) + onejan.getDay() + 1) / 7);
        const key = dateObj.getFullYear() + '-W' + String(week).padStart(2,'0');
        if (!weekMap[key]) weekMap[key] = { played:0, correct:0 };
        weekMap[key].played += dailyData[d].played;
        weekMap[key].correct += dailyData[d].correct;
    });
    const weekKeys = Object.keys(weekMap).sort().slice(-16);
    const labels = weekKeys.map(k => k.split('-W')[1] + 'ª sett.');
    const accData = weekKeys.map(k => weekMap[k].played > 0 ? Math.round((weekMap[k].correct/weekMap[k].played)*100) : null);

    const ctx = document.getElementById('accuracyTrendChart');
    if (accuracyTrendChartInstance) accuracyTrendChartInstance.destroy();

    if (weekKeys.length === 0) {
        ctx.parentElement.innerHTML = '<div class="empty-hint">Ancora nessun dato per calcolare una tendenza.</div>';
        return;
    }

    accuracyTrendChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels, datasets:[{ data:accData, borderColor:cGreen(), backgroundColor:hexToRgba(cGreen(),.18), fill:true, tension:.4, pointRadius:3, pointBackgroundColor:cGreen(), spanGaps:true }] },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => c.raw + '% precisione' } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:9}, maxTicksLimit:8 } },
                y:{ min:0, max:100, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// ANDAMENTO: tempo di studio settimanale
// ==========================================
let timeTrendChartInstance = null;
function renderTimeTrendChart(){
    const dates = Object.keys(dailyTimeData).sort();
    const weekMap = {};
    dates.forEach(d => {
        const dateObj = new Date(d);
        const onejan = new Date(dateObj.getFullYear(),0,1);
        const week = Math.ceil((((dateObj - onejan) / 86400000) + onejan.getDay() + 1) / 7);
        const key = dateObj.getFullYear() + '-W' + String(week).padStart(2,'0');
        weekMap[key] = (weekMap[key] || 0) + dailyTimeData[d];
    });
    const weekKeys = Object.keys(weekMap).sort().slice(-16);
    const labels = weekKeys.map(k => k.split('-W')[1] + 'ª sett.');
    const card = document.getElementById('timeTrendCard');

    if (weekKeys.length === 0) { card.style.display = 'none'; return; }
    card.style.display = '';

    const ctx = document.getElementById('timeTrendChart');
    if (timeTrendChartInstance) timeTrendChartInstance.destroy();
    timeTrendChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels, datasets:[{ data: weekKeys.map(k => Math.round(weekMap[k]/60)), borderColor:cTeal(), backgroundColor:hexToRgba(cTeal(),.18), fill:true, tension:.4, pointRadius:3, pointBackgroundColor:cTeal() }] },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => fmtDuration(c.raw*60) } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:9}, maxTicksLimit:8 } },
                y:{ min:0, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10}, callback:(v)=>v+'m' } }
            }
        }
    });
}

// ==========================================
// ANDAMENTO: punteggio partite
// ==========================================
let scoreTrendChartInstance = null;
function renderScoreTrendChart(){
    const ctx = document.getElementById('scoreTrendChart');
    if (scoreTrendChartInstance) scoreTrendChartInstance.destroy();

    if (scoreTrend.length === 0) {
        ctx.parentElement.innerHTML = '<div class="empty-hint">Nessuna partita con punteggio registrata.</div>';
        return;
    }

    const labels = scoreTrend.map(s => (s.date || '').slice(5,10));
    const data = scoreTrend.map(s => s.score);

    scoreTrendChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels, datasets:[{ data, borderColor:cIndigo(), backgroundColor:hexToRgba(cIndigo(),.15), fill:true, tension:.3, pointRadius:2.5, pointBackgroundColor:cIndigo() }] },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c) => {
                const item = scoreTrend[c.dataIndex];
                return `Punteggio: ${item.score} (${item.cat || item.type || ''})`;
            } } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:9}, maxTicksLimit:8 } },
                y:{ grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// ANDAMENTO: donut padronanza
// ==========================================
let masteryChartInstance = null;
function renderMasteryChart(){
    const ctx = document.getElementById('masteryChart');
    if (masteryChartInstance) masteryChartInstance.destroy();
    masteryChartInstance = new Chart(ctx, {
        type:'doughnut',
        data:{
            labels:['Imparate','In corso','Da provare'],
            datasets:[{ data:[learntCardsCount, attemptedNotLearntCount, neverTriedCount], backgroundColor:[cGreen(), cOrange(), cHairline2()], borderWidth:0 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, cutout:'72%',
            plugins:{ legend:{ display:false } }
        }
    });
}

// ==========================================
// ANDAMENTO: proiezione futura
// ==========================================
let forecastChartInstance = null;
function buildProjectionData(trendArr, target){
    let cum = 0;
    const histLabels = [];
    const histData = [];
    trendArr.forEach(pt => { cum += pt.count; histLabels.push(pt.date); histData.push(cum); });

    if (histData.length < 2 || cum >= target) {
        return { labels: histLabels, hist: histData, proj: [], histLen: histData.length };
    }

    const firstDate = new Date(trendArr[0].date);
    const lastDate = new Date(trendArr[trendArr.length - 1].date);
    const daysSpan = Math.max(1, (lastDate - firstDate) / 86400000);
    const ratePerDay = cum / daysSpan;

    const projLabels = [];
    const projData = [];
    if (ratePerDay > 0.01) {
        let curCum = cum;
        const curDate = new Date(lastDate);
        let guard = 0;
        while (curCum < target && guard < 300) {
            curDate.setDate(curDate.getDate() + 7);
            curCum = Math.min(target, curCum + ratePerDay * 7);
            projLabels.push(fmtDate(curDate));
            projData.push(Math.round(curCum));
            guard++;
        }
    }
    return { labels: histLabels.concat(projLabels), hist: histData, proj: projData, histLen: histData.length };
}

function renderForecastChart(){
    const ctx = document.getElementById('forecastChart');
    const emptyEl = document.getElementById('forecastEmpty');
    const { labels, hist, proj, histLen } = buildProjectionData(learntTrend, totalCards);

    if (forecastChartInstance) forecastChartInstance.destroy();

    if (histLen < 2) {
        emptyEl.style.display = 'block';
        ctx.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    ctx.style.display = 'block';

    const histSeries = labels.map((_, i) => i < histLen ? hist[i] : null);
    const projSeries = labels.map((_, i) => {
        if (i < histLen - 1) return null;
        if (i === histLen - 1) return hist[histLen - 1];
        return proj[i - histLen];
    });

    forecastChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels: labels.map(d => d.slice(5)), datasets:[
            { label:'Storico', data:histSeries, borderColor:cGreen(), backgroundColor:hexToRgba(cGreen(),.15), fill:true, tension:.3, pointRadius:0, borderWidth:2.5 },
            { label:'Proiezione', data:projSeries, borderColor:cGreen(), backgroundColor:'transparent', borderDash:[6,6], fill:false, tension:.3, pointRadius:0, borderWidth:2 }
        ]},
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'bottom', labels:{ color:cText2(), boxWidth:10, font:{size:11} } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:9}, maxTicksLimit:7 } },
                y:{ min:0, max:totalCards, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// DEEP-DIVE PER CATEGORIA (analisi estrema di un ramo)
// ==========================================
let deepDiveCategoryId = null;
let deepDivePeriodDays = 0;
let ddTrendChartInstance = null;
let ddForecastChartInstance = null;

function getSubtreeIds(rootId){
    rootId = Number(rootId);
    const ids = [rootId];
    (catChildren[rootId] || []).forEach(cid => { ids.push(...getSubtreeIds(cid)); });
    return ids;
}

function getBreadcrumb(id){
    const path = [];
    let cur = catById[id];
    while (cur) {
        path.unshift(cur.name);
        cur = (cur.parent_id && catById[cur.parent_id]) ? catById[cur.parent_id] : null;
    }
    return path;
}

function computeStreakFromDates(dateArr){
    const dates = Array.from(new Set(dateArr)).sort();
    let best = 0, run = 0, prev = null, current = 0;
    dates.forEach(d => {
        const dateObj = new Date(d + 'T00:00:00');
        if (prev !== null) {
            const diffDays = Math.round((dateObj - prev) / 86400000);
            run = diffDays === 1 ? run + 1 : 1;
        } else { run = 1; }
        best = Math.max(best, run);
        prev = dateObj;
    });
    if (dates.length > 0) {
        const today = new Date(); today.setHours(0,0,0,0);
        const last = new Date(dates[dates.length - 1] + 'T00:00:00');
        const diffToday = Math.round((today - last) / 86400000);
        current = (diffToday === 0 || diffToday === 1) ? run : 0;
    }
    return { current, best };
}

function openCatDeepDive(id){
    if (!catById[id]) return;
    deepDiveCategoryId = id;
    document.getElementById('catOverviewSection').style.display = 'none';
    document.getElementById('catDeepDiveSection').style.display = 'block';

    const cat = catById[id];
    document.getElementById('ddTitle').textContent = cat.name;
    const crumb = getBreadcrumb(id);
    document.getElementById('ddBreadcrumb').textContent = crumb.length > 1 ? crumb.slice(0, -1).join(' › ') : 'Materia';
    document.getElementById('ddIcon').style.setProperty('--row-color', cat.color || '#007AFF');

    renderDeepDiveAll();
    document.getElementById('tabpanel-cat').scrollIntoView({ behavior:'smooth', block:'start' });
}

function closeCatDeepDive(){
    deepDiveCategoryId = null;
    document.getElementById('catDeepDiveSection').style.display = 'none';
    document.getElementById('catOverviewSection').style.display = 'block';
    document.querySelectorAll('#catRootPills .pill-cat').forEach(p => p.classList.remove('active'));
    document.querySelector('#catRootPills .pill-cat[data-id="all"]').classList.add('active');
}

function setDeepDivePeriod(days, btn){
    deepDivePeriodDays = days;
    document.querySelectorAll('#ddPeriodPills .pill-cat').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderDeepDiveAll();
}

function renderDeepDiveAll(){
    if (deepDiveCategoryId === null) return;
    const subtreeIds = getSubtreeIds(deepDiveCategoryId);
    const idSet = new Set(subtreeIds);
    const { start, end } = getDateRangeForPeriod(deepDivePeriodDays);

    renderDeepDiveHero(subtreeIds, idSet, start, end);
    renderDeepDiveTrendChart(idSet, start, end);
    renderDeepDiveSpeedChart(idSet);
    renderDeepDiveProjectCard(subtreeIds, idSet, start, end);
    renderSubcatComparison(idSet, start, end);
    renderRetryImprovement(subtreeIds);
    renderWorstCards(subtreeIds);
    renderDeepDiveForecast(subtreeIds);
    renderDeepDiveTimeForecast(idSet);
}

function renderDeepDiveHero(subtreeIds, idSet, start, end){
    let played = 0, correct = 0;
    const dateSet = new Set();
    catDailyData.forEach(row => {
        if (idSet.has(row.cat)) {
            if (row.played > 0) dateSet.add(row.d);
            if (row.d >= start && row.d <= end) { played += row.played; correct += row.correct; }
        }
    });
    const streak = computeStreakFromDates(Array.from(dateSet));

    let total = 0, learnt = 0;
    subtreeIds.forEach(id => { total += catCardTotals[id] || 0; learnt += catLearnt[id] || 0; });

    document.getElementById('ddStreak').textContent = streak.current;
    document.getElementById('ddPlayed').textContent = played;
    document.getElementById('ddAccuracy').textContent = played > 0 ? Math.round((correct/played)*100) + '%' : '—';
    document.getElementById('ddMastery').textContent = learnt + '/' + total;
    document.getElementById('ddMasteryBar').style.width = (total > 0 ? Math.round((learnt/total)*100) : 0) + '%';

    // ---- tempo totale (nel periodo selezionato) ----
    let seconds = 0;
    catTimeDaily.forEach(row => {
        if (idSet.has(row.cat) && row.d >= start && row.d <= end) { seconds += row.seconds; }
    });
    animateCountText(document.getElementById('ddTime'), seconds, fmtDuration);

    // ---- velocità media + tendenza (su tutta la cronologia della materia, non solo il periodo:
    //      serve più storico possibile per un confronto vecchio/recente affidabile) ----
    const speed = computeCatSpeedStats(idSet);
    const speedEl = document.getElementById('ddSpeed');
    const trendEl = document.getElementById('ddSpeedTrend');
    if (speed) {
        speedEl.textContent = fmtSecPerCard(speed.recentAvg);
        if (speed.improvementPct === null) {
            trendEl.className = 'dd-hero-sub trend-flat'; trendEl.textContent = 'poche partite';
        } else if (speed.trend === 'up') {
            trendEl.className = 'dd-hero-sub trend-up'; trendEl.textContent = '▲ ' + speed.improvementPct + '% più veloce';
        } else if (speed.trend === 'down') {
            trendEl.className = 'dd-hero-sub trend-down'; trendEl.textContent = '▼ ' + Math.abs(speed.improvementPct) + '% più lento';
        } else {
            trendEl.className = 'dd-hero-sub trend-flat'; trendEl.textContent = 'stabile';
        }
    } else {
        speedEl.textContent = '—'; trendEl.className = 'dd-hero-sub trend-flat'; trendEl.textContent = '';
    }
}

function renderDeepDiveTrendChart(idSet, start, end){
    const byDate = {};
    catDailyData.forEach(row => {
        if (idSet.has(row.cat) && row.d >= start && row.d <= end) {
            if (!byDate[row.d]) byDate[row.d] = { played:0, correct:0 };
            byDate[row.d].played += row.played;
            byDate[row.d].correct += row.correct;
        }
    });
    const dates = Object.keys(byDate).sort();
    const ctx = document.getElementById('ddTrendChart');
    const emptyEl = document.getElementById('ddTrendEmpty');
    if (ddTrendChartInstance) ddTrendChartInstance.destroy();

    if (dates.length === 0) {
        emptyEl.style.display = 'block';
        ctx.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    ctx.style.display = 'block';

    ddTrendChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels: dates.map(d => d.slice(5)), datasets:[
            { label:'Testate', data: dates.map(d => byDate[d].played), borderColor:cBlue(), backgroundColor:hexToRgba(cBlue(),.15), fill:true, tension:.35, pointRadius:0, borderWidth:2.5 },
            { label:'Corrette', data: dates.map(d => byDate[d].correct), borderColor:cGreen(), backgroundColor:'transparent', fill:false, tension:.35, pointRadius:0, borderWidth:2 }
        ]},
        options:{
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ position:'bottom', labels:{ color:cText2(), boxWidth:10, font:{size:11} } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), maxTicksLimit:6, font:{size:10} } },
                y:{ beginAtZero:true, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

let ddSpeedChartInstance = null;
function renderDeepDiveSpeedChart(idSet){
    const pts = catSpeedPoints.filter(p => idSet.has(p.cat)).sort((a,b) => a.d < b.d ? -1 : (a.d > b.d ? 1 : 0));
    const card = document.getElementById('ddSpeedCard');
    if (ddSpeedChartInstance) ddSpeedChartInstance.destroy();

    if (pts.length < 3) { card.style.display = 'none'; return; }
    card.style.display = '';

    const ctx = document.getElementById('ddSpeedChart');
    ddSpeedChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels: pts.map(p => p.d.slice(5)), datasets:[
            { label:'Sec/carta', data: pts.map(p => p.spc), borderColor:cTeal(), backgroundColor:hexToRgba(cTeal(),.15), fill:true, tension:.35, pointRadius:0, borderWidth:2.5 }
        ]},
        options:{
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{
                title:(items) => 'Partita del ' + items[0].label,
                label:(c) => fmtSecPerCard(c.raw) + ' (' + pts[c.dataIndex].cards + ' carte)'
            } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), maxTicksLimit:6, font:{size:10} } },
                y:{ min:0, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10}, callback:(v)=>v+'s' } }
            }
        }
    });
}

// Card combinata flashcard + pomodoro, quando questa materia è collegata a un progetto
function renderDeepDiveProjectCard(subtreeIds, idSet, start, end){
    const card = document.getElementById('ddProjectCard');
    // il collegamento vive sulla categoria esatta selezionata, non si eredita dal ramo:
    // un progetto è legato a UNA materia precisa (rapporto 1 a 1)
    const link = catProjectLink[deepDiveCategoryId];
    if (!link) { card.style.display = 'none'; return; }
    card.style.display = '';

    let fcSeconds = 0;
    catTimeDaily.forEach(row => { if (idSet.has(row.cat) && row.d >= start && row.d <= end) fcSeconds += row.seconds; });
    const pomoSeconds = link.pomodoro_secondi || 0;
    const total = fcSeconds + pomoSeconds;
    const fcPct = total > 0 ? Math.round((fcSeconds/total)*100) : 0;
    const pomoPct = total > 0 ? 100 - fcPct : 0;

    document.getElementById('ddProjectSub').textContent = 'Flashcard (nel periodo) + progetto "' + link.progetto_nome + '" (da sempre)';
    document.getElementById('ddProjectFcBar').style.width = fcPct + '%';
    document.getElementById('ddProjectPomoBar').style.width = pomoPct + '%';
    animateCountText(document.getElementById('ddProjectFcNum'), fcSeconds, fmtDuration);
    animateCountText(document.getElementById('ddProjectPomoNum'), pomoSeconds, fmtDuration);
    document.getElementById('ddProjectPomoLabel').textContent = 'Pomodoro · ' + link.progetto_nome;
    animateCountText(document.getElementById('ddProjectTotalNum'), total, fmtDuration);
}

// "Durata prevista prossima partita": velocità recente della materia × dimensione tipica
// di una sua partita. Stessa filosofia semplice e trasparente delle altre previsioni qui.
function renderDeepDiveTimeForecast(idSet){
    const card = document.getElementById('ddTimeForecastCard');
    const speed = computeCatSpeedStats(idSet);
    let avgSize = 0, sizeN = 0;
    idSet.forEach(id => { if (catAvgGameSize[id]) { avgSize += catAvgGameSize[id]; sizeN++; } });
    if (!speed || speed.points.length < 3 || sizeN === 0) { card.style.display = 'none'; return; }
    card.style.display = '';
    avgSize = Math.round(avgSize / sizeN);
    const estSeconds = speed.recentAvg * avgSize;
    let trendPhrase = '';
    if (speed.improvementPct !== null && speed.trend === 'up') trendPhrase = `, il <b>${speed.improvementPct}% più veloce</b> rispetto a quando hai iniziato`;
    else if (speed.improvementPct !== null && speed.trend === 'down') trendPhrase = `, un po' più lento rispetto a quando hai iniziato`;
    document.getElementById('ddTimeForecastText').innerHTML =
        `Alla velocità attuale (~${fmtSecPerCard(speed.recentAvg)}${trendPhrase}), una prossima partita tipica da <b>${avgSize} carte</b> richiederà circa <b>${fmtDuration(estSeconds)}</b>`;
}

function renderSubcatComparison(idSet, start, end){
    const card = document.getElementById('ddSubcatCard');
    const list = document.getElementById('ddSubcatList');
    const children = catChildren[deepDiveCategoryId] || [];

    if (children.length === 0) {
        card.style.display = 'none';
        return;
    }
    card.style.display = 'block';
    list.innerHTML = '';

    const rows = children.map(cid => {
        const subIds = new Set(getSubtreeIds(cid));
        let played = 0, correct = 0, seconds = 0;
        catDailyData.forEach(row => {
            if (subIds.has(row.cat) && row.d >= start && row.d <= end) { played += row.played; correct += row.correct; }
        });
        catTimeDaily.forEach(row => {
            if (subIds.has(row.cat) && row.d >= start && row.d <= end) { seconds += row.seconds; }
        });
        return { id: cid, name: catById[cid].name, color: catById[cid].color || '#007AFF', played, correct, seconds };
    }).sort((a,b) => b.played - a.played);

    rows.forEach(r => {
        const accuracy = r.played > 0 ? Math.round((r.correct/r.played)*100) : null;
        let accHtml = `<span class="cat-acc-pill acc-none">—</span>`;
        if (accuracy !== null) {
            const accClass = accuracy >= 80 ? 'acc-good' : (accuracy >= 50 ? 'acc-mid' : 'acc-bad');
            accHtml = `<span class="cat-acc-pill ${accClass}">${accuracy}%</span>`;
        }
        const timeHtml = r.seconds > 0
            ? `<span class="cat-time-mini"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>${fmtDuration(r.seconds)}</span>`
            : '';
        const div = document.createElement('div');
        div.className = 'subcat-row';
        div.onclick = () => openCatDeepDive(r.id);
        div.innerHTML = `
            <span class="subcat-icon" style="background:color-mix(in srgb, ${r.color} 18%, var(--surface)); color:${r.color};">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            </span>
            <div class="subcat-info">
                <div class="subcat-name">${escapeHtml(r.name)}</div>
            </div>
            ${timeHtml}
            <span class="cat-played-num">${r.played}</span>
            ${accHtml}
            <span class="subcat-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
        `;
        list.appendChild(div);
    });
}

function renderRetryImprovement(subtreeIds){
    let opportunities = 0, fixed = 0;
    subtreeIds.forEach(id => {
        const r = retryStatsByCategory[id];
        if (r) { opportunities += r.opportunities; fixed += r.fixed; }
    });

    const pctEl = document.getElementById('ddRetryPct');
    const textEl = document.getElementById('ddRetryText');
    const ringFill = document.getElementById('ddRetryRingFill');

    if (opportunities < 3) {
        pctEl.textContent = '—';
        ringFill.setAttribute('stroke-dasharray', '0, 100');
        textEl.innerHTML = 'Non ci sono ancora abbastanza ripetizioni per calcolare questa statistica in modo affidabile.';
        return;
    }
    const pct = Math.round((fixed/opportunities)*100);
    pctEl.textContent = pct + '%';
    ringFill.setAttribute('stroke-dasharray', pct + ', 100');
    ringFill.style.stroke = pct >= 60 ? cGreen() : (pct >= 35 ? cOrange() : cRed());
    textEl.innerHTML = `Quando sbagli una carta in questa materia, la volta dopo la indovini nel <b>${pct}%</b> dei casi (su ${opportunities} occasioni osservate).`;
}

function renderWorstCards(subtreeIds){
    const idSet = new Set(subtreeIds);
    const card = document.getElementById('ddWorstCard');
    const list = document.getElementById('ddWorstList');

    const entries = cardStats.filter(cs => idSet.has(cs.cat) && cs.played >= 2).map(cs => ({
        card: cs.card, played: cs.played, wrong: cs.played - cs.correct,
        accuracy: Math.round((cs.correct/cs.played)*100)
    })).filter(e => e.wrong > 0).sort((a,b) => b.wrong - a.wrong).slice(0, 8);

    if (entries.length === 0) {
        card.style.display = 'none';
        return;
    }
    card.style.display = 'block';
    list.innerHTML = '';

    entries.forEach(e => {
        const info = cardInfo[e.card] || {};
        // Otteniamo il testo grezzo (o un fallback)
        const rawDesc = info.desc || 'Carta senza descrizione';
        const imgHtml = info.img
            ? `<img class="worst-card-thumb" src="${escapeHtml(info.img)}" onerror="this.outerHTML='<span class=&quot;worst-card-thumb-fallback&quot;><svg viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot;><path d=&quot;M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z&quot;/></svg></span>'">`
            : `<span class="worst-card-thumb-fallback"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>`;

        const div = document.createElement('div');
        div.className = 'worst-card-row';
        div.innerHTML = `
            ${imgHtml}
            <div class="worst-card-info">
                <div class="worst-card-desc raw-text-content" data-raw="${escapeHtml(rawDesc)}"></div>
                <div class="worst-card-meta">${e.played} tentativi · ${e.accuracy}% precisione</div>
            </div>
            <span class="worst-card-wrong">${e.wrong}×</span>
        `;
        list.appendChild(div);
    });

    // NUOVO: Finito di iniettare i div, chiediamo al parser di formattarli
    // (Questo processerà sia il testo colorato sia le eventuali formule di MathJax)
    if (typeof renderAllRawTexts === 'function') {
        renderAllRawTexts(list);
    }
}

function renderDeepDiveForecast(subtreeIds){
    const idSet = new Set(subtreeIds);
    let total = 0, learnt = 0;
    subtreeIds.forEach(id => { total += catCardTotals[id] || 0; learnt += catLearnt[id] || 0; });

    const byDate = {};
    catLearntDaily.forEach(row => {
        if (idSet.has(row.cat)) { byDate[row.d] = (byDate[row.d] || 0) + row.count; }
    });
    const trendArr = Object.keys(byDate).sort().map(d => ({ date: d, count: byDate[d] }));

    const textEl = document.getElementById('ddForecastText');
    if (total > 0 && learnt >= total) {
        textEl.innerHTML = 'Hai imparato tutte le carte di questa materia! Ottimo lavoro.';
    } else if (trendArr.length >= 2 && learnt > 0) {
        const firstDate = new Date(trendArr[0].date);
        const lastDate = new Date(trendArr[trendArr.length - 1].date);
        const daysSpan = Math.max(1, (lastDate - firstDate) / 86400000);
        const ratePerWeek = (learnt / daysSpan) * 7;
        const remaining = total - learnt;
        if (ratePerWeek > 0.05 && remaining > 0) {
            const weeksLeft = Math.round((remaining / ratePerWeek) * 10) / 10;
            const targetDate = new Date();
            targetDate.setDate(targetDate.getDate() + Math.round(weeksLeft * 7));
            const dd = String(targetDate.getDate()).padStart(2,'0');
            const mm = String(targetDate.getMonth()+1).padStart(2,'0');
            textEl.innerHTML = `Al ritmo attuale, finirai questa materia tra circa <b>${weeksLeft} settimane</b> (~${dd}/${mm}/${targetDate.getFullYear()})`;
        } else {
            textEl.innerHTML = 'Continua a giocare in questa materia per sbloccare una previsione.';
        }
    } else {
        textEl.innerHTML = 'Continua a giocare in questa materia per sbloccare una previsione.';
    }

    const ctx = document.getElementById('ddForecastChart');
    const emptyEl = document.getElementById('ddForecastEmpty');
    const { labels, hist, proj, histLen } = buildProjectionData(trendArr, total);
    if (ddForecastChartInstance) ddForecastChartInstance.destroy();

    if (histLen < 2 || total === 0) {
        emptyEl.style.display = 'block';
        ctx.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    ctx.style.display = 'block';

    const histSeries = labels.map((_, i) => i < histLen ? hist[i] : null);
    const projSeries = labels.map((_, i) => {
        if (i < histLen - 1) return null;
        if (i === histLen - 1) return hist[histLen - 1];
        return proj[i - histLen];
    });

    ddForecastChartInstance = new Chart(ctx, {
        type:'line',
        data:{ labels: labels.map(d => d.slice(5)), datasets:[
            { label:'Storico', data:histSeries, borderColor:cGreen(), backgroundColor:hexToRgba(cGreen(),.15), fill:true, tension:.3, pointRadius:0, borderWidth:2.5 },
            { label:'Proiezione', data:projSeries, borderColor:cGreen(), backgroundColor:'transparent', borderDash:[6,6], fill:false, tension:.3, pointRadius:0, borderWidth:2 }
        ]},
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'bottom', labels:{ color:cText2(), boxWidth:10, font:{size:11} } } },
            scales:{
                x:{ grid:{ display:false }, ticks:{ color:cText2(), font:{size:9}, maxTicksLimit:7 } },
                y:{ min:0, max:total, grid:{ color:cGrid() }, ticks:{ color:cText2(), font:{size:10} } }
            }
        }
    });
}

// ==========================================
// OBIETTIVO GIORNALIERO
// ==========================================
let goalSaveTimeout = null;
function updateGoalRing(){
    const pct = Math.min(100, Math.round((todayPlayedValue / dailyGoalValue) * 100));
    document.getElementById('goalRingFill').setAttribute('stroke-dasharray', pct + ', 100');
    document.getElementById('goalRingFill').style.stroke = pct >= 100 ? cGreen() : cBlue();
    document.getElementById('goalStepperValue').textContent = dailyGoalValue;
    document.querySelector('.goal-ring-sub').textContent = '/ ' + dailyGoalValue;
}

function adjustGoal(delta){
    dailyGoalValue = Math.max(5, dailyGoalValue + delta);
    updateGoalRing();
    clearTimeout(goalSaveTimeout);
    goalSaveTimeout = setTimeout(saveGoal, 500);
}

async function saveGoal(){
    try {
        const fd = new FormData();
        fd.append('action', 'set_goal');
        fd.append('user_id', currentUserId);
        fd.append('daily_goal', dailyGoalValue);
        const res = await fetch('stats_fc.php', { method:'POST', body:fd });
        await res.json();
        const note = document.getElementById('goalSavedNote');
        note.classList.add('show');
        setTimeout(() => note.classList.remove('show'), 1800);
    } catch(e) { console.error(e); }
}

// ==========================================
// LEADERBOARD ANIMATA
// ==========================================
function animateLeaderboard(){
    const bars = document.querySelectorAll('.lb-bar-fill');
    let maxVal = 1;
    bars.forEach(b => { maxVal = Math.max(maxVal, parseInt(b.dataset.target) || 0); });
    bars.forEach(b => {
        const target = parseInt(b.dataset.target) || 0;
        setTimeout(() => { b.style.width = Math.round((target/maxVal)*100) + '%'; }, 80);
    });
}

// ==========================================
// INIT
// ==========================================
window.onload = () => {
    renderCalendar();
    updateHeroStats();
    updateGoalRing();
    initTabCharts('activity');
};
</script>

</body>
</html>
