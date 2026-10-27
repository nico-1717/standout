<?php
/* =====================================================================
   STANDOUT — API DEL PIANO (calendario dei progetti)
   Sostituisce il vecchio «to study»: invece di contare pomodori per
   argomento, ragiona in MINUTI su tutto l'albero dei progetti, dal
   grande obiettivo (un esame, l'inglese) fino all'ultima voce.

     piano_api.php?ajax=tree         (GET)  progetto + discendenti, obiettivi, giorni, fatto
     piano_api.php?ajax=goal_save    (POST) tempo, tipo, priorità, scadenza di un progetto
     piano_api.php?ajax=split_save   (POST) divide le ore di un progetto fra i figli (con lucchetti)
     piano_api.php?ajax=paint        (POST) evidenziatore: segna / toglie giorni di un progetto
     piano_api.php?ajax=day_set      (POST) minuti di un progetto in un giorno, a mano
     piano_api.php?ajax=autoplan     (POST) «pianifica per me»: anteprima o applica
     piano_api.php?ajax=master       (GET)  tutti gli obiettivi contro il tempo libero
     piano_api.php?ajax=today        (GET)  cosa fare oggi, cosa è saltato, la settimana
     piano_api.php?ajax=skip_fix     (POST) sposta o lascia ciò che ieri non è stato fatto
     piano_api.php?ajax=settings_get / settings_save
     piano_api.php?ajax=import_old   (POST) porta i pomodori stimati del vecchio piano
     piano_api.php?ajax=tpl_list / tpl_save / tpl_apply / tpl_delete

   Perché i minuti: «100 ore» si spezza fra i figli senza resti strani, e
   il pomodoro resta solo il modo in cui si misura ciò che è stato fatto.

   Le tabelle si creano da sole alla prima richiesta (CREATE TABLE IF NOT
   EXISTS): così il deploy non rompe niente se la migrazione SQL non è
   ancora stata eseguita a mano.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'piano', 0, 400, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';
/* Solo le letture possono arrivare con GET: il resto è POST, dove il token CSRF
   viene davvero controllato (vedi sec_solo_post_se_scrive in security_core.php). */
sec_solo_post_se_scrive($ajax, ['tree', 'master', 'today', 'settings_get', 'tpl_list']);

const PN_KINDS = ['plain', 'exam', 'steady', 'course', 'count'];
const PN_PRIO  = ['must', 'should', 'nice'];

/* ---------------------------------------------------------------------
   Tabelle
   --------------------------------------------------------------------- */
function pn_tabelle(): void {
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    sec_q("CREATE TABLE IF NOT EXISTS plan_goals (
             user_id INT NOT NULL,
             project_id INT NOT NULL,
             active TINYINT(1) NOT NULL DEFAULT 1,
             minutes INT NOT NULL DEFAULT 0,
             locked TINYINT(1) NOT NULL DEFAULT 0,
             kind VARCHAR(10) NOT NULL DEFAULT 'plain',
             priority VARCHAR(10) NOT NULL DEFAULT 'should',
             start_date DATE NULL,
             deadline DATE NULL,
             since DATE NULL,
             items INT NULL,
             unit_minutes INT NULL,
             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (user_id, project_id)
           )" . $tail);
    sec_q("CREATE TABLE IF NOT EXISTS plan_paint (
             user_id INT NOT NULL,
             project_id INT NOT NULL,
             day DATE NOT NULL,
             PRIMARY KEY (user_id, project_id, day),
             KEY idx_day (user_id, day)
           )" . $tail);
    sec_q("CREATE TABLE IF NOT EXISTS plan_days (
             user_id INT NOT NULL,
             day DATE NOT NULL,
             project_id INT NOT NULL,
             kind VARCHAR(8) NOT NULL DEFAULT 'study',
             minutes INT NOT NULL DEFAULT 0,
             source VARCHAR(8) NOT NULL DEFAULT 'auto',
             PRIMARY KEY (user_id, day, project_id, kind),
             KEY idx_proj (user_id, project_id)
           )" . $tail);
    sec_q("CREATE TABLE IF NOT EXISTS plan_settings (
             user_id INT NOT NULL,
             weekday_min INT NULL,
             weekend_min INT NULL,
             rest_dow TINYINT NOT NULL DEFAULT 0,
             buffer_days TINYINT NOT NULL DEFAULT 0,
             floor_min SMALLINT NOT NULL DEFAULT 15,
             max_subjects TINYINT NOT NULL DEFAULT 3,
             reviews TINYINT(1) NOT NULL DEFAULT 0,
             overrides TEXT NULL,
             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (user_id)
           )" . $tail);
    sec_q("CREATE TABLE IF NOT EXISTS plan_templates (
             id INT UNSIGNED NOT NULL AUTO_INCREMENT,
             user_id INT NOT NULL,
             name VARCHAR(80) NOT NULL,
             body TEXT NOT NULL,
             created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             KEY idx_user (user_id)
           )" . $tail);
}
pn_tabelle();

/* ---------------------------------------------------------------------
   Date: tutto passa da stringhe YYYY-MM-DD, così un cambio d'ora non
   sposta mai un giorno (si somma sempre a mezzogiorno).
   --------------------------------------------------------------------- */
function pn_oggi(): string { return date('Y-m-d'); }
function pn_piu(string $d, int $n): string { return date('Y-m-d', strtotime($d . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' day')); }
function pn_dow(string $d): int { return (int)date('N', strtotime($d . ' 12:00:00')); }   // 1 = lunedì … 7 = domenica
function pn_lunedi(string $d): string { return pn_piu($d, -(pn_dow($d) - 1)); }
function pn_diff(string $a, string $b): int { return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400); }

/* ---------------------------------------------------------------------
   Impostazioni + ritmo misurato.
   L'utente non deve dire quante ore libere ha: il sito le ricava dai
   pomodori degli ultimi 60 giorni (media nei giorni in cui ha lavorato,
   feriali e festivi a parte) e lui corregge solo se sbaglia.
   --------------------------------------------------------------------- */
function pn_ritmo_misurato(int $uid): array {
    $righe = sec_all("SELECT DATE(start_time) AS g, SUM(COALESCE(duration_seconds,0)) / 60 AS m
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND start_time >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                        AND start_time < CURDATE()
                      GROUP BY DATE(start_time)", [$uid], 'i');
    $wd = []; $we = [];
    foreach ($righe as $r) {
        $m = (float)$r['m'];
        if ($m < 10) continue;
        if (pn_dow((string)$r['g']) >= 6) $we[] = $m; else $wd[] = $m;
    }
    $avg = function (array $a, int $def): int {
        if (count($a) < 4) return $def;          // pochi dati: meglio un valore prudente che uno finto
        return (int)(round(array_sum($a) / count($a) / 5) * 5);
    };
    return ['weekday' => $avg($wd, 120), 'weekend' => $avg($we, 120), 'days' => count($wd) + count($we),
            'enough' => (count($wd) + count($we)) >= 8];
}

function pn_impostazioni(int $uid): array {
    $r = sec_one("SELECT * FROM plan_settings WHERE user_id = ?", [$uid], 'i');
    $mis = pn_ritmo_misurato($uid);
    $ov = [];
    if ($r && !empty($r['overrides'])) {
        $j = json_decode((string)$r['overrides'], true);
        if (is_array($j)) foreach ($j as $d => $m) if (sec_date($d) && is_numeric($m)) $ov[$d] = max(0, min(1440, (int)$m));
    }
    $wd = ($r && $r['weekday_min'] !== null) ? (int)$r['weekday_min'] : null;
    $we = ($r && $r['weekend_min'] !== null) ? (int)$r['weekend_min'] : null;
    return [
        'weekday_min' => $wd, 'weekend_min' => $we,
        'weekday_eff' => $wd ?? $mis['weekday'], 'weekend_eff' => $we ?? $mis['weekend'],
        'rest_dow'    => $r ? (int)$r['rest_dow'] : 0,
        'buffer_days' => $r ? (int)$r['buffer_days'] : 0,
        'floor_min'   => $r ? (int)$r['floor_min'] : 15,
        'max_subjects'=> $r ? (int)$r['max_subjects'] : 3,
        'reviews'     => $r ? (int)$r['reviews'] === 1 : false,
        'overrides'   => $ov,
        'measured'    => $mis,
    ];
}

/* Minuti già presi dal planner (lezioni, visite…) per ogni giorno: tolti
   dal tempo libero. Si contano solo gli eventi veri, con orario, e quelli
   di tutto il giorno marcati importanti. Gli eventi che sono già blocchi di
   studio (hanno un progetto) non si contano due volte. */
function pn_occupato(int $uid, string $da, string $a): array {
    $out = [];
    $righe = sec_all("SELECT event_date, is_all_day, importance,
                             TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60 AS m
                      FROM planner_events
                      WHERE user_id = ? AND event_date BETWEEN ? AND ?
                        AND rrule IS NULL AND COALESCE(is_cancelled,0) = 0
                        AND item_type = 'event' AND project_id IS NULL AND COALESCE(is_completed,0) = 0",
                     [$uid, $da, $a], 'iss');
    foreach ($righe as $r) {
        $g = (string)$r['event_date'];
        if ((int)$r['is_all_day'] === 1) $m = ($r['importance'] === 'normal') ? 0 : ($r['importance'] === 'high' ? 120 : 240);
        else $m = max(0, min(600, (float)$r['m']));
        $out[$g] = ($out[$g] ?? 0) + (int)round($m);
    }
    return $out;
}

/* Capienza di un giorno: base (feriale/festivo) o valore messo a mano per
   quella data, zero nel giorno di riposo, meno ciò che è già occupato. */
function pn_capienza(string $g, array $imp, array $occ, bool $dipinto = false): int {
    if (isset($imp['overrides'][$g])) return (int)$imp['overrides'][$g];
    $base = pn_dow($g) >= 6 ? $imp['weekend_eff'] : $imp['weekday_eff'];
    if ($imp['rest_dow'] > 0 && pn_dow($g) === $imp['rest_dow'] && !$dipinto) return 0;
    return max(0, $base - ($occ[$g] ?? 0));
}

/* ---------------------------------------------------------------------
   L'albero dei progetti dell'utente e i suoi obiettivi.
   --------------------------------------------------------------------- */
function pn_progetti(int $uid): array {
    $p = [];
    foreach (sec_all("SELECT id, parent_id, name, color, status, study_pomodori_stimati, custom_order
                      FROM projects WHERE user_id = ? ORDER BY custom_order ASC, id ASC", [$uid], 'i') as $r) {
        $p[(int)$r['id']] = [
            'id' => (int)$r['id'], 'parent' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name' => (string)$r['name'], 'color' => (string)$r['color'], 'status' => (string)$r['status'],
            'pom' => $r['study_pomodori_stimati'] === null ? null : (int)$r['study_pomodori_stimati'],
            'kids' => [],
        ];
    }
    foreach ($p as $id => $n) if ($n['parent'] !== null && isset($p[$n['parent']])) $p[$n['parent']]['kids'][] = $id;
    return $p;
}

function pn_obiettivi(int $uid): array {
    $g = [];
    foreach (sec_all("SELECT * FROM plan_goals WHERE user_id = ?", [$uid], 'i') as $r) {
        $g[(int)$r['project_id']] = [
            'active' => (int)$r['active'] === 1, 'minutes' => (int)$r['minutes'], 'locked' => (int)$r['locked'] === 1,
            'kind' => (string)$r['kind'], 'priority' => (string)$r['priority'],
            'start' => $r['start_date'], 'deadline' => $r['deadline'], 'since' => $r['since'],
            'items' => $r['items'] === null ? null : (int)$r['items'],
            'unit_minutes' => $r['unit_minutes'] === null ? null : (int)$r['unit_minutes'],
        ];
    }
    return $g;
}

function pn_sottoalbero(array $p, int $root): array {
    $out = []; $pila = [$root];
    while ($pila) {
        $id = array_pop($pila);
        if (!isset($p[$id]) || isset($out[$id])) continue;
        $out[$id] = true;
        foreach ($p[$id]['kids'] as $k) $pila[] = $k;
    }
    return array_keys($out);
}

function pn_radice(array $p, int $id): int {
    $guard = 0;
    while (isset($p[$id]) && $p[$id]['parent'] !== null && isset($p[$p[$id]['parent']]) && $guard++ < 30) $id = $p[$id]['parent'];
    return $id;
}

/* Una proprietà non scritta sul figlio (scadenza, inizio) si eredita dal
   primo antenato che la ha: se l'esame è il 12 gennaio, lo sono anche i
   suoi argomenti. */
function pn_eredita(array $p, array $g, int $id, string $campo) {
    $guard = 0;
    while ($id && $guard++ < 30) {
        if (isset($g[$id]) && !empty($g[$id][$campo])) return $g[$id][$campo];
        $id = $p[$id]['parent'] ?? 0;
        if ($id === null) break;
    }
    return null;
}

/* Minuti fatti (pomodori) per progetto e giorno, da una data in poi. */
function pn_fatto(int $uid, string $da): array {
    $out = [];
    foreach (sec_all("SELECT project_id, DATE(start_time) AS g, SUM(COALESCE(duration_seconds,0)) / 60 AS m
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND project_id IS NOT NULL AND start_time >= ?
                      GROUP BY project_id, DATE(start_time)", [$uid, $da . ' 00:00:00'], 'is') as $r) {
        $out[(int)$r['project_id']][(string)$r['g']] = (int)round((float)$r['m']);
    }
    return $out;
}

function pn_fatto_nodo(array $p, array $fatto, int $id, ?string $dal): int {
    $tot = 0;
    foreach (pn_sottoalbero($p, $id) as $x) {
        foreach ($fatto[$x] ?? [] as $g => $m) if ($dal === null || $g >= $dal) $tot += $m;
    }
    return $tot;
}

/* ---------------------------------------------------------------------
   Il pianificatore.
   Per ogni «unità» (un progetto con tempo e senza figli che ne abbiano) si
   conta quanto resta e in quanti giorni utili. Ogni giorno si dà a ciascuna
   la sua parte di quello che resta diviso i giorni che restano; se la
   giornata è piena vincono prima le cose «da fare», poi le «dovrebbe», poi
   le «se avanza». Quello che non entra non si perde: il giorno dopo resta
   più da fare, quindi l'urgenza cresce da sola. Sotto la soglia minima
   (15 min) la parte si accumula e si dà a giorni alterni, perché 5 minuti
   di inglese non servono a nessuno.
   --------------------------------------------------------------------- */
function pn_pianifica(int $uid, int $radice, array $imp): array {
    $oggi = pn_oggi();
    $p = pn_progetti($uid);
    $g = pn_obiettivi($uid);
    $ambito = $radice > 0 ? pn_sottoalbero($p, $radice) : array_keys($p);
    $inAmbito = array_flip($ambito);

    /* le unità */
    $unita = [];
    foreach ($ambito as $id) {
        $o = $g[$id] ?? null;
        if (!$o || !$o['active'] || $o['minutes'] <= 0) continue;
        if (($p[$id]['status'] ?? '') === 'completed') continue;
        // un antenato spento o completato spegne tutto il ramo
        $ok = true; $a = $p[$id]['parent']; $guard = 0;
        while ($a !== null && isset($p[$a]) && $guard++ < 30) {
            if (isset($g[$a]) && !$g[$a]['active'] && $g[$a]['minutes'] > 0) { $ok = false; break; }
            if (($p[$a]['status'] ?? '') === 'completed') { $ok = false; break; }
            $a = $p[$a]['parent'];
        }
        if (!$ok) continue;
        // se un figlio ha il suo tempo, è il figlio l'unità
        $figlio = false;
        foreach ($p[$id]['kids'] as $k) if (!empty($g[$k]['active']) && ($g[$k]['minutes'] ?? 0) > 0) { $figlio = true; break; }
        if ($figlio) continue;
        $unita[$id] = $o;
    }

    $fatto = pn_fatto($uid, '2000-01-01');
    $manuali = [];     // [giorno][progetto] => minuti messi a mano, da oggi in poi
    foreach (sec_all("SELECT day, project_id, minutes FROM plan_days WHERE user_id = ? AND source = 'manual' AND kind = 'study' AND day >= ?",
                     [$uid, $oggi], 'is') as $r) $manuali[(string)$r['day']][(int)$r['project_id']] = (int)$r['minutes'];

    $dipinti = [];     // [progetto] => [giorno => true], solo da oggi in poi
    foreach (sec_all("SELECT project_id, day FROM plan_paint WHERE user_id = ? AND day >= ?", [$uid, $oggi], 'is') as $r)
        $dipinti[(int)$r['project_id']][(string)$r['day']] = true;

    $info = [];
    $orizzonteFine = null;
    foreach ($unita as $id => $o) {
        $scad = pn_eredita($p, $g, $id, 'deadline');
        $ini  = pn_eredita($p, $g, $id, 'start');
        $since = $o['since'] ?: null;
        $fattoU = pn_fatto_nodo($p, $fatto, $id, $since);
        $manU = 0; foreach ($manuali as $gg => $pm) $manU += $pm[$id] ?? 0;
        $resta = max(0, $o['minutes'] - $fattoU - $manU);
        // i giorni dipinti: del progetto stesso o del primo antenato che ne ha
        $dip = null; $a = $id; $guard = 0;
        while ($a !== null && $guard++ < 30) { if (!empty($dipinti[$a])) { $dip = $dipinti[$a]; break; } $a = $p[$a]['parent'] ?? null; }
        $info[$id] = ['resta' => $resta, 'scad' => $scad, 'ini' => $ini, 'dip' => $dip, 'carry' => 0.0, 'tot' => $resta,
                      'kind' => $o['kind'], 'prio' => $o['priority'], 'root' => pn_radice($p, $id)];
        if ($scad && ($orizzonteFine === null || $scad > $orizzonteFine)) $orizzonteFine = $scad;
    }
    if ($orizzonteFine === null || $orizzonteFine < $oggi) $orizzonteFine = pn_piu($oggi, 84);
    if (pn_diff($oggi, $orizzonteFine) > 400) $orizzonteFine = pn_piu($oggi, 400);

    $occ = pn_occupato($uid, $oggi, $orizzonteFine);
    $giorni = [];
    for ($d = $oggi; $d <= $orizzonteFine; $d = pn_piu($d, 1)) $giorni[] = $d;

    /* capienza di ogni giorno, calcolata una volta: un giorno dipinto da
       qualcuno vale anche se è il giorno di riposo, e i blocchi messi a
       mano si tolgono subito */
    $capGiorno = [];
    foreach ($giorni as $d) {
        $dip = false;
        foreach ($info as $u) if ($u['dip'] !== null && !empty($u['dip'][$d])) { $dip = true; break; }
        $c = pn_capienza($d, $imp, $occ, $dip);
        foreach ($manuali[$d] ?? [] as $m) $c = max(0, $c - $m);
        $capGiorno[$d] = $c;
    }

    /* i giorni utili di ogni unità: dentro le sue date, sui giorni dipinti
       se ce ne sono, e solo dove c'è posto */
    $buffer = $imp['buffer_days'];
    foreach ($info as $id => &$u) {
        $fine = $u['scad'] ? pn_piu($u['scad'], -1) : $orizzonteFine;     // il giorno dell'esame non si studia
        $fineStudio = $fine;
        if ($u['scad'] && $u['kind'] === 'exam' && $buffer > 0) {
            $f2 = pn_piu($u['scad'], -1 - $buffer);
            if ($f2 >= $oggi) $fineStudio = $f2;                           // gli ultimi giorni sono solo di ripasso
        }
        $u['fine'] = $fine; $u['fineStudio'] = $fineStudio;
        $inizio = ($u['ini'] && $u['ini'] > $oggi) ? $u['ini'] : $oggi;
        $u['utili'] = [];
        foreach ($giorni as $d) {
            if ($d < $inizio || $d > $fineStudio || $capGiorno[$d] <= 0) continue;
            if ($u['dip'] !== null && empty($u['dip'][$d])) continue;
            $u['utili'][] = $d;
        }
        // evidenziatore tutto nel passato o fuori scadenza: lo si ignora, altrimenti l'unità sparirebbe
        if (!$u['utili'] && $u['dip'] !== null) {
            $u['dip'] = null;
            foreach ($giorni as $d) if ($d >= $inizio && $d <= $fineStudio && $capGiorno[$d] > 0) $u['utili'][] = $d;
        }
        $u['rimasti'] = count($u['utili']);
        $u['idx'] = array_flip($u['utili']);
    }
    unset($u);

    $rango = ['must' => 0, 'should' => 1, 'nice' => 2];
    $peso = ['must' => 3, 'should' => 2, 'nice' => 1];
    $ultimoGiorno = [];
    $righe = [];                 // [giorno][progetto][kind] => minuti
    $ultimoStudio = [];          // [progetto][giorno] => minuti, per i ripassi
    /* Quanto è piena la strada: bisogno totale diviso tempo totale. Se è
       comoda, ogni giorno prende solo la sua parte e resta tempo libero. Se è
       stretta, il tempo che avanza si usa per portarsi avanti, altrimenti il
       lavoro si ammucchia in fondo e ci si accorge troppo tardi. */
    $totBisogno = 0; foreach ($info as $u) $totBisogno += $u['resta'];
    $totTempo = 0; foreach ($capGiorno as $c) $totTempo += $c;
    $tensione = $totTempo > 0 ? $totBisogno / $totTempo : 9.0;
    $mira = $tensione > 0.6 ? min(1.0, $tensione + 0.12) : 0.0;     // quota del tempo del giorno da riempire
    $soglia = max(5, $imp['floor_min']);
    $maxSogg = max(1, $imp['max_subjects']);

    foreach ($giorni as $d) {
        $cap = $capGiorno[$d];
        if ($cap <= 0) continue;
        /* chi ha qualcosa da fare oggi */
        $cand = [];
        foreach ($info as $id => &$u) {
            if (!isset($u['idx'][$d]) || $u['resta'] <= 0) continue;
            $need = $u['resta'] / max(1, $u['rimasti']);
            $cand[] = ['id' => $id, 'need' => $need, 'rango' => $rango[$u['prio']] ?? 1,
                       'urg' => $need / max(1, $cap)];
            $u['rimasti']--;
        }
        unset($u);
        if (!$cand) continue;
        /* chi viene scelto oggi: il bisogno pesato per priorità, più un po' per
           ogni giorno che la cosa aspetta, così una «se avanza» non resta
           ferma per sempre dietro agli esami */
        foreach ($cand as &$c) {
            $ult = $ultimoGiorno[$c['id']] ?? null;
            $eta = $ult === null ? 7 : min(14, pn_diff($ult, $d));
            $c['score'] = $c['need'] * $peso[$info[$c['id']]['prio']] * (1 + $eta / 7);
        }
        unset($c);
        usort($cand, function ($a, $b) { return $b['score'] <=> $a['score']; });
        /* sotto la soglia: la parte si accumula e si dà a giorni alterni */
        $scelti = [];
        foreach ($cand as $c) {
            $u = &$info[$c['id']];
            $nec = $c['need'] + $u['carry'];
            $ultimo = ($u['rimasti'] <= 0);                  // oggi è l'ultimo giorno utile: si dà tutto
            if ($nec < $soglia && !$ultimo) { $u['carry'] = $nec; unset($u); continue; }
            $u['carry'] = 0.0;
            $c['need'] = $ultimo ? $u['resta'] : $nec;
            $scelti[] = $c;
            unset($u);
        }
        /* «al massimo N cose al giorno» vale quando la giornata è piena. Se i
           primi N bastano a riempire meno dell'85% del tempo, se ne aggiungono
           altri: lasciare ore libere oggi vorrebbe dire dover recuperare domani. */
        $presi = []; $somma = 0;
        foreach ($scelti as $c) {
            if (count($presi) >= $maxSogg && $somma >= $cap * 0.85) break;
            $presi[] = $c; $somma += $c['need'];
        }
        $scelti = $presi;
        /* il tempo del giorno si divide in proporzione al bisogno pesato:
           nessuno resta a zero, chi pesa di più ha di più, e chi ha bisogno
           di meno della sua parte prende solo quello che gli serve (il resto
           passa agli altri) */
        $libero = $cap;
        $dare = [];
        $attivi = $scelti;
        for ($giro = 0; $giro < 8 && $attivi; $giro++) {
            $sw = 0; foreach ($attivi as $c) $sw += $c['need'] * $peso[$info[$c['id']]['prio']];
            if ($sw <= 0) break;
            $piccoli = []; $resto = [];
            foreach ($attivi as $c) {
                $quota = $libero * ($c['need'] * $peso[$info[$c['id']]['prio']]) / $sw;
                if ($quota >= $c['need']) $piccoli[] = $c; else $resto[] = $c;
            }
            if (!$piccoli) {
                foreach ($attivi as $c) $dare[$c['id']] = $libero * ($c['need'] * $peso[$info[$c['id']]['prio']]) / $sw;
                $attivi = [];
                break;
            }
            foreach ($piccoli as $c) { $dare[$c['id']] = $c['need']; $libero -= $c['need']; }
            $attivi = $resto;
        }
        $libero = $cap;
        foreach ($scelti as $c) {
            $x = $dare[$c['id']] ?? 0;
            $m = (int)(round($x / 5) * 5);
            if ($m <= 0 && $x >= 1) $m = 5;
            $u = &$info[$c['id']];
            $m = min($m, $u['resta'], $libero);
            if ($u['resta'] - $m > 0 && $u['resta'] - $m < 10 && $libero - $m >= $u['resta'] - $m) $m = $u['resta'];   // niente avanzi da 5 minuti
            if ($m > 0) {
                $righe[$d][$c['id']]['study'] = $m;
                $u['resta'] -= $m;
                $libero -= $m;
                $ultimoStudio[$c['id']][$d] = $m;
                $ultimoGiorno[$c['id']] = $d;
            }
            unset($u);
        }
        /* portarsi avanti: solo con la strada stretta, e solo sulle cose già in programma oggi */
        if ($mira > 0 && !empty($righe[$d])) {
            $usato = $cap - $libero;
            $stanza = min($libero, (int)round($cap * $mira) - $usato);
            if ($stanza >= 15) {
                $pool = []; $sw = 0;
                foreach ($righe[$d] as $pid => $kk) {
                    if (!isset($kk['study']) || $info[$pid]['resta'] <= 0) continue;
                    $w = $info[$pid]['resta'] * $peso[$info[$pid]['prio']];
                    $pool[$pid] = $w; $sw += $w;
                }
                foreach ($pool as $pid => $w) {
                    $m = (int)(round(($stanza * $w / $sw) / 5) * 5);
                    $m = min($m, $info[$pid]['resta'], $libero);
                    if ($m < 10) continue;
                    $righe[$d][$pid]['study'] += $m;
                    $info[$pid]['resta'] -= $m;
                    $libero -= $m;
                }
            }
        }
        /* ripassi a 1, 3 e 7 giorni: usano il tempo che avanza, non il budget */
        if ($imp['reviews'] && $libero >= 10) {
            foreach ($ultimoStudio as $pid => $perGiorno) {
                foreach ([1, 3, 7] as $dist) {
                    $prima = pn_piu($d, -$dist);
                    if (($perGiorno[$prima] ?? 0) >= 30 && $libero >= 10 && !isset($righe[$d][$pid]['review'])) {
                        $righe[$d][$pid]['review'] = 10; $libero -= 10;
                    }
                }
            }
        }
    }

    /* giorni di buffer prima di un esame: solo ripasso, diviso fra gli esami vicini */
    if ($buffer > 0) {
        foreach ($giorni as $d) {
            $es = [];
            foreach ($info as $id => $u) {
                if ($u['kind'] === 'exam' && $u['scad'] && $d > $u['fineStudio'] && $d <= $u['fine'] && $u['tot'] > 0) $es[] = $id;
            }
            if (!$es) continue;
            $cap = pn_capienza($d, $imp, $occ, true);
            $usato = 0; foreach ($righe[$d] ?? [] as $x) $usato += array_sum($x);
            $lib = max(0, $cap - $usato);
            if ($lib < 10) continue;
            $each = (int)(floor(min(45, $lib / count($es)) / 5) * 5);
            if ($each < 10) continue;
            foreach ($es as $id) $righe[$d][$id]['review'] = ($righe[$d][$id]['review'] ?? 0) + $each;
        }
    }

    /* cosa non è entrato: il «deficit» di ogni unità */
    $deficit = [];
    foreach ($info as $id => $u) if ($u['resta'] > 0) $deficit[$id] = $u['resta'];

    return ['righe' => $righe, 'deficit' => $deficit, 'info' => $info, 'giorni' => $giorni,
            'occ' => $occ, 'p' => $p, 'g' => $g, 'fine' => $orizzonteFine];
}

/* ---------------------------------------------------------------------
   Piccoli accessori
   --------------------------------------------------------------------- */
function pn_posseduto(int $uid, int $id): bool {
    return $id > 0 && sec_owns('projects', $id, $uid);
}

function pn_in(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}

function pn_ore(int $m): string {
    $h = intdiv($m, 60); $r = $m % 60;
    return $h > 0 ? ($r ? "{$h} h {$r} min" : "{$h} h") : "{$r} min";
}

/* ---------------------------------------------------------------------
   tree: tutto quello che serve per disegnare un progetto nel calendario
   --------------------------------------------------------------------- */
if ($ajax === 'tree') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    $da = sec_date(sec_in('from'), date('Y-m-01'));
    $a  = sec_date(sec_in('to'), pn_piu($da, 42));
    if (pn_diff($da, $a) > 400 || $a < $da) $a = pn_piu($da, 42);
    $p = pn_progetti($uid);
    if ($id > 0 && !isset($p[$id])) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $g = pn_obiettivi($uid);
    $radici = $id > 0 ? [$id] : array_values(array_filter(array_keys($p), function ($x) use ($p) { return $p[$x]['parent'] === null; }));
    $ids = [];
    foreach ($radici as $r) foreach (pn_sottoalbero($p, $r) as $x) $ids[$x] = true;
    $ids = array_keys($ids);

    $nodi = [];
    $fatto = pn_fatto($uid, '2000-01-01');
    foreach ($ids as $x) {
        $o = $g[$x] ?? null;
        $nodi[] = [
            'id' => $x, 'parent' => $p[$x]['parent'], 'name' => $p[$x]['name'], 'color' => $p[$x]['color'],
            'status' => $p[$x]['status'], 'kids' => $p[$x]['kids'], 'pom' => $p[$x]['pom'],
            'goal' => $o,
            'deadline_eff' => pn_eredita($p, $g, $x, 'deadline'),
            'done_min' => pn_fatto_nodo($p, $fatto, $x, $o['since'] ?? null),
        ];
    }
    $dip = []; $piano = []; $fattoGiorni = [];
    if ($ids) {
        $ph = pn_in($ids);
        foreach (sec_all("SELECT project_id, day FROM plan_paint WHERE user_id = ? AND day BETWEEN ? AND ? AND project_id IN ($ph)",
                         array_merge([$uid, $da, $a], $ids)) as $r) $dip[(int)$r['project_id']][] = (string)$r['day'];
        foreach (sec_all("SELECT project_id, day, kind, minutes, source FROM plan_days WHERE user_id = ? AND day BETWEEN ? AND ? AND project_id IN ($ph)",
                         array_merge([$uid, $da, $a], $ids)) as $r)
            $piano[] = ['p' => (int)$r['project_id'], 'd' => (string)$r['day'], 'k' => (string)$r['kind'], 'm' => (int)$r['minutes'], 's' => (string)$r['source']];
        foreach ($ids as $x) foreach ($fatto[$x] ?? [] as $gg => $m) if ($gg >= $da && $gg <= $a) $fattoGiorni[] = ['p' => $x, 'd' => $gg, 'm' => $m];
    }
    $imp = pn_impostazioni($uid);
    $occ = pn_occupato($uid, $da, $a);
    $cap = [];
    for ($d = $da; $d <= $a; $d = pn_piu($d, 1)) $cap[$d] = ['cap' => pn_capienza($d, $imp, $occ, true), 'busy' => $occ[$d] ?? 0];
    sec_json(['ok' => true, 'today' => pn_oggi(), 'nodes' => $nodi, 'paint' => $dip, 'plan' => $piano,
              'done' => $fattoGiorni, 'cap' => $cap, 'settings' => $imp]);
}

/* ---------------------------------------------------------------------
   goal_save: tempo, tipo, priorità, scadenza di UN progetto
   --------------------------------------------------------------------- */
if ($ajax === 'goal_save') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $old = sec_one("SELECT * FROM plan_goals WHERE user_id = ? AND project_id = ?", [$uid, $id], 'ii');
    $kind = sec_enum(sec_in('kind', $old['kind'] ?? 'plain'), PN_KINDS, 'plain');
    $prio = sec_enum(sec_in('priority', $old['priority'] ?? 'should'), PN_PRIO, 'should');
    $active = sec_bool(sec_in('active', $old ? $old['active'] : 1)) ? 1 : 0;
    $items = sec_in('items', $old['items'] ?? null);
    $unit = sec_in('unit_minutes', $old['unit_minutes'] ?? null);
    $items = ($items === null || $items === '') ? null : sec_int($items, 0, 100000, 0);
    $unit  = ($unit === null || $unit === '') ? null : sec_int($unit, 0, 1440, 0);
    $minutes = sec_int(sec_in('minutes', $old['minutes'] ?? 0), 0, 600000, 0);
    if ($kind === 'count' && $items !== null && $unit !== null && $items > 0 && $unit > 0) $minutes = $items * $unit;
    $locked = sec_bool(sec_in('locked', $old ? $old['locked'] : 0)) ? 1 : 0;
    $dl = array_key_exists('deadline', sec_body()) ? sec_date(sec_in('deadline')) : ($old['deadline'] ?? null);
    $st = array_key_exists('start', sec_body()) ? sec_date(sec_in('start')) : ($old['start_date'] ?? null);
    $since = $old['since'] ?? null;
    // la data da cui si contano i pomodori: se il tempo cambia per la prima volta, da oggi
    if (!$old || ((int)$old['minutes'] === 0 && $minutes > 0)) $since = pn_oggi();
    sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, locked, kind, priority, start_date, deadline, since, items, unit_minutes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE active=VALUES(active), minutes=VALUES(minutes), locked=VALUES(locked), kind=VALUES(kind),
             priority=VALUES(priority), start_date=VALUES(start_date), deadline=VALUES(deadline), since=VALUES(since),
             items=VALUES(items), unit_minutes=VALUES(unit_minutes)",
          [$uid, $id, $active, $minutes, $locked, $kind, $prio, $st, $dl, $since, $items, $unit],
          'iiiiisssssii');
    /* «scadenza e priorità anche ai figli»: comodo quando si imposta un esame intero */
    if (sec_bool(sec_in('cascade', 0))) {
        $p = pn_progetti($uid);
        foreach (pn_sottoalbero($p, $id) as $k) {
            if ($k === $id) continue;
            sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, kind, priority, deadline, start_date, since)
                   VALUES (?,?,?,0,?,?,?,?,NULL)
                   ON DUPLICATE KEY UPDATE kind=VALUES(kind), priority=VALUES(priority), deadline=VALUES(deadline), start_date=VALUES(start_date)",
                  [$uid, $k, $active, $kind, $prio, $dl, $st], 'iiissss');
        }
    }
    sec_json(['ok' => true, 'minutes' => $minutes]);
}

/* ---------------------------------------------------------------------
   split_save: le ore di un progetto divise fra i figli.
   Il client fa la matematica (cursori, lucchetti); qui si ricontrolla
   che la somma torni e che i figli siano davvero suoi.
   --------------------------------------------------------------------- */
if ($ajax === 'split_save') {
    $pid = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $pid)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $tot = sec_int(sec_in('total'), 0, 600000, 0);
    $items = sec_in('items', []);
    if (!is_array($items) || !$items) sec_die_json(t('piano.api_dati_non_validi'), 400, 'bad_request');
    $p = pn_progetti($uid);
    $figli = array_flip($p[$pid]['kids'] ?? []);
    $righe = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $cid = sec_int($it['id'] ?? 0, 0, PHP_INT_MAX, 0);
        if (!isset($figli[$cid])) continue;
        $righe[$cid] = ['m' => sec_int($it['minutes'] ?? 0, 0, 600000, 0), 'l' => !empty($it['locked']) ? 1 : 0];
    }
    if (!$righe) sec_die_json(t('piano.api_dati_non_validi'), 400, 'bad_request');
    /* la somma deve fare il totale: la differenza va sul più grande non bloccato */
    $somma = 0; foreach ($righe as $r) $somma += $r['m'];
    $diff = $tot - $somma;
    if ($diff !== 0) {
        $best = null;
        foreach ($righe as $cid => $r) if (!$r['l'] && ($best === null || $r['m'] > $righe[$best]['m'])) $best = $cid;
        if ($best === null) { $best = array_key_first($righe); }
        $righe[$best]['m'] = max(0, $righe[$best]['m'] + $diff);
    }
    $og = pn_obiettivi($uid);
    $padre = $og[$pid] ?? null;
    $pri = $padre['priority'] ?? 'should'; $kind = $padre['kind'] ?? 'plain';
    $since = pn_oggi();
    sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, kind, priority, since)
           VALUES (?,?,1,?,?,?,?)
           ON DUPLICATE KEY UPDATE minutes=VALUES(minutes), active=1, since=COALESCE(since, VALUES(since))",
          [$uid, $pid, $tot, $kind, $pri, $since], 'iiisss');
    foreach ($righe as $cid => $r) {
        $c = $og[$cid] ?? null;
        sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, locked, kind, priority, since)
               VALUES (?,?,1,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE minutes=VALUES(minutes), locked=VALUES(locked), active=1, since=COALESCE(since, VALUES(since))",
              [$uid, $cid, $r['m'], $r['l'], $c['kind'] ?? $kind, $c['priority'] ?? $pri, $since], 'iiiisss');
    }
    $out = []; foreach ($righe as $cid => $r) $out[] = ['id' => $cid, 'minutes' => $r['m'], 'locked' => $r['l'] === 1];
    sec_json(['ok' => true, 'items' => $out]);
}

/* ---------------------------------------------------------------------
   paint: l'evidenziatore. Una richiesta sola per tutto il trascinamento.
   --------------------------------------------------------------------- */
if ($ajax === 'paint') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $mode = sec_enum(sec_in('mode'), ['add', 'remove', 'clear'], 'add');
    $days = [];
    $raw = sec_in('days', []);
    if (is_array($raw)) foreach ($raw as $d) { $x = sec_date($d); if ($x) $days[$x] = true; }
    $days = array_slice(array_keys($days), 0, 400);
    if ($mode === 'clear') {
        sec_q("DELETE FROM plan_paint WHERE user_id = ? AND project_id = ?", [$uid, $id], 'ii');
    } elseif ($days) {
        if ($mode === 'add') {
            foreach ($days as $d) sec_q("INSERT IGNORE INTO plan_paint (user_id, project_id, day) VALUES (?,?,?)", [$uid, $id, $d], 'iis');
        } else {
            $ph = pn_in($days);
            sec_q("DELETE FROM plan_paint WHERE user_id = ? AND project_id = ? AND day IN ($ph)", array_merge([$uid, $id], $days));
        }
    }
    $n = sec_one("SELECT COUNT(*) AS n FROM plan_paint WHERE user_id = ? AND project_id = ?", [$uid, $id], 'ii');
    sec_json(['ok' => true, 'count' => (int)($n['n'] ?? 0)]);
}

/* ---------------------------------------------------------------------
   day_set: un blocco messo (o tolto) a mano
   --------------------------------------------------------------------- */
if ($ajax === 'day_set') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $d = sec_date(sec_in('day'));
    if (!$d) sec_die_json(t('piano.api_dati_non_validi'), 400, 'bad_request');
    $m = sec_int(sec_in('minutes'), 0, 1440, 0);
    if ($m <= 0) sec_q("DELETE FROM plan_days WHERE user_id = ? AND day = ? AND project_id = ? AND kind = 'study'", [$uid, $d, $id], 'isi');
    else sec_q("INSERT INTO plan_days (user_id, day, project_id, kind, minutes, source) VALUES (?,?,?,'study',?, 'manual')
                ON DUPLICATE KEY UPDATE minutes=VALUES(minutes), source='manual'", [$uid, $d, $id, $m], 'isii');
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   autoplan: anteprima o applica
   --------------------------------------------------------------------- */
if ($ajax === 'autoplan') {
    $radice = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if ($radice > 0 && !pn_posseduto($uid, $radice)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $apply = sec_bool(sec_in('apply', 0));
    $imp = pn_impostazioni($uid);
    $r = pn_pianifica($uid, $radice, $imp);
    $p = $r['p'];

    $tot = 0; $settimane = []; $perUnita = [];
    foreach ($r['righe'] as $d => $per) {
        foreach ($per as $pid => $k) {
            foreach ($k as $kind => $m) {
                $tot += $m;
                $w = pn_lunedi($d);
                $settimane[$w] = ($settimane[$w] ?? 0) + $m;
                if ($kind === 'study') $perUnita[$pid] = ($perUnita[$pid] ?? 0) + $m;
            }
        }
    }
    $mancano = 0; $avvisi = [];
    foreach ($r['deficit'] as $pid => $m) {
        $mancano += $m;
        $avvisi[] = ['project' => $pid, 'name' => $p[$pid]['name'] ?? '', 'minutes' => $m,
                     'painted' => ($r['info'][$pid]['dip'] ?? null) !== null ? count($r['info'][$pid]['utili']) : 0];
    }
    $primo = null; $ultimo = null;
    foreach ($r['righe'] as $d => $_) { if ($primo === null || $d < $primo) $primo = $d; if ($ultimo === null || $d > $ultimo) $ultimo = $d; }

    if ($apply) {
        $oggi = pn_oggi();
        $ids = array_keys($r['info']);
        /* si cancella solo il piano AUTOMATICO futuro delle unità in gioco: i blocchi messi a mano restano */
        $scope = $radice > 0 ? pn_sottoalbero($p, $radice) : array_keys($p);
        if ($scope) {
            $ph = pn_in($scope);
            sec_q("DELETE FROM plan_days WHERE user_id = ? AND day >= ? AND source = 'auto' AND project_id IN ($ph)",
                  array_merge([$uid, $oggi], $scope));
        }
        foreach ($r['righe'] as $d => $per) foreach ($per as $pid => $k) foreach ($k as $kind => $m) {
            if ($m <= 0) continue;
            sec_q("INSERT INTO plan_days (user_id, day, project_id, kind, minutes, source) VALUES (?,?,?,?,?, 'auto')
                   ON DUPLICATE KEY UPDATE minutes=IF(source='manual', minutes, VALUES(minutes))",
                  [$uid, $d, $pid, $kind, $m], 'isisi');
        }
    }
    ksort($settimane);
    sec_json(['ok' => true, 'applied' => $apply, 'total_min' => $tot, 'missing_min' => $mancano, 'warnings' => $avvisi,
              'first' => $primo, 'last' => $ultimo, 'weeks' => $settimane, 'units' => count($r['info']),
              'fits' => $mancano === 0]);
}

/* ---------------------------------------------------------------------
   master: tutti gli obiettivi contro il tempo libero
   --------------------------------------------------------------------- */
if ($ajax === 'master') {
    $imp = pn_impostazioni($uid);
    $r = pn_pianifica($uid, 0, $imp);
    $p = $r['p']; $g = $r['g'];
    $oggi = pn_oggi();
    $fatto = pn_fatto($uid, '2000-01-01');

    /* un obiettivo = un progetto di primo livello che ha tempo da qualche parte nel suo ramo */
    $obiettivi = [];
    foreach ($p as $id => $n) {
        if ($n['parent'] !== null) continue;
        $min = 0; $attivo = false; $kind = $g[$id]['kind'] ?? 'plain';
        foreach ($r['info'] as $uid2 => $u) {
            if ($u['root'] !== $id) continue;
            $attivo = true;
        }
        $og = $g[$id] ?? null;
        $tuttiMin = 0;
        foreach (pn_sottoalbero($p, $id) as $x) {
            if (isset($r['info'][$x])) $tuttiMin += (int)$g[$x]['minutes'];
        }
        $min = max($tuttiMin, (int)($og['minutes'] ?? 0));
        if (!$og && !$attivo) continue;
        $fa = pn_fatto_nodo($p, $fatto, $id, $og['since'] ?? null);
        $pian = 0;
        foreach ($r['righe'] as $d => $per) foreach ($per as $pid => $k) if (pn_radice($p, $pid) === $id) $pian += array_sum($k);
        $mancano = 0; foreach ($r['deficit'] as $pid => $m) if (pn_radice($p, $pid) === $id) $mancano += $m;
        $obiettivi[] = [
            'id' => $id, 'name' => $n['name'], 'color' => $n['color'], 'status' => $n['status'],
            'active' => (bool)($og['active'] ?? false), 'kind' => $kind, 'priority' => $og['priority'] ?? 'should',
            'minutes' => $min, 'done_min' => $fa, 'planned_min' => $pian, 'missing_min' => $mancano,
            'deadline' => $og['deadline'] ?? null, 'kids' => count($n['kids']),
        ];
    }
    /* settimane: capienza e ore pianificate per obiettivo */
    $inizio = pn_lunedi($oggi);
    $fine = $r['fine'];
    $nw = min(30, max(1, (int)ceil((pn_diff($inizio, $fine) + 1) / 7)));
    $settimane = [];
    $occ = $r['occ'];
    for ($i = 0; $i < $nw; $i++) {
        $w = pn_piu($inizio, 7 * $i);
        $cap = 0;
        for ($k = 0; $k < 7; $k++) { $d = pn_piu($w, $k); if ($d >= $oggi && $d <= $fine) $cap += pn_capienza($d, $imp, $occ, false); }
        $settimane[$w] = ['start' => $w, 'cap' => $cap, 'parts' => []];
    }
    foreach ($r['righe'] as $d => $per) {
        $w = pn_lunedi($d);
        if (!isset($settimane[$w])) continue;
        foreach ($per as $pid => $k) {
            $rad = pn_radice($p, $pid);
            $settimane[$w]['parts'][$rad] = ($settimane[$w]['parts'][$rad] ?? 0) + array_sum($k);
        }
    }
    $capTot = 0; foreach ($settimane as $s) $capTot += $s['cap'];
    $serve = 0; foreach ($r['info'] as $u) $serve += $u['tot'];
    $mancano = array_sum($r['deficit']);
    $out = []; foreach ($settimane as $s) { $s['parts'] = (object)$s['parts']; $out[] = $s; }
    sec_json(['ok' => true, 'goals' => $obiettivi, 'weeks' => $out, 'needed_min' => $serve, 'free_min' => $capTot,
              'missing_min' => $mancano, 'fits' => $mancano === 0, 'end' => $fine, 'settings' => $imp,
              'units' => count($r['info'])]);
}

/* ---------------------------------------------------------------------
   today: cosa fare oggi
   --------------------------------------------------------------------- */
if ($ajax === 'today') {
    $oggi = pn_oggi();
    $ieri = pn_piu($oggi, -1);
    $p = pn_progetti($uid);
    $g = pn_obiettivi($uid);
    $fatto = pn_fatto($uid, pn_piu($oggi, -14));
    $blocchi = [];
    foreach (sec_all("SELECT project_id, kind, minutes FROM plan_days WHERE user_id = ? AND day = ? ORDER BY kind ASC, minutes DESC",
                     [$uid, $oggi], 'is') as $r) {
        $pid = (int)$r['project_id'];
        if (!isset($p[$pid])) continue;
        $rad = pn_radice($p, $pid);
        $f = $fatto[$pid][$oggi] ?? 0;
        $blocchi[] = [
            'project' => $pid, 'name' => $p[$pid]['name'], 'color' => $p[$pid]['color'],
            'root' => $rad, 'root_name' => $p[$rad]['name'] ?? '', 'root_color' => $p[$rad]['color'] ?? '',
            'kind' => (string)$r['kind'], 'minutes' => (int)$r['minutes'], 'done' => $f,
        ];
    }
    $saltati = [];
    foreach (sec_all("SELECT project_id, minutes FROM plan_days WHERE user_id = ? AND day = ? AND kind = 'study'", [$uid, $ieri], 'is') as $r) {
        $pid = (int)$r['project_id'];
        if (!isset($p[$pid])) continue;
        $f = $fatto[$pid][$ieri] ?? 0;
        $manca = (int)$r['minutes'] - $f;
        if ($manca >= 10 && $f < (int)$r['minutes'] * 0.5) {
            $saltati[] = ['project' => $pid, 'name' => $p[$pid]['name'], 'missing' => $manca, 'planned' => (int)$r['minutes'], 'done' => $f];
        }
    }
    $imp = pn_impostazioni($uid);
    $lun = pn_lunedi($oggi); $dom = pn_piu($lun, 6);
    $occ = pn_occupato($uid, $oggi, pn_piu($oggi, 10));
    foreach ($saltati as &$s) {
        /* il giorno più vicino con abbastanza posto libero (entro 7 giorni) */
        $s['move_to'] = null;
        for ($i = 0; $i <= 7; $i++) {
            $d = pn_piu($oggi, $i);
            $cap = pn_capienza($d, $imp, $occ, false);
            $us = sec_one("SELECT COALESCE(SUM(minutes),0) AS m FROM plan_days WHERE user_id = ? AND day = ?", [$uid, $d], 'is');
            if ($cap - (int)($us['m'] ?? 0) >= $s['missing']) { $s['move_to'] = $d; $s['free_min'] = $cap - (int)($us['m'] ?? 0); break; }
        }
    }
    unset($s);
    $sett = sec_one("SELECT COALESCE(SUM(minutes),0) AS m FROM plan_days WHERE user_id = ? AND day BETWEEN ? AND ? AND kind = 'study'", [$uid, $lun, $dom], 'iss');
    $fattoSett = 0;
    foreach (sec_all("SELECT SUM(COALESCE(duration_seconds,0)) / 60 AS m FROM pomodoro_sessions
                      WHERE user_id = ? AND project_id IS NOT NULL AND DATE(start_time) BETWEEN ? AND ?", [$uid, $lun, $dom], 'iss') as $r)
        $fattoSett = (int)round((float)($r['m'] ?? 0));
    $esame = null;
    foreach ($g as $id => $o) {
        if (!$o['active'] || $o['kind'] !== 'exam' || !$o['deadline'] || $o['deadline'] < $oggi || !isset($p[$id])) continue;
        if ($esame === null || $o['deadline'] < $esame['date']) $esame = ['project' => $id, 'name' => $p[$id]['name'], 'date' => $o['deadline'], 'days' => pn_diff($oggi, $o['deadline'])];
    }
    sec_json(['ok' => true, 'today' => $oggi, 'blocks' => $blocchi, 'skipped' => $saltati,
              'week' => ['planned' => (int)($sett['m'] ?? 0), 'done' => $fattoSett], 'next_exam' => $esame]);
}

/* ---------------------------------------------------------------------
   skip_fix: ieri non è andata. Si sposta, o si lascia perdere.
   --------------------------------------------------------------------- */
if ($ajax === 'skip_fix') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $ieri = pn_piu(pn_oggi(), -1);
    $mode = sec_enum(sec_in('mode'), ['move', 'leave'], 'leave');
    $riga = sec_one("SELECT minutes FROM plan_days WHERE user_id = ? AND day = ? AND project_id = ? AND kind = 'study'", [$uid, $ieri, $id], 'isi');
    if ($riga) {
        $fatto = pn_fatto($uid, $ieri);
        $f = $fatto[$id][$ieri] ?? 0;
        $manca = max(0, (int)$riga['minutes'] - $f);
        /* ieri si riduce a ciò che è stato fatto davvero: non risulterà più «saltato» */
        if ($f > 0) sec_q("UPDATE plan_days SET minutes = ? WHERE user_id = ? AND day = ? AND project_id = ? AND kind = 'study'", [$f, $uid, $ieri, $id], 'iisi');
        else sec_q("DELETE FROM plan_days WHERE user_id = ? AND day = ? AND project_id = ? AND kind = 'study'", [$uid, $ieri, $id], 'isi');
        if ($mode === 'move' && $manca > 0) {
            $to = sec_date(sec_in('to'), pn_oggi());
            if ($to < pn_oggi()) $to = pn_oggi();
            sec_q("INSERT INTO plan_days (user_id, day, project_id, kind, minutes, source) VALUES (?,?,?,'study',?,'manual')
                   ON DUPLICATE KEY UPDATE minutes = minutes + VALUES(minutes), source='manual'", [$uid, $to, $id, $manca], 'isii');
        }
    }
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   Impostazioni
   --------------------------------------------------------------------- */
if ($ajax === 'settings_get') {
    sec_json(['ok' => true, 'settings' => pn_impostazioni($uid)]);
}

if ($ajax === 'settings_save') {
    $body = sec_body();
    $wd = array_key_exists('weekday_min', $body) && $body['weekday_min'] !== null && $body['weekday_min'] !== '' ? sec_int($body['weekday_min'], 0, 1440, 0) : null;
    $we = array_key_exists('weekend_min', $body) && $body['weekend_min'] !== null && $body['weekend_min'] !== '' ? sec_int($body['weekend_min'], 0, 1440, 0) : null;
    $rest = sec_int(sec_in('rest_dow', 0), 0, 7, 0);
    $buf = sec_int(sec_in('buffer_days', 0), 0, 14, 0);
    $floor = sec_int(sec_in('floor_min', 15), 5, 120, 15);
    $max = sec_int(sec_in('max_subjects', 3), 1, 8, 3);
    $rev = sec_bool(sec_in('reviews', 0)) ? 1 : 0;
    $ov = [];
    if (isset($body['overrides']) && is_array($body['overrides'])) {
        foreach ($body['overrides'] as $d => $m) if (sec_date($d) && is_numeric($m)) $ov[$d] = max(0, min(1440, (int)$m));
    }
    $json = $ov ? json_encode($ov) : null;
    sec_q("INSERT INTO plan_settings (user_id, weekday_min, weekend_min, rest_dow, buffer_days, floor_min, max_subjects, reviews, overrides)
           VALUES (?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE weekday_min=VALUES(weekday_min), weekend_min=VALUES(weekend_min), rest_dow=VALUES(rest_dow),
             buffer_days=VALUES(buffer_days), floor_min=VALUES(floor_min), max_subjects=VALUES(max_subjects),
             reviews=VALUES(reviews), overrides=VALUES(overrides)",
          [$uid, $wd, $we, $rest, $buf, $floor, $max, $rev, $json], 'iiiiiiiis');
    sec_json(['ok' => true, 'settings' => pn_impostazioni($uid)]);
}

/* ---------------------------------------------------------------------
   import_old: i pomodori stimati del vecchio piano diventano minuti.
   Una volta sola per progetto: se ha già un tempo, non si tocca.
   --------------------------------------------------------------------- */
if ($ajax === 'import_old') {
    $pom = sec_one("SELECT last_pomodoro_duration_min AS m FROM pomodoro_settings WHERE user_id = ?", [$uid], 'i');
    $durata = max(5, min(120, (int)($pom['m'] ?? 25)));
    $p = pn_progetti($uid); $g = pn_obiettivi($uid);
    $n = 0;
    foreach (sec_all("SELECT id, study_pomodori_stimati AS s, study_start AS a, study_end AS b, parent_id
                      FROM projects WHERE user_id = ? AND study_pomodori_stimati > 0 AND status <> 'completed'", [$uid], 'i') as $r) {
        $id = (int)$r['id'];
        if (!empty($g[$id]['minutes'])) continue;
        sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, kind, priority, start_date, deadline, since)
               VALUES (?,?,1,?, 'plain', 'should', ?, ?, ?)
               ON DUPLICATE KEY UPDATE minutes=VALUES(minutes), active=1, since=COALESCE(since, VALUES(since))",
              [$uid, $id, (int)$r['s'] * $durata, $r['a'], $r['b'], pn_oggi()], 'iiisss');
        $n++;
    }
    sec_json(['ok' => true, 'imported' => $n, 'pomodoro_min' => $durata]);
}

/* ---------------------------------------------------------------------
   Modelli: un piano che funziona si salva e si riusa (la «sessione di
   gennaio» del prossimo anno). Si salva la forma, non i giorni: tempo,
   tipo e priorità dei progetti per NOME, così vale anche su progetti nuovi.
   --------------------------------------------------------------------- */
if ($ajax === 'tpl_list') {
    $rows = sec_all("SELECT id, name, created_at FROM plan_templates WHERE user_id = ? ORDER BY id DESC LIMIT 30", [$uid], 'i');
    sec_json(['ok' => true, 'templates' => array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name']]; }, $rows)]);
}
if ($ajax === 'tpl_save') {
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $nome = sec_str(sec_in('name'), 80);
    if ($nome === '') sec_die_json(t('piano.api_dati_non_validi'), 400, 'bad_request');
    $p = pn_progetti($uid); $g = pn_obiettivi($uid);
    $voci = [];
    $visita = function (int $x, ?string $path) use (&$visita, $p, $g, &$voci) {
        $nm = $p[$x]['name']; $pp = $path === null ? $nm : $path . ' / ' . $nm;
        $o = $g[$x] ?? null;
        if ($o && $o['minutes'] > 0) $voci[] = ['path' => $pp, 'minutes' => $o['minutes'], 'kind' => $o['kind'], 'priority' => $o['priority'], 'locked' => $o['locked']];
        foreach ($p[$x]['kids'] as $k) $visita($k, $pp);
    };
    $visita($id, null);
    sec_q("INSERT INTO plan_templates (user_id, name, body) VALUES (?,?,?)", [$uid, $nome, json_encode($voci, JSON_UNESCAPED_UNICODE)], 'iss');
    sec_json(['ok' => true, 'saved' => count($voci)]);
}
if ($ajax === 'tpl_apply') {
    $tid = sec_int(sec_in('id'), 0, PHP_INT_MAX, 0);
    $id = sec_int(sec_in('project'), 0, PHP_INT_MAX, 0);
    if (!pn_posseduto($uid, $id)) sec_die_json(t('piano.api_progetto_non_trovato'), 404, 'not_found');
    $t = sec_one("SELECT body FROM plan_templates WHERE id = ? AND user_id = ?", [$tid, $uid], 'ii');
    if (!$t) sec_die_json(t('piano.api_modello_non_trovato'), 404, 'not_found');
    $voci = json_decode((string)$t['body'], true);
    if (!is_array($voci)) sec_die_json(t('piano.api_dati_non_validi'), 400, 'bad_request');
    $p = pn_progetti($uid);
    $per = [];
    $visita = function (int $x, ?string $path) use (&$visita, $p, &$per) {
        $nm = $p[$x]['name']; $pp = $path === null ? $nm : $path . ' / ' . $nm;
        $per[$pp] = $x;
        foreach ($p[$x]['kids'] as $k) $visita($k, $pp);
    };
    $visita($id, null);
    $n = 0;
    foreach ($voci as $v) {
        if (!is_array($v) || !isset($v['path'])) continue;
        // il primo livello può avere un altro nome (un altro anno, un altro esame): si abbina per posizione
        $parti = explode(' / ', (string)$v['path'], 2);
        $chiave = $p[$id]['name'] . (isset($parti[1]) ? ' / ' . $parti[1] : '');
        if (!isset($per[$chiave])) continue;
        $pid = $per[$chiave];
        sec_q("INSERT INTO plan_goals (user_id, project_id, active, minutes, locked, kind, priority, since)
               VALUES (?,?,1,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE minutes=VALUES(minutes), locked=VALUES(locked), kind=VALUES(kind), priority=VALUES(priority),
                 active=1, since=COALESCE(since, VALUES(since))",
              [$uid, $pid, sec_int($v['minutes'] ?? 0, 0, 600000, 0), !empty($v['locked']) ? 1 : 0,
               sec_enum($v['kind'] ?? 'plain', PN_KINDS, 'plain'), sec_enum($v['priority'] ?? 'should', PN_PRIO, 'should'), pn_oggi()],
              'iiiisss');
        $n++;
    }
    sec_json(['ok' => true, 'applied' => $n]);
}
if ($ajax === 'tpl_delete') {
    sec_q("DELETE FROM plan_templates WHERE id = ? AND user_id = ?", [sec_int(sec_in('id'), 0, PHP_INT_MAX, 0), $uid], 'ii');
    sec_json(['ok' => true]);
}

sec_die_json(t('piano.api_azione_sconosciuta'), 400, 'bad_request');
