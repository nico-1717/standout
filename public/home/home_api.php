<?php
/* =====================================================================
   STANDOUT — API DELLA HOME
   Quello che la home non puo' chiedere agli endpoint che gia' esistono.
   Le spunte (attivita', abitudini, pastiglie, eventi) la home le manda ai
   loro endpoint di sempre: todo_api.php, habits.php, planner_api.php. Qui
   ci sono solo le cose nuove:

     home_api.php?ajax=summary       (GET)  agenda, attivita', nota, abitudini, voti, flashcard,
                                            date importanti. ?g=agenda,fc,... ne chiede solo
                                            alcuni gruppi (la home carica prima quelli in vista)
     home_api.php?ajax=layout_save   (POST) quali riquadri mostrare e in che ordine
     home_api.php?ajax=ai_usage      (GET)  il piano e i consumi del mese (popup dell'avatar)
     home_api.php?ajax=draft_add     (POST) una flashcard "da completare" (solo il fronte)
     home_api.php?ajax=draft_finish  (POST) la completa: diventa una carta vera in un mazzo
     home_api.php?ajax=draft_delete  (POST) la butta

   Perche' un solo "summary": la home mostra una decina di riquadri, e
   dieci richieste in fila su un telefono lento sono dieci attese. Una
   sola risposta si salva anche in localStorage, cosi' senza rete si vede
   l'ultima fotografia invece di una pagina vuota.

   Le due tabelle nuove (home_layout, fc_quick_drafts) si creano da sole
   alla prima richiesta, come fa piano_api.php: se la migrazione SQL non e'
   ancora stata eseguita a mano il deploy non rompe niente. Niente chiavi
   esterne: sec_q() si ferma sugli errori SQL, e un vincolo che fallisce
   per una riga orfana bloccherebbe l'intera home.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/planner/planner_lib.php';
require_once __DIR__ . '/../../lib/fc/fc_summary.php';
require_once __DIR__ . '/../../lib/fc/fc_ripresa.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'home', 0, 300, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';
/* Solo le letture possono arrivare con GET: il resto è POST, dove il token CSRF
   viene davvero controllato (vedi sec_solo_post_se_scrive in security_core.php). */
sec_solo_post_se_scrive($ajax, ['summary', 'ai_usage']);

/* I riquadri che esistono, nell'ordine in cui compaiono la prima volta.
   Un id sconosciuto arrivato dal client si scarta: il layout e' dell'utente
   ma l'elenco dei riquadri e' del codice. */
/* «tasks» e «habits» non sono piu' riquadri: attivita', acquisti, pastiglie e l'anello
   delle abitudini vivono dentro «agenda» (Oggi); le abitudini complete si aprono in un
   popup. I gruppi di dati con quei nomi esistono ancora (li chiede «agenda»), e un
   layout salvato che li nomina ancora li perde da solo (vedi ho_layout). */
/* L'ordine qui e' quello standard (lo stesso di ORDINE in home_core.js). */
const HO_RIQUADRI = ['shortcuts', 'agenda', 'note', 'cards', 'projects', 'grades', 'trivia', 'stats'];

function ho_tabelle(): void {
    static $fatto = false;
    if ($fatto) return;
    sec_q("CREATE TABLE IF NOT EXISTS home_layout (
             user_id INT NOT NULL,
             layout TEXT NOT NULL,
             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (user_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    sec_q("CREATE TABLE IF NOT EXISTS fc_quick_drafts (
             id INT UNSIGNED NOT NULL AUTO_INCREMENT,
             user_id INT NOT NULL,
             front VARCHAR(500) NOT NULL,
             back VARCHAR(2000) NOT NULL DEFAULT '',
             deck_id INT UNSIGNED DEFAULT NULL,
             created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             KEY idx_user (user_id, created_at)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fatto = true;
}

/* L'ordine dei tasti dell'accesso rapido (gli href delle pagine), salvato dentro la
   voce «shortcuts» del layout. Si tengono solo stringhe corte e sicure: l'elenco
   vero dei tasti e' del codice (dashboard_content.php), qui si salva solo l'ordine. */
function ho_ordine_scorciatoie($v): array {
    $o = [];
    if (is_array($v)) {
        foreach ($v as $h) {
            if (is_string($h) && preg_match('~^[A-Za-z0-9_/.\-]{1,60}$~', $h) && !in_array($h, $o, true)) $o[] = $h;
            if (count($o) >= 20) break;
        }
    }
    return $o;
}

/** Il layout salvato, ripulito e completato: ogni riquadro noto compare una volta sola. */
function ho_layout(int $uid): array {
    $r = sec_one("SELECT layout FROM home_layout WHERE user_id = ?", [$uid], 'i');
    $salvato = $r ? json_decode((string)$r['layout'], true) : null;
    $out = []; $visti = [];
    if (is_array($salvato)) {
        foreach ($salvato as $v) {
            $id = is_array($v) ? (string)($v['id'] ?? '') : '';
            if (!in_array($id, HO_RIQUADRI, true) || isset($visti[$id])) continue;
            $visti[$id] = true;
            $voce = ['id' => $id, 'on' => !empty($v['on'])];
            if ($id === 'shortcuts') { $ord = ho_ordine_scorciatoie($v['order'] ?? null); if ($ord) $voce['order'] = $ord; }
            $out[] = $voce;
        }
    }
    /* un riquadro aggiunto da un aggiornamento compare in fondo, acceso:
       chi ha gia' personalizzato la home non lo trova nascosto a sua insaputa */
    foreach (HO_RIQUADRI as $id) { if (!isset($visti[$id])) $out[] = ['id' => $id, 'on' => true]; }
    return $out;
}

/* ---------------------------------------------------------------------
   Mazzi di studio come elenco piatto con il percorso («Anatomia › Cuore»)
   --------------------------------------------------------------------- */
function ho_mazzi(int $uid): array {
    $rows = sec_all("SELECT id, name, parent_id, color FROM fc_study_decks WHERE user_id = ? AND archived = 0 ORDER BY name ASC", [$uid], 'i');
    $per = [];
    foreach ($rows as $r) { $per[(int)$r['id']] = $r; }
    $out = [];
    foreach ($per as $id => $r) {
        $nomi = [$r['name']]; $p = (int)$r['parent_id']; $giri = 0;
        while ($p > 0 && isset($per[$p]) && $giri++ < 8) { array_unshift($nomi, $per[$p]['name']); $p = (int)$per[$p]['parent_id']; }
        $out[] = ['id' => $id, 'name' => $r['name'], 'path' => implode(' › ', $nomi), 'color' => $r['color']];
    }
    usort($out, function ($a, $b) { return strcasecmp($a['path'], $b['path']); });
    return $out;
}

/* ---------------------------------------------------------------------
   Voti: media ponderata sui CFU, avanzamento, ultimi esami, prossimo esame
   --------------------------------------------------------------------- */
function ho_voti(int $uid, string $oggi): array {
    try {
        $imp = sec_one("SELECT total_cfu, honors_value, target_average, program_label FROM university_settings WHERE user_id = ?", [$uid], 'i');
        $esami = sec_all("SELECT id, name, cfu, status, grade, has_honors, is_pass_fail, counts_for_graduation, exam_date
                            FROM university_exams WHERE user_id = ? ORDER BY exam_date ASC, id ASC", [$uid], 'i');
    } catch (Throwable $e) {
        return ['has' => false];
    }
    if (!$esami && !$imp) return ['has' => false];

    $lode = $imp ? (float)$imp['honors_value'] : 30.0;
    $cfuTot = $imp ? (int)$imp['total_cfu'] : 180;
    $somma = 0.0; $pesi = 0.0; $cfuFatti = 0.0; $fatti = 0; $ultimi = [];
    foreach ($esami as $e) {
        if ($e['status'] !== 'done') continue;
        $fatti++;
        if ((int)$e['counts_for_graduation'] === 1) $cfuFatti += (float)$e['cfu'];
        if ((int)$e['is_pass_fail'] === 1 || $e['grade'] === null) continue;
        $v = (int)$e['has_honors'] === 1 ? $lode : (float)$e['grade'];
        $somma += $v * (float)$e['cfu']; $pesi += (float)$e['cfu'];
        $ultimi[] = ['name' => $e['name'], 'grade' => (int)$e['grade'], 'honors' => (int)$e['has_honors'] === 1, 'cfu' => (float)$e['cfu'], 'date' => $e['exam_date']];
    }
    $prossimo = null;
    try {
        $p = sec_one("SELECT e.event_date, x.name FROM planner_events e
                        JOIN university_exams x ON x.id = e.study_exam_id AND x.user_id = e.user_id
                       WHERE e.user_id = ? AND e.event_date >= ? AND x.status <> 'done'
                       ORDER BY e.event_date ASC, e.start_time ASC LIMIT 1", [$uid, $oggi], 'is');
        if ($p) $prossimo = ['name' => $p['name'], 'date' => $p['event_date'], 'days' => (int)round((strtotime($p['event_date']) - strtotime($oggi)) / 86400)];
    } catch (Throwable $e) { /* senza il collegamento agli eventi si mostra e basta la media */ }

    return [
        'has' => true,
        'avg' => $pesi > 0 ? round($somma / $pesi, 2) : null,
        'target' => ($imp && $imp['target_average'] !== null) ? (float)$imp['target_average'] : null,
        'cfu_done' => round($cfuFatti, 1), 'cfu_total' => $cfuTot,
        'exams_done' => $fatti, 'exams_total' => count($esami),
        'last' => array_slice($ultimi, -6),
        'next' => $prossimo,
        'program' => $imp['program_label'] ?? null,
    ];
}

/* ---------------------------------------------------------------------
   Flashcard: il mazzo da giocare adesso
   --------------------------------------------------------------------- */

/** Il mazzo da giocare adesso: prima quello con l'esame piu' vicino, poi il meno ripassato. */
function ho_mazzo_prossimo(int $uid, string $oggi): ?array {
    $ultime = [];
    foreach (sec_all("SELECT c.category_id AS d, MAX(p.play_date) AS u
                        FROM fc_study_plays p
                        JOIN fc_games g ON g.id = p.game_id
                        JOIN fc_study_cards c ON c.id = p.fc_image_id
                       WHERE g.user_id = ? AND p.user_answer IN ('Correct','Wrong')
                       GROUP BY c.category_id", [$uid], 'i') as $r) {
        $ultime[(int)$r['d']] = (string)$r['u'];
    }
    $mazzi = sec_all("SELECT d.id, d.name, d.color, d.exam_date,
                             (SELECT COUNT(*) FROM fc_study_cards c WHERE c.category_id = d.id AND c.is_father = 0) AS n
                        FROM fc_study_decks d
                       WHERE d.user_id = ? AND d.archived = 0", [$uid], 'i');
    $scelto = null; $punti = null;
    foreach ($mazzi as $m) {
        if ((int)$m['n'] < 1) continue;
        $ultima = $ultime[(int)$m['id']] ?? '';
        $giorniFa = $ultima === '' ? 999 : (int)floor((strtotime($oggi) - strtotime(substr($ultima, 0, 10))) / 86400);
        if ($giorniFa < 1) continue;   // gia' giocato oggi: non serve riproporlo
        $esame = ($m['exam_date'] && $m['exam_date'] >= $oggi) ? (int)round((strtotime($m['exam_date']) - strtotime($oggi)) / 86400) : null;
        /* punteggio piu' alto = piu' urgente: l'esame vicino pesa molto, poi i giorni dall'ultimo ripasso */
        $p = $giorniFa + ($esame !== null ? max(0, 60 - $esame) * 3 : 0);
        if ($punti === null || $p > $punti) {
            $punti = $p;
            $scelto = ['id' => (int)$m['id'], 'name' => $m['name'], 'color' => $m['color'], 'cards' => (int)$m['n'],
                       'days_ago' => $ultima === '' ? null : $giorniFa, 'exam_days' => $esame];
        }
    }
    return $scelto;
}

function ho_flashcard(int $uid, string $oggi): array {
    $r = fc_riepilogo($uid);
    $tot = (int)$r['study']['carte'] + (int)$r['vocab']['parole'];
    $imp = (int)$r['study']['imparate'] + (int)$r['vocab']['imparate'];
    ho_tabelle();
    $bozze = sec_all("SELECT id, front, back, deck_id, created_at FROM fc_quick_drafts WHERE user_id = ? ORDER BY created_at DESC LIMIT 12", [$uid], 'i');
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_quick_drafts WHERE user_id = ?", [$uid], 'i')['n'] ?? 0);
    return [
        'streak' => (int)$r['serie'], 'record' => (int)$r['record'],
        'today' => (int)$r['oggi'], 'goal' => (int)$r['obiettivo'],
        'accuracy' => (int)$r['precisione'],
        'week' => $r['settimana'],
        'mastery' => $tot > 0 ? min(100, (int)round($imp / $tot * 100)) : 0,
        'cards_total' => $tot,
        /* una partita a meta' per tipo (studio, lingue) e il corso su cui «Gioca» porta */
        'unfinished' => ['study' => fc_partita_in_sospeso($uid, 'study'), 'vocab' => fc_partita_in_sospeso($uid, 'vocab')],
        'course' => ($c = fc_corso_da_giocare($uid)) ? ['id' => $c['id'], 'name' => fc_lingua_nome($c['learn_lang'], false), 'flag' => fc_lingua_bandiera($c['learn_lang'])] : null,
        'next' => ho_mazzo_prossimo($uid, $oggi),
        'drafts' => array_map(function ($b) {
            return ['id' => (int)$b['id'], 'front' => $b['front'], 'back' => $b['back'],
                    'deck_id' => $b['deck_id'] !== null ? (int)$b['deck_id'] : null, 'at' => $b['created_at']];
        }, $bozze),
        'draft_count' => $n,
        'decks' => ho_mazzi($uid),
    ];
}

/* ---------------------------------------------------------------------
   Date importanti: eventi segnati importanti, esami dei progetti, esami dei mazzi
   --------------------------------------------------------------------- */
function ho_importanti(int $uid, string $oggi): array {
    $lista = [];
    $giorni = function (string $d) use ($oggi) { return (int)round((strtotime($d) - strtotime($oggi)) / 86400); };
    foreach (pl_events_range($uid, $oggi, pl_add_days($oggi, 60)) as $e) {
        /* solo quelli segnati importanti: gli altri sono già nell'agenda del giorno */
        if (($e['importance'] ?? 'normal') === 'normal') continue;
        if ($e['date'] < $oggi) continue;
        $lista[] = ['kind' => 'event', 'title' => $e['title'], 'date' => $e['date'], 'time' => $e['all_day'] ? '' : $e['start'],
                    'days' => $giorni($e['date']), 'color' => $e['color'] ?: ($e['project_color'] ?: null), 'strong' => $e['importance'] === 'highest'];
    }
    foreach (sec_all("SELECT g.deadline, p.name, p.color FROM plan_goals g
                        JOIN projects p ON p.id = g.project_id AND p.user_id = g.user_id
                       WHERE g.user_id = ? AND g.active = 1 AND g.kind = 'exam' AND g.deadline >= ? AND p.parent_id IS NULL
                       ORDER BY g.deadline ASC LIMIT 6", [$uid, $oggi], 'is') as $r) {
        $lista[] = ['kind' => 'exam', 'title' => $r['name'], 'date' => $r['deadline'], 'time' => '', 'days' => $giorni($r['deadline']), 'color' => $r['color'], 'strong' => true];
    }
    foreach (sec_all("SELECT name, exam_date, color FROM fc_study_decks WHERE user_id = ? AND archived = 0 AND exam_date >= ? ORDER BY exam_date ASC LIMIT 6", [$uid, $oggi], 'is') as $r) {
        $lista[] = ['kind' => 'deck', 'title' => $r['name'], 'date' => $r['exam_date'], 'time' => '', 'days' => $giorni($r['exam_date']), 'color' => $r['color'], 'strong' => true];
    }
    /* lo stesso esame arriva da piu' parti (progetto, mazzo, evento): una riga sola per titolo e giorno */
    $viste = []; $uniche = [];
    usort($lista, function ($a, $b) { return strcmp($a['date'] . ($a['time'] ?? ''), $b['date'] . ($b['time'] ?? '')); });
    foreach ($lista as $x) {
        $k = mb_strtolower($x['title']) . '|' . $x['date'];
        if (isset($viste[$k])) continue;
        $viste[$k] = true; $uniche[] = $x;
    }
    return array_slice($uniche, 0, 6);
}


/* ---------------------------------------------------------------------
   Abitudini di oggi, divise per ritmo: giornaliere, settimanali, mensili,
   ogni N giorni. Le stesse famiglie della pagina Abitudini, cosi' la home
   non inventa un modo suo di contarle:
     - giornaliere / a intervallo: contano solo nei giorni in cui sono previste
     - settimanali: «n volte a settimana», si conta da lunedi' a oggi
     - mensili: «n volte al mese», dal primo del mese a oggi
   Una spunta e' sempre quella di OGGI (log_set con mode=toggle): per le
   settimanali e le mensili vuol dire «oggi l'ho fatta», e il conto sale. Le
   abitudini «da evitare» (quit) non compaiono: non si spuntano, si resiste.
   --------------------------------------------------------------------- */
function ho_abitudini(int $uid, string $oggi): array {
    $lun = pl_monday($oggi);
    $mese1 = substr($oggi, 0, 7) . '-01';
    $da = min($lun, $mese1);
    $hs = sec_all("SELECT id, name, icon, color, goal_type, target_amount, frequency_type, weekdays, interval_days,
                          times_per_week, times_per_month, start_date, end_date, is_archived
                     FROM habits WHERE user_id = ? AND habit_kind = 'build' AND is_archived = 0
                    ORDER BY sort_order, id", [$uid], 'i');
    if (!$hs) return [];
    $per = [];
    foreach (sec_all("SELECT habit_id, log_date, status, amount, target_amount FROM habit_logs
                       WHERE user_id = ? AND log_date BETWEEN ? AND ?", [$uid, $da, $oggi], 'iss') as $l) {
        $t = (float)$l['target_amount'];
        $per[(int)$l['habit_id']][$l['log_date']] = [
            'ok' => $l['status'] === 'done' || ($t > 0 && (float)$l['amount'] >= $t),
            'part' => $t > 0 ? min(1.0, (float)$l['amount'] / $t) : 0.0,
        ];
    }
    $gruppi = ['daily' => [], 'weekly' => [], 'monthly' => [], 'interval' => []];
    foreach ($hs as $h) {
        $suoi = $per[(int)$h['id']] ?? [];
        $oggiLog = $suoi[$oggi] ?? null;
        $fatta = $oggiLog && $oggiLog['ok'];
        $item = ['id' => (int)$h['id'], 'name' => $h['name'], 'icon' => $h['icon'], 'color' => $h['color'],
                 'done' => (bool)$fatta, 'part' => $fatta ? 1.0 : ($oggiLog ? round($oggiLog['part'], 2) : 0.0)];
        $tipo = $h['frequency_type'];
        if ($tipo === 'daily' || $tipo === 'interval') {
            if (!pl_habit_due($h, $oggi)) continue;
            $gruppi[$tipo][] = $item;
        } elseif ($tipo === 'weekly' || $tipo === 'monthly') {
            if ($h['start_date'] > $oggi) continue;
            $inizio = $tipo === 'weekly' ? $lun : $mese1;
            if (!empty($h['end_date']) && $h['end_date'] < $inizio) continue;
            $n = 0;
            foreach ($suoi as $d => $x) { if ($x['ok'] && $d >= $inizio) $n++; }
            $item['n'] = $n;
            $item['target'] = max(1, (int)($tipo === 'weekly' ? $h['times_per_week'] : $h['times_per_month']));
            $gruppi[$tipo][] = $item;
        }
    }
    $out = [];
    foreach ($gruppi as $k => $items) { if ($items) $out[] = ['key' => $k, 'items' => $items]; }
    return $out;
}

/* ---------------------------------------------------------------------
   summary
   --------------------------------------------------------------------- */
if ($ajax === 'summary') {
    ho_tabelle();
    $oggi = date('Y-m-d');
    $domani = pl_add_days($oggi, 1);

    /* PER GRUPPI (26/10/2026). La home chiede prima i dati dei due riquadri
       che si vedono subito (?g=agenda,important) e poi, in sottofondo, tutto il
       resto: cosi' la parte alta compare completa senza aspettare le query
       dei riquadri piu' in basso. Senza ?g= risponde con tutto, come prima.
       La lista dei gruppi e' del codice: un nome sconosciuto si scarta. */
    $gruppi_ok = ['agenda', 'important', 'tasks', 'note', 'habits', 'grades', 'fc'];
    $chiesti = isset($_GET['g'])
        ? array_values(array_intersect($gruppi_ok, array_map('trim', explode(',', sec_str($_GET['g'], 120)))))
        : $gruppi_ok;
    $vuole = function (string $k) use ($chiesti): bool { return in_array($k, $chiesti, true); };

    $out = [
        'ok' => true, 'today' => $oggi, 'now' => date('H:i'), 'name' => $utente_corrente['username'] ?? '',
        'layout' => ho_layout($uid),
    ];

    /* agenda di oggi e domani: i campi servono a disegnare la riga e a spuntarla */
    if ($vuole('agenda')) {
        $agenda = [];
        foreach (pl_events_range($uid, $oggi, $domani) as $e) {
            $agenda[] = [
                'id' => $e['id'], 'uid' => $e['uid'], 'title' => $e['title'], 'date' => $e['date'], 'end_date' => $e['end_date'],
                'start' => $e['start'], 'end' => $e['end'], 'all_day' => $e['all_day'], 'done' => $e['done'],
                'color' => $e['color'] ?: ($e['habit_color'] ?: ($e['project_color'] ?: null)),
                'project' => $e['project_name'], 'habit' => $e['habit_id'] !== null, 'icon' => $e['habit_icon'],
                'type' => $e['type'], 'importance' => $e['importance'], 'place' => $e['location'] ?: ($e['place_name'] ?: ''),
                'occurrence' => (bool)$e['is_occurrence'],
            ];
        }
        $out['agenda'] = $agenda;
    }

    if ($vuole('important')) $out['important'] = ho_importanti($uid, $oggi);

    if ($vuole('tasks')) {
        $todo = sec_all("SELECT t.id, t.title, t.due_date, t.due_time, t.project_id, p.name AS project, p.color AS project_color
                           FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
                          WHERE t.user_id = ? AND t.list_type = 'todo' AND t.is_done = 0 AND t.parent_id IS NULL
                          ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC,
                                   CASE WHEN t.due_time IS NULL THEN 1 ELSE 0 END, t.due_time ASC, t.sort_order ASC, t.id ASC
                          LIMIT 40", [$uid], 'i');
        $nTodo = (int)(sec_one("SELECT COUNT(*) AS n FROM todos WHERE user_id = ? AND list_type = 'todo' AND is_done = 0 AND parent_id IS NULL", [$uid], 'i')['n'] ?? 0);
        $fattiOggi = (int)(sec_one("SELECT COUNT(*) AS n FROM todos WHERE user_id = ? AND list_type = 'todo' AND is_done = 1 AND DATE(done_at) = ?", [$uid, $oggi], 'is')['n'] ?? 0);
        $buy = sec_all("SELECT t.id, t.title, t.buy_priority, t.due_date, c.name AS cat, c.color AS cat_color
                          FROM todos t LEFT JOIN todo_buy_categories c ON c.id = t.buy_category_id AND c.user_id = t.user_id
                         WHERE t.user_id = ? AND t.list_type = 'buy' AND t.is_done = 0 AND t.parent_id IS NULL
                         ORDER BY t.buy_priority ASC, t.sort_order ASC, t.id ASC LIMIT 40", [$uid], 'i');
        $nBuy = (int)(sec_one("SELECT COUNT(*) AS n FROM todos WHERE user_id = ? AND list_type = 'buy' AND is_done = 0 AND parent_id IS NULL", [$uid], 'i')['n'] ?? 0);
        $out['todo'] = array_map(function ($t) {
            return ['id' => (int)$t['id'], 'title' => $t['title'], 'date' => $t['due_date'], 'time' => $t['due_time'] ? substr($t['due_time'], 0, 5) : null,
                    'project' => $t['project'], 'color' => $t['project_color']];
        }, $todo);
        $out['todo_open'] = $nTodo;
        $out['todo_done_today'] = $fattiOggi;
        $out['buy'] = array_map(function ($t) {
            return ['id' => (int)$t['id'], 'title' => $t['title'], 'priority' => (int)$t['buy_priority'], 'date' => $t['due_date'], 'cat' => $t['cat'], 'color' => $t['cat_color']];
        }, $buy);
        $out['buy_open'] = $nBuy;
    }

    if ($vuole('note')) {
        $nota = '';
        try {
            $r = sec_one("SELECT body FROM todo_notes WHERE user_id = ?", [$uid], 'i');
            $nota = $r ? (string)$r['body'] : '';
        } catch (Throwable $e) { /* tabella non ancora creata: la crea la pagina To do alla prima nota */ }
        $out['note'] = $nota;
    }

    if ($vuole('habits')) $out['habit_groups'] = ho_abitudini($uid, $oggi);
    if ($vuole('grades')) $out['grades'] = ho_voti($uid, $oggi);
    if ($vuole('fc'))     $out['fc'] = ho_flashcard($uid, $oggi);

    sec_json($out);
}

/* ---------------------------------------------------------------------
   layout_save
   --------------------------------------------------------------------- */
if ($ajax === 'layout_save') {
    ho_tabelle();
    $in = sec_in('layout');
    if (!is_array($in)) sec_die_json(t('home.api_dati_non_validi'), 400, 'bad_request');
    $pulito = []; $visti = [];
    foreach ($in as $v) {
        $id = is_array($v) ? (string)($v['id'] ?? '') : '';
        if (!in_array($id, HO_RIQUADRI, true) || isset($visti[$id])) continue;
        $visti[$id] = true;
        $voce = ['id' => $id, 'on' => !empty($v['on'])];
        if ($id === 'shortcuts') { $ord = ho_ordine_scorciatoie($v['order'] ?? null); if ($ord) $voce['order'] = $ord; }
        $pulito[] = $voce;
    }
    foreach (HO_RIQUADRI as $id) { if (!isset($visti[$id])) $pulito[] = ['id' => $id, 'on' => true]; }
    sec_q("INSERT INTO home_layout (user_id, layout) VALUES (?, ?) ON DUPLICATE KEY UPDATE layout = VALUES(layout)",
          [$uid, json_encode($pulito)], 'is');
    sec_json(['ok' => true, 'layout' => $pulito]);
}

/* ---------------------------------------------------------------------
   ai_usage: si legge solo quando si apre il popup dell'avatar, cosi' le
   pagine non pagano una query in piu' a ogni caricamento.
   --------------------------------------------------------------------- */
if ($ajax === 'ai_usage') {
    $p = standout_get_piano_utente($uid);
    if (!$p) sec_json(['ok' => true, 'plan' => null]);
    $voce = function ($usato, $limite) {
        return ['used' => (int)$usato, 'limit' => $limite === null ? null : (int)$limite];
    };
    sec_json(['ok' => true, 'plan' => [
        'name' => ucfirst((string)$p['name']),
        'resets' => date('Y-m-01', strtotime('first day of next month')),
        'audio' => $voce($p['monthly_audio_chars'], $p['audio_chars_limit']),
        'images' => $voce($p['monthly_generated_images'], $p['images_limit']),
        'tokens' => $voce($p['monthly_text_tokens'], $p['text_tokens_limit']),
    ]]);
}

/* ---------------------------------------------------------------------
   bozze flashcard
   --------------------------------------------------------------------- */
if ($ajax === 'draft_add') {
    ho_tabelle();
    $front = sec_str(sec_in('front'), 500);
    if ($front === '') sec_die_json(t('home.api_scrivi_qualcosa'), 400, 'empty');
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_quick_drafts WHERE user_id = ?", [$uid], 'i')['n'] ?? 0);
    if ($n >= 200) sec_die_json(t('home.api_troppe_bozze'), 400, 'limit');
    $back = sec_str(sec_in('back', ''), 2000);
    sec_q("INSERT INTO fc_quick_drafts (user_id, front, back) VALUES (?, ?, ?)", [$uid, $front, $back], 'iss');
    sec_json(['ok' => true, 'id' => sec_insert_id(), 'count' => $n + 1]);
}

if ($ajax === 'draft_delete') {
    ho_tabelle();
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    sec_q("DELETE FROM fc_quick_drafts WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true]);
}

/* La bozza diventa una carta vera. Nel mazzo scelto, in fondo (custom_order
   = massimo + 1, come fa fc_insert_save.php per le carte nuove). Se il mazzo
   non e' dell'utente non si scrive niente: il client non e' mai una garanzia. */
if ($ajax === 'draft_finish') {
    ho_tabelle();
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    $b = sec_one("SELECT id, front FROM fc_quick_drafts WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    if (!$b) sec_die_json(t('home.api_bozza_non_trovata'), 404, 'not_found');
    $front = sec_str(sec_in('front', $b['front']), 500);
    $back = sec_text(sec_in('back', ''), 2000);
    $deck = sec_int(sec_in('deck_id'), 0, PHP_INT_MAX, 0);
    if ($front === '' || trim($back) === '') sec_die_json(t('home.api_manca_risposta'), 400, 'incomplete');
    if ($deck < 1 || !sec_owns('fc_study_decks', $deck, $uid)) sec_die_json(t('home.api_scegli_mazzo'), 400, 'no_deck');
    $ord = (int)(sec_one("SELECT COALESCE(MAX(custom_order), 0) AS m FROM fc_study_cards WHERE category_id = ?", [$deck], 'i')['m'] ?? 0) + 1;
    sec_q("INSERT INTO fc_study_cards (category_id, question_desc, answer_desc, is_father, custom_order) VALUES (?, ?, ?, 0, ?)",
          [$deck, $front, $back, $ord], 'issi');
    sec_q("DELETE FROM fc_quick_drafts WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_quick_drafts WHERE user_id = ?", [$uid], 'i')['n'] ?? 0);
    sec_json(['ok' => true, 'count' => $n]);
}

sec_die_json(t('home.api_azione_sconosciuta'), 400, 'bad_request');
