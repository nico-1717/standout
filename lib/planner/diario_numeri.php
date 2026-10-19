<?php
/* =====================================================================
   STANDOUT — I NUMERI DEL DIARIO
   Usato dalla pagina "Il diario in numeri" (diary_api.php?ajax=insights)
   e dal resoconto del trimestre fatto dall'IA (diario_ai.php): un solo
   modo di contare, un solo risultato possibile.

   Per ogni giorno di un intervallo:
     m  umore 1..5        e  energia 1..5
     w  parole scritte    l  voci negli elenchi (cose belle, imparate)
     hd abitudini fatte   hu abitudini previste (con i decimali: una da
                             3 volte a settimana vale 3/7 al giorno)
     p  pomodori finiti   f  minuti di concentrazione
   ===================================================================== */

/** Parole di un testo: qualunque sequenza di lettere o cifre. */
function dn_parole(string $t): int {
    $t = trim($t);
    if ($t === '') return 0;
    return (int)preg_match_all('/[\p{L}\p{N}\']+/u', $t);
}

/** Quante volte un'abitudine era prevista in un giorno (0..1, o una frazione). */
function dn_prevista(array $h, string $d): float {
    if ($h['start_date'] > $d) return 0.0;
    if (!empty($h['end_date']) && $h['end_date'] < $d) return 0.0;
    if (!empty($h['archived_at']) && substr((string)$h['archived_at'], 0, 10) < $d) return 0.0;
    switch ($h['frequency_type']) {
        case 'daily':
            $mask = str_pad((string)$h['weekdays'], 7, '1');
            return substr($mask, (int)date('N', strtotime($d)) - 1, 1) === '1' ? 1.0 : 0.0;
        case 'interval':
            $n = max(1, (int)$h['interval_days']);
            $g = (int)floor((strtotime($d) - strtotime($h['start_date'])) / 86400);
            return ($g >= 0 && $g % $n === 0) ? 1.0 : 0.0;
        case 'weekly':
            return min(7, max(1, (int)$h['times_per_week'])) / 7;
        case 'monthly':
            return min(31, max(1, (int)$h['times_per_month'])) / (int)date('t', strtotime($d));
    }
    return 0.0;
}

/** I giorni di un intervallo, con tutti i numeri. Le chiavi sono le date. */
function dn_giorni(int $uid, string $da, string $a): array {
    $out = [];
    for ($t = strtotime($da); $t <= strtotime($a); $t += 86400) {
        $out[date('Y-m-d', $t)] = ['m' => null, 'e' => null, 'w' => 0, 'l' => 0, 'hd' => 0, 'hu' => 0.0, 'p' => 0, 'f' => 0];
    }
    foreach (sec_all("SELECT entry_date, mood, energy, note FROM habit_daily_journal
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ?", [$uid, $da, $a], 'iss') as $r) {
        $k = $r['entry_date'];
        if (!isset($out[$k])) continue;
        $out[$k]['m'] = $r['mood'] !== null ? (int)$r['mood'] : null;
        $out[$k]['e'] = $r['energy'] !== null ? (int)$r['energy'] : null;
        $out[$k]['w'] = dn_parole((string)$r['note']);
    }
    foreach (sec_all("SELECT entry_date, COUNT(*) AS n FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> ''
                      GROUP BY entry_date", [$uid, $da, $a], 'iss') as $r) {
        if (isset($out[$r['entry_date']])) $out[$r['entry_date']]['l'] = (int)$r['n'];
    }
    /* abitudini: le fatte dai registri, le previste dalle regole di ciascuna */
    foreach (sec_all("SELECT log_date, COUNT(*) AS n FROM habit_logs
                      WHERE user_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
                      GROUP BY log_date", [$uid, $da, $a], 'iss') as $r) {
        if (isset($out[$r['log_date']])) $out[$r['log_date']]['hd'] = (int)$r['n'];
    }
    $abitudini = sec_all("SELECT frequency_type, weekdays, interval_days, times_per_week, times_per_month,
                                 start_date, end_date, is_archived, archived_at
                          FROM habits WHERE user_id = ? AND habit_kind = 'build'
                            AND start_date <= ?", [$uid, $a], 'is');
    if ($abitudini) {
        foreach ($out as $d => &$g) {
            $tot = 0.0;
            foreach ($abitudini as $h) $tot += dn_prevista($h, $d);
            $g['hu'] = round($tot, 2);
        }
        unset($g);
    }
    /* pomodori: quelli a tempo arrivati in fondo; i minuti, tutti */
    foreach (sec_all("SELECT DATE(start_time) AS d,
                             SUM(CASE WHEN `mode` = 0 AND status IN ('completato','archiviato') THEN 1 ELSE 0 END) AS n,
                             SUM(COALESCE(duration_seconds, 0)) AS s
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND start_time >= ? AND start_time < DATE_ADD(?, INTERVAL 1 DAY)
                      GROUP BY DATE(start_time)", [$uid, $da, $a], 'iss') as $r) {
        if (isset($out[$r['d']])) { $out[$r['d']]['p'] = (int)$r['n']; $out[$r['d']]['f'] = (int)round($r['s'] / 60); }
    }
    return $out;
}

/** Riassunto di un insieme di giorni (una settimana, un mese, un anno...). */
function dn_riassunto(array $giorni): array {
    $m = []; $e = []; $scritti = 0; $parole = 0; $hd = 0; $hu = 0.0; $p = 0; $f = 0;
    foreach ($giorni as $g) {
        if ($g['m'] !== null) $m[] = $g['m'];
        if ($g['e'] !== null) $e[] = $g['e'];
        if ($g['w'] > 0 || $g['l'] > 0) $scritti++;
        $parole += $g['w']; $hd += $g['hd']; $hu += $g['hu']; $p += $g['p']; $f += $g['f'];
    }
    $media = function (array $a) { return $a ? round(array_sum($a) / count($a), 2) : null; };
    return [
        'giorni' => count($giorni), 'scritti' => $scritti, 'parole' => $parole,
        'umore' => $media($m), 'energia' => $media($e),
        'abitudini_fatte' => $hd, 'abitudini_previste' => (int)round($hu),
        'abitudini_pct' => $hu > 0 ? (int)round(min(1, $hd / $hu) * 100) : null,
        'pomodori' => $p, 'minuti' => $f,
    ];
}

/** Primo e ultimo giorno di un trimestre. */
function dn_trimestre(int $anno, int $q): array {
    $m = ($q - 1) * 3 + 1;
    $da = sprintf('%04d-%02d-01', $anno, $m);
    $a = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anno, $m + 2)));
    return [$da, $a];
}

/* Quanto bisogna aver scritto in un trimestre perché il resoconto abbia
   qualcosa da dire: sotto, uscirebbero frasi generiche costruite sul niente. */
const DN_MIN_GIORNI = 15;
const DN_MIN_PAROLE = 1500;

/* Le tabelle del resoconto e del libro. Si creano da sole al primo uso: sono
   nuove, non toccano le altre, e così la funzione c'è appena caricato il
   codice, senza una migrazione da ricordare. */
function dn_tabelle_ai(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        sec_q("CREATE TABLE IF NOT EXISTS diary_ai_reports (
                 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                 user_id INT NOT NULL,
                 anno SMALLINT UNSIGNED NOT NULL,
                 trimestre TINYINT UNSIGNED NOT NULL,
                 periodo_da DATE NOT NULL,
                 periodo_a DATE NOT NULL,
                 contenuto LONGTEXT NOT NULL,
                 numeri LONGTEXT NOT NULL,
                 crediti INT UNSIGNED NOT NULL DEFAULT 0,
                 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 UNIQUE KEY uq_trimestre (user_id, anno, trimestre)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        sec_q("CREATE TABLE IF NOT EXISTS diary_ai_books (
                 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                 user_id INT NOT NULL,
                 titolo VARCHAR(200) NOT NULL DEFAULT '',
                 periodo_da DATE NOT NULL,
                 periodo_a DATE NOT NULL,
                 unito_a INT UNSIGNED NULL COMMENT 'il libro precedente che questo continua e riordina',
                 stato ENUM('scrittura','fatto') NOT NULL DEFAULT 'scrittura',
                 piano LONGTEXT NOT NULL,
                 capitoli LONGTEXT NOT NULL,
                 crediti INT UNSIGNED NOT NULL DEFAULT 0,
                 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                 KEY idx_utente (user_id, created_at)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}
