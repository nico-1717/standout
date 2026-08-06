<?php
/* =====================================================================
   NISAMA — GOOGLE CALENDAR
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
require_once 'config.php';
require_once 'security_core.php';
/* Le chiamate del planner vogliono JSON anche quando la sessione è scaduta;
   il collegamento con Google invece è una navigazione normale. */
if (isset($_GET['ajax'])) { require_once 'auth_check_api.php'; }
else                      { require_once 'auth_check.php'; }

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

define('GC_CAL_NAME', 'Planner — Nisama');

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
        'description' => 'Gli eventi del tuo planner. Puoi nasconderlo o cancellarlo quando vuoi: il planner continua a funzionare.',
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
function gc_to_google(array $ev): array {
    $out = [
        'summary'     => $ev['title'] !== '' ? $ev['title'] : 'Senza titolo',
        'description' => (string)$ev['notes'],
    ];
    /* Il luogo va passato come testo: Google lo riconosce da sé e lo rende
       navigabile. Le coordinate nostre non gli servono, e mandare un campo
       in più che lui non usa significherebbe solo altro da tenere allineato. */
    if (!empty($ev['location'])) $out['location'] = (string)$ev['location'];
    if ((int)$ev['is_all_day'] === 1) {
        $out['start'] = ['date' => $ev['event_date']];
        $out['end']   = ['date' => date('Y-m-d', strtotime($ev['event_date'] . ' +1 day'))];
    } else {
        $out['start'] = ['dateTime' => $ev['event_date'] . 'T' . $ev['start_time'], 'timeZone' => GC_TZ];
        $out['end']   = ['dateTime' => $ev['event_date'] . 'T' . $ev['end_time'],   'timeZone' => GC_TZ];
    }
    if (!empty($ev['notify_type']) && $ev['notify_type'] !== 'none') {
        $out['reminders'] = [
            'useDefault' => false,
            'overrides'  => [['method' => 'popup', 'minutes' => max(0, (int)$ev['notify_minutes'])]],
        ];
    }
    return $out;
}

/** Un evento di Google riportato ai campi del planner. */
function gc_from_google(array $g): ?array {
    $start = $g['start'] ?? [];
    $end   = $g['end'] ?? [];
    if (!empty($start['date'])) {
        return [
            'date'    => $start['date'],
            'start'   => '00:00:00',
            'end'     => '23:59:00',
            'all_day' => 1,
            'title'   => (string)($g['summary'] ?? 'Senza titolo'),
            'notes'   => (string)($g['description'] ?? ''),
        ];
    }
    if (!empty($start['dateTime'])) {
        $s = strtotime($start['dateTime']);
        $e = !empty($end['dateTime']) ? strtotime($end['dateTime']) : $s + 1800;
        if ($s === false) return null;
        // gli eventi a cavallo della mezzanotte vengono tagliati al giorno d'inizio
        if (date('Y-m-d', $e) !== date('Y-m-d', $s)) $e = strtotime(date('Y-m-d', $s) . ' 23:59');
        return [
            'date'    => date('Y-m-d', $s),
            'start'   => date('H:i:s', $s),
            'end'     => date('H:i:s', $e),
            'all_day' => 0,
            'title'   => (string)($g['summary'] ?? 'Senza titolo'),
            'notes'   => (string)($g['description'] ?? ''),
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
        $princ = sec_one("SELECT id FROM planner_calendars WHERE user_id = ? ORDER BY is_default DESC, id LIMIT 1",
                         [$uid], 'i');
        $daLeggere[] = [
            'ref'   => (string)($acc['calendar_id'] ?: 'primary'),
            'dest'  => $princ ? (int)$princ['id'] : null,
            'token' => $acc['sync_token'],
        ];
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

    $params = ['singleEvents' => 'true', 'maxResults' => '250', 'showDeleted' => 'true'];
    if (!empty($acc['sync_token'])) {
        $params['syncToken'] = $acc['sync_token'];
    } else {
        $params['timeMin'] = date('c', strtotime('-' . GC_WINDOW_BACK . ' days'));
        $params['timeMax'] = date('c', strtotime('+' . GC_WINDOW_FWD . ' days'));
        $params['orderBy'] = 'startTime';
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

            if (($g['status'] ?? '') === 'cancelled') {
                /* stessa prudenza: se l'evento è stato appena modificato qui,
                   lo teniamo e lo rimanderemo noi */
                sec_q("DELETE FROM planner_events WHERE user_id = ? AND google_event_id = ?
                       AND google_synced_at IS NOT NULL
                       AND updated_at <= google_synced_at", [$uid, $gid], 'is');
                if (sec_affected() > 0) $removed++;
                continue;
            }
            $data = gc_from_google($g);
            if (!$data) continue;

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
            if (!$existing) {
                $gemello = sec_one(
                    "SELECT id FROM planner_events
                     WHERE user_id = ? AND event_date = ?
                       AND title = ? AND is_all_day = ?
                       AND (start_time <=> ?)
                       AND (google_event_id IS NULL
                            OR (google_origin = 1 AND google_event_id <> ?))
                     LIMIT 1",
                    [$uid, $data['date'], $data['title'], $data['all_day'], $data['start'], $gid],
                    /* `<=>` confronta anche i nulli: un evento di tutto il
                       giorno non ha ora d'inizio, e con `=` non si troverebbe
                       mai il suo gemello */
                    'ississ');
                if ($gemello) {
                    /* si prende in carico la riga che c'è già: da qui in poi
                       quell'evento risponde a questo calendario, e la prossima
                       volta lo ritrova per identificativo senza passare di qui */
                    sec_q("UPDATE planner_events
                           SET google_event_id = ?, google_calendar_id = ?, google_source_ref = ?,
                               google_synced_at = NOW(), updated_at = updated_at
                           WHERE id = ? AND user_id = ?",
                          [$gid, $sorgente, $sorgente, (int)$gemello['id'], $uid], 'sssii');
                    continue;
                }
            }
            if ($existing) {
                /* Il calendario di destinazione non si riscrive a ogni giro:
                   se l'utente ha spostato a mano quell'evento in un altro dei
                   suoi calendari, rimetterlo qui glielo riporterebbe indietro
                   a ogni sincronia. Si scrive solo la provenienza, che invece
                   è un fatto e non una scelta. */
                sec_q("UPDATE planner_events SET title = ?, notes = ?, event_date = ?, start_time = ?, end_time = ?,
                              is_all_day = ?, google_calendar_id = ?, google_source_ref = ?,
                              google_synced_at = NOW(), updated_at = updated_at
                       WHERE id = ? AND user_id = ?",
                      [$data['title'], $data['notes'], $data['date'], $data['start'], $data['end'],
                       $data['all_day'], $sorgente, $sorgente, (int)$existing['id'], $uid]);
            } else {
                sec_q("INSERT INTO planner_events
                       (user_id, title, notes, event_date, start_time, end_time, is_all_day,
                        notify_type, notify_minutes, google_event_id, google_calendar_id,
                        google_source_ref, calendar_id, google_synced_at, google_origin)
                       VALUES (?,?,?,?,?,?,?,'none',0,?,?,?,?,NOW(),1)",
                      [$uid, $data['title'], $data['notes'], $data['date'], $data['start'], $data['end'],
                       $data['all_day'], $gid, $sorgente, $sorgente, $dest]);
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
       esattamente così che sono nati i doppioni. Le regole per importanza
       valgono solo per gli eventi nati nel planner. */
    if ((int)($ev['google_origin'] ?? 0) === 1) {
        $suo = $ev['google_calendar_id'] ?? null;
        return $suo !== null && $suo !== '' ? (string)$suo : null;
    }

    /* Il verso di ritorno: se il calendario di questo evento ha un
       calendario Google suo, è là che va, e le regole per importanza qui non
       c'entrano. È una scelta esplicita — «le vacanze vanno nelle vacanze» —
       e batte una regola generale che l'utente ha impostato pensando ad
       altro. */
    $suoCal = $ev['calendar_id'] ?? null;
    if ($suoCal !== null && !empty($push[(int)$suoCal])) {
        return (string)$push[(int)$suoCal];
    }

    $imp = $ev['importance'] ?? 'normal';
    $isHigh    = ($imp === 'high' || $imp === 'highest');
    $isHighest = ($imp === 'highest');

    $primary = $modes['primary'] ?? 'none';
    if (($primary === 'high' && $isHigh) || ($primary === 'highest' && $isHighest)) {
        return 'primary';
    }
    $ded = $modes['dedicated'] ?? 'all';
    if ($ded === 'all') return $dedicatedId;
    if ($ded === 'high' && $isHigh) return $dedicatedId;
    if ($ded === 'highest' && $isHighest) return $dedicatedId;
    return null;   // questo evento resta solo nel planner
}

/** Le scelte dell'utente, lette dalle impostazioni del planner. */
function gc_modes(int $uid): array {
    $row = sec_one("SELECT gc_dedicated_mode, gc_primary_mode FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    return [
        'dedicated' => $row['gc_dedicated_mode'] ?? 'all',
        'primary'   => $row['gc_primary_mode'] ?? 'none',
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
        exit('Google Calendar non è ancora configurato: mancano le costanti in config.php.');
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
                $inizio = $it['start']['date'] ?? ($it['start']['dateTime'] ?? '');
                $chiave = trim((string)($it['summary'] ?? '')) . '|' . substr((string)$inizio, 0, 16);
                $tutti[$chiave][] = [
                    'id' => (string)$it['id'],
                    'cal' => $cal,
                    'noto' => isset($noti[(string)$it['id']])
                ];
            }
            $page = $res['body']['nextPageToken'] ?? null;
        } while ($page && ++$giri < 8);
    }

    $tolti = 0;
    foreach ($tutti as $chiave => $copie) {
        if (count($copie) < 2) continue; // Non è un doppione

        // 1. Scegliamo quale copia tenere. Priorità massima: Calendario Dedicato.
        $da_tenere = $copie[0];
        $punteggio = -1;
        foreach ($copie as $c) {
            $p = 0;
            if ($c['noto']) $p += 2;
            if ($c['cal'] === $dedicato) $p += 5; // Punteggio altissimo se è nel dedicato
            if ($p > $punteggio) { 
                $punteggio = $p; 
                $da_tenere = $c; 
            }
        }

        // 2. Eliminiamo tutte le altre copie da Google
        foreach ($copie as $c) {
            if ($c['id'] === $da_tenere['id']) continue;
            
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
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json('Nessun account Google collegato.', 400, 'not_connected');
    $token = gc_access_token($uid);
    if (!$token) sec_die_json('Il collegamento con Google è scaduto: ricollega l\'account.', 401, 'reauth');

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
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json('Nessun account Google collegato.', 400, 'not_connected');
    $token = gc_access_token($uid);
    if (!$token) sec_die_json('Il collegamento con Google è scaduto: ricollega l\'account.', 401, 'reauth');

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
    sec_json(['ok' => true, 'google' => $out, 'mine' => $miei]);
}

/* ---------- dove far finire gli eventi di un calendario Google ----------
   `calendar_id` a zero vuol dire «nessuno», e nessuno vuol dire davvero
   niente: gli eventi già entrati se ne vanno. È la parte che sorprende, ed è
   voluta — chi toglie i compleanni non vuole ritrovarseli in agenda fino
   all'anno prossimo. */
if ($ajax === 'map_set') {
    sec_guard_write($uid, 'google_sync', 0, 30, 60);
    $ref = sec_str(sec_in('ref'), 255);
    if ($ref === '') sec_die_json('Calendario non indicato.', 422, 'invalid');
    $dest = sec_int(sec_in('calendar_id'), 0, PHP_INT_MAX, 0);
    if ($dest > 0 && !sec_owns('planner_calendars', $dest, $uid)) {
        sec_die_json('Calendario non trovato.', 404, 'not_found');
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
    $mio = sec_int(sec_in('calendar_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('planner_calendars', $mio, $uid)) sec_die_json('Calendario non trovato.', 404, 'not_found');
    $ref = sec_str(sec_in('ref'), 255);
    sec_q("UPDATE planner_calendars SET google_push_ref = ? WHERE id = ? AND user_id = ?",
          [$ref !== '' ? $ref : null, $mio, $uid], 'sii');
    sec_json(['ok' => true]);
}

/* ---------- sincronizza adesso ---------- */
if ($ajax === 'sync') {
    sec_guard_write($uid, 'google_sync', 0, 20, 60);
    if (!gc_configured()) sec_die_json('Google Calendar non è configurato su questo sito.', 503, 'not_configured');

    $acc = gc_account($uid);
    if (!$acc || (int)$acc['is_enabled'] !== 1) sec_die_json('Nessun account Google collegato.', 400, 'not_connected');

    $token = gc_access_token($uid);
    if (!$token) sec_die_json('Il collegamento con Google è scaduto: ricollega l\'account.', 401, 'reauth');

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

sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');