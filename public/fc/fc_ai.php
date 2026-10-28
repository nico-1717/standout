<?php
/**
 * fc_ai.php — l'endpoint della generazione IA delle flashcard.
 *
 * Serve fc_insert.php e risponde SEMPRE in JSON. Cinque azioni:
 *
 *   stima     quanto costerebbe (in crediti) una richiesta, senza chiamare l'IA
 *   concetti  dal testo (o da un elenco/argomento) l'elenco dei concetti chiave
 *   densita   "piu' concetti" / "meno concetti" sull'elenco gia' ottenuto
 *   carte     genera un LOTTO di flashcard dai concetti scelti
 *   migliora  riscrive (o scrive da zero) una carta sola
 *
 * Il lavoro vero sta in fc_ai_lib.php: qui si legge la richiesta, si controlla
 * il budget, si chiama la funzione dell'azione, si addebita e si risponde.
 * Le risposte hanno la stessa forma di prima, campo per campo, quindi
 * fc_ai_client.js e fc_improve_card.js non vanno toccati; in piu', negli
 * errori, c'e' "riprovabile".
 *
 * PERCHE' IL TESTO INTEGRALE VIAGGIA UNA VOLTA SOLA
 *
 * Il costo di questa funzione non e' la lunghezza del documento: sono le
 * chiamate ripetute con il documento allegato. Qui il testo integrale viaggia
 * UNA volta sola, nell'azione "concetti". Da li' in poi il client manda solo i
 * concetti e, per la generazione, la finestra di testo attorno all'ancora di
 * ciascuno (campo "estratto"): su un documento da 30.000 caratteri e 60 carte
 * si passa da ~180.000 caratteri inviati a ~25.000.
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php'; // versione API: se manca il login risponde JSON, non reindirizza
require_once __DIR__ . '/../../lib/fc/fc_ai_lib.php';

$fc_ai_user_id = (int)($utente_corrente['id'] ?? 0);
if ($fc_ai_user_id <= 0) sec_die_json(t('api.sessione_non_valida'), 401, 'auth');

/* Una riga sola: CSRF, origine, livello e limite di frequenza. 30 chiamate al
   minuto sono gia' molte per un uso umano e fermano sul nascere uno script
   impazzito — che qui non farebbe danni ai dati, ma brucerebbe i crediti
   dell'utente, che e' il danno che conta. */
sec_guard_write($fc_ai_user_id, 'fc_ai', 0, 30, 60);

/* Il tempo di questa richiesta, da max_execution_time. Da qui in poi nessuna
   chiamata a Gemini parte se non fa in tempo a finire. */
fc_ai_scadenza_imposta();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sec_json(['ok' => false, 'error' => 'metodo'], 405);
}

$fc_ai_in = sec_body();
if (!$fc_ai_in) {
    sec_json(['ok' => false, 'error' => 'corpo_non_valido'], 400);
}

$azione = fc_ai_enum($fc_ai_in['azione'] ?? '', ['stima', 'concetti', 'densita', 'carte', 'migliora', 'parole'], '');
if ($azione === '') {
    sec_json(['ok' => false, 'error' => 'azione_sconosciuta'], 400);
}

/* ============================================================
   stima — nessuna chiamata all'IA, nessun addebito
   ============================================================ */

if ($azione === 'stima') {
    $stima = fc_ai_preventivo(fc_ai_enum($fc_ai_in['per'] ?? 'concetti', ['concetti', 'densita', 'carte', 'parole'], 'concetti'), $fc_ai_in);
    $b = fc_ai_controlla_budget($fc_ai_user_id, $stima);
    sec_json([
        'ok' => true,
        'stima' => $b['stima'],
        'disponibili' => $b['disponibili'],
        'sufficiente' => $b['ok'],
    ]);
}

/* Da qui in poi si chiama davvero l'IA: prima si guarda il portafoglio.
   Se non bastano, la risposta dice "ripiego" e il client offre il prompt da
   copiare negli appunti — che e' la stessa strada di chi non ha abbonamento. */
$preventivo = fc_ai_preventivo($azione, $fc_ai_in);
$budget = fc_ai_controlla_budget($fc_ai_user_id, $preventivo);
if (!$budget['ok']) {
    sec_json([
        'ok' => false,
        'error' => 'limite_piano',
        'stima' => $budget['stima'],
        'disponibili' => $budget['disponibili'],
        'ripiego' => true,
    ]);
}

$lingua = fc_ai_enum($fc_ai_in['lingua'] ?? 'italiano', ['italiano', 'inglese'], 'italiano');

/* Le parole di un corso di lingua: le due lingue vengono dal corso, che deve
   essere dell'utente — mai dal browser. I nomi si passano in inglese, la
   lingua delle istruzioni al modello. */
$fc_ai_lingue = null;
if ($azione === 'parole') {
    require_once __DIR__ . '/../../lib/fc/fc_type.php';
    $corso = sec_one("SELECT known_lang, learn_lang FROM fc_vocab_courses WHERE id = ? AND user_id = ?",
                     [(int)($fc_ai_in['corso'] ?? 0), $fc_ai_user_id], 'ii');
    if (!$corso) sec_json(['ok' => false, 'error' => 'corso'], 404);
    $fc_ai_lingue = [fc_lingua_nome($corso['learn_lang'], true, 'en'), fc_lingua_nome($corso['known_lang'], false, 'en')];
}

switch ($azione) {
    case 'parole':   $esito = fc_ai_azione_parole($fc_ai_in, $fc_ai_lingue[0], $fc_ai_lingue[1]); break;
    case 'concetti': $esito = fc_ai_azione_concetti($fc_ai_in, $lingua); break;
    case 'densita':  $esito = fc_ai_azione_densita($fc_ai_in, $lingua);  break;
    case 'carte':    $esito = fc_ai_azione_carte($fc_ai_in, $lingua);    break;
    default:         $esito = fc_ai_azione_migliora($fc_ai_in, $lingua); break;
}

/* ============================================================
   ADDEBITO — solo se il lavoro e' stato consegnato
   ============================================================
   Prima si addebitava tutto, anche le chiamate fallite e i tentativi
   ritentati. Ora si addebitano i token del tentativo riuscito, e solo quando
   l'azione ha restituito qualcosa di usabile: un errore non costa niente
   all'utente. I token dei tentativi falliti li paghiamo noi, e restano
   scritti nel libro mastro accanto all'addebito (provider_tokens), cosi' si
   vede quanto ci costa davvero ogni funzione. */

$crediti = 0;
if ($esito['ok']) {
    $crediti = ai_crediti_da_token($esito['token']['utili'], $esito['modello']);
    ai_crediti_addebita($fc_ai_user_id, $crediti, [
        'model'           => $esito['modello'],
        'provider_tokens' => $esito['token']['totali'],
        // la firma dello stile: per scoprire, dai conti, quali scelte costano di piu'
        'note'            => 'fc_ai:' . $azione . ' ' . fc_ai_stile_firma(
            fc_ai_stile_leggi($fc_ai_in['opzioni']['stile'] ?? []),
            fc_ai_impegno_leggi($fc_ai_in['opzioni']['impegno'] ?? 'medio')),
    ]);
} elseif ($esito['token']['totali'] > 0) {
    // niente addebito, ma il costo nostro va scritto da qualche parte
    sec_log_error('fc_ai', "azione $azione fallita ({$esito['error']}), token pagati da noi: {$esito['token']['totali']}");
}

if (!$esito['ok']) {
    sec_json([
        'ok' => false,
        'error' => $esito['error'],
        'riprovabile' => $esito['riprovabile'],
        'token' => 0,
    ], $esito['http']);
}

/* 'token' resta il nome del campo perche' il client lo legge cosi'; il valore
   ora sono i crediti addebitati, che con Flash coincidono con i token. */
sec_json(array_merge(['ok' => true], $esito['dati'], ['token' => $crediti]));
