<?php
// Leggiamo SUBITO il corpo della richiesta, prima di qualsiasi require (vedi
// ai_example.php per il perché: php://input si legge una volta sola).
$__raw_input = file_get_contents('php://input');

// Forza la visualizzazione degli errori (se l'hosting lo permette)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'auth_check_api.php';
require_once 'piano_helpers.php';

ob_start(); // Avvia il buffer per evitare crash con JSON su Altervista

// Rete di sicurezza: garantisce comunque un JSON leggibile anche se qualcosa
// va storto in modo fatale più avanti (vedi ai_example.php per i dettagli).
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore interno del server: ' . $err['message'] . ' (riga ' . $err['line'] . ')']);
    }
});

// --- CONTROLLO CHIAVI ---
if (!defined('GEMINI_API_KEY')) { ob_clean(); die(json_encode(['success' => false, 'error' => 'GEMINI_API_KEY non definita in config.php'])); }

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { ob_clean(); die(json_encode(['success' => false, 'error' => 'Errore di connessione al Database.'])); }

$data = json_decode($__raw_input, true);
if (!$data) {
    ob_clean();
    $diag = 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte';
    die(json_encode(['success' => false, 'error' => 'Nessun dato ricevuto dal JavaScript.', 'debug' => $diag]));
}

// PREVENZIONE BUG PHP 8
$mode = isset($data['mode']) ? $data['mode'] : '';
$raw_text = isset($data['text']) ? $data['text'] : '';
$card_id = isset($data['id']) ? intval($data['id']) : 0;

// Questo endpoint gestisce solo 'explain' e 'metaphor'. Per 'read' non c'è nulla da generare,
// non si chiama mai Gemini e non si spende nessun token: niente controllo limite in questo caso.
if ($mode === 'read') {
    ob_clean();
    echo json_encode(['success' => true, 'text' => $raw_text]);
    exit;
}

$col_to_check = ($mode === 'explain') ? 'ai_explanation' : 'ai_metaphor';

// Protezione Database e Cache
$query = "SELECT $col_to_check FROM fc_ai_data WHERE card_id = $card_id";
$result = mysqli_query($conn, $query);

if (!$result) {
    ob_clean();
    die(json_encode(['success' => false, 'error' => 'Errore SQL (Tabella fc_ai_data mancante?): ' . mysqli_error($conn)]));
}

$row = mysqli_fetch_assoc($result);

if ($row && !empty($row[$col_to_check])) {
    // Trovata nel DB, nessuna chiamata esterna necessaria (Nessun token scalato!)
    ob_clean();
    echo json_encode(['success' => true, 'text' => $row[$col_to_check]]);
    exit;
}

// Non in cache: qui davvero interrogheremo Gemini, quindi ORA ha senso controllare il limite,
// usando la stima (il costo reale in token si scopre solo a chiamata avvenuta).
$stima_limite = nisama_verifica_limite_stimato($utente_corrente['id'], 300);
if (!$stima_limite['ok']) {
    ob_clean();
    $msg = ($stima_limite['disponibili'] !== null)
        ? "Questa richiesta costerebbe circa {$stima_limite['stima']} token, ma te ne restano solo {$stima_limite['disponibili']} questo mese."
        : 'Hai raggiunto il limite mensile di Token IA.';
    die(json_encode(['success' => false, 'error' => 'limite_piano', 'message' => $msg, 'stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']]));
}

// Non in cache: interroghiamo Gemini
$prompt = ($mode === 'explain')
    ? "Spiega in modo semplice, discorsivo e chiaro il seguente concetto per uno studente universitario. Tono amichevole. Mantieni le formule matematiche nei dollari ($$). Testo: " . $raw_text
    : "Crea una metafora o un esempio pratico per memorizzare il seguente concetto. Mantieni formule nei dollari ($$). Testo: " . $raw_text;

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;
$payload = json_encode(["contents" => [["parts" => [["text" => $prompt]]]]]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Expect:']);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$tempo_inizio = microtime(true);
$response = curl_exec($ch);
$durata_ms = (int)round((microtime(true) - $tempo_inizio) * 1000);

if (curl_errno($ch)) {
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    ob_clean();
    $msg_errore = ($errno === CURLE_OPERATION_TIMEOUTED || strpos($errstr, 'timed out') !== false)
        ? 'Gemini ci ha messo troppo a rispondere (le API di Google potrebbero essere più lente del solito in questo momento). Riprova tra poco.'
        : 'Errore connessione a Gemini [curl errno ' . $errno . ']: ' . $errstr;
    die(json_encode(['success' => false, 'error' => $msg_errore]));
}
curl_close($ch);

// Registriamo quanto ci ha messo questa chiamata, per stimare i tempi delle prossime
nisama_registra_tempo_ia($mode, $durata_ms);

$json = json_decode($response, true);
if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
    $final_text = $json['candidates'][0]['content']['parts'][0]['text'];

    // Salvataggio sicuro nel DB
    $safe_response = mysqli_real_escape_string($conn, $final_text);
    $sql_insert = "INSERT INTO fc_ai_data (card_id, $col_to_check) VALUES ($card_id, '$safe_response') ON DUPLICATE KEY UPDATE $col_to_check = '$safe_response'";
    if (!mysqli_query($conn, $sql_insert)) {
        ob_clean();
        die(json_encode(['success' => false, 'error' => 'Impossibile salvare la risposta nel DB: ' . mysqli_error($conn)]));
    }

    // ORA scaliamo i token perché abbiamo effettivamente usato l'API
    $token_usati = isset($json['usageMetadata']['totalTokenCount']) ? (int)$json['usageMetadata']['totalTokenCount'] : 300;
    nisama_incrementa_uso($utente_corrente['id'], 'token', $token_usati);

    ob_clean();
    echo json_encode(['success' => true, 'text' => $final_text, 'duration_ms' => $durata_ms]);
    exit;
} else {
    ob_clean();
    die(json_encode(['success' => false, 'error' => 'Risposta anomala da Gemini: ' . $response]));
}