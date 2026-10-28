<?php
/**
 * ai_credits.php — quanto costa all'utente una chiamata IA, e il registro
 * di ogni movimento.
 *
 * Non e' un endpoint: e' una libreria, inclusa da chi consuma crediti (oggi
 * il motore dei job, domani anche fc_ai.php e ai_example.php). Aperta dal
 * browser non stampa niente, perche' definisce solo funzioni; al riordino
 * delle cartelle (passo 3 dell'ordine dei lavori) andra' in lib/.
 *
 * COSA E' UN CREDITO
 *
 * Un token di Gemini Flash. Un token di un modello piu' caro vale di piu',
 * uno di un modello piu' economico di meno: il peso sta in
 * ai_crediti_peso(), ed e' l'unico posto dove sta.
 *
 * Il motivo per cui l'unita' e' "un token di Flash" e non un numero
 * inventato: oggi tutte le chiamate del sito usano Flash, e il contatore
 * user_plans.monthly_text_tokens conta gia' i loro token. Scegliendo cosi',
 * ogni numero gia' salvato nel database e' GIA' un credito esatto, e la
 * migrazione non deve convertire niente.
 *
 * DUE REGISTRI, UN SALDO
 *
 *   user_plans.monthly_text_tokens  il SALDO del mese. E' quello che si
 *                                   controlla prima di spendere, perche'
 *                                   le funzioni vecchie (spiegazione,
 *                                   metafora, esempi) lo aggiornano gia' e
 *                                   continueranno a farlo.
 *   ai_credit_ledger                la RICEVUTA di ogni movimento del
 *                                   motore dei job, con il passo che l'ha
 *                                   prodotto.
 *
 * Ogni funzione di questo file che muove crediti scrive in entrambi, nella
 * stessa transazione: o tutti e due, o nessuno. Il giorno in cui anche le
 * funzioni vecchie passeranno da qui, il registro sara' completo e il saldo
 * si potra' ricalcolare da esso (la query di verifica e' in fondo al file).
 *
 * PERCHE' TUTTE LE QUERY PASSANO DA sec_q
 *
 * piano_helpers.php apre la sua connessione (standout_db()), security_core ne
 * apre un'altra (sec_db()). Una transazione vale solo sulla connessione che
 * l'ha aperta: se l'INSERT nel registro andasse su una e l'UPDATE del saldo
 * sull'altra, il "tutti e due o nessuno" non varrebbe niente. Qui quindi si
 * usa solo sec_db(), anche dove una funzione di piano_helpers farebbe la
 * stessa cosa.
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';

/* ============================================================
   1. IL PREZZO
   ============================================================ */

/**
 * Quanti crediti vale un token di ciascun modello.
 *
 * Ricavati dai listini di settembre 2026 su una chiamata tipica di questo
 * sito (circa 2.000 token in ingresso e 1.200 in uscita, pensiero compreso):
 *
 *   Flash       0,30 $ in / 2,50 $ out per milione  ->  riferimento, 1
 *   Flash-Lite  0,10 $ in / 0,40 $ out              ->  ~0,19 del costo
 *   Pro         1,25 $ in / 10,00 $ out             ->  ~4 volte
 *
 * Sono arrotondati verso l'alto apposta: se Google ritocca un listino, un
 * margine piccolo assorbe la differenza finche' non si aggiorna questa
 * tabella. Sono una decisione commerciale, non un dato tecnico: vanno
 * riguardati ogni volta che cambia un listino, e prima di cambiarli si
 * guarda quanto spende davvero un utente (la query in fondo al file).
 *
 * Un modello che non e' in tabella costa come Pro: se qualcuno aggiunge un
 * modello e si dimentica di prezzarlo, sbagliare a favore dell'utente
 * vorrebbe dire regalare il modello piu' caro.
 */
function ai_crediti_peso(string $modello): float {
    $pesi = [
        'gemini-2.5-flash'      => 1.0,
        'gemini-2.5-flash-lite' => 0.2,
        'gemini-2.5-pro'        => 4.0,
    ];
    return $pesi[$modello] ?? 4.0;
}

/** Token del fornitore -> crediti. Sempre per eccesso: mezzo credito e' un credito. */
function ai_crediti_da_token(int $token, string $modello): int {
    if ($token <= 0) return 0;
    return (int)ceil($token * ai_crediti_peso($modello));
}

/**
 * Il mese di competenza, nello stesso formato di user_plans.reference_month.
 *
 * Lo chiede al DATABASE, non a PHP, apposta. Le righe del libro mastro portano
 * la data di MySQL (created_at con NOW()), e la pagina dei crediti le raggruppa
 * per mese confrontandole con questo valore. Se i due orologi non sono nello
 * stesso fuso — cosa normalissima: un VPS tiene spesso MySQL su UTC e PHP su
 * Europe/Rome — per un paio d'ore intorno alla mezzanotte del primo del mese le
 * due risposte cadono in mesi diversi, e i movimenti finirebbero nel mese
 * sbagliato (o il contatore mensile si azzererebbe in ritardo). Prendendo l'ora
 * da dove nascono le date, il fuso di PHP non c'entra piu' niente.
 *
 * Una volta per richiesta: e' una domanda al database, non va ripetuta.
 */
function ai_crediti_mese(): string {
    static $mese = null;
    if ($mese === null) {
        $r = sec_one("SELECT DATE_FORMAT(NOW(), '%Y-%m-01') AS m");
        $mese = $r['m'] ?? date('Y-m-01');
    }
    return $mese;
}

/* ============================================================
   2. QUANTO NE RESTA
   ============================================================ */

/**
 * Lo stato del mese per un utente.
 *
 * Passa da standout_get_piano_utente() e non da una query sua perche' quella
 * funzione, prima di leggere, azzera i contatori se il mese e' cambiato:
 * leggere il saldo senza quel passaggio il primo del mese direbbe a un
 * utente che ha finito i crediti quando li ha appena ricevuti.
 *
 * Torna ['limite' => int|null, 'usati' => int, 'disponibili' => int|null];
 * null vuol dire illimitato.
 */
function ai_crediti_stato(int $user_id): array {
    $piano = standout_get_piano_utente($user_id);
    if (!$piano) {
        /* Nessuna riga in user_plans: e' un errore di configurazione, non
           una scelta. piano_helpers in questo caso non blocca; qui si fa lo
           stesso per non rompere il sito a un utente appena registrato, ma
           lo si scrive nel log perche' qualcuno lo sistemi. */
        sec_log_error('ai_crediti', "utente $user_id senza riga in user_plans");
        return ['limite' => null, 'usati' => 0, 'disponibili' => null];
    }
    $limite = $piano['text_tokens_limit'] === null ? null : (int)$piano['text_tokens_limit'];
    $usati  = (int)$piano['monthly_text_tokens'];
    return [
        'limite'      => $limite,
        'usati'       => $usati,
        'disponibili' => $limite === null ? null : max(0, $limite - $usati),
    ];
}

/** true se all'utente restano almeno $servono crediti questo mese. */
function ai_crediti_bastano(int $user_id, int $servono): bool {
    $s = ai_crediti_stato($user_id);
    return $s['disponibili'] === null || $s['disponibili'] >= max(0, $servono);
}

/* ============================================================
   3. MUOVERE CREDITI
   ============================================================ */

/**
 * Addebita il consumo di un passo.
 *
 * Si chiama DOPO che il passo ha prodotto qualcosa di usabile, mai prima:
 * in questo sistema non esiste l'addebito anticipato, quindi un lavoro non
 * consegnato non va rimborsato perche' non e' mai stato pagato.
 *
 * $info: job_id, step_id, model, provider_tokens, note — tutti facoltativi.
 *
 * Torna true se l'addebito e' stato registrato, false se quel passo era gia'
 * stato addebitato. Il false non e' un errore: e' il vincolo UNIQUE del
 * registro che ha fatto il suo lavoro (un ritentativo, due esecutori sullo
 * stesso passo), e il saldo in quel caso non viene toccato.
 */
function ai_crediti_addebita(int $user_id, int $crediti, array $info = []): bool {
    if ($crediti <= 0) return true;   // niente da registrare, e non e' un fallimento
    return ai_crediti_movimento($user_id, -$crediti, 'usage', $info);
}

/**
 * Rimborso: una riga positiva nel registro e il saldo del mese che scende.
 *
 * $motivo: 'refund_error' (automatico, errore nostro) o 'refund_manual'
 * (da supporto; in $info['note'] il perche', sempre).
 *
 * Il saldo scende nel mese CORRENTE anche se il consumo rimborsato era del
 * mese prima: e' piu' generoso di quanto servirebbe, ma e' l'unica scelta
 * che l'utente capisce senza spiegazioni ("mi avete ridato 300 crediti" e
 * li vede subito). Non scende mai sotto zero.
 */
function ai_crediti_rimborsa(int $user_id, int $crediti, string $motivo, array $info = []): bool {
    if ($crediti <= 0) return true;
    if (!in_array($motivo, ['refund_error', 'refund_manual'], true)) {
        sec_log_error('ai_crediti', "motivo di rimborso non previsto: $motivo");
        return false;
    }
    if ($motivo === 'refund_manual' && trim((string)($info['note'] ?? '')) === '') {
        /* Un rimborso manuale senza motivazione e' il tipo di riga che fra
           un anno nessuno sa piu' spiegare. Si rifiuta qui, non si confida
           nella disciplina di chi lo fa. */
        sec_log_error('ai_crediti', "rimborso manuale senza nota per l'utente $user_id");
        return false;
    }
    return ai_crediti_movimento($user_id, $crediti, $motivo, $info);
}

/**
 * Il movimento vero: registro e saldo, insieme o per niente.
 *
 * Se una delle due query fallisce, sec_q() interrompe la richiesta con
 * sec_die_json() — cioe' esce senza arrivare al COMMIT. Alla chiusura della
 * connessione MySQL annulla da solo la transazione aperta: e' questo che
 * garantisce che non resti mai una ricevuta senza addebito o un addebito
 * senza ricevuta, anche senza un rollback scritto a mano.
 */
function ai_crediti_movimento(int $user_id, int $delta, string $motivo, array $info): bool {
    if ($user_id <= 0 || $delta === 0) return false;

    // prima di tutto il cambio di mese: l'addebito deve finire nel mese giusto
    standout_reset_contatori_se_serve($user_id);

    $mese    = ai_crediti_mese();
    $job_id  = isset($info['job_id'])  ? (int)$info['job_id']  : null;
    $step_id = isset($info['step_id']) ? (int)$info['step_id'] : null;
    $modello = isset($info['model']) ? mb_substr((string)$info['model'], 0, 30) : null;
    $token   = isset($info['provider_tokens']) ? max(0, (int)$info['provider_tokens']) : null;
    $nota    = isset($info['note']) ? mb_substr((string)$info['note'], 0, 200) : null;

    $conn = sec_db();
    mysqli_begin_transaction($conn);

    /* ON DUPLICATE KEY UPDATE id = id, e non un INSERT semplice: su un
       doppione del vincolo UNIQUE (step_id, reason) un INSERT normale farebbe
       fallire sec_q, che interromperebbe la richiesta con un errore 500 —
       proprio nel caso in cui non c'e' niente di sbagliato, perche' il passo
       era semplicemente gia' stato registrato. Cosi' la riga non entra,
       affected_rows vale 0, e lo si tratta come "gia' fatto".
       Non INSERT IGNORE, che sembrerebbe la stessa cosa: IGNORE trasforma in
       avvisi silenziosi anche gli errori veri (un job_id inesistente, un
       segno sbagliato che viola il CHECK), e un addebito rifiutato per un
       bug sembrerebbe un addebito gia' fatto. */
    sec_q(
        "INSERT INTO ai_credit_ledger
            (user_id, job_id, step_id, delta, reason, model, provider_tokens, note, month)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id",
        [$user_id, $job_id, $step_id, $delta, $motivo, $modello, $token, $nota, $mese],
        'iiiississ'
    );
    if (sec_affected() !== 1) {
        mysqli_rollback($conn);
        return false;
    }

    if ($delta < 0) {
        // consumo: sale il saldo usato, e il contatore delle chiamate con lui
        sec_q(
            "UPDATE user_plans
                SET monthly_text_tokens = monthly_text_tokens + ?,
                    monthly_text_tokens_calls = monthly_text_tokens_calls + 1
              WHERE user_id = ?",
            [-$delta, $user_id], 'ii'
        );
    } else {
        /* accredito: il saldo usato scende, mai sotto zero. Il contatore
           delle chiamate NON scende: la chiamata c'e' stata, e quel numero
           serve alla stima della media token/chiamata di piano_helpers,
           che deve restare onesta. */
        sec_q(
            "UPDATE user_plans
                SET monthly_text_tokens = GREATEST(0, monthly_text_tokens - ?)
              WHERE user_id = ?",
            [$delta, $user_id], 'ii'
        );
    }

    mysqli_commit($conn);
    return true;
}

/* ============================================================
   4. LEGGERE IL REGISTRO
   ============================================================ */

/**
 * Gli ultimi movimenti di un utente, per la pagina "i tuoi crediti".
 * Solo i suoi: il filtro per user_id sta nella query, non in chi la chiama.
 */
function ai_crediti_movimenti(int $user_id, int $quanti = 50): array {
    $quanti = max(1, min(200, $quanti));
    return sec_all(
        "SELECT l.id, l.delta, l.reason, l.model, l.note, l.created_at, l.job_id,
                j.context_type, j.context_id
           FROM ai_credit_ledger l
           LEFT JOIN ai_jobs j ON j.id = l.job_id
          WHERE l.user_id = ?
          ORDER BY l.id DESC
          LIMIT ?",
        [$user_id, $quanti], 'ii'
    );
}

/* ============================================================
   VERIFICA DEI CONTI — da lanciare a mano ogni tanto, in phpMyAdmin
   ============================================================
   Finche' le funzioni vecchie non passano da questo file, il saldo e il
   registro NON coincidono: il saldo contiene anche spiegazioni, metafore
   ed esempi. La differenza pero' puo' solo essere positiva (saldo >=
   registro). Se una riga esce con differenza NEGATIVA, il registro ha
   addebitato qualcosa che il saldo non ha visto: e' un bug, e va cercato.

   SELECT up.user_id,
          up.monthly_text_tokens                         AS saldo,
          COALESCE(-SUM(l.delta), 0)                     AS dal_registro,
          up.monthly_text_tokens + COALESCE(SUM(l.delta), 0) AS differenza
     FROM user_plans up
     LEFT JOIN ai_credit_ledger l
            ON l.user_id = up.user_id AND l.month = up.reference_month
    GROUP BY up.user_id, up.monthly_text_tokens
   HAVING differenza < 0;

   E quanto ci e' costato davvero ogni modello questo mese, ritentativi
   compresi (e' il numero da guardare prima di toccare i pesi):

   SELECT model, SUM(provider_tokens) AS token_pagati, SUM(credits) AS crediti_addebitati
     FROM ai_job_steps
    WHERE finished_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    GROUP BY model;
   ============================================================ */
