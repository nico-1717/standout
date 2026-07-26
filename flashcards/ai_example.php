<?php
// IMPORTANTISSIMO: leggiamo SUBITO il corpo della richiesta, prima di qualsiasi
// require. php://input è uno stream leggibile una sola volta: se un file incluso
// più avanti (config.php, auth_check_api.php) lo leggesse a sua volta per conto
// suo, la nostra lettura successiva risulterebbe vuota ("Nessun dato ricevuto"),
// anche se il frontend aveva mandato tutto correttamente.
$__raw_input = file_get_contents('php://input');

require_once 'config.php';
require_once 'auth_check_api.php';
require_once 'piano_helpers.php';

ob_start();

// Rete di sicurezza: se qualcosa va storto in modo fatale più avanti (query non
// valida dopo la migrazione, funzione mancante, ecc.), invece di una pagina
// bianca o un errore PHP grezzo che il fetch() del frontend non sa interpretare
// (e che sembra "carica e poi non succede niente"), restituiamo comunque un
// JSON leggibile con l'errore vero.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore interno del server: ' . $err['message'] . ' (riga ' . $err['line'] . ')']);
    }
});

$stima_limite = nisama_verifica_limite_stimato($utente_corrente['id'], 300);
if (!$stima_limite['ok']) {
    ob_clean();
    $msg = ($stima_limite['disponibili'] !== null)
        ? "Questa richiesta ti costerebbe circa {$stima_limite['stima']} token, ma te ne restano solo {$stima_limite['disponibili']} questo mese."
        : 'Hai raggiunto il limite mensile di Token IA.';
    die(json_encode(['success' => false, 'error' => 'limite_piano', 'message' => $msg, 'stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']]));
}

if (!defined('GEMINI_API_KEY')) {
    ob_clean();
    die(json_encode(['success' => false, 'error' => 'API Key non configurata.']));
}

$data = json_decode($__raw_input, true);
if (!$data) {
    ob_clean();
    // Diagnostica: se càpita ancora, questi dettagli dicono subito il perché.
    $diag = 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte, Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'assente') . ', Content-Length header: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'assente');
    die(json_encode(['success' => false, 'error' => 'Nessun dato ricevuto.', 'debug' => $diag]));
}

$q = isset($data['q']) ? $data['q'] : '';
$a = isset($data['a']) ? $data['a'] : '';
$extra = isset($data['extra']) ? trim($data['extra']) : '';

if ($q === '' && $a === '') {
    ob_clean();
    die(json_encode(['success' => false, 'error' => 'Domanda/risposta della flashcard mancanti.']));
}

$prompt = "Agisci come un tutor universitario. Genera un esempio pratico per uno studente universitario sull'argomento di questa flashcard, con focus prevalente sul concetto espresso:\n\nDOMANDA FLASHCARD: $q\nRISPOSTA FLASHCARD: $a\n\n";

if ($extra !== '') {
    $prompt .= "Inoltre, SEGUI ANCHE LE SEGUENTI INDICAZIONI AGGIUNTIVE per la generazione dell'esempio:\n$extra\n\n";
}

$prompt .= "REGOLE RIGIDE DI FORMATTAZIONE:
1. Devi essere diretto: inizia subito con l'esempio, o se è un esercizio: con la consegna dell'esercizio e con la risposta subito dopo.
2. TESTO: Puoi andare a capo quando vuoi per rendere più chiara la lettura. Usa questi comandi per evidenziare le parti più importanti:
per colorare il testo: /red{testo}, /blue{testo}, /green{testo}, /purple{testo}, /orange{testo}, /gray{testo}.
per evidenziare il testo: /yellow{testo}.
per formattarlo: per cose importanti: /bold{testo}, /underline{testo}. per aggiunte: /italic{testo}.
3. LISTE: \"list\" si utilizza SOLO per i punti elenco. Devono essere scritte esattamente così:
/list{
- primo punto
- secondo punto
-- primo sottopunto
}
3. FORMULE LATEX: Il LaTeX in riga va tra $ $ e quello isolato tra $$$$. Non mettere mai punteggiatura attaccata a$$. FARE L'ESCAPE DOPPIO di ogni backslash (es. \\\\frac, \\\\to).

L'output deve essere un JSON valido con questa struttura:
{ \"example\": \"Testo dell'esempio qui...\" }";

$payload = json_encode([
    "contents" => [["parts" => [["text" => $prompt]]]],
    "generationConfig" => [ "temperature" => 0.4, "responseMimeType" => "application/json" ]
]);

// USA L'URL GLOBALE DEL CONFIG
$ch = curl_init(GEMINI_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
// Timeout esplicito: senza, su hosting con limiti di esecuzione stretti (es. Altervista)
// una risposta lenta di Gemini fa scadere lo script PRIMA che si arrivi a restituire
// un JSON di errore pulito, e il frontend riceve una pagina di errore non decodificabile.
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$tempo_inizio = microtime(true);
$response = curl_exec($ch);
$durata_ms = (int)round((microtime(true) - $tempo_inizio) * 1000);
$err = curl_error($ch);
$err_no = curl_errno($ch);
curl_close($ch);

if (!$err) { nisama_registra_tempo_ia('example', $durata_ms); }

ob_clean(); 
header('Content-Type: application/json');

if ($err) {
    $errore_leggibile = ($err_no === CURLE_OPERATION_TIMEOUTED || strpos($err, 'timed out') !== false)
        ? 'Gemini ci ha messo troppo a rispondere (le API di Google potrebbero essere più lente del solito in questo momento). Riprova tra poco.'
        : $err;
    die(json_encode(['success' => false, 'error' => $errore_leggibile]));
}

$responseData = json_decode($response, true);
if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    
    // SCUDO ANTI-ERRORE JSON E LATEX
    $testo_grezzo = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
    $testo_pulito = preg_replace('/^```json\s*/i', '', $testo_grezzo);
    $testo_pulito = preg_replace('/```\s*$/i', '', $testo_pulito);
    $testo_pulito = trim($testo_pulito);
    
    $jsonObj = json_decode($testo_pulito, true);
    
    // Riparazione di emergenza backslash
    if ($jsonObj === null && json_last_error() !== JSON_ERROR_NONE) {
        $testo_riparato = preg_replace('/(?<!\\\\)\\\\(?!["\\\\\/bfnrt])/', '\\\\\\\\', $testo_pulito);
        $jsonObj = json_decode($testo_riparato, true);
    }

    if ($jsonObj && isset($jsonObj['example'])) {
        $token_usati = isset($responseData['usageMetadata']['totalTokenCount']) ? (int)$responseData['usageMetadata']['totalTokenCount'] : 300;
        nisama_incrementa_uso($utente_corrente['id'], 'token', $token_usati);
        
        echo json_encode(['success' => true, 'example' => $jsonObj['example']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Formato JSON inatteso o rotto.', 'raw' => $testo_grezzo]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Errore API Gemini.']);
}
exit;
?>