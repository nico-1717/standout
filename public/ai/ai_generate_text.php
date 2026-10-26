<?php
// Leggiamo SUBITO il corpo della richiesta, prima di qualsiasi require (vedi
// ai_example.php per il perché: php://input si legge una volta sola).
$__raw_input = file_get_contents('php://input');

// Forza la visualizzazione degli errori (se l'hosting lo permette)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_saved.php';

ob_start(); // Avvia il buffer per evitare crash con JSON su Altervista

// Rete di sicurezza: garantisce comunque un JSON leggibile anche se qualcosa
// va storto in modo fatale più avanti (vedi ai_example.php per i dettagli).
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => t('ai.errore_interno', ['errore' => $err['message'], 'riga' => $err['line']])]);
    }
});

// --- CONTROLLO CHIAVI ---
if (!defined('GEMINI_API_KEY')) { ob_clean(); die(json_encode(['success' => false, 'error' => t('ai.chiave_mancante', ['chiave' => 'GEMINI_API_KEY'])])); }

/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();

$data = json_decode($__raw_input, true);
if (!$data) {
    ob_clean();
    $diag = 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte';
    die(json_encode(['success' => false, 'error' => t('ai.nessun_dato'), 'debug' => $diag]));
}

// PREVENZIONE BUG PHP 8
$mode = isset($data['mode']) ? $data['mode'] : '';
$raw_text = isset($data['text']) ? $data['text'] : '';
/* Questo endpoint riceve un POST, quindi qui sec_guard_write protegge davvero
   dal CSRF oltre a limitare la frequenza. 30 al minuto: ogni chiamata costa
   token a Gemini. */
sec_guard_write((int)$utente_corrente['id'], 'fc_ai_text', 0, 30, 60);

$card_id = sec_int($data['id'] ?? 0, 1);
/* Un figlio e un padre non stanno nella stessa tabella e le loro numerazioni
   si sovrappongono: il gioco dice quale dei due e', e il testo salvato si
   cerca (e si scrive) dalla parte giusta. Prima il numero di un figlio finiva
   su un padre qualunque con lo stesso numero. */
$is_child = !empty($data['is_child']);

// Questo endpoint gestisce solo 'explain' e 'metaphor'. Per 'read' non c'è nulla da generare,
// non si chiama mai Gemini e non si spende nessun token: niente controllo limite in questo caso.
if ($mode === 'read') {
    ob_clean();
    echo json_encode(['success' => true, 'text' => $raw_text]);
    exit;
}

/* Qualunque altro valore sarebbe stato trattato come "metafora" e avrebbe
   speso token per un testo che nessun pulsante chiede. */
if ($mode !== 'explain' && $mode !== 'metaphor') {
    ob_clean();
    die(json_encode(['success' => false, 'error' => t('ai.nessun_dato')]));
}

/* $col_to_check e' un nome di colonna, non un valore: non si puo' legare a un
   segnaposto. Qui e' sicuro perche' esce da un ternario con due valori fissi. */
$col_to_check = ($mode === 'explain') ? 'ai_explanation' : 'ai_metaphor';
[$tab_ai, $col_key] = fc_ai_dove($is_child);

/* La carta dev'essere dell'utente: senza questo controllo si poteva far
   generare - e pagare in token - spiegazioni sulle carte di un altro, e
   soprattutto rileggerle dalla cache. */
if (!fc_ai_carta_mia($card_id, $is_child, (int)$utente_corrente['id'])) {
    ob_clean();
    die(json_encode(['success' => false, 'error' => t('fc.non_autorizzato_scheda')]));
}

// Cache: se la risposta c'e' gia', non si chiama Gemini e non si spendono token
$row = sec_one("SELECT $col_to_check FROM $tab_ai WHERE $col_key = ?", [$card_id]);

if ($row && !empty($row[$col_to_check])) {
    // Trovata nel DB, nessuna chiamata esterna necessaria (Nessun token scalato!)
    ob_clean();
    echo json_encode(['success' => true, 'text' => $row[$col_to_check], 'cached' => true]);
    exit;
}

// Non in cache: qui davvero interrogheremo Gemini, quindi ORA ha senso controllare il limite,
// usando la stima (il costo reale in token si scopre solo a chiamata avvenuta).
$stima_limite = standout_verifica_limite_stimato($utente_corrente['id'], 300);
if (!$stima_limite['ok']) {
    ob_clean();
    $msg = ($stima_limite['disponibili'] !== null)
        ? t('ai.stima_token', ['stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']])
        : t('ai.limite_mensile');
    die(json_encode(['success' => false, 'error' => 'limite_piano', 'message' => $msg, 'stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']]));
}

// Non in cache: interroghiamo Gemini
$prompt = ($mode === 'explain')
    ? "Spiega in modo semplice, discorsivo e chiaro il seguente concetto per uno studente universitario. Tono amichevole. Mantieni le formule matematiche nei dollari ($$). Rispondi in " . t('ai.lingua_risposta') . ". Testo: " . $raw_text
    : "Crea una metafora o un esempio pratico per memorizzare il seguente concetto. Mantieni formule nei dollari ($$). Rispondi in " . t('ai.lingua_risposta') . ". Testo: " . $raw_text;

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
        ? t('ai.gemini_lento')
        : t('ai.gemini_connessione', ['n' => $errno, 'errore' => $errstr]);
    die(json_encode(['success' => false, 'error' => $msg_errore]));
}
curl_close($ch);

// Registriamo quanto ci ha messo questa chiamata, per stimare i tempi delle prossime
standout_registra_tempo_ia($mode, $durata_ms);

$json = json_decode($response, true);
if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
    $final_text = $json['candidates'][0]['content']['parts'][0]['text'];

    /* Il testo viaggia come valore legato: il nome della colonna resta scritto
       nella query, per il motivo detto sopra. */
    sec_q("INSERT INTO $tab_ai ($col_key, $col_to_check) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE $col_to_check = ?", [$card_id, $final_text, $final_text]);

    // ORA scaliamo i token perché abbiamo effettivamente usato l'API
    $token_usati = isset($json['usageMetadata']['totalTokenCount']) ? (int)$json['usageMetadata']['totalTokenCount'] : 300;
    standout_incrementa_uso($utente_corrente['id'], 'token', $token_usati);

    ob_clean();
    echo json_encode(['success' => true, 'text' => $final_text, 'duration_ms' => $durata_ms, 'cached' => false]);
    exit;
} else {
    ob_clean();
    /* Il corpo della risposta non si rimanda al client: puo' contenere dettagli
       del servizio e della chiave. Finisce nel log, l'utente vede un messaggio. */
    sec_log_error('gemini', 'risposta anomala: ' . substr((string)$response, 0, 500));
    die(json_encode(['success' => false, 'error' => t('fc.risposta_anomala')]));
}