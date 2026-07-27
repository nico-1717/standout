<?php
/* =====================================================================
   NISAMA — METEO
   Fonte: Open-Meteo (open-meteo.com), dati sotto licenza CC BY 4.0.
   L'attribuzione va mostrata all'utente: è nel popup del planner.

   Perché il server e non il browser:
    - la posizione dell'utente non viene mai esposta al servizio esterno
      con il suo indirizzo IP: parte solo dal nostro server;
    - possiamo tenere in cache le risposte, e con la posizione arrotondata
      a una griglia di pochi chilometri la cache è condivisa fra utenti
      vicini. Mille persone nella stessa città costano una chiamata
      all'ora invece di mille.

   Chiamate:
     meteo_api.php?ajax=status     (GET)   stato + previsioni se attivo
     meteo_api.php?ajax=enable     (POST)  lat, lon → attiva
     meteo_api.php?ajax=disable    (POST)
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'meteo', 0, 60, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';

define('WX_GIORNI', 5);        // oggi più i quattro successivi, non oltre
define('WX_CACHE_MINUTI', 60);
define('WX_FONTE', 'Open-Meteo');
define('WX_FONTE_URL', 'https://open-meteo.com');

/* --------------------------------------------------------------------
   Codici meteo dell'Organizzazione Meteorologica Mondiale, tradotti.
   -------------------------------------------------------------------- */
function wx_codice(int $c): array {
    $m = [
        0  => ['Sereno', 'sole'],
        1  => ['Poco nuvoloso', 'sole-nubi'],
        2  => ['Parzialmente nuvoloso', 'sole-nubi'],
        3  => ['Coperto', 'nubi'],
        45 => ['Nebbia', 'nebbia'],
        48 => ['Nebbia con brina', 'nebbia'],
        51 => ['Pioviggine leggera', 'pioggia-leggera'],
        53 => ['Pioviggine', 'pioggia-leggera'],
        55 => ['Pioviggine intensa', 'pioggia'],
        56 => ['Pioviggine gelata', 'pioggia'],
        57 => ['Pioviggine gelata intensa', 'pioggia'],
        61 => ['Pioggia leggera', 'pioggia-leggera'],
        63 => ['Pioggia', 'pioggia'],
        65 => ['Pioggia forte', 'pioggia-forte'],
        66 => ['Pioggia gelata', 'pioggia'],
        67 => ['Pioggia gelata forte', 'pioggia-forte'],
        71 => ['Neve leggera', 'neve'],
        73 => ['Neve', 'neve'],
        75 => ['Neve abbondante', 'neve'],
        77 => ['Granelli di neve', 'neve'],
        80 => ['Rovesci leggeri', 'pioggia-leggera'],
        81 => ['Rovesci', 'pioggia'],
        82 => ['Rovesci violenti', 'pioggia-forte'],
        85 => ['Rovesci di neve', 'neve'],
        86 => ['Rovesci di neve forti', 'neve'],
        95 => ['Temporale', 'temporale'],
        96 => ['Temporale con grandine', 'temporale'],
        99 => ['Temporale con grandine forte', 'temporale'],
    ];
    return $m[$c] ?? ['Non disponibile', 'nubi'];
}

/**
 * Attendibilità stimata. Attenzione: non è un dato fornito dal servizio,
 * che non pubblica un indice di confidenza. È una stima nostra basata su
 * quanto è lontana la previsione: più è distante, meno è affidabile.
 * Il popup lo dichiara all'utente, per non far passare una stima nostra
 * per un dato ufficiale.
 */
function wx_attendibilita(int $giorniAvanti): int {
    $scala = [0 => 95, 1 => 88, 2 => 78, 3 => 68, 4 => 58];
    return $scala[$giorniAvanti] ?? 50;
}

/* --------------------------------------------------------------------
   Chiamata al servizio, con cache condivisa fra utenti vicini
   -------------------------------------------------------------------- */
function wx_grid(float $lat, float $lon): string {
    /* due decimali ≈ un chilometro: abbastanza preciso per una previsione
       giornaliera, e abbastanza largo per condividere la cache */
    return number_format($lat, 2, '.', '') . ',' . number_format($lon, 2, '.', '');
}

function wx_fetch(float $lat, float $lon): ?array {
    $key = wx_grid($lat, $lon);

    $row = sec_one("SELECT payload FROM weather_cache
                    WHERE grid_key = ? AND fetched_at > (NOW() - INTERVAL ? MINUTE)",
                   [$key, WX_CACHE_MINUTI], 'si');
    if ($row) {
        $cached = json_decode($row['payload'], true);
        if (is_array($cached)) return $cached;
    }

    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude'      => number_format($lat, 4, '.', ''),
        'longitude'     => number_format($lon, 4, '.', ''),
        'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max',
        'hourly'        => 'weather_code,precipitation_probability,temperature_2m',
        'timezone'      => 'Europe/Rome',
        'forecast_days' => WX_GIORNI,
    ]);

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('meteo', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return null;

    $data = json_decode((string)$raw, true);
    if (!is_array($data) || empty($data['daily']['time'])) return null;

    sec_q("INSERT INTO weather_cache (grid_key, payload) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
          [$key, json_encode($data, JSON_UNESCAPED_UNICODE)], 'ss');

    /* pulizia occasionale delle località non più usate */
    if (random_int(1, 50) === 1) {
        sec_q("DELETE FROM weather_cache WHERE fetched_at < (NOW() - INTERVAL 3 DAY)");
    }
    return $data;
}

/** Dalla risposta grezza ai giorni pronti da mostrare. */
function wx_giorni(array $data): array {
    $out = [];
    $d = $data['daily'];
    $oggi = date('Y-m-d');
    for ($i = 0; $i < count($d['time']); $i++) {
        $giorno = $d['time'][$i];
        $avanti = (int)round((strtotime($giorno) - strtotime($oggi)) / 86400);
        if ($avanti < 0 || $avanti >= WX_GIORNI) continue;
        $code = (int)($d['weather_code'][$i] ?? 3);
        [$testo, $icona] = wx_codice($code);
        $out[$giorno] = [
            'date'      => $giorno,
            'code'      => $code,
            'label'     => $testo,
            'icon'      => $icona,
            'tmax'      => isset($d['temperature_2m_max'][$i]) ? round($d['temperature_2m_max'][$i]) : null,
            'tmin'      => isset($d['temperature_2m_min'][$i]) ? round($d['temperature_2m_min'][$i]) : null,
            'pioggia'   => isset($d['precipitation_probability_max'][$i]) ? (int)$d['precipitation_probability_max'][$i] : null,
            'vento'     => isset($d['wind_speed_10m_max'][$i]) ? round($d['wind_speed_10m_max'][$i]) : null,
            'affidabilita' => wx_attendibilita($avanti),
            'giorni_avanti' => $avanti,
        ];
    }
    return $out;
}

/* ===================== ENDPOINT ===================== */

if ($ajax === 'status') {
    $s = sec_one("SELECT weather_enabled, weather_lat, weather_lon, weather_place
                  FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    $on = $s && (int)$s['weather_enabled'] === 1 && $s['weather_lat'] !== null;

    $risposta = [
        'ok'      => true,
        'enabled' => $on,
        'place'   => $s['weather_place'] ?? null,
        'fonte'   => WX_FONTE,
        'fonte_url' => WX_FONTE_URL,
        'giorni'  => [],
        'nota_affidabilita' => 'Stima nostra basata su quanto è lontana la previsione: il servizio non pubblica un indice di confidenza.',
    ];
    if ($on) {
        $data = wx_fetch((float)$s['weather_lat'], (float)$s['weather_lon']);
        if ($data) $risposta['giorni'] = wx_giorni($data);
        else $risposta['errore'] = 'Previsioni non raggiungibili in questo momento.';
    }
    sec_json($risposta);
}

if ($ajax === 'enable') {
    $lat = sec_float(sec_in('lat'), -90, 90, 999);
    $lon = sec_float(sec_in('lon'), -180, 180, 999);
    if ($lat === 999.0 || $lon === 999.0) sec_die_json('Posizione non valida.', 422, 'invalid');

    /* nome della località: comodo da mostrare, e se non arriva non è grave */
    $place = null;
    $geo = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
        'latitude' => $lat, 'longitude' => $lon, 'count' => 1, 'language' => 'it',
    ]);
    if (function_exists('curl_init')) {
        $ch = curl_init($geo);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
        $g = curl_exec($ch);
        curl_close($ch);
        if ($g) {
            $gd = json_decode((string)$g, true);
            if (!empty($gd['results'][0]['name'])) $place = sec_str($gd['results'][0]['name'], 120);
        }
    }

    sec_q("UPDATE planner_settings SET weather_enabled = 1, weather_lat = ?, weather_lon = ?, weather_place = ?
           WHERE user_id = ?", [$lat, $lon, $place, $uid], 'ddsi');
    sec_audit('meteo_on', 'ok', $place);

    $data = wx_fetch($lat, $lon);
    sec_json([
        'ok' => true, 'enabled' => true, 'place' => $place,
        'fonte' => WX_FONTE, 'fonte_url' => WX_FONTE_URL,
        'giorni' => $data ? wx_giorni($data) : [],
    ]);
}

if ($ajax === 'disable') {
    sec_q("UPDATE planner_settings SET weather_enabled = 0 WHERE user_id = ?", [$uid], 'i');
    /* le coordinate restano, così riattivandolo non si richiede il permesso */
    sec_json(['ok' => true, 'enabled' => false]);
}

sec_die_json('Richiesta non riconosciuta.', 404, 'unknown_action');
