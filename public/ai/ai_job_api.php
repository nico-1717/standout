<?php
/**
 * ai_job_api.php — l'endpoint che la pagina usa per i job IA lunghi.
 *
 * Risponde sempre in JSON. Azioni:
 *
 *   crea       crea il job (documento intero, o elenco di concetti gia' rivisto)
 *   avanza     esegue UN passo e restituisce lo stato — e' il giro di polling
 *   stato      restituisce lo stato senza eseguire niente
 *   attivo     il job vivo dell'utente, se c'e' (per riprendere a pagina riaperta)
 *   annulla    ferma il job; cio' che e' gia' fatto resta
 *   riprendi   riparte da una pausa per crediti o per troppi errori
 *   conferma   conferma l'elenco dei concetti dopo la revisione
 *   riprova    rimette in coda i passi falliti e i concetti rimasti senza carta
 *
 * "avanza" e' il motore di oggi: finche' siamo su Altervista, senza cron, e'
 * la pagina aperta a far andare avanti il lavoro, un passo per giro. Dopo il
 * trasloco un cron chiamera' ai_job_avanza() direttamente, e questo endpoint
 * restera' solo a mostrare l'avanzamento.
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php'; // se manca il login risponde JSON, non reindirizza
require_once __DIR__ . '/ai_jobs.php';

$uid = (int)($utente_corrente['id'] ?? 0);
if ($uid <= 0) sec_die_json(t('api.sessione_non_valida'), 401, 'auth');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sec_json(['ok' => false, 'error' => 'metodo'], 405);

/* Anche leggere lo stato passa da qui: "avanza" consuma crediti ed e' a tutti
   gli effetti una scrittura. 90 al minuto lasciano larghezza al polling
   (uno ogni 2-3 secondi, piu' i giri immediati dopo un passo riuscito) e
   fermano uno script che martella. */
sec_guard_write($uid, 'ai_job', 0, 90, 60);

/* Se l'utente chiude la scheda mentre un passo sta generando, il passo va
   finito e registrato lo stesso: e' gia' stato pagato a Google, e buttarlo
   vorrebbe dire rifarlo (e ripagarlo) al giro dopo. */
ignore_user_abort(true);

/* Piu' tempo, se l'hosting lo permette: un passo di carte sta comodo in 60
   secondi, stretto in 30. Su alcuni hosting condivisi set_time_limit e'
   disattivato; in quel caso non succede niente, e fc_ai_scadenza_imposta()
   legge il limite vero. I lotti che non ci stanno si dividono da soli. */
@set_time_limit(90);
fc_ai_scadenza_imposta();

$azione = sec_enum(sec_in('azione', ''), ['crea', 'avanza', 'stato', 'attivo', 'annulla', 'riprendi', 'conferma', 'riprova'], '');
$job_id = sec_int(sec_in('job_id', 0), 0, PHP_INT_MAX, 0);
$dopo   = sec_int(sec_in('dopo', 0), 0, PHP_INT_MAX, 0);

/** Esito di una funzione del motore -> risposta HTTP. */
function ai_job_rispondi(array $r): void {
    if (!empty($r['ok'])) sec_json(['ok' => true, 'job' => $r['job'] ?? null]);
    $http = (int)($r['http'] ?? 400);
    unset($r['http']);
    sec_json(array_merge(['ok' => false], $r), $http);
}

switch ($azione) {
    case 'crea':
        ai_job_rispondi(ai_job_crea($uid, sec_body()));

    case 'avanza':
        /* Con il lavoratore da riga di comando attivo (sul VPS, vedi
           ai_worker.php) la pagina non fa piu' avanzare il lavoro: lo guarda
           soltanto. Senza questa distinzione le due strade funzionerebbero
           lo stesso — un passo lo esegue comunque uno solo — ma il server
           farebbe il lavoro di coordinarle due volte. */
        $stato = (defined('AI_JOB_WORKER') && AI_JOB_WORKER)
            ? ai_job_stato(ai_job_leggi($job_id, $uid), $dopo)
            : ai_job_avanza($job_id, $uid, $dopo);
        if (!$stato) sec_die_json(t('api.non_trovato'), 404, 'not_found');
        sec_json(['ok' => true, 'job' => $stato]);

    case 'stato':
        $stato = ai_job_stato(ai_job_leggi($job_id, $uid), $dopo);
        if (!$stato) sec_die_json(t('api.non_trovato'), 404, 'not_found');
        sec_json(['ok' => true, 'job' => $stato]);

    case 'attivo':
        $job = ai_job_attivo($uid);
        sec_json(['ok' => true, 'job' => $job ? ai_job_stato($job, $dopo) : null]);

    case 'annulla':
        if (!ai_job_annulla($job_id, $uid)) sec_die_json(t('api.niente_da_annullare'), 409, 'non_annullabile');
        sec_json(['ok' => true, 'job' => ai_job_stato(ai_job_leggi($job_id, $uid), $dopo)]);

    case 'riprendi':
        ai_job_rispondi(ai_job_riprendi($job_id, $uid));

    case 'conferma':
        ai_job_rispondi(ai_job_conferma_concetti($job_id, $uid, sec_in('concetti', [])));

    case 'riprova':
        ai_job_rispondi(ai_job_riprova($job_id, $uid));
}

sec_json(['ok' => false, 'error' => 'azione_sconosciuta'], 400);
