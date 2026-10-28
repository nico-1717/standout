<?php
/* =====================================================================
   STANDOUT — PASTIGLIE per l'API /api/v1/pills (PROGETTO §15, passo 9, fetta 6)

   Come lib/habits_api.php: qui c'e' solo il controllo dei tipi e la modifica
   «a campi» (PATCH). Il lavoro vero (il ritmo, le prese che non devono tornare,
   il calendario) lo fanno le funzioni della pagina, in lib/pills_core.php:
   td_pill_giorno(), td_pill_scrivi(), td_pill_segna(), td_pill_giorno_imposta(),
   td_pill_segna_tutte(), td_pill_storia(), td_pill_elimina().

   Differenze dalla pagina, tutte volute:
   - la pagina corregge in silenzio quello che non capisce (un orario scritto male
     diventa «08:00x1»); l'app riceve un errore col nome del campo (422);
   - la pagina crea la terapia INATTIVA se il modulo non manda «active»; qui di
     partenza e' attiva (se no una terapia nuova non avrebbe prese);
   - una spunta con l'ora dettata non puo' stare nel futuro.
   Errori: ['errore' => ['codice', 'campo', 'testo']].
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/pills_core.php';

function pl_pillole_errore(string $codice, string $campo, string $testo): array {
    return ['errore' => ['codice' => $codice, 'campo' => $campo, 'testo' => $testo]];
}

/** Una data AAAA-MM-GG valida, o null. Il fuso e' quello del sito (lo stesso della pagina). */
function pl_pillole_data($v): ?string {
    return is_string($v) ? sec_date($v, null) : null;
}

/** Il giorno: terapie, prese, arretrate e numeri. Senza la tabella: vuoto, come la pagina. */
function pl_pillole_giorno(int $uid, ?string $data): array {
    if (!td_pills_c_e()) return ['installed' => false, 'plans' => [], 'doses' => [], 'late' => [], 'stats' => ['taken' => 0, 'skipped' => 0, 'missed' => 0]];
    return td_pill_giorno($uid, $data ?? date('Y-m-d'));
}

/** Una terapia come la vede l'app, o null. */
function pl_pillole_una(int $uid, int $id): ?array {
    foreach (td_pill_plans($uid) as $p) if ($p['id'] === $id) return $p;
    return null;
}

/** I campi di una terapia (riga di pill_plans) come li manda il client: serve al PATCH, che parte da quello che c'e'. */
function pl_pillole_come_input(array $p): array {
    return ['name' => $p['name'], 'dose' => $p['dose'], 'notes' => $p['notes'], 'color' => $p['color'],
            'freq_type' => $p['freq_type'], 'weekdays' => $p['weekdays'],
            // la pagina puo' aver lasciato «13,31,» o giorni fuori scala: qui restano solo quelli veri
            'month_days' => implode(',', array_filter(explode(',', (string)$p['month_days']), fn($g) => ctype_digit($g) && (int)$g >= 1 && (int)$g <= 31)),
            'interval_days' => $p['interval_days'], 'times' => $p['times'], 'start_date' => $p['start_date'],
            'end_date' => $p['end_date'], 'active' => $p['active']];
}

/**
 * Controlla i campi di una terapia. $c e' il corpo (in creazione, con i valori di
 * partenza gia' sotto; nella modifica, con quelli attuali sotto). Ritorna ['f' => campi
 * ripuliti per td_pill_scrivi] oppure un errore.
 */
function pl_pillole_leggi(array $c, bool $mensileConGiorni = true): array {
    $testo = function (string $k, int $max, bool $obbligatorio) use ($c) {
        $v = $c[$k] ?? '';
        if ($v === null && !$obbligatorio) $v = '';
        if (!is_string($v)) return [null, pl_pillole_errore('validation', $k, "$k must be text")];
        $v = trim($v);
        if ($obbligatorio && $v === '') return [null, pl_pillole_errore('validation', $k, "$k cannot be empty")];
        if (mb_strlen($v) > $max) return [null, pl_pillole_errore('validation', $k, "$k can be at most $max characters")];
        return [sec_str($v, $max), null];
    };
    foreach ([['name', 120, true], ['dose', 60, false], ['notes', 255, false]] as [$k, $max, $ob]) {
        [$v, $e] = $testo($k, $max, $ob);
        if ($e) return $e;
        $f[$k] = $v;
    }

    $col = $c['color'] ?? '#B7A6D6';
    if (!is_string($col) || !preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $col)) return pl_pillole_errore('validation', 'color', 'color must look like #RRGGBB');
    $f['color'] = sec_color($col, '#B7A6D6');

    $freq = $c['freq_type'] ?? 'daily';
    if (!is_string($freq) || !in_array($freq, ['daily', 'monthly', 'interval'], true)) return pl_pillole_errore('validation', 'freq_type', 'freq_type must be daily, monthly or interval');
    $f['freq'] = $freq;

    $wd = $c['weekdays'] ?? '1111111';
    if (!is_string($wd) || !preg_match('/^[01]{7}$/', $wd) || strpos($wd, '1') === false) {
        return pl_pillole_errore('validation', 'weekdays', 'weekdays must be 7 characters of 0 and 1 (Monday first), with at least one 1');
    }
    $f['wd'] = $wd;

    $md = $c['month_days'] ?? '';
    if ($md === null) $md = '';
    if (!is_string($md) || ($md !== '' && !preg_match('/^[0-9]{1,2}(,[0-9]{1,2})*$/', $md))) return pl_pillole_errore('validation', 'month_days', 'month_days must look like 13,27');
    if ($md !== '') foreach (explode(',', $md) as $g) if ((int)$g < 1 || (int)$g > 31) return pl_pillole_errore('validation', 'month_days', 'month_days must be days from 1 to 31');
    if ($mensileConGiorni && $freq === 'monthly' && $md === '') return pl_pillole_errore('validation', 'month_days', 'month_days is required when freq_type is monthly');
    $f['md'] = $md;

    $int = $c['interval_days'] ?? 1;
    if (!is_int($int) || $int < 1 || $int > 365) return pl_pillole_errore('validation', 'interval_days', 'interval_days must be a number from 1 to 365');
    $f['int'] = $int;

    /* «10:00x2,20:00x1»: orario HH:MM e, se serve, «x» e quante volte (1-9). */
    $ore = $c['times'] ?? '08:00x1';
    if (!is_string($ore) || strlen($ore) > 255 || $ore === '') return pl_pillole_errore('validation', 'times', 'times must look like 08:00x1,20:00x2');
    foreach (explode(',', $ore) as $pezzo) {
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](x[1-9])?$/', $pezzo)) return pl_pillole_errore('validation', 'times', 'times must look like 08:00x1,20:00x2 (HH:MM, then x and 1-9)');
    }
    $buoni = [];
    foreach (td_pill_times($ore) as $t) $buoni[] = substr($t[0], 0, 5) . 'x' . $t[1];
    $f['times'] = implode(',', $buoni);

    $start = pl_pillole_data($c['start_date'] ?? date('Y-m-d'));
    if ($start === null) return pl_pillole_errore('validation', 'start_date', 'start_date must be YYYY-MM-DD');
    $f['start'] = $start;
    $f['end'] = null;
    if (($c['end_date'] ?? null) !== null) {
        $fine = pl_pillole_data($c['end_date']);
        if ($fine === null) return pl_pillole_errore('validation', 'end_date', 'end_date must be YYYY-MM-DD or null');
        if ($fine < $start) return pl_pillole_errore('validation', 'end_date', 'end_date cannot be before start_date');
        $f['end'] = $fine;
    }

    $att = $c['active'] ?? true;
    if (!is_bool($att)) return pl_pillole_errore('validation', 'active', 'active must be true or false');
    $f['att'] = $att ? 1 : 0;

    return ['f' => $f];
}

/** Le chiavi che il client puo' mandare per una terapia. */
const PL_PILLOLE_CHIAVI = ['name', 'dose', 'notes', 'color', 'freq_type', 'weekdays', 'month_days', 'interval_days', 'times', 'start_date', 'end_date', 'active'];

function pl_pillole_crea(int $uid, array $c): array {
    $v = pl_pillole_leggi(array_intersect_key($c, array_flip(PL_PILLOLE_CHIAVI)));
    if (isset($v['errore'])) return $v;
    $n = sec_one("SELECT COUNT(*) AS n FROM pill_plans WHERE user_id = ?", [$uid], 'i');
    if ((int)($n['n'] ?? 0) >= 100) return pl_pillole_errore('validation', 'name', 'Too many therapies (at most 100)');
    $id = td_pill_scrivi($uid, 0, $v['f']);
    return ['plan' => pl_pillole_una($uid, (int)$id)];
}

/** Cambia SOLO i campi nominati (gli altri restano quelli di ora). */
function pl_pillole_modifica(int $uid, int $id, array $c): array {
    $attuale = pl_pillole_una($uid, $id);
    if (!$attuale) return pl_pillole_errore('not_found', 'id', 'Therapy not found');
    $c = array_intersect_key($c, array_flip(PL_PILLOLE_CHIAVI));
    if (!$c) return pl_pillole_errore('validation', 'body', 'Nothing to update');
    // «mensile ma senza giorni» e' un errore solo se e' il client a toccare la frequenza o i giorni
    $v = pl_pillole_leggi($c + pl_pillole_come_input($attuale), isset($c['freq_type']) || array_key_exists('month_days', $c));
    if (isset($v['errore'])) return $v;
    if (td_pill_scrivi($uid, $id, $v['f']) === null) return pl_pillole_errore('not_found', 'id', 'Therapy not found');
    return ['plan' => pl_pillole_una($uid, $id)];
}

/** Una presa: pending|taken|skipped; con «taken» si puo' dettare l'ora vera (taken_at), mai nel futuro. */
function pl_pillole_spunta(int $uid, int $id, array $c): array {
    $st = $c['status'] ?? null;
    if (!is_string($st) || !in_array($st, ['pending', 'taken', 'skipped'], true)) return pl_pillole_errore('validation', 'status', 'status must be pending, taken or skipped');
    $atD = $atT = '';
    if (array_key_exists('taken_at', $c) && $c['taken_at'] !== null) {
        if ($st !== 'taken') return pl_pillole_errore('validation', 'taken_at', 'taken_at only makes sense with status taken');
        if (!is_string($c['taken_at']) || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})$/', $c['taken_at'], $m)) return pl_pillole_errore('validation', 'taken_at', 'taken_at must be YYYY-MM-DD HH:MM');
        $atD = sec_date($m[1], '') ?: '';
        $atT = sec_time($m[2], '') ?: '';
        if ($atD === '' || $atT === '') return pl_pillole_errore('validation', 'taken_at', 'taken_at must be a real date and time');
        if ($atD . ' ' . substr($atT, 0, 5) > date('Y-m-d H:i')) return pl_pillole_errore('validation', 'taken_at', 'taken_at cannot be in the future');
    }
    $r = td_pill_segna($uid, $id, $st, $atD, $atT);
    if ($r === null) return pl_pillole_errore('not_found', 'id', 'Dose not found');
    return ['dose' => ['id' => $id, 'status' => $st, 'taken_at' => $r['taken_at']]];
}

/** «Prese tutte»: {ids, mode: on_time|now}. Ritorna n e le ore scritte (solo per le prese ancora da spuntare). */
function pl_pillole_tutte(int $uid, array $c): array {
    $ids = $c['ids'] ?? null;
    if (!is_array($ids) || !$ids || !array_is_list($ids)) return pl_pillole_errore('validation', 'ids', 'ids must be a non-empty list of dose ids');
    if (count($ids) > 200) return pl_pillole_errore('validation', 'ids', 'ids can have at most 200 entries');
    foreach ($ids as $i) if (!is_int($i) || $i < 1) return pl_pillole_errore('validation', 'ids', 'ids must be whole numbers');
    $mode = $c['mode'] ?? 'now';
    if (!is_string($mode) || !in_array($mode, ['on_time', 'now'], true)) return pl_pillole_errore('validation', 'mode', 'mode must be on_time or now');
    $r = td_pill_segna_tutte($uid, array_values(array_unique($ids)), $mode);
    return ['n' => $r['n'], 'taken' => (object)$r['taken']];
}

/** Il calendario: quante prese in un giorno passato o di oggi (0 = nessuna). */
function pl_pillole_calendario(int $uid, int $idTerapia, array $c): array {
    if (!pl_pillole_una($uid, $idTerapia)) return pl_pillole_errore('not_found', 'id', 'Therapy not found');
    $giorno = pl_pillole_data($c['date'] ?? null);
    if ($giorno === null) return pl_pillole_errore('validation', 'date', 'date must be YYYY-MM-DD');
    if ($giorno > date('Y-m-d')) return pl_pillole_errore('validation', 'date', 'date cannot be in the future');
    $n = $c['taken'] ?? null;
    if (!is_int($n) || $n < 0 || $n > 50) return pl_pillole_errore('validation', 'taken', 'taken must be a whole number from 0 to 50');
    return td_pill_giorno_imposta($uid, $idTerapia, $giorno, $n) ?? pl_pillole_errore('not_found', 'id', 'Therapy not found');
}

/** Come e' andata: un mese (month=AAAA-MM) oppure gli ultimi days giorni (7-180, 60 di partenza). */
function pl_pillole_storia(int $uid, int $idTerapia, ?string $mese, ?string $giorni): array {
    if (!pl_pillole_una($uid, $idTerapia)) return pl_pillole_errore('not_found', 'id', 'Therapy not found');
    if ($mese !== null && !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $mese)) return pl_pillole_errore('validation', 'month', 'month must be YYYY-MM');
    if ($giorni !== null && (!preg_match('/^[0-9]{1,3}$/', $giorni) || (int)$giorni < 7 || (int)$giorni > 180)) return pl_pillole_errore('validation', 'days', 'days must be a number from 7 to 180');
    return td_pill_storia($uid, $idTerapia, $mese ?? '', $giorni !== null ? (int)$giorni : 60) ?? pl_pillole_errore('not_found', 'id', 'Therapy not found');
}
