<?php
/* ai_shatter.php — "Frantuma": divide una flashcard di studio in carte piu' piccole.
 *
 * Risposta: { success, split, title, cards: [{q, a}], dropped }
 *   split=false  la carta e' gia' un concetto solo: non c'e' niente da dividere.
 *   title        il titolo della carta padre (nome dell'argomento, non una domanda).
 *   dropped      quante carte il modello ha scritto ma sono state scartate perche'
 *                non risalivano al testo originale (vedi fc_frantuma_radice).
 * Se qualcosa va storto la risposta e' { success:false, error }: prima l'errore
 * viaggiava come una "mini carta" finta, ma ora le mini carte si possono
 * SALVARE (ai_shatter_save.php) e salvare un messaggio d'errore come carta
 * sarebbe stato un danno vero.
 */

// Leggiamo SUBITO il corpo della richiesta, prima di qualsiasi require (vedi
// ai_example.php per il perché: php://input si legge una volta sola).
$__raw_input = file_get_contents('php://input');

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';
require_once __DIR__ . '/../../lib/fc/fc_frantuma.php';

// Inizializza il buffer per nascondere eventuali Warning/Notice di Altervista
ob_start();

/** Risponde con un errore leggibile dalla pagina e si ferma. */
function fc_frantuma_errore(string $msg, array $extra = []): void {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'error' => $msg], $extra));
    exit;
}

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

/* Il testo "tutto minuscolo, solo lettere e cifre": serve a confrontare una
   citazione con l'originale senza che contino spazi, a capo, tag di
   formattazione (/bold{...}), dollari del LaTeX o punteggiatura, che il
   modello cambia spesso senza cambiare il contenuto. */
function fc_frantuma_radice(string $s): string {
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '', 'UTF-8');
}

$stima_limite = standout_verifica_limite_stimato($utente_corrente['id'], 300);
if (!$stima_limite['ok']) {
    $msg = ($stima_limite['disponibili'] !== null)
        ? t('ai.stima_frantuma', ['stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']])
        : t('ai.limite_mensile');
    fc_frantuma_errore($msg, ['error' => 'limite_piano', 'message' => $msg, 'stima' => $stima_limite['stima'], 'disponibili' => $stima_limite['disponibili']]);
}

if (!defined('GEMINI_API_KEY')) {
    fc_frantuma_errore(t('ai.api_key_mancante'));
}

$data = json_decode($__raw_input, true);
if (!$data) {
    fc_frantuma_errore(t('ai.nessun_dato_frontend', ['diag' => 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte']));
}

/* Un tetto alla lunghezza: il testo finisce dritto nel prompt, e quindi nel
   conto dei token. Una flashcard vera sta molto sotto. */
/* Il testo di una carta puo' essere un documento JSON di fc_parser: all'IA (e al
   controllo di fedelta' piu' sotto) serve il testo vero, vedi fc_frantuma_in_testo. */
$q = mb_substr(fc_frantuma_in_testo(is_string($data['q'] ?? null) ? $data['q'] : ''), 0, 12000, 'UTF-8');
$a = mb_substr(fc_frantuma_in_testo(is_string($data['a'] ?? null) ? $data['a'] : ''), 0, 12000, 'UTF-8');
if (trim($a) === '') {
    fc_frantuma_errore(t('fc.gm_frantuma_senza_testo'));
}

$prompt = <<<'PROMPT'
Sei un tutor universitario. Ti do una flashcard formata da una Domanda e una Risposta. Devi decidere se ha senso dividerla e, solo in quel caso, dividerla in carte piu' piccole.

REGOLA ASSOLUTA: usa ESCLUSIVAMENTE le informazioni scritte nella flashcard. Non aggiungere definizioni, esempi, contesto, spiegazioni, sinonimi o nozioni che conosci tu e che nella flashcard non ci sono, nemmeno se sono corrette. Ogni risposta deve essere costruita con parole e informazioni prese dalla risposta originale: puoi accorciare e riordinare, non puoi aggiungere.

QUANDO DIVIDERE. Dividi solo se la flashcard contiene almeno due concetti DISTINTI, ciascuno dei quali ha senso come domanda a se' e si puo' ripassare senza gli altri. NON dividere, e rispondi con "split": false, se:
- e' una sola definizione, un solo fatto, una sola formula, un solo procedimento o un solo ragionamento, anche se lungo;
- e' un elenco di elementi che hanno senso solo insieme (per esempio i passi di uno stesso procedimento);
- dividerla darebbe frammenti banali, oppure frammenti che per essere capiti dipendono l'uno dall'altro.
Nel dubbio NON dividere.

QUANTE CARTE. Il minimo indispensabile: di solito 2 o 3, al massimo 5. Un dettaglio, un'eccezione, un esempio o una precisazione appartengono alla carta del concetto a cui si riferiscono: non diventano mai una carta a parte. Le carte non devono ripetere la stessa informazione, ne' avere domande simili tra loro che confondano lo studente.

COME SCRIVERE OGNI CARTA:
- "q": la domanda. Viene mostrata da sola, quindi nomina l'argomento. E' chiara, riguarda UN solo concetto e non contiene la risposta.
- "a": la risposta. La risposta vera sta gia' nella prima frase; poi, solo se servono, i dettagli che la flashcard contiene gia'.
- "src": la frase o il pezzo della flashcard originale da cui hai preso la risposta, COPIATO PAROLA PER PAROLA, senza cambiare niente.
"title": un titolo breve (al massimo 8 parole, senza punto finale) che dice di cosa parla l'intera flashcard. Ricavalo dalla domanda originale. Non e' una domanda.

FORMULE: usa il LaTeX. Quello in riga va tra $ $ (es. la formula e' $ formula $, bla bla). Quello isolato, che va a capo, tra $$ $$ (es. la formula e' $$ formula $$ Bla bla). ATTENZIONE: non mettere MAI il punto o la virgola subito dopo il doppio dollaro, altrimenti ci sara' una riga con un solo punto! Sbagliato: $$ formula $$, bla bla.

REGOLE RIGIDE:
0. Scrivi titolo e carte nella stessa lingua della flashcard originale.
1. Mantieni la formattazione originale del sito. Puoi usare i tag /bold{}, /red{}, /italic{} se servono.
2. L'output DEVE ESSERE ESCLUSIVAMENTE un oggetto JSON valido.
3. FONDAMENTALE: poiche' c'e' del codice LaTeX, DEVI FARE L'ESCAPE DOPPIO di ogni singolo backslash nel JSON (esempio: scrivi \\frac invece di \frac, e \\to invece di \to), altrimenti la decodifica JSON si rompe.

Struttura JSON richiesta se dividi:
{"split": true, "title": "Titolo", "cards": [{"q": "Domanda 1", "a": "Risposta 1", "src": "pezzo originale 1"}, {"q": "Domanda 2", "a": "Risposta 2", "src": "pezzo originale 2"}]}

Struttura JSON richiesta se NON dividi:
{"split": false, "title": "", "cards": []}

FLASHCARD ORIGINALE:
PROMPT;
$prompt .= "\nDomanda: " . $q . "\nRisposta: " . $a;

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;

// FORZIAMO IL FORMATO JSON DIRETTAMENTE NELL'API
$payload = json_encode([
    "contents" => [["parts" => [["text" => $prompt]]]],
    "generationConfig" => [
        /* Bassa: qui non serve creativita', serve fedelta' al testo. */
        "temperature" => 0.1,
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

if (!$err) { standout_registra_tempo_ia('shatter', $durata_ms); }

if ($err) {
    fc_frantuma_errore(($err_no === CURLE_OPERATION_TIMEOUTED || strpos($err, 'timed out') !== false)
        ? t('ai.gemini_lento')
        : t('ai.errore_rete'));
}

$responseData = json_decode($response, true);

if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    // Gestione errori API (Es. blocco sicurezza / token esauriti). Il dettaglio
    // va nel log: all'utente basta sapere che non e' andata.
    sec_log_error('gemini', 'frantuma, risposta anomala: ' . substr((string)$response, 0, 500));
    fc_frantuma_errore(t('ai.errore_api'));
}

$testo_grezzo = trim($responseData['candidates'][0]['content']['parts'][0]['text']);

// 1. Pulizia aggressiva degli eventuali blocchi markdown (```json ... ```)
$testo_pulito = preg_replace('/^```json\s*/i', '', $testo_grezzo);
$testo_pulito = preg_replace('/```\s*$/i', '', $testo_pulito);
$testo_pulito = trim($testo_pulito);

// Proviamo a decodificare
$risposta = json_decode($testo_pulito, true);

// 2. Se fallisce, il problema è quasi sicuramente un backslash del LaTeX non "escapato".
// Tenteremo una sostituzione di emergenza:
if ($risposta === null && json_last_error() !== JSON_ERROR_NONE) {
    // Cerca i backslash singoli che non appartengono alle sequenze classiche del JSON e li raddoppia
    $testo_riparato = preg_replace('/(?<!\\\\)\\\\(?!["\\\\\/bfnrt])/', '\\\\\\\\', $testo_pulito);
    $risposta = json_decode($testo_riparato, true);
}

/* Il token si scala comunque: l'IA ha lavorato, anche se il risultato e'
   illeggibile o la carta non c'era da dividere. */
$token_usati = isset($responseData['usageMetadata']['totalTokenCount']) ? (int)$responseData['usageMetadata']['totalTokenCount'] : 300;
standout_incrementa_uso($utente_corrente['id'], 'token', $token_usati);

if (!is_array($risposta)) {
    sec_log_error('gemini', 'frantuma, JSON illeggibile: ' . json_last_error_msg() . ' | ' . substr($testo_grezzo, 0, 300));
    fc_frantuma_errore(t('ai.formato_illeggibile'));
}

/* Il modello ha risposto con una lista (il formato di un tempo) invece che con
   l'oggetto: la si accetta, e conta come "dividi". */
if ($risposta !== [] && array_keys($risposta) === range(0, count($risposta) - 1)) {
    $risposta = ['split' => true, 'title' => '', 'cards' => $risposta];
}

$non_dividere = ['success' => true, 'split' => false, 'title' => '', 'cards' => [], 'dropped' => 0];
if (empty($risposta['split']) || !is_array($risposta['cards'] ?? null)) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($non_dividere);
    exit;
}

/* CONTROLLO DI FEDELTA'. Il modello deve citare, per ogni carta, il pezzo
   originale da cui l'ha ricavata. Una carta la cui citazione non si ritrova
   davvero nella flashcard (domanda o risposta) e' inventata, o ricavata da
   cio' che il modello "sa": e' esattamente cio' che qui non vogliamo, quindi
   si scarta. Si confronta senza spazi, formattazione e punteggiatura (vedi
   fc_frantuma_radice) perche' quelle il modello le cambia spesso. */
$radice_originale = fc_frantuma_radice($q . ' ' . $a);
$carte = [];
$scartate = 0;
foreach ($risposta['cards'] as $c) {
    if (!is_array($c)) continue;
    $cq = trim((string)($c['q'] ?? ''));
    $ca = trim((string)($c['a'] ?? ''));
    if ($cq === '' || $ca === '') { $scartate++; continue; }
    $r = fc_frantuma_radice((string)($c['src'] ?? ''));
    if (mb_strlen($r, 'UTF-8') < 4 || mb_strpos($radice_originale, $r, 0, 'UTF-8') === false) { $scartate++; continue; }
    $carte[] = ['q' => $cq, 'a' => $ca];
}

/* Meno di due carte non e' una divisione: una sola carta uguale all'originale
   non serve, e un titolo con un figlio solo non e' una carta titolo. */
if (count($carte) < 2) {
    $non_dividere['dropped'] = $scartate;
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($non_dividere);
    exit;
}

$titolo = trim((string)($risposta['title'] ?? ''));
$titolo = mb_substr(rtrim($titolo, " .\t"), 0, 200, 'UTF-8');
if ($titolo === '') {
    /* Il modello non l'ha scritto: ripiego sulla domanda originale, accorciata. */
    $titolo = mb_substr(rtrim(preg_replace('/\s+/u', ' ', $q) ?? '', " .?\t"), 0, 80, 'UTF-8');
}

ob_clean();
header('Content-Type: application/json');
echo json_encode(['success' => true, 'split' => true, 'title' => $titolo, 'cards' => $carte, 'dropped' => $scartate]);
exit;
