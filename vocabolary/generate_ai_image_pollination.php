<?php
require_once 'config.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

// Versione "Basic" gratuita — usa Pollinations al posto di Gemini/Imagen.
// Nessun controllo di piano/immagini: è gratis e non consuma la quota Pro.

if (!isset($_POST['word']) || trim($_POST['word']) === '') {
    echo json_encode(["success" => false, "error_type" => "unknown", "error" => "Nessuna parola fornita"]);
    exit;
}

$word = $_POST['word'];
$example = isset($_POST['example']) ? trim($_POST['example']) : '';
$directions = isset($_POST['directions']) ? trim($_POST['directions']) : '';
$isLinear = isset($_POST['is_linear']) && $_POST['is_linear'] === '1';

// 1. Prompt di base (solo la parola)
$prompt = "Generate an image which shows clearly the concept of the word '$word'.";

// 2. Aggiunge l'esempio SOLO se c'è la spunta (quindi se non è vuoto) e se non è il trattino basso di default
if ($example !== '' && $example !== '_') {
    $prompt .= " Include this specific setting or context: '$example'.";
}

// 3. Aggiunge le indicazioni aggiuntive se l'utente le ha scritte (corregge il bug nel file PRO)
if ($directions !== '') {
    $prompt .= " Additional directions: $directions.";
}

// 4. Definisce lo stile corretto in base alla spunta "Stile lineare" (corregge il bug nel file BASIC)
$style = $isLinear
    ? " Style: Minimalist flat vector art, vivid colors, white background."
    : " Style: Realistic high-quality photography, hyper-detailed, lifelike.";

// 5. Assembla il prompt finale con la regola restrittiva per il testo
$prompt .= $style . " CRITICAL STRICT RULE: ABSOLUTELY NO TEXT, NO LETTERS, NO NUMBERS, AND NO WORDS IN THE IMAGE.";

$encoded_prompt = rawurlencode($prompt);
$seed = random_int(1, 999999999); // evita risposte cache identiche a ogni generazione

$url = "https://image.pollinations.ai/prompt/" . $encoded_prompt . "?width=800&height=600&nologo=true&private=true&seed=" . $seed;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 45);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    $isTimeout = in_array($curl_errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST]);
    echo json_encode([
        "success" => false,
        "error_type" => $isTimeout ? "timeout" : "server",
        "error" => "Errore cURL (" . $curl_errno . "): " . ($curl_error !== '' ? $curl_error : "connessione al servizio non riuscita")
    ]);
    exit;
}

if ($httpcode == 200 && strlen($response) > 0) {
    echo json_encode([
        "status" => "success",
        "success" => true,
        "base64" => base64_encode($response)
    ]);
} else if ($httpcode == 429) {
    echo json_encode(["success" => false, "error_type" => "quota_service", "error" => "Il servizio Pollinations ha raggiunto il limite di richieste (codice 429)."]);
} else {
    echo json_encode(["success" => false, "error_type" => "server", "error" => "Il server Pollinations ha risposto con codice $httpcode."]);
}