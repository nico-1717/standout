<?php
/* =====================================================================
   NISAMA — I SOTTO-TASK DELLO STUDIO

   Qui non c'è una tabella nuova, ed è la cosa più importante da sapere
   leggendo questo file. Un sotto-task di «to study» È una riga di `projects`:
   ha un padre (il progetto della materia), un evento di studio a cui è
   appeso (`study_event_id`) e due date sue dentro il periodo di quell'evento.

   Perché non una tabella dedicata. «Integrali tripli» non è un oggetto che
   somiglia a un sotto-progetto: è lo stesso oggetto, con in più due date.
   Tenendoli separati sarebbe servita una regola per ogni caso — rinomino di
   qua e cosa succede di là, cancello di là e cosa resta di qua — e ognuna di
   quelle regole è un bug che aspetta il suo giorno.

   Il guadagno vero sta nei pomodori. `pomodoro_sessions.project_id` esiste da
   sempre: se il sotto-task è un progetto, allora «ho fatto tre pomodori sugli
   integrali tripli» è un dato che il sistema possiede già, senza collegamenti
   nuovi da mantenere. Tutte le statistiche promesse — la curva della fatica,
   la stima contro il consuntivo, la materia trascurata — poggiano su questo,
   e con due tabelle da tenere allineate sarebbero attendibili solo finché
   l'allineamento regge.
   ===================================================================== */

require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check_api.php';

$uid = (int)$utente_corrente['id'];
$ajax = sec_str(sec_in('st_ajax'), 30);

/* Ogni scrittura passa da qui: token di sessione, origine, limite di
   frequenza.
   Le letture non passano dal limite delle scritture. Sono POST solo per
   portarsi dietro il token di sessione, ma non cambiano niente: cambiando mese
   avanti e indietro si arrivava al tetto e la risposta diventava un 429, che
   nel browser si legge come «non riesco a raggiungere il server». */
$SOLA_LETTURA = ['eventi', 'list'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($ajax, $SOLA_LETTURA, true)) {
    sec_guard_write($uid, 'study', 0, 300, 60);
}

/* ---------------------------------------------------------------------
   Aiutanti
   --------------------------------------------------------------------- */

/** L'evento di studio, se è davvero suo. Null altrimenti. */
function st_evento(int $uid, int $eventId): ?array {
    if ($eventId <= 0) return null;
    return sec_one("SELECT id, event_date, end_date, project_id, title
                    FROM planner_events WHERE id = ? AND user_id = ?", [$eventId, $uid], 'ii');
}

/** L'ultimo giorno di un evento: end_date se c'è, altrimenti il giorno stesso. */
function st_fine(array $ev): string {
    return !empty($ev['end_date']) ? (string)$ev['end_date'] : (string)$ev['event_date'];
}

/**
 * Riporta un periodo dentro quello dell'evento padre.
 *
 * È la regola che tiene in piedi tutta la pagina: un sotto-task fuori dal
 * periodo del padre non vuol dire niente — «studio gli integrali tripli il 25»
 * dentro un blocco che finisce il 20 è una riga che nessuna vista saprebbe
 * dove disegnare. Si stringe invece di rifiutare: chi sta trascinando una
 * barra vuole vederla fermarsi al bordo, non ricevere un errore.
 */
function st_dentro_al_padre(?string $da, ?string $a, array $ev): array {
    $pDa = (string)$ev['event_date'];
    $pA  = st_fine($ev);

    if ($da === null || $da === '') $da = $pDa;
    if ($da < $pDa) $da = $pDa;
    if ($da > $pA)  $da = $pA;

    if ($a === null || $a === '' || $a < $da) $a = $da;
    if ($a > $pA) $a = $pA;

    // uguale all'inizio si salva come NULL: è il modo in cui il database dice
    // «un giorno solo», lo stesso di planner_events
    return [$da, $a === $da ? null : $a];
}

/** Un sotto-task nel formato che vuole la pagina. */
function st_task_out(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'color'       => (string)$r['color'],
        'event_id'    => (int)$r['study_event_id'],
        'start'       => (string)$r['study_start'],
        'end'         => !empty($r['study_end']) ? (string)$r['study_end'] : (string)$r['study_start'],
        'stimati'     => $r['study_pomodori_stimati'] !== null ? (int)$r['study_pomodori_stimati'] : null,
        'done'        => $r['status'] === 'completed',
        // un ripasso non genera i propri ripassi: la pagina lo deve sapere
        'ripasso'     => !empty($r['study_ripasso_di']),
        'fc'          => !empty($r['study_fc_categoria']) ? (int)$r['study_fc_categoria'] : null,
        /* I pomodori davvero fatti su questo argomento. Arrivano da una LEFT
           JOIN e non da una query per riga: con venti sotto-task sarebbero
           venti interrogazioni per disegnare una schermata. */
        'fatti'       => (int)($r['pomodori_fatti'] ?? 0),
        'secondi'     => (int)($r['secondi_fatti'] ?? 0),
    ];
}

/* Quanto si è fatto in una settimana. Fuori dal suo endpoint perché una
   funzione dichiarata dentro un `if` esiste solo se quel ramo viene eseguito:
   funziona finché la si chiama lì dentro, e smette il giorno che qualcuno la
   chiama da un'altra parte. */
function st_settimana(int $uid, string $da, string $a): array {
    $p = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds),0) AS s,
                         COUNT(DISTINCT DATE(start_time)) AS giorni
                  FROM pomodoro_sessions
                  WHERE user_id = ? AND end_time IS NOT NULL
                    AND DATE(start_time) >= ? AND DATE(start_time) < ?", [$uid, $da, $a], 'iss');
    $t = sec_one("SELECT COUNT(*) AS n FROM projects
                  WHERE user_id = ? AND study_event_id IS NOT NULL AND status = 'completed'
                    AND updated_at >= ? AND updated_at < ?", [$uid, $da, $a], 'iss');
    return [
        'pomodori' => (int)($p['n'] ?? 0),
        'ore'      => round(((int)($p['s'] ?? 0)) / 3600, 1),
        'giorni'   => (int)($p['giorni'] ?? 0),
        'argomenti'=> (int)($t['n'] ?? 0),
    ];
}

/** L'id del calendario o della categoria con un certo ruolo. */
function st_per_ruolo(int $uid, string $tabella, string $ruolo): ?int {
    $t = $tabella === 'cal' ? 'planner_calendars' : 'planner_categories';
    $r = sec_one("SELECT id FROM $t WHERE user_id = ? AND role = ? LIMIT 1", [$uid, $ruolo], 'is');
    return $r ? (int)$r['id'] : null;
}

/* I filtri salvati: cosa si vuole vedere oltre ai blocchi di studio.
   NULL vuol dire «non ha mai scelto», e allora si parte dal minimo — studio e
   date importanti — che è quello che la pagina mostrava prima che il filtro
   esistesse. */
function st_filtri(int $uid): array {
    $r = sec_one("SELECT study_filtri FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    $d = ($r && !empty($r['study_filtri'])) ? json_decode((string)$r['study_filtri'], true) : null;
    if (!is_array($d)) $d = [];
    return [
        /* L'interruttore generale: spento, del planner non si vede niente e le
           sei spunte sotto restano com'erano. Serve a togliere di mezzo tutto
           con un gesto e a rimetterlo com'era con un altro, senza dover
           ricordare quali voci erano accese. */
        'on'      => isset($d['on'])      ? (int)!!$d['on']      : 1,
        'highest' => isset($d['highest']) ? (int)!!$d['highest'] : 1,
        'high'    => isset($d['high'])    ? (int)!!$d['high']    : 1,
        'routine' => isset($d['routine']) ? (int)!!$d['routine'] : 0,
        'habit'   => isset($d['habit'])   ? (int)!!$d['habit']   : 0,
        'cal'     => array_values(array_filter(array_map('intval', (array)($d['cal'] ?? [])))),
        'cat'     => array_values(array_filter(array_map('intval', (array)($d['cat'] ?? [])))),
    ];
}

/* ---------------------------------------------------------------------
   FILTRI: leggere e salvare
   --------------------------------------------------------------------- */
if ($ajax === 'filtri') {
    /* Insieme ai filtri si mandano anche i calendari e le categorie, perché il
       popup li deve elencare: due richieste per aprire un pannello sarebbero
       due attese, e la seconda arriverebbe a pannello già aperto e vuoto. */
    sec_json([
        'ok' => true,
        'filtri' => st_filtri($uid),
        /* Il principale per primo, poi in ordine alfabetico: è quello che si
           cerca per primo e cercarlo in mezzo agli altri è tempo perso.
           Fuori il calendario e la categoria dello studio: quelli si vedono
           sempre, ed elencarli qui vorrebbe dire offrire una spunta che non
           cambia niente — o peggio, che sembra poterli spegnere. */
        'calendari' => sec_all("SELECT id, name, color FROM planner_calendars
                                WHERE user_id = ? AND (role IS NULL OR role <> 'study')
                                ORDER BY is_default DESC, name", [$uid], 'i'),
        'categorie' => sec_all("SELECT id, name, color FROM planner_categories
                                WHERE user_id = ? AND COALESCE(is_hidden,0)=0
                                  AND (role IS NULL OR role <> 'study')
                                ORDER BY name", [$uid], 'i'),
    ]);
}

if ($ajax === 'salva_filtri') {
    $in = sec_in('filtri');
    $d = is_string($in) ? json_decode($in, true) : null;
    if (!is_array($d)) sec_die_json(t('comune.errore_generico'), 422, 'invalid');

    /* Si riscrive solo quello che conosciamo, non quello che arriva: un client
       modificato a mano potrebbe spedire un JSON di qualunque forma, e questa
       colonna la rileggiamo noi a ogni apertura. */
    $pulito = [
        'on'      => (int)!!($d['on'] ?? 1),
        'highest' => (int)!!($d['highest'] ?? 1),
        'high'    => (int)!!($d['high'] ?? 1),
        'routine' => (int)!!($d['routine'] ?? 0),
        'habit'   => (int)!!($d['habit'] ?? 0),
        'cal'     => array_values(array_unique(array_filter(array_map('intval', (array)($d['cal'] ?? []))))),
        'cat'     => array_values(array_unique(array_filter(array_map('intval', (array)($d['cat'] ?? []))))),
    ];
    // la riga delle impostazioni può non esistere ancora: si crea al volo
    sec_q("INSERT INTO planner_settings (user_id, study_filtri) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE study_filtri = VALUES(study_filtri)",
          [$uid, json_encode($pulito, JSON_UNESCAPED_UNICODE)], 'is');
    sec_json(['ok' => true, 'filtri' => $pulito]);
}

/* ---------------------------------------------------------------------
   STATO: come sei messo
   Un endpoint solo per il conto alla rovescia, il semaforo, la curva della
   fatica e la materia trascurata. Sono domande diverse ma poggiano tutte
   sugli stessi tre insiemi — i blocchi, i loro argomenti, i pomodori fatti —
   e leggerli quattro volte per quattro riquadri sarebbe quattro volte lo
   stesso lavoro.
   --------------------------------------------------------------------- */
if ($ajax === 'stato') {
    $oggi = date('Y-m-d');
    $calStudio = st_per_ruolo($uid, 'cal', 'study');

    /* IL RITMO VERO. Non «quanti pomodori stanno in un giorno» ma quanti ne
       fai tu: la media sui giorni in cui hai davvero lavorato negli ultimi
       sessanta. Contare anche i giorni a zero abbasserebbe la media fino a
       dire che non studi mai; contare solo i giorni pieni direbbe che ne fai
       otto al giorno. In mezzo c'è il numero che serve a capire se ce la fai. */
    $r = sec_one("SELECT COUNT(*) AS n, COUNT(DISTINCT DATE(start_time)) AS giorni
                  FROM pomodoro_sessions
                  WHERE user_id = ? AND end_time IS NOT NULL
                    AND start_time >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)", [$uid], 'i');
    $ritmo = ((int)($r['giorni'] ?? 0) > 0) ? ((int)$r['n'] / (int)$r['giorni']) : 0.0;

    /* QUANTO TI SBAGLI A STIMARE. Il rapporto fra pomodori fatti e stimati su
       tutti gli argomenti già chiusi che avevano una stima. Sotto 1 vuol dire
       che sovrastimi, sopra 1 che sottostimi — ed è quasi sempre sopra. */
    $st = sec_all("SELECT p.study_pomodori_stimati AS stima, COUNT(s.id) AS fatti, p.study_start
                   FROM projects p
                   LEFT JOIN pomodoro_sessions s
                          ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
                   WHERE p.user_id = ? AND p.study_event_id IS NOT NULL
                     AND p.status = 'completed' AND p.study_pomodori_stimati > 0
                   GROUP BY p.id ORDER BY p.study_start, p.id", [$uid], 'i');

    $rapporti = [];
    foreach ($st as $x) {
        $s = (int)$x['stima'];
        if ($s > 0) $rapporti[] = (int)$x['fatti'] / $s;
    }
    $errore = $rapporti ? array_sum($rapporti) / count($rapporti) : 1.0;

    /* LA CURVA DELLA FATICA. Non la media, la PENDENZA: se il quinto argomento
       ti è costato il 60% in più della stima e il sesto l'80%, la fatica sta
       salendo e i prossimi costeranno ancora di più. Si confronta la seconda
       metà degli argomenti chiusi con la prima: due medie, non una retta di
       regressione, perché con sei o sette punti una retta dà un numero
       preciso su niente. */
    $tendenza = 0.0;
    if (count($rapporti) >= 4) {
        $meta = (int)floor(count($rapporti) / 2);
        $primi = array_slice($rapporti, 0, $meta);
        $ultimi = array_slice($rapporti, $meta);
        $a = array_sum($primi) / count($primi);
        $b = array_sum($ultimi) / count($ultimi);
        if ($a > 0) $tendenza = ($b - $a) / $a;   // +0.3 = il 30% peggio di prima
    }

    /* I blocchi di studio aperti, cioè quelli che non sono ancora finiti o che
       hanno ancora argomenti da spuntare. */
    $blocchi = sec_all(
        "SELECT e.id, e.project_id, e.event_date, e.end_date, e.importance, e.title,
                e.start_time, e.study_exam_id,
                p.name AS pnome, p.color AS pcolore
         FROM planner_events e
         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
         WHERE e.user_id = ? AND e.rrule IS NULL AND COALESCE(e.is_cancelled,0) = 0
           AND (e.calendar_id = ? OR e.importance <> 'normal')
           AND COALESCE(e.end_date, e.event_date) >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)",
        [$uid, $calStudio], 'ii');

    $idBlocchi = [];
    foreach ($blocchi as $b) if ((string)$b['importance'] === 'normal') $idBlocchi[] = (int)$b['id'];

    /* Gli argomenti di tutti i blocchi, in una query sola. */
    $perProgetto = [];
    $senzaProgetto = 0;
    if ($idBlocchi) {
        $ph = implode(',', array_fill(0, count($idBlocchi), '?'));
        $tk = sec_all(
            "SELECT p.id, p.status, p.study_event_id, p.study_pomodori_stimati,
                    COUNT(s.id) AS fatti
             FROM projects p
             LEFT JOIN pomodoro_sessions s
                    ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
             WHERE p.user_id = ? AND p.study_event_id IN ($ph)
             GROUP BY p.id",
            array_merge([$uid], $idBlocchi));
        $progDiBlocco = [];
        foreach ($blocchi as $b) $progDiBlocco[(int)$b['id']] = $b['project_id'] !== null ? (int)$b['project_id'] : 0;
        foreach ($tk as $t) {
            $pid = $progDiBlocco[(int)$t['study_event_id']] ?? 0;
            if (!isset($perProgetto[$pid])) $perProgetto[$pid] = ['tot' => 0, 'fatti' => 0, 'pom' => 0, 'stima_rimasta' => 0, 'senza_stima' => 0];
            $perProgetto[$pid]['tot']++;
            $perProgetto[$pid]['pom'] += (int)$t['fatti'];
            if ($t['status'] === 'completed') {
                $perProgetto[$pid]['fatti']++;
            } else {
                $s = (int)($t['study_pomodori_stimati'] ?? 0);
                if ($s > 0) $perProgetto[$pid]['stima_rimasta'] += $s;
                else $perProgetto[$pid]['senza_stima']++;
            }
        }
    }
    foreach ($blocchi as $b) {
        if ((string)$b['importance'] === 'normal' && $b['project_id'] === null) $senzaProgetto++;
    }

    /* Quando è l'esame: la prima data importante collegata a quel progetto.
       Non `university_exams.exam_date`, che è la data in cui l'esame è stato
       SUPERATO — serve alla media, non al conto alla rovescia. */
    $esame = [];
    foreach ($blocchi as $b) {
        if ((string)$b['importance'] === 'normal') continue;
        if ($b['event_date'] < $oggi) continue;
        $pid = $b['project_id'] !== null ? (int)$b['project_id'] : 0;
        if (!isset($esame[$pid]) || $b['event_date'] < $esame[$pid]['data']) {
            $esame[$pid] = ['data' => (string)$b['event_date'], 'titolo' => (string)$b['title'],
                            'ora' => substr((string)$b['start_time'], 0, 5)];
        }
    }

    /* Da quanti giorni non tocchi una materia. Con quattro esami aperti uno lo
       trascuri sempre, e non è quello che credi. */
    $ultimo = [];
    foreach (sec_all("SELECT p2.id AS radice, MAX(s.start_time) AS quando
                      FROM pomodoro_sessions s
                      JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
                      JOIN projects p2 ON p2.id = COALESCE(p.parent_id, p.id) AND p2.user_id = s.user_id
                      WHERE s.user_id = ? AND s.end_time IS NOT NULL
                      GROUP BY p2.id", [$uid], 'i') as $x) {
        $ultimo[(int)$x['radice']] = (string)$x['quando'];
    }

    $materie = [];
    foreach ($perProgetto as $pid => $d) {
        $nome = null; $colore = null;
        foreach ($blocchi as $b) {
            if ((int)($b['project_id'] ?? 0) === $pid) { $nome = $b['pnome']; $colore = $b['pcolore']; break; }
        }
        $rimasti = $d['tot'] - $d['fatti'];

        /* Quanto costeranno gli argomenti che restano. Chi non ha una stima
           prende la media di quello che è costato finora: dire «non lo so» e
           lasciare il conto a metà non aiuta nessuno. */
        $mediaFatta = $d['fatti'] > 0 ? $d['pom'] / $d['fatti'] : 3.0;
        $stimaGrezza = $d['stima_rimasta'] + $d['senza_stima'] * $mediaFatta;
        // il tuo errore medio, peggiorato dalla pendenza: se stai rallentando,
        // i prossimi costano più di quelli di prima
        $previsti = $stimaGrezza * max(0.5, $errore) * (1 + max(0.0, $tendenza));

        $giorni = null; $disponibili = null; $semaforo = null;
        if (isset($esame[$pid])) {
            $giorni = max(0, (int)floor((strtotime($esame[$pid]['data']) - strtotime($oggi)) / 86400));
            $disponibili = $giorni * $ritmo;
            if ($disponibili <= 0)          $semaforo = $previsti > 0 ? 'rosso' : 'verde';
            elseif ($previsti <= $disponibili * 0.7) $semaforo = 'verde';
            elseif ($previsti <= $disponibili)       $semaforo = 'giallo';
            else                                     $semaforo = 'rosso';
        }

        $materie[] = [
            'project_id' => $pid ?: null,
            'nome'       => $nome,
            'colore'     => $colore,
            'tot'        => $d['tot'],
            'fatti'      => $d['fatti'],
            'rimasti'    => $rimasti,
            'pom_fatti'  => $d['pom'],
            'previsti'   => round($previsti, 1),
            'esame'      => $esame[$pid] ?? null,
            'giorni'     => $giorni,
            'disponibili'=> $disponibili !== null ? round($disponibili, 1) : null,
            'semaforo'   => $semaforo,
            'da_giorni'  => isset($ultimo[$pid])
                ? (int)floor((strtotime($oggi) - strtotime(substr($ultimo[$pid], 0, 10))) / 86400)
                : null,
        ];
    }

    // prima chi ha il semaforo peggiore, poi chi ha l'esame più vicino
    $peso = ['rosso' => 0, 'giallo' => 1, 'verde' => 2, null => 3];
    usort($materie, function ($a, $b) use ($peso) {
        $pa = $peso[$a['semaforo']] ?? 3; $pb = $peso[$b['semaforo']] ?? 3;
        if ($pa !== $pb) return $pa - $pb;
        if ($a['giorni'] === null) return 1;
        if ($b['giorni'] === null) return -1;
        return $a['giorni'] - $b['giorni'];
    });

    sec_json([
        'ok' => true,
        'ritmo' => round($ritmo, 2),
        'errore' => round($errore, 2),
        'tendenza' => round($tendenza, 2),
        'campioni' => count($rapporti),
        'senza_progetto' => $senzaProgetto,
        'materie' => $materie,
    ]);
}

/* ---------------------------------------------------------------------
   RIPASSO A INTERVALLI CRESCENTI
   Spuntato un argomento, si propongono tre ripassi a +1, +3 e +7 giorni.
   È la curva dell'oblio, ed è la sola cosa in tutta questa pagina che cambia
   davvero quanto rimane in testa: chi ripassa a intervalli crescenti rende
   più del doppio di chi rilegge la sera prima.
   I ripassi sono argomenti come gli altri — stessa tabella, stesse date,
   stessi pomodori sopra — con in più il puntatore a chi ripassano.
   --------------------------------------------------------------------- */
if ($ajax === 'ripassi') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('projects', $id, $uid)) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');

    $t = sec_one("SELECT id, name, color, parent_id, study_event_id, study_start, study_end
                  FROM projects WHERE id = ? AND user_id = ? AND study_event_id IS NOT NULL",
                 [$id, $uid], 'ii');
    if (!$t) sec_die_json(t('todo.study_non_e_argomento'), 422, 'invalid');

    // già fatti: non se ne creano altri, e non è un errore — capita di
    // spuntare, disfare e rispuntare lo stesso argomento
    $c = sec_one("SELECT COUNT(*) AS n FROM projects WHERE user_id = ? AND study_ripasso_di = ?",
                 [$uid, $id], 'ii');
    if ((int)($c['n'] ?? 0) > 0) sec_json(['ok' => true, 'creati' => 0]);

    /* Il muro: la prima data importante che viene dopo. Un ripasso dopo
       l'esame non ripassa niente, e proporlo vorrebbe dire riempire il
       calendario di cose che non si faranno mai. */
    $catStudio = st_per_ruolo($uid, 'cat', 'study');
    $limite = null;
    $ev = sec_one("SELECT project_id FROM planner_events WHERE id = ? AND user_id = ?",
                  [(int)$t['study_event_id'], $uid], 'ii');
    if ($ev && $ev['project_id'] !== null && $catStudio !== null) {
        $e = sec_one("SELECT MIN(event_date) AS d FROM planner_events
                      WHERE user_id = ? AND project_id = ? AND importance <> 'normal'
                        AND event_date >= CURDATE()", [$uid, (int)$ev['project_id']], 'ii');
        if ($e && !empty($e['d'])) $limite = (string)$e['d'];
    }

    $base = !empty($t['study_end']) ? (string)$t['study_end'] : (string)$t['study_start'];
    $creati = [];
    foreach ([1 => 1, 2 => 3, 3 => 7] as $n => $giorni) {
        $quando = date('Y-m-d', strtotime($base . ' +' . $giorni . ' day'));
        if ($limite !== null && $quando > $limite) continue;

        /* Un ripasso vive nel SUO blocco, che è quello del giorno in cui cade:
           appenderlo al blocco dell'argomento originale lo farebbe comparire
           in un periodo già chiuso, dove nessuno lo guarda più. Se in quel
           giorno non c'è un blocco, se ne crea uno di un giorno solo. */
        $blocco = sec_one("SELECT e.id FROM planner_events e
                           WHERE e.user_id = ? AND e.project_id <=> ?
                             AND e.importance = 'normal'
                             AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?
                           ORDER BY e.id LIMIT 1",
                          [$uid, $ev['project_id'] ?? null, $quando, $quando]);
        if (!$blocco) {
            $cal = st_per_ruolo($uid, 'cal', 'study');
            sec_q("INSERT INTO planner_events
                   (user_id, title, event_date, start_time, end_time, is_all_day,
                    category_id, calendar_id, project_id, importance, item_type, notify_type, notify_minutes)
                   VALUES (?,?,?,'00:00:00','23:59:00',1,?,?,?,'normal','event','none',0)",
                  [$uid, t('todo.study_ripasso_titolo'), $quando, $catStudio, $cal, $ev['project_id'] ?? null]);
            $bloccoId = sec_insert_id();
        } else {
            $bloccoId = (int)$blocco['id'];
        }

        sec_q("INSERT INTO projects
               (user_id, parent_id, study_event_id, study_start, study_end,
                study_ripasso_di, study_ripasso_n, name, color, color_edited, status, created_at)
               VALUES (?,?,?,?,NULL,?,?,?,?,0,'in_progress',NOW())",
              [$uid, $t['parent_id'], $bloccoId, $quando, $id, $n,
               t('todo.study_ripasso_n', ['n' => $giorni, 'nome' => (string)$t['name']]), (string)$t['color']]);
        $creati[] = ['n' => $n, 'quando' => $quando];
    }
    sec_json(['ok' => true, 'creati' => count($creati), 'ripassi' => $creati]);
}

/* ---------------------------------------------------------------------
   COSA STUDIO OGGI
   Serve al pomodoro: aprendolo, il progetto giusto è già scelto.
   È il collegamento senza il quale tutto il resto non ha benzina — le
   statistiche, la curva della fatica, il semaforo poggiano sui pomodori
   attaccati agli argomenti, e quel collegamento saltava ogni volta che si
   aveva fretta.
   --------------------------------------------------------------------- */
if ($ajax === 'oggi') {
    $oggi = date('Y-m-d');
    $calStudio = st_per_ruolo($uid, 'cal', 'study');

    /* Si parte dai BLOCCHI e non dagli argomenti.
       Prima si leggevano solo gli argomenti, e un blocco che non ne ha —
       cioè il caso normale appena lo si crea — non risultava da nessuna
       parte: il planner diceva «niente da studiare oggi» mentre nel to study
       c'erano due blocchi larghi mezzo mese. Il blocco è la cosa che esiste
       sempre; gli argomenti sono un dettaglio che può esserci o no. */
    $blocchi = sec_all(
        "SELECT e.id, e.title, e.is_completed, e.project_id, e.color,
                m.name AS materia, m.color AS materia_colore
         FROM planner_events e
         LEFT JOIN projects m ON m.id = e.project_id AND m.user_id = e.user_id
         WHERE e.user_id = ? AND e.calendar_id = ?
           AND e.rrule IS NULL AND COALESCE(e.is_cancelled, 0) = 0
           AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?
         ORDER BY e.event_date, e.id",
        [$uid, $calStudio, $oggi, $oggi], 'iiss');

    /* Gli argomenti di oggi di tutti quei blocchi, in una query sola: uno per
       blocco sarebbe un viaggio al database per riga disegnata. */
    $perBlocco = [];
    $ids = [];
    foreach ($blocchi as $b) $ids[] = (int)$b['id'];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $arg = sec_all(
            "SELECT p.id, p.name, p.color, p.status, p.study_event_id,
                    p.study_ripasso_di, p.study_fc_categoria, p.study_pomodori_stimati,
                    COUNT(s.id) AS fatti
             FROM projects p
             LEFT JOIN pomodoro_sessions s
                    ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
             WHERE p.user_id = ? AND p.study_event_id IN ($ph)
               AND p.status <> 'completed'
               AND p.study_start <= ? AND COALESCE(p.study_end, p.study_start) >= ?
             GROUP BY p.id
             ORDER BY p.study_start, p.custom_order, p.id",
            array_merge([$uid], $ids, [$oggi, $oggi]));
        foreach ($arg as $a) {
            $perBlocco[(int)$a['study_event_id']][] = [
                'id'      => (int)$a['id'],
                'name'    => (string)$a['name'],
                'color'   => (string)$a['color'],
                'ripasso' => $a['study_ripasso_di'] !== null,
                'fc'      => $a['study_fc_categoria'] !== null ? (int)$a['study_fc_categoria'] : null,
                'stimati' => $a['study_pomodori_stimati'] !== null ? (int)$a['study_pomodori_stimati'] : null,
                'fatti'   => (int)$a['fatti'],
            ];
        }
    }

    $fuoriBlocchi = [];
    /* La lista piatta serve al pomodoro: «su cosa posso far partire un
       pomodoro adesso». Un blocco con argomenti offre i suoi argomenti; uno
       senza offre sé stesso, attraverso il progetto a cui è collegato — che è
       poi dove i pomodori si attaccherebbero comunque. */
    $piatta = [];

    foreach ($blocchi as $b) {
        $bid = (int)$b['id'];
        $argomenti = $perBlocco[$bid] ?? [];
        $colore = !empty($b['materia_colore']) ? (string)$b['materia_colore']
                : (!empty($b['color']) ? (string)$b['color'] : null);

        $fuoriBlocchi[] = [
            'event_id'   => $bid,
            'titolo'     => (string)$b['title'],
            'color'      => $colore,
            'done'       => (int)$b['is_completed'] === 1,
            'materia'    => $b['materia'],
            'materia_id' => $b['project_id'] !== null ? (int)$b['project_id'] : null,
            'argomenti'  => $argomenti,
        ];

        if ($argomenti) {
            foreach ($argomenti as $a) {
                $piatta[] = array_merge($a, [
                    'materia'    => $b['materia'],
                    'materia_id' => $b['project_id'] !== null ? (int)$b['project_id'] : null,
                    'blocco'     => (string)$b['title'],
                ]);
            }
        } elseif ($b['project_id'] !== null) {
            $piatta[] = [
                'id'         => (int)$b['project_id'],
                'name'       => (string)$b['title'],
                'color'      => $colore,
                'ripasso'    => false,
                'fc'         => null,
                'stimati'    => null,
                'fatti'      => 0,
                'materia'    => $b['materia'],
                'materia_id' => (int)$b['project_id'],
                'blocco'     => (string)$b['title'],
            ];
        }
    }

    sec_json(['ok' => true, 'oggi' => $oggi, 'blocchi' => $fuoriBlocchi, 'argomenti' => $piatta]);
}

/* ---------------------------------------------------------------------
   FLASHCARD: il ripasso che si verifica da solo
   --------------------------------------------------------------------- */
if ($ajax === 'collega_fc') {
    $id  = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    $cat = sec_int(sec_in('fc'), 0, PHP_INT_MAX, 0);
    if (!sec_owns('projects', $id, $uid)) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');
    if ($cat > 0) {
        $ok = sec_one("SELECT id FROM fc_categories WHERE id = ? AND user_id = ?", [$cat, $uid], 'ii');
        if (!$ok) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');
    }
    sec_q("UPDATE projects SET study_fc_categoria = ? WHERE id = ? AND user_id = ? AND study_event_id IS NOT NULL",
          [$cat > 0 ? $cat : null, $id, $uid], 'iii');
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   PIANIFICAZIONE A RITROSO
   Dall'esame all'indietro, non da oggi in avanti. È la differenza fra «vediamo
   fin dove arrivo» e «questo deve stare dentro»: partendo da oggi si riempiono
   i primi giorni e ci si accorge di essere in ritardo alla fine, partendo
   dalla data si scopre subito se il piano non ci sta — che è l'unico momento
   in cui si può ancora fare qualcosa.
   I giorni già occupati nel planner valgono meno: se giovedì hai lezione tutto
   il giorno, mettere lì quattro pomodori è scrivere un piano che si sa già che
   salterà.
   --------------------------------------------------------------------- */
if ($ajax === 'pianifica') {
    $progetto = sec_int(sec_in('project_id'), 1, PHP_INT_MAX, 0);
    $applica  = sec_bool(sec_in('applica'));
    if (!$progetto || !sec_owns('projects', $progetto, $uid)) {
        sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');
    }

    // la data d'esame: la prima data importante di quel progetto
    $e = sec_one("SELECT MIN(event_date) AS d FROM planner_events
                  WHERE user_id = ? AND project_id = ? AND importance <> 'normal'
                    AND event_date >= CURDATE()", [$uid, $progetto], 'ii');
    if (!$e || empty($e['d'])) sec_die_json(t('todo.study_serve_esame'), 422, 'invalid');
    $esame = (string)$e['d'];

    $calStudio = st_per_ruolo($uid, 'cal', 'study');

    /* Gli argomenti da piazzare: quelli non ancora spuntati dei blocchi di
       questa materia. Si prendono anche quelli che hanno già delle date — una
       pianificazione a ritroso rifà il piano, non aggiunge un secondo piano
       sopra al primo. */
    $argomenti = sec_all(
        "SELECT p.id, p.name, p.study_pomodori_stimati, p.custom_order, p.study_start
         FROM projects p
         JOIN planner_events e ON e.id = p.study_event_id AND e.user_id = p.user_id
         WHERE p.user_id = ? AND e.project_id = ? AND e.calendar_id <=> ?
           AND p.status <> 'completed' AND p.study_ripasso_di IS NULL
         ORDER BY p.study_start, p.custom_order, p.id",
        [$uid, $progetto, $calStudio]);
    if (!$argomenti) sec_json(['ok' => true, 'niente' => true, 'piano' => []]);

    /* Il ritmo e l'errore: gli stessi numeri dello stato. Un piano costruito
       su «otto pomodori al giorno» quando ne fai cinque è un piano che nasce
       già sbagliato del 60%. */
    $rr = sec_one("SELECT COUNT(*) AS n, COUNT(DISTINCT DATE(start_time)) AS giorni
                   FROM pomodoro_sessions
                   WHERE user_id = ? AND end_time IS NOT NULL
                     AND start_time >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)", [$uid], 'i');
    $ritmo = ((int)($rr['giorni'] ?? 0) > 0) ? ((int)$rr['n'] / (int)$rr['giorni']) : 4.0;
    if ($ritmo < 1) $ritmo = 4.0;

    $sr = sec_all("SELECT p.study_pomodori_stimati AS stima, COUNT(s.id) AS fatti
                   FROM projects p
                   LEFT JOIN pomodoro_sessions s
                          ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
                   WHERE p.user_id = ? AND p.study_event_id IS NOT NULL
                     AND p.status = 'completed' AND p.study_pomodori_stimati > 0
                   GROUP BY p.id", [$uid], 'i');
    $rap = [];
    foreach ($sr as $x) { $s = (int)$x['stima']; if ($s > 0) $rap[] = (int)$x['fatti'] / $s; }
    $errore = $rap ? array_sum($rap) / count($rap) : 1.0;

    /* Quanto è già pieno ogni giorno da qui all'esame. Non si contano i minuti
       ma quante cose ci sono: la precisione qui è finta, e sapere che giovedì
       è affollato basta a spostarci meno roba. */
    $pieni = [];
    foreach (sec_all("SELECT event_date, COUNT(*) AS n FROM planner_events
                      WHERE user_id = ? AND event_date BETWEEN CURDATE() AND ?
                        AND rrule IS NULL AND COALESCE(is_cancelled,0) = 0
                        AND (calendar_id <> ? OR calendar_id IS NULL)
                      GROUP BY event_date", [$uid, $esame, $calStudio], 'isi') as $x) {
        $pieni[(string)$x['event_date']] = (int)$x['n'];
    }

    /* I giorni disponibili, dall'ultimo prima dell'esame all'indietro fino a
       oggi. Il giorno dell'esame non si usa: quello si ripassa, non si studia
       per la prima volta. */
    $giorni = [];
    for ($d = date('Y-m-d', strtotime($esame . ' -1 day')); $d >= date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
        $occupato = $pieni[$d] ?? 0;
        // oltre le cinque cose in un giorno, quel giorno è di qualcun altro
        $capienza = $ritmo * max(0.2, 1 - min(1.0, $occupato / 5));
        $giorni[] = ['data' => $d, 'capienza' => $capienza, 'usato' => 0.0];
    }
    if (!$giorni) sec_die_json(t('todo.study_esame_troppo_vicino'), 422, 'invalid');

    /* Si riempie dall'ultimo giorno all'indietro, e gli argomenti si prendono
       dall'ultimo al primo: l'ordine in cui sono stati scritti è l'ordine in
       cui vanno studiati, quindi l'ultimo argomento va nell'ultimo giorno. */
    $piano = [];
    $g = 0;
    $fuori = 0;
    for ($i = count($argomenti) - 1; $i >= 0; $i--) {
        $a = $argomenti[$i];
        $stima = (int)($a['study_pomodori_stimati'] ?? 0);
        if ($stima <= 0) $stima = 3;
        $costo = $stima * max(0.5, $errore);

        $da = null; $fine = null; $resta = $costo;
        while ($resta > 0 && $g < count($giorni)) {
            $spazio = $giorni[$g]['capienza'] - $giorni[$g]['usato'];
            if ($spazio <= 0.01) { $g++; continue; }
            $preso = min($spazio, $resta);
            $giorni[$g]['usato'] += $preso;
            $resta -= $preso;
            if ($fine === null) $fine = $giorni[$g]['data'];
            $da = $giorni[$g]['data'];
            if ($resta > 0.01) $g++;
        }
        if ($da === null) { $fuori++; continue; }   // non c'è più spazio: resta senza date
        $piano[] = ['id' => (int)$a['id'], 'nome' => (string)$a['name'],
                    'da' => $da, 'a' => $fine, 'pomodori' => round($costo, 1)];
    }
    $piano = array_reverse($piano);

    if ($applica) {
        foreach ($piano as $x) {
            /* Il blocco che ospita l'argomento deve coprire le date nuove: si
               allarga se serve. Un argomento fuori dal periodo del suo blocco
               è una riga che nessuna vista sa dove disegnare. */
            $t = sec_one("SELECT study_event_id FROM projects WHERE id = ? AND user_id = ?", [$x['id'], $uid], 'ii');
            if ($t && !empty($t['study_event_id'])) {
                $ev = sec_one("SELECT event_date, end_date FROM planner_events WHERE id = ? AND user_id = ?",
                              [(int)$t['study_event_id'], $uid], 'ii');
                if ($ev) {
                    $inizio = min((string)$ev['event_date'], $x['da']);
                    $fine   = max(st_fine($ev), $x['a']);
                    sec_q("UPDATE planner_events SET event_date = ?, end_date = ? WHERE id = ? AND user_id = ?",
                          [$inizio, $fine === $inizio ? null : $fine, (int)$t['study_event_id'], $uid]);
                }
            }
            sec_q("UPDATE projects SET study_start = ?, study_end = ? WHERE id = ? AND user_id = ?",
                  [$x['da'], $x['a'] === $x['da'] ? null : $x['a'], $x['id'], $uid]);
        }
    }

    $servono = 0.0;
    foreach ($piano as $x) $servono += $x['pomodori'];
    foreach ($argomenti as $a) {
        $st = (int)($a['study_pomodori_stimati'] ?? 0);
        if ($st <= 0) $st = 3;
    }
    $spazio = 0.0;
    foreach ($giorni as $x) $spazio += $x['capienza'];

    sec_json([
        'ok' => true,
        'esame' => $esame,
        'giorni' => count($giorni),
        'ritmo' => round($ritmo, 2),
        'errore' => round($errore, 2),
        'servono' => round($servono, 1),
        'spazio' => round($spazio, 1),
        'fuori' => $fuori,
        'applicato' => $applica,
        'piano' => $piano,
    ]);
}

/* ---------------------------------------------------------------------
   RIPORTO DI QUELLO CHE NON È STATO FATTO
   Un argomento la cui finestra è passata senza spunta non sparisce e non resta
   indietro in silenzio: scivola al primo giorno libero dentro il periodo del
   suo blocco. Se non ce n'è, lo si dice — «il piano su Analisi 2 non ci sta
   più, servono due giorni» è l'avviso che serve PRIMA dell'esame, non dopo.
   --------------------------------------------------------------------- */
if ($ajax === 'riporta') {
    $applica = sec_bool(sec_in('applica'));
    $oggi = date('Y-m-d');
    $calStudio = st_per_ruolo($uid, 'cal', 'study');

    /* Gli scaduti: finestra chiusa prima di oggi e ancora da spuntare. */
    $scaduti = sec_all(
        "SELECT p.id, p.name, p.study_start, p.study_end, p.study_event_id,
                e.event_date, e.end_date, e.title AS blocco, e.project_id
         FROM projects p
         JOIN planner_events e ON e.id = p.study_event_id AND e.user_id = p.user_id
         WHERE p.user_id = ? AND p.study_event_id IS NOT NULL
           AND p.status <> 'completed'
           AND COALESCE(p.study_end, p.study_start) < ?
           AND e.calendar_id <=> ?",
        [$uid, $oggi, $calStudio]);
    if (!$scaduti) sec_json(['ok' => true, 'spostati' => [], 'senza_posto' => []]);

    /* Quanto è già impegnato ogni giorno da qui in avanti: si conta quante
       finestre di argomenti lo attraversano. Non i minuti — la precisione lì
       è finta — ma sapere che giovedì è già preso da tre cose basta a non
       metterci la quarta. */
    $carico = [];
    foreach (sec_all("SELECT p.study_start, p.study_end
                      FROM projects p
                      WHERE p.user_id = ? AND p.study_event_id IS NOT NULL
                        AND p.status <> 'completed'
                        AND COALESCE(p.study_end, p.study_start) >= ?", [$uid, $oggi], 'is') as $x) {
        $d = (string)$x['study_start'];
        $f = !empty($x['study_end']) ? (string)$x['study_end'] : $d;
        for ($g = $d; $g <= $f; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
            if ($g < $oggi) continue;
            $carico[$g] = ($carico[$g] ?? 0) + 1;
        }
    }

    $spostati = []; $senzaPosto = [];
    foreach ($scaduti as $t) {
        $fineBlocco = !empty($t['end_date']) ? (string)$t['end_date'] : (string)$t['event_date'];
        $durata = 1;
        if (!empty($t['study_end'])) {
            $durata = max(1, (int)floor((strtotime((string)$t['study_end']) - strtotime((string)$t['study_start'])) / 86400) + 1);
        }

        /* Il primo giorno da oggi in poi, dentro al blocco, dove l'argomento
           ci sta per intero. Si prende il meno affollato fra i primi
           disponibili: spostare una cosa sopra un giorno già pieno vuol dire
           doverla spostare di nuovo la settimana prossima. */
        $scelto = null; $minCarico = null;
        for ($g = $oggi; $g <= $fineBlocco; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
            $fine = date('Y-m-d', strtotime($g . ' +' . ($durata - 1) . ' day'));
            if ($fine > $fineBlocco) break;
            $c = 0;
            for ($k = $g; $k <= $fine; $k = date('Y-m-d', strtotime($k . ' +1 day'))) $c += ($carico[$k] ?? 0);
            if ($minCarico === null || $c < $minCarico) { $minCarico = $c; $scelto = $g; }
            if ($c === 0) break;   // un giorno vuoto va bene così com'è
        }

        if ($scelto === null) {
            $senzaPosto[] = ['id' => (int)$t['id'], 'nome' => (string)$t['name'], 'blocco' => (string)$t['blocco']];
            continue;
        }
        $nuovoFine = $durata > 1 ? date('Y-m-d', strtotime($scelto . ' +' . ($durata - 1) . ' day')) : null;
        if ($applica) {
            sec_q("UPDATE projects SET study_start = ?, study_end = ? WHERE id = ? AND user_id = ?",
                  [$scelto, $nuovoFine, (int)$t['id'], $uid]);
            for ($k = $scelto; $k <= ($nuovoFine ?: $scelto); $k = date('Y-m-d', strtotime($k . ' +1 day'))) {
                $carico[$k] = ($carico[$k] ?? 0) + 1;
            }
        }
        $spostati[] = ['id' => (int)$t['id'], 'nome' => (string)$t['name'],
                       'da' => (string)$t['study_start'], 'a' => $scelto];
    }
    sec_json(['ok' => true, 'applicato' => $applica, 'spostati' => $spostati, 'senza_posto' => $senzaPosto]);
}

/* ---------------------------------------------------------------------
   CONSUNTIVO SETTIMANALE
   Un momento fisso in cui guardare com'è andata. Senza, le statistiche non le
   apre nessuno di sua iniziativa: si guardano quando qualcosa va storto, cioè
   troppo tardi per servire.
   --------------------------------------------------------------------- */
if ($ajax === 'settimana') {
    /* Lunedì di questa settimana e di quella prima. Il confronto è il punto:
       «dodici pomodori» non dice niente, «dodici contro otto» sì. */
    $lun = date('Y-m-d', strtotime('monday this week'));
    $lunPrima = date('Y-m-d', strtotime($lun . ' -7 day'));

    $ora = st_settimana($uid, $lun, date('Y-m-d', strtotime($lun . ' +7 day')));
    $prima = st_settimana($uid, $lunPrima, $lun);

    /* Quale materia è andata meglio e quale peggio: i pomodori per materia in
       questa settimana. Con una sola materia non si dice niente — un confronto
       fra una cosa e sé stessa non è un confronto. */
    $perMateria = sec_all(
        "SELECT COALESCE(p2.name, p.name) AS nome, COALESCE(p2.color, p.color) AS colore,
                COUNT(s.id) AS n
         FROM pomodoro_sessions s
         JOIN projects p ON p.id = s.project_id AND p.user_id = s.user_id
         LEFT JOIN projects p2 ON p2.id = p.parent_id AND p2.user_id = s.user_id
         WHERE s.user_id = ? AND s.end_time IS NOT NULL
           AND DATE(s.start_time) >= ? AND DATE(s.start_time) < ?
         GROUP BY nome, colore
         ORDER BY n DESC", [$uid, $lun, date('Y-m-d', strtotime($lun . ' +7 day'))], 'iss');

    sec_json([
        'ok' => true,
        'da' => $lun,
        'ora' => $ora,
        'prima' => $prima,
        'materie' => $perMateria,
    ]);
}

/* ---------------------------------------------------------------------
   IL VOTO CHIUDE IL CERCHIO
   Quanto è costato un esame e come è andato. Dopo tre o quattro esami questo
   sa dire la cosa che nessuno ti dice: «sui 9 CFU con questo metodo prendi in
   media 27, sui 12 scendi a 24».
   --------------------------------------------------------------------- */
if ($ajax === 'esami_fatti') {
    /* Gli esami già dati che avevano un progetto collegato: solo di quelli
       sappiamo quanto sono costati. */
    $righe = sec_all(
        "SELECT x.id, x.name, x.cfu, x.grade, x.has_honors, x.exam_date,
                e.project_id,
                COUNT(DISTINCT s.id) AS pomodori,
                COALESCE(SUM(s.duration_seconds), 0) AS secondi
         FROM university_exams x
         JOIN planner_events e ON e.study_exam_id = x.id AND e.user_id = x.user_id
         LEFT JOIN projects p ON (p.id = e.project_id OR p.parent_id = e.project_id) AND p.user_id = x.user_id
         LEFT JOIN pomodoro_sessions s ON s.project_id = p.id AND s.user_id = x.user_id AND s.end_time IS NOT NULL
         WHERE x.user_id = ? AND x.status = 'done'
         GROUP BY x.id
         ORDER BY x.exam_date DESC", [$uid], 'i');

    $out = []; $perCfu = [];
    foreach ($righe as $r) {
        $cfu = (float)$r['cfu'];
        $pom = (int)$r['pomodori'];
        $out[] = [
            'nome' => (string)$r['name'],
            'cfu' => $cfu,
            'voto' => $r['grade'] !== null ? (int)$r['grade'] : null,
            'lode' => (int)$r['has_honors'] === 1,
            'pomodori' => $pom,
            'ore' => round(((int)$r['secondi']) / 3600, 1),
            // quanto è costato ogni credito: è il numero che si porta avanti
            'per_cfu' => $cfu > 0 ? round($pom / $cfu, 1) : null,
        ];
        if ($cfu > 0 && $pom > 0) {
            $scaglione = $cfu <= 6 ? '≤6' : ($cfu <= 9 ? '7-9' : ($cfu <= 12 ? '10-12' : '13+'));
            if (!isset($perCfu[$scaglione])) $perCfu[$scaglione] = ['n' => 0, 'pom' => 0, 'voti' => []];
            $perCfu[$scaglione]['n']++;
            $perCfu[$scaglione]['pom'] += $pom / $cfu;
            if ($r['grade'] !== null) $perCfu[$scaglione]['voti'][] = (int)$r['grade'];
        }
    }
    $scaglioni = [];
    foreach ($perCfu as $k => $v) {
        $scaglioni[] = [
            'cfu' => $k,
            'esami' => $v['n'],
            'per_cfu' => round($v['pom'] / $v['n'], 1),
            'voto_medio' => $v['voti'] ? round(array_sum($v['voti']) / count($v['voti']), 1) : null,
        ];
    }

    sec_json(['ok' => true, 'esami' => $out, 'scaglioni' => $scaglioni]);
}

/* ---------------------------------------------------------------------
   MODELLI DI MATERIA
   La forma di un piano senza le date: nove argomenti, in che ordine, quanto
   dura ciascuno. Applicandolo a un periodo nuovo le voci si ridistribuiscono
   in proporzione — «Analisi 2» quest'anno e «Analisi 3» l'anno prossimo hanno
   la stessa struttura, e rifarla a mano è il lavoro che fa rinunciare a
   pianificare.
   --------------------------------------------------------------------- */
if ($ajax === 'modelli') {
    sec_json(['ok' => true, 'modelli' => sec_all(
        "SELECT id, name, voci, usato FROM study_templates WHERE user_id = ? ORDER BY name", [$uid], 'i')]);
}

if ($ajax === 'salva_modello') {
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $nome = sec_str(sec_in('name'), 200);
    $ev = st_evento($uid, $eventId);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');
    if ($nome === '') $nome = (string)$ev['title'];

    $voci = [];
    foreach (sec_all("SELECT name, study_start, study_end, study_pomodori_stimati
                      FROM projects
                      WHERE user_id = ? AND study_event_id = ? AND study_ripasso_di IS NULL
                      ORDER BY study_start, custom_order, id", [$uid, $eventId], 'ii') as $t) {
        $da = (string)$t['study_start'];
        $a  = !empty($t['study_end']) ? (string)$t['study_end'] : $da;
        $voci[] = [
            'nome' => (string)$t['name'],
            'giorni' => max(1, (int)floor((strtotime($a) - strtotime($da)) / 86400) + 1),
            'pomodori' => (int)($t['study_pomodori_stimati'] ?? 0),
        ];
    }
    if (!$voci) sec_die_json(t('todo.study_modello_vuoto'), 422, 'invalid');

    sec_q("INSERT INTO study_templates (user_id, name, voci) VALUES (?, ?, ?)",
          [$uid, $nome, json_encode($voci, JSON_UNESCAPED_UNICODE)], 'iss');
    sec_json(['ok' => true, 'id' => sec_insert_id(), 'voci' => count($voci)]);
}

if ($ajax === 'applica_modello') {
    $modello = sec_int(sec_in('modello_id'), 1, PHP_INT_MAX, 0);
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $ev = st_evento($uid, $eventId);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    $m = sec_one("SELECT name, voci FROM study_templates WHERE id = ? AND user_id = ?", [$modello, $uid], 'ii');
    if (!$m) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');
    $voci = json_decode((string)$m['voci'], true);
    if (!is_array($voci) || !$voci) sec_die_json(t('todo.study_modello_vuoto'), 422, 'invalid');

    /* Le proporzioni, non i giorni. Un modello nato su venti giorni applicato a
       dieci non può tenere le durate originali: si conserva il RAPPORTO fra le
       voci, cioè quale argomento merita più tempo degli altri — che è la sola
       cosa del piano vecchio che vale ancora. */
    $totGiorni = 0;
    foreach ($voci as $v) $totGiorni += max(1, (int)($v['giorni'] ?? 1));
    $inizio = (string)$ev['event_date'];
    $fine   = st_fine($ev);
    $disponibili = max(1, (int)floor((strtotime($fine) - strtotime($inizio)) / 86400) + 1);

    $padre = $ev['project_id'] !== null ? (int)$ev['project_id'] : null;
    $colore = '#8E8E93';
    if ($padre) {
        $pp = sec_one("SELECT color FROM projects WHERE id = ? AND user_id = ?", [$padre, $uid], 'ii');
        if ($pp && !empty($pp['color'])) $colore = (string)$pp['color'];
    }

    $ord = 0;
    $cursore = 0.0;     // in giorni, dall'inizio del blocco
    $creati = 0;
    foreach ($voci as $v) {
        $quota = max(1, (int)($v['giorni'] ?? 1)) / $totGiorni * $disponibili;
        $da = date('Y-m-d', strtotime($inizio . ' +' . (int)floor($cursore) . ' day'));
        $cursore += $quota;
        // -1 perché gli estremi sono compresi: un argomento che occupa fino al
        // giorno 3 finisce il 3, non il 4
        $aOff = max((int)floor($cursore) - 1, (int)floor($cursore - $quota));
        $a = date('Y-m-d', strtotime($inizio . ' +' . $aOff . ' day'));
        if ($a > $fine) $a = $fine;
        if ($da > $fine) $da = $fine;

        $nome = sec_str((string)($v['nome'] ?? ''), 255);
        if ($nome === '') continue;
        $pom = (int)($v['pomodori'] ?? 0);

        sec_q("INSERT INTO projects
               (user_id, parent_id, study_event_id, study_start, study_end, study_pomodori_stimati,
                name, color, color_edited, status, custom_order, created_at)
               VALUES (?,?,?,?,?,?,?,?,0,'in_progress',?,NOW())",
              [$uid, $padre, $eventId, $da, $a === $da ? null : $a, $pom > 0 ? $pom : null,
               $nome, $colore, $ord++]);
        $creati++;
    }
    sec_q("UPDATE study_templates SET usato = usato + 1 WHERE id = ? AND user_id = ?", [$modello, $uid], 'ii');
    sec_json(['ok' => true, 'creati' => $creati]);
}

if ($ajax === 'elimina_modello') {
    $modello = sec_int(sec_in('modello_id'), 1, PHP_INT_MAX, 0);
    sec_q("DELETE FROM study_templates WHERE id = ? AND user_id = ?", [$modello, $uid], 'ii');
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   LETTURA: gli eventi di un periodo
   --------------------------------------------------------------------- */
if ($ajax === 'eventi') {
    $from = sec_date(sec_in('from'), date('Y-m-01'));
    $to   = sec_date(sec_in('to'), date('Y-m-t'));
    $f = st_filtri($uid);

    $calStudio = st_per_ruolo($uid, 'cal', 'study');
    $catStudio = st_per_ruolo($uid, 'cat', 'study');

    /* Cosa entra in questa pagina, e perché.
       - i blocchi di studio: calendario 'study';
       - gli eventi importanti: NON stanno nel calendario dello studio (sono
         date vere, vanno sull'agenda principale e su Google) ma qui devono
         vedersi comunque, perché sono l'ancora su cui si appoggia il piano.
         Li si riconosce dalla categoria 'studio' più l'importanza;
       - tutto il resto solo se la casella è spuntata.
       Il periodo si tocca, non si contiene: un blocco che comincia il mese
       prima e finisce dentro deve esserci. */
    /* Cosa entra, secondo i filtri salvati. I blocchi di studio ci sono
       sempre — è la pagina dello studio — e tutto il resto è una scelta
       dell'utente, presa una volta e ricordata.
       Le condizioni si sommano in OR: ogni voce accesa apre una porta, e un
       evento entra se ne trova almeno una aperta. Con tutte spente resta solo
       lo studio, che è il minimo sensato. */
    $args = [$uid, $to, $from];
    $porte = [];
    if ($calStudio !== null) { $porte[] = 'e.calendar_id = ?'; $args[] = $calStudio; }
    /* La categoria «studio» entra sempre, qualunque sia il filtro e qualunque
       sia il calendario in cui vive. Sono le date d'esame e quello che ci
       gira attorno: non c'è nessuno stato di questa pagina in cui abbia senso
       nasconderle, ed è la prima cosa che si vuole vedere aprendola. */
    if ($catStudio !== null) { $porte[] = 'e.category_id = ?'; $args[] = $catStudio; }
    if ($f['on'] && $f['highest']) $porte[] = "e.importance = 'highest'";
    if ($f['on'] && $f['high'])    $porte[] = "e.importance = 'high'";
    if ($f['on'] && $f['routine']) $porte[] = 'e.routine_id IS NOT NULL';
    if ($f['on'] && $f['habit'])   $porte[] = 'e.habit_id IS NOT NULL';
    if ($f['on'] && $f['cal']) {
        $ph = implode(',', array_fill(0, count($f['cal']), '?'));
        $porte[] = "e.calendar_id IN ($ph)";
        foreach ($f['cal'] as $x) $args[] = $x;
    }
    if ($f['on'] && $f['cat']) {
        $ph = implode(',', array_fill(0, count($f['cat']), '?'));
        $porte[] = "e.category_id IN ($ph)";
        foreach ($f['cat'] as $x) $args[] = $x;
    }
    // nessuna porta aperta: la migrazione non è stata lanciata, non si mostra nulla
    $where = ' AND (' . ($porte ? implode(' OR ', $porte) : '1 = 0') . ')';

    $rows = sec_all(
        "SELECT e.id, e.title, e.event_date, e.end_date, e.is_all_day, e.importance,
                e.start_time, e.end_time,
                e.is_completed, e.color, e.calendar_id, e.category_id, e.project_id, e.study_exam_id,
                p.name AS project_name, p.color AS project_color,
                k.color AS cat_color
         FROM planner_events e
         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
         LEFT JOIN planner_categories k ON k.id = e.category_id AND k.user_id = e.user_id
         WHERE e.user_id = ?
           /* Fuori restano solo i capostipiti delle ripetizioni (rrule) — quelli
              sono una regola, non un evento — e i segnaposto di cancellazione.
              Le occorrenze vere invece entrano: con la casella «tutto il
              planner» spuntata le routine SONO il grosso di quello che si
              vuole vedere compattato, e prima venivano escluse tutte insieme
              agli originali, cosi' non si compattava niente perche' non
              arrivava niente. */
           AND e.rrule IS NULL AND COALESCE(e.is_cancelled, 0) = 0
           AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?
           $where
         ORDER BY e.event_date, e.id",
        $args);

    /* I sotto-task di tutti gli eventi letti, in una query sola. Uno per
       evento sarebbe una interrogazione per riga: con trenta blocchi in un
       mese, trenta viaggi al database per disegnare una schermata. */
    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $perEvento = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $tk = sec_all(
            "SELECT p.id, p.name, p.color, p.status, p.study_event_id, p.study_start, p.study_end,
                    p.study_pomodori_stimati, p.study_ripasso_di, p.study_fc_categoria,
                    COUNT(s.id) AS pomodori_fatti,
                    COALESCE(SUM(s.duration_seconds), 0) AS secondi_fatti
             FROM projects p
             LEFT JOIN pomodoro_sessions s
                    ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
             WHERE p.user_id = ? AND p.study_event_id IN ($ph) AND p.study_start IS NOT NULL
             GROUP BY p.id
             ORDER BY p.study_start, p.custom_order, p.id",
            array_merge([$uid], $ids));
        foreach ($tk as $t) $perEvento[(int)$t['study_event_id']][] = st_task_out($t);
    }

    $out = [];
    foreach ($rows as $r) {
        $tasks = $perEvento[(int)$r['id']] ?? [];
        $fatti = 0;
        foreach ($tasks as $t) if ($t['done']) $fatti++;
        $out[] = [
            'id'        => (int)$r['id'],
            'title'     => (string)$r['title'],
            'date'      => (string)$r['event_date'],
            'end_date'  => !empty($r['end_date']) ? (string)$r['end_date'] : (string)$r['event_date'],
            'all_day'   => (int)$r['is_all_day'] === 1,
            /* Servono a raggruppare ora per ora quando si guarda tutto il
               planner: con la casella spuntata, una giornata normale porta
               dentro venti fra routine e impegni da cinque minuti, e messi uno
               sotto l'altro coprono il mese senza dire niente. */
            'start_time'=> substr((string)$r['start_time'], 0, 5),
            'end_time'  => substr((string)$r['end_time'], 0, 5),
            'importance'=> (string)$r['importance'],
            'done'      => (int)$r['is_completed'] === 1,
            'di_studio' => $calStudio !== null && (int)$r['calendar_id'] === $calStudio,
            /* Il colore viene dal progetto. Senza progetto resta grigio, ed è
               voluto: è il segno che quel blocco è fuori dalle statistiche,
               e si vede a colpo d'occhio invece di doverlo andare a cercare. */
            'color'     => !empty($r['project_color']) ? (string)$r['project_color']
                         : (!empty($r['color']) ? (string)$r['color'] : null),
            'project_id'   => $r['project_id'] !== null ? (int)$r['project_id'] : null,
            'project_name' => $r['project_name'] ?? null,
            'exam_id'   => $r['study_exam_id'] !== null ? (int)$r['study_exam_id'] : null,
            'tasks'     => $tasks,
            'tasks_fatti' => $fatti,
        ];
    }
    sec_json(['ok' => true, 'eventi' => $out, 'cal_studio' => $calStudio, 'cat_studio' => $catStudio]);
}

/* ---------------------------------------------------------------------
   CREAZIONE di un blocco di studio (o di una data importante)
   --------------------------------------------------------------------- */
if ($ajax === 'crea_evento') {
    $da = sec_date(sec_in('start'), date('Y-m-d'));
    $a  = sec_date(sec_in('end'), null);
    if ($a === null || $a < $da) $a = $da;
    // stesso tetto del planner: un evento entra in tutte le caselle che copre
    $limite = date('Y-m-d', strtotime($da . ' +365 day'));
    if ($a > $limite) $a = $limite;

    /* Senza titolo non si crea niente, e il controllo sta anche qui: quello
       del browser lo aggira chiunque, e un blocco senza nome sporca il mese di
       chi lo guarda. Prima si ripiegava su un nome finto, ed è esattamente il
       modo in cui nascevano i blocchi per sbaglio. */
    $titolo = sec_str(sec_in('title'), 180);
    if ($titolo === '') sec_die_json(t('todo.study_serve_titolo'), 422, 'invalid');
    $imp = sec_enum(sec_in('importance'), ['normal', 'high', 'highest'], 'normal');
    $projId = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
    if ($projId > 0 && !sec_owns('projects', $projId, $uid)) $projId = 0;
    $examId = sec_int(sec_in('exam_id'), 0, PHP_INT_MAX, 0);

    $calStudio = st_per_ruolo($uid, 'cal', 'study');
    $catStudio = st_per_ruolo($uid, 'cat', 'study');

    /* Il bivio deciso a suo tempo: i blocchi di studio vivono nel calendario
       'study', che si può spegnere dal filtro del planner; le date importanti
       no — vanno nel calendario principale, perché un esame non è una cosa
       che si nasconde, e da lì finiscono anche su Google. La categoria
       'studio' invece ce l'hanno tutti e due: è quella che li lega. */
    if ($imp === 'normal') {
        $calId = $calStudio;
    } else {
        $princ = sec_one("SELECT id FROM planner_calendars WHERE user_id = ?
                          ORDER BY is_default DESC, id LIMIT 1", [$uid], 'i');
        $calId = $princ ? (int)$princ['id'] : null;
    }

    sec_q("INSERT INTO planner_events
           (user_id, title, event_date, end_date, start_time, end_time, is_all_day,
            category_id, calendar_id, project_id, study_exam_id, importance,
            item_type, notify_type, notify_minutes)
           VALUES (?,?,?,?,'00:00:00','23:59:00',1,?,?,?,?,?,'event',?,?)",
          [$uid, $titolo, $da, $a === $da ? null : $a,
           $catStudio, $calId, $projId > 0 ? $projId : null, $examId > 0 ? $examId : null, $imp,
           $imp === 'normal' ? 'none' : 'd1', $imp === 'normal' ? 0 : 1440]);

    sec_json(['ok' => true, 'id' => sec_insert_id()]);
}

/* ---------------------------------------------------------------------
   MODIFICA di un blocco: titolo, progetto, importanza
   Le DATE non passano da qui ma da sposta_evento: spostare un blocco muove
   anche i suoi argomenti, ed è una decisione che va chiesta, non un campo
   che si salva insieme al titolo.
   --------------------------------------------------------------------- */
if ($ajax === 'salva_evento') {
    $id = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('planner_events', $id, $uid)) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    $campi = []; $vals = [];

    $titolo = sec_in('title');
    if ($titolo !== null) {
        $titolo = sec_str($titolo, 180);
        if ($titolo !== '') { $campi[] = 'title = ?'; $vals[] = $titolo; }
    }
    if (sec_in('project_id') !== null) {
        $pid = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        if ($pid > 0 && !sec_owns('projects', $pid, $uid)) $pid = 0;
        $campi[] = 'project_id = ?'; $vals[] = $pid > 0 ? $pid : null;
    }
    if (sec_in('importance') !== null) {
        $imp = sec_enum(sec_in('importance'), ['normal', 'high', 'highest'], 'normal');
        $campi[] = 'importance = ?'; $vals[] = $imp;
        /* Cambiando importanza cambia anche dove vive: una data importante non
           sta nel calendario dello studio, che si può spegnere dal filtro —
           un esame non è una cosa che si nasconde. */
        if ($imp === 'normal') {
            $cal = st_per_ruolo($uid, 'cal', 'study');
        } else {
            $princ = sec_one("SELECT id FROM planner_calendars WHERE user_id = ?
                              ORDER BY is_default DESC, id LIMIT 1", [$uid], 'i');
            $cal = $princ ? (int)$princ['id'] : null;
        }
        $campi[] = 'calendar_id = ?'; $vals[] = $cal;
    }
    if (sec_in('exam_id') !== null) {
        $ex = sec_int(sec_in('exam_id'), 0, PHP_INT_MAX, 0);
        $campi[] = 'study_exam_id = ?'; $vals[] = $ex > 0 ? $ex : null;
    }
    if (empty($campi)) sec_json(['ok' => true]);

    $vals[] = $id; $vals[] = $uid;
    sec_q("UPDATE planner_events SET " . implode(', ', $campi) . " WHERE id = ? AND user_id = ?", $vals);

    /* Il colore degli argomenti segue quello della materia. Solo di quelli che
       non l'hanno mai avuto scelto a mano (color_edited = 0): se l'utente ne
       ha ritoccato uno, non glielo si riscrive addosso. */
    if (sec_in('project_id') !== null) {
        $pid = sec_int(sec_in('project_id'), 0, PHP_INT_MAX, 0);
        if ($pid > 0) {
            $pp = sec_one("SELECT color FROM projects WHERE id = ? AND user_id = ?", [$pid, $uid], 'ii');
            if ($pp && !empty($pp['color'])) {
                sec_q("UPDATE projects SET parent_id = ?, color = ?
                       WHERE user_id = ? AND study_event_id = ? AND color_edited = 0",
                      [$pid, (string)$pp['color'], $uid, $id], 'isii');
            }
        }
    }
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   CANCELLAZIONE di un blocco
   --------------------------------------------------------------------- */
if ($ajax === 'elimina_evento') {
    $id = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('planner_events', $id, $uid)) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    /* Gli argomenti NON si cancellano: sono sotto-progetti, e dentro ci sono i
       pomodori già fatti. Perdono solo il posto nel calendario. Cancellarli
       davvero si fa uno per uno, dove la domanda si può porre per bene. */
    $n = sec_one("SELECT COUNT(*) AS n FROM projects WHERE user_id = ? AND study_event_id = ?",
                 [$uid, $id], 'ii');
    sec_q("UPDATE projects SET study_event_id = NULL, study_start = NULL, study_end = NULL
           WHERE user_id = ? AND study_event_id = ?", [$uid, $id], 'ii');
    sec_q("DELETE FROM planner_events WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'scollegati' => (int)($n['n'] ?? 0)]);
}

/* ---------------------------------------------------------------------
   LETTURA: i sotto-task di un evento
   --------------------------------------------------------------------- */
if ($ajax === 'list') {
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $ev = st_evento($uid, $eventId);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    $rows = sec_all(
        "SELECT p.id, p.name, p.color, p.status, p.study_event_id, p.study_start, p.study_end,
                p.study_pomodori_stimati, p.study_ripasso_di, p.study_fc_categoria,
                COUNT(s.id)                     AS pomodori_fatti,
                COALESCE(SUM(s.duration_seconds), 0) AS secondi_fatti
         FROM projects p
         LEFT JOIN pomodoro_sessions s
                ON s.project_id = p.id AND s.user_id = p.user_id AND s.end_time IS NOT NULL
         WHERE p.user_id = ? AND p.study_event_id = ?
         GROUP BY p.id
         ORDER BY p.study_start, p.custom_order, p.id",
        [$uid, $eventId], 'ii');

    $out = [];
    foreach ($rows as $r) $out[] = st_task_out($r);
    sec_json(['ok' => true, 'tasks' => $out]);
}

/* ---------------------------------------------------------------------
   CREAZIONE: nasce come sotto-progetto della materia
   --------------------------------------------------------------------- */
if ($ajax === 'add') {
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $ev = st_evento($uid, $eventId);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    $nome = sec_str(sec_in('name'), 255);
    if ($nome === '') $nome = t('todo.nuovo_argomento');

    /* Il padre è il progetto collegato all'evento. Se l'evento non ne ha uno,
       il sotto-task nasce comunque ma alla radice: è il caso «blocco grigio»,
       che la pagina segnala perché senza progetto i pomodori non si attaccano
       a niente e le statistiche restano vuote. */
    $padre = $ev['project_id'] !== null ? (int)$ev['project_id'] : 0;
    if ($padre > 0 && !sec_owns('projects', $padre, $uid)) $padre = 0;

    /* Il colore lo eredita dal padre invece di sceglierne uno: due argomenti
       della stessa materia con due colori diversi renderebbero illeggibile
       l'elenco del giorno, dove il colore è quello che dice di che materia si
       tratta. color_edited = 0 perché non è una scelta dell'utente, e il
       giorno che cambia il colore della materia questi devono seguirla. */
    $colore = '#8E8E93';
    if ($padre > 0) {
        $pp = sec_one("SELECT color FROM projects WHERE id = ? AND user_id = ?", [$padre, $uid], 'ii');
        if ($pp && !empty($pp['color'])) $colore = (string)$pp['color'];
    }

    [$da, $a] = st_dentro_al_padre(sec_date(sec_in('start'), null), sec_date(sec_in('end'), null), $ev);
    $stimati = sec_int(sec_in('stimati'), 0, 200, 0);

    $ord = sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM projects
                    WHERE user_id = ? AND study_event_id = ?", [$uid, $eventId], 'ii');

    sec_q("INSERT INTO projects
           (user_id, parent_id, study_event_id, study_start, study_end, study_pomodori_stimati,
            name, color, color_edited, status, custom_order, created_at)
           VALUES (?,?,?,?,?,?,?,?,0,'in_progress',?,NOW())",
          [$uid, $padre > 0 ? $padre : null, $eventId, $da, $a,
           $stimati > 0 ? $stimati : null, $nome, $colore, (int)($ord['n'] ?? 0)]);

    $id = sec_insert_id();
    $r = sec_one("SELECT id, name, color, status, study_event_id, study_start, study_end,
                         study_pomodori_stimati, study_ripasso_di, study_fc_categoria
                  FROM projects WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'task' => st_task_out($r)]);
}

/* ---------------------------------------------------------------------
   MODIFICA: nome, date, stima
   --------------------------------------------------------------------- */
if ($ajax === 'save') {
    $id = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('projects', $id, $uid)) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');

    $t = sec_one("SELECT study_event_id FROM projects WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    /* Solo i sotto-task dello studio passano da qui. Un progetto normale ha
       una sua pagina con le sue regole, e lasciargli cambiare le date da
       questo endpoint vorrebbe dire due strade per modificare la stessa cosa. */
    if (!$t || empty($t['study_event_id'])) sec_die_json(t('todo.study_non_e_argomento'), 422, 'invalid');

    $ev = st_evento($uid, (int)$t['study_event_id']);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    $campi = [];
    $vals  = [];

    $nome = sec_in('name');
    if ($nome !== null) {
        $nome = sec_str($nome, 255);
        if ($nome !== '') { $campi[] = 'name = ?'; $vals[] = $nome; }
    }
    if (sec_in('start') !== null || sec_in('end') !== null) {
        [$da, $a] = st_dentro_al_padre(sec_date(sec_in('start'), null), sec_date(sec_in('end'), null), $ev);
        $campi[] = 'study_start = ?'; $vals[] = $da;
        $campi[] = 'study_end = ?';   $vals[] = $a;
    }
    if (sec_in('stimati') !== null) {
        $st = sec_int(sec_in('stimati'), 0, 200, 0);
        $campi[] = 'study_pomodori_stimati = ?'; $vals[] = $st > 0 ? $st : null;
    }
    if (empty($campi)) sec_json(['ok' => true]);

    $vals[] = $id; $vals[] = $uid;
    sec_q("UPDATE projects SET " . implode(', ', $campi) . " WHERE id = ? AND user_id = ?", $vals);
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   SPUNTA: come in progetti, con l'annullamento
   --------------------------------------------------------------------- */
if ($ajax === 'toggle') {
    $id   = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    $done = sec_bool(sec_in('done'));
    if (!sec_owns('projects', $id, $uid)) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');

    sec_q("UPDATE projects SET status = ? WHERE id = ? AND user_id = ? AND study_event_id IS NOT NULL",
          [$done ? 'completed' : 'in_progress', $id, $uid], 'sii');
    sec_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
   SPUNTA DEL PADRE: scende sui figli, e si può disfare
   --------------------------------------------------------------------- */
if ($ajax === 'toggle_event') {
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $done    = sec_bool(sec_in('done'));
    $ev = st_evento($uid, $eventId);
    if (!$ev) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');

    /* Prima di scendere sui figli si annota chi era GIÀ spuntato. Senza,
       disfare l'azione li spegnerebbe tutti, compresi quelli che l'utente
       aveva spuntato ieri uno per uno — e quello sarebbe un danno vero, non
       un fastidio. La lista torna al browser, che la rimanda indietro se
       l'utente tocca «annulla». */
    $prima = [];
    foreach (sec_all("SELECT id FROM projects
                      WHERE user_id = ? AND study_event_id = ? AND status = 'completed'",
                     [$uid, $eventId], 'ii') as $r) {
        $prima[] = (int)$r['id'];
    }

    sec_q("UPDATE planner_events SET is_completed = ?, completed_at = " . ($done ? 'NOW()' : 'NULL') . "
           WHERE id = ? AND user_id = ?", [$done ? 1 : 0, $eventId, $uid], 'iii');

    if ($done) {
        sec_q("UPDATE projects SET status = 'completed'
               WHERE user_id = ? AND study_event_id = ?", [$uid, $eventId], 'ii');
    } else {
        /* Disfacendo si riaprono tutti TRANNE quelli che erano già chiusi
           prima: `era_gia` arriva dal browser, che l'aveva ricevuto al momento
           della spunta. */
        $eraGia = sec_in('era_gia');
        $tenere = [];
        if (is_array($eraGia)) {
            foreach ($eraGia as $x) { $n = (int)$x; if ($n > 0) $tenere[] = $n; }
        }
        if ($tenere) {
            $ph = implode(',', array_fill(0, count($tenere), '?'));
            sec_q("UPDATE projects SET status = 'in_progress'
                   WHERE user_id = ? AND study_event_id = ? AND id NOT IN ($ph)",
                  array_merge([$uid, $eventId], $tenere));
        } else {
            sec_q("UPDATE projects SET status = 'in_progress'
                   WHERE user_id = ? AND study_event_id = ?", [$uid, $eventId], 'ii');
        }
    }
    sec_json(['ok' => true, 'era_gia' => $prima]);
}

/* ---------------------------------------------------------------------
   SPOSTAMENTO DEL PADRE: i figli lo seguono, o restano
   --------------------------------------------------------------------- */
if ($ajax === 'sposta_evento') {
    $eventId = sec_int(sec_in('event_id'), 1, PHP_INT_MAX, 0);
    $nuovoDa = sec_date(sec_in('start'), null);
    $nuovoA  = sec_date(sec_in('end'), null);
    /* 'segui'  = i figli traslano dello stesso numero di giorni
       'stringi'= restano dove sono, e chi finisce fuori viene riportato dentro
       'stacca' = restano dove sono, e chi finisce fuori perde le date */
    $modo = sec_enum(sec_in('modo'), ['segui', 'stringi', 'stacca'], 'segui');

    $ev = st_evento($uid, $eventId);
    if (!$ev || $nuovoDa === null) sec_die_json(t('todo.study_ev_non_trovato'), 404, 'not_found');
    if ($nuovoA === null || $nuovoA < $nuovoDa) $nuovoA = $nuovoDa;

    $scarto = (int)floor((strtotime($nuovoDa) - strtotime((string)$ev['event_date'])) / 86400);

    sec_q("UPDATE planner_events SET event_date = ?, end_date = ? WHERE id = ? AND user_id = ?",
          [$nuovoDa, $nuovoA === $nuovoDa ? null : $nuovoA, $eventId, $uid]);

    $nuovoEv = ['event_date' => $nuovoDa, 'end_date' => $nuovoA === $nuovoDa ? null : $nuovoA];
    $staccati = 0;

    foreach (sec_all("SELECT id, study_start, study_end FROM projects
                      WHERE user_id = ? AND study_event_id = ?", [$uid, $eventId], 'ii') as $t) {
        $da = (string)$t['study_start'];
        $a  = !empty($t['study_end']) ? (string)$t['study_end'] : $da;

        if ($modo === 'segui' && $scarto !== 0) {
            $da = date('Y-m-d', strtotime($da . ' ' . ($scarto >= 0 ? '+' : '') . $scarto . ' day'));
            $a  = date('Y-m-d', strtotime($a  . ' ' . ($scarto >= 0 ? '+' : '') . $scarto . ' day'));
        }

        $fuori = ($a < $nuovoEv['event_date'] || $da > st_fine($nuovoEv));
        if ($fuori && $modo === 'stacca') {
            /* Le date si perdono, il sotto-progetto no: dentro ci sono i
               pomodori già fatti, e buttarli via non è una cosa che si fa
               come effetto collaterale di un trascinamento. */
            sec_q("UPDATE projects SET study_start = NULL, study_end = NULL
                   WHERE id = ? AND user_id = ?", [(int)$t['id'], $uid], 'ii');
            $staccati++;
            continue;
        }

        [$da2, $a2] = st_dentro_al_padre($da, $a, $nuovoEv);
        sec_q("UPDATE projects SET study_start = ?, study_end = ? WHERE id = ? AND user_id = ?",
              [$da2, $a2, (int)$t['id'], $uid]);
    }
    sec_json(['ok' => true, 'staccati' => $staccati]);
}

/* ---------------------------------------------------------------------
   CANCELLAZIONE: scollegare o buttare via davvero
   --------------------------------------------------------------------- */
if ($ajax === 'remove') {
    $id   = sec_int(sec_in('id'), 1, PHP_INT_MAX, 0);
    $modo = sec_enum(sec_in('modo'), ['scollega', 'cancella'], 'scollega');
    if (!sec_owns('projects', $id, $uid)) sec_die_json(t('todo.study_non_trovato'), 404, 'not_found');

    if ($modo === 'scollega') {
        /* La strada normale: il sotto-progetto resta in progetti con la sua
           storia, perde solo il posto nel calendario dello studio. */
        sec_q("UPDATE projects SET study_event_id = NULL, study_start = NULL, study_end = NULL
               WHERE id = ? AND user_id = ? AND study_event_id IS NOT NULL", [$id, $uid], 'ii');
        sec_json(['ok' => true, 'modo' => 'scollega']);
    }

    /* Cancellare davvero porta via anche i pomodori: sono legati al progetto,
       e il progetto sta per sparire. Il conto lo restituiamo comunque, perché
       la seconda conferma deve poter dire QUANTO si sta buttando — «sei
       sicuro?» senza un numero davanti non lo legge nessuno. */
    $c = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds),0) AS s
                  FROM pomodoro_sessions WHERE user_id = ? AND project_id = ?", [$uid, $id], 'ii');

    if (!sec_bool(sec_in('conferma'))) {
        sec_json(['ok' => false, 'serve_conferma' => true,
                  'pomodori' => (int)($c['n'] ?? 0), 'secondi' => (int)($c['s'] ?? 0)]);
    }

    sec_q("DELETE FROM pomodoro_sessions WHERE user_id = ? AND project_id = ?", [$uid, $id], 'ii');
    sec_q("DELETE FROM projects WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'modo' => 'cancella', 'pomodori' => (int)($c['n'] ?? 0)]);
}

sec_die_json(t('comune.errore_generico'), 400, 'bad_request');
