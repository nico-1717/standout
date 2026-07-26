<?php
require_once 'config.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

// Versione "Basic" gratuita — usa l'API testuale pubblica di Pollinations al posto
// di Gemini. Nessun controllo di piano/token: è gratis e non consuma la quota Pro.

if (!isset($_POST['word']) || trim($_POST['word']) === '') {
    echo json_encode(["success" => false, "error_type" => "unknown", "error" => "Nessuna parola fornita"]);
    exit;
}

$word = $_POST['word'];

$prompt = "You are an assistant for English vocabulary learning. Reply ONLY with pure JSON, no markdown, no text before or after, just the JSON structure.
Provide the data for the English word '$word'.
The JSON must have EXACTLY these 3 keys:
- \"description_it\": the Italian translation (single word or short phrase).
- \"meaning\": a simple explanation in English (max 15 words).
- \"example\": a single English example sentence where the word '$word' (or its inflected forms) is replaced by a single underscore '_'. Example: 'I like to _'.";

$url = "https://text.pollinations.ai/" . rawurlencode($prompt) . "?json=true";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    $isTimeout = in_array($curl_errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST]);
    echo json_encode(["success" => false, "error_type" => $isTimeout ? "timeout" : "server", "error" => "Errore cURL (" . $curl_errno . "): " . ($curl_error !== '' ? $curl_error : "connessione al servizio non riuscita")]);
    exit;
}

if ($httpcode == 429) {
    echo json_encode(["success" => false, "error_type" => "quota_service", "error" => "Il servizio Pollinations ha raggiunto il limite di richieste (codice 429)."]);
    exit;
}
if ($httpcode != 200) {
    echo json_encode(["success" => false, "error_type" => "server", "error" => "Il server Pollinations ha risposto con codice $httpcode."]);
    exit;
}

// Ripulisce eventuali blocchi markdown ```json ... ``` che il modello a volte aggiunge
$cleanJson = trim(str_replace(['```json', '```'], '', $response));

// L'API a volte restituisce testo prima/dopo il JSON: isoliamo il primo blocco { ... }
if (!preg_match('/\{.*\}/s', $cleanJson, $m)) {
    echo json_encode(["success" => false, "error_type" => "invalid_format", "error" => "Risposta non in formato JSON valido.", "raw" => $cleanJson]);
    exit;
}

$parsed = json_decode($m[0], true);

if (is_array($parsed) && isset($parsed['description_it'])) {
    $parsed['success'] = true;
    echo json_encode($parsed);
} else {
    echo json_encode(["success" => false, "error_type" => "invalid_format", "error" => "Risposta non in formato JSON valido.", "raw" => $cleanJson]);
}