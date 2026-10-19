<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_eventi.php
   Eventi: forma per il browser, invitati, ricorrenze (RRULE) e lettura di un intervallo di date.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

/* --- eventi --------------------------------------------------------- */
/* La fine vera di un evento: end_date se c'è, altrimenti il giorno stesso.
   Una funzione sola perché la regola non venga riscritta a mano ogni volta —
   è il genere di condizione che, ripetuta in venti punti, in uno dei venti
   finisce sbagliata. */
function pl_fine_evento(array $r): string {
    return !empty($r['end_date']) ? (string)$r['end_date'] : (string)$r['event_date'];
}

/* Quante caselle occupa nel calendario. Estremi compresi: dal 10 al 12 sono
   tre giorni, non due. */
function pl_giorni_evento(array $r): int {
    $inizio = strtotime((string)$r['event_date']);
    $fine   = strtotime(pl_fine_evento($r));
    if ($inizio === false || $fine === false || $fine < $inizio) return 1;
    return (int)floor(($fine - $inizio) / 86400) + 1;
}

/* Il massimo numero di giorni che un evento può coprire. Non è una regola di
   buon gusto: un evento lungo entra in TUTTE le caselle che attraversa, quindi
   uno da diecimila giorni comparirebbe in ogni vista per trent'anni. Un anno
   basta a qualunque uso vero — un corso, una vacanza, una sessione d'esami. */
const PL_MAX_GIORNI_EVENTO = 366;

function pl_event_out(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'notes'       => $r['notes'] ?? '',
        'location'    => $r['location'] ?? '',
        /* Il luogo scelto dalla ricerca sulle mappe viaggia separato dal testo
           libero: `location` resta quello che l'utente vede e può correggere a
           mano, le coordinate servono per aprire la mappa nel punto esatto
           anche quando il nome è ambiguo (quante "Via Roma" ci sono). */
        'place_name'  => $r['place_name'] ?? null,
        'place_lat'   => isset($r['place_lat']) && $r['place_lat'] !== null ? (float)$r['place_lat'] : null,
        'place_lon'   => isset($r['place_lon']) && $r['place_lon'] !== null ? (float)$r['place_lon'] : null,
        'place_ref'   => $r['place_ref'] ?? null,
        'duration_locked' => (int)($r['duration_locked'] ?? 1) === 1,
        'guests'      => [],   // riempito da pl_attach_guests
        'date'        => $r['event_date'],
        /* La fine di un evento è sempre COALESCE(end_date, event_date): nel
           database NULL vuol dire «finisce dove comincia», ma verso il
           JavaScript esce già risolta, così nessuna vista deve ricordarsi di
           gestire il caso nullo. `giorni` è il numero di caselle che l'evento
           occupa nella griglia del mese, 1 per la stragrande maggioranza. */
        'end_date'    => !empty($r['end_date']) ? $r['end_date'] : $r['event_date'],
        'giorni'      => pl_giorni_evento($r),
        'multiday'    => pl_giorni_evento($r) > 1,
        'start'       => substr($r['start_time'], 0, 5),
        'end'         => substr($r['end_time'], 0, 5),
        'all_day'     => (int)$r['is_all_day'] === 1,
        'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
        'calendar_id' => $r['calendar_id'] !== null ? (int)$r['calendar_id'] : null,
        'project_id'  => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name'=> $r['project_name'] ?? null,
        'project_color'=> $r['project_color'] ?? null,
        'habit_id'    => $r['habit_id'] !== null ? (int)$r['habit_id'] : null,
        'habit_icon'  => $r['habit_icon'] ?? null,
        'habit_color' => $r['habit_color'] ?? null,
        'routine_id'  => $r['routine_id'] !== null ? (int)$r['routine_id'] : null,
        'routine_name'=> $r['routine_name'] ?? null,
        'routine_group'     => $r['routine_group'],
        'routine_collapsed' => (int)$r['routine_collapsed'] === 1,
        'importance'  => $r['importance'],
        'color'       => $r['color'],
        'type'        => $r['item_type'],
        'done'        => (int)$r['is_completed'] === 1,
        'notify'      => $r['notify_type'],
        'notify_min'  => (int)$r['notify_minutes'],
        'repeat'      => $r['repeat_type'],
        'repeat_interval' => (int)$r['repeat_interval'],
        'repeat_unit' => $r['repeat_unit'],
        'repeat_until'=> $r['repeat_until'],
        'series_id'   => $r['series_id'] !== null ? (int)$r['series_id'] : null,
        /* Le occorrenze di una serie non esistono nel database: sono cloni
           calcolati in lettura e portano tutte lo stesso `id`, quello del
           master. Da sole non si distinguono. `occ_date` dice di quale
           ripetizione si tratta, `master_id` a chi appartiene. */
        'occ_date'    => $r['occ_date'] ?? $r['event_date'],
        'master_id'   => isset($r['master_id']) ? (int)$r['master_id'] : (int)$r['id'],
        'is_occurrence' => !empty($r['rrule']) && isset($r['occ_date']),
        'uid'         => (isset($r['master_id']) ? (int)$r['master_id'] : (int)$r['id'])
                         . '@' . ($r['occ_date'] ?? $r['event_date']),
    ];
}

/** Attacca agli eventi la lista degli invitati, con una sola query. */
function pl_attach_guests(int $userId, array $events): array {
    if (empty($events)) return $events;
    $ids = [];
    foreach ($events as $e) { $ids[] = (int)$e['id']; }
    /* un ? per ogni id invece della lista incollata: gli id sono gia' interi,
       ma cosi' resta vero anche se domani qualcuno toglie il cast */
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = sec_all("SELECT event_id, email, name FROM event_guests
                     WHERE user_id = ? AND event_id IN ($ph)", array_merge([$userId], $ids));
    if (empty($rows)) return $events;
    $byEvent = [];
    foreach ($rows as $g) {
        $byEvent[(int)$g['event_id']][] = ['email' => $g['email'], 'name' => $g['name']];
    }
    foreach ($events as $k => $e) {
        if (isset($byEvent[(int)$e['id']])) $events[$k]['guests'] = $byEvent[(int)$e['id']];
    }
    return $events;
}

/* Una data che arriva dal frontend può essere una stringa vuota, un null o
   una sciocchezza. PHP 8 non perdona più: passare null dove è dichiarato
   `string` è un TypeError, e strtotime('') torna false, che date() poi
   interpreta come il 1970. Facciamo passare tutto di qui, così a valle
   esiste una sola forma valida: 'Y-m-d' oppure null. */
function pl_date_or_null(mixed $v): ?string {
    if (is_array($v) || is_object($v) || is_bool($v)) return null;
    $v = trim((string)($v ?? ''));
    if ($v === '' || $v === '0000-00-00' || strcasecmp($v, 'null') === 0) return null;
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/* La RRULE salvata sul master è la verità sulla ripetizione: le colonne
   repeat_* restano per compatibilità con gli eventi vecchi e con Google,
   ma se c'è la regola scritta è quella a comandare. */
function pl_rrule_parse(?string $rrule): ?array {
    if ($rrule === null || trim($rrule) === '') return null;
    $out = ['unit' => null, 'interval' => 1, 'until' => null, 'count' => null];
    foreach (explode(';', str_ireplace('RRULE:', '', trim($rrule))) as $pezzo) {
        $pezzo = trim($pezzo);
        if ($pezzo === '' || !str_contains($pezzo, '=')) continue;
        [$k, $v] = explode('=', $pezzo, 2);
        switch (strtoupper(trim($k))) {
            case 'FREQ':
                $mappa = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'];
                $out['unit'] = $mappa[strtoupper(trim($v))] ?? null;
                break;
            case 'INTERVAL':
                $out['interval'] = max(1, (int)$v);
                break;
            case 'UNTIL':
                $solaData = substr(preg_replace('/[^0-9]/', '', $v), 0, 8);
                if (strlen($solaData) === 8) $out['until'] = substr($solaData, 0, 4) . '-' . substr($solaData, 4, 2) . '-' . substr($solaData, 6, 2);
                break;
            case 'COUNT':
                $out['count'] = max(1, (int)$v);
                break;
        }
    }
    return $out['unit'] === null ? null : $out;
}

/**
 * Le date generate da un master dentro un periodo.
 *
 * Il punto delicato: NON si parte dall'inizio della serie avanzando di un
 * passo per volta. Un evento quotidiano nato tre anni fa richiederebbe mille
 * giri prima di arrivare al mese che stiamo guardando, e con un tetto di
 * sicurezza sul ciclo quel mese resterebbe vuoto senza dire perché. Si
 * calcola quanti passi servono per atterrare dentro il periodo e si salta lì.
 */
function pl_occurrences(array $r, string $from, string $to): array {
    $regola = pl_rrule_parse($r['rrule'] ?? null);

    if ($regola === null) {
        // evento vecchio senza RRULE: si ricostruisce la regola dalle colonne
        $tipo = (string)($r['repeat_type'] ?? 'none');
        if ($tipo === 'none' || $tipo === '') return [];
        $unita = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
        if ($tipo === 'custom') {
            $u = (string)($r['repeat_unit'] ?? 'week');
            $regola = ['unit' => in_array($u, ['day', 'week', 'month', 'year'], true) ? $u : 'week',
                       'interval' => max(1, (int)($r['repeat_interval'] ?? 1)), 'until' => null, 'count' => null];
        } elseif (isset($unita[$tipo])) {
            $regola = ['unit' => $unita[$tipo], 'interval' => 1, 'until' => null, 'count' => null];
        } else {
            return [];
        }
    }

    $inizio = pl_date_or_null($r['event_date'] ?? null);
    if ($inizio === null) return [];

    /* Il limite può stare sulla colonna, dentro la RRULE o in nessuno dei
       due: vince il più stretto fra quelli che esistono. */
    $fine = pl_date_or_null($r['repeat_until'] ?? null);
    if ($regola['until'] !== null) $fine = ($fine === null || $regola['until'] < $fine) ? $regola['until'] : $fine;
    $limite = ($fine !== null && $fine < $to) ? $fine : $to;
    if ($limite < $inizio || $limite < $from) return [];

    $passo = max(1, (int)$regola['interval']);
    $date  = [];
    $tetto = defined('PL_MAX_OCCURRENCES') ? PL_MAX_OCCURRENCES : 400;

    if ($regola['unit'] === 'day' || $regola['unit'] === 'week') {
        $giorni = $regola['unit'] === 'week' ? $passo * 7 : $passo;
        $dInizio = new DateTimeImmutable($inizio);
        $dFrom   = new DateTimeImmutable($from);
        // salto diretto: quanti passi interi mancano per entrare nel periodo
        $distanza = (int)$dInizio->diff($dFrom)->format('%r%a');
        $salti = $distanza > 0 ? (int)ceil($distanza / $giorni) : 0;
        $cur = $dInizio->modify('+' . ($salti * $giorni) . ' days');
        $n = 0;
        while ($cur->format('Y-m-d') <= $limite && $n < $tetto) {
            $g = $cur->format('Y-m-d');
            if ($g >= $from && $g >= $inizio) $date[] = $g;
            $cur = $cur->modify('+' . $giorni . ' days');
            $n++;
        }
    } else {
        $mesi = $regola['unit'] === 'year' ? $passo * 12 : $passo;
        $aI = (int)substr($inizio, 0, 4); $mI = (int)substr($inizio, 5, 2); $gI = (int)substr($inizio, 8, 2);
        $aF = (int)substr($from, 0, 4);   $mF = (int)substr($from, 5, 2);
        $distanza = (($aF - $aI) * 12) + ($mF - $mI);
        $salti = $distanza > 0 ? (int)ceil($distanza / $mesi) : 0;
        /* Un passo indietro di sicurezza: il giorno di partenza può cadere
           dopo la fine del mese di arrivo e il conto per mesi non lo sa. */
        $salti = max(0, $salti - 1);
        $n = 0;
        while ($n < $tetto) {
            $off = ($salti + $n) * $mesi;
            $anno = $aI + intdiv($mI - 1 + $off, 12);
            $mese = (($mI - 1 + $off) % 12) + 1;
            $n++;
            /* Il 31 di un mese che non ce l'ha si salta, non si arrotonda: è
               quello che fa anche Google. Arrotondare al 1º del mese dopo
               farebbe scivolare l'evento in avanti a ogni febbraio. */
            if (!checkdate($mese, $gI, $anno)) {
                if (sprintf('%04d-%02d-01', $anno, $mese) > $limite) break;
                continue;
            }
            $g = sprintf('%04d-%02d-%02d', $anno, $mese, $gI);
            if ($g > $limite) break;
            if ($g >= $from && $g >= $inizio) $date[] = $g;
        }
    }

    if ($regola['count'] !== null && count($date) > $regola['count']) {
        $date = array_slice($date, 0, $regola['count']);
    }
    return $date;
}

function pl_events_range(int $userId, string $from, string $to): array {
    // Estraiamo sia gli eventi normali/eccezioni, sia i "Master" delle serie ripetute
    $rows = sec_all(
        "SELECT e.*, p.name AS project_name, p.color AS project_color,
                h.icon AS habit_icon, h.color AS habit_color, r.name AS routine_name
         FROM planner_events e
         LEFT JOIN projects p ON p.id = e.project_id AND p.user_id = e.user_id
         LEFT JOIN habits h   ON h.id = e.habit_id   AND h.user_id = e.user_id
         LEFT JOIN planner_routines r ON r.id = e.routine_id AND r.user_id = e.user_id
         WHERE e.user_id = ?
           AND (
               /* Un evento tocca il periodo se comincia entro la fine e finisce
                  dopo l'inizio. Prima la condizione era «event_date dentro il
                  periodo», che per un evento dal 28 al 3 lo faceva sparire dal
                  mese successivo: comincia prima, quindi non veniva nemmeno
                  letto. COALESCE perché end_date NULL vuol dire «finisce dove
                  comincia». */
               (e.rrule IS NULL AND e.original_date IS NULL
                AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?)
               OR
               (e.rrule IS NOT NULL AND e.event_date <= ? AND (e.repeat_until IS NULL OR e.repeat_until >= ?))
               OR
               (e.original_date IS NOT NULL AND (e.event_date BETWEEN ? AND ? OR e.original_date BETWEEN ? AND ?))
           )",
        [$userId, $to, $from, $to, $from, $from, $to, $from, $to], 'issssssss'
    );

    $instances = [];
    $exceptions = [];

    /* Le eccezioni si leggono anche quando la data SPOSTATA è fuori periodo:
       basta che lo sia la data d'origine. Senza questa seconda condizione un
       evento trascinato al mese successivo tornava a comparire anche nella
       casella di partenza, perché il master non sapeva di essere stato
       scavalcato e ricreava l'occorrenza. */
    foreach ($rows as $r) {
        if ($r['original_date'] !== null && $r['original_date'] !== '' && !empty($r['series_id'])) {
            $exceptions[(int)$r['series_id']][(string)$r['original_date']] = $r;
        }
    }

    foreach ($rows as $r) {
        // Evento normale o eccezione: si mostra così com'è, se cade nel periodo
        if (empty($r['rrule'])) {
            // il marker di cancellazione è solo un segnaposto: non si disegna
            if ((int)($r['is_cancelled'] ?? 0) === 1) continue;
            // fuori periodo solo se non lo tocca affatto: un evento lungo che
            // comincia prima o finisce dopo va comunque disegnato
            if (pl_fine_evento($r) < $from || $r['event_date'] > $to) continue;
            $r['occ_date']  = $r['event_date'];
            $r['master_id'] = !empty($r['series_id']) ? (int)$r['series_id'] : (int)$r['id'];
            $instances[] = $r;
            continue;
        }

        // Master con RRULE: i cloni virtuali nascono qui, uno per data generata
        foreach (pl_occurrences($r, $from, $to) as $data) {
            // se per quella data esiste una riga vera (spostata o cancellata) parla lei
            if (isset($exceptions[(int)$r['id']][$data])) continue;
            $clone = $r;
            $clone['event_date'] = $data;
            /* La ripetizione dura quanto il capostipite: se il master va dal 3
               al 5, ogni occorrenza copre tre giorni a partire dalla sua data.
               Senza questa riga le copie si porterebbero dietro la end_date
               dell'originale, cioè finirebbero tutte nel passato. */
            $durata = pl_giorni_evento($r);
            $clone['end_date'] = $durata > 1
                ? date('Y-m-d', strtotime($data . ' +' . ($durata - 1) . ' day'))
                : null;
            $clone['occ_date']   = $data;   // quale ripetizione stiamo guardando
            $clone['master_id']  = (int)$r['id'];
            $clone['series_id']  = (int)$r['id'];
            $instances[] = $clone;
        }
    }

    // Riordiniamo l'array finale generato al volo per rispettare il rendering della griglia
    usort($instances, function($a, $b) {
        if ($a['event_date'] !== $b['event_date']) return strcmp($a['event_date'], $b['event_date']);
        if ((int)$a['is_all_day'] !== (int)$b['is_all_day']) return (int)$b['is_all_day'] <=> (int)$a['is_all_day'];
        if ($a['start_time'] !== $b['start_time']) return strcmp($a['start_time'], $b['start_time']);
        if ((int)$a['sort_order'] !== (int)$b['sort_order']) return (int)$a['sort_order'] <=> (int)$b['sort_order'];
        return (int)$a['id'] <=> (int)$b['id'];
    });

    return pl_attach_guests($userId, array_map('pl_event_out', $instances));
}
