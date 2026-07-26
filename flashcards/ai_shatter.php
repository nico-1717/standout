<?php
// Leggiamo SUBITO il corpo della richiesta, prima di qualsiasi require (vedi
// ai_example.php per il perché: php://input si legge una volta sola).
$__raw_input = file_get_contents('php://input');

require_once 'config.php';
require_once 'auth_check_api.php';
require_once 'piano_helpers.php';

// Inizializza il buffer per nascondere eventuali Warning/Notice di Altervista
ob_start();

// Rete di sicurezza: garantisce comunque un JSON leggibile anche se qualcosa
// va storto in modo fatale più avanti (vedi ai_example.php per i dettagli).
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'cards' => [["q" => "⚠️ Errore interno", "a" => $err['message'] . ' (riga ' . $err['line'] . ')']]]);
    }
});

$stima_limite = nisama_verifica_limite_stimato($utente_corrente['id'], 300);
if (!$stima_limite['ok']) {
    ob_clean();
    $msg = ($stima_limite['disponibili'] !== null)
        ? "Frantumare questa flashcard costerebbe circa {$stima_limite['stima']} token, ma te ne restano solo {$stima_limite['disponibili']} questo mese."
        : 'Hai raggiunto il limite mensile di Token IA.';
    die(json_encode(['success' => false, 'error' => 'limite_piano', 'message' => $msg, 'stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']]));
}

if (!defined('GEMINI_API_KEY')) {
    ob_clean();
    // Invece di far fallire il Javascript, mandiamo l'errore come se fosse una mini-card!
    echo json_encode(['success' => true, 'cards' => [["q" => "⚠️ Errore", "a" => "API Key non configurata."]]]);
    exit;
}

$data = json_decode($__raw_input, true);
if (!$data) {
    ob_clean();
    $diag = 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte';
    echo json_encode(['success' => true, 'cards' => [["q" => "⚠️ Errore", "a" => "Nessun dato ricevuto dal frontend. (" . $diag . ")"]]]);
    exit;
}

$q = isset($data['q']) ? $data['q'] : '';
$a = isset($data['a']) ? $data['a'] : '';

// Prompt corazzato: diamo istruzioni CHIARE sull'escape dei backslash per il LaTeX
$prompt = "Agisci come un tutor universitario. Ti fornirò una Flashcard composta da una Domanda e una Risposta. Il tuo compito è individuare i concetti principali della flashcard, e dunque 'frantumarla' in N micro-flashcards più piccole, esse, se unite, dovranno formare la flashcard originale, dunque NON DEVI AGGIUNGERE ALTRI CONCETTI O SPIEGARLA ULTERIOMENTE, ti devi limitare a spezzare gli argomenti e dare un senso computo alla frase. Fai dunque il minimo indispensabile di flashcard, non farne troppe. 
La domanda di ognuna, dovrà essere super chiara e diretta su quale concetto di quella flashcard vuole la risposta, e, a sua volta la risposta, dovrà essere diretta sulla risposta nella prima frase, e poi eventualmente spiegarsi meglio dalla seconda.
Quando dico che le domande devono essere chiare, intendo anche dire che non devono essere simili tra di loro, e dunque confondore lo studente perché 2 o più domande sembrano chiedere lo stesso concetto.
Per le formule, utilizza il Latex:
Il LaTeX in riga va tra $ $ (es. la formula è $ formula $, bla bla). Quello isolato che va a capo va tra $$ $$ (es. la formula è $$ formula $$ Bla bla). ATTENZIONE: non mettere MAI il punto o la virgola subito dopo il doppio dollaro, altrimenti ci sarà una riga con un solo punto! Sbagliato: $$ formula $$, bla bla.
.\nREGOLE RIGIDE:\n1. Mantieni sempre la formattazione originale del sito. Usa anche i tag /bold{}, /red{}, /italic{} se utili.\n2. L'output DEVE ESSERE ESCLUSIVAMENTE un Array JSON valido.\n3. FONDAMENTALE: Poiché c'è del codice LaTeX, DEVI FARE L'ESCAPE DOPPIO di ogni singolo backslash nel JSON! (Esempio: scrivi \\\\frac invece di \\frac, e \\\\to invece di \\to, altrimenti la decodifica JSON si rompe).\n\nStruttura JSON richiesta:\n[\n  {\"q\": \"Mini domanda 1...\", \"a\": \"Mini risposta 1...\"},\n  {\"q\": \"Mini domanda 2...\", \"a\": \"Mini risposta 2...\"}\n]\n\nFLASHCARD ORIGINALE:\nDomanda: " . $q . "\nRisposta: " . $a;

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;

// FORZIAMO IL FORMATO JSON DIRETTAMENTE NELL'API
$payload = json_encode([
    "contents" => [["parts" => [["text" => $prompt]]]],
    "generationConfig" => [
        "temperature" => 0.2,
        "responseMimeType" => "application/json"
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Scudi Anti-Proxy per Altervista
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
curl_setopt($ch, CURLOPT_FORBID_REUSE, true); 
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
// Timeout esplicito: senza, su hosting con limiti di esecuzione stretti (es. Altervista)
// una risposta lenta di Gemini fa scadere lo script prima di poter restituire un JSON pulito.
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$tempo_inizio = microtime(true);
$response = curl_exec($ch);
$durata_ms = (int)round((microtime(true) - $tempo_inizio) * 1000);
$err = curl_error($ch);
$err_no = curl_errno($ch);
curl_close($ch);

if (!$err) { nisama_registra_tempo_ia('shatter', $durata_ms); }

// Pulisce qualsiasi cosa stampata finora per garantire un JSON puro
ob_clean(); 
header('Content-Type: application/json');

if ($err) {
    $msg_errore = ($err_no === CURLE_OPERATION_TIMEOUTED || strpos($err, 'timed out') !== false)
        ? 'Gemini ci ha messo troppo a rispondere (le API di Google potrebbero essere più lente del solito). Riprova tra poco.'
        : "cURL Error: " . $err;
    echo json_encode(['success' => true, 'cards' => [["q" => "⚠️ Errore di Rete", "a" => $msg_errore]]]);
    exit;
}

$responseData = json_decode($response, true);

if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $testo_grezzo = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
    
    // 1. Pulizia aggressiva degli eventuali blocchi markdown (```json ... ```)
    $testo_pulito = preg_replace('/^```json\s*/i', '', $testo_grezzo);
    $testo_pulito = preg_replace('/```\s*$/i', '', $testo_pulito);
    $testo_pulito = trim($testo_pulito);
    
    // Proviamo a decodificare
    $cards = json_decode($testo_pulito, true);
    
    // 2. Se fallisce, il problema è quasi sicuramente un backslash del LaTeX non "escapato".
    // Tenteremo una sostituzione di emergenza:
    if ($cards === null && json_last_error() !== JSON_ERROR_NONE) {
        // Cerca i backslash singoli che non appartengono alle sequenze classiche del JSON e li raddoppia
        $testo_riparato = preg_replace('/(?<!\\\\)\\\\(?!["\\\\\/bfnrt])/', '\\\\\\\\', $testo_pulito);
        $cards = json_decode($testo_riparato, true);
    }
    
    if ($cards && is_array($cards)) {
        $token_usati = isset($responseData['usageMetadata']['totalTokenCount']) ? (int)$responseData['usageMetadata']['totalTokenCount'] : 300;
        nisama_incrementa_uso($utente_corrente['id'], 'token', $token_usati);
        
        echo json_encode(['success' => true, 'cards' => $cards]);
    } else {
        // 3. Se proprio l'IA ha scritto in ostrogoto, mostriamo l'errore DENTRO il popup come se fosse una carta
        $error_msg = json_last_error_msg();
        $error_card = [
            "q" => "⚠️ Errore Decodifica JSON",
            "a" => "L'IA ha restituito un formato illeggibile.\n**Errore PHP:** " . $error_msg . "\n\n**Testo Generato:**\n" . htmlspecialchars($testo_grezzo)
        ];
        echo json_encode(['success' => true, 'cards' => [$error_card]]);
    }
} else {
    // Gestione errori API (Es. blocco sicurezza / token esauriti)
    $api_error = isset($responseData['error']['message']) ? $responseData['error']['message'] : 'Risposta API sconosciuta';
    echo json_encode(['success' => true, 'cards' => [["q" => "⚠️ Errore API Gemini", "a" => $api_error]]]);
}
exit;
?>