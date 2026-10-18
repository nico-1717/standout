<?php
// Leggiamo SUBITO il corpo della richiesta, prima di qualsiasi require:
// php://input si legge una volta sola (vedi ai_example.php).
$__raw_input = file_get_contents('php://input');

// Forza la visualizzazione degli errori (se l'hosting lo permette)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'auth_check_api.php';
require_once 'piano_helpers.php';

ob_start();

// Rete di sicurezza: garantisce comunque un JSON leggibile anche se qualcosa
// va storto in modo fatale più avanti.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore interno del server: ' . $err['message'] . ' (riga ' . $err['line'] . ')']);
    }
});

// --- CONTROLLO CHIAVI ---
if (!defined('GOOGLE_TTS_API_KEY')) { ob_clean(); die(json_encode(['success' => false, 'error' => 'GOOGLE_TTS_API_KEY non definita in config.php'])); }

$data = json_decode($__raw_input, true);
if (!$data) {
    ob_clean();
    die(json_encode(['success' => false, 'error' => 'Nessun dato ricevuto dal JavaScript.', 'debug' => 'lunghezza corpo ricevuto: ' . strlen($__raw_input) . ' byte']));
}

// PREVENZIONE BUG PHP 8
// $mode finisce dentro il NOME DI UN FILE poco più sotto: va ripulito, altrimenti
// un valore come "../../qualcosa" farebbe scrivere l'MP3 fuori dalla cartella.
$mode = isset($data['mode']) ? preg_replace('/[^a-z0-9_]/i', '', $data['mode']) : '';
if ($mode === '') $mode = 'read';
$final_text = isset($data['text']) ? $data['text'] : '';
$card_id = isset($data['id']) ? intval($data['id']) : 0;
$voice_name = isset($data['voice']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $data['voice']) : 'it-IT-Chirp3-HD-Charon';

function splitTextForTTS($text, $maxBytes = 2000) {
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$sentences) $sentences = [$text];
    $chunks = [];
    $current = '';
    foreach ($sentences as $sentence) {
        if (strlen($sentence) > $maxBytes) {
            if ($current !== '') { $chunks[] = $current; $current = ''; }
            $words = explode(' ', $sentence);
            $piece = '';
            foreach ($words as $word) {
                if ($piece !== '' && strlen($piece) + strlen($word) + 1 > $maxBytes) {
                    $chunks[] = $piece; $piece = $word;
                } else { $piece = ($piece === '') ? $word : $piece . ' ' . $word; }
            }
            if ($piece !== '') $current = $piece;
            continue;
        }
        if ($current !== '' && strlen($current) + strlen($sentence) + 1 > $maxBytes) {
            $chunks[] = $current; $current = $sentence;
        } else { $current = ($current === '') ? $sentence : $current . ' ' . $sentence; }
    }
    if ($current !== '') $chunks[] = $current;
    return $chunks;
}

$clean_text_for_audio = preg_replace('/[\$\*#_]/', '', $final_text);

if (!mb_check_encoding($clean_text_for_audio, 'UTF-8')) {
    $clean_text_for_audio = mb_convert_encoding($clean_text_for_audio, 'UTF-8', 'UTF-8');
}

// Nel nome del file mettiamo la voce E un'impronta del testo effettivo.
// Senza l'impronta, cambiando un'impostazione che modifica il testo letto
// (es. "Leggi LaTeX" da Sì a No, o la lingua), si sarebbe continuato a
// riascoltare il vecchio MP3 in cache, generato da un testo diverso.
if (!is_dir('ai_audio')) { @mkdir('ai_audio', 0755, true); }
$text_hash = substr(md5($clean_text_for_audio), 0, 10);
$audio_filename = "ai_audio/" . $mode . "_" . $card_id . "_" . $voice_name . "_" . $text_hash . ".mp3";

if (!file_exists($audio_filename)) {
    // Il controllo del limite ha senso SOLO qui: se il file è già in cache non
    // chiamiamo Google e non consumiamo nulla, quindi non c'è motivo di bloccare
    // l'utente che sta solo riascoltando un audio già generato. Contiamo inoltre
    // i caratteri realmente inviati a Google, non quelli del testo grezzo.
    $caratteri_audio = mb_strlen($clean_text_for_audio, 'UTF-8');
    if (!nisama_verifica_limite($utente_corrente['id'], 'audio', $caratteri_audio)) {
        ob_clean();
        die(json_encode([
            'success' => false,
            'error' => 'limite_piano',
            'message' => "Questa lettura richiede circa {$caratteri_audio} caratteri audio, ma hai raggiunto il limite mensile del tuo piano."
        ]));
    }

    $tts_url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" . GOOGLE_TTS_API_KEY;
    $text_chunks = splitTextForTTS($clean_text_for_audio);
    $audio_binary = '';

    foreach ($text_chunks as $index => $chunk) {
        if (!mb_check_encoding($chunk, 'UTF-8')) {
            $chunk = mb_convert_encoding($chunk, 'UTF-8', 'UTF-8');
        }

        $tts_payload = json_encode([
            "input" => ["text" => $chunk],
            "voice" => ["languageCode" => "it-IT", "name" => $voice_name],
            "audioConfig" => ["audioEncoding" => "MP3", "speakingRate" => 1.0]
        ]);
        
        if ($tts_payload === false) {
            ob_clean();
            die(json_encode(['success' => false, 'error' => 'Errore JSON blocco ' . ($index + 1)]));
        }

        $ch2 = curl_init($tts_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, $tts_payload);
        
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch2, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch2, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 60);
        
        $tts_response = curl_exec($ch2);

        if (curl_errno($ch2)) {
             $err = curl_error($ch2);
             curl_close($ch2);
             ob_clean();
             die(json_encode(['success' => false, 'error' => 'Errore TTS (blocco ' . ($index + 1) . ') [curl errno ' . curl_errno($ch2) . ']: ' . $err]));
        }
        curl_close($ch2);

        $tts_json = json_decode($tts_response, true);

        if (isset($tts_json['audioContent'])) {
            $audio_binary .= base64_decode($tts_json['audioContent']);
        } else {
             ob_clean();
             die(json_encode(['success' => false, 'error' => 'Risposta anomala TTS: ' . $tts_response]));
        }
    }

    $file_saved = file_put_contents($audio_filename, $audio_binary);
    if ($file_saved === false) {
        ob_clean();
        die(json_encode(['success' => false, 'error' => 'Impossibile creare il file MP3.']));
    }
    
    // Il file è stato appena creato, ORA scaliamo i caratteri audio!
    nisama_incrementa_uso($utente_corrente['id'], 'audio', $caratteri_audio);
}

ob_clean();
echo json_encode([
    'success' => true,
    'text' => $final_text,
    'audio_url' => $audio_filename
]);
exit;