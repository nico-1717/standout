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

require_once __DIR__ . '/../../lib/planner/pl_meteo.php';


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