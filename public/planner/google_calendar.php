<?php
/* =====================================================================
   STANDOUT — GOOGLE CALENDAR
   Collegamento dell'account e sincronizzazione nei due sensi con
   planner_events. Un solo file, quattro ingressi:

     google_calendar.php?action=connect      → porta al consenso Google
     google_calendar.php?action=callback     → ritorno da Google
     google_calendar.php?ajax=status         → stato del collegamento
     google_calendar.php?ajax=sync   (POST)  → sincronizza adesso
     google_calendar.php?ajax=disconnect (POST)

   COSA SERVE IN config.php
   ------------------------
   define('GOOGLE_CLIENT_ID',     '....apps.googleusercontent.com');
   define('GOOGLE_CLIENT_SECRET', '....');
   define('GOOGLE_REDIRECT_URI',  'https://iltuosito.it/google_calendar.php?action=callback');
   define('GC_ENC_KEY',           'una-frase-lunga-e-segreta-solo-tua');

   I token non vengono mai salvati in chiaro: si cifrano con GC_ENC_KEY
   (AES-256-GCM). Se cambi quella chiave, gli utenti devono ricollegare
   l'account: è il comportamento giusto, non un errore.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
/* Le chiamate del planner vogliono JSON anche quando la sessione è scaduta;
   il collegamento con Google invece è una navigazione normale. */
if (isset($_GET['ajax'])) { require_once __DIR__ . '/../../lib/auth_check_api.php'; }
else                      { require_once __DIR__ . '/../../lib/auth_check.php'; }

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

define('GC_TZ', 'Europe/Rome');
define('GC_WINDOW_BACK', 30);    // giorni indietro da sincronizzare
define('GC_WINDOW_FWD', 180);    // giorni avanti
define('GC_PUSH_LIMIT', 120);    // eventi per singola sincronizzazione

function gc_configured(): bool {
    return defined('GOOGLE_CLIENT_ID') && defined('GOOGLE_CLIENT_SECRET')
        && defined('GOOGLE_REDIRECT_URI') && defined('GC_ENC_KEY')
        && GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

/* =====================================================================
   1. CIFRATURA DEI TOKEN
   ===================================================================== */
function gc_key(): string {
    return hash('sha256', (string)GC_ENC_KEY, true);   // 32 byte
}
function gc_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', gc_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return null;
    return base64_encode($iv . $tag . $cipher);
}
function gc_decrypt(?string $stored): ?string {
    if ($stored === null || $stored === '') return null;
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', gc_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/* =====================================================================
   2. CHIAMATE HTTP
   ===================================================================== */
/**
 * Ritorna ['code' => int, 'body' => array|null].
 * Non lancia eccezioni: chi chiama decide cosa fare del codice.
 */
function gc_http(string $method, string $url, ?array $payload = null, ?string $bearer = null, bool $form = false): array {
    $headers = ['Accept: application/json'];
    $body = null;
    if ($payload !== null) {
        if ($form) {
            $body = http_build_query($payload);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
    }
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            sec_log_error('google_http', $err . ' ' . $url);
            return ['code' => 0, 'body' => null];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($raw === false) return ['code' => 0, 'body' => null];
    }
    $decoded = json_decode((string)$raw, true);
    return ['code' => $code, 'body' => is_array($decoded) ? $decoded : null];
}

/* =====================================================================
   3. ACCOUNT E TOKEN
   ===================================================================== */
function gc_account(int $uid): ?array {
    return sec_one("SELECT * FROM google_calendar_accounts WHERE user_id = ?", [$uid], 'i');
}

/** Un access token valido, rinfrescandolo se è scaduto. null se scollegato. */
function gc_access_token(int $uid): ?string {
    $acc = gc_account($uid);
    if (!$acc || (int)$acc['is_enabled'] !== 1) return null;

    $expires = $acc['token_expires'] ? strtotime($acc['token_expires']) : 0;
    if ($expires > time() + 90) {
        $tok = gc_decrypt($acc['access_token']);
        if ($tok) return $tok;
    }
    $refresh = gc_decrypt($acc['refresh_token']);
    if (!$refresh) return null;

    $res = gc_http('POST', 'https://oauth2.googleapis.com/token', [
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refresh,
        'grant_type'    => 'refresh_token',
    ], null, true);

    if ($res['code'] !== 200 || empty($res['body']['access_token'])) {
        gc_log($uid, 'auth', 'error', 'rinnovo del token non riuscito (' . $res['code'] . ')');
        // se Google ha revocato il consenso, spegniamo il collegamento
        if ($res['code'] === 400 || $res['code'] === 401) {
            sec_q("UPDATE google_calendar_accounts SET is_enabled = 0 WHERE user_id = ?", [$uid], 'i');
        }
        return null;
    }
    $token = (string)$res['body']['access_token'];
    $ttl = (int)($res['body']['expires_in'] ?? 3600);
    sec_q("UPDATE google_calendar_accounts SET access_token = ?, token_expires = ? WHERE user_id = ?",
          [gc_encrypt($token), date('Y-m-d H:i:s', time() + $ttl), $uid], 'ssi');
    return $token;
}

define('GC_CAL_NAME', 'Planner — Standout');

/**
 * Il calendario dedicato al sito. Tenuto separato da quello personale così
 * l'utente può spegnerlo, cambiargli colore o cancellarlo dal suo Google
 * senza toccare il resto della sua agenda.
 * Ritorna l'id del calendario, o 'primary' se non si riesce a crearlo.
 */
function gc_ensure_calendar(int $uid, string $token, array $acc): string {
    $current = $acc['calendar_id'] ?? '';
    if ($current !== '' && $current !== 'primary') {
        // controlliamo che esista ancora: l'utente potrebbe averlo cancellato
        $res = gc_http('GET', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($current), null, $token);
        if ($res['code'] === 200) return $current;
        if ($res['code'] !== 404 && $res['code'] !== 410) return $current;   // problema di rete: non tocchiamo niente
        gc_log($uid, 'auth', 'ok', 'calendario cancellato da Google, lo ricreo');
    }

    // ce n'è già uno con questo nome? (per esempio dopo un ricollegamento)
    $list = gc_http('GET',
        'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&showHidden=true&showDeleted=false',
        null, $token);
    if ($list['code'] === 200 && !empty($list['body']['items'])) {
        foreach ($list['body']['items'] as $cal) {
            if (($cal['summary'] ?? '') === GC_CAL_NAME && !empty($cal['id'])) {
                sec_q("UPDATE google_calendar_accounts SET calendar_id = ?, sync_token = NULL WHERE user_id = ?",
                      [(string)$cal['id'], $uid], 'si');
                return (string)$cal['id'];
            }
        }
    }

    $made = gc_http('POST', 'https://www.googleapis.com/calendar/v3/calendars', [
        'summary'     => GC_CAL_NAME,
        'description' => t('planner.gc_descrizione_calendario'),
        'timeZone'    => GC_TZ,
    ], $token);

    if ($made['code'] >= 200 && $made['code'] < 300 && !empty($made['body']['id'])) {
        $id = (string)$made['body']['id'];
        /* colore tenue, in tono con il planner */
        gc_http('PATCH', 'https://www.googleapis.com/calendar/v3/users/me/calendarList/' . rawurlencode($id),
                ['backgroundColor' => '#A9C3AE', 'foregroundColor' => '#1D2B22', 'selected' => true], $token);
        sec_q("UPDATE google_calendar_accounts SET calendar_id = ?, sync_token = NULL WHERE user_id = ?", [$id, $uid], 'si');
        gc_log($uid, 'auth', 'ok', 'creato il calendario dedicato');
        return $id;
    }
    gc_log($uid, 'auth', 'error', 'calendario dedicato non creato (' . $made['code'] . ')');
    return 'primary';
}

/* =====================================================================
   IL PONTE FRA I CALENDARI DI GOOGLE E I MIEI

   Prima si leggeva un calendario solo — quello dedicato — e tutto finiva
   in un mucchio. Chi su Google tiene compleanni, salute e vacanze separati
   si ritrovava qui tutto mescolato, oppure niente.

   Ora ogni calendario trovato sull'account ha una riga sua in
   `google_calendar_map`, e quella riga dice dove vanno i suoi eventi:
   in uno dei miei calendari, oppure in nessuno. «Nessuno» non vuol dire
   «non importarli più»: vuol dire che di quel calendario qui dentro non
   deve restare niente, nemmeno quello che era già entrato.
   ===================================================================== */

/** L'elenco dei calendari Google conosciuti, con la destinazione scelta. */
function gc_map(int $uid): array {
    return sec_all("SELECT * FROM google_calendar_map WHERE user_id = ? ORDER BY is_primary DESC, google_name",
                   [$uid], 'i');
}

/** Va a vedere quali calendari ci sono adesso sull'account e aggiorna l'elenco.

    Le righe non si cancellano mai da sole, nemmeno quando un calendario
    sparisce da Google: la scelta fatta dall'utente è un dato suo, e se il
    calendario torna (capita: basta toglierlo e rimetterlo dall'app) deve
    ritrovare la sua destinazione invece di ripartire da zero. */
function gc_refresh_map(int $uid, string $token, ?string $dedicato = null): array {
    /* `showHidden=true` non è un dettaglio: senza, Google non manda i
       calendari che tratta come «di sistema» — compleanni e festività sono
       quasi sempre marcati nascosti anche quando nell'app si vedono
       benissimo, ed è per quello che nell'elenco non comparivano e non si
       poteva scegliere niente per loro. */
    $res = gc_http('GET',
        'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&showHidden=true&showDeleted=false',
        null, $token);
    if ($res['code'] !== 200 || empty($res['body']['items'])) return gc_map($uid);

    /* Dove far finire quello che arriva dal calendario dedicato: nel
       principale. È il calendario che scriviamo noi, quindi non c'è niente
       da chiedere — sono i nostri stessi eventi che tornano indietro. */
    $princ = sec_one("SELECT id FROM planner_calendars WHERE user_id = ? ORDER BY is_default DESC, id LIMIT 1",
                     [$uid], 'i');
    $princId = $princ ? (int)$princ['id'] : null;

    foreach ($res['body']['items'] as $cal) {
        $ref = (string)($cal['id'] ?? '');
        if ($ref === '') continue;
        $nome  = (string)($cal['summary'] ?? $ref);
        $col   = (string)($cal['backgroundColor'] ?? '');
        $primo = !empty($cal['primary']) ? 1 : 0;
        $suo   = ($dedicato !== null && $ref === $dedicato);
        /* Compleanni e festività si possono solo leggere: scriverci dentro
           Google lo rifiuta sempre. Si annota qui per non proporli come
           destinazione nel verso di ritorno — un menù che offre una scelta
           che fallirà comunque è peggio che non offrirla. */
        $ruolo = (string)($cal['accessRole'] ?? 'reader');
        $scriv = ($ruolo === 'owner' || $ruolo === 'writer') ? 1 : 0;

        /* Il nome e il colore si aggiornano sempre: sono di Google e cambiano
           quando li cambia lui. La destinazione no — quella l'ha scelta
           l'utente e non si tocca. */
        sec_q("INSERT INTO google_calendar_map
                   (user_id, google_ref, google_name, google_color, is_primary, can_write,
                    calendar_id, decided, last_seen_at)
               VALUES (?,?,?,?,?,?,?,?,NOW())
               ON DUPLICATE KEY UPDATE
                   google_name = VALUES(google_name),
                   google_color = VALUES(google_color),
                   is_primary = VALUES(is_primary),
                   can_write = VALUES(can_write),
                   last_seen_at = NOW()",
              [$uid, $ref, mb_substr($nome, 0, 160), mb_substr($col, 0, 20), $primo, $scriv,
               $suo ? $princId : null, $suo ? 1 : 0], 'isssiiii');
    }
    return gc_map($uid);
}

/** Toglie di qui tutto quello che è arrivato da un calendario Google.

    Serve quando la destinazione diventa «nessuno». Si cancella davvero e non
    si nasconde: la richiesta è che di quel calendario non resti niente, né
    di ieri né di domani. Solo gli eventi *nati* su Google, però — uno scritto
    a mano nel planner e poi finito là non è roba loro.
    Da Google non si cancella nulla: quegli eventi sono suoi e restano suoi. */
function gc_purge_source(int $uid, string $ref): int {
    sec_q("DELETE FROM planner_events
           WHERE user_id = ? AND google_origin = 1
             AND (google_source_ref = ? OR (google_source_ref IS NULL AND google_calendar_id = ?))",
          [$uid, $ref, $ref], 'iss');
    return sec_affected();
}

function gc_log(int $uid, string $direction, string $outcome = 'ok', ?string $detail = null, int $items = 0): void {
    @sec_q("INSERT INTO google_sync_log (user_id, direction, outcome, items, detail) VALUES (?,?,?,?,?)",
           [$uid, $direction, $outcome, $items, $detail !== null ? mb_substr($detail, 0, 255) : null], 'issis');
}

/* =====================================================================
   4. CONVERSIONI TRA I DUE MONDI
   ===================================================================== */
/** Un evento del planner nel formato che vuole Google. */
/** Un evento del planner nel formato che vuole Google. */
function gc_to_google(array $ev): array {
    $out = [
        'summary'     => $ev['title'] !== '' ? $ev['title'] : t('planner.gc_senza_titolo'),
        'description' => (string)$ev['notes'],
    ];
    if (!empty($ev['location'])) $out['location'] = (string)$ev['location'];
    if ((int)$ev['is_all_day'] === 1) {
        /* LA FINE SECONDO GOOGLE E' ESCLUSIVA: `end.date` e' il giorno in cui
           l'evento NON c'e' piu'. Un evento di un giorno solo va dal 10 all'11,
           uno dal 10 al 12 va dal 10 al 13. Scrivere l'ultimo giorno vero e'
           l'errore classico che fa comparire tutto un giorno piu' corto —
           motivo per cui qui si somma sempre uno all'ultimo giorno, non alla
           data d'inizio.
           COALESCE a mano perche' nel nostro database end_date nulla vuol dire
           "finisce dove comincia". */
        $ultimo = !empty($ev['end_date']) ? (string)$ev['end_date'] : (string)$ev['event_date'];
        if ($ultimo < (string)$ev['event_date']) $ultimo = (string)$ev['event_date'];
        $out['start'] = ['date' => $ev['event_date']];
        $out['end']   = ['date' => date('Y-m-d', strtotime($ultimo . ' +1 day'))];
    } else {
        /* Con un orario la fine puo' cadere su un altro giorno: dal 3 alle
           22:00 al 4 alle 02:00. Senza end_date si resta nella stessa
           giornata, come e' sempre stato. */
        $fineGiorno = !empty($ev['end_date']) && (string)$ev['end_date'] > (string)$ev['event_date']
            ? (string)$ev['end_date']
            : (string)$ev['event_date'];
        $out['start'] = ['dateTime' => $ev['event_date'] . 'T' . $ev['start_time'], 'timeZone' => GC_TZ];
        $out['end']   = ['dateTime' => $fineGiorno . 'T' . $ev['end_time'],         'timeZone' => GC_TZ];
    }
    if (!empty($ev['notify_type']) && $ev['notify_type'] !== 'none') {
        $out['reminders'] = [
            'useDefault' => false,
            'overrides'  => [['method' => 'popup', 'minutes' => max(0, (int)$ev['notify_minutes'])]],
        ];
    }
    
    /* La ripetizione, nel formato che Google riconosce come sua.
       Si parte dalla colonna `rrule`, che è la verità sulla serie: le colonne
       repeat_* restano per gli eventi vecchi e per l'interfaccia, ma ricavare
       la regola da lì vorrebbe dire tenere in piedi due traduzioni che prima
       o poi si allontanano. */
    $rrule = trim((string)($ev['rrule'] ?? ''));
    if ($rrule === '' && !empty($ev['repeat_type']) && $ev['repeat_type'] !== 'none') {
        $freqMap = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];
        $freq = $freqMap[$ev['repeat_type']] ?? null;
        $interval = 1;
        if ($ev['repeat_type'] === 'custom') {
            $customMap = ['day' => 'DAILY', 'week' => 'WEEKLY', 'month' => 'MONTHLY', 'year' => 'YEARLY'];
            $freq = $customMap[$ev['repeat_unit'] ?? 'week'] ?? 'WEEKLY';
            $interval = max(1, (int)($ev['repeat_interval'] ?? 1));
        }
        if ($freq) {
            $rrule = "FREQ=$freq";
            if ($interval > 1) $rrule .= ";INTERVAL=$interval";
        }
    }

    if ($rrule !== '') {
        $rrule = preg_replace('/^RRULE:/i', '', $rrule);
        /* L'UNTIL può stare già dentro la regola o solo nella colonna: si
           aggiunge solo se manca, se no si finisce con due limiti in una
           regola sola e Google la rifiuta.
           strtotime() su una data vuota o storta torna false, che date()
           legge come il 1970: la regola partirebbe già scaduta e Google
           cancellerebbe la ripetizione. Meglio nessun UNTIL che uno sbagliato. */
        if (stripos($rrule, 'UNTIL=') === false && stripos($rrule, 'COUNT=') === false) {
            $fineRip = !empty($ev['repeat_until']) ? strtotime((string)$ev['repeat_until']) : false;
            if ($fineRip !== false) $rrule .= ";UNTIL=" . date('Ymd\T235959\Z', $fineRip);
        }
        $out['recurrence'] = ['RRULE:' . $rrule];
    } else {
        /* Esplicito, non omesso: con una PATCH un campo che manca resta com'è,
           quindi togliendo la ripetizione qui l'evento su Google continuerebbe
           a ripetersi per sempre. */
        $out['recurrence'] = null;
    }

    return $out;
}

/**
 * La ripetizione di un evento di Google riportata alle colonne del planner.
 * Google manda un array `recurrence` che può contenere anche RDATE ed EXDATE:
 * qui interessa solo la RRULE, che è l'unica cosa che il planner sa generare.
 */
function gc_recurrence_in(array $g): array {
    $vuoto = ['rrule' => null, 'type' => 'none', 'interval' => 1, 'unit' => 'week', 'until' => null];
    $righe = $g['recurrence'] ?? null;
    if (!is_array($righe)) return $vuoto;

    $rrule = '';
    foreach ($righe as $r) {
        if (stripos((string)$r, 'RRULE:') === 0) { $rrule = substr((string)$r, 6); break; }
    }
    if ($rrule === '') return $vuoto;

    $freq = ''; $interval = 1; $until = null; $daily = false;
    foreach (explode(';', $rrule) as $pezzo) {
        if (!str_contains($pezzo, '=')) continue;
        [$k, $v] = explode('=', $pezzo, 2);
        switch (strtoupper(trim($k))) {
            case 'FREQ':     $freq = strtoupper(trim($v)); break;
            case 'INTERVAL': $interval = max(1, (int)$v); break;
            case 'UNTIL':
                $solo = substr(preg_replace('/[^0-9]/', '', $v), 0, 8);
                if (strlen($solo) === 8) $until = substr($solo, 0, 4) . '-' . substr($solo, 4, 2) . '-' . substr($solo, 6, 2);
                break;
        }
    }
    $unita = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'];
    if (!isset($unita[$freq])) return $vuoto;   // regole che non sappiamo generare: meglio non fingere

    /* Con intervallo 1 si usa il tipo semplice, che l'interfaccia sa mostrare
       come «ogni settimana»; sopra 1 serve «personalizzata», che è l'unica
       forma con un intervallo. */
    $semplice = ['day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'year' => 'yearly'];
    return [
        'rrule'    => $rrule,
        'type'     => $interval > 1 ? 'custom' : $semplice[$unita[$freq]],
        'interval' => $interval,
        'unit'     => $unita[$freq],
        'until'    => $until,
    ];
}

/** Un evento di Google riportato ai campi del planner. */
function gc_from_google(array $g): ?array {
    $start = $g['start'] ?? [];
    $end   = $g['end'] ?? [];
    if (!empty($start['date'])) {
        /* Il verso opposto della regola di sopra: `end.date` di Google e' il
           giorno in cui l'evento non c'e' piu', quindi l'ultimo giorno vero e'
           quello prima. Senza il meno uno ogni evento importato durerebbe un
           giorno di troppo, e uno di un giorno solo ne durerebbe due.
           end_date nullo quando comincia e finisce lo stesso giorno: e' il modo
           in cui il nostro database dice "un giorno solo". */
        $ultimo = null;
        if (!empty($end['date'])) {
            $ultimo = date('Y-m-d', strtotime($end['date'] . ' -1 day'));
            // difese contro un end malformato o uguale allo start
            if ($ultimo <= $start['date']) $ultimo = null;
        }
        return [
            'date'     => $start['date'],
            'end_date' => $ultimo,
            'start'    => '00:00:00',
            'end'      => '23:59:00',
            'all_day'  => 1,
            'title'    => (string)($g['summary'] ?? t('planner.gc_senza_titolo')),
            'notes'    => (string)($g['description'] ?? ''),
        ];
    }
    if (!empty($start['dateTime'])) {
        $s = strtotime($start['dateTime']);
        $e = !empty($end['dateTime']) ? strtotime($end['dateTime']) : $s + 1800;
        if ($s === false) return null;
        /* Gli eventi a cavallo della mezzanotte non si tagliano piu' al giorno
           d'inizio: adesso c'e' end_date e possono dire davvero fin dove
           arrivano. Il taglio resta solo come rete: oltre il limite di giorni
           un evento importato entrerebbe in tutte le caselle che attraversa. */
        $fine = date('Y-m-d', $e);
        $endDate = null;
        if ($fine !== date('Y-m-d', $s)) {
            $giorni = (int)floor(($e - strtotime(date('Y-m-d', $s))) / 86400) + 1;
            if ($giorni > 366) {
                $e = strtotime(date('Y-m-d', $s) . ' 23:59');
            } else {
                $endDate = $fine;
            }
        }
        return [
            'date'     => date('Y-m-d', $s),
            'end_date' => $endDate,
            'start'    => date('H:i:s', $s),
            'end'      => date('H:i:s', $e),
            'all_day'  => 0,
            'title'    => (string)($g['summary'] ?? t('planner.gc_senza_titolo')),
            'notes'    => (string)($g['description'] ?? ''),
        ];
    }
    return null;
}

/* =====================================================================
   5. SINCRONIZZAZIONE
   ===================================================================== */

/** Google → planner: un giro su ogni calendario che ha una destinazione.

    I calendari messi su «nessuno» non si leggono affatto — non è solo che i
    loro eventi non entrano: non si spende nemmeno una chiamata per andarli a
    prendere.
    Se l'elenco è vuoto (utente vecchio che non ha ancora scelto niente) si
    legge il calendario dedicato come si è sempre fatto, se no la sincronia
    smetterebbe di funzionare da sola il giorno dell'aggiornamento. */
function gc_pull(int $uid, string $token, array $acc): array {
    $mappa = gc_map($uid);
    $daLeggere = [];
    foreach ($mappa as $m) {
        if ($m['calendar_id'] === null) continue;
        $daLeggere[] = [
            'ref'   => (string)$m['google_ref'],
            'dest'  => (int)$m['calendar_id'],
            'token' => $m['sync_token'],
        ];
    }
    if (!$daLeggere) {
        // Controlla se l'utente ha fatto delle scelte. Se ha scelto "Nessuno" ovunque, rispettiamo la scelta.
        $ha_scelto = sec_one("SELECT 1 FROM google_calendar_map WHERE user_id = ? AND decided = 1 LIMIT 1", [$uid], 'i');
        if (!$ha_scelto) {
            $princ = sec_one("SELECT id FROM planner_calendars WHERE user_id = ? ORDER BY is_default DESC, id LIMIT 1",
                             [$uid], 'i');
            $daLeggere[] = [
                'ref'   => (string)($acc['calendar_id'] ?: 'primary'),
                'dest'  => $princ ? (int)$princ['id'] : null,
                'token' => $acc['sync_token'],
            ];
        }
    }

    $ok = true; $changed = 0; $removed = 0;
    foreach ($daLeggere as $c) {
        $r = gc_pull_one($uid, $token, $c['ref'], $c['token'], $c['dest']);
        if (!$r['ok']) $ok = false;
        $changed += $r['changed'];
        $removed += $r['removed'];
    }
    return ['ok' => $ok, 'changed' => $changed, 'removed' => $removed];
}

/** Un calendario solo. Usa il suo segnalibro quando c'è: scarica solo le novità. */
function gc_pull_one(int $uid, string $token, string $ref, ?string $syncToken, ?int $dest): array {
    $acc = ['calendar_id' => $ref, 'sync_token' => $syncToken];
    $calendar = rawurlencode($ref ?: 'primary');
    $base = 'https://www.googleapis.com/calendar/v3/calendars/' . $calendar . '/events';

    /* singleEvents = false: si chiedono i master con la loro regola, non le
       occorrenze già srotolate. Con `true` Google ne mandava una per data e
       qui diventavano righe vere — cioè esattamente la materializzazione da
       cui siamo usciti, rifatta dall'altra parte. Così invece i due sistemi
       hanno la stessa forma: un master con la RRULE, più le eccezioni.
       Nota: con singleEvents = false Google accetta solo orderBy = updated. */
    $params = ['singleEvents' => 'false', 'maxResults' => '250', 'showDeleted' => 'true'];
    if (!empty($acc['sync_token'])) {
        $params['syncToken'] = $acc['sync_token'];
    } else {
        $params['timeMin'] = date('c', strtotime('-' . GC_WINDOW_BACK . ' days'));
        $params['timeMax'] = date('c', strtotime('+' . GC_WINDOW_FWD . ' days'));
        $params['orderBy'] = 'updated';
    }

    $changed = 0; $removed = 0; $pageToken = null; $newSync = null; $guard = 0;
    do {
        $guard++;
        $q = $params;
        if ($pageToken) $q['pageToken'] = $pageToken;
        $res = gc_http('GET', $base . '?' . http_build_query($q), null, $token);

        // sync token scaduto: Google chiede di ricominciare da zero
        if ($res['code'] === 410) {
            sec_q("UPDATE google_calendar_map SET sync_token = NULL WHERE user_id = ? AND google_ref = ?",
                  [$uid, $ref], 'is');
            sec_q("UPDATE google_calendar_accounts SET sync_token = NULL WHERE user_id = ? AND calendar_id = ?",
                  [$uid, $ref], 'is');
            return gc_pull_one($uid, $token, $ref, null, $dest);
        }
        if ($res['code'] !== 200 || !is_array($res['body'])) {
            gc_log($uid, 'pull', 'error', 'risposta ' . $res['code']);
            return ['ok' => false, 'changed' => $changed, 'removed' => $removed];
        }

        foreach (($res['body']['items'] ?? []) as $g) {
            $gid = (string)($g['id'] ?? '');
            if ($gid === '') continue;

            $sorgenteRef = $ref !== '' ? $ref : 'primary';

            /* Un'eccezione di serie: Google la manda come voce a sé, con
               `recurringEventId` che punta al master e `originalStartTime`
               che dice quale occorrenza sostituisce. È la stessa forma che
               usiamo noi — series_id più original_date — quindi si traduce
               riga per riga invece di entrare come evento slegato. */
            $padreG = (string)($g['recurringEventId'] ?? '');
            $dataOrig = null;
            if ($padreG !== '') {
                $os = $g['originalStartTime'] ?? [];
                if (!empty($os['date'])) {
                    $dataOrig = (string)$os['date'];
                } elseif (!empty($os['dateTime'])) {
                    $ts = strtotime((string)$os['dateTime']);
                    if ($ts !== false) $dataOrig = date('Y-m-d', $ts);
                }
            }

            /* Google non cancella: marca. Un evento tolto — o una singola
               occorrenza tolta da una serie — torna nel flusso con
               status = 'cancelled', e spesso si porta ancora dietro il suo
               titolo. Senza questo controllo entravano qui come eventi veri:
               è così che sono comparsi 25 sabati "CANCELLED" alle 05:00 che
               su Google non si vedono, perché là sono nascosti. */
            if (($g['status'] ?? '') === 'cancelled') {
                if ($padreG !== '' && $dataOrig !== null) {
                    /* Occorrenza tolta da una serie che vive su Google: qui
                       diventa un marker, esattamente come quando si cancella
                       una sola data dal planner. Cancellare la riga e basta
                       non basterebbe — il master la rigenererebbe al primo
                       giro di lettura. */
                    $padre = sec_one("SELECT id FROM planner_events
                                      WHERE user_id = ? AND google_event_id = ? LIMIT 1",
                                     [$uid, $padreG], 'is');
                    if ($padre) {
                        $capo = (int)$padre['id'];
                        $gia = sec_one("SELECT id FROM planner_events
                                        WHERE user_id = ? AND series_id = ? AND original_date = ? LIMIT 1",
                                       [$uid, $capo, $dataOrig], 'iis');
                        if ($gia) {
                            sec_q("UPDATE planner_events SET is_cancelled = 1, google_synced_at = NOW(), updated_at = updated_at
                                   WHERE id = ? AND user_id = ?", [(int)$gia['id'], $uid], 'ii');
                        } else {
                            sec_q("INSERT INTO planner_events
                                   (user_id, title, event_date, start_time, end_time, series_id, original_date,
                                    is_cancelled, google_origin, google_source_ref, google_synced_at)
                                   VALUES (?, 'Cancellato', ?, '00:00:00', '00:00:00', ?, ?, 1, 1, ?, NOW())",
                                  [$uid, $dataOrig, $capo, $dataOrig, $sorgenteRef], 'isiss');
                        }
                        $removed++;
                    }
                    continue;
                }
                $via = sec_one("SELECT id FROM planner_events
                                WHERE user_id = ? AND google_event_id = ? AND google_origin = 1 LIMIT 1",
                               [$uid, $gid], 'is');
                if ($via) {
                    sec_q("DELETE FROM planner_events WHERE id = ? AND user_id = ?",
                          [(int)$via['id'], $uid], 'ii');
                    $removed++;
                }
                continue;
            }

            /* Un'eccezione di una serie che è NOSTRA si salta: quella serie
               qui esiste già come master, e le sue eccezioni le decide il
               planner. Quello che arriva da Google sarebbe solo l'eco di
               quanto gli abbiamo appena mandato. */
            if ($padreG !== '') {
                static $our_series = [];
                if (!isset($our_series[$padreG])) {
                    $is_ours = sec_one("SELECT id FROM planner_events WHERE google_event_id = ? AND user_id = ? AND google_origin = 0 LIMIT 1", [$padreG, $uid], 'si');
                    $our_series[$padreG] = $is_ours ? true : false;
                }
                if ($our_series[$padreG]) {
                    continue;
                }
            }
            $data = gc_from_google($g);
            if (!$data) continue;

            /* La regola di ripetizione, tradotta nelle colonne del planner.
               Da qui in poi un evento ripetuto di Google è indistinguibile da
               uno nato qui: una riga sola con la sua RRULE, e le occorrenze
               le calcola pl_events_range in lettura. */
            $rip = gc_recurrence_in($g);

            /* Un'eccezione va agganciata al master già presente qui. Se il
               master non c'è ancora — Google può mandarli in qualsiasi
               ordine — l'eccezione si salta: tornerà al giro dopo, quando il
               padre esiste, invece di entrare slegata e comparire due volte. */
            $serieId = null;
            if ($padreG !== '') {
                if ($dataOrig === null) continue;
                $padre = sec_one("SELECT id FROM planner_events
                                  WHERE user_id = ? AND google_event_id = ? LIMIT 1",
                                 [$uid, $padreG], 'is');
                if (!$padre) continue;
                $serieId = (int)$padre['id'];
                /* l'eccezione non si ripete a sua volta: sostituisce una data */
                $rip = ['rrule' => null, 'type' => 'none', 'interval' => 1, 'unit' => 'week', 'until' => null];
            }

            /* Il calendario da cui questo evento arriva davvero. Non annotarlo
               era la causa dei doppioni: senza, più avanti gc_push non sapeva
               dove l'evento vive e finiva per ricrearlo altrove. */
            $sorgente = $ref !== '' ? $ref : 'primary';

            /* Un evento appena toccato qui non va sovrascritto con la versione
               di Google: la lettura avviene PRIMA dell'invio, quindi
               riporterebbe indietro lo spostamento o il ritardo appena fatti,
               e dopo qualche secondo l'evento tornerebbe dov'era.
               Se è "sporco" lo saltiamo: lo manderemo noi subito dopo. */
            $existing = sec_one(
                "SELECT id, (google_synced_at IS NULL
                             OR updated_at > google_synced_at) AS dirty
                 FROM planner_events WHERE user_id = ? AND google_event_id = ? LIMIT 1",
                [$uid, $gid], 'is');
            if ($existing && (int)$existing['dirty'] === 1) {
                continue;   // vince la modifica fatta qui, non quella di Google
            }

            /* Il gemello: lo stesso evento arrivato da un altro calendario.

               Su Google lo stesso appuntamento può stare in due calendari, e
               in ciascuno ha un identificativo diverso: il confronto per
               identificativo lì sopra non se ne accorge, e nel planner
               comparivano due righe uguali una sull'altra. Succede di sicuro
               con i compleanni, che Google mette sia nel calendario dedicato
               sia nel principale.

               Due eventi importati con lo stesso titolo, lo stesso giorno e
               la stessa ora sono, in pratica, la stessa cosa vista due volte:
               si aggancia il secondo al primo invece di crearne un altro.
               Vale anche per gli eventi scritti a mano qui, ma solo se sono
               rimasti senza identificativo Google: è il caso di chi si è
               scollegato e ricollegato, dove la copia su Google c'è ancora e
               tornerebbe indietro come se fosse un'altra cosa. Un evento
               scritto qui e già appaiato al suo su Google non si tocca.

               Il rischio è di fondere due appuntamenti davvero distinti ma
               identici in tutto — capita, ma è molto più raro del doppione, e
               fra i due errori quello che si nota, e che dà fastidio ogni
               giorno, è il doppione. */
            if (!$existing && $padreG === '') {
                /* Un'eccezione non passa di qui: sappiamo già a quale serie
                   appartiene, e cercarle un gemello per titolo e orario la
                   farebbe fondere con l'occorrenza che deve sostituire. */
                $gemello = null;

                /* I master ripetuti si riconoscono per ricorrenza, non per
                   data d'inizio.

                   È il caso dei compleanni. Lo stesso compleanno arriva due
                   volte — dal calendario Birthdays e dalla copia che era
                   finita nel dedicato — ma i due master partono da anni
                   diversi: quello di Google dal primo compleanno, la nostra
                   copia dall'anno in cui fu creata. Confrontando la data
                   piena non si somigliano affatto, e ne entravano due.
                   Quello che coincide è il giorno dell'anniversario, non
                   l'anno: si confronta quello. */
                if ($rip['rrule'] !== null) {
                    $gemello = sec_one(
                        "SELECT id FROM planner_events
                          WHERE user_id = ? AND title = ? AND is_all_day = ?
                            AND rrule IS NOT NULL
                            AND DATE_FORMAT(event_date, '%m-%d') = ?
                            AND (google_event_id IS NULL OR google_event_id <> ?)
                          LIMIT 1",
                        [$uid, $data['title'], $data['all_day'],
                         date('m-d', strtotime($data['date'])), $gid], 'isiss');
                }
                if (!$gemello) {
                $gemello = sec_one(
                    "SELECT id FROM planner_events
                     WHERE user_id = ? AND event_date = ?
                       AND title = ? AND is_all_day = ?
                       AND (start_time <=> ?)
                       AND (google_event_id IS NULL OR google_event_id <> ?)
                     LIMIT 1",
                    [$uid, $data['date'], $data['title'], $data['all_day'], $data['start'], $gid],
                    /* Tolto il blocco "google_origin = 1" per permettere ai task e abitudini nativi
                       con vecchi ID di essere riagganciati correttamente ai nuovi eventi Google */
                    'ississ');
                }
                if ($gemello) {
                    /* si prende in carico la riga che c'è già: da qui in poi
                       quell'evento risponde a questo calendario, e la prossima
                       volta lo ritrova per identificativo senza passare di qui */
                    sec_q("UPDATE planner_events
                           SET google_event_id = ?, google_calendar_id = ?, google_source_ref = ?,
                               google_synced_at = NOW(), updated_at = updated_at
                           WHERE id = ? AND user_id = ?",
                          [$gid, $sorgente, $sorgente, (int)$gemello['id'], $uid], 'sssii');
                    /* E poi si scrive anche il contenuto, invece di fermarsi
                       qui. Fermandosi, la riga restava con i dati vecchi — in
                       particolare senza la regola di ripetizione — ed è così
                       che nascevano i compleanni «uno giusto e uno no». */
                    $existing = ['id' => (int)$gemello['id'], 'dirty' => 0];
                }
            }
            if ($existing) {
                /* Il calendario di destinazione non si riscrive a ogni giro:
                   se l'utente ha spostato a mano quell'evento in un altro dei
                   suoi calendari, rimetterlo qui glielo riporterebbe indietro
                   a ogni sincronia. Si scrive solo la provenienza, che invece
                   è un fatto e non una scelta. */
                sec_q("UPDATE planner_events SET title = ?, notes = ?, event_date = ?, end_date = ?, start_time = ?, end_time = ?,
                              is_all_day = ?, google_calendar_id = ?, google_source_ref = ?,
                              rrule = ?, repeat_type = ?, repeat_interval = ?, repeat_unit = ?, repeat_until = ?,
                              series_id = ?, original_date = ?, is_cancelled = 0,
                              google_synced_at = NOW(), updated_at = updated_at
                       WHERE id = ? AND user_id = ?",
                      [$data['title'], $data['notes'], $data['date'], $data['end_date'] ?? null, $data['start'], $data['end'],
                       $data['all_day'], $sorgente, $sorgente,
                       $rip['rrule'], $rip['type'], $rip['interval'], $rip['unit'], $rip['until'],
                       $serieId, $dataOrig, (int)$existing['id'], $uid]);
                /* Un master deve essere capo della propria serie: senza
                   series_id le sue eccezioni non hanno a cosa agganciarsi. */
                if ($rip['rrule'] !== null && $serieId === null) {
                    sec_q("UPDATE planner_events SET series_id = id, updated_at = updated_at
                           WHERE id = ? AND user_id = ?", [(int)$existing['id'], $uid], 'ii');
                }
            } else {
                sec_q("INSERT INTO planner_events
                       (user_id, title, notes, event_date, end_date, start_time, end_time, is_all_day,
                        notify_type, notify_minutes, google_event_id, google_calendar_id,
                        google_source_ref, calendar_id, google_synced_at, google_origin,
                        rrule, repeat_type, repeat_interval, repeat_unit, repeat_until,
                        series_id, original_date)
                       VALUES (?,?,?,?,?,?,?,?,'none',0,?,?,?,?,NOW(),1,?,?,?,?,?,?,?)",
                      [$uid, $data['title'], $data['notes'], $data['date'], $data['end_date'] ?? null, $data['start'], $data['end'],
                       $data['all_day'], $gid, $sorgente, $sorgente, $dest,
                       $rip['rrule'], $rip['type'], $rip['interval'], $rip['unit'], $rip['until'],
                       $serieId, $dataOrig]);
                $nuovoId = sec_insert_id();
                if ($rip['rrule'] !== null && $serieId === null) {
                    sec_q("UPDATE planner_events SET series_id = ? WHERE id = ? AND user_id = ?",
                          [$nuovoId, $nuovoId, $uid], 'iii');
                }
            }
            $changed++;
        }
        $pageToken = $res['body']['nextPageToken'] ?? null;
        if (!empty($res['body']['nextSyncToken'])) $newSync = $res['body']['nextSyncToken'];
    } while ($pageToken && $guard < 10);

    if ($newSync) {
        sec_q("UPDATE google_calendar_map SET sync_token = ? WHERE user_id = ? AND google_ref = ?",
              [$newSync, $uid, $ref], 'sis');
        /* il segnalibro vecchio, quello sull'account, resta aggiornato per il
           calendario dedicato: serve a chi non ha ancora scelto niente */
        sec_q("UPDATE google_calendar_accounts SET sync_token = ? WHERE user_id = ? AND calendar_id = ?",
              [$newSync, $uid, $ref], 'sis');
    }
    gc_log($uid, 'pull', 'ok', null, $changed + $removed);
    return ['ok' => true, 'changed' => $changed, 'removed' => $removed];
}

/**
 * In quale calendario deve finire questo evento: quello dedicato, quello
 * principale di Google, o nessuno.
 * Il principale ha la precedenza: chi sceglie di mandare gli eventi importanti
 * là, li vuole là e non nel dedicato.
 * Ritorna l'id del calendario, oppure null se l'evento non va sincronizzato.
 */
function gc_target_for(array $ev, array $modes, string $dedicatedId, array $push = []): ?string {
    /* REGOLA PRIMA DI TUTTE LE ALTRE.
       Un evento nato su Google resta dov'è nato, sempre. È già là: mandarlo
       in un altro calendario non lo sposta, ne crea una copia — ed è
       esattamente così che sono nati i doppioni. */
    if ((int)($ev['google_origin'] ?? 0) === 1) {
        $suo = $ev['google_calendar_id'] ?? null;
        return $suo !== null && $suo !== '' ? (string)$suo : null;
    }

    /* «Importanti» e «importantissimi» si scelgono come fossero calendari a
       sé, e battono il calendario di appartenenza: dire «gli importantissimi
       vanno nel principale di Google» è una regola più mirata di «il
       calendario Lavoro va là», e chi la imposta la vuole valida anche per
       gli importantissimi che stanno in Lavoro. */
    $imp = (string)($ev['importance'] ?? 'normal');
    if ($imp === 'highest' && !empty($push['livello_highest'])) return (string)$push['livello_highest'];
    if (($imp === 'high' || $imp === 'highest') && !empty($push['livello_high'])) return (string)$push['livello_high'];

    /* Poi il calendario di questo evento, se ne ha uno suo su Google. */
    $suoCal = $ev['calendar_id'] ?? null;
    if ($suoCal !== null && !empty($push[(int)$suoCal])) {
        return (string)$push[(int)$suoCal];
    }

    /* Niente più «tutti nel dedicato» come comportamento di partenza: da
       quando ogni calendario ha la sua destinazione, un evento non scelto
       non deve finire su Google di nascosto. */
    return null;
}

/** Le scelte dell'utente, lette dalle impostazioni del planner. */
function gc_modes(int $uid): array {
    $row = sec_one("SELECT gc_dedicated_mode, gc_primary_mode FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    return [
        'dedicated' => $row['gc_dedicated_mode'] ?? 'all',
        'primary'   => $row['gc_primary_mode'] ?? 'none',
    ];
}

/**
 * Dove finiscono su Google gli eventi importanti e importantissimi.
 * Stringa vuota = non ci vanno. Prima erano due tendine con «tutti / solo
 * importanti / nessuno» che decidevano solo *se* mandare, non dove: ora sono
 * destinazioni vere, scelte nello stesso elenco degli altri calendari.
 */
function gc_push_livelli(int $uid): array {
    $row = sec_one("SELECT gc_push_high, gc_push_highest FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    return [
        'high'    => (string)($row['gc_push_high'] ?? ''),
        'highest' => (string)($row['gc_push_highest'] ?? ''),
    ];
}

/** planner → Google. Manda solo quello che è cambiato da qui. */
/** C'è già, in quel calendario, un evento uguale a questo?

    È la rete di sicurezza che mancava. Fin qui, quando la riga qui non aveva
    un identificativo Google, si creava un evento nuovo senza domandarsi
    niente — e se una copia là c'era già (perché l'identificativo si era perso
    per strada, come succedeva scollegando e ricollegando l'account) il
    risultato era un doppione. Ripetuto a ogni cambio di calendario o di
    impostazione, i doppioni diventano tre, quattro.

    Costa una chiamata in più, ma solo quando si sta per creare qualcosa, non
    a ogni giro: è il momento in cui vale la pena essere lenti e sicuri
    invece che veloci e sbagliati.

    Si cerca per finestra di orario e si confronta il titolo: `q` da solo non
    basta, perché fa la ricerca anche dentro le note e restituirebbe cose che
    c'entrano poco. */
function gc_find_twin(string $token, string $cal, array $ev): ?string {
    $giorno = (string)$ev['event_date'];
    if ($giorno === '') return null;
    $da = $giorno . 'T00:00:00Z';
    $a  = date('Y-m-d', strtotime($giorno . ' +1 day')) . 'T23:59:59Z';

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?'
         . http_build_query([
             'timeMin' => $da, 'timeMax' => $a,
             'singleEvents' => 'true', 'maxResults' => 50,
         ]);
    $res = gc_http('GET', $url, null, $token);
    if ($res['code'] !== 200 || empty($res['body']['items'])) return null;

    $titolo = trim((string)($ev['title'] ?? ''));
    $oraNostra = (int)$ev['is_all_day'] === 1
        ? $giorno
        : $giorno . 'T' . substr((string)$ev['start_time'], 0, 5);

    foreach ($res['body']['items'] as $it) {
        if (trim((string)($it['summary'] ?? '')) !== $titolo) continue;
        $suo = $it['start']['date'] ?? ($it['start']['dateTime'] ?? '');
        if ($suo === '') continue;
        /* si confrontano giorno e ora e minuti, non la stringa intera: quella
           porta dietro il fuso orario, che è scritto in modi diversi */
        $suoTaglio = strlen($suo) > 10 ? substr($suo, 0, 16) : substr($suo, 0, 10);
        if ($suoTaglio === $oraNostra && !empty($it['id'])) return (string)$it['id'];
    }
    return null;
}

/**
 * L'identificativo che Google dà a una singola occorrenza di una serie.
 *
 * Si potrebbe costruire a mano — è {master}_{data d'origine in UTC} — ma la
 * conversione del fuso è proprio il punto in cui si sbaglia: un'ora di
 * differenza e la PATCH cade su un'occorrenza che non c'entra, o su nessuna.
 * Meglio chiederlo a Google indicandogli quale data ci interessa.
 */
function gc_instance_id(string $token, string $cal, string $masterGid, string $dataOrig, bool $allDay): ?string {
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal)
         . '/events/' . rawurlencode($masterGid) . '/instances?'
         . http_build_query([
             'timeMin' => $dataOrig . 'T00:00:00Z',
             'timeMax' => date('Y-m-d', strtotime($dataOrig . ' +2 days')) . 'T00:00:00Z',
             'maxResults' => 10, 'showDeleted' => 'true',
         ]);
    $res = gc_http('GET', $url, null, $token);
    if ($res['code'] !== 200 || empty($res['body']['items'])) return null;

    foreach ($res['body']['items'] as $it) {
        $os = $it['originalStartTime'] ?? ($it['start'] ?? []);
        $suo = null;
        if (!empty($os['date'])) $suo = (string)$os['date'];
        elseif (!empty($os['dateTime'])) {
            $ts = strtotime((string)$os['dateTime']);
            if ($ts !== false) $suo = date('Y-m-d', $ts);
        }
        if ($suo === $dataOrig && !empty($it['id'])) return (string)$it['id'];
    }
    return null;
}

/**
 * Le eccezioni fatte qui, portate su Google.
 *
 * Fin qui viaggiavano in un verso solo: cancellando o spostando una singola
 * data nel planner, su Google la serie restava intatta e al giro dopo la
 * lettura rimetteva dentro l'occorrenza. Una serie con un'eccezione è, per
 * Google, il master più una PATCH sulla singola istanza: `status: cancelled`
 * per toglierla, oppure start ed end nuovi per spostarla.
 */
function gc_push_eccezioni(int $uid, string $token, string $from, string $to): array {
    $fatte = 0; $falliti = 0;

    /* Si guarda la data d'origine, non quella nuova: un'occorrenza spostata
       fuori dal periodo va comunque tolta dal giorno in cui stava. */
    $righe = sec_all(
        "SELECT e.*, m.google_event_id AS master_gid, m.google_calendar_id AS master_cal
           FROM planner_events e
           JOIN planner_events m ON m.id = e.series_id AND m.user_id = e.user_id
          WHERE e.user_id = ? AND e.original_date BETWEEN ? AND ?
            AND e.series_id IS NOT NULL AND e.series_id <> e.id
            AND (e.google_synced_at IS NULL OR e.updated_at > e.google_synced_at)
            AND m.google_event_id IS NOT NULL
          ORDER BY e.original_date LIMIT " . GC_PUSH_LIMIT,
        [$uid, $from, $to], 'iss'
    );

    foreach ($righe as $ecc) {
        $cal = (string)($ecc['master_cal'] ?? '');
        if ($cal === '') continue;   // il master non è ancora su Google: al prossimo giro
        $istanza = gc_instance_id($token, $cal, (string)$ecc['master_gid'],
                                  (string)$ecc['original_date'], (int)$ecc['is_all_day'] === 1);
        if ($istanza === null) { $falliti++; continue; }

        if ((int)$ecc['is_cancelled'] === 1) {
            $corpo = ['status' => 'cancelled'];
        } else {
            $corpo = gc_to_google($ecc);
            /* Un'istanza non porta una regola sua: la ripetizione è del
               master, e mandare qui `recurrence` la farebbe rifiutare. */
            unset($corpo['recurrence']);
        }

        $res = gc_http('PATCH',
            'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal)
            . '/events/' . rawurlencode($istanza), $corpo, $token);

        if ($res['code'] >= 200 && $res['code'] < 300) {
            sec_q("UPDATE planner_events
                   SET google_event_id = ?, google_calendar_id = ?, google_synced_at = NOW(), updated_at = updated_at
                   WHERE id = ? AND user_id = ?",
                  [$istanza, $cal, (int)$ecc['id'], $uid], 'ssii');
            $fatte++;
        } else {
            $falliti++;
            gc_log($uid, 'push', 'error', 'eccezione ' . $ecc['original_date'] . ': ' . $res['code']);
        }
    }
    return ['fatte' => $fatte, 'falliti' => $falliti];
}

function gc_push(int $uid, string $token, array $acc): array {
    $dedicated = $acc['calendar_id'] ?: 'primary';
    $modes = gc_modes($uid);
    /* dove va su Google ciascuno dei miei calendari: si legge una volta sola
       e si passa in giro, invece di chiederlo per ogni evento */
    $push = [];
    foreach (sec_all("SELECT id, google_push_ref FROM planner_calendars WHERE user_id = ? AND google_push_ref IS NOT NULL",
                     [$uid], 'i') as $c) {
        $push[(int)$c['id']] = (string)$c['google_push_ref'];
    }
    /* I due «calendari» che non sono calendari: le destinazioni scelte per
       importanza. Stanno nella stessa mappa con chiavi che non possono
       scontrarsi con un id, così gc_target_for legge un posto solo. */
    foreach (gc_push_livelli($uid) as $liv => $ref) {
        if ($ref !== '') $push['livello_' . $liv] = $ref;
    }
    $from = date('Y-m-d', strtotime('-' . GC_WINDOW_BACK . ' days'));
    $to   = date('Y-m-d', strtotime('+' . GC_WINDOW_FWD . ' days'));

    /* "Da mandare" vuol dire toccato qui dopo l'ultimo allineamento.
       Il confronto è stretto perché le scritture di servizio (quelle che
       segnano google_synced_at) rimettono updated_at al valore che aveva:
       prima lo facevano muovere anche loro e serviva una tolleranza di due
       secondi, che però apriva una finestra in cui una modifica appena
       fatta non risultava "da mandare" — e siccome la lettura da Google
       viene prima dell'invio, quella modifica veniva sovrascritta e
       l'evento tornava dov'era dopo qualche secondo. */
    $rows = sec_all(
        "SELECT * FROM planner_events
         WHERE user_id = ? AND event_date BETWEEN ? AND ?
           AND (google_synced_at IS NULL OR updated_at > google_synced_at)
           AND (series_id IS NULL OR series_id = id)
         ORDER BY event_date LIMIT " . GC_PUSH_LIMIT,
        [$uid, $from, $to], 'iss'
    );

    $created = 0; $updated = 0; $failed = 0; $removed = 0;
    foreach ($rows as $ev) {
        $target = gc_target_for($ev, $modes, $dedicated, $push);
        $oldCal = $ev['google_calendar_id'] ?? null;
        $oldId  = $ev['google_event_id'] ?? null;

        /* Non deve più stare su Google: se c'era, lo togliamo. Succede quando
           si abbassa l'importanza o si cambiano le scelte nelle impostazioni. */
        if ($target === null) {
            /* Eccezione importante: un evento IMPORTATO da Google non si
               cancella mai da Google. Qui ci finiscono le righe vecchie, quelle
               importate prima che si annotasse il calendario di provenienza:
               senza questa guardia il primo giro dopo l'aggiornamento
               cancellerebbe dal calendario dell'utente gli eventi veri.
               Si segnano come viste e si lasciano stare. */
            if ((int)($ev['google_origin'] ?? 0) === 1) {
                sec_q("UPDATE planner_events SET google_synced_at = NOW(), updated_at = updated_at WHERE id = ? AND user_id = ?",
                      [(int)$ev['id'], $uid], 'ii');
                continue;
            }
            if (!empty($oldId)) {
                gc_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/'
                        . rawurlencode($oldCal ?: $dedicated) . '/events/' . rawurlencode($oldId), null, $token);
                sec_q("UPDATE planner_events SET google_event_id = NULL, google_calendar_id = NULL,
                              google_synced_at = NOW(), updated_at = updated_at WHERE id = ? AND user_id = ?", [(int)$ev['id'], $uid], 'ii');
                $removed++;
            } else {
                /* niente da fare, ma lo segniamo come visto per non riesaminarlo a ogni giro */
                sec_q("UPDATE planner_events SET google_synced_at = NOW(), updated_at = updated_at WHERE id = ? AND user_id = ?", [(int)$ev['id'], $uid], 'ii');
            }
            continue;
        }

        /* Ha cambiato calendario: si cancella da quello vecchio e si ricrea
           nel nuovo. Google non permette di spostare un evento fra calendari
           mantenendo lo stesso identificativo in modo affidabile.
           Vale solo per gli eventi nati qui: quelli nati su Google non
           cambiano mai calendario (vedi gc_target_for). */
        if (!empty($oldId) && (int)($ev['google_origin'] ?? 0) === 0
            && $oldCal !== null && $oldCal !== '' && $oldCal !== $target) {
            gc_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/'
                    . rawurlencode($oldCal) . '/events/' . rawurlencode($oldId), null, $token);
            $oldId = null;
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($target) . '/events';
        $payload = gc_to_google($ev);

        /* Si sta per crearne uno nuovo: prima si controlla che non ci sia
           già. È qui che si fermano i doppioni, qualunque sia il motivo per
           cui l'identificativo si è perso. */
        if (empty($oldId)) {
            $gemello = gc_find_twin($token, $target, $ev);
            if ($gemello !== null) {
                $oldId = $gemello;
                sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ?,
                              updated_at = updated_at WHERE id = ? AND user_id = ?",
                      [$gemello, $target, (int)$ev['id'], $uid], 'ssii');
                gc_log($uid, 'push', 'ok', 'ritrovato su Google invece di ricrearlo: id ' . (int)$ev['id']);
            }
        }

        if (!empty($oldId)) {
            $res = gc_http('PATCH', $base . '/' . rawurlencode($oldId), $payload, $token);
            if ($res['code'] === 404 || $res['code'] === 410) {
                /* Non c'è più a quell'indirizzo. Prima si ricreava senza
                   pensarci, e se l'evento in realtà viveva in un ALTRO
                   calendario (caso normale per gli eventi importati) la
                   "ricreazione" era un doppione bello e buono.
                   Ora si ricrea solo se l'evento è nato qui; altrimenti si
                   lascia stare e si annota, così il caso si vede nel log
                   invece di moltiplicarsi in silenzio. */
                if ((int)($ev['google_origin'] ?? 0) === 1) {
                    sec_q("UPDATE planner_events SET google_synced_at = NOW(), updated_at = updated_at WHERE id = ? AND user_id = ?",
                          [(int)$ev['id'], $uid], 'ii');
                    gc_log($uid, 'push', 'error', 'evento importato non più su Google: id ' . (int)$ev['id']);
                    $failed++;
                    continue;
                }
                $oldId = null;   // nato qui: si può ricreare senza rischio di doppioni
            }
            elseif ($res['code'] >= 200 && $res['code'] < 300) {
                sec_q("UPDATE planner_events SET google_calendar_id = ?, google_synced_at = NOW(), updated_at = updated_at
                       WHERE id = ? AND user_id = ?", [$target, (int)$ev['id'], $uid], 'sii');
                $updated++;
                continue;
            } else { $failed++; continue; }
        }

        $res = gc_http('POST', $base, $payload, $token);
        if ($res['code'] >= 200 && $res['code'] < 300 && !empty($res['body']['id'])) {
            sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ?, google_synced_at = NOW(), updated_at = updated_at
                   WHERE id = ? AND user_id = ?", [(string)$res['body']['id'], $target, (int)$ev['id'], $uid], 'ssii');
            $created++;
        } else { $failed++; }
    }
    gc_log($uid, 'push', $failed > 0 ? 'error' : 'ok', $failed > 0 ? $failed . ' non riusciti' : null, $created + $updated);
    /* Le eccezioni vanno per ultime: hanno bisogno che il master sia già su
       Google, e il giro qui sopra è proprio quello che ce lo manda. */
    $ecc = gc_push_eccezioni($uid, $token, $from, $to);
    $updated += $ecc['fatte'];
    $failed  += $ecc['falliti'];

    return ['created' => $created, 'updated' => $updated, 'failed' => $failed, 'removed_far' => $removed];
}

/** Cancellazioni fatte qui, da riportare là. */
function gc_flush_deletions(int $uid, string $token, array $acc): int {
    $fallback = $acc['calendar_id'] ?: 'primary';
    /* Si cancella dal calendario in cui l'evento viveva davvero. Prima si
       cancellava sempre da quello dedicato: per un evento che stava altrove
       Google rispondeva 404, il codice lo prendeva per "fatto" e l'evento
       restava su Google per sempre. */
    $rows = sec_all("SELECT id, google_event_id, google_calendar_id FROM google_sync_queue
                     WHERE user_id = ? AND action = 'delete' AND attempts < 3 ORDER BY id LIMIT 60", [$uid], 'i');
    $done = 0;
    foreach ($rows as $r) {
        $cal = !empty($r['google_calendar_id']) ? $r['google_calendar_id'] : $fallback;
        $res = gc_http('DELETE',
            'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal)
            . '/events/' . rawurlencode($r['google_event_id']),
            null, $token);
        // 410 e 404 vanno bene: là non c'è più comunque
        if (($res['code'] >= 200 && $res['code'] < 300) || $res['code'] === 404 || $res['code'] === 410) {
            sec_q("DELETE FROM google_sync_queue WHERE id = ? AND user_id = ?", [(int)$r['id'], $uid], 'ii');
            $done++;
        } else {
            sec_q("UPDATE google_sync_queue SET attempts = attempts + 1 WHERE id = ? AND user_id = ?", [(int)$r['id'], $uid], 'ii');
        }
    }
    if ($done) gc_log($uid, 'delete', 'ok', null, $done);
    return $done;
}

/* =====================================================================
   6. INGRESSI
   ===================================================================== */
$action = isset($_GET['action']) ? sec_str($_GET['action'], 20) : '';
$ajax   = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';

/* ---------- collegamento: mandiamo l'utente al consenso Google ---------- */
if ($action === 'connect') {
    if (!gc_configured()) {
        http_response_code(500);
        exit(t('planner.gc_non_configurato'));
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['gc_state'] = $state;

    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        /* "calendar" e non solo "calendar.events": senza il permesso completo
           Google non lascia creare un calendario nuovo, e gli eventi
           finirebbero mescolati a quelli personali. */
        'scope'         => 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'include_granted_scopes' => 'true',
        'state'         => $state,
    ]);
    header('Location: ' . $url);
    exit;
}

/* ---------- ritorno da Google ---------- */
if ($action === 'callback') {
    $back = 'planner.php';
    $state = sec_str($_GET['state'] ?? '', 64);
    if (empty($_SESSION['gc_state']) || !hash_equals($_SESSION['gc_state'], $state)) {
        gc_log($uid, 'auth', 'error', 'state non corrispondente');
        header('Location: ' . $back . '?google=errore');
        exit;
    }
    unset($_SESSION['gc_state']);

    if (!empty($_GET['error'])) {
        header('Location: ' . $back . '?google=annullato');
        exit;
    }
    $code = sec_str($_GET['code'] ?? '', 512);
    if ($code === '') { header('Location: ' . $back . '?google=errore'); exit; }

    $res = gc_http('POST', 'https://oauth2.googleapis.com/token', [
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
    ], null, true);

    if ($res['code'] !== 200 || empty($res['body']['access_token'])) {
        gc_log($uid, 'auth', 'error', 'scambio del codice non riuscito (' . $res['code'] . ')');
        header('Location: ' . $back . '?google=errore');
        exit;
    }
    $access  = (string)$res['body']['access_token'];
    $refresh = $res['body']['refresh_token'] ?? null;
    $ttl     = (int)($res['body']['expires_in'] ?? 3600);

    // l'indirizzo serve solo per mostrare quale account è collegato
    $email = null;
    $me = gc_http('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', null, $access);
    if ($me['code'] === 200 && !empty($me['body']['email'])) $email = sec_email($me['body']['email']);

    $existing = gc_account($uid);
    if ($existing) {
        // se Google non rimanda il refresh token, teniamo quello che avevamo
        $refreshEnc = $refresh ? gc_encrypt($refresh) : $existing['refresh_token'];
        sec_q("UPDATE google_calendar_accounts
               SET google_email = ?, access_token = ?, refresh_token = ?, token_expires = ?,
                   sync_token = NULL, is_enabled = 1
               WHERE user_id = ?",
              [$email, gc_encrypt($access), $refreshEnc, date('Y-m-d H:i:s', time() + $ttl), $uid]);
    } else {
        sec_q("INSERT INTO google_calendar_accounts
               (user_id, google_email, access_token, refresh_token, token_expires, calendar_id, is_enabled)
               VALUES (?,?,?,?,?,'primary',1)",
              [$uid, $email, gc_encrypt($access), gc_encrypt($refresh), date('Y-m-d H:i:s', time() + $ttl)]);
    }
    sec_q("UPDATE planner_settings SET google_sync_enabled = 1 WHERE user_id = ?", [$uid], 'i');
    /* il calendario dedicato si crea subito, così il primo invio ci va dentro */
    $accNow = gc_account($uid);
    if ($accNow) gc_ensure_calendar($uid, $access, $accNow);
    gc_log($uid, 'auth', 'ok', 'account collegato');
    sec_audit('google_connect', 'ok', $email);

    header('Location: ' . $back . '?google=collegato');
    exit;
}

/* ---------- stato ---------- */
/* Il marcatore serve a rispondere a una domanda che si fa in fretta e a cui
   altrimenti non si può rispondere: «il file che sta rispondendo è quello
   nuovo?». Va cambiato a ogni consegna. */
define('GC_BUILD', '2026-08-06 calendari+riparazione');

if ($ajax === 'status') {
    $acc = gc_account($uid);
    $pending = sec_one("SELECT COUNT(*) AS c FROM google_sync_queue WHERE user_id = ?", [$uid], 'i');
    sec_json([
        'ok'         => true,
        'configured' => gc_configured(),
        'connected'  => $acc && (int)$acc['is_enabled'] === 1,
        'email'      => $acc['google_email'] ?? null,
        'last_sync'  => $acc['last_sync_at'] ?? null,
        'calendar'   => ($acc && ($acc['calendar_id'] ?? 'primary') !== 'primary') ? GC_CAL_NAME : null,
        'modes'      => gc_modes($uid),
        'pending'    => (int)($pending['c'] ?? 0),
        'window'     => ['back' => GC_WINDOW_BACK, 'forward' => GC_WINDOW_FWD],
        'build'      => GC_BUILD,
    ]);
}

/* ---------- ripara i doppioni su Google (CROSS-CALENDAR) ----------
   Toglie le copie in più incrociando i dati tra calendario principale e dedicato.
   Forza l'eliminazione dei cloni e dà la priorità al calendario dedicato. */
function gc_repair_cross_calendars(int $uid, string $token, string $dedicato): array {
    $da = date('Y-m-d', strtotime('-60 days')) . 'T00:00:00Z';
    $a  = date('Y-m-d', strtotime('+' . GC_WINDOW_FWD . ' days')) . 'T23:59:59Z';

    /* Gli ID noti al planner per non perdere l'aggancio in caso di eliminazione */
    $noti = [];
    foreach (sec_all("SELECT google_event_id FROM planner_events WHERE user_id = ? AND google_event_id IS NOT NULL", [$uid], 'i') as $r) {
        $noti[(string)$r['google_event_id']] = true;
    }

    $tutti = [];
    $calendari = array_unique([$dedicato, 'primary']);
    
    foreach ($calendari as $cal) {
        if ($cal === '') continue;
        $page = null; $giri = 0;
        do {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?'
                 . http_build_query(array_filter([
                     'timeMin' => $da, 'timeMax' => $a,
                     'singleEvents' => 'true', 'maxResults' => 250,
                     'pageToken' => $page,
                 ]));
            $res = gc_http('GET', $url, null, $token);
            if ($res['code'] !== 200) return ['ok' => false, 'removed' => 0];
            foreach (($res['body']['items'] ?? []) as $it) {
                if (empty($it['id'])) continue;
                if (($it['status'] ?? '') === 'cancelled') continue;
                $inizio = $it['start']['date'] ?? ($it['start']['dateTime'] ?? '');
                $chiave = trim((string)($it['summary'] ?? '')) . '|' . substr((string)$inizio, 0, 16);
                /* Di una serie si cancella il master, non la singola occorrenza.
                   Cancellando l'istanza Google si limita a nascondere QUEL
                   giorno e la serie resta: il doppione tornerebbe la settimana
                   dopo, ed è il motivo per cui i compleanni doppi non se ne
                   andavano nemmeno premendo il tasto. */
                $capo = (string)($it['recurringEventId'] ?? '');
                $tutti[$chiave][] = [
                    'id'    => $capo !== '' ? $capo : (string)$it['id'],
                    'cal'   => $cal,
                    'serie' => $capo !== '',
                    'noto'  => isset($noti[(string)$it['id']]) || ($capo !== '' && isset($noti[$capo])),
                ];
            }
            $page = $res['body']['nextPageToken'] ?? null;
        } while ($page && ++$giri < 8);
    }

    $tolti = 0;
    foreach ($tutti as $chiave => $copie) {
        if (count($copie) < 2) continue; // Non è un doppione

        /* Ogni master si tocca una volta sola: di una serie ripetuta lo stesso
           evento compare una volta per occorrenza, e senza questo filtro si
           proverebbe a cancellarlo venti volte. */
        $visti = [];
        $unici = [];
        foreach ($copie as $c) {
            $k = $c['cal'] . '|' . $c['id'];
            if (isset($visti[$k])) continue;
            $visti[$k] = true;
            $unici[] = $c;
        }
        $copie = $unici;
        if (count($copie) < 2) continue;

        /* Quale copia tenere.

           Prima era il contrario: vinceva quella nel calendario dedicato. Ma
           il dedicato è NOSTRO — ci finisce solo quello che ci abbiamo messo
           noi — quindi se lo stesso evento esiste anche altrove su Google,
           l'originale è quello di fuori e la copia da togliere è la nostra.
           Era questo a far sopravvivere i compleanni doppi: la riparazione
           cancellava quello vero di Birthdays e teneva la nostra copia.

           Resta davanti a tutto quella che il planner sta ancora puntando:
           cancellarla lascerebbe una riga qui agganciata al vuoto. */
        $da_tenere = $copie[0];
        $punteggio = -1;
        foreach ($copie as $c) {
            $p = 0;
            if ($c['noto']) $p += 5;
            if ($c['cal'] !== $dedicato) $p += 2;
            if ($p > $punteggio) {
                $punteggio = $p;
                $da_tenere = $c;
            }
        }

        // 2. Eliminiamo tutte le altre copie da Google
        foreach ($copie as $c) {
            if ($c['id'] === $da_tenere['id'] && $c['cal'] === $da_tenere['cal']) continue;
            
            $del = gc_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/'
                   . rawurlencode($c['cal']) . '/events/' . rawurlencode($c['id']), null, $token);
                   
            if (($del['code'] >= 200 && $del['code'] < 300) || $del['code'] === 404 || $del['code'] === 410) {
                $tolti++;
                // Se la copia appena polverizzata era quella agganciata al Planner,
                // reindirizziamo il database verso la copia che abbiamo tenuto.
                if ($c['noto']) {
                    sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ? 
                           WHERE user_id = ? AND google_event_id = ?",
                          [$da_tenere['id'], $da_tenere['cal'], $uid, $c['id']], 'ssis');
                }
            }
        }
    }
    return ['ok' => true, 'removed' => $tolti];
}

if ($ajax === 'repair') {
    sec_guard_write($uid, 'google_sync', 0, 4, 300);
    $acc = gc_account($uid);
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json(t('api.nessun_account_google'), 400, 'not_connected');
    $token = gc_access_token($uid);
    if (!$token) sec_die_json(t('api.google_scaduto'), 401, 'reauth');

    $dedicato = (string)($acc['calendar_id'] ?? '');
    $r = gc_repair_cross_calendars($uid, $token, $dedicato);
    
    gc_log($uid, 'delete', $r['ok'] ? 'ok' : 'error', 'riparazione doppioni incrociata', $r['removed']);
    sec_json(['ok' => $r['ok'], 'removed' => $r['removed']]);
}

/* ---------- i calendari di Google, con la destinazione scelta ----------
   Si va a chiedere a Google quali ci sono adesso e si risponde con l'elenco
   più la scelta fatta per ognuno. È la lista che le impostazioni mostrano
   dopo il collegamento. */
if ($ajax === 'calendars') {
    $acc = gc_account($uid);
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json(t('api.nessun_account_google'), 400, 'not_connected');
    $token = gc_access_token($uid);
    if (!$token) sec_die_json(t('api.google_scaduto'), 401, 'reauth');

    $mappa = gc_refresh_map($uid, $token, (string)($acc['calendar_id'] ?? ''));

    /* Quanti eventi sono già entrati da ciascun calendario. Serve a rispondere
       alla domanda che uno si fa guardando l'elenco — «e allora questi da dove
       sono arrivati?» — e a capire cosa si sta per buttare scegliendo
       «nessuno». Si guarda anche `google_calendar_id`, perché gli eventi
       importati prima che esistesse `google_source_ref` hanno solo quello. */
    $conti = [];
    foreach (sec_all("SELECT COALESCE(google_source_ref, google_calendar_id) AS ref, COUNT(*) AS n
                      FROM planner_events
                      WHERE user_id = ? AND google_origin = 1
                      GROUP BY COALESCE(google_source_ref, google_calendar_id)", [$uid], 'i') as $c) {
        if ($c['ref'] !== null) $conti[(string)$c['ref']] = (int)$c['n'];
    }

    $out = [];
    foreach ($mappa as $m) {
        $ref = (string)$m['google_ref'];
        $out[] = [
            'ref'         => $ref,
            'name'        => $m['google_name'],
            'color'       => $m['google_color'],
            'is_primary'  => (int)$m['is_primary'] === 1,
            'can_write'   => (int)$m['can_write'] === 1,
            'is_ours'     => $ref === ($acc['calendar_id'] ?? ''),
            'calendar_id' => $m['calendar_id'] !== null ? (int)$m['calendar_id'] : null,
            'decided'     => (int)$m['decided'] === 1,
            'entrati'     => isset($conti[$ref]) ? $conti[$ref] : 0,
        ];
    }
    /* i miei calendari si rileggono qui e non con pl_calendars(): quella sta
       in planner.php, che questo file non carica — e caricarlo per una lista
       di quattro righe vorrebbe dire tirarsi dietro tutta la pagina */
    $miei = [];
    foreach (sec_all("SELECT id, name, color, is_default, google_push_ref
                      FROM planner_calendars WHERE user_id = ? ORDER BY is_default DESC, name",
                     [$uid], 'i') as $c) {
        $miei[] = [
            'id'         => (int)$c['id'],
            'name'       => $c['name'],
            'color'      => ($c['color'] !== null && $c['color'] !== '') ? $c['color'] : null,
            'is_default' => (int)$c['is_default'] === 1,
            'push_ref'   => $c['google_push_ref'],
        ];
    }
    sec_json(['ok' => true, 'google' => $out, 'mine' => $miei, 'levels' => gc_push_livelli($uid)]);
}

/* ---------- dove far finire gli eventi di un calendario Google ----------
   `calendar_id` a zero vuol dire «nessuno», e nessuno vuol dire davvero
   niente: gli eventi già entrati se ne vanno. È la parte che sorprende, ed è
   voluta — chi toglie i compleanni non vuole ritrovarseli in agenda fino
   all'anno prossimo. */
if ($ajax === 'map_set') {
    sec_guard_write($uid, 'google_sync', 0, 30, 60);
    $ref = sec_str(sec_in('ref'), 255);
    if ($ref === '') sec_die_json(t('api.calendario_non_indicato'), 422, 'invalid');
    $dest = sec_int(sec_in('calendar_id'), 0, PHP_INT_MAX, 0);
    if ($dest > 0 && !sec_owns('planner_calendars', $dest, $uid)) {
        sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
    }

    $prima = sec_one("SELECT calendar_id FROM google_calendar_map WHERE user_id = ? AND google_ref = ?",
                     [$uid, $ref], 'is');
    $vecchia = ($prima && $prima['calendar_id'] !== null) ? (int)$prima['calendar_id'] : 0;

    /* Il segnalibro si azzera sempre: dice a Google «da qui in poi mandami
       solo le novità», e cambiando destinazione le novità non bastano — o si
       riparte da capo, o del calendario appena acceso entrerebbe solo quello
       che cambia da domani. */
    sec_q("INSERT INTO google_calendar_map (user_id, google_ref, calendar_id, decided, sync_token)
           VALUES (?,?,?,1,NULL)
           ON DUPLICATE KEY UPDATE calendar_id = VALUES(calendar_id), decided = 1, sync_token = NULL",
          [$uid, $ref, $dest > 0 ? $dest : null], 'isi');

    $tolti = 0; $spostati = 0;
    if ($dest === 0) {
        $tolti = gc_purge_source($uid, $ref);
    } elseif ($vecchia !== $dest) {
        /* cambiata destinazione: quello che era già entrato trasloca, se no
           resterebbe nel calendario di prima e il filtro lo mostrerebbe sotto
           un'etichetta che non è più vera */
        sec_q("UPDATE planner_events SET calendar_id = ?, updated_at = updated_at
               WHERE user_id = ? AND google_origin = 1
                 AND (google_source_ref = ? OR (google_source_ref IS NULL AND google_calendar_id = ?))",
              [$dest, $uid, $ref, $ref], 'iiss');
        $spostati = sec_affected();
    }

    gc_log($uid, 'auth', 'ok', 'destinazione di ' . mb_substr($ref, 0, 60) . ': ' . ($dest ?: 'nessuno'));
    sec_json(['ok' => true, 'removed' => $tolti, 'moved' => $spostati]);
}

/* ---------- dove finiscono su Google gli eventi di un mio calendario ---------- */
if ($ajax === 'push_set') {
    sec_guard_write($uid, 'google_sync', 0, 30, 60);
    $ref = sec_str(sec_in('ref'), 255);
    /* «importanti» e «importantissimi» arrivano da qui come fossero
       calendari: non hanno un id, si riconoscono dal livello. */
    $liv = sec_enum((string)(sec_in('level') ?? ''), ['', 'high', 'highest'], '');
    if ($liv !== '') {
        $colonna = $liv === 'high' ? 'gc_push_high' : 'gc_push_highest';
        sec_q("UPDATE planner_settings SET $colonna = ? WHERE user_id = ?",
              [$ref !== '' ? $ref : null, $uid], 'si');
        sec_json(['ok' => true]);
    }
    $mio = sec_int(sec_in('calendar_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('planner_calendars', $mio, $uid)) sec_die_json(t('api.calendario_non_trovato'), 404, 'not_found');
    sec_q("UPDATE planner_calendars SET google_push_ref = ? WHERE id = ? AND user_id = ?",
          [$ref !== '' ? $ref : null, $mio, $uid], 'sii');
    sec_json(['ok' => true]);
}

/* ---------- sincronizza adesso ---------- */
if ($ajax === 'sync') {
    sec_guard_write($uid, 'google_sync', 0, 20, 60);
    if (!gc_configured()) sec_die_json(t('api.google_non_configurato'), 503, 'not_configured');

    $acc = gc_account($uid);
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json(t('api.nessun_account_google'), 400, 'not_connected');

    $token = gc_access_token($uid);
    if (!$token) sec_die_json(t('api.google_scaduto'), 401, 'reauth');

    /* prima di tutto: il calendario dedicato esiste? Se l'utente l'ha
       cancellato dal suo Google, lo ricreiamo e ripartiamo da capo. */
    $calId = gc_ensure_calendar($uid, $token, $acc);
    $acc = gc_account($uid);
    if (($acc['calendar_id'] ?? '') !== $calId) $acc['calendar_id'] = $calId;

    /* l'elenco dei calendari si aggiorna prima di leggere: uno creato ieri su
       Google deve poter comparire nelle impostazioni senza aspettare che
       l'utente vada a cercarlo */
    gc_refresh_map($uid, $token, (string)($acc['calendar_id'] ?? ''));

    $deleted = gc_flush_deletions($uid, $token, $acc);
    $pull    = gc_pull($uid, $token, gc_account($uid));   // rileggiamo: il sync token può essere cambiato
    $push    = gc_push($uid, $token, $acc);

    sec_q("UPDATE google_calendar_accounts SET last_sync_at = NOW() WHERE user_id = ?", [$uid], 'i');

    sec_json([
        'ok'      => true,
        'pulled'  => $pull['changed'], 'removed_here' => $pull['removed'],
        'created' => $push['created'], 'updated' => $push['updated'],
        'failed'  => $push['failed'],  'removed_there' => $deleted,
        'last_sync' => date('c'),
    ]);
}

/* ---------- scollega ---------- */
if ($ajax === 'disconnect') {
    sec_guard_write($uid, 'google_sync', 0, 10, 60);
    $acc = gc_account($uid);
    if ($acc) {
        // proviamo a revocare il consenso, così l'utente non lo trova
        // ancora attivo nelle impostazioni del suo account Google
        $tok = gc_decrypt($acc['refresh_token']) ?: gc_decrypt($acc['access_token']);
        if ($tok) gc_http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => $tok], null, true);
    }
    sec_q("DELETE FROM google_calendar_accounts WHERE user_id = ?", [$uid], 'i');
    sec_q("DELETE FROM google_sync_queue WHERE user_id = ?", [$uid], 'i');
    sec_q("UPDATE planner_settings SET google_sync_enabled = 0 WHERE user_id = ?", [$uid], 'i');

    /* Gli eventi restano dove sono, e SI TENGONO IL LORO IDENTIFICATIVO
       GOOGLE.

       Prima si azzerava, con l'idea che scollegandosi quel filo non servisse
       più. Serviva eccome: il calendario dedicato su Google resta lì con
       dentro tutto, e al ricollegamento la lettura non riconosceva più niente
       — ogni evento sembrava nuovo e ne veniva creata una seconda copia qui.
       Bastava scollegare e ricollegare per ritrovarsi l'agenda doppia.
       Tenerlo, invece, non costa niente: da scollegati non sincronizza
       nessuno, e al ricollegamento ogni riga ritrova la sua e si aggiorna
       invece di duplicarsi. Se poi si collega un account diverso quei
       riferimenti non troveranno nulla, e a quel punto interviene il
       controllo sul gemello.

       Si azzera solo `google_synced_at`, che dice «l'ultima volta che erano
       allineati»: dopo uno scollegamento non è più vero di nessuno. */
    sec_q("UPDATE planner_events SET google_synced_at = NULL WHERE user_id = ?", [$uid], 'i');

    /* I segnalibri invece vanno buttati: dicono a Google «mandami solo le
       novità da qui in poi», e dopo un ricollegamento serve rileggere tutto
       da capo. Le destinazioni scelte restano: sono una decisione
       dell'utente, non un dato di sessione. */
    sec_q("UPDATE google_calendar_map SET sync_token = NULL WHERE user_id = ?", [$uid], 'i');
    gc_log($uid, 'auth', 'ok', 'account scollegato');
    sec_audit('google_disconnect', 'ok', null);
    sec_json(['ok' => true]);
}

sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');