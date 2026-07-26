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

if (!nisama_verifica_limite($current_user_id, 'immagini', 1)) {
    echo json_encode(["success" => false, "error_type" => "limite_piano", "message" => "Hai raggiunto il limite mensile di immagini generate del tuo piano."]);
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


// L'endpoint UFFICIALE per il modello Gemini 2.5 Flash Image
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=" . $apiKey;

// Struttura JSON richiesta da Google
$data = [
    "contents" => [
        [
            "parts" => [
                [ "text" => $prompt ]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 45);

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

$responseData = json_decode($response, true);

// Estraiamo l'immagine dalla risposta di Google
if ($httpcode == 200 && isset($responseData['candidates'][0]['content']['parts'])) {
    $base64Image = null;

    foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['inlineData']['data'])) {
            $base64Image = $part['inlineData']['data'];
            break;
        }
    }

    if ($base64Image) {
        // Immagine generata con successo: aggiorna il consumo mensile del piano
        nisama_incrementa_uso($current_user_id, 'immagini', 1);

        echo json_encode([
            "status" => "success",
            "success" => true,
            "base64" => $base64Image
        ]);
    } else {
        // Il modello ha risposto ma non ha prodotto un'immagine (spesso per policy sui contenuti)
        echo json_encode(["success" => false, "error_type" => "no_image", "error" => "Il modello non ha restituito un'immagine per questa richiesta."]);
    }
} else {
    $errorMsg = "Errore sconosciuto";
    if (isset($responseData['error']['message'])) {
        $errorMsg = $responseData['error']['message'];
    }
    $lowerErr = strtolower($errorMsg);
    $isQuota = ($httpcode == 429 || strpos($lowerErr, 'quota') !== false || strpos($lowerErr, 'resource_exhausted') !== false || strpos($lowerErr, 'rate limit') !== false);
    echo json_encode([
        "success" => false,
        "error_type" => $isQuota ? "quota_service" : "server",
        "error" => "Google API Error ($httpcode): " . $errorMsg
    ]);
}