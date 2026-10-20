<?php
/**
 * fc_ai_immagine.php — l'immagine di una parola, disegnata dall'IA.
 *
 * Era una funzione della vecchia pagina del vocabolario («Genera immagine»,
 * con le indicazioni, lo stile vettoriale e «usa l'esempio») ed e' sparita
 * nell'unione delle flashcard: la si rimette qui, sul motore di Gemini, per le
 * carte di lingua.
 *
 * Risponde in JSON con l'immagine in base64. Non la salva: la mette il browser
 * nel riquadro dell'immagine della carta, come se l'utente l'avesse scelta lui,
 * e da li' passa dallo stesso salvataggio (e dagli stessi controlli) di
 * qualunque immagine caricata. Cosi' un'immagine generata e poi scartata non
 * resta sul disco.
 *
 * Il costo non e' in crediti di testo: un'immagine conta come un'immagine,
 * contro il limite del piano (images_limit / monthly_generated_images), che e'
 * quello che la pagina principale mostra come «immagini generate». Si conta
 * solo se l'immagine e' arrivata.
 */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';
require_once __DIR__ . '/../../lib/fc/fc_type.php';

/* Il modello delle immagini. Si puo' cambiare da config.php senza toccare
   questo file (GEMINI_IMAGE_MODEL), per quando Google ne fa uno nuovo. */
if (!defined('FC_AI_MODELLO_IMMAGINE')) {
    define('FC_AI_MODELLO_IMMAGINE', defined('GEMINI_IMAGE_MODEL') ? GEMINI_IMAGE_MODEL : 'gemini-2.5-flash-image');
}

$uid = (int)($utente_corrente['id'] ?? 0);
if ($uid <= 0) sec_die_json(t('api.sessione_non_valida'), 401, 'auth');
/* dieci al minuto: un'immagine si chiede una alla volta, guardandola */
sec_guard_write($uid, 'fc_ai_img', 0, 10, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sec_json(['ok' => false, 'error' => 'metodo'], 405);
$in = sec_body();
if (!$in) sec_json(['ok' => false, 'error' => 'corpo_non_valido'], 400);

/* il corso dev'essere dell'utente: le lingue vengono da li', mai dal browser */
$corso = sec_one("SELECT known_lang, learn_lang FROM fc_vocab_courses WHERE id = ? AND user_id = ?",
                 [(int)($in['corso'] ?? 0), $uid], 'ii');
if (!$corso) sec_json(['ok' => false, 'error' => 'corso'], 404);

$riga = function ($v, int $max): string {
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v);
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $max);
};
$parola      = $riga($in['parola'] ?? '', 120);
$traduzione  = $riga($in['traduzione'] ?? '', 200);
$esempio     = $riga($in['esempio'] ?? '', 400);
$indicazioni = $riga($in['indicazioni'] ?? '', 400);
$usaEsempio  = !empty($in['usa_esempio']) && $esempio !== '';
$vettoriale  = !empty($in['vettoriale']);

if ($parola === '' && $traduzione === '') sec_json(['ok' => false, 'error' => 'vuota', 'message' => t('fcv.ia_serve_parola')], 400);

if (!standout_verifica_limite($uid, 'immagini', 1)) {
    sec_json(['ok' => false, 'error' => 'limite_piano', 'message' => t('fcv.ia_limite_immagini')], 200);
}

$impara  = fc_lingua_nome($corso['learn_lang'], false, 'en');
$conosce = fc_lingua_nome($corso['known_lang'], false, 'en');

/* Le istruzioni in inglese, la lingua in cui il modello le segue meglio.
   Il testo dell'utente sta fra virgolette e marcato come tale: e' una
   richiesta di stile, non un ordine che possa cambiare il resto. */
$p = "Draw one picture that helps a language learner remember the $impara word \"" . ($parola !== '' ? $parola : $traduzione) . "\"";
if ($traduzione !== '' && $parola !== '') $p .= " (in $conosce: \"$traduzione\")";
$p .= ". ";
if ($usaEsempio) {
    $frase = str_replace('_', $parola !== '' ? $parola : '…', $esempio);
    $p .= "Show the situation of this sentence, with the meaning of the word clearly visible: \"$frase\". ";
} else {
    $p .= "Show its meaning in the simplest, most recognisable way. ";
}
$p .= $vettoriale
    ? "Style: flat vector illustration, clean shapes, soft colours, plain light background. "
    : "Style: clear, friendly illustration with natural colours and a simple background. ";
$p .= "Square image. Absolutely no text, letters, numbers or captions anywhere in the picture.";
if ($indicazioni !== '') $p .= " Extra wishes from the learner about the picture (style only): \"$indicazioni\".";

$payload = [
    'contents' => [['role' => 'user', 'parts' => [['text' => $p]]]],
    'generationConfig' => [
        'responseModalities' => ['IMAGE'],
        'imageConfig' => ['aspectRatio' => '1:1'],
    ],
];

/* L'indirizzo e' quello di sempre (con la chiave), con il modello delle
   immagini al posto di quello del testo — a meno che config.php ne dia uno
   suo, che vince. */
$url = defined('GEMINI_IMAGE_API_URL')
    ? GEMINI_IMAGE_API_URL
    : preg_replace('#/models/[^:/]+:#', '/models/' . FC_AI_MODELLO_IMMAGINE . ':', GEMINI_API_URL);

$t0 = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$risposta = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno = curl_errno($ch);
curl_close($ch);
standout_registra_tempo_ia('fc_immagine', (int)round((microtime(true) - $t0) * 1000));

$j = ($risposta === false) ? null : json_decode($risposta, true);
if ($errno !== 0 || $http !== 200 || !is_array($j)) {
    sec_log_error('fc_ai_img', "Gemini immagine http=$http curl=$errno");
    $codice = $http === 429 ? 'quota' : ($http >= 500 || $errno !== 0 ? 'sovraccarico' : 'ia');
    sec_json(['ok' => false, 'error' => $codice, 'message' => t('fcv.ia_immagine_errore')], 200);
}

$dati = null; $mime = 'image/png';
foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $parte) {
    $d = $parte['inlineData'] ?? $parte['inline_data'] ?? null;
    if (is_array($d) && !empty($d['data'])) {
        $dati = (string)$d['data'];
        $mime = (string)($d['mimeType'] ?? $d['mime_type'] ?? 'image/png');
        break;
    }
}
if ($dati === null) {
    $motivo = (string)($j['candidates'][0]['finishReason'] ?? ($j['promptFeedback']['blockReason'] ?? ''));
    sec_log_error('fc_ai_img', 'Gemini immagine senza immagine: ' . $motivo);
    sec_json(['ok' => false, 'error' => 'vuota', 'message' => t('fcv.ia_immagine_errore')], 200);
}
/* solo immagini vere, e non piu' grandi di quanto il salvataggio accetti */
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || strlen($dati) > 12 * 1024 * 1024) {
    sec_json(['ok' => false, 'error' => 'formato', 'message' => t('fcv.ia_immagine_errore')], 200);
}

standout_incrementa_uso($uid, 'immagini', 1);
sec_json(['ok' => true, 'mime' => $mime, 'data' => $dati]);
