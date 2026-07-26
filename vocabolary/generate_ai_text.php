<?php
require_once 'config.php';
require_once 'auth_check.php';
require_once 'piano_helpers.php';

header('Content-Type: application/json');

$current_user_id = $utente_corrente['id'];

if (!isset($_POST['word']) || trim($_POST['word']) === '') {
    echo json_encode(["success" => false, "error_type" => "unknown", "error" => "Nessuna parola fornita"]);
    exit;
}

// Stima prudenziale dei token richiesti da questa chiamata (prompt + risposta),
// usata solo per il controllo limite PRIMA di consumare la chiamata IA vera e propria.
$STIMA_TOKEN = 400;
if (!nisama_verifica_limite($current_user_id, 'token', $STIMA_TOKEN)) {
    echo json_encode(["success" => false, "error_type" => "limite_piano", "message" => "Hai raggiunto il limite mensile di generazioni testo del tuo piano."]);
    exit;
}

$word = $_POST['word'];
$apiKey = GEMINI_API_KEY;

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

$prompt = "Sei un assistente per l'apprendimento dell'inglese. Rispondi ESCLUSIVAMENTE in formato JSON puro, senza markdown, senza virgolette all'inizio o alla fine, solo la struttura JSON. 
Fornisci i dati per la parola inglese '$word'. 
Il JSON deve avere ESATTAMENTE queste 3 chiavi:
- \"description_it\": la traduzione italiana (singola parola o breve frase).
- \"meaning\": una spiegazione semplice in inglese (massimo 15 parole).
- \"example\": una singola frase di esempio in inglese dove la parola '$word' (e le sue declinazioni/coniugazioni) è sostituita da un singolo underscore '_'. Esempio: 'I like to _'.";

$data = [
    "contents" => [["parts" => [["text" => $prompt]]]]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    // Il NOSTRO server non è riuscito a raggiungere Google (timeout, DNS, SSL...)
    $isTimeout = in_array($curl_errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST]);
    echo json_encode([
        "success" => false,
        "error_type" => $isTimeout ? "timeout" : "server",
        "error" => "Errore cURL (" . $curl_errno . "): " . ($curl_error !== '' ? $curl_error : "connessione al servizio non riuscita")
    ]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $rawText = $result['candidates'][0]['content']['parts'][0]['text'];
    $cleanJson = trim(str_replace(['```json', '```'], '', $rawText));
    $parsed = json_decode($cleanJson, true);

    // Aggiorna il consumo mensile con i token realmente usati (se Google li riporta),
    // altrimenti ricade sulla stima prudenziale fatta prima della chiamata.
    $tokenUsati = isset($result['usageMetadata']['totalTokenCount']) ? (int)$result['usageMetadata']['totalTokenCount'] : $STIMA_TOKEN;
    nisama_incrementa_uso($current_user_id, 'token', $tokenUsati);

    if (is_array($parsed)) {
        $parsed['success'] = true;
        echo json_encode($parsed);
    } else {
        // Il modello non ha restituito JSON valido: passiamo comunque il testo grezzo
        echo json_encode(["success" => false, "error_type" => "invalid_format", "error" => "Risposta non in formato JSON valido.", "raw" => $cleanJson]);
    }
} else {
    $apiError = isset($result['error']['message']) ? $result['error']['message'] : "Errore sconosciuto da Google";
    $lowerErr = strtolower($apiError);
    $isQuota = (strpos($lowerErr, 'quota') !== false || strpos($lowerErr, 'resource_exhausted') !== false || strpos($lowerErr, 'rate limit') !== false);
    echo json_encode([
        "success" => false,
        "error_type" => $isQuota ? "quota_service" : "server",
        "error" => $apiError
    ]);
}