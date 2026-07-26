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
    $list = gc_http('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', null, $token);
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

/** Google → planner. Usa il sync token quando c'è: scarica solo le novità. */
function gc_pull(int $uid, string $token, array $acc): array {
    $calendar = rawurlencode($acc['calendar_id'] ?: 'primary');
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
            sec_q("UPDATE google_calendar_accounts SET sync_token = NULL WHERE user_id = ?", [$uid], 'i');
            $acc['sync_token'] = null;
            return gc_pull($uid, $token, $acc);
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
                       AND updated_at <= google_synced_at + INTERVAL 2 SECOND", [$uid, $gid], 'is');
                if (sec_affected() > 0) $removed++;
                continue;
            }
            $data = gc_from_google($g);
            if (!$data) continue;

            /* Un evento appena toccato qui non va sovrascritto con la versione
               di Google: la lettura avviene PRIMA dell'invio, quindi
               riporterebbe indietro lo spostamento o il ritardo appena fatti,
               e dopo qualche secondo l'evento tornerebbe dov'era.
               Se è "sporco" lo saltiamo: lo manderemo noi subito dopo. */
            $existing = sec_one(
                "SELECT id, (google_synced_at IS NULL
                             OR updated_at > google_synced_at + INTERVAL 2 SECOND) AS dirty
                 FROM planner_events WHERE user_id = ? AND google_event_id = ? LIMIT 1",
                [$uid, $gid], 'is');
            if ($existing && (int)$existing['dirty'] === 1) {
                continue;   // vince la modifica fatta qui, non quella di Google
            }
            if ($existing) {
                sec_q("UPDATE planner_events SET title = ?, notes = ?, event_date = ?, start_time = ?, end_time = ?,
                              is_all_day = ?, google_synced_at = NOW()
                       WHERE id = ? AND user_id = ?",
                      [$data['title'], $data['notes'], $data['date'], $data['start'], $data['end'],
                       $data['all_day'], (int)$existing['id'], $uid]);
            } else {
                sec_q("INSERT INTO planner_events
                       (user_id, title, notes, event_date, start_time, end_time, is_all_day,
                        notify_type, notify_minutes, google_event_id, google_synced_at, google_origin)
                       VALUES (?,?,?,?,?,?,?,'none',0,?,NOW(),1)",
                      [$uid, $data['title'], $data['notes'], $data['date'], $data['start'], $data['end'],
                       $data['all_day'], $gid]);
            }
            $changed++;
        }
        $pageToken = $res['body']['nextPageToken'] ?? null;
        if (!empty($res['body']['nextSyncToken'])) $newSync = $res['body']['nextSyncToken'];
    } while ($pageToken && $guard < 10);

    if ($newSync) {
        sec_q("UPDATE google_calendar_accounts SET sync_token = ? WHERE user_id = ?", [$newSync, $uid], 'si');
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
function gc_target_for(array $ev, array $modes, string $dedicatedId): ?string {
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
function gc_push(int $uid, string $token, array $acc): array {
    $dedicated = $acc['calendar_id'] ?: 'primary';
    $modes = gc_modes($uid);
    $from = date('Y-m-d', strtotime('-' . GC_WINDOW_BACK . ' days'));
    $to   = date('Y-m-d', strtotime('+' . GC_WINDOW_FWD . ' days'));

    /* Due secondi di tolleranza: il salvataggio di google_synced_at fa
       muovere anche updated_at, e senza margine gli eventi risulterebbero
       sempre "da mandare". */
    $rows = sec_all(
        "SELECT * FROM planner_events
         WHERE user_id = ? AND event_date BETWEEN ? AND ?
           AND (google_synced_at IS NULL OR updated_at > google_synced_at + INTERVAL 2 SECOND)
         ORDER BY event_date LIMIT " . GC_PUSH_LIMIT,
        [$uid, $from, $to], 'iss'
    );

    $created = 0; $updated = 0; $failed = 0; $removed = 0;
    foreach ($rows as $ev) {
        $target = gc_target_for($ev, $modes, $dedicated);
        $oldCal = $ev['google_calendar_id'] ?? null;
        $oldId  = $ev['google_event_id'] ?? null;

        /* Non deve più stare su Google: se c'era, lo togliamo. Succede quando
           si abbassa l'importanza o si cambiano le scelte nelle impostazioni. */
        if ($target === null) {
            if (!empty($oldId)) {
                gc_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/'
                        . rawurlencode($oldCal ?: $dedicated) . '/events/' . rawurlencode($oldId), null, $token);
                sec_q("UPDATE planner_events SET google_event_id = NULL, google_calendar_id = NULL,
                              google_synced_at = NOW() WHERE id = ? AND user_id = ?", [(int)$ev['id'], $uid], 'ii');
                $removed++;
            } else {
                /* niente da fare, ma lo segniamo come visto per non riesaminarlo a ogni giro */
                sec_q("UPDATE planner_events SET google_synced_at = NOW() WHERE id = ? AND user_id = ?", [(int)$ev['id'], $uid], 'ii');
            }
            continue;
        }

        /* Ha cambiato calendario: si cancella da quello vecchio e si ricrea
           nel nuovo. Google non permette di spostare un evento fra calendari
           mantenendo lo stesso identificativo in modo affidabile. */
        if (!empty($oldId) && $oldCal !== null && $oldCal !== $target) {
            gc_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/'
                    . rawurlencode($oldCal) . '/events/' . rawurlencode($oldId), null, $token);
            $oldId = null;
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($target) . '/events';
        $payload = gc_to_google($ev);

        if (!empty($oldId)) {
            $res = gc_http('PATCH', $base . '/' . rawurlencode($oldId), $payload, $token);
            if ($res['code'] === 404 || $res['code'] === 410) $oldId = null;   // sparito di là: si ricrea
            elseif ($res['code'] >= 200 && $res['code'] < 300) {
                sec_q("UPDATE planner_events SET google_calendar_id = ?, google_synced_at = NOW()
                       WHERE id = ? AND user_id = ?", [$target, (int)$ev['id'], $uid], 'sii');
                $updated++;
                continue;
            } else { $failed++; continue; }
        }

        $res = gc_http('POST', $base, $payload, $token);
        if ($res['code'] >= 200 && $res['code'] < 300 && !empty($res['body']['id'])) {
            sec_q("UPDATE planner_events SET google_event_id = ?, google_calendar_id = ?, google_synced_at = NOW()
                   WHERE id = ? AND user_id = ?", [(string)$res['body']['id'], $target, (int)$ev['id'], $uid], 'ssii');
            $created++;
        } else { $failed++; }
    }
    gc_log($uid, 'push', $failed > 0 ? 'error' : 'ok', $failed > 0 ? $failed . ' non riusciti' : null, $created + $updated);
    return ['created' => $created, 'updated' => $updated, 'failed' => $failed, 'removed_far' => $removed];
}

/** Cancellazioni fatte qui, da riportare là. */
function gc_flush_deletions(int $uid, string $token, array $acc): int {
    $calendar = rawurlencode($acc['calendar_id'] ?: 'primary');
    $rows = sec_all("SELECT id, google_event_id FROM google_sync_queue
                     WHERE user_id = ? AND action = 'delete' AND attempts < 3 ORDER BY id LIMIT 60", [$uid], 'i');
    $done = 0;
    foreach ($rows as $r) {
        $res = gc_http('DELETE',
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendar . '/events/' . rawurlencode($r['google_event_id']),
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
    ]);
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
    /* Gli eventi restano dove sono. Quelli nati su Google non hanno più un
       gemello: li lasciamo, sono comunque cose scritte in agenda. */
    sec_q("UPDATE planner_events SET google_event_id = NULL, google_synced_at = NULL WHERE user_id = ?", [$uid], 'i');
    gc_log($uid, 'auth', 'ok', 'account scollegato');
    sec_audit('google_disconnect', 'ok', null);
    sec_json(['ok' => true]);
}

sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');