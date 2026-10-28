<?php
/* =====================================================================
   STANDOUT — PASTIGLIE E INTEGRATORI: la logica condivisa tra la pagina e l'API

   Prima stava tutta dentro public/planner/todo_api.php (le azioni ?ajax=pill*).
   Le funzioni del blocco «PASTIGLIE E INTEGRATORI» qui sotto sono SPOSTATE da li'
   senza cambiarne una riga; le azioni (td_pill_giorno, td_pill_segna, ...) hanno
   il corpo delle azioni della pagina, con i valori che prima venivano da sec_in()
   passati come argomenti. La pagina e l'app devono comportarsi allo stesso modo
   (soprattutto sul ritmo: «schedule_from», le prese che non devono tornare), e
   l'unico modo di garantirlo e' avere un solo codice.

   Serve security_core.php (sec_*) e la lingua (t()). Caricato da todo_api.php e
   da lib/pills_api.php.
   ===================================================================== */
if (defined('STANDOUT_PILLS_CORE')) return;
define('STANDOUT_PILLS_CORE', true);

/* =====================================================================
   PASTIGLIE E INTEGRATORI

   Una *terapia* dice cosa prendere e con che ritmo; una *presa* è il singolo
   appuntamento (quello delle 10:00 di oggi) con la sua spunta. Le prese
   nascono il giorno in cui servono, non un anno prima: così cambiare il
   ritmo non riscrive il passato e non lascia in giro appuntamenti di una
   terapia che nel frattempo è finita.

   La tabella è facoltativa come le altre aggiunte recenti: se la migrazione
   non è stata eseguita, la sezione risponde vuota e il resto delle attività
   continua a funzionare.
   ===================================================================== */
function td_pills_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'pill_plans'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/** "10:00x2,20:00x1" → [['08:00', 2], ...]. Scritto male, si torna a una
    presa alle 08:00: meglio una terapia addosso che una sezione vuota. */
function td_pill_times(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $pezzo) {
        $pezzo = trim($pezzo);
        if ($pezzo === '') continue;
        $p = explode('x', $pezzo);
        $ora = sec_time($p[0] ?? '', '');
        if ($ora === '') continue;
        $n = isset($p[1]) ? (int)$p[1] : 1;
        $out[] = [substr($ora, 0, 5) . ':00', max(1, min(9, $n))];
    }
    if (!$out) $out[] = ['08:00:00', 1];
    /* in ordine di orologio: l'elenco del giorno si legge dall'alto in basso */
    usort($out, function ($a, $b) { return strcmp($a[0], $b[0]); });
    return $out;
}

/** La terapia tocca quel giorno? */
function td_pill_due(array $p, string $date): bool {
    if ($date < $p['start_date']) return false;
    if (!empty($p['end_date']) && $date > $p['end_date']) return false;

    if ($p['freq_type'] === 'monthly') {
        $g = (int)substr($date, 8, 2);
        foreach (explode(',', (string)$p['month_days']) as $d) {
            if ((int)trim($d) === $g) return true;
        }
        /* il 31 in un mese di 30 giorni non esiste: la presa slitta all'ultimo
           giorno, invece di saltare il mese senza dire niente */
        $ultimo = (int)date('t', strtotime($date));
        if ($g === $ultimo) {
            foreach (explode(',', (string)$p['month_days']) as $d) {
                if ((int)trim($d) > $ultimo) return true;
            }
        }
        return false;
    }

    if ($p['freq_type'] === 'interval') {
        $n = max(1, (int)$p['interval_days']);
        $giorni = (int)floor((strtotime($date) - strtotime($p['start_date'])) / 86400);
        return $giorni >= 0 && ($giorni % $n) === 0;
    }

    /* daily: la maschera dei giorni della settimana, lunedì in prima
       posizione come nelle abitudini (date('N') dà 1 per lunedì) */
    $w = str_pad((string)$p['weekdays'], 7, '1');
    $i = (int)date('N', strtotime($date)) - 1;
    return substr($w, $i, 1) === '1';
}

/** Scrive le prese mancanti di uno o più giorni. Ripetibile senza danni: la
    chiave doppia della tabella fa cadere i doppioni invece di crearli.

    Le terapie si leggono una volta sola anche quando i giorni sono sette:
    sono le stesse per tutti, e rileggerle a ogni giro sarebbe sette volte la
    stessa domanda al database. */
function td_pills_ensure_giorni(int $uid, array $dates): void {
    if (!td_pills_c_e() || !$dates) return;
    $plans = sec_all("SELECT * FROM pill_plans WHERE user_id = ? AND is_active = 1", [$uid], 'i');
    if (!$plans) return;
    foreach ($plans as $p) {
        $ore = td_pill_times((string)$p['times']);
        /* IL BUG DELLE PRESE CHE RITORNAVANO.
           Cambiando l'orario di una terapia da 10:00 a 12:00, tutte le prese
           già spuntate tornavano a galla come "rimaste indietro". Il motivo:
           qui si rigenerano i sette giorni scorsi, e li si rigenerava con gli
           orari di ADESSO. La presa delle 10:00 di ieri restava spuntata dov'era,
           ma ne nasceva una nuova alle 12:00, in sospeso, per un giorno in cui
           quell'orario non è mai esistito.
           Da qui in poi ogni terapia porta la data da cui vale l'orario
           corrente: prima di quella, le prese non si toccano più, perché
           appartengono a un ritmo che non c'è più. */
        $valeDa = !empty($p['schedule_from']) ? (string)$p['schedule_from'] : (string)$p['start_date'];
        foreach ($dates as $date) {
            if ($valeDa !== '' && $date < $valeDa) continue;
            if (!td_pill_due($p, $date)) continue;
            foreach ($ore as $t) {
                for ($seq = 1; $seq <= $t[1]; $seq++) {
                    sec_q("INSERT IGNORE INTO pill_doses (user_id, plan_id, due_date, due_time, seq)
                           VALUES (?, ?, ?, ?, ?)",
                          [$uid, (int)$p['id'], $date, $t[0], $seq], 'iissi');
                }
            }
        }
    }
}

function td_pills_ensure(int $uid, string $date): void {
    td_pills_ensure_giorni($uid, [$date]);
}

/** Le prese rimaste in sospeso nei giorni scorsi.

    Prima sparivano insieme al loro giorno, ed è esattamente il contrario di
    quello che serve: una pastiglia dimenticata è la cosa che deve tornare a
    galla, non quella che deve sparire. Quattordici giorni indietro e non di
    più — oltre non ci si ricorda davvero se la si è presa, e spuntarla a
    caso è peggio che lasciarla in bianco.

    Si generano anche le prese dei giorni scorsi che nessuno ha ancora
    scritto: chi non ha aperto l'app per una settimana, se no, non avrebbe
    niente da recuperare proprio nel caso in cui serve. */
function td_pills_arretrate(int $uid): array {
    if (!td_pills_c_e()) return [];
    $oggi = date('Y-m-d');
    $giorni = [];
    for ($i = 1; $i <= 7; $i++) $giorni[] = date('Y-m-d', strtotime($oggi . ' -' . $i . ' day'));
    td_pills_ensure_giorni($uid, $giorni);

    $da = date('Y-m-d', strtotime($oggi . ' -14 day'));
    $rows = sec_all("SELECT d.id, d.plan_id, d.due_date, d.due_time, d.seq, d.status,
                            p.name, p.dose, p.color, p.notes
                     FROM pill_doses d
                     JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
                     WHERE d.user_id = ? AND d.status = 'pending'
                       AND d.due_date < ? AND d.due_date >= ?
                     ORDER BY d.due_date DESC, d.due_time", [$uid, $oggi, $da], 'iss');
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'id'      => (int)$d['id'],
            'plan_id' => (int)$d['plan_id'],
            'date'    => $d['due_date'],
            'time'    => substr((string)$d['due_time'], 0, 5),
            'seq'     => (int)$d['seq'],
            'status'  => 'pending',
            'name'    => $d['name'],
            'dose'    => $d['dose'],
            'notes'   => $d['notes'],
            'color'   => $d['color'],
        ];
    }
    return $out;
}

function td_pill_plans(int $uid): array {
    if (!td_pills_c_e()) return [];
    $rows = sec_all("SELECT * FROM pill_plans WHERE user_id = ? ORDER BY is_active DESC, sort_order, id", [$uid], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'dose'      => $r['dose'],
            'notes'     => $r['notes'],
            'color'     => $r['color'],
            'freq_type' => $r['freq_type'],
            'weekdays'  => $r['weekdays'],
            'month_days'=> $r['month_days'],
            'interval_days' => (int)$r['interval_days'],
            'times'     => $r['times'],
            'start_date'=> $r['start_date'],
            'end_date'  => $r['end_date'],
            'active'    => (int)$r['is_active'] === 1,
        ];
    }
    return $out;
}


/* ===================== Le azioni (corpo delle azioni della pagina) ===================== */

/** Il giorno: terapie, prese di quel giorno, arretrate e numeri dell'ultimo mese. */
function td_pill_giorno(int $uid, string $date): array {
    td_pills_ensure($uid, $date);
    $doses = sec_all("SELECT d.id, d.plan_id, d.due_time, d.seq, d.status, d.taken_at,
                             p.name, p.dose, p.color, p.notes
                      FROM pill_doses d
                      JOIN pill_plans p ON p.id = d.plan_id AND p.user_id = d.user_id
                      WHERE d.user_id = ? AND d.due_date = ?
                      ORDER BY d.due_time, d.seq", [$uid, $date], 'is');
    $out = [];
    foreach ($doses as $d) {
        $out[] = [
            'id'     => (int)$d['id'],
            'plan_id'=> (int)$d['plan_id'],
            'time'   => substr((string)$d['due_time'], 0, 5),
            'seq'    => (int)$d['seq'],
            'status' => $d['status'],
            'taken_at' => $d['taken_at'],
            'name'   => $d['name'],
            'dose'   => $d['dose'],
            'notes'  => $d['notes'],
            'color'  => $d['color'],
        ];
    }
    /* Quante ne sono state saltate negli ultimi trenta giorni: è il numero per
       cui vale la pena tenere le prese una per una. */
    $st = sec_one("SELECT SUM(status='taken') AS prese, SUM(status='skipped') AS saltate,
                          SUM(status='pending' AND due_date < CURDATE()) AS dimenticate
                   FROM pill_doses WHERE user_id = ? AND due_date > (CURDATE() - INTERVAL 30 DAY)", [$uid], 'i');
    return ['installed' => true, 'date' => $date,
            'plans' => td_pill_plans($uid), 'doses' => $out,
            'late' => td_pills_arretrate($uid),
            'stats' => ['taken' => (int)($st['prese'] ?? 0),
                        'skipped' => (int)($st['saltate'] ?? 0),
                        'missed' => (int)($st['dimenticate'] ?? 0)]];
}

/**
 * Spunta di una presa: $st e' pending|taken|skipped; $atD / $atT (data e ora vere
 * della spunta, '' se non arrivano) valgono solo per «taken»; senza, vale l'orologio.
 * Ritorna ['taken_at' => ...] oppure null se la presa non e' dell'utente.
 */
function td_pill_segna(int $uid, int $id, string $st, string $atD, string $atT): ?array {
    if (!sec_owns('pill_doses', $id, $uid)) return null;
    /* L'ora vera della spunta, non quella prevista: è la differenza fra le due
       che dice se la terapia sta reggendo davvero.

       Ora si può anche dettarla. Il caso che conta è quello di tutti i
       giorni: la pastiglia la si prende la mattina e la si spunta il
       pomeriggio, e senza poterla correggere il registro direbbe che è stata
       presa con sei ore di ritardo. Sulle arretrate serve anche il giorno,
       perché quella presa appartiene a ieri.
       Se non arriva niente vale l'orologio, come prima. */
    $quando = null;
    if ($st === 'taken') {
        $quando = ($atD !== '' && $atT !== '')
            ? $atD . ' ' . substr($atT, 0, 5) . ':00'
            : date('Y-m-d H:i:s');
    }
    sec_q("UPDATE pill_doses SET status = ?, taken_at = ? WHERE id = ? AND user_id = ?",
          [$st, $quando, $id, $uid], 'ssii');
    return ['taken_at' => $quando];
}

/**
 * Il calendario: quante prese in un giorno (0 = nessuna, cioè togliere). Le prese
 * del giorno si spuntano in ordine di orario: le prime n, le altre tornano in
 * sospeso. Una presa già spuntata tiene la sua ora vera; una nuova prende l'ora in
 * cui era prevista (mai nel futuro). Una saltata resta saltata, a meno che non
 * serva per arrivare a n. Il giorno deve essere gia' controllato (valido, non futuro).
 * Ritorna ['taken' => n, 'tot' => n] oppure null se la terapia non e' dell'utente.
 */
function td_pill_giorno_imposta(int $uid, int $pid, string $giorno, int $n): ?array {
    if (!sec_owns('pill_plans', $pid, $uid)) return null;
    td_pills_ensure_giorni($uid, [$giorno]);
    $prese = sec_all("SELECT id, status, due_time FROM pill_doses
                      WHERE user_id = ? AND plan_id = ? AND due_date = ?
                      ORDER BY due_time, seq", [$uid, $pid, $giorno], 'iis');
    /* IL CALENDARIO CHE NON SALVAVA.
       Un giorno prima dell'inizio della terapia, o prima dell'ultima volta
       che se ne sono cambiati gli orari, non ha prese: il ritmo di adesso non
       vale lì. Ma se l'utente tocca quel giorno e dice «ne ho prese due», è
       un fatto, e va scritto: si creano le prese di quel giorno con gli orari
       della terapia. Prima la richiesta si fermava qui con un errore che la
       pagina non mostrava, e sembrava che il calendario ignorasse le modifiche. */
    if (!$prese && $n > 0) {
        $piano = sec_one("SELECT times FROM pill_plans WHERE id = ? AND user_id = ?", [$pid, $uid], 'ii');
        foreach (td_pill_times((string)($piano['times'] ?? '')) as $t) {
            for ($seq = 1; $seq <= $t[1]; $seq++) {
                sec_q("INSERT IGNORE INTO pill_doses (user_id, plan_id, due_date, due_time, seq) VALUES (?, ?, ?, ?, ?)",
                      [$uid, $pid, $giorno, $t[0], $seq], 'iissi');
            }
        }
        $prese = sec_all("SELECT id, status, due_time FROM pill_doses
                          WHERE user_id = ? AND plan_id = ? AND due_date = ?
                          ORDER BY due_time, seq", [$uid, $pid, $giorno], 'iis');
    }
    if (!$prese) return ['taken' => 0, 'tot' => 0];   // zero su un giorno vuoto: niente da fare

    $n = min($n, count($prese));
    $adesso = date('Y-m-d H:i:s');
    /* quelle già prese contano per prime: se ne erano state spuntate due e
       se ne chiedono due, non cambia niente */
    $gia = array_values(array_filter($prese, function ($d) { return $d['status'] === 'taken'; }));
    $altre = array_values(array_filter($prese, function ($d) { return $d['status'] !== 'taken'; }));
    usort($altre, function ($a, $b) { return ($a['status'] === 'skipped') <=> ($b['status'] === 'skipped'); });
    $togliere = array_slice($gia, $n);
    $aggiungere = array_slice($altre, 0, max(0, $n - count($gia)));
    /* Su un giorno passato, «ne ho prese una su due» vuol dire che l'altra
       non è stata presa: si segna saltata. Lasciata in sospeso tornava a galla
       fra le prese rimaste indietro, come se fosse ancora da decidere. Oggi
       invece resta in sospeso: la giornata non è finita. */
    $restoA = $giorno < date('Y-m-d') ? 'skipped' : 'pending';
    foreach ($togliere as $d) {
        sec_q("UPDATE pill_doses SET status = ?, taken_at = NULL WHERE id = ? AND user_id = ?", [$restoA, (int)$d['id'], $uid], 'sii');
    }
    if ($restoA === 'skipped') {
        foreach (array_slice($altre, max(0, $n - count($gia))) as $d) {
            if ($d['status'] === 'pending') sec_q("UPDATE pill_doses SET status = 'skipped' WHERE id = ? AND user_id = ?", [(int)$d['id'], $uid], 'ii');
        }
    }
    foreach ($aggiungere as $d) {
        $quando = $giorno . ' ' . substr((string)$d['due_time'], 0, 5) . ':00';
        if ($quando > $adesso) $quando = $adesso;
        sec_q("UPDATE pill_doses SET status = 'taken', taken_at = ? WHERE id = ? AND user_id = ?", [$quando, (int)$d['id'], $uid], 'sii');
    }
    return ['taken' => $n, 'tot' => count($prese)];
}

/**
 * «Prese tutte»: $ids sono id di prese (non vuoti), $mode on_time (ognuna all'orario in
 * cui era prevista) o now (tutte adesso). Si toccano solo le prese non ancora spuntate:
 * una già spuntata ha già la sua ora vera, e riscriverla la cancellerebbe.
 * Ritorna ['n' => quante, 'taken' => [id => ora]].
 */
function td_pill_segna_tutte(int $uid, array $ids, string $mode): array {
    $segni = implode(',', array_fill(0, count($ids), '?'));
    $rows = sec_all("SELECT id, due_date, due_time FROM pill_doses
                     WHERE user_id = ? AND status <> 'taken' AND id IN ($segni)",
                    array_merge([$uid], $ids), 'i' . str_repeat('i', count($ids)));
    $adesso = date('Y-m-d H:i:s');
    $fatte = [];
    foreach ($rows as $r) {
        $quando = $mode === 'on_time'
            ? $r['due_date'] . ' ' . substr((string)$r['due_time'], 0, 5) . ':00'
            : $adesso;
        /* una presa prevista più avanti di adesso non si scrive nel futuro */
        if ($quando > $adesso) $quando = $adesso;
        sec_q("UPDATE pill_doses SET status = 'taken', taken_at = ? WHERE id = ? AND user_id = ?",
              [$quando, (int)$r['id'], $uid], 'sii');
        $fatte[(int)$r['id']] = $quando;
    }
    return ['n' => count($fatte), 'taken' => $fatte];
}

/**
 * Come è andata, giorno per giorno: un giorno per riga con quante prese erano previste
 * e come sono finite; i giorni in cui la terapia non toccava non compaiono affatto,
 * perché un buco lì non è una dimenticanza. $mese 'AAAA-MM' (un mese per volta) oppure,
 * se vuoto, gli ultimi $gg giorni. Ritorna null se la terapia non e' dell'utente.
 */
function td_pill_storia(int $uid, int $id, string $mese, int $gg): ?array {
    if (!sec_owns('pill_plans', $id, $uid)) return null;
    if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $mese)) {
        $da = $mese . '-01';
        $a  = date('Y-m-t', strtotime($da));
    } else {
        /* la data si calcola qui e si passa come valore: un INTERVAL con un
           segnaposto dentro non è portabile quanto un confronto fra date */
        $da = date('Y-m-d', strtotime(date('Y-m-d') . ' -' . ($gg - 1) . ' day'));
        $a  = date('Y-m-d');
    }

    $rows = sec_all("SELECT due_date, status, COUNT(*) AS n
                     FROM pill_doses
                     WHERE user_id = ? AND plan_id = ? AND due_date >= ? AND due_date <= ?
                     GROUP BY due_date, status
                     ORDER BY due_date", [$uid, $id, $da, $a], 'iiss');
    $per = [];
    foreach ($rows as $r) {
        $d = $r['due_date'];
        if (!isset($per[$d])) $per[$d] = ['date' => $d, 'taken' => 0, 'skipped' => 0, 'pending' => 0];
        $per[$d][$r['status']] = (int)$r['n'];
    }
    return ['from' => $da, 'to' => $a, 'oggi' => date('Y-m-d'), 'days' => array_values($per)];
}

/**
 * Scrive una terapia. $f ha i valori GIA' ripuliti: name, dose, notes, color, freq,
 * wd (7 caratteri 0/1), md (giorni del mese con virgole), int (1-365), times (testo
 * «08:00x2,20:00x1» rimesso in forma da td_pill_times), start, end (data o null),
 * att (0/1). $id = 0 crea, altrimenti modifica (null se non e' dell'utente).
 * Ritorna l'id della terapia.
 */
function td_pill_scrivi(int $uid, int $id, array $f): ?int {
    [$name, $dose, $notes, $color, $freq, $wd, $md, $int, $times, $start, $end, $att] =
        [$f['name'], $f['dose'], $f['notes'], $f['color'], $f['freq'], $f['wd'], $f['md'], $f['int'], $f['times'], $f['start'], $f['end'], $f['att']];

    if ($id > 0) {
        if (!sec_owns('pill_plans', $id, $uid)) return null;

        /* Il ritmo è cambiato? Si guarda PRIMA di scrivere. Solo se cambia
           davvero si sposta schedule_from a oggi: correggere il nome o il
           colore non deve buttare via la storia delle prese passate, mentre
           cambiare gli orari sì — da oggi in poi vale il ritmo nuovo, prima
           valeva quello vecchio e le prese di allora restano com'erano. */
        $prima = sec_one("SELECT times, freq_type, weekdays, month_days, interval_days, schedule_from, start_date
                          FROM pill_plans WHERE id=? AND user_id=?", [$id, $uid], 'ii');
        $ritmoCambiato = !$prima
            || (string)$prima['times']         !== $times
            || (string)$prima['freq_type']     !== $freq
            || (string)$prima['weekdays']      !== $wd
            || (string)$prima['month_days']    !== $md
            || (int)$prima['interval_days']    !== $int;

        if ($ritmoCambiato) {
            /* Da quando vale il ritmo nuovo: di norma da oggi, ma da DOMANI se
               oggi una presa è già stata segnata.
               Il caso: pastiglia delle 12:15 presa a mezzogiorno, e alle 14 si
               sposta l'orario alle 12:00. Con "da oggi" nascerebbe una presa
               delle 12:00 in sospeso accanto a quella delle 12:15 già spuntata:
               due righe per una pastiglia sola, che è il doppione di prima
               spostato dai giorni scorsi a oggi.
               La giornata di oggi è già stata vissuta con il ritmo vecchio, e
               l'orario nuovo riguarda domani — che poi è il motivo per cui lo
               si sta cambiando: per la prossima volta. */
            $giaSegnataOggi = sec_one("SELECT id FROM pill_doses
                                       WHERE plan_id=? AND user_id=? AND due_date=CURDATE()
                                         AND status <> 'pending' LIMIT 1", [$id, $uid], 'ii');
            $valeDa = $giaSegnataOggi ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
        } else {
            $valeDa = !empty($prima['schedule_from']) ? (string)$prima['schedule_from'] : (string)$prima['start_date'];
        }

        sec_q("UPDATE pill_plans SET name=?, dose=?, notes=?, color=?, freq_type=?, weekdays=?,
                      month_days=?, interval_days=?, times=?, start_date=?, end_date=?, is_active=?,
                      schedule_from=?
               WHERE id=? AND user_id=?",
              [$name, $dose, $notes, $color, $freq, $wd, $md, $int, $times, $start, $end, $att, $valeDa, $id, $uid],
              'sssssssisssisii');
        /* Le prese in sospeso si rifanno solo DA QUANDO vale il ritmo nuovo, non
           da oggi: se il ritmo nuovo parte domani, quella di oggi ancora in
           sospeso è dovuta davvero con l'orario vecchio e cancellarla vorrebbe
           dire far sparire una pastiglia che va ancora presa. Quelle già
           spuntate non si toccano mai: sono un fatto avvenuto. */
        sec_q("DELETE FROM pill_doses WHERE plan_id=? AND user_id=? AND due_date >= ? AND status='pending'",
              [$id, $uid, $valeDa], 'iis');
    } else {
        sec_q("INSERT INTO pill_plans (user_id, name, dose, notes, color, freq_type, weekdays,
                                       month_days, interval_days, times, start_date, end_date, is_active, schedule_from)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$uid, $name, $dose, $notes, $color, $freq, $wd, $md, $int, $times, $start, $end, $att, $start],
              'isssssssisssis');
        $id = sec_insert_id();
    }
    td_pills_ensure($uid, date('Y-m-d'));
    return $id;
}

/** Toglie la terapia e le sue prese (senza la terapia non vogliono dire più niente). false se non e' dell'utente. */
function td_pill_elimina(int $uid, int $id): bool {
    if (!sec_owns('pill_plans', $id, $uid)) return false;
    /* via anche le prese: senza la terapia non vogliono dire più niente, e
       tenerle farebbe un archivio di righe orfane */
    sec_q("DELETE FROM pill_doses WHERE plan_id=? AND user_id=?", [$id, $uid], 'ii');
    sec_q("DELETE FROM pill_plans WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    return true;
}
