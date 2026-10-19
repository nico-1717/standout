<?php
/* =====================================================================
   STANDOUT — PLANNER: pl_luoghi.php
   Luoghi: ricerca (Google Places, OpenStreetMap di scorta), cache e rubrica dei posti di tutti i giorni.
   Caricato da lib/planner/planner_lib.php: non si include da solo.
   ===================================================================== */

/* =====================================================================
   LUOGHI — ricerca su Google Places, con OpenStreetMap come rete di scorta

   PERCHÉ SI È CAMBIATO SERVIZIO. Nominatim è un geocodificatore di indirizzi:
   sa dov'è una via, non sa che esiste un ristorante che si chiama in un certo
   modo, e sui civici copre solo quello che gli volontari di OSM hanno mappato.
   Cercando i posti per nome non si trovava quasi niente.

   COME FUNZIONA ADESSO. Due passaggi, come li fa Google Maps:
   1. mentre si scrive, `places:autocomplete` propone i posti (solo nomi e
      identificativi, nessuna coordinata: è la chiamata a buon mercato);
   2. quando se ne sceglie uno, `places/{id}` restituisce le coordinate.
   Il nome da mostrare lo abbiamo già dal primo passaggio, quindi al secondo
   chiediamo solo indirizzo e posizione: sono campi della fascia "Essentials",
   la meno cara, mentre il nome commerciale starebbe in quella sopra.

   Le chiamate partono dal server e non dal browser, come per il meteo: la
   chiave resta qui dentro e l'indirizzo IP di chi cerca non arriva a Google.
   ===================================================================== */

/**
 * La cache dei luoghi è facoltativa: se la tabella non c'è (migrazione non
 * eseguita) la ricerca funziona lo stesso, solo senza risparmiare chiamate.
 * Meglio una funzione più lenta che una pagina che smette di funzionare
 * perché manca una ALTER TABLE.
 */
function pl_place_cache_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'place_cache'");
        $c_e = !empty($r);
    }
    return $c_e;
}

/** Chiave della cache: la stessa ricerca scritta con maiuscole o spazi
    diversi non deve contare come una ricerca nuova. */
function pl_place_key(string $q): string {
    return sha1(mb_strtolower(trim($q), 'UTF-8'));
}

/** Legge dalla cache, se la tabella c'è e la risposta non è troppo vecchia. */
function pl_place_cache_get(string $key, int $giorni): ?array {
    if (!pl_place_cache_c_e()) return null;
    /* I giorni si scrivono dentro la query e non come segnaposto: non è un
       dato che arriva dal browser (lo decide il codice qui sopra), e alcuni
       driver storcono il naso su un segnaposto dentro INTERVAL. */
    $giorni = max(1, min(365, $giorni));
    $row = sec_one("SELECT payload FROM place_cache
                    WHERE q_key = ? AND fetched_at > (NOW() - INTERVAL " . (int)$giorni . " DAY)", [$key], 's');
    if (!$row) return null;
    $c = json_decode($row['payload'], true);
    return is_array($c) ? $c : null;
}

function pl_place_cache_put(string $key, array $dati): void {
    if (!pl_place_cache_c_e()) return;
    sec_q("INSERT INTO place_cache (q_key, payload) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
          [$key, json_encode($dati, JSON_UNESCAPED_UNICODE)], 'ss');
    /* pulizia saltuaria: le ricerche vecchie non servono più a nessuno, e le
       condizioni di Google chiedono di non conservare a lungo i dati dei posti
       (fa eccezione l'identificativo, che infatti finisce sull'evento). */
    if (random_int(1, 50) === 1) {
        sec_q("DELETE FROM place_cache WHERE fetched_at < (NOW() - INTERVAL 30 DAY)");
    }
}

/** Una chiamata sola a Google, in JSON. Restituisce null se qualcosa va storto:
    chi chiama decide cosa fare, e in ogni caso la pagina non deve rompersi. */
function pl_google_call(string $url, ?array $body, array $headers): ?array {
    $raw = null;
    $headers[] = 'Accept: application/json';
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('luoghi', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => $body !== null ? 'POST' : 'GET',
            'timeout' => 8, 'ignore_errors' => true,
            'header'  => implode("\r\n", $headers) . "\r\n",
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return null;
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return null;
    /* Google dice cosa non gli è piaciuto: finisce nel registro degli errori,
       se no una chiave sbagliata sembra semplicemente "nessun risultato". */
    if (isset($data['error'])) {
        sec_log_error('luoghi', 'Google: ' . ($data['error']['message'] ?? 'errore'));
        return null;
    }
    return $data;
}

/**
 * I suggerimenti mentre si scrive. Niente coordinate: quelle costano una
 * chiamata a parte e si chiedono solo per il posto che viene davvero scelto.
 */
function pl_place_google(string $q): array {
    $key = pl_place_key('g:' . $q);
    /* Cache corta: è una scorciatoia per chi torna sulla stessa ricerca dopo
       un attimo, non un archivio. */
    $c = pl_place_cache_get($key, 1);
    if ($c !== null) return $c;

    $data = pl_google_call(
        'https://places.googleapis.com/v1/places:autocomplete',
        /* la lingua dei risultati segue quella scelta: prima "Lombardia, Italia"
           compariva cosi' anche in inglese. regionCode resta 'it' perche' indica
           da dove si cerca, non in che lingua rispondere. */
        ['input' => $q, 'languageCode' => standout_lingua(), 'regionCode' => 'it'],
        ['X-Goog-Api-Key: ' . GOOGLE_PLACES_KEY]
    );
    if ($data === null) return [];

    $out = [];
    foreach (($data['suggestions'] ?? []) as $s) {
        $p = $s['placePrediction'] ?? null;
        if (!$p || empty($p['placeId'])) continue;
        $main = $p['structuredFormat']['mainText']['text'] ?? ($p['text']['text'] ?? '');
        $sec  = $p['structuredFormat']['secondaryText']['text'] ?? '';
        if ($main === '') continue;
        $out[] = [
            'name'   => mb_substr($main, 0, 120, 'UTF-8'),
            'detail' => mb_substr($sec, 0, 200, 'UTF-8'),
            'full'   => mb_substr(trim($main . ($sec !== '' ? ', ' . $sec : '')), 0, 255, 'UTF-8'),
            /* le coordinate arrivano dopo, con pl_place_punto() */
            'lat'    => null,
            'lon'    => null,
            'ref'    => (string)$p['placeId'],
        ];
    }
    pl_place_cache_put($key, $out);
    return $out;
}

/**
 * Le coordinate del posto scelto. Si chiedono solo i campi della fascia
 * economica (posizione e indirizzo): il nome ce l'ha già la pagina, presa dal
 * suggerimento, e chiederlo qui farebbe scivolare la chiamata in una fascia
 * più cara per un dato che abbiamo già.
 */
function pl_place_punto(string $placeId): ?array {
    $key = pl_place_key('p:' . $placeId);
    $c = pl_place_cache_get($key, 30);
    if ($c !== null) return $c;

    $data = pl_google_call(
        'https://places.googleapis.com/v1/places/' . rawurlencode($placeId) . '?languageCode=' . rawurlencode(standout_lingua()),
        null,
        ['X-Goog-Api-Key: ' . GOOGLE_PLACES_KEY,
         'X-Goog-FieldMask: id,location,formattedAddress']
    );
    if ($data === null || !isset($data['location']['latitude'])) return null;

    $punto = [
        'lat'  => round((float)$data['location']['latitude'], 7),
        'lon'  => round((float)$data['location']['longitude'], 7),
        'full' => mb_substr((string)($data['formattedAddress'] ?? ''), 0, 255, 'UTF-8'),
        'ref'  => (string)($data['id'] ?? $placeId),
    ];
    pl_place_cache_put($key, $punto);
    return $punto;
}

/** Con la chiave si va su Google; senza, si continua con OpenStreetMap come
    prima. Serve il giorno in cui la chiave scade o viene tolta: meglio una
    ricerca meno brava che un campo che non risponde più. */
function pl_place_lookup(string $q): array {
    if (defined('GOOGLE_PLACES_KEY') && GOOGLE_PLACES_KEY !== '') return pl_place_google($q);
    return pl_place_osm($q);
}

function pl_place_osm(string $q): array {
    $key = pl_place_key($q);

    $c = pl_place_cache_get($key, 30);
    if ($c !== null) return $c;

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'              => $q,
        'format'         => 'jsonv2',
        'addressdetails' => 1,
        'limit'          => 6,
        'accept-language'=> 'it',
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
            /* Nominatim rifiuta chi non si presenta: è nelle sue condizioni
               d'uso, non un dettaglio facoltativo. */
            CURLOPT_USERAGENT      => 'Standout planner (uso personale)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) sec_log_error('luoghi', curl_error($ch));
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 8, 'ignore_errors' => true,
            'header'  => "User-Agent: Standout planner (uso personale)\r\nAccept: application/json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return [];

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return [];

    $out = [];
    foreach ($data as $p) {
        if (!isset($p['lat'], $p['lon'])) continue;
        $completo = (string)($p['display_name'] ?? '');
        /* Nominatim restituisce il nome per esteso, con nazione e CAP: come
           titolo del suggerimento tengo solo la prima parte, il resto va
           sotto in piccolo. Così l'elenco resta leggibile sul telefono. */
        $pezzi  = array_map('trim', explode(',', $completo));
        $titolo = ($p['name'] ?? '') !== '' ? (string)$p['name'] : (string)array_shift($pezzi);
        if (($p['name'] ?? '') !== '' && count($pezzi) && $pezzi[0] === $p['name']) array_shift($pezzi);
        $out[] = [
            'name'   => mb_substr($titolo, 0, 120, 'UTF-8'),
            'detail' => mb_substr(implode(', ', $pezzi), 0, 200, 'UTF-8'),
            'full'   => mb_substr($completo, 0, 255, 'UTF-8'),
            'lat'    => round((float)$p['lat'], 7),
            'lon'    => round((float)$p['lon'], 7),
            /* riferimento stabile del luogo: serve a ritrovarlo domani anche
               se il nome cambia (osm_type + osm_id sono l'identificativo) */
            'ref'    => isset($p['osm_type'], $p['osm_id'])
                        ? substr((string)$p['osm_type'], 0, 1) . (int)$p['osm_id'] : null,
        ];
    }

    pl_place_cache_put($key, $out);
    return $out;
}

/* ---------------------------------------------------------------------
   RUBRICA DEI LUOGHI

   I posti di tutti i giorni (casa, lavoro, palestra) non si cercano ogni
   volta: si salvano una volta e si riusano. Le parole d'aggancio servono a
   compilarli da soli quando compaiono nel titolo dell'evento.

   Come per la cache, la tabella è facoltativa: se la migrazione non è stata
   eseguita la rubrica resta vuota e il resto del planner non se ne accorge.
   --------------------------------------------------------------------- */
function pl_places_c_e(): bool {
    static $c_e = null;
    if ($c_e === null) {
        $r = sec_one("SHOW TABLES LIKE 'planner_places'");
        $c_e = !empty($r);
    }
    return $c_e;
}

function pl_places(int $userId): array {
    if (!pl_places_c_e()) return [];
    $rows = sec_all("SELECT id, label, keywords, place_name, address, place_lat, place_lon, place_ref
                     FROM planner_places WHERE user_id = ? ORDER BY sort_order, id", [$userId], 'i');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int)$r['id'],
            'label'    => $r['label'],
            'keywords' => $r['keywords'],
            'name'     => $r['place_name'],
            'address'  => $r['address'],
            'lat'      => $r['place_lat'] !== null ? (float)$r['place_lat'] : null,
            'lon'      => $r['place_lon'] !== null ? (float)$r['place_lon'] : null,
            'ref'      => $r['place_ref'],
        ];
    }
    return $out;
}
