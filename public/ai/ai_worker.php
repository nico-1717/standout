<?php
/**
 * ai_worker.php — fa avanzare i job IA da riga di comando, anche a pagina
 * chiusa. E' il passo 5f dell'ordine dei lavori: serve sul VPS, non su
 * Altervista (dove non c'e' un cron e il lavoro lo fa avanzare la pagina).
 *
 * USO — una riga di crontab, ogni minuto:
 *
 *   * * * * * php /percorso/del/sito/ai_worker.php --durata=55 >> /var/log/nisama_ai.log 2>&1
 *
 * Ogni esecuzione lavora per --durata secondi (di serie 55), un passo alla
 * volta, poi esce; il minuto dopo cron ne avvia un'altra. Un lucchetto su
 * file impedisce che due esecuzioni si sovrappongano se una tarda a finire.
 *
 * E ALLORA LA PAGINA?
 *
 * Con il lavoratore attivo, la pagina puo' smettere di far avanzare il
 * lavoro e limitarsi a guardarlo: basta aggiungere in config.php
 *
 *   define('AI_JOB_WORKER', true);
 *
 * Senza quella riga le due strade convivono senza danni — un passo lo
 * esegue comunque uno solo, lo garantisce la presa con il gettone in
 * ai_jobs.php — ma e' lavoro doppio per il server.
 *
 * EQUITA' FRA UTENTI
 *
 * A ogni giro si sceglie il job fermo da piu' tempo (last_tick_at piu'
 * vecchio): chi ha lanciato 200 pagine non tiene in coda per mezz'ora chi ne
 * ha lanciate due.
 */

/* Mai dal browser: questo file lavora per TUTTI gli utenti, senza sessione e
   senza controllo di proprieta'. Aperto da un indirizzo web risponde 404
   come se non esistesse. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* security_core.php, se non trova un indirizzo, reindirizza a HTTPS ed esce:
   giusto per una pagina, fatale per un programma da riga di comando, che
   morirebbe in silenzio alla prima riga. Qui ci si presenta come locale.
   (La correzione pulita e' una riga in sec_boot_headers:
   `if (PHP_SAPI === 'cli') return;` — vedi le note della consegna.) */
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/ai_jobs.php';

$opz = getopt('', ['durata::', 'una-volta']);
$durata = min(600, (int)($opz['durata'] ?? 55));
/* Sotto questo tempo il lavoratore non riuscirebbe a finire nemmeno un passo
   (AI_JOB_TEMPO_MINIMO piu' il margine) e uscirebbe senza fare niente, in
   silenzio. Meglio alzarlo e dirlo. */
$minima = (int)AI_JOB_TEMPO_MINIMO + 10;
if ($durata < $minima) {
    fwrite(STDOUT, date('c') . " durata $durata troppo corta per un passo: uso $minima secondi\n");
    $durata = $minima;
}
$una_volta = isset($opz['una-volta']);   // esegue al massimo un passo: utile per provare a mano

/* IL LUCCHETTO. flock e' rilasciato dal sistema anche se il processo muore:
   non resta mai un lucchetto orfano che blocca tutto fino a un intervento a
   mano, come succederebbe con un file "sto lavorando" scritto e cancellato. */
$lucchetto = fopen(sys_get_temp_dir() . '/nisama_ai_worker.lock', 'c');
if (!$lucchetto || !flock($lucchetto, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, date('c') . " un altro lavoratore e' gia' attivo, esco\n");
    exit(0);
}

$fine = microtime(true) + $durata;
$passi = 0;
$vuoti = 0;

while (microtime(true) < $fine) {
    $resta = $fine - microtime(true);
    /* Un passo ha bisogno di tempo per finire: se ne resta poco si esce, e il
       prossimo giro di cron lo riprende da capo. Meglio che fermarsi a meta'. */
    if ($resta < AI_JOB_TEMPO_MINIMO + 5) break;

    $job = sec_one("SELECT id FROM ai_jobs WHERE status IN ('queued', 'running')
                     ORDER BY last_tick_at IS NOT NULL, last_tick_at, id LIMIT 1");
    if (!$job) {
        // niente da fare: si riguarda fra un secondo, per partire subito se arriva qualcosa
        if (++$vuoti === 1) fwrite(STDOUT, date('c') . " nessun job in coda\n");
        if ($una_volta) break;
        sleep(1);
        continue;
    }
    $vuoti = 0;

    // il tempo di questo passo: quanto resta dell'esecuzione, al massimo 120 secondi
    fc_ai_scadenza_imposta(min(120.0, $resta - 2.0));

    $prima = sec_one("SELECT steps_done, steps_failed FROM ai_jobs WHERE id = ?", [(int)$job['id']], 'i');
    $stato = ai_job_avanza((int)$job['id'], null);
    if (!$stato) continue;

    $fatto = $stato['passi_fatti'] > (int)$prima['steps_done'] || $stato['passi_falliti'] > (int)$prima['steps_failed'];
    if ($fatto) {
        $passi++;
        fwrite(STDOUT, sprintf("%s job %d: %d/%d passi, stato %s%s\n", date('c'), $stato['id'],
            $stato['passi_fatti'], $stato['passi_totali'], $stato['stato'],
            $stato['motivo_pausa'] ? ' (' . $stato['motivo_pausa'] . ')' : ''));
    } elseif (!empty($stato['in_attesa'])) {
        // tetto di sito raggiunto: si respira invece di martellare
        sleep(2);
    }
    if ($una_volta && $fatto) break;
}

fwrite(STDOUT, date('c') . " fine: $passi passi eseguiti\n");
flock($lucchetto, LOCK_UN);
fclose($lucchetto);
