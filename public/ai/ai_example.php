<?php
/**
 * ai_example.php — genera l'esempio di una carta, dal popup degli esempi.
 *
 * Il lavoro vero sta in fc_ai_azione_esempio() (fc_ai_lib.php), la stessa
 * libreria delle carte: stesso formato del testo, stesso stile, stesso
 * impegno, stessa verifica. Qui si legge la richiesta, si controlla il
 * portafoglio, si chiama l'azione, si addebita e si risponde.
 *
 * La risposta ha la forma di sempre, che fc_examples_modal.php legge:
 *   {success: true, example: "..."}
 *   {success: false, error: "limite_piano", message: "...", stima, disponibili}
 *   {success: false, error: <codice>, message: "...", riprovabile: bool}
 *
 * COSA E' CAMBIATO RISPETTO ALLA VERSIONE PRECEDENTE, E PERCHE'
 *
 *   - Controllo CSRF (sec_guard_write). Prima mancava: un altro sito poteva
 *     far generare esempi dal browser di un utente collegato, spendendo i
 *     suoi crediti. E' la regola di SICUREZZA.md §5, qui era sfuggita.
 *   - Certificato di Google verificato. Prima CURLOPT_SSL_VERIFYPEER era
 *     spento, e nell'indirizzo della richiesta viaggia la chiave API: su una
 *     rete ostile la si poteva leggere. Ora la chiamata passa da
 *     fc_ai_gemini(), che il certificato lo verifica.
 *   - Niente errori tecnici a schermo (SICUREZZA.md §7): prima arrivavano al
 *     browser i messaggi di curl e quelli di PHP con il numero di riga.
 *   - Budget di ragionamento fissato dall'impegno scelto, e budget di tempo:
 *     niente piu' attese lunghe che finiscono in errore.
 *   - Addebito nel libro mastro, solo dei tentativi riusciti.
 */

/* Il corpo si legge SUBITO, prima di qualunque include: e' la precauzione di
   sempre di questo file, e resta — un file incluso che leggesse php://input
   per conto suo non deve poterlo svuotare. */
$__raw_input = file_get_contents('php://input');

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_lib.php';

$uid = (int)($utente_corrente['id'] ?? 0);
if ($uid <= 0) sec_die_json('Sessione non valida.', 401, 'auth');

// CSRF, origine e limite di frequenza: 20 esempi al minuto sono gia' tanti per una persona
sec_guard_write($uid, 'fc_ai_esempio', 0, 20, 60);

fc_ai_scadenza_imposta();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sec_json(['success' => false, 'error' => 'metodo', 'message' => t('fc.ibfai_errore_ia')], 405);
}

$dati = json_decode((string)$__raw_input, true);
if (!is_array($dati)) {
    sec_json(['success' => false, 'error' => 'corpo_non_valido', 'message' => t('fc.ibfai_errore_ia')], 400);
}

/* Il preventivo prima della chiamata: stile e impegno cambiano il prezzo, e
   un esercizio svolto con impegno "massimo" non costa come un esempio breve. */
$preventivo = fc_ai_preventivo('esempio', $dati);
$budget = fc_ai_controlla_budget($uid, $preventivo);
if (!$budget['ok']) {
    sec_json([
        'success' => false,
        'error' => 'limite_piano',
        'message' => t('fc.exm_limite_msg', ['stima' => $budget['stima'], 'disponibili' => $budget['disponibili']]),
        'stima' => $budget['stima'],
        'disponibili' => $budget['disponibili'],
    ]);
}

$t0 = microtime(true);
$esito = fc_ai_azione_esempio($dati, 'italiano');

/* Il tempo medio della voce "example" e' quello che il popup rilegge per
   mostrare "di solito ≈ Ns" prima di partire: si continua ad aggiornarlo. */
if ($esito['ok']) standout_registra_tempo_ia('example', (int)round((microtime(true) - $t0) * 1000));

if ($esito['ok']) {
    $crediti = ai_crediti_da_token($esito['token']['utili'], $esito['modello']);
    ai_crediti_addebita($uid, $crediti, [
        'model'           => $esito['modello'],
        'provider_tokens' => $esito['token']['totali'],
        'note'            => 'fc_ai:esempio ' . fc_ai_stile_firma(
            fc_ai_stile_leggi($dati['opzioni']['stile'] ?? []),
            fc_ai_impegno_leggi($dati['opzioni']['impegno'] ?? 'medio')),
    ]);
    sec_json(['success' => true, 'example' => $esito['dati']['esempio']]);
}

if ($esito['token']['totali'] > 0) {
    sec_log_error('ai_example', "fallito ({$esito['error']}), token pagati da noi: {$esito['token']['totali']}");
}

/* Il messaggio per l'utente lo sceglie il server, che conosce la causa. Sono
   le stesse chiavi del pannello: un "server sovraccarico" si dice allo stesso
   modo in tutto il sito. */
$chiavi = [
    'carta_vuota'     => 'fc.exm_carta_vuota',
    'ia_rete'         => 'fc.ibfai_err_rete',
    'ia_quota'        => 'fc.ibfai_err_quota',
    'ia_sovraccarico' => 'fc.ibfai_err_sovraccarico',
    'ia_lento'        => 'fc.ibfai_err_lento',
    'ia_tempo'        => 'fc.ibfai_err_lento',
    'ia_filtro'       => 'fc.ibfai_err_filtro',
    'ia_chiave'       => 'fc.ibfai_err_nostro',
    'ia_richiesta'    => 'fc.ibfai_err_nostro',
];
sec_json([
    'success'     => false,
    'error'       => $esito['error'],
    'message'     => t($chiavi[$esito['error']] ?? 'fc.ibfai_errore_ia'),
    'riprovabile' => $esito['riprovabile'],
], $esito['http']);
