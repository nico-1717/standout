<?php
/* =====================================================================
   STANDOUT — METEO
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
     meteo_api.php?ajax=status       (GET)   stato + previsioni se attivo
     meteo_api.php?ajax=enable       (POST)  lat, lon → attiva
     meteo_api.php?ajax=disable      (POST)
     meteo_api.php?ajax=place_search (POST)  q → luoghi che si chiamano così
     meteo_api.php?ajax=preview      (POST)  lat, lon → previsione per quel
                                             punto SENZA toccare le impostazioni
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'meteo', 0, 60, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';
/* Solo le letture possono arrivare con GET: il resto è POST, dove il token CSRF
   viene davvero controllato (vedi sec_solo_post_se_scrive in security_core.php). */
sec_solo_post_se_scrive($ajax, ['status', 'place_search', 'preview']);

define('WX_GIORNI', 10);       // oggi più nove: Open-Meteo ne dà fino a 16 senza costi in più
define('WX_CACHE_MINUTI', 60);
define('WX_FONTE', 'Open-Meteo');
define('WX_FONTE_URL', 'https://open-meteo.com');

/* --------------------------------------------------------------------
   Codici meteo dell'Organizzazione Meteorologica Mondiale, tradotti.
   -------------------------------------------------------------------- */
function wx_codice(int $c): array {
    $m = [
        0  => [t('planner.wx_sereno'), 'sole'],
        1  => [t('planner.wx_poco_nuvoloso'), 'sole-nubi'],
        2  => [t('planner.wx_parz_nuvoloso'), 'sole-nubi'],
        3  => [t('planner.wx_coperto'), 'nubi'],
        45 => [t('planner.wx_nebbia'), 'nebbia'],
        48 => [t('planner.wx_nebbia_brina'), 'nebbia'],
        51 => [t('planner.wx_piovig_leggera'), 'pioggia-leggera'],
        53 => [t('planner.wx_pioviggine'), 'pioggia-leggera'],
        55 => [t('planner.wx_piovig_intensa'), 'pioggia'],
        56 => [t('planner.wx_piovig_gelata'), 'pioggia'],
        57 => [t('planner.wx_piovig_gelata_int'), 'pioggia'],
        61 => [t('planner.wx_pioggia_leggera'), 'pioggia-leggera'],
        63 => [t('planner.wx_pioggia'), 'pioggia'],
        65 => [t('planner.wx_pioggia_forte'), 'pioggia-forte'],
        66 => [t('planner.wx_pioggia_gelata'), 'pioggia'],
        67 => [t('planner.wx_pioggia_gelata_forte'), 'pioggia-forte'],
        71 => [t('planner.wx_neve_leggera'), 'neve'],
        73 => [t('planner.wx_neve'), 'neve'],
        75 => [t('planner.wx_neve_abbondante'), 'neve'],
        77 => [t('planner.wx_granelli'), 'neve'],
        80 => [t('planner.wx_rovesci_leggeri'), 'pioggia-leggera'],
        81 => [t('planner.wx_rovesci'), 'pioggia'],
        82 => [t('planner.wx_rovesci_violenti'), 'pioggia-forte'],
        85 => [t('planner.wx_rovesci_neve'), 'neve'],
        86 => [t('planner.wx_rovesci_neve_forti'), 'neve'],
        95 => [t('planner.wx_temporale'), 'temporale'],
        96 => [t('planner.wx_temporale_grandine'), 'temporale'],
        99 => [t('planner.wx_temporale_grandine_forte'), 'temporale'],
    ];
    return $m[$c] ?? [t('planner.wx_non_disponibile'), 'nubi'];
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
       giornaliera, e abbastanza largo per condividere la cache.
       Il prefisso è la versione della richiesta: avendo aggiunto campi, una
       risposta salvata prima sarebbe incompleta e mostrerebbe caselle vuote
       per un'ora. Cambiando prefisso, le vecchie semplicemente non si
       trovano più e scadono da sole. */
    return 'v2:' . number_format($lat, 2, '.', '') . ',' . number_format($lon, 2, '.', '');
}

function wx_fetch(float $lat, float $lon): ?array {
    /* Quanti giorni si chiedono fa parte della chiave: alzando WX_GIORNI la
       risposta vecchia in archivio non è più quella giusta, e senza questo
       si continuerebbe a servire cinque giorni finché non scade da sé. */
    $key = wx_grid($lat, $lon) . '|g' . WX_GIORNI;

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
        'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,'
                         . 'apparent_temperature_max,apparent_temperature_min,'
                         . 'precipitation_probability_max,precipitation_sum,precipitation_hours,'
                         . 'wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant,'
                         . 'uv_index_max,sunrise,sunset,sunshine_duration',
        'hourly'        => 'weather_code,precipitation_probability,precipitation,temperature_2m,'
                         . 'apparent_temperature,relative_humidity_2m,wind_speed_10m',
        'current'       => 'weather_code,temperature_2m,apparent_temperature,relative_humidity_2m,'
                         . 'wind_speed_10m,precipitation,is_day',
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

/** Da 250° al punto cardinale: "ovest" si legge, "250°" va interpretato. */
function wx_direzione(?float $gradi): ?string {
    if ($gradi === null) return null;
    $rosa = [t('planner.dir_nord'), t('planner.dir_nord_est'), t('planner.dir_est'), t('planner.dir_sud_est'),
             t('planner.dir_sud'), t('planner.dir_sud_ovest'), t('planner.dir_ovest'), t('planner.dir_nord_ovest')];
    return $rosa[((int)round($gradi / 45)) % 8];
}

/**
 * Le previsioni ora per ora, raggruppate per giorno.
 * Le chiavi sono corte di proposito: sono 24 voci per ogni giorno e i nomi
 * per esteso peserebbero più dei dati che accompagnano. Il significato è
 * documentato qui e usato solo dal planner.
 *   h = ora, c = codice, i = icona, l = descrizione, t = temperatura,
 *   ap = percepita, p = probabilità di pioggia, mm = pioggia prevista,
 *   u = umidità, v = vento
 */
function wx_ore(array $data): array {
    if (empty($data['hourly']['time'])) return [];
    $o = $data['hourly'];
    $out = [];
    foreach ($o['time'] as $i => $t) {
        $giorno = substr($t, 0, 10);
        $code = (int)($o['weather_code'][$i] ?? 3);
        [$testo, $icona] = wx_codice($code);
        $out[$giorno][] = [
            'h'  => substr($t, 11, 5),
            'c'  => $code,
            'i'  => $icona,
            'l'  => $testo,
            't'  => isset($o['temperature_2m'][$i]) ? round($o['temperature_2m'][$i]) : null,
            'ap' => isset($o['apparent_temperature'][$i]) ? round($o['apparent_temperature'][$i]) : null,
            'p'  => isset($o['precipitation_probability'][$i]) ? (int)$o['precipitation_probability'][$i] : null,
            'mm' => isset($o['precipitation'][$i]) ? round((float)$o['precipitation'][$i], 1) : null,
            'u'  => isset($o['relative_humidity_2m'][$i]) ? (int)$o['relative_humidity_2m'][$i] : null,
            'v'  => isset($o['wind_speed_10m'][$i]) ? round($o['wind_speed_10m'][$i]) : null,
        ];
    }
    return $out;
}

/** Le condizioni di adesso, quando il servizio le manda. */
/* ---------------------------------------------------------------------
   Il nome del posto da due coordinate.

   Qui c'era una chiamata a /v1/search di Open-Meteo passandogli latitudine e
   longitudine: quella però è la ricerca *per nome*, non sa che farsene delle
   coordinate e non risponde niente. È il motivo per cui `weather_place`
   restava vuoto e il popup non diceva mai su quale posto stava calcolando.

   Adesso si usa la ricerca all'incontrario di Nominatim, che è già il
   ripiego dei luoghi del planner: nessun servizio nuovo da autorizzare.
   Nominatim vuole che ci si presenti — è nelle sue condizioni d'uso — e non
   vuole essere tempestato, quindi la risposta finisce nella cache del meteo,
   compreso il caso in cui non arrivi niente: così un posto senza nome viene
   richiesto una volta al giorno e non a ogni apertura della pagina.
   --------------------------------------------------------------------- */
function wx_place_nome(float $lat, float $lon): ?string {
    $key = 'nome:' . wx_grid($lat, $lon);

    $row = sec_one("SELECT payload FROM weather_cache
                    WHERE grid_key = ? AND fetched_at > (NOW() - INTERVAL 1440 MINUTE)",
                   [$key], 's');
    if ($row) {
        $c = json_decode($row['payload'], true);
        return (is_array($c) && !empty($c['place'])) ? $c['place'] : null;
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat'             => number_format($lat, 5, '.', ''),
        'lon'             => number_format($lon, 5, '.', ''),
        'format'          => 'jsonv2',
        'zoom'            => 12,          // paese o quartiere: la via non serve
        'addressdetails'  => 1,
        'accept-language' => 'it',
    ]);

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Standout planner (uso personale)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('meteo_luogo', curl_error($ch));
        curl_close($ch);
    }

    $nome = null;
    if ($raw) {
        $d = json_decode((string)$raw, true);
        $a = is_array($d) && isset($d['address']) && is_array($d['address']) ? $d['address'] : [];
        /* dal più preciso al più largo: si tiene il primo che c'è */
        foreach (['village', 'town', 'city', 'municipality', 'county', 'state'] as $campo) {
            if (!empty($a[$campo])) { $nome = sec_str((string)$a[$campo], 120); break; }
        }
        if ($nome === null && !empty($d['name'])) $nome = sec_str((string)$d['name'], 120);
    }

    sec_q("INSERT INTO weather_cache (grid_key, payload) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
          [$key, json_encode(['place' => $nome], JSON_UNESCAPED_UNICODE)], 'ss');
    return $nome;
}

/* --------------------------------------------------------------------
   Ricerca di un luogo per nome (per il cambio posizione dal popup)

   Qui si usa /v1/search di Open-Meteo, che è la ricerca *per nome* e come
   tale funziona benissimo: era usarla all'incontrario, passandole delle
   coordinate, a non dare mai risposta. Per il senso opposto — coordinate
   verso nome — resta Nominatim, qui sopra.

   Si preferisce Open-Meteo a Nominatim perché è già la fonte delle
   previsioni (nessun servizio nuovo, nessuna condizione d'uso in più da
   rispettare) e perché restituisce già latitudine, longitudine, regione e
   paese, cioè esattamente quello che serve per distinguere due paesi che si
   chiamano uguale.

   La risposta finisce in cache per un giorno: i luoghi non si spostano, e
   chi scrive «mil», «mila», «milan», «milano» farebbe quattro chiamate
   per la stessa cosa. La chiave è un riassunto della domanda perché
   `grid_key` è lungo 32 caratteri e un nome di città lungo non ci starebbe.
   -------------------------------------------------------------------- */
function wx_cerca(string $q): array {
    $key = 'q:' . substr(md5(mb_strtolower($q, 'UTF-8')), 0, 24);

    $row = sec_one("SELECT payload FROM weather_cache
                    WHERE grid_key = ? AND fetched_at > (NOW() - INTERVAL 1440 MINUTE)",
                   [$key], 's');
    if ($row) {
        $c = json_decode($row['payload'], true);
        if (is_array($c) && isset($c['luoghi'])) return $c['luoghi'];
    }

    $url = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
        'name'     => $q,
        'count'    => 8,
        /* la lingua dei nomi di luogo segue quella scelta: prima "Lombardia, Italia"
           usciva cosi' anche in inglese */
        'language' => standout_lingua(),
        'format'   => 'json',
    ]);

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('meteo_cerca', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    $luoghi = [];
    if ($raw) {
        $d = json_decode((string)$raw, true);
        $lista = (is_array($d) && !empty($d['results']) && is_array($d['results'])) ? $d['results'] : [];
        foreach ($lista as $r) {
            if (!isset($r['latitude'], $r['longitude'], $r['name'])) continue;
            /* Regione e paese non sono un vezzo: senza, in un elenco di otto
               «San Giovanni» non si capisce quale sia il proprio. */
            $dove = [];
            if (!empty($r['admin1']))  $dove[] = sec_str((string)$r['admin1'], 80);
            if (!empty($r['country'])) $dove[] = sec_str((string)$r['country'], 80);
            $luoghi[] = [
                'nome'  => sec_str((string)$r['name'], 120),
                'dove'  => implode(', ', $dove),
                'lat'   => round((float)$r['latitude'], 4),
                'lon'   => round((float)$r['longitude'], 4),
            ];
        }
    }

    /* si salva anche l'elenco vuoto: una parola che non è un posto non deve
       far ripartire la chiamata a ogni lettera battuta di nuovo */
    sec_q("INSERT INTO weather_cache (grid_key, payload) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
          [$key, json_encode(['luoghi' => $luoghi], JSON_UNESCAPED_UNICODE)], 'ss');
    return $luoghi;
}

function wx_adesso(array $data): ?array {
    if (empty($data['current'])) return null;
    $c = $data['current'];
    $code = (int)($c['weather_code'] ?? 3);
    [$testo, $icona] = wx_codice($code);
    return [
        'ora'       => isset($c['time']) ? substr((string)$c['time'], 11, 5) : null,
        'code'      => $code,
        'label'     => $testo,
        'icon'      => $icona,
        'temp'      => isset($c['temperature_2m']) ? round($c['temperature_2m']) : null,
        'percepita' => isset($c['apparent_temperature']) ? round($c['apparent_temperature']) : null,
        'umidita'   => isset($c['relative_humidity_2m']) ? (int)$c['relative_humidity_2m'] : null,
        'vento'     => isset($c['wind_speed_10m']) ? round($c['wind_speed_10m']) : null,
        'pioggia_mm'=> isset($c['precipitation']) ? round((float)$c['precipitation'], 1) : null,
        'giorno'    => isset($c['is_day']) ? ((int)$c['is_day'] === 1) : null,
    ];
}

/** Dalla risposta grezza ai giorni pronti da mostrare. */
function wx_giorni(array $data): array {
    $out = [];
    $d = $data['daily'];
    $ore = wx_ore($data);
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
            /* dati aggiunti: il servizio li dava già, li stavamo buttando via */
            'percepita_max' => isset($d['apparent_temperature_max'][$i]) ? round($d['apparent_temperature_max'][$i]) : null,
            'percepita_min' => isset($d['apparent_temperature_min'][$i]) ? round($d['apparent_temperature_min'][$i]) : null,
            'pioggia_mm'    => isset($d['precipitation_sum'][$i]) ? round((float)$d['precipitation_sum'][$i], 1) : null,
            'pioggia_ore'   => isset($d['precipitation_hours'][$i]) ? (int)$d['precipitation_hours'][$i] : null,
            'raffiche'      => isset($d['wind_gusts_10m_max'][$i]) ? round($d['wind_gusts_10m_max'][$i]) : null,
            'vento_da'      => wx_direzione(isset($d['wind_direction_10m_dominant'][$i]) ? (float)$d['wind_direction_10m_dominant'][$i] : null),
            'uv'            => isset($d['uv_index_max'][$i]) ? round((float)$d['uv_index_max'][$i], 1) : null,
            'alba'          => isset($d['sunrise'][$i]) ? substr((string)$d['sunrise'][$i], 11, 5) : null,
            'tramonto'      => isset($d['sunset'][$i]) ? substr((string)$d['sunset'][$i], 11, 5) : null,
            /* il servizio dà i secondi di sole: in ore e minuti si capisce */
            'sole_min'      => isset($d['sunshine_duration'][$i]) ? (int)round($d['sunshine_duration'][$i] / 60) : null,
            'ore'           => $ore[$giorno] ?? [],
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

    /* Chi ha acceso il meteo prima che la ricerca del nome funzionasse ha in
       archivio un posto senza nome: si riprova qui, una volta, e da allora
       resta scritto. Senza questo, l'unico modo per rimediare sarebbe
       spegnere e riaccendere il meteo. */
    $place = $s['weather_place'] ?? null;
    if ($on && ($place === null || $place === '')) {
        $place = wx_place_nome((float)$s['weather_lat'], (float)$s['weather_lon']);
        if ($place !== null) {
            sec_q("UPDATE planner_settings SET weather_place = ? WHERE user_id = ?", [$place, $uid], 'si');
        }
    }

    $risposta = [
        'ok'      => true,
        'enabled' => $on,
        'place'   => $place,
        /* le coordinate viaggiano sempre: se il nome non si trova, dire
           "46,07 / 11,12" è comunque una risposta a "su dove sta calcolando" */
        'lat'     => $on ? (float)$s['weather_lat'] : null,
        'lon'     => $on ? (float)$s['weather_lon'] : null,
        'fonte'   => WX_FONTE,
        'fonte_url' => WX_FONTE_URL,
        'giorni'  => [],
        'nota_affidabilita' => t('planner.wx_nota_affidabilita'),
    ];
    if ($on) {
        $data = wx_fetch((float)$s['weather_lat'], (float)$s['weather_lon']);
        if ($data) {
            $risposta['giorni'] = wx_giorni($data);
            $risposta['adesso'] = wx_adesso($data);
        } else $risposta['errore'] = t('planner.wx_non_raggiungibili');
    }
    sec_json($risposta);
}

if ($ajax === 'enable') {
    $lat = sec_float(sec_in('lat'), -90, 90, 999);
    $lon = sec_float(sec_in('lon'), -180, 180, 999);
    if ($lat === 999.0 || $lon === 999.0) sec_die_json(t('planner.wx_posizione_non_valida'), 422, 'invalid');

    $place = wx_place_nome($lat, $lon);

    sec_q("UPDATE planner_settings SET weather_enabled = 1, weather_lat = ?, weather_lon = ?, weather_place = ?
           WHERE user_id = ?", [$lat, $lon, $place, $uid], 'ddsi');
    sec_audit('meteo_on', 'ok', $place);

    $data = wx_fetch($lat, $lon);
    sec_json([
        'ok' => true, 'enabled' => true, 'place' => $place, 'lat' => $lat, 'lon' => $lon,
        'fonte' => WX_FONTE, 'fonte_url' => WX_FONTE_URL,
        'giorni' => $data ? wx_giorni($data) : [],
        'adesso' => $data ? wx_adesso($data) : null,
        'nota_affidabilita' => t('planner.wx_nota_affidabilita'),
    ]);
}

if ($ajax === 'disable') {
    sec_q("UPDATE planner_settings SET weather_enabled = 0 WHERE user_id = ?", [$uid], 'i');
    /* le coordinate restano, così riattivandolo non si richiede il permesso */
    sec_json(['ok' => true, 'enabled' => false]);
}

/* ---------------- ricerca del luogo scritto a mano ----------------
   Primo passo del cambio posizione dal popup: si scrive un nome e si
   ricevono i posti che si chiamano così. Nessuna scrittura in archivio: qui
   non si decide niente, si guarda soltanto. */
if ($ajax === 'place_search') {
    /* stessa briglia della ricerca luoghi del planner: la chiamata è verso
       un servizio esterno, e chi la ripete a raffica la paga a tutti */
    sec_guard_write($uid, 'meteo_cerca', 0, 40, 60);
    $q = sec_str(sec_in('q'), 120);
    $q = trim(preg_replace('/\s+/u', ' ', $q));
    /* sotto le tre lettere la ricerca restituisce mezzo mondo e non serve
       a nessuno: si risponde vuoto senza nemmeno uscire di casa */
    if (mb_strlen($q, 'UTF-8') < 3) sec_json(['ok' => true, 'luoghi' => []]);
    sec_json(['ok' => true, 'luoghi' => wx_cerca($q)]);
}

/* ---------------- previsione per un punto qualsiasi ----------------
   Secondo passo: la previsione per il posto scelto a mano.

   DECISIONE (vale anche per il codice del browser, dove è ripetuta):
   il luogo scelto a mano NON si salva in `planner_settings`. Questo
   endpoint non scrive niente: legge, e basta. Vale per la pagina aperta
   in quel momento e finisce ricaricandola, oppure spegnendo e riaccendendo
   il meteo. È controintuitivo — di solito quello che si sceglie resta —
   quindi va detto: `weather_lat/lon/place` continuano a descrivere *dove si
   è davvero*, e sono quelle che decidono la previsione alla prossima
   apertura. Se un giorno si volesse rendere permanente il cambio, il posto
   dove metterlo è `enable`, non qui: mescolare le due cose vorrebbe dire
   perdere per sempre la posizione vera dopo una sbirciata a una città
   qualsiasi.

   La cache invece è condivisa come sempre: sbirciare che tempo fa a Lisbona
   costa una chiamata sola anche se lo fanno in dieci. */
if ($ajax === 'preview') {
    $lat = sec_float(sec_in('lat'), -90, 90, 999);
    $lon = sec_float(sec_in('lon'), -180, 180, 999);
    if ($lat === 999.0 || $lon === 999.0) sec_die_json(t('planner.wx_posizione_non_valida'), 422, 'invalid');

    /* Il nome arriva dal browser ma si rivalida lo stesso, come tutto il
       resto: l'abbiamo mandato noi un attimo prima, e non basta. Se manca,
       si chiede a Nominatim come per la posizione rilevata. */
    $place = sec_str(sec_in('place'), 120);
    if ($place === '') $place = wx_place_nome($lat, $lon);

    $data = wx_fetch($lat, $lon);
    sec_json([
        'ok' => true, 'enabled' => true, t('planner.wx_manuale') => true,
        'place' => $place, 'lat' => $lat, 'lon' => $lon,
        'fonte' => WX_FONTE, 'fonte_url' => WX_FONTE_URL,
        'giorni' => $data ? wx_giorni($data) : [],
        'adesso' => $data ? wx_adesso($data) : null,
        'nota_affidabilita' => t('planner.wx_nota_affidabilita'),
    ]);
}

sec_die_json(t('planner.wx_richiesta_non_riconosciuta'), 404, 'unknown_action');