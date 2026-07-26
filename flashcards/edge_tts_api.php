<?php
// ============================================================================
// EDGE TTS — voci Microsoft (Elsa, Diego) generate DAL SERVER
// ----------------------------------------------------------------------------
// Il browser non può collegarsi direttamente al servizio "Leggi ad alta voce"
// di Microsoft: pretende header WebSocket che le pagine web non possono
// impostare. Dal server invece si può: questo file apre la connessione,
// chiede l'audio e lo salva come MP3 in cache (una sola generazione per testo).
//
// AVVERTENZA IMPORTANTE: NON è un'API ufficiale. È il servizio interno del
// browser Edge, usato imitandone il comportamento (come fanno le librerie
// edge-tts della community). Microsoft può cambiarne le regole da un giorno
// all'altro: in quel caso questo endpoint inizierà a rispondere con un errore
// (tipicamente 403) e il sito ripiegherà da solo sulla voce di sistema.
// È il motivo per cui questa è la "coppia sperimentale".
//
// Niente Composer, niente librerie: WebSocket scritto a mano su socket TLS,
// così su Altervista basta caricare questo file e nient'altro.
// ============================================================================

// php://input si legge UNA volta sola: va letto prima di qualsiasi require
// (config/auth potrebbero consumarlo — è già successo, vedi ai_example.php).
$__raw_input = file_get_contents('php://input');

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'auth_check_api.php'; // endpoint protetto: solo utenti loggati

ob_start();
header('Content-Type: application/json');

// Rete di sicurezza: anche un errore fatale produce JSON leggibile.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['success' => false, 'error' => 'Errore interno del server: ' . $err['message'] . ' (riga ' . $err['line'] . ')']);
    }
});

function edge_rispondi_errore($msg) {
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ------------------------------ INPUT --------------------------------------
$data = json_decode($__raw_input, true);
if (!$data) {
    edge_rispondi_errore('Nessun dato ricevuto dal JavaScript (corpo: ' . strlen($__raw_input) . ' byte).');
}

$testo = isset($data['text']) ? trim((string)$data['text']) : '';
$voce  = isset($data['voice']) ? (string)$data['voice'] : 'it-IT-ElsaNeural';

$VOCI_PERMESSE = ['it-IT-ElsaNeural', 'it-IT-DiegoNeural'];
if (!in_array($voce, $VOCI_PERMESSE, true)) {
    edge_rispondi_errore('Voce non permessa.');
}
if ($testo === '') {
    edge_rispondi_errore('Testo vuoto.');
}
// Le letture delle flashcard sono corte; un tetto evita abusi e richieste enormi.
if (mb_strlen($testo, 'UTF-8') > 3000) {
    $testo = mb_substr($testo, 0, 3000, 'UTF-8');
}

// ------------------------------ CACHE --------------------------------------
if (!is_dir('edge_audio')) { @mkdir('edge_audio', 0755, true); }
$file_audio = 'edge_audio/edge_' . md5($voce . '|' . $testo) . '.mp3';

if (file_exists($file_audio) && filesize($file_audio) > 0) {
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['success' => true, 'audio_url' => $file_audio, 'cached' => true]);
    exit;
}

// --------------------------- TOKEN Sec-MS-GEC ------------------------------
// È la "firma" che il servizio pretende: SHA-256 di (tempo-Windows arrotondato
// ai 5 minuti, in tick da 100ns) + token pubblico del client. Il tempo Windows
// conta i secondi dal 1601: si ottiene sommando 11644473600 all'epoca Unix.
// Costruiamo i tick come STRINGA (secondi + sette zeri) per non rischiare
// arrotondamenti coi numeri grandi.
$TRUSTED_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
$t = time() + 11644473600;
$t -= $t % 300;                       // arrotonda in giù ai 5 minuti
$ticks = $t . '0000000';              // secondi -> tick da 100 nanosecondi
$gec = strtoupper(hash('sha256', $ticks . $TRUSTED_TOKEN));

$conn_id = bin2hex(random_bytes(16));
$percorso_ws = '/consumer/speech/synthesize/readaloud/edge/v1'
             . '?TrustedClientToken=' . $TRUSTED_TOKEN
             . '&Sec-MS-GEC=' . $gec
             . '&Sec-MS-GEC-Version=1-131.0.2903.99'
             . '&ConnectionId=' . $conn_id;

// --------------------------- STRUMENTI WEBSOCKET ---------------------------

/** Legge esattamente $n byte dal socket (fread può tornarne meno). */
function edge_leggi($sock, $n) {
    $out = '';
    $tentativi_vuoti = 0;
    while (strlen($out) < $n) {
        $chunk = fread($sock, $n - strlen($out));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                throw new Exception('timeout leggendo dal servizio Microsoft');
            }
            if (feof($sock)) {
                throw new Exception('connessione chiusa dal servizio Microsoft');
            }
            if (++$tentativi_vuoti > 200) { // ~4s di letture vuote: qualcosa non va
                throw new Exception('lettura bloccata dal servizio Microsoft');
            }
            usleep(20000);
            continue;
        }
        $tentativi_vuoti = 0;
        $out .= $chunk;
    }
    return $out;
}

/** Invia un frame WebSocket mascherato (obbligatorio per i client). */
function edge_ws_send($sock, $payload, $opcode = 0x1) {
    $len = strlen($payload);
    $hdr = chr(0x80 | $opcode); // FIN=1
    if ($len < 126) {
        $hdr .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $hdr .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $hdr .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $masked = $len > 0
        ? ($payload ^ substr(str_repeat($mask, (int)ceil($len / 4) + 1), 0, $len))
        : '';
    if (fwrite($sock, $hdr . $mask . $masked) === false) {
        throw new Exception('scrittura verso Microsoft fallita');
    }
}

/** Legge un singolo frame WebSocket. */
function edge_ws_leggi_frame($sock) {
    $b = edge_leggi($sock, 2);
    $b0 = ord($b[0]); $b1 = ord($b[1]);
    $fin = ($b0 & 0x80) !== 0;
    $op = $b0 & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $len = $b1 & 0x7F;
    if ($len === 126) {
        $len = unpack('n', edge_leggi($sock, 2))[1];
    } elseif ($len === 127) {
        $len = unpack('J', edge_leggi($sock, 8))[1];
    }
    if ($len > 20 * 1024 * 1024) { // 20MB: nessun frame legittimo è così grande
        throw new Exception('frame anomalo dal servizio Microsoft');
    }
    $mask = $masked ? edge_leggi($sock, 4) : '';
    $pl = $len > 0 ? edge_leggi($sock, $len) : '';
    if ($masked && $len > 0) {
        $pl = $pl ^ substr(str_repeat($mask, (int)ceil($len / 4) + 1), 0, $len);
    }
    return ['fin' => $fin, 'op' => $op, 'dati' => $pl];
}

/** Legge un messaggio completo (riunisce gli eventuali frame di continuazione). */
function edge_ws_leggi_messaggio($sock) {
    $f = edge_ws_leggi_frame($sock);
    $op = $f['op'];
    $dati = $f['dati'];
    while (!$f['fin']) {
        $f = edge_ws_leggi_frame($sock);
        $dati .= $f['dati'];
    }
    return ['op' => $op, 'dati' => $dati];
}

// ------------------------------ DIALOGO ------------------------------------
try {
    $ctx = stream_context_create(['ssl' => [
        'SNI_enabled' => true,
        'peer_name'   => 'speech.platform.bing.com',
    ]]);
    $sock = @stream_socket_client('ssl://speech.platform.bing.com:443', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new Exception('connessione TLS fallita: ' . $errstr . ' — l\'hosting potrebbe bloccare i socket in uscita');
    }
    stream_set_timeout($sock, 20);

    // Handshake HTTP -> WebSocket, con gli header che il servizio si aspetta
    // da un browser Edge (è QUESTA la parte impossibile da fare da JavaScript).
    $key = base64_encode(random_bytes(16));
    $hs = "GET $percorso_ws HTTP/1.1\r\n"
        . "Host: speech.platform.bing.com\r\n"
        . "Connection: Upgrade\r\n"
        . "Upgrade: websocket\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "Sec-WebSocket-Key: $key\r\n"
        . "Origin: chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold\r\n"
        . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.99\r\n"
        . "Pragma: no-cache\r\n"
        . "Cache-Control: no-cache\r\n"
        . "Accept-Language: it-IT,it;q=0.9\r\n\r\n";
    fwrite($sock, $hs);

    // Risposta all'handshake: pretendiamo "101 Switching Protocols".
    $status = '';
    while (($riga = fgets($sock)) !== false) {
        if ($status === '') { $status = trim($riga); }
        if ($riga === "\r\n") { break; }
    }
    if (strpos($status, '101') === false) {
        $hint = (strpos($status, '403') !== false)
            ? ' — Microsoft potrebbe aver cambiato le regole del token (succede periodicamente)'
            : '';
        throw new Exception('handshake rifiutato: "' . $status . '"' . $hint);
    }

    // 1) Configurazione: formato audio MP3 24kHz.
    $timestamp = gmdate('D M d Y H:i:s') . ' GMT+0000 (Coordinated Universal Time)';
    $config = "X-Timestamp:$timestamp\r\n"
            . "Content-Type:application/json; charset=utf-8\r\n"
            . "Path:speech.config\r\n\r\n"
            . '{"context":{"synthesis":{"audio":{"metadataoptions":{"sentenceBoundaryEnabled":"false","wordBoundaryEnabled":"false"},"outputFormat":"audio-24khz-48kbitrate-mono-mp3"}}}}';
    edge_ws_send($sock, $config);

    // 2) SSML col testo. Le voci Microsoft rispettano da sole la punteggiatura.
    $req_id = bin2hex(random_bytes(16));
    $testo_xml = htmlspecialchars($testo, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $ssml = "X-RequestId:$req_id\r\n"
          . "Content-Type:application/ssml+xml\r\n"
          . "X-Timestamp:$timestamp" . "Z\r\n"
          . "Path:ssml\r\n\r\n"
          . "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='it-IT'>"
          . "<voice name='$voce'><prosody pitch='+0Hz' rate='+0%' volume='+0%'>"
          . $testo_xml
          . "</prosody></voice></speak>";
    edge_ws_send($sock, $ssml);

    // 3) Raccolta dell'audio: frame binari = [2 byte lunghezza header][header][mp3].
    $audio = '';
    $inizio = time();
    while (true) {
        if (time() - $inizio > 40) {
            throw new Exception('il servizio Microsoft non ha completato entro 40 secondi');
        }
        $msg = edge_ws_leggi_messaggio($sock);

        if ($msg['op'] === 0x8) { break; }                      // close
        if ($msg['op'] === 0x9) { edge_ws_send($sock, $msg['dati'], 0xA); continue; } // ping -> pong

        if ($msg['op'] === 0x1) {                                // testo
            if (strpos($msg['dati'], 'Path:turn.end') !== false) { break; }
            continue;
        }
        if ($msg['op'] === 0x2 && strlen($msg['dati']) >= 2) {   // binario
            $hl = unpack('n', substr($msg['dati'], 0, 2))[1];
            $hdr_bin = substr($msg['dati'], 2, $hl);
            if (strpos($hdr_bin, 'Path:audio') !== false) {
                $audio .= substr($msg['dati'], 2 + $hl);
            }
        }
    }
    fclose($sock);

    if ($audio === '') {
        throw new Exception('nessun audio ricevuto — Microsoft potrebbe aver cambiato il protocollo');
    }

    if (file_put_contents($file_audio, $audio) === false) {
        throw new Exception('impossibile salvare il file MP3 sul server (permessi cartella edge_audio?)');
    }

    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['success' => true, 'audio_url' => $file_audio, 'byte' => strlen($audio)]);
    exit;

} catch (Exception $e) {
    edge_rispondi_errore('Voce Microsoft non disponibile: ' . $e->getMessage());
}